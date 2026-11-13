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
 * Register: one row per student with progress, outcome, pre-assessment confirmations, identity and signatures.
 *
 * @package    mod_evidencechecklist
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/csvlib.class.php');

use mod_evidencechecklist\local\checklist;
use mod_evidencechecklist\local\defaults;
use mod_evidencechecklist\output\ui;

$id = required_param('id', PARAM_INT);
$download = optional_param('download', '', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'evidencechecklist');
$record = $DB->get_record('evidencechecklist', ['id' => $cm->instance], '*', MUST_EXIST);
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
if (!has_capability('mod/evidencechecklist:viewallreports', $context)) {
    require_capability('mod/evidencechecklist:assess', $context);
}
$p = 'mod_evidencechecklist';
$url = new moodle_url('/mod/evidencechecklist/register.php', ['id' => $cm->id]);
$cl = new checklist($record);
$groupid = groups_get_activity_group($cm, true) ?: 0;
$students = ui::students($cm, $context, $cl, (int)$groupid);
$statements = defaults::statement_list($record);

// Bulk-load confirmations, sessions and signatures for current attempts.
$attemptids = array_values(array_filter(array_map(fn($s) => $s->attempt ? (int)$s->attempt->id : 0, $students)));
$confirms = $sessions = $sigs = [];
if ($attemptids) {
    [$insql, $params] = $DB->get_in_or_equal($attemptids);
    foreach ($DB->get_records_select('evidencechecklist_confirm', "attemptid $insql", $params) as $c) {
        $confirms[$c->attemptid][$c->statementno] = $c;
    }
    foreach ($DB->get_records_select('evidencechecklist_session', "attemptid $insql", $params, 'timestart ASC') as $s) {
        $sessions[$s->attemptid] = $s;
    }
    foreach ($DB->get_records_select('evidencechecklist_signature', "attemptid $insql", $params) as $s) {
        $sigs[$s->attemptid][$s->role] = $s;
    }
}

$rows = [];
foreach ($students as $s) {
    $a = $s->attempt;
    $status = !$a ? 'notstarted' : ($a->status === 'finalised' ? ($a->decision === 'competent' ? 'competent' : 'nyc') :
        (($s->progress['s'] + $s->progress['nys']) ? 'inprogress' : 'notstarted'));
    $conf = [];
    foreach ($statements as $i => $text) {
        $c = $a ? ($confirms[$a->id][$i + 1] ?? null) : null;
        $conf[] = $c && $c->response ? $c->timecreated : 0;
    }
    $sess = $a ? ($sessions[$a->id] ?? null) : null;
    $rows[] = (object)['user' => $s->user, 'attempt' => $a, 'progress' => $s->progress, 'status' => $status, 'confirm' => $conf,
        'identity' => $sess && $sess->idmethod ? $sess->idmethod : '',
        'studentsig' => $a && isset($sigs[$a->id]['student']) ? $sigs[$a->id]['student']->timecreated : 0,
        'assessorsig' => $a && isset($sigs[$a->id]['assessor']) ? $sigs[$a->id]['assessor']->timecreated : 0];
}

if ($download === 'csv') {
    $csv = new csv_export_writer();
    $csv->set_filename('register-' . clean_filename($record->unitcode ?: $record->name));
    $head = [get_string('fullname'), get_string('email'), get_string('status'), get_string('attempt', $p),
        get_string('progress', $p) . ' %', 'S', 'NYS', get_string('stat_required', $p)];
    foreach ($statements as $i => $t) {
        $head[] = get_string('statementn', $p, $i + 1);
    }
    $head = array_merge(
        $head,
        [get_string('identity', $p), get_string('studentsignature', $p),
        get_string('assessorsignature', $p), get_string('finaliseddate', $p)]
    );
    $csv->add_data($head);
    foreach ($rows as $r) {
        $line = [fullname($r->user), $r->user->email, get_string('status_' . $r->status, $p), $r->attempt->attemptno ?? '',
            $r->progress['percent'], $r->progress['s'], $r->progress['nys'], $r->progress['required']];
        foreach ($r->confirm as $t) {
            $line[] = $t ? userdate($t, '%Y-%m-%d %H:%M') : '';
        }
        $line[] = $r->identity ? get_string('id_' . $r->identity, $p) : '';
        $line[] = $r->studentsig ? userdate($r->studentsig, '%Y-%m-%d %H:%M') : '';
        $line[] = $r->assessorsig ? userdate($r->assessorsig, '%Y-%m-%d %H:%M') : '';
        $line[] = !empty($r->attempt->timefinalised) ? userdate($r->attempt->timefinalised, '%Y-%m-%d %H:%M') : '';
        $csv->add_data($line);
    }
    $csv->download_file();
    exit;
}

$PAGE->set_url($url);
$PAGE->set_title(get_string('register', $p));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->add_body_class('ec-page');
$PAGE->activityheader->disable();
[$green, $magenta] = defaults::colours();

echo $OUTPUT->header();
echo html_writer::start_div('ec-root ec-register', ['style' => "--ec-green:$green;--ec-magenta:$magenta;"]);
echo html_writer::div(
    html_writer::div(
        html_writer::tag('h2', get_string('register', $p), ['class' => 'ec-title']) .
        html_writer::div(format_string($record->name), 'ec-subtitle'),
        'ec-pagehead-text'
    ),
    'ec-pagehead'
);
$groupmenu = groups_print_activity_menu($cm, $url, true);
echo html_writer::div(
    html_writer::div($groupmenu, 'ec-toolbar-left') . html_writer::div(
        html_writer::link(
            new moodle_url('/mod/evidencechecklist/view.php', ['id' => $cm->id]),
            get_string('classlist', $p),
            ['class' => 'ec-btn']
        ) .
        html_writer::link(new moodle_url($url, ['download' => 'csv']), get_string('downloadcsv', $p), ['class' => 'ec-btn']),
        'ec-toolbar-right'
    ),
    'ec-toolbar'
);

// Summary stats.
$counts = ['competent' => 0, 'nyc' => 0, 'inprogress' => 0, 'notstarted' => 0];
$confirmedcount = 0;
foreach ($rows as $r) {
    $counts[$r->status]++;
    if ($statements && count(array_filter($r->confirm)) === count($statements)) {
        $confirmedcount++;
    }
}
$stats = '';
foreach (
    [['n' => count($rows), 'l' => 'stat_students', 'c' => ''],
        ['n' => $counts['inprogress'], 'l' => 'stat_inprogress', 'c' => ''],
        ['n' => $counts['competent'], 'l' => 'stat_competent', 'c' => ' ec-stat-good'],
        ['n' => $counts['nyc'], 'l' => 'stat_nyc', 'c' => $counts['nyc'] ? ' ec-stat-gap' : ''],
        ['n' => $confirmedcount, 'l' => 'stat_confirmed', 'c' => '']] as $s
) {
    $stats .= html_writer::div(
        html_writer::div($s['n'], 'ec-stat-num') . html_writer::div(
            get_string($s['l'], $p),
            'ec-stat-label'
        ),
        'ec-card ec-stat' . $s['c']
    );
}
echo html_writer::div($stats, 'ec-stats ec-stats-5');

$heads = [get_string('fullname'), get_string('progress', $p), get_string('status')];
foreach ($statements as $i => $t) {
    $heads[] = ['text' => html_writer::span($i + 1, 'ec-num', ['title' => s($t)]), 'class' => 'ec-tc'];
}
$heads[] = ['text' => get_string('identity', $p), 'class' => 'ec-tc'];
$heads[] = ['text' => get_string('studentsignature', $p), 'class' => 'ec-tc'];
$heads[] = get_string('finaliseddate', $p);
$heads[] = '';
$dot = fn($t, $title = '') => $t ? html_writer::span('✓', 'ec-dot ec-dot-yes', ['title' => $title ?: userdate($t)]) :
    html_writer::span('', 'ec-dot ec-dot-no', ['aria-label' => get_string('no')]);
$body = '';
foreach ($rows as $r) {
    $person = html_writer::div(
        $OUTPUT->user_picture($r->user, ['size' => 36, 'link' => false, 'class' => 'ec-avatar']) .
        html_writer::div(
            html_writer::link(
                new moodle_url(
                    '/mod/evidencechecklist/view.php',
                    ['id' => $cm->id,
                    'userid' => $r->user->id]
                ),
                fullname($r->user)
            ) . html_writer::div(s($r->user->email), 'ec-person-sub')
        ),
        'ec-person'
    );
    $cells = html_writer::tag('td', $person);
    $cells .= html_writer::tag(
        'td',
        ui::progress_bar($r->progress, true) . html_writer::div(
            s(
                get_string(
                    'progresslabel',
                    $p,
                    (object)$r->progress
                )
            ),
            'ec-td-muted ec-nowrap'
        )
    );
    $cells .= html_writer::tag(
        'td',
        html_writer::span(
            get_string('status_' . $r->status, $p),
            'ec-pill ec-pill-sm ec-pill-' .
            $r->status
        )
    );
    foreach ($r->confirm as $t) {
        $cells .= html_writer::tag('td', $dot($t), ['class' => 'ec-tc']);
    }
    $cells .= html_writer::tag(
        'td',
        $dot($r->identity ? 1 : 0, $r->identity ? get_string('id_' . $r->identity, $p) : ''),
        ['class' => 'ec-tc']
    );
    $final = in_array($r->status, ['competent', 'nyc']);
    $cells .= html_writer::tag('td', $final ? $dot($r->studentsig) : html_writer::span('—', 'ec-res-empty'), ['class' => 'ec-tc']);
    $cells .= html_writer::tag(
        'td',
        !empty($r->attempt->timefinalised) ? userdate(
            $r->attempt->timefinalised,
            get_string('strftimedateshort', 'langconfig')
        ) : html_writer::span('—', 'ec-res-empty'),
        ['class' => 'ec-td-muted ec-nowrap']
    );
    $cells .= html_writer::tag(
        'td',
        $r->attempt ? html_writer::link(
            new moodle_url(
                '/mod/evidencechecklist/report.php',
                ['id' => $cm->id, 'userid' => $r->user->id]
            ),
            get_string('viewreport', $p),
            ['class' => 'ec-btn ec-btn-sm']
        ) : ''
    );
    $body .= html_writer::tag('tr', $cells);
}
$key = '';
foreach ($statements as $t) {
    $key .= html_writer::tag('li', format_string($t));
}
$legend = html_writer::div(
    html_writer::span(html_writer::span('✓', 'ec-dot ec-dot-yes') . get_string('legend_done', $p)) .
    html_writer::span(html_writer::span('', 'ec-dot ec-dot-no') . get_string('legend_missing', $p)),
    'ec-legend'
);
$footer = $legend . ($key ? html_writer::tag('ol', $key, ['class' => 'ec-statementkey']) : '');
echo \mod_evidencechecklist\local\report::dtcard(
    get_string('stat_students', $p),
    '',
    $heads,
    $body,
    'ec-registertable',
    false,
    $footer
);
echo html_writer::end_div();
echo $OUTPUT->footer();
