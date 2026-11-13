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
 * Mapping report: every unit requirement, the numbered criteria that cover it, and the assessing lock status.
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
$filter = optional_param('filter', 'all', PARAM_ALPHA);
$download = optional_param('download', '', PARAM_ALPHA);
$action = optional_param('action', '', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'evidencechecklist');
$record = $DB->get_record('evidencechecklist', ['id' => $cm->instance], '*', MUST_EXIST);
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
if (!has_any_capability(['mod/evidencechecklist:manage', 'mod/evidencechecklist:viewallreports'], $context)) {
    require_capability('mod/evidencechecklist:manage', $context);
}
$canmanage = has_capability('mod/evidencechecklist:manage', $context);
$p = 'mod_evidencechecklist';
$url = new moodle_url('/mod/evidencechecklist/mapping.php', ['id' => $cm->id]);

if ($action && $canmanage && confirm_sesskey()) {
    if ($action === 'approve') {
        $DB->update_record(
            'evidencechecklist',
            (object)['id' => $record->id, 'mappingapprovedby' => $USER->id,
            'mappingapprovedtime' => time()]
        );
        checklist::log($record->id, $USER->id, 'approve');
        redirect($url, get_string('mappingapproved', $p), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    if ($action === 'editrow') {
        $reqid = required_param('reqid', PARAM_INT);
        $numbers = optional_param('numbers', '', PARAM_TEXT);
        $other = trim(optional_param('othertool', '', PARAM_TEXT));
        $req = $DB->get_record('evidencechecklist_req', ['id' => $reqid, 'checklistid' => $record->id], '*', MUST_EXIST);
        $cl = new checklist($record);
        $bynumber = [];
        foreach ($cl->items as $it) {
            $bynumber[$it->number] = $it->id;
        }
        $DB->delete_records('evidencechecklist_reqmap', ['reqid' => $reqid]);
        $added = [];
        foreach (preg_split('/[,;\s]+/', $numbers) as $n) {
            if (isset($bynumber[$n]) && !in_array($n, $added)) {
                $DB->insert_record('evidencechecklist_reqmap', (object)['reqid' => $reqid, 'itemid' => $bynumber[$n]]);
                $added[] = $n;
            }
        }
        $DB->update_record('evidencechecklist_req', (object)['id' => $reqid, 'othertool' => $other !== '' ? $other : null]);
        $DB->update_record(
            'evidencechecklist',
            (object)['id' => $record->id, 'mappingapprovedby' => null,
            'mappingapprovedtime' => null]
        );
        checklist::log(
            $record->id,
            $USER->id,
            'mapedit',
            json_encode(
                ['code' => $req->code, 'numbers' => $added,
                'other' => $other]
            )
        );
        redirect(
            new moodle_url($url, ['filter' => $filter]),
            get_string('saved', $p),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

$record = $DB->get_record('evidencechecklist', ['id' => $cm->instance], '*', MUST_EXIST);
$cl = new checklist($record);
$m = $cl->mapping();

if ($download === 'csv') {
    $csv = new csv_export_writer();
    $csv->set_filename('mapping-' . clean_filename($record->unitcode ?: $record->name));
    $csv->add_data(
        [get_string('code', $p), get_string('requirement', $p), get_string('mappedto', $p),
        get_string('othertool', $p), get_string('status')]
    );
    foreach ($m['rows'] as $row) {
        $csv->add_data(
            [$row->req->code, $row->req->reqtext, implode(', ', array_map(fn($i) => $i->number, $row->items)),
            (string)$row->req->othertool, get_string('mapstatus_' . $row->status, $p)]
        );
    }
    $csv->download_file();
    exit;
}

$PAGE->set_url($url);
$PAGE->set_title(get_string('mappingreport', $p));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->add_body_class('ec-page');
$PAGE->activityheader->disable();
[$green, $magenta] = defaults::colours();

echo $OUTPUT->header();
echo html_writer::start_div('ec-root ec-mapping', ['style' => "--ec-green:$green;--ec-magenta:$magenta;"]);
$unit = trim($record->unitcode . ' ' . format_string((string)$record->unittitle));
echo html_writer::div(
    html_writer::div(
        html_writer::tag('h2', get_string('mappingreport', $p), ['class' => 'ec-title']) .
        html_writer::div(s($unit !== '' ? $unit : format_string($record->name)), 'ec-subtitle'),
        'ec-pagehead-text'
    ) .
    ui::mapping_pill($m['state'], $m['gaps']),
    'ec-pagehead'
);

// Big numbers.
$stats = [
    ['n' => $m['total'], 'l' => 'stat_requirements', 'c' => ''],
    ['n' => $m['mapped'], 'l' => 'stat_mapped', 'c' => ' ec-stat-good'],
    ['n' => $m['other'], 'l' => 'stat_other', 'c' => ''],
    ['n' => $m['gaps'], 'l' => 'stat_gaps', 'c' => $m['gaps'] ? ' ec-stat-gap' : ' ec-stat-good'],
    ['n' => $m['percent'] . '%', 'l' => 'stat_complete', 'c' => $m['state'] === 'complete' ? ' ec-stat-good' : ''],
];
$html = '';
foreach ($stats as $s) {
    $html .= html_writer::div(
        html_writer::div($s['n'], 'ec-stat-num') . html_writer::div(
            get_string($s['l'], $p),
            'ec-stat-label'
        ),
        'ec-card ec-stat' . $s['c']
    );
}
echo html_writer::div($html, 'ec-stats ec-stats-5');
$total = max(1, $m['total']);
echo html_writer::div(
    html_writer::span(
        '',
        'ec-bar-s',
        ['style' => 'width:' . round(
            ($m['mapped'] + $m['other']) / $total * 100,
            2
        ) . '%']
    ) . html_writer::span('', 'ec-bar-nys', ['style' => 'width:' . round($m['gaps'] / $total * 100, 2) . '%']),
    'ec-bar ec-bar-lg' . ($m['state'] === 'complete' ? ' ec-bar-done' : '')
);

// Lock and approval.
$lock = defaults::mapping_lock($record);
$info = html_writer::span(
    $m['state'] === 'complete' ? get_string('lockopen', $p) :
    ($lock ? get_string('lockon', $p) : get_string('lockoff', $p)),
    'ec-muted'
);
if ($record->mappingapprovedby) {
    $info .= ' · ' . html_writer::span(
        '✓ ' . get_string(
            'approvedby',
            $p,
            (object)[
            'name' => fullname(core_user::get_user($record->mappingapprovedby)),
            'date' => userdate($record->mappingapprovedtime, get_string('strftimedate', 'langconfig'))]
        ),
        'ec-good-text'
    );
}
$actions = html_writer::link(new moodle_url($url, ['download' => 'csv']), get_string('downloadcsv', $p), ['class' => 'ec-btn']);
if ($canmanage) {
    $actions .= html_writer::link(
        new moodle_url('/mod/evidencechecklist/edit.php', ['id' => $cm->id]),
        get_string('editchecklist', $p),
        ['class' => 'ec-btn']
    );
    if ($m['state'] === 'complete' && !$record->mappingapprovedby) {
        $actions .= html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false), 'class' => 'ec-inline']) .
            html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
            html_writer::tag(
                'button',
                get_string('approvemapping', $p),
                ['type' => 'submit', 'name' => 'action',
                'value' => 'approve', 'class' => 'ec-btn ec-btn-primary']
            ) . html_writer::end_tag('form');
    }
}
echo html_writer::div(html_writer::div($info, 'ec-toolbar-left') . html_writer::div($actions, 'ec-toolbar-right'), 'ec-toolbar');

if (!$m['total']) {
    echo html_writer::div(
        html_writer::div('', 'ec-empty-art') . html_writer::tag('p', get_string('nomapping', $p)),
        'ec-card ec-empty'
    );
    echo html_writer::end_div();
    echo $OUTPUT->footer();
    exit;
}

$tabs = '';
foreach (['all' => 'filter_all', 'gaps' => 'filter_gaps'] as $k => $label) {
    $tabs .= html_writer::link(
        new moodle_url($url, ['filter' => $k]),
        get_string($label, $p) .
        ($k === 'gaps' ? ' (' . $m['gaps'] . ')' : ''),
        ['class' => $filter === $k ? 'ec-on' : '']
    );
}
echo html_writer::div($tabs, 'ec-seg ec-seg-links');

$types = ['PC' => 'type_pc', 'E' => 'type_pc', 'PE' => 'type_pe', 'KE' => 'type_ke', 'AC' => 'type_ac', 'FS' => 'type_fs'];
$bytype = [];
foreach ($m['rows'] as $row) {
    if ($filter === 'gaps' && $row->status !== 'gap') {
        continue;
    }
    $bytype[$types[$row->type] ?? 'type_other'][] = $row;
}
$order = ['type_pc', 'type_pe', 'type_ke', 'type_ac', 'type_fs', 'type_other'];
$heads = [get_string('code', $p), get_string('requirement', $p), get_string('mappedto', $p), get_string('status')];
foreach ($order as $type) {
    if (empty($bytype[$type])) {
        continue;
    }
    $rows = '';
    foreach ($bytype[$type] as $row) {
        $chips = '';
        foreach ($row->items as $it) {
            $label = html_writer::span($it->number, 'ec-chip ec-chip-num', ['title' => format_string($it->criterion)]);
            $chips .= $canmanage ? html_writer::link(
                new moodle_url(
                    '/mod/evidencechecklist/itemedit.php',
                    ['id' => $cm->id,
                    'itemid' => $it->id]
                ),
                $label
            ) : $label;
        }
        if ($row->status === 'other') {
            $chips .= html_writer::span(s($row->req->othertool), 'ec-tag');
        }
        if ($row->status === 'gap') {
            $chips .= html_writer::span('—', 'ec-res-empty');
        }
        $edit = '';
        if ($canmanage) {
            $edit = html_writer::tag(
                'details',
                html_writer::tag('summary', get_string('edit')) .
                html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false), 'class' => 'ec-mapedit']) .
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'reqid', 'value' => $row->req->id]) .
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'filter', 'value' => $filter]) .
                html_writer::empty_tag(
                    'input',
                    ['type' => 'text', 'name' => 'numbers', 'class' => 'ec-input ec-input-sm',
                    'value' => implode(', ', array_map(fn($i) => $i->number, $row->items)),
                    'placeholder' => '1.1, 2.3', 'aria-label' => get_string('mappedto', $p)]
                ) .
                ui::help('mapnumbers') .
                html_writer::empty_tag(
                    'input',
                    ['type' => 'text', 'name' => 'othertool', 'class' => 'ec-input ec-input-sm',
                    'value' => (string)$row->req->othertool, 'placeholder' => get_string('othertool', $p),
                    'aria-label' => get_string('othertool', $p)]
                ) .
                ui::help('othertool') .
                html_writer::tag(
                    'button',
                    get_string('save', $p),
                    ['type' => 'submit', 'name' => 'action', 'value' => 'editrow',
                    'class' => 'ec-btn ec-btn-sm ec-btn-primary']
                ) . html_writer::end_tag('form'),
                ['class' => 'ec-rowedit']
            );
        }
        $statuschip = ['mapped' => 'ec-res ec-res-S', 'gap' => 'ec-res ec-res-NYS', 'other' => 'ec-res'][$row->status];
        $rows .= html_writer::tag(
            'tr',
            html_writer::tag('td', s($row->req->code), ['class' => 'ec-map-code']) .
            html_writer::tag('td', s($row->req->reqtext)) .
            html_writer::tag('td', html_writer::div($chips, 'ec-map-chips')) .
            html_writer::tag(
                'td',
                html_writer::span(get_string('mapstatus_' . $row->status, $p), $statuschip) . $edit,
                ['class' => 'ec-nowrap']
            ),
            ['class' => 'ec-row-' . $row->status]
        );
    }
    echo \mod_evidencechecklist\local\report::dtcard(
        get_string($type, $p) . ' · ' . count($bytype[$type]),
        '',
        $heads,
        $rows,
        'ec-maptable'
    );
}

// Reverse view.
$rows = '';
foreach ($cl->groups as $g) {
    foreach ($g->items as $it) {
        $codes = $m['coverage'][$it->id] ?? [];
        $rows .= html_writer::tag(
            'tr',
            html_writer::tag(
                'td',
                html_writer::div(
                    html_writer::span($it->number, 'ec-num') .
                    html_writer::div(format_string($it->criterion), 'ec-dt-crit-text'),
                    'ec-dt-crit'
                )
            ) .
            html_writer::tag(
                'td',
                $codes ? html_writer::div(
                    implode(
                        '',
                        array_map(
                            fn($c) => html_writer::span(s($c), 'ec-chip'),
                            $codes
                        )
                    ),
                    'ec-map-chips'
                ) : html_writer::span(get_string('unmappedcriterion', $p), 'ec-res ec-res-NYS')
            ),
            ['class' => $codes ? '' : 'ec-row-gap']
        );
    }
}
echo \mod_evidencechecklist\local\report::dtcard(
    get_string('reverseview', $p),
    '',
    [get_string('criterion', $p),
    get_string('covers', $p)],
    $rows,
    'ec-maptable'
);
echo html_writer::end_div();
echo $OUTPUT->footer();
