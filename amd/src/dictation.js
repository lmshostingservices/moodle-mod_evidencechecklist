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
 * Voice dictation into text fields using the browser's built-in speech recognition (where available).
 *
 * @module     mod_evidencechecklist/dictation
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
let active = null;

/**
 * Is dictation available in this browser?
 *
 * @returns {Boolean}
 */
export const supported = () => !!SR;

/**
 * Stop any active dictation.
 */
export const stopAll = () => {
    if (active) {
        active.stop();
        active = null;
    }
};

/**
 * Wire a mic button to a textarea.
 *
 * @param {HTMLElement} button
 * @param {HTMLTextAreaElement} field
 */
export const attach = (button, field) => {
    if (!SR) {
        button.hidden = true;
        return;
    }
    button.addEventListener('click', e => {
        e.preventDefault();
        if (button.classList.contains('ec-listening')) {
            stopAll();
            return;
        }
        stopAll();
        const rec = new SR();
        rec.lang = document.documentElement.lang === 'en' ? 'en-AU' : (document.documentElement.lang || 'en-AU');
        rec.continuous = true;
        rec.interimResults = false;
        const base = () => (field.value && !/\s$/.test(field.value) ? field.value + ' ' : field.value);
        rec.onresult = ev => {
            let text = '';
            for (let i = ev.resultIndex; i < ev.results.length; i++) {
                if (ev.results[i].isFinal) {
                    text += ev.results[i][0].transcript;
                }
            }
            if (text) {
                text = text.trim();
                text = text.charAt(0).toUpperCase() + text.slice(1);
                field.value = base() + text;
                field.dispatchEvent(new Event('input', {bubbles: true}));
            }
        };
        rec.onend = () => {
            button.classList.remove('ec-listening');
            field.dispatchEvent(new Event('change', {bubbles: true}));
            if (active === rec) {
                active = null;
            }
        };
        rec.onerror = () => rec.onend();
        button.classList.add('ec-listening');
        active = rec;
        rec.start();
    });
};
