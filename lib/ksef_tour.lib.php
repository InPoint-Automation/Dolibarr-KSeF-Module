<?php
/* Copyright (C) 2025-2026 InPoint Automation Sp z o.o.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    lib/ksef_tour.lib.php
 * \ingroup ksef
 * \brief   Help and tours
 */

dol_include_once('/ksef/lib/ksef.lib.php');
dol_include_once('/ksef/lib/ksef_requirements.lib.php');

define('KSEF_HELP_BANNER_PARAM', 'KSEF_HELP_BANNER_OFF');

/**
 * @brief Tour pages
 * @return array
 */
function ksefTourPages()
{
    $mod = function ($path) {
        return dol_buildpath('/ksef/' . $path, 1);
    };
    return array(
        'any' => array('re' => '.', 'url' => ''),
        'setup' => array('re' => '/ksef/admin/setup\\.php', 'url' => $mod('admin/setup.php')),
        'auth' => array('re' => '/ksef/admin/setup_auth\\.php', 'url' => $mod('admin/setup_auth.php') . '?mainmenu=home&leftmenu=setup'),
        'about_ask' => array('re' => '/ksef/admin/about\\.php\\?(?:.*&)?action=ksef_reinit_ask', 'url' => ''),
        'auth_ask' => array('re' => '/ksef/admin/setup_auth\\.php\\?(?:.*&)?action=ask_production', 'url' => ''),
        'outgoing' => array('re' => '/ksef/admin/setup_outgoing\\.php', 'url' => $mod('admin/setup_outgoing.php')),
        'fields' => array('re' => '/ksef/admin/setup_fields\\.php', 'url' => $mod('admin/setup_fields.php')),
        'dictionaries' => array('re' => '/ksef/admin/setup_dictionaries\\.php', 'url' => $mod('admin/setup_dictionaries.php')),
        'about' => array('re' => '/ksef/admin/about\\.php', 'url' => $mod('admin/about.php')),
        'dashboard' => array('re' => '/ksef/ksefindex\\.php', 'url' => $mod('ksefindex.php')),
        'status' => array('re' => '/ksef/status\\.php', 'url' => $mod('status.php')),
        'inc_list' => array('re' => '/ksef/incoming_list\\.php', 'url' => $mod('incoming_list.php')),
        'inc_card' => array('re' => '/ksef/incoming_card\\.php', 'url' => ''),
        'inc_pick' => array('re' => '/ksef/incoming_(?:list|card)\\.php', 'url' => $mod('incoming_list.php')),
        'inc_import' => array('re' => '/ksef/incoming_import\\.php', 'url' => ''),
        'sales_import' => array('re' => '/ksef/sales_import\\.php', 'url' => ''),
        'sales_adjust' => array('re' => '/ksef/sales_adjust\\.php', 'url' => ''),
        'tab_ksef' => array('re' => '/ksef/tab_ksef\\.php', 'url' => ''),
        'inv_list' => array('re' => '/compta/facture/list\\.php', 'url' => DOL_URL_ROOT . '/compta/facture/list.php?mainmenu=billing&leftmenu=customers_bills'),
        'inv_create' => array('re' => '/compta/facture/card\\.php(?:$|\\?(?:.*&)?action=create)', 'url' => DOL_URL_ROOT . '/compta/facture/card.php?action=create&mainmenu=billing'),
        'inv_card' => array('re' => '/compta/facture/card\\.php\\?(?:.*&)?(?:facid|id|ref)=', 'url' => ''),
        'inv_confirm' => array('re' => '/compta/facture/card\\.php\\?(?:.*&)?action=(?:ksef_pre_submit|ksef_validate_and_preview|ksef_confirm_upload)', 'url' => ''),
        'inv_sent' => array('re' => '/compta/facture/card\\.php\\?(?:.*&)?ksef_post_upload=1', 'url' => ''),
        'ord_list' => array('re' => '/commande/list\\.php', 'url' => DOL_URL_ROOT . '/commande/list.php?mainmenu=commercial&leftmenu=orders'),
        'ord_card' => array('re' => '/commande/card\\.php\\?(?:.*&)?(?:id|ref)=', 'url' => ''),
        'pay_create' => array('re' => '/compta/paiement\\.php', 'url' => ''),
        'sup_card' => array('re' => '/fourn/facture/card\\.php\\?(?:.*&)?(?:facid|id)=', 'url' => ''),
        'sup_list' => array('re' => '/fourn/facture/list\\.php', 'url' => DOL_URL_ROOT . '/fourn/facture/list.php?mainmenu=billing&leftmenu=suppliers_bills'),
        'sup_pay' => array('re' => '/fourn/facture/paiement\\.php', 'url' => ''),
        'sup_ask' => array('re' => '/fourn/facture/card\\.php\\?(?:.*&)?action=ksef_pay_ask', 'url' => ''),
        'cron' => array('re' => '/cron/list\\.php', 'url' => DOL_URL_ROOT . '/cron/list.php'),
        'cron_card' => array('re' => '/cron/card\\.php', 'url' => ''),
        'users' => array('re' => '/user/list\\.php', 'url' => DOL_URL_ROOT . '/user/list.php?mainmenu=home&leftmenu=users'),
        'user_card' => array('re' => '/user/card\\.php\\?(?:.*&)?id=', 'url' => ''),
        'user_perms' => array('re' => '/user/perms\\.php', 'url' => ''),
        'chart' => array('re' => '/accountancy/admin/account\\.php', 'url' => DOL_URL_ROOT . '/accountancy/admin/account.php?mainmenu=accountancy&leftmenu=accountancy_admin'),
    );
}

/**
 * @brief Tour definitions
 * @return array
 */
function ksefTourDefinitions()
{
    $send = 'a[href*="action=ksef_pre_submit"], a[href*="action=ksef_submit"]:not(.ksef-confirm-send):not([href*="action=ksef_submit_offline"]), a[href*="action=ksef_validate_and_"]';
    $gate = array('key' => 'gate', 'page' => 'any', 'sel' => '', 'gate' => 1);
    $confirm = array('key' => 'confirm', 'page' => 'inv_confirm', 'sel' => '#ksef-confirm-upload-modal .ksef-confirm-send', 'optional' => 1, 'send' => 1,
        'tick' => 'KSEF_TourUi_ProdTickHintConfirm', 'text' => 'KSEF_Tour_ConfirmSend');
    $sent = array('key' => 'sent', 'page' => 'inv_sent', 'sel' => '#ksef-post-upload-modal .ksef-post-upload-close', 'optional' => 1, 'text' => 'KSEF_Tour_Sent');
    $notYet = array('body:not(:has(tr.ksef-status-row .ksef-card-downloads))' => 'KSEF_Tour_StatusNotAccepted');
    $accepted = 'body:has(tr.ksef-status-row .ksef-card-downloads) ';
    $status = array('key' => 'status', 'page' => 'inv_card', 'sel' => 'tr.ksef-status-row', 'vary' => $notYet, 'stage' => 'check');
    $noSoc = 'empty:#formtocreate #socid';
    $createSave = array('key' => 'save', 'page' => 'inv_create', 'sel' => '#formtocreate .button-save', 'wait' => 1,
        'errto' => array(array('when' => $noSoc, 'key' => 'customer'), array('when' => 'body', 'key' => 'form')));
    $listRow = 'table.liste tr.oddeven a[href*="/compta/facture/card.php"]';
    $pickCell = 'table.liste tr.oddeven td:has(> a[href*="/compta/facture/card.php"]) .ksef-pick-info, table.liste tr.oddeven td:has(> a[href*="/compta/facture/card.php"]) .ksef-pick-order';
    $incRow = '#searchFormList tr.oddeven:has(a[href*="action=quickimport"])';
    $pickIncoming = array('key' => 'pick', 'page' => 'inc_pick', 'sel' => $incRow . ', a.butAction[href*="incoming_import.php"]', 'alt' => '#ksef-sync-btn, #searchFormList', 'wait' => 1, 'go' => 1,
        'none' => array('text' => 'KSEF_Tour_None_Incoming', 'after' => 'KSEF_Tour_None_IncomingAfter', 'tour' => 'recv_fetch', 'live' => 'received'), 'stage' => 'pick',
        'refs' => '#searchFormList tr.oddeven td:has(a[href*="incoming_card.php"])');
    $importConfirm = array('key' => 'confirm', 'page' => 'inc_import', 'sel' => '.ui-dialog:has(#import-confirm-modal) .ui-dialog-buttonpane button.butAction', 'optional' => 1,
        'dialog' => 1, 'text' => 'KSEF_Tour_ImportConfirm');
    $incPick = function ($rows, $none) use ($pickIncoming) {
        return array_merge($pickIncoming, array('sel' => $rows . ', a.butAction[href*="incoming_import.php"]:not([href*="quickimport"])',
            'vary' => array('body.page-incoming-card' => 'KSEF_Tour_RecvPickCard'), 'none' => array_merge($pickIncoming['none'], array('text' => $none))));
    };
    $incType = function ($type) {
        return '#searchFormList tr.oddeven[data-ksef-type' . $type . ']:has(a[href*="action=quickimport"]) a[href*="incoming_card.php"]';
    };
    $supplierStep = array('key' => 'supplier', 'page' => 'inc_import', 'sel' => '.ksef-party-missing, #change_supplier_link, #supplier_selector_div', 'span' => '#ksef_create_parties_row', 'info' => 1,
        'text' => 'KSEF_Tour_recv_import_supplier', 'vary' => array('.jnotify-container .jnotify-notification' => 'KSEF_Tour_recv_import_supplier_msg',
        '.ksef-party-missing' => 'KSEF_Tour_recv_import_supplier_missing'), 'stage' => 'import');
    $noOrig = 'body:not(:has(#ksef_for_kor)) ';
    $customerSel = '#socid, #search_socid, #formtocreate td:has(> input[type="hidden"][name="socid"]:not(#socid))';
    $needSoc = '#formtocreate [name="socid"]';
    $rozOn = 'tr.ksef-deposit-summary[data-kind="ROZ"]';
    // never on a deposit
    $notZal = '!tr.ksef-deposit-summary[data-kind="ZAL"]';
    // credit to apply by hand
    $manual = 'body:not(:has(a.ksef-deposit-apply)):not(:has(a.ksef-deposit-pay)):not(:has(' . $rozOn . ')) .fichehalfleft select[name="remise_id"]:has(option:not([value="0"]))';
    $unpaid = 'a.ksef-deposit-pay';
    $noOrder = 'body:not(:has(table[data-block="showLinkedObject"] a[href*="/commande/card.php"])) ';
    $isCredit = 'tr.ksef-deposit-summary[data-deposit-state="credit"], tr.ksef-deposit-summary[data-deposit-state="used"]';
    $rowsSel = '#tablelines tr[id^="row-"]';
    $chosen = array('#formtocreate input[type="hidden"][name="socid"]:not(#socid)' => 'KSEF_Tour_CustomerChosen');
    // buyer NIP field
    $GLOBALS['langs']->load('companies');
    $nipField = ksefGetFieldName('NIP');
    $nipId = $nipField === 'tva_intra' ? 'intra_vat' : $nipField;
    $nipLabel = $nipField === 'tva_intra' ? $GLOBALS['langs']->transnoentities('VATIntra')
        : $GLOBALS['langs']->transcountrynoentities('ProfId' . substr($nipField, 6), !empty($GLOBALS['mysoc']->country_code) ? $GLOBALS['mysoc']->country_code : 'PL');
    // new customer page
    $newSoc = array('page' => '/societe/card\\.php(?:\\?(?:.*&)?action=create|$)', 'sel' => 'form[name="formsoc"] input.button-save', 'text' => 'KSEF_Tour_CustomerNew',
        'args' => array(dol_string_nohtmltag($nipLabel)), 'refs' => '#name, #address, #' . $nipId . ', form[name="formsoc"] td:has(+ td #' . $nipId . ')');
    $customer = array('key' => 'customer', 'page' => 'inv_create', 'sel' => $customerSel, 'go' => 1, 'need' => $needSoc, 'side' => $newSoc, 'vary' => $chosen, 'stage' => 'form', 'reloads' => 1);
    $korStart = function ($param, $none, $pick) use ($listRow, $pickCell) {
        return array(
            array('key' => 'way', 'page' => 'any', 'sel' => '', 'choices' => array('card', 'new'), 'stage' => 'pick'),
            array('key' => 'pick', 'page' => 'inv_list', 'sel' => $listRow, 'alt' => 'table.liste', 'query' => 'ksef_pick=' . $pick, 'wait' => 1, 'go' => 1, 'path' => 'card',
                'picks' => 'a.butAction[href*="' . $param . '="]', 'none' => $none, 'refs' => $pickCell),
            array('key' => 'button', 'page' => 'inv_card', 'sel' => 'a.butAction[href*="' . $param . '="]', 'alt' => 'tr.ksef-status-row:has(.ksef-other-env-note)', 'wait' => 1, 'path' => 'card',
                'none' => array('text' => 'KSEF_Tour_None_OtherEnv', 'tour' => 'send_vat')),
        );
    };
    $korNew = function ($select, $tour) use ($customerSel, $needSoc, $newSoc) {
        return array(
            array('key' => 'customer', 'page' => 'inv_create', 'sel' => $customerSel, 'go' => 1, 'path' => 'new', 'need' => $needSoc, 'side' => $newSoc, 'stage' => 'form', 'reloads' => 1),
            array('key' => 'newform', 'page' => 'inv_create', 'sel' => $select . ':not(:disabled):has(option[value]:not([value=""]):not([value="-1"]):not([value="0"]):not(:disabled))', 'path' => 'new',
                'alt' => $select . ', label.opacitymedium[for="radio_replacement"]', 'none' => array('text' => 'KSEF_Tour_None_Replace', 'tour' => $tour, 'way' => 'card')),
        );
    };
    // Dolibarr 22: no default
    $korSave = array_merge($createSave, array('errto' => array(array('when' => $noSoc, 'key' => 'customer'))));
    $korDate = array('key' => 'date', 'page' => 'inv_create', 'sel' => '#re', 'span' => '#re ~ img.ui-datepicker-trigger, #reButton, #reButtonNow',
        'refs' => '#ui-datepicker-div', 'optional' => 1, 'need' => '#re', 'when' => 'empty:#re', 'text' => 'KSEF_Tour_KorDate');
    $details = array(
        array('key' => 'details', 'page' => 'inv_card', 'sel' => '#ksef_correction_reason_preset', 'optional' => 1, 'stage' => 'details'),
        array('key' => 'detailsave', 'page' => 'inv_card', 'sel' => 'form:has(#ksef_correction_reason_preset) input[type="submit"]', 'optional' => 1),
    );
    $orders = isModEnabled('commande');
    $noNames = !ksefGetCurrentTranslationOverrides($GLOBALS['db']);
    $jobs = isModEnabled('cron') ? ksefGetCronJobs($GLOBALS['db']) : array();
    $syncJobOn = !empty($jobs['cronSyncIncoming']['status']);
    // order or not
    $fromOrder = function ($origin = false) use ($customer, $orders) {
        if ($origin) {
            // deposit customer
            $customer = array_merge($customer, array('query' => 'socid={soc}', 'vary' => array('#formtocreate input[type="hidden"][name="socid"]:not(#socid)' => 'KSEF_Tour_send_roz_customer_chosen')));
        }
        if (!$orders) {
            return array($customer);
        }
        $billable = ksefTourBillableOrders();
        $steps = array(array('key' => 'way', 'page' => 'any', 'sel' => '', 'choices' => $billable ? array('order', 'new') : array('new', 'order'), 'stage' => 'form',
            'suffixes' => $billable ? array() : array('order' => '_none'),
            'when' => $origin ? '!table[data-block="showLinkedObject"] a[href*="/commande/card.php"]' : ''));
        if ($origin) {
            $steps[] = array('key' => 'origin', 'page' => 'inv_card', 'sel' => 'table[data-block="showLinkedObject"] a[href*="/commande/card.php"]', 'optional' => 1, 'path' => 'order',
                'then' => 'createbill');
        }
        $steps[] = array('key' => 'order', 'page' => 'ord_list', 'sel' => 'table.liste tr.oddeven a[href*="/commande/card.php"]', 'alt' => 'table.liste', 'query' => 'search_status=-2&search_billed=0',
            'wait' => 1, 'go' => 1, 'path' => 'order', 'none' => array('text' => 'KSEF_Tour_None_Order', 'way' => 'new'));
        $steps[] = array('key' => 'createbill', 'page' => 'ord_card', 'sel' => 'a[href*="/compta/facture/card.php"][href*="origin=commande"]',
            'alt' => '.dropdown-holder:has(a[href*="origin=commande"]) > a.dropdown-toggle', 'wait' => 1, 'path' => 'order');
        $steps[] = array_merge($customer, array('path' => 'new', 'stage' => ''));
        return $steps;
    };
    $authSave = '#env-{env} form:has(input[name="KSEF_AUTH_TOKEN"]) input.button-save';
    $authCert = '#env-{env} form:has(input[name="auth_cert_file"])';
    $offForm = '#env-{env} form:has(input[name="offline_cert_file"])';
    $offSaved = '[data-ksef-active="1"] form:has(input[name="offline_cert_file"]) a[href*="action=ask_remove_offline_cert"]';
    $authOk = ksefAuthLastTest(ksefGetEnvironment())['result'] === 'ok';
    $currencies = isModEnabled('multicurrency');
    $only = '#multicurrency_code:not(:has(option:not([value="PLN"]):not([value=""]):not([value="-1"])))';
    $main = getDolGlobalString('MAIN_MONNAIE', 'PLN');
    // the create form
    $form = function ($id, $type = 'standard') use ($currencies, $only, $main, $orders) {
        // one click: EUR with the NBP rate
        $links = array(array('url' => ksefRequirementFixUrl('currency_foreign'), 'label' => 'KSEF_ReqFix_AddEurNbp', 'admin' => 1, 'if' => $currencies ? $only : ''));
        $steps = array(
            array('key' => 'form', 'page' => 'inv_create', 'sel' => '#formtocreate tr:has(#re)', 'span' => $currencies ? '#formtocreate tr:has(#multicurrency_code)' : '#formtocreate tr:has(#selectmode_reglement_id), #formtocreate tr:has(#selectfk_account)',
                'info' => 1, 'text' => 'KSEF_Tour_Form_' . $type, 'args' => array('DefaultBankAccount'),
                'suffix' => array_merge($id === 'send_roz' ? array('KSEF_Tour_send_roz_form_saledate') : array(),
                    array($currencies ? ($id === 'send_vat' ? 'KSEF_Tour_FormCurrencyNext' : ($orders ? 'KSEF_Tour_FormCurrency' : 'KSEF_Tour_FormCurrency_noorder')) : ($main === 'PLN' ? 'KSEF_Tour_FormCurrencyOff' : 'KSEF_Tour_FormCurrencyMain'))),
                'vary' => array('#formtocreate:not(:has(#selectfk_account))' => 'KSEF_Tour_Form_' . $type . '_nobank'), 'link' => $links, 'refs' => '#formtocreate input[name="type"]:checked, #formtocreate input[name="type"]:checked + label'),
            array('key' => 'saledate', 'page' => 'inv_create', 'sel' => '#options_ksef_sale_date', 'span' => '#options_ksef_sale_date ~ img.ui-datepicker-trigger, #options_ksef_sale_dateButton',
                'refs' => '#ui-datepicker-div', 'optional' => 1, 'text' => 'KSEF_Tour_send_vat_saledate'),
        );
        if ($currencies && $id === 'send_vat') {
            // currency of the invoice
            array_splice($steps, 1, 0, array(array('key' => 'currency', 'page' => 'inv_create', 'sel' => '#multicurrency_code', 'optional' => 1,
                'when' => '#multicurrency_code:has(option:not([value="PLN"]):not([value=""]):not([value="-1"]))',
                'vary' => array('#multicurrency_code option[value="PLN"]:not(:checked)' => 'KSEF_Tour_send_vat_currency_preset'))));
        }
        // deposit: payment date; settlement: in the form text
        return $type === 'deposit' || $id === 'send_roz' ? array_slice($steps, 0, 1) : $steps;
    };
    $noRows = '!' . $rowsSel;
    // the row being edited
    $edited = '#tablelines tr.tredited input:not([type="hidden"]), #tablelines tr.tredited select, #tablelines tr.tredited textarea';
    $fx = '#tablelines #multicurrency_price_ht';
    // the add row
    $addRow = '#tablelines tr:has(#addline):not(:has(tr)) :is(input:not([type="hidden"]), select, textarea, .select2)';
    $prodOn = isModEnabled('product') || isModEnabled('service');
    $lines = function ($id, $more = array()) use ($noRows, $rowsSel, $fx, $edited, $addRow, $prodOn) {
        $steps = array(
            // product, then price, then add
            array('key' => 'fxprod', 'page' => 'inv_card', 'sel' => '#tablelines tr:has(#addline):not(:has(tr)) td.linecoldescription', 'optional' => 1, 'info' => 1,
                'when' => array($noRows, $fx), 'text' => 'KSEF_Tour_send_vat_fxprod' . ($prodOn ? '' : '_noprod'), 'stage' => 'lines', 'refs' => $addRow),
            array('key' => 'fxprice', 'page' => 'inv_card', 'sel' => $fx, 'optional' => 1, 'when' => $noRows, 'text' => 'KSEF_Tour_send_vat_price_fx' . ($prodOn ? '' : '_noprod'), 'refs' => $addRow),
            array('key' => 'addrow', 'page' => 'inv_card', 'sel' => '#tablelines tr:has(#addline):not(:has(tr))', 'span' => '#tablelines #trlinefordates', 'rows' => 1, 'addrow' => 1,
                'when' => $noRows, 'unitsend' => $id === 'send_vat' ? 1 : 0, 'refs' => $id === 'send_zal' ? $addRow . ', #tablelines #price_ttc' : $addRow, 'text' => 'KSEF_Tour_' . $id . '_addrow', 'stage' => 'lines',
                'args' => array($prodOn && $id !== 'send_zal' ? 'KSEF_Tour_AddrowLine' : 'KSEF_Tour_AddrowLine_noprod'),
                'vary' => array('empty:' . $fx => 'KSEF_Tour_AddrowFxEmpty', $fx => 'KSEF_Tour_AddrowFx')),
            array_merge(array('key' => 'more', 'page' => 'inv_card', 'sel' => $rowsSel, 'span' => $rowsSel, 'rows' => 1, 'info' => 1, 'refs' => $addRow . ', ' . $edited, 'when' => '#tablelines #addline, #tablelines tr.tredited',
                'text' => 'KSEF_Tour_' . $id . '_more'), $more),
        );
        if ($id === 'send_zal') {
            // fields, then Dodać
            array_splice($steps, 2, 0, array(array_merge($steps[2], array('key' => 'zalline', 'info' => 1, 'rows' => 0, 'addrow' => 0, 'span' => '', 'text' => 'KSEF_Tour_send_zal_line', 'vary' => null))));
        }
        return $steps;
    };
    $korNone = array('text' => 'KSEF_Tour_None_Accepted', 'tour' => 'send_vat', 'live' => 'sent');
    $korZalNone = array('text' => 'KSEF_Tour_None_AcceptedDeposit', 'tour' => 'send_zal', 'live' => 'sent');
    // copied correction lines
    $korLines = function ($text, $vary, $fxText = 'KSEF_Tour_KorLinesEditingFx') use ($edited) {
        $pencil = '#tablelines tr[id^="row-"] a[href*="action=editline"]';
        return array('key' => 'lines', 'page' => 'inv_card', 'sel' => $pencil . ', #tablelines tr.tredited input[name="save"]', 'alt' => '#tablelines #addline', 'rows' => 1, 'stage' => 'lines',
            'span' => '#tablelines tr[id^="row-"] td.linecoldescription, #tablelines tr[id^="row-"] td.linecolqty, ' . $pencil,
            'refs' => '#tablelines tr.liste_titre, ' . $edited, 'text' => $text, 'vary' => $vary + array('#tablelines tr.tredited:has(#multicurrency_subprice)' => $fxText, '#tablelines tr.tredited' => 'KSEF_Tour_KorLinesEditing'));
    };
    $fetch = 'body:not(:has(.ksef-rate-ok)) .fichecenter a[href*="action=ksef_fetch_nbp_rate"]';
    // fetch first, then read
    $nbpFetch = array('key' => 'nbpfetch', 'page' => 'inv_card', 'sel' => $fetch, 'optional' => 1, 'wait' => 1, 'text' => 'KSEF_Tour_send_vat_nbp_fetch');
    $nbp = array('key' => 'nbp', 'page' => 'inv_card', 'sel' => '.ksef-rate-ok, .ksef-rate-stale, .ksef-rate-missing', 'optional' => 1, 'info' => 1,
        'text' => 'KSEF_Tour_send_vat_nbp', 'refs' => '.ksef-invoice-note[data-note="account_currency"]',
        'vary' => array('.ksef-invoice-note[data-note="account_currency"]' => 'KSEF_Tour_send_vat_nbp_account', '.ksef-rate-ok' => 'KSEF_Tour_send_vat_nbp_ok'));
    $backdated = array('key' => 'backdated', 'page' => 'inv_card', 'sel' => '#ksef-backdated-modal a[href*="action=ksef_backdated_online"]', 'alt' => '#ksef-backdated-modal > div',
        'optional' => 1, 'send' => 1, 'wait' => 1, 'text' => 'KSEF_Tour_Backdated');
    $offConfirm = array('key' => 'offconfirm', 'page' => 'inv_card', 'sel' => '#ksef-offline-confirm-modal a[href*="action=ksef_submit_offline_confirmed"]', 'optional' => 1, 'send' => 1,
        'text' => 'KSEF_Tour_OffConfirm');
    $deadline = array('key' => 'deadline', 'page' => 'inv_card', 'sel' => '.ksef-offline-deadline', 'optional' => 1, 'text' => 'KSEF_Tour_send_offline_deadline');
    $depositAck = array('key' => 'depositack', 'page' => 'inv_card', 'sel' => '#ksef-deposit-modal #ksef-deposits-send', 'span' => '#ksef-deposit-modal .ksef-deposits-tick', 'optional' => 1, 'send' => 1,
        'dialog' => 1, 'wait' => 1, 'text' => 'KSEF_Tour_DepositAck');
    $failure = array('key' => 'failure', 'page' => 'inv_card', 'sel' => '#ksef-failure-modal .ksef-failure-actions', 'optional' => 1, 'info' => 1, 'text' => 'KSEF_Tour_SendFailed', 'end' => array('tour' => 'send_offline', 'step' => 'failure'));
    // core job card
    $cronCard = array('page' => 'cron_card', 'sel' => 'a.butAction[href*="action=activate"]', 'alt' => '.tabsAction', 'info' => 1, 'module' => 'cron',
        'vary' => array('body:not(:has(a.butAction[href*="action=activate"]))' => 'KSEF_Tour_CronCardOn'));
    $cronOff = isModEnabled('cron') ? array() : array('text' => 'KSEF_Tour_setup_incoming_cronoff',
        'link' => array(array('url' => DOL_URL_ROOT . '/admin/modules.php?search_keyword=cron', 'label' => 'KSEF_Tour_LinkCron', 'admin' => 1, 'side' => 'KSEF_Tour_SideModule',
            'frame' => 'a[href*="action=set"][href*="value=modCron"]', 'done' => array('sel' => 'a[href*="action=reset"][href*="value=modCron"]', 'text' => 'KSEF_Tour_SideModuleOn'))));
    $offCert = !empty(ksefIsOfflineCertificateConfigured()['valid']);
    $sendSteps = function ($send, $after = array()) use ($depositAck, $backdated, $offConfirm, $confirm, $failure, $sent, $deadline, $status, $offCert) {
        return array_merge(array(array_merge($send, array('stage' => 'send')), $depositAck, $offCert ? $offConfirm : $backdated, $confirm, $failure, $sent, $deadline), $after ? $after : array($status));
    };
    $sendBtn = array('key' => 'send', 'page' => 'inv_card', 'sel' => $send, 'wait' => 1, 'send' => 1, 'errto' => array(array('when' => '.ksef-rate-missing, .ksef-rate-stale', 'key' => 'nbpfetch')));
    // a deposit to settle
    $depositPick = array('key' => 'deposit', 'page' => 'inv_list', 'sel' => $listRow, 'alt' => 'table.liste', 'query' => 'ksef_pick=roz', 'wait' => 1, 'go' => 1,
        'prefer' => 'table.liste tr.oddeven[data-ksef-pick-id="{obj}"] a[href*="/compta/facture/card.php"]', 'refs' => $pickCell,
        'picks' => 'body:has(tr.ksef-deposit-summary[data-kind="ZAL"]):has(tr.ksef-status-row .ksef-card-downloads)',
        'none' => array('text' => 'KSEF_Tour_None_Deposit', 'tour' => array('send_zal', 'send_vat')), 'stage' => 'pick');
    $payBtn = 'a.butAction[href*="/compta/paiement.php"][href*="action=create"]';
    $convert = array(
        array('key' => 'convert', 'page' => 'inv_card', 'sel' => 'a.butAction[href*="action=converttoreduc"]', 'optional' => 1, 'when' => '!' . $isCredit, 'text' => 'KSEF_Tour_send_roz_convert'),
        array('key' => 'convertyes', 'page' => 'inv_card', 'sel' => '.ui-dialog:has(#dialog-confirm) .ui-dialog-buttonpane button', 'optional' => 1, 'dialog' => 1, 'when' => '!' . $isCredit,
            'text' => 'KSEF_Tour_send_roz_convertyes'),
    );

    $defs = array(
        'update_apply' => array('track' => 'maint', 'order' => 1, 'admin' => 1, 'stages' => array('update'), 'steps' => array(
            array('key' => 'apply', 'page' => 'about', 'sel' => 'a.ksef-update-apply', 'go' => 1, 'stage' => 'update'),
            array('key' => 'confirm', 'page' => 'about_ask', 'sel' => '.ui-dialog:has(#dialog-confirm) .ui-dialog-buttonset > button:first-child', 'dialog' => 1),
            array('key' => 'done', 'page' => 'about', 'sel' => 'table.ksef-dbcheck', 'info' => 1, 'refs' => '.ksef-dbsummary',
                'vary' => array('.ksef-dbsummary[data-state="fix"]' => 'KSEF_Tour_update_apply_done_fix')),
        )),
        'setup_company' => array('track' => 'setup', 'order' => 1, 'admin' => 1, 'stages' => array('address', 'nip'), 'steps' => array_merge(array(
            // company data first
            array('key' => 'address', 'page' => 'setup', 'sel' => '#ksef-company-address', 'go' => 1, 'info' => 1, 'stage' => 'address',
                'vary' => array('#ksef-company-address .warning' => 'KSEF_Tour_setup_company_address_missing'),
                'link' => array(array('url' => DOL_URL_ROOT . '/admin/company.php', 'label' => 'KSEF_Tour_LinkCompany', 'admin' => 1, 'if' => '#ksef-company-address .warning', 'must' => 1,
                    'side' => 'KSEF_Tour_SideCompany', 'frame' => 'form:has(#name) input.button-save',
                    'refs' => '#selectcountry_id + .select2, #currency + .select2, #selectcountry_id, #currency, #MAIN_INFO_SOCIETE_ADDRESS, #MAIN_INFO_SOCIETE_ZIP, #MAIN_INFO_SOCIETE_TOWN, label[for="currency"], label[for="selectcountry_id"]'))),
            array('key' => 'nip', 'page' => 'setup', 'hash' => 'KSEF_COMPANY_NIP', 'sel' => 'input[name="KSEF_COMPANY_NIP"]', 'span' => 'a.ksef-fill-company[data-ident="NIP"]', 'go' => 1,
                'refs' => '#ksef-company-address', 'stage' => 'nip'),
            array('key' => 'ids', 'page' => 'setup', 'sel' => 'tr:has(> td > input[name="KSEF_COMPANY_KRS"])', 'span' => 'tr:has(> td > input[name="KSEF_COMPANY_EORI"])', 'optional' => 1, 'info' => 1),
            array('key' => 'names', 'page' => 'setup', 'sel' => '.ksef-config-warning[data-code="trans_overrides"]', 'optional' => 1, 'info' => 1),
            array('key' => 'save', 'page' => 'setup', 'sel' => 'input.button-save'),
        ), $noNames ? array(
            // Polish field names
            array('key' => 'langs', 'page' => 'dictionaries', 'hash' => 'ksef-trans-names', 'sel' => '#ksef-trans-names input.ksef-trans-lang', 'go' => 1,
                'prefer' => '#ksef-trans-names #trans_lang_' . (strpos((string) $GLOBALS['langs']->defaultlang, 'pl') === 0 ? 'pl_PL' : 'en_US'), 'refs' => '#ksef-trans-names tr.oddeven:not(:has(input))'),
            array('key' => 'langsave', 'page' => 'dictionaries', 'sel' => 'form:has(#ksef-trans-names) input.button-save'),
        ) : array())),
        'setup_env' => array('track' => 'setup', 'order' => 2, 'admin' => 1, 'stages' => array('env', 'check'), 'steps' => array(
            array('key' => 'select', 'page' => 'auth', 'sel' => '#ksef-env-form select[name="KSEF_ENVIRONMENT"]', 'go' => 1, 'refs' => '#auth_production_warning', 'stage' => 'env'),
            array('key' => 'save', 'page' => 'auth', 'sel' => '#ksef-env-form input.button-save'),
            array('key' => 'confirm', 'page' => 'auth_ask', 'sel' => '.ui-dialog:has(#dialog-confirm) .ui-dialog-buttonset > button:first-child', 'optional' => 1, 'dialog' => 1, 'back' => 'select'),
            array('key' => 'boxes', 'page' => 'auth', 'sel' => '#env-{env} .ksef-env-head', 'info' => 1, 'refs' => '#env-{env} .ksef-env-badges', 'stage' => 'check',
                'vary' => array('[data-ksef-active="1"]:not(:has(.ksef-no-auth))' => 'KSEF_Tour_setup_env_boxes_saved')),
            array('key' => 'portal', 'page' => 'auth', 'sel' => '#env-{env} .ksef-env-badges', 'info' => 1, 'portal' => 1, 'refs' => '#env-{env} .ksef-no-auth',
                'vary' => array('#env-TEST[data-ksef-active="1"]' => 'KSEF_Tour_setup_env_portal_test')),
        )),
        'setup_auth' => array('track' => 'setup', 'order' => 3, 'admin' => 1, 'stages' => array('auth', 'test', 'offline'), 'steps' => array(
            $gate,
            array('key' => 'way', 'page' => 'any', 'sel' => '', 'choices' => array('token', 'cert'), 'stage' => 'auth', 'args' => array('KSEF_ENV_' . ksefGetEnvironment())),
            array('key' => 'token', 'page' => 'auth', 'hash' => 'env-{env}', 'sel' => '#env-{env} input[name="KSEF_AUTH_TOKEN"]', 'go' => 1, 'portal' => 1,
                'path' => 'token', 'args' => array('KSEF_ENV_' . ksefGetEnvironment()),
                'vary' => array('.jnotify-notification-error' => 'KSEF_Tour_setup_auth_testauth_failed', '[data-ksef-active="1"] a[href*="action=ask_remove_token"]' => 'KSEF_Tour_setup_auth_token_replace')),
            array('key' => 'savetoken', 'page' => 'auth', 'hash' => 'env-{env}', 'sel' => $authSave, 'optional' => 1, 'path' => 'token',
                'when' => '!#env-{env}:has(input[name="KSEF_AUTH_TOKEN"]:placeholder-shown):not(:has(input[name="KSEF_AUTH_METHOD"]:checked:not([checked])))'),
            // files, then save
            array('key' => 'cert', 'page' => 'auth', 'hash' => 'env-{env}', 'sel' => $authCert, 'go' => 1, 'portal' => 1, 'info' => 1, 'submits' => 1, 'path' => 'cert',
                'vary' => array('.jnotify-notification-error' => 'KSEF_Tour_setup_auth_cert_failed')),
            array('key' => 'switch', 'page' => 'auth', 'hash' => 'env-{env}', 'sel' => '#env-{env} .ksef-auth-methods > div:has(input[value="certificate"]:not([checked]):not(:disabled))', 'optional' => 1, 'info' => 1,
                'path' => 'cert'),
            array('key' => 'savemethod', 'page' => 'auth', 'hash' => 'env-{env}', 'sel' => $authSave, 'optional' => 1, 'path' => 'cert',
                'when' => '!#env-{env} input[name="KSEF_AUTH_METHOD"][value="certificate"][checked], #env-{env} input[name="KSEF_AUTH_METHOD"][value="certificate"]:disabled, #env-{env} input[name="KSEF_AUTH_METHOD"][value="token"]:checked'),
            array('key' => 'testauth', 'page' => 'auth', 'hash' => 'env-{env}', 'sel' => '#env-{env} a[href*="action=testtokenauth"]', 'alt' => '#env-{env} .ksef-env-badges', 'wait' => 1,
                'path' => 'token', 'stage' => 'test', 'refs' => '#env-{env} .ksef-env-badges', 'none' => array('text' => 'KSEF_Tour_None_AuthTest', 'tour' => 'setup_company', 'end' => 1),
                'errto' => array(array('when' => '.jnotify-notification-error', 'key' => 'token'))),
            array('key' => 'testcert', 'page' => 'auth', 'hash' => 'env-{env}', 'sel' => '#env-{env} a[href*="action=testcertauth"]', 'alt' => $authCert, 'wait' => 1,
                'path' => 'cert', 'refs' => '#env-{env} .ksef-env-badges', 'none' => array('text' => 'KSEF_Tour_None_AuthCert', 'end' => 1),
                'errto' => array(array('when' => '.jnotify-notification-error', 'key' => 'cert'))),
            array('key' => 'offline', 'page' => 'auth', 'hash' => 'env-{env}', 'sel' => $offForm, 'optional' => 1, 'info' => 1, 'submits' => 1, 'stage' => 'offline', 'portal' => 1,
                'text' => $authOk ? 'KSEF_Tour_setup_auth_offline_ok' : 'KSEF_Tour_setup_auth_offline',
                'vary' => array('.jnotify-notification-error' => 'KSEF_Tour_setup_auth_offline_failed', $offSaved => 'KSEF_Tour_setup_auth_offline_saved')),
        )),
        'setup_vat' => array('track' => 'setup', 'order' => 4, 'admin' => 1, 'stages' => array('vat'), 'steps' => array(
            array('key' => 'table', 'page' => 'setup', 'hash' => 'ksef-vat-codes', 'sel' => '#ksef-vat-codes-list', 'span' => 'tr.ksef-vat-code-row', 'go' => 1, 'info' => 1, 'stage' => 'vat',
                'refs' => 'tr.ksef-vat-code-row td:nth-child(-n+3)'),
            array('key' => 'save', 'page' => 'setup', 'sel' => 'input.button-save'),
        )),
        'setup_defaults' => array('track' => 'setup', 'order' => 5, 'admin' => 1, 'stages' => array('payments', 'fields'), 'steps' => array(
            array('key' => 'term', 'page' => 'outgoing', 'hash' => 'KSEF_DEFAULT_PAYMENT_TERM_ID', 'sel' => '[name="KSEF_DEFAULT_PAYMENT_TERM_ID"]', 'go' => 1, 'stage' => 'payments'),
            array('key' => 'method', 'page' => 'outgoing', 'hash' => 'KSEF_DEFAULT_PAYMENT_METHOD_ID', 'sel' => '[name="KSEF_DEFAULT_PAYMENT_METHOD_ID"]', 'go' => 1),
            // bank account first
            array('key' => 'bankon', 'page' => 'outgoing', 'sel' => 'a.ksef-bank-enable', 'optional' => 1, 'wait' => 1),
            array('key' => 'bankadd', 'page' => 'outgoing', 'sel' => 'a.ksef-bank-add', 'optional' => 1, 'wait' => 1,
                'side' => array('page' => '/compta/bank/card\\.php', 'sel' => 'form:has(input[name="ref"])', 'text' => 'KSEF_Tour_setup_defaults_bankform')),
            // just set by bankadd
            array('key' => 'bank', 'page' => 'outgoing', 'sel' => '[name="KSEF_DEFAULT_BANK_ACCOUNT_ID"]', 'optional' => 1,
                'when' => '!body:has(.jnotify-notification:not(.jnotify-notification-error):not(.jnotify-notification-warning)) [name="KSEF_DEFAULT_BANK_ACCOUNT_ID"] option[selected]:not([value="-1"]):not([value="0"]):not([value=""])'),
            array('key' => 'save', 'page' => 'outgoing', 'sel' => 'input.button-save'),
            array('key' => 'saledate', 'page' => 'fields', 'hash' => 'KSEF_FA3_SALE_DATE_SOURCE', 'sel' => 'select[name="KSEF_FA3_SALE_DATE_SOURCE"]', 'go' => 1, 'stage' => 'fields'),
            array('key' => 'notes', 'page' => 'fields', 'hash' => 'KSEF_NOTE_COMBINED_MODE', 'sel' => 'select[name="KSEF_NOTE_COMBINED_MODE"]'),
            array('key' => 'savefields', 'page' => 'fields', 'sel' => 'input.button-save'),
        )),
        'setup_incoming' => array('track' => 'setup', 'order' => 6, 'admin' => 1, 'stages' => array('fetch', 'jobs'), 'steps' => array_merge(array(
            array_merge(array('key' => 'cron', 'page' => 'inc_list', 'sel' => 'tr.ksef-cron-incoming', 'go' => 1, 'info' => 1, 'stage' => 'fetch'), $cronOff),
            array('key' => 'sync', 'page' => 'inc_list', 'sel' => '#ksef-sync-btn', 'info' => 1),
            array('key' => 'enablejobs', 'page' => 'about', 'sel' => 'tr.ksef-cron-required-off input.ksef-enable-jobs', 'optional' => 1, 'wait' => 1, 'module' => 'cron', 'go' => 1, 'stage' => 'jobs',
                'refs' => 'table.ksef-cron-jobs tr.ksef-cron-required-off'),
            array('key' => 'jobs', 'page' => 'about', 'sel' => 'table.ksef-cron-jobs', 'go' => 1, 'info' => 1, 'stage' => 'jobs',
                'vary' => array('tr.ksef-cron-required-off input.ksef-enable-jobs' => 'KSEF_Tour_setup_incoming_jobs_off'),
                'link' => array(array('url' => dol_buildpath('/ksef/admin/howtouse.php', 1) . '#ksef-howto-jobs', 'label' => 'KSEF_Tour_LinkCronHelp'))),
        // job already on
        ), $syncJobOn ? array() : array(
            array('key' => 'card', 'page' => 'about', 'sel' => 'table.ksef-cron-jobs tr[data-ksef-job="cronSyncIncoming"] a[href*="cron/card.php"]', 'wait' => 1, 'module' => 'cron'),
            array_merge($cronCard, array('key' => 'croncard', 'text' => 'KSEF_Tour_setup_incoming_croncard')),
        ))),
        'setup_optional' => array('track' => 'setup', 'order' => 7, 'admin' => 1, 'name' => isModEnabled('accounting') ? '' : 'setup_optional_noacc', 'next' => 'send_vat',
            'stages' => array('nbp', 'settle', 'gus', 'rights'), 'steps' => array_merge(array(
            array('key' => 'nbp', 'page' => 'setup', 'hash' => 'KSEF_NBP_RATE_MODE', 'sel' => 'tr.ksef-nbp-cron', 'go' => 1, 'info' => 1, 'stage' => 'nbp'),
            array('key' => 'nbpopen', 'page' => 'setup', 'sel' => 'tr.ksef-nbp-cron a[href*="cron/card.php"]', 'optional' => 1, 'wait' => 1, 'module' => array('cron', 'multicurrency')),
            array_merge($cronCard, array('key' => 'nbpcard', 'optional' => 1, 'text' => 'KSEF_Tour_setup_optional_nbpcard', 'module' => array('cron', 'multicurrency'))),
            array('key' => 'settle', 'page' => 'chart', 'hash' => 'ksef-chart-load', 'sel' => '#ksef-chart-load', 'go' => 1, 'module' => 'accounting', 'info' => 1, 'stage' => 'settle'),
            array('key' => 'gus', 'page' => 'auth', 'hash' => 'env-GUS', 'sel' => '#env-GUS', 'go' => 1, 'info' => 1, 'stage' => 'gus'),
        ), ksefTourOtherUsers() ? array(
            array('key' => 'rights', 'page' => 'users', 'sel' => 'table.liste tr.oddeven a[href*="/user/card.php"]', 'alt' => 'table.liste', 'go' => 1, 'wait' => 1, 'stage' => 'rights'),
            array('key' => 'permtab', 'page' => 'user_card', 'sel' => 'a#rights', 'wait' => 1),
            array('key' => 'perms', 'page' => 'user_perms', 'sel' => 'tr.trforbreakperms[data-hide-perms="ksef"]', 'span' => 'tr.trtohide_ksef', 'info' => 1),
        ) : array(
            // admin only
            array('key' => 'rights', 'page' => 'users', 'sel' => 'table.liste', 'go' => 1, 'info' => 1, 'stage' => 'rights', 'text' => 'KSEF_Tour_setup_optional_rights_admin'),
        ))),

        'send_vat' => array('track' => 'send', 'order' => 1, 'next' => 'send_kor', 'stages' => array('form', 'lines', 'send', 'check'), 'steps' => array_merge(array(
            $gate,
            $customer,
        ), $form('send_vat'), array(
            $createSave,
        ), $lines('send_vat'), array(
            $nbpFetch,
            $nbp,
        ), $sendSteps($sendBtn, array(
            $status,
            array('key' => 'tab', 'page' => 'inv_card', 'sel' => 'a#ksef', 'wait' => 1),
            array('key' => 'tabpage', 'page' => 'tab_ksef', 'sel' => 'table.ksef-history', 'refs' => '.tabsElem a[href*="compta/facture/card.php"]', 'args' => array('Status', 'Error', 'Action')),
        )))),
        'send_kor' => array('track' => 'send', 'order' => 2, 'next' => 'send_zal', 'stages' => array('pick', 'form', 'details', 'lines', 'send', 'check'), 'steps' => array_merge(array($gate), $korStart('fac_replacement', $korNone, 'kor'), $korNew('#fac_replacement', 'send_vat'), array(
            $korDate,
            $korSave,
        ), $details, array(
            $korLines('KSEF_Tour_send_kor_lines', array()),
            $nbpFetch,
            $nbp,
        ), $sendSteps($sendBtn))),
        'send_zal' => array('track' => 'send', 'order' => 3, 'next' => 'send_zal_pay', 'stages' => array('form', 'lines', 'send', 'check'), 'steps' => array_merge(array($gate), $fromOrder(), array(
            array('key' => 'type', 'page' => 'inv_create', 'sel' => '#radio_deposit'),
        ), $orders ? array(array('key' => 'amount', 'page' => 'inv_create', 'sel' => '#valuedeposit', 'path' => 'order',
            'vary' => array('#typedeposit option[value^="variable"]:checked' => 'KSEF_Tour_send_zal_amount_pct'))) : array(), $form('send_zal', 'deposit'), array(
            $createSave,
        ), array_map(function ($s) use ($orders) {
            return $orders ? array_merge($s, array('path' => 'new')) : $s;
        }, $lines('send_zal')), array(
            array('key' => 'summary', 'page' => 'inv_card', 'sel' => 'tr.ksef-deposit-summary', 'optional' => 1, 'stage' => 'lines',
                'vary' => array('tr.ksef-deposit-summary a[href*="/commande/card.php"], tr.ksef-deposit-summary a[href*="/comm/propal/card.php"]' => 'KSEF_Tour_send_zal_summary_order')),
            $nbpFetch,
            $nbp,
        ), $sendSteps($sendBtn))),
        'send_zal_pay' => array('track' => 'send', 'order' => 4, 'next' => 'send_roz', 'stages' => array('pick', 'pay', 'credit'), 'steps' => array_merge(array(
            $depositPick,
            array('key' => 'pay', 'page' => 'inv_card', 'sel' => $payBtn, 'optional' => 1, 'wait' => 1, 'stage' => 'pay', 'text' => 'KSEF_Tour_send_zal_pay_pay',
                'vary' => array('.paymenttable a[href*="/compta/paiement/card.php"]' => 'KSEF_Tour_send_roz_pay_partial')),
            // date, method, account
            array('key' => 'payhead', 'page' => 'pay_create', 'sel' => '#payment_form #re', 'span' => '#payment_form select[name^="paiement"] + .select2, #payment_form select[name="accountid"] + .select2',
                'optional' => 1, 'info' => 1, 'refs' => '#ui-datepicker-div', 'args' => array('AccountToCredit'),
                'vary' => array('#payment_form:not(:has(#selectaccountid))' => 'KSEF_Tour_send_zal_pay_payhead_nobank')),
            array('key' => 'payform', 'page' => 'pay_create', 'sel' => '#payment_form input.button[type="submit"]:not(.confirmvalidatebutton)', 'optional' => 1, 'wait' => 1,
                'refs' => '#payment_form tr:has(#re), #payment_form input[name="amount_{obj}"], #payment_form .AutoFillAmount[data-rowname="amount_{obj}"], #ui-datepicker-div', 'errto' => array(array('when' => 'empty:#re', 'key' => 'payhead'), array('when' => 'body', 'key' => 'payform'))),
            array('key' => 'payconfirm', 'page' => 'pay_create', 'where' => 'KSEF_TourPage_pay_confirm', 'sel' => '#payment_form .confirmvalidatebutton', 'span' => '#payment_form select[name="confirm"]', 'optional' => 1, 'wait' => 1,
                'vary' => array('#payment_form input[name="ksef_back"]' => 'KSEF_Tour_send_zal_pay_payconfirm_back')),
        ), array(array_merge($convert[0], array('stage' => 'credit')), $convert[1]), array(
            array('key' => 'done', 'page' => 'inv_card', 'sel' => 'body:has(tr.ksef-deposit-summary) .statusref', 'prefer' => 'tr.ksef-deposit-back-row', 'optional' => 1, 'info' => 1,
                'vary' => array('tr.ksef-deposit-back-row[data-state="credit"]' => 'KSEF_Tour_send_zal_pay_done_back')),
        ))),
        'send_roz' => array('track' => 'send', 'order' => 5, 'next' => 'send_kor_zal', 'stages' => array('pick', 'form', 'lines', 'deposits', 'send', 'check'), 'steps' => array_merge(array(
            $gate,
            $depositPick,
            array('key' => 'pay', 'page' => 'inv_card', 'sel' => $payBtn, 'optional' => 1, 'start' => array('tour' => 'send_zal_pay', 'step' => 'payhead', 'back' => 'convert'),
                'vary' => array('.paymenttable a[href*="/compta/paiement/card.php"]' => 'KSEF_Tour_send_roz_pay_partial')),
        ), $convert, array(
            array('key' => 'ready', 'page' => 'inv_card', 'sel' => 'body:has(tr.ksef-deposit-summary[data-kind="ZAL"][data-deposit-state="credit"]) .statusref', 'optional' => 1, 'info' => 1),
        ), array_map(function ($s) {
            return $s['key'] === 'way' ? array_merge($s, array('stage' => 'form')) : $s;
        }, $fromOrder(true)), $form('send_roz'), array(
            $createSave,
        ), $lines('send_roz', array('vary' => array($noOrder . $rowsSel => 'KSEF_Tour_send_roz_more_noorder'))), array(
            // no order: by hand
            array('key' => 'credit', 'page' => 'inv_card', 'sel' => '.fichehalfleft select[name="remise_id"]', 'optional' => 1, 'when' => $manual, 'stage' => 'deposits',
                'refs' => '.fichehalfleft tr:has(select[name="remise_id"]) > td:first-child', 'need' => '.fichehalfleft select[name="remise_id"]', 'one' => 1),
            array('key' => 'creditapply', 'page' => 'inv_card', 'sel' => '.fichehalfleft form:has(select[name="remise_id"]) input[type="submit"]', 'optional' => 1,
                'when' => array($manual, 'filled:.fichehalfleft select[name="remise_id"]')),
            array('key' => 'deposits', 'page' => 'inv_card', 'sel' => '.fichehalfleft tr:has(select[name="remise_id"])', 'prefer' => 'a.ksef-deposit-apply, ' . $rozOn, 'prefclick' => 1,
                'alt' => '.fichehalfleft tr:has(> td > a[href*="/comm/remise.php"])', 'info' => 1, 'stage' => 'deposits', 'when' => array($notZal, '!' . $manual),
                'refs' => '.fichehalfleft tr:has(select[name="remise_id"]) > td:first-child',
                'vary' => array('a.ksef-deposit-apply' => 'KSEF_Tour_send_roz_deposits_apply', $rozOn . '[data-deposits-open="0"]' => 'KSEF_Tour_send_roz_deposits_done',
                    'body:has(' . $unpaid . ') ' . $rozOn => 'KSEF_Tour_send_roz_deposits_unpaid', $rozOn => 'KSEF_Tour_send_roz_deposits_warn'),
                'none' => array('text' => 'KSEF_Tour_None_Credit', 'tour' => 'send_zal', 'unless' => $rozOn . ', a.ksef-deposit-apply, ' . $unpaid)),
            array('key' => 'payother', 'page' => 'inv_card', 'sel' => $unpaid, 'optional' => 1, 'when' => array('!a.ksef-deposit-apply', $notZal),
                'start' => array('tour' => 'send_zal_pay', 'step' => 'payhead', 'back' => 'credit')),
            array('key' => 'summary', 'page' => 'inv_card', 'sel' => $rozOn, 'optional' => 1, 'when' => array('!' . $rozOn . '[data-deposits-open="0"]', $notZal)),
            $nbpFetch,
            $nbp,
        ), $sendSteps(array_merge($sendBtn, array('suffix' => getDolGlobalInt('KSEF_CONFIRM_BEFORE_UPLOAD', 1) ? 'KSEF_Tour_SendConfirmNote' : '',
            'vary' => array('body:not(:has(' . $rozOn . '))' => 'KSEF_Tour_send_roz_send_nodeposit', $rozOn . ':not([data-deposits-open="0"])' => 'KSEF_Tour_send_roz_send_open'))),
            array(array_merge($status, array('prefer' => $accepted . $rozOn, 'vary' => $notYet + array('body:not(:has(' . $rozOn . '))' => 'KSEF_Tour_send_roz_status_nodeposit'))))))),
        'send_kor_zal' => array('track' => 'send', 'order' => 6, 'stages' => array('pick', 'form', 'details', 'lines', 'send', 'check'), 'steps' => array_merge(array($gate),
            array_slice($korStart('fac_replacement', $korZalNone, 'korzal'), 0, 2), array(
            array('key' => 'settle', 'page' => 'inv_card', 'sel' => '.ksef-settlement-needs-kor', 'optional' => 1, 'path' => 'card'),
        ), array_slice($korStart('fac_replacement', $korZalNone, 'korzal'), 2), $korNew('#fac_replacement', 'send_zal'), array(
            $korDate,
            $korSave,
        ), $details, array(
            // settlement first
            $korLines('KSEF_Tour_send_kor_zal_lines', array('body:has(tr.ksef-deposit-summary[data-kind="KOR_ROZ"]) #tablelines tr.tredited' => 'KSEF_Tour_send_kor_zal_lines_editing_roz',
                'tr.ksef-deposit-summary[data-kind="KOR_ROZ"]' => 'KSEF_Tour_send_kor_zal_lines_roz', '#tablelines tr.tredited:has(#multicurrency_subprice)' => 'KSEF_Tour_send_kor_zal_lines_editing_fx',
                '#tablelines tr.tredited' => 'KSEF_Tour_send_kor_zal_lines_editing'), 'KSEF_Tour_send_kor_zal_lines_editing_fx'),
            $nbpFetch,
            $nbp,
        ), $sendSteps(array_merge($sendBtn, array('vary' => array('tr.ksef-deposit-summary[data-kind="KOR_ROZ"]' => 'KSEF_Tour_send_kor_zal_send_roz'))), array(
            array_merge($status, array('prefer' => $accepted . 'tr.ksef-deposit-summary', 'vary' => $notYet + array('.ksef-deposit-settlement-needs-kor' => 'KSEF_Tour_send_kor_zal_status_settle',
                'div.ksef-deposit-lowered-overpaid' => 'KSEF_Tour_send_kor_zal_status_overpaid', 'tr.ksef-deposit-summary[data-kind="KOR_ZAL"]' => 'KSEF_Tour_send_kor_zal_status_zal',
                'tr.ksef-deposit-summary[data-kind="KOR_ROZ"]' => 'KSEF_Tour_send_kor_zal_status_roz'))),
            array('key' => 'tab', 'page' => 'inv_card', 'sel' => 'a#ksef', 'wait' => 1),
            array('key' => 'tabpage', 'page' => 'tab_ksef', 'sel' => 'table.ksef-correction-source, table.ksef-history', 'refs' => 'table.ksef-history, .tabsElem a[href*="compta/facture/card.php"]'),
        )))),
        'send_offline' => array('track' => 'send', 'order' => 7, 'stages' => array('prep', 'pick', 'form', 'lines', 'send', 'check'), 'steps' => array_merge(array(
            $gate,
            array('key' => 'latarnia', 'page' => 'dashboard', 'sel' => '#ksef-latarnia-box', 'go' => 1, 'info' => 1, 'stage' => 'prep'),
            array('key' => 'pick', 'page' => 'inv_list', 'sel' => $listRow, 'alt' => 'table.liste', 'query' => 'ksef_pick=offline', 'wait' => 1, 'go' => 1, 'path' => 'card', 'stage' => 'pick',
                'refs' => $pickCell, 'picks' => 'body:not(:has(tr.ksef-status-row .ksef-card-downloads))', 'none' => array('text' => 'KSEF_Tour_None_Offline', 'way' => 'new')),
            array_merge($customer, array('path' => 'new', 'stage' => 'form', 'go' => 1, 'side' => $newSoc)),
            array('key' => 'backdate', 'page' => 'inv_create', 'sel' => '#re', 'span' => '#re ~ img.ui-datepicker-trigger, #reButton, #reButtonNow', 'refs' => '#ui-datepicker-div',
                'path' => 'new', 'need' => '#re'),
            array_merge($createSave, array('path' => 'new', 'errto' => array(array('when' => $noSoc, 'key' => 'customer'), array('when' => 'body', 'key' => 'backdate')))),
        ), array_map(function ($s) {
            return array_merge($s, array('path' => 'new'), $s['key'] === 'addrow' ? array('text' => 'KSEF_Tour_send_vat_addrow') : array(), $s['key'] === 'more' ? array('text' => 'KSEF_Tour_send_vat_more') : array());
        }, $lines('send_vat')), array(
            array('key' => 'send', 'page' => 'inv_card', 'sel' => $send . ', a[href*="action=ksef_retry&"]', 'alt' => 'tr.ksef-status-row', 'optional' => 1, 'wait' => 1, 'send' => 1, 'stage' => 'send',
                'text' => $offCert ? 'KSEF_Tour_send_offline_send_cert' : 'KSEF_Tour_send_offline_send',
                'none' => array('text' => 'KSEF_Tour_None_OfflineCard', 'tour' => 'send_vat')),
            $confirm,
            array('key' => 'failure', 'page' => 'inv_card', 'sel' => '#ksef-failure-modal .ksef-failure-actions', 'optional' => 1, 'send' => 1, 'info' => 1),
            array('key' => 'offline', 'page' => 'inv_card', 'sel' => 'a[href*="action=ksef_ask_create_offline"], #ksef-failure-modal a[href*="action=ksef_submit_offline_confirmed"]',
                'optional' => 1, 'wait' => 1, 'send' => 1),
            array('key' => 'offconfirm', 'page' => 'inv_card', 'sel' => '#ksef-offline-confirm-modal a[href*="action=ksef_submit_offline_confirmed"], .ui-dialog:has(#dialog-confirm) .ui-dialog-buttonpane button',
                'optional' => 1, 'send' => 1, 'vary' => array('.ui-dialog:has(#dialog-confirm)' => 'KSEF_Tour_send_offline_offconfirm_core')),
            $sent,
            array('key' => 'deadline', 'page' => 'inv_card', 'sel' => '.ksef-offline-deadline', 'optional' => 1, 'stage' => 'check'),
            array_merge($status, array('vary' => array('tr.ksef-status-row:has(.ksef-error-icon)' => 'KSEF_Tour_send_offline_status_failed',
                'tr.ksef-status-row:has(.ksef-offline-deadline)' => 'KSEF_Tour_send_offline_status', 'tr.ksef-status-row:has(.ksef-issued-offline)' => 'KSEF_Tour_send_offline_status_offaccepted',
                'tr.ksef-status-row:has(.ksef-card-downloads)' => 'KSEF_Tour_send_offline_status_online') + $notYet)),
        ))),
        'send_sales' => array('track' => 'send', 'order' => 8, 'stages' => array('sync', 'import'), 'steps' => array(
            array('key' => 'sync', 'page' => 'status', 'sel' => '#ksef-sync-btn', 'go' => 1, 'stage' => 'sync', 'refs' => 'table.ksef-outgoing-sync'),
            array('key' => 'counts', 'page' => 'status', 'sel' => 'tr.ksef-match-counts', 'optional' => 1, 'info' => 1),
            array('key' => 'filter', 'page' => 'status', 'sel' => 'tr.ksef-match-counts a[href*="search_link=IMPORTABLE"]', 'alt' => 'tr.ksef-match-counts', 'optional' => 1,
                'none' => array('text' => 'KSEF_Tour_None_Sales', 'live' => 'sent', 'end' => 1)),
            array('key' => 'missing', 'page' => 'status', 'sel' => 'tr[data-match="IMPORTABLE"] a[href*="sales_import.php"]', 'optional' => 1, 'wait' => 1, 'stage' => 'import'),
            array('key' => 'import', 'page' => 'sales_import', 'sel' => '#import_form > .fichecenter', 'alt' => '#import_form', 'optional' => 1, 'path' => 'import', 'info' => 1,
                'refs' => '#import_form input:not([type="hidden"]), #import_form select, #import_form .select2, #import_form button, #import_form textarea'),
            array('key' => 'importbtn', 'page' => 'sales_import', 'sel' => '#btn_import', 'optional' => 1, 'wait' => 1, 'path' => 'import'),
            array('key' => 'confirm', 'page' => 'sales_import', 'sel' => '.ui-dialog:has(#import-confirm-modal) .ui-dialog-buttonpane button.butAction', 'optional' => 1, 'dialog' => 1,
                'path' => 'import'),
            array('key' => 'done', 'page' => 'inv_card', 'sel' => 'tr.ksef-status-row', 'optional' => 1, 'path' => 'import'),
            array('key' => 'adjust', 'page' => 'status', 'sel' => 'a[href*="sales_adjust.php"]', 'optional' => 1, 'wait' => 1, 'path' => 'adjust'),
            array('key' => 'compare', 'page' => 'sales_adjust', 'sel' => '.ksef-adjust-head', 'optional' => 1, 'path' => 'adjust'),
        )),

        'recv_fetch' => array('track' => 'receive', 'order' => 1, 'next' => 'recv_import', 'stages' => array('fetch', 'check'), 'steps' => array(
            array('key' => 'dash', 'page' => 'dashboard', 'sel' => '.ksef-dash-sync-row', 'span' => '.ksef-dash-sync-row', 'go' => 1, 'optional' => 1, 'info' => 1, 'stage' => 'fetch'),
            array('key' => 'sync', 'page' => 'inc_list', 'sel' => '#ksef-sync-btn', 'go' => 1),
            array('key' => 'cron', 'page' => 'inc_list', 'sel' => 'tr.ksef-cron-incoming', 'info' => 1, 'text' => $syncJobOn ? 'KSEF_Tour_recv_fetch_cron' : 'KSEF_Tour_recv_fetch_cron_off'),
            array('key' => 'list', 'page' => 'inc_list', 'sel' => '#searchFormList table.liste:has(tr.oddeven a[href*="incoming_card.php"])', 'alt' => '#ksef-sync-btn, #searchFormList', 'info' => 1, 'stage' => 'check',
                'refs' => '#searchFormList tr.oddeven td:has(a[href*="incoming_card.php"])',
                'none' => array('text' => 'KSEF_Tour_None_Fetched', 'after' => 'KSEF_Tour_None_FetchedAfter', 'live' => 'received', 'end' => 1)),
            array('key' => 'open', 'page' => 'inc_list', 'sel' => '#searchFormList a[href*="incoming_card.php"]', 'optional' => 1, 'wait' => 1),
            array('key' => 'card', 'page' => 'inc_card', 'sel' => 'a[href*="action=download_pdf"]', 'span' => 'a.butAction[href*="incoming_import.php"]', 'optional' => 1, 'info' => 1),
        )),
        'recv_import' => array('track' => 'receive', 'order' => 2, 'stages' => array('pick', 'import', 'check'), 'steps' => array(
            $gate,
            $incPick($incType('="VAT"') . ', ' . $incType('="UPR"'), 'KSEF_Tour_None_Incoming'),
            $supplierStep,
            array('key' => 'lines', 'page' => 'inc_import', 'sel' => '#tablelines', 'info' => 1),
            array('key' => 'import', 'page' => 'inc_import', 'sel' => '#btn_import', 'wait' => 1),
            $importConfirm,
            array('key' => 'done', 'page' => 'sup_card', 'sel' => 'tr.ksef-sup-received', 'alt' => '.fiche', 'optional' => 1, 'stage' => 'check',
                'next' => array('sel' => 'body', 'tour' => 'recv_import', 'step' => 'pick', 'label' => 'KSEF_Tour_recv_import_again')),
        )),
        'recv_kor' => array('track' => 'receive', 'order' => 3, 'stages' => array('pick', 'import', 'check'), 'steps' => array(
            $gate,
            array_merge($incPick($incType('^="KOR"][data-ksef-orig'), 'KSEF_Tour_None_IncomingKor'), array(
                'resel' => $incType('^="KOR"') . ', a.butAction[href*="incoming_import.php"]:not([href*="quickimport"])',
                'vary' => array('body.page-incoming-card' => 'KSEF_Tour_RecvPickCard',
                    '#searchFormList:not(:has(tr.oddeven[data-ksef-type^="KOR"][data-ksef-orig] a[href*="action=quickimport"])) tr.oddeven[data-ksef-type^="KOR"]:has(a[href*="action=quickimport"])'
                        => array('KSEF_Tour_recv_kor_pick_sync', 'fresh')),
                'none' => array_merge($pickIncoming['none'], array('text' => 'KSEF_Tour_None_IncomingKor', 'unless' => $incType('^="KOR"'))))),
            array('key' => 'corrects', 'page' => 'inc_import', 'where' => 'KSEF_TourPage_kor_import', 'sel' => 'tr.ksef-corrects-row, table.ksef-corrects-table', 'info' => 1, 'stage' => 'import', 'when' => '!.ksef-original-missing'),
            array('key' => 'missing', 'page' => 'inc_import', 'where' => 'KSEF_TourPage_kor_import', 'sel' => 'a.ksef-import-original, a.ksef-download-original', 'alt' => '.ksef-original-missing',
                'optional' => 1, 'wait' => 1, 'lastif' => '.ksef-original-absent, .jnotify-notification-error, .ksef-original-missing:not(:has(+ a))',
                'vary' => array('.ksef-original-absent, .jnotify-notification-error' => 'KSEF_Tour_recv_kor_missing_failed', 'a.ksef-import-original' => 'KSEF_Tour_recv_kor_missing_import',
                    '.ksef-original-missing:not(:has(+ a))' => 'KSEF_Tour_recv_kor_missing_never')),
            array_merge($supplierStep, array('key' => 'origsupplier', 'optional' => 1, 'when' => '#ksef_for_kor', 'stage' => '')),
            array('key' => 'origimport', 'page' => 'inc_import', 'sel' => 'body:has(#ksef_for_kor) #btn_import', 'optional' => 1, 'wait' => 1),
            array_merge($importConfirm, array('key' => 'origconfirm', 'text' => 'KSEF_Tour_recv_kor_origconfirm', 'sel' => 'body:has(#ksef_for_kor) ' . $importConfirm['sel'])),
            array('key' => 'info', 'page' => 'inc_import', 'where' => 'KSEF_TourPage_kor_import', 'sel' => '#ksef_korekta_info', 'optional' => 1, 'info' => 1),
            array('key' => 'refused', 'page' => 'inc_import', 'where' => 'KSEF_TourPage_kor_import', 'sel' => '#ksef_korekta_refused', 'optional' => 1, 'lastif' => '#ksef_korekta_refused'),
            array('key' => 'strategy', 'page' => 'inc_import', 'where' => 'KSEF_TourPage_kor_import', 'sel' => '#correction_strategy_radios', 'optional' => 1),
            array('key' => 'import', 'page' => 'inc_import', 'where' => 'KSEF_TourPage_kor_import', 'sel' => $noOrig . '#btn_import', 'optional' => 1, 'wait' => 1),
            array_merge($importConfirm, array('sel' => $noOrig . $importConfirm['sel'], 'text' => 'KSEF_Tour_recv_kor_confirm')),
            array('key' => 'done', 'page' => 'sup_card', 'sel' => 'tr.ksef-sup-corrects', 'prefer' => 'table.ksef-correction-chain', 'alt' => 'tr.ksef-sup-received', 'optional' => 1, 'stage' => 'check'),
        )),
        'recv_deposits' => array('track' => 'receive', 'order' => 4, 'stages' => array('pick', 'import', 'pay', 'check'), 'steps' => array(
            $gate,
            $incPick($incType('="ZAL"') . ', #searchFormList:not(:has(tr[data-ksef-type="ZAL"] a[href*="action=quickimport"])) tr.oddeven[data-ksef-type="ROZ"]:has(a[href*="action=quickimport"]) a[href*="incoming_card.php"]', 'KSEF_Tour_None_IncomingDeposit'),
            $supplierStep,
            array('key' => 'deposits', 'page' => 'inc_import', 'sel' => 'tr.ksef-incoming-deposits', 'optional' => 1, 'info' => 1),
            array('key' => 'lines', 'page' => 'inc_import', 'sel' => '#tablelines', 'info' => 1, 'refs' => '#tablelines tr.ksef-import-deduction'),
            array('key' => 'import', 'page' => 'inc_import', 'sel' => '#btn_import', 'wait' => 1),
            $importConfirm,
            array('key' => 'pay', 'page' => 'sup_card', 'sel' => '.ksef-pay-from-ksef, a.butAction[href*="/fourn/facture/paiement.php"]', 'optional' => 1, 'wait' => 1, 'stage' => 'pay',
                'vary' => array('body:not(:has(.ksef-pay-from-ksef))' => 'KSEF_Tour_recv_deposits_pay_manual', 'tr.ksef-sup-received[data-ksef-type="ZAL"]' => 'KSEF_Tour_recv_deposits_pay_zal')),
            array('key' => 'payask', 'page' => 'sup_ask', 'sel' => '.ui-dialog:has(#dialog-confirm) .ui-dialog-buttonset > button:first-child', 'optional' => 1, 'dialog' => 1),
            array('key' => 'payhead', 'page' => 'sup_pay', 'sel' => '#payment_form #re', 'span' => '#payment_form select[name^="paiement"] + .select2, #payment_form select[name="accountid"] + .select2',
                'optional' => 1, 'info' => 1, 'refs' => '#ui-datepicker-div', 'args' => array('Account'), 'text' => 'KSEF_Tour_send_zal_pay_payhead'),
            array('key' => 'payform', 'page' => 'sup_pay', 'sel' => '#payment_form input.button[type="submit"]:not(.button-cancel)', 'optional' => 1, 'wait' => 1,
                'refs' => '#payment_form tr:has(#re), #payment_form input[name="amount_{obj}"], #payment_form .AutoFillAmount[data-rowname="amount_{obj}"], #ui-datepicker-div', 'errto' => array(array('when' => 'empty:#re', 'key' => 'payhead'), array('when' => 'body', 'key' => 'payform'))),
            array('key' => 'payconfirm', 'page' => 'sup_pay', 'where' => 'KSEF_TourPage_pay_confirm', 'sel' => '.confirmvalidatebutton', 'span' => 'select[name="confirm"]', 'optional' => 1, 'wait' => 1,
                'text' => 'KSEF_Tour_recv_deposits_supconfirm'),
            array('key' => 'done', 'page' => 'sup_card', 'sel' => 'tr.ksef-sup-received', 'alt' => '.fiche', 'optional' => 1, 'info' => 1, 'stage' => 'check',
                'vary' => array('tr.ksef-sup-received[data-ksef-type="ZAL"]' => 'KSEF_Tour_recv_deposits_done_zal'),
                'next' => array('sel' => 'tr.ksef-sup-received[data-ksef-type="ZAL"]', 'tour' => 'recv_deposits', 'step' => 'pick', 'label' => 'KSEF_Tour_recv_deposits_again')),
        )),
    );
    if (!ksefTourSandbox()) {
        // sandbox walkthrough only
        unset($defs['send_offline']);
    }
    // stage per step
    foreach ($defs as $id => $d) {
        $stage = '';
        foreach ($d['steps'] as $k => $s) {
            if (!empty($s['stage'])) {
                $stage = $s['stage'];
            }
            $defs[$id]['steps'][$k]['stage'] = !empty($s['gate']) ? '' : $stage;
            if (!empty($s['gate'])) {
                // per track
                $defs[$id]['steps'][$k]['text'] = 'KSEF_Tour_Gate_' . ($id === 'send_offline' ? 'offline' : $d['track']);
            }
        }
    }
    return $defs;
}

/**
 * @brief Active non-admin users
 * @return int
 */
function ksefTourOtherUsers()
{
    global $db;
    $res = $db->query("SELECT COUNT(*) AS n FROM " . MAIN_DB_PREFIX . "user WHERE admin = 0 AND statut = 1 AND entity IN (" . getEntity('user') . ")");
    $o = $res ? $db->fetch_object($res) : null;
    return $o ? (int) $o->n : 0;
}

/**
 * @brief Orders to invoice
 * @return int
 */
function ksefTourBillableOrders()
{
    global $db;
    if (!isModEnabled('commande')) {
        return 0;
    }
    $res = $db->query("SELECT COUNT(*) AS n FROM " . MAIN_DB_PREFIX . "commande WHERE fk_statut IN (1, 2) AND facture = 0 AND entity IN (" . getEntity('commande') . ")");
    $o = $res ? $db->fetch_object($res) : null;
    return $o ? (int) $o->n : 0;
}

/**
 * @brief Guide stages
 * @param array $tour
 * @return string[]
 */
function ksefTourStages($tour)
{
    $used = array();
    foreach ($tour['steps'] as $s) {
        if (ksefTourModulesOn($s) && !empty($s['stage'])) {
            $used[$s['stage']] = 1;
        }
    }
    return array_values(array_filter(!empty($tour['stages']) ? $tour['stages'] : array(), function ($k) use ($used) {
        return isset($used[$k]);
    }));
}

/**
 * @brief Guide stage count
 * @param array $tour
 * @param string $env unused, kept for callers
 * @return int
 */
function ksefTourStepCount($tour, $env = null)
{
    return count(ksefTourStages($tour));
}

/**
 * @brief May run tour
 * @param array $tour
 * @param User $user
 * @return bool
 */
function ksefTourAllowed($tour, $user)
{
    if (!ksefUserCanReadModule($user)) {
        return false;
    }
    return empty($tour['admin']) || !empty($user->admin);
}

/**
 * @brief Allowed tours, ordered
 * @param User $user
 * @param string $track '' for all
 * @return array
 */
function ksefTourList($user, $track = '')
{
    $out = array();
    foreach (ksefTourDefinitions() as $id => $t) {
        if (($track !== '' && $t['track'] !== $track) || !ksefTourAllowed($t, $user)) {
            continue;
        }
        $out[$id] = array('title' => ksefTourTitle($id, '', true), 'desc' => ksefTourTitle($id, '_Desc', true),
            'track' => $t['track'], 'order' => $t['order'], 'steps' => ksefTourStepCount($t), 'sends' => ksefTourSends($t));
    }
    $rank = array('setup' => 0, 'send' => 1, 'receive' => 2, 'maint' => 3);
    uasort($out, function ($a, $b) use ($rank) {
        return ($rank[$a['track']] * 100 + $a['order']) - ($rank[$b['track']] * 100 + $b['order']);
    });
    return $out;
}

/**
 * @brief Gate requirements
 * @param string $id Tour id
 * @return array Unmet first, as ksefGuideRequirements()
 */
function ksefTourGateItems($id)
{
    global $db, $langs;
    if (!function_exists('ksefGuideRequirements') && is_readable(dirname(__DIR__) . '/lib/ksef_requirements.lib.php')) {
        dol_include_once('/ksef/lib/ksef_requirements.lib.php');
    }
    if (function_exists('ksefGuideRequirements')) {
        $env = ksefGetEnvironment();
        // what to do on the fix page
        $refs = array('offline_cert' => '#env-' . $env . ' form:has(input[name="offline_cert_file"]) input:not([type="hidden"])',
            'auth_saved' => '#env-' . $env . ' form:has(input[name="KSEF_AUTH_TOKEN"]) input.button-save', 'auth' => '');
        $rows = ksefGuideRequirements($db, $id);
        $portals = ksefTourPortals();
        foreach ($rows as $k => $r) {
            if ($r['key'] === 'offline_cert' && empty($r['portal']) && isset($portals[$env])) {
                // where it comes from
                $rows[$k]['portal'] = $portals[$env];
            }
            if (empty($r['fix']['field'])) {
                continue;
            }
            $key = 'KSEF_Tour_GateFix_' . $r['key'];
            $hint = $langs->transnoentitiesnoconv($key);
            $rows[$k]['hint'] = $hint !== $key ? $hint : '';
            $rows[$k]['refs'] = isset($refs[$r['key']]) ? $refs[$r['key']] : '';
        }
        return $rows;
    }
    // stub until product lands
    $out = array();
    foreach (ksefHelpChecklist($db, true) as $k => $it) {
        $out[] = array('key' => $k, 'met' => (bool) $it['done'], 'label' => $langs->transnoentitiesnoconv('KSEF_Help_Item_' . $k), 'detail' => dol_string_nohtmltag((string) $it['detail']), 'fix' => null);
    }
    return $out;
}

/**
 * @brief Tour browser payload
 * @param string $id Tour id
 * @return array|null null when unknown
 */
function ksefTourPayload($id)
{
    global $langs;
    $defs = ksefTourDefinitions();
    if (!isset($defs[$id])) {
        return null;
    }
    $langs->loadLangs(array('ksef@ksef', 'bills', 'banks', 'compta'));
    $pages = ksefTourPages();
    $env = ksefGetEnvironment();
    $stages = ksefTourStages($defs[$id]);
    $steps = array();
    $prodGate = false;
    foreach ($defs[$id]['steps'] as $s) {
        if (!ksefTourModulesOn($s)) {
            continue;
        }
        $gate = null;
        if (!empty($s['gate'])) {
            $gate = array_values(array_filter(ksefTourGateItems($id), function ($g) {
                return empty($g['met']);
            }));
            if (!$gate) {
                continue;
            }
            $fixable = array_filter($gate, function ($g) {
                return !empty($g['fix']);
            });
        }
        $page = $pages[$s['page']];
        // wrong-page address
        $home = '';
        if ($page['url'] !== '') {
            $home = $page['url'] . (!empty($s['query']) ? (strpos($page['url'], '?') === false ? '?' : '&') . $s['query'] : '');
            $home .= !empty($s['hash']) ? '#' . $s['hash'] : '';
        }
        $links = array();
        foreach (!empty($s['link']) ? $s['link'] : array() as $l) {
            if (empty($l['admin']) || !empty($GLOBALS['user']->admin)) {
                $links[] = array('url' => $l['url'], 'label' => $langs->transnoentitiesnoconv($l['label']), 'if' => !empty($l['if']) ? $l['if'] : '',
                    'side' => !empty($l['side']) ? $langs->transnoentitiesnoconv($l['side']) : '', 'frame' => !empty($l['frame']) ? $l['frame'] : '', 'must' => !empty($l['must']) ? 1 : 0,
                    'refs' => !empty($l['refs']) ? $l['refs'] : '',
                    'done' => !empty($l['done']) ? array('sel' => $l['done']['sel'], 'text' => $langs->transnoentitiesnoconv($l['done']['text'])) : null);
            }
        }
        $args = ksefTourArgs($s);
        $suffix = '';
        foreach (!empty($s['suffix']) ? (array) $s['suffix'] : array() as $sk) {
            $suffix .= ' ' . $langs->transnoentitiesnoconv($sk);
        }
        $steps[] = array(
            'key' => $s['key'],
            'page' => $page['re'],
            'url' => !empty($s['go']) ? $home : '',
            'home' => $home,
            'stage' => !empty($s['stage']) ? array_search($s['stage'], $stages, true) : -1,
            'none' => !empty($s['none']) ? ksefTourNone($id, $s['none'], $env) : null,
            'hash' => !empty($s['hash']) ? $s['hash'] : '',
            'sel' => $s['sel'],
            'text' => ksefTourStepText($id, $s) . $suffix . ($gate && !$fixable ? ' ' . $langs->transnoentitiesnoconv('KSEF_Tour_GateNoFix') : ''),
            'alt' => !empty($s['alt']) ? $s['alt'] : '',
            'span' => !empty($s['span']) ? $s['span'] : '',
            'refs' => !empty($s['refs']) ? $s['refs'] : '',
            'when' => !empty($s['when']) ? $s['when'] : null,
            'dialog' => !empty($s['dialog']),
            'optional' => !empty($s['optional']),
            'wait' => !empty($s['wait']),
            'send' => !empty($s['send']),
            'path' => !empty($s['path']) ? $s['path'] : '',
            'then' => !empty($s['then']) ? $s['then'] : '',
            'start' => !empty($s['start']) && ksefTourDoable($s['start']['tour'], $GLOBALS['user']) ? $s['start'] : null,
            'errto' => !empty($s['errto']) ? $s['errto'] : null,
            'tick' => !empty($s['tick']) ? $langs->transnoentitiesnoconv($s['tick']) : '',
            'info' => !empty($s['info']),
            'rows' => !empty($s['rows']),
            'addrow' => !empty($s['addrow']),
            'submits' => !empty($s['submits']),
            'vary' => !empty($s['vary']) ? ksefTourVary($s['vary'], $args, $suffix) : null,
            'need' => !empty($s['need']) ? $s['need'] : '',
            'links' => !empty($s['portal']) ? ksefTourPortals() : null,
            'prefer' => !empty($s['prefer']) ? $s['prefer'] : '',
            'resel' => !empty($s['resel']) ? $s['resel'] : '',
            'lastif' => !empty($s['lastif']) ? $s['lastif'] : '',
            'back' => !empty($s['back']) ? $s['back'] : '',
            'bind' => in_array($s['page'], array('inv_card', 'inv_confirm', 'inv_sent', 'tab_ksef'), true),
            'picks' => !empty($s['picks']) ? $s['picks'] : '',
            'noring' => !empty($s['noring']),
            'reloads' => !empty($s['reloads']),
            'prefclick' => !empty($s['prefclick']),
            'one' => !empty($s['one']),
            'gate' => $gate,
            'side' => !empty($s['side']) ? array('page' => $s['side']['page'], 'sel' => $s['side']['sel'],
                'text' => $langs->transnoentitiesnoconv($s['side']['text'], !empty($s['side']['args']) ? $s['side']['args'][0] : ''),
                'refs' => !empty($s['side']['refs']) ? $s['side']['refs'] : '') : null,
            'end' => !empty($s['end']) && ksefTourDoable($s['end']['tour'], $GLOBALS['user'])
                ? array('id' => $s['end']['tour'], 'step' => $s['end']['step'], 'title' => ksefTourTitle($s['end']['tour'])) : null,
            'next' => !empty($s['next']) && ksefTourDoable($s['next']['tour'], $GLOBALS['user'])
                ? array('id' => $s['next']['tour'], 'sel' => $s['next']['sel'], 'title' => ksefTourTitle($s['next']['tour']), 'step' => !empty($s['next']['step']) ? $s['next']['step'] : '',
                    'label' => !empty($s['next']['label']) ? $langs->transnoentitiesnoconv($s['next']['label']) : '') : null,
            'link' => $links,
            'where' => !empty($s['where']) ? $langs->transnoentitiesnoconv($s['where']) : ($s['page'] !== 'any' ? $langs->transnoentitiesnoconv('KSEF_TourPage_' . $s['page']) : ''),
        );
        if (!empty($s['choices'])) {
            $ways = array();
            foreach ($s['choices'] as $path) {
                $ways[] = array('path' => $path, 'label' => $langs->transnoentitiesnoconv('KSEF_Tour_' . $id . '_way_' . $path . (isset($s['suffixes'][$path]) ? $s['suffixes'][$path] : '')));
            }
            $steps[count($steps) - 1]['choices'] = $ways;
            unset($ways);
        }
        $prodGate = $prodGate || !empty($s['send']);
    }
    $note = '';
    if ($prodGate && $env === 'PRODUCTION') {
        array_unshift($steps, ksefTourEnvStep($env));
    } elseif ($prodGate) {
        $note = $langs->transnoentitiesnoconv('KSEF_TourUi_EnvText', $langs->transnoentitiesnoconv('KSEF_ENV_' . $env));
    }
    $next = null;
    if (!empty($defs[$id]['next']) && ksefTourDoable($defs[$id]['next'], $GLOBALS['user'])) {
        $next = array('id' => $defs[$id]['next'], 'title' => ksefTourTitle($defs[$id]['next']));
    } elseif ($defs[$id]['track'] === 'setup') {
        foreach (ksefTourList($GLOBALS['user'], 'setup') as $nid => $t) {
            if ($t['order'] > $defs[$id]['order']) {
                $next = array('id' => $nid, 'title' => ksefTourTitle($nid));
                break;
            }
        }
    }
    $labels = array();
    foreach ($stages as $k) {
        $labels[] = $langs->transnoentitiesnoconv('KSEF_Tour_Stage_' . $k);
    }
    return array('id' => $id, 'track' => $defs[$id]['track'], 'title' => ksefTourTitle($id), 'prodGate' => $prodGate,
        'env' => $env, 'envNote' => $note, 'next' => $next, 'count' => count($stages), 'stages' => $labels, 'stageKeys' => $stages, 'steps' => $steps);
}

/**
 * @brief Nothing to pick
 * @param string $id Tour id
 * @param array $none text, tour, way, live, end, unless
 * @param string $env
 * @return array
 */
function ksefTourNone($id, $none, $env)
{
    global $langs, $user;
    $tours = array();
    foreach (!empty($none['tour']) ? (array) $none['tour'] : array() as $t) {
        if (ksefTourDoable($t, $user)) {
            $tours[] = array('id' => $t, 'title' => ksefTourTitle($t));
        }
    }
    $way = !empty($none['way']) ? array('path' => $none['way'], 'label' => $langs->transnoentitiesnoconv('KSEF_Tour_' . $id . '_way_' . $none['way'])) : null;
    return array('text' => $langs->transnoentitiesnoconv($none['text']), 'tour' => $tours ? $tours[0] : null, 'tours' => $tours, 'way' => $way, 'end' => !empty($none['end']),
        'after' => !empty($none['after']) ? $langs->transnoentitiesnoconv($none['after']) : '',
        'unless' => !empty($none['unless']) ? $none['unless'] : '',
        'live' => empty($none['live']) ? '' : ($env === 'TEST' ? ($none['live'] === 'received' || $none['text'] === 'KSEF_Tour_None_Sales' ? $langs->transnoentitiesnoconv('KSEF_TourUi_NoneTestNip') : '')
            : $langs->transnoentitiesnoconv($none['live'] === 'received' ? 'KSEF_TourUi_NoneLiveReceived' : 'KSEF_TourUi_NoneLiveSent', $langs->transnoentitiesnoconv('KSEF_ENV_' . $env))));
}

/**
 * @brief Page-dependent texts
 * @param array $vary condition => lang key or array(key, 'fresh'), first match wins
 * @param array $args Text arguments
 * @param string $suffix Appended text
 * @return array
 */
function ksefTourVary($vary, $args = array('', '', ''), $suffix = '')
{
    global $langs;
    $out = array();
    foreach ($vary as $sel => $key) {
        $out[] = array('sel' => $sel, 'text' => $langs->transnoentitiesnoconv(is_array($key) ? $key[0] : $key, $args[0], $args[1], $args[2]) . $suffix,
            'fresh' => is_array($key) && in_array('fresh', $key, true));
    }
    return $out;
}

/**
 * @brief Step text arguments
 * @param array $s Step
 * @return array
 */
function ksefTourArgs($s)
{
    global $langs;
    $args = array('', '', '');
    foreach (!empty($s['args']) ? array_values($s['args']) : array() as $k => $key) {
        // core 22 label
        $key = $key === 'DefaultBankAccount' && version_compare(DOL_VERSION, '23.0', '<') ? 'BankAccount' : $key;
        // core labels
        $args[$k] = $key === 'Deposit' && strpos((string) $langs->defaultlang, 'pl') === 0 ? $langs->transnoentitiesnoconv('KSEF_CoreInvoiceDeposit') : $langs->transnoentitiesnoconv($key);
    }
    return $args;
}

/**
 * @brief Step modules enabled
 * @param array $s Step
 * @return bool
 */
function ksefTourModulesOn($s)
{
    foreach (!empty($s['module']) ? (array) $s['module'] : array() as $m) {
        if (!isModEnabled($m)) {
            return false;
        }
    }
    return true;
}

/**
 * @brief Sandbox only tours
 * @return bool
 */
function ksefTourSandbox()
{
    return defined('KSEF_ALLOW_TEST_OVERRIDE') && constant('KSEF_ALLOW_TEST_OVERRIDE') && getDolGlobalString('KSEF_TEST_URL_KSEF') !== '';
}

/**
 * @brief Tour title
 * @param string $id Tour id
 * @param string $suffix '' or '_Desc'
 * @param bool $html HTML-escaped
 * @return string
 */
function ksefTourTitle($id, $suffix = '', $html = false)
{
    global $langs;
    $defs = ksefTourDefinitions();
    $key = 'KSEF_Tour_' . (!empty($defs[$id]['name']) ? $defs[$id]['name'] : $id) . $suffix;
    return $html ? $langs->trans($key) : $langs->transnoentitiesnoconv($key);
}

/**
 * @brief MF taxpayer apps
 * @return array env => URL
 */
function ksefTourPortals()
{
    return array('TEST' => 'https://ap-test.ksef.mf.gov.pl/web/', 'DEMO' => 'https://ap-demo.ksef.mf.gov.pl/web/', 'PRODUCTION' => 'https://ap.ksef.mf.gov.pl/web/');
}

/**
 * @brief Step text
 * @param string $id Tour id
 * @param array $s Step
 * @return string
 */
function ksefTourStepText($id, $s)
{
    global $langs, $db;
    $args = ksefTourArgs($s);
    if ($id === 'send_zal' && $s['key'] === 'amount') {
        // core option names
        $langs->load('bills');
        $dep = ksefTourArgs(array('args' => array('Deposit')))[0];
        $args = array($langs->transnoentitiesnoconv('FixAmount', $dep), $langs->transnoentitiesnoconv('VarAmountOneLine', $dep), $langs->transnoentitiesnoconv('VarAmountAllLines'));
    }
    $text = $langs->transnoentitiesnoconv(!empty($s['text']) ? $s['text'] : 'KSEF_Tour_' . $id . '_' . $s['key'], $args[0], $args[1], $args[2]);
    if (!empty($s['unitsend']) && getDolGlobalInt('PRODUCT_USE_UNITS')) {
        $text .= ' ' . $langs->transnoentitiesnoconv(getDolGlobalInt('KSEF_FA3_INCLUDE_UNIT') ? 'KSEF_Tour_AddrowUnit' : 'KSEF_Tour_AddrowUnit_nosend');
    }
    return $text;
}

/**
 * @brief Production warning step
 * @param string $env
 * @return array
 */
function ksefTourEnvStep($env)
{
    global $langs;
    return array('key' => 'env', 'page' => '.', 'url' => '', 'hash' => '', 'sel' => '', 'warn' => $env,
        'head' => $langs->transnoentitiesnoconv('KSEF_TourUi_EnvProdHead'),
        'text' => $langs->transnoentitiesnoconv('KSEF_TourUi_EnvProdText', $langs->transnoentitiesnoconv('KSEF_ENV_' . $env)),
        'agree' => $langs->transnoentitiesnoconv('KSEF_TourUi_EnvProdTick'),
        'optional' => false, 'wait' => false, 'send' => false, 'path' => '', 'then' => '', 'tick' => '', 'refs' => '', 'info' => true, 'rows' => false, 'when' => null, 'where' => '',
        'alt' => '', 'span' => '', 'dialog' => false, 'stage' => -1);
}

/**
 * @brief Seller address HTML
 * @param Societe $mysoc
 * @return string HTML
 */
function ksefHelpCompanyAddressHtml($mysoc)
{
    global $langs;
    $parts = array();
    foreach (array($mysoc->name, $mysoc->address, trim($mysoc->zip . ' ' . $mysoc->town), $mysoc->country_code) as $p) {
        if (trim((string) $p) !== '') {
            $parts[] = dol_escape_htmltag(trim((string) $p));
        }
    }
    if (!ksefHelpCompanyAddressComplete($mysoc)) {
        return '<span class="warning">' . $langs->trans('KSEF_SellerAddressMissing') . '</span>' . ($parts ? ' <span class="opacitymedium">(' . implode(', ', $parts) . ')</span>' : '');
    }
    return implode(', ', $parts);
}

/**
 * @brief Seller address complete
 * @param Societe $mysoc
 * @return bool
 */
function ksefHelpCompanyAddressComplete($mysoc)
{
    foreach (array('name', 'address', 'zip', 'town', 'country_code') as $f) {
        if (trim((string) $mysoc->$f) === '') {
            return false;
        }
    }
    return true;
}

/**
 * @brief Setup checklist
 * @param DoliDB $db
 * @param bool $requiredOnly Required items only, no SQL
 * @return array
 */
function ksefHelpChecklist($db, $requiredOnly = false)
{
    global $langs, $mysoc, $conf;
    $langs->load('ksef@ksef');
    $env = ksefGetEnvironment();
    $items = array();

    if (!function_exists('ksefRequirementRows')) {
        dol_include_once('/ksef/lib/ksef_requirements.lib.php');
    }
    // one source: requirements
    $co = ksefRequirementRows($db, array('company_name', 'company_nip', 'company_address', 'main_currency'));
    $miss = array_values(array_filter($co, function ($r) {
        return !$r['met'];
    }));
    $items['company'] = array('done' => !$miss, 'tour' => 'setup_company',
        'detail' => $miss ? dol_escape_htmltag($miss[0]['detail'] !== '' ? $miss[0]['detail'] : $miss[0]['label']) : '');

    $auth = ksefAuthReadiness($env);
    $items['env'] = array('done' => $env !== 'DEMO' || $auth['ready'], 'tour' => 'setup_env',
        'detail' => $langs->trans('KSEF_Help_Detail_Env', $langs->transnoentitiesnoconv('KSEF_ENV_' . $env))
            . ($env === 'DEMO' && !$auth['ready'] ? ' ' . $langs->trans('KSEF_Help_Detail_EnvDemoAuth') : ''));

    // done after a passed test
    $ar = ksefRequirementRows($db, array('auth'));
    $items['auth'] = array('done' => !empty($ar[0]['met']), 'tour' => 'setup_auth',
        'detail' => !empty($ar[0]['met']) ? $langs->trans('KSEF_Help_Detail_AuthTestOk') : dol_escape_htmltag((string) $ar[0]['detail']));
    if ($requiredOnly) {
        foreach ($items as $k => $it) {
            $items[$k]['optional'] = false;
        }
        return $items;
    }

    $sent = $db->query("SELECT s.environment FROM " . MAIN_DB_PREFIX . "ksef_submissions AS s INNER JOIN " . MAIN_DB_PREFIX . "facture AS f ON f.rowid = s.fk_facture"
        . " WHERE f.entity IN (" . getEntity('invoice') . ") AND s.status = 'ACCEPTED' AND s.upo_xml IS NOT NULL AND s.upo_xml <> '' ORDER BY s.environment = 'TEST' DESC LIMIT 1");
    $sentEnv = ($sent && ($o = $db->fetch_object($sent))) ? (string) $o->environment : '';
    $items['firstsend'] = array('done' => $sentEnv !== '', 'optional' => 1, 'tour' => 'send_vat',
        'detail' => $sentEnv !== '' ? $langs->trans('KSEF_Help_Detail_Sent', $langs->transnoentitiesnoconv('KSEF_ENV_' . $sentEnv)) : $langs->trans('KSEF_Help_Detail_NotSent'));

    $active = array();
    $off = array();
    $sql = "SELECT t.code, t.active FROM " . MAIN_DB_PREFIX . "c_tva as t INNER JOIN " . MAIN_DB_PREFIX . "c_country as c ON c.rowid = t.fk_pays"
        . " WHERE c.code = 'PL' AND t.entity IN (0, " . ((int) $conf->entity) . ") AND t.code IN ('ZW', 'RC', 'NP', 'NP2', 'WDT', 'EX', 'MARZA2', 'MARZA31', 'MARZA32', 'MARZA33')";
    $res = $db->query($sql);
    while ($res && ($o = $db->fetch_object($res))) {
        if ((int) $o->active) {
            $active[$o->code] = $o->code;
        } else {
            $off[$o->code] = $o->code;
        }
    }
    $off = array_diff_key($off, $active);
    $vatNone = !$active && getDolGlobalInt('KSEF_VAT_CODES_NONE') > 0;
    $items['vat'] = array('done' => !empty($active) || $vatNone, 'optional' => 1, 'tour' => 'setup_vat', 'none' => $active ? null : $vatNone,
        'detail' => $active ? $langs->trans('KSEF_Help_Detail_VatActive', implode(', ', $active)) . ($off ? '; ' . $langs->trans('KSEF_Help_Detail_VatOff', implode(', ', $off)) : '')
            : $langs->trans($vatNone ? 'KSEF_Help_Detail_VatMarkedNone' : 'KSEF_Help_Detail_VatNone'));

    $method = getDolGlobalInt('KSEF_DEFAULT_PAYMENT_METHOD_ID') > 0;
    $bank = !isModEnabled('bank') || getDolGlobalInt('KSEF_DEFAULT_BANK_ACCOUNT_ID') > 0;
    $items['defaults'] = array('done' => $method && $bank, 'optional' => 1, 'tour' => 'setup_defaults',
        'detail' => (!$method ? $langs->trans('KSEF_Help_Detail_NoMethod') . ' ' : '') . (!$bank ? $langs->trans('KSEF_Help_Detail_NoBank') : ''));

    $jobs = ksefGetCronJobs($db);
    $engine = ksefCronEngineProblem($db, $jobs);
    $incomingOn = !empty($jobs['cronSyncIncoming']['status']);
    $items['incoming'] = array('done' => $engine !== 'off' && $incomingOn, 'optional' => 1, 'tour' => 'setup_incoming',
        'detail' => $engine === 'off' ? $langs->trans('KSEF_CronEngineOff') : (!$incomingOn ? $langs->trans('KSEF_Help_Detail_IncomingOff') : ''));

    $nbp = !isModEnabled('multicurrency') ? null : !empty($jobs['cronSyncMulticurrencyRates']['status']);
    $items['nbp'] = array('done' => $nbp, 'optional' => 1, 'tour' => 'setup_optional', 'step' => 'nbp',
        'detail' => $nbp === null ? $langs->trans('KSEF_Help_Detail_NoMulticurrency') : '');
    dol_include_once('/ksef/lib/ksef_chart.lib.php');
    $settle = ksefSettleChecklistState($db);
    $items['settle'] = array('done' => $settle['done'], 'optional' => 1, 'tour' => 'setup_optional', 'step' => 'settle', 'detail' => $settle['detail']);
    $items['gus'] = array('done' => getDolGlobalInt('KSEF_GUS_ENABLED') > 0, 'optional' => 1, 'tour' => 'setup_optional', 'step' => 'gus', 'detail' => '');
    $items['rights'] = array('done' => ksefHelpNonAdminReaders($db) > 0, 'optional' => 1, 'tour' => 'setup_optional', 'step' => 'rights', 'detail' => '');

    foreach ($items as $k => $it) {
        $items[$k]['optional'] = !empty($it['optional']);
    }
    return $items;
}

/**
 * @brief Non-admin readers
 * @param DoliDB $db
 * @return int
 */
function ksefHelpNonAdminReaders($db)
{
    global $conf;
    $P = MAIN_DB_PREFIX;
    $E = (int) $conf->entity;
    $sql = "SELECT COUNT(DISTINCT u.rowid) as n FROM " . $P . "user as u WHERE u.admin = 0 AND u.statut = 1 AND u.entity IN (" . getEntity('user') . ") AND u.rowid IN ("
        . "SELECT ur.fk_user FROM " . $P . "user_rights as ur INNER JOIN " . $P . "rights_def as rd ON rd.id = ur.fk_id WHERE rd.module = 'ksef' AND ur.entity = " . $E
        . " UNION SELECT gu.fk_user FROM " . $P . "usergroup_user as gu INNER JOIN " . $P . "usergroup_rights as gr ON gr.fk_usergroup = gu.fk_usergroup"
        . " INNER JOIN " . $P . "rights_def as rd2 ON rd2.id = gr.fk_id WHERE rd2.module = 'ksef' AND gr.entity = " . $E . ")";
    $res = $db->query($sql);
    return ($res && ($o = $db->fetch_object($res))) ? (int) $o->n : 0;
}

/**
 * @brief Required items progress
 * @param array $items Checklist
 * @return array
 */
function ksefHelpProgress($items)
{
    $done = 0;
    $total = 0;
    foreach ($items as $it) {
        if ($it['optional']) {
            continue;
        }
        $total++;
        $done += $it['done'] ? 1 : 0;
    }
    return array('done' => $done, 'total' => $total);
}

/**
 * @brief Tour start link
 * @param string $id Tour id
 * @param string $label HTML-safe, '' default
 * @param string $step Start step key, '' first
 * @return string
 */
function ksefTourButton($id, $label = '', $step = '')
{
    global $langs;
    return '<a href="#" class="ksef-tour-start" data-ksef-tour="' . dol_escape_htmltag($id) . '"' . ($step !== '' ? ' data-ksef-tour-step="' . dol_escape_htmltag($step) . '"' : '') . '>'
        . img_picto('', 'fa-route', 'class="pictofixedwidth"') . ($label !== '' ? $label : $langs->trans('KSEF_Tour_Guide')) . '</a>';
}

/**
 * @brief Section guide button
 * @param User $user
 * @param string $section Section key
 * @param string $titleKey Title lang key
 * @param array $ids Tour ids
 * @param bool $doable Only tours the user can finish
 * @return array
 */
function ksefTourGuideHtml($user, $section, $titleKey, $ids, $doable = false)
{
    global $langs;
    $defs = ksefTourDefinitions();
    $ok = array();
    $sends = false;
    foreach ($ids as $id) {
        if (isset($defs[$id]) && ksefTourAllowed($defs[$id], $user) && (!$doable || ksefTourDoable($id, $user))) {
            $ok[] = $id;
            $sends = $sends || ksefTourSends($defs[$id]);
        }
    }
    if (!$ok) {
        return array('button' => '', 'dialog' => '', 'badge' => '');
    }
    $link = img_picto('', 'fa-route', 'class="pictofixedwidth"');
    $badge = $sends ? ksefTourSendBadge() : '';
    if (count($ok) === 1) {
        $name = ksefTourTitle($ok[0]);
        return array('dialog' => '', 'badge' => $badge,
            'button' => '<a href="#" class="ksef-howto-guide ksef-tour-start" data-ksef-tour="' . dol_escape_htmltag($ok[0])
                . '" title="' . dol_escape_htmltag($name) . '">' . $link . $langs->trans('KSEF_Tour_Guide') . '</a>');
    }
    $did = 'ksef-guides-' . $section;
    $button = '<a href="#" class="ksef-howto-guide" data-ksef-guides="' . dol_escape_htmltag($did) . '" aria-haspopup="dialog">' . $link . $langs->trans('KSEF_Tour_GuidesN', count($ok)) . '</a>';
    $dialog = '<div id="' . dol_escape_htmltag($did) . '" class="ksef-guide-chooser" style="display: none;" title="' . dol_escape_htmltag($langs->transnoentitiesnoconv('KSEF_Tour_Choose') . ': ' . $langs->transnoentitiesnoconv($titleKey)) . '"'
        . ' data-cancel="' . dol_escape_htmltag($langs->transnoentitiesnoconv('Cancel')) . '">'
        . '<p class="opacitymedium small">' . ksefTourIntroHtml($sends) . '</p><table class="noborder centpercent ksef-guide-list">' . ksefTourRowsHtml($ok);
    return array('button' => $button, 'badge' => $badge, 'dialog' => $dialog . '</table></div>');
}

/**
 * @brief Missing requirement badge
 * @param string $id Tour id
 * @return string
 */
function ksefTourNeedsBadge($id)
{
    global $db, $langs;
    if ($id !== 'send_offline' || !function_exists('ksefRequirementRows')) {
        return '';
    }
    $rows = ksefRequirementRows($db, array('offline_cert'));
    if (!$rows || !empty($rows[0]['met'])) {
        return '';
    }
    return ' <span class="badge badge-status8 ksef-guide-needs" data-ksef-req="offline_cert">' . dol_escape_htmltag($langs->transnoentitiesnoconv('KSEF_Tour_GuideNeedsOfflineCert')) . '</span>';
}

/**
 * @brief Chooser intro text
 * @param bool $sends A tour sends
 * @return string
 */
function ksefTourIntroHtml($sends)
{
    global $langs;
    return $langs->trans('KSEF_Tour_ChooseIntro') . ($sends ? ' <span class="ksef-guide-sendsnote">' . dol_escape_htmltag(ksefTourSendBadgeParts()['full']) . '.</span>' : '');
}

/**
 * @brief Chooser tour rows
 * @param array $ids Tour ids
 * @return string
 */
function ksefTourRowsHtml($ids)
{
    $defs = ksefTourDefinitions();
    $out = '';
    foreach ($ids as $id) {
        $out .= '<tr class="oddeven" data-tour="' . dol_escape_htmltag($id) . '"><td><a href="#" class="ksef-guide-choice ksef-tour-start" data-ksef-tour="' . dol_escape_htmltag($id) . '">'
            . '<span class="fas fa-route paddingright"></span><strong>' . ksefTourTitle($id, '', true) . '</strong></a>' . (ksefTourSends($defs[$id]) ? ' ' . ksefTourSendBadge() : '') . ksefTourNeedsBadge($id)
            . '<br><span class="opacitymedium small">' . ksefTourTitle($id, '_Desc', true)
            . ' (' . ksefTourStepsText(ksefTourStepCount($defs[$id])) . ')</span></td></tr>';
    }
    return $out;
}

/**
 * @brief Can finish tour
 * @param string $id Tour id
 * @param User $user
 * @return bool
 */
function ksefTourDoable($id, $user)
{
    $defs = ksefTourDefinitions();
    if (!isset($defs[$id]) || !ksefTourAllowed($defs[$id], $user)) {
        return false;
    }
    if (ksefTourSends($defs[$id]) || in_array($id, array('send_sales'), true)) {
        return ksefUserCan($user, 'send');
    }
    if (in_array($id, array('recv_import', 'recv_kor', 'recv_deposits'), true)) {
        return ksefUserCan($user, 'import_incoming');
    }
    return true;
}

/**
 * @brief Title help button
 * @param User $user
 * @param string $key Page key
 * @param array $sections How to use section => title lang key
 * @param array $ids Tour ids
 * @return string
 */
function ksefHelpTitleButton($user, $key, $sections, $ids)
{
    global $langs;
    $ok = array();
    $sends = false;
    $defs = ksefTourDefinitions();
    foreach ($ids as $id) {
        if (ksefTourDoable($id, $user)) {
            $ok[] = $id;
            $sends = $sends || ksefTourSends($defs[$id]);
        }
    }
    $did = 'ksef-help-' . $key;
    $title = $langs->transnoentitiesnoconv('KSEF_Help_Title');
    $out = dolGetButtonTitle($langs->trans('KSEF_Help_Title'), $langs->transnoentitiesnoconv($ok ? 'KSEF_Help_TitleTip' : 'KSEF_HowToUse'), 'fa fa-question-circle', '#', '', 1,
        array('attr' => array('data-ksef-guides' => $did, 'aria-haspopup' => 'dialog', 'class' => 'ksef-help-title'), 'forcenohideoftext' => 1));
    $out .= '<div id="' . dol_escape_htmltag($did) . '" class="ksef-guide-chooser ksef-help-chooser" style="display: none;" title="' . dol_escape_htmltag($title) . '"'
        . ' data-cancel="' . dol_escape_htmltag($langs->transnoentitiesnoconv('Cancel')) . '"><table class="noborder centpercent ksef-guide-list">'
        . '<tr class="liste_titre"><th><span class="fas fa-book paddingright"></span>' . $langs->trans('KSEF_HowToUse') . '</th></tr>';
    foreach ($sections as $section => $titleKey) {
        $out .= '<tr class="oddeven" data-section="' . dol_escape_htmltag($section) . '"><td><a href="' . dol_escape_htmltag(ksefHelpUrl($section)) . '">'
            . '<span class="fas fa-book-open paddingright"></span>' . $langs->trans($titleKey) . '</a></td></tr>';
    }
    if ($ok) {
        $out .= '<tr class="liste_titre"><th><span class="fas fa-route paddingright"></span>' . $langs->trans('KSEF_Tour_Guides')
            . '<br><span class="opacitymedium small nobold">' . ksefTourIntroHtml($sends) . '</span></th></tr>' . ksefTourRowsHtml($ok);
    }
    return $out . '</table></div>';
}

/**
 * @brief Help section URL
 * @param string $section Section key
 * @return string
 */
function ksefHelpUrl($section)
{
    return dol_buildpath('/ksef/admin/howtouse.php', 2) . '#ksef-howto-sec-' . $section;
}

/**
 * @brief Tour sends
 * @param array $tour
 * @return bool
 */
function ksefTourSends($tour)
{
    foreach ($tour['steps'] as $s) {
        if (!empty($s['send'])) {
            return true;
        }
    }
    return false;
}

/**
 * @brief Send badge parts
 * @return array
 */
function ksefTourSendBadgeParts()
{
    global $langs;
    $prod = ksefGetEnvironment() === 'PRODUCTION';
    $full = $langs->transnoentitiesnoconv($prod ? 'KSEF_Tour_SendsProd' : 'KSEF_Tour_SendsFull');
    return array('label' => $langs->transnoentitiesnoconv('KSEF_Tour_Sends'), 'full' => $full,
        'tip' => $prod ? $langs->transnoentitiesnoconv('KSEF_Tour_SendsProdTip') : $full,
        'css' => ksefToneBadgeClass($prod ? 'problem' : 'check') . ' ksef-tour-sends');
}

/**
 * @brief Send badge
 * @param bool $short Short label
 * @return string
 */
function ksefTourSendBadge($short = true)
{
    $b = ksefTourSendBadgeParts();
    $label = $short ? $b['label'] : $b['full'];
    return dolGetBadge($label, dol_escape_htmltag($label), '', '', '', array('css' => $b['css'], 'attr' => array('title' => $b['tip'])));
}

/**
 * @brief Checklist table
 * @param DoliDB $db
 * @param User $user
 * @return string
 */
function ksefHelpChecklistHtml($db, $user)
{
    global $langs;
    $items = ksefHelpChecklist($db);
    $p = ksefHelpProgress($items);
    $out = '<table class="noborder centpercent ksef-help-checklist">';
    $out .= '<tr class="liste_titre"><th colspan="3">' . $langs->trans('KSEF_Help_Checklist') . ' <span class="badge marginleftonlyshort ksef-help-count">' . $langs->trans('KSEF_Help_RequiredCount', $p['done'], $p['total']) . '</span></th></tr>';
    $defs = ksefTourDefinitions();
    $optHead = false;
    foreach ($items as $key => $it) {
        if ($it['optional'] && !$optHead) {
            $optHead = true;
            $out .= '<tr class="liste_titre ksef-help-optional-head"><td colspan="3">' . $langs->trans('KSEF_Help_OptionalGroup')
                . ' <span class="opacitymedium small">' . $langs->trans('KSEF_Help_OptionalGroupHint') . '</span></td></tr>';
        }
        if ($it['done'] === null) {
            $icon = '<span class="fas fa-minus-circle opacitymedium" title="' . dol_escape_htmltag($langs->trans('KSEF_Help_NotApplicable')) . '"></span>';
        } elseif ($it['done']) {
            $icon = '<span class="fas fa-check-circle ' . ksefToneTextClass('ok') . '" title="' . dol_escape_htmltag($langs->trans('KSEF_Help_Done')) . '"></span>';
        } else {
            $icon = '<span class="far fa-circle opacitymedium" title="' . dol_escape_htmltag($langs->trans('KSEF_Help_Todo')) . '"></span>';
        }
        $out .= '<tr class="oddeven ksef-help-item" data-item="' . dol_escape_htmltag($key) . '" data-done="' . ($it['done'] ? '1' : '0') . '" data-optional="' . ($it['optional'] ? '1' : '0') . '">';
        $out .= '<td class="center" style="width: 24px;">' . $icon . '</td>';
        $out .= '<td>' . $langs->trans('KSEF_Help_Item_' . $key);
        if ($it['detail'] !== '') {
            $out .= '<br><span class="opacitymedium small">' . $it['detail'] . '</span>';
        }
        $out .= '</td><td class="right nowraponall">';
        $step = !empty($it['step']) ? $it['step'] : '';
        $shown = true;
        foreach ($step !== '' ? $defs[$it['tour']]['steps'] : array() as $s) {
            $shown = $s['key'] === $step ? ksefTourModulesOn($s) : $shown;
        }
        if ($shown && ksefTourAllowed($defs[$it['tour']], $user)) {
            $out .= (ksefTourSends($defs[$it['tour']]) ? ksefTourSendBadge() . ' ' : '') . ksefTourButton($it['tour'], '', $step);
            if (isset($it['none'])) {
                $back = ksefRequirementBackUrl();
                $out .= '<br><a class="ksef-help-none" href="' . dol_escape_htmltag(dol_buildpath('/ksef/ksef_fix.php', 1) . '?action=vatnone&undo=' . ($it['none'] ? 1 : 0) . '&token=' . newToken()
                    . ($back !== '' ? '&backtopage=' . urlencode($back) : '')) . '">' . $langs->trans($it['none'] ? 'KSEF_Help_VatNoneUndo' : 'KSEF_Help_VatNoneSet') . '</a>';
            }
        } elseif ($shown) {
            $out .= '<span class="opacitymedium small">' . $langs->trans('KSEF_Help_AdminOnly') . '</span>';
        }
        $out .= '</td></tr>';
    }
    $out .= '</table>';
    return $out;
}

/**
 * @brief Stage count text
 * @param int $n
 * @return string
 */
function ksefTourStepsText($n)
{
    global $langs;
    $n = (int) $n;
    $key = 'KSEF_Tour_Stages';
    if ($n === 1) {
        $key = 'KSEF_Tour_Stages1';
    } elseif ($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 12 || $n % 100 > 14)) {
        $key = 'KSEF_Tour_StagesFew';
    }
    return $langs->trans($key, $n);
}

/**
 * @brief Dashboard tour picker
 * @param User $user
 * @return array
 */
function ksefTourPickerHtml($user)
{
    global $langs;
    $out = '<tr class="oddeven"><td colspan="2" class="opacitymedium small">' . $langs->trans('KSEF_Tour_PickerIntro')
        . ' <a href="' . dol_buildpath('/ksef/admin/howtouse.php', 1) . '">' . $langs->trans('KSEF_Tour_PickerMore') . '</a></td></tr>';
    $dialogs = '';
    foreach (array('setup', 'send', 'receive') as $track) {
        $list = ksefTourList($user, $track);
        if (!$list) {
            continue;
        }
        $g = ksefTourGuideHtml($user, 'track-' . $track, 'KSEF_Tour_Track_' . $track, array_keys($list));
        $dialogs .= $g['dialog'];
        $out .= '<tr class="oddeven ksef-tour-track" data-track="' . $track . '"><td>' . $langs->trans('KSEF_Tour_Track_' . $track) . ($g['badge'] !== '' ? ' ' . $g['badge'] : '')
            . '<br><span class="opacitymedium small">' . $langs->trans('KSEF_Tour_TrackDesc_' . $track) . '</span></td>'
            . '<td class="right nowraponall">' . str_replace('ksef-howto-guide', 'ksef-track-guide', $g['button']) . '</td></tr>';
    }
    return array('rows' => $out, 'dialogs' => $dialogs);
}

/**
 * @brief Dashboard help box
 * @param DoliDB $db
 * @param User $user
 * @return string
 */
function ksefHelpBoxHtml($db, $user)
{
    global $langs;
    $out = '<div class="ksef-help-box" id="ksef-help">';
    $out .= '<table class="noborder centpercent"><tr class="liste_titre"><th><span class="fas fa-life-ring paddingright"></span>' . $langs->trans('KSEF_Help_Title') . '</th></tr></table>';
    $out .= '<div class="ksef-help-cols"><div class="ksef-help-col">' . ksefHelpChecklistHtml($db, $user) . '</div>';
    $picker = ksefTourPickerHtml($user);
    $out .= '<div class="ksef-help-col"><table class="noborder centpercent ksef-tour-picker"><tr class="liste_titre"><th colspan="2">' . $langs->trans('KSEF_Tour_Title') . '</th></tr>'
        . $picker['rows'] . '</table></div></div>' . $picker['dialogs'];
    return $out . '</div>';
}

/**
 * @brief Setup help banner
 * @param DoliDB $db
 * @param User $user
 * @return string '' when hidden
 */
function ksefHelpBannerHtml($db, $user)
{
    global $langs;
    if (getDolUserInt(KSEF_HELP_BANNER_PARAM, 0, $user)) {
        return '';
    }
    $p = ksefHelpProgress(ksefHelpChecklist($db, true));
    if ($p['done'] === $p['total']) {
        return '';
    }
    $back = $_SERVER['REQUEST_URI'] ?? '';
    $out = '<div class="info ksef-help-banner" id="ksef-help-banner">';
    $out .= '<span class="fas fa-life-ring paddingright"></span>' . $langs->trans('KSEF_Help_Banner', $p['done'], $p['total']) . ' ';
    $out .= '<a href="' . dol_buildpath('/ksef/ksefindex.php', 1) . '#ksef-help">' . $langs->trans('KSEF_Help_BannerLink') . '</a>';
    $out .= '<form method="POST" action="' . dol_buildpath('/ksef/ksef_help.php', 1) . '" class="ksef-help-banner-form">';
    $out .= '<input type="hidden" name="token" value="' . newToken() . '">';
    $out .= '<input type="hidden" name="action" value="dismiss">';
    $out .= '<input type="hidden" name="backtopage" value="' . dol_escape_htmltag($back) . '">';
    $out .= '<button type="submit" class="button smallpaddingimp">' . $langs->trans('KSEF_Help_Dismiss') . '</button>';
    $out .= '</form></div>';
    return $out;
}

/**
 * @brief Tour script footer
 * @param DoliDB $db
 * @param User $user
 * @param bool $banner
 * @return string
 */
function ksefHelpFooter($db, $user, $banner = true)
{
    static $printed = false;
    if ($printed || !isModEnabled('ksef') || !ksefUserCanReadModule($user)) {
        return '';
    }
    $printed = true;
    $flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
    $out = $banner ? ksefHelpBannerHtml($db, $user) : '';
    $out .= '<script>window.ksefHelpCfg = ' . json_encode(ksefHelpConfig($user), $flags) . ';</script>';
    $out .= '<script src="' . ksefHelpScriptUrl() . '"></script>';
    return $out;
}

/**
 * @brief Guide list notes
 * @param DoliDB $db
 * @param string $pick kor, korzal, roz, offline
 * @param string $where tourPickWhere() SQL
 * @return string HTML
 */
function ksefTourPickListHtml($db, $pick, $where)
{
    global $langs;
    $langs->loadLangs(array('ksef@ksef', 'bills', 'orders'));
    $P = MAIN_DB_PREFIX;
    $rows = array();
    if ($pick === 'korzal') {
        // deposit or settlement
        $sql = "SELECT f.rowid, f.type, (SELECT c.ref FROM " . $P . "element_element AS ee INNER JOIN " . $P . "commande AS c ON c.rowid = ee.fk_source"
            . " WHERE ee.sourcetype = 'commande' AND ee.targettype = 'facture' AND ee.fk_target = f.rowid ORDER BY c.rowid LIMIT 1) AS oref"
            . " FROM " . $P . "facture AS f WHERE f.entity IN (" . getEntity('invoice') . ")" . $where . " ORDER BY f.rowid DESC LIMIT 1000";
        $res = $db->query($sql);
        while ($res && ($o = $db->fetch_object($res))) {
            $bits = array($langs->transnoentitiesnoconv((int) $o->type === 3 ? 'KSEF_TourPick_Deposit' : 'KSEF_TourPick_Settlement'));
            if ((string) $o->oref !== '') {
                $bits[] = $langs->transnoentitiesnoconv('KSEF_TourPick_Order', $o->oref);
            }
            $rows[(int) $o->rowid] = implode(' · ', $bits);
        }
    }
    $flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
    $note = '<div class="info ksef-pick-note" data-pick="' . dol_escape_htmltag($pick) . '"><span class="fas fa-filter paddingright"></span>'
        . $langs->trans('KSEF_TourPick_Note_' . $pick) . ' <a href="' . DOL_URL_ROOT . '/compta/facture/list.php">' . $langs->trans('KSEF_TourPick_All') . '</a></div>';
    return '<script>(function () {'
        . 'var rows = ' . json_encode((object) $rows, $flags) . ', note = ' . json_encode($note, $flags) . ';'
        . 'var t = document.querySelector("table.liste"), at = t ? t.closest(".div-table-responsive, .div-table-responsive-no-min") || t : null;'
        . 'if (at && !document.querySelector(".ksef-pick-note")) { at.insertAdjacentHTML("beforebegin", note); }'
        . 'document.querySelectorAll("table.liste tr.oddeven").forEach(function (tr) {'
        . ' var a = tr.querySelector("a[href*=\"/compta/facture/card.php\"]"), m = a ? /[?&](?:facid|id)=(\\d+)/.exec(a.getAttribute("href")) : null;'
        . ' if (!m) { return; } tr.setAttribute("data-ksef-pick-id", m[1]);'
        . ' if (rows[m[1]] && !tr.querySelector(".ksef-pick-info")) { var s = document.createElement("span"); s.className = "opacitymedium small ksef-pick-info"; s.textContent = rows[m[1]]; a.closest("td").appendChild(s); }'
        . '});'
        . '})();</script>';
}

/**
 * @brief Tour script settings
 * @param User $user
 * @return array
 */
function ksefHelpConfig($user)
{
    global $langs;
    $langs->load('ksef@ksef');
    $ui = array();
    foreach (array('Next', 'NextTo', 'Back', 'Ok', 'UsePick', 'Close', 'Finish', 'NotHere', 'OtherPage', 'NextElsewhere', 'PrevElsewhere', 'ClickFirst', 'GoThere', 'JumpHere',
        'StageOf', 'Wait', 'UseIt', 'UseField', 'EnvProdContinue', 'EnvSwitch', 'ProdTick', 'ProdTickHint', 'LoadError', 'AddLine', 'LineAdded', 'LinesThere', 'ScrollRight',
        'Recheck', 'Paused', 'PausedPlain', 'SideBack', 'Return', 'BackInv', 'BackSup', 'BackInc', 'OnKsefTab', 'OnOtherTab', 'WayInv', 'WaySup', 'WayInc', 'BrowserBack', 'NothingMore', 'NextTour', 'NextGuide', 'Done', 'NoMatch', 'SaveFirst', 'StillOpen', 'BackOrd', 'BackSales', 'BrowserBackOnly', 'LinesKept', 'NeedValue', 'Saved', 'NoneGuide', 'LineChanged', 'EditLine',
        'Fold', 'PickRow', 'BackTour', 'Busy', 'ScrollText', 'PartOf', 'UsePickReload', 'GuideList') as $k) {
        $ui[$k] = $langs->transnoentitiesnoconv('KSEF_TourUi_' . $k);
    }
    $pause = array();
    foreach (array('ksef-backdated-modal' => 'PauseBackdated', 'ksef-failure-modal' => 'PauseFailure') as $id => $k) {
        $pause[$id] = $langs->transnoentitiesnoconv('KSEF_TourUi_' . $k);
    }
    return array(
        'endpoint' => dol_buildpath('/ksef/ksef_help.php', 1),
        'env' => ksefGetEnvironment(),
        'pause' => $pause,
        'authUrl' => !empty($user->admin) ? dol_buildpath('/ksef/admin/setup_auth.php', 1) : '',
        'guides' => dol_buildpath('/ksef/ksefindex.php', 1) . '#ksef-help',
        'ui' => $ui,
    );
}

/**
 * @brief Tour script URL
 * @return string
 */
function ksefHelpScriptUrl()
{
    return dol_buildpath('/ksef/js/ksef_tour.js', 1) . '?v=' . urlencode(ksefHelpAssetVersion());
}

/**
 * @brief Tour script version
 * @return string
 */
function ksefHelpAssetVersion()
{
    return (string) (int) @filemtime(dirname(__DIR__) . '/js/ksef_tour.js');
}
