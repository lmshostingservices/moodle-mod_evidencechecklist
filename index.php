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
 * List of Evidence Checklists in a course.
 *
 * @package    mod_evidencechecklist
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);
require_login($course);
$context = context_course::instance($course->id);
require_capability('mod/evidencechecklist:view', $context);

$event = \mod_evidencechecklist\event\course_module_instance_list_viewed::create(['context' => $context]);
$event->add_record_snapshot('course', $course);
$event->trigger();

$PAGE->set_url('/mod/evidencechecklist/index.php', ['id' => $id]);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('modulenameplural', 'mod_evidencechecklist'));
$PAGE->set_heading(format_string($course->fullname));
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'mod_evidencechecklist'));

$instances = get_all_instances_in_course('evidencechecklist', $course);
if (!$instances) {
    notice(
        get_string('thereareno', 'moodle', get_string('modulenameplural', 'mod_evidencechecklist')),
        new moodle_url('/course/view.php', ['id' => $course->id])
    );
}
$table = new html_table();
$table->head = [get_string('name'), get_string('unitcode', 'mod_evidencechecklist')];
foreach ($instances as $i) {
    $link = html_writer::link(
        new moodle_url('/mod/evidencechecklist/view.php', ['id' => $i->coursemodule]),
        format_string($i->name),
        $i->visible ? [] : ['class' => 'dimmed']
    );
    $table->data[] = [$link, s($i->unitcode)];
}
echo html_writer::table($table);
echo $OUTPUT->footer();
