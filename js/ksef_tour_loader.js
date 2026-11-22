/* Copyright (C) 2025-2026 InPoint Automation Sp z o.o.
 * License: AGPL-3.0-or-later
 *
 * KSeF tour loader.
 */
(function () {
    'use strict';
    var me = document.currentScript && document.currentScript.src;
    if (!me) {
        return;
    }
    var base = me.replace(/js\/ksef_tour_loader\.js.*$/, '');

    function active() {
        try {
            return !!(window.sessionStorage.getItem('ksefTour') || window.sessionStorage.getItem('ksefTourDone'));
        } catch (e) {
            return false;
        }
    }

    function run() {
        if (window.ksefHelpCfg || window.ksefTourLoaded || !active() || !window.fetch) {
            return;
        }
        window.fetch(base + 'ksef_help.php?action=displaycfg', {credentials: 'same-origin'}).then(function (r) {
            return r.ok ? r.json() : null;
        }).then(function (d) {
            if (!d || !d.cfg || !d.js || window.ksefHelpCfg) {
                return;
            }
            window.ksefHelpCfg = d.cfg;
            var s = document.createElement('script');
            s.src = d.js;
            document.body.appendChild(s);
        }).catch(function () { /* no tour here */ });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }
})();
