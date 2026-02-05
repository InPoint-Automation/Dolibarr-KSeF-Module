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
 * \brief   List and sync incoming invoices from KSeF
 */

// CSRF check
if (!defined('CSRFCHECK_WITH_TOKEN')) {
    define('CSRFCHECK_WITH_TOKEN', '1');
}

// Prevent token renewal for actions that exit without page reload
$action_raw = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
if (in_array($action_raw, array('check_fetch_status', 'init_fetch', 'download_xml', 'download_pdf'))) {
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
dol_include_once('/ksef/class/ksef_incoming.class.php');
dol_include_once('/ksef/class/ksef_sync_state.class.php');
dol_include_once('/ksef/class/ksef.class.php');
dol_include_once('/ksef/lib/ksef.lib.php');

$langs->loadLangs(array("ksef@ksef", "bills"));

if (!$user->hasRight('ksef', 'read')) accessforbidden();

// Parameters
$action = GETPOST('action', 'aZ09');
$massaction = GETPOST('massaction', 'alpha');
$confirm = GETPOST('confirm', 'alpha');
$toselect = GETPOST('toselect', 'array');
$contextpage = GETPOST('contextpage', 'aZ09') ? GETPOST('contextpage', 'aZ09') : 'ksef_incoming_list';
$optioncss = GETPOST('optioncss', 'alpha');

// Search filters
$search_seller_nip = GETPOST('search_seller_nip', 'alpha');
$search_seller_name = GETPOST('search_seller_name', 'alpha');
$search_invoice_number = GETPOST('search_invoice_number', 'alpha');
$search_ksef_number = GETPOST('search_ksef_number', 'alpha');
$search_import_status = GETPOST('search_import_status', 'alpha');

// Pagination
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
$ksef = new KSEF($db);

$hookmanager->initHooks(array('ksef_incoming_list'));

/*
 * AJAX - Check fetch status
 */

if ($action == 'check_fetch_status') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache');

    if (!$user->hasRight('ksef', 'read')) {
        echo json_encode(array('status' => 'ERROR', 'error' => 'Access denied'));
        exit;
    }

    echo json_encode($ksef->checkIncomingFetchStatus($user));
    exit;
}

/*
 * AJAX - Initiate fetch
 */

if ($action == 'init_fetch') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache');

    if (!$user->hasRight('ksef', 'write')) {
        echo json_encode(array('status' => 'ERROR', 'error' => 'Access denied'));
        exit;
    }

    $result = $ksef->initIncomingFetch($user);
    if ($result === false) {
        echo json_encode(array('status' => 'ERROR', 'error' => $ksef->error));
    } else {
        echo json_encode($result);
    }
    exit;
}

/*
 * Actions
 */

$parameters = array();
$reshook = $hookmanager->executeHooks('doActions', $parameters, $incoming, $action);
if ($reshook < 0) setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');

if (empty($reshook)) {
    include DOL_DOCUMENT_ROOT . '/core/actions_changeselectedfields.inc.php';

    // Reset filters
    if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
        $search_seller_nip = '';
        $search_seller_name = '';
        $search_invoice_number = '';
        $search_ksef_number = '';
        $search_import_status = '';
        $toselect = array();
    }

    // Initiate async sync from KSeF
    if ($action == 'sync' && $user->hasRight('ksef', 'write')) {
        $result = $ksef->initIncomingFetch($user);

        if ($result === false) {
            setEventMessages($ksef->error, $ksef->errors, 'errors');
        } elseif ($result['status'] === 'INITIATED' || $result['status'] === 'ALREADY_PROCESSING') {
            // JS should handle this, but fallback for non-JS browsers
            header('Location: ' . $_SERVER["PHP_SELF"] . '?polling=1');
            exit;
        }
    }

    // Reset stuck fetch
    if ($action == 'reset_fetch' && $user->hasRight('ksef', 'write')) {
        if ($ksef->resetIncomingFetch()) {
            setEventMessages($langs->trans('KSEF_FetchReset'), null, 'mesgs');
        } else {
            setEventMessages($ksef->error, null, 'errors');
        }
        header('Location: ' . $_SERVER["PHP_SELF"]);
        exit;
    }

    // Reset sync state (admin)
    if ($action == 'confirm_reset' && $confirm == 'yes' && $user->admin) {
        $daysBack = GETPOST('reset_days', 'int') ?: 30;
        if ($ksef->resetIncomingSyncState($user, $daysBack)) {
            setEventMessages($langs->trans('KSEF_SyncStateReset', $daysBack), null, 'mesgs');
        } else {
            setEventMessages($langs->trans('Error'), null, 'errors');
        }
    }

    // Mass delete
    if (!empty($massaction) && !empty($toselect)) {
        if ($massaction == 'delete' && GETPOST('confirmmassaction', 'alpha') && $user->hasRight('ksef', 'write')) {
            $num_deleted = 0;
            $num_errors = 0;
            $db->begin();
            foreach ($toselect as $toselectid) {
                $record = new KsefIncoming($db);
                if ($record->fetch($toselectid) > 0) {
                    if ($record->delete($user) > 0) {
                        $num_deleted++;
                    } else {
                        $num_errors++;
                    }
                }
            }
            if ($num_errors == 0) {
                $db->commit();
                setEventMessages($langs->trans("RecordsDeleted", $num_deleted), null, 'mesgs');
            } else {
                $db->rollback();
                setEventMessages($langs->trans("Error") . " - " . $num_deleted . " deleted, " . $num_errors . " errors", null, 'errors');
            }
            header("Location: " . $_SERVER["PHP_SELF"]);
            exit;
        }
    }

    // Clear all
    if ($action == 'confirm_clearall' && $confirm == 'yes' && $user->admin) {
        $environment = getDolGlobalString('KSEF_ENVIRONMENT', 'TEST');
        $deleted = $incoming->deleteAll($user, $environment);
        if ($deleted >= 0) {
            setEventMessages($langs->trans("KSEF_ClearedRecords", $deleted, $environment), null, 'mesgs');
        } else {
            setEventMessages($incoming->error, null, 'errors');
        }
    }

    // Download XML
    if ($action == 'download_xml' && GETPOST('id', 'int')) {
        $id = GETPOST('id', 'int');
        $record = new KsefIncoming($db);
        if ($record->fetch($id) > 0 && !empty($record->fa3_xml)) {
            $filename = 'FA3_' . ($record->ksef_number ?: 'incoming_' . $id) . '.xml';
            header('Content-Type: application/xml; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($record->fa3_xml));
            echo $record->fa3_xml;
            exit;
        }
        setEventMessages($langs->trans('KSEF_XmlNotAvailable'), null, 'warnings');
    }

    // Download PDF
    if ($action == 'download_pdf' && GETPOST('id', 'int')) {
        $id = GETPOST('id', 'int');
        $record = new KsefIncoming($db);
        if ($record->fetch($id) > 0 && !empty($record->fa3_xml)) {
            $pdfContent = $record->generatePdfVisualization();
            if ($pdfContent) {
                $filename = 'Invoice_' . ($record->invoice_number ?: $id) . '.pdf';
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Content-Length: ' . strlen($pdfContent));
                echo $pdfContent;
                exit;
            }
        }
        setEventMessages($langs->trans('KSEF_PdfGenerationFailed'), null, 'warnings');
    }
}


/*
 * View
 */

$title = $langs->trans('KSEF_IncomingInvoices');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-ksef page-incoming-list');

print load_fiche_titre($title, '', 'ksef@ksef');

// Confirmation dialogs
if ($action == 'clearall') {
    $environment = getDolGlobalString('KSEF_ENVIRONMENT', 'TEST');
    print $form->formconfirm($_SERVER["PHP_SELF"], $langs->trans('KSEF_ClearAll'), $langs->trans('KSEF_ConfirmClearAll', $environment), 'confirm_clearall', '', 0, 1);
}

if ($action == 'reset') {
    $formquestion = array(
        array('type' => 'text', 'name' => 'reset_days', 'label' => $langs->trans('KSEF_ResetDaysBack'), 'value' => '30', 'size' => 5)
    );
    print $form->formconfirm($_SERVER["PHP_SELF"], $langs->trans('KSEF_ResetSyncState'), $langs->trans('KSEF_ConfirmResetSync'), 'confirm_reset', $formquestion, 0, 1);
}

// Get state and stats
$environment = getDolGlobalString('KSEF_ENVIRONMENT', 'TEST');
$syncState = $ksef->getIncomingSyncState();
$syncState->getDisplayValues(); // Populate compatibility fields
$stats = $incoming->getStatistics(30);

$isPolling = GETPOST('polling', 'int') || $syncState->isFetchInProgress();
$showFailedState = ($syncState->fetch_status === KsefSyncState::FETCH_STATUS_FAILED || $syncState->fetch_status === KsefSyncState::FETCH_STATUS_TIMEOUT);

$overlayStyle = ($isPolling || $showFailedState) ? '' : ' style="display:none;"';
$overlayClass = $showFailedState ? 'error' : 'info';

print '<div id="ksef-fetch-overlay" class="' . $overlayClass . '"' . $overlayStyle . ' style="padding: 15px; margin-bottom: 15px; position: relative;' . ($isPolling || $showFailedState ? '' : ' display:none;') . '">';

if ($showFailedState && !$isPolling) {
    // Show failed/timeout state
    print '<span class="fa fa-exclamation-triangle" style="color: #dc3545; margin-right: 10px;"></span>';
    print '<strong style="color: #dc3545;">' . $langs->trans('KSEF_FetchFailed') . '</strong>';
    if (!empty($syncState->fetch_error)) {
        print '<span class="opacitymedium" style="margin-left: 10px;">' . dol_escape_htmltag($syncState->fetch_error) . '</span>';
    }
    print '<a href="' . $_SERVER["PHP_SELF"] . '?action=reset_fetch&token=' . newToken() . '" class="butAction" style="margin-left: 15px;">';
    print $langs->trans('KSEF_ResetAndRetry');
    print '</a>';
} else {
    // Default/processing state (will be populated by JS)
    $elapsed = $syncState->isFetchInProgress() ? (dol_now() - $syncState->fetch_started) : 0;
    print '<span class="fa fa-spinner fa-spin" style="margin-right: 10px;"></span>';
    print '<strong>' . $langs->trans('KSEF_FetchInProgress') . '</strong>';
    print '<span id="ksef-elapsed" style="margin-left: 15px;" class="opacitymedium">';
    print $langs->trans('KSEF_ElapsedTime') . ': <span id="elapsed-val">' . gmdate("i:s", $elapsed) . '</span>';
    print '</span>';
    print '<a href="' . $_SERVER["PHP_SELF"] . '?action=reset_fetch&token=' . newToken() . '" class="butAction" style="position: absolute; right: 15px; top: 12px;" onclick="return confirm(\'' . dol_escape_js($langs->trans('KSEF_ConfirmCancelFetch')) . '\');">';
    print '<span class="fa fa-times"></span> ' . $langs->trans('Cancel');
    print '</a>';
}

print '</div>';

// Main polling/sync
print '<script>
(function() {
    var overlayEl = document.getElementById("ksef-fetch-overlay");
    var elapsedEl = document.getElementById("elapsed-val");
    var elapsedTimer = null;
    var startTime = ' . ($syncState->isFetchInProgress() ? (int)$syncState->fetch_started : 'null') . ';
    var isPolling = ' . ($isPolling ? 'true' : 'false') . ';
    var token = "' . newToken() . '";
    var ajaxToken = "' . currentToken() . '";
    var baseUrl = "' . $_SERVER["PHP_SELF"] . '";

    var langFetchInProgress = "' . dol_escape_js($langs->trans('KSEF_FetchInProgress')) . '";
    var langElapsedTime = "' . dol_escape_js($langs->trans('KSEF_ElapsedTime')) . '";
    var langFetchCompleted = "' . dol_escape_js($langs->trans('KSEF_FetchCompleted')) . '";
    var langNewInvoices = "' . dol_escape_js($langs->trans('KSEF_NewInvoices')) . '";
    var langExistingInvoices = "' . dol_escape_js($langs->trans('KSEF_ExistingInvoices')) . '";
    var langFetchFailed = "' . dol_escape_js($langs->trans('KSEF_FetchFailed')) . '";
    var langResetAndRetry = "' . dol_escape_js($langs->trans('KSEF_ResetAndRetry')) . '";
    var langCancel = "' . dol_escape_js($langs->trans('Cancel')) . '";
    var langConfirmCancel = "' . dol_escape_js($langs->trans('KSEF_ConfirmCancelFetch')) . '";
    var langInitiating = "' . dol_escape_js($langs->trans('KSEF_Initiating')) . '";

    function formatElapsed(seconds) {
        return String(Math.floor(seconds/60)).padStart(2,"0") + ":" + String(seconds%60).padStart(2,"0");
    }

    function startElapsedTimer() {
        if (elapsedTimer) clearInterval(elapsedTimer);
        elapsedTimer = setInterval(function() {
            var elapsed = Math.floor(Date.now() / 1000) - startTime;
            if (elapsedEl) elapsedEl.textContent = formatElapsed(elapsed);
        }, 1000);
    }

    function showProcessingOverlay() {
        overlayEl.className = "info";
        overlayEl.style.display = "block";
        overlayEl.innerHTML = \'<span class="fa fa-spinner fa-spin" style="margin-right:10px;"></span>\' +
            \'<strong>\' + langFetchInProgress + \'</strong>\' +
            \'<span id="ksef-elapsed" style="margin-left:15px;" class="opacitymedium">\' + langElapsedTime + \': <span id="elapsed-val">00:00</span></span>\' +
            \'<a href="\' + baseUrl + \'?action=reset_fetch&token=\' + token + \'" class="butAction" style="position:absolute;right:15px;top:12px;" onclick="return confirm(\\\'\' + langConfirmCancel + \'\\\');"><span class="fa fa-times"></span> \' + langCancel + \'</a>\';
        elapsedEl = document.getElementById("elapsed-val");
    }

    function showCompletedOverlay(data) {
        if (elapsedTimer) clearInterval(elapsedTimer);
        overlayEl.className = "info";
        overlayEl.innerHTML = \'<span class="fa fa-check-circle" style="color:#28a745;margin-right:10px;"></span>\' +
            \'<strong style="color:#28a745;">\' + langFetchCompleted + \'</strong> - \' +
            langNewInvoices + \': \' + (data.new||0) + \', \' + langExistingInvoices + \': \' + (data.existing||0);
    }

    function showErrorOverlay(error) {
        if (elapsedTimer) clearInterval(elapsedTimer);
        overlayEl.className = "error";
        overlayEl.innerHTML = \'<span class="fa fa-exclamation-triangle" style="margin-right:10px;"></span>\' +
            \'<strong>\' + langFetchFailed + \'</strong>\' + (error ? \': \' + error : \'\') +
            \' <a href="\' + baseUrl + \'?action=reset_fetch&token=\' + token + \'" class="butAction" style="margin-left:15px;">\' + langResetAndRetry + \'</a>\';
    }

    function poll() {
        fetch(baseUrl + "?action=check_fetch_status&token=" + ajaxToken)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            console.log("KSeF poll:", data);
            if (data.status === "COMPLETED") {
                showCompletedOverlay(data);
                setTimeout(function() { location.href = baseUrl; }, 2000);
            } else if (data.status === "PROCESSING") {
                setTimeout(poll, 3000);
            } else if (data.status === "RATE_LIMITED") {
                setTimeout(poll, (data.retry_after || 10) * 1000);
            } else if (data.status === "FAILED" || data.status === "TIMEOUT" || data.status === "DOWNLOAD_FAILED" || data.status === "CHECK_FAILED") {
                showErrorOverlay(data.error || "");
            } else if (data.status === "NO_PENDING_FETCH") {
                location.href = baseUrl;
            } else {
                setTimeout(poll, 3000);
            }
        })
        .catch(function(e) { console.error("Poll error:", e); setTimeout(poll, 6000); });
    }

    // Initiate sync via AJAX
    window.ksefInitSync = function(e) {
        if (e) e.preventDefault();

        // Show overlay immediately
        startTime = Math.floor(Date.now() / 1000);
        showProcessingOverlay();
        startElapsedTimer();

        // Disable the sync button
        var btn = document.getElementById("ksef-sync-btn");
        if (btn) {
            btn.className = "butActionRefused";
            btn.onclick = function() { return false; };
        }

        // Call init_fetch AJAX endpoint
        fetch(baseUrl + "?action=init_fetch&token=" + ajaxToken)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            console.log("KSeF init:", data);
            if (data.status === "INITIATED" || data.status === "ALREADY_PROCESSING") {
                setTimeout(poll, 1000);
            } else if (data.status === "ERROR") {
                showErrorOverlay(data.error || "Init failed");
            } else {
                setTimeout(poll, 1000);
            }
        })
        .catch(function(e) {
            console.error("Init error:", e);
            showErrorOverlay("Connection error");
        });

        return false;
    };

    if (isPolling && startTime) {
        startElapsedTimer();
        setTimeout(poll, 1000);
    }
})();
</script>';

/*
 * Sync Panel
 */

print '<div class="fichecenter">';
print '<div class="fichethirdleft">';

// Sync Panel
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder nohover centpercent">';
print '<tr class="liste_titre"><th colspan="2">' . $langs->trans("KSEF_SyncFromKSeF") . '</th></tr>';

// Environment
print '<tr class="oddeven"><td class="titlefield">' . $langs->trans("KSEF_Environment") . '</td>';
print '<td>' . ksefGetEnvironmentBadge($environment) . '</td></tr>';

// Sync state info
if ($syncState) {
    print '<tr class="oddeven"><td>' . $langs->trans("KSEF_SyncContinuationPoint") . '</td>';
    print '<td>' . ($syncState->continuation_date ? dol_print_date($syncState->continuation_date, 'dayhour') : '<span class="opacitymedium">' . $langs->trans("NotSet") . '</span>') . '</td></tr>';

    print '<tr class="oddeven"><td>' . $langs->trans("KSEF_LastSync") . '</td>';
    print '<td>' . ($syncState->last_sync_date ? dol_print_date($syncState->last_sync_date, 'dayhour') : '<span class="opacitymedium">' . $langs->trans("Never") . '</span>') . '</td></tr>';

    if ($syncState->last_sync_date) {
        print '<tr class="oddeven"><td>' . $langs->trans("KSEF_LastSyncResult") . '</td>';
        print '<td>' . $langs->trans("KSEF_SyncResultShort", $syncState->last_sync_new, $syncState->last_sync_existing, $syncState->last_sync_total) . '</td></tr>';
    }

    if ($syncState->isRateLimited()) {
        $retryTime = $syncState->getRateLimitExpiryFormatted();
        print '<tr class="oddeven"><td colspan="2"><span class="warning">';
        print '<span class="fa fa-clock"></span> ' . $langs->trans("KSEF_RateLimitActive", $retryTime);
        print '</span></td></tr>';
    }
} else {
    print '<tr class="oddeven"><td colspan="2"><span class="opacitymedium">';
    print $langs->trans("KSEF_SyncNotInitialized");
    print '</span></td></tr>';
}

print '</table>';
print '</div>';

// Sync button
print '<div class="center" style="margin-top: 15px;">';
if ($user->hasRight('ksef', 'write')) {
    $canSync = $syncState && $syncState->canSyncNow();
    $btnClass = $canSync ? 'butAction' : 'butActionRefused';
    $btnTitle = '';
    if (!$canSync) {
        if ($syncState->isFetchInProgress()) {
            $btnTitle = ' title="' . $langs->trans('KSEF_FetchAlreadyInProgress') . '"';
        } elseif ($syncState->isRateLimited()) {
            $btnTitle = ' title="' . $langs->trans('KSEF_RateLimitActive', $syncState->getRateLimitExpiryFormatted()) . '"';
        }
    }

    $onclick = $canSync ? ' onclick="return ksefInitSync(event);"' : ' onclick="return false;"';
    print '<a id="ksef-sync-btn" class="' . $btnClass . '"' . $btnTitle . $onclick . ' href="' . $_SERVER["PHP_SELF"] . '?action=sync&token=' . newToken() . '">';
    print '<span class="fa fa-sync"></span> ' . $langs->trans("KSEF_SyncNow");
    print '</a>';
}
print '</div>';

print '</div>';

// Statistics Panel
print '<div class="fichetwothirdright">';

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder nohover centpercent">';
print '<tr class="liste_titre"><th colspan="2">' . $langs->trans("Statistics") . ' (' . $langs->trans("KSEF_Last30Days") . ')</th></tr>';

print '<tr class="oddeven"><td class="titlefield">' . $langs->trans("Total") . '</td>';
print '<td class="right"><strong>' . (int)($stats['total'] ?? 0) . '</strong></td></tr>';

print '<tr class="oddeven"><td>' . $langs->trans("KSEF_ImportStatusNEW") . '</td>';
print '<td class="right"><span class="badge badge-status1">' . (int)($stats['new'] ?? 0) . '</span></td></tr>';

print '<tr class="oddeven"><td>' . $langs->trans("KSEF_ImportStatusIMPORTED") . '</td>';
print '<td class="right"><span class="badge badge-status4">' . (int)($stats['imported'] ?? 0) . '</span></td></tr>';

if (!empty($stats['error'])) {
    print '<tr class="oddeven"><td>' . $langs->trans("KSEF_ImportStatusERROR") . '</td>';
    print '<td class="right"><span class="badge badge-status8">' . (int)$stats['error'] . '</span></td></tr>';
}

print '</table>';
print '</div>';

// Admin actions
if ($user->admin) {
    print '<div class="center" style="margin-top: 15px;">';

    print '<a class="butAction" href="' . $_SERVER["PHP_SELF"] . '?action=reset&token=' . newToken() . '">';
    print '<span class="fa fa-undo"></span> ' . $langs->trans("KSEF_ResetSyncState");
    print '</a> ';

    print '<a class="butActionDelete" href="' . $_SERVER["PHP_SELF"] . '?action=clearall&token=' . newToken() . '">';
    print '<span class="fa fa-trash"></span> ' . $langs->trans("KSEF_ClearAll");
    print '</a>';

    print '</div>';
}

print '</div>';
print '</div>';

print '<div class="clearboth"></div>';
print '<br>';

$filters = array();
if (!empty($search_seller_nip)) $filters['seller_nip'] = $search_seller_nip;
if (!empty($search_seller_name)) $filters['seller_name'] = $search_seller_name;
if (!empty($search_invoice_number)) $filters['invoice_number'] = $search_invoice_number;
if (!empty($search_ksef_number)) $filters['ksef_number'] = $search_ksef_number;
if (!empty($search_import_status) && $search_import_status != '-1') $filters['import_status'] = $search_import_status;

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
if ($optioncss != '') $param .= '&optioncss=' . urlencode($optioncss);

$arrayofmassactions = array(
    'delete' => img_picto('', 'delete', 'class="pictofixedwidth"') . $langs->trans("Delete")
);
$massactionbutton = $form->selectMassAction('', $arrayofmassactions);

$arrayofselected = is_array($toselect) ? $toselect : array();

print '<form method="POST" id="searchFormList" action="' . $_SERVER["PHP_SELF"] . '" name="formlist">';
if ($optioncss != '') print '<input type="hidden" name="optioncss" value="' . $optioncss . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="formfilteraction" id="formfilteraction" value="list">';
print '<input type="hidden" name="action" value="list">';
print '<input type="hidden" name="sortfield" value="' . $sortfield . '">';
print '<input type="hidden" name="sortorder" value="' . $sortorder . '">';
print '<input type="hidden" name="page" value="' . $page . '">';
print '<input type="hidden" name="contextpage" value="' . $contextpage . '">';

print_barre_liste($langs->trans("List"), $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, $massactionbutton, $nbtotalofrecords, $nbtotalofrecords, '', 0, '', '', $limit, 0, 0, 1);

print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal liste">' . "\n";

// Filter row
print '<tr class="liste_titre_filter">';
if (getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
    print '<td class="liste_titre center maxwidthsearch">' . $form->showFilterButtons('left') . '</td>';
}
print '<td class="liste_titre left"><input class="flat maxwidth100" type="text" name="search_invoice_number" value="' . dol_escape_htmltag($search_invoice_number) . '"></td>';
print '<td class="liste_titre left"><input class="flat maxwidth150" type="text" name="search_seller_name" value="' . dol_escape_htmltag($search_seller_name) . '"></td>';
print '<td class="liste_titre left"><input class="flat maxwidth100" type="text" name="search_seller_nip" value="' . dol_escape_htmltag($search_seller_nip) . '"></td>';
print '<td class="liste_titre center">';
print $form->selectarray('search_import_status', array(
    'NEW' => $langs->trans('KSEF_ImportStatusNEW'),
    'IMPORTED' => $langs->trans('KSEF_ImportStatusIMPORTED'),
    'ERROR' => $langs->trans('KSEF_ImportStatusERROR'),
), $search_import_status, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth100');
print '</td>';
print '<td class="liste_titre center"></td>';
print '<td class="liste_titre right"></td>';
print '<td class="liste_titre center maxwidthsearch">';
if (!getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) print $form->showFilterButtons();
print '</td>';
if (!getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) print '<td class="liste_titre center maxwidthsearch"></td>';
print '</tr>' . "\n";

// Header row
print '<tr class="liste_titre">';
if (getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) print getTitleFieldOfList('', 0, $_SERVER["PHP_SELF"], '', '', '', '', $sortfield, $sortorder, 'center maxwidthsearch ') . "\n";
print_liste_field_titre("Invoice", $_SERVER["PHP_SELF"], "i.invoice_number", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre("KSEF_Seller", $_SERVER["PHP_SELF"], "i.seller_name", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre("NIP", $_SERVER["PHP_SELF"], "i.seller_nip", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre("Status", $_SERVER["PHP_SELF"], "i.import_status", "", $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre("Date", $_SERVER["PHP_SELF"], "i.invoice_date", "", $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre("Amount", $_SERVER["PHP_SELF"], "i.total_gross", "", $param, '', $sortfield, $sortorder, 'right ');
print_liste_field_titre("Actions", $_SERVER["PHP_SELF"], "", "", $param, '', '', '', 'center maxwidthsearch');
if (!getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) print getTitleFieldOfList('', 0, $_SERVER["PHP_SELF"], '', '', '', '', $sortfield, $sortorder, 'center maxwidthsearch ') . "\n";
print '</tr>' . "\n";

$records = $incoming->fetchAll($filters, $sortfield, $sortorder, $limit, $offset);

if (is_array($records) && count($records) > 0) {
    foreach ($records as $obj) {
        print '<tr class="oddeven">';

        if (getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
            print '<td class="nowrap center">';
            if ($massactionbutton || $massaction) print '<input id="cb' . $obj->rowid . '" class="flat checkforselect" type="checkbox" name="toselect[]" value="' . $obj->rowid . '"' . (in_array($obj->rowid, $arrayofselected) ? ' checked="checked"' : '') . '>';
            print '</td>';
        }

        print '<td class="tdoverflowmax200">';
        print '<a href="' . dol_buildpath('/ksef/incoming_card.php', 1) . '?id=' . $obj->rowid . '">';
        print img_object($langs->trans("Show"), 'bill', 'class="pictofixedwidth"');
        print dol_escape_htmltag($obj->invoice_number);
        print '</a>';
        if ($obj->invoice_type == 'KOR') print ' <span class="badge badge-warning">KOR</span>';
        print '</td>';

        print '<td class="tdoverflowmax200">' . dol_escape_htmltag($obj->seller_name) . '</td>';
        print '<td class="nowraponall">' . ksefFormatNIP($obj->seller_nip) . '</td>';
        print '<td class="center">' . ksefGetIncomingStatusBadge($obj->import_status) . '</td>';
        print '<td class="center">' . dol_print_date($obj->invoice_date, 'day') . '</td>';
        print '<td class="right nowraponall">' . price($obj->total_gross) . ' ' . dol_escape_htmltag($obj->currency) . '</td>';

        print '<td class="center nowraponall">';
        if (!empty($obj->fa3_xml)) {
            print '<a class="butAction classfortooltip marginleftonly" href="' . $_SERVER["PHP_SELF"] . '?action=download_xml&id=' . $obj->rowid . '&token=' . newToken() . '" title="' . $langs->trans("KSEF_DownloadXml") . '"><span class="fa fa-file-code"></span></a>';
        }
        if (!empty($obj->ksef_number) && !empty($obj->fa3_xml)) {
            $objEnv = !empty($obj->environment) ? $obj->environment : $environment;
            $verifyUrl = ksefGetVerificationUrlFromXml($obj->ksef_number, $obj->fa3_xml, $objEnv);
            print '<a class="butAction classfortooltip marginleftonly" href="' . $verifyUrl . '" target="_blank" title="' . $langs->trans("KSEF_ViewOnPortal") . '"><span class="fa fa-external-link-alt"></span></a>';
        }
        if (!empty($obj->fa3_xml)) {
            print '<a class="butAction classfortooltip marginleftonly" href="' . $_SERVER["PHP_SELF"] . '?action=download_pdf&id=' . $obj->rowid . '&token=' . newToken() . '" title="' . $langs->trans("KSEF_DownloadPdf") . '"><span class="fa fa-file-pdf"></span></a>';
        }
        print '</td>';

        if (!getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
            print '<td class="nowrap center">';
            if ($massactionbutton || $massaction) print '<input id="cb' . $obj->rowid . '" class="flat checkforselect" type="checkbox" name="toselect[]" value="' . $obj->rowid . '"' . (in_array($obj->rowid, $arrayofselected) ? ' checked="checked"' : '') . '>';
            print '</td>';
        }

        print '</tr>' . "\n";
    }
} else {
    $colspan = 8;
    if (!getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) $colspan++;
    print '<tr><td colspan="' . $colspan . '"><span class="opacitymedium">' . $langs->trans("NoRecordFound") . '</span></td></tr>';
}

print '</table>';
print '</div>';
print '</form>';

llxFooter();
$db->close();