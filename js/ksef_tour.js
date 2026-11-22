/* Copyright (C) 2025-2026 InPoint Automation Sp z o.o.
 * License: AGPL-3.0-or-later
 *
 * KSeF help tours
 */
(function () {
    'use strict';
    var cfg = window.ksefHelpCfg;
    if (!cfg || window.ksefTourLoaded) {
        return;
    }
    window.ksefTourLoaded = true;
    var KEY = 'ksefTour';
    var ui = cfg.ui || {};
    var state = null;
    var layer = null;
    var ring = null;
    var target = null;
    var tail = null;
    var box = null;
    var follower = null;
    var keep = false;
    var glue = null;
    var hold = null;
    var paused = null;
    var nextEl = null;
    var viaAlt = false;
    var doneAvoid = '';
    var sideRefs = '';
    var named = null;
    var MODAL = 'div[id^="ksef-"][id$="-modal"]';
    var TOAST = '.jnotify-container .jnotify-notification';
    var OVER = '#id-top, #ui-datepicker-div, .select2-container--open .select2-dropdown, .ui-autocomplete, ' + TOAST;
    var ERR = '.jnotify-notification-error, .jnotify-message.error';
    var IDX = ['i', 'clicked', 'min', 'open', 'first', 'from', 'want.from', 'want.to', 'ret.i', 'sent.from', 'via.at'];
    var ENVK = ['sel', 'hash', 'url', 'home', 'alt', 'span', 'prefer', 'refs', 'resel'];
    var leaving = false;
    var LOAD = String(Math.random());
    var renders = 0;

    function load() {
        try {
            return JSON.parse(window.sessionStorage.getItem(KEY) || 'null');
        } catch (e) {
            return null;
        }
    }

    function getp(o, p) {
        p.split('.').forEach(function (k) {
            o = o === null || o === undefined ? undefined : o[k];
        });
        return o;
    }

    function setp(o, p, v) {
        var parts = p.split('.');
        var last = parts.pop();
        parts.forEach(function (k) {
            o = o ? o[k] : null;
        });
        if (o) {
            o[last] = v;
        }
    }

    function keyAt(def, key) {
        for (var k = 0; def && key && k < def.steps.length; k++) {
            if (def.steps[k].key === key) {
                return k;
            }
        }
        return -1;
    }

    // indexes as keys
    function keyed(st) {
        var out = {};
        IDX.forEach(function (p) {
            var v = getp(st, p);
            var s = typeof v === 'number' && st.def ? st.def.steps[v] : null;
            if (s) {
                out[p] = s.key;
            }
        });
        return out;
    }

    // keys as indexes
    function indexed(st) {
        var keys = st.keys || {};
        IDX.forEach(function (p) {
            if (keys[p] === undefined) {
                return;
            }
            var k = keyAt(st.def, keys[p]);
            if (k >= 0) {
                setp(st, p, k);
            } else if (p !== 'i') {
                setp(st, p, null);
            }
        });
        if (typeof st.i !== 'number' || !st.def.steps[st.i]) {
            st.i = 0;
        }
        return st;
    }

    function save() {
        try {
            if (state) {
                state.keys = keyed(state);
                state.key = state.keys.i || '';
                window.sessionStorage.setItem(KEY, JSON.stringify(state));
            } else {
                window.sessionStorage.removeItem(KEY);
            }
        } catch (e) { /* ignore */ }
    }

    // new definition, same place
    function adopt(def) {
        var keys = keyed(state);
        var old = state.def;
        var was = old ? old.steps[keyAt(old, keys.i)] : null;
        if (was && was.gate && keyAt(def, keys.i) < 0) {
            // gate met: on to the start
            var from = typeof state.from === 'number' && old.steps[state.from] ? old.steps[state.from].key : '';
            if (from && keyAt(def, from) >= 0) {
                keys.i = from;
            }
            state.auto = 'gate';
            state.fixKey = null;
        }
        if (keys.i && keyAt(def, keys.i) < 0) {
            for (var k = keyAt(old, keys.i) + 1; k > 0 && k < old.steps.length; k++) {
                if (keyAt(def, old.steps[k].key) >= 0) {
                    keys.i = old.steps[k].key;
                    break;
                }
            }
        }
        state.def = envify(def);
        state.keys = keys;
        indexed(state);
    }

    function envify(def) {
        ((def && def.steps) || []).forEach(function (s) {
            ENVK.forEach(function (k) {
                var t = s['_' + k] = s['_' + k] !== undefined ? s['_' + k] : (s[k] || '');
                s[k] = t.replace(/\{env\}/g, cfg.env).replace(/\{soc\}/g, state && state.obj && state.obj.soc ? state.obj.soc : '');
            });
            if (s.when) {
                s._when = s._when || JSON.stringify(s.when);
                s.when = JSON.parse(s._when.replace(/\{env\}/g, cfg.env));
            }
        });
        return def;
    }

    function here() {
        return window.location.pathname + window.location.search;
    }

    function cur() {
        return state && state.def ? state.def.steps[state.i] : null;
    }

    function onPage(step) {
        try {
            if (!new RegExp(step.page).test(here())) {
                return false;
            }
        } catch (e) {
            return false;
        }
        // invoice in play
        return !(step.bind && state && state.bound && pageId() && pageId() !== state.bound);
    }

    function param(name) {
        var m = new RegExp('[?&]' + name + '=([^&#]*)').exec(window.location.search);
        return m ? decodeURIComponent(m[1]) : '';
    }

    function pageId() {
        var id = param('facid') || param('id');
        return /^\d+$/.test(id) ? id : '';
    }

    function pageRef() {
        var r = document.querySelector('div.refid > span.valignmiddle');
        if (r) {
            return r.textContent.trim();
        }
        // core 22: text
        var d = document.querySelector('div.refid');
        for (var n = d ? d.firstChild : null; n; n = n.nextSibling) {
            if (n.nodeType === 3 && n.nodeValue.trim() !== '') {
                return n.nodeValue.trim();
            }
        }
        return '';
    }

    function remember() {
        var path = window.location.pathname;
        var id = pageId();
        var kind = /\/compta\/facture\/card\.php$/.test(path) ? 'inv' : /\/fourn\/facture\/card\.php$/.test(path) ? 'sup'
            : /\/ksef\/incoming_import\.php$/.test(path) ? 'inc' : /\/commande\/card\.php$/.test(path) ? 'ord'
            : /\/ksef\/sales_import\.php$/.test(path) ? 'sal' : '';
        if (!kind || !id) {
            return;
        }
        var byId = kind === 'inc' || kind === 'ord' || kind === 'sal';
        // its customer
        var so = kind === 'inv' ? document.querySelector('div.refidno a[href*="socid="]') : null;
        var m = so ? /[?&]socid=(\d+)/.exec(so.getAttribute('href')) : null;
        var was = state.obj && state.obj.soc ? state.obj.soc : '';
        state.obj = {kind: kind, id: id, ref: kind === 'inc' || kind === 'sal' ? '' : pageRef(), url: path + (byId ? '?id=' : '?facid=') + id, soc: m ? m[1] : ''};
        if (state.obj.soc !== was) {
            envify(state.def);
        }
    }

    // a tour page
    function known() {
        return state.def.steps.some(function (s) {
            return s.page !== '.' && onPage(s);
        });
    }

    function lineCount() {
        // edited row too
        return document.querySelectorAll('#tablelines tr[id^="row-"], #tablelines tr.tredited').length;
    }

    // unparsable selector
    function has(sel) {
        try {
            return !!document.querySelector(sel);
        } catch (e) {
            return false;
        }
    }

    // live field value
    function valued(sel) {
        var n = null;
        try {
            n = document.querySelector(sel);
        } catch (e) {
            n = null;
        }
        if (!n) {
            return null;
        }
        var v = String(n.value || '').trim();
        return v !== '' && v !== '0' && v !== '-1';
    }

    // condition language
    function test(c) {
        if (!c) {
            return true;
        }
        if (typeof c !== 'string') {
            return [].every.call(c, test);
        }
        c = ids(c);
        var m = /^(empty|filled|flag):(.*)$/.exec(c);
        if (m && m[1] === 'flag') {
            return !!(state && state[m[2]]);
        }
        if (m) {
            return valued(m[2]) === (m[1] === 'filled');
        }
        return c.charAt(0) === '!' ? !has(c.slice(1)) : has(c);
    }

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) {
            n.className = cls;
        }
        if (text !== undefined && text !== null) {
            n.textContent = text;
        }
        return n;
    }

    function clear() {
        if (follower) {
            window.clearInterval(follower);
            follower = null;
        }
        if (layer && layer.parentNode) {
            layer.parentNode.removeChild(layer);
        }
        layer = null;
        ring = null;
        box = null;
        target = null;
        tail = null;
        viaAlt = false;
        hold = null;
        keep = false;
        glue = null;
        paused = null;
        nextEl = null;
        sideRefs = '';
        named = null;
    }

    function stop() {
        state = null;
        save();
        clear();
    }

    function hiddenTwin(n) {
        var r = n.getBoundingClientRect();
        return (r.width <= 1 && r.height <= 1) || r.right + window.pageXOffset < 0 || r.bottom + window.pageYOffset < 0;
    }

    function visible(n) {
        return !!(n && n.getClientRects().length && window.getComputedStyle(n).visibility !== 'hidden' && !hiddenTwin(n));
    }

    function reveal(n) {
        var undo = [];
        var row = n.closest ? n.closest('tr.ksef-section-folded') : null;
        if (row) {
            var head = row.previousElementSibling;
            while (head && !head.classList.contains('ksef-section-head')) {
                head = head.previousElementSibling;
            }
            if (head) {
                var r = head.nextElementSibling;
                var rows = [];
                while (r && !r.classList.contains('ksef-section-head')) {
                    if (r.classList.contains('ksef-section-folded')) {
                        r.classList.remove('ksef-section-folded');
                        rows.push(r);
                    }
                    r = r.nextElementSibling;
                }
                var expanded = head.getAttribute('aria-expanded');
                head.setAttribute('aria-expanded', 'true');
                var chev = head.querySelector('.ksef-section-chevron');
                var chevCls = chev ? chev.className : '';
                if (chev) {
                    chev.className = 'fa fa-chevron-down paddingright ksef-section-chevron';
                }
                undo.push(function () {
                    rows.forEach(function (x) {
                        x.classList.add('ksef-section-folded');
                    });
                    if (expanded === null) {
                        head.removeAttribute('aria-expanded');
                    } else {
                        head.setAttribute('aria-expanded', expanded);
                    }
                    if (chev) {
                        chev.className = chevCls;
                    }
                });
            }
        }
        var body = n.closest ? n.closest('[id^="ksef_env_body_"]') : null;
        if (body && body.style.display === 'none' && typeof window.ksefToggleEnv === 'function') {
            var env = body.id.replace('ksef_env_body_', '');
            window.ksefToggleEnv(env);
            undo.push(function () {
                window.ksefToggleEnv(env);
            });
        }
        var back = function () {
            undo.reverse().forEach(function (fn) {
                fn();
            });
        };
        var next = n.nextElementSibling;
        if (next && next.classList.contains('select2-container') && (n.classList.contains('select2-hidden-accessible') || !visible(n))) {
            return {shown: next, undo: back};
        }
        return {shown: n, undo: back};
    }

    // {obj}: current invoice
    function ids(sel) {
        return String(sel || '').replace(/\{obj\}/g, param('facid') || (state && state.obj && state.obj.id) || '0');
    }

    function selParts(sel) {
        sel = ids(sel);
        var parts = [];
        var depth = 0;
        var quote = '';
        var acc = '';
        for (var k = 0; k < sel.length; k++) {
            var ch = sel.charAt(k);
            if (quote) {
                quote = ch === quote ? '' : quote;
            } else if (ch === '"' || ch === "'") {
                quote = ch;
            } else if (ch === '(' || ch === '[') {
                depth++;
            } else if (ch === ')' || ch === ']') {
                depth--;
            } else if (ch === ',' && depth === 0) {
                parts.push(acc);
                acc = '';
                continue;
            }
            acc += ch;
        }
        parts.push(acc);
        return parts;
    }

    function all(sel, root) {
        var list = [];
        selParts(sel || '').forEach(function (part) {
            if (!part.trim()) {
                return;
            }
            try {
                [].forEach.call((root || document).querySelectorAll(part), function (n) {
                    if (list.indexOf(n) === -1) {
                        list.push(n);
                    }
                });
            } catch (e) { /* ignore */ }
        });
        return list;
    }

    function find(step) {
        // prefer: in order
        var f = null;
        selParts(step.prefer || '').some(function (part) {
            f = part.trim() ? findSel(part) : null;
            return !!f;
        });
        if (f) {
            f.preferred = true;
            return f;
        }
        f = findSel(step.sel) || (state && state.synced && step.resel ? findSel(step.resel) : null);
        if (!f && step.alt) {
            f = findSel(step.alt);
            if (f) {
                f.alt = true;
            }
        }
        return f;
    }

    // found, nothing changed
    function found(step) {
        var f = step.sel ? find(step) : null;
        if (f) {
            f.undo();
        }
        return !!f;
    }

    function candidates(step) {
        var rows = [];
        all(step.sel).forEach(function (n) {
            var tr = n.closest('tr');
            if (tr && rows.indexOf(tr) === -1 && visible(n)) {
                rows.push(tr);
            }
        });
        return rows.length;
    }

    function spanEnd(step, from) {
        var end = null;
        all(step.span).forEach(function (n) {
            if (visible(n) && n !== from && (!end || (end.compareDocumentPosition(n) & 4))) {
                end = n;
            }
        });
        return end;
    }

    function area(n, end) {
        var r = n.getBoundingClientRect();
        if (!end || !end.isConnected) {
            return r;
        }
        var e = end.getBoundingClientRect();
        var left = Math.min(r.left, e.left);
        var top = Math.min(r.top, e.top);
        var right = Math.max(r.right, e.right);
        var bottom = Math.max(r.bottom, e.bottom);
        return {left: left, top: top, right: right, bottom: bottom, width: right - left, height: bottom - top};
    }

    // behind a window
    function covered(n) {
        var ws = document.querySelectorAll(MODAL);
        for (var k = 0; k < ws.length; k++) {
            if (!ws[k].contains(n) && visible(ws[k])) {
                return true;
            }
        }
        return false;
    }

    function findSel(sel) {
        var list = all(sel);
        list.sort(function (a, b) {
            return a.compareDocumentPosition(b) & 4 ? -1 : 1;
        });
        for (var k = 0; k < list.length; k++) {
            var rv = reveal(list[k]);
            var inner = rv.shown.classList.contains('select2-container') ? rv.shown.querySelector('.selection') : null;
            if (visible(rv.shown) && !(inner && !inner.getClientRects().length) && !covered(list[k])) {
                return {orig: list[k], shown: rv.shown, undo: rv.undo};
            }
            rv.undo();
        }
        return null;
    }

    // ---- what comes next

    function skipped(n, path) {
        var s = state.def.steps[n];
        var p = path || state.path;
        var v = state.via;
        if (s && v && p === state.path && s.path === v.path && n <= v.at) {
            return false;
        }
        return !!(s && s.path && p && s.path !== p);
    }

    function applies(k, path) {
        var s = state.def.steps[k];
        if (!s) {
            return false;
        }
        if (s.gate) {
            return s.gate.some(function (g) {
                return !g.met;
            });
        }
        if (skipped(k, path)) {
            return false;
        }
        return !(s.when && onPage(s) && !test(s.when));
    }

    function after(i) {
        var k = i + 1;
        while (k < state.def.steps.length && !applies(k)) {
            k++;
        }
        return k;
    }

    function resolve(i, dir) {
        var steps = state.def.steps;
        var first = null;
        for (var k = i + dir; k >= 0 && k < steps.length; k += dir) {
            var s = steps[k];
            if (!applies(k)) {
                continue;
            }
            if (s.choices || s.warn || s.gate) {
                return {k: k, here: true};
            }
            if (s.dialog && onPage(s)) {
                if (dir > 0 && found(s)) {
                    return {k: k, here: true};
                }
                // window not open
                continue;
            }
            if (!onPage(s)) {
                if (s.optional) {
                    first = first === null ? k : first;
                    continue;
                }
                // page without address
                return {k: dir > 0 && first !== null && (steps[first].url || !s.url) ? first : k, here: false};
            }
            if (!s.sel || found(s) || (dir > 0 && !s.optional)) {
                return {k: k, here: true};
            }
        }
        return first === null ? null : {k: first, here: false};
    }

    // after a click
    function successor(i) {
        for (var k = i + 1; k < state.def.steps.length; k++) {
            var s = state.def.steps[k];
            if (applies(k) && !s.dialog && !s.gate && !s.warn) {
                return k;
            }
        }
        return -1;
    }

    function isLast(i) {
        return !resolve(i, 1);
    }

    function thenIndex(i) {
        var k = keyAt(state.def, state.def.steps[i].then);
        return k >= 0 ? k : after(i);
    }

    function clickedHere(s) {
        return s.wait && state.clicked === state.def.steps.indexOf(s) && state.clickAt && state.clickAt !== LOAD && !(has(ERR) && found(s));
    }

    // where a page lands
    function land(i, dir) {
        var steps = state.def.steps;
        if (dir < 0 || renders > 40) {
            return i;
        }
        // action did not happen
        if (state.at && state.at !== here() && !onPage(steps[i])) {
            for (var p = i - 1; p >= 0; p--) {
                var ps = steps[p];
                if (!applies(p) || (ps.optional && !onPage(ps)) || (onPage(ps) && ps.sel && (!ps.wait || ps.optional) && !found(ps))) {
                    continue;
                }
                if (ps.wait && onPage(ps) && found(ps)) {
                    state.clicked = null;
                    return p;
                }
                break;
            }
        }
        var miss = -1;
        for (var k = i; k < steps.length; k++) {
            var s = steps[k];
            if (!applies(k)) {
                continue;
            }
            if (s.warn || s.gate || s.choices) {
                return k;
            }
            if (k === i && clickedHere(s)) {
                continue;
            }
            if (onPage(s)) {
                if (s.page === '.' && !known()) {
                    return miss >= 0 ? miss : i;
                }
                if (!s.sel || found(s)) {
                    return k;
                }
                miss = miss < 0 && !s.dialog && !s.optional ? k : miss;
                continue;
            }
            if (k === i && s.then && thenIndex(i) > i && onPage(steps[thenIndex(i)])) {
                k = thenIndex(i) - 1;
                continue;
            }
            if (s.optional || (k === i && s.picks && has(s.picks))) {
                continue;
            }
            break;
        }
        return miss >= 0 ? miss : i;
    }

    function stageOf(i, dir) {
        var list = state.def.stages || [];
        var s = state.def.steps[i];
        var n = s && typeof s.stage === 'number' && s.stage >= 0 ? s.stage + 1 : 0;
        if (list.length < 2 || !n || s.warn || s.gate) {
            return null;
        }
        if (dir > 0 && state.stage && state.stage > n) {
            n = state.stage;
        }
        state.stage = n;
        return {n: n, m: list.length, label: list[n - 1]};
    }

    function panel(w) {
        return (w.classList.contains('ui-dialog') ? w : (w.querySelector('.ksef-modal-panel') || w.firstElementChild || w)).getBoundingClientRect();
    }

    function pad(r, p) {
        return {left: r.left - p, top: r.top - p, right: r.right + p, bottom: r.bottom + p};
    }

    function rects(nodes, into) {
        for (var n = 0; n < nodes.length; n++) {
            if (visible(nodes[n]) && !(layer && layer.contains(nodes[n]))) {
                into.push(nodes[n].getBoundingClientRect());
            }
        }
        return into;
    }

    function extent(n) {
        try {
            var rg = document.createRange();
            rg.selectNodeContents(n);
            var e = rg.getBoundingClientRect();
            if (e.width > 0 && e.height > 0) {
                return e;
            }
        } catch (err) { /* ignore */ }
        return n.getBoundingClientRect();
    }

    // open window
    function blocking() {
        var mine = target || hold;
        var ws = document.querySelectorAll(MODAL + ', .ui-dialog');
        for (var k = 0; k < ws.length; k++) {
            var w = ws[k];
            if ((layer && layer.contains(w)) || w.classList.contains('ksef-tour-box') || w.classList.contains('select2-dropdown') || !visible(w) || (mine && w.contains(mine))) {
                continue;
            }
            return w;
        }
        return null;
    }

    // label cells to keep
    function labelCells() {
        if (named && named.box === box && named.target === target) {
            return named.nodes;
        }
        var nodes = [];
        var t = box ? box.querySelector('.ksef-tour-text') : null;
        var qs = [];
        String(t ? t.textContent : '').replace(/\u201e([^\u201d]{1,60})\u201d|"([^"]{1,60})"/g, function (m, a, b) {
            qs.push((a || b).trim().toLowerCase());
            return m;
        });
        var plain = function (n) {
            return !n.querySelector('input:not([type="hidden"]), select, textarea, .select2, a.butAction, button');
        };
        var add = function (cell) {
            if (nodes.indexOf(cell) < 0) {
                nodes.push(cell);
            }
        };
        var line = target && target.tagName === 'TR' ? target : null;
        if (line && !line.closest('table.liste, #tablelines')) {
            // framed rows
            var end = tail ? (tail.tagName === 'TR' ? tail : tail.closest('tr')) : null;
            end = end && end.parentNode === line.parentNode ? end : line;
            for (var tr = line; tr; tr = tr.nextElementSibling) {
                [].forEach.call(tr.children, function (c) {
                    if (plain(c)) {
                        add(c);
                    }
                });
                if (tr === end) {
                    break;
                }
            }
        }
        if (qs.length) {
            // rows the text quotes
            [].forEach.call(document.querySelectorAll('form td, .fichecenter td, form label'), function (c) {
                if ((layer && layer.contains(c)) || c.closest('table.liste, #tablelines') || !plain(c)) {
                    return;
                }
                var tx = (c.textContent || '').replace(/[\s*:]+$/, '').trim().toLowerCase();
                if (tx && qs.indexOf(tx) >= 0) {
                    add(c);
                    var f = c.tagName === 'TD' ? c.nextElementSibling : null;
                    if (f) {
                        [].forEach.call(f.querySelectorAll('input:not([type="hidden"]), select, textarea, .select2'), add);
                    }
                }
            });
        }
        named = {box: box, target: target, nodes: nodes};
        return nodes;
    }

    function obstacles() {
        var o = obstacles0();
        var n = rects(labelCells(), []).map(function (x) {
            return pad(x, 4);
        });
        if (n.length) {
            // dropped last
            o.soft.unshift(n);
        }
        return o;
    }

    function obstacles0() {
        var step = cur();
        var hard = rects(document.querySelectorAll(OVER + ', .tabsAction'), []);
        var soft = [];
        var mine = target || hold;
        var host = mine ? mine.closest(MODAL + ', .ui-dialog') : null;
        [].forEach.call(document.querySelectorAll(MODAL + ', .ui-dialog'), function (w) {
            if (w !== host && visible(w) && !(layer && layer.contains(w)) && !w.classList.contains('ksef-tour-box')) {
                hard.push(panel(w));
            }
        });
        [step ? step.refs : '', sideRefs].forEach(function (rf) {
            rects(rf ? all(rf) : [], []).forEach(function (x) {
                hard.push(pad(x, 8));
            });
        });
        if (!mine) {
            return {hard: hard, soft: soft};
        }
        var ar = target ? area(target, tail) : mine.getBoundingClientRect();
        // taller than the window
        var room = window.innerHeight - topBar() - 16;
        var big = !!(target && step && step.refs && (ar.bottom - ar.top > room
            || (Math.max(document.documentElement.clientWidth - ar.right, ar.left) - 32 < minWidth() && ar.bottom - ar.top > room / 2)));
        if (!big) {
            hard.push(pad(ar, 10));
        }
        if (typeof mine.id === 'string' && mine.id) {
            rects(document.querySelectorAll('label[for="' + mine.id.replace(/"/g, '') + '"]'), hard);
        }
        if (nextEl && nextEl.isConnected && visible(nextEl)) {
            hard.push(pad(nextEl.getBoundingClientRect(), 4));
        }
        if (host) {
            // the button row
            rects(host.querySelectorAll('a, button, input, select, textarea, label, .butAction, p, li, h1, h2, h3, h4, .ui-dialog-buttonpane, .ksef-modal-actions'), hard);
            // the preview last
            soft.push(rects(host.querySelectorAll('iframe, img, pre, table'), []));
            soft.push([panel(host)]);
        }
        if (!target) {
            return {hard: hard, soft: soft};
        }
        if (/^(TABLE|TBODY|TR|TD|DIV|FORM|FIELDSET|UL|LI)$/.test(target.tagName)) {
            // a framed group: its controls
            rects(target.querySelectorAll('input:not([type="hidden"]), select, textarea, button, a.butAction, .select2'), hard);
        }
        var last = big ? [[pad(ar, 10)]] : [];
        var item = target.closest('table.liste tr');
        // outer list row
        while (item && !item.parentNode.closest('table').classList.contains('liste')) {
            item = item.parentNode.closest('table.liste tr');
        }
        if (item) {
            var table = item.closest('table.liste');
            // rows to pick
            all([step.sel, step.resel, step.prefer].filter(Boolean).join(','), table).forEach(function (n) {
                // a row picked whole
                var cell = n.tagName === 'TR' ? n : n.closest('td');
                if (cell && visible(n)) {
                    // clipped sub-lines too
                    var e = extent(cell);
                    var cr = cell.getBoundingClientRect();
                    hard.push(pad({left: e.left, top: e.top, right: Math.max(e.right, cr.left + cell.scrollWidth), bottom: e.bottom}, 12));
                }
            });
            return {hard: hard, soft: soft.concat(last), big: big};
        }
        var line = target.closest('#tablelines tr');
        if (line && line !== target) {
            var dates = line.nextElementSibling;
            soft.push(rects(dates && dates.id === 'trlinefordates' ? [line, dates] : [line], []));
            return {hard: hard, soft: soft.concat(last), big: big};
        }
        var row = target.closest('.tagtr');
        var cell2 = target.closest('td, th');
        if (row || (cell2 && cell2 !== target)) {
            soft.push([extent(row || cell2)]);
        }
        return {hard: hard, soft: soft.concat(last), big: big};
    }

    function free(s, bs, bw, bh, vw, vh, top) {
        if (s.left < 8 || s.top < top || s.left + bw > vw - 8 || s.top + bh > vh - 8) {
            return false;
        }
        for (var m = 0; m < bs.length; m++) {
            // a pixel apart
            if (!(s.left + bw < bs[m].left || s.left > bs[m].right || s.top + bh < bs[m].top || s.top > bs[m].bottom)) {
                return false;
            }
        }
        return true;
    }

    function topBar() {
        var top = 8;
        rects(document.querySelectorAll('#id-top'), []).forEach(function (t) {
            top = Math.max(top, t.bottom + 8);
        });
        return top;
    }

    // ring, then grid
    function search(r, obs, bw, bh, loose, only) {
        var vw = document.documentElement.clientWidth;
        var vh = window.innerHeight;
        var top = topBar();
        var x = Math.max(8, Math.min(r.left, vw - bw - 8));
        var y = Math.max(top, Math.min(r.top, vh - bh - 8));
        var ringSpots = loose ? [] : [{left: r.right + 16, top: y}, {left: x, top: r.bottom + 14}, {left: x, top: r.top - 14 - bh},
            {left: r.left - 16 - bw, top: y}, {left: vw - bw - 8, top: r.bottom + 14}, {left: vw - bw - 8, top: r.top - 14 - bh},
            {left: r.right + 16, top: Math.max(top, r.bottom - bh)}, {left: r.left - 16 - bw, top: Math.max(top, r.bottom - bh)}];
        var cx = (r.left + r.right) / 2;
        var cy = (r.top + r.bottom) / 2;
        for (var t = obs.soft.length; t >= 0; t--) {
            if (only !== undefined && t !== only) {
                continue;
            }
            var bs = obs.hard.concat([].concat.apply([], obs.soft.slice(0, t)));
            for (var k = 0; k < ringSpots.length; k++) {
                if (free(ringSpots[k], bs, bw, bh, vw, vh, top)) {
                    return ringSpots[k];
                }
            }
            var best = null;
            var dist = Infinity;
            for (var gy = top; gy + bh <= vh - 8; gy += 20) {
                for (var gx = 8; gx + bw <= vw - 8; gx += 20) {
                    var s = {left: gx, top: gy};
                    var d = Math.pow(gx + bw / 2 - cx, 2) + Math.pow(gy + bh / 2 - cy, 2);
                    if (d < dist && free(s, bs, bw, bh, vw, vh, top)) {
                        best = s;
                        dist = d;
                    }
                }
            }
            if (best) {
                return best;
            }
        }
        return null;
    }

    function fold(on) {
        box.classList.toggle('ksef-tour-min', on);
        box.setAttribute('data-folded', on ? '1' : '0');
        var m = box.querySelector('.ksef-tour-fold');
        if (m) {
            m.setAttribute('aria-expanded', on ? 'false' : 'true');
        }
    }

    // box at viewport spot
    function put(spot) {
        var o = box.classList.contains('ksef-tour-anchored') ? layer.getBoundingClientRect() : {left: 0, top: 0};
        box.style.left = (spot.left - o.left) + 'px';
        box.style.top = (spot.top - o.top) + 'px';
    }

    // only the text scrolls
    function cap(px) {
        var t = box.querySelector('.ui-dialog-content .ksef-tour-text');
        var cue = box.querySelector('.ksef-tour-scrollcue');
        if (t) {
            t.style.maxHeight = px ? px + 'px' : '';
            t.style.overflowY = px ? 'auto' : '';
        }
        var over = !!(t && px && t.scrollHeight > t.clientHeight + 2);
        if (over && !cue) {
            cue = el('p', 'ksef-tour-note opacitymedium small ksef-tour-scrollcue');
            cue.appendChild(el('span', 'fas fa-angle-double-down paddingright'));
            cue.appendChild(document.createTextNode(ui.ScrollText || ''));
            t.parentNode.insertBefore(cue, t.nextSibling);
        } else if (!over && cue) {
            cue.parentNode.removeChild(cue);
        }
    }

    // smallest readable box
    function minWidth() {
        var vw = document.documentElement.clientWidth;
        // a short note, or a small window
        var floor = (box && box.getAttribute('data-kind') === 'paused') || vw < 1200 ? 160 : 300;
        return Math.min(floor, vw - 16);
    }

    function settle(r, obs, loose) {
        var userFold = !!(state && state.min === state.i);
        fold(userFold);
        box.style.width = '';
        cap(0);
        var cs = box.querySelector('.ui-dialog-content');
        if (cs) {
            cs.style.maxHeight = '';
            cs.style.overflowY = '';
        }
        var spot = null;
        var mw = minWidth();
        // every size before dropping a tier
        var sizes = userFold ? [[0, 0]] : (loose ? [[480, 0], [0, 0], [mw, 0]] : [[0, 0], [480, 0], [mw, 0]])
            .concat([[480, 240], [380, 240], [mw, 240], [480, 140], [mw, 140], [mw, 90]]);
        for (var t = obs.soft.length; t >= 0 && !spot; t--) {
            sizes.some(function (wc) {
                if (wc[0] > document.documentElement.clientWidth - 16) {
                    return false;
                }
                box.style.width = wc[0] ? wc[0] + 'px' : '';
                cap(wc[1]);
                spot = search(r, obs, box.offsetWidth, box.offsetHeight, loose, t);
                if (spot) {
                    spot.w = wc[0];
                    spot.cap = wc[1];
                }
                return !!spot;
            });
        }
        if (!spot && !userFold && box.getAttribute('data-kind') === 'paused') {
            // a pause note may fold
            box.style.width = '';
            cap(0);
            fold(true);
            spot = search(r, obs, box.offsetWidth, box.offsetHeight);
            if (!spot) {
                fold(false);
            }
        }
        if (!spot) {
            // the most text that covers no more
            var vw0 = document.documentElement.clientWidth;
            [[480, 0], [380, 0], [mw, 0], [480, 240], [380, 240], [mw, 240], [480, 140], [mw, 140], [mw, 90]].forEach(function (wc) {
                if (userFold || wc[0] > vw0 - 16) {
                    return;
                }
                box.style.width = wc[0] + 'px';
                cap(wc[1]);
                var lb = leastBad(r, obs);
                if (!spot || lb.hard < spot.hard) {
                    spot = lb;
                    spot.w = wc[0];
                    spot.cap = wc[1];
                }
            });
            if (!spot) {
                box.style.width = mw + 'px';
                cap(90);
                spot = leastBad(r, obs);
                spot.w = mw;
                spot.cap = 90;
            }
            box.style.width = spot.w + 'px';
            cap(spot.cap);
        }
        var c0 = box.querySelector('.ui-dialog-content');
        var room = window.innerHeight - topBar() - 8;
        if (c0 && box.offsetHeight > room) {
            // a small screen: all of it scrolls
            c0.style.maxHeight = Math.max(80, c0.offsetHeight - (box.offsetHeight - room)) + 'px';
            c0.style.overflowY = 'auto';
            spot.top = Math.min(spot.top, window.innerHeight - box.offsetHeight - 8);
            spot.top = Math.max(spot.top, topBar());
        }
        return spot;
    }

    // never over the target
    function leastBad(r, obs) {
        var vw = document.documentElement.clientWidth;
        var vh = window.innerHeight;
        var bw = box.offsetWidth;
        var bh = box.offsetHeight;
        // a big group may be covered
        var t = obs.big ? {left: 0, top: 0, right: 0, bottom: 0} : pad(r, 6);
        var best = null;
        var cost = Infinity;
        var hardCost = Infinity;
        var soft = [].concat.apply([], obs.soft);
        var over = function (gx, gy, a) {
            return Math.max(0, Math.min(gx + bw, a.right) - Math.max(gx, a.left)) * Math.max(0, Math.min(gy + bh, a.bottom) - Math.max(gy, a.top));
        };
        for (var gy = topBar(); gy + bh <= vh - 8 || gy === topBar(); gy += 16) {
            for (var gx = 8; gx + bw <= vw - 8 || gx === 8; gx += 16) {
                if (!(gx + bw <= t.left || gx >= t.right || gy + bh <= t.top || gy >= t.bottom)) {
                    continue;
                }
                var c = 0;
                obs.hard.forEach(function (a) {
                    c += over(gx, gy, a) * 1000;
                });
                var h = c;
                soft.forEach(function (a) {
                    c += over(gx, gy, a);
                });
                if (c < cost) {
                    cost = c;
                    hardCost = h;
                    best = {left: gx, top: gy, forced: true, hard: h};
                }
            }
        }
        return best || {left: Math.max(8, vw - bw - 8), top: Math.max(8, vh - bh - 8), forced: true, hard: hardCost};
    }

    function collides(b, hard) {
        return hard.some(function (a) {
            return !(b.right <= a.left + 4 || b.left >= a.right - 4 || b.bottom <= a.top + 4 || b.top >= a.bottom - 4);
        });
    }

    function place(fresh) {
        if (!box || !layer) {
            return;
        }
        var pausable = box.hasAttribute('data-pausable');
        if (pausable && blocking() !== paused) {
            render();
            return;
        }
        if (busy()) {
            return;
        }
        var vw = document.documentElement.clientWidth;
        var vh = window.innerHeight;
        if (hold && !target && !paused && visible(hold)) {
            // gated: beside the button
            var hb = hold.getBoundingClientRect();
            var bh0 = box.getBoundingClientRect();
            var obh = obstacles();
            if (fresh || !glue || bh0.bottom > vh + 1 || bh0.top < -1 || (!glue.forced && collides(bh0, obh.hard))) {
                var sh = settle(hb, obh);
                box.style.width = sh.w ? sh.w + 'px' : '';
                cap(sh.cap || 0);
                put(sh);
                glue = {loose: true, forced: !!sh.forced};
            }
            return;
        }
        if (!target || !ring) {
            var host = paused || (hold ? hold.closest(MODAL + ', .ui-dialog') : null);
            var done = box.classList.contains('ksef-tour-done');
            var r0 = host ? panel(host) : done ? {left: vw, right: vw, top: vh, bottom: vh} : {left: vw / 2, right: vw / 2, top: vh / 3, bottom: vh / 3};
            var obs0 = obstacles();
            if (host) {
                obs0.hard.push(r0);
            } else {
                obs0.hard = obs0.hard.concat(rects(document.querySelectorAll('#ksef-help, #ksef-sync-btn, .butAction, .butActionRefused, .tabsElem'), []));
                if (doneAvoid && done) {
                    rects(all(doneAvoid), obs0.hard);
                }
            }
            var bb = box.getBoundingClientRect();
            // outside the window
            var out0 = bb.bottom > vh + 1 || bb.right > vw + 1 || bb.top < -1 || bb.left < -1;
            if (fresh || !glue || out0 || (!glue.forced && collides(bb, obs0.hard))) {
                var s0 = settle(r0, obs0, !host);
                put(s0);
                glue = {loose: true, forced: !!s0.forced};
            }
            return;
        }
        if (viaAlt && state && state.def) {
            var own = findSel(state.def.steps[state.i].sel);
            if (own) {
                own.undo();
                render();
                return;
            }
        }
        if (!target.isConnected || !visible(target)) {
            ring.style.display = 'none';
            var step = cur();
            if (step && step.dialog && !leaving) {
                go(resolve(state.i, -1) ? resolve(state.i, -1).k : state.i, -1);
            }
            return;
        }
        ring.style.display = '';
        var r = area(target, tail);
        var tr0 = target.getBoundingClientRect();
        if (keep && (tr0.top < topBar() - 4 || (tr0.bottom > vh + 4 && tr0.height < vh - topBar() - 16))) {
            bring();
            r = area(target, tail);
            fresh = true;
        }
        var o = layer.getBoundingClientRect();
        // never sideways
        var rl = Math.min(Math.max(r.left - 4, 0), vw - 8);
        var rr = Math.min(r.right + 4, vw);
        ring.style.left = (rl - o.left) + 'px';
        ring.style.top = (r.top - o.top - 4) + 'px';
        ring.style.width = Math.max(rr - rl, 8) + 'px';
        ring.style.height = (r.height + 8) + 'px';
        var obs = null;
        if (glue && !fresh && !glue.loose) {
            box.style.left = (Math.max(8, Math.min(r.left + glue.dx, vw - box.offsetWidth - 8)) - o.left) + 'px';
            box.style.top = (r.top + glue.dy - o.top) + 'px';
            var b = box.getBoundingClientRect();
            obs = obstacles();
            if (glue.forced || (!collides(b, obs.hard) && b.top >= 0 && b.bottom <= vh)) {
                return;
            }
        }
        var spot = settle(r, obs || obstacles());
        glue = {dx: spot.left - r.left, dy: spot.top - r.top, forced: !!spot.forced};
        box.style.width = spot.w ? spot.w + 'px' : '';
        cap(spot.cap || 0);
        put(spot);
    }

    function busy() {
        var ov = document.getElementById('ksef-fetch-overlay');
        var on = !!(ov && ov.classList.contains('info') && visible(ov));
        if (on !== box.classList.contains('ksef-tour-busy')) {
            box.classList.toggle('ksef-tour-busy', on);
            glue = null;
            if (on) {
                try {
                    ov.scrollIntoView({block: 'nearest'});
                } catch (e) { /* ignore */ }
            } else if (target && visible(target)) {
                // target in view
                bring();
            }
        }
        if (!on) {
            return false;
        }
        // short note only
        if (ring) {
            ring.style.display = 'none';
        }
        var ob = ov.getBoundingClientRect();
        var vh = window.innerHeight;
        var top = ob.bottom + 8 + box.offsetHeight <= vh - 8 ? ob.bottom + 8 : Math.max(8, ob.top - 8 - box.offsetHeight);
        put({left: Math.max(8, Math.min(ob.left, document.documentElement.clientWidth - box.offsetWidth - 8)), top: top});
        return true;
    }

    // ---- viewport

    function bring() {
        fit();
        flat();
    }

    // window never sideways
    function flat() {
        if (window.pageXOffset) {
            window.scrollTo(0, window.pageYOffset);
        }
    }

    // inner scroller only
    function sideways(n) {
        var r = n.getBoundingClientRect();
        var vw = document.documentElement.clientWidth;
        for (var p = n.parentElement; p && p !== document.body; p = p.parentElement) {
            if (p.scrollWidth > p.clientWidth + 2 && /auto|scroll/.test(window.getComputedStyle(p).overflowX)) {
                var pr = p.getBoundingClientRect();
                var right = Math.min(pr.right, vw);
                if (r.left < pr.left) {
                    p.scrollLeft += r.left - pr.left - 16;
                } else if (r.left + Math.min(r.width, 60) > right) {
                    p.scrollLeft += r.left + Math.min(r.width, right - pr.left - 32) - right + 16;
                }
                return;
            }
        }
    }

    function fit() {
        var top = topBar();
        var vh = window.innerHeight;
        if (target.closest(MODAL) || target.closest('.ui-dialog')) {
            // window scrolls itself
            try {
                target.scrollIntoView({block: 'nearest', inline: 'nearest'});
            } catch (e) {
                target.scrollIntoView();
            }
            flat();
            return;
        }
        sideways(target);
        var r = area(target, tail);
        var vw = document.documentElement.clientWidth;
        // a capped box may do
        var bh = box ? Math.min(box.offsetHeight, 320) + 24 : 0;
        // with the refs near it
        var u = {left: r.left, right: r.right, top: r.top, bottom: r.bottom};
        var st0 = cur();
        rects(st0 && st0.refs ? all(st0.refs) : [], []).forEach(function (x) {
            if (x.bottom > r.top - vh / 2 && x.top < r.bottom + vh / 2) {
                u = {left: Math.min(u.left, x.left), right: Math.max(u.right, x.right), top: Math.min(u.top, x.top), bottom: Math.max(u.bottom, x.bottom)};
            }
        });
        u.height = u.bottom - u.top;
        // box beside: no room
        var beside = !box || Math.max(vw - u.right, u.left) - 32 >= Math.max(box.offsetWidth, minWidth());
        var t = target.getBoundingClientRect();
        var pickList = !!target.closest('table.liste tr');
        if (!beside && pickList && u.height + bh <= vh - top - 16) {
            // rows below: box above
            window.scrollBy(0, u.top - (top + bh));
        } else if (!beside && u.height + bh <= vh - top - 16 && (u.top < top || u.bottom + bh > vh - 8)) {
            // box fits below
            window.scrollBy(0, u.top - top - (vh - top - u.height - bh) / 2);
        } else if (r.height > vh - top - 16) {
            // the target itself first
            var d = r.top - top;
            if (t.bottom - d > vh - 8) {
                d = t.top - top - Math.max(0, (vh - top - t.height) / 3);
            }
            window.scrollBy(0, d);
        } else if (!beside && r.height + bh <= vh - top - 16 && (r.top < top || r.bottom + bh > vh - 8)) {
            // box fits below
            window.scrollBy(0, r.top - top - (vh - top - r.height - bh) / 2);
        } else if (u.height <= vh - top - 16 && (u.top < top || u.bottom > vh - 8)) {
            // the refs in view too
            window.scrollBy(0, u.top - top - (vh - top - u.height) / 2);
        } else if (r.top < top || r.bottom > vh - 8) {
            window.scrollBy(0, r.top - top - (vh - top - r.height) / 2);
        }
        flat();
    }

    // target still off-screen
    function far() {
        var c = box && target ? box.querySelector('.ui-dialog-content') : null;
        if (!c || c.querySelector('.ksef-tour-scrollright')) {
            return;
        }
        var vw = document.documentElement.clientWidth;
        var st = cur();
        // the target, and fields its text names
        var nodes = [target].concat(st && st.refs ? all(ids(st.refs)).filter(function (n) {
            return /^(INPUT|SELECT|TEXTAREA)$/.test(n.tagName) && visible(n);
        }) : []);
        var off = nodes.some(function (n) {
            var r = n.getBoundingClientRect();
            return r.left + Math.min(r.width, 40) > vw + 2;
        });
        if (!off) {
            return;
        }
        var p = el('p', 'ksef-tour-note ksef-tour-scrollright');
        p.appendChild(el('span', 'fas fa-arrow-right paddingright'));
        p.appendChild(document.createTextNode(ui.ScrollRight || ''));
        c.appendChild(p);
    }

    // ---- box parts

    function dialog(extra) {
        var d = el('div', 'ui-dialog ui-corner-all ui-widget ui-widget-content ui-front ksef-tour-box' + (extra ? ' ' + extra : ''));
        d.setAttribute('role', 'dialog');
        return d;
    }

    function fill(text, step) {
        return (text || '').replace('{page}', step && step.where ? step.where : '');
    }

    function action(label, cls, fn) {
        var b = el('button', 'ui-button ui-corner-all ui-widget ' + cls, label);
        b.type = 'button';
        b.addEventListener('click', fn);
        return b;
    }

    function pane() {
        return el('div', 'ui-dialog-buttonpane ui-widget-content ui-helper-clearfix ksef-tour-nav');
    }

    function dest(into, text) {
        into.appendChild(el('div', 'opacitymedium small ksef-tour-dest', text));
    }

    function titlebar(title, stage) {
        var h = el('div', 'ui-dialog-titlebar ui-corner-all ui-widget-header ui-helper-clearfix ksef-tour-head');
        var t = el('span', 'ui-dialog-title ksef-tour-title');
        t.appendChild(el('span', 'fas fa-route paddingright'));
        t.appendChild(document.createTextNode(title));
        h.appendChild(t);
        if (stage !== false) {
            if (stage) {
                h.appendChild(el('span', 'ksef-tour-count opacitymedium', (ui.StageOf || '{n}/{total}: {label}').replace('{n}', stage.n).replace('{total}', stage.m).replace('{label}', stage.label)));
            }
            // fold the box
            var m = el('button', 'ui-button ui-corner-all ui-widget ui-button-icon-only ksef-tour-fold');
            m.type = 'button';
            m.title = ui.Fold || '';
            m.setAttribute('aria-label', ui.Fold || '');
            m.setAttribute('aria-expanded', 'true');
            m.appendChild(el('span', 'ui-button-icon ui-icon ui-icon-minusthick'));
            m.addEventListener('click', function () {
                var on = !box.classList.contains('ksef-tour-min');
                if (state) {
                    state.min = on ? state.i : null;
                    state.open = on ? null : state.i;
                    save();
                }
                fold(on);
                glue = null;
                place(true);
            });
            h.appendChild(m);
        }
        var x = el('button', 'ui-button ui-corner-all ui-widget ui-button-icon-only ui-dialog-titlebar-close ksef-tour-x');
        x.type = 'button';
        x.title = ui.Close || 'Close';
        x.setAttribute('aria-label', ui.Close || 'Close');
        x.appendChild(el('span', 'ui-button-icon ui-icon ui-icon-closethick'));
        x.addEventListener('click', stop);
        h.appendChild(x);
        return h;
    }

    function header(def, i, dir) {
        if (!def.steps[i].gate && (state.first === undefined || state.first === null || i < state.first)) {
            state.first = i;
        }
        var st = stageOf(i, dir);
        if (st) {
            box.setAttribute('data-stage', st.n + '/' + st.m);
        }
        var h = titlebar(def.title, st);
        if (state.origin && state.origin.title && ui.PartOf) {
            // inside another guide
            h.insertBefore(el('span', 'ksef-tour-partof opacitymedium small', ui.PartOf.replace('{title}', state.origin.title)), h.querySelector('.ksef-tour-fold'));
        }
        return h;
    }

    function arrow(icon, label, cls, fn) {
        var b = action(label, 'ui-button-icon-only ' + cls, fn);
        b.title = label;
        b.insertBefore(el('span', 'ui-button-icon ui-icon ' + icon), b.firstChild);
        if (!fn) {
            b.disabled = true;
            b.classList.add('ui-state-disabled');
        }
        return b;
    }

    function nav(i, kind, last, ahead, opt) {
        var p = pane();
        var arrows = el('div', 'float ksef-tour-arrows');
        var acts = el('div', 'ui-dialog-buttonset ksef-tour-acts');
        var step = state.def.steps[i];
        opt = opt || {};
        var n = kind === 'info' && last && !opt.empty ? nextOf(step) : null;
        // next frame hidden
        var mine = target || hold;
        var must = kind === 'info' && mustLink(step);
        var fixed = !!(step.warn || step.choices || step.gate) || must || kind === 'away' || kind === 'paused' || !!opt.empty || (kind === 'click' && !!(mine && mine.closest(MODAL + ', .ui-dialog')));
        arrows.appendChild(arrow('ui-icon-triangle-1-w', ui.Back, 'ksef-tour-back', i > 0 && !step.warn && kind !== 'paused' ? function () { move(i, -1, true); } : null));
        arrows.appendChild(arrow('ui-icon-triangle-1-e', ui.Next, 'ksef-tour-fwd', !last && !fixed ? function () {
            if (unsaved(i, kind)) {
                state.want = {from: i, to: i, dir: 1, unsaved: true};
                save();
                render();
            } else if (needed(step)) {
                move(i, 1, kind === 'info');
            }
        } : null));
        if (kind === 'info' && last) {
            var home = !opt.empty ? state.origin : null;
            // next already offered
            acts.appendChild(action(ui.Finish, (n || home ? 'ui-priority-secondary' : 'ui-priority-primary') + ' ksef-tour-close ksef-tour-finish', n || home ? stop : finished));
            if (home) {
                backTour(acts, home);
            } else if (n) {
                if (!n.label) {
                    dest(p, (ui.NextTour || '{title}').replace('{title}', n.title));
                }
                nextTour(acts, n, function () { start(n.id, n.step || '', carry()); });
            }
            if (!home) {
                guideList(acts);
            }
        } else {
            acts.appendChild(action(ui.Finish, 'ui-priority-secondary ksef-tour-close', stop));
            if (kind === 'field' && !(step.reloads && !chosen(step))) {
                acts.appendChild(action(ui.Ok, 'ui-priority-primary ksef-tour-ok', function () {
                    if (!needed(step)) {
                        return;
                    }
                    if (last) {
                        finished();
                    } else {
                        move(i, 1, false);
                    }
                }));
            }
            if (kind === 'gate') {
                acts.appendChild(action(ui.Recheck, 'ui-priority-primary ksef-tour-recheck', function () {
                    refetch(render);
                }));
            }
            var to = ahead && !ahead.here ? state.def.steps[ahead.k] : null;
            var real = to && step.wait && kind === 'click' ? state.def.steps[successor(i)] : null;
            to = real && real.where ? real : to;
            if (to && to.where && !opt.empty && (to.url || kind !== 'info') && !(state.want && state.want.from === i)) {
                dest(p, fill(ui.NextTo, to));
            }
            if (kind === 'info' && !must) {
                acts.appendChild(action(ui.Next, 'ui-priority-primary ksef-tour-next', function () { move(i, 1, true); }));
            }
        }
        if (kind !== 'gate') {
            // nothing behind a gate
            p.appendChild(arrows);
        }
        p.appendChild(acts);
        return p;
    }

    // a value is set
    function chosen(step) {
        var n = all(step.sel).filter(function (x) {
            return /^(SELECT|INPUT)$/.test(x.tagName);
        })[0];
        var v = n ? String(n.value || '').trim() : '';
        return v !== '' && v !== '-1' && v !== '0';
    }

    function needed(step) {
        if (!step.need || [].some.call(document.querySelectorAll(step.need), function (n) {
            var v = (n.value || '').trim();
            return v !== '' && v !== '0' && v !== '-1';
        })) {
            return true;
        }
        if (box && !box.querySelector('.ksef-tour-need')) {
            var w = el('p', 'warning ksef-tour-need', ui.NeedValue);
            w.setAttribute('role', 'alert');
            box.querySelector('.ui-dialog-content').appendChild(w);
        }
        return false;
    }

    // next guide
    function nextOf(step) {
        if (step.end && target) {
            return step.end;
        }
        if (step.next && has(step.next.sel)) {
            return step.next;
        }
        return state.def.next || null;
    }

    // chained guide state
    function carry(back) {
        if (!state) {
            return null;
        }
        var o = {prod: !!state.prod, prev: state.id, obj: state.obj || null, origin: state.origin || null};
        if (back) {
            var bk = state.def.steps[keyAt(state.def, back)];
            o.origin = {id: state.id, step: back, title: state.def.title, page: bk ? bk.page : '', obj: state.obj || null};
        }
        return o;
    }

    function nextTour(into, n, fn) {
        var b = action(n.label || ui.NextGuide, 'ui-priority-primary ksef-tour-nexttour', fn);
        b.setAttribute('data-next', n.id);
        into.appendChild(b);
    }

    // all guides
    function guideList(into) {
        if (!cfg.guides) {
            return;
        }
        var a = el('a', 'ui-button ui-corner-all ui-widget ksef-tour-guidelist', ui.GuideList);
        a.href = cfg.guides;
        a.addEventListener('click', function () {
            stop();
        });
        into.appendChild(a);
    }

    // back to sender
    function backTour(into, home, prod) {
        var b = action((ui.BackTour || '{title}').replace('{title}', home.title), 'ui-priority-primary ksef-tour-backtour', function () {
            var keepProd = prod || !!(state && state.prod);
            if (home.obj && home.obj.url && String(pageId()) !== String(home.obj.id)) {
                // its invoice first
                chain(home.id, home.step, {prod: keepProd, obj: home.obj});
                window.location.href = home.obj.url;
                return;
            }
            clear();
            start(home.id, home.step, {prod: keepProd});
        });
        b.setAttribute('data-next', home.id);
        into.appendChild(b);
    }

    function finished() {
        var s = cur();
        var d = state && state.def ? {title: state.def.title, next: state.def.next || null, id: state.def.track === 'setup' ? state.def.id : '', origin: state.origin || null,
            prod: !!state.prod, avoid: s ? s.refs : '', tour: state.id, key: s ? s.key : ''} : null;
        stop();
        if (!d) {
            return;
        }
        try {
            window.sessionStorage.setItem(KEY + 'Done', JSON.stringify(d));
        } catch (e) { /* ignore */ }
        window.setTimeout(function () {
            if (!leaving) {
                doneBox();
            }
        }, 900);
    }

    function doneBox(loaded) {
        var d = null;
        try {
            d = JSON.parse(window.sessionStorage.getItem(KEY + 'Done') || 'null');
            window.sessionStorage.removeItem(KEY + 'Done');
        } catch (e) {
            d = null;
        }
        if (!d || state) {
            return;
        }
        if (loaded && d.tour && d.key && document.querySelector(ERR)) {
            // failed, not done
            start(d.tour, d.key, {prod: d.prod});
            return;
        }
        clear();
        doneAvoid = String(d.avoid || '').replace(/\{env\}/g, cfg.env);
        layer = el('div', 'ksef-tour-layer');
        box = dialog('ksef-tour-done');
        box.setAttribute('data-kind', 'done');
        box.appendChild(titlebar(d.title, false));
        var c = content();
        var p = el('p', 'ksef-tour-text ok');
        p.appendChild(el('span', 'fas fa-check paddingright'));
        p.appendChild(document.createTextNode((ui.Done || '{title}').replace('{title}', d.title)));
        c.appendChild(p);
        box.appendChild(c);
        if (d.id) {
            stillOpen(d.id, c);
        }
        var pn = pane();
        var acts = el('div', 'ui-dialog-buttonset ksef-tour-acts');
        acts.appendChild(action(ui.Close, (d.next || d.origin ? '' : 'ui-priority-primary') + ' ksef-tour-close', clear));
        if (d.origin) {
            backTour(acts, d.origin, d.prod);
        } else if (d.next) {
            dest(pn, (ui.NextTour || '{title}').replace('{title}', d.next.title));
            nextTour(acts, d.next, function () { clear(); start(d.next.id); });
        }
        if (!d.origin) {
            guideList(acts);
        }
        pn.appendChild(acts);
        box.appendChild(pn);
        mount();
    }

    function stillOpen(id, c) {
        fetch(endpoint('action=displayitem&tour=' + encodeURIComponent(id)), {credentials: 'same-origin'}).then(function (r) {
            return r.ok ? r.json() : null;
        }).then(function (j) {
            if (!j || !j.open || !c.isConnected) {
                return;
            }
            var w = el('div', 'warning ksef-tour-stillopen');
            w.appendChild(el('span', 'fas fa-exclamation-triangle paddingright'));
            w.appendChild(document.createTextNode((ui.StillOpen || '').replace('{item}', j.open.item).replace('{detail}', j.open.detail)));
            c.appendChild(w);
            if (j.open.none) {
                // close it as not used
                var np = el('p', 'ksef-tour-note ksef-tour-nonehint', j.open.none.text + ' ');
                var nu = new URL(j.open.none.url, window.location.href);
                nu.searchParams.set('backtopage', backUrl());
                var na = el('a', 'ui-button ui-corner-all ui-widget ui-priority-primary ksef-tour-noneset', j.open.none.label);
                na.href = nu.pathname + nu.search;
                np.appendChild(na);
                c.appendChild(np);
            }
            place(true);
        }).catch(function () { /* ignore */ });
    }

    // unsaved form
    function unsaved(i, kind) {
        var dirty = document.querySelector('form[data-ksef-dirty]');
        if (!dirty) {
            return false;
        }
        var a = resolve(i, 1);
        if (a && !a.here) {
            return true;
        }
        return kind === 'click' && !!(target && dirty.contains(target));
    }

    function content(cls) {
        return el('div', 'ui-dialog-content ui-widget-content ' + (cls || ''));
    }

    function go(i, dir) {
        if (!state) {
            return;
        }
        state.i = Math.max(0, Math.min(i, state.def.steps.length - 1));
        state.at = null;
        state.dir = dir || 1;
        state.want = null;
        state.clicked = null;
        var step = state.def.steps[state.i];
        if (step.path) {
            state.path = step.path;
        }
        save();
        if (!onPage(step) && step.url) {
            window.location.href = step.url;
            return;
        }
        if (onPage(step) && step.hash && window.location.hash !== '#' + step.hash) {
            window.location.hash = step.hash;
        }
        render();
    }

    function move(i, dir, direct) {
        if (!state) {
            return;
        }
        var a = resolve(i, dir);
        if (!a) {
            if (dir > 0) {
                stop();
            }
            return;
        }
        var win = dir < 0 && a.here ? (target || hold) : null;
        win = win ? win.closest(MODAL) : null;
        if (win && pageId() && /\/compta\/facture\/card\.php$/.test(window.location.pathname)) {
            var pf = state.def.steps[a.k].sel ? find(state.def.steps[a.k]) : null;
            var inWin = !!(pf && win.contains(pf.orig));
            if (pf) {
                pf.undo();
            }
            if (!inWin) {
                state.i = a.k;
                state.at = null;
                state.dir = dir;
                state.want = null;
                save();
                window.location.href = window.location.pathname + '?facid=' + pageId();
                return;
            }
        }
        if (a.here || (direct && state.def.steps[a.k].url)) {
            go(a.k, dir);
            return;
        }
        state.want = {from: i, to: a.k, dir: dir};
        save();
        render();
    }

    // a real link
    function goThere(step, k) {
        var a = el('a', 'ui-button ui-corner-all ui-widget ui-priority-primary ksef-tour-go', fill(ui.GoThere, step));
        a.href = step.url || step.home || '#';
        a.addEventListener('click', function (e) {
            e.preventDefault();
            if (!step.url && step.home && state) {
                go(k);
                window.location.href = step.home;
                return;
            }
            go(k);
        });
        return a;
    }

    function refusal(i, kind) {
        var w = state.want;
        if (!w || w.from !== i) {
            return null;
        }
        var s = state.def.steps[w.to];
        var d = el('div', 'warning ksef-tour-refuse');
        d.setAttribute('role', 'alert');
        d.appendChild(el('span', 'fas fa-exclamation-triangle paddingright'));
        if (w.unsaved) {
            d.appendChild(document.createTextNode(ui.SaveFirst));
            return d;
        }
        if (w.dir < 0 && !s.url) {
            d.appendChild(document.createTextNode(fill(ui.PrevElsewhere, s)));
            var back = el('div', 'ksef-tour-refuse-go');
            if (s.home) {
                back.appendChild(goThere(s, w.to));
            }
            wayBack(s, back, d);
            if (back.firstChild) {
                d.appendChild(back);
            }
            return d;
        }
        var text = fill(w.dir < 0 ? ui.PrevElsewhere : ui.NextElsewhere, s);
        if (!s.url && w.dir > 0 && (kind === 'click' || kind === 'field')) {
            text += ' ' + ui.ClickFirst;
        }
        d.appendChild(document.createTextNode(text));
        if (s.url) {
            var p = el('div', 'ksef-tour-refuse-go');
            p.appendChild(goThere(s, w.to));
            d.appendChild(p);
        }
        return d;
    }

    function warnStep(step, i, c) {
        box.classList.add('ksef-tour-warn', 'ksef-tour-prod');
        box.setAttribute('data-env', step.warn);
        box.setAttribute('aria-modal', 'true');
        layer.appendChild(el('div', 'ui-widget-overlay ui-front ksef-tour-shade'));
        var w = el('div', 'error ksef-tour-envwarn');
        var h = el('div', 'ksef-tour-envhead');
        h.appendChild(el('span', 'fas fa-exclamation-triangle paddingright'));
        h.appendChild(document.createTextNode(step.head));
        w.appendChild(h);
        w.appendChild(el('p', 'ksef-tour-text', step.text));
        c.appendChild(w);
        var pn = nav(i, 'none', false);
        var lab = el('label', 'ksef-tour-tick ksef-tour-agree');
        var cb = el('input', 'paddingright');
        cb.type = 'checkbox';
        cb.checked = !!state.prod;
        lab.appendChild(cb);
        lab.appendChild(document.createTextNode(' ' + step.agree));
        c.appendChild(lab);
        var cont = action(ui.EnvProdContinue, 'ui-priority-primary ksef-tour-prod-continue', function () {
            if (cb.checked) {
                state.prod = true;
                save();
                var g = keyAt(state.def, 'gate');
                if (g >= 0 && applies(g)) {
                    go(g);
                } else if (state.from > 0) {
                    go(state.from);
                } else {
                    move(i, 1, true);
                }
            }
        });
        var sync = function () {
            cont.disabled = !cb.checked;
            cont.classList.toggle('ui-state-disabled', !cb.checked);
            if (!cb.checked && state.prod) {
                state.prod = false;
                save();
            }
        };
        cb.addEventListener('change', sync);
        sync();
        pn.lastChild.appendChild(cont);
        if (cfg.authUrl) {
            var a = el('a', 'ksef-tour-envlink', ui.EnvSwitch);
            a.href = cfg.authUrl + '#KSEF_ENVIRONMENT';
            var p = el('p', 'ksef-tour-note');
            p.appendChild(el('span', 'fas fa-cog paddingright opacitymedium'));
            p.appendChild(a);
            c.appendChild(p);
        }
        var r = refusal(i, 'none');
        if (r) {
            c.appendChild(r);
        }
        box.appendChild(pn);
    }

    // what is missing
    function gateStep(step, i, c) {
        box.setAttribute('data-kind', 'gate');
        c.appendChild(el('p', 'ksef-tour-text', step.text));
        var list = el('ul', 'ksef-req-list ksef-tour-gate');
        step.gate.forEach(function (g) {
            if (g.met) {
                return;
            }
            var li = el('li', 'ksef-req');
            li.setAttribute('data-ksef-req', g.key);
            li.setAttribute('data-met', '0');
            li.appendChild(el('span', 'ksef-req-label', g.label));
            if (g.fix) {
                var a = el('a', 'ksef-req-fix', g.fix.label);
                a.href = g.fix.url;
                a.setAttribute('data-kind', g.fix.kind || 'page');
                a.addEventListener('click', function (e) {
                    if (g.fix.confirm && !window.confirm(g.fix.confirm)) {
                        e.preventDefault();
                        return;
                    }
                    if (state) {
                        state.fixKey = g.key;
                        save();
                    }
                });
                if (g.fix.confirm) {
                    a.setAttribute('data-confirm', g.fix.confirm);
                }
                li.appendChild(document.createTextNode(' '));
                li.appendChild(a);
            }
            if (g.detail) {
                li.appendChild(el('div', 'opacitymedium small', g.detail));
            }
            if (g.portal) {
                // taxpayer app
                var pa = el('a', 'ksef-tour-portal', g.portal);
                pa.href = g.portal;
                pa.target = '_blank';
                pa.rel = 'noopener noreferrer';
                var pd = el('div', 'ksef-tour-note');
                pd.appendChild(el('span', 'fas fa-external-link-alt paddingright opacitymedium'));
                pd.appendChild(pa);
                li.appendChild(pd);
            }
            list.appendChild(li);
        });
        // the fix just followed first
        var order = step.gate.filter(function (g) {
            return !g.met && g.fix && g.fix.field;
        }).sort(function (a, b) {
            return (b.key === state.fixKey ? 1 : 0) - (a.key === state.fixKey ? 1 : 0);
        });
        step.refs = '';
        order.some(function (g) {
            var f = findSel(g.fix.field);
            if (f) {
                f.undo();
                // a field, not a block
                target = f.shown.getBoundingClientRect().height < 120 ? f.shown : null;
            }
            if (target) {
                // on the fix page
                var li2 = list.querySelector('li[data-ksef-req="' + g.key + '"]');
                if (g.hint && li2) {
                    li2.appendChild(el('div', 'ksef-tour-note ksef-tour-fixhint', g.hint));
                }
                step.refs = g.refs || '';
            }
            return !!target;
        });
        c.appendChild(list);
        if (target) {
            ring = el('div', 'ksef-tour-ring');
            layer.appendChild(ring);
            box.classList.add('ksef-tour-anchored');
            keep = true;
        }
        box.appendChild(nav(i, 'gate', false));
    }

    function envNote(i) {
        var def = state.def;
        if (!def.envNote) {
            return;
        }
        // once per run
        if (typeof state.noteAt !== 'number') {
            state.noteAt = i;
            save();
        }
        if (state.noteAt !== i) {
            return;
        }
        var w = el('div', 'warning ksef-tour-envnote');
        w.setAttribute('data-env', def.env || cfg.env);
        w.appendChild(el('span', 'fas fa-exclamation-triangle paddingright'));
        w.appendChild(document.createTextNode(def.envNote));
        var c = box.querySelector('.ui-dialog-content');
        c.insertBefore(w, c.firstChild);
    }

    function wayBack(step, gos, note) {
        var o = state.obj;
        var re = null;
        try {
            re = new RegExp(step.page);
        } catch (e) {
            re = null;
        }
        if (!o || !re || !re.test(o.url)) {
            if (!step.url) {
                note.appendChild(el('div', 'ksef-tour-way', gos.firstChild ? ui.BrowserBack : ui.BrowserBackOnly));
            }
            return;
        }
        var label = {inv: ui.BackInv, sup: ui.BackSup, inc: ui.BackInc, ord: ui.BackOrd, sal: ui.BackSales}[o.kind] || ui.BackInv;
        var a = el('a', 'ui-button ui-corner-all ui-widget ksef-tour-backto', (label || '').replace('{ref}', o.ref).replace(/\s+$/, ''));
        a.href = o.url;
        gos.insertBefore(a, gos.firstChild);
        var same = pageId() === o.id;
        var hint = '';
        if (same && o.kind === 'inv' && /\/ksef\/tab_ksef\.php$/.test(window.location.pathname)) {
            hint = ui.OnKsefTab;
        } else if (same && o.kind !== 'inc' && o.kind !== 'sal' && window.location.pathname.replace(/[^\/]*$/, '') === o.url.replace(/[^\/]*$/, '')) {
            hint = ui.OnOtherTab;
        } else {
            hint = ({inv: ui.WayInv, sup: ui.WaySup, inc: ui.WayInc}[o.kind] || '').replace('{ref}', o.ref || '');
        }
        if (hint) {
            note.appendChild(el('div', 'ksef-tour-way', hint));
        }
    }

    // nothing to pick
    function nothing(step, c) {
        var w = el('div', 'warning ksef-tour-none');
        w.setAttribute('role', 'status');
        // the text may scroll
        var nt = el('div', 'ksef-tour-text ksef-tour-nonetext');
        nt.appendChild(el('span', 'fas fa-info-circle paddingright'));
        nt.appendChild(document.createTextNode(state.synced && step.none.after ? step.none.after : step.none.text));
        w.appendChild(nt);
        if (step.none.live) {
            w.appendChild(el('div', 'ksef-tour-way ksef-tour-nonelive', step.none.live));
        }
        var gos = el('div', 'ksef-tour-refuse-go');
        (step.none.tours || []).forEach(function (t) {
            // not back
            if (t.id === state.prev) {
                return;
            }
            var b = action((ui.NoneGuide || '{title}').replace('{title}', t.title), (gos.firstChild ? '' : 'ui-priority-primary ') + 'ksef-tour-noneguide', function () {
                start(t.id, '', carry(step.key));
            });
            b.setAttribute('data-next', t.id);
            gos.appendChild(b);
        });
        var way = step.none.way;
        var to = way ? firstOf(way.path) : -1;
        if (to >= 0) {
            gos.appendChild(action(way.label, 'ksef-tour-noneway', function () {
                state.path = way.path;
                state.via = null;
                go(to);
            }));
        }
        if (gos.firstChild) {
            w.appendChild(gos);
        }
        c.appendChild(w);
    }

    // first step of a way
    function firstOf(path) {
        for (var k = state.i + 1; k < state.def.steps.length; k++) {
            if (state.def.steps[k].path === path && applies(k, path)) {
                return k;
            }
        }
        for (k = 0; k < state.def.steps.length; k++) {
            if (state.def.steps[k].path === path) {
                return k;
            }
        }
        return -1;
    }

    function choices(step) {
        var f = el('table', 'noborder centpercent ksef-tour-choices');
        step.choices.forEach(function (c) {
            var to = firstOf(c.path);
            if (to === -1) {
                return;
            }
            var tr = el('tr', 'oddeven');
            var td = el('td');
            var b = el('a', 'ksef-tour-choice');
            b.href = '#';
            b.addEventListener('click', function (e) {
                e.preventDefault();
                state.path = c.path;
                state.via = null;
                go(to);
            });
            b.appendChild(el('span', 'fas fa-chevron-right paddingright'));
            b.appendChild(document.createTextNode(c.label));
            b.setAttribute('data-path', c.path);
            td.appendChild(b);
            tr.appendChild(td);
            f.appendChild(tr);
        });
        return f;
    }

    // excursion return address
    function backUrl() {
        var u = new URL(window.location.href);
        var f = document.getElementById('formtocreate');
        if (f) {
            var soc = f.querySelector('#socid') || f.querySelector('input[type="hidden"][name="socid"]');
            if (soc && soc.value && soc.value !== '-1' && soc.value !== '0') {
                u.searchParams.set('socid', soc.value);
            }
            var type = f.querySelector('input[name="type"]:checked');
            if (type) {
                u.searchParams.set('type', type.value);
            }
            var cur0 = f.querySelector('#multicurrency_code');
            if (cur0 && cur0.value) {
                u.searchParams.set('multicurrency_code', cur0.value);
            }
            u.searchParams.set('action', 'create');
        }
        return u.pathname + u.search;
    }

    // the way on
    function mustLink(step) {
        return (step.link || []).some(function (lk) {
            return lk.must && (!lk['if'] || test(lk['if']));
        });
    }

    function portal(step, c) {
        (step.link || []).forEach(function (lk) {
            if (!c || (lk['if'] && !test(lk['if']))) {
                return;
            }
            var l = el('p', 'ksef-tour-note ksef-tour-link');
            l.appendChild(el('span', 'fas fa-cog paddingright opacitymedium'));
            var la = el('a', lk.must ? 'ui-button ui-corner-all ui-widget ui-priority-primary ksef-tour-must' : '', lk.label);
            la.href = lk.url;
            la.addEventListener('click', function () {
                if (/\/ksef_fix\.php/.test(lk.url)) {
                    // back to this form, as filled
                    var fu = new URL(lk.url, window.location.href);
                    fu.searchParams.set('backtopage', backUrl());
                    la.href = fu.pathname + fu.search;
                }
                if (state) {
                    state.ret = {url: backUrl(), i: state.i, text: lk.side || '', frame: lk.frame || '', refs: lk.refs || '', done: lk.done || null};
                    save();
                }
            });
            l.appendChild(la);
            c.appendChild(l);
        });
        var url = step.links && c ? step.links[cfg.env] : '';
        if (!url) {
            return;
        }
        var p = el('p', 'ksef-tour-note ksef-tour-portal');
        p.appendChild(el('span', 'fas fa-external-link-alt paddingright opacitymedium'));
        var a = el('a', '', url);
        a.href = url;
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
        p.appendChild(a);
        c.appendChild(p);
    }

    // rows of the invoice
    function rows(c, step) {
        var n = lineCount();
        var id = pageId();
        var was = state.rows && state.rows.id === id ? state.rows : null;
        box.setAttribute('data-lines', String(n));
        if (document.querySelector('#tablelines tr.tredited')) {
            state.rows = {id: id, n: n, edit: true};
            return 'edit';
        }
        var added = !!(was && n > was.n);
        var changed = !added && !!(was && was.edit && n === was.n);
        state.rows = {id: id, n: n, added: added || !!(was && was.added && n === was.n)};
        var text = added || state.rows.added ? ui.LineAdded : changed ? ui.LineChanged : step.addrow ? '' : ui.LinesThere;
        if (n && text) {
            var p = el('p', added || changed || state.rows.added ? 'ok ksef-tour-note ksef-tour-lineadded' : 'ksef-tour-note ksef-tour-linesthere');
            p.appendChild(el('span', 'fas ' + (added || changed || state.rows.added ? 'fa-check' : 'fa-list') + ' paddingright'));
            p.appendChild(document.createTextNode((text || '').replace('{n}', n)));
            c.appendChild(p);
        }
        return step.addrow ? 'line' : 'more';
    }

    function mount() {
        layer.appendChild(box);
        document.body.appendChild(layer);
        follower = window.setInterval(function () {
            place();
        }, 300);
        if (keep && target) {
            bring();
        } else if (hold && !paused && hold.getBoundingClientRect().bottom > window.innerHeight - 8) {
            // the held button in view
            try {
                hold.scrollIntoView({block: 'center'});
            } catch (e) {
                hold.scrollIntoView();
            }
            flat();
        }
        if (target) {
            far();
        }
        place(true);
    }

    // a step on this page
    function sideBox(text, frame, i, c, back, refs) {
        box.setAttribute('data-kind', 'side');
        sideRefs = refs || '';
        c.appendChild(el('p', 'ksef-tour-text', text));
        var f = frame ? findSel(frame) : null;
        if (f) {
            f.undo();
            target = f.shown;
            ring = el('div', 'ksef-tour-ring');
            layer.appendChild(ring);
            box.classList.add('ksef-tour-anchored');
            keep = true;
        }
        var pn = nav(i, 'away', false);
        if (back) {
            var a = el('a', 'ui-button ui-corner-all ui-widget ui-priority-primary ksef-tour-return', ui.Return);
            a.href = back;
            pn.lastChild.appendChild(a);
        }
        box.appendChild(pn);
        envNote(i);
        mount();
    }

    function pauseBox(win, i, c) {
        box.setAttribute('data-kind', 'paused');
        box.classList.add('ksef-tour-paused');
        var reg = cfg.pause || {};
        var id = win.id || (win.querySelector('.ui-dialog-content[id]') || {}).id || '';
        var t = win.querySelector('.ui-dialog-title, .ksef-modal-title, h2, h3');
        var title = t ? t.textContent.trim() : '';
        c.appendChild(el('p', 'ksef-tour-note ksef-tour-pausenote', reg[id] || (ui.Paused || '').replace('{title}', title || ui.ThisWindow || '')));
        var s = state.def.steps[i];
        c.appendChild(el('p', 'ksef-tour-text opacitymedium', textOf(s)));
        box.appendChild(nav(i, 'paused', false));
        mount();
    }

    function textOf(step) {
        var t = step.text;
        (step.vary || []).some(function (v) {
            if (!(v.fresh && state.synced) && test(v.sel)) {
                t = v.text;
                return true;
            }
            return false;
        });
        return t;
    }

    // after a refused submit
    function errorStep(i) {
        var s = state.def.steps[i];
        var to = -1;
        (s.errto || []).some(function (e) {
            to = test(e.when) ? keyAt(state.def, e.key) : -1;
            return to >= 0;
        });
        return to >= 0 ? to : i;
    }

    function render(soft) {
        clear();
        if (!state || !state.def) {
            return;
        }
        renders++;
        var def = state.def;
        var i = state.i;
        if (def.prodGate && cfg.env === 'PRODUCTION' && !def.steps[0].warn) {
            start(state.id);
            return;
        }
        if (def.prodGate && cfg.env === 'PRODUCTION' && !state.prod && i > 0 && def.steps[0].warn) {
            i = state.i = 0;
            state.want = null;
        }
        // origin guide back
        var og = state.origin;
        if (og && og.page && og.obj && pageId() === og.obj.id) {
            try {
                if (new RegExp(og.page).test(here())) {
                    var keepProd = !!state.prod;
                    state = null;
                    save();
                    start(og.id, og.step, {prod: keepProd});
                    return;
                }
            } catch (e) { /* ignore */ }
        }
        if (state.sent && state.sent.load !== LOAD) {
            if (state.sent.from === i && !has(ERR)) {
                i = state.i = after(i);
            }
            state.sent = null;
        }
        if (state.endAt && state.endAt !== here()) {
            if (state.endQuiet) {
                stop();
            } else {
                finished();
            }
            return;
        }
        var want = state.want;
        if (want && (want.from !== i || !def.steps[want.to])) {
            want = state.want = null;
        }
        if (want && state.at && state.at !== here()) {
            if (onPage(def.steps[want.to]) && !onPage(def.steps[i])) {
                i = state.i = want.to;
            }
            want = state.want = null;
        }
        var dir = state.dir || 1;
        state.dir = 1;
        // refused submit
        if (dir > 0 && state.clickAt && state.clickAt !== LOAD && state.clicked === i && has(ERR) && onPage(def.steps[i])) {
            i = state.i = errorStep(i);
            state.clicked = null;
        }
        // side trip back
        var ret = state.ret;
        if (ret && typeof ret.i === 'number' && def.steps[ret.i] && onPage(def.steps[ret.i])) {
            i = state.i = ret.i;
            state.ret = null;
        }
        if (def.steps[i].gate && !applies(i) && state.from > i) {
            // gate passed: on to the start
            i = state.i = state.from;
            state.auto = 'gate';
            state.fixKey = null;
        }
        var k2 = land(i, dir);
        if (k2 !== i && soft && onPage(def.steps[i]) && !def.steps[i].dialog) {
            // page still changing
            window.setTimeout(function () {
                if (!leaving && state && state.i === i && box && box.classList.contains('ksef-tour-missing')) {
                    render();
                }
            }, 1000);
        } else if (k2 !== i) {
            state.first = state.first === i ? null : state.first;
            i = state.i = k2;
        }
        if (state.want && state.want.from !== i) {
            state.want = null;
        }
        state.at = here();
        if (onPage(def.steps[i])) {
            remember();
            if (def.steps[i].page !== '.') {
                state.bound = def.steps[i].bind ? state.bound || pageId() || null : null;
            }
        }
        if (def.steps[i].choices) {
            state.path = null;
            state.via = null;
        } else if (def.steps[i].path) {
            state.path = def.steps[i].path;
        }
        var auto = state.auto;
        state.auto = false;
        save();
        var step = def.steps[i];
        var text = (onPage(step) ? textOf(step) : step.text).replace(/ ?\{ref\}/g, state.obj && state.obj.ref ? ' ' + state.obj.ref : '');
        var startHere = false;
        try {
            startHere = auto === 'gate' && !!(state.startUrl && new RegExp(step.page).test(state.startUrl));
        } catch (e) {
            startHere = false;
        }
        if (auto && !onPage(step) && (step.url || startHere) && !has(ERR)) {
            state.saved = !!state.sub;
            save();
            // the page it started on
            window.location.href = startHere ? state.startUrl : step.url;
            return;
        }
        var last = isLast(i);
        layer = el('div', 'ksef-tour-layer');
        box = dialog();
        box.setAttribute('data-step', step.key);
        box.appendChild(header(def, i, dir));
        save();
        var c = content();
        box.appendChild(c);
        c.appendChild(el('p', 'ksef-tour-busynote', ui.Busy));
        if (state.saved) {
            state.saved = false;
            save();
            c.appendChild(el('p', 'ksef-tour-note ok ksef-tour-saved', ui.Saved));
        }

        if (step.warn) {
            box.setAttribute('data-kind', 'warn');
            warnStep(step, i, c);
            mount();
            var first = box.querySelector('.ksef-tour-next, .ksef-tour-agree input');
            if (first) {
                first.focus({preventScroll: true});
            }
            return;
        }

        if (step.gate) {
            gateStep(step, i, c);
            mount();
            return;
        }

        if (ret && !known()) {
            var rd = ret.done && has(ret.done.sel) ? ret.done : null;
            sideBox(rd ? rd.text : ret.text || ui.SideBack, rd ? '' : ret.frame, i, c, ret.url, ret.refs);
            return;
        }

        if (!onPage(step) && step.side && new RegExp(step.side.page).test(here())) {
            sideBox(step.side.text, step.side.sel, i, c, '', step.side.refs || '');
            return;
        }

        if (!onPage(step)) {
            box.classList.add('ksef-tour-elsewhere');
            box.setAttribute('data-kind', 'elsewhere');
            c.appendChild(el('p', 'ksef-tour-text', step.text));
            var note = el('div', 'warning ksef-tour-refuse');
            note.setAttribute('role', 'alert');
            note.appendChild(el('span', 'fas fa-exclamation-triangle paddingright'));
            note.appendChild(document.createTextNode(fill(ui.OtherPage, step)));
            var gos = el('div', 'ksef-tour-refuse-go');
            if (step.url || step.home) {
                gos.appendChild(goThere(step, i));
            }
            for (var k = i + 1; k < def.steps.length; k++) {
                if (applies(k) && onPage(def.steps[k]) && !def.steps[k].warn && def.steps[k].page !== '.' && !(def.steps[k].optional && def.steps[k].sel && !found(def.steps[k]))) {
                    gos.appendChild(action(ui.JumpHere, 'ksef-tour-jump', (function (n) { return function () { go(n); }; })(k)));
                    break;
                }
            }
            wayBack(step, gos, note);
            if (gos.firstChild) {
                note.appendChild(gos);
            }
            c.appendChild(note);
            if (step.rows && state.rows && state.rows.n > 0) {
                c.insertBefore(el('p', 'ok ksef-tour-note ksef-tour-lineskept', (ui.LinesKept || '').replace('{n}', state.rows.n)), note);
            }
            box.appendChild(nav(i, 'away', last));
            envNote(i);
            mount();
            return;
        }

        if (step.choices) {
            box.classList.add('ksef-tour-fork');
            box.setAttribute('data-kind', 'choice');
            c.appendChild(el('p', 'ksef-tour-text', text));
            c.appendChild(choices(step));
            box.appendChild(nav(i, 'none', false));
            envNote(i);
            mount();
            return;
        }

        var hit = step.sel ? find(step) : null;
        var win = blocking();
        if (hit && win && win.contains(hit.orig)) {
            win = null;
        }
        if (win) {
            var nx = resolve(i, 1);
            var ns = nx && nx.here ? def.steps[nx.k] : null;
            var nf = ns && ns.sel ? find(ns) : null;
            if (nf) {
                nf.undo();
            }
            if (nf && win.contains(nf.orig)) {
                if (hit) {
                    hit.undo();
                }
                go(nx.k);
                return;
            }
            if (hit) {
                hit.undo();
            }
            paused = win;
            box.setAttribute('data-pausable', '1');
            pauseBox(win, i, c);
            return;
        }
        box.setAttribute('data-pausable', '1');
        var gated = step.send && cfg.env === 'PRODUCTION' && !(state.ticks && (state.ticks[step.key] || state.ticks[i]));
        var empty = false;
        var kind = 'info';
        var notHere = null;
        var ends = false;
        var lab2 = null;
        if (!hit && !soft && step.optional && !step.none && !step.alt && !step.dialog && dir > 0 && renders <= 40) {
            // a missing optional step is passed
            var pass = resolve(i, 1);
            if (pass && pass.here) {
                go(pass.k);
                return;
            }
        }
        if (!hit) {
            box.classList.add('ksef-tour-missing');
            notHere = c.appendChild(el('p', 'ksef-tour-note', ui.NotHere));
            c.appendChild(el('p', 'ksef-tour-text opacitymedium', text));
        } else if (gated) {
            hold = hit.shown;
            kind = 'click';
            box.classList.add('ksef-tour-gated');
            c.appendChild(el('p', 'ksef-tour-text', text));
            var lab = el('label', 'ksef-tour-tick');
            var cb = el('input', 'paddingright');
            cb.type = 'checkbox';
            cb.addEventListener('change', function () {
                if (cb.checked) {
                    state.ticks = state.ticks || {};
                    state.ticks[step.key] = true;
                    save();
                    // the tick seen first
                    window.setTimeout(render, 350);
                }
            });
            lab.appendChild(cb);
            lab.appendChild(document.createTextNode(' ' + ui.ProdTick));
            c.appendChild(lab);
            c.appendChild(el('p', 'ksef-tour-note opacitymedium', step.tick || ui.ProdTickHint));
        } else {
            if (step.send && cfg.env === 'PRODUCTION') {
                // ticked, can be undone
                lab2 = el('label', 'ksef-tour-tick ksef-tour-ticked');
                var cb2 = el('input', 'paddingright');
                cb2.type = 'checkbox';
                cb2.checked = true;
                cb2.addEventListener('change', function () {
                    if (!cb2.checked && state && state.ticks) {
                        delete state.ticks[step.key];
                        delete state.ticks[i];
                        save();
                        window.setTimeout(render, 350);
                    }
                });
                lab2.appendChild(cb2);
                lab2.appendChild(document.createTextNode(' ' + ui.ProdTick));
            }
            target = hit.shown;
            tail = spanEnd(step, hit.orig);
            if (!step.noring) {
                ring = el('div', 'ksef-tour-ring');
                layer.appendChild(ring);
                box.classList.add('ksef-tour-anchored');
            }
            viaAlt = !!(step.alt && hit.alt);
            empty = viaAlt && !!step.none && !(step.none.unless && has(step.none.unless));
            var said = null;
            if (empty) {
                // empty state only
                nothing(step, c);
            } else {
                said = c.appendChild(el('p', 'ksef-tour-text', text.replace('{label}', (hit.orig.textContent || '').trim())));
                said.setAttribute('data-text', text);
                if (viaAlt && step.key === 'pick' && !step.none) {
                    c.appendChild(el('p', 'warning ksef-tour-note ksef-tour-nomatch', ui.NoMatch));
                }
            }
            if (step.one && hit.orig.tagName === 'SELECT' && state.one !== step.key && !chosen(step)) {
                // the only choice
                var opts = [].filter.call(hit.orig.options, function (o) {
                    return !o.disabled && o.value !== '' && o.value !== '0' && o.value !== '-1';
                });
                state.one = step.key;
                if (opts.length === 1) {
                    hit.orig.value = opts[0].value;
                    if (window.jQuery) {
                        window.jQuery(hit.orig).trigger('change.select2');
                    }
                }
            }
            ends = !!(step.lastif && has(step.lastif));
            var does = ends ? '' : step.rows ? rows(c, step) : follow(hit, i) || (step.wait ? 'wait' : '');
            if (does === 'more' && hit.orig.id === 'addline') {
                // the lines
                var rs = document.querySelectorAll('#tablelines tr[id^="row-"]');
                target = rs.length ? rs[rs.length - 1] : target;
                tail = rs.length ? rs[0] : null;
            }
            var save1 = does === 'edit' ? findSel('#tablelines tr.tredited input[name="save"], #tablelines input[name="save"]') : null;
            if (save1) {
                save1.undo();
                target = save1.shown;
                tail = null;
            }
            if (empty) {
                // no click hint
                kind = step.none.end || !does ? 'info' : 'click';
                box.classList.add('ksef-tour-empty');
            } else if (does === 'more') {
                kind = 'info';
            } else if (does) {
                kind = does === 'field' || does === 'pick' ? 'field' : 'click';
                box.classList.add('ksef-tour-follows');
                var hint = el('p', 'ksef-tour-note ksef-tour-do');
                hint.appendChild(el('span', 'fas ' + (kind === 'field' ? 'fa-pen' : 'fa-hand-pointer') + ' paddingright'));
                // several rows
                var rowPick = does === 'wait' && !hit.preferred && !viaAlt && !!hit.orig.closest('table.liste tr') && candidates(step) > 1;
                hint.appendChild(document.createTextNode(rowPick ? ui.PickRow : does === 'wait' ? (opensHere(i) ? ui.UseIt : ui.Wait) : does === 'stay' ? ui.Wait : does === 'field' ? ui.UseField
                    : does === 'pick' ? (step.reloads && ui.UsePickReload ? ui.UsePickReload : ui.UsePick) : does === 'line' ? ui.AddLine : does === 'edit' ? ui.EditLine : ui.UseIt));
                c.appendChild(hint);
            }
            if (lab2) {
                c.appendChild(lab2);
            }
            keep = true;
            var nx2 = resolve(i, 1);
            var nst = nx2 && nx2.here ? def.steps[nx2.k] : null;
            var nfd = nst && nst.sel && !nst.dialog ? find(nst) : null;
            if (nfd) {
                nfd.undo();
                nextEl = nfd.shown !== target ? nfd.shown : null;
            }
        }
        box.setAttribute('data-kind', kind);
        var rf = refusal(i, kind);
        if (rf) {
            c.appendChild(rf);
        }
        if ((empty && step.none.end) || (hit && (step.end || ends))) {
            // guide ends here
            last = true;
        }
        var ahead = !last ? resolve(i, 1) : null;
        if (kind === 'info' && !last && !(step.then && hit && !(step.alt && hit.alt)) && (!ahead || (!ahead.here && !def.steps[ahead.k].url))) {
            last = true;
            if (ahead) {
                c.appendChild(el('p', 'ksef-tour-note opacitymedium ksef-tour-nothing', ui.NothingMore));
            }
        }
        if (notHere && last) {
            // no Dalej here
            c.removeChild(notHere);
            if (!c.querySelector('.ksef-tour-nothing')) {
                c.appendChild(el('p', 'ksef-tour-note opacitymedium ksef-tour-nothing', ui.NothingMore));
            }
        }
        if (ahead && step.then && hit && !(step.alt && hit.alt)) {
            // link leads there
            ahead = {k: thenIndex(i), here: false};
        }
        box.appendChild(nav(i, kind, last, empty ? null : ahead, {empty: empty}));
        state.endAt = last && kind === 'info' ? here() : null;
        state.endQuiet = !hit;
        if (step.submits && hit) {
            all(step.sel + (step.span ? ', ' + step.span : '')).forEach(function (f) {
                f.addEventListener('submit', function () {
                    if (state && state.i === i) {
                        state.sent = {from: i, load: LOAD};
                        save();
                    }
                }, {once: true});
            });
        }
        save();
        portal(step, hit ? c : null);
        if (!empty) {
            // nothing to send here
            envNote(i);
        }
        mount();
        var focus = box.querySelector('.ksef-tour-next');
        if (focus && !gated) {
            focus.focus({preventScroll: true});
        }
    }

    function opensHere(i) {
        var n = after(i);
        var s = state.def.steps[n];
        return !!(s && s.dialog && onPage(s));
    }

    function follow(f, i) {
        var step = state.def.steps[i];
        var o = f.orig;
        var tag = o.tagName.toLowerCase();
        if (step.prefclick && f.preferred && tag === 'a') {
            // same step reloads
            return 'stay';
        }
        if (step.start && tag === 'a') {
            o.addEventListener('click', function () {
                chain(step.start.tour, step.start.step, carry(step.start.back));
            }, {once: true});
            return 'click';
        }
        var info = step.info;
        if (info && !(step.then && tag === 'a')) {
            return '';
        }
        var type = (o.getAttribute('type') || '').toLowerCase();
        var change = tag === 'select' || tag === 'textarea' || (tag === 'input' && ['radio', 'checkbox', 'submit', 'button', 'image', 'reset'].indexOf(type) === -1);
        var submit = ((tag === 'input' || tag === 'button') && (type === 'submit' || type === 'image')) || (tag === 'button' && type === '');
        var href = tag === 'a' ? (o.getAttribute('href') || '').trim() : '';
        var navigates = tag === 'a' && href !== '' && href !== '#' && !/^javascript:/i.test(href) && !step.wait;
        if (submit || (tag === 'a' && !navigates)) {
            if (isLast(i)) {
                o.addEventListener('click', function () {
                    if (state && state.i === i) {
                        finished();
                    }
                }, {once: true});
                return step.wait ? 'wait' : 'click';
            }
            if (step.wait) {
                return 'wait';
            }
            if (!submit) {
                return '';
            }
        } else if (!change && !navigates && type !== 'radio' && type !== 'checkbox' && tag !== 'button' && tag !== 'label') {
            return '';
        }
        var later = navigates || submit;
        var done = function () {
            if (!state || state.i !== i) {
                return;
            }
            if (!state.def.steps[i].then && isLast(i)) {
                finished();
                return;
            }
            state.i = thenIndex(i);
            state.at = null;
            state.dir = 1;
            state.want = null;
            state.auto = later;
            state.sub = submit;
            var to = state.def.steps[state.i].path;
            if (to && state.path && to !== state.path) {
                state.via = {path: state.path, at: i};
            }
            if (to) {
                state.path = to;
            }
            save();
            window.setTimeout(function () {
                // keep for next
                if (!leaving) {
                    render(true);
                }
            }, later ? 600 : 0);
        };
        if (info) {
            o.addEventListener('click', done, {once: true});
            return '';
        }
        if (change && tag !== 'select') {
            return 'field';
        }
        if (change && window.jQuery) {
            // select2: jQuery only
            var picked = function () {
                if (tag === 'select' && (o.value === '' || o.value === '-1')) {
                    return;
                }
                window.jQuery(o).off('change', picked);
                done();
            };
            window.jQuery(o).on('change', picked);
        } else {
            o.addEventListener(change ? 'change' : 'click', done, {once: true});
        }
        return change ? 'pick' : 'click';
    }

    function endpoint(q) {
        return cfg.endpoint + (cfg.endpoint.indexOf('?') === -1 ? '?' : '&') + q;
    }

    function fetchDef(id) {
        return fetch(endpoint('action=displaytour&id=' + encodeURIComponent(id) + '&ksef_page=' + encodeURIComponent(here())), {credentials: 'same-origin'}).then(function (r) {
            if (!r.ok) {
                throw new Error(String(r.status));
            }
            return r.json();
        });
    }

    function refetch(then) {
        if (!state) {
            return;
        }
        fetchDef(state.id).then(function (def) {
            if (state && def) {
                adopt(def);
                save();
            }
            then();
        }).catch(then);
    }

    // guide starts on next page
    function chain(id, step, from) {
        try {
            window.sessionStorage.setItem(KEY + 'Chain', JSON.stringify({id: id, step: step || '', from: from}));
        } catch (e) { /* ignore */ }
    }

    function startAt(def, key) {
        var k;
        for (k = 0; k < def.steps.length; k++) {
            var s = def.steps[k];
            if (s.warn || s.choices || s.gate) {
                continue;
            }
            if (key ? s.key === key : (s.page !== '.' && onPage(s) && s.sel && findSel(s.sel))) {
                return k;
            }
        }
        // skip absent optionals
        for (k = 0; k < def.steps.length; k++) {
            var t = def.steps[k];
            if (!t.warn && !t.gate && !(t.optional && !onPage(t))) {
                return k;
            }
        }
        return 0;
    }

    function start(id, key, from) {
        fetchDef(id).then(function (def) {
            envify(def);
            clear();
            var at = startAt(def, key || '');
            state = {id: id, i: 0, def: def, prod: !!(from && from.prod), ticks: {}, path: at ? def.steps[at].path || null : null, from: at, startUrl: here(),
                prev: from ? from.prev : null, obj: from && from.obj ? from.obj : null, origin: from && from.origin ? from.origin : null, stage: 0};
            envify(def);
            if (def.steps[0].warn && !state.prod) {
                go(0);
                return;
            }
            var g = keyAt(def, 'gate');
            if (g >= 0 && applies(g)) {
                go(g);
                return;
            }
            save();
            go(at);
        }).catch(function () {
            window.alert(ui.LoadError);
        });
    }

    // ---- events

    document.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-ksef-tour]') : null;
        if (b) {
            e.preventDefault();
            start(b.getAttribute('data-ksef-tour'), b.getAttribute('data-ksef-tour-step') || '');
        }
    });
    document.addEventListener('click', function (e) {
        var g = e.target.closest ? e.target.closest('[data-ksef-guides]') : null;
        var d = g ? document.getElementById(g.getAttribute('data-ksef-guides')) : null;
        if (!d || !window.jQuery || !jQuery.fn.dialog) {
            return;
        }
        e.preventDefault();
        var $d = jQuery(d);
        var buttons = {};
        buttons[d.getAttribute('data-cancel')] = function () {
            jQuery(this).dialog('close');
        };
        $d.dialog({modal: true, width: Math.min(540, window.innerWidth - 32), buttons: buttons, close: function () {
            g.focus();
        }});
        $d.find('[data-ksef-tour]').off('click.ksefGuide').on('click.ksefGuide', function () {
            $d.dialog('close');
        });
    });
    document.addEventListener('keydown', function (e) {
        if ((e.key === 'Escape' || e.key === 'Esc') && state && !has('#ui-datepicker-div:not([style*="display: none"]), .select2-container--open') && !blocking()) {
            stop();
        }
    });
    ['wheel', 'touchstart', 'keydown', 'mousedown'].forEach(function (t) {
        window.addEventListener(t, function () {
            keep = false;
        }, {capture: true, passive: true});
    });
    window.addEventListener('resize', function () {
        place(true);
    });
    // inner scrollers too
    window.addEventListener('scroll', function () {
        place();
    }, true);
    window.addEventListener('beforeunload', function () {
        leaving = true;
    });
    document.addEventListener('click', function (e) {
        var step = cur();
        if (step && e.target.closest && e.target.closest('#ksef-sync-btn')) {
            state.synced = true;
            save();
        }
        var no = step && step.back && step.dialog && e.target.closest ? e.target.closest('.ui-dialog-buttonset button') : null;
        if (no && target && no !== target && no.parentNode === target.parentNode) {
            // the other answer
            var bk = keyAt(state.def, step.back);
            if (bk >= 0) {
                go(bk, -1);
            }
            return;
        }
        if (!step || !step.wait || !e.target.closest || (layer && layer.contains(e.target)) || !onPage(step)) {
            return;
        }
        var hit = selParts(step.sel + (step.alt ? ',' + step.alt : '')).some(function (part) {
            try {
                return !!e.target.closest(part);
            } catch (x) {
                return false;
            }
        });
        if (hit) {
            state.clicked = state.i;
            state.clickAt = LOAD;
            save();
        }
    }, true);
    var markDirty = function (e) {
        var t = e.target;
        if (state && t && t.form && !(layer && layer.contains(t))) {
            t.form.setAttribute('data-ksef-dirty', '1');
        }
    };
    document.addEventListener('input', markDirty, true);
    document.addEventListener('change', markDirty, true);
    // texts follow page
    var pending = null;
    var revary = function () {
        if (pending) {
            return;
        }
        pending = window.setTimeout(function () {
            pending = null;
            var step = cur();
            if (!step || !step.vary || !box || !onPage(step) || (layer && layer.contains(document.activeElement)) || box.getAttribute('data-kind') === 'paused') {
                return;
            }
            var said = box.querySelector('.ksef-tour-text[data-text]');
            if (said && said.getAttribute('data-text') !== textOf(step)) {
                render();
            }
        }, 250);
    };
    document.addEventListener('change', revary);
    if (window.jQuery) {
        window.jQuery(document).on('change', revary);
    }
    if (window.MutationObserver) {
        new MutationObserver(function (list) {
            if (!state || !box) {
                return;
            }
            for (var k = 0; k < list.length; k++) {
                if (!(layer && layer.contains(list[k].target))) {
                    revary();
                    return;
                }
            }
        }).observe(document.documentElement, {childList: true, subtree: true});
    }
    window.addEventListener('hashchange', function () {
        if (state) {
            render();
        }
    });

    // core editor swaps
    function editorLoading() {
        var ed = window.CKEDITOR && window.CKEDITOR.instances;
        for (var k in ed || {}) {
            if (ed[k].status !== 'ready') {
                return true;
            }
        }
        return false;
    }

    function init() {
        var banner = document.getElementById('ksef-help-banner');
        var host = document.querySelector('#id-right > .fiche') || document.getElementById('id-right');
        if (banner && host && host.firstElementChild !== banner) {
            host.insertBefore(banner, host.firstChild);
        }
        var link = null;
        try {
            link = JSON.parse(window.sessionStorage.getItem(KEY + 'Chain') || 'null');
            window.sessionStorage.removeItem(KEY + 'Chain');
        } catch (e) {
            link = null;
        }
        if (link) {
            start(link.id, link.step, link.from);
            return;
        }
        state = load();
        if (!state) {
            doneBox(true);
        } else if (state.def) {
            // stored indexes fit the stored definition
            envify(state.def);
            state.keys = null;
            indexed(state);
        }
        if (state && state.def) {
            var waited = 0;
            var fresh = false;
            var later = function () {
                if ((((window.ksefTourHold || 0) > 0 || editorLoading()) && waited < 3000) || (!fresh && waited < 1500)) {
                    waited += 50;
                    window.setTimeout(later, 50);
                    return;
                }
                render();
            };
            // current page texts
            fetchDef(state.id).then(function (def) {
                var now = load();
                if (now && now.def && state && (now.id !== state.id || now.i !== state.i)) {
                    // changed meanwhile
                    envify(now.def);
                    now.keys = null;
                    state = indexed(now);
                } else if (def && state && state.def) {
                    adopt(def);
                }
                fresh = true;
            }).catch(function () {
                fresh = true;
            });
            window.setTimeout(later, 0);
        }
    }

    function snapshot() {
        if (!state) {
            return null;
        }
        var s = cur();
        var st = box && box.getAttribute('data-stage') ? box.getAttribute('data-stage').split('/') : null;
        var lbl = st ? (state.def.stages || [])[Number(st[0]) - 1] : '';
        var host = target ? target.closest(MODAL + ', .ui-dialog') : null;
        var out = JSON.parse(JSON.stringify(state));
        out.key = s ? s.key : '';
        out.stage = st ? {n: Number(st[0]), m: Number(st[1]), label: lbl} : null;
        out.kind = box ? box.getAttribute('data-kind') || '' : '';
        out.refs = sideRefs || (s ? ids(s.refs || '') : '');
        var dw = host || paused;
        out.dialog = dw ? (dw.id ? '#' + dw.id : (dw.querySelector('.ui-dialog-content[id]') ? '.ui-dialog:has(#' + dw.querySelector('.ui-dialog-content[id]').id + ')' : '.ui-dialog')) : '';
        out.paused = !!paused;
        out.folded = !!(box && box.classList.contains('ksef-tour-min'));
        out.busy = !!(box && box.classList.contains('ksef-tour-busy'));
        return out;
    }

    window.ksefTour = {start: start, stop: stop, state: snapshot, test: test, target: function () { return box && box.classList.contains('ksef-tour-busy') ? null : target || hold || null; }, obstacles: function () { return box ? obstacles() : null; }};
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
