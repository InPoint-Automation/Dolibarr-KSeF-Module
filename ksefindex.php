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
 * \file       ksef/ksefindex.php
 * \ingroup    ksef
 * \brief      Home page
 */

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Include of main fails");

global $conf, $langs, $user, $db;

require_once DOL_DOCUMENT_ROOT . '/core/class/html.formfile.class.php';
dol_include_once('/ksef/class/ksef_submission.class.php');
dol_include_once('/ksef/lib/ksef.lib.php');

$langs->loadLangs(array("ksef@ksef"));

$action = GETPOST('action', 'aZ09');
$socid = GETPOST('socid', 'int');
if (isset($user->socid) && $user->socid > 0) {
    $action = '';
    $socid = $user->socid;
}

$max = 10;
$now = dol_now();

$form = new Form($db);
$formfile = new FormFile($db);

llxHeader("", $langs->trans("KSEF_Area"), '', '', 0, 0, '', '', '', 'mod-ksef page-index');

print load_fiche_titre($langs->trans("KSEF_Area"), '', 'ksef@ksef');

print '<div class="fichecenter">';

print '<div class="fichehalfleft">';

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder nohover centpercent">';
print '<tr class="liste_titre"><th colspan="2">' . $langs->trans("KSEF_Configuration") . '</th></tr>';

print '<tr class="oddeven"><td>' . $langs->trans("KSEF_COMPANY_NIP") . '</td><td>';
if (!empty($conf->global->KSEF_COMPANY_NIP)) {
    print '<span class="badge badge-status4">✓</span> ' . ksefFormatNIP($conf->global->KSEF_COMPANY_NIP);
} else {
    print '<span class="badge badge-status8">✗</span> ' . $langs->trans("KSEF_NotConfigured");
}
print '</td></tr>';

print '<tr class="oddeven"><td>' . $langs->trans("KSEF_AuthToken") . '</td><td>';
if (!empty($conf->global->KSEF_AUTH_TOKEN)) {
    print '<span class="badge badge-status4">✓</span> ' . $langs->trans("KSEF_Configured");
} else {
    print '<span class="badge badge-status8">✗</span> ' . $langs->trans("KSEF_NotConfigured");
}
print '</td></tr>';

print '<tr class="oddeven"><td>' . $langs->trans("KSEF_Environment") . '</td><td>';
$environment = $conf->global->KSEF_ENVIRONMENT ?? 'TEST';
print ksefGetEnvironmentBadge($environment);
print '</td></tr>';

print '<tr class="oddeven"><td>' . $langs->trans("KSEF_AutoSubmit") . '</td><td>';
$auto_submit = !empty($conf->global->KSEF_AUTO_SUBMIT) ? $conf->global->KSEF_AUTO_SUBMIT : 0;
print '<span class="badge badge-status' . ($auto_submit ? '4' : '8') . '">' . $langs->trans($auto_submit ? "Enabled" : "Disabled") . '</span>';
print '</td></tr>';

print '</table></div><br>';

$submission = new KsefSubmission($db);
$stats = $submission->getStatistics(30);

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder nohover centpercent">';
print '<tr class="liste_titre"><th colspan="2">' . $langs->trans("KSEF_Statistics") . ' (' . $langs->trans("KSEF_Last30Days") . ')</th></tr>';

print '<tr class="oddeven"><td>' . $langs->trans("KSEF_TotalSubmissions") . '</td><td class="right"><strong>' . $stats['total'] . '</strong></td></tr>';
print '<tr class="oddeven"><td>' . $langs->trans("KSEF_Accepted") . '</td><td class="right"><span class="badge badge-status4">' . $stats['accepted'] . '</span></td></tr>';
print '<tr class="oddeven"><td>' . $langs->trans("KSEF_Pending") . '</td><td class="right"><span class="badge badge-status1">' . $stats['pending'] . '</span></td></tr>';
print '<tr class="oddeven"><td>' . $langs->trans("KSEF_Failed") . '</td><td class="right"><span class="badge badge-status8">' . $stats['failed'] . '</span></td></tr>';
print '<tr class="oddeven"><td>' . $langs->trans("KSEF_SuccessRate") . '</td><td class="right"><strong>' . $stats['success_rate'] . '%</strong></td></tr>';

print '</table></div></div>';

print '<div class="fichehalfright">';

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder nohover centpercent">';
print '<tr class="liste_titre">';
print '<th>' . $langs->trans("KSEF_RecentSubmissions") . '</th>';
print '<th>' . $langs->trans("Invoice") . '</th>';
print '<th>' . $langs->trans("Status") . '</th>';
print '<th>' . $langs->trans("Date") . '</th>';
print '</tr>';

$sql = "SELECT s.rowid, s.fk_facture, s.status, s.date_submission, s.ksef_number, f.ref";
$sql .= " FROM " . MAIN_DB_PREFIX . "ksef_submissions as s";
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "facture as f ON s.fk_facture = f.rowid";
$sql .= " WHERE f.entity IN (" . getEntity('invoice') . ")";
$sql .= " ORDER BY s.date_submission DESC LIMIT " . $max;

$resql = $db->query($sql);
if ($resql) {
    $num = $db->num_rows($resql);
    if ($num > 0) {
        while ($obj = $db->fetch_object($resql)) {
            print '<tr class="oddeven">';
            print '<td>' . (!empty($obj->ksef_number) ? $obj->ksef_number : '<span class="opacitymedium">' . $langs->trans("KSEF_Pending") . '</span>') . '</td>';
            print '<td><a href="' . DOL_URL_ROOT . '/compta/facture/card.php?facid=' . $obj->fk_facture . '">' . $obj->ref . '</a></td>';
            print '<td>' . ksefGetStatusBadge($obj->status) . '</td>';
            print '<td>' . dol_print_date($obj->date_submission, 'dayhour') . '</td>';
            print '</tr>';
        }
    } else {
        print '<tr class="oddeven"><td colspan="4" class="opacitymedium">' . $langs->trans("KSEF_NoRecentSubmissions") . '</td></tr>';
    }
    $db->free($resql);
} else {
    dol_print_error($db);
}
print '</table></div><br>';

print '<div class="div-table-responsive-no-min"><div class="tabsAction">';
if ($user->admin) {
    print '<a class="butAction" href="' . dol_buildpath('/ksef/admin/setup.php', 1) . '">' . $langs->trans("KSEF_Settings") . '</a>';
}
print '<a class="butAction" href="' . dol_buildpath('/ksef/status.php', 1) . '">' . $langs->trans("KSEF_ViewAllSubmissions") . '</a>';
if (!empty($conf->global->KSEF_COMPANY_NIP) && !empty($conf->global->KSEF_AUTH_TOKEN)) {
    print '<a class="butAction" href="' . DOL_URL_ROOT . '/compta/facture/list.php">' . $langs->trans("KSEF_CreateInvoice") . '</a>';
}
print '</div></div>';

if (empty($conf->global->KSEF_COMPANY_NIP) || empty($conf->global->KSEF_AUTH_TOKEN)) {
    print '<br><div class="info">';
    print '<strong>' . $langs->trans("KSEF_SetupRequired") . '</strong><br>';
    print $langs->trans("KSEF_SetupRequiredDesc");
    print '<br><br><a class="butAction" href="' . dol_buildpath('/ksef/admin/setup.php', 1) . '">' . $langs->trans("KSEF_ConfigureNow") . '</a>';
    print '</div>';
}

$mandatory_date = mktime(0, 0, 0, 2, 1, 2026);
if ($now < $mandatory_date) {
    $days_remaining = ceil(($mandatory_date - $now) / 86400);
    print '<br><div class="warning">';
    print '<strong>' . $langs->trans("KSEF_MandatoryWarning") . '</strong><br>';
    print sprintf($langs->trans("KSEF_MandatoryWarningDesc"), $days_remaining);
    print '</div>';
}

print '</div></div>';

llxFooter();
$db->close();
