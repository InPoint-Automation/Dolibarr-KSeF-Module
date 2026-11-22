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
 * \file    ksef/incoming_list.php
 * \ingroup ksef
 * \brief   Incoming invoice list
 */

if (!defined('CSRFCHECK_WITH_TOKEN')) {
    define('CSRFCHECK_WITH_TOKEN', '1');
}

$action_raw = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
if (in_array($action_raw, array('check_fetch_status', 'init_fetch', 'process_incoming', 'process_fetch', 'upo_sweep', 'download_xml', 'download_pdf', 'batch_import_preview', 'batch_import_execute', 'gus_supplier_preview'))) {
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

global $conf, $langs, $user, $db, $hookmanager;

require_once DOL_DOCUMENT_ROOT . '/core/class/html.formother.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
dol_include_once('/ksef/class/ksef_incoming.class.php');
dol_include_once('/ksef/class/ksef_sync_state.class.php');
dol_include_once('/ksef/class/ksef_service.class.php');
dol_include_once('/ksef/class/ksef_latarnia.class.php');
dol_include_once('/ksef/lib/ksef.lib.php');
dol_include_once('/ksef/lib/ksef_tour.lib.php');
dol_include_once('/ksef/lib/ksef_import_ui.lib.php');

$langs->loadLangs(array("ksef@ksef", "bills", "companies"));

if (!$user->hasRight('ksef', 'read') || !empty($user->socid)) accessforbidden();

$action = GETPOST('action', 'aZ09');
$massaction = GETPOST('massaction', 'alpha');
$confirm = GETPOST('confirm', 'alpha');
$toselect = GETPOST('toselect', 'array');
$contextpage = GETPOST('contextpage', 'aZ09') ? GETPOST('contextpage', 'aZ09') : 'ksef_incoming_list';
$optioncss = GETPOST('optioncss', 'alpha');
$show_errors = GETPOST('show_errors', 'int');

$search_seller_nip = GETPOST('search_seller_nip', 'alpha');
$search_seller_name = GETPOST('search_seller_name', 'alpha');
$search_invoice_number = GETPOST('search_invoice_number', 'alpha');
$search_ksef_number = GETPOST('search_ksef_number', 'alpha');
$search_import_status = GETPOST('search_import_status', 'alpha');
$search_environment = ksefGetEnvironment();

$limit = GETPOST('limit', 'int') ? GETPOST('limit', 'int') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOST('pageplusone') - 1) : GETPOST("page", 'int');
if (empty($page) || $page < 0 || GETPOST('button_search', 'alpha') || GETPOST('button_removefilter', 'alpha')) $page = 0;
$offset = $limit * $page;

if (!$sortfield) {
    $sortfield = "i.invoice_date";
    $sortorder = "DESC";
}

$form = new Form($db);
$incoming = new KsefIncoming($db);
$ksef = new KSEFService($db);

$hookmanager->initHooks(array('ksef_incoming_list'));

ksefSyncAjax($ksef, $user, $action, 'incoming');

if ($action == 'batch_import_preview') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache');

    $importRights = ksefIncomingImportRights($user);
    if (!$importRights['import']) {
        echo json_encode(array('status' => 'ERROR', 'error' => 'Access denied'));
        exit;
    }

    $ids = GETPOST('ids', 'array');
    if (empty($ids)) {
        echo json_encode(array('status' => 'ERROR', 'error' => 'No invoices selected'));
        exit;
    }

    session_write_close();
    require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';

    $previews = array();
    $skipped = array();
    $supplierCache = array();

    foreach ($ids as $invoiceId) {
        $record = new KsefIncoming($db);
        if ($record->fetch((int)$invoiceId) <= 0) continue;
        if ($record->import_status != 'NEW' && $record->import_status != 'ERROR') {
            $skipped[] = array('id' => (int) $record->id, 'invoice_number' => $record->invoice_number ?: $record->ksef_number,
                'reason' => $langs->transnoentitiesnoconv($record->import_status === KsefIncoming::STATUS_FETCH_ERROR ? 'KSEF_FetchErrorNotImportable' : 'KSEF_BatchNotImportableStatus',
                    $langs->transnoentitiesnoconv('KSEF_ImportStatus' . $record->import_status)));
            continue;
        }

        $preview = array(
            'id' => $record->id,
            'invoice_number' => $record->invoice_number,
            'seller_name' => $record->seller_name,
            'seller_nip' => $record->seller_nip,
            'seller_vat_id' => $record->seller_vat_id,
            'invoice_date' => dol_print_date($record->invoice_date, 'day'),
            'total_gross' => price($record->total_gross, 0, $langs, 1, -1, -1, $record->currency),
            'currency' => $record->currency,
            'invoice_type' => $record->invoice_type,
        );

        $nip = preg_replace('/[^0-9]/', '', $record->seller_nip);
        $cacheKey = ($record->seller_country ?: 'PL') . '_' . $nip;
        if (isset($supplierCache[$cacheKey])) {
            $preview['socid'] = $supplierCache[$cacheKey]['socid'];
            $preview['supplier_name'] = $supplierCache[$cacheKey]['name'];
            $preview['supplier_matched'] = $supplierCache[$cacheKey]['matched'];
            $preview['party_role_missing'] = $supplierCache[$cacheKey]['role_missing'];
        } else {
            $socid = $record->findMatchingThirdParty();
            if ($socid > 0) {
                $soc = new Societe($db);
                $soc->fetch($socid);
                $preview['socid'] = $socid;
                $preview['supplier_name'] = $soc->name;
                $preview['supplier_matched'] = true;
                $preview['party_role_missing'] = ((int) $soc->fournisseur !== 1);
                $supplierCache[$cacheKey] = array('socid' => $socid, 'name' => $soc->name, 'matched' => true, 'role_missing' => $preview['party_role_missing']);
            } else {
                $preview['socid'] = 0;
                $preview['supplier_name'] = $record->seller_name;
                $preview['supplier_matched'] = false;
                $preview['party_role_missing'] = false;
                $supplierCache[$cacheKey] = array('socid' => 0, 'name' => $record->seller_name, 'matched' => false, 'role_missing' => false);
            }
        }

        $lines = $record->previewImportLines();
        $autoMatches = $record->autoMatchLineProducts($lines, (int) $preview['socid']);
        $totalLines = count($lines);
        $matchedLines = count($autoMatches);
        $preview['total_lines'] = $totalLines;
        $preview['matched_lines'] = $matchedLines;
        $preview['all_products_matched'] = ($matchedLines == $totalLines);

        $preview['lines'] = ksefBatchPreviewLines($lines, $autoMatches);

        $chk = $record->checkBatchCorrectionSource($ids);
        $preview['correction_blocked'] = $chk['blocked'];
        $preview['correction_issues'] = $chk['messages'];
        if ($chk['blocked']) {
            $preview['correction_issues'][] = $langs->transnoentitiesnoconv('KSEF_BatchCorrectionUseIndividualImport');
        }
        $currencyRefusal = $record->importCurrencyRefusal();
        if ($currencyRefusal !== '') {
            $preview['correction_blocked'] = true;
            $preview['correction_issues'][] = $currencyRefusal;
        }

        $previews[] = $preview;
    }

    echo json_encode(array('status' => 'OK', 'invoices' => $previews, 'skipped' => $skipped, 'rights' => $importRights));
    exit;
}

if ($action == 'gus_supplier_preview') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache');

    if (!$user->hasRight('ksef', 'write')) {
        echo json_encode(array('status' => 'ERROR', 'error' => 'Access denied'));
        exit;
    }
    if (empty(getDolGlobalString('KSEF_GUS_ENABLED'))) {
        echo json_encode(array('status' => 'OK', 'overrides' => array()));
        exit;
    }

    $rawInput = file_get_contents('php://input');
    $payload = json_decode($rawInput, true);
    $ids = (is_array($payload) && !empty($payload['ids']) && is_array($payload['ids'])) ? $payload['ids'] : array();
    if (empty($ids)) {
        echo json_encode(array('status' => 'OK', 'overrides' => array()));
        exit;
    }

    $parties = array();
    foreach ($ids as $rawId) {
        $record = new KsefIncoming($db);
        if ((int) $rawId > 0 && $record->fetch((int) $rawId) > 0) {
            $parties[] = array('nip' => $record->seller_nip, 'country' => $record->seller_country, 'name' => $record->seller_name, 'address' => $record->seller_address);
        }
    }
    echo json_encode(ksefBatchGusPreview($db, $parties, 'KSEF_GUS_SUPPLIER_DATA'));
    exit;
}

if ($action == 'batch_import_execute') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache');

    $importRights = ksefIncomingImportRights($user);
    if (!$importRights['import']) {
        echo json_encode(array('status' => 'ERROR', 'error' => 'Access denied'));
        exit;
    }

    $rawInput = file_get_contents('php://input');
    $payload = json_decode($rawInput, true);
    if (empty($payload) || !is_array($payload)) {
        echo json_encode(array('status' => 'ERROR', 'error' => 'Invalid request data'));
        exit;
    }

    $decisions = $payload['decisions'] ?? array();
    if (empty($decisions) || !is_array($decisions)) {
        echo json_encode(array('status' => 'ERROR', 'error' => 'Invalid request data'));
        exit;
    }

    $gusFetchSuppliers = !empty($payload['fetch_suppliers']) && !empty(getDolGlobalString('KSEF_GUS_ENABLED'));
    $gusSupplierData = $gusFetchSuppliers ? ksefSessionRead('KSEF_GUS_SUPPLIER_DATA', array(), false) : array();
    if (!is_array($gusSupplierData)) {
        $gusSupplierData = array();
    }

    session_write_close();
    require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
    require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';

    $results = array();
    $createdSuppliers = array();

    // one bad invoice
    $prevErrorHandler = set_error_handler(function ($errno, $errstr, $errfile, $errline) use (&$prevErrorHandler) {
        if ($errno === E_USER_ERROR) {
            throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
        }
        return $prevErrorHandler ? call_user_func($prevErrorHandler, $errno, $errstr, $errfile, $errline) : false;
    });

    $order = array();
    $orderIds = array_filter(array_map(function ($d) {
        return (int) ($d['id'] ?? 0);
    }, (array) $decisions));
    if ($orderIds) {
        $res = $db->query("SELECT rowid, invoice_type, invoice_date FROM " . MAIN_DB_PREFIX . "ksef_incoming WHERE rowid IN (" . implode(',', $orderIds) . ")");
        while ($res && ($o = $db->fetch_object($res))) {
            $order[(int) $o->rowid] = array(KsefIncoming::isCorrectionType($o->invoice_type) ? 1 : 0, (int) $o->invoice_date, (int) $o->rowid);
        }
    }
    usort($decisions, function ($x, $y) use ($order) {
        return ($order[(int) ($x['id'] ?? 0)] ?? array(2, 0, 0)) <=> ($order[(int) ($y['id'] ?? 0)] ?? array(2, 0, 0));
    });

    foreach ($decisions as $decision) {
        $invoiceId = (int)($decision['id'] ?? 0);
        if ($invoiceId <= 0) continue;
        $openTransactions = $db->transaction_opened;

        try {
            $record = new KsefIncoming($db);
            if ($record->fetch($invoiceId) <= 0) {
                $langs->load('errors');
                $results[] = array('id' => $invoiceId, 'status' => 'ERROR', 'error' => $langs->transnoentitiesnoconv('ErrorRecordNotFound'));
                continue;
            }
            if ($record->import_status != 'NEW' && $record->import_status != 'ERROR') {
                $results[] = array('id' => $invoiceId, 'status' => 'SKIPPED', 'error' => $langs->transnoentitiesnoconv('KSEF_BatchAlreadyProcessed'), 'invoice_number' => $record->invoice_number);
                continue;
            }

            $chk = $record->checkBatchCorrectionSource();
            if ($chk['blocked']) {
                $results[] = array('id' => $invoiceId, 'status' => 'ERROR', 'error' => implode('; ', $chk['messages']), 'invoice_number' => $record->invoice_number);
                continue;
            }
            // before creating parties
            $record->error = $record->importCurrencyRefusal();
            if ($record->error !== '') {
                $record->recordImportError();
                $results[] = array('id' => $invoiceId, 'status' => 'ERROR', 'error' => $record->error, 'invoice_number' => $record->invoice_number);
                continue;
            }
            $created = array();

            // never trust posted
            $nip = preg_replace('/[^0-9]/', '', $record->seller_nip);
            $supplierCacheKey = ($record->seller_country ?: 'PL') . '_' . $nip;
            $socid = isset($createdSuppliers[$supplierCacheKey]) ? $createdSuppliers[$supplierCacheKey] : $record->findMatchingThirdParty();
            $autoCreateSupplier = !empty($decision['auto_create_supplier']);
            $rowWarnings = array();

            if ($socid <= 0 && !$importRights['create_supplier']) {
                $results[] = array('id' => $invoiceId, 'status' => 'ERROR', 'error' => $langs->transnoentitiesnoconv('KSEF_BatchNoRightCreateSupplier'), 'invoice_number' => $record->invoice_number);
                continue;
            }

            if ($socid <= 0 && $autoCreateSupplier) {
                $gusForThis = (!empty($gusSupplierData[$nip])) ? $gusSupplierData[$nip] : null;
                if ($gusFetchSuppliers && $gusForThis === null && ($record->seller_country ?: 'PL') === 'PL') {
                    $rowWarnings[] = $langs->transnoentitiesnoconv('KSEF_BatchGusNoData');
                }
                $socid = $record->autoCreateSupplier($user, $gusForThis);
                if ($socid > 0) {
                    $createdSuppliers[$supplierCacheKey] = $socid;
                    $newSoc = new Societe($db);
                    $created[] = $langs->transnoentitiesnoconv('KSEF_BatchCreatedSupplier', $newSoc->fetch($socid) > 0 ? $newSoc->name : $record->seller_name);
                } else {
                    $results[] = array('id' => $invoiceId, 'status' => 'ERROR', 'error' => $langs->transnoentitiesnoconv('KSEF_BatchSupplierCreateFailed', ksefTranslateError($record->error)), 'invoice_number' => $record->invoice_number);
                    continue;
                }
            }

            if ($socid <= 0) {
                $results[] = array('id' => $invoiceId, 'status' => 'ERROR', 'error' => $langs->transnoentitiesnoconv('KSEF_ImportErrorNoSupplier'), 'invoice_number' => $record->invoice_number);
                continue;
            }

            $lines = $record->previewImportLines();
            $autoMatches = $record->autoMatchLineProducts($lines, $socid);
            $lineProductMap = array();
            foreach ($autoMatches as $ln => $match) {
                $lineProductMap[$ln] = $match['product_id'];
            }
            $autoCreateProducts = !empty($decision['auto_create_products']);
            if ($autoCreateProducts && !$importRights['create_product']) {
                $autoCreateProducts = false;
                if (count($lineProductMap) < count($lines)) {
                    $rowWarnings[] = $langs->transnoentitiesnoconv('KSEF_BatchNoRightCreateProduct');
                }
            }

            $createdProducts = array();
            if ($autoCreateProducts) {
                foreach ($lines as $line) {
                    $lineNum = $line['line_num'] ?? 0;
                    $line['socid'] = $socid;
                    $line['product_type'] = (int) ($decision['product_type'] ?? ($payload['product_type'] ?? 0));
                    if (!isset($lineProductMap[$lineNum]) && KsefIncoming::isProductLine($line)) {
                        $lineWarnings = array();
                        $productId = $record->autoCreateProduct($user, $line, $lineWarnings);
                        $rowWarnings = array_merge($rowWarnings, $lineWarnings);
                        if ($productId > 0) {
                            $lineProductMap[$lineNum] = $productId;
                            if ($record->created_product_ref !== '') {
                                $createdProducts[] = $record->created_product_ref;
                            }
                        } else {
                            $rowWarnings[] = $langs->transnoentitiesnoconv('KSEF_BatchProductNotCreated', $lineNum, $record->error);
                        }
                    }
                }
            }

            $result = $record->importToDolibarr($user, $socid, $lineProductMap, 0, '', array('warehouse_id' => (int) ($payload['options']['warehouse_id'] ?? 0)));
            if ($result > 0) {
                $results[] = array('id' => $invoiceId, 'status' => 'OK', 'facture_id' => $result, 'invoice_number' => $record->invoice_number, 'draft' => !$record->validated,
                    'warnings' => array_merge($rowWarnings, (array) $record->warnings));
            } else {
                $record->recordImportError();
                $error = ksefTranslateError($record->error);
                if ($createdProducts) {
                    $created[] = $langs->transnoentitiesnoconv('KSEF_BatchCreatedProducts', implode(', ', $createdProducts));
                }
                if ($created) {
                    $error .= ' ' . $langs->transnoentitiesnoconv('KSEF_BatchCreatedKept', implode('; ', $created));
                }
                $results[] = array('id' => $invoiceId, 'status' => 'ERROR', 'error' => $error, 'invoice_number' => $record->invoice_number);
            }
        } catch (Throwable $e) {
            while ($db->transaction_opened > $openTransactions) {
                $db->rollback();
            }
            dol_syslog('incoming_list batch_import_execute: invoice ' . $invoiceId . ': ' . get_class($e) . ' ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine(), LOG_ERR);
            $results[] = array('id' => $invoiceId, 'status' => 'ERROR', 'error' => $langs->transnoentitiesnoconv('KSEF_BatchUnexpectedError', $e->getMessage()),
                'invoice_number' => isset($record) && is_object($record) ? $record->invoice_number : '');
        }
    }
    restore_error_handler();

    $successCount = 0;
    $errorCount = 0;
    foreach ($results as $r) {
        if ($r['status'] == 'OK') $successCount++;
        elseif ($r['status'] == 'ERROR') $errorCount++;
    }

    echo json_encode(array('status' => 'OK', 'results' => $results, 'success_count' => $successCount, 'error_count' => $errorCount));
    exit;
}

$parameters = array();
$reshook = $hookmanager->executeHooks('doActions', $parameters, $incoming, $action);
if ($reshook < 0) setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');

if (empty($reshook)) {
    include DOL_DOCUMENT_ROOT . '/core/actions_changeselectedfields.inc.php';

    if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
        $search_seller_nip = '';
        $search_seller_name = '';
        $search_invoice_number = '';
        $search_ksef_number = '';
        $search_import_status = '';
        $search_environment = ksefGetEnvironment();
        $toselect = array();
    }

    if ($action == 'sync' && $user->hasRight('ksef', 'write')) {
        if (ksefSyncStart($ksef, $user)) {
            header('Location: ' . $_SERVER["PHP_SELF"] . '?polling=1');
            exit;
        }
    }

    if ($action == 'reset_fetch' && $user->hasRight('ksef', 'write')) {
        if (ksefSyncReset($ksef, $user, 'incoming')) {
            setEventMessages($langs->trans('KSEF_FetchReset'), null, 'mesgs');
        } else {
            setEventMessages($ksef->error, null, 'errors');
        }
        header('Location: ' . $_SERVER["PHP_SELF"]);
        exit;
    }

    if ($action == 'confirm_reset' && $confirm == 'yes' && $user->admin) {
        $resetFromTs = dol_mktime(0, 0, 0, GETPOSTINT('reset_frommonth'), GETPOSTINT('reset_fromday'), GETPOSTINT('reset_fromyear'));
        $resetFrom = $resetFromTs ? dol_print_date($resetFromTs, '%Y-%m-%d') : '';
        if ($ksef->resetIncomingSyncState($user, 'incoming', $resetFrom)) {
            setEventMessages($langs->trans('KSEF_SyncStateReset'), null, 'mesgs');
        } else {
            setEventMessages(!empty($ksef->error) ? $ksef->error : $langs->trans('Error'), null, 'errors');
        }
    }

    if (!empty($massaction) && !empty($toselect)) {
        if ($massaction == 'retry_fetch' && $user->hasRight('ksef', 'write')) {
            $rr = $incoming->reprocessMany($user, $toselect);
            setEventMessages($langs->trans('KSEF_RetryFetchMassResult', $rr['done'], $rr['failed'], $rr['skipped']), null, $rr['failed'] ? 'warnings' : 'mesgs');
            header("Location: " . $_SERVER["PHP_SELF"]);
            exit;
        }
    }
    // empty massaction
    if ((($massaction == 'delete' && GETPOST('confirmmassaction', 'alpha')) || ($action == 'delete' && $confirm == 'yes')) && !empty($toselect)) {
        if (!ksefUserCan($user, 'delete_incoming')) accessforbidden();
        $num_deleted = 0;
        $num_skipped = 0;
        $num_errors = 0;
        foreach ($toselect as $toselectid) {
            $record = new KsefIncoming($db);
            if ($record->fetch((int) $toselectid) <= 0) {
                continue;
            }
            $r = $record->delete($user);
            if ($r > 0) {
                $num_deleted++;
            } elseif ($r == -2) {
                $num_skipped++;
            } else {
                $num_errors++;
            }
        }
        $msg = $langs->trans('KSEF_MassDeleteResult', $num_deleted, $num_skipped);
        if ($num_errors) {
            setEventMessages($msg . ' ' . $langs->trans('KSEF_MassDeleteErrors', $num_errors), null, 'errors');
        } else {
            setEventMessages($msg, null, $num_skipped ? 'warnings' : 'mesgs');
        }
        header("Location: " . $_SERVER["PHP_SELF"] . '?restore_lastsearch_values=1');
        exit;
    }

    if ($action == 'confirm_clearall' && $confirm == 'yes' && $user->admin) {
        $environment = ksefGetEnvironment();
        $deleted = $incoming->deleteAll($user, $environment);
        if ($deleted >= 0) {
            setEventMessages($langs->trans("KSEF_ClearedRecords", $deleted, ksefEnvironmentLabel($environment)), null, 'mesgs');
            if ($incoming->kept_count > 0) {
                setEventMessages($langs->trans("KSEF_ClearAllKeptImported", $incoming->kept_count), null, 'warnings');
            }
        } else {
            setEventMessages($incoming->error, null, 'errors');
        }
    }

    if ($action == 'download_xml' && GETPOST('id', 'int')) {
        $id = GETPOST('id', 'int');
        $record = new KsefIncoming($db);
        if ($record->fetch($id) > 0 && !empty($record->fa3_xml)) {
            ksefSendDownload($record->fa3_xml, ksefDownloadName('FA3', $record->ksef_number, 'incoming_' . $id, 'xml'), 'application/xml');
            exit;
        }
        setEventMessages($langs->trans('KSEF_XMLNotAvailable'), null, 'warnings');
    }

    if ($action == 'download_pdf' && GETPOST('id', 'int')) {
        $id = GETPOST('id', 'int');
        $record = new KsefIncoming($db);
        if ($record->fetch($id) > 0 && !empty($record->fa3_xml)) {
            $pdfContent = $record->generatePdfVisualization();
            if ($pdfContent) {
                ksefSendDownload($pdfContent, ksefDownloadName('FA3', $record->ksef_number, 'incoming_' . $id, 'pdf'), 'application/pdf');
                exit;
            }
        }
        setEventMessages($langs->trans('KSEF_PdfGenerationFailed'), null, 'warnings');
    }
}

$title = $langs->trans('KSEF_IncomingInvoices');
llxHeader('', $title, ksefHelpUrl('receiving'), '', 0, 0, '', '', '', 'mod-ksef page-incoming-list');

print load_fiche_titre($title, ksefHelpTitleButton($user, 'incoming', array('receiving' => 'KSEF_ReceivingInvoices'), array('recv_fetch', 'recv_import', 'recv_kor', 'recv_deposits')), 'ksef@ksef');

if ($action == 'clearall') {
    $environment = ksefGetEnvironment();
    print $form->formconfirm($_SERVER["PHP_SELF"], $langs->trans('KSEF_ClearAll'), $langs->trans('KSEF_ConfirmClearAll', ksefEnvironmentLabel($environment)), 'confirm_clearall', '', 0, 1);
}

if ($action == 'reset') {
    $resetQuestion = array(array('type' => 'date', 'name' => 'reset_from', 'label' => $langs->trans('KSEF_ResetSyncFromDate'), 'value' => -1));
    print $form->formconfirm($_SERVER["PHP_SELF"], $langs->trans('KSEF_ResetSyncState'), $langs->trans('KSEF_ConfirmResetSync') . '<br><span class="opacitymedium">' . $langs->trans('KSEF_ResetSyncFromDateHelp') . '</span>', 'confirm_reset', $resetQuestion, 0, 1, 360, 560);
}

$environment = ksefGetEnvironment();
$syncState = $ksef->getIncomingSyncState();
$syncState->getDisplayValues();

$currentFetchPhase = $syncState->fetch_status;
ksefPrintSyncAuditNotice('incoming');
$syncStates = ksefSyncStates($ksef, $user);
$syncStates['incoming'] = $syncState;
ksefPrintFetchOverlay($syncStates);

print '<div class="fichecenter">';
print '<div class="fichethirdleft">';

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder nohover centpercent">';
print '<tr class="liste_titre"><th colspan="2">' . $langs->trans("KSEF_SyncFromKSeF") . '</th></tr>';

$latarnia_cached = KsefLatarnia::getCachedStatus();
print '<tr class="oddeven"><td class="titlefield">' . $langs->trans("KSEF_SystemStatus") . '</td>';
print '<td>' . ksefGetLatarniaStatusBadge($latarnia_cached['status']) . '</td></tr>';

print '<tr class="oddeven"><td class="titlefield">' . $langs->trans("KSEF_ENVIRONMENT") . '</td>';
print '<td>' . ksefGetEnvironmentBadge($environment) . '</td></tr>';

if ($syncState) {
    print '<tr class="oddeven"><td>' . $langs->trans("KSEF_SyncContinuationPoint") . '</td>';
    print '<td>' . ($syncState->continuation_date ? dol_print_date($syncState->continuation_date, 'dayhour') : '<span class="opacitymedium">' . $langs->trans("NotSet") . '</span>') . '</td></tr>';

    print '<tr class="oddeven"><td>' . $langs->trans("KSEF_LastSync") . '</td>';
    print '<td>' . ($syncState->last_sync_date ? dol_print_date($syncState->last_sync_date, 'dayhour') : '<span class="opacitymedium">' . $langs->trans("Never") . '</span>') . '</td></tr>';

    if ($syncState->last_sync_date) {
        print '<tr class="oddeven ksef-sync-result-incoming"><td>' . $langs->trans("KSEF_LastSyncResultPurchase") . '</td>';
        print '<td>' . ksefSyncResultText($syncState) . '</td></tr>';
    }
    // sales side too
    $outState = isset($syncStates['outgoing']) ? $syncStates['outgoing'] : null;
    if ($outState && $outState->last_sync_date) {
        print '<tr class="oddeven ksef-sync-result-outgoing"><td>' . $langs->trans("KSEF_LastSyncResultSales") . '</td>';
        print '<td>' . ksefSyncResultText($outState) . ' <a href="' . dol_buildpath('/ksef/status.php', 1) . '">' . $langs->trans('KSEF_OutgoingInvoices') . '</a></td></tr>';
    }

    if ($syncState->isRateLimited()) {
        $retryTime = $syncState->getRateLimitExpiryFormatted();
        print '<tr class="oddeven"><td colspan="2"><span class="warning">';
        print '<span class="fa fa-clock"></span> ' . $langs->trans("KSEF_RateLimitActive", $retryTime);
        print '</span></td></tr>';
    }

    if ($syncState->isFetchInProgress() && !empty($currentFetchPhase)) {
        $phaseLabel = '';
        switch ($currentFetchPhase) {
            case KsefSyncState::FETCH_STATUS_PROCESSING:
                $phaseLabel = $langs->trans("KSEF_PhaseWaitingKSeF");
                break;
            case KsefSyncState::FETCH_STATUS_READY_TO_PROCESS:
                $phaseLabel = $langs->trans("KSEF_PhaseReadyToProcess");
                break;
            case KsefSyncState::FETCH_STATUS_DOWNLOADING:
                $phaseLabel = $langs->trans("KSEF_PhaseDownloading");
                break;
            case KsefSyncState::FETCH_STATUS_PROCESSING_BATCHES:
                $phaseLabel = $langs->trans("KSEF_PhaseProcessingBatches", $syncState->process_offset, $syncState->process_total);
                break;
        }
        if ($phaseLabel) {
            print '<tr class="oddeven"><td>' . $langs->trans("KSEF_CurrentStatus") . '</td>';
            print '<td><span class="fa fa-spinner fa-spin" style="margin-right:5px;"></span>' . $phaseLabel . '</td></tr>';
        }
    }
} else {
    print '<tr class="oddeven"><td colspan="2"><span class="opacitymedium">';
    print $langs->trans("KSEF_SyncNotInitialized");
    print '</span></td></tr>';
}

$cronJobs = ksefGetCronJobs($db);
$cronProblem = ksefCronEngineProblem($db, $cronJobs);
print '<tr class="oddeven ksef-cron-incoming"><td>' . $langs->trans("KSEF_CronJob_cronSyncIncoming") . '</td><td>';
if ($cronProblem !== '') {
    print '<span class="badge ' . ksefToneBadgeClass('problem') . ' badge-status">' . $langs->trans($cronProblem === 'off' ? 'KSEF_CronEngineOff' : ($cronProblem === 'never' ? 'KSEF_CronEngineNever' : 'KSEF_CronEngineStalled')) . '</span>';
} elseif (isset($cronJobs['cronSyncIncoming'])) {
    print ksefCronJobStatusHtml($cronJobs['cronSyncIncoming']);
} else {
    print '<span class="opacitymedium">' . $langs->trans('KSEF_CronJobMissing') . '</span>';
}
print '</td></tr>';

print '</table>';
print '</div>';

print '<div class="center" style="margin-top: 15px;">';
if ($user->hasRight('ksef', 'write')) {
    ksefPrintSyncButton($syncStates, $_SERVER["PHP_SELF"]);
}
print '</div>';

print '</div>';

print '<div class="fichetwothirdright">';

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder nohover centpercent">';
print '<tr class="liste_titre"><th colspan="2"><span class="fa fa-file-download paddingright opacitymedium"></span>' . $langs->trans("KSEF_IncomingStatistics") . '</th></tr>';

print ksefStatRowsHtml('incoming', $incoming->countForStats($environment));

ksefPrintSyncCoverageRow($syncState, (bool) $user->admin);

print '</table>';
print '</div>';

if ($user->admin) {
    ksefPrintAdminOptionsDialog($environment, 'KSEF_AdminClearAllDesc');
}
print '</div>';
print '</div>';

print '<div class="clearboth"></div>';
print '<br>';

$lastSyncErrors = array();
if ($syncState && !empty($syncState->last_sync_errors)) {
    $lastSyncErrors = json_decode($syncState->last_sync_errors, true);
    if (!is_array($lastSyncErrors)) $lastSyncErrors = array();
}
if (!empty($lastSyncErrors)) {
    $errCount = count($lastSyncErrors);
    print '<div class="warning" style="padding: 8px 12px; margin-bottom: 10px;">';
    print '<span class="fa fa-exclamation-triangle" style="margin-right: 6px;"></span> ';
    print $langs->trans('KSEF_SyncErrorsExist', $errCount);
    if (empty($show_errors)) {
        print ' <a class="butAction small" href="' . $_SERVER["PHP_SELF"] . '?show_errors=1&token=' . newToken() . '">';
        print '<span class="fa fa-eye" style="margin-right: 4px;"></span>' . $langs->trans('KSEF_ViewErrors') . '</a>';
    } else {
        print ' <a class="butAction small" href="' . $_SERVER["PHP_SELF"] . '?token=' . newToken() . '">';
        print '<span class="fa fa-eye-slash" style="margin-right: 4px;"></span>' . $langs->trans('KSEF_HideErrors') . '</a>';
    }
    print '</div>';

    if (!empty($show_errors)) {
        print '<div class="div-table-responsive-no-min">';
        print '<table class="noborder centpercent">';
        print '<tr class="liste_titre">';
        print '<th>' . $langs->trans('KSEF_ErrorKsefNumber') . '</th>';
        print '<th>' . $langs->trans('KSEF_ErrorMessage') . '</th>';
        print '</tr>';

        foreach ($lastSyncErrors as $errLine) {
            $parts = explode(': ', (string) $errLine, 2);
            $errKsef = count($parts) === 2 ? $parts[0] : '';
            $errMsg = count($parts) === 2 ? $parts[1] : (string) $errLine;
            print '<tr class="oddeven">';
            print '<td class="tdoverflowmax200">' . dol_escape_htmltag($errKsef) . '</td>';
            print '<td class="small">' . dol_escape_htmltag($errMsg) . '</td>';
            print '</tr>';
        }

        print '</table>';
        print '</div>';
        print '<br>';
    }
}

$filters = array();
if (!empty($search_seller_nip)) $filters['seller_nip'] = $search_seller_nip;
if (!empty($search_seller_name)) $filters['seller_name'] = $search_seller_name;
if (!empty($search_invoice_number)) $filters['invoice_number'] = $search_invoice_number;
if (!empty($search_ksef_number)) $filters['ksef_number'] = $search_ksef_number;
if (!empty($search_import_status) && $search_import_status != '-1') $filters['import_status'] = $search_import_status;
if ($search_environment !== '' && $search_environment != '-1') {
    $filters['environment'] = $search_environment;
}

$nbtotalofrecords = $incoming->countAll($filters);

if (($page * $limit) > $nbtotalofrecords) {
    $page = 0;
    $offset = 0;
}

$param = '';
if (!empty($contextpage) && $contextpage != $_SERVER["PHP_SELF"]) $param .= '&contextpage=' . urlencode($contextpage);
if ($limit > 0 && $limit != $conf->liste_limit) $param .= '&limit=' . ((int)$limit);
if (!empty($search_seller_nip)) $param .= '&search_seller_nip=' . urlencode($search_seller_nip);
if (!empty($search_seller_name)) $param .= '&search_seller_name=' . urlencode($search_seller_name);
if (!empty($search_invoice_number)) $param .= '&search_invoice_number=' . urlencode($search_invoice_number);
if (!empty($search_ksef_number)) $param .= '&search_ksef_number=' . urlencode($search_ksef_number);
if (!empty($search_import_status)) $param .= '&search_import_status=' . urlencode($search_import_status);
if ($search_environment !== ksefGetEnvironment()) $param .= '&search_environment=' . urlencode($search_environment);
if ($optioncss != '') $param .= '&optioncss=' . urlencode($optioncss);

$batchRights = ksefIncomingImportRights($user);
$arrayofmassactions = array();
if ($batchRights['import']) {
    $arrayofmassactions['batch_import'] = img_picto('', 'download', 'class="pictofixedwidth"') . $langs->trans("KSEF_BatchImport");
}
if ($user->hasRight('ksef', 'write')) {
    $arrayofmassactions['retry_fetch'] = img_picto('', 'refresh', 'class="pictofixedwidth"') . $langs->trans("KSEF_RetryFetch");
}
if (ksefUserCan($user, 'delete_incoming')) {
    $arrayofmassactions['predelete'] = img_picto('', 'delete', 'class="pictofixedwidth"') . $langs->trans("Delete");
}
$massactionbutton = $form->selectMassAction('', $arrayofmassactions);

$arrayofselected = is_array($toselect) ? $toselect : array();

$selectedfields = '';
if (count($arrayofmassactions)) {
    $selectedfields .= $form->showCheckAddButtons('checkforselect', 1);
}

$errRec = new KsefIncoming($db);
$sqlerr = "SELECT import_status, import_error FROM " . MAIN_DB_PREFIX . "ksef_incoming";
$sqlerr .= " WHERE entity IN (" . getEntity($errRec->element) . ") AND environment = '" . $db->escape($environment) . "'";
$sqlerr .= " AND import_status IN ('" . KsefIncoming::STATUS_ERROR . "', '" . KsefIncoming::STATUS_FETCH_ERROR . "')";
$sqlerr .= " AND (import_error IS NULL OR import_error NOT LIKE '" . $db->escape(KsefIncoming::UNSUPPORTED_FORM_PREFIX) . "%')";
$resqlerr = $db->query($sqlerr);
$errGroups = array();
while ($resqlerr && ($objerr = $db->fetch_object($resqlerr))) {
    if ($objerr->import_status == KsefIncoming::STATUS_FETCH_ERROR) {
        $errRec->import_error = (string) $objerr->import_error;
        $why = $errRec->getFetchErrorText();
        if (strpos((string) $objerr->import_error, 'Failed to parse XML') === 0) {
            $why = $langs->transnoentitiesnoconv('KSEF_FetchErrorParse');
        }
        $label = $langs->transnoentitiesnoconv('KSEF_ImportStatusFETCH_ERROR');
    } else {
        $why = dol_trunc((string) $objerr->import_error, 120);
        $label = $langs->transnoentitiesnoconv('KSEF_ImportStatusERROR');
    }
    $k = $label . '|' . $why;
    if (!isset($errGroups[$k])) {
        $errGroups[$k] = array($label, $why, 0);
    }
    $errGroups[$k][2]++;
}
usort($errGroups, function ($a, $b) {
    return $b[2] - $a[2];
});
ksefPrintErrorSummary($errGroups, in_array($search_import_status, array('ERROR', 'FETCH_ERROR'), true));

print '<form method="POST" id="searchFormList" action="' . $_SERVER["PHP_SELF"] . '" name="searchFormList">';
if ($optioncss != '') print '<input type="hidden" name="optioncss" value="' . $optioncss . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="formfilteraction" id="formfilteraction" value="list">';
print '<input type="hidden" name="action" value="list">';
print '<input type="hidden" name="sortfield" value="' . $sortfield . '">';
print '<input type="hidden" name="sortorder" value="' . $sortorder . '">';
print '<input type="hidden" name="page" value="' . $page . '">';
print '<input type="hidden" name="contextpage" value="' . $contextpage . '">';

// +1: next page
$records = $incoming->fetchAll($filters, $sortfield, $sortorder, $limit ? $limit + 1 : 0, $offset);
$num = is_array($records) ? count($records) : 0;
print_barre_liste($langs->trans("List"), $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, $massactionbutton, $num, $nbtotalofrecords, '', 0, '', '', $limit, 0, 0, 1);
print ksefOtherEnvRowsHint($db, $environment, array('incoming'), 'KSEF_IncomingOtherEnvHint');

$topicmail = '';
$modelmail = '';
$objecttmp = new KsefIncoming($db);
$trackid = 'ksefi';
include DOL_DOCUMENT_ROOT.'/core/tpl/massactions_pre.tpl.php';
if ($massaction == 'predelete') {
    print '<div class="warning">' . $langs->trans('KSEF_DeleteNotRefetched') . '</div>';
}

print '<div class="div-table-responsive ksef-list-scroll">';
print '<table class="tagtable nobottomiftotal liste">' . "\n";

print '<tr class="liste_titre_filter">';
if (getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
    print '<td class="liste_titre center maxwidthsearch">' . $form->showFilterButtons('left') . '</td>';
}
print '<td class="liste_titre left"><input class="flat maxwidth75" type="text" name="search_invoice_number" value="' . dol_escape_htmltag($search_invoice_number) . '"></td>';
print '<td class="liste_titre left"><input class="flat maxwidth75" type="text" name="search_ksef_number" value="' . dol_escape_htmltag($search_ksef_number) . '"></td>';
print '<td class="liste_titre center"></td>';
print '<td class="liste_titre left"><input class="flat maxwidth75" type="text" name="search_seller_name" value="' . dol_escape_htmltag($search_seller_name) . '" placeholder="' . $langs->trans('KSEF_Seller') . '">';
print '<br><input class="flat maxwidth75" type="text" name="search_seller_nip" value="' . dol_escape_htmltag($search_seller_nip) . '" placeholder="NIP"></td>';
print '<td class="liste_titre center">';
print $form->selectarray('search_import_status', array(
    'NEW' => $langs->trans('KSEF_ImportStatusNEW'),
    'IMPORTED' => $langs->trans('KSEF_ImportStatusIMPORTED'),
    'SKIPPED' => $langs->trans('KSEF_ImportStatusSKIPPED'),
    'ERROR' => $langs->trans('KSEF_ImportStatusERROR'),
    'FETCH_ERROR' => $langs->trans('KSEF_ImportStatusFETCH_ERROR'),
    'FETCH_ERROR_UNSUPPORTED' => $langs->trans('KSEF_FetchErrorUnsupported'),
), $search_import_status, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth100');
print '</td>';
print '<td class="liste_titre center"></td>';
print '<td class="liste_titre center"></td>';
print '<td class="liste_titre right"></td>';
print '<td class="liste_titre center"></td>';
if (!getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
    print '<td class="liste_titre center maxwidthsearch">' . $form->showFilterButtons() . '</td>';
}
print '</tr>' . "\n";

print '<tr class="liste_titre">';
if (getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) print getTitleFieldOfList($selectedfields, 0, $_SERVER["PHP_SELF"], '', '', '', '', $sortfield, $sortorder, 'center maxwidthsearch ') . "\n";
print_liste_field_titre("Invoice", $_SERVER["PHP_SELF"], "i.invoice_number", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre("KSEF_Number", $_SERVER["PHP_SELF"], "i.ksef_number", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre("Type", $_SERVER["PHP_SELF"], "i.invoice_type", "", $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre("KSEF_Seller", $_SERVER["PHP_SELF"], "i.seller_name", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre("Status", $_SERVER["PHP_SELF"], "i.import_status", "", $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre("KSEF_LinkedInvoice", $_SERVER["PHP_SELF"], "", "", $param, '', '', '', '');
print_liste_field_titre("Date", $_SERVER["PHP_SELF"], "i.invoice_date", "", $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre("Amount", $_SERVER["PHP_SELF"], "i.total_gross", "", $param, '', $sortfield, $sortorder, 'right ');
print_liste_field_titre("KSEF_ListActions", $_SERVER["PHP_SELF"], "", "", $param, '', '', '', 'center maxwidthsearch');
if (!getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) print getTitleFieldOfList($selectedfields, 0, $_SERVER["PHP_SELF"], '', '', '', '', $sortfield, $sortorder, 'center maxwidthsearch ') . "\n";
print '</tr>' . "\n";

if ($num > 0) {
    $rowActs = array();
    ob_start();
    // tour frames these
    $origKnown = array();
    foreach (($limit ? array_slice($records, 0, $limit) : $records) as $obj) {
        if (KsefIncoming::isCorrectionType($obj->invoice_type) && (string) $obj->corrected_ksef_number !== '') {
            $origKnown[(string) $obj->corrected_ksef_number] = 0;
        }
    }
    if ($origKnown) {
        $res = $db->query("SELECT ksef_number FROM " . MAIN_DB_PREFIX . "ksef_incoming WHERE entity IN (" . getEntity('ksef_incoming') . ") AND ksef_number IN ('"
            . implode("','", array_map(array($db, 'escape'), array_keys($origKnown))) . "')");
        while ($res && ($o = $db->fetch_object($res))) {
            $origKnown[(string) $o->ksef_number] = 1;
        }
    }
    // one query
    $linkedInvoices = array();
    $linkedIds = array();
    foreach (($limit ? array_slice($records, 0, $limit) : $records) as $obj) {
        if ($obj->fk_facture_fourn > 0) {
            $linkedIds[] = (int) $obj->fk_facture_fourn;
        }
    }
    if ($linkedIds) {
        $res = $db->query("SELECT rowid, ref, ref_supplier, type, subtype, fk_statut, paye, close_code, libelle, total_ht, total_tva, localtax1, localtax2, total_ttc, datef, date_lim_reglement"
            . " FROM " . MAIN_DB_PREFIX . "facture_fourn WHERE rowid IN (" . implode(',', array_unique($linkedIds)) . ")");
        while ($res && ($o = $db->fetch_object($res))) {
            $linkedInvoices[(int) $o->rowid] = $o;
        }
    }
    $linkedInv = new FactureFournisseur($db);
    $pickKinds = ksefPickKindsOfRows($db, array('recv_import', 'recv_kor', 'recv_deposits'), array_column(($limit ? array_slice($records, 0, $limit) : $records), 'rowid'));
    foreach (($limit ? array_slice($records, 0, $limit) : $records) as $obj) {
        print '<tr class="oddeven" data-ksef-type="' . dol_escape_htmltag((string) $obj->invoice_type) . '"'
            . (!empty($origKnown[(string) $obj->corrected_ksef_number]) ? ' data-ksef-orig="1"' : '') . ksefPickRowAttrs($pickKinds, $obj->rowid) . '>';

        if (getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
            print '<td class="nowrap center">';
            if ($massactionbutton || $massaction) print '<input id="cb' . $obj->rowid . '" class="flat checkforselect" type="checkbox" name="toselect[]" value="' . $obj->rowid . '"' . (in_array($obj->rowid, $arrayofselected) ? ' checked="checked"' : '') . '>';
            print '</td>';
        }

        print '<td class="minwidth75 maxwidth150 nowraponall">';
        print '<a href="' . dol_buildpath('/ksef/incoming_card.php', 1) . '?id=' . $obj->rowid . '&save_lastsearch_values=1">';
        print img_object($langs->trans("Show"), 'bill', 'class="pictofixedwidth"');
        print '<span class="ksef-wrap-text">' . dol_escape_htmltag($obj->invoice_number !== '' && $obj->invoice_number !== null ? $obj->invoice_number : $obj->ksef_number) . '</span>';
        print '</a>';
        print '</td>';

        print '<td class="nowraponall">';
        if (!empty($obj->ksef_number)) {
            $knum = (string) $obj->ksef_number;
            $verifyUrl = !empty($obj->fa3_xml) && $obj->import_status !== KsefIncoming::STATUS_FETCH_ERROR
                ? ksefGetVerificationUrlFromXml($knum, $obj->fa3_xml, !empty($obj->environment) ? $obj->environment : $environment) : '';
            if ($verifyUrl !== '') {
                print '<a href="' . dol_escape_htmltag($verifyUrl) . '" target="_blank" rel="noopener noreferrer" class="classfortooltip ksef-num-badge" title="' . dol_escape_htmltag($knum . ' - ' . $langs->transnoentitiesnoconv("KSEF_VerifyOnKSEFPortal")) . '" aria-label="' . dol_escape_htmltag($langs->transnoentitiesnoconv("KSEF_VerifyOnKSEFPortal")) . '"><span class="badge badge-info ' . ksefToneClass('id') . '">' . ksefNumberBadgeText($knum) . '</span></a>';
            } else {
                print '<span class="classfortooltip ksef-num-badge" title="' . dol_escape_htmltag($knum) . '"><span class="badge badge-info ' . ksefToneClass('id') . '">' . ksefNumberBadgeText($knum) . '</span></span>';
            }
        } else {
            print '<span class="opacitymedium">-</span>';
        }
        print '</td>';

        print '<td class="center nowraponall">' . ksefGetInvoiceTypeBadge($obj->invoice_type) . '</td>';

        print '<td class="minwidth75 maxwidth150 ksef-wrap">' . dol_escape_htmltag($obj->seller_name);
        $sellerNip = trim((string) ksefFormatNIP($obj->seller_nip));
        if ($sellerNip !== '') {
            print '<br><span class="opacitymedium small nowraponall">NIP: ' . $sellerNip . '</span>';
        }
        print '</td>';
        print '<td class="center nowraponall">' . ksefGetIncomingStatusBadge($obj->import_status, $obj->import_error);
        if ($obj->import_status == KsefIncoming::STATUS_FETCH_ERROR) {
            $isUnsup = strpos((string) $obj->import_error, KsefIncoming::UNSUPPORTED_FORM_PREFIX) === 0;
            print ' <span class="fa ' . ($isUnsup ? 'fa-info-circle opacitymedium' : 'fa-exclamation-triangle ksef-error-icon') . ' classfortooltip" title="' . ksefEscapeText(ksefEscapeText($obj->getFetchErrorText())) . '"></span>';
        }
        print '</td>';

        print '<td class="tdoverflowmax150">';
        $o = $obj->fk_facture_fourn > 0 ? ($linkedInvoices[(int) $obj->fk_facture_fourn] ?? null) : null;
        if ($o) {
            $linkedInv->id = $o->rowid;
            $linkedInv->ref = $o->ref;
            $linkedInv->ref_supplier = $o->ref_supplier;
            $linkedInv->type = $o->type;
            $linkedInv->subtype = $o->subtype;
            $linkedInv->statut = $o->fk_statut;
            $linkedInv->status = $o->fk_statut;
            $linkedInv->paye = $o->paye;
            $linkedInv->paid = $o->paye;
            $linkedInv->close_code = $o->close_code;
            $linkedInv->label = $o->libelle;
            $linkedInv->total_ht = $o->total_ht;
            $linkedInv->total_tva = $o->total_tva;
            $linkedInv->total_localtax1 = $o->localtax1;
            $linkedInv->total_localtax2 = $o->localtax2;
            $linkedInv->total_ttc = $o->total_ttc;
            $linkedInv->date = $db->jdate($o->datef);
            $linkedInv->date_echeance = $db->jdate($o->date_lim_reglement);
            print $linkedInv->getNomUrl(1);
        }
        print '</td>';

        print '<td class="center">' . dol_print_date($obj->invoice_date, 'day') . '</td>';
        print '<td class="right nowraponall">' . price($obj->total_gross, 0, $langs, 1, -1, -1, !empty($obj->currency) ? $obj->currency : $conf->currency) . '</td>';

        print '<td class="center nowraponall">';
        $act = array();
        if (($obj->import_status == 'NEW' || $obj->import_status == 'ERROR') && $batchRights['import']) {
            $act['import'] = '<a class="butAction classfortooltip" href="' . dol_buildpath('/ksef/incoming_import.php', 1) . '?id=' . $obj->rowid . '&action=quickimport&save_lastsearch_values=1&token=' . newToken() . '" title="' . $langs->trans("KSEF_Import") . '" aria-label="' . $langs->trans("KSEF_Import") . '"><span class="fa fa-file-import"></span></a>';
        }
        $fetchErr = ($obj->import_status === KsefIncoming::STATUS_FETCH_ERROR);
        if (!empty($obj->fa3_xml) && $fetchErr) {
            $act['xml'] = ksefActIcon('xml', $_SERVER["PHP_SELF"] . '?action=download_xml&id=' . $obj->rowid . '&token=' . newToken(), $langs->trans("KSEF_DownloadXml"), 'fa-file-code');
        } elseif (!empty($obj->fa3_xml)) {
            $act['xml'] = ksefActIcon('xml', $_SERVER["PHP_SELF"] . '?action=download_xml&id=' . $obj->rowid . '&token=' . newToken(), $langs->trans("KSEF_DownloadXml"), 'fa-file-code');
            $act['pdf'] = ksefActIcon('pdf', $_SERVER["PHP_SELF"] . '?action=download_pdf&id=' . $obj->rowid . '&token=' . newToken(), $langs->trans("KSEF_DownloadPdf"), 'fa-file-pdf');
        }
        print ksefRowActionsHold($rowActs, $act);
        print '</td>';

        if (!getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
            print '<td class="nowrap center">';
            if ($massactionbutton || $massaction) print '<input id="cb' . $obj->rowid . '" class="flat checkforselect" type="checkbox" name="toselect[]" value="' . $obj->rowid . '"' . (in_array($obj->rowid, $arrayofselected) ? ' checked="checked"' : '') . '>';
            print '</td>';
        }

        print '</tr>' . "\n";
    }
    print ksefRowActionsFill(ob_get_clean(), array('import', 'xml', 'pdf'), $rowActs);
} else {
    $colspan = 10;
    $filtered = (!empty($search_seller_nip) || !empty($search_seller_name) || !empty($search_invoice_number) || !empty($search_ksef_number)
        || (!empty($search_import_status) && $search_import_status != '-1'));
    $emptyText = $filtered ? $langs->trans('KSEF_NoRecordMatchFilters')
        : $langs->trans('KSEF_IncomingEmptyInEnv', ($search_environment !== '' && $search_environment != '-1') ? ksefEnvironmentLabel($search_environment) : $langs->trans('All'));
    print '<tr><td colspan="' . $colspan . '"><span class="opacitymedium">' . $emptyText . '</span></td></tr>';
}

print '</table>';
print '</div>';
print '</form>';

$stockEnabled = getDolGlobalString('STOCK_CALCULATE_ON_SUPPLIER_BILL');
$whSelect = ksefImportWarehouseSelect($db, $user, 'incoming');
ksefPrintBatchImportDialog(array(
    'direction' => 'incoming',
    'massaction' => 'batch_import',
    'value_prefix' => '',
    'rights' => array('import' => $batchRights['import'], 'create_party' => $batchRights['create_supplier'], 'create_product' => $batchRights['create_product']),
    'party_box_on' => ksefImportPartyBoxDefault('incoming'),
    'product_box_on' => getDolGlobalString('KSEF_BATCH_AUTO_CREATE_PRODUCTS', '1'),
    'extra_options_html' => $whSelect !== '' ? $langs->trans('SelectWarehouseForStockIncrease') . ' ' . $whSelect : '',
    'stock_short' => isModEnabled('stock') ? $langs->trans($stockEnabled ? 'KSEF_StockWillIncrease' : 'KSEF_StockWillNotIncrease') : '',
    'stock_verbose' => isModEnabled('stock') ? $langs->trans($stockEnabled ? 'KSEF_StockAutoIncreaseEnabled' : 'KSEF_StockAutoIncreaseDisabled') : '',
));

print ksefHelpFooter($db, $user);
llxFooter();
$db->close();