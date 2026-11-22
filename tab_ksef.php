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
 * \file    ksef/tab_ksef.php
 * \ingroup ksef
 * \brief   Invoice KSeF tab
 */

if (!defined('CSRFCHECK_WITH_TOKEN')) {
    define('CSRFCHECK_WITH_TOKEN', '1');
}

$action_raw = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
if (in_array($action_raw, array('download_xml', 'download_upo'))) {
    if (!defined('NOTOKENRENEWAL')) {
        define('NOTOKENRENEWAL', '1');
    }
}

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Main.inc.php not found");

require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/invoice.lib.php';
dol_include_once('/ksef/class/ksef_submission.class.php');
dol_include_once('/ksef/class/ksef_client.class.php');
dol_include_once('/ksef/lib/ksef.lib.php');
dol_include_once('/ksef/lib/ksef_tour.lib.php');

$id = GETPOST('id', 'int');
$action = GETPOST('action', 'aZ09');

if (!$user->hasRight('ksef', 'read') || !$user->hasRight('facture', 'lire')) accessforbidden();

$object = new Facture($db);
if ($object->fetch($id) <= 0) accessforbidden();
restrictedArea($user, 'facture', $object->id, '', '', 'fk_soc', 'rowid');
$object->fetch_optionals();

$langs->load("bills");
$langs->load("ksef@ksef");

if ($action == 'download_xml' && GETPOST('sub_id', 'int')) {
    $submission = ksefFetchSubmissionForUser($db, $user, GETPOST('sub_id', 'int'), $object->id);
    if ($submission && !empty($submission->fa3_xml)) {
        ksefSendDownload($submission->fa3_xml, ksefDownloadName('FA3', $submission->ksef_number, 'invoice_' . $submission->fk_facture, 'xml'), 'application/xml');
        exit;
    }
    setEventMessages($langs->trans("KSEF_XMLNotAvailable"), null, 'warnings');
}

if ($action == 'download_upo' && GETPOST('sub_id', 'int')) {
    $submission = ksefFetchSubmissionForUser($db, $user, GETPOST('sub_id', 'int'), $object->id);
    $upoError = '';
    if ($submission) {
        dol_include_once('/ksef/class/ksef_service.class.php');
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

$title = $langs->trans('Invoice') . " - " . $object->ref . " - " . $langs->trans("KSEF_Tab");
llxHeader('', $title);

$head = facture_prepare_head($object);
print dol_get_fiche_head($head, 'ksef', $langs->trans("Invoice"), -1, 'bill');
print ksefShowDolibarrVersionWarning() . ksefShowReactivationWarning();

$linkback = '<a href="' . DOL_URL_ROOT . '/compta/facture/list.php?restore_lastsearch_values=1">' . $langs->trans("BackToList") . '</a>';
$morehtmlref = '<div class="refidno">' . $langs->trans("Ref") . ' : ' . $object->ref . '</div>';

dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', $morehtmlref);

print '<div class="fichecenter">';
print '<div class="underbanner clearboth"></div>';

$depositHtml = ksefDepositSummaryHtml($db, $object);
if ($depositHtml !== '') {
    print '<table class="border centpercent tableforfield ksef-deposit-summary"><tr><td class="titlefield">' . $langs->trans('KSEF_KsefInvoiceKind') . '</td><td>' . $depositHtml . '</td></tr></table><br>';
}

$citation = ksefCorrectionCitation($db, $object);
if ($citation !== null) {
    $srcLink = '<a href="' . DOL_URL_ROOT . '/compta/facture/card.php?facid=' . $citation['source_id'] . '">' . dol_escape_htmltag($citation['source_ref']) . '</a>';
    if ($citation['sent']) {
        $num = $citation['cited'] !== '' ? '<span class="badge ' . ksefToneBadgeClass('ok') . ' badge-status">' . dol_escape_htmltag($citation['cited']) . '</span>' : '<span class="opacitymedium">' . $langs->trans('KSEF_CorrectionCitedNoNumber') . '</span>';
    } elseif (!empty($citation['awaiting'])) {
        $awaitKey = ($citation['awaiting_kind'] ?? '') === 'online' ? 'KSEF_CorrectionWillCiteAwaitingAcceptance' : 'KSEF_CorrectionWillCiteAwaiting';
        $num = '<span class="badge ' . ksefToneBadgeClass('offline') . ' badge-status ksef-correction-awaiting">' . $langs->trans($awaitKey) . '</span>';
    } else {
        $num = $citation['will_cite'] !== '' ? '<span class="badge ' . ksefToneBadgeClass('action') . ' badge-status">' . dol_escape_htmltag($citation['will_cite']) . '</span> <span class="opacitymedium">(' . $langs->trans('KSEF_CorrectionWillCite') . ')</span>' : '<span class="opacitymedium">' . $langs->trans('KSEF_CorrectionWillCiteNoNumber') . '</span>';
    }
    print '<table class="border centpercent tableforfield ksef-correction-source"><tr><td class="titlefield">' . $langs->trans('KSEF_CorrectsKsefNumber') . '</td><td>' . $srcLink . ' &middot; ' . $num;
    if ($citation['mismatch']) {
        print '<br><span class="warning">' . ksefTransHtml('KSEF_CorrectionCitedMismatch', array(ksefEscapeText($citation['will_cite']))) . '</span>';
    }
    print '</td></tr></table><br>';
}
$corrections = ksefInvoiceCorrections($db, $object->id);
if (!empty($corrections)) {
    print '<table class="noborder centpercent ksef-corrections"><tr class="liste_titre"><th>' . $langs->trans('KSEF_Corrections') . '</th><th>' . $langs->trans('Status') . '</th><th>' . $langs->trans('KSEF_KSEFNumber') . '</th></tr>';
    foreach ($corrections as $corr) {
        print '<tr class="oddeven"><td><a href="' . dol_buildpath('/ksef/tab_ksef.php', 1) . '?id=' . $corr['id'] . '">' . dol_escape_htmltag($corr['ref']) . '</a></td>';
        print '<td>' . ($corr['status'] !== '' ? ksefGetStatusBadge($corr['status'], true) : '<span class="opacitymedium">' . $langs->trans('KSEF_NotSent') . '</span>') . '</td>';
        print '<td>' . ($corr['ksef_number'] !== '' ? dol_escape_htmltag($corr['ksef_number']) : '-') . '</td></tr>';
    }
    print '</table><br>';
}

print '<table class="centpercent notopnoleftnoright table-fiche-title">';
print '<tbody><tr class="toptitle">';
print '<td class="nobordernopadding valignmiddle col-title">';
print '<div class="titre inline-block">' . $langs->trans("KSEF_SubmissionHistory") . '</div>';
print '</td></tr></tbody></table>';

$sql = "SELECT rowid, ksef_reference, ksef_number, ksef_session_reference, api_response, source, status, environment, date_submission, date_acceptance, error_code, error_message, error_details, retry_count, fa3_xml, upo_xml, offline_mode";
$sql .= " FROM " . MAIN_DB_PREFIX . "ksef_submissions";
$sql .= " WHERE fk_facture = " . (int)$object->id;
$sql .= " ORDER BY date_submission DESC, rowid DESC";

$resql = $db->query($sql);
if ($resql) {
    $num = $db->num_rows($resql);

    print '<div class="div-table-responsive">';
    print '<table class="tagtable liste ksef-history">';
    print '<colgroup><col><col><col><col style="width: 22%"><col><col style="width: 30%"><col></colgroup>';
    print '<tr class="liste_titre">';
    print '<th>' . $langs->trans("Date") . '</th>';
    print '<th>' . $langs->trans("Status") . '</th>';
    print '<th>' . $langs->trans("KSEF_ENVIRONMENT") . '</th>';
    print '<th>' . $langs->trans("KSEF_Number") . '</th>';
    print '<th class="center">#</th>';
    print '<th>' . $langs->trans("Error") . '</th>';
    print '<th class="center">' . $langs->trans("Action") . '</th>';
    print '</tr>';

    if ($num > 0) {
        $rowActs = array();
        ob_start();

        while ($obj = $db->fetch_object($resql)) {
            print '<tr class="oddeven">';

            print '<td class="nowraponall"' . (!empty($obj->offline_mode) ? ' title="' . dol_escape_htmltag($langs->trans('KSEF_IssuedOfflineOn')) . '"' : '') . '>' . dol_print_date($obj->date_submission, 'dayhour') . '</td>';
            print '<td class="nowraponall">' . ksefGetStatusBadge(ksefDisplayStatus($obj), true) . '</td>';
            print '<td class="nowraponall">' . ksefGetEnvironmentBadge($obj->environment) . '</td>';
            print '<td>' . ($obj->ksef_number ? ksefEscapeText($obj->ksef_number) : '-') . '</td>';
            $carried = ((string) $obj->source === KsefSubmission::SOURCE_CARRIED_FORWARD);
            print '<td class="center">' . ($carried ? '-' : (($obj->retry_count ?? 0) + 1)) . '</td>';

            print '<td>';
            if ($carried) {
                $cf = json_decode((string) $obj->api_response, true);
                $cfDate = is_array($cf) ? (int) ($cf['carried_from_date'] ?? 0) : 0;
                print '<span class="badge ' . ksefToneBadgeClass('info') . ' ksef-carried-copy">' . $langs->trans('KSEF_CarriedForwardLabel') . '</span>'
                    . '<br><small class="opacitymedium">' . ($cfDate > 0 ? $langs->trans('KSEF_CarriedForwardNote', dol_print_date($cfDate, 'dayhour')) : $langs->trans('KSEF_CarriedForwardNoteNoDate')) . '</small>';
            }
            $errorDetails = !empty($obj->error_details) ? json_decode($obj->error_details, true) : null;
            $errorDetails = is_array($errorDetails) ? $errorDetails : array();
            $fullError = '';
            if ($obj->error_code) {
                $codeDescription = ksefKsefCodeLabel($obj->error_code);
                print '<span class="fa fa-exclamation-triangle paddingright ksef-error-icon"></span><strong>' . ksefEscapeText($obj->error_code) . '</strong>: ' . ksefEscapeText(dol_trunc($codeDescription, 60));
                $fullError = $obj->error_code . ': ' . $codeDescription;
            } elseif ($obj->error_message) {
                print ksefEscapeText(dol_trunc($obj->error_message, 80));
            }
            if (!empty($obj->error_message)) {
                $fullError .= ($fullError !== '' ? "\n" : '') . $obj->error_message;
            }
            $dupCode = (int) ($errorDetails['code'] ?? ($errorDetails['raw_response']['status']['code'] ?? 0));
            if ($dupCode === 440) {
                $originalKsef = $errorDetails['original_ksef_number'] ?? ($errorDetails['raw_response']['status']['extensions']['originalKsefNumber'] ?? null);
                print ' <span class="badge badge-warning ' . ksefToneClass('problem') . '">' . $langs->trans("KSEF_DuplicateInvoiceNumber") . '</span>';
                if ($originalKsef) {
                    $fullError .= "\n" . $langs->transnoentitiesnoconv("KSEF_InvoiceNumberUsedBy") . ': ' . $originalKsef;
                    print '<br><small class="opacitymedium">' . $langs->trans("KSEF_InvoiceNumberUsedBy") . ': <strong>' . ksefEscapeText($originalKsef) . '</strong></small>';
                }
            } else {
                $desc = $errorDetails['description'] ?? ($errorDetails['raw_response']['status']['description'] ?? '');
                if ($desc !== '' && strpos((string) $obj->error_message, (string) $desc) === false) {
                    $fullError .= "\n" . $desc;
                }
                if (!empty($errorDetails['details']) && is_array($errorDetails['details'])) {
                    $more = array_filter(array_map('strval', $errorDetails['details']), function ($d) use ($obj) {
                        return $d !== '' && strpos((string) $obj->error_message, $d) === false;
                    });
                    if ($more) {
                        $fullError .= "\n" . implode("\n", $more);
                    }
                }
            }
            if ($obj->error_code && ksefRetryHintText($obj->error_code) !== '') {
                $fullError .= "\n" . ksefRetryHintText($obj->error_code);
            }
            if (trim($fullError) !== '') {
                print '<details class="small"><summary class="opacitymedium">' . $langs->trans("KSEF_ShowFullError") . '</summary>' . ksefEscapeText(trim($fullError), true) . '</details>';
            }
            print '</td>';

            print '<td class="center nowraponall">';
            $act = array();
            if (!empty($obj->fa3_xml)) {
                $act['xml'] = ksefActIcon('xml', $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=download_xml&sub_id=' . $obj->rowid . '&token=' . newToken(), $langs->trans("KSEF_DownloadXml"), 'fa-file-code');
            }
            if ($obj->status == 'ACCEPTED' && !empty($obj->ksef_number)) {
                $rowSub = new KsefSubmission($db);
                $rowSub->ksef_session_reference = $obj->ksef_session_reference;
                $rowSub->api_response = $obj->api_response;
                $rowSub->ksef_reference = $obj->ksef_reference;
                $rowSub->upo_xml = $obj->upo_xml;
                $act['upo'] = ksefActIcon('upo', $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=download_upo&sub_id=' . $obj->rowid . '&token=' . newToken(), dol_escape_htmltag(ksefUpoDownloadTitle($rowSub)), 'fa-certificate');
            }
            print ksefRowActionsHold($rowActs, $act);
            print '</td>';
            print '</tr>';
        }
        print ksefRowActionsFill(ob_get_clean(), array('xml', 'upo'), $rowActs);
    } else {
        print '<tr><td colspan="7" class="opacitymedium">' . $langs->trans("NoRecordFound") . '</td></tr>';
    }
    print '</table></div>';
    $db->free($resql);
}

print '</div>';
print dol_get_fiche_end();

print ksefHelpFooter($db, $user);
llxFooter();
$db->close();