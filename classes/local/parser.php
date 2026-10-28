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

/**
 * Fast paste parser: turns pasted text (plain lists, Excel rows or the ChatGPT prompt output)
 * into groups, items and the mapping list. Rule-based, no AI.
 *
 * @package    mod_evidencechecklist
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class parser {
    /** @var string Requirement code pattern, e.g. PC1.2, PE2a, KE4, AC1, FS2. */
    const CODE = '(?:PC|PE|KE|AC|FS|E)\s?\d+(?:\.\d+)*[a-z]?';

    /**
     * Normalise a requirement code: upper-case prefix, keep sub-letters (PE2a).
     *
     * @param string $code
     * @return string
     */
    public static function code(string $code): string {
        $code = str_replace(' ', '', trim($code));
        return preg_replace_callback('/^[a-z]+/i', fn($m) => strtoupper($m[0]), $code);
    }

    /**
     * Parse pasted text.
     *
     * @param string $text
     * @param int $defaultoccasions
     * @return array ['meta' => [], 'groups' => [], 'mapping' => [], 'errors' => [], 'warnings' => []]
     */
    public static function parse(string $text, int $defaultoccasions = 1): array {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // Strip markdown code fences copied from ChatGPT.
        $text = preg_replace('/^\s*\x60{3}[a-z]*\s*$/mi', '', $text);
        $lines = explode("\n", $text);

        $meta = ['unitcode' => '', 'unittitle' => '', 'setting' => '', 'equipment' => '', 'occasions' => $defaultoccasions,
            'columns' => []];
        $declared = [];
        $groups = [];
        $mapping = [];
        $errors = [];
        $warnings = [];
        $inmapping = false;
        $current = null;

        foreach ($lines as $index => $raw) {
            $lineno = $index + 1;
            $line = trim($raw);
            if ($line === '') {
                continue;
            }
            // The totals line ChatGPT writes after the code block.
            if (preg_match('/^mapping:\s*(\d+)\s+requirements?\D+(\d+)\D+(\d+)\D+(\d+)/i', $line, $tm)) {
                $meta['claimed'] = ['total' => (int)$tm[1], 'items' => (int)$tm[2], 'other' => (int)$tm[3], 'gaps' => (int)$tm[4]];
                continue;
            }
            if (preg_match('/^@mapping\s*:?\s*$/i', $line)) {
                $inmapping = true;
                continue;
            }
            if (preg_match('/^@([a-z-]+)\s*:\s*(.*)$/i', $line, $m)) {
                $key = strtolower($m[1]);
                $value = trim($m[2]);
                if ($key === 'unit') {
                    $parts = array_map('trim', explode('|', $value, 2));
                    $meta['unitcode'] = \core_text::substr($parts[0], 0, 50);
                    $meta['unittitle'] = \core_text::substr($parts[1] ?? '', 0, 255);
                } else if ($key === 'setting') {
                    $meta['setting'] = $value;
                } else if ($key === 'equipment') {
                    $meta['equipment'] = $value;
                } else if ($key === 'occasions-default' || $key === 'occasions') {
                    $meta['occasions'] = max(1, min(10, (int)$value));
                    $defaultoccasions = $meta['occasions'];
                } else if ($key === 'columns') {
                    // For example "Adult x1; Infant x1" or "Adult (2); Infant".
                    foreach (preg_split('/\s*[;,]\s*/', $value) as $col) {
                        $col = trim($col);
                        if ($col === '') {
                            continue;
                        }
                        $times = 0;
                        if (preg_match('/^(.*?)\s*(?:[x\x{00D7}*]\s*(\d+)|\((\d+)\))$/iu', $col, $cm)) {
                            $col = trim($cm[1]);
                            $times = (int)($cm[2] !== '' ? $cm[2] : ($cm[3] ?? 0));
                        }
                        $declared[] = ['label' => \core_text::substr($col, 0, 40), 'times' => $times];
                    }
                }
                continue;
            }

            if ($inmapping) {
                $row = self::split($line);
                if (count($row) < 2 || !preg_match('/^' . self::CODE . '$/i', str_replace(' ', '', $row[0]))) {
                    $warnings[] = ['line' => $lineno, 'msg' => get_string('parse_badmapping', 'mod_evidencechecklist', s($line))];
                    continue;
                }
                $target = trim(count($row) >= 3 ? $row[2] : $row[1]);
                $reqtext = count($row) >= 3 ? $row[1] : '';
                $entry = ['code' => self::code($row[0]), 'text' => $reqtext, 'numbers' => [],
                    'other' => '', 'gap' => false, 'line' => $lineno];
                if (preg_match('/^other\s*:\s*(.+)$/i', $target, $om)) {
                    $entry['other'] = trim($om[1]);
                } else if (preg_match('/^gap$/i', $target) || $target === '' || $target === '-') {
                    $entry['gap'] = true;
                } else {
                    foreach (preg_split('/[,;\s]+/', $target) as $num) {
                        if (preg_match('/^\d+\.\d+$/', $num)) {
                            $entry['numbers'][] = $num;
                        }
                    }
                    if (!$entry['numbers']) {
                        $entry['gap'] = true;
                    }
                }
                $mapping[] = $entry;
                continue;
            }

            // Group heading: "# Name", or a short line ending in ":" with no separators.
            if (
                preg_match('/^#{1,3}\s*(.+)$/', $line, $m)
                    || (strpos($line, '|') === false && strpos($line, "\t") === false
                        && preg_match('/^(.{2,120}):$/', $line, $m))
            ) {
                $name = trim(preg_replace('/^\d+[\.\)]\s*/', '', trim($m[1])));
                $groups[] = ['name' => \core_text::substr($name, 0, 255), 'items' => []];
                $current = count($groups) - 1;
                continue;
            }

            $item = self::parse_item($line, $defaultoccasions);
            if (!$item) {
                continue;
            }
            if ($current === null) {
                $groups[] = ['name' => get_string('defaultgroup', 'mod_evidencechecklist'), 'items' => []];
                $current = count($groups) - 1;
            }
            $item['line'] = $lineno;
            $groups[$current]['items'][] = $item;
        }

        // Drop empty groups and number the rest.
        $groups = array_values(array_filter($groups, fn($g) => !empty($g['items'])));
        $numbers = [];
        foreach ($groups as $gi => &$group) {
            $group['number'] = (string)($gi + 1);
            foreach ($group['items'] as $ii => &$item) {
                $item['number'] = ($gi + 1) . '.' . ($ii + 1);
                $numbers[$item['number']] = $item;
            }
        }
        unset($group, $item);

        if (!$groups) {
            $errors[] = ['line' => 0, 'msg' => get_string('parse_noitems', 'mod_evidencechecklist')];
        }

        // Columns: as declared, plus any context an item names that was not declared.
        $cols = [];
        foreach ($declared as $d) {
            $key = checklist::context_key($d['label']);
            $cols[$key] = ['key' => $key, 'label' => $d['label'], 'times' => max(1, min(10, $d['times'] ?: $meta['occasions']))];
        }
        foreach ($numbers as $num => $item) {
            foreach ($item['contexts'] as $c) {
                if (in_array(\core_text::strtolower($c), ['all', 'once'])) {
                    continue;
                }
                $key = checklist::context_key($c);
                if (!isset($cols[$key])) {
                    if ($declared) {
                        $warnings[] = ['line' => $item['line'], 'msg' => get_string(
                            'parse_newcolumn',
                            'mod_evidencechecklist',
                            (object)['name' => $c, 'number' => $num]
                        )];
                    }
                    $cols[$key] = ['key' => $key, 'label' => $c, 'times' => $meta['occasions']];
                }
            }
        }
        $meta['columns'] = array_values($cols) ?: [['key' => '', 'label' => '', 'times' => $meta['occasions']]];

        // Check the mapping both ways and repair the checklist side so the two always agree:
        // the mapping section is the report, so each item's references are rebuilt from it.
        $codes = [];
        $merged = [];
        foreach ($mapping as $entry) {
            if (isset($codes[$entry['code']])) {
                $warnings[] = ['line' => $entry['line'], 'msg' => get_string(
                    'parse_duplicatecode',
                    'mod_evidencechecklist',
                    $entry['code']
                )];
                $first = &$merged[$codes[$entry['code']]];
                $first['numbers'] = array_values(array_unique(array_merge($first['numbers'], $entry['numbers'])));
                $first['gap'] = $first['gap'] && $entry['gap'];
                unset($first);
                continue;
            }
            $codes[$entry['code']] = count($merged);
            $merged[] = $entry;
        }
        $mapping = $merged;
        $pointing = [];
        foreach ($mapping as $entry) {
            foreach ($entry['numbers'] as $num) {
                if (!isset($numbers[$num])) {
                    $errors[] = ['line' => $entry['line'],
                        'msg' => get_string(
                            'parse_unknownnumber',
                            'mod_evidencechecklist',
                            (object)[
                            'code' => $entry['code'], 'number' => $num]
                        )];
                    continue;
                }
                $pointing[$num][] = $entry['code'];
            }
        }
        $repairs = 0;
        if ($mapping) {
            foreach ($groups as &$group) {
                foreach ($group['items'] as &$item) {
                    $num = $item['number'];
                    $fromreport = array_values(array_unique($pointing[$num] ?? []));
                    foreach ($item['refs'] as $ref) {
                        if (!isset($codes[$ref])) {
                            $warnings[] = ['line' => $item['line'], 'msg' => get_string(
                                'parse_refnotinmapping',
                                'mod_evidencechecklist',
                                (object)['code' => $ref, 'number' => $num]
                            )];
                            $repairs++;
                        } else if (!in_array($ref, $fromreport, true)) {
                            $warnings[] = ['line' => $item['line'], 'msg' => get_string(
                                'parse_refnotreverse',
                                'mod_evidencechecklist',
                                (object)['code' => $ref, 'number' => $num]
                            )];
                            $repairs++;
                        }
                    }
                    foreach ($fromreport as $code) {
                        if (!in_array($code, $item['refs'], true)) {
                            $warnings[] = ['line' => $item['line'], 'msg' => get_string(
                                'parse_refadded',
                                'mod_evidencechecklist',
                                (object)['code' => $code, 'number' => $num]
                            )];
                            $repairs++;
                        }
                    }
                    $item['refs'] = $fromreport;
                }
                unset($item);
            }
            unset($group);
        }
        // Field counts on item lines.
        foreach ($groups as $group) {
            foreach ($group['items'] as $item) {
                if ($item['fields'] > 1 && !in_array($item['fields'], [5, 6], true) && !$item['legacy']) {
                    $warnings[] = ['line' => $item['line'], 'msg' => get_string(
                        'parse_fieldcount',
                        'mod_evidencechecklist',
                        (object)['number' => $item['number'], 'fields' => $item['fields']]
                    )];
                }
            }
        }
        // Totals, and a check against the totals line if ChatGPT wrote one.
        $totals = ['total' => count($mapping), 'items' => 0, 'other' => 0, 'gaps' => 0];
        foreach ($mapping as $entry) {
            if ($entry['other'] !== '') {
                $totals['other']++;
            } else if ($entry['gap'] || !$entry['numbers']) {
                $totals['gaps']++;
            } else {
                $totals['items']++;
            }
        }
        $meta['totals'] = $totals;
        $meta['repairs'] = $repairs;
        if (!empty($meta['claimed']) && $meta['claimed'] != $totals) {
            $warnings[] = ['line' => 0, 'msg' => get_string(
                'parse_totalsdiffer',
                'mod_evidencechecklist',
                (object)($totals +
                ['ctotal' => $meta['claimed']['total'], 'citems' => $meta['claimed']['items'],
                'cother' => $meta['claimed']['other'], 'cgaps' => $meta['claimed']['gaps']])
            )];
        }

        return ['meta' => $meta, 'groups' => $groups, 'mapping' => $mapping, 'errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * Split a line on pipes or tabs.
     *
     * @param string $line
     * @return array
     */
    protected static function split(string $line): array {
        $sep = strpos($line, '|') !== false ? '|' : (strpos($line, "\t") !== false ? "\t" : null);
        if ($sep === null) {
            return [$line];
        }
        $parts = array_map('trim', explode($sep, $line));
        // Tolerate markdown table edges.
        if ($sep === '|' && $parts && $parts[0] === '') {
            array_shift($parts);
        }
        if ($sep === '|' && $parts && end($parts) === '') {
            array_pop($parts);
        }
        return $parts;
    }

    /**
     * Parse one item line.
     *
     * @param string $line
     * @param int $defaultoccasions
     * @return array|null
     */
    protected static function parse_item(string $line, int $defaultoccasions): ?array {
        // Skip markdown table header separators and header rows.
        if (preg_match('/^\|?\s*:?-{3,}/', $line) || preg_match('/^\|?\s*criterion\s*\|/i', $line)) {
            return null;
        }
        $parts = self::split($line);
        $fields = count($parts);
        $criterion = array_shift($parts);
        $legacy = false;
        // Formats: Criterion | Mapping | Contexts | Benchmark | Gap tip (current);
        // older rows also carried an Occasions number after Contexts, or instead of it.
        $occasions = '';
        if (count($parts) >= 2 && preg_match('/^\d+$/', $parts[1])) {
            $occasions = $parts[1];
            $parts[1] = '';
            $legacy = true;
        } else if (count($parts) >= 3 && preg_match('/^\d+$/', $parts[2])) {
            $occasions = $parts[2];
            array_splice($parts, 2, 1);
            $legacy = true;
        }
        [$mapping, $contexts, $benchmark, $gaptip] = array_pad($parts, 4, '');

        // Strip bullets and list numbering.
        $criterion = preg_replace(
            '/^(?:[-*\x{2022}\x{2023}\x{25E6}]\s+|\d+(?:\.\d+)*[\.\)]?\s+|[a-z][\.\)]\s+)/u',
            '',
            trim($criterion)
        );
        $refs = [];
        // A leading requirement code (e.g. "PC1.2 Checks for danger") becomes a mapping reference.
        if (preg_match('/^(' . self::CODE . ')\s+(.+)$/i', $criterion, $m)) {
            $refs[] = self::code($m[1]);
            $criterion = trim($m[2]);
        }
        $mandatory = 1;
        $verbal = 0;
        for ($i = 0; $i < 2; $i++) {
            if (preg_match('/^optional\s*:\s*(.*)$/i', $criterion, $m)) {
                $mandatory = 0;
                $criterion = trim($m[1]);
            }
            if (preg_match('/^verbal\s*:\s*(.*)$/i', $criterion, $m)) {
                $verbal = 1;
                $criterion = trim($m[1]);
            }
        }
        if ($criterion === '') {
            return null;
        }
        foreach (preg_split('/[,;]+/', $mapping) as $ref) {
            $ref = self::code($ref);
            if ($ref !== '' && !in_array($ref, $refs)) {
                $refs[] = $ref;
            }
        }
        $ctx = [];
        foreach (preg_split('/\s*;\s*/', trim($contexts)) as $c) {
            $c = trim($c);
            if (strcasecmp($c, 'general') === 0) {
                $c = 'Once';
            }
            if ($c !== '') {
                $ctx[] = \core_text::substr($c, 0, 40);
            }
        }
        $occ = (int)$occasions > 0 ? min(10, (int)$occasions) : $defaultoccasions;
        return [
            'criterion' => $criterion,
            'refs' => $refs,
            'contexts' => $ctx,
            'occasions' => $occ,
            'benchmark' => $benchmark,
            'gaptip' => $gaptip,
            'mandatory' => $mandatory,
            'verbal' => $verbal,
            'fields' => $fields,
            'legacy' => $legacy,
        ];
    }
}
