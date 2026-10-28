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

use mod_evidencechecklist\local\attempt_manager;
use mod_evidencechecklist\local\checklist;
use mod_evidencechecklist\local\parser;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Backup and restore with user data, mapping and files.
 *
 * @package    mod_evidencechecklist
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \backup_evidencechecklist_activity_structure_step
 * @covers     \restore_evidencechecklist_activity_structure_step
 */
final class backup_test extends \advanced_testcase {
    /**
     * Duplicate a course with users and check everything comes across.
     */
    public function test_backup_restore(): void {
        global $CFG, $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $student = $gen->create_user();
        $gen->enrol_user($student->id, $course->id, 'student');
        $instance = $gen->create_module('evidencechecklist', ['course' => $course->id]);
        $record = $DB->get_record('evidencechecklist', ['id' => $instance->id]);
        (new checklist($record))->replace_from_parsed(
            parser::parse(
                "# A\nOne | PC1.1 | Adult; Infant | 2\n" .
                "@mapping\nPC1.1 | One | 1.1\n"
            ),
            $USER->id
        );
        $cl = new checklist($record);
        $attempt = attempt_manager::current_attempt($record, $student->id);
        $row = attempt_manager::set_result($cl, $attempt, array_key_first($cl->items), 'adult|1', 'S', $USER->id);
        $context = \context_module::instance($instance->cmid);
        $file = get_file_storage()->create_file_from_string(
            ['contextid' => $context->id, 'component' => 'mod_evidencechecklist',
            'filearea' => 'cellmedia', 'itemid' => $row->id, 'filepath' => '/', 'filename' => 'p.jpg'],
            'img'
        );
        attempt_manager::link_media(
            $cl,
            $attempt,
            $context,
            $file->get_id(),
            [array_key_first($cl->items) . '#infant|1'],
            $USER->id
        );

        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_value(true);
        $bc->execute_plan();
        $backupid = $bc->get_backupid();
        $file = $bc->get_results()['backup_destination'];
        $file->extract_to_pathname(
            get_file_packer('application/vnd.moodle.backup'),
            make_backup_temp_directory($backupid)
        );
        $bc->destroy();
        $newcourseid = \restore_dbops::create_new_course('Restored', 'R1', $course->category);
        $rc = new \restore_controller(
            $backupid,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $rc->get_plan()->get_setting('users')->set_value(true);
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        $new = $DB->get_record('evidencechecklist', ['course' => $newcourseid], '*', MUST_EXIST);
        $newcl = new checklist($new);
        $this->assertCount(1, $newcl->items);
        $this->assertSame('complete', $newcl->mapping_state());
        $newattempt = $DB->get_record('evidencechecklist_attempt', ['checklistid' => $new->id], '*', MUST_EXIST);
        $this->assertEquals($student->id, $newattempt->userid);
        $newrow = $DB->get_record('evidencechecklist_result', ['attemptid' => $newattempt->id, 'contextkey' => 'adult',
            'occasionno' => 1], '*', MUST_EXIST);
        $this->assertSame('S', $newrow->result);
        $newcm = get_coursemodule_from_instance('evidencechecklist', $new->id);
        $files = get_file_storage()->get_area_files(
            \context_module::instance($newcm->id)->id,
            'mod_evidencechecklist',
            'cellmedia',
            $newrow->id,
            'id',
            false
        );
        $this->assertCount(1, $files);
        // The link to the other criterion comes across, pointing at the restored rows.
        $link = $DB->get_record('evidencechecklist_medialink', ['attemptid' => $newattempt->id], '*', MUST_EXIST);
        $this->assertEquals($newrow->id, $link->sourceid);
        $this->assertSame('p.jpg', $link->filename);
        $target = $DB->get_record('evidencechecklist_result', ['id' => $link->targetid], '*', MUST_EXIST);
        $this->assertSame('infant', $target->contextkey);
        $this->assertEquals($newattempt->id, $target->attemptid);
    }
}
