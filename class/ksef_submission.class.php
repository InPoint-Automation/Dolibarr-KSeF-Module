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
 * \file    ksef/class/ksef_submission.class.php
 * \ingroup ksef
 * \brief   KSeF submission record
 */

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';

/**
 * @brief KSeF send record
 */
class KsefSubmission extends CommonObject
{
    public $element = 'ksef_submission';
    public $table_element = 'ksef_submissions';

    public $rowid;
    public $fk_facture;
    public $ksef_reference;
    public $ksef_number;
    public $ksef_session_reference;
    public $invoice_hash;
    public $source;
    public $status;
    public $environment;
    public $fa3_xml;
    public $upo_xml;
    public $api_response;
    public $date_submission;
    public $date_acceptance;
    public $date_last_check;
    public $error_message;
    public $retry_count;
    public $fk_user_submit;
    public $error_code;
    public $error_details;
    /** @var string */
    public $failure_kind;
    /** @var string */
    public $replaced_number;
    public $offline_mode;
    public $offline_deadline;
    public $offline_detected_reason;
    public $original_invoice_hash;
    public $fa3_creation_date;
    public $invoice_type;
    /** @var string */
    public $locked_fields;
    /** @var array */
    public $lastHeldBack = array();
    const STATUS_PENDING = 'PENDING';
    const STATUS_SUBMITTED = 'SUBMITTED';
    const STATUS_ACCEPTED = 'ACCEPTED';
    const STATUS_REJECTED = 'REJECTED';
    const STATUS_OFFLINE = 'OFFLINE';
    const STATUS_FAILED = 'FAILED';
    const STATUS_TIMEOUT = 'TIMEOUT';
    const SOURCE_SENT = 'sent';
    const SOURCE_SENT_OFFLINE = 'sent_offline';
    const SOURCE_ADOPTED_440 = 'adopted_440';
    const SOURCE_ADOPTED_MANUAL = 'adopted_manual';
    const SOURCE_LINKED_FROM_SYNC = 'linked_from_sync';
    const SOURCE_CARRIED_FORWARD = 'carried_forward';
    /** @var array */
    const HASH_SEARCH_BACKOFF = array(3600, 14400, 86400);
    /** @var int */
    const HASH_SEARCH_MAX_DAYS = 7;

    /**
     * @brief Constructor
     * @param DoliDB|null $db
     */
    public function __construct($db = null)
    {
        $this->db = $db;
        $this->retry_count = 0;
    }

    /**
     * @brief Create record
     * @param $user User
     * @param $notrigger Skip triggers
     * @return int <0 on error
     */
    public function create($user, $notrigger = 0)
    {
        global $conf, $langs;

        $error = 0;
        $this->status = trim((string) $this->status);
        $this->environment = trim((string) $this->environment);
        $this->ksef_reference = trim((string) $this->ksef_reference);
        $this->ksef_number = trim((string) $this->ksef_number);

        if (empty($this->date_submission)) {
            $this->date_submission = dol_now();
        }
        dol_include_once('/ksef/lib/ksef.lib.php');
        $xmlType = ksefXmlInvoiceType($this->fa3_xml);
        if ($xmlType !== '') {
            $this->invoice_type = $xmlType;
        }
        if (!empty($this->fa3_xml) && empty($this->locked_fields)) {
            $this->locked_fields = ksefLockedFieldsSnapshot($this->db, (int) $this->fk_facture);
        }

        $this->db->begin();

        $sql = "INSERT INTO " . MAIN_DB_PREFIX . $this->table_element . " (";
        $sql .= "fk_facture,";
        $sql .= "ksef_reference,";
        $sql .= "ksef_number,";
        $sql .= "ksef_session_reference,";
        $sql .= "invoice_hash,";
        $sql .= "source,";
        $sql .= "status,";
        $sql .= "environment,";
        $sql .= "fa3_xml,";
        $sql .= "fa3_creation_date,";
        $sql .= "upo_xml,";
        $sql .= "api_response,";
        $sql .= "date_submission,";
        $sql .= "date_acceptance,";
        $sql .= "date_last_check,";
        $sql .= "error_message,";
        $sql .= "error_code,";
        $sql .= "error_details,";
        $sql .= "failure_kind,";
        $sql .= "replaced_number,";
        $sql .= "retry_count,";
        $sql .= "fk_user_submit,";
        $sql .= "offline_mode,";
        $sql .= "offline_deadline,";
        $sql .= "offline_detected_reason,";
        $sql .= "original_invoice_hash,";
        $sql .= "invoice_type,";
        $sql .= "locked_fields";
        $sql .= ")";
        $sql .= " VALUES (";
        $sql .= " " . (int)$this->fk_facture . ",";
        $sql .= " " . ($this->ksef_reference ? "'" . $this->db->escape($this->ksef_reference) . "'" : "NULL") . ",";
        $sql .= " " . ($this->ksef_number ? "'" . $this->db->escape($this->ksef_number) . "'" : "NULL") . ",";
        $sql .= " " . ($this->ksef_session_reference ? "'" . $this->db->escape($this->ksef_session_reference) . "'" : "NULL") . ",";
        $sql .= " " . ($this->invoice_hash ? "'" . $this->db->escape($this->invoice_hash) . "'" : "NULL") . ",";
        $sql .= " " . ($this->source ? "'" . $this->db->escape($this->source) . "'" : "NULL") . ",";
        $sql .= " '" . $this->db->escape($this->status) . "',";
        $sql .= " '" . $this->db->escape($this->environment) . "',";
        $sql .= " " . ($this->fa3_xml ? "'" . $this->db->escape($this->fa3_xml) . "'" : "NULL") . ",";
        $sql .= " " . ($this->fa3_creation_date ? (int)$this->fa3_creation_date : "NULL") . ",";
        $sql .= " " . ($this->upo_xml ? "'" . $this->db->escape($this->upo_xml) . "'" : "NULL") . ",";
        $sql .= " " . ($this->api_response ? "'" . $this->db->escape($this->api_response) . "'" : "NULL") . ",";
        $sql .= " " . (int)$this->date_submission . ",";
        $sql .= " " . ($this->date_acceptance ? (int)$this->date_acceptance : "NULL") . ",";
        $sql .= " " . ($this->date_last_check ? (int)$this->date_last_check : "NULL") . ",";
        $sql .= " " . ($this->error_message ? "'" . $this->db->escape($this->error_message) . "'" : "NULL") . ",";
        $sql .= " " . ($this->error_code ? (int)$this->error_code : "NULL") . ",";
        $sql .= " " . ($this->error_details ? "'" . $this->db->escape($this->error_details) . "'" : "NULL") . ",";
        $sql .= " " . ($this->failure_kind ? "'" . $this->db->escape($this->failure_kind) . "'" : "NULL") . ",";
        $sql .= " " . ($this->replaced_number ? "'" . $this->db->escape($this->replaced_number) . "'" : "NULL") . ",";
        $sql .= " " . (int)$this->retry_count . ",";
        $sql .= " " . (int)$user->id . ",";
        $sql .= " " . ($this->offline_mode ? "'" . $this->db->escape($this->offline_mode) . "'" : "NULL") . ",";
        $sql .= " " . ($this->offline_deadline ? (int)$this->offline_deadline : "NULL") . ",";
        $sql .= " " . ($this->offline_detected_reason ? "'" . $this->db->escape($this->offline_detected_reason) . "'" : "NULL") . ",";
        $sql .= " " . ($this->original_invoice_hash ? "'" . $this->db->escape($this->original_invoice_hash) . "'" : "NULL") . ",";
        $sql .= " " . ($this->invoice_type ? "'" . $this->db->escape($this->invoice_type) . "'" : "NULL") . ",";
        $sql .= " " . ($this->locked_fields ? "'" . $this->db->escape($this->locked_fields) . "'" : "NULL");
        $sql .= ")";

        dol_syslog(get_class($this) . "::create", LOG_DEBUG);
        dol_include_once('/ksef/lib/ksef.lib.php');
        $savepoint = ksefDbWriteSavepoint($this->db, 'ksef_submission_write');
        $resql = $this->db->query($sql);

        if (!$resql) {
            $error++;
            $this->errors[] = "Error " . $this->db->lasterror();
            dol_syslog(get_class($this) . "::create " . $this->db->lasterror(), LOG_ERR);
        }

        if (!$error) {
            $this->rowid = $this->db->last_insert_id(MAIN_DB_PREFIX . $this->table_element);
            if ((int) $this->rowid <= 0) {
                $error++;
                $this->errors[] = 'Error no row id after insert';
            }
        }
        if (!$error && $this->rejectTruncated('create', $savepoint)) {
            $error++;
            $this->rowid = 0;
        }
        if (!$error) {
            if ($this->mayWriteInvoiceFields()) {
                ksefUpdateInvoiceExtrafields($this->db, $this->fk_facture, $this->ksef_number, $this->status, $this->date_submission, false);
            }

            if (!$notrigger) {
                $result = $this->call_trigger('KSEF_SUBMISSION_CREATE', $user);
                if ($result < 0) $error++;
            }
        }

        if ($error) {
            foreach ($this->errors as $errmsg) {
                dol_syslog(get_class($this) . "::create " . $errmsg, LOG_ERR);
                $this->error .= ($this->error ? ', ' . $errmsg : $errmsg);
            }
            $this->db->rollback();
            return -1 * $error;
        }
        if (!$this->db->commit()) {
            $this->error = "Error " . $this->db->lasterror();
            dol_syslog(get_class($this) . "::create commit failed " . $this->error, LOG_ERR);
            $this->rowid = 0;
            return -1;
        }
        return $this->rowid;
    }

    /**
     * @brief Update record
     * @param $user User
     * @param $notrigger Skip triggers
     * @param array|null $onlyIfStatus Expected statuses
     * @return int 0 status moved, <0 error
     */
    public function update($user, $notrigger = 0, $onlyIfStatus = null)
    {
        $error = 0;

        $this->status = trim((string) $this->status);
        $this->ksef_reference = trim((string) $this->ksef_reference);
        $this->ksef_number = trim((string) $this->ksef_number);

        if ((int) $this->rowid <= 0) {
            $this->error = 'ErrorRecordNotFound';
            dol_syslog(get_class($this) . "::update without rowid", LOG_ERR);
            return -1;
        }

        dol_include_once('/ksef/lib/ksef.lib.php');
        $xmlType = ksefXmlInvoiceType($this->fa3_xml);
        if ($xmlType !== '') {
            $this->invoice_type = $xmlType;
        }
        $snapshot = '';
        if (!empty($this->fa3_xml)) {
            $chk = $this->db->query("SELECT fk_facture, fa3_xml, locked_fields FROM " . MAIN_DB_PREFIX . $this->table_element . " WHERE rowid = " . (int) $this->rowid);
            $cur = $chk ? $this->db->fetch_object($chk) : null;
            if ($cur && (empty($cur->locked_fields) || (string) $cur->fa3_xml !== (string) $this->fa3_xml)) {
                $snapshot = ksefLockedFieldsSnapshot($this->db, (int) $cur->fk_facture);
            }
        }

        $this->db->begin();

        $sql = "UPDATE " . MAIN_DB_PREFIX . $this->table_element . " SET";
        if ($snapshot !== '') {
            $this->locked_fields = $snapshot;
            $sql .= " locked_fields = '" . $this->db->escape($snapshot) . "',";
        }
        if (!empty($this->invoice_type)) {
            $sql .= " invoice_type = '" . $this->db->escape($this->invoice_type) . "',";
        }
        $sql .= " ksef_reference = " . ($this->ksef_reference ? "'" . $this->db->escape($this->ksef_reference) . "'" : "NULL") . ",";
        $sql .= " ksef_number = " . ($this->ksef_number ? "'" . $this->db->escape($this->ksef_number) . "'" : "NULL") . ",";
        $sql .= " ksef_session_reference = " . ($this->ksef_session_reference ? "'" . $this->db->escape($this->ksef_session_reference) . "'" : "NULL") . ",";
        $sql .= " source = " . ($this->source ? "'" . $this->db->escape($this->source) . "'" : "NULL") . ",";
        $sql .= " fa3_creation_date = " . ($this->fa3_creation_date ? (int)$this->fa3_creation_date : "NULL") . ",";
        $sql .= " invoice_hash = " . ($this->invoice_hash ? "'" . $this->db->escape($this->invoice_hash) . "'" : "NULL") . ",";
        $sql .= " status = '" . $this->db->escape($this->status) . "',";
        $sql .= " api_response = " . ($this->api_response ? "'" . $this->db->escape($this->api_response) . "'" : "NULL") . ",";
        $sql .= " date_acceptance = " . ($this->date_acceptance ? (int)$this->date_acceptance : "NULL") . ",";
        $sql .= " date_last_check = " . ($this->date_last_check ? (int)$this->date_last_check : "NULL") . ",";
        $sql .= " error_message = " . ($this->error_message ? "'" . $this->db->escape($this->error_message) . "'" : "NULL") . ",";
        $sql .= " error_code = " . ($this->error_code ? (int)$this->error_code : "NULL") . ",";
        $sql .= " error_details = " . ($this->error_details ? "'" . $this->db->escape($this->error_details) . "'" : "NULL") . ",";
        $sql .= " failure_kind = " . ($this->failure_kind ? "'" . $this->db->escape($this->failure_kind) . "'" : "NULL") . ",";
        $sql .= " retry_count = " . (int)$this->retry_count . ",";
        $sql .= " offline_mode = " . ($this->offline_mode ? "'" . $this->db->escape($this->offline_mode) . "'" : "NULL") . ",";
        $sql .= " offline_deadline = " . ($this->offline_deadline ? (int)$this->offline_deadline : "NULL") . ",";
        $sql .= " offline_detected_reason = " . ($this->offline_detected_reason ? "'" . $this->db->escape($this->offline_detected_reason) . "'" : "NULL") . ",";
        $sql .= " original_invoice_hash = " . ($this->original_invoice_hash ? "'" . $this->db->escape($this->original_invoice_hash) . "'" : "NULL") . ",";
        $sql .= " fa3_xml = " . ($this->fa3_xml ? "'" . $this->db->escape($this->fa3_xml) . "'" : "NULL") . ",";
        $sql .= " upo_xml = " . ($this->upo_xml ? "'" . $this->db->escape($this->upo_xml) . "'" : "NULL");
        $sql .= " WHERE rowid = " . (int)$this->rowid;
        if (is_array($onlyIfStatus) && $onlyIfStatus) {
            $sql .= " AND status IN ('" . implode("','", array_map(array($this->db, 'escape'), $onlyIfStatus)) . "')";
        }

        dol_syslog(get_class($this) . "::update", LOG_DEBUG);
        dol_include_once('/ksef/lib/ksef.lib.php');
        $savepoint = ksefDbWriteSavepoint($this->db, 'ksef_submission_write');
        $resql = $this->db->query($sql);
        $affected = $resql ? $this->db->affected_rows($resql) : 0;

        if (!$resql) {
            $error++;
            $this->errors[] = "Error " . $this->db->lasterror();
            dol_syslog(get_class($this) . "::update " . $this->db->lasterror(), LOG_ERR);
        } elseif ($this->rejectTruncated('update', $savepoint)) {
            $error++;
        } elseif ($affected == 0) {
            // MySQL: changed rows
            $chk = $this->db->query("SELECT rowid, status FROM " . MAIN_DB_PREFIX . $this->table_element . " WHERE rowid = " . (int) $this->rowid);
            $cur = ($chk && $this->db->num_rows($chk)) ? $this->db->fetch_object($chk) : null;
            if (!$cur) {
                $error++;
                $this->errors[] = 'ErrorRecordNotFound';
            } elseif (is_array($onlyIfStatus) && $onlyIfStatus && !in_array($cur->status, $onlyIfStatus, true)) {
                $this->db->rollback();
                $this->error = 'StatusChanged';
                dol_syslog(get_class($this) . "::update row " . (int) $this->rowid . " is " . $cur->status . " now, not overwritten", LOG_WARNING);
                return 0;
            }
        }

        if (!$error) {
            if (empty($this->fk_facture)) {
                $old_obj = new KsefSubmission($this->db);
                $old_obj->fetch($this->rowid);
                $this->fk_facture = $old_obj->fk_facture;
            }
            if ($this->mayWriteInvoiceFields()) {
                ksefUpdateInvoiceExtrafields($this->db, $this->fk_facture, $this->ksef_number, $this->status, $this->date_submission, false);
            }
        }

        if (!$error && !$notrigger) {
            $result = $this->call_trigger('KSEF_SUBMISSION_UPDATE', $user);
            if ($result < 0) $error++;
        }

        if ($error) {
            foreach ($this->errors as $errmsg) {
                dol_syslog(get_class($this) . "::update " . $errmsg, LOG_ERR);
                $this->error .= ($this->error ? ', ' . $errmsg : $errmsg);
            }
            $this->db->rollback();
            return -1 * $error;
        }
        if (!$this->db->commit()) {
            $this->error = "Error " . $this->db->lasterror();
            dol_syslog(get_class($this) . "::update commit failed " . $this->error, LOG_ERR);
            return -1;
        }
        return 1;
    }

    /**
     * @brief May set fields
     * @return bool
     */
    private function mayWriteInvoiceFields()
    {
        $env = (string) $this->environment;
        if ($env === '' && (int) $this->rowid > 0) {
            $res = $this->db->query("SELECT environment FROM " . MAIN_DB_PREFIX . $this->table_element . " WHERE rowid = " . (int) $this->rowid);
            $o = $res ? $this->db->fetch_object($res) : null;
            $env = $o ? (string) $o->environment : '';
        }
        if ((int) $this->fk_facture <= 0) {
            return true;
        }
        if ((int) $this->rowid > 0) {
            $res = $this->db->query("SELECT status FROM " . MAIN_DB_PREFIX . $this->table_element . " WHERE fk_facture = " . (int) $this->fk_facture
                . " AND environment = '" . $this->db->escape($env) . "' AND rowid > " . (int) $this->rowid);
            $newer = false;
            $newerIssued = false;
            while ($res && ($o = $this->db->fetch_object($res))) {
                $newer = true;
                $newerIssued = $newerIssued || in_array((string) $o->status, array(self::STATUS_ACCEPTED, self::STATUS_OFFLINE), true);
            }
            if ($newer && ((string) $this->status !== self::STATUS_ACCEPTED || $newerIssued)) {
                return false;
            }
        }
        if ($env === 'PRODUCTION') {
            return true;
        }
        dol_include_once('/ksef/lib/ksef.lib.php');
        foreach (ksefIssuedRows($this->db, (int) $this->fk_facture) as $r) {
            if ($r['environment'] === 'PRODUCTION') {
                return false;
            }
        }
        return true;
    }

    /**
     * @brief Undo truncated write
     * @param string $method For log
     * @param string $savepoint
     * @return bool True when undone
     */
    private function rejectTruncated($method, $savepoint)
    {
        $why = ksefDbRejectTruncated($this->db, $savepoint, get_class($this) . '::' . $method . ' row ' . (int) $this->rowid);
        if ($why === '') {
            return false;
        }
        $this->errors[] = $why;
        return true;
    }

    /**
     * @brief Store locked fields
     * @param string $json
     * @return int -1 on error
     */
    public function setLockedFields($json)
    {
        if ((int) $this->rowid <= 0 || (string) $json === '') {
            return -1;
        }
        dol_include_once('/ksef/lib/ksef.lib.php');
        $this->db->begin();
        $savepoint = ksefDbWriteSavepoint($this->db, 'ksef_submission_write');
        if (!$this->db->query("UPDATE " . MAIN_DB_PREFIX . $this->table_element . " SET locked_fields = '" . $this->db->escape($json) . "', tms = tms WHERE rowid = " . (int) $this->rowid)
            || $this->rejectTruncated('setLockedFields', $savepoint)) {
            $this->error = $this->errors ? implode(', ', $this->errors) : $this->db->lasterror();
            $this->db->rollback();
            return -1;
        }
        $this->db->commit();
        $this->locked_fields = $json;
        return 1;
    }

    /**
     * @brief Delete record
     * @param User $user
     * @return int -1 on error
     */
    public function delete($user)
    {
        if (empty($this->rowid)) {
            return -1;
        }
        $fk = (int) $this->fk_facture;
        $latest = new KsefSubmission($this->db);
        $wasLatest = $latest->fetchByInvoice($fk) > 0 && (int) $latest->rowid === (int) $this->rowid;

        $this->db->begin();
        if (!$this->db->query("DELETE FROM " . MAIN_DB_PREFIX . $this->table_element . " WHERE rowid = " . (int) $this->rowid)) {
            $this->error = $this->db->lasterror();
            $this->db->rollback();
            return -1;
        }
        if ($wasLatest) {
            dol_include_once('/ksef/lib/ksef.lib.php');
            $next = new KsefSubmission($this->db);
            $found = $next->fetchByInvoice($fk);
            if ($found > 0 && !$next->mayWriteInvoiceFields()) {
                $found = $next->fetchByInvoice($fk, 'PRODUCTION');
            }
            if ($found > 0) {
                $res = ksefUpdateInvoiceExtrafields($this->db, $fk, (string) $next->ksef_number, (string) $next->status, $next->date_submission ? $next->date_submission : '');
            } else {
                $res = ksefUpdateInvoiceExtrafields($this->db, $fk, '', '', '');
            }
            if ($res < 0) {
                $this->error = 'extrafields';
                $this->db->rollback();
                return -1;
            }
        }
        $this->db->commit();
        dol_syslog("KsefSubmission::delete row " . (int) $this->rowid . " (invoice " . $fk . ", " . $this->status . ", " . $this->environment . ") by " . (is_object($user) ? $user->login : '?'), LOG_WARNING);
        return 1;
    }

    /**
     * @brief Fetch submission
     * @param $id Submission id
     * @param $fk_facture Invoice id
     * @param string $environment '' any, '*' latest
     * @return int 0 not found, -1 error
     */
    public function fetch($id = 0, $fk_facture = 0, $environment = '')
    {
        $sql = "SELECT " . self::selectColumns();
        $sql .= " FROM " . MAIN_DB_PREFIX . $this->table_element . " as t";

        if ($id > 0) {
            $sql .= " WHERE t.rowid = " . (int)$id;
        } elseif ($fk_facture > 0) {
            $sql .= " WHERE t.fk_facture = " . (int)$fk_facture;
            dol_include_once('/ksef/lib/ksef.lib.php');
            if ((string) $environment !== '' && $environment !== '*') {
                $sql .= " AND t.environment = '" . $this->db->escape((string) $environment) . "'";
            }
            $sql .= " ORDER BY " . ($environment === '*' ? "" : "CASE WHEN t.environment = '" . $this->db->escape(ksefGetEnvironment()) . "' THEN 0 ELSE 1 END, ")
                . "t.date_submission DESC, t.rowid DESC LIMIT 1";
        } else {
            $this->error = 'ErrorBadParameters';
            return -1;
        }

        dol_syslog(get_class($this) . "::fetch", LOG_DEBUG);
        $resql = $this->db->query($sql);

        if ($resql) {
            $num = $this->db->num_rows($resql);
            if ($num) {
                $obj = $this->db->fetch_object($resql);
                $this->setFromRow($obj);
            }
            $this->db->free($resql);
            return $num ? 1 : 0;
        } else {
            $this->error = "Error " . $this->db->lasterror();
            dol_syslog(get_class($this) . "::fetch " . $this->db->lasterror(), LOG_ERR);
            return -1;
        }
    }

    /**
     * @brief Select columns
     * @param bool $blobs With XML, else has_fa3_xml / has_upo_xml
     * @return string
     */
    public static function selectColumns($blobs = true)
    {
        $xml = $blobs ? "t.fa3_xml, t.upo_xml"
            : "CASE WHEN t.fa3_xml IS NULL OR t.fa3_xml = '' THEN 0 ELSE 1 END as has_fa3_xml, CASE WHEN t.upo_xml IS NULL OR t.upo_xml = '' THEN 0 ELSE 1 END as has_upo_xml";
        return "t.rowid, t.fk_facture, t.ksef_reference, t.ksef_number, t.ksef_session_reference, t.invoice_hash, t.source, t.status, t.environment, "
            . $xml . ", t.fa3_creation_date, t.api_response, t.date_submission, t.date_acceptance, t.date_last_check, t.error_message, t.error_code, t.error_details,"
            . " t.failure_kind, t.replaced_number, t.retry_count, t.fk_user_submit, t.offline_mode, t.offline_deadline, t.offline_detected_reason,"
            . " t.original_invoice_hash, t.invoice_type, t.locked_fields";
    }

    /**
     * @brief Load from row
     * @param object $obj
     * @return void
     */
    public function setFromRow($obj)
    {
        foreach (get_object_vars($obj) as $k => $v) {
            if (property_exists($this, $k)) {
                $this->$k = $v;
            }
        }
    }

    /**
     * @brief Session reference
     * @return string
     */
    public function getSessionReference()
    {
        if (!empty($this->ksef_session_reference)) {
            return (string) $this->ksef_session_reference;
        }
        if (!empty($this->api_response)) {
            $resp = json_decode($this->api_response, true);
            if (is_array($resp) && !empty($resp['session_reference'])) {
                return (string) $resp['session_reference'];
            }
        }
        if (!empty($this->ksef_reference) && strpos($this->ksef_reference, '-SO-') !== false) {
            return (string) $this->ksef_reference;
        }
        return '';
    }

    /**
     * @brief Error details array
     * @return array|null
     */
    public function getErrorDetailsArray()
    {
        if (empty($this->error_details)) return null;
        $details = json_decode($this->error_details, true);
        return is_array($details) ? $details : null;
    }

    /**
     * @brief Pending submissions
     * @param $environment Environment
     * @param $max_age Seconds
     * @return array|false
     */
    public function fetchPending($environment = null, $max_age = 86400)
    {
        $results = array();

        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . $this->table_element;
        $sql .= " WHERE status IN ('" . self::STATUS_PENDING . "', '" . self::STATUS_SUBMITTED . "', '" . self::STATUS_TIMEOUT . "')";

        if ($environment) $sql .= " AND environment = '" . $this->db->escape($environment) . "'";

        $sql .= " AND date_submission > " . (dol_now() - $max_age);
        $sql .= " ORDER BY date_submission ASC";

        $resql = $this->db->query($sql);

        if ($resql) {
            $num = $this->db->num_rows($resql);
            for ($i = 0; $i < $num; $i++) {
                $obj = $this->db->fetch_object($resql);
                $submission = new KsefSubmission($this->db);
                if ($submission->fetch($obj->rowid) > 0) $results[] = $submission;
            }
            $this->db->free($resql);
            return $results;
        } else {
            $this->error = "Error " . $this->db->lasterror();
            dol_syslog(get_class($this) . "::fetchPending " . $this->db->lasterror(), LOG_ERR);
            return false;
        }
    }

    /**
     * @brief Offline rows waiting
     * @param string $environment
     * @param int $limit
     * @param array $exclude Handled row ids
     * @return array|false
     */
    public function fetchOfflineWaiting($environment, $limit = 10, $exclude = array())
    {
        $results = array();
        $this->lastHeldBack = array();
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . $this->table_element;
        $sql .= " WHERE status = '" . self::STATUS_OFFLINE . "'";
        $sql .= " AND environment = '" . $this->db->escape($environment) . "'";
        $sql .= " AND fa3_xml IS NOT NULL AND invoice_hash IS NOT NULL";
        $sql .= $this->excludeSql(MAIN_DB_PREFIX . $this->table_element . ".rowid", $exclude);
        $sql .= " AND NOT EXISTS (SELECT 1 FROM " . MAIN_DB_PREFIX . $this->table_element . " n WHERE n.fk_facture = "
            . MAIN_DB_PREFIX . $this->table_element . ".fk_facture AND n.environment = " . MAIN_DB_PREFIX . $this->table_element . ".environment AND n.rowid > " . MAIN_DB_PREFIX . $this->table_element . ".rowid)";
        $sql .= " ORDER BY date_submission ASC, rowid ASC";
        $skipProduction = ($environment !== 'PRODUCTION');
        dol_include_once('/ksef/lib/ksef.lib.php');
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = "Error " . $this->db->lasterror();
            return false;
        }
        while ($obj = $this->db->fetch_object($resql)) {
            if (count($results) >= (int) $limit) {
                break;
            }
            $submission = new KsefSubmission($this->db);
            if ($submission->fetch($obj->rowid) > 0) {
                if ($skipProduction && ksefProductionIssuedBlock($this->db, (int) $submission->fk_facture, $environment)) {
                    continue;
                }
                $latest = new KsefSubmission($this->db);
                if ($latest->fetchByInvoice($submission->fk_facture, $environment) > 0 && (int) $latest->rowid === (int) $submission->rowid) {
                    if ($this->isHeldBackCorrection((int) $submission->fk_facture, $environment)) {
                        $this->lastHeldBack[] = (int) $submission->rowid;
                        continue;
                    }
                    $results[] = $submission;
                }
            }
        }
        return $results;
    }

    /**
     * @brief Correction awaiting original
     * @param int $fk_facture
     * @param string $environment
     * @return bool
     */
    private function isHeldBackCorrection($fk_facture, $environment)
    {
        $res = $this->db->query("SELECT type, fk_facture_source FROM " . MAIN_DB_PREFIX . "facture WHERE rowid = " . (int) $fk_facture);
        $o = $res ? $this->db->fetch_object($res) : null;
        if (!$o || !in_array((int) $o->type, array(1, 2), true) || (int) $o->fk_facture_source <= 0) {
            return false;
        }
        return ksefSourceAwaitingRow($this->db, (int) $o->fk_facture_source, $environment) !== null;
    }

    /**
     * @brief Exclude rows filter
     * @param string $col Rowid column
     * @param array $exclude Row ids
     * @return string
     */
    private function excludeSql($col, $exclude)
    {
        $ids = array_filter(array_map('intval', (array) $exclude));
        return $ids ? " AND " . $col . " NOT IN (" . implode(',', $ids) . ")" : '';
    }

    /**
     * @brief Hash search due
     * @param string $environment
     * @param int $limit
     * @param int $max_age Seconds
     * @param array $exclude Handled row ids
     * @return array|false
     */
    public function fetchNoRefOlder($environment, $limit = 5, $max_age = 86400, $exclude = array())
    {
        $results = array();
        $t = MAIN_DB_PREFIX . $this->table_element;
        $sql = "SELECT rowid, date_submission, date_last_check, error_details FROM " . $t;
        $sql .= " WHERE status = '" . self::STATUS_PENDING . "'" . $this->excludeSql($t . ".rowid", $exclude);
        $sql .= " AND environment = '" . $this->db->escape($environment) . "'";
        $sql .= " AND date_submission <= " . (dol_now() - $max_age);
        $sql .= " AND offline_mode IS NULL AND ksef_session_reference IS NULL AND invoice_hash IS NOT NULL";
        $sql .= " AND NOT EXISTS (SELECT 1 FROM " . $t . " n WHERE n.fk_facture = " . $t . ".fk_facture AND n.environment = " . $t . ".environment AND n.rowid > " . $t . ".rowid"
            . " AND " . self::notPlainFailureSql('n') . ")";
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = "Error " . $this->db->lasterror();
            return false;
        }
        $now = dol_now();
        $due = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $probe = new KsefSubmission($this->db);
            $probe->status = self::STATUS_PENDING;
            $probe->date_submission = (int) $obj->date_submission;
            $probe->date_last_check = (int) $obj->date_last_check;
            $probe->error_details = $obj->error_details;
            $at = $probe->hashSearchDueAt();
            if ($at <= $now) {
                $due[] = array('at' => $at, 'id' => (int) $obj->rowid);
            }
        }
        $this->db->free($resql);
        usort($due, function ($a, $b) {
            return array($a['at'], $a['id']) <=> array($b['at'], $b['id']);
        });
        foreach (array_slice($due, 0, max(0, (int) $limit)) as $d) {
            $submission = new KsefSubmission($this->db);
            if ($submission->fetch($d['id']) > 0) {
                $results[] = $submission;
            }
        }
        return $results;
    }

    /**
     * @brief Stale sales rows
     * @param string $environment
     * @param int $limit
     * @param array $exclude Handled row ids
     * @return array|false
     */
    public function fetchNoRefInSales($environment, $limit = 20, $exclude = array())
    {
        $results = array();
        $t = MAIN_DB_PREFIX . $this->table_element;
        $sql = "SELECT rowid FROM " . $t;
        $sql .= " WHERE status IN ('" . self::STATUS_PENDING . "', '" . self::STATUS_SUBMITTED . "', '" . self::STATUS_TIMEOUT . "')";
        $sql .= $this->excludeSql($t . ".rowid", $exclude);
        $sql .= " AND environment = '" . $this->db->escape($environment) . "'";
        $sql .= " AND date_submission <= " . (dol_now() - ksefSendStaleAfter());
        $sql .= " AND offline_mode IS NULL AND ksef_session_reference IS NULL AND invoice_hash IS NOT NULL";
        $sql .= " AND EXISTS (SELECT 1 FROM " . MAIN_DB_PREFIX . "ksef_sales s, " . MAIN_DB_PREFIX . "facture f WHERE s.invoice_hash = " . $t . ".invoice_hash AND s.environment = " . $t . ".environment"
            . " AND f.rowid = " . $t . ".fk_facture AND s.invoice_number = f.ref AND s.entity = f.entity AND (s.fk_facture IS NULL OR s.fk_facture = 0 OR s.fk_facture = " . $t . ".fk_facture)"
            . " AND NOT EXISTS (SELECT 1 FROM " . $t . " a WHERE a.ksef_number = s.ksef_number AND a.status = '" . self::STATUS_ACCEPTED . "' AND a.rowid <> " . $t . ".rowid))";
        $sql .= " AND NOT EXISTS (SELECT 1 FROM " . $t . " n WHERE n.fk_facture = " . $t . ".fk_facture AND n.environment = " . $t . ".environment AND n.rowid > " . $t . ".rowid)";
        $sql .= " ORDER BY date_submission ASC, rowid ASC";
        $sql .= $this->db->plimit((int) $limit);
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = "Error " . $this->db->lasterror();
            return false;
        }
        while ($obj = $this->db->fetch_object($resql)) {
            $submission = new KsefSubmission($this->db);
            if ($submission->fetch($obj->rowid) > 0) {
                $results[] = $submission;
            }
        }
        return $results;
    }

    /**
     * @brief Hash search note
     * @return array
     */
    public function getHashSearchNote()
    {
        $d = $this->getErrorDetailsArray();
        $n = (is_array($d) && isset($d['hash_search']) && is_array($d['hash_search'])) ? $d['hash_search'] : array();
        return array(
            'attempts' => (int) ($n['attempts'] ?? 0),
            'read' => (int) ($n['read'] ?? 0),
            'listed' => (int) ($n['listed'] ?? 0),
            'complete' => !empty($n['complete']),
            'outcome' => (string) ($n['outcome'] ?? ''),
            'date' => (int) ($n['date'] ?? 0),
            'final' => !empty($n['final']),
            'seen' =>(isset($n['seen']) && is_array($n['seen'])) ? array_values(array_map('strval', $n['seen'])) : array(),
        );
    }

    /**
     * @brief Next hash search
     * @return int 0 now, PHP_INT_MAX never
     */
    public function hashSearchDueAt()
    {
        $last = (int) $this->date_last_check;
        if ($last <= 0 || $last < (int) $this->date_submission + ksefSendStaleAfter()) {
            return 0;
        }
        $note = $this->getHashSearchNote();
        if ($note['final']) {
            return PHP_INT_MAX;
        }
        $steps = self::HASH_SEARCH_BACKOFF;
        $i = min(max(1, $note['attempts']), count($steps)) - 1;
        $at = $last + $steps[$i];
        $end = $this->hashSearchEndsAt();
        return $last < $end ? min($at, $end) : $at;
    }

    /**
     * @brief Hash search end
     * @return int
     */
    public function hashSearchEndsAt()
    {
        return (int) $this->date_submission + self::HASH_SEARCH_MAX_DAYS * 86400;
    }

    /**
     * @brief Close replaced sends
     * @param User $user
     * @param string $environment
     * @param int $fk_facture 0 for all
     * @return int -1 on error
     */
    public function closeReplacedOpen($user, $environment, $fk_facture = 0)
    {
        global $langs;
        $langs->load('ksef@ksef');
        $t = MAIN_DB_PREFIX . $this->table_element;
        $open = "'" . self::STATUS_PENDING . "', '" . self::STATUS_SUBMITTED . "', '" . self::STATUS_TIMEOUT . "'";
        $sql = "SELECT o.rowid, a.rowid AS by_row, a.ksef_number FROM " . $t . " o, " . $t . " a"
            . " WHERE o.status IN (" . $open . ") AND o.offline_mode IS NULL AND o.environment = '" . $this->db->escape((string) $environment) . "'"
            . ((int) $fk_facture > 0 ? " AND o.fk_facture = " . (int) $fk_facture : '')
            . " AND a.fk_facture = o.fk_facture AND a.environment = o.environment AND a.rowid > o.rowid AND a.status = '" . self::STATUS_ACCEPTED . "'"
            . " AND a.ksef_number IS NOT NULL AND a.ksef_number <> '' AND a.ksef_number NOT LIKE 'OFFLINE%'"
            . " ORDER BY o.rowid ASC, a.rowid DESC";
        $res = $this->db->query($sql);
        if (!$res) {
            $this->error = "Error " . $this->db->lasterror();
            return -1;
        }
        $rows = array();
        while ($o = $this->db->fetch_object($res)) {
            if (!isset($rows[(int) $o->rowid])) {
                $rows[(int) $o->rowid] = array('by' => (int) $o->by_row, 'number' => (string) $o->ksef_number);
            }
        }
        $this->db->free($res);
        $now = dol_now();
        $closed = 0;
        foreach ($rows as $id => $r) {
            $details = json_encode(array('reason' => 'REPLACED', 'by_row' => $r['by'], 'ksef_number' => $r['number'], 'date' => $now));
            $msg = $langs->transnoentitiesnoconv('KSEF_ReplacedByAcceptedNote', $r['number']);
            $ok = $this->db->query("UPDATE " . $t . " SET status = '" . self::STATUS_FAILED . "', failure_kind = 'unknown', error_code = NULL"
                . ", error_message = '" . $this->db->escape($msg) . "', error_details = '" . $this->db->escape($details) . "', date_last_check = " . (int) $now
                . (is_object($user) && !empty($user->id) ? ", fk_user_last_update = " . (int) $user->id : '')
                . " WHERE rowid = " . (int) $id . " AND status IN (" . $open . ") AND offline_mode IS NULL");
            if (!$ok) {
                $this->error = "Error " . $this->db->lasterror();
                return -1;
            }
            if ($this->db->affected_rows($ok) > 0) {
                $closed++;
                dol_syslog("KsefSubmission::closeReplacedOpen row " . $id . " closed: row " . $r['by'] . " of the same invoice was accepted as " . $r['number'], LOG_INFO);
            }
        }
        return $closed;
    }

    /**
     * @brief Not-plain-failure SQL
     * @param string $alias
     * @return string
     */
    private static function notPlainFailureSql($alias)
    {
        return "NOT (" . $alias . ".status IN ('" . self::STATUS_FAILED . "', '" . self::STATUS_REJECTED . "') AND COALESCE(" . $alias . ".error_code, 0) <> 440)";
    }

    /**
     * @brief Only failures after
     * @return bool
     */
    public function onlyFailedSendsAfter()
    {
        $res = $this->db->query("SELECT n.rowid FROM " . MAIN_DB_PREFIX . $this->table_element . " n WHERE n.fk_facture = " . (int) $this->fk_facture
            . " AND n.environment = '" . $this->db->escape((string) $this->environment) . "' AND n.rowid > " . (int) $this->rowid
            . " AND " . self::notPlainFailureSql('n') . $this->db->plimit(1));
        return $res && (int) $this->db->num_rows($res) === 0;
    }

    /**
     * @brief Hold behind 440
     * @param User $user
     * @param string $environment
     * @return array -1 on error
     */
    public function holdBehindDuplicate($user, $environment)
    {
        global $langs;
        $langs->load('ksef@ksef');
        dol_include_once('/ksef/lib/ksef.lib.php');
        $t = MAIN_DB_PREFIX . $this->table_element;
        $open = "'" . self::STATUS_PENDING . "', '" . self::STATUS_SUBMITTED . "', '" . self::STATUS_TIMEOUT . "'";
        $sql = "SELECT o.rowid, o.error_details, o.fa3_xml AS o_xml, l.rowid AS l_id, l.error_details AS l_details, l.fa3_xml AS l_xml FROM " . $t . " o, " . $t . " l"
            . " WHERE o.status IN (" . $open . ") AND o.offline_mode IS NULL AND o.environment = '" . $this->db->escape((string) $environment) . "'"
            . " AND l.fk_facture = o.fk_facture AND l.environment = o.environment AND l.rowid > o.rowid"
            . " AND l.status IN ('" . self::STATUS_FAILED . "', '" . self::STATUS_REJECTED . "') AND l.error_code = 440"
            . " AND NOT EXISTS (SELECT 1 FROM " . $t . " n WHERE n.fk_facture = l.fk_facture AND n.environment = l.environment AND n.rowid > l.rowid)"
            . " ORDER BY o.rowid ASC";
        $res = $this->db->query($sql);
        if (!$res) {
            $this->error = "Error " . $this->db->lasterror();
            return array('held' => -1, 'closed' => -1);
        }
        $rows = array();
        while ($o = $this->db->fetch_object($res)) {
            $rows[] = $o;
        }
        $this->db->free($res);
        $now = dol_now();
        $out = array('held' => 0, 'closed' => 0);
        foreach ($rows as $o) {
            $mine = json_decode((string) $o->error_details, true);
            $mine = is_array($mine) ? $mine : array();
            $theirs = json_decode((string) $o->l_details, true);
            $theirs = is_array($theirs) ? $theirs : array();
            $number = (string) ($theirs['original_ksef_number'] ?? '');
            $sameNumber = ksefXmlInvoiceType((string) $o->o_xml) !== '' && ksefXmlInvoiceType((string) $o->o_xml) === ksefXmlInvoiceType((string) $o->l_xml)
                && self::xmlInvoiceNumber((string) $o->o_xml) !== '' && self::xmlInvoiceNumber((string) $o->o_xml) === self::xmlInvoiceNumber((string) $o->l_xml);
            if (($theirs['adopt'] ?? '') === 'hash_mismatch' && $number !== '' && $sameNumber) {
                $details = json_encode(array('reason' => 'NOT_RECEIVED', 'not_found' => 'other_holds', 'by_row' => (int) $o->l_id, 'ksef_number' => $number, 'date' => $now));
                $msg = $langs->transnoentitiesnoconv('KSEF_NotFoundInKsefOtherHolds', $number);
                $ok = $this->db->query("UPDATE " . $t . " SET status = '" . self::STATUS_FAILED . "', failure_kind = 'unknown', error_code = NULL"
                    . ", error_message = '" . $this->db->escape($msg) . "', error_details = '" . $this->db->escape($details) . "', date_last_check = " . (int) $now
                    . (is_object($user) && !empty($user->id) ? ", fk_user_last_update = " . (int) $user->id : '')
                    . " WHERE rowid = " . (int) $o->rowid . " AND status IN (" . $open . ") AND offline_mode IS NULL");
                if ($ok && $this->db->affected_rows($ok) > 0) {
                    $out['closed']++;
                    dol_syslog("KsefSubmission::holdBehindDuplicate row " . (int) $o->rowid . " closed: KSeF holds another file under " . $number . " (row " . (int) $o->l_id . ")", LOG_INFO);
                }
                continue;
            }
            $held = isset($mine['held']) && is_array($mine['held']) ? $mine['held'] : array();
            if ((int) ($held['by_row'] ?? 0) === (int) $o->l_id && (string) ($held['ksef_number'] ?? '') === $number) {
                continue;
            }
            $mine['held'] = array('reason' => 'duplicate', 'by_row' => (int) $o->l_id, 'ksef_number' => $number, 'date' => $now);
            $msg = $number !== '' ? $langs->transnoentitiesnoconv('KSEF_HeldByDuplicate', $number) : $langs->transnoentitiesnoconv('KSEF_HeldByDuplicateNoNumber');
            $ok = $this->db->query("UPDATE " . $t . " SET error_message = '" . $this->db->escape($msg) . "', error_details = '" . $this->db->escape(json_encode($mine)) . "'"
                . " WHERE rowid = " . (int) $o->rowid . " AND status IN (" . $open . ") AND offline_mode IS NULL");
            if ($ok && $this->db->affected_rows($ok) > 0) {
                $out['held']++;
                dol_syslog("KsefSubmission::holdBehindDuplicate row " . (int) $o->rowid . " kept open: the newer row " . (int) $o->l_id . " met 440" . ($number !== '' ? " (" . $number . ")" : ''), LOG_INFO);
            }
        }
        return $out;
    }

    /**
     * @brief XML P_2 number
     * @param string $xml
     * @return string '' when absent
     */
    private static function xmlInvoiceNumber($xml)
    {
        if ($xml === '' || !preg_match('/<(?:[A-Za-z0-9_.-]+:)?P_2\s*>([^<]*)</', $xml, $m)) {
            return '';
        }
        return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }

    /**
     * @brief Is latest row
     * @return bool
     */
    public function isLatestOfInvoice()
    {
        $res = $this->db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . $this->table_element . " WHERE fk_facture = " . (int) $this->fk_facture
            . " AND environment = '" . $this->db->escape((string) $this->environment) . "' AND rowid > " . (int) $this->rowid . $this->db->plimit(1));
        return $res && (int) $this->db->num_rows($res) === 0;
    }

    /**
     * @brief Old unresolved sends
     * @param string $environment
     * @param int $limit
     * @param int $max_age Seconds
     * @param array $exclude Handled row ids
     * @return array|false
     */
    public function fetchUnresolvedOlder($environment, $limit = 5, $max_age = 86400, $exclude = array())
    {
        $results = array();
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . $this->table_element;
        $sql .= " WHERE status IN ('" . self::STATUS_SUBMITTED . "', '" . self::STATUS_TIMEOUT . "')";
        $sql .= $this->excludeSql(MAIN_DB_PREFIX . $this->table_element . ".rowid", $exclude);
        $sql .= " AND environment = '" . $this->db->escape($environment) . "'";
        $sql .= " AND date_submission <= " . (dol_now() - $max_age);
        $sql .= " AND ksef_session_reference IS NOT NULL";
        $sql .= " ORDER BY date_last_check ASC, rowid ASC";
        $sql .= $this->db->plimit((int) $limit);
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = "Error " . $this->db->lasterror();
            return false;
        }
        while ($obj = $this->db->fetch_object($resql)) {
            $submission = new KsefSubmission($this->db);
            if ($submission->fetch($obj->rowid) > 0) {
                $results[] = $submission;
            }
        }
        return $results;
    }

    /**
     * @brief Send statistics
     * @param int $days
     * @param string $environment '' for all
     * @param User|null $user null for all
     * @return array
     */
    public function getStatistics($days = 30, $environment = '', $user = null)
    {
        $stats = array('total' => 0, 'accepted' => 0, 'pending' => 0, 'offline' => 0, 'failed' => 0, 'success_rate' => 0, 'common_errors' => array());
        $P = MAIN_DB_PREFIX;
        dol_include_once('/ksef/lib/ksef.lib.php');
        $latest = ksefLatestSubmissionSql('t');
        $where = " INNER JOIN " . $P . "facture as f ON f.rowid = t.fk_facture AND f.entity IN (" . getEntity('invoice') . ")"
            . " WHERE " . $latest . " AND t.date_submission > " . (dol_now() - ($days * 86400))
            . ($environment !== '' ? " AND t.environment = '" . $this->db->escape($environment) . "'" : '')
            . (is_object($user) && function_exists('ksefSqlInvoiceVisibility') ? ksefSqlInvoiceVisibility($user, 'f') : '');

        $resql = $this->db->query("SELECT t.status, COUNT(*) as count FROM " . $P . $this->table_element . " as t" . $where . " GROUP BY t.status");
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $n = (int) $obj->count;
                $stats['total'] += $n;
                if ($obj->status == self::STATUS_ACCEPTED) {
                    $stats['accepted'] += $n;
                } elseif (in_array($obj->status, array(self::STATUS_PENDING, self::STATUS_SUBMITTED, self::STATUS_TIMEOUT))) {
                    $stats['pending'] += $n;
                } elseif ($obj->status == self::STATUS_OFFLINE) {
                    $stats['offline'] += $n;
                } else {
                    $stats['failed'] += $n;
                }
            }
            if ($stats['total'] > 0) {
                $stats['success_rate'] = round(($stats['accepted'] / $stats['total']) * 100, 2);
            }
            $this->db->free($resql);
        }

        $resql = $this->db->query("SELECT t.error_code, COUNT(*) as count FROM " . $P . $this->table_element . " as t" . $where
            . " AND t.status IN ('FAILED', 'REJECTED') AND t.error_code IS NOT NULL GROUP BY t.error_code ORDER BY count DESC LIMIT 5");
        if ($resql) {
            require_once DOL_DOCUMENT_ROOT . '/custom/ksef/class/ksef_client.class.php';
            $client = new KsefClient($this->db);
            while ($obj = $this->db->fetch_object($resql)) {
                $stats['common_errors'][] = array(
                    'code' => $obj->error_code,
                    'count' => (int) $obj->count,
                    'description' => $client->getErrorDescription($obj->error_code)
                );
            }
            $this->db->free($resql);
        }

        return $stats;
    }

    /**
     * @brief Latest of invoice
     * @param $fk_facture Invoice id
     * @param string $environment See fetch()
     * @return int 0 not found, -1 error
     */
    public function fetchByInvoice($fk_facture, $environment = '')
    {
        return $this->fetch(0, $fk_facture, $environment);
    }

    /**
     * @brief Count invoice sends
     * @param int $fk_facture
     * @param KsefSubmission|null $obj Unused
     * @return int
     */
    public function countForInvoice($fk_facture, $obj = null)
    {
        $resql = $this->db->query("SELECT COUNT(*) as nb FROM " . MAIN_DB_PREFIX . "ksef_submissions WHERE fk_facture = " . (int) $fk_facture);
        if (!$resql) {
            return 0;
        }
        $row = $this->db->fetch_object($resql);
        $this->db->free($resql);
        return $row ? (int) $row->nb : 0;
    }

    /**
     * @brief Offline deadlines due
     * @param int $hours_ahead
     * @param string $environment '' for all
     * @param bool $include_overdue
     * @return array|false
     */
    public function fetchCronJobs($hours_ahead = 24, $environment = '', $include_overdue = true)
    {
        $results = array();
        $deadline_threshold = dol_now() + ($hours_ahead * 3600);
        $t = MAIN_DB_PREFIX . $this->table_element;

        $sql = "SELECT rowid FROM " . $t;
        $sql .= " WHERE offline_mode IS NOT NULL";
        $sql .= " AND NOT EXISTS (SELECT 1 FROM " . $t . " n WHERE n.fk_facture = " . $t . ".fk_facture AND n.environment = " . $t . ".environment AND n.rowid > " . $t . ".rowid)";
        if ($environment !== '') {
            $sql .= " AND environment = '" . $this->db->escape($environment) . "'";
        }
        $sql .= " AND status <> 'ACCEPTED'";
        $sql .= " AND offline_deadline IS NOT NULL";
        $sql .= " AND offline_deadline <= " . (int)$deadline_threshold;
        if (!$include_overdue) {
            $sql .= " AND offline_deadline > " . dol_now();
        }
        $sql .= " AND EXISTS (SELECT 1 FROM " . MAIN_DB_PREFIX . "facture f WHERE f.rowid = " . $t . ".fk_facture AND f.entity IN (" . getEntity('invoice') . "))";
        $sql .= " ORDER BY offline_deadline ASC";

        $resql = $this->db->query($sql);

        if ($resql) {
            dol_include_once('/ksef/lib/ksef.lib.php');
            while ($obj = $this->db->fetch_object($resql)) {
                $submission = new KsefSubmission($this->db);
                if ($submission->fetch($obj->rowid) > 0) {
                    if ((string) $submission->environment !== 'PRODUCTION'
                        && ksefProductionIssuedBlock($this->db, (int) $submission->fk_facture, (string) $submission->environment)) {
                        continue;
                    }
                    $results[] = $submission;
                }
            }
            $this->db->free($resql);
            return $results;
        }

        $this->error = "Error " . $this->db->lasterror();
        return false;
    }

    /**
     * @brief May retry
     * @return bool
     */
    public function canRetry()
    {
        return $this->noRetryKey() === '';
    }

    /**
     * @brief Retry refusal key
     * @return string '' when allowed
     */
    public function noRetryKey()
    {
        $status = (string) $this->status;
        if ($status === self::STATUS_ACCEPTED) {
            return (!empty($this->offline_mode) && strpos((string) $this->ksef_number, 'OFFLINE') === 0) ? '' : 'KSEF_AlreadyAccepted';
        }
        if ($status === self::STATUS_OFFLINE) {
            return '';
        }
        if ($status === self::STATUS_PENDING && !empty($this->offline_mode)) {
            return $this->isStale() ? '' : 'KSEF_SubmissionInProgress';
        }
        if ($status === self::STATUS_REJECTED) {
            return !empty($this->offline_mode) ? 'KSEF_RetryRefusedUseTechnicalCorrection' : 'KSEF_RetryRefusedRejected';
        }
        if ($this->isDuplicate()) {
            return 'KSEF_RetryRefusedDuplicate';
        }
        if (in_array($status, array(self::STATUS_PENDING, self::STATUS_SUBMITTED), true) && !$this->isStale()) {
            return 'KSEF_SubmissionInProgress';
        }
        if ($status === self::STATUS_TIMEOUT || $this->isStale()) {
            return empty($this->ksef_reference) ? '' : 'KSEF_RetryRefusedCheckFirst';
        }
        if ($status === self::STATUS_FAILED) {
            return '';
        }
        return 'KSEF_RetryNotPossible';
    }

    /**
     * @brief Retry refusal text
     * @return string|null null when allowed
     */
    public function getNoRetryReason()
    {
        global $langs;
        $langs->load("ksef@ksef");
        $key = $this->noRetryKey();
        return $key === '' ? null : $langs->trans($key);
    }

    /**
     * @brief Send is stale
     * @return bool
     */
    public function isStale()
    {
        if (!in_array($this->status, array(self::STATUS_PENDING, self::STATUS_SUBMITTED), true)) {
            return false;
        }
        if (!empty($this->offline_mode)) {
            if ($this->status !== self::STATUS_PENDING) {
                return false;
            }
            $since = max((int) ($this->date_submission ?: 0), (int) ($this->date_last_check ?: 0));
            return $since > 0 && dol_now() - $since > ksefSendStaleAfter();
        }
        $since = (int) ($this->date_submission ?: 0);
        return $since > 0 && dol_now() - $since > ksefSendStaleAfter();
    }

    /**
     * @brief KSeF answered 440
     * @return bool
     */
    public function isDuplicate()
    {
        return in_array($this->status, array(self::STATUS_FAILED, self::STATUS_REJECTED), true) && (int) $this->error_code === 440;
    }

    /**
     * @brief 440 original
     * @return array
     */
    public function getDuplicateOriginal()
    {
        $d = $this->getErrorDetailsArray();
        return array(
            'ksef_number' => is_array($d) && !empty($d['original_ksef_number']) ? (string) $d['original_ksef_number'] : '',
            'session' => is_array($d) && !empty($d['original_session']) ? (string) $d['original_session'] : '',
        );
    }

    /**
     * @brief Invoice hashes
     * @return array
     */
    public function getInvoiceHashes()
    {
        $out = array();
        $res = $this->db->query("SELECT DISTINCT invoice_hash FROM " . MAIN_DB_PREFIX . $this->table_element
            . " WHERE fk_facture = " . (int) $this->fk_facture . " AND invoice_hash IS NOT NULL AND invoice_hash <> ''");
        if ($res) {
            while ($o = $this->db->fetch_object($res)) {
                $out[] = (string) $o->invoice_hash;
            }
        }
        return $out;
    }
}