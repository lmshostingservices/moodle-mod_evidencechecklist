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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.

/**
 * Check an existing credit unlock or recognised Marketplace purchase.
 *
 * @package    mod_evidencechecklist
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

$refresh = optional_param('refresh', 0, PARAM_BOOL);
if ($refresh && ($_SERVER['REQUEST_METHOD'] !== 'POST' || !confirm_sesskey())) {
    throw new moodle_exception('invalidsesskey');
}
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/mod/evidencechecklist/activate.php');
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('activationheading', 'mod_evidencechecklist'));
$PAGE->set_heading(get_string('pluginname', 'mod_evidencechecklist'));

$active = \mod_evidencechecklist\local\entitlement::has_access((bool)$refresh);
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('activationheading', 'mod_evidencechecklist'));
echo html_writer::div(get_string('acquisitiondescription', 'mod_evidencechecklist'), 'alert alert-info');
echo $OUTPUT->notification(
    get_string($active ? 'activationverified' : 'activationnotverified', 'mod_evidencechecklist'),
    $active ? 'success' : 'warning'
);
echo $OUTPUT->single_button(
    new moodle_url('/mod/evidencechecklist/activate.php', ['refresh' => 1]),
    get_string('checkactivation', 'mod_evidencechecklist'),
    'post'
);
echo html_writer::tag('p', html_writer::link(
    new moodle_url('/admin/settings.php', ['section' => 'modsettingevidencechecklist']),
    get_string('settings', 'core')
));
echo html_writer::tag('p', html_writer::link(
    'https://lms-labs.com/evidence-checklist/docs',
    get_string('activationinstructions', 'mod_evidencechecklist')
));
echo $OUTPUT->footer();
