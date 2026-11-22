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
 * \brief   PDF QR codes
 */

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';

class KsefQR
{
    private $db;

    /** @var Translate */
    private $langs;

    /**
     * @brief Constructor
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * @brief QR II URL
     * @param array $params
     * @return string|false False on error
     */
    private function generateQRCodeII($params)
    {
        dol_include_once('/ksef/lib/ksef.lib.php');

        $url = ksefBuildCertificateVerificationUrl(
            $params['env'] ?? 'TEST',
            $params['sellerNIP'] ?? '',
            $params['invoiceHash'] ?? '',
            null,
            $params['fa3Xml'] ?? null
        );
        if (!$url) {
            dol_syslog("KsefQR::generateQRCodeII - Offline certificate not configured or signing failed", LOG_WARNING);
            return false;
        }
        return $url;
    }

    /**
     * @brief QR II placeholder
     * @param object $pdf TCPDF
     * @param float $x
     * @param float $y
     * @param float $size
     * @return void
     */
    private function renderQRCodeIIPlaceholder(&$pdf, $x, $y, $size)
    {
        $langs = $this->langs;

        $pdf->SetDrawColor(180, 180, 180);
        $pdf->SetFillColor(245, 245, 245);
        $pdf->Rect($x, $y, $size, $size, 'DF');

        $pdf->SetFont('freesans', '', 5.5);
        $pdf->SetTextColor(120, 120, 120);

        $line1 = $langs->transnoentities('KSEF_QRCodeII');
        $line2 = $langs->transnoentities('KSEF_QRCodeIIUnavailable');

        $midY = $y + ($size / 2) - 4;
        $pdf->SetXY($x, $midY);
        $pdf->MultiCell($size, 3, $line1 . "\n" . $line2, 0, 'C', false);

        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetFillColor(255, 255, 255);
        $pdf->SetTextColor(0, 0, 0);
    }

    /**
     * @brief Verification link text
     * @param object $pdf TCPDF
     * @param float $textX
     * @param float $textY
     * @param float $textWidth
     * @param string $verifyUrl
     * @param string|null $ksefNumber
     * @return void
     */
    private function renderVerificationLink(&$pdf, $textX, $textY, $textWidth, $verifyUrl, $ksefNumber = null)
    {
        $langs = $this->langs;

        if (empty($verifyUrl)) {
            return;
        }

        $pdf->SetFont('freesans', '', 6);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetXY($textX, $textY);
        $instructionText = $langs->transnoentities('KSEF_QRVerificationInstruction');
        $pdf->MultiCell($textWidth, 3, $instructionText, 0, 'L', false);

        $textY = $pdf->GetY() + 1;

        if (!empty($ksefNumber)) {
            $pdf->SetFont('freesans', 'B', 6);
            $pdf->SetXY($textX, $textY);
            $pdf->Cell($textWidth, 3, $ksefNumber, 0, 1, 'L');
            $textY = $pdf->GetY() + 1;
        }

        $pdf->SetFont('freesans', '', 5);
        $pdf->SetTextColor(0, 0, 255);
        $pdf->SetXY($textX, $textY);
        $linkHtml = '<a href="' . htmlspecialchars($verifyUrl) . '">' . htmlspecialchars($verifyUrl) . '</a>';
        $pdf->writeHTMLCell($textWidth, 0, $textX, $textY, $linkHtml, 0, 1, false, true, 'L');
        $pdf->SetTextColor(0, 0, 0);
    }

    /**
     * @brief QR size
     * @return int mm
     */
    public static function qrSize()
    {
        global $conf;
        return !empty($conf->global->KSEF_QR_SIZE) ? (int) $conf->global->KSEF_QR_SIZE : 25;
    }

    /**
     * @brief QR block height
     * @return int mm
     */
    public static function blockHeight()
    {
        return self::qrSize() + 6;
    }

    /**
     * @brief Invoice QR data
     * @param int $invoice_id
     * @return array|false False if none
     */
    private function prepare($invoice_id)
    {
        global $conf, $mysoc;

        if (!isModEnabled('ksef') || empty($conf->global->KSEF_ADD_QR)) {
            return false;
        }

        dol_include_once('/ksef/class/ksef_submission.class.php');
        dol_include_once('/ksef/lib/ksef.lib.php');

        $submission = new KsefSubmission($this->db);
        if ($submission->fetchByInvoice($invoice_id) <= 0) {
            dol_syslog("KsefQR::prepare - No submission found for invoice $invoice_id", LOG_DEBUG);
            return false;
        }

        if (ksefSubmissionHash($submission) === '') {
            dol_syslog("KsefQR::prepare - No invoice hash available for submission " . $submission->rowid, LOG_WARNING);
            return false;
        }

        $nip = ksefSellerNip();
        if (empty($nip)) {
            dol_syslog("KsefQR::prepare - No seller NIP configured", LOG_WARNING);
            return false;
        }

        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
        $invoice = new Facture($this->db);
        if ($invoice->fetch($invoice_id) <= 0) {
            return false;
        }

        $codes = ksefQrCodesForSubmission($submission, $nip, $invoice->date, false);
        if (!$codes['offline'] && $codes['kod1'] === '') {
            return false;
        }
        return array('codes' => $codes, 'submission' => $submission, 'nip' => $nip);
    }

    /**
     * @brief QR band height
     * @param int $invoice_id
     * @return int mm, 0 if none
     */
    public function reservedBandHeight($invoice_id)
    {
        return $this->prepare($invoice_id) ? self::blockHeight() + 3 : 0;
    }

    /**
     * @brief Draw QR band
     * @param object $pdf TCPDF
     * @param int $invoice_id
     * @param object|null $generator PDF template
     * @param Translate|null $outputlangs
     * @return bool True if drawn
     */
    public function addQRToPDF(&$pdf, $invoice_id, $generator = null, $outputlangs = null)
    {
        global $langs;
        $this->langs = is_object($outputlangs) ? $outputlangs : $langs;

        if (!is_object($pdf) || !method_exists($pdf, 'write2DBarcode')) {
            return false;
        }
        $data = $this->prepare($invoice_id);
        if (!$data) {
            return false;
        }
        $codes = $data['codes'];
        $submission = $data['submission'];

        $langs->load("ksef@ksef");
        $this->langs->load("ksef@ksef");

        dol_syslog("KsefQR::addQRToPDF - invoice=$invoice_id, status={$submission->status}, offline_mode={$submission->offline_mode}, "
            . "ksef_number={$submission->ksef_number}, offline=" . (int) $codes['offline'] . ", label=" . $codes['kod1_label'], LOG_DEBUG);

        $drawn = false;
        try {
            $currentY = $pdf->GetY();
            $currentX = $pdf->GetX();
            $currentPage = $pdf->getPage();
            $currentFont = $pdf->getFontFamily();
            $currentFontSize = $pdf->getFontSizePt();
            $currentFontStyle = $pdf->getFontStyle();
            $currentAutoPageBreak = $pdf->getAutoPageBreak();
            $margins = $pdf->getMargins();
            $currentAutoPageBreakMargin = $margins['bottom'] ?? 0;

            $pageWidth = $pdf->getPageWidth();
            $pageHeight = $pdf->getPageHeight();
            $marginRight = $margins['right'] ?? 10;
            $marginLeft = $margins['left'] ?? 10;

            $qrSize = self::qrSize();
            $blockHeight = self::blockHeight();
            if (is_object($generator) && isset($generator->page_hauteur) && isset($generator->heightforfooter)) {
                $pageHeight = (float) $generator->page_hauteur;
                $qrY = $pageHeight - (float) $generator->heightforfooter - (float) ($generator->heightforfreetext ?? 0) - $blockHeight;
                if (isset($generator->marge_gauche)) {
                    $marginLeft = (float) $generator->marge_gauche;
                }
                if (isset($generator->marge_droite)) {
                    $marginRight = (float) $generator->marge_droite;
                }
            } else {
                $qrY = $pageHeight - 50;
            }

            if ($currentY > $qrY - 1) {
                dol_syslog("KsefQR::addQRToPDF - no room for the QR band on invoice $invoice_id (content ends at "
                    . round($currentY, 1) . " mm, band starts at " . round($qrY, 1) . " mm): QR left out", LOG_WARNING);
                if (php_sapi_name() !== 'cli') {
                    setEventMessages($langs->trans('KSEF_QRNoRoom'), null, 'warnings');
                }
                return false;
            }

            $pdf->SetAutoPageBreak(false);

            $style = array(
                'border' => false,
                'vpadding' => 'auto',
                'hpadding' => 'auto',
                'fgcolor' => array(0, 0, 0),
                'bgcolor' => false,
                'module_width' => 1,
                'module_height' => 1
            );

            $qr_i_x = $marginLeft;
            $lastX = $qr_i_x;

            if ($codes['kod1'] !== '') {
                $pdf->write2DBarcode($codes['kod1'], 'QRCODE,H', $qr_i_x, $qrY, $qrSize, $qrSize, $style, 'N');
                $drawn = true;
                $pdf->SetTextColor(0, 0, 0);
                if ($codes['number'] !== '') {
                    $pdf->SetFont('freesans', '', 5);
                    $pdf->SetXY($qr_i_x, $qrY + $qrSize + 1);
                    $ksefNum = $codes['number'];
                    if (strlen($ksefNum) > 35) {
                        $pdf->Cell($qrSize, 2.5, substr($ksefNum, 0, 35), 0, 1, 'C');
                        $pdf->SetXY($qr_i_x, $qrY + $qrSize + 3.5);
                        $pdf->Cell($qrSize, 2.5, substr($ksefNum, 35), 0, 1, 'C');
                    } else {
                        $pdf->Cell($qrSize, 3, $ksefNum, 0, 1, 'C');
                    }
                } else {
                    $pdf->SetFont('freesans', 'B', 7);
                    $pdf->SetXY($qr_i_x, $qrY + $qrSize + 1);
                    $pdf->Cell($qrSize, 4, 'OFFLINE', 0, 0, 'C');
                }
            }

            if ($codes['offline']) {
                $qr_ii_x = $qr_i_x + $qrSize + 8;
                $lastX = $qr_ii_x;
                $qr_ii_url = $this->generateQRCodeII(array(
                    'sellerNIP' => $data['nip'],
                    'invoiceHash' => ksefSubmissionHash($submission),
                    'env' => $submission->environment ?? 'TEST',
                    'fa3Xml' => $submission->fa3_xml
                ));

                if (!empty($qr_ii_url)) {
                    $pdf->write2DBarcode($qr_ii_url, 'QRCODE,H', $qr_ii_x, $qrY, $qrSize, $qrSize, $style, 'N');
                    $drawn = true;
                } else {
                    dol_syslog("KsefQR::addQRToPDF - QR Code II not generated (certificate not configured or signing failed)", LOG_WARNING);
                    $this->renderQRCodeIIPlaceholder($pdf, $qr_ii_x, $qrY, $qrSize);
                }
                $pdf->SetFont('freesans', 'B', 7);
                $pdf->SetTextColor(0, 0, 0);
                $pdf->SetXY($qr_ii_x, $qrY + $qrSize + 1);
                $pdf->Cell($qrSize, 4, 'CERTYFIKAT', 0, 0, 'C');
            }

            $textX = $lastX + $qrSize + 5;
            $textWidth = $pageWidth - $textX - $marginRight;
            if ($textWidth > 30 && $codes['kod1'] !== '') {
                $this->renderVerificationLink($pdf, $textX, $qrY, $textWidth, $codes['kod1'], null);
            }

            $pdf->SetAutoPageBreak($currentAutoPageBreak, $currentAutoPageBreakMargin);
            $pdf->setPage($currentPage);
            $pdf->SetXY($currentX, $currentY);
            $pdf->SetFont($currentFont, $currentFontStyle, $currentFontSize);
            $pdf->SetTextColor(0, 0, 0);

            return $drawn;

        } catch (Exception $e) {
            dol_syslog("KsefQR::addQRToPDF: Error - " . $e->getMessage(), LOG_ERR);
            return false;
        }
    }

}