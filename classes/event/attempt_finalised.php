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

namespace mod_evidencechecklist\event;

/**
 * An attempt was finalised and released.
 *
 * @package    mod_evidencechecklist
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attempt_finalised extends \core\event\base {
    /**
     * Init.
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'evidencechecklist_attempt';
    }

    /**
     * Name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventattemptfinalised', 'mod_evidencechecklist');
    }

    /**
     * Description.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '$this->userid' finalised attempt id '$this->objectid' for the user with id " .
            "'$this->relateduserid' as '{$this->other['decision']}' in the Evidence Checklist with course module id " .
            "'$this->contextinstanceid'.";
    }

    /**
     * URL.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url(
            '/mod/evidencechecklist/report.php',
            ['id' => $this->contextinstanceid,
            'userid' => $this->relateduserid]
        );
    }

    /**
     * Object id mapping for restore.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'evidencechecklist_attempt', 'restore' => 'evidencechecklist_attempt'];
    }

    /**
     * Other mapping.
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
