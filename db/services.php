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
 * Web service definitions for Evidence Checklist.
 *
 * @package    mod_evidencechecklist
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [];
$descriptions = [
    'get_state' => ['read', 'Get the checklist state for a student (live view).'],
    'set_result' => ['write', 'Mark one cell S, NYS, not observed or blank.'],
    'set_cell_text' => ['write', 'Save a cell comment, private note or timer.'],
    'bulk_fill' => ['write', 'Fill empty cells in a column, group or the whole checklist.'],
    'undo_bulk' => ['write', 'Undo the last bulk fill.'],
    'save_gap' => ['write', 'Save the gap plan for a criterion.'],
    'set_gap_status' => ['write', 'Close a gap plan by the assessor\'s decision, or reopen it.'],
    'save_confirm' => ['write', 'Save pre-assessment confirmations.'],
    'save_session' => ['write', 'Save session details and identity check.'],
    'save_signature' => ['write', 'Save an e-signature.'],
    'finalise' => ['write', 'Finalise and release the result.'],
    'start_reassessment' => ['write', 'Start a reassessment after not yet competent.'],
    'delete_media' => ['write', 'Delete an evidence file from a cell.'],
    'link_media' => ['write', 'Link one evidence file to the other criteria it covers.'],
];
foreach ($descriptions as $method => [$type, $description]) {
    $functions['mod_evidencechecklist_' . $method] = [
        'classname' => \mod_evidencechecklist\external\api::class,
        'methodname' => $method,
        'description' => $description,
        'type' => $type,
        'ajax' => true,
        'loginrequired' => true,
    ];
}
