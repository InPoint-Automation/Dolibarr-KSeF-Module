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
 * \file    admin/setup_auth.php
 * \ingroup ksef
 * \brief   KSEF authentication settings tab
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
require_once DOL_DOCUMENT_ROOT . '/core/lib/security.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
dol_include_once('/ksef/lib/ksef.lib.php');
dol_include_once('/ksef/lib/ksef_tour.lib.php');
dol_include_once('/ksef/class/ksef_client.class.php');

if (!$user->admin) {
    accessforbidden();
}

$langs->loadLangs(array("admin", "ksef@ksef"));

$action = GETPOST('action', 'aZ09');
$activeEnv = ksefGetEnvironment();
$allowedEnvs = array('TEST', 'DEMO', 'PRODUCTION');

$env = strtoupper(GETPOST('env', 'alpha'));
if (!in_array($env, $allowedEnvs)) {
    $env = $activeEnv;
}

/**
 * @brief Switch environment
 * @param DoliDB $db
 * @param string $newEnv TEST, DEMO, PRODUCTION
 * @return void
 */
function ksefAuthTabSwitchEnv($db, $newEnv)
{
    global $langs;
    if (ksefSetEnvironment($db, $newEnv) > 0) {
        setEventMessages($langs->trans('KSEF_SyncResetForEnvironment', ksefEnvironmentLabel($newEnv)), null, 'mesgs');
    }
    $r = ksefAuthReadiness($newEnv);
    if (!$r['token'] && !$r['cert']['configured']) {
        setEventMessages($langs->trans('KSEF_WARNING_ENV_NO_AUTH', ksefEnvironmentLabel($newEnv)), null, 'warnings');
    }
    setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
}

if ($action == 'update_env') {
    $newEnv = strtoupper(GETPOST('KSEF_ENVIRONMENT', 'alpha'));
    if (in_array($newEnv, $allowedEnvs)) {
        if ($newEnv === 'PRODUCTION' && $activeEnv !== 'PRODUCTION') {
            header("Location: " . $_SERVER["PHP_SELF"] . '?action=ask_production&token=' . newToken());
            exit;
        }
        ksefAuthTabSwitchEnv($db, $newEnv);
    }
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

if ($action == 'confirm_production' && GETPOST('confirm', 'alpha') == 'yes') {
    ksefAuthTabSwitchEnv($db, 'PRODUCTION');
    header("Location: " . $_SERVER["PHP_SELF"] . '#env-PRODUCTION');
    exit;
}

// credentials changed
if (in_array($action, array('update', 'upload_auth_cert', 'confirm_remove_auth_cert', 'confirm_remove_token'), true)) {
    dolibarr_del_const($db, 'KSEF_AUTH_TEST_' . $env, $conf->entity);
}

if ($action == 'update') {
    $error = 0;

    $auth_method = GETPOST('KSEF_AUTH_METHOD', 'alpha');
    if (in_array($auth_method, array('token', 'certificate'))) {
        dolibarr_set_const($db, 'KSEF_AUTH_METHOD_' . $env, $auth_method, 'chaine', 0, '', $conf->entity);
    }

    $new_token = GETPOST('KSEF_AUTH_TOKEN', 'password');
    if (!empty($new_token)) {
        $new_token = trim($new_token);
        if (strlen($new_token) < 50) {
            setEventMessages($langs->trans("KSEF_TOKEN_TOO_SHORT"), null, 'errors');
            $error++;
        } elseif (preg_match('/[\r\n\t]/', $new_token)) {
            setEventMessages($langs->trans("KSEF_TOKEN_INVALID_FORMAT"), null, 'errors');
            $error++;
        } else {
            ksefSetSecretConst($db, 'KSEF_AUTH_TOKEN_' . $env, dol_encode($new_token), $conf->entity);
            dolibarr_set_const($db, 'KSEF_TOKEN_UPDATED_AT_' . $env, dol_now(), 'chaine', 0, '', $conf->entity);
            setEventMessages($langs->trans("KSEF_TOKEN_SAVED"), null, 'mesgs');

            if (!ksefCheckAuthCertificate($env)['configured']) {
                dolibarr_set_const($db, 'KSEF_AUTH_METHOD_' . $env, 'token', 'chaine', 0, '', $conf->entity);
            }
        }
    }

    if (!$error) {
        setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
    }
    header("Location: " . $_SERVER["PHP_SELF"] . '#env-' . $env);
    exit;
}

if ($action == 'upload_auth_cert' || $action == 'upload_offline_cert') {
    $kind = ($action == 'upload_auth_cert') ? 'AUTH' : 'OFFLINE';
    $field = ($kind === 'AUTH') ? 'auth' : 'offline';
    $res = ksefStageCertificateUpload($db, $kind, $env, $field . '_cert_file', $field . '_key_file', GETPOST($field . '_key_password', 'none'));
    if ($res['errors']) {
        setEventMessages(null, $res['errors'], 'errors');
    }
    if ($res['warnings']) {
        setEventMessages(null, $res['warnings'], 'warnings');
    }
    if ($res['messages']) {
        setEventMessages(null, $res['messages'], 'mesgs');
    }
    if ($kind === 'OFFLINE' && !$res['errors']) {
        $usable = ksefOfflineCertUsable($env);
        if (!$usable['ok']) {
            setEventMessages($langs->trans('KSEF_OfflineCertNotReady', $usable['message']), null, 'errors');
        }
    }

    if ($kind === 'AUTH' && $res['committed'] && $res['complete']) {
        $has_token_now = !empty(getDolGlobalString('KSEF_AUTH_TOKEN_' . $env));
        $current_method = getDolGlobalString('KSEF_AUTH_METHOD_' . $env, 'token');
        if (!$has_token_now && $current_method != 'certificate') {
            dolibarr_set_const($db, 'KSEF_AUTH_METHOD_' . $env, 'certificate', 'chaine', 0, '', $conf->entity);
            setEventMessages($langs->trans("KSEF_AuthMethodAutoSwitchedToCert"), null, 'mesgs');
        }
    }

    header("Location: " . $_SERVER["PHP_SELF"] . '#env-' . $env);
    exit;
}

if ($action == 'confirm_remove_auth_cert' && GETPOST('confirm', 'alpha') == 'yes') {
    dolibarr_del_const($db, 'KSEF_AUTH_CERTIFICATE_' . $env, $conf->entity);
    dolibarr_del_const($db, 'KSEF_AUTH_PRIVATE_KEY_' . $env, $conf->entity);
    dolibarr_del_const($db, 'KSEF_AUTH_KEY_PASSWORD_' . $env, $conf->entity);
    dolibarr_del_const($db, 'KSEF_AUTH_CERT_SERIAL_' . $env, $conf->entity);
    dolibarr_del_const($db, 'KSEF_AUTH_CERT_VALID_FROM_' . $env, $conf->entity);
    dolibarr_del_const($db, 'KSEF_AUTH_CERT_VALID_TO_' . $env, $conf->entity);

    if (getDolGlobalString('KSEF_AUTH_METHOD_' . $env) == 'certificate' && getDolGlobalString('KSEF_AUTH_TOKEN_' . $env) !== '') {
        dolibarr_set_const($db, 'KSEF_AUTH_METHOD_' . $env, 'token', 'chaine', 0, '', $conf->entity);
    }

    setEventMessages($langs->trans("KSEF_AuthCertificateRemoved"), null, 'mesgs');
    header("Location: " . $_SERVER["PHP_SELF"] . '#env-' . $env);
    exit;
}

if ($action == 'confirm_remove_offline_cert' && GETPOST('confirm', 'alpha') == 'yes') {
    dolibarr_del_const($db, 'KSEF_OFFLINE_CERTIFICATE_' . $env, $conf->entity);
    dolibarr_del_const($db, 'KSEF_OFFLINE_PRIVATE_KEY_' . $env, $conf->entity);
    dolibarr_del_const($db, 'KSEF_OFFLINE_KEY_PASSWORD_' . $env, $conf->entity);
    dolibarr_del_const($db, 'KSEF_OFFLINE_CERT_SERIAL_' . $env, $conf->entity);
    dolibarr_del_const($db, 'KSEF_OFFLINE_CERT_VALID_FROM_' . $env, $conf->entity);
    dolibarr_del_const($db, 'KSEF_OFFLINE_CERT_VALID_TO_' . $env, $conf->entity);

    setEventMessages($langs->trans("KSEF_OfflineCertificateRemoved"), null, 'mesgs');
    header("Location: " . $_SERVER["PHP_SELF"] . '#env-' . $env);
    exit;
}

if ($action == 'confirm_remove_token' && GETPOST('confirm', 'alpha') == 'yes') {
    dolibarr_del_const($db, 'KSEF_AUTH_TOKEN_' . $env, $conf->entity);
    dolibarr_del_const($db, 'KSEF_TOKEN_UPDATED_AT_' . $env, $conf->entity);
    setEventMessages($langs->trans("KSEF_TokenRemoved", ksefEnvironmentLabel($env)), null, 'mesgs');
    $cert = ksefCheckAuthCertificate($env);
    if (getDolGlobalString('KSEF_AUTH_METHOD_' . $env, 'token') === 'token' && $cert['configured'] && $cert['valid']) {
        dolibarr_set_const($db, 'KSEF_AUTH_METHOD_' . $env, 'certificate', 'chaine', 0, '', $conf->entity);
        setEventMessages($langs->trans("KSEF_AuthMethodAutoSwitchedToCert"), null, 'mesgs');
    }
    header("Location: " . $_SERVER["PHP_SELF"] . '#env-' . $env);
    exit;
}

if ($action == 'confirm_remove_gus_key' && GETPOST('confirm', 'alpha') == 'yes') {
    dolibarr_del_const($db, 'KSEF_GUS_KEY', $conf->entity);
    setEventMessages($langs->trans("KSEF_GusKeyRemoved"), null, 'mesgs');
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

if (in_array($action, array('testconnection', 'testtokenauth', 'testcertauth'), true)) {
    $testEnv = strtoupper(GETPOST('testenv', 'alpha'));
    if (!in_array($testEnv, $allowedEnvs)) $testEnv = $activeEnv;
    $kinds = array('testconnection' => 'connection', 'testtokenauth' => 'token', 'testcertauth' => 'certificate');
    $test = ksefRunConnectionTest($db, $testEnv, $kinds[$action]);
    setEventMessages($test['message'], null, $test['ok'] ? 'mesgs' : 'errors');
    header("Location: " . $_SERVER["PHP_SELF"] . '#env-' . $testEnv);
    exit;
}

if ($action == 'update_gus') {
    $gusEnabled = GETPOST('KSEF_GUS_ENABLED', 'int') ? '1' : '0';

    $gusEnv = GETPOST('KSEF_GUS_ENV', 'alpha');
    if (!in_array($gusEnv, array('TEST', 'PROD'))) {
        $gusEnv = 'TEST';
    }

    if ($gusEnabled === '1' && !ksefTranslationOverridesReady($db)) {
        $gusEnabled = '0';
        setEventMessages($langs->trans("KSEF_GusSetupRequiredBanner", ksefTranslationLanguagesLabel($db)), null, 'warnings');
    }

    $gusNameStyle = GETPOST('KSEF_GUS_NAME_STYLE', 'alpha');
    if (!in_array($gusNameStyle, array('full', 'upper', 'mixed'))) {
        $gusNameStyle = 'full';
    }

    dolibarr_set_const($db, 'KSEF_GUS_ENABLED', $gusEnabled, 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'KSEF_GUS_ENV', $gusEnv, 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'KSEF_GUS_NAME_STYLE', $gusNameStyle, 'chaine', 0, '', $conf->entity);

    $gusKey = GETPOST('KSEF_GUS_KEY', 'password');
    if ($gusKey !== '') {
        dolibarr_set_const($db, 'KSEF_GUS_KEY', dol_encode(trim($gusKey)), 'chaine', 0, '', $conf->entity);
    }

    setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
    header("Location: " . $_SERVER["PHP_SELF"] . '#env-GUS');
    exit;
}

if ($action == 'testgusconnection') {
    dol_include_once('/ksef/class/ksef_gus_client.class.php');
    try {
        $gusClient = new KsefGusClient($db, getDolGlobalString('KSEF_GUS_ENV', 'TEST'));
        if ($gusClient->login()) {
            $gusClient->logout();
            setEventMessages($langs->trans("KSEF_GusTestOk"), null, 'mesgs');
        } else {
            dol_syslog('ksef setup_auth: GUS test failed: ' . $gusClient->error, LOG_WARNING);
            setEventMessages($langs->trans("KSEF_GusTestFail", ksefGusErrorText($langs, (string) $gusClient->error)), null, 'errors');
        }
    } catch (Exception $e) {
        setEventMessages($langs->trans("KSEF_GusTestFail", $e->getMessage()), null, 'errors');
    }
    header("Location: " . $_SERVER["PHP_SELF"] . '#env-GUS');
    exit;
}

$form = new Form($db);
$page_name = "KSEF_Setup";

llxHeader('', $langs->trans($page_name), ksefHelpUrl('quickstart'));

$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1">' . $langs->trans("KSEF_BackToModuleList") . '</a>';
print load_fiche_titre($langs->trans($page_name), ksefHelpTitleButton($user, 'auth', array('quickstart' => 'KSEF_QuickStartGuide'), array('setup_env', 'setup_auth')) . $linkback, 'title_setup');

$head = ksefAdminPrepareHead();
print dol_get_fiche_head($head, 'auth', $langs->trans("KSEF_Module"), -1, 'ksef@ksef');

if ($action == 'ask_production') {
    $prodAuth = ksefAuthReadiness('PRODUCTION');
    $nipNow = ksefSellerNip();
    $question = $langs->trans('KSEF_ConfirmProductionQuestion', dol_escape_htmltag($nipNow !== '' ? $nipNow : '-'));
    if (!ksefValidateNIP($nipNow)) {
        $question .= '<br><span class="error">' . $langs->trans('KSEF_ConfirmProductionNipInvalid') . '</span>';
    }
    $question .= '<br>' . ($prodAuth['ready'] ? $langs->trans('KSEF_ConfirmProductionAuthReady', $langs->transnoentitiesnoconv($prodAuth['method'] === 'certificate' ? 'KSEF_AUTH_METHOD_CERTIFICATE' : 'KSEF_AUTH_METHOD_TOKEN'))
        : '<span class="warning">' . $langs->trans('KSEF_ConfirmProductionNoAuth') . '</span>');
    print $form->formconfirm($_SERVER["PHP_SELF"], $langs->trans('KSEF_ConfirmProductionTitle'), $question, 'confirm_production', '', 0, 2, 'auto', 600);
} elseif ($action == 'ask_remove_token' && getDolGlobalString('KSEF_AUTH_TOKEN_' . $env) !== '') {
    print $form->formconfirm($_SERVER["PHP_SELF"] . '?env=' . $env, $langs->trans('KSEF_RemoveToken'), $langs->trans('KSEF_ConfirmRemoveToken', ksefEnvironmentLabel($env)), 'confirm_remove_token', '', 0, 1, 'auto', 550);
} elseif ($action == 'ask_remove_auth_cert') {
    print $form->formconfirm($_SERVER["PHP_SELF"] . '?env=' . $env, $langs->trans('KSEF_RemoveAll') . ' (' . ksefEnvironmentLabel($env) . ')', $langs->trans('KSEF_ConfirmRemoveAuthCert'), 'confirm_remove_auth_cert', '', 0, 1, 'auto', 550);
} elseif ($action == 'ask_remove_offline_cert') {
    print $form->formconfirm($_SERVER["PHP_SELF"] . '?env=' . $env, $langs->trans('KSEF_RemoveAll') . ' (' . ksefEnvironmentLabel($env) . ')', $langs->trans('KSEF_ConfirmRemoveOfflineCert'), 'confirm_remove_offline_cert', '', 0, 1, 'auto', 550);
} elseif ($action == 'ask_remove_gus_key' && getDolGlobalString('KSEF_GUS_KEY') !== '') {
    print $form->formconfirm($_SERVER["PHP_SELF"], $langs->trans('KSEF_RemoveGusKey'), $langs->trans('KSEF_ConfirmRemoveGusKey'), 'confirm_remove_gus_key', '', 0, 1, 'auto', 550);
}

echo ksefShowAdminBanners();

$warnings = ksefGetConfigWarnings();
if (!empty($warnings)) {
    echo ksefRenderConfigWarnings($warnings, 'auth');
}

$gusReady = ksefTranslationOverridesReady($db);
if (!$gusReady) {
    print '<div class="warning" style="margin-bottom: 15px;">' . img_warning() . ' ' . $langs->trans("KSEF_GusSetupRequiredBanner", ksefTranslationLanguagesLabel($db));
    print ' <a href="' . dol_buildpath('/ksef/admin/setup_dictionaries.php', 1) . '#trans_lang_pl_PL">' . $langs->trans("KSEF_GusRunSetup") . '</a>';
    print '</div>';
}

print '<div style="margin-bottom: 15px;">';
print '<span>' . $langs->trans("KSEF_AuthPageInfo") . '</span>';
print '</div>';

print '<form id="ksef-env-form" method="POST" action="' . $_SERVER["PHP_SELF"] . '" style="margin-bottom: 15px;" data-ksef-guarded="1">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="update_env">';
print '<div class="ksef-settings-box">';
print '<table class="noborder centpercent">';
print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans("KSEF_ENVIRONMENT"), $langs->trans("KSEF_ENVIRONMENT_Help")) . '</td>';
print '<td>';
$array_env = array(
    'TEST' => $langs->trans('KSEF_ENV_TEST') . ' (api-test.ksef.mf.gov.pl)',
    'DEMO' => $langs->trans('KSEF_ENV_DEMO') . ' (api-demo.ksef.mf.gov.pl)',
    'PRODUCTION' => $langs->trans('KSEF_ENV_PRODUCTION') . ' (api.ksef.mf.gov.pl)'
);
print $form->selectarray('KSEF_ENVIRONMENT', $array_env, $activeEnv, 0, 0, 0, '', 0, 0, 0, '', 'minwidth300 maxwidth250onsmartphone');

print '<div id="auth_production_warning" class="warning" style="margin-top: 10px; padding: 8px 12px; display: ' . ($activeEnv == 'PRODUCTION' ? 'block' : 'none') . ';">';
print '<i class="fa fa-exclamation-triangle"></i> ' . $langs->trans("KSEF_PRODUCTION_WARNING");
print '</div>';

print '<script type="text/javascript">
$(document).ready(function() {
    $("#KSEF_ENVIRONMENT").on("change", function() {
        if ($(this).val() == "PRODUCTION") {
            $("#auth_production_warning").show();
        } else {
            $("#auth_production_warning").hide();
        }
    });
});
</script>';

print '</td></tr>';
print '</table>';
print '</div>';
print '<div class="center" style="margin-top: 10px;">';
print '<input type="submit" class="button button-save" value="' . $langs->trans("Save") . '">';
print '</div>';
print '</form>';

$environments = array('TEST', 'DEMO', 'PRODUCTION');

foreach ($environments as $envName) {
    $isActive = ($envName === $activeEnv);

    $certCheck = ksefCheckAuthCertificate($envName);
    $offlineCheck = ksefIsOfflineCertificateConfigured($envName);
    $has_token = !empty(getDolGlobalString('KSEF_AUTH_TOKEN_' . $envName));
    $has_auth_cert = $certCheck['configured'];
    $current_auth_method = getDolGlobalString('KSEF_AUTH_METHOD_' . $envName, '');
    $no_auth_configured = (!$has_token && !$has_auth_cert);

    $tokenBadge = $has_token
        ? '<span class="badge badge-status4 badge-status">' . $langs->trans('KSEF_EnvStatusToken') . ' <i class="fa fa-check-circle"></i></span>'
        : '<span class="badge badge-status8 badge-status">' . $langs->trans('KSEF_EnvStatusToken') . ' <i class="fa fa-times-circle"></i></span>';

    if ($has_auth_cert) {
        $certBadge = ($certCheck['configured'] && !$certCheck['valid'])
            ? '<span class="badge badge-status1 badge-status">' . $langs->trans('KSEF_EnvStatusCert') . ' <i class="fa fa-times-circle"></i> ' . $langs->trans("KSEF_Expired") . '</span>'
            : '<span class="badge badge-status4 badge-status">' . $langs->trans('KSEF_EnvStatusCert') . ' <i class="fa fa-check-circle"></i></span>';
    } else {
        $certBadge = '<span class="badge badge-status8 badge-status">' . $langs->trans('KSEF_EnvStatusCert') . ' <i class="fa fa-times-circle"></i></span>';
    }

    $has_offline_cert_summary = $offlineCheck['configured'];
    if ($has_offline_cert_summary && empty($offlineCheck['valid'])) {
        $offlineBadge = '<span class="badge badge-status1 badge-status">' . $langs->trans('KSEF_EnvStatusOffline') . ' <i class="fa fa-times-circle"></i> ' . $langs->trans("KSEF_Expired") . '</span>';
    } else {
        $offlineBadge = $has_offline_cert_summary
            ? '<span class="badge badge-status4 badge-status">' . $langs->trans('KSEF_EnvStatusOffline') . ' <i class="fa fa-check-circle"></i></span>'
            : '<span class="badge badge-status8 badge-status">' . $langs->trans('KSEF_EnvStatusOffline') . ' <i class="fa fa-times-circle"></i></span>';
    }

    $methodBadge = '';
    if (!empty($current_auth_method)) {
        $methodLabels = array('token' => 'KSEF_EnvStatusToken', 'certificate' => 'KSEF_EnvStatusCert');
        $methodText = isset($methodLabels[$current_auth_method]) ? $langs->trans($methodLabels[$current_auth_method]) : $current_auth_method;
        $methodBadge = '<span class="badge badge-status6 badge-status">' . $langs->trans('KSEF_EnvStatusMethod') . ': ' . dol_escape_htmltag($methodText) . '</span>';
    }

    $borderColor = $isActive ? '#28a745' : '#dee2e6';
    $borderWidth = $isActive ? '3px' : '1px';
    print '<div id="env-' . $envName . '"' . ($isActive ? ' data-ksef-active="1"' : '') . ' style="border: ' . $borderWidth . ' solid ' . $borderColor . '; border-radius: 6px; margin-bottom: 15px; overflow: hidden;">';
    print '<div class="ksef-env-head" aria-expanded="false" onclick="ksefToggleEnv(\'' . $envName . '\')" style="cursor: pointer; padding: 12px 15px; background: ' . ($isActive ? 'var(--ksef-surface-ok, #f0fff0)' : 'var(--ksef-surface, #f8f9fa)') . '; display: flex; justify-content: space-between; align-items: center;">';
    print '<div>';
    print '<i id="ksef_env_toggle_' . $envName . '" class="fa fa-chevron-right" style="margin-right: 8px;"></i>';
    print '<strong>' . $langs->trans('KSEF_EnvSection_' . $envName) . '</strong>';
    if ($isActive) {
        print ' <span class="badge badge-status4 badge-status">' . $langs->trans('KSEF_EnvSection_Active') . '</span>';
    }
    print ksefSectionDesc('KSEF_EnvSection_' . $envName . '_Desc');
    print '</div>';
    print '</div>';

    print '<div class="ksef-env-badges" style="padding: 8px 15px; background: ' . ($isActive ? 'var(--ksef-surface-ok, #f8fff8)' : 'var(--ksef-surface-card, #fdfdfd)') . '; border-top: 1px solid var(--ksef-surface-border, #eee); display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">';
    print $tokenBadge . ' ' . $certBadge . ' ' . $offlineBadge;
    if (!empty($methodBadge)) {
        print ' ' . $methodBadge;
    }
    if ($no_auth_configured) {
        print ' <span class="badge badge-status1 badge-status"><i class="fa fa-exclamation-triangle"></i> ' . $langs->trans("KSEF_NO_AUTH_CONFIGURED") . '</span>';
    }
    print '</div>';

    print '<div id="ksef_env_body_' . $envName . '" style="padding: 15px; display: none;">';

    print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '" data-ksef-guarded="1">';
    print '<input type="hidden" name="token" value="' . newToken() . '">';
    print '<input type="hidden" name="action" value="update">';
    print '<input type="hidden" name="env" value="' . $envName . '">';

    print '<div class="ksef-settings-box">';

    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre"><td colspan="2">' . $langs->trans("KSEF_AUTH_METHOD_SELECT") . '</td></tr>';
    print '<tr class="oddeven"><td colspan="2">';
    if ($no_auth_configured) {
        print '<div class="warning ksef-no-auth">' . img_warning() . ' <strong>' . $langs->trans("KSEF_NO_AUTH_CONFIGURED") . '.</strong> ' . $langs->trans("KSEF_ConfigureAuthBelow") . '</div>';
    }
    print '<div class="ksef-auth-methods" style="display: flex; gap: 20px; padding: 10px 0; flex-wrap: wrap;">';

    $token_selected = ($current_auth_method == 'token' && $has_token)
        || (empty($current_auth_method) && $has_token);
    $token_border_color = $token_selected ? '#28a745' : ($has_token ? '#17a2b8' : '#dee2e6');
    $token_bg_color = $token_selected ? 'var(--ksef-surface-ok, #f8fff8)' : 'var(--ksef-surface-card, #fff)';

    print '<div style="flex: 1; min-width: 250px; padding: 15px; border: 2px solid ' . $token_border_color . '; border-radius: 8px; background: ' . $token_bg_color . ';">';
    print '<label style="display: flex; align-items: center; cursor: pointer; margin-bottom: 10px;">';
    print '<input type="radio" name="KSEF_AUTH_METHOD" value="token" ' . ($token_selected ? 'checked' : '') . ' ' . (!$has_token ? 'disabled' : '') . ' style="margin-right: 10px;">';
    print '<strong>' . $langs->trans("KSEF_AUTH_METHOD_TOKEN") . '</strong>';
    if ($token_selected && $has_token) {
        print ' <span class="badge badge-success" style="margin-left: 10px;">' . $langs->trans("KSEF_Active") . '</span>';
    }
    print '</label>';
    print '<div style="margin-left: 24px;">';
    if ($has_token) {
        print '<span class="badge badge-status4"><i class="fa fa-check-circle"></i> ' . $langs->trans("KSEF_TokenConfigured") . '</span>';
        $token_updated = getDolGlobalString('KSEF_TOKEN_UPDATED_AT_' . $envName, '');
        if (!empty($token_updated)) {
            print '<br><small>' . $langs->trans("KSEF_Updated") . ': ' . dol_print_date($token_updated, 'dayhour') . '</small>';
        }
    } else {
        print '<span class="badge badge-warning"><i class="fa fa-exclamation-triangle"></i> ' . $langs->trans("KSEF_TokenNotConfigured") . '</span>';
        print '<br><small>' . $langs->trans("KSEF_ConfigureTokenBelow") . '</small>';
    }
    print '</div></div>';

    $cert_selected = ($current_auth_method == 'certificate' && $has_auth_cert)
        || (empty($current_auth_method) && $has_auth_cert && !$has_token);
    $cert_border_color = $cert_selected ? '#28a745' : ($has_auth_cert ? '#17a2b8' : '#dee2e6');
    $cert_bg_color = $cert_selected ? 'var(--ksef-surface-ok, #f8fff8)' : 'var(--ksef-surface-card, #fff)';

    print '<div style="flex: 1; min-width: 250px; padding: 15px; border: 2px solid ' . $cert_border_color . '; border-radius: 8px; background: ' . $cert_bg_color . ';">';
    print '<label style="display: flex; align-items: center; cursor: pointer; margin-bottom: 10px;">';
    print '<input type="radio" name="KSEF_AUTH_METHOD" value="certificate" ' . ($cert_selected ? 'checked' : '') . ' ' . (!$has_auth_cert ? 'disabled title="' . $langs->trans("KSEF_ConfigureCertificateFirst") . '"' : '') . ' style="margin-right: 10px;">';
    print '<strong>' . $langs->trans("KSEF_AUTH_METHOD_CERTIFICATE") . '</strong>';
    if ($cert_selected && $has_auth_cert) {
        print ' <span class="badge badge-success" style="margin-left: 10px;">' . $langs->trans("KSEF_Active") . '</span>';
    }
    print '</label>';
    print '<div style="margin-left: 24px;">';
    if ($has_auth_cert) {
        $cert_valid_to = getDolGlobalString('KSEF_AUTH_CERT_VALID_TO_' . $envName, '');
        $is_expired = (!empty($cert_valid_to) && $cert_valid_to < dol_now());
        if ($is_expired) {
            print '<span class="badge badge-danger"><i class="fa fa-times-circle"></i> ' . $langs->trans("KSEF_CertificateExpired") . '</span>';
        } else {
            print '<span class="badge badge-status4"><i class="fa fa-check-circle"></i> ' . $langs->trans("KSEF_CertificateConfigured") . '</span>';
        }
        if (!empty($cert_valid_to)) {
            print '<br><small>' . $langs->trans("KSEF_ValidUntil") . ': ' . dol_print_date($cert_valid_to, 'day') . '</small>';
        }
    } else {
        print '<span class="badge badge-secondary"><i class="fa fa-certificate"></i> ' . $langs->trans("KSEF_CertificateNotConfigured") . '</span>';
        print '<br><small>' . $langs->trans("KSEF_ConfigureCertificateBelow") . '</small>';
    }
    print '</div></div>';

    print '</div>';
    print '</td></tr>';
    print '</table>';

    print '<br><table class="noborder centpercent ksef-stack-phone">';
    print '<tr class="liste_titre"><td colspan="2">' . $langs->trans("KSEF_TOKEN_CONFIG") . '</td></tr>';
    print '<tr class="oddeven">';
    print '<td>' . $form->textwithpicto($langs->trans("KSEF_AUTH_TOKEN"), $langs->trans("KSEF_AUTH_TOKEN_Help")) . '</td>';
    print '<td>';
    if ($has_token) {
        print '<div style="margin-bottom: 10px; display: flex; flex-wrap: wrap; gap: 6px; align-items: center;">';
        print '<span class="badge badge-status4"><i class="fa fa-check-circle"></i> ' . $langs->trans("KSEF_TokenConfigured") . '</span>';
        print ' <a class="button button-cancel small" href="' . $_SERVER["PHP_SELF"] . '?action=ask_remove_token&env=' . $envName . '&token=' . newToken() . '"><i class="fa fa-trash"></i> ' . $langs->trans('KSEF_RemoveToken') . '</a>';
        print '</div>';
        print '<strong>' . $langs->trans("KSEF_UpdateToken") . ':</strong><br>';
    }
    print '<input type="password" class="flat minwidth300imp widthcentpercentminusx" name="KSEF_AUTH_TOKEN" value="" placeholder="' . ($has_token ? $langs->trans("KSEF_EnterNewTokenToReplace") : $langs->trans("KSEF_PasteTokenHere")) . '" autocomplete="new-password">';
    print '<br><span>' . $langs->trans("KSEF_TOKEN_OBTAIN_INFO") . '</span>';
    print '</td></tr>';
    print '</table>';
    print '</div>';

    print '<div class="center" style="margin-top: 10px;">';
    print '<input type="submit" class="button button-save" value="' . $langs->trans("Save") . '">';
    print '</div>';
    print '</form>';
    print '<div style="display: flex; flex-wrap: wrap; gap: 20px; align-items: flex-start; margin-top: 20px;">';

    print '<div class="minwidth400imp" style="flex: 1;">';
    print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '" enctype="multipart/form-data" data-ksef-guarded="1">';
    print '<input type="hidden" name="token" value="' . newToken() . '">';
    print '<input type="hidden" name="action" value="upload_auth_cert">';
    print '<input type="hidden" name="env" value="' . $envName . '">';

    print '<div class="ksef-settings-box">';
    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre"><td colspan="2">' . $langs->trans("KSEF_AUTH_CERTIFICATE_CONFIG") . ' <small>(' . $langs->trans("KSEF_ForAPIAuthentication") . ')</small></td></tr>';
    print '<tr class="oddeven"><td colspan="2">';

    if ($has_auth_cert) {
        $cert_valid_to = getDolGlobalString('KSEF_AUTH_CERT_VALID_TO_' . $envName, '');
        $is_expired = (!empty($cert_valid_to) && $cert_valid_to < dol_now());

        print '<div style="padding: 15px; border-radius: 4px; margin-bottom: 15px; ';
        print 'background-color: ' . ($is_expired ? 'var(--ksef-surface-bad, #f8d7da)' : 'var(--ksef-surface-good, #d4edda)') . '; ';
        print 'border: 1px solid ' . ($is_expired ? 'var(--ksef-border-bad, #f5c6cb)' : 'var(--ksef-border-good, #c3e6cb)') . ';">';

        print '<table class="nobordernopadding">';
        print '<tr><td><i class="fa fa-check text-success"></i></td><td>' . $langs->trans("KSEF_Certificate") . ' (.crt)</td></tr>';
        print '<tr><td><i class="fa fa-' . (!empty(getDolGlobalString('KSEF_AUTH_PRIVATE_KEY_' . $envName)) ? 'check text-success' : 'times text-danger') . '"></i></td><td>' . $langs->trans("KSEF_PrivateKey") . ' (.key)</td></tr>';
        print '<tr><td><i class="fa fa-' . (!empty(getDolGlobalString('KSEF_AUTH_KEY_PASSWORD_' . $envName)) ? 'check text-success' : 'minus opacitymedium') . '"></i></td><td>' . $langs->trans(!empty(getDolGlobalString('KSEF_AUTH_KEY_PASSWORD_' . $envName)) ? "KSEF_Password" : "KSEF_KeyWithoutPassword") . '</td></tr>';
        print '</table>';

        $cert_serial = getDolGlobalString('KSEF_AUTH_CERT_SERIAL_' . $envName, '');
        if (!empty($cert_serial)) {
            print '<br><strong>' . $langs->trans("KSEF_CertSerial") . ':</strong> ' . dol_escape_htmltag(substr($cert_serial, 0, 24)) . '...';
        }
        if (!empty($cert_valid_to)) {
            print '<br><strong>' . $langs->trans("KSEF_ValidUntil") . ':</strong> ' . dol_print_date($cert_valid_to, 'day');
            if ($is_expired) print ' <span class="badge badge-danger">' . $langs->trans("KSEF_Expired") . '</span>';
        }

        print '<br><br><a class="button button-cancel small" href="' . $_SERVER["PHP_SELF"] . '?action=ask_remove_auth_cert&env=' . $envName . '&token=' . newToken() . '">';
        print '<i class="fa fa-trash"></i> ' . $langs->trans("KSEF_RemoveAll") . '</a>';
        print '</div>';
    }

    print '<div style="background: var(--ksef-surface, #f8f9fa); padding: 15px; border-radius: 4px;">';
    print '<div style="margin-bottom: 15px;">';
    print '<label><strong>' . $langs->trans("KSEF_CertificateFile") . ' (.crt):</strong></label><br>';
    print '<input type="file" name="auth_cert_file" accept=".crt,.pem,.cer" class="flat maxwidth250onsmartphone">';
    print '</div>';
    print '<div style="margin-bottom: 15px;">';
    print '<label><strong>' . $langs->trans("KSEF_PrivateKeyFile") . ' (.key):</strong></label><br>';
    print '<input type="file" name="auth_key_file" accept=".key,.pem" class="flat maxwidth250onsmartphone">';
    print '</div>';
    print '<div style="margin-bottom: 15px;">';
    print '<label><strong>' . $langs->trans("KSEF_PrivateKeyPassword") . ':</strong></label><br>';
    print '<input type="password" name="auth_key_password" class="flat minwidth200" placeholder="' . $langs->trans("KSEF_EnterPassword") . '" autocomplete="new-password">';
    print '</div>';
    print '<div class="opacitymedium small">' . $langs->trans("KSEF_CertUploadHint") . '</div>';
    print '</div>';

    print '</td></tr>';
    print '</table>';
    print '</div>';
    print '<div class="center" style="margin-top: 10px;">';
    print '<input type="submit" class="button" value="' . $langs->trans("KSEF_SaveAuthCertificate") . '">';
    print '</div>';
    print '</form>';
    print '</div>';

    print '<div class="minwidth400imp" style="flex: 1;">';

    $has_offline_cert = $offlineCheck['configured'];

    print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '" enctype="multipart/form-data" data-ksef-guarded="1">';
    print '<input type="hidden" name="token" value="' . newToken() . '">';
    print '<input type="hidden" name="action" value="upload_offline_cert">';
    print '<input type="hidden" name="env" value="' . $envName . '">';

    print '<div class="ksef-settings-box">';
    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre"><td colspan="2">' . $langs->trans("KSEF_OFFLINE_CERTIFICATE_CONFIG") . ' <small>(' . $langs->trans("KSEF_ForQRCodeSigning") . ')</small></td></tr>';
    print '<tr class="oddeven"><td colspan="2">';

    if ($has_offline_cert) {
        $cert_valid_to = getDolGlobalString('KSEF_OFFLINE_CERT_VALID_TO_' . $envName, '');
        $is_expired = (!empty($cert_valid_to) && $cert_valid_to < dol_now());

        print '<div style="padding: 15px; border-radius: 4px; margin-bottom: 15px; ';
        print 'background-color: ' . ($is_expired ? 'var(--ksef-surface-bad, #f8d7da)' : 'var(--ksef-surface-good, #d4edda)') . '; ';
        print 'border: 1px solid ' . ($is_expired ? 'var(--ksef-border-bad, #f5c6cb)' : 'var(--ksef-border-good, #c3e6cb)') . ';">';

        print '<table class="nobordernopadding">';
        print '<tr><td><i class="fa fa-check text-success"></i></td><td>' . $langs->trans("KSEF_Certificate") . ' (.crt)</td></tr>';
        print '<tr><td><i class="fa fa-' . (!empty(getDolGlobalString('KSEF_OFFLINE_PRIVATE_KEY_' . $envName)) ? 'check text-success' : 'times text-danger') . '"></i></td><td>' . $langs->trans("KSEF_PrivateKey") . ' (.key)</td></tr>';
        print '<tr><td><i class="fa fa-' . (!empty(getDolGlobalString('KSEF_OFFLINE_KEY_PASSWORD_' . $envName)) ? 'check text-success' : 'minus opacitymedium') . '"></i></td><td>' . $langs->trans(!empty(getDolGlobalString('KSEF_OFFLINE_KEY_PASSWORD_' . $envName)) ? "KSEF_Password" : "KSEF_KeyWithoutPassword") . '</td></tr>';
        print '</table>';

        $cert_serial = getDolGlobalString('KSEF_OFFLINE_CERT_SERIAL_' . $envName, '');
        if (!empty($cert_serial)) {
            print '<br><strong>' . $langs->trans("KSEF_CertSerial") . ':</strong> ' . dol_escape_htmltag(substr($cert_serial, 0, 24)) . '...';
        }
        if (!empty($cert_valid_to)) {
            print '<br><strong>' . $langs->trans("KSEF_ValidUntil") . ':</strong> ' . dol_print_date($cert_valid_to, 'day');
            if ($is_expired) print ' <span class="badge badge-danger">' . $langs->trans("KSEF_Expired") . '</span>';
        }

        print '<br><br><a class="button button-cancel small" href="' . $_SERVER["PHP_SELF"] . '?action=ask_remove_offline_cert&env=' . $envName . '&token=' . newToken() . '">';
        print '<i class="fa fa-trash"></i> ' . $langs->trans("KSEF_RemoveAll") . '</a>';
        print '</div>';
    }

    print '<div style="background: var(--ksef-surface, #f8f9fa); padding: 15px; border-radius: 4px;">';
    print '<div style="margin-bottom: 15px;">';
    print '<label><strong>' . $langs->trans("KSEF_CertificateFile") . ' (.crt):</strong></label><br>';
    print '<input type="file" name="offline_cert_file" accept=".crt,.pem,.cer" class="flat maxwidth250onsmartphone">';
    print '</div>';
    print '<div style="margin-bottom: 15px;">';
    print '<label><strong>' . $langs->trans("KSEF_PrivateKeyFile") . ' (.key):</strong></label><br>';
    print '<input type="file" name="offline_key_file" accept=".key,.pem" class="flat maxwidth250onsmartphone">';
    print '</div>';
    print '<div style="margin-bottom: 15px;">';
    print '<label><strong>' . $langs->trans("KSEF_PrivateKeyPassword") . ':</strong></label><br>';
    print '<input type="password" name="offline_key_password" class="flat minwidth200" placeholder="' . $langs->trans("KSEF_EnterPassword") . '" autocomplete="new-password">';
    print '</div>';
    print '<div class="opacitymedium small">' . $langs->trans("KSEF_CertUploadHint") . '</div>';
    $portal = ksefTaxpayerAppUrl($envName);
    print '<div class="small ksef-offline-source" style="margin-top: 8px;">' . str_replace(dol_escape_htmltag($portal), '<a href="' . dol_escape_htmltag($portal) . '" target="_blank" rel="noopener">' . dol_escape_htmltag($portal) . '</a>', dol_escape_htmltag(ksefOfflineCertSourceText($envName))) . '</div>';
    print '</div>';

    print '</td></tr>';
    print '</table>';
    print '</div>';
    print '<div class="center" style="margin-top: 10px;">';
    print '<input type="submit" class="button" value="' . $langs->trans("KSEF_SaveOfflineCertificate") . '">';
    print '</div>';
    print '</form>';
    print '</div>';

    print '</div>';

    if (ksefSellerNip() !== '') {
        print '<div class="tabsAction" style="margin-top: 15px; padding-top: 10px; border-top: 1px solid var(--ksef-surface-border, #ddd);">';
        print '<a class="butAction" href="' . $_SERVER["PHP_SELF"] . '?action=testconnection&testenv=' . $envName . '&token=' . newToken() . '">' . $langs->trans("KSEF_TEST_CONNECTION") . '</a>';
        if ($has_token) {
            print '<a class="butAction' . ($token_selected ? ' ksef-test-active' : '') . '" href="' . $_SERVER["PHP_SELF"] . '?action=testtokenauth&testenv=' . $envName . '&token=' . newToken() . '">' . $langs->trans("KSEF_TEST_TOKEN_AUTH") . '</a>';
        }
        if ($has_auth_cert) {
            print '<a class="butAction' . ($cert_selected ? ' ksef-test-active' : '') . '" href="' . $_SERVER["PHP_SELF"] . '?action=testcertauth&testenv=' . $envName . '&token=' . newToken() . '">' . $langs->trans("KSEF_TEST_CERT_AUTH") . '</a>';
        }
        print '</div>';
    }

    print '</div>';
    print '</div>';
}

$gusEnabled = getDolGlobalString('KSEF_GUS_ENABLED');
$gusEnv = getDolGlobalString('KSEF_GUS_ENV', 'TEST');
$gusHasKey = !empty(getDolGlobalString('KSEF_GUS_KEY'));
$gusNameStyle = getDolGlobalString('KSEF_GUS_NAME_STYLE', 'full');
$gusOpen = false;

print '<div id="env-GUS" style="border: 1px solid var(--ksef-surface-border, #dee2e6); border-radius: 6px; margin-bottom: 15px; overflow: hidden;">';
print '<div class="ksef-env-head" aria-expanded="' . ($gusOpen ? 'true' : 'false') . '" onclick="ksefToggleEnv(\'GUS\')" style="cursor: pointer; padding: 12px 15px; background: var(--ksef-surface, #f8f9fa); display: flex; justify-content: space-between; align-items: center;">';
print '<div>';
print '<i id="ksef_env_toggle_GUS" class="fa fa-chevron-' . ($gusOpen ? 'down' : 'right') . '" style="margin-right: 8px;"></i>';
print '<strong>' . $langs->trans('KSEF_GusSectionTitle') . '</strong>';
if ($gusEnabled) {
    print ' <span class="badge badge-status4 badge-status">' . $langs->trans('Enabled') . '</span>';
} else {
    print ' <span class="badge badge-status8 badge-status">' . $langs->trans('Disabled') . '</span>';
}
print ksefSectionDesc('KSEF_GusSectionTitle_Desc');
print '</div>';
print '</div>';

print '<div id="ksef_env_body_GUS" style="padding: 15px; ' . ($gusOpen ? '' : 'display: none;') . '">';

print '<div style="margin-bottom: 10px;"><span class="opacitymedium">' . $langs->trans("KSEF_GusSectionDesc") . '</span></div>';

if (!$gusHasKey) {
    print '<div style="padding: 8px 12px; background: var(--ksef-surface-info, #eef4ff); border: 1px solid var(--ksef-border-info, #cddffb); border-radius: 4px; margin-bottom: 10px;">';
    print '<i class="fa fa-info-circle"></i> ' . $langs->trans("KSEF_GusNoKeyBanner");
    print '</div>';

    print '<div style="border: 1px solid var(--ksef-surface-border, #dee2e6); border-radius: 6px; margin-bottom: 15px; overflow: hidden;">';
    print '<div onclick="ksefToggleEnv(\'GUSKEY\')" style="cursor: pointer; padding: 10px 12px; background: var(--ksef-surface, #f8f9fa); display: flex; align-items: center;">';
    print '<i id="ksef_env_toggle_GUSKEY" class="fa fa-chevron-right" style="margin-right: 8px;"></i>';
    print '<strong>' . $langs->trans("KSEF_GusKeyHelpTitle") . '</strong>';
    print '</div>';
    print '<div id="ksef_env_body_GUSKEY" style="padding: 15px; display: none;">';
    print '<ol><li>' . $langs->trans("KSEF_GusKeyApplyStep1") . '</li><li>' . $langs->trans("KSEF_GusKeyApplyStep2") . '</li><li>' . $langs->trans("KSEF_GusKeyApplyStep3") . '</li><li>' . $langs->trans("KSEF_GusKeyApplyStep4") . '</li></ol>';
    print '<p><strong>' . $langs->trans("KSEF_GusHowTo_EmailLabel") . '</strong></p>';
    print '<pre style="background: var(--ksef-surface, #f5f5f5); border: 1px solid var(--ksef-surface-border, #ddd); padding: 10px; white-space: pre-wrap;">' . htmlspecialchars(ksefGusRequestEmail()) . '</pre>';
    print '</div>';
    print '</div>';
}

print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '" data-ksef-guarded="1">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="update_gus">';
print '<div class="ksef-settings-box">';
print '<table class="noborder centpercent">';

print '<tr class="oddeven"><td class="titlefield">' . $langs->trans("KSEF_GusEnabled") . '</td><td>';
print '<input type="checkbox" name="KSEF_GUS_ENABLED" value="1"' . ($gusEnabled ? ' checked' : '') . ($gusReady ? '' : ' disabled') . '>';
print '</td></tr>';

print '<tr class="oddeven"><td>' . $langs->trans("KSEF_GusEnv") . '</td><td>';
print '<select name="KSEF_GUS_ENV" class="flat maxwidth150onsmartphone">';
print '<option value="TEST"' . ($gusEnv === 'TEST' ? ' selected' : '') . '>' . $langs->trans("KSEF_GusEnvTest") . '</option>';
print '<option value="PROD"' . ($gusEnv === 'PROD' ? ' selected' : '') . '>' . $langs->trans("KSEF_GusEnvProd") . '</option>';
print '</select>';
print '</td></tr>';

print '<tr class="oddeven"><td>' . $langs->trans("KSEF_GusApiKey") . '</td><td>';
print '<input type="password" name="KSEF_GUS_KEY" autocomplete="new-password" value="" placeholder="' . ($gusHasKey ? '********' : '') . '" size="30" class="maxwidth150onsmartphone">';
if ($gusHasKey) {
    print ' <a class="button button-cancel small" href="' . $_SERVER["PHP_SELF"] . '?action=ask_remove_gus_key&token=' . newToken() . '"><i class="fa fa-trash"></i> ' . $langs->trans('KSEF_RemoveGusKey') . '</a>';
}
print '<br><span class="opacitymedium">' . $langs->trans("KSEF_GusApiKeyHelp") . '</span>';
print '</td></tr>';

print '<tr class="oddeven"><td>' . $langs->trans("KSEF_GusNameStyle") . '</td><td>';
print '<select name="KSEF_GUS_NAME_STYLE" class="flat maxwidth150onsmartphone">';
print '<option value="full"' . ($gusNameStyle === 'full' ? ' selected' : '') . '>' . $langs->trans("KSEF_GusNameStyleFull") . '</option>';
print '<option value="upper"' . ($gusNameStyle === 'upper' ? ' selected' : '') . '>' . $langs->trans("KSEF_GusNameStyleUpper") . '</option>';
print '<option value="mixed"' . ($gusNameStyle === 'mixed' ? ' selected' : '') . '>' . $langs->trans("KSEF_GusNameStyleMixed") . '</option>';
print '</select>';
print '</td></tr>';

print '</table>';
print '</div>';

print '<div class="center" style="margin-top: 10px;">';
print '<input type="submit" class="button button-save" value="' . $langs->trans("Save") . '">';
if ($gusEnabled) {
    print ' <a class="butAction" href="' . $_SERVER["PHP_SELF"] . '?action=testgusconnection&token=' . newToken() . '">' . $langs->trans("KSEF_GusTestConnection") . '</a>';
}
print '</div>';
print '</form>';
print '</div>';
print '</div>';

print dol_get_fiche_end();

print '<script>
function ksefToggleEnv(envName) {
    var body = document.getElementById("ksef_env_body_" + envName);
    var toggle = document.getElementById("ksef_env_toggle_" + envName);
    var open = body.style.display === "none";
    body.style.display = open ? "block" : "none";
    toggle.className = "fa fa-chevron-" + (open ? "down" : "right");
    var head = toggle.closest(".ksef-env-head");
    if (head) head.setAttribute("aria-expanded", open ? "true" : "false");
}
function ksefOpenEnvFromHash() {
    var m = window.location.hash.match(/^#env-([A-Z]+)$/);
    var body = m ? document.getElementById("ksef_env_body_" + m[1]) : null;
    if (body && body.style.display === "none") {
        ksefToggleEnv(m[1]);
    }
}
ksefOpenEnvFromHash();
window.addEventListener("hashchange", ksefOpenEnvFromHash);
</script>';
print ksefAdminDirtyGuardJs();

print ksefHelpFooter($db, $user);
llxFooter();
