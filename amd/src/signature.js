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
 * Finger/stylus/mouse signature pad.
 *
 * @module     mod_evidencechecklist/signature
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

export default class SignaturePad {
    /**
     * Constructor.
     *
     * @param {HTMLCanvasElement} canvas
     */
    constructor(canvas) {
        this.canvas = canvas;
        this.empty = true;
        const ratio = Math.max(window.devicePixelRatio || 1, 1);
        const rect = canvas.getBoundingClientRect();
        canvas.width = Math.round((rect.width || 480) * ratio);
        canvas.height = Math.round((rect.height || 160) * ratio);
        this.ctx = canvas.getContext('2d');
        this.ctx.scale(ratio, ratio);
        this.ctx.lineWidth = 2.2;
        this.ctx.lineCap = 'round';
        this.ctx.lineJoin = 'round';
        this.ctx.strokeStyle = '#101828';
        this.drawing = false;
        const pos = e => {
            const r = canvas.getBoundingClientRect();
            return {x: e.clientX - r.left, y: e.clientY - r.top};
        };
        canvas.addEventListener('pointerdown', e => {
            e.preventDefault();
            canvas.setPointerCapture(e.pointerId);
            this.drawing = true;
            const p = pos(e);
            this.ctx.beginPath();
            this.ctx.moveTo(p.x, p.y);
            this.ctx.lineTo(p.x + 0.1, p.y + 0.1);
            this.ctx.stroke();
            this.empty = false;
            canvas.dispatchEvent(new CustomEvent('ec:signed'));
        });
        canvas.addEventListener('pointermove', e => {
            if (!this.drawing) {
                return;
            }
            const p = pos(e);
            this.ctx.lineTo(p.x, p.y);
            this.ctx.stroke();
        });
        const end = () => {
            this.drawing = false;
        };
        canvas.addEventListener('pointerup', end);
        canvas.addEventListener('pointercancel', end);
        canvas.style.touchAction = 'none';
    }

    /**
     * Clear the pad.
     */
    clear() {
        this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
        this.empty = true;
    }

    /**
     * PNG data URL, or empty string when nothing was drawn.
     *
     * @returns {String}
     */
    toDataURL() {
        return this.empty ? '' : this.canvas.toDataURL('image/png');
    }
}
