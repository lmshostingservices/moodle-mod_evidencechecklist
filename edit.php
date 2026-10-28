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
 * Build the checklist: fast paste (plain lists, Excel rows or the ChatGPT prompt output) with preview.
 *
 * @package    mod_evidencechecklist
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use mod_evidencechecklist\local\checklist;
use mod_evidencechecklist\local\defaults;
use mod_evidencechecklist\local\parser;
use mod_evidencechecklist\output\ui;

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$text = optional_param('pastetext', '', PARAM_TEXT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'evidencechecklist');
$record = $DB->get_record('evidencechecklist', ['id' => $cm->instance], '*', MUST_EXIST);
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/evidencechecklist:manage', $context);
\mod_evidencechecklist\local\entitlement::require_access();

$url = new moodle_url('/mod/evidencechecklist/edit.php', ['id' => $cm->id]);
if (optional_param('downloadprompt', 0, PARAM_BOOL)) {
    $name = clean_filename('evidence-checklist-prompt' . ($record->unitcode ? '-' . $record->unitcode : '') . '.txt');
    send_file(defaults::prompt($record), $name, 0, 0, true, true, 'text/plain');
}
$PAGE->set_url($url);
$PAGE->set_title(get_string('editchecklist', 'mod_evidencechecklist'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->add_body_class('ec-page');
$PAGE->activityheader->disable();

$p = 'mod_evidencechecklist';
$hasresults = checklist::has_results($record->id);
$parsed = null;
if ($action !== '' && confirm_sesskey()) {
    $parsed = parser::parse($text, (int)$record->defaultoccasions);
    if ($action === 'import' && !$parsed['errors'] && !$hasresults) {
        $cl = new checklist($record);
        $cl->replace_from_parsed($parsed, $USER->id);
        redirect(
            new moodle_url('/mod/evidencechecklist/mapping.php', ['id' => $cm->id]),
            get_string(
                'imported',
                $p,
                (object)['groups' => count($parsed['groups']),
                'items' => array_sum(array_map(fn($g) => count($g['items']), $parsed['groups']))]
            ),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}
// Columns card: rename, change how many times, add or remove (only when no results are in the column).
if (optional_param('savecolumns', 0, PARAM_BOOL) && confirm_sesskey()) {
    $cl = new checklist($record);
    $used = $cl->used_cells();
    $labels = optional_param_array('collabel', [], PARAM_TEXT);
    $times = optional_param_array('coltimes', [], PARAM_INT);
    $keys = optional_param_array('colkey', [], PARAM_RAW_TRIMMED); // pipeline-ignore: PARAM_RAW_TRIMMED — key compared only.
    $remove = optional_param_array('colremove', [], PARAM_BOOL);
    $defs = [];
    $blocked = [];
    foreach ($labels as $i => $label) {
        $existing = ($keys[$i] ?? '') !== '';
        $key = ($keys[$i] ?? '') === '_' ? '' : clean_param($keys[$i] ?? '', PARAM_ALPHANUMEXT);
        $label = trim($label);
        $t = max(1, min(10, (int)($times[$i] ?? 1)));
        $usedmax = 0;
        foreach (array_keys($used) as $cell) {
            [$ck, $cn] = explode('|', $cell);
            if ($ck === $key && $existing) {
                $usedmax = max($usedmax, (int)$cn);
            }
        }
        if (!empty($remove[$i]) || (!$existing && $label === '')) {
            if ($usedmax) {
                $blocked[] = $label;
                $defs[] = ['key' => $key, 'label' => $label, 'times' => $t, 'existing' => true];
            }
            continue;
        }
        $defs[] = ['key' => $key, 'label' => $label, 'times' => max($t, $usedmax), 'existing' => $existing];
    }
    $cl->save_columns($defs, $USER->id);
    redirect(
        $url,
        $blocked ? get_string('columnsinuse', $p, implode(', ', $blocked)) : get_string('columnssaved', $p),
        null,
        $blocked ? \core\output\notification::NOTIFY_WARNING : \core\output\notification::NOTIFY_SUCCESS
    );
}
$record = $DB->get_record('evidencechecklist', ['id' => $cm->instance], '*', MUST_EXIST);
$cl = new checklist($record);
[$green, $magenta] = defaults::colours();

echo $OUTPUT->header();
echo html_writer::start_div('ec-root ec-edit', ['style' => "--ec-green:$green;--ec-magenta:$magenta;"]);
echo html_writer::div(
    html_writer::div(
        html_writer::tag('h2', get_string('editchecklist', $p), ['class' => 'ec-title']) .
        html_writer::div(format_string($record->name), 'ec-subtitle'),
        'ec-pagehead-text'
    ) .
    html_writer::link(
        new moodle_url('/mod/evidencechecklist/mapping.php', ['id' => $cm->id]),
        ui::mapping_pill($cl->mapping_state(), $cl->mapping()['gaps']),
        ['class' => 'ec-pill-link']
    ),
    'ec-pagehead'
);

// Step cards: the ChatGPT prompt (outside Moodle) and fast paste.
$prompt = defaults::prompt($record);
$steps = html_writer::tag(
    'ol',
    html_writer::tag('li', get_string('promptstep1', $p)) .
    html_writer::tag(
        'li',
        trim($record->unitcode . $record->unittitle) !== '' ? get_string(
            'promptstep2_filled',
            $p,
            s(trim($record->unitcode . ' ' . $record->unittitle))
        ) : get_string('promptstep2', $p)
    ) .
    html_writer::tag('li', get_string('promptstep3', $p)),
    ['class' => 'ec-steps']
);
echo html_writer::div(
    html_writer::div(
        html_writer::tag('h3', get_string('promptcard', $p)) .
        html_writer::div(get_string('promptcard_desc', $p), 'ec-card-sub'),
        ''
    ) .
    $steps .
    html_writer::tag(
        'textarea',
        s($prompt),
        ['id' => 'ec-prompt', 'class' => 'ec-input ec-prompt', 'rows' => 6,
        'readonly' => 'readonly', 'aria-label' => get_string('promptcard', $p)]
    ) .
    html_writer::div(
        html_writer::tag(
            'button',
            get_string('copyprompt', $p),
            ['type' => 'button',
            'class' => 'ec-btn ec-btn-primary',
            'data-ec-copy' => '#ec-prompt']
        ) .
        html_writer::link(
            new moodle_url('/mod/evidencechecklist/edit.php', ['id' => $cm->id, 'downloadprompt' => 1]),
            get_string('downloadprompt', $p),
            ['class' => 'ec-btn']
        ) .
        ($record->unitcode && preg_match('/^[A-Z0-9]+$/i', $record->unitcode) ? html_writer::link(
            'https://training.gov.au/Training/Details/' . strtoupper($record->unitcode),
            get_string('opentga', $p, s(strtoupper($record->unitcode))),
            ['class' => 'ec-btn', 'target' => '_blank', 'rel' => 'noopener']
        ) : '') .
        html_writer::span(get_string('noaiinplugin', $p), 'ec-muted'),
        'ec-actions'
    ),
    'ec-card'
);

if ($hasresults) {
    echo html_writer::div(get_string('importlocked', $p), 'ec-card ec-callout ec-callout-gap');
}

// Columns.
if ($cl->items && !$parsed) {
    $used = $cl->used_cells();
    $rows = '';
    $defs = $cl->coldefs;
    $defs[] = ['key' => '', 'label' => '', 'times' => 1, 'new' => true];
    foreach ($defs as $i => $d) {
        $inuse = false;
        foreach (array_keys($used) as $cell) {
            $inuse = $inuse || explode('|', $cell)[0] === $d['key'];
        }
        $new = !empty($d['new']);
        $rows .= html_writer::tag(
            'tr',
            html_writer::tag(
                'td',
                html_writer::empty_tag(
                    'input',
                    ['type' => 'hidden', 'name' => "colkey[$i]",
                    'value' => $new ? '' : ($d['key'] === '' ? '_' : $d['key'])]
                ) .
                html_writer::empty_tag(
                    'input',
                    ['type' => 'text', 'name' => "collabel[$i]", 'value' => $d['label'],
                    'class' => 'ec-input', 'placeholder' => $new ? get_string('addcolumn', $p) : get_string('observedcol', $p),
                    'aria-label' => get_string('columnname', $p)]
                )
            ) .
            html_writer::tag(
                'td',
                html_writer::select(
                    array_combine(range(1, 10), range(1, 10)),
                    "coltimes[$i]",
                    $d['times'],
                    false,
                    ['class' => 'ec-input ec-timesselect', 'aria-label' => get_string('columntimes', $p)]
                )
            ) .
            html_writer::tag(
                'td',
                $new ? html_writer::span(get_string('newcolumn', $p), 'ec-muted') : ($inuse ?
                html_writer::span(get_string('columnhasresults', $p), 'ec-pill ec-pill-sm ec-pill-competent') :
                html_writer::tag(
                    'label',
                    html_writer::checkbox("colremove[$i]", 1, false, '') . ' ' . get_string('remove'),
                    ['class' => 'ec-removecol']
                )),
                ['class' => 'ec-nowrap']
            )
        );
    }
    $preview = '';
    foreach ($cl->columns() as $c) {
        $preview .= html_writer::span(s($c['label']), 'ec-chip ec-chip-num');
    }
    $table = html_writer::tag(
        'table',
        html_writer::tag(
            'thead',
            html_writer::tag(
                'tr',
                html_writer::tag(
                    'th',
                    get_string('columnname', $p) . ' ' . ui::help('collabel')
                ) . html_writer::tag('th', get_string('columntimes', $p) . ' ' . ui::help('coltimes')) . html_writer::tag('th', '')
            )
        ) .
        html_writer::tag('tbody', $rows),
        ['class' => 'ec-dt']
    );
    echo html_writer::tag(
        'form',
        html_writer::empty_tag(
            'input',
            ['type' => 'hidden', 'name' => 'sesskey',
            'value' => sesskey()]
        ) . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'savecolumns', 'value' => 1]) .
        html_writer::div(
            html_writer::tag('h3', get_string('columns', $p)) . html_writer::div(
                get_string('columns_desc', $p),
                'ec-dtcard-sub'
            ) . html_writer::div($preview, 'ec-map-chips ec-colpreview'),
            'ec-dtcard-head ec-colhead-card'
        ) .
        html_writer::div($table, 'ec-dtscroll') .
        html_writer::div(
            html_writer::tag(
                'button',
                get_string('savecolumns', $p),
                ['type' => 'submit',
                'class' => 'ec-btn ec-btn-primary']
            ),
            'ec-dtcard-foot'
        ),
        ['method' => 'post', 'action' => $url->out(false), 'class' => 'ec-card ec-dtcard ec-columnscard']
    );
}

$form = html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false)]);
$form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
$form .= html_writer::tag('h3', get_string('fastpaste', $p) . ' ' . ui::help('pastetext'));
$form .= html_writer::div(get_string('fastpaste_desc', $p), 'ec-card-sub');
$form .= html_writer::tag(
    'textarea',
    s($text),
    ['name' => 'pastetext', 'class' => 'ec-input ec-paste', 'rows' => 14,
    'placeholder' => get_string('fastpaste_placeholder', $p), 'aria-label' => get_string('fastpaste', $p)]
);
$buttons = html_writer::tag(
    'button',
    get_string('preview', $p),
    ['type' => 'submit', 'name' => 'action', 'value' => 'preview',
    'class' => 'ec-btn']
);
if ($parsed && !$parsed['errors'] && !$hasresults) {
    $buttons .= html_writer::tag(
        'button',
        get_string('importnow', $p),
        ['type' => 'submit', 'name' => 'action',
        'value' => 'import', 'class' => 'ec-btn ec-btn-primary']
    );
}
$form .= html_writer::div($buttons, 'ec-actions');
$form .= html_writer::end_tag('form');
echo html_writer::div($form, 'ec-card');

if ($parsed) {
    $itemcount = array_sum(array_map(fn($g) => count($g['items']), $parsed['groups']));
    $stats = '';
    foreach (
        [['n' => count($parsed['groups']), 'l' => 'stat_groups'], ['n' => $itemcount, 'l' => 'stat_items'],
            ['n' => count($parsed['mapping']), 'l' => 'stat_requirements'],
            ['n' => count($parsed['errors']), 'l' => 'stat_errors', 'c' => count($parsed['errors']) ? ' ec-stat-gap' : '']] as $s
    ) {
        $stats .= html_writer::div(
            html_writer::div($s['n'], 'ec-stat-num') . html_writer::div(
                get_string($s['l'], $p),
                'ec-stat-label'
            ),
            'ec-card ec-stat' . ($s['c'] ?? '')
        );
    }
    echo html_writer::div($stats, 'ec-stats');
    if ($parsed['mapping']) {
        $t = (object)$parsed['meta']['totals'];
        $ok = !$parsed['errors'] && !$t->gaps;
        echo html_writer::div(
            html_writer::tag('strong', get_string('mappingcheck', $p, $t)) . ' ' .
            ($parsed['meta']['repairs'] ? get_string('mappingrepaired', $p, $parsed['meta']['repairs']) :
            get_string('mappingclean', $p)),
            'ec-card ec-callout ' . ($ok ? 'ec-callout-good' : 'ec-callout-gap')
        );
    }
    foreach (['errors' => 'ec-callout-gap', 'warnings' => ''] as $kind => $cls) {
        if ($parsed[$kind]) {
            $list = html_writer::alist(
                array_map(
                    fn($e) => ($e['line'] ? get_string('lineno', $p, $e['line']) . ' ' : '') .
                    $e['msg'],
                    $parsed[$kind]
                )
            );
            echo html_writer::div(
                html_writer::tag('strong', get_string('preview_' . $kind, $p)) . $list,
                'ec-card ec-callout ' . $cls
            );
        }
    }
    $meta = $parsed['meta'];
    if ($meta['unitcode'] || $meta['unittitle']) {
        echo html_writer::div(
            html_writer::tag('strong', s(trim($meta['unitcode'] . ' ' . $meta['unittitle']))) .
            ($meta['setting'] ? html_writer::div(s($meta['setting']), 'ec-muted') : '') .
            ($meta['equipment'] ? html_writer::div(get_string('equipment', $p) . ': ' . s($meta['equipment']), 'ec-muted') : ''),
            'ec-card'
        );
    }
    foreach ($parsed['groups'] as $g) {
        $rows = '';
        foreach ($g['items'] as $it) {
            $tags = '';
            if ($it['verbal']) {
                $tags .= html_writer::span(get_string('verbal', $p), 'ec-tag ec-tag-verbal');
            }
            if (!$it['mandatory']) {
                $tags .= html_writer::span(get_string('optional', $p), 'ec-tag');
            }
            $ctx = $it['contexts'] ? implode(' · ', array_map('s', $it['contexts'])) : get_string('allcolumns', $p);
            $tags .= html_writer::span($ctx, 'ec-tag');
            foreach ($it['refs'] as $r) {
                $tags .= html_writer::span(s($r), 'ec-chip');
            }
            $rows .= html_writer::div(
                html_writer::span($it['number'], 'ec-num') .
                html_writer::div(
                    html_writer::div(s($it['criterion']), 'ec-crit-text') .
                    ($it['benchmark'] !== '' ? html_writer::div(s($it['benchmark']), 'ec-muted') : '') .
                    html_writer::div($tags, 'ec-crit-meta'),
                    'ec-crit-main'
                ),
                'ec-preview-row'
            );
        }
        echo html_writer::div(
            html_writer::div(
                html_writer::span($g['number'], 'ec-num ec-num-lg') .
                html_writer::tag('h3', s($g['name'])),
                'ec-group-head'
            ) . $rows,
            'ec-card ec-group'
        );
    }
}

// Current checklist.
if ($cl->groups && !$parsed) {
    echo html_writer::tag('h3', get_string('currentchecklist', $p), ['class' => 'ec-section-title']);
    foreach ($cl->groups as $g) {
        $rows = '';
        foreach ($g->items as $it) {
            $edit = html_writer::link(
                new moodle_url('/mod/evidencechecklist/itemedit.php', ['id' => $cm->id, 'itemid' => $it->id]),
                get_string('edit'),
                ['class' => 'ec-btn ec-btn-sm']
            );
            $tags = $it->verbal ? html_writer::span(get_string('verbal', $p), 'ec-tag ec-tag-verbal') : '';
            $tags .= !$it->mandatory ? html_writer::span(get_string('optional', $p), 'ec-tag') : '';
            $labels = [];
            foreach ($cl->columns() as $c) {
                if (in_array($c['id'], $it->cells, true)) {
                    $labels[] = s($c['label']);
                }
            }
            $tags .= html_writer::span(implode(' · ', $labels), 'ec-tag');
            foreach (array_filter(array_map('trim', explode(',', (string)$it->mappingrefs))) as $r) {
                $tags .= html_writer::span(s($r), 'ec-chip');
            }
            $rows .= html_writer::div(
                html_writer::span($it->number, 'ec-num') .
                html_writer::div(
                    html_writer::div(format_string($it->criterion), 'ec-crit-text') .
                    html_writer::div($tags, 'ec-crit-meta'),
                    'ec-crit-main'
                ) . $edit,
                'ec-preview-row'
            );
        }
        echo html_writer::div(
            html_writer::div(
                html_writer::span($g->number, 'ec-num ec-num-lg') .
                html_writer::tag('h3', format_string($g->name)),
                'ec-group-head'
            ) . $rows,
            'ec-card ec-group'
        );
    }
}
echo html_writer::end_div();
$PAGE->requires->js_call_amd('mod_evidencechecklist/copy', 'init');
echo $OUTPUT->footer();
