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
 * Backup structure.
 *
 * @package    mod_evidencechecklist
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Backup structure step for Evidence Checklist.
 */
class backup_evidencechecklist_activity_structure_step extends backup_activity_structure_step {
    /**
     * Structure.
     *
     * @return backup_nested_element
     */
    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');

        $root = new backup_nested_element(
            'evidencechecklist',
            ['id'],
            ['name', 'intro', 'introformat', 'unitcode',
            'unittitle', 'setting', 'equipment', 'defaultoccasions', 'columnsdef', 'requiremapping', 'livestudentview', 'allowbulk',
            'requirephoto', 'statements', 'grade', 'completioncompetent', 'mappingapprovedby', 'mappingapprovedtime',
            'timecreated', 'timemodified']
        );
        $groups = new backup_nested_element('groups');
        $group = new backup_nested_element('group', ['id'], ['name', 'sortorder']);
        $items = new backup_nested_element('items');
        $item = new backup_nested_element(
            'item',
            ['id'],
            ['sortorder', 'criterion', 'benchmark', 'gaptip', 'mappingrefs',
            'contexts', 'occasions', 'mandatory', 'verbal']
        );
        $reqs = new backup_nested_element('reqs');
        $req = new backup_nested_element('req', ['id'], ['code', 'reqtext', 'othertool', 'sortorder']);
        $maps = new backup_nested_element('reqmaps');
        $map = new backup_nested_element('reqmap', ['id'], ['itemid']);

        $attempts = new backup_nested_element('attempts');
        $attempt = new backup_nested_element(
            'attempt',
            ['id'],
            ['userid', 'attemptno', 'status', 'decision', 'assessorid',
            'feedback', 'recordhash', 'rev', 'timefinalised', 'timecreated', 'timemodified']
        );
        $sessions = new backup_nested_element('sessions');
        $session = new backup_nested_element(
            'session',
            ['id'],
            ['assessorid', 'setting', 'simulated', 'equipment',
            'adjustments', 'idmethod', 'iddocument', 'verifyidattempt', 'timestart', 'timemodified']
        );
        $results = new backup_nested_element('results');
        $result = new backup_nested_element(
            'result',
            ['id'],
            ['itemid', 'contextkey', 'occasionno', 'result', 'comment',
            'assessornote', 'bulk', 'carried', 'timerseconds', 'sessionid', 'assessorid', 'timemodified']
        );
        $logs = new backup_nested_element('resultlogs');
        $log = new backup_nested_element(
            'resultlog',
            ['id'],
            ['resultid', 'userid', 'field', 'oldvalue', 'newvalue', 'bulk',
            'timecreated']
        );
        $gaps = new backup_nested_element('gaps');
        $gap = new backup_nested_element(
            'gap',
            ['id'],
            ['itemid', 'observed', 'strategy', 'reobservedate', 'status',
            'assessorid', 'closereason', 'closenote', 'closedby', 'timeclosed', 'timemodified']
        );
        $confirms = new backup_nested_element('confirms');
        $confirm = new backup_nested_element(
            'confirm',
            ['id'],
            ['statementno', 'statementtext', 'response', 'note', 'method',
            'userid', 'timecreated']
        );
        $links = new backup_nested_element('medialinks');
        $link = new backup_nested_element('medialink', ['id'], ['sourceid', 'filename', 'targetid', 'userid', 'timecreated']);
        $sigs = new backup_nested_element('signatures');
        $sig = new backup_nested_element(
            'signature',
            ['id'],
            ['userid', 'role', 'typedname', 'recordhash', 'device',
            'timecreated']
        );

        $root->add_child($groups);
        $groups->add_child($group);
        $group->add_child($items);
        $items->add_child($item);
        $root->add_child($reqs);
        $reqs->add_child($req);
        $req->add_child($maps);
        $maps->add_child($map);
        $root->add_child($attempts);
        $attempts->add_child($attempt);
        foreach (
            [[$sessions, $session], [$results, $result], [$logs, $log], [$gaps, $gap], [$confirms, $confirm],
                [$sigs, $sig], [$links, $link]] as [$wrap, $el]
        ) {
            $attempt->add_child($wrap);
            $wrap->add_child($el);
        }

        $root->set_source_table('evidencechecklist', ['id' => backup::VAR_ACTIVITYID]);
        $group->set_source_table('evidencechecklist_group', ['checklistid' => backup::VAR_PARENTID], 'sortorder ASC');
        $item->set_source_table('evidencechecklist_item', ['groupid' => backup::VAR_PARENTID], 'sortorder ASC');
        $req->set_source_table('evidencechecklist_req', ['checklistid' => backup::VAR_PARENTID], 'sortorder ASC');
        $map->set_source_table('evidencechecklist_reqmap', ['reqid' => backup::VAR_PARENTID]);
        if ($userinfo) {
            $attempt->set_source_table('evidencechecklist_attempt', ['checklistid' => backup::VAR_PARENTID], 'id ASC');
            $session->set_source_table('evidencechecklist_session', ['attemptid' => backup::VAR_PARENTID], 'id ASC');
            $result->set_source_table('evidencechecklist_result', ['attemptid' => backup::VAR_PARENTID], 'id ASC');
            $log->set_source_table('evidencechecklist_resultlog', ['attemptid' => backup::VAR_PARENTID], 'id ASC');
            $gap->set_source_table('evidencechecklist_gap', ['attemptid' => backup::VAR_PARENTID], 'id ASC');
            $confirm->set_source_table('evidencechecklist_confirm', ['attemptid' => backup::VAR_PARENTID], 'id ASC');
            $sig->set_source_table('evidencechecklist_signature', ['attemptid' => backup::VAR_PARENTID], 'id ASC');
            $link->set_source_table('evidencechecklist_medialink', ['attemptid' => backup::VAR_PARENTID], 'id ASC');
        }

        $root->annotate_ids('user', 'mappingapprovedby');
        $root->annotate_ids('scale', 'grade');
        $attempt->annotate_ids('user', 'userid');
        $attempt->annotate_ids('user', 'assessorid');
        $session->annotate_ids('user', 'assessorid');
        $result->annotate_ids('user', 'assessorid');
        $log->annotate_ids('user', 'userid');
        $gap->annotate_ids('user', 'assessorid');
        $gap->annotate_ids('user', 'closedby');
        $confirm->annotate_ids('user', 'userid');
        $sig->annotate_ids('user', 'userid');
        $link->annotate_ids('user', 'userid');

        $root->annotate_files('mod_evidencechecklist', 'intro', null);
        $item->annotate_files('mod_evidencechecklist', 'itemimage', 'id');
        $result->annotate_files('mod_evidencechecklist', 'cellmedia', 'id');
        $result->annotate_files('mod_evidencechecklist', 'poster', 'id');
        $session->annotate_files('mod_evidencechecklist', 'sessionphoto', 'id');
        $sig->annotate_files('mod_evidencechecklist', 'signature', 'id');

        return $this->prepare_activity_structure($root);
    }
}
