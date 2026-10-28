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

namespace mod_evidencechecklist;

use mod_evidencechecklist\local\parser;

/**
 * Fast paste parser tests.
 *
 * @package    mod_evidencechecklist
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_evidencechecklist\local\parser
 */
final class parser_test extends \advanced_testcase {
    /**
     * The ChatGPT prompt format with contexts, verbal, optional and mapping.
     */
    public function test_prompt_format(): void {
        $fence = str_repeat(chr(96), 3);
        $text = $fence . "\n@unit: HLTAID009 | Provide CPR\n@setting: Simulated room\n@equipment: Manikins, AED trainer\n" .
            "@occasions-default: 2\n\n# Check the scene\n" .
            "Checks for danger | PC1.1, PE2 | General | 2 | Names two hazards | Practise a scan\n" .
            "# CPR\n2 minutes CPR, 5 cycles | PE2a, PE3 | Adult; Infant | 2 | Depth one third | Use a feedback manikin\n" .
            "Optional: Verbal: When do you stop CPR? | KE6 | General | 1 | Paramedics take over | Re-read the guide\n" .
            "\n@mapping\nPC1.1 | Recognise the emergency | 1.1\nPE2a | 2 min CPR adult | 2.1\nPE3 | Infant CPR | 2.1\n" .
            "PE2 | Non-breathing adult | 1.1\nKE6 | When to stop | 2.2\nKE1 | ARC guidelines | Other: Written quiz\n" . $fence;
        $r = parser::parse($text, 1);
        $this->assertEmpty($r['errors']);
        $this->assertEmpty($r['warnings']);
        $this->assertSame('HLTAID009', $r['meta']['unitcode']);
        $this->assertSame(2, $r['meta']['occasions']);
        $this->assertCount(2, $r['groups']);
        $cpr = $r['groups'][1]['items'];
        $this->assertSame('2.1', $cpr[0]['number']);
        $this->assertSame(['Adult', 'Infant'], $cpr[0]['contexts']);
        $this->assertSame(['PE2a', 'PE3'], $cpr[0]['refs']);
        $this->assertSame(1, $cpr[1]['verbal']);
        $this->assertSame(0, $cpr[1]['mandatory']);
        $this->assertSame('When do you stop CPR?', $cpr[1]['criterion']);
        $this->assertSame(['Once'], $r['groups'][0]['items'][0]['contexts']);
        $this->assertCount(6, $r['mapping']);
        $this->assertSame('Written quiz', $r['mapping'][5]['other']);
    }

    /**
     * Columns come from @columns; items say All, Once or name columns; new names add a column with a warning.
     */
    public function test_columns(): void {
        $r = parser::parse(
            "@columns: Adult x1; Infant (2)\n# A\nScene safe | PC1.1 | All | Hazards named | Scan\n" .
            "Handover | PC3.1 | Once | Clear report | Template\nVomit drill | PE2d | Adult | Rolls | Drill\n" .
            "Child CPR | PE4 | Child | Depth | Practise\n"
        );
        $this->assertEmpty($r['errors']);
        $this->assertCount(1, $r['warnings']);
        $this->assertSame(
            [['key' => 'adult', 'label' => 'Adult', 'times' => 1], ['key' => 'infant', 'label' => 'Infant',
            'times' => 2], ['key' => 'child', 'label' => 'Child', 'times' => 1]],
            $r['meta']['columns']
        );
        $items = $r['groups'][0]['items'];
        $this->assertSame(['All'], $items[0]['contexts']);
        $this->assertSame('Hazards named', $items[0]['benchmark']);
        $this->assertSame('Template', $items[1]['gaptip']);
        $defs = $r['meta']['columns'];
        $cells = fn($ctx) => \mod_evidencechecklist\local\checklist::cells_for((object)['contexts' => $ctx], $defs);
        $this->assertSame(['adult|1', 'infant|1', 'infant|2', 'child|1'], $cells('All'));
        $this->assertSame(['adult|1'], $cells('Once'));
        $this->assertSame(['adult|1'], $cells('Adult'));
        $this->assertSame(['infant|1', 'infant|2'], $cells('Infant'));
    }

    /**
     * The mapping report is checked both ways and the criteria's references are corrected to match it.
     */
    public function test_two_way_mapping_repair(): void {
        $r = parser::parse(
            "@columns: Adult x1\n# A\nDo one | PC1.1, PE2 | All | B | G\nDo two | PC1.2 | All | B | G\n" .
            "@mapping\nPC1.1 | X | 1.1\nPC1.2 | Y | 1.1, 1.2\nPE2a | Z | 1.2\nPC1.2 | Dup | 1.1\nKE1 | K | Other: Quiz\n" .
            "Mapping: 4 requirements - 3 to checklist items, 1 to other tools, 0 gaps.\n"
        );
        $this->assertEmpty($r['errors']);
        $items = $r['groups'][0]['items'];
        $this->assertSame(['PC1.1', 'PC1.2'], $items[0]['refs']);
        $this->assertSame(['PC1.2', 'PE2a'], $items[1]['refs']);
        $this->assertCount(4, $r['mapping']);
        $this->assertSame(['total' => 4, 'items' => 3, 'other' => 1, 'gaps' => 0], $r['meta']['totals']);
        $this->assertSame(3, $r['meta']['repairs']);
        $this->assertCount(4, $r['warnings']);
    }

    /**
     * Mapping to a criterion number that does not exist is an error.
     */
    public function test_unknown_number(): void {
        $r = parser::parse("# A\nDo it | PC1.1\n@mapping\nPC1.1 | Do | 1.1, 4.2\n");
        $this->assertCount(1, $r['errors']);
    }

    /**
     * A plain bulleted list becomes a single group with defaults.
     */
    public function test_plain_list(): void {
        $r = parser::parse("- Checks danger\n- Calls 000\n3. Starts CPR", 2);
        $this->assertCount(1, $r['groups']);
        $this->assertCount(3, $r['groups'][0]['items']);
        $this->assertSame('Starts CPR', $r['groups'][0]['items'][2]['criterion']);
        $this->assertSame(2, $r['groups'][0]['items'][0]['occasions']);
    }

    /**
     * Legacy four-column Excel rows (no contexts column) and a leading code.
     */
    public function test_legacy_columns_and_leading_code(): void {
        $r = parser::parse("Heading:\nPC2.1 Performs CPR\tPE2\t3\tGood depth");
        $item = $r['groups'][0]['items'][0];
        $this->assertSame('Heading', $r['groups'][0]['name']);
        $this->assertSame('Performs CPR', $item['criterion']);
        $this->assertSame(['PC2.1', 'PE2'], $item['refs']);
        $this->assertSame(3, $item['occasions']);
        $this->assertSame('Good depth', $item['benchmark']);
    }

    /**
     * Nothing to import.
     */
    public function test_empty(): void {
        $r = parser::parse("   \n");
        $this->assertNotEmpty($r['errors']);
    }
}
