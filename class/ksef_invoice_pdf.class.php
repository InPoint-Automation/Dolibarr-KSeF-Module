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
 * @brief KSeF invoice PDF
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
    private $parsed = null;

    private $colorText = array(52, 58, 64);
    private $colorLine = array(186, 186, 186);
    private $colorHeaderBg = array(246, 247, 250);
    private $colorBorder = array(186, 186, 186);
    private $colorLink = array(0, 0, 255);
    private $borderWidth = 0.3;

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

    private $spaceSectionAfter = 3;
    private $spaceLineAfter = 4;
    private $spaceLineBefore = 4;
    private $spaceTableAfter = 4;
    private $spaceParagraph = 2;
    private $lineHeight = 1.2;

    private $countryNames = array(
        'AD' => 'Andora', 'AE' => 'Zjednoczone Emiraty Arabskie', 'AF' => 'Afganistan', 'AG' => 'Antigua i Barbuda',
        'AI' => 'Anguilla', 'AL' => 'Albania', 'AM' => 'Armenia', 'AN' => 'Antyle Holenderskie',
        'AO' => 'Angola', 'AQ' => 'Antarktyda', 'AR' => 'Argentyna', 'AS' => 'Samoa Amerykańskie',
        'AT' => 'Austria', 'AU' => 'Australia', 'AW' => 'Aruba', 'AX' => 'Aland Islands',
        'AZ' => 'Azerbejdżan', 'BA' => 'Bośnia i Hercegowina', 'BB' => 'Barbados', 'BD' => 'Bangladesz',
        'BE' => 'Belgia', 'BF' => 'Burkina Faso', 'BG' => 'Bułgaria', 'BH' => 'Bahrajn',
        'BI' => 'Burundi', 'BJ' => 'Benin', 'BL' => 'Saint Barthelemy', 'BM' => 'Bermudy',
        'BN' => 'Brunei Darussalam', 'BO' => 'Boliwia', 'BQ' => 'Bonaire, Sint Eustatius i Saba', 'BR' => 'Brazylia',
        'BS' => 'Bahamy', 'BT' => 'Bhutan', 'BV' => 'Wyspa Bouveta', 'BW' => 'Botswana',
        'BY' => 'Białoruś', 'BZ' => 'Belize', 'CA' => 'Kanada', 'CC' => 'Wyspy Kokosowe (Keelinga)',
        'CD' => 'Kongo, Republika Demokratyczna', 'CF' => 'Rep.środkowoafrykańska', 'CG' => 'Kongo', 'CH' => 'Szwajcaria',
        'CI' => 'Wybrzeże Kości Słoniowej', 'CK' => 'Wyspy Cooka', 'CL' => 'Chile', 'CM' => 'Kamerun',
        'CN' => 'Chiny', 'CO' => 'Kolumbia', 'CR' => 'Kostaryka', 'CU' => 'Kuba',
        'CV' => 'Zielony Przylądek', 'CW' => 'Curaçao', 'CX' => 'Wyspa Bożego Narodzenia', 'CY' => 'Cypr',
        'CZ' => 'Republika Czeska', 'DE' => 'Niemcy', 'DJ' => 'Dżibuti', 'DK' => 'Dania',
        'DM' => 'Dominika', 'DO' => 'Dominikana', 'DZ' => 'Algieria', 'EC' => 'Ekwador',
        'EE' => 'Estonia', 'EG' => 'Egipt', 'EH' => 'Sahara Zachodnia', 'ER' => 'Erytrea',
        'ES' => 'Hiszpania', 'ET' => 'Etiopia', 'FI' => 'Finlandia', 'FJ' => 'Fidżi Republika',
        'FK' => 'Falklandy', 'FM' => 'Mikronezja', 'FO' => 'Wyspy Owcze', 'FR' => 'Francja',
        'GA' => 'Gabon', 'GB' => 'Wielka Brytania', 'GD' => 'Grenada', 'GE' => 'Gruzja',
        'GF' => 'Gujana Francuska', 'GG' => 'Guernsey', 'GH' => 'Ghana', 'GI' => 'Gibraltar',
        'GL' => 'Grenlandia', 'GM' => 'Gambia', 'GN' => 'Gwinea', 'GP' => 'Gwadelupa',
        'GQ' => 'Gwinea Równikowa', 'GR' => 'Grecja', 'GS' => 'Południowa Georgia i Połud.Wyspy Sandwich', 'GT' => 'Gwatemala',
        'GU' => 'Guam', 'GW' => 'Gwinea Bissau', 'GY' => 'Gujana', 'HK' => 'Hongkong',
        'HM' => 'Wyspy Heard i Mcdonald', 'HN' => 'Honduras', 'HR' => 'Chorwacja', 'HT' => 'Haiti',
        'HU' => 'Węgry', 'ID' => 'Indonezja', 'IE' => 'Irlandia', 'IL' => 'Izrael',
        'IM' => 'Wyspa Man', 'IN' => 'Indie', 'IO' => 'Brytyjskie Terytorium Oceanu Indyjskiego', 'IQ' => 'Irak',
        'IR' => 'Iran', 'IS' => 'Islandia', 'IT' => 'Włochy', 'JE' => 'Jersey',
        'JM' => 'Jamajka', 'JO' => 'Jordania', 'JP' => 'Japonia', 'KE' => 'Kenia',
        'KG' => 'Kirgistan', 'KH' => 'Kambodża', 'KI' => 'Kiribati', 'KM' => 'Komory',
        'KN' => 'Saint Kitts i Nevis', 'KP' => 'Koreańska Republika Ludowo-Demokratyczna', 'KR' => 'Republika Korei', 'KW' => 'Kuwejt',
        'KY' => 'Kajmany', 'KZ' => 'Kazachstan', 'LA' => 'Laos', 'LB' => 'Liban',
        'LC' => 'Saint Lucia', 'LI' => 'Liechtenstein', 'LK' => 'Sri Lanka', 'LR' => 'Liberia',
        'LS' => 'Lesotho', 'LT' => 'Litwa', 'LU' => 'Luksemburg', 'LV' => 'Łotwa',
        'LY' => 'Libia', 'MA' => 'Maroko', 'MC' => 'Monako', 'MD' => 'Mołdowa',
        'ME' => 'Czarnogóra', 'MF' => 'Saint Martin', 'MG' => 'Madagaskar', 'MH' => 'Wyspy Marshalla',
        'MK' => 'Macedonia', 'ML' => 'Mali', 'MM' => 'Myanmar (Burma)', 'MN' => 'Mongolia',
        'MO' => 'Makau', 'MP' => 'Mariany Północne', 'MQ' => 'Martynika', 'MR' => 'Mauretania',
        'MS' => 'Montserrat', 'MT' => 'Malta', 'MU' => 'Mauritius', 'MV' => 'Malediwy',
        'MW' => 'Malawi', 'MX' => 'Meksyk', 'MY' => 'Malezja', 'MZ' => 'Mozambik',
        'NA' => 'Namibia', 'NC' => 'Nowa Kaledonia', 'NE' => 'Niger', 'NF' => 'Norfolk',
        'NG' => 'Nigeria', 'NI' => 'Nikaragua', 'NL' => 'Niderlandy (Holandia)', 'NO' => 'Norwegia',
        'NP' => 'Nepal', 'NR' => 'Nauru', 'NU' => 'Niue', 'NZ' => 'Nowa Zelandia',
        'OM' => 'Oman', 'PA' => 'Panama', 'PE' => 'Peru', 'PF' => 'Polinezja Francuska',
        'PG' => 'Papua Nowa Gwinea', 'PH' => 'Filipiny', 'PK' => 'Pakistan', 'PL' => 'Polska',
        'PM' => 'Saint Pierre i Miquelon', 'PN' => 'Pitcairn', 'PR' => 'Portoryko', 'PS' => 'Okupowane Terytorium Palestyny',
        'PT' => 'Portugalia', 'PW' => 'Palau', 'PY' => 'Paragwaj', 'QA' => 'Katar',
        'RE' => 'Reunion', 'RO' => 'Rumunia', 'RS' => 'Serbia', 'RU' => 'Rosja',
        'RW' => 'Rwanda', 'SA' => 'Arabia Saudyjska', 'SB' => 'Wyspy Salomona', 'SC' => 'Seszele',
        'SD' => 'Sudan', 'SE' => 'Szwecja', 'SG' => 'Singapur', 'SH' => 'Święta Helena',
        'SI' => 'Słowenia', 'SJ' => 'Svalbard i Jan Mayen', 'SK' => 'Słowacja', 'SL' => 'Sierra Leone',
        'SM' => 'San Marino', 'SN' => 'Senegal', 'SO' => 'Somalia', 'SR' => 'Surinam',
        'SS' => 'Sudan Południowy', 'ST' => 'Wyspy Świętego Tomasza i Książęca', 'SV' => 'Salwador', 'SX' => 'Wyspa Sint Maarten (część holenderska wyspy)',
        'SY' => 'Syria', 'SZ' => 'Suazi', 'TC' => 'Wyspy Turks i Caicos', 'TD' => 'Czad',
        'TF' => 'Francuskie Terytorium Południowe', 'TG' => 'Togo', 'TH' => 'Tajlandia', 'TJ' => 'Tadżykistan',
        'TK' => 'Tokelau', 'TL' => 'Wschodni Timor', 'TM' => 'Turkmenistan', 'TN' => 'Tunezja',
        'TO' => 'Tonga', 'TR' => 'Turcja', 'TT' => 'Trynidad i Tobago', 'TV' => 'Tuvalu',
        'TW' => 'Tajwan', 'TZ' => 'Tanzania', 'UA' => 'Ukraina', 'UG' => 'Uganda',
        'UM' => 'Minor', 'US' => 'Stany Zjednoczone Ameryki', 'UY' => 'Urugwaj', 'UZ' => 'Uzbekistan',
        'VA' => 'Watykan', 'VC' => 'Saint Vincent i Grenadyny', 'VE' => 'Wenezuela', 'VG' => 'Wyspy Dziewicze-W.B.',
        'VI' => 'Wyspy Dziewicze-USA', 'VN' => 'Wietnam', 'VU' => 'Vanuatu', 'WF' => 'Wallis i Futuna',
        'WS' => 'Samoa', 'XC' => 'Ceuta', 'XI' => 'Zjednoczone Królestwo (Irlandia Północna)', 'XK' => 'Kosowo',
        'XL' => 'Melilla', 'YE' => 'Jemen', 'YT' => 'Majotta', 'ZA' => 'Republika Południowej Afryki',
        'ZM' => 'Zambia', 'ZW' => 'Zimbabwe',
    );

    /**
     * @brief Constructor
     * @param DoliDB $db
     */
    public function __construct($db)
    {
        $this->db = $db;
        $this->contentWidth = $this->pageWidth - $this->marginLeft - $this->marginRight;
    }

    /**
     * @brief Generate PDF
     * @param $incoming KsefIncoming
     * @param $outputPath Save path
     * @param $previewMode Unsent preview
     * @return string|bool false on error
     */
    public function generate($incoming, $outputPath = '', $previewMode = false)
    {
        global $conf, $langs;
        dol_include_once('/ksef/lib/ksef.lib.php');

        if (empty($incoming->rowid) && !$previewMode) {
            $this->error = "Invoice object not loaded";
            return false;
        }

        dol_include_once('/ksef/class/fa3_parser.class.php');

        ksefPdfThrowErrors();
        try {
            pdf_getInstance();
            if ($previewMode) {
                $incoming = clone $incoming;
                $incoming->ksef_number = '';
            }
            $parser = new FA3Parser($this->db);
            $fa3Xml = isset($incoming->fa3_xml) ? $incoming->fa3_xml : null;
            $this->parsed = !empty($fa3Xml) ? $parser->parse($fa3Xml) : false;
            if (!$this->parsed) {
                if (!$previewMode) {
                    $this->error = 'Invoice XML cannot be read';
                    return false;
                }
                $this->parsed = $this->getEmptyParsed();
            }

            $this->pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
            $this->pdf->SetCreator('KSeF Module for Dolibarr');
            $this->pdf->SetAuthor($incoming->seller_name ?? '');
            $this->pdf->SetTitle(($previewMode ? 'PODGLĄD - ' : '') . 'Faktura ' . ($incoming->invoice_number ?? ''));
            $this->pdf->SetSubject($previewMode ? 'Podgląd - faktura nie została wysłana do KSeF' : 'KSeF Invoice');
            $this->pdf->setPrintHeader(false);
            $this->pdf->setPrintFooter(false);
            $this->pdf->SetMargins($this->marginLeft, $this->marginTop, $this->marginRight);
            $this->pdf->SetAutoPageBreak(false);
            $this->pdf->SetFont($this->fontFamily, '', $this->fontSizeNormal);
            $this->pdf->SetTextColorArray($this->colorText);
            $this->pdf->setCellHeightRatio($this->lineHeight);
            $this->pdf->AddPage();

            $y = $this->marginTop;

            $y = $this->renderHeader($incoming, $y);

            $correction = $this->parsed['correction'];
            if (!empty($correction['reason']) || !empty($correction['corrected_invoices'])) {
                $y = $this->renderCorrectionData($y);
            }

            $y = $this->renderSellerBuyer($incoming, $y);

            $y = $this->renderDetails($incoming, $y);

            $y = $this->renderPositionsTable($incoming, $y);

            $y = $this->renderGtinTable($y);

            if (!empty($this->parsed['deposits'])) {
                $y = $this->renderDeposits($y);
            }
            if (isset($this->parsed['order']['value']) && $this->parsed['order']['value'] !== null && $this->parsed['order']['value'] !== '') {
                $y = $this->renderOrder($y);
            }

            $y = $this->renderTotalAmount($incoming, $y);

            $y = $this->renderVatSummary($incoming, $y);

            $y = $this->renderAdnotacje($y);

            $addInfo = $this->parsed['additional_info'] ?? array();
            $addPrefix = array();
            if (!empty($this->parsed['invoice']['tp_flag'])) {
                $addPrefix[] = '- Istniejące powiązania między nabywcą a dokonującym dostawy towarów lub usługodawcą';
            }
            if (!empty($this->parsed['invoice']['fp_flag'])) {
                $addPrefix[] = '- Faktura, o której mowa w art. 109 ust. 3d ustawy';
            }
            $addInfo = array_merge($addPrefix, $addInfo);

            $hasAddDesc = !empty($this->parsed['additional_desc']);
            if (!empty($addInfo) || $hasAddDesc) {
                $y = $this->renderAdditionalInfo($addInfo, $y);
                if ($hasAddDesc) {
                    $y = $this->renderAdditionalDesc($y);
                }
            }

            $y = $this->renderRozliczenie($incoming, $y);

            $y = $this->renderPayment($incoming, $y);

            if ($this->hasWarunkiTransakcji()) {
                $y = $this->renderWarunkiTransakcji($y);
            }

            $y = $this->renderWz($y);

            $reg = $this->parsed['registries'];
            if (!empty($reg['krs']) || !empty($reg['regon']) || !empty($reg['bdo']) || !empty($reg['pelna_nazwa']) || !empty($reg['rows'])) {
                $y = $this->renderRegistries($y);
            }

            if (!empty($this->parsed['stopka'])) {
                $y = $this->renderStopkaInfo($y);
            }

            if (!$previewMode) {
                $y = $this->renderQrCode($incoming, $y);
            }

            $totalPages = $this->pdf->getNumPages();
            for ($p = 1; $p <= $totalPages; $p++) {
                $this->pdf->setPage($p);
                if ($previewMode) {
                    $this->renderWatermark();
                }
                $this->renderFooter($p, $totalPages);
            }

            if (!empty($outputPath)) {
                $dir = dirname($outputPath);
                if (!is_dir($dir)) {
                    $mkResult = dol_mkdir($dir);
                    if ($mkResult < 0) {
                        $this->error = "Cannot create directory: $dir";
                        dol_syslog("KsefInvoicePdf::generate - dol_mkdir failed for $dir", LOG_ERR);
                        return false;
                    }
                }
                $this->pdf->Output($outputPath, 'F');
                return true;
            } else {
                return $this->pdf->Output('', 'S');
            }

        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
            dol_syslog("KsefInvoicePdf::generate ERROR: " . get_class($e) . ': ' . $e->getMessage(), LOG_ERR);
            return false;
        }
    }

    /**
     * @brief Draw preview watermark
     * @return void
     */
    private function renderWatermark()
    {
        $pdf = $this->pdf;
        $cx = $this->pageWidth / 2;
        $cy = $this->pageHeight / 2;
        $pdf->SetAlpha(0.2);
        $pdf->StartTransform();
        $pdf->Rotate(45, $cx, $cy);
        $pdf->SetTextColor(177, 177, 177);
        $pdf->SetFont($this->fontFamily, 'B', 50);
        $pdf->SetXY(0, $cy - 14);
        $pdf->Cell($this->pageWidth, 20, 'PODGLĄD', 0, 1, 'C');
        $pdf->SetFont($this->fontFamily, 'B', 16);
        $pdf->SetXY(0, $cy + 6);
        $pdf->Cell($this->pageWidth, 8, 'Faktura nie została wysłana do KSeF', 0, 1, 'C');
        $pdf->StopTransform();
        $pdf->SetAlpha(1);
        $pdf->SetTextColorArray($this->colorText);
        $pdf->SetFont($this->fontFamily, '', $this->fontSizeNormal);
    }

    /**
     * @brief Page break check
     * @param $y
     * @param $neededHeight
     * @return float New Y
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
     * @param $y
     * @return float New Y
     */
    private function drawLine($y)
    {
        $this->pdf->SetDrawColorArray($this->colorLine);
        $this->pdf->SetLineWidth(0.3);
        $this->pdf->Line($this->marginLeft, $y, $this->marginLeft + $this->contentWidth, $y);
        return $y + $this->spaceLineAfter;
    }

    /**
     * @brief Render header
     * @param $incoming KsefIncoming
     * @param $y
     * @return float New Y
     */
    private function renderHeader($incoming, $y)
    {
        global $conf, $mysoc;

        $pdf = $this->pdf;
        $logoHeight = 0;
        $startY = $y;

        $ourNip = ksefSellerNip();
        $sellerNip = !empty($incoming->seller_nip) ? preg_replace('/[^0-9]/', '', $incoming->seller_nip) : '';

        $isOutgoing = (!empty($ourNip) && !empty($sellerNip) && $ourNip === $sellerNip);

        if ($isOutgoing && !empty($mysoc->logo)) {
            $logoFile = $conf->mycompany->dir_output . '/logos/' . $mysoc->logo;
            if (is_readable($logoFile)) {
                $logoHeight = pdf_getHeightForLogo($logoFile);
                $pdf->Image($logoFile, $this->marginLeft, $y, 0, $logoHeight);
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

        if (ksefIsRealKsefNumber($incoming->ksef_number)) {
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
     * @brief Render correction data
     * @param $y
     * @return float New Y
     */
    private function renderCorrectionData($y)
    {
        $correction = $this->parsed['correction'];
        $hasLeftContent = !empty($correction['reason']) || !empty($correction['type']);
        $correctedInvoices = $correction['corrected_invoices'] ?? array();
        $colWidth = ($this->contentWidth - 5) / 2;
        $leftX = $this->marginLeft;
        $rightX = $this->marginLeft + $colWidth + 5;

        if (count($correctedInvoices) <= 1) {
            $inv = $correctedInvoices[0] ?? null;
            $draw = function ($y0) use ($hasLeftContent, $inv, $leftX, $rightX, $colWidth) {
                $leftY = $hasLeftContent ? $this->renderCorrectionReason($leftX, $y0, $colWidth) : $y0;
                $rightY = $y0;
                if ($inv !== null) {
                    $rightY = $this->renderCorrectedInvoice($hasLeftContent ? $rightX : $leftX, $y0, $hasLeftContent ? $colWidth : $this->contentWidth, $inv, 0);
                }
                return max($leftY, $rightY);
            };
            $h = $this->measureBlock($draw);
            $y = $this->checkPageBreak($y, $this->spaceLineAfter + $h);
            $y = $this->drawLine($y);
            return $draw($y) + 2;
        }

        $hReason = $hasLeftContent ? $this->measureBlock(function ($y0) use ($leftX, $colWidth) {
            return $this->renderCorrectionReason($leftX, $y0, $colWidth);
        }) : 0;
        $y = $this->checkPageBreak($y, $this->spaceLineAfter + $hReason + 20);
        $y = $this->drawLine($y);
        if ($hasLeftContent) {
            $y = $this->renderCorrectionReason($leftX, $y, $colWidth);
        }
        $count = count($correctedInvoices);
        for ($i = 0; $i < $count; $i += 2) {
            $draw = function ($y0) use ($i, $correctedInvoices, $leftX, $rightX, $colWidth) {
                $yl = $this->renderCorrectedInvoice($leftX, $y0, $colWidth, $correctedInvoices[$i], $i + 1);
                $yr = $y0;
                if (isset($correctedInvoices[$i + 1])) {
                    $yr = $this->renderCorrectedInvoice($rightX, $y0, $colWidth, $correctedInvoices[$i + 1], $i + 2);
                }
                return max($yl, $yr);
            };
            $y = $this->checkPageBreak($y, $this->measureBlock($draw));
            $y = $draw($y);
        }
        return $y + 2;
    }

    /**
     * @brief Draw correction reason
     * @param $x
     * @param $y
     * @param $w
     * @return float New Y
     */
    private function renderCorrectionReason($x, $y, $w)
    {
        $correction = $this->parsed['correction'];
        $pdf = $this->pdf;
        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($x, $y);
        $pdf->Cell($w, 5, 'Dane faktury korygowanej', 0, 1, 'L');
        $y += 5 + $this->spaceSectionAfter;
        if (!empty($correction['reason'])) {
            $y = $this->renderLabelValueAt($x, $y, $w, 'Przyczyna korekty dla faktur korygujących: ', $correction['reason']) + $this->spaceParagraph;
        }
        if (!empty($correction['type'])) {
            $y = $this->renderLabelValueAt($x, $y, $w, 'Typ skutku korekty: ', $this->getCorrectionTypeLabel($correction['type'])) + $this->spaceParagraph;
        }
        return $y;
    }

    /**
     * @brief Corrected invoice block
     * @param $x
     * @param $y
     * @param $w
     * @param $inv Corrected invoice data
     * @param $num 1-based, 0 single
     * @return float New Y
     */
    private function renderCorrectedInvoice($x, $y, $w, $inv, $num)
    {
        $pdf = $this->pdf;
        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($x, $y);
        $pdf->Cell($w, 5, 'Dane identyfikacyjne faktury korygowanej' . ($num > 0 ? ' ' . $num : ''), 0, 1, 'L');
        $y += 5 + $this->spaceSectionAfter;
        $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
        if (!empty($inv['invoice_date'])) {
            $y = $this->renderLabelValueAt($x, $y, $w, 'Data wystawienia faktury, której dotyczy faktura korygująca: ', $this->formatDatePolish($inv['invoice_date']));
        }
        if (!empty($inv['invoice_number'])) {
            $y = $this->renderLabelValueAt($x, $y, $w, 'Numer faktury korygowanej: ', $inv['invoice_number']);
        }
        if (!empty($inv['ksef_number'])) {
            $y = $this->renderLabelValueAt($x, $y, $w, 'Numer KSeF faktury korygowanej: ', $inv['ksef_number']);
        }
        return $y + 2;
    }

    /**
     * @brief Label at X
     * @param $x
     * @param $y
     * @param $colWidth
     * @param $label
     * @param $value
     * @return float New Y
     */
    private function renderLabelValueAt($x, $y, $colWidth, $label, $value)
    {
        $pdf = $this->pdf;
        $pdf->SetXY($x, $y);
        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
        $labelWidth = $pdf->GetStringWidth($label);

        $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
        $valueWidth = $pdf->GetStringWidth($value);

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
     * @brief Render label line
     * @param $y
     * @param $label
     * @param $value
     * @return float New Y
     */
    private function renderLabelValue($y, $label, $value)
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
     * @brief Render parties
     * @param $incoming KsefIncoming
     * @param $y
     * @return float New Y
     */
    private function renderSellerBuyer($incoming, $y)
    {
        $colWidth = ($this->contentWidth - 5) / 2;
        $leftX = $this->marginLeft;
        $rightX = $this->marginLeft + $colWidth + 5;

        $sellerData = array(
            'idLines' => !empty($incoming->seller_nip) ? array(array('NIP: ', $this->formatNIP($incoming->seller_nip))) : array(),
            'eori' => $this->parsed['seller']['nr_eori'] ?? null,
            'name' => $incoming->seller_name,
            'address' => $incoming->seller_address,
            'country' => $incoming->seller_country ?? 'PL',
            'email' => $this->parsed['seller']['email'] ?? null,
            'phone' => $this->parsed['seller']['phone'] ?? null,
        );

        $buyer = $this->parsed['buyer'];
        $idLines = $this->identityLines($buyer);
        if (empty($idLines) && !empty($incoming->buyer_nip)) {
            $idLines = array(array('NIP: ', $this->formatNIP($incoming->buyer_nip)));
        }
        $buyerData = array(
            'idLines' => $idLines,
            'eori' => $buyer['nr_eori'] ?? null,
            'name' => !empty($buyer['name']) ? $buyer['name'] : ($incoming->buyer_name ?? ''),
            'address' => $buyer['address'] ?? null,
            'country' => $buyer['country'] ?? 'PL',
            'email' => $buyer['email'] ?? null,
            'phone' => $buyer['phone'] ?? null,
            'customerNumber' => $buyer['customer_number'] ?? null,
        );

        $draw = function ($y0) use ($leftX, $rightX, $colWidth, $sellerData, $buyerData, $buyer) {
            $sellerY = $this->renderEntity($leftX, $y0, $colWidth, 'Sprzedawca', $sellerData);
            $buyerY = $this->renderEntity($rightX, $y0, $colWidth, 'Nabywca', $buyerData);

            $buyerY += 2;
            $jstValue = (isset($buyer['jst']) && $buyer['jst'] === '1') ? 'TAK' : 'NIE';
            $gvValue = (isset($buyer['gv']) && $buyer['gv'] === '1') ? 'TAK' : 'NIE';
            $buyerY = $this->renderFieldAt($rightX, $buyerY, $colWidth, 'Faktura dotyczy jednostki podrzędnej JST: ', $jstValue);
            $buyerY = $this->renderFieldAt($rightX, $buyerY, $colWidth, 'Faktura dotyczy członka grupy GV: ', $gvValue);
            return max($sellerY, $buyerY);
        };
        $h = $this->measureBlock($draw);
        $y = $this->checkPageBreak($y, $this->spaceLineAfter + $h);
        $y = $this->drawLine($y);
        $y = $draw($y) + 2;

        $buyerBefore = $this->parsed['buyer_before'] ?? null;
        if (!empty($buyerBefore)) {
            $y = $this->renderBuyerCorrection($buyerBefore, $buyer, $incoming, $y);
        }

        $thirdParties = $this->parsed['third_parties'] ?? array();
        foreach ($thirdParties as $idx => $tp) {
            $y = $this->renderThirdParty($idx, $tp, $y);
        }

        return $y;
    }

    /**
     * @brief Render Podmiot3 block
     * @param int $index 0-based
     * @param array $tp
     * @param float $y
     * @return float New Y
     */
    private function renderThirdParty($index, $tp, $y)
    {
        $h = $this->measureBlock(function ($y0) use ($index, $tp) {
            return $this->renderThirdPartyBody($index, $tp, $y0);
        });
        $y = $this->checkPageBreak($y, $this->spaceLineAfter + $h);
        $y = $this->drawLine($y);
        return $this->renderThirdPartyBody($index, $tp, $y);
    }

    /**
     * @brief Draw Podmiot3 block
     * @param $index 0-based
     * @param $tp Podmiot3 data
     * @param $y
     * @return float New Y
     */
    private function renderThirdPartyBody($index, $tp, $y)
    {
        $pdf = $this->pdf;
        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 5, 'Podmiot inny ' . ($index + 1), 0, 1, 'L');
        $y += 6;

        $colWidth = ($this->contentWidth - 5) / 2;
        $leftX = $this->marginLeft;
        $rightX = $this->marginLeft + $colWidth + 5;

        $ly = $y;
        if (!empty($tp['id_nabywcy'])) {
            $ly = $this->renderTpField($leftX, $ly, $colWidth, 'Identyfikator nabywcy: ', $tp['id_nabywcy']);
        }
        foreach ($this->identityLines($tp) as $l) {
            $ly = $this->renderTpField($leftX, $ly, $colWidth, $l[0], $l[1]);
        }
        if (!empty($tp['name'])) {
            $ly = $this->renderTpField($leftX, $ly, $colWidth, 'Nazwa: ', $tp['name']);
        }
        if (($tp['role'] ?? '') !== '') {
            $ly = $this->renderTpField($leftX, $ly, $colWidth, 'Rola: ', $this->thirdPartyRoleLabel($tp['role']));
        }
        if (!empty($tp['opis_roli'])) {
            $ly = $this->renderTpField($leftX, $ly, $colWidth, 'Rola inna: ', $tp['opis_roli']);
        }
        if (!empty($tp['udzial'])) {
            $ly = $this->renderTpField($leftX, $ly, $colWidth, 'Udział: ', $this->formatPercent($tp['udzial']));
        }

        $ry = $this->renderTextAt($rightX, $y, $colWidth, 'Adres', 'B');
        if (!empty($tp['address'])) {
            foreach ($this->parseAddressLines($tp['address']) as $line) {
                $ry = $this->renderTextAt($rightX, $ry, $colWidth, $line);
            }
        }
        if (!empty($tp['country'])) {
            $ry = $this->renderTextAt($rightX, $ry, $colWidth, $this->getCountryName($tp['country']));
        }
        if (!empty($tp['email']) || !empty($tp['phone']) || !empty($tp['nr_klienta'])) {
            $ry = $this->renderTextAt($rightX, $ry + 2, $colWidth, 'Dane kontaktowe', 'B');
            if (!empty($tp['email'])) $ry = $this->renderTpField($rightX, $ry, $colWidth, 'E-mail: ', $tp['email']);
            if (!empty($tp['phone'])) $ry = $this->renderTpField($rightX, $ry, $colWidth, 'Tel.: ', $tp['phone']);
            if (!empty($tp['nr_klienta'])) $ry = $this->renderTpField($rightX, $ry, $colWidth, 'Numer klienta: ', $tp['nr_klienta']);
        }

        return max($ly, $ry) + 2;
    }

    /**
     * @brief Render Podmiot3 field
     * @param $x
     * @param $y
     * @param $w
     * @param $label
     * @param $value
     * @return float New Y
     */
    private function renderTpField($x, $y, $w, $label, $value)
    {
        return $this->renderFieldAt($x, $y, $w, $label, $value, 0);
    }

    /**
     * @brief Render wrapped label
     * @param $x
     * @param $y
     * @param $w
     * @param $label
     * @param $value
     * @param $minH Minimum height
     * @param $fontSize
     * @return float New Y
     */
    private function renderFieldAt($x, $y, $w, $label, $value, $minH = 4, $fontSize = null)
    {
        $pdf = $this->pdf;
        $pdf->SetFont($this->fontFamily, '', $fontSize ?: $this->fontSizeSmall);
        $html = '<b>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</b>' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $pdf->writeHTMLCell($w, 0, $x, $y, $html, 0, 1);
        return max($pdf->GetY(), $y + $minH);
    }

    /**
     * @brief Render wrapped text
     * @param $x
     * @param $y
     * @param $w
     * @param $text
     * @param $style '' or 'B'
     * @return float New Y
     */
    private function renderTextAt($x, $y, $w, $text, $style = '')
    {
        $pdf = $this->pdf;
        $pdf->SetFont($this->fontFamily, $style, $this->fontSizeSmall);
        $pdf->SetXY($x, $y);
        $pdf->MultiCell($w, 4, (string) $text, 0, 'L', false, 1);
        return max($pdf->GetY(), $y + 4);
    }

    /**
     * @brief Measure block height
     * @param callable $fn Draws, returns Y
     * @return float Height in mm
     */
    private function measureBlock($fn)
    {
        $page = $this->pdf->getPage();
        $this->pdf->startTransaction();
        $y0 = $this->marginTop;
        $y1 = call_user_func($fn, $y0);
        $added = $this->pdf->getPage() - $page;
        $this->pdf = $this->pdf->rollbackTransaction();
        return $added > 0 ? $this->pageHeight : ($y1 - $y0);
    }

    /**
     * @brief Multi-page MultiCell
     * @param $x
     * @param $y
     * @param $w
     * @param $h Line height
     * @param $text
     * @param $border
     * @return float New Y
     */
    private function flowMultiCell($x, $y, $w, $h, $text, $border = 0)
    {
        $pdf = $this->pdf;
        $pdf->SetAutoPageBreak(true, $this->marginBottom);
        $pdf->SetXY($x, $y);
        $pdf->MultiCell($w, $h, $text, $border, 'L', false, 1);
        $y = $pdf->GetY();
        $pdf->SetAutoPageBreak(false);
        return $y;
    }

    /**
     * @brief Party identity lines
     * @param array $p Parsed party
     * @return array array(label, value) rows
     */
    private function identityLines($p)
    {
        if (!empty($p['nr_id'])) {
            return array(array('Identyfikator podatkowy inny: ', trim(($p['kod_kraju_id'] ?? '') . ' ' . $p['nr_id'])));
        }
        if (!empty($p['idwew'])) {
            return array(array('Identyfikator wewnętrzny: ', $p['idwew']));
        }
        if (!empty($p['kod_ue'])) {
            return array(array('Numer VAT-UE: ', trim($p['kod_ue'] . ' ' . ($p['nr_vat_ue'] ?? ''))));
        }
        if (!empty($p['brak_id'])) {
            return array(array('Brak identyfikatora', ''));
        }
        if (!empty($p['nip'])) {
            return array(array('NIP: ', $this->formatNIP($p['nip'])));
        }
        return array();
    }

    /**
     * @brief Podmiot3 role label
     * @param $role Role code
     * @return string
     */
    private function thirdPartyRoleLabel($role)
    {
        $map = array(
            '1'  => 'Faktor - w przypadku, gdy na fakturze występują dane faktora',
            '2'  => 'Odbiorca - w przypadku, gdy na fakturze występują dane jednostek wewnętrznych, oddziałów, wyodrębnionych w ramach nabywcy, które same nie stanowią nabywcy w rozumieniu ustawy',
            '3'  => 'Podmiot pierwotny - w przypadku, gdy na fakturze występują dane podmiotu będącego w stosunku do podatnika podmiotem przejętym lub przekształconym, który dokonywał dostawy lub świadczył usługę. Z wyłączeniem przypadków, o których mowa w art. 106j ust.2 pkt 3 ustawy, gdy dane te wykazywane są w części Podmiot1K',
            '4'  => 'Dodatkowy nabywca - w przypadku, gdy na fakturze występują dane kolejnych (innych niż wymieniony w części Podmiot2) nabywców',
            '5'  => 'Wystawca faktury - w przypadku, gdy na fakturze występują dane podmiotu wystawiającego fakturę w imieniu podatnika. Nie dotyczy przypadku, gdy wystawcą faktury jest nabywca',
            '6'  => 'Dokonujący płatności - w przypadku, gdy na fakturze występują dane podmiotu regulującego zobowiązanie w miejsce nabywcy',
            '7'  => 'Jednostka samorządu terytorialnego - wystawca',
            '8'  => 'Jednostka samorządu terytorialnego - odbiorca',
            '9'  => 'Członek grupy VAT - wystawca',
            '10' => 'Członek grupy VAT - odbiorca',
            '11' => 'Pracownik',
        );
        return isset($map[$role]) ? $map[$role] : ('Rola ' . $role);
    }

    /**
     * @brief Render buyer before/after
     * @param $buyerBefore array Podmiot2K
     * @param $buyerAfter array Podmiot2
     * @param $incoming KsefIncoming
     * @param $y
     * @return float New Y
     */
    private function renderBuyerCorrection($buyerBefore, $buyerAfter, $incoming, $y)
    {
        $colWidth = ($this->contentWidth - 5) / 2;
        $leftX = $this->marginLeft;
        $rightX = $this->marginLeft + $colWidth + 5;

        $afterData = $buyerAfter;
        if (empty($afterData['name'])) $afterData['name'] = $incoming->buyer_name ?? '';
        if (empty($this->identityLines($afterData))) $afterData['nip'] = $incoming->buyer_nip ?? '';

        $draw = function ($y0) use ($leftX, $rightX, $colWidth, $buyerBefore, $afterData) {
            $leftY = $this->renderTextAt($leftX, $y0, $colWidth, 'Treść korygowana', 'B') + 2;
            $leftY = $this->renderBuyerColumn($leftX, $leftY, $colWidth, $buyerBefore);
            $rightY = $this->renderTextAt($rightX, $y0, $colWidth, 'Treść korygująca', 'B') + 2;
            $rightY = $this->renderBuyerColumn($rightX, $rightY, $colWidth, $afterData);
            return max($leftY, $rightY);
        };
        $h = $this->measureBlock($draw);
        $y = $this->checkPageBreak($y, $h);
        return $draw($y) + 2;
    }

    /**
     * @brief Render buyer column
     * @param $x
     * @param $y
     * @param $width
     * @param $data Parsed buyer
     * @return float New Y
     */
    private function renderBuyerColumn($x, $y, $width, $data)
    {
        foreach ($this->identityLines($data) as $l) {
            $y = $this->renderFieldAt($x, $y, $width, $l[0], $l[1]);
        }
        if (!empty($data['name'])) {
            $y = $this->renderFieldAt($x, $y, $width, 'Nazwa: ', $data['name']);
        }
        if (!empty($data['address'])) {
            $y = $this->renderTextAt($x, $y + 2, $width, 'Adres', 'B');
            foreach ($this->parseAddressLines($data['address']) as $line) {
                $y = $this->renderTextAt($x, $y, $width, $line);
            }
        }
        if (!empty($data['country'])) {
            $y = $this->renderTextAt($x, $y, $width, $this->getCountryName($data['country']));
        }
        return $y;
    }

    /**
     * @brief Render party section
     * @param $x
     * @param $y
     * @param $width
     * @param $header
     * @param $data Party data
     * @return float New Y
     */
    private function renderEntity($x, $y, $width, $header, $data)
    {
        $pdf = $this->pdf;

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($x, $y);
        $pdf->Cell($width, 5, $header, 0, 1, 'L');
        $y += 6;

        if (!empty($data['eori'])) {
            $y = $this->renderFieldAt($x, $y, $width, 'Numer EORI: ', $data['eori']);
        }
        foreach ($data['idLines'] ?? array() as $l) {
            $y = $this->renderFieldAt($x, $y, $width, $l[0], $l[1]);
        }
        if (!empty($data['name'])) {
            $y = $this->renderFieldAt($x, $y, $width, 'Nazwa: ', $data['name']);
        }

        $y = $this->renderTextAt($x, $y + 2, $width, 'Adres', 'B');
        if (!empty($data['address'])) {
            foreach ($this->parseAddressLines($data['address']) as $line) {
                $y = $this->renderTextAt($x, $y, $width, $line);
            }
        }
        if (!empty($data['country'])) {
            $y = $this->renderTextAt($x, $y, $width, $this->getCountryName($data['country']));
        }

        if (!empty($data['email']) || !empty($data['phone']) || !empty($data['customerNumber'])) {
            $y = $this->renderTextAt($x, $y + 2, $width, 'Dane kontaktowe', 'B');
            if (!empty($data['email'])) $y = $this->renderFieldAt($x, $y, $width, 'E-mail: ', $data['email']);
            if (!empty($data['phone'])) $y = $this->renderFieldAt($x, $y, $width, 'Tel.: ', $data['phone']);
            if (!empty($data['customerNumber'])) $y = $this->renderFieldAt($x, $y, $width, 'Numer klienta: ', $data['customerNumber']);
        }

        return $y;
    }

    /**
     * @brief Render invoice details
     * @param $incoming KsefIncoming
     * @param $y
     * @return float New Y
     */
    private function renderDetails($incoming, $y)
    {
        $pdf = $this->pdf;
        $y = $this->checkPageBreak($y, 20);
        $y = $this->drawLine($y);

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 5, 'Szczegóły', 0, 1, 'L');
        $y += 5;

        $colWidth = ($this->contentWidth - 5) / 2;
        $leftX = $this->marginLeft;
        $rightX = $this->marginLeft + $colWidth + 5;
        $detailsFontSize = 6;

        $leftY = $y;
        $rightY = $y;

        if (!empty($incoming->invoice_date)) {
            $dateStr = $this->formatDatePolish($incoming->invoice_date);
            $label = 'Data wystawienia, z zastrzeżeniem art. 106na ust. 1 ustawy: ';

            $pdf->SetXY($leftX, $leftY);
            $pdf->SetFont($this->fontFamily, 'B', $detailsFontSize);
            $labelWidth = $pdf->GetStringWidth($label);

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

        $currencyCode = $this->parsed['invoice']['currency'] ?? ($incoming->currency ?: 'PLN');
        $pdf->SetXY($leftX, $leftY);
        $pdf->SetFont($this->fontFamily, 'B', $detailsFontSize);
        $label = 'Kod waluty: ';
        $pdf->Cell($pdf->GetStringWidth($label), 3, $label, 0, 0, 'L');
        $pdf->SetFont($this->fontFamily, '', $detailsFontSize);
        $pdf->Cell(0, 3, $currencyCode, 0, 1, 'L');
        $leftY += 4;

        $placeOfIssue = $this->parsed['invoice']['place_of_issue'] ?? null;
        if (!empty($placeOfIssue)) {
            $leftY = $this->renderFieldAt($leftX, $leftY, $colWidth - 2, 'Miejsce wystawienia: ', $placeOfIssue, 4, $detailsFontSize);
        }

        $periodFrom = $this->parsed['invoice']['period_from'] ?? null;
        $periodTo = $this->parsed['invoice']['period_to'] ?? null;
        $saleP6 = $this->parsed['invoice']['sale_date'] ?? null;
        $dateStr = '';
        if (!empty($periodFrom) && !empty($periodTo)) {
            $dateStr = 'od ' . $this->formatDatePolish($periodFrom) . ' do ' . $this->formatDatePolish($periodTo);
        } elseif (!empty($periodFrom) && empty($saleP6)) {
            $dateStr = 'od ' . $this->formatDatePolish($periodFrom);
        } elseif (!empty($periodTo) && empty($saleP6)) {
            $dateStr = 'do ' . $this->formatDatePolish($periodTo);
        } elseif (!empty($saleP6)) {
            $dateStr = $this->formatDatePolish($saleP6);
        } elseif (!empty($incoming->sale_date) && empty($this->parsed['partial_payments'])) {
            $dateStr = $this->formatDatePolish($incoming->sale_date);
        }
        if ($dateStr !== '') {
            $label = 'Data dokonania lub zakończenia dostawy towarów lub wykonania usługi: ';

            $pdf->SetXY($rightX, $rightY);
            $pdf->SetFont($this->fontFamily, 'B', $detailsFontSize);
            $labelWidth = $pdf->GetStringWidth($label);

            $pdf->SetFont($this->fontFamily, '', $detailsFontSize);
            $dateWidth = $pdf->GetStringWidth($dateStr);

            if ($labelWidth + $dateWidth < $colWidth - 2) {
                $pdf->SetXY($rightX, $rightY);
                $pdf->SetFont($this->fontFamily, 'B', $detailsFontSize);
                $pdf->Cell($labelWidth, 3, $label, 0, 0, 'L');
                $pdf->SetFont($this->fontFamily, '', $detailsFontSize);
                $pdf->Cell($dateWidth, 3, $dateStr, 0, 1, 'L');
                $rightY += 4;
            } else {
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

        $currency = $this->parsed['invoice']['currency'] ?? ($incoming->currency ?: 'PLN');
        $lineRates = $this->distinctLineRates();
        // never weighted
        $xr = (array) ($this->parsed['exchange_rate'] ?? array());
        $headerRate = in_array($xr['source'] ?? '', array('deposit', 'payment'), true) ? ($xr['rate'] ?? null) : null;
        $kursWaluty = count($lineRates) === 1 ? $lineRates[0] : (empty($lineRates) ? $headerRate : null);

        if ($currency != 'PLN' && !empty($kursWaluty)) {
            $y = $this->checkPageBreak($y, 10);

            $pdf->SetXY($this->marginLeft, $y);
            $pdf->SetFont($this->fontFamily, 'B', $detailsFontSize);
            $label = 'Kurs waluty: ';
            $pdf->Cell($pdf->GetStringWidth($label), 3, $label, 0, 0, 'L');

            $pdf->SetFont($this->fontFamily, '', $detailsFontSize);
            $rateText = rtrim(rtrim(number_format((float)$kursWaluty, 6, ',', ' '), '0'), ',');
            $pdf->Cell(0, 3, $rateText, 0, 1, 'L');
            $y += 4;

            $pdf->SetXY($this->marginLeft, $y);
            $pdf->SetFont($this->fontFamily, 'B', $detailsFontSize);
            $pdf->Cell(0, 3, 'Kurs waluty wspólny dla wszystkich wierszy faktury', 0, 1, 'L');
            $y += 4;
        }

        $parts = (array) ($this->parsed['partial_payments'] ?? array());
        if ($parts) {
            $withRate = false;
            foreach ($parts as $p) {
                $withRate = $withRate || ($p['rate'] !== null && $p['rate'] !== '');
            }
            $rows = array();
            foreach ($parts as $p) {
                $row = array($this->formatDatePolish($p['date']), $this->formatMoney($p['amount']));
                if ($withRate) {
                    $row[] = ($p['rate'] !== null && $p['rate'] !== '') ? $this->formatDecimalTrim($p['rate'], 6, 0) : '';
                }
                $rows[] = $row;
            }
            $headers = $withRate ? array('Data otrzymania płatności', 'Kwota płatności', 'Kurs waluty') : array('Data otrzymania płatności', 'Kwota płatności');
            $y = $this->checkPageBreak($y, 5 * (count($rows) + 1) + 4);
            $y = $this->renderMiniTable($this->marginLeft, $y + 1, $this->contentWidth, $headers, $rows) + 3;
        }

        return $y;
    }

    /**
     * @brief Distinct line rates
     * @return array
     */
    private function distinctLineRates()
    {
        $rates = array();
        foreach ($this->parsed['lines'] ?? array() as $line) {
            if (!empty($line['kurs_waluty'])) $rates[(string) (float) $line['kurs_waluty']] = $line['kurs_waluty'];
        }
        return array_values($rates);
    }

    /**
     * @brief Render positions table
     * @param $incoming KsefIncoming
     * @param $y
     * @return float New Y
     */
    private function renderPositionsTable($incoming, $y)
    {
        $pdf = $this->pdf;
        $lines = $this->parsed['lines'] ?? array();
        $linesBefore = $this->parsed['lines_before'] ?? array();

        if (empty($lines) && empty($linesBefore)) return $y;

        $y = $this->checkPageBreak($y, 30);
        $y = $this->drawLine($y);

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 5, 'Pozycje', 0, 1, 'L');
        $y += 5 + 3;

        $currency = $this->parsed['invoice']['currency'] ?? ($incoming->currency ?: 'PLN');
        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeNormal);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 4, 'Faktura wystawiona w walucie ' . $currency, 0, 1, 'L');
        $y += 4 + $this->spaceParagraph;

        $allLines = array_merge($linesBefore, $lines);
        $hasNetPrice = false;
        $hasGrossPrice = false;
        foreach ($allLines as $line) {
            if (isset($line['unit_price_net']) && empty($line['unit_price_net_derived'])) $hasNetPrice = true;
            if (isset($line['unit_price_gross']) && empty($line['unit_price_gross_derived'])) $hasGrossPrice = true;
        }
        $hasUUID = $this->hasAnyField($allLines, 'uuid');
        $hasDiscount = $this->hasAnyField($allLines, 'discount');
        $hasGrossAmount = $this->hasAnyField($allLines, 'gross_amount');
        $hasVatAmount = $this->hasAnyField($allLines, 'vat_amount');
        $hasStanPrzed = !empty($linesBefore);
        $hasUnit = $this->hasAnyField($allLines, 'unit');
        $hasP6A = $this->hasAnyField($allLines, 'p_6a');
        $hasKursWaluty = count($this->distinctLineRates()) > 1;

        $cols = array();
        $cols[] = array('h' => 'Lp.', 'a' => 'C', 'k' => 'line_num');
        if ($hasUUID) $cols[] = array('h' => 'Unikalny numer wiersza', 'a' => 'L', 'k' => 'uuid');
        $cols[] = array('h' => 'Nazwa towaru lub usługi', 'a' => 'L', 'k' => 'description');
        if ($hasNetPrice) $cols[] = array('h' => 'Cena jedn. netto', 'a' => 'R', 'k' => 'unit_price_net');
        if ($hasGrossPrice) $cols[] = array('h' => 'Cena jedn. brutto', 'a' => 'R', 'k' => 'unit_price_gross');
        $cols[] = array('h' => 'Ilość', 'a' => 'R', 'k' => 'quantity');
        if ($hasUnit) $cols[] = array('h' => 'Miara', 'a' => 'C', 'k' => 'unit');
        if ($hasDiscount) $cols[] = array('h' => 'Rabat', 'a' => 'R', 'k' => 'discount');
        $cols[] = array('h' => 'Stawka podatku', 'a' => 'C', 'k' => 'vat_rate');
        $cols[] = array('h' => 'Wartość sprzedaży netto', 'a' => 'R', 'k' => 'net_amount');
        if ($hasGrossAmount) $cols[] = array('h' => 'Wartość sprzedaży brutto', 'a' => 'R', 'k' => 'gross_amount');
        if ($hasVatAmount) $cols[] = array('h' => 'Wartość sprzedaży vat', 'a' => 'R', 'k' => 'vat_amount');
        if ($hasP6A) $cols[] = array('h' => 'Data dostawy / wykonania', 'a' => 'C', 'k' => 'p_6a');
        if ($hasKursWaluty) $cols[] = array('h' => 'Kurs waluty', 'a' => 'R', 'k' => 'kurs_waluty');
        if ($hasStanPrzed) $cols[] = array('h' => 'Stan przed', 'a' => 'C', 'k' => 'stan_przed');

        $allRenderLines = array();
        if (!empty($linesBefore)) {
            $beforeByNum = array();
            foreach ($linesBefore as $line) {
                $line['stan_przed'] = 'Tak';
                $num = $line['line_num'] ?? 0;
                $beforeByNum[$num] = $line;
            }
            $afterByNum = array();
            foreach ($lines as $line) {
                $line['stan_przed'] = '';
                $num = $line['line_num'] ?? 0;
                $afterByNum[$num][] = $line;
            }
            $allNums = array_unique(array_merge(array_keys($beforeByNum), array_keys($afterByNum)));
            sort($allNums, SORT_NUMERIC);
            foreach ($allNums as $num) {
                if (isset($beforeByNum[$num])) {
                    $allRenderLines[] = $beforeByNum[$num];
                }
                if (isset($afterByNum[$num])) {
                    foreach ($afterByNum[$num] as $afterLine) {
                        $allRenderLines[] = $afterLine;
                    }
                }
            }
        } else {
            foreach ($lines as $line) {
                $line['stan_przed'] = '';
                $allRenderLines[] = $line;
            }
        }

        $rows = array();
        foreach ($allRenderLines as $line) {
            $row = array();
            foreach ($cols as $col) {
                $row[] = $this->formatPositionCell($col['k'], $line);
            }
            $rows[] = $row;
        }

        $layout = $this->layoutPositionColumns($cols, $rows);
        $fs = $layout['fs'];
        foreach ($cols as $i => $col) {
            $cols[$i]['w'] = $layout['w'][$i];
        }
        $lineH = $fs * 0.46;

        $y = $this->drawPositionsTableHeader($cols, $y, $fs);
        $pdf->SetFont($this->fontFamily, '', $fs);
        $pdf->SetLineWidth($this->borderWidth);
        $pdf->SetDrawColorArray($this->colorBorder);

        foreach ($rows as $rowData) {
            $maxLines = 1;
            foreach ($cols as $i => $col) {
                if (in_array($col['k'], array('uuid', 'description')) && $rowData[$i] !== '') {
                    $maxLines = max($maxLines, $pdf->getNumLines($rowData[$i], $col['w'] - 1));
                }
            }
            $rowHeight = max(5, $maxLines * $lineH + 1);

            $oldY = $y;
            $y = $this->checkPageBreak($y, $rowHeight + 2);
            if ($y < $oldY) {
                $y = $this->drawPositionsTableHeader($cols, $y, $fs);
                $pdf->SetFont($this->fontFamily, '', $fs);
                $pdf->SetLineWidth($this->borderWidth);
                $pdf->SetDrawColorArray($this->colorBorder);
            }

            $x = $this->marginLeft;
            foreach ($cols as $i => $col) {
                $pdf->SetXY($x, $y);
                if (in_array($col['k'], array('uuid', 'description'))) {
                    $pdf->Cell($col['w'], $rowHeight, '', 1, 0, $col['a']);
                    $pdf->SetXY($x + 0.5, $y + 0.5);
                    $pdf->MultiCell($col['w'] - 1, $lineH, $rowData[$i], 0, 'L', false, 0);
                } else {
                    $pdf->Cell($col['w'], $rowHeight, $rowData[$i], 1, 0, $col['a'], false, '', 1);
                }
                $x += $col['w'];
            }
            $y += $rowHeight;
        }

        return $y + $this->spaceParagraph;
    }

    /**
     * @brief Position cell text
     * @param $key Column key
     * @param $line Parsed line
     * @return string
     */
    private function formatPositionCell($key, $line)
    {
        switch ($key) {
            case 'line_num': return (string) ($line['line_num'] ?? '');
            case 'uuid': return (string) ($line['uuid'] ?? '');
            case 'description': return (string) ($line['description'] ?? '');
            case 'quantity':
                // empty, not 0
                if (isset($line['declared']) && !in_array('P_8B', $line['declared'], true)) return '';
                return $this->formatQuantity($line['quantity'] ?? null);
            case 'unit': return (string) ($line['unit'] ?? '');
            case 'unit_price_net':
                return !empty($line['unit_price_net_derived']) ? '' : $this->formatDecimalTrim($line['unit_price_net'] ?? null, 8, 2);
            case 'unit_price_gross':
                return !empty($line['unit_price_gross_derived']) ? '' : $this->formatDecimalTrim($line['unit_price_gross'] ?? null, 8, 2);
            case 'discount': return $this->formatDecimalTrim($line['discount'] ?? null, 8, 2);
            case 'vat_rate': return (string) $this->getVatRateLabel($line['vat_rate'] ?? '', true);
            case 'net_amount': return $this->formatMoney($line['net_amount'] ?? null);
            case 'gross_amount': return $this->formatMoney($line['gross_amount'] ?? null);
            case 'vat_amount': return $this->formatMoney($line['vat_amount'] ?? null);
            case 'kurs_waluty': return $this->formatDecimalTrim($line['kurs_waluty'] ?? null, 6, 0);
            case 'stan_przed': return (string) ($line['stan_przed'] ?? '');
            case 'p_6a': return $this->formatDatePolish($line['p_6a'] ?? '');
        }
        return '';
    }

    /**
     * @brief Fit column widths
     * @param $cols Column defs
     * @param $rows Cell texts
     * @return array fs, w
     */
    private function layoutPositionColumns($cols, $rows)
    {
        $pdf = $this->pdf;
        $pad = 2.2;
        $nameMin = 40;
        $result = null;
        foreach (array(7, 6) as $fs) {
            $w = array();
            $nameIdx = null;
            $uuidIdx = null;
            foreach ($cols as $i => $col) {
                $pdf->SetFont($this->fontFamily, 'B', $fs);
                $head = 0;
                foreach (preg_split('/\s+/u', $col['h']) as $word) {
                    $head = max($head, $pdf->GetStringWidth($word));
                }
                if ($col['k'] === 'description') {
                    $nameIdx = $i;
                    $w[$i] = 0;
                    continue;
                }
                $pdf->SetFont($this->fontFamily, '', $fs);
                $val = 0;
                foreach ($rows as $row) {
                    if ($row[$i] !== '') $val = max($val, $pdf->GetStringWidth($row[$i]));
                }
                if ($col['k'] === 'uuid') {
                    $uuidIdx = $i;
                    $w[$i] = min(max($head, $val) + $pad, 30);
                    continue;
                }
                $w[$i] = max(max($head, $val) + $pad, 6);
            }
            $name = $this->contentWidth - array_sum($w);
            if ($name < $nameMin && $uuidIdx !== null) {
                $w[$uuidIdx] = max(18, $w[$uuidIdx] - ($nameMin - $name));
                $name = $this->contentWidth - array_sum($w);
            }
            $w[$nameIdx] = $name;
            $result = array('fs' => $fs, 'w' => $w);
            if ($name >= $nameMin) {
                return $result;
            }
        }

        $w = $result['w'];
        $nameIdx = null;
        $others = 0;
        foreach ($cols as $i => $col) {
            if ($col['k'] === 'description') {
                $nameIdx = $i;
            } else {
                $others += $w[$i];
            }
        }
        if ($w[$nameIdx] < 25 && $others > 0) {
            $factor = ($this->contentWidth - 25) / $others;
            foreach ($cols as $i => $col) {
                if ($i !== $nameIdx) $w[$i] = $w[$i] * $factor;
            }
            $w[$nameIdx] = 25;
        }
        $result['w'] = $w;
        return $result;
    }

    /**
     * @brief Draw positions header
     * @param $cols Column defs
     * @param $y
     * @param $fs Font size
     * @return float New Y
     */
    private function drawPositionsTableHeader($cols, $y, $fs = 7)
    {
        $pdf = $this->pdf;
        $lineH = $fs * 0.46;

        $pdf->SetFont($this->fontFamily, 'B', $fs);
        $pdf->SetFillColorArray($this->colorHeaderBg);
        $pdf->SetDrawColorArray($this->colorBorder);
        $pdf->SetLineWidth($this->borderWidth);

        $maxLines = 1;
        foreach ($cols as $col) {
            $maxLines = max($maxLines, $pdf->getNumLines($col['h'], $col['w']));
        }
        $headerHeight = max(8, $maxLines * $lineH + 2);

        $x = $this->marginLeft;
        foreach ($cols as $col) {
            $pdf->SetXY($x, $y);
            $pdf->Cell($col['w'], $headerHeight, '', 1, 0, 'C', true);
            $pdf->SetXY($x, $y + 1);
            $pdf->MultiCell($col['w'], $lineH, $col['h'], 0, 'C', false, 0);
            $x += $col['w'];
        }
        return $y + $headerHeight;
    }

    /**
     * @brief Render GTIN table
     * @param $y
     * @return float New Y
     */
    private function renderGtinTable($y)
    {
        $pdf = $this->pdf;
        $lines = array_merge($this->parsed['lines_before'] ?? array(), $this->parsed['lines'] ?? array());

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
            if ($y + $rowHeight > $this->pageHeight - $this->marginBottom) {
                $pdf->AddPage();
                $y = $this->marginTop;
                $y = $this->drawGtinTableHeader($cols, $y);
                $pdf->SetFont($this->fontFamily, '', $this->fontSizeTable);
            }

            $x = $this->marginLeft;
            $pdf->SetXY($x, $y);
            $pdf->Cell($cols[0]['w'], $rowHeight, $line['line_num'], 1, 0, 'C');
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
     * @brief Draw GTIN header
     * @param $cols Column defs
     * @param $y
     * @return float New Y
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
     * @brief Render total amount
     * @param $incoming KsefIncoming
     * @param $y
     * @return float New Y
     */
    private function renderTotalAmount($incoming, $y)
    {
        $pdf = $this->pdf;
        $y = $this->checkPageBreak($y, 10);

        $currency = $incoming->currency ?: 'PLN';
        $totalGross = $this->parsed['invoice']['total_gross'] ?? null;
        if ($totalGross !== null) {
            $amount = $this->formatMoney($totalGross);
        } else {
            $amount = $this->formatMoney($incoming->total_gross);
        }

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($this->marginLeft, $y);
        $labels = array(
            'ROZ' => 'Kwota pozostała do zapłaty: ',
            'KOR' => 'Korekta kwoty należności ogółem: ',
            'KOR_ROZ' => 'Korekta kwoty pozostałej do zapłaty: ',
            'ZAL' => 'Kwota zapłaty (zaliczki) dokumentowana fakturą: ',
            'KOR_ZAL' => 'Korekta kwoty zapłaty (zaliczki) dokumentowana fakturą: ',
        );
        $type = $this->parsed['invoice']['type'] ?? 'VAT';
        $label = $labels[$type] ?? 'Kwota należności ogółem: ';
        $pdf->Cell($this->contentWidth, 5, $label . $amount . ' ' . $currency, 0, 1, 'R');

        return $y + 7;
    }

    /**
     * @brief Render VAT summary
     * @param $incoming KsefIncoming
     * @param $y
     * @return float New Y
     */
    private function renderVatSummary($incoming, $y)
    {
        $pdf = $this->pdf;

        // old rows lack
        $vatSummary = array();
        if (!empty($this->parsed['vat_summary'])) {
            foreach ($this->parsed['vat_summary'] as $rate => $amounts) {
                $vatSummary[$rate] = array(
                    'net' => $amounts['net'] ?? 0,
                    'vat' => $amounts['vat'] ?? 0,
                    'vat_pln' => $amounts['vat_pln'] ?? null,
                );
            }
        }
        if (empty($vatSummary) && method_exists($incoming, 'getVatSummary')) {
            $vatSummary = $incoming->getVatSummary();
        }
        if (empty($vatSummary)) return $y;

        // art. 106e(11)
        $hasPln = false;
        foreach ($vatSummary as $amounts) {
            if (isset($amounts['vat_pln']) && $amounts['vat_pln'] !== null && $amounts['vat_pln'] !== '') $hasPln = true;
        }

        $amountW = 28;
        $headers = array('Lp.', 'Stawka podatku', 'Kwota netto', 'Kwota podatku', 'Kwota brutto');
        if ($hasPln) $headers[] = 'Kwota podatku PLN';
        $colW = array(10, 0);
        for ($i = 2; $i < count($headers); $i++) $colW[] = $amountW;
        $colW[1] = $this->contentWidth - array_sum($colW);

        $pdf->SetFont($this->fontFamily, '', $this->fontSizeTable);
        $rowHeights = array();
        foreach ($vatSummary as $rate => $amounts) {
            $rowHeights[$rate] = max(5, $pdf->getNumLines($this->getVatRateLabel($rate), $colW[1] - 1) * 3.2 + 1);
        }

        $y = $this->checkPageBreak($y, 11 + reset($rowHeights));

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 5, 'Podsumowanie stawek podatku', 0, 1, 'L');
        $y += 6;

        $drawHeader = function ($y) use ($pdf, $headers, $colW) {
            $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeTable);
            $pdf->SetFillColorArray($this->colorHeaderBg);
            $pdf->SetDrawColorArray($this->colorBorder);
            $pdf->SetLineWidth($this->borderWidth);
            $x = $this->marginLeft;
            foreach ($headers as $i => $h) {
                $pdf->SetXY($x, $y);
                $pdf->Cell($colW[$i], 5, $h, 1, 0, 'C', true, '', 1);
                $x += $colW[$i];
            }
            $pdf->SetFont($this->fontFamily, '', $this->fontSizeTable);
            return $y + 5;
        };
        $y = $drawHeader($y);

        $rowNum = 1;
        foreach ($vatSummary as $rate => $amounts) {
            $rh = $rowHeights[$rate];
            $oldY = $y;
            $y = $this->checkPageBreak($y, $rh + 2);
            if ($y < $oldY) {
                $y = $drawHeader($y);
            }

            $x = $this->marginLeft;
            $pdf->SetXY($x, $y);
            $pdf->Cell($colW[0], $rh, $rowNum, 1, 0, 'C');
            $x += $colW[0];
            $pdf->SetXY($x, $y);
            $pdf->Cell($colW[1], $rh, '', 1, 0, 'L');
            $pdf->SetXY($x + 0.5, $y + 0.5);
            $pdf->MultiCell($colW[1] - 1, 3.2, $this->getVatRateLabel($rate), 0, 'L', false, 0);
            $x += $colW[1];
            $gross = ($amounts['net'] ?? 0) + ($amounts['vat'] ?? 0);
            $values = array($amounts['net'] ?? 0, $amounts['vat'] ?? 0, $gross);
            if ($hasPln) $values[] = $amounts['vat_pln'] ?? null;
            foreach ($values as $k => $v) {
                $pdf->SetXY($x, $y);
                $pdf->Cell($colW[2 + $k], $rh, $this->formatMoney($v), 1, 0, 'R');
                $x += $colW[2 + $k];
            }
            $y += $rh;
            $rowNum++;
        }

        return $y + 3;
    }

    /**
     * @brief Render Adnotacje
     * @param $y
     * @return float New Y
     */
    private function renderAdnotacje($y)
    {
        $inv = $this->parsed['invoice'] ?? array();
        $entries = array();

        if (!empty($inv['p_19_flag'])) {
            $entries[] = array('label' => '', 'text' => 'Dostawa towarów lub świadczenie usług zwolnionych od podatku na podstawie art. 43 ust. 1, art. 113 ust. 1 i 9 albo przepisów wydanych na podstawie art. 82 ust. 3 lub na podstawie innych przepisów', 'balance' => true);
            if (!empty($inv['p_19a'])) {
                $entries[] = array('label' => 'Podstawa zwolnienia od podatku: ', 'text' => 'Przepis ustawy albo aktu wydanego na podstawie ustawy, na podstawie którego podatnik stosuje zwolnienie od podatku', 'balance' => true);
                $entries[] = array('label' => 'Przepis ustawy albo aktu wydanego na podstawie ustawy: ', 'text' => $inv['p_19a'], 'balance' => true);
            }
            if (!empty($inv['p_19b'])) {
                $entries[] = array('label' => 'Podstawa zwolnienia od podatku: ', 'text' => 'Przepis dyrektywy 2006/112/WE, który zwalnia od podatku taką dostawę towarów lub takie świadczenie usług', 'balance' => true);
                $entries[] = array('label' => 'Przepis dyrektywy: ', 'text' => $inv['p_19b'], 'balance' => true);
            }
            if (!empty($inv['p_19c'])) {
                $entries[] = array('label' => 'Podstawa zwolnienia od podatku: ', 'text' => 'Inna podstawa prawna wskazującą na to, że dostawa towarów lub świadczenie usług korzysta ze zwolnienia', 'balance' => true);
                $entries[] = array('label' => 'Inna podstawa prawna: ', 'text' => $inv['p_19c'], 'balance' => true);
            }
        }

        if (!empty($inv['mpp_flag']))  $entries[] = array('label' => '', 'text' => 'Mechanizm podzielonej płatności', 'balance' => false);
        if (!empty($inv['p_16_flag'])) $entries[] = array('label' => '', 'text' => 'Metoda kasowa', 'balance' => false);
        if (!empty($inv['p_18_flag'])) $entries[] = array('label' => '', 'text' => 'Odwrotne obciążenie', 'balance' => false);
        if (!empty($inv['p_23_flag'])) $entries[] = array('label' => '', 'text' => 'Procedura trójstronna uproszczona', 'balance' => false);
        if (!empty($inv['pmarzy_flag'])) {
            $marzyTypes = array('2' => 'biura podróży', '3_1' => 'towary używane', '3_2' => 'dzieła sztuki', '3_3' => 'przedmioty kolekcjonerskie i antyki');
            $marzyVal = isset($marzyTypes[$inv['pmarzy_subtype']]) ? $marzyTypes[$inv['pmarzy_subtype']] : '';
            $entries[] = array('label' => 'Procedura marży: ', 'text' => $marzyVal, 'balance' => false);
        }
        if (!empty($inv['p_17_flag'])) $entries[] = array('label' => '', 'text' => 'Samofakturowanie', 'balance' => false);

        if (empty($entries)) {
            return $y;
        }

        $left = array();
        $right = array();
        foreach ($entries as $e) {
            if (count($left) > count($right) && $e['balance']) {
                $right[] = $e;
            } else {
                $left[] = $e;
            }
        }

        $pdf = $this->pdf;
        $colGap = 6;
        $colW = ($this->contentWidth - $colGap) / 2;

        $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
        $measure = function ($items) use ($pdf, $colW) {
            $h = 0;
            foreach ($items as $e) $h += $pdf->getStringHeight($colW, $e['label'] . $e['text']);
            return $h;
        };
        $y = $this->checkPageBreak($y, 8 + max($measure($left), $measure($right)));

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 5, 'Adnotacje', 0, 1, 'L');
        $y += 5;

        $yLeft = $this->renderAdnotacjeColumn($left, $this->marginLeft, $colW, $y);
        $yRight = $this->renderAdnotacjeColumn($right, $this->marginLeft + $colW + $colGap, $colW, $y);

        return max($yLeft, $yRight) + 2;
    }

    /**
     * @brief Render one column
     * @param $items
     * @param $x
     * @param $w
     * @param $y
     * @return float New Y
     */
    private function renderAdnotacjeColumn($items, $x, $w, $y)
    {
        $pdf = $this->pdf;
        $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
        foreach ($items as $e) {
            $html = ($e['label'] !== '' ? '<b>' . htmlspecialchars($e['label'], ENT_QUOTES, 'UTF-8') . '</b>' : '')
                . htmlspecialchars($e['text'], ENT_QUOTES, 'UTF-8');
            $pdf->writeHTMLCell($w, 0, $x, $y, $html, 0, 1);
            $y = $pdf->GetY();
        }
        return $y;
    }

    /**
     * @brief Render additional info
     * @param $items
     * @param $y
     * @return float
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
            $pdf->Cell($this->contentWidth, 4, $item, 0, 1, 'L');
            $y += 4;
        }

        return $y + 2;
    }

    /**
     * @brief Render additional description
     * @param $y
     * @return float
     */
    private function renderAdditionalDesc($y)
    {
        $pdf = $this->pdf;
        $items = $this->parsed['additional_desc'] ?? array();
        $y = $this->checkPageBreak($y, 20);

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 4, 'Dodatkowy opis', 0, 1, 'L');
        $y += 5;

        $smallTableFont = 6;

        $hasNrWiersza = false;
        foreach ($items as $item) {
            if (!empty($item['nr_wiersza'])) {
                $hasNrWiersza = true;
                break;
            }
        }

        if ($hasNrWiersza) {
            $colW = array(10, 22, 38, 120);
            $headers = array('Lp.', 'Numer wiersza', 'Rodzaj informacji', 'Treść informacji');
        } else {
            $colW = array(10, 50, 130);
            $headers = array('Lp.', 'Rodzaj informacji', 'Treść informacji');
        }

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
        $colIdxRodzaj = $hasNrWiersza ? 2 : 1;
        $colIdxTresc = $hasNrWiersza ? 3 : 2;
        $rowNum = 1;
        foreach ($items as $item) {
            $rodzaj = $item['key'] ?? '';
            $tresc = $item['value'] ?? '';

            $rodzajLines = $rodzaj !== '' ? $pdf->getNumLines($rodzaj, $colW[$colIdxRodzaj] - 2) : 1;
            $trescLines = $tresc !== '' ? $pdf->getNumLines($tresc, $colW[$colIdxTresc] - 2) : 1;
            $maxLines = max($rodzajLines, $trescLines, 1);
            $rowHeight = max(5, $maxLines * 4);

            $oldY = $y;
            $y = $this->checkPageBreak($y, $rowHeight + 2);
            if ($y < $oldY) {
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

            $pdf->SetXY($x, $y);
            $pdf->Cell($colW[0], $rowHeight, $rowNum, 1, 0, 'C');
            $x += $colW[0];

            if ($hasNrWiersza) {
                $nrPoz = !empty($item['nr_wiersza']) ? (string) $item['nr_wiersza'] : '';
                $pdf->SetXY($x, $y);
                $pdf->Cell($colW[1], $rowHeight, $nrPoz, 1, 0, 'C');
                $x += $colW[1];
            }

            $pdf->SetXY($x, $y);
            $pdf->Cell($colW[$colIdxRodzaj], $rowHeight, '', 1, 0, 'L');
            $pdf->SetXY($x + 1, $y + 1);
            $pdf->MultiCell($colW[$colIdxRodzaj] - 2, 4, $rodzaj, 0, 'L', false, 0);
            $x += $colW[$colIdxRodzaj];

            $pdf->SetXY($x, $y);
            $pdf->Cell($colW[$colIdxTresc], $rowHeight, '', 1, 0, 'L');
            $pdf->SetXY($x + 1, $y + 1);
            $pdf->MultiCell($colW[$colIdxTresc] - 2, 4, $tresc, 0, 'L', false, 0);

            $y += $rowHeight;
            $rowNum++;
        }

        return $y + 3;
    }

    /**
     * @brief Render payment section
     * @param $incoming KsefIncoming
     * @param $y
     * @return float
     */
    private function renderPayment($incoming, $y)
    {
        $payment = $this->parsed['payment'] ?? array();
        if (empty($payment) && empty($incoming->payment_method)) return $y;

        $y = $this->checkPageBreak($y, 30);
        $y = $this->drawLine($y);

        $pdf = $this->pdf;
        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 5, 'Płatność', 0, 1, 'L');
        $y += 6;

        $status = $payment['status'] ?? null;
        if ($status === 'paid') {
            $y = $this->renderPaymentField($y, 'Informacja o płatności: ', 'Zapłacono');
            if (!empty($payment['payment_date'])) {
                $y = $this->renderPaymentField($y, 'Data zapłaty: ', $this->formatDatePolish($payment['payment_date']));
            }
        } elseif ($status === 'partial' || $status === 'paid_installments') {
            $y = $this->renderPaymentField($y, 'Informacja o płatności: ', 'Zapłata częściowa');
            $y = $this->renderPaymentField($y, 'Informacja o płatności (kontynuacja): ', $status === 'partial' ? 'Zapłacono w części' : 'Zapłacono całość w częściach');
        }

        $method = $incoming->payment_method ?? $payment['method'] ?? null;
        if (!empty($method)) {
            $y = $this->renderPaymentField($y, 'Forma płatności: ', $this->getPaymentMethodLabel($method));
        } elseif (!empty($payment['method_description'])) {
            $y = $this->renderPaymentField($y, 'Forma płatności: ', 'Płatność inna');
            $y = $this->renderPaymentField($y, 'Opis płatności innej: ', $payment['method_description']);
        }

        $partialTable = null;
        if (!empty($payment['partial_payments'])) {
            $rows = array();
            foreach ($payment['partial_payments'] as $partial) {
                if (isset($partial['method'])) {
                    $methodLabel = $this->getPaymentMethodLabel($partial['method']);
                } elseif (isset($partial['method_description'])) {
                    $methodLabel = $partial['method_description'];
                } else {
                    $methodLabel = '';
                }
                $rows[] = array($this->formatDatePolish($partial['date'] ?? ''), $this->formatMoney($partial['amount'] ?? null), $methodLabel);
            }
            $partialTable = array(array('Data zapłaty częściowej', 'Kwota zapłaty częściowej', 'Forma płatności'), $rows, array('L', 'R', 'L'));
        }
        $terms = $payment['due_dates'] ?? array();
        if (empty($terms) && (!empty($payment['due_date']) || !empty($payment['due_date_description']))) {
            $terms = array(array('date' => $payment['due_date'] ?? null, 'description' => $payment['due_date_description'] ?? null));
        }
        $termTable = null;
        if (!empty($terms)) {
            $hasDesc = false;
            foreach ($terms as $term) {
                if (!empty($term['description'])) $hasDesc = true;
            }
            $rows = array();
            foreach ($terms as $term) {
                $row = array($this->formatDatePolish($term['date'] ?? ''));
                if ($hasDesc) $row[] = (string) ($term['description'] ?? '');
                $rows[] = $row;
            }
            $termTable = array($hasDesc ? array('Termin płatności', 'Opis płatności') : array('Termin płatności'), $rows, $hasDesc ? array('L', 'L') : array('L'));
        }
        $y = $this->renderPaymentTables($partialTable, $termTable, $y);

        if (!empty($payment['payment_link'])) {
            $y = $this->checkPageBreak($y, 8);
            $y = $this->renderTextAt($this->marginLeft, $y, $this->contentWidth, 'Link do płatności bezgotówkowej: ', 'B');
            $y = $this->renderTextAt($this->marginLeft, $y, $this->contentWidth, $payment['payment_link']);
        }
        if (!empty($payment['ipksef'])) {
            $y = $this->renderPaymentField($y, 'Identyfikator płatności Krajowego Systemu e-Faktur: ', $payment['ipksef']);
        }

        $regular = $payment['bank_accounts'] ?? array();
        if (empty($regular) && !empty($payment['bank_account'])) {
            $regular = array($payment);
        }
        $accounts = array();
        foreach ($regular as $acc) {
            $accounts[] = array('title' => 'Numer rachunku bankowego', 'data' => $acc);
        }
        foreach (($payment['factor_bank_accounts'] ?? array()) as $acc) {
            $accounts[] = array('title' => 'Numer rachunku bankowego faktora', 'data' => $acc);
        }
        if (!empty($accounts)) {
            $y = $this->renderBankAccounts($accounts, $y);
        }

        if (!empty($payment['discount'])) {
            $y = $this->checkPageBreak($y, 14);
            $this->pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
            $this->pdf->SetXY($this->marginLeft, $y);
            $this->pdf->Cell($this->contentWidth, 4, 'Skonto', 0, 1, 'L');
            $y += 5;
            $y = $this->renderPaymentField($y, 'Warunki skonta: ', (string) ($payment['discount']['conditions'] ?? ''));
            $y = $this->renderPaymentField($y, 'Wysokość skonta: ', (string) ($payment['discount']['amount'] ?? ''));
        }

        return $y + 2;
    }

    /**
     * @brief Render payment line
     * @param $y
     * @param $label
     * @param $value
     * @return float
     */
    private function renderPaymentField($y, $label, $value)
    {
        $y = $this->checkPageBreak($y, 4);
        return $this->renderFieldAt($this->marginLeft, $y, $this->contentWidth, $label, $value);
    }

    /**
     * @brief Render payment tables
     * @param $partialTable Table or null
     * @param $termTable Table or null
     * @param $y
     * @return float
     */
    private function renderPaymentTables($partialTable, $termTable, $y)
    {
        if ($partialTable === null && $termTable === null) return $y;
        $gap = 6;
        $colW = ($this->contentWidth - $gap) / 2;
        $leftX = $this->marginLeft;
        $rightX = $this->marginLeft + $colW + $gap;
        if ($partialTable !== null && $termTable !== null) {
            $blocks = array(array($leftX, $colW, $partialTable), array($rightX, $colW, $termTable));
        } elseif ($termTable !== null) {
            $blocks = array(array($leftX, $colW, $termTable));
        } else {
            $blocks = array(array($leftX, $this->contentWidth, $partialTable));
        }
        $draw = function ($y0) use ($blocks) {
            $end = $y0;
            foreach ($blocks as $b) {
                $end = max($end, $this->renderWrapTable($b[0], $y0, $b[1], $b[2][0], $b[2][1], $b[2][2], false));
            }
            return $end;
        };
        $h = $this->measureBlock($draw);
        $bottom = $this->pageHeight - $this->marginBottom;
        if ($y + $h <= $bottom) {
            return $draw($y) + 2;
        }
        if ($h <= 60) {
            return $draw($this->checkPageBreak($y, $h)) + 2;
        }
        foreach ($blocks as $b) {
            $y = $this->renderWrapTable($this->marginLeft, $y, $this->contentWidth, $b[2][0], $b[2][1], $b[2][2], true) + 2;
        }
        return $y;
    }

    /**
     * @brief Render wrapping table
     * @param $x
     * @param $y
     * @param $w
     * @param $headers
     * @param $rows
     * @param $aligns Per-column alignment
     * @param $breaks Break per row
     * @return float
     */
    private function renderWrapTable($x, $y, $w, $headers, $rows, $aligns, $breaks)
    {
        $n = count($headers);
        if ($n === 0) return $y;
        $cw = $w / $n;
        $lineH = 3.2;

        $drawHeader = function ($y) use ($x, $cw, $headers, $lineH) {
            $pdf = $this->pdf;
            $pdf->SetDrawColorArray($this->colorBorder);
            $pdf->SetLineWidth($this->borderWidth);
            $pdf->SetFillColorArray($this->colorHeaderBg);
            $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeTable);
            $hh = 5;
            foreach ($headers as $hd) {
                $hh = max($hh, $pdf->getNumLines($hd, $cw - 1) * $lineH + 1.6);
            }
            $cx = $x;
            foreach ($headers as $hd) {
                $pdf->SetXY($cx, $y);
                $pdf->Cell($cw, $hh, '', 1, 0, 'L', true);
                $pdf->SetXY($cx + 0.5, $y + 0.8);
                $pdf->MultiCell($cw - 1, $lineH, $hd, 0, 'L', false, 0);
                $cx += $cw;
            }
            return $y + $hh;
        };

        $y = $drawHeader($y);
        foreach ($rows as $row) {
            $pdf = $this->pdf;
            $pdf->SetFont($this->fontFamily, '', $this->fontSizeTable);
            $rh = 5;
            foreach ($row as $cell) {
                $rh = max($rh, $pdf->getNumLines((string) $cell, $cw - 1) * $lineH + 1.6);
            }
            if ($breaks && $y + $rh > $this->pageHeight - $this->marginBottom) {
                $this->pdf->AddPage();
                $y = $drawHeader($this->marginTop);
                $pdf = $this->pdf;
                $pdf->SetFont($this->fontFamily, '', $this->fontSizeTable);
            }
            $pdf->SetDrawColorArray($this->colorBorder);
            $pdf->SetLineWidth($this->borderWidth);
            $cx = $x;
            foreach (array_values($row) as $i => $cell) {
                $pdf->SetXY($cx, $y);
                $pdf->Cell($cw, $rh, '', 1, 0, 'L');
                $pdf->SetXY($cx + 0.5, $y + 0.8);
                $pdf->MultiCell($cw - 1, $lineH, (string) $cell, 0, $aligns[$i] ?? 'L', false, 0);
                $cx += $cw;
            }
            $y += $rh;
        }
        return $y;
    }

    /**
     * @brief Bank account fields
     * @return array
     */
    private function bankAccountFields()
    {
        return array(
            array('label' => 'Pełny numer rachunku', 'key' => 'bank_account'),
            array('label' => 'Kod SWIFT', 'key' => 'bank_swift'),
            array('label' => 'Rachunek własny banku', 'key' => 'bank_own_account'),
            array('label' => 'Nazwa banku', 'key' => 'bank_name'),
            array('label' => 'Opis rachunku', 'key' => 'bank_description'),
        );
    }

    /**
     * @brief Own account label
     * @param $code
     * @return string
     */
    private function getOwnBankAccountLabel($code)
    {
        $labels = array(
            '1' => 'Rachunek banku lub rachunek spółdzielczej kasy oszczędnościowo-kredytowej służący do dokonywania rozliczeń z tytułu nabywanych przez ten bank lub tę kasę wierzytelności pieniężnych',
            '2' => 'Rachunek banku lub rachunek spółdzielczej kasy oszczędnościowo-kredytowej wykorzystywany przez ten bank lub tę kasę do pobrania należności od nabywcy towarów lub usług za dostawę towarów lub świadczenie usług, potwierdzone fakturą, i przekazania jej w całości albo części dostawcy towarów lub usługodawcy',
            '3' => 'Rachunek banku lub rachunek spółdzielczej kasy oszczędnościowo-kredytowej prowadzony przez ten bank lub tę kasę w ramach gospodarki własnej, niebędący rachunkiem rozliczeniowym',
        );
        $code = trim((string) $code);
        return $labels[$code] ?? $code;
    }

    /**
     * @brief Bank account rows
     * @param $data Bank fields
     * @param $w
     * @return array
     */
    private function bankAccountRows($data, $w)
    {
        $pdf = $this->pdf;
        $labelW = min(45, round($w * 0.40));
        $valueW = $w - $labelW;
        $rows = array();
        foreach ($this->bankAccountFields() as $field) {
            if (empty($data[$field['key']])) continue;
            $value = (string) $data[$field['key']];
            if ($field['key'] === 'bank_account') $value = $this->formatBankAccount($value);
            if ($field['key'] === 'bank_own_account') $value = $this->getOwnBankAccountLabel($value);
            $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeTable);
            $nl = $pdf->getNumLines($field['label'], $labelW - 1);
            $pdf->SetFont($this->fontFamily, '', $this->fontSizeTable);
            $nv = $pdf->getNumLines($value, $valueW - 1);
            $rows[] = array($field['label'], $value, max(5, max($nl, $nv) * 3.2 + 1.6));
        }
        return $rows;
    }

    /**
     * @brief Bank block height
     * @param $data Account data
     * @param $w
     * @return float
     */
    private function bankAccountHeight($data, $w)
    {
        $rows = $this->bankAccountRows($data, $w);
        if (empty($rows)) return 0;
        $h = 6;
        foreach ($rows as $r) $h += $r[2];
        return $h;
    }

    /**
     * @brief Render bank accounts
     * @param array $accounts
     * @param float $y
     * @return float
     */
    private function renderBankAccounts($accounts, $y)
    {
        $count = count($accounts);
        if ($count === 1) {
            $y = $this->checkPageBreak($y, $this->bankAccountHeight($accounts[0]['data'], $this->contentWidth) + 2);
            return $this->renderOneBankAccount($accounts[0]['title'], $accounts[0]['data'], $this->marginLeft, $y, $this->contentWidth) + 2;
        }

        $gap = 6;
        $colW = ($this->contentWidth - $gap) / 2;
        $leftX = $this->marginLeft;
        $rightX = $this->marginLeft + $colW + $gap;

        for ($i = 0; $i < $count; $i += 2) {
            $hL = $this->bankAccountHeight($accounts[$i]['data'], $colW);
            $hR = isset($accounts[$i + 1]) ? $this->bankAccountHeight($accounts[$i + 1]['data'], $colW) : 0;
            $y = $this->checkPageBreak($y, max($hL, $hR) + 2);

            $yL = $this->renderOneBankAccount($accounts[$i]['title'], $accounts[$i]['data'], $leftX, $y, $colW);
            $yR = $y;
            if (isset($accounts[$i + 1])) {
                $yR = $this->renderOneBankAccount($accounts[$i + 1]['title'], $accounts[$i + 1]['data'], $rightX, $y, $colW);
            }
            $y = max($yL, $yR) + 2;
        }

        return $y;
    }

    /**
     * @brief Render bank block
     * @param $title
     * @param $data Account data
     * @param $x
     * @param $y
     * @param $w
     * @return float
     */
    private function renderOneBankAccount($title, $data, $x, $y, $w)
    {
        $rows = $this->bankAccountRows($data, $w);
        if (empty($rows)) return $y;
        $pdf = $this->pdf;

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($x, $y);
        $pdf->Cell($w, 5, $title, 0, 1, 'L');
        $y += 6;

        $labelW = min(45, round($w * 0.40));
        $valueW = $w - $labelW;

        $pdf->SetFillColorArray($this->colorHeaderBg);
        $pdf->SetDrawColorArray($this->colorBorder);
        $pdf->SetLineWidth($this->borderWidth);

        foreach ($rows as $r) {
            $pdf->SetXY($x, $y);
            $pdf->Cell($labelW, $r[2], '', 1, 0, 'L', true);
            $pdf->Cell($valueW, $r[2], '', 1, 0, 'L');
            $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeTable);
            $pdf->SetXY($x + 0.5, $y + 0.8);
            $pdf->MultiCell($labelW - 1, 3.2, $r[0], 0, 'L', false, 0);
            $pdf->SetFont($this->fontFamily, '', $this->fontSizeTable);
            $pdf->SetXY($x + $labelW + 0.5, $y + 0.8);
            $pdf->MultiCell($valueW - 1, 3.2, $r[1], 0, 'L', false, 0);
            $y += $r[2];
        }

        return $y;
    }

    /**
     * @brief Format IBAN
     * @param $acct
     * @return string
     */
    private function formatBankAccount($acct)
    {
        $acct = trim((string) $acct);
        $digits = preg_replace('/\s+/', '', $acct);
        if (!preg_match('/^\d{26}$/', $digits)) {
            return $acct;
        }
        return substr($digits, 0, 2) . ' ' . trim(chunk_split(substr($digits, 2), 4, ' '));
    }

    /**
     * @brief Has transaction terms
     * @return bool
     */
    private function hasWarunkiTransakcji()
    {
        $w = $this->parsed['warunki'] ?? array();
        if (empty($w)) return false;
        return !empty($w['umowy']) || !empty($w['zamowienia']) || !empty($w['partie'])
            || !empty($w['waluta_umowna']) || !empty($w['kurs_umowny']) || !empty($w['warunki_dostawy'])
            || !empty($w['podmiot_posredniczacy']) || !empty($w['transport']);
    }

    /**
     * @brief Render transaction terms
     * @param $y
     * @return float
     */
    private function renderWarunkiTransakcji($y)
    {
        $pdf = $this->pdf;
        $w = $this->parsed['warunki'];

        $y = $this->checkPageBreak($y, 25);
        $y = $this->drawLine($y);

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 5, 'Warunki transakcji', 0, 1, 'L');
        $y += 6;

        $hasUmowy = !empty($w['umowy']);
        $hasZam = !empty($w['zamowienia']);
        if ($hasUmowy || $hasZam) {
            $colGap = 6;
            $colW = ($this->contentWidth - $colGap) / 2;
            $rightX = $this->marginLeft + $colW + $colGap;
            $rows = (max($hasUmowy ? count($w['umowy']) : 0, $hasZam ? count($w['zamowienia']) : 0)) + 1;
            $y = $this->checkPageBreak($y, 6 + 5 * $rows);
            $yStart = $y;
            $yLeft = $y;
            $yRight = $y;
            if ($hasUmowy) {
                $yLeft = $this->renderWarunkiSubBlock($this->marginLeft, $yStart, $colW, 'Umowa',
                    array('Data umowy', 'Numer umowy'),
                    array_map(function ($u) { return array($this->formatDatePolish($u['data']), (string) $u['nr']); }, $w['umowy']));
            }
            if ($hasZam) {
                $yRight = $this->renderWarunkiSubBlock($rightX, $yStart, $colW, 'Zamówienie',
                    array('Data zamówienia', 'Numer zamówienia'),
                    array_map(function ($z) { return array($this->formatDatePolish($z['data']), (string) $z['nr']); }, $w['zamowienia']));
            }
            $y = max($yLeft, $yRight) + 3;
        }

        if (!empty($w['waluta_umowna']) || !empty($w['kurs_umowny'])) {
            $y = $this->checkPageBreak($y, 18);
            $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
            $pdf->SetXY($this->marginLeft, $y);
            $pdf->Cell($this->contentWidth, 5, 'Waluta umowna i kurs umowny', 0, 1, 'L');
            $y += 5;
            if (!empty($w['waluta_umowna'])) $y = $this->renderLabelValue($y, 'Waluta umowna: ', $w['waluta_umowna']);
            if (!empty($w['kurs_umowny'])) $y = $this->renderLabelValue($y, 'Kurs umowny: ', $w['kurs_umowny']);
            $y += 2;
        }

        if (!empty($w['partie'])) {
            $colW = ($this->contentWidth - 6) / 2;
            $batchRows = array_map(function ($p) { return array($p); }, $w['partie']);
            $y = $this->checkPageBreak($y, 5 * (count($batchRows) + 1) + 3);
            $y = $this->renderMiniTable($this->marginLeft, $y, $colW, array('Numer partii towaru'), $batchRows) + 3;
        }

        if (!empty($w['warunki_dostawy'])) {
            $y = $this->checkPageBreak($y, 8);
            $y = $this->renderLabelValue($y, 'Warunki dostawy towarów: ', $w['warunki_dostawy']) + 2;
        }

        if (!empty($w['podmiot_posredniczacy'])) {
            $y = $this->checkPageBreak($y, 14);
            $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
            $text = 'Dostawa dokonana przez podmiot, o którym mowa w art. 22 ust. 2d ustawy. Pole dotyczy sytuacji, w której podmiot uczestniczy w transakcji łańcuchowej innej niż procedura trójstronna uproszczona, o której mowa w art. 135 ust. 1 pkt 4 ustawy';
            $pdf->SetXY($this->marginLeft, $y);
            $pdf->MultiCell($this->contentWidth, 4, $text, 0, 'L');
            $y = $pdf->GetY() + 2;
        }

        if (!empty($w['transport'])) {
            $multi = count($w['transport']) > 1;
            foreach ($w['transport'] as $i => $t) {
                $y = $this->renderTransport($y, $t, $multi ? ($i + 1) : null);
            }
        }

        return $y + 2;
    }

    /**
     * @brief Render labelled sub-block
     * @param $x
     * @param $y
     * @param $w
     * @param $title
     * @param $headers
     * @param $rows
     * @return float
     */
    private function renderWarunkiSubBlock($x, $y, $w, $title, $headers, $rows)
    {
        $pdf = $this->pdf;
        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
        $pdf->SetXY($x, $y);
        $pdf->Cell($w, 5, $title, 0, 1, 'L');
        return $this->renderMiniTable($x, $y + 5, $w, $headers, $rows);
    }

    /**
     * @brief Render advance invoices
     * @param float $y
     * @return float
     */
    private function renderDeposits($y)
    {
        $rows = array();
        foreach ($this->parsed['deposits'] as $d) {
            $rows[] = array($d['ksef_number'] !== '' ? $d['ksef_number'] : $d['number'] . ' (poza KSeF)');
        }
        $y = $this->checkPageBreak($y, 5 * (count($rows) + 1) + 4);
        $y = $this->renderMiniTable($this->marginLeft, $y + 1, $this->contentWidth, array('Numery wcześniejszych faktur zaliczkowych'), $rows);
        return $y + 3;
    }

    /**
     * @brief Render advance order
     * @param float $y
     * @return float
     */
    private function renderOrder($y)
    {
        $pdf = $this->pdf;
        $order = $this->parsed['order'];
        $y = $this->checkPageBreak($y, 14);
        if (($this->parsed['lines_source'] ?? '') !== 'order') {
            $blocks = array();
            if (!empty($order['lines_before'])) {
                $blocks['Zamówienie przed korektą'] = $order['lines_before'];
                $blocks['Zamówienie po korekcie'] = $order['lines'];
            } else {
                $blocks['Zamówienie'] = $order['lines'];
            }
            foreach ($blocks as $title => $lines) {
                $rows = array();
                foreach ($lines as $l) {
                    $rows[] = array((string) $l['line_num'], dol_trunc((string) $l['description'], 40, 'right', 'UTF-8', 1), $this->formatQuantity($l['quantity']),
                        $l['unit_price_net'] !== null ? $this->formatMoney($l['unit_price_net']) : '', $l['net_amount'] !== null ? $this->formatMoney($l['net_amount']) : '',
                        is_numeric($l['vat_rate']) ? $this->formatPercent($l['vat_rate']) : (string) $l['vat_rate']);
                }
                $y = $this->checkPageBreak($y, 5 * (count($rows) + 2) + 2);
                $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
                $pdf->SetXY($this->marginLeft, $y);
                $pdf->Cell($this->contentWidth, 5, $title, 0, 1, 'L');
                $y = $this->renderMiniTable($this->marginLeft, $y + 5, $this->contentWidth, array('Lp.', 'Nazwa', 'Ilość', 'Cena netto', 'Wartość netto', 'Stawka'), $rows) + 2;
            }
        }
        $y = $this->renderLabelValue($y, 'Wartość zamówienia lub umowy z uwzględnieniem kwoty podatku: ', $this->formatMoney($order['value']));
        return $y + 2;
    }

    /**
     * @brief Render mini table
     * @param $x
     * @param $y
     * @param $w
     * @param $headers
     * @param $rows
     * @return float
     */
    private function renderMiniTable($x, $y, $w, $headers, $rows)
    {
        $pdf = $this->pdf;
        $n = count($headers);
        if ($n === 0) return $y;
        $cw = $w / $n;

        $pdf->SetDrawColorArray($this->colorBorder);
        $pdf->SetLineWidth($this->borderWidth);

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeTable);
        $pdf->SetFillColorArray($this->colorHeaderBg);
        $cx = $x;
        foreach ($headers as $h) {
            $pdf->SetXY($cx, $y);
            $pdf->Cell($cw, 5, $h, 1, 0, 'L', true);
            $cx += $cw;
        }
        $y += 5;

        $pdf->SetFont($this->fontFamily, '', $this->fontSizeTable);
        foreach ($rows as $row) {
            $cx = $x;
            foreach ($row as $cell) {
                $pdf->SetXY($cx, $y);
                $pdf->Cell($cw, 5, (string) $cell, 1, 0, 'L');
                $cx += $cw;
            }
            $y += 5;
        }

        return $y;
    }

    /**
     * @brief Render one transport
     * @param $y
     * @param $t Transport data
     * @param $index 1-based or null
     * @return float
     */
    private function renderTransport($y, $t, $index)
    {
        $pdf = $this->pdf;
        $y = $this->checkPageBreak($y, 20);
        $y = $this->drawLine($y);

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 5, 'Transport' . ($index ? ' ' . $index : ''), 0, 1, 'L');
        $y += 5;

        $colGap = 6;
        $colW = ($this->contentWidth - $colGap) / 2;
        $rightX = $this->marginLeft + $colW + $colGap;

        $leftItems = array();
        if (!empty($t['rodzaj'])) {
            $leftItems[] = array('Rodzaj transportu: ', $this->getTransportTypeName($t['rodzaj']));
        } elseif (($t['transport_inny'] ?? '') === '1' && !empty($t['opis_innego_transportu'])) {
            $leftItems[] = array('Rodzaj transportu: ', 'Transport inny');
            $leftItems[] = array('Opis innego rodzaju transportu: ', $t['opis_innego_transportu']);
        }

        $rightItems = array();
        if (!empty($t['nr_zlecenia'])) $rightItems[] = array('Numer zlecenia transportu: ', $t['nr_zlecenia']);
        if (!empty($t['opis_ladunku'])) {
            $rightItems[] = array('Opis ładunku: ', $this->getCargoTypeName($t['opis_ladunku']));
        }
        if (($t['ladunek_inny'] ?? '') === '1' && !empty($t['opis_innego_ladunku'])) {
            $rightItems[] = array('Opis ładunku: ', 'Ładunek inny');
            $rightItems[] = array('Opis innego ładunku: ', $t['opis_innego_ladunku']);
        }
        if (!empty($t['jednostka_opakowania'])) $rightItems[] = array('Jednostka opakowania: ', $t['jednostka_opakowania']);
        if (!empty($t['data_rozp'])) $rightItems[] = array('Data i godzina rozpoczęcia transportu: ', $this->formatDateTimePolish($t['data_rozp']));
        if (!empty($t['data_zak'])) $rightItems[] = array('Data i godzina zakończenia transportu: ', $this->formatDateTimePolish($t['data_zak']));

        if (!empty($leftItems) || !empty($rightItems)) {
            $yStart = $y;
            $yL = $yStart;
            $yR = $yStart;
            foreach ($leftItems as $it) {
                $yL = $this->renderLabelValueAt($this->marginLeft, $yL, $colW, $it[0], $it[1]);
            }
            if (!empty($rightItems)) {
                $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
                $pdf->SetXY($rightX, $yR);
                $pdf->Cell($colW, 4, 'Dane transportu', 0, 1, 'L');
                $yR += 4;
                foreach ($rightItems as $it) {
                    $yR = $this->renderLabelValueAt($rightX, $yR, $colW, $it[0], $it[1]);
                }
            }
            $y = max($yL, $yR) + 3;
        }

        if (!empty($t['przewoznik'])) {
            $y = $this->renderTransportCarrier($y, $t['przewoznik']);
        }

        $z = $t['wysylka_z'] ?? null;
        $do = $t['wysylka_do'] ?? null;
        $przez = $t['wysylka_przez'] ?? array();
        if (!empty($z) || !empty($do) || !empty($przez)) {
            $y = $this->checkPageBreak($y, 18);
            $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
            $pdf->SetXY($this->marginLeft, $y);
            $pdf->Cell($this->contentWidth, 5, 'Wysyłka', 0, 1, 'L');
            $y += 5;

            $yStart = $y;
            $yL = $yStart;
            $yR = $yStart;
            if (!empty($z)) $yL = $this->renderTransportAdres($this->marginLeft, $yL, $colW, 'Adres miejsca wysyłki', $z);
            foreach ($przez as $adr) {
                $yL += 2;
                $yL = $this->renderTransportAdres($this->marginLeft, $yL, $colW, 'Adres pośredni wysyłki', $adr);
            }
            if (!empty($do)) $yR = $this->renderTransportAdres($rightX, $yR, $colW, 'Adres miejsca docelowego, do którego został zlecony transport', $do);
            $y = max($yL, $yR) + 2;
        }

        return $y + 2;
    }

    /**
     * @brief Render transport carrier
     * @param $y
     * @param $p Carrier data
     * @return float
     */
    private function renderTransportCarrier($y, $p)
    {
        $pdf = $this->pdf;
        $y = $this->checkPageBreak($y, 16);

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 5, 'Przewoźnik', 0, 1, 'L');
        $y += 5;

        $colGap = 6;
        $colW = ($this->contentWidth - $colGap) / 2;
        $rightX = $this->marginLeft + $colW + $colGap;
        $yStart = $y;
        $yL = $yStart;
        $yR = $yStart;

        if (!empty($p['nip'])) $yL = $this->renderLabelValueAt($this->marginLeft, $yL, $colW, 'NIP: ', $p['nip']);
        if (!empty($p['nr_vat_ue'])) $yL = $this->renderLabelValueAt($this->marginLeft, $yL, $colW, 'Numer VAT UE: ', trim(($p['kod_ue'] ?? '') . ' ' . $p['nr_vat_ue']));
        if (!empty($p['nr_id'])) $yL = $this->renderLabelValueAt($this->marginLeft, $yL, $colW, 'Identyfikator podatkowy inny: ', trim(($p['kod_kraju'] ?? '') . ' ' . $p['nr_id']));
        if (($p['brak_id'] ?? '') === '1') $yL = $this->renderLabelValueAt($this->marginLeft, $yL, $colW, 'Brak identyfikatora podatkowego', '');
        if (!empty($p['nazwa'])) $yL = $this->renderLabelValueAt($this->marginLeft, $yL, $colW, 'Nazwa: ', $p['nazwa']);

        if (!empty($p['adres'])) {
            $yR = $this->renderTransportAdres($rightX, $yR, $colW, 'Adres przewoźnika', $p['adres']);
        }

        return max($yL, $yR) + 2;
    }

    /**
     * @brief Render address block
     * @param $x
     * @param $y
     * @param $w
     * @param $title
     * @param $adr Address data
     * @return float
     */
    private function renderTransportAdres($x, $y, $w, $title, $adr)
    {
        $pdf = $this->pdf;
        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
        $pdf->SetXY($x, $y);
        $pdf->MultiCell($w, 4, $title, 0, 'L');
        $y = $pdf->GetY();

        $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
        foreach (array($adr['l1'] ?? '', $adr['l2'] ?? '') as $line) {
            if ($line === '') continue;
            $pdf->SetXY($x, $y);
            $pdf->MultiCell($w, 4, $line, 0, 'L');
            $y = $pdf->GetY();
        }
        if (!empty($adr['kod_kraju'])) {
            $pdf->SetXY($x, $y);
            $pdf->MultiCell($w, 4, $this->getCountryName($adr['kod_kraju']), 0, 'L');
            $y = $pdf->GetY();
        }
        if (!empty($adr['gln'])) {
            $y = $this->renderLabelValueAt($x, $y, $w, 'GLN: ', $adr['gln']);
        }
        return $y;
    }

    /**
     * @brief Transport type name
     * @param $code
     * @return string
     */
    private function getTransportTypeName($code)
    {
        $map = array(
            '1' => 'Transport morski',
            '2' => 'Transport kolejowy',
            '3' => 'Transport drogowy',
            '4' => 'Transport lotniczy',
            '5' => 'Przesyłka pocztowa',
            '7' => 'Stałe instalacje przesyłowe',
            '8' => 'Żegluga śródlądowa',
        );
        return $map[$code] ?? $code;
    }

    /**
     * @brief Cargo type name
     * @param $code
     * @return string
     */
    private function getCargoTypeName($code)
    {
        $map = array(
            '1' => 'Bańka', '2' => 'Beczka', '3' => 'Butla', '4' => 'Karton', '5' => 'Kanister',
            '6' => 'Klatka', '7' => 'Kontener', '8' => 'Kosz/koszyk', '9' => 'Łubianka',
            '10' => 'Opakowanie zbiorcze', '11' => 'Paczka', '12' => 'Pakiet', '13' => 'Paleta',
            '14' => 'Pojemnik', '15' => 'Pojemnik do ładunków masowych stałych',
            '16' => 'Pojemnik do ładunków masowych w postaci płynnej', '17' => 'Pudełko',
            '18' => 'Puszka', '19' => 'Skrzynia', '20' => 'Worek',
        );
        return $map[$code] ?? $code;
    }

    /**
     * @brief Format ISO dateTime
     * @param $value
     * @return string
     */
    private function formatDateTimePolish($value)
    {
        if (empty($value)) return '';
        try {
            $dt = new DateTime($value);
            $dt->setTimezone(new DateTimeZone('Europe/Warsaw'));
            return $dt->format('d.m.Y H:i');
        } catch (Exception $e) {
            $ts = strtotime($value);
            return $ts ? date('d.m.Y H:i', $ts) : $value;
        }
    }

    /**
     * @brief Render registries
     * @param $y
     * @return float
     */
    private function renderRegistries($y)
    {
        $pdf = $this->pdf;
        $registries = $this->parsed['registries'];
        $rows = $registries['rows'] ?? array($registries);

        $keys = array('pelna_nazwa' => 'Pełna nazwa', 'krs' => 'KRS', 'regon' => 'REGON', 'bdo' => 'BDO');
        $cols = array();
        foreach ($keys as $k => $label) {
            foreach ($rows as $r) {
                if (!empty($r[$k])) {
                    $cols[$k] = array('label' => $label, 'w' => 35);
                    break;
                }
            }
        }
        if (empty($cols)) return $y;
        if (isset($cols['pelna_nazwa'])) {
            $cols['pelna_nazwa']['w'] = $this->contentWidth - 35 * (count($cols) - 1);
        }

        $y = $this->checkPageBreak($y, 20);
        $y = $this->drawLine($y);

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 5, 'Rejestry', 0, 1, 'L');
        $y += 6;

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeTable);
        $pdf->SetFillColorArray($this->colorHeaderBg);
        $pdf->SetDrawColorArray($this->colorBorder);
        $pdf->SetLineWidth($this->borderWidth);

        $x = $this->marginLeft;
        foreach ($cols as $col) {
            $pdf->SetXY($x, $y);
            $pdf->Cell($col['w'], 5, $col['label'], 1, 0, 'L', true);
            $x += $col['w'];
        }
        $y += 5;

        $pdf->SetFont($this->fontFamily, '', $this->fontSizeTable);
        foreach ($rows as $r) {
            $rh = 5;
            if (isset($cols['pelna_nazwa']) && !empty($r['pelna_nazwa'])) {
                $rh = max(5, $pdf->getNumLines($r['pelna_nazwa'], $cols['pelna_nazwa']['w'] - 1) * 3.2 + 1);
            }
            $y = $this->checkPageBreak($y, $rh);
            $x = $this->marginLeft;
            foreach ($cols as $k => $col) {
                $pdf->SetXY($x, $y);
                if ($k === 'pelna_nazwa') {
                    $pdf->Cell($col['w'], $rh, '', 1, 0, 'L');
                    $pdf->SetXY($x + 0.5, $y + 0.5);
                    $pdf->MultiCell($col['w'] - 1, 3.2, (string) ($r[$k] ?? ''), 0, 'L', false, 0);
                } else {
                    $pdf->Cell($col['w'], $rh, (string) ($r[$k] ?? ''), 1, 0, 'L');
                }
                $x += $col['w'];
            }
            $y += $rh;
        }

        return $y + 3;
    }

    /**
     * @brief Render settlement
     * @param $incoming KsefIncoming
     * @param $y
     * @return float
     */
    private function renderRozliczenie($incoming, $y)
    {
        $r = $this->parsed['settlement'] ?? null;
        if (empty($r)) return $y;
        $currency = $this->parsed['invoice']['currency'] ?? ($incoming->currency ?: 'PLN');
        $colGap = 6;
        $colW = ($this->contentWidth - $colGap) / 2;
        $rightX = $this->marginLeft + $colW + $colGap;
        $hasCharges = !empty($r['charges']);
        $hasDeductions = !empty($r['deductions']);

        $draw = function ($y0) use ($r, $colW, $rightX, $hasCharges, $hasDeductions) {
            $yl = $y0;
            $yr = $y0;
            if ($hasCharges) {
                $yl = $this->renderSettlementTable($this->marginLeft, $y0, $colW, 'Obciążenia', 'Powód obciążenia', $r['charges'], 'Suma kwot obciążenia: ', $r['total_charges']);
            }
            if ($hasDeductions) {
                $yr = $this->renderSettlementTable($rightX, $y0, $colW, 'Odliczenia', 'Powód odliczenia', $r['deductions'], 'Suma kwot odliczenia: ', $r['total_deductions']);
            }
            return max($yl, $yr);
        };
        $hTables = ($hasCharges || $hasDeductions) ? $this->measureBlock($draw) : 0;

        $y = $this->checkPageBreak($y, $this->spaceLineAfter + 6 + min($hTables, 60) + 8);
        $y = $this->drawLine($y);
        $pdf = $this->pdf;
        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 5, 'Rozliczenie', 0, 1, 'L');
        $y += 6;

        if ($hTables > 0) {
            if ($y + $hTables <= $this->pageHeight - $this->marginBottom) {
                $y = $draw($y);
            } else {
                if ($hasCharges) {
                    $y = $this->renderSettlementTable($this->marginLeft, $y, $this->contentWidth, 'Obciążenia', 'Powód obciążenia', $r['charges'], 'Suma kwot obciążenia: ', $r['total_charges'], true);
                }
                if ($hasDeductions) {
                    $y = $this->renderSettlementTable($this->marginLeft, $y + 2, $this->contentWidth, 'Odliczenia', 'Powód odliczenia', $r['deductions'], 'Suma kwot odliczenia: ', $r['total_deductions'], true);
                }
            }
        }

        $final = null;
        if ($r['to_pay'] !== null && $r['to_pay'] !== '') {
            $final = array('Do zapłaty: ', $r['to_pay']);
        } elseif ($r['to_settle'] !== null && $r['to_settle'] !== '') {
            $final = array('Do rozliczenia: ', $r['to_settle']);
        }
        if ($final !== null) {
            $y = $this->checkPageBreak($y + 2, 7);
            $pdf = $this->pdf;
            $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
            $pdf->SetXY($this->marginLeft, $y);
            $pdf->Cell($this->contentWidth, 5, $final[0] . $this->formatMoney($final[1]) . ' ' . $currency, 0, 1, 'R');
            $y += 6;
        }
        return $y + 2;
    }

    /**
     * @brief Render settlement table
     * @param $x
     * @param $y
     * @param $w
     * @param $title
     * @param $reasonHeader
     * @param $items
     * @param $sumLabel
     * @param $sum
     * @param $breaks Break per row
     * @return float
     */
    private function renderSettlementTable($x, $y, $w, $title, $reasonHeader, $items, $sumLabel, $sum, $breaks = false)
    {
        $pdf = $this->pdf;
        $amountW = 30;
        $reasonW = $w - $amountW;

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
        $pdf->SetXY($x, $y);
        $pdf->Cell($w, 5, $title, 0, 1, 'L');
        $y += 5;

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeTable);
        $pdf->SetFillColorArray($this->colorHeaderBg);
        $pdf->SetDrawColorArray($this->colorBorder);
        $pdf->SetLineWidth($this->borderWidth);
        $pdf->SetXY($x, $y);
        $pdf->Cell($reasonW, 5, $reasonHeader, 1, 0, 'L', true);
        $pdf->Cell($amountW, 5, 'Kwota', 1, 0, 'L', true);
        $y += 5;

        $pdf->SetFont($this->fontFamily, '', $this->fontSizeTable);
        foreach ($items as $it) {
            $reason = (string) ($it['reason'] ?? '');
            $rh = max(5, $pdf->getNumLines($reason, $reasonW - 1) * 3.2 + 1);
            if ($breaks) {
                $y = $this->checkPageBreak($y, $rh);
                $pdf = $this->pdf;
                $pdf->SetFont($this->fontFamily, '', $this->fontSizeTable);
            }
            $pdf->SetXY($x, $y);
            $pdf->Cell($reasonW, $rh, '', 1, 0, 'L');
            $pdf->SetXY($x + 0.5, $y + 0.5);
            $pdf->MultiCell($reasonW - 1, 3.2, $reason, 0, 'L', false, 0);
            $pdf->SetXY($x + $reasonW, $y);
            $pdf->Cell($amountW, $rh, $this->formatMoney($it['amount'] ?? null), 1, 0, 'R');
            $y += $rh;
        }

        if ($sum !== null && $sum !== '') {
            $pdf->SetXY($x, $y + 1);
            $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
            $label = $sumLabel;
            $value = $this->formatMoney($sum);
            $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
            $vw = $pdf->GetStringWidth($value) + 2;
            $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
            $pdf->Cell($w - $vw, 4, $label, 0, 0, 'R');
            $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
            $pdf->Cell($vw, 4, $value, 0, 1, 'R');
            $y += 5;
        }
        return $y;
    }

    /**
     * @brief Render WZ numbers
     * @param $y
     * @return float
     */
    private function renderWz($y)
    {
        $wz = $this->parsed['invoice']['wz'] ?? array();
        if (empty($wz)) return $y;
        $colW = ($this->contentWidth - 6) / 2;
        $y = $this->checkPageBreak($y, $this->spaceLineAfter + 15);
        $y = $this->drawLine($y);
        $this->pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
        $this->pdf->SetXY($this->marginLeft, $y);
        $this->pdf->Cell($colW, 5, 'Numery dokumentów magazynowych WZ', 0, 1, 'L');
        $y += 6;
        $rows = array();
        foreach ($wz as $nr) {
            $rows[] = array($nr);
        }
        return $this->renderWrapTable($this->marginLeft, $y, $colW, array('Numer WZ'), $rows, array('L'), true) + 2;
    }

    /**
     * @brief Render Stopka info
     * @param $y
     * @return float
     */
    private function renderStopkaInfo($y)
    {
        $stopkaItems = $this->parsed['stopka'];
        if (empty($stopkaItems)) {
            return $y;
        }

        $pdf = $this->pdf;
        $y = $this->checkPageBreak($y, $this->spaceLineAfter + 5 + $this->spaceSectionAfter + 5 + 12);
        $y = $this->drawLine($y);

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 5, 'Pozostałe informacje', 0, 1, 'L');
        $y += 5 + $this->spaceSectionAfter;

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
        $pdf->SetFillColorArray($this->colorHeaderBg);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 5, 'Stopka faktury', 1, 1, 'L', true);
        $y += 5;

        $entries = is_array($stopkaItems) ? $stopkaItems : array($stopkaItems);
        foreach ($entries as $entry) {
            $y = $this->checkPageBreak($y, 8);
            $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
            $y = $this->flowMultiCell($this->marginLeft, $y, $this->contentWidth, 4, $entry, 1);
        }
        $y += $this->spaceSectionAfter;

        return $y;
    }

    /**
     * @brief Render QR codes
     * @param $incoming KsefIncoming
     * @param $y
     * @return float
     */
    private function renderQrCode($incoming, $y)
    {
        $pdf = $this->pdf;

        if (!empty($incoming->offline_mode)) {
            return $this->renderQrCodeOffline($incoming, $y);
        }

        $verificationUrl = $this->getVerificationUrl($incoming);
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

        if (!empty($verificationUrl)) {
            try {
                $pdf->write2DBarcode($verificationUrl, 'QRCODE,M', $this->marginLeft, $y, $qrSize, $qrSize);
            } catch (Exception $e) {
                dol_syslog("KsefInvoicePdf::renderQrCode QR error: " . $e->getMessage(), LOG_WARNING);
            }
        }

        if (!empty($incoming->ksef_number)) {
            $this->renderKsefNumberUnderQr($this->marginLeft, $y + $qrSize, $qrSize, $incoming->ksef_number);
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
     * @brief Print KSeF number
     * @param $x
     * @param $qrBottom QR bottom Y
     * @param $qrSize QR side
     * @param $ksefNum
     * @return void
     */
    private function renderKsefNumberUnderQr($x, $qrBottom, $qrSize, $ksefNum)
    {
        $pdf = $this->pdf;
        $pdf->SetFont($this->fontFamily, '', 5);
        if (strlen($ksefNum) > 35) {
            $pdf->SetXY($x, $qrBottom + 1);
            $pdf->Cell($qrSize, 2.5, substr($ksefNum, 0, 35), 0, 1, 'C');
            $pdf->SetXY($x, $qrBottom + 3.5);
            $pdf->Cell($qrSize, 2.5, substr($ksefNum, 35), 0, 1, 'C');
        } else {
            $pdf->SetXY($x, $qrBottom + 1);
            $pdf->Cell($qrSize, 3, $ksefNum, 0, 1, 'C');
        }
    }

    /**
     * @brief Offline QR codes
     * @param $incoming
     * @param $y
     * @return float
     */
    private function renderQrCodeOffline($incoming, $y)
    {
        global $mysoc;

        dol_include_once('/ksef/lib/ksef.lib.php');

        $pdf = $this->pdf;

        $y = $this->checkPageBreak($y, 60);
        $y = $this->drawLine($y);

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 5, 'Sprawdź, czy Twoja faktura znajduje się w KSeF!', 0, 1, 'L');
        $y += 6;

        $qrSize = 35;
        $spacing = 6;

        $sellerNip = !empty($incoming->seller_nip) ? $incoming->seller_nip : getDolGlobalString('KSEF_COMPANY_NIP');
        if (empty($sellerNip) && is_object($mysoc)) {
            $sellerNip = ksefGetIdentifierField($mysoc, 'NIP');
        }
        $codes = ksefQrCodesForSubmission($incoming, (string) $sellerNip, $incoming->invoice_date ?? null);
        $qr1X = $this->marginLeft;
        $qr1Url = $codes['kod1'];

        if (!empty($qr1Url)) {
            try {
                $pdf->write2DBarcode($qr1Url, 'QRCODE,H', $qr1X, $y, $qrSize, $qrSize);
            } catch (Exception $e) {
                dol_syslog("KsefInvoicePdf::renderQrCodeOffline QR OFFLINE error: " . $e->getMessage(), LOG_WARNING);
            }
        }
        $pdf->SetTextColorArray($this->colorText);
        if ($codes['number'] !== '') {
            $this->renderKsefNumberUnderQr($qr1X, $y + $qrSize, $qrSize, $codes['number']);
        } else {
            $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
            $pdf->SetXY($qr1X, $y + $qrSize + 1);
            $pdf->Cell($qrSize, 3, 'OFFLINE', 0, 0, 'C');
        }

        $qr2X = $qr1X + $qrSize + $spacing;
        $qr2Url = $codes['kod2'];

        $qr2Rendered = false;
        if (!empty($qr2Url)) {
            try {
                $pdf->write2DBarcode($qr2Url, 'QRCODE,H', $qr2X, $y, $qrSize, $qrSize);
                $qr2Rendered = true;
            } catch (Exception $e) {
                dol_syslog("KsefInvoicePdf::renderQrCodeOffline QR CERTYFIKAT error: " . $e->getMessage(), LOG_WARNING);
            }
        }
        if (!$qr2Rendered) {
            $pdf->SetDrawColorArray(array(200, 200, 200));
            $pdf->SetFillColorArray(array(248, 248, 248));
            $pdf->Rect($qr2X, $y, $qrSize, $qrSize, 'DF');
            $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
            $pdf->SetTextColor(150, 150, 150);
            $pdf->SetXY($qr2X, $y + ($qrSize / 2) - 2);
            $pdf->Cell($qrSize, 3, 'Brak certyfikatu', 0, 1, 'C');
            $pdf->SetDrawColorArray($this->colorLine);
        }
        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
        $pdf->SetTextColorArray($this->colorText);
        $pdf->SetXY($qr2X, $y + $qrSize + 1);
        $pdf->Cell($qrSize, 3, 'CERTYFIKAT', 0, 0, 'C');
        $textX = $qr2X + $qrSize + 5;
        $textWidth = $this->contentWidth - ($textX - $this->marginLeft);
        if ($textWidth > 20) {
            $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
            $pdf->SetXY($textX, $y);
            $pdf->Cell($textWidth, 4, 'Nie możesz zeskanować kodu z obrazka? Kliknij w link weryfikacyjny i przejdź do weryfikacji faktury!', 0, 1, 'L');

            if (!empty($qr1Url)) {
                $pdf->SetFont($this->fontFamily, '', 5);
                $pdf->SetTextColorArray($this->colorLink);
                $pdf->SetXY($textX, $pdf->GetY() + 1);
                $pdf->MultiCell($textWidth, 3, $qr1Url, 0, 'L');
                $pdf->SetTextColorArray($this->colorText);
            }
        }

        return $y + $qrSize + 10;
    }

    /**
     * @brief Render footer
     * @param $pageNum
     * @param $totalPages
     * @return void
     */
    private function renderFooter($pageNum = 0, $totalPages = 0)
    {
        $pdf = $this->pdf;
        $footerY = $this->pageHeight - $this->marginBottom + 2;

        $pdf->SetDrawColorArray($this->colorLine);
        $pdf->SetLineWidth(0.3);
        $pdf->Line($this->marginLeft, $footerY - 4, $this->marginLeft + $this->contentWidth, $footerY - 4);

        $pdf->SetFont($this->fontFamily, '', $this->fontSizeTable);

        $systemInfo = $this->parsed['header']['system_info'] ?? null;
        if (!empty($systemInfo)) {
            $pdf->SetXY($this->marginLeft, $footerY - 3);
            $pdf->Cell($this->contentWidth, 3, 'Wytworzona w:' . $systemInfo, 0, 1, 'L');
        }

        $pdf->SetXY($this->marginLeft, $footerY);
        $pdf->SetFont($this->fontFamily, '', $this->fontSizeTable);
        $pdf->Cell($this->contentWidth, 3, 'Wizualizacja danych XML wygenerowana przez Dolibarr z użyciem TCPDF', 0, 0, 'L');

        if ($pageNum > 0 && $totalPages > 0) {
            $pdf->SetXY($this->marginLeft, $footerY);
            $pdf->Cell($this->contentWidth, 3, $pageNum . ' z ' . $totalPages, 0, 0, 'R');
        }
    }

    /**
     * @brief Any line field
     * @param $lines
     * @param $field
     * @return bool
     */
    private function hasAnyField($lines, $field)
    {
        foreach ($lines as $line) {
            if (!empty($line[$field])) return true;
        }
        return false;
    }

    /**
     * @brief Digits-only NIP
     * @param $nip
     * @return string
     */
    private function formatNIP($nip)
    {
        return preg_replace('/[^0-9]/', '', $nip);
    }

    /**
     * @brief Format date d.m.Y
     * @param $date Timestamp or string
     * @return string
     */
    private function formatDatePolish($date)
    {
        if (empty($date)) return '';
        if (is_numeric($date)) {
            return date('d.m.Y', $date);
        }
        $ts = strtotime($date);
        return $ts ? date('d.m.Y', $ts) : $date;
    }

    /**
     * @brief Format money
     * @param $value
     * @return string
     */
    private function formatMoney($value)
    {
        if ($value === null || $value === '') return '';
        return number_format((float)$value, 2, ',', ' ');
    }

    /**
     * @brief Format percentage
     * @param $value
     * @return string
     */
    private function formatPercent($value)
    {
        if ($value === null || $value === '') return '';
        return rtrim(rtrim(number_format((float) str_replace(',', '.', (string) $value), 2, ',', ''), '0'), ',') . '%';
    }

    /**
     * @brief Format quantity
     * @param $value
     * @return string
     */
    private function formatQuantity($value)
    {
        return $this->formatDecimalTrim($value, 6, 0);
    }

    /**
     * @brief Format trimmed decimal
     * @param $value
     * @param $maxDp XSD fractionDigits
     * @param $minDp Minimum decimals
     * @return string
     */
    private function formatDecimalTrim($value, $maxDp, $minDp = 2)
    {
        if ($value === null || $value === '') return '';
        $s = number_format((float) $value, $maxDp, '.', '');
        $sign = '';
        if ($s[0] === '-') {
            $sign = '-';
            $s = substr($s, 1);
        }
        $parts = explode('.', $s);
        $int = $parts[0];
        $frac = rtrim(isset($parts[1]) ? $parts[1] : '', '0');
        if (strlen($frac) < $minDp) $frac = str_pad($frac, $minDp, '0');
        if ($int === '0' && trim($frac, '0') === '') $sign = '';
        $int = strrev(implode(' ', str_split(strrev($int), 3)));
        return $sign . $int . ($frac !== '' ? ',' . $frac : '');
    }

    /**
     * @brief Polish country name
     * @param $code
     * @return string
     */
    private function getCountryName($code)
    {
        $code = strtoupper(trim($code));
        return $this->countryNames[$code] ?? $code;
    }

    /**
     * @brief Split address lines
     * @param $address
     * @return array
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
     * @brief Invoice type label
     * @param $type
     * @return string
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
     * @brief Correction type label
     * @param $type
     * @return string
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
     * @brief VAT rate label
     * @param $rate
     * @param $short Short form
     * @return string
     */
    private function getVatRateLabel($rate, $short = false)
    {
        if ($short) {
            $labels = array(
                '23' => '23%', '22' => '22%', '8' => '8%', '7' => '7%',
                '5' => '5%', '4' => '4%', '3' => '3%', 'oss' => 'OSS', '0' => '0%',
                '0 KR' => '0% KR', '0 WDT' => '0% WDT', '0 EX' => '0% EX',
                'zw' => 'zw', 'np' => 'np', 'np I' => 'np I', 'np II' => 'np II',
                'oo' => 'oo', 'marza' => 'marża',
            );
        } else {
            $labels = array(
                '23' => '23% lub 22%', '22' => '23% lub 22%', '8' => '8% lub 7%', '7' => '8% lub 7%',
                '5' => '5%',
                '4' => '4% lub 3%', '3' => '4% lub 3%',
                'oss' => 'OSS',
                '0' => '0%',
                '0 KR' => '0% w przypadku sprzedaży towarów i świadczenia usług na terytorium kraju (z wyłączeniem WDT i eksportu)',
                '0 WDT' => '0% w przypadku wewnątrzwspólnotowej dostawy towarów (WDT)',
                '0 EX' => '0% w przypadku eksportu towarów',
                'zw' => 'zwolnione od podatku',
                'np' => 'np',
                'np I' => 'np z wyłączeniem art. 100 ust 1 pkt 4 ustawy',
                'np II' => 'np na podstawie art. 100 ust. 1 pkt 4 ustawy',
                'oo' => 'odwrotne obciążenie',
                'marza' => 'marża',
            );
        }
        return $labels[$rate] ?? $rate;
    }

    /**
     * @brief Payment method label
     * @param $method
     * @return string
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
     * @brief KSeF verification URL
     * @param $incoming KsefIncoming
     * @return string URL or ''
     */
    private function getVerificationUrl($incoming)
    {
        if (!empty($incoming->fa3_xml) && !empty($incoming->ksef_number)) {
            dol_include_once('/ksef/lib/ksef.lib.php');
            try {
                return ksefGetVerificationURL($incoming->ksef_number, ksefSubmissionHash($incoming), !empty($incoming->environment) ? $incoming->environment : null, null, null, $incoming->fa3_xml);
            } catch (Exception $e) {
                dol_syslog("KsefInvoicePdf::getVerificationUrl: " . $e->getMessage(), LOG_WARNING);
            }
        }

        return '';
    }

    /**
     * @brief Empty parsed structure
     * @return array
     */
    private function getEmptyParsed()
    {
        return array(
            'header' => array('creation_date' => null, 'system_info' => null),
            'seller' => array('nip' => '', 'name' => '', 'country' => 'PL', 'address' => '', 'email' => null, 'phone' => null),
            'buyer' => array('nip' => '', 'name' => '', 'country' => 'PL', 'address' => '', 'jst' => null, 'gv' => null, 'email' => null, 'phone' => null, 'customer_number' => null, 'kod_ue' => null, 'nr_vat_ue' => null),
            'buyer_before' => null,
            'third_parties' => array(),
            'invoice' => array('currency' => 'PLN', 'number' => '', 'type' => 'VAT', 'date' => null, 'sale_date' => null, 'total_net' => 0, 'total_vat' => 0, 'total_gross' => 0, 'place_of_issue' => null, 'period_from' => null, 'period_to' => null, 'fp_flag' => false, 'p_16_flag' => false, 'p_17_flag' => false, 'p_18_flag' => false, 'mpp_flag' => false, 'p_23_flag' => false, 'tp_flag' => false, 'p_19_flag' => false, 'p_19a' => null, 'p_19b' => null, 'p_19c' => null, 'pmarzy_flag' => false, 'pmarzy_subtype' => null, 'total_amount' => null, 'wz' => array()),
            'vat_summary' => array(),
            'lines' => array(),
            'lines_before' => array(),
            'payment' => array('due_date' => null, 'due_date_earliest' => null, 'due_date_description' => null, 'due_dates' => array(), 'method' => null, 'method_other' => false, 'method_description' => null, 'bank_account' => null, 'status' => 'unpaid', 'payment_date' => null, 'bank_swift' => null, 'bank_name' => null, 'bank_own_account' => null, 'bank_description' => null, 'bank_accounts' => array(), 'factor_bank_accounts' => array(), 'discount' => null, 'payment_link' => null, 'ipksef' => null),
            'correction' => null,
            'registries' => array('krs' => null, 'regon' => null, 'bdo' => null, 'pelna_nazwa' => null, 'rows' => array()),
            'settlement' => null,
            'stopka' => array(),
            'additional_info' => array(),
            'additional_desc' => array(),
            'exchange_rate' => array('rate' => null),
            'warunki' => array('umowy' => array(), 'zamowienia' => array(), 'partie' => array(), 'waluta_umowna' => null, 'kurs_umowny' => null, 'warunki_dostawy' => null, 'podmiot_posredniczacy' => false, 'transport' => array()),
            'lines_source' => 'fawiersz',
            'deposits' => array(),
            'partial_payments' => array(),
            'order' => array('value' => null, 'lines' => array(), 'lines_before' => array()),
        );
    }
}