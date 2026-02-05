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
 * \brief   KSeF Incoming Invoice DAO
 */

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';

class KsefIncoming extends CommonObject
{
    public $element = 'ksef_incoming';
    public $table_element = 'ksef_incoming';

    // Database fields
    public $rowid;
    public $ksef_number;
    public $seller_nip;
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
    public $corrected_ksef_number;
    public $corrected_invoice_number;
    public $corrected_invoice_date;
    public $fa3_xml;
    public $fa3_creation_date;
    public $fa3_system_info;
    public $fetch_date;
    public $environment;
    public $fk_facture_fourn;
    public $import_status;
    public $import_date;
    public $import_error;
    public $entity;
    public $picto = 'supplier_invoice';

    // Status
    const STATUS_NEW = 'NEW';
    const STATUS_IMPORTED = 'IMPORTED';
    const STATUS_ERROR = 'ERROR';

    public function __construct($db)
    {
        $this->db = $db;
        $this->import_status = self::STATUS_NEW;
    }


    /**
     * @brief Create new incoming invoice record
     * @param $user User object
     * @param $notrigger Skip triggers flag
     * @return int Record ID or negative on error
     * @called_by createFromParsed()
     */
    public function create($user, $notrigger = 0)
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

        $this->db->begin();

        $sql = "INSERT INTO " . MAIN_DB_PREFIX . $this->table_element . " (";
        $sql .= "ksef_number,";
        $sql .= "seller_nip,";
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
        $sql .= "corrected_ksef_number,";
        $sql .= "corrected_invoice_number,";
        $sql .= "corrected_invoice_date,";
        $sql .= "fa3_xml,";
        $sql .= "fa3_creation_date,";
        $sql .= "fa3_system_info,";
        $sql .= "fetch_date,";
        $sql .= "environment,";
        $sql .= "import_status,";
        $sql .= "entity";
        $sql .= ")";
        $sql .= " VALUES (";
        $sql .= " '" . $this->db->escape($this->ksef_number) . "',";
        $sql .= " " . ($this->seller_nip ? "'" . $this->db->escape($this->seller_nip) . "'" : "NULL") . ",";
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
        $sql .= " " . ($this->corrected_ksef_number ? "'" . $this->db->escape($this->corrected_ksef_number) . "'" : "NULL") . ",";
        $sql .= " " . ($this->corrected_invoice_number ? "'" . $this->db->escape($this->corrected_invoice_number) . "'" : "NULL") . ",";
        $sql .= " " . ($this->corrected_invoice_date ? (int)$this->corrected_invoice_date : "NULL") . ",";
        $sql .= " " . ($this->fa3_xml ? "'" . $this->db->escape($this->fa3_xml) . "'" : "NULL") . ",";
        $sql .= " " . ($this->fa3_creation_date ? (int)$this->fa3_creation_date : "NULL") . ",";
        $sql .= " " . ($this->fa3_system_info ? "'" . $this->db->escape($this->fa3_system_info) . "'" : "NULL") . ",";
        $sql .= " " . (int)$this->fetch_date . ",";
        $sql .= " " . ($this->environment ? "'" . $this->db->escape($this->environment) . "'" : "'TEST'") . ",";
        $sql .= " '" . $this->db->escape($this->import_status ?: self::STATUS_NEW) . "',";
        $sql .= " " . (int)$this->entity;
        $sql .= ")";

        dol_syslog("KsefIncoming::create", LOG_DEBUG);
        $resql = $this->db->query($sql);

        if (!$resql) {
            $error++;
            $this->errors[] = "Error " . $this->db->lasterror();
            dol_syslog("KsefIncoming::create " . $this->db->lasterror(), LOG_ERR);
        }

        if (!$error) {
            $this->rowid = $this->db->last_insert_id(MAIN_DB_PREFIX . $this->table_element);
            $this->id = $this->rowid;

            if (!$notrigger) {
                $result = $this->call_trigger('KSEF_INCOMING_CREATE', $user);
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
            $this->db->rollback();
            return -1 * $error;
        } else {
            $this->db->commit();
            return $this->rowid;
        }
    }


    /**
     * @brief Fetch record by ID
     * @param $id Record ID
     * @param $ksef_number fetch by KSeF number
     * @return int Positive if success, negative if error, 0 if not found
     * @called_by fetchAll(), incoming_card.php
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

        dol_syslog("KsefIncoming::fetch", LOG_DEBUG);
        $resql = $this->db->query($sql);

        if ($resql) {
            if ($this->db->num_rows($resql) > 0) {
                $obj = $this->db->fetch_object($resql);

                $this->rowid = $obj->rowid;
                $this->id = $obj->rowid;
                $this->ksef_number = $obj->ksef_number;
                $this->seller_nip = $obj->seller_nip;
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
                $this->corrected_ksef_number = $obj->corrected_ksef_number;
                $this->corrected_invoice_number = $obj->corrected_invoice_number;
                $this->corrected_invoice_date = $obj->corrected_invoice_date;
                $this->fa3_xml = $obj->fa3_xml;
                $this->fa3_creation_date = $obj->fa3_creation_date;
                $this->fa3_system_info = $obj->fa3_system_info;
                $this->fetch_date = $obj->fetch_date;
                $this->environment = $obj->environment;
                $this->fk_facture_fourn = $obj->fk_facture_fourn;
                $this->import_status = $obj->import_status;
                $this->import_date = $obj->import_date;
                $this->import_error = $obj->import_error;
                $this->entity = $obj->entity;

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
     * @param $notrigger Skip triggers flag
     * @return int Positive if success, negative if error
     * @called_by incoming_card.php import action
     */
    public function update($user, $notrigger = 0)
    {
        $error = 0;

        $this->db->begin();

        $sql = "UPDATE " . MAIN_DB_PREFIX . $this->table_element . " SET ";
        $sql .= "import_status = '" . $this->db->escape($this->import_status) . "',";
        $sql .= "import_date = " . ($this->import_date ? (int)$this->import_date : "NULL") . ",";
        $sql .= "import_error = " . ($this->import_error ? "'" . $this->db->escape($this->import_error) . "'" : "NULL") . ",";
        $sql .= "fk_facture_fourn = " . ($this->fk_facture_fourn ? (int)$this->fk_facture_fourn : "NULL");
        $sql .= " WHERE rowid = " . (int)$this->rowid;

        dol_syslog("KsefIncoming::update", LOG_DEBUG);
        $resql = $this->db->query($sql);

        if (!$resql) {
            $error++;
            $this->errors[] = "Error " . $this->db->lasterror();
        }

        if (!$error && !$notrigger) {
            $result = $this->call_trigger('KSEF_INCOMING_MODIFY', $user);
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
     * @brief Delete record
     * @param $user User object
     * @param $notrigger Skip triggers flag
     * @return int Positive if success, negative if error
     * @called_by incoming_card.php delete action
     */
    public function delete($user, $notrigger = 0)
    {
        $error = 0;

        $this->db->begin();

        if (!$notrigger) {
            $result = $this->call_trigger('KSEF_INCOMING_DELETE', $user);
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
     * @brief Delete all records
     * @param $user User object
     * @param $environment Optional: only delete for specific environment
     * @return int Number of deleted records or -1 on error
     * @called_by incoming_list.php mass action
     */
    public function deleteAll($user, $environment = '')
    {
        global $conf;

        $this->db->begin();

        $sql = "DELETE FROM " . MAIN_DB_PREFIX . $this->table_element;
        $sql .= " WHERE entity IN (" . getEntity($this->element) . ")";

        if (!empty($environment)) {
            $sql .= " AND environment = '" . $this->db->escape($environment) . "'";
        }

        dol_syslog("KsefIncoming::deleteAll", LOG_DEBUG);
        $resql = $this->db->query($sql);

        if (!$resql) {
            $this->error = "Error " . $this->db->lasterror();
            $this->db->rollback();
            return -1;
        }

        $deleted = $this->db->affected_rows($resql);
        $this->db->commit();

        dol_syslog("KsefIncoming::deleteAll deleted $deleted records", LOG_INFO);
        return $deleted;
    }


    /**
     * @brief Check if record exists by KSeF number
     * @param $ksef_number KSeF number
     * @return bool True if exists
     * @called_by KSEF::syncIncomingInvoices()
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
     * @brief Create from parsed FA(3) data
     * @param $parsedData Parsed data from FA3Parser
     * @param $rawXml Raw XML string
     * @param $ksefNumber KSeF reference number
     * @param $environment Environment (TEST/DEMO/PRODUCTION)
     * @param $user User object
     * @return int Record ID or negative on error
     * @called_by KSEF::syncIncomingInvoices()
     * @calls create()
     */
    public function createFromParsed($parsedData, $rawXml, $ksefNumber, $environment, $user)
    {
        $this->ksef_number = $ksefNumber;
        $this->seller_nip = $parsedData['seller']['nip'] ?? '';
        $this->seller_name = $parsedData['seller']['name'] ?? '';
        $this->seller_country = $parsedData['seller']['country'] ?? 'PL';
        $this->seller_address = $parsedData['seller']['address'] ?? '';
        $this->buyer_nip = $parsedData['buyer']['nip'] ?? '';
        $this->buyer_name = $parsedData['buyer']['name'] ?? '';
        $this->invoice_number = $parsedData['invoice']['number'] ?? '';
        $this->invoice_type = $parsedData['invoice']['type'] ?? 'VAT';
        $this->invoice_date = !empty($parsedData['invoice']['date']) ? strtotime($parsedData['invoice']['date']) : null;
        $this->sale_date = !empty($parsedData['invoice']['sale_date']) ? strtotime($parsedData['invoice']['sale_date']) : null;
        $this->currency = $parsedData['invoice']['currency'] ?? 'PLN';
        $this->total_net = $parsedData['invoice']['total_net'] ?? 0;
        $this->total_vat = $parsedData['invoice']['total_vat'] ?? 0;
        $this->total_gross = $parsedData['invoice']['total_gross'] ?? 0;
        if (!empty($parsedData['vat_summary'])) {
            $this->vat_summary = json_encode($parsedData['vat_summary']);
        }
        if (!empty($parsedData['lines'])) {
            $this->line_items = json_encode($parsedData['lines']);
        }
        $this->payment_due_date = !empty($parsedData['payment']['due_date']) ? strtotime($parsedData['payment']['due_date']) : null;
        $this->payment_method = $parsedData['payment']['method'] ?? null;
        $this->bank_account = $parsedData['payment']['bank_account'] ?? null;
        if (!empty($parsedData['correction'])) {
            $firstCorrected = $parsedData['correction']['corrected_invoices'][0] ?? array();
            $this->corrected_ksef_number = $firstCorrected['ksef_number'] ?? null;
            $this->corrected_invoice_number = $firstCorrected['invoice_number'] ?? null;
            $this->corrected_invoice_date = !empty($firstCorrected['invoice_date']) ? strtotime($firstCorrected['invoice_date']) : null;
        }
        $this->fa3_xml = $rawXml;
        $this->fa3_creation_date = !empty($parsedData['header']['creation_date']) ? strtotime($parsedData['header']['creation_date']) : null;
        $this->fa3_system_info = $parsedData['header']['system_info'] ?? '';
        $this->fetch_date = dol_now();
        $this->environment = $environment;
        $this->import_status = self::STATUS_NEW;

        return $this->create($user);
    }


    /**
     * @brief Get line items as array
     * @return array Line items
     * @called_by incoming_card.php
     */
    public function getLineItems()
    {
        if (empty($this->line_items)) {
            return array();
        }
        $decoded = json_decode($this->line_items, true);
        return is_array($decoded) ? $decoded : array();
    }


    /**
     * @brief Get VAT summary as array
     * @return array VAT summary by rate
     * @called_by incoming_card.php
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
     * @brief Fetch all records with filters
     * @param $filters Filter conditions array
     * @param $sortfield Sort field
     * @param $sortorder Sort order (ASC/DESC)
     * @param $limit Limit
     * @param $offset Offset
     * @return array|int Array of objects or -1 on error
     * @called_by incoming_list.php
     * @calls fetch()
     */
    public function fetchAll($filters = array(), $sortfield = 'i.invoice_date', $sortorder = 'DESC', $limit = 0, $offset = 0)
    {
        global $conf;

        $results = array();

        $sql = "SELECT i.rowid FROM " . MAIN_DB_PREFIX . $this->table_element . " as i";
        $sql .= " WHERE i.entity IN (" . getEntity($this->element) . ")";

        if (!empty($filters['seller_nip'])) {
            $sql .= " AND i.seller_nip LIKE '%" . $this->db->escape($filters['seller_nip']) . "%'";
        }
        if (!empty($filters['seller_name'])) {
            $sql .= " AND i.seller_name LIKE '%" . $this->db->escape($filters['seller_name']) . "%'";
        }
        if (!empty($filters['invoice_number'])) {
            $sql .= " AND i.invoice_number LIKE '%" . $this->db->escape($filters['invoice_number']) . "%'";
        }
        if (!empty($filters['ksef_number'])) {
            $sql .= " AND i.ksef_number LIKE '%" . $this->db->escape($filters['ksef_number']) . "%'";
        }
        if (!empty($filters['import_status'])) {
            $sql .= " AND i.import_status = '" . $this->db->escape($filters['import_status']) . "'";
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

        $sql .= " ORDER BY " . $sortfield . " " . $sortorder;

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
                $record = new KsefIncoming($this->db);
                if ($record->fetch($obj->rowid) > 0) {
                    $results[] = $record;
                }
            }
            $this->db->free($resql);
            return $results;
        } else {
            $this->error = "Error " . $this->db->lasterror();
            return -1;
        }
    }


    /**
     * @brief Count records with filters
     * @param $filters Filter conditions array
     * @return int Count or -1 on error
     * @called_by incoming_list.php
     */
    public function countAll($filters = array())
    {
        $sql = "SELECT COUNT(*) as total FROM " . MAIN_DB_PREFIX . $this->table_element . " as i";
        $sql .= " WHERE i.entity IN (" . getEntity($this->element) . ")";

        // Apply same filters as fetchAll
        if (!empty($filters['seller_nip'])) {
            $sql .= " AND i.seller_nip LIKE '%" . $this->db->escape($filters['seller_nip']) . "%'";
        }
        if (!empty($filters['seller_name'])) {
            $sql .= " AND i.seller_name LIKE '%" . $this->db->escape($filters['seller_name']) . "%'";
        }
        if (!empty($filters['invoice_number'])) {
            $sql .= " AND i.invoice_number LIKE '%" . $this->db->escape($filters['invoice_number']) . "%'";
        }
        if (!empty($filters['ksef_number'])) {
            $sql .= " AND i.ksef_number LIKE '%" . $this->db->escape($filters['ksef_number']) . "%'";
        }
        if (!empty($filters['import_status'])) {
            $sql .= " AND i.import_status = '" . $this->db->escape($filters['import_status']) . "'";
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
     * @brief Get statistics for incoming invoices
     * @param $days Number of days to look back
     * @return array Statistics array with total, new, imported, error counts
     * @called_by KSEF::getIncomingStatistics()
     */
    public function getStatistics($days = 30)
    {
        $stats = array(
            'total' => 0,
            'new' => 0,
            'imported' => 0,
            'error' => 0,
        );

        $since = dol_now() - ($days * 86400);

        $sql = "SELECT import_status, COUNT(*) as count FROM " . MAIN_DB_PREFIX . $this->table_element;
        $sql .= " WHERE fetch_date > " . (int)$since;
        $sql .= " GROUP BY import_status";

        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $stats['total'] += $obj->count;
                if ($obj->import_status == self::STATUS_NEW) {
                    $stats['new'] = $obj->count;
                } elseif ($obj->import_status == self::STATUS_IMPORTED) {
                    $stats['imported'] = $obj->count;
                } elseif ($obj->import_status == self::STATUS_ERROR) {
                    $stats['error'] = $obj->count;
                }
            }
            $this->db->free($resql);
        }

        return $stats;
    }


    /**
     * @brief Get list of IDs for mass operations
     * @param $filters Filter conditions array
     * @return array Array of rowids
     * @called_by incoming_list.php mass actions
     */
    public function getIds($filters = array())
    {
        $ids = array();

        $sql = "SELECT i.rowid FROM " . MAIN_DB_PREFIX . $this->table_element . " as i";
        $sql .= " WHERE i.entity IN (" . getEntity($this->element) . ")";

        if (!empty($filters['import_status'])) {
            $sql .= " AND i.import_status = '" . $this->db->escape($filters['import_status']) . "'";
        }
        if (!empty($filters['environment'])) {
            $sql .= " AND i.environment = '" . $this->db->escape($filters['environment']) . "'";
        }

        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $ids[] = $obj->rowid;
            }
            $this->db->free($resql);
        }

        return $ids;
    }


    /**
     * @brief Return clickable link of object (with eventually picto)
     * @param $withpicto Add picto into link
     * @param $option Options
     * @param $notooltip 1=Disable tooltip
     * @param $morecss Add more css on link
     * @param $save_lastsearch_value -1=Auto, 0=No save, 1=Save lastsearch_values
     * @return string String with URL
     * @called_by Various display pages
     */
    public function getNomUrl($withpicto = 0, $option = '', $notooltip = 0, $morecss = '', $save_lastsearch_value = -1)
    {
        global $conf, $langs, $hookmanager;

        if (!empty($conf->dol_no_mouse_hover)) {
            $notooltip = 1;
        }

        $result = '';

        $label = img_picto('', $this->picto) . ' <u class="paddingrightonly">' . $langs->trans("KSEF_IncomingInvoice") . '</u>';
        if (isset($this->import_status)) {
            $label .= ' ' . $this->getLibStatut(5);
        }
        $label .= '<br>';
        $label .= '<b>' . $langs->trans('KSEF_Number') . ':</b> ' . $this->ksef_number;
        if (!empty($this->invoice_number)) {
            $label .= '<br><b>' . $langs->trans('RefSupplierBill') . ':</b> ' . $this->invoice_number;
        }
        if (!empty($this->seller_name)) {
            $label .= '<br><b>' . $langs->trans('Supplier') . ':</b> ' . $this->seller_name;
        }
        if (!empty($this->total_gross)) {
            $label .= '<br><b>' . $langs->trans('AmountTTC') . ':</b> ' . price($this->total_gross, 0, $langs, 1, -1, -1, $this->currency);
        }

        $url = dol_buildpath('/ksef/incoming_card.php', 1) . '?id=' . $this->id;

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
                $label = $langs->trans("ShowKsefIncoming");
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
     * @brief Return the label of the status
     * @param $mode 0=long label, 1=short label, 2=Picto + short label, 3=Picto, 4=Picto + long label, 5=Short label + Picto, 6=Long label + Picto
     * @return string Label of status
     * @called_by getNomUrl()
     * @calls LibStatut()
     */
    public function getLibStatut($mode = 0)
    {
        return $this->LibStatut($this->import_status, $mode);
    }


    /**
     * @brief Return the label of a given status
     * @param $status Status to get label for
     * @param $mode 0=long label, 1=short label, 2=Picto + short label, 3=Picto, 4=Picto + long label, 5=Short label + Picto, 6=Long label + Picto
     * @return string Label of status
     * @called_by getLibStatut()
     */
    public function LibStatut($status, $mode = 0)
    {
        global $langs;
        $langs->load("ksef@ksef");

        $statusType = 'status0';
        $statusLabel = $status;
        $statusLabelShort = $status;

        if ($status == self::STATUS_NEW || $status == 'NEW') {
            $statusType = 'status1';
            $statusLabel = $langs->transnoentitiesnoconv('KSEF_ImportStatusNEW');
            $statusLabelShort = $langs->transnoentitiesnoconv('KSEF_ImportStatusNEW');
        } elseif ($status == self::STATUS_IMPORTED || $status == 'IMPORTED') {
            $statusType = 'status4';
            $statusLabel = $langs->transnoentitiesnoconv('KSEF_ImportStatusIMPORTED');
            $statusLabelShort = $langs->transnoentitiesnoconv('KSEF_ImportStatusIMPORTED');
        } elseif ($status == 'SKIPPED') {
            $statusType = 'status6';
            $statusLabel = $langs->transnoentitiesnoconv('KSEF_ImportStatusSKIPPED');
            $statusLabelShort = $langs->transnoentitiesnoconv('KSEF_ImportStatusSKIPPED');
        } elseif ($status == self::STATUS_ERROR || $status == 'ERROR') {
            $statusType = 'status8';
            $statusLabel = $langs->transnoentitiesnoconv('KSEF_ImportStatusERROR');
            $statusLabelShort = $langs->transnoentitiesnoconv('KSEF_ImportStatusERROR');
        }

        return dolGetStatus($statusLabel, $statusLabelShort, '', $statusType, $mode);
    }


    /**
     * @brief Generate PDF visualization of the invoice
     * @return string|false PDF content as string or false on error
     */
    public function generatePdfVisualization()
    {
        dol_include_once('/ksef/class/ksef_invoice_pdf.class.php');

        if (empty($this->rowid)) {
            $this->error = "Invoice not loaded";
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
}