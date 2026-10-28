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
 * Upgrade steps for Evidence Checklist.
 *
 * @package    mod_evidencechecklist
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_evidencechecklist_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026102701) {
        // Grid columns are defined once per checklist (for example Adult and Infant) instead of per criterion.
        $table = new xmldb_table('evidencechecklist');
        $field = new xmldb_field('columnsdef', XMLDB_TYPE_TEXT, null, null, null, null, null, 'defaultoccasions');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2026102701, 'evidencechecklist');
    }

    if ($oldversion < 2026102801) {
        // Gap plans record how, when and by whom they were closed.
        $table = new xmldb_table('evidencechecklist_gap');
        $fields = [
            new xmldb_field('closereason', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'assessorid'),
            new xmldb_field('closenote', XMLDB_TYPE_TEXT, null, null, null, null, null, 'closereason'),
            new xmldb_field('closedby', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'closenote'),
            new xmldb_field('timeclosed', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'closedby'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_mod_savepoint(true, 2026102801, 'evidencechecklist');
    }

    if ($oldversion < 2026102802) {
        // One evidence file can also cover other criteria.
        $table = new xmldb_table('evidencechecklist_medialink');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('attemptid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('sourceid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('filename', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('targetid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('attemptid', XMLDB_KEY_FOREIGN, ['attemptid'], 'evidencechecklist_attempt', ['id']);
        $table->add_key('sourceid', XMLDB_KEY_FOREIGN, ['sourceid'], 'evidencechecklist_result', ['id']);
        $table->add_key('targetid', XMLDB_KEY_FOREIGN, ['targetid'], 'evidencechecklist_result', ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_index('link', XMLDB_INDEX_UNIQUE, ['sourceid', 'filename', 'targetid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_mod_savepoint(true, 2026102802, 'evidencechecklist');
    }
    return true;
}
