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
 * Evidence Checklist app: one-tap marking grid for assessors and the live, read-only view for students.
 *
 * @module     mod_evidencechecklist/app
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import {compressImage, upload, Recorder, canRecord} from './media';
import SignaturePad from './signature';
import * as Dictation from './dictation';
import {LINKS, ORDER} from './tips';

const POLL_MS = 5000;
const LONGPRESS_MS = 450;

const ICON = {
    S: '<svg viewBox="0 0 24 24" class="ec-ico ec-ico-check" aria-hidden="true"><path d="M5 12.5l4.2 4.2L19 7"/></svg>',
    NYS: '<svg viewBox="0 0 24 24" class="ec-ico" aria-hidden="true"><path d="M7 7l10 10M17 7L7 17"/></svg>',
    NO: '<svg viewBox="0 0 24 24" class="ec-ico" aria-hidden="true"><path d="M7 12h10"/></svg>',
    video: '<svg viewBox="0 0 24 24" class="ec-ico-sm" aria-hidden="true"><rect x="3" y="6" width="13" height="12" rx="2"/>' +
        '<path d="M16 10l5-3v10l-5-3z"/></svg>',
    upload: '<svg viewBox="0 0 24 24" class="ec-ico-sm" aria-hidden="true"><path d="M12 16V4M7 9l5-5 5 5M5 20h14"/></svg>',
    cam: '<svg viewBox="0 0 24 24" class="ec-ico-sm" aria-hidden="true"><path d="M4 8h3l2-2h6l2 2h3v11H4z"/>' +
        '<circle cx="12" cy="13" r="3.5"/></svg>',
    note: '<svg viewBox="0 0 24 24" class="ec-ico-sm" aria-hidden="true"><path d="M5 5h14v10H9l-4 4z"/></svg>',
    mic: '<svg viewBox="0 0 24 24" class="ec-ico-sm" aria-hidden="true"><rect x="9" y="3" width="6" height="11" rx="3"/>' +
        '<path d="M5 11a7 7 0 0014 0M12 18v3"/></svg>',
    info: '<svg viewBox="0 0 24 24" class="ec-ico-sm" aria-hidden="true"><circle cx="12" cy="12" r="9"/>' +
        '<path d="M12 11v6M12 7.5v.5"/></svg>',
    more: '<svg viewBox="0 0 24 24" class="ec-ico-sm" aria-hidden="true"><circle cx="6" cy="12" r="1.4"/>' +
        '<circle cx="12" cy="12" r="1.4"/><circle cx="18" cy="12" r="1.4"/></svg>',
    close: '<svg viewBox="0 0 24 24" class="ec-ico-sm" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>',
    back: '<svg viewBox="0 0 24 24" class="ec-ico-sm" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg>',
    bulb: '<svg viewBox="0 0 24 24" class="ec-ico-sm" aria-hidden="true"><path d="M9 18h6M10 21h4M12 3a6 6 0 00-3.5 ' +
        '10.9c.6.5 1 1.2 1 2V17h5v-1.1c0-.8.4-1.5 1-2A6 6 0 0012 3z"/></svg>',
    kbd: '<svg viewBox="0 0 24 24" class="ec-ico-sm" aria-hidden="true"><rect x="2.5" y="6" width="19" height="12" rx="2.5"/>' +
        '<path d="M6 10h.01M9.5 10h.01M13 10h.01M16.5 10h.01M7.5 14h9"/></svg>',
    next: '<svg viewBox="0 0 24 24" class="ec-ico-sm" aria-hidden="true"><path d="M9 5l7 7-7 7"/></svg>',
    link: '<svg viewBox="0 0 24 24" class="ec-ico-sm" aria-hidden="true">' +
        '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/>' +
        '<path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/></svg>',
    stop: '<svg viewBox="0 0 24 24" class="ec-ico-sm" aria-hidden="true"><rect x="7" y="7" width="10" height="10" rx="2"/></svg>',
    shield: '<svg viewBox="0 0 24 24" class="ec-ico-sm" aria-hidden="true"><path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/>' +
        '<path d="M8.5 12l2.5 2.5 4.5-5"/></svg>',
};

/**
 * Escape text for HTML.
 *
 * @param {*} t
 * @returns {String}
 */
const esc = t => String(t === null || t === undefined ? '' : t).replace(/[&<>"']/g,
    c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));

/**
 * Format a unix time.
 *
 * @param {Number} t
 * @param {Boolean} withtime
 * @returns {String}
 */
const fmt = (t, withtime = true) => {
    if (!t) {
        return '';
    }
    const d = new Date(t * 1000);
    const opts = {day: 'numeric', month: 'short', year: 'numeric'};
    if (withtime) {
        opts.hour = 'numeric';
        opts.minute = '2-digit';
    }
    // Moodle's "en" pack is Australian English.
    const lang = (document.documentElement.lang || 'en').replace('_', '-');
    return d.toLocaleString(lang === 'en' ? 'en-AU' : lang, opts);
};

/**
 * Position help pop-ups so they stay on screen, and let a tap open them on touch screens. Installed once per page.
 */
const installHelp = () => {
    if (document.body.dataset.ecHelp) {
        return;
    }
    document.body.dataset.ecHelp = '1';
    // One floating bubble on the body, so panels with transforms or scrolling never clip it.
    const tip = document.createElement('div');
    tip.className = 'ec-root ec-help-float';
    tip.setAttribute('role', 'tooltip');
    tip.hidden = true;
    document.body.appendChild(tip);
    let pinned = null;
    const show = h => {
        const pop = h.querySelector('.ec-help-pop');
        if (!pop) {
            return;
        }
        tip.textContent = pop.textContent;
        tip.hidden = false;
        const r = h.getBoundingClientRect();
        const w = Math.min(320, window.innerWidth - 24);
        tip.style.width = w + 'px';
        const left = Math.max(12, Math.min(r.left + r.width / 2 - w / 2, window.innerWidth - w - 12));
        tip.style.left = left + 'px';
        tip.style.top = '0px';
        const th = tip.offsetHeight;
        const below = r.bottom + 10;
        tip.style.top = (below + th > window.innerHeight - 8 ? Math.max(8, r.top - th - 10) : below) + 'px';
    };
    const hide = () => {
        tip.hidden = true;
        pinned = null;
    };
    document.addEventListener('mouseover', e => {
        const h = e.target.closest && e.target.closest('.ec-help');
        if (h) {
            show(h);
        } else if (!pinned && !tip.hidden) {
            tip.hidden = true;
        }
    });
    document.addEventListener('focusin', e => {
        const h = e.target.closest && e.target.closest('.ec-help');
        if (h) {
            show(h);
        }
    });
    document.addEventListener('focusout', e => {
        if (e.target.closest && e.target.closest('.ec-help') && !pinned) {
            tip.hidden = true;
        }
    });
    document.addEventListener('click', e => {
        const h = e.target.closest && e.target.closest('.ec-help');
        if (h) {
            // A help icon inside a label must not tick the box or focus the field.
            e.preventDefault();
            e.stopPropagation();
            if (pinned === h) {
                hide();
                return;
            }
            show(h);
            pinned = h;
            return;
        }
        if (pinned) {
            hide();
        }
    }, true);
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && !tip.hidden) {
            hide();
        }
    });
    window.addEventListener('scroll', hide, {passive: true, capture: true});
};

class App {
    /**
     * Constructor.
     *
     * @param {HTMLElement} root
     * @param {Object} cfg
     */
    constructor(root, cfg) {
        this.root = root;
        this.cfg = cfg;
        this.s = cfg.structure;
        this.state = cfg.state;
        this.items = {};
        this.s.groups.forEach(g => g.items.forEach(it => {
            it.groupid = g.id;
            this.items[it.id] = it;
        }));
        this.colIndex = {};
        this.s.columns.forEach((c, i) => {
            this.colIndex[c.id] = i;
        });
        this.panel = null;
        this.undoIds = null;
        this.pending = 0;
        // Keyboard and quick-action state.
        this.active = null;
        this.rowCell = {};
        this.undoStack = [];
        this.order = [];
        this.s.groups.forEach(g => g.items.forEach(it => {
            this.s.columns.forEach(c => {
                if (it.cells.indexOf(c.id) !== -1) {
                    this.order.push({itemid: it.id, cellid: c.id});
                }
            });
        }));
        this.render();
        this.bindRoot();
        this.bindKeys();
        installHelp();
        this.startPolling();
    }

    /**
     * Language string with simple {$a} / {$a->x} replacement.
     *
     * @param {String} key
     * @param {*} a
     * @returns {String}
     */
    str(key, a) {
        let s = this.cfg.strings[key] || key;
        if (a !== undefined && a !== null) {
            if (typeof a === 'object') {
                s = s.replace(/\{\$a->(\w+)\}/g, (m, k) => (a[k] !== undefined ? a[k] : m));
            } else {
                s = s.replace(/\{\$a\}/g, a);
            }
        }
        return s;
    }

    /** @returns {Boolean} */
    get isAssessor() {
        return this.cfg.role !== 'student';
    }

    /** @returns {Boolean} */
    get editable() {
        return this.cfg.role === 'assessor' && this.cfg.canassess && this.state && this.state.attempt.status === 'inprogress';
    }

    /** @returns {Boolean} */
    get canMark() {
        if (!this.editable) {
            return false;
        }
        if (!this.state.confirm.done) {
            return false;
        }
        if (this.s.requirephoto && !this.todaySessionPhoto()) {
            return false;
        }
        return true;
    }

    /**
     * Has today's session got a candidate photo?
     *
     * @returns {Boolean}
     */
    todaySessionPhoto() {
        const sess = this.todaySession();
        return !!(sess && sess.photo);
    }

    /**
     * Session started today by anyone (latest first).
     *
     * @returns {Object|null}
     */
    todaySession() {
        if (!this.state || !this.state.sessions || !this.state.sessions.length) {
            return null;
        }
        const s = this.state.sessions[0];
        const midnight = new Date();
        midnight.setHours(0, 0, 0, 0);
        return s.time * 1000 >= midnight.getTime() ? s : null;
    }

    /**
     * Call a web service and apply the returned state.
     *
     * @param {String} method
     * @param {Object} args
     * @returns {Promise<Object>}
     */
    async call(method, args) {
        this.pending++;
        // Any poll already in flight is now out of date.
        this.gen = (this.gen || 0) + 1;
        this.root.classList.add('ec-busy');
        try {
            const res = await Ajax.call([{methodname: 'mod_evidencechecklist_' + method,
                args: Object.assign({cmid: this.s.cmid, userid: this.cfg.userid}, args)}])[0];
            const st = JSON.parse(res.state);
            const cur = this.state && this.state.attempt;
            // Quick taps can return out of order: never step back to an older revision of the same attempt.
            if (st && !(cur && st.attempt.id === cur.id && st.attempt.rev < cur.rev)) {
                this.applyState(st);
            }
            return JSON.parse(res.extra || '{}');
        } catch (e) {
            this.toast((e && (e.message || e.error)) || this.str('savefailed'), 'error');
            throw e;
        } finally {
            this.pending--;
            if (!this.pending) {
                this.root.classList.remove('ec-busy');
            }
        }
    }

    /**
     * Replace state and re-render.
     *
     * @param {Object} st
     * @param {Boolean} fromPoll
     */
    applyState(st, fromPoll = false) {
        const changed = !this.state || st.attempt.rev !== this.state.attempt.rev || st.attempt.id !== this.state.attempt.id;
        if (this.state && st.attempt.id === this.state.attempt.id && st.gaps && this.state.gaps) {
            // Tell the assessor (and student) when a gap plan closes itself after a re-observation.
            Object.keys(st.gaps).forEach(id => {
                const was = this.state.gaps[id];
                if (was && was.status === 'open' && st.gaps[id].status === 'closed' && st.gaps[id].closereason === 'reobserved' &&
                        this.items[id]) {
                    this.toast(this.str('gapclosedtoast', this.items[id].number), 'info', null, 4000);
                }
            });
        }
        this.state = st;
        if (changed || !fromPoll) {
            this.render();
            if (this.panel) {
                this.refreshPanel();
            }
        }
        if (fromPoll && changed) {
            this.flash();
        }
    }

    /**
     * Poll for changes so the student (and other assessors) see marks as they happen.
     */
    startPolling() {
        const tick = async() => {
            if (document.hidden || this.pending || !this.state && this.cfg.role !== 'student') {
                return;
            }
            const gen = this.gen || 0;
            try {
                const res = await Ajax.call([{methodname: 'mod_evidencechecklist_get_state',
                    args: {cmid: this.s.cmid, userid: this.cfg.userid, since: this.state ? this.state.attempt.rev : -1}}],
                    true, true, true)[0];
                const st = JSON.parse(res.state);
                // Ignore a poll that started before this screen saved something, or that is older than what we have.
                const cur = this.state && this.state.attempt;
                const older = cur && st && st.attempt.id === cur.id && st.attempt.rev < cur.rev;
                if (st && !this.pending && gen === (this.gen || 0) && !older) {
                    this.applyState(st, true);
                }
            } catch (e) {
                // Network blips are retried on the next tick.
            }
        };
        this.poll = setInterval(tick, POLL_MS);
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                tick();
            }
        });
    }

    /**
     * Brief "updated" indicator for live changes.
     */
    flash() {
        const live = this.root.querySelector('.ec-live');
        if (live) {
            live.classList.remove('ec-live-pulse');
            void live.offsetWidth;
            live.classList.add('ec-live-pulse');
        }
        if (!this.isAssessor) {
            this.toast(this.str('updatedjustnow'));
        }
    }

    /**
     * Show a toast.
     *
     * @param {String} msg
     * @param {String} type
     * @param {Object} action {label, fn}
     * @param {Number} ms
     * @returns {HTMLElement}
     */
    toast(msg, type = 'info', action = null, ms = 2600) {
        let host = document.querySelector('.ec-toasts');
        if (!host) {
            host = document.createElement('div');
            host.className = 'ec-root ec-toasts';
            host.setAttribute('aria-live', 'polite');
            document.body.appendChild(host);
        }
        const t = document.createElement('div');
        t.className = 'ec-toast ec-toast-' + type;
        t.innerHTML = '<span>' + esc(msg) + '</span>';
        if (action) {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'ec-toast-action';
            b.textContent = action.label;
            b.addEventListener('click', () => {
                action.fn();
                t.remove();
            });
            t.appendChild(b);
        }
        host.appendChild(t);
        // Keep at most two toasts on screen.
        while (host.children.length > 2) {
            host.firstChild.remove();
        }
        requestAnimationFrame(() => t.classList.add('ec-in'));
        setTimeout(() => {
            t.classList.remove('ec-in');
            setTimeout(() => t.remove(), 300);
        }, ms);
        return t;
    }

    // Rendering.

    /**
     * Render everything.
     */
    render() {
        const scroll = window.scrollY;
        const st = this.state;
        let html = this.header();
        if (st && st.attempt.status === 'finalised') {
            html += this.resultCard();
        }
        if (this.cfg.role === 'assessor' && !this.cfg.canassess) {
            html += '<div class="ec-card ec-callout ec-callout-gap"><strong>' + esc(this.str('mappinglocked_msg')) +
                '</strong> <a class="ec-btn ec-btn-sm" href="' + esc(this.cfg.urls.mapping) + '">' +
                esc(this.str('learnmore')) + '</a></div>';
        }
        if (this.isAssessor) {
            html += this.tips(['competency', 'nograde', 'dimensions', 'benchmark']);
        }
        html += this.confirmCard();
        if (this.isAssessor && st) {
            html += this.sessionCard();
        }
        if (st && st.progress && st.progress.hidden) {
            html += '<div class="ec-card ec-callout">' + esc(this.str('livehidden')) + '</div>';
        }
        html += this.grid();
        if (this.isAssessor) {
            html += this.tips(['retention']);
        }
        html += this.bottomBar();
        // Keep unsaved ticks and notes when a live update re-renders the screen.
        const keep = {};
        this.root.querySelectorAll('[data-confirm]').forEach(cb => {
            keep[cb.dataset.confirm] = cb.checked;
        });
        const keepnote = this.root.querySelector('[data-confirm-note]');
        const notev = keepnote && !keepnote.disabled ? keepnote.value : null;
        this.root.innerHTML = html;
        this.root.querySelectorAll('[data-confirm]').forEach(cb => {
            if (keep[cb.dataset.confirm] !== undefined && !cb.disabled) {
                cb.checked = keep[cb.dataset.confirm];
                cb.closest('.ec-statement').classList.toggle('ec-on', cb.checked);
            }
        });
        const newnote = this.root.querySelector('[data-confirm-note]');
        if (newnote && notev !== null && !newnote.disabled) {
            newnote.value = notev;
        }
        this.afterRender();
        window.scrollTo(0, scroll);
    }

    /**
     * Sticky header with progress.
     *
     * @returns {String}
     */
    header() {
        const st = this.state;
        const p = st ? st.progress : {required: 0, s: 0, nys: 0, todo: 0, percent: 0, complete: false};
        const req = Math.max(1, p.required);
        const sw = (p.s / req * 100).toFixed(2);
        const nw = (p.nys / req * 100).toFixed(2);
        const status = !st ? 'notstarted' : st.attempt.status === 'finalised' ? (st.attempt.decision === 'competent' ?
            'competent' : 'nyc') : 'inprogress';
        let left = '';
        if (this.isAssessor) {
            left += '<a class="ec-iconbtn" href="' + esc(this.cfg.urls.list) + '" title="' + esc(this.str('classlist')) +
                '" aria-label="' + esc(this.str('classlist')) + '">' + ICON.back + '</a>';
        }
        const name = st ? st.student.name : '';
        left += '<div class="ec-head-titles"><div class="ec-head-name">' + esc(this.isAssessor ? name : this.s.name) + '</div>' +
            '<div class="ec-head-sub">' + esc(this.isAssessor ? this.s.name : (this.s.unitcode + ' ' + this.s.unittitle)) +
            (st && st.attempt.no > 1 ? ' · ' + esc(this.str('attemptn', st.attempt.no)) : '') + '</div></div>' +
            '<span class="ec-pill ec-pill-sm ec-pill-' + status + '">' + esc(this.str(status === 'inprogress' ? 'inprogress' :
                status === 'notstarted' ? 'notstarted' : 'decision_' + status)) + '</span>';
        const nav = this.cfg.nav;
        if (this.isAssessor && nav && nav.total > 1) {
            const navbtn = (to, key, icon) => to ? '<a class="ec-iconbtn ec-iconbtn-sm" href="' + esc(to.url) + '" title="' +
                esc(this.str(key) + ': ' + to.name) + ' (Shift+' + (key === 'prevstudent' ? '←' : '→') + ')" aria-label="' +
                esc(this.str(key) + ': ' + to.name) + '">' + icon + '</a>' :
                '<span class="ec-iconbtn ec-iconbtn-sm ec-iconbtn-off" aria-hidden="true">' + icon + '</span>';
            left += '<div class="ec-stunav">' + navbtn(nav.prev, 'prevstudent', ICON.back) + '<span class="ec-stunav-pos">' +
                esc(this.str('studentpos', nav)) + '</span>' + navbtn(nav.next, 'nextstudent', ICON.next) +
                (nav.open ? '<a class="ec-btn ec-btn-sm ec-nextopen" href="' + esc(nav.open.url) + '" title="' +
                    esc(this.str('sc_nextopen') + ': ' + nav.open.name) + ' (Shift+N)">' + esc(this.str('nextopen')) + ' ' +
                    ICON.next + '</a>' : '') + '</div>';
        }
        let right = '';
        if (this.isAssessor) {
            right += '<button type="button" class="ec-switch' + (this.cfg.tips ? ' ec-on' : '') + '" data-act="tips" ' +
                'role="switch" aria-checked="' + (this.cfg.tips ? 'true' : 'false') + '">' + ICON.bulb +
                '<span>' + esc(this.str('tips')) + '</span><span class="ec-switch-knob"></span></button>';
            right += '<button type="button" class="ec-btn ec-btn-ghost" data-act="guide">' + esc(this.str('guide')) + '</button>';
            if (this.editable) {
                right += '<button type="button" class="ec-btn ec-btn-ghost ec-kbdbtn" data-act="shortcuts" title="' +
                    esc(this.str('shortcuts_title')) + ' (?)">' + ICON.kbd + '<span>' + esc(this.str('shortcuts')) +
                    '</span><kbd>?</kbd></button>';
            }
        }
        if (st && st.attempt.id) {
            right += '<a class="ec-btn" href="' + esc(this.cfg.urls.report) + '">' + esc(this.str('viewreport')) + '</a>';
        }
        if (this.editable && this.cfg.canfinalise) {
            right += '<button type="button" class="ec-btn ec-btn-primary" data-act="signoff">' + esc(this.str('signoff')) +
                '</button>';
        }
        const label = this.str('progresslabel', p);
        return '<div class="ec-head"><div class="ec-head-row"><div class="ec-head-left">' + left + '</div>' +
            '<div class="ec-head-right">' + right + '</div></div>' +
            '<div class="ec-head-progress"><div class="ec-bar' + (p.complete ? ' ec-bar-done' : '') + '" role="progressbar" ' +
            'aria-valuemin="0" aria-valuemax="100" aria-valuenow="' + p.percent + '" aria-label="' + esc(label) + '">' +
            '<span class="ec-bar-s" style="width:' + sw + '%"></span><span class="ec-bar-nys" style="width:' + nw + '%"></span>' +
            '</div><div class="ec-head-label"><span class="ec-live" title="Live"></span>' +
            (st && st.attempt.status === 'finalised' ? '<strong class="ec-ready">' + esc(this.str('finalised')) + ' ' +
                esc(fmt(st.attempt.timefinalised, false)) + '</strong> · ' + esc(label) :
                p.complete ? '<strong class="ec-ready">✓ ' + esc(this.str('readyforsignoff')) + '</strong>' : esc(label)) +
            '<span class="ec-head-pct">' + (st && !p.hidden ? Math.round(p.s / req * 100) + '%' : '') + '</span>' +
            '</div></div></div>';
    }

    /**
     * Phone action bar (hidden above 640px).
     *
     * @returns {String}
     */
    bottomBar() {
        const st = this.state;
        let html = '';
        if (this.isAssessor) {
            html += '<button type="button" class="ec-iconbtn" data-act="guide" aria-label="' + esc(this.str('guide')) + '">' +
                ICON.bulb + '</button>';
        }
        if (st && st.attempt.id) {
            html += '<a class="ec-btn" href="' + esc(this.cfg.urls.report) + '">' + esc(this.str('viewreport')) + '</a>';
        }
        if (this.editable && this.cfg.canfinalise) {
            html += '<button type="button" class="ec-btn ec-btn-primary" data-act="signoff">' + esc(this.str('signoff')) +
                '</button>';
        }
        return html ? '<div class="ec-bottombar">' + html + '</div>' : '';
    }

    /**
     * Tip card (if tips are on and not dismissed).
     *
     * @param {String} id
     * @param {Boolean} force show even if dismissed (guide)
     * @returns {String}
     */
    tip(id, force = false) {
        if (!this.isAssessor || (!force && (!this.cfg.tips || this.cfg.dismissed.indexOf(id) !== -1))) {
            return '';
        }
        if (force) {
            return '<div class="ec-card ec-tip" data-tip="' + id + '"><div class="ec-tip-icon">' + ICON.bulb + '</div>' +
                '<div class="ec-tip-body"><div class="ec-tip-title">' + esc(this.str('tip_' + id + '_t')) + '</div>' +
                '<div class="ec-tip-text">' + esc(this.str('tip_' + id)) + '</div>' +
                '<a class="ec-tip-link" target="_blank" rel="noopener" href="' + esc(LINKS[id] || '#') + '">' +
                esc(this.str('learnmore')) + '</a></div></div>';
        }
        return '<details class="ec-tipchip" data-tip="' + id + '"><summary>' + ICON.bulb +
            esc(this.str('tip_' + id + '_t')) + '</summary><div class="ec-tippop"><div class="ec-tippop-title">' +
            esc(this.str('tip_' + id + '_t')) + '</div><div>' + esc(this.str('tip_' + id)) + '</div>' +
            '<div class="ec-tippop-foot"><a class="ec-tip-link" target="_blank" rel="noopener" href="' +
            esc(LINKS[id] || '#') + '">' + esc(this.str('learnmore')) + '</a><button type="button" class="ec-tip-dismiss" ' +
            'data-act="dismisstip" data-tip="' + id + '">' + esc(this.str('gotit')) + '</button></div></div></details>';
    }

    /**
     * A row of tip chips.
     *
     * @param {String[]} ids
     * @returns {String}
     */
    tips(ids) {
        const html = ids.map(id => this.tip(id)).join('');
        return html ? '<div class="ec-tiprow">' + html + '</div>' : '';
    }

    /**
     * Help icon with advice text, shown on hover, focus or tap.
     *
     * @param {String} key string key without the help_ prefix
     * @returns {String}
     */
    help(key) {
        const text = this.cfg.strings['help_' + key];
        if (!text) {
            return '';
        }
        return '<span class="ec-help" tabindex="0" role="button" aria-label="' + esc(this.str('help') + ': ' + text) + '">' +
            ICON.info + '<span class="ec-help-pop">' + esc(text) + '</span></span>';
    }

    /**
     * Final result card.
     *
     * @returns {String}
     */
    resultCard() {
        const st = this.state;
        const comp = st.attempt.decision === 'competent';
        let html = '<div class="ec-card ec-result ec-result-' + (comp ? 'good' : 'gap') + '">' +
            '<div class="ec-result-head"><div class="ec-result-badge">' + (comp ? ICON.S : ICON.NYS) + '</div><div>' +
            '<div class="ec-result-title">' + esc(this.str('decision_' + st.attempt.decision)) + '</div>' +
            '<div class="ec-result-sub">' + esc(this.str('finalised')) + ' ' + esc(fmt(st.attempt.timefinalised)) + '</div>' +
            '</div></div>';
        if (st.attempt.feedback) {
            html += '<div class="ec-result-feedback">' + esc(st.attempt.feedback).replace(/\n/g, '<br>') + '</div>';
        }
        html += '<div class="ec-sigs">' + st.signatures.map(sg => '<div class="ec-sig"><div class="ec-sig-role">' +
            esc(this.str(sg.role === 'student' ? 'studentsignature' : 'assessorsignature')) + '</div>' +
            (sg.url ? '<img src="' + esc(sg.url) + '" alt="">' : '<div class="ec-sig-typed">' + esc(sg.typed) + '</div>') +
            '<div class="ec-sig-meta">' + esc(sg.name) + ' · ' + esc(fmt(sg.time)) + '</div></div>').join('') + '</div>';
        const studentsigned = st.signatures.some(sg => sg.role === 'student');
        if (!this.isAssessor && !studentsigned) {
            html += '<div class="ec-ack"><div class="ec-ack-label">' + esc(this.str('acknowledge')) + ' ' + this.help('ack') +
                '</div>' +
                '<canvas class="ec-sigpad" data-pad="ack"></canvas><div class="ec-actions">' +
                '<button type="button" class="ec-btn ec-btn-ghost" data-act="clearpad" data-pad="ack">' +
                esc(this.str('clear')) + '</button><button type="button" class="ec-btn ec-btn-primary" data-act="ack">' +
                esc(this.str('sign')) + '</button></div></div>';
        }
        html += '<div class="ec-actions"><a class="ec-btn" href="' + esc(this.cfg.urls.pdf) + '">' +
            esc(this.str('downloadpdf')) + '</a>';
        if (this.cfg.role === 'assessor' && !comp) {
            html += '<button type="button" class="ec-btn ec-btn-primary" data-act="reassess">' + esc(this.str('reassess')) +
                '</button>';
        }
        return html + '</div></div>';
    }

    /**
     * Pre-assessment confirmation card. Students tick first-person statements; assessors see the answers live,
     * and can hand their device over or ask the questions aloud when the candidate has no device.
     *
     * @returns {String}
     */
    confirmCard() {
        const st = this.state;
        if (!st || !st.confirm.statements.length) {
            if (!st && !this.isAssessor) {
                return '<div class="ec-card ec-callout">' + esc(this.str('notstarted')) + '</div>';
            }
            return '';
        }
        if (this.isAssessor) {
            return this.confirmCardAssessor();
        }
        const done = st.confirm.done;
        const editable = st.attempt.status === 'inprogress';
        const open = !done || this.confirmOpen;
        let html = '<section class="ec-card ec-confirm' + (done ? ' ec-done' : '') + '">' +
            '<header class="ec-card-head" data-act="toggleconfirm"><div><h3>' + esc(this.str('preassessment')) + '</h3>' +
            '<div class="ec-card-sub">' + esc(done ? this.str('confirmsaved') : this.str('preassessment_intro')) + '</div></div>' +
            '<span class="ec-pill ec-pill-sm ' + (done ? 'ec-pill-competent' : 'ec-pill-notstarted') + '">' +
            esc(done ? this.str('signed') : this.str('confirmneeded')) + '</span></header>';
        if (open) {
            html += this.statementBoxes(st.confirm.statements, editable, 'text');
            html += this.noteField(editable);
            if (editable) {
                html += '<div class="ec-actions"><button type="button" class="ec-btn ec-btn-ghost" data-act="confirmall">' +
                    esc(this.str('confirmall')) + '</button><button type="button" class="ec-btn ec-btn-primary" ' +
                    'data-act="saveconfirm">' + esc(this.str('save')) + '</button></div>';
            }
        }
        return html + '</section>';
    }

    /**
     * Statement checkboxes.
     *
     * @param {Array} list
     * @param {Boolean} editable
     * @param {String} field text (first person) or question (assessor voice)
     * @returns {String}
     */
    statementBoxes(list, editable, field) {
        return '<div class="ec-statements">' + list.map(c => {
            const on = c.response === 1;
            return '<label class="ec-statement' + (on ? ' ec-on' : '') + '"><input type="checkbox" data-confirm="' + c.no +
                '"' + (on ? ' checked' : '') + (editable ? '' : ' disabled') + '><span class="ec-check"></span>' +
                '<span class="ec-statement-text">' + (field === 'question' ? '<span class="ec-statement-kicker">' +
                esc(this.str('askcandidate')) + '</span>' : '') + c[field] + '</span></label>';
        }).join('') + '</div>';
    }

    /**
     * Reasonable adjustment note.
     *
     * @param {Boolean} editable
     * @returns {String}
     */
    noteField(editable) {
        const withnote = this.state.confirm.statements.find(c => c.note) || {};
        return '<label class="ec-field"><span>' + esc(this.str('adjustmentnote')) + ' ' + this.help('adjustmentnote') +
            '</span><textarea rows="2" class="ec-input" data-confirm-note' + (editable ? '' : ' disabled') + '>' +
            esc(withnote.note || '') + '</textarea></label>';
    }

    /**
     * Assessor's confirmation card: live status, or the spoken questions.
     *
     * @returns {String}
     */
    confirmCardAssessor() {
        const st = this.state;
        const list = st.confirm.statements;
        const done = st.confirm.done;
        const ticked = list.filter(c => c.response === 1).length;
        const name = st.student.name;
        const last = list.reduce((m, c) => (c.time > (m ? m.time : 0) ? c : m), null);
        const by = last && last.time ? this.str('confirm_by', {who: last.who || name,
            how: this.str('method_' + (last.method || 'own')), time: fmt(last.time)}) : '';
        if (this.confirmMode === 'verbal' && this.editable) {
            return '<section class="ec-card ec-confirm ec-confirm-verbal"><header class="ec-card-head"><div><h3>' +
                esc(this.str('preassessment')) + '</h3><div class="ec-card-sub">' + esc(this.str('confirm_verbal_intro')) + ' ' +
                this.help('verbal') + '</div></div></header>' + this.statementBoxes(list, true, 'question') +
                this.noteField(true) + '<div class="ec-actions"><button type="button" class="ec-btn ec-btn-ghost" ' +
                'data-act="confirmmode" data-mode="">' + esc(this.str('confirm_back')) + '</button><button type="button" ' +
                'class="ec-btn ec-btn-primary" data-act="saveconfirm" data-method="verbal">' +
                esc(this.str('confirm_verbal_save')) + '</button></div></section>';
        }
        const open = !done || this.confirmOpen;
        let html = '<section class="ec-card ec-confirm' + (done ? ' ec-done' : '') + '"><header class="ec-card-head"' +
            (done ? ' data-act="toggleconfirm"' : '') + '><div><h3>' + esc(this.str('preassessment')) + '</h3>' +
            '<div class="ec-card-sub">' + esc(done ? by : this.str('confirm_waiting_sub')) + '</div></div>' +
            '<span class="ec-pill ec-pill-sm ' + (done ? 'ec-pill-competent' : 'ec-pill-notstarted ec-pill-wait') + '">' +
            esc(done ? this.str('signed') : ticked ? this.str('confirm_partial', {done: ticked, total: list.length}) :
                this.str('confirm_waiting', name)) + '</span></header>';
        const note = (list.find(c => c.note) || {}).note;
        const notebox = note ? '<div class="ec-bench-box ec-adjnote"><div class="ec-label">' + esc(this.str('adjustmentnote')) +
            '</div>' + esc(note) + '</div>' : '';
        if (!open) {
            // The adjustment the candidate asked for stays in view even when the card is folded.
            return html + notebox + '</section>';
        }
        html += '<ul class="ec-confirmlist">' + list.map(c => '<li class="' + (c.response === 1 ? 'ec-on' : '') + '">' +
            '<span class="ec-confirmlist-icon">' + (c.response === 1 ? ICON.S : '') + '</span><span>' + c.text +
            (c.time && c.response === 1 ? '<span class="ec-statement-meta">' + esc(fmt(c.time)) + '</span>' : '') +
            '</span></li>').join('') + '</ul>';
        html += notebox;
        if (this.editable && !done) {
            html += '<div class="ec-actions"><button type="button" class="ec-btn" data-act="handover">' +
                esc(this.str('confirm_handover')) + '</button>' + this.help('handover') +
                '<button type="button" class="ec-btn ec-btn-ghost" data-act="confirmmode" data-mode="verbal">' +
                esc(this.str('confirm_verbal')) + '</button>' + this.help('verbal') + '</div>';
        }
        html += '<div class="ec-actions">' + this.tips(['principles']) + '</div>';
        return html + '</section>';
    }

    /**
     * Hand the assessor's device to the candidate: first-person statements in a full-screen sheet.
     */
    openHandover() {
        const st = this.state;
        const body = '<p class="ec-sc-intro">' + esc(this.str('confirm_forcandidate_intro')) + '</p>' +
            this.statementBoxes(st.confirm.statements, true, 'text') + this.noteField(true) +
            '<div class="ec-actions ec-actions-end"><button type="button" class="ec-btn ec-btn-ghost" data-act="confirmall">' +
            esc(this.str('confirmall')) + '</button><button type="button" class="ec-btn ec-btn-primary ec-btn-lg" ' +
            'data-handover-save>' + esc(this.str('confirm_iconfirm')) + '</button></div>';
        const m = this.modal(this.str('confirm_forcandidate') + ' · ' + st.student.name, body, 'ec-modal-wide ec-modal-handover');
        m.addEventListener('change', e => {
            const cb = e.target.closest('[data-confirm]');
            if (cb) {
                cb.closest('.ec-statement').classList.toggle('ec-on', cb.checked);
            }
        });
        m.addEventListener('click', async e => {
            if (e.target.closest('[data-act="confirmall"]')) {
                m.querySelectorAll('[data-confirm]').forEach(cb => {
                    cb.checked = true;
                    cb.closest('.ec-statement').classList.add('ec-on');
                });
            }
            if (e.target.closest('[data-handover-save]')) {
                const ticked = [...m.querySelectorAll('[data-confirm]')].filter(cb => cb.checked).map(cb => cb.dataset.confirm);
                const note = (m.querySelector('[data-confirm-note]') || {}).value || '';
                await this.call('save_confirm', {responses: ticked.join(','), note, method: 'handover'});
                m.close();
                this.toast(this.str('saved'));
            }
        });
    }

    /**
     * Session details and identity card (assessor).
     *
     * @returns {String}
     */
    sessionCard() {
        const st = this.state;
        const editable = this.editable;
        const today = this.todaySession();
        const last = st.sessions && st.sessions.length ? st.sessions[0] : null;
        if (!today && !last && !editable) {
            return '';
        }
        // A past session is shown as a summary; assessors can start today's from it.
        const past = !today && last && (!editable || !this.sessionOpen);
        const sess = today || (past ? last : {});
        const ref = st.verifyid;
        let badge;
        if (sess.idmethod === 'verifyid') {
            badge = '<span class="ec-idbadge ec-idbadge-ok">' + ICON.shield + esc(this.str('verifyidmatch')) + '</span>';
        } else if (sess.idmethod === 'sighted') {
            badge = '<span class="ec-idbadge ec-idbadge-ok">' + ICON.shield + esc(this.str('idsighted')) +
                (sess.iddocument ? ' · ' + esc(sess.iddocument) : '') + '</span>';
        } else {
            badge = '<span class="ec-idbadge">' + esc(this.str('idnone')) + '</span>';
        }
        if (past || (sess.photo && sess.idmethod && !this.sessionOpen)) {
            return '<section class="ec-card ec-session ec-session-compact">' + (sess.photo ? '<div class="ec-session-thumb">' +
                '<img src="' + esc(sess.photo) + '" alt="">' + (sess.idmethod ? '<span class="ec-session-tick">' + ICON.S +
                '</span>' : '') + '</div>' : '') +
                '<div class="ec-session-sum"><h3>' + esc(this.str(past ? 'lastsession' : 'session')) +
                '</h3><div class="ec-card-sub">' +
                esc(fmt(sess.time, false)) + (sess.assessor ? ' · ' + esc(sess.assessor) : '') + ' · ' +
                esc(this.str(sess.simulated === false ? 'workplace' : 'simulated')) + '</div></div>' + badge.replace('ec-idbadge',
                'ec-idbadge ec-idbadge-inline') + (past && !editable ? '' : '<button type="button" class="ec-btn ec-btn-sm" ' +
                'data-act="togglesession">' +
                esc(this.str(past ? 'newsession' : (editable ? 'edit' : 'view'))) + '</button>') + '</section>';
        }
        let html = '<section class="ec-card ec-session"><header class="ec-card-head"><div><h3>' + esc(this.str('session')) +
            '</h3><div class="ec-card-sub">' + esc(fmt(sess.time || Math.floor(Date.now() / 1000), false)) +
            (sess.assessor ? ' · ' + esc(sess.assessor) : '') + '</div></div></header><div class="ec-session-body">';
        html += '<div class="ec-photo"><div class="ec-label">' + esc(this.str('photosection')) + ' ' + this.help('photo') +
            '</div><div class="ec-photo-frame">' + (sess.photo ? '<img src="' + esc(sess.photo) +
            '" alt="">' : '<div class="ec-photo-empty">' + ICON.cam + '<span>' + esc(this.str('photocandidate')) +
            '</span></div>') + badge + '</div>';
        if (editable) {
            html += '<label class="ec-btn ec-btn-sm ec-filebtn">' + ICON.cam + ' ' + esc(this.str('photocandidate')) +
                '<input type="file" accept="image/*" capture="environment" data-upload="sessionphoto" hidden></label>';
        }
        html += '</div><div class="ec-session-fields">';
        html += '<div class="ec-label">' + esc(this.str('identity')) + ' ' + this.help(ref ? 'verifyid' : 'idsighted') + '</div>';
        html += '<div class="ec-idrow">';
        if (ref) {
            html += '<div class="ec-idref">' + (ref.photo ? '<img src="' + esc(ref.photo) + '" alt="">' : '') +
                '<div><div class="ec-idref-t">' + esc(this.str('verifyidref')) + '</div><div class="ec-idref-m">' +
                esc(fmt(ref.time, false)) + '</div></div></div>';
            if (editable) {
                html += '<button type="button" class="ec-btn ec-btn-sm' + (sess.idmethod === 'verifyid' ? ' ec-btn-good' : '') +
                    '" data-act="idverify">✓ ' + esc(this.str('verifyidmatch')) + '</button>';
            }
        }
        if (editable) {
            html += '<div class="ec-inline"><input type="text" class="ec-input ec-input-sm" data-sess="iddocument" ' +
                'placeholder="' + esc(this.str('iddocument')) + '" aria-label="' + esc(this.str('iddocument')) + '" value="' +
                esc(sess.iddocument || '') + '">' + this.help('iddocument') +
                '<button type="button" class="ec-btn ec-btn-sm' + (sess.idmethod === 'sighted' ? ' ec-btn-good' : '') +
                '" data-act="idsighted">✓ ' + esc(this.str('idsighted')) + '</button></div>';
        }
        html += '</div>';
        const dis = editable ? '' : ' disabled';
        html += '<div class="ec-label">' + esc(this.str('assessmenttype')) + ' ' +
            this.help('simulated') + '</div>';
        html += '<div class="ec-seg" role="group"><button type="button" class="' + (sess.simulated !== false ? 'ec-on' : '') +
            '" data-act="sim" data-sim="1"' + dis + '>' + esc(this.str('simulated')) + '</button><button type="button" class="' +
            (sess.simulated === false ? 'ec-on' : '') + '" data-act="sim" data-sim="0"' + dis + '>' +
            esc(this.str('workplace')) + '</button></div>';
        html += '<label class="ec-field"><span>' + esc(this.str('settingfield')) + ' ' + this.help('setting') +
            '</span><input class="ec-input" ' +
            'data-sess="setting" value="' + esc(sess.setting || '') + '"' + dis + '></label>';
        html += '<label class="ec-field"><span>' + esc(this.str('equipmentfield')) + ' ' + this.help('equipment') +
            '</span><input class="ec-input" ' +
            'data-sess="equipment" value="' + esc(sess.equipment || '') + '"' + dis + '></label>';
        html += '<label class="ec-field"><span>' + esc(this.str('adjustmentsfield')) + ' ' + this.help('adjustments') +
            '</span><input class="ec-input" ' +
            'data-sess="adjustments" value="' + esc(sess.adjustments || '') + '"' + dis + '></label>';
        if (editable) {
            html += '<div class="ec-actions"><button type="button" class="ec-btn ec-btn-primary ec-btn-sm" ' +
                'data-act="savesession">' + esc(this.str('save')) + '</button></div>';
        }
        html += '</div></div><div class="ec-actions">' + this.tips(['identity', 'rules', 'simulation']) + '</div></section>';
        return html;
    }

    /**
     * The marking grid.
     *
     * @returns {String}
     */
    grid() {
        const st = this.state;
        const cols = this.s.columns;
        const cells = st ? st.cells : {};
        const prog = st && st.progress ? st.progress : {groups: {}, columns: {}};
        const canmark = this.canMark;
        let html = '';
        if (this.editable && !canmark) {
            if (st.confirm.done) {
                html += '<div class="ec-card ec-callout">' + esc(this.str('requiredphoto')) + '</div>';
            } else {
                // Each attempt starts with the candidate's confirmation; offer the two ways to get it right here.
                html += '<div class="ec-card ec-callout ec-callout-lock"><div><strong>' +
                    esc(this.str(st.attempt.no > 1 ? 'confirmfirst_attempt' : 'confirmfirst', st.attempt.no)) + '</strong>' +
                    '<div class="ec-card-sub">' + esc(this.str('confirmfirst_how')) + '</div></div><div class="ec-actions">' +
                    '<button type="button" class="ec-btn ec-btn-primary" data-act="confirmmode" data-mode="verbal">' +
                    esc(this.str('confirm_verbal')) + '</button><button type="button" class="ec-btn" data-act="handover">' +
                    esc(this.str('confirm_handover')) + '</button></div></div>';
            }
        }
        const pad = this.editable;
        const tpl = 'grid-template-columns: minmax(240px, 1fr) repeat(' + cols.length + ', var(--ec-cellw))' +
            (pad ? ' var(--ec-padw)' : '') + ';';
        html += '<div class="ec-gridwrap"><div class="ec-grid ec-cols-' + Math.min(cols.length, 7) + '">';
        html += '<div class="ec-colhead" style="' + tpl + '"><div class="ec-colhead-crit">' +
            (canmark && this.s.allowbulk ? '<button type="button" class="ec-btn ec-btn-sm ec-btn-good" data-act="fill" ' +
                'data-scope="all" data-value="S">✓ ' + esc(this.str('alls')) + '</button>' : '') + '</div>';
        cols.forEach(c => {
            const cp = prog.columns[c.id] || {required: 0, s: 0, nys: 0};
            const pct = cp.required ? Math.round(cp.s / cp.required * 100) : 0;
            html += '<div class="ec-colhead-cell"><span class="ec-colhead-label">' + esc(c.label) + '</span>' +
                '<span class="ec-minibar"><span style="width:' + pct + '%"></span></span>' +
                (canmark && this.s.allowbulk ? '<button type="button" class="ec-colfill" data-act="fill" data-scope="col:' +
                    esc(c.id) + '" data-value="S" title="' + esc(this.str('columnfill')) + '">✓ S</button>' : '') + '</div>';
        });
        if (pad) {
            html += '<div class="ec-colhead-cell ec-colhead-pad"><span class="ec-colhead-label">' + esc(this.str('quickpad')) +
                '</span></div>';
        }
        html += '</div>';
        this.s.groups.forEach(g => {
            const gp = prog.groups[g.id] || {required: 0, s: 0, nys: 0};
            const gpct = gp.required ? Math.round(gp.s / gp.required * 100) : 0;
            html += '<section class="ec-card ec-group' + (gp.required && gp.s === gp.required ? ' ec-state-complete' :
                gp.nys ? ' ec-state-gap' : '') + '" data-group="' + g.id + '"><header class="ec-group-head">' +
                '<span class="ec-num ec-num-lg">' + esc(g.number) + '</span><h3>' + g.name + '</h3>' +
                '<span class="ec-group-progress"><span class="ec-minibar ec-minibar-lg"><span style="width:' + gpct +
                '%"></span></span><span class="ec-group-count">' + gp.s + '/' + gp.required + '</span></span>' +
                (gp.nys ? '<span class="ec-group-gaps">' + esc(gp.nys === 1 ? this.str('gapone') :
                    this.str('gapsn', gp.nys)) + '</span>' : '') +
                (canmark && this.s.allowbulk ? '<button type="button" class="ec-btn ec-btn-sm ec-btn-good" data-act="fill" ' +
                    'data-scope="group:' + g.id + '" data-value="S" title="' + esc(this.str('groupfill')) + '">✓ ' +
                    esc(this.str('alls')) + '</button>' : '') + '</header>';
            g.items.forEach(it => {
                html += '<div class="ec-row" style="' + tpl + '" data-item="' + it.id + '">';
                html += '<div class="ec-crit" data-act="item" data-item="' + it.id + '" tabindex="0" role="button">' +
                    '<span class="ec-num">' + esc(it.number) + '</span><div class="ec-crit-main">' +
                    '<div class="ec-crit-text" title="' +
                    esc(this.stripTags(it.criterion)) + '">' +
                    it.criterion + '</div><div class="ec-crit-meta">' +
                    (it.verbal ? '<span class="ec-tag ec-tag-verbal">' + ICON.mic + esc(this.str('verbal')) + '</span>' : '') +
                    (!it.mandatory ? '<span class="ec-tag">' + esc(this.str('optional')) + '</span>' : '') +
                    (it.refs ? it.refs.split(/,\s*/).map(r => '<span class="ec-chip">' + esc(r) + '</span>').join('') : '') +
                    (it.benchmark ? '<span class="ec-bench" title="' + esc(this.stripTags(it.benchmark)) + '">' + ICON.info +
                        esc(this.str('benchmark')) + '</span>' : '') +
                    (st && st.gaps[it.id] && st.gaps[it.id].status === 'open' ? '<span class="ec-tag ec-tag-gap">' +
                        esc(this.str('gapplan')) + '</span>' : '') +
                    (st && st.gaps[it.id] && st.gaps[it.id].status === 'closed' ? '<span class="ec-tag ec-tag-gapclosed">✓ ' +
                        esc(this.str('gapclosedtag')) + '</span>' : '') +
                    '</div></div></div>';
                cols.forEach(c => {
                    if (it.cells.indexOf(c.id) === -1) {
                        html += '<div class="ec-cell ec-cell-na" aria-hidden="true"></div>';
                        return;
                    }
                    const cell = cells[it.id + '#' + c.id] || null;
                    const v = cell ? cell.r : '';
                    const cls = v ? 'ec-cell-' + v : 'ec-cell-empty';
                    const marks = (cell && cell.media && cell.media.length ? '<span class="ec-cell-mark">' + ICON.cam + '</span>' :
                        '') + (cell && (cell.c || cell.note) ? '<span class="ec-cell-mark">' + ICON.note + '</span>' : '') +
                        (cell && cell.bulk && this.isAssessor ? '<span class="ec-cell-flag" title="' +
                            esc(this.str('bulkmarker')) + '"></span>' : '') +
                        (cell && cell.carried ? '<span class="ec-cell-flag ec-cell-flag-carried" title="' +
                            esc(this.str('carriedmarker')) + '"></span>' : '');
                    const label = it.number + ' ' + c.label + ': ' + (v ? this.str(v === 'NO' ? 'notobserved' : v.toLowerCase()) :
                        '—');
                    const act = this.active && this.active.itemid === it.id && this.active.cellid === c.id;
                    html += '<button type="button" class="ec-cell ' + cls + (canmark ? '' : ' ec-readonly') +
                        (it.once ? ' ec-cell-once' : '') + (act ? ' ec-active' : '') + '" data-act="cell" ' +
                        'data-item="' + it.id + '" data-cell="' + esc(c.id) + '" aria-label="' + esc(label) + '">' +
                        '<span class="ec-cell-col">' + esc(it.once ? this.str('observedonce') : c.label) + '</span>' +
                        '<span class="ec-cell-face">' + (v ? ICON[v] : '') + '<span class="ec-cell-text">' +
                        (v === 'NO' ? esc(this.str('notobserved_short')) : esc(v)) + '</span></span>' + marks +
                        (canmark ? '<span class="ec-cell-more" data-act="openpanel" title="' + esc(this.str('cellhint')) + '">' +
                            ICON.more + '</span>' : '') + '</button>';
                });
                if (pad) {
                    html += this.padHtml(it, canmark);
                }
                html += '</div>';
            });
            html += '</section>';
        });
        html += '</div></div>';
        if (canmark) {
            html += '<div class="ec-hint">' + esc(this.str('longpresshint')) + '</div>';
        }
        return html;
    }

    /**
     * The 2x2 evidence pad at the end of a row: photo, video, audio and comment for the row's current cell.
     *
     * @param {Object} it
     * @param {Boolean} canmark
     * @returns {String}
     */
    padHtml(it, canmark) {
        const cellid = this.padCell(it);
        const cell = this.state && this.state.cells[it.id + '#' + cellid];
        const media = [];
        it.cells.forEach(cid => {
            const c = this.state && this.state.cells[it.id + '#' + cid];
            (c && c.media ? c.media : []).forEach(m => media.push(m.type));
        });
        const hascomment = it.cells.some(cid => {
            const c = this.state && this.state.cells[it.id + '#' + cid];
            return c && (c.c || c.note);
        });
        const target = this.cellName(it, cellid);
        const recording = this.quickRec && this.quickRec.itemid === it.id;
        const btn = (kind, icon, has, key, shortcut) => {
            const label = this.str('pad_for', {what: this.str(key), cell: target});
            return '<button type="button" class="ec-pad-btn ec-pad-' + kind + (has ? ' ec-has' : '') +
                (kind === 'audio' && recording ? ' ec-recording' : '') + '" data-act="pad" data-kind="' + kind +
                '" data-item="' + it.id + '"' + (canmark ? '' : ' disabled') + ' title="' + esc(label + ' (' + shortcut + ')') +
                '" aria-label="' + esc(label) + '">' + icon + '</button>';
        };
        return '<div class="ec-pad" data-item="' + it.id + '">' +
            btn('photo', ICON.cam, media.indexOf('image') !== -1, 'pad_photo', 'P') +
            btn('video', ICON.video, media.indexOf('video') !== -1, 'pad_video', 'V') +
            btn('audio', recording ? ICON.stop : ICON.mic, media.indexOf('audio') !== -1, recording ? 'pad_audio_stop' :
                'pad_audio', 'A') +
            btn('comment', ICON.note, !!hascomment || !!(cell && cell.c), 'pad_comment', 'C') + '</div>';
    }

    /**
     * Which cell of a row the evidence pad works on: the active cell, the last one tapped, or the first.
     *
     * @param {Object} it
     * @returns {String}
     */
    padCell(it) {
        if (this.active && this.active.itemid === it.id) {
            return this.active.cellid;
        }
        return this.rowCell[it.id] && it.cells.indexOf(this.rowCell[it.id]) !== -1 ? this.rowCell[it.id] : it.cells[0];
    }

    /**
     * "1.2 Adult" style name for a cell.
     *
     * @param {Object} it
     * @param {String} cellid
     * @returns {String}
     */
    cellName(it, cellid) {
        if (it.once || it.cells.length === 1 && this.s.columns.length === 1) {
            return it.number;
        }
        const col = this.s.columns[this.colIndex[cellid]] || {label: ''};
        return it.number + ' ' + col.label;
    }

    /**
     * Strip tags for title attributes.
     *
     * @param {String} html
     * @returns {String}
     */
    stripTags(html) {
        const d = document.createElement('div');
        d.innerHTML = html;
        return d.textContent || '';
    }

    /**
     * Wire up per-render elements.
     */
    afterRender() {
        this.root.querySelectorAll('canvas[data-pad]').forEach(c => {
            c.pad = new SignaturePad(c);
        });
        this.measureHead();
    }

    /**
     * Keep the column header just under the sticky header.
     */
    measureHead() {
        const head = this.root.querySelector('.ec-head');
        const nav = document.querySelector('.navbar.fixed-top, nav.navbar');
        const navh = nav ? nav.getBoundingClientRect().height : 60;
        if (head) {
            this.root.style.setProperty('--ec-navh', Math.round(navh) + 'px');
            this.root.style.setProperty('--ec-headh', Math.round(head.getBoundingClientRect().height) + 'px');
        }
    }

    // Events.

    /**
     * Delegate events on the root (bound once).
     */
    bindRoot() {
        // When the page scrolls, the header and the column header join into one slim sticky bar.
        let ticking = false;
        const onScroll = () => {
            ticking = false;
            const y = window.scrollY;
            const stuck = this.root.classList.contains('ec-stuck');
            if (!stuck && y > 140 || stuck && y < 40) {
                this.root.classList.toggle('ec-stuck', !stuck);
                this.measureHead();
            }
        };
        window.addEventListener('scroll', () => {
            if (!ticking) {
                ticking = true;
                requestAnimationFrame(onScroll);
            }
        }, {passive: true});
        window.addEventListener('resize', () => this.measureHead());
        let pressTimer = null;
        let longPressed = false;
        let swipe = null;
        this.root.addEventListener('pointerdown', e => {
            this.root.classList.remove('ec-kbd');
            const cell = e.target.closest('[data-act="cell"]');
            if (!cell || !this.canMark || e.target.closest('[data-act="openpanel"]')) {
                return;
            }
            swipe = e.pointerType === 'mouse' ? null : {x: e.clientX, y: e.clientY, cell};
            longPressed = false;
            pressTimer = setTimeout(() => {
                longPressed = true;
                this.openPanel(+cell.dataset.item, cell.dataset.cell);
            }, LONGPRESS_MS);
        });
        const cancel = () => {
            clearTimeout(pressTimer);
        };
        // Touch: swipe a cell right for S, left for NYS.
        this.root.addEventListener('pointermove', e => {
            if (swipe && Math.abs(e.clientX - swipe.x) > 12) {
                clearTimeout(pressTimer);
            }
        });
        this.root.addEventListener('pointerup', e => {
            if (swipe) {
                const dx = e.clientX - swipe.x;
                const dy = e.clientY - swipe.y;
                if (Math.abs(dx) > 45 && Math.abs(dy) < 30) {
                    const c = swipe.cell;
                    longPressed = true;
                    this.mark(+c.dataset.item, c.dataset.cell, dx > 0 ? 'S' : 'NYS');
                }
                swipe = null;
            }
        });
        this.root.addEventListener('pointerup', cancel);
        this.root.addEventListener('pointerleave', cancel, true);
        this.root.addEventListener('pointercancel', cancel);
        this.root.addEventListener('contextmenu', e => {
            const cell = e.target.closest('[data-act="cell"]');
            if (cell) {
                e.preventDefault();
                this.openPanel(+cell.dataset.item, cell.dataset.cell);
            }
        });
        this.root.addEventListener('click', e => {
            const el = e.target.closest('[data-act]');
            if (!el) {
                return;
            }
            const act = el.dataset.act;
            if (act === 'openpanel') {
                e.preventDefault();
                e.stopPropagation();
                const cell = el.closest('[data-act="cell"]');
                this.openPanel(+cell.dataset.item, cell.dataset.cell);
                return;
            }
            if (act === 'cell') {
                if (longPressed) {
                    longPressed = false;
                    return;
                }
                if (this.canMark) {
                    if (this.panelEl) {
                        this.closePanel();
                    }
                    this.rowCell[+el.dataset.item] = el.dataset.cell;
                    if (this.active) {
                        this.setActive(+el.dataset.item, el.dataset.cell, false);
                    }
                    this.cycle(+el.dataset.item, el.dataset.cell);
                } else {
                    this.openPanel(+el.dataset.item, el.dataset.cell);
                }
                return;
            }
            this.handle(act, el);
        });
        this.root.addEventListener('keydown', e => {
            const crit = e.target.closest('[data-act="item"]');
            if (crit && (e.key === 'Enter' || e.key === ' ')) {
                e.preventDefault();
                this.openPanel(+crit.dataset.item, null);
            }
        });
        this.root.addEventListener('change', e => {
            const up = e.target.closest('[data-upload]');
            if (up && up.files && up.files[0]) {
                this.uploadFile(up.dataset.upload, up.files[0], null, null);
                up.value = '';
            }
            const cb = e.target.closest('[data-confirm]');
            if (cb) {
                cb.closest('.ec-statement').classList.toggle('ec-on', cb.checked);
            }
        });
    }

    /**
     * Handle a button action.
     *
     * @param {String} act
     * @param {HTMLElement} el
     */
    async handle(act, el) {
        switch (act) {
            case 'item':
                this.openPanel(+el.dataset.item, null);
                break;
            case 'fill':
                await this.fill(el.dataset.scope, el.dataset.value);
                break;
            case 'tips':
                this.cfg.tips = this.cfg.tips ? 0 : 1;
                this.savePref('mod_evidencechecklist_tips', this.cfg.tips);
                this.toast(this.str(this.cfg.tips ? 'tipson' : 'tipsoff'));
                this.render();
                break;
            case 'dismisstip':
                this.cfg.dismissed.push(el.dataset.tip);
                this.savePref('mod_evidencechecklist_dismissed', this.cfg.dismissed.join(','));
                el.closest('.ec-tipchip').classList.add('ec-tip-out');
                setTimeout(() => this.render(), 200);
                break;
            case 'guide':
                this.openGuide();
                break;
            case 'shortcuts':
                this.openShortcuts();
                break;
            case 'pad':
                this.padAction(+el.dataset.item, el.dataset.kind);
                break;
            case 'togglesession':
                this.sessionOpen = !this.sessionOpen;
                this.render();
                break;
            case 'toggleconfirm':
                if (this.state && this.state.confirm.done) {
                    this.confirmOpen = !this.confirmOpen;
                    this.render();
                }
                break;
            case 'handover':
                this.openHandover();
                break;
            case 'confirmmode': {
                this.confirmMode = el.dataset.mode;
                this.render();
                const card = this.root.querySelector('.ec-confirm');
                if (card && this.confirmMode) {
                    card.scrollIntoView({behavior: 'smooth', block: 'start'});
                }
                break;
            }
            case 'confirmall':
                this.root.querySelectorAll('[data-confirm]').forEach(cb => {
                    cb.checked = true;
                    cb.closest('.ec-statement').classList.add('ec-on');
                });
                break;
            case 'saveconfirm': {
                const ticked = [...this.root.querySelectorAll('[data-confirm]')].filter(cb => cb.checked)
                    .map(cb => cb.dataset.confirm);
                const note = (this.root.querySelector('[data-confirm-note]') || {}).value || '';
                await this.call('save_confirm', {responses: ticked.join(','), note, method: el.dataset.method || ''});
                this.confirmOpen = false;
                this.confirmMode = '';
                this.render();
                this.toast(this.str('saved'));
                break;
            }
            case 'savesession':
            case 'idverify':
            case 'idsighted': {
                const get = n => (this.root.querySelector('[data-sess="' + n + '"]') || {}).value || '';
                const sim = this.root.querySelector('[data-sim].ec-on');
                const sess = this.todaySession() || {};
                let idmethod = sess.idmethod || '';
                if (act === 'idverify') {
                    idmethod = 'verifyid';
                } else if (act === 'idsighted') {
                    idmethod = 'sighted';
                }
                await this.call('save_session', {setting: get('setting'), simulated: !sim || sim.dataset.sim === '1',
                    equipment: get('equipment'), adjustments: get('adjustments'), idmethod, iddocument: get('iddocument')});
                this.toast(this.str('saved'));
                break;
            }
            case 'signoff':
                this.openSignoff();
                break;
            case 'reassess':
                await this.call('start_reassessment', {});
                this.toast(this.str('reassess_started', this.state.attempt.no), 'info', null, 6000);
                window.scrollTo({top: 0, behavior: 'smooth'});
                break;
            case 'clearpad': {
                const pad = this.root.querySelector('canvas[data-pad="' + el.dataset.pad + '"]') ||
                    document.querySelector('.ec-modal canvas[data-pad="' + el.dataset.pad + '"]');
                if (pad && pad.pad) {
                    pad.pad.clear();
                }
                break;
            }
            case 'ack': {
                const pad = this.root.querySelector('canvas[data-pad="ack"]');
                const img = pad && pad.pad ? pad.pad.toDataURL() : '';
                if (!img) {
                    this.toast(this.str('signhere'), 'error');
                    return;
                }
                await this.call('save_signature', {role: 'student', image: img, typedname: this.cfg.me});
                this.toast(this.str('acknowledged'));
                break;
            }
            case 'sim':
                el.parentNode.querySelectorAll('[data-sim]').forEach(b => b.classList.toggle('ec-on', b === el));
                break;
            default:
                break;
        }
    }

    /**
     * Save a user preference.
     *
     * @param {String} name
     * @param {*} value
     */
    savePref(name, value) {
        Ajax.call([{methodname: 'core_user_update_user_preferences',
            args: {preferences: [{type: name, value: String(value)}]}}])[0].catch(() => null);
    }

    /**
     * One tap: blank -> S -> NYS -> blank.
     *
     * @param {Number} itemid
     * @param {String} cellid
     */
    async cycle(itemid, cellid) {
        const key = itemid + '#' + cellid;
        const cur = this.state.cells[key] ? this.state.cells[key].r : '';
        const next = cur === '' ? 'S' : cur === 'S' ? 'NYS' : cur === 'NYS' ? '' : 'S';
        await this.mark(itemid, cellid, next);
    }

    /**
     * Set one cell, with an instant on-screen update and an undo entry.
     *
     * @param {Number} itemid
     * @param {String} cellid
     * @param {String} next '', S, NYS or NO
     * @param {Boolean} undoable
     * @returns {Promise}
     */
    async mark(itemid, cellid, next, undoable = true) {
        const key = itemid + '#' + cellid;
        const cur = this.state.cells[key] ? this.state.cells[key].r : '';
        if (undoable) {
            this.undoStack.push({itemid, cellid, prev: cur});
            if (this.undoStack.length > 50) {
                this.undoStack.shift();
            }
        }
        this.rowCell[itemid] = cellid;
        const sel = '[data-act="cell"][data-item="' + itemid + '"][data-cell="' + CSS.escape(cellid) + '"]';
        const btn = this.root.querySelector(sel);
        if (btn) {
            const active = btn.classList.contains('ec-active');
            const once = btn.classList.contains('ec-cell-once');
            btn.className = 'ec-cell ' + (next ? 'ec-cell-' + next : 'ec-cell-empty') + ' ec-pop' + (active ? ' ec-active' : '') +
                (once ? ' ec-cell-once' : '');
            btn.querySelector('.ec-cell-face').innerHTML = (next ? ICON[next] : '') + '<span class="ec-cell-text">' +
                esc(next === 'NO' ? this.str('notobserved_short') : next) + '</span>';
        }
        if (navigator.vibrate) {
            navigator.vibrate(8);
        }
        try {
            await this.call('set_result', {itemid, cellid, value: next});
        } catch (e) {
            return;
        }
        if (next === 'NYS') {
            this.openPanel(itemid, cellid, true);
            if (this.cfg.tips && this.cfg.dismissed.indexOf('nys') === -1) {
                this.toast(this.str('tip_nys_t'));
            }
        }
    }

    /**
     * Undo the last mark made on this screen.
     */
    async undo() {
        const u = this.undoStack.pop();
        if (!u) {
            this.toast(this.str('nothingtoundo'));
            return;
        }
        await this.mark(u.itemid, u.cellid, u.prev, false);
        this.setActive(u.itemid, u.cellid);
        this.toast(this.str('undone', this.cellName(this.items[u.itemid], u.cellid)));
    }

    // Evidence pad and quick actions.

    /**
     * A tap on the evidence pad (or its shortcut).
     *
     * @param {Number} itemid
     * @param {String} kind photo, video, audio or comment
     */
    padAction(itemid, kind) {
        const it = this.items[itemid];
        if (!it || !this.canMark) {
            return;
        }
        const cellid = this.padCell(it);
        if (kind === 'photo' || kind === 'video') {
            this.pickFile(kind === 'photo' ? 'image/*' : 'video/*', file => {
                this.uploadFile('cellmedia', file, itemid, cellid, '', '', this.str('addedto', this.cellName(it, cellid)));
            });
        } else if (kind === 'audio') {
            this.toggleQuickRec(it, cellid);
        } else if (kind === 'comment') {
            this.openQuickComment(it, cellid);
        }
    }

    /**
     * Open the camera or file picker straight away.
     *
     * @param {String} accept
     * @param {Function} done
     */
    pickFile(accept, done) {
        const inp = document.createElement('input');
        inp.type = 'file';
        inp.accept = accept;
        inp.setAttribute('capture', 'environment');
        inp.hidden = true;
        inp.addEventListener('change', () => {
            if (inp.files && inp.files[0]) {
                done(inp.files[0]);
            }
            inp.remove();
        });
        document.body.appendChild(inp);
        inp.click();
    }

    /**
     * Start or stop an audio recording from the row, without opening the panel.
     *
     * @param {Object} it
     * @param {String} cellid
     */
    async toggleQuickRec(it, cellid) {
        if (this.quickRec) {
            const q = this.quickRec;
            this.quickRec = null;
            clearInterval(q.tick);
            if (q.bar) {
                q.bar.remove();
            }
            const blob = await q.rec.stop();
            const ext = blob.type.indexOf('mp4') !== -1 ? 'm4a' : blob.type.indexOf('ogg') !== -1 ? 'ogg' : 'webm';
            this.render();
            const qit = this.items[q.itemid];
            this.uploadFile('cellmedia', blob, q.itemid, q.cellid, 'audio', 'recording.' + ext,
                this.str('addedto', this.cellName(qit, q.cellid)));
            if (q.itemid === it.id) {
                return;
            }
        }
        if (!canRecord()) {
            this.toast(this.str('recordinghint'), 'error');
            return;
        }
        const rec = new Recorder();
        try {
            await rec.start();
        } catch (err) {
            this.toast(err.message, 'error');
            return;
        }
        const bar = document.createElement('div');
        bar.className = 'ec-root ec-recbar';
        bar.innerHTML = '<span class="ec-recdot"></span><span class="ec-recbar-text">' + esc(this.str('recording')) + ' · ' +
            esc(this.cellName(it, cellid)) + '</span><span class="ec-recbar-time">0:00</span><button type="button" ' +
            'class="ec-btn ec-btn-sm ec-btn-rec">' + ICON.stop + ' ' + esc(this.str('stop')) + ' <kbd>A</kbd></button>';
        document.body.appendChild(bar);
        bar.querySelector('button').addEventListener('click', () => this.toggleQuickRec(it, cellid));
        this.quickRec = {rec, itemid: it.id, cellid, bar, tick: setInterval(() => {
            bar.querySelector('.ec-recbar-time').textContent = this.mmss(rec.elapsed());
        }, 500)};
        this.render();
    }

    /**
     * Quick comment pop-up beside the row, with phrases and dictation.
     *
     * @param {Object} it
     * @param {String} cellid
     */
    openQuickComment(it, cellid) {
        this.closeQuickComment();
        const cell = this.state.cells[it.id + '#' + cellid];
        const phrases = this.str('quickphrases').split('|').filter(x => x);
        let recent = [];
        try {
            recent = JSON.parse(window.localStorage.getItem('mod_evidencechecklist_recent') || '[]');
        } catch (e) {
            recent = [];
        }
        recent = recent.filter(r => phrases.indexOf(r) === -1).slice(0, 4);
        const chip = t => '<button type="button" class="ec-qc-chip" data-phrase="' + esc(t) + '">' + esc(t) + '</button>';
        const mic = this.s.dictation && Dictation.supported() ? '<button type="button" class="ec-mic" data-mic aria-label="' +
            esc(this.str('dictate')) + '" title="' + esc(this.str('dictate')) + '">' + ICON.mic + '</button>' : '';
        const wrap = document.createElement('div');
        wrap.className = 'ec-root ec-qc';
        wrap.setAttribute('role', 'dialog');
        wrap.setAttribute('aria-label', this.str('pad_comment'));
        wrap.innerHTML = '<div class="ec-qc-head"><span class="ec-num">' + esc(it.number) + '</span><span class="ec-qc-title">' +
            esc(this.str('comment')) + ' · ' + esc(this.cellName(it, cellid)) + '</span>' + this.help('comment') +
            '<button type="button" class="ec-iconbtn ec-iconbtn-sm" data-qc-close aria-label="' + esc(this.str('close')) + '">' +
            ICON.close + '</button></div><span class="ec-textwrap"><textarea rows="3" class="ec-input" data-qc-text>' +
            esc(cell && cell.c ? cell.c : '') + '</textarea>' + mic + '</span>' +
            '<div class="ec-qc-label">' + esc(this.str('quickphrases_desc')) + '</div><div class="ec-qc-chips">' +
            phrases.map(chip).join('') + '</div>' + (recent.length ? '<div class="ec-qc-label">' +
            esc(this.str('recentcomments')) + '</div><div class="ec-qc-chips">' + recent.map(chip).join('') + '</div>' : '') +
            '<div class="ec-qc-foot"><button type="button" class="ec-link" data-qc-more>' + esc(this.str('moredetails')) +
            '</button><span class="ec-qc-hint">' + esc(this.str('enterhint')) + '</span><button type="button" ' +
            'class="ec-btn ec-btn-sm ec-btn-primary" data-qc-save>' + esc(this.str('save')) + '</button></div>';
        document.body.appendChild(wrap);
        // Place it under the row's pad, kept on screen.
        const anchor = this.root.querySelector('.ec-pad[data-item="' + it.id + '"]') ||
            this.root.querySelector('.ec-row[data-item="' + it.id + '"]');
        const r = anchor ? anchor.getBoundingClientRect() : {left: 20, right: 380, bottom: 200, top: 100};
        const w = Math.min(420, window.innerWidth - 24);
        wrap.style.width = w + 'px';
        wrap.style.left = Math.max(12, Math.min(r.right - w, window.innerWidth - w - 12)) + 'px';
        const h = wrap.offsetHeight;
        wrap.style.top = (r.bottom + 8 + h > window.innerHeight - 8 ? Math.max(8, r.top - h - 8) : r.bottom + 8) + 'px';
        requestAnimationFrame(() => wrap.classList.add('ec-in'));
        const ta = wrap.querySelector('[data-qc-text]');
        ta.focus();
        ta.setSelectionRange(ta.value.length, ta.value.length);
        const micbtn = wrap.querySelector('[data-mic]');
        if (micbtn) {
            Dictation.attach(micbtn, ta);
        }
        const save = async() => {
            const text = ta.value.trim();
            this.closeQuickComment();
            if (text === (cell && cell.c ? cell.c : '')) {
                return;
            }
            await this.call('set_cell_text', {itemid: it.id, cellid, field: 'comment', value: text});
            this.rememberComment(text);
            this.toast(this.str('commentsaved', this.cellName(it, cellid)));
        };
        wrap.addEventListener('click', e => {
            const ph = e.target.closest('[data-phrase]');
            if (ph) {
                const v = ta.value.trim();
                ta.value = v ? v.replace(/[.\s]*$/, '. ') + ph.dataset.phrase : ph.dataset.phrase;
                ta.focus();
                return;
            }
            if (e.target.closest('[data-qc-save]')) {
                save();
            } else if (e.target.closest('[data-qc-close]')) {
                this.closeQuickComment();
            } else if (e.target.closest('[data-qc-more]')) {
                this.closeQuickComment();
                this.openPanel(it.id, cellid);
            }
        });
        ta.addEventListener('keydown', e => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                save();
            } else if (e.key === 'Escape') {
                e.stopPropagation();
                this.closeQuickComment();
            }
        });
        this.qc = wrap;
        this.qcOutside = e => {
            if (this.qc && !this.qc.contains(e.target) && !e.target.closest('.ec-pad-comment')) {
                this.closeQuickComment();
            }
        };
        setTimeout(() => document.addEventListener('pointerdown', this.qcOutside), 0);
    }

    /**
     * Close the quick comment pop-up.
     */
    closeQuickComment() {
        Dictation.stopAll();
        if (this.qcOutside) {
            document.removeEventListener('pointerdown', this.qcOutside);
            this.qcOutside = null;
        }
        if (this.qc) {
            const el = this.qc;
            el.classList.remove('ec-in');
            setTimeout(() => el.remove(), 160);
            this.qc = null;
        }
    }

    /**
     * Remember a comment so it can be reused with one tap.
     *
     * @param {String} text
     */
    rememberComment(text) {
        if (!text || text.length > 120) {
            return;
        }
        try {
            const list = JSON.parse(window.localStorage.getItem('mod_evidencechecklist_recent') || '[]').filter(t => t !== text);
            list.unshift(text);
            window.localStorage.setItem('mod_evidencechecklist_recent', JSON.stringify(list.slice(0, 8)));
        } catch (e) {
            // Storage can be blocked; recent comments are only a convenience.
        }
    }

    // Keyboard.

    /**
     * Move the keyboard highlight to a cell.
     *
     * @param {Number} itemid
     * @param {String} cellid
     * @param {Boolean} scroll
     */
    setActive(itemid, cellid, scroll = true) {
        this.active = {itemid, cellid};
        this.root.querySelectorAll('.ec-cell.ec-active').forEach(c => c.classList.remove('ec-active'));
        const el = this.root.querySelector('[data-act="cell"][data-item="' + itemid + '"][data-cell="' + CSS.escape(cellid) +
            '"]');
        if (!el) {
            return;
        }
        el.classList.add('ec-active');
        // Keep the row's evidence pad pointing at this cell.
        const pad = this.root.querySelector('.ec-pad[data-item="' + itemid + '"]');
        if (pad) {
            pad.outerHTML = this.padHtml(this.items[itemid], this.canMark);
        }
        if (scroll) {
            const r = el.getBoundingClientRect();
            const head = this.root.querySelector('.ec-colhead');
            const top = head ? Math.max(0, head.getBoundingClientRect().bottom) + 12 : 160;
            if (r.top < top) {
                window.scrollBy({top: r.top - top - 20, behavior: 'smooth'});
            } else if (r.bottom > window.innerHeight - 24) {
                window.scrollBy({top: r.bottom - window.innerHeight + 120, behavior: 'smooth'});
            }
        }
    }

    /**
     * Index of the active cell in reading order.
     *
     * @returns {Number}
     */
    activeIndex() {
        if (!this.active) {
            return -1;
        }
        return this.order.findIndex(o => o.itemid === this.active.itemid && o.cellid === this.active.cellid);
    }

    /**
     * Move the highlight by cells (left/right) or rows (up/down).
     *
     * @param {String} how next, prev, down, up, empty, group+, group-
     */
    move(how) {
        const order = this.order;
        if (!order.length) {
            return;
        }
        let i = this.activeIndex();
        if (i === -1) {
            const first = order.findIndex(o => !this.state.cells[o.itemid + '#' + o.cellid]);
            const o = order[first === -1 ? 0 : first];
            this.setActive(o.itemid, o.cellid);
            return;
        }
        const cur = order[i];
        const items = this.s.groups.reduce((a, g) => a.concat(g.items), []);
        const ii = items.findIndex(x => x.id === cur.itemid);
        const col = this.colIndex[cur.cellid];
        const inItem = (it, prefer) => {
            const cells = this.s.columns.filter(c => it.cells.indexOf(c.id) !== -1);
            const same = cells.find(c => this.colIndex[c.id] === prefer);
            return (same || cells[0]).id;
        };
        if (how === 'next' || how === 'prev') {
            i = Math.max(0, Math.min(order.length - 1, i + (how === 'next' ? 1 : -1)));
            this.setActive(order[i].itemid, order[i].cellid);
        } else if (how === 'down' || how === 'up') {
            const ni = Math.max(0, Math.min(items.length - 1, ii + (how === 'down' ? 1 : -1)));
            this.setActive(items[ni].id, inItem(items[ni], col));
        } else if (how === 'empty') {
            for (let k = 1; k <= order.length; k++) {
                const o = order[(i + k) % order.length];
                const c = this.state.cells[o.itemid + '#' + o.cellid];
                if (!c || !c.r) {
                    this.setActive(o.itemid, o.cellid);
                    return;
                }
            }
            this.toast(this.str('allmarked'));
        } else if (how === 'group+' || how === 'group-') {
            const gi = this.s.groups.findIndex(g => g.id === this.items[cur.itemid].groupid);
            const g = this.s.groups[Math.max(0, Math.min(this.s.groups.length - 1, gi + (how === 'group+' ? 1 : -1)))];
            if (g && g.items.length) {
                this.setActive(g.items[0].id, inItem(g.items[0], col));
            }
        }
    }

    /**
     * Keyboard shortcuts for the marking screen and the side panel (bound once).
     */
    bindKeys() {
        document.addEventListener('keydown', e => {
            if (!this.isAssessor || e.defaultPrevented) {
                return;
            }
            const t = e.target;
            const typing = t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' ||
                t.isContentEditable);
            if (typing || e.altKey || document.querySelector('.ec-modal-wrap') || this.qc) {
                return;
            }
            const mod = e.ctrlKey || e.metaKey;
            const k = e.key;
            if (mod && (k === 'z' || k === 'Z') && this.canMark) {
                e.preventDefault();
                this.undo();
                return;
            }
            if (mod) {
                return;
            }
            if (k === '?') {
                e.preventDefault();
                this.openShortcuts();
                return;
            }
            const nav = this.cfg.nav;
            if (e.shiftKey && (k === 'ArrowRight' || k === 'ArrowLeft') && nav) {
                const to = k === 'ArrowRight' ? nav.next : nav.prev;
                if (to) {
                    e.preventDefault();
                    window.location.href = to.url;
                }
                return;
            }
            if (this.panelEl) {
                this.panelKey(e);
                return;
            }
            if (!this.canMark) {
                return;
            }
            if (e.shiftKey && (k === 'N' || k === 'n')) {
                e.preventDefault();
                if (nav && nav.open) {
                    window.location.href = nav.open.url;
                } else {
                    this.toast(this.str('nonextopen'));
                }
                return;
            }
            if (e.shiftKey && (k === 'G' || k === 'g') && this.canMark && this.s.allowbulk) {
                e.preventDefault();
                if (!this.active) {
                    this.move('next');
                    return;
                }
                this.fill('col:' + this.active.cellid, 'S');
                return;
            }
            const lower = k.length === 1 ? k.toLowerCase() : k;
            const act = this.active;
            const needCell = () => {
                if (!this.active) {
                    this.move('next');
                    return false;
                }
                return true;
            };
            let handled = true;
            switch (lower) {
                case 'ArrowDown': case 'j':
                    this.root.classList.add('ec-kbd');
                    this.move('down');
                    break;
                case 'ArrowUp': case 'k':
                    this.root.classList.add('ec-kbd');
                    this.move('up');
                    break;
                case 'ArrowRight': case 'l':
                    this.root.classList.add('ec-kbd');
                    this.move('next');
                    break;
                case 'ArrowLeft': case 'h':
                    this.root.classList.add('ec-kbd');
                    this.move('prev');
                    break;
                case 'e':
                    this.root.classList.add('ec-kbd');
                    this.move('empty');
                    break;
                case ']':
                    this.root.classList.add('ec-kbd');
                    this.move('group+');
                    break;
                case '[':
                    this.root.classList.add('ec-kbd');
                    this.move('group-');
                    break;
                case 's': case '1':
                    if (e.shiftKey && lower === 's') {
                        if (needCell()) {
                            this.markRow(act.itemid);
                        }
                    } else if (needCell()) {
                        this.mark(act.itemid, act.cellid, 'S');
                        this.move('next');
                    }
                    break;
                case 'n': case '2':
                    if (needCell()) {
                        this.mark(act.itemid, act.cellid, 'NYS');
                    }
                    break;
                case 'o': case '3':
                    if (needCell()) {
                        this.mark(act.itemid, act.cellid, 'NO');
                        this.move('next');
                    }
                    break;
                case '0': case 'Backspace': case 'Delete':
                    if (needCell()) {
                        this.mark(act.itemid, act.cellid, '');
                    }
                    break;
                case ' ':
                    if (needCell()) {
                        this.cycle(act.itemid, act.cellid);
                    }
                    break;
                case 'u':
                    this.undo();
                    break;
                case 'g':
                    if (needCell() && this.s.allowbulk) {
                        this.fill('group:' + this.items[act.itemid].groupid, 'S');
                    }
                    break;
                case 'Enter':
                    if (needCell()) {
                        this.openPanel(act.itemid, act.cellid);
                    }
                    break;
                case 't':
                    if (needCell()) {
                        this.openPanel(act.itemid, act.cellid);
                        const tb = this.panelEl && this.panelEl.querySelector('[data-timer]');
                        if (tb) {
                            tb.click();
                        }
                    }
                    break;
                case 'p': case 'v': case 'a': case 'r': case 'c':
                    if (needCell()) {
                        e.preventDefault();
                        this.padAction(act.itemid, {p: 'photo', v: 'video', a: 'audio', r: 'audio', c: 'comment'}[lower]);
                    }
                    break;
                case '/': case 'f':
                    this.openJump();
                    break;
                case 'x':
                    if (this.cfg.canfinalise) {
                        this.openSignoff();
                    }
                    break;
                default:
                    handled = false;
            }
            if (handled) {
                e.preventDefault();
            }
        });
    }

    /**
     * Keys while the side panel is open.
     *
     * @param {KeyboardEvent} e
     */
    panelKey(e) {
        if (!this.panel || !this.canMark) {
            return;
        }
        const k = e.key.length === 1 ? e.key.toLowerCase() : e.key;
        const it = this.items[this.panel.itemid];
        const set = v => {
            const cur = this.currentCell();
            if (!cur || cur.r !== v) {
                this.mark(it.id, this.panel.cellid, v);
            }
        };
        const items = this.s.groups.reduce((a, g) => a.concat(g.items), []);
        const go = d => {
            const ni = items.findIndex(x => x.id === it.id) + d;
            if (items[ni]) {
                const col = this.colIndex[this.panel.cellid];
                const cells = this.s.columns.filter(c => items[ni].cells.indexOf(c.id) !== -1);
                const cid = (cells.find(c => this.colIndex[c.id] === col) || cells[0]).id;
                this.setActive(items[ni].id, cid, true);
                this.openPanel(items[ni].id, cid);
            }
        };
        const sheet = this.panelEl.querySelector('.ec-sheet');
        let handled = true;
        switch (k) {
            case 's': case '1':
                set('S');
                break;
            case 'n': case '2':
                set('NYS');
                break;
            case 'o': case '3':
                set('NO');
                break;
            case '0': case 'Backspace': case 'Delete':
                this.mark(it.id, this.panel.cellid, '');
                break;
            case 'ArrowDown': case 'j':
                go(1);
                break;
            case 'ArrowUp': case 'k':
                go(-1);
                break;
            case 'ArrowRight': case 'ArrowLeft': {
                const i = it.cells.indexOf(this.panel.cellid) + (k === 'ArrowRight' ? 1 : -1);
                if (it.cells[i]) {
                    this.panel.cellid = it.cells[i];
                    this.renderPanel();
                }
                break;
            }
            case 'c': {
                const ta = sheet.querySelector('[data-text="comment"]');
                if (ta) {
                    ta.focus();
                }
                break;
            }
            case 't': {
                const tb = sheet.querySelector('[data-timer]');
                if (tb) {
                    tb.click();
                }
                break;
            }
            case 'p': case 'v': {
                const inp = sheet.querySelector('input[data-cellupload][accept="' + (k === 'p' ? 'image/*' : 'video/*') + '"]');
                if (inp) {
                    inp.click();
                }
                break;
            }
            case 'a': case 'r': {
                const rb = sheet.querySelector('[data-rec]');
                if (rb) {
                    rb.click();
                }
                break;
            }
            case 'u':
                this.undo();
                break;
            default:
                handled = false;
        }
        if (handled) {
            e.preventDefault();
        }
    }

    /**
     * Mark every cell in a row S and move to the next row.
     *
     * @param {Number} itemid
     */
    async markRow(itemid) {
        const it = this.items[itemid];
        for (const cid of it.cells) {
            const c = this.state.cells[itemid + '#' + cid];
            if (!c || c.r !== 'S') {
                await this.mark(itemid, cid, 'S');
            }
        }
        this.toast(this.str('rowmarked', it.number));
        this.move('down');
    }

    /**
     * Go to an item by number.
     */
    openJump() {
        const m = this.modal(this.str('jumpto'), '<input type="text" class="ec-input ec-jump" inputmode="decimal" placeholder="' +
            esc(this.str('jumpto_ph')) + '" aria-label="' + esc(this.str('jumpto')) + '">', 'ec-modal-sm');
        const inp = m.querySelector('.ec-jump');
        setTimeout(() => inp.focus(), 60);
        inp.addEventListener('keydown', ev => {
            if (ev.key === 'Escape') {
                m.close();
            }
            if (ev.key !== 'Enter') {
                return;
            }
            const num = inp.value.trim();
            const it = Object.values(this.items).find(x => String(x.number) === num);
            if (!it) {
                this.toast(this.str('nosuchitem', num), 'error');
                return;
            }
            m.close();
            this.root.classList.add('ec-kbd');
            this.setActive(it.id, it.cells[0]);
        });
    }

    /**
     * Shortcut help.
     */
    openShortcuts() {
        const row = (keys, key) => '<div class="ec-sc-row"><span class="ec-sc-keys">' + keys.map(x => '<kbd>' + esc(x) +
            '</kbd>').join('') + '</span><span>' + esc(this.str(key)) + '</span></div>';
        const group = (title, rows) => '<section class="ec-sc-group"><h4>' + esc(this.str(title)) + '</h4>' + rows.join('') +
            '</section>';
        const body = '<p class="ec-sc-intro">' + esc(this.str('shortcuts_intro')) + '</p><div class="ec-sc-grid">' +
            group('sc_group_mark', [row(['S'], 'sc_s'), row(['N'], 'sc_n'), row(['O'], 'sc_o'), row(['0'], 'sc_clear'),
                row(['Space'], 'sc_space'), row(['Shift', 'S'], 'sc_rows'), row(['G'], 'sc_group'),
                row(['Shift', 'G'], 'sc_column'),
                row(['U'], 'sc_undo')]) +
            group('sc_group_move', [row(['←', '→'], 'sc_arrows'), row(['↑', '↓'], 'sc_updown'), row(['E'], 'sc_empty'),
                row(['[', ']'], 'sc_section'), row(['/'], 'sc_jump'), row(['Shift', '←', '→'], 'sc_student'),
                row(['Shift', 'N'], 'sc_nextopen')]) +
            group('sc_group_evidence', [row(['P'], 'sc_photo'), row(['V'], 'sc_video'), row(['A'], 'sc_audio'),
                row(['C'], 'sc_comment'), row(['T'], 'sc_timer'), row(['Enter'], 'sc_details')]) +
            group('sc_group_more', [row(['X'], 'sc_signoff'), row(['?'], 'sc_help'), row(['Esc'], 'sc_close')]) +
            group('sc_group_panel', [row(['S', 'N', 'O'], 'sc_panelmark'), row(['↑', '↓'], 'sc_panelitems'),
                row(['←', '→'], 'sc_panelcols'), row(['C'], 'sc_comment'), row(['T'], 'sc_timer')]) +
            '<section class="ec-sc-group"><h4>' + esc(this.str('sc_group_touch')) + '</h4><div class="ec-sc-row ec-sc-text">' +
            esc(this.str('sc_tap')) + '</div><div class="ec-sc-row ec-sc-text">' + esc(this.str('sc_hold')) + '</div>' +
            '<div class="ec-sc-row ec-sc-text">' + esc(this.str('sc_swipe')) + '</div>' +
            '<div class="ec-sc-row ec-sc-text">' + esc(this.str('sc_pad')) + '</div></section></div>';
        const m = this.modal(this.str('shortcuts_title'), body, 'ec-modal-wide ec-modal-shortcuts');
        const toggle = ev => {
            if (ev.key === '?' || ev.key === 'Escape' || !document.body.contains(m)) {
                document.removeEventListener('keydown', toggle);
                if (ev.key === '?') {
                    ev.preventDefault();
                    ev.stopImmediatePropagation();
                    m.close();
                }
            }
        };
        setTimeout(() => document.addEventListener('keydown', toggle), 0);
    }

    /**
     * Bulk fill with undo.
     *
     * @param {String} scope
     * @param {String} value
     */
    async fill(scope, value) {
        if (this.cfg.tips && this.cfg.dismissed.indexOf('bulk') === -1) {
            // eslint-disable-next-line no-alert
            if (!window.confirm(this.str('tip_bulk_t') + '\n\n' + this.str('tip_bulk'))) {
                return;
            }
            this.cfg.dismissed.push('bulk');
            this.savePref('mod_evidencechecklist_dismissed', this.cfg.dismissed.join(','));
        }
        const extra = await this.call('bulk_fill', {scope, value});
        const ids = extra.changed || [];
        if (!ids.length) {
            this.toast(this.str('saved'));
            return;
        }
        this.toast(this.str('saved') + ' · ' + ids.length, 'info', {label: this.str('undo'), fn: async() => {
            await this.call('undo_bulk', {ids: ids.join(',')});
        }}, 10000);
    }

    // Panel.

    /**
     * Open the side panel for an item (and optionally one cell).
     *
     * @param {Number} itemid
     * @param {String|null} cellid
     * @param {Boolean} focusGap
     */
    openPanel(itemid, cellid, focusGap = false) {
        const it = this.items[itemid];
        if (!it) {
            return;
        }
        if (!cellid) {
            cellid = it.cells[0];
        }
        // Switching rows with the panel already docked must not animate the grid closed and open again.
        const wasDocked = !!this.root.style.marginRight;
        this.closePanel();
        this.panel = {itemid, cellid};
        const wrap = document.createElement('div');
        wrap.className = 'ec-root ec-sheet-wrap';
        wrap.style.cssText = this.root.getAttribute('style') || '';
        wrap.innerHTML = '<div class="ec-sheet-backdrop" data-close></div><aside class="ec-sheet" role="dialog" ' +
            'aria-modal="true" aria-label="' + esc(it.number) + '"></aside>';
        document.body.appendChild(wrap);
        this.panelEl = wrap;
        // On a wide screen the panel docks beside the grid, so the assessor can keep marking with it open.
        if (window.innerWidth >= 1200) {
            wrap.classList.add('ec-docked');
            const sheetw = Math.min(460, Math.round(window.innerWidth * 0.34));
            wrap.style.setProperty('--ec-sheetw', sheetw + 'px');
            // Measure the grid at full width: no margin and no transition in flight.
            this.root.style.transition = 'none';
            this.root.style.marginRight = '';
            const r = this.root.getBoundingClientRect();
            const overlap = r.right - (window.innerWidth - 16 - sheetw) + 16;
            if (!wasDocked) {
                this.root.style.transition = 'margin-right .28s ease';
            }
            this.root.style.marginRight = Math.max(0, Math.round(overlap)) + 'px';
        }
        wrap.addEventListener('click', ev => {
            if (ev.target.closest('[data-close]')) {
                this.closePanel();
            }
        });
        document.addEventListener('keydown', this.escHandler = ev => {
            if (ev.key === 'Escape') {
                this.closePanel();
            }
        });
        this.renderPanel();
        requestAnimationFrame(() => wrap.classList.add('ec-in'));
        if (focusGap) {
            const f = wrap.querySelector('[data-gap="observed"]');
            if (f) {
                setTimeout(() => f.focus(), 250);
            }
        }
    }

    /**
     * Close the panel.
     */
    closePanel() {
        Dictation.stopAll();
        if (this.recorder) {
            this.recorder.stop();
            this.recorder = null;
        }
        if (this.timer) {
            clearInterval(this.timer.int);
            this.timer = null;
        }
        if (this.panelEl) {
            const el = this.panelEl;
            el.classList.remove('ec-in');
            setTimeout(() => el.remove(), 220);
            this.root.style.marginRight = '';
        }
        if (this.escHandler) {
            document.removeEventListener('keydown', this.escHandler);
        }
        this.panelEl = null;
        this.panel = null;
    }

    /**
     * Refresh panel after a state change without losing typing.
     */
    refreshPanel() {
        if (!this.panelEl) {
            return;
        }
        const active = document.activeElement;
        if (active && this.panelEl.contains(active) && (active.tagName === 'TEXTAREA' || active.tagName === 'INPUT')) {
            const media = this.panelEl.querySelector('[data-media-list]');
            if (media) {
                media.innerHTML = this.mediaList(this.currentCell());
            }
            return;
        }
        this.renderPanel();
    }

    /**
     * Current cell state in the panel.
     *
     * @returns {Object|null}
     */
    currentCell() {
        if (!this.panel || !this.state) {
            return null;
        }
        return this.state.cells[this.panel.itemid + '#' + this.panel.cellid] || null;
    }

    /**
     * Media list HTML.
     *
     * @param {Object|null} cell
     * @returns {String}
     */
    mediaList(cell) {
        const media = cell && cell.media ? cell.media : [];
        if (!media.length) {
            return '<div class="ec-muted">' + esc(this.str('nomedia')) + '</div>';
        }
        return media.map(m => {
            let body;
            if (m.type === 'image') {
                body = '<a href="' + esc(m.url) + '" target="_blank" rel="noopener"><img src="' + esc(m.url) + '" alt="' +
                    esc(m.name) + '" loading="lazy"></a>';
            } else if (m.type === 'video') {
                body = '<video controls preload="metadata" playsinline' + (m.poster ? ' poster="' + esc(m.poster) + '"' : '') +
                    ' src="' + esc(m.url) + '"></video>';
            } else if (m.type === 'audio') {
                body = '<audio controls preload="metadata" src="' + esc(m.url) + '"></audio>';
            } else {
                body = '<a href="' + esc(m.url) + '">' + esc(m.name) + '</a>';
            }
            const n = m.links ? m.links.length : 0;
            let foot = '';
            if (m.linked) {
                foot = '<figcaption class="ec-media-foot"><span class="ec-linktag">' + ICON.link + ' ' +
                    esc(this.str('linkedfrom', m.fromno)) + '</span>' + (this.canMark ? '<button type="button" ' +
                    'class="ec-linkbtn" data-unlink="' + m.id + '" data-from="' + esc(m.from) + '">' + esc(this.str('unlink')) +
                    '</button>' : '') + '</figcaption>';
            } else if (this.canMark || n) {
                foot = '<figcaption class="ec-media-foot">' + (this.canMark ? '<button type="button" class="ec-linkbtn' +
                    (n ? ' ec-on' : '') + '" data-link="' + m.id + '">' + ICON.link + ' ' +
                    esc(n ? this.str('alsocoversn', n) : this.str('alsocovers')) + '</button>' :
                    '<span class="ec-linktag">' + ICON.link + ' ' + esc(this.str('alsocoversn', n)) + '</span>') +
                    '</figcaption>';
            }
            return '<figure class="ec-media ec-media-' + m.type + (m.linked ? ' ec-media-linked' : '') + '">' + body +
                (this.editable && !m.linked ? '<button type="button" ' +
                'class="ec-media-del" data-del="' + m.id + '" aria-label="' + esc(this.str('delete')) + '">' + ICON.close +
                '</button>' : '') + foot + '</figure>';
        }).join('');
    }

    /**
     * Render panel contents.
     */
    renderPanel() {
        const it = this.items[this.panel.itemid];
        const cellid = this.panel.cellid;
        const col = this.s.columns[this.colIndex[cellid]] || {label: ''};
        const cell = this.currentCell();
        const v = cell ? cell.r : '';
        const canmark = this.canMark;
        const gap = this.state && this.state.gaps[it.id];
        const sheet = this.panelEl.querySelector('.ec-sheet');
        let html = '<header class="ec-sheet-head"><div><div class="ec-sheet-kicker"><span class="ec-num">' + esc(it.number) +
            '</span> ' + esc(col.label) + '</div><div class="ec-sheet-title">' + it.criterion + '</div></div>' +
            '<button type="button" class="ec-iconbtn" data-close aria-label="' + esc(this.str('close')) + '">' + ICON.close +
            '</button></header><div class="ec-sheet-body">';
        if (it.cells.length > 1) {
            html += '<div class="ec-cellpick">' + it.cells.map(cid => {
                const c = this.state && this.state.cells[it.id + '#' + cid];
                const cv = c ? c.r : '';
                const lbl = (this.s.columns[this.colIndex[cid]] || {label: cid}).label;
                return '<button type="button" class="ec-cellpick-btn' + (cid === cellid ? ' ec-on' : '') + ' ec-cp-' +
                    (cv || 'empty') + '" data-pick="' + esc(cid) + '">' + esc(lbl) + '</button>';
            }).join('') + '</div>';
        }
        if (it.benchmark) {
            html += '<div class="ec-bench-box"><div class="ec-label">' + esc(this.str('benchmark')) + '</div>' + it.benchmark +
                '</div>';
        }
        if (it.image) {
            html += '<img class="ec-refimg" src="' + esc(it.image) + '" alt="">';
        }
        if (it.verbal) {
            html += this.tips(['verbal']);
        }
        if (this.state) {
            html += '<div class="ec-label">' + esc(this.str('decision')) + ' ' + this.help('result') + '</div>';
            html += '<div class="ec-bigbtns">' + ['S', 'NYS', 'NO'].map(val => '<button type="button" class="ec-big ec-big-' +
                val + (v === val ? ' ec-on' : '') + '" data-set="' + val + '"' + (canmark ? '' : ' disabled') + '>' + ICON[val] +
                '<span>' + esc(val === 'NO' ? this.str('notobserved') : this.str(val.toLowerCase())) + '</span></button>')
                .join('') + '</div>';
            if (v === 'NO') {
                html += this.tips(['notobserved']);
            }
            // Gap plan, straight under the decision so a carried or open plan is the first thing the assessor sees.
            if (v === 'NYS' || gap) {
                const g = gap || {observed: '', strategy: it.gaptip || '', reobservedate: 0, status: 'open'};
                const date = g.reobservedate ? new Date(g.reobservedate * 1000).toISOString().slice(0, 10) : '';
                html += '<div class="ec-sec ec-gapsec' + (g.status === 'closed' ? ' ec-closed' : '') + '"><div class="ec-label">' +
                    esc(this.str('gapplan')) + (g.status === 'closed' ? ' ✓' : '') + '</div>' + this.gapStatus(it, gap, canmark) +
                    this.textField('observed', this.str('observed'), g.observed, canmark, '', 'gap') +
                    this.textField('strategy', this.str('strategy'), g.strategy || '', canmark, '', 'gap') +
                    '<label class="ec-field"><span>' + esc(this.str('reobservedate')) + ' ' + this.help('reobservedate') +
                    '</span><input type="date" ' +
                    'class="ec-input" data-gap="reobservedate" value="' + date + '"' + (canmark ? '' : ' disabled') + '></label>' +
                    (canmark ? '<div class="ec-actions"><button type="button" class="ec-btn ec-btn-primary ec-btn-sm" ' +
                        'data-savegap>' + esc(this.str('save')) + '</button></div>' : '') + this.tips(['nys']) + '</div>';
            }

            // Timer.
            const secs = cell && cell.timer !== null && cell.timer !== undefined ? cell.timer : null;
            html += '<div class="ec-timer"><span class="ec-label">' + esc(this.str('timer')) + ' ' + this.help('timer') +
                '</span>' +
                '<span class="ec-timer-val" data-timer-val>' + this.mmss(secs || 0) + '</span>' +
                (canmark ? '<button type="button" class="ec-btn ec-btn-sm" ' +
                'data-timer>' + esc(this.str('start')) + '</button>' : '') + '</div>';
            // Comment.
            html += this.textField('comment', this.str('comment'), cell ? cell.c : '', canmark);
            if (this.isAssessor) {
                html += this.textField('assessornote', this.str('assessornote'), cell ? (cell.note || '') : '', canmark,
                    this.str('assessornote_help'));
            }
            // Evidence.
            html += '<div class="ec-sec"><div class="ec-label">' + esc(this.str('evidence')) + ' ' + this.help('evidence') +
                this.help('alsocovers') +
                '</div>';
            if (canmark) {
                html += '<div class="ec-mediabtns"><label class="ec-btn ec-btn-sm ec-filebtn">' + ICON.cam + ' ' +
                    esc(this.str('addphoto')) + '<input type="file" accept="image/*" capture="environment" data-cellupload ' +
                    'hidden></label><label class="ec-btn ec-btn-sm ec-filebtn">' + ICON.video + ' ' + esc(this.str('addvideo')) +
                    '<input type="file" accept="video/*" capture="environment" data-cellupload hidden></label>' +
                    (canRecord() ? '<button type="button" class="ec-btn ec-btn-sm" data-rec>' + ICON.mic + ' ' +
                        esc(this.str('recordaudio')) + '</button>' : '') +
                    '<label class="ec-btn ec-btn-sm ec-filebtn">' + ICON.upload + ' ' + esc(this.str('uploadaudio')) +
                    '<input type="file" accept="audio/*" data-cellupload data-kind="audio" hidden></label></div>' +
                    '<div class="ec-upload-progress" data-upprog hidden><span></span></div>';
            }
            html += '<div class="ec-medialist" data-media-list>' + this.mediaList(cell) + '</div></div>';
        }
        html += '</div>';
        sheet.innerHTML = html;
        this.bindPanel(sheet, it);
    }

    /**
     * Gap plan status: how and when it closed, or how it will close, with the assessor's close and reopen controls.
     *
     * @param {Object} it
     * @param {Object|null} gap
     * @param {Boolean} canmark
     * @returns {String}
     */
    gapStatus(it, gap, canmark) {
        if (!gap) {
            return '';
        }
        if (gap.status === 'closed') {
            const how = this.str(gap.closereason === 'assessor' ? 'gapclosed_assessor' : 'gapclosed_reobserved');
            return '<div class="ec-gapstatus ec-gapstatus-closed">' + ICON.S + '<div><strong>' +
                esc(this.str('gapclosedby', {how, who: gap.closedby || '', time: fmt(gap.timeclosed)})) + '</strong>' +
                (gap.closenote ? '<div>' + esc(gap.closenote) + '</div>' : '') + '</div>' +
                (canmark ? '<button type="button" class="ec-btn ec-btn-sm" data-gapreopen>' + esc(this.str('gapreopen')) +
                    '</button>' : '') + '</div>';
        }
        const hasnys = it.cells.some(cid => {
            const c = this.state.cells[it.id + '#' + cid];
            return c && c.r === 'NYS';
        });
        const carried = this.state.attempt.no > 1 && !hasnys;
        let html = '<div class="ec-gapstatus">' + (carried ? '<strong>' + esc(this.str('gapcarried', this.state.attempt.no - 1)) +
            '</strong> ' : '') + esc(this.str('gapopen_help')) + '</div>';
        if (canmark) {
            html += '<details class="ec-gapclose"' + (hasnys ? '' : '') + '><summary>' + esc(this.str('gapclose')) + ' ' +
                this.help('gapclose') + '</summary><label class="ec-field"><span>' + esc(this.str('gapclosenote')) +
                '</span><input type="text" class="ec-input" data-gapnote placeholder="' + esc(this.str('gapclosenote_ph')) +
                '"></label><div class="ec-actions">' + (hasnys ? '<div class="ec-muted">' + esc(this.str('gapstillnys')) +
                '</div>' : '<button type="button" class="ec-btn ec-btn-sm ec-btn-primary" data-gapclose>' +
                esc(this.str('gapclose')) + '</button>') + '</div></details>';
        }
        return html;
    }

    /**
     * Text field with optional dictation.
     *
     * @param {String} name
     * @param {String} label
     * @param {String} value
     * @param {Boolean} editable
     * @param {String} help
     * @param {String} kind 'cell' or 'gap'
     * @returns {String}
     */
    textField(name, label, value, editable, help = '', kind = 'cell') {
        const attr = kind === 'gap' ? 'data-gap="' + name + '"' : 'data-text="' + name + '"';
        const mic = editable && this.s.dictation && Dictation.supported() ? '<button type="button" class="ec-mic" ' +
            'data-mic aria-label="' + esc(this.str('dictate')) + '" title="' + esc(this.str('dictate')) + '">' + ICON.mic +
            '</button>' : '';
        return '<label class="ec-field ec-field-text"><span>' + esc(label) + (help ? ' <small>' + esc(help) + '</small>' : '') +
            ' ' + this.help(name) + '</span><span class="ec-textwrap"><textarea rows="3" class="ec-input" ' + attr +
            (editable ? '' : ' disabled') +
            '>' + esc(value || '') + '</textarea>' + mic + '</span></label>';
    }

    /**
     * Seconds to m:ss.
     *
     * @param {Number} s
     * @returns {String}
     */
    mmss(s) {
        return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
    }

    /**
     * Bind panel controls.
     *
     * @param {HTMLElement} sheet
     * @param {Object} it
     */
    bindPanel(sheet, it) {
        const cellid = this.panel.cellid;
        sheet.querySelectorAll('[data-pick]').forEach(b => b.addEventListener('click', () => {
            this.panel.cellid = b.dataset.pick;
            this.renderPanel();
        }));
        sheet.querySelectorAll('[data-set]').forEach(b => b.addEventListener('click', async() => {
            const cur = this.currentCell();
            const val = cur && cur.r === b.dataset.set ? '' : b.dataset.set;
            await this.call('set_result', {itemid: it.id, cellid, value: val});
        }));
        sheet.querySelectorAll('[data-mic]').forEach(b => {
            Dictation.attach(b, b.parentNode.querySelector('textarea'));
        });
        sheet.querySelectorAll('[data-text]').forEach(t => t.addEventListener('change', async() => {
            await this.call('set_cell_text', {itemid: it.id, cellid, field: t.dataset.text, value: t.value});
            this.toast(this.str('saved'));
        }));
        const save = sheet.querySelector('[data-savegap]');
        if (save) {
            const savegap = async() => {
                const get = n => sheet.querySelector('[data-gap="' + n + '"]').value;
                const d = get('reobservedate');
                await this.call('save_gap', {itemid: it.id, observed: get('observed'), strategy: get('strategy'),
                    reobservedate: d ? Math.floor(new Date(d + 'T09:00:00').getTime() / 1000) : 0});
                this.toast(this.str('saved'));
            };
            save.addEventListener('click', savegap);
            // Typing is saved when the field loses focus, so nothing is lost if the assessor moves on.
            sheet.querySelectorAll('.ec-gapsec [data-gap]').forEach(f => f.addEventListener('change', savegap));
        }
        const gclose = sheet.querySelector('[data-gapclose]');
        if (gclose) {
            gclose.addEventListener('click', async() => {
                await this.call('set_gap_status', {itemid: it.id, close: true,
                    note: (sheet.querySelector('[data-gapnote]') || {}).value || ''});
                this.toast(this.str('gapclosedtoast', it.number));
            });
        }
        const greopen = sheet.querySelector('[data-gapreopen]');
        if (greopen) {
            greopen.addEventListener('click', () => this.call('set_gap_status', {itemid: it.id, close: false, note: ''}));
        }
        const tbtn = sheet.querySelector('[data-timer]');
        if (tbtn) {
            tbtn.addEventListener('click', async() => {
                if (this.timer) {
                    clearInterval(this.timer.int);
                    const secs = Math.floor((Date.now() - this.timer.start) / 1000);
                    this.timer = null;
                    await this.call('set_cell_text', {itemid: it.id, cellid, field: 'timerseconds', value: String(secs)});
                    return;
                }
                const start = Date.now();
                const val = sheet.querySelector('[data-timer-val]');
                tbtn.textContent = this.str('stop');
                tbtn.classList.add('ec-btn-rec');
                this.timer = {start, int: setInterval(() => {
                    val.textContent = this.mmss(Math.floor((Date.now() - start) / 1000));
                }, 250)};
            });
        }
        sheet.querySelectorAll('[data-cellupload]').forEach(inp => inp.addEventListener('change', () => {
            if (inp.files && inp.files[0]) {
                this.uploadFile('cellmedia', inp.files[0], it.id, cellid, inp.dataset.kind || '');
                inp.value = '';
            }
        }));
        const rec = sheet.querySelector('[data-rec]');
        if (rec) {
            rec.addEventListener('click', async() => {
                if (this.recorder) {
                    const r = this.recorder;
                    this.recorder = null;
                    clearInterval(this.recTick);
                    const blob = await r.stop();
                    const ext = blob.type.indexOf('mp4') !== -1 ? 'm4a' : blob.type.indexOf('ogg') !== -1 ? 'ogg' : 'webm';
                    this.uploadFile('cellmedia', blob, it.id, cellid, 'audio', 'recording.' + ext);
                    return;
                }
                try {
                    this.recorder = new Recorder();
                    await this.recorder.start();
                } catch (err) {
                    this.recorder = null;
                    this.toast(err.message, 'error');
                    return;
                }
                rec.classList.add('ec-btn-rec');
                this.recTick = setInterval(() => {
                    if (this.recorder) {
                        rec.innerHTML = ICON.mic + ' ' + esc(this.str('stoprecording')) + ' ' + this.mmss(this.recorder.elapsed());
                    }
                }, 500);
            });
        }
        sheet.querySelectorAll('[data-del]').forEach(b => b.addEventListener('click', async() => {
            await this.call('delete_media', {fileid: +b.dataset.del});
        }));
        this.bindLinks(sheet);
    }

    /**
     * Bind the "Also covers" and "Remove link" buttons inside an element.
     * Delegated, so it keeps working when only the media list is redrawn.
     *
     * @param {HTMLElement} el
     */
    bindLinks(el) {
        if (el.dataset.linksBound) {
            return;
        }
        el.dataset.linksBound = '1';
        el.addEventListener('click', async e => {
            const lb = e.target.closest('[data-link]');
            if (lb) {
                e.preventDefault();
                const found = this.findMedia(+lb.dataset.link);
                if (found) {
                    this.openLinkPicker(found.media, found.key);
                }
                return;
            }
            const ub = e.target.closest('[data-unlink]');
            if (ub) {
                e.preventDefault();
                const found = this.findMedia(+ub.dataset.unlink, ub.dataset.from);
                if (!found) {
                    return;
                }
                const here = this.panel ? this.panel.itemid + '#' + this.panel.cellid : '';
                const keep = (found.media.links || []).filter(k => k !== here);
                await this.call('link_media', {fileid: found.media.id, cells: keep.join(',')});
                this.toast(this.str('unlinked'));
            }
        });
    }

    /**
     * Find a file on its own cell (not a linked copy).
     *
     * @param {Number} fileid
     * @param {String} key optional cell key to look in first
     * @returns {Object|null} {media, key}
     */
    findMedia(fileid, key = '') {
        const cells = this.state ? this.state.cells : {};
        const keys = key && cells[key] ? [key].concat(Object.keys(cells)) : Object.keys(cells);
        for (const k of keys) {
            const m = (cells[k].media || []).find(x => x.id === fileid && !x.linked);
            if (m) {
                return {media: m, key: k};
            }
        }
        return null;
    }

    /**
     * Pick the other criteria one evidence file also covers. Linking never marks anything.
     *
     * @param {Object} m media entry on its own cell
     * @param {String} key its cell key 'itemid#cellid'
     */
    openLinkPicker(m, key) {
        const srcItem = this.items[+key.split('#')[0]];
        const srcCell = key.split('#')[1];
        const have = m.links || [];
        // First time: suggest the rest of the same section in the same column. The assessor unticks what the file does not show.
        const suggest = !have.length;
        let body = '<p class="ec-muted ec-linkintro">' + esc(this.str('linkintro')) + '</p>';
        body += '<div class="ec-linkbar"><button type="button" class="ec-btn ec-btn-sm" data-lall>' +
            esc(this.str('link_selectall')) + '</button><button type="button" class="ec-btn ec-btn-sm" data-lnone>' +
            esc(this.str('link_selectnone')) + '</button><span class="ec-linkcount" data-lcount></span></div>' +
            '<div class="ec-linklist">';
        this.s.groups.forEach(g => {
            const rows = g.items.map(it => {
                const boxes = it.cells.map(cid => {
                    const k = it.id + '#' + cid;
                    if (k === key) {
                        return '<span class="ec-linkbox ec-linkbox-src">' + esc(this.str('linkhere')) + '</span>';
                    }
                    const on = have.indexOf(k) !== -1 || (suggest && it.groupid === srcItem.groupid && cid === srcCell);
                    const lbl = it.cells.length > 1 || this.s.columns.length > 1 ?
                        (this.s.columns[this.colIndex[cid]] || {label: cid}).label : this.str('linkthis');
                    return '<label class="ec-linkbox"><input type="checkbox" data-lk="' + esc(k) + '"' + (on ? ' checked' : '') +
                        '><span>' + esc(lbl) + '</span></label>';
                }).join('');
                return '<div class="ec-linkrow"><span class="ec-num">' + esc(it.number) + '</span><span class="ec-linktext">' +
                    esc(this.stripTags(it.criterion)) + '</span><span class="ec-linkboxes">' + boxes + '</span></div>';
            }).join('');
            body += '<div class="ec-linkgroup"><div class="ec-label">' + esc(g.number + '. ' + g.name) + '</div>' + rows + '</div>';
        });
        body += '</div><div class="ec-actions ec-linkactions"><button type="button" class="ec-btn" data-mclose>' +
            esc(this.str('link_cancel')) + '</button><button type="button" class="ec-btn ec-btn-primary" data-lsave>' +
            esc(this.str('savelinks')) + '</button></div>';
        const w = this.modal(this.str('alsocovers_title', this.cellName(srcItem, srcCell)), body, 'ec-modal-wide ec-linkmodal');
        const boxes = [...w.querySelectorAll('[data-lk]')];
        const count = () => {
            const n = boxes.filter(b => b.checked).length;
            w.querySelector('[data-lcount]').textContent = this.str('linkcount', n);
        };
        boxes.forEach(b => b.addEventListener('change', count));
        w.querySelector('[data-lall]').addEventListener('click', () => {
            boxes.forEach(b => {
                b.checked = true;
            });
            count();
        });
        w.querySelector('[data-lnone]').addEventListener('click', () => {
            boxes.forEach(b => {
                b.checked = false;
            });
            count();
        });
        count();
        w.querySelector('[data-lsave]').addEventListener('click', async() => {
            const keys = boxes.filter(b => b.checked).map(b => b.dataset.lk);
            w.close();
            try {
                await this.call('link_media', {fileid: m.id, cells: keys.join(',')});
                this.toast(keys.length ? this.str('linkedsaved', keys.length) : this.str('unlinked'));
            } catch (e) {
                // The error toast is already shown.
            }
        });
    }

    /**
     * Upload a file (photo compressed first).
     *
     * @param {String} area cellmedia or sessionphoto
     * @param {Blob} file
     * @param {Number|null} itemid
     * @param {String|null} cellid
     * @param {String} kind
     * @param {String} name
     * @param {String} okmsg toast when saved
     */
    async uploadFile(area, file, itemid, cellid, kind = '', name = '', okmsg = '') {
        const prog = this.panelEl && this.panelEl.querySelector('[data-upprog]');
        const setProg = pct => {
            if (prog) {
                prog.hidden = false;
                prog.firstChild.style.width = pct + '%';
            }
        };
        if (file.type && file.type.startsWith('video/') && this.s.maxvideo) {
            const secs = await this.duration(file);
            if (secs > this.s.maxvideo + 1) {
                this.toast(this.str('media_toolarge') + ' (' + Math.round(secs) + 's > ' + this.s.maxvideo + 's)', 'error');
                return;
            }
        }
        let blob = file;
        let filename = name || file.name || 'evidence';
        if (file.type && file.type.startsWith('image/')) {
            blob = await compressImage(file, this.s.maxpx);
            if (blob !== file) {
                filename = filename.replace(/\.[^.]+$/, '') + '.jpg';
            }
        }
        setProg(2);
        const busy = this.toast(this.str('uploading'), 'info', null, 120000);
        try {
            const res = await upload(this.cfg.urls.upload, {cmid: this.s.cmid, userid: this.cfg.userid, area,
                itemid: itemid || 0, cellid: cellid || '', kind, sesskey: this.cfg.sesskey}, blob, filename, setProg);
            this.applyState(res.state);
            const key = itemid + '#' + cellid;
            const cell = area === 'cellmedia' && this.state && this.state.cells[key];
            const own = cell ? (cell.media || []).filter(x => !x.linked) : [];
            const newest = own.reduce((a, x) => (!a || x.id > a.id ? x : a), null);
            if (newest && this.canMark) {
                this.toast(okmsg || this.str('saved'), 'info', {label: this.str('alsocovers'),
                    fn: () => this.openLinkPicker(newest, key)}, 6000);
            } else {
                this.toast(okmsg || this.str('saved'));
            }
        } catch (err) {
            this.toast(err.message, 'error', null, 5000);
        } finally {
            busy.remove();
            if (prog) {
                prog.hidden = true;
            }
        }
    }

    /**
     * Video duration in seconds.
     *
     * @param {Blob} file
     * @returns {Promise<Number>}
     */
    duration(file) {
        return new Promise(resolve => {
            const v = document.createElement('video');
            v.preload = 'metadata';
            v.onloadedmetadata = () => {
                URL.revokeObjectURL(v.src);
                resolve(isFinite(v.duration) ? v.duration : 0);
            };
            v.onerror = () => resolve(0);
            v.src = URL.createObjectURL(file);
        });
    }

    // Modals.

    /**
     * Open a modal.
     *
     * @param {String} title
     * @param {String} body
     * @param {String} cls
     * @returns {HTMLElement}
     */
    modal(title, body, cls = '') {
        const wrap = document.createElement('div');
        wrap.className = 'ec-root ec-modal-wrap';
        wrap.style.cssText = this.root.getAttribute('style') || '';
        wrap.innerHTML = '<div class="ec-sheet-backdrop" data-mclose></div><div class="ec-modal ' + cls + '" role="dialog" ' +
            'aria-modal="true" aria-label="' + esc(title) + '"><header class="ec-sheet-head"><div class="ec-sheet-title">' +
            esc(title) + '</div><button type="button" class="ec-iconbtn" data-mclose aria-label="' + esc(this.str('close')) +
            '">' + ICON.close + '</button></header><div class="ec-modal-body">' + body + '</div></div>';
        document.body.appendChild(wrap);
        const close = () => {
            wrap.classList.remove('ec-in');
            setTimeout(() => wrap.remove(), 220);
        };
        wrap.addEventListener('click', e => {
            if (e.target.closest('[data-mclose]')) {
                close();
            }
        });
        const onkey = e => {
            if (e.key === 'Escape') {
                close();
            }
        };
        document.addEventListener('keydown', onkey);
        wrap.close = () => {
            document.removeEventListener('keydown', onkey);
            close();
        };
        wrap.addEventListener('click', e => {
            if (e.target.closest('[data-mclose]')) {
                document.removeEventListener('keydown', onkey);
            }
        });
        requestAnimationFrame(() => wrap.classList.add('ec-in'));
        return wrap;
    }

    /**
     * Guide panel listing every tip.
     */
    openGuide() {
        const body = ORDER.map(id => this.tip(id, true)).join('');
        this.modal(this.str('guide'), '<div class="ec-guide">' + body + '</div>', 'ec-modal-wide');
    }

    /**
     * One-step sign-off.
     */
    openSignoff() {
        const st = this.state;
        const p = st.progress;
        const blockers = st.blockers || [];
        const bulkcells = [];
        Object.keys(st.cells).forEach(k => {
            const c = st.cells[k];
            if (c.bulk && c.r === 'S' && !c.c && !(c.media && c.media.length)) {
                const [itemid, cellid] = k.split('#');
                const it = this.items[itemid];
                const col = this.s.columns[this.colIndex[cellid]] || {label: ''};
                if (it) {
                    bulkcells.push(it.number + ' ' + col.label);
                }
            }
        });
        const gaps = Object.keys(st.gaps).filter(k => st.gaps[k].status === 'open').length;
        const cancomp = !blockers.length;
        const studentsigned = st.signatures.some(s => s.role === 'student');
        let body = '<div class="ec-signoff-summary"><div class="ec-bar' + (p.complete ? ' ec-bar-done' : '') + '"><span ' +
            'class="ec-bar-s" style="width:' + (p.s / Math.max(1, p.required) * 100) + '%"></span><span class="ec-bar-nys" ' +
            'style="width:' + (p.nys / Math.max(1, p.required) * 100) + '%"></span></div><div class="ec-signoff-stats">' +
            '<div><b>' + p.s + '</b>/' + p.required + ' S</div><div class="ec-gaptext"><b>' + gaps + '</b> ' +
            esc((gaps === 1 ? this.str('gapone') : this.str('gapsn', gaps)).replace(/^\d+\s*/, '')) + '</div></div></div>';
        if (blockers.length) {
            body += '<div class="ec-card ec-callout ec-callout-gap">' + blockers.map(esc).join('<br>') + '</div>';
        }
        body += '<div class="ec-label">' + esc(this.str('decision')) + ' ' + this.help('decision') +
            '</div><div class="ec-decide">' +
            '<button type="button" class="ec-big ec-big-S' + (cancomp ? ' ec-on' : '') + '" data-decide="competent"' +
            (cancomp ? '' : ' disabled title="' + esc(this.str('competentblocked')) + '"') + '>' + ICON.S + '<span>' +
            esc(this.str('competent')) + '</span></button><button type="button" class="ec-big ec-big-NYS' +
            (cancomp ? '' : ' ec-on') + '" data-decide="nyc">' + ICON.NYS + '<span>' + esc(this.str('nyc')) +
            '</span></button></div>';
        body += this.textField('feedback', this.str('feedback'), st.attempt.feedback || '', true, '', 'gap');
        if (bulkcells.length) {
            body += '<div class="ec-card ec-callout"><div class="ec-label">' + esc(this.str('bulkreview')) + '</div>' +
                '<div class="ec-bulklist">' + bulkcells.map(c => '<span class="ec-chip">' + esc(c) + '</span>').join('') +
                '</div><label class="ec-statement"><input type="checkbox" data-bulkok><span class="ec-check"></span><span>' +
                esc(this.str('bulkconfirm')) + ' ' + this.help('bulkok') + '</span></label></div>';
        }
        body += '<div class="ec-sigrow">';
        if (!studentsigned) {
            body += '<div class="ec-sigbox"><div class="ec-label">' + esc(this.str('studentsignature')) + ' ' +
                this.help('signature') + '</div>' +
                '<canvas class="ec-sigpad" data-pad="student"></canvas><button type="button" class="ec-link" data-padclear=' +
                '"student">' + esc(this.str('clear')) + '</button></div>';
        }
        body += '<div class="ec-sigbox"><div class="ec-label">' + esc(this.str('assessorsignature')) + ' ' +
            this.help('signature') + '</div>' +
            '<canvas class="ec-sigpad" data-pad="assessor"></canvas><button type="button" class="ec-link" data-padclear=' +
            '"assessor">' + esc(this.str('clear')) + '</button><input class="ec-input ec-input-sm" data-typed value="' +
            esc(this.cfg.me) + '" aria-label="' + esc(this.str('typename')) + '">' + this.help('typedname') + '</div></div>';
        body += '<label class="ec-statement ec-declare"><input type="checkbox" data-declare><span class="ec-check"></span><span>' +
            esc(this.str('declaration')) + ' ' + this.help('declare') + '</span></label>';
        body += this.tips(['signoff', 'credential']);
        body += '<div class="ec-actions ec-actions-end"><button type="button" class="ec-btn ec-btn-ghost" data-mclose>' +
            esc(this.str('close')) + '</button><button type="button" class="ec-btn ec-btn-primary ec-btn-lg" data-finalise>' +
            esc(this.str('finalise')) + '</button></div>';
        const m = this.modal(this.str('signoff'), body, 'ec-modal-wide');
        const pads = {};
        m.querySelectorAll('canvas[data-pad]').forEach(c => {
            pads[c.dataset.pad] = new SignaturePad(c);
        });
        m.querySelectorAll('[data-padclear]').forEach(b => b.addEventListener('click', () => pads[b.dataset.padclear].clear()));
        m.querySelectorAll('[data-mic]').forEach(b => Dictation.attach(b, b.parentNode.querySelector('textarea')));
        let decision = cancomp ? 'competent' : 'nyc';
        m.querySelectorAll('[data-decide]').forEach(b => b.addEventListener('click', () => {
            decision = b.dataset.decide;
            m.querySelectorAll('[data-decide]').forEach(x => x.classList.toggle('ec-on', x === b));
        }));
        m.querySelector('[data-finalise]').addEventListener('click', async() => {
            const bulk = m.querySelector('[data-bulkok]');
            if (bulk && !bulk.checked) {
                this.toast(this.str('bulkconfirm'), 'error');
                return;
            }
            if (!m.querySelector('[data-declare]').checked) {
                this.toast(this.str('declaration'), 'error');
                return;
            }
            const aimg = pads.assessor.toDataURL();
            const typed = m.querySelector('[data-typed]').value.trim();
            if (!aimg && !typed) {
                this.toast(this.str('signhere'), 'error');
                return;
            }
            try {
                if (pads.student && pads.student.toDataURL()) {
                    await this.call('save_signature', {role: 'student', image: pads.student.toDataURL(),
                        typedname: st.student.name});
                }
                await this.call('save_signature', {role: 'assessor', image: aimg, typedname: typed});
                await this.call('finalise', {decision, feedback: m.querySelector('[data-gap="feedback"]').value});
                m.close();
                this.toast(this.str('finalised'));
            } catch (e) {
                // Error already shown.
            }
        });
    }
}

/**
 * Init.
 *
 * @param {String} selector
 */
export const init = selector => {
    const root = document.querySelector(selector);
    if (!root) {
        return;
    }
    const cfg = JSON.parse(document.getElementById(root.id + '-data').textContent);
    root.ecApp = new App(root, cfg);
};
