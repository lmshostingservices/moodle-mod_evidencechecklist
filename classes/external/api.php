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

namespace mod_evidencechecklist\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_evidencechecklist\local\attempt_manager;
use mod_evidencechecklist\local\checklist;
use mod_evidencechecklist\local\state;
use moodle_exception;

/**
 * Web services for the marking screen and live student view.
 *
 * @package    mod_evidencechecklist
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api extends external_api {
    /**
     * Resolve and check access.
     *
     * @param int $cmid
     * @param int $userid student
     * @param string $mode 'read', 'assess', 'self' (student acting on own attempt) or 'finalise'
     * @return array [cm, context, checklist, attempt, isassessor]
     */
    protected static function setup(int $cmid, int $userid, string $mode): array {
        global $DB, $USER;
        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'evidencechecklist');
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/evidencechecklist:view', $context);
        if ($mode !== 'read') {
            \mod_evidencechecklist\local\entitlement::require_access();
        }
        $record = $DB->get_record('evidencechecklist', ['id' => $cm->instance], '*', MUST_EXIST);
        $cl = new checklist($record);
        $isself = ($userid == $USER->id);
        $canassess = has_capability('mod/evidencechecklist:assess', $context);
        if ($mode === 'self') {
            if (!$isself || !has_capability('mod/evidencechecklist:beassessed', $context)) {
                if (!$canassess) {
                    throw new \required_capability_exception($context, 'mod/evidencechecklist:assess', 'nopermissions', '');
                }
            }
        } else if ($mode === 'read') {
            if (!$isself && !$canassess && !has_capability('mod/evidencechecklist:viewallreports', $context)) {
                throw new \required_capability_exception($context, 'mod/evidencechecklist:assess', 'nopermissions', '');
            }
        } else {
            require_capability(
                $mode === 'finalise' ? 'mod/evidencechecklist:finalise' : 'mod/evidencechecklist:assess',
                $context
            );
            if (!$cl->assessing_allowed()) {
                throw new moodle_exception('mappinglocked', 'mod_evidencechecklist');
            }
        }
        if (!$isself) {
            attempt_manager::require_group_access($cm, $context, $userid);
            if (!is_enrolled($context, $userid, 'mod/evidencechecklist:beassessed', true)) {
                throw new moodle_exception('notastudent', 'mod_evidencechecklist');
            }
        }
        $create = ($mode !== 'read');
        $attempt = attempt_manager::current_attempt($record, $userid, $create);
        if (!$attempt && $mode === 'read') {
            $attempt = (object)['id' => 0, 'userid' => $userid, 'attemptno' => 1, 'status' => 'inprogress', 'decision' => '',
                'rev' => 0, 'feedback' => '', 'timefinalised' => 0];
        }
        $assessorview = !$isself && ($canassess || has_capability('mod/evidencechecklist:viewallreports', $context));
        if ($mode === 'self' && !$isself) {
            // Assessor completing the student's confirmations on the tablet.
            $assessorview = true;
        }
        return [$cm, $context, $cl, $attempt, $assessorview];
    }

    /**
     * Standard response with the fresh state.
     *
     * @param \cm_info $cm
     * @param \context_module $context
     * @param checklist $cl
     * @param \stdClass $attempt
     * @param bool $assessorview
     * @param array $extra
     * @return array
     */
    protected static function respond(
        $cm,
        $context,
        checklist $cl,
        \stdClass $attempt,
        bool $assessorview,
        array $extra = []
    ): array {
        global $DB;
        if (!empty($attempt->id)) {
            $attempt = $DB->get_record('evidencechecklist_attempt', ['id' => $attempt->id]);
        }
        return [
            'rev' => (int)$attempt->rev,
            'state' => json_encode(empty($attempt->id) ? null : state::attempt($cm, $context, $cl, $attempt, $assessorview)),
            'extra' => json_encode((object)$extra),
        ];
    }

    /**
     * Returns description for state responses.
     *
     * @return external_single_structure
     */
    protected static function state_returns(): external_single_structure {
        return new external_single_structure(
            [
            'rev' => new external_value(PARAM_INT, 'Revision'),
            'state' => new external_value(PARAM_RAW, 'State JSON'), // pipeline-ignore: PARAM_RAW — JSON built server side.
            'extra' => new external_value(PARAM_RAW, 'Extra JSON'), // pipeline-ignore: PARAM_RAW — JSON built server side.
            ]
        );
    }

    // Get state.

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function get_state_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'userid' => new external_value(PARAM_INT, 'Student id'),
            'since' => new external_value(PARAM_INT, 'Known revision', VALUE_DEFAULT, -1),
            ]
        );
    }

    /**
     * Get the attempt state, or only the revision when unchanged.
     *
     * @param int $cmid
     * @param int $userid
     * @param int $since
     * @return array
     */
    public static function get_state(int $cmid, int $userid, int $since = -1): array {
        $p = self::validate_parameters(self::get_state_parameters(), compact('cmid', 'userid', 'since'));
        [$cm, $context, $cl, $attempt, $assessorview] = self::setup($p['cmid'], $p['userid'], 'read');
        if ($p['since'] >= 0 && (int)$attempt->rev === $p['since']) {
            return ['rev' => (int)$attempt->rev, 'state' => 'null', 'extra' => '{}'];
        }
        return self::respond($cm, $context, $cl, $attempt, $assessorview);
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function get_state_returns(): external_single_structure {
        return self::state_returns();
    }

    // Set result.

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function set_result_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'userid' => new external_value(PARAM_INT, 'Student id'),
            'itemid' => new external_value(PARAM_INT, 'Item id'),
            'cellid' => new external_value(PARAM_TEXT, 'Cell id'),
            'value' => new external_value(PARAM_ALPHA, 'S, NYS, NO or empty', VALUE_DEFAULT, ''),
            ]
        );
    }

    /**
     * Mark one cell.
     *
     * @param int $cmid
     * @param int $userid
     * @param int $itemid
     * @param string $cellid
     * @param string $value
     * @return array
     */
    public static function set_result(int $cmid, int $userid, int $itemid, string $cellid, string $value = ''): array {
        global $USER;
        $p = self::validate_parameters(self::set_result_parameters(), compact('cmid', 'userid', 'itemid', 'cellid', 'value'));
        [$cm, $context, $cl, $attempt, $assessorview] = self::setup($p['cmid'], $p['userid'], 'assess');
        $session = attempt_manager::current_session($cl, $attempt, $USER->id);
        attempt_manager::set_result($cl, $attempt, $p['itemid'], $p['cellid'], $p['value'], $USER->id, $session->id);
        return self::respond($cm, $context, $cl, $attempt, $assessorview);
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function set_result_returns(): external_single_structure {
        return self::state_returns();
    }

    // Set text field on a cell.

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function set_cell_text_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'userid' => new external_value(PARAM_INT, 'Student id'),
            'itemid' => new external_value(PARAM_INT, 'Item id'),
            'cellid' => new external_value(PARAM_TEXT, 'Cell id'),
            'field' => new external_value(PARAM_ALPHA, 'comment, assessornote or timerseconds'),
            'value' => new external_value(PARAM_TEXT, 'Value'),
            ]
        );
    }

    /**
     * Save a comment, private note or timer.
     *
     * @param int $cmid
     * @param int $userid
     * @param int $itemid
     * @param string $cellid
     * @param string $field
     * @param string $value
     * @return array
     */
    public static function set_cell_text(
        int $cmid,
        int $userid,
        int $itemid,
        string $cellid,
        string $field,
        string $value
    ): array {
        global $USER;
        $p = self::validate_parameters(
            self::set_cell_text_parameters(),
            compact('cmid', 'userid', 'itemid', 'cellid', 'field', 'value')
        );
        [$cm, $context, $cl, $attempt, $assessorview] = self::setup($p['cmid'], $p['userid'], 'assess');
        attempt_manager::require_editable($attempt);
        if (!in_array($p['field'], ['comment', 'assessornote', 'timerseconds'], true) || !isset($cl->items[$p['itemid']])) {
            throw new moodle_exception('invalidcell', 'mod_evidencechecklist');
        }
        $value = $p['field'] === 'timerseconds' ? ($p['value'] === '' ? null : max(0, (int)$p['value'])) : trim($p['value']);
        $row = attempt_manager::cell($attempt, $cl->items[$p['itemid']], $p['cellid']);
        if (attempt_manager::update_cell_field($attempt, $row, $p['field'], $value, $USER->id)) {
            attempt_manager::touch($attempt);
        }
        return self::respond($cm, $context, $cl, $attempt, $assessorview);
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function set_cell_text_returns(): external_single_structure {
        return self::state_returns();
    }

    // Bulk fill.

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function bulk_fill_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'userid' => new external_value(PARAM_INT, 'Student id'),
            'scope' => new external_value(PARAM_TEXT, 'all, group:<id> or col:<key|n>'),
            'value' => new external_value(PARAM_ALPHA, 'S or NYS'),
            ]
        );
    }

    /**
     * Fill empty cells in a scope.
     *
     * @param int $cmid
     * @param int $userid
     * @param string $scope
     * @param string $value
     * @return array
     */
    public static function bulk_fill(int $cmid, int $userid, string $scope, string $value): array {
        global $USER;
        $p = self::validate_parameters(self::bulk_fill_parameters(), compact('cmid', 'userid', 'scope', 'value'));
        [$cm, $context, $cl, $attempt, $assessorview] = self::setup($p['cmid'], $p['userid'], 'assess');
        $session = attempt_manager::current_session($cl, $attempt, $USER->id);
        $ids = attempt_manager::bulk_fill($cl, $attempt, $p['scope'], $p['value'], $USER->id, $session->id);
        return self::respond($cm, $context, $cl, $attempt, $assessorview, ['changed' => $ids]);
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function bulk_fill_returns(): external_single_structure {
        return self::state_returns();
    }

    // Undo bulk.

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function undo_bulk_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'userid' => new external_value(PARAM_INT, 'Student id'),
            'ids' => new external_value(PARAM_SEQUENCE, 'Result ids'),
            ]
        );
    }

    /**
     * Undo the last bulk fill.
     *
     * @param int $cmid
     * @param int $userid
     * @param string $ids
     * @return array
     */
    public static function undo_bulk(int $cmid, int $userid, string $ids): array {
        global $USER;
        $p = self::validate_parameters(self::undo_bulk_parameters(), compact('cmid', 'userid', 'ids'));
        [$cm, $context, $cl, $attempt, $assessorview] = self::setup($p['cmid'], $p['userid'], 'assess');
        $list = array_filter(array_map('intval', explode(',', $p['ids'])));
        $n = attempt_manager::undo_bulk($cl, $attempt, $list, $USER->id);
        return self::respond($cm, $context, $cl, $attempt, $assessorview, ['undone' => $n]);
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function undo_bulk_returns(): external_single_structure {
        return self::state_returns();
    }

    // Save gap.

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function save_gap_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'userid' => new external_value(PARAM_INT, 'Student id'),
            'itemid' => new external_value(PARAM_INT, 'Item id'),
            'observed' => new external_value(PARAM_TEXT, 'What was observed'),
            'strategy' => new external_value(PARAM_TEXT, 'Strategy to close the gap'),
            'reobservedate' => new external_value(PARAM_INT, 'Re-observation date', VALUE_DEFAULT, 0),
            ]
        );
    }

    /**
     * Save the gap plan for an item.
     *
     * @param int $cmid
     * @param int $userid
     * @param int $itemid
     * @param string $observed
     * @param string $strategy
     * @param int $reobservedate
     * @return array
     */
    public static function save_gap(
        int $cmid,
        int $userid,
        int $itemid,
        string $observed,
        string $strategy,
        int $reobservedate = 0
    ): array {
        global $USER;
        $p = self::validate_parameters(
            self::save_gap_parameters(),
            compact('cmid', 'userid', 'itemid', 'observed', 'strategy', 'reobservedate')
        );
        [$cm, $context, $cl, $attempt, $assessorview] = self::setup($p['cmid'], $p['userid'], 'assess');
        attempt_manager::save_gap(
            $cl,
            $attempt,
            $p['itemid'],
            trim($p['observed']),
            trim($p['strategy']),
            $p['reobservedate'],
            $USER->id
        );
        return self::respond($cm, $context, $cl, $attempt, $assessorview);
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function save_gap_returns(): external_single_structure {
        return self::state_returns();
    }

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function set_gap_status_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'userid' => new external_value(PARAM_INT, 'Student id'),
            'itemid' => new external_value(PARAM_INT, 'Item id'),
            'close' => new external_value(PARAM_BOOL, 'True to close, false to reopen'),
            'note' => new external_value(PARAM_TEXT, 'Why the assessor closed it', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Close or reopen a gap plan.
     *
     * @param int $cmid
     * @param int $userid
     * @param int $itemid
     * @param bool $close
     * @param string $note
     * @return array
     */
    public static function set_gap_status(int $cmid, int $userid, int $itemid, bool $close, string $note = ''): array {
        global $USER;
        $p = self::validate_parameters(self::set_gap_status_parameters(), compact('cmid', 'userid', 'itemid', 'close', 'note'));
        [$cm, $context, $cl, $attempt, $assessorview] = self::setup($p['cmid'], $p['userid'], 'assess');
        attempt_manager::set_gap_status($cl, $attempt, $p['itemid'], $p['close'], $p['note'], $USER->id);
        return self::respond($cm, $context, $cl, $attempt, $assessorview);
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function set_gap_status_returns(): external_single_structure {
        return self::state_returns();
    }

    // Pre-assessment confirmations.

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function save_confirm_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'userid' => new external_value(PARAM_INT, 'Student id'),
            'responses' => new external_value(PARAM_SEQUENCE, 'Ticked statement numbers'),
            'note' => new external_value(PARAM_TEXT, 'Reasonable adjustment note', VALUE_DEFAULT, ''),
            'method' => new external_value(PARAM_ALPHA, 'verbal or handover (assessor only)', VALUE_DEFAULT, ''),
            ]
        );
    }

    /**
     * Save the student's pre-assessment confirmations.
     *
     * @param int $cmid
     * @param int $userid
     * @param string $responses
     * @param string $note
     * @param string $method
     * @return array
     */
    public static function save_confirm(
        int $cmid,
        int $userid,
        string $responses,
        string $note = '',
        string $method = ''
    ): array {
        global $DB, $USER;
        $p = self::validate_parameters(
            self::save_confirm_parameters(),
            compact(
                'cmid',
                'userid',
                'responses',
                'note',
                'method'
            )
        );
        [$cm, $context, $cl, $attempt, $assessorview] = self::setup($p['cmid'], $p['userid'], 'self');
        attempt_manager::require_editable($attempt);
        $ticked = array_map('intval', array_filter(explode(',', $p['responses'])));
        $statements = \mod_evidencechecklist\local\defaults::statement_list($cl->record);
        if ($USER->id == $p['userid']) {
            $method = 'own';
        } else {
            $method = $p['method'] === 'verbal' ? 'verbal' : 'assessortablet';
        }
        $DB->delete_records('evidencechecklist_confirm', ['attemptid' => $attempt->id]);
        // The adjustment note belongs to the statement about reasonable adjustment (or the third one).
        $noteno = 3;
        foreach ($statements as $i => $text) {
            if (stripos($text, 'adjust') !== false) {
                $noteno = $i + 1;
                break;
            }
        }
        foreach ($statements as $i => $text) {
            $no = $i + 1;
            $DB->insert_record(
                'evidencechecklist_confirm',
                (object)['attemptid' => $attempt->id, 'statementno' => $no,
                'statementtext' => $text, 'response' => in_array($no, $ticked) ? 1 : 0,
                'note' => $no === $noteno ? trim($p['note']) : null, 'method' => $method, 'userid' => $USER->id,
                'timecreated' => time()]
            );
        }
        attempt_manager::touch($attempt);
        return self::respond($cm, $context, $cl, $attempt, $assessorview);
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function save_confirm_returns(): external_single_structure {
        return self::state_returns();
    }

    // Session details and identity.

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function save_session_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'userid' => new external_value(PARAM_INT, 'Student id'),
            'setting' => new external_value(PARAM_TEXT, 'Setting'),
            'simulated' => new external_value(PARAM_BOOL, 'Simulated'),
            'equipment' => new external_value(PARAM_TEXT, 'Equipment'),
            'adjustments' => new external_value(PARAM_TEXT, 'Adjustments'),
            'idmethod' => new external_value(PARAM_ALPHA, 'verifyid, sighted or empty', VALUE_DEFAULT, ''),
            'iddocument' => new external_value(PARAM_TEXT, 'Document type sighted', VALUE_DEFAULT, ''),
            ]
        );
    }

    /**
     * Save today's session details and identity check.
     *
     * @param int $cmid
     * @param int $userid
     * @param string $setting
     * @param bool $simulated
     * @param string $equipment
     * @param string $adjustments
     * @param string $idmethod
     * @param string $iddocument
     * @return array
     */
    public static function save_session(
        int $cmid,
        int $userid,
        string $setting,
        bool $simulated,
        string $equipment,
        string $adjustments,
        string $idmethod = '',
        string $iddocument = ''
    ): array {
        global $DB, $USER;
        $p = self::validate_parameters(
            self::save_session_parameters(),
            compact(
                'cmid',
                'userid',
                'setting',
                'simulated',
                'equipment',
                'adjustments',
                'idmethod',
                'iddocument'
            )
        );
        [$cm, $context, $cl, $attempt, $assessorview] = self::setup($p['cmid'], $p['userid'], 'assess');
        attempt_manager::require_editable($attempt);
        $session = attempt_manager::current_session($cl, $attempt, $USER->id);
        $session->setting = $p['setting'];
        $session->simulated = $p['simulated'] ? 1 : 0;
        $session->equipment = $p['equipment'];
        $session->adjustments = $p['adjustments'];
        if (in_array($p['idmethod'], ['verifyid', 'sighted', ''], true)) {
            $session->idmethod = $p['idmethod'];
            $session->iddocument = \core_text::substr($p['iddocument'], 0, 100);
            $session->verifyidattempt = null;
            if ($p['idmethod'] === 'verifyid') {
                $ref = \mod_evidencechecklist\local\verifyid::reference($p['userid'], (int)$cm->course);
                if (!$ref) {
                    throw new moodle_exception('noverifyid', 'mod_evidencechecklist');
                }
                $session->verifyidattempt = $ref['attemptid'];
            }
        }
        $session->timemodified = time();
        $DB->update_record('evidencechecklist_session', $session);
        attempt_manager::touch($attempt);
        return self::respond($cm, $context, $cl, $attempt, $assessorview);
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function save_session_returns(): external_single_structure {
        return self::state_returns();
    }

    // Signatures.

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function save_signature_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'userid' => new external_value(PARAM_INT, 'Student id'),
            'role' => new external_value(PARAM_ALPHA, 'student or assessor'),
            'typedname' => new external_value(PARAM_TEXT, 'Typed name', VALUE_DEFAULT, ''),
            // Base64 PNG of the drawn signature; decoded and validated as an image.
            'image' => new external_value(PARAM_RAW, 'PNG data URL', VALUE_DEFAULT, ''), // pipeline-ignore: PARAM_RAW — base64 PNG.
            ]
        );
    }

    /**
     * Save an e-signature.
     *
     * @param int $cmid
     * @param int $userid
     * @param string $role
     * @param string $typedname
     * @param string $image
     * @return array
     */
    public static function save_signature(
        int $cmid,
        int $userid,
        string $role,
        string $typedname = '',
        string $image = ''
    ): array {
        global $DB, $USER;
        $p = self::validate_parameters(self::save_signature_parameters(), compact('cmid', 'userid', 'role', 'typedname', 'image'));
        if ($p['role'] === 'assessor') {
            [$cm, $context, $cl, $attempt, $assessorview] = self::setup($p['cmid'], $p['userid'], 'finalise');
            attempt_manager::require_editable($attempt);
        } else if ($p['role'] === 'student') {
            [$cm, $context, $cl, $attempt, $assessorview] = self::setup($p['cmid'], $p['userid'], 'self');
        } else {
            throw new moodle_exception('invalidrole', 'mod_evidencechecklist');
        }
        $png = null;
        if ($p['image'] !== '') {
            if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $p['image'], $m)) {
                throw new moodle_exception('invalidsignature', 'mod_evidencechecklist');
            }
            $png = base64_decode($m[1], true);
            if ($png === false || strlen($png) > 512000 || @getimagesizefromstring($png) === false) {
                throw new moodle_exception('invalidsignature', 'mod_evidencechecklist');
            }
        } else if (trim($p['typedname']) === '') {
            throw new moodle_exception('invalidsignature', 'mod_evidencechecklist');
        }
        // The student signs once per attempt; the assessor re-signs replace the earlier one.
        $signer = $p['role'] === 'student' ? $p['userid'] : $USER->id;
        $old = $DB->get_records('evidencechecklist_signature', ['attemptid' => $attempt->id, 'role' => $p['role']]);
        $fs = get_file_storage();
        foreach ($old as $o) {
            $fs->delete_area_files($context->id, 'mod_evidencechecklist', 'signature', $o->id);
            $DB->delete_records('evidencechecklist_signature', ['id' => $o->id]);
        }
        $sig = (object)['attemptid' => $attempt->id, 'userid' => $signer, 'role' => $p['role'],
            'typedname' => trim($p['typedname']), 'recordhash' => attempt_manager::record_hash($attempt),
            'device' => \core_text::substr(clean_param($_SERVER['HTTP_USER_AGENT'] ?? '', PARAM_TEXT), 0, 255),
            'timecreated' => time()];
        $sig->id = $DB->insert_record('evidencechecklist_signature', $sig);
        if ($png !== null) {
            $fs->create_file_from_string(
                ['contextid' => $context->id, 'component' => 'mod_evidencechecklist',
                'filearea' => 'signature', 'itemid' => $sig->id, 'filepath' => '/', 'filename' => 'signature.png'],
                $png
            );
        }
        attempt_manager::touch($attempt);
        return self::respond($cm, $context, $cl, $attempt, $assessorview);
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function save_signature_returns(): external_single_structure {
        return self::state_returns();
    }

    // Finalise.

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function finalise_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'userid' => new external_value(PARAM_INT, 'Student id'),
            'decision' => new external_value(PARAM_ALPHA, 'competent or nyc'),
            'feedback' => new external_value(PARAM_TEXT, 'Overall feedback', VALUE_DEFAULT, ''),
            ]
        );
    }

    /**
     * Finalise and release.
     *
     * @param int $cmid
     * @param int $userid
     * @param string $decision
     * @param string $feedback
     * @return array
     */
    public static function finalise(int $cmid, int $userid, string $decision, string $feedback = ''): array {
        global $USER;
        $p = self::validate_parameters(self::finalise_parameters(), compact('cmid', 'userid', 'decision', 'feedback'));
        [$cm, $context, $cl, $attempt, $assessorview] = self::setup($p['cmid'], $p['userid'], 'finalise');
        attempt_manager::finalise($cl, $cm, $attempt, $p['decision'], trim($p['feedback']), $USER->id);
        return self::respond($cm, $context, $cl, $attempt, $assessorview);
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function finalise_returns(): external_single_structure {
        return self::state_returns();
    }

    // Reassessment.

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function start_reassessment_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'userid' => new external_value(PARAM_INT, 'Student id'),
            ]
        );
    }

    /**
     * Start a reassessment after not yet competent.
     *
     * @param int $cmid
     * @param int $userid
     * @return array
     */
    public static function start_reassessment(int $cmid, int $userid): array {
        $p = self::validate_parameters(self::start_reassessment_parameters(), compact('cmid', 'userid'));
        [$cm, $context, $cl, $attempt, $assessorview] = self::setup($p['cmid'], $p['userid'], 'assess');
        $new = attempt_manager::start_reassessment($cl, $attempt);
        return self::respond($cm, $context, $cl, $new, $assessorview);
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function start_reassessment_returns(): external_single_structure {
        return self::state_returns();
    }

    // Delete media.

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function delete_media_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'userid' => new external_value(PARAM_INT, 'Student id'),
            'fileid' => new external_value(PARAM_INT, 'File id'),
            ]
        );
    }

    /**
     * Delete one evidence file from a cell (while in progress).
     *
     * @param int $cmid
     * @param int $userid
     * @param int $fileid
     * @return array
     */
    public static function delete_media(int $cmid, int $userid, int $fileid): array {
        global $DB;
        $p = self::validate_parameters(self::delete_media_parameters(), compact('cmid', 'userid', 'fileid'));
        [$cm, $context, $cl, $attempt, $assessorview] = self::setup($p['cmid'], $p['userid'], 'assess');
        attempt_manager::require_editable($attempt);
        $fs = get_file_storage();
        $file = $fs->get_file_by_id($p['fileid']);
        if (
            !$file || $file->get_contextid() != $context->id || $file->get_component() !== 'mod_evidencechecklist'
                || $file->get_filearea() !== 'cellmedia'
                || !$DB->record_exists('evidencechecklist_result', ['id' => $file->get_itemid(), 'attemptid' => $attempt->id])
        ) {
            throw new moodle_exception('invalidfile', 'mod_evidencechecklist');
        }
        $fs->delete_area_files($context->id, 'mod_evidencechecklist', 'poster', $file->get_itemid());
        $DB->delete_records('evidencechecklist_medialink', ['sourceid' => $file->get_itemid(),
            'filename' => $file->get_filename()]);
        $file->delete();
        attempt_manager::touch($attempt);
        return self::respond($cm, $context, $cl, $attempt, $assessorview);
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function delete_media_returns(): external_single_structure {
        return self::state_returns();
    }

    // Link one evidence file to other criteria.

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function link_media_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'userid' => new external_value(PARAM_INT, 'Student id'),
            'fileid' => new external_value(PARAM_INT, 'File id'),
            'cells' => new external_value(PARAM_TEXT, 'Comma separated cell keys (itemid#context|occasion) the file also covers'),
        ]);
    }

    /**
     * Set the full list of other criteria one evidence file covers. Linking never marks a criterion.
     *
     * @param int $cmid
     * @param int $userid
     * @param int $fileid
     * @param string $cells
     * @return array
     */
    public static function link_media(int $cmid, int $userid, int $fileid, string $cells): array {
        global $USER;
        $p = self::validate_parameters(self::link_media_parameters(), compact('cmid', 'userid', 'fileid', 'cells'));
        [$cm, $context, $cl, $attempt, $assessorview] = self::setup($p['cmid'], $p['userid'], 'assess');
        $keys = array_values(array_filter(array_map('trim', explode(',', $p['cells']))));
        $n = attempt_manager::link_media($cl, $attempt, $context, $p['fileid'], $keys, $USER->id);
        return self::respond($cm, $context, $cl, $attempt, $assessorview, ['linked' => $n]);
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function link_media_returns(): external_single_structure {
        return self::state_returns();
    }
}
