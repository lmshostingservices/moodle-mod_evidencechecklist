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

namespace mod_evidencechecklist\local;

use html_writer;

/**
 * Practical Evidence Report data, HTML view and PDF.
 *
 * @package    mod_evidencechecklist
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report {
    /** @var \cm_info */
    protected $cm;
    /** @var \context_module */
    protected $context;
    /** @var checklist */
    protected $cl;
    /** @var \stdClass */
    protected $attempt;
    /** @var bool */
    protected $assessorview;
    /** @var array */
    public $data;

    /**
     * Constructor.
     *
     * @param \cm_info $cm
     * @param \context_module $context
     * @param checklist $cl
     * @param \stdClass $attempt
     * @param bool $assessorview
     */
    public function __construct($cm, \context_module $context, checklist $cl, \stdClass $attempt, bool $assessorview) {
        $this->cm = $cm;
        $this->context = $context;
        $this->cl = $cl;
        $this->attempt = $attempt;
        $this->assessorview = $assessorview;
        $this->data = state::attempt($cm, $context, $cl, $attempt, $assessorview);
        $this->data['cells'] = (array)$this->data['cells'];
        $this->data['gaps'] = (array)$this->data['gaps'];
    }

    /**
     * Result label.
     *
     * @param string $v
     * @return string
     */
    protected static function label(string $v): string {
        return $v === '' ? '' : ($v === 'NO' ? get_string('notobserved_short', 'mod_evidencechecklist') : $v);
    }

    /**
     * Assessor names involved.
     *
     * @return string
     */
    protected function assessors(): string {
        global $DB;
        $ids = $DB->get_fieldset_select(
            'evidencechecklist_result',
            'DISTINCT assessorid',
            'attemptid = ? AND assessorid > 0',
            [$this->attempt->id]
        );
        if ($this->attempt->assessorid) {
            $ids[] = $this->attempt->assessorid;
        }
        $names = [];
        foreach (array_unique($ids) as $id) {
            $names[] = fullname(\core_user::get_user($id));
        }
        return implode(', ', $names);
    }

    /**
     * HTML report.
     *
     * @return string
     */
    public function html(): string {
        $p = 'mod_evidencechecklist';
        $d = $this->data;
        $rec = $this->cl->record;
        $cols = $this->cl->columns();
        $out = '';
        $datefmt = get_string('strftimedatetimeshort', 'langconfig');

        $decision = $d['attempt']['decision'];
        $status = $d['attempt']['status'] === 'finalised' ? ($decision === 'competent' ? 'competent' : 'nyc') : 'inprogress';
        $head = html_writer::div(
            html_writer::div(get_string('reporttitle', $p), 'ec-kicker') .
            html_writer::tag('h2', s($d['student']['name']), ['class' => 'ec-title']) .
            html_writer::div(s(trim($rec->unitcode . ' ' . $rec->unittitle)) . ' · ' . format_string($rec->name), 'ec-subtitle'),
            'ec-pagehead-text'
        );
        $head .= html_writer::span(
            $status === 'inprogress' ? get_string('inprogress', $p) :
            get_string('decision_' . $status, $p),
            'ec-pill ec-pill-' . $status . ' ec-pill-lg'
        );
        $out .= html_writer::div($head, 'ec-pagehead');

        // Summary stats.
        $pr = $d['progress'];
        $stats = [
            ['n' => $pr['s'] . html_writer::span('/' . $pr['required'], 'ec-stat-of'), 'l' => get_string('stat_observeds', $p),
                'c' => $pr['complete'] ? ' ec-stat-good' : ''],
            ['n' => $pr['nys'], 'l' => get_string('stat_gaps', $p), 'c' => $pr['nys'] ? ' ec-stat-gap' : ''],
            ['n' => $d['attempt']['no'], 'l' => get_string('attempt', $p), 'c' => ''],
            ['n' => $d['attempt']['timefinalised'] ? userdate(
                $d['attempt']['timefinalised'],
                get_string('strftimedateshort', 'langconfig')
            ) : '—', 'l' => get_string('finaliseddate', $p), 'c' => ' ec-stat-text'],
        ];
        $shtml = '';
        foreach ($stats as $st) {
            $shtml .= html_writer::div(
                html_writer::div($st['n'], 'ec-stat-num') . html_writer::div($st['l'], 'ec-stat-label'),
                'ec-card ec-stat' . $st['c']
            );
        }
        $out .= html_writer::div($shtml, 'ec-stats');
        $out .= \mod_evidencechecklist\output\ui::progress_bar($pr);
        $out .= html_writer::div(
            get_string('assessors', $p) . ': ' . html_writer::tag('strong', s($this->assessors())),
            'ec-muted ec-assessorline'
        );

        // Pre-assessment.
        if ($d['confirm']['statements']) {
            $rows = '';
            foreach ($d['confirm']['statements'] as $c) {
                $resp = $c['response'] === 1 ? html_writer::span(get_string('yes'), 'ec-res ec-res-S') :
                    ($c['response'] === 0 ? html_writer::span(get_string('no'), 'ec-res ec-res-NYS') :
                    html_writer::span('—', 'ec-res-empty'));
                $rows .= html_writer::tag(
                    'tr',
                    html_writer::tag(
                        'td',
                        html_writer::div($c['text'], 'ec-dt-crit-text') .
                        ($c['note'] !== '' ? html_writer::div(s($c['note']), 'ec-td-muted') : '')
                    ) .
                    html_writer::tag('td', $resp) .
                    html_writer::tag(
                        'td',
                        $c['time'] ? userdate($c['time'], $datefmt) : '—',
                        ['class' => 'ec-td-muted ec-nowrap']
                    ) .
                    html_writer::tag(
                        'td',
                        $c['method'] ? get_string('method_' . $c['method'], $p) : '—',
                        ['class' => 'ec-td-muted ec-nowrap']
                    )
                );
            }
            $out .= self::dtcard(
                get_string('preassessment', $p),
                '',
                [get_string('statement', $p), get_string('response', $p),
                get_string('datetime', $p), get_string('method', $p)],
                $rows
            );
        }

        // Sessions.
        if ($d['sessions']) {
            $rows = '';
            foreach ($d['sessions'] as $s) {
                $badge = $s['idmethod'] === 'verifyid' ? get_string('verifyidmatch', $p) :
                    ($s['idmethod'] === 'sighted' ? get_string('idsighted', $p) . ($s['iddocument'] ? ' · ' . s($s['iddocument']) :
                        '') : get_string('idnone', $p));
                $photo = $s['photo'] ? html_writer::empty_tag('img', ['src' => $s['photo'], 'alt' => '']) :
                    html_writer::div('', 'ec-photo-empty');
                $rows .= html_writer::tag(
                    'tr',
                    html_writer::tag(
                        'td',
                        html_writer::div(
                            $photo . html_writer::span(
                                ($s['idmethod'] ? '✓ ' : '') . $badge,
                                'ec-idbadge' . ($s['idmethod'] ? ' ec-idbadge-ok' : '')
                            ),
                            'ec-photo-frame ec-photo-sm'
                        ),
                        ['style' => 'width:150px']
                    ) .
                    html_writer::tag(
                        'td',
                        html_writer::tag(
                            'dl',
                            html_writer::tag('dt', get_string('datetime', $p)) . html_writer::tag('dd', userdate($s['time'])) .
                            html_writer::tag('dt', get_string('assessors', $p)) . html_writer::tag('dd', s($s['assessor'])) .
                            html_writer::tag('dt', get_string('settingfield', $p)) . html_writer::tag(
                                'dd',
                                get_string($s['simulated'] ? 'simulated' : 'workplace', $p) .
                                ($s['setting'] ? ' · ' . s($s['setting']) :
                                '')
                            ) .
                            ($s['equipment'] ? html_writer::tag('dt', get_string('equipmentfield', $p)) .
                            html_writer::tag('dd', s($s['equipment'])) : '') .
                            ($s['adjustments'] ? html_writer::tag('dt', get_string('adjustmentsfield', $p)) .
                            html_writer::tag('dd', s($s['adjustments'])) : '') .
                            html_writer::tag('dt', get_string('identity', $p)) . html_writer::tag('dd', $badge),
                            ['class' => 'ec-kv']
                        )
                    )
                );
            }
            $out .= self::dtcard(get_string('sessions', $p), '', [], $rows, 'ec-sessiontable');
        }

        // Criteria by group.
        foreach ($this->cl->groups as $g) {
            $used = [];
            foreach ($g->items as $it) {
                foreach (checklist::item_cells($it) as $c) {
                    $used[$c] = true;
                }
            }
            $gcols = array_values(array_filter($cols, fn($c) => isset($used[$c['id']])));
            $heads = [get_string('criterion', $p)];
            foreach ($gcols as $c) {
                $heads[] = ['text' => s($c['label']), 'class' => 'ec-tc'];
            }
            $rows = '';
            foreach ($g->items as $it) {
                $refs = '';
                foreach (array_filter(array_map('trim', explode(',', (string)$it->mappingrefs))) as $r) {
                    $refs .= html_writer::span(s($r), 'ec-chip');
                }
                $cells = html_writer::tag(
                    'td',
                    html_writer::div(
                        html_writer::span($it->number, 'ec-num') .
                        html_writer::div(
                            html_writer::div(format_string($it->criterion), 'ec-dt-crit-text') .
                            ($refs ? html_writer::div($refs, 'ec-dt-refs') : ''),
                            ''
                        ),
                        'ec-dt-crit'
                    )
                );
                $notes = '';
                $itemcells = checklist::item_cells($it);
                foreach ($gcols as $c) {
                    if (!in_array($c['id'], $itemcells)) {
                        $cells .= html_writer::tag('td', '', ['class' => 'ec-tc ec-na']);
                        continue;
                    }
                    $cell = $d['cells'][$it->id . '#' . $c['id']] ?? null;
                    $v = $cell['r'] ?? '';
                    $cells .= html_writer::tag(
                        'td',
                        $v !== '' ? html_writer::span(self::label($v), 'ec-res ec-res-' . $v) .
                        html_writer::span(userdate($cell['time'], get_string('strftimedateshort', 'langconfig')), 'ec-res-date') :
                        html_writer::span('—', 'ec-res-empty'),
                        ['class' => 'ec-tc']
                    );
                    if ($cell && ($cell['c'] !== '' || $cell['media'] || $cell['timer'] !== null)) {
                        $notes .= $this->note_html($c['label'], $cell);
                    }
                }
                $rows .= html_writer::tag('tr', $cells);
                $gap = $d['gaps'][$it->id] ?? null;
                if ($gap && ($gap['observed'] !== '' || $gap['strategy'] !== '')) {
                    $dl = ($gap['observed'] !== '' ? html_writer::tag('dt', get_string('observed', $p)) .
                        html_writer::tag('dd', s($gap['observed'])) : '') .
                        ($gap['strategy'] !== '' ? html_writer::tag('dt', get_string('strategy', $p)) .
                        html_writer::tag('dd', s($gap['strategy'])) : '') .
                        ($gap['reobservedate'] ? html_writer::tag('dt', get_string('reobservedate', $p)) .
                        html_writer::tag('dd', userdate($gap['reobservedate'], get_string('strftimedate', 'langconfig'))) : '');
                    $notes .= html_writer::div(
                        html_writer::div(
                            get_string('gapplan', $p) . ($gap['status'] === 'closed' ? ' · ' . self::gap_closed_text($gap) : ''),
                            'ec-note-head'
                        ) . html_writer::tag('dl', $dl),
                        'ec-note ec-note-gap' . ($gap['status'] === 'closed' ? ' ec-closed' : '')
                    );
                }
                if ($notes !== '') {
                    $rows .= html_writer::tag(
                        'tr',
                        html_writer::tag(
                            'td',
                            html_writer::div($notes, 'ec-dt-notes'),
                            ['colspan' => count($gcols) + 1]
                        ),
                        ['class' => 'ec-dt-detail']
                    );
                }
            }
            $title = html_writer::span($g->number, 'ec-num ec-num-lg') . ' ' . format_string($g->name);
            $out .= self::dtcard($title, '', $heads, $rows, 'ec-gridtable', true);
        }

        // Outcome.
        $outcome = '';
        if ($d['attempt']['feedback'] !== '') {
            $outcome .= html_writer::div(
                html_writer::div(get_string('feedback', $p), 'ec-note-head') .
                html_writer::div(nl2br(s($d['attempt']['feedback'])), 'ec-note-text'),
                'ec-note'
            );
        }
        if ($d['attempt']['status'] === 'finalised') {
            $outcome .= html_writer::div(get_string('declarationdone', $p), 'ec-muted ec-declared');
        }
        $sigs = '';
        foreach ($d['signatures'] as $sg) {
            $sigs .= html_writer::div(
                html_writer::div(
                    get_string(
                        $sg['role'] === 'student' ? 'studentsignature' :
                        'assessorsignature',
                        $p
                    ),
                    'ec-sig-role'
                ) . ($sg['url'] ? html_writer::empty_tag(
                    'img',
                    ['src' => $sg['url'],
                    'alt' => '']
                ) : html_writer::div(s($sg['typed']), 'ec-sig-typed')) .
                html_writer::div(s($sg['name']) . ' · ' . userdate($sg['time']), 'ec-sig-meta'),
                'ec-sig'
            );
        }
        $outcome .= html_writer::div($sigs, 'ec-sigs');
        if ($this->attempt->recordhash) {
            $outcome .= html_writer::div(
                get_string('recordhash', $p) . ': ' . html_writer::tag('code', $this->attempt->recordhash),
                'ec-small ec-muted ec-hash'
            );
        }
        $out .= html_writer::div(html_writer::tag('h3', get_string('outcome', $p)) . $outcome, 'ec-card ec-outcome');
        $out .= html_writer::div(get_string('retentionnote', $p), 'ec-small ec-muted ec-footnote');
        return $out;
    }

    /**
     * Note card for one cell (comment, timer, media).
     *
     * @param string $label
     * @param array $cell
     * @return string
     */
    protected function note_html(string $label, array $cell): string {
        $p = 'mod_evidencechecklist';
        $media = '';
        foreach ($cell['media'] as $mm) {
            $before = $media;
            $media = '';
            if ($mm['type'] === 'image') {
                $media .= html_writer::link(
                    $mm['url'],
                    html_writer::empty_tag(
                        'img',
                        ['src' => $mm['url'], 'alt' => s($mm['name']),
                        'loading' => 'lazy']
                    ),
                    ['target' => '_blank', 'class' => 'ec-media']
                );
            } else if ($mm['type'] === 'video') {
                $media .= html_writer::tag(
                    'figure',
                    html_writer::tag(
                        'video',
                        '',
                        ['src' => $mm['url'], 'controls' => 'controls',
                        'preload' => 'metadata', 'poster' => $mm['poster'] ?: null]
                    ),
                    ['class' => 'ec-media ec-media-video']
                );
            } else if ($mm['type'] === 'audio') {
                $media .= html_writer::tag(
                    'audio',
                    '',
                    ['src' => $mm['url'], 'controls' => 'controls', 'preload' => 'metadata',
                    'class' => 'ec-media-audio']
                );
            }
            if (!empty($mm['linked']) && $media !== '') {
                $media = html_writer::div(
                    html_writer::span(get_string('linkedfrom', $p, $mm['fromno']), 'ec-linktag') . $media,
                    'ec-media-linkwrap'
                );
            }
            $media = $before . $media;
        }
        $head = html_writer::span(s($label), 'ec-chip') . ($cell['timer'] !== null ? html_writer::span(
            get_string('timer', $p) .
            ' ' . sprintf('%d:%02d', intdiv($cell['timer'], 60), $cell['timer'] % 60),
            'ec-tag'
        ) : '');
        return html_writer::div(
            html_writer::div($head, 'ec-note-head') .
            ($cell['c'] !== '' ? html_writer::div(s($cell['c']), 'ec-note-text') : '') .
            ($media ? html_writer::div($media, 'ec-medialist') : ''),
            'ec-note'
        );
    }

    /**
     * A premium table inside a card.
     *
     * @param string $title HTML
     * @param string $sub
     * @param array $heads strings or ['text','class']
     * @param string $rows tbody HTML
     * @param string $class
     * @param bool $titlehtml
     * @param string $footer HTML after the table
     * @return string
     */
    public static function dtcard(
        string $title,
        string $sub,
        array $heads,
        string $rows,
        string $class = '',
        bool $titlehtml = false,
        string $footer = ''
    ): string {
        $th = '';
        foreach ($heads as $h) {
            $th .= is_array($h) ? html_writer::tag('th', $h['text'], ['class' => $h['class']]) : html_writer::tag('th', $h);
        }
        $table = html_writer::tag(
            'table',
            ($th ? html_writer::tag('thead', html_writer::tag('tr', $th)) : '') .
            html_writer::tag('tbody', $rows),
            ['class' => 'ec-dt']
        );
        $head = html_writer::tag('h3', $titlehtml ? $title : s($title)) . ($sub ? html_writer::div($sub, 'ec-dtcard-sub') : '');
        return html_writer::div(
            html_writer::div($head, 'ec-dtcard-head') . html_writer::div($table, 'ec-dtscroll') . $footer,
            'ec-card ec-dtcard ' . $class
        );
    }
    /**
     * Send the PDF: a hand-drawn layout with embedded Plus Jakarta Sans.
     *
     * @param string $dest D to download, S to return as a string
     * @return string|null
     */
    public function send_pdf(string $dest = 'D'): ?string {
        $p = 'mod_evidencechecklist';
        $d = $this->data;
        $rec = $this->cl->record;
        [$green, $magenta] = defaults::colours();
        $pdf = new pdf_report('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->setup_style($green, $magenta);
        $pdf->SetTitle(get_string('reporttitle', $p) . ' - ' . $d['student']['name']);
        $pdf->SetAuthor('Evidence Checklist');
        $unit = trim($rec->unitcode . ' ' . $rec->unittitle);
        $course = get_course($rec->course);
        $pdf->headerleft = $d['student']['name'] . '   ·   ' . ($unit ?: format_string($rec->name));
        $pdf->footerleft = get_string('reporttitle', $p) . '  ·  ' . format_string($course->shortname);
        $pdf->AddPage();
        $this->pdf_cover($pdf, $unit, $course);

        if ($d['confirm']['statements']) {
            $pdf->section(get_string('preassessment', $p));
            $this->pdf_confirm($pdf);
        }
        if ($d['sessions']) {
            $pdf->section(get_string('sessions', $p), '', '', 56);
            $this->pdf_sessions($pdf);
        }
        $cols = $this->cl->columns();
        foreach ($this->cl->groups as $g) {
            $this->pdf_group($pdf, $g, $cols);
        }
        $pdf->section(get_string('outcome', $p), '', '', $d['signatures'] ? 90 : 28);
        $this->pdf_outcome($pdf);

        $name = clean_filename('evidence-report-' . ($rec->unitcode ?: 'checklist') . '-' . $d['student']['name'] . '.pdf');
        if ($dest === 'S') {
            return $pdf->Output($name, 'S');
        }
        $pdf->Output($name, 'D');
        return null;
    }

    /**
     * First-page header: name, unit, decision, stat cards, progress and facts.
     *
     * @param pdf_report $pdf
     * @param string $unit
     * @param \stdClass $course
     */
    protected function pdf_cover(pdf_report $pdf, string $unit, \stdClass $course): void {
        $p = 'mod_evidencechecklist';
        $d = $this->data;
        $ml = pdf_report::ML;
        $cw = pdf_report::CW;
        $pr = $d['progress'];
        $decision = $d['attempt']['decision'];
        $final = $d['attempt']['status'] === 'finalised';
        $status = $final ? get_string('decision_' . $decision, $p) : get_string('inprogress', $p);
        $kind = !$final ? 'plain' : ($decision === 'competent' ? 'S' : 'NYS');

        // Brand line.
        $pdf->colours('green', null);
        $pdf->RoundedRect($ml, 16.2, 3.2, 3.2, 0.9, '1111', 'F');
        $pdf->f('b', 8.5, 'ink2');
        $pdf->SetXY($ml + 5, 15.2);
        $pdf->Cell(100, 5, get_string('reporttitle', $p), 0, 0, 'L', false, '', 0, false, 'T', 'M');
        $pdf->f('m', 8, 'muted');
        $pdf->SetXY($ml, 15.2);
        $pdf->Cell(
            $cw,
            5,
            userdate(time(), get_string('strftimedatefullshort', 'langconfig')),
            0,
            0,
            'R',
            false,
            '',
            0,
            false,
            'T',
            'M'
        );
        $pdf->colours(null, 'border', 0.25);
        $pdf->Line($ml, 23, $ml + $cw, 23);

        // Name and decision.
        $pdf->f('b', 11);
        $pillw = $pdf->GetStringWidth($status) + 11;
        $y = $pdf->txt($ml, 30, $cw - $pillw - 8, $d['student']['name'], 'x', 26);
        $pdf->pill($ml + $cw - $pillw, 33, $status, $kind, 11);
        $y = $pdf->txt($ml, $y + 1, $cw - $pillw - 8, $unit, 's', 11.5, 'ink2');
        $y = $pdf->txt($ml, $y + 0.5, $cw, format_string($this->cl->record->name), 'r', 9.5, 'muted');

        // Stat cards.
        $y += 8;
        $gap = 4;
        $w = ($cw - 3 * $gap) / 4;
        $h = 27;
        $date = $d['attempt']['timefinalised'] ? userdate(
            $d['attempt']['timefinalised'],
            get_string('strftimedatefullshort', 'langconfig')
        ) : '—';
        $stats = [
            [get_string('stat_observeds', $p), (string)$pr['s'], '/' . $pr['required'], $pr['complete'] ? 'S' : ''],
            [get_string('stat_gaps', $p), (string)$pr['nys'], '', $pr['nys'] ? 'NYS' : ''],
            [get_string('attempt', $p), (string)$d['attempt']['no'], '', ''],
            [get_string('finaliseddate', $p), $date, '', ''],
        ];
        foreach ($stats as $i => [$label, $num, $of, $tone]) {
            $x = $ml + $i * ($w + $gap);
            $pdf->colours(
                $tone === 'S' ? 'greentint' : ($tone === 'NYS' ? 'magentatint' : 'white'),
                $tone === 'S' ? 'green' : ($tone === 'NYS' ? 'magenta' : 'border'),
                0.3
            );
            $pdf->RoundedRect($x, $y, $w, $h, 3.5, '1111', 'DF');
            $pdf->f('s', 8, $tone === 'S' ? 'greenink' : ($tone === 'NYS' ? 'magenta' : 'muted'));
            $pdf->SetXY($x + 5, $y + 4.5);
            $pdf->Cell($w - 10, 4, $label, 0, 0, 'L', false, '', 1);
            $small = $i === 3;
            $pdf->f('x', $small ? 13 : 24, $tone === 'S' ? 'greenink' : ($tone === 'NYS' ? 'magenta' : 'ink'));
            $pdf->SetXY($x + 5, $y + ($small ? 14 : 11.5));
            $nw = $pdf->GetStringWidth($num);
            $pdf->Cell($nw, $small ? 7 : 11, $num, 0, 0, 'L', false, '', 0, false, 'T', 'B');
            if ($of !== '') {
                $pdf->f('b', 12, 'muted');
                $pdf->SetXY($x + 5 + $nw + 0.6, $y + 11.5);
                $pdf->Cell(20, 10.2, $of, 0, 0, 'L', false, '', 0, false, 'T', 'B');
            }
        }
        $y += $h + 7;

        // Progress.
        $req = max(1, $pr['required']);
        $pdf->colours('border', null);
        $pdf->RoundedRect($ml, $y, $cw, 3.2, 1.6, '1111', 'F');
        $sw = $cw * $pr['s'] / $req;
        $nw = $cw * $pr['nys'] / $req;
        if ($nw > 0) {
            $pdf->colours('magenta', null);
            $pdf->RoundedRect($ml, $y, min($cw, $sw + $nw), 3.2, 1.6, '1111', 'F');
        }
        if ($sw > 0) {
            $pdf->colours('green', null);
            $pdf->RoundedRect($ml, $y, $sw, 3.2, 1.6, '1111', 'F');
        }
        $pdf->f('m', 8.5, 'ink2');
        $pdf->SetXY($ml, $y + 5);
        $pdf->Cell($cw / 2, 5, get_string('progresslabel', $p, (object)$pr), 0, 0, 'L');
        $pdf->f('b', 8.5, 'ink');
        $pdf->Cell($cw / 2, 5, round(100 * $pr['s'] / $req) . '%', 0, 0, 'R');
        $y += 15;

        // Facts card.
        $facts = [
            [get_string('course'), format_string($course->fullname)],
            [get_string('assessors', $p), $this->assessors() ?: '—'],
            [get_string('pdf_student', $p), $d['student']['name']],
        ];
        $fw = ($cw - 12) / 3;
        $fh = 0;
        foreach ($facts as [$k, $v]) {
            $fh = max($fh, $pdf->th($fw - 4, $v, 's', 9.5));
        }
        $pdf->card($y, $fh + 15);
        foreach ($facts as $i => [$k, $v]) {
            $x = $ml + 6 + $i * ($fw + 0);
            $pdf->txt($x, $y + 5.5, $fw - 4, $k, 'm', 7.5, 'muted');
            $pdf->txt($x, $y + 10, $fw - 4, $v, 's', 9.5, 'ink');
        }
        $pdf->cy = $y + $fh + 15 + 4;
    }

    /**
     * Pre-assessment confirmations.
     *
     * @param pdf_report $pdf
     */
    protected function pdf_confirm(pdf_report $pdf): void {
        $p = 'mod_evidencechecklist';
        $ml = pdf_report::ML;
        $cw = pdf_report::CW;
        $textw = $cw - 74;
        $fmt = get_string('strftimedatetimeshort', 'langconfig');
        $pdf->box_start();
        $head = function () use ($pdf, $p, $ml, $textw) {
            $y = $pdf->box_row(10, false);
            $pdf->box_headfill($y, 10);
            $pdf->f('s', 7.5, 'muted');
            foreach (
                [[$ml + 6, get_string('statement', $p)], [$ml + $textw + 12, get_string('response', $p)],
                    [$ml + $textw + 34, get_string('datetime', $p)]] as [$x, $t]
            ) {
                $pdf->SetXY($x, $y);
                $pdf->Cell(40, 10, $t, 0, 0, 'L', false, '', 0, false, 'T', 'M');
            }
        };
        $head();
        foreach ($this->data['confirm']['statements'] as $c) {
            $text = trim(strip_tags($c['text']));
            $h = $pdf->th($textw, $text, 's', 9);
            if ($c['note'] !== '') {
                $h += $pdf->th($textw, $c['note'], 'r', 8) + 1;
            }
            $rowh = max($h, 9) + 9;
            $y = $pdf->box_row($rowh, true, $head);
            $ty = $pdf->txt($ml + 6, $y + 4.5, $textw, $text, 's', 9);
            if ($c['note'] !== '') {
                $pdf->txt($ml + 6, $ty + 1, $textw, $c['note'], 'r', 8, 'ink2');
            }
            if ($c['response'] === 1 || $c['response'] === 0) {
                $pdf->pill(
                    $ml + $textw + 12,
                    $y + 4.5,
                    $c['response'] === 1 ? get_string('yes') : get_string('no'),
                    $c['response'] === 1 ? 'S' : 'NYS',
                    8
                );
            } else {
                $pdf->txt($ml + $textw + 12, $y + 4.5, 10, '—', 'm', 9, 'faint');
            }
            $pdf->txt($ml + $textw + 34, $y + 4.5, 38, $c['time'] ? userdate($c['time'], $fmt) : '—', 'm', 8, 'ink2');
            if ($c['method']) {
                $pdf->txt($ml + $textw + 34, $y + 8.6, 38, get_string('method_' . $c['method'], $p), 'r', 7.5, 'muted');
            }
        }
        $pdf->box_end();
    }

    /**
     * Assessment sessions with candidate photo and identity.
     *
     * @param pdf_report $pdf
     */
    protected function pdf_sessions(pdf_report $pdf): void {
        $p = 'mod_evidencechecklist';
        $ml = pdf_report::ML;
        $cw = pdf_report::CW;
        $fs = get_file_storage();
        $pdf->box_start();
        foreach ($this->data['sessions'] as $s) {
            $id = $s['idmethod'] === 'verifyid' ? get_string('verifyidmatch', $p) : ($s['idmethod'] === 'sighted' ?
                get_string('idsighted', $p) . ($s['iddocument'] ? ' · ' . $s['iddocument'] : '') : get_string('idnone', $p));
            $kv = [
                [get_string('datetime', $p), userdate($s['time'], get_string('strftimedatetime', 'langconfig'))],
                [get_string('assessors', $p), $s['assessor']],
                [get_string('settingfield', $p), get_string($s['simulated'] ? 'simulated' : 'workplace', $p) .
                    ($s['setting'] ? ' · ' . $s['setting'] : '')],
                [get_string('identity', $p), $id],
            ];
            if ($s['equipment']) {
                $kv[] = [get_string('equipmentfield', $p), $s['equipment']];
            }
            if ($s['adjustments']) {
                $kv[] = [get_string('adjustmentsfield', $p), $s['adjustments']];
            }
            $gx = $ml + 50;
            $colw = ($cw - 56) / 2 - 3;
            $rows = array_chunk($kv, 2);
            $rh = [];
            foreach ($rows as $r) {
                $hh = 0;
                foreach ($r as [$k, $v]) {
                    $hh = max($hh, $pdf->th($colw, $v, 's', 9.5));
                }
                $rh[] = $hh + 9;
            }
            $h = max(52, array_sum($rh) + 10);
            $y = $pdf->box_row($h);
            // Photo.
            $photo = $fs->get_area_files($this->context->id, $p, 'sessionphoto', $s['id'], 'id', false);
            $img = $photo ? $pdf->prep_image(reset($photo), 900) : null;
            $px = $ml + 6;
            $py = $y + 6;
            if ($img) {
                $pdf->cover($img, $px, $py, 36, 40, 3);
            } else {
                $pdf->colours('subtle', 'border', 0.3);
                $pdf->RoundedRect($px, $py, 36, 40, 3, '1111', 'DF');
                $pdf->txt($px, $py + 17, 36, get_string('nophoto', $p), 'm', 7.5, 'muted', 'C');
            }
            if ($s['idmethod']) {
                $pdf->colours('white', null);
                $pdf->Circle($px + 36 - 2, $py + 40 - 2, 4.4, 0, 360, 'F');
                $pdf->colours('green', null);
                $pdf->Circle($px + 36 - 2, $py + 40 - 2, 3.6, 0, 360, 'F');
                $pdf->icon('S', $px + 36 - 2, $py + 40 - 2, 3.4, 'white');
            }
            // Key values.
            $ky = $y + 6;
            foreach ($rows as $ri => $r) {
                foreach ($r as $ci => [$k, $v]) {
                    $x = $gx + $ci * ($colw + 6);
                    $pdf->txt($x, $ky, $colw, $k, 'm', 7.5, 'muted');
                    $pdf->txt(
                        $x,
                        $ky + 4.2,
                        $colw,
                        $v,
                        's',
                        9.5,
                        $k === get_string('identity', $p) && $s['idmethod'] ?
                        'greenink' : 'ink'
                    );
                }
                $ky += $rh[$ri];
            }
        }
        $pdf->box_end();
    }

    /**
     * One criteria group: header, column labels, rows, notes and media.
     *
     * @param pdf_report $pdf
     * @param \stdClass $g
     * @param array $cols
     */
    protected function pdf_group(pdf_report $pdf, \stdClass $g, array $cols): void {
        $p = 'mod_evidencechecklist';
        $d = $this->data;
        $ml = pdf_report::ML;
        $cw = pdf_report::CW;
        $used = [];
        $req = 0;
        $done = 0;
        foreach ($g->items as $it) {
            foreach (checklist::item_cells($it) as $c) {
                $used[$c] = true;
                if ($it->mandatory) {
                    $req++;
                    $done += (($d['cells'][$it->id . '#' . $c]['r'] ?? '') === 'S') ? 1 : 0;
                }
            }
        }
        $gcols = array_values(array_filter($cols, fn($c) => isset($used[$c['id']])));
        $colw = count($gcols) > 4 ? 16.5 : 20;
        $colsx = $ml + $cw - 4 - $colw * count($gcols);
        $textx = $ml + 19;
        $textw = $colsx - $textx - 4;
        $dfmt = get_string('strftimedatefullshort', 'langconfig');

        // Keep short groups whole; long groups start with at least their header and two rows.
        $total = 13;
        $first = 13;
        foreach (array_values($g->items) as $i => $it) {
            $refs = trim((string)$it->mappingrefs);
            $rh = max($pdf->th($textw, format_string($it->criterion), 's', 9.5) + ($refs !== '' ? 4.4 : 0), 10.5) + 8.5 +
                $this->pdf_item_extra($pdf, $it, $gcols, $cw - ($textx - $ml) - 6);
            $total += $rh;
            if ($i < 2) {
                $first += $rh;
            }
        }
        $pdf->section(
            format_string($g->name),
            (string)$g->number,
            get_string(
                'pdf_groupcount',
                $p,
                (object)['s' => $done, 'required' => $req]
            ),
            $total < 150 ? $total : min($first, 120)
        );
        $pdf->box_start();
        $head = function () use ($pdf, $p, $ml, $gcols, $colsx, $colw) {
            $y = $pdf->box_row(13, false);
            $pdf->box_headfill($y, 13);
            $pdf->f('s', 7.5, 'muted');
            $pdf->SetXY($ml + 6, $y);
            $pdf->Cell(60, 13, get_string('criterion', $p), 0, 0, 'L', false, '', 0, false, 'T', 'M');
            foreach ($gcols as $i => $c) {
                $x = $colsx + $i * $colw;
                if ($c['context'] !== '' && ($c['times'] ?? 1) > 1) {
                    $pdf->txt($x, $y + 2.6, $colw, $c['context'], 'b', 7.5, 'ink2', 'C');
                    $pdf->txt($x, $y + 6.6, $colw, get_string('occasionn', $p, $c['occasion']), 'm', 6.5, 'muted', 'C');
                } else {
                    $pdf->SetXY($x, $y);
                    $pdf->f('b', 7.5, 'ink2');
                    $pdf->Cell($colw, 13, $c['label'], 0, 0, 'C', false, '', 1, false, 'T', 'M');
                }
            }
        };
        $head();
        foreach ($g->items as $it) {
            $crit = format_string($it->criterion);
            $refs = trim((string)$it->mappingrefs);
            $h = $pdf->th($textw, $crit, 's', 9.5) + ($refs !== '' ? 4.4 : 0) + (!$it->mandatory ? 4.4 : 0);
            $rowh = max($h, 10.5) + 8.5;
            $pdf->box_keep($rowh + $this->pdf_item_extra($pdf, $it, $gcols, $cw - ($textx - $ml) - 6), $head);
            $y = $pdf->box_row($rowh, true, $head);
            // Number badge.
            $pdf->f('b', 7.5);
            $bw = max(9, $pdf->GetStringWidth($it->number) + 4);
            $pdf->colours('subtle', 'border', 0.25);
            $pdf->RoundedRect($ml + 6, $y + 5, $bw, 5.6, 1.6, '1111', 'DF');
            $pdf->f('b', 7.5, 'ink2');
            $pdf->SetXY($ml + 6, $y + 5);
            $pdf->Cell($bw, 5.6, $it->number, 0, 0, 'C', false, '', 0, false, 'T', 'M');
            $ty = $pdf->txt($textx, $y + 5, $textw, $crit, 's', 9.5);
            if ($refs !== '') {
                $ty = $pdf->txt($textx, $ty + 0.6, $textw, get_string('covers', $p) . ' ' . $refs, 'm', 7, 'muted');
            }
            if (!$it->mandatory) {
                $pdf->txt($textx, $ty + 0.6, $textw, get_string('optional', $p), 'm', 7, 'muted');
            }
            // Result cells.
            $itemcells = checklist::item_cells($it);
            $media = [];
            $notes = [];
            foreach ($gcols as $i => $c) {
                $cx = !empty($it->once) ? $colsx + count($gcols) * $colw / 2 : $colsx + $i * $colw + $colw / 2;
                if (!in_array($c['id'], $itemcells)) {
                    continue;
                }
                $cell = $d['cells'][$it->id . '#' . $c['id']] ?? null;
                $v = $cell['r'] ?? '';
                if ($v === '') {
                    $pdf->colours(null, 'faint', 0.35);
                    $pdf->Line($cx - 2, $y + 8.2, $cx + 2, $y + 8.2);
                } else {
                    $pdf->pill($cx, $y + 5.4, self::label($v), $v === 'NO' ? 'plain' : $v, 8, true);
                    $pdf->txt($cx - $colw / 2, $y + 11.2, $colw, userdate($cell['time'], $dfmt), 'm', 6.3, 'muted', 'C');
                }
                if ($cell && ($cell['c'] !== '' || $cell['timer'] !== null)) {
                    $notes[] = ['label' => $c['label'], 'text' => $cell['c'], 'timer' => $cell['timer']];
                }
                foreach ($cell['media'] ?? [] as $mm) {
                    $col = $c['label'];
                    if (!empty($mm['linked'])) {
                        $col .= ' · ' . get_string('linkedfrom', 'mod_evidencechecklist', $mm['fromno']);
                    }
                    $media[] = $mm + ['col' => $col, 'resultid' => $mm['srcid'] ?? $cell['id']];
                }
            }
            // Comments.
            $nw = $cw - ($textx - $ml) - 6;
            foreach ($notes as $nt) {
                $tag = $nt['label'] . ($nt['timer'] !== null ? '  ·  ' . get_string('timer', $p) . ' ' .
                    sprintf('%d:%02d', intdiv($nt['timer'], 60), $nt['timer'] % 60) : '');
                $th = $nt['text'] !== '' ? $pdf->th($nw - 10, $nt['text'], 'r', 8.5) : 0;
                $nh = 10 + $th;
                $ny = $pdf->box_row($nh + 3, false);
                $pdf->colours('subtle', 'border', 0.25);
                $pdf->RoundedRect($textx, $ny, $nw, $nh, 2.5, '1111', 'DF');
                $pdf->txt($textx + 5, $ny + 3.2, $nw - 10, $tag, 'b', 7, 'ink2');
                if ($nt['text'] !== '') {
                    $pdf->txt($textx + 5, $ny + 7.4, $nw - 10, $nt['text'], 'r', 8.5, 'ink');
                }
            }
            // Gap plan.
            $gap = $d['gaps'][$it->id] ?? null;
            if ($gap && ($gap['observed'] !== '' || $gap['strategy'] !== '')) {
                $closed = $gap['status'] === 'closed';
                $parts = [];
                if ($gap['observed'] !== '') {
                    $parts[] = [get_string('observed', $p), $gap['observed']];
                }
                if ($gap['strategy'] !== '') {
                    $parts[] = [get_string('strategy', $p), $gap['strategy']];
                }
                if ($gap['reobservedate']) {
                    $parts[] = [get_string('reobservedate', $p), userdate(
                        $gap['reobservedate'],
                        get_string('strftimedate', 'langconfig')
                    )];
                }
                $gh = 10;
                foreach ($parts as [$k, $v]) {
                    $gh += 4 + $pdf->th($nw - 10, $v, 'r', 8.5) + 1.5;
                }
                $gy = $pdf->box_row($gh + 3, false);
                $pdf->colours($closed ? 'subtle' : 'magentatint', $closed ? 'border' : 'magenta', 0.25);
                $pdf->RoundedRect($textx, $gy, $nw, $gh, 2.5, '1111', 'DF');
                $pdf->txt(
                    $textx + 5,
                    $gy + 3.2,
                    $nw - 10,
                    get_string('gapplan', $p) . ($closed ? '  ·  ' . self::gap_closed_text($gap) : ''),
                    'b',
                    7.5,
                    $closed ? 'ink2' : 'magenta'
                );
                $yy = $gy + 8;
                foreach ($parts as [$k, $v]) {
                    $pdf->txt($textx + 5, $yy, $nw - 10, $k, 'm', 7, 'muted');
                    $yy = $pdf->txt($textx + 5, $yy + 3.6, $nw - 10, $v, 'r', 8.5, 'ink') + 1.5;
                }
            }
            if ($media) {
                $this->pdf_media($pdf, $media, $textx, $nw);
            }
            if ($notes || $media || ($gap && ($gap['observed'] !== '' || $gap['strategy'] !== ''))) {
                $pdf->box_row(2.5, false);
            }
        }
        $pdf->box_end();
    }

    /**
     * Height of the notes, gap plan and media under an item, so the block can be kept on one page.
     *
     * @param pdf_report $pdf
     * @param \stdClass $it
     * @param array $gcols
     * @param float $nw note width
     * @return float
     */
    protected function pdf_item_extra(pdf_report $pdf, \stdClass $it, array $gcols, float $nw): float {
        $d = $this->data;
        $h = 0;
        $media = 0;
        $itemcells = checklist::item_cells($it);
        foreach ($gcols as $c) {
            if (!in_array($c['id'], $itemcells)) {
                continue;
            }
            $cell = $d['cells'][$it->id . '#' . $c['id']] ?? null;
            if ($cell && ($cell['c'] !== '' || $cell['timer'] !== null)) {
                $h += 13 + ($cell['c'] !== '' ? $pdf->th($nw - 10, $cell['c'], 'r', 8.5) : 0);
            }
            $media += count($cell['media'] ?? []);
        }
        $gap = $d['gaps'][$it->id] ?? null;
        if ($gap && ($gap['observed'] !== '' || $gap['strategy'] !== '')) {
            $h += 13 + $pdf->th($nw - 10, $gap['observed'] . ' ' . $gap['strategy'], 'r', 8.5) + 12;
        }
        if ($media) {
            $h += 38 * ceil($media / 3) + 2.5;
        }
        return $h > 200 ? 0 : $h;
    }

    /**
     * Media tiles: photos embedded, video and audio as poster + centred QR.
     *
     * @param pdf_report $pdf
     * @param array $media
     * @param float $x0
     * @param float $width
     */
    protected function pdf_media(pdf_report $pdf, array $media, float $x0, float $width): void {
        $p = 'mod_evidencechecklist';
        $fs = get_file_storage();
        $th = 30;
        $tiles = [];
        foreach ($media as $mm) {
            $file = $fs->get_file_by_id($mm['id']);
            if (!$file) {
                continue;
            }
            if ($mm['type'] === 'image') {
                $img = $pdf->prep_image($file, 1000);
                if ($img) {
                    $tiles[] = ['w' => 40, 'type' => 'image', 'img' => $img] + $mm;
                }
            } else if ($mm['type'] === 'video') {
                $poster = $fs->get_area_files($this->context->id, $p, 'poster', $mm['resultid'], 'id', false);
                $img = $poster ? $pdf->prep_image(reset($poster), 700) : null;
                $tiles[] = ['w' => $img ? 74 : 40, 'type' => 'video', 'img' => $img] + $mm;
            } else if ($mm['type'] === 'audio') {
                $tiles[] = ['w' => 40, 'type' => 'audio', 'img' => null] + $mm;
            }
        }
        $rows = [];
        $row = [];
        $used = 0;
        foreach ($tiles as $t) {
            if ($row && $used + $t['w'] > $width) {
                $rows[] = $row;
                $row = [];
                $used = 0;
            }
            $row[] = $t;
            $used += $t['w'] + 4;
        }
        if ($row) {
            $rows[] = $row;
        }
        foreach ($rows as $row) {
            $y = $pdf->box_row($th + 8, false) + 1;
            $x = $x0;
            foreach ($row as $t) {
                if ($t['type'] === 'image') {
                    $pdf->cover($t['img'], $x, $y, $t['w'], $th, 2.5, $t['url']);
                } else {
                    $pdf->colours('white', 'border', 0.3);
                    $pdf->RoundedRect($x, $y, $t['w'], $th, 2.5, '1111', 'DF');
                    $qx = $x;
                    $qw = $t['w'];
                    if ($t['img']) {
                        $pdf->cover($t['img'], $x, $y, 34, $th, 2.5, $t['url']);
                        $pdf->play($x + 17, $y + $th / 2, 4.2);
                        $qx = $x + 34;
                        $qw = $t['w'] - 34;
                    }
                    $qs = 17;
                    $pdf->qr($t['url'], $qx + $qw / 2, $y + 3.2, $qs);
                    $pdf->txt(
                        $qx,
                        $y + 21.4,
                        $qw,
                        get_string($t['type'] === 'video' ? 'videoevidence' : 'audioevidence', $p),
                        'b',
                        7,
                        $t['type'] === 'video' ? 'greenink' : 'ink',
                        'C'
                    );
                    $pdf->txt($qx, $y + 25, $qw, get_string('scantoplay', $p), 'm', 6, 'muted', 'C');
                    $pdf->Link($x, $y, $t['w'], $th, $t['url']);
                }
                $pdf->txt($x, $y + $th + 1.2, $t['w'], $t['col'], 'm', 6.5, 'muted');
                $x += $t['w'] + 4;
            }
        }
    }

    /**
     * Outcome card, signatures, hash and retention note.
     *
     * @param pdf_report $pdf
     */
    protected function pdf_outcome(pdf_report $pdf): void {
        $p = 'mod_evidencechecklist';
        $d = $this->data;
        $ml = pdf_report::ML;
        $cw = pdf_report::CW;
        $final = $d['attempt']['status'] === 'finalised';
        $decision = $d['attempt']['decision'];
        $pdf->box_start();
        $fb = $d['attempt']['feedback'];
        $decl = $final ? get_string('declarationdone', $p) : '';
        $h = 22 + ($fb !== '' ? 6 + $pdf->th($cw - 12, $fb, 'r', 9.5) : 0) + ($decl !== '' ? 4 + $pdf->th(
            $cw - 12,
            $decl,
            'r',
            8
        ) : 0);
        $y = $pdf->box_row($h);
        $pdf->txt($ml + 6, $y + 6, 60, get_string('decision', $p), 'm', 7.5, 'muted');
        $pdf->pill(
            $ml + 6,
            $y + 10.5,
            $final ? get_string('decision_' . $decision, $p) : get_string('inprogress', $p),
            !$final ? 'plain' : ($decision === 'competent' ? 'S' : 'NYS'),
            10
        );
        $yy = $y + 20;
        if ($fb !== '') {
            $pdf->txt($ml + 6, $yy, $cw - 12, get_string('feedback', $p), 'm', 7.5, 'muted');
            $yy = $pdf->txt($ml + 6, $yy + 4, $cw - 12, $fb, 'r', 9.5) + 2;
        }
        if ($decl !== '') {
            $pdf->txt($ml + 6, $yy + 1, $cw - 12, $decl, 'r', 8, 'ink2');
        }
        $pdf->box_end(5);

        // Signatures side by side.
        if ($d['signatures']) {
            $pdf->need(46);
            $y = $pdf->cy;
            $w = ($cw - 6) / 2;
            foreach (array_values($d['signatures']) as $i => $sg) {
                $x = $ml + ($i % 2) * ($w + 6);
                if ($i && $i % 2 === 0) {
                    $pdf->cy = $y + 46;
                    $pdf->need(46);
                    $y = $pdf->cy;
                }
                $pdf->colours('white', 'border', 0.3);
                $pdf->RoundedRect($x, $y, $w, 40, 3.5, '1111', 'DF');
                $pdf->txt(
                    $x + 6,
                    $y + 5,
                    $w - 12,
                    get_string(
                        $sg['role'] === 'student' ? 'studentsignature' :
                        'assessorsignature',
                        $p
                    ),
                    'm',
                    7.5,
                    'muted'
                );
                $file = $this->signature_file($sg);
                $img = $file ? $pdf->prep_image($file, 900, true) : null;
                if ($img) {
                    $pdf->contain($img, $x + 6, $y + 9.5, $w - 30, 15, 'L');
                } else {
                    $pdf->txt($x + 6, $y + 12, $w - 12, $sg['typed'], 'b', 16, 'ink');
                }
                $pdf->colours(null, 'border', 0.25);
                $pdf->Line($x + 6, $y + 26.5, $x + $w - 6, $y + 26.5);
                $pdf->txt($x + 6, $y + 28.5, $w - 12, $sg['name'], 's', 9.5);
                $pdf->txt(
                    $x + 6,
                    $y + 33.2,
                    $w - 12,
                    userdate($sg['time'], get_string('strftimedatetime', 'langconfig')),
                    'm',
                    7.5,
                    'muted'
                );
            }
            $pdf->cy = $y + 46;
        }
        $pdf->need(22);
        $y = $pdf->cy;
        if ($this->attempt->recordhash) {
            $y = $pdf->txt($ml, $y, $cw, get_string('recordhash', $p), 'm', 7, 'muted');
            $y = $pdf->txt($ml, $y + 0.5, $cw, $this->attempt->recordhash, 's', 7.5, 'ink2') + 3;
        }
        $pdf->txt(
            $ml,
            $y,
            $cw,
            get_string('retentionnote', $p) . ' ' . get_string('generated', $p, userdate(time())),
            'r',
            7.5,
            'muted'
        );
    }

    /**
     * Signature file for a signature entry.
     *
     * @param array $sg
     * @return \stored_file|null
     */
    protected function signature_file(array $sg): ?\stored_file {
        global $DB;
        $sig = $DB->get_record('evidencechecklist_signature', ['attemptid' => $this->attempt->id, 'role' => $sg['role']]);
        if (!$sig) {
            return null;
        }
        $files = get_file_storage()->get_area_files(
            $this->context->id,
            'mod_evidencechecklist',
            'signature',
            $sig->id,
            'id',
            false
        );
        return $files ? reset($files) : null;
    }

    /**
     * "Closed: re-observed as satisfactory · Sarah Mitchell · 28 Oct 2026" plus the assessor's note.
     *
     * @param array $gap
     * @return string
     */
    protected static function gap_closed_text(array $gap): string {
        $p = 'mod_evidencechecklist';
        $how = get_string(($gap['closereason'] ?? '') === 'assessor' ? 'gapclosed_assessor' : 'gapclosed_reobserved', $p);
        $parts = [$how];
        if (!empty($gap['closedby'])) {
            $parts[] = $gap['closedby'];
        }
        if (!empty($gap['timeclosed'])) {
            $parts[] = userdate($gap['timeclosed'], get_string('strftimedatetimeshort', 'langconfig'));
        }
        $text = implode(' · ', $parts);
        if (!empty($gap['closenote'])) {
            $text .= ' · ' . $gap['closenote'];
        }
        return $text;
    }
}
