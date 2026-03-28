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

class modKSEF extends DolibarrModules
{
    public $url_last_version;
    public $tabs;
    public $dictionaries;

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
        $this->version = '1.3.3';
        $this->url_last_version = '';
        $this->const_name = 'MAIN_MODULE_' . strtoupper($this->name);
        $this->picto = 'ksef@ksef';

        $this->module_parts = array(
            'triggers' => 1,
            'api' => 1,
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
                'invoicesuppliercard',
                'completeTabsHead',
                'afterPDFTotalTable',
                'thirdpartycard',
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

        $this->phpmin = array(7, 4);
        $this->need_dolibarr_version = array(14, 0);

        $this->warnings_activation = array();
        $this->warnings_activation_ext = array();

        $this->const = array();


        if (!isset($conf->ksef) || !isset($conf->ksef->enabled)) {
            $conf->ksef = new stdClass();
            $conf->ksef->enabled = 0;
        }

        $this->tabs = array(
            'invoice:+ksef:KSEF:ksef@ksef:$user->hasRight("ksef", "read"):/ksef/tab_ksef.php?id=__ID__'
        );

        $this->dictionaries = array();
        $this->boxes = array();

        $this->cronjobs = array(
            0 => array(
                'label'          => 'KSEF - Check pending/submitted invoice statuses',
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
                'label'          => 'KSEF - Sync incoming invoices',
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
                'label'          => 'KSEF - Download UPO confirmations',
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
                'label'          => 'KSEF - Warn approaching offline deadlines',
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
        );

        $this->rights = array();
        $r = 0;

        $this->rights[$r][0] = $this->numero + $r;
        $this->rights[$r][1] = 'Read KSEF submissions';
        $this->rights[$r][4] = 'read';
        $this->rights[$r][5] = '1';
        $r++;

        $this->rights[$r][0] = $this->numero + $r;
        $this->rights[$r][1] = 'Create/Update KSEF submissions';
        $this->rights[$r][4] = 'write';
        $this->rights[$r][5] = '1';
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
            'enabled' => '$conf->ksef->enabled',
            'perms' => '1',
            'target' => '',
            'user' => 2,
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
            'enabled' => '$conf->ksef->enabled',
            'perms' => '$user->hasRight("ksef", "read")',
            'target' => '',
            'user' => 2,
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
            'enabled' => '$conf->ksef->enabled',
            'perms' => '$user->hasRight("ksef", "read")',
            'target' => '',
            'user' => 2,
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
            'enabled' => '$conf->ksef->enabled',
            'perms' => '$user->hasRight("ksef", "read")',
            'target' => '',
            'user' => 2,
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
            'enabled' => '$conf->ksef->enabled',
            'perms' => '$user->admin',
            'target' => '',
            'user' => 2,
        );

        $this->export_code = array();
        $this->export_label = array();
        $this->import_code = array();
        $this->import_label = array();
    }

    public function init($options = '')
    {
        global $conf, $langs, $db;

        $sql = array();
        $langs->loadLangs(array("ksef@ksef"));

        // remove old cron job processPendingSubmissions
        $sql[] = "DELETE FROM " . MAIN_DB_PREFIX . "cronjob"
            . " WHERE methodename = 'processPendingSubmissions'"
            . " AND classesname LIKE '%ksef%'";

        $result = $this->_load_tables('/ksef/sql/');
        if ($result < 0) return -1;

        $persistentDefaults = array(
            // PDF & QR Settings
            'KSEF_ADD_TO_PDF'        => '1',
            'KSEF_ADD_QR'            => '1',
            'KSEF_QR_SIZE'           => '25',

            // UI Customization
            'KSEF_BUTTON_COLOR'      => '#dc3545',

            // Purge all configuration when module is disabled?
            'KSEF_PURGE_ON_DISABLE'  => '0',

            // Company & Environment
            'KSEF_COMPANY_NIP'       => '',
            'KSEF_COMPANY_KRS'       => '',
            'KSEF_COMPANY_REGON'     => '',
            'KSEF_COMPANY_BDO'       => '',

            // Field Mapping
            'KSEF_FIELD_NIP'         => 'idprof1',
            'KSEF_FIELD_KRS'         => 'idprof2',
            'KSEF_FIELD_REGON'       => 'idprof3',
            'KSEF_FIELD_BDO'         => 'idprof4',
            'KSEF_ENVIRONMENT'       => 'DEMO',
            'KSEF_TIMEOUT'           => '5',

            // Authentication
            'KSEF_AUTH_METHOD'       => 'token',
            'KSEF_AUTH_TOKEN'        => '',
            'KSEF_TOKEN_UPDATED_AT'  => '',

            // Authentication Certificate
            'KSEF_AUTH_CERTIFICATE'  => '',
            'KSEF_AUTH_PRIVATE_KEY'  => '',
            'KSEF_AUTH_KEY_PASSWORD' => '',
            'KSEF_AUTH_CERT_SERIAL'  => '',
            'KSEF_AUTH_CERT_VALID_FROM' => '',
            'KSEF_AUTH_CERT_VALID_TO'   => '',

            // Offline Certificate
            'KSEF_OFFLINE_CERTIFICATE'  => '',
            'KSEF_OFFLINE_PRIVATE_KEY'  => '',
            'KSEF_OFFLINE_KEY_PASSWORD' => '',
            'KSEF_OFFLINE_CERT_SERIAL'  => '',
            'KSEF_OFFLINE_CERT_VALID_FROM' => '',
            'KSEF_OFFLINE_CERT_VALID_TO'   => '',

            // Customer Exclusions
            'KSEF_EXCLUDED_CUSTOMERS' => '',

            // FA3 Optional Fields
            'KSEF_FA3_INCLUDE_NRKLIENTA'   => '0',
            'KSEF_FA3_INCLUDE_INDEKS'      => '0',
            'KSEF_FA3_INCLUDE_GTIN'        => '0',
            'KSEF_FA3_INCLUDE_UNIT'        => '0',
            'KSEF_FA3_INCLUDE_BANK_DESC'   => '0',
            'KSEF_FA3_PLACE_OF_ISSUE_MODE' => 'disabled',
            'KSEF_FA3_PLACE_OF_ISSUE_CUSTOM' => '',
            'KSEF_FA3_SALE_DATE_SOURCE'      => 'delivery_date',
            'KSEF_NBP_RATE_MODE'           => 'keep_base',
        );

        foreach ($persistentDefaults as $name => $defaultValue) {
            $sql_check = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . "const"
                . " WHERE name = '" . $db->escape($name) . "'"
                . " AND entity = " . (int) $conf->entity;
            $resql = $db->query($sql_check);
            if ($resql) {
                $obj = $db->fetch_object($resql);
                if ($obj->cnt == 0) {
                    dolibarr_set_const($db, $name, $defaultValue,
                        'chaine', 0, '', $conf->entity);
                }
                $db->free($resql);
            }
        }

        include_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
        $extrafields = new ExtraFields($db);

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
                'alwayseditable' => 1,
                'perms' => '',
                'list' => '0',
                'help' => '',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => '$conf->ksef->enabled',
                'totalizable' => 0,
                'printable' => 0,
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
                'alwayseditable' => 1,
                'perms' => '',
                'list' => '1',
                'help' => '',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => '$conf->ksef->enabled',
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
                'list' => '1',
                'help' => '',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => '$conf->ksef->enabled',
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
                'alwayseditable' => 1,
                'perms' => '',
                'list' => '1',
                'help' => 'KSEF_ExtraFieldKursDataHelp',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => 'isModEnabled("multicurrency") && isModEnabled("ksef")',
                'totalizable' => 0,
                'printable' => 0,
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
                'alwayseditable' => 1,
                'perms' => '',
                'list' => '1',
                'help' => 'KSEF_ExtraFieldSaleDateHelp',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => '$conf->ksef->enabled',
                'totalizable' => 0,
                'printable' => 0,
            ),

        );

        // Apply extrafields to both customer and supplier invoices
        $elementTypes = array('facture', 'facture_fourn');
        foreach ($elementTypes as $elementType) {
            $existingFields = $extrafields->fetch_name_optionals_label($elementType);

            foreach ($extraFieldDefs as $fieldName => $def) {
                if (!isset($existingFields[$fieldName])) {
                    $result = $extrafields->addExtraField(
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
                        $def['printable']
                    );
                    if ($result < 0 && $db->errno() != 'DB_ERROR_COLUMN_ALREADY_EXISTS' && $db->errno() != 'DB_ERROR_RECORD_ALREADY_EXISTS') {
                        $this->error = $extrafields->error;
                        return -1;
                    }
                } else {
                    // Update existing extrafield
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
                        $def['printable']
                    );
                    if ($result > 0) {
                        dol_syslog("modKSEF::init - Updated extrafield '$fieldName' for $elementType", LOG_INFO);
                    }
                }
            }
        }

        // specific migrations for version updates
        $migrations = array(
            '1.2.0' => array(
                // Add correction_data column
                function () use ($db, $conf) {
                    $sql_check = "SHOW COLUMNS FROM " . MAIN_DB_PREFIX . "ksef_incoming LIKE 'correction_data'";
                    $resql = $db->query($sql_check);
                    if ($resql && $db->num_rows($resql) == 0) {
                        $sql_alter = "ALTER TABLE " . MAIN_DB_PREFIX . "ksef_incoming ADD COLUMN correction_data MEDIUMTEXT AFTER corrected_invoice_date";
                        $db->query($sql_alter);
                        dol_syslog("modKSEF::migration 1.2.0 - Added correction_data column", LOG_INFO);
                    }
                },
                // composite index
                function () use ($db, $conf) {
                    $sql_check = "SHOW INDEX FROM " . MAIN_DB_PREFIX . "ksef_incoming WHERE Key_name = 'idx_ksef_incoming_seller_invoice'";
                    $resql = $db->query($sql_check);
                    if ($resql && $db->num_rows($resql) == 0) {
                        $db->query("CREATE INDEX idx_ksef_incoming_seller_invoice ON " . MAIN_DB_PREFIX . "ksef_incoming (seller_nip, invoice_number)");
                        dol_syslog("modKSEF::migration 1.2.0 - Added seller_nip+invoice_number index", LOG_INFO);
                    }
                },
                // Re-parse stored incoming invoices
                function () use ($db, $conf) {
                    $migrated = 0;
                    $sql_incoming = "SELECT rowid, fa3_xml, invoice_type FROM " . MAIN_DB_PREFIX . "ksef_incoming"
                        . " WHERE fa3_xml IS NOT NULL AND fa3_xml != ''"
                        . " AND entity = " . (int) $conf->entity;
                    $resql = $db->query($sql_incoming);
                    if ($resql) {
                        dol_include_once('/ksef/class/fa3_parser.class.php');
                        $parser = new FA3Parser($db);
                        while ($obj = $db->fetch_object($resql)) {
                            $parsed = $parser->parse($obj->fa3_xml);
                            if ($parsed) {
                                $updates = array();
                                if (!empty($parsed['lines'])) {
                                    $updates[] = "line_items = '" . $db->escape(json_encode($parsed['lines'])) . "'";
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
                // seller_vat_id column
                function () use ($db, $conf) {
                    $sql_check = "SHOW COLUMNS FROM " . MAIN_DB_PREFIX . "ksef_incoming LIKE 'seller_vat_id'";
                    $resql = $db->query($sql_check);
                    if ($resql && $db->num_rows($resql) == 0) {
                        $sql_alter = "ALTER TABLE " . MAIN_DB_PREFIX . "ksef_incoming ADD COLUMN seller_vat_id VARCHAR(50) AFTER seller_nip";
                        $db->query($sql_alter);
                        dol_syslog("modKSEF::migration 1.3.0 - Added seller_vat_id column", LOG_INFO);
                    }
                },
                // Re-parse to populate seller_vat_id
                function () use ($db, $conf) {
                    $migrated = 0;
                    $sql_incoming = "SELECT rowid, fa3_xml FROM " . MAIN_DB_PREFIX . "ksef_incoming"
                        . " WHERE fa3_xml IS NOT NULL AND fa3_xml != ''"
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
                // Add fk_credit_note column for replace-mode upward corrections
                function () use ($db, $conf) {
                    $sql_check = "SHOW COLUMNS FROM " . MAIN_DB_PREFIX . "ksef_incoming LIKE 'fk_credit_note'";
                    $resql = $db->query($sql_check);
                    if ($resql && $db->num_rows($resql) == 0) {
                        $sql_alter = "ALTER TABLE " . MAIN_DB_PREFIX . "ksef_incoming ADD COLUMN fk_credit_note INTEGER DEFAULT NULL AFTER fk_facture_fourn";
                        $db->query($sql_alter);
                        dol_syslog("modKSEF::migration 1.3.0 - Added fk_credit_note column", LOG_INFO);
                    }
                },
            ),
            '1.3.2' => array(
                // Fix cron jobs
                function () use ($db, $conf) {
                    $sql = "UPDATE " . MAIN_DB_PREFIX . "cronjob"
                        . " SET objectname = 'KsefService'"
                        . " WHERE objectname = 'KSEF'"
                        . " AND classesname = '/ksef/class/ksef_service.class.php'"
                        . " AND entity IN (0, " . ((int) $conf->entity) . ")";
                    $db->query($sql);
                    dol_syslog("modKSEF::migration 1.3.2 - Fixed cron job objectname KSEF -> KsefService", LOG_INFO);
                },
                // Re-parse incoming invoices
                function () use ($db, $conf) {
                    $migrated = 0;
                    $sql_incoming = "SELECT rowid, fa3_xml FROM " . MAIN_DB_PREFIX . "ksef_incoming"
                        . " WHERE fa3_xml IS NOT NULL AND fa3_xml != ''"
                        . " AND entity = " . (int) $conf->entity;
                    $resql = $db->query($sql_incoming);
                    if ($resql) {
                        dol_include_once('/ksef/class/fa3_parser.class.php');
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
                                if (!empty($parsed['lines'])) {
                                    $updates[] = "line_items = '" . $db->escape(json_encode($parsed['lines'])) . "'";
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
                // Add payment_status
                function () use ($db, $conf) {
                    $sql_check = "SHOW COLUMNS FROM " . MAIN_DB_PREFIX . "ksef_incoming LIKE 'payment_status'";
                    $resql = $db->query($sql_check);
                    if ($resql && $db->num_rows($resql) == 0) {
                        $db->query("ALTER TABLE " . MAIN_DB_PREFIX . "ksef_incoming ADD COLUMN payment_status VARCHAR(20) AFTER bank_account");
                        dol_syslog("modKSEF::migration 1.3.3 - Added payment_status column", LOG_INFO);
                    }
                },
                // Add payment_date
                function () use ($db, $conf) {
                    $sql_check = "SHOW COLUMNS FROM " . MAIN_DB_PREFIX . "ksef_incoming LIKE 'payment_date'";
                    $resql = $db->query($sql_check);
                    if ($resql && $db->num_rows($resql) == 0) {
                        $db->query("ALTER TABLE " . MAIN_DB_PREFIX . "ksef_incoming ADD COLUMN payment_date INTEGER AFTER payment_status");
                        dol_syslog("modKSEF::migration 1.3.3 - Added payment_date column", LOG_INFO);
                    }
                },
                // payment_status/payment_date/sale_date reparse
                function () use ($db, $conf) {
                    $migrated = 0;
                    $sql = "SELECT rowid, fa3_xml FROM " . MAIN_DB_PREFIX . "ksef_incoming"
                        . " WHERE fa3_xml IS NOT NULL AND fa3_xml != ''"
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
                // Fix cron job classesname again
                function () use ($db, $conf) {
                    $sql = "UPDATE " . MAIN_DB_PREFIX . "cronjob"
                        . " SET classesname = '/ksef/class/ksef_service.class.php',"
                        . " objectname = 'KsefService'"
                        . " WHERE classesname LIKE '%ksef%'"
                        . " AND methodename LIKE 'cron%'"
                        . " AND entity IN (0, " . ((int) $conf->entity) . ")";
                    $db->query($sql);
                    dol_syslog("modKSEF::migration 1.3.3 - Fixed cron job classesname and objectname", LOG_INFO);
                },
            ),
        );

        $lastMigration = getDolGlobalString('KSEF_MIGRATION_VERSION', '');
        foreach ($migrations as $version => $steps) {

            // TODO /!\: ALWAYS MIGRATE - if commented then migrations run always
            if (!empty($lastMigration) && version_compare($lastMigration, $version, '>=')) {
                continue;
            }
            // END ALWAYS MIGRATE

            dol_syslog("modKSEF::init - Running migrations for v$version", LOG_INFO);
            foreach ($steps as $step) {
                $step();
            }
            dolibarr_set_const($db, 'KSEF_MIGRATION_VERSION', $version, 'chaine', 0, '', $conf->entity);
        }

        return $this->_init($sql, $options);
    }

    public function remove($options = '')
    {
        global $conf, $db;

        $sql = array();

        // purge on disable
        if (!empty($conf->global->KSEF_PURGE_ON_DISABLE)) {
            $constantsToPurge = array(
                // PDF & QR Settings
                'KSEF_ADD_TO_PDF',
                'KSEF_ADD_QR',
                'KSEF_QR_SIZE',

                // UI Customization
                'KSEF_BUTTON_COLOR',

                // Purge on disable (reset itself too)
                'KSEF_PURGE_ON_DISABLE',

                // Company & Environment
                'KSEF_COMPANY_NIP',
                'KSEF_COMPANY_KRS',
                'KSEF_COMPANY_REGON',
                'KSEF_COMPANY_BDO',
                'KSEF_ENVIRONMENT',
                'KSEF_TIMEOUT',

                // Authentication
                'KSEF_AUTH_METHOD',
                'KSEF_AUTH_TOKEN',
                'KSEF_TOKEN_UPDATED_AT',

                // Authentication Certificate
                'KSEF_AUTH_CERTIFICATE',
                'KSEF_AUTH_PRIVATE_KEY',
                'KSEF_AUTH_KEY_PASSWORD',
                'KSEF_AUTH_CERT_SERIAL',
                'KSEF_AUTH_CERT_VALID_FROM',
                'KSEF_AUTH_CERT_VALID_TO',

                // Offline Certificate
                'KSEF_OFFLINE_CERTIFICATE',
                'KSEF_OFFLINE_PRIVATE_KEY',
                'KSEF_OFFLINE_KEY_PASSWORD',
                'KSEF_OFFLINE_CERT_SERIAL',
                'KSEF_OFFLINE_CERT_VALID_FROM',
                'KSEF_OFFLINE_CERT_VALID_TO',

                // Customer Exclusions
                'KSEF_EXCLUDED_CUSTOMERS',

                // FA3 Optional Fields
                'KSEF_FA3_INCLUDE_NRKLIENTA',
                'KSEF_FA3_INCLUDE_INDEKS',
                'KSEF_FA3_INCLUDE_GTIN',
                'KSEF_FA3_INCLUDE_UNIT',
                'KSEF_FA3_INCLUDE_BANK_DESC',
                'KSEF_FA3_PLACE_OF_ISSUE_MODE',
                'KSEF_FA3_PLACE_OF_ISSUE_CUSTOM',
                'KSEF_FA3_SALE_DATE_SOURCE',
                'KSEF_NBP_RATE_MODE',
                'KSEF_MIGRATION_VERSION',
            );

            foreach ($constantsToPurge as $constName) {
                dolibarr_del_const($db, $constName, $conf->entity);
            }
        }

        return $this->_remove($sql, $options);
    }
}