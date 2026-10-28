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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.

namespace mod_evidencechecklist\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Check the existing site licence; never purchase or debit credits here.
 *
 * @package    mod_evidencechecklist
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class entitlement {
    /** @var callable|null Test-only transport, receiving the JSON payload. */
    public static $transport = null;

    /**
     * Resolve Central Config first, then explicitly configured local credentials.
     *
     * @return array
     */
    protected static function credentials(): array {
        if (class_exists('\\local_aiconfig\\config')) {
            $siteid = trim((string)\local_aiconfig\config::get_site_id());
            $apikey = trim((string)\local_aiconfig\config::get_api_key());
            if ($siteid !== '' && $apikey !== '') {
                return [$siteid, $apikey];
            }
        }
        return [
            trim((string)get_config('mod_evidencechecklist', 'siteid')),
            trim((string)get_config('mod_evidencechecklist', 'apikey')),
        ];
    }

    /**
     * Check-only verification. Credentials never enter a URL or browser output.
     *
     * @param array $credentials
     * @return bool
     */
    protected static function verify(array $credentials): bool {
        global $CFG;
        $payload = [
            'pluginId' => 'evidencechecklist',
            'siteId' => $credentials[0],
            'apiKey' => $credentials[1],
        ];
        if (static::$transport !== null) {
            [$status, $response] = (static::$transport)($payload);
        } else {
            require_once($CFG->libdir . '/filelib.php');
            $curl = new \curl();
            $curl->setHeader('Content-Type: application/json');
            $response = $curl->post(
                'https://lms-labs.com/api/plugin-unlock/verify',
                json_encode($payload),
                [
                    'CURLOPT_CONNECTTIMEOUT' => 5,
                    'CURLOPT_TIMEOUT' => 15,
                    'CURLOPT_FOLLOWLOCATION' => false,
                ]
            );
            $info = $curl->get_info();
            $status = $curl->get_errno() ? 0 : (int)($info['http_code'] ?? 0);
        }
        $data = json_decode((string)$response, true);
        return $status === 200 && is_array($data) && ($data['unlocked'] ?? null) === true;
    }

    /**
     * A short positive-only cache bound to the site and credentials.
     *
     * @param bool $refresh Force a new check without charging.
     * @return bool
     */
    public static function has_access(bool $refresh = false): bool {
        global $CFG, $SESSION;
        $credentials = static::credentials();
        $identity = hash('sha256', $CFG->wwwroot . "\0" . implode("\0", $credentials));
        $cached = $SESSION->mod_evidencechecklist_entitlement ?? null;
        if (!$refresh && is_array($cached) && ($cached['identity'] ?? '') === $identity
                && ($cached['expires'] ?? 0) > time()) {
            return true;
        }
        unset($SESSION->mod_evidencechecklist_entitlement);
        if ($credentials[0] === '' || $credentials[1] === '') {
            return false;
        }
        try {
            $verified = static::verify($credentials);
        } catch (\Throwable $e) {
            // An unavailable check cannot grant a licence; never log credentials.
            return false;
        }
        if (!$verified) {
            return false;
        }
        $SESSION->mod_evidencechecklist_entitlement = [
            'identity' => $identity,
            'expires' => time() + 300,
        ];
        return true;
    }

    /**
     * Require the licence before creating, editing or assessing an activity.
     *
     * @return void
     */
    public static function require_access(): void {
        if (!static::has_access()) {
            throw new \moodle_exception(
                'unlockrequired',
                'mod_evidencechecklist',
                new \moodle_url('/mod/evidencechecklist/activate.php')
            );
        }
    }
}
