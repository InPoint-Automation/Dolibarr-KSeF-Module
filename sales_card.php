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
 * \file    ksef/sales_card.php
 * \ingroup ksef
 * \brief   Sales invoice card
 */

if (!defined('CSRFCHECK_WITH_TOKEN')) {
    define('CSRFCHECK_WITH_TOKEN', '1');
}

// keep token
$action_raw = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
if (in_array($action_raw, array('download_xml', 'download_pdf', 'download_upo'))) {
    if (!defined('NOTOKENRENEWAL')) {
        define('NOTOKENRENEWAL', '1');
    }
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
dol_include_once('/ksef/class/ksef_sales.class.php');
dol_include_once('/ksef/class/ksef_service.class.php');
dol_include_once('/ksef/class/fa3_parser.class.php');
dol_include_once('/ksef/lib/ksef.lib.php');
dol_include_once('/ksef/lib/ksef_tour.lib.php');
dol_include_once('/ksef/lib/ksef_import_ui.lib.php');

$langs->loadLangs(array("ksef@ksef", "bills", "companies", "banks"));

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

$parser = new FA3Parser($db);
$form = new Form($db);
$usercanwrite = $user->hasRight('ksef', 'write');
$isFetchError = ($object->import_status === KsefSales::STATUS_FETCH_ERROR);

$hookmanager->initHooks(array('ksef_salescard', 'globalcard'));

if ($action == 'download_xml' && !empty($object->fa3_xml)) {
    ksefSendDownload($object->fa3_xml, ksefDownloadName('FA3', $object->ksef_number, 'sales_' . $object->id, 'xml'), 'application/xml');
    exit;
}

if ($action == 'download_pdf' && !empty($object->fa3_xml) && !$isFetchError) {
    $pdf = $object->generatePdfVisualization();
    if ($pdf) {
        ksefSendDownload($pdf, ksefDownloadName('FA3', $object->ksef_number, 'sales_' . $object->id, 'pdf'), 'application/pdf');
        exit;
    }
    setEventMessages($langs->trans('KSEF_PdfGenerationFailed'), null, 'warnings');
    $action = '';
}

if ($action == 'confirm_link' && $confirm == 'yes' && ksefUserCan($user, 'link_ksef_number')) {
    if ((int) $object->link_candidate > 0 && ksefUserCanReadInvoice($user, (int) $object->link_candidate)) {
        $ksef = new KsefService($db);
        $r = $ksef->linkKsefNumber((int) $object->link_candidate, $object->ksef_number, $user);
        if (($r['status'] ?? '') === 'ACCEPTED') {
            setEventMessages($langs->trans('KSEF_LinkKsefNumberDone', ksefEscapeText($object->ksef_number)), null, 'mesgs');
            if (empty($r['has_upo'])) {
                setEventMessages($langs->trans('KSEF_UPOUnavailableNoSession'), null, 'warnings');
            }
            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
            exit;
        }
        setEventMessages(ksefEscapeText($r['error'] ?? $ksef->error), null, 'errors');
    }
    $action = '';
}

$upoSub = null;
if (!empty($object->fk_facture) && !$isFetchError && ksefUserCanReadInvoice($user, (int) $object->fk_facture)) {
    dol_include_once('/ksef/class/ksef_submission.class.php');
    $upoSub = new KsefSubmission($db);
    if ($upoSub->fetch(0, (int) $object->fk_facture) <= 0 || $upoSub->status !== 'ACCEPTED'
        || (string) $upoSub->ksef_number !== (string) $object->ksef_number) {
        $upoSub = null;
    }
}
if ($action == 'download_upo' && $upoSub) {
    $ksef = new KsefService($db);
    $upo = $ksef->upoForDownload($upoSub, $user);
    if ($upo !== '') {
        ksefSendDownload($upo, ksefDownloadName('UPO', $upoSub->ksef_number, 'sales_' . $object->id, 'xml'), 'application/xml');
        exit;
    }
    setEventMessages($langs->trans('KSEF_UPONotAvailable') . ($ksef->error !== '' ? ': ' . ksefEscapeText($ksef->error) : ''), null, 'warnings');
    header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
    exit;
}

if ($action == 'retry_fetch' && $isFetchError && $usercanwrite) {
    $result = $object->reprocessFromXml($user);
    if ($result > 0) {
        setEventMessages($langs->trans('KSEF_RetryFetchDone'), null, 'mesgs');
        header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
        exit;
    }
    setEventMessages($object->error, null, $result == 0 ? 'warnings' : 'errors');
    $object->fetch($object->id);
}

if ($action == 'confirm_delete' && $confirm == 'yes' && ksefUserCan($user, 'delete_sales')) {
    if ($object->delete($user) > 0) {
        setEventMessages($langs->trans("RecordDeleted"), null, 'mesgs');
        header('Location: ' . dol_buildpath('/ksef/status.php', 1) . '?restore_lastsearch_values=1');
        exit;
    }
    setEventMessages($object->error, $object->errors, 'errors');
}

$title = $langs->trans('KSEF_SalesInvoice') . ' - ' . $object->invoice_number;
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-ksef page-sales-card');

$head = ksef_sales_prepare_head($object);
print dol_get_fiche_head($head, 'card', $langs->trans('KSEF_SalesInvoice'), -1, 'bill');

$formconfirm = '';
$candidate = null;
if ((int) $object->link_candidate > 0) {
    $candidate = new Facture($db);
    if ($candidate->fetch((int) $object->link_candidate) <= 0) {
        $candidate = null;
    }
}
if ($action == 'link' && $candidate && ksefUserCan($user, 'link_ksef_number')) {
    $formconfirm = $form->formconfirm($_SERVER['PHP_SELF'] . '?id=' . $object->id, $langs->trans('KSEF_LinkToInvoice'),
        $langs->trans('KSEF_ConfirmLinkSales', dol_escape_htmltag($object->ksef_number), dol_escape_htmltag($candidate->ref)), 'confirm_link', '', 0, 1);
}
if ($action == 'delete' && ksefUserCan($user, 'delete_sales')) {
    $formconfirm = $form->formconfirm($_SERVER['PHP_SELF'] . '?id=' . $object->id, $langs->trans('Delete'),
        ksefTransHtml('KSEF_SalesConfirmDelete', array(ksefEscapeText($object->invoice_number))), 'confirm_delete', '', 0, 1);
}
print $formconfirm;

$linkback = '<a href="' . dol_buildpath('/ksef/status.php', 1) . '?restore_lastsearch_values=1">' . $langs->trans("BackToList") . '</a>';
$party = $object->partyInfo();
$morehtmlref = '<div class="refidno">';
$morehtmlref .= $langs->trans('Ref') . ': <strong>' . dol_escape_htmltag($object->invoice_number) . '</strong>';
$morehtmlref .= '<br>' . $langs->trans("Customer") . ': <strong>' . dol_escape_htmltag($party['name']) . '</strong>';
if ($party['nip'] !== '') {
    $morehtmlref .= ' <span class="opacitymedium">(NIP: ' . ksefFormatNIP($party['nip']) . ')</span>';
}
if ($party['vat_id'] !== '') {
    $morehtmlref .= ' <span class="opacitymedium">(VAT: ' . dol_escape_htmltag($party['vat_id']) . ')</span>';
}
$morehtmlref .= '</div>';
dol_banner_tab($object, 'id', $linkback, 1, 'rowid', 'ksef_number', $morehtmlref);

print '<div class="fichecenter">';
print '<div class="fichehalfleft">';
print '<div class="underbanner clearboth"></div>';
print '<table class="border tableforfield centpercent">';

print '<tr><td class="titlefield">' . $langs->trans('Type') . '</td><td>';
print ksefGetInvoiceTypeBadge($object->invoice_type);
if (ksefInvoiceTypeLabel($object->invoice_type) !== '') {
    print ' <span class="opacitymedium">(' . dol_escape_htmltag(ksefInvoiceTypeLabel($object->invoice_type)) . ')</span>';
}
print '</td></tr>';

print '<tr><td>' . $langs->trans('KSEF_SalesMatch') . '</td><td>' . $object->getLibStatut(5);
$why = $isFetchError ? $object->getFetchErrorText() : (string) $object->link_note;
if ($why === '' && !$isFetchError) {
    $why = $langs->transnoentitiesnoconv('KSEF_SalesLinkHelp_' . ($object->import_status === KsefSales::STATUS_SKIPPED ? KsefSales::LINK_SKIPPED : ($object->link_status ?: 'IMPORTABLE')));
}
if ($why !== '' && strpos($why, 'KSEF_SalesLinkHelp_') !== 0) {
    print '<br><span class="opacitymedium">' . ksefEscapeText($why) . '</span>';
}
print '</td></tr>';
$buyerChange = null;
if (!empty($object->fk_facture)) {
    $linked = new Facture($db);
    print '<tr><td>' . $langs->trans('KSEF_SalesLinkedInvoice') . '</td><td>';
    if ($linked->fetch((int) $object->fk_facture) > 0) {
        print $linked->getNomUrl(1) . ' ' . ksefInvoiceStatusBadge($linked, 5);
        $buyerChange = $isFetchError ? null : $object->buyerChange($linked);
    } else {
        print '<span class="warning"><span class="fa fa-exclamation-triangle"></span> ' . $langs->trans('KSEF_LinkedInvoiceDeleted') . '</span>';
    }
    print '</td></tr>';
} elseif ($candidate) {
    print '<tr><td>' . $langs->trans('KSEF_SalesCandidateInvoice') . '</td><td>' . $candidate->getNomUrl(1) . ' ' . ksefInvoiceStatusBadge($candidate, 5) . '</td></tr>';
}

if (KsefIncoming::isCorrectionType($object->invoice_type)) {
    print '<tr><td>' . $langs->trans('KSEF_CorrectsInvoice') . '</td><td>';
    print $langs->trans('KSEF_CorrectsKsefNr') . ': ' . ($object->corrected_ksef_number ? dol_escape_htmltag($object->corrected_ksef_number) : '<span class="opacitymedium">-</span>');
    if (!empty($object->corrected_invoice_number)) {
        print '<br>' . $langs->trans('Ref') . ': ' . dol_escape_htmltag($object->corrected_invoice_number);
    }
    $srcId = $object->findCorrectedInvoice();
    if ($srcId > 0) {
        $src = new Facture($db);
        $src->fetch($srcId);
        print '<br>' . $langs->trans('KSEF_ImportedAs') . ': ' . $src->getNomUrl(1) . ' ' . ksefInvoiceStatusBadge($src, 5);
    } else {
        print '<br><span class="warning"><span class="fa fa-exclamation-triangle"></span> ' . $langs->trans('KSEF_OriginalNotImported') . '</span>';
    }
    print '</td></tr>';
}
if ($object->invoice_type === 'ROZ') {
    print '<tr><td>' . $langs->trans('KSEF_SettledDeposits') . '</td><td>';
    foreach ($object->settledDeposits() as $d) {
        print dol_escape_htmltag($d['label']) . ' ';
        print $d['id'] > 0 ? '<span class="badge ' . ksefToneBadgeClass('ok') . ' badge-status">' . $langs->trans('KSEF_SalesInDolibarr') . '</span>' : KsefSales::linkBadge(KsefSales::LINK_IMPORTABLE);
        print '<br>';
    }
    print '</td></tr>';
}

print '<tr><td>' . $langs->trans("DateInvoice") . '</td><td>' . dol_print_date($object->invoice_date, 'day') . '</td></tr>';
if ($object->sale_date && $object->sale_date != $object->invoice_date) {
    print '<tr><td>' . $langs->trans("KSEF_SaleDate") . '</td><td>' . dol_print_date($object->sale_date, 'day') . '</td></tr>';
}
if ($object->payment_due_date) {
    print '<tr><td>' . $langs->trans("DateMaxPayment") . '</td><td>' . dol_print_date($object->payment_due_date, 'day') . '</td></tr>';
}
if ($object->payment_method && (empty($object->payment_status) || $object->payment_status === 'unpaid')) {
    print '<tr><td>' . $langs->trans("PaymentMode") . '</td><td>' . dol_escape_htmltag($parser->getPaymentMethodDescription($object->payment_method)) . '</td></tr>';
}
if ($object->bank_account) {
    print '<tr><td>' . $langs->trans("BankAccount") . '</td><td>' . dol_escape_htmltag($object->bank_account) . '</td></tr>';
}
ksefPrintCardPaymentRows($object);

print '<tr><td>' . $langs->trans("Customer") . '</td><td>';
print '<strong>' . dol_escape_htmltag($party['name']) . '</strong>';
print '<br><span class="opacitymedium">';
print $party['nip'] !== '' ? 'NIP: ' . ksefFormatNIP($party['nip']) : '';
print $party['vat_id'] !== '' ? ($party['nip'] !== '' ? '<br>' : '') . 'VAT: ' . dol_escape_htmltag($party['vat_id']) : '';
print $party['address'] !== '' ? '<br>' . ksefHtmlMultiline($party['address']) . ', ' . dol_escape_htmltag($party['country']) : '';
print '</span></td></tr>';
if ($buyerChange) {
    print '<tr class="ksef-sales-buyer-now"><td>' . $langs->trans('KSEF_SalesBuyerNow') . '</td><td>' . ksefBuyerSideHtml($buyerChange['dolibarr'])
        . ($buyerChange['changed'] ? '<br>' . ksefBuyerChangedNote($buyerChange['changed_what']) : '') . '</td></tr>';
}

print '<tr><td>' . $langs->trans("KSEF_ENVIRONMENT") . '</td><td>' . ksefGetEnvironmentBadge($object->environment) . '</td></tr>';
print '<tr><td>' . $langs->trans("KSEF_FetchedOn") . '</td><td>' . dol_print_date($object->fetch_date, 'dayhour') . '</td></tr>';

print '</table>';
print '</div>';

print '<div class="fichehalfright">';
print '<div class="underbanner clearboth"></div>';
ksefPrintImportAmounts($object);
ksefPrintCardVatSummary($object);
print '</div>';

print '</div>';
print '<div class="clearboth"></div><br>';

ksefPrintCardLines($object);
dol_include_once('/ksef/lib/ksef_kor_view.lib.php');
print ksefKorViewHtml($db, $object);

print dol_get_fiche_end();

print '<div class="tabsAction">';
$parameters = array();
$reshook = $hookmanager->executeHooks('addMoreActionsButtons', $parameters, $object, $action);
if (empty($reshook)) {
    $match = (string) $object->link_status;
    if (!$isFetchError && $object->import_status !== KsefSales::STATUS_SKIPPED) {
        if ($match === KsefSales::LINK_IMPORTABLE) {
            $canImport = ksefUserCan($user, 'import_sales');
            if ($canImport || !ksefHideUnauthorized($user)) {
                print dolGetButtonAction($canImport ? '' : ksefMissingRightText('import_sales'), $langs->trans('KSEF_ImportToDolibarrSales'), 'default', dol_buildpath('/ksef/sales_import.php', 1) . '?id=' . $object->id, '', $canImport);
            }
        } elseif (in_array($match, array(KsefSales::LINK_LINKABLE, KsefSales::LINK_SUGGEST), true) && $candidate) {
            $canLink = ksefUserCan($user, 'link_ksef_number') && ksefUserCanReadInvoice($user, (int) $object->link_candidate);
            if ($canLink || !ksefHideUnauthorized($user)) {
                print dolGetButtonAction($canLink ? '' : ksefMissingRightText('link_ksef_number'), $langs->trans('KSEF_LinkToInvoice'), 'default', $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=link&token=' . newToken(), '', $canLink);
            }
        } elseif ($match === KsefSales::LINK_DIFFERS && !empty($object->fk_facture)) {
            $canAdjust = ksefUserCan($user, 'adjust_invoice') && ksefUserCanReadInvoice($user, (int) $object->fk_facture);
            if ($canAdjust || !ksefHideUnauthorized($user)) {
                print dolGetButtonAction($canAdjust ? '' : ksefMissingRightText('adjust_invoice'), $langs->trans('KSEF_AdjustToKsef'), 'default', dol_buildpath('/ksef/sales_adjust.php', 1) . '?id=' . $object->id, '', $canAdjust);
            }
        }
    }
    if ($isFetchError && $usercanwrite) {
        if (!empty($object->fa3_xml)) {
            print '<a class="butAction" href="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=retry_fetch&token=' . newToken() . '" title="' . dol_escape_htmltag($langs->trans('KSEF_RetryFetchHelp')) . '">' . $langs->trans('KSEF_RetryFetch') . '</a>';
        } else {
            print '<span class="butActionRefused classfortooltip" title="' . dol_escape_htmltag($langs->trans('KSEF_RetryFetchNoXml')) . '">' . $langs->trans('KSEF_RetryFetch') . '</span>';
        }
    }
    if (!empty($object->fa3_xml)) {
        print '<a class="butAction" href="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=download_xml&token=' . newToken() . '">' . $langs->trans("KSEF_DownloadXml") . '</a>';
    }
    if ($upoSub) {
        print '<a class="butAction ksef-sales-download-upo classfortooltip" href="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=download_upo&token=' . newToken() . '" title="' . dol_escape_htmltag(ksefUpoDownloadTitle($upoSub)) . '">' . $langs->trans("KSEF_DownloadUPO") . '</a>';
    }
    if (!empty($object->ksef_number) && !empty($object->fa3_xml) && !$isFetchError) {
        $verifyUrl = ksefGetVerificationUrlFromXml($object->ksef_number, $object->fa3_xml, $object->environment ?: ksefGetEnvironment());
        if ($verifyUrl !== '') {
            print '<a class="butAction" href="' . $verifyUrl . '" target="_blank" rel="noopener noreferrer">' . $langs->trans("KSEF_VerifyOnKSEFPortal") . '</a>';
        }
        print '<a class="butAction" href="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=download_pdf&token=' . newToken() . '">' . $langs->trans("KSEF_DownloadPdf") . '</a>';
    }
    if (empty($object->fk_facture) && $usercanwrite) {
        $canDelete = ksefUserCan($user, 'delete_sales');
        if ($canDelete || !ksefHideUnauthorized($user)) {
            print dolGetButtonAction($canDelete ? '' : ksefMissingRightText('delete_sales'), $langs->trans('Delete'), 'delete', $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=delete&token=' . newToken(), '', $canDelete);
        }
    }
}
print '</div>';

print ksefHelpFooter($db, $user);
llxFooter();
$db->close();
