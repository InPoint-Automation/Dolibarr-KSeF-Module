<?php
/* Copyright (C) 2025-2026 InPoint Automation Sp z o.o.
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
 * \file       ksef/ksefindex.php
 * \ingroup    ksef
 * \brief      Home page
 */

if (!defined('CSRFCHECK_WITH_TOKEN')) {
    define('CSRFCHECK_WITH_TOKEN', '1');
}

$action_raw = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
if (in_array($action_raw, array('init_fetch', 'check_fetch_status', 'process_fetch', 'upo_sweep'), true)) {
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

require_once DOL_DOCUMENT_ROOT . '/core/class/html.formfile.class.php';
dol_include_once('/ksef/class/ksef_submission.class.php');
dol_include_once('/ksef/class/ksef_incoming.class.php');
dol_include_once('/ksef/class/ksef_latarnia.class.php');
dol_include_once('/ksef/class/ksef_service.class.php');
dol_include_once('/ksef/class/ksef_sync_state.class.php');
dol_include_once('/ksef/lib/ksef.lib.php');
dol_include_once('/ksef/lib/ksef_tour.lib.php');

$langs->loadLangs(array("ksef@ksef", "bills"));

if (!ksefUserCanReadModule($user)) {
    accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$ksef = new KSEFService($db);
ksefSyncAjax($ksef, $user, $action, 'incoming');

if ($action == 'sync' && ksefSyncSides($user, true)) {
    if (ksefSyncStart($ksef, $user)) {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?polling=1');
        exit;
    }
}
if ($action == 'reset_fetch' && ksefSyncSides($user, true)) {
    if (ksefSyncReset($ksef, $user, '')) {
        setEventMessages($langs->trans('KSEF_FetchReset'), null, 'mesgs');
    } else {
        setEventMessages($ksef->error, null, 'errors');
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

if ($action == 'checklatarnia') {
    if (!verifCond('isModEnabled("ksef")')) {
        accessforbidden();
    }
    $latarnia = new KsefLatarnia($db);
    $result = $latarnia->checkAndCache();
    if ($result !== false) {
        setEventMessages($langs->trans('KSEF_LatarniaCheckSuccess', $langs->transnoentitiesnoconv('KSEF_LATARNIA_' . $result['status'])), null, 'mesgs');
    } else {
        setEventMessages($langs->trans('KSEF_LatarniaCheckError', $latarnia->error), null, 'errors');
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

$max = 5;
$now = dol_now();

$form = new Form($db);
$formfile = new FormFile($db);

$environment = ksefGetEnvironment();
$envSql = "'" . $db->escape($environment) . "'";
$submission = new KsefSubmission($db);
$canOutgoing = $user->hasRight('facture', 'lire');
$stats_outgoing = $canOutgoing ? $submission->getStatistics(30, $environment, $user) : array();
$stats_incoming = array('total' => 0, 'new' => 0, 'imported' => 0, 'skipped' => 0, 'error' => 0, 'fetch_error' => 0);
$sql_inc = "SELECT import_status, COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . "ksef_incoming WHERE entity IN (" . getEntity('invoice') . ") AND environment = " . $envSql . " GROUP BY import_status";
$resql_inc = $db->query($sql_inc);
if ($resql_inc) {
    while ($obj = $db->fetch_object($resql_inc)) {
        $stats_incoming['total'] += $obj->cnt;
        $status_key = strtolower($obj->import_status);
        if (isset($stats_incoming[$status_key])) {
            $stats_incoming[$status_key] = (int)$obj->cnt;
        }
    }
    $db->free($resql_inc);
}

dol_include_once('/ksef/class/ksef_sync_state.class.php');
$syncState = new KsefSyncState($db);
$syncState->load('incoming');

$latest_join = " INNER JOIN " . MAIN_DB_PREFIX . "facture fl ON fl.rowid = s.fk_facture AND s.environment = " . $envSql
    . " AND " . ksefLatestSubmissionSql('s');

$cnt_offline_urgent = $cnt_failed = $cnt_pending = 0;
if ($canOutgoing) {
    $offlineDue = $submission->fetchCronJobs(24, $environment, true);
    $offlineDue = is_array($offlineDue) ? ksefEffectiveDueRows($db, $offlineDue, 24) : $offlineDue;
    if (is_array($offlineDue) && !ksefUserSeesAllCustomers($user)) {
        $offlineDue = array_filter($offlineDue, function ($r) use ($user) {
            return ksefUserCanReadInvoice($user, (int) $r->fk_facture);
        });
    }
    $cnt_offline_urgent = is_array($offlineDue) ? count($offlineDue) : 0;

    $sql_failed = "SELECT COUNT(DISTINCT s.fk_facture) as cnt FROM " . MAIN_DB_PREFIX . "ksef_submissions s";
    $sql_failed .= $latest_join;
    $sql_failed .= " LEFT JOIN " . MAIN_DB_PREFIX . "facture f ON s.fk_facture = f.rowid";
    $sql_failed .= " WHERE 1 = 1" . ksefSqlInvoiceVisibility($user, 'f');
    $sql_failed .= " AND s.status IN ('FAILED', 'REJECTED')";
    $resql = $db->query($sql_failed);
    if ($resql && $obj = $db->fetch_object($resql)) {
        $cnt_failed = (int) $obj->cnt;
    }

    $sql_pending = "SELECT COUNT(DISTINCT s.fk_facture) as cnt FROM " . MAIN_DB_PREFIX . "ksef_submissions s";
    $sql_pending .= $latest_join;
    $sql_pending .= " LEFT JOIN " . MAIN_DB_PREFIX . "facture f ON s.fk_facture = f.rowid";
    $sql_pending .= " WHERE 1 = 1" . ksefSqlInvoiceVisibility($user, 'f');
    // TIMEOUT is pending
    $sql_pending .= " AND s.status IN ('PENDING', 'SUBMITTED', 'TIMEOUT')";
    $resql = $db->query($sql_pending);
    if ($resql && $obj = $db->fetch_object($resql)) {
        $cnt_pending = (int) $obj->cnt;
    }
}

$cnt_incoming_new = (int)($stats_incoming['new'] ?? 0);
$cnt_incoming_error = (int)($stats_incoming['error'] ?? 0);
dol_include_once('/ksef/class/ksef_incoming.class.php');
$incomingCounter = new KsefIncoming($db);
$cnt_incoming_fetch_error = $incomingCounter->countFetchErrors($environment);

$config_ok = !empty(getDolGlobalString('KSEF_COMPANY_NIP')) && ksefAuthReadiness($environment)['ready'];

$cert_warnings = array();
foreach (ksefGetConfigWarnings() as $w) {
    if (in_array($w['code'], array('auth_cert_expired', 'auth_cert_expires_soon', 'auth_cert_unusable', 'offline_cert_expired', 'offline_cert_expires_soon', 'offline_cert_unusable'), true)) {
        $cert_warnings[] = $w;
    }
}

llxHeader("", $langs->trans("KSEF_Area"), ksefHelpUrl('quickstart'), '', 0, 0, '', '', '', 'mod-ksef page-index');

print load_fiche_titre($langs->trans("KSEF_Area"), '', 'ksef@ksef');
print ksefMainCurrencyWarning() . ksefShowDolibarrVersionWarning() . ksefShowReactivationWarning();
$syncStates = ksefSyncStates($ksef, $user);
if ($syncStates) {
    ksefPrintFetchOverlay($syncStates, false);
}

print '<div class="fichecenter">';

print '<div class="opened-dash-board-wrap"><div class="box-flex-container">';

if ($canOutgoing) {
    print '<div class="box-flex-item"><div class="box-flex-item-with-margin">';
    print '<div class="info-box">';
    print '<span class="info-box-icon bg-infobox-facture"><i class="fa fa-file-upload"></i></span>';
    print '<div class="info-box-content">';
    print '<div class="info-box-title" title="' . dol_escape_htmltag($langs->trans("KSEF_OutgoingInvoices")) . '">' . $langs->trans("KSEF_OutgoingInvoices") . '</div>';
    print '<div class="info-box-lines">';
    print '<div class="info-box-line spanoverflow nowrap">';
    print '<a href="' . dol_buildpath('/ksef/status.php', 1) . '" class="info-box-text info-box-text-a">';
    print '<div class="marginrightonly inline-block valignmiddle info-box-line-text" title="' . dol_escape_htmltag($langs->trans("KSEF_Last30Days")) . '">' . $langs->trans("KSEF_Sent") . '</div>';
    print '</a>';
    print '<div class="inline-block nowraponall">';
    print '<span class="classfortooltip badge badge-info" title="' . dol_escape_htmltag($langs->trans("KSEF_TotalSubmissions")) . '">' . $stats_outgoing['total'] . '</span>';
    print '</div></div>';
    print '<div class="info-box-line spanoverflow nowrap">';
    print '<div class="marginrightonly inline-block valignmiddle info-box-line-text">' . $langs->trans("KSEF_Accepted") . '</div>';
    print '<div class="inline-block nowraponall">';
    print '<span class="classfortooltip badge ' . ksefToneBadgeClass('ok') . '" title="' . price($stats_outgoing['success_rate'], 0, $langs, 0, 2, 2) . ' % ' . $langs->trans("KSEF_SuccessRate") . '">' . $stats_outgoing['accepted'] . '</span>';
    print '</div></div>';
    print '</div></div></div>';
    print '</div></div>';
}

print '<div class="box-flex-item"><div class="box-flex-item-with-margin">';
print '<div class="info-box">';
print '<span class="info-box-icon bg-infobox-supplier_proposal"><i class="fa fa-file-download"></i></span>';
print '<div class="info-box-content">';
print '<div class="info-box-title" title="' . dol_escape_htmltag($langs->trans("KSEF_IncomingInvoices")) . '">' . $langs->trans("KSEF_IncomingInvoices") . '</div>';
print '<div class="info-box-lines">';
print '<div class="info-box-line spanoverflow nowrap">';
print '<a href="' . dol_buildpath('/ksef/incoming_list.php', 1) . '" class="info-box-text info-box-text-a">';
print '<div class="marginrightonly inline-block valignmiddle info-box-line-text">' . $langs->trans("Total") . '</div>';
print '</a>';
print '<div class="inline-block nowraponall">';
print '<span class="badge badge-info">' . $stats_incoming['total'] . '</span>';
print '</div></div>';
if ($cnt_incoming_new > 0) {
    print '<div class="info-box-line spanoverflow nowrap">';
    print '<a href="' . dol_buildpath('/ksef/incoming_list.php', 1) . '?search_import_status=NEW" class="info-box-text info-box-text-a">';
    print '<div class="marginrightonly inline-block valignmiddle info-box-line-text">' . $langs->trans("KSEF_ImportStatusNEW") . '</div>';
    print '</a>';
    print '<div class="inline-block nowraponall">';
    print '<span class="badge ' . ksefToneBadgeClass('action') . '">' . $cnt_incoming_new . '</span>';
    print '</div></div>';
}
print '</div></div></div>';
print '</div></div>';

print '<div class="box-flex-item"><div class="box-flex-item-with-margin">';
print '<div class="info-box">';
print '<span class="info-box-icon bg-infobox-project"><i class="fa fa-cog"></i></span>';
print '<div class="info-box-content">';
print '<div class="info-box-title" title="' . dol_escape_htmltag($langs->trans("KSEF_Configuration")) . '">' . $langs->trans("KSEF_Configuration") . '</div>';
print '<div class="info-box-lines">';
print '<div class="info-box-line ksef-attn-line">';
print '<div class="marginrightonly inline-block valignmiddle info-box-line-text">' . $langs->trans("KSEF_ENVIRONMENT") . '</div>';
print '<div class="inline-block nowraponall">' . ksefGetEnvironmentBadge($environment) . '</div>';
print '</div>';
print '<div class="info-box-line ksef-attn-line">';
print '<div class="marginrightonly inline-block valignmiddle info-box-line-text">' . $langs->trans("Status") . '</div>';
print '<div class="inline-block nowraponall">';
if ($config_ok) {
    print '<span class="badge ' . ksefToneBadgeClass('ok') . '">' . $langs->trans("KSEF_Ready") . '</span>';
} else {
    print '<span class="badge ' . ksefToneBadgeClass('problem') . '">' . $langs->trans("KSEF_SetupRequired") . '</span>';
}
print '</div></div>';
print '</div></div></div>';
print '</div></div>';

$latarnia_cached = KsefLatarnia::getCachedStatus();
$latarnia_status = $latarnia_cached['status'];
$latarnia_timestamp = $latarnia_cached['timestamp'];
$latarnia_messages = $latarnia_cached['messages'];

print '<div class="box-flex-item"><div class="box-flex-item-with-margin">';
print '<div class="info-box" id="ksef-latarnia-box">';
print '<span class="info-box-icon bg-infobox-action"><i class="fas fa-signal"></i></span>';
print '<div class="info-box-content">';
print '<div class="info-box-title" title="' . dol_escape_htmltag($langs->trans("KSEF_SystemStatus")) . '">' . $langs->trans("KSEF_SystemStatus") . '</div>';
print '<div class="info-box-lines">';

print '<div class="info-box-line spanoverflow nowrap">';
print '<div class="marginrightonly inline-block valignmiddle info-box-line-text">' . $langs->trans("Status") . '</div>';
print '<div class="inline-block nowraponall">' . ksefGetLatarniaStatusBadge($latarnia_status) . '</div>';
print '</div>';

print '<div class="info-box-line spanoverflow nowrap">';
print '<div class="marginrightonly inline-block valignmiddle info-box-line-text">';
if ($latarnia_timestamp > 0) {
    print dol_print_date($latarnia_timestamp, 'dayhour');
} else {
    print $langs->trans("KSEF_NeverChecked");
}
print '</div>';
print '<div class="inline-block nowraponall">';
print '<a href="' . $_SERVER['PHP_SELF'] . '?action=checklatarnia&token=' . newToken() . '" class="classfortooltip" title="' . dol_escape_htmltag($langs->trans("KSEF_CheckNow")) . '">';
print '<i class="fas fa-sync-alt paddingleft" style="font-size: 0.85em;"></i>';
print '</a>';
print '</div>';
print '</div>';

print '</div></div></div>';
print '</div></div>';

$status_url = dol_buildpath('/ksef/status.php', 1);
$incoming_url = dol_buildpath('/ksef/incoming_list.php', 1);
$attention_lines = array();
$outgoing_badges = '';
if ($cnt_offline_urgent > 0) {
    $outgoing_badges .= ksefAttentionBadge($status_url . '?search_status=OFFLINE_DUE', $cnt_offline_urgent, 'problem', $langs->trans("KSEF_OfflineDeadlineSoon"));
}
if ($cnt_failed > 0) {
    $outgoing_badges .= ksefAttentionBadge($status_url . '?search_status=FAILED_ANY', $cnt_failed, 'problem', $langs->trans("KSEF_FailedCanRetry"));
}
if ($cnt_pending > 0) {
    $outgoing_badges .= ksefAttentionBadge($status_url . '?search_status=IN_PROGRESS', $cnt_pending, 'action', $langs->trans("KSEF_AwaitingConfirmation"));
}
dol_include_once('/ksef/class/ksef_sales.class.php');
$salesTmp = new KsefSales($db);
$reconcileCounts = $canOutgoing ? $salesTmp->getReconcileCounts($environment) : array();
if (!empty($reconcileCounts['gaps'])) {
    $outgoing_badges .= ksefAttentionBadge($status_url . '?search_link=ACTION', (int) $reconcileCounts['gaps'], 'action', $langs->trans("KSEF_AttentionReconcile"));
}
if ($outgoing_badges !== '') {
    $attention_lines[] = array('label' => $langs->trans("KSEF_AttentionOutgoing"), 'badges' => $outgoing_badges);
}
$incoming_badges = '';
if ($cnt_incoming_new > 0) {
    $incoming_badges .= ksefAttentionBadge($incoming_url . '?search_import_status=NEW', $cnt_incoming_new, 'action', $langs->trans("KSEF_NewToReview"));
}
if ($cnt_incoming_error > 0) {
    $incoming_badges .= ksefAttentionBadge($incoming_url . '?search_import_status=ERROR', $cnt_incoming_error, 'problem', $langs->trans("KSEF_ImportErrors"));
}
if ($cnt_incoming_fetch_error > 0) {
    $incoming_badges .= ksefAttentionBadge($incoming_url . '?search_import_status=FETCH_ERROR', $cnt_incoming_fetch_error, 'problem', $langs->trans("KSEF_FetchErrors"));
}
if ($incoming_badges !== '') {
    $attention_lines[] = array('label' => $langs->trans("KSEF_AttentionIncoming"), 'badges' => $incoming_badges);
}

print '<div class="box-flex-item"><div class="box-flex-item-with-margin">';
print '<div class="info-box">';
print '<span class="info-box-icon bg-infobox-contrat"><i class="fas fa-exclamation-circle"></i></span>';
print '<div class="info-box-content">';
print '<div class="info-box-title" title="' . dol_escape_htmltag($langs->trans("KSEF_NeedsAttention")) . '">' . $langs->trans("KSEF_NeedsAttention") . '</div>';
print '<div class="info-box-lines">';
if (!empty($attention_lines)) {
    foreach ($attention_lines as $line) {
        print '<div class="info-box-line ksef-attn-line">';
        print '<div class="marginrightonly inline-block valignmiddle info-box-line-text">' . $line['label'] . '</div>';
        print '<div class="inline-block ksef-attn-badges">' . $line['badges'] . '</div>';
        print '</div>';
    }
} else {
    print '<div class="info-box-line"><span class="badge ' . ksefToneBadgeClass('ok') . '">' . $langs->trans("KSEF_NothingToReport") . '</span></div>';
}
print '</div></div></div>';
print '</div></div>';

print '<div class="box-flex-item filler"></div>';
print '<div class="box-flex-item filler"></div>';

print '</div></div>';

if ($cert_warnings) {
    print ksefRenderConfigWarnings($cert_warnings, !empty($user->admin) ? 'dashboard' : 'auth');
}

print ksefOtherEnvRowsHint($db, $environment, array('submissions', 'incoming'));

dol_include_once('/ksef/lib/ksef_invoice_notes.lib.php');
$notedMore = false;
$noted = ksefInvoiceNotesIndex($db, $user, $notedMore);
if ($noted) {
    $shown = array_slice($noted, 0, 10);
    print '<div class="div-table-responsive-no-min ksef-notes-box" style="margin-top: 10px;">';
    print '<table class="noborder centpercent"><tr class="liste_titre"><th colspan="2"><span class="fas fa-exclamation-triangle paddingright opacitymedium"></span>'
        . $langs->trans($notedMore ? 'KSEF_NoteIndexTitleMore' : 'KSEF_NoteIndexTitle', count($noted)) . '</th></tr>';
    foreach ($shown as $n) {
        print '<tr class="oddeven"><td class="nowraponall"><a href="' . dol_escape_htmltag($n['url']) . '">' . dol_escape_htmltag($n['ref']) . '</a></td>'
            . '<td>' . dol_escape_htmltag($n['title']) . '</td></tr>';
    }
    if (count($noted) > count($shown)) {
        print '<tr class="oddeven"><td colspan="2" class="opacitymedium">' . $langs->trans('KSEF_NoteIndexMore', count($shown), count($noted)) . '</td></tr>';
    }
    print '</table></div>';
}

print '<div class="clearboth"></div>';
print '<div class="fichecenterbis">';
print '<div class="twocolumns">';

if ($canOutgoing) {
    print '<div class="firstcolumn fichehalfleft boxhalfleft" id="boxhalfleft">';

    print '<div class="box divboxtable">';
    print '<table class="noborder boxtable centpercent">';
    print '<tr class="liste_titre box_titre"><th colspan="2">';
    print '<div class="tdoverflowmax400 maxwidth250onsmartphone float">';
    print '<span class="fa fa-file-upload paddingright opacitymedium"></span>' . $langs->trans("KSEF_OutgoingStatistics");
    print '</div></th></tr>';

    print ksefStatRowsHtml('outgoing', ksefOutgoingStatCounts($db, $user, $environment));

    print '<tr class="liste_total"><td class="liste_total">' . $langs->trans("KSEF_SuccessRate") . ' <span class="opacitymedium small">(' . dol_strtolower($langs->trans("KSEF_Last30Days")) . ')</span></td>';
    print '<td class="liste_total right nowraponall"><strong>' . price($stats_outgoing['success_rate'], 0, $langs, 0, 2, 2) . ' %</strong></td></tr>';

    print '</table></div>';

    print '<div class="box divboxtable">';
    print '<table class="noborder boxtable centpercent">';
    print '<tr class="liste_titre box_titre"><th colspan="4">';
    print '<div class="tdoverflowmax400 maxwidth250onsmartphone float">';
    print $langs->trans("KSEF_RecentSubmissions");
    print '<a class="paddingleft" href="' . dol_buildpath('/ksef/status.php', 1) . '"><span class="badge">...</span></a>';
    print '</div><div class="right">';
    print dolGetButtonTitle($langs->trans('NewBill'), '', 'fa fa-plus-circle', DOL_URL_ROOT . '/compta/facture/card.php?action=create', '', $user->hasRight('facture', 'creer') ? 1 : 0);
    print '</div></th></tr>';

    $sql = "SELECT s.rowid, s.fk_facture, s.status, s.date_submission, s.date_last_check, s.offline_mode, s.ksef_number, s.ksef_reference, s.environment, s.error_details,";
    $sql .= " f.ref as invoice_ref, soc.nom as company_name, soc.rowid as socid,";
    $sql .= " (SELECT COUNT(*) FROM " . MAIN_DB_PREFIX . "ksef_submissions s2 WHERE s2.fk_facture = s.fk_facture AND s2.environment = s.environment"
        . " AND (s2.source IS NULL OR s2.source <> '" . KsefSubmission::SOURCE_CARRIED_FORWARD . "')) as total_attempts";
    $sql .= " FROM " . MAIN_DB_PREFIX . "ksef_submissions as s";
    $sql .= $latest_join;
    $sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "facture as f ON s.fk_facture = f.rowid";
    $sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "societe as soc ON f.fk_soc = soc.rowid";
    $sql .= " WHERE s.environment = " . $envSql . ksefSqlInvoiceVisibility($user, 'f');
    $sql .= " ORDER BY s.date_submission DESC, s.rowid DESC LIMIT " . $max;

    $resql = $db->query($sql);
    if ($resql) {
        $num = $db->num_rows($resql);
        if ($num > 0) {
            while ($obj = $db->fetch_object($resql)) {
                print '<tr class="oddeven">';
                print '<td class="nowraponall tdoverflowmax150">';
                print '<a href="' . DOL_URL_ROOT . '/compta/facture/card.php?facid=' . $obj->fk_facture . '">';
                print '<span class="fas fa-file-invoice-dollar infobox-commande pictofixedwidth"></span>';
                print dol_escape_htmltag($obj->invoice_ref) . '</a>';
                if ($obj->total_attempts > 1) {
                    print ' <a href="' . dol_buildpath('/ksef/tab_ksef.php', 1) . '?id=' . $obj->fk_facture . '" class="classfortooltip" title="' . $langs->trans("KSEF_ViewAllAttemptsOnInvoice") . '">';
                    print '<span class="badge badge-secondary">' . $obj->total_attempts . '</span></a>';
                }
                print '</td>';
                print '<td class="tdoverflowmax150">';
                if ($obj->socid > 0) {
                    print '<a href="' . DOL_URL_ROOT . '/societe/card.php?socid=' . $obj->socid . '">' . dol_escape_htmltag($obj->company_name) . '</a>';
                }
                print '</td>';
                print '<td class="center nowraponall">' . dol_print_date($obj->date_submission, 'day') . '</td>';
                print '<td class="right" width="18">';
                $dotStatus = ksefDisplayStatus($obj);
                print ksefStatusDotHtml($dotStatus, $langs->transnoentitiesnoconv('KSEF_STATUS_' . $dotStatus));
                print '</td>';
                print '</tr>';
            }
        } else {
            print '<tr class="oddeven"><td colspan="4" class="center">';
            print '<span class="opacitymedium">' . $langs->trans("KSEF_NoRecentSubmissions") . '</span>';
            print '</td></tr>';
        }
        $db->free($resql);
    } else {
        dol_print_error($db);
    }

    print '</table></div>';

    print '<div class="center" style="margin-top: 10px;">';
    print '<a class="butAction" href="' . dol_buildpath('/ksef/status.php', 1) . '">' . $langs->trans("KSEF_ViewAll") . '</a>';
    print '</div>';

    print '</div>';
}

print '<div class="secondcolumn fichehalfright boxhalfright" id="boxhalfright">';

print '<div class="box divboxtable">';
print '<table class="noborder boxtable centpercent">';
print '<tr class="liste_titre box_titre"><th colspan="2">';
print '<div class="tdoverflowmax400 maxwidth250onsmartphone float">';
print '<span class="fa fa-file-download paddingright opacitymedium"></span>' . $langs->trans("KSEF_IncomingStatistics");
print '</div></th></tr>';

print ksefStatRowsHtml('incoming', $incomingCounter->countForStats($environment));

$ksefStartDate = strtotime('2026-02-01');
$ksefPreStartDate = strtotime('2026-01-31');
$todayTs = dol_now();
$hwmTimestamp = !empty($syncState->hwm_date) ? $syncState->getContinuationDate() : null;
if ($hwmTimestamp && $hwmTimestamp < $ksefPreStartDate) $hwmTimestamp = $ksefPreStartDate;
if ($hwmTimestamp && $hwmTimestamp > $todayTs) $hwmTimestamp = $todayTs;
$totalSpan = max($todayTs - $ksefStartDate, 1);
$hwmPercent = $hwmTimestamp ? round((($hwmTimestamp - $ksefStartDate) / $totalSpan) * 100, 1) : 0;
if ($hwmPercent < 0) $hwmPercent = 0;
if ($hwmPercent > 100) $hwmPercent = 100;

if ($hwmPercent >= 95) {
    $barColor = '#4CAF50';
    $barStatus = $langs->trans("KSEF_SyncProgressUpToDate");
} elseif ($hwmPercent >= 50) {
    $barColor = '#FF9800';
    $barStatus = $langs->trans("KSEF_SyncProgressPartial");
} elseif ($hwmTimestamp) {
    $barColor = '#f44336';
    $barStatus = $langs->trans("KSEF_SyncProgressBehind");
} else {
    $barColor = 'var(--ksef-text-faint)';
    $barStatus = $langs->trans("KSEF_SyncNotStarted");
}

print '<tr class="oddeven"><td>' . $langs->trans("KSEF_SyncProgress") . '</td>';
print '<td class="right ksef-dash-sync-progress">';
print '<div style="display: inline-block; width: 120px; height: 14px; background: var(--ksef-surface-border); border-radius: 3px; vertical-align: middle; margin-right: 6px;">';
if ($hwmPercent > 0) {
    print '<div style="height: 100%; width: ' . $hwmPercent . '%; background: ' . $barColor . '; border-radius: 3px;"></div>';
}
print '</div>';
print '<span style="color: ' . $barColor . '; font-weight: 600; font-size: 0.85em;">' . $barStatus . '</span>';
if ($hwmTimestamp && $hwmPercent < 100) {
    print ' <span class="opacitymedium small">(' . $hwmPercent . '%)</span>';
}
print '</td></tr>';

print '</table></div>';

if ($syncStates) {
    $syncLinks = array(
        'incoming' => array('url' => $incoming_url, 'label' => 'KSEF_IncomingInvoices'),
        'outgoing' => array('url' => $status_url, 'label' => 'KSEF_OutgoingInvoices'),
    );
    $syncTodo = array(
        'incoming' => $cnt_incoming_new > 0 ? '<a class="ksef-dash-sync-todo" href="' . $incoming_url . '?search_import_status=NEW">' . $langs->trans('KSEF_DashSyncToImport', $cnt_incoming_new) . '</a>' : '',
        'outgoing' => '',
    );
    $importable = (int) ($reconcileCounts[KsefSales::LINK_IMPORTABLE] ?? 0);
    if ($importable > 0) {
        $syncTodo['outgoing'] .= '<a class="ksef-dash-sync-todo" href="' . $status_url . '?search_link=' . KsefSales::LINK_IMPORTABLE . '">' . $langs->trans('KSEF_DashSyncMissing', $importable) . '</a>';
    }
    $otherGaps = (int) ($reconcileCounts['gaps'] ?? 0) - $importable;
    if ($otherGaps > 0) {
        $syncTodo['outgoing'] .= ($syncTodo['outgoing'] !== '' ? ' · ' : '') . '<a class="ksef-dash-sync-todo" href="' . $status_url . '?search_link=' . ($importable > 0 ? 'ACTION_OTHER' : 'ACTION') . '">'
            . $langs->trans($importable > 0 ? 'KSEF_DashSyncToCheckOther' : 'KSEF_DashSyncToCheck', $otherGaps) . '</a>';
    }
    $stateBadge = array('running' => array('info', 'KSEF_DashSyncRunning'), 'ok' => array('ok', 'KSEF_DashSyncOk'), 'error' => array('problem', 'KSEF_DashSyncError'));

    print '<div class="box divboxtable ksef-dash-sync">';
    print '<table class="noborder boxtable centpercent">';
    print '<tr class="liste_titre box_titre"><th colspan="2">';
    print '<div class="tdoverflowmax400 maxwidth250onsmartphone float">';
    print '<span class="fa fa-sync paddingright opacitymedium"></span>' . $langs->trans("KSEF_DashSync");
    print '</div></th></tr>';
    foreach ($syncStates as $dir => $st) {
        $res = ksefSyncLastResult($st);
        print '<tr class="oddeven ksef-dash-sync-row" data-dir="' . $dir . '" data-state="' . $res['state'] . '">';
        print '<td class="tdtop"><a href="' . $syncLinks[$dir]['url'] . '">' . $langs->trans($syncLinks[$dir]['label']) . '</a></td>';
        print '<td class="right">';
        if (isset($stateBadge[$res['state']])) {
            print '<span class="badge ' . ksefToneBadgeClass($stateBadge[$res['state']][0]) . ' ksef-dash-sync-state">' . $langs->trans($stateBadge[$res['state']][1]) . '</span> ';
        }
        print '<span class="ksef-dash-sync-when">' . ($st->last_sync ? dol_print_date($st->last_sync, 'dayhour') : '<span class="opacitymedium">' . $langs->trans('KSEF_DashSyncNever') . '</span>') . '</span>';
        if ($res['reason'] !== '') {
            print '<br><span class="small ksef-dash-sync-reason ' . ksefToneTextClass('problem') . '">' . dol_escape_htmltag($res['reason']) . '</span>';
        }
        if ($st->last_sync) {
            print '<br><span class="small opacitymedium ksef-dash-sync-new">' . $langs->trans('KSEF_DashSyncFetched', max((int) $st->last_sync_total, (int) $st->last_sync_new), (int) $st->last_sync_new) . '</span>';
        }
        if ($syncTodo[$dir] !== '') {
            print '<br><span class="small">' . $syncTodo[$dir] . '</span>';
        }
        print '</td></tr>';
    }
    if (ksefSyncSides($user, true)) {
        print '<tr class="nohover"><td colspan="2" class="center">';
        ksefPrintSyncButton($syncStates, $_SERVER['PHP_SELF']);
        print '</td></tr>';
    }
    print '</table></div>';
}

print '<div class="box divboxtable">';
print '<table class="noborder boxtable centpercent">';
print '<tr class="liste_titre box_titre"><th colspan="4">';
print '<div class="tdoverflowmax400 maxwidth250onsmartphone float">';
print $langs->trans("KSEF_RecentIncoming");
print '<a class="paddingleft" href="' . dol_buildpath('/ksef/incoming_list.php', 1) . '"><span class="badge">...</span></a>';
print '</div></th></tr>';

$sql_incoming = "SELECT rowid, ksef_number, seller_name, seller_nip, invoice_number, invoice_date, total_gross, currency, import_status";
$sql_incoming .= " FROM " . MAIN_DB_PREFIX . "ksef_incoming";
$sql_incoming .= " WHERE entity IN (" . getEntity('invoice') . ") AND environment = " . $envSql;
$sql_incoming .= " ORDER BY fetch_date DESC LIMIT " . $max;

$resql = $db->query($sql_incoming);
if ($resql) {
    $num = $db->num_rows($resql);
    if ($num > 0) {
        while ($obj = $db->fetch_object($resql)) {
            print '<tr class="oddeven">';
            print '<td class="nowraponall tdoverflowmax150">';
            print '<a href="' . dol_buildpath('/ksef/incoming_card.php', 1) . '?id=' . $obj->rowid . '">';
            print '<span class="fas fa-file-invoice-dollar infobox-order_supplier pictofixedwidth"></span>';
            print dol_escape_htmltag($obj->invoice_number) . '</a>';
            print '</td>';
            print '<td class="tdoverflowmax150">' . dol_escape_htmltag($obj->seller_name) . '</td>';
            print '<td class="center nowraponall">' . dol_print_date($obj->invoice_date, 'day') . '</td>';
            print '<td class="right" width="18">';
            print ksefStatusDotHtml($obj->import_status, $langs->transnoentitiesnoconv('KSEF_ImportStatus' . $obj->import_status), true);
            print '</td>';
            print '</tr>';
        }
    } else {
        print '<tr class="oddeven"><td colspan="4" class="center">';
        print '<span class="opacitymedium">' . $langs->trans("KSEF_NoIncomingInvoices") . '</span>';
        print '</td></tr>';
    }
    $db->free($resql);
} else {
    print '<tr class="oddeven"><td colspan="4" class="center">';
    print '<span class="opacitymedium">' . $langs->trans("KSEF_NoIncomingInvoices") . '</span>';
    print '</td></tr>';
}

print '</table></div>';

print '<div class="center" style="margin-top: 10px;">';
print '<a class="butAction" href="' . dol_buildpath('/ksef/incoming_list.php', 1) . '">' . $langs->trans("KSEF_ViewAll") . '</a>';
print '</div>';
print '</div>';
print '</div>';
print '</div>';
print '</div>';

if (!empty($latarnia_messages)) {
    print '<div class="clearboth"></div>';
    print '<div class="div-table-responsive-no-min" style="margin-top: 10px;">';
    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre">';
    print '<th>' . $langs->trans("KSEF_SystemStatus") . ' - ' . $langs->trans("KSEF_LatarniaMessages") . '</th>';
    print '<th>' . $langs->trans("Type") . '</th>';
    print '<th class="center">' . $langs->trans("KSEF_Start") . '</th>';
    print '<th class="center">' . $langs->trans("KSEF_End") . '</th>';
    print '<th class="center">' . $langs->trans("KSEF_Published") . '</th>';
    print '</tr>';
    foreach ($latarnia_messages as $lmsg) {
        print '<tr class="oddeven">';
        print '<td style="white-space: normal;">' . dol_escape_htmltag($lmsg['title'] ?? '') . '<br><small class="opacitymedium">' . dol_escape_htmltag($lmsg['text'] ?? '') . '</small></td>';
        $cat = $lmsg['category'] ?? '';
        $category_badge = ksefGetLatarniaStatusBadge($cat);
        $type_raw = $lmsg['type'] ?? '';
        $type_label = $langs->trans('KSEF_LATARNIA_TYPE_' . $type_raw);
        if ($type_label === 'KSEF_LATARNIA_TYPE_' . $type_raw) {
            $type_label = $type_raw;
        }
        print '<td class="nowraponall">' . $category_badge . '<br><small class="opacitymedium">' . dol_escape_htmltag($type_label) . '</small></td>';
        print '<td class="center">' . (!empty($lmsg['start']) ? dol_print_date(strtotime($lmsg['start']), 'dayhour') : '') . '</td>';
        print '<td class="center">' . (!empty($lmsg['end']) ? dol_print_date(strtotime($lmsg['end']), 'dayhour') : '<span class="opacitymedium">-</span>') . '</td>';
        print '<td class="center">' . (!empty($lmsg['published']) ? dol_print_date(strtotime($lmsg['published']), 'dayhour') : '') . '</td>';
        print '</tr>';
    }
    print '</table>';
    print '</div>';
}

print '<div class="clearboth"></div>';
print ksefHelpBoxHtml($db, $user);

if (!$config_ok) {
    print '<div class="clearboth"></div><br>';
    print '<div class="info">';
    print '<strong>' . $langs->trans("KSEF_SetupRequired") . '</strong><br>';
    print $langs->trans("KSEF_SetupRequiredDesc");
    print '<br><br><a class="butAction" href="' . dol_buildpath('/ksef/admin/setup.php', 1) . '">' . $langs->trans("KSEF_ConfigureNow") . '</a>';
    print '</div>';
}

print ksefHelpFooter($db, $user);
llxFooter();
$db->close();