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
 * \file    lib/ksef.lib.php
 * \ingroup ksef
 * \brief   common functions
 */

/**
 * @brief Prepares admin head tabs
 * @return array Tab array
 * @called_by setup.php, howtouse.php, about.php
 */
function ksefAdminPrepareHead()
{
    global $langs, $conf;
    $langs->load("ksef@ksef");

    $h = 0;
    $head = array();

    $head[$h][0] = dol_buildpath("/ksef/admin/setup.php", 1);
    $head[$h][1] = $langs->trans("KSEF_Settings");
    $head[$h][2] = 'settings';
    $h++;

    $head[$h][0] = dol_buildpath("/ksef/admin/howtouse.php", 1);
    $head[$h][1] = $langs->trans("KSEF_HowToUse");
    $head[$h][2] = 'howtouse';
    $h++;

    $head[$h][0] = dol_buildpath("/ksef/admin/about.php", 1);
    $head[$h][1] = $langs->trans("KSEF_About");
    $head[$h][2] = 'about';
    $h++;

    complete_head_from_modules($conf, $langs, null, $head, $h, 'ksef');
    return $head;
}

/**
 * @brief Formats NIP with dashes
 * @param $nip NIP string
 * @return string Formatted NIP
 * @called_by ksefindex.php
 */
function ksefFormatNIP($nip)
{
    $nip = preg_replace('/[^0-9]/', '', $nip);
    if (strlen($nip) == 10) {
        return substr($nip, 0, 3).'-'.substr($nip, 3, 3).'-'.substr($nip, 6, 2).'-'.substr($nip, 8, 2);
    }
    return $nip;
}

/**
 * @brief Cleans NIP (removes all non-digit characters)
 * @param $nip NIP string
 * @return string Cleaned NIP with only digits
 */
function ksefCleanNIP($nip)
{
    return preg_replace('/[^0-9]/', '', $nip);
}

/**
 * @brief Validates NIP checksum
 * @param $nip NIP string
 * @return bool True if valid
 * @called_by External validation
 */
function ksefValidateNIP($nip)
{
    $nip = ksefCleanNIP($nip);
    if (strlen($nip) != 10) return false;

    $weights = array(6, 5, 7, 2, 3, 4, 5, 6, 7);
    $sum = 0;
    for ($i = 0; $i < 9; $i++) {
        $sum += (int)$nip[$i] * $weights[$i];
    }

    $checksum = $sum % 11;
    if ($checksum == 10) $checksum = 0;

    return ($checksum == (int)$nip[9]);
}

/**
 * @brief Gets status badge HTML
 * @param $status Status code
 * @return string Badge HTML
 * @called_by status.php, ksefindex.php, tab_ksef.php
 */
function ksefGetStatusBadge($status)
{
    global $langs;
    $badges = array(
        'PENDING' => '<span class="badge badge-status1 badge-status">'.$langs->trans('KSEF_STATUS_PENDING').'</span>',
        'SUBMITTED' => '<span class="badge badge-status1 badge-status">'.$langs->trans('KSEF_STATUS_SUBMITTED').'</span>',
        'ACCEPTED' => '<span class="badge badge-status4 badge-status">'.$langs->trans('KSEF_STATUS_ACCEPTED').'</span>',
        'REJECTED' => '<span class="badge badge-status8 badge-status">'.$langs->trans('KSEF_STATUS_REJECTED').'</span>',
        'FAILED' => '<span class="badge badge-status8 badge-status">'.$langs->trans('KSEF_STATUS_FAILED').'</span>',
        'TIMEOUT' => '<span class="badge badge-status7 badge-status">'.$langs->trans('KSEF_STATUS_TIMEOUT').'</span>'
    );
    return isset($badges[$status]) ? $badges[$status] : '<span class="badge badge-status">'.htmlspecialchars($status).'</span>';
}

/**
 * @brief Gets environment badge HTML
 * @param $environment Environment code
 * @return string Badge HTML
 * @called_by status.php, ksefindex.php, tab_ksef.php
 */
function ksefGetEnvironmentBadge($environment)
{
    global $langs;
    $badges = array(
        'TEST' => '<span class="badge badge-status1">'.$langs->trans('KSEF_ENV_TEST').'</span>',
        'DEMO' => '<span class="badge badge-status7">'.$langs->trans('KSEF_ENV_DEMO').'</span>',
        'PRODUCTION' => '<span class="badge badge-status4">'.$langs->trans('KSEF_ENV_PRODUCTION').'</span>'
    );
    return isset($badges[$environment]) ? $badges[$environment] : '<span class="badge">'.htmlspecialchars($environment).'</span>';
}

/**
 * @brief Gets KSeF verification URL
 * @param $ksef_number KSeF number
 * @param $invoice_hash Invoice hash
 * @param $environment Environment
 * @return string Verification URL
 * @called_by KsefQR::addQRToPDF(), status.php
 */
function ksefGetVerificationURL($ksef_number, $invoice_hash = null, $environment = null)
{
    global $conf;

    if (empty($environment)) {
        $environment = !empty($conf->global->KSEF_ENVIRONMENT) ? $conf->global->KSEF_ENVIRONMENT : 'TEST';
    }

    $urls = array(
        'PRODUCTION' => 'https://ksef.mf.gov.pl',
        'TEST' => 'https://ksef-test.mf.gov.pl',
        'DEMO' => 'https://ksef-demo.mf.gov.pl'
    );

    $base_url = isset($urls[$environment]) ? $urls[$environment] : $urls['TEST'];

    $parts = explode('-', $ksef_number);

    if (count($parts) < 2) {
        throw new Exception("Invalid KSeF number format: " . $ksef_number);
    }

    $nip = $parts[0];
    $dateStr = $parts[1];

    $date = DateTime::createFromFormat('Ymd', $dateStr);
    $formattedDate = $date ? $date->format('d-m-Y') : date('d-m-Y');

    // Convert to Base64URL
    $decoded = base64_decode($invoice_hash);
    $base64Url = rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=');

    return $base_url . '/client-app/invoice/' . $nip . '/' . $formattedDate . '/' . $base64Url;
}


/**
 * @brief Checks if customer is excluded from KSeF submissions
 * @param $socid Customer ID
 * @return bool True if excluded
 * @called_by ActionsKSEF, setup.php, status.php
 */
function ksefIsCustomerExcluded($socid) {
    global $conf;
    if (empty($conf->global->KSEF_EXCLUDED_CUSTOMERS)) return false;
    $excluded = array_map('trim', explode(',', $conf->global->KSEF_EXCLUDED_CUSTOMERS));
    return in_array($socid, $excluded);
}

/**
 * @brief Updates invoice KSeF extrafields and optionally regenerates PDF
 * @param $db Database handler
 * @param $invoice_id Invoice ID
 * @param $ksef_number KSeF number (optional)
 * @param $status Status (optional)
 * @param $submission_date Submission date timestamp (optional)
 * @param $regenerate_pdf Regenerate PDF flag
 * @return int Result code (1 on success, -1 on error)
 */
function ksefUpdateInvoiceExtrafields($db, $invoice_id, $ksef_number = null, $status = null, $submission_date = null, $regenerate_pdf = false)
{
    global $langs;

    require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

    $invoice = new Facture($db);
    if ($invoice->fetch($invoice_id) <= 0) {
        return -1;
    }

    if (empty($invoice->array_options)) {
        $invoice->array_options = array();
    }

    // Update fields only if provided
    if ($ksef_number !== null) {
        $invoice->array_options['options_ksef_number'] = $ksef_number;
    }
    if ($status !== null) {
        $invoice->array_options['options_ksef_status'] = $status;
    }
    if ($submission_date !== null) {
        $invoice->array_options['options_ksef_submission_date'] = $submission_date;
    }

    $result = $invoice->insertExtraFields();

    // Regenerate PDF if requested
    if ($result >= 0 && $regenerate_pdf && !empty($ksef_number)) {
        $invoice->fetch($invoice_id); // Reload to get updated extrafields
        $pdf_result = $invoice->generateDocument($invoice->model_pdf, $langs, 0, 0, 0);
        if ($pdf_result > 0) {
            dol_syslog("KSeF: PDF regenerated for invoice " . $invoice->ref . " with KSEF number", LOG_INFO);
        } else {
            dol_syslog("KSeF: PDF regeneration failed for invoice " . $invoice->ref . ": " . $invoice->error, LOG_ERR);
        }
    }

    return $result >= 0 ? 1 : -1;
}