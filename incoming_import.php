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
 * \brief   Import incoming invoice
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
require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
dol_include_once('/ksef/class/ksef_incoming.class.php');
dol_include_once('/ksef/lib/ksef.lib.php');
dol_include_once('/ksef/lib/ksef_tour.lib.php');
dol_include_once('/ksef/lib/ksef_import_ui.lib.php');

$langs->loadLangs(array("ksef@ksef", "bills", "companies", "suppliers", "products"));

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
if ($object->import_status == KsefIncoming::STATUS_FETCH_ERROR) {
    setEventMessages($langs->trans('KSEF_FetchErrorNotImportable'), null, 'warnings');
    header('Location: ' . dol_buildpath('/ksef/incoming_card.php', 1) . '?id=' . $object->id);
    exit;
}

$form = new Form($db);

$usercanwrite = $user->hasRight('ksef', 'write');
$importRights = ksefIncomingImportRights($user);
$usercanimport = $importRights['import'];
$forKor = ksefImportForKor($object);

$hookmanager->initHooks(array('ksef_incomingimport', 'globalcard'));

$parameters = array();
$reshook = $hookmanager->executeHooks('doActions', $parameters, $object, $action);
if ($reshook < 0) {
    setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}

if (empty($reshook)) {
    if ($action == 'stock_preview') {
        ksefImportStockPreviewAjax($db, $user, $object, 'incoming');
    }
    ksefCitedOriginalActions($object, $action, $user, $usercanwrite);

    if (in_array($action, array('import', 'quickimport')) && $usercanwrite && !$usercanimport) {
        setEventMessages($langs->trans('NotEnoughPermissions'), null, 'errors');
        $action = '';
    }

    if ($action == 'import' && $usercanimport) {
        $socid = GETPOSTINT('socid');
        $partyError = '';
        $createdText = '';
        $canCreate = ksefUserCan($user, 'create_supplier');
        $gusNote = '';
        if ($socid <= 0) {
            $socid = $object->findMatchingThirdParty();
            if ($socid <= 0 && !$canCreate) {
                $partyError = $langs->transnoentitiesnoconv('KSEF_BatchNoRightCreateSupplier');
            } elseif ($socid <= 0 && GETPOSTINT('create_parties') === 1) {
                $gusData = null;
                if (GETPOSTINT('gus_fetch') === 1 && getDolGlobalString('KSEF_GUS_ENABLED') && ($object->seller_country ?: 'PL') === 'PL') {
                    $gusData = ksefImportGusData($db, (string) $object->seller_nip, 'KSEF_GUS_SUPPLIER_DATA', GETPOSTINT('gus_previewed') !== 1);
                    if ($gusData === null) {
                        $gusNote = $langs->transnoentitiesnoconv('KSEF_BatchGusNoData');
                    }
                }
                $socid = $object->autoCreateSupplier($user, $gusData);
                if ($socid > 0) {
                    $newSoc = new Societe($db);
                    $createdText = $langs->transnoentitiesnoconv('KSEF_BatchCreatedSupplier', $newSoc->fetch($socid) > 0 ? $newSoc->name : (string) $object->seller_name);
                } else {
                    $partyError = $langs->transnoentitiesnoconv('KSEF_BatchSupplierCreateFailed', ksefTranslateError($object->error));
                }
            }
        }
        if ($socid <= 0) {
            setEventMessages($partyError !== '' ? ksefEscapeText($partyError) : $langs->trans('KSEF_ImportErrorNoSupplier'), null, 'errors');
        } else {
            $lineProductMap = array();
            $lines = $object->previewImportLines();
            foreach ($lines as $line) {
                $lineNum = $line['line_num'] ?? 0;
                $productId = GETPOSTINT('line_product_' . $lineNum);
                if ($productId > 0) {
                    $lineProductMap[$lineNum] = $productId;
                }
            }
            $correctionSourceId = GETPOSTINT('correction_source_id');
            $result = $object->importToDolibarr($user, $socid, $lineProductMap, $correctionSourceId, '', array('warehouse_id' => GETPOSTINT('warehouse_id')));
            if ($result > 0) {
                setEventMessages(ksefTransHtml($object->validated ? 'KSEF_ImportSuccess' : 'KSEF_ImportSuccessDraft', array(ksefEscapeText((string) $object->invoice_number))), null, 'mesgs');
                if ($createdText !== '') {
                    setEventMessages(ksefEscapeText($langs->transnoentitiesnoconv('KSEF_ImportCreatedFromInvoice', $createdText)), null, 'mesgs');
                }
                if ($gusNote !== '') {
                    setEventMessages(ksefEscapeText($gusNote), null, 'warnings');
                }
                if (!empty($object->warnings)) {
                    setEventMessages(null, array_map('ksefEscapeText', (array) $object->warnings), 'warnings');
                }
                if ($forKor) {
                    setEventMessages(ksefTransHtml('KSEF_OriginalImportedNowKor', array(ksefEscapeText((string) $forKor->invoice_number))), null, 'mesgs');
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $forKor->id . '&socid=' . $socid);
                    exit;
                }
                header('Location: ' . DOL_URL_ROOT . '/fourn/facture/card.php?facid=' . $result);
                exit;
            } else {
                $object->recordImportError();
                // decode, escape once
                setEventMessages(ksefEscapeText(html_entity_decode((string) $object->error, ENT_QUOTES | ENT_HTML5, 'UTF-8')), array_map('ksefEscapeText', (array) $object->errors), 'errors');
                if ($createdText !== '') {
                    setEventMessages(ksefEscapeText($langs->transnoentitiesnoconv('KSEF_BatchCreatedKept', $createdText)), null, 'warnings');
                }
            }
        }
    }

    if ($action == 'quickimport' && $usercanimport) {
        if ($object->import_status == 'IMPORTED') {
            if ($object->fk_facture_fourn > 0) {
                header('Location: ' . DOL_URL_ROOT . '/fourn/facture/card.php?facid=' . $object->fk_facture_fourn);
                exit;
            }
        }
        if ($object->import_status == 'NEW' || $object->import_status == 'ERROR') {
            $autoSocid = $object->findMatchingThirdParty();
            if ($autoSocid <= 0) {
                $willCreate = ksefUserCan($user, 'create_supplier') && ksefImportPartyBoxDefault('incoming');
                setEventMessages($langs->trans($willCreate ? 'KSEF_QuickImportSupplierWillCreate' : 'KSEF_QuickImportNoSupplier'), null, $willCreate ? 'mesgs' : 'warnings');
            } else {
                $lines = $object->previewImportLines();
                $autoMatches = $object->autoMatchLineProducts($lines, $autoSocid);
                $unmatched = 0;
                foreach ($lines as $line) {
                    $lineNum = $line['line_num'] ?? 0;
                    if (!isset($autoMatches[$lineNum])) {
                        $unmatched++;
                    }
                }
                $allMatched = ($unmatched === 0);
                if (!$allMatched) {
                    setEventMessages($langs->trans('KSEF_QuickImportUnmatchedLines', $unmatched, count($lines)), null, 'warnings');
                }
                if ($allMatched) {
                    $productMap = array();
                    foreach ($autoMatches as $ln => $match) {
                        $productMap[$ln] = $match['product_id'];
                    }
                    $result = $object->importToDolibarr($user, $autoSocid, $productMap);
                    if ($result > 0) {
                        setEventMessages($langs->trans($object->validated ? 'KSEF_QuickImportAutoComplete' : 'KSEF_QuickImportAutoDraft'), null, 'mesgs');
                        if (!empty($object->warnings)) {
                            setEventMessages(null, array_map('ksefEscapeText', (array) $object->warnings), 'warnings');
                        }
                        header('Location: ' . DOL_URL_ROOT . '/fourn/facture/card.php?facid=' . $result);
                        exit;
                    } else {
                        $object->recordImportError();
                        setEventMessages(ksefEscapeText(html_entity_decode((string) $object->error, ENT_QUOTES | ENT_HTML5, 'UTF-8')), array_map('ksefEscapeText', (array) $object->errors), 'errors');
                    }
                }
            }
            $action = '';
        } elseif ($object->import_status != 'IMPORTED') {
            setEventMessages($langs->trans('KSEF_QuickImportNotImportable'), null, 'warnings');
            $action = '';
        }
    }

    // kept for scripts
    if ((($action == 'skip') || ($action == 'confirm_skip' && $confirm == 'yes')) && $usercanwrite) {
        $result = $object->skip($user);
        if ($result > 0) {
            setEventMessages($langs->trans('KSEF_InvoiceSkipped'), null, 'mesgs');
            header('Location: ' . dol_buildpath('/ksef/incoming_list.php', 1) . '?restore_lastsearch_values=1');
            exit;
        } else {
            setEventMessages($object->error, $object->errors, 'errors');
        }
    }

    if ($action == 'confirm_reset_import' && $confirm == 'yes' && $usercanwrite) {
        $result = $object->resetImportStatus($user);
        if ($result > 0) {
            setEventMessages($langs->trans('KSEF_ImportStatusReset'), null, 'mesgs');
            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
            exit;
        } else {
            setEventMessages($object->error, $object->errors, 'errors');
        }
    }

    if ($action == 'reopen' && $usercanwrite) {
        $result = $object->reopen($user);
        if ($result > 0) {
            setEventMessages($langs->trans('RecordModifiedSuccessfully'), null, 'mesgs');
            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
            exit;
        } else {
            setEventMessages($object->error, $object->errors, 'errors');
        }
    }
}

$title = $langs->trans('KSEF_ImportToDolibarr') . ' - ' . $object->invoice_number;
$help_url = ksefHelpUrl('receiving');

llxHeader('', $title, $help_url, '', 0, 0, '', '', '', 'mod-ksef page-incoming-import');

$head = ksef_incoming_prepare_head($object);

print dol_get_fiche_head($head, 'import', $langs->trans('KSEF_IncomingInvoice'), -1, 'supplier_invoice');

$linkback = '<a href="' . dol_buildpath('/ksef/incoming_list.php', 1) . '?restore_lastsearch_values=1">' . $langs->trans("BackToList") . '</a>';

$morehtmlref = '<div class="refidno">';
$morehtmlref .= $form->editfieldkey("RefSupplierBill", 'ref_supplier', $object->invoice_number, $object, 0, 'string', '', 0, 1);
$morehtmlref .= $form->editfieldval("RefSupplierBill", 'ref_supplier', $object->invoice_number, $object, 0, 'string', '', null, null, '', 1);
$morehtmlref .= '<br>' . $langs->trans("Supplier") . ': <strong>' . dol_escape_htmltag($object->seller_name) . '</strong>';
if (!empty($object->seller_nip)) {
    $morehtmlref .= ' (NIP: ' . ksefFormatNIP($object->seller_nip) . ')';
}
$morehtmlref .= '</div>';

dol_banner_tab($object, 'id', $linkback, 1, 'rowid', 'ksef_number', $morehtmlref);

if ($action == 'ask_skip') {
    print $form->formconfirm($_SERVER["PHP_SELF"] . '?id=' . $object->id, $langs->trans('KSEF_ImportSkip'), $langs->trans('KSEF_ImportSkipConfirm'), 'confirm_skip', '', 0, 1);
}

if ($object->import_status == 'IMPORTED') {
    $linkedInvoiceExists = false;
    $linkedInvoice = null;
    if ($object->fk_facture_fourn > 0) {
        $linkedInvoice = new FactureFournisseur($db);
        if ($linkedInvoice->fetch($object->fk_facture_fourn) > 0) {
            $linkedInvoiceExists = true;
        }
    }

    if ($action == 'reset_import') {
        print $form->formconfirm(
            $_SERVER["PHP_SELF"] . '?id=' . $object->id,
            $langs->trans('KSEF_ResetImportStatus'),
            $langs->trans('KSEF_ConfirmResetImport'),
            'confirm_reset_import',
            '',
            0,
            1
        );
    }

    $creditNoteExists = false;
    $creditNoteInvoice = null;
    if ($object->fk_credit_note > 0) {
        $creditNoteInvoice = new FactureFournisseur($db);
        if ($creditNoteInvoice->fetch($object->fk_credit_note) > 0) {
            $creditNoteExists = true;
        }
    }

    $isReplaceMode = ($creditNoteExists && $linkedInvoiceExists);

    print '<div class="fichecenter">';
    print '<div class="fichehalfleft">';
    print '<div class="underbanner clearboth"></div>';
    print '<table class="border tableforfield centpercent">';

    print '<tr><td class="titlefield">' . $langs->trans("Status") . '</td><td>';
    print $object->getLibStatut(5);
    print '</td></tr>';

    if ($object->import_date) {
        print '<tr><td>' . $langs->trans("DateCreation") . '</td><td>';
        print dol_print_date($object->import_date, 'dayhour');
        print '</td></tr>';
    }

    if ($isReplaceMode) {
        print '<tr><td>' . $langs->trans("KSEF_ImportMode") . '</td><td>';
        print '<span class="badgeneutral">' . $langs->trans("KSEF_UpwardCorrectionReplace") . '</span>';
        print '</td></tr>';
    }

    print '</table>';
    print '</div>';
    print '</div>';
    print '<div class="clearboth"></div>';

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
    } else {
        print '<div class="fichecenter">';
        print '<div class="fichehalfleft">';
        print '<table class="border tableforfield centpercent">';

        if ($object->fk_facture_fourn > 0) {
            print '<tr><td class="titlefield">' . $langs->trans("KSEF_ImportedInvoice") . '</td><td>';
            if ($linkedInvoiceExists) {
                print $linkedInvoice->getNomUrl(1) . ' ' . ksefInvoiceStatusBadge($linkedInvoice, 5);
            } else {
                print '<span class="warning"><span class="fa fa-exclamation-triangle"></span> ' . $langs->trans("KSEF_LinkedInvoiceDeleted") . '</span>';
            }
            print '</td></tr>';
        }

        if ($creditNoteExists) {
            print '<tr><td>' . $langs->trans("KSEF_ReplaceCreditNote") . '</td><td>';
            print $creditNoteInvoice->getNomUrl(1) . ' ' . ksefInvoiceStatusBadge($creditNoteInvoice, 5);
            print '</td></tr>';
        }

        print '</table>';
        print '</div>';
        print '</div>';
        print '<div class="clearboth"></div>';
    }

    print dol_get_fiche_end();

    print '<div class="tabsAction">';
    if ($isReplaceMode) {
        print '<a class="butAction" href="' . DOL_URL_ROOT . '/fourn/facture/card.php?facid=' . $creditNoteInvoice->id . '">' . $langs->trans("KSEF_ViewCreditNote") . '</a>';
        print '<a class="butAction" href="' . DOL_URL_ROOT . '/fourn/facture/card.php?facid=' . $object->fk_facture_fourn . '">' . $langs->trans("KSEF_ViewReplacementInvoice") . '</a>';
    } elseif ($linkedInvoiceExists) {
        print '<a class="butAction" href="' . DOL_URL_ROOT . '/fourn/facture/card.php?facid=' . $object->fk_facture_fourn . '">' . $langs->trans("KSEF_ViewLinkedInvoice") . '</a>';
    } elseif ($usercanwrite) {
        print '<a class="butAction" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=reset_import&token=' . newToken() . '">' . $langs->trans("KSEF_ResetImportStatus") . '</a>';
    }
    print '<a class="butAction" href="' . dol_buildpath('/ksef/incoming_card.php', 1) . '?id=' . $object->id . '">' . $langs->trans('KSEF_Back') . '</a>';
    print '</div>';

    print ksefHelpFooter($db, $user);
    llxFooter();
    $db->close();
    return;
}

if ($object->import_status == 'SKIPPED') {
    print '<div class="fichecenter">';
    print '<div class="fichehalfleft">';
    print '<div class="underbanner clearboth"></div>';

    print '<table class="border tableforfield centpercent">';

    print '<tr><td class="titlefield">' . $langs->trans("Status") . '</td><td>';
    print $object->getLibStatut(5);
    print '</td></tr>';

    print '<tr><td>' . $langs->trans("Invoice") . '</td><td>';
    print '<strong>' . dol_escape_htmltag($object->invoice_number) . '</strong>';
    print ' - ' . dol_print_date($object->invoice_date, 'day');
    print ' - ' . price($object->total_gross, 0, $langs, 1, -1, -1, $object->currency);
    print '</td></tr>';

    print '</table>';

    print '<div class="opacitymedium" style="margin: 10px 0;">';
    print $langs->trans("KSEF_InvoiceSkipped");
    print '</div>';

    print '</div>';
    print '</div>';
    print '<div class="clearboth"></div>';

    print dol_get_fiche_end();

    print '<div class="tabsAction">';
    if ($usercanwrite) {
        print '<a class="butAction" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=reopen&token=' . newToken() . '">' . $langs->trans("KSEF_ReopenForImport") . '</a>';
    }
    print '<a class="butAction" href="' . dol_buildpath('/ksef/incoming_card.php', 1) . '?id=' . $object->id . '">' . $langs->trans('KSEF_Back') . '</a>';
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

$object->deposit_socid = $selectedSocid;
$lines = $object->previewImportLines();
$autoMatches = $object->autoMatchLineProducts($lines, $selectedSocid);
$newProductId = GETPOSTINT('ksef_new_product');
$newLine = GETPOSTINT('ksef_new_line');
if ($newProductId > 0 && ksefUserCan($user, 'create_product')) {
    foreach ($lines as $l) {
        if ((int) ($l['line_num'] ?? 0) === $newLine) {
            $autoMatches[$newLine] = array('product_id' => $newProductId, 'method' => 'created');
            if ($selectedSocid > 0) {
                $object->addSupplierPriceFromLine($user, $newProductId, $selectedSocid, $l);
            }
            break;
        }
    }
}

$backtopageRaw = $_SERVER['PHP_SELF'] . '?id=' . $object->id . ($selectedSocid > 0 ? '&socid=' . (int) $selectedSocid : '') . ($forKor ? '&for_kor=' . (int) $forKor->id : '');

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

print '<tr><td class="titlefield">' . $langs->trans("Status") . '</td><td>';
print $object->getLibStatut(5);
if ($object->import_status == 'ERROR' && !empty($object->import_error)) {
    print '<br><span class="error">' . dol_escape_htmltag($object->import_error) . '</span>';
}
print '</td></tr>';

print '<tr><td>' . $langs->trans("Invoice") . '</td><td>';
print '<strong>' . dol_escape_htmltag($object->invoice_number) . '</strong>';
print ' - ' . dol_print_date($object->invoice_date, 'day');
print ' - ' . price($object->total_gross, 0, $langs, 1, -1, -1, $object->currency);
print '</td></tr>';

print '<tr><td>' . $langs->trans("Type") . '</td><td>';
print ksefGetInvoiceTypeBadge($object->invoice_type);
print '</td></tr>';
ksefPrintIncomingDepositsRow($object, $selectedSocid);

$resolvedCorrections = array();
$korektaRefusal = '';
$correctedCount = 0;
$allCorrectionsImported = true;
$isMultiCorrection = false;
if (KsefIncoming::isCorrectionType($object->invoice_type)) {
    $resolvedCorrections = $object->resolveCorrectedInvoices();
    $correctionData = $object->getCorrectionData();
    $correctedCount = count($resolvedCorrections);
    $isMultiCorrection = ($correctedCount > 1);

    foreach ($resolvedCorrections as $rc) {
        if (!$rc['supplier_invoice']) {
            $allCorrectionsImported = false;
        }
    }

    if (empty($resolvedCorrections)) {
        print '<tr class="ksef-corrects-row"><td>' . $langs->trans("KSEF_CorrectsInvoice") . '</td><td>';
        print '<span class="warning"><span class="fa fa-exclamation-triangle"></span> ' . $langs->trans("KSEF_CorrectionNoReferenceData") . '</span>';
        print '</td></tr>';
    } elseif ($correctedCount == 1) {
        $rc = $resolvedCorrections[0];
        $isSelfReference = (!empty($rc['ksef_number']) && $rc['ksef_number'] === $object->ksef_number);
        print '<tr class="ksef-corrects-row"><td>' . $langs->trans("KSEF_CorrectsInvoice") . '</td><td>';

                print $langs->trans("KSEF_CorrectsKsefNr") . ': ';
        print $rc['incoming'] ? $rc['incoming']->getNomUrl(1) : (!empty($rc['ksef_number']) ? dol_escape_htmltag($rc['ksef_number']) : '<span class="opacitymedium">-</span>');
        $offer = $rc['supplier_invoice'] ? '' : ksefCitedOriginalOffer($object, (string) $rc['ksef_number'], $rc['incoming'], $usercanwrite);
        print $offer !== '' ? '<br>' . $offer : '';
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
    } else {
        print '<tr><td>' . $langs->trans("KSEF_CorrectedInvoices") . '</td><td>';
        print $langs->trans("KSEF_CorrectsXInvoices", $correctedCount);
        print '</td></tr>';
    }

    if (!empty($correctionData['reason'])) {
        print '<tr><td>' . $langs->trans("KSEF_CorrectionReason") . '</td>';
        print '<td>' . dol_escape_htmltag($correctionData['reason']) . '</td></tr>';
    }

    $korektaSource = $object->resolveKorektaSource(GETPOSTINT('correction_source_id'), $selectedSocid);
    $citedFound = array();
    foreach ($resolvedCorrections as $rc) {
        if ($rc['supplier_invoice']) {
            $citedFound[(int) $rc['supplier_invoice']->id] = $rc;
        }
    }
    if (count($citedFound) > 1) {
        print '<tr><td>' . $langs->trans("KSEF_CorrectionChooseStrategy") . '</td><td>';
        print '<div id="correction_strategy_radios">';
        $pick = isset($citedFound[GETPOSTINT('correction_source_id')]) ? GETPOSTINT('correction_source_id') : array_key_first($citedFound);
        foreach ($citedFound as $fid => $rc) {
            $refLabel = $rc['supplier_invoice']->ref . ' (' . ($rc['ksef_number'] !== '' ? $rc['ksef_number'] : $rc['invoice_number']) . ')';
            $reload = "location.href='" . dol_escape_js($_SERVER['PHP_SELF'] . '?id=' . $object->id . '&correction_source_id=' . $fid . '&socid=') . "' + (jQuery('#socid_hidden').val() || '')";
            print '<div style="margin-bottom: 4px;"><label><input type="radio" name="correction_source_id" value="' . $fid . '"' . ($fid === $pick ? ' checked' : '') . ' onchange="' . dol_escape_htmltag($reload) . '"> ';
            print $langs->trans("KSEF_CorrectionLinkTo", dol_escape_htmltag($refLabel)) . '</label></div>';
        }
        print '</div>';
        print '</td></tr>';
    }
    if ($isMultiCorrection && !$allCorrectionsImported && $citedFound) {
        print '<tr><td></td><td><span class="warning"><span class="fa fa-exclamation-triangle"></span> ' . $langs->trans("KSEF_CorrectionNotAllImported") . '</span></td></tr>';
    }

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
        $korektaRefusal = $korektaSource;
        print '<span class="warning" id="ksef_korekta_refused"><span class="fa fa-exclamation-triangle paddingright"></span>' . dol_escape_htmltag($korektaSource) . '</span>';
    }
    print '</td></tr>';
}

$party = array('name' => (string) $object->seller_name, 'nip' => (string) $object->seller_nip, 'vat_id' => (string) $object->seller_vat_id,
    'country' => (string) $object->seller_country, 'address' => (string) $object->seller_address);
ksefPrintImportPartyPicker($form, $object, $party, $selectedSocid, $autoSocid, $backtopageRaw, 'incoming');
ksefPrintImportCreatePartiesRow($user, 'incoming');
print '</table>';

print '</div>';

print '<div class="fichehalfright">';
print '<div class="underbanner clearboth"></div>';

ksefPrintImportAmounts($object);

if ($isMultiCorrection) {
    print '<div class="div-table-responsive-no-min">';
    print '<table class="noborder paymenttable centpercent ksef-corrects-table">';

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
        print $rc['incoming'] ? $rc['incoming']->getNomUrl(1) : (!empty($rc['ksef_number']) ? dol_escape_htmltag($rc['ksef_number']) : '<span class="opacitymedium">-</span>');
        $offer = $rc['supplier_invoice'] ? '' : ksefCitedOriginalOffer($object, (string) $rc['ksef_number'], $rc['incoming'], $usercanwrite);
        print $offer !== '' ? '<br>' . $offer : '';
        if ($isSelfReference) {
            print '<br><span class="warning"><span class="fa fa-exclamation-triangle"></span> ' . $langs->trans("KSEF_CorrectedKsefNumberSameAsOwn") . '</span>';
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
print '<div class="clearboth"></div>';

ksefPrintImportLines($form, $object, array_merge($lines, $object->depositPreviewLines()), $autoMatches, $backtopageRaw, 'incoming');

$stockKey = '';
if (isModEnabled('stock')) {
    $stockEnabled = getDolGlobalString('STOCK_CALCULATE_ON_SUPPLIER_BILL');
    $stockKey = $stockEnabled ? 'KSEF_StockWillIncrease' : 'KSEF_StockWillNotIncrease';
    if ($stockEnabled && KsefIncoming::isCorrectionType($object->invoice_type)) {
        $srcMoved = true;
        if (!empty($korektaSource) && is_object($korektaSource)) {
            $srcMoved = (bool) ksefCorrectionStockSlots($db, $korektaSource, true);
        }
        $stockKey = $srcMoved ? 'KSEF_IncomingKorStockDifference' : 'KSEF_IncomingKorStockFull';
    }
    $stockShort = $langs->trans($stockKey);
    $stockVerbose = $langs->trans($stockKey === 'KSEF_IncomingKorStockDifference' ? 'KSEF_IncomingKorStockHelp' : ($stockEnabled ? 'KSEF_StockAutoIncreaseEnabled' : 'KSEF_StockAutoIncreaseDisabled'));

    print '<div class="info-box" style="margin-top: 10px;">';
    print $stockShort;
    print ' <span class="fa fa-info-circle classfortooltip" style="opacity: 0.5;" title="' . dol_escape_htmltag($stockVerbose) . '"></span>';
    print '<div id="ksef_stock_preview" class="ksef-stock-preview" style="margin-top: 6px;"></div>';
    print '</div>';
}

print '</form>';

print dol_get_fiche_end();

print '<div class="tabsAction">';

$parameters = array();
$reshook = $hookmanager->executeHooks('addMoreActionsButtons', $parameters, $object, $action);
if (empty($reshook)) {
    if ($usercanwrite && !$usercanimport && ($object->import_status == 'NEW' || $object->import_status == 'ERROR')) {
        if (!ksefHideUnauthorized($user)) {
            print '<span class="butActionRefused classfortooltip" title="' . dol_escape_htmltag(ksefMissingRightText('import_incoming')) . '">' . $langs->trans("KSEF_Import") . '</span>';
        }
        print '<a class="butAction" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ask_skip&token=' . newToken() . '" id="btn_skip" title="' . dol_escape_htmltag($langs->trans("KSEF_ImportSkipDesc")) . '">' . $langs->trans("KSEF_ImportSkip") . '</a>';
    } elseif ($usercanwrite && ($object->import_status == 'NEW' || $object->import_status == 'ERROR')) {
        if ($korektaRefusal !== '') {
            print '<span class="butActionRefused classfortooltip" title="' . dol_escape_htmltag($korektaRefusal) . '">' . $langs->trans("KSEF_Import") . '</span>';
        } else {
            print '<a class="butAction" href="#" id="btn_import" title="' . dol_escape_htmltag($langs->trans("KSEF_ImportDesc")) . '">' . $langs->trans("KSEF_Import") . '</a>';
        }

        print '<a class="butAction" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ask_skip&token=' . newToken() . '" id="btn_skip" title="' . dol_escape_htmltag($langs->trans("KSEF_ImportSkipDesc")) . '">' . $langs->trans("KSEF_ImportSkip") . '</a>';
    }

    print '<a class="butAction" href="' . dol_buildpath('/ksef/incoming_card.php', 1) . '?id=' . $object->id . '">' . $langs->trans('KSEF_Back') . '</a>';
}

print '</div>';

$korWh = (KsefIncoming::isCorrectionType($object->invoice_type) && !empty($korektaSource) && getDolGlobalString('STOCK_CALCULATE_ON_SUPPLIER_BILL')) ? ksefImportCorrectionWarehouse($db, $korektaSource, true) : 0;
$whHtml = ksefImportWarehouseSelect($db, $user, 'incoming', $korWh);
ksefPrintImportConfirmScript($object, array_merge($lines, $object->depositPreviewLines()), array(
    'direction' => 'incoming',
    'party_name' => (string) $object->seller_name,
    'gus_preview_url' => (getDolGlobalString('KSEF_GUS_ENABLED') && ksefUserCan($user, 'create_supplier')) ? dol_buildpath('/ksef/incoming_list.php', 1) . '?action=gus_supplier_preview&token=' . newToken() : '',
    'stock_short' => ($stockKey !== '' ? $langs->transnoentitiesnoconv($stockKey) : ''),
    'warehouse_html' => $whHtml,
    'stock_preview_url' => $whHtml !== '' && $user->hasRight('stock', 'lire') ? $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=stock_preview' : '',
    'multi_correction' => $isMultiCorrection,
    'korekta' => KsefIncoming::isCorrectionType($object->invoice_type),
    'korekta_after' => isset($korektaSource) ? ksefImportKorektaAfterText($object, $korektaSource) : '',
));

print ksefHelpFooter($db, $user);
llxFooter();
$db->close();
