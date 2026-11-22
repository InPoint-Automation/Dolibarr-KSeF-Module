<?php
/* Copyright (C) 2026 InPoint Automation Sp z o.o.
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
 * \file    ksef/sales_import.php
 * \ingroup ksef
 * \brief   Sales invoice import
 */

if (!defined('CSRFCHECK_WITH_TOKEN')) {
    define('CSRFCHECK_WITH_TOKEN', '1');
}

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Include of main fails");

global $conf, $langs, $user, $db;

require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
dol_include_once('/ksef/class/ksef_sales.class.php');
dol_include_once('/ksef/lib/ksef.lib.php');
dol_include_once('/ksef/lib/ksef_tour.lib.php');
dol_include_once('/ksef/lib/ksef_import_ui.lib.php');

$langs->loadLangs(array("ksef@ksef", "bills", "companies", "products"));

if (!$user->hasRight('ksef', 'read')) accessforbidden();
if (isset($user->socid) && $user->socid > 0) accessforbidden();

$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');

if (empty($id)) {
    header('Location: ' . dol_buildpath('/ksef/status.php', 1) . '?restore_lastsearch_values=1');
    exit;
}

$object = new KsefSales($db);
if ($object->fetch($id) <= 0) {
    accessforbidden('Record not found');
}
if (!ksefUserCanReadSales($user, $object)) {
    accessforbidden();
}
if ($object->import_status === KsefSales::STATUS_FETCH_ERROR) {
    setEventMessages($langs->trans('KSEF_FetchErrorNotImportable'), null, 'warnings');
    header('Location: ' . dol_buildpath('/ksef/sales_card.php', 1) . '?id=' . $object->id);
    exit;
}

$form = new Form($db);
$usercanwrite = $user->hasRight('ksef', 'write');
$usercanimport = ksefUserCan($user, 'import_sales');
$forKor = ksefImportForKor($object);

$hookmanager->initHooks(array('ksef_salesimport', 'globalcard'));

$parameters = array();
$reshook = $hookmanager->executeHooks('doActions', $parameters, $object, $action);
if ($reshook < 0) {
    setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}

if (empty($reshook)) {
    if ($action == 'stock_preview') {
        ksefImportStockPreviewAjax($db, $user, $object, 'outgoing');
    }
    ksefCitedOriginalActions($object, $action, $user, $usercanwrite);

    if ($action == 'import' && !$usercanimport) {
        setEventMessages($langs->trans('NotEnoughPermissions'), null, 'errors');
        $action = '';
    }

    if ($action == 'import' && $usercanimport) {
        $lineProducts = array();
        foreach ($object->previewImportLines() as $line) {
            $ln = (int) ($line['line_num'] ?? 0);
            $lineProducts[$ln] = GETPOSTINT('line_product_' . $ln);
        }
        // scripts: create parties
        $boxShown = GETPOSTINT('create_parties_box') === 1;
        $createParties = $boxShown ? GETPOSTINT('create_parties') === 1 : true;
        $opts = array(
            'socid' => GETPOSTINT('socid'),
            'create_customer' => $boxShown && $createParties,
            'create_parties' => $createParties,
            'gus' => $boxShown && $createParties && GETPOSTINT('gus_fetch') === 1,
            'gus_data' => ($boxShown && $createParties && GETPOSTINT('gus_fetch') === 1) ? ksefImportGusData($db, (string) $object->partyInfo()['nip'], 'KSEF_GUS_CUSTOMER_DATA', false) : null,
            'gus_live' => GETPOSTINT('gus_previewed') !== 1,
            'line_products' => $lineProducts,
            'warehouse_id' => GETPOSTINT('warehouse_id'),
            'record_payment' => GETPOSTINT('record_payment') === 1,
            'bank_account_id' => GETPOSTINT('bank_account_id'),
            'correction_source_id' => GETPOSTINT('correction_source_id'),
        );
        $created = array();
        $fid = $object->prepareAndImport($user, $opts, $created);
        $createdText = '';
        if ($object->created_customer_id > 0) {
            $newSoc = new Societe($db);
            $newSoc->fetch((int) $object->created_customer_id);
            $createdText = $langs->transnoentitiesnoconv('KSEF_BatchCreatedCustomer', (string) $newSoc->name);
        }
        if ($fid > 0) {
            $inv = new Facture($db);
            $inv->fetch($fid);
            setEventMessages($langs->trans('KSEF_SalesImported', $inv->ref), null, 'mesgs');
            if ($createdText !== '') {
                setEventMessages(ksefEscapeText($langs->transnoentitiesnoconv('KSEF_ImportCreatedFromInvoice', $createdText)), null, 'mesgs');
            }
            if (!empty($object->warnings)) {
                setEventMessages(null, array_map('ksefEscapeText', (array) $object->warnings), 'warnings');
            }
            if ($forKor) {
                setEventMessages(ksefTransHtml('KSEF_OriginalImportedNowKor', array(ksefEscapeText((string) $forKor->invoice_number))), null, 'mesgs');
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $forKor->id . '&socid=' . (int) $inv->socid);
                exit;
            }
            header('Location: ' . DOL_URL_ROOT . '/compta/facture/card.php?facid=' . $fid);
            exit;
        }
        // decode, escape once
        setEventMessages(ksefEscapeText(html_entity_decode((string) $object->error, ENT_QUOTES | ENT_HTML5, 'UTF-8')), array_map('ksefEscapeText', (array) $object->errors), 'errors');
        if ($createdText !== '') {
            setEventMessages(ksefEscapeText($langs->transnoentitiesnoconv('KSEF_BatchCreatedKept', $createdText)), null, 'warnings');
        }
    }

    // kept for scripts
    if ((($action == 'skip') || ($action == 'confirm_skip' && $confirm == 'yes')) && $usercanwrite) {
        if ($object->skip($user) > 0) {
            setEventMessages($langs->trans('KSEF_InvoiceSkipped'), null, 'mesgs');
            header('Location: ' . dol_buildpath('/ksef/status.php', 1) . '?restore_lastsearch_values=1');
            exit;
        }
        setEventMessages($object->error, $object->errors, 'errors');
    }

    if ($action == 'reopen' && $usercanwrite) {
        if ($object->reopen($user) > 0) {
            setEventMessages($langs->trans('RecordModifiedSuccessfully'), null, 'mesgs');
            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
            exit;
        }
        setEventMessages($object->error, $object->errors, 'errors');
    }
}

$title = $langs->trans('KSEF_ImportToDolibarr') . ' - ' . $object->invoice_number;
llxHeader('', $title, ksefHelpUrl('reconcile'), '', 0, 0, '', '', '', 'mod-ksef page-sales-import');

if ($action == 'ask_skip') {
    print $form->formconfirm($_SERVER["PHP_SELF"] . '?id=' . $object->id, $langs->trans('KSEF_ImportSkip'), $langs->trans('KSEF_ImportSkipConfirm'), 'confirm_skip', '', 0, 1);
}

$head = ksef_sales_prepare_head($object);
print dol_get_fiche_head($head, 'import', $langs->trans('KSEF_SalesInvoice'), -1, 'bill');

$party = $object->partyInfo();
$linkback = '<a href="' . dol_buildpath('/ksef/status.php', 1) . '?restore_lastsearch_values=1">' . $langs->trans("BackToList") . '</a>';
$morehtmlref = '<div class="refidno">';
$morehtmlref .= $langs->trans('Ref') . ': <strong>' . dol_escape_htmltag($object->invoice_number) . '</strong>';
$morehtmlref .= '<br>' . $langs->trans("Customer") . ': <strong>' . dol_escape_htmltag($party['name']) . '</strong>';
if ($party['nip'] !== '') {
    $morehtmlref .= ' (NIP: ' . ksefFormatNIP($party['nip']) . ')';
}
$morehtmlref .= '</div>';
dol_banner_tab($object, 'id', $linkback, 1, 'rowid', 'ksef_number', $morehtmlref);

if (!empty($object->fk_facture)) {
    $linked = new Facture($db);
    $linkedExists = ($linked->fetch((int) $object->fk_facture) > 0);
    print '<div class="fichecenter"><div class="fichehalfleft"><div class="underbanner clearboth"></div>';
    print '<table class="border tableforfield centpercent">';
    print '<tr><td class="titlefield">' . $langs->trans("Status") . '</td><td>' . $object->getLibStatut(5) . '</td></tr>';
    print '<tr><td>' . $langs->trans('KSEF_ImportedInvoice') . '</td><td>';
    print $linkedExists ? $linked->getNomUrl(1) . ' ' . ksefInvoiceStatusBadge($linked, 5) : '<span class="warning"><span class="fa fa-exclamation-triangle"></span> ' . $langs->trans("KSEF_LinkedInvoiceDeleted") . '</span>';
    print '</td></tr></table></div></div><div class="clearboth"></div>';
    print dol_get_fiche_end();
    print '<div class="tabsAction">';
    if ($linkedExists) {
        print '<a class="butAction" href="' . DOL_URL_ROOT . '/compta/facture/card.php?facid=' . $linked->id . '">' . $langs->trans("KSEF_ViewLinkedInvoice") . '</a>';
    }
    print '<a class="butAction" href="' . dol_buildpath('/ksef/sales_card.php', 1) . '?id=' . $object->id . '">' . $langs->trans('KSEF_Back') . '</a>';
    print '</div>';
    print ksefHelpFooter($db, $user);
    llxFooter();
    $db->close();
    return;
}

if ($object->import_status === KsefSales::STATUS_SKIPPED) {
    print '<div class="fichecenter"><div class="fichehalfleft"><div class="underbanner clearboth"></div>';
    print '<table class="border tableforfield centpercent">';
    print '<tr><td class="titlefield">' . $langs->trans("Status") . '</td><td>' . $object->getLibStatut(5) . '</td></tr>';
    print '<tr><td>' . $langs->trans("Invoice") . '</td><td><strong>' . dol_escape_htmltag($object->invoice_number) . '</strong> - '
        . dol_print_date($object->invoice_date, 'day') . ' - ' . price($object->total_gross, 0, $langs, 1, -1, -1, $object->currency) . '</td></tr>';
    print '</table><div class="opacitymedium" style="margin: 10px 0;">' . $langs->trans("KSEF_InvoiceSkipped") . '</div>';
    print '</div></div><div class="clearboth"></div>';
    print dol_get_fiche_end();
    print '<div class="tabsAction">';
    if ($usercanwrite) {
        print '<a class="butAction" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=reopen&token=' . newToken() . '">' . $langs->trans("KSEF_ReopenForImport") . '</a>';
    }
    print '<a class="butAction" href="' . dol_buildpath('/ksef/sales_card.php', 1) . '?id=' . $object->id . '">' . $langs->trans('KSEF_Back') . '</a>';
    print '</div>';
    print ksefHelpFooter($db, $user);
    llxFooter();
    $db->close();
    return;
}

$autoSocid = $object->findMatchingThirdParty();
$selectedSocid = GETPOSTINT('socid');
if ($selectedSocid <= 0) {
    $selectedSocid = $autoSocid;
}
$refusalCode = '';
$refusal = $object->importRefusal($refusalCode, GETPOSTINT('correction_source_id'), $selectedSocid);
$lines = $object->previewImportLines();
$lines = array_merge($lines, $object->depositPreviewLines());
$autoMatches = $object->autoMatchLineProducts($lines);
$backtopageRaw = $_SERVER['PHP_SELF'] . '?id=' . $object->id . ($forKor ? '&for_kor=' . (int) $forKor->id : '');

if ($refusal !== '') {
    print '<div class="warning"><span class="fa fa-exclamation-triangle"></span> ' . ksefEscapeText($refusal) . '</div>';
}

ksefPrintImportHelperForms($lines);

if ($forKor) {
    print '<div class="info" id="ksef_for_kor">' . ksefTransHtml('KSEF_ImportingOriginalOf', array(ksefEscapeText((string) $forKor->invoice_number))) . '</div>';
}

print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '" id="import_form">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="id" value="' . $object->id . '">';
print '<input type="hidden" name="action" value="">';
print '<input type="hidden" name="for_kor" value="' . ($forKor ? (int) $forKor->id : 0) . '">';

print '<div class="fichecenter">';
print '<div class="fichehalfleft">';
print '<div class="underbanner clearboth"></div>';
print '<table class="border tableforfield centpercent">';

print '<tr><td class="titlefield">' . $langs->trans("Status") . '</td><td>' . $object->getLibStatut(5) . '</td></tr>';

print '<tr><td>' . $langs->trans("Invoice") . '</td><td><strong>' . dol_escape_htmltag($object->invoice_number) . '</strong>'
    . ' - ' . dol_print_date($object->invoice_date, 'day') . ' - ' . price($object->total_gross, 0, $langs, 1, -1, -1, $object->currency) . '</td></tr>';
print '<tr><td>' . $langs->trans("Type") . '</td><td>' . ksefGetInvoiceTypeBadge($object->invoice_type) . '</td></tr>';

if (KsefIncoming::isCorrectionType($object->invoice_type)) {
    print '<tr><td>' . $langs->trans("KSEF_CorrectsInvoice") . '</td><td>';
    $citedFound = array();
    foreach ($object->citedInvoices() as $i => $c) {
        print ($i ? '<br>' : '') . dol_escape_htmltag($c['label']);
        if ($c['invoice']) {
            $citedFound[(int) $c['invoice']->id] = $c['invoice'];
            print ' - ' . $langs->trans("KSEF_ImportedAs") . ': ' . $c['invoice']->getNomUrl(1) . ' ' . ksefInvoiceStatusBadge($c['invoice'], 5);
            continue;
        }
        print ' ' . ksefCitedOriginalOffer($object, $c['ksef'], $c['record'], $usercanwrite);
    }
    print '</td></tr>';
    if (count($citedFound) > 1) {
        print '<tr><td>' . $langs->trans("KSEF_CorrectionChooseStrategy") . '</td><td><div id="correction_strategy_radios">';
        $pick = isset($citedFound[GETPOSTINT('correction_source_id')]) ? GETPOSTINT('correction_source_id') : array_key_first($citedFound);
        foreach ($citedFound as $fid => $inv) {
            $reload = "location.href='" . dol_escape_js($_SERVER['PHP_SELF'] . '?id=' . $object->id . '&correction_source_id=' . $fid . '&socid=') . "' + (jQuery('#socid_hidden').val() || '')";
            print '<div style="margin-bottom: 4px;"><label><input type="radio" name="correction_source_id" value="' . $fid . '"' . ($fid === $pick ? ' checked' : '') . ' onchange="' . dol_escape_htmltag($reload) . '"> ';
            print $langs->trans("KSEF_CorrectionLinkTo", dol_escape_htmltag($inv->ref)) . '</label></div>';
        }
        print '</div></td></tr>';
    }
    if ($citedFound) {
        $korektaSource = $object->resolveKorektaSource(GETPOSTINT('correction_source_id'), $selectedSocid);
        print '<tr><td>' . $langs->trans("KSEF_UpwardCorrection") . '</td><td>';
        if (is_object($korektaSource)) {
            print '<span class="fa fa-info-circle paddingright"></span><span id="ksef_korekta_info">'
                . $langs->trans("KSEF_UpwardCorrectionKorekta", $korektaSource->getNomUrl(1), dol_escape_htmltag($korektaSource->ref)) . '</span>';
            $others = array();
            foreach ($object->korekta_linked as $inv) {
                $others[] = $inv->getNomUrl(1);
            }
            if ($others) {
                print '<br><span class="fa fa-link paddingright"></span><span id="ksef_korekta_linked">' . $langs->trans("KSEF_KorektaAlsoLinked", implode(', ', $others)) . '</span>';
            }
        } else {
            print '<span class="warning" id="ksef_korekta_refused"><span class="fa fa-exclamation-triangle paddingright"></span>' . dol_escape_htmltag($korektaSource) . '</span>';
        }
        print '</td></tr>';
    }
    $corr = $object->getCorrectionData();
    if (!empty($corr['reason'])) {
        print '<tr><td>' . $langs->trans("KSEF_CorrectionReason") . '</td><td>' . dol_escape_htmltag($corr['reason']) . '</td></tr>';
    }
}

if ($object->invoice_type === 'ROZ') {
    print '<tr><td>' . $langs->trans('KSEF_SettledDeposits') . '</td><td>';
    foreach ($object->settledDeposits() as $d) {
        if ($d['id'] > 0) {
            $depInv = new Facture($db);
            $depInv->fetch($d['id']);
            print $depInv->getNomUrl(1) . ' <span class="opacitymedium small">' . dol_escape_htmltag($d['label']) . '</span> ';
        } else {
            print dol_escape_htmltag($d['label']) . ' ';
        }
        print $d['id'] > 0 ? '<span class="badge ' . ksefToneBadgeClass('ok') . ' badge-status">' . $langs->trans('KSEF_SalesInDolibarr') . '</span>' : KsefSales::linkBadge(KsefSales::LINK_IMPORTABLE);
        print '<br>';
    }
    print '</td></tr>';
}

ksefPrintImportPartyPicker($form, $object, $party, $selectedSocid, $autoSocid, $backtopageRaw, 'outgoing');
ksefPrintImportCreatePartiesRow($user, 'outgoing');

print '</table>';
print '</div>';

print '<div class="fichehalfright">';
print '<div class="underbanner clearboth"></div>';
ksefPrintImportAmounts($object);
ksefPrintCardVatSummary($object);
print '</div>';
print '</div>';
print '<div class="clearboth"></div>';

ksefPrintImportLines($form, $object, $lines, $autoMatches, $backtopageRaw, 'outgoing');

$stockOn = isModEnabled('stock') && getDolGlobalString('STOCK_CALCULATE_ON_BILL');
$isKor = KsefIncoming::isCorrectionType($object->invoice_type);
$korWh = ($stockOn && $isKor && !empty($korektaSource)) ? ksefImportCorrectionWarehouse($db, $korektaSource, false) : 0;
$whSelect = ksefImportWarehouseSelect($db, $user, 'outgoing', $korWh);
$stockKey = '';
if (isModEnabled('stock')) {
    $stockKey = ($stockOn && $whSelect !== '') ? 'KSEF_SalesStockWillDecrease' : 'KSEF_SalesStockWillNotDecrease';
    if ($stockOn && $whSelect !== '' && $isKor) {
        $srcMoved = true;
        if (!empty($korektaSource) && is_object($korektaSource)) {
            $srcMoved = (bool) ksefCorrectionStockSlots($db, $korektaSource, false);
        }
        $stockKey = $srcMoved ? 'KSEF_SalesKorStockDifference' : 'KSEF_SalesKorStockFull';
    }
}
$stockNone = ($stockKey === 'KSEF_SalesKorStockDifference' || $stockKey === 'KSEF_SalesKorStockFull') ? $langs->transnoentitiesnoconv('KSEF_SalesStockWillNotDecrease') : '';
$optRows = '';
if ($stockKey !== '') {
    $optRows .= '<tr class="oddeven" id="ksef_stock_option"><td class="titlefield">' . $langs->trans($isKor || $whSelect === '' ? 'KSEF_SalesStockOption' : 'KSEF_SalesWarehouse') . '</td><td>';
    $optRows .= ($whSelect !== '' ? $whSelect . '<br>' : '') . '<span class="opacitymedium" id="ksef_stock_hint"' . ($stockNone !== '' ? ' data-chosen="' . dol_escape_htmltag($langs->transnoentitiesnoconv($stockKey)) . '" data-none="' . dol_escape_htmltag($stockNone) . '"' : '') . '>' . ($stockNone !== '' && $korWh <= 0 ? dol_escape_htmltag($stockNone) : $langs->trans($stockKey)) . '</span>';
    $optRows .= ' <span class="fa fa-info-circle classfortooltip opacitymedium" title="' . dol_escape_htmltag($langs->transnoentitiesnoconv($stockKey === 'KSEF_SalesKorStockDifference' ? 'KSEF_SalesKorStockHelp' : ($stockOn ? 'KSEF_SalesStockDecreaseHelp' : 'KSEF_SalesStockNoDecreaseHelp'))) . '"></span>'
        . ($whSelect !== '' ? '<div id="ksef_stock_preview" class="ksef-stock-preview" style="margin-top: 6px;"></div>' : '') . '</td></tr>';
}
$ksefPayText = KsefIncoming::isCorrectionType($object->invoice_type) ? '' : $object->ksefPaymentText();
if ($ksefPayText !== '') {
    $optRows .= '<tr class="oddeven"><td class="titlefield">' . $langs->trans('KSEF_SalesKsefPayment') . '</td><td>';
    $optRows .= dol_escape_htmltag($ksefPayText) . '<br>';
    if (!ksefUserCan($user, 'record_payment')) {
        $optRows .= '<span class="warning">' . $langs->trans('KSEF_SalesPaymentNotRecordedRight') . '</span>';
    } elseif (!isModEnabled('bank')) {
        $optRows .= '<span class="warning">' . $langs->trans('KSEF_SalesPaymentNotRecordedBank') . '</span>';
    } else {
        $invBank = $object->defaultBankAccount();
        $optRows .= '<label><input type="checkbox" name="record_payment" value="1"' . ($invBank > 0 ? ' checked' : '') . '> ' . $langs->trans('KSEF_SalesRecordPayment') . '</label> ';
        $optRows .= $form->select_comptes($invBank > 0 ? $invBank : '', 'bank_account_id', 0, '', 1, '', 0, '', 1);
        if ($invBank <= 0) {
            $optRows .= '<br><span class="warning">' . dol_escape_htmltag($object->bankAccountProblem()) . '</span>';
        }
    }
    $optRows .= '</td></tr>';
}
if ($optRows !== '') {
    print '<br><table class="noborder centpercent">';
    print '<tr class="liste_titre"><td colspan="2">' . $langs->trans('KSEF_ImportOptions') . '</td></tr>';
    print $optRows;
    print '</table>';
}

print '</form>';

print dol_get_fiche_end();

print '<div class="tabsAction">';
$parameters = array();
$reshook = $hookmanager->executeHooks('addMoreActionsButtons', $parameters, $object, $action);
if (empty($reshook) && $usercanwrite) {
    if (!$usercanimport) {
        if (!ksefHideUnauthorized($user)) {
            print '<span class="butActionRefused classfortooltip" title="' . dol_escape_htmltag(ksefMissingRightText('import_sales')) . '">' . $langs->trans("KSEF_Import") . '</span>';
        }
    } elseif ($refusal !== '') {
        print '<span class="butActionRefused classfortooltip" title="' . dol_escape_htmltag($refusal) . '">' . $langs->trans("KSEF_Import") . '</span>';
    } else {
        print '<a class="butAction" href="#" id="btn_import" title="' . dol_escape_htmltag($langs->trans("KSEF_SalesImportDesc", $object->invoice_number)) . '">' . $langs->trans("KSEF_Import") . '</a>';
    }
    print '<a class="butAction" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ask_skip&token=' . newToken() . '" id="btn_skip" title="' . dol_escape_htmltag($langs->trans("KSEF_ImportSkipDesc")) . '">' . $langs->trans("KSEF_ImportSkip") . '</a>';
}
print '<a class="butAction" href="' . dol_buildpath('/ksef/sales_card.php', 1) . '?id=' . $object->id . '">' . $langs->trans('KSEF_Back') . '</a>';
print '</div>';

ksefPrintImportConfirmScript($object, $lines, array(
    'direction' => 'outgoing',
    'party_name' => $party['name'],
    'gus_preview_url' => (getDolGlobalString('KSEF_GUS_ENABLED') && ksefUserCan($user, 'create_customer')) ? dol_buildpath('/ksef/status.php', 1) . '?action=gus_supplier_preview&token=' . newToken() : '',
    'multi_correction' => !empty($citedFound) && count($citedFound) > 1,
    'korekta' => KsefIncoming::isCorrectionType($object->invoice_type),
    'korekta_after' => isset($korektaSource) ? ksefImportKorektaAfterText($object, $korektaSource) : '',
    'stock_short' => $stockNone !== '' ? $stockNone : ($stockKey !== '' ? $langs->transnoentitiesnoconv($stockKey) : ''),
    'stock_preview_url' => $whSelect !== '' && $user->hasRight('stock', 'lire') ? $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=stock_preview' : '',
));

print ksefHelpFooter($db, $user);
llxFooter();
$db->close();
