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
 * \defgroup   ksef     KSEF module
 * \brief      KSEF module
 *
 * \file       core/modules/modKSEF.class.php
 * \ingroup    ksef
 * \brief      KSEF module
 */
require_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';

/**
 * @brief KSeF module descriptor
 */
class modKSEF extends DolibarrModules
{
    /** @var string */
    const MIGRATION_SQUASHED = '1.5.0';
    /** @var string Bump when declared label / position / visibility change */
    const EXTRAFIELD_DEFAULTS = '1.5.0';

    public $url_last_version;
    public $tabs;
    public $dictionaries;

    /**
     * @brief Constructor
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        global $langs, $conf;
        $this->db = $db;

        $this->numero = 592189;
        $this->rights_class = 'ksef';
        $this->family = "financial";
        $this->module_position = '90';
        $this->name = preg_replace('/^mod/i', '', get_class($this));
        $this->description = "Polish KSEF (Krajowy System e-Faktur) integration module for Dolibarr";
        $this->descriptionlong = "Submit invoices to Polish KSEF system";
        $this->editor_name = 'InPoint Automation';
        $this->editor_url = 'https://inpointautomation.com';
        $this->version = '1.5.0';
        $this->url_last_version = ''; // see checkForUpdate()
        $this->const_name = 'MAIN_MODULE_' . strtoupper($this->name);
        $this->picto = 'ksef@ksef';

        $this->module_parts = array(
            'triggers' => 1,
            'api' => 1,
            'css' => array('/ksef/css/ksef.css.php'),
            'js' => array('/ksef/js/ksef_tour_loader.js'),
            'login' => 0,
            'substitutions' => 0,
            'menus' => 0,
            'tpl' => 0,
            'barcode' => 0,
            'models' => 0,
            'printing' => 0,
            'theme' => 0,
            'actions' => array('/ksef/class/actions_ksef.class.php@ActionsKSEF'),
            'hooks' => array(
                'invoicecard',
                'invoicelist',
                'invoicenote',
                'invoicesuppliercard',
                'supplierpaymentcard',
                'completeTabsHead',
                'pdfgeneration',
                'afterPDFTotalTable',
                'thirdpartycard',
                'thirdpartycomm',
                'sellsjournal',
                'supplierinvoicelist',
                'thirdpartysupplier',
                'purchasesjournal',
                'invoice_supplierdao',
                'recapcomptacard',
                'externalbalance',
                'customersupplierreportlist',
                'paiementcard',
                'paymentcard',
                'paymentsupplierlist',
                'variouscard',
                'accountancyadminaccount',
                // core page tours
                'ordercard',
                'orderlist',
                'cronjoblist',
                'userlist',
                // filtered in ActionsKSEF::inContexts()
                'accountingjournaldao',
                'facturedao',
                'paymentdao',
                'payment_supplierdao',
                'api',
                'cli',
            ),
            'moduleforexternal' => 0,
        );

        $this->dirs = array("/ksef/temp");
        $this->config_page_url = array("setup.php@ksef");

        $this->hidden = false;
        $this->depends = array('modFacture');
        $this->requiredby = array();
        $this->conflictwith = array();

        $this->langfiles = array("ksef@ksef");

        $this->phpmin = array(8, 0);
        $this->need_dolibarr_version = array(22, 0);

        $this->warnings_activation = array();
        $this->warnings_activation_ext = array();
        global $mysoc;
        $this->warnings_unactivation = array();
        if (getDolGlobalInt('KSEF_PURGE_ON_DISABLE') && is_object($mysoc) && !empty($mysoc->country_code)) {
            $this->warnings_unactivation[$mysoc->country_code] = 'KSEF_PurgeOnDisableConfirm';
        }

        $this->const = array();

        $this->tabs = array(
            'invoice:+ksef:KSEF,KsefSubmission,/ksef/class/ksef_submission.class.php,countForInvoice:ksef@ksef:$user->hasRight("ksef", "read"):/ksef/tab_ksef.php?id=__ID__'
        );

        $this->dictionaries = array();
        $this->boxes = array();

        // core translates labels
        $this->cronjobs = array(
            0 => array(
                'label'          => 'KSEF_CronJob_cronCheckStatuses:ksef@ksef',
                'jobtype'        => 'method',
                'class'          => '/ksef/class/ksef_service.class.php',
                'objectname'     => 'KsefService',
                'method'         => 'cronCheckStatuses',
                'parameters'     => '',
                'comment'        => 'Poll KSeF for status of PENDING/SUBMITTED/TIMEOUT invoices',
                'frequency'      => 30,
                'unitfrequency'  => 60,
                'status'         => 1,
                'test'           => 'isModEnabled("ksef")',
                'priority'       => 50,
            ),
            1 => array(
                'label'          => 'KSEF_CronJob_cronSyncIncoming:ksef@ksef',
                'jobtype'        => 'method',
                'class'          => '/ksef/class/ksef_service.class.php',
                'objectname'     => 'KsefService',
                'method'         => 'cronSyncIncoming',
                'parameters'     => '',
                'comment'        => 'Fetch new incoming invoices from KSeF',
                'frequency'      => 4,
                'unitfrequency'  => 3600,
                'status'         => 0,
                'test'           => 'isModEnabled("ksef")',
                'priority'       => 50,
            ),
            2 => array(
                'label'          => 'KSEF_CronJob_cronDownloadUPOs:ksef@ksef',
                'jobtype'        => 'method',
                'class'          => '/ksef/class/ksef_service.class.php',
                'objectname'     => 'KsefService',
                'method'         => 'cronDownloadUPOs',
                'parameters'     => '',
                'comment'        => 'Download UPO XML for accepted submissions that lack it',
                'frequency'      => 6,
                'unitfrequency'  => 3600,
                'status'         => 0,
                'test'           => 'isModEnabled("ksef")',
                'priority'       => 50,
            ),
            3 => array(
                'label'          => 'KSEF_CronJob_cronWarnDeadlines:ksef@ksef',
                'jobtype'        => 'method',
                'class'          => '/ksef/class/ksef_service.class.php',
                'objectname'     => 'KsefService',
                'method'         => 'cronWarnDeadlines',
                'parameters'     => '',
                'comment'        => 'Log warnings for offline invoices with deadlines in next 24h',
                'frequency'      => 1,
                'unitfrequency'  => 3600,
                'status'         => 1,
                'test'           => 'isModEnabled("ksef")',
                'priority'       => 50,
            ),
            4 => array(
                'label'          => 'KSEF_CronJob_cronCheckLatarniaStatus:ksef@ksef',
                'jobtype'        => 'method',
                'class'          => '/ksef/class/ksef_service.class.php',
                'objectname'     => 'KsefService',
                'method'         => 'cronCheckLatarniaStatus',
                'parameters'     => '',
                'comment'        => 'Poll KSeF Latarnia API for system availability status',
                'frequency'      => 15,
                'unitfrequency'  => 60,
                'status'         => 1,
                'test'           => 'isModEnabled("ksef")',
                'priority'       => 50,
            ),
            5 => array(
                'label'          => 'KSEF_CronJob_cronSyncMulticurrencyRates:ksef@ksef',
                'jobtype'        => 'method',
                'class'          => '/ksef/class/ksef_nbp_currency_rate.class.php',
                'objectname'     => 'KsefNbpCurrencyRate',
                'method'         => 'cronSyncMulticurrencyRates',
                'parameters'     => '',
                'comment'        => 'Fetch D-1 NBP rates for the Multicurrency module currencies into llx_multicurrency_rate',
                'frequency'      => 1,
                'unitfrequency'  => 86400,
                'status'         => 0,
                'test'           => 'isModEnabled("ksef") && isModEnabled("multicurrency")',
                'priority'       => 50,
            ),
        );

        $this->rights = array();
        $r = 0;

        // '1' breaks hasRight()
        $this->rights[$r][0] = $this->numero + $r;
        $this->rights[$r][1] = 'Read KSEF submissions';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'read';
        $this->rights[$r][5] = '';
        $r++;

        $this->rights[$r][0] = $this->numero + $r;
        $this->rights[$r][1] = 'Create/Update KSEF submissions';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'write';
        $this->rights[$r][5] = '';
        $r++;

        $this->menu = array();
        $r = 0;

        $this->menu[$r++] = array(
            'fk_menu' => 'fk_mainmenu=billing',
            'type' => 'left',
            'titre' => 'KSEF',
            'prefix' => '<i class="fas fa-file-invoice fa-fw paddingright"></i>',
            'mainmenu' => 'billing',
            'leftmenu' => 'ksef',
            'url' => '/ksef/ksefindex.php',
            'langs' => 'ksef@ksef',
            'position' => 1000 + $r,
            'enabled' => 'isModEnabled("ksef")',
            'perms' => '$user->hasRight("ksef", "read") || $user->admin',
            'target' => '',
            'user' => 0,
        );

        $this->menu[$r++] = array(
            'fk_menu' => 'fk_mainmenu=billing,fk_leftmenu=ksef',
            'type' => 'left',
            'titre' => 'KSEF_MenuSubmissionStatus',
            'mainmenu' => 'billing',
            'leftmenu' => 'ksef_status',
            'url' => '/ksef/status.php',
            'langs' => 'ksef@ksef',
            'position' => 1000 + $r,
            'enabled' => 'isModEnabled("ksef")',
            'perms' => '$user->hasRight("ksef", "read") && $user->hasRight("facture", "lire")',
            'target' => '',
            'user' => 0,
        );

        $this->menu[$r++] = array(
            'fk_menu' => 'fk_mainmenu=billing,fk_leftmenu=ksef',
            'type' => 'left',
            'titre' => 'KSEF_IncomingInvoices',
            'mainmenu' => 'billing',
            'leftmenu' => 'ksef_incoming',
            'url' => '/ksef/incoming_list.php',
            'langs' => 'ksef@ksef',
            'position' => 1000 + $r,
            'enabled' => 'isModEnabled("ksef")',
            'perms' => '$user->hasRight("ksef", "read")',
            'target' => '',
            'user' => 0,
        );

        $this->menu[$r++] = array(
            'fk_menu' => 'fk_mainmenu=billing,fk_leftmenu=ksef',
            'type' => 'left',
            'titre' => 'KSEF_HowToUse',
            'mainmenu' => 'billing',
            'leftmenu' => 'ksef_howto',
            'url' => '/ksef/admin/howtouse.php',
            'langs' => 'ksef@ksef',
            'position' => 1000 + $r,
            'enabled' => 'isModEnabled("ksef")',
            'perms' => '$user->hasRight("ksef", "read")',
            'target' => '',
            'user' => 0,
        );

        $this->menu[$r++] = array(
            'fk_menu' => 'fk_mainmenu=billing,fk_leftmenu=ksef',
            'type' => 'left',
            'titre' => 'KSEF_MenuConfiguration',
            'mainmenu' => 'billing',
            'leftmenu' => 'ksef_config',
            'url' => '/ksef/admin/setup.php',
            'langs' => 'ksef@ksef',
            'position' => 1000 + $r,
            'enabled' => 'isModEnabled("ksef")',
            'perms' => '$user->admin',
            'target' => '',
            'user' => 0,
        );

        $this->export_code = array();
        $this->export_label = array();
        $this->import_code = array();
        $this->import_label = array();
        if (isModEnabled('accounting')) {
            dol_include_once('/ksef/lib/ksef_chart.lib.php');
            $r = 1;
            $rule = array('rule' => 'compute', 'classfile' => '/ksef/class/ksef_chart_import.class.php', 'class' => 'KsefChartImport', 'type' => 'varchar');
            $this->import_code[$r] = $this->rights_class . '_' . $r;
            $this->import_label[$r] = 'KSEF_ChartImportDataset';
            $this->import_icon[$r] = 'accountancy';
            $this->import_entities_array[$r] = array();
            $this->import_tables_array[$r] = array('aa' => MAIN_DB_PREFIX . 'accounting_account');
            $this->import_tables_creator_array[$r] = array('aa' => 'fk_user_author');
            $this->import_fields_array[$r] = ksefChartImportFields();
            $this->import_fieldshidden_array[$r] = array('aa.fk_pcg_version' => 'rule-computePcgVersion', 'aa.account_parent' => 'const-0', 'aa.pcg_type' => 'const-',
                'aa.active' => 'const-1', 'aa.datec' => 'const-' . dol_print_date(dol_now(), 'standard'));
            $this->import_regex_array[$r] = array('aa.account_number' => '^[A-Za-z0-9._-]{1,32}$', 'aa.pcg_type' => '^[A-Za-z0-9_]{1,20}$');
            $this->import_convertvalue_array[$r] = array('aa.account_number' => $rule + array('method' => 'computeNumber'), 'aa.account_parent' => $rule + array('method' => 'computeParent'),
                'aa.pcg_type' => $rule + array('method' => 'computeType'), 'aa.fk_pcg_version' => $rule + array('method' => 'computePcgVersion'));
            $this->import_examplevalues_array[$r] = array('aa.account_number' => '130', 'aa.label' => 'Rachunek bieżący', 'aa.account_parent' => '', 'aa.pcg_type' => 'FINAN');
        }
    }

    /**
     * @brief Migration start level
     * @param string $recorded KSEF_MIGRATION_VERSION
     * @param string $marker KSEF_MIGRATION_SQUASHED
     * @return string '' when none
     */
    public function migrationLevel($recorded, $marker)
    {
        if ($recorded !== '' && $marker !== self::MIGRATION_SQUASHED && version_compare($recorded, '1.4.5', '>')) {
            return '1.4.5';
        }
        return $recorded;
    }

    /**
     * @brief Declared extrafields
     * @return array
     */
    public function getKsefExtraFieldDefs()
    {
        $extraFieldDefs = array(
            'ksef_number' => array(
                'label' => 'KSEF_ExtraFieldNumber',
                'type' => 'varchar',
                'pos' => 100,
                'size' => '255',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => '',
                'alwayseditable' => 0,
                'perms' => '',
                'list' => '-5',
                'element_overrides' => array('facture_fourn' => array('alwayseditable' => 1, 'list' => '-1')),
                'help' => '',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => 'isModEnabled("ksef")',
                'totalizable' => 0,
                'printable' => 2,
            ),
            'ksef_status' => array(
                'label' => 'KSEF_ExtraFieldStatus',
                'type' => 'select',
                'pos' => 101,
                'size' => '',
                'unique' => 0,
                'required' => 0,
                'default_value' => 'PENDING',
                'param' => array('options' => array(
                    'PENDING' => 'KSEF_StatusPending',
                    'ACCEPTED' => 'KSEF_StatusAccepted',
                    'REJECTED' => 'KSEF_StatusRejected',
                    'FAILED' => 'KSEF_StatusFailed',
                    'OFFLINE' => 'KSEF_StatusOffline',
                    'VALIDATION_FAILED' => 'KSEF_StatusValidationFailed'
                )),
                'alwayseditable' => 0,
                'perms' => '',
                'list' => '5',
                'element_overrides' => array('facture_fourn' => array('list' => '-5')),
                'help' => '',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => 'isModEnabled("ksef")',
                'totalizable' => 0,
                'printable' => 0,
            ),
            'ksef_submission_date' => array(
                'label' => 'KSEF_ExtraFieldSubmissionDate',
                'type' => 'datetime',
                'pos' => 102,
                'size' => '',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => '',
                'alwayseditable' => 0,
                'perms' => '',
                'list' => '-5',
                'help' => '',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => 'isModEnabled("ksef")',
                'totalizable' => 0,
                'printable' => 0,
            ),
            'ksef_kurs_data' => array(
                'label' => 'KSEF_ExtraFieldKursData',
                'type' => 'date',
                'pos' => 103,
                'size' => '',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => '',
                'alwayseditable' => 0,
                'perms' => '',
                'list' => '-1',
                'element_overrides' => array('facture_fourn' => array('alwayseditable' => 1)),
                'help' => 'KSEF_ExtraFieldKursDataHelp',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => 'isModEnabled("multicurrency") && isModEnabled("ksef")',
                'totalizable' => 0,
                'printable' => 2,
            ),
            'ksef_sale_date' => array(
                'label' => 'KSEF_ExtraFieldSaleDate',
                'type' => 'date',
                'pos' => 104,
                'size' => '',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => '',
                'alwayseditable' => 0,
                'perms' => '',
                'list' => '-1',
                'element_overrides' => array('facture_fourn' => array('alwayseditable' => 1)),
                'help' => 'KSEF_ExtraFieldSaleDateHelp',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => 'isModEnabled("ksef")',
                'totalizable' => 0,
                'printable' => 2,
            ),
            'ksef_dodatkowy_opis_mode' => array(
                'label' => 'KSEF_ExtraFieldDodatkowyOpisMode',
                'type' => 'select',
                'pos' => 110,
                'size' => '',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => array('options' => array(
                    ''                   => 'KSEF_DodatkowyOpisMode_Default',
                    'simple_stopka'      => 'KSEF_DodatkowyOpisMode_SimpleStopka',
                    'simple_dodatkowy'   => 'KSEF_DodatkowyOpisMode_SimpleDodatkowy',
                    'keyvalue_dodatkowy' => 'KSEF_DodatkowyOpisMode_KeyValueDodatkowy',
                    'disabled'           => 'KSEF_DodatkowyOpisMode_Disabled',
                )),
                'alwayseditable' => 1,
                'perms' => '',
                'list' => '0',
                'help' => 'KSEF_ExtraFieldDodatkowyOpisModeHelp',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => 'isModEnabled("ksef")',
                'totalizable' => 0,
                'printable' => 0,
                'element_types' => array('facture'),
            ),
            'ksef_correction_reason' => array(
                'label' => 'KSEF_ExtraFieldCorrectionReason',
                'type' => 'varchar',
                'pos' => 106,
                'size' => '256',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => '',
                'alwayseditable' => 1,
                'perms' => '',
                'list' => '0',
                'help' => 'KSEF_CorrectionReasonHelp',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => 'isModEnabled("ksef")',
                'totalizable' => 0,
                'printable' => 0,
                'element_types' => array('facture', 'facture_fourn'),
            ),
            'ksef_podmiot3' => array(
                'label' => 'KSEF_Podmiot3_Field',
                'type' => 'text',
                'pos' => 510,
                'size' => '',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => '',
                'alwayseditable' => 1,
                'perms' => '',
                'list' => '0',
                'help' => 'KSEF_Podmiot3_Field_Help',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => 'isModEnabled("ksef")',
                'totalizable' => 0,
                'printable' => 0,
                'element_types' => array('facture'),
            ),
            'ksef_podmiot3_role' => array(
                'label' => 'KSEF_Podmiot3_Role_Field',
                'type' => 'varchar',
                'pos' => 511,
                'size' => '2',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => '',
                'alwayseditable' => 1,
                'perms' => '',
                'list' => '0',
                'help' => 'KSEF_Podmiot3_Role_Field_Help',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => 'isModEnabled("ksef")',
                'totalizable' => 0,
                'printable' => 0,
                'element_types' => array('facture'),
            ),
            'ksef_correction_type' => array(
                'label' => 'KSEF_ExtraFieldCorrectionType',
                'type' => 'select',
                'pos' => 107,
                'size' => '',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => array('options' => array(
                    '1' => 'KSEF_CorrectionType1',
                    '2' => 'KSEF_CorrectionType2',
                    '3' => 'KSEF_CorrectionType3',
                )),
                'alwayseditable' => 1,
                'perms' => '',
                'list' => '0',
                'help' => '',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => 'isModEnabled("ksef")',
                'totalizable' => 0,
                'printable' => 0,
                'element_types' => array('facture', 'facture_fourn'),
            ),
            'ksef_correction_original_ht' => array(
                'label' => 'KSEF_CorrectionOriginalHT',
                'type' => 'double',
                'pos' => 108,
                'size' => '24,8',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => '',
                'alwayseditable' => 0,
                'perms' => '',
                'list' => '0',
                'help' => '',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => 'isModEnabled("ksef")',
                'totalizable' => 0,
                'printable' => 0,
                'element_types' => array('facture', 'facture_fourn'),
            ),
            'ksef_correction_original_tva' => array(
                'label' => 'KSEF_CorrectionOriginalTVA',
                'type' => 'double',
                'pos' => 109,
                'size' => '24,8',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => '',
                'alwayseditable' => 0,
                'perms' => '',
                'list' => '0',
                'help' => '',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => 'isModEnabled("ksef")',
                'totalizable' => 0,
                'printable' => 0,
                'element_types' => array('facture', 'facture_fourn'),
            ),
            'ksef_correction_original_ttc' => array(
                'label' => 'KSEF_CorrectionOriginalTTC',
                'type' => 'double',
                'pos' => 113,
                'size' => '24,8',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => '',
                'alwayseditable' => 0,
                'perms' => '',
                'list' => '0',
                'help' => '',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => 'isModEnabled("ksef")',
                'totalizable' => 0,
                'printable' => 0,
                'element_types' => array('facture', 'facture_fourn'),
            ),
            'ksef_correction_original_discount_ids' => array(
                'label' => 'KSEF_CorrectionOriginalDiscountIds',
                'type' => 'varchar',
                'pos' => 112,
                'size' => '255',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => '',
                'alwayseditable' => 0,
                'perms' => '',
                'list' => '0',
                'help' => '',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => 'isModEnabled("ksef")',
                'totalizable' => 0,
                'printable' => 0,
                'element_types' => array('facture', 'facture_fourn'),
            ),
            'ksef_correction_discount_id' => array(
                'label' => 'KSEF_CorrectionDiscountId',
                'type' => 'int',
                'pos' => 111,
                'size' => '',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => '',
                'alwayseditable' => 0,
                'perms' => '',
                'list' => '0',
                'help' => '',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => 'isModEnabled("ksef")',
                'totalizable' => 0,
                'printable' => 0,
                'element_types' => array('facture', 'facture_fourn'),
            ),
            'ksef_correction_settled_amount' => array(
                'label' => 'KSEF_CorrectionSettledAmount',
                'type' => 'double',
                'pos' => 114,
                'size' => '24,8',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => '',
                'alwayseditable' => 0,
                'perms' => '',
                'list' => '0',
                'help' => '',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => 'isModEnabled("ksef")',
                'totalizable' => 0,
                'printable' => 0,
                'element_types' => array('facture', 'facture_fourn'),
            ),
            'ksef_correction_settled_amount_mc' => array(
                'label' => 'KSEF_CorrectionSettledAmountMC',
                'type' => 'double',
                'pos' => 115,
                'size' => '24,8',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => '',
                'alwayseditable' => 0,
                'perms' => '',
                'list' => '0',
                'help' => '',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => 'isModEnabled("ksef")',
                'totalizable' => 0,
                'printable' => 0,
                'element_types' => array('facture', 'facture_fourn'),
            ),
            'ksef_correction_state' => array(
                'label' => 'KSEF_CorrectionState',
                'type' => 'text',
                'pos' => 116,
                'size' => '',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => '',
                'alwayseditable' => 0,
                'perms' => '',
                'list' => '0',
                'help' => '',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => 'isModEnabled("ksef")',
                'totalizable' => 0,
                'printable' => 0,
                'element_types' => array('facture', 'facture_fourn'),
            ),
            'ksef_fx_rules' => array(
                'label' => 'KSEF_FxRules',
                'type' => 'varchar',
                'pos' => 117,
                'size' => '255',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => '',
                'alwayseditable' => 0,
                'perms' => '',
                'list' => '0',
                'help' => '',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => 'isModEnabled("ksef")',
                'totalizable' => 0,
                'printable' => 0,
                'element_types' => array('facture'),
            ),

            'ksef_period_from' => array(
                'label' => 'KSEF_ExtraFieldPeriodFrom',
                'type' => 'date',
                'pos' => 130,
                'size' => '',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => '',
                'alwayseditable' => 0,
                'perms' => '',
                'list' => '1',
                'help' => 'KSEF_ExtraFieldPeriodHelp',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => 'isModEnabled("ksef") && getDolGlobalInt("KSEF_FA3_PERIOD_FIELDS")',
                'totalizable' => 0,
                'printable' => 0,
                'element_types' => array('facture'),
            ),
            'ksef_period_to' => array(
                'label' => 'KSEF_ExtraFieldPeriodTo',
                'type' => 'date',
                'pos' => 131,
                'size' => '',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => '',
                'alwayseditable' => 0,
                'perms' => '',
                'list' => '1',
                'help' => 'KSEF_ExtraFieldPeriodHelp',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => 'isModEnabled("ksef") && getDolGlobalInt("KSEF_FA3_PERIOD_FIELDS")',
                'totalizable' => 0,
                'printable' => 0,
                'element_types' => array('facture'),
            ),
            'ksef_order_date' => array(
                'label' => 'KSEF_ExtraFieldOrderDate',
                'type' => 'date',
                'pos' => 132,
                'size' => '',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => '',
                'alwayseditable' => 0,
                'perms' => '',
                'list' => '1',
                'help' => 'KSEF_ExtraFieldOrderDateHelp',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => 'isModEnabled("ksef") && getDolGlobalInt("KSEF_FA3_ORDER_DATE_FIELD")',
                'totalizable' => 0,
                'printable' => 0,
                'element_types' => array('facture'),
            ),

            'ksef_send_skipped' => array(
                'label' => 'KSEF_SendSkipped',
                'type' => 'boolean',
                'pos' => 120,
                'size' => '',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => '',
                'alwayseditable' => 0,
                'perms' => '',
                'list' => '0',
                'help' => '',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => 'isModEnabled("ksef")',
                'totalizable' => 0,
                'printable' => 0,
                'element_types' => array('facture'),
            ),
        );

        $societeExtraFieldDefs = array(
            'ksef_hide_contact' => array(
                'label' => 'KSEF_ExtraFieldHideContact',
                'type' => 'boolean',
                'pos' => 600,
                'size' => '',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => '',
                'alwayseditable' => 1,
                'perms' => '',
                'list' => '3',
                'help' => 'KSEF_ExtraFieldHideContactHelp',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => 'isModEnabled("ksef")',
                'totalizable' => 0,
                'printable' => 0,
            ),
            'ksef_hide_eori' => array(
                'label' => 'KSEF_ExtraFieldHideEori',
                'type' => 'boolean',
                'pos' => 601,
                'size' => '',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => '',
                'alwayseditable' => 1,
                'perms' => '',
                'list' => '3',
                'help' => 'KSEF_ExtraFieldHideEoriHelp',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => 'isModEnabled("ksef")',
                'totalizable' => 0,
                'printable' => 0,
            ),
            'is_vat_group' => array(
                'label' => 'KSEF_ExtraFieldVatGroup',
                'type' => 'boolean',
                'pos' => 602,
                'size' => '',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => '',
                'alwayseditable' => 1,
                'perms' => '',
                'list' => '3',
                'help' => 'KSEF_ExtraFieldVatGroupHelp',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => 'isModEnabled("ksef")',
                'totalizable' => 0,
                'printable' => 0,
            ),
            'is_jst' => array(
                'label' => 'KSEF_ExtraFieldJst',
                'type' => 'boolean',
                'pos' => 603,
                'size' => '',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => '',
                'alwayseditable' => 1,
                'perms' => '',
                'list' => '3',
                'help' => 'KSEF_ExtraFieldJstHelp',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => 'isModEnabled("ksef")',
                'totalizable' => 0,
                'printable' => 0,
            ),
        );

        $lineExtraFieldDefs = array(
            'ksef_uu_id' => array(
                'label' => 'KSEF_ExtraFieldUUID',
                'type' => 'varchar',
                'pos' => 200,
                'size' => '36',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => '',
                'alwayseditable' => 0,
                'perms' => '',
                'list' => '0',
                'help' => '',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => 'isModEnabled("ksef")',
                'totalizable' => 0,
                'printable' => 0,
            ),
            'ksef_kurs' => array(
                'label' => 'KSEF_ExtraFieldLineKurs',
                'type' => 'varchar',
                'pos' => 201,
                'size' => '16',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => '',
                'alwayseditable' => 0,
                'perms' => '',
                'list' => '0',
                'help' => '',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => 'isModEnabled("ksef")',
                'totalizable' => 0,
                'printable' => 0,
                'element_types' => array('facturedet'),
            ),
            // JSON, see ksefKursSrc()
            'ksef_kurs_src' => array(
                'label' => 'KSEF_ExtraFieldLineKursSrc',
                'type' => 'varchar',
                'pos' => 202,
                'size' => '255',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => '',
                'alwayseditable' => 0,
                'perms' => '',
                'list' => '0',
                'help' => '',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => 'isModEnabled("ksef")',
                'totalizable' => 0,
                'printable' => 0,
                'element_types' => array('facturedet'),
            ),
        );

        return array(
            'invoice' => array('elements' => array('facture', 'facture_fourn'), 'defs' => $extraFieldDefs),
            'societe' => array('elements' => array('societe'), 'defs' => $societeExtraFieldDefs),
            'line' => array('elements' => array('facturedet', 'facture_fourn_det'), 'defs' => $lineExtraFieldDefs),
        );
    }

    /**
     * @brief Check for update
     * @return int 1 newer, 0 current, -1 failed
     */
    public function checkForUpdate()
    {
        dol_include_once('/ksef/lib/ksef.lib.php');
        if (!function_exists('ksefCheckForUpdate')) {
            return -1;
        }
        $res = ksefCheckForUpdate($this->db);
        if (($res['status'] ?? '') !== 'ok' || ($res['latest'] ?? '') === '') {
            return -1;
        }
        $this->lastVersion = preg_replace('/[^a-zA-Z0-9_.\-]+/', '', (string) $res['latest']);
        $this->needUpdate = (version_compare($this->lastVersion, $this->version) > 0);
        return $this->needUpdate ? 1 : 0;
    }

    /**
     * @brief Enable module
     * @param string $options '' or 'noboxes'
     * @return int 1 OK, 0 KO
     */
    public function init($options = '')
    {
        global $conf, $langs, $db;

        $sql = array();
        $langs->loadLangs(array("ksef@ksef"));

        $sql[] = "DELETE FROM " . MAIN_DB_PREFIX . "cronjob"
            . " WHERE methodename = 'processPendingSubmissions'"
            . " AND classesname LIKE '%ksef%'";

        $result = $this->_load_tables('/ksef/sql/');
        if ($result < 0) return -1;

        $persistentDefaults = array(
            'KSEF_ADD_QR'            => '1',
            'KSEF_QR_SIZE'           => '25',

            'KSEF_PURGE_ON_DISABLE'  => '0',

            'KSEF_COMPANY_NIP'       => '',
            'KSEF_COMPANY_KRS'       => '',
            'KSEF_COMPANY_REGON'     => '',
            'KSEF_COMPANY_BDO'       => '',
            'KSEF_COMPANY_EORI'      => '',

            'KSEF_FIELD_NIP'         => 'idprof1',
            'KSEF_FIELD_KRS'         => 'idprof2',
            'KSEF_FIELD_REGON'       => 'idprof3',
            'KSEF_FIELD_BDO'         => 'idprof4',
            'KSEF_FIELD_EORI'        => 'idprof5',
            'KSEF_ENVIRONMENT'       => 'DEMO',

            'KSEF_EXCLUDED_CUSTOMERS' => '',

            'KSEF_FA3_INCLUDE_NRKLIENTA'   => '0',
            'KSEF_FA3_INCLUDE_INDEKS'      => '0',
            'KSEF_FA3_INCLUDE_GTIN'        => '0',
            'KSEF_FA3_INCLUDE_UNIT'        => '0',
            'KSEF_FA3_INCLUDE_BANK_DESC'   => '0',
            'KSEF_FA3_PLACE_OF_ISSUE_MODE' => 'disabled',
            'KSEF_FA3_PLACE_OF_ISSUE_CUSTOM' => '',
            'KSEF_FA3_SALE_DATE_SOURCE'      => 'delivery_date',
            'KSEF_NBP_RATE_MODE'           => 'keep_foreign',

            'KSEF_DEFAULT_CORRECTION_REASON' => '',
            'KSEF_DEFAULT_CORRECTION_TYPE'   => '',
            'KSEF_KOR_LINE_METHOD'           => 'stanprzed',
            'KSEF_IDNABYWCY_SOURCE'          => 'disabled',
            'KSEF_TP_SOURCE'                 => 'disabled',

            'KSEF_FA3_MPP_SOURCE'            => 'disabled',
            'KSEF_FA3_FP_SOURCE'             => 'disabled',
            'KSEF_P17_SOURCE'                => 'disabled',
            'KSEF_P17_TP_SOURCE'             => 'disabled',
            'KSEF_P16_SOURCE'                => 'disabled',
            'KSEF_P18_SOURCE'                => 'disabled',
            'KSEF_P18_TP_SOURCE'             => 'disabled',

            'KSEF_PODMIOT3_SOURCE'           => 'disabled',
            'KSEF_PODMIOT3_ROLE'             => '6',
            'KSEF_IDWEW_SOURCE'              => 'disabled',

            'KSEF_FA3_INCLUDE_WZ'            => '0',
            'KSEF_FA3_LINE_DATES'            => 'disabled',
            'KSEF_FA3_PERIOD_FIELDS'         => '0',
            'KSEF_FA3_ORDER_DATE_FIELD'      => '0',
            'KSEF_NR_ZAMOWIENIA_PARSE_DATE'  => '0',
            // art. 106e(10)
            'KSEF_FA3_INCLUDE_LINE_VAT'      => 'auto',
            'KSEF_FX_PLN_VAT_METHOD'         => 'convert_vat',
            'KSEF_FX_KOR_METHOD'             => 'after_before',
            'KSEF_DEPOSIT_FX_POLICY'         => 'settlement',
            'KSEF_FX_MULTI_RATE_ROUNDING'    => 'once',
            'KSEF_FX_DEPOSIT_KOR_ALLOC'      => 'proportional',
            'KSEF_FA3_GROSS_SOURCE'          => 'disabled',
            'KSEF_FA3_UNIT_MAP'              => '',
            'KSEF_FA3_PAYMENT_FORM_MAP'      => '',
            'KSEF_IMPORT_PAYMENT_MODE_MAP'   => '',

            'KSEF_GUS_ENABLED'               => '0',
            'KSEF_GUS_ENV'                   => 'TEST',
            'KSEF_GUS_KEY'                   => '',
            'KSEF_GUS_NAME_STYLE'            => 'full',

            'KSEF_OFFLINE_DEADLINE_AGENDA'   => '0',
        );

        $visibleDefaults = array(
            'KSEF_TIMEOUT' => array('value' => '7', 'note' => 'KSeF API timeout in seconds'),
        );
        $names = array_merge(array_keys($persistentDefaults), array_keys($visibleDefaults));
        $resql = $db->query("SELECT name FROM " . MAIN_DB_PREFIX . "const WHERE entity = " . (int) $conf->entity
            . " AND name IN ('" . implode("','", array_map(array($db, 'escape'), $names)) . "')");
        if ($resql) {
            $have = array();
            while ($obj = $db->fetch_object($resql)) {
                $have[$obj->name] = true;
            }
            $db->free($resql);
            foreach ($persistentDefaults as $name => $defaultValue) {
                if (!isset($have[$name])) {
                    dolibarr_set_const($db, $name, $defaultValue,
                        'chaine', 0, '', $conf->entity);
                }
            }
            foreach ($visibleDefaults as $name => $def) {
                if (!isset($have[$name])) {
                    dolibarr_set_const($db, $name, $def['value'],
                        'chaine', 1, $def['note'], $conf->entity);
                }
            }
        }

        include_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
        $extrafields = new ExtraFields($db);

        $ksefExtraFields = $this->getKsefExtraFieldDefs();
        $extraFieldDefs = $ksefExtraFields['invoice']['defs'];

        $efLevel = $this->migrationLevel(getDolGlobalString('KSEF_MIGRATION_VERSION', ''), getDolGlobalString('KSEF_MIGRATION_SQUASHED', ''));
        $efDefaults = ($efLevel === '' || version_compare($efLevel, self::EXTRAFIELD_DEFAULTS, '<'));
        $efKept = array('label', 'pos', 'list', 'alwayseditable', 'required', 'totalizable', 'printable');
        $elementTypes = $ksefExtraFields['invoice']['elements'];
        foreach ($elementTypes as $elementType) {
            $existingFields = $extrafields->fetch_name_optionals_label($elementType);

            foreach ($extraFieldDefs as $fieldName => $def) {
                if (isset($def['element_types']) && is_array($def['element_types']) && !in_array($elementType, $def['element_types'])) {
                    continue;
                }
                if (isset($def['element_overrides'][$elementType])) {
                    $def = array_merge($def, $def['element_overrides'][$elementType]);
                }
                if (!isset($existingFields[$fieldName])) {
                    $result = $this->addDeclaredExtraField($extrafields, $fieldName, $def, $elementType);
                    if ($result < 0 && $db->errno() != 'DB_ERROR_COLUMN_ALREADY_EXISTS' && $db->errno() != 'DB_ERROR_RECORD_ALREADY_EXISTS') {
                        $this->error = $extrafields->error;
                        return -1;
                    }
                } else {
                    // admin choices survive
                    $attrs = $extrafields->attributes[$elementType];
                    foreach ($efDefaults ? array('printable') : $efKept as $k) {
                        if (isset($attrs[$k][$fieldName])) {
                            $def[$k] = $attrs[$k][$fieldName];
                        }
                    }
                    $more = array();
                    foreach (array('css', 'cssview', 'csslist') as $k) {
                        if (!empty($attrs[$k][$fieldName])) {
                            $more[$k] = $attrs[$k][$fieldName];
                        }
                    }
                    $result = $extrafields->updateExtraField(
                        $fieldName,
                        $def['label'],
                        $def['type'],
                        $def['pos'],
                        $def['size'],
                        $elementType,
                        $def['unique'],
                        $def['required'],
                        $def['default_value'],
                        $def['param'],
                        $def['alwayseditable'],
                        $def['perms'],
                        $def['list'],
                        $def['help'],
                        $def['computed'],
                        $def['entity'],
                        $def['langfile'],
                        $def['enabled'],
                        $def['totalizable'],
                        $def['printable'],
                        $more
                    );
                    if ($result > 0) {
                        dol_syslog("modKSEF::init - Updated extrafield '$fieldName' for $elementType", LOG_INFO);
                    }
                }
            }
        }

        if ($this->addSocieteExtraFields() < 0) {
            return -1;
        }

        $lineExtraFieldDefs = $ksefExtraFields['line']['defs'];

        $lineElementTypes = $ksefExtraFields['line']['elements'];
        foreach ($lineElementTypes as $lineElementType) {
            $existingLineFields = $extrafields->fetch_name_optionals_label($lineElementType);

            foreach ($lineExtraFieldDefs as $fieldName => $def) {
                if (isset($def['element_types']) && is_array($def['element_types']) && !in_array($lineElementType, $def['element_types'])) {
                    continue;
                }
                if (!isset($existingLineFields[$fieldName])) {
                    $result = $this->addDeclaredExtraField($extrafields, $fieldName, $def, $lineElementType);
                    if ($result < 0 && $db->errno() != 'DB_ERROR_COLUMN_ALREADY_EXISTS' && $db->errno() != 'DB_ERROR_RECORD_ALREADY_EXISTS') {
                        $this->error = $extrafields->error;
                        return -1;
                    }
                }
            }
        }

        $migratedBefore = getDolGlobalString('KSEF_MIGRATION_VERSION', '');
        // survives 1.4.5 purge
        $wasInstalled = ($migratedBefore !== '' || getDolGlobalString('KSEF_LAST_INIT_VERSION', '') !== '');
        if (!$wasInstalled) {
            $resql = $db->query("SELECT COUNT(*) AS n FROM " . MAIN_DB_PREFIX . "ksef_submissions AS s INNER JOIN " . MAIN_DB_PREFIX . "facture AS f ON f.rowid = s.fk_facture"
                . " WHERE f.entity = " . (int) $conf->entity);
            if ($resql && ($obj = $db->fetch_object($resql)) && (int) $obj->n > 0) {
                $wasInstalled = true;
            }
        }
        dol_include_once('/ksef/lib/ksef.lib.php');
        $migrations = array(
            '1.2.0' => array(
                function () use ($db, $conf) {
                    if (!ksefDbColumnExists($db, MAIN_DB_PREFIX . "ksef_incoming", 'correction_data')) {
                        $sql_alter = "ALTER TABLE " . MAIN_DB_PREFIX . "ksef_incoming ADD COLUMN correction_data MEDIUMTEXT AFTER corrected_invoice_date";
                        $db->query($sql_alter);
                        dol_syslog("modKSEF::migration 1.2.0 - Added correction_data column", LOG_INFO);
                    }
                },
                function () use ($db, $conf) {
                    $sql_check = "SHOW INDEX FROM " . MAIN_DB_PREFIX . "ksef_incoming WHERE Key_name = 'idx_ksef_incoming_seller_invoice'";
                    $resql = $db->query($sql_check);
                    if ($resql && $db->num_rows($resql) == 0) {
                        $db->query("CREATE INDEX idx_ksef_incoming_seller_invoice ON " . MAIN_DB_PREFIX . "ksef_incoming (seller_nip, invoice_number)");
                        dol_syslog("modKSEF::migration 1.2.0 - Added seller_nip+invoice_number index", LOG_INFO);
                    }
                },
                function () use ($db, $conf) {
                    $migrated = 0;
                    $sql_incoming = "SELECT rowid, fa3_xml, invoice_type FROM " . MAIN_DB_PREFIX . "ksef_incoming"
                        . " WHERE fa3_xml IS NOT NULL AND fa3_xml != '' AND (import_status IS NULL OR import_status <> 'FETCH_ERROR')"
                        . " AND entity = " . (int) $conf->entity;
                    $resql = $db->query($sql_incoming);
                    if ($resql) {
                        dol_include_once('/ksef/class/fa3_parser.class.php');
                        dol_include_once('/ksef/class/ksef_incoming.class.php');
                        $parser = new FA3Parser($db);
                        while ($obj = $db->fetch_object($resql)) {
                            $parsed = $parser->parse($obj->fa3_xml);
                            if ($parsed) {
                                $updates = array();
                                $storedLines = KsefIncoming::storedLineItems($parsed);
                                if ($storedLines !== null) {
                                    $updates[] = "line_items = '" . $db->escape(json_encode($storedLines)) . "'";
                                }
                                if (!empty($parsed['correction'])) {
                                    $updates[] = "correction_data = '" . $db->escape(json_encode($parsed['correction'])) . "'";
                                }
                                if (!empty($updates)) {
                                    $sql_update = "UPDATE " . MAIN_DB_PREFIX . "ksef_incoming"
                                        . " SET " . implode(", ", $updates)
                                        . " WHERE rowid = " . (int) $obj->rowid;
                                    $db->query($sql_update);
                                    $migrated++;
                                }
                            }
                        }
                        $db->free($resql);
                    }
                    dol_syslog("modKSEF::migration 1.2.0 - Re-parsed data for $migrated incoming invoices", LOG_INFO);
                },
            ),
            '1.3.0' => array(
                function () use ($db, $conf) {
                    if (!ksefDbColumnExists($db, MAIN_DB_PREFIX . "ksef_incoming", 'seller_vat_id')) {
                        $sql_alter = "ALTER TABLE " . MAIN_DB_PREFIX . "ksef_incoming ADD COLUMN seller_vat_id VARCHAR(50) AFTER seller_nip";
                        $db->query($sql_alter);
                        dol_syslog("modKSEF::migration 1.3.0 - Added seller_vat_id column", LOG_INFO);
                    }
                },
                function () use ($db, $conf) {
                    $migrated = 0;
                    $sql_incoming = "SELECT rowid, fa3_xml FROM " . MAIN_DB_PREFIX . "ksef_incoming"
                        . " WHERE fa3_xml IS NOT NULL AND fa3_xml != '' AND (import_status IS NULL OR import_status <> 'FETCH_ERROR')"
                        . " AND entity = " . (int) $conf->entity;
                    $resql = $db->query($sql_incoming);
                    if ($resql) {
                        dol_include_once('/ksef/class/fa3_parser.class.php');
                        $parser = new FA3Parser($db);
                        while ($obj = $db->fetch_object($resql)) {
                            $parsed = $parser->parse($obj->fa3_xml);
                            if ($parsed && !empty($parsed['seller']['kod_ue']) && !empty($parsed['seller']['nr_vat_ue'])) {
                                $vatId = $parsed['seller']['kod_ue'] . $parsed['seller']['nr_vat_ue'];
                                $sql_update = "UPDATE " . MAIN_DB_PREFIX . "ksef_incoming"
                                    . " SET seller_vat_id = '" . $db->escape($vatId) . "'"
                                    . " WHERE rowid = " . (int) $obj->rowid;
                                $db->query($sql_update);
                                $migrated++;
                            }
                        }
                        $db->free($resql);
                    }
                    dol_syslog("modKSEF::migration 1.3.0 - Populated seller_vat_id for $migrated incoming invoices", LOG_INFO);
                },
                function () use ($db, $conf) {
                    if (!ksefDbColumnExists($db, MAIN_DB_PREFIX . "ksef_incoming", 'fk_credit_note')) {
                        $sql_alter = "ALTER TABLE " . MAIN_DB_PREFIX . "ksef_incoming ADD COLUMN fk_credit_note INTEGER DEFAULT NULL AFTER fk_facture_fourn";
                        $db->query($sql_alter);
                        dol_syslog("modKSEF::migration 1.3.0 - Added fk_credit_note column", LOG_INFO);
                    }
                },
            ),
            '1.3.2' => array(
                function () use ($db, $conf) {
                    $sql = "UPDATE " . MAIN_DB_PREFIX . "cronjob"
                        . " SET objectname = 'KsefService'"
                        . " WHERE objectname = 'KSEF'"
                        . " AND classesname = '/ksef/class/ksef_service.class.php'"
                        . " AND entity IN (0, " . ((int) $conf->entity) . ")";
                    $db->query($sql);
                    dol_syslog("modKSEF::migration 1.3.2 - Fixed cron job objectname KSEF -> KsefService", LOG_INFO);
                },
                function () use ($db, $conf) {
                    $migrated = 0;
                    $sql_incoming = "SELECT rowid, fa3_xml FROM " . MAIN_DB_PREFIX . "ksef_incoming"
                        . " WHERE fa3_xml IS NOT NULL AND fa3_xml != '' AND (import_status IS NULL OR import_status <> 'FETCH_ERROR')"
                        . " AND entity = " . (int) $conf->entity;
                    $resql = $db->query($sql_incoming);
                    if ($resql) {
                        dol_include_once('/ksef/class/fa3_parser.class.php');
                        dol_include_once('/ksef/class/ksef_incoming.class.php');
                        $parser = new FA3Parser($db);
                        while ($obj = $db->fetch_object($resql)) {
                            $parsed = $parser->parse($obj->fa3_xml);
                            if ($parsed) {
                                $updates = array();
                                $updates[] = "total_net = " . (float) ($parsed['invoice']['total_net'] ?? 0);
                                $updates[] = "total_vat = " . (float) ($parsed['invoice']['total_vat'] ?? 0);
                                $updates[] = "total_gross = " . (float) ($parsed['invoice']['total_gross'] ?? 0);
                                if (!empty($parsed['vat_summary'])) {
                                    $updates[] = "vat_summary = '" . $db->escape(json_encode($parsed['vat_summary'])) . "'";
                                }
                                $storedLines = KsefIncoming::storedLineItems($parsed);
                                if ($storedLines !== null) {
                                    $updates[] = "line_items = '" . $db->escape(json_encode($storedLines)) . "'";
                                }
                                $sql_update = "UPDATE " . MAIN_DB_PREFIX . "ksef_incoming"
                                    . " SET " . implode(", ", $updates)
                                    . " WHERE rowid = " . (int) $obj->rowid;
                                $db->query($sql_update);
                                $migrated++;
                            } else {
                                dol_syslog("modKSEF::migration 1.3.2 - Failed to parse invoice rowid=" . (int) $obj->rowid, LOG_WARNING);
                            }
                        }
                        $db->free($resql);
                    }
                    dol_syslog("modKSEF::migration 1.3.2 - Re-parsed data for $migrated incoming invoices (fix unit prices)", LOG_INFO);
                },
            ),
            '1.3.3' => array(
                function () use ($db, $conf) {
                    if (!ksefDbColumnExists($db, MAIN_DB_PREFIX . "ksef_incoming", 'payment_status')) {
                        $db->query("ALTER TABLE " . MAIN_DB_PREFIX . "ksef_incoming ADD COLUMN payment_status VARCHAR(20) AFTER bank_account");
                        dol_syslog("modKSEF::migration 1.3.3 - Added payment_status column", LOG_INFO);
                    }
                },
                function () use ($db, $conf) {
                    if (!ksefDbColumnExists($db, MAIN_DB_PREFIX . "ksef_incoming", 'payment_date')) {
                        $db->query("ALTER TABLE " . MAIN_DB_PREFIX . "ksef_incoming ADD COLUMN payment_date INTEGER AFTER payment_status");
                        dol_syslog("modKSEF::migration 1.3.3 - Added payment_date column", LOG_INFO);
                    }
                },
                function () use ($db, $conf) {
                    $migrated = 0;
                    $sql = "SELECT rowid, fa3_xml FROM " . MAIN_DB_PREFIX . "ksef_incoming"
                        . " WHERE fa3_xml IS NOT NULL AND fa3_xml != '' AND (import_status IS NULL OR import_status <> 'FETCH_ERROR')"
                        . " AND (payment_status IS NULL OR sale_date IS NULL)"
                        . " AND entity = " . (int) $conf->entity;
                    $resql = $db->query($sql);
                    if ($resql) {
                        dol_include_once('/ksef/class/fa3_parser.class.php');
                        $parser = new FA3Parser($db);
                        while ($obj = $db->fetch_object($resql)) {
                            $parsed = $parser->parse($obj->fa3_xml);
                            if (!$parsed) continue;

                            $updates = array();

                            if (!empty($parsed['payment'])) {
                                $status = $parsed['payment']['status'] ?? null;
                                $payDate = !empty($parsed['payment']['payment_date']) ? strtotime($parsed['payment']['payment_date']) : null;
                                if ($status) {
                                    $updates[] = "payment_status = '" . $db->escape($status) . "'";
                                    if ($payDate && $payDate > 0) {
                                        $updates[] = "payment_date = " . (int) $payDate;
                                    }
                                }
                            }

                            if (!empty($parsed['invoice']['sale_date'])) {
                                $saleDate = strtotime($parsed['invoice']['sale_date']);
                                if ($saleDate && $saleDate > 0) {
                                    $updates[] = "sale_date = " . (int) $saleDate;
                                }
                            }

                            if (!empty($updates)) {
                                $db->query("UPDATE " . MAIN_DB_PREFIX . "ksef_incoming SET " . implode(", ", $updates) . " WHERE rowid = " . (int) $obj->rowid);
                                $migrated++;
                            }
                        }
                        $db->free($resql);
                    }
                    dol_syslog("modKSEF::migration 1.3.3 - Backfilled payment/sale_date for $migrated incoming invoices", LOG_INFO);
                },
                function () use ($db, $conf) {
                    $sql = "UPDATE " . MAIN_DB_PREFIX . "cronjob"
                        . " SET classesname = '/ksef/class/ksef_service.class.php',"
                        . " objectname = 'KsefService'"
                        . " WHERE classesname LIKE '%ksef%'"
                        . " AND methodename IN ('cronCheckStatuses', 'cronSyncIncoming', 'cronDownloadUPOs', 'cronWarnDeadlines', 'cronCheckLatarniaStatus')"
                        . " AND entity IN (0, " . ((int) $conf->entity) . ")";
                    $db->query($sql);
                    dol_syslog("modKSEF::migration 1.3.3 - Fixed cron job classesname and objectname", LOG_INFO);
                },
            ),
            '1.3.7' => array(
                function () use ($db, $conf, $wasInstalled) {
                    if (!$wasInstalled) {
                        return;
                    }
                    $sql = "SELECT value FROM " . MAIN_DB_PREFIX . "const"
                        . " WHERE name = 'KSEF_DODATKOWY_OPIS_NOTE_MODE'"
                        . " AND entity = " . ((int) $conf->entity);
                    $resql = $db->query($sql);
                    if ($resql && $db->num_rows($resql) == 0) {
                        dolibarr_set_const($db, 'KSEF_DODATKOWY_OPIS_NOTE_MODE', 'disabled', 'chaine', 0, '', $conf->entity);
                        dolibarr_set_const($db, 'KSEF_NOTE_PUBLIC_TARGET', 'stopka_faktury', 'chaine', 0, '', $conf->entity);
                        dol_syslog("modKSEF::migration 1.3.7 - Preserved implicit 'disabled' note mode for existing installation", LOG_INFO);
                    }
                },
                function () use ($db, $conf) {
                    $backfillMap = array(
                        'facture'    => 'KSEF_DODATKOWY_OPIS_EXTRAFIELDS',
                        'facturedet' => 'KSEF_DODATKOWY_OPIS_DET_EXTRAFIELDS',
                        'product'    => 'KSEF_DODATKOWY_OPIS_PRODUCT_EXTRAFIELDS',
                        'societe'    => 'KSEF_DODATKOWY_OPIS_SOCIETE_EXTRAFIELDS',
                        'projet'     => 'KSEF_DODATKOWY_OPIS_PROJECT_EXTRAFIELDS',
                    );
                    foreach ($backfillMap as $elType => $configKey) {
                        $configVal = getDolGlobalString($configKey, '');
                        if (empty($configVal)) continue;
                        $fieldEntries = array_filter(array_map('trim', explode(',', $configVal)));
                        foreach ($fieldEntries as $entry) {
                            if (empty($entry)) continue;
                            $parts = explode(':', $entry, 2);
                            $fn = $parts[0];
                            if (empty($fn)) continue;
                            $sql = "UPDATE " . MAIN_DB_PREFIX . "extrafields SET langs = 'ksef@ksef'"
                                . " WHERE name = '" . $db->escape($fn) . "'"
                                . " AND elementtype = '" . $db->escape($elType) . "'"
                                . " AND (langs IS NULL OR langs = '')";
                            $db->query($sql);
                        }
                    }
                    dol_syslog("modKSEF::migration 1.3.7 - Backfilled langfile on module-managed extrafields", LOG_INFO);
                },
                function () use ($db, $conf) {
                    $table = MAIN_DB_PREFIX . "facture_extrafields";
                    $db->query("UPDATE $table SET ksef_dodatkowy_opis_mode = 'simple_dodatkowy' WHERE ksef_dodatkowy_opis_mode = 'simple'");
                    $db->query("UPDATE $table SET ksef_dodatkowy_opis_mode = 'keyvalue_dodatkowy' WHERE ksef_dodatkowy_opis_mode = 'keyvalue'");
                    dol_syslog("modKSEF::migration 1.3.7 - Migrated per-invoice note override values to combined format", LOG_INFO);
                },
                function () use ($db, $conf) {
                    $env = getDolGlobalString('KSEF_ENVIRONMENT', 'DEMO');
                    $env = strtoupper($env);

                    $authKeys = array(
                        'KSEF_AUTH_METHOD',
                        'KSEF_AUTH_TOKEN',
                        'KSEF_TOKEN_UPDATED_AT',
                        'KSEF_AUTH_CERTIFICATE',
                        'KSEF_AUTH_PRIVATE_KEY',
                        'KSEF_AUTH_KEY_PASSWORD',
                        'KSEF_AUTH_CERT_SERIAL',
                        'KSEF_AUTH_CERT_VALID_FROM',
                        'KSEF_AUTH_CERT_VALID_TO',
                        'KSEF_OFFLINE_CERTIFICATE',
                        'KSEF_OFFLINE_PRIVATE_KEY',
                        'KSEF_OFFLINE_KEY_PASSWORD',
                        'KSEF_OFFLINE_CERT_SERIAL',
                        'KSEF_OFFLINE_CERT_VALID_FROM',
                        'KSEF_OFFLINE_CERT_VALID_TO',
                    );

                    foreach ($authKeys as $oldKey) {
                        $oldValue = dolibarr_get_const($db, $oldKey, $conf->entity);
                        if ($oldValue !== '' && $oldValue !== null) {
                            $newKey = $oldKey . '_' . $env;
                            $existingVal = dolibarr_get_const($db, $newKey, $conf->entity);
                            if ($existingVal === '' || $existingVal === null) {
                                dolibarr_set_const($db, $newKey, $oldValue, 'chaine', 0, '', $conf->entity);
                                dol_syslog("modKSEF::migration 1.3.7 - Copied $oldKey to $newKey", LOG_INFO);
                            }
                        }
                        dolibarr_del_const($db, $oldKey, $conf->entity);
                    }
                    dol_syslog("modKSEF::migration 1.3.7 - Per-environment auth key migration complete (env=$env)", LOG_INFO);
                },
            ),
            '1.4.0' => array(
                function () use ($db) {
                    if (!ksefDbColumnExists($db, MAIN_DB_PREFIX . "ksef_submissions", 'invoice_type')) {
                        $db->query("ALTER TABLE " . MAIN_DB_PREFIX . "ksef_submissions ADD COLUMN invoice_type VARCHAR(10) DEFAULT NULL");
                        $db->query("CREATE INDEX idx_ksef_submissions_invoice_type ON " . MAIN_DB_PREFIX . "ksef_submissions (invoice_type)");
                        dol_syslog("modKSEF::migration 1.4.0 - Added invoice_type column and index to submissions", LOG_INFO);
                    }
                },
            ),
            '1.4.1' => array(
                function () use ($db, $conf) {
                    $db->query("DELETE FROM " . MAIN_DB_PREFIX . "const"
                        . " WHERE name = 'KSEF_TIMEOUT' AND entity = " . (int) $conf->entity);
                    dolibarr_set_const($db, 'KSEF_TIMEOUT', '7',
                        'chaine', 1, 'KSeF API timeout in seconds', $conf->entity);
                    dol_syslog("modKSEF::migration 1.4.1 - Recreated KSEF_TIMEOUT as visible with default 7s (#27)", LOG_INFO);
                },
                function () use ($db, $conf) {
                    if (getDolGlobalInt('KSEF_FA3_INCLUDE_FP')) {
                        dolibarr_set_const($db, 'KSEF_FA3_FP_SOURCE', 'always_on', 'chaine', 0, '', $conf->entity);
                        dol_syslog("modKSEF::migration 1.4.1 - Migrated KSEF_FA3_INCLUDE_FP=1 to KSEF_FA3_FP_SOURCE=always_on (#29)", LOG_INFO);
                    }
                },
            ),
            '1.4.4' => array(
                function () use ($db, $conf) {
                    dolibarr_del_const($db, 'KSEF_FA3_INCLUDE_FP', $conf->entity);
                    dolibarr_del_const($db, 'KSEF_NREORI_BUYER_SOURCE', $conf->entity);
                    dol_syslog("modKSEF::migration 1.4.4 - Removed dead consts KSEF_FA3_INCLUDE_FP and KSEF_NREORI_BUYER_SOURCE", LOG_INFO);
                },
            ),
            '1.5.0' => array(
                // re-run safe
                function () use ($db, $conf) {
                    dolibarr_del_const($db, 'KSEF_GUS_FETCH_SUPPLIERS', $conf->entity);
                    dol_syslog("modKSEF::migration 1.5.0 - Removed KSEF_GUS_FETCH_SUPPLIERS", LOG_INFO);
                },
                function () use ($db) {
                    $cols = array(
                        'ksef_session_reference' => "VARCHAR(100) DEFAULT NULL AFTER ksef_number",
                        'source' => "VARCHAR(32) DEFAULT NULL AFTER invoice_hash",
                    );
                    foreach ($cols as $col => $def) {
                        if (!ksefDbColumnExists($db, MAIN_DB_PREFIX . "ksef_submissions", $col)) {
                            $db->query("ALTER TABLE " . MAIN_DB_PREFIX . "ksef_submissions ADD COLUMN " . $col . " " . $def);
                            dol_syslog("modKSEF::migration 1.5.0 - Added ksef_submissions." . $col, LOG_INFO);
                        }
                    }
                },
                function () use ($db, $conf) {
                    if (!getDolGlobalInt('KSEF_NBP_STALE_STRICT_SINCE')) {
                        dolibarr_set_const($db, 'KSEF_NBP_STALE_STRICT_SINCE', (string) dol_now(), 'chaine', 0, '', $conf->entity);
                        dol_syslog("modKSEF::migration 1.5.0 - KSEF_NBP_STALE_STRICT_SINCE set", LOG_INFO);
                    }
                },
                function () use ($db) {
                    if (!$db->DDLListTables($db->database_name, MAIN_DB_PREFIX . "ksef_sales")) {
                        dol_include_once('/ksef/lib/ksef_dbcheck.lib.php');
                        $c = ksefDbCheckCreateTable($db, 'ksef_sales');
                        if ($c['ok'] && !$c['keys_failed']) {
                            dol_syslog("modKSEF::migration 1.5.0 - Created ksef_sales", LOG_INFO);
                        } else {
                            dol_syslog("modKSEF::migration 1.5.0 - Could not create ksef_sales: " . ($c['error'] ?: implode('; ', $c['keys_failed'])), LOG_ERR);
                        }
                    }
                },
                function () use ($db) {
                    if (!$db->DDLListTables($db->database_name, MAIN_DB_PREFIX . "ksef_session_invoice")) {
                        dol_include_once('/ksef/lib/ksef_dbcheck.lib.php');
                        $c = ksefDbCheckCreateTable($db, 'ksef_session_invoice');
                        if ($c['ok'] && !$c['keys_failed']) {
                            dol_syslog("modKSEF::migration 1.5.0 - Created ksef_session_invoice", LOG_INFO);
                        } else {
                            dol_syslog("modKSEF::migration 1.5.0 - Could not create ksef_session_invoice: " . ($c['error'] ?: implode('; ', $c['keys_failed'])), LOG_ERR);
                        }
                    }
                },
                function () use ($db) {
                    if (!$db->DDLListTables($db->database_name, MAIN_DB_PREFIX . "ksef_nbp_rate_row")) {
                        dol_include_once('/ksef/lib/ksef_dbcheck.lib.php');
                        $c = ksefDbCheckCreateTable($db, 'ksef_nbp_rate_row');
                        if (!$c['ok'] || $c['keys_failed']) {
                            dol_syslog("modKSEF::migration 1.5.0 - Could not create ksef_nbp_rate_row: " . ($c['error'] ?: implode('; ', $c['keys_failed'])), LOG_ERR);
                        }
                        if (!$c['ok']) {
                            return;
                        }
                    }
                    dol_include_once('/ksef/class/ksef_nbp_currency_rate.class.php');
                    $m = KsefNbpCurrencyRate::migrateRateRowsConst($db);
                    if ($m['error'] !== '') {
                        dol_syslog("modKSEF::migration 1.5.0 - KSEF_NBP_RATE_ROWS: " . $m['error'], LOG_ERR);
                    } elseif ($m['moved'] + $m['skipped'] > 0) {
                        dol_syslog("modKSEF::migration 1.5.0 - KSEF_NBP_RATE_ROWS moved to ksef_nbp_rate_row: " . $m['moved'] . " record(s), " . $m['skipped'] . " unusable skipped", LOG_INFO);
                    }
                },
                function () use ($db) {
                    if (!$db->DDLListTables($db->database_name, MAIN_DB_PREFIX . "ksef_writeoff")) {
                        dol_include_once('/ksef/lib/ksef_dbcheck.lib.php');
                        $c = ksefDbCheckCreateTable($db, 'ksef_writeoff');
                        if (!$c['ok'] || $c['keys_failed']) {
                            dol_syslog("modKSEF::migration 1.5.0 - Could not create ksef_writeoff: " . ($c['error'] ?: implode('; ', $c['keys_failed'])), LOG_ERR);
                        }
                    }
                },
                function () use ($db, $conf, $wasInstalled) {
                    if (!$wasInstalled) {
                        return;
                    }
                    $writeId = (int) $this->numero + 1;
                    $creerId = 12;
                    $n = 0;
                    foreach (array('user_rights' => 'fk_user', 'usergroup_rights' => 'fk_usergroup') as $table => $col) {
                        $sql = "SELECT DISTINCT r." . $col . " AS who FROM " . MAIN_DB_PREFIX . $table . " AS r"
                            . " WHERE r.fk_id = " . $creerId . " AND r.entity = " . (int) $conf->entity
                            . " AND NOT EXISTS (SELECT 1 FROM " . MAIN_DB_PREFIX . $table . " AS w"
                            . " WHERE w." . $col . " = r." . $col . " AND w.fk_id = " . $writeId . " AND w.entity = r.entity)";
                        $resql = $db->query($sql);
                        if (!$resql) {
                            dol_syslog("modKSEF::migration 1.5.0 - " . $db->lasterror(), LOG_ERR);
                            continue;
                        }
                        while ($obj = $db->fetch_object($resql)) {
                            if ($db->query("INSERT INTO " . MAIN_DB_PREFIX . $table . " (entity, " . $col . ", fk_id) VALUES (" . (int) $conf->entity . ", " . (int) $obj->who . ", " . $writeId . ")")) {
                                $n++;
                            }
                        }
                    }
                    dol_syslog("modKSEF::migration 1.5.0 - Granted KSeF write to " . $n . " invoice creator(s) / group(s): sending now needs it", LOG_INFO);
                },
                function () use ($db) {
                    if (!ksefDbColumnExists($db, MAIN_DB_PREFIX . "ksef_submissions", 'failure_kind')) {
                        $db->query("ALTER TABLE " . MAIN_DB_PREFIX . "ksef_submissions ADD COLUMN failure_kind VARCHAR(16) DEFAULT NULL AFTER error_details");
                        dol_syslog("modKSEF::migration 1.5.0 - Added ksef_submissions.failure_kind", LOG_INFO);
                    }
                },
                function () use ($db) {
                    if (!ksefDbColumnExists($db, MAIN_DB_PREFIX . "ksef_submissions", 'replaced_number')) {
                        $db->query("ALTER TABLE " . MAIN_DB_PREFIX . "ksef_submissions ADD COLUMN replaced_number VARCHAR(100) DEFAULT NULL AFTER failure_kind");
                        dol_syslog("modKSEF::migration 1.5.0 - Added ksef_submissions.replaced_number", LOG_INFO);
                    }
                },
                function () use ($db, $conf) {
                    // collations differ
                    $accepted = array();
                    $resql = $db->query("SELECT fk_facture, ksef_number FROM " . MAIN_DB_PREFIX . "ksef_submissions WHERE status = 'ACCEPTED' AND ksef_number IS NOT NULL AND ksef_number <> ''");
                    if (!$resql) {
                        dol_syslog("modKSEF::migration 1.5.0 - " . $db->lasterror(), LOG_ERR);
                        return;
                    }
                    while ($obj = $db->fetch_object($resql)) {
                        $accepted[trim((string) $obj->ksef_number)][(int) $obj->fk_facture] = true;
                    }
                    if (empty($accepted)) {
                        return;
                    }
                    $resql = $db->query("SELECT f.rowid, f.ref, e.ksef_number FROM " . MAIN_DB_PREFIX . "facture_extrafields AS e"
                        . " INNER JOIN " . MAIN_DB_PREFIX . "facture AS f ON f.rowid = e.fk_object"
                        . " WHERE f.entity = " . (int) $conf->entity . " AND e.ksef_number IS NOT NULL AND e.ksef_number <> ''");
                    if (!$resql) {
                        dol_syslog("modKSEF::migration 1.5.0 - " . $db->lasterror(), LOG_ERR);
                        return;
                    }
                    $copies = array();
                    while ($obj = $db->fetch_object($resql)) {
                        $number = trim((string) $obj->ksef_number);
                        if (isset($accepted[$number]) && empty($accepted[$number][(int) $obj->rowid])) {
                            $copies[] = $obj;
                        }
                    }
                    foreach ($copies as $obj) {
                        $status = 'NULL';
                        $date = 'NULL';
                        $res = $db->query("SELECT status, date_submission FROM " . MAIN_DB_PREFIX . "ksef_submissions WHERE fk_facture = " . (int) $obj->rowid
                            . " ORDER BY date_submission DESC, rowid DESC LIMIT 1");
                        if ($res && ($own = $db->fetch_object($res))) {
                            $status = "'" . $db->escape($own->status) . "'";
                            $date = !empty($own->date_submission) ? "'" . $db->idate((int) $own->date_submission) . "'" : 'NULL';
                        }
                        if ($db->query("UPDATE " . MAIN_DB_PREFIX . "facture_extrafields SET ksef_number = NULL, ksef_status = " . $status . ", ksef_submission_date = " . $date
                            . " WHERE fk_object = " . (int) $obj->rowid)) {
                            dol_syslog("modKSEF::migration 1.5.0 - Invoice " . $obj->ref . " (id " . (int) $obj->rowid . ") carried KSeF number " . $obj->ksef_number
                                . " of another invoice (copy): cleared", LOG_WARNING);
                        } else {
                            dol_syslog("modKSEF::migration 1.5.0 - Invoice " . $obj->ref . ": " . $db->lasterror(), LOG_ERR);
                        }
                    }
                },
                function () use ($db, $conf) {
                    // collations differ
                    $holders = array();
                    $resql = $db->query("SELECT i.ksef_number, i.fk_facture_fourn, i.fk_credit_note FROM " . MAIN_DB_PREFIX . "ksef_incoming AS i"
                        . " WHERE i.entity = " . (int) $conf->entity . " AND (i.fk_facture_fourn > 0 OR i.fk_credit_note > 0)");
                    if (!$resql) {
                        dol_syslog("modKSEF::migration 1.5.0 - " . $db->lasterror(), LOG_ERR);
                        return;
                    }
                    while ($obj = $db->fetch_object($resql)) {
                        $number = trim((string) $obj->ksef_number);
                        foreach (array((int) $obj->fk_facture_fourn, (int) $obj->fk_credit_note) as $linked) {
                            if ($number !== '' && $linked > 0) {
                                $holders[$number][$linked] = true;
                            }
                        }
                    }
                    if (empty($holders)) {
                        return;
                    }
                    $existing = array();
                    $resql = $db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "facture_fourn WHERE entity = " . (int) $conf->entity);
                    if (!$resql) {
                        dol_syslog("modKSEF::migration 1.5.0 - " . $db->lasterror(), LOG_ERR);
                        return;
                    }
                    while ($obj = $db->fetch_object($resql)) {
                        $existing[(int) $obj->rowid] = true;
                    }
                    $resql = $db->query("SELECT f.rowid, f.ref, e.ksef_number FROM " . MAIN_DB_PREFIX . "facture_fourn_extrafields AS e"
                        . " INNER JOIN " . MAIN_DB_PREFIX . "facture_fourn AS f ON f.rowid = e.fk_object"
                        . " WHERE f.entity = " . (int) $conf->entity . " AND e.ksef_number IS NOT NULL AND e.ksef_number <> ''");
                    if (!$resql) {
                        dol_syslog("modKSEF::migration 1.5.0 - " . $db->lasterror(), LOG_ERR);
                        return;
                    }
                    $copies = array();
                    while ($obj = $db->fetch_object($resql)) {
                        $number = trim((string) $obj->ksef_number);
                        if (empty($holders[$number]) || !empty($holders[$number][(int) $obj->rowid])) {
                            continue;
                        }
                        if (count(array_intersect_key($holders[$number], $existing)) > 0) {
                            $copies[] = $obj;
                        }
                    }
                    foreach ($copies as $obj) {
                        if ($db->query("UPDATE " . MAIN_DB_PREFIX . "facture_fourn_extrafields SET ksef_number = NULL, ksef_status = NULL, ksef_submission_date = NULL"
                            . " WHERE fk_object = " . (int) $obj->rowid)) {
                            dol_syslog("modKSEF::migration 1.5.0 - Supplier invoice " . $obj->ref . " (id " . (int) $obj->rowid . ") carried KSeF number " . $obj->ksef_number
                                . " of another supplier invoice (copy): cleared", LOG_WARNING);
                        } else {
                            dol_syslog("modKSEF::migration 1.5.0 - Supplier invoice " . $obj->ref . ": " . $db->lasterror(), LOG_ERR);
                        }
                    }
                },
                function () use ($db, $conf) {
                    $resql = $db->query("SELECT rowid, ksef_number, fa3_xml, line_items FROM " . MAIN_DB_PREFIX . "ksef_incoming"
                        . " WHERE entity = " . (int) $conf->entity . " AND invoice_type IN ('KOR', 'KOR_ZAL', 'KOR_ROZ')"
                        . " AND (import_status IS NULL OR import_status <> 'FETCH_ERROR')"
                        . " AND line_items IS NOT NULL AND line_items <> '' AND fa3_xml LIKE '%StanPrzed%'");
                    if (!$resql) {
                        dol_syslog("modKSEF::migration 1.5.0 - " . $db->lasterror(), LOG_ERR);
                        return;
                    }
                    dol_include_once('/ksef/class/fa3_parser.class.php');
                    dol_include_once('/ksef/class/ksef_incoming.class.php');
                    $parser = new FA3Parser($db);
                    $rows = array();
                    while ($obj = $db->fetch_object($resql)) {
                        $rows[] = $obj;
                    }
                    $db->free($resql);
                    $repaired = 0;
                    foreach ($rows as $obj) {
                        $parsed = $parser->parse($obj->fa3_xml);
                        $lines = $parsed ? KsefIncoming::storedLineItems($parsed) : null;
                        if ($lines === null) {
                            continue;
                        }
                        $json = json_encode($lines);
                        if (json_encode(json_decode((string) $obj->line_items, true)) === $json) {
                            continue;
                        }
                        if ($db->query("UPDATE " . MAIN_DB_PREFIX . "ksef_incoming SET line_items = '" . $db->escape($json) . "' WHERE rowid = " . (int) $obj->rowid)) {
                            $repaired++;
                        } else {
                            dol_syslog("modKSEF::migration 1.5.0 - Incoming " . $obj->ksef_number . ": " . $db->lasterror(), LOG_ERR);
                        }
                    }
                    if ($repaired > 0) {
                        dol_syslog("modKSEF::migration 1.5.0 - Restored the difference lines of " . $repaired . " incoming correction(s) stored as after-state lines", LOG_INFO);
                    }
                },
                function () use ($db) {
                    $widths = array('buyer_nip' => 50, 'seller_name' => 512, 'buyer_name' => 512, 'invoice_number' => 256, 'corrected_invoice_number' => 256, 'fa3_system_info' => 256);
                    foreach (array('ksef_incoming', 'ksef_sales') as $table) {
                        foreach ($widths as $field => $width) {
                            $resql = $db->DDLDescTable(MAIN_DB_PREFIX . $table, $field);
                            $col = $resql ? $db->fetch_object($resql) : null;
                            // PostgreSQL: widen anyway
                            if (!$col || (isset($col->Type) && (!preg_match('/^varchar\((\d+)\)/i', (string) $col->Type, $m) || (int) $m[1] >= $width))) {
                                continue;
                            }
                            if ($db->query("ALTER TABLE " . MAIN_DB_PREFIX . $table . " MODIFY COLUMN " . $field . " VARCHAR(" . (int) $width . ") DEFAULT NULL")) {
                                dol_syslog("modKSEF::migration 1.5.0 - " . $table . "." . $field . " widened to VARCHAR(" . (int) $width . ")", LOG_INFO);
                            } else {
                                dol_syslog("modKSEF::migration 1.5.0 - " . $table . "." . $field . ": " . $db->lasterror(), LOG_ERR);
                            }
                        }
                    }
                },
                function () use ($db) {
                    // match invoice collation
                    dol_include_once('/ksef/lib/ksef_dbcheck.lib.php');
                    foreach (ksefDbHealthCharset($db) as $table => $cs) {
                        if ($db->query("ALTER TABLE " . $db->sanitize($table) . " CONVERT TO CHARACTER SET " . $db->sanitize($cs['cs']) . " COLLATE " . $db->sanitize($cs['target']))) {
                            dol_syslog("modKSEF::migration 1.5.0 - " . $table . " converted from " . $cs['collation'] . " to " . $cs['target'], LOG_INFO);
                        } else {
                            dol_syslog("modKSEF::migration 1.5.0 - " . $table . ": " . $db->lasterror(), LOG_ERR);
                        }
                    }
                },
                function () use ($db) {
                    dol_include_once('/ksef/lib/ksef.lib.php');
                    $n = ksefRecomputeOfflineDeadlines($db);
                    if ($n > 0) {
                        dol_syslog("modKSEF::migration 1.5.0 - Offline deadline of " . $n . " submission(s) moved past Polish public holidays", LOG_INFO);
                    }
                },
                function () use ($db) {
                    dol_include_once('/ksef/lib/ksef.lib.php');
                    $n = ksefBackfillSentInvoiceTypes($db);
                    if ($n > 0) {
                        dol_syslog("modKSEF::migration 1.5.0 - Invoice type of " . $n . " submission(s) read from their stored XML", LOG_INFO);
                    }
                },
                function () use ($db) {
                    dol_include_once('/ksef/lib/ksef.lib.php');
                    $n = ksefBackfillLegacyFailureKind($db);
                    if ($n > 0) {
                        dol_syslog("modKSEF::migration 1.5.0 - " . $n . " failed submission(s) that stopped before the upload marked as connection failures", LOG_INFO);
                    }
                },
                function () use ($db, $conf) {
                    $res = $db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "c_country WHERE code = 'PL'");
                    $pl = ($res && ($o = $db->fetch_object($res))) ? (int) $o->rowid : 0;
                    if ($pl <= 0) {
                        return;
                    }
                    $seeds = array(
                        'MARZA2'  => 'Procedura marzy - uslugi turystyki (art. 119) - KSeF: P_PMarzy_2',
                        'MARZA31' => 'Procedura marzy - towary uzywane (art. 120) - KSeF: P_PMarzy_3_1',
                        'MARZA32' => 'Procedura marzy - dziela sztuki (art. 120) - KSeF: P_PMarzy_3_2',
                        'MARZA33' => 'Procedura marzy - przedmioty kolekcjonerskie i antyki (art. 120) - KSeF: P_PMarzy_3_3',
                    );
                    foreach ($seeds as $code => $note) {
                        $chk = $db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "c_tva WHERE entity IN (0, " . ((int) $conf->entity) . ")"
                            . " AND fk_pays = " . $pl . " AND code = '" . $db->escape($code) . "' AND taux = 0");
                        if ($chk && $db->num_rows($chk) > 0) {
                            continue;
                        }
                        if (!$db->query("INSERT INTO " . MAIN_DB_PREFIX . "c_tva (entity, fk_pays, code, taux, note, active, recuperableonly)"
                            . " VALUES (" . ((int) $conf->entity) . ", " . $pl . ", '" . $db->escape($code) . "', 0, '" . $db->escape($note) . "', 0, 0)")) {
                            dol_syslog("modKSEF::migration 1.5.0 - c_tva " . $code . ": " . $db->lasterror(), LOG_ERR);
                        }
                    }
                },
                function () use ($db, $conf) {
                    $res = $db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "c_country WHERE code = 'PL'");
                    $pl = ($res && ($o = $db->fetch_object($res))) ? (int) $o->rowid : 0;
                    if ($pl <= 0) {
                        return;
                    }
                    $chk = $db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "c_tva WHERE entity IN (0, " . ((int) $conf->entity) . ", " . getEntity('c_tva') . ")"
                        . " AND fk_pays = " . $pl . " AND taux = 5");
                    if (!$chk || $db->num_rows($chk) > 0) {
                        return;
                    }
                    if ($db->query("INSERT INTO " . MAIN_DB_PREFIX . "c_tva (entity, fk_pays, code, taux, note, active, recuperableonly)"
                        . " VALUES (" . ((int) $conf->entity) . ", " . $pl . ", '', 5, 'VAT rate 5 - reduced 1', 1, 0)")) {
                        dol_syslog("modKSEF::migration 1.5.0 - c_tva: PL 5% VAT rate added", LOG_INFO);
                    } else {
                        dol_syslog("modKSEF::migration 1.5.0 - c_tva PL 5%: " . $db->lasterror(), LOG_ERR);
                    }
                },
                function () use ($db, $conf) {
                    $first = (int) $this->numero;
                    $resRights = $db->query("UPDATE " . MAIN_DB_PREFIX . "rights_def SET subperms = '' WHERE module = 'ksef' AND id IN (" . $first . ", " . ($first + 1) . ")"
                        . " AND subperms = '1' AND entity = " . ((int) $conf->entity));
                    if ($resRights) {
                        dol_syslog("modKSEF::migration 1.5.0 - rights " . $first . "/" . ($first + 1) . ": second level cleared (" . $db->affected_rows($resRights) . " row(s))", LOG_INFO);
                    }
                    dolibarr_del_const($db, 'KSEF_ADD_TO_PDF', $conf->entity);
                    dolibarr_del_const($db, 'KSEF_BUTTON_COLOR', $conf->entity);
                    $db->query("UPDATE " . MAIN_DB_PREFIX . "cronjob SET classesname = '/ksef/class/ksef_nbp_currency_rate.class.php', objectname = 'KsefNbpCurrencyRate'"
                        . " WHERE methodename = 'cronSyncMulticurrencyRates' AND classesname LIKE '%ksef%' AND objectname <> 'KsefNbpCurrencyRate'"
                        . " AND entity IN (0, " . ((int) $conf->entity) . ")");
                },
                // before _init()
                function () use ($db) {
                    dol_include_once('/ksef/lib/ksef.lib.php');
                    $n = ksefCronRelabel($db);
                    dol_syslog("modKSEF::migration 1.5.0 - cron labels renamed to lang keys: " . $n . " row(s)", LOG_INFO);
                },
                function () use ($db) {
                    if (!ksefDbColumnExists($db, MAIN_DB_PREFIX . "ksef_submissions", 'locked_fields')) {
                        $db->query("ALTER TABLE " . MAIN_DB_PREFIX . "ksef_submissions ADD COLUMN locked_fields TEXT DEFAULT NULL");
                        dol_syslog("modKSEF::migration 1.5.0 - Added ksef_submissions.locked_fields", LOG_INFO);
                    }
                    dol_include_once('/ksef/lib/ksef.lib.php');
                    dol_include_once('/ksef/class/ksef_submission.class.php');
                    $resql = $db->query("SELECT rowid, fk_facture, status, ksef_number, offline_mode,"
                        . " CASE WHEN fa3_xml IS NOT NULL AND fa3_xml <> '' THEN 1 ELSE 0 END AS has_xml"
                        . " FROM " . MAIN_DB_PREFIX . "ksef_submissions WHERE locked_fields IS NULL OR locked_fields = ''");
                    if (!$resql) {
                        dol_syslog("modKSEF::migration 1.5.0 - " . $db->lasterror(), LOG_ERR);
                        return;
                    }
                    $rows = array();
                    while ($obj = $db->fetch_object($resql)) {
                        $obj->fa3_xml = (int) $obj->has_xml ? 'x' : '';
                        if (ksefRowIsIssued($obj)) {
                            $rows[] = $obj;
                        }
                    }
                    $n = 0;
                    foreach ($rows as $obj) {
                        $json = ksefLockedFieldsSnapshot($db, (int) $obj->fk_facture);
                        $sub = new KsefSubmission($db);
                        $sub->rowid = (int) $obj->rowid;
                        if ($json !== '' && $sub->setLockedFields($json) > 0) {
                            $n++;
                        } elseif ($json !== '') {
                            dol_syslog("modKSEF::migration 1.5.0 - locked fields of submission " . (int) $obj->rowid . ": " . $sub->error, LOG_ERR);
                        }
                    }
                    dol_syslog("modKSEF::migration 1.5.0 - locked fields stored for " . $n . " issued submission(s)", LOG_INFO);
                },
                function () use ($db, $conf) {
                    dol_include_once('/ksef/lib/ksef.lib.php');
                    foreach (ksefMigrateNbpRateModeAll($db, (int) $conf->entity) as $entity => $r) {
                        if ($r !== '') {
                            dol_syslog("modKSEF::migration 1.5.0 - entity " . (int) $entity . ": " . $r, LOG_INFO);
                        }
                    }
                },
                function () use ($db) {
                    if ($db->query("DELETE FROM " . MAIN_DB_PREFIX . "const WHERE name = 'KSEF_FX_CLOSE_SETTLED'")) {
                        dol_syslog("modKSEF::migration 1.5.0 - Removed KSEF_FX_CLOSE_SETTLED (all entities)", LOG_INFO);
                    }
                },
                function () use ($db) {
                    dol_include_once('/ksef/lib/ksef_log.lib.php');
                    dol_syslog("modKSEF::migration 1.5.0 - encrypted " . ksefEncryptStoredSecrets($db) . " stored secret(s) (all entities)", LOG_INFO);
                },
            ),
        );

        $recordedMigration = getDolGlobalString('KSEF_MIGRATION_VERSION', '');
        $lastMigration = $this->migrationLevel($recordedMigration, getDolGlobalString('KSEF_MIGRATION_SQUASHED', ''));
        if ($lastMigration !== $recordedMigration) {
            dol_syslog("modKSEF::init - KSEF_MIGRATION_VERSION " . $recordedMigration . " was recorded before the 1.5.0 migrations were squashed (no KSEF_MIGRATION_SQUASHED 1.5.0), migrating again from " . $lastMigration, LOG_WARNING);
        }
        @set_time_limit(0);
        foreach ($migrations as $version => $steps) {
            if (!empty($lastMigration) && version_compare($lastMigration, $version, '>=')) {
                continue;
            }

            dol_syslog("modKSEF::init - Running migrations for v$version", LOG_INFO);
            foreach ($steps as $step) {
                $step();
            }
            dolibarr_set_const($db, 'KSEF_MIGRATION_VERSION', $version, 'chaine', 0, '', $conf->entity);
            if ($version === self::MIGRATION_SQUASHED) {
                dolibarr_set_const($db, 'KSEF_MIGRATION_SQUASHED', self::MIGRATION_SQUASHED, 'chaine', 0, '', $conf->entity);
            }
        }

        if (getDolGlobalString('KSEF_NBP_RATE_ROWS') !== '') {
            dol_include_once('/ksef/class/ksef_nbp_currency_rate.class.php');
            $m = KsefNbpCurrencyRate::migrateRateRowsConst($db);
            dol_syslog("modKSEF::init - KSEF_NBP_RATE_ROWS moved to ksef_nbp_rate_row: " . $m['moved'] . " record(s)" . ($m['error'] !== '' ? ", " . $m['error'] : ''), $m['error'] !== '' ? LOG_ERR : LOG_INFO);
        }

        dolibarr_set_const($db, 'KSEF_LAST_INIT_VERSION', $this->version, 'chaine', 0, '', $conf->entity);

        $polandId = 0;
        $plSql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "c_country WHERE code = 'PL'";
        $plRes = $db->query($plSql);
        if ($plRes && $plObj = $db->fetch_object($plRes)) $polandId = (int) $plObj->rowid;
        if ($polandId > 0) {
            $vatSeeds = array(
                array('code' => 'ZW',  'note' => 'Zwolniony z VAT (art. 43/113) - KSeF: zw'),
                array('code' => 'RC',  'note' => 'Odwrotne obciazenie (art. 17) - KSeF: oo'),
                array('code' => 'NP',  'note' => 'Niepodlegajace - poza terytorium kraju - KSeF: np I'),
                array('code' => 'NP2', 'note' => 'Niepodlegajace - uslugi art. 100 ust. 1 pkt 4 - KSeF: np II'),
                array('code' => 'WDT', 'note' => 'WDT - wewnatrzwspolnotowa dostawa towarow - KSeF: 0 WDT'),
                array('code' => 'EX',  'note' => 'Eksport towarow - KSeF: 0 EX'),
            );
            foreach ($vatSeeds as $vs) {
                $chk = "SELECT rowid FROM " . MAIN_DB_PREFIX . "c_tva"
                    . " WHERE entity IN (0, " . ((int) $conf->entity) . ")"
                    . " AND fk_pays = " . $polandId
                    . " AND code = '" . $db->escape($vs['code']) . "' AND taux = 0";
                $chkRes = $db->query($chk);
                if ($chkRes && $db->num_rows($chkRes) > 0) continue;
                $db->query("INSERT INTO " . MAIN_DB_PREFIX . "c_tva (entity, fk_pays, code, taux, note, active, recuperableonly)"
                    . " VALUES (" . ((int) $conf->entity) . ", " . $polandId . ", '" . $db->escape($vs['code']) . "'"
                    . ", 0, '" . $db->escape($vs['note']) . "', 0, 0)");
            }
        }

        $res = $this->_init($sql, $options);
        // where to start
        if ($res > 0 && !empty($_SERVER['REQUEST_METHOD']) && strpos((string) ($_SERVER['PHP_SELF'] ?? ''), '/admin/modules.php') !== false && dol_include_once('/ksef/lib/ksef_tour.lib.php')
            && in_array(false, array_map(function ($it) {
                return !empty($it['done']);
            }, ksefHelpChecklist($db, true)), true)) {
            $langs->load('compta');
            setEventMessages($langs->trans('KSEF_Help_Activated', dol_buildpath('/ksef/ksefindex.php', 1) . '#ksef-help', $langs->transnoentitiesnoconv('MenuFinancial')), null, 'warnings');
        }
        return $res;
    }

    /**
     * @brief Add declared extrafield
     * @param ExtraFields $extrafields
     * @param string $name
     * @param array $def Declared definition
     * @param string $element
     * @return int addExtraField() result
     */
    public function addDeclaredExtraField($extrafields, $name, $def, $element)
    {
        if (isset($def['element_overrides'][$element])) {
            $def = array_merge($def, $def['element_overrides'][$element]);
        }
        return $extrafields->addExtraField($name, $def['label'], $def['type'], $def['pos'], $def['size'], $element, $def['unique'], $def['required'],
            $def['default_value'], $def['param'], $def['alwayseditable'], $def['perms'], $def['list'], $def['help'], $def['computed'], $def['entity'],
            $def['langfile'], $def['enabled'], $def['totalizable'], $def['printable']);
    }

    /**
     * @brief Add societe extrafields
     * @return int 1 OK, -1 error
     */
    public function addSocieteExtraFields()
    {
        global $db;

        include_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
        $extrafields = new ExtraFields($db);
        $defs = $this->getKsefExtraFieldDefs();
        $existing = $extrafields->fetch_name_optionals_label('societe', true);
        foreach ($defs['societe']['defs'] as $fieldName => $def) {
            if (isset($existing[$fieldName])) {
                continue;
            }
            $result = $this->addDeclaredExtraField($extrafields, $fieldName, $def, 'societe');
            if ($result < 0 && $db->errno() != 'DB_ERROR_COLUMN_ALREADY_EXISTS' && $db->errno() != 'DB_ERROR_RECORD_ALREADY_EXISTS') {
                $this->error = $extrafields->error;
                return -1;
            }
        }
        return 1;
    }

    // phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
    /**
     * @brief Insert missing jobs
     * @return int Error count
     */
    public function insert_cronjobs()
    {
        // phpcs:enable
        global $conf;
        dol_include_once('/ksef/lib/ksef.lib.php');
        $all = $this->cronjobs;
        // core matches label
        if (is_array($all)) {
            $db = $this->db;
            $this->cronjobs = array_filter($all, function ($job) use ($db, $conf) {
                return !ksefCronFind($db, $job, isset($job['entity']) ? $job['entity'] : $conf->entity);
            });
        }
        $err = parent::insert_cronjobs();
        $this->cronjobs = $all;
        return $err;
    }

    /**
     * @brief Disable module
     * @param string $options '' or 'noboxes'
     * @return int 1 OK, 0 KO
     */
    public function remove($options = '')
    {
        global $conf, $db;

        $sql = array();

        if ($options === '' && getDolGlobalInt('KSEF_PURGE_ON_DISABLE')) {
            $keep = array('KSEF_MIGRATION_VERSION', 'KSEF_MIGRATION_SQUASHED', 'KSEF_LAST_INIT_VERSION', 'KSEF_NBP_STALE_STRICT_SINCE', 'KSEF_NBP_MODE_MIGRATED');
            $resql = $db->query("SELECT name FROM " . MAIN_DB_PREFIX . "const WHERE name LIKE 'KSEF\\_%' AND entity = " . ((int) $conf->entity));
            $names = array();
            while ($resql && ($obj = $db->fetch_object($resql))) {
                $names[] = (string) $obj->name;
            }
            foreach ($names as $constName) {
                if (in_array($constName, $keep, true) || strpos($constName, 'KSEF_SYNC_') === 0) {
                    continue;
                }
                dolibarr_del_const($db, $constName, $conf->entity);
            }
        }

        return $this->_remove($sql, $options);
    }

    /**
     * @brief Disable loses data
     * @return bool
     */
    public function alreadyUsed()
    {
        return (bool) getDolGlobalInt('KSEF_PURGE_ON_DISABLE');
    }
}