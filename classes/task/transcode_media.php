<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_evidencechecklist\task;

/**
 * Compress uploaded video (H.264 720p + poster frame) and audio (mono AAC) with ffmpeg, when configured.
 *
 * @package    mod_evidencechecklist
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class transcode_media extends \core\task\adhoc_task {
    /**
     * Run.
     */
    public function execute() {
        global $DB;
        $data = $this->get_custom_data();
        $ffmpeg = get_config('mod_evidencechecklist', 'ffmpegpath');
        if (!$ffmpeg || !is_executable($ffmpeg)) {
            mtrace('Evidence Checklist: ffmpeg not configured, skipping.');
            return;
        }
        $fs = get_file_storage();
        $file = $fs->get_file_by_id((int)$data->fileid);
        if (!$file || $file->get_filearea() !== 'cellmedia') {
            return;
        }
        $mime = $file->get_mimetype();
        $isvideo = str_starts_with($mime, 'video/') && !str_starts_with($file->get_filename(), 'audio-');
        $dir = make_request_directory();
        $in = $dir . '/in.' . pathinfo($file->get_filename(), PATHINFO_EXTENSION);
        $file->copy_content_to($in);
        $base = pathinfo($file->get_filename(), PATHINFO_FILENAME);
        if ($isvideo) {
            $out = $dir . '/out.mp4';
            $cmd = escapeshellcmd($ffmpeg) . ' -y -i ' . escapeshellarg($in) .
                ' -vf ' . escapeshellarg("scale='min(1280,iw)':-2") .
                ' -c:v libx264 -preset veryfast -crf 28 -pix_fmt yuv420p -c:a aac -b:a 96k -ac 1 -movflags +faststart ' .
                escapeshellarg($out) . ' 2>&1';
            $newname = $base . '.mp4';
        } else {
            $out = $dir . '/out.m4a';
            $cmd = escapeshellcmd($ffmpeg) . ' -y -i ' . escapeshellarg($in) . ' -vn -c:a aac -b:a 48k -ac 1 ' .
                escapeshellarg($out) . ' 2>&1';
            $newname = $base . '.m4a';
        }
        exec($cmd, $log, $code);
        if ($code !== 0 || !file_exists($out) || filesize($out) === 0) {
            mtrace('Evidence Checklist: transcode failed: ' . implode("\n", array_slice($log, -5)));
            return;
        }
        $record = ['contextid' => $file->get_contextid(), 'component' => 'mod_evidencechecklist', 'filearea' => 'cellmedia',
            'itemid' => $file->get_itemid(), 'filepath' => '/', 'filename' => $newname, 'userid' => $file->get_userid(),
            'timecreated' => $file->get_timecreated()];
        $oldhash = $file->get_contenthash();
        $file->delete();
        if (
            $existing = $fs->get_file(
                $record['contextid'],
                'mod_evidencechecklist',
                'cellmedia',
                $record['itemid'],
                '/',
                $newname
            )
        ) {
            $existing->delete();
        }
        $new = $fs->create_file_from_pathname($record, $out);
        if ($isvideo) {
            $poster = $dir . '/poster.jpg';
            exec(
                escapeshellcmd($ffmpeg) . ' -y -ss 1 -i ' . escapeshellarg($out) . ' -frames:v 1 -vf ' .
                escapeshellarg("scale='min(960,iw)':-2") . ' ' . escapeshellarg($poster) . ' 2>&1',
                $plog,
                $pcode
            );
            if ($pcode === 0 && file_exists($poster)) {
                $fs->delete_area_files($record['contextid'], 'mod_evidencechecklist', 'poster', $record['itemid']);
                $fs->create_file_from_pathname(['filearea' => 'poster', 'filename' => $base . '.jpg'] + $record, $poster);
            }
        }
        $result = $DB->get_record('evidencechecklist_result', ['id' => $record['itemid']]);
        if ($result) {
            $DB->insert_record(
                'evidencechecklist_resultlog',
                (object)['attemptid' => $result->attemptid,
                'resultid' => $result->id, 'userid' => $file->get_userid() ?: 0, 'field' => 'media:compressed',
                'oldvalue' => $oldhash, 'newvalue' => $newname . ' (' . $new->get_contenthash() . ')', 'bulk' => 0,
                'timecreated' => time()]
            );
            $DB->execute('UPDATE {evidencechecklist_attempt} SET rev = rev + 1 WHERE id = ?', [$result->attemptid]);
        }
        mtrace('Evidence Checklist: compressed ' . $newname);
    }
}
