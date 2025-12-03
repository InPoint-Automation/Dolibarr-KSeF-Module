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
     * @brief Submits invoice to KSeF system
     * @param $invoice_id Invoice ID
     * @param $user User object
     * @param $mode Submission mode (SYNC/MANUAL)
     * @return array Result array with status, ksef_number, etc.
     * @called_by ActionsKSEF::doActions()
     * @calls FA3Builder::buildFromInvoice(), KsefClient::submitInvoice(), getSubmissionByInvoice()
     */
    public function submitInvoice($invoice_id, $user, $mode = 'SYNC')
    {
        global $conf;
        dol_syslog("KSEF::submitInvoice invoice_id=$invoice_id mode=$mode", LOG_INFO);

        $start_time = microtime(true);

        try {
            $invoice = new Facture($this->db);
            if ($invoice->fetch($invoice_id) <= 0) {
                throw new Exception("Invoice not found: $invoice_id");
            }

            $existing = new KsefSubmission($this->db);
            if ($existing->fetchByInvoice($invoice_id) > 0 &&
                in_array($existing->status, ['ACCEPTED', 'PENDING'])) {
                return array(
                    'status' => $existing->status,
                    'ksef_number' => $existing->ksef_number,
                    'submission_date' => $existing->date_submission,
                    'message' => 'Invoice already submitted'
                );
            }

            $fa3_xml = $this->builder->buildFromInvoice($invoice_id);
            if ($fa3_xml === false) {
                throw new Exception("Failed to build FA(3) XML: " . $this->builder->error);
            }

            if (!$this->builder->validate($fa3_xml)) {
                dol_syslog("KSEF::submitInvoice XML validation warnings: " . implode(', ', $this->builder->errors), LOG_WARNING);
            }

            if ($mode === 'SYNC') {
                $this->client->setTimeout(self::TIMEOUT_SYNC);
            }

            $api_result = $this->client->submitInvoice($fa3_xml);

            if ($api_result === false) {
                throw new Exception($this->client->error ?: 'Unknown submission error');
            }

            $submission = new KsefSubmission($this->db);
            $submission->fk_facture = $invoice_id;
            $submission->fa3_xml = $fa3_xml;
            $submission->status = $api_result['status'] ?? 'FAILED';
            $submission->date_submission = dol_now();
            $submission->environment = $conf->global->KSEF_ENVIRONMENT ?? 'TEST';
            $submission->ksef_reference = $api_result['reference_number'] ?? '';
            $submission->ksef_number = $api_result['ksef_number'] ?? null;
            $submission->invoice_hash = $api_result['invoice_hash'] ?? null;
            $submission->upo_xml = $api_result['upo_xml'] ?? null;
            $submission->api_response = json_encode($api_result);

            $create_result = $submission->create($user);
            if ($create_result < 0) {
                dol_syslog("KSEF::submitInvoice failed to store submission: " . $submission->error, LOG_ERR);
            }

            $elapsed = microtime(true) - $start_time;

            if ($api_result['status'] === 'ACCEPTED' && !empty($api_result['ksef_number'])) {
                dol_syslog("KSEF::submitInvoice SUCCESS in {$elapsed}s - KSEF: " . $api_result['ksef_number'], LOG_INFO);
                return array(
                    'status' => 'ACCEPTED',
                    'ksef_number' => $api_result['ksef_number'],
                    'submission_date' => dol_now(),
                    'submission_id' => $create_result
                );
            }

            if ($mode === 'SYNC' && $elapsed >= self::TIMEOUT_SYNC) {
                dol_syslog("KSEF::submitInvoice TIMEOUT after {$elapsed}s", LOG_WARNING);
                return array(
                    'status' => 'PENDING',
                    'ksef_number' => 'PENDING-' . $invoice->ref,
                    'submission_date' => dol_now(),
                    'reference_number' => $api_result['reference_number'] ?? '',
                    'submission_id' => $create_result
                );
            }

            return array(
                'status' => $api_result['status'] ?? 'UNKNOWN',
                'ksef_number' => $api_result['ksef_number'] ?? ('KSEF-' . $api_result['status'] . '-' . $invoice->ref),
                'submission_date' => dol_now(),
                'reference_number' => $api_result['reference_number'] ?? '',
                'message' => $api_result['message'] ?? '',
                'submission_id' => $create_result
            );

        } catch (Exception $e) {
            $this->error = $e->getMessage();
            $this->errors[] = $e->getMessage();

            dol_syslog("KSEF::submitInvoice ERROR: " . $e->getMessage(), LOG_ERR);

            if (isset($invoice_id)) {
                global $conf;
                $submission = new KsefSubmission($this->db);
                $submission->fk_facture = $invoice_id;
                $submission->fa3_xml = $fa3_xml ?? '';
                $submission->status = KsefSubmission::STATUS_FAILED;
                $submission->environment = $conf->global->KSEF_ENVIRONMENT ?? 'TEST';
                $submission->date_submission = dol_now();
                $submission->error_message = $e->getMessage();

                if (!empty($this->client->last_error_code)) {
                    $submission->error_code = $this->client->last_error_code;
                }
                if (!empty($this->client->last_error_details)) {
                    $submission->error_details = json_encode($this->client->last_error_details);
                }
                $submission->create($user);
            }

            return array(
                'status' => 'FAILED',
                'ksef_number' => 'ERROR-' . ($invoice->ref ?? 'UNKNOWN'),
                'submission_date' => dol_now(),
                'error' => $e->getMessage()
            );
        }
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

            if (!in_array($submission->status, ['PENDING', 'OFFLINE24'])) {
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
     * @calls submitInvoice()
     */
    public function retrySubmission($invoice_id, $user)
    {
        try {
            $submission = new KsefSubmission($this->db);
            if ($submission->fetchByInvoice($invoice_id) > 0) {
                if (!in_array($submission->status, ['FAILED', 'REJECTED'])) {
                    throw new Exception("Submission status is " . $submission->status . ", cannot retry");
                }
                $submission->retry_count++;
                $submission->update($user);
            }
            return $this->submitInvoice($invoice_id, $user, 'MANUAL');

        } catch (Exception $e) {
            $this->error = $e->getMessage();
            return array('status' => 'ERROR', 'error' => $e->getMessage());
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
            $submission->upo_download_date = dol_now();
            $submission->update($user);

            return $upo_xml;

        } catch (Exception $e) {
            $this->error = $e->getMessage();
            dol_syslog("KSEF::downloadUPO ERROR: " . $e->getMessage(), LOG_ERR);
            return false;
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
        $sql .= " WHERE status IN ('PENDING', 'OFFLINE24')";
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
