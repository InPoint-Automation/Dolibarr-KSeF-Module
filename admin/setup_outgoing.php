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
 * \file    admin/setup_outgoing.php
 * \ingroup ksef
 * \brief   Outgoing settings tab
 */

if (!defined('CSRFCHECK_WITH_TOKEN')) {
    define('CSRFCHECK_WITH_TOKEN', '1');
}

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
    $i--;
    $j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)) . "/main.inc.php")) {
    $res = @include substr($tmp, 0, ($i + 1)) . "/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php")) {
    $res = @include dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
    $res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
    $res = @include "../../../main.inc.php";
}
if (!$res) {
    die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
dol_include_once('/ksef/lib/ksef.lib.php');
dol_include_once('/ksef/lib/ksef_tour.lib.php');

if (!$user->admin) {
    accessforbidden();
}

$langs->loadLangs(array("admin", "bills", "ksef@ksef"));

$action = GETPOST('action', 'aZ09');

if ($action == 'update') {
    $error = 0;
    $qr_val = GETPOST('KSEF_ADD_QR', 'alpha') ? '1' : '0';
    dolibarr_set_const($db, 'KSEF_ADD_QR', $qr_val, 'chaine', 0, '', $conf->entity);

    // inverted: disables button
    $disable_validate_and_upload = GETPOST('KSEF_SHOW_VALIDATE_AND_UPLOAD', 'alpha') ? '0' : '1';
    dolibarr_set_const($db, 'KSEF_DISABLE_VALIDATE_AND_UPLOAD', $disable_validate_and_upload, 'chaine', 0, '', $conf->entity);
    $gap_d = GETPOSTINT('KSEF_OUTGOING_GAP_STARTday');
    $gap_m = GETPOSTINT('KSEF_OUTGOING_GAP_STARTmonth');
    $gap_y = GETPOSTINT('KSEF_OUTGOING_GAP_STARTyear');
    $gap_start = ($gap_y > 1900 && checkdate($gap_m, $gap_d, $gap_y)) ? sprintf('%04d-%02d-%02d', $gap_y, $gap_m, $gap_d) : '';
    dolibarr_set_const($db, 'KSEF_OUTGOING_GAP_START', $gap_start, 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'KSEF_BATCH_AUTO_CREATE_CUSTOMERS', GETPOST('KSEF_BATCH_AUTO_CREATE_CUSTOMERS', 'int') ? '1' : '0', 'chaine', 0, '', $conf->entity);

    $confirm_before_upload = GETPOST('KSEF_CONFIRM_BEFORE_UPLOAD', 'alpha') ? '1' : '0';
    dolibarr_set_const($db, 'KSEF_CONFIRM_BEFORE_UPLOAD', $confirm_before_upload, 'chaine', 0, '', $conf->entity);

    $offline_agenda = GETPOST('KSEF_OFFLINE_DEADLINE_AGENDA', 'alpha') ? '1' : '0';
    dolibarr_set_const($db, 'KSEF_OFFLINE_DEADLINE_AGENDA', $offline_agenda, 'chaine', 0, '', $conf->entity);

    $show_pdf_after_upload = GETPOST('KSEF_SHOW_PDF_AFTER_UPLOAD', 'alpha') ? '1' : '0';
    dolibarr_set_const($db, 'KSEF_SHOW_PDF_AFTER_UPLOAD', $show_pdf_after_upload, 'chaine', 0, '', $conf->entity);

    $confirmTemplate = GETPOST('KSEF_CONFIRM_PDF_TEMPLATE', 'alphanohtml');
    require_once DOL_DOCUMENT_ROOT . '/core/modules/facture/modules_facture.php';
    $confirmModels = ModelePDFFactures::liste_modeles($db);
    if ($confirmTemplate === 'ksef' || (is_array($confirmModels) && array_key_exists($confirmTemplate, $confirmModels))) {
        dolibarr_set_const($db, 'KSEF_CONFIRM_PDF_TEMPLATE', $confirmTemplate, 'chaine', 0, '', $conf->entity);
    } else {
        setEventMessages($langs->trans('ErrorBadValueForParameter', dol_escape_htmltag($confirmTemplate), $langs->transnoentitiesnoconv('KSEF_CONFIRM_PDF_TEMPLATE')), null, 'errors');
        $error++;
    }

    $paymentDefaults = array('KSEF_DEFAULT_PAYMENT_TERM_ID', 'KSEF_DEFAULT_PAYMENT_METHOD_ID');
    if (isModEnabled('bank')) {
        $paymentDefaults[] = 'KSEF_DEFAULT_BANK_ACCOUNT_ID';
    }
    foreach ($paymentDefaults as $constName) {
        $idValue = GETPOSTINT($constName);
        if ($idValue > 0) {
            dolibarr_set_const($db, $constName, (string) $idValue, 'chaine', 0, '', $conf->entity);
        } else {
            dolibarr_del_const($db, $constName, $conf->entity);
        }
    }

    $corr_reason_preset = GETPOST('KSEF_DEFAULT_CORRECTION_REASON_PRESET', 'alphanohtml');
    if ($corr_reason_preset === 'custom') {
        $corr_default_reason = trim(GETPOST('KSEF_DEFAULT_CORRECTION_REASON_CUSTOM', 'alphanohtml'));
    } elseif (!empty($corr_reason_preset) && $corr_reason_preset !== '-1') {
        $corr_default_reason = $corr_reason_preset;
    } else {
        $corr_default_reason = '';
    }
    dolibarr_set_const($db, 'KSEF_DEFAULT_CORRECTION_REASON', $corr_default_reason, 'chaine', 0, '', $conf->entity);

    $corr_default_type = GETPOST('KSEF_DEFAULT_CORRECTION_TYPE', 'int');
    if ($corr_default_type === '-1') {
        $corr_default_type = '';
    }
    if (in_array($corr_default_type, array('1', '2', '3', ''))) {
        dolibarr_set_const($db, 'KSEF_DEFAULT_CORRECTION_TYPE', $corr_default_type, 'chaine', 0, '', $conf->entity);
    }

    $kor_line_method = GETPOST('KSEF_KOR_LINE_METHOD', 'alpha');
    if (in_array($kor_line_method, array('differential', 'stanprzed'))) {
        dolibarr_set_const($db, 'KSEF_KOR_LINE_METHOD', $kor_line_method, 'chaine', 0, '', $conf->entity);
    }

    $socid = GETPOSTINT('socid');
    if ($socid > 0) {
        $tmpsoc = new Societe($db);
        if ($tmpsoc->fetch($socid) > 0) {
            $excluded = ksefGetExcludedCustomers();
            if (!in_array($socid, $excluded, true)) {
                $excluded[] = $socid;
                ksefSetExcludedCustomers($db, $excluded);
            }
            setEventMessages($langs->trans("KSEF_CustomerExcludedFromKSEF"), null, 'mesgs');
        } else {
            setEventMessages($langs->trans("ErrorRecordNotFound"), null, 'errors');
            $error++;
        }
    }

    if (!$error) {
        setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
    }
    // saved, then bank
    $then = GETPOST('ksef_then', 'aZ09');
    $outBack = dol_buildpath('/ksef/admin/setup_outgoing.php', 1) . '#KSEF_DEFAULT_BANK_ACCOUNT_ID';
    if ($then === 'bankenable') {
        $r = ksefRequirementFix($db, $user, 'mod_banque');
        setEventMessages($r['message'], null, $r['ok'] ? 'mesgs' : 'errors');
        header('Location: ' . $outBack);
        exit;
    }
    if ($then === 'bankadd' && isModEnabled('bank') && $user->hasRight('banque', 'configurer')) {
        header('Location: ' . ksefRequirementBankCreateUrl($outBack));
        exit;
    }
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

if ($action == 'remove_excluded') {
    $socid = GETPOSTINT('socid');
    ksefSetExcludedCustomers($db, array_diff(ksefGetExcludedCustomers(), array($socid)));
    setEventMessages($langs->trans("KSEF_CustomerRemovedFromKSEFExclusions"), null, 'mesgs');
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

$form = new Form($db);
$page_name = "KSEF_Setup";

llxHeader('', $langs->trans($page_name), ksefHelpUrl('quickstart'));

$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1">' . $langs->trans("KSEF_BackToModuleList") . '</a>';
print load_fiche_titre($langs->trans($page_name), ksefHelpTitleButton($user, 'outgoing', array('config' => 'KSEF_ConfigurationSection', 'sending' => 'KSEF_SendingInvoices'), array('setup_defaults')) . $linkback, 'title_setup');

$head = ksefAdminPrepareHead();
print dol_get_fiche_head($head, 'outgoing', $langs->trans("KSEF_Module"), -1, 'ksef@ksef');

echo ksefShowAdminBanners();

$warnings = ksefGetConfigWarnings();
if (!empty($warnings)) {
    echo ksefRenderConfigWarnings($warnings, 'outgoing');
}

print '<form id="ksef-outgoing-form" method="POST" action="' . $_SERVER["PHP_SELF"] . '" data-ksef-guarded="1">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="update">';
print '<input type="hidden" name="ksef_then" value="">';

print '<div class="ksef-settings-box">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre ksef-section-head"><td colspan="2">' . $langs->trans("KSEF_SUBMISSION_PDF_SECTION") . ksefSectionDesc('KSEF_SUBMISSION_PDF_SECTION_Desc') . '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_ADD_QR'), $langs->trans('KSEF_ADD_QR_Help')) . '</td>';
print '<td>';
print '<input type="checkbox" name="KSEF_ADD_QR" id="KSEF_ADD_QR" value="1" ' . (getDolGlobalInt('KSEF_ADD_QR') ? 'checked' : '') . '>';
print ' <label for="KSEF_ADD_QR">' . $langs->trans("KSEF_Enabled") . '</label>';
print '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_SHOW_VALIDATE_AND_UPLOAD'), $langs->trans('KSEF_SHOW_VALIDATE_AND_UPLOAD_Help')) . '</td>';
print '<td>';
print '<input type="checkbox" name="KSEF_SHOW_VALIDATE_AND_UPLOAD" id="KSEF_SHOW_VALIDATE_AND_UPLOAD" value="1" ' . (getDolGlobalInt('KSEF_DISABLE_VALIDATE_AND_UPLOAD') ? '' : 'checked') . '>';
print ' <label for="KSEF_SHOW_VALIDATE_AND_UPLOAD">' . $langs->trans("KSEF_Enabled") . '</label>';
print '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_OUTGOING_GAP_START'), $langs->trans('KSEF_OUTGOING_GAP_START_Help')) . '</td>';
$gap_ts = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', getDolGlobalString('KSEF_OUTGOING_GAP_START'), $gap_p) ? dol_mktime(12, 0, 0, (int) $gap_p[2], (int) $gap_p[3], (int) $gap_p[1]) : -1;
print '<td>' . $form->selectDate($gap_ts, 'KSEF_OUTGOING_GAP_START', 0, 0, 1, '', 1, 0) . '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_BATCH_AUTO_CREATE_CUSTOMERS_SETTING'), $langs->trans('KSEF_BATCH_AUTO_CREATE_CUSTOMERS_SETTING_Help')) . '</td>';
print '<td>';
print '<input type="checkbox" name="KSEF_BATCH_AUTO_CREATE_CUSTOMERS" id="KSEF_BATCH_AUTO_CREATE_CUSTOMERS" value="1" ' . (getDolGlobalString('KSEF_BATCH_AUTO_CREATE_CUSTOMERS', '1') === '1' ? 'checked' : '') . '>';
print ' <label for="KSEF_BATCH_AUTO_CREATE_CUSTOMERS">' . $langs->trans("KSEF_Enabled") . '</label>';
print '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_CONFIRM_BEFORE_UPLOAD'), $langs->trans('KSEF_CONFIRM_BEFORE_UPLOAD_Help')) . '</td>';
print '<td>';
print '<input type="checkbox" name="KSEF_CONFIRM_BEFORE_UPLOAD" id="KSEF_CONFIRM_BEFORE_UPLOAD" value="1" ' . (getDolGlobalInt('KSEF_CONFIRM_BEFORE_UPLOAD', 1) ? 'checked' : '') . '>';
print ' <label for="KSEF_CONFIRM_BEFORE_UPLOAD">' . $langs->trans("KSEF_Enabled") . '</label>';
print '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_OFFLINE_DEADLINE_AGENDA'), $langs->trans('KSEF_OFFLINE_DEADLINE_AGENDA_Help')) . '</td>';
print '<td>';
print '<input type="checkbox" name="KSEF_OFFLINE_DEADLINE_AGENDA" id="KSEF_OFFLINE_DEADLINE_AGENDA" value="1" ' . (getDolGlobalInt('KSEF_OFFLINE_DEADLINE_AGENDA') ? 'checked' : '') . '>';
print ' <label for="KSEF_OFFLINE_DEADLINE_AGENDA">' . $langs->trans("KSEF_Enabled") . '</label>';
if (!isModEnabled('agenda')) {
    print ' <span class="opacitymedium">' . $langs->trans('KSEF_OFFLINE_DEADLINE_AGENDA_NoAgenda') . '</span>';
}
print '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_SHOW_PDF_AFTER_UPLOAD'), $langs->trans('KSEF_SHOW_PDF_AFTER_UPLOAD_Help')) . '</td>';
print '<td>';
print '<input type="checkbox" name="KSEF_SHOW_PDF_AFTER_UPLOAD" id="KSEF_SHOW_PDF_AFTER_UPLOAD" value="1" ' . (getDolGlobalInt('KSEF_SHOW_PDF_AFTER_UPLOAD', 1) ? 'checked' : '') . '>';
print ' <label for="KSEF_SHOW_PDF_AFTER_UPLOAD">' . $langs->trans("KSEF_Enabled") . '</label>';
print '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_CONFIRM_PDF_TEMPLATE'), $langs->trans('KSEF_CONFIRM_PDF_TEMPLATE_Help')) . '</td>';
print '<td>';
$current_template = getDolGlobalString('KSEF_CONFIRM_PDF_TEMPLATE', 'ksef');
$pdf_template_opts = array('ksef' => $langs->trans('KSEF_CONFIRM_PDF_TEMPLATE_KSEF'));
require_once DOL_DOCUMENT_ROOT . '/core/modules/facture/modules_facture.php';
$dolibarr_models = ModelePDFFactures::liste_modeles($db);
if (is_array($dolibarr_models)) {
    foreach ($dolibarr_models as $key => $label) {
        $pdf_template_opts[$key] = ucfirst($key);
    }
}
print $form->selectarray('KSEF_CONFIRM_PDF_TEMPLATE', $pdf_template_opts, $current_template, 0, 0, 0, '', 0, 0, 0, '', 'minwidth300');
print '</td></tr>';

print '</table>';

print '<br><table class="noborder centpercent">';
print '<tr class="liste_titre ksef-section-head"><td colspan="2">' . $langs->trans("KSEF_CorrectionInvoicesSection") . ksefSectionDesc('KSEF_CorrectionInvoicesSection_Desc') . '</td></tr>';

print '<tr class="oddeven">';
print '<td colspan="2">';
print '<span class="opacitymedium">' . $langs->trans("KSEF_CorrectionInvoicesDesc") . '</span>';
print '</td></tr>';

$corrReasonPresetsSetup = array(
    'Zwrot towaru'          => 'KSEF_CorrectionReasonPreset_return',
    'Błędna ilość'          => 'KSEF_CorrectionReasonPreset_qty',
    'Błędna cena'           => 'KSEF_CorrectionReasonPreset_price',
    'Błędna stawka VAT'     => 'KSEF_CorrectionReasonPreset_vat',
    'Rabat potransakcyjny'  => 'KSEF_CorrectionReasonPreset_discount',
    'Skonto'                => 'KSEF_CorrectionReasonPreset_skonto',
    'Zwrot zaliczki'        => 'KSEF_CorrectionReasonPreset_advance',
    'Błędne dane nabywcy'   => 'KSEF_CorrectionReasonPreset_buyer',
);
$currentDefaultReason = getDolGlobalString('KSEF_DEFAULT_CORRECTION_REASON', '');
$defaultIsPreset = !empty($currentDefaultReason) && array_key_exists($currentDefaultReason, $corrReasonPresetsSetup);
$defaultIsCustom = !empty($currentDefaultReason) && !$defaultIsPreset;

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans("KSEF_DefaultCorrectionReason"), $langs->trans("KSEF_DefaultCorrectionReasonHelp")) . '</td>';
print '<td>';
$reason_opts = array();
foreach ($corrReasonPresetsSetup as $plText => $langKey) {
    $reason_opts[$plText] = $langs->trans($langKey);
}
$reason_opts['custom'] = $langs->trans("KSEF_CorrectionReasonPreset_other");
$reason_sel = $defaultIsCustom ? 'custom' : ($defaultIsPreset ? $currentDefaultReason : -1);
print $form->selectarray('KSEF_DEFAULT_CORRECTION_REASON_PRESET', $reason_opts, $reason_sel, $langs->trans('KSEF_NoDefault'), 0, 0, '', 0, 0, 0, '', 'minwidth300');
$customDisplay = $defaultIsCustom ? 'inline-block' : 'none';
$customValue = $defaultIsCustom ? dol_escape_htmltag($currentDefaultReason) : '';
print ' <input type="text" name="KSEF_DEFAULT_CORRECTION_REASON_CUSTOM" id="KSEF_DEFAULT_CORRECTION_REASON_CUSTOM"';
print ' value="' . $customValue . '" maxlength="256" style="display: ' . $customDisplay . '; width: 300px;"';
print ' placeholder="' . dol_escape_htmltag($langs->trans("KSEF_CorrectionReasonCustomPlaceholder")) . '">';
print '</td></tr>';

$currentDefaultType = getDolGlobalString('KSEF_DEFAULT_CORRECTION_TYPE', '');
print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans("KSEF_DefaultCorrectionType"), $langs->trans("KSEF_DefaultCorrectionTypeHelp")) . '</td>';
print '<td>';
$type_opts = array();
for ($t = 1; $t <= 3; $t++) {
    $type_opts[(string) $t] = $langs->trans("KSEF_CorrectionType" . $t);
}
print $form->selectarray('KSEF_DEFAULT_CORRECTION_TYPE', $type_opts, $currentDefaultType !== '' ? $currentDefaultType : -1, $langs->trans('KSEF_NoDefault'), 0, 0, '', 0, 0, 0, '', 'minwidth300');
print '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_KOR_LINE_METHOD'), $langs->trans('KSEF_KOR_LINE_METHOD_Help')) . '</td>';
print '<td>';
$kor_method_options = array(
    'differential' => $langs->trans('KSEF_KOR_LINE_METHOD_DIFFERENTIAL'),
    'stanprzed' => $langs->trans('KSEF_KOR_LINE_METHOD_STANPRZED'),
);
$current_kor_method = getDolGlobalString('KSEF_KOR_LINE_METHOD', 'stanprzed');
print $form->selectarray('KSEF_KOR_LINE_METHOD', $kor_method_options, $current_kor_method, 0, 0, 0, '', 0, 0, 0, '', 'minwidth300');
print '</td></tr>';

print '</table>';

print '<br><table class="noborder centpercent">';
print '<tr class="liste_titre ksef-section-head"><td colspan="2">' . $langs->trans("KSEF_DEFAULT_PAYMENT_CONFIG") . ksefSectionDesc('KSEF_DEFAULT_PAYMENT_CONFIG_Desc') . '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_DEFAULT_PAYMENT_TERM'), $langs->trans('KSEF_DEFAULT_PAYMENT_TERM_Help')) . '</td>';
print '<td>';
print $form->getSelectConditionsPaiements(getDolGlobalInt('KSEF_DEFAULT_PAYMENT_TERM_ID', 0), 'KSEF_DEFAULT_PAYMENT_TERM_ID', -1, 1, 1, 'minwidth300');
print '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_DEFAULT_PAYMENT_METHOD'), $langs->trans('KSEF_DEFAULT_PAYMENT_METHOD_Help')) . '</td>';
print '<td>';
print $form->select_types_paiements(getDolGlobalString('KSEF_DEFAULT_PAYMENT_METHOD_ID', ''), 'KSEF_DEFAULT_PAYMENT_METHOD_ID', '', 0, 1, 1, 0, 1, 'minwidth300', 1);
print '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_DEFAULT_BANK_ACCOUNT'), $langs->trans('KSEF_DEFAULT_BANK_ACCOUNT_Help')) . '</td>';
print '<td>';
$outBack = dol_buildpath('/ksef/admin/setup_outgoing.php', 1) . '#KSEF_DEFAULT_BANK_ACCOUNT_ID';
if (isModEnabled('bank')) {
    print $form->select_comptes(getDolGlobalString('KSEF_DEFAULT_BANK_ACCOUNT_ID', ''), 'KSEF_DEFAULT_BANK_ACCOUNT_ID', 0, '', 1, '', 0, 'minwidth300', 1);
    if (!ksefRequirementRow($db, 'bank_account', $user)['met'] && $user->hasRight('banque', 'configurer')) {
        print ' <a class="ksef-bank-add" data-ksef-then="bankadd" title="' . dol_escape_htmltag($langs->trans('KSEF_SavesFormFirst')) . '" href="' . dol_escape_htmltag(ksefRequirementBankCreateUrl($outBack)) . '">' . img_picto('', 'add', 'class="pictofixedwidth"') . $langs->trans('KSEF_ReqFix_AddBank') . '</a>';
        print '<div class="opacitymedium">' . $langs->trans('KSEF_BankAccountNewDefault') . '</div>';
    }
} else {
    $bankId = getDolGlobalInt('KSEF_DEFAULT_BANK_ACCOUNT_ID');
    if ($bankId > 0) {
        $resql = $db->query("SELECT ref, label FROM " . MAIN_DB_PREFIX . "bank_account WHERE rowid = " . $bankId);
        $bankObj = $resql ? $db->fetch_object($resql) : null;
        print '<span class="marginrightonly">' . dol_escape_htmltag($bankObj ? $bankObj->ref . ' - ' . $bankObj->label : '#' . $bankId) . '</span> ';
    }
    print ksefRequiresModuleText('Module85Name');
    if (!empty($user->admin)) {
        print ' <a class="ksef-bank-enable" data-ksef-then="bankenable" title="' . dol_escape_htmltag($langs->trans('KSEF_SavesFormFirst')) . '" href="' . dol_escape_htmltag(ksefRequirementFixUrl('mod_banque', $outBack)) . '">' . $langs->trans('KSEF_ReqFix_Enable') . '</a>';
    }
}
print '</td></tr>';
print '</table>';

print '<br><table class="noborder centpercent">';
print '<tr class="liste_titre ksef-section-head"><td colspan="2">' . $langs->trans("KSEF_CUSTOMER_EXCLUSIONS") . ksefSectionDesc('KSEF_CUSTOMER_EXCLUSIONS_Desc') . '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans("KSEF_EXCLUDED_CUSTOMERS"), $langs->trans("KSEF_EXCLUDED_CUSTOMERS_Help")) . '</td>';
print '<td>';

$excluded_ids = ksefGetExcludedCustomers();
if (!empty($excluded_ids)) {
    foreach ($excluded_ids as $socid) {
        $tmpsoc = new Societe($db);
        if ($tmpsoc->fetch($socid) > 0) {
            print '<span class="badge badge-secondary" style="margin-right: 5px; white-space: normal; text-align: left;">';
            print dol_escape_htmltag($tmpsoc->name);
            print ' <a href="' . $_SERVER["PHP_SELF"] . '?action=remove_excluded&socid=' . $socid . '&token=' . newToken() . '" class="reposition">' . img_delete($langs->trans('Remove')) . '</a>';
            print '</span> ';
        }
    }
    print '<br><br>';
}

print $form->select_company('', 'socid', '(s.client:IN:1,3)', 'KSEF_SelectCustomer', 0, 0, array(), 0, 'minwidth300 maxwidth500 widthcentpercentminusx', '', '', 1, array(), false, $excluded_ids);
print '<br><span class="opacitymedium small">' . $langs->trans('KSEF_ExcludedAddOnSave') . '</span>';
print '</td></tr>';

print '</table>';

print '</div>';

print '<div class="center" style="margin-top: 10px;">';
print '<input type="submit" class="button button-save" value="' . $langs->trans("Save") . '">';
print '</div>';
print '</form>';

print '<script>
document.addEventListener("DOMContentLoaded", function() {
    var outForm = document.getElementById("ksef-outgoing-form");
    if (outForm) {
        // save first, then go
        outForm.addEventListener("click", function (e) {
            var a = e.target && e.target.closest ? e.target.closest("a[data-ksef-then]") : null;
            if (!a) { return; }
            e.preventDefault();
            outForm.elements["ksef_then"].value = a.getAttribute("data-ksef-then");
            outForm.removeAttribute("data-ksef-dirty");
            outForm.submit();
        });
    }
    var presetSel = document.getElementById("KSEF_DEFAULT_CORRECTION_REASON_PRESET");
    if (presetSel) {
        // select2: jQuery events
        jQuery(presetSel).on("change", function() {
            var customInput = document.getElementById("KSEF_DEFAULT_CORRECTION_REASON_CUSTOM");
            if (this.value === "custom") {
                customInput.style.display = "inline-block";
                customInput.focus();
            } else {
                customInput.style.display = "none";
                customInput.value = "";
            }
        });
    }
});
</script>';

print dol_get_fiche_end();

print ksefAdminDirtyGuardJs();
print ksefCollapsibleSectionsJs();
print ksefHelpFooter($db, $user);
llxFooter();
