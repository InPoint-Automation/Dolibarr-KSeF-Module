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
 * \file    ksef/class/ksef_nbp_currency_rate.class.php
 * \ingroup ksef
 * \brief   NBP rate client
 */

require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
dol_include_once('/ksef/lib/ksef.lib.php');

class KsefNbpCurrencyRate
{
    /** @var int */
    const STALE_MAX_GAP_DAYS = 8;

    /** @var array */
    const TABLE_A_CURRENCIES = array('THB', 'USD', 'AUD', 'HKD', 'CAD', 'NZD', 'SGD', 'EUR', 'HUF', 'CHF', 'GBP', 'UAH', 'JPY', 'CZK',
        'DKK', 'ISK', 'NOK', 'SEK', 'RON', 'TRY', 'ILS', 'CLP', 'PHP', 'MXN', 'ZAR', 'BRL', 'MYR', 'IDR', 'INR', 'KRW', 'CNY', 'XDR');

    private $db;
    public $entity;
    public $error = '';
    public $errors = array();
    /** @var string */
    public $errorCode = '';
    public $output = '';
    const API_BASE_URL = 'https://api.nbp.pl/api/exchangerates/rates';
    const API_TABLES_URL = 'https://api.nbp.pl/api/exchangerates/tables';
    const LOOKBACK_DAYS = 14;
    const API_TIMEOUT = 10;

    /**
     * @brief Constructor
     * @param DoliDB $db
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * @brief D-1 in Warsaw
     * @param int $ts
     * @return string YYYY-MM-DD
     */
    protected function warsawDayBefore($ts)
    {
        $dt = new DateTime('@' . (int) $ts);
        $dt->setTimezone(new DateTimeZone('Europe/Warsaw'));
        $dt->modify('-1 day');
        return $dt->format('Y-m-d');
    }

    /**
     * @brief Day before reference
     * @param int $ts Reference date
     * @return string YYYY-MM-DD
     */
    protected function referenceDayBefore($ts)
    {
        dol_include_once('/ksef/lib/ksef.lib.php');
        $day = ksefMomentDay($ts);
        return date('Y-m-d', strtotime(($day !== '' ? $day : date('Y-m-d', (int) $ts)) . ' -1 day'));
    }

    /**
     * @brief Fetch NBP rate
     * @param string $currencyCode
     * @param int $referenceDate
     * @return array|false false on error
     */
    public function getRateForDate($currencyCode, $referenceDate)
    {
        $this->error = '';
        $this->errors = array();

        if (empty($currencyCode)) {
            $this->error = 'Currency code is required';
            return false;
        }

        if ($currencyCode === 'PLN') {
            $this->error = 'PLN does not require exchange rate';
            return false;
        }

        $currencyCode = strtoupper(trim($currencyCode));
        $endDate = $this->referenceDayBefore($referenceDate);
        $startDate = date('Y-m-d', strtotime('-' . self::LOOKBACK_DAYS . ' days', strtotime($endDate)));

        dol_syslog("KsefNbpCurrencyRate::getRateForDate currency=$currencyCode reference=" . date('Y-m-d', $referenceDate) . " range=$startDate/$endDate", LOG_DEBUG);

        $result = $this->fetchFromAPI($currencyCode, $startDate, $endDate, 'a');

        if ($result === false && strpos($this->error, '404') !== false) {
            dol_syslog("KsefNbpCurrencyRate::getRateForDate Table A returned 404, trying Table B", LOG_DEBUG);
            $this->error = '';
            $result = $this->fetchFromAPI($currencyCode, $startDate, $endDate, 'b');
        }

        if ($result === false) {
            if (empty($this->error)) {
                $this->error = sprintf('No NBP rate found for %s in date range %s to %s', $currencyCode, $startDate, $endDate);
            }
            dol_syslog("KsefNbpCurrencyRate::getRateForDate FAILED: " . $this->error, LOG_WARNING);
            return false;
        }

        dol_syslog("KsefNbpCurrencyRate::getRateForDate SUCCESS: " . $currencyCode . " = " . $result['rate'] . " from " . $result['date'], LOG_INFO);
        return $result;
    }

    /**
     * @brief Fetch currency rate
     * @param string $currencyCode
     * @param string $startDate YYYY-MM-DD
     * @param string $endDate YYYY-MM-DD
     * @param string $table 'a' or 'b'
     * @return array|false false on error
     */
    protected function fetchFromAPI($currencyCode, $startDate, $endDate, $table = 'a')
    {
        $url = ksefEndpointOverride('NBP', self::API_BASE_URL) . '/' . $table . '/' . strtolower($currencyCode) . '/' . $startDate . '/' . $endDate . '/?format=json';

        dol_syslog("KsefNbpCurrencyRate::fetchFromAPI URL: " . $url, LOG_DEBUG);

        $ch = curl_init();
        ksefCurlApplyProxy($ch);
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::API_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => array(
                'Accept: application/json',
                'User-Agent: Dolibarr-KSeF-Module/1.0'
            ),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
        ));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $this->error = 'NBP API connection error: ' . $curlError;
            return false;
        }

        if ($httpCode == 404) {
            $this->error = '404 - Currency not found in table ' . strtoupper($table) . ' or no rates in date range';
            return false;
        }

        if ($httpCode != 200) {
            $this->error = 'NBP API error: HTTP ' . $httpCode;
            return false;
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error = 'NBP API error: Invalid JSON response';
            return false;
        }

        if (empty($data['rates']) || !is_array($data['rates'])) {
            $this->error = 'NBP API error: No rates in response';
            return false;
        }

        $lastRate = end($data['rates']);

        if (empty($lastRate['effectiveDate']) || !isset($lastRate['mid'])) {
            $this->error = 'NBP API error: Invalid rate data structure';
            return false;
        }

        return array(
            'date' => $lastRate['effectiveDate'],
            'rate' => (float) $lastRate['mid'],
            'table' => strtoupper($table),
            'currency' => strtoupper($currencyCode),
            'no' => (string) ($lastRate['no'] ?? ''),
        );
    }

    /**
     * @brief Whole NBP table
     * @param string $table 'a' or 'b'
     * @param string $startDate YYYY-MM-DD
     * @param string $endDate YYYY-MM-DD
     * @return array|false Rates by code
     */
    protected function fetchTableRates($table, $startDate, $endDate)
    {
        $url = ksefEndpointOverride('NBP_TABLES', self::API_TABLES_URL) . '/' . $table . '/' . $startDate . '/' . $endDate . '/?format=json';

        dol_syslog("KsefNbpCurrencyRate::fetchTableRates URL: " . $url, LOG_DEBUG);

        $ch = curl_init();
        ksefCurlApplyProxy($ch);
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::API_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => array(
                'Accept: application/json',
                'User-Agent: Dolibarr-KSeF-Module/1.0'
            ),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
        ));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $this->error = 'NBP API connection error: ' . $curlError;
            return false;
        }

        if ($httpCode == 404) {
            $this->error = '404 - No table ' . strtoupper($table) . ' published in date range';
            return false;
        }

        if ($httpCode != 200) {
            $this->error = 'NBP API error: HTTP ' . $httpCode;
            return false;
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE || empty($data) || !is_array($data)) {
            $this->error = 'NBP API error: Invalid JSON response';
            return false;
        }

        $lastTable = end($data);
        if (empty($lastTable['effectiveDate']) || empty($lastTable['rates']) || !is_array($lastTable['rates'])) {
            $this->error = 'NBP API error: Invalid table data structure';
            return false;
        }

        $rates = array();
        foreach ($lastTable['rates'] as $row) {
            if (empty($row['code']) || !isset($row['mid'])) {
                continue;
            }
            $rates[strtoupper($row['code'])] = array(
                'rate' => (float) $row['mid'],
                'date' => $lastTable['effectiveDate'],
                'table' => strtoupper($table),
                'no' => (string) ($lastTable['no'] ?? ''),
            );
        }

        return $rates;
    }

    /**
     * @brief Store NBP rate
     * @param Facture $invoice
     * @param User $user
     * @param string $pick '', 'keep' or 'exact'
     * @return array|false false on error
     */
    public function fetchAndStoreForInvoice(&$invoice, $user, $pick = '')
    {
        global $conf;

        $this->error = '';
        $this->errors = array();
        $this->errorCode = '';
        $invoiceCurrency = $this->getInvoiceCurrency($invoice);

        if ($invoiceCurrency === 'PLN') {
            $this->error = 'Invoice is in PLN, no NBP rate needed';
            return false;
        }

        if (!empty($invoice->id) && ($invoice->element ?? 'facture') === 'facture') {
            dol_include_once('/ksef/lib/ksef.lib.php');
            $lock = ksefInvoiceModifyLock($this->db, (int) $invoice->id);
            if (($lock['result'] ?? '') === 'blocked') {
                global $langs;
                $langs->load('ksef@ksef');
                $this->error = !empty($lock['key']) ? $langs->transnoentitiesnoconv($lock['key'], ...((array) ($lock['args'] ?? array()))) : $langs->transnoentitiesnoconv('KSEF_CannotModifySubmitted');
                dol_syslog("KsefNbpCurrencyRate::fetchAndStoreForInvoice refused on invoice " . (int) $invoice->id . ": " . ($lock['reason'] ?? 'locked'), LOG_WARNING);
                return false;
            }
        }

        $referenceDate = $this->getReferenceDate($invoice, 0, $pick === 'exact');

        if (empty($referenceDate)) {
            $this->error = 'Invoice date is required to fetch NBP rate';
            return false;
        }

        $rateData = $this->getRateForDate($invoiceCurrency, $referenceDate);

        if ($rateData === false) {
            return false;
        }

        $nbpRate = (float) $rateData['rate'];
        $dolibarrTx = 1 / $nbpRate;
        $txForSql = number_format($dolibarrTx, 10, '.', '');

        $korekta = ((int) $invoice->type === Facture::TYPE_REPLACEMENT && !empty($invoice->fk_facture_source));
        $rateMode = ($pick !== '' || $korekta || (int) $invoice->statut !== Facture::STATUS_DRAFT) ? 'keep_foreign' : getDolGlobalString('KSEF_NBP_RATE_MODE', 'keep_foreign');

        $sameRateValidated = (int) $invoice->statut !== Facture::STATUS_DRAFT && abs((float) $invoice->multicurrency_tx - $dolibarrTx) <= 1e-8;
        if ($korekta && (int) $invoice->statut !== Facture::STATUS_DRAFT && !$sameRateValidated) {
            $settled = $this->korektaSettledParts($invoice);
            if ($settled === false || !empty($settled)) {
                global $langs;
                $langs->load('ksef@ksef');
                $parts = array();
                foreach ((array) $settled as $p) {
                    $parts[] = $langs->transnoentitiesnoconv($p === 'payments' ? 'KSEF_NBPRateSettledPayments' : 'KSEF_NBPRateSettledCredits');
                }
                $this->error = $settled === false ? 'Cannot read the payments of ' . $invoice->ref . ': ' . $this->db->lasterror()
                    : $langs->transnoentitiesnoconv('KSEF_NBPRateKorektaSettled', $invoice->ref, implode(', ', $parts));
                $this->errorCode = 'RATE_CHANGE_SETTLED';
                dol_syslog("KsefNbpCurrencyRate::fetchAndStoreForInvoice refused on korekta " . (int) $invoice->id . ": PLN totals would change, settled " . json_encode($settled), LOG_WARNING);
                return false;
            }
        }
        if ((int) $invoice->statut !== Facture::STATUS_DRAFT && !$sameRateValidated) {
            $booked = $this->rateChangeBlocked($invoice);
            if ($booked === false || !empty($booked)) {
                $this->error = $booked === false ? 'Cannot read the payments or ledger rows of ' . $invoice->ref . ': ' . $this->db->lasterror() : $this->rateChangeBookedText($invoice, $booked);
                $this->errorCode = 'RATE_CHANGE_BOOKED';
                dol_syslog("KsefNbpCurrencyRate::fetchAndStoreForInvoice refused on invoice " . (int) $invoice->id . ": amounts would change, " . json_encode($booked), LOG_WARNING);
                return false;
            }
        }

        dol_syslog("KsefNbpCurrencyRate::fetchAndStoreForInvoice - NBP rate: $nbpRate, Dolibarr tx: $dolibarrTx, Mode: $rateMode", LOG_DEBUG);

        $this->db->begin();
        $error = 0;
        $id = (int) $invoice->id;

        if (!$sameRateValidated) {
            dol_include_once('/ksef/lib/ksef.lib.php');
            // keep_base reads rate
            $sql = "UPDATE " . MAIN_DB_PREFIX . "facture SET multicurrency_tx = " . $txForSql . " WHERE rowid = " . $id;
            dol_syslog("KsefNbpCurrencyRate::fetchAndStoreForInvoice SQL header: " . $sql, LOG_DEBUG);
            if (!$this->db->query($sql)) {
                $error++;
                $this->error = 'Failed to update invoice header: ' . $this->db->lasterror();
            }
            $txStored = round($dolibarrTx, 8);
            $step = $error ? '' : ($rateMode === 'keep_base' ? $this->foreignFromPlnUnitPrices($id, $txStored) : $this->plnUnitPricesFromForeign($id, $txStored));
            if ($step !== '') {
                $error++;
                $this->error = 'Failed to update invoice lines: ' . $step;
            }
            if (!$error && ($invoice->element ?? 'facture') === 'facture') {
                $lineRates = ksefLineKursRefresh($this->db, $invoice, $nbpRate);
                if ($lineRates['result'] < 0) {
                    $error++;
                    $this->error = 'Line exchange rates: ' . $lineRates['error'];
                }
            }
            if (!$error) {
                $derived = ksefDerivePlnFromForeign($this->db, $id, array('kurs' => $nbpRate));
                if ($derived['result'] < 0 || !in_array($derived['skip'], array('', 'no_lines'), true)) {
                    $error++;
                    $this->error = 'Amounts do not add up after the rate change: ' . ($derived['error'] !== '' ? $derived['error'] : 'derivation skipped (' . $derived['skip'] . ')');
                }
            }
            if ($error) {
                dol_syslog("KsefNbpCurrencyRate::fetchAndStoreForInvoice ERROR: " . $this->error, LOG_ERR);
            }
        } elseif (($invoice->element ?? 'facture') === 'facture') {
            dol_include_once('/ksef/lib/ksef.lib.php');
            $linePrep = ksefLineKursSendPrepare($this->db, $invoice, 'plain');
            if (!$linePrep['ok']) {
                $error++;
                $this->error = $linePrep['message'];
                $this->errorCode = $linePrep['code'] === 'line_rates_booked' ? 'RATE_CHANGE_BOOKED' : 'LINE_RATES';
                dol_syslog("KsefNbpCurrencyRate::fetchAndStoreForInvoice line rates of invoice " . (int) $invoice->id . ": " . $this->error, LOG_WARNING);
            }
        }

        if (!$error) {
            if (!isset($invoice->array_options) || !is_array($invoice->array_options)) {
                $invoice->array_options = array();
            }
            $invoice->fetch_optionals();
            $invoice->array_options['options_ksef_kurs_data'] = strtotime($rateData['date']);

            $result = $invoice->insertExtraFields();
            if ($result < 0) {
                $error++;
                $this->error = 'Failed to save extrafields: ' . $invoice->error;
                dol_syslog("KsefNbpCurrencyRate::fetchAndStoreForInvoice ERROR: " . $this->error, LOG_ERR);
            }
        }

        if ($error) {
            $this->db->rollback();
            return false;
        }

        $this->db->commit();
        $invoice->fetch($invoice->id);
        $invoice->fetch_lines();

        $action = null;
        if ($this->addRateToCache($invoiceCurrency, $dolibarrTx, $rateData['date'], $user, $action, (string) ($rateData['table'] ?? ''), (string) ($rateData['no'] ?? ''))) {
            $this->supersedeCurrentRate($invoiceCurrency, $dolibarrTx, (string) $rateData['date'], $user);
        }

        dol_syslog("KsefNbpCurrencyRate::fetchAndStoreForInvoice SUCCESS: Invoice " . $invoice->ref .
            " NBP rate=" . $nbpRate . " PLN/" . $invoiceCurrency .
            " (stored tx=" . $dolibarrTx . ") mode=" . $rateMode . " date=" . $rateData['date'], LOG_INFO);

        return $rateData;
    }

    /**
     * @brief Foreign from PLN
     * @param int $invoiceId
     * @param float $tx Foreign per PLN
     * @return string SQL error or ''
     */
    private function foreignFromPlnUnitPrices($invoiceId, $tx)
    {
        global $mysoc;
        require_once DOL_DOCUMENT_ROOT . '/core/lib/price.lib.php';
        $res = $this->db->query("SELECT rowid, qty, subprice, remise_percent, tva_tx, localtax1_tx, localtax2_tx, localtax1_type, localtax2_type, info_bits, product_type,"
            . " situation_percent, multicurrency_code FROM " . MAIN_DB_PREFIX . "facturedet WHERE fk_facture = " . (int) $invoiceId
            . " AND (fk_remise_except IS NULL OR fk_remise_except = 0) AND product_type <> 9");
        if (!$res) {
            return $this->db->lasterror();
        }
        $rows = array();
        while ($o = $this->db->fetch_object($res)) {
            $rows[] = $o;
        }
        foreach ($rows as $o) {
            $msub = (float) price2num((float) $o->subprice * $tx, 'MU');
            // price.lib, tx=1
            $r = calcul_price_total((float) $o->qty, (float) $o->subprice, (float) $o->remise_percent, (float) $o->tva_tx, (float) $o->localtax1_tx, (float) $o->localtax2_tx, 0, 'HT',
                (int) $o->info_bits, (int) $o->product_type, $mysoc, array($o->localtax1_type, $o->localtax1_tx, $o->localtax2_type, $o->localtax2_tx),
                ($o->situation_percent !== null ? (float) $o->situation_percent : 100), $tx, $msub, (string) $o->multicurrency_code);
            $sql = "UPDATE " . MAIN_DB_PREFIX . "facturedet SET multicurrency_subprice = " . price2num($msub, 'MU')
                . ", multicurrency_total_ht = " . price2num((float) $r[16], 'MT') . ", multicurrency_total_tva = " . price2num((float) $r[17], 'MT')
                . ", multicurrency_total_ttc = " . price2num((float) $r[18], 'MT') . " WHERE rowid = " . (int) $o->rowid;
            if (!$this->db->query($sql)) {
                return $this->db->lasterror();
            }
        }
        return '';
    }

    /**
     * @brief PLN from foreign
     * @param int $invoiceId
     * @param float $tx Foreign per PLN
     * @return string SQL error or ''
     */
    private function plnUnitPricesFromForeign($invoiceId, $tx)
    {
        $res = $this->db->query("SELECT rowid, multicurrency_subprice FROM " . MAIN_DB_PREFIX . "facturedet WHERE fk_facture = " . (int) $invoiceId);
        if (!$res) {
            return $this->db->lasterror();
        }
        $rows = array();
        while ($o = $this->db->fetch_object($res)) {
            $rows[] = $o;
        }
        foreach ($rows as $o) {
            if (!$this->db->query("UPDATE " . MAIN_DB_PREFIX . "facturedet SET subprice = " . price2num((float) $o->multicurrency_subprice / $tx, 'MU') . " WHERE rowid = " . (int) $o->rowid)) {
                return $this->db->lasterror();
            }
        }
        return '';
    }

    /**
     * @brief Settled korekta parts
     * @param Facture $invoice
     * @return array|false false on error
     */
    public function korektaSettledParts($invoice)
    {
        $id = (int) $invoice->id;
        $out = array();
        $res = $this->db->query("SELECT COUNT(*) AS nb FROM " . MAIN_DB_PREFIX . "paiement_facture WHERE fk_facture = " . $id);
        $o = $res ? $this->db->fetch_object($res) : null;
        if (!$o) {
            return false;
        }
        if ((int) $o->nb > 0) {
            $out[] = 'payments';
        }
        $res = $this->db->query("SELECT COUNT(*) AS nb FROM " . MAIN_DB_PREFIX . "societe_remise_except WHERE fk_facture = " . $id . " OR fk_facture_source = " . $id
            . " OR fk_facture_line IN (SELECT rowid FROM " . MAIN_DB_PREFIX . "facturedet WHERE fk_facture = " . $id . ")");
        $o = $res ? $this->db->fetch_object($res) : null;
        if (!$o) {
            return false;
        }
        if (!isset($invoice->array_options) || !is_array($invoice->array_options)) {
            $invoice->fetch_optionals();
        }
        $state = json_decode((string) ($invoice->array_options['options_ksef_correction_state'] ?? ''), true);
        $applied = false;
        foreach (array('excess', 'recov', 'short', 'dep', 'dep_paid', 'setoff', 'abs', 'conv', 'cn_used') as $k) {
            if (is_array($state) && !empty($state[$k])) {
                $applied = true;
            }
        }
        if ((int) $o->nb > 0 || $applied || !empty($invoice->array_options['options_ksef_correction_discount_id'])) {
            $out[] = 'credits';
        }
        return $out;
    }

    /**
     * @brief Modify blockers
     * @param Facture $invoice
     * @return array|false false on error
     */
    public function rateChangeBlocked($invoice)
    {
        if ((int) $invoice->statut === Facture::STATUS_DRAFT || empty($invoice->id)) {
            return array();
        }
        dol_include_once('/ksef/lib/ksef.lib.php');
        $out = array();
        if (getDolGlobalString('INVOICE_CAN_NEVER_BE_EDITED')) {
            $out[] = 'never';
        }
        $res = $this->db->query("SELECT type, fk_statut, paye, total_ttc FROM " . MAIN_DB_PREFIX . "facture WHERE rowid = " . (int) $invoice->id);
        $f = $res ? $this->db->fetch_object($res) : null;
        if (!$f) {
            return false;
        }
        if ((int) $f->fk_statut !== Facture::STATUS_VALIDATED) {
            $out[] = 'closed';
        }
        // caller copy stale
        $fresh = new Facture($this->db);
        if ($fresh->fetch((int) $invoice->id) <= 0) {
            return false;
        }
        $ventil = $fresh->getVentilExportCompta();
        if ($ventil < 0) {
            return false;
        }
        if ($ventil > 0) {
            $out[] = 'accounting';
        }
        if (!getDolGlobalString('INVOICE_CAN_BE_EDITED_EVEN_IF_PAYMENT_DONE')) {
            // -1 is valid
            $fresh->error = '';
            $paid = $fresh->getSommePaiement();
            $credits = $fresh->getSumCreditNotesUsed();
            $deposits = $fresh->getSumDepositsUsed();
            // error text
            if ((string) $fresh->error !== '' || ($credits !== null && !is_numeric($credits))) {
                return false;
            }
            $rest = price2num((float) $f->total_ttc - (float) $paid - (float) $credits - (float) $deposits, 'MT');
            if (!empty($f->paye) || (float) $rest != (float) price2num((float) $f->total_ttc, 'MT', 1)) {
                $hasCredits = (float) price2num((float) $credits + (float) $deposits, 'MT') != 0;
                if (!empty($f->paye) || (float) price2num((float) $paid, 'MT') != 0 || !$hasCredits) {
                    $out[] = 'payments';
                }
                if ($hasCredits) {
                    $out[] = 'credits';
                }
            }
        }
        $ownModify = (int) $f->type === Facture::TYPE_REPLACEMENT && (int) $f->fk_statut === Facture::STATUS_VALIDATED
            && !ksefIsCustomerExcluded((int) $fresh->socid)
            && empty(ksefSubmissionState($this->db, (int) $invoice->id, ksefGetEnvironment())['has_submission']);
        if (!$ownModify && !$fresh->is_last_in_cycle()) {
            $out[] = 'cycle';
        }
        if ($ownModify) {
            // validated misses paid
            $resN = $this->db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "facture WHERE fk_facture_source = " . (int) $invoice->id . " AND type < 2"
                . " AND fk_statut IN (" . Facture::STATUS_VALIDATED . ", " . Facture::STATUS_CLOSED . ")");
            if (!$resN) {
                return false;
            }
            $next = $this->db->num_rows($resN) > 0 ? 1 : 0;
        } else {
            $next = $fresh->getIdReplacingInvoice();
        }
        if ($next < 0) {
            return false;
        }
        if ($next > 0) {
            $out[] = 'replaced';
        }
        return $out;
    }

    /**
     * @brief Rate refusal text
     * @param Facture $invoice
     * @param array $parts Blockers
     * @return string
     */
    public function rateChangeBookedText($invoice, $parts)
    {
        return $this->rateChangeBookedMessage('KSEF_NBPRateBooked', $invoice, $parts, array($invoice->ref));
    }

    /**
     * @brief Replacing invoice
     * @param Facture $invoice
     * @return Facture|null
     */
    public function rateChangeReplacement($invoice)
    {
        if (empty($invoice->id)) {
            return null;
        }
        $src = new Facture($this->db);
        $src->id = (int) $invoice->id;
        $next = (int) $src->getIdReplacingInvoice();
        if ($next <= 0) {
            return null;
        }
        $kor = new Facture($this->db);
        return $kor->fetch($next) > 0 ? $kor : null;
    }

    /**
     * @brief Rate refusal message
     * @param string $key Lang key
     * @param Facture $invoice
     * @param array $parts Blockers
     * @param array $lead Leading args
     * @param string $mode 'plain', 'html' or 'trans'
     * @return string
     */
    public function rateChangeBookedMessage($key, $invoice, $parts, $lead = array(), $mode = 'plain')
    {
        global $langs;
        $langs->load('ksef@ksef');
        $kor = in_array('replaced', (array) $parts, true) ? $this->rateChangeReplacement($invoice) : null;
        if ($kor) {
            $key .= (int) $kor->statut === Facture::STATUS_DRAFT ? 'KorDraft' : 'KorIssued';
        }
        $args = array();
        foreach ((array) $lead as $a) {
            $args[] = $mode === 'html' ? dol_escape_htmltag((string) $a) : (string) $a;
        }
        $args[] = $this->rateChangeBookedParts($parts, $kor, $mode === 'html');
        return $mode === 'trans' ? $langs->trans($key, ...$args) : $langs->transnoentitiesnoconv($key, ...$args);
    }

    /**
     * @brief Blocker list text
     * @param array $parts Blockers
     * @param Facture|null $kor Replacement
     * @param bool $html
     * @return string
     */
    public function rateChangeBookedParts($parts, $kor = null, $html = false)
    {
        global $langs;
        $langs->load('ksef@ksef');
        $keys = array('never' => 'KSEF_NBPRateBookedNever', 'closed' => 'KSEF_NBPRateBookedClosed', 'accounting' => 'KSEF_NBPRateBookedAccounting',
            'payments' => 'KSEF_NBPRateBookedPayments', 'credits' => 'KSEF_NBPRateBookedCredits', 'cycle' => 'KSEF_NBPRateBookedCycle', 'replaced' => 'KSEF_NBPRateBookedReplaced');
        $txt = array();
        foreach ((array) $parts as $p) {
            if ($p === 'replaced' && is_object($kor)) {
                $txt[] = $langs->transnoentitiesnoconv((int) $kor->statut === Facture::STATUS_DRAFT ? 'KSEF_NBPRateBookedReplacedByDraft' : 'KSEF_NBPRateBookedReplacedBy',
                    $html ? $kor->getNomUrl(1) : $kor->ref);
                continue;
            }
            $t = $langs->transnoentitiesnoconv($keys[$p] ?? 'KSEF_NBPRateBookedPayments');
            $txt[] = $html ? dol_escape_htmltag($t) : $t;
        }
        return implode(', ', $txt);
    }

    /**
     * @brief Plain fetch refusal
     * @param Facture $invoice
     * @return array parts, text
     */
    public function rateFetchRefusal($invoice)
    {
        $out = array('parts' => array(), 'text' => '');
        if ((int) $invoice->statut === Facture::STATUS_DRAFT || !$this->invoiceNeedsNBPRate($invoice)) {
            return $out;
        }
        $parts = $this->rateChangeBlocked($invoice);
        if (empty($parts)) {
            return $out;
        }
        $ref = $this->getReferenceDate($invoice);
        $r = $ref ? $this->getRateForDateCached($this->getInvoiceCurrency($invoice), $ref) : false;
        if (!$r || (float) $r['rate'] <= 0 || abs((float) $invoice->multicurrency_tx - 1 / (float) $r['rate']) <= 1e-8) {
            return $out;
        }
        return array('parts' => $parts, 'text' => $this->rateChangeBookedText($invoice, $parts));
    }

    /**
     * @brief Check amount sums
     * @param int $invoiceId
     * @return array
     */
    public function amountsInvariant($invoiceId)
    {
        $out = array('mc' => array(), 'pln' => array(), 'deposit' => array(), 'header' => array(), 'error' => '');
        $id = (int) $invoiceId;
        $cols = array('total_ht', 'total_tva', 'total_ttc', 'multicurrency_total_ht', 'multicurrency_total_tva', 'multicurrency_total_ttc');
        $sum = array_fill_keys($cols, 0.0);
        $res = $this->db->query("SELECT rowid, info_bits, fk_remise_except, total_localtax1, total_localtax2, " . implode(', ', $cols) . " FROM " . MAIN_DB_PREFIX . "facturedet WHERE fk_facture = " . $id);
        if (!$res) {
            $out['error'] = $this->db->lasterror();
            return $out;
        }
        while ($o = $this->db->fetch_object($res)) {
            foreach ($cols as $c) {
                $sum[$c] += (float) $o->$c;
            }
            if (!empty($o->fk_remise_except)) {
                $out['deposit'][] = (int) $o->rowid;
            }
            if ((int) $o->info_bits & 1) {
                continue;
            }
            $lt = (float) $o->total_localtax1 + (float) $o->total_localtax2;
            if (abs((float) $o->total_ttc - (float) $o->total_ht - (float) $o->total_tva - $lt) >= 0.001) {
                $out['pln'][] = (int) $o->rowid;
            }
            if (abs($lt) < 0.001 && abs((float) $o->multicurrency_total_ttc - (float) $o->multicurrency_total_ht - (float) $o->multicurrency_total_tva) >= 0.001) {
                $out['mc'][] = (int) $o->rowid;
            }
        }
        $res = $this->db->query("SELECT " . implode(', ', $cols) . " FROM " . MAIN_DB_PREFIX . "facture WHERE rowid = " . $id);
        $h = $res ? $this->db->fetch_object($res) : null;
        if (!$h) {
            $out['error'] = $res ? 'invoice ' . $id . ' not found' : $this->db->lasterror();
            return $out;
        }
        foreach ($cols as $c) {
            if (abs((float) $h->$c - $sum[$c]) >= 0.001) {
                $out['header'][] = $c;
            }
        }
        return $out;
    }

    /**
     * @brief Stored NBP rate
     * @param string $currencyCode
     * @param int $referenceDate
     * @return array|false false when none
     */
    public function getCachedRateForDate($currencyCode, $referenceDate)
    {
        global $conf;
        $currencyCode = strtoupper(trim((string) $currencyCode));
        if ($currencyCode === '' || $currencyCode === 'PLN' || empty($referenceDate)) {
            return false;
        }
        $end = $this->referenceDayBefore($referenceDate);
        $from = date('Y-m-d', strtotime($end . ' -' . self::LOOKBACK_DAYS . ' days'));
        $sql = "SELECT k.table_day, k.nbp_table, k.table_no, k.mid, r.rate, r.date_sync"
            . " FROM " . MAIN_DB_PREFIX . "ksef_nbp_rate_row AS k"
            . " INNER JOIN " . MAIN_DB_PREFIX . "multicurrency_rate AS r ON r.rowid = k.fk_multicurrency_rate AND r.entity = k.entity"
            . " INNER JOIN " . MAIN_DB_PREFIX . "multicurrency AS m ON m.rowid = r.fk_multicurrency AND m.entity = k.entity"
            . " WHERE k.entity = " . ((int) $conf->entity) . " AND k.currency_code = '" . $this->db->escape($currencyCode) . "'"
            . " AND m.code = k.currency_code AND k.table_day >= '" . $this->db->escape($from) . "' AND k.table_day <= '" . $this->db->escape($end) . "'"
            . " ORDER BY k.table_day DESC";
        $res = $this->db->query($sql);
        if (!$res) {
            dol_syslog("KsefNbpCurrencyRate::getCachedRateForDate " . $this->db->lasterror(), LOG_WARNING);
            return false;
        }
        $best = null;
        while ($o = $this->db->fetch_object($res)) {
            $day = substr((string) $o->table_day, 0, 10);
            $mid = (float) $o->mid;
            if (!ksefIsWorkingDay($day) || $mid <= 0 || $this->db->jdate($o->date_sync) !== strtotime($day) || abs((float) $o->rate * $mid - 1) > 1e-7) {
                continue;
            }
            $best = array('d' => $day, 'm' => $mid, 't' => strtoupper((string) $o->nbp_table), 'n' => (string) $o->table_no);
            break;
        }
        $this->db->free($res);
        if ($best === null) {
            return false;
        }
        $table = $best['t'] !== '' ? $best['t'] : (in_array($currencyCode, self::TABLE_A_CURRENCIES, true) ? 'A' : 'B');
        $gap = (int) round((strtotime($end) - strtotime($best['d'])) / 86400);
        if ($gap > ($table === 'A' ? self::LOOKBACK_DAYS : 4)) {
            return false;
        }
        for ($i = 1; $table === 'A' && $i <= $gap; $i++) {
            if (ksefIsWorkingDay(strtotime($best['d'] . ' +' . $i . ' days'))) {
                return false;
            }
        }
        return array('date' => $best['d'], 'rate' => $best['m'], 'table' => $table, 'currency' => $currencyCode, 'no' => $best['n'], 'cached' => true);
    }

    /**
     * @brief Rate row record
     * @param string $currencyCode
     * @param string $day YYYY-MM-DD
     * @return array|null null when none
     */
    public function rateRecord($currencyCode, $day)
    {
        global $conf;
        $res = $this->db->query("SELECT fk_multicurrency_rate, mid, nbp_table, table_no, date_fetch FROM " . MAIN_DB_PREFIX . "ksef_nbp_rate_row"
            . " WHERE entity = " . ((int) $conf->entity) . " AND currency_code = '" . $this->db->escape(strtoupper(trim((string) $currencyCode))) . "'"
            . " AND table_day = '" . $this->db->escape((string) $day) . "'");
        $o = $res ? $this->db->fetch_object($res) : null;
        if (!$o) {
            return null;
        }
        return array('id' => (int) $o->fk_multicurrency_rate, 'mid' => (float) $o->mid, 'table' => (string) $o->nbp_table, 'no' => (string) $o->table_no, 'fetched' => (int) $o->date_fetch);
    }

    /**
     * @brief Record rate row
     * @param string $currencyCode
     * @param string $day YYYY-MM-DD
     * @param int $rowid 0 to forget
     * @param float $mid
     * @param string $table 'A', 'B' or ''
     * @param string $no Table number
     * @param int $fetched 0: now
     * @return bool
     */
    protected function rememberRate($currencyCode, $day, $rowid, $mid = 0.0, $table = '', $no = '', $fetched = 0)
    {
        global $conf;
        $where = "entity = " . ((int) $conf->entity) . " AND currency_code = '" . $this->db->escape($currencyCode) . "' AND table_day = '" . $this->db->escape($day) . "'";
        if ($rowid > 0) {
            $where = "(" . $where . ") OR fk_multicurrency_rate = " . ((int) $rowid);
        }
        $ok = (bool) $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "ksef_nbp_rate_row WHERE " . $where);
        if ($ok && $rowid > 0) {
            $ok = (bool) $this->db->query("INSERT INTO " . MAIN_DB_PREFIX . "ksef_nbp_rate_row (entity, fk_multicurrency_rate, currency_code, table_day, nbp_table, table_no, mid, date_fetch)"
                . " VALUES (" . ((int) $conf->entity) . ", " . ((int) $rowid) . ", '" . $this->db->escape($currencyCode) . "', '" . $this->db->escape($day) . "', "
                . ($table !== '' ? "'" . $this->db->escape(substr(strtoupper((string) $table), 0, 1)) . "'" : "NULL") . ", "
                . ($no !== '' ? "'" . $this->db->escape(dol_trunc((string) $no, 32, 'right', 'UTF-8', 1)) . "'" : "NULL") . ", "
                . sprintf('%.8F', (float) $mid) . ", " . ((int) ($fetched > 0 ? $fetched : dol_now())) . ")");
        }
        if (!$ok) {
            dol_syslog("KsefNbpCurrencyRate::rememberRate " . $currencyCode . " " . $day . ": " . $this->db->lasterror(), LOG_WARNING);
        }
        return $ok;
    }

    /**
     * @brief Forget rate row
     * @param int $rateId
     * @param bool $deleted
     * @return int -1 on error
     */
    public function forgetRateRow($rateId, $deleted)
    {
        $res = $this->db->query("SELECT k.rowid, k.table_day, k.mid, r.rate, r.date_sync FROM " . MAIN_DB_PREFIX . "ksef_nbp_rate_row AS k"
            . " LEFT JOIN " . MAIN_DB_PREFIX . "multicurrency_rate AS r ON r.rowid = k.fk_multicurrency_rate WHERE k.fk_multicurrency_rate = " . ((int) $rateId));
        if (!$res) {
            return -1;
        }
        $o = $this->db->fetch_object($res);
        if (!$o) {
            return 0;
        }
        $day = substr((string) $o->table_day, 0, 10);
        if (!$deleted && $o->rate !== null && (float) $o->mid > 0 && abs((float) $o->rate * (float) $o->mid - 1) <= 1e-7 && $this->db->jdate($o->date_sync) === strtotime($day)) {
            return 0;
        }
        return $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "ksef_nbp_rate_row WHERE rowid = " . ((int) $o->rowid)) ? 0 : -1;
    }

    /**
     * @brief Drop orphan records
     * @return int -1 on error
     */
    public function pruneRateRecords()
    {
        $sql = "DELETE FROM " . MAIN_DB_PREFIX . "ksef_nbp_rate_row WHERE NOT EXISTS (SELECT 1 FROM " . MAIN_DB_PREFIX . "multicurrency_rate AS r"
            . " WHERE r.rowid = " . MAIN_DB_PREFIX . "ksef_nbp_rate_row.fk_multicurrency_rate)";
        $res = $this->db->query($sql);
        if (!$res) {
            dol_syslog("KsefNbpCurrencyRate::pruneRateRecords " . $this->db->lasterror(), LOG_WARNING);
            return -1;
        }
        return (int) $this->db->affected_rows($res);
    }

    /**
     * @brief Migrate rate rows
     * @param DoliDB $db
     * @return array
     */
    public static function migrateRateRowsConst($db)
    {
        global $conf;
        $out = array('moved' => 0, 'skipped' => 0, 'error' => '');
        $res = $db->query("SELECT entity, value FROM " . MAIN_DB_PREFIX . "const WHERE name = 'KSEF_NBP_RATE_ROWS'");
        if (!$res) {
            $out['error'] = $db->lasterror();
            return $out;
        }
        $consts = array();
        while ($o = $db->fetch_object($res)) {
            $consts[(int) $o->entity] = (string) $o->value;
        }
        if (empty($consts)) {
            return $out;
        }
        $saved = $conf->entity;
        $nbp = new self($db);
        foreach ($consts as $entity => $json) {
            $conf->entity = $entity;
            $rec = json_decode($json, true);
            foreach (is_array($rec) ? $rec : array() as $e) {
                $code = strtoupper(trim((string) ($e['c'] ?? '')));
                $day = (string) ($e['d'] ?? '');
                if (!is_array($e) || (int) ($e['id'] ?? 0) <= 0 || (float) ($e['m'] ?? 0) <= 0 || !preg_match('/^[A-Z]{3}$/', $code) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
                    $out['skipped']++;
                    continue;
                }
                if (!$nbp->rememberRate($code, $day, (int) $e['id'], (float) $e['m'], (string) ($e['t'] ?? ''), (string) ($e['n'] ?? ''))) {
                    $out['error'] = $db->lasterror();
                    $conf->entity = $saved;
                    return $out;
                }
                $out['moved']++;
            }
            require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
            if (dolibarr_del_const($db, 'KSEF_NBP_RATE_ROWS', $entity) < 0) {
                $out['error'] = $db->lasterror();
                break;
            }
        }
        $conf->entity = $saved;
        return $out;
    }

    /**
     * @brief Rate, stored first
     * @param string $currencyCode
     * @param int $referenceDate
     * @return array|false
     */
    public function getRateForDateCached($currencyCode, $referenceDate)
    {
        global $user;
        $cached = $this->getCachedRateForDate($currencyCode, $referenceDate);
        if ($cached) {
            dol_syslog("KsefNbpCurrencyRate::getRateForDateCached " . $currencyCode . " " . $cached['date'] . " = " . $cached['rate'] . " from the stored rates", LOG_DEBUG);
            return $cached;
        }
        $r = $this->getRateForDate($currencyCode, $referenceDate);
        if ($r && is_object($user) && (float) $r['rate'] > 0) {
            $action = null;
            $this->addRateToCache(strtoupper(trim((string) $currencyCode)), 1 / (float) $r['rate'], $r['date'], $user, $action, (string) ($r['table'] ?? ''), (string) ($r['no'] ?? ''));
        }
        return $r;
    }

    /**
     * @brief Store rate row
     * @param string $currencyCode
     * @param float $rate Foreign per base
     * @param string $date YYYY-MM-DD
     * @param User $user
     * @param string $action Out: outcome
     * @param string $table 'A', 'B' or ''
     * @param string $no Table number
     * @return bool
     */
    protected function addRateToCache($currencyCode, $rate, $date, $user, &$action = null, $table = '', $no = '')
    {
        global $conf;

        require_once DOL_DOCUMENT_ROOT . '/multicurrency/class/multicurrency.class.php';
        $fk_multicurrency = MultiCurrency::getIdFromCode($this->db, $currencyCode);

        if ($fk_multicurrency <= 0 || (float) $rate <= 0) {
            dol_syslog("KsefNbpCurrencyRate::addRateToCache Currency $currencyCode not found in multicurrency table", LOG_DEBUG);
            $action = 'failed';
            return false;
        }
        $mid = 1 / (float) $rate;
        $table = $table !== '' ? strtoupper($table) : (in_array($currencyCode, self::TABLE_A_CURRENCIES, true) ? 'A' : '');
        $sql = "SELECT rowid, rate FROM " . MAIN_DB_PREFIX . "multicurrency_rate";
        $sql .= " WHERE fk_multicurrency = " . ((int) $fk_multicurrency);
        $sql .= " AND date_sync = '" . $this->db->idate(strtotime($date)) . "'";
        $sql .= " AND entity = " . ((int) $conf->entity);
        $sql .= " ORDER BY rowid DESC";

        $resql = $this->db->query($sql);
        $other = false;
        while ($resql && ($o = $this->db->fetch_object($resql))) {
            if (abs((float) $o->rate * $mid - 1) <= 1e-7) {
                dol_syslog("KsefNbpCurrencyRate::addRateToCache Rate for $currencyCode on $date already exists (id=" . (int) $o->rowid . ")", LOG_DEBUG);
                $this->rememberRate($currencyCode, $date, (int) $o->rowid, $mid, $table, $no);
                $action = 'unchanged';
                return true;
            }
            $other = true;
        }
        if ($other) {
            dol_syslog("KsefNbpCurrencyRate::addRateToCache $currencyCode on $date: a rate other than NBP's is set for that day, left as is", LOG_INFO);
            $this->rememberRate($currencyCode, $date, 0);
            $action = 'unchanged';
            return true;
        }

        $currencyRate = new CurrencyRate($this->db);
        $currencyRate->rate = (float) $rate;
        $currencyRate->date_sync = strtotime($date);
        $currencyRate->entity = $conf->entity;

        $result = $currencyRate->create($user, $fk_multicurrency, 1);

        if ($result > 0) {
            dol_syslog("KsefNbpCurrencyRate::addRateToCache Added rate $rate for $currencyCode on $date (id=$result)", LOG_INFO);
            $this->rememberRate($currencyCode, $date, (int) $result, $mid, $table, $no);
            $action = 'created';
            return true;
        }

        dol_syslog("KsefNbpCurrencyRate::addRateToCache Failed to add rate: " . implode(',', $currencyRate->errors), LOG_WARNING);
        $action = 'failed';
        return false;
    }

    /**
     * @brief Store NBP rate
     * @param string $currencyCode
     * @param array $rateData getRateForDate() result
     * @param User $user
     * @return bool
     */
    public function storeNbpRate($currencyCode, $rateData, $user)
    {
        $code = strtoupper(trim((string) $currencyCode));
        if (empty($rateData['rate']) || (float) $rateData['rate'] <= 0) {
            return false;
        }
        $action = null;
        $ok = $this->addRateToCache($code, 1 / (float) $rateData['rate'], (string) $rateData['date'], $user, $action, (string) ($rateData['table'] ?? ''), (string) ($rateData['no'] ?? ''));
        if ($ok) {
            $this->supersedeCurrentRate($code, 1 / (float) $rateData['rate'], (string) $rateData['date'], $user);
        }
        return $ok;
    }

    /**
     * @brief Supersede typed rate
     * @param string $currencyCode
     * @param float $rate Foreign per base
     * @param string $date NBP table day
     * @param User $user
     * @return bool True when replaced
     */
    protected function supersedeCurrentRate($currencyCode, $rate, $date, $user)
    {
        global $conf;
        require_once DOL_DOCUMENT_ROOT . '/multicurrency/class/multicurrency.class.php';
        $fk = MultiCurrency::getIdFromCode($this->db, $currencyCode);
        if ($fk <= 0 || (float) $rate <= 0 || !is_object($user)) {
            return false;
        }
        $P = MAIN_DB_PREFIX;
        $o = $this->db->getRow("SELECT r.rowid, r.rate, r.date_sync, k.rowid AS known FROM " . $P . "multicurrency_rate AS r"
            . " LEFT JOIN " . $P . "ksef_nbp_rate_row AS k ON k.fk_multicurrency_rate = r.rowid"
            . " WHERE r.fk_multicurrency = " . ((int) $fk) . " AND r.entity = " . ((int) $conf->entity) . " ORDER BY r.date_sync DESC, r.rowid DESC");
        if (!$o || !empty($o->known) || abs((float) $o->rate / (float) $rate - 1) <= 1e-7) {
            return false;
        }
        $last = $this->db->getRow("SELECT MAX(table_day) AS d FROM " . $P . "ksef_nbp_rate_row WHERE entity = " . ((int) $conf->entity)
            . " AND currency_code = '" . $this->db->escape($currencyCode) . "'");
        if ($last && !empty($last->d) && substr((string) $last->d, 0, 10) > $date) {
            return false;
        }
        $cr = new CurrencyRate($this->db);
        $cr->rate = (float) $rate;
        $cr->date_sync = max(dol_now(), (int) $this->db->jdate($o->date_sync) + 1);
        $cr->entity = $conf->entity;
        $id = $cr->create($user, $fk, 1);
        dol_syslog("KsefNbpCurrencyRate::supersedeCurrentRate " . $currencyCode . ": current rate " . $o->rate . " (id " . (int) $o->rowid . ") is not NBP's, NBP " . $date
            . " rate " . $rate . " made current (id " . (int) $id . ")", $id > 0 ? LOG_INFO : LOG_WARNING);
        return $id > 0;
    }

    /**
     * @brief Cron: D-1 rates
     * @return int <0 on error
     */
    public function cronSyncMulticurrencyRates()
    {
        global $conf, $user;

        $this->error = '';
        $this->output = '';

        if (!isModEnabled('multicurrency')) {
            $this->output = 'Multicurrency module disabled, nothing to sync';
            return 0;
        }

        $this->pruneRateRecords();

        $baseCurrency = strtoupper(empty($conf->currency) ? '' : $conf->currency);

        if ($baseCurrency !== 'PLN') {
            $this->output = 'Base currency is ' . ($baseCurrency ?: 'unset') . ', not PLN; NBP D-1 sync only applies to a PLN base';
            return 0;
        }

        $sql = "SELECT code FROM " . MAIN_DB_PREFIX . "multicurrency";
        $sql .= " WHERE entity = " . ((int) $conf->entity);
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            $this->output = 'Failed to read multicurrency dictionary: ' . $this->error;
            return -1;
        }

        $endDate = $this->warsawDayBefore(dol_now());
        $startDate = date('Y-m-d', strtotime('-' . self::LOOKBACK_DAYS . ' days', strtotime($endDate)));

        $tableRates = array();
        $tableErrors = array();
        foreach (array('a', 'b') as $t) {
            $this->error = '';
            $res = $this->fetchTableRates($t, $startDate, $endDate);
            if ($res === false) {
                $tableErrors[strtoupper($t)] = $this->error;
                continue;
            }
            foreach ($res as $code => $info) {
                if (!isset($tableRates[$code])) {
                    $tableRates[$code] = $info;
                }
            }
        }

        if (empty($tableRates)) {
            $this->db->free($resql);
            $transient = array();
            foreach ($tableErrors as $tbl => $e) {
                if (strpos($e, '404') === false) {
                    $transient[] = $tbl . ': ' . $e;
                }
            }
            if (!empty($transient)) {
                $this->error = 'NBP table fetch failed: ' . implode(' | ', $transient);
                $this->output = $this->error;
                return -1;
            }
            $this->output = 'NBP rate sync (D-1): no tables published in range ' . $startDate . ' to ' . $endDate;
            dol_syslog("KsefNbpCurrencyRate::cronSyncMulticurrencyRates " . $this->output, LOG_INFO);
            return 0;
        }

        $subset = null;
        $subsetRaw = getDolGlobalString('KSEF_NBP_SYNC_CURRENCIES', '');
        if ($subsetRaw !== '') {
            $subset = array_filter(array_map('trim', explode(',', strtoupper($subsetRaw))));
        }

        $updated = 0;
        $skipped = 0;
        $failed = 0;
        $unpublished = 0;
        $failCodes = array();
        $unpublishedCodes = array();
        $superseded = array();

        while ($obj = $this->db->fetch_object($resql)) {
            $code = strtoupper(trim($obj->code));
            if ($code === '' || $code === 'PLN' || $code === $baseCurrency) {
                $skipped++;
                continue;
            }
            if ($subset !== null && !in_array($code, $subset)) {
                $skipped++;
                continue;
            }

            if (empty($tableRates[$code]) || (float) $tableRates[$code]['rate'] <= 0) {
                $unpublished++;
                $unpublishedCodes[] = $code;
                continue;
            }

            $dolibarrTx = 1 / (float) $tableRates[$code]['rate'];
            $action = null;
            $this->addRateToCache($code, $dolibarrTx, $tableRates[$code]['date'], $user, $action, (string) ($tableRates[$code]['table'] ?? ''), (string) ($tableRates[$code]['no'] ?? ''));
            if ($action !== 'failed' && $this->supersedeCurrentRate($code, $dolibarrTx, (string) $tableRates[$code]['date'], $user)) {
                $superseded[] = $code;
            }
            if ($action === 'created') {
                $updated++;
            } elseif ($action === 'unchanged') {
                $skipped++;
            } else {
                $failed++;
                $failCodes[] = $code;
            }
        }
        $this->db->free($resql);

        $this->output = "NBP rate sync (D-1): updated $updated, skipped $skipped, unpublished $unpublished, failed $failed";
        if (!empty($failCodes)) {
            $this->output .= ' [write failed: ' . implode(',', $failCodes) . ']';
        }
        if (!empty($unpublishedCodes)) {
            $this->output .= ' [not quoted: ' . implode(',', $unpublishedCodes) . ']';
        }
        if (!empty($superseded)) {
            $this->output .= ' [typed current rate replaced by NBP: ' . implode(',', $superseded) . ']';
        }
        $typed = ksefTypedRateCron($this->db);
        if ($typed['checked'] > 0) {
            $this->output .= '; rates set by the user checked ' . $typed['checked'] . ', not matching NBP ' . count($typed['differs'])
                . ($typed['differs'] ? ' [' . implode(',', $typed['differs']) . ']' : '') . ', not checked ' . $typed['unverified'];
        }
        dol_syslog("KsefNbpCurrencyRate::cronSyncMulticurrencyRates " . $this->output, LOG_INFO);

        if ($updated === 0 && $skipped === 0 && $failed > 0) {
            $this->error = 'NBP rate sync failed to write all ' . $failed . ' currency(ies): ' . implode(',', $failCodes);
            return -1;
        }

        return 0;
    }

    /**
     * @brief Invoice currency
     * @param Facture $invoice
     * @return string
     */
    public function getInvoiceCurrency($invoice)
    {
        if (!empty($invoice->multicurrency_code)) {
            return $invoice->multicurrency_code;
        }

        global $conf;
        return !empty($conf->currency) ? $conf->currency : 'EUR';
    }

    /**
     * @brief Needs NBP rate
     * @param Facture $invoice
     * @return bool
     */
    public function invoiceNeedsNBPRate($invoice)
    {
        $currency = $this->getInvoiceCurrency($invoice);
        return ($currency !== 'PLN');
    }

    /**
     * @brief Has NBP rate
     * @param Facture $invoice
     * @return bool
     */
    public function invoiceHasNBPRate($invoice)
    {
        if (!$this->invoiceNeedsNBPRate($invoice)) {
            return true;
        }

        if (!isset($invoice->array_options)) {
            $invoice->fetch_optionals();
        }

        $hasDate = !empty($invoice->array_options['options_ksef_kurs_data']);
        $hasRate = !empty($invoice->multicurrency_tx) && $invoice->multicurrency_tx > 0;

        return ($hasDate && $hasRate);
    }

    /**
     * @brief NBP reference date
     * @param Facture $invoice
     * @param int $depth Recursion guard
     * @param bool $exact Ignore source table
     * @param array $over 'sale', 'paid' overrides
     * @return int 0 when none
     */
    public function getReferenceDate($invoice, $depth = 0, $exact = false, $over = array())
    {
        if (empty($invoice->array_options)) {
            $invoice->fetch_optionals();
        }
        $saleOver = (int) ($over['sale'] ?? 0);
        $paidOver = (int) ($over['paid'] ?? 0);
        if ($saleOver > 0 || $paidOver > 0) {
            $depth = 5;
        }
        if ($depth < 5 && !empty($invoice->fk_facture_source)
            && in_array((int) $invoice->type, array(Facture::TYPE_CREDIT_NOTE, Facture::TYPE_REPLACEMENT), true)) {
            $src = new Facture($this->db);
            if ($src->fetch((int) $invoice->fk_facture_source) > 0) {
                $src->fetch_optionals();
                $kurs = $src->array_options['options_ksef_kurs_data'] ?? '';
                $kursTs = is_numeric($kurs) ? (int) $kurs : (int) strtotime((string) $kurs);
                $srcRef = $this->getReferenceDate($src, $depth + 1, $exact);
                // art. 31a(3)
                if (!$exact && $kursTs > 0 && !$this->storedTableFits($kursTs, $srcRef, $this->getInvoiceCurrency($src))) {
                    // not dol_time_plus_duree(): DST
                    return (int) strtotime(date('Y-m-d', $kursTs) . ' +1 day');
                }
                return $srcRef;
            }
        }
        $issue = (int) $invoice->date;
        $isDeposit = ((int) $invoice->type === Facture::TYPE_DEPOSIT);
        $wdt = ksefInvoiceIsWdtOnly($this->db, $invoice);
        $paidDay = 0;
        if (($isDeposit && !$wdt) || (!$isDeposit && getDolGlobalString('KSEF_NBP_RATE_DATE_METHOD', 'vat') === 'income_tax')) {
            $paidDay = $paidOver > 0 ? $paidOver : ksefDepositPaymentDate($this->db, $invoice);
            $paidDay = $paidDay > 0 ? (int) strtotime(ksefMomentDay($paidDay)) : 0;
        }
        $saleDate = $saleOver > 0 ? $saleOver : ksefGetSaleDate($invoice);
        if ($isDeposit) {
            // art. 19a(8)
            $tax = $wdt ? $issue : $paidDay;
        } elseif (getDolGlobalString('KSEF_NBP_RATE_DATE_METHOD', 'vat') === 'income_tax') {
            $tax = $saleDate;
            $paid = $paidDay;
            if ($paid > 0 && ($tax <= 0 || $paid < $tax)) {
                $tax = $paid;
            }
        } elseif ($wdt) {
            // art. 20(1)
            $tax = ksefWdtTaxPointCap($saleDate);
        } else {
            $tax = $saleDate;
        }
        if (empty($issue)) {
            return (int) $tax;
        }
        return ($tax > 0 && $tax < $issue) ? (int) $tax : $issue;
    }

    /**
     * @brief Stored table fits
     * @param int $kursTs Stored table date
     * @param int $ref Reference date
     * @param string $currency
     * @return bool
     */
    private function storedTableFits($kursTs, $ref, $currency)
    {
        $stored = dol_print_date($kursTs, '%Y-%m-%d', 'tzserver');
        $gapDays = (int) round((strtotime(dol_print_date($ref, '%Y-%m-%d', 'tzserver')) - strtotime($stored)) / 86400);
        if ($gapDays < 1 || $gapDays > self::STALE_MAX_GAP_DAYS) {
            return false;
        }
        if (!in_array(strtoupper((string) $currency), self::TABLE_A_CURRENCIES, true)) {
            return true;
        }
        for ($i = 1; $i < $gapDays; $i++) {
            if (ksefIsWorkingDay(strtotime($stored . ' +' . $i . ' days'))) {
                return false;
            }
        }
        return true;
    }

    /**
     * @brief Check stored rate
     * @param Facture $invoice
     * @return array
     */
    public function checkStoredRate($invoice)
    {
        $out = array('stale' => false, 'stored' => '', 'reference' => '', 'strict' => false, 'stored_ts' => 0, 'reference_ts' => 0,
            'stored_label' => '', 'reference_label' => '');
        if (!$this->invoiceNeedsNBPRate($invoice) || !$this->invoiceHasNBPRate($invoice)) {
            return $out;
        }
        if (in_array((int) $invoice->type, array(Facture::TYPE_CREDIT_NOTE, Facture::TYPE_REPLACEMENT), true)) {
            return $out;
        }
        dol_include_once('/ksef/lib/ksef.lib.php');
        if ((int) $invoice->type === Facture::TYPE_DEPOSIT && ksefDepositPaymentParts($this->db, $invoice, array('no_rates' => true))) {
            return $out;
        }
        $kurs = $invoice->array_options['options_ksef_kurs_data'];
        $kursTs = is_numeric($kurs) ? (int) $kurs : (int) strtotime((string) $kurs);
        $ref = $this->getReferenceDate($invoice);
        if (empty($kursTs) || empty($ref)) {
            return $out;
        }
        $out['stored'] = dol_print_date($kursTs, '%Y-%m-%d', 'tzserver');
        $out['reference'] = dol_print_date($ref, '%Y-%m-%d', 'tzserver');
        $out['stored_ts'] = $kursTs;
        $out['reference_ts'] = (int) $ref;
        $out['stored_label'] = dol_print_date($kursTs, 'day');
        $out['reference_label'] = dol_print_date($ref, 'day');
        $out['stale'] = !$this->storedTableFits($kursTs, $ref, $this->getInvoiceCurrency($invoice));
        $since = getDolGlobalInt('KSEF_NBP_STALE_STRICT_SINCE');
        $validated = !empty($invoice->date_validation) ? (int) $invoice->date_validation : 0;
        $out['strict'] = $out['stale'] && ($since <= 0 || $validated === 0
            || dol_print_date($validated, '%Y-%m-%d') >= dol_print_date($since, '%Y-%m-%d'));
        return $out;
    }

    /**
     * @brief Correct-table rate
     * @param Facture $invoice
     * @return array|false false when none
     */
    public function correctionRateChoice($invoice)
    {
        if ((int) $invoice->type !== Facture::TYPE_REPLACEMENT || empty($invoice->fk_facture_source) || !$this->invoiceNeedsNBPRate($invoice)) {
            return false;
        }
        dol_include_once('/ksef/lib/ksef.lib.php');
        if (ksefDepositRootId($this->db, (int) $invoice->id) > 0) {
            return false;
        }
        $exactRef = $this->getReferenceDate($invoice, 0, true);
        if (empty($exactRef) || dol_print_date($exactRef, '%Y-%m-%d', 'tzserver') === dol_print_date($this->getReferenceDate($invoice), '%Y-%m-%d', 'tzserver')) {
            return false;
        }
        return $this->getRateForDate($this->getInvoiceCurrency($invoice), $exactRef);
    }

    /**
     * @brief Classify correction rate
     * @param Facture $invoice
     * @return array
     */
    public function checkCorrectionRate($invoice)
    {
        $out = array('state' => '', 'rate' => 0.0, 'source_rate' => 0.0, 'source_ref' => '', 'source_day' => 0, 'exact' => false, 'choice' => false,
            'credit_note' => ((int) $invoice->type === Facture::TYPE_CREDIT_NOTE));
        if (!in_array((int) $invoice->type, array(Facture::TYPE_REPLACEMENT, Facture::TYPE_CREDIT_NOTE), true) || empty($invoice->fk_facture_source) || !$this->invoiceNeedsNBPRate($invoice)
            || (float) $invoice->multicurrency_tx <= 0) {
            return $out;
        }
        dol_include_once('/ksef/lib/ksef.lib.php');
        if (ksefDepositRootId($this->db, (int) $invoice->id) > 0) {
            return $out;
        }
        if (!isset($invoice->array_options) || !is_array($invoice->array_options)) {
            $invoice->fetch_optionals();
        }
        if (!empty($invoice->array_options['options_ksef_number'])) {
            return $out;
        }
        $src = new Facture($this->db);
        if ($src->fetch((int) $invoice->fk_facture_source) <= 0 || (float) $src->multicurrency_tx <= 0
            || $this->getInvoiceCurrency($src) !== $this->getInvoiceCurrency($invoice)) {
            return $out;
        }
        $src->fetch_optionals();
        $kurs = $src->array_options['options_ksef_kurs_data'] ?? '';
        $out['source_day'] = is_numeric($kurs) ? (int) $kurs : (int) strtotime((string) $kurs);
        $out['rate'] = 1 / (float) $invoice->multicurrency_tx;
        $out['source_rate'] = 1 / (float) $src->multicurrency_tx;
        $out['source_ref'] = $src->ref;
        // builder: 6 decimals
        if (number_format($out['rate'], 6, '.', '') === number_format($out['source_rate'], 6, '.', '')) {
            $out['state'] = 'source';
            return $out;
        }
        $exactRef = $this->getReferenceDate($invoice, 0, true);
        $out['exact'] = $exactRef ? $this->getRateForDateCached($this->getInvoiceCurrency($invoice), $exactRef) : false;
        if (!$out['exact']) {
            $out['state'] = 'unknown';
            return $out;
        }
        $out['choice'] = !$out['credit_note'] && dol_print_date($exactRef, '%Y-%m-%d', 'tzserver') !== dol_print_date($this->getReferenceDate($invoice), '%Y-%m-%d', 'tzserver');
        $out['state'] = number_format($out['rate'], 4, '.', '') === number_format((float) $out['exact']['rate'], 4, '.', '') ? 'exact' : 'other';
        return $out;
    }

    /**
     * @brief Correction rate warning
     * @param array $rc checkCorrectionRate() result
     * @param bool $validated Not a draft
     * @return string
     */
    public function correctionRateWarning($rc, $validated)
    {
        global $langs;
        $langs->load('ksef@ksef');
        $fmt = function ($r) {
            return number_format((float) $r, 4, ',', '');
        };
        require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
        $note = !empty($rc['credit_note']);
        $txt = $langs->transnoentitiesnoconv($note ? 'KSEF_NBPRateCreditNoteTyped' : 'KSEF_NBPRateCorrectionTyped', $fmt($rc['rate']), $rc['source_ref'], $fmt($rc['source_rate']),
            $fmt($rc['exact']['rate']) . ' (' . dol_print_date(dol_stringtotime((string) $rc['exact']['date']), 'day') . ')');
        $txt .= ' ' . $langs->transnoentitiesnoconv(!empty($rc['choice']) ? 'KSEF_NBPRateTypedHintBoth' : 'KSEF_NBPRateTypedHintKeep');
        return $txt . ($validated ? ' ' . $langs->transnoentitiesnoconv($note ? 'KSEF_NBPRateCreditNoteTypedDraftHint' : 'KSEF_NBPRateCorrectionTypedDraftHint') : '');
    }

    /**
     * @brief Rate display info
     * @param Facture $invoice
     * @return array
     */
    public function getFormattedRateInfo($invoice)
    {
        if (!$this->invoiceNeedsNBPRate($invoice)) {
            return array();
        }

        if (!isset($invoice->array_options)) {
            $invoice->fetch_optionals();
        }

        $rateDate = $invoice->array_options['options_ksef_kurs_data'] ?? null;
        $rate = $invoice->multicurrency_tx ?? 0;

        if (empty($rateDate) || $rate <= 0) {
            return array();
        }

        $dateFormatted = is_numeric($rateDate) ? date('Y-m-d', $rateDate) : $rateDate;
        $rateFormatted = rtrim(rtrim(number_format($rate, 6, '.', ''), '0'), '.');
        $dotPos = strpos($rateFormatted, '.');
        if ($dotPos !== false) {
            $decimals = strlen($rateFormatted) - $dotPos - 1;
            if ($decimals < 4) {
                $rateFormatted = number_format($rate, 4, '.', '');
            }
        }

        return array(
            'rate' => $rateFormatted,
            'date' => $dateFormatted,
            'formatted' => $rateFormatted . ' z dnia ' . $dateFormatted,
            'currency' => $this->getInvoiceCurrency($invoice),
        );
    }
}