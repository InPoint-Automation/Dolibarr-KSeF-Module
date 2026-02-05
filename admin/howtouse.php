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
 * \file    ksef/admin/howtouse.php
 * \ingroup ksef
 * \brief   KSEF How-To Page
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

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
dol_include_once('/ksef/lib/ksef.lib.php');

if (!$user->admin) {
    accessforbidden();
}

$langs->loadLangs(array("ksef@ksef", "admin"));

$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');

$page_name = "KSEF_HowToUse";

// Get current environment
$current_env = !empty($conf->global->KSEF_ENVIRONMENT) ? $conf->global->KSEF_ENVIRONMENT : 'DEMO';

if ($action == 'testconnection') {
    dol_include_once('/ksef/class/ksef_client.class.php');

    $environment = $current_env;

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

    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

if ($action == 'testcertauth') {
    dol_include_once('/ksef/class/ksef_client.class.php');

    $environment = $current_env;

    $has_auth_cert = !empty($conf->global->KSEF_AUTH_CERTIFICATE) &&
            !empty($conf->global->KSEF_AUTH_PRIVATE_KEY) &&
            !empty($conf->global->KSEF_AUTH_KEY_PASSWORD);

    if (!$has_auth_cert) {
        setEventMessages($langs->trans('KSEF_CertificateNotConfigured'), null, 'errors');
        header("Location: " . $_SERVER["PHP_SELF"]);
        exit;
    }

    $orig_method = $conf->global->KSEF_AUTH_METHOD;
    $conf->global->KSEF_AUTH_METHOD = 'certificate';

    try {
        $client = new KsefClient($db, $environment);

        if ($client->authenticate()) {
            setEventMessages(
                    $langs->trans('KSEF_CERT_AUTH_SUCCESS') . ' [' . $environment . ']',
                    null,
                    'mesgs'
            );
        } else {
            setEventMessages(
                    $langs->trans('KSEF_CERT_AUTH_FAILED') . ': ' . $client->error,
                    null,
                    'errors'
            );
        }
    } catch (Exception $e) {
        setEventMessages(
                $langs->trans('KSEF_CERT_AUTH_ERROR') . ': ' . $e->getMessage(),
                null,
                'errors'
        );
    }

    $conf->global->KSEF_AUTH_METHOD = $orig_method;

    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

if ($action == 'testtokenauth') {
    dol_include_once('/ksef/class/ksef_client.class.php');

    $environment = $current_env;

    $orig_method = $conf->global->KSEF_AUTH_METHOD;
    $conf->global->KSEF_AUTH_METHOD = 'token';

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

    $conf->global->KSEF_AUTH_METHOD = $orig_method;

    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

$form = new Form($db);

llxHeader('', $langs->trans($page_name));

$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1' . '">' . $langs->trans("KSEF_BackToModuleList") . '</a>';
print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

$head = ksefAdminPrepareHead();
print dol_get_fiche_head($head, 'howtouse', $langs->trans("KSEF_Module"), -1, 'ksef@ksef');

$has_token = !empty($conf->global->KSEF_AUTH_TOKEN);
$has_auth_cert = !empty($conf->global->KSEF_AUTH_CERTIFICATE) &&
        !empty($conf->global->KSEF_AUTH_PRIVATE_KEY) &&
        !empty($conf->global->KSEF_AUTH_KEY_PASSWORD);

?>

    <table class="noborder centpercent">
        <tr class="liste_titre">
            <th><span class="fa fa-bolt paddingright"></span><?php echo $langs->trans("KSEF_QuickStartGuide"); ?></th>
        </tr>
        <tr class="oddeven">
            <td>
                <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 220px; padding: 15px; background: #f8f9fa; border-left: 4px solid #dc3545;">
                        <h4 style="margin-top: 0;">1. <?php echo $langs->trans("KSEF_GetCredentials"); ?></h4>
                        <p><?php echo $langs->trans("KSEF_GetCredentialsDesc"); ?></p>
                        <p><strong><?php echo $langs->trans("KSEF_Portals"); ?>:</strong></p>
                        <?php
                        $portals = array(
                                'PRODUCTION' => array('url' => 'https://ap.ksef.mf.gov.pl/web/', 'label' => $langs->trans('KSEF_ENV_PRODUCTION')),
                                'DEMO' => array('url' => 'https://ap-demo.ksef.mf.gov.pl/web/', 'label' => $langs->trans('KSEF_ENV_DEMO')),
                                'TEST' => array('url' => 'https://ap-test.ksef.mf.gov.pl/web/', 'label' => $langs->trans('KSEF_ENV_TEST')),
                        );
                        foreach ($portals as $env => $portal) {
                            $is_active = ($env === $current_env);
                            $style = $is_active ? 'font-weight: bold;' : 'opacity: 0.5;';
                            $icon = $is_active ? '<span class="fa fa-check-circle" style="color: #28a745;"></span> ' : '';
                            print '<div style="' . $style . ' margin-bottom: 4px;">';
                            print $icon . '<a href="' . $portal['url'] . '" target="_blank">' . $portal['label'] . '</a>';
                            if ($is_active) {
                                print ' <small>(' . $langs->trans("KSEF_CurrentEnvironment") . ')</small>';
                            }
                            print '</div>';
                        }
                        ?>

                        <div style="margin-top: 12px; padding: 10px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px;">
                            <span class="fa fa-exclamation-triangle" style="color: #856404;"></span>
                            <strong><?php echo $langs->trans("KSEF_Important"); ?>:</strong>
                            <?php echo $langs->trans("KSEF_PermissionsWarning"); ?>
                        </div>
                    </div>

                    <div style="flex: 1; min-width: 220px; padding: 15px; background: #f8f9fa; border-left: 4px solid #fd7e14;">
                        <h4 style="margin-top: 0;">2. <?php echo $langs->trans("KSEF_ConfigureModule"); ?></h4>
                        <p><?php echo $langs->trans("KSEF_ConfigureModuleDesc"); ?></p>
                        <p><?php echo $langs->trans("KSEF_DefaultEnvironmentNote"); ?></p>
                        <p><a href="<?php echo dol_buildpath('/ksef/admin/setup.php', 1); ?>" class="button small">
                                <span class="fa fa-cog paddingright"></span><?php echo $langs->trans("KSEF_GoToSetup"); ?>
                            </a></p>
                    </div>

                    <div style="flex: 1; min-width: 220px; padding: 15px; background: #f8f9fa; border-left: 4px solid #ffc107;">
                        <h4 style="margin-top: 0;">3. <?php echo $langs->trans("KSEF_TestConnection"); ?></h4>
                        <p><?php echo $langs->trans("KSEF_TestConnectionDesc"); ?></p>

                        <a href="<?php echo $_SERVER["PHP_SELF"]; ?>?action=testconnection&token=<?php echo newToken(); ?>"
                           class="button small" style="margin-bottom: 8px; display: block;">
                            <span class="fa fa-plug paddingright"></span><?php echo $langs->trans("KSEF_TEST_CONNECTION"); ?>
                        </a>

                        <?php if ($has_token) { ?>
                            <a href="<?php echo $_SERVER["PHP_SELF"]; ?>?action=testtokenauth&token=<?php echo newToken(); ?>"
                               class="button small" style="margin-bottom: 8px; display: block;">
                                <span class="fa fa-key paddingright"></span><?php echo $langs->trans("KSEF_TEST_TOKEN_AUTH"); ?>
                            </a>
                        <?php } else { ?>
                            <span class="button small butActionRefused classfortooltip"
                                  style="margin-bottom: 8px; display: block; opacity: 0.6; cursor: not-allowed;"
                                  title="<?php echo $langs->trans('KSEF_ConfigureTokenFirst'); ?>">
                            <span class="fa fa-key paddingright"></span><?php echo $langs->trans("KSEF_TEST_TOKEN_AUTH"); ?>
                        </span>
                        <?php } ?>

                        <?php if ($has_auth_cert) { ?>
                            <a href="<?php echo $_SERVER["PHP_SELF"]; ?>?action=testcertauth&token=<?php echo newToken(); ?>"
                               class="button small" style="margin-bottom: 8px; display: block;">
                                <span class="fa fa-certificate paddingright"></span><?php echo $langs->trans("KSEF_TEST_CERT_AUTH"); ?>
                            </a>
                        <?php } else { ?>
                            <span class="button small butActionRefused classfortooltip"
                                  style="margin-bottom: 8px; display: block; opacity: 0.6; cursor: not-allowed;"
                                  title="<?php echo $langs->trans('KSEF_ConfigureCertificateFirst'); ?>">
                            <span class="fa fa-certificate paddingright"></span><?php echo $langs->trans("KSEF_TEST_CERT_AUTH"); ?>
                        </span>
                        <?php } ?>
                    </div>

                    <div style="flex: 1; min-width: 220px; padding: 15px; background: #f8f9fa; border-left: 4px solid #28a745;">
                        <h4 style="margin-top: 0;">4. <?php echo $langs->trans("KSEF_SubmitFirstInvoice"); ?></h4>
                        <p><?php echo $langs->trans("KSEF_SubmitFirstInvoiceDesc"); ?></p>
                    </div>

                </div>
            </td>
        </tr>
    </table>

    <br>

    <table class="noborder centpercent">
        <tr class="liste_titre">
            <th><span class="fa fa-cog paddingright"></span><?php echo $langs->trans("KSEF_ConfigurationSection"); ?></th>
        </tr>
        <tr class="oddeven">
            <td>
                <h4><?php echo $langs->trans("KSEF_Environments"); ?></h4>
                <ul>
                    <li><strong><?php echo $langs->trans("KSEF_ENV_TEST"); ?></strong> — <?php echo $langs->trans("KSEF_ENV_TEST_FullDesc"); ?></li>
                    <li><strong><?php echo $langs->trans("KSEF_ENV_DEMO"); ?></strong> — <?php echo $langs->trans("KSEF_ENV_DEMO_FullDesc"); ?></li>
                    <li><strong><?php echo $langs->trans("KSEF_ENV_PRODUCTION"); ?></strong> — <?php echo $langs->trans("KSEF_ENV_PRODUCTION_FullDesc"); ?></li>
                </ul>

                <h4><?php echo $langs->trans("KSEF_Authentication"); ?></h4>
                <p><?php echo $langs->trans("KSEF_AuthenticationDesc"); ?></p>

                <h4><?php echo $langs->trans("KSEF_OptionalFields"); ?></h4>
                <p><?php echo $langs->trans("KSEF_OptionalFieldsDesc"); ?></p>

                <h4><?php echo $langs->trans("KSEF_CompanyIdentifiers"); ?></h4>
                <p><?php echo $langs->trans("KSEF_CompanyIdentifiersDesc"); ?></p>
            </td>
        </tr>
    </table>

    <br>

    <table class="noborder centpercent">
        <tr class="liste_titre">
            <th><span class="fa fa-file-invoice paddingright"></span><?php echo $langs->trans("KSEF_SendingInvoices"); ?></th>
        </tr>
        <tr class="oddeven">
            <td>
                <h4><?php echo $langs->trans("KSEF_TheWorkflow"); ?></h4>
                <ol>
                    <li><?php echo $langs->trans("KSEF_Workflow_Step1"); ?></li>
                    <li><?php echo $langs->trans("KSEF_Workflow_Step2"); ?></li>
                    <li><?php echo $langs->trans("KSEF_Workflow_Step3"); ?>
                        <ul>
                            <li><strong><?php echo $langs->trans("KSEF_ValidateAndUpload"); ?></strong> — <?php echo $langs->trans("KSEF_ValidateAndUpload_Desc"); ?></li>
                            <li><strong><?php echo $langs->trans("KSEF_UploadToKSEF"); ?></strong> — <?php echo $langs->trans("KSEF_UploadToKSEF_Desc"); ?></li>
                        </ul>
                    </li>
                    <li><?php echo $langs->trans("KSEF_Workflow_Step4"); ?></li>
                </ol>

                <h4><?php echo $langs->trans("KSEF_Statuses"); ?></h4>
                <ul style="list-style: none; padding-left: 0;">
                    <li style="margin-bottom: 8px;"><span class="badge badge-status4"><?php echo $langs->trans("KSEF_STATUS_ACCEPTED"); ?></span> — <?php echo $langs->trans("KSEF_STATUS_ACCEPTED_Desc"); ?></li>
                    <li style="margin-bottom: 8px;"><span class="badge badge-status3"><?php echo $langs->trans("KSEF_STATUS_PENDING"); ?></span> — <?php echo $langs->trans("KSEF_STATUS_PENDING_Desc"); ?></li>
                    <li style="margin-bottom: 8px;"><span class="badge badge-status8"><?php echo $langs->trans("KSEF_STATUS_REJECTED"); ?></span> / <span class="badge badge-status8"><?php echo $langs->trans("KSEF_STATUS_FAILED"); ?></span> — <?php echo $langs->trans("KSEF_STATUS_REJECTED_Desc"); ?></li>
                    <li style="margin-bottom: 8px;"><span class="badge badge-status1"><?php echo $langs->trans("KSEF_STATUS_OFFLINE"); ?></span> — <?php echo $langs->trans("KSEF_STATUS_OFFLINE_Desc"); ?></li>
                </ul>

                <h4><?php echo $langs->trans("KSEF_ForeignCurrencyInvoices"); ?></h4>
                <p><?php echo $langs->trans("KSEF_ForeignCurrencyDesc"); ?></p>

                <h4><?php echo $langs->trans("KSEF_AfterAcceptance"); ?></h4>
                <p><?php echo $langs->trans("KSEF_AfterAcceptanceDesc"); ?></p>

                <h4><?php echo $langs->trans("KSEF_CheckingStatus"); ?></h4>
                <p><?php echo $langs->trans("KSEF_CheckingStatusDesc"); ?>
                    <a href="<?php echo dol_buildpath('/ksef/status.php', 1); ?>"><?php echo $langs->trans("KSEF_SubmissionStatus"); ?></a>
                </p>
            </td>
        </tr>
    </table>

    <br>

    <table class="noborder centpercent">
        <tr class="liste_titre">
            <th><span class="fa fa-download paddingright"></span><?php echo $langs->trans("KSEF_ReceivingInvoices"); ?></th>
        </tr>
        <tr class="oddeven">
            <td>
                <p><?php echo $langs->trans("KSEF_ReceivingInvoicesDesc"); ?></p>
                <ol>
                    <li><?php echo $langs->trans("KSEF_Receiving_Step1"); ?>
                        <a href="<?php echo dol_buildpath('/ksef/incoming_list.php', 1); ?>"><?php echo $langs->trans("KSEF_IncomingInvoices"); ?></a>
                    </li>
                    <li><?php echo $langs->trans("KSEF_Receiving_Step2"); ?></li>
                    <li><?php echo $langs->trans("KSEF_Receiving_Step3"); ?></li>
                </ol>
                <p><?php echo $langs->trans("KSEF_ReceivingActions"); ?></p>

                <h4><?php echo $langs->trans("KSEF_ImportingToDolibarr"); ?></h4>
                <p><?php echo $langs->trans("KSEF_ImportingToDolibarrDesc"); ?></p>
            </td>
        </tr>
    </table>

    <br>

    <table class="noborder centpercent">
        <tr class="liste_titre">
            <th><span class="fa fa-user-slash paddingright"></span><?php echo $langs->trans("KSEF_CustomerExclusions"); ?></th>
        </tr>
        <tr class="oddeven">
            <td>
                <p><?php echo $langs->trans("KSEF_CustomerExclusionsDesc"); ?></p>
            </td>
        </tr>
    </table>

    <br>

    <table class="noborder centpercent">
        <tr class="liste_titre">
            <th><span class="fa fa-wrench paddingright"></span><?php echo $langs->trans("KSEF_Troubleshooting"); ?></th>
        </tr>
        <tr class="oddeven">
            <td>
                <h4><?php echo $langs->trans("KSEF_FindingErrorDetails"); ?></h4>
                <p><?php echo $langs->trans("KSEF_FindingErrorDetailsDesc"); ?>
                    <a href="<?php echo dol_buildpath('/ksef/status.php', 1); ?>"><?php echo $langs->trans("KSEF_SubmissionStatus"); ?></a>
                </p>

                <h4><?php echo $langs->trans("KSEF_CommonIssues"); ?></h4>

                <p><strong><?php echo $langs->trans("KSEF_Issue_DuplicateNumber"); ?></strong><br>
                    <?php echo $langs->trans("KSEF_Issue_DuplicateNumber_Desc"); ?></p>

                <p><strong><?php echo $langs->trans("KSEF_Issue_AuthFailed"); ?></strong><br>
                    <?php echo $langs->trans("KSEF_Issue_AuthFailed_Desc"); ?></p>

                <p><strong><?php echo $langs->trans("KSEF_Issue_NBPMissing"); ?></strong><br>
                    <?php echo $langs->trans("KSEF_Issue_NBPMissing_Desc"); ?></p>

                <p><strong><?php echo $langs->trans("KSEF_Issue_Timeout"); ?></strong><br>
                    <?php echo $langs->trans("KSEF_Issue_Timeout_Desc"); ?></p>

                <h4><?php echo $langs->trans("KSEF_RetryLimits"); ?></h4>
                <p><?php echo $langs->trans("KSEF_RetryLimitsDesc"); ?></p>
            </td>
        </tr>
    </table>

<?php

print dol_get_fiche_end();

llxFooter();
$db->close();
?>