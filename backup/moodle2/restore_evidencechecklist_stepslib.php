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
 * Restore structure.
 *
 * @package    mod_evidencechecklist
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Restore structure step for Evidence Checklist.
 */
class restore_evidencechecklist_activity_structure_step extends restore_activity_structure_step {
    /**
     * Structure.
     *
     * @return array
     */
    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');
        $paths = [
            new restore_path_element('evidencechecklist', '/activity/evidencechecklist'),
            new restore_path_element('evidencechecklist_group', '/activity/evidencechecklist/groups/group'),
            new restore_path_element('evidencechecklist_item', '/activity/evidencechecklist/groups/group/items/item'),
            new restore_path_element('evidencechecklist_req', '/activity/evidencechecklist/reqs/req'),
            new restore_path_element('evidencechecklist_reqmap', '/activity/evidencechecklist/reqs/req/reqmaps/reqmap'),
        ];
        if ($userinfo) {
            $a = '/activity/evidencechecklist/attempts/attempt';
            $paths[] = new restore_path_element('evidencechecklist_attempt', $a);
            $paths[] = new restore_path_element('evidencechecklist_session', $a . '/sessions/session');
            $paths[] = new restore_path_element('evidencechecklist_result', $a . '/results/result');
            $paths[] = new restore_path_element('evidencechecklist_resultlog', $a . '/resultlogs/resultlog');
            $paths[] = new restore_path_element('evidencechecklist_gap', $a . '/gaps/gap');
            $paths[] = new restore_path_element('evidencechecklist_confirm', $a . '/confirms/confirm');
            $paths[] = new restore_path_element('evidencechecklist_signature', $a . '/signatures/signature');
            $paths[] = new restore_path_element('evidencechecklist_medialink', $a . '/medialinks/medialink');
        }
        return $this->prepare_activity_structure($paths);
    }

    /**
     * Activity.
     *
     * @param array $data
     */
    protected function process_evidencechecklist($data) {
        global $DB;
        $data = (object)$data;
        $data->course = $this->get_courseid();
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);
        $data->mappingapprovedby = $data->mappingapprovedby ? $this->get_mappingid('user', $data->mappingapprovedby) : null;
        if ($data->grade < 0) {
            $data->grade = -($this->get_mappingid('scale', abs($data->grade)));
        }
        $newid = $DB->insert_record('evidencechecklist', $data);
        $this->apply_activity_instance($newid);
    }

    /**
     * Group.
     *
     * @param array $data
     */
    protected function process_evidencechecklist_group($data) {
        global $DB;
        $data = (object)$data;
        $old = $data->id;
        $data->checklistid = $this->get_new_parentid('evidencechecklist');
        $this->set_mapping('evidencechecklist_group', $old, $DB->insert_record('evidencechecklist_group', $data));
    }

    /**
     * Item.
     *
     * @param array $data
     */
    protected function process_evidencechecklist_item($data) {
        global $DB;
        $data = (object)$data;
        $old = $data->id;
        $data->checklistid = $this->get_new_parentid('evidencechecklist');
        $data->groupid = $this->get_new_parentid('evidencechecklist_group');
        $this->set_mapping('evidencechecklist_item', $old, $DB->insert_record('evidencechecklist_item', $data), true);
    }

    /**
     * Requirement.
     *
     * @param array $data
     */
    protected function process_evidencechecklist_req($data) {
        global $DB;
        $data = (object)$data;
        $old = $data->id;
        $data->checklistid = $this->get_new_parentid('evidencechecklist');
        $this->set_mapping('evidencechecklist_req', $old, $DB->insert_record('evidencechecklist_req', $data));
    }

    /**
     * Requirement link.
     *
     * @param array $data
     */
    protected function process_evidencechecklist_reqmap($data) {
        global $DB;
        $data = (object)$data;
        $data->reqid = $this->get_new_parentid('evidencechecklist_req');
        $data->itemid = $this->get_mappingid('evidencechecklist_item', $data->itemid);
        if ($data->itemid) {
            $DB->insert_record('evidencechecklist_reqmap', $data);
        }
    }

    /**
     * Attempt.
     *
     * @param array $data
     */
    protected function process_evidencechecklist_attempt($data) {
        global $DB;
        $data = (object)$data;
        $old = $data->id;
        $data->checklistid = $this->get_new_parentid('evidencechecklist');
        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->assessorid = $data->assessorid ? $this->get_mappingid('user', $data->assessorid) : null;
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);
        if (!$data->userid) {
            return;
        }
        $this->set_mapping('evidencechecklist_attempt', $old, $DB->insert_record('evidencechecklist_attempt', $data));
    }

    /**
     * Session.
     *
     * @param array $data
     */
    protected function process_evidencechecklist_session($data) {
        global $DB;
        $data = (object)$data;
        $old = $data->id;
        $data->attemptid = $this->get_new_parentid('evidencechecklist_attempt');
        if (!$data->attemptid) {
            return;
        }
        $data->assessorid = $this->get_mappingid('user', $data->assessorid) ?: 0;
        $this->set_mapping('evidencechecklist_session', $old, $DB->insert_record('evidencechecklist_session', $data), true);
    }

    /**
     * Result.
     *
     * @param array $data
     */
    protected function process_evidencechecklist_result($data) {
        global $DB;
        $data = (object)$data;
        $old = $data->id;
        $data->attemptid = $this->get_new_parentid('evidencechecklist_attempt');
        $data->itemid = $this->get_mappingid('evidencechecklist_item', $data->itemid);
        if (!$data->attemptid || !$data->itemid) {
            return;
        }
        $data->assessorid = $data->assessorid ? $this->get_mappingid('user', $data->assessorid) : null;
        $data->sessionid = $data->sessionid ? $this->get_mappingid('evidencechecklist_session', $data->sessionid) : null;
        $this->set_mapping('evidencechecklist_result', $old, $DB->insert_record('evidencechecklist_result', $data), true);
    }

    /**
     * Result log.
     *
     * @param array $data
     */
    protected function process_evidencechecklist_resultlog($data) {
        global $DB;
        $data = (object)$data;
        $data->attemptid = $this->get_new_parentid('evidencechecklist_attempt');
        if (!$data->attemptid) {
            return;
        }
        $data->resultid = $this->get_mappingid('evidencechecklist_result', $data->resultid) ?: 0;
        $data->userid = $this->get_mappingid('user', $data->userid) ?: 0;
        $DB->insert_record('evidencechecklist_resultlog', $data);
    }

    /**
     * Gap.
     *
     * @param array $data
     */
    protected function process_evidencechecklist_gap($data) {
        global $DB;
        $data = (object)$data;
        $data->attemptid = $this->get_new_parentid('evidencechecklist_attempt');
        $data->itemid = $this->get_mappingid('evidencechecklist_item', $data->itemid);
        if (!$data->attemptid || !$data->itemid) {
            return;
        }
        $data->assessorid = $data->assessorid ? $this->get_mappingid('user', $data->assessorid) : null;
        $data->closedby = !empty($data->closedby) ? $this->get_mappingid('user', $data->closedby) : null;
        $DB->insert_record('evidencechecklist_gap', $data);
    }

    /**
     * Confirmation.
     *
     * @param array $data
     */
    protected function process_evidencechecklist_confirm($data) {
        global $DB;
        $data = (object)$data;
        $data->attemptid = $this->get_new_parentid('evidencechecklist_attempt');
        if (!$data->attemptid) {
            return;
        }
        $data->userid = $this->get_mappingid('user', $data->userid) ?: 0;
        $DB->insert_record('evidencechecklist_confirm', $data);
    }

    /**
     * Signature.
     *
     * @param array $data
     */
    protected function process_evidencechecklist_signature($data) {
        global $DB;
        $data = (object)$data;
        $old = $data->id;
        $data->attemptid = $this->get_new_parentid('evidencechecklist_attempt');
        if (!$data->attemptid) {
            return;
        }
        $data->userid = $this->get_mappingid('user', $data->userid) ?: 0;
        $this->set_mapping('evidencechecklist_signature', $old, $DB->insert_record('evidencechecklist_signature', $data), true);
    }

    /**
     * Evidence file that also covers other criteria.
     *
     * @param array $data
     */
    protected function process_evidencechecklist_medialink($data) {
        global $DB;
        $data = (object)$data;
        $data->attemptid = $this->get_new_parentid('evidencechecklist_attempt');
        $data->sourceid = $this->get_mappingid('evidencechecklist_result', $data->sourceid);
        $data->targetid = $this->get_mappingid('evidencechecklist_result', $data->targetid);
        if (!$data->attemptid || !$data->sourceid || !$data->targetid) {
            return;
        }
        $data->userid = $this->get_mappingid('user', $data->userid) ?: 0;
        $DB->insert_record('evidencechecklist_medialink', $data);
    }

    /**
     * Files.
     */
    protected function after_execute() {
        $this->add_related_files('mod_evidencechecklist', 'intro', null);
        $this->add_related_files('mod_evidencechecklist', 'itemimage', 'evidencechecklist_item');
        $this->add_related_files('mod_evidencechecklist', 'cellmedia', 'evidencechecklist_result');
        $this->add_related_files('mod_evidencechecklist', 'poster', 'evidencechecklist_result');
        $this->add_related_files('mod_evidencechecklist', 'sessionphoto', 'evidencechecklist_session');
        $this->add_related_files('mod_evidencechecklist', 'signature', 'evidencechecklist_signature');
    }
}
