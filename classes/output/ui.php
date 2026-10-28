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

namespace mod_evidencechecklist\output;

use html_writer;
use mod_evidencechecklist\local\attempt_manager;
use mod_evidencechecklist\local\checklist;
use mod_evidencechecklist\local\defaults;
use mod_evidencechecklist\local\state;
use moodle_url;

/**
 * Page building blocks.
 *
 * @package    mod_evidencechecklist
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ui {
    /**
     * Strings the JS app needs.
     *
     * @return array
     */
    public static function js_strings(): array {
        $keys = ['s', 'nys', 'notobserved', 'notobserved_short', 'alls', 'allnys', 'undo', 'saved', 'savefailed', 'updatedjustnow',
            'progresslabel', 'readyforsignoff', 'comment', 'assessornote', 'assessornote_help', 'timer', 'start', 'stop',
            'addphoto', 'addvideo', 'addaudio', 'recordaudio', 'stoprecording', 'uploading', 'evidence', 'delete',
            'gapplan', 'observed', 'strategy', 'reobservedate', 'save', 'close', 'benchmark', 'verbal', 'optional',
            'dictate', 'listening', 'tips', 'tipson', 'tipsoff', 'gotit', 'guide', 'signoff', 'decision', 'competent', 'nyc',
            'feedback', 'studentsignature', 'assessorsignature', 'clear', 'typename', 'declaration', 'finalise',
            'finalised', 'blockers', 'preassessment', 'preassessment_intro', 'confirmall', 'adjustmentnote',
            'confirmsaved', 'confirmneeded', 'session', 'settingfield', 'simulated', 'workplace', 'equipmentfield',
            'adjustmentsfield', 'identity', 'photocandidate', 'idverified', 'idsighted', 'idnone', 'iddocument',
            'verifyidmatch', 'verifyidref', 'mappinglocked_msg', 'inprogress', 'notstarted', 'attemptn',
            'reassess', 'viewreport', 'downloadpdf', 'livehidden', 'gapsn', 'gapone', 'observedonce', 'lastsession',
            'newsession', 'ofobserved', 'bulkmarker', 'carriedmarker',
            'bulkreview', 'bulkconfirm', 'signhere', 'sign', 'signed', 'awaitingstudentsign', 'acknowledge', 'acknowledged',
            'cellhint', 'columnfill', 'groupfill', 'close', 'yourresult', 'decision_competent', 'decision_nyc',
            'nostatements', 'confirmfirst', 'longpresshint', 'back', 'classlist', 'tip_competency', 'tip_nograde',
            'tip_benchmark', 'tip_rules', 'tip_dimensions', 'tip_knowledge', 'tip_notobserved', 'tip_simulation',
            'tip_verbal', 'tip_bulk', 'tip_nys', 'tip_identity', 'tip_signoff', 'tip_credential', 'tip_retention',
            'tip_principles', 'tip_thirdparty', 'tip_competency_t', 'tip_nograde_t', 'tip_benchmark_t', 'tip_rules_t',
            'tip_dimensions_t', 'tip_knowledge_t', 'tip_notobserved_t', 'tip_simulation_t', 'tip_verbal_t', 'tip_bulk_t',
            'tip_nys_t', 'tip_identity_t', 'tip_signoff_t', 'tip_credential_t', 'tip_retention_t', 'tip_principles_t',
            'tip_thirdparty_t', 'learnmore', 'studentview', 'requiredphoto', 'statusfinalised', 'nomedia', 'media_toolarge',
            'alsocovers', 'alsocoversn', 'alsocovers_title', 'linkintro',
            'link_selectall', 'link_selectnone', 'link_cancel', 'linkcount', 'linkhere', 'linkthis', 'savelinks', 'linkedsaved',
            'linkedfrom', 'unlink', 'unlinked', 'help_alsocovers',
            'recordinghint', 'uploadaudio', 'photosection', 'assessmentfor', 'competentblocked', 'help', 'quickpad',
            'pad_photo', 'pad_video', 'pad_audio', 'pad_audio_stop', 'pad_comment', 'pad_for', 'addedto', 'commentsaved',
            'recording', 'quickphrases', 'quickphrases_desc', 'recentcomments', 'moredetails', 'enterhint', 'shortcuts',
            'shortcuts_title', 'shortcuts_intro', 'sc_group_mark', 'sc_group_move', 'sc_group_evidence', 'sc_group_more',
            'sc_group_panel', 'sc_group_touch', 'sc_s', 'sc_n', 'sc_o', 'sc_clear', 'sc_space', 'sc_rows', 'sc_group',
            'sc_undo', 'sc_arrows', 'sc_updown', 'sc_empty', 'sc_section', 'sc_jump', 'sc_student', 'sc_photo', 'sc_video',
            'sc_audio', 'sc_comment', 'sc_timer', 'sc_details', 'sc_signoff', 'sc_help', 'sc_close', 'sc_panelmark',
            'sc_panelitems', 'sc_panelcols', 'sc_tap', 'sc_hold', 'sc_pad', 'jumpto', 'jumpto_ph', 'nosuchitem', 'undone',
            'nothingtoundo', 'allmarked', 'prevstudent', 'nextstudent', 'studentpos', 'rowmarked', 'help_iddocument',
            'help_idsighted', 'help_verifyid', 'help_photo', 'help_simulated', 'help_setting', 'help_equipment',
            'help_adjustments', 'help_adjustmentnote', 'help_statements', 'help_comment', 'help_assessornote', 'help_timer',
            'help_evidence', 'help_observed', 'help_strategy', 'help_reobservedate', 'help_result', 'help_decision',
            'help_feedback', 'help_signature', 'help_typedname', 'help_declare', 'help_bulkok', 'help_ack', 'identity',
            'assessmenttype', 'askcandidate', 'confirm_waiting', 'confirm_waiting_sub', 'confirm_handover', 'confirm_verbal',
            'confirm_verbal_intro', 'confirm_verbal_save', 'confirm_forcandidate', 'confirm_forcandidate_intro',
            'confirm_iconfirm', 'confirm_back', 'confirm_by', 'confirm_partial', 'help_verbal', 'help_handover',
            'method_own', 'method_assessortablet', 'method_verbal', 'sc_column', 'sc_nextopen', 'sc_swipe',
            'nextopen', 'nonextopen', 'confirmfirst_attempt', 'confirmfirst_how', 'reassess_started',
            'gapopen_help', 'gapclosed_reobserved', 'gapclosed_assessor', 'gapclosedby', 'gapclose', 'gapreopen', 'gapclosenote',
            'gapclosenote_ph', 'gapclosedtoast', 'gapclosedtag', 'gapcarried', 'help_gapclose'];
        $out = [];
        foreach (array_unique($keys) as $k) {
            $out[$k] = get_string($k, 'mod_evidencechecklist');
        }
        $out['edit'] = get_string('edit');
        $out['view'] = get_string('view');
        return $out;
    }

    /**
     * The checklist app (assessor marking screen or student live view).
     *
     * @param \cm_info $cm
     * @param \context_module $context
     * @param checklist $cl
     * @param \stdClass|null $attempt
     * @param int $userid
     * @param string $role assessor, viewer or student
     * @return string
     */
    public static function app(
        $cm,
        \context_module $context,
        checklist $cl,
        ?\stdClass $attempt,
        int $userid,
        string $role
    ): string {
        global $PAGE, $USER;
        $assessorview = $role !== 'student';
        $config = [
            'role' => $role,
            'userid' => $userid,
            'structure' => state::structure($cm, $context, $cl),
            'state' => $attempt ? state::attempt($cm, $context, $cl, $attempt, $assessorview) : null,
            'tips' => (int)get_user_preferences(
                'mod_evidencechecklist_tips',
                (int)get_config('mod_evidencechecklist', 'tipsdefault')
            ),
            'dismissed' => array_values(
                array_filter(
                    explode(
                        ',',
                        (string)get_user_preferences('mod_evidencechecklist_dismissed', '')
                    )
                )
            ),
            'strings' => self::js_strings(),
            'urls' => [
                'upload' => (new moodle_url('/mod/evidencechecklist/upload.php'))->out(false),
                'report' => (new moodle_url('/mod/evidencechecklist/report.php', ['id' => $cm->id, 'userid' => $userid]))
                    ->out(false),
                'pdf' => (new moodle_url(
                    '/mod/evidencechecklist/report.php',
                    ['id' => $cm->id, 'userid' => $userid,
                    'format' => 'pdf']
                ))->out(false),
                'list' => (new moodle_url('/mod/evidencechecklist/view.php', ['id' => $cm->id]))->out(false),
                'mapping' => (new moodle_url('/mod/evidencechecklist/mapping.php', ['id' => $cm->id]))->out(false),
            ],
            'canfinalise' => has_capability('mod/evidencechecklist:finalise', $context),
            'nav' => $role === 'student' ? null : self::student_nav($cm, $context, $userid),
            'canassess' => $role === 'assessor' && $cl->assessing_allowed(),
            'me' => fullname($USER),
            'sesskey' => sesskey(),
        ];
        $domid = 'ec-app-' . $cm->id;
        $PAGE->requires->js_call_amd('mod_evidencechecklist/app', 'init', ['#' . $domid]);
        [$green, $magenta] = defaults::colours();
        $style = "--ec-green:$green;--ec-magenta:$magenta;";
        $html = html_writer::tag(
            'script',
            json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP),
            ['type' => 'application/json', 'id' => $domid . '-data']
        );
        $html .= html_writer::div(
            self::skeleton(),
            'ec-root ec-app',
            ['id' => $domid, 'style' => $style,
            'data-role' => $role]
        );
        return $html;
    }

    /**
     * Previous and next student for the assessor, in class list order and within the current group.
     *
     * @param \cm_info $cm
     * @param \context_module $context
     * @param int $userid
     * @return array|null
     */
    protected static function student_nav($cm, \context_module $context, int $userid): ?array {
        $groupid = groups_get_activity_group($cm, true) ?: 0;
        $users = array_values(
            get_enrolled_users(
                $context,
                'mod/evidencechecklist:beassessed',
                (int)$groupid,
                'u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename',
                'u.lastname, u.firstname',
                0,
                0,
                true
            )
        );
        $pos = null;
        foreach ($users as $i => $u) {
            if ((int)$u->id === $userid) {
                $pos = $i;
            }
        }
        if ($pos === null) {
            return null;
        }
        $link = function ($i) use ($users, $cm) {
            if (!isset($users[$i])) {
                return null;
            }
            return ['name' => fullname($users[$i]), 'url' => (new moodle_url(
                '/mod/evidencechecklist/view.php',
                ['id' => $cm->id, 'userid' => $users[$i]->id]
            ))->out(false)];
        };
        // The next student, after this one and wrapping round, who is not yet finalised as Competent.
        global $DB;
        $done = [];
        $sql = "SELECT a.id, a.userid, a.status, a.decision, a.attemptno
                  FROM {evidencechecklist_attempt} a
                 WHERE a.checklistid = ?
              ORDER BY a.attemptno ASC";
        foreach ($DB->get_records_sql($sql, [$cm->instance]) as $a) {
            $done[$a->userid] = $a->status === 'finalised' && $a->decision === 'competent';
        }
        $open = null;
        for ($k = 1; $k < count($users); $k++) {
            $i = ($pos + $k) % count($users);
            if (empty($done[$users[$i]->id])) {
                $open = $link($i);
                break;
            }
        }
        return ['n' => $pos + 1, 'total' => count($users), 'prev' => $link($pos - 1), 'next' => $link($pos + 1),
            'open' => $open];
    }

    /**
     * Help icon with an advice pop-up (same markup as the app's help()).
     *
     * @param string $key string key without the help_ prefix
     * @return string
     */
    public static function help(string $key): string {
        $text = get_string('help_' . $key, 'mod_evidencechecklist');
        $icon = '<svg viewBox="0 0 24 24" class="ec-ico-sm" aria-hidden="true"><circle cx="12" cy="12" r="9"/>' .
            '<path d="M12 11v6M12 7.5v.5"/></svg>';
        return html_writer::tag(
            'span',
            $icon . html_writer::span(s($text), 'ec-help-pop', ['role' => 'tooltip']),
            [
            'class' => 'ec-help', 'tabindex' => 0, 'role' => 'button',
            'aria-label' => get_string('help', 'mod_evidencechecklist') . ': ' . $text,
            ]
        );
    }

    /**
     * Loading skeleton shown until the app renders.
     *
     * @return string
     */
    protected static function skeleton(): string {
        $bar = html_writer::div('', 'ec-skel ec-skel-bar');
        $cards = '';
        for ($i = 0; $i < 3; $i++) {
            $cards .= html_writer::div(
                html_writer::div('', 'ec-skel ec-skel-line') .
                html_writer::div('', 'ec-skel ec-skel-line short'),
                'ec-card ec-skel-card'
            );
        }
        return $bar . $cards;
    }

    /**
     * Mapping status pill.
     *
     * @param string $state
     * @param int $gaps
     * @return string
     */
    public static function mapping_pill(string $state, int $gaps = 0): string {
        $icons = ['complete' => '✓', 'gaps' => '!', 'notstarted' => '–'];
        $label = get_string('mappingpill_' . $state, 'mod_evidencechecklist', $gaps);
        return html_writer::span(
            html_writer::span($icons[$state] ?? '', 'ec-pill-icon') . s($label),
            'ec-pill ec-pill-' . $state
        );
    }

    /**
     * Progress bar HTML.
     *
     * @param array $p progress
     * @param bool $small
     * @return string
     */
    public static function progress_bar(array $p, bool $small = false): string {
        $req = max(1, $p['required']);
        $s = round($p['s'] / $req * 100, 2);
        $n = round($p['nys'] / $req * 100, 2);
        $bar = html_writer::div(
            html_writer::span('', 'ec-bar-s', ['style' => "width:{$s}%"]) .
            html_writer::span('', 'ec-bar-nys', ['style' => "width:{$n}%"]),
            'ec-bar' . ($small ? ' ec-bar-sm' : '') . (!empty($p['complete']) ? ' ec-bar-done' : ''),
            ['role' => 'progressbar', 'aria-valuemin' => 0, 'aria-valuemax' => 100, 'aria-valuenow' => $p['percent'],
            'aria-label' => get_string(
                'progresslabel',
                'mod_evidencechecklist',
                (object)$p
            )]
        );
        return $bar;
    }

    /**
     * Students the viewer can see, with their current attempt and progress.
     *
     * @param \cm_info $cm
     * @param \context_module $context
     * @param checklist $cl
     * @param int $groupid
     * @return array
     */
    public static function students($cm, \context_module $context, checklist $cl, int $groupid): array {
        global $DB;
        $users = get_enrolled_users(
            $context,
            'mod/evidencechecklist:beassessed',
            $groupid,
            'u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename,' .
            ' u.picture, u.imagealt, u.email',
            'u.lastname, u.firstname',
            0,
            0,
            true
        );
        $attempts = [];
        $sql = "SELECT a.*
                  FROM {evidencechecklist_attempt} a
                 WHERE a.checklistid = ?
              ORDER BY a.attemptno ASC";
        foreach ($DB->get_records_sql($sql, [$cl->record->id]) as $a) {
            $attempts[$a->userid] = $a;
        }
        $results = [];
        if ($attempts) {
            [$insql, $params] = $DB->get_in_or_equal(array_map(fn($a) => $a->id, $attempts));
            $rs = $DB->get_recordset_select(
                'evidencechecklist_result',
                "attemptid $insql",
                $params,
                '',
                'id, attemptid, itemid, contextkey, occasionno, result'
            );
            foreach ($rs as $r) {
                $results[$r->attemptid][$r->itemid . '#' . $r->contextkey . '|' . $r->occasionno] = $r->result;
            }
            $rs->close();
        }
        $out = [];
        foreach ($users as $u) {
            $a = $attempts[$u->id] ?? null;
            $p = attempt_manager::progress($cl, $a ? ($results[$a->id] ?? []) : []);
            $out[] = (object)['user' => $u, 'attempt' => $a, 'progress' => $p];
        }
        return $out;
    }

    /**
     * Assessor class list.
     *
     * @param \cm_info $cm
     * @param \context_module $context
     * @param checklist $cl
     * @return string
     */
    public static function class_list($cm, \context_module $context, checklist $cl): string {
        global $OUTPUT, $PAGE;
        $p = 'mod_evidencechecklist';
        $groupid = groups_get_activity_group($cm, true) ?: 0;
        $students = self::students($cm, $context, $cl, (int)$groupid);
        $mapping = $cl->mapping();
        [$green, $magenta] = defaults::colours();

        $counts = ['total' => count($students), 'inprogress' => 0, 'competent' => 0, 'nyc' => 0];
        foreach ($students as $s) {
            if (!$s->attempt) {
                continue;
            }
            if ($s->attempt->status === 'finalised') {
                $counts[$s->attempt->decision === 'competent' ? 'competent' : 'nyc']++;
            } else {
                $counts['inprogress']++;
            }
        }

        $html = html_writer::start_div('ec-root ec-classlist', ['style' => "--ec-green:$green;--ec-magenta:$magenta;"]);
        $html .= html_writer::start_div('ec-pagehead');
        // Moodle's activity header already shows the activity name, so lead with the unit.
        $unit = trim($cl->record->unitcode . ' ' . format_string((string)$cl->record->unittitle));
        $html .= html_writer::div(
            html_writer::div(get_string('classlist', $p), 'ec-kicker') .
            html_writer::tag(
                'h2',
                $unit !== '' ? s($unit) : format_string($cl->record->name, true, ['context' => $context]),
                ['class' => 'ec-title ec-title-md']
            ),
            'ec-pagehead-text'
        );
        if (has_any_capability(['mod/evidencechecklist:manage', 'mod/evidencechecklist:viewallreports'], $context)) {
            $html .= html_writer::link(
                new moodle_url('/mod/evidencechecklist/mapping.php', ['id' => $cm->id]),
                self::mapping_pill($mapping['state'], $mapping['gaps']),
                ['class' => 'ec-pill-link']
            );
        } else {
            $html .= self::mapping_pill($mapping['state'], $mapping['gaps']);
        }
        $html .= html_writer::end_div();

        if (!$cl->items) {
            $msg = get_string('noitems', $p);
            if (has_capability('mod/evidencechecklist:manage', $context)) {
                $msg .= ' ' . html_writer::link(
                    new moodle_url('/mod/evidencechecklist/edit.php', ['id' => $cm->id]),
                    get_string('editchecklist', $p),
                    ['class' => 'ec-btn ec-btn-primary']
                );
            }
            $html .= html_writer::div(html_writer::div('', 'ec-empty-art') . html_writer::tag('p', $msg), 'ec-card ec-empty');
            return $html . html_writer::end_div();
        }
        if (!$cl->assessing_allowed()) {
            $html .= html_writer::div(
                html_writer::tag('strong', get_string('mappinglocked_title', $p)) . ' ' .
                get_string('mappinglocked_msg', $p),
                'ec-card ec-callout ec-callout-gap'
            );
        }

        $html .= html_writer::start_div('ec-stats');
        foreach (
            ['total' => 'stat_students', 'inprogress' => 'stat_inprogress', 'competent' => 'stat_competent',
                'nyc' => 'stat_nyc'] as $k => $label
        ) {
            $cls = $k === 'competent' ? ' ec-stat-good' : ($k === 'nyc' ? ' ec-stat-gap' : '');
            $html .= html_writer::div(
                html_writer::div($counts[$k], 'ec-stat-num') .
                html_writer::div(get_string($label, $p), 'ec-stat-label'),
                'ec-card ec-stat' . $cls
            );
        }
        $html .= html_writer::end_div();

        $groupmenu = groups_print_activity_menu($cm, new moodle_url('/mod/evidencechecklist/view.php', ['id' => $cm->id]), true);
        $search = html_writer::empty_tag(
            'input',
            ['type' => 'search', 'class' => 'ec-input ec-search',
            'placeholder' => get_string('searchstudents', $p), 'data-ec-search' => '1',
            'aria-label' => get_string('searchstudents', $p)]
        );
        $links = html_writer::link(
            new moodle_url('/mod/evidencechecklist/register.php', ['id' => $cm->id]),
            get_string('register', $p),
            ['class' => 'ec-btn']
        );
        $html .= html_writer::div(
            html_writer::div($search . self::help('search') . $groupmenu, 'ec-toolbar-left') .
            html_writer::div($links, 'ec-toolbar-right'),
            'ec-toolbar'
        );

        $html .= html_writer::start_div('ec-studentgrid', ['data-ec-list' => '1']);
        foreach ($students as $s) {
            $u = $s->user;
            $a = $s->attempt;
            $pr = $s->progress;
            if ($a && $a->status === 'finalised') {
                $status = $a->decision === 'competent' ? 'competent' : 'nyc';
            } else if ($a && ($pr['s'] + $pr['nys']) > 0) {
                $status = 'inprogress';
            } else {
                $status = 'notstarted';
            }
            $statuspill = html_writer::span(get_string('status_' . $status, $p), 'ec-pill ec-pill-sm ec-pill-' . $status);
            $url = new moodle_url('/mod/evidencechecklist/view.php', ['id' => $cm->id, 'userid' => $u->id]);
            $meta = get_string('progresslabel', $p, (object)$pr);
            $card = html_writer::div(
                $OUTPUT->user_picture($u, ['size' => 40, 'link' => false, 'class' => 'ec-avatar']) .
                html_writer::div(
                    html_writer::div(fullname($u), 'ec-sname') .
                    html_writer::div($a && $a->attemptno > 1 ? get_string('attemptn', $p, $a->attemptno) : '', 'ec-smeta'),
                    'ec-sinfo'
                ) . $statuspill,
                'ec-shead'
            );
            $card .= self::progress_bar($pr, true);
            $card .= html_writer::div(s($meta), 'ec-sprogress');
            $card .= html_writer::span('›', 'ec-chevron', ['aria-hidden' => 'true']);
            $html .= html_writer::link(
                $url,
                $card,
                ['class' => 'ec-card ec-student ec-state-' . $status,
                'data-ec-name' => \core_text::strtolower(fullname($u))]
            );
        }
        $html .= html_writer::end_div();
        $html .= html_writer::end_div();
        $PAGE->requires->js_call_amd('mod_evidencechecklist/classlist', 'init');
        return $html;
    }
}
