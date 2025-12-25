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
    const FA3_NAMESPACE = 'http://crd.gov.pl/wzor/2025/06/25/13775/';
    const FA3_SCHEMA_VERSION = '1-0E';

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

            $faktura = $xml->createElementNS(self::FA3_NAMESPACE, 'Faktura');
            $xml->appendChild($faktura);

            if (!empty($options['original_creation_date'])) {
                $this->lastCreationDate = $options['original_creation_date'];
            } else {
                $this->lastCreationDate = dol_now();
            }

            $this->buildNaglowek($xml, $faktura, $invoice, $options);
            $this->buildPodmiot1($xml, $faktura, $mysoc);
            $this->buildPodmiot2($xml, $faktura, $customer);
            $this->buildFa($xml, $faktura, $invoice, $originalInvoice);

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
        $kodFormularza->setAttribute('wersjaSchemy', self::FA3_SCHEMA_VERSION);
        $naglowek->appendChild($kodFormularza);

        // Form variant
        $naglowek->appendChild($xml->createElement('WariantFormularza', '3'));

        if (!empty($options['original_creation_date'])) {
            if (is_numeric($options['original_creation_date'])) {
                $dateCreation = date('Y-m-d\TH:i:s', $options['original_creation_date']);
            } else {
                $dateCreation = $options['original_creation_date'];
            }
        } else {
            $dateCreation = date('Y-m-d\TH:i:s');
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
     * @called_by buildFromInvoice()
     * @calls ksefCleanNIP(), xmlSafe()
     */
    private function buildPodmiot1($xml, $parent, $mysoc)
    {
        // Seller
        $podmiot1 = $xml->createElement('Podmiot1');
        $parent->appendChild($podmiot1);

        // Identification
        $daneIdent = $xml->createElement('DaneIdentyfikacyjne');
        $podmiot1->appendChild($daneIdent);

        $nip = ksefCleanNIP($mysoc->idprof1);
        if (empty($nip)) throw new Exception("Seller NIP is required");

        $this->sellerName = $this->xmlSafe($mysoc->name);
        $daneIdent->appendChild($xml->createElement('NIP', $nip));
        $daneIdent->appendChild($xml->createElement('Nazwa', $this->sellerName));

        // Address
        $adres = $xml->createElement('Adres');
        $podmiot1->appendChild($adres);

        $adres->appendChild($xml->createElement('KodKraju', $mysoc->country_code ?: 'PL'));

        $address = !empty($mysoc->address) ? $this->xmlSafe($mysoc->address) : 'ul. Testowa 1';
        $adresL1 = $address;

        if (!empty($mysoc->zip) && !empty($mysoc->town)) {
            $adresL1 .= ', ' . $this->xmlSafe($mysoc->zip) . ' ' . $this->xmlSafe($mysoc->town);
        } elseif (!empty($mysoc->town)) {
            $adresL1 .= ', ' . $this->xmlSafe($mysoc->town);
        }

        $adres->appendChild($xml->createElement('AdresL1', $adresL1));
    }

    /**
     * @brief Builds buyer (Podmiot2) section
     * @param $xml DOMDocument
     * @param $parent Parent element
     * @param $customer Customer object
     * @called_by buildFromInvoice()
     * @calls ksefCleanNIP(), xmlSafe(), getEntityFlags()
     */
    private function buildPodmiot2($xml, $parent, $customer)
    {
        // Buyer
        $podmiot2 = $xml->createElement('Podmiot2');
        $parent->appendChild($podmiot2);

        // Identification
        $daneIdent = $xml->createElement('DaneIdentyfikacyjne');
        $podmiot2->appendChild($daneIdent);

        $nip = ksefCleanNIP($customer->idprof1);
        if (!empty($nip) && $customer->country_code == 'PL') {
            $daneIdent->appendChild($xml->createElement('NIP', $nip));
        } elseif (!empty($customer->idprof1)) {
            $nrID = $xml->createElement('NrID', $this->xmlSafe($customer->idprof1));
            $nrID->setAttribute('kodKraju', $customer->country_code ?: 'XX');
            $daneIdent->appendChild($nrID);
        }
        $this->buyerName = $this->xmlSafe($customer->name);
        $daneIdent->appendChild($xml->createElement('Nazwa', $this->buyerName));

        // Address
        $adres = $xml->createElement('Adres');
        $podmiot2->appendChild($adres);

        $adres->appendChild($xml->createElement('KodKraju', $customer->country_code ?: 'PL'));

        $address = !empty($customer->address) ? $this->xmlSafe($customer->address) : 'Brak danych';
        $adresL1 = $address;

        if (!empty($customer->zip) && !empty($customer->town)) {
            $adresL1 .= ', ' . $this->xmlSafe($customer->zip) . ' ' . $this->xmlSafe($customer->town);
        } elseif (!empty($customer->town)) {
            $adresL1 .= ', ' . $this->xmlSafe($customer->town);
        }

        $adres->appendChild($xml->createElement('AdresL1', $adresL1));

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
        $fa = $xml->createElement('Fa');
        $parent->appendChild($fa);

        $invoiceType = $this->getInvoiceType($invoice);

        // Currency
        $fa->appendChild($xml->createElement('KodWaluty', $invoice->multicurrency_code ?: 'PLN'));
        // P_1: Invoice Date
        $fa->appendChild($xml->createElement('P_1', dol_print_date($invoice->date, '%Y-%m-%d')));
        // P_2: Invoice Number
        $fa->appendChild($xml->createElement('P_2', $this->xmlSafe($invoice->ref)));
        // P_6: Sale Date
        $deliveryDate = !empty($invoice->date_livraison) ? $invoice->date_livraison : $invoice->date;
        $fa->appendChild($xml->createElement('P_6', dol_print_date($deliveryDate, '%Y-%m-%d')));

        // Net/Tax Amounts (P_13_* / P_14_*)
        $vatSummary = $this->calculateVatSummary($invoice);

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

        // Tax Amounts
        if (isset($vatSummary['23']) || isset($vatSummary['22'])) {
            $fa->appendChild($xml->createElement('P_14_1', number_format($vatSummary['23']['vat'] ?? $vatSummary['22']['vat'], 2, '.', '')));
        }
        if (isset($vatSummary['8']) || isset($vatSummary['7'])) {
            $fa->appendChild($xml->createElement('P_14_2', number_format($vatSummary['8']['vat'] ?? $vatSummary['7']['vat'], 2, '.', '')));
        }
        if (isset($vatSummary['5'])) {
            $fa->appendChild($xml->createElement('P_14_3', number_format($vatSummary['5']['vat'], 2, '.', '')));
        }

        // P_15: Total Amount
        $fa->appendChild($xml->createElement('P_15', number_format($invoice->total_ttc, 2, '.', '')));

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
    private function calculateVatSummary($invoice)
    {
        $summary = array();
        foreach ($invoice->lines as $line) {
            $rate = number_format($line->tva_tx, 0);
            if (!isset($summary[$rate])) $summary[$rate] = array('net' => 0, 'vat' => 0);
            $summary[$rate]['net'] += $line->total_ht;
            $summary[$rate]['vat'] += $line->total_tva;
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
        $lineNum = 1;
        foreach ($invoice->lines as $line) {
            $faWiersz = $xml->createElement('FaWiersz');
            $parent->appendChild($faWiersz);

            $faWiersz->appendChild($xml->createElement('NrWierszaFa', $lineNum++));

            // P_7: Product Name
            $description = $line->product_label ?: $line->desc;
            $faWiersz->appendChild($xml->createElement('P_7', $this->xmlSafe(substr($description, 0, 512))));

            // P_8B: Quantity
            $faWiersz->appendChild($xml->createElement('P_8B', number_format($line->qty, 2, '.', '')));

            // P_9A: Unit Net Price
            $faWiersz->appendChild($xml->createElement('P_9A', number_format($line->subprice, 2, '.', '')));

            // P_11: Net Line Total
            $faWiersz->appendChild($xml->createElement('P_11', number_format($line->total_ht, 2, '.', '')));

            // P_12: VAT Rate (Integer)
            $vatRate = number_format($line->tva_tx, 0, '.', '');
            $faWiersz->appendChild($xml->createElement('P_12', $vatRate));
        }
    }

    /**
     * @brief Builds payment information section
     * @param $xml DOMDocument
     * @param $parent Parent element
     * @param $invoice Invoice object
     * @called_by buildFa()
     * @calls getPaymentMethodCode(), cleanIBAN()
     */
    private function buildPlatnosc($xml, $parent, $invoice)
    {
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
            }
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
            51 => '9',  // COMPENSATION -> Kompensata
            52 => '12', // OTHER -> Inne
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
}
