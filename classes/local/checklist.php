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
 * Checklist structure: groups, numbered items, context x occasion columns and mapping status.
 *
 * @package    mod_evidencechecklist
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class checklist {
    /** @var \stdClass The activity record. */
    public $record;
    /** @var array Groups with items, in order. */
    public $groups = [];
    /** @var array Items keyed by id. */
    public $items = [];

    /** @var array Column definitions: [['key', 'label', 'times']]. */
    public $coldefs = [];

    /**
     * Load a checklist.
     *
     * @param \stdClass $record
     */
    public function __construct(\stdClass $record) {
        global $DB;
        $this->record = $record;
        $groups = $DB->get_records('evidencechecklist_group', ['checklistid' => $record->id], 'sortorder, id');
        $items = $DB->get_records('evidencechecklist_item', ['checklistid' => $record->id], 'sortorder, id');
        $this->coldefs = self::column_defs($record, $items);
        $gi = 0;
        foreach ($groups as $group) {
            $gi++;
            $group->number = (string)$gi;
            $group->items = [];
            $this->groups[$group->id] = $group;
        }
        $counters = [];
        foreach ($items as $item) {
            if (!isset($this->groups[$item->groupid])) {
                continue;
            }
            $group = $this->groups[$item->groupid];
            $counters[$group->id] = ($counters[$group->id] ?? 0) + 1;
            $item->number = $group->number . '.' . $counters[$group->id];
            $item->cells = self::cells_for($item, $this->coldefs);
            $item->once = count($this->coldefs) > 1 && count($item->cells) === 1 && !array_filter(
                self::tokens($item),
                fn($t) => !in_array(\core_text::strtolower($t), ['once', 'general', 'all'])
            );
            $item->contextlist = self::contexts_of($item);
            $group->items[] = $item;
            $this->items[$item->id] = $item;
        }
    }

    /**
     * Column definitions for a checklist: stored ones, else derived from the items' contexts.
     *
     * @param \stdClass $record
     * @param array $items
     * @return array
     */
    public static function column_defs(\stdClass $record, array $items): array {
        $defs = [];
        $stored = json_decode((string)($record->columnsdef ?? ''), true);
        if (is_array($stored)) {
            foreach ($stored as $d) {
                $label = trim((string)($d['label'] ?? ''));
                $key = (string)($d['key'] ?? self::context_key($label));
                if (!isset($defs[$key])) {
                    $defs[$key] = ['key' => $key, 'label' => $label, 'times' => max(1, min(10, (int)($d['times'] ?? 1)))];
                }
            }
        }
        if (!$defs) {
            $times = max(1, (int)($record->defaultoccasions ?? 1));
            foreach ($items as $item) {
                foreach (self::tokens($item) as $t) {
                    if (!in_array(\core_text::strtolower($t), ['all', 'once', 'general'])) {
                        $key = self::context_key($t);
                        $defs[$key] = $defs[$key] ?? ['key' => $key, 'label' => $t, 'times' => $times];
                    }
                }
            }
            if (!$defs) {
                $defs[''] = ['key' => '', 'label' => '', 'times' => $times];
            }
        }
        return array_values($defs);
    }

    /**
     * Raw context tokens on an item.
     *
     * @param \stdClass $item
     * @return string[]
     */
    protected static function tokens(\stdClass $item): array {
        return array_values(array_filter(array_map('trim', explode(';', (string)$item->contexts)), fn($t) => $t !== ''));
    }

    /**
     * Cells an item needs, given the checklist's columns.
     * "All" means every column; blank, "Once" or "General" means one observation (every occasion when the checklist has a
     * single column); named columns mean every occasion of those columns.
     *
     * @param \stdClass $item
     * @param array $defs
     * @return string[] 'key|n'
     */
    public static function cells_for(\stdClass $item, array $defs): array {
        if (!$defs) {
            return ['|1'];
        }
        $tokens = array_map(fn($t) => \core_text::strtolower($t), self::tokens($item));
        $cells = [];
        $all = in_array('all', $tokens);
        $named = array_map([self::class, 'context_key'], array_diff($tokens, ['all', 'once', 'general']));
        if (!$all && !$named) {
            if (count($defs) === 1 && !in_array('once', $tokens)) {
                $all = true;
            } else {
                return [$defs[0]['key'] . '|1'];
            }
        }
        foreach ($defs as $d) {
            if ($all || in_array($d['key'], $named, true)) {
                for ($n = 1; $n <= $d['times']; $n++) {
                    $cells[] = $d['key'] . '|' . $n;
                }
            }
        }
        return $cells ?: [$defs[0]['key'] . '|1'];
    }

    /**
     * Context labels named on an item (for display).
     *
     * @param \stdClass $item
     * @return array of ['key' => string, 'label' => string]
     */
    public static function contexts_of(\stdClass $item): array {
        $out = [];
        foreach (self::tokens($item) as $label) {
            $out[] = ['key' => self::context_key($label), 'label' => $label];
        }
        return $out ?: [['key' => '', 'label' => '']];
    }

    /**
     * Stable key for a context label.
     *
     * @param string $label
     * @return string
     */
    public static function context_key(string $label): string {
        return \core_text::substr(trim(preg_replace('/[^a-z0-9]+/', '-', \core_text::strtolower($label)), '-'), 0, 100);
    }

    /**
     * Grid columns, one per column definition and occasion.
     *
     * @return array of ['id' => 'key|n', 'key', 'context', 'occasion', 'times', 'label']
     */
    public function columns(): array {
        $cols = [];
        foreach ($this->coldefs as $d) {
            for ($n = 1; $n <= $d['times']; $n++) {
                if ($d['label'] === '') {
                    $label = $d['times'] > 1 ? get_string('occasionn', 'mod_evidencechecklist', $n) :
                        get_string('observedcol', 'mod_evidencechecklist');
                } else {
                    $label = $d['times'] > 1 ? $d['label'] . ' ' . $n : $d['label'];
                }
                $cols[] = ['id' => $d['key'] . '|' . $n, 'key' => $d['key'], 'context' => $d['label'], 'occasion' => $n,
                    'times' => $d['times'], 'label' => $label];
            }
        }
        return $cols;
    }

    /**
     * Cells an item requires.
     *
     * @param \stdClass $item
     * @return array of 'key|n'
     */
    public static function item_cells(\stdClass $item): array {
        if (isset($item->cells)) {
            return $item->cells;
        }
        $cells = [];
        foreach (self::contexts_of($item) as $ctx) {
            for ($n = 1; $n <= max(1, (int)$item->occasions); $n++) {
                $cells[] = $ctx['key'] . '|' . $n;
            }
        }
        return $cells;
    }

    /**
     * Column keys and occasions that hold results (they cannot be removed).
     *
     * @return array ['key|n' => true]
     */
    public function used_cells(): array {
        global $DB;
        $sql = "SELECT DISTINCT r.contextkey, r.occasionno
                  FROM {evidencechecklist_result} r
                  JOIN {evidencechecklist_attempt} a ON a.id = r.attemptid
                 WHERE a.checklistid = ? AND r.result <> ?";
        $used = [];
        foreach ($DB->get_recordset_sql($sql, [$this->record->id, '']) as $r) {
            $used[$r->contextkey . '|' . $r->occasionno] = true;
        }
        return $used;
    }

    /**
     * Save new column definitions.
     *
     * @param array $defs [['key', 'label', 'times', 'existing']]
     * @param int $userid
     */
    public function save_columns(array $defs, int $userid): void {
        global $DB;
        $clean = [];
        foreach ($defs as $d) {
            $label = \core_text::substr(trim((string)$d['label']), 0, 40);
            $existing = !empty($d['existing']);
            if (!$existing && $label === '') {
                continue;
            }
            $key = $existing ? (string)$d['key'] : self::context_key($label);
            while (!$existing && isset($clean[$key])) {
                $key .= '-2';
            }
            $clean[$key] = ['key' => $key, 'label' => $label, 'times' => max(1, min(10, (int)$d['times']))];
        }
        $DB->set_field('evidencechecklist', 'columnsdef', json_encode(array_values($clean)), ['id' => $this->record->id]);
        $DB->set_field('evidencechecklist', 'timemodified', time(), ['id' => $this->record->id]);
        self::log($this->record->id, $userid, 'columns', json_encode(array_values($clean)));
    }

    /**
     * Replace the checklist with parsed content. Only allowed before any results exist.
     *
     * @param array $parsed Output of parser::parse()
     * @param int $userid
     */
    public function replace_from_parsed(array $parsed, int $userid): void {
        global $DB;
        $id = $this->record->id;
        $transaction = $DB->start_delegated_transaction();
        $reqids = $DB->get_fieldset_select('evidencechecklist_req', 'id', 'checklistid = ?', [$id]);
        if ($reqids) {
            [$insql, $params] = $DB->get_in_or_equal($reqids);
            $DB->delete_records_select('evidencechecklist_reqmap', "reqid $insql", $params);
        }
        $DB->delete_records('evidencechecklist_req', ['checklistid' => $id]);
        $DB->delete_records('evidencechecklist_item', ['checklistid' => $id]);
        $DB->delete_records('evidencechecklist_group', ['checklistid' => $id]);

        $numbertoid = [];
        $gs = 0;
        foreach ($parsed['groups'] as $group) {
            $gs++;
            $groupid = $DB->insert_record(
                'evidencechecklist_group',
                (object)[
                'checklistid' => $id, 'name' => $group['name'], 'sortorder' => $gs]
            );
            $is = 0;
            foreach ($group['items'] as $item) {
                $is++;
                $itemid = $DB->insert_record(
                    'evidencechecklist_item',
                    (object)[
                    'checklistid' => $id,
                    'groupid' => $groupid,
                    'sortorder' => $is,
                    'criterion' => $item['criterion'],
                    'benchmark' => $item['benchmark'],
                    'gaptip' => $item['gaptip'],
                    'mappingrefs' => \core_text::substr(implode(', ', $item['refs']), 0, 255),
                    'contexts' => \core_text::substr(implode(';', $item['contexts']), 0, 255),
                    'occasions' => $item['occasions'],
                    'mandatory' => $item['mandatory'],
                    'verbal' => $item['verbal'],
                    ]
                );
                $numbertoid[$item['number']] = $itemid;
            }
        }

        // Mapping: from the @mapping section, else derived from each item's references.
        $mapping = $parsed['mapping'];
        if (!$mapping) {
            $derived = [];
            foreach ($parsed['groups'] as $group) {
                foreach ($group['items'] as $item) {
                    foreach ($item['refs'] as $ref) {
                        $derived[$ref]['numbers'][] = $item['number'];
                    }
                }
            }
            foreach ($derived as $code => $d) {
                $mapping[] = ['code' => $code, 'text' => '', 'numbers' => $d['numbers'], 'other' => '', 'gap' => false];
            }
        }
        $rs = 0;
        foreach ($mapping as $entry) {
            $rs++;
            $reqid = $DB->insert_record(
                'evidencechecklist_req',
                (object)[
                'checklistid' => $id, 'code' => \core_text::substr($entry['code'], 0, 50), 'reqtext' => $entry['text'],
                'othertool' => $entry['other'] !== '' ? \core_text::substr($entry['other'], 0, 255) : null, 'sortorder' => $rs]
            );
            foreach (array_unique($entry['numbers']) as $num) {
                if (isset($numbertoid[$num])) {
                    $DB->insert_record('evidencechecklist_reqmap', (object)['reqid' => $reqid, 'itemid' => $numbertoid[$num]]);
                }
            }
        }

        $update = ['id' => $id, 'timemodified' => time(), 'mappingapprovedby' => null, 'mappingapprovedtime' => null];
        foreach (['unitcode', 'unittitle', 'setting', 'equipment'] as $f) {
            if (!empty($parsed['meta'][$f])) {
                $update[$f] = $parsed['meta'][$f];
            }
        }
        if (!empty($parsed['meta']['occasions'])) {
            $update['defaultoccasions'] = $parsed['meta']['occasions'];
        }
        $update['columnsdef'] = json_encode($parsed['meta']['columns'] ?? []);
        $DB->update_record('evidencechecklist', (object)$update);
        $this->record = $DB->get_record('evidencechecklist', ['id' => $id]);
        self::log(
            $id,
            $userid,
            'import',
            json_encode(
                ['groups' => count($parsed['groups']), 'items' => count($numbertoid),
                'requirements' => count($mapping)]
            )
        );
        $transaction->allow_commit();
    }

    /**
     * Has any assessment data been recorded for this checklist?
     *
     * @param int $checklistid
     * @return bool
     */
    public static function has_results(int $checklistid): bool {
        global $DB;
        return $DB->record_exists_sql(
            "SELECT 1 FROM {evidencechecklist_result} r
                                         JOIN {evidencechecklist_attempt} a ON a.id = r.attemptid
                                        WHERE a.checklistid = ?",
            [$checklistid]
        );
    }

    /**
     * Mapping report data.
     *
     * @return array
     */
    public function mapping(): array {
        global $DB;
        $reqs = $DB->get_records('evidencechecklist_req', ['checklistid' => $this->record->id], 'sortorder, id');
        $links = [];
        if ($reqs) {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($reqs));
            foreach ($DB->get_records_select('evidencechecklist_reqmap', "reqid $insql", $params) as $l) {
                if (isset($this->items[$l->itemid])) {
                    $links[$l->reqid][] = $this->items[$l->itemid];
                }
            }
        }
        $rows = [];
        $mapped = $other = $gaps = 0;
        $coverage = [];
        foreach ($reqs as $req) {
            $type = strtoupper(preg_replace('/[^A-Za-z].*$/', '', $req->code));
            $items = $links[$req->id] ?? [];
            usort($items, fn($a, $b) => strnatcmp($a->number, $b->number));
            $isperf = in_array($type, ['PC', 'PE', 'E']);
            if ($items) {
                $status = 'mapped';
                $mapped++;
            } else if (!empty($req->othertool) && !$isperf) {
                $status = 'other';
                $other++;
            } else {
                $status = 'gap';
                $gaps++;
            }
            foreach ($items as $it) {
                $coverage[$it->id][] = $req->code;
            }
            $rows[] = (object)['req' => $req, 'type' => $type, 'items' => $items, 'status' => $status];
        }
        $total = count($rows);
        $unmappeditems = array_filter($this->items, fn($it) => empty($coverage[$it->id]));
        if ($total === 0) {
            $state = 'notstarted';
        } else if ($gaps > 0) {
            $state = 'gaps';
        } else {
            $state = 'complete';
        }
        return [
            'rows' => $rows,
            'total' => $total,
            'mapped' => $mapped,
            'other' => $other,
            'gaps' => $gaps,
            'percent' => $total ? (int)floor((($mapped + $other) / $total) * 100) : 0,
            'state' => $state,
            'coverage' => $coverage,
            'unmappeditems' => $unmappeditems,
        ];
    }

    /**
     * Mapping state only ('complete', 'gaps', 'notstarted').
     *
     * @return string
     */
    public function mapping_state(): string {
        return $this->mapping()['state'];
    }

    /**
     * Can assessment proceed given the mapping lock?
     *
     * @return bool
     */
    public function assessing_allowed(): bool {
        if (!$this->items) {
            return false;
        }
        if (!defaults::mapping_lock($this->record)) {
            return true;
        }
        return $this->mapping_state() === 'complete';
    }

    /**
     * Write a change log entry.
     *
     * @param int $checklistid
     * @param int $userid
     * @param string $action
     * @param string $detail
     */
    public static function log(int $checklistid, int $userid, string $action, string $detail = ''): void {
        global $DB;
        $DB->insert_record(
            'evidencechecklist_maplog',
            (object)['checklistid' => $checklistid, 'userid' => $userid,
            'action' => $action, 'detail' => $detail, 'timecreated' => time()]
        );
    }
}
