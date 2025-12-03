<?php
/* Copyright (C) 2004-2017 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2021      Éric Seigne          <eric.seigne@cap-rel.fr>
 * Copyright (C) 2025      InPoint Automation Sp z o.o.
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
 * \brief   KSEF configuration page
 */

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
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
dol_include_once('/ksef/lib/ksef.lib.php');
dol_include_once('/ksef/class/ksef_client.class.php');

$langs->loadLangs(array("admin", "ksef@ksef"));

if (!$user->admin) {
    accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');

$error = 0;

$arrayofparameters = array(
    'KSEF_COMPANY_NIP' => array('type' => 'string', 'enabled' => 1),
    'KSEF_ENVIRONMENT' => array('type' => 'string', 'enabled' => 1),
    'KSEF_AUTH_TOKEN' => array('type' => 'password', 'enabled' => 1),
    'KSEF_BUTTON_COLOR' => array('type' => 'string', 'enabled' => 1),
    'KSEF_ADD_QR' => array('type' => 'yesno', 'enabled' => 1),
);


// Auto-pull NIP - first from professional ID and 2nd from VAT ID
if (empty($conf->global->KSEF_COMPANY_NIP)) {
    global $mysoc;
    $auto_nip = '';

    if (!empty($mysoc->idprof1)) {
        $auto_nip = ksefCleanNIP($mysoc->idprof1);
    } elseif (!empty($mysoc->tva_intra)) {
        $auto_nip = ksefCleanNIP($mysoc->tva_intra);
    }

    if (!empty($auto_nip)) {
        dolibarr_set_const($db, 'KSEF_COMPANY_NIP', $auto_nip, 'chaine', 0, '', $conf->entity);
    }
}


if (!empty($auto_nip)) {
    dolibarr_set_const($db, 'KSEF_COMPANY_NIP', $auto_nip, 'chaine', 0, '', $conf->entity);
}


if ($action == 'update' && GETPOSTISSET('KSEF_COMPANY_NIP')) {
    $nip = GETPOST("KSEF_COMPANY_NIP", 'alphanohtml');
    $nip = ksefCleanNIP($nip);
    $_POST['KSEF_COMPANY_NIP'] = $nip;
}

// Handle token update
if ($action == 'update') {
    require_once DOL_DOCUMENT_ROOT . '/core/lib/security.lib.php';

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
            // Encrypt and store token
            $encrypted = dol_encode($new_token);
            dolibarr_set_const($db, 'KSEF_AUTH_TOKEN', $encrypted, 'chaine', 0, '', $conf->entity);
            dolibarr_set_const($db, 'KSEF_TOKEN_UPDATED_AT', dol_now(), 'chaine', 0, '', $conf->entity);

            dol_syslog("KSeF token saved with timestamp (length: " . strlen($new_token) . ")", LOG_INFO);
            setEventMessages($langs->trans("KSEF_TOKEN_SAVED"), null, 'mesgs');
        }

        unset($_POST['KSEF_AUTH_TOKEN']);
    } else {
        // keep existing token
        unset($_POST['KSEF_AUTH_TOKEN']);
    }
}

if ($action == 'update') {
    $qr_val = GETPOST('KSEF_ADD_QR', 'alpha') ? '1' : '0';
    dolibarr_set_const($db, 'KSEF_ADD_QR', $qr_val, 'chaine', 0, '', $conf->entity);
}

include DOL_DOCUMENT_ROOT . '/core/actions_setmoduleoptions.inc.php';

if ($action == 'testconnection') {
    $environment = !empty($conf->global->KSEF_ENVIRONMENT) ? $conf->global->KSEF_ENVIRONMENT : 'TEST';

    try {
        $client = new KsefClient($db, $environment);
        dol_syslog("Testing KSeF connection to $environment environment", LOG_INFO);

        if ($client->testConnection()) {
            setEventMessages(
                $langs->trans('KSEF_CONNECTION_SUCCESS') . ' [' . $environment . ']',
                null,
                'mesgs'
            );
        } else {
            setEventMessages(
                $langs->trans('KSEF_CONNECTION_FAILED') . ': ' . $client->error,
                null,
                'errors'
            );
        }
    } catch (Exception $e) {
        dol_syslog("Connection test exception: " . $e->getMessage(), LOG_ERR);
        setEventMessages(
            $langs->trans('KSEF_CONNECTION_ERROR') . ': ' . $e->getMessage(),
            null,
            'errors'
        );
    }
}

if ($action == 'testtokenauth') {
    $environment = !empty($conf->global->KSEF_ENVIRONMENT) ? $conf->global->KSEF_ENVIRONMENT : 'TEST';

    try {
        dol_syslog("Testing KSeF token authentication with $environment environment", LOG_INFO);
        $client = new KsefClient($db, $environment);

        if (!$client->authenticate()) {
            setEventMessages(
                $langs->trans('KSEF_AUTH_FAILED') . ': ' . $client->error,
                null,
                'errors'
            );
        } else {
            setEventMessages(
                $langs->trans('KSEF_AUTH_SUCCESS') . ' [' . $environment . ']',
                null,
                'mesgs'
            );
        }
    } catch (Exception $e) {
        dol_syslog("Authentication test exception: " . $e->getMessage(), LOG_ERR);
        setEventMessages(
            $langs->trans('KSEF_AUTH_ERROR') . ': ' . $e->getMessage(),
            null,
            'errors'
        );
    }
}

if ($action == 'add_excluded') {
    $socid = GETPOST('socid', 'int');
    $current = !empty($conf->global->KSEF_EXCLUDED_CUSTOMERS) ? $conf->global->KSEF_EXCLUDED_CUSTOMERS : '';
    $excluded = array_filter(array_map('trim', explode(',', $current)));

    if (!in_array($socid, $excluded)) {
        $excluded[] = $socid;
        dolibarr_set_const($db, "KSEF_EXCLUDED_CUSTOMERS", implode(',', $excluded), 'chaine', 0, '', $conf->entity);
        setEventMessages($langs->trans("KSEF_CustomerExcludedFromKSEF"), null, 'mesgs');
    }

    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

if ($action == 'remove_excluded') {
    $socid = GETPOST('socid', 'int');
    $current = !empty($conf->global->KSEF_EXCLUDED_CUSTOMERS) ? $conf->global->KSEF_EXCLUDED_CUSTOMERS : '';
    $excluded = array_filter(array_map('trim', explode(',', $current)));
    $excluded = array_diff($excluded, array($socid));

    dolibarr_set_const($db, "KSEF_EXCLUDED_CUSTOMERS", implode(',', $excluded), 'chaine', 0, '', $conf->entity);
    setEventMessages($langs->trans("KSEF_CustomerRemovedFromKSEFExclusions"), null, 'mesgs');

    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

$form = new Form($db);
$help_url = '';
$page_name = "KSEF_Setup";

llxHeader('', $langs->trans($page_name), $help_url);

$linkback = '<a href="' . ($backtopage ? $backtopage : DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1') . '">' . $langs->trans("KSEF_BackToModuleList") . '</a>';
print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

$head = ksefAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans("KSEF_Module"), -1, 'ksef@ksef');

print '<span class="opacitymedium">' . $langs->trans("KSEF_SetupPage") . '</span><br><br>';

$missing = array();
if (!extension_loaded('openssl')) $missing[] = 'OpenSSL';
if (!extension_loaded('curl')) $missing[] = 'cURL';
if (!extension_loaded('dom')) $missing[] = 'DOM';

if (count($missing) > 0) {
    print '<div class="error">';
    print '<strong>' . $langs->trans("KSEF_Error") . ':</strong> Missing PHP extensions: ' . implode(', ', $missing);
    print '</div><br>';
}

// Configuration warnings
$warnings = array();

if (empty($conf->global->KSEF_COMPANY_NIP)) {
    $warnings[] = $langs->trans("KSEF_WARNING_NO_NIP");
}
if (empty($conf->global->KSEF_AUTH_TOKEN)) {
    $warnings[] = $langs->trans("KSEF_WARNING_NO_TOKEN");
}

if (count($warnings) > 0) {
    print '<div class="warning">';
    foreach ($warnings as $warning) {
        print '• ' . $warning . '<br>';
    }
    print '</div><br>';
}

print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="update">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>' . $langs->trans("KSEF_Parameter") . '</td>';
print '<td>' . $langs->trans("KSEF_Value") . '</td>';
print '</tr>';

// Company NIP
print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto($langs->trans("KSEF_COMPANY_NIP"), $langs->trans("KSEF_COMPANY_NIP_Help")) . '</td>';
print '<td>';
$nip_value = !empty($conf->global->KSEF_COMPANY_NIP) ? $conf->global->KSEF_COMPANY_NIP : '';
print '<input type="text" class="flat minwidth300" name="KSEF_COMPANY_NIP" value="' . dol_escape_htmltag($nip_value) . '" placeholder="1234567890">';

print '</td>';
print '</tr>';

// Environment
print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto($langs->trans("KSEF_ENVIRONMENT"), $langs->trans("KSEF_ENVIRONMENT_Help")) . '</td>';
print '<td>';
$array_env = array(
    'TEST' => $langs->trans('KSEF_ENV_TEST') . ' (ksef-test.mf.gov.pl)',
    'DEMO' => $langs->trans('KSEF_ENV_DEMO') . ' (ksef-demo.mf.gov.pl)',
    'PRODUCTION' => $langs->trans('KSEF_ENV_PRODUCTION') . ' (ksef.mf.gov.pl)'
);
print $form->selectarray('KSEF_ENVIRONMENT', $array_env, (!empty($conf->global->KSEF_ENVIRONMENT) ? $conf->global->KSEF_ENVIRONMENT : 'TEST'));
print '</td>';
print '</tr>';

// Token Configuration
print '<tr class="liste_titre">';
print '<td colspan="2">' . $langs->trans("KSEF_AUTH_CONFIG") . '</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto($langs->trans("KSEF_TOKEN_STATUS"), $langs->trans("KSEF_AUTH_TOKEN_Help")) . '</td>';
print '<td>';

$has_token = !empty($conf->global->KSEF_AUTH_TOKEN);
$token_updated = !empty($conf->global->KSEF_TOKEN_UPDATED_AT) ? $conf->global->KSEF_TOKEN_UPDATED_AT : null;

if ($has_token) {
    // Display token status
    print '<div style="margin-bottom: 15px;">';
    print '<span class="badge badge-status4" style="font-size: 1.1em; padding: 6px 10px;">';
    print '<i class="fa fa-check-circle"></i> ' . $langs->trans("KSEF_Active");
    print '</span> ';

    if ($token_updated) {
        print '<span class="opacitymedium">';
        print $langs->trans("KSEF_Updated") . ': ' . dol_print_date($token_updated, 'dayhour');
        print '</span>';
    }
    print '</div>';

    print '<strong>' . $langs->trans("KSEF_UpdateToken") . ':</strong><br>';
    print '<input type="password" class="flat minwidth400" name="KSEF_AUTH_TOKEN" value="" ';
    print 'placeholder="' . $langs->trans("KSEF_EnterNewTokenToReplace") . '" autocomplete="new-password">';
} else {
    // No token
    print '<div class="warning" style="margin-bottom: 10px; padding: 10px;">';
    print '<i class="fa fa-exclamation-triangle"></i> <strong>' . $langs->trans("KSEF_NoTokenConfigured") . '</strong>';
    print '</div>';

    print '<input type="password" class="flat minwidth400" name="KSEF_AUTH_TOKEN" value="" ';
    print 'placeholder="' . $langs->trans("KSEF_PasteTokenHere") . '" autocomplete="new-password">';
}

print '<br><span class="opacitymedium">' . $langs->trans("KSEF_TOKEN_OBTAIN_INFO") . '</span>';
print '</td>';
print '</tr>';

// Customer Exclusions
print '<tr class="liste_titre">';
print '<td colspan="2">' . $langs->trans("KSEF_CUSTOMER_EXCLUSIONS") . '</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto($langs->trans("KSEF_EXCLUDED_CUSTOMERS"), $langs->trans("KSEF_EXCLUDED_CUSTOMERS_Help")) . '</td>';
print '<td>';

if (!empty($conf->global->KSEF_EXCLUDED_CUSTOMERS)) {
    $excluded_ids = explode(',', $conf->global->KSEF_EXCLUDED_CUSTOMERS);
    $excluded_ids = array_map('trim', $excluded_ids);

    foreach ($excluded_ids as $socid) {
        if (!empty($socid)) {
            $tmpsoc = new Societe($db);
            if ($tmpsoc->fetch($socid) > 0) {
                print '<span class="badge badge-secondary" style="margin-right: 5px;">';
                print $tmpsoc->name;
                print ' <a href="' . $_SERVER["PHP_SELF"] . '?action=remove_excluded&socid=' . $socid . '&token=' . newToken() . '" style="color:white; text-decoration:none;">×</a>';
                print '</span> ';
            }
        }
    }
    print '<br><br>';
}

print '<select id="excluded_customer" name="excluded_customer" class="flat minwidth300">';
print '<option value="">-- ' . $langs->trans("KSEF_SelectCustomer") . ' --</option>';

$sql = "SELECT rowid, nom FROM " . MAIN_DB_PREFIX . "societe WHERE client IN (1, 3) AND entity = " . $conf->entity . " ORDER BY nom";
$resql = $db->query($sql);
if ($resql) {
    while ($obj = $db->fetch_object($resql)) {
        print '<option value="' . $obj->rowid . '">' . $obj->nom . '</option>';
    }
}
print '</select> ';
print '<input type="button" class="button small" value="' . $langs->trans("KSEF_Add") . '" onclick="addExcludedCustomer();">';

print '<br><span class="opacitymedium">' . $langs->trans("KSEF_EXCLUDED_CUSTOMERS_INFO") . '</span>';
print '</td>';
print '</tr>';

print '<tr class="liste_titre">';
print '<td colspan="2">' . $langs->trans("KSEF_CUSTOMIZATION_CONFIG") . '</td>';
print '</tr>';

// QR Code
print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto($langs->trans('KSEF_ADD_QR'), $langs->trans('KSEF_ADD_QR_Help')) . '</td>';
print '<td>';

$is_checked = !empty($conf->global->KSEF_ADD_QR) ? 'checked' : '';

print '<input type="checkbox" name="KSEF_ADD_QR" id="KSEF_ADD_QR" value="1" ' . $is_checked . '>';
print ' <label for="KSEF_ADD_QR">' . $langs->trans("KSEF_Enabled") . '</label>';

print '</td>';
print '</tr>';

print '<tr class="liste_titre">';
print '<td colspan="2">' . $langs->trans("KSEF_UI_CONFIG") . '</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto($langs->trans("KSEF_BUTTON_COLOR"), $langs->trans("KSEF_BUTTON_COLOR_Help")) . '</td>';
print '<td>';
$array_colors = array(
    '#dc3545' => $langs->trans('KSEF_Red') . ' (Default)',
    '#fd7e14' => $langs->trans('KSEF_Orange'),
    '#ffc107' => $langs->trans('KSEF_Yellow'),
    '#28a745' => $langs->trans('KSEF_Green'),
    '#17a2b8' => $langs->trans('KSEF_Cyan'),
    '#007bff' => $langs->trans('KSEF_Blue'),
    '#6f42c1' => $langs->trans('KSEF_Purple'),
);
print $form->selectarray('KSEF_BUTTON_COLOR', $array_colors, (!empty($conf->global->KSEF_BUTTON_COLOR) ? $conf->global->KSEF_BUTTON_COLOR : '#dc3545'));
print '</td>';
print '</tr>';

print '</table>';

print '<br><div class="center">';
print '<input type="submit" class="button button-save" value="' . $langs->trans("KSEF_Save") . '">';
print '</div>';

print '</form>';

print '<script type="text/javascript">
function addExcludedCustomer() {
    var socid = document.getElementById(\'excluded_customer\').value;
    if (socid) {
        window.location.href = "' . $_SERVER["PHP_SELF"] . '?action=add_excluded&socid=" + socid + "&token=' . newToken() . '";
    } else {
        alert(\'' . $langs->trans("KSEF_PleaseSelectCustomer") . '\');
    }
}
</script>';

print dol_get_fiche_end();

if (!empty($conf->global->KSEF_COMPANY_NIP)) {
    print '<br>';
    print '<div class="tabsAction">';
    print '<a class="butAction" href="' . $_SERVER["PHP_SELF"] . '?action=testconnection&token=' . newToken() . '">' . $langs->trans("KSEF_TEST_CONNECTION") . '</a>';

    if (!empty($conf->global->KSEF_AUTH_TOKEN)) {
        print '<a class="butAction" href="' . $_SERVER["PHP_SELF"] . '?action=testtokenauth&token=' . newToken() . '">' . $langs->trans("KSEF_TEST_TOKEN_AUTH") . '</a>';
    }
    print '</div>';
}

llxFooter();
$db->close();

