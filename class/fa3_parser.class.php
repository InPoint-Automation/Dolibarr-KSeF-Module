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

/**
 * \file    ksef/class/fa3_parser.class.php
 * \ingroup ksef
 * \brief   FA(3) XML parser
 */

dol_include_once('/ksef/lib/ksef.lib.php');

class FA3Parser
{
    const PAYMENT_PAID = 'paid';
    const PAYMENT_PAID_INSTALLMENTS = 'paid_installments';
    const PAYMENT_PARTIAL = 'partial';
    const PAYMENT_UNPAID = 'unpaid';

    private $db;
    public $error;
    public $errors = array();
    /** @var string */
    public $unsupported_form = '';

    /**
     * @brief Constructor
     * @param DoliDB $db
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * @brief Parse FA(3) XML
     * @param $xml
     * @return array|false
     */
    public function parse($xml)
    {
        $this->unsupported_form = '';
        if (empty($xml)) {
            $this->error = "Empty XML provided";
            return false;
        }

        try {
            $doc = new DOMDocument();
            $doc->preserveWhiteSpace = false;

            $loaded = @$doc->loadXML($xml);
            if (!$loaded) {
                $this->error = "Failed to parse XML";
                return false;
            }

            $rootNs = $doc->documentElement->namespaceURI;
            if ($rootNs !== KSEF_FA3_NAMESPACE) {
                $kodSystemowy = '';
                $kodNodes = $doc->getElementsByTagName('KodFormularza');
                if ($kodNodes->length > 0) {
                    $kodSystemowy = $kodNodes->item(0)->getAttribute('kodSystemowy');
                }
                if (!$kodSystemowy && strpos((string) $rootNs, 'urn:oasis:names:specification:ubl:schema:xsd:') === 0) {
                    $kodSystemowy = (strpos((string) $rootNs, 'CreditNote') !== false) ? 'FA_KOR_PEF (3)' : 'FA_PEF (3)';
                }
                $this->unsupported_form = $kodSystemowy ?: 'unknown';
                $this->error = "Unsupported invoice schema: " . ($kodSystemowy ?: 'unknown') . " (namespace: {$rootNs}). Only FA(3) is supported.";
                return false;
            }

            $xpath = new DOMXPath($doc);
            $xpath->registerNamespace('fa', KSEF_FA3_NAMESPACE);

            $invoice = $this->parseFa($xpath);
            $lineData = $this->parseFaWiersz($xpath);

            $linesSource = 'fawiersz';
            $zamData = $this->parseZamowienieWiersz($xpath);
            if (empty($lineData['lines']) && empty($lineData['lines_before'])) {
                if (!empty($zamData['lines']) || !empty($zamData['lines_before'])) {
                    $lineData = $zamData;
                    $linesSource = 'order';
                }
            }

            $result = array(
                'header' => $this->parseNaglowek($xpath),
                'seller' => $this->parsePodmiot1($xpath),
                'buyer' => $this->parsePodmiot2($xpath),
                'buyer_before' => $this->parsePodmiot2K($xpath),
                'third_parties' => $this->parsePodmiot3($xpath),
                'invoice' => $invoice,
                'vat_summary' => $this->parseVatSummary($xpath),
                'lines' => $lineData['lines'],
                'lines_before' => $lineData['lines_before'],
                'payment' => $this->parsePlatnosc($xpath),
                'correction' => $this->parseDaneFaKorygowanej($xpath),
                'registries' => $this->parseRegistries($xpath),
                'settlement' => $this->parseRozliczenie($xpath),
                'stopka' => $this->parseStopka($xpath),
                'additional_info' => $this->parseAdditionalInfo($invoice),
                'additional_desc' => $this->parseAdditionalDesc($xpath),
                'exchange_rate' => $this->parseExchangeRate($xpath, $lineData['lines'], $invoice['type'] ?? ''),
                'warunki' => $this->parseWarunkiTransakcji($xpath),
                'lines_source' => $linesSource,
                'deposits' => $this->parseFakturaZaliczkowa($xpath),
                'partial_payments' => $this->parseZaliczkaCzesciowa($xpath),
                'order' => array(
                    'value' => $this->getValue($xpath, '//fa:Fa/fa:Zamowienie/fa:WartoscZamowienia', null),
                    'lines' => $zamData['lines'],
                    'lines_before' => $zamData['lines_before'],
                ),
            );

            return $result;

        } catch (Throwable $e) {
            // keep return-false contract
            $this->error = "FA(3) parsing error: " . get_class($e) . ': ' . $e->getMessage();
            $this->errors[] = $this->error;
            dol_syslog("FA3Parser::parse ERROR: " . $this->error, LOG_ERR);
            return false;
        }
    }

    /**
     * @brief Parse Naglowek
     * @param $xpath
     * @return array
     */
    private function parseNaglowek($xpath)
    {
        $header = array(
            'form_code' => '',
            'system_code' => '',
            'schema_version' => '',
            'form_variant' => '',
            'creation_date' => null,
            'system_info' => '',
        );

        $kodFormularza = $xpath->query('//fa:Naglowek/fa:KodFormularza');
        if ($kodFormularza->length > 0) {
            $node = $kodFormularza->item(0);
            $header['form_code'] = $node->textContent;
            $header['system_code'] = $node->getAttribute('kodSystemowy');
            $header['schema_version'] = $node->getAttribute('wersjaSchemy');
        }

        $header['form_variant'] = $this->getValue($xpath, '//fa:Naglowek/fa:WariantFormularza');

        $header['creation_date'] = $this->getValue($xpath, '//fa:Naglowek/fa:DataWytworzeniaFa', null);

        $header['system_info'] = $this->getValue($xpath, '//fa:Naglowek/fa:SystemInfo');

        return $header;
    }

    /**
     * @brief Parse Podmiot1
     * @param $xpath
     * @return array
     */
    private function parsePodmiot1($xpath)
    {
        $seller = array(
            'nip' => '',
            'name' => '',
            'country' => 'PL',
            'address' => '',
            'email' => null,
            'phone' => null,
            'kod_ue' => null,
            'nr_vat_ue' => null,
            'nr_eori' => null,
        );

        $nrEori = $this->getValue($xpath, '//fa:Podmiot1/fa:NrEORI', null);
        if ($nrEori) $seller['nr_eori'] = $nrEori;

        $seller['nip'] = $this->getValue($xpath, '//fa:Podmiot1/fa:DaneIdentyfikacyjne/fa:NIP');

        $seller['name'] = $this->getValue($xpath, '//fa:Podmiot1/fa:DaneIdentyfikacyjne/fa:Nazwa');
        $seller['country'] = $this->getValue($xpath, '//fa:Podmiot1/fa:Adres/fa:KodKraju', 'PL');
        $seller['address'] = $this->getValue($xpath, '//fa:Podmiot1/fa:Adres/fa:AdresL1');

        $adresL2 = $this->getValue($xpath, '//fa:Podmiot1/fa:Adres/fa:AdresL2');
        if ($adresL2) {
            $seller['address'] .= "\n" . $adresL2;
        }

        $email = $this->getValue($xpath, '//fa:Podmiot1/fa:DaneKontaktowe/fa:Email', null);
        if ($email) $seller['email'] = $email;
        $phone = $this->getValue($xpath, '//fa:Podmiot1/fa:DaneKontaktowe/fa:Telefon', null);
        if ($phone) $seller['phone'] = $phone;

        return $seller;
    }

    /**
     * @brief Parse Podmiot2
     * @param $xpath
     * @return array
     */
    private function parsePodmiot2($xpath)
    {
        $buyer = array(
            'nip' => '',
            'name' => '',
            'country' => 'PL',
            'address' => '',
            'jst' => '',
            'gv' => '',
            'email' => null,
            'phone' => null,
            'customer_number' => null,
            'kod_ue' => null,
            'nr_vat_ue' => null,
            'nr_eori' => null,
        );

        $nrEori = $this->getValue($xpath, '//fa:Podmiot2/fa:NrEORI', null);
        if ($nrEori) $buyer['nr_eori'] = $nrEori;

        $buyer = array_merge($buyer, $this->parseIdentity($xpath, '//fa:Podmiot2/fa:DaneIdentyfikacyjne'));
        $buyer['nip'] = $buyer['nip'] !== '' ? $buyer['nip'] : (string) $buyer['nr_id'];
        $idNabywcy = $this->getValue($xpath, '//fa:Podmiot2/fa:IDNabywcy', null);
        if ($idNabywcy) $buyer['id_nabywcy'] = $idNabywcy;

        $buyer['name'] = $this->getValue($xpath, '//fa:Podmiot2/fa:DaneIdentyfikacyjne/fa:Nazwa');

        $adresKodKraju = $this->getValue($xpath, '//fa:Podmiot2/fa:Adres/fa:KodKraju');
        if ($adresKodKraju) {
            $buyer['country'] = $adresKodKraju;
        }

        $buyer['address'] = $this->getValue($xpath, '//fa:Podmiot2/fa:Adres/fa:AdresL1');
        $adresL2 = $this->getValue($xpath, '//fa:Podmiot2/fa:Adres/fa:AdresL2');
        if ($adresL2) {
            $buyer['address'] .= "\n" . $adresL2;
        }

        $buyer['jst'] = $this->getValue($xpath, '//fa:Podmiot2/fa:JST');
        $buyer['gv'] = $this->getValue($xpath, '//fa:Podmiot2/fa:GV');

        $email = $this->getValue($xpath, '//fa:Podmiot2/fa:DaneKontaktowe/fa:Email', null);
        if ($email) $buyer['email'] = $email;
        $phone = $this->getValue($xpath, '//fa:Podmiot2/fa:DaneKontaktowe/fa:Telefon', null);
        if ($phone) $buyer['phone'] = $phone;

        $nrKlienta = $this->getValue($xpath, '//fa:Podmiot2/fa:NrKlienta', null);
        if ($nrKlienta) $buyer['customer_number'] = $nrKlienta;

        return $buyer;
    }

    /**
     * @brief Parse Podmiot2K
     * @param $xpath
     * @return array|null
     */
    private function parsePodmiot2K($xpath)
    {
        $node = $xpath->query('//fa:Fa/fa:Podmiot2K');
        if ($node->length == 0) {
            return null;
        }

        $buyer = array(
            'nip' => '',
            'name' => '',
            'country' => 'PL',
            'address' => '',
            'kod_ue' => null,
            'nr_vat_ue' => null,
        );

        $buyer = array_merge($buyer, $this->parseIdentity($xpath, '//fa:Fa/fa:Podmiot2K/fa:DaneIdentyfikacyjne'));
        $buyer['nip'] = $buyer['nip'] !== '' ? $buyer['nip'] : (string) $buyer['nr_id'];

        $buyer['name'] = $this->getValue($xpath, '//fa:Fa/fa:Podmiot2K/fa:DaneIdentyfikacyjne/fa:Nazwa');

        $adresKodKraju = $this->getValue($xpath, '//fa:Fa/fa:Podmiot2K/fa:Adres/fa:KodKraju');
        if ($adresKodKraju) {
            $buyer['country'] = $adresKodKraju;
        }

        $buyer['address'] = $this->getValue($xpath, '//fa:Fa/fa:Podmiot2K/fa:Adres/fa:AdresL1');
        $adresL2 = $this->getValue($xpath, '//fa:Fa/fa:Podmiot2K/fa:Adres/fa:AdresL2');
        if ($adresL2) {
            $buyer['address'] .= "\n" . $adresL2;
        }

        return $buyer;
    }

    /**
     * @brief Parse Podmiot3 entries
     * @param $xpath
     * @return array
     */
    private function parsePodmiot3($xpath)
    {
        $result = array();
        $nodes = $xpath->query('//fa:Podmiot3');
        for ($i = 0; $i < $nodes->length; $i++) {
            $base = '//fa:Podmiot3[' . ($i + 1) . ']';
            $entry = array_merge($this->parseIdentity($xpath, $base . '/fa:DaneIdentyfikacyjne'), array(
                'name'        => $this->getValue($xpath, $base . '/fa:DaneIdentyfikacyjne/fa:Nazwa'),
                'country'     => $this->getValue($xpath, $base . '/fa:Adres/fa:KodKraju', 'PL'),
                'address'     => $this->getValue($xpath, $base . '/fa:Adres/fa:AdresL1'),
                'role'        => $this->getValue($xpath, $base . '/fa:Rola'),
                'rola_inna'   => $this->getValue($xpath, $base . '/fa:RolaInna') === '1',
                'opis_roli'   => $this->getValue($xpath, $base . '/fa:OpisRoli', null),
                'udzial'      => $this->getValue($xpath, $base . '/fa:Udzial', null),
                'nr_klienta'  => $this->getValue($xpath, $base . '/fa:NrKlienta', null),
                'id_nabywcy'  => $this->getValue($xpath, $base . '/fa:IDNabywcy', null),
                'email'       => $this->getValue($xpath, $base . '/fa:DaneKontaktowe/fa:Email', null),
                'phone'       => $this->getValue($xpath, $base . '/fa:DaneKontaktowe/fa:Telefon', null),
            ));
            $adresL2 = $this->getValue($xpath, $base . '/fa:Adres/fa:AdresL2');
            if ($adresL2) {
                $entry['address'] .= "\n" . $adresL2;
            }
            if ($entry['nip'] === '') {
                if (!empty($entry['nr_id'])) {
                    $entry['nip'] = $entry['nr_id'];
                } elseif (!empty($entry['idwew'])) {
                    $entry['nip'] = $entry['idwew'];
                }
            }
            $result[] = $entry;
        }
        return $result;
    }

    /**
     * @brief Parse DaneIdentyfikacyjne
     * @param $xpath
     * @param $base Node XPath
     * @return array
     */
    private function parseIdentity($xpath, $base)
    {
        return array(
            'nip'          => $this->getValue($xpath, $base . '/fa:NIP'),
            'idwew'        => $this->getValue($xpath, $base . '/fa:IDWew', null),
            'kod_ue'       => $this->getValue($xpath, $base . '/fa:KodUE', null),
            'nr_vat_ue'    => $this->getValue($xpath, $base . '/fa:NrVatUE', null),
            'kod_kraju_id' => $this->getValue($xpath, $base . '/fa:KodKraju', null),
            'nr_id'        => $this->getValue($xpath, $base . '/fa:NrID', null),
            'brak_id'      => $this->getValue($xpath, $base . '/fa:BrakID') === '1',
        );
    }

    /**
     * @brief Parse Fa section
     * @param $xpath
     * @return array
     */
    private function parseFa($xpath)
    {
        $invoice = array(
            'currency' => 'PLN',
            'number' => '',
            'type' => 'VAT',
            'date' => null,
            'sale_date' => null,
            'total_net' => 0.0,
            'total_vat' => 0.0,
            'total_gross' => 0.0,
            'place_of_issue' => null,
            'fp_flag' => false,
            'total_amount' => null,
        );

        $invoice['currency'] = $this->getValue($xpath, '//fa:Fa/fa:KodWaluty', 'PLN');
        $invoice['number'] = $this->getValue($xpath, '//fa:Fa/fa:P_2');
        $invoice['type'] = $this->getValue($xpath, '//fa:Fa/fa:RodzajFaktury', 'VAT');

        $invoice['date'] = $this->getValue($xpath, '//fa:Fa/fa:P_1', null);
        $invoice['sale_date'] = $this->getValue($xpath, '//fa:Fa/fa:P_6', null);
        $invoice['period_from'] = $this->getValue($xpath, '//fa:Fa/fa:OkresFa/fa:P_6_Od', null);
        $invoice['period_to'] = $this->getValue($xpath, '//fa:Fa/fa:OkresFa/fa:P_6_Do', null);
        $invoice['wz'] = array();
        foreach ($xpath->query('//fa:Fa/fa:WZ') as $wzNode) {
            $v = trim($wzNode->textContent);
            if ($v !== '') $invoice['wz'][] = $v;
        }

        $p1m = $this->getValue($xpath, '//fa:Fa/fa:P_1M', null);
        if ($p1m) $invoice['place_of_issue'] = $p1m;

        $fp = $this->getValue($xpath, '//fa:Fa/fa:FP');
        $invoice['fp_flag'] = ($fp === '1');

        $invoice['p_16_flag'] = ($this->getValue($xpath, '//fa:Fa/fa:Adnotacje/fa:P_16') === '1'); // cash accounting
        $invoice['p_17_flag'] = ($this->getValue($xpath, '//fa:Fa/fa:Adnotacje/fa:P_17') === '1'); // self-billing
        $invoice['p_18_flag'] = ($this->getValue($xpath, '//fa:Fa/fa:Adnotacje/fa:P_18') === '1'); // reverse charge
        $invoice['mpp_flag']  = ($this->getValue($xpath, '//fa:Fa/fa:Adnotacje/fa:P_18A') === '1'); // split payment (MPP)
        $invoice['p_23_flag'] = ($this->getValue($xpath, '//fa:Fa/fa:Adnotacje/fa:P_23') === '1');

        $invoice['tp_flag'] = ($this->getValue($xpath, '//fa:Fa/fa:TP') === '1');

        $invoice['p_19_flag'] = ($this->getValue($xpath, '//fa:Fa/fa:Adnotacje/fa:Zwolnienie/fa:P_19') === '1');
        $invoice['p_19a'] = $this->getValue($xpath, '//fa:Fa/fa:Adnotacje/fa:Zwolnienie/fa:P_19A', null);
        $invoice['p_19b'] = $this->getValue($xpath, '//fa:Fa/fa:Adnotacje/fa:Zwolnienie/fa:P_19B', null);
        $invoice['p_19c'] = $this->getValue($xpath, '//fa:Fa/fa:Adnotacje/fa:Zwolnienie/fa:P_19C', null);

        $invoice['pmarzy_flag'] = ($this->getValue($xpath, '//fa:Fa/fa:Adnotacje/fa:PMarzy/fa:P_PMarzy') === '1');
        $invoice['pmarzy_subtype'] = null;
        if ($invoice['pmarzy_flag']) {
            $pm = '//fa:Fa/fa:Adnotacje/fa:PMarzy/fa:';
            if ($this->getValue($xpath, $pm . 'P_PMarzy_3_1') === '1') {
                $invoice['pmarzy_subtype'] = '3_1';
            } elseif ($this->getValue($xpath, $pm . 'P_PMarzy_3_2') === '1') {
                $invoice['pmarzy_subtype'] = '3_2';
            } elseif ($this->getValue($xpath, $pm . 'P_PMarzy_2') === '1') {
                $invoice['pmarzy_subtype'] = '2';
            } elseif ($this->getValue($xpath, $pm . 'P_PMarzy_3_3') === '1') {
                $invoice['pmarzy_subtype'] = '3_3';
            }
        }

        $totalNet = 0.0;
        $totalVat = 0.0;

        $totalNet += $this->getDecimal($xpath, '//fa:Fa/fa:P_13_1');   // 23%
        $totalNet += $this->getDecimal($xpath, '//fa:Fa/fa:P_13_2');   // 8%
        $totalNet += $this->getDecimal($xpath, '//fa:Fa/fa:P_13_3');   // 5%
        $totalNet += $this->getDecimal($xpath, '//fa:Fa/fa:P_13_4');   // 4%/3% (taxi)
        $totalNet += $this->getDecimal($xpath, '//fa:Fa/fa:P_13_5');   // OSS
        $totalNet += $this->getDecimal($xpath, '//fa:Fa/fa:P_13_6_1'); // 0% KR
        $totalNet += $this->getDecimal($xpath, '//fa:Fa/fa:P_13_6_2'); // 0% WDT
        $totalNet += $this->getDecimal($xpath, '//fa:Fa/fa:P_13_6_3'); // 0% export
        $totalNet += $this->getDecimal($xpath, '//fa:Fa/fa:P_13_7');   // zwolnione (zw)
        $totalNet += $this->getDecimal($xpath, '//fa:Fa/fa:P_13_8');   // nie podlega I (np I)
        $totalNet += $this->getDecimal($xpath, '//fa:Fa/fa:P_13_9');   // nie podlega II (np II)
        $totalNet += $this->getDecimal($xpath, '//fa:Fa/fa:P_13_10');  // odwrotne obciążenie (oo)
        $totalNet += $this->getDecimal($xpath, '//fa:Fa/fa:P_13_11');  // procedura marży

        $totalVat += $this->getDecimal($xpath, '//fa:Fa/fa:P_14_1');   // 23%
        $totalVat += $this->getDecimal($xpath, '//fa:Fa/fa:P_14_2');   // 8%
        $totalVat += $this->getDecimal($xpath, '//fa:Fa/fa:P_14_3');   // 5%
        $totalVat += $this->getDecimal($xpath, '//fa:Fa/fa:P_14_4');   // 4% / 3%
        $totalVat += $this->getDecimal($xpath, '//fa:Fa/fa:P_14_5');   // OSS

        $invoice['total_net'] = $totalNet;
        $invoice['total_vat'] = $totalVat;

        $p15raw = $this->getValue($xpath, '//fa:Fa/fa:P_15', null);
        if ($p15raw !== null && $p15raw !== '') $invoice['total_amount'] = $p15raw;
        $p15 = $this->getDecimal($xpath, '//fa:Fa/fa:P_15');
        $invoice['total_gross'] = ($p15 !== null && $p15 !== false) ? $p15 : ($totalNet + $totalVat);
        $p15zk = $this->getValue($xpath, '//fa:Fa/fa:P_15ZK', '');
        $invoice['p15zk'] = is_numeric($p15zk) ? (float) $p15zk : null;
        $kzk = $this->getValue($xpath, '//fa:Fa/fa:KursWalutyZK', '');
        $invoice['kurs_waluty_zk'] = is_numeric($kzk) ? (float) $kzk : null;

        return $invoice;
    }

    /**
     * @brief Parse VAT summary
     * @param $xpath
     * @return array By rate
     */
    private function parseVatSummary($xpath)
    {
        $summary = array();

        // only P_14_xW
        $net23 = $this->getDecimal($xpath, '//fa:Fa/fa:P_13_1');
        $vat23 = $this->getDecimal($xpath, '//fa:Fa/fa:P_14_1');
        if ($net23 != 0 || $vat23 != 0 || $this->getDecimal($xpath, '//fa:Fa/fa:P_14_1W') != 0) {
            $summary['23'] = array('net' => $net23, 'vat' => $vat23, 'vat_pln' => null);
            $vatPln23 = $this->getValue($xpath, '//fa:Fa/fa:P_14_1W', null);
            if ($vatPln23 !== null && $vatPln23 !== '') {
                $summary['23']['vat_pln'] = (float)$vatPln23;
            }
        }

        $net8 = $this->getDecimal($xpath, '//fa:Fa/fa:P_13_2');
        $vat8 = $this->getDecimal($xpath, '//fa:Fa/fa:P_14_2');
        if ($net8 != 0 || $vat8 != 0 || $this->getDecimal($xpath, '//fa:Fa/fa:P_14_2W') != 0) {
            $summary['8'] = array('net' => $net8, 'vat' => $vat8, 'vat_pln' => null);
            $vatPln8 = $this->getValue($xpath, '//fa:Fa/fa:P_14_2W', null);
            if ($vatPln8 !== null && $vatPln8 !== '') {
                $summary['8']['vat_pln'] = (float)$vatPln8;
            }
        }

        $net5 = $this->getDecimal($xpath, '//fa:Fa/fa:P_13_3');
        $vat5 = $this->getDecimal($xpath, '//fa:Fa/fa:P_14_3');
        if ($net5 != 0 || $vat5 != 0 || $this->getDecimal($xpath, '//fa:Fa/fa:P_14_3W') != 0) {
            $summary['5'] = array('net' => $net5, 'vat' => $vat5, 'vat_pln' => null);
            $vatPln5 = $this->getValue($xpath, '//fa:Fa/fa:P_14_3W', null);
            if ($vatPln5 !== null && $vatPln5 !== '') {
                $summary['5']['vat_pln'] = (float)$vatPln5;
            }
        }

        // P_13_4: 4%/3%
        $net4 = $this->getDecimal($xpath, '//fa:Fa/fa:P_13_4');
        $vat4 = $this->getDecimal($xpath, '//fa:Fa/fa:P_14_4');
        if ($net4 != 0 || $vat4 != 0 || $this->getDecimal($xpath, '//fa:Fa/fa:P_14_4W') != 0) {
            $key4 = ($xpath->query("//fa:Fa/fa:FaWiersz[fa:P_12='3']")->length > 0 && $xpath->query("//fa:Fa/fa:FaWiersz[fa:P_12='4']")->length === 0) ? '3' : '4';
            $summary[$key4] = array('net' => $net4, 'vat' => $vat4, 'vat_pln' => null);
            $vatPln4 = $this->getValue($xpath, '//fa:Fa/fa:P_14_4W', null);
            if ($vatPln4 !== null && $vatPln4 !== '') {
                $summary[$key4]['vat_pln'] = (float)$vatPln4;
            }
        }

        $netOss = $this->getDecimal($xpath, '//fa:Fa/fa:P_13_5');
        $vatOss = $this->getDecimal($xpath, '//fa:Fa/fa:P_14_5');
        if ($netOss != 0 || $vatOss != 0) {
            $summary['oss'] = array('net' => $netOss, 'vat' => $vatOss);
        }

        $net0KR = $this->getDecimal($xpath, '//fa:Fa/fa:P_13_6_1');
        if ($net0KR != 0) {
            $summary['0 KR'] = array('net' => $net0KR, 'vat' => 0.0);
        }

        $net0WDT = $this->getDecimal($xpath, '//fa:Fa/fa:P_13_6_2');
        if ($net0WDT != 0) {
            $summary['0 WDT'] = array('net' => $net0WDT, 'vat' => 0.0);
        }

        $net0EX = $this->getDecimal($xpath, '//fa:Fa/fa:P_13_6_3');
        if ($net0EX != 0) {
            $summary['0 EX'] = array('net' => $net0EX, 'vat' => 0.0);
        }

        $netZw = $this->getDecimal($xpath, '//fa:Fa/fa:P_13_7');
        if ($netZw != 0) {
            $summary['zw'] = array('net' => $netZw, 'vat' => 0.0);
        }

        $netNpI = $this->getDecimal($xpath, '//fa:Fa/fa:P_13_8');
        if ($netNpI != 0) {
            $summary['np I'] = array('net' => $netNpI, 'vat' => 0.0);
        }

        $netNpII = $this->getDecimal($xpath, '//fa:Fa/fa:P_13_9');
        if ($netNpII != 0) {
            $summary['np II'] = array('net' => $netNpII, 'vat' => 0.0);
        }

        $netOO = $this->getDecimal($xpath, '//fa:Fa/fa:P_13_10');
        if ($netOO != 0) {
            $summary['oo'] = array('net' => $netOO, 'vat' => 0.0);
        }

        // art. 119/120
        $netMarza = $this->getDecimal($xpath, '//fa:Fa/fa:P_13_11');
        if ($netMarza != 0) {
            $summary['marza'] = array('net' => $netMarza, 'vat' => 0.0);
        }

        return $summary;
    }

    /**
     * @brief Parse FaWiersz lines
     * @param $xpath
     * @return array
     */
    private function parseFaWiersz($xpath)
    {
        $lines = array();
        $linesBefore = array();

        $faWierszNodes = $xpath->query('//fa:Fa/fa:FaWiersz');
        $hasStanPrzed = false;

        foreach ($faWierszNodes as $node) {
            foreach ($node->childNodes as $child) {
                if ($child->nodeType === XML_ELEMENT_NODE && $child->localName === 'StanPrzed') {
                    $hasStanPrzed = true;
                    break 2;
                }
            }
        }

        foreach ($faWierszNodes as $node) {
            $line = array(
                'line_num' => 0,
                'uuid' => null,
                'p_6a' => null,
                'indeks' => null,
                'gtin' => null,
                'cn' => null,
                'description' => '',
                'quantity' => 0.0,
                'unit' => null,
                'unit_price_net' => null,
                'unit_price_gross' => null,
                'discount' => null,
                'net_amount' => null,
                'gross_amount' => null,
                'vat_rate' => null,
                // imported at 0%
                'oss' => false,
                'vat_rate_oss' => null,
                'kurs_waluty' => null,
                'vat_amount' => null,
                'declared' => array(),
            );

            $isBefore = false;

            foreach ($node->childNodes as $child) {
                if ($child->nodeType !== XML_ELEMENT_NODE) continue;

                switch ($child->localName) {
                    case 'NrWierszaFa':
                        $line['line_num'] = (int)$child->textContent;
                        break;
                    case 'UU_ID':
                        $line['uuid'] = trim($child->textContent);
                        break;
                    case 'Indeks':
                        $line['indeks'] = trim($child->textContent);
                        break;
                    case 'GTIN':
                        $line['gtin'] = trim($child->textContent);
                        break;
                    case 'P_6A':
                        $line['p_6a'] = trim($child->textContent);
                        break;
                    case 'P_7':
                        $line['description'] = $child->textContent;
                        break;
                    case 'P_8B':
                        $line['quantity'] = (float)$child->textContent;
                        $line['declared'][] = 'P_8B';
                        break;
                    case 'P_8A':
                        $line['unit'] = trim($child->textContent);
                        break;
                    case 'P_9A':
                        $line['unit_price_net'] = (float)$child->textContent;
                        $line['declared'][] = 'P_9A';
                        break;
                    case 'P_9B':
                        $line['unit_price_gross'] = (float)$child->textContent;
                        $line['declared'][] = 'P_9B';
                        break;
                    case 'P_10':
                        $line['discount'] = trim($child->textContent);
                        break;
                    case 'P_11':
                        $line['net_amount'] = (float)$child->textContent;
                        $line['declared'][] = 'P_11';
                        break;
                    case 'P_11A':
                        $line['gross_amount'] = (float)$child->textContent;
                        $line['declared'][] = 'P_11A';
                        break;
                    case 'P_11Vat':
                        $line['vat_amount'] = (float)$child->textContent;
                        break;
                    case 'P_12':
                        $line['vat_rate'] = trim($child->textContent);
                        break;
                    case 'P_12_XII':
                        $line['oss'] = true;
                        $line['vat_rate_oss'] = trim($child->textContent);
                        break;
                    case 'CN':
                        $line['cn'] = trim($child->textContent);
                        break;
                    case 'KursWaluty':
                        $line['kurs_waluty'] = trim($child->textContent);
                        break;
                    case 'StanPrzed':
                        if ($child->textContent === '1') {
                            $isBefore = true;
                        }
                        break;
                }
            }

            $line['unit_price_net_derived'] = $this->isNullOrBlank($line['unit_price_net']);
            $line['unit_price_gross_derived'] = $this->isNullOrBlank($line['unit_price_gross']);

            if ($this->isNullOrBlank($line['unit_price_net']) && !$this->isNullOrBlank($line['net_amount']) && !$this->isNullOrBlank($line['quantity']) && $line['quantity'] != 0) {
                $line['unit_price_net'] = $line['net_amount'] / $line['quantity'];
            }
            if ($this->isNullOrBlank($line['unit_price_gross']) && !$this->isNullOrBlank($line['gross_amount']) && !$this->isNullOrBlank($line['quantity']) && $line['quantity'] != 0) {
                $line['unit_price_gross'] = $line['gross_amount'] / $line['quantity'];
            }

            $vatRate = is_numeric($line['vat_rate']) ? (float)$line['vat_rate'] : 0;
            $vatMultiplier = 1 + $vatRate / 100;
            if ($this->isNullOrBlank($line['net_amount']) && !$this->isNullOrBlank($line['gross_amount']) && $vatMultiplier > 0) {
                $line['net_amount'] = round($line['gross_amount'] / $vatMultiplier, 2);
            }
            if ($this->isNullOrBlank($line['unit_price_net']) && !$this->isNullOrBlank($line['unit_price_gross']) && $vatMultiplier > 0) {
                $line['unit_price_net'] = round($line['unit_price_gross'] / $vatMultiplier, 4);
            }
            if ($this->isNullOrBlank($line['gross_amount']) && !$this->isNullOrBlank($line['net_amount'])) {
                $line['gross_amount'] = round($line['net_amount'] * $vatMultiplier, 2);
            }
            if ($this->isNullOrBlank($line['unit_price_gross']) && !$this->isNullOrBlank($line['unit_price_net'])) {
                $line['unit_price_gross'] = round($line['unit_price_net'] * $vatMultiplier, 4);
            }

            if ($hasStanPrzed && $isBefore) {
                $linesBefore[] = $line;
            } else {
                $lines[] = $line;
            }
        }

        return array('lines' => $lines, 'lines_before' => $linesBefore);
    }

    /**
     * @brief Parse FakturaZaliczkowa
     * @param $xpath
     * @return array
     */
    private function parseFakturaZaliczkowa($xpath)
    {
        $out = array();
        foreach ($xpath->query('//fa:Fa/fa:FakturaZaliczkowa') as $node) {
            $ksef = $xpath->evaluate('string(fa:NrKSeFFaZaliczkowej)', $node);
            $out[] = array(
                'ksef_number' => (string) $ksef,
                'outside' => $xpath->evaluate('string(fa:NrKSeFZN)', $node) === '1',
                'number' => (string) $xpath->evaluate('string(fa:NrFaZaliczkowej)', $node),
            );
        }
        return $out;
    }

    /**
     * @brief Parse ZaliczkaCzesciowa
     * @param DOMXPath $xpath
     * @return array
     */
    private function parseZaliczkaCzesciowa($xpath)
    {
        $out = array();
        foreach ($xpath->query('//fa:Fa/fa:ZaliczkaCzesciowa') as $node) {
            $rate = trim((string) $xpath->evaluate('string(fa:KursWalutyZW)', $node));
            $out[] = array(
                'date' => trim((string) $xpath->evaluate('string(fa:P_6Z)', $node)),
                'amount' => trim((string) $xpath->evaluate('string(fa:P_15Z)', $node)),
                'rate' => $rate !== '' ? $rate : null,
            );
        }
        return $out;
    }

    /**
     * @brief Parse ZamowienieWiersz lines
     * @param $xpath
     * @return array
     */
    private function parseZamowienieWiersz($xpath)
    {
        $lines = array();
        $linesBefore = array();

        $zamWierszNodes = $xpath->query('//fa:Fa/fa:Zamowienie/fa:ZamowienieWiersz');
        $hasStanPrzed = false;

        foreach ($zamWierszNodes as $node) {
            foreach ($node->childNodes as $child) {
                if ($child->nodeType === XML_ELEMENT_NODE && $child->localName === 'StanPrzedZ') {
                    $hasStanPrzed = true;
                    break 2;
                }
            }
        }

        foreach ($zamWierszNodes as $node) {
            $line = array(
                'line_num' => 0,
                'uuid' => null,
                'indeks' => null,
                'gtin' => null,
                'cn' => null,
                'description' => '',
                'quantity' => 0.0,
                'unit' => null,
                'unit_price_net' => null,
                'unit_price_gross' => null,
                'discount' => null,
                'net_amount' => null,
                'gross_amount' => null,
                'vat_rate' => null,
                'oss' => false,
                'vat_rate_oss' => null,
                'kurs_waluty' => null,
            );

            $isBefore = false;
            $vatAmount = null;

            foreach ($node->childNodes as $child) {
                if ($child->nodeType !== XML_ELEMENT_NODE) continue;

                switch ($child->localName) {
                    case 'NrWierszaZam':
                        $line['line_num'] = (int)$child->textContent;
                        break;
                    case 'UU_IDZ':
                        $line['uuid'] = trim($child->textContent);
                        break;
                    case 'IndeksZ':
                        $line['indeks'] = trim($child->textContent);
                        break;
                    case 'GTINZ':
                        $line['gtin'] = trim($child->textContent);
                        break;
                    case 'P_7Z':
                        $line['description'] = $child->textContent;
                        break;
                    case 'P_8BZ':
                        $line['quantity'] = (float)$child->textContent;
                        break;
                    case 'P_8AZ':
                        $line['unit'] = trim($child->textContent);
                        break;
                    case 'P_9AZ':
                        $line['unit_price_net'] = (float)$child->textContent;
                        break;
                    case 'P_11NettoZ':
                        $line['net_amount'] = (float)$child->textContent;
                        break;
                    case 'P_11VatZ':
                        $vatAmount = (float)$child->textContent;
                        break;
                    case 'P_12Z':
                        $line['vat_rate'] = trim($child->textContent);
                        break;
                    case 'P_12Z_XII':
                        $line['oss'] = true;
                        $line['vat_rate_oss'] = trim($child->textContent);
                        break;
                    case 'CNZ':
                        $line['cn'] = trim($child->textContent);
                        break;
                    case 'StanPrzedZ':
                        if ($child->textContent === '1') {
                            $isBefore = true;
                        }
                        break;
                }
            }

            if (!$this->isNullOrBlank($line['net_amount']) && !$this->isNullOrBlank($vatAmount)) {
                $line['gross_amount'] = round($line['net_amount'] + $vatAmount, 2);
            }

            $line['unit_price_net_derived'] = $this->isNullOrBlank($line['unit_price_net']);
            $line['unit_price_gross_derived'] = $this->isNullOrBlank($line['unit_price_gross']);

            if ($this->isNullOrBlank($line['unit_price_net']) && !$this->isNullOrBlank($line['net_amount']) && !$this->isNullOrBlank($line['quantity']) && $line['quantity'] != 0) {
                $line['unit_price_net'] = round($line['net_amount'] / $line['quantity'], 4);
            }
            if ($this->isNullOrBlank($line['unit_price_gross']) && !$this->isNullOrBlank($line['gross_amount']) && !$this->isNullOrBlank($line['quantity']) && $line['quantity'] != 0) {
                $line['unit_price_gross'] = round($line['gross_amount'] / $line['quantity'], 4);
            }

            $vatRate = is_numeric($line['vat_rate']) ? (float)$line['vat_rate'] : 0;
            $vatMultiplier = 1 + $vatRate / 100;
            if ($this->isNullOrBlank($line['net_amount']) && !$this->isNullOrBlank($line['gross_amount']) && $vatMultiplier > 0) {
                $line['net_amount'] = round($line['gross_amount'] / $vatMultiplier, 2);
            }
            if ($this->isNullOrBlank($line['unit_price_net']) && !$this->isNullOrBlank($line['unit_price_gross']) && $vatMultiplier > 0) {
                $line['unit_price_net'] = round($line['unit_price_gross'] / $vatMultiplier, 4);
            }
            if ($this->isNullOrBlank($line['gross_amount']) && !$this->isNullOrBlank($line['net_amount'])) {
                $line['gross_amount'] = round($line['net_amount'] * $vatMultiplier, 2);
            }
            if ($this->isNullOrBlank($line['unit_price_gross']) && !$this->isNullOrBlank($line['unit_price_net'])) {
                $line['unit_price_gross'] = round($line['unit_price_net'] * $vatMultiplier, 4);
            }

            if ($hasStanPrzed && $isBefore) {
                $linesBefore[] = $line;
            } else {
                $lines[] = $line;
            }
        }

        return array('lines' => $lines, 'lines_before' => $linesBefore);
    }

    /**
     * @brief Parse Platnosc
     * @param $xpath
     * @return array
     */
    private function parsePlatnosc($xpath)
    {
        $payment = array(
            'due_date' => null,
            'due_date_earliest' => null,
            'due_date_description' => null,
            'due_dates' => array(),
            'method' => null,
            'method_other' => false,
            'method_description' => null,
            'bank_account' => null,
            'status' => self::PAYMENT_UNPAID,
            'payment_date' => null,
            'bank_swift' => null,
            'bank_name' => null,
            'bank_own_account' => null,
            'bank_description' => null,
            'bank_accounts' => array(),
            'factor_bank_accounts' => array(),
            'discount' => null,
            'payment_link' => null,
            'ipksef' => null,
        );
        $base = '//fa:Fa/fa:Platnosc';

        foreach ($xpath->query($base . '/fa:TerminPlatnosci') as $tn) {
            $date = $this->getValueRel($xpath, 'fa:Termin', $tn);
            $desc = null;
            $opis = $xpath->query('fa:TerminOpis', $tn);
            if ($opis->length > 0) {
                $parts = array();
                foreach (array('Ilosc', 'Jednostka', 'ZdarzeniePoczatkowe') as $el) {
                    $v = $this->getValueRel($xpath, 'fa:' . $el, $opis->item(0));
                    if ($v !== null) $parts[] = $v;
                }
                if (!empty($parts)) $desc = implode(' ', $parts);
            }
            if ($date === null && $desc === null) continue;
            $payment['due_dates'][] = array('date' => $date, 'description' => $desc);
            if ($date !== null) {
                if ($payment['due_date'] === null) $payment['due_date'] = $date;
                if ($payment['due_date_earliest'] === null || strcmp($date, $payment['due_date_earliest']) < 0) {
                    $payment['due_date_earliest'] = $date;
                }
            }
            if ($desc !== null && $payment['due_date_description'] === null) {
                $payment['due_date_description'] = $desc;
            }
        }

        $method = $this->getValue($xpath, $base . '/fa:FormaPlatnosci', null);
        if ($method) $payment['method'] = $method;
        if ($this->getValue($xpath, $base . '/fa:PlatnoscInna', null) === '1') $payment['method_other'] = true;
        $opisPlatnosci = $this->getValue($xpath, $base . '/fa:OpisPlatnosci', null);
        if ($opisPlatnosci !== null && trim($opisPlatnosci) !== '') $payment['method_description'] = trim($opisPlatnosci);

        $zaplacono = $this->getValue($xpath, '//fa:Fa/fa:Platnosc/fa:Zaplacono');
        $czesciowa = $this->getValue($xpath, '//fa:Fa/fa:Platnosc/fa:ZnacznikZaplatyCzesciowej');
        if ($zaplacono === '1') {
            $payment['status'] = self::PAYMENT_PAID;
        } elseif ($czesciowa === '2') {
            $payment['status'] = self::PAYMENT_PAID_INSTALLMENTS;
        } elseif ($czesciowa === '1') {
            $payment['status'] = self::PAYMENT_PARTIAL;
        }

        $dataZaplaty = $this->getValue($xpath, '//fa:Fa/fa:Platnosc/fa:DataZaplaty', null);
        if ($dataZaplaty) $payment['payment_date'] = $dataZaplaty;

        $partialNodes = $xpath->query('//fa:Fa/fa:Platnosc/fa:ZaplataCzesciowa');
        if ($partialNodes && $partialNodes->length > 0) {
            $payment['partial_payments'] = array();
            foreach ($partialNodes as $node) {
                $partial = array();
                $kwota = $xpath->query('fa:KwotaZaplatyCzesciowej', $node);
                if ($kwota->length > 0) $partial['amount'] = $kwota->item(0)->textContent;
                $data = $xpath->query('fa:DataZaplatyCzesciowej', $node);
                if ($data->length > 0) $partial['date'] = $data->item(0)->textContent;
                $forma = $xpath->query('fa:FormaPlatnosci', $node);
                if ($forma->length > 0) {
                    $partial['method'] = $forma->item(0)->textContent;
                } else {
                    $opis = $xpath->query('fa:OpisPlatnosci', $node);
                    if ($opis->length > 0) $partial['method_description'] = $opis->item(0)->textContent;
                }
                $payment['partial_payments'][] = $partial;
            }
        }

        // fields never mix
        foreach ($xpath->query($base . '/fa:RachunekBankowy') as $bn) {
            $acc = $this->parseBankAccountNode($xpath, $bn);
            if (!empty($acc['bank_account'])) $payment['bank_accounts'][] = $acc;
        }
        if (!empty($payment['bank_accounts'])) {
            foreach ($payment['bank_accounts'][0] as $k => $v) {
                $payment[$k] = $v;
            }
        }

        foreach ($xpath->query($base . '/fa:RachunekBankowyFaktora') as $fn) {
            $acc = $this->parseBankAccountNode($xpath, $fn);
            if (!empty($acc['bank_account'])) $payment['factor_bank_accounts'][] = $acc;
        }

        $skonto = $xpath->query($base . '/fa:Skonto');
        if ($skonto->length > 0) {
            $payment['discount'] = array(
                'conditions' => $this->getValueRel($xpath, 'fa:WarunkiSkonta', $skonto->item(0)),
                'amount' => $this->getValueRel($xpath, 'fa:WysokoscSkonta', $skonto->item(0)),
            );
        }
        $link = $this->getValue($xpath, $base . '/fa:LinkDoPlatnosci', null);
        if ($link !== null && trim($link) !== '') $payment['payment_link'] = trim($link);
        $ip = $this->getValue($xpath, $base . '/fa:IPKSeF', null);
        if ($ip !== null && trim($ip) !== '') $payment['ipksef'] = trim($ip);

        return $payment;
    }

    /**
     * @brief Parse bank account
     * @param $xpath
     * @param $node
     * @return array
     */
    private function parseBankAccountNode($xpath, $node)
    {
        return array(
            'bank_account'     => $this->getValueRel($xpath, 'fa:NrRB', $node),
            'bank_swift'       => $this->getValueRel($xpath, 'fa:SWIFT', $node),
            'bank_name'        => $this->getValueRel($xpath, 'fa:NazwaBanku', $node),
            'bank_own_account' => $this->getValueRel($xpath, 'fa:RachunekWlasnyBanku', $node),
            'bank_description' => $this->getValueRel($xpath, 'fa:OpisRachunku', $node),
        );
    }

    /**
     * @brief Parse DaneFaKorygowanej
     * @param $xpath
     * @return array|null Null if not correction
     */
    private function parseDaneFaKorygowanej($xpath)
    {
        $daneFaKor = $xpath->query('//fa:Fa/fa:DaneFaKorygowanej');
        if ($daneFaKor->length == 0) {
            $reason = $this->getValue($xpath, '//fa:Fa/fa:PrzyczynaKorekty', null);
            $type = $this->getValue($xpath, '//fa:Fa/fa:TypKorekty', null);
            if (empty($reason) && empty($type)) {
                return null;
            }
            return array(
                'reason' => $reason,
                'type' => $type,
                'corrected_invoices' => array(),
            );
        }

        $correction = array(
            'reason' => null,
            'type' => null,
            'corrected_invoices' => array(),
        );

        $reason = $this->getValue($xpath, '//fa:Fa/fa:PrzyczynaKorekty', null);
        if ($reason) $correction['reason'] = $reason;
        $type = $this->getValue($xpath, '//fa:Fa/fa:TypKorekty', null);
        if ($type) $correction['type'] = $type;

        foreach ($daneFaKor as $node) {
            $inv = array(
                'invoice_date' => null,
                'invoice_number' => null,
                'ksef_number' => null,
            );

            foreach ($node->childNodes as $child) {
                if ($child->nodeType !== XML_ELEMENT_NODE) continue;

                switch ($child->localName) {
                    case 'DataWystFaKorygowanej':
                        $inv['invoice_date'] = trim($child->textContent);
                        break;
                    case 'NrFaKorygowanej':
                        $inv['invoice_number'] = trim($child->textContent);
                        break;
                    case 'NrKSeFFaKorygowanej':
                        $inv['ksef_number'] = trim($child->textContent);
                        break;
                }
            }

            $correction['corrected_invoices'][] = $inv;
        }

        return $correction;
    }

    /**
     * @brief Parse Rejestry
     * @param $xpath
     * @return array
     */
    private function parseRegistries($xpath)
    {
        $rows = array();
        foreach ($xpath->query('//fa:Stopka/fa:Rejestry') as $node) {
            $rows[] = array(
                'pelna_nazwa' => $this->getValueRel($xpath, 'fa:PelnaNazwa', $node),
                'krs' => $this->getValueRel($xpath, 'fa:KRS', $node),
                'regon' => $this->getValueRel($xpath, 'fa:REGON', $node),
                'bdo' => $this->getValueRel($xpath, 'fa:BDO', $node),
            );
        }
        return array(
            'krs' => $this->getValue($xpath, '//fa:Stopka/fa:Rejestry/fa:KRS', null),
            'regon' => $this->getValue($xpath, '//fa:Stopka/fa:Rejestry/fa:REGON', null),
            'bdo' => $this->getValue($xpath, '//fa:Stopka/fa:Rejestry/fa:BDO', null),
            'pelna_nazwa' => $this->getValue($xpath, '//fa:Stopka/fa:Rejestry/fa:PelnaNazwa', null),
            'rows' => $rows,
        );
    }

    /**
     * @brief Parse Rozliczenie
     * @param $xpath
     * @return array|null
     */
    private function parseRozliczenie($xpath)
    {
        $base = '//fa:Fa/fa:Rozliczenie';
        if ($xpath->query($base)->length === 0) {
            return null;
        }
        $out = array(
            'charges' => array(),
            'deductions' => array(),
            'total_charges' => $this->getValue($xpath, $base . '/fa:SumaObciazen', null),
            'total_deductions' => $this->getValue($xpath, $base . '/fa:SumaOdliczen', null),
            'to_pay' => $this->getValue($xpath, $base . '/fa:DoZaplaty', null),
            'to_settle' => $this->getValue($xpath, $base . '/fa:DoRozliczenia', null),
        );
        foreach (array('Obciazenia' => 'charges', 'Odliczenia' => 'deductions') as $el => $key) {
            foreach ($xpath->query($base . '/fa:' . $el) as $node) {
                $out[$key][] = array(
                    'amount' => $this->getValueRel($xpath, 'fa:Kwota', $node),
                    'reason' => $this->getValueRel($xpath, 'fa:Powod', $node),
                );
            }
        }
        return $out;
    }

    /**
     * @brief Parse Stopka
     * @param $xpath
     * @return array
     */
    private function parseStopka($xpath)
    {
        $items = array();
        $nodes = $xpath->query('//fa:Stopka/fa:Informacje/fa:StopkaFaktury');
        foreach ($nodes as $node) {
            $text = trim($node->textContent);
            if ($text !== '') {
                $items[] = $text;
            }
        }
        return $items;
    }

    /**
     * @brief Build additional info
     * @param $invoice Parsed invoice
     * @return array
     */
    private function parseAdditionalInfo($invoice)
    {
        return array();
    }

    /**
     * @brief Parse DodatkowyOpis
     * @param $xpath
     * @return array
     */
    private function parseAdditionalDesc($xpath)
    {
        $items = array();
        $nodes = $xpath->query('//fa:Fa/fa:DodatkowyOpis');
        foreach ($nodes as $node) {
            $key = null;
            $value = null;
            $nrWiersza = null;
            foreach ($node->childNodes as $child) {
                if ($child->nodeType !== XML_ELEMENT_NODE) continue;
                if ($child->localName === 'NrWiersza') $nrWiersza = trim($child->textContent);
                if ($child->localName === 'Klucz') $key = trim($child->textContent);
                if ($child->localName === 'Wartosc') $value = trim($child->textContent);
            }
            if ($key && $value) {
                $item = array('key' => $key, 'value' => $value);
                if ($nrWiersza !== null && $nrWiersza !== '') {
                    $item['nr_wiersza'] = (int) $nrWiersza;
                }
                $items[] = $item;
            }
        }
        return $items;
    }

    /**
     * @brief Parse WarunkiTransakcji
     * @param $xpath
     * @return array
     */
    private function parseWarunkiTransakcji($xpath)
    {
        $result = array(
            'umowy' => array(),
            'zamowienia' => array(),
            'partie' => array(),
            'waluta_umowna' => null,
            'kurs_umowny' => null,
            'warunki_dostawy' => null,
            'podmiot_posredniczacy' => false,
            'transport' => array(),
        );

        $base = '//fa:Fa/fa:WarunkiTransakcji';
        if ($xpath->query($base)->length === 0) {
            return $result;
        }

        foreach ($xpath->query($base . '/fa:Umowy') as $node) {
            $nr = $this->getValueRel($xpath, 'fa:NrUmowy', $node);
            $data = $this->getValueRel($xpath, 'fa:DataUmowy', $node);
            if ($nr !== null || $data !== null) {
                $result['umowy'][] = array('nr' => $nr, 'data' => $data);
            }
        }

        foreach ($xpath->query($base . '/fa:Zamowienia') as $node) {
            $nr = $this->getValueRel($xpath, 'fa:NrZamowienia', $node);
            $data = $this->getValueRel($xpath, 'fa:DataZamowienia', $node);
            if ($nr !== null || $data !== null) {
                $result['zamowienia'][] = array('nr' => $nr, 'data' => $data);
            }
        }

        foreach ($xpath->query($base . '/fa:NrPartiiTowaru') as $node) {
            $v = trim($node->textContent);
            if ($v !== '') $result['partie'][] = $v;
        }

        $result['waluta_umowna'] = $this->getValue($xpath, $base . '/fa:WalutaUmowna', null);
        $result['kurs_umowny'] = $this->getValue($xpath, $base . '/fa:KursUmowny', null);
        $result['warunki_dostawy'] = $this->getValue($xpath, $base . '/fa:WarunkiDostawy', null);
        $result['podmiot_posredniczacy'] = ($this->getValue($xpath, $base . '/fa:PodmiotPosredniczacy', '') === '1');

        foreach ($xpath->query($base . '/fa:Transport') as $node) {
            $result['transport'][] = $this->parseTransport($xpath, $node);
        }

        return $result;
    }

    /**
     * @brief Parse Transport
     * @param $xpath
     * @param $node
     * @return array
     */
    private function parseTransport($xpath, $node)
    {
        $t = array(
            'rodzaj' => $this->getValueRel($xpath, 'fa:RodzajTransportu', $node),
            'transport_inny' => $this->getValueRel($xpath, 'fa:TransportInny', $node),
            'opis_innego_transportu' => $this->getValueRel($xpath, 'fa:OpisInnegoTransportu', $node),
            'nr_zlecenia' => $this->getValueRel($xpath, 'fa:NrZleceniaTransportu', $node),
            'opis_ladunku' => $this->getValueRel($xpath, 'fa:OpisLadunku', $node),
            'ladunek_inny' => $this->getValueRel($xpath, 'fa:LadunekInny', $node),
            'opis_innego_ladunku' => $this->getValueRel($xpath, 'fa:OpisInnegoLadunku', $node),
            'jednostka_opakowania' => $this->getValueRel($xpath, 'fa:JednostkaOpakowania', $node),
            'data_rozp' => $this->getValueRel($xpath, 'fa:DataGodzRozpTransportu', $node),
            'data_zak' => $this->getValueRel($xpath, 'fa:DataGodzZakTransportu', $node),
            'przewoznik' => null,
            'wysylka_z' => null,
            'wysylka_do' => null,
            'wysylka_przez' => array(),
        );

        $prz = $xpath->query('fa:Przewoznik', $node);
        if ($prz->length > 0) {
            $pn = $prz->item(0);
            $t['przewoznik'] = array(
                'nip' => $this->getValueRel($xpath, 'fa:DaneIdentyfikacyjne/fa:NIP', $pn),
                'kod_ue' => $this->getValueRel($xpath, 'fa:DaneIdentyfikacyjne/fa:KodUE', $pn),
                'nr_vat_ue' => $this->getValueRel($xpath, 'fa:DaneIdentyfikacyjne/fa:NrVatUE', $pn),
                'kod_kraju' => $this->getValueRel($xpath, 'fa:DaneIdentyfikacyjne/fa:KodKraju', $pn),
                'nr_id' => $this->getValueRel($xpath, 'fa:DaneIdentyfikacyjne/fa:NrID', $pn),
                'brak_id' => $this->getValueRel($xpath, 'fa:DaneIdentyfikacyjne/fa:BrakID', $pn),
                'nazwa' => $this->getValueRel($xpath, 'fa:DaneIdentyfikacyjne/fa:Nazwa', $pn),
                'adres' => $this->parseAdresNode($xpath, $xpath->query('fa:AdresPrzewoznika', $pn)->item(0)),
            );
        }

        $t['wysylka_z'] = $this->parseAdresNode($xpath, $xpath->query('fa:WysylkaZ', $node)->item(0));
        $t['wysylka_do'] = $this->parseAdresNode($xpath, $xpath->query('fa:WysylkaDo', $node)->item(0));
        foreach ($xpath->query('fa:WysylkaPrzez', $node) as $wn) {
            $adr = $this->parseAdresNode($xpath, $wn);
            if ($adr !== null) $t['wysylka_przez'][] = $adr;
        }

        return $t;
    }

    /**
     * @brief Parse address
     * @param $xpath
     * @param $node
     * @return array|null
     */
    private function parseAdresNode($xpath, $node)
    {
        if ($node === null) return null;
        $adr = array(
            'kod_kraju' => $this->getValueRel($xpath, 'fa:KodKraju', $node),
            'l1' => $this->getValueRel($xpath, 'fa:AdresL1', $node),
            'l2' => $this->getValueRel($xpath, 'fa:AdresL2', $node),
            'gln' => $this->getValueRel($xpath, 'fa:GLN', $node),
        );
        if ($adr['l1'] === null && $adr['l2'] === null && $adr['gln'] === null && $adr['kod_kraju'] === null) {
            return null;
        }
        return $adr;
    }

    /**
     * @brief Get relative value
     * @param $xpath
     * @param $query
     * @param $node Context node
     * @param $default
     * @return string|null
     */
    private function getValueRel($xpath, $query, $node, $default = null)
    {
        $nodes = $xpath->query($query, $node);
        if ($nodes->length > 0) {
            $v = trim($nodes->item(0)->textContent);
            return $v !== '' ? $v : $default;
        }
        return $default;
    }

    /**
     * @brief Parse exchange rate
     * @param $xpath
     * @param $lines After-state lines
     * @param $type RodzajFaktury
     * @return array
     */
    private function parseExchangeRate($xpath, $lines, $type = '')
    {
        $data = array(
            'rate' => null,
            'source' => '',
            'line_rates' => array(),
            'deposit_rate' => null,
            'payment_rates' => array(),
            'before_rate' => null,
            'unusable' => array(),
            'mixed' => array(),
        );
        $usable = function ($v) {
            $v = trim((string) $v);
            return ($v !== '' && is_numeric($v) && (float) $v > 0) ? (float) $v : null;
        };
        $firstRaw = array();

        // gross: P_11A
        $lineRaw = array();
        $weighted = 0.0;
        $weight = 0.0;
        foreach ((array) $lines as $line) {
            $raw = trim((string) ($line['kurs_waluty'] ?? ''));
            if ($raw === '') {
                continue;
            }
            $k = $usable($raw);
            if ($k === null) {
                $data['unusable'][] = $raw;
                $firstRaw[] = $raw;
                continue;
            }
            $key = (string) round($k, 6);
            if (!isset($data['line_rates'][$key])) {
                $data['line_rates'][$key] = $k;
                $lineRaw[$key] = $raw;
            }
            $w = $line['net_amount'] !== null ? (float) $line['net_amount'] : (float) ($line['gross_amount'] ?? 0);
            $weighted += abs($w) * $k;
            $weight += abs($w);
        }
        $data['line_rates'] = array_values($data['line_rates']);

        $zRaw = $this->getValue($xpath, '//fa:Fa/fa:KursWalutyZ', '');
        if ($zRaw !== '') {
            $data['deposit_rate'] = $usable($zRaw);
            if ($data['deposit_rate'] === null) {
                $data['unusable'][] = $zRaw;
                $firstRaw[] = $zRaw;
            }
        }
        $zkRaw = $this->getValue($xpath, '//fa:Fa/fa:KursWalutyZK', '');
        $data['before_rate'] = $usable($zkRaw);

        $payWeighted = 0.0;
        $payWeight = 0.0;
        $payRates = array();
        foreach ($xpath->query('//fa:Fa/fa:ZaliczkaCzesciowa') as $zc) {
            $wRaw = (string) $this->getValueRel($xpath, 'fa:KursWalutyZW', $zc, '');
            if ($wRaw === '') {
                continue;
            }
            $k = $usable($wRaw);
            if ($k === null) {
                $data['unusable'][] = $wRaw;
                $firstRaw[] = $wRaw;
                continue;
            }
            $amount = abs((float) $this->getValueRel($xpath, 'fa:P_15Z', $zc, '0'));
            $payWeighted += $amount * $k;
            $payWeight += $amount;
            $payRates[(string) round($k, 6)] = $k;
        }
        $data['payment_rates'] = array_values($payRates);

        $fromLines = function () use (&$data, $lineRaw, $weighted, $weight) {
            if (count($data['line_rates']) === 1) {
                $data['rate'] = reset($lineRaw);
                $data['source'] = 'line';
            } elseif (count($data['line_rates']) > 1 && $weight > 0) {
                $data['rate'] = round($weighted / $weight, 6);
                $data['source'] = 'line_weighted';
            }
        };
        $fromDeposit = function () use (&$data, $payWeighted, $payWeight) {
            if ($data['deposit_rate'] !== null) {
                $data['rate'] = $data['deposit_rate'];
                $data['source'] = 'deposit';
            } elseif (count($data['payment_rates']) === 1) {
                $data['rate'] = reset($data['payment_rates']);
                $data['source'] = 'payment';
            } elseif (count($data['payment_rates']) > 1 && $payWeight > 0) {
                $data['rate'] = round($payWeighted / $payWeight, 6);
                $data['source'] = 'payment_weighted';
            }
        };
        // art. 106b(1)(4)
        if (in_array(strtoupper((string) $type), array('ZAL', 'KOR_ZAL'), true)) {
            $fromDeposit();
            if ($data['rate'] === null) {
                $fromLines();
            }
        } else {
            $fromLines();
            if ($data['rate'] === null) {
                $fromDeposit();
            }
        }
        if ($data['rate'] === null && $firstRaw) {
            $data['rate'] = $firstRaw[0];
        }

        $all = array();
        foreach (array_merge($data['line_rates'], $data['deposit_rate'] !== null ? array($data['deposit_rate']) : array(), $data['payment_rates']) as $k) {
            $all[(string) round($k, 6)] = $k;
        }
        if (count($all) > 1 || ($all && $data['unusable'])) {
            $data['mixed'] = array_merge(array_values($all), array_values(array_unique($data['unusable'])));
        }

        return $data;
    }

    /**
     * @brief Get text value
     * @param $xpath
     * @param $query
     * @param $default
     * @return string
     */
    private function getValue($xpath, $query, $default = '')
    {
        $nodes = $xpath->query($query);
        if ($nodes->length > 0) {
            return trim($nodes->item(0)->textContent);
        }
        return $default;
    }

    /**
     * @brief Get decimal value
     * @param $xpath
     * @param $query
     * @param $default
     * @return float
     */
    private function getDecimal($xpath, $query, $default = 0.0)
    {
        $value = $this->getValue($xpath, $query, null);
        if ($value === null || $value === '') {
            return $default;
        }
        return (float)$value;
    }

    /**
     * @brief Null or blank
     * @param mixed $val
     * @return bool
     */
    private function isNullOrBlank($val)
    {
        return $val === null || $val === '';
    }

    /**
     * @brief Payment method label
     * @param $code
     * @return string
     */
    public function getPaymentMethodDescription($code)
    {
        $methods = array(
            '1' => 'Gotówka',
            '2' => 'Karta',
            '3' => 'Bon',
            '4' => 'Czek',
            '5' => 'Kredyt',
            '6' => 'Przelew',
            '7' => 'Mobilna',
        );
        return isset($methods[$code]) ? $methods[$code] : $code;
    }

    /**
     * @brief Invoice type label
     * @param $type
     * @return string
     */
    public function getInvoiceTypeDescription($type)
    {
        $types = array(
            'VAT' => 'Faktura VAT',
            'KOR' => 'Faktura korygująca',
            'ZAL' => 'Faktura zaliczkowa',
            'ROZ' => 'Faktura rozliczeniowa',
            'UPR' => 'Faktura uproszczona',
            'KOR_ZAL' => 'Korekta faktury zaliczkowej',
            'KOR_ROZ' => 'Korekta faktury rozliczeniowej',
        );
        return isset($types[$type]) ? $types[$type] : $type;
    }
}