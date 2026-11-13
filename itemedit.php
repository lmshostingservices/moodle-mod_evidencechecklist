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
 * Edit one criterion, including its reference image.
 *
 * @package    mod_evidencechecklist
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use mod_evidencechecklist\local\checklist;

$id = required_param('id', PARAM_INT);
$itemid = required_param('itemid', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'evidencechecklist');
$record = $DB->get_record('evidencechecklist', ['id' => $cm->instance], '*', MUST_EXIST);
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/evidencechecklist:manage', $context);
$item = $DB->get_record('evidencechecklist_item', ['id' => $itemid, 'checklistid' => $record->id], '*', MUST_EXIST);

$url = new moodle_url('/mod/evidencechecklist/itemedit.php', ['id' => $cm->id, 'itemid' => $itemid]);
$back = new moodle_url('/mod/evidencechecklist/edit.php', ['id' => $cm->id]);
$PAGE->set_url($url);
$PAGE->set_title(get_string('edititem', 'mod_evidencechecklist'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->add_body_class('ec-page');
$PAGE->activityheader->disable();
$PAGE->navbar->add(get_string('editchecklist', 'mod_evidencechecklist'), $back);
$PAGE->navbar->add(get_string('edititem', 'mod_evidencechecklist'));

$locked = checklist::has_results($record->id);
$form = new \mod_evidencechecklist\form\item_form($url, ['locked' => $locked]);
$options = ['subdirs' => 0, 'maxfiles' => 1, 'accepted_types' => ['image']];
$draftid = file_get_submitted_draft_itemid('itemimage');
file_prepare_draft_area($draftid, $context->id, 'mod_evidencechecklist', 'itemimage', $item->id, $options);
$item->itemimage = $draftid;
$item->id = $cm->id;
$item->itemid = $itemid;
$form->set_data($item);

if ($form->is_cancelled()) {
    redirect($back);
} else if ($data = $form->get_data()) {
    $update = (object)['id' => $itemid, 'criterion' => $data->criterion, 'benchmark' => $data->benchmark,
        'gaptip' => $data->gaptip, 'mappingrefs' => $data->mappingrefs, 'mandatory' => $data->mandatory,
        'verbal' => $data->verbal];
    $update->contexts = \core_text::substr(implode(';', array_filter(array_map('trim', explode(';', $data->contexts)))), 0, 255);
    $DB->update_record('evidencechecklist_item', $update);
    file_save_draft_area_files($data->itemimage, $context->id, 'mod_evidencechecklist', 'itemimage', $itemid, $options);
    checklist::log($record->id, $USER->id, 'itemedit', json_encode(['itemid' => $itemid]));
    redirect($back, get_string('saved', 'mod_evidencechecklist'), null, \core\output\notification::NOTIFY_SUCCESS);
}

[$green, $magenta] = \mod_evidencechecklist\local\defaults::colours();
echo $OUTPUT->header();
echo html_writer::start_div('ec-root ec-formpage', ['style' => "--ec-green:$green;--ec-magenta:$magenta;"]);
echo html_writer::div(
    html_writer::div(
        html_writer::div(get_string('editchecklist', 'mod_evidencechecklist'), 'ec-kicker') .
        html_writer::tag('h2', get_string('edititem', 'mod_evidencechecklist'), ['class' => 'ec-title']) .
        html_writer::div(format_string($record->name), 'ec-subtitle'),
        'ec-pagehead-text'
    ),
    'ec-pagehead'
);
echo html_writer::start_div('ec-card ec-formcard');
$form->display();
echo html_writer::end_div();
echo html_writer::end_div();
echo $OUTPUT->footer();
