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
 * \file    ksef/class/ksef_service.class.php
 * \ingroup ksef
 * \brief   Main KSEF class
 */

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';

class KsefService extends CommonObject
{
    public $element = 'ksef';
    public $table_element = 'ksef_submissions';
    public $failureReason = null;
    /** @var bool */
    public $searchedByHash = false;
    /** @var string */
    public $notRecordedNumber = '';
    /** @var bool */
    public $hashNotFoundFresh = false;
    /** @var bool */
    public $hashSearchComplete = false;
    /** @var int */
    public $hashSearchRead = 0;
    public $hashSearchListed = 0;
    /** @var int */
    public $hashSearchMatches = 0;
    /** @var int */
    public $hashSearchSessions = 10;
    /** @var int */
    public $hashSearchWaitUntil = 0;
    /** @var bool */
    public $acknowledgeDeposits = false;
    /** @var bool */
    public $acknowledgeNegativeRates = false;
    public $warnings = array();
    public $last_http_code = 0;
    public $last_retry_after = 0;
    public $notSubmittableCode = '';
    public $pdfWarning = '';

    private $client;
    private $builder;

    const SYNC_INCOMING = 'incoming';
    const SYNC_OUTGOING = 'outgoing';

    const CRON_RETRY_WAIT_CAP = 60;

    const CRON_HASH_SEARCH_ROWS = 2;
    const CRON_HASH_SEARCH_SESSIONS = 10;
    const CRON_SALES_ADOPT_ROWS = 20;
    const CRON_OFFLINE_BATCH = 10;
    const CRON_UNRESOLVED_BATCH = 5;
    const CRON_UPO_BATCH = 50;
    const CRON_TIME_BUDGET = 120;
    const CRON_TIME_MARGIN = 120;

    /** @var float|null */
    public $cronTimeBudget = null;
    /** @var float */
    private $cronDeadline = 0.0;
    /** @var bool */
    private $hashSearchPaused = false;
    /** @var bool */
    private $hashSearchRateLimited = false;
    /** @var bool */
    private $manualHashSearch = false;
    /** @var bool */
    private $manualHashClaimed = false;

    const MANUAL_HASH_SEARCH_GAP = 60;
    const MANUAL_HASH_SEARCH_HOLD = 600;

    const HASH_SEARCH_OFFLINE_DAYS = 30;
    const HASH_SEARCH_LIST_PAGES = 3;

    /**
     * @brief Constructor
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        global $conf;

        dol_include_once('/ksef/class/ksef_client.class.php');
        dol_include_once('/ksef/class/fa3_builder.class.php');
        dol_include_once('/ksef/class/ksef_submission.class.php');
        $this->db = $db;

        $this->client = new KsefClient($db, ksefGetEnvironment());
        $this->builder = new FA3Builder($db);
    }

    /**
     * @brief Check invoice sendable
     * @param Facture $invoice
     * @param KsefSubmission|null $existing Latest row
     * @return string '' when sendable
     */
    public function checkInvoiceSubmittable($invoice, $existing = null)
    {
        global $langs;
        $this->notSubmittableCode = '';
        if (!in_array((int) $invoice->statut, array(Facture::STATUS_VALIDATED, Facture::STATUS_CLOSED), true)) {
            $langs->load("ksef@ksef");
            $this->notSubmittableCode = 'INVOICE_NOT_VALIDATED';
            dol_syslog("KSEF::checkInvoiceSubmittable invoice " . (int) $invoice->id . " refused: not validated (status " . (int) $invoice->statut . ")", LOG_WARNING);
            return $langs->trans('KSEF_InvoiceMustBeValidated');
        }
        $prodRow = ksefProductionIssuedBlock($this->db, (int) $invoice->id);
        if ($prodRow) {
            $this->notSubmittableCode = 'ISSUED_IN_PRODUCTION';
            dol_syslog("KSEF::checkInvoiceSubmittable invoice " . (int) $invoice->id . " refused: issued in PRODUCTION (row " . (int) $prodRow['id'] . "), not sent in " . ksefGetEnvironment(), LOG_WARNING);
            return ksefProductionIssuedRefusal($prodRow);
        }
        $excluded = ksefIsCustomerExcluded($invoice->socid);
        $noSource = !$excluded && ksefCorrectionMissingSource($invoice);
        $otherEnv = $excluded ? '' : ksefCorrectionOtherEnvRefusal($this->db, $invoice);
        if ($excluded || $noSource || $otherEnv !== '') {
            if ($existing === null) {
                $existing = new KsefSubmission($this->db);
                if ($existing->fetchByInvoice((int) $invoice->id, ksefGetEnvironment()) <= 0) {
                    $existing = null;
                }
            }
            $issuedOffline = is_object($existing) && !empty($existing->rowid)
                && ($existing->status === 'OFFLINE' || !empty($existing->offline_mode));
            if (!$issuedOffline) {
                $langs->load("ksef@ksef");
                if ($otherEnv !== '' && !$noSource) {
                    $this->notSubmittableCode = 'CORRECTION_SOURCE_OTHER_ENV';
                    dol_syslog("KSEF::checkInvoiceSubmittable invoice " . (int) $invoice->id . " refused: corrected invoice accepted only outside PRODUCTION", LOG_WARNING);
                    return $otherEnv;
                }
                $this->notSubmittableCode = $excluded ? 'CUSTOMER_EXCLUDED' : 'CORRECTION_NO_SOURCE';
                dol_syslog("KSEF::checkInvoiceSubmittable invoice " . (int) $invoice->id . " refused: " . ($excluded ? "customer " . (int) $invoice->socid . " is excluded from KSeF" : "correction has no corrected invoice"), LOG_WARNING);
                return $langs->trans($excluded ? 'KSEF_CustomerExcluded' : 'KSEF_CorrectionNoSource');
            }
        }
        return '';
    }

    /**
     * @brief Refuse non-production resend
     * @param int $invoice_id
     * @param string $environment '' current
     * @return array|null null when allowed
     */
    private function productionIssuedRefusal($invoice_id, $environment = '')
    {
        $env = $environment !== '' ? $environment : null;
        $row = ksefProductionIssuedBlock($this->db, (int) $invoice_id, $env);
        if (!$row) {
            return null;
        }
        dol_syslog("KSEF: invoice " . (int) $invoice_id . " issued in PRODUCTION (row " . (int) $row['id'] . "): not sent in " . ($env ?? ksefGetEnvironment()), LOG_WARNING);
        return $this->refuse('ISSUED_IN_PRODUCTION', ksefProductionIssuedRefusal($row, $env));
    }

    /**
     * @brief Local refusal result
     * @param string $code
     * @param string $msg
     * @param array $extra
     * @return array
     */
    private function refuse($code, $msg, $extra = array())
    {
        $this->error = $msg;
        return $extra + array('status' => 'FAILED', 'error_code' => $code, 'error' => $msg, 'failure_kind' => 'local', 'refused' => 1, 'can_use_offline' => false);
    }

    /**
     * @brief Company data refusal
     * @return array|null null when complete
     */
    private function companyRefusal()
    {
        global $langs;
        $unmet = ksefRequirementsUnmet($this->db, array('company_name', 'company_nip', 'company_address', 'main_currency'));
        if (!$unmet) {
            return null;
        }
        $langs->load('ksef@ksef');
        dol_syslog("KSEF: send refused, company data incomplete: " . implode(', ', array_column($unmet, 'key')), LOG_WARNING);
        return $this->refuse('COMPANY_INCOMPLETE', $langs->transnoentitiesnoconv('KSEF_ReqSendRefused') . ' ' . implode('; ', array_column($unmet, 'label')));
    }

    /**
     * @brief Schema check refusal
     * @param Facture $invoice
     * @param string $xml
     * @return array|null null when valid
     */
    private function schemaRefusal($invoice, $xml)
    {
        $problem = ksefFa3SchemaRefusal($this->db, $invoice, $xml);
        if ($problem === null) {
            return null;
        }
        dol_syslog("KSEF: invoice " . (int) $invoice->id . " not sent, schema check failed: " . $problem['detail'], LOG_WARNING);
        return $this->refuse('XSD_INVALID', $problem['text'], array('fix_url' => $problem['url'], 'xsd_kind' => $problem['kind']));
    }

    /**
     * @brief Save locked row
     * @param KsefSubmission $row
     * @param User $user
     * @param int $seenId Latest row seen, 0 none
     * @param string $seenStatus
     * @return int >0 saved, 0 moved, <0 error
     */
    private function saveSendRow($row, $user, $seenId, $seenStatus)
    {
        $this->db->begin();
        $now = new KsefSubmission($this->db);
        $lock = $this->db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "facture WHERE rowid = " . (int) $row->fk_facture . " FOR UPDATE");
        $found = $lock ? $now->fetchByInvoice((int) $row->fk_facture, (string) $row->environment) : -1;
        if ($found < 0) {
            $row->error = $lock ? $now->error : $this->db->lasterror();
            $this->db->rollback();
            return -1;
        }
        $same = $seenId > 0 ? ($found > 0 && (int) $now->rowid === (int) $seenId && (string) $now->status === (string) $seenStatus) : $found === 0;
        if (!$same) {
            $this->db->rollback();
            dol_syslog("KSEF::saveSendRow invoice " . (int) $row->fk_facture . ": latest row is now " . ($found > 0 ? (int) $now->rowid . " (" . $now->status . ")" : 'none')
                . ", seen " . (int) $seenId . " (" . $seenStatus . "): another request got there first, nothing saved", LOG_WARNING);
            return 0;
        }
        $r = !empty($row->rowid) ? $row->update($user, 1, array((string) $seenStatus)) : $row->create($user);
        if ($r <= 0) {
            $this->db->rollback();
            return $r < 0 ? -1 : 0;
        }
        return $this->db->commit() ? 1 : -1;
    }

    /**
     * @brief Lost send race
     * @param int $invoice_id
     * @return array
     */
    private function sendRowMoved($invoice_id)
    {
        $latest = new KsefSubmission($this->db);
        if ($latest->fetchByInvoice((int) $invoice_id, ksefGetEnvironment()) > 0) {
            if ($latest->status === KsefSubmission::STATUS_ACCEPTED) {
                return array('status' => 'ACCEPTED', 'ksef_number' => $latest->ksef_number, 'submission_date' => $latest->date_submission,
                    'submission_id' => $latest->rowid, 'message' => 'Invoice already submitted');
            }
            if (in_array($latest->status, array(KsefSubmission::STATUS_FAILED, KsefSubmission::STATUS_REJECTED), true)) {
                return $this->resultOfRow($latest, array('outcome' => ''));
            }
        }
        return array('status' => 'PENDING', 'message' => 'Submission already in progress', 'submission_id' => $latest->rowid);
    }

    /**
     * @brief Last failure transport
     * @return bool
     */
    public function lastFailureWasTransport()
    {
        return is_object($this->client) && !empty($this->client->last_transport_error);
    }

    /**
     * @brief Refresh invoice PDFs
     * @param KsefSubmission $submission
     * @param int $invoice_id
     * @param bool $offline
     * @return string Warning or ''
     */
    private function refreshDocuments($submission, $invoice_id, $offline = false)
    {
        global $langs;
        $langs->load('ksef@ksef');
        $warnings = array();
        $err = '';
        if ((string) $submission->environment !== '' && ksefProductionIssuedBlock($this->db, (int) $invoice_id, (string) $submission->environment)) {
            dol_syslog("KSEF::refreshDocuments invoice " . (int) $invoice_id . ": row " . (int) $submission->rowid . " is " . $submission->environment
                . ", the invoice is issued in PRODUCTION: PDFs left as they are", LOG_WARNING);
            $this->pdfWarning = '';
            return '';
        }
        if (empty($submission->fa3_xml)) {
            dol_syslog("KSEF::refreshDocuments invoice " . (int) $invoice_id . ": no FA(3) XML on row " . (int) $submission->rowid . ", no KSeF PDF", LOG_INFO);
            $inv = new Facture($this->db);
            if ($inv->fetch((int) $invoice_id) > 0) {
                ksefRemovePreviewPdf($inv);
            }
        } elseif (!ksefAutoGeneratePdf($this->db, $submission, (int) $invoice_id, $err)) {
            dol_syslog("KSEF::refreshDocuments KSeF PDF of invoice " . (int) $invoice_id . " not written: " . $err, LOG_ERR);
            $warnings[] = $langs->transnoentitiesnoconv($offline ? 'KSEF_OfflinePdfGenerateFailed' : 'KSEF_PDFAutoGenerateFailed', $err);
        }
        $mainErr = '';
        if (ksefRegenerateMainPdf($this->db, (int) $invoice_id, $mainErr) < 0) {
            $warnings[] = $langs->transnoentitiesnoconv('KSEF_MainPdfRegenerateFailed', $mainErr);
        }
        $this->pdfWarning = implode(' ', $warnings);
        return $this->pdfWarning;
    }

    /**
     * @brief PDF warning entry
     * @return array
     */
    private function pdfWarningResult()
    {
        return $this->pdfWarning !== '' ? array('pdf_warning' => $this->pdfWarning) : array();
    }

    /**
     * @brief Submit invoice
     * @param $invoice_id
     * @param $user
     * @param $mode SYNC/MANUAL
     * @param $force_offline
     * @param $offline_reason
     * @return array
     */
    public function submitInvoice($invoice_id, $user, $mode = 'SYNC', $force_offline = false, $offline_reason = '')
    {
        global $conf;
        dol_syslog("KSEF::submitInvoice invoice_id=$invoice_id mode=$mode force_offline=" . ($force_offline ? 'yes' : 'no'), LOG_INFO);

        $start_time = microtime(true);
        $this->pdfWarning = '';
        $fa3_xml = '';
        $xml_hash = '';
        $fa3_creation_date = null;
        $invoice_type = null;
        $replacedNumber = '';

        try {
            $invoice = new Facture($this->db);
            if ($invoice->fetch($invoice_id) <= 0) {
                throw new Exception(ksefMsg('KSEF_ErrInvoiceNotFound', $invoice_id));
            }

            $notSubmittable = $this->checkInvoiceSubmittable($invoice);
            if ($notSubmittable !== '') {
                return $this->refuse($this->notSubmittableCode, $notSubmittable);
            }

            $existing = new KsefSubmission($this->db);
            $existingFound = $existing->fetchByInvoice($invoice_id, ksefGetEnvironment());
            if ($existingFound < 0) {
                dol_syslog("KSEF::submitInvoice cannot read submissions of invoice $invoice_id: " . $existing->error, LOG_ERR);
                $this->error = ksefDbErrorForUser($user, 'KSEF_SubmissionRecordReadFailed', $existing->error);
                return array('status' => 'FAILED', 'error_code' => 'RECORD_READ_FAILED', 'record_failed' => true, 'error' => $this->error);
            }
            if ($existingFound > 0) {
                if ($existing->status == 'ACCEPTED') {
                    return array(
                        'status' => 'ACCEPTED',
                        'ksef_number' => $existing->ksef_number,
                        'submission_date' => $existing->date_submission,
                        'message' => 'Invoice already submitted'
                    );
                }

                if (in_array($existing->status, array('PENDING', 'SUBMITTED'), true) && (!$existing->isStale() || !empty($existing->offline_mode))) {
                    return array(
                        'status' => 'PENDING',
                        'message' => 'Submission already in progress',
                        'submission_id' => $existing->rowid
                    );
                }

                if (empty($existing->offline_mode) && ($existing->status === 'TIMEOUT' || $existing->isStale())) {
                    $resolved = $this->resolveBeforeResend($existing, $user);
                    if ($resolved !== null) {
                        return $resolved;
                    }
                    // open for 440
                    if (in_array($existing->status, array('TIMEOUT', 'PENDING', 'SUBMITTED'), true) && ksefHashStopState($existing) === '') {
                        $this->closeNotReceived($existing, $user);
                    }
                }

                if ($existing->isDuplicate()) {
                    return $this->retryDuplicateAdoption($existing, $user);
                }

                // KOD I target
                if (!empty($existing->offline_mode) || $existing->status === 'OFFLINE') {
                    if ($existing->canRetry()) {
                        return $this->retryOfflineWithStoredXML($existing, $user);
                    }
                    global $langs;
                    $langs->load('ksef@ksef');
                    $this->error = $langs->transnoentitiesnoconv($existing->noRetryKey());
                    return array('status' => 'FAILED', 'error_code' => 'NOT_RETRYABLE', 'error' => $this->error, 'refused' => 1, 'can_use_offline' => false,
                        'rejected_offline' => $existing->status === 'REJECTED' ? 1 : 0, 'offline_mode' => $existing->offline_mode, 'submission_id' => $existing->rowid);
                }

                if (!empty($existing->fa3_creation_date)) {
                    $fa3_creation_date = $existing->fa3_creation_date;
                    dol_syslog("KSEF::submitInvoice - Reusing original creation date: $fa3_creation_date", LOG_INFO);
                }
            }

            $replacedNumber = ksefNumberWithoutRow($this->db, (int) $invoice_id);
            if ($replacedNumber !== '') {
                dol_syslog("KSEF::submitInvoice invoice $invoice_id already carries KSeF number $replacedNumber without a submission record: sending again may create a second KSeF invoice", LOG_WARNING);
            }

            // before backdate check
            $timing = $this->correctionTimingRefusal($invoice);
            if ($timing !== null) {
                return $timing;
            }

            $backdatedOnline = false;
            if (!$force_offline) {
                $backdate_info = $this->needsOfflineConfirmation($invoice);
                $certOk = ksefIsOfflineCertificateConfigured();
                if ($backdate_info && empty($certOk['valid'])) {
                    $backdatedOnline = true;
                    dol_syslog("KSEF::submitInvoice invoice $invoice_id is backdated, no Offline certificate: sent online, KSeF classes it offline", LOG_INFO);
                } elseif ($backdate_info) {
                    return array(
                        'status' => 'NEEDS_CONFIRMATION',
                        'backdate_info' => $backdate_info,
                        'deadline' => $backdate_info['deadline'],
                        'message' => $backdate_info['reason']
                    );
                }
            }

            $rateRefusal = $this->rateGuard($invoice);
            if ($rateRefusal !== null) {
                return $rateRefusal;
            }

            dol_include_once('/ksef/class/ksef_latarnia.class.php');
            $latarnia = new KsefLatarnia($this->db);
            $latarnia->refreshIfStale();

            $companyRefusal = $this->companyRefusal();
            if ($companyRefusal !== null) {
                return $companyRefusal;
            }

            $stage = 'build';
            $build_options = array();
            if ($fa3_creation_date) {
                $build_options['original_creation_date'] = $fa3_creation_date;
            }
            if ($force_offline) {
                $build_options['offline_mode'] = true;
            }

            $fa3_xml = $this->builder->buildFromInvoice($invoice_id, $build_options);
            if ($fa3_xml === false) {
                throw new Exception(ksefMsg('KSEF_ErrBuildXml', $this->builder->error));
            }

            $fa3_creation_date = $this->builder->getLastCreationDate();
            $xml_hash = $this->builder->getLastXmlHash();
            $invoice_type = $this->builder->getLastInvoiceType();

            $schemaRefusal = $this->schemaRefusal($invoice, $fa3_xml);
            if ($schemaRefusal !== null) {
                return $schemaRefusal;
            }

            $offline_mode_value = $force_offline ? 'OFFLINE' : null;
            $offline_deadline = $force_offline ? ksefOfflineCreationDeadline($invoice->date) : null;

            $submission = new KsefSubmission($this->db);

            $attempt_number = 1;
            if (!empty($existing->rowid)) {
                $sql = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . "ksef_submissions WHERE fk_facture = " . (int)$invoice_id
                    . " AND environment = '" . $this->db->escape(ksefGetEnvironment()) . "'";
                $resql = $this->db->query($sql);
                if ($resql) {
                    $obj = $this->db->fetch_object($resql);
                    $attempt_number = ($obj->cnt ?? 0) + 1;
                }
            }
            $submission->retry_count = $attempt_number - 1;

            $submission->fk_facture = $invoice_id;
            $submission->fa3_xml = $fa3_xml;
            $submission->fa3_creation_date = $fa3_creation_date;
            $submission->invoice_hash = $xml_hash;
            $submission->invoice_type = $invoice_type;
            $submission->status = 'PENDING';
            $submission->environment = ksefGetEnvironment();
            $submission->date_submission = dol_now();
            $submission->offline_mode = $offline_mode_value;
            $submission->offline_deadline = $offline_deadline;
            $submission->offline_detected_reason = $offline_reason ?: null;
            $submission->error_message = null;
            $submission->error_code = null;
            $submission->source = $force_offline ? KsefSubmission::SOURCE_SENT_OFFLINE : KsefSubmission::SOURCE_SENT;
            $submission->replaced_number = $replacedNumber !== '' ? $replacedNumber : null;

            $saved = $this->saveSendRow($submission, $user, $existingFound > 0 ? (int) $existing->rowid : 0, $existingFound > 0 ? (string) $existing->status : '');
            if ($saved === 0) {
                return $this->sendRowMoved($invoice_id);
            }
            if ($saved < 0) {
                dol_syslog("KSEF::submitInvoice PENDING row not saved for invoice $invoice_id, nothing sent: " . $submission->error, LOG_ERR);
                $this->error = ksefDbErrorForUser($user, 'KSEF_SubmissionNotRecorded', $submission->error);
                return array('status' => 'FAILED', 'error_code' => 'RECORD_FAILED', 'record_failed' => true, 'error' => $this->error);
            }

            $stage = 'send';
            $sent = $this->sendWithRecord($submission, $fa3_xml, array(
                'offline_mode' => $force_offline,
                'invoice_hash' => $xml_hash
            ), $user);
            $api_result = $sent['api'];

            $elapsed = microtime(true) - $start_time;

            if ($sent['outcome'] === 'NOT_RECORDED') {
                return $this->acceptedButNotRecorded($submission, $invoice_id, $user, $sent['accepted']);
            }
            if ($sent['outcome'] === 'ACCEPTED') {
                dol_syslog("KSEF::submitInvoice SUCCESS in {$elapsed}s - KSEF: " . $submission->ksef_number . (!empty($sent['adopted']) ? ' (adopted after 440)' : ''), LOG_INFO);
                $out = array(
                    'status' => 'ACCEPTED',
                    'ksef_number' => $submission->ksef_number,
                    'invoice_hash' => $submission->invoice_hash ?: $xml_hash,
                    'submission_date' => dol_now(),
                    'submission_id' => $submission->rowid,
                    'offline_mode' => $offline_mode_value
                );
                if (!empty($sent['adopted'])) {
                    $out['adopted'] = 1;
                    $out['message'] = 'Invoice was already in KSeF, number adopted';
                }
                if ($backdatedOnline) {
                    $out['backdated_online'] = 1;
                }
                return $out + $this->pdfWarningResult();
            }
            if ($sent['outcome'] === 'TIMEOUT' || $sent['outcome'] === 'MOVED') {
                return $this->resultOfRow($submission, $sent);
            }
            $submission->status = 'FAILED';
            $submission->error_message = (is_array($api_result) ? ($api_result['error'] ?? null) : null) ?? $this->client->error ?? 'Unknown error';
            $submission->error_code = $this->client->last_error_code ?? null;
            if (!empty($sent['error_details'])) {
                $submission->error_details = json_encode($sent['error_details']);
            } elseif (!empty($this->client->last_error_details)) {
                $submission->error_details = json_encode($this->client->last_error_details);
            }
            $submission->api_response = json_encode($api_result);
            $kind = $this->failureKindNow($stage);
            $submission->failure_kind = $kind;
            if ($submission->update($user, 1, $sent['allowed']) < 0) {
                dol_syslog("KSEF::submitInvoice FAILED result not saved on row " . $submission->rowid . ": " . $submission->error, LOG_ERR);
            }

            dol_syslog("KSEF::submitInvoice FAILED in {$elapsed}s: " . $submission->error_message, LOG_ERR);
            return array(
                'status' => 'FAILED',
                'error' => $submission->error_message,
                'error_code' => $submission->error_code,
                'submission_id' => $submission->rowid,
                'fa3_creation_date' => $fa3_creation_date,
                'invoice_hash' => $xml_hash,
                'failure_kind' => $kind,
                'can_use_offline' => $kind === 'transport'
            );

        } catch (Throwable $e) {
            $this->error = $e->getMessage();
            $this->errors[] = $e->getMessage();
            dol_syslog("KSEF::submitInvoice ERROR: " . get_class($e) . ": " . $e->getMessage(), LOG_ERR);

            if (!empty($this->client->last_error_details)) {
                dol_syslog("KSEF::submitInvoice ERROR DETAILS: " . json_encode($this->client->last_error_details), LOG_ERR);
            }

            $kind = $this->failureKindNow(isset($stage) ? $stage : 'build');
            $hasRow = isset($submission) && !empty($submission->rowid);
            if (!$hasRow) {
                $submission = new KsefSubmission($this->db);
                $submission->fk_facture = $invoice_id;
                $submission->fa3_xml = $fa3_xml;
                $submission->fa3_creation_date = $fa3_creation_date;
                $submission->invoice_hash = $xml_hash;
                $submission->invoice_type = $invoice_type;
                $submission->environment = ksefGetEnvironment();
                $submission->date_submission = dol_now();
                $submission->replaced_number = $replacedNumber !== '' ? $replacedNumber : null;
            }
            $submission->status = KsefSubmission::STATUS_FAILED;
            $submission->failure_kind = $kind;
            $submission->error_message = $e->getMessage();
            if (!empty($this->client->last_error_code)) {
                $submission->error_code = $this->client->last_error_code;
            }
            if (!empty($this->client->last_error_details)) {
                $submission->error_details = json_encode($this->client->last_error_details);
            }
            if ($hasRow) {
                $this->storeSendReferences($submission, null);
                $saved = $submission->update($user, 1, array(KsefSubmission::STATUS_PENDING, KsefSubmission::STATUS_SUBMITTED, KsefSubmission::STATUS_TIMEOUT));
                if ($saved === 0) {
                    return $this->resultOfRow($submission, array('outcome' => 'MOVED'));
                }
            } else {
                $saved = $submission->create($user);
            }
            if ($saved <= 0) {
                dol_syslog("KSEF::submitInvoice FAILED result not saved for invoice $invoice_id (row " . (int) $submission->rowid . "): " . $submission->error, LOG_ERR);
            }

            return array(
                'status' => 'FAILED',
                'error' => $e->getMessage(),
                'error_code' => $this->client->last_error_code ?? null,
                'submission_id' => $submission->rowid ?? null,
                'fa3_creation_date' => $fa3_creation_date,
                'invoice_hash' => $xml_hash,
                'failure_kind' => $kind,
                'can_use_offline' => $kind === 'transport'
            );
        }
    }

    /**
     * @brief Close unreceived send
     * @param KsefSubmission $sub
     * @param User $user
     * @param string $why '', complete or expired
     * @return int
     */
    private function closeNotReceived($sub, $user, $why = '')
    {
        global $langs;
        $langs->load('ksef@ksef');
        $from = (string) $sub->status;
        $details = array('reason' => 'NOT_RECEIVED');
        $message = $langs->transnoentitiesnoconv('KSEF_NotReceivedNote');
        if ($why !== '') {
            $note = $sub->getHashSearchNote();
            $sent = dol_print_date((int) $sub->date_submission, 'dayhour');
            $details += array('not_found' => $why, 'read' => (int) $this->hashSearchRead, 'listed' => (int) $this->hashSearchListed,
                'searches' => $note['attempts'] + 1, 'date' => dol_now());
            if ($why === 'complete' && (int) $this->hashSearchRead === 0) {
                $message = $langs->transnoentitiesnoconv('KSEF_NotFoundInKsefNoSession', $sent);
            } else {
                $message = $why === 'complete' ? $langs->transnoentitiesnoconv('KSEF_NotFoundInKsefComplete', (int) $this->hashSearchRead, $sent)
                    : $langs->transnoentitiesnoconv('KSEF_NotFoundInKsefExpired', (int) $this->hashSearchRead, KsefSubmission::HASH_SEARCH_MAX_DAYS, $sent);
            }
        }
        $sub->status = 'FAILED';
        $sub->failure_kind = 'unknown';
        $sub->error_code = null;
        $sub->error_message = $message;
        $sub->error_details = json_encode($details);
        $r = $sub->update($user, 1, array($from));
        dol_syslog("KSEF::closeNotReceived row " . (int) $sub->rowid . " ($from) closed: KSeF did not receive it" . ($why !== '' ? " (hash search " . $why . ")" : ''), LOG_INFO);
        return $r;
    }

    /**
     * @brief Current failure kind
     * @param string $stage build or send
     * @return string
     */
    private function failureKindNow($stage)
    {
        if ($stage === 'build') {
            return 'local';
        }
        $code = $this->client->last_error_code ?? null;
        if ($code !== null && $code !== '' && is_numeric($code) && ksefFailureKind($code) === 'rejected') {
            return 'rejected';
        }
        // offline refused
        if (!empty($this->client->last_invoice_reference) && $code !== null && $code !== '' && is_numeric($code)) {
            return 'internal';
        }
        if (!empty($this->client->last_transport_error)) {
            return 'transport';
        }
        if ($code !== null && $code !== '' && is_numeric($code)) {
            return ksefFailureKind($code);
        }
        $http = (int) ($this->client->last_http_code ?? 0);
        if ($http >= 400) {
            return ksefFailureKind($http);
        }
        return 'internal';
    }

    /**
     * @brief Issue invoice offline
     * @param int $invoice_id
     * @param object $user
     * @param string $offline_reason
     * @return array
     */
    public function submitInvoiceOffline($invoice_id, $user, $offline_reason = 'user_choice')
    {
        global $conf;
        dol_syslog("KSEF::submitInvoiceOffline invoice_id=$invoice_id reason=$offline_reason", LOG_INFO);
        $this->pdfWarning = '';

        global $langs;
        $prodRow = ksefProductionIssuedBlock($this->db, (int) $invoice_id);
        if ($prodRow) {
            $this->notSubmittableCode = 'ISSUED_IN_PRODUCTION';
            dol_syslog("KSEF::submitInvoiceOffline invoice " . (int) $invoice_id . " refused: issued in PRODUCTION, not issued offline in " . ksefGetEnvironment(), LOG_WARNING);
            return $this->refuse('ISSUED_IN_PRODUCTION', ksefProductionIssuedRefusal($prodRow));
        }
        $cert_check = ksefIsOfflineCertificateConfigured();
        if (!$cert_check['valid']) {
            $langs->load('ksef@ksef');
            $this->error = ksefOfflineCertRefusalText('KSEF_OfflineCertNotConfigured', $cert_check);
            dol_syslog("KSEF::submitInvoiceOffline - Offline certificate not configured or expired", LOG_ERR);
            return array(
                'status' => 'FAILED',
                'error' => $this->error,
                'needs_certificate' => true,
                'missing_items' => $cert_check['configured'] ? array('expired') : $cert_check['missing']
            );
        }

        try {
            $invoice = new Facture($this->db);
            if ($invoice->fetch($invoice_id) <= 0) {
                throw new Exception(ksefMsg('KSEF_ErrInvoiceNotFound', $invoice_id));
            }

            $notSubmittable = $this->checkInvoiceSubmittable($invoice);
            if ($notSubmittable !== '') {
                return $this->refuse($this->notSubmittableCode, $notSubmittable);
            }

            $existing = new KsefSubmission($this->db);
            $submission = new KsefSubmission($this->db);

            $existingFound = $existing->fetchByInvoice($invoice_id, ksefGetEnvironment());
            $seenId = $existingFound > 0 ? (int) $existing->rowid : 0;
            $seenStatus = $existingFound > 0 ? (string) $existing->status : '';
            if ($existingFound > 0) {
                if ($existing->status == 'ACCEPTED') {
                    return array(
                        'status' => 'ACCEPTED',
                        'ksef_number' => $existing->ksef_number,
                        'message' => 'Invoice already accepted in KSeF'
                    );
                }
                $refusal = ksefOfflineIssueRefusal($existing, $invoice, false);
                if ($refusal !== '') {
                    $this->error = ksefOfflineRefusalText($refusal);
                    return array('status' => 'FAILED', 'error_code' => 'OFFLINE_REFUSED', 'refusal' => $refusal, 'error' => $this->error,
                        'submission_id' => $existing->rowid, 'can_use_offline' => false);
                }

                $submission = $existing;
            }

            $replacedNumber = ksefNumberWithoutRow($this->db, (int) $invoice_id);
            if ($replacedNumber !== '') {
                dol_syslog("KSEF::submitInvoiceOffline invoice $invoice_id already carries KSeF number $replacedNumber without a submission record: issuing offline may create a second KSeF invoice", LOG_WARNING);
            }

            $offline_deadline = ksefOfflineCreationDeadline($invoice->date);

            $rateRefusal = $this->rateGuard($invoice);
            if ($rateRefusal !== null) {
                return $rateRefusal;
            }
            $companyRefusal = $this->companyRefusal();
            if ($companyRefusal !== null) {
                return $companyRefusal;
            }
            $fa3_xml = $this->builder->buildFromInvoice($invoice_id, array('offline_mode' => true));
            if ($fa3_xml === false) {
                throw new Exception(ksefMsg('KSEF_ErrBuildXml', $this->builder->error));
            }
            $schemaRefusal = $this->schemaRefusal($invoice, $fa3_xml);
            if ($schemaRefusal !== null) {
                return $schemaRefusal;
            }
            $xml_hash = $this->builder->getLastXmlHash();
            $fa3_creation_date = $this->builder->getLastCreationDate();
            $submission->invoice_type = $this->builder->getLastInvoiceType();

            $offline_number = 'OFFLINE-' . $invoice->ref . '-' . date('YmdHis');
            $submission->fk_facture = $invoice_id;
            $submission->fa3_xml = $fa3_xml;
            $submission->fa3_creation_date = $fa3_creation_date;
            $submission->invoice_hash = $xml_hash;
            $submission->status = 'OFFLINE';
            $submission->failure_kind = null;
            $submission->error_code = null;
            $submission->ksef_number = $offline_number;
            $submission->environment = ksefGetEnvironment();
            $submission->date_submission = dol_now();
            $submission->offline_mode = 'OFFLINE';
            $submission->offline_deadline = $offline_deadline;
            $submission->offline_detected_reason = $offline_reason;
            $submission->error_message = null;
            $submission->source = KsefSubmission::SOURCE_SENT_OFFLINE;
            if ($replacedNumber !== '') {
                $submission->replaced_number = $replacedNumber;
            }

            $saved = $this->saveSendRow($submission, $user, $seenId, $seenStatus);
            if ($saved === 0) {
                return $this->sendRowMoved($invoice_id);
            }
            if ($saved < 0) {
                dol_syslog("KSEF::submitInvoiceOffline row not saved for invoice $invoice_id: " . $submission->error, LOG_ERR);
                $this->error = ksefDbErrorForUser($user, 'KSEF_SubmissionNotRecorded', $submission->error);
                return array('status' => 'FAILED', 'error_code' => 'RECORD_FAILED', 'record_failed' => true, 'error' => $this->error);
            }

            $this->refreshDocuments($submission, $invoice_id, true);

            return array(
                'status' => 'OFFLINE',
                'ksef_number' => $offline_number,
                'invoice_hash' => $xml_hash,
                'submission_date' => dol_now(),
                'submission_id' => $submission->rowid,
                'offline_mode' => 'OFFLINE',
                'offline_deadline' => $offline_deadline
            ) + $this->pdfWarningResult();

        } catch (Throwable $e) {
            $this->error = $e->getMessage();
            dol_syslog("KSEF::submitInvoiceOffline ERROR: " . $e->getMessage(), LOG_ERR);

            return array(
                'status' => 'FAILED',
                'error' => $e->getMessage()
            );
        }
    }

    /**
     * @brief Correction timing guard
     * @param Facture $invoice
     * @param array|null $corr Source ref
     * @return array|null null when allowed
     */
    private function correctionTimingRefusal($invoice, $corr = null)
    {
        if ($corr === null) {
            $corr = ksefCorrectionSourceRef($this->db, $invoice);
        }
        if ($corr['state'] === 'awaiting_number') {
            dol_syslog("KSEF: correction " . (int) $invoice->id . " refused: original " . (int) $corr['source_id'] . " "
                . (($corr['awaiting_kind'] ?? '') === 'online' ? "sent online, not accepted yet" : "issued offline, no KSeF number yet"), LOG_WARNING);
            return $this->refuse('CORRECTION_SOURCE_AWAITING_NUMBER', ksefCorrectionAwaitingRefusal($corr, false));
        }
        $early = ksefCorrectionNumberDayCheck($this->db, $invoice, $corr);
        if ($early) {
            dol_syslog("KSEF: correction " . (int) $invoice->id . " refused: dated " . $early['day'] . ", original's KSeF number assigned " . $early['number_day'], LOG_WARNING);
            return $this->refuse('CORRECTION_BEFORE_SOURCE_NUMBER', ksefCorrectionNumberDayRefusal($early, false));
        }
        return null;
    }

    /**
     * @brief Pre-build send guards
     * @param Facture $invoice
     * @return array|null null when allowed
     */
    private function rateGuard($invoice)
    {
        global $langs;
        dol_include_once('/ksef/class/ksef_nbp_currency_rate.class.php');
        $langs->load('ksef@ksef');
        $corr = ksefCorrectionSourceRef($this->db, $invoice);
        if ($corr['state'] === 'rejected') {
            return $this->refuse('CORRECTION_SOURCE_REJECTED', dol_string_nohtmltag(ksefCorrectionSourceRefusal($corr)));
        }
        $waiting = $this->correctionTimingRefusal($invoice, $corr);
        if ($waiting !== null) {
            return $waiting;
        }
        $earlier = ksefEarlierCorrectionUnsent($this->db, $invoice);
        if ($earlier) {
            return $this->refuse('EARLIER_CORRECTION_UNSENT', dol_string_nohtmltag(ksefEarlierCorrectionRefusal($earlier)));
        }
        if (ksefAdoptOrderDepositLines($this->db, (int) $invoice->id) > 0) {
            $invoice->fetch_lines();
        }
        $ctx = ksefDepositContext($this->db, $invoice);
        $rateRefusal = ksefKorZalRateRefusal($this->db, $invoice, $ctx);
        if ($rateRefusal !== '') {
            return $this->refuse('KORZAL_RATE_MISMATCH', $rateRefusal);
        }
        $vatMismatch = ksefDepositVatMismatch($invoice, $ctx);
        if ($vatMismatch !== '') {
            return $this->refuse('DEPOSIT_VAT_MISMATCH', dol_string_nohtmltag($vatMismatch));
        }
        $blockCode = ksefDepositBlockCode($ctx);
        if ($blockCode !== '') {
            $codes = array('currency_mismatch' => 'DEPOSIT_CURRENCY_MISMATCH', 'below_zero' => 'SETTLEMENT_BELOW_ZERO');
            return $this->refuse($codes[$blockCode] ?? 'DEPOSIT_NOT_IN_KSEF', ksefDepositBlockMessage($this->db, $invoice, $ctx));
        }
        $ack = ksefDepositAckMessage($this->db, $invoice, $ctx);
        $ackKinds = ksefDepositAckKinds($ctx);
        if ($ackKinds['deposits'] && !$this->acknowledgeDeposits) {
            return $this->refuse('DEPOSITS_ACK_REQUIRED', ksefDepositAckMessage($this->db, $invoice, $ctx, false, $this->acknowledgeNegativeRates ? 'deposits' : ''),
                array('negative_rate' => $ackKinds['rates'] && !$this->acknowledgeNegativeRates ? 1 : 0));
        }
        if ($ackKinds['rates'] && !$this->acknowledgeNegativeRates) {
            return $this->refuse('NEGATIVE_RATE_ACK_REQUIRED', ksefDepositAckMessage($this->db, $invoice, $ctx, false, 'rates'));
        }
        if ($ack !== '') {
            dol_syslog('KSEF: invoice ' . (int) $invoice->id . ' sent with deposit notices acknowledged: ' . $ack, LOG_WARNING);
        }
        $this->warnings = array();
        if (in_array($ctx['type'], array('ZAL', 'KOR_ZAL'), true) && empty($ctx['order'])) {
            $this->warnings[] = $langs->transnoentitiesnoconv('KSEF_DepositNoOrderWarning');
            dol_syslog('KSEF: deposit invoice ' . (int) $invoice->id . ' sent without a linked order (no Zamowienie)', LOG_WARNING);
        }
        $nbp = new KsefNbpCurrencyRate($this->db);
        if (empty($invoice->array_options)) {
            $invoice->fetch_optionals();
        }
        if ($nbp->invoiceNeedsNBPRate($invoice) && !$nbp->invoiceHasNBPRate($invoice)) {
            return $this->refuse('NBP_RATE_REQUIRED', $langs->transnoentitiesnoconv('KSEF_NBPRateRequired'));
        }
        if ($nbp->invoiceNeedsNBPRate($invoice)) {
            $linePrep = ksefLineKursSendPrepare($this->db, $invoice, 'plain');
            if (!$linePrep['ok']) {
                return $this->refuse(strtoupper($linePrep['code']), $linePrep['message']);
            }
            if ($linePrep['notice'] !== '') {
                $this->warnings[] = $linePrep['notice'];
            }
            foreach (ksefTypedRateSendWarnings($this->db, $invoice, 'plain') as $w) {
                $this->warnings[] = $w;
            }
        }
        $rate = $nbp->checkStoredRate($invoice);
        if ($rate['stale'] && $rate['strict']) {
            $refusal = $nbp->rateFetchRefusal($invoice);
            $msg = !empty($refusal['parts'])
                ? $nbp->rateChangeBookedMessage('KSEF_NBPRateStaleBooked', $invoice, $refusal['parts'], array($rate['stored_label'], $rate['reference_label']))
                : $langs->transnoentitiesnoconv('KSEF_NBPRateStale', $rate['stored_label'], $rate['reference_label']);
            return $this->refuse('NBP_RATE_STALE', $msg, array('rate_date' => $rate['stored'], 'reference_date' => $rate['reference']));
        }
        if ($rate['stale']) {
            dol_syslog('KSEF: invoice ' . (int) $invoice->id . ' built with the stored NBP rate of ' . $rate['stored'] . ' (reference date '
                . $rate['reference'] . '): validated before the stale-rate check, sent as it is', LOG_WARNING);
        }
        $typedRate = $nbp->checkCorrectionRate($invoice);
        if ($typedRate['state'] === 'other') {
            $this->warnings[] = $nbp->correctionRateWarning($typedRate, (int) $invoice->statut !== Facture::STATUS_DRAFT);
        }
        return null;
    }

    /**
     * @brief Detect backdated invoice
     * @param $invoice
     * @return array|false
     */
    private function needsOfflineConfirmation($invoice)
    {
        $backdate_info = ksefDetectBackdating($invoice->date);

        if ($backdate_info['is_backdated']) {
            return $backdate_info;
        }

        return false;
    }

    /**
     * @brief Store send references
     * @param KsefSubmission $submission
     * @param array|false|null $apiResult
     * @return void
     */
    private function storeSendReferences($submission, $apiResult)
    {
        $inv = is_array($apiResult) && !empty($apiResult['reference_number']) ? $apiResult['reference_number'] : $this->client->last_invoice_reference;
        $ses = is_array($apiResult) && !empty($apiResult['session_reference']) ? $apiResult['session_reference'] : $this->client->last_session_reference;
        if (!empty($inv)) {
            $submission->ksef_reference = $inv;
        }
        if (!empty($ses)) {
            $submission->ksef_session_reference = $ses;
        }
    }

    /**
     * @brief Accepted, not recorded
     * @param KsefSubmission $submission
     * @param int $invoice_id
     * @param User $user
     * @param array $apiResult
     * @return array
     */
    private function acceptedButNotRecorded($submission, $invoice_id, $user, $apiResult)
    {
        global $langs;
        $langs->load('ksef@ksef');
        dol_syslog("KSEF ACCEPTED BUT NOT RECORDED invoice_id=" . (int) $invoice_id . " ksef_number=" . ($apiResult['ksef_number'] ?? '')
            . " invoice_hash=" . ($submission->invoice_hash ?? '') . " invoice_ref=" . ($submission->ksef_reference ?? '')
            . " session_ref=" . ($submission->ksef_session_reference ?? '') . " row=" . (int) $submission->rowid . " error=" . $submission->error, LOG_ERR);
        $this->error = $langs->transnoentitiesnoconv('KSEF_AcceptedButNotRecorded', $apiResult['ksef_number'] ?? '');
        return array(
            'status' => 'ACCEPTED',
            'ksef_number' => $apiResult['ksef_number'] ?? null,
            'invoice_hash' => $apiResult['invoice_hash'] ?? $submission->invoice_hash,
            'submission_date' => dol_now(),
            'submission_id' => $submission->rowid,
            'record_saved' => false,
            'warning' => $this->error
        );
    }

    /**
     * @brief Send saved row
     * @param KsefSubmission $submission
     * @param string $xml
     * @param array $options
     * @param User $user
     * @return array
     */
    private function sendWithRecord($submission, $xml, $options, $user)
    {
        $online = empty($submission->offline_mode);
        $options['offline_mode'] = !$online;
        $allowed = array_values(array_unique(array((string) $submission->status, 'PENDING', 'SUBMITTED', 'TIMEOUT')));
        $options['on_progress'] = function ($sessionRef, $invoiceRef) use ($submission, $user, $online, $allowed) {
            $submission->ksef_session_reference = $sessionRef;
            $submission->ksef_reference = $invoiceRef;
            if ($online) {
                $submission->status = 'SUBMITTED';
            }
            if ($submission->update($user, 1, $allowed) <= 0) {
                dol_syslog("KSEF::sendWithRecord references not saved on row " . (int) $submission->rowid . ": " . $submission->error, LOG_WARNING);
            }
        };
        $api = $this->client->submitInvoice($xml, $options);
        $this->storeSendReferences($submission, $api);
        $out = array('outcome' => 'FAILED', 'api' => $api, 'allowed' => $allowed, 'adopted' => false);

        if (is_array($api) && ($api['status'] ?? '') === 'ACCEPTED' && !empty($api['ksef_number'])) {
            $r = $this->acceptSubmission($submission, $api, $user, $allowed);
            $out['outcome'] = $r > 0 ? 'ACCEPTED' : ($r === 0 ? 'MOVED' : 'NOT_RECORDED');
            $out['accepted'] = $api;
            return $out;
        }
        if (is_array($api) && ($api['status'] ?? '') === 'TIMEOUT') {
            if ($online) {
                $submission->status = 'TIMEOUT';
            } elseif ($submission->status === 'PENDING') {
                $submission->status = 'OFFLINE';
            }
            $submission->error_message = $api['error'];
            $submission->error_code = null;
            $submission->error_details = null;
            $submission->api_response = json_encode($api);
            $r = $submission->update($user, 1, $allowed);
            $out['outcome'] = $r === 0 ? 'MOVED' : 'TIMEOUT';
            return $out;
        }
        if ((int) $this->client->last_error_code === 440) {
            $details = is_array($this->client->last_error_details) ? $this->client->last_error_details : array();
            $sendError = $this->client->error;
            $adopt = $this->adoptAfterDuplicate($submission, $details, $user, $allowed);
            if ($adopt === 'ACCEPTED') {
                $out['outcome'] = 'ACCEPTED';
                $out['adopted'] = true;
                return $out;
            }
            $this->client->last_error_code = 440;
            $this->client->error = $sendError;
            $details['adopt'] = $adopt;
            $this->client->last_error_details = $details;
            $out['error_details'] = $details;
        }
        return $out;
    }

    /**
     * @brief Mark row accepted
     * @param KsefSubmission $submission
     * @param array $data
     * @param User $user
     * @param array|null $allowed From statuses
     * @return int 0 moved, <0 error
     */
    private function acceptSubmission($submission, $data, $user, $allowed = null)
    {
        $submission->status = 'ACCEPTED';
        $submission->ksef_number = $data['ksef_number'];
        if (!empty($data['session_reference'])) {
            $submission->ksef_session_reference = $data['session_reference'];
        }
        if (!empty($data['upo_xml'])) {
            $submission->upo_xml = $data['upo_xml'];
        } elseif (empty($submission->upo_xml) && ($same = $this->upoOfSameNumber($submission)) !== '') {
            $submission->upo_xml = $same;
        } elseif (empty($submission->upo_xml) && $submission->getSessionReference() !== '') {
            $upo = $this->client->downloadUPO($submission->ksef_number, $submission->getSessionReference());
            if ($upo) {
                $submission->upo_xml = $upo;
            }
        }
        $submission->date_acceptance = dol_now();
        $submission->error_message = null;
        $submission->error_code = null;
        $submission->error_details = null;
        $submission->api_response = json_encode(array_diff_key($data, array('raw_response' => 1, 'upo_xml' => 1))
            + array('session_reference' => $submission->getSessionReference()));
        $r = $submission->update($user, 1, $allowed);
        if ($r > 0) {
            $this->carryAcceptedForward($submission, $user);
            $this->refreshDocuments($submission, (int) $submission->fk_facture);
            $submission->closeReplacedOpen($user, (string) $submission->environment, (int) $submission->fk_facture);
        }
        return $r;
    }

    /**
     * @brief Carry acceptance forward
     * @param KsefSubmission $submission
     * @param User $user
     * @return int New id, -1 error
     */
    private function carryAcceptedForward($submission, $user)
    {
        if ((int) $submission->rowid <= 0 || (int) $submission->fk_facture <= 0 || (string) $submission->status !== KsefSubmission::STATUS_ACCEPTED) {
            return 0;
        }
        $res = $this->db->query("SELECT status, error_code, date_submission FROM " . MAIN_DB_PREFIX . "ksef_submissions WHERE fk_facture = " . (int) $submission->fk_facture
            . " AND environment = '" . $this->db->escape((string) $submission->environment) . "' AND rowid > " . (int) $submission->rowid);
        if (!$res) {
            return -1;
        }
        $newer = 0;
        $latestDate = 0;
        while ($o = $this->db->fetch_object($res)) {
            if (!in_array((string) $o->status, array(KsefSubmission::STATUS_FAILED, KsefSubmission::STATUS_REJECTED), true) || (int) $o->error_code === 440) {
                return 0;
            }
            $newer++;
            $latestDate = max($latestDate, (int) $o->date_submission);
        }
        if ($newer === 0) {
            return 0;
        }
        $row = new KsefSubmission($this->db);
        foreach (array('fk_facture', 'ksef_reference', 'ksef_number', 'ksef_session_reference', 'invoice_hash', 'environment', 'fa3_xml', 'upo_xml',
            'date_acceptance', 'fk_user_submit', 'offline_mode', 'offline_deadline', 'offline_detected_reason', 'original_invoice_hash', 'fa3_creation_date',
            'invoice_type', 'locked_fields') as $f) {
            $row->$f = $submission->$f;
        }
        $row->status = KsefSubmission::STATUS_ACCEPTED;
        $row->source = KsefSubmission::SOURCE_CARRIED_FORWARD;
        $row->date_submission = max(dol_now(), $latestDate + 1);
        $row->api_response = json_encode(array('ksef_number' => (string) $submission->ksef_number, 'session_reference' => $submission->getSessionReference(),
            'carried_from_row' => (int) $submission->rowid, 'carried_from_date' => (int) $submission->date_submission,
            'carried_from_source' => (string) $submission->source, 'failed_after' => $newer));
        $id = $row->create($user, 1);
        if ($id <= 0) {
            dol_syslog("KSEF::carryAcceptedForward row " . (int) $submission->rowid . " accepted as " . $submission->ksef_number . ", the latest row not written: " . $row->error, LOG_ERR);
            return -1;
        }
        $ef = $this->db->query("SELECT ksef_number FROM " . MAIN_DB_PREFIX . "facture_extrafields WHERE fk_object = " . (int) $submission->fk_facture);
        $efo = $ef ? $this->db->fetch_object($ef) : null;
        if ($efo && (string) $efo->ksef_number === (string) $submission->ksef_number) {
            ksefUpdateInvoiceExtrafields($this->db, (int) $submission->fk_facture, null, null, (int) $submission->date_submission, false);
        }
        dol_syslog("KSEF::carryAcceptedForward row " . (int) $submission->rowid . " accepted as " . $submission->ksef_number . " after " . $newer
            . " failed send(s) of invoice " . (int) $submission->fk_facture . ": record carried to the new latest row " . (int) $id, LOG_INFO);
        return (int) $id;
    }

    /**
     * @brief Prove copy ours
     * @param KsefSubmission $submission
     * @param string $ksefNumber
     * @param string $sessionRef '' unknown
     * @return array
     */
    private function proveOurs($submission, $ksefNumber, $sessionRef)
    {
        $candidates = $submission->getInvoiceHashes();
        if (!empty($submission->invoice_hash)) {
            $candidates[] = (string) $submission->invoice_hash;
        }
        $res = $this->db->query("SELECT ksef_number FROM " . MAIN_DB_PREFIX . "facture_extrafields WHERE fk_object = " . (int) $submission->fk_facture);
        $ef = $res ? $this->db->fetch_object($res) : null;
        $extrafieldSays = $ef && trim((string) $ef->ksef_number) === $ksefNumber;

        $dl = $this->client->downloadInvoiceXml($ksefNumber);
        if ($dl) {
            $ok = in_array($dl['hash'], $candidates, true) || $extrafieldSays;
            return array('result' => $ok ? 'match' : 'hash_mismatch', 'xml' => $dl['xml'], 'hash' => $dl['hash']);
        }
        if ((int) $this->client->last_http_code === 403 && $sessionRef !== '') {
            $upo = $this->client->downloadUPO($ksefNumber, $sessionRef);
            if ($upo && preg_match('#<(?:\w+:)?SkrotDokumentu>([^<]+)</#', $upo, $m)) {
                $ok = in_array(trim($m[1]), $candidates, true) || $extrafieldSays;
                return array('result' => $ok ? 'match' : 'hash_mismatch', 'xml' => null, 'hash' => trim($m[1]), 'upo_xml' => $upo);
            }
            return array('result' => 'no_permission', 'xml' => null, 'hash' => null);
        }
        if ((int) $this->client->last_http_code === 403) {
            return array('result' => 'no_permission', 'xml' => null, 'hash' => null);
        }
        if ((int) $this->client->last_error_code === 21164 || (int) $this->client->last_http_code === 404) {
            return array('result' => 'not_found', 'xml' => null, 'hash' => null);
        }
        return array('result' => 'gateway', 'xml' => null, 'hash' => null);
    }

    /**
     * @brief Adopt 440 number
     * @param KsefSubmission $submission
     * @param array $details 440 details
     * @param User $user
     * @param array|null $allowed From statuses
     * @return string ACCEPTED or reason
     */
    private function adoptAfterDuplicate($submission, $details, $user, $allowed = null)
    {
        $number = (string) ($details['original_ksef_number'] ?? '');
        $session = (string) ($details['original_session'] ?? '');
        if ($number === '') {
            return 'no_original';
        }
        $proof = $this->proveOurs($submission, $number, $session);
        if ($proof['result'] !== 'match') {
            dol_syslog("KSEF::adoptAfterDuplicate row " . (int) $submission->rowid . " number $number not adopted: " . $proof['result'], LOG_WARNING);
            return $proof['result'];
        }
        $submission->source = KsefSubmission::SOURCE_ADOPTED_440;
        $data = array('ksef_number' => $number, 'session_reference' => $session !== '' ? $session : null);
        if (!empty($proof['upo_xml'])) {
            $data['upo_xml'] = $proof['upo_xml'];
        }
        $submission->ksef_reference = null;
        $this->takeKsefCopy($submission, $proof);
        $r = $this->acceptSubmission($submission, $data, $user, $allowed);
        if ($r <= 0) {
            dol_syslog("KSEF::adoptAfterDuplicate row " . (int) $submission->rowid . " ACCEPTED not saved: " . $submission->error, LOG_ERR);
            return 'record';
        }
        dol_syslog("KSEF::adoptAfterDuplicate row " . (int) $submission->rowid . " adopted KSeF number $number", LOG_INFO);
        return 'ACCEPTED';
    }

    /**
     * @brief Take KSeF copy
     * @param KsefSubmission $row
     * @param array $proof
     * @return void
     */
    private function takeKsefCopy($row, $proof)
    {
        if (empty($proof['hash']) || $proof['hash'] === (string) $row->invoice_hash) {
            return;
        }
        $xml = !empty($proof['xml']) ? $proof['xml'] : null;
        if ($xml === null) {
            $res = $this->db->query("SELECT fa3_xml FROM " . MAIN_DB_PREFIX . "ksef_submissions WHERE fk_facture = " . (int) $row->fk_facture
                . " AND invoice_hash = '" . $this->db->escape($proof['hash']) . "' AND fa3_xml IS NOT NULL ORDER BY rowid DESC LIMIT 1");
            $o = $res ? $this->db->fetch_object($res) : null;
            $xml = $o ? $o->fa3_xml : null;
        }
        $row->invoice_hash = $proof['hash'];
        $row->fa3_xml = $xml;
        dol_syslog("KSEF row " . (int) $row->rowid . " takes the copy KSeF holds; Dolibarr differs from it", LOG_WARNING);
    }

    /**
     * @brief Retry 440 adoption
     * @param KsefSubmission $existing
     * @param User $user
     * @return array
     */
    private function retryDuplicateAdoption($existing, $user)
    {
        global $langs;
        $langs->load('ksef@ksef');
        $details = $existing->getErrorDetailsArray() ?: array();
        $adopt = $this->adoptAfterDuplicate($existing, $details, $user, array('FAILED', 'REJECTED'));
        if ($adopt === 'ACCEPTED') {
            return array('status' => 'ACCEPTED', 'ksef_number' => $existing->ksef_number, 'submission_id' => $existing->rowid,
                'adopted' => 1, 'message' => 'Invoice was already in KSeF, number adopted');
        }
        if ($adopt !== ($details['adopt'] ?? '')) {
            $details['adopt'] = $adopt;
            $existing->error_details = json_encode($details);
            $existing->update($user, 1, array('FAILED', 'REJECTED'));
        }
        $this->error = $langs->transnoentitiesnoconv('KSEF_RetryRefusedDuplicate');
        return array('status' => 'FAILED', 'error' => $this->error, 'error_code' => 440, 'submission_id' => $existing->rowid,
            'duplicate' => 1, 'adopt' => $adopt, 'can_use_offline' => false);
    }

    /**
     * @brief Resolve before resend
     * @param KsefSubmission $existing
     * @param User $user
     * @return array|null null when safe
     */
    private function resolveBeforeResend($existing, $user)
    {
        global $langs;
        $langs->load('ksef@ksef');
        $res = $this->refreshSubmissionStatus($existing, $user);
        if ($res === false) {
            if (in_array($this->failureReason, array('noref', 'notfound'), true)) {
                return null;
            }
            if ($this->notRecordedNumber !== '') {
                return array('status' => 'ACCEPTED', 'ksef_number' => $this->notRecordedNumber, 'submission_id' => $existing->rowid,
                    'record_saved' => false, 'warning' => $this->error);
            }
            $this->error = $langs->transnoentitiesnoconv('KSEF_CannotCheckNow');
            return array('status' => 'TIMEOUT', 'in_progress' => 1, 'error' => $this->error, 'submission_id' => $existing->rowid);
        }
        if ($existing->status === 'ACCEPTED') {
            return array('status' => 'ACCEPTED', 'ksef_number' => $existing->ksef_number, 'submission_id' => $existing->rowid,
                'adopted' => $existing->source === KsefSubmission::SOURCE_ADOPTED_440 ? 1 : 0, 'message' => 'Invoice found in KSeF');
        }
        if (($res['status'] ?? '') === 'PENDING') {
            $this->error = $langs->transnoentitiesnoconv('KSEF_StatusStillProcessing');
            return array('status' => 'TIMEOUT', 'in_progress' => 1, 'error' => $this->error, 'submission_id' => $existing->rowid);
        }
        if ($existing->isDuplicate()) {
            return $this->retryDuplicateAdoption($existing, $user);
        }
        return null;
    }

    /**
     * @brief Unsettled row result
     * @param KsefSubmission $submission
     * @param array $sent
     * @return array
     */
    private function resultOfRow($submission, $sent)
    {
        global $langs;
        $langs->load('ksef@ksef');
        if ($sent['outcome'] === 'MOVED') {
            $submission->fetch($submission->rowid);
        }
        if ($submission->status === 'ACCEPTED') {
            return array('status' => 'ACCEPTED', 'ksef_number' => $submission->ksef_number, 'submission_id' => $submission->rowid);
        }
        $final = in_array($submission->status, array('FAILED', 'REJECTED'), true);
        $rejected = $submission->status === 'REJECTED';
        $this->error = $final ? (string) $submission->error_message : $langs->transnoentitiesnoconv('KSEF_SendOutcomeUnknown');
        return array(
            'status' => $final ? 'FAILED' : 'TIMEOUT',
            'error' => $this->error,
            'error_code' => $final ? $submission->error_code : null,
            'failure_kind' => $rejected ? 'rejected' : null,
            'rejected_offline' => ($rejected && !empty($submission->offline_mode)) ? 1 : 0,
            'submission_id' => $submission->rowid,
            'session_reference' => $submission->ksef_session_reference,
            'reference_number' => $submission->ksef_reference,
            'can_use_offline' => false
        );
    }

    /**
     * @brief Link KSeF number
     * @param int $invoice_id
     * @param string $ksefNumber
     * @param User $user
     * @param string $source Row source
     * @return array
     */
    public function linkKsefNumber($invoice_id, $ksefNumber, $user, $source = '')
    {
        global $langs;
        $langs->load('ksef@ksef');
        $ksefNumber = trim((string) $ksefNumber);
        $latest = null;
        $fail = function ($reason, $msg) use (&$latest, $user) {
            $this->error = $msg;
            if (is_object($latest) && !empty($latest->rowid) && in_array($latest->status, array('FAILED', 'REJECTED'), true)
                && method_exists($latest, 'isDuplicate') && $latest->isDuplicate()
                && in_array($reason, array('not_found', 'no_permission', 'gateway', 'fields', 'used'), true)) {
                $details = $latest->getErrorDetailsArray() ?: array();
                $details['link_refused'] = array('reason' => $reason, 'date' => dol_now());
                $latest->error_details = json_encode($details);
                $latest->update($user, 1, array('FAILED', 'REJECTED'));
            }
            return array('status' => 'FAILED', 'reason' => $reason, 'error' => $msg);
        };
        if (!ksefIsKsefNumber($ksefNumber)) {
            return $fail('bad_number', $langs->transnoentitiesnoconv('KSEF_LinkRefusedNotFound', $ksefNumber));
        }
        $invoice = new Facture($this->db);
        if ($invoice->fetch((int) $invoice_id) <= 0) {
            return $fail('not_found', 'Invoice not found');
        }
        $latest = new KsefSubmission($this->db);
        $found = $latest->fetchByInvoice((int) $invoice_id, ksefGetEnvironment());
        if ($found < 0) {
            return $fail('record', ksefDbErrorForUser($user, 'KSEF_SubmissionRecordReadFailed', $latest->error));
        }
        $allowedNumber = '';
        if ($found > 0 && $latest->isDuplicate()) {
            $allowedNumber = $latest->getDuplicateOriginal()['ksef_number'];
        }
        if ($allowedNumber !== $ksefNumber && ksefNumberWithoutRow($this->db, (int) $invoice_id) === $ksefNumber) {
            $allowedNumber = $ksefNumber;
        }
        if ($allowedNumber !== $ksefNumber && $this->salesNumberOffered($ksefNumber, (int) $invoice_id)) {
            $allowedNumber = $ksefNumber;
        }
        if ($found > 0 && in_array($latest->status, array('ACCEPTED', 'OFFLINE'), true) && !empty($latest->ksef_number)) {
            return $fail('conflict', $langs->transnoentitiesnoconv('KSEF_LinkRefusedConflict', $latest->ksef_number));
        }
        if ($allowedNumber === '' || $allowedNumber !== $ksefNumber) {
            return $fail('not_offered', $langs->transnoentitiesnoconv('KSEF_LinkRefusedNotOffered'));
        }
        $res = $this->db->query("SELECT fk_facture FROM " . MAIN_DB_PREFIX . "ksef_submissions WHERE ksef_number = '" . $this->db->escape($ksefNumber) . "'"
            . " AND status = 'ACCEPTED' AND fk_facture <> " . (int) $invoice_id . " LIMIT 1");
        if ($res && $this->db->num_rows($res)) {
            return $fail('used', $langs->transnoentitiesnoconv('KSEF_LinkRefusedUsed', $ksefNumber));
        }

        $probe = $found > 0 ? $latest : new KsefSubmission($this->db);
        $probe->fk_facture = (int) $invoice_id;
        $session = $found > 0 ? $latest->getDuplicateOriginal()['session'] : '';
        $proof = $this->proveOurs($probe, $ksefNumber, $session);
        if ($proof['result'] === 'not_found') {
            return $fail('not_found', $langs->transnoentitiesnoconv('KSEF_LinkRefusedNotFound', $ksefNumber));
        }
        if ($proof['result'] === 'no_permission') {
            return $fail('no_permission', $langs->transnoentitiesnoconv('KSEF_LinkRefusedNoPermission'));
        }
        if ($proof['result'] === 'gateway') {
            return $fail('gateway', $langs->transnoentitiesnoconv('KSEF_CannotCheckNow'));
        }
        if ($proof['result'] !== 'match') {
            $diff = $this->linkFieldDiff((int) $invoice_id, (string) $proof['xml'], $found > 0 ? (string) $latest->fa3_xml : '');
            if ($diff === null) {
                return $fail('fields', $langs->transnoentitiesnoconv('KSEF_LinkRefusedUnreadable'));
            }
            if ($diff) {
                return $fail('fields', $langs->transnoentitiesnoconv('KSEF_LinkRefusedFields', implode(', ', $diff)));
            }
        }

        $open = array(KsefSubmission::STATUS_PENDING, KsefSubmission::STATUS_SUBMITTED, KsefSubmission::STATUS_TIMEOUT);
        if ($found > 0 && in_array((string) $latest->status, $open, true) && !empty($latest->invoice_hash)
            && (string) ($proof['hash'] ?? '') === (string) $latest->invoice_hash) {
            $latest->source = $source === KsefSubmission::SOURCE_LINKED_FROM_SYNC ? KsefSubmission::SOURCE_LINKED_FROM_SYNC : KsefSubmission::SOURCE_ADOPTED_MANUAL;
            $data = array('ksef_number' => $ksefNumber, 'linked_by' => $user->login);
            if ($session !== '') {
                $data['session_reference'] = $session;
            }
            if (!empty($proof['upo_xml'])) {
                $data['upo_xml'] = $proof['upo_xml'];
            }
            $r = $this->acceptSubmission($latest, $data, $user, $open);
            if ($r === 0) {
                $latest->fetch($latest->rowid);
                if ($latest->status !== KsefSubmission::STATUS_ACCEPTED || (string) $latest->ksef_number !== $ksefNumber) {
                    return $fail('conflict', $langs->transnoentitiesnoconv('KSEF_LinkRefusedConflict', (string) $latest->ksef_number));
                }
            } elseif ($r < 0) {
                return $fail('record', ksefDbErrorForUser($user, 'KSEF_SubmissionNotRecorded', $latest->error));
            }
            $this->db->query("UPDATE " . MAIN_DB_PREFIX . "ksef_sales SET fk_facture = " . (int) $invoice_id . ", import_status = 'IMPORTED', link_status = 'OK', link_candidate = NULL, link_note = NULL"
                . " WHERE ksef_number = '" . $this->db->escape($ksefNumber) . "'");
            dol_syslog("KSEF::linkKsefNumber invoice $invoice_id: its open send (row " . (int) $latest->rowid . ") accepted with KSeF number $ksefNumber by " . $user->login, LOG_INFO);
            return array('status' => 'ACCEPTED', 'ksef_number' => $ksefNumber, 'submission_id' => $latest->rowid, 'has_upo' => !empty($latest->upo_xml)) + $this->pdfWarningResult();
        }

        $row = new KsefSubmission($this->db);
        $row->fk_facture = (int) $invoice_id;
        $row->ksef_number = $ksefNumber;
        $row->ksef_session_reference = $session !== '' ? $session : null;
        $row->fa3_xml = $found > 0 ? $latest->fa3_xml : null;
        $row->invoice_hash = $found > 0 ? $latest->invoice_hash : null;
        $this->takeKsefCopy($row, $proof);
        $row->invoice_type = $found > 0 ? $latest->invoice_type : null;
        $row->fa3_creation_date = $found > 0 ? $latest->fa3_creation_date : null;
        $row->status = 'ACCEPTED';
        $row->source = $source === KsefSubmission::SOURCE_LINKED_FROM_SYNC ? KsefSubmission::SOURCE_LINKED_FROM_SYNC : KsefSubmission::SOURCE_ADOPTED_MANUAL;
        $row->environment = ksefGetEnvironment();
        $row->date_submission = dol_now();
        $row->date_acceptance = dol_now();
        $row->upo_xml = !empty($proof['upo_xml']) ? $proof['upo_xml'] : null;
        $row->api_response = json_encode(array('ksef_number' => $ksefNumber, 'session_reference' => $session, 'linked_by' => $user->login));
        if ($row->create($user) <= 0) {
            return $fail('record', ksefDbErrorForUser($user, 'KSEF_SubmissionNotRecorded', $row->error));
        }
        if (empty($row->upo_xml) && $session !== '') {
            $upo = $this->client->downloadUPO($ksefNumber, $session);
            if ($upo) {
                $row->upo_xml = $upo;
                $row->update($user, 1);
            }
        }
        $this->refreshDocuments($row, (int) $invoice_id);
        $row->closeReplacedOpen($user, (string) $row->environment, (int) $invoice_id);
        $this->db->query("UPDATE " . MAIN_DB_PREFIX . "ksef_sales SET fk_facture = " . (int) $invoice_id . ", import_status = 'IMPORTED', link_status = 'OK', link_candidate = NULL, link_note = NULL"
            . " WHERE ksef_number = '" . $this->db->escape($ksefNumber) . "'");
        dol_syslog("KSEF::linkKsefNumber invoice $invoice_id tied to KSeF number $ksefNumber by " . $user->login, LOG_INFO);
        return array('status' => 'ACCEPTED', 'ksef_number' => $ksefNumber, 'submission_id' => $row->rowid, 'has_upo' => !empty($row->upo_xml)) + $this->pdfWarningResult();
    }

    /**
     * @brief Sales number offered
     * @param string $ksefNumber
     * @param int $invoice_id
     * @return bool
     */
    private function salesNumberOffered($ksefNumber, $invoice_id)
    {
        $res = $this->db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "ksef_sales WHERE ksef_number = '" . $this->db->escape($ksefNumber) . "'"
            . " AND (fk_facture IS NULL OR fk_facture = 0) AND link_candidate = " . (int) $invoice_id
            . " AND link_status IN ('LINKABLE', 'SUGGEST') AND environment = '" . $this->db->escape(ksefGetEnvironment()) . "'");
        return $res && $this->db->num_rows($res) > 0;
    }

    /**
     * @brief Reconcile stored sales
     * @param string $environment
     * @param User|null $forUser
     * @param bool $onlyNew Only never reconciled rows
     * @return array Status counts
     */
    public function reconcileSales($environment, $forUser = null, $onlyNew = false)
    {
        global $user;
        dol_include_once('/ksef/class/ksef_sales.class.php');
        $u = $forUser ?: $user;
        $sales = new KsefSales($this->db);
        return $sales->reconcileAll($environment, $u, $onlyNew);
    }

    /**
     * @brief Key field diff
     * @param int $invoice_id
     * @param string $ksefXml
     * @param string $ourXml '' to build
     * @return array|null null when unreadable
     */
    private function linkFieldDiff($invoice_id, $ksefXml, $ourXml)
    {
        dol_include_once('/ksef/class/fa3_parser.class.php');
        if ($ourXml === '') {
            $ourXml = (string) $this->builder->buildFromInvoice($invoice_id);
        }
        $parser = new FA3Parser($this->db);
        $theirs = $ksefXml !== '' ? $parser->parse($ksefXml) : false;
        $ours = $ourXml !== '' ? $parser->parse($ourXml) : false;
        if (!$theirs || !$ours) {
            return null;
        }
        $diff = array();
        if ((string) $theirs['invoice']['date'] !== (string) $ours['invoice']['date']) {
            $diff[] = 'P_1';
        }
        if (ksefCleanNIP((string) $theirs['buyer']['nip']) !== ksefCleanNIP((string) $ours['buyer']['nip'])) {
            $diff[] = 'NIP';
        }
        if (abs((float) $theirs['invoice']['total_gross'] - (float) $ours['invoice']['total_gross']) >= 0.005) {
            $diff[] = 'P_15';
        }
        if (strtoupper((string) $theirs['invoice']['currency']) !== strtoupper((string) $ours['invoice']['currency'])) {
            $diff[] = 'KodWaluty';
        }
        return $diff;
    }

    /**
     * @brief Mark not sent
     * @param KsefSubmission $submission
     * @param User $user
     * @return int 0 refused, <0 error
     */
    public function markNotSent($submission, $user)
    {
        global $langs;
        $langs->load('ksef@ksef');
        $unknown = $submission->status === 'TIMEOUT' || $submission->isStale();
        if (!$unknown || !empty($submission->ksef_reference) || !empty($submission->offline_mode)) {
            $this->error = $langs->transnoentitiesnoconv('KSEF_MarkNotSentRefused');
            return 0;
        }
        if (in_array(ksefHashStopState($submission), array('IN_SALES_UNLINKED', 'AMBIGUOUS'), true)) {
            $this->error = $langs->transnoentitiesnoconv('KSEF_MarkNotSentFoundInKsef');
            return 0;
        }
        $from = (string) $submission->status;
        $submission->status = 'FAILED';
        $submission->error_code = null;
        $submission->error_details = null;
        $submission->failure_kind = 'unknown';
        $submission->error_message = $langs->transnoentitiesnoconv('KSEF_MarkedNotSentNote', $user->login);
        $r = $submission->update($user, 1, array($from));
        if ($r <= 0) {
            $this->error = $r === 0 ? $langs->transnoentitiesnoconv('KSEF_StatusChangedMeanwhile') : $submission->error;
            return $r === 0 ? 0 : -1;
        }
        dol_syslog("KSEF::markNotSent row " . (int) $submission->rowid . " ($from) marked not sent by " . $user->login, LOG_INFO);
        return 1;
    }

    /**
     * @brief Refresh row status
     * @param KsefSubmission $submission
     * @param User $user
     * @param bool $byHash
     * @return array|false
     */
    public function refreshSubmissionStatus($submission, $user, $byHash = false)
    {
        $mark = $this->cronRateHits();
        $this->hashSearchRateLimited = false;
        try {
            return $this->refreshSubmissionStatusRun($submission, $user, $byHash);
        } finally {
            if ($this->searchedByHash && $this->cronRateHits() > $mark) {
                $this->hashSearchRateLimited = true;
                $this->pauseHashSearch(isset($this->client->retry_after_seconds) ? (int) $this->client->retry_after_seconds : 0);
            }
        }
    }

    /**
     * @brief Refresh status body
     * @param KsefSubmission $submission
     * @param User $user
     * @param bool $byHash
     * @return array|false
     */
    private function refreshSubmissionStatusRun($submission, $user, $byHash)
    {
        $this->failureReason = null;
        $this->searchedByHash = false;
        $this->notRecordedNumber = '';
        $this->hashNotFoundFresh = false;
        $this->hashSearchComplete = false;
        $this->hashSearchRead = 0;
        $this->hashSearchListed = 0;
        $this->hashSearchMatches = 0;
        $sessionRef = $submission->getSessionReference();
        if ($sessionRef === '' && $byHash && !empty($submission->invoice_hash)
            && (empty($submission->offline_mode) || $submission->status !== KsefSubmission::STATUS_OFFLINE)) {
            $this->searchedByHash = true;
            $fromSales = empty($submission->offline_mode) ? $this->adoptFromSales($submission, $user) : 0;
            if ($fromSales > 0) {
                return array('status' => 'ACCEPTED', 'ksef_number' => $submission->ksef_number, 'from_sales' => 1);
            }
            if ($fromSales < 0) {
                $this->failureReason = 'record';
                return false;
            }
            if ($this->manualHashSearch && !$this->manualSearchClaim()) {
                return false;
            }
            try {
                $byHashFound = $this->findSentByHash($submission, $user);
            } catch (Exception $e) {
                $this->error = $e->getMessage();
                $this->failureReason = 'gateway';
                $byHashFound = -1;
            }
            if ($byHashFound <= 0) {
                return false;
            }
            $sessionRef = $submission->getSessionReference();
        }
        if ($sessionRef === '') {
            $this->error = "No KSeF session and invoice reference to check";
            $this->failureReason = 'noref';
            return false;
        }
        $allowed = array((string) $submission->status);

        try {
            if (empty($submission->ksef_reference)) {
                if (empty($submission->invoice_hash)) {
                    $this->error = "No KSeF invoice reference to check";
                    $this->failureReason = 'noref';
                    return false;
                }
                $found = $this->client->findInvoiceInSession($sessionRef, $submission->invoice_hash);
                if ($found === null) {
                    $this->error = "KSeF did not receive the invoice in session $sessionRef";
                    $this->failureReason = 'notfound';
                    $submission->date_last_check = dol_now();
                    if (empty($submission->offline_mode) && dol_now() - (int) $submission->date_submission > ksefSendStaleAfter()) {
                        $this->closeNotReceived($submission, $user);
                        return false;
                    }
                    $submission->update($user, 1, $allowed);
                    return false;
                }
                if ($found !== false && !empty($found['reference_number'])) {
                    $submission->ksef_reference = $found['reference_number'];
                }
            }
            $status_result = !empty($submission->ksef_reference) ? $this->client->checkStatus($sessionRef, $submission->ksef_reference) : false;
        } catch (Exception $e) {
            $status_result = false;
            $this->client->error = $e->getMessage();
        }
        if (!is_array($status_result) || !in_array($status_result['status'] ?? '', array('ACCEPTED', 'FAILED', 'PENDING'), true)) {
            $this->error = !empty($this->client->error) ? $this->client->error : 'KSeF gateway returned no usable status';
            $this->failureReason = 'gateway';
            dol_syslog("KSEF::refreshSubmissionStatus gateway failure for row " . (int) $submission->rowid . ": " . $this->error, LOG_ERR);
            return false;
        }

        $submission->date_last_check = dol_now();
        $failDetails = is_array($this->client->last_error_details) ? $this->client->last_error_details : array();
        if ($status_result['status'] === 'ACCEPTED') {
            $r = $this->acceptSubmission($submission, array_diff_key($status_result, array('raw_response' => 1)) + array('session_reference' => $sessionRef), $user, $allowed);
        } else {
            if ($status_result['status'] === 'FAILED' && (int) $status_result['code'] === 440) {
                $adopt = $this->adoptAfterDuplicate($submission, $failDetails, $user, $allowed);
                if ($adopt === 'ACCEPTED') {
                    return array_merge($status_result, array('status' => 'ACCEPTED', 'ksef_number' => $submission->ksef_number, 'adopted' => 1));
                }
                $failDetails['adopt'] = $adopt;
            }
            if ($status_result['status'] === 'FAILED') {
                $submission->status = !empty($submission->offline_mode) ? 'REJECTED' : 'FAILED';
                $submission->error_code = $status_result['code'];
                $submission->error_message = $status_result['description'] . (!empty($status_result['details']) ? ' - ' . implode('; ', $status_result['details']) : '');
                $submission->error_details = json_encode($failDetails);
            }
            $r = $submission->update($user, 1, $allowed);
        }

        if ($r === 0) {
            $submission->fetch($submission->rowid);
            return array_merge($status_result, array('status' => $submission->status === 'ACCEPTED' ? 'ACCEPTED' : $status_result['status'],
                'ksef_number' => $submission->ksef_number));
        }
        if ($r < 0) {
            $this->error = $submission->error ?: 'Could not persist submission status';
            $this->failureReason = 'record';
            dol_syslog("KSEF::refreshSubmissionStatus persist failed for row " . (int) $submission->rowid . ": " . $this->error, LOG_ERR);
            if ($status_result['status'] === 'ACCEPTED') {
                global $langs;
                $langs->load('ksef@ksef');
                $this->notRecordedNumber = (string) $status_result['ksef_number'];
                $this->error = $langs->transnoentitiesnoconv('KSEF_AcceptedButNotRecorded', $this->notRecordedNumber);
            }
            return false;
        }
        return $status_result;
    }

    /**
     * @brief Check invoice status
     * @param $invoice_id
     * @param $user
     * @param bool $manual User or REST
     * @return array|false
     */
    public function checkStatus($invoice_id, $user, $manual = false)
    {
        $this->failureReason = null;
        $submission = new KsefSubmission($this->db);
        if ($submission->fetchByInvoice($invoice_id, ksefGetEnvironment()) <= 0) {
            $this->error = ksefMsg('KSEF_ErrNoSubmission');
            $this->failureReason = 'notfound';
            return false;
        }

        if (!in_array($submission->status, array('PENDING', 'OFFLINE', 'SUBMITTED', 'TIMEOUT'))) {
            return array(
                'status' => $submission->status,
                'ksef_number' => $submission->ksef_number,
                'message' => 'Submission not in pending state'
            );
        }

        return $manual ? $this->checkStatusManual($submission, $user) : $this->refreshSubmissionStatus($submission, $user, true);
    }

    /**
     * @brief Manual status check
     * @param KsefSubmission $submission
     * @param User $user
     * @return array|false
     */
    public function checkStatusManual($submission, $user)
    {
        global $langs;
        $langs->load('ksef@ksef');
        $this->hashSearchWaitUntil = 0;
        if (ksefStatusCheckSearches($submission) && !ksefUserCan($user, 'search_by_hash')) {
            $this->failureReason = 'right';
            $this->searchedByHash = false;
            $this->error = $langs->transnoentitiesnoconv('KSEF_HashSearchNoRight', $langs->transnoentitiesnoconv('KSEF_RightNameKsefWrite'));
            return false;
        }
        $this->manualHashSearch = true;
        try {
            return $this->refreshSubmissionStatus($submission, $user, true);
        } finally {
            $this->manualHashSearch = false;
            $this->manualSearchRelease();
        }
    }

    /**
     * @brief Claim search slot
     * @return bool
     */
    private function manualSearchClaim()
    {
        global $conf, $langs;
        $langs->load('ksef@ksef');
        $now = dol_now();
        $pause = $this->hashSearchPausedUntil();
        if ($pause > $now) {
            $this->failureReason = 'wait';
            $this->hashSearchWaitUntil = $pause;
            $this->error = $langs->transnoentitiesnoconv('KSEF_HashSearchPaused', dol_print_date($pause, 'dayhoursec', 'tzuserrel'));
            return false;
        }
        $name = 'KSEF_HASH_SEARCH_MANUAL_NEXT';
        // compared as text
        $where = " WHERE name = '" . $this->db->escape($name) . "' AND entity = " . (int) $conf->entity;
        $res = $this->db->query("SELECT value FROM " . MAIN_DB_PREFIX . "const" . $where);
        if ($res && !$this->db->fetch_object($res)) {
            dolibarr_set_const($this->db, $name, sprintf('%012d', 0), 'chaine', 0, '', $conf->entity);
        }
        $res = $this->db->query("UPDATE " . MAIN_DB_PREFIX . "const SET value = '" . sprintf('%012d', $now + self::MANUAL_HASH_SEARCH_HOLD) . "'"
            . $where . " AND value <= '" . sprintf('%012d', $now) . "'");
        if ($res && $this->db->affected_rows($res) > 0) {
            $this->manualHashClaimed = true;
            return true;
        }
        $res = $this->db->query("SELECT value FROM " . MAIN_DB_PREFIX . "const" . $where);
        $o = $res ? $this->db->fetch_object($res) : null;
        $next = $o ? (int) $o->value : $now + self::MANUAL_HASH_SEARCH_GAP;
        $this->failureReason = 'wait';
        if ($next - $now > self::MANUAL_HASH_SEARCH_GAP) {
            $this->hashSearchWaitUntil = $now + self::MANUAL_HASH_SEARCH_GAP;
            $this->error = $langs->transnoentitiesnoconv('KSEF_HashSearchBusy');
        } else {
            $this->hashSearchWaitUntil = $next;
            $this->error = $langs->transnoentitiesnoconv('KSEF_HashSearchCooldown', dol_print_date($next, 'dayhoursec', 'tzuserrel'));
        }
        return false;
    }

    /**
     * @brief Release search slot
     * @return void
     */
    private function manualSearchRelease()
    {
        global $conf;
        if (!$this->manualHashClaimed) {
            return;
        }
        $this->manualHashClaimed = false;
        $this->db->query("UPDATE " . MAIN_DB_PREFIX . "const SET value = '" . sprintf('%012d', dol_now() + self::MANUAL_HASH_SEARCH_GAP) . "'"
            . " WHERE name = 'KSEF_HASH_SEARCH_MANUAL_NEXT' AND entity = " . (int) $conf->entity);
    }

    /**
     * @brief Find by hash
     * @param KsefSubmission $submission
     * @param User $user
     * @return int 1 found, -1 error
     */
    private function findSentByHash($submission, $user)
    {
        global $langs;
        $langs->load('ksef@ksef');
        $sent = (int) $submission->date_submission;
        if ($sent <= 0 || empty($submission->invoice_hash)) {
            return 0;
        }
        $offline = !empty($submission->offline_mode);
        $anchor = $sent;
        if ($offline) {
            $to = min(dol_now(), $sent + self::HASH_SEARCH_OFFLINE_DAYS * 86400);
            if ((int) $submission->date_last_check > $sent) {
                $anchor = (int) $submission->date_last_check;
                $to = min($to, $anchor + 86400);
            }
        } else {
            $to = min(dol_now(), $sent + 86400);
        }
        $sessions = array();
        $token = null;
        for ($page = 0; $page < self::HASH_SEARCH_LIST_PAGES; $page++) {
            $list = $this->client->listSessions(array(
                'sessionType' => 'Online',
                'dateCreatedFrom' => $sent - 3600,
                'dateCreatedTo' => $to,
                'pageSize' => 1000,
            ), $token);
            if ($list === false) {
                $this->error = (string) $this->client->error;
                $this->failureReason = 'gateway';
                return -1;
            }
            $sessions = array_merge($sessions, $list['sessions']);
            $token = $list['continuation_token'];
            if (empty($token)) {
                break;
            }
        }
        $cands = array();
        foreach ($sessions as $sess) {
            if (empty($sess['reference_number']) || ((int) $sess['total_count'] > 0 && (int) $sess['failed_count'] >= (int) $sess['total_count'])) {
                continue;
            }
            $ts = !empty($sess['date_created']) ? strtotime($sess['date_created']) : false;
            $cands[] = array('ref' => (string) $sess['reference_number'], 'dist' => $ts !== false ? abs($ts - $anchor) : PHP_INT_MAX);
        }
        usort($cands, function ($a, $b) {
            return $a['dist'] <=> $b['dist'];
        });
        $seen = $offline ? array() : array_flip($submission->getHashSearchNote()['seen']);
        $unread = array();
        $done = 0;
        foreach ($cands as $c) {
            if (isset($seen[$this->hashSearchSessionKey($c['ref'])])) {
                $done++;
            } else {
                $unread[] = $c;
            }
        }
        $budget = max(1, (int) $this->hashSearchSessions);
        $pagesRead = 0;
        $read = array();
        $cutShort = false;
        $matches = array();
        $live = array();
        $this->hashSearchListed = count($cands);
        foreach ($unread as $c) {
            if ($pagesRead >= $budget) {
                break;
            }
            $found = $this->client->findInvoiceInSession($c['ref'], $submission->invoice_hash, min(KsefClient::SESSION_INVOICE_PAGES, $budget - $pagesRead));
            if ($found === false) {
                $this->error = (string) $this->client->error;
                $this->failureReason = 'gateway';
                return -1;
            }
            $pagesRead += max(1, (int) ($this->client->last_session_pages ?? 1));
            if ($found === null && isset($this->client->last_session_complete) && !$this->client->last_session_complete) {
                $cutShort = true;
                continue;
            }
            $read[] = $c;
            if ($found === null || empty($found['reference_number'])) {
                continue;
            }
            $m = array('session' => $c['ref'], 'found' => $found);
            $matches[] = $m;
            if (($found['status'] ?? '') !== 'FAILED') {
                $live[] = $m;
            }
        }
        $this->hashSearchComplete = empty($token) && !$cutShort && count($unread) <= count($read);
        $this->hashSearchRead = $done + count($read);
        if (count($live) > 1) {
            $refs = array();
            foreach ($live as $m) {
                $refs[] = $m['session'];
            }
            $this->error = $langs->transnoentitiesnoconv('KSEF_StatusHashAmbiguous', count($live), implode(', ', $refs));
            $this->failureReason = 'ambiguous';
            $this->hashSearchMatches = count($live);
            dol_syslog("KSEF::findSentByHash row " . (int) $submission->rowid . " hash found in " . count($live) . " sessions (" . implode(', ', $refs) . "): not taken over", LOG_WARNING);
            return 0;
        }
        $pick = $live ? $live[0] : ($matches ? $matches[0] : null);
        if ($pick === null) {
            if (in_array($submission->status, array(KsefSubmission::STATUS_PENDING, KsefSubmission::STATUS_SUBMITTED), true) && !$submission->isStale()) {
                $this->hashNotFoundFresh = true;
                $this->error = $langs->transnoentitiesnoconv('KSEF_StatusNotFoundByHashFresh', max(10, (int) floor(ksefSendStaleAfter() / 60)));
            } elseif (!$this->hashSearchComplete) {
                $this->error = $langs->transnoentitiesnoconv('KSEF_StatusNotFoundByHashPartial', $this->hashSearchRead, dol_print_date($sent, 'dayhour'));
            } else {
                $this->error = $langs->transnoentitiesnoconv('KSEF_StatusNotFoundByHash', $this->hashSearchRead, dol_print_date($sent, 'dayhour'));
            }
            if (!$offline && !$this->hashNotFoundFresh && dol_now() - $sent > ksefSendStaleAfter()) {
                $this->rememberSessionsRead($submission, $read);
            }
            $this->failureReason = 'notfound';
            dol_syslog("KSEF::findSentByHash row " . (int) $submission->rowid . " hash not found in " . count($read) . " sessions, " . $this->hashSearchRead . " of " . count($cands) . " read so far"
                . ($this->hashSearchComplete ? '' : (empty($token) ? ', not all read' : ', listing not complete')) . ($this->hashNotFoundFresh ? ', sent within the waiting time' : ''), LOG_INFO);
            return 0;
        }
        $submission->ksef_session_reference = $pick['session'];
        $submission->ksef_reference = $pick['found']['reference_number'];
        if ($submission->status === KsefSubmission::STATUS_PENDING && !$offline) {
            $submission->status = KsefSubmission::STATUS_SUBMITTED;
        }
        if ($submission->update($user, 1, array(KsefSubmission::STATUS_PENDING, KsefSubmission::STATUS_SUBMITTED, KsefSubmission::STATUS_TIMEOUT)) <= 0) {
            $this->error = $submission->error ?: 'Could not store the KSeF references';
            $this->failureReason = 'record';
            dol_syslog("KSEF::findSentByHash row " . (int) $submission->rowid . " found in session " . $pick['session'] . ", references not saved: " . $this->error, LOG_ERR);
            return -1;
        }
        dol_syslog("KSEF::findSentByHash row " . (int) $submission->rowid . " found by its hash in session " . $pick['session'] . " (" . count($matches) . " match(es), " . count($read) . " sessions read)", LOG_INFO);
        return 1;
    }

    /**
     * @brief Retry failed submission
     * @param $invoice_id
     * @param $user
     * @return array
     */
    public function retrySubmission($invoice_id, $user)
    {
        try {
            $submission = new KsefSubmission($this->db);

            if ($submission->fetchByInvoice($invoice_id, ksefGetEnvironment()) <= 0) {
                $this->error = ksefMsg('KSEF_ErrNoSubmission');
                return array('status' => 'ERROR', 'error_code' => 'NO_SUBMISSION', 'error' => $this->error);
            }

            if ($submission->status == 'ACCEPTED' && $submission->noRetryKey() !== '') {
                return array(
                    'status' => 'ACCEPTED',
                    'ksef_number' => $submission->ksef_number,
                    'message' => 'Invoice already accepted'
                );
            }

            if (in_array($submission->status, array('PENDING', 'SUBMITTED'), true) && !$submission->isStale()) {
                return array(
                    'status' => 'PENDING',
                    'message' => 'Submission already in progress, please wait'
                );
            }

            $refusal = $this->productionIssuedRefusal((int) $invoice_id);
            if ($refusal !== null) {
                return $refusal;
            }

            if ($submission->isDuplicate()) {
                return $this->retryDuplicateAdoption($submission, $user);
            }

            if (!$submission->canRetry()) {
                global $langs;
                $langs->load('ksef@ksef');
                $this->error = $langs->transnoentitiesnoconv($submission->noRetryKey());
                return array('status' => 'ERROR', 'error_code' => 'NOT_RETRYABLE', 'error' => $this->error,
                    'not_retryable' => 1, 'submission_id' => $submission->rowid);
            }

            if (!empty($submission->offline_mode) && in_array($submission->status, array('OFFLINE', 'ACCEPTED', 'FAILED', 'PENDING'), true)) {
                return $this->retryOfflineWithStoredXML($submission, $user);
            }

            $invoice = new Facture($this->db);
            if ($invoice->fetch($invoice_id) <= 0) {
                throw new Exception(ksefMsg('KSEF_ErrInvoiceNotFound', $invoice_id));
            }

            $timing = $this->correctionTimingRefusal($invoice);
            if ($timing !== null) {
                return $timing;
            }
            $backdate_info = ksefDetectBackdating($invoice->date);
            $certOk = ksefIsOfflineCertificateConfigured();
            if ($backdate_info['is_backdated'] && !empty($certOk['valid'])) {
                return array(
                    'status' => 'NEEDS_OFFLINE_CONFIRMATION',
                    'backdate_info' => $backdate_info,
                    'deadline' => $backdate_info['deadline']
                );
            }

            return $this->submitInvoice($invoice_id, $user, 'SYNC');

        } catch (Throwable $e) {
            $this->error = $e->getMessage();
            return array('status' => 'ERROR', 'error' => $e->getMessage());
        }
    }

    /**
     * @brief Upload offline XML
     * @param KsefSubmission $submission
     * @param object $user
     * @return array
     */
    public function retryOfflineWithStoredXML($submission, $user)
    {
        global $conf;

        $this->pdfWarning = '';
        $refusal = $this->productionIssuedRefusal((int) $submission->fk_facture, (string) $submission->environment);
        if ($refusal !== null) {
            return $refusal;
        }
        if ((string) $submission->environment === ksefGetEnvironment()) {
            $kor = new Facture($this->db);
            if ($kor->fetch((int) $submission->fk_facture) > 0) {
                $corr = ksefCorrectionSourceRef($this->db, $kor);
                if ($corr['state'] === 'awaiting_number') {
                    return $this->correctionTimingRefusal($kor, $corr) + array('submission_id' => $submission->rowid, 'offline_mode' => $submission->offline_mode, 'deferred' => 1);
                }
            }
        }
        $fa3_xml = $submission->fa3_xml;
        $stored_hash = $submission->invoice_hash;

        if (empty($fa3_xml) || empty($stored_hash)) {
            $this->error = 'No stored XML or hash found in submission';
            return array(
                'status' => 'FAILED',
                'error' => $this->error
            );
        }

        $statusBefore = (string) $submission->status;
        try {
            if (!empty($submission->ksef_reference) && $submission->getSessionReference() !== '') {
                $known = $this->refreshSubmissionStatus($submission, $user);
                if ($known && $submission->status === 'ACCEPTED') {
                    return array('status' => 'ACCEPTED', 'ksef_number' => $submission->ksef_number, 'invoice_hash' => $stored_hash,
                        'submission_id' => $submission->rowid, 'offline_mode' => $submission->offline_mode, 'reused_xml' => true);
                }
                if ($known && $submission->status === 'REJECTED') {
                    $this->error = (string) $submission->error_message;
                    return array('status' => 'FAILED', 'rejected_offline' => 1, 'failure_kind' => 'rejected', 'error' => $this->error,
                        'error_code' => $submission->error_code, 'submission_id' => $submission->rowid, 'offline_mode' => $submission->offline_mode,
                        'invoice_hash' => $stored_hash, 'reused_xml' => true, 'can_use_offline' => false);
                }
                if ($known && ($known['status'] ?? '') === 'PENDING') {
                    $this->error = 'KSeF is still processing the previous upload';
                    return array('status' => 'TIMEOUT', 'in_progress' => 1, 'error' => $this->error, 'submission_id' => $submission->rowid,
                        'offline_mode' => $submission->offline_mode, 'reused_xml' => true);
                }
            }

            $statusBefore = (string) $submission->status;
            if (!$this->claimOfflineRow($submission)) {
                return $this->offlineClaimRefused($submission, $stored_hash);
            }
            $restoreStatus = $statusBefore === KsefSubmission::STATUS_PENDING ? KsefSubmission::STATUS_OFFLINE : $statusBefore;

            $sendOptions = array('invoice_hash' => $stored_hash);
            if (!empty($submission->original_invoice_hash)) {
                $sendOptions['offline_mode'] = true;
                $sendOptions['corrected_hash'] = $submission->original_invoice_hash;
            }
            $sent = $this->sendWithRecord($submission, $fa3_xml, $sendOptions, $user);
            $api_result = $sent['api'];

            if ($sent['outcome'] === 'NOT_RECORDED') {
                return $this->acceptedButNotRecorded($submission, (int) $submission->fk_facture, $user, $sent['accepted']);
            }
            if ($sent['outcome'] === 'ACCEPTED') {
                return array(
                    'status' => 'ACCEPTED',
                    'ksef_number' => $submission->ksef_number,
                    'invoice_hash' => $stored_hash,
                    'submission_date' => dol_now(),
                    'submission_id' => $submission->rowid,
                    'offline_mode' => $submission->offline_mode,
                    'reused_xml' => true,
                    'adopted' => !empty($sent['adopted']) ? 1 : 0
                ) + $this->pdfWarningResult();
            }
            if ($sent['outcome'] === 'TIMEOUT' || $sent['outcome'] === 'MOVED') {
                return $this->resultOfRow($submission, $sent) + array('offline_mode' => $submission->offline_mode, 'reused_xml' => true);
            }
            $error_msg = (is_array($api_result) ? ($api_result['error'] ?? null) : null) ?? $this->client->error ?? 'Unknown error';
            $submission->error_message = $error_msg;
            if (!empty($this->client->last_error_code)) {
                $submission->error_code = $this->client->last_error_code;
            }
            if (!empty($sent['error_details'])) {
                $submission->error_details = json_encode($sent['error_details']);
            }
            $kind = $this->failureKindNow('send');
            $submission->failure_kind = $kind;
            $rejected = $kind === 'rejected' && (!empty($submission->ksef_reference) || (int) $submission->error_code === 440);
            if ($rejected) {
                $submission->status = 'REJECTED';
            } else {
                $submission->status = $restoreStatus;
            }
            if ($submission->update($user, 1, $sent['allowed']) < 0) {
                dol_syslog("KSEF::retryOfflineWithStoredXML error not saved on row " . $submission->rowid . ": " . $submission->error, LOG_ERR);
            }

            return array(
                'status' => 'FAILED',
                'error' => $error_msg,
                'error_code' => $submission->error_code,
                'invoice_hash' => $stored_hash,
                'submission_id' => $submission->rowid,
                'offline_mode' => $submission->offline_mode,
                'reused_xml' => true,
                'failure_kind' => $rejected ? 'rejected' : $kind,
                'rejected_offline' => $rejected ? 1 : 0,
                'can_use_offline' => false
            );

        } catch (Throwable $e) {
            $this->error = $e->getMessage();
            $this->errors[] = $e->getMessage();
            dol_syslog("KSEF::retryOfflineWithStoredXML ERROR: " . get_class($e) . ": " . $e->getMessage(), LOG_ERR);

            if (isset($restoreStatus) && $submission->status === KsefSubmission::STATUS_PENDING) {
                $submission->status = $restoreStatus;
            }
            $submission->error_message = $e->getMessage();
            if ($submission->update($user, 1, array(KsefSubmission::STATUS_PENDING, $statusBefore)) <= 0) {
                dol_syslog("KSEF::retryOfflineWithStoredXML error not saved on row " . $submission->rowid . ": " . $submission->error, LOG_ERR);
            }

            return array(
                'status' => 'FAILED',
                'error' => $e->getMessage(),
                'invoice_hash' => $stored_hash,
                'submission_id' => $submission->rowid,
                'offline_mode' => $submission->offline_mode,
                'reused_xml' => true
            );
        }
    }

    /**
     * @brief Claim offline row
     * @param KsefSubmission $submission
     * @return bool
     */
    private function claimOfflineRow($submission)
    {
        $now = dol_now();
        $status = (string) $submission->status;
        $sql = "UPDATE " . MAIN_DB_PREFIX . "ksef_submissions SET status = '" . KsefSubmission::STATUS_PENDING . "', date_last_check = " . (int) $now
            . " WHERE rowid = " . (int) $submission->rowid . " AND status = '" . $this->db->escape($status) . "'";
        if ($status === KsefSubmission::STATUS_PENDING) {
            $sql .= " AND COALESCE(date_last_check, date_submission, 0) < " . (int) ($now - ksefSendStaleAfter());
        }
        $res = $this->db->query($sql);
        if (!$res || $this->db->affected_rows($res) != 1) {
            dol_syslog("KSEF::claimOfflineRow row " . (int) $submission->rowid . " (" . $status . ") not claimed: another upload holds it or it moved on", LOG_WARNING);
            return false;
        }
        $submission->status = KsefSubmission::STATUS_PENDING;
        $submission->date_last_check = $now;
        return true;
    }

    /**
     * @brief Offline claim refused
     * @param KsefSubmission $submission
     * @param string $stored_hash
     * @return array
     */
    private function offlineClaimRefused($submission, $stored_hash)
    {
        $now = new KsefSubmission($this->db);
        if ($now->fetch((int) $submission->rowid) > 0 && $now->status === KsefSubmission::STATUS_ACCEPTED && ksefIsKsefNumber((string) $now->ksef_number)) {
            return array('status' => 'ACCEPTED', 'ksef_number' => $now->ksef_number, 'invoice_hash' => $stored_hash,
                'submission_id' => $now->rowid, 'offline_mode' => $now->offline_mode, 'reused_xml' => true);
        }
        $this->error = 'Another upload of this offline invoice is in progress';
        return array('status' => 'TIMEOUT', 'in_progress' => 1, 'error' => $this->error, 'submission_id' => $submission->rowid,
            'offline_mode' => $submission->offline_mode, 'reused_xml' => true);
    }

    /**
     * @brief Download invoice UPO
     * @param $invoice_id
     * @param $user
     * @return string|false
     */
    public function downloadUPO($invoice_id, $user)
    {
        $submission = new KsefSubmission($this->db);
        if ($submission->fetchByInvoice($invoice_id, ksefGetEnvironment()) <= 0) {
            $this->error = ksefMsg('KSEF_ErrNoSubmission');
            dol_syslog("KSEF::downloadUPO ERROR: " . $this->error, LOG_ERR);
            return false;
        }
        return $this->downloadUPOForSubmission($submission, $user);
    }

    /**
     * @brief Download submission UPO
     * @param KsefSubmission $submission
     * @param $user
     * @return string|false
     */
    public function downloadUPOForSubmission($submission, $user)
    {
        try {
            if ($submission->status !== 'ACCEPTED') throw new Exception(ksefMsg('KSEF_ErrUpoNotAccepted'));
            if (empty($submission->ksef_number)) throw new Exception(ksefMsg('KSEF_ErrUpoNoNumber'));

            if (!empty($submission->upo_xml)) return $submission->upo_xml;

            $same = $this->upoOfSameNumber($submission);
            if ($same !== '') {
                $submission->upo_xml = $same;
                $submission->update($user, 0, array(KsefSubmission::STATUS_ACCEPTED));
                return $same;
            }

            $sessionRef = $submission->getSessionReference();

            if (empty($sessionRef)) {
                throw new Exception(ksefMsg('KSEF_UPOUnavailableNoSession'));
            }

            $upo_xml = $this->client->downloadUPO($submission->ksef_number, $sessionRef);

            if ($upo_xml === false) throw new Exception(ksefMsg('KSEF_ErrUpoDownload', $this->client->error));

            $submission->upo_xml = $upo_xml;
            if ($submission->update($user, 0, array(KsefSubmission::STATUS_ACCEPTED)) > 0) {
                $this->shareUpoWithSameNumber($submission);
            }

            return $upo_xml;

        } catch (Exception $e) {
            $this->error = $e->getMessage();
            dol_syslog("KSEF::downloadUPO ERROR: " . $e->getMessage(), LOG_ERR);
            return false;
        }
    }

    /**
     * @brief Same-number UPO
     * @param KsefSubmission $submission
     * @return string '' when none
     */
    private function upoOfSameNumber($submission)
    {
        if (empty($submission->ksef_number)) {
            return '';
        }
        $res = $this->db->query("SELECT upo_xml FROM " . MAIN_DB_PREFIX . "ksef_submissions"
            . " WHERE ksef_number = '" . $this->db->escape((string) $submission->ksef_number) . "'"
            . " AND environment = '" . $this->db->escape((string) $submission->environment) . "'"
            . " AND status = 'ACCEPTED' AND upo_xml IS NOT NULL AND upo_xml <> '' AND rowid <> " . (int) $submission->rowid
            . " ORDER BY rowid ASC" . $this->db->plimit(1));
        $o = $res ? $this->db->fetch_object($res) : null;
        return $o ? (string) $o->upo_xml : '';
    }

    /**
     * @brief Share number UPO
     * @param KsefSubmission $submission
     * @return int Rows filled
     */
    private function shareUpoWithSameNumber($submission)
    {
        if (empty($submission->upo_xml) || empty($submission->ksef_number) || (int) $submission->rowid <= 0) {
            return 0;
        }
        $res = $this->db->query("UPDATE " . MAIN_DB_PREFIX . "ksef_submissions SET upo_xml = '" . $this->db->escape((string) $submission->upo_xml) . "'"
            . " WHERE ksef_number = '" . $this->db->escape((string) $submission->ksef_number) . "'"
            . " AND environment = '" . $this->db->escape((string) $submission->environment) . "'"
            . " AND status = 'ACCEPTED' AND (upo_xml IS NULL OR upo_xml = '') AND rowid <> " . (int) $submission->rowid);
        $n = $res ? (int) $this->db->affected_rows($res) : 0;
        if ($n > 0) {
            dol_syslog("KSEF::shareUpoWithSameNumber UPO of " . $submission->ksef_number . " stored on " . $n . " other row(s) of the same number", LOG_INFO);
        }
        return $n;
    }

    /**
     * @brief Fill missing UPOs
     * @param int $limit Max rows
     * @return int Rows filled
     */
    private function fillUposFromSameNumber($limit = 200)
    {
        $env = $this->db->escape(ksefGetEnvironment());
        $res = $this->db->query("SELECT DISTINCT s.rowid, s.ksef_number, s.environment FROM " . MAIN_DB_PREFIX . "ksef_submissions s"
            . " INNER JOIN " . MAIN_DB_PREFIX . "ksef_submissions o ON o.ksef_number = s.ksef_number AND o.environment = s.environment AND o.rowid <> s.rowid"
            . " AND o.status = 'ACCEPTED' AND o.upo_xml IS NOT NULL AND o.upo_xml <> ''"
            . " WHERE s.status = 'ACCEPTED' AND (s.upo_xml IS NULL OR s.upo_xml = '') AND s.ksef_number IS NOT NULL AND s.environment = '" . $env . "'"
            . " ORDER BY s.rowid ASC" . $this->db->plimit(max(1, (int) $limit)));
        if (!$res) {
            return 0;
        }
        $rows = array();
        while ($o = $this->db->fetch_object($res)) {
            $rows[] = $o;
        }
        $this->db->free($res);
        $n = 0;
        foreach ($rows as $o) {
            $probe = new KsefSubmission($this->db);
            $probe->rowid = (int) $o->rowid;
            $probe->ksef_number = (string) $o->ksef_number;
            $probe->environment = (string) $o->environment;
            $upo = $this->upoOfSameNumber($probe);
            if ($upo === '') {
                continue;
            }
            $u = $this->db->query("UPDATE " . MAIN_DB_PREFIX . "ksef_submissions SET upo_xml = '" . $this->db->escape($upo) . "'"
                . " WHERE rowid = " . (int) $o->rowid . " AND status = 'ACCEPTED' AND (upo_xml IS NULL OR upo_xml = '')");
            if ($u && $this->db->affected_rows($u) > 0) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * @brief UPO for download
     * @param KsefSubmission $submission
     * @param User $user
     * @return string '' when unavailable
     */
    public function upoForDownload($submission, $user)
    {
        global $langs;
        $langs->load('ksef@ksef');
        $this->error = '';
        if (!empty($submission->upo_xml)) {
            return (string) $submission->upo_xml;
        }
        if (empty($submission->ksef_number) || $submission->status != 'ACCEPTED') {
            return '';
        }
        if ($submission->getSessionReference() !== '') {
            return ($this->downloadUPOForSubmission($submission, $user) !== false && !empty($submission->upo_xml)) ? (string) $submission->upo_xml : '';
        }
        // no search right
        $same = $this->upoOfSameNumber($submission);
        if ($same !== '') {
            $submission->upo_xml = $same;
            $submission->update($user, 1, array('ACCEPTED'));
            return $same;
        }
        if (!ksefUserCan($user, 'search_by_hash')) {
            $this->error = $langs->transnoentitiesnoconv('KSEF_UPOSearchNoRight', $langs->transnoentitiesnoconv('KSEF_RightNameKsefWrite'));
            return '';
        }
        $this->manualHashSearch = true;
        try {
            return ($this->recoverUPO($submission, $user) > 0 && !empty($submission->upo_xml)) ? (string) $submission->upo_xml : '';
        } finally {
            $this->manualHashSearch = false;
            $this->manualSearchRelease();
        }
    }

    /**
     * @brief Recover missing UPO
     * @param KsefSubmission $submission
     * @param User $user
     * @return int 0 not found, -1 error
     */
    public function recoverUPO($submission, $user)
    {
        global $langs;
        $langs->load('ksef@ksef');
        if ($submission->status !== 'ACCEPTED' || empty($submission->ksef_number)) {
            $this->error = $langs->transnoentitiesnoconv('KSEF_UPORecoverNotAccepted');
            return -1;
        }
        if (!empty($submission->upo_xml)) {
            return 1;
        }
        if ($submission->getSessionReference() !== '') {
            return $this->downloadUPOForSubmission($submission, $user) === false ? -1 : 1;
        }
        $same = $this->upoOfSameNumber($submission);
        if ($same !== '') {
            $submission->upo_xml = $same;
            if ($submission->update($user, 1, array('ACCEPTED')) <= 0) {
                $this->error = $submission->error ?: 'Update failed';
                return -1;
            }
            return 1;
        }
        if ((string) $submission->environment !== '' && $submission->environment !== ksefGetEnvironment()) {
            $this->error = $langs->transnoentitiesnoconv('KSEF_UPORecoverOtherEnvironment', $submission->environment);
            return -1;
        }

        dol_include_once('/ksef/class/ksef_session_cache.class.php');
        $cache = new KsefSessionCache($this->db);
        $cachedRef = $cache->sessionFor($submission->ksef_number, ksefGetEnvironment());
        if ($cachedRef !== '') {
            $upo = $this->client->downloadUPO($submission->ksef_number, $cachedRef);
            if ($upo !== false && $upo !== '') {
                $submission->upo_xml = $upo;
                $submission->ksef_session_reference = $cachedRef;
                if ($submission->update($user, 1, array('ACCEPTED')) <= 0) {
                    $this->error = $submission->error ?: 'Update failed';
                    return -1;
                }
                dol_syslog("KSEF::recoverUPO row " . (int) $submission->rowid . " UPO found in cached session " . $cachedRef, LOG_INFO);
                $this->shareUpoWithSameNumber($submission);
                return 1;
            }
            if ((int) $this->client->last_http_code === 429) {
                $this->error = $langs->transnoentitiesnoconv('KSEF_UPORecoverRateLimited');
                return -1;
            }
        }

        $day = 0;
        if (preg_match('/^\d{10}-(\d{4})(\d{2})(\d{2})-/', (string) $submission->ksef_number, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $tz = new DateTimeZone('Europe/Warsaw');
            $day = (new DateTime($m[1] . '-' . $m[2] . '-' . $m[3] . ' 00:00:00', $tz))->getTimestamp();
        } else {
            $ref = (int) ($submission->date_acceptance ?: $submission->date_submission);
            $day = $ref > 0 ? $ref - ($ref % 86400) : 0;
        }
        if ($day <= 0) {
            $this->error = $langs->transnoentitiesnoconv('KSEF_UPORecoverNoDate');
            return -1;
        }
        if ($this->manualHashSearch && !$this->manualSearchClaim()) {
            return -1;
        }

        $list = $this->client->listSessions(array(
            'sessionType' => 'Online',
            'dateCreatedFrom' => $day - 86400,
            'dateCreatedTo' => $day + 86400,
            'pageSize' => 1000,
        ));
        if ($list === false) {
            $this->error = (int) $this->client->last_http_code === 429
                ? $langs->transnoentitiesnoconv('KSEF_UPORecoverRateLimited')
                : $langs->transnoentitiesnoconv('KSEF_UPORecoverListFailed', (string) $this->client->error);
            return -1;
        }

        $cands = array();
        foreach ($list['sessions'] as $sess) {
            if (empty($sess['reference_number']) || (isset($sess['successful_count']) && (int) $sess['successful_count'] === 0 && (int) $sess['total_count'] > 0)) {
                continue;
            }
            $ts = !empty($sess['date_created']) ? strtotime($sess['date_created']) : false;
            $cands[] = array('ref' => (string) $sess['reference_number'], 'dist' => $ts !== false ? abs($ts - ($day + 43200)) : PHP_INT_MAX);
        }
        usort($cands, function ($a, $b) {
            return $a['dist'] <=> $b['dist'];
        });
        $cands = array_slice($cands, 0, 50);
        foreach ($cands as $c) {
            $upo = $this->client->downloadUPO($submission->ksef_number, $c['ref']);
            if ($upo !== false && $upo !== '') {
                $submission->upo_xml = $upo;
                $submission->ksef_session_reference = $c['ref'];
                if ($submission->update($user, 1, array('ACCEPTED')) <= 0) {
                    $this->error = $submission->error ?: 'Update failed';
                    return -1;
                }
                dol_syslog("KSEF::recoverUPO row " . (int) $submission->rowid . " UPO found in session " . $c['ref'], LOG_INFO);
                $this->shareUpoWithSameNumber($submission);
                return 1;
            }
            if ((int) $this->client->last_http_code === 429) {
                $this->error = $langs->transnoentitiesnoconv('KSEF_UPORecoverRateLimited');
                return -1;
            }
        }
        $this->error = $langs->transnoentitiesnoconv('KSEF_UPORecoverNotFound', count($cands), dol_print_date($day, 'day'))
            . (!empty($list['continuation_token']) ? ' ' . $langs->transnoentitiesnoconv('KSEF_UPORecoverMoreSessions') : '');
        return 0;
    }

    /**
     * @brief Submit technical correction
     * @param $invoice_id
     * @param $original_submission_id Rejected row
     * @param $user
     * @return array
     */
    public function submitTechnicalCorrection($invoice_id, $original_submission_id, $user)
    {
        global $conf;

        try {
            $invoice = new Facture($this->db);
            if ($invoice->fetch($invoice_id) <= 0) {
                throw new Exception(ksefMsg('KSEF_ErrInvoiceNotFound', $invoice_id));
            }
            $this->pdfWarning = '';
            $original = new KsefSubmission($this->db);
            if ($original->fetch($original_submission_id) <= 0) {
                throw new Exception(ksefMsg('KSEF_ErrTechCorrNoOriginal'));
            }
            $notSubmittable = $this->checkInvoiceSubmittable($invoice, $original);
            if ($notSubmittable !== '') {
                $this->error = $notSubmittable;
                return array('status' => 'FAILED', 'error_code' => $this->notSubmittableCode, 'error' => $notSubmittable);
            }
            if ((int) $original->fk_facture !== (int) $invoice_id) {
                throw new Exception(ksefMsg('KSEF_ErrTechCorrOtherInvoice'));
            }

            if (!in_array($original->status, array('REJECTED', 'FAILED'))) {
                throw new Exception(ksefMsg('KSEF_ErrTechCorrStatus'));
            }

            if (empty($original->invoice_hash)) {
                throw new Exception(ksefMsg('KSEF_ErrTechCorrNoHash'));
            }

            if (empty($original->offline_mode)) {
                throw new Exception(ksefMsg('KSEF_ErrTechCorrNotOffline'));
            }

            $latest = new KsefSubmission($this->db);
            if ($latest->fetchByInvoice($invoice_id, ksefGetEnvironment()) <= 0 || (int) $latest->rowid !== (int) $original->rowid) {
                throw new Exception(ksefMsg('KSEF_ErrTechCorrNotLatest'));
            }
            $rootHash = !empty($original->original_invoice_hash) ? $original->original_invoice_hash : $original->invoice_hash;

            $new_xml = $this->builder->buildFromInvoice($invoice_id, array('offline_mode' => true, 'original_creation_date' => $original->fa3_creation_date));
            if (!$new_xml) {
                throw new Exception(ksefMsg('KSEF_ErrBuildXml', $this->builder->error));
            }
            $schemaRefusal = $this->schemaRefusal($invoice, $new_xml);
            if ($schemaRefusal !== null) {
                return $schemaRefusal;
            }

            $new_hash = $this->builder->getLastXmlHash();

            $submission = new KsefSubmission($this->db);
            $submission->fk_facture = $invoice_id;
            $submission->fa3_xml = $new_xml;
            $submission->fa3_creation_date = $this->builder->getLastCreationDate();
            $submission->invoice_hash = $new_hash;
            $submission->invoice_type = $this->builder->getLastInvoiceType();
            $submission->original_invoice_hash = $rootHash;
            $submission->offline_mode = $original->offline_mode;
            $submission->offline_deadline = $original->offline_deadline;
            $submission->offline_detected_reason = 'Technical correction of submission #' . $original_submission_id;
            $submission->status = 'PENDING';
            $submission->environment = ksefGetEnvironment();
            $submission->date_submission = dol_now();
            $submission->source = KsefSubmission::SOURCE_SENT_OFFLINE;
            $saved = $this->saveSendRow($submission, $user, (int) $original->rowid, (string) $original->status);
            if ($saved === 0) {
                return $this->sendRowMoved($invoice_id) + array('original_submission_id' => $original_submission_id);
            }
            if ($saved < 0) {
                dol_syslog("KSEF::submitTechnicalCorrection row not saved for invoice $invoice_id, nothing sent: " . $submission->error, LOG_ERR);
                $this->error = ksefDbErrorForUser($user, 'KSEF_SubmissionNotRecorded', $submission->error);
                return array('status' => 'ERROR', 'error_code' => 'RECORD_FAILED', 'record_failed' => true, 'error' => $this->error);
            }

            $sent = $this->sendWithRecord($submission, $new_xml, array(
                'offline_mode' => true,
                'invoice_hash' => $new_hash,
                'corrected_hash' => $rootHash
            ), $user);
            $api_result = $sent['api'];

            if ($sent['outcome'] === 'NOT_RECORDED') {
                return $this->acceptedButNotRecorded($submission, $invoice_id, $user, $sent['accepted']);
            }
            if ($sent['outcome'] === 'FAILED') {
                $kind = $this->failureKindNow('send');
                $submission->failure_kind = $kind;
                $submission->status = $kind === 'rejected' ? 'REJECTED' : 'OFFLINE';
                $submission->error_message = $this->client->error ?: 'Technical correction submission failed';
                $submission->error_code = $this->client->last_error_code ?? null;
                if (!empty($sent['error_details'])) {
                    $submission->error_details = json_encode($sent['error_details']);
                } elseif (!empty($this->client->last_error_details)) {
                    $submission->error_details = json_encode($this->client->last_error_details);
                }
                $submission->api_response = json_encode($api_result);
                $saved = $submission->update($user, 1, $sent['allowed']);
                if ($saved === 0) {
                    return $this->resultOfRow($submission, array('outcome' => 'MOVED')) + array('original_submission_id' => $original_submission_id, 'new_submission_id' => $submission->rowid);
                }
                if ($saved < 0) {
                    dol_syslog("KSEF::submitTechnicalCorrection FAILED result not saved on row " . $submission->rowid . ": " . $submission->error, LOG_ERR);
                }
                if ($submission->status === 'OFFLINE') {
                    $this->error = $submission->error_message;
                    return array('status' => 'OFFLINE', 'error' => $this->error, 'submission_id' => $submission->rowid,
                        'original_submission_id' => $original_submission_id, 'new_submission_id' => $submission->rowid);
                }
                throw new Exception($submission->error_message);
            }
            if ($sent['outcome'] !== 'ACCEPTED') {
                $r = $this->resultOfRow($submission, $sent);
                return $r + array('original_submission_id' => $original_submission_id, 'new_submission_id' => $submission->rowid);
            }

            return array(
                'status' => 'ACCEPTED',
                'ksef_number' => $submission->ksef_number,
                'original_submission_id' => $original_submission_id,
                'new_submission_id' => $submission->rowid
            ) + $this->pdfWarningResult();

        } catch (Throwable $e) {
            $this->error = $e->getMessage();
            dol_syslog("KSEF::submitTechnicalCorrection ERROR: " . $e->getMessage(), LOG_ERR);
            return array('status' => 'ERROR', 'error' => $e->getMessage());
        }
    }

    /**
     * @brief Normalize sync direction
     * @param string $direction
     * @return string
     */
    public static function syncDirection($direction)
    {
        return $direction === self::SYNC_OUTGOING ? self::SYNC_OUTGOING : self::SYNC_INCOMING;
    }

    /**
     * @brief Download cited original
     * @param KsefIncoming $kor Correction
     * @param string $ksefNumber Cited number
     * @param User $user
     * @return int Record id, <0 error
     */
    public function downloadCitedOriginal($kor, $ksefNumber, $user)
    {
        global $langs;
        $langs->load('ksef@ksef');
        dol_include_once('/ksef/class/fa3_parser.class.php');
        $ksefNumber = trim((string) $ksefNumber);
        $cited = false;
        foreach ($kor->citedInvoices() as $c) {
            $cited = $cited || ($ksefNumber !== '' && $c['ksef'] === $ksefNumber);
        }
        if (!$cited) {
            $this->error = $langs->transnoentitiesnoconv('KSEF_OriginalNotCited', $ksefNumber);
            return -1;
        }
        $direction = ($kor instanceof KsefSales) ? self::SYNC_OUTGOING : self::SYNC_INCOMING;
        $recordClass = self::syncRecordClass($direction);
        $rec = new $recordClass($this->db);
        if ($rec->fetch(0, $ksefNumber) > 0) {
            return (int) $rec->id;
        }
        $environment = (string) $kor->environment !== '' ? (string) $kor->environment : ksefGetEnvironment();
        $client = new KsefClient($this->db, $environment);
        try {
            $dl = $client->downloadInvoiceXml($ksefNumber);
        } catch (Exception $e) {
            $this->error = $langs->transnoentitiesnoconv('KSEF_OriginalDownloadFailed', $ksefNumber, $e->getMessage());
            return -1;
        }
        if (!$dl) {
            $code = (int) $client->last_http_code;
            if ((int) $client->last_error_code === 21164 || $code === 404) {
                $this->error = $langs->transnoentitiesnoconv('KSEF_OriginalNotFoundInKsef', $ksefNumber);
            } elseif ($code === 403) {
                $this->error = $langs->transnoentitiesnoconv('KSEF_OriginalNoPermission', $ksefNumber);
            } else {
                $this->error = $langs->transnoentitiesnoconv('KSEF_OriginalDownloadFailed', $ksefNumber, ksefFetchErrorMessage($code, (int) $client->retry_after_seconds));
            }
            return -1;
        }
        $parser = new FA3Parser($this->db);
        $parsed = $parser->parse($dl['xml']);
        $seller = $parsed ? ksefCleanNIP((string) ($parsed['seller']['nip'] ?? '')) : '';
        if ($parsed && $seller !== ksefCleanNIP((string) $kor->seller_nip)) {
            $this->error = $langs->transnoentitiesnoconv('KSEF_OriginalOtherSeller', $ksefNumber, $seller, (string) $kor->seller_name);
            return -1;
        }
        $this->processIncomingPackage(array('invoices' => array($ksefNumber => $dl['xml'])), $user, $environment, $direction);
        $rec = new $recordClass($this->db);
        if ($rec->fetch(0, $ksefNumber) <= 0) {
            $this->error = $langs->transnoentitiesnoconv('KSEF_OriginalDownloadFailed', $ksefNumber, $this->db->lasterror());
            return -1;
        }
        return (int) $rec->id;
    }

    /**
     * @brief Direction record class
     * @param string $direction
     * @return string Class name
     */
    public static function syncRecordClass($direction)
    {
        if (self::syncDirection($direction) === self::SYNC_OUTGOING) {
            dol_include_once('/ksef/class/ksef_sales.class.php');
            return 'KsefSales';
        }
        dol_include_once('/ksef/class/ksef_incoming.class.php');
        return 'KsefIncoming';
    }

    /**
     * @brief Start KSeF export
     * @param User $user
     * @param string $direction
     * @return array|false
     */
    public function initIncomingFetch($user, $direction = 'incoming')
    {
        $direction = self::syncDirection($direction);
        dol_include_once('/ksef/class/ksef_sync_state.class.php');
        dol_include_once('/ksef/class/ksef_client.class.php');

        dol_include_once('/ksef/class/ksef_latarnia.class.php');
        $latarnia = new KsefLatarnia($this->db);
        $latarnia->refreshIfStale();

        $environment = ksefGetEnvironment();

        $syncState = new KsefSyncState($this->db);
        $syncState->load($direction);
        $this->ensureIncomingSyncEnvironment($syncState, $environment);

        if ($syncState->isFetchInProgress()) {
            if ($syncState->isFetchTimedOut()) {
                dol_syslog("KSeF: Previous fetch timed out, clearing state", LOG_WARNING);
                $syncState->clearFetchState();
            } else {
                return array(
                    'status' => 'ALREADY_PROCESSING',
                    'reference' => $syncState->fetch_reference,
                    'started' => $syncState->fetch_started
                );
            }
        }

        // export budget per-NIP
        $other = new KsefSyncState($this->db);
        $other->load($direction === self::SYNC_OUTGOING ? self::SYNC_INCOMING : self::SYNC_OUTGOING);
        $limited = $syncState->isRateLimited() ? $syncState : ($other->isRateLimited() ? $other : null);
        if ($limited) {
            $this->error = 'Rate limited until ' . $limited->getRateLimitExpiryFormatted();
            $this->last_http_code = 429;
            $this->last_retry_after = (int) $limited->secondsUntilNextSync();
            return false;
        }

        $client = $this->syncClient($environment);

        $encryptionData = $client->generateEncryptionData();
        if (!$encryptionData) {
            $this->error = $client->error;
            $this->last_http_code = (int) $client->last_http_code;
            $this->last_retry_after = (int) $client->retry_after_seconds;
            $this->noteSyncRateLimit($syncState, $client);
            $syncState->noteStartFailure($this->last_http_code);
            return false;
        }

        $fromDate = !empty($syncState->hwm_date) ? $syncState->hwm_date : KsefSyncState::DEFAULT_HWM;

        $maxChunkDays = 60;
        $fromTs = strtotime($fromDate);
        $toDate = null;

        if ($fromTs !== false) {
            $chunkEndTs = strtotime("+{$maxChunkDays} days", $fromTs);
            if ($chunkEndTs < time()) {
                $toDate = date('c', $chunkEndTs);
                dol_syslog("KSeF: Date range exceeds {$maxChunkDays} days, chunking to: " . $toDate, LOG_INFO);
            }
        }

        dol_syslog("KSeF: Initiating " . $direction . " fetch from: " . $fromDate . ($toDate ? " to: " . $toDate : ''), LOG_INFO);

        $subject = $direction === self::SYNC_OUTGOING ? 'subject1' : 'subject2';
        $initResult = $client->initHwmExport($fromDate, $subject, $encryptionData['encryption_info'], $toDate);
        if (!$initResult) {
            $this->error = $client->error;
            $this->last_http_code = (int) $client->last_http_code;
            $this->last_retry_after = (int) $client->retry_after_seconds;
            $this->noteSyncRateLimit($syncState, $client);
            $syncState->noteStartFailure($this->last_http_code);
            return false;
        }

        $syncState->last_fail = '';
        $syncState->fetch_reference = $initResult['referenceNumber'];
        $syncState->fetch_status = KsefSyncState::FETCH_STATUS_PROCESSING;
        $syncState->fetch_started = dol_now();
        $syncState->fetch_key = dol_encode(base64_encode($encryptionData['aes_key']));
        $syncState->fetch_iv = dol_encode(base64_encode($encryptionData['iv']));
        $syncState->fetch_error = '';
        $syncState->save();

        dol_syslog("KSeF: Initiated fetch, reference: " . $initResult['referenceNumber'], LOG_INFO);

        return array(
            'status' => 'INITIATED',
            'reference' => $initResult['referenceNumber']
        );
    }

    /**
     * @brief Check fetch status
     * @param User $user
     * @param string $direction
     * @return array
     */
    public function checkIncomingFetchStatus($user, $direction = 'incoming')
    {
        dol_include_once('/ksef/class/ksef_sync_state.class.php');
        dol_include_once('/ksef/class/ksef_client.class.php');
        dol_include_once('/ksef/class/ksef_incoming.class.php');

        $direction = self::syncDirection($direction);
        $environment = ksefGetEnvironment();

        $syncState = new KsefSyncState($this->db);
        $syncState->load($direction);
        if ($this->ensureIncomingSyncEnvironment($syncState, $environment)) {
            return array('status' => 'NO_PENDING_FETCH');
        }

        if ($syncState->isProcessingInProgress()) {
            return $this->continueProcessingBatch($syncState, $user, $environment);
        }

        if (!$syncState->isFetchInProgress()) {
            if (!empty($syncState->last_sync) && (int) $syncState->last_sync >= dol_now() - 120 && empty($syncState->fetch_error)) {
                return array(
                    'status' => 'COMPLETED',
                    'new' => (int) $syncState->last_sync_new,
                    'existing' => (int) $syncState->last_sync_existing,
                    'total' => (int) $syncState->last_sync_total,
                    'fetch_errors' => (int) $syncState->last_sync_fetch_errors,
                    'unsupported' => (int) $syncState->last_sync_unsupported,
                    'recent' => true,
                );
            }
            return array('status' => 'NO_PENDING_FETCH');
        }

        if ($syncState->fetch_status === KsefSyncState::FETCH_STATUS_READY_TO_PROCESS) {
            return array(
                'status' => 'READY_TO_PROCESS',
                'reference' => $syncState->fetch_reference,
                'invoice_count' => $syncState->process_total,
            );
        }

        if ($syncState->isFetchTimedOut()) {
            $syncState->fetch_status = KsefSyncState::FETCH_STATUS_TIMEOUT;
            $syncState->fetch_error = 'Fetch request timed out';
            $syncState->save();
            return array('status' => 'TIMEOUT', 'reference' => $syncState->fetch_reference);
        }

        if ($syncState->fetch_status === KsefSyncState::FETCH_STATUS_DOWNLOADING) {
            return array(
                'status' => 'DOWNLOADING',
                'reference' => $syncState->fetch_reference,
                'parts_total' => $syncState->process_total,
                'parts_done' => $syncState->process_offset,
            );
        }

        if ($syncState->fetch_status === KsefSyncState::FETCH_STATUS_PROCESSING_BATCHES) {
            return array(
                'status' => 'PROCESSING_BATCHES',
                'reference' => $syncState->fetch_reference,
                'total' => $syncState->process_total,
                'processed' => $syncState->process_offset,
                'new' => $syncState->process_new,
                'existing' => $syncState->process_existing,
            );
        }

        if ($syncState->isRateLimited()) {
            return array('status' => 'RATE_LIMITED', 'retry_after' => (int) $syncState->secondsUntilNextSync());
        }

        $client = $this->syncClient($environment);
        $status = $client->getExportStatus($syncState->fetch_reference);

        if (!$status) {
            if ($this->noteSyncRateLimit($syncState, $client)) {
                return array('status' => 'RATE_LIMITED', 'retry_after' => (int) $syncState->secondsUntilNextSync());
            }
            return array('status' => 'CHECK_FAILED', 'error' => $client->error);
        }

        if ($status['status'] === 'PROCESSING') {
            return array(
                'status' => 'PROCESSING',
                'reference' => $syncState->fetch_reference,
                'elapsed_seconds' => dol_now() - $syncState->fetch_started
            );
        }

        if ($status['status'] === 'FAILED') {
            $errorMsg = $status['raw']['status']['description'] ?? 'Unknown error';
            $syncState->fetch_status = KsefSyncState::FETCH_STATUS_FAILED;
            $syncState->fetch_error = $errorMsg;
            $syncState->save();
            return array('status' => 'FAILED', 'error' => $errorMsg);
        }

        if ($status['status'] === 'COMPLETED') {
            $invoiceCount = $status['raw']['package']['invoiceCount'] ?? 0;
            $partsCount = count($status['parts'] ?? array());

            if ($invoiceCount == 0 || $partsCount == 0) {
                dol_syslog("KSeF: Export completed but no invoices to download (invoiceCount: {$invoiceCount}, parts: {$partsCount})", LOG_INFO);

                $newHwm = $this->computeIncomingContinuation(array(
                    'isTruncated' => !empty($status['isTruncated']),
                    'lastPermanentStorageDate' => $status['lastPermanentStorageDate'] ?? null,
                    'permanentStorageHwmDate' => $status['permanentStorageHwmDate'] ?? null,
                ));
                if ($newHwm !== '') {
                    $syncState->hwm_date = $newHwm;
                    dol_syslog("KSeF: Updated HWM to: " . $syncState->hwm_date, LOG_INFO);
                }

                $syncState->fetch_reference = '';
                $syncState->fetch_status = '';
                $syncState->fetch_started = 0;
                $syncState->fetch_key = '';
                $syncState->fetch_iv = '';
                $syncState->fetch_error = '';
                $syncState->fetch_parts = '';
                $syncState->fetch_hwm_data = '';
                $syncState->last_sync = dol_now();
                $syncState->last_sync_new = 0;
                $syncState->last_sync_existing = 0;
                $syncState->last_sync_total = 0;
                $syncState->last_sync_errors = '';
                $syncState->last_sync_fetch_errors = 0;
                $syncState->last_sync_unsupported = 0;
                $syncState->save();
                if ($direction === self::SYNC_OUTGOING) {
                    // full pass ran
                    $this->reconcileSales($environment, null, true);
                }

                return array(
                    'status' => 'COMPLETED',
                    'new' => 0,
                    'existing' => 0,
                    'total' => 0,
                    'errors' => 0,
                    'message' => 'No invoices to sync',
                    'audit_missing' => $this->syncAuditRound($direction, 0, !empty($status['isTruncated'])),
                );
            }

            dol_syslog("KSeF: Export completed, ({$invoiceCount} invoices, {$partsCount} parts)", LOG_INFO);

            $hwmData = array(
                'isTruncated' => !empty($status['isTruncated']),
                'lastPermanentStorageDate' => $status['lastPermanentStorageDate'] ?? null,
                'permanentStorageHwmDate' => $status['permanentStorageHwmDate'] ?? null,
                'completedDate' => $status['completedDate'] ?? null,
                'invoiceCount' => $invoiceCount,
            );

            $syncState->fetch_parts = json_encode($status['parts']);
            $syncState->fetch_hwm_data = json_encode($hwmData);
            $syncState->fetch_status = KsefSyncState::FETCH_STATUS_READY_TO_PROCESS;
            $syncState->process_total = $invoiceCount;
            $syncState->process_offset = 0;
            $syncState->process_new = 0;
            $syncState->process_existing = 0;
            $syncState->save();

            return array(
                'status' => 'READY_TO_PROCESS',
                'reference' => $syncState->fetch_reference,
                'invoice_count' => $invoiceCount,
                'parts_count' => $partsCount,
            );
        }

        dol_syslog("KSeF: Unknown export status: " . $status['status'], LOG_WARNING);
        return array('status' => $status['status']);
    }

    /**
     * @brief Process ready export
     * @param User $user
     * @param string $direction
     * @return array
     */
    public function processIncomingDownload($user, $direction = 'incoming')
    {
        dol_include_once('/ksef/class/ksef_sync_state.class.php');
        dol_include_once('/ksef/class/ksef_client.class.php');
        dol_include_once('/ksef/class/ksef_incoming.class.php');

        $direction = self::syncDirection($direction);
        $environment = ksefGetEnvironment();

        $syncState = new KsefSyncState($this->db);
        $syncState->load($direction);
        if ($this->ensureIncomingSyncEnvironment($syncState, $environment)) {
            return array('status' => 'SKIPPED', 'reason' => 'Environment changed, sync state reset');
        }

        if ($syncState->fetch_status !== KsefSyncState::FETCH_STATUS_READY_TO_PROCESS) {
            dol_syslog("KSeF: processIncomingDownload called but status is " . $syncState->fetch_status, LOG_WARNING);
            return array('status' => 'SKIPPED', 'reason' => 'Not in READY_TO_PROCESS state');
        }
        if ($syncState->storedFetchReference() === (string) $syncState->fetch_reference && !$syncState->claimFetchStatus(KsefSyncState::FETCH_STATUS_READY_TO_PROCESS, KsefSyncState::FETCH_STATUS_DOWNLOADING)) {
            dol_syslog("KSeF: processIncomingDownload: round " . $syncState->fetch_reference . " already claimed by another worker", LOG_WARNING);
            return array('status' => 'SKIPPED', 'reason' => 'Already being processed');
        }

        $level = (int) $this->db->transaction_opened;
        $parts = json_decode($syncState->fetch_parts, true);
        $hwmData = json_decode($syncState->fetch_hwm_data, true);
        $isTruncated = !empty($hwmData['isTruncated']);

        if (empty($parts)) {
            dol_syslog("KSeF: processIncomingDownload no parts found", LOG_ERR);
            return $this->failIncomingRound($syncState, 'No download parts found in state', array(), $isTruncated, null, $level);
        }

        $aesKey = base64_decode(dol_decode($syncState->fetch_key));
        $iv = base64_decode(dol_decode($syncState->fetch_iv));

        if (empty($aesKey) || empty($iv)) {
            dol_syslog("KSeF: Key decryption failed", LOG_ERR);
            return $this->failIncomingRound($syncState, 'Key decryption failed', array(), $isTruncated, null, $level);
        }

        $parts = $this->orderPackageParts($parts, $orderError);
        if ($parts === false) {
            return $this->failIncomingRound($syncState, $orderError, array(), $isTruncated, null, $level);
        }

        $partsTotal = count($parts);
        $syncState->fetch_status = KsefSyncState::FETCH_STATUS_DOWNLOADING;
        $syncState->process_total = $partsTotal;
        $syncState->process_offset = 0;
        $syncState->saveProgress();

        dol_syslog("KSeF: Starting async download ({$partsTotal} parts)", LOG_INFO);

        $client = $this->syncClient($environment);
        $tempFile = null;
        $fp = false;
        try {
            $tempFile = tempnam(sys_get_temp_dir(), 'ksef_dl_');
            if ($tempFile === false) {
                $tempFile = null;
                throw new Exception("Failed to create temp file");
            }

            $fp = fopen($tempFile, 'wb');
            if ($fp === false) {
                throw new Exception("Failed to open temp file for writing");
            }

            foreach ($parts as $index => $part) {
                $label = isset($part['ordinalNumber']) ? (int) $part['ordinalNumber'] : $index + 1;
                $encrypted = $client->downloadPackagePart($part);
                if ($encrypted === false) {
                    throw new Exception("Failed to download part $label");
                }
                $mismatch = $client->verifyPartHash($part, $encrypted);
                if ($mismatch !== '') {
                    throw new Exception("Part $label integrity check failed: $mismatch");
                }

                $decrypted = $client->decryptPackageData($encrypted, $aesKey, $iv);
                if ($decrypted === false) {
                    throw new Exception("Failed to decrypt part $label");
                }
                $mismatch = $client->verifyPartHash(array_diff_key($part, array('encryptedPartHash' => 1, 'encryptedPartSize' => 1)), '', $decrypted);
                if ($mismatch !== '') {
                    throw new Exception("Part $label integrity check failed: $mismatch");
                }

                $this->writePackagePart($fp, $decrypted, $label);
                unset($encrypted, $decrypted);

                $syncState->process_offset = $index + 1;
                $syncState->saveProgress();
            }

            $closed = fclose($fp);
            $fp = false;
            if ($closed === false) {
                throw new Exception("Failed to close temp file (disk full?)");
            }
            dol_syslog("KSeF: Package downloaded to temp file: " . $tempFile . " (" . filesize($tempFile) . " bytes)", LOG_INFO);
        } catch (Throwable $e) {
            if (is_resource($fp)) {
                fclose($fp);
            }
            dol_syslog("KSeF: Download exception: " . $e->getMessage(), LOG_ERR);
            $r = $this->failIncomingRound($syncState, 'Download exception: ' . $e->getMessage(), array(), $isTruncated, $tempFile, $level);
            $r['status'] = 'DOWNLOAD_FAILED';
            return $r;
        }

        $syncState->fetch_status = KsefSyncState::FETCH_STATUS_PROCESSING_BATCHES;
        $syncState->process_total = $hwmData['invoiceCount'] ?? 0;
        $syncState->process_offset = 0;
        $syncState->process_new = 0;
        $syncState->process_existing = 0;
        $syncState->process_file = $tempFile;
        $syncState->saveProgress();

        dol_syslog("KSeF: Starting batch processing", LOG_INFO);

        $batchSize = KsefSyncState::PROCESS_BATCH_SIZE;
        $counts = array('new' => 0, 'existing' => 0, 'errors' => 0, 'fetch_errors' => 0, 'unsupported' => 0, 'error_details' => array());
        $ksef = $this;

        $summary = $client->processPackageInBatches($tempFile, $batchSize, function ($batch, $batchNum, $totalFiles, $metadataIndex) use ($ksef, $user, $environment, &$counts, $syncState, $direction) {
            dol_syslog("KSeF: Starting callback for batch {$batchNum} with " . count($batch) . " invoices", LOG_INFO);

            $result = $ksef->processIncomingPackage(array('invoices' => $batch, 'metadata' => $metadataIndex), $user, $environment, $direction);
            $ksef->addIncomingCounts($counts, $result);
            dol_syslog("KSeF: Batch {$batchNum} complete - new: {$result['new']}, existing: {$result['existing']}, errors: {$result['errors']}", LOG_INFO);

            $syncState->process_offset += count($batch);
            $syncState->process_new = $counts['new'];
            $syncState->process_existing = $counts['existing'];
            $syncState->saveProgress();
            gc_collect_cycles();
        });

        if ($summary === false) {
            return $this->failIncomingRound($syncState, 'Processing interrupted: ' . $client->error, $counts, $isTruncated, $tempFile, $level);
        }

        if (!empty($summary['missing'])) {
            try {
                $missing = array();
                foreach ($summary['missing'] as $num) {
                    $missing[$num] = null;
                }
                $result = $this->processIncomingPackage(array('invoices' => $missing, 'metadata' => $summary['metadata']), $user, $environment, $direction);
                $this->addIncomingCounts($counts, $result);
            } catch (Throwable $e) {
                return $this->failIncomingRound($syncState, 'Processing interrupted: ' . $e->getMessage(), $counts, $isTruncated, $tempFile, $level);
            }
        }

        @unlink($tempFile);

        if ($syncState->storedFetchReference() !== (string) $syncState->fetch_reference) {
            dol_syslog("KSeF: Incoming round " . $syncState->fetch_reference . " finished after its state was replaced; result not saved", LOG_WARNING);
            return array('status' => 'SUPERSEDED', 'new' => $counts['new'], 'existing' => $counts['existing'], 'errors' => $counts['errors']);
        }

        $newHwm = $this->computeIncomingContinuation($hwmData);
        if ($newHwm !== '') {
            $syncState->hwm_date = $newHwm;
            dol_syslog("KSeF: Updated HWM to: " . $newHwm, LOG_INFO);
        }

        $finalTotal = $counts['new'] + $counts['existing'] + $counts['errors'] + (int) ($counts['unsupported'] ?? 0);
        $syncState->last_sync = dol_now();
        $syncState->last_sync_new = $counts['new'];
        $syncState->last_sync_existing = $counts['existing'];
        $syncState->last_sync_total = $finalTotal;
        $syncState->last_sync_errors = !empty($counts['error_details']) ? json_encode(array_slice($counts['error_details'], 0, 100)) : '';
        $syncState->last_sync_fetch_errors = (int) ($counts['fetch_errors'] ?? 0);
        $syncState->last_sync_unsupported = (int) ($counts['unsupported'] ?? 0);
        $syncState->fetch_reference = '';
        $syncState->fetch_status = '';
        $syncState->fetch_started = 0;
        $syncState->fetch_key = '';
        $syncState->fetch_iv = '';
        $syncState->fetch_error = '';
        $syncState->fetch_parts = '';
        $syncState->fetch_hwm_data = '';
        $syncState->process_file = '';
        $syncState->process_total = 0;
        $syncState->process_offset = 0;
        $syncState->process_new = 0;
        $syncState->process_existing = 0;
        $syncState->heartbeat = 0;
        $syncState->save();

        dol_syslog("KSeF: All batches complete - new: {$counts['new']}, existing: {$counts['existing']}, fetch errors: {$counts['fetch_errors']}, total: {$finalTotal}", LOG_INFO);
        if ($direction === self::SYNC_OUTGOING) {
            $this->reconcileSales($environment, null, true);
        }

        return array(
            'status' => 'COMPLETED',
            'audit_missing' => $this->syncAuditRound($direction, (int) $counts['new'] + (int) $counts['fetch_errors'] + (int) ($counts['unsupported'] ?? 0), !empty($isTruncated)),
            'new' => $counts['new'],
            'existing' => $counts['existing'],
            'total' => $finalTotal,
            'errors' => $counts['errors'],
            'fetch_errors' => $counts['fetch_errors'],
            'unsupported' => (int) ($counts['unsupported'] ?? 0),
            'error_details' => $counts['error_details'],
            'truncated' => $isTruncated
        );
    }

    /**
     * @brief Add round counts
     * @param array $counts By reference
     * @param array $result
     * @return void
     */
    public function addIncomingCounts(&$counts, $result)
    {
        $counts['new'] += (int) ($result['new'] ?? 0);
        $counts['existing'] += (int) ($result['existing'] ?? 0);
        $counts['errors'] += (int) ($result['errors'] ?? 0);
        $counts['fetch_errors'] += (int) ($result['fetch_errors'] ?? 0);
        $counts['unsupported'] = (int) ($counts['unsupported'] ?? 0) + (int) ($result['unsupported'] ?? 0);
        if (!empty($result['error_details'])) {
            $counts['error_details'] = array_merge($counts['error_details'], $result['error_details']);
        }
    }

    /**
     * @brief Sort export parts
     * @param array $parts
     * @param string $error By reference
     * @return array|false false on bad ordinals
     */
    private function orderPackageParts($parts, &$error)
    {
        $error = '';
        $parts = array_values((array) $parts);
        $withOrdinal = 0;
        foreach ($parts as $p) {
            if (is_array($p) && isset($p['ordinalNumber']) && is_numeric($p['ordinalNumber'])) {
                $withOrdinal++;
            }
        }
        if ($withOrdinal === 0) {
            dol_syslog("KSeF: Export parts carry no ordinalNumber, joined in list order", LOG_WARNING);
            return $parts;
        }
        $ordinals = array();
        foreach ($parts as $p) {
            $ordinals[] = (is_array($p) && isset($p['ordinalNumber']) && is_numeric($p['ordinalNumber'])) ? (int) $p['ordinalNumber'] : 0;
        }
        $sorted = $ordinals;
        sort($sorted);
        if ($withOrdinal !== count($parts) || $sorted !== range(1, count($parts))) {
            $error = 'Export parts have missing, repeated or non-contiguous ordinalNumber (' . implode(',', $ordinals) . ')';
            return false;
        }
        array_multisort($ordinals, SORT_ASC, SORT_NUMERIC, $parts);
        return array_values($parts);
    }

    /**
     * @brief Append decrypted part
     * @param resource $fp
     * @param string $bytes
     * @param int $label Part number
     * @return void
     * @throws Exception
     */
    private function writePackagePart($fp, $bytes, $label)
    {
        $len = strlen((string) $bytes);
        $done = 0;
        while ($done < $len) {
            $written = @fwrite($fp, $done === 0 ? $bytes : substr($bytes, $done));
            if ($written === false || $written === 0) {
                throw new Exception("Failed to write part $label to the temp file ($done of $len bytes, disk full?)");
            }
            $done += $written;
        }
    }

    /**
     * @brief Store sync 429
     * @param KsefSyncState $syncState
     * @param KsefClient $client
     * @return bool True on 429
     */
    private function noteSyncRateLimit($syncState, $client)
    {
        if ((int) $client->last_http_code !== 429) {
            return false;
        }
        $syncState->setRateLimit((int) $client->retry_after_seconds);
        dol_syslog("KSeF: sync rate limited until " . dol_print_date($syncState->rate_limit_until, 'dayhourlog'), LOG_WARNING);
        return true;
    }

    /**
     * @brief Sync step client
     * @param string $environment
     * @return KsefClient
     */
    private function syncClient($environment)
    {
        $client = new KsefClient($this->db, $environment);
        if (is_object($this->client) && isset($this->client->retry_wait_cap)) {
            $client->retry_wait_cap = $this->client->retry_wait_cap;
        }
        return $client;
    }

    /**
     * @brief Round continuation point
     * @param array|null $hwmData
     * @return string '' when none
     */
    private function computeIncomingContinuation($hwmData)
    {
        $hwmData = is_array($hwmData) ? $hwmData : array();
        $ts = null;
        if (!empty($hwmData['isTruncated']) && !empty($hwmData['lastPermanentStorageDate'])) {
            $ts = $hwmData['lastPermanentStorageDate'];
        } elseif (!empty($hwmData['permanentStorageHwmDate'])) {
            $ts = $hwmData['permanentStorageHwmDate'];
        } elseif (!empty($hwmData['lastPermanentStorageDate'])) {
            $ts = $hwmData['lastPermanentStorageDate'];
        }
        if ($ts === null || $ts === false) {
            return '';
        }
        return date('c', is_numeric($ts) ? (int) $ts : strtotime($ts));
    }

    /**
     * @brief Fail incoming round
     * @param KsefSyncState $syncState
     * @param string $message
     * @param array $counts
     * @param bool $isTruncated
     * @param string|null $tempFile Removed
     * @param int $level Entry transaction level
     * @return array
     */
    private function failIncomingRound($syncState, $message, $counts, $isTruncated, $tempFile, $level)
    {
        while ((int) $this->db->transaction_opened > (int) $level) {
            $this->db->rollback();
        }
        if (!empty($tempFile)) {
            @unlink($tempFile);
        }
        $counts += array('new' => 0, 'existing' => 0, 'error_details' => array());
        dol_syslog("KSeF: Incoming round failed (continuation point kept): " . $message, LOG_ERR);

        $result = array(
            'status' => 'FAILED',
            'error' => $message,
            'new' => $counts['new'],
            'existing' => $counts['existing'],
            'total' => $counts['new'] + $counts['existing'],
            'errors' => 1,
            'error_details' => array_merge(array($message), $counts['error_details']),
            'truncated' => $isTruncated
        );
        if ($syncState->storedFetchReference() !== (string) $syncState->fetch_reference) {
            dol_syslog("KSeF: Incoming round " . $syncState->fetch_reference . " failed after its state was replaced; failure not saved", LOG_WARNING);
            return $result;
        }

        $syncState->last_sync = dol_now();
        $syncState->last_sync_new = $counts['new'];
        $syncState->last_sync_existing = $counts['existing'];
        $syncState->last_sync_total = $counts['new'] + $counts['existing'];
        $syncState->last_sync_errors = json_encode(array_slice($result['error_details'], 0, 100));
        $syncState->fetch_status = KsefSyncState::FETCH_STATUS_FAILED;
        $syncState->fetch_error = dol_trunc($message, 1000, 'right', 'UTF-8', 1);
        $syncState->fetch_parts = '';
        $syncState->fetch_hwm_data = '';
        $syncState->process_file = '';
        $syncState->process_total = 0;
        $syncState->process_offset = 0;
        $syncState->process_new = 0;
        $syncState->process_existing = 0;
        $syncState->heartbeat = 0;
        $syncState->save();
        return $result;
    }

    /**
     * @brief Reset foreign-environment state
     * @param KsefSyncState $syncState
     * @param string $environment
     * @return bool True on reset
     */
    public function ensureIncomingSyncEnvironment($syncState, $environment)
    {
        if ((string) $syncState->environment === (string) $environment) {
            return false;
        }
        if ((string) $syncState->environment === '') {
            $syncState->adoptEnvironment($environment);
            return false;
        }
        dol_syslog("KSeF: Incoming sync state belongs to " . $syncState->environment . ", environment is " . $environment . ": sync starts again", LOG_WARNING);
        $syncState->resetAll($environment);
        return true;
    }

    /**
     * @brief Store downloaded package
     * @param array $packageData
     * @param User $user
     * @param string $environment
     * @param string $direction
     * @return array
     * @throws Exception
     */
    public function processIncomingPackage($packageData, $user, $environment, $direction = 'incoming')
    {
        global $langs;
        dol_include_once('/ksef/class/fa3_parser.class.php');
        dol_include_once('/ksef/class/ksef_incoming.class.php');
        $recordClass = self::syncRecordClass($direction);

        $result = array('new' => 0, 'existing' => 0, 'errors' => 0, 'fetch_errors' => 0, 'unsupported' => 0, 'total' => 0, 'error_details' => array());

        if (empty($packageData['invoices'])) {
            dol_syslog("KSeF: No invoices in package", LOG_INFO);
            return $result;
        }

        $invoiceCount = count($packageData['invoices']);
        dol_syslog("KSeF: processIncomingPackage starting with {$invoiceCount} invoices", LOG_INFO);

        $metadata = isset($packageData['metadata']) && is_array($packageData['metadata']) ? $packageData['metadata'] : array();
        $incoming = new $recordClass($this->db);
        $parser = new FA3Parser($this->db);

        // unique: ksef_number
        $stored = $incoming->getStoredEnvironments(array_keys($packageData['invoices']));

        foreach ($packageData['invoices'] as $ksefNumber => $xmlContent) {
            $result['total']++;
            $ksefNumber = (string) $ksefNumber;
            $meta = isset($metadata[$ksefNumber]) && is_array($metadata[$ksefNumber]) ? $metadata[$ksefNumber] : array();

            if (isset($stored[$ksefNumber])) {
                $result['existing']++;
                if ($stored[$ksefNumber] !== (string) $environment) {
                    dol_syslog("KSeF: {$ksefNumber} already stored under environment " . $stored[$ksefNumber] . ", counted as existing", LOG_WARNING);
                }
                unset($packageData['invoices'][$ksefNumber]);
                continue;
            }

            $failure = '';
            if ($xmlContent === null) {
                $failure = KsefIncoming::FETCH_ERROR_MISSING_FILE;
            } elseif ($xmlContent === false || $xmlContent === '') {
                $failure = KsefIncoming::FETCH_ERROR_DAMAGED_FILE;
            } else {
                $level = (int) $this->db->transaction_opened;
                $this->db->begin();
                try {
                    if (!is_string($xmlContent)) {
                        throw new Exception('XML content is not a string');
                    }
                    $parsed = $parser->parse($xmlContent);
                    if (!$parsed) {
                        if ($parser->unsupported_form !== '') {
                            $form = !empty($meta['formCode']['systemCode']) ? $meta['formCode']['systemCode'] : $parser->unsupported_form;
                            throw new Exception(KsefIncoming::UNSUPPORTED_FORM_PREFIX . $form);
                        }
                        $perr = $parser->error ?: 'unknown error';
                        throw new Exception(strpos($perr, 'Failed to parse XML') === 0 ? $perr : 'Failed to parse XML: ' . $perr);
                    }

                    $newIncoming = new $recordClass($this->db);
                    if ($newIncoming->createFromParsed($parsed, $xmlContent, $ksefNumber, $environment, $user, true) > 0) {
                        $this->db->commit();
                        $result['new']++;
                    } else {
                        throw new Exception($newIncoming->error ?: 'Create failed');
                    }
                } catch (Throwable $e) {
                    while ((int) $this->db->transaction_opened > $level) {
                        $this->db->rollback();
                    }
                    $failure = $e->getMessage();
                    if (!($e instanceof Exception)) {
                        $failure = get_class($e) . ': ' . $failure;
                    }
                }
            }

            if ($failure !== '') {
                $failed = new $recordClass($this->db);
                $res = $failed->createFailed($ksefNumber, is_string($xmlContent) ? $xmlContent : null, $environment, $failure, $meta, $user);
                if ($res === 0) {
                    $result['existing']++;
                } elseif ($res > 0 && strpos($failure, KsefIncoming::UNSUPPORTED_FORM_PREFIX) === 0) {
                    $result['unsupported']++;
                    dol_syslog("KSeF: {$ksefNumber} stored as unsupported form: " . $failure, LOG_INFO);
                } elseif ($res > 0) {
                    $result['errors']++;
                    $result['fetch_errors']++;
                    $result['error_details'][] = "{$ksefNumber}: " . $failed->getFetchErrorText();
                    dol_syslog("KSeF: Error processing {$ksefNumber}: " . $failure . " (stored as fetch error)", LOG_ERR);
                } else {
                    throw new Exception("Could not store the failed invoice {$ksefNumber}: " . ($failed->error ?: 'unknown error'));
                }
            }

            unset($packageData['invoices'][$ksefNumber]);
        }

        return $result;
    }

    /**
     * @brief Batch processing status
     * @param KsefSyncState $syncState
     * @param User $user
     * @param string $environment
     * @return array
     */
    private function continueProcessingBatch($syncState, $user, $environment)
    {
        if ($syncState->isProcessingTimedOut()) {
            dol_syslog("KSeF: Batch processing timed out - marking as failed", LOG_WARNING);
            $doneNew = (int) $syncState->process_new;
            $doneExisting = (int) $syncState->process_existing;
            $syncState->last_sync = dol_now();
            $syncState->last_sync_new = $syncState->process_new;
            $syncState->last_sync_existing = $syncState->process_existing;
            $syncState->last_sync_total = $syncState->process_new + $syncState->process_existing;
            $syncState->fetch_status = KsefSyncState::FETCH_STATUS_FAILED;
            $syncState->fetch_error = 'Batch processing timed out. '
                . $syncState->process_new . ' new and '
                . $syncState->process_existing . ' existing were processed before timeout. '
                . 'Use "Reset & Retry" to restart.';
            $syncState->clearProcessingState();
            $syncState->save();
            return array(
                'status' => 'FAILED',
                'error' => $syncState->fetch_error,
                'new' => $doneNew,
                'existing' => $doneExisting,
            );
        }

        return array(
            'status' => 'PROCESSING_BATCHES',
            'reference' => $syncState->fetch_reference,
            'total' => $syncState->process_total,
            'processed' => $syncState->process_offset,
            'new' => $syncState->process_new,
            'existing' => $syncState->process_existing,
        );
    }

    /**
     * @brief Reset failed fetch
     * @param string $direction
     * @return bool
     */
    public function resetIncomingFetch($direction = 'incoming')
    {
        dol_include_once('/ksef/class/ksef_sync_state.class.php');

        $syncState = new KsefSyncState($this->db);
        $syncState->load(self::syncDirection($direction));
        return $syncState->clearFetchState() > 0;
    }

    /**
     * @brief Current sync state
     * @param string $direction
     * @return KsefSyncState
     */
    public function getIncomingSyncState($direction = 'incoming')
    {
        dol_include_once('/ksef/class/ksef_sync_state.class.php');

        $syncState = new KsefSyncState($this->db);
        $syncState->load(self::syncDirection($direction));
        return $syncState;
    }

    /**
     * @brief Reset sync state
     * @param User $user
     * @param string $direction
     * @param string|int $fromDate '' = KSeF start
     * @return bool
     */
    public function resetIncomingSyncState($user, $direction = 'incoming', $fromDate = '')
    {
        global $conf, $langs;
        dol_include_once('/ksef/class/ksef_sync_state.class.php');

        $syncState = new KsefSyncState($this->db);
        $syncState->load(self::syncDirection($direction));
        $hwm = '';
        if ((string) $fromDate !== '') {
            $ts = is_numeric($fromDate) ? (int) $fromDate : strtotime((string) $fromDate);
            if ($ts === false || $ts < strtotime(KsefSyncState::DEFAULT_HWM) || $ts > dol_now()) {
                $langs->load('ksef@ksef');
                $this->error = $langs->trans('KSEF_ErrorResyncDateInvalid', dol_print_date(strtotime(KsefSyncState::DEFAULT_HWM), 'day'));
                return false;
            }
            $current = $syncState->getContinuationDate();
            if (!empty($current) && $ts > $current) {
                $ts = $current;
            }
            $hwm = date('c', $ts);
        }
        $ok = $syncState->resetAll(ksefGetEnvironment(), $hwm) > 0;
        if ($ok) {
            require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
            $dir = strtoupper(self::syncDirection($direction));
            dolibarr_set_const($this->db, 'KSEF_SYNC_AUDIT_' . $dir, '0', 'chaine', 0, '', $conf->entity);
            dolibarr_del_const($this->db, 'KSEF_SYNC_AUDIT_DONE_' . $dir, $conf->entity);
        }
        return $ok;
    }

    /**
     * @brief Can rewind sync
     * @param User $user
     * @return bool
     */
    public static function canRewindSync($user)
    {
        return is_object($user) && !empty($user->admin);
    }

    /**
     * @brief Reset start date
     * @param string $fromDate
     * @param int|string $daysBack
     * @return string|false false when invalid
     */
    public function syncResetStartDate($fromDate, $daysBack = 0)
    {
        global $langs;
        $langs->load('ksef@ksef');
        dol_include_once('/ksef/class/ksef_sync_state.class.php');
        $startTs = strtotime(KsefSyncState::DEFAULT_HWM);
        $invalid = $langs->transnoentitiesnoconv('KSEF_ErrorResyncDateInvalid', dol_print_date($startTs, 'day'));
        $fromDate = trim((string) $fromDate);
        if ($fromDate !== '') {
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $fromDate, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                $this->error = $invalid;
                return false;
            }
            $ts = strtotime($fromDate . ' 00:00:00');
            if ($ts < $startTs || $ts > dol_now()) {
                $this->error = $invalid;
                return false;
            }
            return $fromDate;
        }
        $daysBack = trim((string) $daysBack);
        if ($daysBack === '' || $daysBack === '0') {
            return '';
        }
        if (!ctype_digit($daysBack)) {
            $this->error = $langs->transnoentitiesnoconv('KSEF_ErrorResyncDaysBackInvalid');
            return false;
        }
        $ts = strtotime(date('Y-m-d', dol_now()) . ' 00:00:00') - (int) $daysBack * 86400;
        return $ts <= $startTs ? '' : date('Y-m-d', $ts);
    }

    /**
     * @brief Sync state summary
     * @param string $direction
     * @return array
     */
    public function getIncomingSyncStatus($direction = 'incoming')
    {
        $st = $this->getIncomingSyncState($direction);
        $fetching = $st->isFetchInProgress();
        $processing = $st->isProcessingInProgress();
        $timedOut = $st->isFetchTimedOut() || $st->isProcessingTimedOut();
        $rateLimited = $st->isRateLimited();
        $cont = $st->getContinuationDate();
        $data = array(
            'is_fetching' => $fetching,
            'is_processing' => $processing,
            'is_timed_out' => $timedOut,
            'is_rate_limited' => $rateLimited,
            'can_sync_now' => !$rateLimited && ((!$fetching && !$processing) || $timedOut),
            'can_advance' => ($fetching || $processing) && !$timedOut,
            'fetch_reference' => $st->fetch_reference ?: null,
            'fetch_status' => $st->fetch_status ?: null,
            'fetch_started' => $st->fetch_started ?: null,
            'fetch_error' => $st->fetch_error ?: null,
            'hwm_date' => $st->hwm_date ?: null,
            'last_sync' => $st->last_sync ?: null,
            'last_sync_new' => (int) $st->last_sync_new,
            'last_sync_existing' => (int) $st->last_sync_existing,
            'last_sync_total' => (int) $st->last_sync_total,
            'last_sync_fetch_errors' => (int) $st->last_sync_fetch_errors,
            'last_sync_unsupported' => (int) $st->last_sync_unsupported,
            'more' => !$cont || $cont < dol_now() - 86400,
        );
        if ($processing) {
            $data['processing_progress'] = $st->getProcessingProgress();
        }
        if ($rateLimited) {
            $data['rate_limit_until'] = $st->rate_limit_until;
            $data['rate_limit_seconds'] = $st->secondsUntilNextSync();
        }
        return $data;
    }

    /**
     * @brief Step started sync
     * @param User $user
     * @param string $direction
     * @return array
     */
    public function advanceIncomingSync($user, $direction = 'incoming')
    {
        dol_include_once('/ksef/lib/ksef.lib.php');
        $direction = self::syncDirection($direction);
        $r = $this->checkIncomingFetchStatus($user, $direction);
        if (is_array($r) && ($r['status'] ?? '') === 'READY_TO_PROCESS') {
            $r = $this->processIncomingDownload($user, $direction);
        }
        return ksefSyncAddMore($r, $direction);
    }

    /**
     * @brief Count completeness round
     * @param string $direction
     * @param int $new Stored invoices
     * @param bool $truncated
     * @return int|null null when no check
     */
    public function syncAuditRound($direction, $new, $truncated)
    {
        global $conf;
        $dir = strtoupper(self::syncDirection($direction));
        $key = 'KSEF_SYNC_AUDIT_' . $dir;
        $cur = getDolGlobalString($key, '');
        if ($cur === '') {
            return null;
        }
        require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
        $found = (int) $cur + (int) $new;
        dol_include_once('/ksef/class/ksef_sync_state.class.php');
        $st = new KsefSyncState($this->db);
        $st->load(self::syncDirection($direction));
        $cont = $st->getContinuationDate();
        $behind = !$cont || $cont < dol_now() - 86400;
        if ($truncated || $behind) {
            dolibarr_set_const($this->db, $key, (string) $found, 'chaine', 0, '', $conf->entity);
        } else {
            dolibarr_del_const($this->db, $key, $conf->entity);
            dolibarr_set_const($this->db, 'KSEF_SYNC_AUDIT_DONE_' . $dir, (string) $found, 'chaine', 0, '', $conf->entity);
        }
        return $found;
    }

    /**
     * @brief Cron send queue
     * @return int 0 OK, <0 error
     */
    public function cronCheckStatuses()
    {
        global $conf, $user;
        $this->output = '';
        $prevCap = $this->client->retry_wait_cap;
        $this->client->retry_wait_cap = self::CRON_RETRY_WAIT_CAP;
        $processed = 0;
        $errors = 0;
        $fromSales = 0;

        try {
            dol_include_once('/ksef/class/ksef_submission.class.php');
            dol_include_once('/ksef/class/ksef_client.class.php');
            dol_include_once('/ksef/lib/ksef.lib.php');

            $submission = new KsefSubmission($this->db);
            $environment = ksefGetEnvironment();

            $uploaded = 0;
            $notRecorded = array();
            $replacedClosed = $submission->closeReplacedOpen($user, $environment);
            if ($replacedClosed > 0) {
                dol_syslog("KSEF::cronCheckStatuses " . $replacedClosed . " open send(s) replaced by an accepted send closed", LOG_INFO);
            }
            $held = $submission->holdBehindDuplicate($user, $environment);
            if ($held['held'] > 0 || $held['closed'] > 0) {
                dol_syslog("KSEF::cronCheckStatuses open send(s) behind a 440 duplicate: " . $held['held'] . " kept open, " . $held['closed'] . " closed (KSeF holds another file)", LOG_INFO);
            }
            $this->cronStartClock();
            $stats = array('offline' => 0, 'sales' => 0, 'older' => 0, 'held_back' => array(), 'stop' => array());
            $offHandled = array();
            // correction may follow
            $heldLate = array();
            while (true) {
                if ($stats['offline'] > 0 && !$this->cronTimeLeft()) {
                    $stats['stop']['offline'] = 'time';
                    break;
                }
                $waiting = $submission->fetchOfflineWaiting($environment, self::CRON_OFFLINE_BATCH, array_keys($offHandled));
                $heldNow = array_fill_keys(array_map('intval', (array) $submission->lastHeldBack), true);
                $stats['held_back'] = $heldNow + $heldLate;
                if (!is_array($waiting) || !$waiting) {
                    break;
                }
                $stats['offline']++;
                $batchUploaded = 0;
                $batchFailed = 0;
                $halt = '';
                $rateMark = $this->cronRateHits();
                foreach ($waiting as $sub) {
                    if ($this->cronRateLimited($rateMark)) {
                        $halt = 'rate_limit';
                        break;
                    }
                    // progress guaranteed
                    if ($offHandled && !$this->cronTimeLeft()) {
                        $halt = 'time';
                        break;
                    }
                    $offHandled[(int) $sub->rowid] = true;
                    $res = $this->retryOfflineWithStoredXML($sub, $user);
                    if (is_array($res) && isset($res['record_saved']) && $res['record_saved'] === false) {
                        $errors++;
                        $batchFailed++;
                        $notRecorded[] = (string) ($res['warning'] ?? ('KSeF ' . ($res['ksef_number'] ?? '')));
                        continue;
                    }
                    if (is_array($res) && ($res['status'] ?? '') === 'ACCEPTED') {
                        $uploaded++;
                        $batchUploaded++;
                        continue;
                    }
                    if (is_array($res) && (!empty($res['in_progress']) || in_array(($res['error_code'] ?? ''), array('ISSUED_IN_PRODUCTION', 'CORRECTION_SOURCE_AWAITING_NUMBER'), true))) {
                        if (($res['error_code'] ?? '') === 'CORRECTION_SOURCE_AWAITING_NUMBER') {
                            $heldLate[(int) $sub->rowid] = true;
                            $stats['held_back'][(int) $sub->rowid] = true;
                        }
                        continue;
                    }
                    $errors++;
                    $batchFailed++;
                    if (is_array($res) && ($res['failure_kind'] ?? '') === 'transport') {
                        $halt = 'transport';
                        break;
                    }
                    if ($this->cronRateLimited($rateMark)) {
                        $halt = 'rate_limit';
                        break;
                    }
                }
                if ($halt === '' && $this->cronRateLimited($rateMark)) {
                    $halt = 'rate_limit';
                }
                if ($halt !== '') {
                    $stats['stop']['offline'] = $halt;
                    break;
                }
                if (count($waiting) < self::CRON_OFFLINE_BATCH && (!$heldNow || $batchUploaded === 0)) {
                    break;
                }
                if ($batchUploaded === 0 && $batchFailed > 0) {
                    $stats['stop']['offline'] = 'no_progress';
                    break;
                }
            }
            $resStuck = $this->db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "ksef_submissions WHERE status = '" . KsefSubmission::STATUS_PENDING . "'"
                . " AND offline_mode IS NOT NULL AND environment = '" . $this->db->escape($environment) . "'"
                . " AND COALESCE(date_last_check, date_submission, 0) < " . (int) (dol_now() - ksefSendStaleAfter()));
            $this->hashSearchPaused = $this->hashSearchPausedUntil() > dol_now();
            $hashBudget = $this->hashSearchPaused ? 0 : self::CRON_HASH_SEARCH_ROWS;
            if ($this->hashSearchPaused) {
                $dueNoRef = $submission->fetchNoRefOlder($environment, 1);
                if (is_array($dueNoRef) && $dueNoRef) {
                    $stats['stop']['hash_search'] = 'rate_limit';
                }
            }
            $stuckSearched = 0;
            while ($resStuck && ($stuckRow = $this->db->fetch_object($resStuck))) {
                if ($stuckSearched > 0 && $hashBudget > 0 && !$this->cronTimeLeft()) {
                    $hashBudget = 0;
                    $stats['stop']['hash_search'] = 'time';
                }
                $stuck = new KsefSubmission($this->db);
                if ($stuck->fetch((int) $stuckRow->rowid) > 0) {
                    if ($stuck->getSessionReference() === '' && !empty($stuck->invoice_hash) && $this->hashSearchPaused) {
                        $stats['stop']['hash_search'] = 'rate_limit';
                    }
                    if ($stuck->getSessionReference() === '' && !empty($stuck->invoice_hash) && $hashBudget > 0) {
                        $hashBudget--;
                        $stuckSearched++;
                        $found = $this->cronHashSearch($stuck, $user, $notRecorded, $hashBudget);
                        if ($found === 'found') {
                            $processed++;
                            continue;
                        }
                        if ($found === 'error') {
                            $errors++;
                            if ($this->failureReason === 'gateway') {
                                $hashBudget = 0;
                            }
                        }
                        // 440 adopts copy
                    }
                    $stuck->status = KsefSubmission::STATUS_OFFLINE;
                    $stuck->date_last_check = dol_now();
                    $stuck->update($user, 1, array(KsefSubmission::STATUS_PENDING));
                }
            }

            $salesHandled = array();
            while (true) {
                if ($stats['sales'] > 0 && !$this->cronTimeLeft()) {
                    $stats['stop']['sales'] = 'time';
                    break;
                }
                $inSalesRows = $submission->fetchNoRefInSales($environment, self::CRON_SALES_ADOPT_ROWS, array_keys($salesHandled));
                if (!is_array($inSalesRows) || !$inSalesRows) {
                    break;
                }
                $stats['sales']++;
                $batchAdopted = 0;
                $salesTimeUp = false;
                foreach ($inSalesRows as $sub) {
                    if ($salesHandled && !$this->cronTimeLeft()) {
                        $salesTimeUp = true;
                        break;
                    }
                    $salesHandled[(int) $sub->rowid] = true;
                    $adopted = $this->adoptFromSales($sub, $user);
                    if ($adopted > 0) {
                        $fromSales++;
                        $batchAdopted++;
                    } elseif ($adopted < 0) {
                        $errors++;
                        $notRecorded[] = $this->error;
                    }
                }
                if ($salesTimeUp) {
                    $stats['stop']['sales'] = 'time';
                    break;
                }
                if (count($inSalesRows) < self::CRON_SALES_ADOPT_ROWS) {
                    break;
                }
                if ($batchAdopted === 0) {
                    $stats['stop']['sales'] = 'no_progress';
                    break;
                }
            }

            $pending = $submission->fetchPending($environment);
            if (is_array($pending)) {
                usort($pending, function ($a, $b) {
                    return array((int) $a->date_last_check, (int) $a->date_submission, (int) $a->rowid) <=> array((int) $b->date_last_check, (int) $b->date_submission, (int) $b->rowid);
                });
            }
            $older =$submission->fetchUnresolvedOlder($environment, self::CRON_UNRESOLVED_BATCH);
            $noRefOlder = $hashBudget > 0 ? $submission->fetchNoRefOlder($environment, $hashBudget + 3) : array();
            $pending = array_merge(is_array($pending) ? $pending : array(), is_array($older) ? $older : array(), is_array($noRefOlder) ? $noRefOlder : array());
            $seenRows = array();
            foreach ($pending as $k => $sub) {
                if (isset($seenRows[(int) $sub->rowid])) {
                    unset($pending[$k]);
                }
                $seenRows[(int) $sub->rowid] = true;
            }
            $hashPick = $this->cronHashSearchPicks($pending, $hashBudget);

            if (!$pending || count($pending) == 0) {
                $this->output = $uploaded || $errors || $fromSales ? "Processed: 0, Errors: $errors, Offline uploaded: $uploaded" . ($fromSales ? ", Taken from KSeF sales: $fromSales" : '') : 'No pending submissions found';
                $this->output .= $this->cronRunNote($stats);
                if ($notRecorded) {
                    $this->error = implode("\n", $notRecorded);
                    $this->output .= "\n" . $this->error;
                    return -1;
                }
                return 0;
            }

            $pending = array_values($pending);
            $pendingCtl = array('handled' => array(), 'processed' => $processed, 'errors' => $errors, 'rate_mark' => $this->cronRateHits());
            foreach ($pending as $sub) {
                $pendingCtl['handled'][(int) $sub->rowid] = true;
            }
            $olderCalls = 0;
            for ($i = 0; true; $i++) {
                if ($i >= count($pending)) {
                    $next = $this->cronNextPendingBatch($submission, $environment, $pendingCtl, $processed, $errors, $hashBudget, $hashPick, $stats);
                    if (!$next) {
                        break;
                    }
                    $pending = array_merge($pending, $next);
                }
                if ($this->cronRateHits() > (int) $pendingCtl['rate_mark']) {
                    $stats['stop']['older'] = 'rate_limit';
                    break;
                }
                if ($olderCalls > 0 && !$this->cronTimeLeft()) {
                    $stats['stop']['older'] = 'time';
                    break;
                }
                $sub = $pending[$i];
                try {
                    // stuck loop owns
                    if (!empty($sub->offline_mode) && $sub->status == KsefSubmission::STATUS_PENDING) {
                        continue;
                    }

                    if ($sub->status == KsefSubmission::STATUS_SUBMITTED && !$sub->isStale()) {
                        continue;
                    }

                    if (in_array($sub->status, array(
                        KsefSubmission::STATUS_SUBMITTED,
                        KsefSubmission::STATUS_TIMEOUT
                    ))) {
                        $olderCalls++;
                        if ($this->refreshSubmissionStatus($sub, $user) === false && !in_array($this->failureReason, array('noref', 'notfound'), true)) {
                            if ($this->notRecordedNumber !== '') {
                                $notRecorded[] = $this->error;
                            }
                            $sub->date_last_check = dol_now();
                            $sub->update($user, 1, array($sub->status));
                            $errors++;
                            continue;
                        }
                        if ($this->failureReason === 'noref') {
                            $sub->date_last_check = dol_now();
                            $sub->update($user, 1, array($sub->status));
                        }
                    } elseif ($this->cronHashSearchCandidate($sub)) {
                        if (!isset($hashPick[(int) $sub->rowid]) || $hashBudget <= 0) {
                            if ($this->hashSearchPaused && $sub->hashSearchDueAt() <= dol_now()) {
                                $stats['stop']['hash_search'] = 'rate_limit';
                            }
                            continue;
                        }
                        $hashBudget--;
                        $olderCalls++;
                        $found = $this->cronHashSearch($sub, $user, $notRecorded, $hashBudget);
                        if ($found === 'notfound') {
                            $inSales = $this->hashInSales($sub);
                            $expired = dol_now() >= $sub->hashSearchEndsAt();
                            if (!$inSales && ($this->hashSearchComplete || $expired)) {
                                $this->closeNotReceived($sub, $user, $this->hashSearchComplete ? 'complete' : 'expired');
                            } else {
                                $this->stampHashSearch($sub, $inSales ? 'in_sales' : 'incomplete', $expired);
                                dol_syslog("KSEF::cronCheckStatuses row " . (int) $sub->rowid . " not found in the " . $this->hashSearchRead . " nearest of "
                                    . $this->hashSearchListed . " sessions" . ($inSales ? ", its hash is in the KSeF sales" : ", search not complete") . ": left PENDING, search "
                                    . $sub->getHashSearchNote()['attempts'] . ($expired ? ", the last one" : ", next after " . dol_print_date($sub->hashSearchDueAt(), 'dayhour')), LOG_WARNING);
                            }
                        } elseif ($found === 'error') {
                            $errors++;
                            $ambiguous = ($this->failureReason === 'ambiguous');
                            $this->stampHashSearch($sub, $ambiguous ? 'ambiguous' : 'error', $ambiguous && dol_now() >= $sub->hashSearchEndsAt());
                            if ($this->failureReason === 'gateway') {
                                $hashBudget = 0;
                                $pendingCtl['gateway'] = true;
                            }
                            continue;
                        }
                    } else {
                        $sub->date_last_check = dol_now();
                        $sub->update($user, 1, array($sub->status));
                    }

                    $processed++;

                } catch (Throwable $e) {
                    $sub->error_message = $e->getMessage();
                    $sub->date_last_check = dol_now();
                    $sub->update($user, 1, array(KsefSubmission::STATUS_PENDING, KsefSubmission::STATUS_SUBMITTED, KsefSubmission::STATUS_TIMEOUT));
                    $errors++;
                }
            }

            $this->output = "Processed: $processed, Errors: $errors" . ($uploaded ? ", Offline uploaded: $uploaded" : '') . ($fromSales ? ", Taken from KSeF sales: $fromSales" : '')
                . $this->cronRunNote($stats);
            if ($notRecorded) {
                $this->error = implode("\n", $notRecorded);
                $this->output .= "\n" . $this->error;
                return -1;
            }
            return ($errors > 0 && $processed == 0 && $uploaded == 0 && $fromSales == 0) ? -1 : 0;

        } catch (Throwable $e) {
            $this->error = $e->getMessage();
            $this->output = 'Fatal error: ' . $e->getMessage();
            return -1;
        } finally {
            $this->client->retry_wait_cap = $prevCap;
        }
    }

    /**
     * @brief Start cron clock
     * @return void
     */
    private function cronStartClock()
    {
        $budget = $this->cronTimeBudget === null ? (float) self::CRON_TIME_BUDGET : max(0.0, (float) $this->cronTimeBudget);
        $max = (int) ini_get('max_execution_time');
        if ($max > 0) {
            $used = isset($_SERVER['REQUEST_TIME_FLOAT']) ? max(0.0, microtime(true) - (float) $_SERVER['REQUEST_TIME_FLOAT']) : 0.0;
            $left = $max - $used;
            if ($left < $budget + self::CRON_TIME_MARGIN) {
                $raised = function_exists('set_time_limit') && @set_time_limit((int) ceil($budget + self::CRON_TIME_MARGIN));
                if (!$raised) {
                    $budget = min($budget, max(0.0, $left / 2));
                }
            }
        }
        $this->cronDeadline = microtime(true) + $budget;
    }

    /**
     * @brief Cron time left
     * @return bool
     */
    private function cronTimeLeft()
    {
        return microtime(true) < $this->cronDeadline;
    }

    /**
     * @brief Rate limit hit
     * @param int|null $since null = last call
     * @return bool
     */
    private function cronRateLimited($since = null)
    {
        if ($since !== null && $this->cronRateHits() > (int) $since) {
            return true;
        }
        return isset($this->client->last_http_code) && (int) $this->client->last_http_code === 429;
    }

    /**
     * @brief Client 429 count
     * @return int
     */
    private function cronRateHits()
    {
        return isset($this->client->rate_limited_hits) ? (int) $this->client->rate_limited_hits : 0;
    }

    /**
     * @brief Hash pause end
     * @return int 0 when none
     */
    public function hashSearchPausedUntil()
    {
        return (int) getDolGlobalInt('KSEF_HASH_SEARCH_PAUSE_UNTIL');
    }

    /**
     * @brief Pause hash search
     * @param int $seconds 0 = 60 s
     * @return void
     */
    private function pauseHashSearch($seconds)
    {
        global $conf;
        $seconds = (int) $seconds > 0 ? (int) $seconds : 60;
        dolibarr_set_const($this->db, 'KSEF_HASH_SEARCH_PAUSE_UNTIL', (string) (dol_now() + $seconds), 'chaine', 0, '', $conf->entity);
        dol_syslog("KSEF::pauseHashSearch hash search rate limited: no hash search before " . dol_print_date(dol_now() + $seconds, 'dayhourlog'), LOG_WARNING);
    }

    /**
     * @brief Next older batch
     * @param KsefSubmission $submission
     * @param string $environment
     * @param array $ctl Batch control
     * @param int $processed
     * @param int $errors
     * @param int $hashBudget Searches left
     * @param array $hashPick
     * @param array $stats
     * @return array Empty stops run
     */
    private function cronNextPendingBatch($submission, $environment, &$ctl, $processed, $errors, &$hashBudget, &$hashPick, &$stats)
    {
        $progress = $processed > $ctl['processed'];
        $failed = $errors > $ctl['errors'];
        $ctl['processed'] = $processed;
        $ctl['errors'] = $errors;
        if (!$progress && $failed) {
            $stats['stop']['older'] = 'no_progress';
            return array();
        }
        if (!$this->cronTimeLeft()) {
            $stats['stop']['older'] = 'time';
            return array();
        }
        if ($this->cronRateLimited($ctl['rate_mark'])) {
            $stats['stop']['older'] = 'rate_limit';
            return array();
        }
        $ctl['rate_mark'] = $this->cronRateHits();
        $next = array();
        $exclude = array_keys($ctl['handled']);
        $older = $submission->fetchUnresolvedOlder($environment, self::CRON_UNRESOLVED_BATCH, 86400, $exclude);
        $noRef = (empty($ctl['gateway']) && $hashBudget > 0) ? $submission->fetchNoRefOlder($environment, $hashBudget, 86400, $exclude) : array();
        foreach (array_merge(is_array($older) ? $older : array(), is_array($noRef) ? $noRef : array()) as $sub) {
            if (!isset($ctl['handled'][(int) $sub->rowid])) {
                $ctl['handled'][(int) $sub->rowid] = true;
                $next[(int) $sub->rowid] = $sub;
            }
        }
        if (!$next) {
            return array();
        }
        $stats['older']++;
        $hashPick = $this->cronHashSearchPicks($next, $hashBudget);
        return array_values($next);
    }

    /**
     * @brief Cron output suffix
     * @param array $stats
     * @return string
     */
    private function cronRunNote($stats)
    {
        $out = '';
        $batches = array();
        foreach (array('offline' => 'offline uploads', 'sales' => 'KSeF sales', 'older' => 'older sends') as $k => $label) {
            if ((int) $stats[$k] > ($k === 'older' ? 0 : 1)) {
                $batches[] = $label . ' ' . (int) $stats[$k];
            }
        }
        if ($batches) {
            $out .= ", Batches: " . implode(', ', $batches);
        }
        if (!empty($stats['held_back'])) {
            $out .= ", Held back (original without a KSeF number): " . count($stats['held_back']);
        }
        if (!empty($stats['stop'])) {
            $why = array();
            foreach ($stats['stop'] as $queue => $reason) {
                $why[] = $queue . ' (' . $reason . ')';
            }
            $out .= ", Stopped before the queue was empty: " . implode(', ', $why);
        }
        return $out;
    }

    /**
     * @brief Cron hash search
     * @param KsefSubmission $sub
     * @param User $user
     * @param array $notRecorded Appended
     * @param int $hashBudget Searches left
     * @return string found, notfound, error
     */
    private function cronHashSearch($sub, $user, &$notRecorded, &$hashBudget = null)
    {
        $prev = $this->hashSearchSessions;
        $this->hashSearchSessions = self::CRON_HASH_SEARCH_SESSIONS;
        $mark = $this->cronRateHits();
        try {
            $r = $this->refreshSubmissionStatus($sub, $user, true);
        } finally {
            $this->hashSearchSessions = $prev;
        }
        if ($this->hashSearchRateLimited || $this->cronRateHits() > $mark) {
            $hashBudget = 0;
            $this->hashSearchPaused = true;
            if (!$this->hashSearchRateLimited) {
                // refresh stored it
                $this->pauseHashSearch(isset($this->client->retry_after_seconds) ? (int) $this->client->retry_after_seconds : 0);
            }
        }
        if ($r !== false) {
            dol_syslog("KSEF::cronCheckStatuses row " . (int) $sub->rowid . " found by its hash in session " . $sub->getSessionReference() . ": " . ($r['status'] ?? ''), LOG_INFO);
            return 'found';
        }
        if ($this->notRecordedNumber !== '') {
            $notRecorded[] = $this->error;
        }
        if ($this->failureReason === 'notfound') {
            dol_syslog("KSEF::cronCheckStatuses row " . (int) $sub->rowid . " not found by its hash: " . $this->error, LOG_INFO);
            return 'notfound';
        }
        dol_syslog("KSEF::cronCheckStatuses row " . (int) $sub->rowid . " hash search failed (" . $this->failureReason . "): " . $this->error, LOG_WARNING);
        return 'error';
    }

    /**
     * @brief Hash search candidate
     * @param KsefSubmission $sub
     * @return bool
     */
    private function cronHashSearchCandidate($sub)
    {
        return $sub->status == KsefSubmission::STATUS_PENDING && empty($sub->offline_mode) && !empty($sub->invoice_hash)
            && $sub->getSessionReference() === '' && $sub->isStale();
    }

    /**
     * @brief Hash search rows
     * @param array $rows
     * @param int $budget Searches left
     * @return array rowid => true
     */
    private function cronHashSearchPicks($rows, $budget)
    {
        $due = array();
        $now = dol_now();
        foreach ($rows as $sub) {
            if (!$this->cronHashSearchCandidate($sub)) {
                continue;
            }
            $at = $sub->hashSearchDueAt();
            if ($at > $now || !$sub->onlyFailedSendsAfter()) {
                continue;
            }
            $due[] = array('id' => (int) $sub->rowid, 'at' => $at, 'sent' => (int) $sub->date_submission);
        }
        usort($due, function ($a, $b) {
            return array($a['at'], $a['sent'], $a['id']) <=> array($b['at'], $b['sent'], $b['id']);
        });
        $picks = array();
        foreach (array_slice($due, 0, max(0, (int) $budget)) as $d) {
            $picks[$d['id']] = true;
        }
        return $picks;
    }

    /**
     * @brief Stamp hash search
     * @param KsefSubmission $sub
     * @param string $outcome
     * @param bool $final Last search
     * @return void
     */
    private function stampHashSearch($sub, $outcome, $final = false)
    {
        $now = dol_now();
        $res = $this->db->query("SELECT error_details FROM " . MAIN_DB_PREFIX . "ksef_submissions WHERE rowid = " . (int) $sub->rowid
            . " AND status = '" . KsefSubmission::STATUS_PENDING . "'");
        $o = $res ? $this->db->fetch_object($res) : null;
        if (!$o) {
            return;
        }
        $stored = new KsefSubmission($this->db);
        $stored->error_details = $o->error_details;
        $details = $stored->getErrorDetailsArray() ?: array();
        $prev = $stored->getHashSearchNote();
        $counted = ($outcome !== 'error');
        $details['hash_search'] = array(
            'attempts' => $prev['attempts'] + ($counted ? 1 : 0),
            'read' => $counted ? (int) $this->hashSearchRead : $prev['read'],
            'listed' => $counted ? (int) $this->hashSearchListed : $prev['listed'],
            'complete' => ($counted && $this->hashSearchComplete) ? 1 : 0,
            'outcome' => (string) $outcome,
            'date' => $now,
            'seen' => $prev['seen'],
        );
        if ($outcome === 'ambiguous') {
            $details['hash_search']['matches'] = (int) $this->hashSearchMatches;
        }
        $message = null;
        if ($final) {
            $details['hash_search']['final'] = 1;
            $probe = new KsefSubmission($this->db);
            $probe->status = KsefSubmission::STATUS_PENDING;
            $probe->environment = $sub->environment;
            $probe->invoice_hash = $sub->invoice_hash;
            $probe->error_details = json_encode($details);
            $message = ksefHashStopHint($this->db, $probe);
        }
        $json = json_encode($details);
        $this->db->query("UPDATE " . MAIN_DB_PREFIX . "ksef_submissions SET date_last_check = " . (int) $now . ", error_details = '" . $this->db->escape($json) . "'"
            . ($message !== null && $message !== '' ? ", error_message = '" . $this->db->escape($message) . "'" : '')
            . " WHERE rowid = " . (int) $sub->rowid . " AND status = '" . KsefSubmission::STATUS_PENDING . "'");
        $sub->date_last_check = $now;
        $sub->error_details = $json;
        if ($message !== null && $message !== '') {
            $sub->error_message = $message;
        }
    }

    /**
     * @brief Session reference key
     * @param string $ref
     * @return string
     */
    private function hashSearchSessionKey($ref)
    {
        return substr(md5((string) $ref), 0, 12);
    }

    /**
     * @brief Note sessions read
     * @param KsefSubmission $sub
     * @param array $read Sessions read
     * @return void
     */
    private function rememberSessionsRead($sub, $read)
    {
        if (!$read || empty($sub->rowid)) {
            return;
        }
        $open = "'" . KsefSubmission::STATUS_PENDING . "', '" . KsefSubmission::STATUS_SUBMITTED . "', '" . KsefSubmission::STATUS_TIMEOUT . "'";
        $where = " WHERE rowid = " . (int) $sub->rowid . " AND status IN (" . $open . ") AND offline_mode IS NULL";
        $res = $this->db->query("SELECT error_details FROM " . MAIN_DB_PREFIX . "ksef_submissions" . $where);
        $o = $res ? $this->db->fetch_object($res) : null;
        if (!$o) {
            return;
        }
        $stored = new KsefSubmission($this->db);
        $stored->error_details = $o->error_details;
        $details = $stored->getErrorDetailsArray() ?: array();
        $note = (isset($details['hash_search']) && is_array($details['hash_search'])) ? $details['hash_search'] : array();
        $seen = $stored->getHashSearchNote()['seen'];
        foreach ($read as $c) {
            $seen[] = $this->hashSearchSessionKey($c['ref']);
        }
        $note['seen'] = array_slice(array_values(array_unique($seen)), -self::HASH_SEARCH_LIST_PAGES * 1000);
        $details['hash_search'] = $note;
        $json = json_encode($details);
        if ($this->db->query("UPDATE " . MAIN_DB_PREFIX . "ksef_submissions SET error_details = '" . $this->db->escape($json) . "'" . $where)) {
            $sub->error_details = $json;
        }
    }

    /**
     * @brief Adopt synced sale
     * @param KsefSubmission $sub
     * @param User $user
     * @return int 1 accepted, 0 none, -1 error
     */
    private function adoptFromSales($sub, $user)
    {
        global $langs;
        $open = array(KsefSubmission::STATUS_PENDING, KsefSubmission::STATUS_SUBMITTED, KsefSubmission::STATUS_TIMEOUT);
        if (empty($sub->invoice_hash) || !empty($sub->offline_mode) || $sub->getSessionReference() !== '' || !in_array((string) $sub->status, $open, true)) {
            return 0;
        }
        $env = (string) ($sub->environment ?: ksefGetEnvironment());
        $res = $this->db->query("SELECT rowid, ksef_number, seller_nip, invoice_number, fk_facture, entity FROM " . MAIN_DB_PREFIX . "ksef_sales"
            . " WHERE invoice_hash = '" . $this->db->escape($sub->invoice_hash) . "' AND environment = '" . $this->db->escape($env) . "'");
        $rows = array();
        while ($res && ($o = $this->db->fetch_object($res))) {
            $rows[] = $o;
        }
        if (!$rows) {
            return 0;
        }
        $why = '';
        $s = $rows[0];
        $number = trim((string) $s->ksef_number);
        $inv = new Facture($this->db);
        if (count($rows) > 1) {
            $why = count($rows) . ' sales invoices carry the hash';
        } elseif ($inv->fetch((int) $sub->fk_facture) <= 0) {
            $why = 'invoice ' . (int) $sub->fk_facture . ' not found';
        } elseif (!ksefIsKsefNumber($number)) {
            $why = 'not a KSeF number: ' . $number;
        } elseif ((int) $s->entity !== (int) $inv->entity) {
            $why = 'sales entity ' . (int) $s->entity . ', invoice entity ' . (int) $inv->entity;
        } elseif (ksefCleanNIP((string) $s->seller_nip) === '' || ksefCleanNIP((string) $s->seller_nip) !== ksefSellerNip()) {
            $why = 'seller NIP ' . (string) $s->seller_nip . ' is not this company';
        } elseif (trim((string) $s->invoice_number) === '' || trim((string) $s->invoice_number) !== trim((string) $inv->ref)) {
            $why = 'invoice number ' . (string) $s->invoice_number . ', ref ' . $inv->ref;
        } elseif ((int) $s->fk_facture > 0 && (int) $s->fk_facture !== (int) $sub->fk_facture) {
            $why = 'tied to invoice ' . (int) $s->fk_facture;
        } elseif (!$sub->isLatestOfInvoice()) {
            $why = 'a newer send of the invoice exists';
        } else {
            $used = $this->db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "ksef_submissions WHERE ksef_number = '" . $this->db->escape($number) . "'"
                . " AND status = 'ACCEPTED' AND rowid <> " . (int) $sub->rowid . $this->db->plimit(1));
            $ef = $this->db->query("SELECT ksef_number FROM " . MAIN_DB_PREFIX . "facture_extrafields WHERE fk_object = " . (int) $sub->fk_facture);
            $efo = $ef ? $this->db->fetch_object($ef) : null;
            if (!$used) {
                $why = 'number check failed: ' . $this->db->lasterror();
            } elseif ($this->db->num_rows($used) > 0) {
                $why = 'number on another accepted row';
            } elseif ($efo && trim((string) $efo->ksef_number) !== '' && trim((string) $efo->ksef_number) !== $number) {
                $why = 'the invoice carries KSeF number ' . trim((string) $efo->ksef_number);
            }
        }
        if ($why !== '') {
            dol_syslog("KSEF::adoptFromSales row " . (int) $sub->rowid . " hash found in the KSeF sales (" . $number . "), number not taken: " . $why, LOG_WARNING);
            return 0;
        }
        $sub->source = KsefSubmission::SOURCE_LINKED_FROM_SYNC;
        $r = $this->acceptSubmission($sub, array('ksef_number' => $number, 'sales_id' => (int) $s->rowid), $user, $open);
        if ($r === 0) {
            $sub->fetch($sub->rowid);
            return $sub->status === KsefSubmission::STATUS_ACCEPTED ? 1 : 0;
        }
        if ($r < 0) {
            $langs->load('ksef@ksef');
            $this->notRecordedNumber = $number;
            $this->error = $langs->transnoentitiesnoconv('KSEF_AcceptedButNotRecorded', $number);
            dol_syslog("KSEF::adoptFromSales row " . (int) $sub->rowid . " number $number from the KSeF sales not saved: " . $sub->error, LOG_ERR);
            return -1;
        }
        dol_include_once('/ksef/class/ksef_sales.class.php');
        $sales = new KsefSales($this->db);
        if ($sales->fetch((int) $s->rowid) > 0) {
            $sales->reconcile($user, false);
        }
        dol_syslog("KSEF::adoptFromSales row " . (int) $sub->rowid . " took KSeF number $number from the KSeF sales (sales row " . (int) $s->rowid . ")", LOG_INFO);
        return 1;
    }

    /**
     * @brief Hash in sales
     * @param KsefSubmission $sub
     * @return bool
     */
    private function hashInSales($sub)
    {
        if (empty($sub->invoice_hash)) {
            return false;
        }
        $res = $this->db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "ksef_sales WHERE invoice_hash = '" . $this->db->escape($sub->invoice_hash) . "'"
            . " AND environment = '" . $this->db->escape((string) ($sub->environment ?: ksefGetEnvironment())) . "'" . $this->db->plimit(1));
        return $res && $this->db->num_rows($res) > 0;
    }

    /**
     * @brief Cron sync
     * @return int 0 OK, <0 error
     */
    public function cronSyncIncoming()
    {
        global $user;
        dol_syslog("KSEF::cronSyncIncoming START", LOG_INFO);
        $prevCap = $this->client->retry_wait_cap;
        $this->client->retry_wait_cap = self::CRON_RETRY_WAIT_CAP;
        $rcIn = $this->cronSyncDirection(self::SYNC_INCOMING);
        $outIn = $this->output;
        $errIn = $this->error;
        $rcOut = $this->cronSyncDirection(self::SYNC_OUTGOING);
        $outOut = $this->output;
        $upo = $this->syncUpoSessions($user);
        $this->client->retry_wait_cap = $prevCap;
        $this->output = $outIn . "\nOutgoing: " . $outOut
            . "\nUPO: " . ($upo['status'] === 'SKIPPED' ? 'Skipped: already running' : "matched " . $upo['matched'] . ", cached " . $upo['invoices'] . " from " . $upo['sessions']
            . " session(s), downloaded " . $upo['upo'] . ", still without session " . $upo['missing_session'] . ($upo['warning'] !== '' ? ". " . $upo['warning'] : ''));
        if ($rcIn < 0) {
            $this->error = $errIn;
        }
        return min($rcIn, $rcOut);
    }

    /**
     * @brief Cron sync direction
     * @param string $direction
     * @return int 0 OK, <0 error
     */
    private function cronSyncDirection($direction)
    {
        global $conf, $user;

        $this->output = '';
        $this->error = '';

        try {
            dol_include_once('/ksef/class/ksef_sync_state.class.php');
            dol_include_once('/ksef/lib/ksef.lib.php');

            $syncState = new KsefSyncState($this->db);
            $syncState->load($direction);
            // export budget per-NIP
            $other = new KsefSyncState($this->db);
            $other->load($direction === self::SYNC_OUTGOING ? self::SYNC_INCOMING : self::SYNC_OUTGOING);
            if ($syncState->canSyncNow() && $other->isRateLimited()) {
                $this->output = 'Skipped: Rate limited until ' . $other->getRateLimitExpiryFormatted();
                return 0;
            }

            $resume = $syncState->canResumeFetch();
            if (!$resume && !$syncState->canSyncNow()) {
                $reason = '';
                if ($syncState->isFetchInProgress()) {
                    $reason = 'Fetch already in progress';
                } elseif ($syncState->isProcessingInProgress()) {
                    $reason = 'Processing already in progress';
                } elseif ($syncState->isRateLimited()) {
                    $reason = 'Rate limited until '
                        . $syncState->getRateLimitExpiryFormatted();
                }
                $this->output = 'Skipped: ' . $reason;
                return 0;
            }

            if ($direction === self::SYNC_OUTGOING && !$resume) {
                // once per run
                $this->reconcileSales(ksefGetEnvironment());
            }
            $initResult = $this->initIncomingFetch($user, $direction);

            if (!$initResult) {
                $this->error = $this->error ?: 'Failed to initiate fetch';
                $this->output = 'Init failed: ' . $this->error;
                return -1;
            }

            $waitFirst = true;
            if ($initResult['status'] === 'ALREADY_PROCESSING') {
                dol_syslog("KSEF::cronSyncIncoming fetch already initiated, polling", LOG_INFO);
                $waitFirst = false;
            }

            $maxWait = 300;
            $elapsed = 0;
            $interval = 30;

            while ($elapsed < $maxWait) {
                if ($waitFirst) {
                    ksefSleep($interval);
                }
                $waitFirst = true;
                $elapsed += $interval;

                $result = $this->checkIncomingFetchStatus($user, $direction);

                if (empty($result) || empty($result['status'])) {
                    $this->error = 'Empty status response';
                    $this->output = 'Polling failed: empty status';
                    return -1;
                }

                $status = $result['status'];

                if ($status === 'READY_TO_PROCESS') {
                    dol_syslog("KSEF::cronSyncIncoming READY_TO_PROCESS, starting download+process", LOG_INFO);
                    $processResult = $this->processIncomingDownload($user, $direction);
                    if (($processResult['status'] ?? '') === 'SKIPPED') {
                        $this->output = 'Skipped: ' . ($processResult['reason'] ?? 'Processing already in progress');
                        return 0;
                    }
                    $new = $processResult['new'] ?? 0;
                    $existing = $processResult['existing'] ?? 0;
                    $total = $processResult['total'] ?? ($new + $existing);
                    if (in_array($processResult['status'], array('COMPLETED'))) {
                        $this->output = "Sync complete. New: $new, Existing: $existing, Total: $total";
                        if (!empty($processResult['fetch_errors'])) {
                            $this->output .= ". Fetch errors: " . (int) $processResult['fetch_errors'] . ($direction === self::SYNC_OUTGOING ? " (stored, see KSeF submissions)" : " (stored, see incoming list)");
                        }
                        if (isset($processResult['audit_missing']) && $processResult['audit_missing'] !== null) {
                            $this->output .= ". Completeness check: " . (int) $processResult['audit_missing'] . " missing found";
                        }
                        if (!empty($processResult['unsupported'])) {
                            $this->output .= ". Unsupported forms: " . (int) $processResult['unsupported'] . " (stored, not importable)";
                        }
                        return 0;
                    } else {
                        $this->error = $processResult['error'] ?? $processResult['status'];
                        $this->output = 'Processing failed: ' . $this->error;
                        return -1;
                    }
                }

                if ($status === 'COMPLETED') {
                    $new = $result['new'] ?? 0;
                    $existing = $result['existing'] ?? 0;
                    $total = $result['total'] ?? ($new + $existing);
                    $this->output = "Sync complete. New: $new, "
                        . "Existing: $existing, Total: $total";
                    return 0;
                }

                if (in_array($status, array(
                    'FAILED', 'TIMEOUT', 'DOWNLOAD_FAILED', 'CHECK_FAILED'
                ))) {
                    $this->error = $result['error'] ?? $status;
                    $this->output = 'Sync failed: ' . $this->error;
                    return -1;
                }

                if ($status === 'RATE_LIMITED') {
                    $this->output = 'Rate limited, will resume on next run';
                    return 0;
                }

                if (in_array($status, array('DOWNLOADING', 'PROCESSING_BATCHES'))) {
                    dol_syslog("KSEF::cronSyncIncoming status=$status, waiting for background process to finish", LOG_INFO);
                    ksefSleep(5);
                }
            }

            $this->output = 'Sync timed out after ' . $maxWait
                . 's, will resume on next run';
            return 0;

        } catch (Throwable $e) {
            $this->error = $e->getMessage();
            $this->output = 'Fatal error: ' . $e->getMessage();
            return -1;
        }
    }

    /**
     * @brief Cron UPO download
     * @return int 0 OK, <0 error
     */
    public function cronDownloadUPOs()
    {
        global $user;

        dol_syslog("KSEF::cronDownloadUPOs START", LOG_INFO);
        $this->output = '';
        $prevCap = $this->client->retry_wait_cap;
        $this->client->retry_wait_cap = self::CRON_RETRY_WAIT_CAP;

        try {
            $this->cronStartClock();
            $total = array('downloaded' => 0, 'failed' => 0, 'copied' => 0);
            $handled = array();
            $batches = 0;
            $stop = '';
            while (true) {
                if ($batches > 0 && !$this->cronTimeLeft()) {
                    $stop = 'time';
                    break;
                }
                $r = $this->downloadMissingUpos($user, self::CRON_UPO_BATCH, array_keys($handled));
                if ($r === false) {
                    $this->output = 'Query error: ' . $this->error;
                    return -1;
                }
                $total['copied'] += (int) $r['copied'];
                if (empty($r['ids'])) {
                    break;
                }
                $batches++;
                foreach ($r['ids'] as $id) {
                    $handled[(int) $id] = true;
                }
                $total['downloaded'] += $r['downloaded'];
                $total['failed'] += $r['failed'];
                if ($r['rate_limited']) {
                    $stop = 'rate_limit';
                    break;
                }
                if (count($r['ids']) < self::CRON_UPO_BATCH) {
                    break;
                }
                if ($r['downloaded'] === 0 && $r['failed'] > 0) {
                    $stop = 'no_progress';
                    break;
                }
            }
            $this->output = "Downloaded: " . $total['downloaded'] . ", Failed: " . $total['failed'] . ($total['copied'] > 0 ? ", Copied from a row of the same KSeF number: " . $total['copied'] : '')
                . ($batches > 1 ? ", Batches: " . $batches : '')
                . ($stop !== '' ? ", Stopped before the queue was empty: " . $stop : '');
            return 0;

        } catch (Throwable $e) {
            $this->error = $e->getMessage();
            $this->output = 'Fatal error: ' . $e->getMessage();
            return -1;
        } finally {
            $this->client->retry_wait_cap = $prevCap;
        }
    }

    /**
     * @brief Download missing UPOs
     * @param User $user
     * @param int $limit Rows per call
     * @param array $exclude Ids to skip
     * @return array|false false on error
     */
    public function downloadMissingUpos($user, $limit, $exclude = array())
    {
        $exclude = array_filter(array_map('intval', (array) $exclude));
        dol_include_once('/ksef/class/ksef_submission.class.php');
        $copied = $this->fillUposFromSameNumber();
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "ksef_submissions"
            . " WHERE status = 'ACCEPTED'"
            . " AND upo_xml IS NULL"
            . " AND ksef_number IS NOT NULL"
            . " AND ((ksef_session_reference IS NOT NULL AND ksef_session_reference <> '') OR api_response LIKE '%-SO-%' OR api_response LIKE '%essionReference%')"
            . " AND environment = '" . $this->db->escape(ksefGetEnvironment()) . "'"
            . ($exclude ? " AND rowid NOT IN (" . implode(',', $exclude) . ")" : '')
            . " ORDER BY COALESCE(date_last_check, 0) ASC, date_acceptance ASC";
        $resql = $this->db->query($sql . $this->db->plimit(max(1, (int) $limit)));
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return false;
        }
        $ids = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $ids[] = (int) $obj->rowid;
        }
        $this->db->free($resql);

        $r = array('downloaded' => 0, 'failed' => 0, 'copied' => $copied, 'rate_limited' => false, 'ids' => $ids);
        foreach ($ids as $id) {
            $sub = new KsefSubmission($this->db);
            if ($sub->fetch($id) <= 0 || empty($sub->ksef_number) || $sub->getSessionReference() === '') {
                continue;
            }
            if (!empty($sub->upo_xml)) {
                $r['copied']++;
                continue;
            }
            $same = $this->upoOfSameNumber($sub);
            if ($same !== '') {
                $sub->upo_xml = $same;
                if ($sub->update($user, 0, array(KsefSubmission::STATUS_ACCEPTED)) > 0) {
                    $r['copied']++;
                    continue;
                }
            }
            try {
                if ($this->downloadUPOForSubmission($sub, $user) !== false) {
                    $r['downloaded']++;
                    continue;
                }
            } catch (Throwable $e) {
                $this->error = $e->getMessage();
            }
            dol_syslog("KSEF::downloadMissingUpos error for " . $sub->ksef_number . ": " . $this->error, LOG_ERR);
            $r['failed']++;
            if ((int) $this->client->last_http_code === 429) {
                $r['rate_limited'] = true;
                break;
            }
            $this->db->query("UPDATE " . MAIN_DB_PREFIX . "ksef_submissions SET date_last_check = " . (int) dol_now() . " WHERE rowid = " . (int) $id);
        }
        return $r;
    }

    /**
     * @brief Outgoing UPO step
     * @param User $user
     * @param array $limits Cache limit overrides
     * @return array
     */
    public function syncUpoSessions($user, $limits = array())
    {
        global $langs;
        $langs->load('ksef@ksef');
        dol_include_once('/ksef/class/ksef_session_cache.class.php');
        $environment = ksefGetEnvironment();
        $out = array('status' => 'OK', 'matched' => 0, 'sessions' => 0, 'invoices' => 0, 'upo' => 0, 'upo_failed' => 0, 'missing_session' => 0, 'until' => 0, 'warning' => '');
        $cache = new KsefSessionCache($this->db);
        $cache->limits = array_merge($cache->limits, (array) $limits);
        $state = $cache->loadState($environment);
        if (!$state->claimRun(KsefSessionCache::RUN_STALE_SECONDS)) {
            $out['status'] = 'SKIPPED';
            return $out;
        }
        $warnings = array();
        try {
            $out['matched'] = $cache->matchSubmissions($environment);
            if ($cache->missingSessionCount($environment) > 0) {
                if ($state->isRateLimited()) {
                    $warnings[] = $langs->transnoentitiesnoconv('KSEF_UpoSweepRateLimited', $state->getRateLimitExpiryFormatted());
                } else {
                    $sw = $cache->sweep($this->client, $state, $environment);
                    $out['sessions'] = $sw['sessions'];
                    $out['invoices'] = $sw['invoices'];
                    if ($sw['rate_limited']) {
                        $warnings[] = $langs->transnoentitiesnoconv('KSEF_UpoSweepRateLimited', $state->getRateLimitExpiryFormatted());
                    } elseif ($sw['error'] !== '') {
                        $warnings[] = $langs->transnoentitiesnoconv('KSEF_UpoSweepFailed', $sw['error']);
                    }
                    $out['matched'] += $cache->matchSubmissions($environment);
                }
            }
            $up = (int) $cache->limits['upo'] > 0 ? $this->downloadMissingUpos($user, $cache->limits['upo']) : array('downloaded' => 0, 'failed' => 0, 'rate_limited' => false);
            if ($up === false) {
                $warnings[] = $langs->transnoentitiesnoconv('KSEF_UpoSweepFailed', $this->error);
            } else {
                $out['upo'] = $up['downloaded'];
                $out['upo_failed'] = $up['failed'];
                if ($up['rate_limited']) {
                    $warnings[] = $langs->transnoentitiesnoconv('KSEF_UpoSweepUpoRateLimited');
                }
            }
        } catch (Throwable $e) {
            $warnings[] = $langs->transnoentitiesnoconv('KSEF_UpoSweepFailed', $e->getMessage());
        }
        $out['missing_session'] = $cache->missingSessionCount($environment);
        $out['until'] = (int) $state->getContinuationDate();
        $out['warning'] = implode(' ', $warnings);
        if ($out['warning'] !== '') {
            dol_syslog("KSEF::syncUpoSessions " . $out['warning'], LOG_WARNING);
        }
        $state->last_sync = dol_now();
        $state->last_sync_errors = $out['warning'];
        $state->saveSweep();
        $state->releaseRun();
        dol_syslog("KSEF::syncUpoSessions sessions " . $out['sessions'] . ", cached " . $out['invoices'] . ", matched " . $out['matched'] . ", UPO " . $out['upo'] . ", still without session " . $out['missing_session'], LOG_INFO);
        return $out;
    }

    /**
     * @brief Cron offline deadlines
     * @return int 0 OK, <0 error
     */
    public function cronWarnDeadlines()
    {
        global $conf;

        dol_syslog("KSEF::cronWarnDeadlines START", LOG_INFO);
        $this->output = '';

        try {
            dol_include_once('/ksef/class/ksef_submission.class.php');

            $submission = new KsefSubmission($this->db);
            $due = $submission->fetchCronJobs(24, ksefGetEnvironment(), true);
            if ($due === false) {
                $this->error = $submission->error;
                $this->output = 'Error: ' . $submission->error;
                return -1;
            }
            $due = ksefEffectiveDueRows($this->db, $due, 24);

            $events = $this->syncOfflineDeadlineEvents($due);
            $eventsOut = ($events['created'] || $events['closed'] || $events['moved'])
                ? ", agenda events created: " . $events['created'] . ", closed: " . $events['closed'] . ", moved: " . $events['moved'] : '';

            if (count($due) == 0) {
                $this->output = 'No approaching deadlines' . $eventsOut;
                return 0;
            }

            $now = dol_now();
            $overdue = 0;
            $approaching = 0;
            foreach ($due as $sub) {
                $hours = round(($sub->offline_deadline - $now) / 3600, 1);
                if ($hours < 0) {
                    dol_syslog("KSEF DEADLINE OVERDUE: Submission #" . $sub->rowid . " (invoice_id=" . $sub->fk_facture
                        . ", status " . $sub->status . ") passed its offline deadline " . abs($hours) . " hours ago, send it now", LOG_ERR);
                    $overdue++;
                } else {
                    dol_syslog("KSEF DEADLINE WARNING: Submission #" . $sub->rowid . " (invoice_id=" . $sub->fk_facture
                        . ", status " . $sub->status . ") has offline deadline in " . $hours . " hours", LOG_WARNING);
                    $approaching++;
                }
            }

            $this->output = "Deadlines approaching: $approaching invoices, overdue: $overdue invoices" . $eventsOut;
            return 0;

        } catch (Throwable $e) {
            $this->error = $e->getMessage();
            $this->output = 'Fatal error: ' . $e->getMessage();
            return -1;
        }
    }

    /**
     * @brief Sync deadline events
     * @param array $due Due rows
     * @return array Counts
     */
    public function syncOfflineDeadlineEvents($due)
    {
        global $conf, $langs, $user;

        $out = array('created' => 0, 'closed' => 0, 'moved' => 0);
        if (!isModEnabled('agenda')) {
            return $out;
        }
        $code = 'AC_KSEF_OFFLINE_DUE';
        $t = MAIN_DB_PREFIX . 'ksef_submissions';

        $res = $this->db->query("SELECT a.id FROM " . MAIN_DB_PREFIX . "actioncomm as a"
            . " WHERE a.code = '" . $code . "' AND a.elementtype = 'invoice' AND a.percent < 100 AND a.entity = " . (int) $conf->entity
            . " AND NOT EXISTS (SELECT 1 FROM " . $t . " s WHERE s.fk_facture = a.fk_element AND s.status <> 'ACCEPTED'"
            . " AND NOT EXISTS (SELECT 1 FROM " . $t . " n WHERE n.fk_facture = s.fk_facture AND n.environment = s.environment AND n.rowid > s.rowid))");
        $closeIds = array();
        while ($res && ($obj = $this->db->fetch_object($res))) {
            $closeIds[] = (int) $obj->id;
        }

        $langs->load('ksef@ksef');
        $res = $this->db->query("SELECT a.id, a.datep, a.fk_element, s.offline_mode, s.offline_deadline, s.environment, f.ref FROM " . MAIN_DB_PREFIX . "actioncomm as a"
            . " INNER JOIN " . $t . " s ON s.fk_facture = a.fk_element AND s.status <> 'ACCEPTED'"
            . " AND NOT EXISTS (SELECT 1 FROM " . $t . " n WHERE n.fk_facture = s.fk_facture AND n.environment = s.environment AND n.rowid > s.rowid)"
            . " INNER JOIN " . MAIN_DB_PREFIX . "facture f ON f.rowid = a.fk_element"
            . " WHERE a.code = '" . $code . "' AND a.elementtype = 'invoice' AND a.percent < 100 AND a.entity = " . (int) $conf->entity);
        $open = array();
        while ($res && ($obj = $this->db->fetch_object($res))) {
            $id = (int) $obj->id;
            if (!isset($open[$id])) {
                $open[$id] = array('obj' => $obj, 'deadline' => null);
            }
            if (empty($obj->offline_mode) || empty($obj->offline_deadline)) {
                continue;
            }
            if ((string) $obj->environment !== 'PRODUCTION' && ksefProductionIssuedBlock($this->db, (int) $obj->fk_element, (string) $obj->environment)) {
                continue;
            }
            $info = ksefOfflineDeadlineForInvoice($this->db, $obj->fk_element, $obj->environment, (int) $obj->offline_deadline);
            if ($info['state'] === 'due' && ($open[$id]['deadline'] === null || $info['deadline'] < $open[$id]['deadline'])) {
                $open[$id]['deadline'] = (int) $info['deadline'];
            }
        }
        foreach ($open as $id => $ev) {
            $obj = $ev['obj'];
            if ($ev['deadline'] === null) {
                $closeIds[] = $id;
                continue;
            }
            if ($ev['deadline'] === (int) $this->db->jdate($obj->datep)) {
                continue;
            }
            $note = $langs->transnoentities('KSEF_OfflineDueEventNote', $obj->ref, dol_print_date($ev['deadline'], 'dayhour'));
            if ($this->db->query("UPDATE " . MAIN_DB_PREFIX . "actioncomm SET datep = '" . $this->db->idate($ev['deadline']) . "', datep2 = '" . $this->db->idate($ev['deadline']) . "',"
                . " note = '" . $this->db->escape($note) . "' WHERE id = " . (int) $id)) {
                $out['moved']++;
            }
        }

        foreach ($closeIds as $id) {
            if ($this->db->query("UPDATE " . MAIN_DB_PREFIX . "actioncomm SET percent = 100 WHERE id = " . $id)) {
                $this->db->query("UPDATE " . MAIN_DB_PREFIX . "actioncomm_reminder SET status = 1 WHERE fk_actioncomm = " . $id . " AND status = 0");
                $out['closed']++;
            }
        }

        if (!getDolGlobalInt('KSEF_OFFLINE_DEADLINE_AGENDA') || empty($due)) {
            return $out;
        }

        require_once DOL_DOCUMENT_ROOT . '/comm/action/class/actioncomm.class.php';
        require_once DOL_DOCUMENT_ROOT . '/comm/action/class/actioncommreminder.class.php';
        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
        $langs->load('ksef@ksef');
        $channels = array();
        if (getDolGlobalString('AGENDA_REMINDER_BROWSER')) {
            $channels[] = 'browser';
        }
        if (getDolGlobalString('AGENDA_REMINDER_EMAIL')) {
            $channels[] = 'email';
        }
        $now = dol_now();

        foreach ($due as $sub) {
            $fk = (int) $sub->fk_facture;
            $res = $this->db->query("SELECT id FROM " . MAIN_DB_PREFIX . "actioncomm WHERE code = '" . $code . "' AND elementtype = 'invoice'"
                . " AND fk_element = " . $fk . " AND percent < 100 AND entity = " . (int) $conf->entity);
            if (!$res || $this->db->num_rows($res) > 0) {
                continue;
            }
            $invoice = new Facture($this->db);
            if ($invoice->fetch($fk) <= 0) {
                continue;
            }
            $owner = (int) $sub->fk_user_submit;
            if ($owner <= 0) {
                $owner = !empty($invoice->fk_user_author) ? (int) $invoice->fk_user_author : (!empty($invoice->user_author) ? (int) $invoice->user_author : (int) $user->id);
            }
            $deadline = (int) $sub->offline_deadline;

            $ac = new ActionComm($this->db);
            $ac->type_code = 'AC_OTH_AUTO';
            $ac->code = $code;
            $ac->label = $langs->transnoentities('KSEF_OfflineDueEventLabel', $invoice->ref);
            $ac->note_private = $langs->transnoentities('KSEF_OfflineDueEventNote', $invoice->ref, dol_print_date($deadline, 'dayhour'));
            $ac->datep = $deadline;
            $ac->datef = $deadline;
            $ac->percentage = 0;
            $ac->elementtype = 'invoice';
            $ac->fk_element = $fk;
            $ac->socid = (int) $invoice->socid;
            $ac->userownerid = $owner;
            $ac->userassigned = array($owner => array('id' => $owner, 'transparency' => 0));
            $id = $ac->create($user);
            if ($id <= 0) {
                dol_syslog("KSEF::syncOfflineDeadlineEvents - invoice " . $invoice->ref . ": " . $ac->error, LOG_ERR);
                continue;
            }
            $out['created']++;
            foreach ($channels as $type) {
                $rem = new ActionCommReminder($this->db);
                $rem->dateremind = $now;
                $rem->typeremind = $type;
                $rem->fk_user = $owner;
                $rem->offsetunit = 'h';
                $rem->offsetvalue = max(0, (int) floor(($deadline - $now) / 3600));
                $rem->status = 0;
                $rem->fk_actioncomm = $id;
                if ($rem->create($user) <= 0) {
                    dol_syslog("KSEF::syncOfflineDeadlineEvents - reminder $type for invoice " . $invoice->ref . ": " . $rem->error, LOG_ERR);
                }
            }
        }
        return $out;
    }

    /**
     * @brief Cron Latarnia refresh
     * @return int 0 OK, -1 error
     */
    public function cronCheckLatarniaStatus()
    {
        dol_syslog("KSEF::cronCheckLatarniaStatus START", LOG_INFO);
        $this->output = '';

        try {
            dol_include_once('/ksef/class/ksef_latarnia.class.php');

            $latarnia = new KsefLatarnia($this->db);
            $result = $latarnia->checkAndCache();

            if ($result !== false) {
                $status = $result['status'];
                $msgCount = count($result['messages']);
                $this->output = "KSeF Latarnia status: $status ($msgCount messages)";
                dol_syslog("KSEF::cronCheckLatarniaStatus Result: $status ($msgCount messages)", LOG_INFO);
                return 0;
            }

            $this->output = 'Latarnia unreachable: ' . $latarnia->error;
            dol_syslog("KSEF::cronCheckLatarniaStatus WARNING: " . $latarnia->error, LOG_WARNING);
            return 0;

        } catch (Throwable $e) {
            $this->error = $e->getMessage();
            $this->output = 'Fatal error: ' . $e->getMessage();
            dol_syslog("KSEF::cronCheckLatarniaStatus ERROR: " . $e->getMessage(), LOG_ERR);
            return -1;
        }
    }
}