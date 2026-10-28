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

namespace mod_evidencechecklist\local;

/**
 * Builds the JSON state used by the marking screen (assessor) and the live view (student).
 * Assessor-only data is never included for students.
 *
 * @package    mod_evidencechecklist
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class state {
    /**
     * Static structure (sent once).
     *
     * @param \cm_info|\stdClass $cm
     * @param \context_module $context
     * @param checklist $cl
     * @return array
     */
    public static function structure($cm, \context_module $context, checklist $cl): array {
        $fs = get_file_storage();
        $images = [];
        $files = $fs->get_area_files($context->id, 'mod_evidencechecklist', 'itemimage', false, 'itemid, filename', false);
        foreach ($files as $f) {
            $images[$f->get_itemid()] = self::fileurl($context, $f);
        }
        $groups = [];
        foreach ($cl->groups as $group) {
            $items = [];
            foreach ($group->items as $item) {
                $items[] = [
                    'id' => (int)$item->id,
                    'number' => $item->number,
                    'criterion' => format_text($item->criterion, FORMAT_PLAIN, ['context' => $context, 'para' => false]),
                    'benchmark' => format_text((string)$item->benchmark, FORMAT_PLAIN, ['context' => $context, 'para' => false]),
                    'gaptip' => format_string((string)$item->gaptip, true, ['context' => $context]),
                    'refs' => (string)$item->mappingrefs,
                    'verbal' => (bool)$item->verbal,
                    'mandatory' => (bool)$item->mandatory,
                    'cells' => checklist::item_cells($item),
                    'once' => !empty($item->once),
                    'image' => $images[$item->id] ?? '',
                ];
            }
            $groups[] = ['id' => (int)$group->id, 'number' => $group->number,
                'name' => format_string($group->name, true, ['context' => $context]), 'items' => $items];
        }
        [$green, $magenta] = defaults::colours();
        return [
            'cmid' => (int)$cm->id,
            'name' => format_string($cl->record->name, true, ['context' => $context]),
            'unitcode' => s($cl->record->unitcode),
            'unittitle' => format_string((string)$cl->record->unittitle, true, ['context' => $context]),
            'columns' => $cl->columns(),
            'groups' => $groups,
            'green' => $green,
            'magenta' => $magenta,
            'allowbulk' => (bool)$cl->record->allowbulk,
            'requirephoto' => (bool)$cl->record->requirephoto,
            'mapping' => $cl->mapping_state(),
            'assessingallowed' => $cl->assessing_allowed(),
            'dictation' => (bool)get_config('mod_evidencechecklist', 'dictation'),
            'maxpx' => (int)(get_config('mod_evidencechecklist', 'imagemaxpx') ?: 1600),
            'maxvideo' => (int)(get_config('mod_evidencechecklist', 'maxvideoseconds') ?: 120),
        ];
    }

    /**
     * Attempt state (polled for live updates).
     *
     * @param \cm_info|\stdClass $cm
     * @param \context_module $context
     * @param checklist $cl
     * @param \stdClass $attempt
     * @param bool $assessorview
     * @return array
     */
    public static function attempt($cm, \context_module $context, checklist $cl, \stdClass $attempt, bool $assessorview): array {
        global $DB, $USER;
        $live = $assessorview || !empty($cl->record->livestudentview) || $attempt->status === 'finalised';
        [$map, $rows] = attempt_manager::results($attempt->id);
        $progress = attempt_manager::progress($cl, $map);

        $fs = get_file_storage();
        $media = [];
        $posters = [];
        foreach ($fs->get_area_files($context->id, 'mod_evidencechecklist', 'poster', false, 'itemid', false) as $f) {
            $posters[$f->get_itemid()] = self::fileurl($context, $f);
        }
        if ($rows) {
            $files = $fs->get_area_files($context->id, 'mod_evidencechecklist', 'cellmedia', false, 'itemid, timecreated', false);
            foreach ($files as $f) {
                if (!isset($rows[$f->get_itemid()])) {
                    continue;
                }
                $mime = $f->get_mimetype();
                $ext = strtolower(pathinfo($f->get_filename(), PATHINFO_EXTENSION));
                if ($mime === 'document/unknown' && in_array($ext, ['webp', 'jpg', 'jpeg', 'png', 'gif'])) {
                    $mime = 'image/' . $ext;
                }
                $type = str_starts_with($mime, 'image/') ? 'image' : (str_starts_with($mime, 'video/') ? 'video'
                    : (str_starts_with($mime, 'audio/') ? 'audio' : 'file'));
                if (str_starts_with($f->get_filename(), 'audio-')) {
                    // Recorded audio can arrive in a video container (WebM or MP4).
                    $type = 'audio';
                }
                $media[$f->get_itemid()][] = ['url' => self::fileurl($context, $f), 'type' => $type,
                    'name' => $f->get_filename(), 'size' => (int)$f->get_filesize(), 'id' => (int)$f->get_id(),
                    'poster' => $posters[$f->get_itemid()] ?? ''];
            }
        }

        if ($rows && !empty($attempt->id)) {
            // One file can also cover other criteria: show it on every linked cell, stored once.
            foreach ($DB->get_records('evidencechecklist_medialink', ['attemptid' => $attempt->id], 'id') as $lk) {
                if (!isset($rows[$lk->sourceid]) || !isset($rows[$lk->targetid])) {
                    continue;
                }
                $src = $rows[$lk->sourceid];
                $tgt = $rows[$lk->targetid];
                foreach ($media[$lk->sourceid] ?? [] as $k => $m) {
                    if ($m['name'] !== $lk->filename) {
                        continue;
                    }
                    $media[$lk->sourceid][$k]['links'][] = $tgt->itemid . '#' . $tgt->contextkey . '|' . $tgt->occasionno;
                    unset($m['links']);
                    $media[$lk->targetid][] = $m + ['linked' => true, 'srcid' => (int)$src->id,
                        'from' => $src->itemid . '#' . $src->contextkey . '|' . $src->occasionno,
                        'fromno' => isset($cl->items[$src->itemid]) ? $cl->items[$src->itemid]->number : ''];
                }
            }
        }

        $cells = [];
        if ($live) {
            foreach ($rows as $r) {
                $hasnote = $assessorview && trim((string)$r->assessornote) !== '';
                if ($r->result === '' && trim((string)$r->comment) === '' && empty($media[$r->id]) && !$hasnote) {
                    continue;
                }
                $cell = [
                    'id' => (int)$r->id,
                    'r' => $r->result,
                    'c' => (string)$r->comment,
                    'bulk' => (bool)$r->bulk,
                    'carried' => (bool)$r->carried,
                    'timer' => $r->timerseconds !== null ? (int)$r->timerseconds : null,
                    'time' => (int)$r->timemodified,
                    'media' => $media[$r->id] ?? [],
                ];
                if ($assessorview) {
                    $cell['note'] = (string)$r->assessornote;
                }
                $cells[$r->itemid . '#' . $r->contextkey . '|' . $r->occasionno] = $cell;
            }
        }

        $gaps = [];
        if ($live) {
            foreach ($DB->get_records('evidencechecklist_gap', ['attemptid' => $attempt->id]) as $g) {
                $gaps[$g->itemid] = ['observed' => (string)$g->observed, 'strategy' => (string)$g->strategy,
                    'reobservedate' => (int)$g->reobservedate, 'status' => $g->status,
                    'closereason' => (string)($g->closereason ?? ''), 'closenote' => (string)($g->closenote ?? ''),
                    'timeclosed' => (int)($g->timeclosed ?? 0),
                    'closedby' => !empty($g->closedby) ? fullname(\core_user::get_user($g->closedby)) : ''];
            }
        }

        $statements = defaults::statement_list($cl->record);
        $confirms = $DB->get_records('evidencechecklist_confirm', ['attemptid' => $attempt->id], 'statementno');
        $byno = [];
        foreach ($confirms as $c) {
            $byno[$c->statementno] = $c;
        }
        $clist = [];
        foreach ($statements as $i => $text) {
            $c = $byno[$i + 1] ?? null;
            $clist[] = ['no' => $i + 1, 'text' => format_string($text, true, ['context' => $context]),
                'response' => $c ? (int)$c->response : null, 'note' => $c ? (string)$c->note : '',
                'time' => $c ? (int)$c->timecreated : 0, 'method' => $c ? $c->method : '',
                'question' => format_string(defaults::question($text), true, ['context' => $context]),
                'who' => $c ? fullname(\core_user::get_user($c->userid)) : ''];
        }
        $confirmed = $statements && count(array_filter($clist, fn($c) => $c['response'] === 1)) === count($statements);

        $sigs = [];
        foreach ($DB->get_records('evidencechecklist_signature', ['attemptid' => $attempt->id], 'timecreated') as $sig) {
            $file = self::first_file($context, 'signature', $sig->id);
            $sigs[] = ['role' => $sig->role, 'name' => fullname(\core_user::get_user($sig->userid)),
                'typed' => (string)$sig->typedname, 'time' => (int)$sig->timecreated, 'url' => $file];
        }

        $student = \core_user::get_user($attempt->userid);
        $out = [
            'attempt' => ['id' => (int)$attempt->id, 'no' => (int)$attempt->attemptno, 'status' => $attempt->status,
                'decision' => (string)$attempt->decision, 'rev' => (int)$attempt->rev,
                'feedback' => $attempt->status === 'finalised' || $assessorview ? (string)$attempt->feedback : '',
                'timefinalised' => (int)$attempt->timefinalised],
            'student' => ['id' => (int)$student->id, 'name' => fullname($student)],
            'live' => $live,
            'cells' => (object)$cells,
            'gaps' => (object)$gaps,
            'progress' => $progress,
            'confirm' => ['statements' => $clist, 'done' => $confirmed || !$statements],
            'signatures' => $sigs,
            'viewer' => ['id' => (int)$USER->id, 'assessor' => $assessorview],
        ];
        if (!$live) {
            $out['progress'] = ['required' => $progress['required'], 's' => 0, 'nys' => 0, 'todo' => $progress['required'],
                'percent' => 0, 'complete' => false, 'groups' => [], 'columns' => [], 'hidden' => true];
        }

        // Sessions with candidate photos.
        $sessions = [];
        foreach ($DB->get_records('evidencechecklist_session', ['attemptid' => $attempt->id], 'timestart DESC') as $s) {
            $sessions[] = ['id' => (int)$s->id, 'time' => (int)$s->timestart,
                'assessor' => fullname(\core_user::get_user($s->assessorid)),
                'setting' => (string)$s->setting, 'simulated' => (bool)$s->simulated, 'equipment' => (string)$s->equipment,
                'adjustments' => $assessorview ? (string)$s->adjustments : '',
                'idmethod' => (string)$s->idmethod, 'iddocument' => (string)$s->iddocument,
                'verifyidattempt' => (int)$s->verifyidattempt,
                'photo' => self::first_file($context, 'sessionphoto', $s->id)];
        }
        $out['sessions'] = $sessions;
        if ($assessorview) {
            $out['verifyid'] = verifyid::reference((int)$attempt->userid, (int)$cm->course, true);
            $out['blockers'] = attempt_manager::competent_blockers($cl, $attempt);
        }
        return $out;
    }

    /**
     * Pluginfile URL for a stored file.
     *
     * @param \context $context
     * @param \stored_file $f
     * @return string
     */
    public static function fileurl(\context $context, \stored_file $f): string {
        $url = \moodle_url::make_pluginfile_url(
            $context->id,
            'mod_evidencechecklist',
            $f->get_filearea(),
            $f->get_itemid(),
            $f->get_filepath(),
            $f->get_filename()
        );
        return $url->out(false);
    }

    /**
     * URL of the first file in an area/item.
     *
     * @param \context $context
     * @param string $area
     * @param int $itemid
     * @return string
     */
    public static function first_file(\context $context, string $area, int $itemid): string {
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'mod_evidencechecklist', $area, $itemid, 'timecreated DESC', false);
        return $files ? self::fileurl($context, reset($files)) : '';
    }
}
