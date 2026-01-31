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
 * \brief   Card view for KSeF incoming invoice
 *
 * Based on htdocs/fourn/facture/card.php from Dolibarr
 * see https://github.com/Dolibarr/dolibarr/blob/develop/htdocs/fourn/facture/card.php
 */

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

$langs->loadLangs(array("ksef@ksef", "bills", "companies", "suppliers"));

// Security check
if (!$user->hasRight('ksef', 'read')) accessforbidden();

// Get parameters
$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');

if (empty($id)) {
    header('Location: ' . dol_buildpath('/ksef/incoming_list.php', 1));
    exit;
}

// Initialize objects
$object = new KsefIncoming($db);
if ($object->fetch($id) <= 0) {
    accessforbidden('Record not found');
}

$parser = new FA3Parser($db);
$form = new Form($db);

// Permissions
$usercanread = $user->hasRight('ksef', 'read');
$usercanwrite = $user->hasRight('ksef', 'write');

// Initialize a technical object to manage hooks
$hookmanager->initHooks(array('ksef_incomingcard', 'globalcard'));


/*
 * Actions
 */

// Download XML
if ($action == 'download_xml') {
    if (!empty($object->fa3_xml)) {
        $filename = 'FA3_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $object->ksef_number) . '.xml';
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $object->fa3_xml;
        exit;
    }
}

// View raw XML
if ($action == 'view_xml') {
    if (!empty($object->fa3_xml)) {
        header('Content-Type: application/xml; charset=utf-8');
        echo $object->fa3_xml;
        exit;
    }
}

// Download PDF visualization
if ($action == 'download_pdf') {
    if (!empty($object->rowid) && !empty($object->fa3_xml)) {
        dol_include_once('/ksef/class/ksef_invoice_pdf.class.php');
        $pdfGenerator = new KsefInvoicePdf($db);
        $pdfContent = $pdfGenerator->generate($object);

        if ($pdfContent !== false) {
            $filename = 'FA3_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $object->ksef_number) . '.pdf';
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($pdfContent));
            echo $pdfContent;
            exit;
        } else {
            setEventMessages($pdfGenerator->error, $pdfGenerator->errors, 'errors');
        }
    }
}

// Delete record
if ($action == 'confirm_delete' && $confirm == 'yes' && $usercanwrite) {
    $result = $object->delete($user);
    if ($result > 0) {
        setEventMessages($langs->trans("RecordDeleted"), null, 'mesgs');
        header('Location: ' . dol_buildpath('/ksef/incoming_list.php', 1));
        exit;
    } else {
        setEventMessages($object->error, $object->errors, 'errors');
    }
}


/*
 * View
 */

$title = $langs->trans('KSEF_IncomingInvoice') . ' - ' . $object->invoice_number;
$help_url = '';

llxHeader('', $title, $help_url, '', 0, 0, '', '', '', 'mod-ksef page-incoming-card');


// Prepare tabs
$head = ksef_incoming_prepare_head($object);

print dol_get_fiche_head($head, 'card', $langs->trans('KSEF_IncomingInvoice'), -1, 'supplier_invoice');


/*
 * Confirmation dialogs
 */

$formconfirm = '';

if ($action == 'delete') {
    $formconfirm = $form->formconfirm(
        $_SERVER["PHP_SELF"] . '?id=' . $object->id,
        $langs->trans('Delete'),
        $langs->trans('ConfirmDeleteIncomingInvoice', $object->invoice_number),
        'confirm_delete',
        '',
        0,
        1
    );
}

// Call Hook formConfirm
$parameters = array('formConfirm' => $formconfirm);
$reshook = $hookmanager->executeHooks('formConfirm', $parameters, $object, $action);
if (empty($reshook)) {
    $formconfirm .= $hookmanager->resPrint;
} elseif ($reshook > 0) {
    $formconfirm = $hookmanager->resPrint;
}

// Print form confirm
print $formconfirm;


/*
 * Banner
 */

$linkback = '<a href="' . dol_buildpath('/ksef/incoming_list.php', 1) . '?restore_lastsearch_values=1">' . $langs->trans("BackToList") . '</a>';

$morehtmlref = '<div class="refidno">';
// Ref supplier (invoice number from supplier)
$morehtmlref .= $form->editfieldkey("RefSupplierBill", 'ref_supplier', $object->invoice_number, $object, 0, 'string', '', 0, 1);
$morehtmlref .= $form->editfieldval("RefSupplierBill", 'ref_supplier', $object->invoice_number, $object, 0, 'string', '', null, null, '', 1);
// Thirdparty (seller)
$morehtmlref .= '<br>' . $langs->trans("Supplier") . ': <strong>' . dol_escape_htmltag($object->seller_name) . '</strong>';
if (!empty($object->seller_nip)) {
    $morehtmlref .= ' <span class="opacitymedium">(NIP: ' . ksefFormatNIP($object->seller_nip) . ')</span>';
}
$morehtmlref .= '</div>';

dol_banner_tab($object, 'id', $linkback, 1, 'rowid', 'ksef_number', $morehtmlref);


/*
 * Card body
 */

// Call Hook tabContentViewKsefIncoming
$parameters = array();
$reshook = $hookmanager->executeHooks('tabContentViewKsefIncoming', $parameters, $object, $action);
if (empty($reshook)) {
    print '<div class="fichecenter">';
    print '<div class="fichehalfleft">';
    print '<div class="underbanner clearboth"></div>';

    print '<table class="border tableforfield centpercent">';

    // Type
    print '<tr><td class="titlefield">' . $langs->trans('Type') . '</td><td>';
    print '<span class="badgeneutral">';
    print ksefGetInvoiceTypeBadge($object->invoice_type);
    print '</span>';
    if (!empty($parser->getInvoiceTypeDescription($object->invoice_type))) {
        print ' <span class="opacitymedium">(' . dol_escape_htmltag($parser->getInvoiceTypeDescription($object->invoice_type)) . ')</span>';
    }
    // Correction reference (if KOR)
    if ($object->invoice_type == 'KOR' && ($object->corrected_invoice_number || $object->corrected_ksef_number)) {
        print ' <span class="opacitymediumbycolor paddingleft">' . $langs->trans("KSEF_CorrectsInvoice") . ': ';
        if ($object->corrected_invoice_number) {
            print dol_escape_htmltag($object->corrected_invoice_number);
        }
        if ($object->corrected_ksef_number) {
            $origIncoming = new KsefIncoming($db);
            if ($origIncoming->fetch(0, $object->corrected_ksef_number) > 0) {
                print ' ' . $origIncoming->getNomUrl(1);
            } else {
                print ' ' . dol_escape_htmltag($object->corrected_ksef_number);
            }
        }
        print '</span>';
    }
    print '</td></tr>';

    // Linked Dolibarr invoice
    if ($object->fk_facture_fourn > 0) {
        $linkedInvoice = new FactureFournisseur($db);
        if ($linkedInvoice->fetch($object->fk_facture_fourn) > 0) {
            print '<tr><td>' . $langs->trans("LinkedSupplierInvoice") . '</td>';
            print '<td>' . $linkedInvoice->getNomUrl(1) . ' ' . $linkedInvoice->getLibStatut(5) . '</td></tr>';
        }
    }

    // Label (Invoice number)
    print '<tr>';
    print '<td>' . $langs->trans("Label") . '</td>';
    print '<td>' . dol_escape_htmltag($object->invoice_number) . '</td>';
    print '</tr>';

    // Invoice Date
    print '<tr><td>' . $langs->trans("DateInvoice") . '</td>';
    print '<td>' . dol_print_date($object->invoice_date, 'day') . '</td></tr>';

    // Sale Date (if different)
    if ($object->sale_date && $object->sale_date != $object->invoice_date) {
        print '<tr><td>' . $langs->trans("KSEF_SaleDate") . '</td>';
        print '<td>' . dol_print_date($object->sale_date, 'day') . '</td></tr>';
    }

    // Payment Due Date
    if ($object->payment_due_date) {
        print '<tr><td>' . $langs->trans("DateMaxPayment") . '</td>';
        print '<td>' . dol_print_date($object->payment_due_date, 'day') . '</td></tr>';
    }

    // Payment Method
    if ($object->payment_method) {
        print '<tr><td>' . $langs->trans("PaymentMode") . '</td>';
        print '<td>' . dol_escape_htmltag($parser->getPaymentMethodDescription($object->payment_method)) . '</td></tr>';
    }

    // Bank Account
    if ($object->bank_account) {
        print '<tr><td>' . $langs->trans("BankAccount") . '</td>';
        print '<td>' . dol_escape_htmltag($object->bank_account) . '</td></tr>';
    }

    // Seller info
    print '<tr><td>' . $langs->trans("KSEF_Seller") . '</td><td>';
    print '<strong>' . dol_escape_htmltag($object->seller_name) . '</strong>';
    print '<br><span class="opacitymedium">';
    print 'NIP: ' . ksefFormatNIP($object->seller_nip);
    if ($object->seller_address) {
        print '<br>' . dol_escape_htmltag($object->seller_address);
    }
    if ($object->seller_country) {
        print ', ' . dol_escape_htmltag($object->seller_country);
    }
    print '</span>';
    print '</td></tr>';

    // KSeF Environment
    print '<tr><td>' . $langs->trans("KSEF_Environment") . '</td>';
    print '<td>' . ksefGetEnvironmentBadge($object->environment) . '</td></tr>';

    // Fetched On
    print '<tr><td>' . $langs->trans("KSEF_FetchedOn") . '</td>';
    print '<td>' . dol_print_date($object->fetch_date, 'dayhour') . '</td></tr>';

    // Import Status
    print '<tr><td>' . $langs->trans("Status") . '</td>';
    print '<td>' . $object->getLibStatut(5) . '</td></tr>';

    print '</table>';

    print '</div>'; // fichehalfleft


    /*
     * Right column - Amounts
     */

    print '<div class="fichehalfright">';
    print '<div class="underbanner clearboth"></div>';

    print '<table class="border tableforfield centpercent">';

    // Currency row
    print '<tr><td><table class="nobordernopadding centpercent"><tr><td>' . $langs->trans("Currency") . '</td></tr></table></td>';
    print '<td>' . dol_escape_htmltag($object->currency);
    // Add currency name if we can get it
    if ($object->currency == 'PLN') {
        print ' - Poland Zloty';
    }
    print '</td></tr>';

    // Amount (excl. tax)
    print '<tr>';
    print '<td class="titlefieldmiddle">' . $langs->trans('AmountHT') . '</td>';
    print '<td class="nowrap amountcard right">' . price($object->total_net, 0, $langs, 0, -1, -1, $object->currency) . '</td>';
    print '</tr>';

    // Amount tax
    print '<tr>';
    print '<td>' . $langs->trans('AmountVAT') . '</td>';
    print '<td class="nowrap amountcard right">' . price($object->total_vat, 0, $langs, 0, -1, -1, $object->currency) . '</td>';
    print '</tr>';

    // Amount (inc. tax)
    print '<tr>';
    print '<td>' . $langs->trans('AmountTTC') . '</td>';
    print '<td class="nowrap amountcard right">' . price($object->total_gross, 0, $langs, 0, -1, -1, $object->currency) . '</td>';
    print '</tr>';

    print '</table>';


    /*
     * VAT Summary
     */

    $vatSummary = $object->getVatSummary();
    if (!empty($vatSummary)) {
        print '<div class="div-table-responsive-no-min">';
        print '<table class="noborder paymenttable centpercent">';

        print '<tr class="liste_titre">';
        print '<td class="liste_titre">' . $langs->trans("VATRate") . '</td>';
        print '<td class="right"><span class="hideonsmartphone">' . $langs->trans("KSEF_Net") . '</span></td>';
        print '<td class="right">' . $langs->trans("VATAmount") . '</td>';
        print '</tr>';

        foreach ($vatSummary as $rate => $amounts) {
            print '<tr class="oddeven">';
            if ($rate == 'zw') {
                print '<td>' . $langs->trans("KSEF_VATExempt") . '</td>';
                print '<td class="right">' . price($amounts['net'], 0, $langs, 1, -1, -1, $object->currency) . '</td>';
                print '<td class="right">-</td>';
            } else {
                print '<td>' . $rate . '%</td>';
                print '<td class="right">' . price($amounts['net'], 0, $langs, 1, -1, -1, $object->currency) . '</td>';
                print '<td class="right">' . price($amounts['vat'], 0, $langs, 1, -1, -1, $object->currency) . '</td>';
            }
            print '</tr>';
        }

        // Total row
        print '<tr>';
        print '<td colspan="2" class="right"><span class="opacitymedium">' . $langs->trans("Total") . '</span></td>';
        print '<td class="right">' . price($object->total_vat, 0, $langs, 1, -1, -1, $object->currency) . '</td>';
        print '</tr>';

        print '</table>';
        print '</div>';
    }

    print '</div>'; // fichehalfright

    print '</div>'; // fichecenter

    print '<div class="clearboth"></div><br>';


    /*
     * Invoice Line Items
     */

    $lines = $object->getLineItems();
    if (!empty($lines)) {
        print '<div class="div-table-responsive-no-min">';
        print '<table id="tablelines" class="noborder noshadow centpercent">';

        print '<tr class="liste_titre nodrag nodrop">';
        print '<td class="linecoldescription">' . $langs->trans("Description") . '</td>';
        print '<td class="linerefsupplier maxwidth125">' . $langs->trans("VendorSKU") . '</td>';
        print '<td class="linecolvat right nowraponall">' . $langs->trans("VAT") . '</td>';
        print '<td class="linecoluht right nowraponall">' . $langs->trans("PriceUHT") . '</td>';
        print '<td class="linecoluttc right nowraponall">' . $langs->trans("PriceUTTC") . '</td>';
        print '<td class="linecolqty right">' . $langs->trans("Qty") . '</td>';
        print '<td class="linecoldiscount right nowraponall">' . $langs->trans("ReductionShort") . '</td>';
        print '<td class="linecolht right">' . $langs->trans("TotalHT") . '</td>';
        print '</tr>';

        foreach ($lines as $line) {
            // Calculate unit price TTC
            $vatRate = is_numeric($line['vat_rate']) ? (float)$line['vat_rate'] : 0;
            $unitPriceTTC = $line['unit_price'] * (1 + $vatRate / 100);

            print '<tr class="oddeven">';
            print '<td class="linecoldescription minwidth300imp">' . dol_escape_htmltag($line['description']) . '</td>';
            print '<td class="linecolrefsupplier">' . (isset($line['supplier_ref']) ? dol_escape_htmltag($line['supplier_ref']) : '') . '</td>';
            print '<td class="linecolvat nowrap right">' . ($line['vat_rate'] == 'zw' ? $langs->trans("KSEF_VATExempt") : (int)$line['vat_rate'] . '%') . '</td>';
            print '<td class="linecoluht nowraponall right">' . price($line['unit_price'], 0, '', 1, -1, 2) . '</td>';
            print '<td class="linecoluttc nowraponall right">' . price($unitPriceTTC, 0, '', 1, -1, 4) . '</td>';
            print '<td class="linecolqty nowraponall right">' . price($line['quantity'], 0, '', 1, -1, 4) . '</td>';
            print '<td class="linecoldiscount">&nbsp;</td>';
            print '<td class="linecolht nowrap right">' . price($line['net_amount'], 0, '', 1, -1, 2) . '</td>';
            print '</tr>';
        }

        print '</table>';
        print '</div>';
    }
}

print dol_get_fiche_end();


/*
 * Action Buttons
 */

print '<div class="tabsAction">';

$parameters = array();
$reshook = $hookmanager->executeHooks('addMoreActionsButtons', $parameters, $object, $action);
if (empty($reshook)) {
    // Import to Dolibarr
    if ($object->import_status == 'NEW' && $usercanwrite) {
        print '<a class="butAction" href="' . dol_buildpath('/ksef/incoming_import.php', 1) . '?id=' . $object->id . '">' . $langs->trans("KSEF_ImportToDolibarr") . '</a>';
    } elseif ($object->import_status == 'IMPORTED') {
        print '<span class="butActionRefused classfortooltip" title="' . dol_escape_htmltag($langs->trans("KSEF_AlreadyImported")) . '">' . $langs->trans("KSEF_ImportToDolibarr") . '</span>';
    }

    // Download XML
    if (!empty($object->fa3_xml)) {
        print '<a class="butAction" href="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=download_xml&token=' . newToken() . '">' . $langs->trans("KSEF_DownloadXml") . '</a>';
    }

    // View on KSeF Portal
    if (!empty($object->ksef_number) && !empty($object->fa3_xml)) {
        $objEnvironment = !empty($object->environment) ? $object->environment : getDolGlobalString('KSEF_ENVIRONMENT', 'TEST');
        $verifyUrl = ksefGetVerificationUrlFromXml($object->ksef_number, $object->fa3_xml, $objEnvironment);
        print '<a class="butAction" href="' . $verifyUrl . '" target="_blank" rel="noopener noreferrer">' . $langs->trans("KSEF_ViewOnPortal") . '</a>';
    }

    // Download PDF visualization
    if (!empty($object->fa3_xml)) {
        print '<a class="butAction" href="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=download_pdf&token=' . newToken() . '">' . $langs->trans("KSEF_DownloadPdf") . '</a>';
    }

    // Delete
    if ($usercanwrite && $object->import_status == 'NEW') {
        print dolGetButtonAction('', $langs->trans('Delete'), 'delete', $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=delete&token=' . newToken(), '', true);
    }
}

print '</div>';


llxFooter();
$db->close();