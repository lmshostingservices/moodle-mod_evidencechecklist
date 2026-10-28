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

/**
 * Evidence upload endpoint: photos (compressed in the browser), video and audio for a cell, or the candidate photo.
 *
 * @package    mod_evidencechecklist
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');

use mod_evidencechecklist\local\attempt_manager;
use mod_evidencechecklist\local\checklist;
use mod_evidencechecklist\local\state;

$cmid = required_param('cmid', PARAM_INT);
$userid = required_param('userid', PARAM_INT);
$area = required_param('area', PARAM_ALPHA);
$itemid = optional_param('itemid', 0, PARAM_INT);
$cellid = optional_param('cellid', '', PARAM_TEXT);

[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'evidencechecklist');
require_login($course, false, $cm);
require_sesskey();
$context = context_module::instance($cm->id);
require_capability('mod/evidencechecklist:assess', $context);
\mod_evidencechecklist\local\entitlement::require_access();
attempt_manager::require_group_access($cm, $context, $userid);
if (!is_enrolled($context, $userid, 'mod/evidencechecklist:beassessed', true)) {
    throw new moodle_exception('notastudent', 'mod_evidencechecklist');
}
$record = $DB->get_record('evidencechecklist', ['id' => $cm->instance], '*', MUST_EXIST);
$cl = new checklist($record);
if (!$cl->assessing_allowed()) {
    throw new moodle_exception('mappinglocked', 'mod_evidencechecklist');
}
$attempt = attempt_manager::current_attempt($record, $userid);
attempt_manager::require_editable($attempt);

if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
    throw new moodle_exception('nofile', 'mod_evidencechecklist');
}
$upload = $_FILES['file'];
$maxbytes = max(1, (int)get_config('mod_evidencechecklist', 'maxuploadmb') ?: 200) * 1024 * 1024;
$maxbytes = min($maxbytes, get_max_upload_file_size($CFG->maxbytes, $course->maxbytes));
if ($upload['size'] > $maxbytes) {
    throw new moodle_exception('media_toolarge', 'mod_evidencechecklist', '', display_size($maxbytes));
}
$mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
$allowed = [
    'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
    'video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/webm' => 'webm', 'video/x-m4v' => 'm4v',
    'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a', 'audio/mpeg' => 'mp3', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav',
    'audio/ogg' => 'ogg', 'audio/webm' => 'webm', 'audio/aac' => 'aac', 'audio/x-hx-aac-adts' => 'aac',
    'application/ogg' => 'ogg',
];
if (!isset($allowed[$mime])) {
    throw new moodle_exception('media_badtype', 'mod_evidencechecklist', '', s($mime));
}
// A WebM recorded by the browser's microphone is reported as video/webm; trust the client kind for that one case.
$kind = optional_param('kind', '', PARAM_ALPHA);
$ext = $allowed[$mime];
if ($kind === 'audio' && $ext === 'mp4') {
    // Browsers record microphone audio as MP4; store it as M4A so it is treated as audio everywhere.
    $ext = 'm4a';
}
$base = clean_filename(pathinfo($upload['name'], PATHINFO_FILENAME)) ?: 'evidence';
$prefix = $kind === 'audio' ? 'audio' : (str_starts_with($mime, 'image/') ? 'photo' : (str_starts_with($mime, 'video/')
    ? 'video' : 'audio'));
$filename = $prefix . '-' . date('Ymd-His') . '-' . substr(md5($base . microtime()), 0, 6) . '.' . $ext;

// Store photos as JPEG so every browser, Moodle version and the PDF can show them.
$path = $upload['tmp_name'];
if (in_array($mime, ['image/webp', 'image/png', 'image/gif']) && $prefix === 'photo' && function_exists('imagecreatefromstring')) {
    $img = @imagecreatefromstring(file_get_contents($path));
    if ($img) {
        $bg = imagecreatetruecolor(imagesx($img), imagesy($img));
        imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 255, 255));
        imagecopy($bg, $img, 0, 0, 0, 0, imagesx($img), imagesy($img));
        $path = make_request_directory() . '/photo.jpg';
        imagejpeg($bg, $path, 85);
        $filename = preg_replace('/\.[a-z0-9]+$/', '.jpg', $filename);
    }
}
$fs = get_file_storage();
if ($area === 'cellmedia') {
    if (!isset($cl->items[$itemid])) {
        throw new moodle_exception('invalidcell', 'mod_evidencechecklist');
    }
    $row = attempt_manager::cell($attempt, $cl->items[$itemid], $cellid);
    $fileitem = (int)$row->id;
} else if ($area === 'sessionphoto') {
    if (!str_starts_with($mime, 'image/')) {
        throw new moodle_exception('media_badtype', 'mod_evidencechecklist', '', s($mime));
    }
    $session = attempt_manager::current_session($cl, $attempt, $USER->id);
    $fileitem = (int)$session->id;
    $fs->delete_area_files($context->id, 'mod_evidencechecklist', 'sessionphoto', $fileitem);
} else {
    throw new moodle_exception('invalidarea', 'mod_evidencechecklist');
}
$file = $fs->create_file_from_pathname(
    ['contextid' => $context->id, 'component' => 'mod_evidencechecklist',
    'filearea' => $area, 'itemid' => $fileitem, 'filepath' => '/', 'filename' => $filename,
    'userid' => $USER->id],
    $path
);

if ($area === 'cellmedia' && $prefix !== 'photo' && get_config('mod_evidencechecklist', 'ffmpegpath')) {
    $task = new \mod_evidencechecklist\task\transcode_media();
    $task->set_custom_data(['fileid' => $file->get_id()]);
    \core\task\manager::queue_adhoc_task($task);
}
$DB->insert_record(
    'evidencechecklist_resultlog',
    (object)['attemptid' => $attempt->id,
    'resultid' => $area === 'cellmedia' ? $fileitem : 0, 'userid' => $USER->id, 'field' => 'media:' . $area,
    'oldvalue' => null, 'newvalue' => $filename . ' (' . $file->get_contenthash() . ')', 'bulk' => 0, 'timecreated' => time()]
);
attempt_manager::touch($attempt);

echo json_encode(
    ['ok' => true, 'rev' => (int)$attempt->rev,
    'state' => state::attempt($cm, $context, $cl, $attempt, true)]
);
