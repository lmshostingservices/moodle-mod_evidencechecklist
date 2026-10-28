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

namespace mod_evidencechecklist;

use core_external\external_api;
use mod_evidencechecklist\external\api;
use mod_evidencechecklist\local\attempt_manager;
use mod_evidencechecklist\local\checklist;
use mod_evidencechecklist\local\parser;

/**
 * Marking, gaps, bulk fill, finalising, locking, permissions and clean-up.
 *
 * @package    mod_evidencechecklist
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_evidencechecklist\local\attempt_manager
 * @covers     \mod_evidencechecklist\external\api
 */
final class attempt_test extends \advanced_testcase {
    /** @var \stdClass */
    private $course;
    /** @var \stdClass */
    private $student;
    /** @var \stdClass */
    private $teacher;
    /** @var \stdClass */
    private $record;
    /** @var \stdClass */
    private $cm;

    /**
     * Course with one checklist: 2 items, the second in Adult and Infant x 2 occasions.
     *
     * @param array $settings
     */
    private function setup_checklist(array $settings = []): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course(['enablecompletion' => 1]);
        $this->student = $gen->create_user();
        $this->teacher = $gen->create_user();
        $gen->enrol_user($this->student->id, $this->course->id, 'student');
        $gen->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');
        $instance = $gen->create_module(
            'evidencechecklist',
            ['course' => $this->course->id, 'grade' => 1,
            'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completioncompetent' => 1] + $settings
        );
        $this->record = $DB->get_record('evidencechecklist', ['id' => $instance->id]);
        $this->cm = get_coursemodule_from_instance('evidencechecklist', $instance->id);
        $parsed = parser::parse(
            "@columns: Adult x2; Infant x2\n# Scene\nChecks danger | PC1.1 | Once | Names hazards\n" .
            "# CPR\nPerforms CPR | PE2 | All | Good depth\n" .
            "@mapping\nPC1.1 | Danger | 1.1\nPE2 | CPR | 2.1\n",
            1
        );
        (new checklist($this->record))->replace_from_parsed($parsed, 2);
        $this->record = $DB->get_record('evidencechecklist', ['id' => $instance->id]);
    }

    /**
     * Confirm all statements for the student.
     */
    private function confirm(): void {
        $this->setUser($this->student);
        api::save_confirm($this->cm->id, $this->student->id, '1,2,3,4,5,6,7,8', '');
    }

    /**
     * One cell per call; gaps open on NYS and close when every cell is S.
     */
    public function test_mark_and_gap(): void {
        global $DB;
        $this->setup_checklist();
        $cl = new checklist($this->record);
        $items = array_values($cl->items);
        $this->assertSame(['adult|1'], checklist::item_cells($items[0]));
        $this->assertSame(['adult|1', 'adult|2', 'infant|1', 'infant|2'], checklist::item_cells($items[1]));
        $this->assertCount(4, $cl->columns());
        $this->assertSame('Adult 1', $cl->columns()[0]['label']);

        $attempt = attempt_manager::current_attempt($this->record, $this->student->id);
        attempt_manager::set_result($cl, $attempt, $items[1]->id, 'adult|1', 'NYS', $this->teacher->id);
        $gap = $DB->get_record('evidencechecklist_gap', ['attemptid' => $attempt->id, 'itemid' => $items[1]->id]);
        $this->assertSame('open', $gap->status);
        foreach (['adult|1', 'adult|2', 'infant|1', 'infant|2'] as $c) {
            attempt_manager::set_result($cl, $attempt, $items[1]->id, $c, 'S', $this->teacher->id);
        }
        $gap = $DB->get_record('evidencechecklist_gap', ['attemptid' => $attempt->id, 'itemid' => $items[1]->id]);
        $this->assertSame('closed', $gap->status);
        [$map] = attempt_manager::results($attempt->id);
        $p = attempt_manager::progress($cl, $map);
        $this->assertSame(5, $p['required']);
        $this->assertSame(4, $p['s']);
        $this->assertFalse($p['complete']);
        // Every change is logged.
        $this->assertSame(5, $DB->count_records('evidencechecklist_resultlog', ['attemptid' => $attempt->id]));

        $this->expectException(\moodle_exception::class);
        attempt_manager::set_result($cl, $attempt, $items[0]->id, 'infant|1', 'S', $this->teacher->id);
    }

    /**
     * One file, several criteria: stored once, shown on each linked cell, never marks anything, cleaned up on delete.
     */
    public function test_link_media(): void {
        global $DB;
        $this->setup_checklist();
        $this->confirm();
        $cl = new checklist($this->record);
        $items = array_values($cl->items);
        $context = \context_module::instance($this->cm->id);
        $attempt = attempt_manager::current_attempt($this->record, $this->student->id);
        $row = attempt_manager::set_result($cl, $attempt, $items[0]->id, 'adult|1', 'S', $this->teacher->id);
        $file = get_file_storage()->create_file_from_string(
            ['contextid' => $context->id, 'component' => 'mod_evidencechecklist',
            'filearea' => 'cellmedia', 'itemid' => $row->id, 'filepath' => '/', 'filename' => 'video-1.mp4'],
            'vid'
        );
        $keys = [$items[1]->id . '#adult|1', $items[1]->id . '#infant|2', $items[0]->id . '#adult|1'];
        $this->setUser($this->teacher);
        $res = api::link_media($this->cm->id, $this->student->id, $file->get_id(), implode(',', $keys));
        $res = external_api::clean_returnvalue(api::link_media_returns(), $res);
        $this->assertSame(2, json_decode($res['extra'])->linked);
        $this->assertSame(2, $DB->count_records('evidencechecklist_medialink', ['attemptid' => $attempt->id]));
        $this->assertCount(1, get_file_storage()->get_area_files(
            $context->id,
            'mod_evidencechecklist',
            'cellmedia',
            false,
            'id',
            false
        ));
        $state = json_decode($res['state'], true);
        $target = $state['cells'][$items[1]->id . '#infant|2'];
        $this->assertSame('', $target['r']);
        $this->assertTrue($target['media'][0]['linked']);
        $this->assertSame('1.1', $target['media'][0]['fromno']);
        $this->assertCount(2, $state['cells'][$items[0]->id . '#adult|1']['media'][0]['links']);
        // Replace the list: one link left.
        api::link_media($this->cm->id, $this->student->id, $file->get_id(), $items[1]->id . '#adult|1');
        $this->assertSame(1, $DB->count_records('evidencechecklist_medialink', ['attemptid' => $attempt->id]));
        $this->assertSame(1, $DB->count_records('evidencechecklist_resultlog', ['attemptid' => $attempt->id,
            'field' => 'media:unlink']));
        // A student cannot link.
        $this->setUser($this->student);
        try {
            api::link_media($this->cm->id, $this->student->id, $file->get_id(), '');
            $this->fail('student linked evidence');
        } catch (\required_capability_exception $e) {
            $this->assertSame(1, $DB->count_records('evidencechecklist_medialink', ['attemptid' => $attempt->id]));
        }
        // Deleting the file removes its links.
        $this->setUser($this->teacher);
        api::delete_media($this->cm->id, $this->student->id, $file->get_id());
        $this->assertSame(0, $DB->count_records('evidencechecklist_medialink', ['attemptid' => $attempt->id]));
    }

    /**
     * All S fills only empty cells; undo reverses exactly those.
     */
    public function test_bulk_and_undo(): void {
        $this->setup_checklist();
        $cl = new checklist($this->record);
        $items = array_values($cl->items);
        $attempt = attempt_manager::current_attempt($this->record, $this->student->id);
        attempt_manager::set_result($cl, $attempt, $items[1]->id, 'infant|2', 'NYS', $this->teacher->id);
        $ids = attempt_manager::bulk_fill($cl, $attempt, 'all', 'S', $this->teacher->id);
        $this->assertCount(4, $ids);
        [$map] = attempt_manager::results($attempt->id);
        $this->assertSame('NYS', $map[$items[1]->id . '#infant|2']);
        $this->assertSame(4, attempt_manager::undo_bulk($cl, $attempt, $ids, $this->teacher->id));
        [$map] = attempt_manager::results($attempt->id);
        $this->assertSame(0, attempt_manager::progress($cl, $map)['s']);
        // Column scope.
        $ids = attempt_manager::bulk_fill($cl, $attempt, 'col:adult|2', 'S', $this->teacher->id);
        $this->assertCount(1, $ids);
    }

    /**
     * Competent needs every cell S and an assessor signature; then grade and completion follow.
     */
    public function test_finalise(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->libdir . '/completionlib.php');
        $this->setup_checklist();
        $this->confirm();
        $this->setUser($this->teacher);
        try {
            api::finalise($this->cm->id, $this->student->id, 'competent', '');
            $this->fail('Competent should be blocked');
        } catch (\moodle_exception $e) {
            $this->assertSame('notreadycompetent', $e->errorcode);
        }
        api::bulk_fill($this->cm->id, $this->student->id, 'all', 'S');
        try {
            api::finalise($this->cm->id, $this->student->id, 'competent', '');
            $this->fail('Signature should be required');
        } catch (\moodle_exception $e) {
            $this->assertSame('assessorsignatureneeded', $e->errorcode);
        }
        api::save_signature($this->cm->id, $this->student->id, 'assessor', 'Pat Assessor', '');
        $res = api::finalise($this->cm->id, $this->student->id, 'competent', 'Well done');
        $state = json_decode($res['state'], true);
        $this->assertSame('finalised', $state['attempt']['status']);
        $attempt = $DB->get_record('evidencechecklist_attempt', ['checklistid' => $this->record->id]);
        $this->assertSame(64, strlen($attempt->recordhash));
        $grades = grade_get_grades($this->course->id, 'mod', 'evidencechecklist', $this->record->id, $this->student->id);
        $this->assertEquals(1, $grades->items[0]->grades[$this->student->id]->grade);
        $completion = new \completion_info($this->course);
        $cminfo = get_fast_modinfo($this->course)->get_cm($this->cm->id);
        $this->assertEquals(COMPLETION_COMPLETE, $completion->get_data($cminfo, false, $this->student->id)->completionstate);
        // Locked after finalising.
        $this->expectException(\moodle_exception::class);
        $first = array_key_first((new checklist($this->record))->items);
        api::set_result($this->cm->id, $this->student->id, $first, 'adult|1', 'NYS');
    }

    /**
     * Students cannot mark; the mapping lock blocks assessors when mapping has gaps.
     */
    public function test_permissions_and_lock(): void {
        global $DB;
        $this->setup_checklist(['requiremapping' => 1]);
        $itemid = array_key_first((new checklist($this->record))->items);
        $this->setUser($this->student);
        try {
            api::set_result($this->cm->id, $this->student->id, $itemid, 'adult|1', 'S');
            $this->fail('Student must not mark');
        } catch (\required_capability_exception $e) {
            $this->assertTrue(true);
        }
        // Make a gap in the mapping.
        $req = $DB->get_record('evidencechecklist_req', ['checklistid' => $this->record->id, 'code' => 'PE2']);
        $DB->delete_records('evidencechecklist_reqmap', ['reqid' => $req->id]);
        $this->setUser($this->teacher);
        try {
            api::set_result($this->cm->id, $this->student->id, $itemid, 'adult|1', 'S');
            $this->fail('Mapping lock should block');
        } catch (\moodle_exception $e) {
            $this->assertSame('mappinglocked', $e->errorcode);
        }
        set_config('requiremappingdefault', 0, 'mod_evidencechecklist');
        $DB->set_field('evidencechecklist', 'requiremapping', 0, ['id' => $this->record->id]);
        api::set_result($this->cm->id, $this->student->id, $itemid, 'adult|1', 'S');
        $this->assertTrue($DB->record_exists('evidencechecklist_result', ['itemid' => $itemid, 'result' => 'S']));
    }

    /**
     * The student's live view never includes private assessor notes.
     */
    public function test_student_state_hides_notes(): void {
        $this->setup_checklist();
        $itemid = array_key_first((new checklist($this->record))->items);
        $this->setUser($this->teacher);
        api::set_cell_text($this->cm->id, $this->student->id, $itemid, 'adult|1', 'assessornote', 'Private');
        api::set_cell_text($this->cm->id, $this->student->id, $itemid, 'adult|1', 'comment', 'Public');
        $this->setUser($this->student);
        $res = api::get_state($this->cm->id, $this->student->id, -1);
        $this->assertStringContainsString('Public', $res['state']);
        $this->assertStringNotContainsString('Private', $res['state']);
        // Unchanged revision returns no state.
        $again = api::get_state($this->cm->id, $this->student->id, $res['rev']);
        $this->assertSame('null', $again['state']);
    }

    /**
     * Deleting the activity removes everything.
     */
    public function test_delete_instance(): void {
        global $DB;
        $this->setup_checklist();
        $cl = new checklist($this->record);
        $attempt = attempt_manager::current_attempt($this->record, $this->student->id);
        attempt_manager::bulk_fill($cl, $attempt, 'all', 'S', $this->teacher->id);
        course_delete_module($this->cm->id);
        foreach (
            ['evidencechecklist', 'evidencechecklist_item', 'evidencechecklist_group', 'evidencechecklist_req',
                'evidencechecklist_attempt', 'evidencechecklist_result', 'evidencechecklist_resultlog'] as $table
        ) {
            $this->assertSame(0, $DB->count_records($table), $table);
        }
    }

    /**
     * A not yet competent result can be reassessed, carrying satisfactory cells forward.
     */
    public function test_reassessment(): void {
        $this->setup_checklist();
        $this->confirm();
        $this->setUser($this->teacher);
        $items = array_keys((new checklist($this->record))->items);
        api::set_result($this->cm->id, $this->student->id, $items[0], 'adult|1', 'S');
        api::set_result($this->cm->id, $this->student->id, $items[1], 'adult|1', 'NYS');
        api::save_gap($this->cm->id, $this->student->id, $items[1], 'Too shallow', 'Practise', 0);
        api::save_signature($this->cm->id, $this->student->id, 'assessor', 'Pat', '');
        api::finalise($this->cm->id, $this->student->id, 'nyc', 'Not yet');
        $res = api::start_reassessment($this->cm->id, $this->student->id);
        $state = json_decode($res['state'], true);
        $this->assertSame(2, $state['attempt']['no']);
        $this->assertSame('S', $state['cells'][$items[0] . '#adult|1']['r']);
        $this->assertTrue($state['cells'][$items[0] . '#adult|1']['carried']);
    }
}
