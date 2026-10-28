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

use moodle_exception;

/**
 * Attempts, grid cells, gaps, confirmations, signatures and finalising.
 *
 * @package    mod_evidencechecklist
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attempt_manager {
    /** @var string[] Allowed cell values. */
    const VALUES = ['', 'S', 'NYS', 'NO'];

    /**
     * Latest attempt for a user, created on demand.
     *
     * @param \stdClass $checklist
     * @param int $userid
     * @param bool $create
     * @return \stdClass|null
     */
    public static function current_attempt(\stdClass $checklist, int $userid, bool $create = true): ?\stdClass {
        global $DB;
        $attempts = $DB->get_records(
            'evidencechecklist_attempt',
            ['checklistid' => $checklist->id, 'userid' => $userid],
            'attemptno DESC',
            '*',
            0,
            1
        );
        if ($attempts) {
            return reset($attempts);
        }
        if (!$create) {
            return null;
        }
        $now = time();
        $attempt = (object)['checklistid' => $checklist->id, 'userid' => $userid, 'attemptno' => 1, 'status' => 'inprogress',
            'rev' => 1, 'timecreated' => $now, 'timemodified' => $now];
        try {
            $id = $DB->insert_record('evidencechecklist_attempt', $attempt);
        } catch (\dml_write_exception $e) {
            // Created concurrently by another request.
            return self::current_attempt($checklist, $userid, false);
        }
        return $DB->get_record('evidencechecklist_attempt', ['id' => $id]);
    }

    /**
     * All attempts of a user, newest first.
     *
     * @param int $checklistid
     * @param int $userid
     * @return array
     */
    public static function attempts(int $checklistid, int $userid): array {
        global $DB;
        return $DB->get_records(
            'evidencechecklist_attempt',
            ['checklistid' => $checklistid, 'userid' => $userid],
            'attemptno DESC'
        );
    }

    /**
     * Bump the revision so live viewers pick up changes.
     *
     * @param \stdClass $attempt
     */
    public static function touch(\stdClass $attempt): void {
        global $DB;
        // Increment in the database so two assessors saving at the same moment always get different revisions.
        $attempt->timemodified = time();
        $DB->execute(
            'UPDATE {evidencechecklist_attempt} SET rev = rev + 1, timemodified = ? WHERE id = ?',
            [$attempt->timemodified, $attempt->id]
        );
        $attempt->rev = (int)$DB->get_field('evidencechecklist_attempt', 'rev', ['id' => $attempt->id]);
    }

    /**
     * Ensure the attempt can still be edited.
     *
     * @param \stdClass $attempt
     */
    public static function require_editable(\stdClass $attempt): void {
        if ($attempt->status !== 'inprogress') {
            throw new moodle_exception('attemptlocked', 'mod_evidencechecklist');
        }
    }

    /**
     * Validate a cell id against an item.
     *
     * @param \stdClass $item
     * @param string $cellid 'contextkey|occasion'
     * @return array [contextkey, occasionno]
     */
    public static function parse_cell(\stdClass $item, string $cellid): array {
        if (!in_array($cellid, checklist::item_cells($item), true)) {
            throw new moodle_exception('invalidcell', 'mod_evidencechecklist');
        }
        [$key, $n] = explode('|', $cellid);
        return [$key, (int)$n];
    }

    /**
     * Get or create the result row for a cell.
     *
     * @param \stdClass $attempt
     * @param \stdClass $item
     * @param string $cellid
     * @return \stdClass
     */
    public static function cell(\stdClass $attempt, \stdClass $item, string $cellid): \stdClass {
        global $DB;
        [$key, $n] = self::parse_cell($item, $cellid);
        $params = ['attemptid' => $attempt->id, 'itemid' => $item->id, 'contextkey' => $key, 'occasionno' => $n];
        if ($row = $DB->get_record('evidencechecklist_result', $params)) {
            return $row;
        }
        $row = (object)($params + ['result' => '', 'bulk' => 0, 'carried' => 0, 'timemodified' => time()]);
        try {
            $row->id = $DB->insert_record('evidencechecklist_result', $row);
        } catch (\dml_write_exception $e) {
            return $DB->get_record('evidencechecklist_result', $params, '*', MUST_EXIST);
        }
        return $row;
    }

    /**
     * Change one field of a cell and log it.
     *
     * @param \stdClass $attempt
     * @param \stdClass $row result row
     * @param string $field
     * @param mixed $value
     * @param int $userid
     * @param bool $bulk
     * @param int|null $sessionid
     * @return bool changed
     */
    public static function update_cell_field(
        \stdClass $attempt,
        \stdClass $row,
        string $field,
        $value,
        int $userid,
        bool $bulk = false,
        ?int $sessionid = null
    ): bool {
        global $DB;
        $old = $row->$field ?? null;
        if ((string)$old === (string)$value) {
            return false;
        }
        $row->$field = $value;
        $row->assessorid = $userid;
        $row->timemodified = time();
        if ($field === 'result') {
            $row->bulk = $bulk ? 1 : 0;
            $row->carried = 0;
            if ($sessionid) {
                $row->sessionid = $sessionid;
            }
        }
        $DB->update_record('evidencechecklist_result', $row);
        $DB->insert_record(
            'evidencechecklist_resultlog',
            (object)['attemptid' => $attempt->id, 'resultid' => $row->id,
            'userid' => $userid, 'field' => $field, 'oldvalue' => $old, 'newvalue' => $value, 'bulk' => $bulk ? 1 : 0,
            'timecreated' => time()]
        );
        return true;
    }

    /**
     * Set a cell result.
     *
     * @param checklist $cl
     * @param \stdClass $attempt
     * @param int $itemid
     * @param string $cellid
     * @param string $value
     * @param int $userid
     * @param int|null $sessionid
     * @return \stdClass the row
     */
    public static function set_result(
        checklist $cl,
        \stdClass $attempt,
        int $itemid,
        string $cellid,
        string $value,
        int $userid,
        ?int $sessionid = null
    ): \stdClass {
        self::require_editable($attempt);
        if (!in_array($value, self::VALUES, true) || !isset($cl->items[$itemid])) {
            throw new moodle_exception('invalidcell', 'mod_evidencechecklist');
        }
        $item = $cl->items[$itemid];
        $row = self::cell($attempt, $item, $cellid);
        if (self::update_cell_field($attempt, $row, 'result', $value, $userid, false, $sessionid)) {
            self::sync_gap($attempt, $item, $userid);
            self::touch($attempt);
        }
        return $row;
    }

    /**
     * Set the other criteria that one evidence file also covers. The file stays stored once, on its own cell.
     * Linking never changes a result: the assessor still marks each criterion.
     *
     * @param checklist $cl
     * @param \stdClass $attempt
     * @param \context $context
     * @param int $fileid
     * @param string[] $keys cell keys 'itemid#context|occasion'
     * @param int $userid
     * @return int number of linked cells
     */
    public static function link_media(
        checklist $cl,
        \stdClass $attempt,
        \context $context,
        int $fileid,
        array $keys,
        int $userid
    ): int {
        global $DB;
        self::require_editable($attempt);
        $file = get_file_storage()->get_file_by_id($fileid);
        if (
            !$file || $file->get_contextid() != $context->id || $file->get_component() !== 'mod_evidencechecklist'
                || $file->get_filearea() !== 'cellmedia'
                || !$DB->record_exists('evidencechecklist_result', ['id' => $file->get_itemid(), 'attemptid' => $attempt->id])
        ) {
            throw new moodle_exception('invalidfile', 'mod_evidencechecklist');
        }
        $sourceid = (int)$file->get_itemid();
        $filename = $file->get_filename();
        $want = [];
        foreach (array_unique($keys) as $key) {
            if (!preg_match('/^(\d+)#(.+)$/', $key, $m) || !isset($cl->items[(int)$m[1]])) {
                throw new moodle_exception('invalidcell', 'mod_evidencechecklist');
            }
            $row = self::cell($attempt, $cl->items[(int)$m[1]], $m[2]);
            if ((int)$row->id !== $sourceid) {
                $want[(int)$row->id] = $row;
            }
        }
        $have = $DB->get_records(
            'evidencechecklist_medialink',
            ['sourceid' => $sourceid, 'filename' => $filename],
            '',
            'targetid, id'
        );
        $changed = false;
        foreach ($have as $targetid => $link) {
            if (!isset($want[$targetid])) {
                $DB->delete_records('evidencechecklist_medialink', ['id' => $link->id]);
                $DB->insert_record('evidencechecklist_resultlog', (object)['attemptid' => $attempt->id, 'resultid' => $targetid,
                    'userid' => $userid, 'field' => 'media:unlink', 'oldvalue' => $filename, 'newvalue' => null, 'bulk' => 0,
                    'timecreated' => time()]);
                $changed = true;
            }
        }
        foreach ($want as $targetid => $row) {
            if (isset($have[$targetid])) {
                continue;
            }
            $DB->insert_record('evidencechecklist_medialink', (object)['attemptid' => $attempt->id, 'sourceid' => $sourceid,
                'filename' => $filename, 'targetid' => $targetid, 'userid' => $userid, 'timecreated' => time()]);
            $DB->insert_record('evidencechecklist_resultlog', (object)['attemptid' => $attempt->id, 'resultid' => $targetid,
                'userid' => $userid, 'field' => 'media:link', 'oldvalue' => null, 'newvalue' => $filename, 'bulk' => 0,
                'timecreated' => time()]);
            $changed = true;
        }
        if ($changed) {
            self::touch($attempt);
        }
        return count($want);
    }

    /**
     * Bulk fill empty cells.
     *
     * @param checklist $cl
     * @param \stdClass $attempt
     * @param string $scope 'all', 'group:<id>' or 'col:<key|n>'
     * @param string $value S or NYS
     * @param int $userid
     * @param int|null $sessionid
     * @return int[] result ids changed (for undo)
     */
    public static function bulk_fill(
        checklist $cl,
        \stdClass $attempt,
        string $scope,
        string $value,
        int $userid,
        ?int $sessionid = null
    ): array {
        global $DB;
        self::require_editable($attempt);
        if (!in_array($value, ['S', 'NYS'], true)) {
            throw new moodle_exception('invalidcell', 'mod_evidencechecklist');
        }
        if ($value === 'S' && empty($cl->record->allowbulk)) {
            throw new moodle_exception('bulkdisabled', 'mod_evidencechecklist');
        }
        $existing = [];
        foreach ($DB->get_records('evidencechecklist_result', ['attemptid' => $attempt->id]) as $r) {
            $existing[$r->itemid . '#' . $r->contextkey . '|' . $r->occasionno] = $r;
        }
        $changed = [];
        $transaction = $DB->start_delegated_transaction();
        foreach ($cl->items as $item) {
            if (str_starts_with($scope, 'group:') && (int)substr($scope, 6) !== (int)$item->groupid) {
                continue;
            }
            foreach (checklist::item_cells($item) as $cellid) {
                if (str_starts_with($scope, 'col:') && substr($scope, 4) !== $cellid) {
                    continue;
                }
                $current = $existing[$item->id . '#' . $cellid] ?? null;
                if ($current && $current->result !== '') {
                    continue;
                }
                $row = $current ?: self::cell($attempt, $item, $cellid);
                if (self::update_cell_field($attempt, $row, 'result', $value, $userid, true, $sessionid)) {
                    $changed[] = (int)$row->id;
                }
            }
            self::sync_gap($attempt, $item, $userid);
        }
        if ($changed) {
            self::touch($attempt);
        }
        $transaction->allow_commit();
        return $changed;
    }

    /**
     * Undo a bulk fill: blanks cells that are still exactly as the bulk fill left them.
     *
     * @param checklist $cl
     * @param \stdClass $attempt
     * @param int[] $resultids
     * @param int $userid
     * @return int number undone
     */
    public static function undo_bulk(checklist $cl, \stdClass $attempt, array $resultids, int $userid): int {
        global $DB;
        self::require_editable($attempt);
        if (!$resultids) {
            return 0;
        }
        [$insql, $params] = $DB->get_in_or_equal($resultids);
        $params[] = $attempt->id;
        $rows = $DB->get_records_select('evidencechecklist_result', "id $insql AND attemptid = ? AND bulk = 1", $params);
        $n = 0;
        foreach ($rows as $row) {
            if (self::update_cell_field($attempt, $row, 'result', '', $userid, false)) {
                $n++;
                if (isset($cl->items[$row->itemid])) {
                    self::sync_gap($attempt, $cl->items[$row->itemid], $userid);
                }
            }
        }
        if ($n) {
            self::touch($attempt);
        }
        return $n;
    }

    /**
     * Open a gap when any cell of an item is NYS; close it when all required cells are S.
     *
     * @param \stdClass $attempt
     * @param \stdClass $item
     * @param int $userid
     */
    public static function sync_gap(\stdClass $attempt, \stdClass $item, int $userid): void {
        global $DB;
        $rows = $DB->get_records('evidencechecklist_result', ['attemptid' => $attempt->id, 'itemid' => $item->id]);
        $results = [];
        foreach ($rows as $r) {
            $results[$r->contextkey . '|' . $r->occasionno] = $r->result;
        }
        $hasnys = in_array('NYS', $results, true);
        $allsat = true;
        foreach (checklist::item_cells($item) as $cellid) {
            if (($results[$cellid] ?? '') !== 'S') {
                $allsat = false;
            }
        }
        $gap = $DB->get_record('evidencechecklist_gap', ['attemptid' => $attempt->id, 'itemid' => $item->id]);
        if ($hasnys && (!$gap || $gap->status !== 'open')) {
            if ($gap) {
                $gap->status = 'open';
                $gap->closereason = null;
                $gap->closenote = null;
                $gap->closedby = null;
                $gap->timeclosed = null;
                $gap->timemodified = time();
                $DB->update_record('evidencechecklist_gap', $gap);
            } else {
                $DB->insert_record(
                    'evidencechecklist_gap',
                    (object)['attemptid' => $attempt->id, 'itemid' => $item->id,
                    'status' => 'open', 'assessorid' => $userid, 'strategy' => $item->gaptip, 'timemodified' => time()]
                );
            }
        } else if ($gap && $gap->status === 'open' && $allsat) {
            // Re-observed as satisfactory in every cell: the plan has done its job.
            $gap->status = 'closed';
            $gap->closereason = 'reobserved';
            $gap->closedby = $userid;
            $gap->timeclosed = time();
            $gap->timemodified = time();
            $DB->update_record('evidencechecklist_gap', $gap);
        }
    }

    /**
     * Close a gap plan by the assessor's decision, or reopen it.
     *
     * Closing is refused while any cell of the item is still NYS; a re-observation as S closes it automatically.
     *
     * @param checklist $cl
     * @param \stdClass $attempt
     * @param int $itemid
     * @param bool $close
     * @param string $note why the assessor closed it
     * @param int $userid
     */
    public static function set_gap_status(
        checklist $cl,
        \stdClass $attempt,
        int $itemid,
        bool $close,
        string $note,
        int $userid
    ): void {
        global $DB;
        self::require_editable($attempt);
        if (!isset($cl->items[$itemid])) {
            throw new moodle_exception('invalidcell', 'mod_evidencechecklist');
        }
        $gap = $DB->get_record('evidencechecklist_gap', ['attemptid' => $attempt->id, 'itemid' => $itemid], '*', MUST_EXIST);
        if ($close) {
            $nys = $DB->record_exists('evidencechecklist_result', ['attemptid' => $attempt->id, 'itemid' => $itemid,
                'result' => 'NYS']);
            if ($nys) {
                throw new moodle_exception('gapstillnys', 'mod_evidencechecklist');
            }
            $gap->status = 'closed';
            $gap->closereason = 'assessor';
            $gap->closenote = trim($note) !== '' ? trim($note) : null;
            $gap->closedby = $userid;
            $gap->timeclosed = time();
        } else {
            $gap->status = 'open';
            $gap->closereason = null;
            $gap->closenote = null;
            $gap->closedby = null;
            $gap->timeclosed = null;
        }
        $gap->timemodified = time();
        $DB->update_record('evidencechecklist_gap', $gap);
        checklist::log($cl->record->id, $userid, $close ? 'gapclosed' : 'gapreopened', json_encode(['attemptid' => $attempt->id,
            'itemid' => $itemid]));
        self::touch($attempt);
    }

    /**
     * Save a gap plan.
     *
     * @param checklist $cl
     * @param \stdClass $attempt
     * @param int $itemid
     * @param string $observed
     * @param string $strategy
     * @param int $reobservedate
     * @param int $userid
     */
    public static function save_gap(
        checklist $cl,
        \stdClass $attempt,
        int $itemid,
        string $observed,
        string $strategy,
        int $reobservedate,
        int $userid
    ): void {
        global $DB;
        self::require_editable($attempt);
        if (!isset($cl->items[$itemid])) {
            throw new moodle_exception('invalidcell', 'mod_evidencechecklist');
        }
        $gap = $DB->get_record('evidencechecklist_gap', ['attemptid' => $attempt->id, 'itemid' => $itemid]);
        $data = ['observed' => $observed, 'strategy' => $strategy, 'reobservedate' => $reobservedate ?: null,
            'assessorid' => $userid, 'timemodified' => time()];
        if ($gap) {
            $DB->update_record('evidencechecklist_gap', (object)(['id' => $gap->id] + $data));
        } else {
            $DB->insert_record(
                'evidencechecklist_gap',
                (object)(['attemptid' => $attempt->id, 'itemid' => $itemid,
                'status' => 'open'] + $data)
            );
        }
        self::touch($attempt);
    }

    /**
     * Progress for an attempt.
     *
     * @param checklist $cl
     * @param array $results keyed 'itemid#key|n' => result
     * @return array
     */
    public static function progress(checklist $cl, array $results): array {
        $required = $s = $nys = 0;
        $groups = [];
        $columns = [];
        foreach ($cl->items as $item) {
            foreach (checklist::item_cells($item) as $cellid) {
                $value = $results[$item->id . '#' . $cellid] ?? '';
                $g = &$groups[$item->groupid];
                $c = &$columns[$cellid];
                $g = $g ?? ['required' => 0, 's' => 0, 'nys' => 0];
                $c = $c ?? ['required' => 0, 's' => 0, 'nys' => 0];
                if ($item->mandatory) {
                    $required++;
                    $g['required']++;
                    $c['required']++;
                    if ($value === 'S') {
                        $s++;
                        $g['s']++;
                        $c['s']++;
                    } else if ($value === 'NYS') {
                        $nys++;
                        $g['nys']++;
                        $c['nys']++;
                    }
                }
                unset($g, $c);
            }
        }
        return ['required' => $required, 's' => $s, 'nys' => $nys, 'todo' => max(0, $required - $s - $nys),
            'percent' => $required ? (int)floor($s / $required * 100) : 0, 'complete' => $required > 0 && $s === $required,
            'groups' => $groups, 'columns' => $columns];
    }

    /**
     * Results of an attempt keyed 'itemid#key|n'.
     *
     * @param int $attemptid
     * @return array [map of values, rows keyed by id]
     */
    public static function results(int $attemptid): array {
        global $DB;
        $rows = $DB->get_records('evidencechecklist_result', ['attemptid' => $attemptid]);
        $map = [];
        foreach ($rows as $r) {
            $map[$r->itemid . '#' . $r->contextkey . '|' . $r->occasionno] = $r->result;
        }
        return [$map, $rows];
    }

    /**
     * The open session for an assessor today, created on demand.
     *
     * @param checklist $cl
     * @param \stdClass $attempt
     * @param int $assessorid
     * @param bool $create
     * @return \stdClass|null
     */
    public static function current_session(checklist $cl, \stdClass $attempt, int $assessorid, bool $create = true): ?\stdClass {
        global $DB;
        $since = usergetmidnight(time());
        $sessions = $DB->get_records_select(
            'evidencechecklist_session',
            'attemptid = ? AND assessorid = ? AND timestart >= ?',
            [$attempt->id, $assessorid, $since],
            'timestart DESC',
            '*',
            0,
            1
        );
        if ($sessions) {
            return reset($sessions);
        }
        if (!$create) {
            return null;
        }
        $session = (object)['attemptid' => $attempt->id, 'assessorid' => $assessorid, 'setting' => $cl->record->setting,
            'simulated' => 1, 'equipment' => $cl->record->equipment, 'timestart' => time(), 'timemodified' => time()];
        $session->id = $DB->insert_record('evidencechecklist_session', $session);
        return $session;
    }

    /**
     * Data for files to find their attempt (for pluginfile access checks).
     *
     * @param string $filearea
     * @param int $itemid
     * @param int $checklistid
     * @return \stdClass|null attempt
     */
    public static function attempt_for_file(string $filearea, int $itemid, int $checklistid): ?\stdClass {
        global $DB;
        $table = [
            'cellmedia' => 'evidencechecklist_result',
            'poster' => 'evidencechecklist_result',
            'sessionphoto' => 'evidencechecklist_session',
            'signature' => 'evidencechecklist_signature',
        ][$filearea] ?? null;
        if (!$table) {
            return null;
        }
        $attempt = $DB->get_record_sql(
            "SELECT a.*
                                          FROM {{$table}} x
                                          JOIN {evidencechecklist_attempt} a ON a.id = x.attemptid
                                         WHERE x.id = ? AND a.checklistid = ?",
            [$itemid, $checklistid]
        );
        return $attempt ?: null;
    }

    /**
     * Separate groups: staff must share a group with the student unless they can access all groups.
     *
     * @param \cm_info|\stdClass $cm
     * @param \context_module $context
     * @param int $userid student
     */
    public static function require_group_access($cm, \context_module $context, int $userid): void {
        global $USER;
        $mode = groups_get_activity_groupmode($cm);
        if ($mode != SEPARATEGROUPS || has_capability('moodle/site:accessallgroups', $context) || $userid == $USER->id) {
            return;
        }
        $mine = groups_get_all_groups($cm->course, $USER->id, $cm->groupingid);
        foreach ($mine as $g) {
            if (groups_is_member($g->id, $userid)) {
                return;
            }
        }
        throw new moodle_exception('nogroupaccess', 'mod_evidencechecklist');
    }

    /**
     * Grade value for a decision.
     *
     * @param \stdClass $checklist
     * @param string|null $decision
     * @return float|null
     */
    public static function grade_value(\stdClass $checklist, ?string $decision): ?float {
        global $DB;
        if (!$decision) {
            return null;
        }
        if ($checklist->grade > 0) {
            return $decision === 'competent' ? (float)$checklist->grade : 0.0;
        }
        if ($checklist->grade < 0) {
            $scale = $DB->get_record('scale', ['id' => -$checklist->grade]);
            $count = $scale ? count(explode(',', $scale->scale)) : 2;
            return $decision === 'competent' ? (float)$count : 1.0;
        }
        return null;
    }

    /**
     * Canonical hash of the record as it stands.
     *
     * @param \stdClass $attempt
     * @return string
     */
    public static function record_hash(\stdClass $attempt): string {
        global $DB;
        $data = [
            'attempt' => [$attempt->id, $attempt->userid, $attempt->attemptno, $attempt->decision ?? ''],
            'results' => array_values(
                array_map(
                    fn($r) => [$r->itemid, $r->contextkey, $r->occasionno, $r->result,
                    (string)$r->comment, $r->timemodified],
                    $DB->get_records('evidencechecklist_result', ['attemptid' => $attempt->id], 'id')
                )
            ),
            'gaps' => array_values(
                array_map(
                    fn($g) => [$g->itemid, (string)$g->observed, (string)$g->strategy, $g->status],
                    $DB->get_records('evidencechecklist_gap', ['attemptid' => $attempt->id], 'id')
                )
            ),
            'confirm' => array_values(
                array_map(
                    fn($c) => [$c->statementno, $c->response, $c->timecreated],
                    $DB->get_records('evidencechecklist_confirm', ['attemptid' => $attempt->id], 'id')
                )
            ),
        ];
        return hash('sha256', json_encode($data));
    }

    /**
     * Can this attempt be finalised as competent?
     *
     * @param checklist $cl
     * @param \stdClass $attempt
     * @return array list of problems (empty when ready)
     */
    public static function competent_blockers(checklist $cl, \stdClass $attempt): array {
        [$map] = self::results($attempt->id);
        $progress = self::progress($cl, $map);
        $problems = [];
        if (!$progress['complete']) {
            $problems[] = get_string(
                'blocker_cells',
                'mod_evidencechecklist',
                (object)['s' => $progress['s'],
                'required' => $progress['required']]
            );
        }
        return $problems;
    }

    /**
     * Finalise an attempt.
     *
     * @param checklist $cl
     * @param \cm_info|\stdClass $cm
     * @param \stdClass $attempt
     * @param string $decision competent|nyc
     * @param string $feedback
     * @param int $userid
     */
    public static function finalise(
        checklist $cl,
        $cm,
        \stdClass $attempt,
        string $decision,
        string $feedback,
        int $userid
    ): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/evidencechecklist/lib.php');
        require_once($CFG->libdir . '/completionlib.php');
        self::require_editable($attempt);
        if (!in_array($decision, ['competent', 'nyc'], true)) {
            throw new moodle_exception('invaliddecision', 'mod_evidencechecklist');
        }
        if ($decision === 'competent' && self::competent_blockers($cl, $attempt)) {
            throw new moodle_exception('notreadycompetent', 'mod_evidencechecklist');
        }
        if ($decision === 'nyc') {
            $open = $DB->get_records('evidencechecklist_gap', ['attemptid' => $attempt->id, 'status' => 'open']);
            foreach ($open as $gap) {
                if (trim((string)$gap->strategy) === '' && trim((string)$gap->observed) === '') {
                    throw new moodle_exception('gapnotesneeded', 'mod_evidencechecklist');
                }
            }
        }
        if (!$DB->record_exists('evidencechecklist_signature', ['attemptid' => $attempt->id, 'role' => 'assessor'])) {
            throw new moodle_exception('assessorsignatureneeded', 'mod_evidencechecklist');
        }
        $attempt->decision = $decision;
        $attempt->feedback = $feedback;
        $attempt->assessorid = $userid;
        $attempt->status = 'finalised';
        $attempt->timefinalised = time();
        $attempt->recordhash = self::record_hash($attempt);
        $attempt->rev = (int)$DB->get_field('evidencechecklist_attempt', 'rev', ['id' => $attempt->id]) + 1;
        $attempt->timemodified = time();
        $DB->update_record('evidencechecklist_attempt', $attempt);

        $checklist = $cl->record;
        evidencechecklist_update_grades($checklist, $attempt->userid);
        $course = get_course($cm->course);
        $completion = new \completion_info($course);
        if ($completion->is_enabled($cm) && !empty($checklist->completioncompetent)) {
            $completion->update_state($cm, COMPLETION_UNKNOWN, $attempt->userid);
        }
        $event = \mod_evidencechecklist\event\attempt_finalised::create(
            [
            'objectid' => $attempt->id,
            'context' => \context_module::instance($cm->id),
            'relateduserid' => $attempt->userid,
            'other' => ['decision' => $decision],
            ]
        );
        $event->trigger();
        self::notify_student($cm, $checklist, $attempt);
    }

    /**
     * Start a reassessment after a not yet competent result. Satisfactory cells carry forward.
     *
     * @param checklist $cl
     * @param \stdClass $attempt finalised NYC attempt
     * @return \stdClass new attempt
     */
    public static function start_reassessment(checklist $cl, \stdClass $attempt): \stdClass {
        global $DB;
        if ($attempt->status !== 'finalised' || $attempt->decision !== 'nyc') {
            throw new moodle_exception('cannotreassess', 'mod_evidencechecklist');
        }
        $now = time();
        $new = (object)['checklistid' => $attempt->checklistid, 'userid' => $attempt->userid,
            'attemptno' => $attempt->attemptno + 1, 'status' => 'inprogress', 'rev' => 1, 'timecreated' => $now,
            'timemodified' => $now];
        $new = $DB->get_record('evidencechecklist_attempt', ['id' => $DB->insert_record('evidencechecklist_attempt', $new)]);
        foreach ($DB->get_records('evidencechecklist_result', ['attemptid' => $attempt->id, 'result' => 'S']) as $r) {
            unset($r->id);
            $r->attemptid = $new->id;
            $r->carried = 1;
            $r->bulk = 0;
            $DB->insert_record('evidencechecklist_result', $r);
        }
        // Open gap plans carry forward as the to-do list for the reassessment; each closes when re-observed as S.
        foreach ($DB->get_records('evidencechecklist_gap', ['attemptid' => $attempt->id, 'status' => 'open']) as $g) {
            unset($g->id);
            $g->attemptid = $new->id;
            $DB->insert_record('evidencechecklist_gap', $g);
        }
        // Pre-assessment confirmations are not copied: the student confirms again for the reassessment.
        return $new;
    }

    /**
     * Tell the student their result is released.
     *
     * @param \cm_info|\stdClass $cm
     * @param \stdClass $checklist
     * @param \stdClass $attempt
     */
    protected static function notify_student($cm, \stdClass $checklist, \stdClass $attempt): void {
        global $USER;
        $url = new \moodle_url('/mod/evidencechecklist/view.php', ['id' => $cm->id]);
        $message = new \core\message\message();
        $message->component = 'mod_evidencechecklist';
        $message->name = 'resultreleased';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $attempt->userid;
        $message->subject = get_string('resultreleased_subject', 'mod_evidencechecklist', format_string($checklist->name));
        $a = (object)['name' => format_string($checklist->name),
            'decision' => get_string('decision_' . $attempt->decision, 'mod_evidencechecklist'), 'url' => $url->out(false)];
        $message->fullmessage = get_string('resultreleased_body', 'mod_evidencechecklist', $a);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '';
        $message->smallmessage = $message->subject;
        $message->notification = 1;
        $message->contexturl = $url->out(false);
        $message->contexturlname = format_string($checklist->name);
        $message->courseid = $cm->course;
        try {
            message_send($message);
        } catch (\Throwable $e) {
            debugging('Evidence Checklist notification failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
