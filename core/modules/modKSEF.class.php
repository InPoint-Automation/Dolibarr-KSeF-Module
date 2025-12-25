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
        $this->version = '0.1.0';
        $this->url_last_version = '';
        $this->const_name = 'MAIN_MODULE_' . strtoupper($this->name);
        $this->picto = 'ksef@ksef';

        $this->module_parts = array(
            'triggers' => 1,
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
                'completeTabsHead',
                'afterPDFTotalTable',
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
        $r = 0;

        // Company & Environment
        $this->const[$r++] = array('KSEF_COMPANY_NIP', 'chaine', '', 'Company NIP for KSEF authentication', 0, 'current', 1);
        $this->const[$r++] = array('KSEF_ENVIRONMENT', 'chaine', 'TEST', 'KSEF API environment (TEST/DEMO/PRODUCTION)', 0, 'current', 1);
        $this->const[$r++] = array('KSEF_TIMEOUT', 'chaine', '5', 'KSEF API timeout in seconds', 0, 'current', 1);

        // Authentication Method Selection
        $this->const[$r++] = array('KSEF_AUTH_METHOD', 'chaine', 'token', 'Authentication method: token or certificate', 0, 'current', 1);

        // Online Authentication Token
        $this->const[$r++] = array('KSEF_AUTH_TOKEN', 'chaine', '', 'KSeF authentication token (encrypted)', 0, 'current', 1);
        $this->const[$r++] = array('KSEF_TOKEN_UPDATED_AT', 'chaine', '', 'Timestamp when auth token was last updated', 0, 'current', 1);

        // Authentication Certificate Configuration
        $this->const[$r++] = array('KSEF_AUTH_CERTIFICATE', 'chaine', '', 'Authentication certificate (Base64 PEM)', 0, 'current', 1);
        $this->const[$r++] = array('KSEF_AUTH_PRIVATE_KEY', 'chaine', '', 'Authentication encrypted private key (Base64 PEM)', 0, 'current', 1);
        $this->const[$r++] = array('KSEF_AUTH_KEY_PASSWORD', 'chaine', '', 'Authentication private key password (encrypted)', 0, 'current', 1);
        $this->const[$r++] = array('KSEF_AUTH_CERT_SERIAL', 'chaine', '', 'Authentication certificate serial number', 0, 'current', 1);
        $this->const[$r++] = array('KSEF_AUTH_CERT_VALID_FROM', 'chaine', '', 'Authentication certificate validity start timestamp', 0, 'current', 1);
        $this->const[$r++] = array('KSEF_AUTH_CERT_VALID_TO', 'chaine', '', 'Authentication certificate validity end timestamp', 0, 'current', 1);

        // Offline Certificate Configuration
        $this->const[$r++] = array('KSEF_OFFLINE_CERTIFICATE', 'chaine', '', 'Offline certificate (Base64 PEM)', 0, 'current', 1);
        $this->const[$r++] = array('KSEF_OFFLINE_PRIVATE_KEY', 'chaine', '', 'Offline encrypted private key (Base64 PEM)', 0, 'current', 1);
        $this->const[$r++] = array('KSEF_OFFLINE_KEY_PASSWORD', 'chaine', '', 'Offline private key password (encrypted)', 0, 'current', 1);
        $this->const[$r++] = array('KSEF_OFFLINE_CERT_SERIAL', 'chaine', '', 'Offline certificate serial number', 0, 'current', 1);
        $this->const[$r++] = array('KSEF_OFFLINE_CERT_VALID_FROM', 'chaine', '', 'Offline certificate validity start timestamp', 0, 'current', 1);
        $this->const[$r++] = array('KSEF_OFFLINE_CERT_VALID_TO', 'chaine', '', 'Offline certificate validity end timestamp', 0, 'current', 1);


        // PDF & QR Settings
        $this->const[$r++] = array('KSEF_ADD_TO_PDF', 'chaine', '1', 'Add KSEF number to invoice PDFs', 0, 'current', 1);
        $this->const[$r++] = array('KSEF_ADD_QR', 'chaine', '1', 'Add KSEF QR code to invoice PDFs', 0, 'current', 1);
        $this->const[$r++] = array('KSEF_QR_SIZE', 'chaine', '25', 'QR code size in mm', 0, 'current', 1);

        // UI Customization
        $this->const[$r++] = array('KSEF_BUTTON_COLOR', 'chaine', '#dc3545', 'KSeF button color', 0, 'current', 1);

        // Customer Exclusions
        $this->const[$r++] = array('KSEF_EXCLUDED_CUSTOMERS', 'chaine', '', 'Comma-separated list of excluded customer IDs', 0, 'current', 1);


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
                'label' => 'KSEF - Process pending submissions',
                'jobtype' => 'method',
                'class' => '/ksef/class/ksef_submission.class.php',
                'objectname' => 'KsefSubmission',
                'method' => 'processPendingSubmissions',
                'parameters' => '',
                'comment' => 'Check and retry pending KSEF submissions',
                'frequency' => 1,
                'unitfrequency' => 3600,
                'status' => 1,
                'test' => '$conf->ksef->enabled',
                'priority' => 50,
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
            'titre' => 'Submission Status',
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
            'titre' => 'HowToUse',
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
            'titre' => 'Configuration',
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

        $result = $this->_load_tables('/ksef/sql/');
        if ($result < 0) return -1;

        include_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
        $extrafields = new ExtraFields($db);
        $existingFields = $extrafields->fetch_name_optionals_label('facture');

        if (!isset($existingFields['ksef_number'])) {
            $result = $extrafields->addExtraField(
                'ksef_number',
                'KSEF Number',
                'varchar',
                100,
                255,
                'facture',
                0, 0, '', '', 1, '', '0', '', '', '', 'ksef@ksef', '$conf->ksef->enabled', 0, 1
            );
            if ($result < 0 && $db->errno() != 'DB_ERROR_COLUMN_ALREADY_EXISTS' && $db->errno() != 'DB_ERROR_RECORD_ALREADY_EXISTS') {
                $this->error = $extrafields->error;
                return -1;
            }
        }

        if (!isset($existingFields['ksef_status'])) {
            $result = $extrafields->addExtraField(
                'ksef_status',
                'KSEF Status',
                'select',
                101,
                0,
                'facture',
                0, 0, 'PENDING',
                array('options' => array(
                    'PENDING' => 'Pending',
                    'ACCEPTED' => 'Accepted',
                    'REJECTED' => 'Rejected',
                    'FAILED' => 'Failed',
                    'OFFLINE' => 'Offline',
                    'VALIDATION_FAILED' => 'Validation Failed'
                )),
                1, '', '1', '', '', '', 'ksef@ksef', '$conf->ksef->enabled', 0, 0
            );
            if ($result < 0 && $db->errno() != 'DB_ERROR_COLUMN_ALREADY_EXISTS' && $db->errno() != 'DB_ERROR_RECORD_ALREADY_EXISTS') {
                $this->error = $extrafields->error;
                return -1;
            }
        }

        if (!isset($existingFields['ksef_submission_date'])) {
            $result = $extrafields->addExtraField(
                'ksef_submission_date',
                'KSEF Submission Date',
                'datetime',
                102,
                0,
                'facture',
                0, 0, '', '', 0, '', '1', '', '', '', 'ksef@ksef', '$conf->ksef->enabled', 0, 0
            );
            if ($result < 0 && $db->errno() != 'DB_ERROR_COLUMN_ALREADY_EXISTS' && $db->errno() != 'DB_ERROR_RECORD_ALREADY_EXISTS') {
                $this->error = $extrafields->error;
                return -1;
            }
        }

        return $this->_init($sql, $options);
    }

    public function remove($options = '')
    {
        $sql = array();
        return $this->_remove($sql, $options);
    }
}
