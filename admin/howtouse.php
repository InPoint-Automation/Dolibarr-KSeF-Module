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
dol_include_once('/ksef/lib/ksef_tour.lib.php');

if (!ksefUserCanReadModule($user)) {
    accessforbidden();
}

$langs->loadLangs(array("ksef@ksef", "admin"));

$backtopage = GETPOST('backtopage', 'alpha');

$page_name = "KSEF_HowToUse";

$current_env = ksefGetEnvironment();

$form = new Form($db);

llxHeader('', $langs->trans($page_name));

$linkback = !empty($user->admin) ? '<a href="' . DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1' . '">' . $langs->trans("KSEF_BackToModuleList") . '</a>' : '';
print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

$head = ksefAdminPrepareHead();
print dol_get_fiche_head($head, 'howtouse', $langs->trans("KSEF_Module"), -1, 'ksef@ksef');
print ksefShowDolibarrVersionWarning();
$isAdmin = !empty($user->admin);

$guideSections = array(
    'quickstart' => array('KSEF_QuickStartGuide', array('setup_company', 'setup_env', 'setup_auth', 'setup_vat', 'setup_defaults', 'setup_incoming', 'setup_optional')),
    'sending' => array('KSEF_SendingInvoices', array('send_vat', 'send_offline')),
    'corrections' => array('KSEF_HowTo_Corrections', array('send_kor', 'send_kor_zal')),
    'deposits' => array('KSEF_HowTo_Deposits', array('send_zal', 'send_zal_pay', 'send_roz', 'send_kor_zal')),
    'reconcile' => array('KSEF_HowTo_ReconcileTitle', array('send_sales')),
    'receiving' => array('KSEF_ReceivingInvoices', array('recv_fetch', 'recv_import', 'recv_kor', 'recv_deposits')),
);
$guide = array();
$guideDialogs = '';
foreach ($guideSections as $guideKey => $guideDef) {
    $g = ksefTourGuideHtml($user, $guideKey, $guideDef[0], $guideDef[1]);
    $guide[$guideKey] = $g['button'] !== '' ? '<span class="nowraponall ksef-howto-guidehead">' . $g['button'] . '</span>' : '';
    $guideDialogs .= $g['dialog'];
}

?>

    <div class="opacitymedium ksef-howto-intro"><?php echo $langs->trans("KSEF_HowTo_Intro"); ?> &middot;
        <a href="<?php echo dol_buildpath('/ksef/ksefindex.php', 1); ?>#ksef-help"><?php echo $langs->trans("KSEF_Help_ChecklistLink"); ?></a></div>

    <table class="noborder centpercent">
        <tr class="liste_titre ksef-section-head" id="ksef-howto-sec-quickstart">
            <th><?php echo $guide['quickstart']; ?><span class="fas fa-bolt paddingright"></span><?php echo $langs->trans("KSEF_QuickStartGuide"); ?><?php echo ksefSectionDesc("KSEF_QuickStartGuide_Desc"); ?></th>
        </tr>
        <tr class="oddeven">
            <td>
                <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 220px; padding: 15px; background: var(--ksef-surface, #f8f9fa); border-left: 4px solid #dc3545;">
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
                            $icon = $is_active ? '<span class="fas fa-check-circle ' . ksefToneTextClass('ok') . '"></span> ' : '';
                            print '<div style="' . $style . ' margin-bottom: 4px;">';
                            print $icon . '<a href="' . $portal['url'] . '" target="_blank">' . $portal['label'] . '</a>';
                            if ($is_active) {
                                print ' <small>(' . $langs->trans("KSEF_CurrentEnvironment") . ')</small>';
                            }
                            print '</div>';
                        }
                        ?>

                        <div class="ksef-note-check" style="margin-top: 12px; padding: 10px; border-radius: 4px;">
                            <span class="fas fa-exclamation-triangle <?php echo ksefToneTextClass('check'); ?>"></span>
                            <strong><?php echo $langs->trans("KSEF_Important"); ?>:</strong>
                            <?php echo $langs->trans("KSEF_PermissionsWarning"); ?>
                        </div>
                    </div>

                    <div style="flex: 1; min-width: 220px; padding: 15px; background: var(--ksef-surface, #f8f9fa); border-left: 4px solid #fd7e14;">
                        <h4 style="margin-top: 0;">2. <?php echo $langs->trans("KSEF_ConfigureModule"); ?></h4>
                        <p><?php echo $langs->trans("KSEF_ConfigureModuleDesc"); ?></p>
                        <p><?php echo $langs->trans("KSEF_DefaultEnvironmentNote"); ?></p>
                        <?php if ($isAdmin) { ?>
                        <p><a href="<?php echo dol_buildpath('/ksef/admin/setup_auth.php', 1); ?>" class="button small">
                                <span class="fas fa-cog paddingright"></span><?php echo $langs->trans("KSEF_GoToSetup"); ?>
                            </a></p>
                        <?php } ?>
                    </div>

                    <div style="flex: 1; min-width: 220px; padding: 15px; background: var(--ksef-surface, #f8f9fa); border-left: 4px solid #ffc107;">
                        <h4 style="margin-top: 0;">3. <?php echo $langs->trans("KSEF_TestConnection"); ?></h4>
                        <p><?php echo $langs->trans("KSEF_TestConnectionDesc"); ?></p>

                        <?php if ($isAdmin) { ?>
                            <a href="<?php echo dol_buildpath('/ksef/admin/setup_auth.php', 1); ?>#env-<?php echo $current_env; ?>"
                               class="button small ksef-howto-testlink" style="margin-bottom: 8px; display: block;">
                                <span class="fas fa-plug paddingright"></span><?php echo $langs->trans("KSEF_TestOnAuthTab"); ?>
                            </a>
                        <?php } else { ?>
                            <p class="opacitymedium"><?php echo $langs->trans("KSEF_TestAskAdmin"); ?></p>
                        <?php } ?>
                    </div>

                    <div style="flex: 1; min-width: 220px; padding: 15px; background: var(--ksef-surface, #f8f9fa); border-left: 4px solid #28a745;">
                        <h4 style="margin-top: 0;">4. <?php echo $langs->trans("KSEF_SubmitFirstInvoice"); ?></h4>
                        <p><?php echo $langs->trans("KSEF_SubmitFirstInvoiceDesc"); ?></p>
                    </div>

                </div>
            </td>
        </tr>
    </table>

    <br>

    <table class="noborder centpercent">
        <tr class="liste_titre ksef-section-head" id="ksef-howto-sec-config">
            <th><span class="fas fa-cog paddingright"></span><?php echo $langs->trans("KSEF_ConfigurationSection"); ?><?php echo ksefSectionDesc("KSEF_ConfigurationSection_Desc"); ?></th>
        </tr>
        <tr class="oddeven">
            <td>
                <h4><?php echo $langs->trans("KSEF_Environments"); ?></h4>
                <ul>
                    <li><strong><?php echo $langs->trans("KSEF_ENV_TEST"); ?></strong> - <?php echo $langs->trans("KSEF_ENV_TEST_FullDesc"); ?></li>
                    <li><strong><?php echo $langs->trans("KSEF_ENV_DEMO"); ?></strong> - <?php echo $langs->trans("KSEF_ENV_DEMO_FullDesc"); ?></li>
                    <li><strong><?php echo $langs->trans("KSEF_ENV_PRODUCTION"); ?></strong> - <?php echo $langs->trans("KSEF_ENV_PRODUCTION_FullDesc"); ?></li>
                </ul>
                <p><?php echo $langs->trans("KSEF_HowTo_EnvFilter"); ?></p>

                <h4><?php echo $langs->trans("KSEF_Authentication"); ?></h4>
                <p><?php echo $langs->trans("KSEF_AuthenticationDesc"); ?></p>

                <h4><?php echo $langs->trans("KSEF_HowTo_Config_PerEnvAuth"); ?></h4>
                <p><?php echo $langs->trans("KSEF_HowTo_Config_PerEnvAuthDesc"); ?></p>

                <h4 id="ksef-howto-rights"><?php echo $langs->trans("KSEF_HowTo_Rights"); ?></h4>
                <p><?php echo $langs->trans("KSEF_HowTo_RightsDesc"); ?></p>

                <h4><?php echo $langs->trans("KSEF_HowTo_Config_PaymentDefaults"); ?></h4>
                <p><?php echo $langs->trans("KSEF_HowTo_Config_PaymentDefaultsDesc"); ?></p>

                <h4><?php echo $langs->trans("KSEF_OptionalFields"); ?></h4>
                <p><?php echo $langs->trans("KSEF_OptionalFieldsDesc"); ?></p>

                <h4><?php echo $langs->trans("KSEF_HowTo_Config_Notes"); ?></h4>
                <p><?php echo $langs->trans("KSEF_HowTo_Config_NotesIntro"); ?></p>
                <p><?php echo $langs->trans("KSEF_HowTo_Config_NotesModes"); ?></p>
                <div style="background: var(--ksef-surface, #f8f9fa); border-left: 3px solid #6c757d; padding: 8px 12px; margin: 8px 0; font-family: monospace; font-size: 13px;">
                    <?php echo $langs->trans("KSEF_HowTo_Config_NotesKeyValueExample1"); ?><br>
                    <?php echo $langs->trans("KSEF_HowTo_Config_NotesKeyValueExample2"); ?>
                </div>
                <p><?php echo $langs->trans("KSEF_HowTo_Config_CustomFields"); ?></p>
                <p><?php echo $langs->trans("KSEF_HowTo_Config_CustomFieldsTarget"); ?></p>
                <p><?php echo $langs->trans("KSEF_HowTo_Config_Limits"); ?></p>

                <h4><?php echo $langs->trans("KSEF_HowTo_Config_OrderContract"); ?></h4>
                <p><?php echo $langs->trans("KSEF_HowTo_Config_OrderContractDesc"); ?></p>

                <h4><?php echo $langs->trans("KSEF_HowTo_Config_Boilerplate"); ?></h4>
                <p><?php echo $langs->trans("KSEF_HowTo_Config_BoilerplateDesc"); ?></p>

                <h4><?php echo $langs->trans("KSEF_CompanyIdentifiers"); ?></h4>
                <p><?php echo $langs->trans("KSEF_CompanyIdentifiersDesc"); ?></p>

                <h4><?php echo $langs->trans("KSEF_CountryRequirement"); ?></h4>
                <p><?php echo $langs->trans("KSEF_CountryRequirementDesc"); ?></p>

                <h4><?php echo $langs->trans("KSEF_TranslationOverridesHowTo"); ?></h4>
                <p><?php echo $langs->trans("KSEF_TranslationOverridesHowToDesc"); ?></p>

                <h4><?php echo $langs->trans("KSEF_HowTo_Config_VatRates"); ?></h4>
                <p><?php echo $langs->trans("KSEF_HowTo_Config_VatRatesIntro"); ?></p>
                <p><b><?php echo $langs->trans("KSEF_HowTo_Config_VatRatesCodes_Intro"); ?></b></p>
                <ol>
                    <li><?php echo $langs->trans("KSEF_HowTo_Config_VatRatesCodes_Step1"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_Config_VatRatesCodes_Step2"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_Config_VatRatesCodes_Step3"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_Config_VatRatesCodes_Step4"); ?></li>
                </ol>
                <p><?php echo $langs->trans("KSEF_HowTo_Config_VatRatesCodes_Outro"); ?></p>
                <div style="background: var(--ksef-surface, #f8f9fa); border-left: 3px solid #6c757d; padding: 8px 12px; margin: 8px 0; font-size: 13px;">
                    <table style="width:100%; border-collapse:collapse;">
                        <tr style="border-bottom:1px solid #ccc;">
                            <th style="text-align:left; padding:4px;"><?php echo $langs->trans("KSEF_HowTo_Config_VatRatesTable_ColCode"); ?></th>
                            <th style="text-align:left; padding:4px;"><?php echo $langs->trans("KSEF_HowTo_Config_VatRatesTable_ColUse"); ?></th>
                        </tr>
                        <tr style="border-bottom:1px solid var(--ksef-surface-border, #eee);">
                            <td style="padding:4px;"><i><?php echo $langs->trans("KSEF_HowTo_Config_VatRatesTable_None"); ?></i></td>
                            <td style="padding:4px;"><?php echo $langs->trans("KSEF_HowTo_Config_VatRatesTable_NoneUse"); ?></td>
                        </tr>
                        <tr style="border-bottom:1px solid var(--ksef-surface-border, #eee);">
                            <td style="padding:4px;"><b>ZW</b></td>
                            <td style="padding:4px;"><?php echo $langs->trans("KSEF_HowTo_Config_VatRatesTable_ZWUse"); ?></td>
                        </tr>
                        <tr style="border-bottom:1px solid var(--ksef-surface-border, #eee);">
                            <td style="padding:4px;"><b>RC</b></td>
                            <td style="padding:4px;"><?php echo $langs->trans("KSEF_HowTo_Config_VatRatesTable_RCUse"); ?></td>
                        </tr>
                        <tr style="border-bottom:1px solid var(--ksef-surface-border, #eee);">
                            <td style="padding:4px;"><b>NP</b></td>
                            <td style="padding:4px;"><?php echo $langs->trans("KSEF_HowTo_Config_VatRatesTable_NPUse"); ?></td>
                        </tr>
                        <tr style="border-bottom:1px solid var(--ksef-surface-border, #eee);">
                            <td style="padding:4px;"><b>NP2</b></td>
                            <td style="padding:4px;"><?php echo $langs->trans("KSEF_HowTo_Config_VatRatesTable_NP2Use"); ?></td>
                        </tr>
                        <tr style="border-bottom:1px solid var(--ksef-surface-border, #eee);">
                            <td style="padding:4px;"><b>WDT</b></td>
                            <td style="padding:4px;"><?php echo $langs->trans("KSEF_HowTo_Config_VatRatesTable_WDTUse"); ?></td>
                        </tr>
                        <tr>
                            <td style="padding:4px;"><b>EX</b></td>
                            <td style="padding:4px;"><?php echo $langs->trans("KSEF_HowTo_Config_VatRatesTable_EXUse"); ?></td>
                        </tr>
                    </table>
                </div>
                <p><?php echo $langs->trans("KSEF_HowTo_Config_VatRatesSetup"); ?></p>
                <p><?php echo $langs->trans("KSEF_HowTo_Config_VatRates5"); ?></p>

                <h4><?php echo $langs->trans("KSEF_HowTo_Config_VatExemptSetup"); ?></h4>
                <p><?php echo $langs->trans("KSEF_HowTo_Config_VatExemptIntro"); ?></p>
                <p><b><?php echo $langs->trans("KSEF_HowTo_Config_VatExemptSteps_S1Title"); ?></b></p>
                <ol>
                    <li><?php echo $langs->trans("KSEF_HowTo_Config_VatExemptSteps_S1_1"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_Config_VatExemptSteps_S1_2"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_Config_VatExemptSteps_S1_3"); ?></li>
                </ol>
                <p><?php echo $langs->trans("KSEF_HowTo_Config_VatExemptSteps_S1Outro"); ?></p>
                <p><b><?php echo $langs->trans("KSEF_HowTo_Config_VatExemptSteps_S2Title"); ?></b></p>
                <ol>
                    <li><?php echo $langs->trans("KSEF_HowTo_Config_VatExemptSteps_S2_1"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_Config_VatExemptSteps_S2_2"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_Config_VatExemptSteps_S2_3"); ?></li>
                </ol>
                <p><?php echo $langs->trans("KSEF_HowTo_Config_VatExemptSteps_S2Outro"); ?></p>

                <h4><?php echo $langs->trans("KSEF_HowTo_AdvancedFields"); ?></h4>
                <p><?php echo $langs->trans("KSEF_HowTo_AdvancedFields_Intro"); ?></p>

                <p><b><?php echo $langs->trans("KSEF_HowTo_EntityFields"); ?></b></p>
                <ul>
                    <li><?php echo $langs->trans("KSEF_HowTo_EntityFields_IDNabywcy"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_EntityFields_EORI"); ?></li>
                </ul>

                <p><b><?php echo $langs->trans("KSEF_HowTo_LineFields"); ?></b></p>
                <ul>
                    <li><?php echo $langs->trans("KSEF_HowTo_LineFields_GTU"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_LineFields_Procedura"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_LineFields_UUID"); ?></li>
                </ul>

                <p><b><?php echo $langs->trans("KSEF_HowTo_InvoiceFlags"); ?></b></p>
                <ul>
                    <li><?php echo $langs->trans("KSEF_HowTo_InvoiceFlags_FP"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_InvoiceFlags_TP"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_InvoiceFlags_MPP"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_InvoiceFlags_P16"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_InvoiceFlags_P17"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_InvoiceFlags_P18"); ?></li>
                </ul>

                <h4><?php echo $langs->trans("KSEF_HowTo_Podmiot3"); ?></h4>
                <p><?php echo $langs->trans("KSEF_HowTo_Podmiot3_Intro"); ?></p>
                <ol>
                    <li><?php echo $langs->trans("KSEF_HowTo_Podmiot3_Step1"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_Podmiot3_Step2"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_Podmiot3_Step3"); ?></li>
                </ol>
                <p><?php echo $langs->trans("KSEF_HowTo_Podmiot3_Reconciliation"); ?></p>

                <p><b><?php echo $langs->trans("KSEF_HowTo_Podmiot3_JSTGV_Title"); ?></b></p>
                <p><?php echo $langs->trans("KSEF_HowTo_Podmiot3_JSTGV"); ?></p>

                <p><b><?php echo $langs->trans("KSEF_HowTo_Podmiot3_IDWew_Title"); ?></b></p>
                <p><?php echo $langs->trans("KSEF_HowTo_Podmiot3_IDWew_What"); ?></p>
                <p><?php echo $langs->trans("KSEF_HowTo_Podmiot3_IDWew_How"); ?></p>

                <p><b><?php echo $langs->trans("KSEF_HowTo_Podmiot3_Limits_Title"); ?></b></p>
                <ul>
                    <li><?php echo $langs->trans("KSEF_HowTo_Podmiot3_Faktor"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_Podmiot3_Role4"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_Podmiot3_Subject3"); ?></li>
                </ul>
            </td>
        </tr>
    </table>

    <br>

    <table class="noborder centpercent">
        <tr class="liste_titre ksef-section-head">
            <th><span class="fas fa-search paddingright"></span><?php echo $langs->trans("KSEF_GusHowTo_Title"); ?><?php echo ksefSectionDesc("KSEF_GusHowTo_Title_Desc"); ?></th>
        </tr>
        <tr class="oddeven">
            <td>
                <p><?php echo $langs->trans("KSEF_GusHowTo_Intro"); ?></p>
                <p><?php echo $langs->trans("KSEF_GusHowTo_KeyTest"); ?></p>
                <p><?php echo $langs->trans("KSEF_GusHowTo_KeyProd"); ?></p>
                <ol>
                    <li><?php echo $langs->trans("KSEF_GusHowTo_Step1"); ?></li>
                    <li><?php echo $langs->trans("KSEF_GusHowTo_Step2"); ?></li>
                    <li><?php echo $langs->trans("KSEF_GusHowTo_Step3"); ?></li>
                    <li><?php echo $langs->trans("KSEF_GusHowTo_Step4"); ?></li>
                </ol>
                <p><strong><?php echo $langs->trans("KSEF_GusRequestKeyTitle"); ?></strong></p>
                <ol>
                    <li><?php echo $langs->trans("KSEF_GusKeyApplyStep1"); ?></li>
                    <li><?php echo $langs->trans("KSEF_GusKeyApplyStep2"); ?></li>
                    <li><?php echo $langs->trans("KSEF_GusKeyApplyStep3"); ?></li>
                    <li><?php echo $langs->trans("KSEF_GusKeyApplyStep4"); ?></li>
                </ol>
                <p><?php echo $langs->trans("KSEF_GusHowTo_EmailLabel"); ?></p>
                <pre style="background: var(--ksef-surface, #f5f5f5); border: 1px solid var(--ksef-surface-border, #ddd); padding: 10px; white-space: pre-wrap;"><?php echo htmlspecialchars(ksefGusRequestEmail()); ?></pre>
            </td>
        </tr>
    </table>

    <br>

    <table class="noborder centpercent ksef-howto-jobs">
        <tr class="liste_titre ksef-section-head">
            <th><span class="fas fa-clock paddingright"></span><?php echo $langs->trans("KSEF_HowTo_JobsTitle"); ?><?php echo ksefSectionDesc("KSEF_HowTo_JobsTitle_Desc"); ?></th>
        </tr>
        <tr class="oddeven">
            <td>
                <h4 id="ksef-howto-contact"><?php echo $langs->trans("KSEF_HowTo_ContactTitle"); ?></h4>
                <p><?php echo $langs->trans("KSEF_HowTo_ContactDesc"); ?></p>

                <h4 id="ksef-howto-nbp"><?php echo $langs->trans("KSEF_HowTo_NbpTitle"); ?></h4>
                <p><?php echo $langs->trans("KSEF_HowTo_NbpDesc"); ?></p>

                <h4 id="ksef-howto-jobs"><?php echo $langs->trans("KSEF_HowTo_JobsIntroTitle"); ?></h4>
                <p><?php echo $langs->trans("KSEF_HowTo_JobsIntro"); ?></p>
                <table class="noborder ksef-howto-jobs-table">
                    <tr class="liste_titre">
                        <th><?php echo $langs->trans("KSEF_HowTo_JobsColJob"); ?></th>
                        <th><?php echo $langs->trans("KSEF_HowTo_JobsColDefault"); ?></th>
                    </tr>
                    <?php
                    $howtoJobs = array(
                        'cronCheckStatuses' => 1, 'cronSyncIncoming' => 0, 'cronDownloadUPOs' => 0,
                        'cronWarnDeadlines' => 1, 'cronCheckLatarniaStatus' => 1, 'cronSyncMulticurrencyRates' => 0,
                    );
                    foreach ($howtoJobs as $howtoMethod => $howtoOn) {
                        print '<tr class="oddeven" data-ksef-job="' . $howtoMethod . '"><td>' . $langs->trans('KSEF_CronJob_' . $howtoMethod) . '</td>';
                        print '<td>' . $langs->trans($howtoOn ? 'KSEF_CronEnabled' : 'KSEF_CronDisabled') . '</td></tr>';
                    }
                    ?>
                </table>
                <p><?php echo $langs->trans("KSEF_HowTo_JobsEnable"); ?>
                    <a href="<?php echo DOL_URL_ROOT; ?>/cron/list.php?search_module_name=ksef"><?php echo $langs->trans("KSEF_HowTo_JobsListLink"); ?></a>
                </p>
            </td>
        </tr>
    </table>

    <br>

    <table class="noborder centpercent">
        <tr class="liste_titre ksef-section-head" id="ksef-howto-sec-sending">
            <th><?php echo $guide['sending']; ?><span class="fas fa-file-invoice paddingright"></span><?php echo $langs->trans("KSEF_SendingInvoices"); ?><?php echo ksefSectionDesc("KSEF_SendingInvoices_Desc"); ?></th>
        </tr>
        <tr class="oddeven">
            <td>
                <h4><?php echo $langs->trans("KSEF_TheWorkflow"); ?></h4>
                <ol>
                    <li><?php echo $langs->trans("KSEF_Workflow_Step1"); ?></li>
                    <li><?php echo $langs->trans("KSEF_Workflow_Step2"); ?></li>
                    <li><?php echo $langs->trans("KSEF_Workflow_Step3"); ?>
                        <ul>
                            <li><strong><?php echo $langs->trans("KSEF_ValidateAndUpload"); ?></strong> - <?php echo $langs->trans("KSEF_ValidateAndUpload_Desc"); ?></li>
                            <li><strong><?php echo $langs->trans("KSEF_UploadToKSEF"); ?></strong> - <?php echo $langs->trans("KSEF_UploadToKSEF_Desc"); ?></li>
                        </ul>
                    </li>
                    <li><?php echo $langs->trans("KSEF_Workflow_Step4"); ?></li>
                </ol>

                <h4><?php echo $langs->trans("KSEF_HowTo_Sending_PaymentFirst"); ?></h4>
                <p><?php echo $langs->trans("KSEF_HowTo_Sending_PaymentFirstDesc"); ?></p>

                <h4><?php echo $langs->trans("KSEF_Statuses"); ?></h4>
                <ul style="list-style: none; padding-left: 0;">
                    <li style="margin-bottom: 8px;"><span class="badge badge-status4"><?php echo $langs->trans("KSEF_STATUS_ACCEPTED"); ?></span> - <?php echo $langs->trans("KSEF_STATUS_ACCEPTED_Desc"); ?></li>
                    <li style="margin-bottom: 8px;"><span class="badge badge-status3"><?php echo $langs->trans("KSEF_STATUS_PENDING"); ?></span> - <?php echo $langs->trans("KSEF_STATUS_PENDING_Desc"); ?></li>
                    <li style="margin-bottom: 8px;"><?php echo ksefGetStatusBadge('TIMEOUT'); ?> - <?php echo $langs->trans("KSEF_STATUS_TIMEOUT_Desc"); ?></li>
                    <li style="margin-bottom: 8px;"><?php echo ksefGetStatusBadge('INTERRUPTED'); ?> - <?php echo $langs->trans("KSEF_STATUS_INTERRUPTED_Desc"); ?></li>
                    <li style="margin-bottom: 8px;"><span class="badge badge-status8"><?php echo $langs->trans("KSEF_STATUS_REJECTED"); ?></span> / <span class="badge badge-status8"><?php echo $langs->trans("KSEF_STATUS_FAILED"); ?></span> - <?php echo $langs->trans("KSEF_STATUS_REJECTED_Desc"); ?></li>
                    <li style="margin-bottom: 8px;"><?php echo ksefGetStatusBadge('OFFLINE'); ?> - <?php echo $langs->trans("KSEF_STATUS_OFFLINE_Desc"); ?></li>
                </ul>

                <h4><?php echo $langs->trans("KSEF_ForeignCurrencyInvoices"); ?></h4>
                <p><?php echo $langs->trans("KSEF_ForeignCurrencyDesc"); ?></p>

                <h4><?php echo $langs->trans("KSEF_HowTo_Offline"); ?></h4>
                <p><?php echo $langs->trans("KSEF_HowTo_OfflineDesc"); ?></p>
                <ul>
                    <li><?php echo $langs->trans("KSEF_HowTo_OfflineWhen"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_Offline1"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_Offline2"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_Offline3"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_Offline4"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_Offline5"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_OfflineTest"); ?></li>
                </ul>
                <p class="ksef-howto-offline-do"><?php echo $langs->trans("KSEF_HowTo_OfflineDo"); ?></p>

                <h4><?php echo $langs->trans("KSEF_BankAccountOnInvoice"); ?></h4>
                <p><?php echo $langs->trans("KSEF_BankAccountOnInvoiceDesc"); ?></p>
                <ol>
                    <li><?php echo $langs->trans("KSEF_BankAccount_Step1"); ?></li>
                    <li><?php echo $langs->trans("KSEF_BankAccount_Step2"); ?></li>
                    <li><?php echo $langs->trans("KSEF_BankAccount_Step3"); ?></li>
                    <li><?php echo $langs->trans("KSEF_BankAccount_Step4"); ?></li>
                </ol>

                <h4><?php echo $langs->trans("KSEF_HowTo_Sending_PDFPreview"); ?></h4>
                <p><?php echo $langs->trans("KSEF_HowTo_Sending_PDFPreviewDesc"); ?></p>

                <h4><?php echo $langs->trans("KSEF_HowTo_Sending_Notes"); ?></h4>
                <p><?php echo $langs->trans("KSEF_HowTo_Sending_NotesEditing"); ?></p>
                <p><?php echo $langs->trans("KSEF_HowTo_Sending_NotesPreview"); ?></p>
                <p><?php echo $langs->trans("KSEF_HowTo_Sending_NotesOverride"); ?></p>

                <h4><?php echo $langs->trans("KSEF_AfterAcceptance"); ?></h4>
                <p><?php echo $langs->trans("KSEF_AfterAcceptanceDesc"); ?></p>

                <h4><?php echo $langs->trans("KSEF_HowTo_EditSent"); ?></h4>
                <p><?php echo $langs->trans("KSEF_HowTo_EditSentDesc"); ?></p>

                <h4><?php echo $langs->trans("KSEF_HowTo_NumberNoRecord"); ?></h4>
                <p><?php echo $langs->trans("KSEF_HowTo_NumberNoRecordDesc"); ?></p>

                <h4><?php echo $langs->trans("KSEF_CheckingStatus"); ?></h4>
                <p><?php echo $langs->trans("KSEF_CheckingStatusDesc"); ?>
                    <a href="<?php echo dol_buildpath('/ksef/status.php', 1); ?>"><?php echo $langs->trans("KSEF_OutgoingInvoices"); ?></a>
                </p>

                <h4 id="ksef-howto-hash-search"><?php echo $langs->trans("KSEF_HowTo_HashSearch"); ?></h4>
                <p><?php echo $langs->trans("KSEF_HowTo_HashSearchDesc"); ?></p>
                <ul style="list-style: none; padding-left: 0;" class="ksef-howto-hash-statuses">
                    <?php
                    $hashStatuses = array('INTERRUPTED' => 'KSEF_HowTo_HashSearch_Searching', 'NOT_FOUND' => 'KSEF_HowTo_HashSearch_NotFound',
                        'REPLACED' => 'KSEF_HowTo_HashSearch_Replaced', 'IN_SALES_UNLINKED' => 'KSEF_HowTo_HashSearch_InSales',
                        'AMBIGUOUS' => 'KSEF_HowTo_HashSearch_Ambiguous', 'PROBABLY_IN_KSEF' => 'KSEF_HowTo_HashSearch_Held');
                    foreach ($hashStatuses as $hashCode => $hashKey) {
                        print '<li style="margin-bottom: 8px;">' . ksefGetStatusBadge($hashCode) . ' - ' . $langs->trans($hashKey) . '</li>';
                    }
                    ?>
                </ul>

                <h4 id="ksef-howto-exclusions"><?php echo $langs->trans("KSEF_CustomerExclusions"); ?></h4>
                <p><?php echo $langs->trans("KSEF_CustomerExclusionsDesc"); ?></p>
            </td>
        </tr>
    </table>

    <br>

    <table class="noborder centpercent">
        <tr class="liste_titre ksef-section-head" id="ksef-howto-sec-corrections">
            <th><?php echo $guide['corrections']; ?><span class="fas fa-exchange-alt paddingright"></span><?php echo $langs->trans("KSEF_HowTo_Corrections"); ?><?php echo ksefSectionDesc("KSEF_HowTo_Corrections_Desc"); ?></th>
        </tr>
        <tr class="oddeven">
            <td>
                <p><?php echo $langs->trans("KSEF_HowTo_Corrections_Intro"); ?></p>

                <h4><?php echo $langs->trans("KSEF_HowTo_Corrections_WhenTitle"); ?></h4>
                <ul>
                    <li><?php echo $langs->trans("KSEF_HowTo_Corrections_Replacement"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_Corrections_CreditNote"); ?></li>
                </ul>

                <h4><?php echo $langs->trans("KSEF_HowTo_Corrections_CreateTitle"); ?></h4>
                <p><?php echo $langs->trans("KSEF_HowTo_Corrections_CreateButton"); ?></p>
                <p><?php echo $langs->trans("KSEF_HowTo_Corrections_CreateRadio"); ?></p>
                <p><?php echo $langs->trans("KSEF_HowTo_Corrections_PaidInvoice"); ?></p>

                <h4><?php echo $langs->trans("KSEF_HowTo_Corrections_DetailsTitle"); ?></h4>
                <p><?php echo $langs->trans("KSEF_HowTo_Corrections_Reason"); ?></p>
                <p><?php echo $langs->trans("KSEF_HowTo_Corrections_Type"); ?></p>

                <h4><?php echo $langs->trans("KSEF_HowTo_Corrections_KsefTitle"); ?></h4>
                <p><?php echo $langs->trans("KSEF_HowTo_Corrections_KsefXml"); ?></p>
                <ul>
                    <li><?php echo $langs->trans("KSEF_HowTo_Corrections_MethodDiff"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_Corrections_MethodStan"); ?></li>
                </ul>
                <p><?php echo $langs->trans("KSEF_HowTo_Corrections_CreditNoteDiff"); ?></p>

                <h4><?php echo $langs->trans("KSEF_HowTo_Corrections_ChainTitle"); ?></h4>
                <p><?php echo $langs->trans("KSEF_HowTo_Corrections_Chain"); ?></p>

                <h4 id="ksef-correction-reports"><?php echo $langs->trans("KSEF_HowTo_Corrections_ReportsTitle"); ?></h4>
                <p><?php echo $langs->trans("KSEF_HowTo_Corrections_ReportsIntro"); ?></p>
                <table class="noborder centpercent ksef-correction-reports">
                    <tr class="liste_titre">
                        <th><?php echo $langs->trans("KSEF_HowTo_Corrections_ReportsColReport"); ?></th>
                        <th><?php echo $langs->trans("KSEF_HowTo_Corrections_ReportsColEffect"); ?></th>
                    </tr>
                    <?php
                    foreach (array('Journal', 'Recap', 'Vat', 'Result', 'Localtax', 'Stats', 'Simple') as $ksefReport) {
                        print '<tr class="oddeven" data-ksef-report="' . strtolower($ksefReport) . '">';
                        print '<td>' . $langs->trans('KSEF_HowTo_Corrections_Reports' . $ksefReport) . '</td>';
                        print '<td>' . $langs->trans('KSEF_HowTo_Corrections_Reports' . $ksefReport . 'Effect') . '</td>';
                        print '</tr>';
                    }
                    ?>
                </table>

                <h4><?php echo $langs->trans("KSEF_HowTo_Corrections_ConfigTitle"); ?></h4>
                <p><?php echo $langs->trans("KSEF_HowTo_Corrections_Config"); ?></p>
            </td>
        </tr>
    </table>

    <br>

    <table class="noborder centpercent ksef-howto-deposits">
        <tr class="liste_titre ksef-section-head" id="ksef-howto-sec-deposits">
            <th><?php echo $guide['deposits']; ?><span class="fas fa-hand-holding-usd paddingright"></span><?php echo $langs->trans("KSEF_HowTo_Deposits"); ?><?php echo ksefSectionDesc("KSEF_HowTo_Deposits_Desc"); ?></th>
        </tr>
        <tr class="oddeven">
            <td>
                <p><?php echo $langs->trans("KSEF_HowTo_DepositsDesc"); ?></p>
                <ol>
                    <li><?php echo $langs->trans("KSEF_HowTo_Deposits_Step1"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_Deposits_Step2"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_Deposits_Step3"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_Deposits_Step4"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_Deposits_Step5"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_Deposits_Step6"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_Deposits_Step7"); ?></li>
                </ol>
                <h4><?php echo $langs->trans("KSEF_HowTo_Deposits_FullTitle"); ?></h4>
                <p><?php echo $langs->trans("KSEF_HowTo_Deposits_Full"); ?></p>
                <h4><?php echo $langs->trans("KSEF_HowTo_Deposits_RulesTitle"); ?></h4>
                <ul>
                    <li><?php echo $langs->trans("KSEF_HowTo_Deposits_Rule1"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_Deposits_Rule2"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_Deposits_Rule3"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_Deposits_Rule4"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_Deposits_Rule5"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_Deposits_Rule6"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_Deposits_Rule7"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_Deposits_Rule8"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_Deposits_Rule9"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_Deposits_Rule10"); ?></li>
                </ul>
            </td>
        </tr>
    </table>

    <br>

    <table class="noborder centpercent ksef-howto-reconcile">
        <tr class="liste_titre ksef-section-head" id="ksef-howto-sec-reconcile">
            <th><?php echo $guide['reconcile']; ?><span class="fas fa-balance-scale paddingright"></span><?php echo $langs->trans("KSEF_HowTo_ReconcileTitle"); ?><?php echo ksefSectionDesc("KSEF_HowTo_ReconcileTitle_Desc"); ?></th>
        </tr>
        <tr class="oddeven">
            <td>
                <p><?php echo $langs->trans("KSEF_HowTo_ReconcileDesc"); ?></p>
                <p><?php echo $langs->trans("KSEF_HowTo_ReconcileImport"); ?></p>
                <p class="ksef-howto-reconcile-upo"><?php echo $langs->trans("KSEF_HowTo_ReconcileUpo"); ?></p>
                <p><?php echo $langs->trans("KSEF_HowTo_ReconcileReset"); ?></p>
            </td>
        </tr>
    </table>

    <br>

    <table class="noborder centpercent">
        <tr class="liste_titre ksef-section-head" id="ksef-howto-sec-receiving">
            <th><?php echo $guide['receiving']; ?><span class="fas fa-download paddingright"></span><?php echo $langs->trans("KSEF_ReceivingInvoices"); ?><?php echo ksefSectionDesc("KSEF_ReceivingInvoices_Desc"); ?></th>
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
                <p class="opacitymedium"><?php echo $langs->trans("KSEF_TourUi_NoneTestNip"); ?></p>

                <h4><?php echo $langs->trans("KSEF_ImportingToDolibarr"); ?></h4>
                <p><?php echo $langs->trans("KSEF_ImportingToDolibarrDesc"); ?></p>

                <h4><?php echo $langs->trans("KSEF_Importing_HowItWorks"); ?></h4>
                <ol>
                    <li><?php echo $langs->trans("KSEF_Importing_Step1"); ?></li>
                    <li><?php echo $langs->trans("KSEF_Importing_Step2"); ?></li>
                    <li><?php echo $langs->trans("KSEF_Importing_Step3"); ?></li>
                    <li><?php echo $langs->trans("KSEF_Importing_Step4"); ?></li>
                    <li><?php echo $langs->trans("KSEF_Importing_Step5"); ?></li>
                </ol>

                <p><strong><?php echo $langs->trans("KSEF_Importing_Individual"); ?></strong> - <?php echo $langs->trans("KSEF_Importing_IndividualDesc"); ?></p>
                <p><strong><?php echo $langs->trans("KSEF_Importing_Batch"); ?></strong> - <?php echo $langs->trans("KSEF_Importing_BatchDesc"); ?></p>

                <p><?php echo $langs->trans("KSEF_Importing_StockNote"); ?>
                    <a href="<?php echo DOL_URL_ROOT; ?>/admin/stock.php"><?php echo $langs->trans("KSEF_Importing_StockNoteLink"); ?></a>.
                    <?php echo $langs->trans("KSEF_Importing_StockNoteFreeText"); ?>
                </p>
                <p><strong><?php echo $langs->trans("KSEF_Importing_Totals"); ?></strong> - <?php echo $langs->trans("KSEF_Importing_TotalsDesc"); ?></p>
            </td>
        </tr>
    </table>

    <br>

<?php
$langs->loadLangs(array('bills', 'banks', 'accountancy', 'main'));
$payL = function ($key) use ($langs) {
    return $langs->transnoentitiesnoconv($key);
};
?>
    <table class="noborder centpercent ksef-howto-payments">
        <tr class="liste_titre ksef-section-head">
            <th><span class="fas fa-coins paddingright"></span><?php echo $langs->trans("KSEF_HowTo_Pay"); ?><?php echo ksefSectionDesc("KSEF_HowTo_Pay_Desc"); ?></th>
        </tr>
        <tr class="oddeven">
            <td>
                <p id="ksef-howto-pay"><?php echo $langs->trans("KSEF_HowTo_Pay_Intro"); ?></p>
                <ul class="ksef-howto-pay-tools">
                    <li class="ksef-howto-pay-short"><?php echo $langs->trans("KSEF_HowTo_Pay_Short", $payL('ClassifyPaidPartially'), $payL('ConfirmClassifyPaidPartiallyReasonBankCharge'), $payL('Other')); ?>
                        <?php echo $langs->trans("KSEF_HowTo_Pay_ShortAccount", $payL('AccountingJournalType1'), $payL('ReOpen')); ?></li>
                    <li class="ksef-howto-pay-extra"><?php echo $langs->trans("KSEF_HowTo_Pay_Extra", $payL('NewVariousPayment'), $payL('MenuVariousPayment')); ?></li>
                    <li class="ksef-howto-pay-over"><?php echo $langs->trans("KSEF_HowTo_Pay_Over", $payL('ConvertExcessReceivedToReduc'), $payL('ConvertExcessPaidToReduc')); ?></li>
                    <li class="ksef-howto-pay-later"><?php echo $langs->trans("KSEF_HowTo_Pay_PayLater", $payL('DoPayment')); ?></li>
                    <li class="ksef-howto-pay-discount"><?php echo $langs->trans("KSEF_HowTo_Pay_Discount", $payL('KSEF_CreateCorrection')); ?></li>
                </ul>
                <p class="ksef-howto-pay-eur"><?php echo $langs->trans("KSEF_HowTo_Pay_Eur", $payL('KSEF_PayFxInfoTitle'), $payL('NewVariousPayment')); ?></p>
                <p class="ksef-howto-pay-mpp"><?php echo $langs->trans("KSEF_HowTo_Pay_Mpp"); ?></p>
                <p class="ksef-howto-pay-accountant"><?php echo $langs->trans("KSEF_HowTo_Pay_Accountant"); ?></p>
                <?php if ($isAdmin && isModEnabled('accounting')) { ?>
                <p><a href="<?php echo DOL_URL_ROOT; ?>/accountancy/admin/account.php#ksef-chart-load" class="ksef-howto-pay-settings">
                    <span class="fas fa-cog paddingright"></span><?php echo $langs->trans("KSEF_HowTo_Pay_Settings"); ?></a></p>
                <?php } ?>
            </td>
        </tr>
    </table>

    <br>

<?php

$restBase = DOL_MAIN_URL_ROOT . '/api/index.php/ksefapi/';
$restExplorer = DOL_MAIN_URL_ROOT . '/api/index.php/explorer/';
$restRoutes = array(
    'KSEF_HowTo_RestGroupSubmissions' => array(
        'GET /submissions', 'GET /submissions/stats', 'GET /submissions/{id}', 'GET /submissions/invoice/{invoice_id}',
        'POST /submissions/{invoice_id}/submit', 'POST /submissions/{invoice_id}/submit-offline', 'POST /submissions/{invoice_id}/retry',
        'POST /submissions/{invoice_id}/link', 'GET /submissions/{invoice_id}/status', 'GET /submissions/{invoice_id}/upo',
        'GET /submissions/{invoice_id}/xml', 'PUT /submissions/{invoice_id}/correction', 'DELETE /corrections/{invoice_id}',
    ),
    'KSEF_HowTo_RestGroupSales' => array('GET /sales', 'GET /sales/counts', 'GET /sales/gaps', 'GET /sales/{id}', 'GET /sales/{id}/xml'),
    'KSEF_HowTo_RestGroupIncoming' => array(
        'GET /incoming', 'GET /incoming/{id}', 'GET /incoming/{id}/xml', 'GET /incoming/{id}/pdf', 'POST /incoming/{id}/link',
        'DELETE /incoming/{id}', 'POST /incoming/sync', 'POST /incoming/sync/advance', 'GET /incoming/sync/status', 'POST /incoming/sync/reset',
    ),
    'KSEF_HowTo_RestGroupCreditNotes' => array(
        'GET /creditnotes/{id}/discounts', 'POST /creditnotes/{id}/convert', 'POST /creditnotes/{id}/apply-line',
        'POST /creditnotes/{id}/apply-payment', 'POST /creditnotes/{id}/refund', 'GET /creditnotes/{id}/resolution-state',
    ),
    'KSEF_HowTo_RestGroupOther' => array('GET /status', 'GET /config', 'POST /test-connection', 'GET /gus/{nip}'),
);
?>
    <table class="noborder centpercent ksef-howto-rest">
        <tr class="liste_titre ksef-section-head">
            <th><span class="fas fa-plug paddingright"></span><?php echo $langs->trans("KSEF_HowTo_RestTitle"); ?><?php echo ksefSectionDesc("KSEF_HowTo_RestTitle_Desc"); ?></th>
        </tr>
        <tr class="oddeven">
            <td>
                <p><?php echo $langs->trans("KSEF_HowTo_RestIntro"); ?><br>
                    <code><?php echo dol_escape_htmltag($restBase); ?></code></p>
                <p><?php echo $langs->trans("KSEF_HowTo_RestSetup"); ?>
                    <a href="<?php echo dol_escape_htmltag($restExplorer); ?>" target="_blank" rel="noopener"><?php echo dol_escape_htmltag($restExplorer); ?></a></p>
                <p><?php echo $langs->trans("KSEF_HowTo_RestRights"); ?></p>
                <p><?php echo $langs->trans("KSEF_HowTo_RestCodes"); ?></p>
                <p><?php echo $langs->trans("KSEF_HowTo_RestSync"); ?></p>
                <p><?php echo $langs->trans("KSEF_HowTo_RestApplyLine"); ?></p>
                <h4><?php echo $langs->trans("KSEF_HowTo_RestRoutes"); ?></h4>
                <?php foreach ($restRoutes as $groupKey => $routes) { ?>
                <p><strong><?php echo $langs->trans($groupKey); ?></strong><br>
                    <?php foreach ($routes as $route) { ?>
                    <code><?php echo dol_escape_htmltag($route); ?></code><br>
                    <?php } ?>
                </p>
                <?php } ?>
            </td>
        </tr>
    </table>

    <br>

    <table class="noborder centpercent">
        <tr class="liste_titre ksef-section-head" id="ksef-howto-sec-troubleshooting">
            <th><span class="fas fa-wrench paddingright"></span><?php echo $langs->trans("KSEF_Troubleshooting"); ?><?php echo ksefSectionDesc("KSEF_Troubleshooting_Desc"); ?></th>
        </tr>
        <tr class="oddeven">
            <td>
                <h4><?php echo $langs->trans("KSEF_FindingErrorDetails"); ?></h4>
                <p><?php echo $langs->trans("KSEF_FindingErrorDetailsDesc"); ?>
                    <a href="<?php echo dol_buildpath('/ksef/status.php', 1); ?>"><?php echo $langs->trans("KSEF_OutgoingInvoices"); ?></a>
                </p>

                <h4><?php echo $langs->trans("KSEF_CommonIssues"); ?></h4>

                <p><strong><?php echo $langs->trans("KSEF_Issue_DuplicateNumber"); ?></strong><br>
                    <?php echo $langs->trans("KSEF_Issue_DuplicateNumber_Desc"); ?></p>

                <p><strong><?php echo $langs->trans("KSEF_Issue_AuthFailed"); ?></strong><br>
                    <?php echo $langs->trans("KSEF_Issue_AuthFailed_Desc"); ?></p>

                <p><strong><?php echo $langs->trans("KSEF_Issue_NBPMissing"); ?></strong><br>
                    <?php echo $langs->trans("KSEF_Issue_NBPMissing_Desc"); ?></p>

                <p><strong><?php echo $langs->trans("KSEF_Issue_Timeout"); ?></strong><br>
                    <?php echo $langs->trans("KSEF_Issue_Timeout_Desc"); ?><br>
                    <em><?php echo $langs->trans("KSEF_Issue_Timeout_Solution"); ?></em></p>

                <h4><?php echo $langs->trans("KSEF_RetryLimits"); ?></h4>
                <p><?php echo $langs->trans("KSEF_RetryLimitsDesc"); ?></p>

                <h4><?php echo $langs->trans("KSEF_DbCheck"); ?></h4>
                <p><?php echo $langs->trans("KSEF_HowTo_DbCheckDesc"); ?></p>
            </td>
        </tr>
    </table>

<?php

print $guideDialogs;
print ksefCollapsibleSectionsJs();

print dol_get_fiche_end();

print ksefHelpFooter($db, $user);
llxFooter();
$db->close();
?>