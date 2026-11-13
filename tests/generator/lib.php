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
 * Data generator for Evidence Checklist.
 *
 * @package    mod_evidencechecklist
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_evidencechecklist_generator extends testing_module_generator {
    /**
     * Create an instance with sensible defaults.
     *
     * @param array|stdClass $record
     * @param array|null $options
     * @return stdClass
     */
    public function create_instance($record = null, ?array $options = null) {
        $record = (array)$record + [
            'name' => 'Evidence Checklist',
            'intro' => '',
            'introformat' => FORMAT_HTML,
            'unitcode' => '',
            'unittitle' => '',
            'setting' => '',
            'equipment' => '',
            'defaultoccasions' => 1,
            'requiremapping' => 0,
            'livestudentview' => 1,
            'allowbulk' => 1,
            'requirephoto' => 0,
            'statements' => '',
            'grade' => 1,
            'completioncompetent' => 0,
        ];
        return parent::create_instance($record, (array)$options);
    }
}
