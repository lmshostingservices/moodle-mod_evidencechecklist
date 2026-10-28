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
 * Copy-to-clipboard buttons.
 *
 * @module     mod_evidencechecklist/copy
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {add as addToast} from 'core/toast';
import {get_string as getString} from 'core/str';

/**
 * Init.
 */
export const init = () => {
    document.querySelectorAll('[data-ec-copy]').forEach(btn => {
        btn.addEventListener('click', async() => {
            const src = document.querySelector(btn.dataset.ecCopy);
            if (!src) {
                return;
            }
            try {
                await navigator.clipboard.writeText(src.value);
            } catch (e) {
                src.select();
                document.execCommand('copy');
            }
            addToast(await getString('promptcopied', 'mod_evidencechecklist'));
        });
    });
};
