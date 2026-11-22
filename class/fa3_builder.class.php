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
dol_include_once('/ksef/lib/ksef.lib.php');

class FA3Builder
{
    private $db;
    public $error;
    public $errors = array();

    private $sellerName;
    private $buyerName;
    private $lastXmlHash;
    private $lastCreationDate;
    private $lastInvoiceType;
    private $currentInvoiceCurrency;
    private $currentKursWaluty;
    /** @var float|null */
    private $beforeKursWaluty;
    /** @var callable|null */
    private $lineKursResolve = null;
    /** @var array */
    private $payParts = array();
    /** @var array|null */
    private $korPlan = null;
    /** @var bool */
    private $noRowKurs = false;
    private $currentCustomer;
    private $currentCustomerCountry;
    private $currentCustomerIsEU;
    private $reconstructing = false;
    private $productCache = array();
    private $depositCtx = null;
    /** @var bool */
    private $grossMethod = false;
    /** @var bool */
    private $lineVatOn = false;
    private $lineDates = array();
    /** @var array|null */
    private $sourceLineDates = null;
    /** @var array */
    private $settlement = array();
    private $origSettlement = array();
    /** @var array */
    private $absorbedNotes = array();
    /** @var array */
    private $legacyZeroKeys = array();

    /**
     * @brief Constructor
     * @param DoliDB $db
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * @brief Build invoice XML
     * @param int $invoice_id
     * @param array $options Build options
     * @return string|false False on error
     */
    public function buildFromInvoice($invoice_id, $options = array())
    {
        global $conf, $mysoc, $extrafields;

        $this->lastXmlHash = null;
        $this->lastCreationDate = null;
        $this->lastInvoiceType = null;
        $this->productCache = array();
        $this->legacyZeroKeys = array();

        try {
            if (!isset($extrafields) || !is_object($extrafields)) {
                dol_include_once('/core/class/extrafields.class.php');
                $extrafields = new ExtraFields($this->db);
            }
            unset($extrafields->attributes['facture']);
            unset($extrafields->attributes['facturedet']);
            unset($extrafields->attributes['product']);
            $extrafields->fetch_name_optionals_label('facture', true);
            $extrafields->fetch_name_optionals_label('facturedet', true);
            $extrafields->fetch_name_optionals_label('product', true);

            $invoice = new Facture($this->db);
            if ($invoice->fetch($invoice_id) <= 0) {
                $this->error = "Invoice not found: $invoice_id";
                return false;
            }
            $invoice->fetch_lines();
            $invoice->fetch_optionals();

            $customer = new Societe($this->db);
            if ($customer->fetch($invoice->socid) <= 0) {
                $this->error = "Customer not found: " . $invoice->socid;
                return false;
            }
            $customer->fetch_optionals();
            $this->currentCustomer = $customer;

            $this->depositCtx = ksefDepositContext($this->db, $invoice);
            if (!$this->reconstructing && ksefDepositBlockCode($this->depositCtx) !== '') {
                throw new Exception(ksefDepositBlockMessage($this->db, $invoice, $this->depositCtx));
            }

            $originalInvoice = null;
            $originalXmlData = null;
            if (($invoice->type == Facture::TYPE_CREDIT_NOTE || $invoice->type == Facture::TYPE_REPLACEMENT) && !empty($invoice->fk_facture_source)) {
                $originalInvoice = new Facture($this->db);
                if ($originalInvoice->fetch($invoice->fk_facture_source) <= 0) {
                    $this->error = "Original invoice not found: " . $invoice->fk_facture_source;
                    return false;
                }
                $originalInvoice->fetch_lines();
                if (ksefGetInvoiceCurrency($invoice) !== ksefGetInvoiceCurrency($originalInvoice)) {
                    $this->error = ksefMsg('KSEF_ErrCrossCurrencyCorrection', ksefGetInvoiceCurrency($originalInvoice), ksefGetInvoiceCurrency($invoice));
                    return false;
                }
                $originalXmlData = $this->fetchOriginalXmlData($originalInvoice->id);
                if ($originalXmlData === null) {
                    $originalXmlData = $this->reconstructOriginalXmlData($originalInvoice);
                }
            }
            $this->absorbedNotes = array();
            foreach ($originalInvoice && $invoice->type == Facture::TYPE_REPLACEMENT ? array_map('intval', (array) (ksefCorrectionState($invoice)['absorb'] ?? array())) : array() as $noteId) {
                $note = new Facture($this->db);
                if ($note->fetch($noteId) <= 0) {
                    $this->error = "Credit note not found: " . $noteId;
                    return false;
                }
                $originalInvoice->lines = array_merge($originalInvoice->lines, $note->lines);
                foreach (array('total_ht', 'total_tva', 'total_ttc', 'multicurrency_total_ht', 'multicurrency_total_tva', 'multicurrency_total_ttc') as $col) {
                    $originalInvoice->$col = (float) $originalInvoice->$col + (float) $note->$col;
                }
                $this->absorbedNotes[] = $note;
            }

            foreach (array_filter(array($invoice, $originalInvoice)) as $obj) {
                $obj->lines = array_values(array_filter((array) $obj->lines, function ($l) {
                    return (int) ($l->product_type ?? 0) !== 9;
                }));
            }
            $badRates = $this->reconstructing ? array() : ksefNonPolishRates($invoice->lines);
            if ($badRates) {
                throw new Exception(ksefMsg('KSEF_VatRateNotPolish', implode(', ', $badRates)));
            }
            $this->settlement = $this->takeSettlementLines($invoice);
            $this->origSettlement = $originalInvoice ? $this->takeSettlementLines($originalInvoice) : array();

            $this->grossMethod = $this->resolveBooleanFlagSource(getDolGlobalString('KSEF_FA3_GROSS_SOURCE', 'disabled'), $originalInvoice ? $originalInvoice : $invoice);
            if ($this->grossMethod && getDolGlobalInt('MAIN_ROUNDOFTOTAL_NOT_TOTALOFROUND')) {
                global $langs;
                $langs->load('ksef@ksef');
                throw new Exception($langs->transnoentitiesnoconv('KSEF_GrossMethodRoundingMode'));
            }
            // art. 106e(10)
            $this->lineVatOn = ksefLineVatWanted(array((array) $invoice->lines, $originalInvoice ? (array) $originalInvoice->lines : array()), array(
                'key' => function ($line) {
                    return $this->rateKey($line);
                },
                'pln' => ksefGetInvoiceCurrency($invoice) === 'PLN',
                'gross' => $this->grossMethod,
            ));

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
            $this->buildPodmiot2($xml, $faktura, $customer, $invoice);
            $this->buildPodmiot3($xml, $faktura, $invoice);
            if (!empty($originalXmlData['sent_rows'])) {
                $this->legacyZeroKeys = $this->legacyZeroKeys($invoice, $originalInvoice, $originalXmlData['sent_rows']);
            }
            $this->buildFa($xml, $faktura, $invoice, $originalInvoice, $originalXmlData);
            $this->buildStopka($xml, $faktura, $invoice);

            $xmlString = $xml->saveXML();

            $this->lastXmlHash = base64_encode(hash('sha256', $xmlString, true));

            return $xmlString;

        } catch (Exception $e) {
            $this->error = $e->getMessage();
            $this->errors[] = $this->error;
            dol_syslog("FA3Builder::buildFromInvoice ERROR: FA(3) generation error: " . $this->error, LOG_ERR);
            return false;
        }
    }

    /**
     * @brief Last creation date
     * @return int|null
     */
    public function getLastCreationDate()
    {
        return $this->lastCreationDate;
    }

    /**
     * @brief Last XML hash
     * @return string|null Base64 SHA-256
     */
    public function getLastXmlHash()
    {
        return $this->lastXmlHash;
    }

    /**
     * @brief Last invoice type
     * @return string|null
     */
    public function getLastInvoiceType()
    {
        return $this->lastInvoiceType;
    }

    /**
     * @brief Build Naglowek
     * @param DOMDocument $xml
     * @param DOMElement $parent
     * @param Facture $invoice
     * @param array $options
     */
    private function buildNaglowek($xml, $parent, $invoice, $options = array())
    {
        $naglowek = $xml->createElement('Naglowek');
        $parent->appendChild($naglowek);

        $kodFormularza = $xml->createElement('KodFormularza', 'FA');
        $kodFormularza->setAttribute('kodSystemowy', 'FA (3)');
        $kodFormularza->setAttribute('wersjaSchemy', KSEF_FA3_SCHEMA_VERSION);
        $naglowek->appendChild($kodFormularza);

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

        $systemInfo = 'Dolibarr ERP ' . DOL_VERSION;
        if (!empty($options['offline_mode'])) {
            $systemInfo .= ' (offline)';
        }
        $naglowek->appendChild($xml->createElement('SystemInfo', $systemInfo));
    }

    /**
     * @brief Build seller Podmiot1
     * @param DOMDocument $xml
     * @param DOMElement $parent
     * @param Societe $mysoc
     * @param Societe $customer
     */
    private function buildPodmiot1($xml, $parent, $mysoc, $customer)
    {
        global $conf;

        $podmiot1 = $xml->createElement('Podmiot1');
        $parent->appendChild($podmiot1);

        $buyerCountry = ksefInferCountryCode($customer);
        $isForeignBuyer = ($buyerCountry != 'PL');

        if ($isForeignBuyer && !empty($mysoc->tva_intra)) {
            $vatIntra = strtoupper(trim($mysoc->tva_intra));
            if (preg_match('/^([A-Z]{2})/', $vatIntra, $matches)) {
                $vatPrefix = $matches[1];
                $podmiot1->appendChild($xml->createElement('PrefiksPodatnika', $vatPrefix));
            }
        }

        $sellerEori = getDolGlobalString('KSEF_COMPANY_EORI', '');
        if (empty($sellerEori)) {
            $sellerEori = trim(ksefGetIdentifierField($mysoc, 'EORI'));
        }
        if (!empty($sellerEori)) {
            $podmiot1->appendChild($xml->createElement('NrEORI', $this->xmlSafe($sellerEori)));
        }

        $daneIdent = $xml->createElement('DaneIdentyfikacyjne');
        $podmiot1->appendChild($daneIdent);

        $nip = ksefSellerNip();
        if (empty($nip)) {
            throw new Exception(ksefMsg('KSEF_ErrSellerNipMissing'));
        }

        $this->sellerName = $this->xmlSafe($mysoc->name);
        $daneIdent->appendChild($xml->createElement('NIP', $nip));
        $daneIdent->appendChild($xml->createElement('Nazwa', $this->sellerName));

        $adres = $xml->createElement('Adres');
        $podmiot1->appendChild($adres);

        $adres->appendChild($xml->createElement('KodKraju', $mysoc->country_code ?: 'PL'));

        $adres->appendChild($xml->createElement('AdresL1', !empty($mysoc->address) ? $this->xmlSafe($mysoc->address) : ''));

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

        $this->appendDaneKontaktowe($xml, $podmiot1, $mysoc->email ?? '', $mysoc->phone ?? '');
    }

    /**
     * @brief Append DaneKontaktowe
     * @param DOMDocument $xml
     * @param DOMElement $parent
     * @param string $email May be several
     * @param string $phone
     */
    private function appendDaneKontaktowe($xml, $parent, $email, $phone)
    {
        $mail = '';
        foreach (preg_split('/[\s,;]+/', trim((string) $email)) as $candidate) {
            if ($candidate !== '' && isValidEmail($candidate)) {
                $mail = $candidate;
                break;
            }
        }
        $tel = ksefPhoneForXml($phone);
        if (ksefPhoneTooLong($phone)) {
            dol_syslog(__METHOD__ . " phone '" . $phone . "' longer than 16 characters (TNumerTelefonu): left out of DaneKontaktowe", LOG_WARNING);
        }
        if ($mail === '' && $tel === '') {
            return;
        }
        $dane = $xml->createElement('DaneKontaktowe');
        if ($mail !== '') {
            $dane->appendChild($xml->createElement('Email', $this->xmlSafe($mail)));
        }
        if ($tel !== '') {
            $dane->appendChild($xml->createElement('Telefon', $this->xmlSafe($tel)));
        }
        $parent->appendChild($dane);
    }

    /**
     * @brief Include party contact
     * @param Societe $party
     * @return bool
     */
    private function includeContactOf($party)
    {
        return getDolGlobalString('KSEF_FA3_INCLUDE_CONTACT', '1') !== '0'
            && empty($party->array_options['options_ksef_hide_contact']);
    }

    /**
     * @brief Build buyer Podmiot2
     * @param DOMDocument $xml
     * @param DOMElement $parent
     * @param Societe $customer
     * @param Facture|null $invoice
     */
    private function buildPodmiot2($xml, $parent, $customer, $invoice = null)
    {
        $podmiot2 = $xml->createElement('Podmiot2');
        $parent->appendChild($podmiot2);

        $nrEori = $this->getBuyerNrEORI($customer);
        if (!empty($nrEori) && empty($customer->array_options['options_ksef_hide_eori'])) {
            $podmiot2->appendChild($xml->createElement('NrEORI', $this->xmlSafe($nrEori)));
        }

        $daneIdent = $xml->createElement('DaneIdentyfikacyjne');
        $podmiot2->appendChild($daneIdent);

        $countryCode = ksefInferCountryCode($customer);
        $isPolish = ($countryCode === 'PL');
        $isEU = $this->isEUCountry($countryCode);

        $nipRaw = ksefGetIdentifierField($customer, 'NIP');
        $nip = ksefCleanNIP($nipRaw);

        if ($isPolish && !empty($nip)) {
            $daneIdent->appendChild($xml->createElement('NIP', $nip));
        } elseif ($isEU && !empty($customer->tva_intra)) {
            $vatNumber = ksefNrVatUE($customer->tva_intra);
            $daneIdent->appendChild($xml->createElement('KodUE', ksefKodUE($countryCode)));
            if (!empty($vatNumber)) {
                $daneIdent->appendChild($xml->createElement('NrVatUE', $vatNumber));
            }
        } elseif (!$isPolish && !empty($nipRaw)) {
            $daneIdent->appendChild($xml->createElement('KodKraju', $countryCode));
            $daneIdent->appendChild($xml->createElement('NrID', $this->xmlSafe(ksefStripVATPrefix($nipRaw))));
        } else {
            $daneIdent->appendChild($xml->createElement('BrakID', '1'));
        }

        $this->buyerName = $this->xmlSafe($customer->name);
        $daneIdent->appendChild($xml->createElement('Nazwa', $this->buyerName));

        $adres = $xml->createElement('Adres');
        $podmiot2->appendChild($adres);

        $adres->appendChild($xml->createElement('KodKraju', $countryCode));

        $lines = ksefBuyerAddressLines($customer);
        $adres->appendChild($xml->createElement('AdresL1', $this->xmlSafe($lines['l1'])));
        if ($lines['l2'] !== '') {
            $adres->appendChild($xml->createElement('AdresL2', ksefXmlEscape($lines['l2'])));
        }

        if ($this->includeContactOf($customer)) {
            $this->appendDaneKontaktowe($xml, $podmiot2, $customer->email ?? '', $customer->phone ?? '');
        }

        if (getDolGlobalString('KSEF_FA3_INCLUDE_NRKLIENTA') && !empty($customer->code_client)) {
            $podmiot2->appendChild($xml->createElement('NrKlienta', $this->xmlSafe($customer->code_client)));
        }

        $idNabywcy = $this->getIdNabywcy($customer);
        if (!empty($idNabywcy)) {
            $podmiot2->appendChild($xml->createElement('IDNabywcy', $this->xmlSafe($idNabywcy)));
        }

        $this->currentCustomerCountry = $countryCode;
        $this->currentCustomerIsEU = $isEU;

        $flags = $this->getEntityFlags($customer, $invoice);
        $podmiot2->appendChild($xml->createElement('JST', $flags['jst']));
        $podmiot2->appendChild($xml->createElement('GV', $flags['gv']));
    }

    /**
     * @brief Build Podmiot3 sections
     * @param DOMDocument $xml
     * @param DOMElement $parent
     * @param Facture $invoice
     */
    private function buildPodmiot3($xml, $parent, $invoice)
    {
        if (getDolGlobalString('KSEF_PODMIOT3_SOURCE', 'disabled') !== 'enabled') {
            return;
        }
        if (!isset($invoice->array_options) || empty($invoice->array_options)) {
            $invoice->fetch_optionals();
        }

        $list = $this->getPodmiot3List($invoice);
        if (empty($list)) {
            return;
        }

        $emitted = 0;
        foreach ($list as $e) {
            $this->buildOnePodmiot3($xml, $parent, $e['id'], $e['role'], $e['udzial']);
            if (++$emitted >= 20) break;
        }
    }

    /**
     * @brief Podmiot3 entries
     * @param Facture $invoice
     * @return array
     */
    private function getPodmiot3List($invoice)
    {
        if (!isset($invoice->array_options) || empty($invoice->array_options)) {
            $invoice->fetch_optionals();
        }
        return ksefPodmiot3Entries($invoice);
    }

    /**
     * @brief Build one Podmiot3
     * @param DOMDocument $xml
     * @param DOMElement $parent
     * @param int $societeId
     * @param string $role Role code
     * @param string $udzial Percent share
     */
    private function buildOnePodmiot3($xml, $parent, $societeId, $role, $udzial = '')
    {
        $party = ksefVisibleSociete($this->db, $societeId);
        if (!$party) {
            return;
        }
        $party->fetch_optionals();
        if ($role === '') {
            $role = getDolGlobalString('KSEF_PODMIOT3_ROLE', '6');
        }

        $podmiot3 = $xml->createElement('Podmiot3');
        $parent->appendChild($podmiot3);

        $daneIdent = $xml->createElement('DaneIdentyfikacyjne');
        $podmiot3->appendChild($daneIdent);

        $countryCode = ksefInferCountryCode($party);
        $isPolish = ($countryCode === 'PL');
        $isEU = $this->isEUCountry($countryCode);
        $nipRaw = ksefGetIdentifierField($party, 'NIP');
        $nip = ksefCleanNIP($nipRaw);

        $idwew = '';
        $idwewSource = getDolGlobalString('KSEF_IDWEW_SOURCE', 'disabled');
        if (strpos($idwewSource, 'thirdparty_extrafield:') === 0) {
            $idwewField = substr($idwewSource, strlen('thirdparty_extrafield:'));
            if (!empty($idwewField)) {
                $idwew = trim((string) ($party->array_options['options_' . $idwewField] ?? ''));
            }
        }
        if ($idwew !== '' && preg_match('/^[1-9]((\d[1-9])|([1-9]\d))\d{7}-\d{5}$/', $idwew)) {
            $daneIdent->appendChild($xml->createElement('IDWew', $idwew));
        } elseif ($isPolish && !empty($nip)) {
            $daneIdent->appendChild($xml->createElement('NIP', $nip));
        } elseif ($isEU && !empty($party->tva_intra)) {
            $vatNumber = ksefNrVatUE($party->tva_intra);
            $daneIdent->appendChild($xml->createElement('KodUE', ksefKodUE($countryCode)));
            if (!empty($vatNumber)) {
                $daneIdent->appendChild($xml->createElement('NrVatUE', $vatNumber));
            }
        } elseif (!$isPolish && !empty($nipRaw)) {
            $daneIdent->appendChild($xml->createElement('KodKraju', $countryCode));
            $daneIdent->appendChild($xml->createElement('NrID', $this->xmlSafe(ksefStripVATPrefix($nipRaw))));
        } else {
            $daneIdent->appendChild($xml->createElement('BrakID', '1'));
        }
        if (!empty($party->name)) {
            $daneIdent->appendChild($xml->createElement('Nazwa', $this->xmlSafe($party->name)));
        }

        $adres = $xml->createElement('Adres');
        $podmiot3->appendChild($adres);
        $adres->appendChild($xml->createElement('KodKraju', $countryCode));
        $lines = ksefBuyerAddressLines($party);
        $adres->appendChild($xml->createElement('AdresL1', $this->xmlSafe($lines['l1'])));
        if ($lines['l2'] !== '') {
            $adres->appendChild($xml->createElement('AdresL2', ksefXmlEscape($lines['l2'])));
        }

        // XSD sequence
        if ($this->includeContactOf($party)) {
            $this->appendDaneKontaktowe($xml, $podmiot3, $party->email ?? '', $party->phone ?? '');
        }

        $podmiot3->appendChild($xml->createElement('Rola', $this->xmlSafe($role)));

        if ($role === '4' && $udzial !== '') {
            $udzialVal = (float) str_replace(',', '.', $udzial);
            if ($udzialVal > 0 && $udzialVal <= 100) {
                $udzialStr = rtrim(rtrim(number_format($udzialVal, 2, '.', ''), '0'), '.');
                $podmiot3->appendChild($xml->createElement('Udzial', $udzialStr));
            }
        }
    }

    /**
     * @brief Build Fa section
     * @param DOMDocument $xml
     * @param DOMElement $parent
     * @param Facture $invoice
     * @param Facture|null $originalInvoice
     * @param array|null $originalXmlData Parsed source XML
     */
    private function buildFa($xml, $parent, $invoice, $originalInvoice = null, $originalXmlData = null)
    {
        global $mysoc;

        $fa = $xml->createElement('Fa');
        $parent->appendChild($fa);

        $invoiceType = $this->getInvoiceType($invoice);
        $this->lastInvoiceType = $invoiceType;
        $isKorType = in_array($invoiceType, array('KOR', 'KOR_ZAL', 'KOR_ROZ'), true);

        dol_include_once('/ksef/lib/ksef.lib.php');

        $invoiceCurrency = ksefGetInvoiceCurrency($invoice);

        if (!isset($invoice->array_options) || empty($invoice->array_options)) {
            $invoice->fetch_optionals();
        }

        $kursWaluty = null;
        if ($invoiceCurrency != 'PLN') {

            $dolibarrRate = $invoice->multicurrency_tx ?? 0;
            if ($dolibarrRate <= 0) {
                throw new Exception(ksefMsg('KSEF_ErrNBPRateMissing', $invoiceCurrency));
            }

            $kursOpt = ($invoiceType === 'KOR_ZAL' && !empty($this->depositCtx['root_id'])) ? array('root_id' => (int) $this->depositCtx['root_id']) : array();
            $kursWaluty = $this->kursOf($invoice, $kursOpt);
        }
        $this->currentInvoiceCurrency = $invoiceCurrency;
        $this->currentKursWaluty = $kursWaluty;
        $this->beforeKursWaluty = null;
        $origKurs = ($kursWaluty > 0 && $originalInvoice) ? $this->kursOf($originalInvoice) : null;
        if ($kursWaluty > 0 && $invoiceType !== 'KOR_ZAL' && $invoice->type == Facture::TYPE_REPLACEMENT && $origKurs
            && number_format($origKurs, 6, '.', '') !== number_format($kursWaluty, 6, '.', '')) {
            $this->beforeKursWaluty = $origKurs;
        }
        // art. 31b(1)
        $this->lineKursResolve = null;
        if ($kursWaluty > 0 && !in_array($invoiceType, array('ZAL', 'KOR_ZAL'), true)) {
            $lineMap = ksefLineKursMap($this->db, $invoice);
            if ($lineMap['active']) {
                $this->lineKursResolve = $lineMap['resolve'];
                $this->beforeKursWaluty = null;
            }
        }
        $useMulticurrency = ($invoiceCurrency != 'PLN');
        $fa->appendChild($xml->createElement('KodWaluty', $invoiceCurrency));

        // no TZ shift
        $fa->appendChild($xml->createElement('P_1', dol_print_date($invoice->date, '%Y-%m-%d', 'tzserver')));

        $placeOfIssueMode = getDolGlobalString('KSEF_FA3_PLACE_OF_ISSUE_MODE', 'disabled');
        if ($placeOfIssueMode != 'disabled') {
            $placeOfIssue = '';
            if ($placeOfIssueMode == 'custom' && getDolGlobalString('KSEF_FA3_PLACE_OF_ISSUE_CUSTOM')) {
                $placeOfIssue = getDolGlobalString('KSEF_FA3_PLACE_OF_ISSUE_CUSTOM');
            } elseif ($placeOfIssueMode == 'company' && !empty($mysoc->town)) {
                $placeOfIssue = $mysoc->town;
            }
            if (!empty($placeOfIssue)) {
                $fa->appendChild($xml->createElement('P_1M', $this->xmlSafe($placeOfIssue)));
            }
        }

        $fa->appendChild($xml->createElement('P_2', $this->xmlSafe($invoice->ref)));
        if (getDolGlobalInt('KSEF_FA3_INCLUDE_WZ')) {
            foreach ($this->getLinkedShipmentRefs($invoice) as $wz) {
                $fa->appendChild($xml->createElement('WZ', $this->xmlSafe($wz)));
            }
        }
        $this->lineDates = array();
        $this->sourceLineDates = null;
        $korZalRoot = ($this->depositCtx['type'] ?? '') === 'KOR_ZAL' ? (int) ($this->depositCtx['root_id'] ?? 0) : 0;
        $this->payParts = ((int) $invoice->type === Facture::TYPE_DEPOSIT && $korZalRoot <= 0) ? ksefDepositPaymentParts($this->db, $invoice) : array();
        $this->korPlan = null;
        if ($korZalRoot > 0) {
            $korRoot = new Facture($this->db);
            $this->payParts = $korRoot->fetch($korZalRoot) > 0 ? ksefDepositKorParts($this->db, $korRoot) : array();
            $this->korPlan = ksefDepositKorPlan($this->db, $invoice);
            if ($this->korPlan !== null) {
                $this->payParts = $this->korPlan['parts'];
            }
        }
        if (count($this->payParts) > 31) {
            throw new Exception(ksefMsg('KSEF_DepositPartsMax', $korZalRoot > 0 ? $korRoot->ref : $invoice->ref, count($this->payParts)));
        }
        $this->noRowKurs = false;
        if ($this->payParts && $invoiceCurrency != 'PLN') {
            $partRates = array();
            foreach ($this->payParts as $pp) {
                if ((float) $pp['kurs'] <= 0) {
                    throw new Exception(ksefMsg('KSEF_ErrNBPRateMissing', $invoiceCurrency));
                }
                $partRates[number_format((float) $pp['kurs'], 6, '.', '')] = true;
            }
            if (count($partRates) === 1) {
                $kursWaluty = (float) key($partRates);
                $this->currentKursWaluty = $kursWaluty;
            } else {
                $this->noRowKurs = true;
            }
        }
        if ($this->payParts) {
            // P_6 per ZaliczkaCzesciowa
        } elseif ((int) $invoice->type === Facture::TYPE_DEPOSIT || $korZalRoot > 0) {
            $paidOn = $korZalRoot > 0 ? ksefDepositSentPaymentDate($this->db, $korZalRoot, (int) $invoice->id) : ksefDepositPaymentDate($this->db, $invoice);
            if ($paidOn > 0 && ksefMomentDay($paidOn) !== dol_print_date($invoice->date, '%Y-%m-%d', 'tzserver')) {
                $fa->appendChild($xml->createElement('P_6', ksefMomentDay($paidOn)));
            }
        } else {
            $xmlLines = $this->linesForXml($invoice);
            // art. 106j(2)(3)(a)
            $supply = ($isKorType && !empty($invoice->fk_facture_source)) ? $this->correctionSupply($invoice, $xmlLines) : ksefInvoiceSupplyMode($invoice, $xmlLines);
            if ($supply['mode'] === 'p6a') {
                foreach ($supply['lines'] as $i => $d) {
                    $this->lineDates[spl_object_id($xmlLines[$i])] = $d;
                }
                if (isset($supply['by_id'])) {
                    $this->sourceLineDates = array('by_id' => $supply['by_id'], 'fallback' => $supply['fallback']);
                }
            }
            if ($supply['mode'] === 'okres') {
                $okres = $xml->createElement('OkresFa');
                $okres->appendChild($xml->createElement('P_6_Od', $supply['from']));
                $okres->appendChild($xml->createElement('P_6_Do', $supply['to']));
                $fa->appendChild($okres);
            } elseif ($supply['mode'] === 'p6') {
                $fa->appendChild($xml->createElement('P_6', $supply['p6']));
            }
        }

        $vatSummary = $this->calculateVatSummary($invoice, $useMulticurrency);

        $origVatSummary = array();
        if ($isKorType && $invoice->type == Facture::TYPE_REPLACEMENT && $originalInvoice) {
            $origVatSummary = $this->calculateVatSummary($originalInvoice, $useMulticurrency);
            foreach ($origVatSummary as $rate => $amounts) {
                if (isset($vatSummary[$rate])) {
                    $vatSummary[$rate]['net'] -= $amounts['net'];
                    $vatSummary[$rate]['vat'] -= $amounts['vat'];
                    $vatSummary[$rate]['gross'] -= $amounts['gross'];
                } else {
                    $vatSummary[$rate] = array('net' => -$amounts['net'], 'vat' => -$amounts['vat'], 'gross' => -$amounts['gross']);
                }
            }
        }

        $korRozPaid = ($invoiceType === 'KOR_ROZ' && $invoice->type == Facture::TYPE_REPLACEMENT) ? $this->korRozPaymentShift($invoice, $useMulticurrency) : array('rates' => array(), 'ttc' => 0.0);
        $vatSummary = $this->lessDeposits($vatSummary, $korRozPaid['rates']);
        if ($this->grossMethod && $korRozPaid['rates'] && $originalInvoice) {
            $after = $this->grossVatSummary($this->lessDeposits($this->calculateVatSummary($invoice, $useMulticurrency), $korRozPaid['new']));
            $before = $this->grossVatSummary($this->lessDeposits($this->calculateVatSummary($originalInvoice, $useMulticurrency), $korRozPaid['old']));
            $vatSummary = $after;
            foreach ($before as $key => $amt) {
                if (!isset($vatSummary[$key])) {
                    $vatSummary[$key] = array('net' => 0, 'vat' => 0, 'gross' => 0);
                }
                foreach (array('net', 'vat', 'gross') as $col) {
                    $vatSummary[$key][$col] -= $amt[$col];
                }
            }
        }

        // ROZ: MF 2.7
        $isMulticurrency = ($invoiceCurrency != 'PLN' && $kursWaluty > 0);
        if ($invoiceType == 'ROZ' && is_array($this->depositCtx)) {
            if ($this->depositCtx['mode'] === 'payment') {
                $vatSummary = $this->lessDeposits($vatSummary, $this->depositRates($this->depositCtx['deposits'], $useMulticurrency));
                if ($this->grossMethod) {
                    $vatSummary = $this->grossVatSummary($vatSummary);
                }
            }
        }
        // art. 106e(11)
        $before = $origVatSummary;
        if ($korRozPaid['rates']) {
            $before = $this->lessDeposits($before, $korRozPaid['old']);
            if ($this->grossMethod) {
                $before = $this->grossVatSummary($before);
            }
        }
        $replacementW = $isMulticurrency && $isKorType && $invoice->type == Facture::TYPE_REPLACEMENT && $originalInvoice;
        $statedBefore = $replacementW ? ksefStatedBeforeVatW($this->db, $invoice) : null;
        $beforeKurs = $this->beforeKursWaluty !== null ? $this->beforeKursWaluty : $kursWaluty;
        // match ksefDerivePlnFromForeign()
        $fx = $isMulticurrency ? ksefFxRulesOf($this->db, (int) $invoice->id) : array('vat' => 'convert_vat', 'kor' => 'after_before');
        $grossLike = $this->grossMethod || in_array($invoiceType, array('ZAL', 'KOR_ZAL'), true);
        $korChange = $replacementW && $fx['kor'] === 'change' && $this->beforeKursWaluty === null && empty($korRozPaid['rates']);
        $partsAfter = $partsBefore = null;
        if ($isMulticurrency && ($this->lineKursResolve !== null || ($this->payParts && in_array($invoiceType, array('ZAL', 'KOR_ZAL'), true)))) {
            $partsOpt = array('kurs' => $kursWaluty, 'gross' => $this->grossMethod, 'key' => function ($line) {
                return $this->rateKey($line);
            });
            if ($this->lineKursResolve !== null) {
                $partsOpt['line_kurs'] = $this->lineKursResolve;
            } else {
                $partsOpt['pay_parts'] = $this->korPlan !== null ? $this->korPlan['own'] : $this->payParts;
            }
            $partsAfter = ksefForeignRateSummary((array) $invoice->lines, $partsOpt)['keys'];
            if ($this->korPlan !== null && $this->korPlan['before']) {
                $partsOpt['pay_parts'] = $this->korPlan['before'];
            }
            $partsBefore = ($replacementW && $originalInvoice) ? ksefForeignRateSummary((array) $originalInvoice->lines, $partsOpt)['keys'] : array();
            if ($this->lineKursResolve !== null) {
                $paidDeposits = array();
                if ($invoiceType === 'ROZ' && is_array($this->depositCtx) && $this->depositCtx['mode'] === 'payment') {
                    $paidDeposits = $this->depositRates($this->depositCtx['deposits'], true);
                }
                $partsAfter = $this->partsLessDeposits($partsAfter, $korRozPaid['rates'] ? $korRozPaid['new'] : $paidDeposits, $kursWaluty);
                if ($korRozPaid['rates'] && $partsBefore) {
                    $partsBefore = $this->partsLessDeposits($partsBefore, $korRozPaid['old'], $kursWaluty);
                }
            }
        }
        $bucketW = function ($keys, $vat) use ($kursWaluty, $beforeKurs, $before, $replacementW, $statedBefore, $vatSummary, $fx, $grossLike, $korChange, $partsAfter, $partsBefore) {
            $k = round((float) $kursWaluty, 6);
            if ($partsAfter !== null) {
                $convP = function ($amounts) use ($keys, $k, $fx, $grossLike) {
                    return ksefFxConvertW($amounts, $keys, $k, $fx['vat'], $grossLike, (string) ($fx['multi'] ?? 'once'))['vat_w'];
                };
                if (!$replacementW) {
                    $w = $convP($partsAfter);
                } elseif ($korChange) {
                    $delta = array();
                    foreach ($keys as $key) {
                        foreach (array('net', 'vat', 'gross') as $col) {
                            $delta[$key][$col] = round((float) ($partsAfter[$key][$col] ?? 0) - (float) ($partsBefore[$key][$col] ?? 0), 2);
                        }
                        $pa = (array) ($partsAfter[$key]['parts'] ?? array());
                        $pb = (array) ($partsBefore[$key]['parts'] ?? array());
                        foreach (array_unique(array_merge(array_keys($pa), array_keys($pb))) as $pk) {
                            foreach (array('net', 'vat', 'gross') as $col) {
                                $delta[$key]['parts'][(string) $pk][$col] = round((float) ($pa[$pk][$col] ?? 0) - (float) ($pb[$pk][$col] ?? 0), 2);
                            }
                        }
                    }
                    $w = $convP($delta);
                } else {
                    $wb = $statedBefore !== null ? (float) ($statedBefore[ksefForeignRateBucket($keys[0])] ?? 0) : $convP($partsBefore);
                    $w = round($convP($partsAfter) - $wb, 2);
                }
                return number_format(abs($w) < 0.005 ? 0 : $w, 2, '.', '');
            }
            $conv = function ($amounts, $kk) use ($keys, $fx, $grossLike) {
                return ksefFxConvertW($amounts, $keys, $kk, $fx['vat'], $grossLike)['vat_w'];
            };
            if (!$replacementW || $korChange) {
                $w = $fx['vat'] === 'convert_base' || $korChange ? $conv($vatSummary, $k) : round(round((float) $vat, 2) * $k, 2);
                return number_format(abs($w) < 0.005 ? 0 : $w, 2, '.', '');
            }
            $after = array();
            foreach ($keys as $key) {
                foreach (array('net', 'vat', 'gross') as $col) {
                    $after[$key][$col] = (float) ($vatSummary[$key][$col] ?? 0) + (float) ($before[$key][$col] ?? 0);
                }
            }
            $wb = $statedBefore !== null ? (float) ($statedBefore[ksefForeignRateBucket($keys[0])] ?? 0) : $conv($before, round((float) $beforeKurs, 6));
            $w = round($conv($after, $k) - $wb, 2);
            return number_format(abs($w) < 0.005 ? 0 : $w, 2, '.', '');
        };

        if (isset($vatSummary['23']) || isset($vatSummary['22'])) {
            $net = ($vatSummary['23']['net'] ?? 0) + ($vatSummary['22']['net'] ?? 0);
            $vat = ($vatSummary['23']['vat'] ?? 0) + ($vatSummary['22']['vat'] ?? 0);
            $fa->appendChild($xml->createElement('P_13_1', number_format($net, 2, '.', '')));
            $fa->appendChild($xml->createElement('P_14_1', number_format($vat, 2, '.', '')));
            if ($isMulticurrency) {
                $fa->appendChild($xml->createElement('P_14_1W', $bucketW(array('23', '22'), $vat)));
            }
        }

        if (isset($vatSummary['8']) || isset($vatSummary['7'])) {
            $net = ($vatSummary['8']['net'] ?? 0) + ($vatSummary['7']['net'] ?? 0);
            $vat = ($vatSummary['8']['vat'] ?? 0) + ($vatSummary['7']['vat'] ?? 0);
            $fa->appendChild($xml->createElement('P_13_2', number_format($net, 2, '.', '')));
            $fa->appendChild($xml->createElement('P_14_2', number_format($vat, 2, '.', '')));
            if ($isMulticurrency) {
                $fa->appendChild($xml->createElement('P_14_2W', $bucketW(array('8', '7'), $vat)));
            }
        }

        if (isset($vatSummary['5'])) {
            $fa->appendChild($xml->createElement('P_13_3', number_format($vatSummary['5']['net'], 2, '.', '')));
            $fa->appendChild($xml->createElement('P_14_3', number_format($vatSummary['5']['vat'], 2, '.', '')));
            if ($isMulticurrency) {
                $fa->appendChild($xml->createElement('P_14_3W', $bucketW(array('5'), $vatSummary['5']['vat'])));
            }
        }

        // 3%: taxi
        if (isset($vatSummary['4']) || isset($vatSummary['3'])) {
            $net = ($vatSummary['4']['net'] ?? 0) + ($vatSummary['3']['net'] ?? 0);
            $vat = ($vatSummary['4']['vat'] ?? 0) + ($vatSummary['3']['vat'] ?? 0);
            $fa->appendChild($xml->createElement('P_13_4', number_format($net, 2, '.', '')));
            $fa->appendChild($xml->createElement('P_14_4', number_format($vat, 2, '.', '')));
            if ($isMulticurrency) {
                $fa->appendChild($xml->createElement('P_14_4W', $bucketW(array('4', '3'), $vat)));
            }
        }

        if (isset($vatSummary['0 KR'])) {
            $fa->appendChild($xml->createElement('P_13_6_1', number_format($vatSummary['0 KR']['net'], 2, '.', '')));
        }
        if (isset($vatSummary['0 WDT'])) {
            $fa->appendChild($xml->createElement('P_13_6_2', number_format($vatSummary['0 WDT']['net'], 2, '.', '')));
        }
        if (isset($vatSummary['0 EX'])) {
            $fa->appendChild($xml->createElement('P_13_6_3', number_format($vatSummary['0 EX']['net'], 2, '.', '')));
        }
        if (isset($vatSummary['zw'])) {
            $fa->appendChild($xml->createElement('P_13_7', number_format($vatSummary['zw']['net'], 2, '.', '')));
        }
        if (isset($vatSummary['np I'])) {
            $fa->appendChild($xml->createElement('P_13_8', number_format($vatSummary['np I']['net'], 2, '.', '')));
        }
        if (isset($vatSummary['np II'])) {
            $fa->appendChild($xml->createElement('P_13_9', number_format($vatSummary['np II']['net'], 2, '.', '')));
        }
        if (isset($vatSummary['oo'])) {
            $fa->appendChild($xml->createElement('P_13_10', number_format($vatSummary['oo']['net'], 2, '.', '')));
        }
        // marza: gross value
        if (isset($vatSummary['marza'])) {
            $fa->appendChild($xml->createElement('P_13_11', number_format($vatSummary['marza']['gross'], 2, '.', '')));
        }

        $totalAmount = ($useMulticurrency ? $invoice->multicurrency_total_ttc : $invoice->total_ttc) - $this->settlementSum($this->settlement);
        if ($isKorType && $invoice->type == Facture::TYPE_REPLACEMENT && $originalInvoice) {
            $origTotal = ($useMulticurrency ? $originalInvoice->multicurrency_total_ttc : $originalInvoice->total_ttc) - $this->settlementSum($this->origSettlement);
            $totalAmount -= $origTotal;
        }
        if ($invoiceType == 'ROZ' && is_array($this->depositCtx) && $this->depositCtx['mode'] === 'payment') {
            foreach ($this->depositCtx['deposits'] as $dep) {
                $totalAmount -= $useMulticurrency ? $dep['mc_ttc'] : $dep['ttc'];
            }
        }
        $totalAmount -= $korRozPaid['ttc'];
        if (abs($totalAmount) < 0.005) {
            $totalAmount = 0;
        }
        $fa->appendChild($xml->createElement('P_15', number_format($totalAmount, 2, '.', '')));
        $p15 = round((float) $totalAmount, 2);
        $rateZ = $kursWaluty;
        if ($isMulticurrency && in_array($invoiceType, array('ZAL', 'KOR_ZAL'), true) && !$this->noRowKurs) {
            $fa->appendChild($xml->createElement('KursWalutyZ', number_format($rateZ, 6, '.', '')));
        }

        $this->buildAdnotacje($xml, $fa, $invoice, $vatSummary, $originalInvoice, $originalXmlData);

        $fa->appendChild($xml->createElement('RodzajFaktury', $invoiceType));

        $p15Before = null;
        if ($isKorType) {
            $corrReason = $invoice->array_options['options_ksef_correction_reason'] ?? '';
            $corrType = $invoice->array_options['options_ksef_correction_type'] ?? '';
            if (!empty($corrReason)) {
                $fa->appendChild($xml->createElement('PrzyczynaKorekty', $this->xmlSafe($corrReason)));
            }
            if (!empty($corrType) && in_array((int) $corrType, array(1, 2, 3))) {
                $fa->appendChild($xml->createElement('TypKorekty', (int) $corrType));
            }
            if ($originalInvoice) {
                $this->buildDaneFaKorygowanej($xml, $fa, $originalInvoice);
            }
            foreach ($this->absorbedNotes as $note) {
                $this->buildDaneFaKorygowanej($xml, $fa, $note);
            }

            if ($originalXmlData) {
                $this->buildPodmiot1K($xml, $fa, $originalXmlData);
                $this->buildPodmiot2K($xml, $fa, $originalXmlData);
            }
            if (in_array($invoiceType, array('KOR_ZAL', 'KOR_ROZ'), true) && $originalInvoice) {
                $p15Before = $this->buildP15ZK($xml, $fa, $invoice, $originalInvoice, $invoiceType, ($isMulticurrency && !$this->noRowKurs) ? $rateZ : null, $useMulticurrency);
            }
        }
        if ($invoiceType === 'KOR_ZAL' && $this->korPlan !== null) {
            foreach ($this->korPlan['parts'] as $pp) {
                $zc = $xml->createElement('ZaliczkaCzesciowa');
                $zc->appendChild($xml->createElement('P_6Z', $pp['day']));
                $zc->appendChild($xml->createElement('P_15Z', number_format(abs((float) $pp['amount']) < 0.005 ? 0 : (float) $pp['amount'], 2, '.', '')));
                if ($isMulticurrency && (float) $pp['kurs'] > 0) {
                    $zc->appendChild($xml->createElement('KursWalutyZW', number_format((float) $pp['kurs'], 6, '.', '')));
                }
                $fa->appendChild($zc);
            }
        } elseif ($invoiceType === 'KOR_ZAL' && $this->payParts) {
            $weightTotal = 0.0;
            foreach ($this->payParts as $pp) {
                $weightTotal += (float) $pp['amount'];
            }
            $korBefore = $p15Before !== null ? (float) $p15Before : round($weightTotal, 2);
            $allocBefore = ksefDepositKorAlloc($this->payParts, $korBefore);
            $allocAfter = ksefDepositKorAlloc($this->payParts, round($korBefore + $p15, 2));
            foreach (array_values($this->payParts) as $i => $pp) {
                $zc = $xml->createElement('ZaliczkaCzesciowa');
                $zc->appendChild($xml->createElement('P_6Z', $pp['day']));
                $change = round($allocAfter[$i] - $allocBefore[$i], 2);
                $zc->appendChild($xml->createElement('P_15Z', number_format(abs($change) < 0.005 ? 0 : $change, 2, '.', '')));
                if ($isMulticurrency && (float) $pp['kurs'] > 0) {
                    $zc->appendChild($xml->createElement('KursWalutyZW', number_format((float) $pp['kurs'], 6, '.', '')));
                }
                $fa->appendChild($zc);
            }
        }

        // XSD sequence
        if ($invoiceType === 'ZAL') {
            foreach ($this->payParts as $pp) {
                $zc = $xml->createElement('ZaliczkaCzesciowa');
                $zc->appendChild($xml->createElement('P_6Z', $pp['day']));
                $zc->appendChild($xml->createElement('P_15Z', number_format((float) $pp['amount'], 2, '.', '')));
                if ($isMulticurrency && (float) $pp['kurs'] > 0) {
                    $zc->appendChild($xml->createElement('KursWalutyZW', number_format((float) $pp['kurs'], 6, '.', '')));
                }
                $fa->appendChild($zc);
            }
        }

        if ($this->resolveBooleanFlagSource(getDolGlobalString('KSEF_FA3_FP_SOURCE', 'disabled'), $invoice)) {
            $fa->appendChild($xml->createElement('FP', '1'));
        }

        if ($this->isRelatedPartyTransaction($invoice)) {
            $fa->appendChild($xml->createElement('TP', '1'));
        }

        $this->buildDodatkowyOpis($xml, $fa, $invoice);

        // art. 106f(3)/(4)
        $this->buildFakturaZaliczkowa($xml, $fa, $invoiceType);

        $originalForLines = $isKorType ? $originalInvoice : null;
        $this->buildFaWiersz($xml, $fa, $invoice, $originalForLines);

        // XSD sequence
        $this->buildRozliczenie($xml, $fa, $p15, $isKorType && $invoice->type == Facture::TYPE_REPLACEMENT);

        $this->buildPlatnosc($xml, $fa, $invoice);

        $this->buildWarunkiTransakcji($xml, $fa, $invoice);

        // art. 106f(1)(4)
        $this->buildZamowienie($xml, $fa, $invoice, $invoiceType);
    }

    /**
     * @brief Append P_15ZK
     * @param DOMDocument $xml
     * @param DOMElement $fa
     * @param Facture $invoice
     * @param Facture $originalInvoice
     * @param string $invoiceType KOR_ZAL|KOR_ROZ
     * @param float|null $rateZ Deposit rate
     * @param bool $mc Invoice currency amounts
     * @return float|null Null when omitted
     */
    private function buildP15ZK($xml, $fa, $invoice, $originalInvoice, $invoiceType, $rateZ, $mc = false)
    {
        $before = ksefCorrectionStatedBefore($this->db, $invoice);
        if ($before === null && $invoiceType === 'KOR_ZAL' && !empty($this->depositCtx['root_id'])) {
            $v = ksefDepositCurrentValue($this->db, (int) $this->depositCtx['root_id'], (int) $invoice->id);
            $before = $v ? (float) ($v['foreign'] ? $v['mttc'] : $v['ttc']) : null;
        }
        if ($before === null && $invoiceType === 'KOR_ROZ') {
            $before = ($mc ? (float) $originalInvoice->multicurrency_total_ttc : (float) $originalInvoice->total_ttc) - $this->settlementSum($this->origSettlement);
            foreach (ksefConsumedDeposits($this->db, (int) $originalInvoice->id) as $d) {
                if ($d['mode'] === 'payment') {
                    $before -= (float) ($mc ? $d['mc_ttc'] : $d['ttc']);
                }
            }
            $before = (float) price2num($before, 'MT');
        }
        if ($before === null) {
            dol_syslog(__METHOD__ . ' ' . $invoice->ref . ': no sent XML of ' . $originalInvoice->ref . ', P_15ZK left out', LOG_INFO);
            return null;
        }
        $fa->appendChild($xml->createElement('P_15ZK', number_format(abs($before) < 0.005 ? 0 : $before, 2, '.', '')));
        if ($rateZ === null) {
            return (float) $before;
        }
        $rate = $rateZ;
        if ($invoiceType === 'KOR_ROZ' && (float) $originalInvoice->multicurrency_tx > 0) {
            $rate = $this->kursOf($originalInvoice);
        }
        $fa->appendChild($xml->createElement('KursWalutyZK', number_format($rate, 6, '.', '')));
        return (float) $before;
    }

    /**
     * @brief Build FakturaZaliczkowa rows
     * @param DOMDocument $xml
     * @param DOMElement $fa
     * @param string $invoiceType
     */
    private function buildFakturaZaliczkowa($xml, $fa, $invoiceType)
    {
        if (!is_array($this->depositCtx)) {
            return;
        }
        $list = array();
        if ($invoiceType == 'ROZ') {
            $list = $this->depositCtx['deposits'];
        } elseif ($invoiceType == 'ZAL' && !empty($this->depositCtx['last'])) {
            $list = $this->depositCtx['earlier'];
        }
        foreach (array_slice($list, 0, 100) as $dep) {
            $c = $dep['citation'];
            $el = $xml->createElement('FakturaZaliczkowa');
            if (!$c['outside'] && $c['ksef_number'] !== '') {
                $el->appendChild($xml->createElement('NrKSeFFaZaliczkowej', $c['ksef_number']));
            } else {
                $el->appendChild($xml->createElement('NrKSeFZN', '1'));
                $el->appendChild($xml->createElement('NrFaZaliczkowej', $this->xmlSafe($dep['ref'] ?? $c['ref'])));
            }
            $fa->appendChild($el);
        }
    }

    /**
     * @brief Build Zamowienie
     * @param DOMDocument $xml
     * @param DOMElement $fa
     * @param Facture $invoice
     * @param string $invoiceType
     */
    private function buildZamowienie($xml, $fa, $invoice, $invoiceType)
    {
        if (!in_array($invoiceType, array('ZAL', 'KOR_ZAL'), true) || !is_array($this->depositCtx) || empty($this->depositCtx['order'])) {
            return;
        }
        $order = $this->depositCtx['order'];
        $useMulti = $this->currentInvoiceCurrency !== 'PLN';
        $lines = array_values(ksefOrderRowLines($order));
        $z = $xml->createElement('Zamowienie');
        $z->appendChild($xml->createElement('WartoscZamowienia', number_format(ksefOrderValue($order, $this->currentInvoiceCurrency), 2, '.', '')));
        // MF Przyklad 23
        $afterNums = array();
        $byNr = array();
        if ($invoiceType == 'KOR_ZAL') {
            $sent = !empty($this->depositCtx['root_id']) ? ksefDepositSentOrder($this->db, (int) $this->depositCtx['root_id'], (int) $invoice->id) : null;
            if ($sent && $sent['rows']) {
                $afterNums = $this->pairOrderRows($sent, $lines);
                foreach ($sent['rows'] as $i => $fields) {
                    $nr = (int) $sent['nums'][$i];
                    $w = $xml->createElement('ZamowienieWiersz');
                    $w->appendChild($xml->createElement('NrWierszaZam', $nr));
                    foreach ($fields as $f) {
                        $w->appendChild($xml->createElement($f[0], $this->xmlSafe($f[1])));
                    }
                    $w->appendChild($xml->createElement('StanPrzedZ', '1'));
                    $byNr[$nr][] = $w;
                }
            } else {
                $n = 1;
                foreach ($lines as $line) {
                    $byNr[$n][] = $this->orderRow($xml, $line, $n, $useMulti, true);
                    $n++;
                }
            }
        }
        $n = 1;
        foreach ($lines as $i => $line) {
            $nr = isset($afterNums[$i]) ? (int) $afterNums[$i] : $n;
            $byNr[$nr][] = $this->orderRow($xml, $line, $nr, $useMulti, false);
            $n++;
        }
        if (!$byNr) {
            dol_syslog("FA3Builder::buildZamowienie invoice " . (int) $invoice->id . ": the linked order has no lines with a quantity or value, Zamowienie left out", LOG_WARNING);
            return;
        }
        ksort($byNr);
        foreach ($byNr as $rows) {
            foreach ($rows as $w) {
                $z->appendChild($w);
            }
        }
        $fa->appendChild($z);
    }

    /**
     * @brief Pair order rows
     * @param array $sent Sent order
     * @param array $lines
     * @return array Line key => NrWierszaZam
     */
    private function pairOrderRows($sent, $lines)
    {
        $sentKeys = array();
        foreach ($sent['rows'] as $i => $fields) {
            $k = array('', '');
            foreach ($fields as $f) {
                if ($f[0] === 'P_7Z') {
                    $k[0] = trim((string) $f[1]);
                } elseif ($f[0] === 'IndeksZ') {
                    $k[1] = trim((string) $f[1]);
                }
            }
            $sentKeys[$i] = $k;
        }
        $free = $sentKeys;
        $out = array();
        $unmatched = array();
        foreach ($lines as $li => $line) {
            $k = $this->orderRowKey($line);
            foreach ($free as $i => $sk) {
                if ($sk[0] === $k[0] && ($sk[1] === '' || $k[1] === '' || $sk[1] === $k[1])) {
                    $out[$li] = (int) $sent['nums'][$i];
                    unset($free[$i]);
                    continue 2;
                }
            }
            $unmatched[] = $li;
        }
        $next = (int) $sent['max_nr'];
        foreach ($unmatched as $li) {
            if ($free) {
                reset($free);
                $i = key($free);
                $out[$li] = (int) $sent['nums'][$i];
                unset($free[$i]);
            } else {
                $out[$li] = ++$next;
            }
        }
        return $out;
    }

    /**
     * @brief Order line key
     * @param object $line
     * @return array P_7Z, IndeksZ
     */
    private function orderRowKey($line)
    {
        $label = $this->orderRowLabel($line);
        $indeks = '';
        if (getDolGlobalString('KSEF_FA3_INCLUDE_INDEKS') && !empty($line->product_ref)) {
            $indeks = trim(substr((string) $line->product_ref, 0, 50));
        }
        return array($label !== '' ? trim(dol_trunc($label, 512, 'right', 'UTF-8', 1)) : '', $indeks);
    }

    /**
     * @brief Order line label
     * @param object $line
     * @return string
     */
    private function orderRowLabel($line)
    {
        $label = trim(dol_string_nohtmltag((string) ($line->desc ?: ($line->product_label ?? $line->label ?? ''))));
        if ($label === '' && !empty($line->product_label)) {
            $label = (string) $line->product_label;
        }
        return $label;
    }

    /**
     * @brief Build ZamowienieWiersz
     * @param DOMDocument $xml
     * @param object $line
     * @param int $n NrWierszaZam
     * @param bool $useMulti Non-PLN currency
     * @param bool $before StanPrzedZ row
     * @return DOMElement
     */
    private function orderRow($xml, $line, $n, $useMulti, $before)
    {
        $w = $xml->createElement('ZamowienieWiersz');
        $w->appendChild($xml->createElement('NrWierszaZam', $n));
        $label = $this->orderRowLabel($line);
        if ($label !== '') {
            $w->appendChild($xml->createElement('P_7Z', $this->xmlSafe(dol_trunc($label, 512, 'right', 'UTF-8', 1))));
        }
        if (getDolGlobalString('KSEF_FA3_INCLUDE_INDEKS') && !empty($line->product_ref)) {
            $w->appendChild($xml->createElement('IndeksZ', $this->xmlSafe(substr((string) $line->product_ref, 0, 50))));
        }
        if (getDolGlobalInt('KSEF_FA3_INCLUDE_UNIT')) {
            $unit = ksefUnitText($this->db, (int) ($line->fk_unit ?? 0));
            if ($unit !== '') {
                $w->appendChild($xml->createElement('P_8AZ', $this->xmlSafe(mb_substr($unit, 0, 256, 'UTF-8'))));
            }
        }
        $w->appendChild($xml->createElement('P_8BZ', ksefFormatDecimal((float) $line->qty, 6)));
        $pu = $useMulti ? (float) $line->multicurrency_subprice : (float) $line->subprice;
        $w->appendChild($xml->createElement('P_9AZ', ksefFormatDecimal($pu, 8)));
        $w->appendChild($xml->createElement('P_11NettoZ', number_format($useMulti ? (float) $line->multicurrency_total_ht : (float) $line->total_ht, 2, '.', '')));
        $w->appendChild($xml->createElement('P_11VatZ', number_format($useMulti ? (float) $line->multicurrency_total_tva : (float) $line->total_tva, 2, '.', '')));
        $rate = ksefForeignRateKey($line, (string) $this->currentCustomerCountry, !empty($this->currentCustomerIsEU));
        if ($rate !== 'marza') {
            $w->appendChild($xml->createElement('P_12Z', $rate));
        }
        if ($before) {
            $w->appendChild($xml->createElement('StanPrzedZ', '1'));
        }
        return $w;
    }

    /**
     * @brief VAT by rate
     * @param Facture $invoice
     * @param bool $useMulticurrency
     * @return array
     */
    private function calculateVatSummary($invoice, $useMulticurrency = false)
    {
        $s = ksefForeignRateSummary((array) $invoice->lines, array(
            'key' => function ($line) {
                return $this->rateKey($line);
            },
            'gross' => $this->grossMethod,
            'pln' => !$useMulticurrency,
        ));
        $summary = array();
        foreach ($s['keys'] as $key => $a) {
            $summary[$key] = array('net' => $a['net'], 'vat' => $a['vat'], 'gross' => $a['gross']);
        }
        return $summary;
    }

    /**
     * @brief Invoice rate K
     * @param Facture $invoice
     * @param array $opt Kurs options
     * @return float|null Null without rate
     */
    private function kursOf($invoice, $opt = array())
    {
        $tx = (float) ($invoice->multicurrency_tx ?? 0);
        if ($tx <= 0) {
            return null;
        }
        $k = ksefInvoiceKurs($invoice, array('db' => $this->db) + $opt);
        return $k > 0 ? $k : round(1 / $tx, 6);
    }

    /**
     * @brief Row KursWaluty
     * @param object $line
     * @param float|null $override
     * @return float|null
     */
    private function rowKurs($line, $override = null)
    {
        if ($this->noRowKurs) {
            return null;
        }
        if ($this->payParts && $this->currentKursWaluty > 0) {
            return $this->currentKursWaluty;
        }
        if ($this->lineKursResolve !== null) {
            $k = (float) call_user_func($this->lineKursResolve, $line);
            if ($k > 0) {
                return $k;
            }
        }
        return $override !== null ? $override : $this->currentKursWaluty;
    }

    /**
     * @brief Gross-method VAT summary
     * @param array $summary
     * @return array
     */
    private function grossVatSummary($summary)
    {
        foreach ($summary as $key => $a) {
            $gross = round((float) ($a['gross'] ?? 0), 2);
            if (preg_match('/^\d+$/', (string) $key)) {
                $r = (float) $key;
                $vat = round($gross * $r / (100 + $r), 2);
                $summary[$key]['vat'] = $vat;
                $summary[$key]['net'] = $gross - $vat;
            } else {
                $summary[$key]['vat'] = 0;
                $summary[$key]['net'] = $gross;
            }
        }
        return $summary;
    }

    /**
     * @brief Line rate key
     * @param object $line
     * @return string
     */
    private function rateKey($line)
    {
        if (!empty($line->id) && isset($this->legacyZeroKeys[(int) $line->id])) {
            return $this->legacyZeroKeys[(int) $line->id];
        }
        return ksefForeignRateKey($line, (string) $this->currentCustomerCountry, !empty($this->currentCustomerIsEU));
    }

    /**
     * @brief KOR_ROZ deposit shift
     * @param Facture $invoice
     * @param bool $mc Invoice currency amounts
     * @return array
     */
    private function korRozPaymentShift($invoice, $mc)
    {
        $out = array('rates' => array(), 'ttc' => 0.0, 'new' => array(), 'old' => array());
        $state = ksefCorrectionState($invoice);
        if (empty($state['dep_paid'])) {
            return $out;
        }
        $consumed = array();
        foreach (ksefConsumedDeposits($this->db, (int) $invoice->id) as $d) {
            if ($d['mode'] === 'payment') {
                $consumed[] = $d;
                $out['ttc'] += (float) ($mc ? $d['mc_ttc'] : $d['ttc']);
            }
        }
        foreach ($state['dep_paid'] as $d) {
            $out['ttc'] -= (float) ($mc ? $d['mc_ttc'] : $d['ttc']);
        }
        $out['new'] = $this->depositRates($consumed, $mc);
        $out['old'] = $this->depositRates($state['dep_paid'], $mc);
        foreach (array('new' => 1, 'old' => -1) as $side => $sign) {
            foreach ($out[$side] as $key => $amt) {
                $out['rates'][$key]['net'] = (float) ($out['rates'][$key]['net'] ?? 0) + $sign * $amt['net'];
                $out['rates'][$key]['vat'] = (float) ($out['rates'][$key]['vat'] ?? 0) + $sign * $amt['vat'];
            }
        }
        return $out;
    }

    /**
     * @brief Deposit rates
     * @param array $deposits
     * @param bool $mc Invoice currency amounts
     * @return array
     */
    private function depositRates($deposits, $mc)
    {
        $out = array();
        foreach ((array) $deposits as $d) {
            foreach ((array) $d['rates'] as $rate => $amt) {
                $key = $this->rateKey((object) array('tva_tx' => $rate, 'vat_src_code' => (string) ($amt['code'] ?? '')));
                $out[$key]['net'] = (float) ($out[$key]['net'] ?? 0) + (float) ($mc ? $amt['mc_ht'] : $amt['ht']);
                $out[$key]['vat'] = (float) ($out[$key]['vat'] ?? 0) + (float) ($mc ? $amt['mc_tva'] : $amt['tva']);
            }
        }
        return $out;
    }

    /**
     * @brief Summary less deposits
     * @param array $summary
     * @param array $deposits
     * @return array
     */
    private function lessDeposits($summary, $deposits)
    {
        foreach ($deposits as $key => $amt) {
            if (!isset($summary[$key])) {
                $summary[$key] = array('net' => 0, 'vat' => 0, 'gross' => 0);
            }
            $summary[$key]['net'] -= $amt['net'];
            $summary[$key]['vat'] -= $amt['vat'];
            $summary[$key]['gross'] = ($summary[$key]['gross'] ?? 0) - $amt['net'] - $amt['vat'];
        }
        return $summary;
    }

    /**
     * @brief Parts less deposits
     * @param array $keys
     * @param array $deposits
     * @param float $kurs Invoice rate
     * @return array
     */
    private function partsLessDeposits($keys, $deposits, $kurs)
    {
        $pk = number_format(round((float) $kurs, 6), 6, '.', '');
        foreach ((array) $deposits as $key => $amt) {
            $d = array('net' => (float) $amt['net'], 'vat' => (float) $amt['vat'], 'gross' => (float) $amt['net'] + (float) $amt['vat']);
            if (!isset($keys[$key])) {
                $keys[$key] = array('rate' => preg_match('/^\d+$/', (string) $key) ? (float) $key : null, 'net' => 0.0, 'vat' => 0.0, 'gross' => 0.0);
            }
            foreach (array('net', 'vat', 'gross') as $c) {
                $keys[$key][$c] = round((float) $keys[$key][$c] - $d[$c], 2);
                if (!empty($keys[$key]['parts'])) {
                    $keys[$key]['parts'][$pk][$c] = round((float) ($keys[$key]['parts'][$pk][$c] ?? 0) - $d[$c], 2);
                }
            }
        }
        return $keys;
    }

    /**
     * @brief Settlement total
     * @param array $list
     * @return float
     */
    private function settlementSum($list)
    {
        $sum = 0.0;
        foreach ($list as $s) {
            $sum += (float) $s['ttc'];
        }
        return $sum;
    }

    /**
     * @brief Build Rozliczenie
     * @param DOMDocument $xml
     * @param DOMElement $fa
     * @param float $p15
     * @param bool $isReplacement
     */
    private function buildRozliczenie($xml, $fa, $p15, $isReplacement)
    {
        $pl = ksefPolishLangs();
        $rows = array();
        if ($isReplacement) {
            $delta = round(-$this->settlementSum($this->settlement) + $this->settlementSum($this->origSettlement), 2);
            if (abs($delta) >= 0.005) {
                $s = $this->settlement ? $this->settlement[0] : $this->origSettlement[0];
                $rows[] = array($delta, $s);
            }
        } else {
            foreach ($this->settlement as $s) {
                $rows[] = array(round(-(float) $s['ttc'], 2), $s);
            }
        }
        if (!$rows) {
            return;
        }
        if (count($rows) > 100) {
            $tail = array_slice($rows, 99);
            $sumTail = 0.0;
            foreach ($tail as $r) {
                $sumTail += $r[0];
            }
            $rows = array_slice($rows, 0, 99);
            $rows[] = array($sumTail, $tail[0][1]);
            dol_syslog(__METHOD__ . ' more than 100 settlement lines: the rest summed into one Odliczenia', LOG_WARNING);
        }
        $roz = $xml->createElement('Rozliczenie');
        $sum = 0.0;
        foreach ($rows as $r) {
            list($amount, $s) = $r;
            $sum += $amount;
            $key = ($s['kind'] === 'excess') ? 'KSEF_SettlementReasonExcess' : 'KSEF_SettlementReasonCreditNote';
            $od = $xml->createElement('Odliczenia');
            $od->appendChild($xml->createElement('Kwota', number_format($amount, 2, '.', '')));
            $od->appendChild($xml->createElement('Powod', $this->xmlSafe(trim($pl->transnoentitiesnoconv($key, $s['ref'])))));
            $roz->appendChild($od);
        }
        $roz->appendChild($xml->createElement('SumaOdliczen', number_format($sum, 2, '.', '')));
        $rest = round($p15 - $sum, 2);
        if ($rest >= 0) {
            $roz->appendChild($xml->createElement('DoZaplaty', number_format($rest, 2, '.', '')));
        } else {
            $roz->appendChild($xml->createElement('DoRozliczenia', number_format(-$rest, 2, '.', '')));
        }
        $fa->appendChild($roz);
    }

    /**
     * @brief FaWiersz lines
     * @param Facture $invoice
     * @return array
     */
    private function linesForXml($invoice)
    {
        $type = is_array($this->depositCtx) ? $this->depositCtx['type'] : '';
        if ($type !== 'ROZ' && $type !== 'KOR_ROZ') {
            return array_values((array) $invoice->lines);
        }
        $db = $this->db;
        return array_values(array_filter((array) $invoice->lines, function ($l) use ($db) {
            return !ksefIsDepositLine($db, $l);
        }));
    }

    /**
     * @brief Korekta supply dates
     * @param Facture $invoice
     * @param array $xmlLines
     * @return array
     */
    private function correctionSupply($invoice, $xmlLines)
    {
        $out = array('mode' => 'p6', 'p6' => dol_print_date(ksefGetSaleDate($invoice), '%Y-%m-%d', 'tzserver'), 'from' => '', 'to' => '', 'lines' => array());
        $members = array();
        $cur = (int) $invoice->fk_facture_source;
        $type = 0;
        for ($i = 0; $cur > 0 && $i < 20 && !in_array($cur, $members, true); $i++) {
            $members[] = $cur;
            $res = $this->db->query("SELECT type, fk_facture_source FROM " . MAIN_DB_PREFIX . "facture WHERE rowid = " . $cur);
            $o = $res ? $this->db->fetch_object($res) : null;
            $type = $o ? (int) $o->type : 0;
            $cur = ($o && $type === Facture::TYPE_REPLACEMENT) ? (int) $o->fk_facture_source : 0;
        }
        if (!$members) {
            return $out;
        }
        $supply = null;
        $srcId = 0;
        foreach ($members as $id) {
            $supply = $this->sentSupply(ksefLatestSentXml($this->db, array($id)));
            if ($supply !== null) {
                $srcId = $id;
                break;
            }
        }
        $src = new Facture($this->db);
        if ($supply === null) {
            $srcId = in_array($type, array(Facture::TYPE_REPLACEMENT, Facture::TYPE_CREDIT_NOTE), true) ? (int) $members[0] : (int) end($members);
            if ($src->fetch($srcId) <= 0) {
                return $out;
            }
            $src->fetch_optionals();
            $src->fetch_lines();
            $srcLines = $this->linesForXml($src);
            $supply = ksefInvoiceSupplyMode($src, $srcLines);
            $rows = array();
            foreach ($srcLines as $k => $l) {
                $rows[] = $supply['lines'][$k] ?? '';
            }
        } else {
            $rows = $supply['rows'];
        }
        $out = array('mode' => $supply['mode'], 'p6' => $supply['p6'], 'from' => $supply['from'], 'to' => $supply['to'], 'lines' => array());
        if ($out['mode'] !== 'p6a') {
            return $out;
        }
        if (empty($src->id)) {
            $src->fetch($srcId);
            $src->fetch_lines();
        }
        $srcLines = $this->linesForXml($src);
        $byLine = array();
        $lineRows = $rows;
        if (count($srcLines) !== count($rows)) {
            $own = ksefSupplyDates($srcLines, $out['p6']);
            $lineRows = array();
            foreach ($srcLines as $k => $l) {
                $lineRows[$k] = $own['lines'][$k] ?? '';
            }
        }
        foreach (array_values($srcLines) as $k => $l) {
            if (($lineRows[$k] ?? '') !== '') {
                $byLine[(int) $l->id] = $lineRows[$k];
            }
        }
        $known = array_values(array_filter($rows));
        $out['by_id'] = $byLine;
        $out['fallback'] = count(array_unique($known)) === 1 ? $known[0] : ($known ? max($known) : $out['p6']);
        foreach ($xmlLines as $k => $l) {
            $out['lines'][$k] = $this->supplyDateOf((int) ($l->fk_prev_id ?? 0), $byLine, $out['fallback']);
        }
        return $out;
    }

    /**
     * @brief Line supply date
     * @param int $lineId
     * @param array $byLine
     * @param string $fallback
     * @return string
     */
    private function supplyDateOf($lineId, $byLine, $fallback)
    {
        $prev = (int) $lineId;
        for ($hop = 0; $prev > 0 && !isset($byLine[$prev]) && $hop < 5; $hop++) {
            $res = $this->db->query("SELECT fk_prev_id FROM " . MAIN_DB_PREFIX . "facturedet WHERE rowid = " . $prev);
            $o = $res ? $this->db->fetch_object($res) : null;
            $prev = $o ? (int) $o->fk_prev_id : 0;
        }
        return ($prev > 0 && isset($byLine[$prev])) ? $byLine[$prev] : $fallback;
    }

    /**
     * @brief Row P_6A date
     * @param object $line
     * @return string|null
     */
    private function lineDate($line)
    {
        if (isset($this->lineDates[spl_object_id($line)])) {
            return $this->lineDates[spl_object_id($line)];
        }
        if ($this->sourceLineDates === null) {
            return null;
        }
        return $this->supplyDateOf((int) ($line->id ?? ($line->rowid ?? 0)), $this->sourceLineDates['by_id'], $this->sourceLineDates['fallback']);
    }

    /**
     * @brief Sent supply dates
     * @param string $xml
     * @return array|null Null without XML
     */
    private function sentSupply($xml)
    {
        $doc = new DOMDocument();
        if (!is_string($xml) || $xml === '' || !@$doc->loadXML($xml)) {
            return null;
        }
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('fa', KSEF_FA3_NAMESPACE);
        $val = function ($q, $ctx = null) use ($xp) {
            $n = $ctx ? $xp->query($q, $ctx) : $xp->query($q);
            return ($n && $n->length && preg_match('/^\s*(\d{4}-\d{2}-\d{2})\s*$/', $n->item(0)->textContent, $m)) ? $m[1] : '';
        };
        $out = array('mode' => 'p6', 'p6' => $val('//fa:Fa/fa:P_6'), 'from' => $val('//fa:Fa/fa:OkresFa/fa:P_6_Od'), 'to' => $val('//fa:Fa/fa:OkresFa/fa:P_6_Do'), 'rows' => array());
        if ($out['from'] !== '' && $out['to'] !== '') {
            $out['mode'] = 'okres';
            return $out;
        }
        $any = false;
        foreach ($xp->query('//fa:Fa/fa:FaWiersz[not(fa:StanPrzed)]') as $w) {
            $d = $val('fa:P_6A', $w);
            $any = $any || $d !== '';
            $out['rows'][] = $d;
        }
        if ($any && $out['p6'] === '') {
            $out['mode'] = 'p6a';
            return $out;
        }
        if ($out['p6'] === '') {
            $out['p6'] = $val('//fa:Fa/fa:P_1');
        }
        $out['rows'] = array();
        return $out['p6'] !== '' ? $out : null;
    }

    /**
     * @brief Extrafield date
     * @param Facture $invoice
     * @param string $name Extrafield code
     * @return string Y-m-d or empty
     */
    private function extrafieldDay($invoice, $name)
    {
        $v = $invoice->array_options['options_' . $name] ?? '';
        if ($v === '' || $v === null) {
            return '';
        }
        if (is_numeric($v)) {
            return ((int) $v > 0) ? dol_print_date((int) $v, '%Y-%m-%d', 'tzserver') : '';
        }
        return preg_match('/^(\d{4}-\d{2}-\d{2})/', (string) $v, $m) ? $m[1] : '';
    }

    /**
     * @brief FA(3) invoice type
     * @param Facture $invoice
     * @return string
     */
    private function getInvoiceType($invoice)
    {
        if (is_array($this->depositCtx) && !empty($this->depositCtx['type'])) {
            return $this->depositCtx['type'];
        }
        switch ($invoice->type) {
            case Facture::TYPE_CREDIT_NOTE:
                return 'KOR';
            case Facture::TYPE_DEPOSIT:
                return 'ZAL';
            case Facture::TYPE_REPLACEMENT:
                return 'KOR';
            case Facture::TYPE_STANDARD:
            case Facture::TYPE_SITUATION:
            default:
                return 'VAT';
        }
    }

    /**
     * @brief Take settlement lines
     * @param Facture $invoice
     * @return array
     */
    private function takeSettlementLines($invoice)
    {
        $out = array();
        if (empty($invoice->lines) || (int) $invoice->type === Facture::TYPE_DEPOSIT || (int) $invoice->type === Facture::TYPE_CREDIT_NOTE
            || ($this->depositCtx['type'] ?? '') === 'KOR_ZAL') {
            return $out;
        }
        $useMulti = ksefGetInvoiceCurrency($invoice) !== 'PLN';
        $keep = array();
        foreach ($invoice->lines as $l) {
            $s = ksefSettlementLine($this->db, $l);
            if ($s === null) {
                $keep[] = $l;
                continue;
            }
            $s['ttc'] = (float) ($useMulti ? $l->multicurrency_total_ttc : $l->total_ttc);
            $out[] = $s;
        }
        if ($out) {
            $invoice->lines = $keep;
        }
        return $out;
    }

    /**
     * @brief Build DaneFaKorygowanej
     * @param DOMDocument $xml
     * @param DOMElement $parent
     * @param Facture $originalInvoice
     * @throws Exception
     */
    private function buildDaneFaKorygowanej($xml, $parent, $originalInvoice)
    {
        $daneFaKor = $xml->createElement('DaneFaKorygowanej');
        $parent->appendChild($daneFaKor);

        $daneFaKor->appendChild($xml->createElement('DataWystFaKorygowanej', dol_print_date($originalInvoice->date, '%Y-%m-%d', 'tzserver')));
        $daneFaKor->appendChild($xml->createElement('NrFaKorygowanej', $this->xmlSafe($originalInvoice->ref)));

        $originalKsefNumber = $this->getInvoiceKsefNumber($originalInvoice->id);

        if ($originalKsefNumber) {
            $daneFaKor->appendChild($xml->createElement('NrKSeF', '1'));
            $daneFaKor->appendChild($xml->createElement('NrKSeFFaKorygowanej', $originalKsefNumber));
        } elseif ($await = ksefSourceAwaitingRow($this->db, (int) $originalInvoice->id)) {
            // art. 106nda(12)
            throw new Exception(ksefCorrectionAwaitingRefusal(array('source_id' => (int) $originalInvoice->id, 'source_ref' => (string) $originalInvoice->ref,
                'awaiting_kind' => $await['kind']), false));
        } else {
            $daneFaKor->appendChild($xml->createElement('NrKSeFN', '1'));
        }
    }

    /**
     * @brief Source XML data
     * @param int $originalInvoiceId
     * @return array|null
     */
    private function fetchOriginalXmlData($originalInvoiceId)
    {
        dol_include_once('/ksef/class/ksef_submission.class.php');
        dol_include_once('/ksef/lib/ksef.lib.php');
        $rowId = ksefSentXmlRowId($this->db, $originalInvoiceId);
        $submission = new KsefSubmission($this->db);
        if ($rowId <= 0 || $submission->fetch($rowId) <= 0 || empty($submission->fa3_xml)) {
            return null;
        }

        $origXml = new DOMDocument();
        if (!@$origXml->loadXML($submission->fa3_xml)) {
            return null;
        }

        $data = $this->extractPodmiotData($origXml);
        $data['sent_rows'] = $this->sentRows($origXml);
        $xpath = new DOMXPath($origXml);
        $xpath->registerNamespace('fa', KSEF_FA3_NAMESPACE);
        $data['p18'] = $this->xpathValue($xpath, '//fa:Fa/fa:Adnotacje/fa:P_18');
        return $data;
    }

    /**
     * @brief Sent FaWiersz rows
     * @param DOMDocument $origXml
     * @return array
     */
    private function sentRows($origXml)
    {
        $xpath = new DOMXPath($origXml);
        $xpath->registerNamespace('fa', KSEF_FA3_NAMESPACE);
        $rows = array();
        foreach ($xpath->query('//fa:Fa/fa:FaWiersz[not(fa:StanPrzed)]') as $node) {
            $rows[] = array(
                'nr' => $this->xpathValue($xpath, 'fa:NrWierszaFa', $node),
                'p7' => $this->xpathValue($xpath, 'fa:P_7', $node),
                'p12' => $this->xpathValue($xpath, 'fa:P_12', $node),
            );
        }
        return $rows;
    }

    /**
     * @brief Legacy 0% keys
     * @param Facture $invoice
     * @param Facture $originalInvoice
     * @param array $sentRows
     * @return array Line id => P_12
     */
    private function legacyZeroKeys($invoice, $originalInvoice, $sentRows)
    {
        $keys = array();
        $origLines = ($originalInvoice && !empty($originalInvoice->lines)) ? array_values($originalInvoice->lines) : array();
        foreach ($origLines as $i => $line) {
            $old = $this->oldZeroKey($line);
            if ($old === '') {
                continue;
            }
            $p7 = $this->lineP7Text($line);
            $match = null;
            foreach ($sentRows as $row) {
                if ((string) $row['nr'] === (string) ($i + 1) && $row['p7'] === $p7) {
                    $match = $row;
                    break;
                }
            }
            if ($match === null) {
                $same = array_values(array_filter($sentRows, function ($row) use ($p7) {
                    return $row['p7'] === $p7;
                }));
                $match = count($same) === 1 ? $same[0] : null;
            }
            if ($match !== null && $match['p12'] === $old) {
                $keys[(int) $line->id] = $old;
            }
        }
        if (empty($keys)) {
            return $keys;
        }
        foreach ($this->matchCorrectionLines($origLines, array_values((array) $invoice->lines)) as $pair) {
            if ($pair['orig'] && $pair['corr'] && isset($keys[(int) $pair['orig']->id])) {
                $old = $this->oldZeroKey($pair['corr']);
                if ($old !== '') {
                    $keys[(int) $pair['corr']->id] = $old;
                }
            }
        }
        return $keys;
    }

    /**
     * @brief Legacy 0% key
     * @param object $line
     * @return string Empty if unchanged
     */
    private function oldZeroKey($line)
    {
        if (empty($line->id) || (int) round((float) $line->tva_tx) !== 0 || ksefMarginSubtype($line) !== '') {
            return '';
        }
        $code = $line->vat_src_code ?? '';
        $country = (string) $this->currentCustomerCountry;
        $isEu = !empty($this->currentCustomerIsEU);
        $old = ksefZeroRateKey($code, $country, $isEu, false);
        return $old !== ksefZeroRateKey($code, $country, $isEu, (int) ($line->product_type ?? 0) === 1) ? $old : '';
    }

    /**
     * @brief Line P_7 text
     * @param object $line
     * @return string
     */
    public function lineP7Text($line)
    {
        $doc = new DOMDocument();
        return $doc->createElement('P_7', $this->xmlSafe(mb_substr(self::coreLineMarkers($this->lineDescription($line)), 0, 512, 'UTF-8')))->textContent;
    }

    /**
     * @brief Rebuild source data
     * @param Facture $originalInvoice
     * @return array|null
     */
    private function reconstructOriginalXmlData($originalInvoice)
    {
        if ($this->reconstructing) {
            return null;
        }
        $saved = array(
            $this->currentCustomer, $this->currentCustomerCountry, $this->currentCustomerIsEU,
            $this->currentInvoiceCurrency, $this->currentKursWaluty,
            $this->lastInvoiceType, $this->lastCreationDate, $this->lastXmlHash,
            $this->error, $this->errors, $this->depositCtx,
            $this->grossMethod, $this->lineDates, $this->sourceLineDates, $this->settlement, $this->origSettlement,
            $this->lineKursResolve, $this->payParts, $this->noRowKurs, $this->korPlan,
        );
        $this->reconstructing = true;
        $xmlString = $this->buildFromInvoice($originalInvoice->id);
        $this->reconstructing = false;
        list($this->currentCustomer, $this->currentCustomerCountry, $this->currentCustomerIsEU,
            $this->currentInvoiceCurrency, $this->currentKursWaluty,
            $this->lastInvoiceType, $this->lastCreationDate, $this->lastXmlHash,
            $this->error, $this->errors, $this->depositCtx,
            $this->grossMethod, $this->lineDates, $this->sourceLineDates, $this->settlement, $this->origSettlement,
            $this->lineKursResolve, $this->payParts, $this->noRowKurs, $this->korPlan) = $saved;

        if (!is_string($xmlString) || $xmlString === '') {
            dol_syslog("FA3Builder::reconstructOriginalXmlData - could not rebuild original "
                . $originalInvoice->id . " for the correction before-state; Podmiot1K/2K omitted", LOG_WARNING);
            return null;
        }
        $doc = new DOMDocument();
        if (!@$doc->loadXML($xmlString)) {
            return null;
        }
        return $this->extractPodmiotData($doc);
    }

    /**
     * @brief Extract Podmiot data
     * @param DOMDocument $origXml
     * @return array
     */
    private function extractPodmiotData($origXml)
    {
        $xpath = new DOMXPath($origXml);
        $xpath->registerNamespace('fa', KSEF_FA3_NAMESPACE);

        $data = array('podmiot1' => array(), 'podmiot2' => array());

        $data['podmiot1']['prefiks_podatnika'] = $this->xpathValue($xpath, '//fa:Podmiot1/fa:PrefiksPodatnika');
        $data['podmiot1']['nip'] = $this->xpathValue($xpath, '//fa:Podmiot1/fa:DaneIdentyfikacyjne/fa:NIP');
        $data['podmiot1']['nazwa'] = $this->xpathValue($xpath, '//fa:Podmiot1/fa:DaneIdentyfikacyjne/fa:Nazwa');
        $data['podmiot1']['nr_eori'] = $this->xpathValue($xpath, '//fa:Podmiot1/fa:NrEORI');
        $data['podmiot1']['gln'] = $this->xpathValue($xpath, '//fa:Podmiot1/fa:Adres/fa:GLN');
        $data['podmiot1']['kod_kraju'] = $this->xpathValue($xpath, '//fa:Podmiot1/fa:Adres/fa:KodKraju');
        $data['podmiot1']['adres_l1'] = $this->xpathValue($xpath, '//fa:Podmiot1/fa:Adres/fa:AdresL1');
        $data['podmiot1']['adres_l2'] = $this->xpathValue($xpath, '//fa:Podmiot1/fa:Adres/fa:AdresL2');

        $data['podmiot2']['nip'] = $this->xpathValue($xpath, '//fa:Podmiot2/fa:DaneIdentyfikacyjne/fa:NIP');
        $data['podmiot2']['kod_ue'] = $this->xpathValue($xpath, '//fa:Podmiot2/fa:DaneIdentyfikacyjne/fa:KodUE');
        $data['podmiot2']['nr_vat_ue'] = $this->xpathValue($xpath, '//fa:Podmiot2/fa:DaneIdentyfikacyjne/fa:NrVatUE');
        $data['podmiot2']['kod_kraju_id'] = $this->xpathValue($xpath, '//fa:Podmiot2/fa:DaneIdentyfikacyjne/fa:KodKraju');
        $data['podmiot2']['nr_id'] = $this->xpathValue($xpath, '//fa:Podmiot2/fa:DaneIdentyfikacyjne/fa:NrID');
        $data['podmiot2']['brak_id'] = $this->xpathValue($xpath, '//fa:Podmiot2/fa:DaneIdentyfikacyjne/fa:BrakID');
        $data['podmiot2']['nazwa'] = $this->xpathValue($xpath, '//fa:Podmiot2/fa:DaneIdentyfikacyjne/fa:Nazwa');
        $data['podmiot2']['id_nabywcy'] = $this->xpathValue($xpath, '//fa:Podmiot2/fa:IDNabywcy');
        $data['podmiot2']['nr_eori'] = $this->xpathValue($xpath, '//fa:Podmiot2/fa:NrEORI');
        $data['podmiot2']['gln'] = $this->xpathValue($xpath, '//fa:Podmiot2/fa:Adres/fa:GLN');
        $data['podmiot2']['kod_kraju'] = $this->xpathValue($xpath, '//fa:Podmiot2/fa:Adres/fa:KodKraju');
        $data['podmiot2']['adres_l1'] = $this->xpathValue($xpath, '//fa:Podmiot2/fa:Adres/fa:AdresL1');
        $data['podmiot2']['adres_l2'] = $this->xpathValue($xpath, '//fa:Podmiot2/fa:Adres/fa:AdresL2');

        // compare escaped form
        foreach ($data as $k => $p) {
            $data[$k] = array_map('ksefXmlEscape', $p);
        }
        return $data;
    }

    /**
     * @brief First XPath text
     * @param DOMXPath $xpath
     * @param string $expression
     * @param DOMNode|null $context
     * @return string
     */
    private function xpathValue($xpath, $expression, $context = null)
    {
        $nodes = $context !== null ? $xpath->query($expression, $context) : $xpath->query($expression);
        return ($nodes && $nodes->length > 0) ? $nodes->item(0)->textContent : '';
    }

    /**
     * @brief Build Podmiot1K
     * @param DOMDocument $xml
     * @param DOMElement $parent
     * @param array $originalXmlData
     */
    private function buildPodmiot1K($xml, $parent, $originalXmlData)
    {
        global $conf, $mysoc;

        $orig = $originalXmlData['podmiot1'];

        $currentPrefiks = '';
        $buyerCountry = ksefInferCountryCode($this->currentCustomer);
        if ($buyerCountry != 'PL' && !empty($mysoc->tva_intra)) {
            $vatIntra = strtoupper(trim($mysoc->tva_intra));
            if (preg_match('/^([A-Z]{2})/', $vatIntra, $matches)) {
                $currentPrefiks = $matches[1];
            }
        }

        $nip = ksefSellerNip();
        $currentNazwa = $this->xmlSafe($mysoc->name);

        $currentNrEori = getDolGlobalString('KSEF_COMPANY_EORI', '');
        if (empty($currentNrEori)) {
            $currentNrEori = trim(ksefGetIdentifierField($mysoc, 'EORI'));
        }
        $currentNrEori = ksefXmlEscape($currentNrEori);

        $currentKodKraju = $mysoc->country_code ?: 'PL';
        $currentAdresL1 = !empty($mysoc->address) ? $this->xmlSafe($mysoc->address) : '';
        $adresL2Parts = array();
        if (!empty($mysoc->zip)) $adresL2Parts[] = $this->xmlSafe($mysoc->zip);
        if (!empty($mysoc->town)) $adresL2Parts[] = $this->xmlSafe($mysoc->town);
        $currentAdresL2 = implode(' ', $adresL2Parts);

        if (($orig['prefiks_podatnika'] ?? '') === $currentPrefiks
            && $orig['nip'] === $nip
            && $orig['nazwa'] === $currentNazwa
            && ($orig['nr_eori'] ?? '') === $currentNrEori
            && ($orig['gln'] ?? '') === ''
            && $orig['kod_kraju'] === $currentKodKraju
            && $orig['adres_l1'] === $currentAdresL1
            && $orig['adres_l2'] === $currentAdresL2) {
            return;
        }

        $podmiot1k = $xml->createElement('Podmiot1K');
        $parent->appendChild($podmiot1k);

        if (!empty($orig['prefiks_podatnika'])) {
            $podmiot1k->appendChild($xml->createElement('PrefiksPodatnika', $orig['prefiks_podatnika']));
        }

        $daneIdent = $xml->createElement('DaneIdentyfikacyjne');
        $podmiot1k->appendChild($daneIdent);
        if (!empty($orig['nip'])) {
            $daneIdent->appendChild($xml->createElement('NIP', $orig['nip']));
        }
        if (!empty($orig['nazwa'])) {
            $daneIdent->appendChild($xml->createElement('Nazwa', $orig['nazwa']));
        }

        $adres = $xml->createElement('Adres');
        $podmiot1k->appendChild($adres);
        $adres->appendChild($xml->createElement('KodKraju', !empty($orig['kod_kraju']) ? $orig['kod_kraju'] : 'PL'));
        $adres->appendChild($xml->createElement('AdresL1', !empty($orig['adres_l1']) ? $orig['adres_l1'] : 'Brak danych'));
        if (!empty($orig['adres_l2'])) {
            $adres->appendChild($xml->createElement('AdresL2', $orig['adres_l2']));
        }
        if (!empty($orig['gln'])) {
            $adres->appendChild($xml->createElement('GLN', $orig['gln']));
        }
    }

    /**
     * @brief Emit Podmiot2K if buyer data changed
     * @param $xml DOMDocument
     * @param $parent Fa element
     * @param array $originalXmlData Parsed original
     */
    private function buildPodmiot2K($xml, $parent, $originalXmlData)
    {
        $orig = $originalXmlData['podmiot2'];
        $customer = $this->currentCustomer;

        $countryCode = ksefInferCountryCode($customer);
        $isPolish = ($countryCode === 'PL');
        $isEU = $this->isEUCountry($countryCode);
        $nipRaw = ksefGetIdentifierField($customer, 'NIP');
        $nip = ksefCleanNIP($nipRaw);

        $currentNip = ($isPolish && !empty($nip)) ? $nip : '';
        $currentKodUE = ($isEU && !$isPolish && !empty($customer->tva_intra)) ? ksefKodUE($countryCode) : '';
        $currentNrVatUE = '';
        if (!empty($currentKodUE)) {
            $currentNrVatUE = ksefNrVatUE($customer->tva_intra);
        }
        $currentKodKrajuId = (!$isPolish && empty($currentKodUE) && !empty($nipRaw)) ? $countryCode : '';
        $currentNrId = !empty($currentKodKrajuId) ? $this->xmlSafe(ksefStripVATPrefix($nipRaw)) : '';
        $currentBrakId = (empty($currentNip) && empty($currentKodUE) && empty($currentKodKrajuId)) ? '1' : '';

        $currentNazwa = $this->xmlSafe($customer->name);
        $currentIdNabywcy = ksefXmlEscape($this->getIdNabywcy($customer));
        $currentNrEori = empty($customer->array_options['options_ksef_hide_eori']) ? ksefXmlEscape($this->getBuyerNrEORI($customer)) : '';
        $currentKodKraju = $countryCode;
        $lines = ksefBuyerAddressLines($customer);
        $currentAdresL1 = $this->xmlSafe($lines['l1']);
        $currentAdresL2 = ksefXmlEscape($lines['l2']);

        $identSame = ($orig['nip'] === $currentNip
            && $orig['kod_ue'] === $currentKodUE
            && $orig['nr_vat_ue'] === $currentNrVatUE
            && $orig['kod_kraju_id'] === $currentKodKrajuId
            && $orig['nr_id'] === $currentNrId
            && $orig['brak_id'] === $currentBrakId
            && $orig['nazwa'] === $currentNazwa
            && ($orig['id_nabywcy'] ?? '') === $currentIdNabywcy
            && ($orig['nr_eori'] ?? '') === $currentNrEori);

        $addrSame = (($orig['gln'] ?? '') === ''
            && $orig['kod_kraju'] === $currentKodKraju
            && $orig['adres_l1'] === $currentAdresL1
            && $orig['adres_l2'] === $currentAdresL2);

        if ($identSame && $addrSame) {
            return;
        }

        $origHasIdent = !empty($orig['nip']) || !empty($orig['kod_ue']) || !empty($orig['kod_kraju_id']) || !empty($orig['nr_id']);
        if (!$origHasIdent && ($orig['brak_id'] ?? '') !== '1') {
            dol_syslog("FA3Builder::buildPodmiot2K - original buyer identity unreproducible from stored XML; Podmiot2K omitted to avoid a false BrakID", LOG_WARNING);
            return;
        }

        $podmiot2k = $xml->createElement('Podmiot2K');
        $parent->appendChild($podmiot2k);

        $daneIdent = $xml->createElement('DaneIdentyfikacyjne');
        $podmiot2k->appendChild($daneIdent);

        if (!empty($orig['nip'])) {
            $daneIdent->appendChild($xml->createElement('NIP', $orig['nip']));
        } elseif (!empty($orig['kod_ue'])) {
            $daneIdent->appendChild($xml->createElement('KodUE', $orig['kod_ue']));
            if (!empty($orig['nr_vat_ue'])) {
                $daneIdent->appendChild($xml->createElement('NrVatUE', $orig['nr_vat_ue']));
            }
        } elseif (!empty($orig['kod_kraju_id']) || !empty($orig['nr_id'])) {
            if (!empty($orig['kod_kraju_id'])) {
                $daneIdent->appendChild($xml->createElement('KodKraju', $orig['kod_kraju_id']));
            }
            if (!empty($orig['nr_id'])) {
                $daneIdent->appendChild($xml->createElement('NrID', $orig['nr_id']));
            }
        } else {
            $daneIdent->appendChild($xml->createElement('BrakID', '1'));
        }
        if (!empty($orig['nazwa'])) {
            $daneIdent->appendChild($xml->createElement('Nazwa', $orig['nazwa']));
        }

        $adres = $xml->createElement('Adres');
        $podmiot2k->appendChild($adres);
        $adres->appendChild($xml->createElement('KodKraju', !empty($orig['kod_kraju']) ? $orig['kod_kraju'] : 'PL'));
        $adres->appendChild($xml->createElement('AdresL1', !empty($orig['adres_l1']) ? $orig['adres_l1'] : 'Brak danych'));
        if (!empty($orig['adres_l2'])) {
            $adres->appendChild($xml->createElement('AdresL2', $orig['adres_l2']));
        }
        if (!empty($orig['gln'])) {
            $adres->appendChild($xml->createElement('GLN', $orig['gln']));
        }
        if (!empty($orig['id_nabywcy'])) {
            $podmiot2k->appendChild($xml->createElement('IDNabywcy', $orig['id_nabywcy']));
        }
    }

    /**
     * @brief Get IDNabywcy for customer
     * @param $customer Thirdparty
     * @return string
     */
    private function getIdNabywcy($customer)
    {
        $source = getDolGlobalString('KSEF_IDNABYWCY_SOURCE', 'disabled');
        if ($source === 'disabled') {
            return '';
        }
        if ($source === 'code_client') {
            return !empty($customer->code_client) ? $customer->code_client : '';
        }
        if (strpos($source, 'thirdparty_extrafield:') === 0) {
            $fieldName = substr($source, strlen('thirdparty_extrafield:'));
            if (!empty($fieldName) && isset($customer->array_options['options_' . $fieldName])) {
                return trim($customer->array_options['options_' . $fieldName]);
            }
        }
        return '';
    }

    /**
     * @brief Get NrEORI for buyer
     * @param $customer Thirdparty
     * @return string
     */
    private function getBuyerNrEORI($customer)
    {
        return trim(ksefGetIdentifierField($customer, 'EORI'));
    }

    /**
     * @brief Get product extrafield value
     * @param $line Invoice line
     * @param string $fieldName Extrafield code
     * @return string
     */
    private function getProductExtraFieldValue($line, $fieldName)
    {
        if (empty($line->fk_product) || empty($fieldName)) {
            return '';
        }
        if (!isset($this->productCache[$line->fk_product])) {
            require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
            $prod = new Product($this->db);
            if ($prod->fetch($line->fk_product) > 0) {
                $prod->fetch_optionals();
                $this->productCache[$line->fk_product] = $prod;
            } else {
                $this->productCache[$line->fk_product] = false;
            }
        }
        if (empty($this->productCache[$line->fk_product])) {
            return '';
        }
        $val = $this->productCache[$line->fk_product]->array_options['options_' . $fieldName] ?? '';
        return trim(strip_tags((string) $val));
    }

    /**
     * @brief Get GTU marker for line
     * @param $line Invoice line
     * @return string GTU_xx or empty
     */
    private function getLineGTU($line)
    {
        $source = getDolGlobalString('KSEF_GTU_SOURCE', 'disabled');
        if ($source === 'disabled') {
            return '';
        }
        if (strpos($source, 'product_extrafield:') === 0) {
            $fieldName = substr($source, strlen('product_extrafield:'));
            $val = $this->getProductExtraFieldValue($line, $fieldName);
            if (preg_match('/^GTU_(0[1-9]|1[0-3])$/', $val)) {
                return $val;
            }
        }
        return '';
    }

    /**
     * @brief Get Procedura marker for line
     * @param $line Invoice line
     * @return string
     */
    private function getLineProcedura($line)
    {
        $source = getDolGlobalString('KSEF_PROCEDURA_SOURCE', 'disabled');
        if ($source === 'disabled') {
            return '';
        }
        $validValues = array('WSTO_EE', 'IED', 'TT_D', 'I_42', 'I_63', 'B_SPV', 'B_SPV_DOSTAWA', 'B_MPV_PROWIZJA', 'MPP');
        if (strpos($source, 'product_extrafield:') === 0) {
            $fieldName = substr($source, strlen('product_extrafield:'));
            $val = $this->getProductExtraFieldValue($line, $fieldName);
            if (in_array($val, $validValues)) {
                return $val;
            }
        }
        return '';
    }

    /**
     * @brief Resolve Adnotacje flag
     * @param string $source Flag source setting
     * @param object $invoice
     * @param object|null $fallbackInvoice Correction source
     * @return bool
     */
    private function resolveBooleanFlagSource($source, $invoice, $fallbackInvoice = null)
    {
        if ($source === 'disabled' || $source === '') {
            return false;
        }
        if ($source === 'always_on') {
            return true;
        }
        if (strpos($source, 'thirdparty_extrafield:') === 0) {
            $fieldName = substr($source, strlen('thirdparty_extrafield:'));
            return !empty($fieldName) && !empty($this->currentCustomer->array_options['options_' . $fieldName]);
        }
        if (strpos($source, 'extrafield:') === 0) {
            $fieldName = substr($source, strlen('extrafield:'));
            if (!empty($fieldName) && !empty($invoice->array_options['options_' . $fieldName])) {
                return true;
            }
            if ($fallbackInvoice !== null && !empty($fieldName)
                && !empty($fallbackInvoice->array_options['options_' . $fieldName])) {
                return true;
            }
        }
        return false;
    }

    /**
     * @brief Has NP II
     * @param array $lines Invoice lines
     * @return bool
     */
    private function hasNpIILine($lines)
    {
        foreach ((array) $lines as $line) {
            if ($this->rateKey($line) === 'np II') {
                return true;
            }
        }
        return false;
    }

    /**
     * @brief Adds NP II
     * @param Facture $invoice Correction
     * @param Facture|null $originalInvoice Source
     * @return bool
     */
    private function addsNpIILine($invoice, $originalInvoice)
    {
        $origLines = ($originalInvoice && !empty($originalInvoice->lines)) ? array_values($originalInvoice->lines) : array();
        foreach ($this->matchCorrectionLines($origLines, array_values((array) $invoice->lines)) as $pair) {
            if (!$pair['corr'] || $this->rateKey($pair['corr']) !== 'np II') {
                continue;
            }
            if (!$pair['orig'] || $this->rateKey($pair['orig']) !== 'np II') {
                return true;
            }
        }
        return false;
    }

    /**
     * @brief Check TP flag
     * @param $invoice
     * @return bool
     */
    private function isRelatedPartyTransaction($invoice)
    {
        return $this->resolveBooleanFlagSource(
            getDolGlobalString('KSEF_TP_SOURCE', 'disabled'), $invoice);
    }

    /**
     * @brief Build Adnotacje
     * @param $xml DOMDocument
     * @param $parent Parent element
     * @param $invoice Invoice object
     * @param $vatSummary VAT by rate
     * @param object|null $originalInvoice Correction source
     * @param array|null $originalXmlData Sent source data
     */
    private function buildAdnotacje($xml, $parent, $invoice, $vatSummary = array(), $originalInvoice = null, $originalXmlData = null)
    {
        global $conf;

        $adnotacje = $xml->createElement('Adnotacje');
        $parent->appendChild($adnotacje);

        $p16On = $this->resolveBooleanFlagSource(getDolGlobalString('KSEF_P16_SOURCE', 'disabled'), $invoice);
        $p17On = $this->resolveBooleanFlagSource(getDolGlobalString('KSEF_P17_SOURCE', 'disabled'), $invoice) || $this->resolveBooleanFlagSource(getDolGlobalString('KSEF_P17_TP_SOURCE', 'disabled'), $invoice);
        $p18On = $this->resolveBooleanFlagSource(getDolGlobalString('KSEF_P18_SOURCE', 'disabled'), $invoice) || $this->resolveBooleanFlagSource(getDolGlobalString('KSEF_P18_TP_SOURCE', 'disabled'), $invoice);
        $sentP18 = isset($originalXmlData['p18']) ? (string) $originalXmlData['p18'] : '';
        if ($sentP18 === '1' || $sentP18 === '2') {
            // art. 28b: P_18
            $p18On = $sentP18 === '1' || $this->addsNpIILine($invoice, $originalInvoice);
        } elseif ($this->hasNpIILine($invoice->lines)) {
            $p18On = true;
        }
        $mppOn = $this->resolveBooleanFlagSource(getDolGlobalString('KSEF_FA3_MPP_SOURCE', 'disabled'), $invoice, $originalInvoice);

        $adnotacje->appendChild($xml->createElement('P_16',  $p16On ? '1' : '2'));
        $adnotacje->appendChild($xml->createElement('P_17',  $p17On ? '1' : '2'));
        $adnotacje->appendChild($xml->createElement('P_18',  $p18On ? '1' : '2'));
        $adnotacje->appendChild($xml->createElement('P_18A', $mppOn ? '1' : '2'));

        $zwolnienie = $xml->createElement('Zwolnienie');
        $adnotacje->appendChild($zwolnienie);

        $basisLines = (array) $invoice->lines;
        if ($originalInvoice && $invoice->type == Facture::TYPE_REPLACEMENT) {
            $basisLines = array_merge($basisLines, (array) $originalInvoice->lines);
        }
        list($hasExempt, $podstawa, $zwolnienieType) = $this->exemptionBasis((object) array('lines' => $basisLines));

        if ($hasExempt && !empty($podstawa)) {
            $zwolnienie->appendChild($xml->createElement('P_19', '1'));
            $zwolnienie->appendChild($xml->createElement($zwolnienieType, $this->xmlSafe(mb_substr($podstawa, 0, 256, 'UTF-8'))));
        } else {
            $zwolnienie->appendChild($xml->createElement('P_19N', '1'));
        }

        $noweSrodki = $xml->createElement('NoweSrodkiTransportu');
        $adnotacje->appendChild($noweSrodki);
        $noweSrodki->appendChild($xml->createElement('P_22N', '1'));

        $adnotacje->appendChild($xml->createElement('P_23', '2'));

        // XSD: one subtype
        $subtypes = array();
        $allLines = array_merge((array) $invoice->lines, ($originalInvoice && !empty($originalInvoice->lines)) ? (array) $originalInvoice->lines : array());
        foreach ($allLines as $line) {
            $sub = ksefMarginSubtype($line);
            if ($sub !== '') {
                $subtypes[$sub] = true;
            }
        }
        if (count($subtypes) > 1) {
            global $langs;
            $langs->load('ksef@ksef');
            throw new Exception($langs->transnoentitiesnoconv('KSEF_MarginMixedSubtypes', implode(', ', array_keys($subtypes))));
        }
        $pmarzy = $xml->createElement('PMarzy');
        $adnotacje->appendChild($pmarzy);
        if ($subtypes) {
            $pmarzy->appendChild($xml->createElement('P_PMarzy', '1'));
            $pmarzy->appendChild($xml->createElement('P_PMarzy_' . key($subtypes), '1'));
        } else {
            $pmarzy->appendChild($xml->createElement('P_PMarzyN', '1'));
        }
    }

    /**
     * @brief Translate DEPOSIT marker
     * @param string $description Line text
     * @return string
     */
    public static function coreLineMarkers($description)
    {
        if (strpos((string) $description, '(DEPOSIT)') === 0) {
            return 'Zaliczka' . substr((string) $description, strlen('(DEPOSIT)'));
        }
        return (string) $description;
    }

    /**
     * @brief Strip note HTML
     * @param string $html HTML or text
     * @return string
     */
    private static function stripNoteHtml($html)
    {
        $text = str_replace(array("\r\n", "\r"), "\n", (string) $html);
        $isHtml = function_exists('dol_textishtml') ? dol_textishtml($text) : (bool) preg_match('/<\/?[a-z][a-z0-9]*(\s[^<>]*)?\/?>|&[a-z0-9#]{1,8};/i', $text);
        if ($isHtml) {
            $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
            $text = preg_replace('/<\/(p|div|li|tr|h[1-6])>/i', "\n", $text);
            $text = preg_replace('/<!--.*?-->/s', '', $text);
            $text = preg_replace('/<(script|style)\b[^<>]*>.*?<\/\1\s*>/is', '', $text);
            $text = preg_replace('/<\/?[a-z][a-z0-9:-]*(\s[^<>]*)?\/?>|<![a-z][^<>]*>/i', '', $text);
            $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        }
        $text = preg_replace('/[ \t]+\n/', "\n", $text);
        $text = preg_replace('/\n{2,}/', "\n", $text);
        return trim(self::sanitizeForXml($text));
    }

    /**
     * @brief Strip control chars
     * @param string $str
     * @return string
     */
    private static function sanitizeForXml($str)
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $str);
    }

    /**
     * @brief Parse DodatkowyOpis entries
     * @param object $invoice
     * @param object $conf
     * @param object $db
     * @return array key/value entries
     */
    public static function parseDodatkowyOpisPreview($invoice, $conf, $db)
    {
        global $langs;

        dol_include_once('/ksef/lib/ksef.lib.php');

        $entries = array();

        if (!isset($invoice->array_options) || empty($invoice->array_options)) {
            $invoice->fetch_optionals();
        }

        $invoiceOverride = isset($invoice->array_options['options_ksef_dodatkowy_opis_mode'])
            ? trim((string) $invoice->array_options['options_ksef_dodatkowy_opis_mode'])
            : '';

        $globalNoteMode = getDolGlobalString('KSEF_DODATKOWY_OPIS_NOTE_MODE', 'simple');
        $noteTarget = getDolGlobalString('KSEF_NOTE_PUBLIC_TARGET', 'stopka_faktury');

        if (!empty($invoiceOverride)) {
            $parsed = self::parseCombinedNoteMode($invoiceOverride);
            if ($parsed) {
                $effectiveNoteMode = $parsed['mode'];
                $noteTarget = $parsed['target'];
            } else {
                $effectiveNoteMode = $globalNoteMode;
            }
        } else {
            $effectiveNoteMode = $globalNoteMode;
        }

        if ($effectiveNoteMode === 'disabled' && !empty($invoiceOverride) && $invoiceOverride === 'disabled') {
            return $entries;
        }

        if ($effectiveNoteMode !== 'disabled' && $noteTarget === 'dodatkowy_opis' && !empty($invoice->note_public)) {
            $text = self::stripNoteHtml($invoice->note_public);

            if (!empty($text)) {
                if ($effectiveNoteMode === 'simple') {
                    $totalLen = mb_strlen($text, 'UTF-8');
                    if ($totalLen <= 256) {
                        $entries[] = array('key' => 'Uwagi', 'value' => $text, 'source' => 'note');
                    } else {
                        $chunkNum = 0;
                        for ($offset = 0; $offset < $totalLen; $offset += 256) {
                            $chunk = mb_substr($text, $offset, 256, 'UTF-8');
                            $chunkNum++;
                            $label = ($chunkNum == 1) ? 'Uwagi' : 'Uwagi ' . $chunkNum;
                            $entries[] = array('key' => $label, 'value' => $chunk, 'source' => 'note');
                        }
                    }
                } elseif ($effectiveNoteMode === 'keyvalue') {
                    $lines = explode("\n", $text);
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if (empty($line)) continue;
                        $parts = explode(':', $line, 2);
                        if (count($parts) < 2) continue;
                        $key = trim($parts[0]);
                        $value = trim($parts[1]);
                        if ($key === '' || $value === '') continue;
                        $entries[] = array(
                            'key' => mb_substr($key, 0, 256, 'UTF-8'),
                            'value' => mb_substr($value, 0, 256, 'UTF-8'),
                            'source' => 'note',
                        );
                    }
                }
            }
        }

        $extrafieldsConf = getDolGlobalString('KSEF_DODATKOWY_OPIS_EXTRAFIELDS');
        if (!empty($extrafieldsConf)) {
            $fieldEntries = array_filter(array_map('trim', explode(',', $extrafieldsConf)));

            dol_include_once('/core/class/extrafields.class.php');
            $ef = new ExtraFields($db);
            $ef->fetch_name_optionals_label('facture');

            $skipTypes = ksefDodatkowyOpisUnsupportedTypes();

            foreach ($fieldEntries as $entry) {
                if (empty($entry)) continue;
                $parts = explode(':', $entry, 2);
                $name = $parts[0];
                $target = isset($parts[1]) ? $parts[1] : 'dodatkowy';
                if ($target !== 'dodatkowy') continue;

                if (!isset($ef->attributes['facture']['label'][$name])) continue;

                $type = isset($ef->attributes['facture']['type'][$name]) ? $ef->attributes['facture']['type'][$name] : '';
                if (in_array($type, $skipTypes)) continue;

                $rawValue = isset($invoice->array_options['options_' . $name]) ? $invoice->array_options['options_' . $name] : '';
                if ($rawValue === '' || $rawValue === null) continue;

                $displayValue = self::formatExtraFieldValue($rawValue, $type, $ef, 'facture', $name, $langs);
                if ($displayValue === '' || $displayValue === null) continue;

                $label = $langs->trans($ef->attributes['facture']['label'][$name]);
                $entries[] = array(
                    'key' => mb_substr(self::sanitizeForXml($label), 0, 256, 'UTF-8'),
                    'value' => mb_substr(self::sanitizeForXml((string) $displayValue), 0, 256, 'UTF-8'),
                    'source' => 'extrafield',
                );
            }
        }

        $detExtrafieldsConf = getDolGlobalString('KSEF_DODATKOWY_OPIS_DET_EXTRAFIELDS', '');
        if (!empty($detExtrafieldsConf) && !empty($invoice->lines)) {
            $detFieldNames = array_filter(array_map('trim', explode(',', $detExtrafieldsConf)));
            if (!empty($detFieldNames)) {
                if (!isset($ef)) {
                    dol_include_once('/core/class/extrafields.class.php');
                    $ef = new ExtraFields($db);
                }
                $ef->fetch_name_optionals_label('facturedet');

                if (!isset($skipTypes)) $skipTypes = ksefDodatkowyOpisUnsupportedTypes();

                $lineNum = 0;
                foreach ($invoice->lines as $line) {
                    $lineNum++;
                    if (!isset($line->array_options) || empty($line->array_options)) {
                        $line->fetch_optionals();
                    }
                    foreach ($detFieldNames as $name) {
                        if (empty($name)) continue;
                        if (!isset($ef->attributes['facturedet']['label'][$name])) continue;

                        $type = isset($ef->attributes['facturedet']['type'][$name]) ? $ef->attributes['facturedet']['type'][$name] : '';
                        if (in_array($type, $skipTypes)) continue;

                        $rawValue = isset($line->array_options['options_' . $name]) ? $line->array_options['options_' . $name] : '';
                        if ($rawValue === '' || $rawValue === null) continue;

                        $displayValue = self::formatExtraFieldValue($rawValue, $type, $ef, 'facturedet', $name, $langs);
                        if ($displayValue === '' || $displayValue === null) continue;

                        $label = $langs->trans($ef->attributes['facturedet']['label'][$name]);
                        $entries[] = array(
                            'key' => mb_substr(self::sanitizeForXml($label), 0, 256, 'UTF-8'),
                            'value' => mb_substr(self::sanitizeForXml((string) $displayValue), 0, 256, 'UTF-8'),
                            'source' => 'extrafield_det',
                            'nr_wiersza' => $lineNum,
                        );
                    }
                }
            }
        }

        $prodExtrafieldsConf = getDolGlobalString('KSEF_DODATKOWY_OPIS_PRODUCT_EXTRAFIELDS', '');
        if (!empty($prodExtrafieldsConf) && !empty($invoice->lines)) {
            $prodFieldNames = array_filter(array_map('trim', explode(',', $prodExtrafieldsConf)));
            if (!empty($prodFieldNames)) {
                if (!isset($ef)) {
                    dol_include_once('/core/class/extrafields.class.php');
                    $ef = new ExtraFields($db);
                }
                $ef->fetch_name_optionals_label('product');

                if (!isset($skipTypes)) $skipTypes = ksefDodatkowyOpisUnsupportedTypes();

                require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
                $productCache = array();
                $lineNum = 0;
                foreach ($invoice->lines as $line) {
                    $lineNum++;
                    if (empty($line->fk_product)) continue;

                    if (!isset($productCache[$line->fk_product])) {
                        $prod = new Product($db);
                        if ($prod->fetch($line->fk_product) > 0) {
                            $prod->fetch_optionals();
                            $productCache[$line->fk_product] = $prod;
                        }
                    }
                    if (!isset($productCache[$line->fk_product])) continue;

                    $prod = $productCache[$line->fk_product];

                    foreach ($prodFieldNames as $name) {
                        if (empty($name)) continue;
                        if (!isset($ef->attributes['product']['label'][$name])) continue;

                        $type = isset($ef->attributes['product']['type'][$name]) ? $ef->attributes['product']['type'][$name] : '';
                        if (in_array($type, $skipTypes)) continue;

                        $rawValue = isset($prod->array_options['options_' . $name]) ? $prod->array_options['options_' . $name] : '';
                        if ($rawValue === '' || $rawValue === null) continue;

                        $displayValue = self::formatExtraFieldValue($rawValue, $type, $ef, 'product', $name, $langs);
                        if ($displayValue === '' || $displayValue === null) continue;

                        $label = $langs->trans($ef->attributes['product']['label'][$name]);
                        $entries[] = array(
                            'key' => mb_substr(self::sanitizeForXml($label), 0, 256, 'UTF-8'),
                            'value' => mb_substr(self::sanitizeForXml((string) $displayValue), 0, 256, 'UTF-8'),
                            'source' => 'extrafield_product',
                            'nr_wiersza' => $lineNum,
                        );
                    }
                }
            }
        }

        $socExtrafieldsConf = getDolGlobalString('KSEF_DODATKOWY_OPIS_SOCIETE_EXTRAFIELDS', '');
        if (!empty($socExtrafieldsConf) && !empty($invoice->socid)) {
            $socFieldEntries = array_filter(array_map('trim', explode(',', $socExtrafieldsConf)));
            if (!empty($socFieldEntries)) {
                if (!isset($ef)) {
                    dol_include_once('/core/class/extrafields.class.php');
                    $ef = new ExtraFields($db);
                }
                $ef->fetch_name_optionals_label('societe');

                if (!isset($skipTypes)) $skipTypes = ksefDodatkowyOpisUnsupportedTypes();

                require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
                $soc = new Societe($db);
                if ($soc->fetch($invoice->socid) > 0) {
                    $soc->fetch_optionals();

                    foreach ($socFieldEntries as $entry) {
                        if (empty($entry)) continue;
                        $parts = explode(':', $entry, 2);
                        $name = $parts[0];
                        $target = isset($parts[1]) ? $parts[1] : 'dodatkowy';
                        if ($target !== 'dodatkowy') continue;

                        if (!isset($ef->attributes['societe']['label'][$name])) continue;
                        $type = isset($ef->attributes['societe']['type'][$name]) ? $ef->attributes['societe']['type'][$name] : '';
                        if (in_array($type, $skipTypes)) continue;

                        $rawValue = isset($soc->array_options['options_' . $name]) ? $soc->array_options['options_' . $name] : '';
                        if ($rawValue === '' || $rawValue === null) continue;

                        $displayValue = self::formatExtraFieldValue($rawValue, $type, $ef, 'societe', $name, $langs);
                        if ($displayValue === '' || $displayValue === null) continue;

                        $label = $langs->trans($ef->attributes['societe']['label'][$name]);
                        $entries[] = array(
                            'key' => mb_substr(self::sanitizeForXml($label), 0, 256, 'UTF-8'),
                            'value' => mb_substr(self::sanitizeForXml((string) $displayValue), 0, 256, 'UTF-8'),
                            'source' => 'extrafield_societe',
                        );
                    }
                }
            }
        }

        $projExtrafieldsConf = getDolGlobalString('KSEF_DODATKOWY_OPIS_PROJECT_EXTRAFIELDS', '');
        if (!empty($projExtrafieldsConf) && !empty($invoice->fk_project)) {
            $projFieldEntries = array_filter(array_map('trim', explode(',', $projExtrafieldsConf)));
            if (!empty($projFieldEntries)) {
                if (!isset($ef)) {
                    dol_include_once('/core/class/extrafields.class.php');
                    $ef = new ExtraFields($db);
                }
                $ef->fetch_name_optionals_label('projet');

                if (!isset($skipTypes)) $skipTypes = ksefDodatkowyOpisUnsupportedTypes();

                require_once DOL_DOCUMENT_ROOT . '/projet/class/project.class.php';
                $proj = new Project($db);
                if ($proj->fetch($invoice->fk_project) > 0) {
                    $proj->fetch_optionals();

                    foreach ($projFieldEntries as $entry) {
                        if (empty($entry)) continue;
                        $parts = explode(':', $entry, 2);
                        $name = $parts[0];
                        $target = isset($parts[1]) ? $parts[1] : 'dodatkowy';
                        if ($target !== 'dodatkowy') continue;

                        if (!isset($ef->attributes['projet']['label'][$name])) continue;
                        $type = isset($ef->attributes['projet']['type'][$name]) ? $ef->attributes['projet']['type'][$name] : '';
                        if (in_array($type, $skipTypes)) continue;

                        $rawValue = isset($proj->array_options['options_' . $name]) ? $proj->array_options['options_' . $name] : '';
                        if ($rawValue === '' || $rawValue === null) continue;

                        $displayValue = self::formatExtraFieldValue($rawValue, $type, $ef, 'projet', $name, $langs);
                        if ($displayValue === '' || $displayValue === null) continue;

                        $label = $langs->trans($ef->attributes['projet']['label'][$name]);
                        $entries[] = array(
                            'key' => mb_substr(self::sanitizeForXml($label), 0, 256, 'UTF-8'),
                            'value' => mb_substr(self::sanitizeForXml((string) $displayValue), 0, 256, 'UTF-8'),
                            'source' => 'extrafield_project',
                        );
                    }
                }
            }
        }

        return $entries;
    }

    /**
     * @brief Collect stopka extrafields
     * @param object $invoice
     * @param object $conf
     * @param object $db
     * @return string
     */
    public static function collectStopkaExtrafields($invoice, $conf, $db, $customer = null)
    {
        global $langs;

        dol_include_once('/ksef/lib/ksef.lib.php');
        dol_include_once('/core/class/extrafields.class.php');

        $lines = array();
        $skipTypes = ksefDodatkowyOpisUnsupportedTypes();
        $ef = new ExtraFields($db);

        if (!isset($invoice->array_options) || empty($invoice->array_options)) {
            $invoice->fetch_optionals();
        }

        $extrafieldsConf = getDolGlobalString('KSEF_DODATKOWY_OPIS_EXTRAFIELDS', '');
        if (!empty($extrafieldsConf)) {
            $ef->fetch_name_optionals_label('facture');
            $fieldEntries = array_filter(array_map('trim', explode(',', $extrafieldsConf)));
            foreach ($fieldEntries as $entry) {
                $parts = explode(':', $entry, 2);
                $name = $parts[0];
                $target = isset($parts[1]) ? $parts[1] : 'dodatkowy';
                if ($target !== 'stopka') continue;
                if (empty($name) || !isset($ef->attributes['facture']['label'][$name])) continue;
                $type = $ef->attributes['facture']['type'][$name] ?? '';
                if (in_array($type, $skipTypes)) continue;

                $rawValue = isset($invoice->array_options['options_' . $name]) ? $invoice->array_options['options_' . $name] : '';
                if ($rawValue === '' || $rawValue === null) continue;
                $displayValue = self::formatExtraFieldValue($rawValue, $type, $ef, 'facture', $name, $langs);
                if ($displayValue === '' || $displayValue === null) continue;

                $label = $langs->trans($ef->attributes['facture']['label'][$name]);
                $lines[] = self::sanitizeForXml($label) . ': ' . self::sanitizeForXml((string) $displayValue);
            }
        }

        $socConf = getDolGlobalString('KSEF_DODATKOWY_OPIS_SOCIETE_EXTRAFIELDS', '');
        if (!empty($socConf) && !empty($invoice->socid)) {
            $ef->fetch_name_optionals_label('societe');
            $socEntries = array_filter(array_map('trim', explode(',', $socConf)));
            $hasStopka = false;
            foreach ($socEntries as $entry) {
                $parts = explode(':', $entry, 2);
                if (isset($parts[1]) && $parts[1] === 'stopka') { $hasStopka = true; break; }
            }
            if ($hasStopka) {
                $soc = null;
                if ($customer && $customer->id == $invoice->socid) {
                    $soc = $customer;
                } else {
                    require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
                    $soc = new Societe($db);
                    if ($soc->fetch($invoice->socid) <= 0) $soc = null;
                }
                if ($soc) {
                    $soc->fetch_optionals();
                    foreach ($socEntries as $entry) {
                        $parts = explode(':', $entry, 2);
                        $name = $parts[0];
                        $target = isset($parts[1]) ? $parts[1] : 'dodatkowy';
                        if ($target !== 'stopka') continue;
                        if (empty($name) || !isset($ef->attributes['societe']['label'][$name])) continue;
                        $type = $ef->attributes['societe']['type'][$name] ?? '';
                        if (in_array($type, $skipTypes)) continue;

                        $rawValue = isset($soc->array_options['options_' . $name]) ? $soc->array_options['options_' . $name] : '';
                        if ($rawValue === '' || $rawValue === null) continue;
                        $displayValue = self::formatExtraFieldValue($rawValue, $type, $ef, 'societe', $name, $langs);
                        if ($displayValue === '' || $displayValue === null) continue;

                        $label = $langs->trans($ef->attributes['societe']['label'][$name]);
                        $lines[] = self::sanitizeForXml($label) . ': ' . self::sanitizeForXml((string) $displayValue);
                    }
                }
            }
        }

        $projConf = getDolGlobalString('KSEF_DODATKOWY_OPIS_PROJECT_EXTRAFIELDS', '');
        if (!empty($projConf) && !empty($invoice->fk_project)) {
            $ef->fetch_name_optionals_label('projet');
            $projEntries = array_filter(array_map('trim', explode(',', $projConf)));
            $hasStopka = false;
            foreach ($projEntries as $entry) {
                $parts = explode(':', $entry, 2);
                if (isset($parts[1]) && $parts[1] === 'stopka') { $hasStopka = true; break; }
            }
            if ($hasStopka) {
                require_once DOL_DOCUMENT_ROOT . '/projet/class/project.class.php';
                $proj = new Project($db);
                if ($proj->fetch($invoice->fk_project) > 0) {
                    $proj->fetch_optionals();
                    foreach ($projEntries as $entry) {
                        $parts = explode(':', $entry, 2);
                        $name = $parts[0];
                        $target = isset($parts[1]) ? $parts[1] : 'dodatkowy';
                        if ($target !== 'stopka') continue;
                        if (empty($name) || !isset($ef->attributes['projet']['label'][$name])) continue;
                        $type = $ef->attributes['projet']['type'][$name] ?? '';
                        if (in_array($type, $skipTypes)) continue;

                        $rawValue = isset($proj->array_options['options_' . $name]) ? $proj->array_options['options_' . $name] : '';
                        if ($rawValue === '' || $rawValue === null) continue;
                        $displayValue = self::formatExtraFieldValue($rawValue, $type, $ef, 'projet', $name, $langs);
                        if ($displayValue === '' || $displayValue === null) continue;

                        $label = $langs->trans($ef->attributes['projet']['label'][$name]);
                        $lines[] = self::sanitizeForXml($label) . ': ' . self::sanitizeForXml((string) $displayValue);
                    }
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @brief Format extrafield value
     * @param mixed $rawValue
     * @param string $type Extrafield type
     * @param ExtraFields $ef
     * @param string $elementType facture or facturedet
     * @param string $name Field name
     * @param Translate $langs
     * @return string
     */
    private static function formatExtraFieldValue($rawValue, $type, $ef, $elementType, $name, $langs)
    {
        if ($type === 'date') {
            return dol_print_date($rawValue, 'day', 'tzserver');
        } elseif ($type === 'datetime') {
            return dol_print_date($rawValue, 'dayhour', 'tzserver');
        } elseif ($type === 'select') {
            $param = isset($ef->attributes[$elementType]['param'][$name]) ? $ef->attributes[$elementType]['param'][$name] : array();
            if (isset($param['options'][$rawValue])) {
                $optLabel = $param['options'][$rawValue];
                if (($pos = strpos($optLabel, '|')) !== false) {
                    $optLabel = substr($optLabel, 0, $pos);
                }
                return $langs->trans($optLabel);
            }
            return (string) $rawValue;
        } elseif ($type === 'double' || $type === 'price') {
            return (string) $rawValue;
        } else {
            return strip_tags((string) $rawValue);
        }
    }

    /**
     * @brief Invoice DodatkowyOpis override
     * @param object $invoice
     * @return string
     */
    public static function getDodatkowyOpisOverride($invoice)
    {
        if (!isset($invoice->array_options) || empty($invoice->array_options)) {
            $invoice->fetch_optionals();
        }
        return isset($invoice->array_options['options_ksef_dodatkowy_opis_mode'])
            ? trim((string) $invoice->array_options['options_ksef_dodatkowy_opis_mode'])
            : '';
    }

    /**
     * @brief Parse note mode
     * @param string $combined e.g. simple_stopka
     * @return array|null mode, target; null unknown
     */
    public static function parseCombinedNoteMode($combined)
    {
        $map = array(
            'simple_stopka'      => array('mode' => 'simple',   'target' => 'stopka_faktury'),
            'simple_dodatkowy'   => array('mode' => 'simple',   'target' => 'dodatkowy_opis'),
            'keyvalue_dodatkowy' => array('mode' => 'keyvalue', 'target' => 'dodatkowy_opis'),
            'disabled'           => array('mode' => 'disabled', 'target' => ''),
        );
        return isset($map[$combined]) ? $map[$combined] : null;
    }

    /**
     * @brief Build DodatkowyOpis
     * @param $xml DOMDocument
     * @param $parent Fa element
     * @param $invoice Invoice object
     */
    private function buildDodatkowyOpis($xml, $parent, $invoice)
    {
        global $conf;

        $entries = self::parseDodatkowyOpisPreview($invoice, $conf, $this->db);

        foreach ($entries as $entry) {
            $dodatkowy = $xml->createElement('DodatkowyOpis');
            // XSD: NrWiersza first
            if (!empty($entry['nr_wiersza'])) {
                $dodatkowy->appendChild($xml->createElement('NrWiersza', (int) $entry['nr_wiersza']));
            }
            $dodatkowy->appendChild($xml->createElement('Klucz', $this->xmlSafe($entry['key'])));
            $dodatkowy->appendChild($xml->createElement('Wartosc', $this->xmlSafe($entry['value'])));
            $parent->appendChild($dodatkowy);
        }
    }

    /**
     * @brief Build line items
     * @param $xml DOMDocument
     * @param $parent Parent element
     * @param $invoice Invoice object
     * @param $originalInvoice Original for KOR
     */
    private function buildFaWiersz($xml, $parent, $invoice, $originalInvoice = null)
    {
        // MF 2.7
        $type = is_array($this->depositCtx) ? $this->depositCtx['type'] : '';
        if ($type === 'ROZ' || $type === 'KOR_ROZ') {
            $db = $this->db;
            $keep = function ($obj) use ($db) {
                if (!$obj || empty($obj->lines)) {
                    return $obj;
                }
                $copy = clone $obj;
                $copy->lines = array_values(array_filter($obj->lines, function ($l) use ($db) {
                    return !ksefIsDepositLine($db, $l);
                }));
                return $copy;
            };
            $invoice = $keep($invoice);
            $originalInvoice = $keep($originalInvoice);
        }
        $isKor = ($invoice->type == Facture::TYPE_CREDIT_NOTE
               || $invoice->type == Facture::TYPE_REPLACEMENT);

        if (!$isKor) {
            $this->buildFaWierszStandard($xml, $parent, $invoice);
            return;
        }

        $method = getDolGlobalString('KSEF_KOR_LINE_METHOD', 'stanprzed');

        if ($invoice->type == Facture::TYPE_CREDIT_NOTE) {
            $this->buildFaWierszDifferentialCreditNote($xml, $parent, $invoice);
            return;
        }

        // no StanPrzed
        if ($method === 'stanprzed' && !$this->correctsAfterCreditNote($invoice)) {
            $this->buildFaWierszStanPrzed($xml, $parent, $invoice, $originalInvoice);
        } else {
            $this->buildFaWierszDifferentialReplacement($xml, $parent, $invoice, $originalInvoice);
        }
    }

    /**
     * @brief Open credit note
     * @param Facture $invoice Replacement
     * @return bool
     */
    private function correctsAfterCreditNote($invoice)
    {
        $P = MAIN_DB_PREFIX;
        $members = array();
        $cur = (int) $invoice->fk_facture_source;
        for ($i = 0; $cur > 0 && $i < 20 && !in_array($cur, $members, true); $i++) {
            $members[] = $cur;
            $res = $this->db->query("SELECT type, fk_facture_source FROM " . $P . "facture WHERE rowid = " . $cur);
            $o = $res ? $this->db->fetch_object($res) : null;
            $cur = ($o && (int) $o->type === Facture::TYPE_REPLACEMENT) ? (int) $o->fk_facture_source : 0;
        }
        if (!$members) {
            return false;
        }
        $absorbed = ksefShortfallCovers($this->db, $members)['absorb'];
        foreach ($this->absorbedNotes as $note) {
            $absorbed[] = (int) $note->id;
        }
        $res = $this->db->query("SELECT rowid FROM " . $P . "facture WHERE fk_facture_source IN (" . implode(',', $members) . ") AND type = " . Facture::TYPE_CREDIT_NOTE
            . " AND fk_statut IN (" . Facture::STATUS_VALIDATED . ", " . Facture::STATUS_CLOSED . ")"
            . ($absorbed ? " AND rowid NOT IN (" . implode(',', array_map('intval', $absorbed)) . ")" : '') . " LIMIT 1");
        return $res && $this->db->num_rows($res) > 0;
    }

    /**
     * @brief Emit standard lines
     */
    private function buildFaWierszStandard($xml, $parent, $invoice)
    {
        $lineNum = 1;
        foreach ($invoice->lines as $line) {
            $this->buildSingleFaWiersz($xml, $parent, $line, $lineNum);
            $lineNum++;
        }
    }

    /**
     * @brief Credit note lines
     */
    private function buildFaWierszDifferentialCreditNote($xml, $parent, $invoice)
    {
        $lineNum = 1;
        foreach ($invoice->lines as $line) {
            $this->buildSingleFaWiersz($xml, $parent, $line, $lineNum, false, true);
            $lineNum++;
        }
    }

    /**
     * @brief Pair correction lines
     */
    private function matchCorrectionLines($origLines, $corrLines)
    {
        $origById = array();
        foreach ($origLines as $origLine) {
            if (!empty($origLine->id)) {
                $origById[$origLine->id] = $origLine;
            }
        }

        $pairs = array();
        $matchedOrigIds = array();
        $matchedCorrIndices = array();

        foreach ($corrLines as $ci => $corrLine) {
            $prevId = $corrLine->fk_prev_id ?? 0;
            if ($prevId > 0 && isset($origById[$prevId])) {
                $pairs[] = array('orig' => $origById[$prevId], 'corr' => $corrLine);
                $matchedOrigIds[$prevId] = true;
                $matchedCorrIndices[$ci] = true;
            }
        }

        if (empty($pairs)) {
            $maxLines = max(count($origLines), count($corrLines));
            for ($i = 0; $i < $maxLines; $i++) {
                $pairs[] = array(
                    'orig' => isset($origLines[$i]) ? $origLines[$i] : null,
                    'corr' => isset($corrLines[$i]) ? $corrLines[$i] : null,
                );
            }
            return $pairs;
        }

        foreach ($origLines as $origLine) {
            if (!empty($origLine->id) && !isset($matchedOrigIds[$origLine->id])) {
                $pairs[] = array('orig' => $origLine, 'corr' => null);
            }
        }

        foreach ($corrLines as $ci => $corrLine) {
            if (!isset($matchedCorrIndices[$ci])) {
                $pairs[] = array('orig' => null, 'corr' => $corrLine);
            }
        }

        return $pairs;
    }

    /**
     * @brief Emit StanPrzed lines
     */
    private function buildFaWierszStanPrzed($xml, $parent, $invoice, $originalInvoice)
    {
        $corrLines = $invoice->lines;
        $origLines = ($originalInvoice && !empty($originalInvoice->lines)) ? $originalInvoice->lines : array();
        $lineNum = 1;

        $origKursWaluty = $originalInvoice ? $this->kursOf($originalInvoice) : null;

        $pairs = $this->matchCorrectionLines($origLines, $corrLines);

        foreach ($pairs as $pair) {
            $origLine = $pair['orig'];
            $corrLine = $pair['corr'];

            if ($origLine && $corrLine) {
                // rate changes PLN
                if ($this->beforeKursWaluty !== null || $this->linesAreDifferent($origLine, $corrLine)) {
                    $this->buildSingleFaWiersz($xml, $parent, $origLine, $lineNum, true, false, $origKursWaluty);
                    $this->buildSingleFaWiersz($xml, $parent, $corrLine, $lineNum, false);
                    $lineNum++;
                }
            } elseif ($origLine) {
                $this->buildSingleFaWiersz($xml, $parent, $origLine, $lineNum, true, false, $origKursWaluty);
                $lineNum++;
            } else {
                $this->buildSingleFaWiersz($xml, $parent, $corrLine, $lineNum, false);
                $lineNum++;
            }
        }
    }

    /**
     * @brief Emit differential lines
     */
    private function buildFaWierszDifferentialReplacement($xml, $parent, $invoice, $originalInvoice)
    {
        $corrLines = $invoice->lines;
        $origLines = ($originalInvoice && !empty($originalInvoice->lines)) ? $originalInvoice->lines : array();
        $useMulti = (!empty($this->currentInvoiceCurrency) && $this->currentInvoiceCurrency != 'PLN');
        $origKursWaluty = ($useMulti && $originalInvoice) ? $this->kursOf($originalInvoice) : null;
        $lineNum = 1;

        $pairs = $this->matchCorrectionLines($origLines, $corrLines);

        foreach ($pairs as $pair) {
            $origLine = $pair['orig'];
            $corrLine = $pair['corr'];

            if ($origLine && $corrLine) {
                $origRate = $this->rateKey($origLine);
                $corrRate = $this->rateKey($corrLine);
                $origTotal = $this->lineAmount($origLine, $useMulti);
                $corrTotal = $this->lineAmount($corrLine, $useMulti);
                $deltaQty = $corrLine->qty - $origLine->qty;
                $deltaTotal = $corrTotal - $origTotal;
                $deltaVat = $this->lineVat($corrLine, $useMulti) - $this->lineVat($origLine, $useMulti);
                $qtyChanged = ksefFormatDecimal($corrLine->qty, 6) !== ksefFormatDecimal($origLine->qty, 6);

                if ($origRate !== $corrRate || $this->beforeKursWaluty !== null) {
                    $this->buildDifferentialRow($xml, $parent, $origLine, $lineNum, -$origLine->qty, -$origTotal, $origRate, $useMulti, null, $origKursWaluty, -$this->lineVat($origLine, $useMulti));
                    $this->buildDifferentialRow($xml, $parent, $corrLine, $lineNum, $corrLine->qty, $corrTotal, $corrRate, $useMulti, null, null, $this->lineVat($corrLine, $useMulti));
                    $lineNum++;
                } elseif (abs($deltaTotal) >= 0.005 || $qtyChanged) {
                    $emitQty = $qtyChanged ? $deltaQty : $corrLine->qty;
                    $corrPrice = $this->lineUnitPrice($corrLine, $useMulti, $corrRate);
                    $this->buildDifferentialRow($xml, $parent, $corrLine, $lineNum, $emitQty, $deltaTotal, $corrRate, $useMulti, $corrPrice, null, $deltaVat);
                    $lineNum++;
                } elseif ($this->linesAreDifferent($origLine, $corrLine)) {
                    $corrPrice = $this->lineUnitPrice($corrLine, $useMulti, $corrRate);
                    $this->buildDifferentialRow($xml, $parent, $corrLine, $lineNum, 0, 0, $corrRate, $useMulti, $corrPrice, null, 0);
                    $lineNum++;
                }
            } elseif ($origLine) {
                $origRate = $this->rateKey($origLine);
                $origTotal = $this->lineAmount($origLine, $useMulti);
                $this->buildDifferentialRow($xml, $parent, $origLine, $lineNum, -$origLine->qty, -$origTotal, $origRate, $useMulti, null, $origKursWaluty, -$this->lineVat($origLine, $useMulti));
                $lineNum++;
            } else {
                $corrRate = $this->rateKey($corrLine);
                $corrTotal = $this->lineAmount($corrLine, $useMulti);
                $this->buildDifferentialRow($xml, $parent, $corrLine, $lineNum, $corrLine->qty, $corrTotal, $corrRate, $useMulti, null, null, $this->lineVat($corrLine, $useMulti));
                $lineNum++;
            }
        }
    }

    /**
     * @brief Gross form line
     * @param string $rateKey
     * @return bool
     */
    private function isGrossForm($rateKey)
    {
        return $this->grossMethod || $rateKey === 'marza';
    }

    /**
     * @brief Net or gross
     * @param object $line
     * @param bool $useMulti Non-PLN currency
     * @return float
     */
    private function lineAmount($line, $useMulti)
    {
        if ($this->grossMethod) {
            return (float) ($useMulti ? $line->multicurrency_total_ttc : $line->total_ttc);
        }
        return (float) ($useMulti ? $line->multicurrency_total_ht : $line->total_ht);
    }

    /**
     * @brief Line VAT amount
     * @param object $line
     * @param bool $useMulti Non-PLN currency
     * @return float
     */
    private function lineVat($line, $useMulti)
    {
        return (float) ($useMulti ? $line->multicurrency_total_tva : $line->total_tva);
    }

    /**
     * @brief Line unit price
     * @param object $line
     * @param bool $useMulti Non-PLN currency
     * @param string $rateKey
     * @return float
     */
    private function lineUnitPrice($line, $useMulti, $rateKey)
    {
        $sub = (float) ($useMulti ? $line->multicurrency_subprice : $line->subprice);
        if (!$this->isGrossForm($rateKey)) {
            return $sub;
        }
        $qty = (float) $line->qty;
        $remise = (float) ($line->remise_percent ?? 0);
        if ($qty == 0 || $remise >= 100) {
            return $sub * (1 + (float) $line->tva_tx / 100);
        }
        $p = (float) ($useMulti ? $line->multicurrency_total_ttc : $line->total_ttc) / $qty / (1 - $remise / 100);
        $sp = isset($line->situation_percent) ? (float) $line->situation_percent : 100;
        if ($sp > 0 && $sp != 100) {
            $p = $p * 100 / $sp;
        }
        return $p;
    }

    /**
     * @brief Emit P_8A unit
     * @param DOMDocument $xml
     * @param DOMElement $faWiersz
     * @param object $line
     */
    private function appendUnit($xml, $faWiersz, $line)
    {
        if (!getDolGlobalInt('KSEF_FA3_INCLUDE_UNIT')) {
            return;
        }
        $unit = ksefUnitText($this->db, (int) ($line->fk_unit ?? 0));
        if ($unit !== '') {
            $faWiersz->appendChild($xml->createElement('P_8A', $this->xmlSafe(mb_substr($unit, 0, 256, 'UTF-8'))));
        }
    }

    /**
     * @brief Line P_7 text
     * @param object $line
     * @return string
     */
    private function lineDescription($line)
    {
        $descText = !empty($line->desc) ? self::stripNoteHtml($line->desc) : '';
        $label = !empty($line->product_label) ? trim($line->product_label) : '';
        if (!empty($label) && !empty($descText)) {
            return (stripos($descText, $label) !== 0) ? $label . "\n" . $descText : $descText;
        }
        if (!empty($descText)) {
            return $descText;
        }
        return !empty($label) ? $label : '';
    }

    /**
     * @brief Emit differential row
     * @param $xml DOMDocument
     * @param $parent Parent element
     * @param $line Source line
     * @param $lineNum NrWierszaFa
     * @param $qty Quantity delta
     * @param $total Value delta
     * @param $vatRate Rate key
     * @param $useMulti Non-PLN currency
     * @param $unitPrice Override or null
     * @param $kursWalutyOverride Rate override
     * @param $vat P_11Vat or null
     */
    private function buildDifferentialRow($xml, $parent, $line, $lineNum, $qty, $total, $vatRate, $useMulti, $unitPrice = null, $kursWalutyOverride = null, $vat = null)
    {
        $gross = $this->isGrossForm($vatRate);
        $faWiersz = $xml->createElement('FaWiersz');
        $parent->appendChild($faWiersz);

        $faWiersz->appendChild($xml->createElement('NrWierszaFa', $lineNum));

        if (getDolGlobalInt('KSEF_FA3_INCLUDE_UU_ID') && !empty($line->array_options['options_ksef_uu_id'])) {
            $faWiersz->appendChild($xml->createElement('UU_ID', $this->xmlSafe($line->array_options['options_ksef_uu_id'])));
        }

        $p6a = $this->lineDate($line);
        if ($p6a !== null) {
            $faWiersz->appendChild($xml->createElement('P_6A', $p6a));
        }

        $faWiersz->appendChild($xml->createElement('P_7', $this->xmlSafe(mb_substr(self::coreLineMarkers($this->lineDescription($line)), 0, 512, 'UTF-8'))));

        if (getDolGlobalString('KSEF_FA3_INCLUDE_INDEKS') && !empty($line->product_ref)) {
            $faWiersz->appendChild($xml->createElement('Indeks', $this->xmlSafe(substr($line->product_ref, 0, 50))));
        }

        if (getDolGlobalString('KSEF_FA3_INCLUDE_GTIN') && !empty($line->product_barcode)) {
            $faWiersz->appendChild($xml->createElement('GTIN', $this->xmlSafe($line->product_barcode)));
        }

        $this->appendUnit($xml, $faWiersz, $line);

        $faWiersz->appendChild($xml->createElement('P_8B', ksefFormatDecimal($qty, 6)));

        if ($unitPrice === null) {
            $unitPrice = $this->lineUnitPrice($line, $useMulti, $vatRate);
        }
        $faWiersz->appendChild($xml->createElement($gross ? 'P_9B' : 'P_9A', ksefFormatDecimal(abs($unitPrice), 8)));

        $faWiersz->appendChild($xml->createElement($gross ? 'P_11A' : 'P_11', number_format($total, 2, '.', '')));

        if ($vat !== null && !$gross && $this->lineVatOn && preg_match('/^\d+$/', (string) $vatRate)) {
            $faWiersz->appendChild($xml->createElement('P_11Vat', number_format($vat, 2, '.', '')));
        }

        if ($vatRate !== 'marza') {
            $faWiersz->appendChild($xml->createElement('P_12', $vatRate));
        }

        $gtu = $this->getLineGTU($line);
        if (!empty($gtu)) {
            $faWiersz->appendChild($xml->createElement('GTU', $gtu));
        }

        $procedura = $this->getLineProcedura($line);
        if (!empty($procedura)) {
            $faWiersz->appendChild($xml->createElement('Procedura', $procedura));
        }

        $rowKurs = $this->rowKurs($line, $kursWalutyOverride);
        if (!empty($this->currentInvoiceCurrency) && $this->currentInvoiceCurrency != 'PLN' && !empty($rowKurs)) {
            $faWiersz->appendChild($xml->createElement('KursWaluty', number_format($rowKurs, 6, '.', '')));
        }
    }

    /**
     * @brief Emit FaWiersz row
     * @param $xml DOMDocument
     * @param $parent Parent element
     * @param $line Invoice line
     * @param $lineNum NrWierszaFa
     * @param $stanPrzed StanPrzed marker
     * @param $isCreditNote Flips P_8B/P_9A signs
     * @param $kursWalutyOverride Rate override
     * @return void
     */
    private function buildSingleFaWiersz($xml, $parent, $line, $lineNum, $stanPrzed = false, $isCreditNote = false, $kursWalutyOverride = null)
    {
        $useMulti = (!empty($this->currentInvoiceCurrency) && $this->currentInvoiceCurrency != 'PLN');
        $rateKey = $this->rateKey($line);
        $gross = $this->isGrossForm($rateKey);

        $faWiersz = $xml->createElement('FaWiersz');
        $parent->appendChild($faWiersz);

        $faWiersz->appendChild($xml->createElement('NrWierszaFa', $lineNum));

        // XSD sequence
        if (getDolGlobalInt('KSEF_FA3_INCLUDE_UU_ID') && !empty($line->array_options['options_ksef_uu_id'])) {
            $faWiersz->appendChild($xml->createElement('UU_ID', $this->xmlSafe($line->array_options['options_ksef_uu_id'])));
        }

        $p6a = $this->lineDate($line);
        if ($p6a !== null) {
            $faWiersz->appendChild($xml->createElement('P_6A', $p6a));
        }

        $faWiersz->appendChild($xml->createElement('P_7', $this->xmlSafe(mb_substr(self::coreLineMarkers($this->lineDescription($line)), 0, 512, 'UTF-8'))));

        if (getDolGlobalString('KSEF_FA3_INCLUDE_INDEKS') && !empty($line->product_ref)) {
            $faWiersz->appendChild($xml->createElement('Indeks', $this->xmlSafe(substr($line->product_ref, 0, 50))));
        }

        if (getDolGlobalString('KSEF_FA3_INCLUDE_GTIN') && !empty($line->product_barcode)) {
            $faWiersz->appendChild($xml->createElement('GTIN', $this->xmlSafe($line->product_barcode)));
        }

        $this->appendUnit($xml, $faWiersz, $line);

        // KSeF: -qty/+price
        $qty = $isCreditNote ? -abs($line->qty) : $line->qty;
        $faWiersz->appendChild($xml->createElement('P_8B', ksefFormatDecimal($qty, 6)));

        $unitPrice = $this->lineUnitPrice($line, $useMulti, $rateKey);
        if ($isCreditNote) {
            $unitPrice = abs($unitPrice);
        }
        $unitText = ksefFormatDecimal($unitPrice, 8);
        $faWiersz->appendChild($xml->createElement($gross ? 'P_9B' : 'P_9A', $unitText));

        $lineTotal = $gross
            ? (float) ($useMulti ? $line->multicurrency_total_ttc : $line->total_ttc)
            : (float) ($useMulti ? $line->multicurrency_total_ht : $line->total_ht);

        // P_8BxP_9-P_10=P_11
        if (!$isCreditNote && !empty($line->remise_percent) && $line->remise_percent > 0) {
            $discountAmount = round((float) $unitText * (float) $line->qty - $lineTotal, 8);
            if ($discountAmount > 0) {
                $faWiersz->appendChild($xml->createElement('P_10', ksefFormatDecimal($discountAmount, 8)));
            }
        }

        $faWiersz->appendChild($xml->createElement($gross ? 'P_11A' : 'P_11', number_format($lineTotal, 2, '.', '')));

        if (!$gross && $this->lineVatOn && preg_match('/^\d+$/', (string) $rateKey)) {
            $faWiersz->appendChild($xml->createElement('P_11Vat', number_format($this->lineVat($line, $useMulti), 2, '.', '')));
        }

        if ($rateKey !== 'marza') {
            $faWiersz->appendChild($xml->createElement('P_12', $rateKey));
        }

        $gtu = $this->getLineGTU($line);
        if (!empty($gtu)) {
            $faWiersz->appendChild($xml->createElement('GTU', $gtu));
        }

        $procedura = $this->getLineProcedura($line);
        if (!empty($procedura)) {
            $faWiersz->appendChild($xml->createElement('Procedura', $procedura));
        }

        $kursWaluty = $this->rowKurs($line, $kursWalutyOverride);
        if (!empty($this->currentInvoiceCurrency) && $this->currentInvoiceCurrency != 'PLN' && !empty($kursWaluty)) {
            $faWiersz->appendChild($xml->createElement('KursWaluty', number_format($kursWaluty, 6, '.', '')));
        }

        if ($stanPrzed) {
            $faWiersz->appendChild($xml->createElement('StanPrzed', '1'));
        }

    }

    /**
     * @brief KOR lines differ
     * @param $origLine Original line
     * @param $corrLine Correction line
     * @return bool
     */
    private function linesAreDifferent($origLine, $corrLine)
    {
        $useMulti = (!empty($this->currentInvoiceCurrency) && $this->currentInvoiceCurrency != 'PLN');

        $origDesc = !empty($origLine->desc) ? self::stripNoteHtml($origLine->desc) : '';
        $corrDesc = !empty($corrLine->desc) ? self::stripNoteHtml($corrLine->desc) : '';
        $origLabel = !empty($origLine->product_label) ? trim($origLine->product_label) : '';
        $corrLabel = !empty($corrLine->product_label) ? trim($corrLine->product_label) : '';
        if ($origDesc !== $corrDesc || $origLabel !== $corrLabel) return true;

        if (getDolGlobalString('KSEF_FA3_INCLUDE_INDEKS')) {
            $origRef = !empty($origLine->product_ref) ? substr($origLine->product_ref, 0, 50) : '';
            $corrRef = !empty($corrLine->product_ref) ? substr($corrLine->product_ref, 0, 50) : '';
            if ($origRef !== $corrRef) return true;
        }

        if (getDolGlobalString('KSEF_FA3_INCLUDE_GTIN')) {
            $origGtin = !empty($origLine->product_barcode) ? $origLine->product_barcode : '';
            $corrGtin = !empty($corrLine->product_barcode) ? $corrLine->product_barcode : '';
            if ($origGtin !== $corrGtin) return true;
        }

        if (getDolGlobalInt('KSEF_FA3_INCLUDE_UNIT')) {
            if ((int) ($origLine->fk_unit ?? 0) !== (int) ($corrLine->fk_unit ?? 0)) return true;
        }

        if (ksefFormatDecimal($origLine->qty, 6) !== ksefFormatDecimal($corrLine->qty, 6)) return true;

        $origRate = $this->rateKey($origLine);
        $corrRate = $this->rateKey($corrLine);
        if (ksefFormatDecimal($this->lineUnitPrice($origLine, $useMulti, $origRate), 8) !== ksefFormatDecimal($this->lineUnitPrice($corrLine, $useMulti, $corrRate), 8)) return true;

        $origDiscount = (!empty($origLine->remise_percent) && $origLine->remise_percent > 0) ? $origLine->remise_percent : 0;
        $corrDiscount = (!empty($corrLine->remise_percent) && $corrLine->remise_percent > 0) ? $corrLine->remise_percent : 0;
        if (number_format($origDiscount, 2, '.', '') !== number_format($corrDiscount, 2, '.', '')) return true;

        if (number_format($this->lineAmount($origLine, $useMulti), 2, '.', '') !== number_format($this->lineAmount($corrLine, $useMulti), 2, '.', '')) return true;
        $origTotal = $useMulti ? $origLine->multicurrency_total_ht : $origLine->total_ht;
        $corrTotal = $useMulti ? $corrLine->multicurrency_total_ht : $corrLine->total_ht;
        if (number_format($origTotal, 2, '.', '') !== number_format($corrTotal, 2, '.', '')) return true;

        if ($origRate !== $corrRate || ksefMarginSubtype($origLine) !== ksefMarginSubtype($corrLine)) return true;

        return false;
    }

    /**
     * @brief Build Platnosc
     * @param $xml DOMDocument
     * @param $parent Parent element
     * @param $invoice Invoice object
     */
    private function buildPlatnosc($xml, $parent, $invoice)
    {
        $platnosc = $xml->createElement('Platnosc');

        $fullyPaid = false;
        if ($invoice->statut >= 1) {
            $payments = $invoice->getListOfPayments('', ksefGetInvoiceCurrency($invoice) !== 'PLN' ? 1 : 0, 1);

            if (!empty($payments)) {
                $isFullyPaid = !empty($invoice->paye);
                $fullyPaid = $isFullyPaid;
                $paymentCount = count($payments);

                if ($isFullyPaid && $paymentCount == 1) {
                    $platnosc->appendChild($xml->createElement('Zaplacono', '1'));
                    $payDate = ksefMomentDay($this->db->jdate($payments[0]['date']));
                    $platnosc->appendChild($xml->createElement('DataZaplaty', $payDate));
                } else {
                    // 1 partial, 2=paid
                    $znacznik = $isFullyPaid ? '2' : '1';
                    $platnosc->appendChild($xml->createElement('ZnacznikZaplatyCzesciowej', $znacznik));

                    foreach (array_slice($payments, 0, 100) as $payment) {
                        $zaplataCzesciowa = $xml->createElement('ZaplataCzesciowa');
                        $platnosc->appendChild($zaplataCzesciowa);

                        $zaplataCzesciowa->appendChild($xml->createElement(
                            'KwotaZaplatyCzesciowej',
                            number_format((float)$payment['amount'], 2, '.', '')
                        ));
                        $zaplataCzesciowa->appendChild($xml->createElement(
                            'DataZaplatyCzesciowej',
                            ksefMomentDay($this->db->jdate($payment['date']))
                        ));

                        if (!empty($payment['type'])) {
                            $this->appendPaymentForm($xml, $zaplataCzesciowa, ksefPaymentForm($this->db, $payment['type']));
                        }
                    }
                }
            }
        }

        $due = $this->dueDay($invoice, $fullyPaid);
        if ($due !== '') {
            $terminPlatnosci = $xml->createElement('TerminPlatnosci');
            $platnosc->appendChild($terminPlatnosci);
            $terminPlatnosci->appendChild($xml->createElement('Termin', $due));
        }

        $form = ksefPaymentForm($this->db, $this->paymentModeCode($invoice));
        $this->appendPaymentForm($xml, $platnosc, $form);

        $noForm = ($form['form'] === '' && $form['other'] === '');
        if (($form['form'] === '6' || $noForm) && !empty($invoice->fk_account)) {
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
                if (getDolGlobalString('KSEF_FA3_INCLUDE_BANK_DESC') && !empty($account->label)) {
                    $rachunekBankowy->appendChild($xml->createElement('OpisRachunku', $this->xmlSafe($account->label)));
                }
            }
        }

        if (getDolGlobalString('KSEF_PODMIOT3_SOURCE', 'disabled') === 'enabled') {
            $this->buildRachunekBankowyFaktora($xml, $platnosc, $invoice);
        }

        if ($platnosc->hasChildNodes()) {
            $parent->appendChild($platnosc);
        }
    }

    /**
     * @brief Emit payment form
     * @param DOMDocument $xml
     * @param DOMElement $parent Platnosc or ZaplataCzesciowa
     * @param array $form ksefPaymentForm() result
     */
    private function appendPaymentForm($xml, $parent, $form)
    {
        if ($form['form'] !== '') {
            $parent->appendChild($xml->createElement('FormaPlatnosci', $form['form']));
        } elseif ($form['other'] !== '') {
            $parent->appendChild($xml->createElement('PlatnoscInna', '1'));
            $parent->appendChild($xml->createElement('OpisPlatnosci', $this->xmlSafe($form['other'])));
        }
    }

    /**
     * @brief Invoice payment code
     * @param Facture $invoice
     * @return string '' if none
     */
    private function paymentModeCode($invoice)
    {
        if (empty($invoice->mode_reglement_id)) {
            return '';
        }
        if (!empty($invoice->mode_reglement_code)) {
            return (string) $invoice->mode_reglement_code;
        }
        $res = $this->db->query("SELECT code FROM " . MAIN_DB_PREFIX . "c_paiement WHERE id = " . ((int) $invoice->mode_reglement_id));
        $o = $res ? $this->db->fetch_object($res) : null;
        return $o ? (string) $o->code : '';
    }

    /**
     * @brief TerminPlatnosci date
     * @param Facture $invoice
     * @param bool $fullyPaid
     * @return string Y-m-d or ''
     */
    private function dueDay($invoice, $fullyPaid)
    {
        if ($fullyPaid || (int) $invoice->type === Facture::TYPE_CREDIT_NOTE || empty($invoice->date_lim_reglement)) {
            return '';
        }
        $dueDay = dol_print_date($invoice->date_lim_reglement, '%Y-%m-%d', 'tzserver');
        if (empty($invoice->cond_reglement_id) && $dueDay <= dol_print_date($invoice->date, '%Y-%m-%d', 'tzserver')) {
            return '';
        }
        return $dueDay;
    }

    /**
     * @brief Emit factor accounts
     */
    private function buildRachunekBankowyFaktora($xml, $platnosc, $invoice)
    {
        $emitted = 0;
        foreach ($this->getPodmiot3List($invoice) as $e) {
            if ($e['role'] !== '1') {
                continue;
            }
            $accounts = $this->getSocieteBankAccounts((int) $e['id']);
            if (empty($accounts)) {
                dol_syslog('FA3Builder::buildRachunekBankowyFaktora - factor societe ' . $e['id'] . ' has no bank account on file; RachunekBankowyFaktora omitted', LOG_WARNING);
                continue;
            }
            foreach ($accounts as $account) {
                if ($emitted >= 20) {
                    break 2;
                }
                $rb = $xml->createElement('RachunekBankowyFaktora');
                $platnosc->appendChild($rb);
                $rb->appendChild($xml->createElement('NrRB', $this->cleanIBAN($account->iban)));
                if (!empty($account->bic)) {
                    $rb->appendChild($xml->createElement('SWIFT', strtoupper(trim($account->bic))));
                }
                if (!empty($account->bank)) {
                    $rb->appendChild($xml->createElement('NazwaBanku', $this->xmlSafe($account->bank)));
                }
                if (!empty($account->label)) {
                    $rb->appendChild($xml->createElement('OpisRachunku', $this->xmlSafe($account->label)));
                }
                $emitted++;
            }
        }
    }

    /**
     * @brief Third-party bank accounts
     * @param int $socid
     * @return array CompanyBankAccount list
     */
    private function getSocieteBankAccounts($socid)
    {
        if ($socid <= 0) {
            return array();
        }
        require_once DOL_DOCUMENT_ROOT . '/societe/class/companybankaccount.class.php';
        $accounts = array();
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "societe_rib"
            . " WHERE fk_soc = " . ((int) $socid) . " AND type = 'ban'"
            . " ORDER BY default_rib DESC, rowid ASC";
        $res = $this->db->query($sql);
        if ($res) {
            while ($obj = $this->db->fetch_object($res)) {
                $acc = new CompanyBankAccount($this->db);
                if ($acc->fetch($obj->rowid) > 0 && !empty($acc->iban)) {
                    $accounts[] = $acc;
                }
            }
        }
        return $accounts;
    }

    /**
     * @brief Build WarunkiTransakcji
     * @param DOMDocument $xml
     * @param DOMElement $parent Fa element
     * @param object $invoice
     */
    private function buildWarunkiTransakcji($xml, $parent, $invoice)
    {
        global $conf;

        $orderRefs = array();
        $zamowieniaSource = getDolGlobalString('KSEF_NR_ZAMOWIENIA_SOURCE', 'ref_client');
        if ($zamowieniaSource === 'ref_client') {
            $val = isset($invoice->ref_client) ? trim((string) $invoice->ref_client) : '';
            if (!empty($val)) $orderRefs[] = array(mb_substr($val, 0, 256, 'UTF-8'), '');
        } elseif ($zamowieniaSource === 'linked_order') {
            foreach ($this->getLinkedOrderRefs($invoice) as $o) {
                $orderRefs[] = array(mb_substr($o['ref'], 0, 256, 'UTF-8'), $o['date']);
            }
        } elseif (strpos($zamowieniaSource, 'extrafield:') === 0) {
            $fieldName = substr($zamowieniaSource, strlen('extrafield:'));
            if (!empty($fieldName)) {
                if (!isset($invoice->array_options) || empty($invoice->array_options)) {
                    $invoice->fetch_optionals();
                }
                $val = isset($invoice->array_options['options_' . $fieldName])
                    ? trim(strip_tags((string) $invoice->array_options['options_' . $fieldName]))
                    : '';
                $date = '';
                if ($val !== '' && getDolGlobalInt('KSEF_NR_ZAMOWIENIA_PARSE_DATE')) {
                    $parsed = ksefParseContractDate($val);
                    if (!empty($parsed['date']) && $parsed['nr'] !== '') {
                        $date = $parsed['date'];
                        $val = $parsed['nr'];
                    }
                }
                if (!empty($val)) $orderRefs[] = array(mb_substr($val, 0, 256, 'UTF-8'), $date);
            }
        }
        if ($orderRefs && getDolGlobalInt('KSEF_FA3_ORDER_DATE_FIELD')) {
            $efDate = $this->extrafieldDay($invoice, 'ksef_order_date');
            if ($efDate !== '' && $efDate >= '1990-01-01' && $efDate <= '2050-01-01') {
                foreach ($orderRefs as $i => $o) {
                    if ($o[1] === '') {
                        $orderRefs[$i][1] = $efDate;
                    }
                }
            }
        }

        $nrUmowy = '';
        $umowySource = getDolGlobalString('KSEF_NR_UMOWY_SOURCE', 'disabled');
        if (strpos($umowySource, 'thirdparty_extrafield:') === 0) {
            $fieldName = substr($umowySource, strlen('thirdparty_extrafield:'));
            if (!empty($fieldName) && !empty($this->currentCustomer)) {
                if (!isset($this->currentCustomer->array_options) || empty($this->currentCustomer->array_options)) {
                    $this->currentCustomer->fetch_optionals();
                }
                $nrUmowy = isset($this->currentCustomer->array_options['options_' . $fieldName])
                    ? trim(strip_tags((string) $this->currentCustomer->array_options['options_' . $fieldName]))
                    : '';
            }
        } elseif (strpos($umowySource, 'extrafield:') === 0) {
            $fieldName = substr($umowySource, strlen('extrafield:'));
            if (!empty($fieldName)) {
                if (!isset($invoice->array_options) || empty($invoice->array_options)) {
                    $invoice->fetch_optionals();
                }
                $nrUmowy = isset($invoice->array_options['options_' . $fieldName])
                    ? trim(strip_tags((string) $invoice->array_options['options_' . $fieldName]))
                    : '';
            }
        }
        $dataUmowy = '';
        if (!empty($nrUmowy) && getDolGlobalInt('KSEF_NR_UMOWY_PARSE_DATE')) {
            $parsed = ksefParseContractDate($nrUmowy);
            if (!empty($parsed['date'])) {
                $dataUmowy = $parsed['date'];
                $nrUmowy = $parsed['nr'];
            }
        }
        $nrUmowy = mb_substr($nrUmowy, 0, 256, 'UTF-8');

        if (empty($orderRefs) && empty($nrUmowy) && empty($dataUmowy)) {
            return;
        }

        $warunki = $xml->createElement('WarunkiTransakcji');
        $parent->appendChild($warunki);

        if (!empty($nrUmowy) || !empty($dataUmowy)) {
            $umowy = $xml->createElement('Umowy');
            $warunki->appendChild($umowy);
            if (!empty($dataUmowy)) {
                $umowy->appendChild($xml->createElement('DataUmowy', $dataUmowy));
            }
            if (!empty($nrUmowy)) {
                $umowy->appendChild($xml->createElement('NrUmowy', $this->xmlSafe($nrUmowy)));
            }
        }

        foreach (array_slice($orderRefs, 0, 100) as $o) {
            $zamowienia = $xml->createElement('Zamowienia');
            $warunki->appendChild($zamowienia);
            if ($o[1] !== '') {
                $zamowienia->appendChild($xml->createElement('DataZamowienia', $o[1]));
            }
            $zamowienia->appendChild($xml->createElement('NrZamowienia', $this->xmlSafe($o[0])));
        }
    }

    /**
     * @brief Build Stopka
     * @param DOMDocument $xml
     * @param DOMElement $parent Parent element
     */
    private function buildStopka($xml, $parent, $invoice = null)
    {
        global $conf, $mysoc;

        $krs = trim(getDolGlobalString('KSEF_COMPANY_KRS'));
        $regon = trim(getDolGlobalString('KSEF_COMPANY_REGON'));
        $bdo = trim(getDolGlobalString('KSEF_COMPANY_BDO'));
        if (empty($krs)) {
            $krs = trim(ksefGetIdentifierField($mysoc, 'KRS'));
        }
        if (empty($regon)) {
            $regon = trim(ksefGetIdentifierField($mysoc, 'REGON'));
        }
        if (empty($bdo)) {
            $bdo = trim(ksefGetIdentifierField($mysoc, 'BDO'));
        }

        $hasRejestry = !empty($krs) || !empty($regon) || !empty($bdo);

        $stopkaTexts = array();

        $noteTarget = getDolGlobalString('KSEF_NOTE_PUBLIC_TARGET', 'stopka_faktury');
        $noteMode = getDolGlobalString('KSEF_DODATKOWY_OPIS_NOTE_MODE', 'simple');
        $includeNoteInStopka = ($noteTarget === 'stopka_faktury' && $noteMode !== 'disabled');

        $isDisabledOverride = false;
        if ($invoice) {
            if (!isset($invoice->array_options) || empty($invoice->array_options)) {
                $invoice->fetch_optionals();
            }
            $invoiceOverride = isset($invoice->array_options['options_ksef_dodatkowy_opis_mode'])
                ? trim((string) $invoice->array_options['options_ksef_dodatkowy_opis_mode'])
                : '';
            if (!empty($invoiceOverride)) {
                if ($invoiceOverride === 'disabled') {
                    $isDisabledOverride = true;
                    $includeNoteInStopka = false;
                } else {
                    $parsed = self::parseCombinedNoteMode($invoiceOverride);
                    if ($parsed) {
                        $includeNoteInStopka = ($parsed['target'] === 'stopka_faktury' && $parsed['mode'] !== 'disabled');
                    }
                }
            }
        }

        if ($includeNoteInStopka && $invoice && !empty($invoice->note_public)) {
            $text = trim(self::stripNoteHtml($invoice->note_public));
            if (!empty($text)) {
                $stopkaTexts[] = mb_substr($text, 0, 3500, 'UTF-8');
            }
        }

        $boilerplate = getDolGlobalString('KSEF_STOPKA_BOILERPLATE', '');
        if (!empty($boilerplate)) {
            $stopkaTexts[] = mb_substr(trim($boilerplate), 0, 3500, 'UTF-8');
        }

        if ($invoice && !$isDisabledOverride) {
            $stopkaEfText = self::collectStopkaExtrafields($invoice, $conf, $this->db, $this->currentCustomer);
            if (!empty($stopkaEfText)) {
                $stopkaTexts[] = mb_substr($stopkaEfText, 0, 3500, 'UTF-8');
            }
        }

        if (!$hasRejestry && empty($stopkaTexts)) {
            return;
        }

        $stopka = $xml->createElement('Stopka');
        $parent->appendChild($stopka);

        // XSD sequence
        if (!empty($stopkaTexts)) {
            foreach ($stopkaTexts as $footerText) {
                $informacje = $xml->createElement('Informacje');
                $stopka->appendChild($informacje);
                $informacje->appendChild($xml->createElement('StopkaFaktury', $this->xmlSafe($footerText)));
            }
        }

        if ($hasRejestry) {
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
    }

    /**
     * @brief Clean IBAN
     * @param $iban
     * @return string
     */
    private function cleanIBAN($iban)
    {
        return preg_replace('/\s+/', '', strtoupper($iban));
    }

    /**
     * @brief Escape for XML
     * @param $str
     * @return string
     */
    private function xmlSafe($str)
    {
        return ksefXmlEscape(trim($str));
    }

    /**
     * @brief Previous KSeF number
     * @param $invoice_id
     * @return string|null
     */
    private function getInvoiceKsefNumber($invoice_id)
    {
        return ksefAcceptedKsefNumber($this->db, $invoice_id);
    }

    /**
     * @brief JST/VAT group flags
     * @param $entity
     * @param Facture|null $invoice Null: buyer only
     * @return array jst, gv
     */
    private function getEntityFlags($entity, $invoice = null)
    {
        $f = ksefGvJstFlags($entity, $invoice);
        return array('jst' => $f['jst'], 'gv' => $f['gv']);
    }

    /**
     * @brief Validate against XSD
     * @param $xml XML string
     * @return bool
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
     * @brief Is EU country
     * @param string $countryCode ISO alpha-2
     * @return bool
     */
    private function isEUCountry($countryCode)
    {
        return ksefIsEuCountry($countryCode);
    }

    /**
     * @brief Linked order refs
     * @param object $invoice
     * @return array (ref, date) pairs
     */
    private function getLinkedOrderRefs($invoice)
    {
        $invoice->fetchObjectLinked(null, '', null, '', 'OR', 1, 'sourcetype', 0);
        if (empty($invoice->linkedObjectsIds['commande'])) return array();

        require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
        $refs = array();
        foreach ($invoice->linkedObjectsIds['commande'] as $orderId) {
            $order = new Commande($this->db);
            if ($order->fetch($orderId) > 0) {
                $ref = trim($order->ref);
                if (empty($ref)) continue;
                $ts = !empty($order->date_commande) ? $order->date_commande : ($order->date ?? 0);
                $day = !empty($ts) ? dol_print_date($ts, '%Y-%m-%d', 'tzserver') : '';
                if ($day !== '' && ($day < '1990-01-01' || $day > '2050-01-01')) {
                    $day = '';
                }
                $refs[] = array('ref' => $ref, 'date' => $day);
            }
        }
        return $refs;
    }

    /**
     * @brief Shipment WZ refs
     * @param object $invoice
     * @return array
     */
    private function getLinkedShipmentRefs($invoice)
    {
        // Shipments maybe off
        $P = MAIN_DB_PREFIX;
        $id = (int) $invoice->id;
        $sql = "SELECT e.ref FROM " . $P . "element_element AS ee JOIN " . $P . "expedition AS e ON e.rowid ="
            . " CASE WHEN ee.fk_target = " . $id . " AND ee.targettype = 'facture' THEN ee.fk_source ELSE ee.fk_target END"
            . " WHERE ((ee.fk_target = " . $id . " AND ee.targettype = 'facture' AND ee.sourcetype IN ('shipping', 'expedition'))"
            . " OR (ee.fk_source = " . $id . " AND ee.sourcetype = 'facture' AND ee.targettype IN ('shipping', 'expedition')))"
            . " AND e.fk_statut >= 1 ORDER BY e.date_creation, e.rowid";
        $refs = array();
        $res = $this->db->query($sql);
        while ($res && ($o = $this->db->fetch_object($res))) {
            $ref = mb_substr(trim((string) $o->ref), 0, 256, 'UTF-8');
            if ($ref !== '' && !in_array($ref, $refs, true)) {
                $refs[] = $ref;
            }
        }
        return array_slice($refs, 0, 1000);
    }

    /**
     * @brief Exemption basis
     * @param Facture $invoice
     * @return array array(has exempt line, basis text, P_19 element or 'disabled')
     */
    public function exemptionBasis($invoice)
    {
        $hasExempt = false;
        foreach ($invoice->lines as $line) {
            if ($this->rateKey($line) === 'zw') {
                $hasExempt = true;
                break;
            }
        }
        $globalPodstawa = getDolGlobalString('KSEF_ZWOLNIENIE_PODSTAWA', '');
        $zwolnienieType = getDolGlobalString('KSEF_ZWOLNIENIE_TYPE', 'disabled');
        $productField = getDolGlobalString('KSEF_ZWOLNIENIE_PRODUCT_FIELD', '');

        $podstawa = '';
        if ($hasExempt && $zwolnienieType !== 'disabled') {
            $bases = array();
            if (!empty($productField) && !empty($invoice->lines)) {
                require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
                $productCache = array();
                foreach ($invoice->lines as $line) {
                    $ksefRate = $this->rateKey($line);
                    if ($ksefRate !== 'zw' || empty($line->fk_product)) continue;

                    if (!isset($productCache[$line->fk_product])) {
                        $prod = new Product($this->db);
                        if ($prod->fetch($line->fk_product) > 0) {
                            $prod->fetch_optionals();
                            $productCache[$line->fk_product] = $prod;
                        }
                    }
                    if (isset($productCache[$line->fk_product])) {
                        $val = $productCache[$line->fk_product]->array_options['options_' . $productField] ?? '';
                        $val = trim(strip_tags((string) $val));
                        if (!empty($val)) $bases[$val] = true;
                    }
                }
            }

            if (!empty($bases)) {
                $podstawa = implode(', ', array_keys($bases));
            } elseif (!empty($globalPodstawa)) {
                $podstawa = $globalPodstawa;
            }
        }

        return array($hasExempt, $podstawa, $zwolnienieType);
    }
}