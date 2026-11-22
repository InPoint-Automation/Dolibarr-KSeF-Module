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
 * \file    ksef/class/ksef_incoming.class.php
 * \ingroup ksef
 * \brief   Incoming invoice DAO
 */

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';
dol_include_once('/ksef/class/fa3_parser.class.php');
dol_include_once('/ksef/lib/ksef.lib.php');

class KsefIncoming extends CommonObject
{
    public $element = 'ksef_incoming';
    public $table_element = 'ksef_incoming';
    // core 22 lacks
    public $warnings = array();

    public $rowid;
    public $ksef_number;
    public $seller_nip;
    public $seller_vat_id;
    public $seller_name;
    public $seller_country;
    public $seller_address;
    public $buyer_nip;
    public $buyer_name;
    public $invoice_number;
    public $invoice_type;
    public $invoice_date;
    public $sale_date;
    public $currency;
    public $total_net;
    public $total_vat;
    public $total_gross;
    public $vat_summary;
    public $line_items;
    public $payment_due_date;
    public $payment_method;
    public $bank_account;
    public $payment_status;
    public $payment_date;
    public $corrected_ksef_number;
    public $corrected_invoice_number;
    public $corrected_invoice_date;
    public $correction_data;
    public $fa3_xml;
    public $fa3_creation_date;
    public $fa3_system_info;
    public $fetch_date;
    public $environment;
    public $fk_facture_fourn;
    public $fk_credit_note;
    public $import_status;
    public $import_date;
    public $import_error;
    public $created_product_ref = '';
    public $entity;
    public $picto = 'supplier_invoice';
    public $error_code = '';
    public $kept_count = 0;
    public $validated = false;
    public $deposit_socid = 0;
    public $korekta_cited = 0;
    public $korekta_linked = array();

    // KsefSales overrides these
    protected $linkColumn = 'fk_facture_fourn';
    protected $triggerPrefix = 'KSEF_INCOMING';
    protected $productRefPrefix = 'KSEF-';
    protected $cardPage = '/ksef/incoming_card.php';
    protected $cardLabel = 'KSEF_IncomingInvoice';

    const STATUS_NEW = 'NEW';
    const STATUS_IMPORTED = 'IMPORTED';
    const STATUS_ERROR = 'ERROR';
    const STATUS_SKIPPED = 'SKIPPED';
    const STATUS_FETCH_ERROR = 'FETCH_ERROR';
    const UNSUPPORTED_FORM_PREFIX = 'unsupported_form:';
    // translated on display
    const FETCH_ERROR_MISSING_FILE = 'Listed in the package metadata but no XML file in the package';
    const FETCH_ERROR_DAMAGED_FILE = 'XML file damaged or empty in the package';
    const FETCH_ERROR_NOT_STORED = 'KSEF_NOT_STORED';

    // REST sort whitelist
    const SORT_FIELDS = array(
        'i.invoice_number', 'i.invoice_type', 'i.seller_name', 'i.seller_nip',
        'i.import_status', 'i.invoice_date', 'i.total_gross', 'i.fetch_date', 'i.rowid', 'i.ksef_number',
    );

    /**
     * @brief Constructor
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
        $this->import_status = self::STATUS_NEW;
    }

    /**
     * @brief Safe strtotime
     * @param string $dateStr
     * @return int|null
     */
    protected static function safeStrtotime($dateStr)
    {
        if (empty($dateStr)) return null;
        $ts = strtotime($dateStr);
        if ($ts === false || $ts < 0 || $ts > 2147483647) {
            dol_syslog("KsefIncoming::safeStrtotime: out of range or invalid date '{$dateStr}' (ts={$ts})", LOG_WARNING);
            return null;
        }
        return $ts;
    }

    /**
     * @brief Earliest due date
     * @param string $rawXml FA(3) XML
     * @return int|null
     */
    public static function earliestDueDate($rawXml)
    {
        if (empty($rawXml)) {
            return null;
        }
        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($rawXml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$loaded) {
            return null;
        }
        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query("/*[local-name()='Faktura']/*[local-name()='Fa']/*[local-name()='Platnosc']"
            . "/*[local-name()='TerminPlatnosci']/*[local-name()='Termin']");
        $earliest = null;
        foreach ($nodes ?: array() as $node) {
            $ts = self::safeStrtotime(trim($node->textContent));
            if ($ts !== null && ($earliest === null || $ts < $earliest)) {
                $earliest = $ts;
            }
        }
        return $earliest;
    }

    /**
     * @brief Subclass extra columns
     * @return array Escaped values by column
     */
    protected function insertExtraColumns()
    {
        return array();
    }

    /**
     * @brief Read subclass columns
     * @param object $obj DB row
     * @return void
     */
    protected function fetchExtraColumns($obj)
    {
    }

    /**
     * @brief Create record
     * @param $user User object
     * @param $notrigger
     * @param $notransaction Caller owns transaction
     * @return int Id, <0 on error
     */
    public function create($user, $notrigger = 0, $notransaction = false)
    {
        global $conf;

        $error = 0;

        $this->ksef_number = trim($this->ksef_number);
        if (empty($this->fetch_date)) {
            $this->fetch_date = dol_now();
        }
        if (empty($this->entity)) {
            $this->entity = $conf->entity;
        }

        if (!$notransaction) {
            $this->db->begin();
        }

        $sql = "INSERT INTO " . MAIN_DB_PREFIX . $this->table_element . " (";
        $sql .= "ksef_number,";
        $sql .= "seller_nip,";
        $sql .= "seller_vat_id,";
        $sql .= "seller_name,";
        $sql .= "seller_country,";
        $sql .= "seller_address,";
        $sql .= "buyer_nip,";
        $sql .= "buyer_name,";
        $sql .= "invoice_number,";
        $sql .= "invoice_type,";
        $sql .= "invoice_date,";
        $sql .= "sale_date,";
        $sql .= "currency,";
        $sql .= "total_net,";
        $sql .= "total_vat,";
        $sql .= "total_gross,";
        $sql .= "vat_summary,";
        $sql .= "line_items,";
        $sql .= "payment_due_date,";
        $sql .= "payment_method,";
        $sql .= "bank_account,";
        $sql .= "payment_status,";
        $sql .= "payment_date,";
        $sql .= "corrected_ksef_number,";
        $sql .= "corrected_invoice_number,";
        $sql .= "corrected_invoice_date,";
        $sql .= "correction_data,";
        $sql .= "fa3_xml,";
        $sql .= "fa3_creation_date,";
        $sql .= "fa3_system_info,";
        $sql .= "fetch_date,";
        $sql .= "environment,";
        $sql .= "import_status,";
        $sql .= "import_error,";
        $extra = $this->insertExtraColumns();
        foreach ($extra as $col => $val) {
            $sql .= $col . ",";
        }
        $sql .= "entity";
        $sql .= ")";
        $sql .= " VALUES (";
        $sql .= " '" . $this->db->escape($this->ksef_number) . "',";
        $sql .= " " . ($this->seller_nip ? "'" . $this->db->escape($this->seller_nip) . "'" : "NULL") . ",";
        $sql .= " " . ($this->seller_vat_id ? "'" . $this->db->escape($this->seller_vat_id) . "'" : "NULL") . ",";
        $sql .= " " . ($this->seller_name ? "'" . $this->db->escape($this->seller_name) . "'" : "NULL") . ",";
        $sql .= " " . ($this->seller_country ? "'" . $this->db->escape($this->seller_country) . "'" : "'PL'") . ",";
        $sql .= " " . ($this->seller_address ? "'" . $this->db->escape($this->seller_address) . "'" : "NULL") . ",";
        $sql .= " " . ($this->buyer_nip ? "'" . $this->db->escape($this->buyer_nip) . "'" : "NULL") . ",";
        $sql .= " " . ($this->buyer_name ? "'" . $this->db->escape($this->buyer_name) . "'" : "NULL") . ",";
        $sql .= " " . ($this->invoice_number ? "'" . $this->db->escape($this->invoice_number) . "'" : "NULL") . ",";
        $sql .= " " . ($this->invoice_type ? "'" . $this->db->escape($this->invoice_type) . "'" : "'VAT'") . ",";
        $sql .= " " . ($this->invoice_date ? (int)$this->invoice_date : "NULL") . ",";
        $sql .= " " . ($this->sale_date ? (int)$this->sale_date : "NULL") . ",";
        $sql .= " " . ($this->currency ? "'" . $this->db->escape($this->currency) . "'" : "'PLN'") . ",";
        $sql .= " " . ($this->total_net !== null ? (float)$this->total_net : "NULL") . ",";
        $sql .= " " . ($this->total_vat !== null ? (float)$this->total_vat : "NULL") . ",";
        $sql .= " " . ($this->total_gross !== null ? (float)$this->total_gross : "NULL") . ",";
        $sql .= " " . ($this->vat_summary ? "'" . $this->db->escape($this->vat_summary) . "'" : "NULL") . ",";
        $sql .= " " . ($this->line_items ? "'" . $this->db->escape($this->line_items) . "'" : "NULL") . ",";
        $sql .= " " . ($this->payment_due_date ? (int)$this->payment_due_date : "NULL") . ",";
        $sql .= " " . ($this->payment_method ? "'" . $this->db->escape($this->payment_method) . "'" : "NULL") . ",";
        $sql .= " " . ($this->bank_account ? "'" . $this->db->escape($this->bank_account) . "'" : "NULL") . ",";
        $sql .= " " . ($this->payment_status ? "'" . $this->db->escape($this->payment_status) . "'" : "NULL") . ",";
        $sql .= " " . ($this->payment_date ? (int)$this->payment_date : "NULL") . ",";
        $sql .= " " . ($this->corrected_ksef_number ? "'" . $this->db->escape($this->corrected_ksef_number) . "'" : "NULL") . ",";
        $sql .= " " . ($this->corrected_invoice_number ? "'" . $this->db->escape($this->corrected_invoice_number) . "'" : "NULL") . ",";
        $sql .= " " . ($this->corrected_invoice_date ? (int)$this->corrected_invoice_date : "NULL") . ",";
        $sql .= " " . ($this->correction_data ? "'" . $this->db->escape($this->correction_data) . "'" : "NULL") . ",";
        $sql .= " " . ($this->fa3_xml ? "'" . $this->db->escape($this->fa3_xml) . "'" : "NULL") . ",";
        $sql .= " " . ($this->fa3_creation_date ? (int)$this->fa3_creation_date : "NULL") . ",";
        $sql .= " " . ($this->fa3_system_info ? "'" . $this->db->escape($this->fa3_system_info) . "'" : "NULL") . ",";
        $sql .= " " . (int)$this->fetch_date . ",";
        $sql .= " " . ($this->environment ? "'" . $this->db->escape($this->environment) . "'" : "'TEST'") . ",";
        $sql .= " '" . $this->db->escape($this->import_status ?: self::STATUS_NEW) . "',";
        $sql .= " " . ($this->import_error ? "'" . $this->db->escape($this->import_error) . "'" : "NULL") . ",";
        foreach ($extra as $col => $val) {
            $sql .= " " . $val . ",";
        }
        $sql .= " " . (int)$this->entity;
        $sql .= ")";

        dol_syslog("KsefIncoming::create", LOG_DEBUG);
        $savepoint = ksefDbWriteSavepoint($this->db, 'ksef_incoming_write');
        $resql = $this->db->query($sql);

        if (!$resql) {
            $error++;
            $this->errors[] = "Error " . $this->db->lasterror();
            dol_syslog("KsefIncoming::create " . $this->db->lasterror(), LOG_ERR);
        }

        if (!$error) {
            $this->rowid = $this->db->last_insert_id(MAIN_DB_PREFIX . $this->table_element);
            $this->id = $this->rowid;
            $cut = ksefDbRejectTruncated($this->db, $savepoint, get_class($this) . '::create ' . $this->ksef_number);
            if ($cut !== '') {
                $error++;
                $this->errors[] = $cut;
                if ($savepoint === '') {
                    $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . $this->table_element . " WHERE rowid = " . (int) $this->rowid);
                }
                $this->rowid = 0;
                $this->id = 0;
            }
        }

        if (!$error) {
            if (!$notrigger) {
                $result = $this->call_trigger($this->triggerPrefix . '_CREATE', $user);
                if ($result < 0) {
                    $error++;
                }
            }
        }

        if ($error) {
            foreach ($this->errors as $errmsg) {
                dol_syslog("KsefIncoming::create " . $errmsg, LOG_ERR);
                $this->error .= ($this->error ? ', ' . $errmsg : $errmsg);
            }
            if (!$notransaction) {
                $this->db->rollback();
            }
            return -1 * $error;
        } else {
            if (!$notransaction) {
                $this->db->commit();
            }
            return $this->rowid;
        }
    }

    /**
     * @brief Hydrate from row
     * @param object $obj SELECT * row
     * @return void
     */
    protected function setFromRow($obj)
    {
        $this->rowid = $obj->rowid;
        $this->id = $obj->rowid;
        $this->ksef_number = $obj->ksef_number;
        $this->seller_nip = $obj->seller_nip;
        $this->seller_vat_id = $obj->seller_vat_id ?? null;
        $this->seller_name = $obj->seller_name;
        $this->seller_country = $obj->seller_country;
        $this->seller_address = $obj->seller_address;
        $this->buyer_nip = $obj->buyer_nip;
        $this->buyer_name = $obj->buyer_name;
        $this->invoice_number = $obj->invoice_number;
        $this->invoice_type = $obj->invoice_type;
        $this->invoice_date = $obj->invoice_date;
        $this->sale_date = $obj->sale_date;
        $this->currency = $obj->currency;
        $this->total_net = $obj->total_net;
        $this->total_vat = $obj->total_vat;
        $this->total_gross = $obj->total_gross;
        $this->vat_summary = $obj->vat_summary;
        $this->line_items = $obj->line_items;
        $this->payment_due_date = $obj->payment_due_date;
        $this->payment_method = $obj->payment_method;
        $this->bank_account = $obj->bank_account;
        $this->payment_status = $obj->payment_status ?? null;
        $this->payment_date = $obj->payment_date ?? null;
        $this->corrected_ksef_number = $obj->corrected_ksef_number;
        $this->corrected_invoice_number = $obj->corrected_invoice_number;
        $this->corrected_invoice_date = $obj->corrected_invoice_date;
        $this->correction_data = $obj->correction_data;
        $this->fa3_xml = $obj->fa3_xml;
        $this->fa3_creation_date = $obj->fa3_creation_date;
        $this->fa3_system_info = $obj->fa3_system_info;
        $this->fetch_date = $obj->fetch_date;
        $this->environment = $obj->environment;
        $link = $this->linkColumn;
        $this->$link = $obj->$link;
        $this->fk_credit_note = $obj->fk_credit_note ?? null;
        $this->import_status = $obj->import_status;
        $this->import_date = $obj->import_date;
        $this->import_error = $obj->import_error;
        $this->entity = $obj->entity;
        $this->fetchExtraColumns($obj);
    }

    /**
     * @brief Fetch by id/number
     * @param $id
     * @param $ksef_number
     * @return int 1 found, 0 none, -1 error
     */
    public function fetch($id, $ksef_number = '')
    {
        $sql = "SELECT * FROM " . MAIN_DB_PREFIX . $this->table_element . " WHERE ";

        if ($id > 0) {
            $sql .= "rowid = " . (int)$id;
        } elseif (!empty($ksef_number)) {
            $sql .= "ksef_number = '" . $this->db->escape($ksef_number) . "'";
        } else {
            return -1;
        }
        $sql .= " AND entity IN (" . getEntity($this->element) . ")";

        dol_syslog("KsefIncoming::fetch", LOG_DEBUG);
        $resql = $this->db->query($sql);

        if ($resql) {
            if ($this->db->num_rows($resql) > 0) {
                $this->setFromRow($this->db->fetch_object($resql));

                $this->db->free($resql);
                return 1;
            } else {
                $this->db->free($resql);
                return 0;
            }
        } else {
            $this->error = "Error " . $this->db->lasterror();
            return -1;
        }
    }

    /**
     * @brief Update record
     * @param $user User object
     * @param $notrigger
     * @return int <0 on error
     */
    public function update($user, $notrigger = 0)
    {
        $error = 0;

        $this->db->begin();

        $sql = "UPDATE " . MAIN_DB_PREFIX . $this->table_element . " SET ";
        $sql .= "import_status = '" . $this->db->escape($this->import_status) . "',";
        $sql .= "import_date = " . ($this->import_date ? (int)$this->import_date : "NULL") . ",";
        $sql .= "import_error = " . ($this->import_error ? "'" . $this->db->escape($this->import_error) . "'" : "NULL") . ",";
        $link = $this->linkColumn;
        $sql .= $link . " = " . ($this->$link ? (int)$this->$link : "NULL") . ",";
        $sql .= "fk_credit_note = " . ($this->fk_credit_note ? (int)$this->fk_credit_note : "NULL");
        foreach ($this->insertExtraColumns() as $col => $val) {
            $sql .= ", " . $col . " = " . $val;
        }
        $sql .= " WHERE rowid = " . (int)$this->rowid;

        dol_syslog("KsefIncoming::update", LOG_DEBUG);
        $savepoint = ksefDbWriteSavepoint($this->db, 'ksef_incoming_write');
        $resql = $this->db->query($sql);

        if (!$resql) {
            $error++;
            $this->errors[] = "Error " . $this->db->lasterror();
        } else {
            $cut = ksefDbRejectTruncated($this->db, $savepoint, get_class($this) . '::update row ' . (int) $this->rowid);
            if ($cut !== '') {
                $error++;
                $this->errors[] = $cut;
                $this->error = $cut;
            }
        }

        if (!$error && !$notrigger) {
            $result = $this->call_trigger($this->triggerPrefix . '_MODIFY', $user);
            if ($result < 0) {
                $error++;
            }
        }

        if ($error) {
            $this->db->rollback();
            return -1 * $error;
        } else {
            $this->db->commit();
            return 1;
        }
    }

    /**
     * @brief Check deletable
     * @return bool
     */
    public function canDelete()
    {
        global $langs;

        if ($this->import_status === self::STATUS_IMPORTED || $this->linkedSupplierDocumentExists()) {
            $langs->load('ksef@ksef');
            $this->error = $langs->trans('KSEF_ErrorDeleteImportedIncoming');
            return false;
        }
        return true;
    }

    /**
     * @brief Linked document exists
     * @return bool
     */
    protected function linkedSupplierDocumentExists()
    {
        if ($this->linkColumn !== 'fk_facture_fourn') {
            return false;
        }
        $ids = array();
        foreach (array('fk_facture_fourn', 'fk_credit_note') as $col) {
            if (!empty($this->$col) && (int) $this->$col > 0) {
                $ids[] = (int) $this->$col;
            }
        }
        if (!$ids) {
            return false;
        }
        $sql = "SELECT COUNT(*) AS n FROM " . MAIN_DB_PREFIX . "facture_fourn WHERE rowid IN (" . implode(',', $ids) . ")";
        $resql = $this->db->query($sql);
        if (!$resql) {
            // keep the row
            return true;
        }
        $obj = $this->db->fetch_object($resql);
        return $obj && (int) $obj->n > 0;
    }

    /**
     * @brief Delete if allowed
     * @param $user User object
     * @param $notrigger
     * @return int -2 refused, <0 error
     */
    public function delete($user, $notrigger = 0)
    {
        if (!$this->canDelete()) {
            return -2;
        }

        $error = 0;

        $this->db->begin();

        if (!$notrigger) {
            $result = $this->call_trigger($this->triggerPrefix . '_DELETE', $user);
            if ($result < 0) {
                $error++;
            }
        }

        if (!$error) {
            $sql = "DELETE FROM " . MAIN_DB_PREFIX . $this->table_element;
            $sql .= " WHERE rowid = " . (int)$this->rowid;

            dol_syslog("KsefIncoming::delete", LOG_DEBUG);
            $resql = $this->db->query($sql);

            if (!$resql) {
                $error++;
                $this->errors[] = "Error " . $this->db->lasterror();
            }
        }

        if ($error) {
            $this->db->rollback();
            return -1 * $error;
        } else {
            $this->db->commit();
            return 1;
        }
    }

    /**
     * @brief Delete all deletable
     * @param $user User object
     * @param $environment Filter
     * @return int Deleted count, -1 error
     */
    public function deleteAll($user, $environment = '')
    {
        global $conf;

        $this->kept_count = 0;
        $this->db->begin();

        $where = " WHERE entity IN (" . getEntity($this->element) . ")";
        if (!empty($environment)) {
            $where .= " AND environment = '" . $this->db->escape($environment) . "'";
        }
        if ($this->linkColumn === 'fk_facture_fourn') {
            $live = "SELECT ff.rowid FROM " . MAIN_DB_PREFIX . "facture_fourn AS ff";
            $where .= " AND import_status <> '" . self::STATUS_IMPORTED . "'";
            $where .= " AND (fk_facture_fourn IS NULL OR fk_facture_fourn NOT IN (" . $live . "))";
            $where .= " AND (fk_credit_note IS NULL OR fk_credit_note NOT IN (" . $live . "))";
            $resql = $this->db->query("SELECT COUNT(*) AS n FROM " . MAIN_DB_PREFIX . $this->table_element . " WHERE entity IN (" . getEntity($this->element) . ")"
                . (!empty($environment) ? " AND environment = '" . $this->db->escape($environment) . "'" : ''));
            if ($resql && ($o = $this->db->fetch_object($resql))) {
                $this->kept_count = (int) $o->n;
            }
        }

        $sql = "DELETE FROM " . MAIN_DB_PREFIX . $this->table_element . $where;

        dol_syslog("KsefIncoming::deleteAll", LOG_DEBUG);
        $resql = $this->db->query($sql);

        if (!$resql) {
            $this->error = "Error " . $this->db->lasterror();
            $this->db->rollback();
            return -1;
        }

        $deleted = $this->db->affected_rows($resql);
        $this->kept_count = max(0, $this->kept_count - $deleted);
        $this->db->commit();

        dol_syslog("KsefIncoming::deleteAll deleted $deleted records, kept " . $this->kept_count, LOG_INFO);
        return $deleted;
    }

    /**
     * @brief Exists by number
     * @param $ksef_number
     * @return bool
     */
    public function existsByKsefNumber($ksef_number)
    {
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . $this->table_element;
        $sql .= " WHERE ksef_number = '" . $this->db->escape($ksef_number) . "'";
        $sql .= " LIMIT 1";

        $resql = $this->db->query($sql);
        if ($resql) {
            $exists = ($this->db->num_rows($resql) > 0);
            $this->db->free($resql);
            return $exists;
        }
        return false;
    }

    /**
     * @brief Stored environments
     * @param array $ksefNumbers
     * @return array ksef_number => environment
     */
    public function getStoredEnvironments($ksefNumbers)
    {
        $out = array();
        if (empty($ksefNumbers)) {
            return $out;
        }
        $escaped = array();
        foreach ($ksefNumbers as $num) {
            $escaped[] = "'" . $this->db->escape($num) . "'";
        }
        $sql = "SELECT ksef_number, environment FROM " . MAIN_DB_PREFIX . $this->table_element;
        $sql .= " WHERE ksef_number IN (" . implode(',', $escaped) . ")";
        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $out[$obj->ksef_number] = (string) $obj->environment;
            }
            $this->db->free($resql);
        }
        return $out;
    }

    /**
     * @brief Create from parsed
     * @param $parsedData FA3Parser output
     * @param $rawXml
     * @param $ksefNumber
     * @param $environment TEST/DEMO/PRODUCTION
     * @param $user User object
     * @param $notransaction Caller owns transaction
     * @return int Id, <0 on error
     */
    public function createFromParsed($parsedData, $rawXml, $ksefNumber, $environment, $user, $notransaction = false)
    {
        $this->ksef_number = $ksefNumber;
        $this->assignParsed($parsedData, $rawXml);
        $this->fetch_date = dol_now();
        $this->environment = $environment;
        $this->import_status = self::STATUS_NEW;

        return $this->create($user, 0, $notransaction);
    }

    /**
     * @brief Fill from parsed
     * @param array $parsedData FA3Parser output
     * @param string $rawXml
     * @return void
     */
    protected function assignParsed($parsedData, $rawXml)
    {
        $this->seller_nip = $parsedData['seller']['nip'] ?? '';
        $this->seller_name = $parsedData['seller']['name'] ?? '';
        $this->seller_country = $parsedData['seller']['country'] ?? 'PL';
        $this->seller_address = $parsedData['seller']['address'] ?? '';
        $this->buyer_nip = $parsedData['buyer']['nip'] ?? '';
        $this->buyer_name = $parsedData['buyer']['name'] ?? '';
        $this->invoice_number = $parsedData['invoice']['number'] ?? '';
        $this->invoice_type = $parsedData['invoice']['type'] ?? 'VAT';
        $this->invoice_date = self::safeStrtotime($parsedData['invoice']['date'] ?? '');
        $this->sale_date = self::safeStrtotime($parsedData['invoice']['sale_date'] ?? '');
        $this->currency = $parsedData['invoice']['currency'] ?? 'PLN';
        $this->total_net = $parsedData['invoice']['total_net'] ?? 0;
        $this->total_vat = $parsedData['invoice']['total_vat'] ?? 0;
        $this->total_gross = $parsedData['invoice']['total_gross'] ?? 0;
        if (!empty($parsedData['vat_summary'])) {
            $this->vat_summary = json_encode($parsedData['vat_summary']);
        }
        $linesToStore = self::storedLineItems($parsedData);
        if ($linesToStore !== null) {
            $this->line_items = json_encode($linesToStore);
        }
        $earliestDue = self::earliestDueDate($rawXml);
        $this->payment_due_date = $earliestDue !== null ? $earliestDue : self::safeStrtotime($parsedData['payment']['due_date'] ?? '');
        $this->payment_method = $parsedData['payment']['method'] ?? null;
        $this->bank_account = $parsedData['payment']['bank_account'] ?? null;
        $this->payment_status = $parsedData['payment']['status'] ?? null;
        $this->payment_date = self::safeStrtotime($parsedData['payment']['payment_date'] ?? '');
        if (!empty($parsedData['correction'])) {
            $firstCorrected = $parsedData['correction']['corrected_invoices'][0] ?? array();
            $this->corrected_ksef_number = $firstCorrected['ksef_number'] ?? null;
            $this->corrected_invoice_number = $firstCorrected['invoice_number'] ?? null;
            $this->corrected_invoice_date = self::safeStrtotime($firstCorrected['invoice_date'] ?? '');
            $this->correction_data = json_encode($parsedData['correction']);
        }
        $this->fa3_xml = $rawXml;
        $this->fa3_creation_date = self::safeStrtotime($parsedData['header']['creation_date'] ?? '');
        $this->fa3_system_info = $parsedData['header']['system_info'] ?? '';
    }

    /**
     * @brief Store FETCH_ERROR row
     * @param string $ksefNumber
     * @param string|null $rawXml Null if missing
     * @param string $environment
     * @param string $message Reason
     * @param array $meta Package metadata
     * @param User $user
     * @return int Id, 0 exists, <0 error
     */
    public function createFailed($ksefNumber, $rawXml, $environment, $message, $meta, $user)
    {
        $meta = is_array($meta) ? $meta : array();
        $this->ksef_number = $ksefNumber;
        $this->seller_nip = (string) ($meta['seller']['nip'] ?? '');
        $this->seller_name = (string) ($meta['seller']['name'] ?? '');
        $this->buyer_nip = (string) ($meta['buyer']['identifier']['value'] ?? '');
        $this->buyer_name = (string) ($meta['buyer']['name'] ?? '');
        $this->invoice_number = (string) ($meta['invoiceNumber'] ?? '');
        $types = array('Vat' => 'VAT', 'Kor' => 'KOR', 'Zal' => 'ZAL', 'Roz' => 'ROZ', 'Upr' => 'UPR', 'KorZal' => 'KOR_ZAL', 'KorRoz' => 'KOR_ROZ', 'VatPef' => 'PEF', 'VatPefSp' => 'PEF', 'KorPef' => 'KOR_PEF');
        $type = (string) ($meta['invoiceType'] ?? '');
        $this->invoice_type = isset($types[$type]) ? $types[$type] : (strlen($type) <= 10 && $type !== '' ? $type : '');
        $this->invoice_date = self::safeStrtotime($meta['issueDate'] ?? '');
        $this->currency = (string) ($meta['currency'] ?? '') ?: 'PLN';
        $this->total_net = isset($meta['netAmount']) ? (float) $meta['netAmount'] : null;
        $this->total_vat = isset($meta['vatAmount']) ? (float) $meta['vatAmount'] : null;
        $this->total_gross = isset($meta['grossAmount']) ? (float) $meta['grossAmount'] : null;
        $this->fa3_xml = is_string($rawXml) ? $rawXml : null;
        $this->fetch_date = dol_now();
        $this->environment = $environment;
        $this->import_status = self::STATUS_FETCH_ERROR;
        $this->import_error = dol_trunc((string) $message, 2000, 'right', 'UTF-8', 1);

        // unique across environments
        if ($this->existsByKsefNumber($ksefNumber)) {
            return 0;
        }
        $res = $this->create($user, 0, false);
        if ($res < 0 && $this->db->lasterrno() === 'DB_ERROR_RECORD_ALREADY_EXISTS') {
            // concurrent worker won
            return 0;
        }
        if ($res < 0) {
            // 1.4.5 widths
            dol_syslog(get_class($this) . '::createFailed ' . $ksefNumber . ' stored without XML: ' . $this->db->lasterror(), LOG_WARNING);
            $widths = array('seller_nip' => 20, 'seller_name' => 255, 'buyer_nip' => 50, 'buyer_name' => 255, 'invoice_number' => 100, 'currency' => 3, 'import_error' => 2000);
            foreach ($widths as $field => $width) {
                $this->$field = self::bareText($this->$field, $width);
            }
            $this->import_error = self::FETCH_ERROR_NOT_STORED;
            $this->fa3_xml = null;
            $this->errors = array();
            $res = $this->create($user, 0, false);
            if ($res < 0 && $this->db->lasterrno() === 'DB_ERROR_RECORD_ALREADY_EXISTS') {
                return 0;
            }
        }
        return $res;
    }

    /**
     * @brief Bare row text
     * @param string $text
     * @param int $width Characters
     * @return string Valid UTF-8, no 4-byte chars
     */
    private static function bareText($text, $width)
    {
        $text = (string) preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', mb_convert_encoding((string) $text, 'UTF-8', 'UTF-8'));
        return dol_trunc($text, $width, 'right', 'UTF-8', 1);
    }

    /**
     * @brief Fetch error text
     * @return string Not escaped
     */
    public function getFetchErrorText()
    {
        global $langs;
        $langs->load("ksef@ksef");
        $err = (string) $this->import_error;
        if (strpos($err, self::UNSUPPORTED_FORM_PREFIX) === 0) {
            return $langs->transnoentitiesnoconv('KSEF_FetchErrorUnsupportedForm', substr($err, strlen(self::UNSUPPORTED_FORM_PREFIX)));
        }
        if ($err === self::FETCH_ERROR_MISSING_FILE) {
            return $langs->transnoentitiesnoconv('KSEF_FetchErrorMissingFile');
        }
        if ($err === self::FETCH_ERROR_NOT_STORED) {
            return $langs->transnoentitiesnoconv('KSEF_FetchErrorNotStored');
        }
        if ($err === self::FETCH_ERROR_DAMAGED_FILE) {
            return $langs->transnoentitiesnoconv('KSEF_FetchErrorDamagedFile');
        }
        if (strpos($err, 'Failed to parse XML') === 0) {
            $detail = trim(substr($err, strlen('Failed to parse XML')), ": \t");
            return $langs->transnoentitiesnoconv('KSEF_FetchErrorParse') . ($detail !== '' ? ': ' . $detail : '');
        }
        return $err;
    }

    /**
     * @brief Is correction type
     * @param string $type
     * @return bool
     */
    public static function isCorrectionType($type)
    {
        return in_array($type, array('KOR', 'KOR_ZAL', 'KOR_ROZ'));
    }

    /**
     * @brief Resolve cited invoices
     * @return array
     */
    public function resolveCorrectedInvoices()
    {
        require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';

        $correctionData = self::isCorrectionType($this->invoice_type) ? $this->getCorrectionData() : null;
        if (empty($correctionData) && self::isCorrectionType($this->invoice_type) && ($this->corrected_invoice_number || $this->corrected_ksef_number)) {
            $correctionData = array(
                'reason' => null,
                'corrected_invoices' => array(
                    array(
                        'invoice_number' => $this->corrected_invoice_number,
                        'invoice_date' => $this->corrected_invoice_date ? dol_print_date($this->corrected_invoice_date, '%Y-%m-%d') : '',
                        'ksef_number' => $this->corrected_ksef_number,
                    ),
                ),
            );
        }

        $correctedInvoices = !empty($correctionData['corrected_invoices']) ? $correctionData['corrected_invoices'] : array();
        $resolved = array();

        foreach ($correctedInvoices as $corrInv) {
            if (empty($corrInv['ksef_number']) && empty($corrInv['invoice_number']) && empty($corrInv['invoice_date'])) {
                continue;
            }

            $entry = array(
                'invoice_number' => $corrInv['invoice_number'] ?? '',
                'invoice_date' => $corrInv['invoice_date'] ?? '',
                'ksef_number' => $corrInv['ksef_number'] ?? '',
                'incoming' => null,
                'supplier_invoice' => null,
            );

            $lookup = new KsefIncoming($this->db);
            $found = false;
            if (!empty($corrInv['ksef_number'])) {
                $found = ($lookup->fetch(0, $corrInv['ksef_number']) > 0);
            }
            if (!$found && !empty($corrInv['invoice_number']) && !empty($this->seller_nip)) {
                $found = ($lookup->fetchBySellerAndInvoice($this->seller_nip, $corrInv['invoice_number'], $this->environment) > 0);
            }
            if ($found) {
                $entry['incoming'] = $lookup;
                if ($lookup->fk_facture_fourn > 0) {
                    $suppInv = new FactureFournisseur($this->db);
                    if ($suppInv->fetch($lookup->fk_facture_fourn) > 0) {
                        $entry['supplier_invoice'] = $suppInv;
                    }
                }
            }

            if (empty($entry['supplier_invoice']) && !empty($corrInv['invoice_number']) && (!empty($this->seller_nip) || !empty($this->seller_vat_id))) {
                $nip = preg_replace('/[^0-9]/', '', $this->seller_nip);
                $vatId = !empty($this->seller_vat_id) ? $this->seller_vat_id : '';

                $matchWhere = $this->buildThirdPartyMatchWhere($nip, $vatId);
                $sql = "SELECT f.rowid FROM " . MAIN_DB_PREFIX . "facture_fourn f";
                $sql .= " INNER JOIN " . MAIN_DB_PREFIX . "societe s ON f.fk_soc = s.rowid";
                $sql .= " WHERE f.ref_supplier = '" . $this->db->escape($corrInv['invoice_number']) . "'";
                // supplier flag optional
                $sql .= " AND s.entity IN (" . getEntity('societe') . ")";
                $sql .= " AND f.entity IN (" . getEntity('supplier_invoice') . ")";
                $sql .= " AND (" . $matchWhere . ")";
                $sql .= " LIMIT 1";
                $resql = $this->db->query($sql);
                if ($resql && ($obj = $this->db->fetch_object($resql))) {
                    $suppInv = new FactureFournisseur($this->db);
                    if ($suppInv->fetch($obj->rowid) > 0) {
                        $entry['supplier_invoice'] = $suppInv;
                    }
                }
            }

            $resolved[] = $entry;
        }

        return $resolved;
    }

    /**
     * @brief Batch correction source
     * @param array $batchIds Same-batch rows
     * @return array
     */
    public function checkBatchCorrectionSource($batchIds = array())
    {
        global $langs;
        $out = array('blocked' => false, 'found' => false, 'issues' => array(), 'messages' => array());
        if (!self::isCorrectionType($this->invoice_type)) {
            return $out;
        }
        $resolved = $this->resolveCorrectedInvoices();
        if (empty($resolved)) {
            $out['issues'][] = 'KSEF_BatchCorrectionNoReference';
        }
        $batchIds = array_map('intval', (array) $batchIds);
        $pending = false;
        foreach ($resolved as $rc) {
            if (!empty($rc['ksef_number']) && $rc['ksef_number'] === $this->ksef_number) {
                $out['issues'][] = 'KSEF_BatchCorrectionSelfReference';
            } elseif (!empty($rc['supplier_invoice'])) {
                $out['found'] = true;
            } elseif ($rc['incoming'] && $rc['incoming']->fk_facture_fourn <= 0 && in_array((int) $rc['incoming']->id, $batchIds, true)) {
                $pending = true;
            } elseif ($rc['incoming'] && $rc['incoming']->fk_facture_fourn <= 0) {
                $out['issues'][] = 'KSEF_BatchCorrectionOriginalNotImported';
            } elseif (!empty($rc['ksef_number']) && !$rc['incoming']) {
                $out['issues'][] = 'KSEF_BatchCorrectionOriginalNotInSystem';
            }
        }
        if (!$out['found'] && !$pending && empty($out['issues'])) {
            $byHand = true;
            foreach ($resolved as $rc) {
                $byHand = $byHand && empty($rc['ksef_number']) && !$rc['incoming'];
            }
            $out['issues'][] = $byHand ? 'KSEF_BatchCorrectionOriginalByHand' : 'KSEF_BatchCorrectionOriginalNotImported';
        }
        $why = '';
        if (empty($out['issues']) && $out['found'] && !is_object($why = $this->resolveKorektaSource())) {
            $out['issues'][] = 'KSEF_BatchKorektaNotReady';
        }
        $out['issues'] = array_values(array_unique($out['issues']));
        foreach ($out['issues'] as $key) {
            $out['messages'][] = ($key === 'KSEF_BatchKorektaNotReady' && is_string($why) && $why !== '') ? $why : $langs->transnoentitiesnoconv($key);
        }
        $out['blocked'] = !empty($out['issues']);
        return $out;
    }

    /**
     * @brief Decoded correction data
     * @return array|null
     */
    public function getCorrectionData()
    {
        if (empty($this->correction_data)) {
            return null;
        }
        $decoded = json_decode($this->correction_data, true);
        if (!is_array($decoded)) return null;
        return $decoded;
    }

    /**
     * @brief Fetch by seller
     * @param string $sellerNip
     * @param string $invoiceNumber P_2
     * @param string $environment Empty: any
     * @return int 1 found, 0 none, -1 error
     */
    public function fetchBySellerAndInvoice($sellerNip, $invoiceNumber, $environment = '')
    {
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . $this->table_element
            . " WHERE seller_nip = '" . $this->db->escape($sellerNip) . "'"
            . " AND invoice_number = '" . $this->db->escape($invoiceNumber) . "'"
            . " AND entity IN (" . getEntity($this->element) . ")"
            . ($environment !== '' ? " AND environment = '" . $this->db->escape($environment) . "'" : "")
            . " ORDER BY rowid DESC"
            . " LIMIT 1";

        $resql = $this->db->query($sql);
        if ($resql) {
            if ($this->db->num_rows($resql) > 0) {
                $obj = $this->db->fetch_object($resql);
                $this->db->free($resql);
                return $this->fetch($obj->rowid);
            }
            $this->db->free($resql);
            return 0;
        }
        return -1;
    }

    /**
     * @brief Fetch by supplier
     * @param int $fk_facture_fourn
     * @return int >0 found, 0 none, -1 error
     */
    public function fetchBySupplierInvoice($fk_facture_fourn)
    {
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . $this->table_element
            . " WHERE fk_facture_fourn = " . (int)$fk_facture_fourn
            . " LIMIT 1";

        $resql = $this->db->query($sql);
        if ($resql) {
            if ($this->db->num_rows($resql) > 0) {
                $obj = $this->db->fetch_object($resql);
                $this->db->free($resql);
                return $this->fetch($obj->rowid);
            }
            $this->db->free($resql);
            return 0;
        }
        return -1;
    }

    /**
     * @brief Fetch by note
     * @param int $fkCreditNote
     * @return int 1 found, 0 none, -1 error
     */
    public function fetchByCreditNote($fkCreditNote)
    {
        $resql = $this->db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . $this->table_element . " WHERE fk_credit_note = " . (int) $fkCreditNote . " LIMIT 1");
        if (!$resql) {
            return -1;
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        return $obj ? $this->fetch($obj->rowid) : 0;
    }

    /**
     * @brief Decoded line items
     * @return array
     */
    public function getLineItems()
    {
        if (empty($this->line_items)) {
            return array();
        }
        $decoded = json_decode($this->line_items, true);
        if (!is_array($decoded)) return array();

        foreach ($decoded as &$line) {
            if ($this->isNullOrBlank($line['unit_price_net'] ?? null) && !$this->isNullOrBlank($line['net_amount'] ?? null) && !$this->isNullOrBlank($line['quantity'] ?? null) && $line['quantity'] != 0) {
                $line['unit_price_net'] = round($line['net_amount'] / $line['quantity'], 4);
            }
            if ($this->isNullOrBlank($line['unit_price_gross'] ?? null) && !$this->isNullOrBlank($line['gross_amount'] ?? null) && !$this->isNullOrBlank($line['quantity'] ?? null) && $line['quantity'] != 0) {
                $line['unit_price_gross'] = round($line['gross_amount'] / $line['quantity'], 4);
            }

            $vatRate = isset($line['vat_rate']) && is_numeric($line['vat_rate']) ? (float)$line['vat_rate'] : 0;
            $vatMultiplier = 1 + $vatRate / 100;
            if ($this->isNullOrBlank($line['net_amount'] ?? null) && !$this->isNullOrBlank($line['gross_amount'] ?? null) && $vatMultiplier > 0) {
                $line['net_amount'] = round($line['gross_amount'] / $vatMultiplier, 2);
            }
            if ($this->isNullOrBlank($line['unit_price_net'] ?? null) && !$this->isNullOrBlank($line['unit_price_gross'] ?? null) && $vatMultiplier > 0) {
                $line['unit_price_net'] = round($line['unit_price_gross'] / $vatMultiplier, 4);
            }
            if ($this->isNullOrBlank($line['gross_amount'] ?? null) && !$this->isNullOrBlank($line['net_amount'] ?? null)) {
                $line['gross_amount'] = round($line['net_amount'] * $vatMultiplier, 2);
            }
            if ($this->isNullOrBlank($line['unit_price_gross'] ?? null) && !$this->isNullOrBlank($line['unit_price_net'] ?? null)) {
                $line['unit_price_gross'] = round($line['unit_price_net'] * $vatMultiplier, 4);
            }
        }

        return $decoded;
    }

    /**
     * @brief Resolve line amount
     * @param array $line
     * @return array qty, pu, price_base_type
     */
    public static function resolveLineAmount($line)
    {
        $declared = isset($line['declared']) && is_array($line['declared']) ? $line['declared'] : null;
        $has = function ($el, $key) use ($line, $declared) {
            if ($declared !== null) {
                return in_array($el, $declared, true) && isset($line[$key]) && $line[$key] !== '';
            }
            return isset($line[$key]) && $line[$key] !== null && $line[$key] !== '';
        };
        $qty = $has('P_8B', 'quantity') ? (float) $line['quantity'] : 0.0;
        if ($has('P_11', 'net_amount')) {
            $amount = (float) $line['net_amount'];
            $base = 'HT';
        } elseif ($has('P_11A', 'gross_amount')) {
            $amount = (float) $line['gross_amount'];
            $base = 'TTC';
        } else {
            $amount = null;
            $base = 'HT';
        }
        if ($amount !== null) {
            if ($qty != 0) {
                return array('qty' => $qty, 'pu' => $amount / $qty, 'price_base_type' => $base);
            }
            return array('qty' => 1.0, 'pu' => $amount, 'price_base_type' => $base);
        }
        if ($has('P_9A', 'unit_price_net')) {
            return array('qty' => $qty != 0 ? $qty : 1.0, 'pu' => (float) $line['unit_price_net'], 'price_base_type' => 'HT');
        }
        if ($has('P_9B', 'unit_price_gross')) {
            return array('qty' => $qty != 0 ? $qty : 1.0, 'pu' => (float) $line['unit_price_gross'], 'price_base_type' => 'TTC');
        }
        return array('qty' => $qty != 0 ? $qty : 1.0, 'pu' => 0.0, 'price_base_type' => 'HT');
    }

    /**
     * @brief Diff correction line
     * @param array $after
     * @param array $before StanPrzed line
     * @return array
     */
    public static function diffCorrectionLine($after, $before)
    {
        $a = self::resolveLineAmount($after);
        $b = self::resolveLineAmount($before);
        $vatRate = isset($after['vat_rate']) && is_numeric($after['vat_rate']) ? (float) $after['vat_rate'] : 0.0;
        $mult = 1 + $vatRate / 100;
        $aNet = $a['qty'] * $a['pu'] / ($a['price_base_type'] === 'TTC' ? $mult : 1);
        $bNet = $b['qty'] * $b['pu'] / ($b['price_base_type'] === 'TTC' ? $mult : 1);
        $netDelta = round($aNet - $bNet, 2);
        $qtyDelta = (float) ($after['quantity'] ?? 0) - (float) ($before['quantity'] ?? 0);
        $line = $after;
        $line['net_amount'] = $netDelta;
        $line['gross_amount'] = round($netDelta * $mult, 2);
        $line['price_only'] = !($qtyDelta != 0 && $netDelta != 0 && (($qtyDelta > 0) === ($netDelta > 0)));
        $line['quantity'] = $line['price_only'] ? 1.0 : $qtyDelta;
        $line['unit_price_net'] = $line['quantity'] != 0 ? $netDelta / $line['quantity'] : 0.0;
        $line['unit_price_gross'] = $line['quantity'] != 0 ? $line['gross_amount'] / $line['quantity'] : 0.0;
        $line['declared'] = array('P_8B', 'P_11');
        return $line;
    }

    /**
     * @brief Pair correction rows
     * @param array $lines
     * @param array $linesBefore StanPrzed lines
     * @return array after => before
     */
    public static function pairCorrectionRows($lines, $linesBefore)
    {
        $pairs = array();
        $usedBefore = array();
        $take = function ($i, $j) use (&$pairs, &$usedBefore) {
            $pairs[$i] = $j;
            $usedBefore[$j] = true;
        };
        foreach ($linesBefore as $j => $b) {
            if ((string) ($b['uuid'] ?? '') === '') {
                continue;
            }
            foreach ($lines as $i => $a) {
                if (!isset($pairs[$i]) && (string) ($a['uuid'] ?? '') === (string) $b['uuid']) {
                    $take($i, $j);
                    break;
                }
            }
        }
        $afterNums = array();
        foreach ($lines as $a) {
            $afterNums[(int) ($a['line_num'] ?? 0)] = true;
        }
        $shared = false;
        foreach ($linesBefore as $b) {
            if (isset($afterNums[(int) ($b['line_num'] ?? 0)])) {
                $shared = true;
                break;
            }
        }
        if ($shared) {
            foreach ($linesBefore as $j => $b) {
                if (isset($usedBefore[$j])) {
                    continue;
                }
                foreach ($lines as $i => $a) {
                    if (!isset($pairs[$i]) && (int) ($a['line_num'] ?? 0) === (int) ($b['line_num'] ?? 0)) {
                        $take($i, $j);
                        break;
                    }
                }
            }
            return $pairs;
        }
        $key = function ($l) {
            return trim((string) ($l['description'] ?? '')) . "\n" . trim((string) ($l['indeks'] ?? ''));
        };
        foreach ($linesBefore as $j => $b) {
            if (isset($usedBefore[$j])) {
                continue;
            }
            foreach ($lines as $i => $a) {
                if (!isset($pairs[$i]) && $key($a) === $key($b)) {
                    $take($i, $j);
                    break;
                }
            }
        }
        $byNum = function ($rows, $skip) {
            $left = array();
            foreach ($rows as $k => $r) {
                if (!isset($skip[$k])) {
                    $left[$k] = (int) ($r['line_num'] ?? 0);
                }
            }
            asort($left);
            return array_keys($left);
        };
        $leftA = $byNum($lines, $pairs);
        $leftB = $byNum($linesBefore, $usedBefore);
        $n = min(count($leftA), count($leftB));
        for ($k = 0; $k < $n; $k++) {
            $take($leftA[$k], $leftB[$k]);
        }
        return $pairs;
    }

    /**
     * @brief Mark rebate row
     * @param array $line
     * @return array
     */
    private static function markRebateRow($line)
    {
        if ((float) ($line['quantity'] ?? 0) != 0 && self::resolveLineAmount($line)['pu'] < 0) {
            $line['price_only'] = true;
        }
        return $line;
    }

    /**
     * @brief Apply correction diff
     * @param array $lines
     * @param array $linesBefore StanPrzed lines
     * @return array
     */
    public static function applyCorrectionDiff($lines, $linesBefore)
    {
        $lines = array_values($lines);
        $linesBefore = array_values((array) $linesBefore);
        $pairs = empty($linesBefore) ? array() : self::pairCorrectionRows($lines, $linesBefore);
        $out = array();
        foreach ($lines as $i => $line) {
            if (isset($pairs[$i])) {
                $out[] = self::diffCorrectionLine($line, $linesBefore[$pairs[$i]]);
                continue;
            }
            $out[] = self::markRebateRow($line);
        }
        if (empty($linesBefore)) {
            return $out;
        }
        $paired = array_flip($pairs);
        foreach ($linesBefore as $j => $bLine) {
            if (isset($paired[$j])) {
                continue;
            }
            $zero = $bLine;
            $zero['quantity'] = 0.0;
            $zero['net_amount'] = 0.0;
            $zero['gross_amount'] = 0.0;
            $zero['declared'] = array('P_8B', 'P_11');
            $removed = self::diffCorrectionLine($zero, $bLine);
            $removed['correction_removed'] = true;
            $out[] = $removed;
        }
        // stable since PHP8
        usort($out, function ($x, $y) {
            return (int) ($x['line_num'] ?? 0) <=> (int) ($y['line_num'] ?? 0);
        });
        return $out;
    }

    /**
     * @brief Lines to store
     * @param array $parsed Parser result
     * @return array|null Null if none
     */
    public static function storedLineItems($parsed)
    {
        $lines = isset($parsed['lines']) && is_array($parsed['lines']) ? $parsed['lines'] : array();
        $before = isset($parsed['lines_before']) && is_array($parsed['lines_before']) ? $parsed['lines_before'] : array();
        if (empty($lines) && empty($before)) {
            return null;
        }
        if (empty($before) && !self::isCorrectionType((string) ($parsed['invoice']['type'] ?? ''))) {
            return $lines;
        }
        return self::applyCorrectionDiff($lines, $before);
    }

    /**
     * @brief VAT by rate
     * @return array
     */
    public function getVatSummary()
    {
        if (empty($this->vat_summary)) {
            return array();
        }
        $decoded = json_decode($this->vat_summary, true);
        return is_array($decoded) ? $decoded : array();
    }

    /**
     * @brief PLN per unit
     * @return float 0 if unknown
     */
    public function getExchangeRate()
    {
        if (empty($this->fa3_xml)) {
            return 0;
        }
        $parser = new FA3Parser($this->db);
        $parsed = $parser->parse($this->fa3_xml);
        if (ksefParsedKurs($parsed) > 0) {
            return ksefParsedKurs($parsed);
        }
        $pln = 0.0;
        $vat = 0.0;
        foreach ((array) ($parsed['vat_summary'] ?? array()) as $r) {
            if (isset($r['vat_pln']) && $r['vat_pln'] !== null && (float) $r['vat'] != 0) {
                $pln += (float) $r['vat_pln'];
                $vat += (float) $r['vat'];
            }
        }
        // reproduces P_14_xW
        return $vat != 0 ? round($pln / $vat, 6) : 0;
    }

    /**
     * @brief Check import currency
     * @param CommonInvoice|null $facture Null: check only
     * @return string '' if ok
     */
    public function importCurrencyRefusal($facture = null)
    {
        global $langs;
        $code = strtoupper((string) $this->currency);
        // keeps source currency
        if ($code === '' || $code === 'PLN' || self::isCorrectionType($this->invoice_type)) {
            return '';
        }
        $langs->load('ksef@ksef');
        if (!isModEnabled('multicurrency')) {
            return $langs->transnoentitiesnoconv('KSEF_ImportCurrencyModuleOff', $code);
        }
        require_once DOL_DOCUMENT_ROOT . '/multicurrency/class/multicurrency.class.php';
        if (MultiCurrency::getIdFromCode($this->db, $code) <= 0) {
            return $langs->transnoentitiesnoconv('KSEF_ImportCurrencyNotSetUp', $code, $code);
        }
        if ($facture) {
            $facture->multicurrency_code = $code;
            $rate = $this->getExchangeRate();
            if ($rate > 0) {
                // tx is inverse
                $facture->multicurrency_tx = 1 / $rate;
            }
            $kursText = ksefIncomingKursText(ksefIncomingKursState($this, 0.0, is_numeric($facture->date ?? '') ? (int) $facture->date : (int) strtotime((string) ($facture->date ?? ''))), $code);
            if ($kursText !== '') {
                $this->warnings[] = $kursText;
            }
        }
        return '';
    }

    /**
     * @brief Store import error
     * @return int 0 not NEW/ERROR, -1 error
     */
    public function recordImportError()
    {
        $err = dol_trunc((string) $this->error, 2000, 'right', 'UTF-8', 1);
        $sql = "UPDATE " . MAIN_DB_PREFIX . $this->table_element . " SET import_status = '" . self::STATUS_ERROR . "', import_error = '" . $this->db->escape($err) . "'";
        $sql .= " WHERE rowid = " . (int) $this->id . " AND import_status IN ('" . self::STATUS_NEW . "', '" . self::STATUS_ERROR . "')";
        $res = $this->db->query($sql);
        if (!$res) {
            return -1;
        }
        if ((int) $this->db->affected_rows($res) !== 1) {
            return 0;
        }
        $this->import_status = self::STATUS_ERROR;
        $this->import_error = $err;
        return 1;
    }

    /**
     * @brief Null or blank
     * @param mixed $val
     * @return bool
     */
    private function isNullOrBlank($val)
    {
        return $val === null || $val === '';
    }

    /**
     * @brief Fetch filtered records
     * @param $filters
     * @param $sortfield
     * @param $sortorder ASC/DESC
     * @param $limit
     * @param $offset
     * @return array|int -1 on error
     */
    public function fetchAll($filters = array(), $sortfield = 'i.invoice_date', $sortorder = 'DESC', $limit = 0, $offset = 0)
    {
        global $conf;

        if (!in_array($sortfield, self::SORT_FIELDS, true)) {
            $sortfield = 'i.invoice_date';
        }
        $sortorder = strtoupper($sortorder);
        if (!in_array($sortorder, array('ASC', 'DESC'))) {
            $sortorder = 'DESC';
        }

        $results = array();

        $sql = "SELECT i.* FROM " . MAIN_DB_PREFIX . $this->table_element . " as i";
        $sql .= " WHERE i.entity IN (" . getEntity($this->element) . ")";

        if (!empty($filters['ids'])) {
            $sql .= " AND i.rowid IN (" . implode(',', array_map('intval', (array) $filters['ids'])) . ")";
        }

        if (!empty($filters['seller_nip'])) {
            $sql .= " AND i.seller_nip LIKE '%" . $this->db->escape($this->db->escapeforlike($filters['seller_nip'])) . "%'";
        }
        if (!empty($filters['seller_name'])) {
            $sql .= " AND i.seller_name LIKE '%" . $this->db->escape($this->db->escapeforlike($filters['seller_name'])) . "%'";
        }
        if (!empty($filters['invoice_number'])) {
            $sql .= " AND i.invoice_number LIKE '%" . $this->db->escape($this->db->escapeforlike($filters['invoice_number'])) . "%'";
        }
        if (!empty($filters['ksef_number'])) {
            $sql .= " AND i.ksef_number LIKE '%" . $this->db->escape($this->db->escapeforlike($filters['ksef_number'])) . "%'";
        }
        if (!empty($filters['import_status'])) {
            $sql .= $this->statusFilterSql($filters['import_status']);
        }
        if (!empty($filters['environment'])) {
            $sql .= " AND i.environment = '" . $this->db->escape($filters['environment']) . "'";
        }
        if (!empty($filters['invoice_date_start'])) {
            $sql .= " AND i.invoice_date >= " . (int)$filters['invoice_date_start'];
        }
        if (!empty($filters['invoice_date_end'])) {
            $sql .= " AND i.invoice_date <= " . (int)$filters['invoice_date_end'];
        }

        $sql .= " ORDER BY " . $sortfield . " " . $sortorder . ($sortfield !== 'i.rowid' ? ", i.rowid " . $sortorder : '');

        if ($limit > 0) {
            $sql .= " LIMIT " . (int)$limit;
            if ($offset > 0) {
                $sql .= " OFFSET " . (int)$offset;
            }
        }

        dol_syslog("KsefIncoming::fetchAll", LOG_DEBUG);
        $resql = $this->db->query($sql);

        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                // KsefSales reuses
                $record = new static($this->db);
                $record->setFromRow($obj);
                $results[] = $record;
            }
            $this->db->free($resql);
            return $results;
        } else {
            $this->error = "Error " . $this->db->lasterror();
            return -1;
        }
    }

    /**
     * @brief Count filtered records
     * @param $filters
     * @return int -1 on error
     */
    public function countAll($filters = array())
    {
        $sql = "SELECT COUNT(*) as total FROM " . MAIN_DB_PREFIX . $this->table_element . " as i";
        $sql .= " WHERE i.entity IN (" . getEntity($this->element) . ")";

        if (!empty($filters['seller_nip'])) {
            $sql .= " AND i.seller_nip LIKE '%" . $this->db->escape($this->db->escapeforlike($filters['seller_nip'])) . "%'";
        }
        if (!empty($filters['seller_name'])) {
            $sql .= " AND i.seller_name LIKE '%" . $this->db->escape($this->db->escapeforlike($filters['seller_name'])) . "%'";
        }
        if (!empty($filters['invoice_number'])) {
            $sql .= " AND i.invoice_number LIKE '%" . $this->db->escape($this->db->escapeforlike($filters['invoice_number'])) . "%'";
        }
        if (!empty($filters['ksef_number'])) {
            $sql .= " AND i.ksef_number LIKE '%" . $this->db->escape($this->db->escapeforlike($filters['ksef_number'])) . "%'";
        }
        if (!empty($filters['import_status'])) {
            $sql .= $this->statusFilterSql($filters['import_status']);
        }
        if (!empty($filters['environment'])) {
            $sql .= " AND i.environment = '" . $this->db->escape($filters['environment']) . "'";
        }
        if (!empty($filters['invoice_date_start'])) {
            $sql .= " AND i.invoice_date >= " . (int)$filters['invoice_date_start'];
        }
        if (!empty($filters['invoice_date_end'])) {
            $sql .= " AND i.invoice_date <= " . (int)$filters['invoice_date_end'];
        }

        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            $this->db->free($resql);
            return (int)$obj->total;
        }
        return -1;
    }

    /**
     * @brief Status filter SQL
     * @param string $status
     * @return string
     */
    private function statusFilterSql($status)
    {
        $unsupported = "i.import_error LIKE '" . $this->db->escape(self::UNSUPPORTED_FORM_PREFIX) . "%'";
        if ($status === self::STATUS_FETCH_ERROR) {
            return " AND i.import_status = '" . self::STATUS_FETCH_ERROR . "' AND (i.import_error IS NULL OR NOT " . $unsupported . ")";
        }
        if ($status === 'FETCH_ERROR_UNSUPPORTED') {
            return " AND i.import_status = '" . self::STATUS_FETCH_ERROR . "' AND " . $unsupported;
        }
        return " AND i.import_status = '" . $this->db->escape($status) . "'";
    }

    /**
     * @brief Status counts
     * @param string $environment
     * @return array Counts by status
     */
    public function countForStats($environment)
    {
        $out = array('total' => 0);
        $base = "SELECT COUNT(*) as n FROM " . MAIN_DB_PREFIX . $this->table_element . " as i WHERE i.entity IN (" . getEntity($this->element) . ")"
            . " AND i.environment = '" . $this->db->escape($environment) . "'";
        foreach (array('total' => '', self::STATUS_NEW => self::STATUS_NEW, self::STATUS_IMPORTED => self::STATUS_IMPORTED, self::STATUS_SKIPPED => self::STATUS_SKIPPED,
            self::STATUS_ERROR => self::STATUS_ERROR, self::STATUS_FETCH_ERROR => self::STATUS_FETCH_ERROR) as $key => $status) {
            $resql = $this->db->query($base . ($status !== '' ? $this->statusFilterSql($status) : ''));
            $obj = $resql ? $this->db->fetch_object($resql) : null;
            $out[$key] = $obj ? (int) $obj->n : 0;
        }
        return $out;
    }

    /**
     * @brief Count fetch errors
     * @param string $environment
     * @return int
     */
    public function countFetchErrors($environment)
    {
        $sql = "SELECT COUNT(*) as total FROM " . MAIN_DB_PREFIX . $this->table_element . " as i";
        $sql .= " WHERE i.entity IN (" . getEntity($this->element) . ")";
        $sql .= " AND i.environment = '" . $this->db->escape($environment) . "'";
        $sql .= $this->statusFilterSql(self::STATUS_FETCH_ERROR);
        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            $this->db->free($resql);
            return (int) $obj->total;
        }
        return 0;
    }

    /**
     * @brief Recent statistics
     * @param $days Lookback days
     * @param string $environment '' for all
     * @return array
     */
    public function getStatistics($days = 30, $environment = '')
    {
        $stats = array(
            'total' => 0,
            'new' => 0,
            'imported' => 0,
            'error' => 0,
            'fetch_error' => 0,
        );

        $since = dol_now() - ($days * 86400);

        $sql = "SELECT import_status, COUNT(*) as count FROM " . MAIN_DB_PREFIX . $this->table_element;
        $sql .= " WHERE fetch_date > " . (int)$since;
        $sql .= " AND entity IN (" . getEntity($this->element) . ")";
        if ((string) $environment !== '') {
            $sql .= " AND environment = '" . $this->db->escape($environment) . "'";
        }
        $sql .= " GROUP BY import_status";

        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $count = (int) $obj->count;
                $stats['total'] += $count;
                if ($obj->import_status == self::STATUS_NEW) {
                    $stats['new'] = $count;
                } elseif ($obj->import_status == self::STATUS_IMPORTED) {
                    $stats['imported'] = $count;
                } elseif ($obj->import_status == self::STATUS_ERROR) {
                    $stats['error'] = $count;
                } elseif ($obj->import_status == self::STATUS_FETCH_ERROR) {
                    $stats['fetch_error'] = $count;
                }
            }
            $this->db->free($resql);
        }

        return $stats;
    }

    /**
     * @brief Record link
     * @param $withpicto
     * @param $option
     * @param $notooltip
     * @param $morecss
     * @param $save_lastsearch_value -1 auto
     * @return string HTML
     */
    public function getNomUrl($withpicto = 0, $option = '', $notooltip = 0, $morecss = '', $save_lastsearch_value = -1)
    {
        global $conf, $langs, $hookmanager;

        if (!empty($conf->dol_no_mouse_hover)) {
            $notooltip = 1;
        }

        $result = '';

        $label = img_picto('', $this->picto) . ' <u class="paddingrightonly">' . $langs->trans($this->cardLabel) . '</u>';
        if (isset($this->import_status)) {
            $label .= ' ' . $this->getLibStatut(5);
        }
        $label .= '<br>';
        $label .= '<b>' . $langs->trans('KSEF_Number') . ':</b> ' . $this->ksef_number;
        if (!empty($this->invoice_number)) {
            $label .= '<br><b>' . $langs->trans('RefSupplierBill') . ':</b> ' . $this->invoice_number;
        }
        $partyName = $this->cardPage === '/ksef/sales_card.php' ? $this->buyer_name : $this->seller_name;
        if (!empty($partyName)) {
            $label .= '<br><b>' . $langs->trans($this->cardPage === '/ksef/sales_card.php' ? 'Customer' : 'Supplier') . ':</b> ' . $partyName;
        }
        if (!empty($this->total_gross)) {
            $label .= '<br><b>' . $langs->trans('AmountTTC') . ':</b> ' . price($this->total_gross, 0, $langs, 1, -1, -1, $this->currency);
        }

        $url = dol_buildpath($this->cardPage, 1) . '?id=' . $this->id;

        if ($option != 'nolink') {
            $add_save_lastsearch_values = ($save_lastsearch_value == 1 ? 1 : 0);
            if ($save_lastsearch_value == -1 && isset($_SERVER["PHP_SELF"]) && preg_match('/list\.php/', $_SERVER["PHP_SELF"])) {
                $add_save_lastsearch_values = 1;
            }
            if ($add_save_lastsearch_values) {
                $url .= '&save_lastsearch_values=1';
            }
        }

        $linkclose = '';
        if (empty($notooltip)) {
            if (getDolGlobalString('MAIN_OPTIMIZEFORTEXTBROWSER')) {
                $label = $langs->trans("KSEF_ShowKsefIncoming");
                $linkclose .= ' alt="' . dol_escape_htmltag($label, 1) . '"';
            }
            $linkclose .= ' title="' . dol_escape_htmltag($label, 1) . '"';
            $linkclose .= ' class="classfortooltip' . ($morecss ? ' ' . $morecss : '') . '"';
        } else {
            $linkclose = ($morecss ? ' class="' . $morecss . '"' : '');
        }

        $linkstart = '<a href="' . $url . '"';
        $linkstart .= $linkclose . '>';
        $linkend = '</a>';

        $result .= $linkstart;

        if ($withpicto) {
            $result .= img_object(($notooltip ? '' : $label), ($this->picto ? $this->picto : 'generic'), ($notooltip ? (($withpicto != 2) ? 'class="paddingright"' : '') : 'class="' . (($withpicto != 2) ? 'paddingright ' : '') . 'classfortooltip"'), 0, 0, $notooltip ? 0 : 1);
        }

        if ($withpicto != 2) {
            $result .= $this->ksef_number;
        }

        $result .= $linkend;

        global $action;
        $hookmanager->initHooks(array('ksef_incomingdao'));
        $parameters = array('id' => $this->id, 'getnomurl' => &$result);
        $reshook = $hookmanager->executeHooks('getNomUrl', $parameters, $this, $action);
        if ($reshook > 0) {
            $result = $hookmanager->resPrint;
        } else {
            $result .= $hookmanager->resPrint;
        }

        return $result;
    }

    /**
     * @brief Status label
     * @param $mode Label mode 0-6
     * @return string
     */
    public function getLibStatut($mode = 0)
    {
        return $this->LibStatut($this->import_status, $mode);
    }

    /**
     * @brief Label for status
     * @param $status
     * @param $mode Label mode 0-6
     * @return string
     */
    public function LibStatut($status, $mode = 0)
    {
        global $langs;
        $langs->load("ksef@ksef");

        $statusType = 'status0';
        $statusLabel = $status;
        $statusLabelShort = $status;
        $params = array();
        $map = ksefIncomingStatusMap();
        if (isset($map[$status])) {
            $statusType = $map[$status][0];
            $statusLabel = $statusLabelShort = $langs->transnoentitiesnoconv($map[$status][1]);
            $params = array('badgeParams' => array('css' => ksefToneClass($map[$status][2])));
        }

        return dolGetStatus($statusLabel, $statusLabelShort, '', $statusType, $mode, '', $params);
    }

    /**
     * @brief Party match WHERE
     * @param string $nip NIP digits
     * @param string $vatId EU VAT ID
     * @param string $alias
     * @return string No leading AND
     */
    protected function buildThirdPartyMatchWhere($nip, $vatId, $alias = 's')
    {
        $vatDigits = !empty($vatId) ? preg_replace('/[^0-9]/', '', $vatId) : '';
        $nipField = ksefGetFieldName('NIP');
        $nipColumn = ksefFieldToColumn($nipField);

        $conditions = array();
        if (!empty($nip)) {
            $escapedNip = $this->db->escape($nip);
            if (!empty($nipColumn)) {
                $conditions[] = "REPLACE(REPLACE({$alias}." . $this->db->escape($nipColumn) . ", '-', ''), ' ', '') LIKE '%" . $escapedNip . "%'";
            }
            $otherFields = array('siren', 'siret', 'ape', 'idprof4', 'idprof5', 'idprof6', 'tva_intra');
            foreach ($otherFields as $field) {
                if ($field !== $nipColumn) {
                    $conditions[] = "REPLACE(REPLACE({$alias}." . $field . ", '-', ''), ' ', '') LIKE '%" . $escapedNip . "%'";
                }
            }
        }
        if (!empty($vatDigits) && $vatDigits !== $nip) {
            $escapedVat = $this->db->escape($vatDigits);
            $conditions[] = "REPLACE(REPLACE({$alias}.tva_intra, '-', ''), ' ', '') LIKE '%" . $escapedVat . "%'";
        }

        return implode(" OR ", $conditions);
    }

    /**
     * @brief Party match SQL
     * @param string $nip NIP digits
     * @param string $vatId EU VAT ID
     * @param string $roleSql Role condition
     * @param string $preferSql Extra ORDER BY
     * @return string
     */
    protected function buildThirdPartyMatchSQL($nip, $vatId, $roleSql = '1 = 1', $preferSql = '')
    {
        $vatDigits = !empty($vatId) ? preg_replace('/[^0-9]/', '', $vatId) : '';
        $nipField = ksefGetFieldName('NIP');
        $nipColumn = ksefFieldToColumn($nipField);

        $sql = "SELECT s.rowid FROM " . MAIN_DB_PREFIX . "societe s";
        $sql .= " WHERE " . $roleSql;
        $sql .= " AND s.entity IN (" . getEntity('societe') . ")";
        $sql .= " AND (" . $this->buildThirdPartyMatchWhere($nip, $vatId) . ")";

        $orderCases = array();
        if (!empty($nip)) {
            $escapedNip = $this->db->escape($nip);
            if (!empty($nipColumn)) {
                $orderCases[] = "CASE WHEN REPLACE(REPLACE(s." . $this->db->escape($nipColumn) . ", '-', ''), ' ', '') LIKE '%" . $escapedNip . "%' THEN 0";
            } else {
                $orderCases[] = "CASE WHEN 1=0 THEN 0";
            }
            $otherFields = array('siren', 'siret', 'ape', 'idprof4', 'idprof5', 'idprof6');
            foreach ($otherFields as $field) {
                if ($field !== $nipColumn) {
                    $orderCases[] = "WHEN REPLACE(REPLACE(s." . $field . ", '-', ''), ' ', '') LIKE '%" . $escapedNip . "%' THEN 1";
                }
            }
            if ($nipField !== 'tva_intra') {
                $orderCases[] = "WHEN REPLACE(REPLACE(s.tva_intra, '-', ''), ' ', '') LIKE '%" . $escapedNip . "%' THEN 2";
            }
        }
        if (!empty($vatDigits)) {
            $escapedVat = $this->db->escape($vatDigits);
            if (empty($orderCases)) {
                $orderCases[] = "CASE WHEN REPLACE(REPLACE(s.tva_intra, '-', ''), ' ', '') LIKE '%" . $escapedVat . "%' THEN " . (empty($nip) ? '0' : '2');
            } else {
                $orderCases[] = "WHEN REPLACE(REPLACE(s.tva_intra, '-', ''), ' ', '') LIKE '%" . $escapedVat . "%' THEN " . (empty($nip) ? '0' : '2');
            }
        }
        if (empty($orderCases)) {
            $orderCases[] = "CASE WHEN 1=0 THEN 0";
        }
        $orderCases[] = "ELSE 9 END";

        $sql .= " ORDER BY " . ($preferSql !== '' ? $preferSql . ", " : '') . implode(" ", $orderCases) . ", s.rowid ASC";
        $sql .= " LIMIT 1";

        return $sql;
    }

    /**
     * @brief Party identifiers
     * @return array NIP digits, VAT ID
     */
    public function partyIdentifiers()
    {
        return array(preg_replace('/[^0-9]/', '', (string) $this->seller_nip), (string) ($this->seller_vat_id ?? ''));
    }

    /**
     * @brief Party identity state
     * @param int $socid
     * @return string match, mismatch, no_id, n/a
     */
    public function partyIdentityState($socid)
    {
        list($nip, $vatId) = $this->partyIdentifiers();
        if ((int) $socid <= 0 || ($nip === '' && $vatId === '')) {
            return 'n/a';
        }
        $where = $this->buildThirdPartyMatchWhere($nip, $vatId, 's');
        $res = $this->db->query("SELECT s.rowid FROM " . MAIN_DB_PREFIX . "societe s WHERE s.rowid = " . (int) $socid . ($where !== '' ? " AND (" . $where . ")" : ''));
        if ($res && $this->db->num_rows($res) > 0) {
            return 'match';
        }
        $soc = new Societe($this->db);
        if ($soc->fetch((int) $socid) <= 0) {
            return 'n/a';
        }
        $own = trim((string) ksefGetIdentifierField($soc, 'NIP')) . trim((string) $soc->tva_intra);
        return $own === '' ? 'no_id' : 'mismatch';
    }

    /**
     * @brief Find seller party
     * @return int 0 if none
     */
    public function findMatchingThirdParty()
    {
        if (empty($this->seller_nip) && empty($this->seller_vat_id)) {
            return 0;
        }

        $nip = preg_replace('/[^0-9]/', '', $this->seller_nip);
        $vatId = !empty($this->seller_vat_id) ? $this->seller_vat_id : '';

        if (empty($nip) && empty($vatId)) {
            return 0;
        }

        $sql = $this->buildThirdPartyMatchSQL($nip, $vatId, '1 = 1', 's.fournisseur DESC');

        dol_syslog("KsefIncoming::findMatchingThirdParty nip=" . $nip . " vatId=" . $vatId, LOG_DEBUG);
        $resql = $this->db->query($sql);

        if ($resql) {
            if ($this->db->num_rows($resql) > 0) {
                $obj = $this->db->fetch_object($resql);
                $this->db->free($resql);
                return (int)$obj->rowid;
            }
            $this->db->free($resql);
        }

        return 0;
    }

    /**
     * @brief Auto-match line products
     * @param array $lines
     * @param int $socid Supplier, 0 skips
     * @return array Matches by line_num
     */
    public function autoMatchLineProducts($lines, $socid = 0)
    {
        $matches = array();

        if (empty($lines)) {
            return $matches;
        }

        foreach ($lines as $line) {
            $lineNum = $line['line_num'] ?? null;
            if ($lineNum === null || !self::isProductLine($line)) {
                continue;
            }

            $indeks = !empty($line['indeks']) ? trim($line['indeks']) : '';
            $gtin = !empty($line['gtin']) ? trim($line['gtin']) : '';

            if (!empty($indeks)) {
                // supplier ref first
                $resql = false;
                if ((int) $socid > 0) {
                    $sql = "SELECT pfp.fk_product, pfp.ref_fourn, p.ref, p.label FROM " . MAIN_DB_PREFIX . "product_fournisseur_price as pfp";
                    $sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "product as p ON p.rowid = pfp.fk_product";
                    $sql .= " WHERE pfp.ref_fourn = '" . $this->db->escape($indeks) . "'";
                    $sql .= " AND pfp.fk_soc = " . (int) $socid;
                    $sql .= " AND pfp.entity IN (" . getEntity('product') . ")";
                    $sql .= " LIMIT 1";
                    $resql = $this->db->query($sql);
                }
                if ($resql && $this->db->num_rows($resql) > 0) {
                    $obj = $this->db->fetch_object($resql);
                    $matches[$lineNum] = array(
                        'product_id' => (int)$obj->fk_product,
                        'match_method' => 'supplier_ref',
                        'product_ref' => $obj->ref,
                        'product_label' => $obj->label,
                    );
                    $this->db->free($resql);
                    continue;
                }
                if ($resql) {
                    $this->db->free($resql);
                }

                $sql = "SELECT rowid, ref, label FROM " . MAIN_DB_PREFIX . "product";
                $sql .= " WHERE ref = '" . $this->db->escape($indeks) . "'";
                $sql .= " AND entity IN (" . getEntity('product') . ")";
                $sql .= " LIMIT 1";

                $resql = $this->db->query($sql);
                if ($resql && $this->db->num_rows($resql) > 0) {
                    $obj = $this->db->fetch_object($resql);
                    $matches[$lineNum] = array(
                        'product_id' => (int)$obj->rowid,
                        'match_method' => 'product_ref',
                        'product_ref' => $obj->ref,
                        'product_label' => $obj->label,
                    );
                    $this->db->free($resql);
                    continue;
                }
                if ($resql) {
                    $this->db->free($resql);
                }
            }

            if (!empty($gtin)) {
                $sql = "SELECT rowid, ref, label FROM " . MAIN_DB_PREFIX . "product";
                $sql .= " WHERE barcode = '" . $this->db->escape($gtin) . "'";
                $sql .= " AND entity IN (" . getEntity('product') . ")";
                $sql .= " LIMIT 1";

                $resql = $this->db->query($sql);
                if ($resql && $this->db->num_rows($resql) > 0) {
                    $obj = $this->db->fetch_object($resql);
                    $matches[$lineNum] = array(
                        'product_id' => (int)$obj->rowid,
                        'match_method' => 'barcode',
                        'product_ref' => $obj->ref,
                        'product_label' => $obj->label,
                    );
                    $this->db->free($resql);
                    continue;
                }
                if ($resql) {
                    $this->db->free($resql);
                }
            }
        }

        return $matches;
    }

    /**
     * @brief Import supplier invoice
     * @param User $user
     * @param int $socid Supplier id
     * @param array $lineProductMap line_num => fk_product
     * @param int $correctionSourceId Cited invoice id
     * @param string $upwardMode Ignored
     * @param array $options warehouse_id
     * @return int Invoice id, <0 on error
     */
    public function importToDolibarr($user, $socid, $lineProductMap = array(), $correctionSourceId = 0, $upwardMode = '', $options = array())
    {
        global $conf, $langs;

        $this->warnings = array();
        $this->validated = false;
        $this->error = '';
        $this->errors = array();

        if (!ksefUserCan($user, 'create_supplier_invoice')) {
            $this->error = $langs->trans('NotEnoughPermissions');
            return -1;
        }

        if ($socid <= 0) {
            $this->error = $langs->trans('KSEF_ImportErrorNoSupplier');
            return -1;
        }

        if ($this->import_status === 'IMPORTED') {
            $this->error = $langs->trans('KSEF_ImportErrorAlreadyImported');
            return -2;
        }

        if ($this->import_status === self::STATUS_FETCH_ERROR) {
            $this->error = $langs->trans('KSEF_FetchErrorNotImportable');
            return -1;
        }
        if ($this->import_status === self::STATUS_SKIPPED) {
            $this->error = $langs->trans('KSEF_InvoiceSkipped');
            return -1;
        }

        require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
        require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
        require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';

        // security: validate ids
        $soc = ksefVisibleSociete($this->db, $socid);
        if (!$soc) {
            $this->error = $langs->trans('KSEF_ImportErrorNoSupplier');
            return -1;
        }
        if ((int) $soc->fournisseur !== 1) {
            if ($this->partyIdentityState($soc->id) !== 'match') {
                $this->error = $langs->trans('KSEF_ImportErrorNotASupplier', $soc->name);
                return -1;
            }
            // outside import transaction
            $this->ensureSupplierRole($user, $soc);
        }
        $lineProductMap = $this->knownProducts($lineProductMap);

        $this->db->begin();

        $lockSql = "SELECT import_status FROM " . MAIN_DB_PREFIX . $this->table_element;
        $lockSql .= " WHERE rowid = " . (int)$this->id . " FOR UPDATE";
        $lockRes = $this->db->query($lockSql);
        if ($lockRes) {
            $lockObj = $this->db->fetch_object($lockRes);
            if ($lockObj && $lockObj->import_status === 'IMPORTED') {
                $this->error = $langs->trans('KSEF_ImportErrorAlreadyImported');
                $this->db->rollback();
                return -2;
            }
            if ($lockObj && $lockObj->import_status === self::STATUS_FETCH_ERROR) {
                $this->error = $langs->trans('KSEF_FetchErrorNotImportable');
                $this->db->rollback();
                return -1;
            }
            if ($lockObj && $lockObj->import_status === self::STATUS_SKIPPED) {
                $this->error = $langs->trans('KSEF_InvoiceSkipped');
                $this->db->rollback();
                return -1;
            }
        }

        $korektaSource = null;

        $facture = new FactureFournisseur($this->db);
        $facture->fk_soc = $socid;
        $facture->socid = $socid;
        $facture->ref_supplier = $this->invoice_number;
        $facture->date = $this->invoice_date ? $this->invoice_date : dol_now();
        // old rows: first
        $earliestDue = self::earliestDueDate($this->fa3_xml);
        $dueDate = $earliestDue !== null ? $earliestDue : $this->payment_due_date;
        $facture->date_echeance = $dueDate ? $dueDate : null;
        $facture->note_public = 'KSeF: ' . $this->ksef_number;

        if (self::isCorrectionType($this->invoice_type)) {
            $korektaSource = $this->resolveKorektaSource($correctionSourceId, $socid);
            if (!is_object($korektaSource)) {
                $this->error = $korektaSource;
                $this->import_status = self::STATUS_ERROR;
                $this->import_error = $this->error;
                $this->db->rollback();
                return -6;
            }
            $facture->type = FactureFournisseur::TYPE_REPLACEMENT;
            $facture->fk_facture_source = $korektaSource->id;
        } elseif ($this->invoice_type === 'ZAL') {
            $facture->type = FactureFournisseur::TYPE_DEPOSIT;
            $facture->cond_reglement_id = 1;
        } else {
            $facture->type = FactureFournisseur::TYPE_STANDARD;
        }

        $isMulticurrency = (!empty($this->currency) && $this->currency !== 'PLN');
        $currencyRefusal = $this->importCurrencyRefusal($facture);
        if ($currencyRefusal !== '') {
            $this->error = $currencyRefusal;
            $this->import_status = self::STATUS_ERROR;
            $this->import_error = $this->error;
            $this->db->rollback();
            return -1;
        }

        if ($korektaSource) {
            $facture->multicurrency_code = $korektaSource->multicurrency_code;
            $facture->multicurrency_tx = $korektaSource->multicurrency_tx;
            $isMulticurrency = !empty($korektaSource->multicurrency_code) && $korektaSource->multicurrency_code !== $conf->currency;
            // art. 31b: deposit
            $srcRate = (float) $korektaSource->multicurrency_tx > 0 ? 1 / (float) $korektaSource->multicurrency_tx : 0;
            if ($this->invoice_type === 'KOR_ZAL' && $isMulticurrency && $srcRate > 0) {
                $parsedRate = (new FA3Parser($this->db))->parse((string) $this->fa3_xml);
                $rate = ksefParsedKurs($parsedRate);
                $differs = $rate > 0 && abs($rate - $srcRate) >= 0.00005;
                $before = (float) ($parsedRate['exchange_rate']['before_rate'] ?? 0);
                if (!$differs && $before > 0 && abs($before - $srcRate) >= 0.00005) {
                    $differs = true;
                    $rate = $before;
                }
                // rounded: unusable rate
                foreach ($rate > 0 ? array() : (array) ($parsedRate['vat_summary'] ?? array()) as $r) {
                    if (isset($r['vat_pln']) && $r['vat_pln'] !== null && (float) $r['vat'] != 0 && abs((float) $r['vat_pln'] - round((float) $r['vat'] * $srcRate, 2)) > 0.015) {
                        $differs = true;
                    }
                }
                if ($differs) {
                    $this->warnings[] = $langs->transnoentitiesnoconv('KSEF_ImportKorZalRateDiffers', price($rate > 0 ? $rate : $this->getExchangeRate(), 0, $langs, 0, -1, 4), $korektaSource->ref, price($srcRate, 0, $langs, 0, -1, 4));
                }
            }
        }

        if (!empty($this->payment_method)) {
            $facture->mode_reglement_id = $this->mapKsefPaymentMethod($this->payment_method);
        }

        $factureId = $facture->create($user);
        if ($factureId <= 0) {
            $this->error = $facture->error;
            if ($this->db->errno() == 'DB_ERROR_RECORD_ALREADY_EXISTS' || $facture->error === $langs->trans('ErrorRefAlreadyExists')) {
                $this->error = $langs->transnoentitiesnoconv('KSEF_ImportErrorDuplicateRefSupplier', $facture->ref_supplier);
            }
            $this->errors = $facture->errors;
            $this->import_status = self::STATUS_ERROR;
            $this->import_error = $this->error;
            $this->db->rollback();
            return -3;
        }

        $reParsedVatSummary = null;
        $this->deposit_socid = (int) $socid;
        $lines = $this->loadImportLines(false, $reParsedVatSummary);
        $prepared = $this->prepareImportLines($lines, $lineProductMap, false, $isMulticurrency, $reParsedVatSummary, 'STOCK_CALCULATE_ON_SUPPLIER_BILL');
        if ($prepared === false) {
            $this->import_status = self::STATUS_ERROR;
            $this->import_error = $this->error;
            $this->db->rollback();
            return -4;
        }
        $error = 0;

        foreach ($prepared as $pl) {
            $result = $facture->addline(
                $pl['desc'],
                $pl['pu'],
                $pl['txtva'],
                0,
                0,
                $pl['qty'],
                $pl['fk_product'],
                0,
                0,
                0,
                0,
                0,
                $pl['price_base'],
                $pl['type'],
                -1,
                0,
                array(),
                null,
                0,
                $pl['pu_devise'],
                $pl['ref']
            );

            if ($result <= 0) {
                $error++;
                $this->error = $facture->error;
                $this->errors = $facture->errors;
                break;
            }
        }

        if (!$error && $this->invoice_type === 'ROZ' && $this->linkSupplierDeposits($user, $facture, $socid) < 0) {
            $error++;
        }
        if (!$error && $this->invoice_type === 'ZAL' && $this->coverEarlierSettlements($facture->id, $socid) < 0) {
            $error++;
        }

        if (!$error && $korektaSource && $this->rebuildKorektaLines($facture, $korektaSource, $lineProductMap, $isMulticurrency) < 0) {
            $error++;
        }
        $below = !$error && $korektaSource && $this->invoice_type === 'KOR_ZAL' ? ksefDepositKorektaBelowZero($this->db, (int) $facture->id, 'invoice_supplier') : null;
        if ($below) {
            $this->error = $langs->transnoentitiesnoconv('KSEF_ImportErrorKorZalBelowZero', $below['ref'], $below['label']);
            $error++;
        }
        if (!$error && $korektaSource && $this->linkCitedInvoices($facture) < 0) {
            $error++;
        }
        if (!$error && $korektaSource && $isMulticurrency && $reParsedVatSummary && ksefKorektaPlnFromXml($this->db, $facture, $reParsedVatSummary) < 0) {
            $this->error = ksefDbErrorForUser($user, 'KSEF_DbErrorGeneric', $this->db->lasterror());
            $error++;
        }

        if ($error) {
            $this->import_status = self::STATUS_ERROR;
            $this->import_error = $this->error;
            $this->db->rollback();
            return -4;
        }

        // before totals check
        if ($isMulticurrency && !$korektaSource && ksefDepositLinesAtInvoiceRate($this->db, (int) $facture->id, 'invoice_supplier') < 0) {
            $this->error = ksefDbErrorForUser($user, 'KSEF_DbErrorGeneric', $this->db->lasterror());
            $this->import_status = self::STATUS_ERROR;
            $this->import_error = $this->error;
            $this->db->rollback();
            return -4;
        }

        $check = new FactureFournisseur($this->db);
        $check->fetch($facture->id);
        $settled = ksefApplyIncomingVatRounding($this, $check);
        if ($settled['error'] !== '') {
            $this->error = ksefDbErrorForUser($user, 'KSEF_DbErrorGeneric', $settled['error']);
            $this->import_status = self::STATUS_ERROR;
            $this->import_error = $this->error;
            $this->db->rollback();
            return -4;
        }
        if ($settled['note'] !== '') {
            $this->warnings[] = $settled['note'];
        }
        if (ksefSyncBaseMulticurrencyTotals($this->db, $check) > 0) {
            $check->fetch($facture->id);
        }
        $plnSet = ksefIncomingPlnFromXml($this->db, $check, $this);
        if ($plnSet['result'] < 0) {
            $this->error = ksefDbErrorForUser($user, 'KSEF_DbErrorGeneric', $plnSet['error']);
            $this->import_status = self::STATUS_ERROR;
            $this->import_error = $this->error;
            $this->db->rollback();
            return -4;
        }
        if ($plnSet['result'] > 0) {
            $check->fetch($facture->id);
        }
        $sellerPln = ksefIncomingSellerPlnText(ksefIncomingSellerPlnCheck($this), strtoupper((string) $this->currency));
        if ($sellerPln !== '') {
            $this->warnings[] = $sellerPln;
        }
        $cmp = ksefCompareIncomingTotals($this, $check, true);
        if (!$cmp['ok'] && !$cmp['skipped']) {
            $this->warnings[] = ksefIncomingTotalsWarning($cmp);
        }
        if ($this->partyIdentityState($socid) === 'mismatch') {
            $this->warnings[] = $langs->transnoentitiesnoconv('KSEF_PartyIdMismatch');
        }
        $stated = $this->statedBeforeCheck((int) $facture->id);
        if ($stated !== '') {
            $this->warnings[] = $stated;
        }

        $facture->array_options['options_ksef_number'] = $this->ksef_number;
        $facture->array_options['options_ksef_status'] = 'ACCEPTED';
        $facture->array_options['options_ksef_submission_date'] = $this->fa3_creation_date;
        $facture->array_options['options_ksef_sale_date'] = $this->sale_date;
        if ($facture->insertExtraFields() < 0) {
            $this->error = $facture->error ?: implode(', ', (array) $facture->errors);
            $this->db->rollback();
            return -5;
        }

        $plnOnly = !empty($cmp['pln']) && empty($cmp['other_differs']) && empty($cmp['oss_differs']);
        // no deposit realign
        $GLOBALS['ksef_importing_purchase_pln'] = $plnSet['kurs'] > 0 ? (int) $facture->id : 0;
        $this->validateImported($user, $facture, $check, !$cmp['ok'] && !$cmp['skipped'] && !$plnOnly, (int) ($options['warehouse_id'] ?? 0));
        $GLOBALS['ksef_importing_purchase_pln'] = 0;

        $this->import_status = self::STATUS_IMPORTED;
        $this->import_date = dol_now();
        $this->fk_facture_fourn = $facture->id;
        $this->import_error = null;

        $updResult = $this->update($user, 1);
        if ($updResult < 0) {
            $this->db->rollback();
            return -5;
        }

        $this->db->commit();
        $this->attachPdfToSupplierInvoice($facture);
        return $facture->id;
    }

    /**
     * @brief Validate imported draft
     * @param User $user
     * @param FactureFournisseur $facture Imported draft
     * @param FactureFournisseur $check Same, with lines
     * @param bool $totalsDiffer
     * @param int $warehouse 0: none
     * @return void
     */
    private function validateImported($user, $facture, $check, $totalsDiffer, $warehouse)
    {
        global $langs;

        if ($totalsDiffer) {
            $this->warnings[] = $langs->transnoentitiesnoconv('KSEF_ImportDraftTotals');
            return;
        }
        if ($warehouse > 0 && !ksefUserCan($user, 'stock_movement')) {
            $warehouse = 0;
            $this->warnings[] = $langs->transnoentitiesnoconv('KSEF_SalesNoStockRight');
        }
        if ($warehouse <= 0 && ksefSupplierPayNeedsWarehouse($check)) {
            $this->warnings[] = $langs->transnoentitiesnoconv('KSEF_ImportDraftNoWarehouse');
            return;
        }

        // nested rollback: savepoint
        $level = (int) $this->db->transaction_opened;
        $this->db->query("SAVEPOINT ksef_import_validate");
        $facture->fetch($facture->id);
        $GLOBALS['ksef_import_no_stock'] = $warehouse > 0 ? 0 : 1;
        $res = $facture->validate($user, '', $warehouse > 0 ? $warehouse : 0);
        unset($GLOBALS['ksef_import_no_stock']);
        if ($res > 0) {
            $this->db->query("RELEASE SAVEPOINT ksef_import_validate");
            $this->validated = true;
            return;
        }
        while ((int) $this->db->transaction_opened > $level) {
            $this->db->rollback();
        }
        $this->db->query("ROLLBACK TO SAVEPOINT ksef_import_validate");
        $err = $facture->error ? $facture->error : implode(', ', (array) $facture->errors);
        $this->warnings[] = $langs->transnoentitiesnoconv('KSEF_ImportDraftValidateFailed', ksefTranslateError($err));
        $facture->fetch($facture->id);
    }

    /**
     * @brief Cited invoices
     * @return array
     */
    public function citedInvoices()
    {
        $out = array();
        foreach ($this->resolveCorrectedInvoices() as $rc) {
            $out[] = array('label' => $rc['invoice_number'] !== '' ? $rc['invoice_number'] : $rc['ksef_number'],
                'key' => $rc['ksef_number'] !== '' ? $rc['ksef_number'] : $rc['invoice_number'], 'ksef' => $rc['ksef_number'], 'number' => $rc['invoice_number'],
                'date' => $rc['invoice_date'], 'invoice' => $rc['supplier_invoice'], 'record' => $rc['incoming']);
        }
        return $out;
    }

    /**
     * @brief Resolve korekta source
     * @param int $correctionSourceId 0: first found
     * @param int $socid 0: no check
     * @return FactureFournisseur|Facture|string Invoice, or refusal
     */
    public function resolveKorektaSource($correctionSourceId = 0, $socid = 0)
    {
        global $conf, $langs;
        $langs->load('ksef@ksef');
        dol_include_once('/ksef/lib/ksef.lib.php');
        $this->korekta_cited = 0;
        $this->korekta_linked = array();
        $cited = $this->citedInvoices();
        if (empty($cited)) {
            return $langs->transnoentitiesnoconv('KSEF_ImportErrorNoCorrectionsReference');
        }
        $found = array();
        $keys = array();
        foreach ($cited as $c) {
            if ($c['invoice']) {
                $found[(int) $c['invoice']->id] = $c['invoice'];
                $keys['i' . (int) $c['invoice']->id] = true;
            } else {
                $keys['c' . $c['key']] = true;
            }
        }
        $this->korekta_cited = count($keys);
        if (empty($found)) {
            $byHand = true;
            $absent = true;
            foreach ($cited as $c) {
                $byHand = $byHand && $c['ksef'] === '' && empty($c['record']);
                $absent = $absent && empty($c['record']) && ($c['ksef'] === '' || !empty($_SESSION['ksef_original_absent'][$c['ksef']]));
            }
            $key = $byHand ? 'KSEF_ImportErrorOriginalByHand' : ($absent ? 'KSEF_ImportErrorOriginalAbsent' : 'KSEF_ImportErrorOriginalNotImported');
            return $langs->transnoentitiesnoconv($key, $cited[0]['label'] ?: $this->corrected_invoice_number);
        }
        if ((int) $correctionSourceId > 0 && !isset($found[(int) $correctionSourceId])) {
            return $langs->transnoentitiesnoconv('KSEF_ImportErrorBadCorrectionSource');
        }
        $src = (int) $correctionSourceId > 0 ? $found[(int) $correctionSourceId] : reset($found);
        $kind = ksefInvoiceKind($src);
        if ($this->korekta_cited > 1) {
            $deposit = in_array($this->invoice_type, array('KOR_ZAL', 'KOR_ROZ'), true);
            foreach ($found as $inv) {
                $deposit = $deposit || ksefDepositRootId($this->db, $inv->id, $kind['element']) > 0;
            }
            if ($deposit) {
                return $langs->transnoentitiesnoconv('KSEF_ImportErrorKorektaCollectiveDeposit');
            }
        }
        $korCurrency = ksefNormCurrency($this->currency ?: 'PLN');
        require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
        foreach ($found as $inv) {
            if ((int) $socid > 0 && (int) $inv->socid !== (int) $socid) {
                $theirs = new Societe($this->db);
                $ours = new Societe($this->db);
                $theirs->fetch((int) $inv->socid);
                $ours->fetch((int) $socid);
                return $langs->transnoentitiesnoconv('KSEF_ImportErrorKorektaOtherParty', $inv->ref, $theirs->name, $ours->name);
            }
            $invCurrency = ksefNormCurrency($inv->multicurrency_code ?: $conf->currency);
            if ($invCurrency !== $korCurrency) {
                return $langs->transnoentitiesnoconv('KSEF_ImportErrorKorektaCurrency', $korCurrency, $inv->ref, $invCurrency);
            }
        }
        $chain = ksefBuildCorrectionChain($src, $this->db, 20, $this->correctsCitedNote($src));
        $tip = end($chain);
        $st = (int) (isset($tip->status) ? $tip->status : $tip->statut);
        if ($st === Facture::STATUS_DRAFT) {
            return $langs->transnoentitiesnoconv('KSEF_ImportErrorKorektaSourceDraft', $tip->ref);
        }
        if ($st === Facture::STATUS_ABANDONED && (string) $tip->close_code !== Facture::CLOSECODE_REPLACED) {
            return $langs->transnoentitiesnoconv('KSEF_ImportErrorKorektaAbandoned', $tip->ref);
        }
        $waiting = ksefFindReplacementOf($this->db, (int) $tip->id, Facture::STATUS_DRAFT . ',' . Facture::STATUS_ABANDONED, 0, false, $kind['element']);
        if ($waiting) {
            return $langs->transnoentitiesnoconv('KSEF_ImportErrorKorektaWaiting', $tip->ref, $waiting->ref);
        }
        unset($found[(int) $src->id]);
        $this->korekta_linked = $found;
        return $tip;
    }

    /**
     * @brief Corrects cited note
     * @param FactureFournisseur|Facture $src Cited invoice
     * @return bool
     */
    protected function correctsCitedNote($src)
    {
        return false;
    }

    /**
     * @brief Link cited invoices
     * @param CommonInvoice $facture Korekta
     * @return int -1 on error
     */
    protected function linkCitedInvoices($facture)
    {
        foreach ($this->korekta_linked as $inv) {
            if ($facture->add_object_linked($inv->element, (int) $inv->id) <= 0) {
                $this->error = $facture->error ?: $this->db->lasterror();
                return -1;
            }
        }
        return 1;
    }

    /**
     * @brief Rebuild korekta lines
     * @param FactureFournisseur $facture Korekta draft
     * @param FactureFournisseur $source Corrected invoice
     * @param array $lineProductMap line_num => product id
     * @param bool $isMulticurrency
     * @return int -1 on error
     */
    private function rebuildKorektaLines($facture, $source, $lineProductMap, $isMulticurrency)
    {
        $facture->fetch($facture->id);
        $have = (float) ($isMulticurrency ? $facture->multicurrency_total_ht : $facture->total_ht);
        $want = (float) ($isMulticurrency ? $source->multicurrency_total_ht : $source->total_ht) + (float) $this->total_net;
        if ($this->korekta_cited <= 1 && abs($have - $want) < 0.015) {
            return 1;
        }
        foreach ($facture->lines as $l) {
            if ($facture->deleteLine($l->id) < 0) {
                $this->error = $facture->error;
                return -1;
            }
        }
        if (empty($source->lines)) {
            $source->fetch_lines();
        }
        foreach ($source->lines as $l) {
            $pu = ksefLineExactUnitPrice($l, $isMulticurrency);
            $r = $facture->addline($l->desc, $isMulticurrency ? 0 : $pu, $l->tva_tx, $l->localtax1_tx, $l->localtax2_tx, $l->qty, $l->fk_product,
                $l->remise_percent, $l->date_start, $l->date_end, 0, $l->info_bits, 'HT', $l->product_type, -1, 0, array(), $l->fk_unit, 0,
                $isMulticurrency ? $pu : 0, $l->ref_supplier);
            if ($r <= 0) {
                $this->error = $facture->error;
                return -1;
            }
        }
        $vs = null;
        $diff = $this->prepareImportLines($this->loadImportLines(true, $vs), $lineProductMap, false, $isMulticurrency, $vs, 'STOCK_CALCULATE_ON_SUPPLIER_BILL');
        foreach ($diff as $pl) {
            $r = $facture->addline($pl['desc'], $pl['pu'], $pl['txtva'], 0, 0, $pl['qty'], $pl['fk_product'], 0, 0, 0, 0, 0, $pl['price_base'], $pl['type'],
                -1, 0, array(), null, 0, $pl['pu_devise'], $pl['ref']);
            if ($r <= 0) {
                $this->error = $facture->error;
                return -1;
            }
        }
        return 1;
    }

    /**
     * @brief Preview import lines
     * @return array
     */
    public function previewImportLines()
    {
        $vs = null;
        return $this->loadImportLines(true, $vs);
    }

    /**
     * @brief Line takes product
     * @param array $line
     * @return bool
     */
    public static function isProductLine($line)
    {
        return empty($line['deposit_line']) && empty($line['deposit_settlement']);
    }

    /**
     * @brief Load import lines
     * @param bool $difference Difference lines
     * @param array|null $vatSummary Out: VAT summary
     * @return array
     */
    public function loadImportLines($difference, &$vatSummary)
    {
        $vatSummary = null;
        $lines = null;
        $parsed = null;
        if (!empty($this->fa3_xml)) {
            $parser = new FA3Parser($this->db);
            $parsed = $parser->parse($this->fa3_xml);
            if ($parsed && (!empty($parsed['lines']) || (self::isCorrectionType($this->invoice_type) && !empty($parsed['lines_before'])))) {
                $lines = (array) ($parsed['lines'] ?? array());
                if (self::isCorrectionType($this->invoice_type)) {
                    if (!empty($parsed['vat_summary'])) {
                        $vatSummary = $parsed['vat_summary'];
                    }
                    if ($difference) {
                        $lines = self::applyCorrectionDiff($lines, $parsed['lines_before'] ?? array());
                    } else {
                        $lines = array_values($lines);
                        $pairs = empty($parsed['lines_before']) ? array() : self::pairCorrectionRows($lines, array_values($parsed['lines_before']));
                        foreach ($lines as $i => $line) {
                            $lines[$i] = isset($pairs[$i]) ? $line : self::markRebateRow($line);
                        }
                    }
                }
            }
        }
        if ($lines === null) {
            $lines = $this->getLineItems();
        }
        if (!empty($parsed)) {
            $lines = $this->depositImportLines($lines, $parsed, $difference);
        }
        return $lines;
    }

    /**
     * @brief Add deposit lines
     * @param array $lines
     * @param array $parsed Parser result
     * @param bool $difference Difference lines
     * @return array
     */
    private function depositImportLines($lines, $parsed, $difference = false)
    {
        global $langs;
        $langs->load('ksef@ksef');
        $type = (string) ($parsed['invoice']['type'] ?? $this->invoice_type);
        $summary = (array) ($parsed['vat_summary'] ?? array());
        if (in_array($type, array('ZAL', 'KOR_ZAL'), true) && ($parsed['lines_source'] ?? '') === 'order') {
            $out = array();
            $n = 1;
            foreach ($summary as $rate => $amt) {
                if ($rate === 'marza' || (float) ($amt['net'] ?? 0) == 0) {
                    continue;
                }
                $out[] = array('line_num' => $n++, 'description' => $langs->transnoentitiesnoconv('KSEF_DepositImportLine', is_numeric($rate) ? $rate . '%' : $rate),
                    'quantity' => 1.0, 'unit_price_net' => (float) $amt['net'], 'net_amount' => (float) $amt['net'], 'vat_rate' => (string) $rate,
                    'declared' => array('P_8B', 'P_9A', 'P_11'), 'unit' => null, 'indeks' => null, 'gtin' => null, 'deposit_line' => true);
            }
            return $out ?: $lines;
        }
        $korRoz = $type === 'KOR_ROZ' && $difference && !$this->isOutgoingRecord();
        if ($type !== 'ROZ' && !$korRoz) {
            return $lines;
        }
        $sums = $this->rozLineSums($lines);
        foreach ($korRoz ? array_keys($summary) : array() as $rate) {
            if ($rate !== 'marza') {
                $sums[(string) $rate] = $sums[(string) $rate] ?? 0;
            }
        }
        $refs = array();
        $linked = array();
        $allRefs = array();
        foreach ((array) ($parsed['deposits'] ?? array()) as $d) {
            $allRefs[] = $d['ksef_number'] !== '' ? $d['ksef_number'] : $d['number'];
        }
        if (!$this->isOutgoingRecord()) {
            foreach ($this->supplierDeposits($this->deposit_socid, $parsed) as $d) {
                if ($d['reason'] === '') {
                    foreach ($d['rates'] as $rate => $net) {
                        $linked[$rate] = ($linked[$rate] ?? 0) + $net;
                    }
                    continue;
                }
                $refs[] = $d['label'] . ($d['ref'] !== '' ? ' (' . $d['ref'] . ')' : '');
            }
        }
        if (!$refs) {
            $refs = $allRefs ?: array((string) $this->corrected_invoice_number);
        }
        $max = 0;
        foreach ($lines as $line) {
            $max = max($max, (int) ($line['line_num'] ?? 0));
        }
        foreach ($linked as $rate => $net) {
            $sums[$rate] = $sums[$rate] ?? 0;
        }
        foreach ($sums as $rate => $net) {
            $diff = round((float) ($summary[$rate]['net'] ?? 0) - $net + (float) ($linked[$rate] ?? 0), 2);
            if (abs($diff) < 0.005) {
                continue;
            }
            $lines[] = array('line_num' => ++$max, 'description' => $langs->transnoentitiesnoconv('KSEF_DepositSettledLine', implode(', ', $refs)),
                'quantity' => 1.0, 'unit_price_net' => $diff, 'net_amount' => $diff, 'vat_rate' => (string) $rate,
                'declared' => array('P_8B', 'P_9A', 'P_11'), 'unit' => null, 'indeks' => null, 'gtin' => null, 'deposit_settlement' => true, 'price_only' => true);
        }
        return $lines;
    }

    /**
     * @brief Cited supplier deposits
     * @param int $socid 0: any
     * @param array|null $parsed null: from fa3_xml
     * @return array
     */
    public function supplierDeposits($socid = 0, $parsed = null)
    {
        global $conf;
        $out = array();
        if ($this->isOutgoingRecord()) {
            return $out;
        }
        if ($parsed === null) {
            if (empty($this->fa3_xml)) {
                return $out;
            }
            $parsed = (new FA3Parser($this->db))->parse($this->fa3_xml);
        }
        if (empty($parsed['deposits']) || ($parsed['invoice']['type'] ?? $this->invoice_type) !== 'ROZ') {
            return $out;
        }
        require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
        $keys = array();
        $norm = array('22' => '23', '7' => '8');
        foreach ((array) ($parsed['lines'] ?? array()) as $l) {
            $k = (string) ($l['vat_rate'] ?? '');
            $keys[] = isset($norm[$k]) ? $norm[$k] : $k;
        }
        $keys = array_merge($keys, array_map('strval', array_keys((array) ($parsed['vat_summary'] ?? array()))));
        $byDoli = array();
        foreach ($keys as $k) {
            $v = ksefVatKeyToDolibarr($k);
            $byDoli[is_string($v) ? $v : (string) (float) $v] = $k;
        }
        $keys = $byDoli;
        $foreign = !empty($this->currency) && $this->currency !== $conf->currency;
        foreach ((array) $parsed['deposits'] as $d) {
            $row = array('label' => $d['ksef_number'] !== '' ? $d['ksef_number'] : $d['number'], 'ksef_number' => (string) $d['ksef_number'],
                'number' => (string) $d['number'], 'id' => 0, 'ref' => '', 'reason' => 'missing', 'rates' => array(), 'value' => array(), 'part' => null, 'discounts' => array());
            $sql = '';
            if ($d['ksef_number'] !== '') {
                $sql = "SELECT ff.rowid FROM " . MAIN_DB_PREFIX . "ksef_incoming AS i INNER JOIN " . MAIN_DB_PREFIX . "facture_fourn AS ff ON ff.rowid = i.fk_facture_fourn"
                    . " WHERE i.ksef_number = '" . $this->db->escape($d['ksef_number']) . "' AND ff.type = " . FactureFournisseur::TYPE_DEPOSIT
                    . " AND ff.entity IN (" . getEntity('supplier_invoice') . ") ORDER BY i.rowid DESC LIMIT 1";
            } elseif ($d['number'] !== '' && $socid > 0) {
                $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "facture_fourn WHERE ref_supplier = '" . $this->db->escape($d['number']) . "'"
                    . " AND fk_soc = " . (int) $socid . " AND type = " . FactureFournisseur::TYPE_DEPOSIT . " AND entity IN (" . getEntity('supplier_invoice') . ") LIMIT 1";
            }
            $res = $sql !== '' ? $this->db->query($sql) : false;
            $o = $res ? $this->db->fetch_object($res) : null;
            $dep = new FactureFournisseur($this->db);
            if (!$o || $dep->fetch((int) $o->rowid) <= 0) {
                $out[] = $row;
                continue;
            }
            $row['id'] = (int) $dep->id;
            $row['ref'] = (string) $dep->ref;
            $depForeign = !empty($dep->multicurrency_code) && $dep->multicurrency_code !== $conf->currency;
            if ((int) $dep->status === FactureFournisseur::STATUS_DRAFT) {
                $row['reason'] = 'draft';
            } elseif ($socid > 0 && (int) $dep->socid !== (int) $socid) {
                $row['reason'] = 'supplier';
            } elseif ($foreign ? $dep->multicurrency_code !== $this->currency : $depForeign) {
                $row['reason'] = 'currency';
            } else {
                $row['reason'] = '';
                $discounts = ksefDepositDiscounts($this->db, $dep);
                $row['discounts'] = is_array($discounts) ? $discounts : array();
                $own = (int) $this->fk_facture_fourn;
                $imported = $own > 0 && in_array($own, array_column($row['discounts'], 'used_by'), true);
                $t = ksefDepositCreditTarget($this->db, $dep->id, 0, 0, 'invoice_supplier');
                $col = $foreign ? 'mht' : 'ht';
                foreach (($t ? $t['rates'] : array()) as $rk => $r) {
                    if (abs($r[$col]) >= 0.005 && isset($keys[$rk])) {
                        $row['value'][$keys[$rk]] = ($row['value'][$keys[$rk]] ?? 0) + $r[$col];
                    }
                }
                $linked = array();
                if ($imported) {
                    foreach ($row['discounts'] as $disc) {
                        if ($disc['used_by'] === $own) {
                            $linked[] = array('rate' => $disc['rate'], 'net' => abs($disc[$col]), 'gross' => abs($disc[$foreign ? 'mttc' : 'ttc']));
                        }
                    }
                } else {
                    foreach ($row['discounts'] as $disc) {
                        if ($disc['used_by'] > 0 && $disc['used_by'] !== $own) {
                            $row['reason'] = 'used';
                        }
                    }
                    $cov = ksefDepositCovers($this->db, $dep->id, $own);
                    foreach (($t ? $t['target']['rates'] : array()) as $rk => $r) {
                        $net = abs($r[$col]) - (float) ($cov[$rk][$col] ?? 0);
                        if ($net >= 0.005) {
                            $linked[] = array('rate' => $rk, 'net' => $net, 'gross' => abs($r[$t['key']]) - (float) ($cov[$rk][$t['key']] ?? 0));
                        } elseif (abs($r[$col]) >= 0.005) {
                            $row['reason'] = $row['reason'] ?: 'used';
                        }
                    }
                }
                $gross = 0.0;
                foreach ($linked as $l) {
                    if (!isset($keys[$l['rate']])) {
                        $row['reason'] = $row['reason'] ?: 'rate';
                        continue;
                    }
                    $k = $keys[$l['rate']];
                    $row['rates'][$k] = ($row['rates'][$k] ?? 0) + $l['net'];
                    $gross += $l['gross'];
                }
                if ($t && abs((float) $t[$t['key']]) < 0.005) {
                    $row['reason'] = $row['reason'] ?: 'zero';
                } elseif (!$t || !$t['rates']) {
                    $row['reason'] = 'rate';
                } elseif (!$linked) {
                    $row['reason'] = $row['reason'] ?: 'unpaid';
                }
                $coveredElsewhere = $t ? array_sum(array_column(ksefDepositCovers($this->db, $dep->id, $own), $t['key'])) : 0;
                if ($t && $linked && $gross + $coveredElsewhere < $t[$t['key']] - 0.005) {
                    $row['part'] = array((float) price2num($gross, 'MT'), (float) $t[$t['key']]);
                }
                if ($row['reason'] === '' && $this->import_status === self::STATUS_IMPORTED && !$imported) {
                    $row['reason'] = 'summary';
                }
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * @brief Deposit preview rows
     * @return array
     */
    public function depositPreviewLines()
    {
        global $langs;
        $out = array();
        if ($this->invoice_type !== 'ROZ') {
            return $out;
        }
        $langs->load('ksef@ksef');
        foreach ($this->supplierDeposits($this->deposit_socid) as $d) {
            if ($d['reason'] !== '') {
                continue;
            }
            $dep = new FactureFournisseur($this->db);
            $dep->fetch($d['id']);
            $label = dol_escape_htmltag($langs->trans('KSEF_IncomingDepositRow')) . ' ' . $dep->getNomUrl(1)
                . ($d['ksef_number'] !== '' ? ' <span class="opacitymedium small">' . dol_escape_htmltag($d['ksef_number']) . '</span>' : '');
            foreach ($d['rates'] as $rate => $net) {
                $out[] = array('line_num' => '', 'description' => '', 'description_html' => $label, 'quantity' => 1, 'unit' => '',
                    'unit_price_net' => -$net, 'vat_rate' => (string) $rate, 'net_amount' => -$net, 'deposit_settlement' => 1);
            }
        }
        return $out;
    }

    /**
     * @brief Link supplier deposits
     * @param User $user
     * @param FactureFournisseur $facture Draft settlement
     * @param int $socid Supplier
     * @return int Count, <0 on error
     */
    private function linkSupplierDeposits($user, $facture, $socid)
    {
        global $langs, $conf;
        $linked = 0;
        $foreign = !empty($this->currency) && $this->currency !== $conf->currency;
        $cols = array('ht', 'tva', 'ttc', 'mht', 'mtva', 'mttc');
        $parsed = (new FA3Parser($this->db))->parse((string) $this->fa3_xml);
        $check = $this->depositDeductionCheck($socid, $parsed);
        foreach ($this->supplierDeposits($socid, $parsed) as $d) {
            if (!in_array($d['reason'], array('', 'unpaid', 'draft'), true)) {
                if (!in_array($d['reason'], array('missing', 'zero'), true)) {
                    $this->warnings[] = $langs->transnoentitiesnoconv('KSEF_DepositNotLinked', $d['ref'], $langs->transnoentitiesnoconv('KSEF_DepositReason_' . $d['reason']));
                }
                continue;
            }
            $rs = $d['reason'] === '' ? ksefDepositRestate($this->db, $user, $d['id'], 0, 'open', 0, 'invoice_supplier') : array('result' => 0);
            if ($rs['result'] < 0) {
                $this->error = $rs['error'];
                return -1;
            }
            $used = array();
            foreach ($d['reason'] === '' ? ksefDepositRows($this->db, $d['id'], 'invoice_supplier') : array() as $r) {
                if ($r['inv'] > 0 || $r[$foreign ? 'mttc' : 'ttc'] < 0.005) {
                    continue;
                }
                if ($facture->insert_discount($r['id']) <= 0) {
                    $this->error = $facture->error ? $facture->error : implode(', ', (array) $facture->errors);
                    return -1;
                }
                $used[$r['key']] = $r;
            }
            $t = ksefDepositCreditTarget($this->db, $d['id'], 0, 0, 'invoice_supplier');
            $covered = ksefDepositCovers($this->db, $d['id'], $facture->id);
            $rem = array();
            foreach (($t ? $t['rates'] : array()) as $rk => $v) {
                $c = array('tva_tx' => $v['tva_tx'], 'code' => $v['code']);
                foreach ($cols as $col) {
                    $c[$col] = (float) price2num($v[$col] - (isset($used[$rk]) ? $used[$rk][$col] : 0) - (float) ($covered[$rk][$col] ?? 0), 'MT');
                }
                if ($c[$t['key']] > 0.005) {
                    $rem[$rk] = $c;
                }
            }
            $cover = $rem ? $this->settlementCover($parsed, $facture->id, $d['id'], $rem, $t['key'] === 'mttc') : array();
            if ($cover === false) {
                return -1;
            }
            if ($cover && !ksefDepositCoverSet($this->db, $d['id'], $facture->id, $cover)) {
                $this->error = $this->db->lasterror();
                return -1;
            }
            if ($d['reason'] !== '') {
                $this->warnings[] = $langs->transnoentitiesnoconv('KSEF_DepositNotLinked', $d['ref'], $langs->transnoentitiesnoconv('KSEF_DepositReason_' . $d['reason']));
                continue;
            }
            if ($d['part']) {
                $this->warnings[] = $langs->transnoentitiesnoconv('KSEF_DepositLinkedPart', $d['ref'], price($d['part'][0], 0, $langs, 1, -1, -1, $this->currency ?: $conf->currency),
                    price($d['part'][1], 0, $langs, 1, -1, -1, $this->currency ?: $conf->currency));
            }
            $linked++;
        }
        if ($check !== '') {
            $this->warnings[] = $check;
        }
        return $linked;
    }

    /**
     * @brief Cover earlier settlements
     * @param int $depositId Draft deposit
     * @param int $socid Supplier
     * @return int Count, <0 on error
     */
    private function coverEarlierSettlements($depositId, $socid)
    {
        if ((string) $this->ksef_number === '') {
            return 0;
        }
        $res = $this->db->query("SELECT i.fk_facture_fourn, i.fa3_xml FROM " . MAIN_DB_PREFIX . $this->table_element . " AS i"
            . " INNER JOIN " . MAIN_DB_PREFIX . "facture_fourn AS ff ON ff.rowid = i.fk_facture_fourn"
            . " WHERE i.invoice_type = 'ROZ' AND i.import_status = '" . self::STATUS_IMPORTED . "' AND i.entity = " . (int) $this->entity
            . " AND i.environment = '" . $this->db->escape($this->environment ?: 'TEST') . "' AND i.currency = '" . $this->db->escape($this->currency ?: 'PLN') . "'"
            . " AND ff.fk_soc = " . (int) $socid . " AND i.fa3_xml LIKE '%" . $this->db->escape($this->db->escapeforlike($this->ksef_number)) . "%' ORDER BY i.rowid");
        if (!$res) {
            $this->error = $this->db->lasterror();
            return -1;
        }
        $t = ksefDepositCreditTarget($this->db, $depositId, 0, 0, 'invoice_supplier');
        $cols = array('ht', 'tva', 'ttc', 'mht', 'mtva', 'mttc');
        $rem = array();
        foreach (($t ? $t['rates'] : array()) as $rk => $v) {
            if ($v[$t['key']] > 0.005) {
                $rem[$rk] = $v;
            }
        }
        $parser = new FA3Parser($this->db);
        $n = 0;
        while ($rem && ($o = $this->db->fetch_object($res))) {
            $p = $parser->parse((string) $o->fa3_xml);
            if (!in_array((string) $this->ksef_number, array_column((array) ($p['deposits'] ?? array()), 'ksef_number'), true)) {
                continue;
            }
            $cover = $this->settlementCover($p, (int) $o->fk_facture_fourn, $depositId, $rem, $t['key'] === 'mttc');
            if ($cover === false) {
                return -1;
            }
            if (!$cover) {
                continue;
            }
            foreach ($cover as $rk => $c) {
                foreach ($cols as $col) {
                    $rem[$rk][$col] -= $c[$col];
                }
            }
            if (!ksefDepositCoverSet($this->db, $depositId, (int) $o->fk_facture_fourn, $cover)) {
                $this->error = $this->db->lasterror();
                return -1;
            }
            $n++;
        }
        return $n;
    }

    /**
     * @brief Settlement deposit cover
     * @param array $parsed ROZ parser result
     * @param int $rozId ROZ invoice id
     * @param int $depositId
     * @param array $rem Remaining per rate
     * @param bool $foreign Use mht
     * @return array|false False on error
     */
    private function settlementCover($parsed, $rozId, $depositId, $rem, $foreign)
    {
        $net = $foreign ? 'mht' : 'ht';
        $ded = array();
        $sums = $this->rozLineSums((array) ($parsed['lines'] ?? array()));
        $summary = (array) ($parsed['vat_summary'] ?? array());
        foreach (array_unique(array_merge(array_map('strval', array_keys($sums)), array_map('strval', array_keys($summary)))) as $rate) {
            if ($rate === 'marza') {
                continue;
            }
            $v = ksefVatKeyToDolibarr($rate);
            $rk = is_string($v) ? $v : ksefDepositRateKey($v);
            $ded[$rk] = ($ded[$rk] ?? 0) + round((float) ($sums[$rate] ?? 0) - (float) ($summary[$rate]['net'] ?? 0), 2);
        }
        $res = $this->db->query("SELECT d.tva_tx, d.vat_src_code, SUM(d." . ($foreign ? 'multicurrency_total_ht' : 'total_ht') . ") AS n"
            . " FROM " . MAIN_DB_PREFIX . "facture_fourn_det AS d INNER JOIN " . MAIN_DB_PREFIX . "societe_remise_except AS r ON r.rowid = d.fk_remise_except"
            . " WHERE d.fk_facture_fourn = " . (int) $rozId . " AND r.description = '(DEPOSIT)' GROUP BY d.tva_tx, d.vat_src_code");
        $others = ksefDepositsCoveredBy($this->db, $rozId);
        if (!$res || $others === false) {
            $this->error = $this->db->lasterror();
            return false;
        }
        while ($o = $this->db->fetch_object($res)) {
            $rk = ksefDepositRateKey($o->tva_tx, (string) $o->vat_src_code);
            $ded[$rk] = ($ded[$rk] ?? 0) - abs((float) $o->n);
        }
        foreach ($others as $dep) {
            if ((int) $dep !== (int) $depositId) {
                foreach ((array) (ksefDepositState($this->db, $dep, 'invoice_supplier')['dep_cover'][(int) $rozId] ?? array()) as $rk => $c) {
                    $ded[$rk] = ($ded[$rk] ?? 0) - (float) ($c[$net] ?? 0);
                }
            }
        }
        $cover = array();
        foreach ($rem as $rk => $r) {
            $share = min((float) $r[$net], max(0.0, (float) ($ded[$rk] ?? 0)));
            if ($share < 0.005) {
                continue;
            }
            $cover[$rk] = array('tva_tx' => $r['tva_tx'], 'code' => $r['code']);
            foreach (array('ht', 'tva', 'ttc', 'mht', 'mtva', 'mttc') as $col) {
                $cover[$rk][$col] = (float) price2num($r[$col] * $share / $r[$net], 'MT');
            }
        }
        return $cover;
    }

    /**
     * @brief Check deposit deduction
     * @param int $socid 0: any
     * @param array|null $parsed null: from fa3_xml
     * @return string Warning or ''
     */
    public function depositDeductionCheck($socid = 0, $parsed = null)
    {
        global $langs, $conf;
        if (!$this->isOutgoingRecord() && in_array($this->invoice_type, array('KOR_ZAL', 'KOR_ROZ'), true)) {
            return $this->statedBeforeCheck((int) $this->fk_facture_fourn, $parsed);
        }
        if ($this->isOutgoingRecord() || $this->invoice_type !== 'ROZ') {
            return '';
        }
        if ($parsed === null) {
            if (empty($this->fa3_xml)) {
                return '';
            }
            $parsed = (new FA3Parser($this->db))->parse($this->fa3_xml);
        }
        $deps = $this->supplierDeposits($socid, $parsed);
        $value = array();
        $refs = array();
        foreach ($deps as $d) {
            if ($d['id'] <= 0 || !in_array($d['reason'], array('', 'unpaid', 'zero', 'summary'), true)) {
                return '';
            }
            $else = $this->depositDeductedElsewhere($d['ksef_number']);
            if ($else === null) {
                return '';
            }
            foreach ($d['value'] as $rate => $net) {
                $value[$rate] = ($value[$rate] ?? 0) + $net - ($else[$rate] ?? 0);
            }
            $refs[] = $d['ref'];
        }
        if (!$deps) {
            return '';
        }
        $sums = $this->rozLineSums((array) ($parsed['lines'] ?? array()));
        $summary = (array) ($parsed['vat_summary'] ?? array());
        $xml = $val = 0.0;
        $differs = false;
        foreach (array_unique(array_merge(array_keys($sums), array_keys($value))) as $rate) {
            $ded = round((float) ($sums[$rate] ?? 0) - (float) ($summary[$rate]['net'] ?? 0), 2);
            $xml += $ded;
            $val += round((float) ($value[$rate] ?? 0), 2);
            if (abs($ded - (float) ($value[$rate] ?? 0)) >= 0.01) {
                $differs = true;
            }
        }
        if (!$differs) {
            return '';
        }
        $langs->load('ksef@ksef');
        $cur = $this->currency ?: $conf->currency;
        return $langs->transnoentitiesnoconv('KSEF_DepositDeductionDiffers', price($xml, 0, $langs, 1, -1, -1, $cur), implode(', ', $refs), price($val, 0, $langs, 1, -1, -1, $cur));
    }

    /**
     * @brief Deposit deducted elsewhere
     * @param string $ksefNumber Deposit KSeF number
     * @return array|null null: unknown
     */
    private function depositDeductedElsewhere($ksefNumber)
    {
        global $conf;
        $out = array();
        if ((string) $ksefNumber === '') {
            return $out;
        }
        $res = $this->db->query("SELECT ksef_number, fa3_xml FROM " . MAIN_DB_PREFIX . $this->table_element . " WHERE invoice_type = 'ROZ'"
            . " AND rowid <> " . (int) ($this->id ?: $this->rowid) . " AND entity = " . (int) $conf->entity
            . " AND import_status <> 'SKIPPED' AND environment = '" . $this->db->escape($this->environment ?: 'TEST') . "'"
            . " AND fa3_xml LIKE '%" . $this->db->escape($this->db->escapeforlike($ksefNumber)) . "%'");
        $seen = array((string) $this->ksef_number => true);
        $parser = new FA3Parser($this->db);
        while ($res && ($o = $this->db->fetch_object($res))) {
            if (isset($seen[(string) $o->ksef_number])) {
                continue;
            }
            $seen[(string) $o->ksef_number] = true;
            $p = $parser->parse((string) $o->fa3_xml);
            $cited = array_column((array) ($p['deposits'] ?? array()), 'ksef_number');
            if (!in_array($ksefNumber, $cited, true)) {
                continue;
            }
            if (count($cited) !== 1) {
                return null;
            }
            $sums = $this->rozLineSums((array) ($p['lines'] ?? array()));
            $summary = (array) ($p['vat_summary'] ?? array());
            foreach (array_unique(array_merge(array_keys($sums), array_keys($summary))) as $rate) {
                $out[$rate] = ($out[$rate] ?? 0) + round((float) ($sums[$rate] ?? 0) - (float) ($summary[$rate]['net'] ?? 0), 2);
            }
        }
        return $out;
    }

    /**
     * @brief Check stated P_15ZK
     * @param int $ownId Own invoice, 0: none
     * @param array|null $parsed null: from fa3_xml
     * @return string Warning or ''
     */
    public function statedBeforeCheck($ownId = 0, $parsed = null)
    {
        global $langs, $conf;
        if ($this->isOutgoingRecord() || !in_array($this->invoice_type, array('KOR_ZAL', 'KOR_ROZ'), true)) {
            return '';
        }
        if ($parsed === null) {
            if (empty($this->fa3_xml)) {
                return '';
            }
            $parsed = (new FA3Parser($this->db))->parse($this->fa3_xml);
        }
        $stated = $parsed['invoice']['p15zk'] ?? null;
        $resolved = $stated === null ? array() : $this->resolveCorrectedInvoices();
        if (count($resolved) !== 1 || empty($resolved[0]['supplier_invoice'])) {
            return '';
        }
        $P = MAIN_DB_PREFIX;
        $own = (int) $ownId;
        $mc = !empty($this->currency) && $this->currency !== $conf->currency;
        $rootId = (int) $resolved[0]['supplier_invoice']->id;
        for ($i = 0; $i < 20; $i++) {
            $res = $this->db->query("SELECT type, fk_facture_source FROM " . $P . "facture_fourn WHERE rowid = " . $rootId);
            $o = $res ? $this->db->fetch_object($res) : null;
            if (!$o || !in_array((int) $o->type, array(1, 2), true) || empty($o->fk_facture_source)) {
                break;
            }
            $rootId = (int) $o->fk_facture_source;
        }
        if ($this->invoice_type === 'KOR_ZAL') {
            $v = ksefDepositCurrentValue($this->db, $rootId, $own, 'invoice_supplier', $own);
            if (!$v) {
                return '';
            }
            $before = (float) ($mc ? $v['mttc'] : $v['ttc']);
            $ref = $v['ref'];
        } else {
            $col = $mc ? 'multicurrency_total_ttc' : 'total_ttc';
            $res = $this->db->query("SELECT ref, " . $col . " AS t FROM " . $P . "facture_fourn WHERE rowid = " . $rootId);
            $o = $res ? $this->db->fetch_object($res) : null;
            if (!$o) {
                return '';
            }
            $ref = (string) $o->ref;
            $inForce = (float) $o->t;
            $notes = 0.0;
            $next = array($rootId);
            $seen = array($rootId);
            for ($i = 0; $next && $i < 20; $i++) {
                $res = $this->db->query("SELECT rowid, ref, type, fk_statut, " . $col . " AS t FROM " . $P . "facture_fourn WHERE fk_facture_source IN (" . implode(',', $next) . ")"
                    . " AND type IN (1, 2) AND fk_statut > 0 AND rowid <> " . $own . ($own > 0 ? " AND rowid < " . $own : "") . " ORDER BY rowid");
                $next = array();
                while ($res && ($o = $this->db->fetch_object($res))) {
                    if (in_array((int) $o->rowid, $seen, true)) {
                        continue;
                    }
                    $seen[] = (int) $o->rowid;
                    $next[] = (int) $o->rowid;
                    if (!in_array((int) $o->fk_statut, array(1, 2), true)) {
                        continue;
                    }
                    if ((int) $o->type === 2) {
                        $notes += (float) $o->t;
                    } else {
                        $inForce = (float) $o->t;
                        $ref = (string) $o->ref;
                    }
                }
            }
            $before = $inForce + $notes;
        }
        $before = (float) price2num($before, 'MT');
        if (abs($before - (float) $stated) < 0.01) {
            return '';
        }
        $langs->load('ksef@ksef');
        $cur = $this->currency ?: $conf->currency;
        return $langs->transnoentitiesnoconv('KSEF_IncomingStatedBeforeDiffers', price((float) $stated, 0, $langs, 1, -1, -1, $cur), $ref, price($before, 0, $langs, 1, -1, -1, $cur));
    }

    /**
     * @brief Net per rate
     * @param array $lines
     * @return array rate => net
     */
    private function rozLineSums($lines)
    {
        $norm = array('22' => '23', '7' => '8');
        $sums = array();
        foreach ($lines as $line) {
            $rate = (string) ($line['vat_rate'] ?? '');
            $rate = isset($norm[$rate]) ? $norm[$rate] : $rate;
            if (isset($line['net_amount']) && $line['net_amount'] !== null && $line['net_amount'] !== '') {
                $net = (float) $line['net_amount'];
            } elseif (isset($line['gross_amount']) && $line['gross_amount'] !== null && $line['gross_amount'] !== '') {
                $net = (float) $line['gross_amount'] / (1 + (is_numeric($rate) ? (float) $rate : 0) / 100);
            } else {
                $net = (float) ($line['quantity'] ?? 1) * (float) ($line['unit_price_net'] ?? 0);
            }
            $sums[$rate] = ($sums[$rate] ?? 0) + $net;
        }
        return $sums;
    }

    /**
     * @brief Prepare import lines
     * @param array $lines
     * @param array $lineProductMap line_num => product id
     * @param bool $isCreditNote
     * @param bool $isMulticurrency
     * @param array|null $vatSummary
     * @param string $stockConst Stock trigger const
     * @return array|false False on error
     */
    public function prepareImportLines($lines, $lineProductMap, $isCreditNote, $isMulticurrency, $vatSummary, $stockConst)
    {
        global $langs;

        $out = array();
        $useSummaryLines = false;
        if ($isCreditNote && !empty($lines)) {
            foreach ($lines as $line) {
                if ((isset($line['net_amount']) ? (float)$line['net_amount'] : 0) > 0) {
                    $useSummaryLines = true;
                    break;
                }
            }
        }

        if ($useSummaryLines) {
            foreach (($vatSummary ?: $this->getVatSummary()) as $rate => $amounts) {
                if ((float) ($amounts['net'] ?? 0) > 0) {
                    $this->error = $langs->transnoentitiesnoconv('KSEF_ImportErrorMixedRateKor', $rate);
                    return false;
                }
            }
            foreach ($lines as $line) {
                $lineNet = isset($line['net_amount']) ? (float)$line['net_amount'] : 0;
                $deltaSign = $lineNet >= 0 ? '+' : '';
                $desc = ($line['description'] ?? '') . ' (' . $langs->transnoentitiesnoconv('KSEF_CorrectionItemDetail', $deltaSign . price($lineNet, 0, $langs, 0, -1, -1, $this->currency), isset($line['quantity']) ? (float)$line['quantity'] : 0, $line['unit'] ?? '') . ')';
                $out[] = array('desc' => $desc, 'pu' => 0, 'pu_devise' => 0, 'txtva' => 0, 'qty' => 0, 'fk_product' => 0, 'type' => 1,
                    'price_base' => 'HT', 'ref' => $line['indeks'] ?? '', 'line_num' => $line['line_num'] ?? 0);
            }
            foreach (($vatSummary ?: $this->getVatSummary()) as $rate => $amounts) {
                $netAmount = abs((float)($amounts['net'] ?? 0));
                if ($netAmount == 0) {
                    continue;
                }
                $out[] = array('desc' => $langs->transnoentitiesnoconv('KSEF_CorrectionSummaryLine', $rate . '%'), 'pu' => $isMulticurrency ? 0 : $netAmount,
                    'pu_devise' => $isMulticurrency ? $netAmount : 0, 'txtva' => ksefVatKeyToDolibarr($rate), 'qty' => 1, 'fk_product' => 0,
                    'type' => 1, 'price_base' => 'HT', 'ref' => '', 'line_num' => 0);
            }
            return $out;
        }

        foreach ($lines as $line) {
            $lineNum = $line['line_num'] ?? 0;
            $fk_product = (isset($lineProductMap[$lineNum]) && self::isProductLine($line)) ? (int)$lineProductMap[$lineNum] : 0;
            $type = 0;
            $product = null;
            if ($fk_product > 0) {
                $product = new Product($this->db);
                if ($product->fetch($fk_product) > 0) {
                    $type = $product->type;
                }
            }
            $desc = $line['description'] ?? '';
            // P_10 in P_11
            if (!$this->isOutgoingRecord() && !self::isCorrectionType($this->invoice_type) && is_numeric($line['discount'] ?? null) && (float) $line['discount'] != 0) {
                $desc .= ($desc !== '' ? "\n" : '') . $langs->transnoentitiesnoconv('KSEF_ImportLineDiscount', price((float) $line['discount'], 0, $langs, 0, -1, -1, $this->currency ?: 'PLN'));
            }
            $amount = self::resolveLineAmount($line);
            if ($fk_product > 0 && !empty($line['price_only']) && getDolGlobalString($stockConst) && isModEnabled('stock')) {
                if ($product && $product->id == $fk_product) {
                    $desc = $product->ref . ($desc !== '' ? ' - ' . $desc : '');
                }
                $fk_product = 0;
                $type = 0;
            }
            if ($fk_product > 0 && is_numeric($line['quantity'] ?? null) && (float) $line['quantity'] == 0 && (float) $amount['pu'] == 0) {
                if ($product && $product->id == $fk_product) {
                    $desc = $product->ref . ($desc !== '' ? ' - ' . $desc : '');
                }
                $fk_product = 0;
                $type = 0;
                $amount['qty'] = 0;
            }
            $out[] = array('desc' => $desc, 'pu' => $isMulticurrency ? 0 : $amount['pu'], 'pu_devise' => $isMulticurrency ? $amount['pu'] : 0,
                'txtva' => isset($line['vat_rate']) ? ksefVatKeyToDolibarr($line['vat_rate']) : 0, 'qty' => $amount['qty'],
                'fk_product' => $fk_product, 'type' => $type, 'price_base' => $amount['price_base_type'], 'ref' => $line['indeks'] ?? '', 'line_num' => $lineNum);
        }
        return $out;
    }

    /**
     * @brief Attach KSeF PDF
     * @param FactureFournisseur $facture
     * @return bool
     */
    private function attachPdfToSupplierInvoice($facture)
    {
        global $conf;

        $pdfContent = $this->generatePdfVisualization();
        if ($pdfContent === false) {
            dol_syslog("KsefIncoming::attachPdfToSupplierInvoice PDF generation failed: " . $this->error, LOG_WARNING);
            return false;
        }

        $ref = dol_sanitizeFileName($facture->ref);
        $dir = $conf->fournisseur->facture->dir_output . '/' . get_exdir($facture->id, 2, 0, 0, $facture, 'invoice_supplier') . $ref;

        if (dol_mkdir($dir) < 0) {
            dol_syslog("KsefIncoming::attachPdfToSupplierInvoice mkdir failed for: " . $dir, LOG_WARNING);
            return false;
        }

        $filename = dol_sanitizeFileName('ksef_' . $this->invoice_number . '.pdf');
        $fullPath = $dir . '/' . $filename;

        if (file_put_contents($fullPath, $pdfContent) === false) {
            dol_syslog("KsefIncoming::attachPdfToSupplierInvoice write failed: " . $fullPath, LOG_WARNING);
            return false;
        }

        $facture->indexFile($fullPath, 0);

        dol_syslog("KsefIncoming::attachPdfToSupplierInvoice attached " . $filename . " to invoice " . $facture->ref, LOG_INFO);
        return true;
    }

    /**
     * @brief Reset import status
     * @param User $user
     * @return int <0 on error
     */
    public function resetImportStatus($user)
    {
        global $langs;
        $langs->load("ksef@ksef");

        if ($this->import_status === self::STATUS_FETCH_ERROR) {
            $langs->load("ksef@ksef");
            $this->error = $langs->trans('KSEF_FetchErrorNotImportable');
            return -1;
        }

        if ($this->linkedSupplierDocumentExists()) {
            $this->error = $langs->trans('KSEF_LinkedInvoiceStillExists');
            return -1;
        }

        $this->import_status = self::STATUS_NEW;
        $this->import_date = null;
        $this->import_error = null;
        $this->fk_facture_fourn = null;
        $this->fk_credit_note = null;

        return $this->update($user);
    }

    /**
     * @brief Link to invoice
     * @param User $user
     * @param int $fkFactureFourn Supplier invoice id
     * @return int 0 refused, <0 error
     */
    public function linkToInvoice($user, $fkFactureFourn)
    {
        global $langs;
        $langs->load('ksef@ksef');
        $this->error_code = '';
        $fk = (int) $fkFactureFourn;
        if ($fk <= 0 || $this->linkColumn !== 'fk_facture_fourn' || empty($this->id)) {
            $this->error = 'Bad link request';
            return -1;
        }
        if ($this->import_status === self::STATUS_IMPORTED && (int) $this->fk_facture_fourn === $fk) {
            return 1;
        }
        $old = 0;
        if ($this->import_status === self::STATUS_IMPORTED) {
            $old = (int) $this->fk_facture_fourn;
            if ($old > 0) {
                require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
                $linked = new FactureFournisseur($this->db);
                if ($linked->fetch($old) > 0) {
                    $this->error_code = 'ALREADY_LINKED';
                    $this->error = $langs->transnoentitiesnoconv('KSEF_IncomingAlreadyLinked', $linked->ref);
                    return 0;
                }
            }
        } elseif (!in_array($this->import_status, array(self::STATUS_NEW, self::STATUS_ERROR, self::STATUS_SKIPPED), true)) {
            $this->error_code = 'NOT_LINKABLE';
            $this->error = $langs->transnoentitiesnoconv('KSEF_ErrorActionNotAllowedForStatus', $this->LibStatut($this->import_status, 0));
            return 0;
        }

        $now = dol_now();
        $sql = "UPDATE " . MAIN_DB_PREFIX . $this->table_element . " SET";
        $sql .= " import_status = '" . self::STATUS_IMPORTED . "', import_date = " . (int) $now . ", import_error = NULL";
        $sql .= ", fk_facture_fourn = " . $fk;
        if ($this->import_status === self::STATUS_IMPORTED) {
            $sql .= ", fk_credit_note = NULL";
        }
        $sql .= " WHERE rowid = " . (int) $this->id . " AND (import_status IN ('" . self::STATUS_NEW . "', '" . self::STATUS_ERROR . "', '" . self::STATUS_SKIPPED . "')";
        if ($this->import_status === self::STATUS_IMPORTED) {
            $sql .= " OR (import_status = '" . self::STATUS_IMPORTED . "' AND COALESCE(fk_facture_fourn, 0) = " . $old . ")";
        }
        $sql .= ")";
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return -1;
        }
        if ((int) $this->db->affected_rows($resql) !== 1) {
            $cur = new static($this->db);
            $cur->fetch($this->id);
            $this->error_code = 'STATUS_CHANGED';
            $this->error = $langs->transnoentitiesnoconv('KSEF_ErrorActionNotAllowedForStatus', $cur->id > 0 ? $cur->LibStatut($cur->import_status, 0) : '?');
            return 0;
        }
        if ($this->import_status === self::STATUS_IMPORTED) {
            $this->fk_credit_note = null;
        }
        $this->import_status = self::STATUS_IMPORTED;
        $this->import_date = $now;
        $this->import_error = null;
        $this->fk_facture_fourn = $fk;
        return 1;
    }

    /**
     * @brief Move import status
     * @param array $from Allowed statuses
     * @param string $to
     * @param int|null $importDate
     * @param bool $clearError
     * @return int 0 refused, <0 error
     */
    private function moveImportStatus($from, $to, $importDate, $clearError)
    {
        global $langs;
        $in = array();
        foreach ($from as $st) {
            $in[] = "'" . $this->db->escape($st) . "'";
        }
        $sql = "UPDATE " . MAIN_DB_PREFIX . $this->table_element . " SET";
        $sql .= " import_status = '" . $this->db->escape($to) . "'";
        $sql .= ", import_date = " . ($importDate ? (int) $importDate : "NULL");
        if ($clearError) {
            $sql .= ", import_error = NULL";
        }
        $sql .= " WHERE rowid = " . (int) $this->id . " AND import_status IN (" . implode(',', $in) . ")";
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return -1;
        }
        if ((int) $this->db->affected_rows($resql) !== 1) {
            $langs->load('ksef@ksef');
            $cur = new static($this->db);
            $cur->fetch($this->id);
            $this->error = $langs->trans('KSEF_ErrorActionNotAllowedForStatus', $cur->id > 0 ? $cur->LibStatut($cur->import_status, 0) : '?');
            return 0;
        }
        $this->import_status = $to;
        $this->import_date = $importDate;
        if ($clearError) {
            $this->import_error = null;
        }
        return 1;
    }

    /**
     * @brief Skip row
     * @param User $user
     * @return int 0 refused, <0 error
     */
    public function skip($user)
    {
        return $this->moveImportStatus(array(self::STATUS_NEW, self::STATUS_ERROR), self::STATUS_SKIPPED, dol_now(), false);
    }

    /**
     * @brief Reopen skipped row
     * @param User $user
     * @return int 0 refused, <0 error
     */
    public function reopen($user)
    {
        return $this->moveImportStatus(array(self::STATUS_SKIPPED), self::STATUS_NEW, null, true);
    }

    /**
     * @brief Retry fetch errors
     * @param User $user
     * @param array $ids Row ids
     * @return array Counts
     */
    public function reprocessMany($user, $ids)
    {
        $out = array('done' => 0, 'failed' => 0, 'skipped' => 0);
        foreach ((array) $ids as $rid) {
            $row = new static($this->db);
            if ($row->fetch((int) $rid) <= 0 || $row->import_status !== self::STATUS_FETCH_ERROR) {
                $out['skipped']++;
                continue;
            }
            if ($row->reprocessFromXml($user) > 0) {
                $out['done']++;
            } else {
                $out['failed']++;
            }
        }
        return $out;
    }

    /**
     * @brief Reparse stored XML
     * @param User $user
     * @return int 0 still failing, <0 error
     */
    public function reprocessFromXml($user)
    {
        global $langs;
        $langs->load('ksef@ksef');
        dol_include_once('/ksef/class/fa3_parser.class.php');

        if ($this->import_status !== self::STATUS_FETCH_ERROR) {
            $this->error = $langs->trans('KSEF_ErrorActionNotAllowedForStatus', $this->LibStatut($this->import_status, 0));
            return 0;
        }
        if (empty($this->fa3_xml)) {
            $this->error = $langs->trans('KSEF_RetryFetchNoXml');
            return 0;
        }

        $parser = new FA3Parser($this->db);
        $parsed = false;
        $failure = '';
        try {
            $parsed = $parser->parse($this->fa3_xml);
        } catch (Throwable $e) {
            $failure = 'Failed to parse XML: ' . $e->getMessage();
        }
        if (!$parsed) {
            if ($failure === '') {
                $failure = $parser->unsupported_form !== '' ? self::UNSUPPORTED_FORM_PREFIX . $parser->unsupported_form
                    : (strpos((string) $parser->error, 'Failed to parse XML') === 0 ? $parser->error : 'Failed to parse XML: ' . ($parser->error ?: 'unknown error'));
            }
            $sql = "UPDATE " . MAIN_DB_PREFIX . $this->table_element . " SET import_error = '" . $this->db->escape(dol_trunc($failure, 2000, 'right', 'UTF-8', 1)) . "'";
            $sql .= " WHERE rowid = " . (int) $this->id . " AND import_status = '" . self::STATUS_FETCH_ERROR . "'";
            if (!$this->db->query($sql)) {
                $this->error = $this->db->lasterror();
                return -1;
            }
            $this->import_error = $failure;
            $this->error = $langs->trans('KSEF_RetryFetchStillFailing', $this->getFetchErrorText());
            return 0;
        }

        $fresh = new static($this->db);
        $fresh->assignParsed($parsed, $this->fa3_xml);
        $q = function ($v) {
            return ($v !== null && $v !== '') ? "'" . $this->db->escape($v) . "'" : "NULL";
        };
        $i = function ($v) {
            return $v ? (int) $v : "NULL";
        };
        $f = function ($v) {
            return $v !== null && $v !== '' ? (float) $v : "NULL";
        };
        $set = array(
            'seller_nip' => $q($fresh->seller_nip),
            'seller_vat_id' => $q($fresh->seller_vat_id),
            'seller_name' => $q($fresh->seller_name),
            'seller_country' => $fresh->seller_country ? $q($fresh->seller_country) : "'PL'",
            'seller_address' => $q($fresh->seller_address),
            'buyer_nip' => $q($fresh->buyer_nip),
            'buyer_name' => $q($fresh->buyer_name),
            'invoice_number' => $q($fresh->invoice_number),
            'invoice_type' => $fresh->invoice_type ? $q($fresh->invoice_type) : "'VAT'",
            'invoice_date' => $i($fresh->invoice_date),
            'sale_date' => $i($fresh->sale_date),
            'currency' => $fresh->currency ? $q($fresh->currency) : "'PLN'",
            'total_net' => $f($fresh->total_net),
            'total_vat' => $f($fresh->total_vat),
            'total_gross' => $f($fresh->total_gross),
            'vat_summary' => $q($fresh->vat_summary),
            'line_items' => $q($fresh->line_items),
            'payment_due_date' => $i($fresh->payment_due_date),
            'payment_method' => $q($fresh->payment_method),
            'bank_account' => $q($fresh->bank_account),
            'payment_status' => $q($fresh->payment_status),
            'payment_date' => $i($fresh->payment_date),
            'corrected_ksef_number' => $q($fresh->corrected_ksef_number),
            'corrected_invoice_number' => $q($fresh->corrected_invoice_number),
            'corrected_invoice_date' => $i($fresh->corrected_invoice_date),
            'correction_data' => $q($fresh->correction_data),
            'fa3_creation_date' => $i($fresh->fa3_creation_date),
            'fa3_system_info' => $q($fresh->fa3_system_info),
            'import_status' => "'" . self::STATUS_NEW . "'",
            'import_error' => "NULL",
            'import_date' => "NULL",
        );
        $sql = "UPDATE " . MAIN_DB_PREFIX . $this->table_element . " SET ";
        $parts = array();
        foreach ($set as $col => $val) {
            $parts[] = $col . " = " . $val;
        }
        $sql .= implode(', ', $parts);
        $sql .= " WHERE rowid = " . (int) $this->id . " AND import_status = '" . self::STATUS_FETCH_ERROR . "'";
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return -1;
        }
        if ((int) $this->db->affected_rows($resql) !== 1) {
            $this->error = $langs->trans('KSEF_ErrorActionNotAllowedForStatus', '?');
            return 0;
        }
        $this->fetch($this->id);
        if (method_exists($this, 'reconcile')) {
            $this->reconcile($user);
        }
        return 1;
    }

    /**
     * @brief Ensure supplier role
     * @param User $user
     * @param Societe $soc
     * @return int 0 already, -1 not flagged
     */
    public function ensureSupplierRole($user, $soc)
    {
        global $langs;

        if ((int) $soc->fournisseur === 1) {
            return 0;
        }
        if (ksefUserCan($user, 'create_supplier')) {
            $upd = new Societe($this->db);
            if ($upd->fetch($soc->id) > 0) {
                $upd->fournisseur = 1;
                if ((string) $upd->code_fournisseur === '') {
                    $upd->code_fournisseur = '-1';
                }
                if ($upd->update($upd->id, $user, 1, 0, 1) > 0) {
                    $soc->fournisseur = 1;
                    return 1;
                }
                dol_syslog('KsefIncoming::ensureSupplierRole socid=' . (int) $soc->id . ': ' . $upd->error . ' ' . implode(', ', (array) $upd->errors), LOG_WARNING);
            }
        }
        $this->warnings[] = $langs->transnoentitiesnoconv('KSEF_ThirdPartyNotFlaggedSupplier', $soc->name);
        return -1;
    }

    /**
     * @brief Drop unknown products
     * @param array $lineProductMap line_num => product id
     * @return array
     */
    protected function knownProducts($lineProductMap)
    {
        $ids = array();
        foreach ((array) $lineProductMap as $ln => $pid) {
            if ((int) $pid > 0) {
                $ids[(int) $pid] = true;
            }
        }
        if (empty($ids)) {
            return array();
        }
        $found = array();
        $res = $this->db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "product WHERE rowid IN (" . implode(',', array_keys($ids)) . ")"
            . " AND entity IN (" . getEntity('product') . ")");
        while ($res && ($o = $this->db->fetch_object($res))) {
            $found[(int) $o->rowid] = true;
        }
        $out = array();
        foreach ((array) $lineProductMap as $ln => $pid) {
            if (isset($found[(int) $pid])) {
                $out[$ln] = (int) $pid;
            }
        }
        return $out;
    }

    /**
     * @brief Create supplier
     * @param User $user
     * @param $gusData GUS overrides
     * @return int Id, <0 on error
     */
    public function autoCreateSupplier($user, $gusData = null)
    {
        global $langs;
        require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';

        // one supplier
        $locked = (int) $this->id > 0;
        if ($locked) {
            $this->db->begin();
            $resql = $this->db->query("SELECT import_status FROM " . MAIN_DB_PREFIX . $this->table_element . " WHERE rowid = " . (int) $this->id . " FOR UPDATE");
            $obj = $resql ? $this->db->fetch_object($resql) : null;
            if (!$obj || !in_array((string) $obj->import_status, array(self::STATUS_NEW, self::STATUS_ERROR), true)) {
                $this->error = $langs->trans('KSEF_BatchAlreadyProcessed');
                $this->db->rollback();
                return -2;
            }
            $found = $this->findMatchingThirdParty();
            if ($found > 0) {
                $this->db->commit();
                return $found;
            }
        }

        $societe = new Societe($this->db);
        $societe->name = $this->seller_name;
        $societe->fournisseur = 1;
        $societe->client = 0;
        $societe->code_fournisseur = '-1';

        // TEST: fictitious NIPs
        if (!empty($this->seller_nip) && $this->seller_country == 'PL') {
            $nipField = ksefGetFieldName('NIP');
            if (!empty($nipField) && $nipField !== 'tva_intra') {
                $societe->$nipField = $this->seller_nip;
            }
        }
        if (!empty($this->seller_vat_id)) {
            $societe->tva_intra = $this->seller_vat_id;
        } elseif (!empty($this->seller_nip) && $this->seller_country != 'PL') {
            $societe->tva_intra = ($this->seller_country ?: '') . $this->seller_nip;
        }
        $nipField = ksefGetFieldName('NIP');
        if ($nipField === 'tva_intra' && empty($societe->tva_intra) && !empty($this->seller_nip) && $this->seller_country == 'PL') {
            $societe->tva_intra = $this->seller_nip;
        }

        if (!empty($this->fa3_xml)) {
            dol_include_once('/ksef/class/fa3_parser.class.php');
            $parser = new FA3Parser($this->db);
            $parsed = $parser->parse($this->fa3_xml);
            if (!empty($parsed) && !empty($parsed['registries'])) {
                $reg = $parsed['registries'];
                foreach (array('KRS' => 'krs', 'REGON' => 'regon', 'BDO' => 'bdo') as $ident => $key) {
                    if (!empty($reg[$key])) {
                        $field = ksefGetFieldName($ident);
                        if (!empty($field) && $field !== 'tva_intra') {
                            $societe->$field = trim($reg[$key]);
                        }
                    }
                }
            }
        }

        if (!empty($this->seller_address)) {
            $split = ksefSplitAddress($this->seller_address, $this->seller_country ?: 'PL');
            $societe->address = $split['address'];
            if ($split['zip'] !== '') {
                $societe->zip = $split['zip'];
                $societe->town = $split['town'];
            }
        }

        if (!empty($this->seller_country)) {
            require_once DOL_DOCUMENT_ROOT . '/core/lib/company.lib.php';
            $countryId = getCountry($this->seller_country, '3');
            if ($countryId > 0) {
                $societe->country_id = $countryId;
                $societe->country_code = $this->seller_country;
            }
        }

        if (!empty($gusData) && is_array($gusData)) {
            if (!empty($gusData['name'])) {
                $societe->name = $gusData['name'];
            }
            if (!empty($gusData['address'])) {
                $societe->address = $gusData['address'];
            }
            if (!empty($gusData['zip'])) {
                $societe->zip = $gusData['zip'];
            }
            if (!empty($gusData['town'])) {
                $societe->town = $gusData['town'];
            }
            foreach (array('REGON' => 'regon', 'KRS' => 'krs') as $ident => $key) {
                if (!empty($gusData[$key])) {
                    $field = ksefGetFieldName($ident);
                    if (!empty($field) && $field !== 'tva_intra') {
                        $societe->$field = trim($gusData[$key]);
                    }
                }
            }
        }

        $result = $societe->create($user);
        if ($result <= 0) {
            $this->error = $societe->error;
            $this->errors = $societe->errors;
            if ($locked) {
                $this->db->rollback();
            }
            return -1;
        }
        if ($locked) {
            $this->db->commit();
        }

        return $result;
    }

    /**
     * @brief Find or create
     * @param User $user
     * @param array $lineData
     * @param array $warnings Out
     * @return int Product id, <0 on error
     */
    public function autoCreateProduct($user, $lineData, &$warnings = null)
    {
        global $langs;

        $this->created_product_ref = '';

        require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';

        if (!is_array($warnings)) {
            $warnings = array();
        }
        if (in_array((string) $this->invoice_type, array('ZAL', 'KOR_ZAL'), true) || !self::isProductLine($lineData)) {
            return 0;
        }
        $lineNum = (int) ($lineData['line_num'] ?? 0);
        $fallbackRef = $this->productRefPrefix . (int) $this->rowid . '-' . $lineNum;
        $useIndeks = getDolGlobalString('KSEF_PRODUCT_REF_USE_INDEKS', '1') === '1';
        $ref = ($useIndeks && !empty($lineData['indeks'])) ? trim((string) $lineData['indeks']) : '';

        foreach (array_unique(array_filter(array($ref, $ref === '' ? $fallbackRef : ''))) as $lookup) {
            $existing = new Product($this->db);
            if ($existing->fetch(0, $lookup) > 0) {
                return (int) $existing->id;
            }
        }

        $barcode = !empty($lineData['gtin']) ? (string) $lineData['gtin'] : '';
        $result = $this->createProductFromLine($user, $lineData, $ref, $barcode, $product);
        if ($result <= 0 && $ref === '' && ($product->error == 'ProductModuleNotSetupForAutoRef' || trim((string) $product->ref) === '')) {
            $ref = $fallbackRef;
            $result = $this->createProductFromLine($user, $lineData, $ref, $barcode, $product);
        }
        $barcodeErrors = $barcode !== '' ? array_values(array_intersect((array) $product->errors, array('ErrorBarCodeAlreadyUsed', 'ErrorBadBarCodeSyntax'))) : array();
        if ($result <= 0 && $barcodeErrors) {
            $langs->load('errors');
            $warnings[] = $langs->transnoentitiesnoconv('KSEF_BatchProductBarcodeDropped', $lineNum, $barcode, $langs->transnoentitiesnoconv($barcodeErrors[0]));
            $retryRef = $ref !== '' ? $ref : trim((string) $product->ref);
            $result = $this->createProductFromLine($user, $lineData, $retryRef, '', $product);
            if ($result <= 0 && in_array('ErrorBarCodeRequired', (array) $product->errors, true)) {
                $result = $this->createProductFromLine($user, $lineData, $retryRef, 'auto', $product);
            }
        }
        if ($result <= 0 && $product->error == 'ErrorProductAlreadyExists') {
            $existing = new Product($this->db);
            if ($existing->fetch(0, trim((string) $product->ref)) > 0) {
                return (int) $existing->id;
            }
        }
        if ($result <= 0) {
            $errs = array_filter(array_merge(array($product->error), (array) $product->errors));
            $langs->loadLangs(array('errors', 'products'));
            dol_syslog(__METHOD__ . ' product create failed: ' . implode(' | ', $errs), LOG_WARNING);
            $this->error = $errs ? $langs->transnoentitiesnoconv(reset($errs)) : 'Product create failed';
            $this->errors = $errs;
            return -1;
        }
        $this->created_product_ref = (string) $product->ref;
        if (!$this->isOutgoingRecord() && !empty($lineData['socid'])) {
            $this->addSupplierPriceFromLine($user, $result, (int) $lineData['socid'], $lineData);
        }

        return $result;
    }

    /**
     * @brief Add supplier price
     * @param User $user
     * @param int $productId
     * @param int $socid Supplier
     * @param array $lineData
     * @return int 0 skipped, -1 error
     */
    public function addSupplierPriceFromLine($user, $productId, $socid, $lineData)
    {
        global $conf;
        $amt = self::resolveLineAmount($lineData);
        $vatNum = isset($lineData['vat_rate']) && is_numeric($lineData['vat_rate']) ? (float) $lineData['vat_rate'] : 0;
        $netUnit = ($amt['price_base_type'] ?? 'HT') === 'TTC' ? (float) $amt['pu'] / (1 + $vatNum / 100) : (float) $amt['pu'];
        if ($netUnit == 0 || $productId <= 0 || $socid <= 0 || strpos((string) $this->invoice_type, 'KOR') === 0) {
            return 0;
        }
        $foreign = !empty($this->currency) && strtoupper((string) $this->currency) !== 'PLN';
        // "4,2913" not 4
        $rate = $foreign ? (ksefParsedKurs(array('exchange_rate' => array('rate' => $lineData['kurs_waluty'] ?? ''))) ?: $this->getExchangeRate()) : 1;
        if ($rate <= 0) {
            return 0;
        }
        $exists = $this->db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "product_fournisseur_price WHERE fk_product = " . (int) $productId . " AND fk_soc = " . (int) $socid . " LIMIT 1");
        if ($exists && $this->db->num_rows($exists) > 0) {
            return 0;
        }
        require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.product.class.php';
        require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
        $pf = new ProductFournisseur($this->db);
        $supplier = new Societe($this->db);
        if ($pf->fetch($productId) <= 0 || $supplier->fetch($socid) <= 0) {
            return -1;
        }
        $mc = round(abs($netUnit), 8);
        $bp = round($mc * $rate, 8);
        if ($pf->update_buyprice(1, $bp, $user, 'HT', $supplier, 0, (string) (!empty($lineData['indeks']) ? $lineData['indeks'] : $pf->ref), $vatNum,
            0, 0, 0, 0, 0, '', array(), '', $mc, 'HT', 1 / $rate, $foreign ? strtoupper((string) $this->currency) : $conf->currency) < 0) {
            dol_syslog(__METHOD__ . ' supplier price not stored: ' . $pf->error, LOG_WARNING);
            return -1;
        }
        return 1;
    }

    /**
     * @brief Is sales record
     * @return bool
     */
    protected function isOutgoingRecord()
    {
        return false;
    }

    /**
     * @brief Create product
     * @param User $user
     * @param array $lineData
     * @param string $ref '' auto
     * @param string $barcode '' none, 'auto'
     * @param Product $product Out
     * @return int Id, <= 0 on error
     */
    private function createProductFromLine($user, $lineData, $ref, $barcode, &$product)
    {
        $product = new Product($this->db);
        $product->ref = $ref;
        $text = trim((string) ($lineData['description'] ?? ''));
        $firstLine = $text !== '' ? ksefSingleLine(strtok($text, "\n"), 255) : '';
        $product->label = $firstLine !== '' ? $firstLine : ($ref !== '' ? $ref : 'KSeF');
        if ($text !== '' && $text !== $product->label) {
            $product->description = $text;
        }
        $product->type = (int) ($lineData['product_type'] ?? 0) === 1 ? 1 : 0;
        $allowed = ksefCreatableProductTypes($user);
        if (!in_array((int) $product->type, $allowed, true)) {
            if (empty($allowed)) {
                $this->error = ksefMissingRightText('create_product');
                return -1;
            }
            $product->type = (int) $allowed[0];
        }
        $product->status = 1;
        $product->status_buy = 1;

        if ($this->isOutgoingRecord() && !empty($lineData['unit_price_net'])) {
            $product->price = (float)$lineData['unit_price_net'];
            $product->price_base_type = 'HT';
        }

        if (isset($lineData['vat_rate']) && is_numeric($lineData['vat_rate'])) {
            $product->tva_tx = (float)$lineData['vat_rate'];
        }

        if ($barcode !== '') {
            $product->barcode = $barcode;
        }

        return $product->create($user);
    }

    /**
     * @brief Generate PDF
     * @return string|false
     */
    public function generatePdfVisualization()
    {
        dol_include_once('/ksef/class/ksef_invoice_pdf.class.php');

        if (empty($this->rowid)) {
            $this->error = "Invoice not loaded";
            return false;
        }
        if ($this->import_status === self::STATUS_FETCH_ERROR || empty($this->fa3_xml)) {
            global $langs;
            $langs->load('ksef@ksef');
            $this->error = $langs->trans('KSEF_FetchErrorNoPdf');
            return false;
        }

        $pdfGenerator = new KsefInvoicePdf($this->db);
        $pdfContent = $pdfGenerator->generate($this);

        if ($pdfContent === false) {
            $this->error = $pdfGenerator->error ?: 'PDF generation failed';
            return false;
        }

        return $pdfContent;
    }

    /**
     * @brief Map payment method
     * @param string|null $ksefMethod Code 1-7
     * @return int 0 if none
     */
    public function mapKsefPaymentMethod($ksefMethod)
    {
        foreach (ksefImportPaymentModeCodes($ksefMethod) as $code) {
            $sql = "SELECT id FROM " . MAIN_DB_PREFIX . "c_paiement"
                . " WHERE code = '" . $this->db->escape($code) . "'"
                . " AND entity IN (" . getEntity('c_paiement') . ")"
                . " AND active = 1 ORDER BY entity DESC LIMIT 1";
            $resql = $this->db->query($sql);
            if ($resql && ($obj = $this->db->fetch_object($resql))) {
                return (int) $obj->id;
            }
        }
        return 0;
    }
}
