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
 * Defaults and site settings helpers.
 *
 * @package    mod_evidencechecklist
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class defaults {
    /** @var string Default green. */
    const GREEN = '#20c75d';
    /** @var string Default magenta. */
    const MAGENTA = '#c50180';

    /**
     * Default pre-assessment statements, one per line.
     *
     * @return string
     */
    public static function statements(): string {
        $site = get_config('mod_evidencechecklist', 'defaultstatements');
        if (is_string($site) && trim($site) !== '') {
            return $site;
        }
        return get_string('defaultstatements', 'mod_evidencechecklist');
    }

    /**
     * Statements of an activity as an array.
     *
     * @param \stdClass $checklist
     * @return string[]
     */
    public static function statement_list(\stdClass $checklist): array {
        $lines = preg_split('/\R/', (string)$checklist->statements);
        return array_values(array_filter(array_map('trim', $lines), fn($l) => $l !== ''));
    }

    /**
     * The assessor's spoken version of a statement: a direct question for the built-in statements, or
     * "Ask the candidate to confirm" for statements the RTO wrote.
     *
     * @param string $text
     * @return string
     */
    public static function question(string $text): string {
        $defaults = array_values(
            array_filter(
                array_map(
                    'trim',
                    preg_split(
                        '/\R/',
                        get_string(
                            'defaultstatements',
                            'mod_evidencechecklist'
                        )
                    )
                )
            )
        );
        $i = array_search(trim($text), $defaults, true);
        $sm = get_string_manager();
        if ($i !== false && $sm->string_exists('confirmq_' . ($i + 1), 'mod_evidencechecklist')) {
            return get_string('confirmq_' . ($i + 1), 'mod_evidencechecklist');
        }
        return get_string('confirmq_generic', 'mod_evidencechecklist', $text);
    }

    /**
     * Brand colours from settings.
     *
     * @return array [green, magenta]
     */
    public static function colours(): array {
        $green = get_config('mod_evidencechecklist', 'colourgreen') ?: self::GREEN;
        $magenta = get_config('mod_evidencechecklist', 'colourmagenta') ?: self::MAGENTA;
        $valid = fn($c, $d) => preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : $d;
        return [$valid($green, self::GREEN), $valid($magenta, self::MAGENTA)];
    }

    /**
     * Is the mapping lock on for this activity (site setting can force it).
     *
     * @param \stdClass $checklist
     * @return bool
     */
    public static function mapping_lock(\stdClass $checklist): bool {
        if (get_config('mod_evidencechecklist', 'forcemappinglock')) {
            return true;
        }
        return !empty($checklist->requiremapping);
    }

    /**
     * The ChatGPT prompt, with the unit, setting and equipment from the activity settings filled in.
     *
     * @param \stdClass $checklist
     * @return string
     */
    public static function prompt(\stdClass $checklist): string {
        global $CFG;
        $prompt = file_get_contents($CFG->dirroot . '/mod/evidencechecklist/prompt/evidence-checklist-prompt.txt');
        $code = trim((string)($checklist->unitcode ?? ''));
        $title = trim((string)($checklist->unittitle ?? ''));
        $set = function (string $placeholder, string $value) use (&$prompt) {
            if ($value !== '') {
                $prompt = str_replace($placeholder, $value, $prompt);
            }
        };
        $set('[ENTER UNIT CODE]', $code);
        $set('[ENTER UNIT TITLE]', $title);
        $prompt = str_replace(
            '[UNIT LINK]',
            $code !== '' && preg_match('/^[A-Z0-9]+$/i', $code) ?
            'https://training.gov.au/Training/Details/' . strtoupper($code) : '',
            $prompt
        );
        $fill = function (string $label, string $value) use (&$prompt) {
            if ($value !== '') {
                $prompt = preg_replace_callback(
                    '/^(\*\*' . preg_quote($label, '/') . ':\*\*) \[[^\n]*\]$/m',
                    fn($m) => $m[1] . ' ' . $value,
                    $prompt,
                    1
                );
            }
        };
        $fill('Industry or workplace setting', trim((string)($checklist->setting ?? '')));
        $fill('Available equipment', trim((string)($checklist->equipment ?? '')));
        return $prompt;
    }
}
