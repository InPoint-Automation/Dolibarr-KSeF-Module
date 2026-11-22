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
 * \file    ksef/class/api_ksef.class.php
 * \ingroup ksef
 * \brief   KSeF REST API
 */

use Luracast\Restler\RestException;

/**
 * @brief KSeF REST API
 * @access protected
 * @class DolibarrApiAccess {@requires user}
 */
class KsefApi extends DolibarrApi
{
    const OP_CONVERT       = 'convert';
    const OP_REFUND        = 'refund';
    const OP_APPLY_LINE    = 'apply-line';
    const OP_APPLY_PAYMENT = 'apply-payment';

    const EPSILON = 0.01;
    private $creditNoteNotFound = false;
    private $creditNoteError = '';

    /**
     * @brief Constructor
     */
    public function __construct()
    {
        global $db;
        $this->db = $db;
    }

    /**
     * @brief Load module classes
     * @return void
     */
    private function loadKsefClasses()
    {
        static $loaded = false;
        if ($loaded) return;
        dol_include_once('/ksef/class/ksef_service.class.php');
        dol_include_once('/ksef/class/ksef_submission.class.php');
        dol_include_once('/ksef/class/ksef_incoming.class.php');
        dol_include_once('/ksef/class/ksef_client.class.php');
        dol_include_once('/ksef/class/ksef_sync_state.class.php');
        dol_include_once('/ksef/lib/ksef.lib.php');
        $loaded = true;
    }

    /**
     * @brief List submissions
     * @param  string $status        Status filter {@from query}
     * @param  string $environment   TEST, DEMO or PRODUCTION {@from query}
     * @param  string $date_from     YYYY-MM-DD {@from query}
     * @param  string $date_to       YYYY-MM-DD {@from query}
     * @param  string $sortfield     Sort field {@from query}
     * @param  string $sortorder     ASC or DESC {@from query}
     * @param  int    $limit         0 = default, max 500 {@from query}
     * @param  int    $page          0-based {@from query}
     * @return array
     * @url GET /submissions
     * @throws RestException 400, 403, 500
     */
    public function getSubmissions(
        $status = '',
        $environment = '',
        $date_from = '',
        $date_to = '',
        $sortfield = 't.date_submission',
        $sortorder = 'DESC',
        $limit = 0,
        $page = 0
    ) {
        $this->checkKsefReadPermission();
        $this->checkInvoiceReadRight();
        list($limit, $offset) = $this->listPage($limit, $page);

        $sql = "SELECT " . KsefSubmission::selectColumns(false) . " FROM " . MAIN_DB_PREFIX . "ksef_submissions as t";
        $sql .= " INNER JOIN " . MAIN_DB_PREFIX . "facture as f ON f.rowid = t.fk_facture";
        $sql .= " WHERE 1 = 1" . ksefSqlInvoiceVisibility(DolibarrApiAccess::$user, 'f');

        if (!empty($status)) {
            $sql .= " AND t.status = '" . $this->db->escape($status) . "'";
        }
        if (!empty($environment)) {
            $sql .= " AND t.environment = '" . $this->db->escape($environment) . "'";
        }
        if (!empty($date_from)) {
            $ts = strtotime($date_from);
            if ($ts === false) {
                throw new RestException(400, 'Invalid date_from (expected YYYY-MM-DD)');
            }
            $sql .= " AND t.date_submission >= " . (int)$ts;
        }
        if (!empty($date_to)) {
            $ts = strtotime($date_to . ' 23:59:59');
            if ($ts === false) {
                throw new RestException(400, 'Invalid date_to (expected YYYY-MM-DD)');
            }
            $sql .= " AND t.date_submission <= " . (int)$ts;
        }

        $allowedSort = array(
            't.date_submission', 't.status', 't.environment',
            't.ksef_number', 't.rowid'
        );
        if (!in_array($sortfield, $allowedSort)) {
            $sortfield = 't.date_submission';
        }
        $sortorder = strtoupper($sortorder) === 'ASC' ? 'ASC' : 'DESC';
        $sql .= " ORDER BY " . $sortfield . " " . $sortorder;
        $sql .= $this->db->plimit($limit, $offset);

        $result = array();
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new RestException(500, ksefDbErrorForUser(DolibarrApiAccess::$user, 'KSEF_DbErrorGeneric', $this->db->lasterror()));
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $sub = new KsefSubmission($this->db);
            $sub->setFromRow($obj);
            $row = $this->formatSubmission($sub);
            $row['has_fa3_xml'] = !empty($obj->has_fa3_xml);
            $row['has_upo_xml'] = !empty($obj->has_upo_xml);
            $result[] = $row;
        }
        $this->db->free($resql);

        return $result;
    }

    /**
     * @brief Submission statistics
     * @param  int   $days  Days back {@from query}
     * @param  string $environment TEST, DEMO or PRODUCTION {@from query}
     * @return array
     * @url GET /submissions/stats
     * @throws RestException 403
     */
    public function getSubmissionStats($days = 30, $environment = '')
    {
        $this->checkKsefReadPermission();
        $this->checkInvoiceReadRight();

        $environment = strtoupper((string) $environment);
        if (!in_array($environment, array('TEST', 'DEMO', 'PRODUCTION'), true)) {
            $environment = ksefGetEnvironment();
        }
        $sub = new KsefSubmission($this->db);
        return $sub->getStatistics((int)$days, $environment, DolibarrApiAccess::$user);
    }

    /**
     * @brief Submission detail
     * @param  int   $id  Submission id {@from path}
     * @return array
     * @url GET /submissions/{id}
     * @throws RestException 403, 404
     */
    public function getSubmission($id)
    {
        $this->checkKsefReadPermission();

        $sub = new KsefSubmission($this->db);
        if ($sub->fetch((int)$id) <= 0) {
            throw new RestException(404, 'Submission not found');
        }
        try {
            $this->checkFactureAccess((int) $sub->fk_facture);
        } catch (RestException $e) {
            throw new RestException(404, 'Submission not found');
        }

        return $this->formatSubmission($sub, true);
    }

    /**
     * @brief Latest invoice submission
     * @param  int   $invoice_id  Invoice id {@from path}
     * @return array
     * @url GET /submissions/invoice/{invoice_id}
     * @throws RestException 403, 404
     */
    public function getSubmissionByInvoice($invoice_id)
    {
        $this->checkKsefReadPermission();
        $this->checkFactureAccess((int)$invoice_id);

        $sub = new KsefSubmission($this->db);
        if ($sub->fetchByInvoice((int)$invoice_id) <= 0) {
            throw new RestException(404, 'No submission found for this invoice');
        }

        return $this->formatSubmission($sub, true);
    }

    /**
     * @brief Submit invoice online
     * @param  int   $invoice_id  Invoice id {@from path}
     * @param  bool  $send_without_deposits  Acknowledge deposit notices {@from body}
     * @param  string $nbp_rate_pick  keep or exact {@from body}
     * @param  bool  $send_with_negative_rate  Acknowledge negative VAT {@from body}
     * @return array
     * @url POST /submissions/{invoice_id}/submit
     * @throws RestException 403, 404, 409, 422, 500, 503
     */
    public function submitInvoice($invoice_id, $send_without_deposits = false, $nbp_rate_pick = '', $send_with_negative_rate = false)
    {
        $this->checkSendPermission();
        $invoice = $this->checkFactureAccess((int) $invoice_id);
        dol_syslog("KsefApi::submitInvoice invoice_id=$invoice_id", LOG_INFO);

        $ksef = new KsefService($this->db);
        $ksef->acknowledgeDeposits = !empty($send_without_deposits);
        $ksef->acknowledgeNegativeRates = !empty($send_with_negative_rate);
        $this->checkInvoiceSubmittable($ksef, $invoice);
        $this->ensureInvoiceNbpRate($invoice, $nbp_rate_pick);

        $result = $ksef->submitInvoice(
            (int)$invoice_id,
            DolibarrApiAccess::$user,
            'SYNC'
        );

        return $this->mapSendResult($ksef, $result, 'Submission failed');
    }

    /**
     * @brief Submit invoice offline
     * @param  int    $invoice_id     Invoice id {@from path}
     * @param  string $reason         Offline reason {@from body}
     * @param  bool   $send_without_deposits  Acknowledge deposit notices {@from body}
     * @param  string $nbp_rate_pick  keep or exact {@from body}
     * @param  bool   $send_with_negative_rate  Acknowledge negative VAT {@from body}
     * @return array
     * @url POST /submissions/{invoice_id}/submit-offline
     * @throws RestException 403, 404, 409, 422, 500
     */
    public function submitInvoiceOffline($invoice_id, $reason = 'api_request', $send_without_deposits = false, $nbp_rate_pick = '', $send_with_negative_rate = false)
    {
        $this->checkSendPermission();
        $invoice = $this->checkFactureAccess((int) $invoice_id);
        dol_syslog("KsefApi::submitInvoiceOffline invoice_id=$invoice_id reason=$reason", LOG_INFO);

        $ksef = new KsefService($this->db);
        $ksef->acknowledgeDeposits = !empty($send_without_deposits);
        $ksef->acknowledgeNegativeRates = !empty($send_with_negative_rate);
        $this->checkInvoiceSubmittable($ksef, $invoice);
        $this->ensureInvoiceNbpRate($invoice, $nbp_rate_pick);

        $result = $ksef->submitInvoiceOffline(
            (int)$invoice_id,
            DolibarrApiAccess::$user,
            $reason
        );

        if (is_array($result) && ($result['status'] ?? '') === 'OFFLINE' && !empty($result['submission_id'])
            && in_array($reason, array('backdated', 'backdated_retry'), true)) {
            $offSub = new KsefSubmission($this->db);
            if ($offSub->fetch((int) $result['submission_id']) > 0) {
                $upload = $ksef->retryOfflineWithStoredXML($offSub, DolibarrApiAccess::$user);
                if (is_array($upload) && (($upload['status'] ?? '') === 'ACCEPTED'
                    || (!in_array(($upload['status'] ?? ''), array('TIMEOUT', 'OFFLINE', 'PENDING', ''), true) && ($upload['failure_kind'] ?? '') !== 'transport'))) {
                    return $this->mapSendResult($ksef, $upload, 'Offline submission failed');
                }
                $result['upload_status'] = is_array($upload) ? (string) ($upload['status'] ?? '') : '';
                $result['upload_error'] = is_array($upload) ? (string) ($upload['error'] ?? '') : '';
            }
        }

        return $this->mapSendResult($ksef, $result, 'Offline submission failed');
    }

    /**
     * @brief Retry submission
     * @param  int   $invoice_id  Invoice id {@from path}
     * @param  bool  $send_without_deposits  Acknowledge deposit notices {@from body}
     * @param  bool  $send_with_negative_rate  Acknowledge negative VAT {@from body}
     * @return array
     * @url POST /submissions/{invoice_id}/retry
     * @throws RestException 403, 404, 409, 422, 500, 503
     */
    public function retrySubmission($invoice_id, $send_without_deposits = false, $send_with_negative_rate = false)
    {
        $this->checkSendPermission();
        $this->checkFactureAccess((int) $invoice_id);
        dol_syslog("KsefApi::retrySubmission invoice_id=$invoice_id", LOG_INFO);

        $ksef = new KsefService($this->db);
        $ksef->acknowledgeDeposits = !empty($send_without_deposits);
        $ksef->acknowledgeNegativeRates = !empty($send_with_negative_rate);
        $result = $ksef->retrySubmission(
            (int)$invoice_id,
            DolibarrApiAccess::$user
        );

        return $this->mapSendResult($ksef, $result, 'Retry failed');
    }

    /**
     * @brief Link KSeF number
     * @param  int    $invoice_id   Invoice id {@from path}
     * @param  string $ksef_number  KSeF number {@from body}
     * @return array
     * @url POST /submissions/{invoice_id}/link
     * @throws RestException 400, 403, 404, 409, 422, 500, 503
     */
    public function linkSubmission($invoice_id, $ksef_number = '')
    {
        $this->checkKsefWritePermission();
        if (!ksefUserCan(DolibarrApiAccess::$user, 'link_ksef_number')) {
            throw new RestException(403, 'Not enough permissions to tie a KSeF number (ksef write and facture creer)');
        }
        $this->checkFactureAccess((int) $invoice_id);
        $ksef_number = trim((string) $ksef_number);
        if ($ksef_number === '' || !ksefIsKsefNumber($ksef_number)) {
            throw new RestException(400, 'ksef_number is required and must be a KSeF number');
        }
        dol_syslog("KsefApi::linkSubmission invoice_id=" . (int) $invoice_id . " ksef_number=" . $ksef_number, LOG_INFO);

        $ksef = new KsefService($this->db);
        $result = $ksef->linkKsefNumber((int) $invoice_id, $ksef_number, DolibarrApiAccess::$user);
        if (($result['status'] ?? '') === 'ACCEPTED') {
            return $result;
        }
        $reason = (string) ($result['reason'] ?? '');
        $msg = (string) ($result['error'] ?? $ksef->error);
        $details = array('status' => 'LINK_REFUSED', 'reason' => $reason);
        if ($reason === 'record') {
            throw new RestException(500, $msg, array('status' => 'RECORD_FAILED'));
        }
        if (in_array($reason, array('gateway', 'no_permission'), true)) {
            throw new RestException(503, $msg, $details);
        }
        if (in_array($reason, array('not_found', 'fields'), true)) {
            throw new RestException(422, $msg, $details);
        }
        if ($reason === 'bad_number') {
            throw new RestException(400, $msg, $details);
        }
        throw new RestException(409, $msg, $details);
    }

    /**
     * @brief Poll KSeF status
     * @param  int   $invoice_id  Invoice id {@from path}
     * @return array
     * @url GET /submissions/{invoice_id}/status
     * @throws RestException 403
     * @throws RestException 404
     * @throws RestException 409
     * @throws RestException 429
     * @throws RestException 500
     * @throws RestException 503
     */
    public function checkSubmissionStatus($invoice_id)
    {
        $this->checkKsefReadPermission();
        $this->checkFactureAccess((int)$invoice_id);

        $sub = new KsefSubmission($this->db);
        if ($sub->fetchByInvoice((int)$invoice_id) <= 0) {
            throw new RestException(404, 'No submission found for invoice ' . (int)$invoice_id);
        }

        $ksef = new KsefService($this->db);
        $result = $ksef->checkStatus((int)$invoice_id, DolibarrApiAccess::$user, true);

        if ($result === false) {
            if ($ksef->failureReason === 'right') {
                throw new RestException(403, $ksef->error, array('status' => 'HASH_SEARCH_RIGHT'));
            }
            if ($ksef->failureReason === 'wait') {
                $wait = max(1, (int) $ksef->hashSearchWaitUntil - dol_now());
                throw new RestException(429, $ksef->error, array('status' => 'HASH_SEARCH_WAIT', 'retry_after' => $wait,
                    'retry_at' => dol_print_date((int) $ksef->hashSearchWaitUntil, 'dayhourrfc', 'gmt')));
            }
            if ($ksef->failureReason === 'noref') {
                throw new RestException(409, $ksef->error ?: 'Submission has no KSeF reference yet', array('status' => 'NO_REFERENCE'));
            }
            if ($ksef->failureReason === 'record' && $ksef->notRecordedNumber !== '') {
                throw new RestException(500, $ksef->error, array('status' => 'ACCEPTED_NOT_RECORDED',
                    'ksef_number' => $ksef->notRecordedNumber, 'submission_id' => (int) $sub->rowid));
            }
            if ($ksef->failureReason === 'record') {
                throw new RestException(500, ksefDbErrorForUser(DolibarrApiAccess::$user, 'KSEF_DbErrorGeneric', $ksef->error));
            }
            if ($ksef->failureReason === 'notfound' && $ksef->searchedByHash) {
                throw new RestException(409, $ksef->error, array('status' => 'NOT_FOUND_IN_KSEF',
                    'retry_later' => ($ksef->hashNotFoundFresh || !$ksef->hashSearchComplete) ? 1 : 0));
            }
            if ($ksef->failureReason === 'ambiguous') {
                throw new RestException(409, $ksef->error, array('status' => 'HASH_AMBIGUOUS'));
            }
            throw new RestException(503, $ksef->error ?: 'KSeF gateway unavailable');
        }
        unset($result['raw_response']);

        return $result;
    }

    /**
     * @brief Download UPO
     * @param  int   $invoice_id  Invoice id {@from path}
     * @return array
     * @url GET /submissions/{invoice_id}/upo
     * @throws RestException 403, 404, 409, 503
     */
    public function getSubmissionUpo($invoice_id)
    {
        $this->checkKsefReadPermission();
        $this->checkFactureAccess((int)$invoice_id);

        $sub = new KsefSubmission($this->db);
        if ($sub->fetchByInvoice((int)$invoice_id) <= 0) {
            throw new RestException(404, 'No submission found for this invoice');
        }

        if (empty($sub->upo_xml)) {
            if ($sub->status !== KsefSubmission::STATUS_ACCEPTED || empty($sub->ksef_number)) {
                throw new RestException(404, 'UPO not available yet');
            }
            if ($sub->getSessionReference() === '') {
                global $langs;
                $langs->load('ksef@ksef');
                throw new RestException(409, $langs->transnoentitiesnoconv('KSEF_RestUpoNeedsRecovery'), array('status' => 'UPO_NEEDS_RECOVERY'));
            }
            $ksef = new KsefService($this->db);
            $upo = $ksef->downloadUPOForSubmission($sub, DolibarrApiAccess::$user);
            if ($upo === false) {
                if ($ksef->lastFailureWasTransport()) {
                    throw new RestException(503, $ksef->error ?: 'KSeF gateway unavailable');
                }
                throw new RestException(404, 'UPO not available yet', array('detail' => (string) $ksef->error));
            }
            $sub->upo_xml = $upo;
        }

        return array(
            'invoice_id'  => (int)$sub->fk_facture,
            'ksef_number' => $sub->ksef_number,
            'upo_xml'     => $sub->upo_xml,
        );
    }

    /**
     * @brief Submission FA(3) XML
     * @param  int   $invoice_id  Invoice id {@from path}
     * @return array
     * @url GET /submissions/{invoice_id}/xml
     * @throws RestException 403, 404
     */
    public function getSubmissionXml($invoice_id)
    {
        $this->checkKsefReadPermission();
        $this->checkFactureAccess((int)$invoice_id);

        $sub = new KsefSubmission($this->db);
        if ($sub->fetchByInvoice((int)$invoice_id) <= 0) {
            throw new RestException(404, 'No submission found for this invoice');
        }

        if (empty($sub->fa3_xml)) {
            throw new RestException(404, 'No FA(3) XML stored for this submission');
        }

        return array(
            'invoice_id'       => (int)$sub->fk_facture,
            'ksef_number'      => $sub->ksef_number,
            'status'           => $sub->status,
            'fa3_xml'          => $sub->fa3_xml,
            'fa3_creation_date' => $sub->fa3_creation_date,
            'invoice_hash'     => $sub->invoice_hash,
        );
    }

    /**
     * @brief List incoming invoices
     * @param  string $seller_nip       Seller NIP {@from query}
     * @param  string $seller_name      Partial match {@from query}
     * @param  string $invoice_number   Partial match {@from query}
     * @param  string $ksef_number      Partial match {@from query}
     * @param  string $import_status    Status filter {@from query}
     * @param  string $environment      Environment {@from query}
     * @param  string $date_from        YYYY-MM-DD {@from query}
     * @param  string $date_to          YYYY-MM-DD {@from query}
     * @param  string $sortfield        Sort field {@from query}
     * @param  string $sortorder        ASC or DESC {@from query}
     * @param  int    $limit            0 = default, max 500 {@from query}
     * @param  int    $page             0-based {@from query}
     * @return array
     * @url GET /incoming
     * @throws RestException 400, 403, 500
     */
    public function getIncoming(
        $seller_nip = '',
        $seller_name = '',
        $invoice_number = '',
        $ksef_number = '',
        $import_status = '',
        $environment = '',
        $date_from = '',
        $date_to = '',
        $sortfield = 'i.invoice_date',
        $sortorder = 'DESC',
        $limit = 0,
        $page = 0
    ) {
        $this->checkKsefReadPermission();
        list($limit, $offset) = $this->listPage($limit, $page);

        $filters = array();
        if (!empty($seller_nip)) {
            $filters['seller_nip'] = $seller_nip;
        }
        if (!empty($seller_name)) {
            $filters['seller_name'] = $seller_name;
        }
        if (!empty($invoice_number)) {
            $filters['invoice_number'] = $invoice_number;
        }
        if (!empty($ksef_number)) {
            $filters['ksef_number'] = $ksef_number;
        }
        if (!empty($import_status)) {
            $filters['import_status'] = $import_status;
        }
        if (!empty($environment)) {
            $filters['environment'] = $environment;
        }
        if (!empty($date_from)) {
            $ts = strtotime($date_from);
            if ($ts === false) {
                throw new RestException(400, 'Invalid date_from (expected YYYY-MM-DD)');
            }
            $filters['invoice_date_start'] = $ts;
        }
        if (!empty($date_to)) {
            $ts = strtotime($date_to . ' 23:59:59');
            if ($ts === false) {
                throw new RestException(400, 'Invalid date_to (expected YYYY-MM-DD)');
            }
            $filters['invoice_date_end'] = $ts;
        }

        if (!in_array($sortfield, KsefIncoming::SORT_FIELDS, true)) {
            $sortfield = 'i.invoice_date';
        }
        $sortorder = strtoupper($sortorder) === 'ASC' ? 'ASC' : 'DESC';

        $incoming = new KsefIncoming($this->db);
        $records = $incoming->fetchAll(
            $filters, $sortfield, $sortorder, (int)$limit, (int)$offset
        );

        if ($records === -1) {
            throw new RestException(500, ksefDbErrorForUser(DolibarrApiAccess::$user, 'KSEF_DbErrorGeneric', $incoming->error));
        }

        $result = array();
        foreach ($records as $rec) {
            $result[] = $this->formatIncoming($rec);
        }

        return $result;
    }

    /**
     * @brief Incoming invoice detail
     * @param  int   $id  Incoming id {@from path}
     * @return array
     * @url GET /incoming/{id}
     * @throws RestException 403, 404, 500
     */
    public function getIncomingById($id)
    {
        $this->checkKsefReadPermission();

        $incoming = new KsefIncoming($this->db);
        $res = $incoming->fetch((int)$id);

        if ($res < 0) {
            throw new RestException(500, 'Database error');
        }
        if ($res == 0) {
            throw new RestException(404, 'Incoming invoice not found');
        }

        $data = $this->formatIncoming($incoming);
        $data['line_items'] = $incoming->getLineItems();
        $data['vat_summary'] = $incoming->getVatSummary();

        return $data;
    }

    /**
     * @brief Incoming FA(3) XML
     * @param  int   $id  Incoming id {@from path}
     * @return array
     * @url GET /incoming/{id}/xml
     * @throws RestException 403, 404
     */
    public function getIncomingXml($id)
    {
        $this->checkKsefReadPermission();

        $incoming = new KsefIncoming($this->db);
        $res = $incoming->fetch((int)$id);

        if ($res <= 0) {
            throw new RestException(404, 'Incoming invoice not found');
        }
        if (empty($incoming->fa3_xml)) {
            throw new RestException(404, 'No FA(3) XML stored for this invoice');
        }

        return array(
            'id'          => (int)$incoming->rowid,
            'ksef_number' => $incoming->ksef_number,
            'fa3_xml'     => $incoming->fa3_xml,
        );
    }

    /**
     * @brief Incoming invoice PDF
     * @param  int   $id  Incoming id {@from path}
     * @return array
     * @url GET /incoming/{id}/pdf
     * @throws RestException 403, 404, 500
     */
    public function getIncomingPdf($id)
    {
        $this->checkKsefReadPermission();

        $incoming = new KsefIncoming($this->db);
        $res = $incoming->fetch((int)$id);

        if ($res <= 0) {
            throw new RestException(404, 'Incoming invoice not found');
        }

        if ($incoming->import_status === KsefIncoming::STATUS_FETCH_ERROR || empty($incoming->fa3_xml)) {
            throw new RestException(409, 'FETCH_ERROR: no readable invoice XML, no visualisation');
        }
        $pdfContent = $incoming->generatePdfVisualization();
        if ($pdfContent === false) {
            throw new RestException(500, $incoming->error ?: 'PDF generation failed');
        }

        return array(
            'id'           => (int)$incoming->rowid,
            'ksef_number'  => $incoming->ksef_number,
            'filename'     => 'ksef_' . $incoming->ksef_number . '.pdf',
            'content_type' => 'application/pdf',
            'encoding'     => 'base64',
            'content'      => base64_encode($pdfContent),
        );
    }

    /**
     * @brief Start incoming sync
     * @return array
     * @url POST /incoming/sync
     * @throws RestException 403, 409, 429, 503
     */
    public function triggerIncomingSync()
    {
        $this->checkKsefWritePermission();
        dol_syslog("KsefApi::triggerIncomingSync", LOG_INFO);

        $ksef = new KsefService($this->db);
        $result = $ksef->initIncomingFetch(DolibarrApiAccess::$user);

        if ($result === false) {
            if ((int) $ksef->last_http_code === 429) {
                throw new RestException(429, $ksef->error ?: 'KSeF rate limit', array('status' => 'RATE_LIMITED', 'retry_after' => (int) $ksef->last_retry_after ?: null));
            }
            throw new RestException(503, $ksef->error ?: 'Failed to initiate sync', array('status' => 'KSEF_UNAVAILABLE'));
        }

        if ($result['status'] === 'ALREADY_PROCESSING') {
            throw new RestException(409, 'Sync already in progress');
        }

        return $result;
    }

    /**
     * @brief Incoming sync state
     * @return array
     * @url GET /incoming/sync/status
     * @throws RestException 403
     */
    public function getIncomingSyncStatus()
    {
        $this->checkKsefReadPermission();

        $ksef = new KsefService($this->db);
        return $ksef->getIncomingSyncStatus('incoming');
    }

    /**
     * @brief Advance sync round
     * @return array
     * @url POST /incoming/sync/advance
     * @throws RestException 403, 409, 429, 500, 503
     */
    public function advanceIncomingSync()
    {
        $this->checkKsefWritePermission();
        dol_syslog("KsefApi::advanceIncomingSync", LOG_INFO);

        $ksef = new KsefService($this->db);
        $result = $ksef->advanceIncomingSync(DolibarrApiAccess::$user, 'incoming');
        if (!is_array($result)) {
            throw new RestException(500, ksefDbErrorForUser(DolibarrApiAccess::$user, 'KSEF_DbErrorGeneric', $ksef->error));
        }
        $status = (string) ($result['status'] ?? '');
        if ($status === 'RATE_LIMITED') {
            throw new RestException(429, 'KSeF rate limit', array('status' => 'RATE_LIMITED', 'retry_after' => (int) ($result['retry_after'] ?? 0) ?: null));
        }
        if ($status === 'CHECK_FAILED') {
            throw new RestException(503, (string) ($result['error'] ?? '') !== '' ? $result['error'] : 'KSeF not reachable', array('status' => 'KSEF_UNAVAILABLE'));
        }
        if ($status === 'NO_PENDING_FETCH') {
            throw new RestException(409, 'No sync round in progress: start one with POST /incoming/sync', array('status' => 'NO_PENDING_FETCH'));
        }
        if ($status === 'TIMEOUT' || $status === 'FAILED') {
            $msg = (string) ($result['error'] ?? '');
            throw new RestException(409, $msg !== '' ? $msg : 'The sync round ended without importing: start a new one with POST /incoming/sync', array('status' => $status));
        }

        return $result;
    }

    /**
     * @brief Reset incoming sync
     * @param  string $from_date  YYYY-MM-DD {@from body}
     * @param  string $days_back  Days before today {@from body}
     * @return array
     * @url POST /incoming/sync/reset
     * @throws RestException 400, 403, 500
     */
    public function resetIncomingSync($from_date = '', $days_back = '')
    {
        $this->checkKsefWritePermission();
        $fromDate = trim((string) $from_date);
        $daysBack = trim((string) $days_back);
        dol_syslog("KsefApi::resetIncomingSync from_date=" . $fromDate . " days_back=" . $daysBack, LOG_INFO);

        $ksef = new KsefService($this->db);
        $rewind = $fromDate !== '' || ($daysBack !== '' && $daysBack !== '0');

        if (!$rewind) {
            if (!$ksef->resetIncomingFetch('incoming')) {
                throw new RestException(500, 'Failed to reset sync state');
            }
            return array(
                'success'  => true,
                'message'  => 'Fetch state cleared',
                'hwm_date' => $ksef->getIncomingSyncState('incoming')->hwm_date ?: null,
            );
        }

        if (!KsefService::canRewindSync(DolibarrApiAccess::$user)) {
            throw new RestException(403, 'Only an administrator may move the sync point back (from_date / days_back)');
        }
        $start = $ksef->syncResetStartDate($fromDate, $daysBack);
        if ($start === false) {
            throw new RestException(400, $ksef->error);
        }
        if (!$ksef->resetIncomingSyncState(DolibarrApiAccess::$user, 'incoming', $start)) {
            throw new RestException(500, 'Failed to reset sync state');
        }
        $hwm = $ksef->getIncomingSyncState('incoming')->hwm_date ?: null;

        return array(
            'success'  => true,
            'message'  => 'Sync state reset, the next sync starts at ' . ($hwm ? dol_print_date(strtotime($hwm), 'dayrfc') : 'the KSeF start'),
            'hwm_date' => $hwm,
        );
    }

    /**
     * @brief Delete incoming record
     * @param  int   $id  Incoming id {@from path}
     * @return array
     * @url DELETE /incoming/{id}
     * @throws RestException 403, 404, 409, 500
     */
    public function deleteIncoming($id)
    {
        $this->checkKsefWritePermission();
        dol_syslog("KsefApi::deleteIncoming id=$id", LOG_INFO);
        if (!ksefUserCan(DolibarrApiAccess::$user, 'delete_incoming')) {
            throw new RestException(403, 'Not enough permissions to delete incoming invoices (ksef/write and fournisseur/facture/creer)');
        }

        $incoming = new KsefIncoming($this->db);
        $res = $incoming->fetch((int)$id);

        if ($res <= 0) {
            throw new RestException(404, 'Incoming invoice not found');
        }
        if (!$incoming->canDelete()) {
            throw new RestException(409, 'An imported incoming invoice (linked to a supplier invoice) cannot be deleted; reset its import status first', array('status' => 'NOT_DELETABLE', 'import_status' => $incoming->import_status));
        }

        $delResult = $incoming->delete(DolibarrApiAccess::$user);
        if ($delResult < 0) {
            throw new RestException(500, ksefDbErrorForUser(DolibarrApiAccess::$user, 'KSEF_DbErrorGeneric', $incoming->error ?: implode(', ', (array) $incoming->errors)));
        }

        return array(
            'success' => true,
            'message' => 'Incoming invoice deleted',
        );
    }

    /**
     * @brief Link supplier invoice
     * @param  int   $id                Incoming id {@from path}
     * @param  int   $fk_facture_fourn  Supplier invoice id {@from body}
     * @return array
     * @url POST /incoming/{id}/link
     * @throws RestException 400, 403, 404, 409, 500
     */
    public function linkIncoming($id, $fk_facture_fourn = 0)
    {
        $this->checkKsefWritePermission();
        dol_syslog("KsefApi::linkIncoming id=$id fk_facture_fourn=$fk_facture_fourn", LOG_INFO);

        if ($fk_facture_fourn <= 0) {
            throw new RestException(
                400,
                'fk_facture_fourn is required (supplier invoice ID)'
            );
        }

        $incoming = new KsefIncoming($this->db);
        $res = $incoming->fetch((int)$id);

        if ($res <= 0) {
            throw new RestException(404, 'Incoming invoice not found');
        }

        if (!DolibarrApiAccess::$user->hasRight('fournisseur', 'facture', 'lire')) {
            throw new RestException(403, 'Not enough permissions to read supplier invoices (fournisseur/facture/lire)');
        }

        require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
        $supInvoice = new FactureFournisseur($this->db);
        if ($supInvoice->fetch((int)$fk_facture_fourn) <= 0) {
            throw new RestException(
                404,
                'Supplier invoice #' . $fk_facture_fourn . ' not found'
            );
        }
        if (!DolibarrApi::_checkAccessToResource('fournisseur', $supInvoice->id, 'facture_fourn', 'facture')) {
            throw new RestException(403, 'Access not allowed for login ' . DolibarrApiAccess::$user->login);
        }

        $linkResult = $incoming->linkToInvoice(DolibarrApiAccess::$user, (int)$fk_facture_fourn);
        if ($linkResult === 0) {
            throw new RestException(409, $incoming->error, $incoming->error_code === 'ALREADY_LINKED'
                ? array('status' => 'ALREADY_LINKED', 'fk_facture_fourn' => (int) $incoming->fk_facture_fourn)
                : array('status' => $incoming->error_code ?: 'STATUS_CHANGED'));
        }
        if ($linkResult < 0) {
            throw new RestException(500, ksefDbErrorForUser(DolibarrApiAccess::$user, 'KSEF_DbErrorGeneric', $incoming->error));
        }

        return array(
            'success'          => true,
            'incoming_id'      => (int)$incoming->rowid,
            'fk_facture_fourn' => (int)$fk_facture_fourn,
            'import_status'    => KsefIncoming::STATUS_IMPORTED,
        );
    }

    /**
     * @brief List sales rows
     * @param  string $link_status    Status filter {@from query}
     * @param  string $environment    Environment {@from query}
     * @param  string $ksef_number    Partial match {@from query}
     * @param  string $invoice_number Partial match {@from query}
     * @param  string $date_from      YYYY-MM-DD {@from query}
     * @param  string $date_to        YYYY-MM-DD {@from query}
     * @param  string $sortfield      Sort field {@from query}
     * @param  string $sortorder      ASC or DESC {@from query}
     * @param  int    $limit          0 = default, max 500 {@from query}
     * @param  int    $page           0-based {@from query}
     * @return array
     * @url GET /sales
     * @throws RestException 400, 403, 500
     */
    public function getSales(
        $link_status = '',
        $environment = '',
        $ksef_number = '',
        $invoice_number = '',
        $date_from = '',
        $date_to = '',
        $sortfield = 'i.invoice_date',
        $sortorder = 'DESC',
        $limit = 0,
        $page = 0
    ) {
        $this->checkKsefReadPermission();
        $this->checkInvoiceReadRight();
        dol_include_once('/ksef/class/ksef_sales.class.php');
        list($limit, $offset) = $this->listPage($limit, $page);
        $environment = strtoupper((string) $environment);
        if (!in_array($environment, array('TEST', 'DEMO', 'PRODUCTION'), true)) {
            $environment = ksefGetEnvironment();
        }

        $sql = "SELECT i.rowid FROM " . MAIN_DB_PREFIX . "ksef_sales as i";
        $sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "facture as f ON f.rowid = i.fk_facture";
        $sql .= " WHERE i.entity IN (" . getEntity('ksef_sales') . ")";
        $sql .= " AND i.environment = '" . $this->db->escape($environment) . "'";
        if (!ksefUserSeesAllCustomers(DolibarrApiAccess::$user)) {
            $sql .= " AND i.fk_facture IS NOT NULL" . ksefSqlInvoiceVisibility(DolibarrApiAccess::$user, 'f');
        }
        $link_status = strtoupper((string) $link_status);
        if ($link_status === KsefSales::STATUS_FETCH_ERROR) {
            $sql .= " AND i.import_status = '" . KsefSales::STATUS_FETCH_ERROR . "'";
        } elseif ($link_status === KsefSales::LINK_SKIPPED) {
            $sql .= " AND i.import_status = '" . KsefSales::STATUS_SKIPPED . "'";
        } elseif ($link_status !== '') {
            if (!in_array($link_status, array(KsefSales::LINK_OK, KsefSales::LINK_DIFFERS, KsefSales::LINK_LINKABLE, KsefSales::LINK_SUGGEST,
                KsefSales::LINK_IMPORTABLE, KsefSales::LINK_CONFLICT, KsefSales::LINK_UNSUPPORTED), true)) {
                throw new RestException(400, "Invalid link_status '" . $link_status . "'");
            }
            $sql .= " AND i.import_status NOT IN ('" . KsefSales::STATUS_FETCH_ERROR . "', '" . KsefSales::STATUS_SKIPPED . "')";
            $sql .= $link_status === KsefSales::LINK_IMPORTABLE ? " AND (i.link_status IS NULL OR i.link_status = '' OR i.link_status = '" . KsefSales::LINK_IMPORTABLE . "')"
                : " AND i.link_status = '" . $this->db->escape($link_status) . "'";
        }
        if ($ksef_number !== '') {
            $sql .= " AND i.ksef_number LIKE '%" . $this->db->escape($ksef_number) . "%'";
        }
        if ($invoice_number !== '') {
            $sql .= " AND i.invoice_number LIKE '%" . $this->db->escape($invoice_number) . "%'";
        }
        if (!empty($date_from)) {
            $ts = strtotime($date_from);
            if ($ts === false) {
                throw new RestException(400, 'Invalid date_from (expected YYYY-MM-DD)');
            }
            $sql .= " AND i.invoice_date >= " . (int) $ts;
        }
        if (!empty($date_to)) {
            $ts = strtotime($date_to . ' 23:59:59');
            if ($ts === false) {
                throw new RestException(400, 'Invalid date_to (expected YYYY-MM-DD)');
            }
            $sql .= " AND i.invoice_date <= " . (int) $ts;
        }
        if (!in_array($sortfield, KsefIncoming::SORT_FIELDS, true)) {
            $sortfield = 'i.invoice_date';
        }
        $sortorder = strtoupper($sortorder) === 'ASC' ? 'ASC' : 'DESC';
        $sql .= " ORDER BY " . $sortfield . " " . $sortorder . ($sortfield !== 'i.rowid' ? ", i.rowid " . $sortorder : '');
        $sql .= $this->db->plimit($limit, $offset);

        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new RestException(500, ksefDbErrorForUser(DolibarrApiAccess::$user, 'KSEF_DbErrorGeneric', $this->db->lasterror()));
        }
        $ids = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $ids[] = (int) $obj->rowid;
        }
        $this->db->free($resql);
        $result = array();
        foreach ($ids as $id) {
            $row = new KsefSales($this->db);
            if ($row->fetch($id) > 0) {
                $result[] = $this->formatSales($row);
            }
        }
        return $result;
    }

    /**
     * @brief Sales status counts
     * @param  string $environment  Environment {@from query}
     * @return array
     * @url GET /sales/counts
     * @throws RestException 403
     */
    public function getSalesCounts($environment = '')
    {
        $this->checkKsefReadPermission();
        $this->checkInvoiceReadRight();
        if (!ksefUserSeesAllCustomers(DolibarrApiAccess::$user)) {
            throw new RestException(403, 'The counts cover every customer: not available to a user limited to some customers');
        }
        dol_include_once('/ksef/class/ksef_sales.class.php');
        $environment = strtoupper((string) $environment);
        if (!in_array($environment, array('TEST', 'DEMO', 'PRODUCTION'), true)) {
            $environment = ksefGetEnvironment();
        }
        $sales = new KsefSales($this->db);
        $counts = $sales->getReconcileCounts($environment);
        $counts['synced_until'] = KsefSales::syncedUntil();
        return $counts;
    }

    /**
     * @brief Sales gaps
     * @param  string $environment  Environment {@from query}
     * @return array
     * @url GET /sales/gaps
     * @throws RestException 403
     */
    public function getSalesGaps($environment = '')
    {
        $this->checkKsefReadPermission();
        $this->checkInvoiceReadRight();
        dol_include_once('/ksef/class/ksef_sales.class.php');
        $environment = strtoupper((string) $environment);
        if (!in_array($environment, array('TEST', 'DEMO', 'PRODUCTION'), true)) {
            $environment = ksefGetEnvironment();
        }
        $sales = new KsefSales($this->db);
        $all = ksefUserSeesAllCustomers(DolibarrApiAccess::$user);
        $out = array();
        foreach ($sales->getDolibarrGaps($environment) as $g) {
            if ($all || ksefUserCanReadInvoice(DolibarrApiAccess::$user, $g['fk_facture'])) {
                $out[] = $g;
            }
        }
        return $out;
    }

    /**
     * @brief Sales row detail
     * @param  int   $id  Sales row id {@from path}
     * @return array
     * @url GET /sales/{id}
     * @throws RestException 403, 404
     */
    public function getSalesById($id)
    {
        return $this->formatSales($this->fetchSalesForUser((int) $id));
    }

    /**
     * @brief Sales row XML
     * @param  int   $id  Sales row id {@from path}
     * @return array
     * @url GET /sales/{id}/xml
     * @throws RestException 403, 404
     */
    public function getSalesXml($id)
    {
        $row = $this->fetchSalesForUser((int) $id);
        if (empty($row->fa3_xml)) {
            throw new RestException(404, 'No FA(3) XML stored for this invoice');
        }
        return array(
            'id'           => (int) $row->rowid,
            'ksef_number'  => $row->ksef_number,
            'invoice_hash' => $row->invoice_hash,
            'fa3_xml'      => $row->fa3_xml,
        );
    }

    /**
     * @brief Credit note discounts
     * @param  int    $id     Credit note id {@from path}
     * @param  string $state  available, used or none {@from query}
     * @return array
     * @url GET /creditnotes/{id}/discounts
     * @throws RestException 400, 403, 404, 409, 500
     */
    public function getCreditNoteDiscounts($id, $state = 'none')
    {
        $this->checkKsefReadPermission();
        $invoice = $this->checkFactureAccess((int) $id);
        if ((int) $invoice->type !== Facture::TYPE_CREDIT_NOTE) {
            throw new RestException(409, 'Invoice ' . (int) $id . ' is not a credit note');
        }

        $sql = "SELECT rowid, tva_tx, amount_ht, amount_tva, amount_ttc, description, fk_facture, fk_facture_line";
        $sql .= " FROM " . MAIN_DB_PREFIX . "societe_remise_except";
        $sql .= " WHERE fk_facture_source = " . (int) $id;
        $sql .= " AND entity IN (" . getEntity('invoice') . ")";

        $state = strtolower((string) $state);
        if ($state === 'available') {
            $sql .= " AND fk_facture IS NULL AND fk_facture_line IS NULL";
        } elseif ($state === 'used') {
            $sql .= " AND (fk_facture IS NOT NULL OR fk_facture_line IS NOT NULL)";
        } elseif ($state !== 'none' && $state !== '') {
            throw new RestException(400, "Invalid state filter '" . $state . "' (expected available, used or none)");
        }
        $sql .= " ORDER BY tva_tx ASC, rowid ASC";

        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new RestException(500, ksefDbErrorForUser(DolibarrApiAccess::$user, 'KSEF_DbErrorGeneric', $this->db->lasterror()));
        }

        $rows = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $rows[] = array(
                'id'              => (int) $obj->rowid,
                'tva_tx'          => abs((float) $obj->tva_tx),
                'amount_ht'       => abs((float) $obj->amount_ht),
                'amount_tva'      => abs((float) $obj->amount_tva),
                'amount_ttc'      => abs((float) $obj->amount_ttc),
                'description'     => $obj->description,
                'fk_facture'      => $obj->fk_facture !== null ? (int) $obj->fk_facture : null,
                'fk_facture_line' => $obj->fk_facture_line !== null ? (int) $obj->fk_facture_line : null,
                'used'            => ($obj->fk_facture !== null || $obj->fk_facture_line !== null),
            );
        }
        $this->db->free($resql);

        return $rows;
    }

    /**
     * @brief Convert credit note to discounts
     * @param  int $id  Credit note id {@from path}
     * @return array
     * @url POST /creditnotes/{id}/convert
     * @throws RestException 403, 404, 409, 500
     */
    public function convertCreditNote($id)
    {
        $this->checkKsefWritePermission();
        $note = $this->checkFactureAccess((int) $id);
        if (!DolibarrApiAccess::$user->hasRight('facture', 'creer')) {
            throw new RestException(403, 'Not enough permissions (facture/creer)');
        }
        if ((int) $note->type !== Facture::TYPE_CREDIT_NOTE) {
            throw new RestException(409, 'Only credit notes can be converted here; replacement money is trigger-driven');
        }
        if ((int) $note->statut <= 0) {
            throw new RestException(409, 'Credit note must be validated before conversion');
        }

        require_once DOL_DOCUMENT_ROOT . '/core/class/discount.class.php';

        $this->lockNoteForSettlement((int) $id);
        $state = $this->computeCreditNoteState((int) $id);
        if ($state === false) {
            $this->db->rollback();
            throw new RestException($this->creditNoteNotFound ? 404 : 500, $this->creditNoteError);
        }

        if ($state['converted_ttc'] > self::EPSILON) {
            $this->db->rollback();
            return array('converted' => true, 'already_converted' => true, 'discount_rows' => $state['discount_rows']);
        }
        try {
            $this->settleGuard($state, self::OP_CONVERT);
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }

        $note->fetch_lines();
        $amount_ht = $amount_tva = $amount_ttc = array();
        $mc_ht = $mc_tva = $mc_ttc = array();
        foreach ($note->lines as $line) {
            if ($line->product_type >= 9
                || ((float) $line->total_ht == 0.0 && (float) $line->total_tva == 0.0 && (float) $line->total_ttc == 0.0)) {
                continue;
            }
            $r = (string) $line->tva_tx;
            if (!isset($amount_ht[$r])) {
                $amount_ht[$r] = $amount_tva[$r] = $amount_ttc[$r] = 0.0;
                $mc_ht[$r] = $mc_tva[$r] = $mc_ttc[$r] = 0.0;
            }
            $amount_ht[$r]  += (float) $line->total_ht;
            $amount_tva[$r] += (float) $line->total_tva;
            $amount_ttc[$r] += (float) $line->total_ttc;
            $mc_ht[$r]  += (float) $line->multicurrency_total_ht;
            $mc_tva[$r] += (float) $line->multicurrency_total_tva;
            $mc_ttc[$r] += (float) $line->multicurrency_total_ttc;
        }

        if (!empty($amount_ttc)) {
            $maxRate = null;
            $maxAbs = -1.0;
            foreach ($amount_ttc as $r => $v) {
                if (abs($v) > $maxAbs) {
                    $maxAbs = abs($v);
                    $maxRate = $r;
                }
            }
            $reconcile = function ($header, &$bucket) use ($maxRate) {
                $residue = (float) $header - array_sum($bucket);
                if (abs($residue) < 1.0) {
                    $bucket[$maxRate] += $residue;
                }
            };
            $reconcile($note->total_ht ?? 0, $amount_ht);
            $reconcile($note->total_tva ?? 0, $amount_tva);
            $reconcile($note->total_ttc ?? 0, $amount_ttc);
            $reconcile($note->multicurrency_total_ht ?? 0, $mc_ht);
            $reconcile($note->multicurrency_total_tva ?? 0, $mc_tva);
            $reconcile($note->multicurrency_total_ttc ?? 0, $mc_ttc);
        }

        $recheck = $this->computeCreditNoteState((int) $id);
        if ($recheck !== false && $recheck['converted_ttc'] > self::EPSILON) {
            $this->db->rollback();
            return array('converted' => true, 'already_converted' => true, 'discount_rows' => $recheck['discount_rows']);
        }

        foreach ($amount_ht as $r => $ignore) {
            $discount = new DiscountAbsolute($this->db);
            $discount->description = '(CREDIT_NOTE)';
            $discount->fk_soc = $note->socid;
            $discount->socid = $note->socid;
            $discount->fk_facture_source = $note->id;
            $discount->amount_ht = $discount->total_ht = abs($amount_ht[$r]);
            $discount->amount_tva = $discount->total_tva = abs($amount_tva[$r]);
            $discount->amount_ttc = $discount->total_ttc = abs($amount_ttc[$r]);
            $discount->multicurrency_amount_ht = $discount->multicurrency_total_ht = abs($mc_ht[$r]);
            $discount->multicurrency_amount_tva = $discount->multicurrency_total_tva = abs($mc_tva[$r]);
            $discount->multicurrency_amount_ttc = $discount->multicurrency_total_ttc = abs($mc_ttc[$r]);
            $discount->tva_tx = abs((float) $r);
            if ($discount->create(DolibarrApiAccess::$user) < 0) {
                $this->db->rollback();
                throw new RestException(500, ksefDbErrorForUser(DolibarrApiAccess::$user, 'KSEF_DbErrorGeneric', 'Could not create discount row: ' . $discount->error));
            }
        }
        if ($note->setPaid(DolibarrApiAccess::$user) < 0) {
            $this->db->rollback();
            throw new RestException(500, ksefDbErrorForUser(DolibarrApiAccess::$user, 'KSEF_DbErrorGeneric', 'Could not set the credit note paid: ' . $note->error));
        }
        $this->db->commit();

        $after = $this->computeCreditNoteState((int) $id);
        return array('converted' => true, 'already_converted' => false, 'discount_rows' => $after ? $after['discount_rows'] : array());
    }

    /**
     * @brief Apply credit note as lines
     * @param  int $id                Credit note id {@from path}
     * @param  int $target_invoice_id Draft invoice id {@from body}
     * @return array
     * @url POST /creditnotes/{id}/apply-line
     * @throws RestException 400, 403, 404, 405, 409, 500
     */
    public function applyCreditNoteLine($id, $target_invoice_id = 0)
    {
        return $this->applyCreditNote((int) $id, (int) $target_invoice_id, 'apply-line');
    }

    /**
     * @brief Apply credit note as payment
     * @param  int $id                Credit note id {@from path}
     * @param  int $target_invoice_id Validated invoice id {@from body}
     * @param  int $closeifpaid       1: close when paid {@from query}
     * @return array
     * @url POST /creditnotes/{id}/apply-payment
     * @throws RestException 400, 403, 404, 409, 500
     */
    public function applyCreditNotePayment($id, $target_invoice_id = 0, $closeifpaid = 1)
    {
        return $this->applyCreditNote((int) $id, (int) $target_invoice_id, 'apply-payment', (int) $closeifpaid);
    }

    /**
     * @brief Apply credit note
     * @param  int    $id        Credit note id
     * @param  int    $targetId
     * @param  string $op
     * @param  int    $closeifpaid
     * @return array
     * @throws RestException 400, 403, 404, 405, 409, 500
     */
    private function applyCreditNote($id, $targetId, $op, $closeifpaid = 1)
    {
        $this->checkKsefWritePermission();
        $note = $this->checkFactureAccess($id);
        $rightNeeded = ($op === self::OP_APPLY_LINE) ? 'creer' : 'paiement';
        if (!DolibarrApiAccess::$user->hasRight('facture', $rightNeeded)) {
            throw new RestException(403, 'Not enough permissions (facture/' . $rightNeeded . ')');
        }
        if ((int) $note->type !== Facture::TYPE_CREDIT_NOTE) {
            throw new RestException(409, 'Only credit notes can be applied here');
        }
        if ((int) $note->statut <= 0) {
            throw new RestException(409, 'Credit note must be validated to apply its credit');
        }
        if (empty($targetId)) {
            throw new RestException(400, 'target_invoice_id is mandatory');
        }
        $target = $this->checkFactureAccess($targetId);
        $baseCurrency = getDolGlobalString('MAIN_MONNAIE', '');
        $noteCurrency = $note->multicurrency_code !== '' ? $note->multicurrency_code : $baseCurrency;
        $targetCurrency = $target->multicurrency_code !== '' ? $target->multicurrency_code : $baseCurrency;
        if ((string) $noteCurrency !== (string) $targetCurrency) {
            throw new RestException(409, 'Credit note and target invoice must be in the same currency');
        }

        require_once DOL_DOCUMENT_ROOT . '/core/class/discount.class.php';

        $this->lockFacturesForSettlement(array($id, $targetId));
        if ($target->fetch((int) $targetId) <= 0) {
            $this->db->rollback();
            throw new RestException(404, 'Target invoice not found');
        }
        $state = $this->computeCreditNoteState($id);
        if ($state === false) {
            $this->db->rollback();
            throw new RestException($this->creditNoteNotFound ? 404 : 500, $this->creditNoteError);
        }
        try {
            $this->settleGuard($state, $op, $this->buildTargetInfo($target));
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }

        $applied = array();
        foreach ($state['discount_rows'] as $row) {
            if ($row['used']) {
                continue;
            }
            if ($op === self::OP_APPLY_LINE) {
                if ($target->insert_discount($row['id']) < 0) {
                    $this->db->rollback();
                    throw new RestException(500, ksefDbErrorForUser(DolibarrApiAccess::$user, 'KSEF_DbErrorGeneric', 'Could not apply discount line: ' . $target->error));
                }
            } else {
                $discount = new DiscountAbsolute($this->db);
                if ($discount->fetch($row['id']) <= 0) {
                    $this->db->rollback();
                    throw new RestException(500, 'Discount row not found: ' . $row['id']);
                }
                if ($discount->link_to_invoice(0, $target->id) < 0) {
                    $this->db->rollback();
                    throw new RestException(500, ksefDbErrorForUser(DolibarrApiAccess::$user, 'KSEF_DbErrorGeneric', 'Could not apply credit as payment: ' . $discount->error));
                }
            }
            $applied[] = (int) $row['id'];
        }

        if ($op === self::OP_APPLY_PAYMENT && $closeifpaid) {
            $target->fetch($target->id);
            // no DISCOUNT_MODIFY close
            if (ksefCloseForeignPaid($this->db, DolibarrApiAccess::$user, $target) < 0) {
                $this->db->rollback();
                throw new RestException(500, ksefDbErrorForUser(DolibarrApiAccess::$user, 'KSEF_DbErrorGeneric', 'Could not set target paid: ' . $target->error));
            }
            $fst = ksefForeignSettledState($target);
            $paidOff = $fst['foreign'] ? ($fst['settled'] && $fst['remain_pln'] == 0) : (float) $target->getRemainToPay() <= self::EPSILON;
            if ($paidOff && (int) $target->paye !== 1) {
                if ($target->setPaid(DolibarrApiAccess::$user) < 0) {
                    $this->db->rollback();
                    throw new RestException(500, ksefDbErrorForUser(DolibarrApiAccess::$user, 'KSEF_DbErrorGeneric', 'Could not set target paid: ' . $target->error));
                }
            }
        }
        $this->db->commit();

        $target->fetch($target->id);
        $isForeign = (string) $targetCurrency !== (string) $baseCurrency;
        return array(
            'applied' => $applied,
            'target'  => array(
                'id'                       => (int) $target->id,
                'remaining_after'          => (float) $target->getRemainToPay(),
                'currency'                 => (string) $targetCurrency,
                'remaining_after_currency' => (float) price2num($target->getRemainToPay($isForeign ? 1 : 0), 'MT'),
                'paye'                     => (int) $target->paye,
            ),
        );
    }

    /**
     * @brief Refund credit note
     * @param  int    $id           Credit note id {@from path}
     * @param  float  $amount       Positive amount {@from body}
     * @param  int    $paymentid    Payment mode id {@from body}
     * @param  int    $accountid    Bank account id {@from body}
     * @param  string $datepaye     YYYY-MM-DD or timestamp {@from body}
     * @param  string $num_payment  Payment reference {@from body}
     * @param  string $comment      Private note {@from body}
     * @param  string $currency     Note currency, empty = PLN {@from body}
     * @return array
     * @url POST /creditnotes/{id}/refund
     * @throws RestException 400, 403, 404, 409, 500
     */
    public function refundCreditNote($id, $amount = 0, $paymentid = 0, $accountid = 0, $datepaye = '', $num_payment = '', $comment = '', $currency = '')
    {
        $this->checkKsefWritePermission();
        $note = $this->checkFactureAccess((int) $id);
        if (!DolibarrApiAccess::$user->hasRight('facture', 'paiement')) {
            throw new RestException(403, 'Not enough permissions (facture/paiement)');
        }
        if ((int) $note->type !== Facture::TYPE_CREDIT_NOTE) {
            throw new RestException(409, 'Only credit notes can be refunded here; replacement money is trigger-driven');
        }
        if ((int) $note->statut <= 0) {
            throw new RestException(409, 'Credit note must be validated before it can be refunded');
        }
        $amount = (float) $amount;
        if (empty($paymentid)) {
            throw new RestException(400, 'paymentid is mandatory');
        }
        global $conf;
        $baseCurrency = (is_object($conf) && !empty($conf->currency)) ? (string) $conf->currency : getDolGlobalString('MAIN_MONNAIE', '');
        $noteCurrency = (string) $note->multicurrency_code !== '' ? (string) $note->multicurrency_code : $baseCurrency;
        $currency = strtoupper(trim((string) $currency));
        $mcOff = $noteCurrency !== $baseCurrency && !isModEnabled('multicurrency');
        $amountInBase = $noteCurrency !== $baseCurrency && $currency === '' && !$mcOff;
        if ($amountInBase && (float) $note->multicurrency_tx <= 0) {
            throw new RestException(409, 'The credit note in ' . $noteCurrency . ' has no exchange rate: give the amount in ' . $noteCurrency . ' with currency ' . $noteCurrency);
        }
        if ($currency !== '' && $currency !== $noteCurrency) {
            throw new RestException(400, 'The refund is in the credit note\'s currency ' . $noteCurrency . ', not ' . $currency);
        }
        if ($mcOff && $currency !== '') {
            throw new RestException(409, 'A credit note in ' . $noteCurrency . ' is refunded in ' . $noteCurrency . ', which needs the Multicurrency module enabled; without currency the amount is in ' . $baseCurrency);
        }

        $paiementcode = (string) dol_getIdFromCode($this->db, (int) $paymentid, 'c_paiement', 'id', 'code', 1);
        if ($paiementcode === '' || $paiementcode === '-1') {
            throw new RestException(400, 'paymentid does not match a known payment mode');
        }

        if (isModEnabled('bank') && !empty($accountid)) {
            require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
            $bankAcc = new Account($this->db);
            if ($bankAcc->fetch((int) $accountid) <= 0) {
                throw new RestException(404, 'Bank account not found or not allowed for this entity');
            }
        }

        if ($datepaye === '' || $datepaye === null) {
            $datepaye_ts = dol_now();
        } elseif (is_numeric($datepaye)) {
            $datepaye_ts = (int) $datepaye;
        } else {
            require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
            $datepaye_ts = dol_stringtotime($datepaye);
        }
        if ($datepaye_ts <= 0) {
            throw new RestException(400, 'datepaye is not a valid date');
        }

        require_once DOL_DOCUMENT_ROOT . '/compta/paiement/class/paiement.class.php';

        $this->lockNoteForSettlement((int) $id);
        $state = $this->computeCreditNoteState((int) $id);
        if ($state === false) {
            $this->db->rollback();
            throw new RestException($this->creditNoteNotFound ? 404 : 500, $this->creditNoteError);
        }
        // amount > 0
        if ($amountInBase && !empty($state['foreign']) && $amount > 0) {
            $plnRemain = (float) $state['remaining_refundable'];
            if (abs($amount) > $plnRemain + self::EPSILON) {
                $this->db->rollback();
                throw new RestException(409, 'Refund amount ' . abs($amount) . ' ' . $baseCurrency . ' exceeds remaining refundable ' . $plnRemain . ' ' . $baseCurrency);
            }
            if ((float) $state['remaining_refundable_currency'] <= 0 && empty($state['paye'])) {
                $this->db->rollback();
                throw new RestException(409, 'Nothing left to refund on this credit note in ' . $noteCurrency);
            }
            $full = abs($amount) > 0 && (float) price2num(abs($amount), 'MT') >= (float) price2num($plnRemain, 'MT');
            // rounding exceeds remain
            $amount = $full ? (float) $state['remaining_refundable_currency']
                : min((float) price2num(abs($amount) * (float) $note->multicurrency_tx, 'MT'), (float) $state['remaining_refundable_currency']);
        }
        try {
            $this->settleGuard($state, self::OP_REFUND, null, $amount);
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }

        $foreign = !empty($state['foreign']);
        $amount = min(abs((float) $amount), (float) $state[$foreign ? 'remaining_refundable_currency' : 'remaining_refundable']);
        if ((float) price2num($amount, 'MT') <= 0.0) {
            $this->db->rollback();
            throw new RestException(409, 'Nothing left to refund on this credit note');
        }

        $amounts = array((int) $id => (float) price2num(-1 * abs($amount), 'MT'));
        $mcAmounts = array();
        if ($foreign) {
            $mcAmounts = $amounts;
            $amounts = array();
        }

        $recheck = $this->computeCreditNoteState((int) $id);
        if ($recheck !== false && (float) $recheck['converted_ttc'] > self::EPSILON) {
            $this->db->rollback();
            throw new RestException(409, 'Credit note was converted to credit; cannot also refund');
        }

        $payment = new Paiement($this->db);
        $payment->datepaye = $datepaye_ts;
        $payment->amounts = $amounts;
        if ($mcAmounts) {
            $payment->multicurrency_amounts = $mcAmounts;
            $payment->multicurrency_code = array((int) $id => $noteCurrency);
            $payment->multicurrency_tx = array((int) $id => (float) $note->multicurrency_tx);
        }
        $payment->paiementid = (int) $paymentid;
        $payment->paiementcode = $paiementcode;
        $payment->num_payment = $num_payment;
        $payment->note_private = $comment;

        $payment_id = $payment->create(DolibarrApiAccess::$user, 0);
        if ($payment_id < 0) {
            $this->db->rollback();
            throw new RestException(400, 'Payment error: ' . $payment->error);
        }
        if (isModEnabled('bank')) {
            $result = $payment->addPaymentToBank(DolibarrApiAccess::$user, 'payment', '(CustomerInvoicePaymentBack)', (int) $accountid, '', '');
            if ($result < 0) {
                $this->db->rollback();
                throw new RestException(400, 'Add payment to bank error: ' . $payment->error);
            }
        }

        $postState = $this->computeCreditNoteState((int) $id);
        $remainingAfter = ($postState !== false) ? (float) $postState['remaining_refundable'] : null;
        $remainingAfterCur = ($postState !== false) ? (float) $postState['remaining_refundable_currency'] : null;
        if ($foreign) {
            if ($remainingAfterCur !== null && $remainingAfterCur == 0 && (int) $note->paye !== 1) {
                $note->fetch((int) $id);
                $closed = ksefCloseForeignPaid($this->db, DolibarrApiAccess::$user, $note);
                if ($closed < 0 || ($closed === 0 && (int) $note->paye !== 1 && $note->setPaid(DolibarrApiAccess::$user) < 0)) {
                    $this->db->rollback();
                    throw new RestException(500, ksefDbErrorForUser(DolibarrApiAccess::$user, 'KSEF_DbErrorGeneric', 'Could not set the credit note paid: ' . $note->error));
                }
            }
        } elseif ($remainingAfter !== null && $remainingAfter <= self::EPSILON) {
            if ((int) $note->paye !== 1 && $note->setPaid(DolibarrApiAccess::$user) < 0) {
                $this->db->rollback();
                throw new RestException(500, ksefDbErrorForUser(DolibarrApiAccess::$user, 'KSEF_DbErrorGeneric', 'Could not set the credit note paid: ' . $note->error));
            }
        }
        $this->db->commit();

        return array(
            'payment_id'                 => (int) $payment_id,
            'refunded_currency'          => (float) price2num(abs($amount), 'MT'),
            'remaining_refundable_after' => $remainingAfter,
            'currency'                   => $mcOff ? $baseCurrency : $noteCurrency,
            'remaining_refundable_after_currency' => $remainingAfterCur,
        );
    }

    /**
     * @brief Set KOR fields
     * @param  int    $invoice_id      Correction id {@from path}
     * @param  string $reason          Max 256 chars {@from body}
     * @param  int    $correction_type 1, 2 or 3 {@from body}
     * @return array
     * @url PUT /submissions/{invoice_id}/correction
     * @throws RestException 400, 403, 404, 409, 500
     */
    public function setCorrection($invoice_id, $reason = '', $correction_type = 0)
    {
        $this->checkKsefWritePermission();
        $invoice = $this->checkFactureAccess((int) $invoice_id);
        if (!DolibarrApiAccess::$user->hasRight('facture', 'creer')) {
            throw new RestException(403, 'Not enough permissions (facture/creer)');
        }

        $correction_type = (int) $correction_type;
        $reason = trim((string) $reason);
        if (!in_array($correction_type, array(1, 2, 3), true)) {
            throw new RestException(400, 'correction_type must be 1, 2 or 3');
        }
        if ($reason === '' || mb_strlen($reason) > 256) {
            throw new RestException(400, 'reason must be non-empty and at most 256 characters');
        }
        if (!in_array((int) $invoice->type, array(Facture::TYPE_CREDIT_NOTE, Facture::TYPE_REPLACEMENT), true)
            || empty($invoice->fk_facture_source)) {
            throw new RestException(409, 'Invoice is not a correction (credit note or replacement) with a source invoice');
        }

        $sub = new KsefSubmission($this->db);
        if ($sub->fetchByInvoice((int) $invoice_id) > 0
            && in_array($sub->status, array('ACCEPTED', 'SUBMITTED', 'OFFLINE'), true)) {
            throw new RestException(409, 'Correction already submitted to KSeF (' . $sub->status . '); fields are locked');
        }

        $invoice->array_options['options_ksef_correction_reason'] = $reason;
        $invoice->array_options['options_ksef_correction_type'] = $correction_type;
        if ($invoice->insertExtraFields() < 0) {
            throw new RestException(500, ksefDbErrorForUser(DolibarrApiAccess::$user, 'KSEF_DbErrorGeneric', 'Could not save correction fields: ' . $invoice->error));
        }

        return array(
            'invoice_id'        => (int) $invoice_id,
            'correction_reason' => $reason,
            'correction_type'   => $correction_type,
        );
    }

    /**
     * @brief Invoice exchange rates
     * @param  int $invoice_id Invoice id {@from path}
     * @param  int $check_nbp  1: query NBP {@from query}
     * @return array
     * @url GET /rates/{invoice_id}
     * @throws RestException 403, 404
     */
    public function getInvoiceRates($invoice_id, $check_nbp = 0)
    {
        $this->checkKsefReadPermission();
        $invoice = $this->checkFactureAccess((int) $invoice_id);
        $invoice->fetch_optionals();
        $out = array('invoice_id' => (int) $invoice->id, 'currency' => (string) $invoice->multicurrency_code, 'rate' => null, 'table_date' => null, 'typed' => null, 'lines' => array());
        if (!ksefInvoiceIsForeign($invoice)) {
            return $out;
        }
        $k = ksefInvoiceKurs($invoice, array('db' => $this->db));
        $out['rate'] = $k > 0 ? $k : null;
        $kd = $invoice->array_options['options_ksef_kurs_data'] ?? '';
        $out['table_date'] = $kd !== '' && $kd !== null ? dol_print_date(is_numeric($kd) ? (int) $kd : strtotime((string) $kd), '%Y-%m-%d', 'tzserver') : null;
        $typed = ksefTypedRateCheck($this->db, $invoice, array('ask_nbp' => (int) $check_nbp > 0));
        if ($typed !== null) {
            $out['typed'] = array('state' => $typed['state'], 'nbp' => $typed['nbp']);
        }
        $map = ksefLineKursMap($this->db, $invoice);
        foreach (ksefLineKursList($this->db, $invoice) as $lid => $l) {
            $own = isset($map['lines'][$lid]) ? (float) $map['lines'][$lid] : (float) $l['kurs'];
            $out['lines'][] = array('line_id' => (int) $lid, 'rate' => $own > 0 ? $own : ($k > 0 ? $k : null), 'own' => $own > 0,
                'delivery_day' => $l['day'] !== '' ? $l['day'] : null);
        }
        return $out;
    }

    /**
     * @brief Delete korekta
     * @param  int $invoice_id   Korekta id {@from path}
     * @param  int $idwarehouse  -1: no stock move {@from query}
     * @return array
     * @url DELETE /corrections/{invoice_id}
     * @throws RestException 400, 403, 404, 409
     */
    public function deleteCorrection($invoice_id, $idwarehouse = -1)
    {
        $this->checkKsefWritePermission();
        $invoice = $this->checkFactureAccess((int) $invoice_id);
        if (!DolibarrApiAccess::$user->hasRight('facture', 'supprimer')) {
            throw new RestException(403, 'Not enough permissions (facture/supprimer)');
        }
        if ((int) $invoice->type !== Facture::TYPE_REPLACEMENT || empty($invoice->fk_facture_source)) {
            throw new RestException(409, 'Invoice is not a korekta (replacement with a source invoice); use DELETE /invoices/{id}', array('status' => 'NOT_A_KOREKTA'));
        }
        $idwarehouse = (int) $idwarehouse;
        if ($idwarehouse !== -1) {
            require_once DOL_DOCUMENT_ROOT . '/product/stock/class/entrepot.class.php';
            $wh = new Entrepot($this->db);
            if ($idwarehouse <= 0 || !isModEnabled('stock') || $wh->fetch($idwarehouse) <= 0) {
                throw new RestException(400, 'idwarehouse must be -1 or an existing warehouse');
            }
            // core always restocks
            if ((int) $invoice->status < Facture::STATUS_VALIDATED || !getDolGlobalString('STOCK_CALCULATE_ON_BILL')) {
                throw new RestException(400, 'idwarehouse only for a validated korekta with stock decrease on invoice validation');
            }
            if (!ksefUserCan(DolibarrApiAccess::$user, 'stock_movement')) {
                throw new RestException(403, 'Not enough permissions to move stock (stock/mouvement/creer)');
            }
        }
        $result = $invoice->delete(DolibarrApiAccess::$user, 0, $idwarehouse);
        if ($result == 0) {
            throw new RestException(403, 'Invoice not erasable');
        }
        if ($result < 0) {
            throw new RestException(409, $invoice->error ?: implode(', ', (array) $invoice->errors), array('status' => 'NOT_DELETABLE'));
        }
        return array('success' => array('code' => 200, 'message' => 'Correction deleted'));
    }

    /**
     * @brief Ensure NBP rate
     * @param  Facture $invoice
     * @param  string  $pick     '', keep or exact
     * @return void
     * @throws RestException
     */
    private function ensureInvoiceNbpRate($invoice, $pick = '')
    {
        dol_include_once('/ksef/class/ksef_nbp_currency_rate.class.php');
        $invoice->fetch_optionals();
        $nbp = new KsefNbpCurrencyRate($this->db);
        if (!$nbp->invoiceNeedsNBPRate($invoice) || $nbp->invoiceHasNBPRate($invoice)) {
            return;
        }
        $pick = in_array((string) $pick, array('keep', 'exact'), true) ? (string) $pick : '';
        $choice = $nbp->correctionRateChoice($invoice);
        if (!$choice) {
            $pick = '';
        } elseif ($pick === '') {
            $src = new Facture($this->db);
            $src->fetch((int) $invoice->fk_facture_source);
            throw new RestException(409, 'NBP_RATE_CHOICE_REQUIRED: korekta ' . $invoice->ref . ' has no NBP rate and the stored NBP table of '
                . $src->ref . ' does not fit its date. Resend with nbp_rate_pick "keep" (the rate of ' . $src->ref . ') or "exact" (correct it: NBP table '
                . $choice['no'] . ' of ' . $choice['date'] . ', ' . $choice['rate'] . ' PLN); both keep the foreign amounts.',
                array('status' => 'NBP_RATE_CHOICE_REQUIRED', 'keep_rate' => $src->multicurrency_tx > 0 ? round(1 / (float) $src->multicurrency_tx, 6) : null,
                    'exact_rate' => (float) $choice['rate'], 'exact_date' => $choice['date'], 'exact_table' => $choice['no']));
        }
        if ($nbp->fetchAndStoreForInvoice($invoice, DolibarrApiAccess::$user, $pick) === false) {
            if ($nbp->errorCode === 'RATE_CHANGE_SETTLED') {
                throw new RestException(409, 'RATE_CHANGE_SETTLED: ' . $nbp->error, array('status' => 'RATE_CHANGE_SETTLED'));
            }
            if ($nbp->errorCode === 'RATE_CHANGE_BOOKED') {
                throw new RestException(409, 'RATE_CHANGE_BOOKED: ' . $nbp->error, array('status' => 'RATE_CHANGE_BOOKED'));
            }
            throw new RestException(502, 'Could not obtain the NBP rate for this foreign-currency invoice: ' . ($nbp->error ?: 'NBP unavailable'));
        }
    }

    /**
     * @brief Credit note state
     * @param  int $id  Credit note id {@from path}
     * @return array
     * @url GET /creditnotes/{id}/resolution-state
     * @throws RestException 403, 404, 409, 500
     */
    public function getCreditNoteResolutionState($id)
    {
        $this->checkKsefReadPermission();
        $invoice = $this->checkFactureAccess((int) $id);
        if ((int) $invoice->type !== Facture::TYPE_CREDIT_NOTE) {
            throw new RestException(409, 'Invoice ' . (int) $id . ' is not a credit note');
        }

        $state = $this->computeCreditNoteState((int) $id);
        if ($state === false) {
            throw new RestException($this->creditNoteNotFound ? 404 : 500, $this->creditNoteError);
        }
        $state['allowed_modes'] = self::allowedModes($state);

        return $state;
    }

    /**
     * @brief Health check
     * @return array
     * @url GET /status
     * @throws RestException 403
     */
    public function getStatus()
    {
        $this->checkKsefReadPermission();

        global $conf;

        $environment = ksefGetEnvironment();
        $nip = ksefSellerNip();
        $authMethod = getDolGlobalString('KSEF_AUTH_METHOD_' . $environment, 'token');

        $moduleVersion = '';
        dol_include_once('/ksef/core/modules/modKSEF.class.php');
        if (class_exists('modKSEF')) {
            $modKsef = new modKSEF($this->db);
            $moduleVersion = $modKsef->version;
        }

        $data = array(
            'module_enabled'  => isModEnabled('ksef'),
            'version'         => $moduleVersion,
            'environment'     => $environment,
            'nip_configured'  => !empty($nip),
            'auth_method'     => $authMethod,
            'auth_configured' => false,
            'connectivity'    => 'unknown',
        );

        if ($authMethod === 'token') {
            $data['auth_configured'] = !empty(
            getDolGlobalString('KSEF_AUTH_TOKEN_' . $environment, '')
            );
        } elseif ($authMethod === 'certificate') {
            $certCheck = function_exists('ksefCheckAuthCertificate')
                ? ksefCheckAuthCertificate($environment)
                : array('configured' => false);
            $data['auth_configured'] = !empty($certCheck['configured']);
        }

        $data['offline_certificate_configured'] = function_exists(
            'ksefIsOfflineCertificateConfigured'
        )
            ? !empty(ksefIsOfflineCertificateConfigured($environment)['valid'])
            : false;

        try {
            $client = new KsefClient($this->db, $environment);
            $data['connectivity'] = $client->testConnection()
                ? 'ok'
                : 'failed';
            if ($data['connectivity'] === 'failed') {
                $data['connectivity_error'] = $client->error;
            }
        } catch (Exception $e) {
            $data['connectivity'] = 'error';
            $data['connectivity_error'] = $e->getMessage();
        }

        $syncState = new KsefSyncState($this->db);
        $syncState->load('incoming');
        $data['incoming_sync'] = array(
            'last_sync'      => $syncState->last_sync ?: null,
            'hwm_date'       => $syncState->hwm_date ?: null,
            'is_running'     => $syncState->isFetchInProgress(),
            'is_rate_limited' => $syncState->isRateLimited(),
        );

        return $data;
    }

    /**
     * @brief Module configuration
     * @return array
     * @url GET /config
     * @throws RestException 403
     */
    public function getConfig()
    {
        $this->checkKsefReadPermission();

        $environment = ksefGetEnvironment();

        return array(
            'environment'       => $environment,
            'company_nip'       => ksefSellerNip(),
            'auth_method'       => getDolGlobalString('KSEF_AUTH_METHOD_' . $environment, 'token'),
            'timeout'           => ksefGetTimeout(),
            // deprecated, kept
            'add_to_pdf'        => '1',
            'add_qr'            => getDolGlobalString('KSEF_ADD_QR', '1'),
            'qr_size'           => getDolGlobalString('KSEF_QR_SIZE', '25'),
            // deprecated, kept
            'button_color'      => '#2f6db5',
            'nbp_rate_mode'     => getDolGlobalString('KSEF_NBP_RATE_MODE', 'keep_foreign'),
            'nbp_rate_date_method' => getDolGlobalString('KSEF_NBP_RATE_DATE_METHOD', 'vat'),
            'place_of_issue_mode' => getDolGlobalString(
                'KSEF_FA3_PLACE_OF_ISSUE_MODE', 'disabled'
            ),
            'fa3_options'       => array(
                'include_nrklienta' => getDolGlobalString(
                    'KSEF_FA3_INCLUDE_NRKLIENTA', '0'
                ),
                'include_indeks'    => getDolGlobalString(
                    'KSEF_FA3_INCLUDE_INDEKS', '0'
                ),
                'include_gtin'      => getDolGlobalString(
                    'KSEF_FA3_INCLUDE_GTIN', '0'
                ),
                'include_unit'      => getDolGlobalString(
                    'KSEF_FA3_INCLUDE_UNIT', '0'
                ),
                'include_bank_desc' => getDolGlobalString(
                    'KSEF_FA3_INCLUDE_BANK_DESC', '0'
                ),
            ),
            'features'          => array(
                'creditnote_discounts'     => true,
                'creditnote_convert'       => true,
                'creditnote_apply_line'    => true,
                'creditnote_apply_payment' => true,
                'creditnote_refund'        => true,
                'creditnote_state'         => true,
                'correction_route'         => true,
            ),
        );
    }

    /**
     * @brief Test connectivity
     * @return array
     * @url POST /test-connection
     * @throws RestException 403
     */
    public function testConnection()
    {
        $this->checkKsefReadPermission();

        global $conf;

        $environment = ksefGetEnvironment();

        try {
            $client = new KsefClient($this->db, $environment);
            $success = $client->testConnection();

            return array(
                'success'     => $success,
                'environment' => $environment,
                'api_url'     => $this->getApiUrl($environment),
                'error'       => $success ? null : $client->error,
                'tested_at'   => dol_now(),
            );
        } catch (Exception $e) {
            return array(
                'success'     => false,
                'environment' => $environment,
                'api_url'     => $this->getApiUrl($environment),
                'error'       => $e->getMessage(),
                'tested_at'   => dol_now(),
            );
        }
    }

    /**
     * @brief List page bounds
     * @param int $limit 0 = default, max 500
     * @param int $page 0-based
     * @return array array(limit, offset)
     */
    private function listPage($limit, $page)
    {
        $limit = (int) $limit > 0 ? min((int) $limit, 500) : getDolGlobalInt('MAIN_SIZE_LISTE_LIMIT', 25);
        return array($limit, $limit * max(0, (int) $page));
    }

    /**
     * @brief Check read permission
     * @return void
     * @throws RestException 403
     */
    private function checkKsefReadPermission()
    {
        $this->loadKsefClasses();
        if (!DolibarrApiAccess::$user->hasRight('ksef', 'read')) {
            throw new RestException(403, 'Not enough permissions to read KSeF data');
        }
    }

    /**
     * @brief Run settle guard
     * @param  array  $state
     * @param  string $op
     * @param  array|null $target
     * @param  float  $amount
     * @return void
     * @throws RestException
     */
    private function settleGuard($state, $op, $target = null, $amount = 0.0)
    {
        try {
            self::assertSettleable($state, $op, $target, $amount);
        } catch (RestException $e) {
            throw $e;
        } catch (Exception $e) {
            $code = $e->getCode();
            throw new RestException($code >= 400 ? $code : 409, $e->getMessage());
        }
    }

    /**
     * @brief Credit note state
     * @param  int         $id  Credit note id
     * @return array|false
     */
    private function computeCreditNoteState($id)
    {
        $id = (int) $id;
        dol_include_once('/compta/facture/class/facture.class.php');

        $this->creditNoteNotFound = false;
        $invoice = new Facture($this->db);
        if ($invoice->fetch($id) <= 0) {
            $this->creditNoteError = 'Credit note not found: ' . $id;
            $this->creditNoteNotFound = true;
            return false;
        }
        $invoice->fetch_lines();

        $total_ttc = abs((float) $invoice->total_ttc);

        $ht = array();
        $tva = array();
        $ttc = array();
        foreach ($invoice->lines as $line) {
            if ($line->product_type >= 9
                || ((float) $line->total_ht == 0.0 && (float) $line->total_tva == 0.0 && (float) $line->total_ttc == 0.0)) {
                continue;
            }
            $rate = (string) $line->tva_tx;
            if (!isset($ht[$rate])) {
                $ht[$rate] = $tva[$rate] = $ttc[$rate] = 0.0;
            }
            $ht[$rate]  += (float) $line->total_ht;
            $tva[$rate] += (float) $line->total_tva;
            $ttc[$rate] += (float) $line->total_ttc;
        }
        $buckets = array();
        foreach (array_keys($ht) as $rate) {
            $buckets[] = array(
                'tva_tx' => abs((float) $rate),
                'ht'     => abs($ht[$rate]),
                'tva'    => abs($tva[$rate]),
                'ttc'    => abs($ttc[$rate]),
            );
        }

        $discount_rows = array();
        $converted_ttc = 0.0;
        $applied_ttc   = 0.0;
        $converted_cur = 0.0;
        $sql = "SELECT rowid, tva_tx, amount_ht, amount_tva, amount_ttc, multicurrency_amount_ttc, fk_facture, fk_facture_line";
        $sql .= " FROM " . MAIN_DB_PREFIX . "societe_remise_except";
        $sql .= " WHERE fk_facture_source = " . $id;
        $sql .= " AND entity IN (" . getEntity('invoice') . ")";
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->creditNoteError = ksefDbErrorForUser(DolibarrApiAccess::$user, 'KSEF_DbErrorGeneric', 'Error querying discount rows: ' . $this->db->lasterror());
            $this->creditNoteNotFound = false;
            return false;
        }
        while ($obj = $this->db->fetch_object($resql)) {
            $used = ($obj->fk_facture !== null || $obj->fk_facture_line !== null);
            $row_ttc = abs((float) $obj->amount_ttc);
            $converted_ttc += $row_ttc;
            $converted_cur += abs((float) ($obj->multicurrency_amount_ttc ?? 0));
            if ($used) {
                $applied_ttc += $row_ttc;
            }
            $discount_rows[] = array(
                'id'              => (int) $obj->rowid,
                'tva_tx'          => abs((float) $obj->tva_tx),
                'amount_ht'       => abs((float) $obj->amount_ht),
                'amount_tva'      => abs((float) $obj->amount_tva),
                'amount_ttc'      => $row_ttc,
                'fk_facture'      => $obj->fk_facture !== null ? (int) $obj->fk_facture : null,
                'fk_facture_line' => $obj->fk_facture_line !== null ? (int) $obj->fk_facture_line : null,
                'used'            => $used,
            );
        }
        $this->db->free($resql);

        $refunded_ttc = 0.0;
        $refunded_cur = 0.0;
        $sql = "SELECT SUM(pf.amount) as total, SUM(pf.multicurrency_amount) as total_cur FROM " . MAIN_DB_PREFIX . "paiement_facture as pf";
        // no entity column
        $sql .= " INNER JOIN " . MAIN_DB_PREFIX . "facture as f ON f.rowid = pf.fk_facture";
        $sql .= " WHERE pf.fk_facture = " . $id;
        $sql .= " AND f.entity IN (" . getEntity('invoice') . ")";
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->creditNoteError = ksefDbErrorForUser(DolibarrApiAccess::$user, 'KSEF_DbErrorGeneric', 'Error querying payments: ' . $this->db->lasterror());
            return false;
        }
        if ($obj = $this->db->fetch_object($resql)) {
            $refunded_ttc = abs((float) $obj->total);
            $refunded_cur = abs((float) ($obj->total_cur ?? 0));
        }
        $this->db->free($resql);

        global $conf;
        $baseCurrency = (is_object($conf) && !empty($conf->currency)) ? (string) $conf->currency : getDolGlobalString('MAIN_MONNAIE', '');
        $currency = (string) $invoice->multicurrency_code !== '' ? (string) $invoice->multicurrency_code : $baseCurrency;
        if ($currency !== $baseCurrency && !isModEnabled('multicurrency')) {
            $currency = $baseCurrency;
        }
        $foreign = $currency !== $baseCurrency;
        $total_cur = $foreign ? abs((float) $invoice->multicurrency_total_ttc) : $total_ttc;
        if (!$foreign) {
            $refunded_cur = $refunded_ttc;
            $converted_cur = $converted_ttc;
        }

        $settled = $refunded_ttc + $converted_ttc;
        $ownKorekta = ((int) $invoice->type === Facture::TYPE_CREDIT_NOTE) ? ksefNoteKorektaInForce($this->db, $id) : null;

        return array(
            'id'                   => $id,
            'type'                 => (int) $invoice->type,
            'socid'                => (int) $invoice->socid,
            'validated'            => ((int) $invoice->statut > 0),
            'paye'                 => ((int) $invoice->paye === 1),
            'total_ttc'            => $total_ttc,
            'buckets'              => $buckets,
            'discount_rows'        => $discount_rows,
            'refunded_ttc'         => $refunded_ttc,
            'converted_ttc'        => $converted_ttc,
            'applied_ttc'          => $applied_ttc,
            'remaining_refundable' => max(0.0, $total_ttc - $settled),
            'remaining_convertible' => ($converted_ttc > self::EPSILON) ? 0.0 : max(0.0, $total_ttc - $refunded_ttc),
            'orphaned_conversion' => (((int) $invoice->statut <= 0) && $converted_ttc > self::EPSILON),
            'corrected_by'         => $ownKorekta ? (string) $ownKorekta->ref : '',
            'currency'             => $currency,
            'foreign'              => $foreign,
            'total_ttc_currency'   => (float) price2num($total_cur, 'MT'),
            'refunded_currency'    => (float) price2num($refunded_cur, 'MT'),
            'converted_currency'   => (float) price2num($converted_cur, 'MT'),
            'remaining_refundable_currency' => $foreign ? max(0.0, (float) price2num($total_cur - $refunded_cur - $converted_cur, 'MT')) : max(0.0, $total_ttc - $settled),
        );
    }

    /**
     * @brief Assert operation allowed
     * @param  array $state
     * @param  string $op     OP_* constant
     * @param  array|null $target
     * @param  float $amount
     * @return true
     */
    private static function assertSettleable($state, $op, $target = null, $amount = 0.0)
    {
        $refunded  = (float) $state['refunded_ttc'];
        $converted = (float) $state['converted_ttc'];

        if ((int) ($state['type'] ?? 0) !== 2) {
            throw new Exception('Only a credit note can be settled here', 409);
        }

        if (($op === self::OP_CONVERT || $op === self::OP_REFUND) && !empty($state['corrected_by'])) {
            throw new Exception('Credit note is corrected by ' . $state['corrected_by'] . ', which settles its value; convert or refund is not possible on the credit note', 409);
        }

        if ($op === self::OP_CONVERT) {
            if ($refunded > self::EPSILON) {
                throw new Exception('Credit note has been refunded (' . $refunded . '); cannot also convert to credit', 409);
            }
            if ($converted > self::EPSILON) {
                throw new Exception('Credit note is already converted (' . $converted . ')', 409);
            }
            return true;
        }

        if ($op === self::OP_REFUND) {
            if ($converted > self::EPSILON) {
                throw new Exception('Credit note has been converted to credit (' . $converted . '); cannot also refund', 409);
            }
            $remaining = (float) $state['remaining_refundable'];
            if ($amount <= 0.0) {
                throw new Exception('Refund amount must be greater than 0', 400);
            }
            if (!empty($state['foreign'])) {
                $remainingCur = (float) $state['remaining_refundable_currency'];
                if (!empty($state['paye'])) {
                    throw new Exception('Credit note is already paid; nothing left to refund', 409);
                }
                if ((float) price2num($amount, 'MT') > $remainingCur) {
                    throw new Exception('Refund amount ' . $amount . ' ' . $state['currency'] . ' exceeds remaining refundable ' . $remainingCur . ' ' . $state['currency'], 409);
                }
                return true;
            }
            if ($amount > $remaining + self::EPSILON) {
                throw new Exception('Refund amount ' . $amount . ' exceeds remaining refundable ' . $remaining, 409);
            }
            return true;
        }

        if ($op === self::OP_APPLY_LINE || $op === self::OP_APPLY_PAYMENT) {
            if ($converted <= self::EPSILON) {
                throw new Exception('Credit note is not converted; nothing to apply', 409);
            }
            $available = $converted - (float) $state['applied_ttc'];
            if ($available <= self::EPSILON) {
                throw new Exception('No unused converted credit remains to apply', 409);
            }
            if (!is_array($target)) {
                throw new Exception('A target invoice is required to apply credit', 400);
            }
            if ((int) $target['socid'] !== (int) $state['socid']) {
                throw new Exception('Target invoice belongs to a different customer', 409);
            }
            if (!in_array((int) $target['type'], array(0, 1, 3), true)) {
                throw new Exception('Target invoice type ' . (int) $target['type'] . ' cannot receive credit', 409);
            }
            if ((int) $target['paye'] === 1) {
                throw new Exception('Target invoice is already paid', 409);
            }
            if ($op === self::OP_APPLY_LINE && (int) $target['status'] !== 0) {
                throw new Exception('Target invoice is not a draft; cannot append a discount line', 405);
            }
            if ($op === self::OP_APPLY_PAYMENT && (int) $target['status'] !== 1) {
                throw new Exception('Target invoice must be validated to receive credit as payment', 409);
            }
            $target_remaining = isset($target['remaining']) ? (float) $target['remaining'] : null;
            if ($target_remaining !== null && $available > $target_remaining + self::EPSILON) {
                throw new Exception('Credit to apply ' . $available . ' exceeds target remaining ' . $target_remaining, 409);
            }
            return true;
        }

        throw new Exception('Unknown settlement operation: ' . $op, 400);
    }

    /**
     * @brief Allowed settle operations
     * @param  array $state
     * @return array         OP_* values
     */
    private static function allowedModes($state)
    {
        $modes = array();
        if ((int) $state['type'] !== 2 || empty($state['validated'])) {
            return $modes;
        }
        $refunded  = (float) $state['refunded_ttc'];
        $converted = (float) $state['converted_ttc'];
        $available = $converted - (float) $state['applied_ttc'];

        $corrected = !empty($state['corrected_by']);
        if (!$corrected && $refunded <= self::EPSILON && $converted <= self::EPSILON) {
            $modes[] = self::OP_CONVERT;
        }
        $refundable = !empty($state['foreign']) ? (empty($state['paye']) && (float) $state['remaining_refundable_currency'] > 0) : (float) $state['remaining_refundable'] > self::EPSILON;
        if (!$corrected && $converted <= self::EPSILON && $refundable) {
            $modes[] = self::OP_REFUND;
        }
        if ($available > self::EPSILON) {
            $modes[] = self::OP_APPLY_LINE;
            $modes[] = self::OP_APPLY_PAYMENT;
        }
        return $modes;
    }

    /**
     * @brief Lock credit note
     * @param  int $id  Credit note id
     * @return void
     * @throws RestException
     */
    private function lockNoteForSettlement($id)
    {
        $this->db->begin();
        if (!$this->db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "facture WHERE rowid = " . (int) $id . " FOR UPDATE")) {
            $this->db->rollback();
            throw new RestException(500, ksefDbErrorForUser(DolibarrApiAccess::$user, 'KSEF_DbErrorGeneric', 'Could not lock the credit note: ' . $this->db->lasterror()));
        }
    }

    /**
     * @brief Lock invoice rows
     * @param array $ids Invoice ids
     * @return void
     */
    private function lockFacturesForSettlement($ids)
    {
        $clean = array();
        foreach ($ids as $x) {
            $x = (int) $x;
            if ($x > 0) {
                $clean[$x] = $x;
            }
        }
        if (empty($clean)) {
            throw new RestException(500, 'No valid invoice ids to lock for settlement');
        }
        sort($clean);
        $this->db->begin();
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "facture WHERE rowid IN (" . implode(',', $clean) . ") ORDER BY rowid FOR UPDATE";
        if (!$this->db->query($sql)) {
            $this->db->rollback();
            throw new RestException(500, ksefDbErrorForUser(DolibarrApiAccess::$user, 'KSEF_DbErrorGeneric', 'Could not lock invoices for settlement: ' . $this->db->lasterror()));
        }
    }

    /**
     * @brief Build guard target
     * @param  Facture $target
     * @return array
     */
    private function buildTargetInfo($target)
    {
        return array(
            'socid'     => (int) $target->socid,
            'type'      => (int) $target->type,
            'paye'      => (int) $target->paye,
            'status'    => (int) $target->statut,
            'remaining' => (float) $target->getRemainToPay(),
        );
    }

    /**
     * @brief Refuse unsendable invoice
     * @param KsefService $ksef
     * @param Facture $invoice
     * @return void
     * @throws RestException
     */
    private function checkInvoiceSubmittable($ksef, $invoice)
    {
        $reason = $ksef->checkInvoiceSubmittable($invoice);
        if ($reason !== '') {
            throw new RestException(409, $reason, array('status' => $ksef->notSubmittableCode !== '' ? $ksef->notSubmittableCode : 'INVOICE_NOT_VALIDATED'));
        }
    }

    /**
     * @brief Map send result
     * @param KsefService $ksef
     * @param array|false $result
     * @param string $fallback Default message
     * @return array
     * @throws RestException
     */
    private function mapSendResult($ksef, $result, $fallback)
    {
        if (!is_array($result)) {
            throw new RestException(500, $ksef->error ?: $fallback);
        }
        $status = $result['status'] ?? '';
        $msg = (string) ($result['error'] ?? $result['message'] ?? $ksef->error ?? '');
        $msg = $msg !== '' ? $msg : $fallback;
        $errorCode = (string) ($result['error_code'] ?? '');
        if (isset($result['record_saved']) && $result['record_saved'] === false) {
            throw new RestException(500, (string) ($result['warning'] ?? $msg), array('status' => 'ACCEPTED_NOT_RECORDED',
                'ksef_number' => $result['ksef_number'] ?? null, 'submission_id' => $result['submission_id'] ?? null));
        }
        if (in_array($status, array('ACCEPTED', 'OFFLINE'), true)) {
            if (!empty($ksef->warnings)) {
                $result['warnings'] = $ksef->warnings;
            }
            return $result;
        }
        if (in_array($status, array('NEEDS_CONFIRMATION', 'NEEDS_OFFLINE_CONFIRMATION'), true)) {
            throw new RestException(409, $msg, array('status' => $status, 'backdate_info' => $result['backdate_info'] ?? null, 'deadline' => $result['deadline'] ?? null));
        }
        if ($status === 'PENDING') {
            throw new RestException(409, $msg, array('status' => 'PENDING', 'submission_id' => $result['submission_id'] ?? null));
        }
        if ($status === 'TIMEOUT') {
            if (!empty($result['in_progress'])) {
                throw new RestException(409, $msg, array('status' => 'TIMEOUT', 'submission_id' => $result['submission_id'] ?? null));
            }
            return $result;
        }
        if (in_array($errorCode, array('INVOICE_NOT_VALIDATED', 'CUSTOMER_EXCLUDED', 'ISSUED_IN_PRODUCTION', 'CORRECTION_NO_SOURCE', 'CORRECTION_SOURCE_OTHER_ENV'), true)) {
            throw new RestException(409, $msg, array('status' => $errorCode));
        }
        if ($errorCode === 'DEPOSITS_ACK_REQUIRED') {
            throw new RestException(409, $msg, array('status' => $errorCode, 'negative_rate' => (int) ($result['negative_rate'] ?? 0)));
        }
        if (in_array($errorCode, array('DEPOSIT_NOT_IN_KSEF', 'DEPOSIT_CURRENCY_MISMATCH', 'NEGATIVE_RATE_ACK_REQUIRED', 'CORRECTION_SOURCE_REJECTED', 'CORRECTION_SOURCE_AWAITING_NUMBER',
            'CORRECTION_BEFORE_SOURCE_NUMBER', 'DEPOSIT_VAT_MISMATCH', 'SETTLEMENT_BELOW_ZERO'), true)) {
            throw new RestException(409, $msg, array('status' => $errorCode));
        }
        if ($errorCode === 'NBP_RATE_STALE') {
            throw new RestException(409, $msg, array('status' => 'NBP_RATE_STALE', 'rate_date' => $result['rate_date'] ?? null, 'reference_date' => $result['reference_date'] ?? null));
        }
        if ($errorCode === 'NBP_RATE_REQUIRED' || ($result['failure_kind'] ?? '') === 'local') {
            throw new RestException(422, $msg, array('status' => 'FAILED', 'error_code' => $errorCode !== '' ? $errorCode : null));
        }
        if (!empty($result['record_failed'])) {
            throw new RestException(500, ksefDbErrorForUser(DolibarrApiAccess::$user, 'KSEF_DbErrorGeneric', ''), array('status' => 'RECORD_FAILED'));
        }
        if (!empty($result['needs_certificate'])) {
            throw new RestException(422, $msg, array('status' => 'NEEDS_CERTIFICATE', 'missing_items' => $result['missing_items'] ?? null));
        }
        if (($result['error_code'] ?? '') === 'OFFLINE_REFUSED') {
            throw new RestException(409, $msg, array('status' => 'OFFLINE_REFUSED', 'reason' => $result['refusal'] ?? null));
        }
        if (!empty($result['rejected_offline'])) {
            throw new RestException(422, $msg, array('status' => 'REJECTED', 'ksef_code' => ctype_digit($errorCode) ? (int) $errorCode : null,
                'submission_id' => isset($result['submission_id']) ? (int) $result['submission_id'] : null, 'next' => 'technical_correction'));
        }
        if ($errorCode === 'NOT_RETRYABLE') {
            throw new RestException(409, $msg, array('status' => 'NOT_RETRYABLE'));
        }
        if ($status === 'ERROR') {
            if (($result['error_code'] ?? '') === 'NO_SUBMISSION') {
                throw new RestException(404, 'No submission found for invoice');
            }
            throw new RestException(500, $msg);
        }
        // transport wins
        if (($result['failure_kind'] ?? '') === 'transport') {
            throw new RestException(503, $msg, array('status' => 'FAILED', 'ksef_code' => ctype_digit($errorCode) ? (int) $errorCode : null,
                'submission_id' => isset($result['submission_id']) ? (int) $result['submission_id'] : null, 'can_use_offline' => !empty($result['can_use_offline'])));
        }
        if (ctype_digit($errorCode) || ($result['failure_kind'] ?? '') === 'local') {
            throw new RestException(422, $msg, array('status' => 'FAILED', 'ksef_code' => ctype_digit($errorCode) ? (int) $errorCode : null, 'submission_id' => $result['submission_id'] ?? null));
        }
        throw new RestException(503, $msg, array('status' => 'FAILED', 'submission_id' => $result['submission_id'] ?? null));
    }

    /**
     * @brief Fetch readable invoice
     * @param  int     $invoice_id  Invoice id
     * @return Facture
     * @throws RestException
     */
    private function checkFactureAccess($invoice_id)
    {
        dol_include_once('/compta/facture/class/facture.class.php');
        if (!DolibarrApiAccess::$user->hasRight('facture', 'lire')) {
            throw new RestException(403, 'Not enough permissions to read invoices (facture/lire)');
        }
        $invoice = new Facture($this->db);
        if ($invoice->fetch((int)$invoice_id) <= 0) {
            throw new RestException(404, 'Invoice not found');
        }
        if (!DolibarrApi::_checkAccessToResource('facture', $invoice->id)) {
            throw new RestException(403, 'Access not allowed for login ' . DolibarrApiAccess::$user->login);
        }
        return $invoice;
    }

    /**
     * @brief Invoice read right
     * @return void
     * @throws RestException 403
     */
    private function checkInvoiceReadRight()
    {
        if (!DolibarrApiAccess::$user->hasRight('facture', 'lire')) {
            throw new RestException(403, 'Not enough permissions to read invoices (facture/lire)');
        }
    }

    /**
     * @brief Readable sales row
     * @param int $id Sales row id
     * @return KsefSales
     * @throws RestException
     */
    private function fetchSalesForUser($id)
    {
        $this->checkKsefReadPermission();
        $this->checkInvoiceReadRight();
        dol_include_once('/ksef/class/ksef_sales.class.php');
        $row = new KsefSales($this->db);
        if ($row->fetch((int) $id) <= 0) {
            throw new RestException(404, 'Sales invoice not found');
        }
        if (!ksefUserCanReadSales(DolibarrApiAccess::$user, $row)) {
            throw new RestException(404, 'Sales invoice not found');
        }
        return $row;
    }

    /**
     * @brief Format sales row
     * @param KsefSales $row
     * @return array
     */
    private function formatSales($row)
    {
        $data = $this->formatIncoming($row);
        unset($data['fk_facture_fourn']);
        $status = $row->import_status === KsefSales::STATUS_FETCH_ERROR ? KsefSales::STATUS_FETCH_ERROR
            : ($row->import_status === KsefSales::STATUS_SKIPPED ? KsefSales::LINK_SKIPPED : ((string) $row->link_status !== '' ? $row->link_status : KsefSales::LINK_IMPORTABLE));
        $data['fk_facture'] = $row->fk_facture ? (int) $row->fk_facture : null;
        $data['invoice_hash'] = $row->invoice_hash;
        $data['link_status'] = $status;
        $data['link_candidate'] = $row->link_candidate ? (int) $row->link_candidate : null;
        $data['link_note'] = $row->link_note;
        return $data;
    }

    /**
     * @brief Check write permission
     * @return void
     * @throws RestException 403
     */
    private function checkKsefWritePermission()
    {
        $this->loadKsefClasses();
        if (!DolibarrApiAccess::$user->hasRight('ksef', 'write')) {
            throw new RestException(
                403,
                'Not enough permissions to create/modify KSeF data'
            );
        }
    }

    /**
     * @brief Check send right
     * @return void
     * @throws RestException 403
     */
    private function checkSendPermission()
    {
        $this->checkKsefWritePermission();
        if (!ksefUserCan(DolibarrApiAccess::$user, 'send')) {
            throw new RestException(403, 'Not enough permissions to send to KSeF (ksef write and facture creer)');
        }
    }

    /**
     * @brief Format submission
     * @param $sub
     * @param $full Include blobs
     * @return array
     */
    private function formatSubmission($sub, $full = false)
    {
        $data = array(
            'id'                    => (int)$sub->rowid,
            'invoice_id'            => (int)$sub->fk_facture,
            'fk_facture'            => (int)$sub->fk_facture,
            'ksef_reference'        => $sub->ksef_reference,
            'ksef_number'           => $sub->ksef_number,
            'ksef_session_reference' => $sub->getSessionReference(),
            'source'                => $sub->source,
            'invoice_hash'          => $sub->invoice_hash,
            'status'                => $sub->status,
            'environment'           => $sub->environment,
            'date_submission'       => $sub->date_submission,
            'date_acceptance'       => $sub->date_acceptance,
            'date_last_check'       => $sub->date_last_check,
            'error_message'         => $sub->error_message,
            'error_code'            => $sub->error_code,
            'retry_count'           => (int)$sub->retry_count,
            'fk_user_submit'        => (int)$sub->fk_user_submit,
            'offline_mode'          => $sub->offline_mode,
            'offline_deadline'      => $sub->offline_deadline,
            'offline_detected_reason' => $sub->offline_detected_reason,
            'fa3_creation_date'     => $sub->fa3_creation_date,
            'can_retry'             => $sub->canRetry(),
        );
        global $langs;
        $langs->load('ksef@ksef');
        $shown = (string) ksefDisplayStatus($sub);
        $data['display_status'] = $shown;
        $data['display_status_key'] = 'KSEF_STATUS_' . $shown;
        $data['display_status_label'] = $langs->transnoentitiesnoconv('KSEF_STATUS_' . $shown);

        if (!$sub->canRetry()) {
            $data['no_retry_reason'] = $sub->getNoRetryReason();
        }

        if (!empty($sub->error_details)) {
            $data['error_details'] = $sub->getErrorDetailsArray();
        }

        if ($full) {
            $data['fa3_xml'] = $sub->fa3_xml;
            $data['upo_xml'] = $sub->upo_xml;
            $data['api_response'] = $sub->api_response;
        } else {
            $data['has_fa3_xml'] = !empty($sub->fa3_xml);
            $data['has_upo_xml'] = !empty($sub->upo_xml);
        }

        return $data;
    }

    /**
     * @brief Format incoming
     * @param $inc
     * @return array
     */
    private function formatIncoming($inc)
    {
        return array(
            'id'                      => (int)$inc->rowid,
            'ksef_number'             => $inc->ksef_number,
            'seller_nip'              => $inc->seller_nip,
            'seller_name'             => $inc->seller_name,
            'seller_country'          => $inc->seller_country,
            'seller_address'          => $inc->seller_address,
            'buyer_nip'               => $inc->buyer_nip,
            'buyer_name'              => $inc->buyer_name,
            'invoice_number'          => $inc->invoice_number,
            'invoice_type'            => $inc->invoice_type,
            'invoice_date'            => $inc->invoice_date,
            'sale_date'               => $inc->sale_date,
            'currency'                => $inc->currency,
            'total_net'               => $inc->total_net,
            'total_vat'               => $inc->total_vat,
            'total_gross'             => $inc->total_gross,
            'payment_due_date'        => $inc->payment_due_date,
            'payment_method'          => $inc->payment_method,
            'bank_account'            => $inc->bank_account,
            'corrected_ksef_number'   => $inc->corrected_ksef_number,
            'corrected_invoice_number' => $inc->corrected_invoice_number,
            'corrected_invoice_date'  => $inc->corrected_invoice_date,
            'fa3_creation_date'       => $inc->fa3_creation_date,
            'fa3_system_info'         => $inc->fa3_system_info,
            'fetch_date'              => $inc->fetch_date,
            'environment'             => $inc->environment,
            'import_status'           => $inc->import_status,
            'import_date'             => $inc->import_date,
            'import_error'            => $inc->import_error,
            'fk_facture_fourn'        => $inc->fk_facture_fourn
                ? (int)$inc->fk_facture_fourn
                : null,
            'has_xml'                 => !empty($inc->fa3_xml),
        );
    }

    /**
     * @brief GUS NIP lookup
     * @param  string $nip  NIP {@from path}
     * @param  string $env  TEST or PROD {@from query}
     * @return array
     * @url GET /gus/{nip}
     * @throws RestException 400, 403, 404, 503
     */
    public function gusLookup($nip, $env = '')
    {
        $this->checkKsefReadPermission();

        if (empty(getDolGlobalString('KSEF_GUS_ENABLED'))) {
            throw new RestException(403, 'GUS lookup is disabled');
        }

        dol_include_once('/ksef/class/ksef_gus_client.class.php');

        $nip = ksefCleanNIP($nip);
        if (!ksefValidateNIP($nip)) {
            throw new RestException(400, 'Invalid NIP');
        }

        $env = $env ? strtoupper($env) : getDolGlobalString('KSEF_GUS_ENV', 'TEST');
        $client = new KsefGusClient($this->db, $env);
        $data = $client->lookupByNip($nip);

        if ($data === false) {
            $err = $client->error ? $client->error : 'error';
            if ($err === 'not_found' || $err === 'unsupported_type') {
                throw new RestException(404, 'No company found for NIP ' . $nip);
            }
            if ($err === 'invalid_nip') {
                throw new RestException(400, 'Invalid NIP');
            }
            throw new RestException(503, 'GUS lookup failed: ' . $err);
        }

        $data['env'] = ($env === 'PROD') ? 'PROD' : 'TEST';

        return $data;
    }

    /**
     * @brief KSeF API URL
     * @param $environment
     * @return string
     */
    private function getApiUrl($environment)
    {
        switch (strtoupper($environment)) {
            case 'PRODUCTION':
                return ksefEndpointOverride('KSEF', KsefClient::API_PROD);
            case 'DEMO':
                return ksefEndpointOverride('KSEF', KsefClient::API_DEMO);
            case 'TEST':
            default:
                return ksefEndpointOverride('KSEF', KsefClient::API_TEST);
        }
    }
}

class_alias('KsefApi', 'Ksef');