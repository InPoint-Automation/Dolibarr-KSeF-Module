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
 * \file    ksef/incoming_import.php
 * \ingroup ksef
 * \brief   Import KSeF incoming invoice to Dolibarr (placeholder)
 */

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Include of main fails");

global $conf, $langs, $user, $db;

require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
dol_include_once('/ksef/class/ksef_incoming.class.php');
dol_include_once('/ksef/lib/ksef.lib.php');

$langs->loadLangs(array("ksef@ksef", "bills", "suppliers"));

// Security check
if (!$user->hasRight('ksef', 'read')) accessforbidden();

// Get parameters
$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');

if (empty($id)) {
    header('Location: ' . dol_buildpath('/ksef/incoming_list.php', 1));
    exit;
}

// Initialize objects
$object = new KsefIncoming($db);
if ($object->fetch($id) <= 0) {
    accessforbidden('Record not found');
}

$form = new Form($db);

// Permissions
$usercanwrite = $user->hasRight('ksef', 'write');

$hookmanager->initHooks(array('ksef_incominginmport', 'globalcard'));


/*
 * Actions
 */

//TODO Some kind of import stuff here

/*
 * View
 */

$title = $langs->trans('KSEF_ImportToDolibarr') . ' - ' . $object->invoice_number;
$help_url = '';

llxHeader('', $title, $help_url, '', 0, 0, '', '', '', 'mod-ksef page-incoming-import');


// Prepare tabs
$head = ksef_incoming_prepare_head($object);

print dol_get_fiche_head($head, 'import', $langs->trans('KSEF_IncomingInvoice'), -1, 'supplier_invoice');


/*
 * Banner
 */

$linkback = '<a href="' . dol_buildpath('/ksef/incoming_list.php', 1) . '?restore_lastsearch_values=1">' . $langs->trans("BackToList") . '</a>';

$morehtmlref = '<div class="refidno">';
$morehtmlref .= $form->editfieldkey("RefSupplierBill", 'ref_supplier', $object->invoice_number, $object, 0, 'string', '', 0, 1);
$morehtmlref .= $form->editfieldval("RefSupplierBill", 'ref_supplier', $object->invoice_number, $object, 0, 'string', '', null, null, '', 1);
$morehtmlref .= '<br>' . $langs->trans("Supplier") . ': <strong>' . dol_escape_htmltag($object->seller_name) . '</strong>';
if (!empty($object->seller_nip)) {
    $morehtmlref .= ' <span class="opacitymedium">(NIP: ' . ksefFormatNIP($object->seller_nip) . ')</span>';
}
$morehtmlref .= '</div>';

dol_banner_tab($object, 'id', $linkback, 1, 'rowid', 'ksef_number', $morehtmlref);


/*
 * Import content
 */

print '<div class="fichecenter">';
print '<div class="fichehalfleft">';
print '<div class="underbanner clearboth"></div>';

print '<table class="border tableforfield centpercent">';

// Current status
print '<tr><td class="titlefield">' . $langs->trans("Status") . '</td><td>';
print $object->getLibStatut(5);
print '</td></tr>';

// Invoice summary
print '<tr><td>' . $langs->trans("Invoice") . '</td><td>';
print '<strong>' . dol_escape_htmltag($object->invoice_number) . '</strong>';
print ' - ' . dol_print_date($object->invoice_date, 'day');
print ' - ' . price($object->total_gross, 0, $langs, 1, -1, -1, $object->currency);
print '</td></tr>';

// Type
print '<tr><td>' . $langs->trans("Type") . '</td><td>';
print ksefGetInvoiceTypeBadge($object->invoice_type);
print '</td></tr>';

// Supplier
print '<tr><td>' . $langs->trans("Supplier") . '</td><td>';
print dol_escape_htmltag($object->seller_name);
print ' <span class="opacitymedium">(NIP: ' . ksefFormatNIP($object->seller_nip) . ')</span>';
print '</td></tr>';

// Linked invoice
if ($object->fk_facture_fourn > 0) {
    $linkedInvoice = new FactureFournisseur($db);
    if ($linkedInvoice->fetch($object->fk_facture_fourn) > 0) {
        print '<tr><td>' . $langs->trans("LinkedSupplierInvoice") . '</td><td>';
        print $linkedInvoice->getNomUrl(1) . ' ' . $linkedInvoice->getLibStatut(5);
        print '</td></tr>';
    }
}

print '</table>';

print '</div>'; // fichehalfleft


/*
 * Right column
 */

print '<div class="fichehalfright">';
print '<div class="underbanner clearboth"></div>';

// coming soon ..?
print '<div class="warning" style="margin: 10px;">';
print '<span class="fa fa-clock pictofixedwidth"></span>';
print '<strong>' . $langs->trans("KSEF_ImportFeatureComingSoon") . '</strong><br><br>';
print $langs->trans("KSEF_ImportFeatureDescription");
print '</div>';

print '</div>'; // fichehalfright

print '</div>'; // fichecenter
print '<div class="clearboth"></div>';


print dol_get_fiche_end();


/*
 * Action Buttons
 */

print '<div class="tabsAction">';

$parameters = array();
$reshook = $hookmanager->executeHooks('addMoreActionsButtons', $parameters, $object, $action);
if (empty($reshook)) {
    if ($object->import_status == 'NEW' && $usercanwrite) {
        // Import button
        print '<span class="butActionRefused classfortooltip" title="' . dol_escape_htmltag($langs->trans("KSEF_ImportComingSoon")) . '">';
        print '<span class="fa fa-clock paddingright"></span>' . $langs->trans("KSEF_ImportToDolibarr");
        print '</span>';
    } elseif ($object->import_status == 'IMPORTED') {
        if ($object->fk_facture_fourn > 0) {
            print '<a class="butAction" href="' . DOL_URL_ROOT . '/fourn/facture/card.php?facid=' . $object->fk_facture_fourn . '">' . $langs->trans("KSEF_ViewLinkedInvoice") . '</a>';
        }
        print '<span class="butActionRefused classfortooltip" title="' . dol_escape_htmltag($langs->trans("KSEF_AlreadyImported")) . '">' . $langs->trans("KSEF_ImportToDolibarr") . '</span>';
    }

    // Back to card
    print '<a class="butAction" href="' . dol_buildpath('/ksef/incoming_card.php', 1) . '?id=' . $object->id . '">' . $langs->trans("Back") . '</a>';
}

print '</div>';


llxFooter();
$db->close();