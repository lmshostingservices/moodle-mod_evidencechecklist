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
 * Evidence Checklist main page: class list and marking screen for assessors, live checklist for students.
 *
 * @package    mod_evidencechecklist
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use mod_evidencechecklist\local\attempt_manager;
use mod_evidencechecklist\local\checklist;
use mod_evidencechecklist\local\state;
use mod_evidencechecklist\output\ui;

$id = required_param('id', PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'evidencechecklist');
$record = $DB->get_record('evidencechecklist', ['id' => $cm->instance], '*', MUST_EXIST);
require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/evidencechecklist:view', $context);

$canassess = has_capability('mod/evidencechecklist:assess', $context);
$canviewall = has_capability('mod/evidencechecklist:viewallreports', $context);
$isstudent = has_capability('mod/evidencechecklist:beassessed', $context, null, false);

$PAGE->set_url('/mod/evidencechecklist/view.php', ['id' => $cm->id] + ($userid ? ['userid' => $userid] : []));
$PAGE->set_title(format_string($record->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->add_body_class('ec-page');
$PAGE->set_pagelayout('incourse');

$event = \mod_evidencechecklist\event\course_module_viewed::create(['objectid' => $record->id, 'context' => $context]);
$event->add_record_snapshot('course', $course);
$event->add_record_snapshot('evidencechecklist', $record);
$event->trigger();
$completion = new completion_info($course);
$completion->set_module_viewed($cm);

$cl = new checklist($record);
$output = $PAGE->get_renderer('mod_evidencechecklist');

if ($userid && ($canassess || $canviewall) && $userid != $USER->id) {
    // Marking screen for one student.
    attempt_manager::require_group_access($cm, $context, $userid);
    if (!is_enrolled($context, $userid, 'mod/evidencechecklist:beassessed', true)) {
        throw new moodle_exception('notastudent', 'mod_evidencechecklist');
    }
    $student = core_user::get_user($userid, '*', MUST_EXIST);
    $PAGE->navbar->add(fullname($student));
    // The marking screen has its own header; keep Moodle's activity header to the title.
    $PAGE->activityheader->set_description('');
    $PAGE->activityheader->set_hidecompletion(true);
    $attempt = $canassess && $cl->assessing_allowed()
        ? attempt_manager::current_attempt($record, $userid)
        : attempt_manager::current_attempt($record, $userid, false);
    echo $output->header();
    echo ui::app($cm, $context, $cl, $attempt, $userid, $canassess ? 'assessor' : 'viewer');
    echo $output->footer();
    exit;
}

echo $output->header();
if ($canassess || $canviewall) {
    echo ui::class_list($cm, $context, $cl);
} else if ($isstudent) {
    $attempt = attempt_manager::current_attempt($record, $USER->id, false);
    echo ui::app($cm, $context, $cl, $attempt, $USER->id, 'student');
} else {
    echo $OUTPUT->notification(get_string('nothingtosee', 'mod_evidencechecklist'), 'info');
}
echo $output->footer();
