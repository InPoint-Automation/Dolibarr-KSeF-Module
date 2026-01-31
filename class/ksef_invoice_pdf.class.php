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

require_once DOL_DOCUMENT_ROOT . '/core/lib/pdf.lib.php';

/**
 * Class to generate PDF visualization of incoming KSeF invoices
 * Layout attempts to mimic the KSeF portal visualizations
 */
class KsefInvoicePdf
{
    public $db;
    public $error = '';
    public $errors = array();
    private $pageWidth = 210;
    private $pageHeight = 297;
    private $marginLeft = 10;
    private $marginRight = 10;
    private $marginTop = 10;
    private $marginBottom = 15;
    private $contentWidth;

    private $pdf;

    // Colors
    private $colorText = array(52, 58, 64);
    private $colorLine = array(186, 186, 186);
    private $colorHeaderBg = array(246, 247, 250);
    private $colorBorder = array(186, 186, 186);
    private $colorLink = array(0, 0, 255);
    private $borderWidth = 0.3;

    // Font
    private $fontFamily = 'freesans';
    private $fontSizeBase = 7;
    private $fontSizeTable = 7;
    private $fontSizeSmall = 7;
    private $fontSizeNormal = 7;
    private $fontSizeLabel = 7;
    private $fontSizeSection = 10;
    private $fontSizeSubsection = 7;
    private $fontSizeInvoiceNum = 16;
    private $fontSizeTitle = 18;

    // Spacing
    private $spaceSectionAfter = 3;
    private $spaceLineAfter = 4;
    private $spaceLineBefore = 4;
    private $spaceTableAfter = 4;
    private $spaceParagraph = 2;
    private $lineHeight = 1.2;

    private $countryNames = array(
        'PL' => 'Polska', 'DE' => 'Niemcy', 'FR' => 'Francja', 'GB' => 'Wielka Brytania',
        'CZ' => 'Czechy', 'SK' => 'Słowacja', 'AT' => 'Austria', 'IT' => 'Włochy',
        'ES' => 'Hiszpania', 'NL' => 'Holandia', 'BE' => 'Belgia', 'SE' => 'Szwecja',
        'DK' => 'Dania', 'FI' => 'Finlandia', 'NO' => 'Norwegia', 'CH' => 'Szwajcaria',
    );

    public function __construct($db)
    {
        $this->db = $db;
        $this->contentWidth = $this->pageWidth - $this->marginLeft - $this->marginRight;
    }

    /**
     * @brief Generate PDF visualization
     * @param $incoming KsefIncoming object
     * @param $outputPath Optional path to save PDF
     * @return string|bool PDF content as string, true if saved to file, false on error
     * @called_by KSEF::getIncomingInvoicePdf(), incoming_card.php
     * @calls parseXmlData(), renderHeader(), renderCorrectionData(), renderSellerBuyer(), renderDetails(), renderPositionsTable(), renderGtinTable(), renderTotalAmount(), renderVatSummary(), renderAdditionalInfo(), renderAdditionalDesc(), renderPayment(), renderRegistries(), renderQrCode(), renderFooter()
     */
    public function generate($incoming, $outputPath = '')
    {
        global $conf, $langs;

        if (empty($incoming->rowid)) {
            $this->error = "Invoice object not loaded";
            return false;
        }

        require_once TCPDF_PATH . 'tcpdf.php';

        try {
            $xmlData = $this->parseXmlData($incoming->fa3_xml);

            $this->pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
            $this->pdf->SetCreator('KSeF Module for Dolibarr');
            $this->pdf->SetAuthor($incoming->seller_name ?? '');
            $this->pdf->SetTitle('Faktura ' . ($incoming->invoice_number ?? ''));
            $this->pdf->SetSubject('KSeF Invoice');
            $this->pdf->setPrintHeader(false);
            $this->pdf->setPrintFooter(false);
            $this->pdf->SetMargins($this->marginLeft, $this->marginTop, $this->marginRight);
            $this->pdf->SetAutoPageBreak(false);
            $this->pdf->SetFont($this->fontFamily, '', $this->fontSizeNormal);
            $this->pdf->SetTextColorArray($this->colorText);
            $this->pdf->setCellHeightRatio($this->lineHeight);
            $this->pdf->AddPage();

            $y = $this->marginTop;

            // HEADER
            $y = $this->renderHeader($incoming, $y);

            // CORRECTION INVOICE DATA
            if (!empty($xmlData['correctionData']['reason']) || !empty($xmlData['correctionData']['correctedInvoices'])) {
                $y = $this->renderCorrectionData($xmlData['correctionData'], $y);
            }

            // SELLER / BUYER
            $y = $this->renderSellerBuyer($incoming, $xmlData, $y);

            // DETAILS
            $y = $this->renderDetails($incoming, $xmlData, $y);

            // POSITIONS TABLE
            $y = $this->renderPositionsTable($incoming, $xmlData, $y);

            // GTIN/INDEKS TABLE
            $y = $this->renderGtinTable($xmlData, $y);

            // TOTAL AMOUNT
            $y = $this->renderTotalAmount($incoming, $y);

            // VAT SUMMARY
            $y = $this->renderVatSummary($incoming, $xmlData, $y);

            // ADDITIONAL INFO
            if (!empty($xmlData['additionalInfo'])) {
                $y = $this->renderAdditionalInfo($xmlData['additionalInfo'], $y);
            }

            // ADDITIONAL DESC
            if (!empty($xmlData['additionalDesc'])) {
                $y = $this->renderAdditionalDesc($xmlData['additionalDesc'], $y);
            }

            // PAYMENT
            $y = $this->renderPayment($incoming, $xmlData, $y);

            // REGISTRIES
            if (!empty($xmlData['registries']['krs']) || !empty($xmlData['registries']['regon']) || !empty($xmlData['registries']['bdo'])) {
                $y = $this->renderRegistries($xmlData['registries'], $y);
            }

            // STOPKA INFO
            if (!empty($xmlData['stopkaInfo'])) {
                $y = $this->renderStopkaInfo($xmlData['stopkaInfo'], $y);
            }

            // QR CODE
            $y = $this->renderQrCode($incoming, $xmlData, $y);

            // FOOTER
            $this->renderFooter($xmlData);

            // Output
            if (!empty($outputPath)) {
                $dir = dirname($outputPath);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                $this->pdf->Output($outputPath, 'F');
                return true;
            } else {
                return $this->pdf->Output('', 'S');
            }

        } catch (Exception $e) {
            $this->error = $e->getMessage();
            dol_syslog("KsefInvoicePdf::generate ERROR: " . $e->getMessage(), LOG_ERR);
            return false;
        }
    }


    /**
     * @brief Check if we need a new page, add one if needed
     * @param $y Current Y position
     * @param $neededHeight Height needed for next content
     * @return float New Y position
     * @called_by render methods
     */
    private function checkPageBreak($y, $neededHeight = 10)
    {
        if ($y + $neededHeight > $this->pageHeight - $this->marginBottom) {
            $this->pdf->AddPage();
            return $this->marginTop;
        }
        return $y;
    }


    /**
     * @brief Draw separator line
     * @param $y Y position for line
     * @return float New Y position after line and spacing
     * @called_by render methods
     */
    private function drawLine($y)
    {
        $this->pdf->SetDrawColorArray($this->colorLine);
        $this->pdf->SetLineWidth(0.3);
        $this->pdf->Line($this->marginLeft, $y, $this->marginLeft + $this->contentWidth, $y);
        return $y + $this->spaceLineAfter;
    }


    /**
     * @brief Render invoice header section with company logo
     * @param $incoming KsefIncoming object
     * @param $y Current Y position
     * @return float New Y position
     * @called_by generate()
     */
    private function renderHeader($incoming, $y)
    {
        global $conf, $mysoc;

        $pdf = $this->pdf;
        $logoHeight = 0;
        $startY = $y;

        // Company logo when seller NIP == our nip from KSeF settings
        $ourNip = !empty($conf->global->KSEF_COMPANY_NIP) ? preg_replace('/[^0-9]/', '', $conf->global->KSEF_COMPANY_NIP) : '';
        $sellerNip = !empty($incoming->seller_nip) ? preg_replace('/[^0-9]/', '', $incoming->seller_nip) : '';

        $isOutgoing = (!empty($ourNip) && !empty($sellerNip) && $ourNip === $sellerNip);

        if ($isOutgoing && !empty($mysoc->logo)) {
            $logoFile = $conf->mycompany->dir_output . '/logos/' . $mysoc->logo;
            if (is_readable($logoFile)) {
                $logoHeight = pdf_getHeightForLogo($logoFile);
                $pdf->Image($logoFile, $this->marginLeft, $y, 0, $logoHeight); // width=0 (auto)
            }
        }

        $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
        $pdf->SetTextColorArray($this->colorText);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 5, 'Numer Faktury:', 0, 1, 'R');
        $y += 5;

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeInvoiceNum);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 6, $incoming->invoice_number, 0, 1, 'R');
        $y += 6;

        $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 4, $this->getInvoiceTypeLabel($incoming->invoice_type), 0, 1, 'R');
        $y += 4;

        if (!empty($incoming->ksef_number)) {
            $pdf->SetXY($this->marginLeft, $y);
            $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
            $label = 'Numer KSEF:';
            $pdf->Cell($this->contentWidth - $pdf->GetStringWidth($incoming->ksef_number) - 2, 4, $label, 0, 0, 'R');
            $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
            $pdf->Cell(0, 4, $incoming->ksef_number, 0, 1, 'R');
            $y += 4;
        }

        $y = max($y, $startY + $logoHeight) + $this->spaceSectionAfter;

        return $y;
    }


    /**
     * @brief Render correction invoice data section (Dane faktury korygowanej)
     * @param $correctionData Correction data array
     * @param $y Current Y position
     * @return float New Y position
     * @called_by generate()
     * @calls checkPageBreak(), drawLine(), renderLabelValueAt()
     */
    private function renderCorrectionData($correctionData, $y)
    {
        $pdf = $this->pdf;
        $y = $this->checkPageBreak($y, 40);
        $y = $this->drawLine($y);

        // Two-column layout
        $colWidth = ($this->contentWidth - 5) / 2;
        $leftX = $this->marginLeft;
        $rightX = $this->marginLeft + $colWidth + 5;
        $startY = $y;

        // LEFT COLUMN - Section header
        $leftY = $startY;
        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($leftX, $leftY);
        $pdf->Cell($colWidth, 5, 'Dane faktury korygowanej', 0, 1, 'L');
        $leftY += 5 + $this->spaceSectionAfter;

        $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);

        // Correction reason
        if (!empty($correctionData['reason'])) {
            $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
            $label = 'Przyczyna korekty dla faktur korygujących: ';
            $labelWidth = $pdf->GetStringWidth($label);
            $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
            $valueWidth = $pdf->GetStringWidth($correctionData['reason']);

            $pdf->SetXY($leftX, $leftY);
            if ($labelWidth + $valueWidth < $colWidth - 2) {
                $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
                $pdf->Cell($labelWidth, 4, $label, 0, 0, 'L');
                $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
                $pdf->Cell($valueWidth, 4, $correctionData['reason'], 0, 1, 'L');
                $leftY += 4 + $this->spaceParagraph;
            } else {
                $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
                $pdf->MultiCell($colWidth, 4, $label, 0, 'L');
                $leftY = $pdf->GetY();
                $pdf->SetXY($leftX, $leftY);
                $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
                $pdf->MultiCell($colWidth, 4, $correctionData['reason'], 0, 'L');
                $leftY = $pdf->GetY() + $this->spaceParagraph;
            }
        }

        // Correction type (if exists)
        if (!empty($correctionData['type'])) {
            $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
            $label = 'Typ skutku korekty: ';
            $labelWidth = $pdf->GetStringWidth($label);
            $typeLabel = $this->getCorrectionTypeLabel($correctionData['type']);
            $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
            $valueWidth = $pdf->GetStringWidth($typeLabel);

            $pdf->SetXY($leftX, $leftY);
            if ($labelWidth + $valueWidth < $colWidth - 2) {
                $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
                $pdf->Cell($labelWidth, 4, $label, 0, 0, 'L');
                $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
                $pdf->Cell($valueWidth, 4, $typeLabel, 0, 1, 'L');
                $leftY += 4 + $this->spaceParagraph;
            } else {
                $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
                $pdf->MultiCell($colWidth, 4, $label, 0, 'L');
                $leftY = $pdf->GetY();
                $pdf->SetXY($leftX, $leftY);
                $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
                $pdf->MultiCell($colWidth, 4, $typeLabel, 0, 'L');
                $leftY = $pdf->GetY() + $this->spaceParagraph;
            }
        }

        // Corrected invoice details
        $rightY = $startY;
        if (!empty($correctionData['correctedInvoices'])) {
            $invoiceCount = count($correctionData['correctedInvoices']);

            foreach ($correctionData['correctedInvoices'] as $idx => $inv) {
                $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
                $pdf->SetXY($rightX, $rightY);
                if ($invoiceCount > 1) {
                    $pdf->Cell($colWidth, 5, 'Dane identyfikacyjne faktury korygowanej ' . ($idx + 1), 0, 1, 'L');
                } else {
                    $pdf->Cell($colWidth, 5 , 'Dane identyfikacyjne faktury korygowanej', 0, 1, 'L');
                }
                $rightY += 5 + $this->spaceSectionAfter;

                $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);

                if (!empty($inv['issueDate'])) {
                    $rightY = $this->renderLabelValueAt($rightX, $rightY, $colWidth, 'Data wystawienia faktury, której dotyczy faktura korygująca: ', $inv['issueDate']);
                }

                if (!empty($inv['number'])) {
                    $rightY = $this->renderLabelValueAt($rightX, $rightY, $colWidth, 'Numer faktury korygowanej: ', $inv['number']);
                }

                if (!empty($inv['ksefNumber'])) {
                    $rightY = $this->renderLabelValueAt($rightX, $rightY, $colWidth, 'Numer KSeF faktury korygowanej: ', $inv['ksefNumber']);
                }

                $rightY += 2;
            }
        }

        $y = max($leftY, $rightY) + 2;
        return $y;
    }


    /**
     * @brief render "Label: Value" at specific X position
     * @param $x X position
     * @param $y Y position
     * @param $colWidth Column width constraint
     * @param $label Label text (bold)
     * @param $value Value text
     * @return float New Y position
     * @called_by renderCorrectionData()
     */
    private function renderLabelValueAt($x, $y, $colWidth, $label, $value)
    {
        $pdf = $this->pdf;
        $pdf->SetXY($x, $y);
        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
        $labelWidth = $pdf->GetStringWidth($label);

        $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
        $valueWidth = $pdf->GetStringWidth($value);

        // Check if label + value fits on one line
        if ($labelWidth + $valueWidth < $colWidth - 2) {
            $pdf->SetXY($x, $y);
            $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
            $pdf->Cell($labelWidth, 4, $label, 0, 0, 'L');
            $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
            $pdf->Cell($valueWidth, 4, $value, 0, 1, 'L');
            return $y + 4;
        } else {
            $pdf->SetXY($x, $y);
            $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
            $pdf->MultiCell($colWidth, 4, $label, 0, 'L');
            $newY = $pdf->GetY();
            $pdf->SetXY($x, $newY);
            $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
            $pdf->MultiCell($colWidth, 4, $value, 0, 'L');
            return $pdf->GetY();
        }
    }


    /**
     * @brief Helper to render "Label: Value" pattern
     * @param $y Y position
     * @param $label Label text (bold)
     * @param $value Value text
     * @param $valueFormat Optional format for value
     * @return float New Y position
     * @called_by renderPayment()
     */
    private function renderLabelValue($y, $label, $value, $valueFormat = null)
    {
        $pdf = $this->pdf;
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
        $pdf->Cell($pdf->GetStringWidth($label), 4, $label, 0, 0, 'L');
        $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
        $pdf->Cell(0, 4, $value, 0, 1, 'L');
        return $y + 4;
    }


    /**
     * @brief Render seller and buyer sections in two columns
     * @param $incoming KsefIncoming object
     * @param $xmlData Parsed XML data
     * @param $y Current Y position
     * @return float New Y position
     * @called_by generate()
     * @calls drawLine(), renderEntity()
     */
    private function renderSellerBuyer($incoming, $xmlData, $y)
    {
        $pdf = $this->pdf;
        $y = $this->drawLine($y);

        $colWidth = ($this->contentWidth - 5) / 2;
        $leftX = $this->marginLeft;
        $rightX = $this->marginLeft + $colWidth + 5;
        $startY = $y;

        // SELLER
        $sellerY = $this->renderEntity($leftX, $y, $colWidth, 'Sprzedawca', array(
            'nip' => $incoming->seller_nip,
            'name' => $incoming->seller_name,
            'address' => $incoming->seller_address,
            'country' => $incoming->seller_country ?? 'PL',
            'email' => $xmlData['seller']['email'] ?? null,
            'phone' => $xmlData['seller']['phone'] ?? null,
        ));

        // BUYER
        $buyerY = $this->renderEntity($rightX, $startY, $colWidth, 'Nabywca', array(
            'nip' => $incoming->buyer_nip,
            'name' => $incoming->buyer_name,
            'address' => $xmlData['buyer']['address'] ?? null,
            'country' => $xmlData['buyer']['country'] ?? 'PL',
            'email' => $xmlData['buyer']['email'] ?? null,
            'phone' => $xmlData['buyer']['phone'] ?? null,
            'customerNumber' => $xmlData['buyer']['customerNumber'] ?? null,
        ));

        return max($sellerY, $buyerY) + 2;
    }


    /**
     * @brief Render entity (seller or buyer) section
     * @param $x X position
     * @param $y Y position
     * @param $width Column width
     * @param $header Section header text
     * @param $data Entity data array
     * @return float New Y position
     * @called_by renderSellerBuyer()
     * @calls formatNIP(), parseAddressLines(), getCountryName()
     */
    private function renderEntity($x, $y, $width, $header, $data)
    {
        $pdf = $this->pdf;

        // Header
        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($x, $y);
        $pdf->Cell($width, 5, $header, 0, 1, 'L');
        $y += 6;

        $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);

        // NIP
        if (!empty($data['nip'])) {
            $pdf->SetXY($x, $y);
            $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
            $pdf->Cell(8, 4, 'NIP: ', 0, 0, 'L');
            $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
            $pdf->Cell($width - 8, 4, $this->formatNIP($data['nip']), 0, 1, 'L');
            $y += 4;
        }

        // Name
        if (!empty($data['name'])) {
            $pdf->SetXY($x, $y);
            $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
            $pdf->Cell(14, 4, 'Nazwa: ', 0, 0, 'L');
            $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
            $pdf->Cell($width - 14, 4, $data['name'], 0, 1, 'L');
            $y += 4;
        }

        // Address header
        $y += 2;
        $pdf->SetXY($x, $y);
        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
        $pdf->Cell($width, 4, 'Adres', 0, 1, 'L');
        $y += 4;

        // Address lines
        $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
        if (!empty($data['address'])) {
            $lines = $this->parseAddressLines($data['address']);
            foreach ($lines as $line) {
                $pdf->SetXY($x, $y);
                $pdf->Cell($width, 4, $line, 0, 1, 'L');
                $y += 4;
            }
        }

        // Country
        if (!empty($data['country'])) {
            $pdf->SetXY($x, $y);
            $pdf->Cell($width, 4, $this->getCountryName($data['country']), 0, 1, 'L');
            $y += 4;
        }

        // Contact data
        if (!empty($data['email']) || !empty($data['phone']) || !empty($data['customerNumber'])) {
            $y += 2;
            $pdf->SetXY($x, $y);
            $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
            $pdf->Cell($width, 4, 'Dane kontaktowe', 0, 1, 'L');
            $y += 4;

            $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);

            if (!empty($data['email'])) {
                $pdf->SetXY($x, $y);
                $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
                $pdf->Cell(14, 4, 'E-mail: ', 0, 0, 'L');
                $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
                $pdf->Cell($width - 14, 4, $data['email'], 0, 1, 'L');
                $y += 4;
            }
            if (!empty($data['phone'])) {
                $pdf->SetXY($x, $y);
                $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
                $pdf->Cell(8, 4, 'Tel.: ', 0, 0, 'L');
                $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
                $pdf->Cell($width - 8, 4, $data['phone'], 0, 1, 'L');
                $y += 4;
            }
            // Customer number (Numer klienta)
            if (!empty($data['customerNumber'])) {
                $pdf->SetXY($x, $y);
                $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
                $pdf->Cell(24, 4, 'Numer klienta: ', 0, 0, 'L');
                $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
                $pdf->Cell($width - 24, 4, $data['customerNumber'], 0, 1, 'L');
                $y += 4;
            }
        }

        return $y;
    }


    /**
     * @brief Render invoice details section (dates, place of issue)
     * @param $incoming KsefIncoming object
     * @param $xmlData Parsed XML data
     * @param $y Current Y position
     * @return float New Y position
     * @called_by generate()
     * @calls checkPageBreak(), drawLine()
     */
    private function renderDetails($incoming, $xmlData, $y)
    {
        $pdf = $this->pdf;
        $y = $this->checkPageBreak($y, 20);
        $y = $this->drawLine($y);

        // Header
        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 5, 'Szczegóły', 0, 1, 'L');
        $y += 5;


        $colWidth = $this->contentWidth / 2;
        $leftX = $this->marginLeft;
        $rightX = $this->marginLeft + $colWidth;
        $detailsFontSize = 6;

        $leftY = $y;
        $rightY = $y;

        // Issue date
        if (!empty($incoming->invoice_date)) {
            $dateStr = is_numeric($incoming->invoice_date) ? date('Y-m-d', $incoming->invoice_date) : $incoming->invoice_date;
            $label = 'Data wystawienia, z zastrzeżeniem art. 106na ust. 1 ustawy: ';

            $pdf->SetXY($leftX, $leftY);
            $pdf->SetFont($this->fontFamily, 'B', $detailsFontSize);
            $labelWidth = $pdf->GetStringWidth($label);

            // Check if label + date fits on one line
            $pdf->SetFont($this->fontFamily, '', $detailsFontSize);
            $dateWidth = $pdf->GetStringWidth($dateStr);

            if ($labelWidth + $dateWidth < $colWidth - 2) {
                $pdf->SetXY($leftX, $leftY);
                $pdf->SetFont($this->fontFamily, 'B', $detailsFontSize);
                $pdf->Cell($labelWidth, 3, $label, 0, 0, 'L');
                $pdf->SetFont($this->fontFamily, '', $detailsFontSize);
                $pdf->Cell($dateWidth, 3, $dateStr, 0, 1, 'L');
                $leftY += 4;
            } else {
                $pdf->SetXY($leftX, $leftY);
                $pdf->SetFont($this->fontFamily, 'B', $detailsFontSize);
                $pdf->MultiCell($colWidth - 2, 3, $label, 0, 'L', false, 1);
                $leftY = $pdf->GetY();
                $pdf->SetXY($leftX, $leftY);
                $pdf->SetFont($this->fontFamily, '', $detailsFontSize);
                $pdf->Cell($dateWidth, 3, $dateStr, 0, 1, 'L');
                $leftY += 4;
            }
        }

        // Place of issue
        if (!empty($xmlData['placeOfIssue'])) {
            $pdf->SetXY($leftX, $leftY);
            $pdf->SetFont($this->fontFamily, 'B', $detailsFontSize);
            $label = 'Miejsce wystawienia: ';
            $pdf->Cell($pdf->GetStringWidth($label), 3, $label, 0, 0, 'L');
            $pdf->SetFont($this->fontFamily, '', $detailsFontSize);
            $pdf->Cell($colWidth - $pdf->GetStringWidth($label), 3, $xmlData['placeOfIssue'], 0, 1, 'L');
            $leftY += 4;
        }

        // RIGHT COLUMN
        // Sale date / delivery date
        if (!empty($incoming->sale_date)) {
            $dateStr = is_numeric($incoming->sale_date) ? date('Y-m-d', $incoming->sale_date) : $incoming->sale_date;
            $label = 'Data dokonania lub zakończenia dostawy towarów lub wykonania usługi: ';

            $pdf->SetXY($rightX, $rightY);
            $pdf->SetFont($this->fontFamily, 'B', $detailsFontSize);
            $labelWidth = $pdf->GetStringWidth($label);

            $pdf->SetFont($this->fontFamily, '', $detailsFontSize);
            $dateWidth = $pdf->GetStringWidth($dateStr);

            if ($labelWidth + $dateWidth < $colWidth - 2) {
                // Fits on one line
                $pdf->SetXY($rightX, $rightY);
                $pdf->SetFont($this->fontFamily, 'B', $detailsFontSize);
                $pdf->Cell($labelWidth, 3, $label, 0, 0, 'L');
                $pdf->SetFont($this->fontFamily, '', $detailsFontSize);
                $pdf->Cell($dateWidth, 3, $dateStr, 0, 1, 'L');
                $rightY += 4;
            } else {
                // Needs to wrap
                $pdf->SetXY($rightX, $rightY);
                $pdf->SetFont($this->fontFamily, 'B', $detailsFontSize);
                $pdf->MultiCell($colWidth - 2, 3, $label, 0, 'L', false, 1);
                $rightY = $pdf->GetY();
                $pdf->SetXY($rightX, $rightY);
                $pdf->SetFont($this->fontFamily, '', $detailsFontSize);
                $pdf->Cell($dateWidth, 3, $dateStr, 0, 1, 'L');
                $rightY += 4;
            }
        }

        $y = max($leftY, $rightY) + 2;

        return $y;
    }


    /**
     * @brief Render invoice line items (Pozycje) table
     * @param $incoming KsefIncoming object
     * @param $xmlData Parsed XML data
     * @param $y Current Y position
     * @return float New Y position
     * @called_by generate()
     * @calls checkPageBreak(), drawLine(), hasAnyField(), drawPositionsTableHeader(), formatQuantity(), formatMoney()
     */
    private function renderPositionsTable($incoming, $xmlData, $y)
    {
        $pdf = $this->pdf;
        $lines = $xmlData['lines'] ?? array();
        $linesBefore = $xmlData['linesBefore'] ?? array();

        if (empty($lines) && empty($linesBefore)) return $y;

        $y = $this->checkPageBreak($y, 30);
        $y = $this->drawLine($y);

        // Header
        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 5, 'Pozycje', 0, 1, 'L');
        $y += 5 + 3;

        // Currency info
        $currency = $incoming->currency ?: 'PLN';
        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeNormal);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 4, 'Faktura wystawiona w cenach netto w walucie ' . $currency, 0, 1, 'L');
        $y += 4 + $this->spaceParagraph;

        $allLines = array_merge($linesBefore, $lines);
        $hasUUID = $this->hasAnyField($allLines, 'uuid');
        $hasGrossPrice = $this->hasAnyField($allLines, 'unit_price_gross');
        $hasDiscount = $this->hasAnyField($allLines, 'discount');
        $hasGrossAmount = $this->hasAnyField($allLines, 'gross_amount');
        $hasStanPrzed = !empty($linesBefore);
        $hasUnit = $this->hasAnyField($allLines, 'unit');

        $cols = array();
        $lpWidth = 8;
        $uuidWidth = $hasUUID ? 30 : 0;
        $priceNetWidth = 14;
        $priceGrossWidth = $hasGrossPrice ? 14 : 0;
        $qtyWidth = 12;
        $unitWidth = $hasUnit ? 10 : 0;
        $discountWidth = $hasDiscount ? 10 : 0;
        $vatRateWidth = 14;
        $netAmtWidth = 18;
        $grossAmtWidth = $hasGrossAmount ? 18 : 0;
        $stanPrzedWidth = $hasStanPrzed ? 12 : 0;

        $fixedWidth = $lpWidth + $uuidWidth + $priceNetWidth + $priceGrossWidth +
            $qtyWidth + $unitWidth + $discountWidth + $vatRateWidth +
            $netAmtWidth + $grossAmtWidth + $stanPrzedWidth;
        $nameWidth = $this->contentWidth - $fixedWidth;

        if ($nameWidth < 25) $nameWidth = 25;

        $cols[] = array('h' => 'Lp.', 'w' => $lpWidth, 'a' => 'C', 'k' => 'num');

        if ($hasUUID) {
            $cols[] = array('h' => "Unikalny numer wiersza", 'w' => $uuidWidth, 'a' => 'L', 'k' => 'uuid');
        }

        $cols[] = array('h' => "Nazwa towaru\nlub usługi", 'w' => $nameWidth, 'a' => 'L', 'k' => 'description');
        $cols[] = array('h' => "Cena\njedn.\nnetto", 'w' => $priceNetWidth, 'a' => 'R', 'k' => 'unit_price_net');

        if ($hasGrossPrice) {
            $cols[] = array('h' => "Cena\njedn.\nbrutto", 'w' => $priceGrossWidth, 'a' => 'R', 'k' => 'unit_price_gross');
        }

        $cols[] = array('h' => 'Ilość', 'w' => $qtyWidth, 'a' => 'R', 'k' => 'quantity');

        if ($hasUnit) {
            $cols[] = array('h' => 'Miara', 'w' => $unitWidth, 'a' => 'C', 'k' => 'unit');
        }

        if ($hasDiscount) {
            $cols[] = array('h' => 'Rabat', 'w' => $discountWidth, 'a' => 'R', 'k' => 'discount');
        }

        $cols[] = array('h' => "Stawka\npodatku", 'w' => $vatRateWidth, 'a' => 'C', 'k' => 'vat_rate');
        $cols[] = array('h' => "Wartość\nsprzedaży\nnetto", 'w' => $netAmtWidth, 'a' => 'R', 'k' => 'net_amount');

        if ($hasGrossAmount) {
            $cols[] = array('h' => "Wartość\nsprzedaży\nbrutto", 'w' => $grossAmtWidth, 'a' => 'R', 'k' => 'gross_amount');
        }

        if ($hasStanPrzed) {
            $cols[] = array('h' => "Stan\nprzed", 'w' => $stanPrzedWidth, 'a' => 'C', 'k' => 'stan_przed');
        }

        $tableFontSize = 7;
        $y = $this->drawPositionsTableHeader($cols, $y);
        $pdf->SetFont($this->fontFamily, '', $tableFontSize);

        $allRenderLines = array();
        foreach ($linesBefore as $line) {
            $line['stan_przed'] = 'Tak';
            $allRenderLines[] = $line;
        }
        foreach ($lines as $line) {
            $line['stan_przed'] = '';
            $allRenderLines[] = $line;
        }

        $pdf->SetLineWidth($this->borderWidth);
        $pdf->SetDrawColorArray($this->colorBorder);

        foreach ($allRenderLines as $line) {
            $rowData = array();
            $maxLines = 1;

            foreach ($cols as $col) {
                $value = '';
                switch ($col['k']) {
                    case 'num': $value = $line['num'] ?? ''; break;
                    case 'uuid': $value = $line['uuid'] ?? ''; break;
                    case 'description': $value = $line['description'] ?? ''; break;
                    case 'quantity': $value = $this->formatQuantity($line['quantity'] ?? 0); break;
                    case 'unit': $value = $line['unit'] ?? ''; break;
                    case 'unit_price_net': $value = isset($line['unit_price_net']) ? $this->formatMoney($line['unit_price_net']) : ''; break;
                    case 'unit_price_gross': $value = isset($line['unit_price_gross']) ? $this->formatMoney($line['unit_price_gross']) : ''; break;
                    case 'discount': $value = isset($line['discount']) ? $this->formatMoney($line['discount']) : ''; break;
                    case 'vat_rate': $value = $line['vat_rate'] ?? ''; break;
                    case 'net_amount': $value = isset($line['net_amount']) ? $this->formatMoney($line['net_amount']) : ''; break;
                    case 'gross_amount': $value = isset($line['gross_amount']) ? $this->formatMoney($line['gross_amount']) : ''; break;
                    case 'stan_przed': $value = $line['stan_przed'] ?? ''; break;
                }
                $rowData[] = $value;

                if (in_array($col['k'], array('uuid', 'description')) && !empty($value)) {
                    $cellLines = $pdf->getNumLines($value, $col['w'] - 1);
                    if ($cellLines > $maxLines) $maxLines = $cellLines;
                }
            }

            $rowHeight = max(5, $maxLines * 3.2);

            $oldY = $y;
            $y = $this->checkPageBreak($y, $rowHeight + 2);
            if ($y < $oldY) {
                // on a new page, redraw the table header
                $y = $this->drawPositionsTableHeader($cols, $y);
                $pdf->SetFont($this->fontFamily, '', $tableFontSize);
                $pdf->SetLineWidth($this->borderWidth);
                $pdf->SetDrawColorArray($this->colorBorder);
            }

            $x = $this->marginLeft;
            foreach ($cols as $i => $col) {
                $pdf->SetXY($x, $y);

                if (in_array($col['k'], array('uuid', 'description'))) {
                    $pdf->Cell($col['w'], $rowHeight, '', 1, 0, $col['a']);
                    $pdf->SetXY($x + 0.5, $y + 0.5);
                    $pdf->MultiCell($col['w'] - 1, 3.2, $rowData[$i], 0, 'L', false, 0);
                } else {
                    $pdf->Cell($col['w'], $rowHeight, $rowData[$i], 1, 0, $col['a']);
                }
                $x += $col['w'];
            }
            $y += $rowHeight;
        }

        return $y + $this->spaceParagraph;
    }


    /**
     * @brief Helper to draw positions table header
     * @param $cols Column definitions array
     * @param $y Current Y position
     * @return float New Y position after header
     * @called_by renderPositionsTable()
     */
    private function drawPositionsTableHeader($cols, $y)
    {
        $pdf = $this->pdf;
        $tableFontSize = 7;
        $headerHeight = 12;

        $pdf->SetFont($this->fontFamily, 'B', $tableFontSize);
        $pdf->SetFillColorArray($this->colorHeaderBg);
        $pdf->SetDrawColorArray($this->colorBorder);
        $pdf->SetLineWidth($this->borderWidth);

        $x = $this->marginLeft;
        foreach ($cols as $col) {
            $pdf->SetXY($x, $y);
            $pdf->Cell($col['w'], $headerHeight, '', 1, 0, 'C', true);
            $pdf->SetXY($x, $y + 1);
            $pdf->MultiCell($col['w'], 3.2, $col['h'], 0, 'C', false, 0);
            $x += $col['w'];
        }
        return $y + $headerHeight;
    }


    /**
     * @brief Render GTIN/Indeks table (if data exists)
     * @param $xmlData Parsed XML data
     * @param $y Current Y position
     * @return float New Y position
     * @called_by generate()
     * @calls hasAnyField(), drawGtinTableHeader()
     */
    private function renderGtinTable($xmlData, $y)
    {
        $pdf = $this->pdf;
        $lines = array_merge($xmlData['linesBefore'] ?? array(), $xmlData['lines'] ?? array());

        $hasGtin = $this->hasAnyField($lines, 'gtin');
        $hasIndeks = $this->hasAnyField($lines, 'indeks');

        if (!$hasGtin && !$hasIndeks) return $y;

        $gtinLines = array();
        foreach ($lines as $line) {
            if (!empty($line['gtin']) || !empty($line['indeks'])) {
                $gtinLines[] = $line;
            }
        }
        if (empty($gtinLines)) return $y;

        $y += 2;

        $cols = array(array('h' => 'Lp.', 'w' => 10));
        if ($hasGtin) $cols[] = array('h' => 'GTIN', 'w' => 30);
        if ($hasIndeks) $cols[] = array('h' => 'Indeks', 'w' => 30);

        $y = $this->drawGtinTableHeader($cols, $y);

        $pdf->SetFont($this->fontFamily, '', $this->fontSizeTable);
        $rowHeight = 4;

        foreach ($gtinLines as $line) {
            // Check if we need a page break
            if ($y + $rowHeight > $this->pageHeight - $this->marginBottom) {
                $pdf->AddPage();
                $y = $this->marginTop;
                $y = $this->drawGtinTableHeader($cols, $y);
                $pdf->SetFont($this->fontFamily, '', $this->fontSizeTable);
            }

            $x = $this->marginLeft;
            $pdf->SetXY($x, $y);
            $pdf->Cell($cols[0]['w'], $rowHeight, $line['num'], 1, 0, 'C');
            $x += $cols[0]['w'];

            $colIdx = 1;
            if ($hasGtin) {
                $pdf->SetXY($x, $y);
                $pdf->Cell($cols[$colIdx]['w'], $rowHeight, $line['gtin'] ?? '', 1, 0, 'L');
                $x += $cols[$colIdx]['w'];
                $colIdx++;
            }
            if ($hasIndeks) {
                $pdf->SetXY($x, $y);
                $pdf->Cell($cols[$colIdx]['w'], $rowHeight, $line['indeks'] ?? '', 1, 0, 'L');
            }
            $y += $rowHeight;
        }

        return $y + $this->spaceTableAfter;
    }


    /**
     * @brief Helper to draw GTIN table header - bold with gray background
     * @param $cols Column definitions array
     * @param $y Current Y position
     * @return float New Y position after header
     * @called_by renderGtinTable()
     */
    private function drawGtinTableHeader($cols, $y)
    {
        $pdf = $this->pdf;
        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeTable);
        $pdf->SetFillColorArray($this->colorHeaderBg);
        $pdf->SetDrawColorArray($this->colorBorder);
        $pdf->SetLineWidth($this->borderWidth);
        $x = $this->marginLeft;
        foreach ($cols as $col) {
            $pdf->SetXY($x, $y);
            $pdf->Cell($col['w'], 5, $col['h'], 1, 0, 'C', true);
            $x += $col['w'];
        }
        return $y + 5;
    }


    /**
     * @brief Render total amount line (Kwota należności ogółem)
     * @param $incoming KsefIncoming object
     * @param $y Current Y position
     * @return float New Y position
     * @called_by generate()
     * @calls checkPageBreak(), formatMoney()
     */
    private function renderTotalAmount($incoming, $y)
    {
        $pdf = $this->pdf;
        $y = $this->checkPageBreak($y, 10);

        $currency = $incoming->currency ?: 'PLN';
        $amount = $this->formatMoney($incoming->total_gross);

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 5, 'Kwota należności ogółem: ' . $amount . ' ' . $currency, 0, 1, 'R');

        return $y + 7;
    }


    /**
     * @brief Render VAT summary table (Podsumowanie stawek podatku)
     * @param $incoming KsefIncoming object
     * @param $xmlData Parsed XML data
     * @param $y Current Y position
     * @return float New Y position
     * @called_by generate()
     * @calls checkPageBreak(), getVatRateLabel(), formatMoney()
     */
    private function renderVatSummary($incoming, $xmlData, $y)
    {
        $pdf = $this->pdf;

        $vatSummary = array();
        if (method_exists($incoming, 'getVatSummary')) {
            $vatSummary = $incoming->getVatSummary();
        }
        if (empty($vatSummary) && !empty($xmlData['vatSummary'])) {
            $vatSummary = $xmlData['vatSummary'];
        }
        if (empty($vatSummary)) return $y;

        $y = $this->checkPageBreak($y, 25);

        // Header
        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 5, 'Podsumowanie stawek podatku', 0, 1, 'L');
        $y += 6;

        // Table columns
        $colW = array(10, 35, 30, 30, 30);
        $headers = array('Lp.', 'Stawka podatku', 'Kwota netto', 'Kwota podatku', 'Kwota brutto');

        // Header row
        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeTable);
        $pdf->SetFillColorArray($this->colorHeaderBg);
        $pdf->SetDrawColorArray($this->colorBorder);
        $pdf->SetLineWidth($this->borderWidth);
        $x = $this->marginLeft;
        foreach ($headers as $i => $h) {
            $pdf->SetXY($x, $y);
            $pdf->Cell($colW[$i], 5, $h, 1, 0, 'C', true);
            $x += $colW[$i];
        }
        $y += 5;

        // Data rows
        $pdf->SetFont($this->fontFamily, '', $this->fontSizeTable);
        $rowNum = 1;
        foreach ($vatSummary as $rate => $amounts) {
            $oldY = $y;
            $y = $this->checkPageBreak($y, 7);
            if ($y < $oldY) {
                // Redraw header on new page
                $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeTable);
                $pdf->SetFillColorArray($this->colorHeaderBg);
                $pdf->SetDrawColorArray($this->colorBorder);
                $pdf->SetLineWidth($this->borderWidth);
                $x = $this->marginLeft;
                foreach ($headers as $i => $h) {
                    $pdf->SetXY($x, $y);
                    $pdf->Cell($colW[$i], 5, $h, 1, 0, 'C', true);
                    $x += $colW[$i];
                }
                $y += 5;
                $pdf->SetFont($this->fontFamily, '', $this->fontSizeTable);
            }

            $x = $this->marginLeft;
            $pdf->SetXY($x, $y);
            $pdf->Cell($colW[0], 5, $rowNum, 1, 0, 'C');
            $x += $colW[0];
            $pdf->SetXY($x, $y);
            $pdf->Cell($colW[1], 5, $this->getVatRateLabel($rate), 1, 0, 'L');
            $x += $colW[1];
            $pdf->SetXY($x, $y);
            $pdf->Cell($colW[2], 5, $this->formatMoney($amounts['net'] ?? 0), 1, 0, 'R');
            $x += $colW[2];
            $pdf->SetXY($x, $y);
            $pdf->Cell($colW[3], 5, $this->formatMoney($amounts['vat'] ?? 0), 1, 0, 'R');
            $x += $colW[3];
            $pdf->SetXY($x, $y);
            $gross = ($amounts['net'] ?? 0) + ($amounts['vat'] ?? 0);
            $pdf->Cell($colW[4], 5, $this->formatMoney($gross), 1, 0, 'R');
            $y += 5;
            $rowNum++;
        }

        return $y + 3;
    }


    /**
     * @brief Render additional info section (Dodatkowe informacje)
     * @param $items Array of info items
     * @param $y Current Y position
     * @return float New Y position
     * @called_by generate()
     * @calls checkPageBreak(), drawLine()
     */
    private function renderAdditionalInfo($items, $y)
    {
        $pdf = $this->pdf;
        $y = $this->checkPageBreak($y, 15);
        $y = $this->drawLine($y);

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 5, 'Dodatkowe informacje', 0, 1, 'L');
        $y += 5;

        $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
        foreach ($items as $item) {
            $pdf->SetXY($this->marginLeft, $y);
            $pdf->Cell($this->contentWidth, 4, '- ' . $item, 0, 1, 'L');
            $y += 4;
        }

        return $y + 2;
    }


    /**
     * @brief Render additional description table (Dodatkowy opis)
     * @param $items Array of description items with rodzaj and tresc
     * @param $y Current Y position
     * @return float New Y position
     * @called_by generate()
     * @calls checkPageBreak()
     */
    private function renderAdditionalDesc($items, $y)
    {
        $pdf = $this->pdf;
        $y = $this->checkPageBreak($y, 20);

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 4, 'Dodatkowy opis', 0, 1, 'L');
        $y += 5;

        $smallTableFont = 6;
        $colW = array(10, 50, 130); // Lp ~5%, Rodzaj ~26%, Treść ~69% of 190mm
        $headers = array('Lp.', 'Rodzaj informacji', 'Treść informacji');

        $pdf->SetFont($this->fontFamily, 'B', $smallTableFont);
        $pdf->SetFillColorArray($this->colorHeaderBg);
        $pdf->SetDrawColorArray($this->colorBorder);
        $pdf->SetLineWidth($this->borderWidth);
        $x = $this->marginLeft;
        foreach ($headers as $i => $h) {
            $pdf->SetXY($x, $y);
            $pdf->Cell($colW[$i], 5, $h, 1, 0, 'C', true);
            $x += $colW[$i];
        }
        $y += 5;

        $pdf->SetFont($this->fontFamily, '', $smallTableFont);
        $rowNum = 1;
        foreach ($items as $item) {
            $tresc = $item['tresc'] ?? '';

            $lineCount = substr_count($tresc, "\n") + 1;
            $rowHeight = max(5, $lineCount * 4);

            $oldY = $y;
            $y = $this->checkPageBreak($y, $rowHeight + 2);
            if ($y < $oldY) {
                // Redraw header on new page
                $pdf->SetFont($this->fontFamily, 'B', $smallTableFont);
                $pdf->SetFillColorArray($this->colorHeaderBg);
                $pdf->SetDrawColorArray($this->colorBorder);
                $pdf->SetLineWidth($this->borderWidth);
                $x = $this->marginLeft;
                foreach ($headers as $i => $h) {
                    $pdf->SetXY($x, $y);
                    $pdf->Cell($colW[$i], 5, $h, 1, 0, 'C', true);
                    $x += $colW[$i];
                }
                $y += 5;
                $pdf->SetFont($this->fontFamily, '', $smallTableFont);
            }

            $x = $this->marginLeft;

            // Lp.
            $pdf->SetXY($x, $y);
            $pdf->Cell($colW[0], $rowHeight, $rowNum, 1, 0, 'C');
            $x += $colW[0];

            // Rodzaj
            $pdf->SetXY($x, $y);
            $pdf->Cell($colW[1], $rowHeight, $item['rodzaj'] ?? '', 1, 0, 'L');
            $x += $colW[1];

            // Treść
            $pdf->SetXY($x, $y);
            if ($lineCount > 1) {
                $pdf->Cell($colW[2], $rowHeight, '', 1, 0, 'L');
                $pdf->SetXY($x + 1, $y + 1);
                $pdf->MultiCell($colW[2] - 2, 4, $tresc, 0, 'L', false, 0);
            } else {
                $pdf->Cell($colW[2], $rowHeight, $tresc, 1, 0, 'L');
            }

            $y += $rowHeight;
            $rowNum++;
        }

        return $y + 3;
    }


    /**
     * @brief Render payment section (Płatność)
     * @param $incoming KsefIncoming object
     * @param $xmlData Parsed XML data
     * @param $y Current Y position
     * @return float New Y position
     * @called_by generate()
     * @calls checkPageBreak(), drawLine(), renderLabelValue(), getPaymentMethodLabel(), renderBankAccount()
     */
    private function renderPayment($incoming, $xmlData, $y)
    {
        $pdf = $this->pdf;
        $payment = $xmlData['payment'] ?? array();
        if (empty($payment) && empty($incoming->payment_method)) return $y;

        $y = $this->checkPageBreak($y, 30);
        $y = $this->drawLine($y);

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 5, 'Płatność', 0, 1, 'L');
        $y += 6;

        $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);

        // Status
        if (!empty($payment['status'])) {
            $statusLabel = 'Brak zapłaty';
            if ($payment['status'] == 'paid') $statusLabel = 'Zapłacono';
            elseif ($payment['status'] == 'partial') $statusLabel = 'Zapłata częściowa';
            $y = $this->renderLabelValue($y, 'Informacja o płatności: ', $statusLabel);
        }

        // Payment date
        if (!empty($payment['paymentDate'])) {
            $y = $this->renderLabelValue($y, 'Data zapłaty: ', $payment['paymentDate']);
        }

        // Payment method
        $method = $incoming->payment_method ?? $payment['method'] ?? null;
        if (!empty($method)) {
            $y = $this->renderLabelValue($y, 'Forma płatności: ', $this->getPaymentMethodLabel($method));
        }

        // Payment due date
        if (!empty($payment['dueDate'])) {
            $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
            $pdf->SetXY($this->marginLeft, $y);
            $pdf->Cell($this->contentWidth, 4, 'Termin płatności', 0, 1, 'L');
            $y += 4;
            $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
            $pdf->SetXY($this->marginLeft, $y);
            $pdf->Cell($this->contentWidth, 4, $payment['dueDate'], 0, 1, 'L');
            $y += 5;
        }

        // Bank account
        if (!empty($payment['bankAccount'])) {
            $y = $this->renderBankAccount($payment['bankAccount'], $y);
        }

        return $y + 2;
    }


    /**
     * @brief Render bank account details table
     * @param $bankAccount Bank account data array
     * @param $y Current Y position
     * @return float New Y position
     * @called_by renderPayment()
     */
    private function renderBankAccount($bankAccount, $y)
    {
        $pdf = $this->pdf;

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 4, 'Numer rachunku bankowego', 0, 1, 'L');
        $y += 5;

        $labelW = 45;
        $valueW = 90;

        $pdf->SetFont($this->fontFamily, '', $this->fontSizeTable);
        $pdf->SetFillColorArray($this->colorHeaderBg);
        $pdf->SetDrawColorArray($this->colorBorder);
        $pdf->SetLineWidth($this->borderWidth);

        $fields = array(
            array('label' => 'Pełny numer rachunku', 'key' => 'accountNumber'),
            array('label' => 'Kod SWIFT', 'key' => 'swift'),
            array('label' => 'Rachunek własny banku', 'key' => 'ownAccount'),
            array('label' => 'Nazwa banku', 'key' => 'bankName'),
            array('label' => 'Opis rachunku', 'key' => 'description'),
        );

        foreach ($fields as $field) {
            $x = $this->marginLeft;
            $pdf->SetXY($x, $y);
            $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeTable);
            $pdf->Cell($labelW, 5, $field['label'], 1, 0, 'L', true);
            $pdf->SetFont($this->fontFamily, '', $this->fontSizeTable);
            $pdf->Cell($valueW, 5, $bankAccount[$field['key']] ?? '', 1, 1, 'L');
            $y += 5;
        }

        return $y + 2;
    }


    /**
     * @brief Render registries section (KRS, REGON, BDO)
     * @param $registries Registry data array
     * @param $y Current Y position
     * @return float New Y position
     * @called_by generate()
     * @calls checkPageBreak(), drawLine()
     */
    private function renderRegistries($registries, $y)
    {
        $pdf = $this->pdf;
        $y = $this->checkPageBreak($y, 20);
        $y = $this->drawLine($y);

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 5, 'Rejestry', 0, 1, 'L');
        $y += 6;

        $colW = 35;

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeTable);
        $pdf->SetFillColorArray($this->colorHeaderBg);
        $pdf->SetDrawColorArray($this->colorBorder);
        $pdf->SetLineWidth($this->borderWidth);

        $x = $this->marginLeft;
        $pdf->SetXY($x, $y);
        $pdf->Cell($colW, 5, 'KRS', 1, 0, 'L', true);
        $x += $colW;
        $pdf->SetXY($x, $y);
        $pdf->Cell($colW, 5, 'REGON', 1, 0, 'L', true);
        $x += $colW;
        $pdf->SetXY($x, $y);
        $pdf->Cell($colW, 5, 'BDO', 1, 0, 'L', true);
        $y += 5;

        $pdf->SetFont($this->fontFamily, '', $this->fontSizeTable);
        $x = $this->marginLeft;
        $pdf->SetXY($x, $y);
        $pdf->Cell($colW, 5, $registries['krs'] ?? '', 1, 0, 'L');
        $x += $colW;
        $pdf->SetXY($x, $y);
        $pdf->Cell($colW, 5, $registries['regon'] ?? '', 1, 0, 'L');
        $x += $colW;
        $pdf->SetXY($x, $y);
        $pdf->Cell($colW, 5, $registries['bdo'] ?? '', 1, 0, 'L');
        $y += 5;

        return $y + 3;
    }


    /**
     * @brief Render Stopka info section
     * @param $stopkaInfo Stopka faktury text value
     * @param $y Current Y position
     * @return float New Y position
     * @called_by generate()
     * @calls checkPageBreak(), drawLine()
     */
    private function renderStopkaInfo($stopkaInfo, $y)
    {
        if (empty($stopkaInfo)) {
            return $y;
        }

        $pdf = $this->pdf;
        $y = $this->checkPageBreak($y, 25);
        $y = $this->drawLine($y);

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 5, 'Pozostałe informacje', 0, 1, 'L');
        $y += 5 + $this->spaceSectionAfter;

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 4, 'Stopka faktury', 0, 1, 'L');
        $y += 4;

        $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->MultiCell($this->contentWidth, 4, $stopkaInfo, 0, 'L');
        $y = $pdf->GetY() + $this->spaceSectionAfter;

        return $y;
    }


    /**
     * @brief Render QR code section with verification URL
     * @param $incoming KsefIncoming object
     * @param $xmlData Parsed XML data
     * @param $y Current Y position
     * @return float New Y position
     * @called_by generate()
     * @calls getVerificationUrl(), checkPageBreak(), drawLine()
     */
    private function renderQrCode($incoming, $xmlData, $y)
    {
        $pdf = $this->pdf;
        $verificationUrl = $this->getVerificationUrl($incoming, $xmlData);
        if (empty($verificationUrl) && empty($incoming->ksef_number)) return $y;

        $y = $this->checkPageBreak($y, 55);
        $y = $this->drawLine($y);

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 5, 'Sprawdź, czy Twoja faktura znajduje się w KSeF!', 0, 1, 'L');
        $y += 6;

        $qrSize = 40;
        $textX = $this->marginLeft + $qrSize + 5;
        $textWidth = $this->contentWidth - $qrSize - 5;

        // QR code
        if (!empty($verificationUrl)) {
            $pdf->write2DBarcode($verificationUrl, 'QRCODE,M', $this->marginLeft, $y, $qrSize, $qrSize);
        }

        if (!empty($incoming->ksef_number)) {
            $pdf->SetFont($this->fontFamily, '', 5);
            $ksefNum = $incoming->ksef_number;
            if (strlen($ksefNum) > 35) {
                $part1 = substr($ksefNum, 0, 35);
                $part2 = substr($ksefNum, 35);
                $pdf->SetXY($this->marginLeft, $y + $qrSize + 1);
                $pdf->Cell($qrSize, 2.5, $part1, 0, 1, 'C');
                $pdf->SetXY($this->marginLeft, $y + $qrSize + 3.5);
                $pdf->Cell($qrSize, 2.5, $part2, 0, 1, 'C');
            } else {
                $pdf->SetXY($this->marginLeft, $y + $qrSize + 1);
                $pdf->Cell($qrSize, 3, $ksefNum, 0, 1, 'C');
            }
        }

        $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
        $pdf->SetXY($textX, $y);
        $pdf->Cell($textWidth, 4, 'Nie możesz zeskanować kodu z obrazka? Kliknij w link weryfikacyjny i przejdź do weryfikacji faktury!', 0, 1, 'L');

        if (!empty($verificationUrl)) {
            $pdf->SetTextColorArray($this->colorLink);
            $pdf->SetXY($textX, $y + 6);
            $pdf->MultiCell($textWidth, 4, $verificationUrl, 0, 'L');
            $pdf->SetTextColorArray($this->colorText);
        }

        return $y + $qrSize + 10;
    }


    /**
     * @brief Render footer
     * @param $xmlData Parsed XML data
     * @called_by generate()
     */
    private function renderFooter($xmlData)
    {
        $pdf = $this->pdf;
        $footerY = $this->pageHeight - $this->marginBottom + 2;

        $pdf->SetDrawColorArray($this->colorLine);
        $pdf->SetLineWidth(0.3);
        $pdf->Line($this->marginLeft, $footerY - 4, $this->marginLeft + $this->contentWidth, $footerY - 4);

        $pdf->SetFont($this->fontFamily, '', $this->fontSizeTable);

        if (!empty($xmlData['systemInfo'])) {
            $pdf->SetXY($this->marginLeft, $footerY - 3);
            $pdf->Cell($this->contentWidth, 3, 'Wytworzona w:' . $xmlData['systemInfo'], 0, 1, 'L');
        }

        $pdf->SetXY($this->marginLeft, $footerY);
        $pdf->SetFont($this->fontFamily, '', $this->fontSizeTable);
        $pdf->Cell($this->contentWidth, 3, 'Wizualizacja danych XML wygenerowana przez Dolibarr z użyciem TCPDF', 0, 0, 'L');
    }


    /**
     * @brief Check if any line has a specific field populated
     * @param $lines Array of line items
     * @param $field Field name to check
     * @return bool True if at least one line has the field
     * @called_by renderPositionsTable(), renderGtinTable()
     */
    private function hasAnyField($lines, $field)
    {
        foreach ($lines as $line) {
            if (!empty($line[$field])) return true;
        }
        return false;
    }


    /**
     * @brief Format NIP
     * @param $nip NIP string
     * @return string Cleaned NIP
     * @called_by renderEntity()
     */
    private function formatNIP($nip)
    {
        return preg_replace('/[^0-9]/', '', $nip);
    }


    /**
     * @brief Format money
     * @param $value Numeric value
     * @return string Formatted money string
     * @called_by renderPositionsTable(), renderTotalAmount(), renderVatSummary()
     */
    private function formatMoney($value)
    {
        if ($value === null || $value === '') return '';
        // Use comma as decimal separator (Polish format)
        return number_format((float)$value, 2, ',', ' ');
    }


    /**
     * @brief Format quantity
     * @param $value Numeric value
     * @return string Formatted quantity string
     * @called_by renderPositionsTable()
     */
    private function formatQuantity($value)
    {
        if ($value === null || $value === '') return '';
        $num = (float)$value;
        return number_format($num, 2, '.', ' ');
    }


    /**
     * @brief Get country name from code
     * @param $code Country code (e.g., 'PL')
     * @return string Country name in Polish
     * @called_by renderEntity()
     */
    private function getCountryName($code)
    {
        $code = strtoupper(trim($code));
        return $this->countryNames[$code] ?? $code;
    }


    /**
     * @brief Parse address into display lines
     * @param $address Address string
     * @return array Array of address lines
     * @called_by renderEntity()
     */
    private function parseAddressLines($address)
    {
        if (strpos($address, "\n") !== false) {
            return array_filter(explode("\n", $address));
        }
        if (preg_match('/^(.+?),\s*(\d{2}-\d{3}\s+.+)$/', $address, $m)) {
            return array(trim($m[1]), trim($m[2]));
        }
        return array($address);
    }


    /**
     * @brief Get invoice type label in Polish
     * @param $type Invoice type code (VAT, ZAL, KOR, etc.)
     * @return string Invoice type label
     * @called_by renderHeader()
     */
    private function getInvoiceTypeLabel($type)
    {
        $labels = array(
            'VAT' => 'Faktura podstawowa',
            'ZAL' => 'Faktura zaliczkowa',
            'KOR' => 'Faktura korygująca',
            'ROZ' => 'Faktura rozliczeniowa',
            'UPR' => 'Faktura uproszczona',
            'KOR_ZAL' => 'Faktura korygująca zaliczkowa',
            'KOR_ROZ' => 'Faktura korygująca rozliczeniowa',
        );
        return $labels[$type] ?? $type;
    }


    /**
     * @brief Get correction type label in Polish
     * @param $type Correction type code
     * @return string Correction type label
     * @called_by renderCorrectionData()
     */
    private function getCorrectionTypeLabel($type)
    {
        $labels = array(
            '1' => 'Korekta skutkująca w dacie ujęcia faktury pierwotnej',
            '2' => 'Korekta skutkująca w bieżącym okresie rozliczeniowym',
            '3' => 'Korekta skutkująca w dacie innej, w tym gdy dla różnych pozycji faktury korygującej daty te są różne',
        );
        return $labels[$type] ?? $type;
    }


    /**
     * @brief Get VAT rate label
     * @param $rate VAT rate code
     * @return string VAT rate label
     * @called_by renderVatSummary()
     */
    private function getVatRateLabel($rate)
    {
        $labels = array(
            '23' => '23% lub 22%', '22' => '23% lub 22%',
            '8' => '8% lub 7%', '7' => '8% lub 7%',
            '5' => '5%', '4' => '5%',
            '3' => '3%', '0' => '0%',
            'zw' => 'Zwolnione', 'np' => 'Niepodlegające', 'oo' => 'Odwrotne obciążenie',
        );
        return $labels[$rate] ?? $rate . '%';
    }


    /**
     * @brief Get payment method label in Polish
     * @param $method Payment method code
     * @return string Payment method label
     * @called_by renderPayment()
     */
    private function getPaymentMethodLabel($method)
    {
        $labels = array(
            '1' => 'Gotówka', '2' => 'Karta', '3' => 'Bon',
            '4' => 'Czek', '5' => 'Kredyt', '6' => 'Przelew', '7' => 'Płatność mobilna',
        );
        return $labels[$method] ?? 'Inna';
    }


    /**
     * @brief Get KSeF verification URL
     * @param $incoming KsefIncoming object
     * @param $xmlData Parsed XML data
     * @return string Verification URL or empty string
     * @called_by renderQrCode()
     */
    private function getVerificationUrl($incoming, $xmlData)
    {
        if (!empty($xmlData['verificationUrl'])) {
            return $xmlData['verificationUrl'];
        }

        if (!empty($incoming->fa3_xml) && !empty($incoming->ksef_number)) {
            $env = getDolGlobalString('KSEF_ENVIRONMENT', 'PRODUCTION');

            $baseUrl = '';
            switch (strtoupper($env)) {
                case 'PRODUCTION':
                    $baseUrl = 'https://qr.ksef.mf.gov.pl';
                    break;
                case 'DEMO':
                    $baseUrl = 'https://qr-demo.ksef.mf.gov.pl';
                    break;
                case 'TEST':
                default:
                    $baseUrl = 'https://qr-test.ksef.mf.gov.pl';
                    break;
            }

            $parts = explode('-', $incoming->ksef_number);
            $nip = $parts[0] ?? '';

            if (empty($nip)) {
                return '';
            }

            $dateStr = $parts[1] ?? '';
            if (strlen($dateStr) == 8) {
                $formattedDate = substr($dateStr, 6, 2) . '-' . substr($dateStr, 4, 2) . '-' . substr($dateStr, 0, 4);
            } else {
                $formattedDate = date('d-m-Y');
            }

            $rawHash = hash('sha256', $incoming->fa3_xml, true);
            $hashBase64Url = rtrim(strtr(base64_encode($rawHash), '+/', '-_'), '=');

            return $baseUrl . '/invoice/' . $nip . '/' . $formattedDate . '/' . $hashBase64Url;
        }

        return '';
    }


    /**
     * @brief Parse FA(3) XML data into structured array
     * @param $xml Raw XML string
     * @return array Parsed data structure
     * @called_by generate()
     * @calls parseLineNode()
     */
    private function parseXmlData($xml)
    {
        $data = array(
            'seller' => array(),
            'buyer' => array(),
            'correctionData' => array(),
            'payment' => array(),
            'registries' => array(),
            'stopkaInfo' => null,
            'lines' => array(),
            'linesBefore' => array(),
            'vatSummary' => array(),
            'additionalInfo' => array(),
            'additionalDesc' => array(),
            'placeOfIssue' => null,
            'systemInfo' => null,
            'verificationUrl' => null,
        );

        if (empty($xml)) return $data;

        try {
            $doc = new DOMDocument();
            $doc->loadXML($xml);
            $xpath = new DOMXPath($doc);
            $xpath->registerNamespace('crd', KSEF_FA3_NAMESPACE);

            $get = function($q, $ctx = null) use ($xpath) {
                $n = $xpath->query($q, $ctx);
                return $n->length > 0 ? trim($n->item(0)->textContent) : null;
            };

            $data['systemInfo'] = $get('//crd:Naglowek/crd:SystemInfo');
            $data['placeOfIssue'] = $get('//crd:Fa/crd:P_1M');

            // Seller
            $data['seller']['email'] = $get('//crd:Podmiot1/crd:DaneKontaktowe/crd:Email');
            $data['seller']['phone'] = $get('//crd:Podmiot1/crd:DaneKontaktowe/crd:Telefon');

            // Buyer
            $data['buyer']['email'] = $get('//crd:Podmiot2/crd:DaneKontaktowe/crd:Email');
            $data['buyer']['phone'] = $get('//crd:Podmiot2/crd:DaneKontaktowe/crd:Telefon');
            $data['buyer']['customerNumber'] = $get('//crd:Podmiot2/crd:NrKlienta');
            $l1 = $get('//crd:Podmiot2/crd:Adres/crd:AdresL1');
            $l2 = $get('//crd:Podmiot2/crd:Adres/crd:AdresL2');
            $data['buyer']['address'] = $l1 . ($l2 ? "\n" . $l2 : '');
            $data['buyer']['country'] = $get('//crd:Podmiot2/crd:Adres/crd:KodKraju');

            // Correction data
            $data['correctionData']['reason'] = $get('//crd:Fa/crd:PrzyczynaKorekty');
            $data['correctionData']['type'] = $get('//crd:Fa/crd:TypKorekty');

            // Corrected invoices
            $data['correctionData']['correctedInvoices'] = array();
            foreach ($xpath->query('//crd:Fa/crd:DaneFaKorygowanej') as $node) {
                $data['correctionData']['correctedInvoices'][] = array(
                    'issueDate' => $get('crd:DataWystFaKorygowanej', $node),
                    'number' => $get('crd:NrFaKorygowanej', $node),
                    'ksefNumber' => $get('crd:NrKSeFFaKorygowanej', $node),
                );
            }

            // Payment
            $zaplacono = $get('//crd:Fa/crd:Platnosc/crd:Zaplacono');
            $czesciowa = $get('//crd:Fa/crd:Platnosc/crd:ZnacznikZaplatyCzesciowej');
            $data['payment']['status'] = $zaplacono == '1' ? 'paid' : ($czesciowa == '1' ? 'partial' : 'unpaid');
            $data['payment']['paymentDate'] = $get('//crd:Fa/crd:Platnosc/crd:DataZaplaty');
            $data['payment']['method'] = $get('//crd:Fa/crd:Platnosc/crd:FormaPlatnosci');

            // Payment due date
            $data['payment']['dueDate'] = $get('//crd:Fa/crd:Platnosc/crd:TerminPlatnosci/crd:Termin');
            if (empty($data['payment']['dueDate'])) {
                $data['payment']['dueDate'] = $get('//crd:Fa/crd:TerminPlatnosci');
            }

            // Bank account
            $rachunekNode = $xpath->query('//crd:Fa/crd:Platnosc/crd:RachunekBankowy')->item(0);
            if ($rachunekNode) {
                $data['payment']['bankAccount'] = array(
                    'accountNumber' => $get('crd:NrRB', $rachunekNode),
                    'swift' => $get('crd:SWIFT', $rachunekNode),
                    'ownAccount' => $get('crd:RachunekWlasnyBanku', $rachunekNode),
                    'bankName' => $get('crd:NazwaBanku', $rachunekNode),
                    'description' => $get('crd:OpisRachunku', $rachunekNode),
                );
            }

            // Registries
            $data['registries']['krs'] = $get('//crd:Stopka/crd:Rejestry/crd:KRS');
            $data['registries']['regon'] = $get('//crd:Stopka/crd:Rejestry/crd:REGON');
            $data['registries']['bdo'] = $get('//crd:Stopka/crd:Rejestry/crd:BDO');

            // Stopka
            $data['stopkaInfo'] = $get('//crd:Stopka/crd:Informacje/crd:StopkaFaktury');

            // Additional info
            if ($get('//crd:Fa/crd:FP') == '1') {
                $data['additionalInfo'][] = 'Faktura, o której mowa w art. 109 ust. 3d ustawy';
            }

            // Additional desc
            foreach ($xpath->query('//crd:Fa/crd:DodatkowyOpis') as $node) {
                $rodzaj = $get('crd:Klucz', $node);
                $tresc = $get('crd:Wartosc', $node);
                if ($rodzaj && $tresc) {
                    $data['additionalDesc'][] = array('rodzaj' => $rodzaj, 'tresc' => $tresc);
                }
            }

            // Lines
            $lineNodesBefore = $xpath->query('//crd:FaWiersz[crd:StanPrzed = 1]');
            $lineNodesAfter = $xpath->query('//crd:FaWiersz[not(crd:StanPrzed = 1)]');
            if ($lineNodesBefore->length == 0 && $lineNodesAfter->length == 0) {
                $lineNodesAfter = $xpath->query('//crd:FaWiersz');
            }

            $num = 1;
            foreach ($lineNodesBefore as $node) {
                $line = $this->parseLineNode($node, $get, $num);
                $data['linesBefore'][] = $line;
                $num++;
            }

            $num = 1;
            foreach ($lineNodesAfter as $node) {
                $line = $this->parseLineNode($node, $get, $num);
                $data['lines'][] = $line;
                $num++;
            }

            // VAT summary
            $vatRates = array(
                '23' => array('net' => 'P_13_1', 'vat' => 'P_14_1'),
                '8' => array('net' => 'P_13_2', 'vat' => 'P_14_2'),
                '5' => array('net' => 'P_13_3', 'vat' => 'P_14_3'),
                '0' => array('net' => 'P_13_4', 'vat' => 'P_14_4'),
            );
            foreach ($vatRates as $rate => $fields) {
                $net = $get('//crd:Fa/crd:' . $fields['net']);
                $vat = $get('//crd:Fa/crd:' . $fields['vat']);
                if ($net && (float)$net != 0) {
                    $data['vatSummary'][$rate] = array(
                        'net' => (float)$net,
                        'vat' => (float)($vat ?: 0),
                    );
                }
            }

        } catch (Exception $e) {
            dol_syslog("KsefInvoicePdf::parseXmlData ERROR: " . $e->getMessage(), LOG_WARNING);
        }

        return $data;
    }


    /**
     * @brief Parse a single line item node from XML
     * @param $node DOMNode for the line
     * @param $get Closure for XPath queries
     * @param $defaultNum Default line number if not in XML
     * @return array Parsed line data
     * @called_by parseXmlData()
     */
    private function parseLineNode($node, $get, $defaultNum)
    {
        $line = array(
            'num' => $get('crd:NrWierszaFa', $node) ?: $defaultNum,
            'uuid' => $get('crd:UU_ID', $node),
            'indeks' => $get('crd:Indeks', $node),
            'gtin' => $get('crd:GTIN', $node),
            'description' => $get('crd:P_7', $node),
            'quantity' => $get('crd:P_8B', $node),
            'unit' => $get('crd:P_8A', $node),
            'unit_price_net' => $get('crd:P_9A', $node),
            'unit_price_gross' => $get('crd:P_9B', $node),
            'discount' => $get('crd:P_10', $node),
            'vat_rate' => $get('crd:P_12', $node),
            'net_amount' => $get('crd:P_11', $node),
            'gross_amount' => $get('crd:P_11A', $node),
        );
        return array_filter($line, function($v) { return $v !== null; });
    }
}