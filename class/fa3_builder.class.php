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
 * \file    ksef/class/fa3_builder.class.php
 * \ingroup ksef
 * \brief   FA(3) XML Generator
 */

require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';

class FA3Builder
{
    private $db;
    public $error;
    public $errors = array();

    private $sellerName;
    private $buyerName;
    private $lastXmlHash;
    private $lastCreationDate;
    private $currentInvoiceCurrency;
    private $currentKursWaluty;
    private $currentCustomerCountry;
    private $currentCustomerIsEU;

    public function __construct($db)
    {
        $this->db = $db;
    }


    /**
     * @brief Builds FA(3) XML from Dolibarr invoice
     * @param int $invoice_id Invoice ID
     * @param array $options Optional settings:
     *   - offline_mode: bool - Whether invoice is being submitted in offline mode
     *   - include_attachments: bool - Attachments
     * @return string|false XML string or false on error
     * @called_by KSEF::generateFA3XML(), KSEF::submitInvoice(), KSEF::submitInvoiceOffline()
     * @calls buildNaglowek(), buildPodmiot1(), buildPodmiot2(), buildFa()
     */
    public function buildFromInvoice($invoice_id, $options = array())
    {
        global $conf, $mysoc;

        // Reset hash
        $this->lastXmlHash = null;
        $this->lastCreationDate = null;

        try {
            $invoice = new Facture($this->db);
            if ($invoice->fetch($invoice_id) <= 0) {
                $this->error = "Invoice not found: $invoice_id";
                return false;
            }
            $invoice->fetch_lines();

            $customer = new Societe($this->db);
            if ($customer->fetch($invoice->socid) <= 0) {
                $this->error = "Customer not found: " . $invoice->socid;
                return false;
            }

            // For corrective invoices, load original
            $originalInvoice = null;
            if ($invoice->type == Facture::TYPE_CREDIT_NOTE && !empty($invoice->fk_facture_source)) {
                $originalInvoice = new Facture($this->db);
                if ($originalInvoice->fetch($invoice->fk_facture_source) <= 0) {
                    $this->error = "Original invoice not found: " . $invoice->fk_facture_source;
                    return false;
                }
                $originalInvoice->fetch_lines();
            }

            $xml = new DOMDocument('1.0', 'UTF-8');
            $xml->formatOutput = true;

            $faktura = $xml->createElementNS(KSEF_FA3_NAMESPACE, 'Faktura');
            $xml->appendChild($faktura);

            if (!empty($options['original_creation_date'])) {
                $this->lastCreationDate = $options['original_creation_date'];
            } else {
                $this->lastCreationDate = dol_now();
            }

            $this->buildNaglowek($xml, $faktura, $invoice, $options);
            $this->buildPodmiot1($xml, $faktura, $mysoc, $customer);
            $this->buildPodmiot2($xml, $faktura, $customer);
            $this->buildFa($xml, $faktura, $invoice, $originalInvoice);
            $this->buildStopka($xml, $faktura);

            $xmlString = $xml->saveXML();

            $this->lastXmlHash = base64_encode(hash('sha256', $xmlString, true));

            return $xmlString;

        } catch (Exception $e) {
            $this->error = "FA(3) generation error: " . $e->getMessage();
            $this->errors[] = $this->error;
            dol_syslog("FA3Builder::buildFromInvoice ERROR: " . $this->error, LOG_ERR);
            return false;
        }
    }

    /**
     * @brief Gets creation date from last built XML
     * @return int|null Unix timestamp of FA3 creation date
     * @called_by KSEF::submitInvoice(), KSEF::submitInvoiceOffline()
     */
    public function getLastCreationDate()
    {
        return $this->lastCreationDate;
    }

    /**
     * @brief Gets hash of last built XML
     * @return string|null Base64-encoded SHA-256 hash
     */
    public function getLastXmlHash()
    {
        return $this->lastXmlHash;
    }

    /**
     * @brief Builds header section of FA(3)
     * @param DOMDocument $xml
     * @param DOMElement $parent Parent element
     * @param Facture $invoice Invoice object
     * @param array $options Build options
     * @called_by buildFromInvoice()
     */
    private function buildNaglowek($xml, $parent, $invoice, $options = array())
    {
        $naglowek = $xml->createElement('Naglowek');
        $parent->appendChild($naglowek);

        // Form code
        $kodFormularza = $xml->createElement('KodFormularza', 'FA');
        $kodFormularza->setAttribute('kodSystemowy', 'FA (3)');
        $kodFormularza->setAttribute('wersjaSchemy', KSEF_FA3_SCHEMA_VERSION);
        $naglowek->appendChild($kodFormularza);

        // Form variant
        $naglowek->appendChild($xml->createElement('WariantFormularza', '3'));

        if (!empty($options['original_creation_date'])) {
            if (is_numeric($options['original_creation_date'])) {
                $dateCreation = date('Y-m-d\TH:i:sP', $options['original_creation_date']);
            } else {
                $dateCreation = $options['original_creation_date'];
            }
        } else {
            $dateCreation = date('Y-m-d\TH:i:sP');
        }
        $naglowek->appendChild($xml->createElement('DataWytworzeniaFa', $dateCreation));

        // System identification
        $systemInfo = 'Dolibarr ERP ' . DOL_VERSION;
        if (!empty($options['offline_mode'])) {
            $systemInfo .= ' (offline)';
        }
        $naglowek->appendChild($xml->createElement('SystemInfo', $systemInfo));
    }

    /**
     * @brief Builds seller (Podmiot1) section
     * @param $xml DOMDocument
     * @param $parent Parent element
     * @param $mysoc Company object
     * @param $customer Customer object
     * @called_by buildFromInvoice()
     * @calls ksefCleanNIP(), xmlSafe()
     */
    private function buildPodmiot1($xml, $parent, $mysoc, $customer)
    {
        global $conf;

        // Seller
        $podmiot1 = $xml->createElement('Podmiot1');
        $parent->appendChild($podmiot1);

        // Check if buyer is not PL
        $buyerCountry = !empty($customer->country_code) ? strtoupper($customer->country_code) : 'PL';
        $isForeignBuyer = ($buyerCountry != 'PL');

        // PrefiksPodatnika - VAT prefix if buyer is foreign AND seller has valid EU VAT ID in tva_intra
        if ($isForeignBuyer && !empty($mysoc->tva_intra)) {
            $vatIntra = strtoupper(trim($mysoc->tva_intra));
            if (preg_match('/^([A-Z]{2})/', $vatIntra, $matches)) {
                $vatPrefix = $matches[1];
                $podmiot1->appendChild($xml->createElement('PrefiksPodatnika', $vatPrefix));
            }
        }

        // Identification
        $daneIdent = $xml->createElement('DaneIdentyfikacyjne');
        $podmiot1->appendChild($daneIdent);

        $nip = '';
        if (!empty($conf->global->KSEF_COMPANY_NIP)) {
            $nip = ksefCleanNIP($conf->global->KSEF_COMPANY_NIP);
        }
        if (empty($nip)) {
            $nip = ksefCleanNIP($mysoc->idprof1);
        }
        if (empty($nip)) {
            throw new Exception("Seller NIP is required - configure KSEF");
        }

        $this->sellerName = $this->xmlSafe($mysoc->name);
        $daneIdent->appendChild($xml->createElement('NIP', $nip));
        $daneIdent->appendChild($xml->createElement('Nazwa', $this->sellerName));

        // Address
        $adres = $xml->createElement('Adres');
        $podmiot1->appendChild($adres);

        $adres->appendChild($xml->createElement('KodKraju', $mysoc->country_code ?: 'PL'));

        // AdresL1: Street address
        $address = !empty($mysoc->address) ? $this->xmlSafe($mysoc->address) : 'ul. Testowa 1';
        $adres->appendChild($xml->createElement('AdresL1', $address));

        // AdresL2: Postal code + city
        $adresL2Parts = array();
        if (!empty($mysoc->zip)) {
            $adresL2Parts[] = $this->xmlSafe($mysoc->zip);
        }
        if (!empty($mysoc->town)) {
            $adresL2Parts[] = $this->xmlSafe($mysoc->town);
        }
        if (!empty($adresL2Parts)) {
            $adres->appendChild($xml->createElement('AdresL2', implode(' ', $adresL2Parts)));
        }

        // DaneKontaktowe: Contact information
        if (!empty($mysoc->email) || !empty($mysoc->phone)) {
            $daneKontakt = $xml->createElement('DaneKontaktowe');
            $podmiot1->appendChild($daneKontakt);

            if (!empty($mysoc->email)) {
                $daneKontakt->appendChild($xml->createElement('Email', $this->xmlSafe($mysoc->email)));
            }
            if (!empty($mysoc->phone)) {
                $daneKontakt->appendChild($xml->createElement('Telefon', $this->xmlSafe($mysoc->phone)));
            }
        }
    }

    /**
     * @brief Builds buyer (Podmiot2) section
     * @param $xml DOMDocument
     * @param $parent Parent element
     * @param $customer Customer object
     * @called_by buildFromInvoice()
     * @calls ksefCleanNIP(), xmlSafe(), getEntityFlags(), isEUCountry()
     */
    private function buildPodmiot2($xml, $parent, $customer)
    {
        global $conf;

        // Buyer
        $podmiot2 = $xml->createElement('Podmiot2');
        $parent->appendChild($podmiot2);

        $daneIdent = $xml->createElement('DaneIdentyfikacyjne');
        $podmiot2->appendChild($daneIdent);

        $countryCode = $customer->country_code ?: 'PL';
        $isPolish = ($countryCode === 'PL');
        $isEU = $this->isEUCountry($countryCode);

        $nip = ksefCleanNIP($customer->idprof1);
        if (empty($nip)) {
            $nip = ksefCleanNIP($customer->tva_intra);
        }

        if ($isPolish && !empty($nip)) {
            $daneIdent->appendChild($xml->createElement('NIP', $nip));
        } elseif ($isEU && !empty($customer->tva_intra)) {
            $vatNumber = ksefCleanNIP($customer->tva_intra);
            if (strlen($vatNumber) > 2 && preg_match('/^[A-Z]{2}/', $vatNumber)) {
                $vatNumber = substr($vatNumber, 2);
            }
            $daneIdent->appendChild($xml->createElement('KodUE', $countryCode));
            if (!empty($vatNumber)) {
                $daneIdent->appendChild($xml->createElement('NrVatUE', $vatNumber));
            }
        } elseif (!$isPolish && !empty($customer->idprof1)) {
            $daneIdent->appendChild($xml->createElement('KodKraju', $countryCode));
            $daneIdent->appendChild($xml->createElement('NrID', $this->xmlSafe($customer->idprof1)));
        } else {
            $daneIdent->appendChild($xml->createElement('BrakID', '1'));
        }

        $this->buyerName = $this->xmlSafe($customer->name);
        $daneIdent->appendChild($xml->createElement('Nazwa', $this->buyerName));

        // Address
        $adres = $xml->createElement('Adres');
        $podmiot2->appendChild($adres);

        $adres->appendChild($xml->createElement('KodKraju', $countryCode));

        // AdresL1: Street address
        $address = !empty($customer->address) ? $this->xmlSafe($customer->address) : 'Brak danych';
        $adres->appendChild($xml->createElement('AdresL1', $address));

        // AdresL2: Postal code + city
        $adresL2Parts = array();
        if (!empty($customer->zip)) {
            $adresL2Parts[] = $this->xmlSafe($customer->zip);
        }
        if (!empty($customer->town)) {
            $adresL2Parts[] = $this->xmlSafe($customer->town);
        }
        if (!empty($adresL2Parts)) {
            $adres->appendChild($xml->createElement('AdresL2', implode(' ', $adresL2Parts)));
        }

        // DaneKontaktowe: Contact information
        if (!empty($customer->email) || !empty($customer->phone)) {
            $daneKontakt = $xml->createElement('DaneKontaktowe');
            $podmiot2->appendChild($daneKontakt);

            if (!empty($customer->email)) {
                $daneKontakt->appendChild($xml->createElement('Email', $this->xmlSafe($customer->email)));
            }
            if (!empty($customer->phone)) {
                $daneKontakt->appendChild($xml->createElement('Telefon', $this->xmlSafe($customer->phone)));
            }
        }

        // NrKlienta: Customer code from Dolibarr
        if (!empty($conf->global->KSEF_FA3_INCLUDE_NRKLIENTA) && !empty($customer->code_client)) {
            $podmiot2->appendChild($xml->createElement('NrKlienta', $this->xmlSafe($customer->code_client)));
        }

        $this->currentCustomerCountry = $countryCode;
        $this->currentCustomerIsEU = $isEU;

        $flags = $this->getEntityFlags($customer);
        $podmiot2->appendChild($xml->createElement('JST', $flags['jst'])); // Local Gov Unit flag
        $podmiot2->appendChild($xml->createElement('GV', $flags['gv']));   // VAT Group flag
    }

    /**
     * @brief Builds invoice data (Fa) section
     * @param $xml DOMDocument
     * @param $parent Parent element
     * @param $invoice Invoice object
     * @param $originalInvoice Original invoice for corrections
     * @called_by buildFromInvoice()
     * @calls calculateVatSummary(), getInvoiceType(), buildDaneFaKorygowanej(), buildAdnotacje(), buildFaWiersz(), buildPlatnosc()
     */
    private function buildFa($xml, $parent, $invoice, $originalInvoice = null)
    {
        global $conf, $mysoc;

        $fa = $xml->createElement('Fa');
        $parent->appendChild($fa);

        $invoiceType = $this->getInvoiceType($invoice);

        dol_include_once('/ksef/lib/ksef.lib.php');

        $invoiceCurrency = ksefGetInvoiceCurrency($invoice);

        $kursWaluty = null;
        if ($invoiceCurrency != 'PLN') {
            if (!isset($invoice->array_options) || empty($invoice->array_options)) {
                $invoice->fetch_optionals();
            }

            $dolibarrRate = $invoice->multicurrency_tx ?? 0;
            if ($dolibarrRate <= 0) {
                throw new Exception("Brak kursu NBP dla faktury w walucie obcej ($invoiceCurrency). Pobierz kurs przed wysłaniem do KSeF.");
            }

            // Invert Dolibarr rate to get KSeF rate
            $kursWaluty = 1 / $dolibarrRate;
        }
        $this->currentInvoiceCurrency = $invoiceCurrency;
        $this->currentKursWaluty = $kursWaluty;
        $useMulticurrency = ($invoiceCurrency != 'PLN');
        $fa->appendChild($xml->createElement('KodWaluty', $invoiceCurrency));

        // P_1: Invoice Date
        $fa->appendChild($xml->createElement('P_1', dol_print_date($invoice->date, '%Y-%m-%d')));

        // P_1M: Place of issue
        $placeOfIssueMode = $conf->global->KSEF_FA3_PLACE_OF_ISSUE_MODE ?? 'disabled';
        if ($placeOfIssueMode != 'disabled') {
            $placeOfIssue = '';
            if ($placeOfIssueMode == 'custom' && !empty($conf->global->KSEF_FA3_PLACE_OF_ISSUE_CUSTOM)) {
                $placeOfIssue = $conf->global->KSEF_FA3_PLACE_OF_ISSUE_CUSTOM;
            } elseif ($placeOfIssueMode == 'company' && !empty($mysoc->town)) {
                $placeOfIssue = $mysoc->town;
            }
            if (!empty($placeOfIssue)) {
                $fa->appendChild($xml->createElement('P_1M', $this->xmlSafe($placeOfIssue)));
            }
        }

        // P_2: Invoice Number
        $fa->appendChild($xml->createElement('P_2', $this->xmlSafe($invoice->ref)));
        // P_6: Sale Date
        $deliveryDate = !empty($invoice->date_livraison) ? $invoice->date_livraison : $invoice->date;
        $fa->appendChild($xml->createElement('P_6', dol_print_date($deliveryDate, '%Y-%m-%d')));

        // Net/Tax Amounts (P_13_* / P_14_*) - in invoice currency!!!!
        $vatSummary = $this->calculateVatSummary($invoice, $useMulticurrency);

        // P_13_x: Net amounts by VAT rate
        if (isset($vatSummary['23']) || isset($vatSummary['22'])) {
            $fa->appendChild($xml->createElement('P_13_1', number_format($vatSummary['23']['net'] ?? $vatSummary['22']['net'], 2, '.', '')));
        }
        if (isset($vatSummary['8']) || isset($vatSummary['7'])) {
            $fa->appendChild($xml->createElement('P_13_2', number_format($vatSummary['8']['net'] ?? $vatSummary['7']['net'], 2, '.', '')));
        }
        if (isset($vatSummary['5'])) {
            $fa->appendChild($xml->createElement('P_13_3', number_format($vatSummary['5']['net'], 2, '.', '')));
        }
        if (isset($vatSummary['0'])) {
            $fa->appendChild($xml->createElement('P_13_6_1', number_format($vatSummary['0']['net'], 2, '.', '')));
        }

        // P_14_x: VAT amounts by rate
        if (isset($vatSummary['23']) || isset($vatSummary['22'])) {
            $fa->appendChild($xml->createElement('P_14_1', number_format($vatSummary['23']['vat'] ?? $vatSummary['22']['vat'], 2, '.', '')));
        }
        if (isset($vatSummary['8']) || isset($vatSummary['7'])) {
            $fa->appendChild($xml->createElement('P_14_2', number_format($vatSummary['8']['vat'] ?? $vatSummary['7']['vat'], 2, '.', '')));
        }
        if (isset($vatSummary['5'])) {
            $fa->appendChild($xml->createElement('P_14_3', number_format($vatSummary['5']['vat'], 2, '.', '')));
        }

        // P_14_xW: VAT amounts in PLN
        if ($invoiceCurrency != 'PLN' && $kursWaluty > 0) {
            if (isset($vatSummary['23']) || isset($vatSummary['22'])) {
                $vatInvoice = $vatSummary['23']['vat'] ?? $vatSummary['22']['vat'];
                $vatPLN = $vatInvoice * $kursWaluty;
                $fa->appendChild($xml->createElement('P_14_1W', number_format($vatPLN, 2, '.', '')));
            }
            if (isset($vatSummary['8']) || isset($vatSummary['7'])) {
                $vatInvoice = $vatSummary['8']['vat'] ?? $vatSummary['7']['vat'];
                $vatPLN = $vatInvoice * $kursWaluty;
                $fa->appendChild($xml->createElement('P_14_2W', number_format($vatPLN, 2, '.', '')));
            }
            if (isset($vatSummary['5'])) {
                $vatPLN = $vatSummary['5']['vat'] * $kursWaluty;
                $fa->appendChild($xml->createElement('P_14_3W', number_format($vatPLN, 2, '.', '')));
            }
        }

        // P_15: Total Amount
        $totalAmount = $useMulticurrency ? $invoice->multicurrency_total_ttc : $invoice->total_ttc;
        $fa->appendChild($xml->createElement('P_15', number_format($totalAmount, 2, '.', '')));

        $this->buildAdnotacje($xml, $fa, $invoice);

        // Invoice Type
        $fa->appendChild($xml->createElement('RodzajFaktury', $invoiceType));

        // Reference to Original Invoice (KOR only)
        if ($invoiceType == 'KOR' && $originalInvoice) {
            $this->buildDaneFaKorygowanej($xml, $fa, $originalInvoice);
        }

        // Invoice Lines
        $this->buildFaWiersz($xml, $fa, $invoice);

        // Payment Info
        $this->buildPlatnosc($xml, $fa, $invoice);
    }

    /**
     * @brief Calculates VAT summary by rate
     * @param $invoice Invoice object
     * @return array VAT summary array
     * @called_by buildFa()
     */
    private function calculateVatSummary($invoice, $useMulticurrency = false)
    {
        $summary = array();
        foreach ($invoice->lines as $line) {
            $rate = number_format($line->tva_tx, 0);
            if (!isset($summary[$rate])) $summary[$rate] = array('net' => 0, 'vat' => 0);

            if ($useMulticurrency) {
                $summary[$rate]['net'] += $line->multicurrency_total_ht;
                $summary[$rate]['vat'] += $line->multicurrency_total_tva;
            } else {
                $summary[$rate]['net'] += $line->total_ht;
                $summary[$rate]['vat'] += $line->total_tva;
            }
        }
        return $summary;
    }

    /**
     * @brief Determines invoice type (VAT, KOR, ZAL)
     * @param $invoice Invoice object
     * @return string Invoice type code
     * @called_by buildFa()
     */
    private function getInvoiceType($invoice)
    {
        switch ($invoice->type) {
            case Facture::TYPE_CREDIT_NOTE:
                return 'KOR';
            case Facture::TYPE_DEPOSIT:
                return 'ZAL';
            case Facture::TYPE_STANDARD:
            case Facture::TYPE_REPLACEMENT:
            case Facture::TYPE_SITUATION:
            default:
                return 'VAT';
        }
    }

    /**
     * @brief Builds corrective invoice reference section
     * @param $xml DOMDocument
     * @param $parent Parent element
     * @param $originalInvoice Original invoice object
     * @called_by buildFa()
     * @calls getInvoiceKsefNumber()
     */
    private function buildDaneFaKorygowanej($xml, $parent, $originalInvoice)
    {
        // Only reference metadata (no amounts)
        $daneFaKor = $xml->createElement('DaneFaKorygowanej');
        $parent->appendChild($daneFaKor);

        // Date of original invoice
        $daneFaKor->appendChild($xml->createElement('DataWystFaKorygowanej', dol_print_date($originalInvoice->date, '%Y-%m-%d')));
        // Number of original invoice
        $daneFaKor->appendChild($xml->createElement('NrFaKorygowanej', $this->xmlSafe($originalInvoice->ref)));

        // KSeF reference check
        $originalKsefNumber = $this->getInvoiceKsefNumber($originalInvoice->id);

        if ($originalKsefNumber) {
            $daneFaKor->appendChild($xml->createElement('NrKSeF', '1'));
            $daneFaKor->appendChild($xml->createElement('NrKSeFFaKorygowanej', $originalKsefNumber));
        } else {
            $daneFaKor->appendChild($xml->createElement('NrKSeFN', '1'));
        }
    }

    /**
     * @brief Builds annotations/flags section
     * @param $xml DOMDocument
     * @param $parent Parent element
     * @param $invoice Invoice object
     * @called_by buildFa()
     */
    private function buildAdnotacje($xml, $parent, $invoice)
    {
        $adnotacje = $xml->createElement('Adnotacje');
        $parent->appendChild($adnotacje);

        // 1 = Yes, 2 = No/Not Applicable
        $adnotacje->appendChild($xml->createElement('P_16', '2'));  // Cash accounting
        $adnotacje->appendChild($xml->createElement('P_17', '2'));  // Self-billed
        $adnotacje->appendChild($xml->createElement('P_18', '2'));  // Reverse charge
        $adnotacje->appendChild($xml->createElement('P_18A', '2')); // Split payment

        $zwolnienie = $xml->createElement('Zwolnienie');
        $adnotacje->appendChild($zwolnienie);
        $zwolnienie->appendChild($xml->createElement('P_19N', '1')); // No exemption

        $noweSrodki = $xml->createElement('NoweSrodkiTransportu');
        $adnotacje->appendChild($noweSrodki);
        $noweSrodki->appendChild($xml->createElement('P_22N', '1')); // Not new transport means

        $adnotacje->appendChild($xml->createElement('P_23', '2')); // Not simplified chain transaction

        $pmarzy = $xml->createElement('PMarzy');
        $adnotacje->appendChild($pmarzy);
        $pmarzy->appendChild($xml->createElement('P_PMarzyN', '1')); // Not margin scheme
    }

    /**
     * @brief Builds invoice line items section
     * @param $xml DOMDocument
     * @param $parent Parent element
     * @param $invoice Invoice object
     * @called_by buildFa()
     * @calls xmlSafe()
     */
    private function buildFaWiersz($xml, $parent, $invoice)
    {
        global $conf;

        $lineNum = 1;
        foreach ($invoice->lines as $line) {
            $faWiersz = $xml->createElement('FaWiersz');
            $parent->appendChild($faWiersz);

            $faWiersz->appendChild($xml->createElement('NrWierszaFa', $lineNum++));

            // P_7: Product Name
            $description = $line->product_label ?: $line->desc;
            $faWiersz->appendChild($xml->createElement('P_7', $this->xmlSafe(substr($description, 0, 512))));

            // Indeks: Product reference code
            if (!empty($conf->global->KSEF_FA3_INCLUDE_INDEKS) && !empty($line->product_ref)) {
                $faWiersz->appendChild($xml->createElement('Indeks', $this->xmlSafe(substr($line->product_ref, 0, 50))));
            }

            // GTIN: From Product barcode
            if (!empty($conf->global->KSEF_FA3_INCLUDE_GTIN) && !empty($line->product_barcode)) {
                $faWiersz->appendChild($xml->createElement('GTIN', $this->xmlSafe($line->product_barcode)));
            }

            // P_8A: Unit of measure
            if (!empty($conf->global->KSEF_FA3_INCLUDE_UNIT) && !empty($line->product_unit)) {
                $faWiersz->appendChild($xml->createElement('P_8A', $this->xmlSafe($line->product_unit)));
            }

            // P_8B: Quantity
            $faWiersz->appendChild($xml->createElement('P_8B', number_format($line->qty, 2, '.', '')));

            // P_9A: Unit Net Price
            $unitPrice = (!empty($this->currentInvoiceCurrency) && $this->currentInvoiceCurrency != 'PLN')
                ? $line->multicurrency_subprice
                : $line->subprice;
            $faWiersz->appendChild($xml->createElement('P_9A', number_format($unitPrice, 2, '.', '')));

            // P_11: Net Line Total
            $lineTotal = (!empty($this->currentInvoiceCurrency) && $this->currentInvoiceCurrency != 'PLN')
                ? $line->multicurrency_total_ht
                : $line->total_ht;
            $faWiersz->appendChild($xml->createElement('P_11', number_format($lineTotal, 2, '.', '')));

            // P_12: VAT Rate
            $vatRate = $this->mapVatRateToKSeF($line->tva_tx, $line);
            $faWiersz->appendChild($xml->createElement('P_12', $vatRate));

            // KursWaluty: Exchange rate per line
            if (!empty($this->currentInvoiceCurrency) && $this->currentInvoiceCurrency != 'PLN' && !empty($this->currentKursWaluty)) {
                $faWiersz->appendChild($xml->createElement('KursWaluty', number_format($this->currentKursWaluty, 6, '.', '')));
            }
        }
    }

    /**
     * @brief Builds payment information section
     * @param $xml DOMDocument
     * @param $parent Parent element
     * @param $invoice Invoice object
     * @called_by buildFa()
     * @calls getPaymentMethodCode(), cleanIBAN(), xmlSafe()
     */
    private function buildPlatnosc($xml, $parent, $invoice)
    {
        global $conf;

        $platnosc = $xml->createElement('Platnosc');
        $parent->appendChild($platnosc);

        $terminPlatnosci = $xml->createElement('TerminPlatnosci');
        $platnosc->appendChild($terminPlatnosci);

        $dueDate = $invoice->date_lim_reglement ?: ($invoice->date + (30 * 86400));
        $terminPlatnosci->appendChild($xml->createElement('Termin', dol_print_date($dueDate, '%Y-%m-%d')));

        $paymentCode = $this->getPaymentMethodCode($invoice->mode_reglement_id);
        $platnosc->appendChild($xml->createElement('FormaPlatnosci', $paymentCode));

        // Add bank account if Transfer (6)
        if ($paymentCode == '6' && !empty($invoice->fk_account)) {
            require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
            $account = new Account($this->db);
            if ($account->fetch($invoice->fk_account) > 0 && !empty($account->iban)) {
                $rachunekBankowy = $xml->createElement('RachunekBankowy');
                $platnosc->appendChild($rachunekBankowy);
                $rachunekBankowy->appendChild($xml->createElement('NrRB', $this->cleanIBAN($account->iban)));
                if (!empty($account->bic)) {
                    $rachunekBankowy->appendChild($xml->createElement('SWIFT', strtoupper(trim($account->bic))));
                }
                if (!empty($account->bank)) {
                    $rachunekBankowy->appendChild($xml->createElement('NazwaBanku', $this->xmlSafe($account->bank)));
                }
                // OpisRachunku: Bank account description/label
                if (!empty($conf->global->KSEF_FA3_INCLUDE_BANK_DESC) && !empty($account->label)) {
                    $rachunekBankowy->appendChild($xml->createElement('OpisRachunku', $this->xmlSafe($account->label)));
                }
            }
        }
    }

    /**
     * @brief Builds footer section with registry numbers
     * @param DOMDocument $xml
     * @param DOMElement $parent Parent element
     * @called_by buildFromInvoice()
     */
    private function buildStopka($xml, $parent)
    {
        global $conf;

        $krs = !empty($conf->global->KSEF_COMPANY_KRS) ? trim($conf->global->KSEF_COMPANY_KRS) : '';
        $regon = !empty($conf->global->KSEF_COMPANY_REGON) ? trim($conf->global->KSEF_COMPANY_REGON) : '';
        $bdo = !empty($conf->global->KSEF_COMPANY_BDO) ? trim($conf->global->KSEF_COMPANY_BDO) : '';

        // Only create Stopka if at least one registry number is set
        if (empty($krs) && empty($regon) && empty($bdo)) {
            return;
        }

        $stopka = $xml->createElement('Stopka');
        $parent->appendChild($stopka);

        $rejestry = $xml->createElement('Rejestry');
        $stopka->appendChild($rejestry);

        if (!empty($krs)) {
            $rejestry->appendChild($xml->createElement('KRS', $this->xmlSafe($krs)));
        }
        if (!empty($regon)) {
            $rejestry->appendChild($xml->createElement('REGON', $this->xmlSafe($regon)));
        }
        if (!empty($bdo)) {
            $rejestry->appendChild($xml->createElement('BDO', $this->xmlSafe($bdo)));
        }
    }

    /**
     * @brief Cleans IBAN (removes spaces)
     * @param $iban IBAN string
     * @return string Cleaned IBAN
     * @called_by buildPlatnosc()
     */
    private function cleanIBAN($iban)
    {
        return preg_replace('/\s+/', '', strtoupper($iban));
    }


    /**
     * @brief Makes string XML-safe
     * @param $str String to escape
     * @return string Escaped string
     * @called_by buildPodmiot1(), buildPodmiot2(), buildFaWiersz()
     */
    private function xmlSafe($str)
    {
        return htmlspecialchars(trim($str), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * @brief Maps Dolibarr payment mode to KSeF code
     * @param $mode_reglement_id Payment mode ID
     * @return string KSeF payment code
     * @called_by buildPlatnosc()
     * KSeF codes: 1=Cash, 2=Card, 3=Voucher, 4=Check, 5=Credit, 6=Transfer, 7=Mobile
     */
    private function getPaymentMethodCode($mode_reglement_id)
    {
        $mapping = array(
            1 => '6',   // VIR -> Przelew (Transfer)
            2 => '6',   // PRE -> Przelew
            3 => '1',   // LIQ -> Gotowka (Cash)
            4 => '2',   // CB  -> Karta (Card)
            6 => '4',   // CHQ -> Czek (Check)
            7 => '6',   // TIP -> Przelew
            8 => '6',   // VAD -> Przelew
            9 => '6',   // TRA -> Przelew
            50 => '5',  // VAL -> Kredyt kupiecki (Trade credit)
            51 => '6',  // COMPENSATION -> Przelew
            52 => '6',  // OTHER -> Przelew
        );
        return isset($mapping[$mode_reglement_id]) ? $mapping[$mode_reglement_id] : '6';
    }

    /**
     * @brief Gets KSeF number from previous submission
     * @param $invoice_id Invoice ID
     * @return string|null KSeF number
     * @called_by buildDaneFaKorygowanej()
     */
    private function getInvoiceKsefNumber($invoice_id)
    {
        $sql = "SELECT ksef_number FROM " . MAIN_DB_PREFIX . "ksef_submissions";
        $sql .= " WHERE fk_facture = " . (int)$invoice_id . " AND status = 'ACCEPTED' AND ksef_number IS NOT NULL";
        $sql .= " ORDER BY date_submission DESC LIMIT 1";

        $resql = $this->db->query($sql);
        if ($resql) {
            if ($this->db->num_rows($resql) > 0) {
                $obj = $this->db->fetch_object($resql);
                return $obj->ksef_number;
            }
        }
        return null;
    }

    /**
     * @brief Gets entity flags (JST, VAT group)
     * @param $entity Entity object
     * @return array Flags array
     * @called_by buildPodmiot2()
     */
    private function getEntityFlags($entity)
    {
        $flags = array('jst' => '2', 'gv' => '2'); // Defaults: No

        if (!empty($entity->array_options['options_is_jst'])) {
            $flags['jst'] = $entity->array_options['options_is_jst'] ? '1' : '2';
        }
        if (!empty($entity->array_options['options_is_vat_group'])) {
            $flags['gv'] = $entity->array_options['options_is_vat_group'] ? '1' : '2';
        }
        return $flags;
    }

    /**
     * @brief Validates XML against schema
     * @param $xml XML string
     * @return bool True if valid
     * @called_by KSEF::validateFA3XML()
     */
    public function validate($xml)
    {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $valid = $dom->loadXML($xml);
        if (!$valid) {
            foreach (libxml_get_errors() as $error) {
                $this->errors[] = "XML Error: " . $error->message;
            }
            libxml_clear_errors();
        }
        return $valid;
    }

    /**
     * @brief Checks if country is EU member state
     * @param string $countryCode ISO 2-letter country code
     * @return bool True if EU member
     * @called_by buildPodmiot2()
     */
    private function isEUCountry($countryCode)
    {
        $euCountries = array(
            'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
            'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
            'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE'
        );
        return in_array(strtoupper($countryCode), $euCountries);
    }

    /**
     * @brief Maps VAT rate to KSeF P_12 value
     * @param float $vatRate VAT rate from invoice line
     * @param object $line Invoice line object (for additional context)
     * @return string KSeF P_12 value
     * @called_by buildFaWiersz()
     */
    private function mapVatRateToKSeF($vatRate, $line = null)
    {
        $rateInt = (int) round($vatRate);
        if (in_array($rateInt, array(23, 22, 8, 7, 5, 4, 3))) {
            return (string) $rateInt;
        }

        if ($rateInt == 0) {
            // Check if this is exempt (zw)
            if (!empty($line->vat_src_code) && stripos($line->vat_src_code, 'ZW') !== false) {
                return 'zw';
            }

            if (!empty($line->vat_src_code) && (stripos($line->vat_src_code, 'RC') !== false || stripos($line->vat_src_code, 'OO') !== false)) {
                return 'oo';
            }

            if (!empty($line->vat_src_code) && stripos($line->vat_src_code, 'NP') !== false) {
                return 'np I';
            }

            if (!empty($this->currentCustomerCountry)) {
                if ($this->currentCustomerCountry === 'PL') {
                    return '0 KR';
                } elseif ($this->currentCustomerIsEU) {
                    return '0 WDT';
                } else {
                    return '0 EX';
                }
            }

            return '0 KR';
        }

        if ($vatRate > 0) {
            return (string) $rateInt;
        }

        return 'np I';
    }
}