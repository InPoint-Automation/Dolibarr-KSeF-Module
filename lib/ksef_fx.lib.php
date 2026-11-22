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
 * \file    ksef/lib/ksef_fx.lib.php
 * \ingroup ksef
 * \brief   Exchange rate helpers
 */

/**
 * @brief Invoice currency
 * @param Facture $invoice
 * @return string
 */
function ksefGetInvoiceCurrency($invoice)
{
    if (!empty($invoice->multicurrency_code)) {
        return $invoice->multicurrency_code;
    }

    global $conf;
    return !empty($conf->currency) ? $conf->currency : 'EUR';
}

/**
 * @brief Normalize currency code
 * @param string|null $code
 * @return string
 */
function ksefNormCurrency($code)
{
    global $conf;
    $code = trim((string) $code);
    return $code !== '' ? $code : (!empty($conf->currency) ? $conf->currency : 'EUR');
}

/**
 * @brief Invoice is foreign
 * @param Facture|FactureFournisseur $facture
 * @return bool
 */
function ksefInvoiceIsForeign($facture)
{
    global $conf;
    return !empty($facture->multicurrency_code) && $facture->multicurrency_code !== $conf->currency;
}

/**
 * @brief Sync multicurrency totals
 * @param DoliDB $db
 * @param CommonInvoice $facture
 * @return int 1 updated, 0 skipped, -1 error
 */
function ksefSyncBaseMulticurrencyTotals($db, $facture)
{
    global $conf;
    $code = !empty($facture->multicurrency_code) ? $facture->multicurrency_code : $conf->currency;
    if ($code !== $conf->currency || (!empty($facture->multicurrency_tx) && (float) $facture->multicurrency_tx != 1.0)) {
        return 0;
    }
    if ($facture->element === 'invoice_supplier') {
        $head = 'facture_fourn';
        $lines = 'facture_fourn_det';
        $fk = 'fk_facture_fourn';
        $lineVat = 'tva';
    } else {
        $head = 'facture';
        $lines = 'facturedet';
        $fk = 'fk_facture';
        $lineVat = 'total_tva';
    }
    $id = (int) $facture->id;
    $ok = $db->query("UPDATE " . MAIN_DB_PREFIX . $lines . " SET multicurrency_total_ht = total_ht, multicurrency_total_tva = " . $lineVat . ", multicurrency_total_ttc = total_ttc WHERE " . $fk . " = " . $id);
    $ok = $ok && $db->query("UPDATE " . MAIN_DB_PREFIX . $head . " SET multicurrency_total_ht = total_ht, multicurrency_total_tva = total_tva, multicurrency_total_ttc = total_ttc WHERE rowid = " . $id);
    return $ok ? 1 : -1;
}

/**
 * @brief Method switch warning
 * @param string $newMethod vat or income_tax
 * @param int $now
 * @return string
 */
function ksefNbpRateMethodSwitchWarning($newMethod, $now = null)
{
    global $langs;
    $current = getDolGlobalString('KSEF_NBP_RATE_DATE_METHOD', 'vat');
    if ($newMethod === $current) {
        return '';
    }
    require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
    $since = getDolGlobalInt('KSEF_NBP_RATE_DATE_METHOD_SINCE');
    $now = $now === null ? dol_now() : (int) $now;
    if ($since <= 0 || dol_time_plus_duree($since, 12, 'm') <= $now) {
        return '';
    }
    $langs->load('ksef@ksef');
    $key = $current === 'income_tax' ? 'KSEF_NBP_RATE_DATE_METHOD_EarlyLeave' : 'KSEF_NBP_RATE_DATE_METHOD_EarlyReturn';
    return $langs->trans($key, dol_print_date($since, 'day'), dol_print_date(dol_time_plus_duree($since, 12, 'm'), 'day'));
}

/**
 * @brief Invoice rate K
 * @param Facture|object $invoice
 * @param array $opt db, root_id, own
 * @return float 0.0 if none
 */
function ksefInvoiceKurs($invoice, $opt = array())
{
    global $conf;
    $db = !empty($opt['db']) ? $opt['db'] : ((is_object($invoice) && !empty($invoice->db) && is_object($invoice->db)) ? $invoice->db : $GLOBALS['db']);
    $code = strtoupper(trim((string) ($invoice->multicurrency_code ?? '')));
    $tx = (float) ($invoice->multicurrency_tx ?? 0);
    if ($code === '' || $code === $conf->currency || $tx <= 0 || abs($tx - 1) < 1e-12) {
        return 0.0;
    }
    if (!class_exists('Facture')) {
        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
    }
    $type = (int) ($invoice->type ?? 0);
    $sourceId = (int) ($invoice->fk_facture_source ?? 0);
    if (empty($opt['own']) && $sourceId > 0 && ($type === Facture::TYPE_REPLACEMENT || $type === Facture::TYPE_CREDIT_NOTE)) {
        $rootId = (int) ($opt['root_id'] ?? 0);
        if ($rootId <= 0) {
            $root = ksefCorrectionRoot($db, $invoice);
            $rootId = ($root && (int) $root->type === Facture::TYPE_DEPOSIT) ? (int) $root->id : 0;
        }
        if ($rootId > 0) {
            $root = new Facture($db);
            if ($root->fetch($rootId) > 0 && (float) $root->multicurrency_tx > 0) {
                return ksefInvoiceKurs($root, array('db' => $db, 'own' => true));
            }
        }
        // art. 31b(1)
        if ($type === Facture::TYPE_REPLACEMENT) {
            $src = new Facture($db);
            if ($src->fetch($sourceId) > 0 && abs((float) $src->multicurrency_tx - $tx) <= 1e-8) {
                return ksefInvoiceKurs($src, array('db' => $db));
            }
        }
    }
    // tx=round(1/mid, 8)
    $fits = function ($mid) use ($tx) {
        return $mid > 0 && abs($tx - 1 / $mid) <= 6e-9;
    };
    $id = (int) ($invoice->id ?? ($invoice->rowid ?? 0));
    if ($id > 0) {
        $res = $db->query("SELECT k.mid FROM " . MAIN_DB_PREFIX . "facture_extrafields AS e"
            . " INNER JOIN " . MAIN_DB_PREFIX . "ksef_nbp_rate_row AS k ON k.entity = " . ((int) $conf->entity) . " AND k.currency_code = '" . $db->escape($code) . "'"
            . " AND k.table_day = e.ksef_kurs_data"
            . " WHERE e.fk_object = " . $id . " AND e.ksef_kurs_data IS NOT NULL");
        $o = $res ? $db->fetch_object($res) : null;
        if ($o && $fits((float) $o->mid)) {
            return (float) $o->mid;
        }
    }
    $inv = 1 / $tx;
    $tol = $inv * $inv * 6e-9 + 1e-9;
    $res = $db->query("SELECT mid FROM " . MAIN_DB_PREFIX . "ksef_nbp_rate_row WHERE entity = " . ((int) $conf->entity) . " AND currency_code = '" . $db->escape($code) . "'"
        . " AND mid >= " . sprintf('%.10F', $inv - $tol) . " AND mid <= " . sprintf('%.10F', $inv + $tol) . " ORDER BY table_day DESC");
    while ($res && ($o = $db->fetch_object($res))) {
        if ($fits((float) $o->mid)) {
            return (float) $o->mid;
        }
    }
    return round($inv, 6);
}

/**
 * @brief Line rate key
 * @param object $line
 * @param string $country Buyer country
 * @param bool $isEu
 * @return string
 */
function ksefForeignRateKey($line, $country, $isEu)
{
    if (ksefMarginSubtype($line) !== '') {
        return 'marza';
    }
    $rate = (float) ($line->tva_tx ?? 0);
    $int = (int) round($rate);
    $k = ksefPolishRateKey($rate);
    if ($k !== '') {
        return $k;
    }
    if ($int === 0) {
        return ksefZeroRateKey($line->vat_src_code ?? '', (string) $country, (bool) $isEu, (int) ($line->product_type ?? 0) === 1);
    }
    return $rate > 0 ? (string) $int : 'np I';
}

/**
 * @brief Polish rate key
 * @param float $rate
 * @param string $else Non-Polish value
 * @return string
 */
function ksefPolishRateKey($rate, $else = '')
{
    $int = (int) round((float) $rate);
    return in_array($int, array(23, 22, 8, 7, 5, 4, 3), true) ? (string) $int : $else;
}

/**
 * @brief Skip in PLN
 * @param object $l
 * @return bool
 */
function ksefFxSkipLine($l)
{
    return ((int) $l->info_bits & 1) || (int) $l->product_type === 9;
}

/**
 * @brief Rate key bucket
 * @param string $key
 * @return string
 */
function ksefForeignRateBucket($key)
{
    $map = array('23' => '1', '22' => '1', '8' => '2', '7' => '2', '5' => '3', '4' => '4', '3' => '4');
    return isset($map[(string) $key]) ? $map[(string) $key] : (string) $key;
}

/**
 * @brief Rate key summary
 * @param array $lines
 * @param array $opt
 * @return array kurs, keys, buckets
 */
function ksefForeignRateSummary($lines, $opt = array())
{
    $kurs = round((float) ($opt['kurs'] ?? 0), 6);
    $pln = !empty($opt['pln']);
    $country = (string) ($opt['country'] ?? 'PL');
    $isEu = array_key_exists('eu', $opt) ? (bool) $opt['eu'] : ksefIsEuCountry($country);
    $keyOf = isset($opt['key']) && is_callable($opt['key']) ? $opt['key'] : function ($l) use ($country, $isEu) {
        return ksefForeignRateKey($l, $country, $isEu);
    };
    $out = array('kurs' => $kurs, 'keys' => array(), 'buckets' => array());
    foreach ((array) $lines as $i => $l) {
        if (isset($opt['skip']) && is_callable($opt['skip']) && call_user_func($opt['skip'], $l)) {
            continue;
        }
        $key = (string) call_user_func($keyOf, $l);
        if (!isset($out['keys'][$key])) {
            $out['keys'][$key] = array('rate' => preg_match('/^\d+$/', $key) ? (float) $key : null, 'bucket' => ksefForeignRateBucket($key),
                'net' => 0.0, 'vat' => 0.0, 'gross' => 0.0, 'lines' => array());
        }
        $out['keys'][$key]['net'] += (float) ($pln ? $l->total_ht : $l->multicurrency_total_ht);
        $out['keys'][$key]['vat'] += (float) ($pln ? $l->total_tva : $l->multicurrency_total_tva);
        $out['keys'][$key]['gross'] += (float) ($pln ? $l->total_ttc : $l->multicurrency_total_ttc);
        $out['keys'][$key]['lines'][] = (int) (!empty($l->rowid) ? $l->rowid : ($l->id ?? $i));
        if ($kurs > 0 && isset($opt['line_kurs']) && is_callable($opt['line_kurs'])) {
            $lk = round((float) call_user_func($opt['line_kurs'], $l), 6);
            $pk = number_format($lk > 0 ? $lk : $kurs, 6, '.', '');
            if (!isset($out['keys'][$key]['raw_parts'][$pk])) {
                $out['keys'][$key]['raw_parts'][$pk] = array('net' => 0.0, 'vat' => 0.0, 'gross' => 0.0);
            }
            $out['keys'][$key]['raw_parts'][$pk]['net'] += (float) $l->multicurrency_total_ht;
            $out['keys'][$key]['raw_parts'][$pk]['vat'] += (float) $l->multicurrency_total_tva;
            $out['keys'][$key]['raw_parts'][$pk]['gross'] += (float) $l->multicurrency_total_ttc;
        }
    }
    foreach ($out['keys'] as $key => $a) {
        $net = round($a['net'], 2);
        $vat = round($a['vat'], 2);
        $gross = round($a['gross'], 2);
        if (!empty($opt['gross'])) {
            $vat = $a['rate'] !== null ? round($gross * $a['rate'] / (100 + $a['rate']), 2) : 0.0;
            $net = round($gross - $vat, 2);
        } elseif (!empty($opt['round_total']) && $a['rate'] !== null) {
            $vat = round($net * $a['rate'] / 100, 2);
            $gross = round($net + $vat, 2);
        }
        $out['keys'][$key]['net'] = $net;
        $out['keys'][$key]['vat'] = $vat;
        $out['keys'][$key]['gross'] = $gross;
        $raw = isset($a['raw_parts']) ? $a['raw_parts'] : array();
        if ($kurs > 0 && !$raw && !empty($opt['pay_parts'])) {
            $total = 0.0;
            foreach ((array) $opt['pay_parts'] as $pp) {
                $total += (float) $pp['amount'];
            }
            foreach ((array) $opt['pay_parts'] as $pp) {
                $pk = number_format(round((float) $pp['kurs'], 6) > 0 ? round((float) $pp['kurs'], 6) : $kurs, 6, '.', '');
                if (!isset($raw[$pk])) {
                    $raw[$pk] = array('net' => 0.0, 'vat' => 0.0, 'gross' => 0.0);
                }
                $share = abs($total) >= 0.005 ? (float) $pp['amount'] / $total : 0.0;
                $raw[$pk]['net'] += $net * $share;
                $raw[$pk]['vat'] += $vat * $share;
                $raw[$pk]['gross'] += $gross * $share;
            }
        }
        unset($out['keys'][$key]['raw_parts']);
        if ($raw) {
            // art. 106f(1)(3)
            $rule = (!empty($opt['gross']) || !empty($opt['pay_parts'])) ? 'gross' : (!empty($opt['round_total']) ? 'net' : '');
            $out['keys'][$key]['parts'] = ksefFxKeyParts($out['keys'][$key], $raw, $rule);
        }
        $b = $a['bucket'];
        if (!isset($out['buckets'][$b])) {
            $out['buckets'][$b] = array('keys' => array(), 'w' => in_array($b, array('1', '2', '3', '4'), true), 'net' => 0.0, 'vat' => 0.0, 'gross' => 0.0,
                'net_w' => null, 'vat_w' => null, 'lines' => array(), 'multi' => false);
        }
        $out['buckets'][$b]['keys'][] = $key;
        $out['buckets'][$b]['net'] = round($out['buckets'][$b]['net'] + $net, 2);
        $out['buckets'][$b]['vat'] = round($out['buckets'][$b]['vat'] + $vat, 2);
        $out['buckets'][$b]['gross'] = round($out['buckets'][$b]['gross'] + $gross, 2);
        $out['buckets'][$b]['lines'] = array_merge($out['buckets'][$b]['lines'], $a['lines']);
    }
    if ($kurs > 0) {
        $method = (string) ($opt['method'] ?? 'convert_vat');
        $grossLike = !empty($opt['gross']) || !empty($opt['deposit']);
        foreach ($out['buckets'] as $b => $x) {
            $out['buckets'][$b]['multi'] = ksefFxPartsMulti($out['keys'], $x['keys'], $kurs);
            if ($out['buckets'][$b]['multi']) {
                $w = ksefFxConvertW($out['keys'], $x['keys'], $kurs, $method, $grossLike, (string) ($opt['multi'] ?? 'once'));
                $out['buckets'][$b]['net_w'] = $w['net_w'];
                $out['buckets'][$b]['vat_w'] = $w['vat_w'];
            } elseif ($method === 'convert_base') {
                $w = ksefFxConvertW($out['keys'], $x['keys'], $kurs, $method, $grossLike);
                $out['buckets'][$b]['net_w'] = $w['net_w'];
                $out['buckets'][$b]['vat_w'] = $w['vat_w'];
            } else {
                $out['buckets'][$b]['net_w'] = round($x['net'] * $kurs, 2);
                $out['buckets'][$b]['vat_w'] = round($x['vat'] * $kurs, 2);
            }
            if (isset($opt['w_override'][$b]) && $x['w']) {
                $out['buckets'][$b]['vat_w'] = round((float) $opt['w_override'][$b], 2);
            }
        }
    }
    return $out;
}

/**
 * @brief FX setting values
 * @return array name => values
 */
function ksefFxSettingValues()
{
    return array(
        'KSEF_FX_PLN_VAT_METHOD' => array('convert_vat', 'convert_base'),
        'KSEF_FX_KOR_METHOD' => array('after_before', 'change'),
        'KSEF_DEPOSIT_FX_POLICY' => array('settlement', 'historical'),
        'KSEF_FX_MULTI_RATE_ROUNDING' => array('once', 'per_part'),
        'KSEF_FX_DEPOSIT_KOR_ALLOC' => array('proportional', 'newest_first'),
    );
}

/**
 * @brief FX setting value
 * @param string $name
 * @return string
 */
function ksefFxSetting($name)
{
    $all = ksefFxSettingValues();
    $values = isset($all[$name]) ? $all[$name] : array('');
    // for coverage scan
    $read = array(
        'KSEF_FX_PLN_VAT_METHOD' => getDolGlobalString('KSEF_FX_PLN_VAT_METHOD', 'convert_vat'),
        'KSEF_FX_KOR_METHOD' => getDolGlobalString('KSEF_FX_KOR_METHOD', 'after_before'),
        'KSEF_DEPOSIT_FX_POLICY' => getDolGlobalString('KSEF_DEPOSIT_FX_POLICY', 'settlement'),
        'KSEF_FX_MULTI_RATE_ROUNDING' => getDolGlobalString('KSEF_FX_MULTI_RATE_ROUNDING', 'once'),
        'KSEF_FX_DEPOSIT_KOR_ALLOC' => getDolGlobalString('KSEF_FX_DEPOSIT_KOR_ALLOC', 'proportional'),
    );
    $v = isset($read[$name]) ? $read[$name] : '';
    return in_array($v, $values, true) ? $v : $values[0];
}

/**
 * @brief Invoice FX rules
 * @param DoliDB $db
 * @param int $invoiceId
 * @return array
 */
function ksefFxRulesOf($db, $invoiceId)
{
    $P = MAIN_DB_PREFIX;
    $id = (int) $invoiceId;
    $status = 0;
    for ($i = 0; $i < 25 && $id > 0; $i++) {
        $res = $db->query("SELECT rowid, type, fk_facture_source, fk_statut FROM " . $P . "facture WHERE rowid = " . $id);
        $o = $res ? $db->fetch_object($res) : null;
        if (!$o) {
            break;
        }
        $status = (int) $o->fk_statut;
        if (in_array((int) $o->type, array(1, 2), true) && (int) $o->fk_facture_source > 0) {
            $id = (int) $o->fk_facture_source;
            continue;
        }
        break;
    }
    $all = ksefFxSettingValues();
    $out = array('vat' => $all['KSEF_FX_PLN_VAT_METHOD'][0], 'kor' => $all['KSEF_FX_KOR_METHOD'][0], 'multi' => $all['KSEF_FX_MULTI_RATE_ROUNDING'][0],
        'dkor' => $all['KSEF_FX_DEPOSIT_KOR_ALLOC'][0], 'root' => $id, 'stored' => false);
    $stored = '';
    if ($id > 0 && ksefFxRulesStorable($db)) {
        $res = $db->query("SELECT ksef_fx_rules FROM " . $P . "facture_extrafields WHERE fk_object = " . $id);
        $o = $res ? $db->fetch_object($res) : null;
        $stored = $o ? trim((string) $o->ksef_fx_rules) : '';
    }
    if ($stored !== '') {
        $out['stored'] = true;
        foreach (explode(';', $stored) as $pair) {
            $kv = explode('=', $pair, 2);
            if (count($kv) === 2) {
                $map = array('vat' => 'KSEF_FX_PLN_VAT_METHOD', 'kor' => 'KSEF_FX_KOR_METHOD', 'multi' => 'KSEF_FX_MULTI_RATE_ROUNDING', 'dkor' => 'KSEF_FX_DEPOSIT_KOR_ALLOC');
                if (isset($map[$kv[0]]) && in_array($kv[1], $all[$map[$kv[0]]], true)) {
                    $out[$kv[0]] = $kv[1];
                }
            }
        }
        return $out;
    }
    if ($status === 0) {
        $out['vat'] = ksefFxSetting('KSEF_FX_PLN_VAT_METHOD');
        $out['kor'] = ksefFxSetting('KSEF_FX_KOR_METHOD');
        $out['multi'] = ksefFxSetting('KSEF_FX_MULTI_RATE_ROUNDING');
        $out['dkor'] = ksefFxSetting('KSEF_FX_DEPOSIT_KOR_ALLOC');
    }
    return $out;
}

/**
 * @brief Rules field exists
 * @param DoliDB $db
 * @return bool
 */
function ksefFxRulesStorable($db)
{
    // field may appear
    static $ok = false;
    if (!$ok) {
        require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
        $ef = new ExtraFields($db);
        $ef->fetch_name_optionals_label('facture');
        $ok = isset($ef->attributes['facture']['type']['ksef_fx_rules']);
    }
    return $ok;
}

/**
 * @brief Stamp FX rules
 * @param DoliDB $db
 * @param Facture $invoice
 * @return int 1 stored, 0 skipped, -1 error
 */
function ksefFxRulesStamp($db, $invoice)
{
    global $conf;
    $code = (string) ($invoice->multicurrency_code ?? '');
    $dkor = (int) $invoice->type === Facture::TYPE_DEPOSIT ? ksefFxSetting('KSEF_FX_DEPOSIT_KOR_ALLOC') : 'proportional';
    $plnDeposit = ($code === '' || $code === $conf->currency) && $dkor !== 'proportional';
    if ((($code === '' || $code === $conf->currency) && !$plnDeposit) || (in_array((int) $invoice->type, array(1, 2), true) && (int) $invoice->fk_facture_source > 0)
        || !empty($GLOBALS['ksef_importing_sales'])) {
        return 0;
    }
    if (!ksefFxRulesStorable($db)) {
        return 0;
    }
    $E = MAIN_DB_PREFIX . "facture_extrafields";
    $res = $db->query("SELECT rowid, ksef_fx_rules FROM " . $E . " WHERE fk_object = " . (int) $invoice->id);
    if (!$res) {
        return -1;
    }
    $o = $db->fetch_object($res);
    if ($o && trim((string) $o->ksef_fx_rules) !== '') {
        return 0;
    }
    // absent means default
    $multi = ksefFxSetting('KSEF_FX_MULTI_RATE_ROUNDING');
    $val = "'" . $db->escape('vat=' . ksefFxSetting('KSEF_FX_PLN_VAT_METHOD') . ';kor=' . ksefFxSetting('KSEF_FX_KOR_METHOD') . ($multi !== 'once' ? ';multi=' . $multi : '')
        . ($dkor !== 'proportional' ? ';dkor=' . $dkor : '')) . "'";
    $sql = $o ? "UPDATE " . $E . " SET ksef_fx_rules = " . $val . " WHERE rowid = " . (int) $o->rowid
        : "INSERT INTO " . $E . " (fk_object, ksef_fx_rules) VALUES (" . (int) $invoice->id . ", " . $val . ")";
    if (!$db->query($sql)) {
        dol_syslog('ksefFxRulesStamp invoice ' . (int) $invoice->id . ': ' . $db->lasterror(), LOG_ERR);
        return -1;
    }
    if (is_array($invoice->array_options ?? null)) {
        $invoice->array_options['options_ksef_fx_rules'] = trim($val, "'");
    }
    return 1;
}

/**
 * @brief Bucket to PLN
 * @param array $amounts
 * @param array $keys Bucket rate keys
 * @param float $kurs
 * @param string $method
 * @param bool $grossLike
 * @param string $multi
 * @return array net_w, vat_w
 */
function ksefFxConvertW($amounts, $keys, $kurs, $method, $grossLike = false, $multi = 'once')
{
    $k = round((float) $kurs, 6);
    if (ksefFxPartsMulti($amounts, $keys, $k)) {
        return ksefFxConvertParts($amounts, $keys, $k, $method, $grossLike, $multi === 'per_part');
    }
    if ($method !== 'convert_base') {
        $net = 0.0;
        $vat = 0.0;
        foreach ((array) $keys as $key) {
            $net += (float) ($amounts[$key]['net'] ?? 0);
            $vat += (float) ($amounts[$key]['vat'] ?? 0);
        }
        return array('net_w' => round(round($net, 2) * $k, 2), 'vat_w' => round(round($vat, 2) * $k, 2));
    }
    $nw = 0.0;
    $vw = 0.0;
    foreach ((array) $keys as $key) {
        $a = isset($amounts[$key]) ? $amounts[$key] : array();
        $net = round((float) ($a['net'] ?? 0), 2);
        $vat = round((float) ($a['vat'] ?? 0), 2);
        $gross = round((float) ($a['gross'] ?? ($net + $vat)), 2);
        $r = preg_match('/^\d+$/', (string) $key) ? (float) $key : 0.0;
        if ($r <= 0) {
            $kn = round($net * $k, 2);
            $kv = round($vat * $k, 2);
        } elseif ($grossLike) {
            $gw = round($gross * $k, 2);
            $kv = round($gw * $r / (100 + $r), 2);
            $kn = round($gw - $kv, 2);
        } else {
            $kn = round($net * $k, 2);
            $kv = round($kn * $r / 100, 2);
        }
        $nw += $kn;
        $vw += $kv;
    }
    return array('net_w' => round($nw, 2), 'vat_w' => round($vw, 2));
}

/**
 * @brief Key over rates
 * @param array $a Rounded key
 * @param array $raw K => amounts
 * @param string $rule '', gross or net
 * @return array K => amounts
 */
function ksefFxKeyParts($a, $raw, $rule)
{
    $r = $a['rate'];
    $left = array('net' => (float) $a['net'], 'vat' => (float) $a['vat'], 'gross' => (float) $a['gross']);
    $ks = array_keys($raw);
    $out = array();
    foreach ($ks as $i => $pk) {
        $x = $raw[$pk];
        if ($i === count($ks) - 1) {
            $p = array('net' => round($left['net'], 2), 'vat' => round($left['vat'], 2), 'gross' => round($left['gross'], 2));
        } elseif ($rule === 'gross') {
            $g = round((float) $x['gross'], 2);
            $v = $r !== null ? round($g * $r / (100 + $r), 2) : 0.0;
            $p = array('net' => round($g - $v, 2), 'vat' => $v, 'gross' => $g);
        } elseif ($rule === 'net' && $r !== null) {
            $n = round((float) $x['net'], 2);
            $v = round($n * $r / 100, 2);
            $p = array('net' => $n, 'vat' => $v, 'gross' => round($n + $v, 2));
        } else {
            $p = array('net' => round((float) $x['net'], 2), 'vat' => round((float) $x['vat'], 2), 'gross' => round((float) $x['gross'], 2));
        }
        foreach (array('net', 'vat', 'gross') as $c) {
            $left[$c] -= $p[$c];
        }
        $out[(string) $pk] = $p;
    }
    return $out;
}

/**
 * @brief Bucket multi-rate
 * @param array $amounts
 * @param array $keys
 * @param float $kurs
 * @return bool
 */
function ksefFxPartsMulti($amounts, $keys, $kurs)
{
    $ks = array();
    foreach ((array) $keys as $key) {
        foreach ((array) ($amounts[$key]['parts'] ?? array()) as $pk => $p) {
            $ks[(string) $pk] = true;
        }
    }
    if (count($ks) > 1) {
        return true;
    }
    return count($ks) === 1 && abs((float) key($ks) - round((float) $kurs, 6)) >= 5e-7;
}

/**
 * @brief Parts to PLN
 * @param array $amounts
 * @param array $keys
 * @param float $kurs
 * @param string $method
 * @param bool $grossLike
 * @param bool $perPart
 * @return array net_w, vat_w
 */
function ksefFxConvertParts($amounts, $keys, $kurs, $method, $grossLike, $perPart)
{
    $partsOf = function ($key) use ($amounts, $kurs) {
        $a = isset($amounts[$key]) ? $amounts[$key] : array();
        if (!empty($a['parts'])) {
            return $a['parts'];
        }
        $net = round((float) ($a['net'] ?? 0), 2);
        $vat = round((float) ($a['vat'] ?? 0), 2);
        return array(number_format($kurs, 6, '.', '') => array('net' => $net, 'vat' => $vat, 'gross' => round((float) ($a['gross'] ?? ($net + $vat)), 2)));
    };
    $nw = 0.0;
    $vw = 0.0;
    if ($method !== 'convert_base') {
        $byK = array();
        foreach ((array) $keys as $key) {
            foreach ($partsOf($key) as $pk => $p) {
                if (!isset($byK[$pk])) {
                    $byK[$pk] = array('net' => 0.0, 'vat' => 0.0);
                }
                $byK[$pk]['net'] += (float) $p['net'];
                $byK[$pk]['vat'] += (float) $p['vat'];
            }
        }
        foreach ($byK as $pk => $x) {
            $n = round($x['net'], 2) * (float) $pk;
            $v = round($x['vat'], 2) * (float) $pk;
            $nw += $perPart ? round($n, 2) : $n;
            $vw += $perPart ? round($v, 2) : $v;
        }
        return array('net_w' => round($nw, 2), 'vat_w' => round($vw, 2));
    }
    foreach ((array) $keys as $key) {
        $r = preg_match('/^\d+$/', (string) $key) ? (float) $key : 0.0;
        $groups = array();
        foreach ($partsOf($key) as $pk => $p) {
            $g = $perPart ? $pk : 'all';
            if (!isset($groups[$g])) {
                $groups[$g] = array('net' => 0.0, 'vat' => 0.0, 'gross' => 0.0);
            }
            foreach (array('net', 'vat', 'gross') as $c) {
                $groups[$g][$c] += (float) $p[$c] * (float) $pk;
            }
        }
        foreach ($groups as $x) {
            if ($r <= 0) {
                $kn = round($x['net'], 2);
                $kv = round($x['vat'], 2);
            } elseif ($grossLike) {
                $gw = round($x['gross'], 2);
                $kv = round($gw * $r / (100 + $r), 2);
                $kn = round($gw - $kv, 2);
            } else {
                $kn = round($x['net'], 2);
                $kv = round($kn * $r / 100, 2);
            }
            $nw += $kn;
            $vw += $kv;
        }
    }
    return array('net_w' => round($nw, 2), 'vat_w' => round($vw, 2));
}

/**
 * @brief KOR change-method VAT
 * @param DoliDB $db
 * @param int $invoiceId Replacement id
 * @param array $view Lines
 * @param array $sumOpt Summary options
 * @param float $kurs
 * @return array|null Null if not applicable
 */
function ksefFxKorChangeW($db, $invoiceId, $view, $sumOpt, $kurs)
{
    $inv = new Facture($db);
    if ($inv->fetch((int) $invoiceId) <= 0 || (int) $inv->fk_facture_source <= 0) {
        return null;
    }
    $inv->fetch_optionals();
    $state = ksefCorrectionState($inv);
    if (!empty($state['dep_paid'])) {
        return null;
    }
    $src = new Facture($db);
    if ($src->fetch((int) $inv->fk_facture_source) <= 0) {
        return null;
    }
    if (number_format(ksefInvoiceKurs($src, array('db' => $db)), 6, '.', '') !== number_format((float) $kurs, 6, '.', '')) {
        return null;
    }
    $src->fetch_lines();
    $before = (array) $src->lines;
    foreach (array_map('intval', (array) ($state['absorb'] ?? array())) as $noteId) {
        $note = new Facture($db);
        if ($note->fetch($noteId) > 0) {
            $note->fetch_lines();
            $before = array_merge($before, (array) $note->lines);
        }
    }
    $opt = array('kurs' => $kurs) + $sumOpt;
    unset($opt['w_override']);
    $a = ksefForeignRateSummary($view, $opt);
    if (!empty($opt['pay_parts_before'])) {
        $opt['pay_parts'] = $opt['pay_parts_before'];
    }
    $b = ksefForeignRateSummary($before, $opt);
    $stated = ksefStatedBeforeVatW($db, $inv);
    $grossLike = !empty($opt['gross']) || !empty($opt['deposit']);
    $out = array();
    foreach (array('1', '2', '3', '4') as $bucket) {
        if (!isset($a['buckets'][$bucket]) && !isset($b['buckets'][$bucket])) {
            continue;
        }
        $keys = array_values(array_unique(array_merge((array) ($a['buckets'][$bucket]['keys'] ?? array()), (array) ($b['buckets'][$bucket]['keys'] ?? array()))));
        $delta = array();
        foreach ($keys as $key) {
            foreach (array('net', 'vat', 'gross') as $col) {
                $delta[$key][$col] = round((float) ($a['keys'][$key][$col] ?? 0) - (float) ($b['keys'][$key][$col] ?? 0), 2);
            }
            $pa = (array) ($a['keys'][$key]['parts'] ?? array());
            $pb = (array) ($b['keys'][$key]['parts'] ?? array());
            foreach (array_unique(array_merge(array_keys($pa), array_keys($pb))) as $pk) {
                foreach (array('net', 'vat', 'gross') as $col) {
                    $delta[$key]['parts'][(string) $pk][$col] = round((float) ($pa[$pk][$col] ?? 0) - (float) ($pb[$pk][$col] ?? 0), 2);
                }
            }
        }
        $wb = $stated !== null ? (float) ($stated[$bucket] ?? 0) : (float) ($b['buckets'][$bucket]['vat_w'] ?? 0);
        $out[$bucket] = round($wb + ksefFxConvertW($delta, $keys, $kurs, (string) ($opt['method'] ?? 'convert_vat'), $grossLike, (string) ($opt['multi'] ?? 'once'))['vat_w'], 2);
    }
    return $out;
}

/**
 * @brief Line kurs field
 * @param DoliDB $db
 * @return bool
 */
function ksefLineKursStorable($db)
{
    // field may appear
    static $ok = false;
    if (!$ok) {
        require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
        $ef = new ExtraFields($db);
        $ef->fetch_name_optionals_label('facturedet');
        $ok = isset($ef->attributes['facturedet']['type']['ksef_kurs']);
    }
    return $ok;
}

/**
 * @brief Stored line rates
 * @param DoliDB $db
 * @param array $lineIds
 * @return array Line id => K
 */
function ksefLineKursOf($db, $lineIds)
{
    $out = array();
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) $lineIds))));
    if (!$ids || !ksefLineKursStorable($db)) {
        return $out;
    }
    $P = MAIN_DB_PREFIX;
    $todo = array_combine($ids, $ids);
    for ($hop = 0; $todo && $hop < 7; $hop++) {
        $res = $db->query("SELECT d.rowid, d.fk_prev_id, e.ksef_kurs FROM " . $P . "facturedet AS d LEFT JOIN " . $P . "facturedet_extrafields AS e ON e.fk_object = d.rowid"
            . " WHERE d.rowid IN (" . implode(',', array_unique(array_values($todo))) . ")");
        $rows = array();
        while ($res && ($o = $db->fetch_object($res))) {
            $rows[(int) $o->rowid] = $o;
        }
        $next = array();
        foreach ($todo as $orig => $cur) {
            $o = $rows[$cur] ?? null;
            if (!$o) {
                continue;
            }
            $k = trim((string) $o->ksef_kurs);
            if ($k !== '' && is_numeric($k) && (float) $k > 0) {
                $out[$orig] = round((float) $k, 6);
            } elseif ((int) $o->fk_prev_id > 0 && (int) $o->fk_prev_id !== (int) $cur) {
                $next[$orig] = (int) $o->fk_prev_id;
            }
        }
        $todo = $next;
    }
    return $out;
}

/**
 * @brief Per-line rate map
 * @param DoliDB $db
 * @param object $invoice Invoice or row
 * @return array
 */
function ksefLineKursMap($db, $invoice)
{
    global $conf;
    $out = array('active' => false, 'kurs' => 0.0, 'lines' => array(), 'missing' => array(), 'resolve' => null);
    $id = (int) ($invoice->id ?? ($invoice->rowid ?? 0));
    $code = (string) ($invoice->multicurrency_code ?? '');
    if ($id <= 0 || $code === '' || $code === $conf->currency || !ksefLineKursStorable($db)) {
        return $out;
    }
    if (!class_exists('Facture')) {
        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
    }
    $type = (int) $invoice->type;
    if (!in_array($type, array(Facture::TYPE_STANDARD, Facture::TYPE_REPLACEMENT, Facture::TYPE_CREDIT_NOTE), true)) {
        return $out;
    }
    if ($type !== Facture::TYPE_STANDARD && (int) ($invoice->fk_facture_source ?? 0) > 0) {
        $root = ksefCorrectionRoot($db, $invoice);
        if ($root && (int) $root->type === Facture::TYPE_DEPOSIT) {
            return $out;
        }
    }
    $res = $db->query("SELECT rowid, product_type, info_bits, fk_remise_except, multicurrency_total_ht, multicurrency_total_ttc FROM " . MAIN_DB_PREFIX . "facturedet WHERE fk_facture = " . $id);
    $own = array();
    while ($res && ($o = $db->fetch_object($res))) {
        $own[(int) $o->rowid] = $o;
    }
    $out['lines'] = ksefLineKursOf($db, array_keys($own));
    if (!$out['lines']) {
        return $out;
    }
    $out['kurs'] = round(ksefInvoiceKurs($invoice, array('db' => $db)), 6);
    foreach ($out['lines'] as $k) {
        if (abs($k - $out['kurs']) >= 5e-7) {
            $out['active'] = true;
            break;
        }
    }
    if (!$out['active']) {
        return $out;
    }
    $perLineRoot = true;
    if ($type !== Facture::TYPE_STANDARD && (int) ($invoice->fk_facture_source ?? 0) > 0) {
        $perLineRoot = !empty($root) && (int) $root->type === Facture::TYPE_STANDARD && ksefLineKursMap($db, $root)['active'];
    }
    foreach ($perLineRoot ? $own : array() as $lid => $o) {
        $amount = abs((float) $o->multicurrency_total_ht) >= 0.005 || abs((float) $o->multicurrency_total_ttc) >= 0.005;
        if (!isset($out['lines'][$lid]) && $amount && !ksefFxSkipLine($o) && (int) $o->fk_remise_except <= 0) {
            $out['missing'][] = $lid;
        }
    }
    $known = $out['lines'];
    $out['resolve'] = function ($l) use ($db, &$known) {
        $lid = (int) (!empty($l->rowid) ? $l->rowid : ($l->id ?? 0));
        if ($lid <= 0) {
            return 0.0;
        }
        if (!array_key_exists($lid, $known)) {
            $found = ksefLineKursOf($db, array($lid));
            $known[$lid] = isset($found[$lid]) ? $found[$lid] : 0.0;
        }
        return (float) $known[$lid];
    };
    return $out;
}

/**
 * @brief Set line rates
 * @param DoliDB $db
 * @param Facture $invoice
 * @param float $kurs 0: invoice rate
 * @param array $opt
 * @return array
 */
function ksefLineKursRefresh($db, $invoice, $kurs = 0.0, $opt = array())
{
    global $conf;
    $out = array('result' => 0, 'active' => false, 'error' => '', 'nbp' => false, 'want' => array(), 'changes' => 0);
    $id = (int) ($invoice->id ?? 0);
    $type = (int) ($invoice->type ?? 0);
    $dry = !empty($opt['dry_run']);
    if ($id <= 0 || !ksefLineKursStorable($db) || !empty($GLOBALS['ksef_importing_sales'])
        || ($type !== Facture::TYPE_STANDARD && $type !== Facture::TYPE_DEPOSIT && $type !== Facture::TYPE_SITUATION)) {
        return $out;
    }
    if (ksefInvoiceSentToKsef($db, $id) || $db->getRow("SELECT rowid FROM " . MAIN_DB_PREFIX . "ksef_sales WHERE fk_facture = " . $id)) {
        return $out;
    }
    $P = MAIN_DB_PREFIX;
    $srcCol = ksefKursSrcStorable($db) ? "e.ksef_kurs_src" : "NULL AS ksef_kurs_src";
    $res = $db->query("SELECT d.rowid, d.fk_remise_except, d.multicurrency_total_ttc, e.rowid AS erow, e.ksef_kurs, " . $srcCol . " FROM " . $P . "facturedet AS d"
        . " LEFT JOIN " . $P . "facturedet_extrafields AS e ON e.fk_object = d.rowid WHERE d.fk_facture = " . $id);
    if (!$res) {
        $out['result'] = -1;
        $out['error'] = $db->lasterror();
        return $out;
    }
    $rows = array();
    $creditLines = array();
    $storedNbp = false;
    while ($o = $db->fetch_object($res)) {
        $rows[(int) $o->rowid] = $o;
        if ((int) $o->fk_remise_except > 0) {
            $creditLines[(int) $o->rowid] = true;
        }
        if (trim((string) $o->ksef_kurs) !== '') {
            $storedNbp = true;
        }
    }
    $want = array();
    $dayOf = array();
    $code = (string) ($invoice->multicurrency_code ?? '');
    if ($type === Facture::TYPE_STANDARD && $code !== '' && $code !== $conf->currency && (float) $invoice->multicurrency_tx > 0) {
        if (empty($invoice->array_options)) {
            $invoice->fetch_optionals();
        }
        if (empty($invoice->lines)) {
            $invoice->fetch_lines();
        }
        $lines = array_values((array) $invoice->lines);
        $supply = ksefInvoiceSupplyMode($invoice, $lines);
        if ($supply['mode'] === 'p6a') {
            dol_include_once('/ksef/class/ksef_nbp_currency_rate.class.php');
            $nbp = new KsefNbpCurrencyRate($db);
            $byDay = array();
            $p6a = true;
            $kurs = round($kurs > 0 ? (float) $kurs : ksefInvoiceKurs($invoice, array('db' => $db)), 6);
            $ownDay = ($kurs > 0 && !empty($invoice->array_options['options_ksef_kurs_data'])) ? ksefMomentDay($nbp->getReferenceDate($invoice)) : '';
            foreach ($supply['lines'] as $i => $day) {
                if ($day === '' || !isset($lines[$i]) || empty($lines[$i]->id) || isset($creditLines[(int) $lines[$i]->id])) {
                    continue;
                }
                $dayOf[(int) $lines[$i]->id] = $day;
                if (!isset($byDay[$day])) {
                    $ref = $nbp->getReferenceDate($invoice, 0, false, array('sale' => (int) strtotime($day)));
                    if ($ownDay !== '' && ksefMomentDay($ref) === $ownDay) {
                        $byDay[$day] = $kurs;
                        $want[(int) $lines[$i]->id] = $kurs;
                        continue;
                    }
                    $r = $nbp->getRateForDateCached($code, $ref);
                    if (!$r || (float) $r['rate'] <= 0) {
                        $out['nbp'] = true;
                        $out['error'] = 'NBP rate of ' . $day . ': ' . $nbp->error;
                        if (empty($opt['nbp_soft'])) {
                            $out['result'] = -1;
                            return $out;
                        }
                        dol_syslog('ksefLineKursRefresh invoice ' . $id . ': ' . $out['error'] . '; line rates left empty until the NBP refresh or the send', LOG_WARNING);
                        $want = array();
                        break;
                    }
                    $byDay[$day] = round((float) $r['rate'], 6);
                }
                $want[(int) $lines[$i]->id] = $byDay[$day];
            }
            foreach ($want as $k) {
                if (abs($k - $kurs) >= 5e-7) {
                    $out['active'] = true;
                    break;
                }
            }
            if (!$out['active']) {
                $want = array();
            }
        }
    }
    if ($type === Facture::TYPE_STANDARD) {
        $out['setting_off'] = empty($p6a) && $storedNbp && getDolGlobalString('KSEF_FA3_LINE_DATES', 'disabled') !== 'auto';
    }
    $out['want'] = $want;
    foreach ($rows as $lid => $o) {
        $new = isset($want[$lid]) ? number_format($want[$lid], 6, '.', '') : '';
        $cur = trim((string) $o->ksef_kurs);
        if ($cur === $new || ($cur !== '' && $new !== '' && abs((float) $cur - (float) $new) < 5e-7)) {
            continue;
        }
        $out['changes']++;
        if ($dry) {
            continue;
        }
        $err = ksefLineKursWrite($db, (int) $lid, $new, $new !== '' && !empty($dayOf[$lid]) ? json_encode(array('d' => $dayOf[$lid])) : '');
        if ($err !== '') {
            $out['result'] = -1;
            $out['error'] = $err;
            return $out;
        }
        $out['result']++;
    }
    return $out;
}

/**
 * @brief Rate correction lines
 * @param DoliDB $db
 * @param Facture $invoice Correction
 * @param array $opt
 * @return array
 */
function ksefLineKursFillCorrection($db, $invoice, $opt = array())
{
    $out = array('result' => 0, 'error' => '', 'nbp' => false, 'want' => array(), 'changes' => 0, 'undated' => array());
    $id = (int) ($invoice->id ?? 0);
    if ($id <= 0 || !empty($GLOBALS['ksef_importing_sales']) || (int) ($invoice->fk_facture_source ?? 0) <= 0
        || !in_array((int) ($invoice->type ?? 0), array(Facture::TYPE_REPLACEMENT, Facture::TYPE_CREDIT_NOTE), true)) {
        return $out;
    }
    if (ksefInvoiceSentToKsef($db, $id) || $db->getRow("SELECT rowid FROM " . MAIN_DB_PREFIX . "ksef_sales WHERE fk_facture = " . $id)) {
        return $out;
    }
    $map = ksefLineKursMap($db, $invoice);
    $P = MAIN_DB_PREFIX;
    $refill = array();
    if ($map['active'] && ksefKursSrcStorable($db)) {
        $resF = $db->query("SELECT d.rowid, d.date_start, d.date_end, e.ksef_kurs_src FROM " . $P . "facturedet AS d INNER JOIN " . $P . "facturedet_extrafields AS e ON e.fk_object = d.rowid"
            . " WHERE d.fk_facture = " . $id . " AND e.ksef_kurs_src LIKE '%\"f\":1%'");
        while ($resF && ($o = $db->fetch_object($resF))) {
            $s = ksefKursSrc($o->ksef_kurs_src);
            $ts = (int) $db->jdate($o->date_end ?: $o->date_start);
            if (!empty($s['f']) && (int) ($s['l'] ?? 0) === (int) $o->rowid && $ts > 0 && dol_print_date($ts, '%Y-%m-%d', 'tzserver') !== (string) ($s['d'] ?? '')) {
                $refill[] = (int) $o->rowid;
            }
        }
    }
    if (!$map['active'] || (!$map['missing'] && !$refill)) {
        return $out;
    }
    $todo = array_values(array_unique(array_merge(array_map('intval', $map['missing']), $refill)));
    $root = ksefCorrectionRoot($db, $invoice);
    if (!$root) {
        $out['result'] = -1;
        $out['error'] = 'corrected invoice of ' . $id . ' not found';
        return $out;
    }
    $res = $db->query("SELECT d.rowid, d.date_start, d.date_end, e.rowid AS erow, e.ksef_kurs FROM " . $P . "facturedet AS d LEFT JOIN " . $P . "facturedet_extrafields AS e ON e.fk_object = d.rowid"
        . " WHERE d.rowid IN (" . implode(',', $todo) . ")");
    if (!$res) {
        $out['result'] = -1;
        $out['error'] = $db->lasterror();
        return $out;
    }
    $rows = array();
    while ($o = $db->fetch_object($res)) {
        $rows[(int) $o->rowid] = $o;
    }
    $dayOf = array();
    dol_include_once('/ksef/class/ksef_nbp_currency_rate.class.php');
    $nbp = new KsefNbpCurrencyRate($db);
    $code = $nbp->getInvoiceCurrency($root);
    $byDay = array();
    foreach ($todo as $lid) {
        $o = $rows[(int) $lid] ?? null;
        if (!$o) {
            continue;
        }
        // same as ksefSupplyDates()
        $ts = (int) $db->jdate($o->date_end ?: $o->date_start);
        if ($ts <= 0) {
            $out['undated'][] = (int) $lid;
            continue;
        }
        $day = dol_print_date($ts, '%Y-%m-%d', 'tzserver');
        $dayOf[(int) $lid] = $day;
        if (!isset($byDay[$day])) {
            $r = $nbp->getRateForDateCached($code, $nbp->getReferenceDate($root, 0, false, array('sale' => (int) strtotime($day))));
            if (!$r || (float) $r['rate'] <= 0) {
                $out['nbp'] = true;
                $out['error'] = 'NBP rate of ' . $day . ': ' . $nbp->error;
                if (empty($opt['nbp_soft'])) {
                    $out['result'] = -1;
                }
                dol_syslog('ksefLineKursFillCorrection invoice ' . $id . ': ' . $out['error'], LOG_WARNING);
                return $out;
            }
            $byDay[$day] = round((float) $r['rate'], 6);
        }
        $out['want'][(int) $lid] = $byDay[$day];
    }
    foreach ($out['want'] as $lid => $k) {
        $cur = trim((string) $rows[$lid]->ksef_kurs);
        if ($cur === '' || !is_numeric($cur) || abs((float) $cur - $k) >= 5e-7) {
            $out['changes']++;
        }
    }
    if (!empty($opt['dry_run'])) {
        return $out;
    }
    foreach ($out['want'] as $lid => $k) {
        // d/f/l codes
        $err = ksefLineKursWrite($db, (int) $lid, number_format($k, 6, '.', ''), json_encode(array('l' => (int) $lid, 'd' => $dayOf[$lid], 'f' => 1)));
        if ($err !== '') {
            $out['result'] = -1;
            $out['error'] = $err;
            return $out;
        }
        $out['result']++;
    }
    return $out;
}

/**
 * @brief Send line rates
 * @param DoliDB $db
 * @param Facture $invoice
 * @param string $mode 'plain' or 'trans'
 * @return array
 */
function ksefLineKursSendPrepare($db, $invoice, $mode = 'plain')
{
    global $conf, $langs;
    $out = array('ok' => true, 'code' => '', 'message' => '', 'changed' => 0, 'notice' => '');
    $code = (string) ($invoice->multicurrency_code ?? '');
    if (empty($invoice->id) || (string) ($invoice->element ?? 'facture') !== 'facture' || $code === '' || $code === $conf->currency
        || (int) $invoice->statut === Facture::STATUS_DRAFT || !ksefLineKursStorable($db)) {
        return $out;
    }
    $langs->load('ksef@ksef');
    $tr = function ($key, $args) use ($langs, $mode) {
        if ($mode === 'plain') {
            return $langs->transnoentitiesnoconv($key, ...$args);
        }
        return $langs->trans($key, ...array_map('dol_escape_htmltag', $args));
    };
    $fail = function ($c, $message) use (&$out) {
        $out['ok'] = false;
        $out['code'] = $c;
        $out['message'] = $message;
        dol_syslog('ksefLineKursSendPrepare: ' . $c . ': ' . dol_string_nohtmltag($message), LOG_WARNING);
        return $out;
    };
    $isCorr = in_array((int) $invoice->type, array(Facture::TYPE_REPLACEMENT, Facture::TYPE_CREDIT_NOTE), true);
    $depRoot = (int) $invoice->type === Facture::TYPE_DEPOSIT ? $invoice : ($isCorr ? ksefCorrectionRoot($db, $invoice) : null);
    if ($depRoot && (int) $depRoot->type === Facture::TYPE_DEPOSIT && !ksefInvoiceSentToKsef($db, (int) $invoice->id)
        && !ksefInvoiceInAccounting($db, (int) $invoice->id) && ksefForeignPlnDerivedModel($db, (int) $invoice->id)) {
        $dry = ksefDerivePlnFromForeign($db, (int) $invoice->id, array('dry_run' => true));
        if ($dry['skip'] === '' && $dry['result'] > 0) {
            $db->begin();
            $d = ksefDerivePlnFromForeign($db, (int) $invoice->id);
            if ($d['result'] >= 0 && (int) $invoice->type === Facture::TYPE_DEPOSIT && ksefDepositCreditPlnFollow($db, (int) $invoice->id) < 0) {
                $d = array('result' => -1, 'error' => $db->lasterror());
            }
            if ($d['result'] < 0) {
                $db->rollback();
                return $fail('line_rate_error', $tr('KSEF_LineRatesFailed', array($invoice->ref, $d['error'])));
            }
            $db->commit();
            if ($d['result'] > 0) {
                $out['changed'] = count($d['lines']);
                $out['notice'] = $tr('KSEF_DepositRatesSetAtSend', array($invoice->ref));
                dol_syslog('ksefLineKursSendPrepare deposit ' . (int) $invoice->id . ': PLN derived again from its payments at the send', LOG_INFO);
                $invoice->fetch((int) $invoice->id);
                $invoice->fetch_lines();
                $invoice->fetch_optionals();
            }
        }
    }
    if (!$isCorr && (int) $invoice->type !== Facture::TYPE_STANDARD) {
        return $out;
    }
    $calc = $isCorr ? ksefLineKursFillCorrection($db, $invoice, array('dry_run' => true)) : ksefLineKursRefresh($db, $invoice, 0, array('dry_run' => true));
    if ($calc['result'] < 0) {
        return $calc['nbp'] ? $fail('line_rate_missing', $tr('KSEF_LineRateNbpMissing', array($invoice->ref)))
            : $fail('line_rate_error', $tr('KSEF_LineRatesFailed', array($invoice->ref, $calc['error'])));
    }
    if ($calc['changes'] > 0) {
        dol_include_once('/ksef/class/ksef_nbp_currency_rate.class.php');
        $nbp = new KsefNbpCurrencyRate($db);
        $parts = $nbp->rateChangeBlocked($invoice);
        if ($parts === false) {
            return $fail('line_rate_error', $tr('KSEF_LineRatesFailed', array($invoice->ref, $db->lasterror())));
        }
        if ($parts && !empty($calc['setting_off'])) {
            return $fail('line_rates_booked', $tr('KSEF_LineRatesSettingOff', array($invoice->ref, $langs->transnoentitiesnoconv('KSEF_FA3_LINE_DATES'),
                $nbp->rateChangeBookedParts($parts, $nbp->rateChangeReplacement($invoice)), $langs->transnoentitiesnoconv('KSEF_FA3_LINE_DATES_auto'))));
        }
        if ($parts) {
            return $fail('line_rates_booked', $nbp->rateChangeBookedMessage('KSEF_LineRatesBooked', $invoice, $parts, array($invoice->ref), $mode === 'plain' ? 'plain' : 'trans'));
        }
        $db->begin();
        $w = $isCorr ? ksefLineKursFillCorrection($db, $invoice) : ksefLineKursRefresh($db, $invoice);
        $d = $w['result'] < 0 ? array('result' => -1, 'error' => $w['error']) : ksefDerivePlnFromForeign($db, (int) $invoice->id);
        if ($d['result'] < 0) {
            $db->rollback();
            return $fail('line_rate_error', $tr('KSEF_LineRatesFailed', array($invoice->ref, $d['error'])));
        }
        $db->commit();
        $out['changed'] = (int) $w['result'];
        $out['notice'] = !empty($calc['setting_off']) ? $tr('KSEF_LineRatesSettingOffNotice', array($invoice->ref, $langs->transnoentitiesnoconv('KSEF_FA3_LINE_DATES')))
            : $tr('KSEF_LineRatesSetAtSend', array($invoice->ref));
        dol_syslog('ksefLineKursSendPrepare invoice ' . (int) $invoice->id . ': ' . (int) $w['result'] . ' line rates set at the send, PLN derived again', LOG_INFO);
        $invoice->fetch((int) $invoice->id);
        $invoice->fetch_lines();
        $invoice->fetch_optionals();
    }
    if ($isCorr) {
        $map = ksefLineKursMap($db, $invoice);
        if ($map['missing']) {
            if (empty($invoice->lines)) {
                $invoice->fetch_lines();
            }
            $label = '#' . (int) $map['missing'][0];
            foreach ((array) $invoice->lines as $l) {
                if ((int) $l->id === (int) $map['missing'][0]) {
                    $label = dol_trunc(trim(dol_string_nohtmltag((string) (!empty($l->product_label) ? $l->product_label : ($l->desc ?? '')))), 60);
                }
            }
            return $fail('line_rate_missing', $tr('KSEF_LineRateMissing', array($label)));
        }
    }
    return $out;
}

/**
 * @brief Imported row rates
 * @param DoliDB $db
 * @param int $invoiceId
 * @param array $rows Parsed lines
 * @param array $lineOfRow Row => line id
 * @return int Lines written, -1 error
 */
function ksefLineKursFromXml($db, $invoiceId, $rows, $lineOfRow)
{
    $id = (int) $invoiceId;
    if ($id <= 0 || !$lineOfRow || !ksefLineKursStorable($db)) {
        return 0;
    }
    $rates = array();
    foreach ((array) $rows as $r) {
        $raw = trim((string) ($r['kurs_waluty'] ?? ''));
        $n = (int) ($r['line_num'] ?? 0);
        if ($n > 0 && $raw !== '' && is_numeric($raw) && (float) $raw > 0) {
            $rates[$n] = round((float) $raw, 6);
        }
    }
    $distinct = array();
    foreach ($rates as $k) {
        $distinct[number_format($k, 6, '.', '')] = true;
    }
    if (count($distinct) < 2) {
        return 0;
    }
    $P = MAIN_DB_PREFIX;
    $res = $db->query("SELECT d.rowid, e.rowid AS erow FROM " . $P . "facturedet AS d LEFT JOIN " . $P . "facturedet_extrafields AS e ON e.fk_object = d.rowid WHERE d.fk_facture = " . $id);
    if (!$res) {
        return -1;
    }
    $own = array();
    while ($o = $db->fetch_object($res)) {
        $own[(int) $o->rowid] = (int) $o->erow;
    }
    $n = 0;
    foreach ($lineOfRow as $row => $lid) {
        if (!isset($rates[(int) $row]) || !array_key_exists((int) $lid, $own)) {
            continue;
        }
        $val = "'" . $db->escape(number_format($rates[(int) $row], 6, '.', '')) . "'";
        $sql = $own[(int) $lid] > 0 ? "UPDATE " . $P . "facturedet_extrafields SET ksef_kurs = " . $val . " WHERE rowid = " . $own[(int) $lid]
            : "INSERT INTO " . $P . "facturedet_extrafields (fk_object, ksef_kurs) VALUES (" . (int) $lid . ", " . $val . ")";
        if (!$db->query($sql)) {
            return -1;
        }
        $n++;
    }
    return $n;
}

/**
 * @brief PLN from foreign
 * @param DoliDB $db
 * @param int $invoiceId
 * @param array $opt
 * @return array
 */
function ksefDerivePlnFromForeign($db, $invoiceId, $opt = array())
{
    global $conf;
    $P = MAIN_DB_PREFIX;
    $id = (int) $invoiceId;
    $out = array('result' => 0, 'skip' => '', 'kurs' => 0.0, 'lines' => array(), 'summary' => array(), 'error' => '');
    $dry = !empty($opt['dry_run']);
    $res = $db->query("SELECT rowid, fk_soc, type, fk_facture_source, multicurrency_code, multicurrency_tx FROM " . $P . "facture WHERE rowid = " . $id);
    $h = $res ? $db->fetch_object($res) : null;
    if (!$h) {
        $out['result'] = -1;
        $out['error'] = $res ? 'invoice ' . $id . ' not found' : $db->lasterror();
        return $out;
    }
    $h->id = $id;
    $h->db = $db;
    if (empty($h->multicurrency_code) || $h->multicurrency_code === $conf->currency || (float) $h->multicurrency_tx <= 0) {
        $out['skip'] = 'pln';
        return $out;
    }
    if (ksefInvoiceSentToKsef($db, $id)) {
        $out['skip'] = 'sent';
        if (!$dry || empty($opt['check_sent'])) {
            return $out;
        }
    }
    if (!empty($GLOBALS['ksef_importing_sales']) || (empty($opt['no_sales_check']) && $db->getRow("SELECT rowid FROM " . $P . "ksef_sales WHERE fk_facture = " . $id))) {
        $out['skip'] = 'sales';
        return $out;
    }
    $kurs = round(isset($opt['kurs']) && (float) $opt['kurs'] > 0 ? (float) $opt['kurs'] : ksefInvoiceKurs($h, array('db' => $db)), 6);
    $out['kurs'] = $kurs;
    if ($kurs <= 0) {
        $out['skip'] = 'no_rate';
        return $out;
    }
    $res = $db->query("SELECT rowid, rang, qty, remise_percent, situation_percent, tva_tx, vat_src_code, info_bits, product_type, fk_remise_except, total_ht, total_tva, total_ttc,"
        . " total_localtax1, total_localtax2, multicurrency_subprice, multicurrency_total_ht, multicurrency_total_tva, multicurrency_total_ttc FROM " . $P . "facturedet"
        . " WHERE fk_facture = " . $id . " ORDER BY rang, rowid");
    if (!$res) {
        $out['result'] = -1;
        $out['error'] = $db->lasterror();
        return $out;
    }
    $lines = array();
    while ($o = $db->fetch_object($res)) {
        $lines[(int) $o->rowid] = $o;
    }
    $skipLine = 'ksefFxSkipLine';
    $isDeposit = function ($l) {
        return (int) $l->fk_remise_except > 0 && abs((float) $l->multicurrency_total_ttc) >= 0.005;
    };
    if (!array_filter($lines, function ($l) use ($skipLine) {
        return !$skipLine($l);
    })) {
        $out['skip'] = 'no_lines';
        return $out;
    }
    if (isset($opt['country']) && (string) $opt['country'] !== '') {
        $country = (string) $opt['country'];
    } else {
        require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
        $soc = new Societe($db);
        $country = $soc->fetch((int) $h->fk_soc) > 0 ? ksefInferCountryCode($soc) : 'PL';
    }
    $gross = ksefInvoiceGrossMethod($db, $id);
    $sumOpt = array('kurs' => $kurs, 'country' => $country, 'eu' => ksefIsEuCountry($country), 'gross' => $gross, 'skip' => $skipLine);

    $mc = array();
    foreach ($lines as $lid => $l) {
        $mc[$lid] = array('mtva' => (float) $l->multicurrency_total_tva, 'mttc' => (float) $l->multicurrency_total_ttc);
    }
    // core < 24
    $creditIds = array_filter(array_map(function ($l) {
        return (int) $l->fk_remise_except;
    }, $lines));
    if ($creditIds) {
        $res = $db->query("SELECT rowid, multicurrency_amount_ht, multicurrency_amount_tva, multicurrency_amount_ttc FROM " . $P . "societe_remise_except WHERE rowid IN (" . implode(',', $creditIds) . ")");
        $credits = array();
        while ($res && ($o = $db->fetch_object($res))) {
            $credits[(int) $o->rowid] = $o;
        }
        foreach ($lines as $lid => $l) {
            $c = $credits[(int) $l->fk_remise_except] ?? null;
            if ($c && abs((float) $c->multicurrency_amount_ttc) >= 0.005 && abs((float) $l->multicurrency_total_ht + (float) $c->multicurrency_amount_ht) < 0.005
                && abs((float) $l->multicurrency_total_tva + (float) $c->multicurrency_amount_tva) >= 0.005) {
                $mc[$lid] = array('mtva' => -(float) $c->multicurrency_amount_tva, 'mttc' => -(float) $c->multicurrency_amount_ttc);
            }
        }
    }
    // art. 106f(1)(3)
    $depositDoc = (int) $h->type === Facture::TYPE_DEPOSIT;
    if (!$depositDoc && (int) $h->fk_facture_source > 0 && in_array((int) $h->type, array(Facture::TYPE_REPLACEMENT, Facture::TYPE_CREDIT_NOTE), true)) {
        $root = ksefCorrectionRoot($db, $h);
        $depositDoc = $root && (int) $root->type === Facture::TYPE_DEPOSIT;
    }
    if (!$gross && !$depositDoc && getDolGlobalString('MAIN_ROUNDOFTOTAL_NOT_TOTALOFROUND') === '1') {
        // 23/22 share P_14_1
        $byRate = array();
        $isEuBuyer = ksefIsEuCountry($country);
        foreach ($lines as $lid => $l) {
            // art. 106f(3)
            if ($skipLine($l) || (float) $l->tva_tx <= 0 || $isDeposit($l)) {
                continue;
            }
            $key = ksefForeignRateKey($l, $country, $isEuBuyer);
            if (!preg_match('/^\d+$/', $key)) {
                continue;
            }
            $r = ksefForeignRateBucket($key);
            if (!isset($byRate[$r])) {
                $byRate[$r] = array('net' => 0.0, 'vat' => 0.0, 'want' => 0.0, 'last' => 0);
            }
            $byRate[$r]['net'] += (float) $l->multicurrency_total_ht;
            $byRate[$r]['vat'] += (float) $l->multicurrency_total_tva;
            $byRate[$r]['want'] += (float) $l->multicurrency_total_ht * (float) $l->tva_tx / 100;
            $byRate[$r]['last'] = $lid;
        }
        foreach ($byRate as $r => $x) {
            $diff = round(round($x['vat'], 2) - round($x['want'], 2), 2);
            if (abs($diff) >= 0.005 && $x['last']) {
                $mc[$x['last']]['mtva'] = round($mc[$x['last']]['mtva'] - $diff, 2);
                $mc[$x['last']]['mttc'] = round((float) $lines[$x['last']]->multicurrency_total_ht + $mc[$x['last']]['mtva'], 2);
            }
        }
    }
    $view = array();
    foreach ($lines as $lid => $l) {
        $v = clone $l;
        $v->multicurrency_total_tva = $mc[$lid]['mtva'];
        $v->multicurrency_total_ttc = $mc[$lid]['mttc'];
        $view[$lid] = $v;
    }
    $rules = ksefFxRulesOf($db, $id);
    $sumOpt['method'] = $rules['vat'];
    $sumOpt['deposit'] = $depositDoc;
    $sumOpt['multi'] = $rules['multi'];
    if ((int) $h->type === Facture::TYPE_DEPOSIT) {
        $dep = new Facture($db);
        if ($dep->fetch($id) > 0) {
            $parts = ksefDepositPaymentParts($db, $dep, array('exclude_payment' => (int) ($opt['exclude_payment'] ?? 0), 'cached_only' => !empty($opt['dry_run'])));
            $noRate = array_filter($parts, function ($p) {
                return (float) $p['kurs'] <= 0;
            });
            if ($parts && $noRate) {
                dol_syslog('ksefDerivePlnFromForeign deposit ' . $id . ': no NBP rate for a payment day, PLN left at the invoice rate', LOG_WARNING);
            } elseif ($parts) {
                $sumOpt['pay_parts'] = $parts;
            }
        }
    } elseif ($depositDoc) {
        $root = ksefCorrectionRoot($db, $h);
        $parts = $root ? ksefDepositKorParts($db, $root, array('cached_only' => !empty($opt['dry_run']), 'exclude_payment' => (int) ($opt['exclude_payment'] ?? 0))) : array();
        // FA3Builder weights
        $plan = null;
        if ($root) {
            $corrInv = new Facture($db);
            $plan = $corrInv->fetch($id) > 0 ? ksefDepositKorPlan($db, $corrInv, array('cached_only' => !empty($opt['dry_run']))) : null;
        }
        if ($plan !== null) {
            $parts = $plan['own'];
        }
        $noRate = array_filter($parts, function ($p) {
            return (float) $p['kurs'] <= 0;
        });
        if ($parts && $noRate) {
            dol_syslog('ksefDerivePlnFromForeign deposit correction ' . $id . ': no NBP rate for a payment day, PLN left at the invoice rate', LOG_WARNING);
        } elseif ($parts) {
            $sumOpt['pay_parts'] = $parts;
            if ($plan !== null && $plan['before']) {
                $sumOpt['pay_parts_before'] = $plan['before'];
            }
        }
    } else {
        $lineMap = ksefLineKursMap($db, $h);
        if ($lineMap['active']) {
            $sumOpt['line_kurs'] = $lineMap['resolve'];
        }
    }
    if ($rules['kor'] === 'change' && (int) $h->type === Facture::TYPE_REPLACEMENT && (int) $h->fk_facture_source > 0) {
        $override = ksefFxKorChangeW($db, $id, $view, $sumOpt, $kurs);
        if ($override !== null) {
            $sumOpt['w_override'] = $override;
        }
    }
    $sum = ksefForeignRateSummary($view, $sumOpt);
    $out['summary'] = $sum;

    $new = ksefForeignBucketPlnLines($sum, $view, $kurs, $isDeposit, $sumOpt['line_kurs'] ?? null);
    foreach ($new as $lid => $d) {
        $l = $lines[$lid];
        $ttc = round($d['ht'] + $d['tva'] + (float) $l->total_localtax1 + (float) $l->total_localtax2, 2);
        $mtvaMoved = abs($mc[$lid]['mtva'] - (float) $l->multicurrency_total_tva) >= 0.005;
        if (abs($d['ht'] - (float) $l->total_ht) < 0.005 && abs($d['tva'] - (float) $l->total_tva) < 0.005 && abs($ttc - (float) $l->total_ttc) < 0.005 && !$mtvaMoved) {
            continue;
        }
        $out['lines'][$lid] = array('ht' => $d['ht'], 'tva' => $d['tva'], 'ttc' => $ttc,
            'old' => array('ht' => (float) $l->total_ht, 'tva' => (float) $l->total_tva, 'ttc' => (float) $l->total_ttc, 'mtva' => (float) $l->multicurrency_total_tva));
        if ($mtvaMoved) {
            $out['lines'][$lid]['mtva'] = $mc[$lid]['mtva'];
            $out['lines'][$lid]['mttc'] = $mc[$lid]['mttc'];
        }
    }
    if ($dry) {
        $out['result'] = $out['lines'] ? 1 : 0;
        return $out;
    }

    $num = function ($v) {
        return (string) price2num((float) $v, 'MT');
    };
    dol_include_once('/ksef/class/ksef_nbp_currency_rate.class.php');
    $pre = (new KsefNbpCurrencyRate($db))->amountsInvariant($id);
    $preMc = array_values(array_diff((array) $pre['mc'], array_keys(array_filter($out['lines'], function ($d) {
        return isset($d['mtva']);
    }))));
    if ($preMc) {
        dol_syslog('ksefDerivePlnFromForeign invoice ' . $id . ' keeps invoice-currency lines whose gross is not net + VAT: ' . json_encode($preMc), LOG_WARNING);
    }
    $db->begin();
    $err = '';
    foreach ($out['lines'] as $lid => $d) {
        $sql = "UPDATE " . $P . "facturedet SET total_ht = " . $num($d['ht']) . ", total_tva = " . $num($d['tva']) . ", total_ttc = " . $num($d['ttc']);
        if (isset($d['mtva'])) {
            $sql .= ", multicurrency_total_tva = " . $num($d['mtva']) . ", multicurrency_total_ttc = " . $num($d['mttc']);
        }
        if (!$db->query($sql . " WHERE rowid = " . (int) $lid)) {
            $err = 'line ' . (int) $lid . ': ' . $db->lasterror();
            break;
        }
    }
    if ($err === '') {
        $set = array();
        foreach (array('total_ht', 'total_tva', 'total_ttc', 'multicurrency_total_ht', 'multicurrency_total_tva', 'multicurrency_total_ttc') as $col) {
            $set[] = $col . " = (SELECT COALESCE(SUM(d." . $col . "), 0) FROM " . $P . "facturedet AS d WHERE d.fk_facture = " . $id . ")";
        }
        if (!$db->query("UPDATE " . $P . "facture SET " . implode(', ', $set) . " WHERE rowid = " . $id)) {
            $err = 'header: ' . $db->lasterror();
        }
    }
    if ($err === '') {
        $err = ksefForeignPlnInvariant($db, $id, $kurs, $sumOpt, $preMc);
    }
    if ($err !== '') {
        $db->rollback();
        $out['result'] = -1;
        $out['error'] = $err;
        dol_syslog('ksefDerivePlnFromForeign invoice ' . $id . ': ' . $err, LOG_ERR);
        return $out;
    }
    $db->commit();
    $out['result'] = $out['lines'] ? 1 : 0;
    if ($out['lines']) {
        dol_syslog('ksefDerivePlnFromForeign invoice ' . $id . ' at K ' . $kurs . ': ' . count($out['lines']) . ' line(s) derived', LOG_DEBUG);
    }
    return $out;
}

/**
 * @brief Rate line VAT
 * @param object $l Line row
 * @param bool $base Use PLN columns
 * @return float|null Null when not checked
 */
function ksefFxLineVat($l, $base = false)
{
    $rate = (float) $l->tva_tx;
    if (ksefFxSkipLine($l) || $rate <= 0 || ksefMarginSubtype($l) !== '' || (int) $l->fk_remise_except > 0
        || abs((float) $l->total_localtax1) >= 0.005 || abs((float) $l->total_localtax2) >= 0.005) {
        return null;
    }
    $sit = ($l->situation_percent ?? null) !== null ? (float) $l->situation_percent : 100.0;
    $net = (float) ($base ? $l->subprice : $l->multicurrency_subprice) * (float) $l->qty * (1 - (float) $l->remise_percent / 100) * $sit / 100;
    if (abs(round($net, 2) - (float) ($base ? $l->total_ht : $l->multicurrency_total_ht)) >= 0.005) {
        return null;
    }
    // art. 106e(11)
    return round($net * $rate / 100, 2);
}

/**
 * @brief Odd line VAT
 * @param DoliDB $db
 * @param int $invoiceId
 * @return array Line id => amounts
 */
function ksefLineVatOff($db, $invoiceId)
{
    global $conf;
    $P = MAIN_DB_PREFIX;
    $id = (int) $invoiceId;
    $out = array();
    $h = $db->getRow("SELECT rowid, type, fk_statut, fk_facture_source, multicurrency_code, multicurrency_tx FROM " . $P . "facture WHERE rowid = " . $id);
    if (!$h || (int) $h->type === Facture::TYPE_DEPOSIT || getDolGlobalString('MAIN_ROUNDOFTOTAL_NOT_TOTALOFROUND') === '1') {
        return $out;
    }
    if ((int) $h->fk_facture_source > 0 && in_array((int) $h->type, array(Facture::TYPE_REPLACEMENT, Facture::TYPE_CREDIT_NOTE), true)) {
        $h->id = $id;
        $h->db = $db;
        $root = ksefCorrectionRoot($db, $h);
        if ($root && (int) $root->type === Facture::TYPE_DEPOSIT) {
            return $out;
        }
    }
    if (ksefInvoiceGrossMethod($db, $id) || $db->getRow("SELECT rowid FROM " . $P . "ksef_sales WHERE fk_facture = " . $id)) {
        return $out;
    }
    $cur = (string) $h->multicurrency_code;
    $base = $cur === '' || $cur === $conf->currency;
    $res = $db->query("SELECT rowid, rang, description, label, fk_product, qty, subprice, remise_percent, situation_percent, tva_tx, vat_src_code, info_bits, product_type, fk_remise_except,"
        . " total_ht, total_tva, total_ttc, total_localtax1, total_localtax2, multicurrency_subprice, multicurrency_total_ht, multicurrency_total_tva, multicurrency_total_ttc"
        . " FROM " . $P . "facturedet WHERE fk_facture = " . $id . " ORDER BY rang, rowid");
    $n = 0;
    while ($res && ($l = $db->fetch_object($res))) {
        $n++;
        $want = ksefFxLineVat($l, $base);
        $have = (float) ($base ? $l->total_tva : $l->multicurrency_total_tva);
        if ($want === null || abs($want - $have) < 0.005) {
            continue;
        }
        $label = trim(dol_string_nohtmltag((string) ($l->label !== null && $l->label !== '' ? $l->label : $l->description)));
        if ($label === '' && (int) $l->fk_product > 0) {
            $p = $db->getRow("SELECT label FROM " . $P . "product WHERE rowid = " . (int) $l->fk_product);
            $label = $p ? (string) $p->label : '';
        }
        $net = (float) ($base ? $l->total_ht : $l->multicurrency_total_ht);
        $out[(int) $l->rowid] = array('pos' => $n, 'label' => dol_trunc($label, 60), 'currency' => $base ? $conf->currency : $cur, 'base' => $base,
            'rate' => (float) $l->tva_tx, 'net' => $net, 'vat' => $have, 'want' => $want, 'ttc' => round($net + $want, 2));
    }
    return $out;
}

/**
 * @brief Line VAT text
 * @param array $d ksefLineVatOff() entry
 * @return string
 */
function ksefLineVatItem($d)
{
    global $langs;
    $langs->load('ksef@ksef');
    $money = function ($v) use ($d, $langs) {
        return price($v, 0, $langs, 1, -1, 2, $d['currency']);
    };
    return $langs->transnoentitiesnoconv('KSEF_LineVatItem', $langs->transnoentitiesnoconv('KSEF_LineVatLine', $d['pos'], $d['label']), $money($d['net']), $money($d['vat']),
        price($d['rate'], 0, $langs, 0, 0, -1) . '%', $money($d['want']));
}

/**
 * @brief Set line VAT
 * @param DoliDB $db
 * @param int $invoiceId
 * @return array
 */
function ksefLineVatFix($db, $invoiceId)
{
    $P = MAIN_DB_PREFIX;
    $id = (int) $invoiceId;
    $num = function ($v) {
        return (string) price2num((float) $v, 'MT');
    };
    $off = ksefLineVatOff($db, $id);
    if (!$off) {
        return array('result' => 'skipped', 'detail' => '');
    }
    $db->begin();
    $err = '';
    foreach ($off as $lid => $d) {
        if ($d['base']) {
            $sql = "UPDATE " . $P . "facturedet SET total_tva = " . $num($d['want']) . ", total_ttc = " . $num($d['ttc'])
                . ", multicurrency_total_tva = CASE WHEN ABS(multicurrency_total_ht - total_ht) < 0.005 THEN " . $num($d['want']) . " ELSE multicurrency_total_tva END"
                . ", multicurrency_total_ttc = CASE WHEN ABS(multicurrency_total_ht - total_ht) < 0.005 THEN " . $num($d['ttc']) . " ELSE multicurrency_total_ttc END";
        } else {
            $sql = "UPDATE " . $P . "facturedet SET multicurrency_total_tva = " . $num($d['want']) . ", multicurrency_total_ttc = " . $num($d['ttc']);
        }
        if (!$db->query($sql . " WHERE rowid = " . (int) $lid)) {
            $err = $db->lasterror();
            break;
        }
    }
    if ($err === '') {
        $set = array();
        foreach (array('total_ht', 'total_tva', 'total_ttc', 'multicurrency_total_ht', 'multicurrency_total_tva', 'multicurrency_total_ttc') as $col) {
            $set[] = $col . " = (SELECT COALESCE(SUM(d." . $col . "), 0) FROM " . $P . "facturedet AS d WHERE d.fk_facture = " . $id . ")";
        }
        if (!$db->query("UPDATE " . $P . "facture SET " . implode(', ', $set) . " WHERE rowid = " . $id)) {
            $err = $db->lasterror();
        }
    }
    if ($err === '' && !reset($off)['base']) {
        $d = ksefDerivePlnFromForeign($db, $id);
        if ($d['result'] < 0) {
            $err = $d['error'];
        }
    }
    if ($err !== '') {
        $db->rollback();
        dol_syslog('ksefLineVatFix invoice ' . $id . ': ' . $err, LOG_ERR);
        return array('result' => 'failed', 'detail' => $err);
    }
    $db->commit();
    return array('result' => 'ok', 'detail' => '');
}

/**
 * @brief PLN per line
 * @param array $sum Rate summary
 * @param array $lines
 * @param float $kurs
 * @param callable $isDeposit
 * @param callable|null $lineKurs
 * @return array Line id => amounts
 */
function ksefForeignBucketPlnLines($sum, $lines, $kurs, $isDeposit, $lineKurs = null)
{
    $new = array();
    foreach ($sum['buckets'] as $b) {
        $target = $anyLast = null;
        $nets = $vats = 0.0;
        foreach ($b['lines'] as $lid) {
            $l = $lines[$lid];
            $lk = is_callable($lineKurs) ? (float) call_user_func($lineKurs, $l) : 0.0;
            $lk = $lk > 0 ? $lk : $kurs;
            $new[$lid] = array('ht' => round((float) $l->multicurrency_total_ht * $lk, 2), 'tva' => round((float) $l->multicurrency_total_tva * $lk, 2));
            $nets += $new[$lid]['ht'];
            $vats += $new[$lid]['tva'];
            $anyLast = $lid;
            if (!$isDeposit($l)) {
                $target = $lid;
            }
        }
        $target = $target !== null ? $target : $anyLast;
        if ($target !== null) {
            $new[$target]['ht'] = round($new[$target]['ht'] + round($b['net_w'] - $nets, 2), 2);
            $new[$target]['tva'] = round($new[$target]['tva'] + round($b['vat_w'] - $vats, 2), 2);
        }
    }
    return $new;
}

/**
 * @brief Seller PLN VAT
 * @param KsefIncoming $incoming
 * @return array Disagreeing buckets
 */
function ksefIncomingSellerPlnCheck($incoming)
{
    global $conf;
    $out = array();
    $code = strtoupper((string) $incoming->currency);
    if ($code === '' || $code === strtoupper((string) $conf->currency) || empty($incoming->fa3_xml) || strpos((string) $incoming->invoice_type, 'KOR') === 0) {
        return $out;
    }
    dol_include_once('/ksef/class/fa3_parser.class.php');
    $parsed = (new FA3Parser($incoming->db))->parse((string) $incoming->fa3_xml);
    $kurs = round(ksefParsedKurs($parsed), 6);
    if ($kurs <= 0) {
        return $out;
    }
    $bucketKurs = array();
    if ((string) ($parsed['exchange_rate']['source'] ?? '') === 'line_weighted') {
        $acc = array();
        foreach ((array) ($parsed['lines'] ?? array()) as $l) {
            $raw = trim((string) ($l['kurs_waluty'] ?? ''));
            $vr = trim((string) ($l['vat_rate'] ?? ''));
            if ($raw === '' || !is_numeric($raw) || (float) $raw <= 0 || $vr === '') {
                continue;
            }
            $b = ksefForeignRateBucket(is_numeric($vr) ? (string) (int) round((float) $vr) : $vr);
            $w = abs($l['net_amount'] !== null ? (float) $l['net_amount'] : (float) ($l['gross_amount'] ?? 0));
            if (!isset($acc[$b])) {
                $acc[$b] = array(0.0, 0.0);
            }
            $acc[$b][0] += $w * (float) $raw;
            $acc[$b][1] += $w;
        }
        foreach ($acc as $b => $a) {
            if ($a[1] > 0) {
                $bucketKurs[$b] = round($a[0] / $a[1], 6);
            }
        }
    }
    $cands = ksefIncomingSellerPlnCandidates($parsed, $kurs);
    foreach ((array) ($parsed['vat_summary'] ?? array()) as $key => $x) {
        if (!isset($x['vat_pln']) || $x['vat_pln'] === null || !in_array(ksefForeignRateBucket((string) $key), array('1', '2', '3', '4'), true)) {
            continue;
        }
        $stated = round((float) $x['vat_pln'], 2);
        $bk = $bucketKurs[ksefForeignRateBucket((string) $key)] ?? $kurs;
        $calc = round(round((float) $x['vat'], 2) * $bk, 2);
        $same = array();
        foreach ($cands[ksefForeignRateBucket((string) $key)] ?? array() as $c) {
            if (abs($c['vat'] - round((float) $x['vat'], 2)) < 0.005) {
                $same[] = $c['w'];
            }
        }
        if ($same) {
            $hit = false;
            foreach ($same as $w) {
                $hit = $hit || abs($stated - $w) < 0.005;
            }
            if ($hit) {
                continue;
            }
            $calc = $same[0];
        }
        if (abs($stated - $calc) >= 0.005) {
            $out[(string) $key] = array('stated' => $stated, 'vat' => round((float) $x['vat'], 2), 'kurs' => $bk, 'calc' => $calc);
        }
    }
    return $out;
}

/**
 * @brief Seller VAT options
 * @param array|null $parsed
 * @param float $kurs
 * @return array
 */
function ksefIncomingSellerPlnCandidates($parsed, $kurs)
{
    $out = array();
    if (!is_array($parsed) || (string) ($parsed['exchange_rate']['source'] ?? '') !== 'line_weighted' || $kurs <= 0) {
        return $out;
    }
    $rows = array();
    foreach ((array) ($parsed['lines'] ?? array()) as $i => $l) {
        $raw = trim((string) ($l['kurs_waluty'] ?? ''));
        $vr = trim((string) ($l['vat_rate'] ?? ''));
        if ($raw === '' || !is_numeric($raw) || (float) $raw <= 0 || $vr === '' || !is_numeric($vr) || $l['net_amount'] === null) {
            return array();
        }
        $r = (float) $vr;
        $net = (float) $l['net_amount'];
        $vat = $l['vat_amount'] !== null ? (float) $l['vat_amount'] : null;
        $rows[] = (object) array('rowid' => $i + 1, 'tva_tx' => $r, 'k' => (float) $raw, 'net' => $net, 'vat' => $vat);
    }
    $keyOf = function ($l) {
        return ksefPolishRateKey($l->tva_tx, (string) (float) $l->tva_tx);
    };
    $allVat = true;
    foreach ($rows as $o) {
        $allVat = $allVat && $o->vat !== null;
    }
    $ways = $allVat ? array('stated', 'line', 'rate') : array('line', 'rate');
    foreach ($ways as $way) {
        $lines = array();
        foreach ($rows as $o) {
            $v = $way === 'stated' ? $o->vat : $o->net * $o->tva_tx / 100;
            $lines[] = (object) array('rowid' => $o->rowid, 'tva_tx' => $o->tva_tx, 'k' => $o->k, 'multicurrency_total_ht' => $o->net,
                'multicurrency_total_tva' => $way === 'line' ? round($v, 2) : $v, 'multicurrency_total_ttc' => $o->net + $v);
        }
        foreach (array('once', 'per_part') as $multi) {
            $sum = ksefForeignRateSummary($lines, array('kurs' => $kurs, 'key' => $keyOf, 'round_total' => $way === 'rate', 'multi' => $multi,
                'line_kurs' => function ($l) {
                    return $l->k;
                }));
            foreach ($sum['buckets'] as $b => $x) {
                if (!empty($x['w']) && $x['vat_w'] !== null) {
                    $out[(string) $b][] = array('vat' => round((float) $x['vat'], 2), 'w' => round((float) $x['vat_w'], 2));
                }
            }
        }
    }
    return $out;
}

/**
 * @brief Seller VAT warning
 * @param array $rows
 * @param string $currency
 * @return string
 */
function ksefIncomingSellerPlnText($rows, $currency)
{
    global $langs, $conf;
    if (!$rows) {
        return '';
    }
    $langs->load('ksef@ksef');
    $money = function ($v, $cur) use ($langs) {
        return str_replace("\xc2\xa0", ' ', html_entity_decode(price($v, 0, $langs, 1, -1, 2, $cur), ENT_QUOTES, 'UTF-8'));
    };
    $parts = array();
    foreach ($rows as $rate => $r) {
        $frac = rtrim(substr(number_format((float) $r['kurs'], 6, '.', ''), -6), '0');
        $parts[] = $langs->transnoentitiesnoconv('KSEF_ImportSellerPlnVatRate', $rate, $money($r['stated'], $conf->currency), $money($r['vat'], $currency),
            price((float) $r['kurs'], 0, $langs, 0, strlen($frac), strlen($frac)), $money($r['calc'], $conf->currency));
    }
    return str_replace("\xc2\xa0", ' ', $langs->transnoentitiesnoconv('KSEF_ImportSellerPlnVat', implode('; ', $parts)));
}

/**
 * @brief Parsed exchange rate
 * @param array|null $parsed
 * @return float 0 if unusable
 */
function ksefParsedKurs($parsed)
{
    $raw = is_array($parsed) ? trim((string) ($parsed['exchange_rate']['rate'] ?? '')) : '';
    return ($raw !== '' && is_numeric($raw) && (float) $raw > 0) ? (float) $raw : 0.0;
}

/**
 * @brief Incoming rate state
 * @param KsefIncoming $incoming
 * @param float $tx 0: look up
 * @param int $date 0: today
 * @return array
 */
function ksefIncomingKursState($incoming, $tx = 0.0, $date = 0)
{
    global $conf;
    $out = array('state' => '', 'raw' => '', 'used' => 0.0, 'from' => '');
    $code = strtoupper((string) $incoming->currency);
    if ($code === '' || $code === 'PLN' || $code === strtoupper((string) $conf->currency) || empty($incoming->fa3_xml) || strpos((string) $incoming->invoice_type, 'KOR') === 0) {
        return $out;
    }
    dol_include_once('/ksef/class/fa3_parser.class.php');
    $parsed = (new FA3Parser($incoming->db))->parse((string) $incoming->fa3_xml);
    $raw = $parsed ? trim((string) ($parsed['exchange_rate']['rate'] ?? '')) : '';
    if (ksefParsedKurs($parsed) > 0) {
        if (!empty($parsed['exchange_rate']['mixed'])) {
            $out['state'] = 'mixed';
            $out['used'] = round(ksefParsedKurs($parsed), 6);
            $out['from'] = (string) $parsed['exchange_rate']['source'];
            $out['rates'] = array();
            foreach ($parsed['exchange_rate']['mixed'] as $m) {
                $out['rates'][] = is_float($m) ? $m : dol_trunc(preg_replace('/[^0-9A-Za-z.,+\- "]/', '?', (string) $m), 20, 'right', 'UTF-8', 1);
            }
        }
        return $out;
    }
    // parser drops unusable
    $written = $raw;
    if ($written === '') {
        $dom = new DOMDocument();
        if (@$dom->loadXML((string) $incoming->fa3_xml)) {
            $nodes = (new DOMXPath($dom))->query("//*[local-name()='KursWaluty' or local-name()='KursWalutyZ' or local-name()='KursWalutyZW']");
            if ($nodes && $nodes->length) {
                $written = trim((string) $nodes->item(0)->textContent);
                $written = $written === '' ? '""' : $written;
            }
        }
    }
    $out['state'] = $written === '' ? 'missing' : 'unusable';
    // plain chars only
    $out['raw'] = dol_trunc(preg_replace('/[^0-9A-Za-z.,+\- "]/', '?', $written), 20, 'right', 'UTF-8', 1);
    $vatRate = round((float) $incoming->getExchangeRate(), 6);
    if ($vatRate > 0) {
        $out['used'] = $vatRate;
        $out['from'] = 'vat';
        return $out;
    }
    $out['from'] = 'dolibarr';
    if ((float) $tx <= 0 && isModEnabled('multicurrency')) {
        require_once DOL_DOCUMENT_ROOT . '/multicurrency/class/multicurrency.class.php';
        $found = MultiCurrency::getIdAndTxFromCode($incoming->db, $code, (int) $date > 0 ? (int) $date : dol_now());
        $tx = is_array($found) ? (float) ($found[1] ?? 0) : 0.0;
    }
    $out['used'] = (float) $tx > 0 && abs((float) $tx - 1) > 1e-12 ? round(1 / (float) $tx, 6) : 0.0;
    return $out;
}

/**
 * @brief Rate state warning
 * @param array $state
 * @param string $currency
 * @return string
 */
function ksefIncomingKursText($state, $currency)
{
    global $langs;
    if (empty($state['state'])) {
        return '';
    }
    $langs->load('ksef@ksef');
    $rate = '';
    if ((float) $state['used'] > 0) {
        $frac = rtrim(substr(number_format((float) $state['used'], 6, '.', ''), -6), '0');
        $rate = price((float) $state['used'], 0, $langs, 0, strlen($frac), strlen($frac));
    }
    if ($state['state'] === 'mixed') {
        $given = array();
        foreach ((array) ($state['rates'] ?? array()) as $r) {
            if (is_float($r)) {
                $f = rtrim(substr(number_format($r, 6, '.', ''), -6), '0');
                $given[] = price($r, 0, $langs, 0, strlen($f), strlen($f));
            } else {
                $given[] = '"' . $r . '"';
            }
        }
        $keys = array('line' => 'KSEF_ImportRateUsedLines', 'line_weighted' => 'KSEF_ImportRateUsedWeighted', 'deposit' => 'KSEF_ImportRateUsedDeposit',
            'payment' => 'KSEF_ImportRateUsedPayment', 'payment_weighted' => 'KSEF_ImportRateUsedPayments');
        $used = $langs->transnoentitiesnoconv($keys[$state['from']] ?? 'KSEF_ImportRateUsedLines', $rate);
        return str_replace("\xc2\xa0", ' ', $langs->transnoentitiesnoconv('KSEF_ImportRateMixed', $currency, implode('; ', $given), $used));
    }
    if ($state['from'] === 'vat') {
        $used = $langs->transnoentitiesnoconv('KSEF_ImportRateUsedVat', $rate);
    } else {
        $used = $langs->transnoentitiesnoconv($rate !== '' ? 'KSEF_ImportRateUsedDolibarr' : 'KSEF_ImportRateUsedDolibarrNoRate', $rate);
    }
    if ($state['state'] === 'unusable') {
        $text = $langs->transnoentitiesnoconv('KSEF_ImportRateUnusable', $currency, $state['raw'], $used);
    } elseif ($state['from'] === 'vat') {
        $text = $langs->transnoentitiesnoconv('KSEF_ImportRateNoLines', $currency, $used);
    } else {
        $text = $langs->transnoentitiesnoconv('KSEF_ImportRateNone', $currency, $used);
    }
    return str_replace("\xc2\xa0", ' ', $text);
}

/**
 * @brief Rate warning
 * @param KsefIncoming $incoming
 * @param FactureFournisseur $facture
 * @return string
 */
function ksefIncomingKursWarning($incoming, $facture)
{
    if ((string) $incoming->import_status !== 'IMPORTED' || (int) $incoming->fk_facture_fourn <= 0 || (int) $facture->id !== (int) $incoming->fk_facture_fourn) {
        return '';
    }
    return ksefIncomingKursText(ksefIncomingKursState($incoming, (float) $facture->multicurrency_tx), strtoupper((string) $incoming->currency));
}

/**
 * @brief VAT warning
 * @param KsefIncoming $incoming
 * @param FactureFournisseur $facture
 * @return string
 */
function ksefIncomingSellerPlnWarning($incoming, $facture)
{
    if ((string) $incoming->import_status !== 'IMPORTED' || (int) $incoming->fk_facture_fourn <= 0 || (int) $facture->id !== (int) $incoming->fk_facture_fourn) {
        return '';
    }
    $since = (int) getDolGlobalString('KSEF_NBP_MODE_MIGRATED');
    $at = is_numeric($incoming->import_date) ? (int) $incoming->import_date : (int) strtotime((string) $incoming->import_date);
    if ($since > 0 && ($at <= 0 || $at < $since)) {
        return '';
    }
    $rows = ksefIncomingSellerPlnCheck($incoming);
    if ($rows) {
        $cmp = ksefCompareIncomingTotals($incoming, $facture, true);
        $rows = array_diff_key($rows, (array) $cmp['pln']);
    }
    return ksefIncomingSellerPlnText($rows, strtoupper((string) $incoming->currency));
}

/**
 * @brief Set imported PLN
 * @param DoliDB $db
 * @param FactureFournisseur|Facture $facture
 * @param KsefIncoming|null $incoming Null: typed
 * @param array $opt
 * @return array
 */
function ksefIncomingPlnFromXml($db, $facture, $incoming, $opt = array())
{
    $out = array('result' => 0, 'kurs' => 0.0, 'error' => '');
    if (!ksefInvoiceIsForeign($facture) || (int) $facture->type === 2 || ((int) $facture->type === 1 && !empty($facture->fk_facture_source))
        || ($incoming && (strpos((string) $incoming->invoice_type, 'KOR') === 0 || empty($incoming->fa3_xml)))) {
        return $out;
    }
    $parsed = null;
    $kurs = 0.0;
    if ($incoming) {
        dol_include_once('/ksef/class/fa3_parser.class.php');
        $parsed = (new FA3Parser($db))->parse((string) $incoming->fa3_xml);
        $kurs = round(ksefParsedKurs($parsed), 6);
    }
    if ($kurs <= 0 && !empty($opt['kurs']) && (float) $opt['kurs'] > 0) {
        $kurs = round((float) $opt['kurs'], 6);
    }
    if ($kurs <= 0 && $incoming) {
        $kurs = round((float) $incoming->getExchangeRate(), 6);
    }
    if ($kurs <= 0 && (float) $facture->multicurrency_tx > 0) {
        $kurs = round(1 / (float) $facture->multicurrency_tx, 6);
    }
    $out['kurs'] = $kurs;
    if ($kurs <= 0) {
        return $out;
    }
    $P = MAIN_DB_PREFIX;
    $id = (int) $facture->id;
    $k = ksefInvoiceKind($facture);
    $res = $db->query("SELECT rowid, tva_tx, vat_src_code, info_bits, product_type, fk_remise_except, total_ht, " . $k['line_tva'] . " AS total_tva, total_ttc, total_localtax1, total_localtax2,"
        . " multicurrency_total_ht, multicurrency_total_tva, multicurrency_total_ttc FROM " . $P . $k['line_table'] . " WHERE " . $k['line_fk'] . " = " . $id . " ORDER BY rang, rowid");
    if (!$res) {
        $out['result'] = -1;
        $out['error'] = $db->lasterror();
        return $out;
    }
    $lines = array();
    while ($o = $db->fetch_object($res)) {
        $lines[(int) $o->rowid] = $o;
    }
    $keyOf = function ($l) {
        return ksefPolishRateKey($l->tva_tx, (string) (float) $l->tva_tx);
    };
    $sum = ksefForeignRateSummary($lines, array('kurs' => $kurs, 'key' => $keyOf, 'skip' => 'ksefFxSkipLine'));
    foreach ((array) ($parsed['vat_summary'] ?? array()) as $key => $x) {
        $b = ksefForeignRateBucket((string) $key);
        $still = empty($opt['match']) || (isset($sum['buckets'][$b]) && abs(round((float) $sum['buckets'][$b]['vat'], 2) - round((float) ($x['vat'] ?? 0), 2)) < 0.005);
        if ($still && isset($sum['buckets'][$b]) && !empty($sum['buckets'][$b]['w']) && isset($x['vat_pln']) && $x['vat_pln'] !== null) {
            $sum['buckets'][$b]['vat_w'] = round((float) $x['vat_pln'], 2);
            $sum['buckets'][$b]['vat_stated'] = true;
        }
    }
    $bucketKurs = ksefIncomingBucketKurs($parsed, $sum);
    foreach ($bucketKurs as $b => $bk) {
        $sum['buckets'][$b]['net_w'] = $bk['net_w'];
        // art. 86(2)(1)
        if (empty($sum['buckets'][$b]['vat_stated']) && $sum['buckets'][$b]['vat_w'] !== null) {
            $sum['buckets'][$b]['vat_w'] = round((float) $sum['buckets'][$b]['vat'] * $bk['kurs'], 2);
        }
    }
    $lineBucket = array();
    foreach ($sum['buckets'] as $b => $x) {
        foreach ($x['lines'] as $lid) {
            $lineBucket[$lid] = $b;
        }
    }
    $new = ksefForeignBucketPlnLines($sum, $lines, $kurs, function ($l) {
        return (int) $l->fk_remise_except > 0 && abs((float) $l->multicurrency_total_ttc) >= 0.005;
    }, $bucketKurs ? function ($l) use ($bucketKurs, $lineBucket) {
        $b = $lineBucket[(int) $l->rowid] ?? null;
        return $b !== null && isset($bucketKurs[$b]) ? $bucketKurs[$b]['kurs'] : 0.0;
    } : null);
    $num = function ($v) {
        return (string) price2num((float) $v, 'MT');
    };
    foreach ($new as $lid => $d) {
        $l = $lines[$lid];
        $ttc = round($d['ht'] + $d['tva'] + (float) $l->total_localtax1 + (float) $l->total_localtax2, 2);
        if (abs($d['ht'] - (float) $l->total_ht) < 0.005 && abs($d['tva'] - (float) $l->total_tva) < 0.005 && abs($ttc - (float) $l->total_ttc) < 0.005) {
            continue;
        }
        if (!$db->query("UPDATE " . $P . $k['line_table'] . " SET total_ht = " . $num($d['ht']) . ", " . $k['line_tva'] . " = " . $num($d['tva']) . ", total_ttc = " . $num($ttc) . " WHERE rowid = " . (int) $lid)) {
            $out['result'] = -1;
            $out['error'] = $db->lasterror();
            return $out;
        }
        $out['result']++;
    }
    if ($out['result'] > 0) {
        $set = array();
        $cols = array('total_ht' => 'total_ht', 'total_tva' => $k['line_tva'], 'total_ttc' => 'total_ttc');
        if ($k['element'] === 'invoice_supplier') {
            $cols['tva'] = 'tva';
        }
        foreach ($cols as $col => $lineCol) {
            $set[] = $col . " = (SELECT COALESCE(SUM(d." . $lineCol . "), 0) FROM " . $P . $k['line_table'] . " AS d WHERE d." . $k['line_fk'] . " = " . $id . ")";
        }
        if (!$db->query("UPDATE " . $P . $k['table'] . " SET " . implode(', ', $set) . " WHERE rowid = " . $id)) {
            $out['result'] = -1;
            $out['error'] = $db->lasterror();
        }
    }
    return $out;
}

/**
 * @brief Bucket weighted rates
 * @param array|null $parsed
 * @param array $sum Rate summary
 * @return array
 */
function ksefIncomingBucketKurs($parsed, $sum)
{
    $out = array();
    if (!is_array($parsed) || (string) ($parsed['exchange_rate']['source'] ?? '') !== 'line_weighted') {
        return $out;
    }
    $acc = array();
    foreach ((array) ($parsed['lines'] ?? array()) as $l) {
        $vr = trim((string) ($l['vat_rate'] ?? ''));
        if ($vr !== '' && is_numeric($vr)) {
            $b = ksefForeignRateBucket(ksefPolishRateKey($vr, (string) (float) $vr));
        } else {
            $b = '0';
        }
        if (!isset($acc[$b])) {
            $acc[$b] = array('net' => 0.0, 'pln' => 0.0, 'ok' => true);
        }
        $raw = trim((string) ($l['kurs_waluty'] ?? ''));
        if ($raw === '' || !is_numeric($raw) || (float) $raw <= 0 || $l['net_amount'] === null) {
            $acc[$b]['ok'] = false;
            continue;
        }
        $acc[$b]['net'] += (float) $l['net_amount'];
        $acc[$b]['pln'] += (float) $l['net_amount'] * (float) $raw;
    }
    foreach ($acc as $b => $a) {
        if (!$a['ok'] || !isset($sum['buckets'][$b]) || abs($a['net']) < 0.005 || abs(round($a['net'], 2) - round((float) $sum['buckets'][$b]['net'], 2)) >= 0.005) {
            continue;
        }
        $out[$b] = array('kurs' => $a['pln'] / $a['net'], 'net_w' => round($a['pln'], 2));
    }
    return $out;
}

/**
 * @brief PLN lock reasons
 * @param FactureFournisseur $f
 * @return array|false False on error
 */
function ksefSupplierPlnKept($f)
{
    $out = array();
    if (!in_array((int) $f->status, array(FactureFournisseur::STATUS_DRAFT, FactureFournisseur::STATUS_VALIDATED), true) || !empty($f->paye)) {
        $out[] = 'closed';
    }
    $f->error = '';
    $paid = $f->getSommePaiement();
    // -1 maybe sum
    if ((string) $f->error !== '') {
        return false;
    }
    if ((float) price2num((float) $paid, 'MT') != 0) {
        $out[] = 'payments';
    }
    $ventil = $f->getVentilExportCompta();
    if ($ventil < 0) {
        return false;
    }
    if ($ventil > 0) {
        $out[] = 'accounting';
    }
    return $out;
}

/**
 * @brief Re-derive supplier PLN
 * @param DoliDB $db
 * @param int $invoiceId
 * @return array
 */
function ksefSupplierPlnPerBucket($db, $invoiceId)
{
    global $conf;
    $out = array('result' => 0, 'how' => '', 'kurs' => 0.0, 'error' => '');
    $kind = ksefInvoiceKind('invoice_supplier');
    $f = new FactureFournisseur($db);
    if ($f->fetch((int) $invoiceId) <= 0) {
        $out['result'] = -1;
        $out['error'] = 'supplier invoice ' . (int) $invoiceId . ' not found';
        return $out;
    }
    if (!ksefInvoiceIsForeign($f) || (float) $f->multicurrency_tx <= 0) {
        return $out;
    }
    $correction = (int) $f->type === FactureFournisseur::TYPE_CREDIT_NOTE || ((int) $f->type === FactureFournisseur::TYPE_REPLACEMENT && !empty($f->fk_facture_source));
    $incoming = null;
    if (!$correction) {
        dol_include_once('/ksef/class/ksef_incoming.class.php');
        $inc = new KsefIncoming($db);
        if ($inc->fetchBySupplierInvoice((int) $invoiceId) > 0 && !empty($inc->fa3_xml) && !KsefIncoming::isCorrectionType($inc->invoice_type)
            && strtoupper((string) $inc->currency) === strtoupper((string) $f->multicurrency_code)) {
            $incoming = $inc;
        }
    }
    $kurs = 0.0;
    if (!$correction && !$incoming) {
        $tx = (float) $f->multicurrency_tx;
        $res = $db->query("SELECT k.mid FROM " . MAIN_DB_PREFIX . $kind['extrafields'] . "_extrafields AS e"
            . " INNER JOIN " . MAIN_DB_PREFIX . "ksef_nbp_rate_row AS k ON k.entity = " . ((int) $conf->entity) . " AND k.currency_code = '" . $db->escape(strtoupper((string) $f->multicurrency_code)) . "'"
            . " AND k.table_day = e.ksef_kurs_data WHERE e.fk_object = " . (int) $invoiceId . " AND e.ksef_kurs_data IS NOT NULL");
        $o = $res ? $db->fetch_object($res) : null;
        if ($o && (float) $o->mid > 0 && abs($tx - 1 / (float) $o->mid) <= 6e-9) {
            $kurs = round((float) $o->mid, 6);
        } else {
            // avoids customer extrafields
            $stub = (object) array('multicurrency_code' => $f->multicurrency_code, 'multicurrency_tx' => $tx, 'type' => 0, 'fk_facture_source' => 0, 'id' => 0);
            $kurs = round(ksefInvoiceKurs($stub, array('db' => $db, 'own' => true)), 6);
        }
    }
    if (!$correction && !$incoming && $kurs > 0) {
        $kept = ksefSupplierPlnKept($f);
        if ($kept === false) {
            $out['result'] = -1;
            $out['error'] = 'payments or accounting of supplier invoice ' . (int) $invoiceId . ': ' . $f->error;
            return $out;
        }
        if ($kept) {
            $n = ksefDepositLinesAtInvoiceRate($db, (int) $invoiceId, $kind['element']);
            $out['how'] = 'kept';
            $out['kurs'] = $kurs;
            $out['result'] = $n;
            if ($n < 0) {
                $out['error'] = 'deposit lines at the invoice rate: ' . $db->lasterror();
            }
            return $out;
        }
    }
    $stated = ksefSupplierCreditLinesAsStated($db, (int) $invoiceId);
    if ($stated < 0) {
        $out['result'] = -1;
        $out['error'] = 'credit lines as stated: ' . $db->lasterror();
        return $out;
    }
    if ($correction || (!$incoming && $kurs <= 0)) {
        $n = ksefDepositLinesAtInvoiceRate($db, (int) $invoiceId, $kind['element']);
        $out['how'] = 'rate';
        $out['result'] = $n < 0 ? $n : $n + $stated;
        if ($n < 0) {
            $out['error'] = 'deposit lines at the invoice rate: ' . $db->lasterror();
        }
        return $out;
    }
    $set = ksefIncomingPlnFromXml($db, $f, $incoming, array('kurs' => $kurs, 'match' => true));
    $out['how'] = $incoming ? 'xml' : 'derived';
    $out['kurs'] = (float) $set['kurs'];
    $out['result'] = (int) $set['result'] < 0 ? (int) $set['result'] : (int) $set['result'] + $stated;
    $out['error'] = (string) $set['error'];
    return $out;
}

/**
 * @brief Check derived PLN
 * @param DoliDB $db
 * @param int $invoiceId
 * @param float $kurs
 * @param array $sumOpt Summary options
 * @param array $ignoreMc Skipped line ids
 * @return string '' if ok
 */
function ksefForeignPlnInvariant($db, $invoiceId, $kurs, $sumOpt, $ignoreMc = array())
{
    dol_include_once('/ksef/class/ksef_nbp_currency_rate.class.php');
    $bad = (new KsefNbpCurrencyRate($db))->amountsInvariant((int) $invoiceId);
    if ($bad['error'] !== '') {
        return $bad['error'];
    }
    if (array_diff($bad['mc'], $bad['deposit'], $ignoreMc) || $bad['pln'] || $bad['header']) {
        return 'gross is not net + VAT or header not the sum of the lines: ' . json_encode($bad);
    }
    $res = $db->query("SELECT rowid, tva_tx, vat_src_code, info_bits, product_type, total_ht, total_tva, total_ttc, multicurrency_total_ht, multicurrency_total_tva, multicurrency_total_ttc"
        . " FROM " . MAIN_DB_PREFIX . "facturedet WHERE fk_facture = " . (int) $invoiceId . " ORDER BY rang, rowid");
    if (!$res) {
        return $db->lasterror();
    }
    $lines = array();
    while ($o = $db->fetch_object($res)) {
        $lines[(int) $o->rowid] = $o;
    }
    $sum = ksefForeignRateSummary($lines, array('kurs' => $kurs) + $sumOpt);
    $pln = ksefForeignRateSummary($lines, array('pln' => true, 'gross' => false, 'round_total' => false) + $sumOpt);
    $off = array();
    foreach ($sum['buckets'] as $b => $x) {
        $p = $pln['buckets'][$b] ?? array('net' => 0.0, 'vat' => 0.0);
        if (abs($p['vat'] - $x['vat_w']) >= 0.005 || abs($p['net'] - $x['net_w']) >= 0.005) {
            $off[$b] = array('pln' => array($p['net'], $p['vat']), 'want' => array($x['net_w'], $x['vat_w']));
        }
    }
    return $off ? 'PLN per bucket is not the converted foreign amount: ' . json_encode($off) : '';
}

/**
 * @brief Migrate rate mode
 * @param DoliDB $db
 * @param int $entity
 * @return string Log text
 */
function ksefMigrateNbpRateMode($db, $entity)
{
    require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
    $get = function ($name) use ($db, $entity) {
        $res = $db->query("SELECT value FROM " . MAIN_DB_PREFIX . "const WHERE name = '" . $db->escape($name) . "' AND entity = " . (int) $entity);
        $o = $res ? $db->fetch_object($res) : null;
        return $o ? (string) $o->value : null;
    };
    if ($get('KSEF_NBP_MODE_MIGRATED') !== null) {
        return '';
    }
    $out = '';
    if ($get('KSEF_NBP_RATE_MODE') === 'keep_base') {
        dolibarr_set_const($db, 'KSEF_NBP_RATE_MODE', 'keep_foreign', 'chaine', 0, '', $entity);
        dolibarr_set_const($db, 'KSEF_NBP_MODE_NOTICE', '1', 'chaine', 0, '', $entity);
        $out = 'KSEF_NBP_RATE_MODE keep_base switched to keep_foreign (invoice-currency amounts primary), notice on the setup page';
    }
    dolibarr_set_const($db, 'KSEF_NBP_MODE_MIGRATED', (string) dol_now(), 'chaine', 0, '', $entity);
    return $out;
}

/**
 * @brief Migrate all entities
 * @param DoliDB $db
 * @param int $current
 * @return array Entity => log
 */
function ksefMigrateNbpRateModeAll($db, $current)
{
    $entities = array((int) $current => true);
    $res = $db->query("SELECT DISTINCT entity FROM " . MAIN_DB_PREFIX . "const WHERE name IN ('MAIN_MODULE_KSEF', 'KSEF_NBP_RATE_MODE') AND entity > 0 ORDER BY entity");
    while ($res && ($o = $db->fetch_object($res))) {
        $entities[(int) $o->entity] = true;
    }
    $out = array();
    foreach (array_keys($entities) as $entity) {
        if ($entity > 0) {
            $out[$entity] = ksefMigrateNbpRateMode($db, $entity);
        }
    }
    return $out;
}

/**
 * @brief Mode migration notice
 * @param string $selfurl
 * @return string HTML
 */
function ksefNbpModeNotice($selfurl = '')
{
    global $langs, $user;
    if (!getDolGlobalString('KSEF_NBP_MODE_NOTICE') || empty($user->admin)) {
        return '';
    }
    $langs->load('ksef@ksef');
    $selfurl = $selfurl !== '' ? $selfurl : $_SERVER['PHP_SELF'];
    $h = '<div class="warning ksef-nbp-mode-notice">';
    $h .= '<span class="fas fa-info-circle paddingright"></span>' . $langs->trans('KSEF_NbpModeNotice');
    $h .= ' <a class="button smallpaddingimp" href="' . dol_escape_htmltag($selfurl . (strpos($selfurl, '?') !== false ? '&' : '?') . 'action=ksef_hide_nbp_mode_notice&token=' . newToken()) . '">'
        . $langs->trans('KSEF_Help_Dismiss') . '</a>';
    $h .= '</div>';
    return $h;
}

/**
 * @brief Sent PLN VAT
 * @param string $xml
 * @return array|null Null if unreadable
 */
function ksefSentVatW($xml)
{
    $doc = new DOMDocument();
    if ((string) $xml === '' || !@$doc->loadXML((string) $xml)) {
        return null;
    }
    $xp = new DOMXPath($doc);
    $xp->registerNamespace('fa', KSEF_FA3_NAMESPACE);
    $val = function ($q) use ($xp) {
        $n = $xp->query($q);
        return ($n && $n->length && is_numeric(trim($n->item(0)->textContent))) ? (float) trim($n->item(0)->textContent) : null;
    };
    $out = array();
    foreach (array('1', '2', '3', '4') as $b) {
        $w = $val('//fa:Fa/fa:P_14_' . $b . 'W');
        if ($w === null) {
            $v = $val('//fa:Fa/fa:P_14_' . $b);
            if ($v === null) {
                continue;
            }
            $k = $val('//fa:Fa/fa:FaWiersz/fa:KursWaluty');
            $w = $k ? round(round($v, 2) * $k, 2) : 0.0;
        }
        $out[$b] = $w;
    }
    return $out;
}

/**
 * @brief Stated PLN VAT
 * @param DoliDB $db
 * @param int $invoiceId
 * @param int $depth
 * @return array|null Null if unsent
 */
function ksefStatedVatFull($db, $invoiceId, $depth = 0)
{
    $w = ksefSentVatW(ksefLatestSentXml($db, array((int) $invoiceId)));
    if ($w === null || $depth > 20) {
        return null;
    }
    $res = $db->query("SELECT type, fk_facture_source FROM " . MAIN_DB_PREFIX . "facture WHERE rowid = " . (int) $invoiceId);
    $o = $res ? $db->fetch_object($res) : null;
    if (!$o || (int) $o->type !== 1 || (int) $o->fk_facture_source <= 0) {
        return $w;
    }
    $f = new Facture($db);
    if ($f->fetch((int) $invoiceId) <= 0) {
        return null;
    }
    $f->fetch_optionals();
    $before = ksefStatedBeforeVatW($db, $f, $depth + 1);
    if ($before === null) {
        return null;
    }
    foreach ($w as $b => $v) {
        $before[$b] = round(($before[$b] ?? 0.0) + $v, 2);
    }
    return $before;
}

/**
 * @brief Before-state PLN VAT
 * @param DoliDB $db
 * @param Facture $invoice Replacement
 * @param int $depth
 * @return array|null Null if unsent
 */
function ksefStatedBeforeVatW($db, $invoice, $depth = 0)
{
    $srcId = (int) ($invoice->fk_facture_source ?? 0);
    if ($srcId <= 0 || $depth > 20) {
        return null;
    }
    $out = ksefStatedVatFull($db, $srcId, $depth + 1);
    if ($out === null) {
        return null;
    }
    foreach (array_map('intval', (array) (ksefCorrectionState($invoice)['absorb'] ?? array())) as $noteId) {
        $w = ksefSentVatW(ksefLatestSentXml($db, array($noteId)));
        if ($w === null) {
            $note = new Facture($db);
            if ($note->fetch($noteId) <= 0) {
                return null;
            }
            $note->fetch_lines();
            $s = ksefForeignRateSummary((array) $note->lines, array('kurs' => ksefInvoiceKurs($note, array('db' => $db)), 'method' => ksefFxRulesOf($db, $noteId)['vat']));
            $w = array();
            foreach ($s['buckets'] as $b => $x) {
                if ($x['w']) {
                    $w[$b] = (float) $x['vat_w'];
                }
            }
        }
        foreach ($w as $b => $v) {
            $out[$b] = round(($out[$b] ?? 0.0) + $v, 2);
        }
    }
    return $out;
}

/**
 * @brief Validated after migration
 * @param DoliDB $db
 * @param int $invoiceId
 * @return bool
 */
function ksefForeignPlnDerivedModel($db, $invoiceId)
{
    $since = (int) getDolGlobalString('KSEF_NBP_MODE_MIGRATED');
    if ($since <= 0) {
        return true;
    }
    $res = $db->query("SELECT date_valid, datec FROM " . MAIN_DB_PREFIX . "facture WHERE rowid = " . (int) $invoiceId);
    $o = $res ? $db->fetch_object($res) : null;
    if (!$o || empty($o->date_valid)) {
        return false;
    }
    // server-zone day
    $validDay = dol_print_date($db->jdate($o->date_valid), '%Y-%m-%d', 'tzserver');
    $sinceDay = dol_print_date($since, '%Y-%m-%d', 'tzserver');
    if ($validDay !== $sinceDay) {
        return $validDay > $sinceDay;
    }
    return !empty($o->datec) && (int) $db->jdate($o->datec) >= $since;
}

/**
 * @brief Send PLN check
 * @param DoliDB $db
 * @param Facture $invoice
 * @return array
 */
function ksefForeignPlnVatCheck($db, $invoice)
{
    global $langs;
    $out = array('level' => '', 'message' => '');
    if (empty($invoice->id) || (int) $invoice->statut === Facture::STATUS_DRAFT) {
        return $out;
    }
    $dry = ksefDerivePlnFromForeign($db, (int) $invoice->id, array('dry_run' => true));
    if ($dry['skip'] !== '' || $dry['result'] < 0 || empty($dry['summary']['buckets'])) {
        return $out;
    }
    $langs->load('ksef@ksef');
    $skip = 'ksefFxSkipLine';
    require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
    $soc = new Societe($db);
    $country = $soc->fetch((int) $invoice->socid) > 0 ? ksefInferCountryCode($soc) : 'PL';
    $plnVat = function ($lines) use ($skip, $country) {
        $s = ksefForeignRateSummary((array) $lines, array('pln' => true, 'country' => $country, 'skip' => $skip));
        $r = array();
        foreach ($s['buckets'] as $b => $x) {
            if ($x['w']) {
                $r[$b] = (float) $x['vat'];
            }
        }
        return $r;
    };
    $label = function ($b) {
        $map = array('1' => '23% / 22%', '2' => '8% / 7%', '3' => '5%', '4' => '4% / 3%');
        return $map[$b] ?? $b;
    };
    $diff = function ($have, $want) use ($label, $langs) {
        $items = array();
        foreach (array_unique(array_merge(array_keys($have), array_keys($want))) as $b) {
            $h = round((float) ($have[$b] ?? 0), 2);
            $w = round((float) ($want[$b] ?? 0), 2);
            if (abs($h - $w) >= 0.005) {
                $items[] = $langs->transnoentitiesnoconv('KSEF_ForeignPlnVatItem', $label($b), price($h, 0, $langs, 1, 2, 2, 'PLN'), price($w, 0, $langs, 1, 2, 2, 'PLN'));
            }
        }
        return implode('; ', $items);
    };
    $want = array();
    foreach ($dry['summary']['buckets'] as $b => $x) {
        if ($x['w']) {
            $want[$b] = (float) $x['vat_w'];
        }
    }
    if (empty($invoice->lines)) {
        $invoice->fetch_lines();
    }
    $own = $diff($plnVat($invoice->lines), $want);
    if ($own !== '') {
        $derived = ksefForeignPlnDerivedModel($db, (int) $invoice->id);
        $out['level'] = $derived ? 'error' : 'warning';
        $out['message'] = $langs->transnoentitiesnoconv($derived ? 'KSEF_ForeignPlnVatMismatch' : 'KSEF_ForeignPlnVatMismatchLegacy', dol_escape_htmltag($own));
        if ($derived) {
            return $out;
        }
    }
    if ((int) $invoice->type !== Facture::TYPE_REPLACEMENT || (int) $invoice->fk_facture_source <= 0) {
        return $out;
    }
    if (empty($invoice->array_options)) {
        $invoice->fetch_optionals();
    }
    if (!empty(ksefCorrectionState($invoice)['dep_paid'])) {
        return $out;
    }
    $stated = ksefStatedBeforeVatW($db, $invoice);
    if ($stated === null) {
        return $out;
    }
    $src = new Facture($db);
    if ($src->fetch((int) $invoice->fk_facture_source) <= 0) {
        return $out;
    }
    $src->fetch_lines();
    $lines = (array) $src->lines;
    foreach (array_map('intval', (array) (ksefCorrectionState($invoice)['absorb'] ?? array())) as $noteId) {
        $note = new Facture($db);
        if ($note->fetch($noteId) > 0) {
            $note->fetch_lines();
            $lines = array_merge($lines, (array) $note->lines);
        }
    }
    $before = $diff($plnVat($lines), $stated);
    if ($before === '') {
        return $out;
    }
    $srcDerived = ksefForeignPlnDerivedModel($db, (int) $src->id);
    $msg = $langs->transnoentitiesnoconv('KSEF_ForeignPlnVatBeforeMismatch', dol_escape_htmltag($src->ref), dol_escape_htmltag($before));
    if ($srcDerived) {
        return array('level' => 'error', 'message' => $msg);
    }
    if ($out['level'] === '') {
        return array('level' => 'warning', 'message' => $msg);
    }
    $out['message'] .= '<br>' . $msg;
    return $out;
}

/**
 * @brief Foreign settled state
 * @param Facture|FactureFournisseur $invoice
 * @return array
 */
function ksefForeignSettledState($invoice)
{
    global $conf;
    $out = array('settled' => false, 'foreign' => false, 'remain' => 0.0, 'remain_pln' => 0.0, 'why' => '');
    $code = (string) $invoice->multicurrency_code;
    $out['foreign'] = $code !== '' && $code !== $conf->currency;
    if (!$out['foreign']) {
        $out['why'] = 'pln';
        return $out;
    }
    // supplier pages
    ksefInvoiceKind($invoice);
    if ((int) $invoice->status !== Facture::STATUS_VALIDATED) {
        $out['why'] = 'status';
        return $out;
    }
    if (!empty($invoice->paye) || !empty($invoice->paid)) {
        $out['why'] = 'paid';
        return $out;
    }
    // as Paiement::create()
    if (!in_array((int) $invoice->type, array(Facture::TYPE_STANDARD, Facture::TYPE_REPLACEMENT, Facture::TYPE_CREDIT_NOTE, Facture::TYPE_DEPOSIT, Facture::TYPE_SITUATION), true)) {
        $out['why'] = 'type';
        return $out;
    }
    $out['remain'] = (float) price2num($invoice->getRemainToPay(1), 'MT');
    $out['remain_pln'] = (float) price2num($invoice->getRemainToPay(0), 'MT');
    if ($out['remain'] != 0) {
        $out['why'] = 'remain';
        return $out;
    }
    $out['settled'] = true;
    return $out;
}

/**
 * @brief Partial close offer
 * @param Facture|FactureFournisseur $invoice
 * @return array
 */
function ksefForeignOwedCloseState($invoice)
{
    global $conf;
    $out = array('applies' => false, 'remain' => 0.0, 'remain_pln' => 0.0, 'remain_at_rate' => 0.0);
    if (!isModEnabled('multicurrency') || !is_object($invoice) || empty($invoice->id) || !in_array($invoice->element ?? '', array('facture', 'invoice_supplier'), true)) {
        return $out;
    }
    $code = (string) $invoice->multicurrency_code;
    if ($code === '' || $code === $conf->currency) {
        return $out;
    }
    if ((int) $invoice->status !== Facture::STATUS_VALIDATED || !empty($invoice->paye) || !empty($invoice->paid)
        || !in_array((int) $invoice->type, array(Facture::TYPE_STANDARD, Facture::TYPE_REPLACEMENT, Facture::TYPE_SITUATION), true)) {
        return $out;
    }
    $out['remain'] = (float) price2num($invoice->getRemainToPay(1), 'MT');
    $out['remain_pln'] = (float) price2num($invoice->getRemainToPay(0), 'MT');
    if ($out['remain'] <= 0 || $out['remain_pln'] >= 0) {
        return $out;
    }
    $out['applies'] = true;
    // as core card
    $out['remain_at_rate'] = (float) $invoice->multicurrency_tx > 0 ? (float) price2num($out['remain'] / (float) $invoice->multicurrency_tx, 'MT') : 0.0;
    return $out;
}

/**
 * @brief Close settled foreign
 * @param DoliDB $db
 * @param User $user
 * @param Facture|FactureFournisseur $invoice
 * @param string $noteKey
 * @return array
 */
function ksefCloseForeignSettled($db, $user, $invoice, $noteKey = '')
{
    global $langs;
    $out = array('result' => 0, 'why' => '', 'error' => '');
    $st = ksefForeignSettledState($invoice);
    if (!$st['settled']) {
        $out['why'] = $st['why'];
        return $out;
    }
    $langs->load('ksef@ksef');
    $db->begin();
    // trigger restates chains
    if ((int) $invoice->type === Facture::TYPE_DEPOSIT) {
        if (empty($invoice->lines)) {
            $invoice->fetch_lines();
        }
        $credits = ksefDepositDiscounts($db, $invoice, $user);
        if (!is_array($credits)) {
            $db->rollback();
            $out['result'] = -1;
            $out['error'] = (string) $credits;
            return $out;
        }
    }
    $credit = ksefDepositCreditPlnState($db, $invoice);
    if ($noteKey === '') {
        $noteKey = 'KSEF_ForeignPaidCloseNote';
    }
    if ($credit['applies']) {
        $noteKey = $credit['kind'] === 'note' ? 'KSEF_ForeignPaidCloseNoteFxNote' : ($credit['kind'] === 'mixed' ? 'KSEF_ForeignPaidCloseNoteFxMixed' : 'KSEF_ForeignPaidCloseNoteFx_' . ksefDepositFxPolicy());
    }
    $noteFull = $langs->transnoentitiesnoconv($noteKey, $invoice->multicurrency_code, price($st['remain_pln'], 0, $langs, 1, -1, 2, 'PLN'));
    $note = dol_trunc($noteFull, 125, 'right', 'UTF-8', 1);
    if (!is_array($invoice->context)) {
        $invoice->context = array();
    }
    $hadMore = array_key_exists('actionmsgmore', $invoice->context);
    $prevMore = $hadMore ? $invoice->context['actionmsgmore'] : null;
    $invoice->context['actionmsgmore'] = $hadMore && (string) $prevMore !== '' ? dol_concatdesc($prevMore, $noteFull) : $noteFull;
    $paidRes = $invoice->setPaid($user, '', $note);
    // core unsets key
    if ($hadMore && array_key_exists('actionmsgmore', $invoice->context)) {
        $invoice->context['actionmsgmore'] = $prevMore;
    } else {
        unset($invoice->context['actionmsgmore']);
    }
    if ($paidRes <= 0) {
        $db->rollback();
        $out['result'] = -1;
        $out['error'] = $invoice->error ? $invoice->error : implode(', ', (array) $invoice->errors);
        return $out;
    }
    $db->commit();
    $out['result'] = 1;
    dol_syslog('ksefCloseForeignSettled ' . $invoice->element . ' ' . (int) $invoice->id . ' closed: remain 0 ' . $invoice->multicurrency_code . ', ' . $st['remain_pln'] . ' PLN left', LOG_INFO);
    return $out;
}

/**
 * @brief Payment-flow foreign close
 * @param DoliDB $db
 * @param User $user
 * @param Facture|FactureFournisseur $invoice
 * @return int 1 closed, 0 skip, -1 error
 */
function ksefCloseForeignPaid($db, $user, $invoice)
{
    if (!is_object($invoice) || empty($invoice->id) || !in_array($invoice->element ?? '', array('facture', 'invoice_supplier'), true)) {
        return 0;
    }
    $st = ksefForeignSettledState($invoice);
    if (!$st['settled'] || $st['remain_pln'] == 0) {
        return 0;
    }
    if (!ksefFxLeftoverExplained($db, $invoice, $st['remain_pln'])) {
        return 0;
    }
    $r = ksefCloseForeignSettled($db, $user, $invoice);
    if ((int) $r['result'] < 0) {
        $invoice->error = $r['error'];
        return -1;
    }
    return (int) $r['result'] > 0 ? 1 : 0;
}

/**
 * @brief Apply foreign credit
 * @param DoliDB $db
 * @param User $user
 * @param Facture|FactureFournisseur $invoice
 * @param int $discountId Credit row
 * @return array
 */
function ksefUseForeignDepositCredit($db, $user, $invoice, $discountId)
{
    $out = array('result' => 0, 'why' => '', 'error' => '');
    $st = ksefForeignSettledState($invoice);
    if (!$st['foreign']) {
        $out['why'] = 'pln';
        return $out;
    }
    if (in_array($st['why'], array('status', 'paid', 'type'), true)) {
        $out['why'] = 'status';
        return $out;
    }
    require_once DOL_DOCUMENT_ROOT . '/core/class/discount.class.php';
    $supplier = ($invoice->element ?? '') === 'invoice_supplier';
    $d = new DiscountAbsolute($db);
    if ((int) $discountId <= 0 || $d->fetch((int) $discountId) <= 0) {
        $out['why'] = 'discount';
        return $out;
    }
    $P = MAIN_DB_PREFIX;
    $srcCol = $supplier ? 'fk_invoice_supplier_source' : 'fk_facture_source';
    $srcTable = $supplier ? 'facture_fourn' : 'facture';
    $row = $db->getRow("SELECT re.fk_soc, re.discount_type, re.description, re.multicurrency_code, re.fk_facture, re.fk_facture_line, re.fk_invoice_supplier, re.fk_invoice_supplier_line,"
        . " src.type AS src_type, src.multicurrency_code AS src_code FROM " . $P . "societe_remise_except AS re"
        . " LEFT JOIN " . $P . $srcTable . " AS src ON src.rowid = re." . $srcCol . " WHERE re.rowid = " . (int) $discountId);
    $code = (string) $invoice->multicurrency_code;
    $rowCode = $row ? ((string) $row->multicurrency_code !== '' ? (string) $row->multicurrency_code : (string) $row->src_code) : '';
    $free = $row && empty($row->fk_facture) && empty($row->fk_facture_line) && empty($row->fk_invoice_supplier) && empty($row->fk_invoice_supplier_line);
    $isDeposit = $row && (string) $row->description === KSEF_DEPOSIT_CREDIT_DESCRIPTION && (int) $row->src_type === Facture::TYPE_DEPOSIT;
    $isNote = $row && strpos((string) $row->description, '(CREDIT_NOTE)') === 0 && (int) $row->src_type === Facture::TYPE_CREDIT_NOTE;
    if (!$row || !$free || (int) $row->fk_soc !== (int) $invoice->socid || (int) $row->discount_type !== ($supplier ? 1 : 0)
        || (!$isDeposit && !$isNote) || $rowCode !== $code) {
        $out['why'] = 'discount';
        return $out;
    }
    // mirrors core card.php
    if (price2num($d->amount_ttc) <= price2num($invoice->getRemainToPay(0))) {
        $out['why'] = 'core';
        return $out;
    }
    if ((float) price2num($d->multicurrency_amount_ttc, 'MT') <= 0) {
        $out['why'] = 'discount';
        return $out;
    }
    if ((float) price2num($d->multicurrency_amount_ttc, 'MT') > $st['remain']) {
        $out['why'] = 'larger';
        return $out;
    }
    $db->begin();
    if ($d->link_to_invoice(0, (int) $invoice->id) < 0) {
        $db->rollback();
        $out['result'] = -1;
        $out['error'] = $d->error ? $d->error : implode(', ', (array) $d->errors);
        return $out;
    }
    $db->commit();
    $out['result'] = 1;
    dol_syslog('ksefUseForeignDepositCredit ' . $invoice->element . ' ' . (int) $invoice->id . ': ' . ($isNote ? 'credit note' : 'deposit') . ' credit ' . (int) $discountId . ' (' . $d->multicurrency_amount_ttc . ' ' . $code
        . ', ' . $d->amount_ttc . ' PLN) used as payment in ' . $code, LOG_INFO);
    return $out;
}

/**
 * @brief ksef_kurs_src exists
 * @param DoliDB $db
 * @return bool
 */
function ksefKursSrcStorable($db)
{
    static $ok = false;
    if (!$ok) {
        require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
        $ef = new ExtraFields($db);
        $ef->fetch_name_optionals_label('facturedet');
        $ok = isset($ef->attributes['facturedet']['type']['ksef_kurs_src']);
    }
    return $ok;
}

/**
 * @brief Decode ksef_kurs_src
 * @param string|null $raw
 * @return array
 */
function ksefKursSrc($raw)
{
    $raw = trim((string) $raw);
    if ($raw === '' || $raw[0] !== '{') {
        return array();
    }
    $j = json_decode($raw, true);
    return is_array($j) ? $j : array();
}

/**
 * @brief Write line rate
 * @param DoliDB $db
 * @param int $lineId
 * @param string $kurs '' clears
 * @param string $src '' clears
 * @param bool $begin Begin transaction
 * @return string Error, '' if ok
 */
function ksefLineKursWrite($db, $lineId, $kurs, $src, $begin = false)
{
    if ($begin) {
        $db->begin();
    }
    $P = MAIN_DB_PREFIX;
    $o = $db->getRow("SELECT rowid FROM " . $P . "facturedet_extrafields WHERE fk_object = " . (int) $lineId);
    $hasSrc = ksefKursSrcStorable($db);
    $k = $kurs === '' ? "NULL" : "'" . $db->escape($kurs) . "'";
    $s = $src === '' ? "NULL" : "'" . $db->escape($src) . "'";
    if ($o) {
        $sql = "UPDATE " . $P . "facturedet_extrafields SET ksef_kurs = " . $k . ($hasSrc ? ", ksef_kurs_src = " . $s : "") . " WHERE rowid = " . (int) $o->rowid;
    } elseif ($kurs === '' && $src === '') {
        return '';
    } else {
        $sql = "INSERT INTO " . $P . "facturedet_extrafields (fk_object, ksef_kurs" . ($hasSrc ? ", ksef_kurs_src" : "") . ") VALUES (" . (int) $lineId . ", " . $k . ($hasSrc ? ", " . $s : "") . ")";
    }
    return $db->query($sql) ? '' : 'line ' . (int) $lineId . ': ' . $db->lasterror();
}

/**
 * @brief Rate-capable lines
 * @param DoliDB $db
 * @param Facture $invoice
 * @return array By line id
 */
function ksefLineKursList($db, $invoice)
{
    $out = array();
    if (empty($invoice->id) || !ksefLineKursStorable($db)) {
        return $out;
    }
    $P = MAIN_DB_PREFIX;
    $src = ksefKursSrcStorable($db) ? "e.ksef_kurs_src" : "NULL AS ksef_kurs_src";
    $res = $db->query("SELECT d.rowid, d.rang, d.description, d.product_type, d.info_bits, d.fk_remise_except, d.multicurrency_total_ht, p.label AS plabel, e.ksef_kurs, " . $src
        . " FROM " . $P . "facturedet AS d LEFT JOIN " . $P . "facturedet_extrafields AS e ON e.fk_object = d.rowid LEFT JOIN " . $P . "product AS p ON p.rowid = d.fk_product"
        . " WHERE d.fk_facture = " . (int) $invoice->id . " ORDER BY d.rang, d.rowid");
    $n = 0;
    while ($res && ($o = $db->fetch_object($res))) {
        $n++;
        if (ksefFxSkipLine($o) || (int) $o->fk_remise_except > 0 || abs((float) $o->multicurrency_total_ht) < 0.005) {
            continue;
        }
        $text = trim(dol_string_nohtmltag((string) ($o->plabel !== null && $o->plabel !== '' ? $o->plabel : $o->description)));
        $k = trim((string) $o->ksef_kurs);
        $s = ksefKursSrc($o->ksef_kurs_src);
        $out[(int) $o->rowid] = array('label' => $n . '. ' . dol_trunc($text, 50), 'kurs' => ($k !== '' && is_numeric($k)) ? round((float) $k, 6) : 0.0,
            'day' => (string) ($s['d'] ?? ''));
    }
    return $out;
}

/**
 * @brief Check user-set rate
 * @param DoliDB $db
 * @param Facture $invoice
 * @param array $opt
 * @return array|null Null if not user-set
 */
function ksefTypedRateCheck($db, $invoice, $opt = array())
{
    global $conf;
    if (empty($invoice->id) || ($invoice->element ?? '') !== 'facture' || !isModEnabled('multicurrency')) {
        return null;
    }
    $code = strtoupper((string) ($invoice->multicurrency_code ?? ''));
    $tx = (float) ($invoice->multicurrency_tx ?? 0);
    if ($code === '' || $code === strtoupper((string) $conf->currency) || $tx <= 0 || abs($tx - 1) < 1e-12) {
        return null;
    }
    $type = (int) $invoice->type;
    if (in_array($type, array(Facture::TYPE_REPLACEMENT, Facture::TYPE_CREDIT_NOTE), true)) {
        return null;
    }
    if (!isset($invoice->array_options) || !is_array($invoice->array_options) || !array_key_exists('options_ksef_kurs_data', $invoice->array_options)) {
        $invoice->fetch_optionals();
    }
    $kd = $invoice->array_options['options_ksef_kurs_data'] ?? '';
    $kdTs = is_numeric($kd) ? (int) $kd : (int) strtotime((string) $kd);
    if ($kdTs <= 0) {
        return null;
    }
    if ($db->getRow("SELECT rowid FROM " . MAIN_DB_PREFIX . "ksef_sales WHERE fk_facture = " . (int) $invoice->id)) {
        return null;
    }
    if ($type === Facture::TYPE_DEPOSIT && ksefDepositPaymentParts($db, $invoice, array('no_rates' => true))) {
        return null;
    }
    // tx=1/mid, 8 dp
    $fits = function ($mid) use ($tx) {
        return $mid > 0 && abs($tx - 1 / $mid) <= 6e-9;
    };
    dol_include_once('/ksef/class/ksef_nbp_currency_rate.class.php');
    $nbp = new KsefNbpCurrencyRate($db);
    $ask = !empty($opt['ask_nbp']);
    $day = dol_print_date($kdTs, '%Y-%m-%d', 'tzserver');
    $rec = $nbp->rateRecord($code, $day);
    $own = $rec ? (float) $rec['mid'] : 0.0;
    if ($own <= 0) {
        $next = (int) strtotime($day . ' +1 day');
        $r0 = $ask ? $nbp->getRateForDateCached($code, $next) : $nbp->getCachedRateForDate($code, $next);
        $own = ($r0 && (string) $r0['date'] === $day) ? (float) $r0['rate'] : 0.0;
    }
    if ($own <= 0 || $fits($own)) {
        return null;
    }
    $ref = $nbp->getReferenceDate($invoice);
    if (empty($ref)) {
        return null;
    }
    $r = $ask ? $nbp->getRateForDateCached($code, $ref) : $nbp->getCachedRateForDate($code, $ref);
    if ($r && (float) $r['rate'] > 0 && $fits((float) $r['rate'])) {
        return null;
    }
    $out = array('kurs' => ksefInvoiceKurs($invoice, array('db' => $db, 'own' => true)), 'state' => 'unverified', 'nbp' => null);
    if ($r && (float) $r['rate'] > 0) {
        $out['state'] = 'differs';
        $out['nbp'] = array('rate' => round((float) $r['rate'], 6), 'date' => (string) $r['date'], 'no' => (string) ($r['no'] ?? ''), 'table' => (string) ($r['table'] ?? ''));
    }
    return $out;
}

/**
 * @brief User-set rate text
 * @param array $it Check result
 * @param Facture $invoice
 * @param bool $hint Add NBP hint
 * @return string
 */
function ksefTypedRateText($it, $invoice, $hint = false)
{
    global $langs;
    $langs->load('ksef@ksef');
    $code = (string) $invoice->multicurrency_code;
    $rate = function ($k) {
        return number_format((float) $k, 4, ',', '');
    };
    if ($it['state'] !== 'differs') {
        return $langs->transnoentitiesnoconv('KSEF_TypedRateUnverified', $rate($it['kurs']), $code);
    }
    $n = $it['nbp'];
    $table = $langs->transnoentitiesnoconv('KSEF_TypedRateTableOf', $n['no'] !== '' ? $n['no'] : $n['table'], dol_print_date(strtotime($n['date']), 'day'));
    $txt = $langs->transnoentitiesnoconv('KSEF_TypedRateDiffers', $rate($it['kurs']), $code, $rate($n['rate']), $table);
    if ($hint) {
        $txt .= ' ' . $langs->transnoentitiesnoconv('KSEF_TypedRateUseNbpHint', number_format(1 / (float) $n['rate'], 8, ',', ''), $langs->transnoentitiesnoconv('CurrencyRate'),
            $langs->transnoentitiesnoconv('KSEF_RefreshNBPRate'));
    }
    return $txt;
}

/**
 * @brief User-set rate warnings
 * @param DoliDB $db
 * @param Facture $invoice
 * @param string $mode plain or trans
 * @return array
 */
function ksefTypedRateSendWarnings($db, $invoice, $mode = 'plain')
{
    $it = ksefTypedRateCheck($db, $invoice, array('ask_nbp' => true));
    if ($it === null) {
        return array();
    }
    $t = ksefTypedRateText($it, $invoice);
    return array($mode === 'trans' ? dol_escape_htmltag($t) : $t);
}

/**
 * @brief Cron rate check
 * @param DoliDB $db
 * @param int $limit Max invoices
 * @return array
 */
function ksefTypedRateCron($db, $limit = 200)
{
    global $conf;
    $out = array('checked' => 0, 'differs' => array(), 'unverified' => 0);
    if (!isModEnabled('multicurrency')) {
        return $out;
    }
    $P = MAIN_DB_PREFIX;
    $res = $db->query("SELECT f.rowid FROM " . $P . "facture AS f INNER JOIN " . $P . "facture_extrafields AS e ON e.fk_object = f.rowid"
        . " INNER JOIN " . $P . "ksef_nbp_rate_row AS k ON k.entity = " . ((int) $conf->entity) . " AND k.currency_code = f.multicurrency_code AND k.table_day = e.ksef_kurs_data"
        . " WHERE f.entity = " . ((int) $conf->entity) . " AND f.fk_statut IN (0, 1, 2) AND f.type NOT IN (1, 2) AND COALESCE(e.ksef_number, '') = ''"
        . " AND f.multicurrency_tx > 0 AND k.mid > 0 AND ABS(f.multicurrency_tx * k.mid - 1) > 0.00000001 ORDER BY f.rowid DESC LIMIT " . (int) $limit);
    $ids = array();
    while ($res && ($o = $db->fetch_object($res))) {
        $ids[] = (int) $o->rowid;
    }
    foreach ($ids as $id) {
        $f = new Facture($db);
        if ($f->fetch($id) <= 0) {
            continue;
        }
        $it = ksefTypedRateCheck($db, $f, array('ask_nbp' => true));
        if ($it === null) {
            continue;
        }
        $out['checked']++;
        if ($it['state'] === 'differs') {
            $out['differs'][] = $f->ref;
        } else {
            $out['unverified']++;
        }
    }
    return $out;
}

/**
 * @brief Line rates HTML
 * @param DoliDB $db
 * @param Facture $invoice
 * @return string
 */
function ksefLineKursCardHtml($db, $invoice)
{
    global $langs;
    $langs->load('ksef@ksef');
    $map = ksefLineKursMap($db, $invoice);
    if (!$map['active']) {
        return '';
    }
    $rows = array();
    foreach (ksefLineKursList($db, $invoice) as $lid => $l) {
        $k = isset($map['lines'][$lid]) ? (float) $map['lines'][$lid] : (float) $l['kurs'];
        if ($k <= 0) {
            $k = (float) $map['kurs'];
            $what = $langs->transnoentitiesnoconv('KSEF_LineRateOfInvoice');
        } elseif ($l['day'] !== '') {
            $what = $langs->transnoentitiesnoconv('KSEF_LineRateNbpForDay', dol_print_date(strtotime($l['day']), 'day'));
        } else {
            $what = $langs->transnoentitiesnoconv('KSEF_LineRateNbp');
        }
        $rows[] = '<li class="ksef-line-rate">' . dol_escape_htmltag($l['label']) . ': ' . ($k > 0 ? number_format($k, 4, ',', '') : '?') . ' <span class="opacitymedium">(' . dol_escape_htmltag($what) . ')</span></li>';
    }
    return '<div class="ksef-line-rates small"><span class="opacitymedium">' . dol_escape_htmltag($langs->transnoentitiesnoconv('KSEF_LineRatesTitle')) . '</span><ul style="margin: 2px 0 0 0;">' . implode('', $rows) . '</ul></div>';
}

/**
 * @brief Bank line undeletable
 * @param DoliDB $db
 * @return bool
 */
function ksefNoLedgerTable($db)
{
    if (getDolGlobalString('BANK_ALLOW_TRANSACTION_DELETION_EVEN_IF_IN_ACCOUNTING')) {
        return false;
    }
    return !$db->DDLListTables($db->database_name, MAIN_DB_PREFIX . 'accounting_bookkeeping');
}

/**
 * @brief Skip missing-ledger check
 * @return void
 */
function ksefNoLedgerAllowDelete()
{
    global $conf;
    $conf->global->BANK_ALLOW_TRANSACTION_DELETION_EVEN_IF_IN_ACCOUNTING = 1;
}

/**
 * @brief Missing table delete
 * @param DoliDB $db
 * @param string $element
 * @param int $id Payment id
 * @return bool
 */
function ksefNoLedgerBlocksDelete($db, $element, $id)
{
    if ((int) $id <= 0 || !ksefNoLedgerTable($db)) {
        return false;
    }
    $P = MAIN_DB_PREFIX;
    if ($element === 'payment_various') {
        $res = $db->query("SELECT b.rappro FROM " . $P . "payment_various AS p INNER JOIN " . $P . "bank AS b ON b.rowid = p.fk_bank WHERE p.rowid = " . (int) $id);
        $o = $res ? $db->fetch_object($res) : null;
        return $o && empty($o->rappro);
    }
    if ($element === 'payment_supplier') {
        $closed = "SELECT COUNT(*) AS nb FROM " . $P . "paiementfourn_facturefourn AS pf INNER JOIN " . $P . "facture_fourn AS f ON f.rowid = pf.fk_facturefourn"
            . " WHERE pf.fk_paiementfourn = " . (int) $id . " AND f.paye = 1";
        $bank = "SELECT b.rappro FROM " . $P . "paiementfourn AS p INNER JOIN " . $P . "bank AS b ON b.rowid = p.fk_bank WHERE p.rowid = " . (int) $id;
    } elseif ($element === 'payment') {
        $closed = "SELECT COUNT(*) AS nb FROM " . $P . "paiement_facture AS pf INNER JOIN " . $P . "facture AS f ON f.rowid = pf.fk_facture"
            . " WHERE pf.fk_paiement = " . (int) $id . " AND f.fk_statut > 1";
        $bank = "SELECT b.rappro FROM " . $P . "paiement AS p INNER JOIN " . $P . "bank AS b ON b.rowid = p.fk_bank WHERE p.rowid = " . (int) $id;
    } else {
        return false;
    }
    $res = $db->query($closed);
    $o = $res ? $db->fetch_object($res) : null;
    if (!$o || (int) $o->nb > 0) {
        return false;
    }
    $res = $db->query($bank);
    $o = $res ? $db->fetch_object($res) : null;
    if ($o) {
        return empty($o->rappro);
    }
    return false;
}

/**
 * @brief PLN leftover explained
 * @param DoliDB $db
 * @param Facture|FactureFournisseur $invoice
 * @param float $remainPln
 * @return bool
 */
function ksefFxLeftoverExplained($db, $invoice, $remainPln)
{
    return abs((float) $remainPln) <= ksefFxSlack($db, $invoice) + 0.000001;
}

/**
 * @brief PLN rounding allowed
 * @param DoliDB $db
 * @param Facture|FactureFournisseur $invoice
 * @param int $more Payments to come
 * @return float
 */
function ksefFxSlack($db, $invoice, $more = 0)
{
    $k = ksefInvoiceKind($invoice);
    $P = MAIN_DB_PREFIX;
    $id = (int) $invoice->id;
    $credits = $db->getRow("SELECT COUNT(*) AS n, SUM(CASE WHEN re.description LIKE '" . $db->escape($k['excess']) . "%' THEN 0 ELSE 1 END) AS rated"
        . " FROM " . $P . "societe_remise_except AS re WHERE re." . $k['col'] . " = " . $id
        . " OR re." . $k['col_line'] . " IN (SELECT l.rowid FROM " . $P . $k['line_table'] . " AS l WHERE l." . $k['line_fk'] . " = " . $id . ")");
    if ($credits && (int) $credits->rated > 0) {
        return 1000000000.0;
    }
    $pays = (int) $db->getRow("SELECT COUNT(*) AS n FROM " . $P . $k['pay_table'] . " WHERE " . $k['pay_fk'] . " = " . $id)->n;
    return 0.01 * max(1, $pays + (int) $more + ($credits ? (int) $credits->n : 0));
}

/**
 * @brief NBP rate before
 * @param DoliDB $db
 * @param string $currency
 * @param int $ts Payment date
 * @return array|null rate, no, date
 */
function ksefPayNbp($db, $currency, $ts)
{
    static $memo = array();
    $currency = strtoupper(trim((string) $currency));
    $day = dol_print_date((int) $ts, '%Y-%m-%d');
    if ($currency === '' || $currency === 'PLN' || (int) $ts <= 0) {
        return null;
    }
    if (!array_key_exists($currency . $day, $memo)) {
        dol_include_once('/ksef/class/ksef_nbp_currency_rate.class.php');
        $nbp = new KsefNbpCurrencyRate($db);
        $ref = dol_mktime(12, 0, 0, (int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4));
        $r = $nbp->getCachedRateForDate($currency, $ref);
        if (!$r) {
            $r = $nbp->getRateForDate($currency, $ref);
        }
        $memo[$currency . $day] = ($r && (float) $r['rate'] > 0) ? array('rate' => (float) $r['rate'], 'no' => (string) ($r['no'] ?? ''), 'date' => (string) $r['date']) : null;
    }
    return $memo[$currency . $day];
}

/**
 * @brief NBP rate text
 * @param array $nbp NBP table
 * @return string
 */
function ksefPayRateText($nbp)
{
    global $langs;
    $langs->load('ksef@ksef');
    $s = rtrim(rtrim(sprintf('%.6F', (float) $nbp['rate']), '0'), '.');
    $dot = strpos($s, '.');
    $s = $dot === false ? $s . '.0000' : $s . str_repeat('0', max(0, 4 - (strlen($s) - $dot - 1)));
    $dec = $langs->transnoentitiesnoconv('SeparatorDecimal');
    $s = str_replace('.', $dec !== 'SeparatorDecimal' ? $dec : ',', $s);
    return $langs->transnoentitiesnoconv('KSEF_PayRateText', $s, 'NBP ' . ($nbp['no'] !== '' ? $nbp['no'] . ', ' : '') . $nbp['date']);
}

/**
 * @brief Payment FX row
 * @param DoliDB $db
 * @param string $element Payment element
 * @param int $paymentId
 * @return string '' if n/a
 */
function ksefPayFxInfoRow($db, $element, $paymentId)
{
    global $conf, $langs;
    if (!isModEnabled('bank') || (int) $paymentId <= 0) {
        return '';
    }
    $P = MAIN_DB_PREFIX;
    $o = $db->getRow("SELECT p.datep, b.amount, b.amount_main_currency AS main, ba.currency_code AS cur FROM " . $P . ($element === 'payment_supplier' ? 'paiementfourn' : 'paiement') . " AS p"
        . " INNER JOIN " . $P . "bank AS b ON b.rowid = p.fk_bank INNER JOIN " . $P . "bank_account AS ba ON ba.rowid = b.fk_account WHERE p.rowid = " . (int) $paymentId);
    if (!$o || (string) $o->cur === '' || (string) $o->cur === (string) $conf->currency || $o->main === null) {
        return '';
    }
    $langs->loadLangs(array('ksef@ksef', 'banks'));
    $main = (string) $conf->currency;
    $atInvoice = abs((float) $o->main);
    $nbp = ksefPayNbp($db, (string) $o->cur, (int) $db->jdate($o->datep));
    if ($nbp) {
        $atNbp = (float) price2num(abs((float) $o->amount) * $nbp['rate'], 'MT');
        $text = $langs->trans('KSEF_PayFxInfo', price($atInvoice, 0, $langs, 1, -1, 2, $main), price($atNbp, 0, $langs, 1, -1, 2, $main),
            dol_escape_htmltag(ksefPayRateText($nbp)), price($atNbp - $atInvoice, 0, $langs, 1, -1, 2, $main));
    } else {
        $text = $langs->trans('KSEF_PayFxInfoNoRate', price($atInvoice, 0, $langs, 1, -1, 2, $main));
    }
    return '<tr class="ksef-pay-fxinfo"><td>' . $langs->trans('KSEF_PayFxInfoTitle') . '</td><td><span class="opacitymedium">' . $text . '</span></td></tr>';
}
