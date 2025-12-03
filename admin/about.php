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
 * \file    ksef/admin/about.php
 * \ingroup ksef
 * \brief   About page
 */

global $langs;

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
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions2.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/security.lib.php';
dol_include_once('/ksef/lib/ksef.lib.php');

$langs->loadLangs(array("errors", "admin", "ksef@ksef"));

if (!$user->admin) {
    accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');

$requirements = array();

$php_version = phpversion();
$php_meets_requirement = version_compare($php_version, '7.4.0', '>=');
$requirements['php'] = array(
        'label' => 'PHP Version',
        'status' => $php_meets_requirement,
        'critical' => true,
        'description' => 'PHP 7.4 or higher required',
        'version' => $php_version
);

$openssl_loaded = extension_loaded('openssl');
$openssl_version = null;
if ($openssl_loaded && defined('OPENSSL_VERSION_TEXT')) {
    $openssl_version = OPENSSL_VERSION_TEXT;
}
$requirements['openssl'] = array(
        'label' => 'OpenSSL Extension',
        'status' => $openssl_loaded,
        'critical' => true,
        'description' => 'Required for encryption and secure communications',
        'version' => $openssl_version
);

$curl_loaded = extension_loaded('curl');
$curl_version = null;
if ($curl_loaded && function_exists('curl_version')) {
    $curl_info = curl_version();
    $curl_version = isset($curl_info['version']) ? $curl_info['version'] : null;
}
$requirements['curl'] = array(
        'label' => 'cURL Extension',
        'status' => $curl_loaded,
        'critical' => true,
        'description' => 'Required for API communications with KSeF',
        'version' => $curl_version
);

$dom_loaded = extension_loaded('dom');
$dom_version = null;
if ($dom_loaded && defined('LIBXML_DOTTED_VERSION')) {
    $dom_version = 'libxml ' . LIBXML_DOTTED_VERSION;
}
$requirements['dom'] = array(
        'label' => 'DOM Extension',
        'status' => $dom_loaded,
        'critical' => true,
        'description' => 'Required for XML processing',
        'version' => $dom_version
);

require_once __DIR__ . '/../lib/vendor/autoload.php';

$phpseclib_status = false;
$phpseclib_version = null;

try {
    if (class_exists('phpseclib3\\Crypt\\RSA')) {
        $phpseclib_status = true;

        $composer_lock = null;
        if (file_exists(__DIR__ . '/../lib/vendor/composer/installed.json')) {
            $composer_lock = json_decode(
                    file_get_contents(__DIR__ . '/../lib/vendor/composer/installed.json'),
                    true
            );
        }

        if ($composer_lock) {
            $packages = isset($composer_lock['packages']) ? $composer_lock['packages'] : $composer_lock;
            foreach ($packages as $package) {
                if (!empty($package['name']) && $package['name'] === 'phpseclib/phpseclib') {
                    $phpseclib_version = !empty($package['version']) ? $package['version'] : '3.x';
                    break;
                }
            }
        }

        if (!$phpseclib_version) {
            $phpseclib_version = '3.x';
        }
    }
} catch (Exception $e) {
    $phpseclib_status = false;
}


$requirements['phpseclib'] = array(
        'label' => 'phpseclib Library',
        'status' => $phpseclib_status,
        'critical' => true,
        'description' => 'Cryptographic library for RSA encryption, included in Module',
        'version' => $phpseclib_version
);

$tcpdf_status = false;
$tcpdf_version = null;
$tcpdf_has_barcode = false;
try {
    if (file_exists(DOL_DOCUMENT_ROOT . '/includes/tecnickcom/tcpdf/tcpdf.php')) {
        require_once DOL_DOCUMENT_ROOT . '/includes/tecnickcom/tcpdf/tcpdf.php';
        $tcpdf_status = class_exists('TCPDF');

        if ($tcpdf_status && defined('TCPDF_STATIC::$version')) {
            $tcpdf_version = TCPDF_STATIC::$version;
        } elseif ($tcpdf_status && defined('PDF_PRODUCER')) {
            if (preg_match('/TCPDF\s+([\d.]+)/', PDF_PRODUCER, $matches)) {
                $tcpdf_version = $matches[1];
            }
        }

        if (file_exists(DOL_DOCUMENT_ROOT . '/includes/tecnickcom/tcpdf/tcpdf_barcodes_2d.php')) {
            require_once DOL_DOCUMENT_ROOT . '/includes/tecnickcom/tcpdf/tcpdf_barcodes_2d.php';
            $tcpdf_has_barcode = class_exists('TCPDF2DBarcode');
        }
    }
} catch (Exception $e) {
    $tcpdf_status = false;
}

$tcpdf_description = 'Required for QR code generation on PDFs';
if ($tcpdf_status && !$tcpdf_has_barcode) {
    $tcpdf_description .= ' (2D barcode support missing)';
}

$requirements['tcpdf'] = array(
        'label' => 'TCPDF Library',
        'status' => $tcpdf_status && $tcpdf_has_barcode,
        'critical' => false,
        'description' => $tcpdf_description,
        'version' => $tcpdf_version
);

$barcode_module_enabled = !empty($conf->barcode->enabled);
$barcode_module_status = $barcode_module_enabled ? 'Enabled' : 'Disabled';
$requirements['barcode_module'] = array(
        'label' => 'Barcode Module',
        'status' => $barcode_module_enabled,
        'critical' => false,
        'description' => 'Dolibarr Barcode module (recommended for QR codes)',
        'value' => $barcode_module_status
);

$nip_configured = !empty($conf->global->KSEF_COMPANY_NIP);
$requirements['nip'] = array(
        'label' => 'Company NIP',
        'status' => $nip_configured,
        'critical' => true,
        'description' => 'Polish tax identification number',
        'value' => $nip_configured ? $conf->global->KSEF_COMPANY_NIP : null
);

$token_configured = !empty($conf->global->KSEF_AUTH_TOKEN);
$requirements['token'] = array(
        'label' => 'Authentication Token',
        'status' => $token_configured,
        'critical' => true,
        'description' => 'KSeF API authentication token',
        'value' => $token_configured ? '••••••••' : null
);

$environment = !empty($conf->global->KSEF_ENVIRONMENT) ? $conf->global->KSEF_ENVIRONMENT : 'TEST';
$requirements['environment'] = array(
        'label' => 'Environment',
        'status' => true,
        'critical' => false,
        'description' => 'Current KSeF environment',
        'value' => $environment
);

$critical_issues = 0;
$warnings = 0;
foreach ($requirements as $req) {
    if (!$req['status']) {
        if ($req['critical']) {
            $critical_issues++;
        } else {
            $warnings++;
        }
    }
}

$form = new Form($db);

$page_name = "KSEF_About";
llxHeader('', $langs->trans($page_name));

$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1' . '">' . $langs->trans("KSEF_BackToModuleList") . '</a>';

print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

$head = ksefAdminPrepareHead();
print dol_get_fiche_head($head, 'about', $langs->trans("KSEF_Module"), -1, 'ksef@ksef');

dol_include_once('/ksef/core/modules/modKSEF.class.php');
$tmpmodule = new modKSEF($db);

?>

<table class="noborder centpercent">
    <tr class="liste_titre">
        <th>
            <span class="fa fa-info-circle paddingright"></span>
            <?php echo $langs->trans("KSEF_ModuleInformation"); ?>
        </th>
    </tr>
    <tr class="oddeven">
        <td>
            <strong><?php echo $tmpmodule->getName(); ?></strong>
            <br>
            <span class="opacitymedium"><?php echo $tmpmodule->getDesc(); ?></span>
            <br><br>
            <strong><?php echo $langs->trans("KSEF_Version"); ?>:</strong> <?php echo $tmpmodule->getVersion(); ?>
            <br>
            <strong><?php echo $langs->trans("KSEF_Publisher"); ?>:</strong> <?php echo $tmpmodule->getPublisher(); ?>
        </td>
    </tr>
</table>

<br>

<table class="noborder centpercent">
    <tr class="liste_titre">
        <th colspan="2">
            <span class="fa fa-check-circle paddingright"></span>
            <?php echo $langs->trans("KSEF_SystemRequirements"); ?>
            <?php if ($critical_issues > 0) { ?>
                <span class="badge badge-danger" style="float: right;">
                    <?php echo $critical_issues; ?><?php echo $langs->trans("KSEF_CriticalIssues"); ?>
                </span>
            <?php } elseif ($warnings > 0) { ?>
                <span class="badge badge-warning" style="float: right;">
                    <?php echo $warnings; ?><?php echo $langs->trans("KSEF_Warnings"); ?>
                </span>
            <?php } else { ?>
                <span class="badge badge-success" style="float: right;">
                    <?php echo $langs->trans("KSEF_AllRequirementsMet"); ?>
                </span>
            <?php } ?>
        </th>
    </tr>

    <?php foreach ($requirements as $key => $req) { ?>
        <tr class="oddeven">
            <td style="width: 50%;">
                <strong><?php echo $req['label']; ?></strong>
                <?php if ($req['critical']) { ?>
                    <span class="badge badge-danger"
                          style="margin-left: 5px;"><?php echo $langs->trans("KSEF_Required"); ?></span>
                <?php } ?>
                <br>
                <span class="opacitymedium"><?php echo $req['description']; ?></span>
            </td>
            <td style="text-align: right;">
                <?php if ($req['status']) { ?>
                    <span class="badge badge-status4" style="font-size: 1.1em;">
                        <i class="fa fa-check-circle"></i> <?php echo $langs->trans("KSEF_OK"); ?>
                    </span>
                    <?php if (!empty($req['version'])) { ?>
                        <br><span class="opacitymedium"><?php echo $req['version']; ?></span>
                    <?php } ?>
                    <?php if (!empty($req['value']) && empty($req['version'])) { ?>
                        <br><span class="opacitymedium"><?php echo $req['value']; ?></span>
                    <?php } ?>
                <?php } else { ?>
                    <?php if ($req['critical']) { ?>
                        <span class="badge badge-danger" style="font-size: 1.1em;">
                            <i class="fa fa-times-circle"></i> <?php echo $langs->trans("KSEF_Missing"); ?>
                        </span>
                    <?php } else { ?>
                        <span class="badge badge-warning" style="font-size: 1.1em;">
                            <i class="fa fa-exclamation-triangle"></i> <?php echo $langs->trans("KSEF_NotConfigured"); ?>
                        </span>
                    <?php } ?>
                    <?php if (!empty($req['value'])) { ?>
                        <br><span class="opacitymedium"><?php echo $req['value']; ?></span>
                    <?php } ?>
                <?php } ?>
            </td>
        </tr>
    <?php } ?>
</table>

<?php if ($critical_issues > 0) { ?>
    <br>
    <div class="error">
        <strong><?php echo $langs->trans("KSEF_ActionRequired"); ?>:</strong>
        <?php echo $langs->trans("KSEF_CriticalRequirementsNotMet"); ?>
        <a href="<?php echo dol_buildpath('/ksef/admin/setup.php', 1); ?>"><?php echo $langs->trans("KSEF_GoToSetup"); ?></a>
    </div>
<?php } ?>

<?php if ($warnings > 0 && $critical_issues == 0) { ?>
    <br>
    <div class="warning">
        <strong><?php echo $langs->trans("KSEF_Note"); ?>:</strong>
        <?php echo $langs->trans("KSEF_SomeOptionalFeaturesUnavailable"); ?>
        <?php if (!$barcode_module_enabled) { ?>
            <br>• <?php echo $langs->trans("KSEF_EnableBarcodeModuleForQR"); ?>
        <?php } ?>
        <?php if (!$tcpdf_status || !$tcpdf_has_barcode) { ?>
            <br>• <?php echo $langs->trans("KSEF_TCPDFRequiredForQR"); ?>
        <?php } ?>
    </div>
<?php } ?>

<br>

<?php

print dol_get_fiche_end();
llxFooter();
$db->close();
?>
