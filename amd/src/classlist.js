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
 * Class list search filter.
 *
 * @module     mod_evidencechecklist/classlist
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Init.
 */
export const init = () => {
    const input = document.querySelector('[data-ec-search]');
    const list = document.querySelector('[data-ec-list]');
    if (!input || !list) {
        return;
    }
    input.addEventListener('input', () => {
        const q = input.value.trim().toLowerCase();
        list.querySelectorAll('[data-ec-name]').forEach(card => {
            card.hidden = q !== '' && card.dataset.ecName.indexOf(q) === -1;
        });
    });
};
