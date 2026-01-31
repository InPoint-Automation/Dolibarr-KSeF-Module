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
 * \brief   FA(3) XML Parser
 */

class FA3Parser
{
    private $db;
    public $error;
    public $errors = array();

    public function __construct($db)
    {
        $this->db = $db;
    }


    /**
     * @brief Parse FA(3) XML string into structured array
     * @param $xml Raw XML string
     * @return array|false Parsed data or false on error
     * @called_by KSEF::syncIncomingInvoices()
     * @calls parseNaglowek(), parsePodmiot1(), parsePodmiot2(), parseFa(), parseVatSummary(), parseFaWiersz(), parsePlatnosc(), parseDaneFaKorygowanej()
     */
    public function parse($xml)
    {
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

            $xpath = new DOMXPath($doc);
            $xpath->registerNamespace('fa', KSEF_FA3_NAMESPACE);

            $result = array(
                'header' => $this->parseNaglowek($xpath),
                'seller' => $this->parsePodmiot1($xpath),
                'buyer' => $this->parsePodmiot2($xpath),
                'invoice' => $this->parseFa($xpath),
                'vat_summary' => $this->parseVatSummary($xpath),
                'lines' => $this->parseFaWiersz($xpath),
                'payment' => $this->parsePlatnosc($xpath),
                'correction' => $this->parseDaneFaKorygowanej($xpath),
            );

            return $result;

        } catch (Exception $e) {
            $this->error = "FA(3) parsing error: " . $e->getMessage();
            $this->errors[] = $this->error;
            dol_syslog("FA3Parser::parse ERROR: " . $this->error, LOG_ERR);
            return false;
        }
    }


    /**
     * @brief Parse header section (Naglowek)
     * @param $xpath DOMXPath object
     * @return array Header data
     * @called_by parse()
     * @calls getValue()
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

        $dateStr = $this->getValue($xpath, '//fa:Naglowek/fa:DataWytworzeniaFa');
        if ($dateStr) {
            $header['creation_date'] = strtotime($dateStr);
        }

        $header['system_info'] = $this->getValue($xpath, '//fa:Naglowek/fa:SystemInfo');

        return $header;
    }


    /**
     * @brief Parse seller section (Podmiot1)
     * @param $xpath DOMXPath object
     * @return array Seller data
     * @called_by parse()
     * @calls getValue()
     */
    private function parsePodmiot1($xpath)
    {
        $seller = array(
            'nip' => '',
            'name' => '',
            'country' => 'PL',
            'address' => '',
        );

        $seller['nip'] = $this->getValue($xpath, '//fa:Podmiot1/fa:DaneIdentyfikacyjne/fa:NIP');
        $seller['name'] = $this->getValue($xpath, '//fa:Podmiot1/fa:DaneIdentyfikacyjne/fa:Nazwa');
        $seller['country'] = $this->getValue($xpath, '//fa:Podmiot1/fa:Adres/fa:KodKraju', 'PL');
        $seller['address'] = $this->getValue($xpath, '//fa:Podmiot1/fa:Adres/fa:AdresL1');

        $adresL2 = $this->getValue($xpath, '//fa:Podmiot1/fa:Adres/fa:AdresL2');
        if ($adresL2) {
            $seller['address'] .= ', ' . $adresL2;
        }

        return $seller;
    }


    /**
     * @brief Parse buyer section (Podmiot2)
     * @param $xpath DOMXPath object
     * @return array Buyer data
     * @called_by parse()
     * @calls getValue()
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
        );

        // NIP
        $buyer['nip'] = $this->getValue($xpath, '//fa:Podmiot2/fa:DaneIdentyfikacyjne/fa:NIP');
        if (empty($buyer['nip'])) {
            $nrIdNode = $xpath->query('//fa:Podmiot2/fa:DaneIdentyfikacyjne/fa:NrID');
            if ($nrIdNode->length > 0) {
                $buyer['nip'] = $nrIdNode->item(0)->textContent;
                $kodKraju = $nrIdNode->item(0)->getAttribute('kodKraju');
                if ($kodKraju) {
                    $buyer['country'] = $kodKraju;
                }
            }
        }

        $buyer['name'] = $this->getValue($xpath, '//fa:Podmiot2/fa:DaneIdentyfikacyjne/fa:Nazwa');

        // Address
        $adresKodKraju = $this->getValue($xpath, '//fa:Podmiot2/fa:Adres/fa:KodKraju');
        if ($adresKodKraju) {
            $buyer['country'] = $adresKodKraju;
        }

        $buyer['address'] = $this->getValue($xpath, '//fa:Podmiot2/fa:Adres/fa:AdresL1');
        $adresL2 = $this->getValue($xpath, '//fa:Podmiot2/fa:Adres/fa:AdresL2');
        if ($adresL2) {
            $buyer['address'] .= ', ' . $adresL2;
        }

        // Entity flags
        $buyer['jst'] = $this->getValue($xpath, '//fa:Podmiot2/fa:JST');
        $buyer['gv'] = $this->getValue($xpath, '//fa:Podmiot2/fa:GV');

        return $buyer;
    }


    /**
     * @brief Parse invoice data section (Fa)
     * @param $xpath DOMXPath object
     * @return array Invoice data
     * @called_by parse()
     * @calls getValue(), getDecimal()
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
        );

        $invoice['currency'] = $this->getValue($xpath, '//fa:Fa/fa:KodWaluty', 'PLN');
        $invoice['number'] = $this->getValue($xpath, '//fa:Fa/fa:P_2');
        $invoice['type'] = $this->getValue($xpath, '//fa:Fa/fa:RodzajFaktury', 'VAT');

        $p1 = $this->getValue($xpath, '//fa:Fa/fa:P_1');
        if ($p1) {
            $invoice['date'] = strtotime($p1);
        }

        $p6 = $this->getValue($xpath, '//fa:Fa/fa:P_6');
        if ($p6) {
            $invoice['sale_date'] = strtotime($p6);
        }

        $totalNet = 0.0;
        $totalVat = 0.0;

        // Net amounts by rate
        $totalNet += $this->getDecimal($xpath, '//fa:Fa/fa:P_13_1');   // 23%
        $totalNet += $this->getDecimal($xpath, '//fa:Fa/fa:P_13_2');   // 8%
        $totalNet += $this->getDecimal($xpath, '//fa:Fa/fa:P_13_3');   // 5%
        $totalNet += $this->getDecimal($xpath, '//fa:Fa/fa:P_13_6_1'); // 0%
        $totalNet += $this->getDecimal($xpath, '//fa:Fa/fa:P_13_6_2'); // Exempt

        // VAT amounts by rate
        $totalVat += $this->getDecimal($xpath, '//fa:Fa/fa:P_14_1');   // 23%
        $totalVat += $this->getDecimal($xpath, '//fa:Fa/fa:P_14_2');   // 8%
        $totalVat += $this->getDecimal($xpath, '//fa:Fa/fa:P_14_3');   // 5%

        $invoice['total_net'] = $totalNet;
        $invoice['total_vat'] = $totalVat;

        // P_15: Total
        $p15 = $this->getDecimal($xpath, '//fa:Fa/fa:P_15');
        $invoice['total_gross'] = $p15 ?: ($totalNet + $totalVat);

        return $invoice;
    }


    /**
     * @brief Parse VAT summary from P_13 fields
     * @param $xpath DOMXPath object
     * @return array VAT summary by rate
     * @called_by parse()
     * @calls getDecimal()
     */
    private function parseVatSummary($xpath)
    {
        $summary = array();

        // 23% rate (P_13_1 / P_14_1)
        $net23 = $this->getDecimal($xpath, '//fa:Fa/fa:P_13_1');
        $vat23 = $this->getDecimal($xpath, '//fa:Fa/fa:P_14_1');
        if ($net23 > 0 || $vat23 > 0) {
            $summary['23'] = array('net' => $net23, 'vat' => $vat23);
        }

        // 8% rate (P_13_2 / P_14_2)
        $net8 = $this->getDecimal($xpath, '//fa:Fa/fa:P_13_2');
        $vat8 = $this->getDecimal($xpath, '//fa:Fa/fa:P_14_2');
        if ($net8 > 0 || $vat8 > 0) {
            $summary['8'] = array('net' => $net8, 'vat' => $vat8);
        }

        // 5% rate (P_13_3 / P_14_3)
        $net5 = $this->getDecimal($xpath, '//fa:Fa/fa:P_13_3');
        $vat5 = $this->getDecimal($xpath, '//fa:Fa/fa:P_14_3');
        if ($net5 > 0 || $vat5 > 0) {
            $summary['5'] = array('net' => $net5, 'vat' => $vat5);
        }

        // 0% rate (P_13_6_1)
        $net0 = $this->getDecimal($xpath, '//fa:Fa/fa:P_13_6_1');
        if ($net0 > 0) {
            $summary['0'] = array('net' => $net0, 'vat' => 0.0);
        }

        // Exempt (P_13_6_2)
        $netZw = $this->getDecimal($xpath, '//fa:Fa/fa:P_13_6_2');
        if ($netZw > 0) {
            $summary['zw'] = array('net' => $netZw, 'vat' => 0.0);
        }

        return $summary;
    }


    /**
     * @brief Parse invoice line items (FaWiersz)
     * @param $xpath DOMXPath object
     * @return array Line items
     * @called_by parse()
     */
    private function parseFaWiersz($xpath)
    {
        $lines = array();

        $faWierszNodes = $xpath->query('//fa:Fa/fa:FaWiersz');

        foreach ($faWierszNodes as $node) {
            $line = array(
                'line_num' => 0,
                'description' => '',
                'quantity' => 0.0,
                'unit_price' => 0.0,
                'net_amount' => 0.0,
                'vat_rate' => 0,
            );

            foreach ($node->childNodes as $child) {
                if ($child->nodeType !== XML_ELEMENT_NODE) continue;

                switch ($child->localName) {
                    case 'NrWierszaFa':
                        $line['line_num'] = (int)$child->textContent;
                        break;
                    case 'P_7':
                        $line['description'] = $child->textContent;
                        break;
                    case 'P_8B':
                        $line['quantity'] = (float)$child->textContent;
                        break;
                    case 'P_9A':
                        $line['unit_price'] = (float)$child->textContent;
                        break;
                    case 'P_11':
                        $line['net_amount'] = (float)$child->textContent;
                        break;
                    case 'P_12':
                        $line['vat_rate'] = (int)$child->textContent;
                        break;
                }
            }

            $lines[] = $line;
        }

        return $lines;
    }


    /**
     * @brief Parse payment information (Platnosc)
     * @param $xpath DOMXPath object
     * @return array Payment info
     * @called_by parse()
     * @calls getValue()
     */
    private function parsePlatnosc($xpath)
    {
        $payment = array(
            'due_date' => null,
            'method' => '',
            'bank_account' => '',
        );

        // Due date
        $termin = $this->getValue($xpath, '//fa:Fa/fa:Platnosc/fa:TerminPlatnosci/fa:Termin');
        if ($termin) {
            $payment['due_date'] = strtotime($termin);
        }

        // Payment method code
        $payment['method'] = $this->getValue($xpath, '//fa:Fa/fa:Platnosc/fa:FormaPlatnosci');

        // Bank account
        $payment['bank_account'] = $this->getValue($xpath, '//fa:Fa/fa:Platnosc/fa:RachunekBankowy/fa:NrRB');

        return $payment;
    }


    /**
     * @brief Parse correction reference (DaneFaKorygowanej)
     * @param $xpath DOMXPath object
     * @return array|null Correction reference or null if not a correction
     * @called_by parse()
     * @calls getValue()
     */
    private function parseDaneFaKorygowanej($xpath)
    {
        $daneFaKor = $xpath->query('//fa:Fa/fa:DaneFaKorygowanej');
        if ($daneFaKor->length == 0) {
            return null;
        }

        $correction = array(
            'invoice_date' => null,
            'invoice_number' => '',
            'ksef_number' => '',
        );

        // invoice date
        $dateStr = $this->getValue($xpath, '//fa:Fa/fa:DaneFaKorygowanej/fa:DataWystFaKorygowanej');
        if ($dateStr) {
            $correction['invoice_date'] = strtotime($dateStr);
        }

        $correction['invoice_number'] = $this->getValue($xpath, '//fa:Fa/fa:DaneFaKorygowanej/fa:NrFaKorygowanej');
        $correction['ksef_number'] = $this->getValue($xpath, '//fa:Fa/fa:DaneFaKorygowanej/fa:NrKSeFFaKorygowanej');

        return $correction;
    }


    /**
     * @brief Get text value from XPath query
     * @param $xpath DOMXPath object
     * @param $query XPath query string
     * @param $default Default value if not found
     * @return string Text value
     * @called_by parseNaglowek(), parsePodmiot1(), parsePodmiot2(), parseFa(), parsePlatnosc(), parseDaneFaKorygowanej()
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
     * @brief Get decimal value from XPath query
     * @param $xpath DOMXPath object
     * @param $query XPath query string
     * @param $default Default value if not found
     * @return float Decimal value
     * @called_by parseFa(), parseVatSummary()
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
     * @brief Get payment method description
     * @param $code Payment method code
     * @return string Description
     */
    public function getPaymentMethodDescription($code)
    {
        $methods = array(
            '1' => 'Gotówka',           // Cash
            '2' => 'Karta',             // Card
            '3' => 'Bon',               // Voucher
            '4' => 'Czek',              // Check
            '5' => 'Kredyt',            // Credit
            '6' => 'Przelew',           // Bank transfer
            '7' => 'Mobilna',           // Mobile payment
        );
        return isset($methods[$code]) ? $methods[$code] : $code;
    }


    /**
     * @brief Get invoice type description
     * @param $type Invoice type code
     * @return string Description
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