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
 * \brief   KSEF configuration page
 */

// CSRF Check
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
dol_include_once('/ksef/class/ksef_client.class.php');

$langs->loadLangs(array("admin", "ksef@ksef"));

if (!$user->admin) {
    accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');
$error = 0;

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

// Auto-pull KRS from Professional ID 2
if (empty($conf->global->KSEF_COMPANY_KRS)) {
    global $mysoc;
    if (!empty($mysoc->idprof2)) {
        dolibarr_set_const($db, 'KSEF_COMPANY_KRS', trim($mysoc->idprof2), 'chaine', 0, '', $conf->entity);
    }
}

// Auto-pull REGON from Professional ID 3
if (empty($conf->global->KSEF_COMPANY_REGON)) {
    global $mysoc;
    if (!empty($mysoc->idprof3)) {
        dolibarr_set_const($db, 'KSEF_COMPANY_REGON', trim($mysoc->idprof3), 'chaine', 0, '', $conf->entity);
    }
}

// Auto-pull BDO from Professional ID 4
if (empty($conf->global->KSEF_COMPANY_BDO)) {
    global $mysoc;
    if (!empty($mysoc->idprof4)) {
        dolibarr_set_const($db, 'KSEF_COMPANY_BDO', trim($mysoc->idprof4), 'chaine', 0, '', $conf->entity);
    }
}


if ($action == 'update') {
    // NIP
    $nip = GETPOST("KSEF_COMPANY_NIP", 'alphanohtml');
    $nip = ksefCleanNIP($nip);
    dolibarr_set_const($db, 'KSEF_COMPANY_NIP', $nip, 'chaine', 0, '', $conf->entity);

    // KRS
    $krs = GETPOST("KSEF_COMPANY_KRS", 'alphanohtml');
    dolibarr_set_const($db, 'KSEF_COMPANY_KRS', trim($krs), 'chaine', 0, '', $conf->entity);

    // REGON
    $regon = GETPOST("KSEF_COMPANY_REGON", 'alphanohtml');
    dolibarr_set_const($db, 'KSEF_COMPANY_REGON', trim($regon), 'chaine', 0, '', $conf->entity);

    // BDO
    $bdo = GETPOST("KSEF_COMPANY_BDO", 'alphanohtml');
    dolibarr_set_const($db, 'KSEF_COMPANY_BDO', trim($bdo), 'chaine', 0, '', $conf->entity);

    // Environment
    $env = GETPOST('KSEF_ENVIRONMENT', 'alpha');
    if (in_array($env, array('TEST', 'DEMO', 'PRODUCTION'))) {
        dolibarr_set_const($db, 'KSEF_ENVIRONMENT', $env, 'chaine', 0, '', $conf->entity);
    }

    // Authentication method
    $auth_method = GETPOST('KSEF_AUTH_METHOD', 'alpha');
    if (in_array($auth_method, array('token', 'certificate'))) {
        dolibarr_set_const($db, 'KSEF_AUTH_METHOD', $auth_method, 'chaine', 0, '', $conf->entity);
    }

    // Token
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
            $encrypted = dol_encode($new_token);
            dolibarr_set_const($db, 'KSEF_AUTH_TOKEN', $encrypted, 'chaine', 0, '', $conf->entity);
            dolibarr_set_const($db, 'KSEF_TOKEN_UPDATED_AT', dol_now(), 'chaine', 0, '', $conf->entity);
            setEventMessages($langs->trans("KSEF_TOKEN_SAVED"), null, 'mesgs');
        }
    }

    // QR Code checkbox
    $qr_val = GETPOST('KSEF_ADD_QR', 'alpha') ? '1' : '0';
    dolibarr_set_const($db, 'KSEF_ADD_QR', $qr_val, 'chaine', 0, '', $conf->entity);

    // Button color
    $color = GETPOST('KSEF_BUTTON_COLOR', 'alpha');
    if (!empty($color)) {
        dolibarr_set_const($db, 'KSEF_BUTTON_COLOR', $color, 'chaine', 0, '', $conf->entity);
    }

    // Optional Fields
    $fa3_nrklienta_val = GETPOST('KSEF_FA3_INCLUDE_NRKLIENTA', 'alpha') ? '1' : '0';
    dolibarr_set_const($db, 'KSEF_FA3_INCLUDE_NRKLIENTA', $fa3_nrklienta_val, 'chaine', 0, '', $conf->entity);

    $fa3_indeks_val = GETPOST('KSEF_FA3_INCLUDE_INDEKS', 'alpha') ? '1' : '0';
    dolibarr_set_const($db, 'KSEF_FA3_INCLUDE_INDEKS', $fa3_indeks_val, 'chaine', 0, '', $conf->entity);

    $fa3_gtin_val = GETPOST('KSEF_FA3_INCLUDE_GTIN', 'alpha') ? '1' : '0';
    dolibarr_set_const($db, 'KSEF_FA3_INCLUDE_GTIN', $fa3_gtin_val, 'chaine', 0, '', $conf->entity);

    $fa3_unit_val = GETPOST('KSEF_FA3_INCLUDE_UNIT', 'alpha') ? '1' : '0';
    dolibarr_set_const($db, 'KSEF_FA3_INCLUDE_UNIT', $fa3_unit_val, 'chaine', 0, '', $conf->entity);

    $fa3_bankdesc_val = GETPOST('KSEF_FA3_INCLUDE_BANK_DESC', 'alpha') ? '1' : '0';
    dolibarr_set_const($db, 'KSEF_FA3_INCLUDE_BANK_DESC', $fa3_bankdesc_val, 'chaine', 0, '', $conf->entity);

    // Place of Issue
    $place_of_issue_mode = GETPOST('KSEF_FA3_PLACE_OF_ISSUE_MODE', 'alpha');
    if (in_array($place_of_issue_mode, array('disabled', 'company', 'custom'))) {
        dolibarr_set_const($db, 'KSEF_FA3_PLACE_OF_ISSUE_MODE', $place_of_issue_mode, 'chaine', 0, '', $conf->entity);
    }
    $place_of_issue_custom = GETPOST('KSEF_FA3_PLACE_OF_ISSUE_CUSTOM', 'alphanohtml');
    dolibarr_set_const($db, 'KSEF_FA3_PLACE_OF_ISSUE_CUSTOM', trim($place_of_issue_custom), 'chaine', 0, '', $conf->entity);

    // NBP Rate Mode
    $nbp_rate_mode = GETPOST('KSEF_NBP_RATE_MODE', 'alpha');
    if (in_array($nbp_rate_mode, array('keep_base', 'keep_foreign'))) {
        dolibarr_set_const($db, 'KSEF_NBP_RATE_MODE', $nbp_rate_mode, 'chaine', 0, '', $conf->entity);
    }

    if (!$error) {
        setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
    }
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

// Authentication certificate upload
if ($action == 'upload_auth_cert') {
    $error_count = 0;

    // Certificate file
    if (!empty($_FILES['auth_cert_file']['tmp_name'])) {
        $cert_content = file_get_contents($_FILES['auth_cert_file']['tmp_name']);
        if ($cert_content !== false) {
            $cert_content = trim($cert_content);
            $cert_resource = openssl_x509_read($cert_content);
            if ($cert_resource) {
                $cert_info = openssl_x509_parse($cert_resource);
                if ($cert_info) {
                    $serial = $cert_info['serialNumberHex'] ?? $cert_info['serialNumber'] ?? '';
                    $valid_from = $cert_info['validFrom_time_t'] ?? null;
                    $valid_to = $cert_info['validTo_time_t'] ?? null;

                    dolibarr_set_const($db, 'KSEF_AUTH_CERTIFICATE', base64_encode($cert_content), 'chaine', 0, '', $conf->entity);
                    dolibarr_set_const($db, 'KSEF_AUTH_CERT_SERIAL', $serial, 'chaine', 0, '', $conf->entity);
                    if ($valid_from) dolibarr_set_const($db, 'KSEF_AUTH_CERT_VALID_FROM', $valid_from, 'chaine', 0, '', $conf->entity);
                    if ($valid_to) dolibarr_set_const($db, 'KSEF_AUTH_CERT_VALID_TO', $valid_to, 'chaine', 0, '', $conf->entity);
                    setEventMessages($langs->trans("KSEF_AuthCertificateSaved"), null, 'mesgs');
                } else {
                    setEventMessages($langs->trans("KSEF_CertificateParseError"), null, 'errors');
                    $error_count++;
                }
            } else {
                setEventMessages($langs->trans("KSEF_CertificateInvalid") . ': ' . openssl_error_string(), null, 'errors');
                $error_count++;
            }
        }
    }

    // Private key file
    if (!empty($_FILES['auth_key_file']['tmp_name'])) {
        $key_content = file_get_contents($_FILES['auth_key_file']['tmp_name']);
        if ($key_content !== false) {
            dolibarr_set_const($db, 'KSEF_AUTH_PRIVATE_KEY', base64_encode(trim($key_content)), 'chaine', 0, '', $conf->entity);
            setEventMessages($langs->trans("KSEF_AuthPrivateKeySaved"), null, 'mesgs');
        }
    }

    // Password
    $key_password = GETPOST('auth_key_password', 'none');
    if (!empty($key_password)) {
        dolibarr_set_const($db, 'KSEF_AUTH_KEY_PASSWORD', dol_encode($key_password), 'chaine', 0, '', $conf->entity);

        // Validate key with password
        $stored_key = $conf->global->KSEF_AUTH_PRIVATE_KEY ?? '';
        if (!empty($stored_key)) {
            $key_pem = base64_decode($stored_key);
            $pk = openssl_pkey_get_private($key_pem, $key_password);
            if (!$pk) {
                setEventMessages($langs->trans("KSEF_PrivateKeyPasswordMismatch"), null, 'warnings');
            } else {
                setEventMessages($langs->trans("KSEF_PrivateKeyValidated"), null, 'mesgs');
            }
        }
    }

    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

// Offline certificate upload
if ($action == 'upload_offline_cert') {
    $error_count = 0;

    // Certificate file
    if (!empty($_FILES['offline_cert_file']['tmp_name'])) {
        $cert_content = file_get_contents($_FILES['offline_cert_file']['tmp_name']);
        if ($cert_content !== false) {
            $cert_content = trim($cert_content);
            $cert_resource = openssl_x509_read($cert_content);
            if ($cert_resource) {
                $cert_info = openssl_x509_parse($cert_resource);
                if ($cert_info) {
                    $serial = $cert_info['serialNumberHex'] ?? $cert_info['serialNumber'] ?? '';
                    $valid_from = $cert_info['validFrom_time_t'] ?? null;
                    $valid_to = $cert_info['validTo_time_t'] ?? null;

                    dolibarr_set_const($db, 'KSEF_OFFLINE_CERTIFICATE', base64_encode($cert_content), 'chaine', 0, '', $conf->entity);
                    dolibarr_set_const($db, 'KSEF_OFFLINE_CERT_SERIAL', $serial, 'chaine', 0, '', $conf->entity);
                    if ($valid_from) dolibarr_set_const($db, 'KSEF_OFFLINE_CERT_VALID_FROM', $valid_from, 'chaine', 0, '', $conf->entity);
                    if ($valid_to) dolibarr_set_const($db, 'KSEF_OFFLINE_CERT_VALID_TO', $valid_to, 'chaine', 0, '', $conf->entity);
                    setEventMessages($langs->trans("KSEF_OfflineCertificateSaved"), null, 'mesgs');
                } else {
                    setEventMessages($langs->trans("KSEF_CertificateParseError"), null, 'errors');
                    $error_count++;
                }
            } else {
                setEventMessages($langs->trans("KSEF_CertificateInvalid") . ': ' . openssl_error_string(), null, 'errors');
                $error_count++;
            }
        }
    }

    // Private key file
    if (!empty($_FILES['offline_key_file']['tmp_name'])) {
        $key_content = file_get_contents($_FILES['offline_key_file']['tmp_name']);
        if ($key_content !== false) {
            dolibarr_set_const($db, 'KSEF_OFFLINE_PRIVATE_KEY', base64_encode(trim($key_content)), 'chaine', 0, '', $conf->entity);
            setEventMessages($langs->trans("KSEF_OfflinePrivateKeySaved"), null, 'mesgs');
        }
    }

    // Password
    $key_password = GETPOST('offline_key_password', 'none');
    if (!empty($key_password)) {
        dolibarr_set_const($db, 'KSEF_OFFLINE_KEY_PASSWORD', dol_encode($key_password), 'chaine', 0, '', $conf->entity);

        // Validate key with password
        $stored_key = $conf->global->KSEF_OFFLINE_PRIVATE_KEY ?? '';
        if (!empty($stored_key)) {
            $key_pem = base64_decode($stored_key);
            $pk = openssl_pkey_get_private($key_pem, $key_password);
            if (!$pk) {
                setEventMessages($langs->trans("KSEF_PrivateKeyPasswordMismatch"), null, 'warnings');
            } else {
                setEventMessages($langs->trans("KSEF_PrivateKeyValidated"), null, 'mesgs');
            }
        }
    }

    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

// Remove auth certificate
if ($action == 'remove_auth_cert') {
    dolibarr_del_const($db, 'KSEF_AUTH_CERTIFICATE', $conf->entity);
    dolibarr_del_const($db, 'KSEF_AUTH_PRIVATE_KEY', $conf->entity);
    dolibarr_del_const($db, 'KSEF_AUTH_KEY_PASSWORD', $conf->entity);
    dolibarr_del_const($db, 'KSEF_AUTH_CERT_SERIAL', $conf->entity);
    dolibarr_del_const($db, 'KSEF_AUTH_CERT_VALID_FROM', $conf->entity);
    dolibarr_del_const($db, 'KSEF_AUTH_CERT_VALID_TO', $conf->entity);

    if ($conf->global->KSEF_AUTH_METHOD == 'certificate') {
        dolibarr_set_const($db, 'KSEF_AUTH_METHOD', 'token', 'chaine', 0, '', $conf->entity);
    }

    setEventMessages($langs->trans("KSEF_AuthCertificateRemoved"), null, 'mesgs');
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

// Remove offline certificate
if ($action == 'remove_offline_cert') {
    dolibarr_del_const($db, 'KSEF_OFFLINE_CERTIFICATE', $conf->entity);
    dolibarr_del_const($db, 'KSEF_OFFLINE_PRIVATE_KEY', $conf->entity);
    dolibarr_del_const($db, 'KSEF_OFFLINE_KEY_PASSWORD', $conf->entity);
    dolibarr_del_const($db, 'KSEF_OFFLINE_CERT_SERIAL', $conf->entity);
    dolibarr_del_const($db, 'KSEF_OFFLINE_CERT_VALID_FROM', $conf->entity);
    dolibarr_del_const($db, 'KSEF_OFFLINE_CERT_VALID_TO', $conf->entity);

    setEventMessages($langs->trans("KSEF_OfflineCertificateRemoved"), null, 'mesgs');
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

// Customer exclusion management
if ($action == 'add_excluded') {
    $socid = GETPOST('socid', 'int');
    $current = $conf->global->KSEF_EXCLUDED_CUSTOMERS ?? '';
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
    $current = $conf->global->KSEF_EXCLUDED_CUSTOMERS ?? '';
    $excluded = array_filter(array_map('trim', explode(',', $current)));
    $excluded = array_diff($excluded, array($socid));
    dolibarr_set_const($db, "KSEF_EXCLUDED_CUSTOMERS", implode(',', $excluded), 'chaine', 0, '', $conf->entity);
    setEventMessages($langs->trans("KSEF_CustomerRemovedFromKSEFExclusions"), null, 'mesgs');
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

// Test connection
if ($action == 'testconnection') {
    $environment = $conf->global->KSEF_ENVIRONMENT ?? 'TEST';
    try {
        $client = new KsefClient($db, $environment);
        if ($client->testConnection()) {
            setEventMessages($langs->trans('KSEF_CONNECTION_SUCCESS') . ' [' . $environment . ']', null, 'mesgs');
        } else {
            setEventMessages($langs->trans('KSEF_CONNECTION_FAILED') . ': ' . $client->error, null, 'errors');
        }
    } catch (Exception $e) {
        setEventMessages($langs->trans('KSEF_CONNECTION_ERROR') . ': ' . $e->getMessage(), null, 'errors');
    }
}

// Test token authentication
if ($action == 'testtokenauth') {
    $environment = $conf->global->KSEF_ENVIRONMENT ?? 'TEST';
    $orig_method = $conf->global->KSEF_AUTH_METHOD;
    $conf->global->KSEF_AUTH_METHOD = 'token';

    try {
        $client = new KsefClient($db, $environment);
        if ($client->authenticate()) {
            setEventMessages($langs->trans('KSEF_AUTH_SUCCESS') . ' [' . $environment . '] (Token)', null, 'mesgs');
        } else {
            setEventMessages($langs->trans('KSEF_AUTH_FAILED') . ': ' . $client->error, null, 'errors');
        }
    } catch (Exception $e) {
        setEventMessages($langs->trans('KSEF_AUTH_ERROR') . ': ' . $e->getMessage(), null, 'errors');
    }

    $conf->global->KSEF_AUTH_METHOD = $orig_method;
}

// Test certificate authentication
if ($action == 'testcertauth') {
    $environment = $conf->global->KSEF_ENVIRONMENT ?? 'TEST';
    $orig_method = $conf->global->KSEF_AUTH_METHOD;
    $conf->global->KSEF_AUTH_METHOD = 'certificate';

    try {
        $client = new KsefClient($db, $environment);
        if ($client->authenticate()) {
            setEventMessages($langs->trans('KSEF_CERT_AUTH_SUCCESS') . ' [' . $environment . ']', null, 'mesgs');
        } else {
            setEventMessages($langs->trans('KSEF_CERT_AUTH_FAILED') . ': ' . $client->error, null, 'errors');
        }
    } catch (Exception $e) {
        setEventMessages($langs->trans('KSEF_CERT_AUTH_ERROR') . ': ' . $e->getMessage(), null, 'errors');
    }
    $conf->global->KSEF_AUTH_METHOD = $orig_method;
}

$form = new Form($db);
$page_name = "KSEF_Setup";

llxHeader('', $langs->trans($page_name), '');

$linkback = '<a href="' . ($backtopage ?: DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1') . '">' . $langs->trans("KSEF_BackToModuleList") . '</a>';
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

// Determine authentication status
$current_auth_method = $conf->global->KSEF_AUTH_METHOD ?? '';
$has_token = !empty($conf->global->KSEF_AUTH_TOKEN);
$has_auth_cert = !empty($conf->global->KSEF_AUTH_CERTIFICATE) &&
    !empty($conf->global->KSEF_AUTH_PRIVATE_KEY) &&
    !empty($conf->global->KSEF_AUTH_KEY_PASSWORD);

$no_auth_configured = (!$has_token && !$has_auth_cert);
if ($no_auth_configured) {
    $warnings[] = '<strong style="color: #dc3545;">' . $langs->trans("KSEF_WARNING_NO_AUTH") . '</strong> - ' . $langs->trans("KSEF_WARNING_CONFIGURE_TOKEN_OR_CERT");
} else {
    $current_auth_method = $conf->global->KSEF_AUTH_METHOD ?? 'token';
    if ($current_auth_method == 'token' && !$has_token) {
        $warnings[] = $langs->trans("KSEF_WARNING_NO_TOKEN");
    } elseif ($current_auth_method == 'certificate' && !$has_auth_cert) {
        $warnings[] = $langs->trans("KSEF_WARNING_NO_AUTH_CERTIFICATE");
    }
}

if (count($warnings) > 0) {
    print '<div class="warning" style="margin-bottom: 15px;">';
    foreach ($warnings as $warning) {
        print '• ' . $warning . '<br>';
    }
    print '</div>';
}

print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="update">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">' . $langs->trans("KSEF_BASIC_CONFIG") . '</td></tr>';

// Company NIP
print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto($langs->trans("KSEF_COMPANY_NIP"), $langs->trans("KSEF_COMPANY_NIP_Help")) . '</td>';
print '<td><input type="text" class="flat minwidth300" name="KSEF_COMPANY_NIP" value="' . dol_escape_htmltag($conf->global->KSEF_COMPANY_NIP ?? '') . '" placeholder="1234567890"></td>';
print '</tr>';

// KRS
print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto($langs->trans("KSEF_COMPANY_KRS"), $langs->trans("KSEF_COMPANY_KRS_Help")) . '</td>';
print '<td><input type="text" class="flat minwidth300" name="KSEF_COMPANY_KRS" value="' . dol_escape_htmltag($conf->global->KSEF_COMPANY_KRS ?? '') . '" placeholder="0000000000">';
print ' <span class="opacitymedium small">(' . $langs->trans("KSEF_Optional") . ')</span></td>';
print '</tr>';

// REGON
print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto($langs->trans("KSEF_COMPANY_REGON"), $langs->trans("KSEF_COMPANY_REGON_Help")) . '</td>';
print '<td><input type="text" class="flat minwidth300" name="KSEF_COMPANY_REGON" value="' . dol_escape_htmltag($conf->global->KSEF_COMPANY_REGON ?? '') . '" placeholder="000000000">';
print ' <span class="opacitymedium small">(' . $langs->trans("KSEF_Optional") . ')</span></td>';
print '</tr>';

// BDO
print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto($langs->trans("KSEF_COMPANY_BDO"), $langs->trans("KSEF_COMPANY_BDO_Help")) . '</td>';
print '<td><input type="text" class="flat minwidth300" name="KSEF_COMPANY_BDO" value="' . dol_escape_htmltag($conf->global->KSEF_COMPANY_BDO ?? '') . '" placeholder="000000000">';
print ' <span class="opacitymedium small">(' . $langs->trans("KSEF_Optional") . ')</span></td>';
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
print $form->selectarray('KSEF_ENVIRONMENT', $array_env, $conf->global->KSEF_ENVIRONMENT ?? 'DEMO');

$current_env = $conf->global->KSEF_ENVIRONMENT ?? 'DEMO';
print '<div id="production_warning" class="warning" style="margin-top: 10px; padding: 8px 12px; display: ' . ($current_env == 'PRODUCTION' ? 'block' : 'none') . ';">';
print '<i class="fa fa-exclamation-triangle"></i> ' . $langs->trans("KSEF_PRODUCTION_WARNING");
print '</div>';

print '<script type="text/javascript">
$(document).ready(function() {
    $("#KSEF_ENVIRONMENT").on("change", function() {
        if ($(this).val() == "PRODUCTION") {
            $("#production_warning").show();
        } else {
            $("#production_warning").hide();
        }
    });
});
</script>';

print '</td></tr>';

print '</table>';

// PDF & UI Settings
print '<br><table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">' . $langs->trans("KSEF_UI_CONFIG") . '</td></tr>';

// QR Code
print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto($langs->trans('KSEF_ADD_QR'), $langs->trans('KSEF_ADD_QR_Help')) . '</td>';
print '<td>';
print '<input type="checkbox" name="KSEF_ADD_QR" id="KSEF_ADD_QR" value="1" ' . (!empty($conf->global->KSEF_ADD_QR) ? 'checked' : '') . '>';
print ' <label for="KSEF_ADD_QR">' . $langs->trans("KSEF_Enabled") . '</label>';
print '</td></tr>';

// Button color
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
print $form->selectarray('KSEF_BUTTON_COLOR', $array_colors, $conf->global->KSEF_BUTTON_COLOR ?? '#dc3545');
print '</td></tr>';

print '</table>';

// Optional Fields
print '<br><table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">' . $langs->trans("KSEF_FA3_OPTIONAL_FIELDS") . '</td></tr>';

// NrKlienta/Customer code
print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto($langs->trans('KSEF_FA3_INCLUDE_NRKLIENTA'), $langs->trans('KSEF_FA3_INCLUDE_NRKLIENTA_Help')) . '</td>';
print '<td>';
print '<input type="checkbox" name="KSEF_FA3_INCLUDE_NRKLIENTA" id="KSEF_FA3_INCLUDE_NRKLIENTA" value="1" ' . (!empty($conf->global->KSEF_FA3_INCLUDE_NRKLIENTA) ? 'checked' : '') . '>';
print ' <label for="KSEF_FA3_INCLUDE_NRKLIENTA">' . $langs->trans("KSEF_Enabled") . '</label>';
print '</td></tr>';

// Indeks/Product reference code
print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto($langs->trans('KSEF_FA3_INCLUDE_INDEKS'), $langs->trans('KSEF_FA3_INCLUDE_INDEKS_Help')) . '</td>';
print '<td>';
print '<input type="checkbox" name="KSEF_FA3_INCLUDE_INDEKS" id="KSEF_FA3_INCLUDE_INDEKS" value="1" ' . (!empty($conf->global->KSEF_FA3_INCLUDE_INDEKS) ? 'checked' : '') . '>';
print ' <label for="KSEF_FA3_INCLUDE_INDEKS">' . $langs->trans("KSEF_Enabled") . '</label>';
print '</td></tr>';

// GTIN/Barcode/EAN
print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto($langs->trans('KSEF_FA3_INCLUDE_GTIN'), $langs->trans('KSEF_FA3_INCLUDE_GTIN_Help')) . '</td>';
print '<td>';
print '<input type="checkbox" name="KSEF_FA3_INCLUDE_GTIN" id="KSEF_FA3_INCLUDE_GTIN" value="1" ' . (!empty($conf->global->KSEF_FA3_INCLUDE_GTIN) ? 'checked' : '') . '>';
print ' <label for="KSEF_FA3_INCLUDE_GTIN">' . $langs->trans("KSEF_Enabled") . '</label>';
print '</td></tr>';

// P_8A/Unit of measure
print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto($langs->trans('KSEF_FA3_INCLUDE_UNIT'), $langs->trans('KSEF_FA3_INCLUDE_UNIT_Help')) . '</td>';
print '<td>';
print '<input type="checkbox" name="KSEF_FA3_INCLUDE_UNIT" id="KSEF_FA3_INCLUDE_UNIT" value="1" ' . (!empty($conf->global->KSEF_FA3_INCLUDE_UNIT) ? 'checked' : '') . '>';
print ' <label for="KSEF_FA3_INCLUDE_UNIT">' . $langs->trans("KSEF_Enabled") . '</label>';
print '</td></tr>';

// OpisRachunku/Bank account description
print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto($langs->trans('KSEF_FA3_INCLUDE_BANK_DESC'), $langs->trans('KSEF_FA3_INCLUDE_BANK_DESC_Help')) . '</td>';
print '<td>';
print '<input type="checkbox" name="KSEF_FA3_INCLUDE_BANK_DESC" id="KSEF_FA3_INCLUDE_BANK_DESC" value="1" ' . (!empty($conf->global->KSEF_FA3_INCLUDE_BANK_DESC) ? 'checked' : '') . '>';
print ' <label for="KSEF_FA3_INCLUDE_BANK_DESC">' . $langs->trans("KSEF_Enabled") . '</label>';
print '</td></tr>';

// P_1M/Place of Issue
print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto($langs->trans('KSEF_FA3_PLACE_OF_ISSUE'), $langs->trans('KSEF_FA3_PLACE_OF_ISSUE_Help')) . '</td>';
print '<td>';
$place_modes = array(
    'disabled' => $langs->trans('KSEF_FA3_PLACE_OF_ISSUE_DISABLED'),
    'company' => $langs->trans('KSEF_FA3_PLACE_OF_ISSUE_COMPANY'),
    'custom' => $langs->trans('KSEF_FA3_PLACE_OF_ISSUE_CUSTOM'),
);
$current_place_mode = $conf->global->KSEF_FA3_PLACE_OF_ISSUE_MODE ?? 'disabled';
print $form->selectarray('KSEF_FA3_PLACE_OF_ISSUE_MODE', $place_modes, $current_place_mode, 0, 0, 0, 'onchange="togglePlaceOfIssueCustom()"', 0, 0, 0, '', 'minwidth200');
print '<span id="place_of_issue_custom_wrapper" style="margin-left: 10px; ' . ($current_place_mode != 'custom' ? 'display:none;' : '') . '">';
print '<input type="text" name="KSEF_FA3_PLACE_OF_ISSUE_CUSTOM" class="flat minwidth200" value="' . dol_escape_htmltag($conf->global->KSEF_FA3_PLACE_OF_ISSUE_CUSTOM ?? '') . '" placeholder="' . $langs->trans("KSEF_FA3_PLACE_OF_ISSUE_CUSTOM_Placeholder") . '">';
print '</span>';
print '</td></tr>';
print '<script>
function togglePlaceOfIssueCustom() {
    var mode = document.querySelector(\'select[name="KSEF_FA3_PLACE_OF_ISSUE_MODE"]\').value;
    var wrapper = document.getElementById("place_of_issue_custom_wrapper");
    wrapper.style.display = (mode == "custom") ? "inline" : "none";
}
</script>';

print '</table>';

// Multicurrency Settings
print '<br><table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">' . $langs->trans("KSEF_MULTICURRENCY_CONFIG") . '</td></tr>';
print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto($langs->trans('KSEF_NBP_RATE_MODE'), $langs->trans('KSEF_NBP_RATE_MODE_Help')) . '</td>';
print '<td>';
$nbp_modes = array(
    'keep_base' => $langs->trans('KSEF_NBP_RATE_MODE_KEEP_BASE'),
    'keep_foreign' => $langs->trans('KSEF_NBP_RATE_MODE_KEEP_FOREIGN'),
);
$current_mode = $conf->global->KSEF_NBP_RATE_MODE ?? 'keep_base';
print $form->selectarray('KSEF_NBP_RATE_MODE', $nbp_modes, $current_mode, 0, 0, 0, '', 0, 0, 0, '', 'minwidth300');
print '</td></tr>';

print '</table>';

// Authentication Method Selection
print '<br><table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">' . $langs->trans("KSEF_AUTH_METHOD_SELECT") . '</td></tr>';

print '<tr class="oddeven"><td colspan="2">';
print '<div style="display: flex; gap: 20px; padding: 10px 0; flex-wrap: wrap;">';

// FIX #6: Show warning box when nothing is configured
if ($no_auth_configured) {
    print '<div style="flex: 1; min-width: 250px; max-width: 300px; padding: 15px; border: 2px solid #dc3545; border-radius: 8px; background: #fff5f5;">';
    print '<div style="display: flex; align-items: center; margin-bottom: 10px;">';
    print '<span style="width: 40px; height: 40px; background: #dc354520; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; margin-right: 10px;">';
    print '<i class="fa fa-exclamation-triangle" style="color: #dc3545; font-size: 18px;"></i>';
    print '</span>';
    print '<strong style="color: #dc3545;">' . $langs->trans("KSEF_NO_AUTH_CONFIGURED") . '</strong>';
    print '</div>';
    print '<div style="padding-left: 50px;">';
    print '<span class="opacitymedium" style="font-size: 12px;">' . $langs->trans("KSEF_ConfigureAuthBelow") . '</span>';
    print '</div></div>';
}

// Token option
$token_selected = ($current_auth_method == 'token' || (empty($current_auth_method) && $has_token && !$has_auth_cert));
$token_border_color = $token_selected ? '#28a745' : ($has_token ? '#17a2b8' : '#dee2e6');
$token_bg_color = $token_selected ? '#f8fff8' : '#fff';

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
    if (!empty($conf->global->KSEF_TOKEN_UPDATED_AT)) {
        print '<br><small class="opacitymedium">' . $langs->trans("KSEF_Updated") . ': ' . dol_print_date($conf->global->KSEF_TOKEN_UPDATED_AT, 'dayhour') . '</small>';
    }
} else {
    print '<span class="badge badge-warning"><i class="fa fa-exclamation-triangle"></i> ' . $langs->trans("KSEF_TokenNotConfigured") . '</span>';
    print '<br><small class="opacitymedium">' . $langs->trans("KSEF_ConfigureTokenBelow") . '</small>';
}
print '</div></div>';

// Certificate option
$cert_selected = ($current_auth_method == 'certificate' || (empty($current_auth_method) && $has_auth_cert && !$has_token));
$cert_border_color = $cert_selected ? '#28a745' : ($has_auth_cert ? '#17a2b8' : '#dee2e6');
$cert_bg_color = $cert_selected ? '#f8fff8' : '#fff';

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
    $cert_valid_to = $conf->global->KSEF_AUTH_CERT_VALID_TO ?? null;
    $is_expired = ($cert_valid_to && $cert_valid_to < dol_now());
    if ($is_expired) {
        print '<span class="badge badge-danger"><i class="fa fa-times-circle"></i> ' . $langs->trans("KSEF_CertificateExpired") . '</span>';
    } else {
        print '<span class="badge badge-status4"><i class="fa fa-check-circle"></i> ' . $langs->trans("KSEF_CertificateConfigured") . '</span>';
    }
    if ($cert_valid_to) {
        print '<br><small class="opacitymedium">' . $langs->trans("KSEF_ValidUntil") . ': ' . dol_print_date($cert_valid_to, 'day') . '</small>';
    }
} else {
    print '<span class="badge badge-secondary"><i class="fa fa-certificate"></i> ' . $langs->trans("KSEF_CertificateNotConfigured") . '</span>';
    print '<br><small class="opacitymedium">' . $langs->trans("KSEF_ConfigureCertificateBelow") . '</small>';
}
print '</div></div>';

print '</div>';
print '</td></tr>';
print '</table>';


// Token Configuration
print '<br><table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">' . $langs->trans("KSEF_TOKEN_CONFIG") . '</td></tr>';

print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto($langs->trans("KSEF_AUTH_TOKEN"), $langs->trans("KSEF_AUTH_TOKEN_Help")) . '</td>';
print '<td>';
if ($has_token) {
    print '<div style="margin-bottom: 10px;">';
    print '<span class="badge badge-status4"><i class="fa fa-check-circle"></i> ' . $langs->trans("KSEF_TokenConfigured") . '</span>';
    print '</div>';
    print '<strong>' . $langs->trans("KSEF_UpdateToken") . ':</strong><br>';
}
print '<input type="password" class="flat minwidth400" name="KSEF_AUTH_TOKEN" value="" placeholder="' . ($has_token ? $langs->trans("KSEF_EnterNewTokenToReplace") : $langs->trans("KSEF_PasteTokenHere")) . '" autocomplete="new-password">';
print '<br><span class="opacitymedium">' . $langs->trans("KSEF_TOKEN_OBTAIN_INFO") . '</span>';
print '</td></tr>';
print '</table>';

print '<br><div class="center">';
print '<input type="submit" class="button button-save" value="' . $langs->trans("Save") . '">';
print '</div>';
print '</form>';

print '<div style="display: flex; flex-wrap: wrap; gap: 20px; align-items: flex-start; margin-top: 20px;">';
print '<div style="flex: 1; min-width: 450px;">';

print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '" enctype="multipart/form-data">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="upload_auth_cert">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">' . $langs->trans("KSEF_AUTH_CERTIFICATE_CONFIG") . ' <small class="opacitymedium">(' . $langs->trans("KSEF_ForAPIAuthentication") . ')</small></td></tr>';

print '<tr class="oddeven"><td colspan="2">';

// Current status
if ($has_auth_cert) {
    $cert_valid_to = $conf->global->KSEF_AUTH_CERT_VALID_TO ?? null;
    $is_expired = ($cert_valid_to && $cert_valid_to < dol_now());

    print '<div style="padding: 15px; border-radius: 4px; margin-bottom: 15px; ';
    print 'background-color: ' . ($is_expired ? '#f8d7da' : '#d4edda') . '; ';
    print 'border: 1px solid ' . ($is_expired ? '#f5c6cb' : '#c3e6cb') . ';">';

    print '<table class="nobordernopadding">';
    print '<tr><td><i class="fa fa-check text-success"></i></td><td>' . $langs->trans("KSEF_Certificate") . ' (.crt)</td></tr>';
    print '<tr><td><i class="fa fa-' . (!empty($conf->global->KSEF_AUTH_PRIVATE_KEY) ? 'check text-success' : 'times text-danger') . '"></i></td><td>' . $langs->trans("KSEF_PrivateKey") . ' (.key)</td></tr>';
    print '<tr><td><i class="fa fa-' . (!empty($conf->global->KSEF_AUTH_KEY_PASSWORD) ? 'check text-success' : 'times text-danger') . '"></i></td><td>' . $langs->trans("KSEF_Password") . '</td></tr>';
    print '</table>';

    if (!empty($conf->global->KSEF_AUTH_CERT_SERIAL)) {
        print '<br><strong>' . $langs->trans("KSEF_CertSerial") . ':</strong> ' . dol_escape_htmltag(substr($conf->global->KSEF_AUTH_CERT_SERIAL, 0, 24)) . '...';
    }
    if ($cert_valid_to) {
        print '<br><strong>' . $langs->trans("KSEF_ValidUntil") . ':</strong> ' . dol_print_date($cert_valid_to, 'day');
        if ($is_expired) print ' <span class="badge badge-danger">' . $langs->trans("Expired") . '</span>';
    }

    print '<br><br><a class="button button-cancel small" href="' . $_SERVER["PHP_SELF"] . '?action=remove_auth_cert&token=' . newToken() . '" onclick="return confirm(\'' . dol_escape_js($langs->trans("KSEF_ConfirmRemoveAuthCert")) . '\');">';
    print '<i class="fa fa-trash"></i> ' . $langs->trans("KSEF_RemoveAll") . '</a>';
    print '</div>';
}

// Upload form
print '<div style="background: #f8f9fa; padding: 15px; border-radius: 4px;">';

print '<div style="margin-bottom: 15px;">';
print '<label><strong>' . $langs->trans("KSEF_CertificateFile") . ' (.crt):</strong></label><br>';
print '<input type="file" name="auth_cert_file" accept=".crt,.pem,.cer" class="flat">';
print '</div>';

print '<div style="margin-bottom: 15px;">';
print '<label><strong>' . $langs->trans("KSEF_PrivateKeyFile") . ' (.key):</strong></label><br>';
print '<input type="file" name="auth_key_file" accept=".key,.pem" class="flat">';
print '</div>';

print '<div style="margin-bottom: 15px;">';
print '<label><strong>' . $langs->trans("KSEF_PrivateKeyPassword") . ':</strong></label><br>';
print '<input type="password" name="auth_key_password" class="flat minwidth200" placeholder="' . $langs->trans("KSEF_EnterPassword") . '" autocomplete="new-password">';
print '</div>';

print '<input type="submit" class="button" value="' . $langs->trans("KSEF_SaveAuthCertificate") . '">';
print '</div>';

print '</td></tr>';
print '</table>';
print '</form>';

print '</div>';


print '<div style="flex: 1; min-width: 450px;">';

$has_offline_cert = !empty($conf->global->KSEF_OFFLINE_CERTIFICATE) && !empty($conf->global->KSEF_OFFLINE_PRIVATE_KEY) && !empty($conf->global->KSEF_OFFLINE_KEY_PASSWORD);

print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '" enctype="multipart/form-data">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="upload_offline_cert">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">' . $langs->trans("KSEF_OFFLINE_CERTIFICATE_CONFIG") . ' <small class="opacitymedium">(' . $langs->trans("KSEF_ForQRCodeSigning") . ')</small></td></tr>';

print '<tr class="oddeven"><td colspan="2">';

// Current status
if ($has_offline_cert) {
    $cert_valid_to = $conf->global->KSEF_OFFLINE_CERT_VALID_TO ?? null;
    $is_expired = ($cert_valid_to && $cert_valid_to < dol_now());

    print '<div style="padding: 15px; border-radius: 4px; margin-bottom: 15px; ';
    print 'background-color: ' . ($is_expired ? '#f8d7da' : '#d4edda') . '; ';
    print 'border: 1px solid ' . ($is_expired ? '#f5c6cb' : '#c3e6cb') . ';">';

    print '<table class="nobordernopadding">';
    print '<tr><td><i class="fa fa-check text-success"></i></td><td>' . $langs->trans("KSEF_Certificate") . ' (.crt)</td></tr>';
    print '<tr><td><i class="fa fa-' . (!empty($conf->global->KSEF_OFFLINE_PRIVATE_KEY) ? 'check text-success' : 'times text-danger') . '"></i></td><td>' . $langs->trans("KSEF_PrivateKey") . ' (.key)</td></tr>';
    print '<tr><td><i class="fa fa-' . (!empty($conf->global->KSEF_OFFLINE_KEY_PASSWORD) ? 'check text-success' : 'times text-danger') . '"></i></td><td>' . $langs->trans("KSEF_Password") . '</td></tr>';
    print '</table>';

    if (!empty($conf->global->KSEF_OFFLINE_CERT_SERIAL)) {
        print '<br><strong>' . $langs->trans("KSEF_CertSerial") . ':</strong> ' . dol_escape_htmltag(substr($conf->global->KSEF_OFFLINE_CERT_SERIAL, 0, 24)) . '...';
    }
    if ($cert_valid_to) {
        print '<br><strong>' . $langs->trans("KSEF_ValidUntil") . ':</strong> ' . dol_print_date($cert_valid_to, 'day');
        if ($is_expired) print ' <span class="badge badge-danger">' . $langs->trans("Expired") . '</span>';
    }

    print '<br><br><a class="button button-cancel small" href="' . $_SERVER["PHP_SELF"] . '?action=remove_offline_cert&token=' . newToken() . '" onclick="return confirm(\'' . dol_escape_js($langs->trans("KSEF_ConfirmRemoveOfflineCert")) . '\');">';
    print '<i class="fa fa-trash"></i> ' . $langs->trans("KSEF_RemoveAll") . '</a>';
    print '</div>';
}

// Upload form
print '<div style="background: #f8f9fa; padding: 15px; border-radius: 4px;">';

print '<div style="margin-bottom: 15px;">';
print '<label><strong>' . $langs->trans("KSEF_CertificateFile") . ' (.crt):</strong></label><br>';
print '<input type="file" name="offline_cert_file" accept=".crt,.pem,.cer" class="flat">';
print '</div>';

print '<div style="margin-bottom: 15px;">';
print '<label><strong>' . $langs->trans("KSEF_PrivateKeyFile") . ' (.key):</strong></label><br>';
print '<input type="file" name="offline_key_file" accept=".key,.pem" class="flat">';
print '</div>';

print '<div style="margin-bottom: 15px;">';
print '<label><strong>' . $langs->trans("KSEF_PrivateKeyPassword") . ':</strong></label><br>';
print '<input type="password" name="offline_key_password" class="flat minwidth200" placeholder="' . $langs->trans("KSEF_EnterPassword") . '" autocomplete="new-password">';
print '</div>';

print '<input type="submit" class="button" value="' . $langs->trans("KSEF_SaveOfflineCertificate") . '">';
print '</div>';

print '</td></tr>';
print '</table>';
print '</form>';

print '</div>';

print '</div>';


print '<br><form method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">' . $langs->trans("KSEF_CUSTOMER_EXCLUSIONS") . '</td></tr>';

print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto($langs->trans("KSEF_EXCLUDED_CUSTOMERS"), $langs->trans("KSEF_EXCLUDED_CUSTOMERS_Help")) . '</td>';
print '<td>';

// Show current exclusions
if (!empty($conf->global->KSEF_EXCLUDED_CUSTOMERS)) {
    $excluded_ids = array_filter(array_map('trim', explode(',', $conf->global->KSEF_EXCLUDED_CUSTOMERS)));
    foreach ($excluded_ids as $socid) {
        $tmpsoc = new Societe($db);
        if ($tmpsoc->fetch($socid) > 0) {
            print '<span class="badge badge-secondary" style="margin-right: 5px;">';
            print dol_escape_htmltag($tmpsoc->name);
            print ' <a href="' . $_SERVER["PHP_SELF"] . '?action=remove_excluded&socid=' . $socid . '&token=' . newToken() . '" style="color:white;">×</a>';
            print '</span> ';
        }
    }
    print '<br><br>';
}

// Add new exclusion
print '<select id="excluded_customer" name="socid" class="flat minwidth300">';
print '<option value="">-- ' . $langs->trans("KSEF_SelectCustomer") . ' --</option>';
$sql = "SELECT rowid, nom FROM " . MAIN_DB_PREFIX . "societe WHERE client IN (1, 3) AND entity = " . $conf->entity . " ORDER BY nom";
$resql = $db->query($sql);
if ($resql) {
    while ($obj = $db->fetch_object($resql)) {
        print '<option value="' . $obj->rowid . '">' . dol_escape_htmltag($obj->nom) . '</option>';
    }
}
print '</select> ';
print '<input type="hidden" name="action" value="add_excluded">';
print '<input type="submit" class="button small" value="' . $langs->trans("Add") . '">';
print '</td></tr>';

print '</table>';
print '</form>';

print dol_get_fiche_end();

if (!empty($conf->global->KSEF_COMPANY_NIP)) {
    print '<br><div class="tabsAction">';

    print '<a class="butAction" href="' . $_SERVER["PHP_SELF"] . '?action=testconnection&token=' . newToken() . '">' . $langs->trans("KSEF_TEST_CONNECTION") . '</a>';

    //if token is configured
    if ($has_token) {
        print '<a class="butAction" href="' . $_SERVER["PHP_SELF"] . '?action=testtokenauth&token=' . newToken() . '">' . $langs->trans("KSEF_TEST_TOKEN_AUTH") . '</a>';
    }

    // if certificate is configured
    if ($has_auth_cert) {
        print '<a class="butAction" href="' . $_SERVER["PHP_SELF"] . '?action=testcertauth&token=' . newToken() . '">' . $langs->trans("KSEF_TEST_CERT_AUTH") . '</a>';
    }

    print '</div>';
}

llxFooter();
$db->close();