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
 * \file    ksef/class/ksef.class.php
 * \ingroup ksef
 * \brief   Main KSEF class
 */

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';

class KSEF extends CommonObject
{
    public $element = 'ksef';
    public $table_element = 'ksef_submissions';

    private $client;
    private $builder;

    const TIMEOUT_SYNC = 5;

    public function __construct($db)
    {
        global $conf;

        dol_include_once('/ksef/class/ksef_client.class.php');
        dol_include_once('/ksef/class/fa3_builder.class.php');
        dol_include_once('/ksef/class/ksef_submission.class.php');
        $this->db = $db;

        $this->client = new KsefClient($db, $conf->global->KSEF_ENVIRONMENT ?? 'TEST');
        $this->builder = new FA3Builder($db);
    }


    /**
     * @brief Submits invoice to KSeF system including offline mode
     * @param $invoice_id Invoice ID
     * @param $user User object
     * @param $mode Submission mode (SYNC/MANUAL)
     * @param $force_offline Force offline mode
     * @param $offline_reason Reason for offline mode
     * @return array Result array with status, ksef_number, etc.
     * @called_by ActionsKSEF::doActions()
     * @calls FA3Builder::buildFromInvoice(), KsefClient::submitInvoice(), getSubmissionByInvoice(), needsOfflineConfirmation()
     */
    public function submitInvoice($invoice_id, $user, $mode = 'SYNC', $force_offline = false, $offline_reason = '')
    {
        global $conf;
        dol_syslog("KSEF::submitInvoice invoice_id=$invoice_id mode=$mode force_offline=" . ($force_offline ? 'yes' : 'no'), LOG_INFO);

        $start_time = microtime(true);
        $fa3_xml = '';
        $xml_hash = '';
        $fa3_creation_date = null;

        try {
            $invoice = new Facture($this->db);
            if ($invoice->fetch($invoice_id) <= 0) {
                throw new Exception("Invoice not found: $invoice_id");
            }

            $existing = new KsefSubmission($this->db);
            if ($existing->fetchByInvoice($invoice_id) > 0) {
                if ($existing->status == 'ACCEPTED') {
                    return array(
                        'status' => 'ACCEPTED',
                        'ksef_number' => $existing->ksef_number,
                        'submission_date' => $existing->date_submission,
                        'message' => 'Invoice already submitted'
                    );
                }

                if ($existing->status == 'PENDING') {
                    return array(
                        'status' => 'PENDING',
                        'message' => 'Submission already in progress',
                        'submission_id' => $existing->rowid
                    );
                }

                if (!empty($existing->fa3_creation_date)) {
                    $fa3_creation_date = $existing->fa3_creation_date;
                    dol_syslog("KSEF::submitInvoice - Reusing original creation date: $fa3_creation_date", LOG_INFO);
                }
            }

            if (!$force_offline) {
                $backdate_info = $this->needsOfflineConfirmation($invoice);
                if ($backdate_info) {
                    return array(
                        'status' => 'NEEDS_CONFIRMATION',
                        'backdate_info' => $backdate_info,
                        'deadline' => $backdate_info['deadline'],
                        'message' => $backdate_info['reason']
                    );
                }
            }

            $build_options = array();
            if ($fa3_creation_date) {
                $build_options['original_creation_date'] = $fa3_creation_date;
            }
            if ($force_offline) {
                $build_options['offline_mode'] = true;
            }

            $fa3_xml = $this->builder->buildFromInvoice($invoice_id, $build_options);
            if ($fa3_xml === false) {
                throw new Exception("Failed to build FA(3) XML: " . $this->builder->error);
            }

            $fa3_creation_date = $this->builder->getLastCreationDate();
            $xml_hash = $this->builder->getLastXmlHash();

            if (!$this->builder->validate($fa3_xml)) {
                dol_syslog("KSEF::submitInvoice XML validation warnings: " . implode(', ', $this->builder->errors), LOG_WARNING);
            }

            $offline_mode_value = $force_offline ? 'OFFLINE' : null;
            $offline_deadline = $force_offline ? ksefCalculateOfflineDeadline($invoice->date) : null;

            $submission = new KsefSubmission($this->db);
            if (!empty($existing->rowid) && in_array($existing->status, array('FAILED', 'REJECTED', 'TIMEOUT'))) {
                $submission = $existing;
                $submission->retry_count = ($submission->retry_count ?? 0) + 1;
            }

            $submission->fk_facture = $invoice_id;
            $submission->fa3_xml = $fa3_xml;
            $submission->fa3_creation_date = $fa3_creation_date;
            $submission->invoice_hash = $xml_hash;
            $submission->status = 'PENDING';
            $submission->environment = $conf->global->KSEF_ENVIRONMENT ?? 'TEST';
            $submission->date_submission = dol_now();
            $submission->offline_mode = $offline_mode_value;
            $submission->offline_deadline = $offline_deadline;
            $submission->offline_detected_reason = $offline_reason ?: null;
            $submission->error_message = null;
            $submission->error_code = null;

            if (!empty($submission->rowid)) {
                $submission->update($user, 1);
            } else {
                $submission->create($user);
            }

            if ($mode === 'SYNC') {
                $this->client->setTimeout(self::TIMEOUT_SYNC);
            }

            $api_result = $this->client->submitInvoice($fa3_xml, array(
                'offline_mode' => $force_offline,
                'invoice_hash' => $xml_hash
            ));

            $elapsed = microtime(true) - $start_time;

            if ($api_result && $api_result['status'] === 'ACCEPTED' && !empty($api_result['ksef_number'])) {
                $submission->status = 'ACCEPTED';
                $submission->ksef_reference = $api_result['reference_number'] ?? '';
                $submission->ksef_number = $api_result['ksef_number'];
                $submission->upo_xml = $api_result['upo_xml'] ?? null;
                $submission->api_response = json_encode($api_result);
                $submission->date_acceptance = dol_now();
                $submission->update($user, 1);

                dol_syslog("KSEF::submitInvoice SUCCESS in {$elapsed}s - KSEF: " . $api_result['ksef_number'], LOG_INFO);

                return array(
                    'status' => 'ACCEPTED',
                    'ksef_number' => $api_result['ksef_number'],
                    'invoice_hash' => $api_result['invoice_hash'] ?? $xml_hash,
                    'submission_date' => dol_now(),
                    'submission_id' => $submission->rowid,
                    'offline_mode' => $offline_mode_value
                );
            } else {
                $submission->status = 'FAILED';
                $submission->error_message = $api_result['error'] ?? $this->client->error ?? 'Unknown error';
                $submission->error_code = $this->client->last_error_code ?? null;
                if (!empty($this->client->last_error_details)) {
                    $submission->error_details = json_encode($this->client->last_error_details);
                }
                $submission->api_response = json_encode($api_result);
                $submission->update($user, 1);

                dol_syslog("KSEF::submitInvoice FAILED in {$elapsed}s: " . $submission->error_message, LOG_ERR);

                return array(
                    'status' => 'FAILED',
                    'error' => $submission->error_message,
                    'error_code' => $submission->error_code,
                    'submission_id' => $submission->rowid,
                    'fa3_creation_date' => $fa3_creation_date,
                    'invoice_hash' => $xml_hash,
                    'can_use_offline' => true
                );
            }

        } catch (Exception $e) {
            $this->error = $e->getMessage();
            $this->errors[] = $e->getMessage();
            dol_syslog("KSEF::submitInvoice ERROR: " . $e->getMessage(), LOG_ERR);

            if (isset($submission) && !empty($submission->rowid)) {
                $submission->status = 'FAILED';
                $submission->error_message = $e->getMessage();
                if (!empty($this->client->last_error_code)) {
                    $submission->error_code = $this->client->last_error_code;
                }
                $submission->update($user, 1);
            } elseif (isset($invoice_id)) {
                $submission = new KsefSubmission($this->db);
                $submission->fk_facture = $invoice_id;
                $submission->fa3_xml = $fa3_xml;
                $submission->fa3_creation_date = $fa3_creation_date;
                $submission->invoice_hash = $xml_hash;
                $submission->status = 'FAILED';
                $submission->environment = $conf->global->KSEF_ENVIRONMENT ?? 'TEST';
                $submission->date_submission = dol_now();
                $submission->error_message = $e->getMessage();
                $submission->create($user);
            }

            return array(
                'status' => 'FAILED',
                'error' => $e->getMessage(),
                'submission_id' => $submission->rowid ?? null,
                'fa3_creation_date' => $fa3_creation_date,
                'invoice_hash' => $xml_hash,
                'can_use_offline' => true
            );
        }
    }

    /**
     * @brief Submits invoice to KSeF in offline mode
     * @param int $invoice_id Invoice ID
     * @param object $user User object
     * @param string $offline_reason Reason for offline mode (backdated/connection_error/user_choice)
     * @return array Result array with status, ksef_number, etc.
     */
    public function submitInvoiceOffline($invoice_id, $user, $offline_reason = 'user_choice')
    {
        global $conf;
        dol_syslog("KSEF::submitInvoiceOffline invoice_id=$invoice_id reason=$offline_reason", LOG_INFO);

        $cert_check = ksefIsOfflineCertificateConfigured();
        if (!$cert_check['configured']) {
            $this->error = 'Offline certificate not configured';
            dol_syslog("KSEF::submitInvoiceOffline - " . $this->error, LOG_ERR);
            return array(
                'status' => 'FAILED',
                'error' => $this->error,
                'needs_certificate' => true,
                'missing_items' => $cert_check['missing']
            );
        }

        try {
            $invoice = new Facture($this->db);
            if ($invoice->fetch($invoice_id) <= 0) {
                throw new Exception("Invoice not found: $invoice_id");
            }

            $existing = new KsefSubmission($this->db);
            $submission = new KsefSubmission($this->db);

            if ($existing->fetchByInvoice($invoice_id) > 0) {
                if ($existing->status == 'ACCEPTED') {
                    return array(
                        'status' => 'ACCEPTED',
                        'ksef_number' => $existing->ksef_number,
                        'message' => 'Invoice already accepted in KSeF'
                    );
                }

                $submission = $existing;
            }

            $offline_deadline = ksefCalculateOfflineDeadline($invoice->date);

            if (!empty($submission->fa3_xml) && !empty($submission->invoice_hash)) {
                dol_syslog("KSEF::submitInvoiceOffline - Reusing stored XML and hash", LOG_INFO);
                $fa3_xml = $submission->fa3_xml;
                $xml_hash = $submission->invoice_hash;
                $fa3_creation_date = $submission->fa3_creation_date;
            } else {
                $fa3_xml = $this->builder->buildFromInvoice($invoice_id, array('offline_mode' => true));
                if ($fa3_xml === false) {
                    throw new Exception("Failed to build FA(3) XML: " . $this->builder->error);
                }
                $xml_hash = $this->builder->getLastXmlHash();
                $fa3_creation_date = $this->builder->getLastCreationDate();
            }

            $offline_number = 'OFFLINE-' . $invoice->ref . '-' . date('YmdHis');
            $submission->fk_facture = $invoice_id;
            $submission->fa3_xml = $fa3_xml;
            $submission->fa3_creation_date = $fa3_creation_date;
            $submission->invoice_hash = $xml_hash;
            $submission->status = 'OFFLINE';
            $submission->ksef_number = $offline_number;
            $submission->environment = $conf->global->KSEF_ENVIRONMENT ?? 'TEST';
            $submission->date_submission = dol_now();
            $submission->offline_mode = 'OFFLINE';
            $submission->offline_deadline = $offline_deadline;
            $submission->offline_detected_reason = $offline_reason;
            $submission->error_message = null;

            if (!empty($submission->rowid)) {
                $submission->update($user, 1);
            } else {
                $submission->create($user);
            }

            ksefUpdateInvoiceExtrafields(
                $this->db,
                $invoice_id,
                $offline_number,
                'OFFLINE',
                dol_now(),
                true
            );

            return array(
                'status' => 'OFFLINE',
                'ksef_number' => $offline_number,
                'invoice_hash' => $xml_hash,
                'submission_date' => dol_now(),
                'submission_id' => $submission->rowid,
                'offline_mode' => 'OFFLINE',
                'offline_deadline' => $offline_deadline
            );

        } catch (Exception $e) {
            $this->error = $e->getMessage();
            dol_syslog("KSEF::submitInvoiceOffline ERROR: " . $e->getMessage(), LOG_ERR);

            return array(
                'status' => 'FAILED',
                'error' => $e->getMessage()
            );
        }
    }

    /**
     * @brief Checks if invoice is backdated
     * @param $invoice Invoice object
     * @return array|false Backdate info or false if no confirmation needed
     * @called_by submitInvoice()
     * @calls ksefDetectBackdating()
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
     * @brief Checks status of pending submission
     * @param $invoice_id Invoice ID
     * @param $user User object
     * @return array Status result
     * @called_by status.php
     * @calls KsefClient::checkStatus(), ksefUpdateInvoiceExtrafields()
     */
    public function checkStatus($invoice_id, $user)
    {
        try {
            $submission = new KsefSubmission($this->db);
            if ($submission->fetchByInvoice($invoice_id) <= 0) {
                throw new Exception("No submission found for invoice: $invoice_id");
            }

            if (!in_array($submission->status, ['PENDING', 'OFFLINE'])) {
                return array(
                    'status' => $submission->status,
                    'ksef_number' => $submission->ksef_number,
                    'message' => 'Submission not in pending state'
                );
            }

            if (empty($submission->ksef_reference)) {
                throw new Exception("No KSEF reference number to check");
            }

            $status_result = $this->client->checkStatus($submission->ksef_reference);

            if ($status_result['status'] !== $submission->status) {
                $submission->status = $status_result['status'];
                $submission->ksef_number = $status_result['ksef_number'] ?? $submission->ksef_number;
                $submission->api_response = json_encode($status_result);
                $submission->update($user);

                if ($status_result['status'] === 'ACCEPTED' && !empty($status_result['ksef_number'])) {
                    ksefUpdateInvoiceExtrafields($this->db, $invoice_id, $status_result['ksef_number'], 'ACCEPTED', null, false);
                }
            }

            return $status_result;

        } catch (Exception $e) {
            $this->error = $e->getMessage();
            dol_syslog("KSEF::checkStatus ERROR: " . $e->getMessage(), LOG_ERR);
            return array('status' => 'ERROR', 'error' => $e->getMessage());
        }
    }

    /**
     * @brief Retries failed submission
     * @param $invoice_id Invoice ID
     * @param $user User object
     * @return array Result array
     * @called_by status.php mass action
     * @calls submitInvoice(), submitInvoiceOffline()
     */
    public function retrySubmission($invoice_id, $user)
    {
        try {
            $submission = new KsefSubmission($this->db);

            if ($submission->fetchByInvoice($invoice_id) <= 0) {
                throw new Exception("No submission found for invoice");
            }

            if ($submission->status == 'ACCEPTED') {
                return array(
                    'status' => 'ACCEPTED',
                    'ksef_number' => $submission->ksef_number,
                    'message' => 'Invoice already accepted'
                );
            }

            if ($submission->status == 'PENDING') {
                return array(
                    'status' => 'PENDING',
                    'message' => 'Submission already in progress, please wait'
                );
            }

            if (!in_array($submission->status, array('FAILED', 'REJECTED', 'TIMEOUT'))) {
                throw new Exception("Cannot retry submission with status: " . $submission->status);
            }

            if (!empty($submission->offline_mode)) {
                return $this->retryOfflineWithStoredXML($submission, $user);
            }

            $invoice = new Facture($this->db);
            if ($invoice->fetch($invoice_id) <= 0) {
                throw new Exception("Invoice not found");
            }

            $backdate_info = ksefDetectBackdating($invoice->date);
            if ($backdate_info['is_backdated']) {
                return array(
                    'status' => 'NEEDS_OFFLINE_CONFIRMATION',
                    'backdate_info' => $backdate_info,
                    'deadline' => $backdate_info['deadline']
                );
            }

            return $this->submitInvoice($invoice_id, $user, 'SYNC');

        } catch (Exception $e) {
            $this->error = $e->getMessage();
            return array('status' => 'ERROR', 'error' => $e->getMessage());
        }
    }


    /**
     * @brief Retries offline submission using stored XML (preserves hash)
     * @param KsefSubmission $submission Submission object with stored XML
     * @param object $user User object
     * @return array Result array
     * @called_by retrySubmission(), ksef_upload_offline action
     */
    public function retryOfflineWithStoredXML($submission, $user)
    {
        global $conf;

        $start_time = microtime(true);
        $fa3_xml = $submission->fa3_xml;
        $stored_hash = $submission->invoice_hash;

        if (empty($fa3_xml) || empty($stored_hash)) {
            $this->error = 'No stored XML or hash found in submission';
            return array(
                'status' => 'FAILED',
                'error' => $this->error
            );
        }

        try {
            $this->client->setTimeout(10);

            $api_result = $this->client->submitInvoice($fa3_xml, array(
                'invoice_hash' => $stored_hash
            ));

            $elapsed = microtime(true) - $start_time;

            if ($api_result && !empty($api_result['ksef_number']) &&
                strpos($api_result['ksef_number'], 'OFFLINE') === false) {
                $submission->status = 'ACCEPTED';
                $submission->ksef_reference = $api_result['reference_number'] ?? '';
                $submission->ksef_number = $api_result['ksef_number'];
                $submission->upo_xml = $api_result['upo_xml'] ?? null;
                $submission->api_response = json_encode($api_result);
                $submission->date_acceptance = dol_now();
                $submission->error_message = null;
                $submission->error_code = null;
                $submission->update($user, 1);

                return array(
                    'status' => 'ACCEPTED',
                    'ksef_number' => $api_result['ksef_number'],
                    'invoice_hash' => $stored_hash,
                    'submission_date' => dol_now(),
                    'submission_id' => $submission->rowid,
                    'offline_mode' => $submission->offline_mode,
                    'reused_xml' => true
                );
            } else {
                $error_msg = $api_result['error'] ?? $this->client->error ?? 'Unknown error';
                $submission->error_message = $error_msg;
                $submission->retry_count = ($submission->retry_count ?? 0) + 1;
                if (!empty($this->client->last_error_code)) {
                    $submission->error_code = $this->client->last_error_code;
                }
                $submission->update($user, 1);

                return array(
                    'status' => 'FAILED',
                    'error' => $error_msg,
                    'error_code' => $submission->error_code,
                    'invoice_hash' => $stored_hash,
                    'submission_id' => $submission->rowid,
                    'offline_mode' => $submission->offline_mode,
                    'reused_xml' => true
                );
            }

        } catch (Exception $e) {
            $this->error = $e->getMessage();
            $this->errors[] = $e->getMessage();
            dol_syslog("KSEF::retryOfflineWithStoredXML ERROR: " . $e->getMessage(), LOG_ERR);

            $submission->error_message = $e->getMessage();
            $submission->retry_count = ($submission->retry_count ?? 0) + 1;
            $submission->update($user, 1);

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
     * @brief Downloads UPO (confirmation) for accepted invoice
     * @param $invoice_id Invoice ID
     * @param $user User object
     * @return string|false UPO XML or false
     * @called_by ActionsKSEF::doActions()
     * @calls KsefClient::downloadUPO()
     */
    public function downloadUPO($invoice_id, $user)
    {
        try {
            $submission = new KsefSubmission($this->db);
            if ($submission->fetchByInvoice($invoice_id) <= 0) throw new Exception("No submission found");
            if ($submission->status !== 'ACCEPTED') throw new Exception("UPO only available for accepted submissions");
            if (empty($submission->ksef_number)) throw new Exception("No KSEF number available");

            if (!empty($submission->upo_xml)) return $submission->upo_xml;

            $sessionRef = null;
            if (!empty($submission->ksef_reference) && strpos($submission->ksef_reference, '-SO-') !== false) {
                $sessionRef = $submission->ksef_reference;
            }

            $upo_xml = $this->client->downloadUPO($submission->ksef_number, $sessionRef);

            if ($upo_xml === false) throw new Exception("Failed to download UPO: " . $this->client->error);

            $submission->upo_xml = $upo_xml;
//            $submission->upo_download_date = dol_now();
            $submission->update($user);

            return $upo_xml;

        } catch (Exception $e) {
            $this->error = $e->getMessage();
            dol_syslog("KSEF::downloadUPO ERROR: " . $e->getMessage(), LOG_ERR);
            return false;
        }
    }

    /**
     * @brief Submits technical correction for rejected offline invoice
     * @param $invoice_id Invoice ID
     * @param $original_submission_id Original submission ID (rejected)
     * @param $user User object
     * @return array Submission result
     * @called_by ActionsKSEF::doActions()
     */
    public function submitTechnicalCorrection($invoice_id, $original_submission_id, $user)
    {
        global $conf;

        try {
            $original = new KsefSubmission($this->db);
            if ($original->fetch($original_submission_id) <= 0) {
                throw new Exception("Original submission not found");
            }

            if (!in_array($original->status, ['REJECTED', 'FAILED'])) {
                throw new Exception("Technical correction only for rejected/failed submissions");
            }

            if (empty($original->invoice_hash)) {
                throw new Exception("Original invoice hash not found - cannot create technical correction");
            }

            if (empty($original->offline_mode)) {
                throw new Exception("Technical correction only available for offline submissions");
            }

            $new_xml = $this->builder->buildFromInvoice($invoice_id);
            if (!$new_xml) {
                throw new Exception("Failed to regenerate XML: " . $this->builder->error);
            }

            $new_hash = base64_encode(hash('sha256', $new_xml, true));

            $api_result = $this->client->submitInvoice($new_xml, array(
                'offline_mode' => true,
                'invoice_hash' => $new_hash,
                'corrected_hash' => $original->invoice_hash
            ));

            if ($api_result === false) {
                throw new Exception($this->client->error ?: 'Technical correction submission failed');
            }

            $submission = new KsefSubmission($this->db);
            $submission->fk_facture = $invoice_id;
            $submission->fa3_xml = $new_xml;
            $submission->invoice_hash = $new_hash;
            $submission->original_invoice_hash = $original->invoice_hash;
            $submission->offline_mode = $original->offline_mode;
            $submission->offline_deadline = $original->offline_deadline;
            $submission->offline_detected_reason = 'Technical correction of submission #' . $original_submission_id;
            $submission->status = $api_result['status'] ?? 'PENDING';
            $submission->environment = $conf->global->KSEF_ENVIRONMENT ?? 'TEST';
            $submission->date_submission = dol_now();
            $submission->ksef_reference = $api_result['reference_number'] ?? '';
            $submission->ksef_number = $api_result['ksef_number'] ?? null;
            $submission->api_response = json_encode($api_result);

            $submission->create($user);

            return array(
                'status' => $api_result['status'] ?? 'PENDING',
                'ksef_number' => $api_result['ksef_number'] ?? null,
                'original_submission_id' => $original_submission_id,
                'new_submission_id' => $submission->rowid
            );

        } catch (Exception $e) {
            $this->error = $e->getMessage();
            dol_syslog("KSEF::submitTechnicalCorrection ERROR: " . $e->getMessage(), LOG_ERR);
            return array('status' => 'ERROR', 'error' => $e->getMessage());
        }
    }

    /**
     * @brief Gets pending submissions for processing
     * @param $limit Max records
     * @return array Submission objects
     * @called_by KsefSubmission::processPendingSubmissions()
     */
    public function getPendingSubmissions($limit = 100)
    {
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "ksef_submissions";
        $sql .= " WHERE status IN ('PENDING', 'OFFLINE')";
        $sql .= " AND date_submission > DATE_SUB(NOW(), INTERVAL 48 HOUR)";
        $sql .= " ORDER BY date_submission ASC";
        $sql .= " LIMIT " . (int)$limit;

        $result = $this->db->query($sql);
        $submissions = array();

        if ($result) {
            while ($obj = $this->db->fetch_object($result)) {
                $submission = new KsefSubmission($this->db);
                if ($submission->fetch($obj->rowid) > 0) {
                    $submissions[] = $submission;
                }
            }
        }
        return $submissions;
    }


    /**
     * @brief Generates FA3 XML for invoice
     * @param $invoice_id Invoice ID
     * @return string|false XML string
     * @called_by External calls
     * @calls FA3Builder::buildFromInvoice()
     */
    public function generateFA3XML($invoice_id)
    {
        try {
            $xml = $this->builder->buildFromInvoice($invoice_id);
            if ($xml === false) {
                $this->error = $this->builder->error;
                $this->errors = $this->builder->errors;
                return false;
            }
            return $xml;
        } catch (Exception $e) {
            $this->error = $e->getMessage();
            return false;
        }
    }

    /**
     * @brief Validates FA3 XML
     * @param $xml XML string
     * @return array Validation result
     * @called_by External validation
     * @calls FA3Builder::validate()
     */
    public function validateFA3XML($xml)
    {
        $is_valid = $this->builder->validate($xml);
        return array(
            'valid' => $is_valid,
            'errors' => $this->builder->errors
        );
    }

}
