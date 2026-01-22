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
    $langs->load("ksef@ksef");

    $badges = array(
        'PENDING' => '<span class="badge badge-status1 badge-status">'.$langs->trans('KSEF_STATUS_PENDING').'</span>',
        'SUBMITTED' => '<span class="badge badge-status1 badge-status">'.$langs->trans('KSEF_STATUS_SUBMITTED').'</span>',
        'ACCEPTED' => '<span class="badge badge-status4 badge-status">'.$langs->trans('KSEF_STATUS_ACCEPTED').'</span>',
        'REJECTED' => '<span class="badge badge-status8 badge-status">'.$langs->trans('KSEF_STATUS_REJECTED').'</span>',
        'FAILED' => '<span class="badge badge-status8 badge-status">'.$langs->trans('KSEF_STATUS_FAILED').'</span>',
        'TIMEOUT' => '<span class="badge badge-status7 badge-status">'.$langs->trans('KSEF_STATUS_TIMEOUT').'</span>',
        'OFFLINE' => '<span class="badge badge-status7 badge-status" style="background-color: #fd7e14;">'.$langs->trans('KSEF_STATUS_OFFLINE').'</span>',
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
 * @param $nip Seller NIP
 * @param $invoice_date Invoice date timestamp
 * @return string Verification URL
 * @called_by KsefQR::addQRToPDF(), status.php
 */
function ksefGetVerificationURL($ksef_number, $invoice_hash = null, $environment = null, $nip = null, $invoice_date = null)
{
    global $conf, $mysoc;

    if (empty($environment)) {
        $environment = !empty($conf->global->KSEF_ENVIRONMENT) ? $conf->global->KSEF_ENVIRONMENT : 'TEST';
    }

    $urls = array(
        'PRODUCTION' => 'https://qr.ksef.mf.gov.pl',
        'TEST' => 'https://qr-test.ksef.mf.gov.pl',
        'DEMO' => 'https://qr-demo.ksef.mf.gov.pl'
    );

    $base_url = isset($urls[$environment]) ? $urls[$environment] : $urls['TEST'];

    if (empty($nip)) {
        if (!empty($ksef_number)) {
            $parts = explode('-', $ksef_number);
            $nip = $parts[0] ?? '';
        }
        if (empty($nip)) {
            $nip = ksefCleanNIP($mysoc->idprof1 ?? '');
        }
    }

    if (empty($nip)) {
        throw new Exception("NIP is required for verification URL");
    }

    if (!empty($invoice_date)) {
        if (is_numeric($invoice_date)) {
            $formattedDate = date('d-m-Y', $invoice_date);
        } else {
            $formattedDate = date('d-m-Y', strtotime($invoice_date));
        }
    } elseif (!empty($ksef_number)) {
        $parts = explode('-', $ksef_number);
        if (count($parts) >= 2) {
            $dateStr = $parts[1];
            $date = DateTime::createFromFormat('Ymd', $dateStr);
            $formattedDate = $date ? $date->format('d-m-Y') : date('d-m-Y');
        } else {
            $formattedDate = date('d-m-Y');
        }
    } else {
        $formattedDate = date('d-m-Y');
    }

    if (!empty($invoice_hash)) {
        $decoded = base64_decode($invoice_hash);
        $base64Url = rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=');
    } else {
        throw new Exception("Invoice hash is required for verification URL");
    }

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

/**
 * @brief Checks if date is a weekday
 * @param int|string $date Date to check (timestamp or string)
 * @return bool True if Mon-Fri
 * @called_by ksefGetNextWorkingDay(), ksefCalculateOfflineDeadline()
 */
function ksefIsWeekDay($date)
{
    if (!is_numeric($date)) {
        $date = strtotime($date);
    }
    $dayOfWeek = date('N', $date);
    return ($dayOfWeek >= 1 && $dayOfWeek <= 5);
}

/**
 * @brief Gets next weekday
 * @param int|string $date Starting date (timestamp or string)
 * @return int Next working day timestamp (end of day 23:59:59)
 * @called_by ksefCalculateOfflineDeadline()
 */
function ksefGetNextWeekday($date)
{
    if (!is_numeric($date)) {
        $date = strtotime($date);
    }

    $nextDay = strtotime('+1 day', strtotime('00:00:00', $date));

    while (!ksefIsWeekDay($nextDay)) {
        $nextDay = strtotime('+1 day', $nextDay);
    }

    return strtotime('23:59:59', $nextDay);
}

/**
 * @brief Calculates offline submission deadline (end of next weekday)
 * @param int|string $invoice_date Invoice issue date
 * @return int Deadline timestamp
 * @called_by KSEF::submitInvoice(), ksefDetectBackdating()
 */
function ksefCalculateOfflineDeadline($invoice_date)
{
    if (!is_numeric($invoice_date)) {
        $invoice_date = strtotime($invoice_date);
    }
    return ksefGetNextWeekday($invoice_date);
}

/**
 * @brief Detects if invoice is backdated (issue date before today)
 * @param int|string $invoice_date Invoice date
 * @return array ['is_backdated' => bool, 'days_behind' => int, 'reason' => string, 'deadline' => int]
 * @called_by KSEF::needsOfflineConfirmation()
 */
function ksefDetectBackdating($invoice_date)
{
    if (!is_numeric($invoice_date)) {
        $invoice_date = strtotime($invoice_date);
    }

    $invoice_day_start = strtotime('00:00:00', $invoice_date);
    $today_start = strtotime('00:00:00', dol_now());

    $diff_seconds = $today_start - $invoice_day_start;
    $days_behind = (int) floor($diff_seconds / 86400);

    $result = array(
        'is_backdated' => false,
        'days_behind' => $days_behind,
        'reason' => '',
        'deadline' => 0
    );

    if ($days_behind > 0) {
        $result['is_backdated'] = true;
        $result['deadline'] = ksefCalculateOfflineDeadline($invoice_date);
        $result['reason'] = sprintf('Invoice date %d day(s) behind current date', $days_behind);
    }

    return $result;
}

/**
 * @brief Checks if offline deadline has passed
 * @param int $deadline Deadline timestamp
 * @return bool True if deadline passed
 * @called_by status.php, cron job
 */
function ksefIsDeadlinePassed($deadline)
{
    return (dol_now() > $deadline);
}

/**
 * @brief Formats offline deadline for display with urgency indicator
 * @param int $deadline Deadline timestamp
 * @return string Formatted deadline HTML
 * @called_by status.php, tab_ksef.php
 */
function ksefFormatDeadline($deadline)
{
    global $langs;
    $langs->load("ksef@ksef");

    $now = dol_now();
    $hours_remaining = ($deadline - $now) / 3600;

    $formatted = dol_print_date($deadline, 'dayhour');

    if ($hours_remaining < 0) {
        return '<span class="badge badge-danger">' . $formatted . ' (' . $langs->trans('KSEF_DeadlinePassed') . ')</span>';
    } elseif ($hours_remaining < 4) {
        return '<span class="badge badge-warning">' . $formatted . ' (' . $langs->trans('KSEF_DeadlineUrgent') . ')</span>';
    } else {
        return '<span class="badge badge-info">' . $formatted . '</span>';
    }
}

/**
 * @brief Gets offline mode badge HTML
 * @param string $mode Offline mode
 * @return string Badge HTML
 * @called_by status.php, tab_ksef.php
 */
function ksefGetOfflineModeBadge($mode)
{
    global $langs;
    $langs->load("ksef@ksef");

    if (empty($mode)) {
        return '';
    }

    $badges = array(
        'OFFLINE' => '<span class="badge badge-warning">' . $langs->trans('KSEF_MODE_OFFLINE') . '</span>',
    );

    return isset($badges[$mode]) ? $badges[$mode] : '<span class="badge">' . htmlspecialchars($mode) . '</span>';
}

/**
 * @brief Gets authentication method badge HTML
 * @param string $method Authentication method (token/certificate)
 * @return string Badge HTML
 * @called_by setup.php, status.php
 */
function ksefGetAuthMethodBadge($method)
{
    global $langs;
    $langs->load("ksef@ksef");

    $badges = array(
        'token' => '<span class="badge badge-info"><i class="fa fa-key"></i> ' . $langs->trans('KSEF_AUTH_METHOD_TOKEN') . '</span>',
        'certificate' => '<span class="badge badge-primary"><i class="fa fa-certificate"></i> ' . $langs->trans('KSEF_AUTH_METHOD_CERTIFICATE') . '</span>'
    );

    return isset($badges[$method]) ? $badges[$method] : '<span class="badge">' . htmlspecialchars($method) . '</span>';
}

/**
 * @brief Checks authentication certificate status
 * @return array ['configured' => bool, 'valid' => bool, 'expires_soon' => bool, 'serial' => string]
 * @called_by setup.php, ksef_client.class.php
 */
function ksefCheckAuthCertificate()
{
    global $conf;

    $result = array(
        'configured' => false,
        'valid' => false,
        'expires_soon' => false,
        'serial' => '',
        'valid_to' => null
    );

    if (empty($conf->global->KSEF_AUTH_CERTIFICATE) ||
        empty($conf->global->KSEF_AUTH_PRIVATE_KEY)) {
        return $result;
    }

    $result['configured'] = true;
    $result['serial'] = $conf->global->KSEF_AUTH_CERT_SERIAL ?? '';

    if (!empty($conf->global->KSEF_AUTH_CERT_VALID_TO)) {
        $valid_to = $conf->global->KSEF_AUTH_CERT_VALID_TO;
        $result['valid_to'] = $valid_to;

        $now = dol_now();
        if ($valid_to > $now) {
            $result['valid'] = true;

            // Check if expires within 30 days
            $thirty_days = 30 * 24 * 3600;
            if (($valid_to - $now) < $thirty_days) {
                $result['expires_soon'] = true;
            }
        }
    } else {
        $result['valid'] = true;
    }

    return $result;
}

/**
 * @brief Converts standard Base64 to Base64URL (URL-safe, no padding)
 * @param string $base64 Standard Base64 string
 * @return string Base64URL string
 */
function ksefBase64ToBase64URL($base64)
{
    return rtrim(strtr($base64, '+/', '-_'), '=');
}

/**
 * @brief Converts Base64URL back to standard Base64
 * @param string $base64url Base64URL string
 * @return string Standard Base64 string
 */
function ksefBase64URLToBase64($base64url)
{
    $base64 = strtr($base64url, '-_', '+/');
    $padding = strlen($base64) % 4;
    if ($padding) {
        $base64 .= str_repeat('=', 4 - $padding);
    }
    return $base64;
}

/**
 * @brief Converts PEM certificate to DER format
 * @param string $pem PEM-encoded certificate
 * @return string DER binary data
 */
function ksefPemToDer($pem)
{
    $begin = '-----BEGIN CERTIFICATE-----';
    $end = '-----END CERTIFICATE-----';
    $pem = str_replace(array($begin, $end, "\r", "\n", " "), '', $pem);
    return base64_decode($pem);
}

/**
 * @brief Converts ECDSA DER signature
 * @param string $derSignature DER-encoded signature
 * @param int $componentSize Size of R and S components
 * @return string Raw signature
 */
function ksefConvertEcdsaDerToRaw($derSignature, $componentSize = 32)
{
    $offset = 0;
    $len = strlen($derSignature);

    // Check for SEQUENCE tag (0x30)
    if ($len < 2 || ord($derSignature[$offset]) != 0x30) {
        return $derSignature;
    }
    $offset++;

    // sequence length
    $seqLen = ord($derSignature[$offset]);
    $offset++;
    if ($seqLen > 0x80) {
        $offset += ($seqLen - 0x80);
    }

    // Parse R
    if ($offset >= $len || ord($derSignature[$offset]) != 0x02) {
        return $derSignature;
    }
    $offset++;
    $rLength = ord($derSignature[$offset]);
    $offset++;
    $r = substr($derSignature, $offset, $rLength);
    $offset += $rLength;

    // Parse S
    if ($offset >= $len || ord($derSignature[$offset]) != 0x02) {
        return $derSignature;
    }
    $offset++;
    $sLength = ord($derSignature[$offset]);
    $offset++;
    $s = substr($derSignature, $offset, $sLength);

    // Remove leading zeros
    $r = ltrim($r, "\x00");
    $s = ltrim($s, "\x00");

    // Pad to fixed component size
    $r = str_pad($r, $componentSize, "\x00", STR_PAD_LEFT);
    $s = str_pad($s, $componentSize, "\x00", STR_PAD_LEFT);

    return $r . $s;
}

/**
 * @brief Signs data with private key
 * @param string $data Data to sign
 * @param string $privateKeyPem Decrypted private key in PEM format
 * @param bool $returnBase64URL Return as Base64URL instead of raw
 * @return string|false Signature or false on error
 */
function ksefSignData($data, $privateKeyPem, $returnBase64URL = true)
{
    $privateKey = openssl_pkey_get_private($privateKeyPem);
    if (!$privateKey) {
        dol_syslog("ksefSignData: Failed to load private key: " . openssl_error_string(), LOG_ERR);
        return false;
    }

    $keyDetails = openssl_pkey_get_details($privateKey);
    $isEcKey = ($keyDetails['type'] == OPENSSL_KEYTYPE_EC);

    $signature = '';
    if (!openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        dol_syslog("ksefSignData: Signing failed: " . openssl_error_string(), LOG_ERR);
        return false;
    }

    // Convert ECDSA DER
    if ($isEcKey) {
        $curveName = $keyDetails['ec']['curve_name'] ?? 'prime256v1';
        $componentSize = 32;
        if (strpos($curveName, '384') !== false) {
            $componentSize = 48;
        } elseif (strpos($curveName, '521') !== false) {
            $componentSize = 66;
        }
        $signature = ksefConvertEcdsaDerToRaw($signature, $componentSize);
    }

    if ($returnBase64URL) {
        return ksefBase64ToBase64URL(base64_encode($signature));
    }

    return $signature;
}

/**
 * @brief Loads and decrypts offline certificate credentials
 * @return array|false ['certificate_pem', 'private_key_pem', 'serial'] or false
 */
function ksefLoadOfflineCertificate()
{
    global $conf;
    require_once DOL_DOCUMENT_ROOT . '/core/lib/security.lib.php';

    $cert_base64 = $conf->global->KSEF_OFFLINE_CERTIFICATE ?? '';
    if (empty($cert_base64)) {
        dol_syslog("ksefLoadOfflineCertificate: Certificate not configured", LOG_WARNING);
        return false;
    }

    $key_base64 = $conf->global->KSEF_OFFLINE_PRIVATE_KEY ?? '';
    if (empty($key_base64)) {
        dol_syslog("ksefLoadOfflineCertificate: Private key not configured", LOG_WARNING);
        return false;
    }

    $encrypted_password = $conf->global->KSEF_OFFLINE_KEY_PASSWORD ?? '';
    if (empty($encrypted_password)) {
        dol_syslog("ksefLoadOfflineCertificate: Password not configured", LOG_WARNING);
        return false;
    }

    $certificate_pem = base64_decode($cert_base64);
    $encrypted_key_pem = base64_decode($key_base64);
    $password = dol_decode($encrypted_password);

    $private_key = openssl_pkey_get_private($encrypted_key_pem, $password);
    if (!$private_key) {
        dol_syslog("ksefLoadOfflineCertificate: Failed to decrypt key: " . openssl_error_string(), LOG_ERR);
        return false;
    }

    $private_key_pem = '';
    openssl_pkey_export($private_key, $private_key_pem);

    return array(
        'certificate_pem' => $certificate_pem,
        'private_key_pem' => $private_key_pem,
        'serial' => $conf->global->KSEF_OFFLINE_CERT_SERIAL ?? ''
    );
}

/**
 * @brief Checks if offline certificate is fully configured
 * @return array ['configured' => bool, 'missing' => array of missing items]
 */
function ksefIsOfflineCertificateConfigured()
{
    global $conf;

    $result = array(
        'configured' => false,
        'missing' => array()
    );

    if (empty($conf->global->KSEF_OFFLINE_CERTIFICATE)) {
        $result['missing'][] = 'certificate';
    }
    if (empty($conf->global->KSEF_OFFLINE_PRIVATE_KEY)) {
        $result['missing'][] = 'private_key';
    }
    if (empty($conf->global->KSEF_OFFLINE_KEY_PASSWORD)) {
        $result['missing'][] = 'password';
    }

    $result['configured'] = empty($result['missing']);

    return $result;
}

/**
 * @brief Calculates SHA-256 hash of data in Base64 format
 * @param string $data Data to hash
 * @return string Base64-encoded SHA-256 hash
 */
function ksefCalculateHash($data)
{
    return base64_encode(hash('sha256', $data, true));
}

/**
 * @brief Builds ASN.1 length encoding
 * @param int $length Length value
 * @return string Encoded length bytes
 * @called_by ksefBuildASN1Sequence(), ksefBuildASN1Set(), ksefBuildASN1Integer(), etc.
 */
function ksefBuildASN1Length($length)
{
    if ($length < 128) {
        return chr($length);
    }
    $lengthBytes = '';
    $temp = $length;
    while ($temp > 0) {
        $lengthBytes = chr($temp & 0xFF) . $lengthBytes;
        $temp >>= 8;
    }
    return chr(0x80 | strlen($lengthBytes)) . $lengthBytes;
}

/**
 * @brief Builds ASN.1 SEQUENCE (tag 0x30)
 * @param string $content Sequence content
 * @return string DER-encoded SEQUENCE
 * @called_by ksefBuildX500NameDER(), ksefBuildRDN(), ksefGenerateIssuerSerialV2DER()
 */
function ksefBuildASN1Sequence($content)
{
    return chr(0x30) . ksefBuildASN1Length(strlen($content)) . $content;
}

/**
 * @brief Builds ASN.1 SET (tag 0x31)
 * @param string $content Set content
 * @return string DER-encoded SET
 * @called_by ksefBuildRDN()
 */
function ksefBuildASN1Set($content)
{
    return chr(0x31) . ksefBuildASN1Length(strlen($content)) . $content;
}

/**
 * @brief Builds ASN.1 INTEGER from decimal or hex string
 * @param string $number Number in decimal or hex format
 * @return string DER-encoded INTEGER
 * @called_by ksefGenerateIssuerSerialV2DER()
 */
function ksefBuildASN1Integer($number)
{
    if (preg_match('/^[0-9A-Fa-f]+$/', $number) && strlen($number) % 2 === 0 && strlen($number) > 2) {
        $bytes = hex2bin($number);
    } else {
        if (function_exists('gmp_init')) {
            $hex = gmp_strval(gmp_init($number, 10), 16);
        } else {
            $hex = dechex($number);
        }
        if (strlen($hex) % 2) $hex = '0' . $hex;
        $bytes = hex2bin($hex);
    }
    if (ord($bytes[0]) & 0x80) {
        $bytes = "\x00" . $bytes;
    }
    return chr(0x02) . ksefBuildASN1Length(strlen($bytes)) . $bytes;
}

/**
 * @brief Encodes a single OID component using base-128 encoding
 * @param int $value OID component value
 * @return string Encoded bytes
 * @called_by ksefBuildASN1ObjectIdentifier()
 */
function ksefEncodeOIDComponent($value)
{
    if ($value < 128) {
        return chr($value);
    }
    $bytes = array();
    while ($value > 0) {
        array_unshift($bytes, $value & 0x7F);
        $value >>= 7;
    }
    $encoded = '';
    for ($i = 0; $i < count($bytes); $i++) {
        $byte = $bytes[$i];
        if ($i < count($bytes) - 1) {
            $byte |= 0x80;
        }
        $encoded .= chr($byte);
    }
    return $encoded;
}

/**
 * @brief Builds ASN.1 OBJECT IDENTIFIER (tag 0x06)
 * @param string $oid OID in dotted notation (e.g., "2.5.4.3")
 * @return string DER-encoded OBJECT IDENTIFIER
 * @called_by ksefBuildRDN()
 */
function ksefBuildASN1ObjectIdentifier($oid)
{
    $parts = explode('.', $oid);
    $encoded = chr(40 * intval($parts[0]) + intval($parts[1]));
    for ($i = 2; $i < count($parts); $i++) {
        $encoded .= ksefEncodeOIDComponent(intval($parts[$i]));
    }
    return chr(0x06) . ksefBuildASN1Length(strlen($encoded)) . $encoded;
}

/**
 * @brief Builds ASN.1 UTF8String (tag 0x0C)
 * @param string $string String value
 * @return string DER-encoded UTF8String
 * @called_by ksefBuildRDN()
 */
function ksefBuildASN1UTF8String($string)
{
    return chr(0x0C) . ksefBuildASN1Length(strlen($string)) . $string;
}

/**
 * @brief Builds ASN.1 PrintableString (tag 0x13)
 * @param string $string String value
 * @return string DER-encoded PrintableString
 * @called_by ksefBuildRDN()
 */
function ksefBuildASN1PrintableString($string)
{
    return chr(0x13) . ksefBuildASN1Length(strlen($string)) . $string;
}

/**
 * @brief Builds a single RelativeDistinguishedName in DER format
 * @param string $attr Attribute name (C, ST, L, O, OU, CN, etc.)
 * @param string $value Attribute value
 * @return string DER-encoded RDN
 * @called_by ksefBuildX500NameDER()
 */
function ksefBuildRDN($attr, $value)
{
    $oids = array(
        'C'            => '2.5.4.6',
        'ST'           => '2.5.4.8',
        'L'            => '2.5.4.7',
        'O'            => '2.5.4.10',
        'OU'           => '2.5.4.11',
        'CN'           => '2.5.4.3',
        'serialNumber' => '2.5.4.5',
    );
    $oid = isset($oids[$attr]) ? $oids[$attr] : $attr;
    $oidDER = ksefBuildASN1ObjectIdentifier($oid);
    if ($attr === 'C') {
        $valueDER = ksefBuildASN1PrintableString($value);
    } else {
        $valueDER = ksefBuildASN1UTF8String($value);
    }
    $attrTypeAndValue = ksefBuildASN1Sequence($oidDER . $valueDER);
    return ksefBuildASN1Set($attrTypeAndValue);
}

/**
 * @brief Builds X.500 Name in DER format from parsed certificate components
 * @param array $components Issuer components from openssl_x509_parse()
 * @return string DER-encoded X.500 Name
 * @called_by ksefGenerateIssuerSerialV2DER()
 */
function ksefBuildX500NameDER($components)
{
    $order = array('CN', 'OU', 'O', 'L', 'ST', 'C', 'serialNumber');
    $rdns = '';

    foreach ($order as $key) {
        $value = null;
        if (isset($components[$key])) {
            $value = $components[$key];
        } elseif (isset($components[strtolower($key)])) {
            $value = $components[strtolower($key)];
        }
        if ($value !== null) {
            if (is_array($value)) {
                $value = $value[0];
            }
            $rdns .= ksefBuildRDN($key, $value);
        }
    }
    return ksefBuildASN1Sequence($rdns);
}

/**
 * @brief Formats issuer name in RFC 2253 format for XAdES
 * @param array $issuer Issuer array from openssl_x509_parse()
 * @return string RFC 2253 formatted issuer name
 * @called_by KsefClient::signXadesAuthRequest()
 */
function ksefFormatIssuerNameRfc2253($issuer)
{
    $parts = array();
    $order = array('CN', 'OU', 'O', 'L', 'ST', 'C');

    foreach ($order as $key) {
        if (isset($issuer[$key])) {
            $val = $issuer[$key];
            $val = str_replace(
                array('\\', ',', '+', '"', '<', '>', ';'),
                array('\\\\', '\\,', '\\+', '\\"', '\\<', '\\>', '\\;'),
                $val
            );
            $parts[] = $key . '=' . $val;
        }
    }
    return implode(',', $parts);
}

/**
 * @brief Generates DER-encoded IssuerSerial for IssuerSerialV2 in XAdES
 * @param string $certificatePem Certificate in PEM format
 * @return string Base64-encoded DER IssuerSerial
 * @called_by KsefClient::buildSignedProperties()
 */
function ksefGenerateIssuerSerialV2DER($certificatePem)
{
    $certInfo = openssl_x509_parse($certificatePem);
    if (!$certInfo) {
        return '';
    }

    $issuerNameDER = ksefBuildX500NameDER($certInfo['issuer']);
    $generalNameDER = chr(0xA4) . ksefBuildASN1Length(strlen($issuerNameDER)) . $issuerNameDER;
    $generalNamesDER = ksefBuildASN1Sequence($generalNameDER);
    $serialDER = ksefBuildASN1Integer($certInfo['serialNumber']);
    $issuerSerialDER = ksefBuildASN1Sequence($generalNamesDER . $serialDER);

    return base64_encode($issuerSerialDER);
}

/**
 * @brief Loads and decrypts authentication certificate credentials
 * @return array|false ['certificate_pem', 'private_key_pem', 'serial'] or false
 * @called_by KsefClient::authenticateWithCertificate()
 */
function ksefLoadAuthCertificate()
{
    global $conf;
    require_once DOL_DOCUMENT_ROOT . '/core/lib/security.lib.php';

    $cert_base64 = $conf->global->KSEF_AUTH_CERTIFICATE ?? '';
    if (empty($cert_base64)) {
        dol_syslog("ksefLoadAuthCertificate: Certificate not configured", LOG_WARNING);
        return false;
    }

    $key_base64 = $conf->global->KSEF_AUTH_PRIVATE_KEY ?? '';
    if (empty($key_base64)) {
        dol_syslog("ksefLoadAuthCertificate: Private key not configured", LOG_WARNING);
        return false;
    }

    $encrypted_password = $conf->global->KSEF_AUTH_KEY_PASSWORD ?? '';
    if (empty($encrypted_password)) {
        dol_syslog("ksefLoadAuthCertificate: Password not configured", LOG_WARNING);
        return false;
    }

    $certificate_pem = base64_decode($cert_base64);
    $encrypted_key_pem = base64_decode($key_base64);
    $password = dol_decode($encrypted_password);

    $private_key = openssl_pkey_get_private($encrypted_key_pem, $password);
    if (!$private_key) {
        dol_syslog("ksefLoadAuthCertificate: Failed to decrypt key: " . openssl_error_string(), LOG_ERR);
        return false;
    }

    $private_key_pem = '';
    openssl_pkey_export($private_key, $private_key_pem);

    return array(
        'certificate_pem' => $certificate_pem,
        'private_key_pem' => $private_key_pem,
        'serial' => $conf->global->KSEF_AUTH_CERT_SERIAL ?? ''
    );
}

/**
 * @brief writes data to session
 * @param string $key Session key to set
 * @param mixed $value Value to store
 * @param bool $closeAfter Whether to close session after write
 * @return bool True on success
 * @called_by ActionsKSEF::doActions()
 */
function ksefSessionWrite($key, $value, $closeAfter = true)
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        } else {
            dol_syslog("ksefSessionWrite: Cannot start session, status=" . session_status(), LOG_WARNING);
            return false;
        }
    }

    $_SESSION[$key] = $value;

    if ($closeAfter) {
        session_write_close();
    }

    return true;
}

/**
 * @brief Removes data from session
 * @param string $key Session key to unset
 * @param bool $closeAfter Whether to close session after (default: true)
 * @return bool True on success
 */
function ksefSessionUnset($key, $closeAfter = true)
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        } else {
            return false;
        }
    }

    unset($_SESSION[$key]);

    if ($closeAfter) {
        session_write_close();
    }

    return true;
}

/**
 * @brief Reads session data
 * @param string $key Session key to read
 * @param mixed $default Default value if key doesn't exist
 * @param bool $closeAfter Whether to close session after read (default: true)
 * @return mixed Session value or default
 */
function ksefSessionRead($key, $default = null, $closeAfter = true)
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        } else {
            return $default;
        }
    }

    $value = isset($_SESSION[$key]) ? $_SESSION[$key] : $default;

    if ($closeAfter) {
        session_write_close();
    }

    return $value;
}