<?php
/* Copyright (C) 2025 InPoint Automation Sp z o.o.
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
 * \file    ksef/status.php
 * \ingroup ksef
 * \brief   Outgoing invoices list
 */

if (!defined('CSRFCHECK_WITH_TOKEN')) {
    define('CSRFCHECK_WITH_TOKEN', '1');
}

// no reload
$action_raw = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
if (in_array($action_raw, array('download_xml', 'download_upo', 'download_sales_xml', 'download_sales_pdf', 'init_fetch', 'check_fetch_status', 'process_outgoing', 'process_fetch', 'upo_sweep', 'batch_import_preview', 'gus_supplier_preview', 'batch_import_execute'))) {
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
require_once DOL_DOCUMENT_ROOT . '/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
dol_include_once('/ksef/class/ksef_submission.class.php');
dol_include_once('/ksef/class/ksef_service.class.php');
dol_include_once('/ksef/class/ksef_latarnia.class.php');
dol_include_once('/ksef/class/ksef_sales.class.php');
dol_include_once('/ksef/class/ksef_sync_state.class.php');
dol_include_once('/ksef/lib/ksef.lib.php');
dol_include_once('/ksef/lib/ksef_tour.lib.php');
dol_include_once('/ksef/lib/ksef_import_ui.lib.php');
dol_include_once('/ksef/class/ksef_client.class.php');

$langs->loadLangs(array("ksef@ksef", "bills", "companies", "errors"));

$action = GETPOST('action', 'aZ09');
$massaction = GETPOST('massaction', 'alpha');
$confirm = GETPOST('confirm', 'alpha');
$toselect = GETPOST('toselect', 'array');
$contextpage = GETPOST('contextpage', 'aZ09') ? GETPOST('contextpage', 'aZ09') : 'kseflist';
$optioncss = GETPOST('optioncss', 'alpha');
$salesId = GETPOSTINT('sales_id');

$search_ref = GETPOST('search_ref', 'alpha');
$search_ksef_number = GETPOST('search_ksef_number', 'alpha');
$search_status = GETPOST('search_status', 'alpha');
$search_link = GETPOST('search_link', 'aZ09');
$search_environment = ksefGetEnvironment();
$search_date_start = dol_mktime(0, 0, 0, GETPOST('search_date_startmonth', 'int'), GETPOST('search_date_startday', 'int'), GETPOST('search_date_startyear', 'int'));
$search_date_end = dol_mktime(23, 59, 59, GETPOST('search_date_endmonth', 'int'), GETPOST('search_date_endday', 'int'), GETPOST('search_date_endyear', 'int'));

$limit = GETPOST('limit', 'int') ? GETPOST('limit', 'int') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOST('pageplusone') - 1) : GETPOST("page", 'int');
if (empty($page) || $page < 0 || GETPOST('button_search', 'alpha') || GETPOST('button_removefilter', 'alpha')) $page = 0;
$offset = $limit * $page;

$sortMap = array(
    'row_date' => 'u.row_date', 's.date_submission' => 'u.row_date',
    'invoice_ref' => 'u.invoice_ref', 'f.ref' => 'u.invoice_ref',
    'company_name' => 'u.company_name', 'soc.nom' => 'u.company_name',
    'ksef_number' => 'u.ksef_number', 's.ksef_number' => 'u.ksef_number',
    'send_status' => 'u.send_status', 's.status' => 'u.send_status',
    'match_status' => 'u.match_status',
    'environment' => 'u.environment', 's.environment' => 'u.environment',
    'amount' => 'u.amount', 'f.total_ttc' => 'u.amount',
);
if (!$sortfield || !isset($sortMap[$sortfield])) {
    $sortfield = 'row_date';
    $sortorder = $sortorder ?: 'DESC';
}
$sortfield = substr($sortMap[$sortfield], 2);
if (!$sortorder) $sortorder = 'DESC';

if (!$user->hasRight('ksef', 'read') || !$user->hasRight('facture', 'lire') || !empty($user->socid)) accessforbidden();

$socid = GETPOST('socid', 'int');

$permissiontosend = ksefUserCan($user, 'send');
$permissiontorefresh = ksefUserCan($user, 'refresh_submission');
$permissiontodelete = ksefUserCan($user, 'delete_submission');
$canWrite = (bool) $user->hasRight('ksef', 'write');
$canImport = ksefUserCan($user, 'import_sales');
$canLink = ksefUserCan($user, 'link_ksef_number');
$canAdjust = ksefUserCan($user, 'adjust_invoice');

$environment = ksefGetEnvironment();
$object = new KsefSubmission($db);
$ksef = new KSEFService($db);
$sales = new KsefSales($db);
$extrafields = new ExtraFields($db);

ksefSyncAjax($ksef, $user, $action, 'outgoing');

// keys hold customer
if (in_array($action, array('batch_import_preview', 'gus_supplier_preview', 'batch_import_execute'), true)) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache');
    if (!ksefUserCan($user, 'import_sales')) {
        echo json_encode(array('status' => 'ERROR', 'error' => 'Access denied'));
        exit;
    }
    require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';

    if ($action === 'batch_import_preview') {
        $ids = GETPOST('ids', 'array');
        session_write_close();
        $previews = array();
        $skipped = array();
        $recs = array();
        foreach ((array) $ids as $sid) {
            $r0 = new KsefSales($db);
            if ($r0->fetch((int) $sid) <= 0 || $r0->import_status === KsefSales::STATUS_FETCH_ERROR || !ksefUserCanReadSales($user, $r0)) {
                $skipped[] = array('id' => (int) $sid, 'invoice_number' => $r0->id ? ($r0->invoice_number ?: $r0->ksef_number) : '', 'reason' => $langs->transnoentitiesnoconv('KSEF_FetchErrorNotImportable'));
                continue;
            }
            $code = '';
            $refusal = $r0->importRefusal($code);
            $recs[] = array('rec' => $r0, 'refusal' => $refusal, 'code' => $code);
        }
        do {
            $changed = false;
            foreach ($recs as $i => $r) {
                if ($r['code'] !== 'source_missing') {
                    continue;
                }
                $c = $r['rec'];
                $exempt = false;
                foreach ($c->citedInvoices() as $ci) {
                    $hits = 0;
                    foreach ($recs as $j => $src) {
                        if ($j === $i || $src['refusal'] !== '') {
                            continue;
                        }
                        $s0 = $src['rec'];
                        $byNumber = $ci['ksef'] !== '' && $s0->ksef_number === $ci['ksef'];
                        $byRef = $ci['number'] !== '' && (string) $s0->invoice_number === $ci['number']
                            && ($ci['date'] === '' || date('Y-m-d', (int) $s0->invoice_date) === date('Y-m-d', is_numeric($ci['date']) ? (int) $ci['date'] : (int) strtotime($ci['date'])));
                        if ($byNumber || $byRef) {
                            $hits++;
                        }
                    }
                    $exempt = $exempt || $hits === 1;
                }
                if ($exempt) {
                    $recs[$i]['refusal'] = '';
                    $recs[$i]['code'] = '';
                    $changed = true;
                }
            }
        } while ($changed);
        foreach ($recs as $r) {
            $rec = $r['rec'];
            $refusal = $r['refusal'];
            $party = $rec->partyInfo();
            $preview = array(
                'id' => $rec->id,
                'invoice_number' => $rec->invoice_number,
                'seller_name' => $party['name'],
                'seller_nip' => $party['nip'],
                'seller_vat_id' => $party['vat_id'],
                'invoice_date' => dol_print_date($rec->invoice_date, 'day'),
                'total_gross' => price($rec->total_gross, 0, $langs, 1, -1, -1, $rec->currency),
                'currency' => $rec->currency,
                'invoice_type' => $rec->invoice_type,
                'correction_blocked' => ($refusal !== ''),
                'correction_issues' => ($refusal !== '' ? array($refusal) : array()),
                'block_title' => ($refusal !== '' ? $refusal : ''),
            );
            $socidMatch = $rec->findMatchingThirdParty();
            if ($socidMatch > 0) {
                $soc = new Societe($db);
                $soc->fetch($socidMatch);
                $preview['socid'] = $socidMatch;
                $preview['supplier_name'] = $soc->name;
                $preview['supplier_matched'] = true;
            } else {
                $preview['socid'] = 0;
                $preview['supplier_name'] = $party['name'];
                $preview['supplier_matched'] = false;
            }
            $lines = $rec->previewImportLines();
            $auto = $rec->autoMatchLineProducts($lines);
            $preview['total_lines'] = count($lines);
            $preview['matched_lines'] = count($auto);
            $preview['all_products_matched'] = (count($auto) == count($lines));
            $preview['lines'] = ksefBatchPreviewLines($lines, $auto);
            $previews[] = $preview;
        }
        echo json_encode(array('status' => 'OK', 'invoices' => $previews, 'skipped' => $skipped));
        exit;
    }

    if ($action === 'gus_supplier_preview') {
        $payload = json_decode((string) file_get_contents('php://input'), true);
        $ids = (is_array($payload) && !empty($payload['ids']) && is_array($payload['ids'])) ? $payload['ids'] : array();
        if (empty($ids) || !getDolGlobalString('KSEF_GUS_ENABLED')) {
            echo json_encode(array('status' => 'OK', 'overrides' => array()));
            exit;
        }
        $parties = array();
        foreach ($ids as $sid) {
            $rec = new KsefSales($db);
            if ($rec->fetch((int) $sid) > 0 && ksefUserCanReadSales($user, $rec)) {
                $parties[] = $rec->partyInfo();
            }
        }
        echo json_encode(ksefBatchGusPreview($db, $parties, 'KSEF_GUS_CUSTOMER_DATA'));
        exit;
    }

    $payload = json_decode((string) file_get_contents('php://input'), true);
    $decisions = (is_array($payload) && !empty($payload['decisions']) && is_array($payload['decisions'])) ? $payload['decisions'] : array();
    if (empty($decisions)) {
        echo json_encode(array('status' => 'ERROR', 'error' => 'Invalid request data'));
        exit;
    }
    $gusData = (!empty($payload['fetch_suppliers']) && getDolGlobalString('KSEF_GUS_ENABLED')) ? ksefSessionRead('KSEF_GUS_CUSTOMER_DATA', array(), false) : array();
    if (!is_array($gusData)) {
        $gusData = array();
    }
    $o = (is_array($payload) && !empty($payload['options']) && is_array($payload['options'])) ? $payload['options'] : array();
    session_write_close();
    $rows = array();
    foreach ($decisions as $d) {
        $rec = new KsefSales($db);
        if ((int) ($d['id'] ?? 0) > 0 && $rec->fetch((int) $d['id']) > 0 && ksefUserCanReadSales($user, $rec)) {
            $rows[] = array('rec' => $rec, 'd' => $d);
        }
    }
    usort($rows, function ($a, $b) {
        $ka = (KsefIncoming::isCorrectionType($a['rec']->invoice_type) ? 1 : 0);
        $kb = (KsefIncoming::isCorrectionType($b['rec']->invoice_type) ? 1 : 0);
        return $ka !== $kb ? $ka - $kb : ((int) $a['rec']->invoice_date - (int) $b['rec']->invoice_date ?: (int) $a['rec']->id - (int) $b['rec']->id);
    });
    $prevErrorHandler = set_error_handler(function ($errno, $errstr, $errfile, $errline) use (&$prevErrorHandler) {
        if ($errno === E_USER_ERROR) {
            throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
        }
        return $prevErrorHandler ? call_user_func($prevErrorHandler, $errno, $errstr, $errfile, $errline) : false;
    });
    $results = array();
    $created = array();
    foreach ($rows as $row) {
        $rec = $row['rec'];
        $d = $row['d'];
        $level = (int) $db->transaction_opened;
        try {
            $nip = ksefCleanNIP($rec->partyInfo()['nip']);
            $fid = $rec->prepareAndImport($user, array(
                'socid' => (int) ($d['socid'] ?? 0),
                'create_customer' => !empty($d['auto_create_supplier']),
                // page checkbox
                'create_parties' => !empty($payload['auto_create_suppliers']),
                'gus' => !empty($payload['fetch_suppliers']),
                'gus_data' => ($nip !== '' && !empty($gusData[$nip])) ? $gusData[$nip] : null,
                // preview queried GUS
                'gus_live' => false,
                'create_products' => !empty($d['auto_create_products']),
                'product_type' => (int) ($d['product_type'] ?? ($payload['product_type'] ?? 0)),
                'warehouse_id' => (int) ($o['warehouse_id'] ?? 0),
                'record_payment' => !empty($o['record_payment']),
                'bank_account_id' => (int) ($o['bank_account_id'] ?? 0),
            ), $created);
        } catch (Throwable $e) {
            while ((int) $db->transaction_opened > $level) {
                $db->rollback();
            }
            dol_syslog('status.php batch_import_execute: ' . $rec->ksef_number . ': ' . get_class($e) . ' ' . $e->getMessage(), LOG_ERR);
            $fid = -1;
            $rec->error = $langs->transnoentitiesnoconv('KSEF_BatchUnexpectedError', $e->getMessage());
        }
        if ($fid > 0) {
            $results[] = array('id' => $rec->id, 'status' => 'OK', 'facture_id' => $fid, 'invoice_number' => $rec->invoice_number, 'warnings' => (array) $rec->warnings);
        } else {
            $results[] = array('id' => $rec->id, 'status' => 'ERROR', 'error' => $rec->error, 'invoice_number' => $rec->invoice_number ?: $rec->ksef_number);
        }
    }
    restore_error_handler();
    $ok = 0;
    $bad = 0;
    foreach ($results as $r) {
        if ($r['status'] === 'OK') {
            $ok++;
        } else {
            $bad++;
        }
    }
    echo json_encode(array('status' => 'OK', 'results' => $results, 'success_count' => $ok, 'error_count' => $bad));
    exit;
}

/**
 * @brief Split selected rows
 * @param array $toselect
 * @return array
 */
function ksefStatusSplitSelection($toselect)
{
    $out = array('sub' => array(), 'sales' => array());
    foreach ((array) $toselect as $v) {
        $v = (string) $v;
        if (preg_match('/^k(\d+)$/', $v, $m)) {
            $out['sales'][] = (int) $m[1];
        } elseif (preg_match('/^\d+$/', $v)) {
            $out['sub'][] = (int) $v;
        }
    }
    return $out;
}
$selection = ksefStatusSplitSelection($toselect);

$parameters = array('socid' => $socid);
$reshook = $hookmanager->executeHooks('doActions', $parameters, $object, $action);
if ($reshook < 0) setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');

if (empty($reshook)) {
    include DOL_DOCUMENT_ROOT . '/core/actions_changeselectedfields.inc.php';

    if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
        $search_ref = '';
        $search_ksef_number = '';
        $search_status = '';
        $search_link = '';
        $search_environment = ksefGetEnvironment();
        $search_date_start = '';
        $search_date_end = '';
        $toselect = array();
        $selection = array('sub' => array(), 'sales' => array());
    }

    if (!empty($massaction) && !empty($toselect)) {
        if ($massaction == 'retry_fetch' && $user->hasRight('ksef', 'write')) {
            $rr = $sales->reprocessMany($user, $selection['sales']);
            setEventMessages($langs->trans('KSEF_RetryFetchMassResult', $rr['done'], $rr['failed'], $rr['skipped'] + count($selection['sub'])), null, $rr['failed'] ? 'warnings' : 'mesgs');
        }

        if ($massaction == 'retry') {
            if (!$permissiontosend) accessforbidden();
            $counts = array('accepted' => 0, 'processing' => 0, 'skipped' => 0, 'error' => 0);
            $errorLines = array();
            $skipLines = array();
            foreach ($selection['sub'] as $toselectid) {
                $submission = ksefFetchSubmissionForUser($db, $user, $toselectid);
                if (!$submission) {
                    $counts['error']++;
                    continue;
                }
                if (!$submission->canRetry()) {
                    $counts['skipped']++;
                    continue;
                }
                $result = $ksef->retrySubmission($submission->fk_facture, $user);
                $m = ksefRetryResultMessage($result, $ksef, $submission->fk_facture);
                $counts[$m[2]]++;
                if ($m[2] === 'skipped' && $m[0] !== '' && count($skipLines) < 10) {
                    $fRef = new Facture($db);
                    $skipLines[] = ($fRef->fetch((int) $submission->fk_facture) > 0 ? dol_escape_htmltag($fRef->ref) : '#' . (int) $submission->fk_facture) . ': ' . $m[0];
                }
                if ($m[2] === 'error' && count($errorLines) < 10) {
                    $fRef = new Facture($db);
                    $errorLines[] = ($fRef->fetch((int) $submission->fk_facture) > 0 ? dol_escape_htmltag($fRef->ref) : '#' . (int) $submission->fk_facture) . ': ' . $m[0];
                }
            }
            if ($counts['accepted'] > 0) setEventMessages($langs->trans("KSEF_MassRetryAccepted", $counts['accepted']), null, 'mesgs');
            if ($counts['processing'] > 0) setEventMessages($langs->trans("KSEF_MassRetryProcessing", $counts['processing']), null, 'warnings');
            if ($counts['skipped'] > 0) setEventMessages($langs->trans("KSEF_MassRetrySkipped", $counts['skipped']), $skipLines, 'warnings');
            if ($counts['error'] > 0) setEventMessages($langs->trans("KSEF_NbRecordsWithErrors", $counts['error']), $errorLines, 'errors');
        }
    }
    // core posts delete
    if ((($massaction == 'delete' && GETPOST('confirmmassaction', 'alpha')) || ($action == 'delete' && $confirm == 'yes')) && !empty($toselect)) {
        if (!$permissiontodelete) accessforbidden();
        $num_deleted = 0;
        $num_errors = 0;
        $num_skipped = 0;
        foreach ($selection['sub'] as $toselectid) {
            $delSub = ksefFetchSubmissionForUser($db, $user, $toselectid);
            if (!$delSub) {
                $num_errors++;
                continue;
            }
            if (ksefSubmissionRowLock($delSub, $user)['result'] != 'allowed') {
                $num_skipped++;
                continue;
            }
            if ($delSub->delete($user) > 0) {
                $num_deleted++;
            } else {
                $num_errors++;
                dol_syslog("Failed to delete submission ID $toselectid: " . $delSub->error, LOG_ERR);
            }
        }
        if ($num_deleted > 0) setEventMessages($langs->trans("KSEF_RecordsDeleted", $num_deleted), null, 'mesgs');
        if ($num_skipped > 0) setEventMessages($langs->trans("KSEF_DeleteSkipped", $num_skipped), null, 'warnings');
        if ($num_errors > 0) setEventMessages($langs->trans("KSEF_NbRecordsWithErrors", $num_errors), null, 'errors');
        header("Location: " . $_SERVER["PHP_SELF"] . '?restore_lastsearch_values=1');
        exit;
    }

    if ($action == 'retry' && GETPOST('id', 'int')) {
        if (!$permissiontosend) accessforbidden();
        $submission = ksefFetchSubmissionForUser($db, $user, GETPOST('id', 'int'));
        if (!$submission) {
            setEventMessages($langs->trans("ErrorRecordNotFound"), null, 'errors');
        } else {
            if (!$submission->canRetry()) {
                setEventMessages(ksefEscapeText(dol_html_entity_decode((string) $submission->getNoRetryReason(), ENT_QUOTES)), null, 'warnings');
            } else {
                $result = $ksef->retrySubmission($submission->fk_facture, $user);
                $notSaved = is_array($result) && isset($result['record_saved']) && $result['record_saved'] === false;
                if (!ksefWarnIfNotRecorded($result) && !$notSaved && empty($result['adopted'])) {
                    $m = ksefRetryResultMessage($result, $ksef, $submission->fk_facture);
                    setEventMessages($m[0], null, $m[1]);
                }
            }
        }
        header("Location: " . $_SERVER["PHP_SELF"]);
        exit;
    }

    if ($action == 'confirm_delete' && GETPOST('confirm', 'alpha') == 'yes' && GETPOST('id', 'int')) {
        if (!$permissiontodelete) accessforbidden();
        $id = GETPOST('id', 'int');
        if (!ksefFetchSubmissionForUser($db, $user, $id)) {
            setEventMessages($langs->trans("ErrorRecordNotFound"), null, 'errors');
            header("Location: " . $_SERVER["PHP_SELF"]);
            exit;
        }
        $delSub = ksefFetchSubmissionForUser($db, $user, $id);
        $rowLock = ksefSubmissionRowLock($delSub, $user);
        if ($rowLock['result'] == 'blocked') {
            setEventMessages($langs->trans($rowLock['key']), null, 'errors');
        } elseif ($delSub->delete($user) > 0) {
            setEventMessages($langs->trans("RecordDeleted"), null, 'mesgs');
        } else {
            setEventMessages(ksefEscapeText(ksefDbErrorForUser($user, 'KSEF_DbErrorGeneric', $delSub->error)), null, 'errors');
        }
        header("Location: " . $_SERVER["PHP_SELF"]);
        exit;
    }

    $viewErrorSubmission = null;
    if ($action == 'view_error' && GETPOST('id', 'int')) {
        $viewErrorSubmission = ksefFetchSubmissionForUser($db, $user, GETPOST('id', 'int'));
    }

    if ($action == 'check_status' && GETPOST('id', 'int')) {
        if (!$permissiontorefresh) accessforbidden();
        $submission = ksefFetchSubmissionForUser($db, $user, GETPOST('id', 'int'));
        if ($submission && !in_array($submission->status, array('PENDING', 'SUBMITTED', 'TIMEOUT'), true)) {
            setEventMessages($langs->trans("KSEF_StatusUpdated"), null, 'mesgs');
        } elseif ($submission) {
            $result = $ksef->checkStatusManual($submission, $user);
            if ($result && $result['status'] === 'ACCEPTED') {
                setEventMessages(!empty($result['adopted']) ? $langs->trans('KSEF_AdoptedDuplicate', ksefEscapeText($result['ksef_number']))
                    : $langs->trans("KSEF_StatusNowAccepted", ksefEscapeText($result['ksef_number'])), null, 'mesgs');
            } elseif ($result && $result['status'] === 'FAILED') {
                setEventMessages($langs->trans("KSEF_StatusNowRejected", ksefEscapeText(ksefKsefCodeLabel($result['code']))), null, 'warnings');
            } elseif ($result) {
                setEventMessages($langs->trans("KSEF_StatusStillProcessing"), null, 'mesgs');
            } elseif ($ksef->failureReason === 'noref') {
                setEventMessages($langs->trans("KSEF_StatusNoReference"), null, 'warnings');
            } elseif ($ksef->failureReason === 'notfound') {
                setEventMessages($ksef->searchedByHash ? ksefEscapeText($ksef->error) : $langs->trans("KSEF_StatusNotReceived"), null, 'warnings');
            } elseif (in_array($ksef->failureReason, array('ambiguous', 'right', 'wait'), true)) {
                setEventMessages(ksefEscapeText($ksef->error), null, 'warnings');
            } else {
                setEventMessages(ksefEscapeText($ksef->error), null, 'errors');
            }
        }
        header("Location: " . $_SERVER["PHP_SELF"]);
        exit;
    }

    if ($action == 'download_xml' && GETPOST('id', 'int')) {
        $submission = ksefFetchSubmissionForUser($db, $user, GETPOST('id', 'int'));
        if ($submission && !empty($submission->fa3_xml)) {
            ksefSendDownload($submission->fa3_xml, ksefDownloadName('FA3', $submission->ksef_number, 'invoice_' . $submission->fk_facture, 'xml'), 'application/xml');
            exit;
        }
        setEventMessages($langs->trans('KSEF_XMLNotAvailable'), null, 'warnings');
    }

    if ($action == 'download_upo' && GETPOST('id', 'int')) {
        $submission = ksefFetchSubmissionForUser($db, $user, GETPOST('id', 'int'));
        $upoError = '';
        if ($submission) {
            $ksef = new KsefService($db);
            $upo = $ksef->upoForDownload($submission, $user);
            if ($upo !== '') {
                ksefSendDownload($upo, ksefDownloadName('UPO', $submission->ksef_number, 'invoice_' . $submission->fk_facture, 'xml'), 'application/xml');
                exit;
            }
            $upoError = $ksef->error;
        }
        setEventMessages($langs->trans("KSEF_UPONotAvailable") . ($upoError !== '' ? ': ' . ksefEscapeText($upoError) : ''), null, 'warnings');
    }

    if (in_array($action, array('download_sales_xml', 'download_sales_pdf'), true) && $salesId > 0) {
        if ($sales->fetch($salesId) > 0 && !empty($sales->fa3_xml) && ksefUserCanReadSales($user, $sales)) {
            if ($action === 'download_sales_xml') {
                ksefSendDownload($sales->fa3_xml, ksefDownloadName('FA3', $sales->ksef_number, 'sales_' . $sales->id, 'xml'), 'application/xml');
                exit;
            }
            $pdf = $sales->generatePdfVisualization();
            if ($pdf) {
                ksefSendDownload($pdf, ksefDownloadName('FA3', $sales->ksef_number, 'sales_' . $sales->id, 'pdf'), 'application/pdf');
                exit;
            }
        }
        setEventMessages($langs->trans($action === 'download_sales_xml' ? 'KSEF_XMLNotAvailable' : 'KSEF_PdfGenerationFailed'), null, 'warnings');
        $action = '';
    }

    if ($action === 'sync' && $canWrite) {
        if (ksefSyncStart($ksef, $user)) {
            header('Location: ' . $_SERVER["PHP_SELF"] . '?polling=1');
            exit;
        }
    }
    if ($action === 'reset_fetch' && $canWrite) {
        if (ksefSyncReset($ksef, $user, 'outgoing')) {
            setEventMessages($langs->trans('KSEF_FetchReset'), null, 'mesgs');
        } else {
            setEventMessages($ksef->error, null, 'errors');
        }
        header('Location: ' . $_SERVER["PHP_SELF"]);
        exit;
    }
    if ($action === 'confirm_reset' && $confirm === 'yes' && $user->admin) {
        if ($ksef->resetIncomingSyncState($user, 'outgoing')) {
            setEventMessages($langs->trans('KSEF_SyncStateReset'), null, 'mesgs');
        } else {
            setEventMessages($langs->trans('Error'), null, 'errors');
        }
    }
    if ($action === 'confirm_clearall' && $confirm === 'yes' && $user->admin) {
        $deleted = $sales->deleteAll($user, $environment);
        if ($deleted >= 0) {
            setEventMessages($langs->trans('KSEF_ClearedSalesRecords', $deleted, $environment), null, 'mesgs');
        } else {
            setEventMessages($sales->error, null, 'errors');
        }
    }

    $gapFacture = GETPOSTINT('gap_facture');
    if (in_array($action, array('confirm_gap_skip', 'gap_unskip'), true) && $gapFacture > 0) {
        if (!$user->hasRight('ksef', 'write') || !ksefUserCanReadInvoice($user, $gapFacture)) accessforbidden();
        if ($action === 'gap_unskip' && GETPOST('token', 'alpha') === '') accessforbidden();
        if ($action === 'gap_unskip' || $confirm === 'yes') {
            if ($sales->setSendSkipped($gapFacture, $action === 'confirm_gap_skip') > 0) {
                setEventMessages($langs->trans($action === 'gap_unskip' ? 'KSEF_GapReopened' : 'KSEF_GapSkipped'), null, 'mesgs');
            } else {
                setEventMessages($sales->error, null, 'errors');
            }
        }
        $action = '';
    }

    if ($action === 'confirm_link_sales' && $confirm === 'yes' && $salesId > 0) {
        if (!$canLink) accessforbidden();
        if ($sales->fetch($salesId) > 0 && (int) $sales->link_candidate > 0 && ksefUserCanReadInvoice($user, (int) $sales->link_candidate)) {
            $r = $ksef->linkKsefNumber((int) $sales->link_candidate, $sales->ksef_number, $user);
            if (($r['status'] ?? '') === 'ACCEPTED') {
                setEventMessages($langs->trans('KSEF_LinkKsefNumberDone', ksefEscapeText($sales->ksef_number)), null, 'mesgs');
                if (empty($r['has_upo'])) {
                    setEventMessages($langs->trans('KSEF_UPOUnavailableNoSession'), null, 'warnings');
                }
            } else {
                setEventMessages(ksefEscapeText($r['error'] ?? $ksef->error), null, 'errors');
            }
        }
        $action = '';
    }
}

$form = new Form($db);
$formother = new FormOther($db);

$title = $langs->trans("KSEF_OutgoingInvoices");
$help_url = ksefHelpUrl('sending');
llxHeader('', $title, $help_url, '', 0, 0, '', '', '', 'mod-ksef page-status');

print load_fiche_titre($title, ksefHelpTitleButton($user, 'status', array('sending' => 'KSEF_SendingInvoices', 'corrections' => 'KSEF_HowTo_Corrections'), array('send_sales', 'send_offline')), 'ksef@ksef');
print ksefShowDolibarrVersionWarning() . ksefShowReactivationWarning();

if (!empty($viewErrorSubmission)) {
    $errSub = $viewErrorSubmission;
    $errInvoice = new Facture($db);
    $errRef = $errInvoice->fetch($errSub->fk_facture) > 0 ? $errInvoice->ref : (string) $errSub->fk_facture;
    $errCode = trim((string) $errSub->error_code);
    print '<div id="ksef-error-dialog" class="ksef-errbox error-modal" title="' . dol_escape_htmltag($langs->trans('KSEF_ErrorDetails') . ': ' . $errRef) . '">';
    if ($errCode !== '') {
        print '<p class="ksef-errhead"><span class="fa fa-exclamation-triangle paddingright"></span>' . ksefEscapeText($errCode . ': ' . ksefKsefCodeLabel($errCode)) . '</p>';
    }
    if (trim((string) $errSub->error_message) !== '') {
        print '<div class="ksef-errlabel">' . $langs->trans('KSEF_KsefMessage') . '</div>';
        print '<p class="ksef-errmsg">' . ksefEscapeText($errSub->error_message, true) . '</p>';
    }
    $errorDetails = $errSub->getErrorDetailsArray();
    if ($errorDetails && !empty($errorDetails['details'])) {
        $lines = array();
        foreach ((array) $errorDetails['details'] as $detail) {
            $lines[] = is_scalar($detail) ? (string) $detail : json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        print '<div class="ksef-errlabel">' . $langs->trans('KSEF_TechnicalDetails') . '</div>';
        print '<pre class="ksef-errdetails">' . ksefEscapeText(implode("\n", $lines)) . '</pre>';
    }
    if ($errCode === '' && trim((string) $errSub->error_message) === '') {
        print '<p class="ksef-errmsg opacitymedium">' . $langs->trans('KSEF_NoErrorDetails') . '</p>';
    }
    $errHint = $errCode !== '' ? ksefRetryHintText($errCode) : '';
    if ($errHint !== '') {
        print '<div class="ksef-errhint"><strong>' . $langs->trans('KSEF_NextStep') . ':</strong> ' . ksefEscapeText($errHint) . '</div>';
    }
    print '<div class="ksef-errmeta opacitymedium small" style="margin-top: 12px;">' . $langs->trans('KSEF_SubmissionDate') . ': ' . dol_print_date((int) $errSub->date_submission, 'dayhour') . '</div>';
    print '<noscript><a class="button" href="' . $_SERVER["PHP_SELF"] . '">' . $langs->trans('Close') . '</a></noscript>';
    print '</div>';
    print '<script>
    $(document).ready(function() {
        $("#ksef-error-dialog").dialog({
            modal: true,
            width: Math.min(640, $(window).width() - 40),
            resizable: false,
            buttons: [{ text: ' . json_encode($langs->transnoentitiesnoconv('Close')) . ', click: function() { $(this).dialog("close"); } }],
            close: function() { if (window.history && history.replaceState) { history.replaceState(null, "", ' . json_encode($_SERVER["PHP_SELF"]) . '); } }
        });
    });
    </script>';
}

if ($action == 'delete' && GETPOST('id', 'int') && $permissiontodelete) {
    $delSub = ksefFetchSubmissionForUser($db, $user, GETPOST('id', 'int'));
    if ($delSub) {
        $delInvoice = new Facture($db);
        $delRef = $delInvoice->fetch($delSub->fk_facture) > 0 ? $delInvoice->ref : (string) $delSub->fk_facture;
        $rowLock = ksefSubmissionRowLock($delSub, $user);
        if ($rowLock['result'] == 'blocked') {
            setEventMessages($langs->trans($rowLock['key']), null, 'errors');
        } else {
            $question = $langs->trans('KSEF_ConfirmDeleteSubmission', dol_escape_htmltag($delRef));
            if ($rowLock['result'] == 'warn') {
                $rows = $rowLock['reason'] == 'issued' ? array_values(array_filter(ksefIssuedRows($db, $delSub->fk_facture), function ($r) use ($delSub) {
                    return $r['id'] == (int) $delSub->rowid;
                })) : array();
                $question = '<div class="warning"><strong>' . $langs->trans($rowLock['reason'] == 'issued' ? 'KSEF_DeleteWarnTitle' : 'Warning') . '</strong><br>' . $langs->trans($rowLock['key'])
                    . ($rows ? ksefIssuedDownloadLinks($delSub->fk_facture, $rows) : '') . '</div>' . $question;
            }
            $isWarn = ($rowLock['result'] == 'warn');
            print $form->formconfirm($_SERVER["PHP_SELF"] . '?id=' . (int) $delSub->rowid, $langs->trans('Delete'), $question, 'confirm_delete', '', 0, 1, $isWarn ? 'auto' : 260, $isWarn ? 700 : 600);
        }
    }
}

if ($action === 'link_sales' && $salesId > 0 && $canLink && $sales->fetch($salesId) > 0 && (int) $sales->link_candidate > 0
    && ksefUserCanReadInvoice($user, (int) $sales->link_candidate)) {
    $cand = new Facture($db);
    $cand->fetch((int) $sales->link_candidate);
    print $form->formconfirm($_SERVER['PHP_SELF'] . '?sales_id=' . $salesId, $langs->trans('KSEF_LinkToInvoice'),
        $langs->trans('KSEF_ConfirmLinkSales', dol_escape_htmltag($sales->ksef_number), dol_escape_htmltag($cand->ref)), 'confirm_link_sales', '', 0, 1);
}

if ($action === 'gap_skip' && GETPOSTINT('gap_facture') > 0 && $user->hasRight('ksef', 'write')) {
    $gapInv = new Facture($db);
    if ($gapInv->fetch(GETPOSTINT('gap_facture')) > 0) {
        print $form->formconfirm($_SERVER['PHP_SELF'] . '?gap_facture=' . (int) $gapInv->id, $langs->trans('KSEF_GapSkip'),
            $langs->trans('KSEF_ConfirmGapSkip', dol_escape_htmltag($gapInv->ref)), 'confirm_gap_skip', '', 0, 1);
    }
}

if ($action === 'clearall' && $user->admin) {
    print $form->formconfirm($_SERVER["PHP_SELF"], $langs->trans('KSEF_ClearAll'), $langs->trans('KSEF_ConfirmClearAllSales', ksefEnvironmentLabel($environment)), 'confirm_clearall', '', 0, 1);
}
if ($action === 'reset' && $user->admin) {
    print $form->formconfirm($_SERVER["PHP_SELF"], $langs->trans('KSEF_ResetSyncState'), $langs->trans('KSEF_ConfirmResetSync'), 'confirm_reset', '', 0, 1);
}

$syncState = $ksef->getIncomingSyncState('outgoing');
ksefPrintSyncAuditNotice('outgoing');
$syncStates = ksefSyncStates($ksef, $user);
$syncStates['outgoing'] = $syncState;
ksefPrintFetchOverlay($syncStates);
$until = KsefSales::syncedUntil();
print ksefLatarniaUpcomingBanner();

print '<div class="fichecenter">';
print '<div class="fichethirdleft">';
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder nohover centpercent ksef-outgoing-sync">';
print '<tr class="liste_titre"><th colspan="2">' . $langs->trans("KSEF_SyncFromKSeF") . '</th></tr>';
$latarnia_cached = KsefLatarnia::getCachedStatus();
print '<tr class="oddeven"><td class="titlefield">' . $langs->trans("KSEF_SystemStatus") . '</td>';
print '<td>' . ksefGetLatarniaStatusBadge($latarnia_cached['status']);
$latarnia_msg = KsefLatarnia::currentMessage($latarnia_cached);
if ($latarnia_msg !== null) {
    print '<br><span class="small">' . dol_escape_htmltag($latarnia_msg['title'] ?? '') . '</span>';
}
print '</td></tr>';
print '<tr class="oddeven"><td>' . $langs->trans("KSEF_ENVIRONMENT") . '</td><td>' . ksefGetEnvironmentBadge($environment) . '</td></tr>';
print '<tr class="oddeven"><td>' . $langs->trans("KSEF_SyncContinuationPoint") . '</td>';
print '<td>' . ($syncState->continuation_date ? dol_print_date($syncState->continuation_date, 'dayhour') : '<span class="opacitymedium">' . $langs->trans("NotSet") . '</span>') . '</td></tr>';
print '<tr class="oddeven"><td>' . $langs->trans("KSEF_LastSync") . '</td>';
print '<td>' . ($syncState->last_sync_date ? dol_print_date($syncState->last_sync_date, 'dayhour') : '<span class="opacitymedium">' . $langs->trans("Never") . '</span>') . '</td></tr>';
if ($syncState->last_sync_date) {
    print '<tr class="oddeven"><td>' . $langs->trans("KSEF_LastSyncResultSales") . '</td>';
    print '<td>' . ksefSyncResultText($syncState) . '</td></tr>';
}
if ($syncState->isRateLimited()) {
    print '<tr class="oddeven"><td colspan="2"><span class="warning"><span class="fa fa-clock"></span> ' . $langs->trans("KSEF_RateLimitActive", $syncState->getRateLimitExpiryFormatted()) . '</span></td></tr>';
}
dol_include_once('/ksef/class/ksef_session_cache.class.php');
$upoCache = new KsefSessionCache($db);
$upoDot = $upoCache->coverageDot($environment);
if ($upoDot && $upoDot['warning'] !== '') {
    print '<tr class="oddeven ksef-upo-warning"><td colspan="2"><span class="warning"><span class="fa fa-exclamation-triangle"></span> ' . dol_escape_htmltag($upoDot['warning']) . '</span></td></tr>';
}
print '</table>';
print '</div>';
print '<div class="center" style="margin-top: 15px;">';
ksefPrintSyncButton($syncStates, $_SERVER["PHP_SELF"]);
print '</div>';
print '</div>';

$P = MAIN_DB_PREFIX;
$sendCounts = ksefOutgoingStatCounts($db, $user, $search_environment, $socid);
$matchCounts = $sales->getReconcileCounts($environment);

print '<div class="fichetwothirdright">';
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder nohover centpercent ksef-outgoing-stats">';
print '<tr class="liste_titre"><th colspan="2"><span class="fa fa-file-upload paddingright opacitymedium"></span>' . $langs->trans("KSEF_OutgoingStatistics") . '</th></tr>';
print ksefStatRowsHtml('outgoing', $sendCounts);
print '<tr class="oddeven ksef-match-counts"><td>' . $langs->trans("KSEF_MatchStatus") . '</td><td class="right">';
print '<a class="marginrightonly" href="' . $_SERVER["PHP_SELF"] . '?search_link=ACTION">'
    . '<span class="badge badge-status ' . ksefToneBadgeClass('action') . ' classfortooltip" title="' . dol_escape_htmltag($langs->transnoentitiesnoconv('KSEF_SalesActionNeeded')) . '"><span class="fa fa-exclamation-circle paddingright"></span>' . (int) ($matchCounts['gaps'] ?? 0) . '</span></a>';
foreach (array(KsefSales::LINK_OK, KsefSales::LINK_DIFFERS, KsefSales::LINK_LINKABLE, KsefSales::LINK_SUGGEST, KsefSales::LINK_IMPORTABLE, KsefSales::LINK_CONFLICT,
    KsefSales::GAP_MISSING, KsefSales::GAP_NOT_SENT, KsefSales::GAP_OPTIONAL, KsefSales::LINK_UNSUPPORTED, KsefSales::LINK_SKIPPED, KsefIncoming::STATUS_FETCH_ERROR) as $k) {
    if (empty($matchCounts[$k])) {
        continue;
    }
    print '<a class="marginleftonly" href="' . $_SERVER["PHP_SELF"] . '?search_link=' . urlencode($k) . '">'
        . KsefSales::linkBadge($k, $langs->transnoentitiesnoconv('KSEF_SalesLinkHelp_' . $k), (int) $matchCounts[$k]) . '</a>';
}
print '</td></tr>';
ksefPrintSyncCoverageRow($syncState, (bool) $user->admin, $upoDot);
print '</table>';
print '</div>';
if ($user->admin) {
    ksefPrintAdminOptionsDialog($environment, 'KSEF_AdminClearAllSalesDesc');
}
print '</div>';
print '</div>';
print '<div class="clearboth"></div>';
if (!$until && empty($matchCounts[KsefSales::LINK_OK]) && empty($matchCounts['gaps'])) {
    print '<div class="info">' . $langs->trans('KSEF_ReconcileNotSynced') . '</div>';
}
print '<br>';

$envEsc = $db->escape($environment);
$kWhere = "k2.environment = s.environment AND k2.entity IN (" . getEntity('ksef_sales') . ")";
// no OR: full-scans
$kPick = "COALESCE(NULLIF(GREATEST(COALESCE((SELECT MAX(k2.rowid) FROM " . $P . "ksef_sales k2 WHERE " . $kWhere . " AND k2.fk_facture = s.fk_facture), 0),"
    . " COALESCE((SELECT MAX(k2.rowid) FROM " . $P . "ksef_sales k2 WHERE " . $kWhere . " AND s.ksef_number IS NOT NULL AND s.ksef_number <> '' AND k2.ksef_number = s.ksef_number), 0)), 0),"
    . " (SELECT MAX(k2.rowid) FROM " . $P . "ksef_sales k2 WHERE " . $kWhere . " AND k2.link_candidate = s.fk_facture AND k2.link_status IN ('LINKABLE', 'SUGGEST')))";
$latestSub = ksefLatestSubmissionSql('s');

$isPg = ($db->type === 'pgsql');
$cs = function ($expr) use ($isPg) {
    return $isPg ? "CAST(" . $expr . " AS VARCHAR)" : "CONVERT(" . $expr . " USING utf8mb4) COLLATE utf8mb4_unicode_ci";
};
$unixTs = function ($expr) use ($isPg) {
    return $isPg ? "CAST(EXTRACT(EPOCH FROM " . $expr . ") AS BIGINT)" : "UNIX_TIMESTAMP(" . $expr . ")";
};
$visF = ksefSqlInvoiceVisibility($user, 'f');
$sqlA = "SELECT " . $cs("'inv'") . " as row_kind, s.fk_facture as fk_facture, s.rowid as sub_id, COALESCE(k.rowid, 0) as sales_id, " . $cs("f.ref") . " as invoice_ref,"
    . " soc.rowid as socid, " . $cs("soc.nom") . " as company_name, " . $cs("s.ksef_number") . " as ksef_number, " . $cs("s.status") . " as send_status,"
    . " " . $cs("s.environment") . " as environment, s.date_submission as row_date, f.total_ttc as amount, " . $cs("COALESCE(f.multicurrency_code, '')") . " as currency,"
    . " f.multicurrency_total_ttc as mc_amount, " . $cs("COALESCE(s.offline_mode, '')") . " as offline_mode, s.date_last_check as last_check, s.offline_deadline as offline_deadline,"
    . " (SELECT COUNT(*) FROM " . $P . "ksef_submissions s3 WHERE s3.fk_facture = s.fk_facture AND s3.environment = s.environment AND (s3.source IS NULL OR s3.source <> '" . KsefSubmission::SOURCE_CARRIED_FORWARD . "')) as attempts, "
    . $cs("CASE WHEN k.rowid IS NOT NULL THEN (CASE WHEN k.import_status = 'FETCH_ERROR' THEN 'FETCH_ERROR' ELSE COALESCE(k.link_status, '') END)"
        . ($until ? " WHEN s.status = 'ACCEPTED' AND s.environment = '" . $envEsc . "' AND s.ksef_number IS NOT NULL AND s.ksef_number <> ''"
            . " AND (s.date_acceptance IS NULL OR s.date_acceptance <= " . (int) $until . ") THEN 'MISSING'" : '')
        . " ELSE '' END") . " as match_status"
    . " FROM " . $P . "ksef_submissions as s"
    . " INNER JOIN " . $P . "facture as f ON f.rowid = s.fk_facture"
    . " LEFT JOIN " . $P . "societe as soc ON soc.rowid = f.fk_soc"
    . " LEFT JOIN " . $P . "ksef_sales as k ON k.rowid = " . $kPick
    . " WHERE " . $latestSub . $visF
    . ($socid > 0 ? " AND soc.rowid = " . ((int) $socid) : '');
$parts = array($sqlA);
if (!($socid > 0)) {
    $target = "COALESCE(NULLIF(k.fk_facture, 0), CASE WHEN k.link_status IN ('LINKABLE', 'SUGGEST') THEN NULLIF(k.link_candidate, 0) END, 0)";
    $parts[] = "SELECT " . $cs("'ksef'") . " as row_kind, " . $target . " as fk_facture, 0 as sub_id, k.rowid as sales_id,"
        . " " . $cs("COALESCE(f.ref, k.invoice_number)") . " as invoice_ref, COALESCE(soc.rowid, 0) as socid, " . $cs("COALESCE(soc.nom, k.buyer_name)") . " as company_name,"
        . " " . $cs("k.ksef_number") . " as ksef_number, " . $cs("''") . " as send_status, " . $cs("k.environment") . " as environment,"
        . " k.invoice_date as row_date, k.total_gross as amount, " . $cs("COALESCE(k.currency, 'PLN')") . " as currency,"
        . " k.total_gross as mc_amount, " . $cs("''") . " as offline_mode, 0 as last_check, 0 as offline_deadline, 0 as attempts, "
        . $cs("CASE WHEN k.import_status = 'FETCH_ERROR' THEN 'FETCH_ERROR' WHEN k.import_status = 'SKIPPED' THEN 'SKIPPED' ELSE COALESCE(k.link_status, 'IMPORTABLE') END") . " as match_status"
        . " FROM " . $P . "ksef_sales as k"
        . " LEFT JOIN " . $P . "facture as f ON f.rowid = " . $target
        . " LEFT JOIN " . $P . "societe as soc ON soc.rowid = f.fk_soc"
        . " WHERE k.environment = '" . $envEsc . "' AND k.entity IN (" . getEntity('ksef_sales') . ")"
        . (!$user->hasRight('societe', 'client', 'voir') ? " AND f.rowid IS NOT NULL" . $visF : '')
        // no correlated subquery
        . " AND k.rowid NOT IN (SELECT p.sales_id FROM (SELECT " . $kPick . " AS sales_id FROM " . $P . "ksef_submissions s WHERE " . $latestSub . ") p WHERE p.sales_id IS NOT NULL)";
    $nsql = $until ? KsefSales::notSentSql($db, $environment, $until) : '';
    if ($nsql !== '') {
        $parts[] = "SELECT " . $cs("'gap'") . " as row_kind, g.rowid as fk_facture, 0 as sub_id, 0 as sales_id, " . $cs("g.ref") . " as invoice_ref, soc.rowid as socid,"
            . " " . $cs("soc.nom") . " as company_name, " . $cs("''") . " as ksef_number, " . $cs("''") . " as send_status, " . $cs("'" . $envEsc . "'") . " as environment,"
            . " " . $unixTs("g.datef") . " as row_date, f.total_ttc as amount, " . $cs("COALESCE(f.multicurrency_code, '')") . " as currency,"
            . " f.multicurrency_total_ttc as mc_amount, " . $cs("''") . " as offline_mode, 0 as last_check, 0 as offline_deadline, 0 as attempts, " . $cs("g.gap_kind") . " as match_status"
            . " FROM (" . $nsql . ") as g INNER JOIN " . $P . "facture as f ON f.rowid = g.rowid LEFT JOIN " . $P . "societe as soc ON soc.rowid = g.fk_soc"
            . " WHERE 1 = 1" . $visF;
    }
}
$union = "(" . implode(" UNION ALL ", $parts) . ") as u";

$where = " WHERE 1 = 1";
if ($search_ref) $where .= natural_search('u.invoice_ref', $search_ref);
if ($search_ksef_number) $where .= natural_search('u.ksef_number', $search_ksef_number);
$statusGroups = array('FAILED_ANY' => array('FAILED', 'REJECTED'), 'IN_PROGRESS' => array('PENDING', 'SUBMITTED', 'TIMEOUT'));
$staleTs = (int) (dol_now() - ksefSendStaleAfter());
$noOffline = "(u.offline_mode IS NULL OR u.offline_mode = '' OR u.offline_mode = '0')";
$staleSql = "((u.send_status IN ('PENDING', 'SUBMITTED') AND " . $noOffline . " AND u.row_date > 0 AND u.row_date < " . $staleTs . ")"
    . " OR (u.send_status = 'PENDING' AND NOT " . $noOffline . " AND GREATEST(COALESCE(u.row_date, 0), COALESCE(u.last_check, 0)) BETWEEN 1 AND " . ($staleTs - 1) . "))";
if ($search_status && $search_status != '-1') {
    if (isset($statusGroups[$search_status])) {
        $where .= " AND u.send_status IN ('" . implode("', '", $statusGroups[$search_status]) . "')";
    } elseif ($search_status === 'TIMEOUT') {
        $where .= " AND (u.send_status = 'TIMEOUT' OR " . $staleSql . ")";
    } elseif ($search_status === 'OFFLINE_DUE') {
        $where .= " AND NOT " . $noOffline . " AND u.send_status <> 'ACCEPTED' AND u.offline_deadline IS NOT NULL AND u.offline_deadline > 0"
            . " AND u.offline_deadline <= " . (int) (dol_now() + 86400);
        $noDeadline = ksefOfflineNoDeadlineInvoices($db);
        if (!empty($noDeadline)) {
            $where .= " AND u.fk_facture NOT IN (" . implode(',', array_map('intval', $noDeadline)) . ")";
        }
        $prodIssuedTest = ksefProductionIssuedTestOfflineRows($db);
        if (!empty($prodIssuedTest)) {
            $where .= " AND u.sub_id NOT IN (" . implode(',', array_map('intval', $prodIssuedTest)) . ")";
        }
    } elseif (in_array($search_status, array('PENDING', 'SUBMITTED'), true)) {
        $where .= " AND u.send_status = '" . $db->escape($search_status) . "' AND NOT " . $staleSql;
    } else {
        $where .= " AND u.send_status = '" . $db->escape($search_status) . "'";
    }
}
if ($search_link === 'ACTION') {
    $where .= " AND u.match_status IN ('DIFFERS', 'LINKABLE', 'SUGGEST', 'IMPORTABLE', 'CONFLICT', 'MISSING', 'NOT_SENT')";
} elseif ($search_link === 'ACTION_OTHER') {
    $where .= " AND u.match_status IN ('DIFFERS', 'LINKABLE', 'SUGGEST', 'CONFLICT', 'MISSING', 'NOT_SENT')";
} elseif ($search_link !== '' && $search_link != '-1') {
    $where .= " AND u.match_status = '" . $db->escape($search_link) . "'";
}
if ($search_environment && $search_environment != '-1') $where .= " AND u.environment = '" . $db->escape($search_environment) . "'";
if ($search_date_start) $where .= " AND u.row_date >= " . ((int) $search_date_start);
if ($search_date_end) $where .= " AND u.row_date <= " . ((int) $search_date_end);

$nbtotalofrecords = '';
if (!getDolGlobalInt('MAIN_DISABLE_FULL_SCANLIST')) {
    $resql = $db->query("SELECT COUNT(*) as nbtotalofrecords FROM " . $union . $where);
    if ($resql) {
        $nbtotalofrecords = (int) $db->fetch_object($resql)->nbtotalofrecords;
        $db->free($resql);
    } else {
        dol_print_error($db);
    }
    if (($page * $limit) > $nbtotalofrecords) {
        $page = 0;
        $offset = 0;
    }
}

$sql = "SELECT u.* FROM " . $union . $where . $db->order($sortMap[$sortfield], $sortorder) . ", u.fk_facture DESC";
if ($limit) $sql .= $db->plimit($limit + 1, $offset);
$resql = $db->query($sql);
if (!$resql) {
    dol_print_error($db);
    exit;
}
$num = $db->num_rows($resql);

$param = '';
if (!empty($contextpage) && $contextpage != $_SERVER["PHP_SELF"]) $param .= '&contextpage=' . urlencode($contextpage);
if ($limit > 0 && $limit != $conf->liste_limit) $param .= '&limit=' . ((int)$limit);
if ($search_ref) $param .= '&search_ref=' . urlencode($search_ref);
if ($search_ksef_number) $param .= '&search_ksef_number=' . urlencode($search_ksef_number);
if ($search_status && $search_status != '-1') $param .= '&search_status=' . urlencode($search_status);
if ($search_link !== '' && $search_link != '-1') $param .= '&search_link=' . urlencode($search_link);
if ($search_date_start) {
    $param .= '&search_date_startday=' . dol_print_date($search_date_start, '%d');
    $param .= '&search_date_startmonth=' . dol_print_date($search_date_start, '%m');
    $param .= '&search_date_startyear=' . dol_print_date($search_date_start, '%Y');
}
if ($search_date_end) {
    $param .= '&search_date_endday=' . dol_print_date($search_date_end, '%d');
    $param .= '&search_date_endmonth=' . dol_print_date($search_date_end, '%m');
    $param .= '&search_date_endyear=' . dol_print_date($search_date_end, '%Y');
}
if ($optioncss != '') $param .= '&optioncss=' . urlencode($optioncss);

$arrayofmassactions = array();
if ($permissiontosend) {
    $arrayofmassactions['retry'] = img_picto('', 'technic', 'class="pictofixedwidth"') . $langs->trans("KSEF_RetrySubmission");
}
if ($user->hasRight('ksef', 'write')) {
    $arrayofmassactions['retry_fetch'] = img_picto('', 'refresh', 'class="pictofixedwidth"') . $langs->trans('KSEF_RetryFetch');
}
if ($canImport) {
    $arrayofmassactions['import_sales'] = img_picto('', 'download', 'class="pictofixedwidth"') . $langs->trans('KSEF_SalesBatchImport');
}
if ($permissiontodelete) {
    $arrayofmassactions['predelete'] = img_picto('', 'delete', 'class="pictofixedwidth"') . $langs->trans("Delete");
}
$massactionbutton = $form->selectMassAction('', $arrayofmassactions);

$arrayofselected = is_array($toselect) ? $toselect : array();

$selectedfields = '';
if (count($arrayofmassactions)) {
    $selectedfields .= $form->showCheckAddButtons('checkforselect', 1);
}

$sqlerr = "SELECT s.error_code, COUNT(*) as nb FROM " . $P . "ksef_submissions as s";
$sqlerr .= " INNER JOIN " . $P . "facture as f ON s.fk_facture = f.rowid";
$sqlerr .= " WHERE s.status IN ('FAILED', 'REJECTED') AND " . $latestSub . ksefSqlInvoiceVisibility($user, 'f');
if ($socid > 0) $sqlerr .= " AND f.fk_soc = " . ((int) $socid);
if ($search_environment !== '' && $search_environment != '-1') $sqlerr .= " AND s.environment = '" . $db->escape($search_environment) . "'";
$sqlerr .= " GROUP BY s.error_code ORDER BY nb DESC";
$resqlerr = $db->query($sqlerr);
$errorRows = array();
while ($resqlerr && ($objerr = $db->fetch_object($resqlerr))) {
    $errorRows[] = array((string) $objerr->error_code, ksefKsefCodeLabel((string) $objerr->error_code), (int) $objerr->nb);
}
ksefPrintErrorSummary($errorRows, in_array($search_status, array('FAILED', 'REJECTED', 'FAILED_ANY'), true));

print '<form method="POST" id="searchFormList" name="searchFormList" action="' . $_SERVER["PHP_SELF"] . '">';
if ($optioncss != '') print '<input type="hidden" name="optioncss" value="' . $optioncss . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="formfilteraction" id="formfilteraction" value="list">';
print '<input type="hidden" name="action" value="list">';
print '<input type="hidden" name="sortfield" value="' . $sortfield . '">';
print '<input type="hidden" name="sortorder" value="' . $sortorder . '">';
print '<input type="hidden" name="page" value="' . $page . '">';
print '<input type="hidden" name="contextpage" value="' . $contextpage . '">';

print_barre_liste($langs->trans("List"), $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, $massactionbutton, $num, $nbtotalofrecords, 'object_ksef@ksef', 0, '', '', $limit, 0, 0, 1);

$topicmail = '';
$modelmail = '';
$objecttmp = new KsefSubmission($db);
$trackid = 'ksefs';
include DOL_DOCUMENT_ROOT.'/core/tpl/massactions_pre.tpl.php';

$curEnv = ksefGetEnvironment();
print ksefOtherEnvRowsHint($db, $curEnv, array('submissions', 'sales'));

print '<div class="div-table-responsive ksef-list-scroll"><table class="tagtable nobottomiftotal liste">' . "\n";

$linkOptions = array('ACTION' => $langs->trans('KSEF_SalesActionNeeded'), 'ACTION_OTHER' => $langs->trans('KSEF_SalesActionNeededOther'));
foreach (array(KsefSales::LINK_OK, KsefSales::LINK_DIFFERS, KsefSales::LINK_LINKABLE, KsefSales::LINK_SUGGEST, KsefSales::LINK_IMPORTABLE, KsefSales::LINK_CONFLICT,
    KsefSales::GAP_MISSING, KsefSales::GAP_NOT_SENT, KsefSales::GAP_OPTIONAL, KsefSales::LINK_UNSUPPORTED, KsefSales::LINK_SKIPPED, KsefIncoming::STATUS_FETCH_ERROR) as $k) {
    $linkOptions[$k] = KsefSales::linkLabel($k);
}

print '<tr class="liste_titre_filter">';
if (getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
    print '<td class="liste_titre center maxwidthsearch">' . $form->showFilterButtons('left') . '</td>';
}
print '<td class="liste_titre left"><input class="flat maxwidth75" type="text" name="search_ref" value="' . dol_escape_htmltag($search_ref) . '"></td>';
print '<td class="liste_titre left"></td>'; // Customer
print '<td class="liste_titre left"><input class="flat maxwidth100" type="text" name="search_ksef_number" value="' . dol_escape_htmltag($search_ksef_number) . '"></td>';
print '<td class="liste_titre center">' . $form->selectarray('search_status', array('IN_PROGRESS' => $langs->trans('KSEF_StatsInProgress'), 'FAILED_ANY' => $langs->trans('KSEF_Failed'), 'PENDING' => $langs->trans('KSEF_STATUS_PENDING'), 'SUBMITTED' => $langs->trans('KSEF_STATUS_SUBMITTED'), 'ACCEPTED' => $langs->trans('KSEF_STATUS_ACCEPTED'), 'REJECTED' => $langs->trans('KSEF_STATUS_REJECTED'), 'FAILED' => $langs->trans('KSEF_STATUS_FAILED'), 'TIMEOUT' => $langs->trans('KSEF_STATUS_TIMEOUT'), 'OFFLINE' => $langs->trans('KSEF_STATUS_OFFLINE'), 'OFFLINE_DUE' => $langs->trans('KSEF_OfflineDeadlineSoon')), $search_status, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth100') . '</td>';
print '<td class="liste_titre center">' . $form->selectarray('search_link', $linkOptions, $search_link, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth100') . '</td>';
print '<td class="liste_titre center"><div class="nowrap">' . $form->selectDate($search_date_start ? $search_date_start : -1, 'search_date_start', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('From')) . '</div>'
    . '<div class="nowrap">' . $form->selectDate($search_date_end ? $search_date_end : -1, 'search_date_end', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('to')) . '</div></td>';
print '<td class="liste_titre right"></td>'; // Amount
print '<td class="liste_titre center"></td>'; // Attempts
print '<td class="liste_titre center"></td>'; // Actions
if (!getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
    print '<td class="liste_titre center maxwidthsearch">' . $form->showFilterButtons() . '</td>';
}
print '</tr>' . "\n";

print '<tr class="liste_titre">';
if (getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) print getTitleFieldOfList($selectedfields, 0, $_SERVER["PHP_SELF"], '', '', '', '', $sortfield, $sortorder, 'center maxwidthsearch ') . "\n";
print_liste_field_titre("Invoice", $_SERVER["PHP_SELF"], "invoice_ref", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre("Customer", $_SERVER["PHP_SELF"], "company_name", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre("KSEF_Number", $_SERVER["PHP_SELF"], "ksef_number", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre("KSEF_SubmissionStatus", $_SERVER["PHP_SELF"], "send_status", "", $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre("KSEF_MatchStatusShort", $_SERVER["PHP_SELF"], "match_status", "", $param, '', $sortfield, $sortorder, 'center ', $langs->trans('KSEF_MatchStatus'));
print_liste_field_titre("Date", $_SERVER["PHP_SELF"], "row_date", "", $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre("Amount", $_SERVER["PHP_SELF"], "amount", "", $param, '', $sortfield, $sortorder, 'right ');
print_liste_field_titre("KSEF_Attempts", $_SERVER["PHP_SELF"], "", "", $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre("KSEF_ListActions", $_SERVER["PHP_SELF"], "", "", $param, '', '', '', 'center maxwidthsearch');
if (!getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) print getTitleFieldOfList($selectedfields, 0, $_SERVER["PHP_SELF"], '', '', '', '', $sortfield, $sortorder, 'center maxwidthsearch ') . "\n";
print '</tr>' . "\n";

$rowActs = array();
ob_start();
$pageRows = array();
$pageSalesIds = array();
while (count($pageRows) < min($num, $limit) && ($u = $db->fetch_object($resql))) {
    $pageRows[] = $u;
    if ((int) $u->sales_id > 0) {
        $pageSalesIds[] = (int) $u->sales_id;
    }
}
$pageSales = array();
if ($pageSalesIds) {
    foreach ((array) $sales->fetchAll(array('ids' => $pageSalesIds), 'i.rowid', 'ASC') as $r0) {
        $pageSales[(int) $r0->id] = $r0;
    }
}
$pickKinds = ksefPickKindsOfRows($db, array('sales'), $pageSalesIds);
$i = 0;
foreach ($pageRows as $u) {
    $fid = (int) $u->fk_facture;
    $rowAccess = $fid > 0 ? ksefUserCanReadInvoice($user, $fid) : true;

    $obj = null;
    if ($u->row_kind === 'inv') {
        $obj = new KsefSubmission($db);
        $obj->fetch((int) $u->sub_id);
        $obj->total_attempts = (int) $u->attempts;
    }
    $rec = $pageSales[(int) $u->sales_id] ?? null;
    $match = (string) $u->match_status;
    $cbValue = '';
    if ($obj && $rowAccess && ($massactionbutton || $massaction)) {
        $cbValue = (string) (int) $obj->rowid;
    } elseif ($rec && $match === KsefSales::LINK_IMPORTABLE && $canImport) {
        $cbValue = 'k' . (int) $rec->id;
    }
    $cb = $cbValue !== '' ? '<input id="cb' . $cbValue . '" class="flat checkforselect" type="checkbox" name="toselect[]" value="' . $cbValue . '"' . (in_array($cbValue, $arrayofselected) ? ' checked="checked"' : '') . '>' : '';

    print '<tr class="oddeven ksef-row-' . dol_escape_htmltag($u->row_kind) . '" data-match="' . dol_escape_htmltag($match) . '"' . ($rec && $canImport ? ksefPickRowAttrs($pickKinds, $rec->id) : '') . '>';
    if (getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
        print '<td class="nowrap center">' . $cb . '</td>';
    }

    print '<td class="tdoverflowmax150">';
    if ($fid > 0) {
        print '<a href="' . DOL_URL_ROOT . '/compta/facture/card.php?facid=' . $fid . '">' . img_object($langs->trans("KSEF_ShowInvoice"), 'bill', 'class="pictofixedwidth"') . dol_escape_htmltag($u->invoice_ref) . '</a>';
    } elseif ($rec) {
        print '<a href="' . dol_buildpath('/ksef/sales_card.php', 1) . '?id=' . (int) $rec->id . '" class="opacitymedium">' . img_object($langs->trans('KSEF_SalesInvoice'), 'bill', 'class="pictofixedwidth"') . ksefEscapeText($u->invoice_ref) . '</a>';
    } else {
        print '<span class="opacitymedium">' . ksefEscapeText($u->invoice_ref) . '</span>';
    }
    print '</td>';
    print '<td class="tdoverflowmax80" title="' . dol_escape_htmltag((string) $u->company_name) . '">' . ($u->socid > 0 ? '<a href="' . DOL_URL_ROOT . '/societe/card.php?socid=' . (int) $u->socid . '">' . img_object($langs->trans("ShowCompany"), 'company', 'class="pictofixedwidth"') . dol_escape_htmltag($u->company_name) . '</a>'
        : ($u->company_name !== null && $u->company_name !== '' ? '<span title="' . dol_escape_htmltag($rec ? $rec->buyer_nip : '') . '">' . ksefEscapeText($u->company_name) . '</span>' : '')) . '</td>';

    print '<td class="nowraponall">';
    if (!empty($u->ksef_number)) {
        $verify_url = $obj ? ksefGetVerificationURL($obj->ksef_number, ksefSubmissionHash($obj), $obj->environment, null, null, $obj->fa3_xml)
            : ($rec ? ksefGetVerificationURL($rec->ksef_number, ksefSubmissionHash($rec), $rec->environment, null, null, $rec->fa3_xml) : '');
        $knum = (string) $u->ksef_number;
        if ($verify_url !== '') {
            print '<a href="' . $verify_url . '" target="_blank" rel="noopener noreferrer" class="classfortooltip ksef-num-badge" title="' . dol_escape_htmltag($knum . ' - ' . $langs->transnoentitiesnoconv("KSEF_VerifyOnKSEFPortal")) . '"><span class="badge badge-info ' . ksefToneClass('id') . '">' . ksefNumberBadgeText($knum) . '</span></a>';
        } else {
            print '<span class="classfortooltip ksef-num-badge" title="' . dol_escape_htmltag($knum) . '"><span class="badge badge-info ' . ksefToneClass('id') . '">' . ksefNumberBadgeText($knum) . '</span></span>';
        }
    } else print '<span class="opacitymedium">-</span>';
    print '</td>';

    print '<td class="center">';
    if ($obj) {
        print ksefGetStatusBadge(ksefDisplayStatus($obj), true);
        $stopHint = ksefHashStopHint($db, $obj);
        if ($stopHint !== '') {
            print ' <span class="fa fa-info-circle ksef-hash-stop-icon classfortooltip ' . ksefToneTextClass(ksefOutgoingStatusTone(ksefHashStopState($obj))) . '" title="' . dol_escape_htmltag($stopHint) . '"></span>';
        }
        $offlineDeadline = ($obj->offline_mode && !empty($obj->offline_deadline) && $obj->status != 'ACCEPTED');
        if (in_array($obj->status, array('REJECTED', 'FAILED'))) {
            print ' <span class="fa fa-exclamation-triangle ksef-error-code ksef-error-icon classfortooltip" title="'
                . htmlspecialchars(ksefErrorTooltip($obj->error_code, $obj->error_message), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"></span>';
        }
        $dlProdRow = ($offlineDeadline && (string) $obj->environment !== 'PRODUCTION') ? ksefProductionIssuedBlock($db, (int) $obj->fk_facture, (string) $obj->environment) : null;
        if ($dlProdRow) {
            $dlTip = $langs->transnoentitiesnoconv('KSEF_DeadlineIssuedInProduction') . ' ' . ksefProductionIssuedRefusal($dlProdRow, (string) $obj->environment)
                . ' ' . $langs->transnoentitiesnoconv('KSEF_IssuedInProductionTestRowHint');
            print '<br><small class="ksef-offline-deadline"><span class="badge badge-info ' . ksefToneClass('info') . ' ksef-production-issued classfortooltip" title="' . dol_escape_htmltag($dlTip) . '">'
                . dol_print_date((int) $obj->offline_deadline, 'dayhour') . '</span></small>';
        } elseif ($offlineDeadline) {
            print '<br><small class="ksef-offline-deadline" title="' . dol_escape_htmltag(strip_tags(ksefGetOfflineModeBadge($obj->offline_mode))) . '">' . ksefFormatDeadline($obj->offline_deadline, true, ksefOfflineDeadlineForInvoice($db, $obj->fk_facture, $obj->environment, $obj->offline_deadline)) . '</small>';
        }
    } else {
        print '<span class="opacitymedium">-</span>';
    }
    print '</td>';

    print '<td class="center nowraponall">';
    if ($match !== '') {
        $tip = $langs->trans('KSEF_SalesLinkHelp_' . $match);
        if ($match === KsefIncoming::STATUS_FETCH_ERROR && $rec) {
            $tip = $rec->getFetchErrorText();
        }
        print KsefSales::linkBadge($match, $tip);
        if ($rec && !empty($rec->link_note) && $match !== KsefSales::LINK_OK) {
            print ' <span class="fa fa-exclamation-triangle ksef-error-icon classfortooltip" title="' . dol_escape_htmltag($rec->link_note) . '"></span>';
        }
    } else {
        print '<span class="opacitymedium">-</span>';
    }
    print '</td>';

    print '<td class="center nowraponall">' . dol_print_date((int) $u->row_date, 'day') . '</td>';
    $rowCur = ((string) $u->currency !== '') ? (string) $u->currency : $conf->currency;
    print '<td class="right nowraponall">';
    if ($rowCur !== $conf->currency && $u->mc_amount !== null && $u->row_kind !== 'ksef') {
        print price($u->mc_amount, 0, $langs, 1, -1, -1, $rowCur);
        print '<br><span class="opacitymedium small">' . price($u->amount, 0, $langs, 1, -1, -1, $conf->currency) . '</span>';
    } else {
        print price($u->amount, 0, $langs, 1, -1, -1, $rowCur);
    }
    print '</td>';
    print '<td class="center">';
    if ($obj) {
        print ($obj->total_attempts > 1 ? '<a href="' . DOL_URL_ROOT . '/custom/ksef/tab_ksef.php?id=' . $fid . '" class="classfortooltip" title="' . $langs->trans("KSEF_ViewAllAttemptsOnInvoice") . '"><span class="badge badge-info">' . $obj->total_attempts . '</span></a>' : '<span class="opacitymedium">1</span>');
    } else {
        print '<span class="opacitymedium">-</span>';
    }
    print '</td>';

    print '<td class="center nowraponall">';
    $act = array();
    if ($obj) {
        $can_retry = $rowAccess && $obj->canRetry();
        $techCorr = $rowAccess && in_array($obj->status, array('REJECTED', 'FAILED')) && !empty($obj->offline_mode) && $permissiontosend;
        $prodRefusal = '';
        if ((($can_retry && $permissiontosend) || $techCorr) && (string) $obj->environment !== 'PRODUCTION') {
            $prodRow = ksefProductionIssuedBlock($db, (int) $obj->fk_facture, (string) $obj->environment);
            if ($prodRow) {
                $prodRefusal = ksefProductionIssuedRefusal($prodRow, (string) $obj->environment) . ' ' . $langs->transnoentitiesnoconv('KSEF_IssuedInProductionTestRowHint');
            }
        }
        if ($prodRefusal !== '' && $can_retry && $permissiontosend) {
            $act['retry'] = '<span class="butActionRefused ksef-act-refused ksef-production-issued classfortooltip" title="' . dol_escape_htmltag($prodRefusal) . '"><span class="fa fa-paper-plane"></span></span>';
        } elseif ($can_retry && $permissiontosend) {
            $act['retry'] = ksefActIcon('retry', $_SERVER["PHP_SELF"] . '?action=retry&id=' . $obj->rowid . '&token=' . newToken(), $langs->trans("KSEF_RetrySubmission"), 'fa-paper-plane');
        }
        if ($rowAccess && in_array($obj->status, array('SUBMITTED', 'TIMEOUT', 'PENDING')) && (!empty($obj->ksef_session_reference) ? (!empty($obj->ksef_reference) || !empty($obj->invoice_hash)) : !empty($obj->invoice_hash)) && $permissiontorefresh) {
            $checkRefusal = ksefStatusCheckRefusal($user, $obj);
            $act['check'] = $checkRefusal === '' ? ksefActIcon('check', $_SERVER["PHP_SELF"] . '?action=check_status&id=' . $obj->rowid . '&token=' . newToken(), $langs->trans("KSEF_CheckStatus"), 'fa-sync-alt')
                : '<span class="butActionRefused ksef-act-refused ksef-act-check-refused classfortooltip" title="' . dol_escape_htmltag($langs->transnoentitiesnoconv('KSEF_CheckStatus') . ': ' . $checkRefusal) . '"><span class="fa fa-sync-alt"></span></span>';
        } elseif ($techCorr && $prodRefusal !== '') {
            $act['check'] = '<span class="butActionRefused ksef-act-refused ksef-production-issued classfortooltip" title="' . dol_escape_htmltag($prodRefusal) . '"><span class="fa fa-tools"></span></span>';
        } elseif ($techCorr) {
            $act['check'] = ksefActIcon('retry', DOL_URL_ROOT . '/compta/facture/card.php?facid=' . $fid . '&action=ksef_technical_correction&submission_id=' . $obj->rowid . '&token=' . newToken(), $langs->trans("KSEF_TechnicalCorrection"), 'fa-tools');
        }
        if ($rowAccess && in_array($obj->status, array('FAILED', 'REJECTED')) && (!empty($obj->error_code) || !empty($obj->error_message))) {
            $act['doc'] = ksefActIcon('error', $_SERVER["PHP_SELF"] . '?action=view_error&id=' . $obj->rowid . '&token=' . newToken(), $langs->trans("KSEF_ViewErrorDetails"), 'fa-exclamation-circle');
        }
    }
    if ($rec && $match === KsefSales::LINK_IMPORTABLE && $canImport) {
        $act['gap'] = '<a class="butAction classfortooltip" href="' . dol_buildpath('/ksef/sales_import.php', 1) . '?id=' . $rec->id . '" title="' . $langs->trans('KSEF_ImportToDolibarrSales') . '" aria-label="' . $langs->trans('KSEF_ImportToDolibarrSales') . '"><span class="fa fa-file-import"></span></a>';
    } elseif ($rec && in_array($match, array(KsefSales::LINK_LINKABLE, KsefSales::LINK_SUGGEST), true) && $canLink && (int) $rec->link_candidate > 0 && ksefUserCanReadInvoice($user, (int) $rec->link_candidate)) {
        $act['gap'] = ksefActIcon('link', $_SERVER['PHP_SELF'] . '?action=link_sales&sales_id=' . $rec->id . '&token=' . newToken(), $langs->trans('KSEF_LinkToInvoice'), 'fa-link');
    } elseif ($rec && $match === KsefSales::LINK_DIFFERS && $canAdjust && ksefUserCanReadInvoice($user, (int) $rec->fk_facture)) {
        if ($rec->sourceLinesAddUp()) {
            $act['gap'] = ksefActIcon('adjust', dol_buildpath('/ksef/sales_adjust.php', 1) . '?id=' . $rec->id, $langs->trans('KSEF_AdjustToKsef'), 'fa-exchange-alt');
        } else {
            $act['gap'] = '<span class="butActionRefused ksef-act-refused classfortooltip" title="' . dol_escape_htmltag($langs->transnoentitiesnoconv('KSEF_AdjustRefusedSourceLines')) . '"><span class="fa fa-exchange-alt"></span></span>';
        }
    }
    if ($u->row_kind === 'gap' && $user->hasRight('ksef', 'write')) {
        if (in_array($match, array(KsefSales::GAP_NOT_SENT, KsefSales::GAP_OPTIONAL), true)) {
            $act['gap'] = ksefActIcon('skip', $_SERVER['PHP_SELF'] . '?action=gap_skip&gap_facture=' . (int) $u->fk_facture . '&token=' . newToken(), $langs->trans('KSEF_GapSkip'), 'fa-eye-slash');
        } elseif ($match === KsefSales::LINK_SKIPPED) {
            $act['gap'] = ksefActIcon('skip', $_SERVER['PHP_SELF'] . '?action=gap_unskip&gap_facture=' . (int) $u->fk_facture . '&token=' . newToken(), $langs->trans('KSEF_GapReopen'), 'fa-undo');
        }
    }
    if ($obj) {
        if ($rowAccess && !empty($obj->fa3_xml)) {
            $act['xml'] = ksefActIcon('xml', $_SERVER["PHP_SELF"] . '?action=download_xml&id=' . $obj->rowid . '&token=' . newToken(), $langs->trans("KSEF_DownloadXml"), 'fa-file-code');
        }
        if ($rowAccess && $obj->status == 'ACCEPTED' && !empty($obj->ksef_number)) {
            $act['doc'] = ksefActIcon('upo', $_SERVER["PHP_SELF"] . '?action=download_upo&id=' . $obj->rowid . '&token=' . newToken(), dol_escape_htmltag(ksefUpoDownloadTitle($obj)), 'fa-certificate');
        }
        if ($rowAccess && $permissiontodelete && ksefSubmissionRowLock($obj, $user)['result'] != 'blocked') {
            $act['delete'] = '<a class="butActionDelete classfortooltip" href="' . $_SERVER["PHP_SELF"] . '?action=delete&id=' . $obj->rowid . '&token=' . newToken() . '" title="' . $langs->trans("Delete") . '" aria-label="' . $langs->trans("Delete") . '"><span class="fa fa-trash"></span></a>';
        }
    } elseif ($rec && !empty($rec->fa3_xml)) {
        $act['xml'] = ksefActIcon('xml', $_SERVER['PHP_SELF'] . '?action=download_sales_xml&sales_id=' . $rec->id . '&token=' . newToken(), $langs->trans('KSEF_DownloadXml'), 'fa-file-code');
        if ($match !== KsefIncoming::STATUS_FETCH_ERROR) {
            $act['doc'] = ksefActIcon('pdf', $_SERVER['PHP_SELF'] . '?action=download_sales_pdf&sales_id=' . $rec->id . '&token=' . newToken(), $langs->trans('KSEF_DownloadPdf'), 'fa-file-pdf');
        }
    }
    print ksefRowActionsHold($rowActs, $act);
    print '</td>';

    if (!getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
        print '<td class="nowrap center">' . $cb . '</td>';
    }

    print '</tr>';
    $i++;
}

print ksefRowActionsFill(ob_get_clean(), array('retry', 'check', 'gap', 'xml', 'doc', 'delete'), $rowActs);

if ($num == 0) {
    $filtered = ($search_ref !== '' || $search_ksef_number !== '' || ($search_status !== '' && $search_status != '-1') || ($search_link !== '' && $search_link != '-1')
        || $search_date_start || $search_date_end || ($search_environment !== '' && $search_environment != '-1' && $search_environment !== $curEnv));
    $emptyText = $filtered ? $langs->trans('KSEF_NoRecordMatchFilters') : $langs->trans('KSEF_OutgoingEmptyInEnv', ksefEnvironmentLabel($curEnv));
    print '<tr><td colspan="10"><span class="opacitymedium">' . $emptyText . '</span></td></tr>';
}

$db->free($resql);
print '</table></div></form>';

if ($canImport) {
    $extra = '';
    $whSelect = ksefImportWarehouseSelect($db, $user, 'outgoing');
    if ($whSelect !== '') {
        $extra .= $langs->trans('KSEF_SalesWarehouse') . ' ' . $whSelect . '<br>';
    }
    if (ksefUserCan($user, 'record_payment') && isModEnabled('bank')) {
        ob_start();
        $form->select_comptes(0, 'bank_account_id', 0, '', $langs->trans('KSEF_SalesBankAuto'));
        $extra .= '<label><input type="checkbox" name="record_payment" value="1" checked> ' . $langs->trans('KSEF_SalesRecordPayment') . '</label> ' . ob_get_clean();
    }
    $stockOn = isModEnabled('stock') && getDolGlobalString('STOCK_CALCULATE_ON_BILL');
    ksefPrintBatchImportDialog(array(
        'direction' => 'outgoing',
        'massaction' => 'import_sales',
        'value_prefix' => 'k',
        'rights' => array('import' => $canImport, 'create_party' => ksefUserCan($user, 'create_customer'), 'create_product' => ksefUserCan($user, 'create_product')),
        'party_box_on' => ksefImportPartyBoxDefault('outgoing'),
        'product_box_on' => true,
        'extra_options_html' => $extra,
        'stock_short' => isModEnabled('stock') ? $langs->trans($stockOn ? 'KSEF_SalesStockWillDecrease' : 'KSEF_SalesStockWillNotDecrease') : '',
        'stock_verbose' => isModEnabled('stock') ? $langs->trans($stockOn ? 'KSEF_SalesStockDecreaseHelp' : 'KSEF_SalesStockNoDecreaseHelp') : '',
    ));
}

print ksefHelpFooter($db, $user);
llxFooter();
$db->close();
