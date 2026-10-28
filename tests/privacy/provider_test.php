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

namespace mod_evidencechecklist\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use mod_evidencechecklist\local\attempt_manager;
use mod_evidencechecklist\local\checklist;
use mod_evidencechecklist\local\parser;

/**
 * Privacy provider tests.
 *
 * @package    mod_evidencechecklist
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_evidencechecklist\privacy\provider
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * Find, export and delete a student's records and files.
     */
    public function test_export_and_delete(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $student = $gen->create_user();
        $other = $gen->create_user();
        $teacher = $gen->create_user();
        $gen->enrol_user($student->id, $course->id, 'student');
        $gen->enrol_user($other->id, $course->id, 'student');
        $gen->enrol_user($teacher->id, $course->id, 'editingteacher');
        $instance = $gen->create_module('evidencechecklist', ['course' => $course->id]);
        $record = $DB->get_record('evidencechecklist', ['id' => $instance->id]);
        $context = \context_module::instance($instance->cmid);
        (new checklist($record))->replace_from_parsed(parser::parse("Checks danger | PC1.1\n"), $teacher->id);
        $cl = new checklist($record);
        $itemid = array_key_first($cl->items);
        foreach ([$student, $other] as $u) {
            $attempt = attempt_manager::current_attempt($record, $u->id);
            $row = attempt_manager::set_result($cl, $attempt, $itemid, '|1', 'S', $teacher->id);
            get_file_storage()->create_file_from_string(
                ['contextid' => $context->id, 'component' => 'mod_evidencechecklist',
                'filearea' => 'cellmedia', 'itemid' => $row->id, 'filepath' => '/', 'filename' => 'photo.jpg'],
                'x'
            );
        }

        $contexts = provider::get_contexts_for_userid($student->id);
        $this->assertSame([$context->id], array_map('intval', $contexts->get_contextids()));
        $this->assertCount(1, provider::get_contexts_for_userid($teacher->id)->get_contextids());

        $userlist = new userlist($context, 'mod_evidencechecklist');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing([$student->id, $other->id, $teacher->id], $userlist->get_userids());

        $this->export_context_data_for_user($student->id, $context, 'mod_evidencechecklist');
        $w = writer::with_context($context);
        $this->assertTrue($w->has_any_data());

        provider::delete_data_for_user(new approved_contextlist($student, 'mod_evidencechecklist', [$context->id]));
        $this->assertFalse($DB->record_exists('evidencechecklist_attempt', ['userid' => $student->id]));
        $this->assertTrue($DB->record_exists('evidencechecklist_attempt', ['userid' => $other->id]));
        $files = get_file_storage()->get_area_files($context->id, 'mod_evidencechecklist', 'cellmedia', false, 'id', false);
        $this->assertCount(1, $files);

        provider::delete_data_for_users(new approved_userlist($context, 'mod_evidencechecklist', [$other->id]));
        $this->assertSame(0, $DB->count_records('evidencechecklist_attempt'));
        $this->assertSame(0, $DB->count_records('evidencechecklist_result'));
    }
}
