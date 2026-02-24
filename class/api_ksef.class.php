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
 * \brief   REST API for KSeF module
 *
 * Submissions:
 *   GET    /submissions                        List (filterable by status, env, date)
 *   GET    /submissions/stats                  Aggregate statistics
 *   GET    /submissions/{id}                   Single submission detail
 *   GET    /submissions/invoice/{invoice_id}   Lookup by Dolibarr invoice
 *   POST   /submissions/{invoice_id}/submit    Submit invoice online
 *   POST   /submissions/{invoice_id}/submit-offline  Submit in offline mode
 *   POST   /submissions/{invoice_id}/retry     Retry failed submission
 *   GET    /submissions/{invoice_id}/status    Poll KSeF status
 *   GET    /submissions/{invoice_id}/upo       Download UPO confirmation
 *   GET    /submissions/{invoice_id}/xml       Get generated FA(3) XML
 *
 * Incoming invoices:
 *   GET    /incoming                           List (filterable)
 *   GET    /incoming/{id}                      Detail with line items
 *   GET    /incoming/{id}/xml                  Raw FA(3) XML
 *   GET    /incoming/{id}/pdf                  PDF visualization (base64)
 *   POST   /incoming/{id}/import               Create supplier invoice from KSeF data
 *   POST   /incoming/{id}/link                 Link to existing supplier invoice
 *   DELETE /incoming/{id}                      Delete record
 *   POST   /incoming/sync                      Trigger async fetch from KSeF
 *   GET    /incoming/sync/status               Poll sync progress
 *   POST   /incoming/sync/reset                Reset stuck sync state
 *
 * Health / configuration:
 *   GET    /status                             Module health & connectivity
 *   GET    /config                             Non-sensitive module settings
 *   POST   /test-connection                    Live KSeF connectivity test
 */

use Luracast\Restler\RestException;

/**
 * KSeF REST API
 * @access protected
 * @class DolibarrApiAccess {@requires user,external}
 */
class KsefApi extends DolibarrApi
{
    public function __construct()
    {
        global $db;
        $this->db = $db;
    }

    /**
     * @brief Loads module classes
     * @return void
     * @called_by checkKsefReadPermission(), checkKsefWritePermission()
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
     * @brief List submissions with filters
     * @param  string $status        Filter by status (PENDING, ACCEPTED, REJECTED, FAILED, OFFLINE, TIMEOUT) {@from query}
     * @param  string $environment   Filter by environment (TEST, DEMO, PRODUCTION) {@from query}
     * @param  string $date_from     Filter: submitted after (YYYY-MM-DD) {@from query}
     * @param  string $date_to       Filter: submitted before (YYYY-MM-DD) {@from query}
     * @param  string $sortfield     Sort field {@from query}
     * @param  string $sortorder     Sort order ASC or DESC {@from query}
     * @param  int    $limit         Limit number of results (0 = conf default) {@from query}
     * @param  int    $page          Page number (0-based) {@from query}
     * @return array List of submission objects
     * @calls formatSubmission()
     * @url GET /submissions
     * @throws RestException 403
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

        if (empty($limit)) {
            $limit = getDolGlobalInt('MAIN_SIZE_LISTE_LIMIT', 25);
        }
        $offset = $limit * $page;

        $sql = "SELECT t.rowid FROM " . MAIN_DB_PREFIX . "ksef_submissions as t";
        $sql .= " WHERE 1 = 1";

        if (!empty($status)) {
            $sql .= " AND t.status = '" . $this->db->escape($status) . "'";
        }
        if (!empty($environment)) {
            $sql .= " AND t.environment = '" . $this->db->escape($environment) . "'";
        }
        if (!empty($date_from)) {
            $ts = strtotime($date_from);
            if ($ts !== false) {
                $sql .= " AND t.date_submission >= " . (int)$ts;
            }
        }
        if (!empty($date_to)) {
            $ts = strtotime($date_to . ' 23:59:59');
            if ($ts !== false) {
                $sql .= " AND t.date_submission <= " . (int)$ts;
            }
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
            throw new RestException(500, 'Database error: ' . $this->db->lasterror());
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $sub = new KsefSubmission($this->db);
            if ($sub->fetch($obj->rowid) > 0) {
                $result[] = $this->formatSubmission($sub);
            }
        }
        $this->db->free($resql);

        return $result;
    }


    /**
     * @brief Get submission statistics
     * @param  int   $days  Number of days to include (default 30) {@from query}
     * @return array Statistics (total, accepted, pending, failed, success_rate, common_errors)
     * @url GET /submissions/stats
     * @throws RestException 403
     */
    public function getSubmissionStats($days = 30)
    {
        $this->checkKsefReadPermission();

        $sub = new KsefSubmission($this->db);
        return $sub->getStatistics((int)$days);
    }


    /**
     * @brief Get submission details by ID
     * @param  int   $id  Submission row ID {@from path}
     * @return array      Submission object
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

        return $this->formatSubmission($sub, true);
    }


    /**
     * @brief Get submission for a specific invoice
     * @param  int   $invoice_id  Invoice (facture) ID {@from path}
     * @return array              Submission object
     * @url GET /submissions/invoice/{invoice_id}
     * @throws RestException 403, 404
     */
    public function getSubmissionByInvoice($invoice_id)
    {
        $this->checkKsefReadPermission();

        $sub = new KsefSubmission($this->db);
        if ($sub->fetchByInvoice((int)$invoice_id) <= 0) {
            throw new RestException(404, 'No submission found for this invoice');
        }

        return $this->formatSubmission($sub, true);
    }


    /**
     * @brief Submit invoice to KSeF (online)
     * @param  int   $invoice_id  Invoice (facture) ID {@from path}
     * @return array              Result with status, ksef_number, etc.
     * @url POST /submissions/{invoice_id}/submit
     * @throws RestException 403, 404, 500
     */
    public function submitInvoice($invoice_id)
    {
        $this->checkKsefWritePermission();
        dol_syslog("KsefApi::submitInvoice invoice_id=$invoice_id", LOG_INFO);

        $ksef = new KsefService($this->db);
        $result = $ksef->submitInvoice(
            (int)$invoice_id,
            DolibarrApiAccess::$user,
            'SYNC'
        );

        if (!$result) {
            throw new RestException(500, $ksef->error ?: 'Submission failed');
        }

        return $result;
    }


    /**
     * @brief Create offline submission for an invoice
     * @param  int    $invoice_id     Invoice (facture) ID {@from path}
     * @param  string $reason         Reason for offline mode {@from body}
     * @return array                  Result with status, offline_deadline, etc.
     * @url POST /submissions/{invoice_id}/submit-offline
     * @throws RestException 403, 404, 500
     */
    public function submitInvoiceOffline($invoice_id, $reason = 'api_request')
    {
        $this->checkKsefWritePermission();
        dol_syslog("KsefApi::submitInvoiceOffline invoice_id=$invoice_id reason=$reason", LOG_INFO);

        $ksef = new KsefService($this->db);
        $result = $ksef->submitInvoiceOffline(
            (int)$invoice_id,
            DolibarrApiAccess::$user,
            $reason
        );

        if (!$result) {
            throw new RestException(500, $ksef->error ?: 'Offline submission failed');
        }
        if ($result['status'] === 'FAILED') {
            $code = !empty($result['needs_certificate']) ? 422 : 500;
            throw new RestException($code, $result['error']);
        }

        return $result;
    }


    /**
     * @brief Retry a failed submission
     * @param  int   $invoice_id  Invoice (facture) ID {@from path}
     * @return array              Result with status
     * @url POST /submissions/{invoice_id}/retry
     * @throws RestException 403, 404, 500
     */
    public function retrySubmission($invoice_id)
    {
        $this->checkKsefWritePermission();
        dol_syslog("KsefApi::retrySubmission invoice_id=$invoice_id", LOG_INFO);

        $ksef = new KsefService($this->db);
        $result = $ksef->retrySubmission(
            (int)$invoice_id,
            DolibarrApiAccess::$user
        );

        if (!$result) {
            throw new RestException(500, $ksef->error ?: 'Retry failed');
        }

        return $result;
    }


    /**
     * @brief Check submission status at KSeF
     * @param  int   $invoice_id  Invoice (facture) ID {@from path}
     * @return array              Status result
     * @url GET /submissions/{invoice_id}/status
     * @throws RestException 403, 404
     */
    public function checkSubmissionStatus($invoice_id)
    {
        $this->checkKsefReadPermission();

        $ksef = new KsefService($this->db);
        $result = $ksef->checkStatus(
            (int)$invoice_id,
            DolibarrApiAccess::$user
        );

        if (!$result) {
            throw new RestException(500, $ksef->error ?: 'Status check failed');
        }

        return $result;
    }


    /**
     * @brief Download UPO confirmation for an invoice submission
     * @param  int   $invoice_id  Invoice (facture) ID {@from path}
     * @return array              UPO XML content and metadata
     * @url GET /submissions/{invoice_id}/upo
     * @throws RestException 403, 404
     */
    public function getSubmissionUpo($invoice_id)
    {
        $this->checkKsefReadPermission();

        $sub = new KsefSubmission($this->db);
        if ($sub->fetchByInvoice((int)$invoice_id) <= 0) {
            throw new RestException(404, 'No submission found for this invoice');
        }

        if (empty($sub->upo_xml)) {
            if ($sub->status === KsefSubmission::STATUS_ACCEPTED
                && !empty($sub->ksef_number)
            ) {
                global $conf;
                $client = new KsefClient(
                    $this->db,
                    $conf->global->KSEF_ENVIRONMENT ?? 'TEST'
                );
                try {
                    $upo = $client->downloadUPO($sub->ksef_number);
                    if ($upo) {
                        $sub->upo_xml = $upo;
                        $sub->update(DolibarrApiAccess::$user, 1);
                    }
                } catch (Exception $e) {}
            }

            if (empty($sub->upo_xml)) {
                throw new RestException(404, 'UPO not available yet');
            }
        }

        return array(
            'invoice_id'  => (int)$sub->fk_facture,
            'ksef_number' => $sub->ksef_number,
            'upo_xml'     => $sub->upo_xml,
        );
    }


    /**
     * @brief Get generated FA(3) XML for an invoice submission
     * @param  int   $invoice_id  Invoice (facture) ID {@from path}
     * @return array              FA3 XML content and metadata
     * @url GET /submissions/{invoice_id}/xml
     * @throws RestException 403, 404
     */
    public function getSubmissionXml($invoice_id)
    {
        $this->checkKsefReadPermission();

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
     * @brief List incoming invoices with filters
     * @param  string $seller_nip       Filter by seller NIP {@from query}
     * @param  string $seller_name      Filter by seller name (partial) {@from query}
     * @param  string $invoice_number   Filter by invoice number (partial) {@from query}
     * @param  string $ksef_number      Filter by KSeF number (partial) {@from query}
     * @param  string $import_status    Filter by import status (NEW, IMPORTED, ERROR) {@from query}
     * @param  string $environment      Filter by environment {@from query}
     * @param  string $date_from        Filter: invoice date from (YYYY-MM-DD) {@from query}
     * @param  string $date_to          Filter: invoice date to (YYYY-MM-DD) {@from query}
     * @param  string $sortfield        Sort field {@from query}
     * @param  string $sortorder        Sort order ASC or DESC {@from query}
     * @param  int    $limit            Limit number of results {@from query}
     * @param  int    $page             Page number (0-based) {@from query}
     * @return array                    List of incoming invoice objects
     * @url GET /incoming
     * @throws RestException 403
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

        if (empty($limit)) {
            $limit = getDolGlobalInt('MAIN_SIZE_LISTE_LIMIT', 25);
        }
        $offset = $limit * $page;

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
            if ($ts !== false) {
                $filters['invoice_date_start'] = $ts;
            }
        }
        if (!empty($date_to)) {
            $ts = strtotime($date_to . ' 23:59:59');
            if ($ts !== false) {
                $filters['invoice_date_end'] = $ts;
            }
        }

        $allowedSort = array(
            'i.invoice_date', 'i.fetch_date', 'i.seller_name',
            'i.total_gross', 'i.import_status', 'i.rowid'
        );
        if (!in_array($sortfield, $allowedSort)) {
            $sortfield = 'i.invoice_date';
        }
        $sortorder = strtoupper($sortorder) === 'ASC' ? 'ASC' : 'DESC';

        $incoming = new KsefIncoming($this->db);
        $records = $incoming->fetchAll(
            $filters, $sortfield, $sortorder, (int)$limit, (int)$offset
        );

        if ($records === -1) {
            throw new RestException(500, 'Database error: ' . $incoming->error);
        }

        $result = array();
        foreach ($records as $rec) {
            $result[] = $this->formatIncoming($rec);
        }

        return $result;
    }


    /**
     * @brief Get incoming invoice details
     * @param  int   $id  Incoming invoice row ID {@from path}
     * @return array      Incoming invoice object
     * @url GET /incoming/{id}
     * @throws RestException 403, 404
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
     * @brief Get raw FA(3) XML of an incoming invoice
     * @param  int   $id  Incoming invoice row ID {@from path}
     * @return array      XML content
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
     * @brief Get PDF of incoming invoice
     * @param  int   $id  Incoming invoice row ID {@from path}
     * @return array      PDF content (base64-encoded) and metadata
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
     * @brief Trigger incoming invoice sync from KSeF
     * @return array  Sync initiation result with reference number
     * @url POST /incoming/sync
     * @throws RestException 403, 409, 500
     */
    public function triggerIncomingSync()
    {
        $this->checkKsefWritePermission();
        dol_syslog("KsefApi::triggerIncomingSync", LOG_INFO);

        $ksef = new KsefService($this->db);
        $result = $ksef->initIncomingFetch(DolibarrApiAccess::$user);

        if ($result === false) {
            throw new RestException(500, $ksef->error ?: 'Failed to initiate sync');
        }

        if ($result['status'] === 'ALREADY_PROCESSING') {
            throw new RestException(409, 'Sync already in progress');
        }

        return $result;
    }


    /**
     * @brief Check incoming sync progress
     * @return array  Sync status with progress details
     * @url GET /incoming/sync/status
     * @throws RestException 403
     */
    public function getIncomingSyncStatus()
    {
        $this->checkKsefReadPermission();

        $ksef = new KsefService($this->db);
        $syncState = $ksef->getIncomingSyncState();

        $data = array(
            'is_fetching'    => $syncState->isFetchInProgress(),
            'is_processing'  => $syncState->isProcessingInProgress(),
            'is_rate_limited' => $syncState->isRateLimited(),
            'can_sync_now'   => $syncState->canSyncNow(),
            'fetch_reference' => $syncState->fetch_reference ?: null,
            'fetch_status'   => $syncState->fetch_status ?: null,
            'fetch_started'  => $syncState->fetch_started ?: null,
            'fetch_error'    => $syncState->fetch_error ?: null,
            'hwm_date'       => $syncState->hwm_date ?: null,
            'last_sync'      => $syncState->last_sync ?: null,
            'last_sync_new'  => (int)$syncState->last_sync_new,
            'last_sync_existing' => (int)$syncState->last_sync_existing,
            'last_sync_total' => (int)$syncState->last_sync_total,
        );

        if ($syncState->isProcessingInProgress()) {
            $data['processing_progress'] = $syncState->getProcessingProgress();
        }

        if ($syncState->isRateLimited()) {
            $data['rate_limit_until'] = $syncState->rate_limit_until;
            $data['rate_limit_seconds'] = $syncState->secondsUntilNextSync();
        }

        // If fetch is in progress but not timed out, try to advance
        if ($syncState->isFetchInProgress() && !$syncState->isFetchTimedOut()) {
            $ksef2 = new KsefService($this->db);
            $pollResult = $ksef2->checkIncomingFetchStatus(DolibarrApiAccess::$user);
            if ($pollResult) {
                $data['poll_result'] = $pollResult;
            }
        }

        return $data;
    }


    /**
     * @brief Reset stuck sync state
     * @param  int   $days_back  Reset HWM to this many days ago (default: keep current) {@from body}
     * @return array             Result
     * @url POST /incoming/sync/reset
     * @throws RestException 403, 500
     */
    public function resetIncomingSync($days_back = 0)
    {
        $this->checkKsefWritePermission();
        dol_syslog("KsefApi::resetIncomingSync days_back=$days_back", LOG_INFO);

        $ksef = new KsefService($this->db);

        if ($days_back > 0) {
            $result = $ksef->resetIncomingSyncState(
                DolibarrApiAccess::$user,
                (int)$days_back
            );
        } else {
            $result = $ksef->resetIncomingFetch();
        }

        if (!$result) {
            throw new RestException(500, 'Failed to reset sync state');
        }

        return array(
            'success' => true,
            'message' => $days_back > 0
                ? "Sync state reset, HWM moved back {$days_back} days"
                : 'Fetch state cleared',
        );
    }


    /**
     * @brief Delete an incoming invoice record
     * @param  int   $id  Incoming invoice row ID {@from path}
     * @return array      Result
     * @url DELETE /incoming/{id}
     * @throws RestException 403, 404
     */
    public function deleteIncoming($id)
    {
        $this->checkKsefWritePermission();
        dol_syslog("KsefApi::deleteIncoming id=$id", LOG_INFO);

        $incoming = new KsefIncoming($this->db);
        $res = $incoming->fetch((int)$id);

        if ($res <= 0) {
            throw new RestException(404, 'Incoming invoice not found');
        }

        $delResult = $incoming->delete(DolibarrApiAccess::$user);
        if ($delResult < 0) {
            throw new RestException(500, 'Delete failed: ' . $incoming->error);
        }

        return array(
            'success' => true,
            'message' => 'Incoming invoice deleted',
        );
    }

    /**
     * @brief Link incoming invoice to an existing supplier invoice
     * @param  int   $id                Incoming invoice row ID {@from path}
     * @param  int   $fk_facture_fourn  Existing supplier invoice ID to link {@from body}
     * @return array                    Result
     * @url POST /incoming/{id}/link
     * @throws RestException 403, 404, 422
     */
    public function linkIncoming($id, $fk_facture_fourn = 0)
    {
        $this->checkKsefWritePermission();
        dol_syslog("KsefApi::linkIncoming id=$id fk_facture_fourn=$fk_facture_fourn", LOG_INFO);

        if ($fk_facture_fourn <= 0) {
            throw new RestException(
                422,
                'fk_facture_fourn is required (supplier invoice ID)'
            );
        }

        $incoming = new KsefIncoming($this->db);
        $res = $incoming->fetch((int)$id);

        if ($res <= 0) {
            throw new RestException(404, 'Incoming invoice not found');
        }

        // Verify the supplier invoice exists
        require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
        $supInvoice = new FactureFournisseur($this->db);
        if ($supInvoice->fetch((int)$fk_facture_fourn) <= 0) {
            throw new RestException(
                404,
                'Supplier invoice #' . $fk_facture_fourn . ' not found'
            );
        }

        $incoming->fk_facture_fourn = (int)$fk_facture_fourn;
        $incoming->import_status = KsefIncoming::STATUS_IMPORTED;
        $incoming->import_date = dol_now();
        $incoming->import_error = null;

        $updResult = $incoming->update(DolibarrApiAccess::$user, 1);
        if ($updResult < 0) {
            throw new RestException(500, 'Failed to update: ' . $incoming->error);
        }

        return array(
            'success'          => true,
            'incoming_id'      => (int)$incoming->rowid,
            'fk_facture_fourn' => (int)$fk_facture_fourn,
            'import_status'    => KsefIncoming::STATUS_IMPORTED,
        );
    }


    /**
     * @brief Module health check
     * Returns authentication status, environment info, and connectivity.
     * @return array  status
     * @url GET /status
     * @throws RestException 403
     */
    public function getStatus()
    {
        $this->checkKsefReadPermission();

        global $conf;

        $environment = getDolGlobalString('KSEF_ENVIRONMENT', 'TEST');
        $nip = getDolGlobalString('KSEF_COMPANY_NIP', '');
        $authMethod = getDolGlobalString('KSEF_AUTH_METHOD', 'token');

        $data = array(
            'module_enabled'  => isModEnabled('ksef'),
            'version'         => '1.2.0',
            'environment'     => $environment,
            'nip_configured'  => !empty($nip),
            'auth_method'     => $authMethod,
            'auth_configured' => false,
            'connectivity'    => 'unknown',
        );

        // Check auth configuration
        if ($authMethod === 'token') {
            $data['auth_configured'] = !empty(
            getDolGlobalString('KSEF_AUTH_TOKEN', '')
            );
        } elseif ($authMethod === 'certificate') {
            $certCheck = function_exists('ksefCheckAuthCertificate')
                ? ksefCheckAuthCertificate()
                : array('configured' => false);
            $data['auth_configured'] = !empty($certCheck['configured']);
        }

        // Check offline certificate
        $data['offline_certificate_configured'] = function_exists(
            'ksefIsOfflineCertificateConfigured'
        )
            ? !empty(ksefIsOfflineCertificateConfigured()['configured'])
            : false;

        // connectivity test
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

        // Sync state summary
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
     * @brief Get current module configuration
     * @return array  Configuration values
     * @url GET /config
     * @throws RestException 403
     */
    public function getConfig()
    {
        $this->checkKsefReadPermission();

        return array(
            'environment'       => getDolGlobalString('KSEF_ENVIRONMENT', 'TEST'),
            'company_nip'       => getDolGlobalString('KSEF_COMPANY_NIP', ''),
            'auth_method'       => getDolGlobalString('KSEF_AUTH_METHOD', 'token'),
            'timeout'           => getDolGlobalInt('KSEF_TIMEOUT', 30),
            'add_to_pdf'        => getDolGlobalString('KSEF_ADD_TO_PDF', '1'),
            'add_qr'            => getDolGlobalString('KSEF_ADD_QR', '1'),
            'qr_size'           => getDolGlobalString('KSEF_QR_SIZE', '25'),
            'button_color'      => getDolGlobalString('KSEF_BUTTON_COLOR', '#dc3545'),
            'nbp_rate_mode'     => getDolGlobalString('KSEF_NBP_RATE_MODE', 'keep_base'),
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
        );
    }


    /**
     * @brief Test KSeF API connectivity
     * @return array  Test result with status and details
     * @url POST /test-connection
     * @throws RestException 403
     */
    public function testConnection()
    {
        $this->checkKsefReadPermission();

        global $conf;

        $environment = getDolGlobalString('KSEF_ENVIRONMENT', 'TEST');

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
     * @brief Check KSeF read permission
     * @return void
     * @throws RestException 403
     * @called_by All public GET endpoints
     * @calls loadKsefClasses()
     */
    private function checkKsefReadPermission()
    {
        $this->loadKsefClasses();
        if (!DolibarrApiAccess::$user->hasRight('ksef', 'read')) {
            throw new RestException(403, 'Not enough permissions to read KSeF data');
        }
    }

    /**
     * @brief Check KSeF write permission
     * @return void
     * @throws RestException 403
     * @called_by All public POST/DELETE endpoints
     * @calls loadKsefClasses()
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
     * @brief Format KsefSubmission for API output, strips large blobs unless full mode
     * @param $sub Submission object
     * @param $full Include large fields (fa3_xml, upo_xml, api_response)
     * @return array Formatted data
     * @called_by getSubmissions(), getSubmission(), getSubmissionByInvoice()
     */
    private function formatSubmission($sub, $full = false)
    {
        $data = array(
            'id'                    => (int)$sub->rowid,
            'fk_facture'            => (int)$sub->fk_facture,
            'ksef_reference'        => $sub->ksef_reference,
            'ksef_number'           => $sub->ksef_number,
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
     * @brief Format KsefIncoming for API output
     * @param $inc Incoming invoice object
     * @return array Formatted data
     * @called_by getIncoming(), getIncomingById()
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
     * @brief Find third-party (societe) by NIP
     * @param $nip NIP to search
     * @return int societe rowid or 0 if not found
     * @called_by importIncoming()
     */
    private function findThirdPartyByNip($nip)
    {
        $cleanNip = preg_replace('/[^0-9]/', '', $nip);
        if (empty($cleanNip)) {
            return 0;
        }

        // Try idprof1
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "societe"
            . " WHERE REPLACE(REPLACE(REPLACE(idprof1, '-', ''), ' ', ''), 'PL', '')"
            . " = '" . $this->db->escape($cleanNip) . "'"
            . " AND entity IN (" . getEntity('societe') . ")"
            . " LIMIT 1";

        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql) > 0) {
            $obj = $this->db->fetch_object($resql);
            $this->db->free($resql);
            return (int)$obj->rowid;
        }

        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "societe"
            . " WHERE REPLACE(REPLACE(siren, '-', ''), ' ', '')"
            . " = '" . $this->db->escape($cleanNip) . "'"
            . " AND entity IN (" . getEntity('societe') . ")"
            . " LIMIT 1";

        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql) > 0) {
            $obj = $this->db->fetch_object($resql);
            $this->db->free($resql);
            return (int)$obj->rowid;
        }

        return 0;
    }

    /**
     * @brief Get KSeF API URL for a given environment
     * @param $environment Environment name
     * @return string API URL
     * @called_by testConnection()
     */
    private function getApiUrl($environment)
    {
        switch (strtoupper($environment)) {
            case 'PRODUCTION':
                return KsefClient::API_PROD;
            case 'DEMO':
                return KsefClient::API_DEMO;
            case 'TEST':
            default:
                return KsefClient::API_TEST;
        }
    }
}

class_alias('KsefApi', 'Ksef');