/* Copyright (C) 2026 InPoint Automation Sp z o.o.
 * License: AGPL-3.0-or-later
 *
 * Close dialog.
 */
(function ($) {
    'use strict';

    function accountSelect(c, value) {
        var sel = $('<select class="flat minwidth150 maxwidth200 ksef-wo-acc"></select>');
        sel.append($('<option value=""></option>').text(c.L.Select));
        $.each(c.chart, function (i, a) {
            sel.append($('<option></option>').attr('value', a.n).text(a.n + ' - ' + a.l));
        });
        sel.val(value || '');
        if (sel.val() === null) {
            sel.val('');
        }
        return sel;
    }

    window.ksefCloseDialog = function (c) {
        var tries = 0;
        (function wait() {
            var d = $('#dialog-confirm');
            if (!d.length || !d.hasClass('ui-dialog-content')) {
                if (++tries < 60) {
                    setTimeout(wait, 50);
                }
                return;
            }
            if (d.data('ksefClose') || !d.find('input[name=close_code]').length) {
                return;
            }
            d.data('ksefClose', 1);
            var row = d.find('input[name=close_code][value=discount_vat]').closest('.tagtr');
            if (row.length) {
                var label = row.children('.tagtd').first();
                var next = row.nextAll('.tagtr').first();
                if (label.text().replace(/\s/g, '') !== '' && !label.find('input').length && next.length) {
                    next.children('.tagtd').first().html(label.html()).addClass('tdtop');
                }
                row.remove();
            }
            // only with button
            var offered = !c.L.KorLater || $('.tabsAction a.butAction').filter(function () { return $(this).attr('href') === c.url; }).length > 0;
            var notes = $('<div class="ksef-close-notes"></div>').append($('<div class="ksef-close-novat"></div>').text(c.L.NoVat),
                $('<div class="ksef-close-kor"></div>').text(c.L.Kor + ' ').append(offered ? $('<a class="ksef-close-kor-link"></a>').attr('href', c.url).text(c.L.KorLink)
                    : $('<span class="ksef-close-kor-later"></span>').text(c.L.KorLater)));
            var radios = d.find('input[name=close_code]').first().closest('.tagtable, table, form');
            if (radios.length && radios[0] !== d[0]) {
                radios.after(notes);
            } else {
                d.append(notes);
            }
            // hidden until chosen
            var note = d.find('[name=close_note]');
            if (note.length) {
                var noteRow = note.closest('.tagtr');
                note.addClass('ksef-close-note marginleftonly minwidth200 maxwidth300');
                var place = function () {
                    var r = d.find('input[name=close_code]:checked');
                    if (!r.length) {
                        note.hide();
                        return;
                    }
                    note.insertAfter(d.find('label[for="' + r.attr('id') + '"]')).show();
                    d.dialog('option', {height: 'auto'});
                };
                noteRow.hide();
                d.on('change', 'input[name=close_code]', place);
                place();
            }
            d.dialog('option', {height: 'auto'});
        })();
    };

    window.ksefWoDialog = function (c) {
        var tries = 0;
        (function wait() {
            var d = $('#dialog-confirm');
            if (!d.length || !d.hasClass('ui-dialog-content')) {
                if (++tries < 60) {
                    setTimeout(wait, 50);
                }
                return;
            }
            if (d.data('ksefWo')) {
                return;
            }
            d.data('ksefWo', 1);
            var sel = accountSelect(c, c.last).attr('id', 'ksef_wo_account');
            var err = $('<div class="error small ksef-wo-err"></div>').hide();
            var block = $('<div class="ksef-wo"></div>').append($('<div class="ksef-wo-help"></div>').text(c.L.Help),
                $('<label class="fieldrequired" for="ksef_wo_account"></label>').text(c.L.Account + ' '), sel, err).hide();
            d.append(block);
            var code = function () {
                return d.find('input[name=close_code]:checked').val() || '';
            };
            var active = function () {
                return $.inArray(code(), c.codes) >= 0;
            };
            var refresh = function () {
                block.toggle(active());
                err.hide();
            };
            d.on('change', 'input[name=close_code]', refresh);
            var buttons = d.dialog('option', 'buttons');
            if (buttons && !Array.isArray(buttons)) {
                var keys = Object.keys(buttons), coreYes = buttons[keys[0]];
                buttons[keys[0]] = function () {
                    if (!active()) {
                        return coreYes.apply(this, arguments);
                    }
                    if (!sel.val()) {
                        err.text(c.L.Required).show();
                        return;
                    }
                    var form = $('<form method="POST" class="hideobject"></form>').attr('action', c.url);
                    var add = function (name, value) {
                        form.append($('<input type="hidden">').attr('name', name).val(value == null ? '' : value));
                    };
                    add('token', c.token);
                    add('action', 'confirm_paid_partially');
                    add('confirm', 'yes');
                    add('close_code', code());
                    add('close_note', d.find('[name=close_note]').val());
                    add('ksef_wo', '1');
                    add('ksef_wo_account', sel.val());
                    d.parent().find('.ui-dialog-buttonpane button').prop('disabled', true);
                    $('body').append(form);
                    form.trigger('submit');
                };
                d.dialog('option', 'buttons', buttons);
            }
            refresh();
            d.dialog('option', {height: 'auto'});
        })();
    };
})(jQuery);
