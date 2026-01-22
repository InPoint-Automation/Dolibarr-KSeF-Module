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
 * \file    ksef/class/ksef_qr.class.php
 * \ingroup ksef
 * \brief   QR Code generation
 */

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';

class KsefQR
{
    private $db;
    private $cache = array();

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * @brief Gets environment base URL
     * @param string $env Environment (TEST/DEMO/PRODUCTION)
     * @return string Base URL
     */
    private function getEnvironmentURL($env)
    {
        $urls = array(
            'PRODUCTION' => 'https://qr.ksef.mf.gov.pl',
            'TEST' => 'https://qr-test.ksef.mf.gov.pl',
            'DEMO' => 'https://qr-demo.ksef.mf.gov.pl'
        );
        return isset($urls[$env]) ? $urls[$env] : $urls['TEST'];
    }

    /**
     * @brief Generates QR Code I URL
     * @param string $nip Seller NIP
     * @param int|string $date Invoice date
     * @param string $hash Invoice SHA-256 hash (base64)
     * @param string $env Environment
     * @return string QR Code I URL
     * @called_by addQRToPDF()
     */
    private function generateQRCodeI($nip, $date, $hash, $env)
    {
        $base_url = $this->getEnvironmentURL($env);

        if (is_numeric($date)) {
            $formatted_date = date('d-m-Y', $date);
        } else {
            $formatted_date = date('d-m-Y', strtotime($date));
        }

        $hash_decoded = base64_decode($hash);
        $base64url_hash = rtrim(strtr(base64_encode($hash_decoded), '+/', '-_'), '=');

        return "{$base_url}/client-app/invoice/{$nip}/{$formatted_date}/{$base64url_hash}";
    }

    /**
     * @brief Generates QR Code II URL (certificate authentication for offline invoices)
     * @param array $params Parameters
     * @return string|false QR Code II URL or false on error
     */
    private function generateQRCodeII($params)
    {
        global $conf;
        dol_include_once('/ksef/lib/ksef.lib.php');

        $credentials = ksefLoadOfflineCertificate();
        if (!$credentials) {
            dol_syslog("KsefQR::generateQRCodeII - Offline certificate not properly configured", LOG_WARNING);
            return false;
        }

        $nip = $params['sellerNIP'];
        $invoiceHash = $params['invoiceHash'];
        $env = $params['env'] ?? 'TEST';

        $hashBase64URL = ksefBase64ToBase64URL($invoiceHash);

        $hashBytes = base64_decode($invoiceHash);
        $signature = ksefSignData($hashBytes, $credentials['private_key_pem'], true);

        if (!$signature) {
            dol_syslog("KsefQR::generateQRCodeII - Failed to sign invoice hash", LOG_ERR);
            return false;
        }

        $base_url = $this->getEnvironmentURL($env);
        $certSerial = $credentials['serial'];

        return "{$base_url}/client-app/certificate/nip/{$nip}/{$nip}/{$certSerial}/{$hashBase64URL}/{$signature}";
    }

    /**
     * @brief Adds QR code(s) to invoice PDF
     * Online mode (has KSeF number): Single QR Code with verification URL
     * Offline mode (no KSeF number yet): Two QR codes - OFFLINE + CERTYFIKAT
     * @param object $pdf TCPDF object
     * @param int $invoice_id Invoice ID
     * @return bool True if QR added successfully
     */
    public function addQRToPDF(&$pdf, $invoice_id)
    {
        global $conf, $mysoc, $langs;

        if (empty($conf->ksef->enabled) || empty($conf->global->KSEF_ADD_QR)) {
            return false;
        }

        if (!is_object($pdf) || !method_exists($pdf, 'write2DBarcode')) {
            return false;
        }

        dol_include_once('/ksef/class/ksef_submission.class.php');
        dol_include_once('/ksef/lib/ksef.lib.php');

        $submission = new KsefSubmission($this->db);
        if ($submission->fetchByInvoice($invoice_id) <= 0) {
            dol_syslog("KsefQR::addQRToPDF - No submission found for invoice $invoice_id", LOG_DEBUG);
            return false;
        }

        if (empty($submission->invoice_hash)) {
            dol_syslog("KsefQR::addQRToPDF - No invoice hash available for submission " . $submission->rowid, LOG_WARNING);
            return false;
        }

        // seller NIP
        $nip = ksefCleanNIP($mysoc->idprof1);
        if (empty($nip)) {
            dol_syslog("KsefQR::addQRToPDF - No seller NIP configured", LOG_WARNING);
            return false;
        }

        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
        $invoice = new Facture($this->db);
        if ($invoice->fetch($invoice_id) <= 0) {
            return false;
        }

        $langs->load("ksef@ksef");

        $has_real_ksef_number = !empty($submission->ksef_number) &&
            strpos($submission->ksef_number, 'OFFLINE') === false &&
            strpos($submission->ksef_number, 'PENDING') === false &&
            strpos($submission->ksef_number, 'ERROR') === false;

        $is_offline_mode = !empty($submission->offline_mode);
        $is_offline_pending = ($is_offline_mode && !$has_real_ksef_number) || ($submission->status == 'OFFLINE');

        dol_syslog("KsefQR::addQRToPDF - invoice=$invoice_id, status={$submission->status}, " .
            "offline_mode={$submission->offline_mode}, ksef_number={$submission->ksef_number}, " .
            "has_real_ksef=$has_real_ksef_number, is_offline_pending=$is_offline_pending", LOG_DEBUG);

        try {
            // Save current PDF state to prevent interfering with the main template
            $currentY = $pdf->GetY();
            $currentX = $pdf->GetX();
            $currentPage = $pdf->getPage();
            $currentFont = $pdf->getFontFamily();
            $currentFontSize = $pdf->getFontSizePt();
            $currentFontStyle = $pdf->getFontStyle();
            $currentAutoPageBreak = $pdf->getAutoPageBreak();

            $pdf->SetAutoPageBreak(false);

            $pageWidth = $pdf->getPageWidth();
            $pageHeight = $pdf->getPageHeight();
            $margins = $pdf->getMargins();
            $marginRight = $margins['right'] ?? 10;
            $marginLeft = $margins['left'] ?? 10;

            $qrSize = !empty($conf->global->KSEF_QR_SIZE) ? (int)$conf->global->KSEF_QR_SIZE : 25;
            $bottomOffset = 50;
            $qrY = $pageHeight - $bottomOffset;

            $style = array(
                'border' => false,
                'vpadding' => 'auto',
                'hpadding' => 'auto',
                'fgcolor' => array(0, 0, 0),
                'bgcolor' => false,
                'module_width' => 1,
                'module_height' => 1
            );

            if ($is_offline_pending) {
                $spacing = 8;
                $qr_i_x = $marginLeft;
                $qr_ii_x = $marginLeft + $qrSize + $spacing;

                // QR Code I (OFFLINE)
                $qr_i_url = $this->generateQRCodeI(
                    $nip,
                    $invoice->date,
                    $submission->invoice_hash,
                    $submission->environment ?? 'TEST'
                );

                if (!empty($qr_i_url)) {
                    $pdf->write2DBarcode($qr_i_url, 'QRCODE,H', $qr_i_x, $qrY, $qrSize, $qrSize, $style, 'N');
                    $pdf->SetFont('helvetica', 'B', 7);
                    $pdf->SetTextColor(0, 0, 0);
                    $pdf->SetXY($qr_i_x, $qrY + $qrSize + 1);
                    $pdf->Cell($qrSize, 4, 'OFFLINE', 0, 0, 'C');
                }

                // QR Code II (CERTYFIKAT)
                $qr_ii_url = $this->generateQRCodeII(array(
                    'sellerNIP' => $nip,
                    'invoiceHash' => $submission->invoice_hash,
                    'env' => $submission->environment ?? 'TEST'
                ));

                if (!empty($qr_ii_url)) {
                    $pdf->write2DBarcode($qr_ii_url, 'QRCODE,H', $qr_ii_x, $qrY, $qrSize, $qrSize, $style, 'N');
                    $pdf->SetFont('helvetica', 'B', 7);
                    $pdf->SetXY($qr_ii_x, $qrY + $qrSize + 1);
                    $pdf->Cell($qrSize, 4, 'CERTYFIKAT', 0, 0, 'C');
                } else {
                    dol_syslog("KsefQR::addQRToPDF - QR Code II not generated (certificate not configured)", LOG_WARNING);
                }
            } else if ($has_real_ksef_number) {
                dol_syslog("KsefQR::addQRToPDF - Generating ACCEPTED QR code for {$submission->ksef_number}", LOG_INFO);
                $qrX = $marginLeft;
                $verifyUrl = ksefGetVerificationURL(
                    $submission->ksef_number,
                    $submission->invoice_hash,
                    $submission->environment ?? 'TEST',
                    $nip,
                    $invoice->date
                );

                if (!empty($verifyUrl)) {
                    $pdf->write2DBarcode($verifyUrl, 'QRCODE,H', $qrX, $qrY, $qrSize, $qrSize, $style, 'N');
                    $pdf->SetFont('helvetica', '', 6);
                    $pdf->SetTextColor(0, 0, 0);
                    $pdf->SetXY($qrX, $qrY + $qrSize + 1);
                    $pdf->MultiCell($qrSize + 20, 3, $submission->ksef_number, 0, 'L', false);

                    dol_syslog("KsefQR::addQRToPDF - Added accepted QR code", LOG_INFO);
                }
            }

            $pdf->SetAutoPageBreak($currentAutoPageBreak);
            $pdf->setPage($currentPage);
            $pdf->SetXY($currentX, $currentY);
            $pdf->SetFont($currentFont, $currentFontStyle, $currentFontSize);
            $pdf->SetTextColor(0, 0, 0);

            return true;

        } catch (Exception $e) {
            dol_syslog("KsefQR::addQRToPDF: Error - " . $e->getMessage(), LOG_ERR);
            return false;
        }
    }

}
