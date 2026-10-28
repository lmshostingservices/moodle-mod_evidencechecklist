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
 * Library functions for Evidence Checklist.
 *
 * @package    mod_evidencechecklist
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Supported features.
 *
 * @param string $feature FEATURE_xx constant
 * @return mixed
 */
function evidencechecklist_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
        case FEATURE_SHOW_DESCRIPTION:
        case FEATURE_BACKUP_MOODLE2:
        case FEATURE_COMPLETION_TRACKS_VIEWS:
        case FEATURE_COMPLETION_HAS_RULES:
        case FEATURE_GRADE_HAS_GRADE:
        case FEATURE_GROUPS:
        case FEATURE_GROUPINGS:
            return true;
        case FEATURE_GRADE_OUTCOMES:
            return false;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_ASSESSMENT;
        default:
            return null;
    }
}

/**
 * Add a new instance.
 *
 * @param stdClass $data
 * @param mod_evidencechecklist_mod_form|null $mform
 * @return int
 */
function evidencechecklist_add_instance($data, $mform = null) {
    global $DB;
    \mod_evidencechecklist\local\entitlement::require_access();
    $data->timecreated = time();
    $data->timemodified = $data->timecreated;
    if (!isset($data->statements) || trim((string)$data->statements) === '') {
        $data->statements = \mod_evidencechecklist\local\defaults::statements();
    }
    $data->id = $DB->insert_record('evidencechecklist', $data);
    $completiontimeexpected = !empty($data->completionexpected) ? $data->completionexpected : null;
    \core_completion\api::update_completion_date_event(
        $data->coursemodule,
        'evidencechecklist',
        $data->id,
        $completiontimeexpected
    );
    evidencechecklist_grade_item_update($data);
    return $data->id;
}

/**
 * Update an instance.
 *
 * @param stdClass $data
 * @param mod_evidencechecklist_mod_form|null $mform
 * @return bool
 */
function evidencechecklist_update_instance($data, $mform = null) {
    global $DB;
    \mod_evidencechecklist\local\entitlement::require_access();
    $data->timemodified = time();
    $data->id = $data->instance;
    $DB->update_record('evidencechecklist', $data);
    $completiontimeexpected = !empty($data->completionexpected) ? $data->completionexpected : null;
    \core_completion\api::update_completion_date_event(
        $data->coursemodule,
        'evidencechecklist',
        $data->id,
        $completiontimeexpected
    );
    $checklist = $DB->get_record('evidencechecklist', ['id' => $data->id]);
    $checklist->cmidnumber = $data->cmidnumber ?? '';
    evidencechecklist_grade_item_update($checklist);
    evidencechecklist_update_grades($checklist);
    return true;
}

/**
 * Delete an instance and all its data.
 *
 * @param int $id
 * @return bool
 */
function evidencechecklist_delete_instance($id) {
    global $DB;
    if (!$checklist = $DB->get_record('evidencechecklist', ['id' => $id])) {
        return false;
    }
    // Grade item first, while the instance still exists.
    evidencechecklist_grade_item_delete($checklist);

    $attemptids = $DB->get_fieldset_select('evidencechecklist_attempt', 'id', 'checklistid = ?', [$id]);
    if ($attemptids) {
        [$insql, $params] = $DB->get_in_or_equal($attemptids);
        foreach (
            ['evidencechecklist_result', 'evidencechecklist_resultlog', 'evidencechecklist_gap',
                'evidencechecklist_confirm', 'evidencechecklist_signature', 'evidencechecklist_session',
                'evidencechecklist_medialink'] as $table
        ) {
            $DB->delete_records_select($table, "attemptid $insql", $params);
        }
    }
    $DB->delete_records('evidencechecklist_attempt', ['checklistid' => $id]);
    $reqids = $DB->get_fieldset_select('evidencechecklist_req', 'id', 'checklistid = ?', [$id]);
    if ($reqids) {
        [$insql, $params] = $DB->get_in_or_equal($reqids);
        $DB->delete_records_select('evidencechecklist_reqmap', "reqid $insql", $params);
    }
    $DB->delete_records('evidencechecklist_req', ['checklistid' => $id]);
    $DB->delete_records('evidencechecklist_item', ['checklistid' => $id]);
    $DB->delete_records('evidencechecklist_group', ['checklistid' => $id]);
    $DB->delete_records('evidencechecklist_maplog', ['checklistid' => $id]);
    $DB->delete_records('evidencechecklist', ['id' => $id]);
    // Files are removed by core when the module context is deleted.
    return true;
}

/**
 * Course module info, including custom completion rules.
 *
 * @param stdClass $coursemodule
 * @return cached_cm_info|false
 */
function evidencechecklist_get_coursemodule_info($coursemodule) {
    global $DB;
    $fields = 'id, name, intro, introformat, completioncompetent';
    if (!$checklist = $DB->get_record('evidencechecklist', ['id' => $coursemodule->instance], $fields)) {
        return false;
    }
    $info = new cached_cm_info();
    $info->name = $checklist->name;
    if ($coursemodule->showdescription) {
        $info->content = format_module_intro('evidencechecklist', $checklist, $coursemodule->id, false);
    }
    if ($coursemodule->completion == COMPLETION_TRACKING_AUTOMATIC) {
        $info->customdata['customcompletionrules']['completioncompetent'] = $checklist->completioncompetent;
    }
    return $info;
}

/**
 * Create or update the grade item.
 *
 * @param stdClass $checklist
 * @param mixed $grades
 * @return int
 */
function evidencechecklist_grade_item_update($checklist, $grades = null) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');
    $params = ['itemname' => $checklist->name];
    if (isset($checklist->cmidnumber)) {
        $params['idnumber'] = $checklist->cmidnumber;
    }
    if ($checklist->grade > 0) {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax'] = $checklist->grade;
        $params['grademin'] = 0;
    } else if ($checklist->grade < 0) {
        $params['gradetype'] = GRADE_TYPE_SCALE;
        $params['scaleid'] = -$checklist->grade;
    } else {
        $params['gradetype'] = GRADE_TYPE_NONE;
    }
    if ($grades === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }
    return grade_update(
        'mod/evidencechecklist',
        $checklist->course,
        'mod',
        'evidencechecklist',
        $checklist->id,
        0,
        $grades,
        $params
    );
}

/**
 * Delete the grade item.
 *
 * @param stdClass $checklist
 * @return int
 */
function evidencechecklist_grade_item_delete($checklist) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');
    return grade_update(
        'mod/evidencechecklist',
        $checklist->course,
        'mod',
        'evidencechecklist',
        $checklist->id,
        0,
        null,
        ['deleted' => 1]
    );
}

/**
 * Grades for users from finalised attempts.
 *
 * @param stdClass $checklist
 * @param int $userid
 * @return array
 */
function evidencechecklist_get_user_grades($checklist, $userid = 0) {
    global $DB;
    $params = ['checklistid' => $checklist->id, 'status' => 'finalised'];
    $sql = "SELECT a.*
              FROM {evidencechecklist_attempt} a
             WHERE a.checklistid = :checklistid AND a.status = :status";
    if ($userid) {
        $sql .= " AND a.userid = :userid";
        $params['userid'] = $userid;
    }
    $sql .= " ORDER BY a.attemptno ASC";
    $grades = [];
    foreach ($DB->get_records_sql($sql, $params) as $attempt) {
        $grades[$attempt->userid] = (object)[
            'userid' => $attempt->userid,
            'rawgrade' => \mod_evidencechecklist\local\attempt_manager::grade_value($checklist, $attempt->decision),
            'usermodified' => $attempt->assessorid,
            'dategraded' => $attempt->timefinalised,
            'feedback' => $attempt->feedback,
            'feedbackformat' => FORMAT_PLAIN,
        ];
    }
    return $grades;
}

/**
 * Push grades to the gradebook.
 *
 * @param stdClass $checklist
 * @param int $userid
 * @param bool $nullifnone
 */
function evidencechecklist_update_grades($checklist, $userid = 0, $nullifnone = true) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');
    if ($checklist->grade == 0) {
        evidencechecklist_grade_item_update($checklist);
        return;
    }
    if ($grades = evidencechecklist_get_user_grades($checklist, $userid)) {
        evidencechecklist_grade_item_update($checklist, $grades);
    } else if ($userid && $nullifnone) {
        evidencechecklist_grade_item_update($checklist, (object)['userid' => $userid, 'rawgrade' => null]);
    } else {
        evidencechecklist_grade_item_update($checklist);
    }
}

/**
 * Serve plugin files with capability checks.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool|void
 */
function evidencechecklist_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $DB, $USER;
    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }
    require_course_login($course, true, $cm);
    $itemid = (int)array_shift($args);
    $checklistid = $cm->instance;

    if ($filearea === 'itemimage') {
        if (!$DB->record_exists('evidencechecklist_item', ['id' => $itemid, 'checklistid' => $checklistid])) {
            return false;
        }
        require_capability('mod/evidencechecklist:view', $context);
    } else if (in_array($filearea, ['cellmedia', 'poster', 'sessionphoto', 'signature'])) {
        $attempt = \mod_evidencechecklist\local\attempt_manager::attempt_for_file($filearea, $itemid, $checklistid);
        if (!$attempt) {
            return false;
        }
        $isowner = ($attempt->userid == $USER->id) && has_capability('mod/evidencechecklist:view', $context);
        if (
            !$isowner && !has_capability('mod/evidencechecklist:assess', $context)
                && !has_capability('mod/evidencechecklist:viewallreports', $context)
        ) {
            return false;
        }
        if (!$isowner) {
            \mod_evidencechecklist\local\attempt_manager::require_group_access($cm, $context, $attempt->userid);
        }
    } else {
        return false;
    }

    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';
    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'mod_evidencechecklist', $filearea, $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }
    send_stored_file($file, 0, 0, $forcedownload, $options);
}

/**
 * User preferences this plugin uses.
 *
 * @return array
 */
function mod_evidencechecklist_user_preferences() {
    return [
        'mod_evidencechecklist_tips' => [
            'type' => PARAM_INT,
            'null' => NULL_NOT_ALLOWED,
            'default' => 1,
            'choices' => [0, 1],
            'permissioncallback' => [core_user::class, 'is_current_user'],
        ],
        'mod_evidencechecklist_dismissed' => [
            'type' => PARAM_TEXT,
            'null' => NULL_ALLOWED,
            'default' => '',
            'permissioncallback' => [core_user::class, 'is_current_user'],
        ],
    ];
}

/**
 * Add pages to the activity's secondary navigation.
 *
 * @param settings_navigation $settings
 * @param navigation_node $node
 */
function evidencechecklist_extend_settings_navigation(settings_navigation $settings, navigation_node $node) {
    $cm = $settings->get_page()->cm;
    if (!$cm) {
        return;
    }
    $context = context_module::instance($cm->id);
    if (has_capability('mod/evidencechecklist:manage', $context)) {
        $node->add(
            get_string('editchecklist', 'mod_evidencechecklist'),
            new moodle_url('/mod/evidencechecklist/edit.php', ['id' => $cm->id]),
            navigation_node::TYPE_SETTING,
            null,
            'evidencechecklistedit'
        );
    }
    if (has_any_capability(['mod/evidencechecklist:manage', 'mod/evidencechecklist:viewallreports'], $context)) {
        $node->add(
            get_string('mappingreport', 'mod_evidencechecklist'),
            new moodle_url('/mod/evidencechecklist/mapping.php', ['id' => $cm->id]),
            navigation_node::TYPE_SETTING,
            null,
            'evidencechecklistmapping'
        );
    }
    if (has_any_capability(['mod/evidencechecklist:assess', 'mod/evidencechecklist:viewallreports'], $context)) {
        $node->add(
            get_string('register', 'mod_evidencechecklist'),
            new moodle_url('/mod/evidencechecklist/register.php', ['id' => $cm->id]),
            navigation_node::TYPE_SETTING,
            null,
            'evidencechecklistregister'
        );
    }
}

/**
 * Reset form elements.
 *
 * @param MoodleQuickForm $mform
 */
function evidencechecklist_reset_course_form_definition(&$mform) {
    $mform->addElement('header', 'evidencechecklistheader', get_string('modulenameplural', 'mod_evidencechecklist'));
    $mform->addElement('advcheckbox', 'reset_evidencechecklist', get_string('resetattempts', 'mod_evidencechecklist'));
}

/**
 * Reset defaults.
 *
 * @param stdClass $course
 * @return array
 */
function evidencechecklist_reset_course_form_defaults($course) {
    return ['reset_evidencechecklist' => 0];
}

/**
 * Reset user data.
 *
 * @param stdClass $data
 * @return array
 */
function evidencechecklist_reset_userdata($data) {
    global $DB;
    $status = [];
    if (empty($data->reset_evidencechecklist)) {
        return $status;
    }
    $checklists = $DB->get_records('evidencechecklist', ['course' => $data->courseid]);
    $fs = get_file_storage();
    foreach ($checklists as $checklist) {
        $cm = get_coursemodule_from_instance('evidencechecklist', $checklist->id, $data->courseid);
        $context = context_module::instance($cm->id);
        foreach (['cellmedia', 'poster', 'sessionphoto', 'signature'] as $area) {
            $fs->delete_area_files($context->id, 'mod_evidencechecklist', $area);
        }
        $attemptids = $DB->get_fieldset_select('evidencechecklist_attempt', 'id', 'checklistid = ?', [$checklist->id]);
        if ($attemptids) {
            [$insql, $params] = $DB->get_in_or_equal($attemptids);
            foreach (
                ['evidencechecklist_result', 'evidencechecklist_resultlog', 'evidencechecklist_gap',
                    'evidencechecklist_confirm', 'evidencechecklist_signature', 'evidencechecklist_session',
                'evidencechecklist_medialink'] as $table
            ) {
                $DB->delete_records_select($table, "attemptid $insql", $params);
            }
        }
        $DB->delete_records('evidencechecklist_attempt', ['checklistid' => $checklist->id]);
        evidencechecklist_grade_item_update($checklist, 'reset');
    }
    $status[] = ['component' => get_string('modulenameplural', 'mod_evidencechecklist'),
        'item' => get_string('resetattempts', 'mod_evidencechecklist'), 'error' => false];
    return $status;
}
