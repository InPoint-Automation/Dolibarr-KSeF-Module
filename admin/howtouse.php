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
 * \file    ksef/admin/howtouse.php
 * \ingroup ksef
 * \brief   KSEF How-To Page
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

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
dol_include_once('/ksef/lib/ksef.lib.php');

if (!$user->admin) {
    accessforbidden();
}

$langs->loadLangs(array("ksef@ksef", "admin"));

$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');

$page_name = "KSEF_HowToUse";

if ($action == 'testconnection') {
    dol_include_once('/ksef/class/ksef_client.class.php');

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

    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

if ($action == 'testcertauth') {
    dol_include_once('/ksef/class/ksef_client.class.php');

    $environment = !empty($conf->global->KSEF_ENVIRONMENT) ? $conf->global->KSEF_ENVIRONMENT : 'TEST';

    $has_auth_cert = !empty($conf->global->KSEF_AUTH_CERTIFICATE) &&
            !empty($conf->global->KSEF_AUTH_PRIVATE_KEY) &&
            !empty($conf->global->KSEF_AUTH_KEY_PASSWORD);

    if (!$has_auth_cert) {
        setEventMessages($langs->trans('KSEF_CertificateNotConfigured'), null, 'errors');
        header("Location: " . $_SERVER["PHP_SELF"]);
        exit;
    }

    $orig_method = $conf->global->KSEF_AUTH_METHOD;
    dolibarr_set_const($db, 'KSEF_AUTH_METHOD', 'certificate', 'chaine', 0, '', $conf->entity);

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

    dolibarr_set_const($db, 'KSEF_AUTH_METHOD', $orig_method, 'chaine', 0, '', $conf->entity);

    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

if ($action == 'testtokenauth') {
    dol_include_once('/ksef/class/ksef_client.class.php');

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

    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

$form = new Form($db);

llxHeader('', $langs->trans($page_name));

$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1' . '">' . $langs->trans("KSEF_BackToModuleList") . '</a>';
print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

$head = ksefAdminPrepareHead();
print dol_get_fiche_head($head, 'howtouse', $langs->trans("KSEF_Module"), -1, 'ksef@ksef');

print '<span class="opacitymedium">' . $langs->trans("KSEF_HowToUsePage") . '</span><br><br>';

?>

<table class="noborder centpercent">
    <tr class="liste_titre">
        <th>
            <span class="fa fa-bolt paddingright"></span>
            <?php echo $langs->trans("KSEF_QuickStartGuide"); ?>
        </th>
    </tr>
    <tr class="oddeven">
        <td>
            <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                <div style="flex: 1; min-width: 200px; padding: 15px; background: #f8f9fa; border-left: 4px solid #dc3545;">
                    <h4 style="margin-top: 0;">1. <?php echo $langs->trans("KSEF_GetToken"); ?></h4>
                    <p><?php echo $langs->trans("KSEF_VisitKSEFPortal"); ?>:<br>
                        <a href="https://ksef.mf.gov.pl" target="_blank">ksef.mf.gov.pl</a></p>
                    <p><?php echo $langs->trans("KSEF_GenerateTokenInPortal"); ?></p>
                </div>

                <div style="flex: 1; min-width: 200px; padding: 15px; background: #f8f9fa; border-left: 4px solid #fd7e14;">
                    <h4 style="margin-top: 0;">2. <?php echo $langs->trans("KSEF_ConfigureModule"); ?></h4>
                    <p>
                        <a href="<?php echo dol_buildpath('/ksef/admin/setup.php', 1); ?>"><?php echo $langs->trans("KSEF_GoToSetup"); ?></a>
                    </p>
                    <p><?php echo $langs->trans("KSEF_EnterNIPAndToken"); ?></p>
                </div>

                <div style="flex: 1; min-width: 200px; padding: 15px; background: #f8f9fa; border-left: 4px solid #ffc107;">
                    <h4 style="margin-top: 0;">3. <?php echo $langs->trans("KSEF_TestConnection"); ?></h4>

                    <?php
                    $has_token = !empty($conf->global->KSEF_AUTH_TOKEN);
                    $has_auth_cert = !empty($conf->global->KSEF_AUTH_CERTIFICATE) &&
                            !empty($conf->global->KSEF_AUTH_PRIVATE_KEY) &&
                            !empty($conf->global->KSEF_AUTH_KEY_PASSWORD);
                    ?>

                    <a href="<?php echo $_SERVER["PHP_SELF"]; ?>?action=testconnection&token=<?php echo newToken(); ?>"
                       class="button small" style="margin-bottom: 8px; display: block;">
                        <span class="fa fa-plug paddingright"></span>
                        <?php echo $langs->trans("KSEF_TEST_CONNECTION"); ?>
                    </a>

                    <?php if ($has_token) { ?>
                        <a href="<?php echo $_SERVER["PHP_SELF"]; ?>?action=testtokenauth&token=<?php echo newToken(); ?>"
                           class="button small" style="margin-bottom: 8px; display: block;">
                            <span class="fa fa-key paddingright"></span>
                            <?php echo $langs->trans("KSEF_TEST_TOKEN_AUTH"); ?>
                        </a>
                    <?php } else { ?>
                        <span class="button small butActionRefused classfortooltip"
                              style="margin-bottom: 8px; display: block; opacity: 0.6; cursor: not-allowed;"
                              title="<?php echo $langs->trans('KSEF_ConfigureTokenFirst'); ?>">
                            <span class="fa fa-key paddingright"></span>
                            <?php echo $langs->trans("KSEF_TEST_TOKEN_AUTH"); ?>
                        </span>
                        <small class="opacitymedium"><?php echo $langs->trans('KSEF_TokenNotConfigured'); ?></small>
                    <?php } ?>

                    <?php if ($has_auth_cert) { ?>
                        <a href="<?php echo $_SERVER["PHP_SELF"]; ?>?action=testcertauth&token=<?php echo newToken(); ?>"
                           class="button small" style="margin-bottom: 8px; display: block;">
                            <span class="fa fa-certificate paddingright"></span>
                            <?php echo $langs->trans("KSEF_TEST_CERT_AUTH"); ?>
                        </a>
                    <?php } else { ?>
                        <span class="button small butActionRefused classfortooltip"
                              style="margin-bottom: 8px; display: block; opacity: 0.6; cursor: not-allowed;"
                              title="<?php echo $langs->trans('KSEF_ConfigureCertificateFirst'); ?>">
                            <span class="fa fa-certificate paddingright"></span>
                            <?php echo $langs->trans("KSEF_TEST_CERT_AUTH"); ?>
                        </span>
                        <small class="opacitymedium"><?php echo $langs->trans('KSEF_CertificateNotConfigured'); ?></small>
                    <?php } ?>
                </div>

                <div style="flex: 1; min-width: 200px; padding: 15px; background: #f8f9fa; border-left: 4px solid #28a745;">
                    <h4 style="margin-top: 0;">4. <?php echo $langs->trans("KSEF_SubmitInvoice"); ?></h4>
                    <p><?php echo $langs->trans("KSEF_OpenAnyInvoice"); ?></p>
                    <p><?php echo $langs->trans("KSEF_ClickKSEFButton"); ?></p>
                </div>
            </div>

        </td>
    </tr>
</table>

<br>

<table class="noborder centpercent">
    <tr class="liste_titre">
        <th>
            <span class="fa fa-file-invoice paddingright"></span>
            <?php echo $langs->trans("KSEF_UsingTheModule"); ?>
        </th>
    </tr>
    <tr class="oddeven">
        <td>
            <h4><?php echo $langs->trans("KSEF_SubmittingInvoices"); ?></h4>
            <p><?php echo $langs->trans("KSEF_SubmitInvoiceSteps"); ?>:</p>
            <ol>
                <li><?php echo $langs->trans("KSEF_OpenValidatedInvoice"); ?></li>
                <li><?php echo $langs->trans("KSEF_ClickRedKSEFButton"); ?></li>
                <li><?php echo $langs->trans("KSEF_WaitForConfirmation"); ?></li>
            </ol>

            <h4><?php echo $langs->trans("KSEF_CheckingStatus"); ?></h4>
            <ul>
                <li><span class="badge badge-status4"><?php echo $langs->trans("KSEF_ACCEPTED"); ?></span>
                    — <?php echo $langs->trans("KSEF_InvoiceAccepted"); ?></li>
                <li><span class="badge badge-status8"><?php echo $langs->trans("KSEF_REJECTED"); ?></span>
                    — <?php echo $langs->trans("KSEF_FixAndRetry"); ?></li>
                <li><span class="badge badge-status3"><?php echo $langs->trans("KSEF_PENDING"); ?></span>
                    — <?php echo $langs->trans("KSEF_BeingProcessed"); ?></li>
            </ul>

            <p><?php echo $langs->trans("KSEF_ViewAllSubmissions"); ?>: <a
                        href="<?php echo dol_buildpath('/ksef/status.php', 1); ?>"><?php echo $langs->trans("KSEF_SubmissionStatus"); ?></a>
            </p>
        </td>
    </tr>
</table>

<br>

<table class="noborder centpercent">
    <tr class="liste_titre">
        <th>
            <span class="fa fa-wrench paddingright"></span>
            <?php echo $langs->trans("KSEF_Troubleshooting"); ?>
        </th>
    </tr>
    <tr class="oddeven">
        <td>
            <h4><?php echo $langs->trans("KSEF_ViewingErrorDetails"); ?></h4>
            <p><?php echo $langs->trans("KSEF_ErrorDetailsLocation"); ?>:</p>
            <ol>
                <li><?php echo $langs->trans("KSEF_OpenInvoiceCard"); ?></li>
                <li><?php echo $langs->trans("KSEF_ScrollToKSEFSection"); ?></li>
                <li><?php echo $langs->trans("KSEF_ClickInfoIcon"); ?> (<span class="fa fa-info-circle"></span>)</li>
            </ol>
        </td>
    </tr>
</table>


<?php

print dol_get_fiche_end();

llxFooter();
$db->close();
?>
