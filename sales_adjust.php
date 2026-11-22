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
 * \file    ksef/sales_adjust.php
 * \ingroup ksef
 * \brief   Sales adjust tab
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

require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
dol_include_once('/ksef/class/ksef_sales.class.php');
dol_include_once('/ksef/lib/ksef.lib.php');
dol_include_once('/ksef/lib/ksef_tour.lib.php');
dol_include_once('/ksef/lib/ksef_import_ui.lib.php');

$langs->loadLangs(array("ksef@ksef", "bills", "companies"));

$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');

if (!$user->hasRight('ksef', 'read') || !empty($user->socid)) accessforbidden();
$object = new KsefSales($db);
if ($id <= 0 || $object->fetch($id) <= 0 || (int) $object->fk_facture <= 0) {
    accessforbidden($langs->trans('ErrorRecordNotFound'), 0);
}
if (!ksefUserCanReadInvoice($user, (int) $object->fk_facture)) accessforbidden();
$invoice = new Facture($db);
$invoice->fetch((int) $object->fk_facture);
$invoice->fetch_thirdparty();
$canAdjust = ksefUserCan($user, 'adjust_invoice');
$form = new Form($db);

$buyerDiffers = in_array('NIP', $object->keyFieldDiff($invoice), true);
$autoSocid = $buyerDiffers ? $object->findMatchingThirdParty() : 0;
$adjustSocid = $buyerDiffers ? (GETPOSTINT('socid') > 0 ? GETPOSTINT('socid') : $autoSocid) : 0;
$socParam = $adjustSocid > 0 ? '&socid=' . $adjustSocid : '';

if ($action === 'confirm_adjust' && $confirm === 'yes') {
    if (!$canAdjust) accessforbidden();
    if ($object->adjustInvoice($user, $invoice, null, $adjustSocid) > 0) {
        setEventMessages($langs->trans('KSEF_AdjustDone', $invoice->ref), null, 'mesgs');
        if ($object->link_status === KsefSales::LINK_DIFFERS) {
            setEventMessages(ksefEscapeText((string) $object->link_note), null, 'warnings');
        }
        header('Location: ' . DOL_URL_ROOT . '/compta/facture/card.php?facid=' . $invoice->id);
        exit;
    }
    setEventMessages(ksefEscapeText(html_entity_decode((string) $object->error, ENT_QUOTES | ENT_HTML5, 'UTF-8')), array_map('ksefEscapeText', (array) $object->errors), 'errors');
    $invoice->fetch($invoice->id);
}

llxHeader('', $langs->trans('KSEF_AdjustTitle'), ksefHelpUrl('reconcile'), '', 0, 0, '', '', '', 'mod-ksef page-sales-adjust');

$head = ksef_sales_prepare_head($object);
print dol_get_fiche_head($head, 'adjust', $langs->trans('KSEF_SalesInvoice'), -1, 'bill');

if ($action === 'adjust' && $canAdjust) {
    $question = $langs->trans('KSEF_AdjustConfirm', $invoice->ref);
    $newSoc = new Societe($db);
    if ($adjustSocid > 0 && $adjustSocid !== (int) $invoice->socid && $newSoc->fetch($adjustSocid) > 0) {
        $question = ksefTransHtml('KSEF_AdjustConfirmCustomer', array(ksefEscapeText($invoice->ref), ksefEscapeText($newSoc->name)));
    }
    print $form->formconfirm($_SERVER['PHP_SELF'] . '?id=' . $object->id . $socParam, $langs->trans('KSEF_AdjustTitle'), $question, 'confirm_adjust', '', 0, 1);
}

$linkback = '<a href="' . dol_buildpath('/ksef/status.php', 1) . '?restore_lastsearch_values=1">' . $langs->trans("BackToList") . '</a>';
$morehtmlref = '<div class="refidno">' . $langs->trans('KSEF_SalesLinkedInvoice') . ': ' . $invoice->getNomUrl(1) . ' ' . ksefInvoiceStatusBadge($invoice, 5)
    . (!empty($invoice->thirdparty) ? '<br>' . $langs->trans('Customer') . ': ' . $invoice->thirdparty->getNomUrl(1) : '') . '</div>';
dol_banner_tab($object, 'id', $linkback, 1, 'rowid', 'ksef_number', $morehtmlref);

$refusal = $object->adjustRefusal($invoice);
$p = $object->adjustPreview($invoice);
$cur = $object->currency ?: 'PLN';
$fieldLabels = array('P_1' => 'DateInvoice', 'P_6' => 'KSEF_SaleDate', 'KodWaluty' => 'Currency', 'NIP' => 'KSEF_SalesBuyerNip');

print '<div class="opacitymedium" style="margin: 6px 0 10px 0;">' . $langs->trans('KSEF_AdjustDesc') . '</div>';
if ($refusal === $langs->transnoentitiesnoconv('KSEF_AdjustNotNeeded')) {
    $why = $object->parsedXml() && !$object->sourceLinesAddUp() ? '<br>' . $langs->trans('KSEF_AdjustMatchesSourceLinesOff') : '';
    print '<div class="info ksef-adjust-matches">' . ksefEscapeText($refusal) . $why . '</div>';
} elseif ($refusal !== '') {
    print '<div class="warning"><span class="fa fa-exclamation-triangle"></span> ' . ksefEscapeText($refusal) . '</div>';
}

print '<div class="fichecenter">';
print '<div class="fichehalfleft">';
print '<div class="underbanner clearboth"></div>';
print '<table class="border tableforfield centpercent ksef-adjust-head">';
print '<tr class="liste_titre"><td class="titlefield"></td><td>' . $langs->trans('KSEF_AdjustDolibarr') . '</td><td>' . $langs->trans('KSEF_AdjustKsef') . '</td></tr>';
foreach ($p['header'] as $field => $v) {
    $changed = trim((string) $v[0]) !== trim((string) $v[1]);
    print '<tr class="' . ($changed ? 'ksef-adjust-changed' : '') . '"><td>' . $langs->trans($fieldLabels[$field] ?? $field) . '</td><td>' . ksefEscapeText((string) $v[0]) . '</td><td>'
        . ($changed ? '<strong>' . ksefEscapeText((string) $v[1]) . '</strong>' : ksefEscapeText((string) $v[1])) . '</td></tr>';
}
if (!empty($p['buyer'])) {
    print '<tr class="ksef-adjust-buyer-data"><td>' . $langs->trans('Customer') . '</td><td>' . ksefBuyerSideHtml($p['buyer']['dolibarr']) . '</td><td>' . ksefBuyerSideHtml($p['buyer']['ksef']) . '</td></tr>';
    if ($p['buyer']['changed']) {
        print '<tr><td></td><td colspan="2">' . ksefBuyerChangedNote($p['buyer']['changed_what']) . '</td></tr>';
    }
}
print '</table>';
if ($buyerDiffers && $refusal === '' && $canAdjust) {
    $party = $object->partyInfo();
    ksefPrintImportHelperForms(array());
    print '<div class="opacitymedium" style="margin: 10px 0 4px 0;">' . ksefTransHtml('KSEF_AdjustBuyerDesc', array(ksefEscapeText(trim($party['name'] . ($party['nip'] !== '' ? ' (NIP: ' . ksefFormatNIP($party['nip']) . ')' : ''))))) . '</div>';
    print '<table class="border tableforfield centpercent ksef-adjust-buyer">';
    ksefPrintImportPartyPicker($form, $object, $party, $adjustSocid, $autoSocid, $_SERVER['PHP_SELF'] . '?id=' . $object->id, 'outgoing');
    print '</table>';
}
print '</div>';
print '<div class="fichehalfright">';
print '<div class="underbanner clearboth"></div>';
print '<table class="border tableforfield centpercent">';
print '<tr class="liste_titre"><td class="titlefieldmiddle"></td><td class="right">' . $langs->trans('KSEF_AdjustDolibarr') . '</td><td class="right">' . $langs->trans('KSEF_AdjustKsef') . '</td></tr>';
foreach (array('net' => 'AmountHT', 'vat' => 'AmountVAT', 'gross' => 'AmountTTC') as $k => $label) {
    $changed = abs($p['totals'][$k][0] - $p['totals'][$k][1]) >= 0.005;
    print '<tr class="' . ($changed ? 'ksef-adjust-changed' : '') . '"><td>' . $langs->trans($label) . '</td><td class="nowrap amountcard right">' . price($p['totals'][$k][0], 0, $langs, 1, -1, 2, $cur) . '</td><td class="nowrap amountcard right">'
        . ($changed ? '<strong>' : '') . price($p['totals'][$k][1], 0, $langs, 1, -1, 2, $cur) . ($changed ? '</strong>' : '') . '</td></tr>';
}
print '</table>';
print '</div>';
print '</div>';
print '<div class="clearboth"></div><br>';

print '<div class="div-table-responsive-no-min">';
print '<table id="tablelines" class="noborder noshadow centpercent">';
print '<tr class="liste_titre nodrag nodrop"><td class="linecolnum center" style="width:30px">#</td><td class="center" style="width:90px"></td><td>' . $langs->trans('Description') . '</td>'
    . '<td class="right">' . $langs->trans('Qty') . '</td><td class="right">' . $langs->trans('PriceUHT') . '</td><td class="right">' . $langs->trans('VAT') . '</td><td class="right">' . $langs->trans('TotalHT') . '</td></tr>';
$n = max(count($p['lines_dolibarr']), count($p['lines_ksef']));
for ($i = 0; $i < $n; $i++) {
    $d = $p['lines_dolibarr'][$i] ?? null;
    $k = $p['lines_ksef'][$i] ?? null;
    $diff = function ($key) use ($d, $k) {
        if (!$d || !$k) {
            return true;
        }
        return is_numeric($d[$key]) ? abs((float) $d[$key] - (float) $k[$key]) >= 0.005 : trim((string) $d[$key]) !== trim((string) $k[$key]);
    };
    $lineChanged = ($diff('desc') || $diff('qty') || $diff('pu') || $diff('vat') || $diff('net'));
    foreach (array(array($d, 'KSEF_AdjustDolibarr', false), array($k, 'KSEF_AdjustKsef', true)) as $side) {
        list($l, $label, $isKsef) = $side;
        print '<tr class="oddeven' . ($lineChanged && $isKsef ? ' ksef-adjust-changed' : '') . '">';
        print '<td class="linecolnum center">' . ($isKsef ? '' : ($i + 1)) . '</td>';
        print '<td class="center"><span class="opacitymedium small">' . $langs->trans($label) . '</span></td>';
        if (!$l) {
            print '<td colspan="5"><span class="opacitymedium">-</span></td></tr>';
            continue;
        }
        $b = function ($key, $html) use ($isKsef, $diff) {
            return ($isKsef && $diff($key)) ? '<strong>' . $html . '</strong>' : $html;
        };
        print '<td class="tdoverflowmax300">' . $b('desc', ksefEscapeText($l['desc'])) . '</td>';
        print '<td class="right nowraponall">' . $b('qty', price($l['qty'], 0, '', 1, -1, 4)) . '</td>';
        print '<td class="right nowraponall">' . $b('pu', price($l['pu'], 0, '', 1, -1, 2)) . '</td>';
        print '<td class="right nowraponall">' . $b('vat', is_numeric($l['vat']) ? price2num($l['vat']) . '%' : dol_escape_htmltag(preg_replace('/^([0-9.]+)\s*/', '$1% ', (string) $l['vat']))) . '</td>';
        print '<td class="right nowraponall">' . $b('net', price($l['net'], 0, '', 1, -1, 2)) . '</td>';
        print '</tr>';
    }
}
print '</table></div>';

print dol_get_fiche_end();

print '<div class="tabsAction">';
if ($refusal !== '') {
    print '<span class="butActionRefused classfortooltip" title="' . dol_escape_htmltag($refusal) . '">' . $langs->trans('KSEF_AdjustToKsef') . '</span>';
} elseif ($canAdjust) {
    print '<a class="butAction" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . $socParam . '&action=adjust&token=' . newToken() . '">' . $langs->trans('KSEF_AdjustToKsef') . '</a>';
} elseif (!ksefHideUnauthorized($user)) {
    print dolGetButtonAction($langs->trans('NotEnoughPermissions'), $langs->trans('KSEF_AdjustToKsef'), 'default', '#', '', false);
}
print '<a class="butAction" href="' . dol_buildpath('/ksef/sales_card.php', 1) . '?id=' . $object->id . '">' . $langs->trans('KSEF_Back') . '</a>';
print '</div>';

print ksefHelpFooter($db, $user);
llxFooter();
$db->close();
