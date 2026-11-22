<?php
/* Copyright (C) 2002-2005  Rodolphe Quiedeville    <rodolphe@quiedeville.org>
 * Copyright (C) 2004-2020	Laurent Destailleur 	<eldy@users.sourceforge.net>
 * Copyright (C) 2004		Christophe Combelles	<ccomb@free.fr>
 * Copyright (C) 2005		Marc Barilley			<marc@ocebo.fr>
 * Copyright (C) 2005-2013	Regis Houssin			<regis.houssin@inodbox.com>
 * Copyright (C) 2010-2023	Juanjo Menent			<jmenent@simnandez.es>
 * Copyright (C) 2013-2022	Philippe Grand			<philippe.grand@atoo-net.com>
 * Copyright (C) 2013		Florian Henry			<florian.henry@open-concept.pro>
 * Copyright (C) 2014-2016  Marcos García			<marcosgdf@gmail.com>
 * Copyright (C) 2016-2025	Alexandre Spangaro		<alexandre@inovea-conseil.com>
 * Copyright (C) 2018-2025  Frédéric France         <frederic.france@free.fr>
 * Copyright (C) 2019       Ferran Marcet	        <fmarcet@2byte.es>
 * Copyright (C) 2022       Gauthier VERDOL         <gauthier.verdol@atm-consulting.fr>
 * Copyright (C) 2023		Nick Fragoulis
 * Copyright (C) 2024-2025	MDW						<mdeweerd@users.noreply.github.com>
 * Copyright (C) 2026       InPoint Automation Sp z o.o.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    ksef/incoming_card.php
 * \ingroup ksef
 * \brief   Incoming invoice card
 *
 *  Based on htdocs/fourn/facture/card.php from Dolibarr
 *  see https://github.com/Dolibarr/dolibarr/blob/develop/htdocs/fourn/facture/card.php
 */

if (!defined('CSRFCHECK_WITH_TOKEN')) {
    define('CSRFCHECK_WITH_TOKEN', '1');
}

$action_raw = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
if (in_array($action_raw, array('download_xml', 'view_xml', 'download_pdf'))) {
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
require_once DOL_DOCUMENT_ROOT . '/core/class/html.formfile.class.php';
require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
dol_include_once('/ksef/class/ksef_incoming.class.php');
dol_include_once('/ksef/class/fa3_parser.class.php');
dol_include_once('/ksef/lib/ksef.lib.php');
dol_include_once('/ksef/lib/ksef_tour.lib.php');
dol_include_once('/ksef/lib/ksef_import_ui.lib.php');

$langs->loadLangs(array("ksef@ksef", "bills", "companies", "suppliers", "banks"));

if (!$user->hasRight('ksef', 'read') || !empty($user->socid)) accessforbidden();

$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');

if (empty($id)) {
    header('Location: ' . dol_buildpath('/ksef/incoming_list.php', 1) . '?restore_lastsearch_values=1');
    exit;
}

$object = new KsefIncoming($db);
if ($object->fetch($id) <= 0) {
    accessforbidden('Record not found');
}

$parser = new FA3Parser($db);
$form = new Form($db);

$usercanread = $user->hasRight('ksef', 'read');
$usercanwrite = $user->hasRight('ksef', 'write');

$hookmanager->initHooks(array('ksef_incomingcard', 'globalcard'));

if ($action == 'download_xml') {
    if (!empty($object->fa3_xml)) {
        ksefSendDownload($object->fa3_xml, ksefDownloadName('FA3', $object->ksef_number, 'incoming_' . $object->id, 'xml'), 'application/xml');
        exit;
    }
    setEventMessages($langs->trans('KSEF_XMLNotAvailable'), null, 'warnings');
}

if ($action == 'download_pdf') {
    if (!empty($object->rowid)) {
        $pdfContent = $object->generatePdfVisualization();

        if ($pdfContent !== false) {
            ksefSendDownload($pdfContent, ksefDownloadName('FA3', $object->ksef_number, 'incoming_' . $object->id, 'pdf'), 'application/pdf');
            exit;
        } else {
            setEventMessages($object->error, null, 'errors');
        }
    }
}

if ($action == 'retry_fetch' && $usercanwrite) {
    $result = $object->reprocessFromXml($user);
    if ($result > 0) {
        setEventMessages($langs->trans('KSEF_RetryFetchDone'), null, 'mesgs');
        header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
        exit;
    }
    setEventMessages($object->error, null, $result == 0 ? 'warnings' : 'errors');
    $object->fetch($object->id);
}

if ($action == 'confirm_delete' && $confirm == 'yes' && ksefUserCan($user, 'delete_incoming')) {
    $result = $object->delete($user);
    if ($result > 0) {
        setEventMessages($langs->trans("RecordDeleted"), null, 'mesgs');
        header('Location: ' . dol_buildpath('/ksef/incoming_list.php', 1) . '?restore_lastsearch_values=1');
        exit;
    } else {
        setEventMessages($object->error, $object->errors, 'errors');
    }
}

$title = $langs->trans('KSEF_IncomingInvoice') . ' - ' . $object->invoice_number;
$help_url = ksefHelpUrl('receiving');

llxHeader('', $title, $help_url, '', 0, 0, '', '', '', 'mod-ksef page-incoming-card');

$head = ksef_incoming_prepare_head($object);

print dol_get_fiche_head($head, 'card', $langs->trans('KSEF_IncomingInvoice'), -1, 'supplier_invoice');

$formconfirm = '';

if ($action == 'delete' && ksefUserCan($user, 'delete_incoming')) {
    $formconfirm = $form->formconfirm(
        $_SERVER["PHP_SELF"] . '?id=' . $object->id,
        $langs->trans('Delete'),
        ksefTransHtml('ConfirmDeleteIncomingInvoice', array(ksefEscapeText($object->invoice_number)))
            . ($object->import_status === KsefIncoming::STATUS_FETCH_ERROR ? '<br>' . $langs->trans(!empty($object->fa3_xml) ? 'KSEF_FetchErrorDeleteNote' : 'KSEF_FetchErrorDeleteNoteNoXml') : '')
            . ($object->import_status !== KsefIncoming::STATUS_IMPORTED ? '<br>' . $langs->trans('KSEF_DeleteNotRefetched') : ''),
        'confirm_delete',
        '',
        0,
        1
    );
}

$parameters = array('formConfirm' => $formconfirm);
$reshook = $hookmanager->executeHooks('formConfirm', $parameters, $object, $action);
if (empty($reshook)) {
    $formconfirm .= $hookmanager->resPrint;
} elseif ($reshook > 0) {
    $formconfirm = $hookmanager->resPrint;
}

print $formconfirm;

$linkback = '<a href="' . dol_buildpath('/ksef/incoming_list.php', 1) . '?restore_lastsearch_values=1">' . $langs->trans("BackToList") . '</a>';

$morehtmlref = '<div class="refidno">';
$morehtmlref .= $form->editfieldkey("RefSupplierBill", 'ref_supplier', $object->invoice_number, $object, 0, 'string', '', 0, 1);
$morehtmlref .= $form->editfieldval("RefSupplierBill", 'ref_supplier', $object->invoice_number, $object, 0, 'string', '', null, null, '', 1);
$morehtmlref .= '<br>' . $langs->trans("Supplier") . ': <strong>' . dol_escape_htmltag($object->seller_name) . '</strong>';
if (!empty($object->seller_nip)) {
    $morehtmlref .= ' <span class="opacitymedium">(NIP: ' . ksefFormatNIP($object->seller_nip) . ')</span>';
}
if (!empty($object->seller_vat_id)) {
    $morehtmlref .= ' <span class="opacitymedium">(VAT: ' . dol_escape_htmltag($object->seller_vat_id) . ')</span>';
}
$morehtmlref .= '</div>';

dol_banner_tab($object, 'id', $linkback, 1, 'rowid', 'ksef_number', $morehtmlref);

$linkedInvoiceExists = false;
$parameters = array();
$reshook = $hookmanager->executeHooks('tabContentViewKsefIncoming', $parameters, $object, $action);
if (empty($reshook)) {
    print '<div class="fichecenter">';
    print '<div class="fichehalfleft">';
    print '<div class="underbanner clearboth"></div>';

    print '<table class="border tableforfield centpercent">';

    print '<tr><td class="titlefield">' . $langs->trans('Type') . '</td><td>';
    if ((string) $object->invoice_type === '') {
        print '<span class="opacitymedium">-</span>';
    } else {
        print '<span class="badgeneutral">';
        print ksefGetInvoiceTypeBadge($object->invoice_type);
        print '</span>';
    }
    if ((string) $object->invoice_type !== '' && ksefInvoiceTypeLabel($object->invoice_type) !== '') {
        print ' <span class="opacitymedium">(' . dol_escape_htmltag(ksefInvoiceTypeLabel($object->invoice_type)) . ')</span>';
    }
    print '</td></tr>';
    if ($object->invoice_type === 'ROZ') {
        ksefPrintIncomingDepositsRow($object, $object->fk_facture_fourn > 0 ? 0 : $object->findMatchingThirdParty());
    }

    if ($object->import_status == KsefIncoming::STATUS_FETCH_ERROR || ($object->import_status == KsefIncoming::STATUS_ERROR && !empty($object->import_error))) {
        $errText = $object->import_status == KsefIncoming::STATUS_FETCH_ERROR ? $object->getFetchErrorText() : (string) $object->import_error;
        print '<tr><td>' . ksefGetIncomingStatusBadge($object->import_status, $object->import_error) . '</td><td>';
        print '<span class="error">' . ksefEscapeText($errText) . '</span>';
        if ($object->import_status == KsefIncoming::STATUS_FETCH_ERROR) {
            print '<br><span class="opacitymedium small">' . $langs->trans(!empty($object->fa3_xml) ? 'KSEF_FetchErrorKept' : 'KSEF_FetchErrorNoXml') . '</span>';
        }
        print '</td></tr>';
    }

    $correctionData = KsefIncoming::isCorrectionType($object->invoice_type) ? $object->getCorrectionData() : null;
    $resolvedCorrections = KsefIncoming::isCorrectionType($object->invoice_type) ? $object->resolveCorrectedInvoices() : array();
    $correctedCount = count($resolvedCorrections);

    if ($correctedCount == 1) {
        $rc = $resolvedCorrections[0];
        $isSelfReference = (!empty($rc['ksef_number']) && $rc['ksef_number'] === $object->ksef_number);
        print '<tr><td>' . $langs->trans("KSEF_CorrectsInvoice") . '</td><td>';

        print $langs->trans("KSEF_CorrectsKsefNr") . ': ';
        if ($rc['incoming']) {
            print $rc['incoming']->getNomUrl(1);
        } elseif (!empty($rc['ksef_number'])) {
            print dol_escape_htmltag($rc['ksef_number']);
        } else {
            print '<span class="opacitymedium">-</span>';
        }
        if ($isSelfReference) {
            print '<br><span class="warning"><span class="fa fa-exclamation-triangle"></span> ' . $langs->trans("KSEF_CorrectedKsefNumberSameAsOwn") . '</span>';
        }

        if (!empty($rc['invoice_number'])) {
            print '<br>' . $langs->trans("KSEF_VendorRef") . ': ';
            print dol_escape_htmltag($rc['invoice_number']);
        }

        if ($rc['supplier_invoice']) {
            print '<br>' . $langs->trans("KSEF_ImportedAs") . ': ';
            print $rc['supplier_invoice']->getNomUrl(1) . ' ' . ksefInvoiceStatusBadge($rc['supplier_invoice'], 5);
        }

        print '</td></tr>';
    } elseif ($correctedCount > 1) {
        print '<tr><td>' . $langs->trans("KSEF_CorrectedInvoices") . '</td><td>';
        print $langs->trans("KSEF_CorrectsXInvoices", $correctedCount);
        print '</td></tr>';
    }

    if (!empty($correctionData['reason'])) {
        print '<tr><td>' . $langs->trans("KSEF_CorrectionReason") . '</td>';
        print '<td>' . ksefHtmlMultiline($correctionData['reason']) . '</td></tr>';
    }

    $linkedInvoiceExists = false;
    $creditNoteExists = false;
    $creditNoteInvoice = null;

    if ($object->fk_credit_note > 0) {
        $creditNoteInvoice = new FactureFournisseur($db);
        if ($creditNoteInvoice->fetch($object->fk_credit_note) > 0) {
            $creditNoteExists = true;
        }
    }

    if ($object->fk_facture_fourn > 0) {
        $linkedInvoice = new FactureFournisseur($db);
        if ($linkedInvoice->fetch($object->fk_facture_fourn) > 0) {
            $linkedInvoiceExists = true;
        }
    }

    $isReplaceMode = ($creditNoteExists && $linkedInvoiceExists);

    if ($isReplaceMode) {
                print '<tr><td>' . $langs->trans("KSEF_ImportMode") . '</td>';
        print '<td><span class="badgeneutral">' . $langs->trans("KSEF_UpwardCorrectionReplace") . '</span></td></tr>';
    } elseif ($object->fk_facture_fourn > 0) {
        if ($linkedInvoiceExists) {
            print '<tr><td>' . $langs->trans("KSEF_LinkedSupplierInvoice") . '</td>';
            print '<td>' . $linkedInvoice->getNomUrl(1) . ' ' . ksefInvoiceStatusBadge($linkedInvoice, 5) . '</td></tr>';
        } else {
            print '<tr><td>' . $langs->trans("KSEF_LinkedSupplierInvoice") . '</td>';
            print '<td><span class="warning"><span class="fa fa-exclamation-triangle"></span> ' . $langs->trans("KSEF_LinkedInvoiceDeleted") . '</span></td></tr>';
        }

        if ($creditNoteExists) {
            print '<tr><td>' . $langs->trans("KSEF_ReplaceCreditNote") . '</td>';
            print '<td>' . $creditNoteInvoice->getNomUrl(1) . ' ' . ksefInvoiceStatusBadge($creditNoteInvoice, 5) . '</td></tr>';
        }
    }

    print '<tr>';
    print '<td>' . $langs->trans("Label") . '</td>';
    print '<td>' . dol_escape_htmltag($object->invoice_number) . '</td>';
    print '</tr>';

    print '<tr><td>' . $langs->trans("DateInvoice") . '</td>';
    print '<td>' . dol_print_date($object->invoice_date, 'day') . '</td></tr>';

    if ($object->sale_date && $object->sale_date != $object->invoice_date) {
        print '<tr><td>' . $langs->trans("KSEF_SaleDate") . '</td>';
        print '<td>' . dol_print_date($object->sale_date, 'day') . '</td></tr>';
    }

    if ($object->payment_due_date) {
        print '<tr><td>' . $langs->trans("DateMaxPayment") . '</td>';
        print '<td>' . dol_print_date($object->payment_due_date, 'day') . '</td></tr>';
    }
    $xmlPayRows = ksefPaymentTermsAccountsRows($object->fa3_xml);
    print $xmlPayRows['terms'];

    if ($object->payment_method && (empty($object->payment_status) || $object->payment_status === 'unpaid')) {
        print '<tr><td>' . $langs->trans("PaymentMode") . '</td>';
        print '<td>' . dol_escape_htmltag($parser->getPaymentMethodDescription($object->payment_method)) . '</td></tr>';
    }

    if ($xmlPayRows['accounts'] !== '') {
        print $xmlPayRows['accounts'];
    } elseif ($object->bank_account) {
        print '<tr><td>' . $langs->trans("BankAccount") . '</td>';
        print '<td>' . dol_escape_htmltag($object->bank_account) . '</td></tr>';
    }

    ksefPrintCardPaymentRows($object);

    print '<tr><td>' . $langs->trans("KSEF_Seller") . '</td><td>';
    print '<strong>' . dol_escape_htmltag($object->seller_name) . '</strong>';
    print '<br><span class="opacitymedium">';
    if (!empty($object->seller_nip)) {
        print 'NIP: ' . ksefFormatNIP($object->seller_nip);
    }
    if (!empty($object->seller_vat_id)) {
        if (!empty($object->seller_nip)) print '<br>';
        print 'VAT: ' . dol_escape_htmltag($object->seller_vat_id);
    }
    if ($object->seller_address) {
        print '<br>' . ksefHtmlMultiline($object->seller_address);
    }
    if ($object->seller_country) {
        print ', ' . dol_escape_htmltag($object->seller_country);
    }
    print '</span>';
    print '</td></tr>';

    print '<tr><td>' . $langs->trans("KSEF_ENVIRONMENT") . '</td>';
    print '<td>' . ksefGetEnvironmentBadge($object->environment) . '</td></tr>';

    print '<tr><td>' . $langs->trans("KSEF_FetchedOn") . '</td>';
    print '<td>' . dol_print_date($object->fetch_date, 'dayhour') . '</td></tr>';

    print '<tr><td>' . $langs->trans("Status") . '</td>';
    print '<td>' . $object->getLibStatut(5) . '</td></tr>';

    print '</table>';

    print '</div>';

    print '<div class="fichehalfright">';
    print '<div class="underbanner clearboth"></div>';

    print '<table class="border tableforfield centpercent">';

    print '<tr><td><table class="nobordernopadding centpercent"><tr><td>' . $langs->trans("Currency") . '</td></tr></table></td>';
    print '<td>' . dol_escape_htmltag($object->currency);
    print '</td></tr>';

    print '<tr>';
    print '<td class="titlefieldmiddle">' . $langs->trans('AmountHT') . '</td>';
    print '<td class="nowrap amountcard right">' . ($object->total_net === null || $object->total_net === '' ? '-' : price($object->total_net, 0, $langs, 0, -1, -1, $object->currency)) . '</td>';
    print '</tr>';

    print '<tr>';
    print '<td>' . $langs->trans('AmountVAT') . '</td>';
    print '<td class="nowrap amountcard right">' . ($object->total_vat === null || $object->total_vat === '' ? '-' : price($object->total_vat, 0, $langs, 0, -1, -1, $object->currency)) . '</td>';
    print '</tr>';

    print '<tr>';
    print '<td>' . $langs->trans('AmountTTC') . '</td>';
    print '<td class="nowrap amountcard right">' . ($object->total_gross === null || $object->total_gross === '' ? '-' : price($object->total_gross, 0, $langs, 0, -1, -1, $object->currency)) . '</td>';
    print '</tr>';

    print '</table>';

    ksefPrintCardVatSummary($object);

    if ($correctedCount > 1) {
        print '<div class="div-table-responsive-no-min">';
        print '<table class="noborder paymenttable centpercent">';

        print '<tr class="liste_titre">';
        print '<td class="liste_titre">' . $langs->trans("KSEF_CorrectsKsefNr") . '</td>';
        print '<td class="liste_titre">' . $langs->trans("KSEF_VendorRef") . '</td>';
        print '<td class="center">' . $langs->trans("Date") . '</td>';
        print '<td class="right">' . $langs->trans("KSEF_ImportedAs") . '</td>';
        print '</tr>';

        foreach ($resolvedCorrections as $rc) {
            $isSelfReference = (!empty($rc['ksef_number']) && $rc['ksef_number'] === $object->ksef_number);
            print '<tr class="oddeven">';

            print '<td class="tdoverflowmax200">';
            if ($rc['incoming']) {
                print $rc['incoming']->getNomUrl(1);
            } elseif (!empty($rc['ksef_number'])) {
                print dol_escape_htmltag($rc['ksef_number']);
            } else {
                print '<span class="opacitymedium">-</span>';
            }
            if ($isSelfReference) {
                print ' <span class="warning"><span class="fa fa-exclamation-triangle" title="' . dol_escape_htmltag($langs->trans("KSEF_CorrectedKsefNumberSameAsOwn")) . '"></span></span>';
            }
            print '</td>';

            print '<td>';
            if (!empty($rc['invoice_number'])) {
                print dol_escape_htmltag($rc['invoice_number']);
            } else {
                print '<span class="opacitymedium">-</span>';
            }
            print '</td>';

            print '<td class="center nowraponall">';
            if (!empty($rc['invoice_date'])) {
                print dol_print_date(strtotime($rc['invoice_date']), 'day');
            }
            print '</td>';

            print '<td class="right">';
            if ($rc['supplier_invoice']) {
                print $rc['supplier_invoice']->getNomUrl(1) . ' ' . ksefInvoiceStatusBadge($rc['supplier_invoice'], 3);
            } else {
                print '<span class="opacitymedium">-</span>';
            }
            print '</td>';

            print '</tr>';
        }

        print '</table>';
        print '</div>';
    }

    print '</div>';

    print '</div>';

    print '<div class="clearboth"></div><br>';

    if ($isReplaceMode) {
        $originalInv = null;
        if ($creditNoteInvoice->fk_facture_source > 0) {
            $originalInv = new FactureFournisseur($db);
            if ($originalInv->fetch($creditNoteInvoice->fk_facture_source) <= 0) {
                $originalInv = null;
            }
        }

        ksefPrintSupplierInvoiceCard(
            $creditNoteInvoice,
            $langs->trans("KSEF_ReplaceCreditNote"),
            'fa-minus-circle',
            '#bc3434',
            $langs->trans("KSEF_ReplaceCreditNoteCardDesc"),
            $originalInv
        );

        ksefPrintSupplierInvoiceCard(
            $linkedInvoice,
            $langs->trans("KSEF_ReplaceNewInvoice"),
            'fa-plus-circle',
            '#46a546',
            $langs->trans("KSEF_ReplaceNewInvoiceDesc")
        );

        print '<br>';
    }

    ksefPrintCardLines($object);
    dol_include_once('/ksef/lib/ksef_kor_view.lib.php');
    print ksefKorViewHtml($db, $object);
}

print dol_get_fiche_end();

print '<div class="tabsAction">';

$parameters = array();
$reshook = $hookmanager->executeHooks('addMoreActionsButtons', $parameters, $object, $action);
if (empty($reshook)) {
    $importRights = ksefIncomingImportRights($user);
    if (($object->import_status == 'NEW' || $object->import_status == 'ERROR') && $usercanwrite && !$importRights['import']) {
        if (!ksefHideUnauthorized($user)) {
            print dolGetButtonAction(ksefMissingRightText('import_incoming'), $langs->trans("KSEF_ImportToDolibarr"), 'default', dol_buildpath('/ksef/incoming_import.php', 1) . '?id=' . $object->id, '', false);
        }
    } elseif (($object->import_status == 'NEW' || $object->import_status == 'ERROR') && $usercanwrite) {
        print '<a class="butAction" href="' . dol_buildpath('/ksef/incoming_import.php', 1) . '?id=' . $object->id . '">' . $langs->trans("KSEF_ImportToDolibarr") . '</a>';
    } elseif ($object->import_status == 'IMPORTED' && !$linkedInvoiceExists && $usercanwrite) {
        print '<a class="butAction" href="' . dol_buildpath('/ksef/incoming_import.php', 1) . '?id=' . $object->id . '">' . $langs->trans("KSEF_ResetImportStatus") . '</a>';
    } elseif ($object->import_status == 'IMPORTED') {
        print '<span class="butActionRefused classfortooltip" title="' . dol_escape_htmltag($langs->trans("KSEF_AlreadyImported")) . '">' . $langs->trans("KSEF_ImportToDolibarr") . '</span>';
    } elseif ($object->import_status == KsefIncoming::STATUS_FETCH_ERROR) {
        print '<span class="butActionRefused classfortooltip" title="' . dol_escape_htmltag($langs->trans("KSEF_FetchErrorNotImportable")) . '">' . $langs->trans("KSEF_ImportToDolibarr") . '</span>';
    }
    $isFetchError = ($object->import_status == KsefIncoming::STATUS_FETCH_ERROR);

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

    if (!empty($object->ksef_number) && !empty($object->fa3_xml) && !$isFetchError) {
        $objEnvironment = !empty($object->environment) ? $object->environment : ksefGetEnvironment();
        $verifyUrl = ksefGetVerificationUrlFromXml($object->ksef_number, $object->fa3_xml, $objEnvironment);
        print '<a class="butAction" href="' . $verifyUrl . '" target="_blank" rel="noopener noreferrer">' . $langs->trans("KSEF_VerifyOnKSEFPortal") . '</a>';
    }

    if (!empty($object->fa3_xml) && !$isFetchError) {
        print '<a class="butAction" href="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=download_pdf&token=' . newToken() . '">' . $langs->trans("KSEF_DownloadPdf") . '</a>';
    }

    if ($object->canDelete() && $usercanwrite) {
        $canDelete = ksefUserCan($user, 'delete_incoming');
        if ($canDelete || !ksefHideUnauthorized($user)) {
            print dolGetButtonAction($canDelete ? '' : ksefMissingRightText('delete_incoming'), $langs->trans('Delete'), 'delete', $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=delete&token=' . newToken(), '', $canDelete);
        }
    }
}

print '</div>';

print ksefHelpFooter($db, $user);
llxFooter();
$db->close();