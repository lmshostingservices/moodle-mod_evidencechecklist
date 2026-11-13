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

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for Evidence Checklist.
 *
 * @package    mod_evidencechecklist
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\user_preference_provider {
    /** @var string[] Child tables keyed by attemptid. */
    const CHILDREN = ['evidencechecklist_result', 'evidencechecklist_resultlog', 'evidencechecklist_gap',
        'evidencechecklist_confirm', 'evidencechecklist_signature', 'evidencechecklist_session', 'evidencechecklist_medialink'];
    /** @var string[] File areas holding attempt data. */
    const AREAS = ['cellmedia' => 'evidencechecklist_result', 'poster' => 'evidencechecklist_result',
        'sessionphoto' => 'evidencechecklist_session', 'signature' => 'evidencechecklist_signature'];

    /**
     * Metadata.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $p = 'privacy:metadata:';
        $collection->add_database_table(
            'evidencechecklist_attempt',
            ['userid' => $p . 'userid', 'status' => $p . 'status',
            'decision' => $p . 'decision', 'assessorid' => $p . 'assessorid', 'feedback' => $p . 'feedback',
            'timefinalised' => $p . 'timefinalised'],
            $p . 'evidencechecklist_attempt'
        );
        $collection->add_database_table(
            'evidencechecklist_result',
            ['result' => $p . 'result', 'comment' => $p . 'comment',
            'assessornote' => $p . 'assessornote', 'assessorid' => $p . 'assessorid', 'timemodified' => $p . 'timemodified'],
            $p . 'evidencechecklist_result'
        );
        $collection->add_database_table(
            'evidencechecklist_resultlog',
            ['userid' => $p . 'userid', 'oldvalue' => $p . 'oldvalue',
            'newvalue' => $p . 'newvalue', 'timecreated' => $p . 'timecreated'],
            $p . 'evidencechecklist_resultlog'
        );
        $collection->add_database_table(
            'evidencechecklist_gap',
            ['observed' => $p . 'observed', 'strategy' => $p . 'strategy',
            'assessorid' => $p . 'assessorid', 'closenote' => $p . 'closenote', 'closedby' => $p . 'closedby',
            'timeclosed' => $p . 'timeclosed'],
            $p . 'evidencechecklist_gap'
        );
        $collection->add_database_table(
            'evidencechecklist_confirm',
            ['userid' => $p . 'userid', 'response' => $p . 'response',
            'note' => $p . 'note', 'timecreated' => $p . 'timecreated'],
            $p . 'evidencechecklist_confirm'
        );
        $collection->add_database_table(
            'evidencechecklist_medialink',
            ['userid' => $p . 'userid', 'filename' => $p . 'filename', 'timecreated' => $p . 'timecreated'],
            $p . 'evidencechecklist_medialink'
        );
        $collection->add_database_table(
            'evidencechecklist_signature',
            ['userid' => $p . 'userid',
            'typedname' => $p . 'typedname', 'device' => $p . 'device', 'timecreated' => $p . 'timecreated'],
            $p . 'evidencechecklist_signature'
        );
        $collection->add_database_table(
            'evidencechecklist_session',
            ['assessorid' => $p . 'assessorid',
            'setting' => $p . 'setting', 'adjustments' => $p . 'adjustments', 'idmethod' => $p . 'idmethod',
            'iddocument' => $p . 'iddocument'],
            $p . 'evidencechecklist_session'
        );
        $collection->add_database_table(
            'evidencechecklist_maplog',
            ['userid' => $p . 'userid', 'action' => $p . 'action'],
            $p . 'evidencechecklist_maplog'
        );
        $collection->add_subsystem_link('core_files', [], $p . 'core_files');
        // LMS Labs activation check: site-level licence details only. No personal or learner data is sent.
        $collection->add_external_location_link('lmslabs', [
            'siteid' => $p . 'lmslabs:siteid',
            'apikey' => $p . 'lmslabs:apikey',
            'pluginid' => $p . 'lmslabs:pluginid',
        ], $p . 'lmslabs');
        $collection->add_user_preference('mod_evidencechecklist_tips', $p . 'pref_tips');
        $collection->add_user_preference('mod_evidencechecklist_dismissed', $p . 'pref_dismissed');
        $collection->add_external_location_link(
            'browserdictation',
            ['audio' => $p . 'browserdictation:audio'],
            $p . 'browserdictation'
        );
        return $collection;
    }

    /**
     * Contexts with data for a user (as student or as staff).
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $base = "SELECT ctx.id
                   FROM {context} ctx
                   JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :ctxlevel
                   JOIN {modules} m ON m.id = cm.module AND m.name = 'evidencechecklist'
                   JOIN {evidencechecklist_attempt} a ON a.checklistid = cm.instance";
        $params = ['ctxlevel' => CONTEXT_MODULE, 'u1' => $userid, 'u2' => $userid, 'u3' => $userid, 'u4' => $userid,
            'u5' => $userid];
        $contextlist->add_from_sql(
            $base . "
                  WHERE a.userid = :u1 OR a.assessorid = :u2
                     OR EXISTS (SELECT 1 FROM {evidencechecklist_result} r WHERE r.attemptid = a.id AND r.assessorid = :u3)
                     OR EXISTS (SELECT 1 FROM {evidencechecklist_session} s WHERE s.attemptid = a.id AND s.assessorid = :u4)
                     OR EXISTS (SELECT 1 FROM {evidencechecklist_signature} g WHERE g.attemptid = a.id AND g.userid = :u5)",
            $params
        );
        return $contextlist;
    }

    /**
     * Users with data in a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        $params = ['cmid' => $context->instanceid, 'modname' => 'evidencechecklist'];
        $join = "FROM {evidencechecklist_attempt} a
                 JOIN {course_modules} cm ON cm.instance = a.checklistid
                 JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                WHERE cm.id = :cmid";
        $userlist->add_from_sql('userid', "SELECT a.userid $join", $params);
        $userlist->add_from_sql('assessorid', "SELECT a.assessorid $join AND a.assessorid IS NOT NULL", $params);
        $sub = "FROM {evidencechecklist_attempt} a
                JOIN {course_modules} cm ON cm.instance = a.checklistid
                JOIN {modules} m ON m.id = cm.module AND m.name = :modname";
        $userlist->add_from_sql(
            'assessorid',
            "SELECT r.assessorid $sub
            JOIN {evidencechecklist_result} r ON r.attemptid = a.id
            WHERE cm.id = :cmid AND r.assessorid IS NOT NULL",
            $params
        );
        $userlist->add_from_sql(
            'assessorid',
            "SELECT s.assessorid $sub
            JOIN {evidencechecklist_session} s ON s.attemptid = a.id
            WHERE cm.id = :cmid",
            $params
        );
        $userlist->add_from_sql(
            'userid',
            "SELECT g.userid $sub
            JOIN {evidencechecklist_signature} g ON g.attemptid = a.id
            WHERE cm.id = :cmid",
            $params
        );
    }

    /**
     * Export.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_MODULE) {
                continue;
            }
            $cm = get_coursemodule_from_id('evidencechecklist', $context->instanceid);
            if (!$cm) {
                continue;
            }
            $base = [get_string('privacy:attempts', 'mod_evidencechecklist')];
            $attempts = $DB->get_records('evidencechecklist_attempt', ['checklistid' => $cm->instance]);
            foreach ($attempts as $a) {
                $own = $a->userid == $userid;
                $staff = !$own && ($a->assessorid == $userid || $DB->record_exists_select(
                    'evidencechecklist_result',
                    'attemptid = ? AND assessorid = ?',
                    [$a->id, $userid]
                ));
                if (!$own && !$staff) {
                    continue;
                }
                $path = array_merge($base, [$a->id]);
                $results = $DB->get_records('evidencechecklist_result', ['attemptid' => $a->id], 'id');
                $data = (object)[
                    'attemptno' => $a->attemptno,
                    'status' => $a->status,
                    'decision' => $a->decision,
                    'feedback' => $a->feedback,
                    'timefinalised' => $a->timefinalised ? transform::datetime($a->timefinalised) : null,
                    'role' => $own ? 'student' : 'assessor',
                    'results' => array_values(
                        array_map(
                            fn($r) => (object)['itemid' => $r->itemid, 'context' => $r->contextkey,
                                'occasion' => $r->occasionno, 'result' => $r->result, 'comment' => $r->comment,
                                'assessornote' => $own ? null : $r->assessornote,
                            'timemodified' => transform::datetime($r->timemodified)],
                            $results
                        )
                    ),
                    'gaps' => array_values(
                        array_map(
                            fn($g) => (object)['itemid' => $g->itemid, 'observed' => $g->observed,
                            'strategy' => $g->strategy, 'status' => $g->status],
                            $DB->get_records('evidencechecklist_gap', ['attemptid' => $a->id])
                        )
                    ),
                    'confirmations' => array_values(
                        array_map(
                            fn($c) => (object)['statement' => $c->statementtext,
                            'response' => transform::yesno($c->response), 'note' => $c->note,
                            'time' => transform::datetime($c->timecreated)],
                            $DB->get_records('evidencechecklist_confirm', ['attemptid' => $a->id])
                        )
                    ),
                    'signatures' => array_values(
                        array_map(
                            fn($s) => (object)['role' => $s->role, 'typedname' => $s->typedname,
                            'time' => transform::datetime($s->timecreated)],
                            $DB->get_records('evidencechecklist_signature', ['attemptid' => $a->id])
                        )
                    ),
                    'evidencelinks' => array_values(
                        array_map(
                            fn($l) => (object)['file' => $l->filename, 'sourceid' => $l->sourceid, 'targetid' => $l->targetid,
                            'time' => transform::datetime($l->timecreated)],
                            $DB->get_records('evidencechecklist_medialink', ['attemptid' => $a->id])
                        )
                    ),
                ];
                writer::with_context($context)->export_data($path, $data);
                if ($own) {
                    foreach (self::AREAS as $area => $table) {
                        foreach ($DB->get_fieldset_select($table, 'id', 'attemptid = ?', [$a->id]) as $itemid) {
                            writer::with_context($context)->export_area_files($path, 'mod_evidencechecklist', $area, $itemid);
                        }
                    }
                }
            }
        }
    }

    /**
     * Export preferences.
     *
     * @param int $userid
     */
    public static function export_user_preferences(int $userid) {
        foreach (['mod_evidencechecklist_tips', 'mod_evidencechecklist_dismissed'] as $name) {
            $value = get_user_preferences($name, null, $userid);
            if ($value !== null) {
                writer::export_user_preference(
                    'mod_evidencechecklist',
                    $name,
                    $value,
                    get_string(
                        'privacy:metadata:pref_' . ($name === 'mod_evidencechecklist_tips' ? 'tips' : 'dismissed'),
                        'mod_evidencechecklist'
                    )
                );
            }
        }
    }

    /**
     * Delete attempts (and their files) for given student ids in a context.
     *
     * @param \context_module $context
     * @param int $checklistid
     * @param array|null $userids null = all
     */
    protected static function delete_attempts(\context_module $context, int $checklistid, ?array $userids): void {
        global $DB;
        $params = ['checklistid' => $checklistid];
        $where = 'checklistid = :checklistid';
        if ($userids !== null) {
            if (!$userids) {
                return;
            }
            [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
            $where .= " AND userid $insql";
            $params += $inparams;
        }
        $attemptids = $DB->get_fieldset_select('evidencechecklist_attempt', 'id', $where, $params);
        if (!$attemptids) {
            return;
        }
        [$asql, $aparams] = $DB->get_in_or_equal($attemptids);
        $fs = get_file_storage();
        foreach (self::AREAS as $area => $table) {
            foreach ($DB->get_fieldset_select($table, 'id', "attemptid $asql", $aparams) as $itemid) {
                $fs->delete_area_files($context->id, 'mod_evidencechecklist', $area, $itemid);
            }
        }
        foreach (self::CHILDREN as $table) {
            $DB->delete_records_select($table, "attemptid $asql", $aparams);
        }
        $DB->delete_records_select('evidencechecklist_attempt', "id $asql", $aparams);
    }

    /**
     * Delete all.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        if (!$context instanceof \context_module) {
            return;
        }
        $cm = get_coursemodule_from_id('evidencechecklist', $context->instanceid);
        if ($cm) {
            self::delete_attempts($context, (int)$cm->instance, null);
        }
    }

    /**
     * Delete for one user (their own assessment records; staff actions on others' records are kept as part of
     * those students' assessment evidence).
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('evidencechecklist', $context->instanceid);
            if ($cm) {
                self::delete_attempts($context, (int)$cm->instance, [$userid]);
            }
        }
    }

    /**
     * Delete for users.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        $cm = get_coursemodule_from_id('evidencechecklist', $context->instanceid);
        if ($cm) {
            self::delete_attempts($context, (int)$cm->instance, $userlist->get_userids());
        }
    }
}
