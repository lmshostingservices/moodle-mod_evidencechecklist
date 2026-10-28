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
 * Optional, read-only link to the Verify ID activity (mod_verifyid) on the same site.
 * Reads the local database only; no external calls.
 *
 * @package    mod_evidencechecklist
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class verifyid {
    /**
     * Is Verify ID installed?
     *
     * @return bool
     */
    public static function available(): bool {
        global $DB;
        if (!get_config('mod_evidencechecklist', 'useverifyid')) {
            return false;
        }
        if (!\core_component::get_component_directory('mod_verifyid')) {
            return false;
        }
        $manager = $DB->get_manager();
        return $manager->table_exists('verifyid_attempts') && $manager->table_exists('verifyid');
    }

    /**
     * Latest approved Verify ID record for a student: this course first, then site-wide.
     *
     * @param int $userid
     * @param int $courseid
     * @param bool $withphoto include the reference selfie (assessors only)
     * @return array|null
     */
    public static function reference(int $userid, int $courseid, bool $withphoto = false): ?array {
        global $DB;
        if (!self::available()) {
            return null;
        }
        $fields = 'va.id, va.timecreated, va.timereviewed, va.documenttype, v.course, v.name' . ($withphoto ? ', va.selfie' : '');
        $sql = "SELECT $fields
                  FROM {verifyid_attempts} va
                  JOIN {verifyid} v ON v.id = va.verifyidid
                 WHERE va.userid = :userid AND va.status = :status";
        $params = ['userid' => $userid, 'status' => 'verified'];
        $records = $DB->get_records_sql(
            $sql . " AND v.course = :courseid ORDER BY va.timecreated DESC",
            $params + ['courseid' => $courseid],
            0,
            1
        );
        if (!$records && get_config('mod_evidencechecklist', 'verifyidsitewide')) {
            $records = $DB->get_records_sql($sql . " ORDER BY va.timecreated DESC", $params, 0, 1);
        }
        if (!$records) {
            return null;
        }
        $r = reset($records);
        $cm = get_coursemodule_from_instance(
            'verifyid',
            $DB->get_field('verifyid_attempts', 'verifyidid', ['id' => $r->id]),
            $r->course,
            false,
            IGNORE_MISSING
        );
        $photo = null;
        if ($withphoto && !empty($r->selfie) && preg_match('#^data:image/(jpeg|png|webp);base64,#', $r->selfie)) {
            $photo = $r->selfie;
        }
        return [
            'attemptid' => (int)$r->id,
            'time' => (int)($r->timereviewed ?: $r->timecreated),
            'document' => (string)$r->documenttype,
            'url' => $cm ? (new \moodle_url('/mod/verifyid/review.php', ['id' => $cm->id, 'pending' => 0]))->out(false) : '',
            'photo' => $photo,
        ];
    }
}
