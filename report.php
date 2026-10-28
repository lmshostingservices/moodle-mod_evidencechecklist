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
 * Practical Evidence Report for one student (screen or PDF).
 *
 * @package    mod_evidencechecklist
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use mod_evidencechecklist\local\attempt_manager;
use mod_evidencechecklist\local\checklist;
use mod_evidencechecklist\local\defaults;
use mod_evidencechecklist\local\report;

$id = required_param('id', PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);
$attemptid = optional_param('attemptid', 0, PARAM_INT);
$format = optional_param('format', '', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'evidencechecklist');
$record = $DB->get_record('evidencechecklist', ['id' => $cm->instance], '*', MUST_EXIST);
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/evidencechecklist:view', $context);
$userid = $userid ?: $USER->id;
$isself = $userid == $USER->id;
$staff = has_any_capability(['mod/evidencechecklist:assess', 'mod/evidencechecklist:viewallreports'], $context);
if (!$isself) {
    if (!$staff) {
        require_capability('mod/evidencechecklist:viewallreports', $context);
    }
    attempt_manager::require_group_access($cm, $context, $userid);
}

$attempts = attempt_manager::attempts($record->id, $userid);
if (!$attempts) {
    throw new moodle_exception('noattempt', 'mod_evidencechecklist');
}
$attempt = $attemptid && isset($attempts[$attemptid]) ? $attempts[$attemptid] : reset($attempts);
if ($isself && !$staff && $attempt->status !== 'finalised' && empty($record->livestudentview)) {
    throw new moodle_exception('notreleased', 'mod_evidencechecklist');
}
$cl = new checklist($record);
$report = new report($cm, $context, $cl, $attempt, !$isself);

if ($format === 'pdf') {
    \core\session\manager::write_close();
    $report->send_pdf();
    exit;
}

$url = new moodle_url('/mod/evidencechecklist/report.php', ['id' => $cm->id, 'userid' => $userid, 'attemptid' => $attempt->id]);
$PAGE->set_url($url);
$PAGE->set_title(get_string('reporttitle', 'mod_evidencechecklist'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->add_body_class('ec-page');
$PAGE->activityheader->disable();
[$green, $magenta] = defaults::colours();

echo $OUTPUT->header();
echo html_writer::start_div('ec-root ec-report', ['style' => "--ec-green:$green;--ec-magenta:$magenta;"]);
$toolbar = html_writer::link(
    new moodle_url(
        '/mod/evidencechecklist/view.php',
        ['id' => $cm->id] + ($isself ? [] :
        ['userid' => $userid])
    ),
    get_string('back', 'mod_evidencechecklist'),
    ['class' => 'ec-btn ec-btn-ghost']
);
if (count($attempts) > 1) {
    $tabs = '';
    foreach (array_reverse($attempts) as $a) {
        $tabs .= html_writer::link(
            new moodle_url($url, ['attemptid' => $a->id]),
            get_string(
                'attemptn',
                'mod_evidencechecklist',
                $a->attemptno
            ),
            ['class' => $a->id == $attempt->id ? 'ec-on' : '']
        );
    }
    $toolbar .= html_writer::div($tabs, 'ec-seg ec-seg-links');
}
$right = html_writer::link(
    new moodle_url($url, ['format' => 'pdf']),
    get_string('downloadpdf', 'mod_evidencechecklist'),
    ['class' => 'ec-btn ec-btn-primary']
);
echo html_writer::div(html_writer::div($toolbar, 'ec-toolbar-left') . html_writer::div($right, 'ec-toolbar-right'), 'ec-toolbar');
echo $report->html();
echo html_writer::end_div();
echo $OUTPUT->footer();
