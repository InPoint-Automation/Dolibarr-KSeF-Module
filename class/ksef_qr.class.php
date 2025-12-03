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
     * @brief Adds QR code to invoice PDF
     * @param $pdf PDF object
     * @param $invoice_id Invoice ID
     * @return bool True if added
     * @called_by ActionsKSEF::afterPDFTotalTable()
     * @calls KsefSubmission::fetchByInvoice(), ksefGetVerificationURL()
     */
    public function addQRToPDF(&$pdf, $invoice_id)
    {
        global $conf;

        if (empty($conf->ksef->enabled) || empty($conf->global->KSEF_ADD_QR)) return false;
        if (!is_object($pdf) || !method_exists($pdf, 'write2DBarcode')) return false;

        dol_include_once('/ksef/class/ksef_submission.class.php');

        $submission = new KsefSubmission($this->db);
        if ($submission->fetchByInvoice($invoice_id) <= 0) return false;

        if (!in_array($submission->status, array('ACCEPTED', 'OFFLINE24'))) return false;
        if (empty($submission->ksef_number) || empty($submission->invoice_hash)) return false;

        dol_include_once('/ksef/lib/ksef.lib.php');
        $verifyUrl = ksefGetVerificationURL(
            $submission->ksef_number,
            $submission->invoice_hash,
            $submission->environment ?? 'PRODUCTION'
        );

        if (empty($verifyUrl)) return false;

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

            // Calculate Position
            $qrX = 10;
            if ($qrX < $marginLeft) $qrX = $marginLeft;
            if (($qrX + $qrSize) > ($pageWidth - $marginRight)) $qrX = $pageWidth - $marginRight - $qrSize;

            $bottomOffset = 50;
            $qrY = $pageHeight - $bottomOffset;
            if (($qrY + $qrSize) > $pageHeight) $qrY = $pageHeight - $qrSize - 5;
            if ($qrY < 50) $qrY = 50;

            $style = array(
                'border' => false,
                'vpadding' => 'auto',
                'hpadding' => 'auto',
                'fgcolor' => array(0, 0, 0),
                'bgcolor' => false,
                'module_width' => 1,
                'module_height' => 1
            );

            $pdf->write2DBarcode($verifyUrl, 'QRCODE,H', $qrX, $qrY, $qrSize, $qrSize, $style, 'N');

            $labelY = $qrY + $qrSize + 1;

            $pdf->SetFont('helvetica', '', 6);
            $pdf->SetXY($qrX, $labelY);
            $pdf->MultiCell($qrSize, 3, $submission->ksef_number, 0, 'C', false);

            $pdf->SetAutoPageBreak($currentAutoPageBreak);
            $pdf->setPage($currentPage);
            $pdf->SetXY($currentX, $currentY);
            $pdf->SetFont($currentFont, $currentFontStyle, $currentFontSize);
            $pdf->SetTextColor(0, 0, 0);

            return true;

        } catch (Exception $e) {
            dol_syslog("KsefQR::addQRToPDF: Error - " . $e->getMessage(), LOG_ERR);
            try {
                if (isset($currentAutoPageBreak)) $pdf->SetAutoPageBreak($currentAutoPageBreak);
                if (isset($currentPage)) $pdf->setPage($currentPage);
                if (isset($currentX) && isset($currentY)) $pdf->SetXY($currentX, $currentY);
                if (isset($currentFont)) $pdf->SetFont($currentFont, $currentFontStyle ?? '', $currentFontSize ?? 10);
            } catch (Exception $e2) {
            }

            return false;
        }
    }

    /**
     * @brief Checks if QR should be shown for invoice
     * @param $invoice_id Invoice ID
     * @return bool True if should show
     * @called_by
     */
    public function shouldShowQR($invoice_id)
    {
        global $conf;
        if (empty($conf->ksef->enabled)) return false;

        dol_include_once('/ksef/class/ksef_submission.class.php');
        $submission = new KsefSubmission($this->db);
        if ($submission->fetchByInvoice($invoice_id) <= 0) return false;

        return ($submission->status === 'ACCEPTED' || $submission->status === 'OFFLINE24');
    }

}
