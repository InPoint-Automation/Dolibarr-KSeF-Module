<?php
/* Copyright (C) 2004-2017 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2021      Éric Seigne          <eric.seigne@cap-rel.fr>
 * Copyright (C) 2025-2026      InPoint Automation Sp z o.o.
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
 * \file    admin/setup.php
 * \ingroup ksef
 * \brief   KSeF setup page
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

global $langs, $user, $conf, $db;

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/security.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
dol_include_once('/ksef/lib/ksef.lib.php');
dol_include_once('/ksef/lib/ksef_tour.lib.php');
dol_include_once('/ksef/class/ksef_client.class.php');
dol_include_once('/ksef/class/ksef_latarnia.class.php');

$langs->loadLangs(array("admin", "multicurrency", "ksef@ksef"));

if (!$user->admin) {
    accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');
$error = 0;

global $mysoc;

if (empty(getDolGlobalString('KSEF_COMPANY_NIP'))) {
    $auto_nip = ksefCleanNIP(ksefGetIdentifierField($mysoc, 'NIP'));
    if (!empty($auto_nip) && ksefValidateNIP($auto_nip)) {
        dolibarr_set_const($db, 'KSEF_COMPANY_NIP', $auto_nip, 'chaine', 0, '', $conf->entity);
    }
}

if (empty(getDolGlobalString('KSEF_COMPANY_KRS'))) {
    $auto_krs = trim(ksefGetIdentifierField($mysoc, 'KRS'));
    if (!empty($auto_krs)) {
        dolibarr_set_const($db, 'KSEF_COMPANY_KRS', $auto_krs, 'chaine', 0, '', $conf->entity);
    }
}

if (empty(getDolGlobalString('KSEF_COMPANY_REGON'))) {
    $auto_regon = trim(ksefGetIdentifierField($mysoc, 'REGON'));
    if (!empty($auto_regon)) {
        dolibarr_set_const($db, 'KSEF_COMPANY_REGON', $auto_regon, 'chaine', 0, '', $conf->entity);
    }
}

if (empty(getDolGlobalString('KSEF_COMPANY_BDO'))) {
    $auto_bdo = trim(ksefGetIdentifierField($mysoc, 'BDO'));
    if (!empty($auto_bdo)) {
        dolibarr_set_const($db, 'KSEF_COMPANY_BDO', $auto_bdo, 'chaine', 0, '', $conf->entity);
    }
}

if (empty(getDolGlobalString('KSEF_COMPANY_EORI'))) {
    $auto_eori = trim(ksefGetIdentifierField($mysoc, 'EORI'));
    if (!empty($auto_eori)) {
        dolibarr_set_const($db, 'KSEF_COMPANY_EORI', $auto_eori, 'chaine', 0, '', $conf->entity);
    }
}

if ($action == 'fill_from_company') {
    $ident = GETPOST('ident', 'aZ09');
    if (in_array($ident, array('NIP', 'KRS', 'REGON', 'BDO', 'EORI'), true)) {
        $fillVal = ksefSetupCompanyValue($mysoc, $ident);
        if ($fillVal === '') {
            setEventMessages($langs->trans('KSEF_FillFromCompanyNone', $ident), null, 'warnings');
        } elseif ($ident === 'NIP' && !ksefValidateNIP($fillVal)) {
            setEventMessages($langs->trans('KSEF_NipInvalidNotCopied', dol_escape_htmltag($fillVal)), null, 'warnings');
        } else {
            dolibarr_set_const($db, 'KSEF_COMPANY_' . $ident, $fillVal, 'chaine', 0, '', $conf->entity);
            setEventMessages($langs->trans('KSEF_FillFromCompanyDone', $ident, dol_escape_htmltag($fillVal)), null, 'mesgs');
        }
    }
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

if ($action == 'update') {
    $nip = GETPOST("KSEF_COMPANY_NIP", 'alphanohtml');
    $nip = ksefCleanNIP($nip);
    if ($nip !== '' && !ksefValidateNIP($nip)) {
        if (ksefGetEnvironment() === 'PRODUCTION') {
            setEventMessages($langs->trans('KSEF_NipInvalidRefused', dol_escape_htmltag($nip)), null, 'errors');
            $error++;
        } else {
            setEventMessages($langs->trans('KSEF_NipInvalidStored', dol_escape_htmltag($nip)), null, 'warnings');
            dolibarr_set_const($db, 'KSEF_COMPANY_NIP', $nip, 'chaine', 0, '', $conf->entity);
        }
    } else {
        dolibarr_set_const($db, 'KSEF_COMPANY_NIP', $nip, 'chaine', 0, '', $conf->entity);
    }

    $krs = GETPOST("KSEF_COMPANY_KRS", 'alphanohtml');
    dolibarr_set_const($db, 'KSEF_COMPANY_KRS', trim($krs), 'chaine', 0, '', $conf->entity);

    $regon = GETPOST("KSEF_COMPANY_REGON", 'alphanohtml');
    dolibarr_set_const($db, 'KSEF_COMPANY_REGON', trim($regon), 'chaine', 0, '', $conf->entity);

    $bdo = GETPOST("KSEF_COMPANY_BDO", 'alphanohtml');
    dolibarr_set_const($db, 'KSEF_COMPANY_BDO', trim($bdo), 'chaine', 0, '', $conf->entity);

    $eori = GETPOST("KSEF_COMPANY_EORI", 'alphanohtml');
    dolibarr_set_const($db, 'KSEF_COMPANY_EORI', trim($eori), 'chaine', 0, '', $conf->entity);

    $validFields = array('idprof1', 'idprof2', 'idprof3', 'idprof4', 'idprof5', 'idprof6', 'tva_intra');
    $fieldMappings = array();
    foreach (array('NIP', 'KRS', 'REGON', 'BDO', 'EORI') as $ident) {
        $fieldVal = GETPOST('KSEF_FIELD_' . $ident, 'alpha');
        if (in_array($fieldVal, $validFields, true) || ($fieldVal === 'disabled' && $ident !== 'NIP')) {
            dolibarr_set_const($db, 'KSEF_FIELD_' . $ident, $fieldVal, 'chaine', 0, '', $conf->entity);
            if ($fieldVal !== 'disabled') {
                $fieldMappings[$ident] = $fieldVal;
            }
        }
    }

    $fieldUsage = array();
    foreach ($fieldMappings as $ident => $fieldVal) {
        $fieldUsage[$fieldVal][] = $ident;
    }
    foreach ($fieldUsage as $fieldVal => $idents) {
        if (count($idents) > 1) {
            setEventMessages($langs->trans("KSEF_WARNING_DUPLICATE_FIELD", implode(', ', $idents), $fieldVal), null, 'warnings');
        }
    }

    foreach (array('NIP' => 'KSEF_COMPANY_NIP', 'KRS' => 'KSEF_COMPANY_KRS', 'REGON' => 'KSEF_COMPANY_REGON', 'BDO' => 'KSEF_COMPANY_BDO', 'EORI' => 'KSEF_COMPANY_EORI') as $ident => $constName) {
        $currentVal = GETPOST($constName, 'alphanohtml');
        if (empty(trim($currentVal)) && isset($fieldMappings[$ident])) {
            $autoVal = ksefGetIdentifierField($mysoc, $ident);
            if ($ident === 'NIP') {
                $autoVal = ksefCleanNIP($autoVal);
                if (!ksefValidateNIP($autoVal)) {
                    if ($autoVal !== '') {
                        setEventMessages($langs->trans('KSEF_NipInvalidNotCopied', dol_escape_htmltag($autoVal)), null, 'warnings');
                    }
                    continue;
                }
            } else {
                $autoVal = trim($autoVal);
            }
            if (!empty($autoVal)) {
                dolibarr_set_const($db, $constName, $autoVal, 'chaine', 0, '', $conf->entity);
            }
        }
    }

    $timeout = GETPOSTISSET('KSEF_TIMEOUT') ? trim(GETPOST('KSEF_TIMEOUT', 'alphanohtml')) : null;
    if ($timeout === null) {
        // keep stored value
    } elseif ($timeout === '' || !preg_match('/^\d+$/', $timeout) || (int) $timeout < 3 || (int) $timeout > 120) {
        setEventMessages($langs->trans('KSEF_TimeoutInvalid'), null, 'warnings');
    } else {
        dolibarr_set_const($db, 'KSEF_TIMEOUT', (string) (int) $timeout, 'chaine', 1, '', $conf->entity);
    }

    $purge_val = GETPOST('KSEF_PURGE_ON_DISABLE', 'alpha') ? '1' : '0';
    dolibarr_set_const($db, 'KSEF_PURGE_ON_DISABLE', $purge_val, 'chaine', 0, '', $conf->entity);

    $nbp_rate_mode = isModEnabled('multicurrency') ? GETPOST('KSEF_NBP_RATE_MODE', 'alpha') : '';
    if (in_array($nbp_rate_mode, array('keep_base', 'keep_foreign'))) {
        dolibarr_set_const($db, 'KSEF_NBP_RATE_MODE', $nbp_rate_mode, 'chaine', 0, '', $conf->entity);
    }

    // art. 31a(2a-2c)
    $nbp_date_method = isModEnabled('multicurrency') ? GETPOST('KSEF_NBP_RATE_DATE_METHOD', 'aZ09') : '';
    if (in_array($nbp_date_method, array('vat', 'income_tax'), true)
        && $nbp_date_method !== getDolGlobalString('KSEF_NBP_RATE_DATE_METHOD', 'vat')) {
        $nbp_switch_warning = ksefNbpRateMethodSwitchWarning($nbp_date_method);
        if ($nbp_switch_warning !== '') {
            setEventMessages($nbp_switch_warning, null, 'warnings');
        }
        dolibarr_set_const($db, 'KSEF_NBP_RATE_DATE_METHOD', $nbp_date_method, 'chaine', 0, '', $conf->entity);
        dolibarr_set_const($db, 'KSEF_NBP_RATE_DATE_METHOD_SINCE', (string) dol_now(), 'chaine', 0, '', $conf->entity);
    }

    if (isModEnabled('multicurrency')) {
        foreach (ksefFxSettingValues() as $fx_name => $fx_values) {
            $fx_val = GETPOST($fx_name, 'aZ09');
            if (in_array($fx_val, $fx_values, true)) {
                dolibarr_set_const($db, $fx_name, $fx_val, 'chaine', 0, '', $conf->entity);
            }
        }
    }

    if (isModEnabled('multicurrency')) {
        $nbp_sync_curr = GETPOST('KSEF_NBP_SYNC_CURRENCIES', 'array');
        $nbp_sync_clean = array();
        if (is_array($nbp_sync_curr)) {
            foreach ($nbp_sync_curr as $c) {
                $c = strtoupper(preg_replace('/[^A-Za-z]/', '', $c));
                if ($c !== '') $nbp_sync_clean[] = $c;
            }
        }
        dolibarr_set_const($db, 'KSEF_NBP_SYNC_CURRENCIES', implode(',', $nbp_sync_clean), 'chaine', 0, '', $conf->entity);
    }

    $_vatPlId = 0;
    $_vatPlSql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "c_country WHERE code = 'PL'";
    $_vatPlRes = $db->query($_vatPlSql);
    if ($_vatPlRes && $_vatPlObj = $db->fetch_object($_vatPlRes)) $_vatPlId = (int) $_vatPlObj->rowid;
    if ($_vatPlId > 0) {
        $_vatAllSql = "SELECT rowid, code, taux, active, entity FROM " . MAIN_DB_PREFIX . "c_tva"
            . " WHERE fk_pays = " . $_vatPlId
            . " AND entity = " . ((int) $conf->entity);
        $_vatAllRes = $db->query($_vatAllSql);
        $_vatActive23 = 0;
        while ($_vatAllRes && $_vatRow = $db->fetch_object($_vatAllRes)) {
            $origField = 'KSEF_VAT_ORIG_' . ((int) $_vatRow->rowid);
            $active = (int) $_vatRow->active;
            if (GETPOSTISSET($origField)) {
                $wantEnabled = GETPOST('KSEF_VAT_TOGGLE_' . ((int) $_vatRow->rowid), 'alpha') ? 1 : 0;
                if ($wantEnabled !== GETPOSTINT($origField)) {
                    $sqlVat = "UPDATE " . MAIN_DB_PREFIX . "c_tva SET active = " . $wantEnabled . " WHERE rowid = " . ((int) $_vatRow->rowid)
                        . " AND entity = " . ((int) $conf->entity) . " AND fk_pays = " . $_vatPlId;
                    if ($db->query($sqlVat)) {
                        $active = $wantEnabled;
                    } else {
                        setEventMessages($db->lasterror(), null, 'errors');
                        $error++;
                    }
                }
            }
            if ($active && (float) $_vatRow->taux == 23) {
                $_vatActive23++;
            }
        }
        $_vat23Global = $db->query("SELECT COUNT(*) AS n FROM " . MAIN_DB_PREFIX . "c_tva WHERE fk_pays = " . $_vatPlId . " AND entity = 0 AND active = 1 AND taux = 23");
        if ($_vat23Global && ($_vatObj23 = $db->fetch_object($_vat23Global))) {
            $_vatActive23 += (int) $_vatObj23->n;
        }
        if ($_vatActive23 === 0) {
            setEventMessages($langs->trans('KSEF_VAT_WARN_NO_23'), null, 'warnings');
        }
    }

    if (!$error) {
        setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
    }
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

if ($action == 'checklatarnia') {
    $latarnia = new KsefLatarnia($db);
    $result = $latarnia->checkAndCache();
    if ($result !== false) {
        setEventMessages($langs->trans('KSEF_LatarniaCheckSuccess', $langs->transnoentitiesnoconv('KSEF_LATARNIA_' . $result['status'])), null, 'mesgs');
    } else {
        setEventMessages($langs->trans('KSEF_LatarniaCheckError', $latarnia->error), null, 'errors');
    }
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

if ($action == 'ksef_hide_nbp_mode_notice') {
    dolibarr_del_const($db, 'KSEF_NBP_MODE_NOTICE', $conf->entity);
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

$activeEnv = ksefGetEnvironment();

$form = new Form($db);
$page_name = "KSEF_Setup";

llxHeader('', $langs->trans($page_name), ksefHelpUrl('quickstart'));

$linkback = '<a href="' . ($backtopage ?: DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1') . '">' . $langs->trans("KSEF_BackToModuleList") . '</a>';
print load_fiche_titre($langs->trans($page_name), ksefHelpTitleButton($user, 'setup', array('quickstart' => 'KSEF_QuickStartGuide', 'config' => 'KSEF_ConfigurationSection'), array('setup_company', 'setup_vat', 'setup_optional')) . $linkback, 'title_setup');

$head = ksefAdminPrepareHead();
print dol_get_fiche_head($head, 'general', $langs->trans("KSEF_Module"), -1, 'ksef@ksef');

echo ksefShowAdminBanners();
echo ksefNbpModeNotice();

$warnings = ksefGetConfigWarnings();
if (!empty($warnings)) {
    echo ksefRenderConfigWarnings($warnings, 'general');
}

print '<span class="ksef-tab-intro">' . $langs->trans("KSEF_SetupPage") . '</span><br><br>';

$latarnia_cached = KsefLatarnia::getCachedStatus();
print '<div style="margin-bottom: 15px; padding: 10px; border: 1px solid var(--ksef-surface-border, #ddd); border-radius: 4px; background: var(--ksef-surface, #f8f9fa);">';
print '<strong><i class="fas fa-signal"></i> ' . $langs->trans("KSEF_SystemStatus") . ':</strong> ';
print ksefGetLatarniaStatusBadge($latarnia_cached['status']);
if ($latarnia_cached['timestamp'] > 0) {
    print ' <span class="opacitymedium" style="font-size: 0.85em;">(' . $langs->trans("KSEF_LastChecked") . ': ' . dol_print_date($latarnia_cached['timestamp'], 'dayhour') . ')</span>';
} else {
    print ' <span class="opacitymedium" style="font-size: 0.85em;">(' . $langs->trans("KSEF_NeverChecked") . ')</span>';
}
print ' <a class="button smallpaddingimp marginleftonly" href="' . $_SERVER["PHP_SELF"] . '?action=checklatarnia&token=' . newToken() . '">' . $langs->trans("KSEF_CheckLatarnia") . '</a>';
$lmsg = KsefLatarnia::currentMessage($latarnia_cached);
if ($lmsg !== null) {
    print '<br><span style="font-size: 0.9em; margin-left: 20px;">' . dol_escape_htmltag($lmsg['title'] ?? '') . '</span>';
}
print '</div>';

print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '" data-ksef-guarded="1">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="update">';

print '<div class="ksef-settings-box">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre ksef-section-head"><td colspan="2">' . $langs->trans("KSEF_BASIC_CONFIG") . ksefSectionDesc('KSEF_BASIC_CONFIG_Desc') . '</td></tr>';

$fieldOptionsNip = ksefGetFieldOptions(false);
$fieldOptionsOptional = ksefGetFieldOptions(true);

$fillButton = function ($ident) use ($langs, $mysoc) {
    $val = ksefSetupCompanyValue($mysoc, $ident);
    $label = '<span class="fa fa-sync-alt"></span>';
    $aria = ' aria-label="' . dol_escape_htmltag($langs->transnoentitiesnoconv('KSEF_FillFromCompany') . ': ' . $ident) . '"';
    if ($val === '') {
        return ' <a class="button smallpaddingimp nominwidth disabled nohover classfortooltip ksef-fill-company" data-ident="' . $ident . '" role="link" aria-disabled="true"' . $aria . ' title="'
            . dol_escape_htmltag($langs->transnoentitiesnoconv('KSEF_FillFromCompanyNone', $ident)) . '">' . $label . '</a>';
    }
    return ' <a class="button smallpaddingimp nominwidth classfortooltip ksef-fill-company" data-ident="' . $ident . '"' . $aria . ' href="' . $_SERVER['PHP_SELF'] . '?action=fill_from_company&ident=' . $ident . '&token=' . newToken()
        . '" title="' . dol_escape_htmltag($langs->transnoentitiesnoconv('KSEF_FillFromCompanyHelp', $ident, $val)) . '">' . $label . '</a>';
};

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans("KSEF_COMPANY_NIP"), $langs->trans("KSEF_COMPANY_NIP_Help")) . '</td>';
print '<td>';
print '<input type="text" class="flat minwidth200" name="KSEF_COMPANY_NIP" value="' . dol_escape_htmltag(getDolGlobalString('KSEF_COMPANY_NIP', '')) . '" placeholder="1234563218">' . $fillButton('NIP') . ' ';
print '<span class="small">' . $langs->trans("KSEF_FIELD_SOURCE") . ': </span>';
print $form->selectarray('KSEF_FIELD_NIP', $fieldOptionsNip, getDolGlobalString('KSEF_FIELD_NIP', 'idprof1'), 0, 0, 0, '', 0, 0, 0, '', 'minwidth200 maxwidth200 small');
$nipField = getDolGlobalString('KSEF_FIELD_NIP', 'idprof1');
if ($nipField === 'tva_intra') {
    print ' <span class="small">(' . $langs->trans("KSEF_NIP_FROM_VATID_NOTE") . ')</span>';
}
print '</td>';
print '</tr>';

print '<tr class="oddeven" id="ksef-company-address">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans("KSEF_SellerAddress"), $langs->trans("KSEF_SellerAddress_Help")) . '</td>';
print '<td>' . ksefHelpCompanyAddressHtml($mysoc) . ' <a href="' . DOL_URL_ROOT . '/admin/company.php" class="small" target="_blank" rel="noopener">' . $langs->trans("KSEF_EditCompanyData") . '</a></td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans("KSEF_FIELD_VATID"), $langs->trans("KSEF_FIELD_VATID_Help")) . '</td>';
print '<td><span>' . $langs->trans("VATIntra") . ' (tva_intra) - ' . $langs->trans("KSEF_FIELD_VATID_ALWAYS") . '</span></td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans("KSEF_COMPANY_KRS"), $langs->trans("KSEF_COMPANY_KRS_Help")) . '</td>';
print '<td>';
print '<input type="text" class="flat minwidth200" name="KSEF_COMPANY_KRS" value="' . dol_escape_htmltag(getDolGlobalString('KSEF_COMPANY_KRS', '')) . '" placeholder="0000000000">' . $fillButton('KRS');
print ' <span class="small">(' . $langs->trans("KSEF_Optional") . ')</span> ';
print '<span class="small">' . $langs->trans("KSEF_FIELD_SOURCE") . ': </span>';
print $form->selectarray('KSEF_FIELD_KRS', $fieldOptionsOptional, getDolGlobalString('KSEF_FIELD_KRS', 'idprof2'), 0, 0, 0, '', 0, 0, 0, '', 'minwidth200 maxwidth200 small');
print '</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans("KSEF_COMPANY_REGON"), $langs->trans("KSEF_COMPANY_REGON_Help")) . '</td>';
print '<td>';
print '<input type="text" class="flat minwidth200" name="KSEF_COMPANY_REGON" value="' . dol_escape_htmltag(getDolGlobalString('KSEF_COMPANY_REGON', '')) . '" placeholder="000000000">' . $fillButton('REGON');
print ' <span class="small">(' . $langs->trans("KSEF_Optional") . ')</span> ';
print '<span class="small">' . $langs->trans("KSEF_FIELD_SOURCE") . ': </span>';
print $form->selectarray('KSEF_FIELD_REGON', $fieldOptionsOptional, getDolGlobalString('KSEF_FIELD_REGON', 'idprof3'), 0, 0, 0, '', 0, 0, 0, '', 'minwidth200 maxwidth200 small');
print '</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans("KSEF_COMPANY_BDO"), $langs->trans("KSEF_COMPANY_BDO_Help")) . '</td>';
print '<td>';
print '<input type="text" class="flat minwidth200" name="KSEF_COMPANY_BDO" value="' . dol_escape_htmltag(getDolGlobalString('KSEF_COMPANY_BDO', '')) . '" placeholder="000000000">' . $fillButton('BDO');
print ' <span class="small">(' . $langs->trans("KSEF_Optional") . ')</span> ';
print '<span class="small">' . $langs->trans("KSEF_FIELD_SOURCE") . ': </span>';
print $form->selectarray('KSEF_FIELD_BDO', $fieldOptionsOptional, getDolGlobalString('KSEF_FIELD_BDO', 'idprof4'), 0, 0, 0, '', 0, 0, 0, '', 'minwidth200 maxwidth200 small');
print '</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans("KSEF_COMPANY_EORI"), $langs->trans("KSEF_COMPANY_EORI_Help")) . '</td>';
print '<td>';
print '<input type="text" class="flat minwidth200" name="KSEF_COMPANY_EORI" value="' . dol_escape_htmltag(getDolGlobalString('KSEF_COMPANY_EORI', '')) . '" placeholder="PL1234567890ABCDE">' . $fillButton('EORI');
print ' <span class="small">(' . $langs->trans("KSEF_Optional") . ')</span> ';
print '<span class="small">' . $langs->trans("KSEF_FIELD_SOURCE") . ': </span>';
print $form->selectarray('KSEF_FIELD_EORI', $fieldOptionsOptional, getDolGlobalString('KSEF_FIELD_EORI', 'idprof5'), 0, 0, 0, '', 0, 0, 0, '', 'minwidth200 maxwidth200 small');
print '</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans("KSEF_ENVIRONMENT"), $langs->trans("KSEF_ENVIRONMENT_Help")) . '</td>';
print '<td>' . ksefGetEnvironmentBadge($activeEnv);
print ' <a href="' . dol_buildpath('/ksef/admin/setup_auth.php', 1) . '#env-' . $activeEnv . '" class="small">' . $langs->trans('KSEF_ChangeEnvironmentOnAuthTab') . '</a>';
print '</td></tr>';

print '<tr class="oddeven" id="ksef-timeout">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans("KSEF_Timeout"), $langs->trans("KSEF_Timeout_Help")) . '</td>';
print '<td><input type="number" class="flat width75" name="KSEF_TIMEOUT" min="3" max="120" step="1" value="' . ksefGetTimeout() . '"> ' . $langs->trans("KSEF_Seconds") . '</td>';
print '</tr>';

print '</table>';

print '<br><table class="noborder centpercent">';
print '<tr class="liste_titre ksef-section-head"><td colspan="2">' . $langs->trans("KSEF_ModuleDataTitle") . ksefSectionDesc('KSEF_ModuleDataTitle_Desc') . '</td></tr>';
print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_PURGE_ON_DISABLE'), $langs->trans('KSEF_PURGE_ON_DISABLE_Help')) . '</td>';
print '<td>';
print '<input type="checkbox" name="KSEF_PURGE_ON_DISABLE" id="KSEF_PURGE_ON_DISABLE" value="1" ' . (getDolGlobalInt('KSEF_PURGE_ON_DISABLE') ? 'checked' : '') . '>';
print ' <label for="KSEF_PURGE_ON_DISABLE">' . $langs->trans("KSEF_Enabled") . '</label>';
print '<br><span class="warning small">' . $langs->trans("KSEF_PurgeWarning") . '</span>';
print '</td></tr>';

print '</table>';

print '<br><table class="noborder centpercent">';
print '<tr class="liste_titre ksef-section-head"><td colspan="4">' . $langs->trans("KSEF_VAT_DICTIONARY_HELPER") . ksefSectionDesc('KSEF_SectionDesc_VatCodes') . '</td></tr>';
print '<tr class="oddeven" id="ksef-vat-codes"><td colspan="4">' . $form->textwithpicto($langs->trans('KSEF_VAT_DICTIONARY_HELPER_Desc'), $langs->trans('KSEF_VAT_DICTIONARY_HELPER_Help')) . '</td></tr>';

$_vatPolandId = 0;
$_vatSqlPl = "SELECT rowid FROM " . MAIN_DB_PREFIX . "c_country WHERE code = 'PL'";
$_vatResPl = $db->query($_vatSqlPl);
if ($_vatResPl && $_vatObjPl = $db->fetch_object($_vatResPl)) $_vatPolandId = (int) $_vatObjPl->rowid;

$ksefVatMeta = array(
    'ZW'  => array('desc' => $langs->trans('KSEF_VAT_CODE_ZW')),
    'RC'  => array('desc' => $langs->trans('KSEF_VAT_CODE_RC')),
    'NP'  => array('desc' => $langs->trans('KSEF_VAT_CODE_NP')),
    'NP2' => array('desc' => $langs->trans('KSEF_VAT_CODE_NP2')),
    'WDT' => array('desc' => $langs->trans('KSEF_VAT_CODE_WDT')),
    'EX'  => array('desc' => $langs->trans('KSEF_VAT_CODE_EX')),
    'MARZA2'  => array('desc' => $langs->trans('KSEF_VAT_CODE_MARZA2')),
    'MARZA31' => array('desc' => $langs->trans('KSEF_VAT_CODE_MARZA31')),
    'MARZA32' => array('desc' => $langs->trans('KSEF_VAT_CODE_MARZA32')),
    'MARZA33' => array('desc' => $langs->trans('KSEF_VAT_CODE_MARZA33')),
);

$_vatAllEntries = array();
if ($_vatPolandId > 0) {
    $_vatSql = "SELECT rowid, code, taux, note, active, entity FROM " . MAIN_DB_PREFIX . "c_tva"
        . " WHERE entity IN (0, " . ((int) $conf->entity) . ")"
        . " AND fk_pays = " . $_vatPolandId
        . " ORDER BY taux DESC, code";
    $_vatRes = $db->query($_vatSql);
    while ($_vatRes && $_vatObj = $db->fetch_object($_vatRes)) {
        $_vatAllEntries[] = $_vatObj;
    }
}

print '<tr class="liste_titre" id="ksef-vat-codes-list">';
print '<td style="width:28px;">' . $langs->trans('KSEF_Enabled') . '</td>';
print '<td style="width:50px;">' . $langs->trans('Rate') . '</td>';
print '<td style="width:60px;">' . $langs->trans('KSEF_VAT_CODE_COLUMN') . '</td>';
print '<td>' . $langs->trans('KSEF_VAT_CODE_DESC_COLUMN') . '</td>';
print '</tr>';

foreach ($_vatAllEntries as $entry) {
    $codeUpper = strtoupper(trim($entry->code));
    $isKsefCode = isset($ksefVatMeta[$codeUpper]);
    $isGlobal = ((int) $entry->entity === 0);
    $isActive = !empty($entry->active);

    print '<tr class="oddeven ksef-vat-code-row">';

    if ($isGlobal) {
        print '<td class="center"><input type="checkbox" ' . ($isActive ? 'checked' : '') . ' disabled title="' . dol_escape_htmltag($langs->trans('KSEF_VAT_CODE_GLOBAL')) . '"></td>';
    } else {
        print '<td class="center"><input type="hidden" name="KSEF_VAT_ORIG_' . ((int) $entry->rowid) . '" value="' . ($isActive ? '1' : '0') . '">';
        print '<input type="checkbox" name="KSEF_VAT_TOGGLE_' . ((int) $entry->rowid) . '" value="1"' . ($isActive ? ' checked' : '') . '></td>';
    }

    print '<td>' . dol_escape_htmltag($entry->taux) . '%</td>';

    $codeDisplay = !empty($entry->code) ? $entry->code : '';
    print '<td><code style="font-size: inherit;">' . dol_escape_htmltag($codeDisplay) . '</code></td>';

    if ($isKsefCode) {
        print '<td>' . dol_escape_htmltag($ksefVatMeta[$codeUpper]['desc']) . '</td>';
    } else {
        print '<td class="opacitymedium">' . $langs->trans('VATRate') . ' ' . dol_escape_htmltag(vatrate($entry->taux, true)) . '</td>';
    }

    print '</tr>';
}

print '<tr class="oddeven"><td colspan="4">';
$dictUrl = DOL_URL_ROOT . '/admin/dict.php?id=10';
print '<a href="' . $dictUrl . '" target="_blank" class="small" id="ksef-vat-codes-dict">' . $langs->trans('KSEF_VAT_CODE_DICT_LINK') . '</a>';
print '</td></tr>';
print '</table>';

print '<br><table class="noborder centpercent">';
print '<tr class="liste_titre ksef-section-head"><td colspan="2">' . $langs->trans("KSEF_MULTICURRENCY_CONFIG") . ksefSectionDesc('KSEF_MULTICURRENCY_CONFIG_Desc') . '</td></tr>';
print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_NBP_RATE_MODE'), $langs->trans('KSEF_NBP_RATE_MODE_Help')) . '</td>';
print '<td>';
$nbp_modes = array(
    'keep_foreign' => $langs->trans('KSEF_NBP_RATE_MODE_KEEP_FOREIGN'),
    'keep_base' => $langs->trans('KSEF_NBP_RATE_MODE_KEEP_BASE'),
);
$current_mode = getDolGlobalString('KSEF_NBP_RATE_MODE', 'keep_foreign');
if (isModEnabled('multicurrency')) {
    print $form->selectarray('KSEF_NBP_RATE_MODE', $nbp_modes, $current_mode, 0, 0, 0, '', 0, 0, 0, '', 'minwidth300');
} else {
    print '<span class="opacitymedium">' . dol_escape_htmltag($nbp_modes[$current_mode] ?? $current_mode) . '</span> &middot; ' . ksefRequiresModuleText('MultiCurrency');
}
print '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_NBP_RATE_DATE_METHOD'), $langs->trans('KSEF_NBP_RATE_DATE_METHOD_Help')) . '</td>';
print '<td>';
$nbp_date_methods = array(
    'vat' => $langs->trans('KSEF_NBP_RATE_DATE_METHOD_VAT'),
    'income_tax' => $langs->trans('KSEF_NBP_RATE_DATE_METHOD_INCOME_TAX'),
);
$current_date_method = getDolGlobalString('KSEF_NBP_RATE_DATE_METHOD', 'vat');
if (isModEnabled('multicurrency')) {
    print $form->selectarray('KSEF_NBP_RATE_DATE_METHOD', $nbp_date_methods, $current_date_method, 0, 0, 0, '', 0, 0, 0, '', 'minwidth300');
} else {
    print '<span class="opacitymedium">' . dol_escape_htmltag($nbp_date_methods[$current_date_method] ?? $current_date_method) . '</span> &middot; ' . ksefRequiresModuleText('MultiCurrency');
}
$nbp_method_since = getDolGlobalInt('KSEF_NBP_RATE_DATE_METHOD_SINCE');
if ($nbp_method_since > 0) {
    print '<br><span class="opacitymedium small">' . $langs->trans('KSEF_NBP_RATE_DATE_METHOD_Since', dol_print_date($nbp_method_since, 'day')) . '</span>';
}
print '</td></tr>';

foreach (ksefFxSettingValues() as $fx_name => $fx_values) {
    print '<tr class="oddeven">';
    print '<td class="titlefield">' . $form->textwithpicto($langs->trans($fx_name), $langs->trans($fx_name . '_Help')) . '</td>';
    print '<td>';
    $fx_options = array();
    foreach ($fx_values as $fx_v) {
        $fx_options[$fx_v] = $langs->trans($fx_name . '_' . $fx_v);
    }
    $fx_current = getDolGlobalString($fx_name, $fx_values[0]);
    if (!isset($fx_options[$fx_current])) {
        $fx_current = $fx_values[0];
    }
    if (isModEnabled('multicurrency')) {
        print $form->selectarray($fx_name, $fx_options, $fx_current, 0, 0, 0, '', 0, 0, 0, '', 'minwidth300');
    } else {
        print '<span class="opacitymedium">' . dol_escape_htmltag($fx_options[$fx_current]) . '</span> &middot; ' . ksefRequiresModuleText('MultiCurrency');
    }
    print '</td></tr>';
}

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_NBP_SYNC_CURRENCIES'), $langs->trans('KSEF_NBP_SYNC_CURRENCIES_Help')) . '</td>';
print '<td>';
$nbp_curr_dict = array();
$nbp_dict_sql = "SELECT code FROM " . MAIN_DB_PREFIX . "multicurrency WHERE entity = " . ((int) $conf->entity) . " ORDER BY code";
$nbp_dict_res = $db->query($nbp_dict_sql);
if ($nbp_dict_res) {
    while ($nbp_dict_obj = $db->fetch_object($nbp_dict_res)) {
        $nbp_dict_code = strtoupper(trim($nbp_dict_obj->code));
        if ($nbp_dict_code !== '' && $nbp_dict_code !== 'PLN') $nbp_curr_dict[$nbp_dict_code] = $nbp_dict_code;
    }
}
$nbp_curr_selected = array();
$nbp_curr_raw = getDolGlobalString('KSEF_NBP_SYNC_CURRENCIES', '');
if ($nbp_curr_raw !== '') $nbp_curr_selected = array_filter(array_map('trim', explode(',', $nbp_curr_raw)));
// keep stale codes
foreach ($nbp_curr_selected as $nbp_stored_code) {
    if (!isset($nbp_curr_dict[$nbp_stored_code])) {
        $nbp_curr_dict[$nbp_stored_code] = $langs->trans('KSEF_NBP_SYNC_CURRENCY_NotInMulticurrency', $nbp_stored_code);
    }
}
if (isModEnabled('multicurrency')) {
    print $form->multiselectarray('KSEF_NBP_SYNC_CURRENCIES', $nbp_curr_dict, $nbp_curr_selected, 0, 0, 'minwidth300', 0, '0', '', '', $langs->trans('KSEF_NBP_SYNC_CURRENCIES_All'));
    if (!$nbp_curr_dict) {
        print '<div class="opacitymedium ksef-no-foreign-currency">' . $langs->trans('KSEF_NBP_NoForeignCurrency', $conf->currency)
            . ' <a href="' . DOL_URL_ROOT . '/admin/multicurrency.php">' . $langs->trans('KSEF_NBP_OpenCurrencies') . '</a></div>';
    }
} else {
    print '<span class="opacitymedium">' . dol_escape_htmltag($nbp_curr_raw !== '' ? $nbp_curr_raw : $langs->trans('KSEF_NBP_SYNC_CURRENCIES_All')) . '</span> &middot; ' . ksefRequiresModuleText('MultiCurrency');
}
print '</td></tr>';

$nbpJobs = ksefGetCronJobs($db);
print '<tr class="oddeven ksef-nbp-cron" data-applicable="' . (isModEnabled('multicurrency') ? 1 : 0) . '">';
print '<td class="titlefield">' . $langs->trans('KSEF_CronNbpJob') . '</td>';
if (!isModEnabled('multicurrency')) {
    print '<td><span class="opacitymedium">' . $langs->trans('KSEF_CronNotApplicable') . '</span> &middot; ' . ksefRequiresModuleText('MultiCurrency') . '</td>';
} else {
    print '<td>' . (isset($nbpJobs['cronSyncMulticurrencyRates']) ? ksefCronJobStatusHtml($nbpJobs['cronSyncMulticurrencyRates']) : '<span class="opacitymedium">' . $langs->trans('KSEF_CronJobMissing') . '</span>') . '</td>';
}
print '</tr>';

print '</table>';

print '</div>';

print '<div class="center" style="margin-top: 10px;">';
print '<input type="submit" class="button button-save" value="' . $langs->trans("Save") . '">';
print '</div>';
print '</form>';

print dol_get_fiche_end();

print ksefAdminDirtyGuardJs();
print ksefCollapsibleSectionsJs();
print ksefHelpFooter($db, $user);
llxFooter();
$db->close();
