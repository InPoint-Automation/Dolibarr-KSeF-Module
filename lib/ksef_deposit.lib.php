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
 * \file    ksef/lib/ksef_deposit.lib.php
 * \ingroup ksef
 * \brief   Deposit and settlement
 */

/**
 * @brief Split fixed deposit
 * @param DoliDB $db
 * @return bool True when switched on
 */
function ksefDepositFixedAmountSplit($db)
{
    global $conf;
    $origin = GETPOST('origin', 'alpha');
    $originId = GETPOSTINT('originid') ? GETPOSTINT('originid') : GETPOSTINT('origin_id');
    $tables = array('commande' => 'commande', 'propal' => 'propal');
    if (GETPOSTINT('type') !== 3 || GETPOST('typedeposit', 'aZ09') !== 'amount' || !isset($tables[$origin])
        || getDolGlobalString('MAIN_DEPOSIT_MULTI_TVA') || $originId <= 0) {
        return false;
    }
    // core divides
    $total = $db->getRow("SELECT total_ttc FROM " . MAIN_DB_PREFIX . $tables[$origin] . " WHERE rowid = " . (int) $originId);
    if (!$total || (float) $total->total_ttc == 0) {
        return false;
    }
    $conf->global->MAIN_DEPOSIT_MULTI_TVA = '1';
    // core warns here
    $prev = null;
    $prev = set_error_handler(function ($no, $str, $file, $line) use (&$prev) {
        if (preg_match('/^Undefined (array key|index:?) "?\d+(\.\d+)?"?$/', (string) $str) && substr(str_replace('\\', '/', (string) $file), -24) === '/compta/facture/card.php') {
            return true;
        }
        return $prev ? call_user_func($prev, $no, $str, $file, $line) : false;
    });
    return true;
}

/**
 * @brief Origin deposits only
 * @param DoliDB $db
 * @return bool
 */
function ksefOriginBilledByDepositsOnly($db)
{
    $origin = GETPOST('origin', 'alpha');
    $originId = GETPOSTINT('originid');
    if (!in_array($origin, array('commande', 'propal'), true) || $originId <= 0) {
        return false;
    }
    $row = $db->getRow("SELECT COUNT(*) AS n, SUM(CASE WHEN f.type = 3 THEN 1 ELSE 0 END) AS d FROM " . MAIN_DB_PREFIX . "element_element AS ee"
        . " INNER JOIN " . MAIN_DB_PREFIX . "facture AS f ON f.rowid = ee.fk_target"
        . " WHERE ee.sourcetype = '" . $db->escape($origin) . "' AND ee.fk_source = " . $originId . " AND ee.targettype = 'facture'");
    return $row && (int) $row->n > 0 && (int) $row->n === (int) $row->d;
}

/**
 * @brief Discount is deposit
 * @param DoliDB $db
 * @param int $discountId
 * @return bool
 */
function ksefIsDepositDiscount($db, $discountId)
{
    $res = $db->query("SELECT src.type FROM " . MAIN_DB_PREFIX . "societe_remise_except AS re"
        . " JOIN " . MAIN_DB_PREFIX . "facture AS src ON src.rowid = re.fk_facture_source"
        . " WHERE re.rowid = " . (int) $discountId . " AND re.description = '" . KSEF_DEPOSIT_CREDIT_DESCRIPTION . "'");
    $o = $res ? $db->fetch_object($res) : null;
    return $o && (int) $o->type === 3;
}

/**
 * @brief Deposit discount IDs
 * @param DoliDB $db
 * @param int $socid
 * @param int $fk_facture
 * @return array
 */
function ksefDepositDiscountIds($db, $socid, $fk_facture)
{
    $out = array();
    $res = $db->query("SELECT re.rowid FROM " . MAIN_DB_PREFIX . "societe_remise_except AS re"
        . " JOIN " . MAIN_DB_PREFIX . "facture AS src ON src.rowid = re.fk_facture_source AND src.type = 3"
        . " AND re.description = '" . KSEF_DEPOSIT_CREDIT_DESCRIPTION . "'"
        . " WHERE (re.fk_soc = " . (int) $socid . " AND (re.fk_facture IS NULL OR re.fk_facture = 0) AND (re.fk_facture_line IS NULL OR re.fk_facture_line = 0))"
        . " OR re.fk_facture = " . (int) $fk_facture);
    while ($res && ($o = $db->fetch_object($res))) {
        $out[] = (int) $o->rowid;
    }
    return $out;
}

/**
 * @brief Deposit full-payment date
 * @param DoliDB $db
 * @param Facture $invoice
 * @return int 0 if unpaid
 */
function ksefDepositPaymentDate($db, $invoice)
{
    if (empty($invoice->id)) {
        return 0;
    }
    $res = $db->query("SELECT SUM(pf.amount) AS paid, SUM(pf.multicurrency_amount) AS mc_paid, MIN(p.datep) AS first, MAX(p.datep) AS last FROM " . MAIN_DB_PREFIX . "paiement_facture AS pf"
        . " INNER JOIN " . MAIN_DB_PREFIX . "paiement AS p ON p.rowid = pf.fk_paiement WHERE pf.fk_facture = " . (int) $invoice->id);
    $o = $res ? $db->fetch_object($res) : null;
    if (!$o || $o->last === null) {
        return 0;
    }
    $paid = (float) $o->paid;
    $total = (float) $invoice->total_ttc;
    // compare in currency
    if (ksefInvoiceIsForeign($invoice) && dol_print_date($db->jdate($o->first), 'dayrfc') === dol_print_date($db->jdate($o->last), 'dayrfc')) {
        $paid = (float) $o->mc_paid;
        $total = (float) $invoice->multicurrency_total_ttc;
    }
    if (price2num($paid, 'MT') + 0.005 < price2num($total, 'MT')) {
        return 0;
    }
    return (int) $db->jdate($o->last);
}

/**
 * @brief Deposit payment date
 * @param DoliDB $db
 * @param int $depositId Chain root
 * @param int $excludeId
 * @return int 0 if none
 */
function ksefDepositSentPaymentDate($db, $depositId, $excludeId = 0)
{
    require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
    $xml = ksefLatestSentXml($db, ksefCorrectionChainIds($db, $depositId, $excludeId));
    if ($xml !== '') {
        $doc = new DOMDocument();
        if (@$doc->loadXML($xml)) {
            $xp = new DOMXPath($doc);
            $xp->registerNamespace('fa', KSEF_FA3_NAMESPACE);
            foreach (array('fa:P_6', 'fa:P_1') as $q) {
                $n = $xp->query('//fa:Fa/' . $q);
                if ($n->length && preg_match('/^\s*(\d{4}-\d{2}-\d{2})\s*$/', $n->item(0)->textContent, $m)) {
                    return (int) dol_stringtotime($m[1] . ' 12:00:00');
                }
            }
        }
    }
    require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
    $dep = new Facture($db);
    if ($dep->fetch((int) $depositId) <= 0) {
        return 0;
    }
    return ksefDepositPaymentDate($db, $dep);
}

/**
 * @brief VAT mismatch refusal
 * @param Facture $invoice Deposit or correction
 * @param array $ctx Deposit context
 * @return string '' when fine
 */
function ksefDepositVatMismatch($invoice, $ctx)
{
    global $langs;
    if (!in_array($ctx['type'] ?? '', array('ZAL', 'KOR_ZAL'), true) || empty($ctx['order']['lines'])) {
        return '';
    }
    $rate = function ($tx) {
        return price2num((float) $tx, 2);
    };
    $orderRates = array();
    foreach ($ctx['order']['lines'] as $l) {
        if ((float) ($l->total_ht ?? 0) != 0) {
            $orderRates[(string) $rate($l->tva_tx)] = true;
        }
    }
    if (empty($orderRates)) {
        return '';
    }
    if (empty($invoice->lines)) {
        $invoice->fetch_lines();
    }
    $bad = array();
    foreach ((array) $invoice->lines as $l) {
        if ((float) ($l->total_ht ?? 0) != 0 && !isset($orderRates[(string) $rate($l->tva_tx)])) {
            $bad[(string) $rate($l->tva_tx)] = true;
        }
    }
    if (empty($bad)) {
        return '';
    }
    $langs->load("ksef@ksef");
    $msg = $langs->trans('KSEF_DepositVatMismatch', implode(', ', array_map(function ($r) {
        return $r . ' %';
    }, array_keys($bad))), implode(', ', array_map(function ($r) {
        return $r . ' %';
    }, array_keys($orderRates))));
    return $msg;
}

/**
 * @brief Consumed deposits
 * @param DoliDB $db
 * @param int $invoiceId
 * @return array
 */
function ksefConsumedDeposits($db, $invoiceId)
{
    $out = array();
    if ((int) $invoiceId <= 0) {
        return $out;
    }
    $P = MAIN_DB_PREFIX;
    $sql = "SELECT 'line' AS mode, fd.rowid AS line_id, re.amount_ht, re.amount_tva, re.amount_ttc, re.tva_tx, re.vat_src_code,"
        . " re.multicurrency_amount_ht, re.multicurrency_amount_tva, re.multicurrency_amount_ttc, src.rowid AS dep_id, src.ref, src.datef, src.fk_soc, src.multicurrency_code"
        . " FROM " . $P . "facturedet AS fd"
        . " JOIN " . $P . "societe_remise_except AS re ON re.rowid = fd.fk_remise_except"
        . " JOIN " . $P . "facture AS src ON src.rowid = re.fk_facture_source AND src.type = 3"
        . " WHERE fd.fk_facture = " . (int) $invoiceId . " AND re.description = '" . KSEF_DEPOSIT_CREDIT_DESCRIPTION . "'"
        . " UNION ALL"
        . " SELECT 'payment' AS mode, 0 AS line_id, re.amount_ht, re.amount_tva, re.amount_ttc, re.tva_tx, re.vat_src_code,"
        . " re.multicurrency_amount_ht, re.multicurrency_amount_tva, re.multicurrency_amount_ttc, src.rowid AS dep_id, src.ref, src.datef, src.fk_soc, src.multicurrency_code"
        . " FROM " . $P . "societe_remise_except AS re"
        . " JOIN " . $P . "facture AS src ON src.rowid = re.fk_facture_source AND src.type = 3"
        . " WHERE re.fk_facture = " . (int) $invoiceId . " AND (re.fk_facture_line IS NULL OR re.fk_facture_line = 0)"
        . " AND re.description = '" . KSEF_DEPOSIT_CREDIT_DESCRIPTION . "'"
        . " ORDER BY datef ASC, dep_id ASC";
    $res = $db->query($sql);
    if (!$res) {
        return $out;
    }
    while ($o = $db->fetch_object($res)) {
        $id = (int) $o->dep_id;
        if (!isset($out[$id])) {
            $out[$id] = array('id' => $id, 'ref' => (string) $o->ref, 'date' => $db->jdate($o->datef), 'socid' => (int) $o->fk_soc,
                'currency' => ksefNormCurrency($o->multicurrency_code), 'mode' => (string) $o->mode, 'line_ids' => array(), 'ht' => 0.0, 'tva' => 0.0, 'ttc' => 0.0,
                'mc_ht' => 0.0, 'mc_tva' => 0.0, 'mc_ttc' => 0.0, 'rates' => array());
        }
        $d = &$out[$id];
        if ((int) $o->line_id > 0) {
            $d['line_ids'][] = (int) $o->line_id;
        }
        $d['ht'] += (float) $o->amount_ht;
        $d['tva'] += (float) $o->amount_tva;
        $d['ttc'] += (float) $o->amount_ttc;
        $d['mc_ht'] += (float) $o->multicurrency_amount_ht;
        $d['mc_tva'] += (float) $o->multicurrency_amount_tva;
        $d['mc_ttc'] += (float) $o->multicurrency_amount_ttc;
        $rate = price2num((float) $o->tva_tx);
        if (!isset($d['rates'][$rate])) {
            // splits zw/np/oo
            $d['rates'][$rate] = array('ht' => 0.0, 'tva' => 0.0, 'mc_ht' => 0.0, 'mc_tva' => 0.0, 'code' => (string) $o->vat_src_code);
        }
        $d['rates'][$rate]['ht'] += (float) $o->amount_ht;
        $d['rates'][$rate]['tva'] += (float) $o->amount_tva;
        $d['rates'][$rate]['mc_ht'] += (float) $o->multicurrency_amount_ht;
        $d['rates'][$rate]['mc_tva'] += (float) $o->multicurrency_amount_tva;
        unset($d);
    }
    return $out;
}

/**
 * @brief Consumed deposit line
 * @param DoliDB $db
 * @param object $line FactureLigne
 * @param int $depth Recursion depth
 * @return bool
 */
function ksefIsDepositLine($db, $line, $depth = 0)
{
    static $cache = array();
    $re = (int) ($line->fk_remise_except ?? 0);
    if ($re > 0) {
        if (!array_key_exists($re, $cache)) {
            $res = $db->query("SELECT re.fk_facture_source, re.description, src.type FROM " . MAIN_DB_PREFIX . "societe_remise_except AS re"
                . " LEFT JOIN " . MAIN_DB_PREFIX . "facture AS src ON src.rowid = re.fk_facture_source WHERE re.rowid = " . $re);
            $o = $res ? $db->fetch_object($res) : null;
            $cache[$re] = ($o && (int) $o->fk_facture_source > 0 && $o->type !== null)
                ? ((int) $o->type === 3 && (string) $o->description === KSEF_DEPOSIT_CREDIT_DESCRIPTION) : null;
        }
        if ($cache[$re] !== null) {
            return $cache[$re];
        }
    }
    $prev = (int) ($line->fk_prev_id ?? 0);
    if ($prev > 0 && $depth < 20) {
        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
        $pl = new FactureLigne($db);
        if ($pl->fetch($prev) > 0) {
            return ksefIsDepositLine($db, $pl, $depth + 1);
        }
    }
    return empty($line->fk_product) && strpos((string) ($line->desc ?? $line->description ?? ''), '(DEPOSIT)') === 0;
}

/**
 * @brief Adopt order deposits
 * @param DoliDB $db
 * @param int $invoiceId
 * @param int $lineId 0 for all
 * @return int -1 on error
 */
function ksefAdoptOrderDepositLines($db, $invoiceId, $lineId = 0)
{
    require_once DOL_DOCUMENT_ROOT . '/core/class/discount.class.php';
    $P = MAIN_DB_PREFIX;
    $res = $db->query("SELECT type FROM " . $P . "facture WHERE rowid = " . (int) $invoiceId);
    $inv = $res ? $db->fetch_object($res) : null;
    if (!$inv || (int) $inv->type !== 0) {
        return 0;
    }
    $res = $db->query("SELECT fd.rowid AS line_id, re.rowid AS re_id, re.amount_ht, re.amount_tva, re.amount_ttc, re.tva_tx FROM " . $P . "facturedet AS fd"
        . " JOIN " . $P . "societe_remise_except AS re ON re.rowid = fd.fk_remise_except"
        . " WHERE fd.fk_facture = " . (int) $invoiceId . " AND (re.fk_facture_source IS NULL OR re.fk_facture_source = 0)"
        . " AND re.description = '" . KSEF_DEPOSIT_CREDIT_DESCRIPTION . "'" . ((int) $lineId > 0 ? " AND fd.rowid = " . (int) $lineId : ""));
    if (!$res) {
        return -1;
    }
    $lines = array();
    while ($o = $db->fetch_object($res)) {
        $lines[] = $o;
    }
    if (!$lines) {
        return 0;
    }
    $cands = array();
    foreach (ksefInvoiceOrderSources($db, $invoiceId) as $src) {
        $isOrder = $src['type'] === 'commande';
        $res = $db->query("SELECT re.rowid, re.amount_ht, re.amount_tva, re.amount_ttc, re.tva_tx FROM " . $P . ($isOrder ? "commandedet" : "propaldet") . " AS l"
            . " JOIN " . $P . "societe_remise_except AS re ON re.rowid = l.fk_remise_except"
            . " JOIN " . $P . "facture AS dep ON dep.rowid = re.fk_facture_source AND dep.type = 3"
            . " WHERE l." . ($isOrder ? "fk_commande" : "fk_propal") . " = " . (int) $src['id'] . " AND re.description = '" . KSEF_DEPOSIT_CREDIT_DESCRIPTION . "'"
            . " AND (re.fk_facture_line IS NULL OR re.fk_facture_line = 0) AND (re.fk_facture IS NULL OR re.fk_facture = 0) ORDER BY re.rowid");
        while ($res && ($o = $db->fetch_object($res))) {
            $cands[(int) $o->rowid] = $o;
        }
    }
    $same = function ($a, $b) {
        return abs((float) $a->amount_ht - (float) $b->amount_ht) < 0.005 && abs((float) $a->amount_tva - (float) $b->amount_tva) < 0.005
            && abs((float) $a->amount_ttc - (float) $b->amount_ttc) < 0.005 && abs((float) $a->tva_tx - (float) $b->tva_tx) < 0.0001;
    };
    $n = 0;
    foreach ($lines as $l) {
        foreach ($cands as $cid => $c) {
            if (!$same($l, $c)) {
                continue;
            }
            unset($cands[$cid]);
            $db->begin();
            $credit = new DiscountAbsolute($db);
            $ok = $db->query("UPDATE " . $P . "facturedet SET fk_remise_except = " . (int) $cid . " WHERE rowid = " . (int) $l->line_id)
                && $credit->fetch($cid) > 0 && $credit->link_to_invoice((int) $l->line_id, 0) > 0
                && $db->query("DELETE FROM " . $P . "societe_remise_except WHERE rowid = " . (int) $l->re_id);
            if (!$ok) {
                dol_syslog("ksefAdoptOrderDepositLines: invoice " . (int) $invoiceId . " line " . (int) $l->line_id . ": " . $db->lasterror() . ' ' . $credit->error, LOG_ERR);
                $db->rollback();
                return -1;
            }
            $db->commit();
            dol_syslog("ksefAdoptOrderDepositLines: invoice " . (int) $invoiceId . " line " . (int) $l->line_id . " now uses deposit credit " . (int) $cid
                . " (order copy " . (int) $l->re_id . " deleted)", LOG_INFO);
            $n++;
            break;
        }
    }
    return $n;
}

/**
 * @brief Credit used elsewhere
 * @param DoliDB $db
 * @param int $invoiceId
 * @return array
 */
function ksefOrderDepositUsedElsewhere($db, $invoiceId)
{
    $P = MAIN_DB_PREFIX;
    $out = array();
    $res = $db->query("SELECT type FROM " . $P . "facture WHERE rowid = " . (int) $invoiceId);
    $inv = $res ? $db->fetch_object($res) : null;
    if (!$inv || (int) $inv->type !== 0) {
        return $out;
    }
    $res = $db->query("SELECT re.amount_ht, re.amount_tva, re.amount_ttc, re.tva_tx FROM " . $P . "facturedet AS fd"
        . " JOIN " . $P . "societe_remise_except AS re ON re.rowid = fd.fk_remise_except"
        . " WHERE fd.fk_facture = " . (int) $invoiceId . " AND (re.fk_facture_source IS NULL OR re.fk_facture_source = 0)"
        . " AND re.description = '" . KSEF_DEPOSIT_CREDIT_DESCRIPTION . "'");
    $lines = array();
    while ($res && ($o = $db->fetch_object($res))) {
        $lines[] = $o;
    }
    if (!$lines) {
        return $out;
    }
    $used = array();
    foreach (ksefInvoiceOrderSources($db, $invoiceId) as $src) {
        $isOrder = $src['type'] === 'commande';
        $res = $db->query("SELECT re.rowid, re.amount_ht, re.amount_tva, re.amount_ttc, re.tva_tx, dep.rowid AS dep_id, dep.ref AS dep_ref,"
            . " COALESCE(NULLIF(re.fk_facture, 0), fdu.fk_facture) AS used_id"
            . " FROM " . $P . ($isOrder ? "commandedet" : "propaldet") . " AS l"
            . " JOIN " . $P . "societe_remise_except AS re ON re.rowid = l.fk_remise_except"
            . " JOIN " . $P . "facture AS dep ON dep.rowid = re.fk_facture_source AND dep.type = 3"
            . " LEFT JOIN " . $P . "facturedet AS fdu ON fdu.rowid = re.fk_facture_line"
            . " WHERE l." . ($isOrder ? "fk_commande" : "fk_propal") . " = " . (int) $src['id'] . " AND re.description = '" . KSEF_DEPOSIT_CREDIT_DESCRIPTION . "'"
            . " AND ((re.fk_facture_line IS NOT NULL AND re.fk_facture_line > 0) OR (re.fk_facture IS NOT NULL AND re.fk_facture > 0)) ORDER BY re.rowid");
        while ($res && ($o = $db->fetch_object($res))) {
            if ((int) $o->used_id > 0 && (int) $o->used_id !== (int) $invoiceId) {
                $used[(int) $o->rowid] = $o;
            }
        }
    }
    foreach ($lines as $l) {
        foreach ($used as $cid => $c) {
            if (abs((float) $l->amount_ht - (float) $c->amount_ht) < 0.005 && abs((float) $l->amount_tva - (float) $c->amount_tva) < 0.005
                && abs((float) $l->amount_ttc - (float) $c->amount_ttc) < 0.005 && abs((float) $l->tva_tx - (float) $c->tva_tx) < 0.0001) {
                unset($used[$cid]);
                $res = $db->query("SELECT ref FROM " . $P . "facture WHERE rowid = " . (int) $c->used_id);
                $u = $res ? $db->fetch_object($res) : null;
                $out[] = array('deposit_id' => (int) $c->dep_id, 'deposit_ref' => (string) $c->dep_ref, 'used_id' => (int) $c->used_id, 'used_ref' => $u ? (string) $u->ref : '');
                break;
            }
        }
    }
    return $out;
}

/**
 * @brief Used-elsewhere notice text
 * @param DoliDB $db
 * @param array $list
 * @param bool $html
 * @param bool $sent Already in KSeF
 * @return string
 */
function ksefOrderDepositUsedElsewhereText($db, $list, $html = false, $sent = false)
{
    global $langs;
    $langs->load("ksef@ksef");
    $key = $sent ? 'KSEF_OrderDepositUsedElsewhereSent' : 'KSEF_OrderDepositUsedElsewhere';
    $msg = array();
    foreach ($list as $u) {
        if ($html) {
            $dep = implode('', ksefDepositLinks($db, array($u['deposit_ref'] => $u['deposit_id'])));
            $msg[] = ksefTransHtml($key, array($dep, implode('', ksefDepositLinks($db, array($u['used_ref'] => $u['used_id'])))));
        } else {
            $msg[] = $langs->transnoentitiesnoconv($key, $u['deposit_ref'], $u['used_ref']);
        }
    }
    return implode($html ? '<br>' : ' ', $msg);
}

/**
 * @brief Discounts per rate
 * @param DoliDB $db
 * @param Facture|FactureFournisseur $deposit
 * @param User|null $user Set to create
 * @return array|string string on error
 */
function ksefDepositDiscounts($db, $deposit, $user = null)
{
    require_once DOL_DOCUMENT_ROOT . '/core/class/discount.class.php';
    $supplier = ($deposit->element === 'invoice_supplier');
    if ($supplier) {
        $sql = "SELECT re.rowid, re.tva_tx, re.vat_src_code, re.amount_ht, re.amount_tva, re.amount_ttc, re.multicurrency_amount_ht, re.multicurrency_amount_tva,"
            . " re.multicurrency_amount_ttc, re.fk_invoice_supplier AS used_inv, d.fk_facture_fourn AS used_line_inv"
            . " FROM " . MAIN_DB_PREFIX . "societe_remise_except AS re LEFT JOIN " . MAIN_DB_PREFIX . "facture_fourn_det AS d ON d.rowid = re.fk_invoice_supplier_line"
            . " WHERE re.fk_invoice_supplier_source = " . (int) $deposit->id . " AND re.description = '" . KSEF_DEPOSIT_CREDIT_DESCRIPTION . "' ORDER BY re.rowid";
    } else {
        $sql = "SELECT re.rowid, re.tva_tx, re.vat_src_code, re.amount_ht, re.amount_tva, re.amount_ttc, re.multicurrency_amount_ht, re.multicurrency_amount_tva,"
            . " re.multicurrency_amount_ttc, re.fk_facture AS used_inv, d.fk_facture AS used_line_inv"
            . " FROM " . MAIN_DB_PREFIX . "societe_remise_except AS re LEFT JOIN " . MAIN_DB_PREFIX . "facturedet AS d ON d.rowid = re.fk_facture_line"
            . " WHERE re.fk_facture_source = " . (int) $deposit->id . " AND re.description = '" . KSEF_DEPOSIT_CREDIT_DESCRIPTION . "' ORDER BY re.rowid";
    }
    $out = array();
    $res = $db->query($sql);
    while ($res && ($o = $db->fetch_object($res))) {
        $out[] = array('id' => (int) $o->rowid, 'rate' => ksefDepositRateKey($o->tva_tx, $o->vat_src_code), 'ht' => (float) $o->amount_ht,
            'tva' => (float) $o->amount_tva, 'ttc' => (float) $o->amount_ttc, 'mht' => (float) $o->multicurrency_amount_ht,
            'mtva' => (float) $o->multicurrency_amount_tva, 'mttc' => (float) $o->multicurrency_amount_ttc,
            'used_by' => (int) $o->used_inv > 0 ? (int) $o->used_inv : (int) $o->used_line_inv);
    }
    if ($out) {
        return $out;
    }
    $byRate = array();
    foreach ((array) $deposit->lines as $l) {
        if ((int) $l->product_type >= 9 || (float) $l->total_ht == 0) {
            continue;
        }
        $k = ksefDepositRateKey($l->tva_tx, $l->vat_src_code ?? '');
        if (!isset($byRate[$k])) {
            $byRate[$k] = array('id' => 0, 'rate' => $k, 'ht' => 0.0, 'tva' => 0.0, 'ttc' => 0.0, 'mht' => 0.0, 'mtva' => 0.0, 'mttc' => 0.0, 'used_by' => 0,
                'tva_tx' => abs((float) $l->tva_tx), 'src' => (string) ($l->vat_src_code ?? ''));
        }
        $byRate[$k]['ht'] += (float) $l->total_ht;
        $byRate[$k]['tva'] += (float) $l->total_tva;
        $byRate[$k]['ttc'] += (float) $l->total_ttc;
        $byRate[$k]['mht'] += (float) $l->multicurrency_total_ht;
        $byRate[$k]['mtva'] += (float) $l->multicurrency_total_tva;
        $byRate[$k]['mttc'] += (float) $l->multicurrency_total_ttc;
    }
    foreach ($byRate as $k => $a) {
        unset($byRate[$k]['tva_tx'], $byRate[$k]['src']);
        if (!$user) {
            continue;
        }
        $disc = new DiscountAbsolute($db);
        $disc->description = KSEF_DEPOSIT_CREDIT_DESCRIPTION;
        $disc->discount_type = $supplier ? 1 : 0;
        $disc->fk_soc = $disc->socid = $deposit->socid;
        if ($supplier) {
            $disc->fk_invoice_supplier_source = $deposit->id;
        } else {
            $disc->fk_facture_source = $deposit->id;
        }
        $disc->amount_ht = abs($a['ht']);
        $disc->amount_tva = abs($a['tva']);
        $disc->amount_ttc = abs($a['ttc']);
        $disc->multicurrency_code = $deposit->multicurrency_code;
        $disc->multicurrency_tx = $deposit->multicurrency_tx;
        $disc->multicurrency_amount_ht = abs($a['mht']);
        $disc->multicurrency_amount_tva = abs($a['mtva']);
        $disc->multicurrency_amount_ttc = abs($a['mttc']);
        $disc->tva_tx = $a['tva_tx'];
        $disc->vat_src_code = $a['src'];
        if ($disc->create($user) <= 0) {
            return $disc->error ? $disc->error : implode(', ', (array) $disc->errors);
        }
        $byRate[$k]['id'] = (int) $disc->id;
    }
    return array_values($byRate);
}

/**
 * @brief Deposit rate key
 * @param float|string $rate tva_tx
 * @param string $code vat_src_code
 * @return string
 */
function ksefDepositRateKey($rate, $code = '')
{
    $r = (string) (float) $rate;
    return (string) $code !== '' ? $r . ' (' . $code . ')' : $r;
}

/**
 * @brief Deposit citation
 * @param DoliDB $db
 * @param int $depositId
 * @return array
 */
function ksefDepositCitation($db, $depositId)
{
    static $ksefStart = null;
    require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
    $out = array('citable' => false, 'ksef_number' => '', 'outside' => false, 'ref' => '', 'reason' => '', 'status' => '', 'number' => '', 'env' => '');
    $dep = new Facture($db);
    if ($dep->fetch((int) $depositId) <= 0) {
        $out['reason'] = 'KSEF_DepositNotFound';
        return $out;
    }
    $out['ref'] = (string) $dep->ref;
    $env = ksefGetEnvironment();
    $state = ksefSubmissionState($db, $dep->id, $env);
    $out['status'] = (string) $state['status'];
    if ($state['is_accepted']) {
        $out['citable'] = true;
        $out['ksef_number'] = (string) $state['ksef_number'];
        return $out;
    }
    if ($state['has_submission']) {
        $out['reason'] = 'KSEF_DepositNotInKsefStatus';
        return $out;
    }
    if ($state['number_without_row'] !== '') {
        $out['reason'] = 'KSEF_DepositNumberNotLinked';
        $out['number'] = (string) $state['number_without_row'];
        return $out;
    }
    if (ksefIsCustomerExcluded($dep->socid)) {
        $out['citable'] = true;
        $out['outside'] = true;
        return $out;
    }
    foreach (ksefIssuedRows($db, $dep->id) as $r) {
        if ($r['environment'] !== $env && $r['status'] === 'ACCEPTED' && ksefIsRealKsefNumber($r['ksef_number'])) {
            $out['reason'] = 'KSEF_DepositOtherEnvOnly';
            $out['env'] = (string) $r['environment'];
            return $out;
        }
    }
    if ($ksefStart === null) {
        $res = $db->query("SELECT MIN(f.datef) AS d FROM " . MAIN_DB_PREFIX . "ksef_submissions AS s JOIN " . MAIN_DB_PREFIX . "facture AS f ON f.rowid = s.fk_facture"
            . " WHERE s.status = 'ACCEPTED' AND f.entity IN (" . getEntity('invoice') . ")"
            . " AND s.environment = '" . $db->escape(ksefGetEnvironment()) . "'");
        $o = $res ? $db->fetch_object($res) : null;
        $ksefStart = ($o && $o->d) ? (int) $db->jdate($o->d) : 0;
    }
    if ($ksefStart === 0 || (int) $dep->date < $ksefStart) {
        $out['citable'] = true;
        $out['outside'] = true;
        return $out;
    }
    $out['reason'] = 'KSEF_DepositNotInKsefUnsent';
    return $out;
}

/**
 * @brief Deposit source order
 * @param DoliDB $db
 * @param Facture $invoice Deposit
 * @return array|null
 */
function ksefDepositOrder($db, $invoice)
{
    $res = $db->query("SELECT sourcetype, fk_source FROM " . MAIN_DB_PREFIX . "element_element WHERE targettype = 'facture' AND fk_target = " . (int) $invoice->id
        . " AND sourcetype IN ('commande', 'propal') ORDER BY (sourcetype = 'commande') DESC, rowid ASC");
    if (!$res) {
        return null;
    }
    while ($o = $db->fetch_object($res)) {
        if ($o->sourcetype === 'commande') {
            require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
            $obj = new Commande($db);
        } else {
            require_once DOL_DOCUMENT_ROOT . '/comm/propal/class/propal.class.php';
            $obj = new Propal($db);
        }
        if ($obj->fetch((int) $o->fk_source) > 0) {
            if (empty($obj->lines)) {
                $obj->fetch_lines();
            }
            return array('type' => (string) $o->sourcetype, 'id' => (int) $obj->id, 'ref' => (string) $obj->ref,
                'date' => $o->sourcetype === 'commande' ? $obj->date : $obj->date, 'total_ht' => (float) $obj->total_ht, 'total_ttc' => (float) $obj->total_ttc,
                'mc_total_ttc' => (float) $obj->multicurrency_total_ttc, 'lines' => $obj->lines);
        }
    }
    return null;
}

/**
 * @brief Order deposits
 * @param DoliDB $db
 * @param string $type commande|propal
 * @param int $id Order ID
 * @param int $currentId Deposit being built
 * @return array
 */
function ksefOrderDeposits($db, $type, $id, $currentId = 0)
{
    $P = MAIN_DB_PREFIX;
    $out = array();
    $res = $db->query("SELECT f.rowid, f.ref, f.datef, f.total_ttc, f.multicurrency_total_ttc, f.fk_statut, f.close_code FROM " . $P . "element_element AS ee"
        . " JOIN " . $P . "facture AS f ON f.rowid = ee.fk_target"
        . " WHERE ee.sourcetype = '" . $db->escape($type) . "' AND ee.fk_source = " . (int) $id . " AND ee.targettype = 'facture'"
        . " AND f.type = 3 AND (f.fk_statut IN (1, 2, 3) OR f.rowid = " . (int) $currentId . ") ORDER BY f.datef ASC, f.rowid ASC");
    $rows = array();
    while ($res && ($o = $db->fetch_object($res))) {
        $rows[] = $o;
    }
    foreach ($rows as $o) {
        $depId = (int) $o->rowid;
        if ((int) $o->fk_statut === 3 && $depId !== (int) $currentId && !ksefInvoiceLeftDolibarr($db, $depId)) {
            continue;
        }
        $v = ksefDepositCurrentValue($db, $depId);
        $ttc = (float) $o->total_ttc;
        $mc = (float) $o->multicurrency_total_ttc;
        if ($v && (int) $v['active'] !== $depId) {
            $r = $db->query("SELECT total_ttc, multicurrency_total_ttc FROM " . $P . "facture WHERE rowid = " . (int) $v['active']);
            $ro = $r ? $db->fetch_object($r) : null;
            if ($ro) {
                $ttc = (float) $ro->total_ttc;
                $mc = (float) $ro->multicurrency_total_ttc;
            }
        }
        $netTtc = $v ? $v['ttc'] : $ttc;
        $netMc = $v ? $v['mttc'] : $mc;
        $st = ksefSubmissionState($db, $depId, ksefGetEnvironment());
        $out[] = array('id' => $depId, 'ref' => (string) $o->ref, 'date' => $db->jdate($o->datef), 'ttc' => $ttc, 'mc_ttc' => $mc,
            'net_ttc' => $netTtc, 'net_mc_ttc' => $netMc, 'accepted' => !empty($st['is_accepted']));
    }
    return $out;
}

/**
 * @brief Deposit credit usage
 * @param DoliDB $db
 * @param int $depositId
 * @return array
 */
function ksefDepositUsage($db, $depositId)
{
    $out = array('used_ttc' => 0.0, 'used_mc' => 0.0, 'unused_ttc' => 0.0, 'unused_mc' => 0.0, 'unused' => array());
    $res = $db->query("SELECT amount_ttc, multicurrency_amount_ttc, tva_tx, fk_facture_line, fk_facture FROM " . MAIN_DB_PREFIX . "societe_remise_except"
        . " WHERE fk_facture_source = " . (int) $depositId . " AND description = '" . KSEF_DEPOSIT_CREDIT_DESCRIPTION . "'");
    while ($res && ($o = $db->fetch_object($res))) {
        if ((int) $o->fk_facture_line > 0 || (int) $o->fk_facture > 0) {
            $out['used_ttc'] += (float) $o->amount_ttc;
            $out['used_mc'] += (float) $o->multicurrency_amount_ttc;
        } elseif (abs((float) $o->amount_ttc) >= 0.005) {
            $out['unused_ttc'] += (float) $o->amount_ttc;
            $out['unused_mc'] += (float) $o->multicurrency_amount_ttc;
            $out['unused'][] = array('rate' => price2num((float) $o->tva_tx), 'ttc' => (float) $o->amount_ttc, 'mc_ttc' => (float) $o->multicurrency_amount_ttc);
        }
    }
    return $out;
}

/**
 * @brief Chain root deposit
 * @param DoliDB $db
 * @param int $invoiceId
 * @param string $element facture|invoice_supplier
 * @return int 0 when none
 */
function ksefDepositRootId($db, $invoiceId, $element = 'facture')
{
    $T = MAIN_DB_PREFIX . ksefInvoiceKind($element)['table'];
    $id = (int) $invoiceId;
    for ($i = 0; $id > 0 && $i < 20; $i++) {
        $res = $db->query("SELECT type, fk_facture_source FROM " . $T . " WHERE rowid = " . $id);
        $o = $res ? $db->fetch_object($res) : null;
        if (!$o) {
            return 0;
        }
        if ((int) $o->type === 3) {
            return $id;
        }
        if (!in_array((int) $o->type, array(1, 2), true)) {
            return 0;
        }
        $id = (int) $o->fk_facture_source;
    }
    return 0;
}

/**
 * @brief Deposit chain members
 * @param DoliDB $db
 * @param string $element facture|invoice_supplier
 * @return int[]
 */
function ksefDepositCorrectionIds($db, $element = 'facture')
{
    $T = MAIN_DB_PREFIX . ksefInvoiceKind($element)['table'];
    $ids = array();
    $from = "SELECT rowid FROM " . $T . " WHERE type = 3";
    for ($i = 0; $i < 20; $i++) {
        $level = array();
        $res = $db->query("SELECT rowid FROM " . $T . " WHERE type IN (1, 2) AND fk_facture_source IN (" . $from . ")");
        while ($res && ($o = $db->fetch_object($res))) {
            $level[] = (int) $o->rowid;
        }
        $level = array_values(array_diff($level, $ids));
        if (!$level) {
            break;
        }
        $ids = array_merge($ids, $level);
        $from = implode(',', $level);
    }
    return $ids;
}

/**
 * @brief Deposit correction chain
 * @param DoliDB $db
 * @param int $depositId
 * @param int $excludeId Being deleted
 * @param string $element facture|invoice_supplier
 * @param int $beforeId 0 for all
 * @return array
 */
function ksefDepositChain($db, $depositId, $excludeId = 0, $element = 'facture', $beforeId = 0)
{
    $T = MAIN_DB_PREFIX . ksefInvoiceKind($element)['table'];
    $before = (int) $beforeId > 0 ? " AND rowid < " . (int) $beforeId : "";
    $chain = array((int) $depositId);
    $cur = (int) $depositId;
    for ($i = 0; $i < 20; $i++) {
        $res = $db->query("SELECT rowid FROM " . $T . " WHERE fk_facture_source = " . $cur . " AND type = 1 AND fk_statut IN (1, 2)"
            . " AND rowid <> " . (int) $excludeId . $before . " ORDER BY rowid DESC LIMIT 1");
        $o = $res ? $db->fetch_object($res) : null;
        if (!$o || in_array((int) $o->rowid, $chain, true)) {
            break;
        }
        $cur = (int) $o->rowid;
        $chain[] = $cur;
    }
    $notes = array();
    $rows = array();
    $res = $db->query("SELECT rowid, fk_statut FROM " . $T . " WHERE fk_facture_source IN (" . implode(',', $chain) . ") AND type = 2"
        . " AND fk_statut IN (1, 2, 3) AND rowid <> " . (int) $excludeId . $before . " ORDER BY rowid");
    while ($res && ($o = $db->fetch_object($res))) {
        $rows[] = $o;
    }
    foreach ($rows as $o) {
        if ((int) $o->fk_statut === 3 && !($element === 'facture' ? ksefInvoiceLeftDolibarr($db, (int) $o->rowid) : ksefInvoiceSentToKsef($db, (int) $o->rowid, $element))) {
            continue;
        }
        $notes[] = (int) $o->rowid;
    }
    $absorbed = array();
    foreach (count($chain) > 1 ? ksefShortfallCovers($db, array_slice($chain, 1), $element)['absorb'] : array() as $id) {
        if (in_array($id, $notes, true)) {
            $absorbed[] = $id;
        }
    }
    return array('chain' => $chain, 'active' => end($chain), 'notes' => array_values(array_diff($notes, $absorbed)), 'absorbed' => $absorbed);
}

/**
 * @brief Deposit current value
 * @param DoliDB $db
 * @param int $depositId
 * @param int $excludeId Being deleted
 * @param string $element facture|invoice_supplier
 * @param int $beforeId 0 for all
 * @return array|false false if not deposit
 */
function ksefDepositCurrentValue($db, $depositId, $excludeId = 0, $element = 'facture', $beforeId = 0)
{
    global $conf;
    $P = MAIN_DB_PREFIX;
    $kind = ksefInvoiceKind($element);
    $res = $db->query("SELECT rowid, ref, type, fk_soc, multicurrency_code, multicurrency_tx FROM " . $P . $kind['table'] . " WHERE rowid = " . (int) $depositId);
    $dep = $res ? $db->fetch_object($res) : null;
    if (!$dep || (int) $dep->type !== 3) {
        return false;
    }
    $c = ksefDepositChain($db, (int) $depositId, $excludeId, $kind['element'], $beforeId);
    $code = (string) $dep->multicurrency_code;
    $out = array('id' => (int) $dep->rowid, 'ref' => (string) $dep->ref, 'socid' => (int) $dep->fk_soc, 'currency' => $code,
        'tx' => (float) $dep->multicurrency_tx, 'foreign' => $code !== '' && $code !== $conf->currency, 'element' => $kind['element']) + $c
        + array('rates' => array(), 'ht' => 0.0, 'tva' => 0.0, 'ttc' => 0.0, 'mht' => 0.0, 'mtva' => 0.0, 'mttc' => 0.0, 'orig' => array('ttc' => 0.0, 'mttc' => 0.0));
    $ids = array_unique(array_merge(array((int) $depositId, (int) $c['active']), $c['notes']));
    $res = $db->query("SELECT " . $kind['line_fk'] . " AS fk_facture, tva_tx, vat_src_code, total_ht, " . $kind['line_tva'] . " AS total_tva, total_ttc, multicurrency_total_ht, multicurrency_total_tva, multicurrency_total_ttc"
        . " FROM " . $P . $kind['line_table'] . " WHERE " . $kind['line_fk'] . " IN (" . implode(',', $ids) . ") AND product_type < 9 AND total_ht <> 0 ORDER BY rowid");
    $cur = array_merge(array((int) $c['active']), $c['notes']);
    while ($res && ($l = $db->fetch_object($res))) {
        $inv = (int) $l->fk_facture;
        if ($inv === (int) $depositId) {
            $out['orig']['ttc'] += (float) $l->total_ttc;
            $out['orig']['mttc'] += (float) $l->multicurrency_total_ttc;
        }
        if (!in_array($inv, $cur, true)) {
            continue;
        }
        $k = ksefDepositRateKey(abs((float) $l->tva_tx), (string) $l->vat_src_code);
        if (!isset($out['rates'][$k])) {
            $out['rates'][$k] = array('tva_tx' => abs((float) $l->tva_tx), 'code' => (string) $l->vat_src_code,
                'ht' => 0.0, 'tva' => 0.0, 'ttc' => 0.0, 'mht' => 0.0, 'mtva' => 0.0, 'mttc' => 0.0);
        }
        $vals = array('ht' => $l->total_ht, 'tva' => $l->total_tva, 'ttc' => $l->total_ttc, 'mht' => $l->multicurrency_total_ht,
            'mtva' => $l->multicurrency_total_tva, 'mttc' => $l->multicurrency_total_ttc);
        foreach ($vals as $col => $v) {
            $out['rates'][$k][$col] += (float) $v;
            $out[$col] += (float) $v;
        }
    }
    foreach (array('ht', 'tva', 'ttc', 'mht', 'mtva', 'mttc') as $col) {
        $out[$col] = (float) price2num($out[$col], 'MT');
        foreach ($out['rates'] as $k => $r) {
            $out['rates'][$k][$col] = (float) price2num($r[$col], 'MT');
        }
    }
    return $out;
}

/**
 * @brief Rate key label
 * @param string $rk Rate key
 * @return string
 */
function ksefDepositRateLabel($rk)
{
    return preg_match('/^([\d.]+)(?: \((.+)\))?$/', (string) $rk, $mm) ? vatrate($mm[1], true) . (isset($mm[2]) ? ' (' . $mm[2] . ')' : '') : (string) $rk;
}

/**
 * @brief Korekta below zero
 * @param DoliDB $db
 * @param int $invoiceId Replacement
 * @param string $element facture|invoice_supplier
 * @return array|null
 */
function ksefDepositKorektaBelowZero($db, $invoiceId, $element = 'facture')
{
    $k = ksefInvoiceKind($element);
    $depId = ksefDepositRootId($db, (int) $invoiceId, $k['element']);
    $v = $depId > 0 ? ksefDepositCurrentValue($db, $depId, 0, $k['element']) : false;
    if (!$v) {
        return null;
    }
    $res = $db->query("SELECT tva_tx, vat_src_code, total_ttc, multicurrency_total_ttc FROM " . MAIN_DB_PREFIX . $k['line_table']
        . " WHERE " . $k['line_fk'] . " IN (" . implode(',', array_merge(array((int) $invoiceId), array_map('intval', $v['notes']))) . ") AND product_type < 9 AND total_ht <> 0");
    $rates = array();
    while ($res && ($l = $db->fetch_object($res))) {
        $rk = ksefDepositRateKey(abs((float) $l->tva_tx), (string) $l->vat_src_code);
        $rates[$rk] = ($rates[$rk] ?? 0) + (float) ($v['foreign'] ? $l->multicurrency_total_ttc : $l->total_ttc);
    }
    foreach ($rates as $rk => $ttc) {
        if ($ttc < -0.005) {
            return array('ref' => $v['ref'], 'rate' => $rk, 'label' => ksefDepositRateLabel($rk));
        }
    }
    return null;
}

/**
 * @brief Deposit money received
 * @param DoliDB $db
 * @param array $value Current value
 * @param int $excludePayment Being deleted
 * @return array
 */
function ksefDepositReceived($db, $value, $excludePayment = 0)
{
    $P = MAIN_DB_PREFIX;
    $k = ksefInvoiceKind($value['element'] ?? 'facture');
    $ids = implode(',', array_map('intval', $value['chain']));
    $out = array('ttc' => 0.0, 'mttc' => 0.0);
    // own notes excluded
    $notOwn = " AND (" . $k['col_source'] . " IS NULL OR " . $k['col_source'] . " NOT IN (" . implode(',', array_map('intval', array_merge($value['chain'], $value['notes'], $value['absorbed'] ?? array()))) . "))";
    $sqls = array(
        1 => "SELECT COALESCE(SUM(amount), 0) AS a, COALESCE(SUM(multicurrency_amount), 0) AS m FROM " . $P . $k['pay_table'] . " WHERE " . $k['pay_fk'] . " IN (" . $ids . ")"
            . ((int) $excludePayment > 0 ? " AND " . $k['pay_id'] . " <> " . (int) $excludePayment : ''),
        2 => "SELECT COALESCE(SUM(amount_ttc), 0) AS a, COALESCE(SUM(multicurrency_amount_ttc), 0) AS m FROM " . $P . "societe_remise_except WHERE " . $k['col'] . " IN (" . $ids . ")" . $notOwn,
        // core splits excess
        -1 => "SELECT COALESCE(SUM(amount_ttc), 0) AS a, COALESCE(SUM(multicurrency_amount_ttc), 0) AS m FROM " . $P . "societe_remise_except WHERE " . $k['col_source'] . " IN (" . $ids . ")"
            . " AND description LIKE '" . $db->escape($k['excess']) . "%' AND (" . $k['col'] . " IS NULL OR " . $k['col'] . " NOT IN (" . $ids . "))",
    );
    foreach ($sqls as $sign => $sql) {
        $res = $db->query($sql);
        $o = $res ? $db->fetch_object($res) : null;
        if ($o) {
            $out['ttc'] += ($sign < 0 ? -1 : 1) * (float) $o->a;
            $out['mttc'] += ($sign < 0 ? -1 : 1) * (float) $o->m;
        }
    }
    $out['ttc'] = (float) price2num($out['ttc'], 'MT');
    $out['mttc'] = (float) price2num($out['mttc'], 'MT');
    return $out;
}

/**
 * @brief Deposit credit origin
 * @param DoliDB $db
 * @param int $depositId
 * @param bool $stored Recorded only
 * @param string $element facture|invoice_supplier
 * @return string accepted|received|''
 */
function ksefDepositConversion($db, $depositId, $stored = false, $element = 'facture')
{
    $P = MAIN_DB_PREFIX;
    $k = ksefInvoiceKind($element);
    $state = ksefDepositState($db, $depositId, $k['element']);
    if (in_array($state['dep_conv'] ?? '', array('accepted', 'received'), true)) {
        return $state['dep_conv'];
    }
    if ($stored || !ksefDepositRows($db, $depositId, $k['element'])) {
        return '';
    }
    global $conf;
    $res = $db->query("SELECT f.total_ttc, f.multicurrency_total_ttc, f.multicurrency_code,"
        . " (SELECT COALESCE(SUM(pf.amount), 0) FROM " . $P . $k['pay_table'] . " AS pf WHERE pf." . $k['pay_fk'] . " = f.rowid) AS paid,"
        . " (SELECT COALESCE(SUM(pf.multicurrency_amount), 0) FROM " . $P . $k['pay_table'] . " AS pf WHERE pf." . $k['pay_fk'] . " = f.rowid) AS mpaid,"
        . " (SELECT COALESCE(SUM(re.amount_ttc), 0) FROM " . $P . "societe_remise_except AS re WHERE re." . $k['col'] . " = f.rowid) AS cred,"
        . " (SELECT COALESCE(SUM(re.multicurrency_amount_ttc), 0) FROM " . $P . "societe_remise_except AS re WHERE re." . $k['col'] . " = f.rowid) AS mcred"
        . " FROM " . $P . $k['table'] . " AS f WHERE f.rowid = " . (int) $depositId . " AND f.type = 3");
    $f = $res ? $db->fetch_object($res) : null;
    if (!$f) {
        return '';
    }
    $foreign = (string) $f->multicurrency_code !== '' && (string) $f->multicurrency_code !== $conf->currency;
    $short = $foreign ? (float) $f->multicurrency_total_ttc - (float) $f->mpaid - (float) $f->mcred : (float) $f->total_ttc - (float) $f->paid - (float) $f->cred;
    return $short < 0.005 ? 'received' : 'accepted';
}

/**
 * @brief Record credit origin
 * @param DoliDB $db
 * @param int $depositId
 * @param string $how accepted|received
 * @param string $element facture|invoice_supplier
 * @return bool
 */
function ksefDepositConversionRecord($db, $depositId, $how, $element = 'facture')
{
    if (ksefDepositConversion($db, $depositId, true, $element) !== '') {
        return true;
    }
    return ksefDepositStateSet($db, $depositId, array('dep_conv' => $how), $element);
}

/**
 * @brief Read correction state
 * @param DoliDB $db
 * @param int $depositId
 * @param string $element facture|invoice_supplier
 * @return array
 */
function ksefDepositState($db, $depositId, $element = 'facture')
{
    if (!ksefCorrectionStateStorable($db, $element)) {
        return array();
    }
    $res = $db->query("SELECT ksef_correction_state FROM " . MAIN_DB_PREFIX . ksefInvoiceKind($element)['extrafields'] . "_extrafields WHERE fk_object = " . (int) $depositId);
    $o = $res ? $db->fetch_object($res) : null;
    $state = ($o && (string) $o->ksef_correction_state !== '') ? json_decode((string) $o->ksef_correction_state, true) : array();
    return is_array($state) ? $state : array();
}

/**
 * @brief Merge correction state
 * @param DoliDB $db
 * @param int $depositId
 * @param array $set Null removes key
 * @param string $element facture|invoice_supplier
 * @return bool
 */
function ksefDepositStateSet($db, $depositId, $set, $element = 'facture')
{
    if (!ksefCorrectionStateStorable($db, $element)) {
        return true;
    }
    $E = MAIN_DB_PREFIX . ksefInvoiceKind($element)['extrafields'] . '_extrafields';
    $res = $db->query("SELECT rowid, ksef_correction_state FROM " . $E . " WHERE fk_object = " . (int) $depositId);
    $o = $res ? $db->fetch_object($res) : null;
    $state = ($o && (string) $o->ksef_correction_state !== '') ? json_decode((string) $o->ksef_correction_state, true) : array();
    $state = is_array($state) ? $state : array();
    foreach ($set as $key => $v) {
        if ($v === null) {
            unset($state[$key]);
        } else {
            $state[$key] = $v;
        }
    }
    $json = $state ? "'" . $db->escape(json_encode($state)) . "'" : 'NULL';
    $sql = $o ? "UPDATE " . $E . " SET ksef_correction_state = " . $json . " WHERE rowid = " . (int) $o->rowid
        : "INSERT INTO " . $E . " (fk_object, ksef_correction_state) VALUES (" . (int) $depositId . ", " . $json . ")";
    if (!$db->query($sql)) {
        dol_syslog('ksefDepositStateSet: ' . $db->lasterror(), LOG_ERR);
        return false;
    }
    return true;
}

/**
 * @brief Deposit target credit
 * @param DoliDB $db
 * @param int $depositId
 * @param int $excludeId Being deleted
 * @param int $excludePayment Being deleted
 * @param string $element facture|invoice_supplier
 * @return array|false false if not deposit
 */
function ksefDepositCreditTarget($db, $depositId, $excludeId = 0, $excludePayment = 0, $element = 'facture')
{
    $v = ksefDepositCurrentValue($db, $depositId, $excludeId, $element);
    if (!$v) {
        return false;
    }
    $v['received'] = ksefDepositReceived($db, $v, $excludePayment);
    $k = $v['foreign'] ? 'mttc' : 'ttc';
    $v['key'] = $k;
    $cur = $v[$k];
    $v['full'] = $v['received'][$k] >= $cur - 0.005;
    $v['accepted'] = ksefDepositConversion($db, $depositId, false, $v['element']) === 'accepted';
    $cov = ksefShortfallCovers($db, $v['chain'], $v['element']);
    $cover = $v['foreign'] ? $cov['keep_mc'] + $cov['paid_mc'] : $cov['keep'] + $cov['paid'];
    $tgt = ($v['accepted'] && !$cov['recorded']) ? $cur : min($cur, max(0.0, $v['received'][$k] + $cover));
    $pos = 0.0;
    foreach ($v['rates'] as $r) {
        $pos += max(0.0, $r[$k]);
    }
    $ratio = ($cur > 0.005 && $pos > 0.005) ? max(0.0, $tgt / $pos) : 0.0;
    $cols = array('ht', 'tva', 'ttc', 'mht', 'mtva', 'mttc');
    $v['target'] = array('rates' => array()) + array_fill_keys($cols, 0.0);
    foreach ($v['rates'] as $rk => $r) {
        $t = $r;
        foreach ($cols as $col) {
            $t[$col] = $ratio >= 0.999999 && $ratio <= 1.000001 ? $r[$col] : (float) price2num($r[$col] * $ratio, 'MT');
            if ($r[$k] <= 0) {
                $t[$col] = 0.0;
            }
            $v['target'][$col] += $t[$col];
        }
        $v['target']['rates'][$rk] = $t;
    }
    foreach ($cols as $col) {
        $v['target'][$col] = (float) price2num($v['target'][$col], 'MT');
    }
    return $v;
}

/**
 * @brief Deposit credit rows
 * @param DoliDB $db
 * @param int $depositId
 * @param string $element facture|invoice_supplier
 * @return array
 */
function ksefDepositRows($db, $depositId, $element = 'facture')
{
    $out = array();
    $k = ksefInvoiceKind($element);
    $res = $db->query("SELECT re.rowid, re.tva_tx, re.vat_src_code, re.amount_ht, re.amount_tva, re.amount_ttc, re.multicurrency_amount_ht,"
        . " re.multicurrency_amount_tva, re.multicurrency_amount_ttc, re." . $k['col'] . " AS fk_facture, re." . $k['col_line'] . " AS fk_facture_line, d." . $k['line_fk'] . " AS line_inv"
        . " FROM " . MAIN_DB_PREFIX . "societe_remise_except AS re LEFT JOIN " . MAIN_DB_PREFIX . $k['line_table'] . " AS d ON d.rowid = re." . $k['col_line']
        . " WHERE re." . $k['col_source'] . " = " . (int) $depositId . " AND re.description = '" . KSEF_DEPOSIT_CREDIT_DESCRIPTION . "' ORDER BY re.rowid");
    while ($res && ($o = $db->fetch_object($res))) {
        $out[] = array('id' => (int) $o->rowid, 'key' => ksefDepositRateKey(abs((float) $o->tva_tx), (string) $o->vat_src_code),
            'tva_tx' => abs((float) $o->tva_tx), 'code' => (string) $o->vat_src_code,
            'ht' => (float) $o->amount_ht, 'tva' => (float) $o->amount_tva, 'ttc' => (float) $o->amount_ttc,
            'mht' => (float) $o->multicurrency_amount_ht, 'mtva' => (float) $o->multicurrency_amount_tva, 'mttc' => (float) $o->multicurrency_amount_ttc,
            'line' => (int) $o->fk_facture_line, 'inv' => (int) $o->fk_facture > 0 ? (int) $o->fk_facture : (int) $o->line_inv);
    }
    return $out;
}

/**
 * @brief Deposits to recheck
 * @param DoliDB $db
 * @param string $element facture|invoice_supplier
 * @param int $socid 0 if unknown
 * @return array
 */
function ksefDepositsWithCredit($db, $element = 'facture', $socid = 0)
{
    $k = ksefInvoiceKind($element);
    $P = MAIN_DB_PREFIX;
    $out = array();
    $onValidated = "EXISTS (SELECT 1 FROM " . $P . "societe_remise_except AS u LEFT JOIN " . $P . $k['line_table'] . " AS ul ON ul.rowid = u." . $k['col_line']
        . " INNER JOIN " . $P . $k['table'] . " AS ui ON ui.rowid = COALESCE(u." . $k['col'] . ", ul." . $k['line_fk'] . ")"
        . " WHERE u." . $k['col_source'] . " = f.rowid AND u.description = '" . KSEF_DEPOSIT_CREDIT_DESCRIPTION . "' AND ui.fk_statut > 0)";
    $res = $db->query("SELECT DISTINCT f.rowid FROM " . $P . $k['table'] . " AS f INNER JOIN " . $P . "societe_remise_except AS re ON re." . $k['col_source'] . " = f.rowid"
        . " WHERE f.type = 3 AND f.entity IN (" . getEntity($k['entity']) . ") AND re.description = '" . KSEF_DEPOSIT_CREDIT_DESCRIPTION . "'"
        . ((int) $socid > 0 ? " AND f.fk_soc = " . (int) $socid : " AND NOT " . $onValidated) . " ORDER BY f.rowid");
    while ($res && ($o = $db->fetch_object($res))) {
        $out[] = (int) $o->rowid;
    }
    return $out;
}

/**
 * @brief Copied deposit lines
 * @param DoliDB $db
 * @param int $invoiceId Korekta ID
 * @param int $depositId
 * @return array
 */
function ksefDepositCopiedLines($db, $invoiceId, $depositId)
{
    $own = array();
    foreach (ksefDepositRows($db, $depositId) as $r) {
        $own[$r['id']] = true;
    }
    $deps = ksefSettlementDeposits($db, (int) $invoiceId);
    $out = array();
    foreach ((isset($deps[$depositId]) ? $deps[$depositId]['lines'] : array()) as $l) {
        if (isset($own[(int) $l['discount']])) {
            continue;
        }
        $k = ksefDepositRateKey($l['tva_tx'], $l['code']);
        if (!isset($out[$k])) {
            $out[$k] = array('tva_tx' => $l['tva_tx'], 'code' => $l['code'], 'ht' => 0.0, 'tva' => 0.0, 'ttc' => 0.0, 'mht' => 0.0, 'mtva' => 0.0, 'mttc' => 0.0, 'lines' => array());
        }
        foreach (array('ht', 'tva', 'ttc', 'mht', 'mtva', 'mttc') as $col) {
            $out[$k][$col] = (float) price2num($out[$k][$col] + $l[$col], 'MT');
        }
        $out[$k]['lines'][] = $l;
    }
    return $out;
}

/**
 * @brief Lines at rate
 * @param DoliDB $db
 * @param int $invoiceId
 * @param string $element facture|invoice_supplier
 * @return int -1 on error
 */
function ksefDepositLinesAtInvoiceRate($db, $invoiceId, $element = 'facture')
{
    global $conf;
    $P = MAIN_DB_PREFIX;
    $k = ksefInvoiceKind($element);
    $scan = function ($id) use ($db, $P, $k, $conf) {
        $res = $db->query("SELECT multicurrency_code, multicurrency_tx, type, fk_facture_source FROM " . $P . $k['table'] . " WHERE rowid = " . (int) $id);
        $h = $res ? $db->fetch_object($res) : null;
        $out = array('h' => $h, 'lines' => array());
        $tx = $h ? (float) $h->multicurrency_tx : 0.0;
        if (!$h || $tx <= 0 || abs($tx - 1) < 1e-9 || empty($h->multicurrency_code) || $h->multicurrency_code === $conf->currency) {
            return $out;
        }
        $res = $db->query("SELECT rowid, total_ht, total_ttc, multicurrency_total_ht, multicurrency_total_tva FROM " . $P . $k['line_table']
            . " WHERE " . $k['line_fk'] . " = " . (int) $id . " AND fk_remise_except > 0 AND multicurrency_total_ttc <> 0");
        while ($res && ($o = $db->fetch_object($res))) {
            $ht = (float) price2num((float) $o->multicurrency_total_ht / $tx, 'MT');
            $ttc = (float) price2num($ht + (float) price2num((float) $o->multicurrency_total_tva / $tx, 'MT'), 'MT');
            if (abs($ttc - (float) $o->total_ttc) >= 0.01 || abs($ht - (float) $o->total_ht) >= 0.01) {
                $out['lines'][(int) $o->rowid] = array('ht' => (float) price2num((float) $o->total_ht - $ht, 'MT'), 'tva' => (float) price2num(((float) $o->total_ttc - $ttc) - ((float) $o->total_ht - $ht), 'MT'),
                    'ttc' => (float) price2num((float) $o->total_ttc - $ttc, 'MT'), 'mht' => 0.0, 'mtva' => 0.0, 'mttc' => 0.0);
            }
        }
        return $out;
    };
    $me = $scan($invoiceId);
    if (!$me['h']) {
        return -1;
    }
    if (!$me['lines'] || ($k['element'] === 'facture' && empty($GLOBALS['ksef_importing_sales']) && ksefInvoiceLeftDolibarr($db, (int) $invoiceId))) {
        return 0;
    }
    if ((int) $me['h']->type === 1 && (int) $me['h']->fk_facture_source > 0 && $scan((int) $me['h']->fk_facture_source)['lines']) {
        return 0;
    }
    foreach ($me['lines'] as $lineId => $d) {
        if (ksefInvoiceLineShift($db, $lineId, $invoiceId, $d, $element, false) !== '') {
            return -1;
        }
    }
    return count($me['lines']);
}

/**
 * @brief Rescale copied lines
 * @param DoliDB $db
 * @param array $holder Copied lines entry
 * @param array $new Amounts to deduct
 * @return string '' on success
 */
function ksefDepositCopiedLinesSet($db, $holder, $new)
{
    $cols = array('ht', 'tva', 'ttc', 'mht', 'mtva', 'mttc');
    $lines = array_values($holder['lines']);
    $n = count($lines);
    $given = array_fill_keys($cols, 0.0);
    foreach ($lines as $i => $l) {
        $v = array();
        foreach ($cols as $col) {
            if ($i === $n - 1) {
                $v[$col] = (float) price2num($new[$col] - $given[$col], 'MT');
            } else {
                $v[$col] = abs($holder[$col]) >= 0.005 ? (float) price2num($new[$col] * $l[$col] / $holder[$col], 'MT') : 0.0;
                $given[$col] += $v[$col];
            }
        }
        $d = array();
        foreach ($cols as $col) {
            $d[$col] = (float) price2num($v[$col] - $l[$col], 'MT');
        }
        $err = ksefInvoiceLineShift($db, $l['id'], $holder['inv'], $d);
        if ($err !== '') {
            return $err;
        }
        if (!$db->query("UPDATE " . MAIN_DB_PREFIX . "societe_remise_except SET amount_ht = " . $v['ht'] . ", amount_tva = " . $v['tva'] . ", amount_ttc = " . $v['ttc']
            . ", multicurrency_amount_ht = " . $v['mht'] . ", multicurrency_amount_tva = " . $v['mtva'] . ", multicurrency_amount_ttc = " . $v['mttc']
            . " WHERE rowid = " . (int) $l['discount'] . " AND (fk_facture_source IS NULL OR fk_facture_source = 0)")) {
            return 'discount ' . (int) $l['discount'] . ': ' . $db->lasterror();
        }
    }
    return '';
}

/**
 * @brief Supplier deposit cover
 * @param DoliDB $db
 * @param int $depositId
 * @param int $excludeId Settlement left out
 * @return array
 */
function ksefDepositCovers($db, $depositId, $excludeId = 0)
{
    $out = array();
    $state = ksefDepositState($db, $depositId, 'invoice_supplier');
    foreach ((array) ($state['dep_cover'] ?? array()) as $rozId => $rates) {
        if ((int) $rozId === (int) $excludeId && $excludeId > 0) {
            continue;
        }
        $res = $db->query("SELECT fk_statut FROM " . MAIN_DB_PREFIX . "facture_fourn WHERE rowid = " . (int) $rozId);
        $o = $res ? $db->fetch_object($res) : null;
        if (!$o || !in_array((int) $o->fk_statut, array(0, 1, 2), true)) {
            continue;
        }
        foreach ((array) $rates as $rk => $c) {
            if (!isset($out[$rk])) {
                $out[$rk] = array('kind' => 'cover', 'inv' => (int) $rozId, 'id' => 0, 'line' => 0, 'tva_tx' => (float) ($c['tva_tx'] ?? 0), 'code' => (string) ($c['code'] ?? ''),
                    'ht' => 0.0, 'tva' => 0.0, 'ttc' => 0.0, 'mht' => 0.0, 'mtva' => 0.0, 'mttc' => 0.0);
            }
            foreach (array('ht', 'tva', 'ttc', 'mht', 'mtva', 'mttc') as $col) {
                $out[$rk][$col] = (float) price2num($out[$rk][$col] + (float) ($c[$col] ?? 0), 'MT');
            }
        }
    }
    return $out;
}

/**
 * @brief Covered deposits
 * @param DoliDB $db
 * @param int $rozId Settlement ID
 * @return array|false false on error
 */
function ksefDepositsCoveredBy($db, $rozId)
{
    $out = array();
    if (!ksefCorrectionStateStorable($db, 'invoice_supplier')) {
        return $out;
    }
    $res = $db->query("SELECT fk_object, ksef_correction_state FROM " . MAIN_DB_PREFIX . "facture_fourn_extrafields WHERE ksef_correction_state LIKE '%dep_cover%'");
    if (!$res) {
        return false;
    }
    while ($o = $db->fetch_object($res)) {
        $state = json_decode((string) $o->ksef_correction_state, true);
        if (is_array($state) && isset($state['dep_cover'][(int) $rozId])) {
            $out[] = (int) $o->fk_object;
        }
    }
    return $out;
}

/**
 * @brief Record deposit cover
 * @param DoliDB $db
 * @param int $depositId
 * @param int $rozId Settlement ID
 * @param array $rates Empty removes
 * @return bool
 */
function ksefDepositCoverSet($db, $depositId, $rozId, $rates)
{
    $state = ksefDepositState($db, $depositId, 'invoice_supplier');
    $covers = (array) ($state['dep_cover'] ?? array());
    if ($rates) {
        $covers[(int) $rozId] = $rates;
    } else {
        unset($covers[(int) $rozId]);
    }
    return ksefDepositStateSet($db, $depositId, array('dep_cover' => $covers ?: null), 'invoice_supplier');
}

/**
 * @brief Sync deposit credit
 * @param DoliDB $db
 * @param User $user
 * @param int $depositId
 * @param int $excludeId Being deleted
 * @param bool|string $convert Create missing credit
 * @param int $excludePayment Being deleted
 * @param string $element facture|invoice_supplier
 * @param bool $dryRun
 * @return array
 */
function ksefDepositRestate($db, $user, $depositId, $excludeId = 0, $convert = false, $excludePayment = 0, $element = 'facture', $dryRun = false)
{
    global $langs;
    require_once DOL_DOCUMENT_ROOT . '/core/class/discount.class.php';
    require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
    $P = MAIN_DB_PREFIX;
    $kind = ksefInvoiceKind($element);
    $supplier = $kind['element'] === 'invoice_supplier';
    $out = array('result' => 0, 'error' => '', 'created' => array(), 'adjusted' => array(), 'locked' => array(), 'plan' => array(), 'touched' => array());
    $t = ksefDepositCreditTarget($db, $depositId, $excludeId, $excludePayment, $kind['element']);
    if (!$t) {
        return $out;
    }
    $cols = array('ht', 'tva', 'ttc', 'mht', 'mtva', 'mttc');
    $K = $t['key'];
    $fail = function ($what) use ($db, &$out) {
        $out['result'] = -1;
        $out['error'] = $what . ': ' . $db->lasterror();
        dol_syslog('ksefDepositRestate: ' . $out['error'], LOG_ERR);
        return $out;
    };
    $create = function ($rate) use ($db, $user, $t, $supplier, $dryRun, &$out) {
        if ($dryRun) {
            $out['plan'][] = array('op' => 'create', 'id' => 0, 'inv' => 0, 'from' => 0.0, 'to' => (float) $rate[$t['key']]);
            $out['created'][] = 0;
            return 1;
        }
        $d = new DiscountAbsolute($db);
        $d->description = KSEF_DEPOSIT_CREDIT_DESCRIPTION;
        $d->discount_type = $supplier ? 1 : 0;
        $d->fk_soc = $d->socid = $t['socid'];
        if ($supplier) {
            $d->fk_invoice_supplier_source = $t['id'];
        } else {
            $d->fk_facture_source = $t['id'];
        }
        $d->amount_ht = $rate['ht'];
        $d->amount_tva = $rate['tva'];
        $d->amount_ttc = $rate['ttc'];
        $d->multicurrency_code = $t['currency'];
        $d->multicurrency_tx = $t['tx'];
        $d->multicurrency_amount_ht = $rate['mht'];
        $d->multicurrency_amount_tva = $rate['mtva'];
        $d->multicurrency_amount_ttc = $rate['mttc'];
        $d->tva_tx = $rate['tva_tx'];
        $d->vat_src_code = $rate['code'];
        if ($d->create($user) <= 0) {
            $out['result'] = -1;
            $out['error'] = 'deposit credit not created: ' . $d->error;
            return 0;
        }
        $out['created'][] = (int) $d->id;
        return (int) $d->id;
    };
    $rows = ksefDepositRows($db, $depositId, $kind['element']);
    if (!$rows) {
        if ($convert !== 'open' && (!$convert || !$t['full'] || (int) $t['active'] === (int) $depositId)) {
            return $out;
        }
        foreach ($t['target']['rates'] as $rate) {
            if ($rate[$K] > 0.005 && !$create($rate)) {
                return $out;
            }
        }
        if ($out['created'] && !$dryRun && !ksefDepositConversionRecord($db, $depositId, 'received', $kind['element'])) {
            return $fail('conversion state');
        }
        $out['result'] = $out['created'] ? 1 : 0;
        if ($dryRun) {
            return $out;
        }
        dol_syslog('ksefDepositRestate: deposit ' . $t['ref'] . ' credit created at ' . $t['target'][$K] . ' (rows ' . implode(',', $out['created']) . ')', LOG_INFO);
        return $out;
    }
    if (!$dryRun && ksefDepositConversion($db, $depositId, true, $kind['element']) === '' && !ksefDepositConversionRecord($db, $depositId, $t['accepted'] ? 'accepted' : 'received', $kind['element'])) {
        return $fail('conversion state');
    }
    $sent = $booked = array();
    $isSent = function ($id) use ($db, $kind, &$sent, &$booked) {
        if (!isset($sent[$id])) {
            $sent[$id] = ksefInvoiceSentToKsef($db, $id, $kind['element']);
            if (!$sent[$id] && ksefInvoiceInAccounting($db, $id, $kind['element'])) {
                $sent[$id] = $booked[$id] = true;
            }
        }
        return $sent[$id];
    };
    $owes = array();
    $owe = function ($id) use ($db, $kind, $t, &$owes) {
        if (!isset($owes[$id])) {
            $cls = $kind['class'];
            $f = new $cls($db);
            $owes[$id] = ($f->fetch($id) > 0 && (int) $f->status !== Facture::STATUS_ABANDONED) ? max(0.0, (float) $f->getRemainToPay($t['foreign'] ? 1 : 0)) : 0.0;
        }
        return $owes[$id];
    };
    $groups = array();
    $activeOf = array();
    foreach ($supplier ? ksefDepositCovers($db, $depositId) : array() as $rk => $c) {
        $groups[$rk][] = $c;
    }
    foreach ($rows as $r) {
        if ($supplier) {
            $groups[$r['key']][] = $r + array('kind' => 'row');
            continue;
        }
        if ($r['inv'] > 0 && !isset($activeOf[$r['inv']])) {
            $activeOf[$r['inv']] = ksefSettlementActive($db, $r['inv']);
            $a = $activeOf[$r['inv']];
            if ($a !== (int) $r['inv'] && !isset($groups['copy:' . $a])) {
                $groups['copy:' . $a] = true;
                foreach (ksefDepositCopiedLines($db, $a, $depositId) as $rk => $c) {
                    $groups[$rk][] = $c + array('kind' => 'copy', 'inv' => $a, 'id' => 0, 'line' => 0);
                }
            }
        }
        if ($r['inv'] > 0 && $activeOf[$r['inv']] !== (int) $r['inv']) {
            continue;
        }
        $groups[$r['key']][] = $r + array('kind' => 'row');
    }
    foreach (array_keys($groups) as $gk) {
        if (strpos((string) $gk, 'copy:') === 0) {
            unset($groups[$gk]);
        }
    }
    $touched = array();
    $zero = array_fill_keys($cols, 0.0);
    foreach (array_unique(array_merge(array_keys($t['target']['rates']), array_keys($groups))) as $rk) {
        $tg = isset($t['target']['rates'][$rk]) ? $t['target']['rates'][$rk] : $zero;
        if (empty($groups[$rk])) {
            if ($tg[$K] > 0.005 && !$create($tg)) {
                return $out;
            }
            continue;
        }
        $rem = $tg;
        $locked = 0;
        $used = $free = $covers = array();
        foreach ($groups[$rk] as $r) {
            if ($r['kind'] === 'cover') {
                $covers[] = $r;
            } elseif ($r['inv'] > 0 && $isSent($r['inv'])) {
                foreach ($cols as $col) {
                    $rem[$col] -= $r[$col];
                }
                $locked = $r['inv'];
            } elseif ($r['inv'] > 0) {
                $used[] = $r;
            } else {
                $free[] = $r;
            }
        }
        foreach ($covers as $c) {
            $share = $c[$K] > 0.005 ? min(1.0, max(0.0, $rem[$K]) / $c[$K]) : 0.0;
            if ($share <= 0) {
                continue;
            }
            foreach ($cols as $col) {
                $rem[$col] -= $share >= 1.0 ? $c[$col] : (float) price2num($c[$col] * $share, 'MT');
            }
            $locked = $c['inv'];
        }
        $adj = array_merge($used, $free);
        if (!$adj || $rem[$K] < -0.005) {
            if (abs($rem[$K]) >= 0.005) {
                $out['locked'][] = $locked;
            }
            if (!$adj) {
                continue;
            }
        }
        $left = max(0.0, $rem[$K]);
        $newK = array();
        foreach ($used as $i => $r) {
            $newK[$i] = min($r[$K], $left);
            $left -= $newK[$i];
        }
        $n = count($used);
        foreach ($free as $j => $r) {
            $newK[$n + $j] = $j === 0 ? $left : 0.0;
        }
        if (!$free && $left > 0.005) {
            for ($i = $n - 1; $i >= 0 && $left > 0.005; $i--) {
                $grow = min($left, $owe($used[$i]['inv']));
                $newK[$i] += $grow;
                $left -= $grow;
                $owes[$used[$i]['inv']] -= $grow;
            }
            if ($left > 0.005) {
                $adj[] = array('kind' => 'new', 'id' => 0, 'inv' => 0, 'line' => 0, 'tva_tx' => $tg['tva_tx'] ?? 0, 'code' => $tg['code'] ?? '') + $zero;
                $newK[] = $left;
            }
        }
        $kept = $zero;
        $changed = array();
        foreach ($adj as $i => $r) {
            if (abs($newK[$i] - $r[$K]) < 0.005) {
                foreach ($cols as $col) {
                    $kept[$col] += $r[$col];
                }
            } else {
                $changed[] = $i;
            }
        }
        if (!$changed) {
            continue;
        }
        $pool = array();
        foreach ($cols as $col) {
            $pool[$col] = max(0.0, $rem[$col]) - $kept[$col];
        }
        $poolK = 0.0;
        foreach ($changed as $i) {
            $poolK += $newK[$i];
        }
        $last = end($changed);
        $given = $zero;
        foreach ($changed as $i) {
            $r = $adj[$i];
            $new = array();
            foreach ($cols as $col) {
                if ($i === $last) {
                    $new[$col] = (float) price2num($pool[$col] - $given[$col], 'MT');
                } else {
                    $new[$col] = $poolK > 0 ? (float) price2num($pool[$col] * $newK[$i] / $poolK, 'MT') : 0.0;
                    $given[$col] += $new[$col];
                }
                if ($poolK <= 0) {
                    $new[$col] = 0.0;
                }
            }
            if ($r['kind'] === 'new') {
                if (!$create(array('tva_tx' => $r['tva_tx'], 'code' => $r['code']) + $new)) {
                    return $out;
                }
                $out['result'] = 1;
                continue;
            }
            if ($dryRun) {
                $out['plan'][] = array('op' => $r['kind'] === 'copy' ? 'copy' : 'row', 'id' => (int) $r['id'], 'inv' => (int) $r['inv'], 'from' => (float) $r[$K], 'to' => (float) $new[$K]);
                if ($r['inv'] > 0) {
                    $touched[$r['inv']] = true;
                }
                $out['result'] = 1;
                continue;
            }
            if ($r['kind'] === 'copy') {
                $err = ksefDepositCopiedLinesSet($db, $r, $new);
                if ($err !== '') {
                    return $fail($err);
                }
                $touched[$r['inv']] = true;
                $out['result'] = 1;
                continue;
            }
            $sql = "UPDATE " . $P . "societe_remise_except SET amount_ht = " . $new['ht'] . ", amount_tva = " . $new['tva'] . ", amount_ttc = " . $new['ttc']
                . ", multicurrency_amount_ht = " . $new['mht'] . ", multicurrency_amount_tva = " . $new['mtva'] . ", multicurrency_amount_ttc = " . $new['mttc']
                . " WHERE rowid = " . (int) $r['id'];
            if (!$db->query($sql)) {
                return $fail('row ' . $r['id']);
            }
            if ($r['inv'] > 0) {
                $d = array();
                foreach ($cols as $col) {
                    $d[$col] = (float) price2num($new[$col] - $r[$col], 'MT');
                }
                if ($r['line'] > 0) {
                    // set per bucket
                    $err = ksefInvoiceLineShift($db, $r['line'], $r['inv'], $d, $kind['element'], !$supplier);
                    if ($err !== '') {
                        return $fail($err);
                    }
                }
                $touched[$r['inv']] = true;
            }
            $out['result'] = 1;
        }
    }
    if ($out['result'] < 0) {
        return $out;
    }
    if ($out['created']) {
        $out['result'] = 1;
    }
    $out['touched'] = array_keys($touched);
    if ($dryRun) {
        $out['locked'] = array_values(array_unique(array_filter($out['locked'])));
        return $out;
    }
    foreach (array_keys($touched) as $invId) {
        // must match P_14_xW
        if (!$supplier) {
            $derived = ksefDerivePlnFromForeign($db, (int) $invId);
            if ($derived['result'] < 0) {
                return $fail('PLN amounts of invoice ' . $invId . ' (' . $derived['error'] . ')');
            }
        } else {
            $perBucket = ksefSupplierPlnPerBucket($db, (int) $invId);
            if ($perBucket['result'] < 0) {
                return $fail('PLN amounts of supplier invoice ' . $invId . ' (' . $perBucket['error'] . ')');
            }
        }
        $cls = $kind['class'];
        $f = new $cls($db);
        if ($f->fetch($invId) <= 0) {
            return $fail('invoice ' . $invId);
        }
        $out['adjusted'][] = $f->ref;
        if ((int) $f->status === Facture::STATUS_DRAFT) {
            continue;
        }
        $remain = (float) $f->getRemainToPay($t['foreign'] ? 1 : 0);
        // skip setPaid() triggers
        $sql = '';
        if ((int) $f->paye === 1 && $remain > 0.005) {
            $sql = "UPDATE " . $P . $kind['table'] . " SET fk_statut = " . Facture::STATUS_VALIDATED . ", paye = 0, close_code = NULL, fk_user_closing = NULL, date_closing = NULL WHERE rowid = " . (int) $invId;
        } elseif ((int) $f->status === Facture::STATUS_VALIDATED && (int) $f->paye === 0 && abs($remain) < 0.005) {
            $sql = "UPDATE " . $P . $kind['table'] . " SET fk_statut = " . Facture::STATUS_CLOSED . ", paye = 1, fk_user_closing = " . (int) $user->id
                . ", date_closing = '" . $db->idate(dol_now()) . "' WHERE rowid = " . (int) $invId;
        }
        if ($sql !== '' && !$db->query($sql)) {
            return $fail('status of invoice ' . $invId);
        }
    }
    $out['locked'] = array_values(array_unique(array_filter($out['locked'])));
    if ($out['adjusted'] && is_object($langs)) {
        $langs->load('ksef@ksef');
        setEventMessages($langs->trans('KSEF_DepositFinalAdjusted', $t['ref'], implode(', ', $out['adjusted'])), null, 'warnings');
    }
    $inLedger = array_intersect($out['locked'], array_keys($booked));
    if ($inLedger && is_object($langs)) {
        $refs = array();
        foreach ($inLedger as $invId) {
            $res = $db->query("SELECT ref FROM " . $P . $kind['table'] . " WHERE rowid = " . (int) $invId);
            $o = $res ? $db->fetch_object($res) : null;
            $refs[] = $o ? (string) $o->ref : (string) $invId;
        }
        $langs->load('ksef@ksef');
        setEventMessages($langs->trans('KSEF_DepositFinalInAccounting', $t['ref'], implode(', ', $refs)), null, 'warnings');
    }
    if ($out['result'] > 0 || $out['locked']) {
        dol_syslog('ksefDepositRestate: deposit ' . $t['ref'] . ' credit to ' . $t['target'][$K] . ' (value ' . $t[$K] . ', received ' . $t['received'][$K] . ')'
            . ($out['adjusted'] ? ', adjusted ' . implode(',', $out['adjusted']) : '') . ($out['locked'] ? ', sent invoices left as they are: ' . implode(',', $out['locked']) : ''), LOG_INFO);
    }
    return $out;
}

/**
 * @brief Invoice in force
 * @param DoliDB $db
 * @param int $invoiceId
 * @return int
 */
function ksefSettlementActive($db, $invoiceId)
{
    $cur = (int) $invoiceId;
    $seen = array($cur => true);
    for ($i = 0; $cur > 0 && $i < 20; $i++) {
        $res = $db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "facture WHERE fk_facture_source = " . $cur . " AND type = 1 AND fk_statut IN (1, 2) ORDER BY rowid DESC LIMIT 1");
        $o = $res ? $db->fetch_object($res) : null;
        if (!$o || isset($seen[(int) $o->rowid])) {
            break;
        }
        $cur = (int) $o->rowid;
        $seen[$cur] = true;
    }
    return $cur;
}

/**
 * @brief Settlement deducted deposits
 * @param DoliDB $db
 * @param int $invoiceId
 * @return array
 */
function ksefSettlementDeposits($db, $invoiceId)
{
    $P = MAIN_DB_PREFIX;
    $out = array();
    $add = function ($depId, $mode, $l) use (&$out) {
        if (!isset($out[$depId])) {
            $out[$depId] = array('id' => $depId, 'mode' => $mode, 'ttc' => 0.0, 'mttc' => 0.0, 'lines' => array());
        }
        $out[$depId]['ttc'] = (float) price2num($out[$depId]['ttc'] + $l['ttc'], 'MT');
        $out[$depId]['mttc'] = (float) price2num($out[$depId]['mttc'] + $l['mttc'], 'MT');
        if ($mode === 'line') {
            $out[$depId]['lines'][] = $l;
        }
    };
    // PLN from discount
    $res = $db->query("SELECT d.rowid, d.fk_remise_except, d.fk_prev_id, d.tva_tx, d.vat_src_code, COALESCE(re.amount_ht, -d.total_ht) AS ht, COALESCE(re.amount_tva, -d.total_tva) AS tva,"
        . " COALESCE(re.amount_ttc, -d.total_ttc) AS ttc, d.multicurrency_total_ht, d.multicurrency_total_tva, d.multicurrency_total_ttc FROM " . $P . "facturedet AS d"
        . " LEFT JOIN " . $P . "societe_remise_except AS re ON re.rowid = d.fk_remise_except WHERE d.fk_facture = " . (int) $invoiceId . " AND d.fk_remise_except > 0 ORDER BY d.rang, d.rowid");
    $lines = array();
    while ($res && ($o = $db->fetch_object($res))) {
        $lines[] = $o;
    }
    foreach ($lines as $o) {
        $re = (int) $o->fk_remise_except;
        $prev = (int) $o->fk_prev_id;
        $depId = 0;
        for ($i = 0; $i < 20 && ($re > 0 || $prev > 0); $i++) {
            if ($re > 0) {
                $r = $db->query("SELECT re.fk_facture_source, re.description, src.type FROM " . $P . "societe_remise_except AS re"
                    . " LEFT JOIN " . $P . "facture AS src ON src.rowid = re.fk_facture_source WHERE re.rowid = " . $re);
                $d = $r ? $db->fetch_object($r) : null;
                if ($d && (int) $d->fk_facture_source > 0) {
                    if ((int) $d->type === 3 && (string) $d->description === KSEF_DEPOSIT_CREDIT_DESCRIPTION) {
                        $depId = (int) $d->fk_facture_source;
                    }
                    break;
                }
            }
            if ($prev <= 0) {
                break;
            }
            $r = $db->query("SELECT fk_remise_except, fk_prev_id FROM " . $P . "facturedet WHERE rowid = " . $prev);
            $pl = $r ? $db->fetch_object($r) : null;
            if (!$pl) {
                break;
            }
            $re = (int) $pl->fk_remise_except;
            $prev = (int) $pl->fk_prev_id;
        }
        if ($depId > 0) {
            $add($depId, 'line', array('id' => (int) $o->rowid, 'discount' => (int) $o->fk_remise_except, 'tva_tx' => abs((float) $o->tva_tx), 'code' => (string) $o->vat_src_code,
                'ht' => (float) $o->ht, 'tva' => (float) $o->tva, 'ttc' => (float) $o->ttc,
                'mht' => -(float) $o->multicurrency_total_ht, 'mtva' => -(float) $o->multicurrency_total_tva, 'mttc' => -(float) $o->multicurrency_total_ttc));
        }
    }
    $res = $db->query("SELECT re.fk_facture_source, re.amount_ttc, re.multicurrency_amount_ttc FROM " . $P . "societe_remise_except AS re"
        . " JOIN " . $P . "facture AS src ON src.rowid = re.fk_facture_source AND src.type = 3"
        . " WHERE re.fk_facture = " . (int) $invoiceId . " AND (re.fk_facture_line IS NULL OR re.fk_facture_line = 0)"
        . " AND re.description = '" . KSEF_DEPOSIT_CREDIT_DESCRIPTION . "'");
    while ($res && ($o = $db->fetch_object($res))) {
        $add((int) $o->fk_facture_source, 'payment', array('ttc' => (float) $o->amount_ttc, 'mttc' => (float) $o->multicurrency_amount_ttc));
    }
    return $out;
}

/**
 * @brief Effective deposit credit
 * @param DoliDB $db
 * @param int $depositId
 * @param string $K ttc|mttc
 * @return array
 */
function ksefDepositEffectiveCredit($db, $depositId, $K)
{
    $out = array('total' => 0.0, 'by' => array());
    $replaced = array();
    foreach (ksefDepositRows($db, $depositId) as $r) {
        if ($r['inv'] <= 0) {
            $out['total'] += $r[$K];
            continue;
        }
        $active = ksefSettlementActive($db, $r['inv']);
        if ($active !== (int) $r['inv']) {
            $replaced[$active] = true;
            continue;
        }
        $out['by'][$active] = ($out['by'][$active] ?? 0.0) + $r[$K];
        $out['total'] += $r[$K];
    }
    foreach (array_keys($replaced) as $active) {
        foreach (ksefDepositCopiedLines($db, $active, $depositId) as $c) {
            $out['by'][$active] = ($out['by'][$active] ?? 0.0) + $c[$K];
            $out['total'] += $c[$K];
        }
    }
    $out['total'] = (float) price2num($out['total'], 'MT');
    return $out;
}

/**
 * @brief Settlement needs correction
 * @param DoliDB $db
 * @param int $finalId Settlement ID
 * @return array
 */
function ksefSettlementNeedsCorrection($db, $finalId)
{
    if ((int) $finalId <= 0 || (!ksefInvoiceSentToKsef($db, (int) $finalId) && !ksefInvoiceInAccounting($db, (int) $finalId))
        || ksefSettlementActive($db, (int) $finalId) !== (int) $finalId) {
        return array();
    }
    return ksefSettlementDiffs($db, (int) $finalId);
}

/**
 * @brief Off-target settlement deposits
 * @param DoliDB $db
 * @param int $invoiceId Settlement ID
 * @return array
 */
function ksefSettlementDiffs($db, $invoiceId)
{
    $out = array();
    foreach (ksefSettlementDeposits($db, (int) $invoiceId) as $depId => $d) {
        $t = ksefDepositCreditTarget($db, $depId);
        if (!$t) {
            continue;
        }
        $K = $t['key'];
        $credit = ksefDepositEffectiveCredit($db, $depId, $K)['total'];
        $want = $t['target'][$K];
        if (abs($want - $credit) >= 0.005) {
            $out[$depId] = array('id' => (int) $depId, 'ref' => $t['ref'], 'mode' => $d['mode'], 'currency' => $t['currency'], 'key' => $K, 'deducted' => (float) price2num($d[$K], 'MT'),
                'credit' => $credit, 'current' => $want, 'diff' => (float) price2num($want - $credit, 'MT'),
                'settle' => (float) price2num(max(0.0, $d[$K] + $want - $credit), 'MT'));
        }
    }
    return $out;
}

/**
 * @brief Deposit deduction mismatch
 * @param DoliDB $db
 * @param int $invoiceId Settlement ID
 * @return array
 */
function ksefSettlementDepositMismatch($db, $invoiceId)
{
    $out = array();
    if ((int) $invoiceId <= 0 || ksefSettlementActive($db, (int) $invoiceId) !== (int) $invoiceId) {
        return $out;
    }
    foreach (ksefSettlementDiffs($db, (int) $invoiceId) as $depId => $n) {
        $eff = ksefDepositEffectiveCredit($db, $depId, $n['key']);
        $mine = (float) ($eff['by'][(int) $invoiceId] ?? 0.0);
        if (!isset($eff['by'][(int) $invoiceId]) || abs($eff['total'] - $mine) >= 0.005 || abs($mine - $n['deducted']) >= 0.005) {
            continue;
        }
        $out[] = array('id' => (int) $depId, 'ref' => $n['ref'], 'deducted' => $n['deducted'], 'current' => $n['current'], 'currency' => $n['currency']);
    }
    return $out;
}

/**
 * @brief Stale sent settlements
 * @param DoliDB $db
 * @param int $depositId Chain root
 * @return array
 */
function ksefDepositSettlementsToCorrect($db, $depositId)
{
    $out = array();
    $seen = array();
    foreach (ksefDepositRows($db, $depositId) as $r) {
        if ($r['inv'] <= 0) {
            continue;
        }
        $active = ksefSettlementActive($db, $r['inv']);
        if (isset($seen[$active])) {
            continue;
        }
        $seen[$active] = true;
        $need = ksefSettlementNeedsCorrection($db, $active);
        if (isset($need[$depositId])) {
            $res = $db->query("SELECT ref FROM " . MAIN_DB_PREFIX . "facture WHERE rowid = " . (int) $active);
            $o = $res ? $db->fetch_object($res) : null;
            $out[$active] = $need[$depositId] + array('inv' => $active, 'inv_ref' => $o ? (string) $o->ref : '');
        }
    }
    return $out;
}

/**
 * @brief Refresh deposit lines
 * @param DoliDB $db
 * @param int $replacementId Draft ID
 * @param int $sourceId Corrected settlement
 * @param bool $atValidate
 * @return array
 */
function ksefSettlementPrefill($db, $replacementId, $sourceId, $atValidate = false)
{
    require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
    $P = MAIN_DB_PREFIX;
    $out = array('result' => 0, 'error' => '', 'changed' => array(), 'missing' => array());
    if ($atValidate) {
        $need = ksefInvoiceSentToKsef($db, (int) $sourceId) ? ksefSettlementDiffs($db, (int) $replacementId) : array();
    } else {
        $need = ksefSettlementNeedsCorrection($db, (int) $sourceId);
    }
    if (!$need) {
        return $out;
    }
    $deps = ksefSettlementDeposits($db, (int) $replacementId);
    $cols = array('ht', 'tva', 'ttc', 'mht', 'mtva', 'mttc');
    $draft = null;
    foreach ($need as $depId => $n) {
        if (empty($deps[$depId]['lines'])) {
            continue;
        }
        $t = ksefDepositCreditTarget($db, $depId);
        $K = $t['key'];
        $lines = $deps[$depId]['lines'];
        $have = array();
        foreach ($lines as $l) {
            $have[ksefDepositRateKey($l['tva_tx'], $l['code'])] = true;
        }
        $freeBy = array();
        $freeAll = 0.0;
        foreach (ksefDepositRows($db, $depId) as $r) {
            if ($r['inv'] <= 0 && $r[$K] >= 0.005) {
                $freeBy[$r['key']][] = $r;
                $freeAll += $r[$K];
            }
        }
        $gained = array();
        $gainedFree = 0.0;
        foreach ($t['target']['rates'] as $rk => $rate) {
            if ($rate[$K] >= 0.005 && !isset($have[(string) $rk])) {
                $gained[(string) $rk] = $rate;
                foreach ($freeBy[(string) $rk] ?? array() as $r) {
                    $gainedFree += $r[$K];
                }
            }
        }
        $added = 0.0;
        foreach ($gained as $rk => $rate) {
            if (empty($freeBy[$rk])) {
                dol_syslog('ksefSettlementPrefill: deposit ' . $n['ref'] . ' rate ' . $rk . ' has no free credit (used elsewhere), not added to ' . (int) $replacementId, LOG_INFO);
                continue;
            }
            if ($atValidate || $freeAll - $gainedFree >= 0.005) {
                $out['missing'][] = array('ref' => $n['ref'], 'rate' => $rk);
                dol_syslog('ksefSettlementPrefill: deposit ' . $n['ref'] . ' gained rate ' . $rk . ', not added to ' . (int) $replacementId . ': other free credit exists', LOG_WARNING);
                continue;
            }
            if (!$draft) {
                $draft = new Facture($db);
                if ($draft->fetch((int) $replacementId) <= 0) {
                    $out['result'] = -1;
                    $out['error'] = 'korekta ' . (int) $replacementId . ': ' . $draft->error;
                    return $out;
                }
            }
            foreach ($freeBy[$rk] as $r) {
                if ($draft->insert_discount($r['id']) <= 0) {
                    $out['missing'][] = array('ref' => $n['ref'], 'rate' => $rk);
                    dol_syslog('ksefSettlementPrefill: deposit row ' . $r['id'] . ' not added to ' . (int) $replacementId . ': ' . $draft->error, LOG_WARNING);
                    continue;
                }
                $added += $r[$K];
            }
        }
        $whole = abs($n['credit'] - $n['deducted'] - $added) < 0.005;
        $new = array();
        if ($whole) {
            $given = array();
            foreach ($lines as $i => $l) {
                $rk = ksefDepositRateKey($l['tva_tx'], $l['code']);
                $new[$i] = (isset($t['target']['rates'][$rk]) && !isset($given[$rk])) ? $t['target']['rates'][$rk] : array_fill_keys($cols, 0.0);
                $given[$rk] = true;
            }
        } else {
            $f = $n['deducted'] > 0.005 ? $n['settle'] / $n['deducted'] : 0.0;
            foreach ($lines as $i => $l) {
                foreach ($cols as $col) {
                    $new[$i][$col] = (float) price2num($l[$col] * $f, 'MT');
                }
                $new[$i]['ttc'] = (float) price2num($new[$i]['ht'] + $new[$i]['tva'], 'MT');
                $new[$i]['mttc'] = (float) price2num($new[$i]['mht'] + $new[$i]['mtva'], 'MT');
            }
        }
        foreach ($lines as $i => $l) {
            $v = $new[$i];
            $sql = "UPDATE " . $P . "facturedet SET subprice = " . (-$v['ht']) . ", total_ht = " . (-$v['ht']) . ", total_tva = " . (-$v['tva']) . ", total_ttc = " . (-$v['ttc'])
                . ", multicurrency_subprice = " . (-$v['mht']) . ", multicurrency_total_ht = " . (-$v['mht']) . ", multicurrency_total_tva = " . (-$v['mtva'])
                . ", multicurrency_total_ttc = " . (-$v['mttc']) . " WHERE rowid = " . (int) $l['id'] . " AND qty = 1";
            $sql2 = "UPDATE " . $P . "societe_remise_except SET amount_ht = " . $v['ht'] . ", amount_tva = " . $v['tva'] . ", amount_ttc = " . $v['ttc']
                . ", multicurrency_amount_ht = " . $v['mht'] . ", multicurrency_amount_tva = " . $v['mtva'] . ", multicurrency_amount_ttc = " . $v['mttc']
                . " WHERE rowid = " . (int) $l['discount'] . " AND (fk_facture_source IS NULL OR fk_facture_source = 0)";
            if (!$db->query($sql) || !$db->query($sql2)) {
                $out['result'] = -1;
                $out['error'] = 'deposit line ' . $l['id'] . ': ' . $db->lasterror();
                dol_syslog('ksefSettlementPrefill: ' . $out['error'], LOG_ERR);
                return $out;
            }
        }
        $to = $added;
        foreach ($new as $v) {
            $to += $v[$K];
        }
        $out['changed'][$depId] = array('ref' => $n['ref'], 'from' => $deps[$depId][$K], 'to' => (float) price2num($to, 'MT'), 'currency' => $t['currency']);
    }
    if (!$out['changed']) {
        return $out;
    }
    $f = new Facture($db);
    if ($f->fetch((int) $replacementId) <= 0 || $f->update_price(1) < 0) {
        $out['result'] = -1;
        $out['error'] = 'totals of ' . (int) $replacementId . ': ' . $f->error;
        dol_syslog('ksefSettlementPrefill: ' . $out['error'], LOG_ERR);
        return $out;
    }
    $out['result'] = 1;
    dol_syslog('ksefSettlementPrefill: korekta ' . $f->ref . ' deducts ' . implode(', ', array_map(function ($c) {
        return $c['ref'] . ' ' . $c['from'] . ' -> ' . $c['to'];
    }, $out['changed'])), LOG_INFO);
    return $out;
}

/**
 * @brief Last sent Zamowienie
 * @param DoliDB $db
 * @param int $depositId Chain root
 * @param int $excludeId Skipped invoice
 * @return array|null
 */
function ksefDepositSentOrder($db, $depositId, $excludeId = 0)
{
    $P = MAIN_DB_PREFIX;
    $ids = ksefCorrectionChainIds($db, $depositId, $excludeId);
    if (!$ids) {
        return null;
    }
    $res = $db->query("SELECT fa3_xml FROM " . $P . "ksef_submissions WHERE fk_facture IN (" . implode(',', $ids) . ")"
        . " AND (status = 'ACCEPTED' OR status = 'OFFLINE') AND fa3_xml IS NOT NULL" . ksefEnvironmentSql($db) . " ORDER BY rowid DESC");
    $xmls = array();
    while ($res && ($o = $db->fetch_object($res))) {
        $xmls[] = (string) $o->fa3_xml;
    }
    foreach ($xmls as $xml) {
        $doc = new DOMDocument();
        if ($xml === '' || !@$doc->loadXML($xml)) {
            continue;
        }
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('fa', KSEF_FA3_NAMESPACE);
        $z = $xp->query('//fa:Fa/fa:Zamowienie');
        if (!$z->length) {
            continue;
        }
        $value = $xp->query('fa:WartoscZamowienia', $z->item(0));
        $cur = $xp->query('//fa:Fa/fa:KodWaluty');
        $rows = array();
        $nums = array();
        $maxNr = 0;
        foreach ($xp->query('fa:ZamowienieWiersz', $z->item(0)) as $w) {
            $nr = $xp->query('fa:NrWierszaZam', $w);
            $nr = $nr->length ? (int) $nr->item(0)->textContent : 0;
            $maxNr = max($maxNr, $nr);
            if ($xp->query('fa:StanPrzedZ', $w)->length) {
                continue;
            }
            $fields = array();
            foreach ($w->childNodes as $c) {
                if ($c->nodeType === XML_ELEMENT_NODE && !in_array($c->localName, array('NrWierszaZam', 'StanPrzedZ'), true)) {
                    $fields[] = array($c->localName, $c->textContent);
                }
            }
            $rows[] = $fields;
            $nums[] = $nr > 0 ? $nr : count($rows);
        }
        return array('value' => $value->length ? (float) $value->item(0)->textContent : 0.0, 'rows' => $rows, 'nums' => $nums,
            'max_nr' => max($maxNr, $nums ? max($nums) : 0), 'currency' => $cur->length ? (string) $cur->item(0)->textContent : 'PLN');
    }
    return null;
}

/**
 * @brief Cached deposit context
 * @param DoliDB $db
 * @param Facture|null $invoice
 * @param bool $reset Clear cache
 * @return array|null
 */
function ksefDepositContextOnce($db, $invoice, $reset = false)
{
    static $memo = array();
    if ($reset) {
        $memo = array();
        return null;
    }
    if ((int) $invoice->id <= 0) {
        return ksefDepositContext($db, $invoice);
    }
    // spl id reuse
    $key = spl_object_id($invoice) . ':' . (int) $invoice->id;
    if (!isset($memo[$key])) {
        $memo[$key] = array($invoice, ksefDepositContext($db, $invoice));
    }
    return $memo[$key][1];
}

/**
 * @brief Invoice deposit context
 * @param DoliDB $db
 * @param Facture $invoice
 * @return array
 */
function ksefDepositContext($db, $invoice)
{
    require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
    $ctx = array('type' => 'VAT', 'deposits' => array(), 'mode' => '', 'zero' => false, 'order' => null, 'earlier' => array(),
        'last' => false, 'blocked' => array(), 'blocked_ids' => array(), 'blocked_info' => array(), 'mismatch' => array(), 'partial' => array(), 'pending' => array(),
        'source_type' => '', 'root_id' => 0, 'remaining' => 0.0, 'below_zero' => false, 'negative_rates' => array());
    $type = (int) $invoice->type;
    $currency = ksefGetInvoiceCurrency($invoice);
    $foreign = $currency !== 'PLN';
    $cite = function ($list) use ($db, &$ctx) {
        $out = array();
        foreach ($list as $d) {
            $c = ksefDepositCitation($db, $d['id']);
            if (!$c['citable']) {
                $key = $c['ref'] !== '' ? $c['ref'] : (string) $d['id'];
                $ctx['blocked'][$key] = $c['reason'];
                $ctx['blocked_ids'][$key] = (int) $d['id'];
                $ctx['blocked_info'][$key] = $c;
            }
            $out[] = $d + array('citation' => $c);
        }
        return $out;
    };
    if ($type === Facture::TYPE_DEPOSIT) {
        $ctx['type'] = 'ZAL';
        $ctx['order'] = ksefDepositOrder($db, $invoice);
        if ($ctx['order']) {
            $all = ksefOrderDeposits($db, $ctx['order']['type'], $ctx['order']['id'], (int) $invoice->id);
            $earlier = array();
            $sum = 0.0;
            $seen = false;
            foreach ($all as $d) {
                $sum += $foreign ? $d['net_mc_ttc'] : $d['net_ttc'];
                if ($d['id'] === (int) $invoice->id) {
                    $seen = true;
                    break;
                }
                $net = $foreign ? $d['net_mc_ttc'] : $d['net_ttc'];
                if (abs($net) >= 0.005 || $d['accepted']) {
                    $earlier[] = $d;
                }
            }
            $orderTotal = $foreign ? $ctx['order']['mc_total_ttc'] : $ctx['order']['total_ttc'];
            // art. 106f(4)
            if ($seen && $earlier && $orderTotal > 0 && $sum >= $orderTotal - 0.005) {
                $ctx['last'] = true;
                $ctx['earlier'] = $cite($earlier);
            }
        }
        return $ctx;
    }
    if ($type === Facture::TYPE_CREDIT_NOTE || $type === Facture::TYPE_REPLACEMENT) {
        $ctx['type'] = 'KOR';
        $root = ksefCorrectionRoot($db, $invoice);
        if ($root) {
            $ctx['root_id'] = (int) $root->id;
            $sent = ksefCorrectionSentType($db, $invoice, $root);
            if ($sent !== '') {
                $ctx['type'] = $sent;
                $ctx['source_type'] = $sent === 'KOR' ? '' : substr($sent, 4);
                if ($sent === 'KOR_ZAL' && (int) $root->type === Facture::TYPE_DEPOSIT) {
                    $ctx['order'] = ksefDepositOrder($db, $root);
                }
                return $ctx;
            }
            if ((int) $root->type === Facture::TYPE_DEPOSIT) {
                $ctx['type'] = 'KOR_ZAL';
                $ctx['source_type'] = 'ZAL';
                $ctx['order'] = ksefDepositOrder($db, $root);
            } elseif ((int) $root->type === Facture::TYPE_STANDARD) {
                $isRoz = (bool) ksefConsumedDeposits($db, $root->id);
                for ($cur = $invoice, $i = 0; !$isRoz && $cur && (int) $cur->id !== (int) $root->id && $i < 20; $i++) {
                    $isRoz = (bool) ksefConsumedDeposits($db, $cur->id);
                    $next = null;
                    if (!$isRoz && !empty($cur->fk_facture_source)) {
                        $next = new Facture($db);
                        if ($next->fetch((int) $cur->fk_facture_source) <= 0) {
                            $next = null;
                        }
                    }
                    $cur = $next;
                }
                if (!$isRoz) {
                    $src = new Facture($db);
                    if ($src->fetch((int) $invoice->fk_facture_source) > 0) {
                        $src->fetch_lines();
                        foreach ($src->lines as $l) {
                            if (ksefIsDepositLine($db, $l)) {
                                $isRoz = true;
                                break;
                            }
                        }
                    }
                }
                if ($isRoz) {
                    $ctx['type'] = 'KOR_ROZ';
                    $ctx['source_type'] = 'ROZ';
                }
            }
        }
        return $ctx;
    }
    if ($type !== Facture::TYPE_STANDARD) {
        return $ctx;
    }
    $deposits = ksefConsumedDeposits($db, $invoice->id);
    if ($deposits) {
        $ctx['type'] = 'ROZ';
        $first = reset($deposits);
        $ctx['mode'] = $first['mode'];
        foreach ($deposits as $d) {
            if ($d['currency'] !== $currency) {
                $ctx['mismatch'][$d['ref']] = (int) $d['id'];
            }
            $u = ksefDepositUsage($db, $d['id']);
            $unused = $foreign ? $u['unused_mc'] : $u['unused_ttc'];
            if ($unused >= 0.005) {
                $ctx['partial'][$d['ref']] = array('id' => (int) $d['id'], 'amount' => $unused);
            }
        }
        $ctx['deposits'] = $cite(array_values($deposits));
        $remaining = $foreign ? (float) $invoice->multicurrency_total_ttc : (float) $invoice->total_ttc;
        if ($ctx['mode'] === 'payment') {
            foreach ($deposits as $d) {
                $remaining -= $foreign ? $d['mc_ttc'] : $d['ttc'];
            }
        }
        $ctx['zero'] = abs($remaining) < 0.005;
        ksefSettlementSigns($db, $invoice, $deposits, $foreign, $ctx);
    }
    foreach (ksefInvoiceOrderSources($db, $invoice->id) as $src) {
        foreach (ksefOrderDeposits($db, $src['type'], $src['id']) as $d) {
            if (isset($deposits[$d['id']]) || isset($ctx['pending'][$d['ref']])) {
                continue;
            }
            $u = ksefDepositUsage($db, $d['id']);
            $left = $foreign ? $d['net_mc_ttc'] - $u['used_mc'] : $d['net_ttc'] - $u['used_ttc'];
            if ($left >= 0.005) {
                $ctx['pending'][$d['ref']] = array('id' => (int) $d['id'], 'amount' => $left);
            }
        }
    }
    return $ctx;
}

/**
 * @brief ROZ sign fields
 * @param DoliDB $db
 * @param Facture $invoice
 * @param array $deposits ksefConsumedDeposits()
 * @param bool $foreign Invoice currency
 * @param array $ctx
 * @return void
 */
function ksefSettlementSigns($db, $invoice, $deposits, $foreign, &$ctx)
{
    $depLines = array();
    $byRate = array();
    $add = function ($k, $ht, $tva) use (&$byRate) {
        if (!isset($byRate[$k])) {
            $byRate[$k] = array('ht' => 0.0, 'tva' => 0.0);
        }
        $byRate[$k]['ht'] += $ht;
        $byRate[$k]['tva'] += $tva;
    };
    foreach ($deposits as $d) {
        foreach ($d['line_ids'] as $lid) {
            $depLines[(int) $lid] = true;
        }
        foreach ($d['rates'] as $rate => $r) {
            $add(ksefDepositRateKey($rate, $r['code']), -($foreign ? $r['mc_ht'] : $r['ht']), -($foreign ? $r['mc_tva'] : $r['tva']));
        }
    }
    if (empty($invoice->lines) && !empty($invoice->id)) {
        $invoice->fetch_lines();
    }
    foreach ((array) $invoice->lines as $l) {
        $lid = (int) (!empty($l->id) ? $l->id : ($l->rowid ?? 0));
        if (isset($depLines[$lid]) || (int) $l->product_type === 9 || ksefSettlementLine($db, $l) !== null) {
            continue;
        }
        $add(ksefDepositRateKey($l->tva_tx, $l->vat_src_code ?? ''), (float) ($foreign ? $l->multicurrency_total_ht : $l->total_ht),
            (float) ($foreign ? $l->multicurrency_total_tva : $l->total_tva));
    }
    $total = 0.0;
    foreach ($byRate as $k => $v) {
        $total += $v['ht'] + $v['tva'];
        if ($v['ht'] <= -0.005 || $v['tva'] <= -0.005) {
            $ctx['negative_rates'][$k] = array('ht' => round($v['ht'], 2), 'tva' => round($v['tva'], 2));
        }
    }
    $ctx['remaining'] = round($total, 2);
    $ctx['below_zero'] = $total <= -0.005;
}

/**
 * @brief Deposit block code
 * @param array $ctx ksefDepositContext()
 * @return string '' if none
 */
function ksefDepositBlockCode($ctx)
{
    if (!empty($ctx['mismatch'])) {
        return 'currency_mismatch';
    }
    if (!empty($ctx['below_zero'])) {
        return 'below_zero';
    }
    return empty($ctx['blocked']) ? '' : 'not_in_ksef';
}

/**
 * @brief Group blocked deposits
 * @param array $ctx ksefDepositContext()
 * @return array
 */
function ksefDepositBlockGroups($ctx)
{
    $out = array('send' => array(), 'link' => array(), 'other_env' => array());
    foreach ((array) $ctx['blocked'] as $ref => $key) {
        $info = $ctx['blocked_info'][$ref] ?? array();
        if ($key === 'KSEF_DepositNumberNotLinked') {
            $out['link'][$ref] = $info;
        } elseif ($key === 'KSEF_DepositOtherEnvOnly') {
            $out['other_env'][$ref] = $info;
        } else {
            $out['send'][$ref] = $key;
        }
    }
    return $out;
}

/**
 * @brief Settlement deposit list
 * @param DoliDB $db
 * @param array $ctx ksefDepositContext()
 * @param bool $html
 * @return string
 */
function ksefSettlementDepositList($db, $ctx, $html)
{
    $refs = array();
    foreach ((array) $ctx['deposits'] as $d) {
        $refs[$d['ref']] = (int) $d['id'];
    }
    return $html ? implode(', ', ksefDepositLinks($db, $refs)) : implode(', ', array_keys($refs));
}

/**
 * @brief Deposit block message
 * @param DoliDB $db
 * @param Facture $invoice
 * @param array|null $ctx
 * @return string '' if none
 */
function ksefDepositBlockMessage($db, $invoice, $ctx = null)
{
    global $langs;
    $langs->load("ksef@ksef");
    if ($ctx === null) {
        $ctx = ksefDepositContext($db, $invoice);
    }
    $msg = array();
    if (!empty($ctx['mismatch'])) {
        $msg[] = $langs->transnoentitiesnoconv('KSEF_DepositCurrencyMismatch', implode(', ', array_keys($ctx['mismatch'])), ksefGetInvoiceCurrency($invoice));
    }
    if (!empty($ctx['below_zero'])) {
        $msg[] = $langs->transnoentitiesnoconv('KSEF_SettlementBelowZero', ksefSettlementDepositList($db, $ctx, false),
            price((float) $ctx['remaining'], 0, $langs, 1, -1, -1, ksefGetInvoiceCurrency($invoice)));
    }
    $groups = ksefDepositBlockGroups($ctx);
    if ($groups['send']) {
        $parts = array();
        foreach ($groups['send'] as $ref => $key) {
            $parts[] = $ref . ' (' . $langs->transnoentitiesnoconv($key) . ')';
        }
        $msg[] = $langs->transnoentitiesnoconv('KSEF_DepositNotInKsef', implode(', ', $parts));
    }
    foreach ($groups['link'] as $ref => $c) {
        $msg[] = $langs->transnoentitiesnoconv('KSEF_DepositLinkNumberFirst', $ref, $c['number'] ?? '');
    }
    $env = ksefEnvironmentLabel(ksefGetEnvironment());
    foreach ($groups['other_env'] as $ref => $c) {
        $msg[] = ($c['env'] ?? '') === 'PRODUCTION' ? $langs->transnoentitiesnoconv('KSEF_DepositProductionOnly', $ref, $env)
            : $langs->transnoentitiesnoconv('KSEF_DepositOtherEnvFirst', $ref, ksefEnvironmentLabel($c['env'] ?? ''), $env);
    }
    return implode(' ', $msg);
}

/**
 * @brief Deposit card links
 * @param DoliDB $db
 * @param array $list ref => id
 * @param array $reasons ref => lang key
 * @return array
 */
function ksefDepositLinks($db, $list, $reasons = array())
{
    global $langs;
    $parts = array();
    foreach ($list as $ref => $id) {
        $link = dol_escape_htmltag($ref);
        $dep = new Facture($db);
        if ((int) $id > 0 && $dep->fetch((int) $id) > 0) {
            $link = $dep->getNomUrl(1);
        }
        if (isset($reasons[$ref])) {
            $link .= ' <span class="badge badge-status8">' . $langs->trans($reasons[$ref]) . '</span>';
        }
        $parts[] = $link;
    }
    return $parts;
}

/**
 * @brief Deposit block HTML
 * @param DoliDB $db
 * @param Facture $invoice
 * @param array|null $ctx
 * @return string
 */
function ksefDepositBlockHtml($db, $invoice, $ctx = null)
{
    global $langs, $user;
    $langs->load("ksef@ksef");
    if ($ctx === null) {
        $ctx = ksefDepositContext($db, $invoice);
    }
    $out = array();
    if (!empty($ctx['mismatch'])) {
        $out[] = ksefTransHtml('KSEF_DepositCurrencyMismatch', array(implode(', ', ksefDepositLinks($db, $ctx['mismatch'])), dol_escape_htmltag(ksefGetInvoiceCurrency($invoice))));
    }
    if (!empty($ctx['below_zero'])) {
        $out[] = ksefTransHtml('KSEF_SettlementBelowZero', array(ksefSettlementDepositList($db, $ctx, true),
            dol_escape_htmltag(price((float) $ctx['remaining'], 0, $langs, 1, -1, -1, ksefGetInvoiceCurrency($invoice)))));
    }
    $groups = ksefDepositBlockGroups($ctx);
    if ($groups['send']) {
        $ids = array_intersect_key($ctx['blocked_ids'], $groups['send']);
        $out[] = ksefTransHtml('KSEF_DepositSendFirst', array(implode(', ', ksefDepositLinks($db, $ids, $groups['send']))));
    }
    foreach ($groups['link'] as $ref => $c) {
        $url = DOL_URL_ROOT . '/compta/facture/card.php?facid=' . (int) $ctx['blocked_ids'][$ref] . '&action=ksef_link_number&ksef_number='
            . urlencode((string) ($c['number'] ?? '')) . '&token=' . newToken();
        $label = '<i class="fas fa-link"></i> ' . $langs->trans('KSEF_LinkKsefNumber');
        $out[] = ksefTransHtml('KSEF_DepositLinkNumberFirst', array(implode('', ksefDepositLinks($db, array($ref => $ctx['blocked_ids'][$ref]))), dol_escape_htmltag((string) ($c['number'] ?? ''))))
            . (ksefUserCan($user, 'link_ksef_number') ? ' <a class="butAction smallpaddingimp ksef-row-button ksef-deposit-link-number" href="' . dol_escape_htmltag($url) . '">' . $label . '</a>'
                : ' <span class="butActionRefused smallpaddingimp ksef-row-button classfortooltip ksef-deposit-link-number" title="' . dol_escape_htmltag($langs->trans('NotEnoughPermissions')) . '">' . $label . '</span>');
    }
    $env = dol_escape_htmltag(ksefEnvironmentLabel(ksefGetEnvironment()));
    foreach ($groups['other_env'] as $ref => $c) {
        $depLink = implode('', ksefDepositLinks($db, array($ref => $ctx['blocked_ids'][$ref])));
        $out[] = ($c['env'] ?? '') === 'PRODUCTION' ? ksefTransHtml('KSEF_DepositProductionOnly', array($depLink, $env))
            : ksefTransHtml('KSEF_DepositOtherEnvFirst', array($depLink, dol_escape_htmltag(ksefEnvironmentLabel($c['env'] ?? '')), $env));
    }
    return implode('<br>', $out);
}

/**
 * @brief Deposit ack message
 * @param DoliDB $db
 * @param Facture $invoice
 * @param array|null $ctx
 * @param bool $html
 * @param string $only deposits, rates
 * @return string
 */
function ksefDepositAckMessage($db, $invoice, $ctx = null, $html = false, $only = '')
{
    global $langs;
    $langs->load("ksef@ksef");
    if ($ctx === null) {
        $ctx = ksefDepositContext($db, $invoice);
    }
    $msg = array();
    foreach (array('partial' => 'KSEF_DepositPartlyApplied', 'pending' => 'KSEF_DepositsPending') as $k => $key) {
        if (empty($ctx[$k]) || $only === 'rates') {
            continue;
        }
        $refs = array();
        $amounts = array();
        foreach ($ctx[$k] as $ref => $d) {
            $refs[$ref] = $d['id'];
            if (!empty($d['amount'])) {
                $amounts[] = $ref . ': ' . price((float) $d['amount'], 0, $langs, 1, -1, -1, ksefGetInvoiceCurrency($invoice));
            }
        }
        $list = $html ? implode(', ', ksefDepositLinks($db, $refs)) : implode(', ', array_keys($refs));
        $line = $html ? ksefTransHtml($key, array($list)) : $langs->transnoentitiesnoconv($key, $list);
        if ($k === 'pending') {
            if ($html) {
                $line .= ksefDepositApplyButton($db, $invoice);
            }
            foreach ($ctx[$k] as $ref => $d) {
                $line .= ($html ? '<br>' : ' ') . ksefDepositPendingStep($db, $invoice, (int) $d['id'], (float) $d['amount'], $html);
                $warn = ($html && (int) $invoice->status === Facture::STATUS_DRAFT) ? ksefPickDepositWarning($db, (int) $d['id']) : '';
                if ($warn !== '') {
                    $line .= '<br><span class="ksef-pick-deposit-warning" data-deposit="' . (int) $d['id'] . '">' . img_warning() . ' ' . dol_escape_htmltag($warn) . '</span>';
                }
            }
        } elseif ($amounts) {
            $left = $langs->transnoentitiesnoconv('KSEF_DepositCreditLeft', implode(', ', $amounts));
            $line .= ' ' . ($html ? dol_escape_htmltag($left) : $left);
        }
        $msg[] = $line;
    }
    $rates = empty($ctx['below_zero']) && $only !== 'deposits' ? ksefSettlementNegativeRatesText($ctx, ksefGetInvoiceCurrency($invoice)) : '';
    if ($rates !== '') {
        $msg[] = $html ? ksefTransHtml('KSEF_SettlementRateNegative', array(dol_escape_htmltag($rates), ksefSettlementDepositList($db, $ctx, true)))
            : $langs->transnoentitiesnoconv('KSEF_SettlementRateNegative', $rates, ksefSettlementDepositList($db, $ctx, false));
    }
    return implode($html ? '<br>' : ' ', $msg);
}

/**
 * @brief Pending deposit state
 * @param DoliDB $db
 * @param int $depositId
 * @return array
 */
function ksefDepositPendingState($db, $depositId)
{
    $dep = new Facture($db);
    if ($dep->fetch((int) $depositId) <= 0) {
        return array('state' => '', 'dep' => null, 'rows' => array(), 'paid' => 0.0, 'value' => 0.0);
    }
    $rows = ksefDepositRows($db, (int) $depositId);
    $free = array();
    foreach ($rows as $r) {
        if ($r['inv'] <= 0 && $r['line'] <= 0) {
            $free[] = $r;
        }
    }
    $paid = (float) price2num($dep->getSommePaiement() + $dep->getSumCreditNotesUsed() + $dep->getSumDepositsUsed(), 'MT');
    $v = ksefDepositCurrentValue($db, (int) $depositId);
    $value = $v ? (float) $v['ttc'] : (float) $dep->total_ttc;
    if ($free) {
        $state = 'credit';
    } elseif ($rows) {
        $state = 'used';
    } elseif ($paid >= (float) price2num($value, 'MT')) {
        $state = 'convert';
    } else {
        $state = $paid > 0 ? 'partial' : 'unpaid';
    }
    return array('state' => $state, 'dep' => $dep, 'rows' => $free, 'paid' => $paid, 'value' => $value);
}

/**
 * @brief Pending deposit step
 * @param DoliDB $db
 * @param Facture $invoice
 * @param int $depositId
 * @param float $amount Left to settle
 * @param bool $html
 * @return string
 */
function ksefDepositPendingStep($db, $invoice, $depositId, $amount, $html = false)
{
    global $langs, $user;
    $langs->loadLangs(array('bills', 'main', 'ksef@ksef'));
    $st = ksefDepositPendingState($db, $depositId);
    if ($st['dep'] === null || $st['state'] === 'used') {
        return '';
    }
    $dep = $st['dep'];
    $cur = ksefGetInvoiceCurrency($invoice);
    $money = function ($v) use ($langs, $cur) {
        return price($v, 0, $langs, 1, -1, -1, $cur);
    };
    $T = function ($k) use ($langs) {
        return $langs->transnoentitiesnoconv($k);
    };
    $ref = $html ? $dep->getNomUrl(1) : $dep->ref;
    $draft = (int) $invoice->status === Facture::STATUS_DRAFT;
    $ready = ($draft && $st['state'] === 'credit' && $user->hasRight('facture', 'creer')) ? ksefDepositReadyList($db, $invoice)['ready'] : array();
    if (isset($ready[$dep->ref])) {
        $key = 'KSEF_DepositStepApplyButton';
        $args = array($ref, $money($amount), $T(count($ready) > 1 ? 'KSEF_DepositApplyButtonMany' : 'KSEF_DepositApplyButton'), $T('DiscountStillRemaining'));
    } elseif ($st['state'] === 'credit') {
        $key = $draft ? 'KSEF_DepositStepApply' : 'KSEF_DepositStepDraft';
        $args = $draft ? array($ref, $money($amount), $T('DiscountStillRemaining'), $T('UseLine')) : array($ref, $money($amount), $T('Modify'));
    } elseif ($st['state'] === 'convert') {
        $key = 'KSEF_DepositStepConvert';
        $args = array($ref, $T('ConvertToReduc'));
    } elseif ($st['state'] === 'partial') {
        $key = 'KSEF_DepositStepPartial';
        $args = array($ref, $money($st['paid']), $money($st['value']), $T('DoPayment'));
    } else {
        $key = 'KSEF_DepositStepUnpaid';
        $args = array($ref, $T('DoPayment'));
    }
    if (!$html) {
        return $langs->transnoentitiesnoconv($key, ...$args);
    }
    foreach ($args as $i => $a) {
        if ($i > 0) {
            $args[$i] = dol_escape_htmltag($a);
        }
    }
    $pay = '';
    if ($draft && in_array($st['state'], array('unpaid', 'partial'), true) && $user->hasRight('facture', 'paiement')) {
        $pay = ' <a class="butAction smallpaddingimp ksef-row-button ksef-deposit-pay" data-deposit="' . (int) $dep->id . '" href="'
            . dol_escape_htmltag(DOL_URL_ROOT . '/compta/paiement.php?facid=' . (int) $dep->id . '&action=create&ksef_back=' . (int) $invoice->id) . '">'
            . $langs->trans('KSEF_DepositPayButton', dol_escape_htmltag($dep->ref)) . '</a>';
    }
    return '<span class="ksef-deposit-step ksef-deposit-step-' . $st['state'] . '">' . ksefTransHtml($key, $args) . $pay . '</span>';
}

/**
 * @brief Remember settlement draft
 * @param int $depositId
 * @param int $draftId
 * @return void
 */
function ksefDepositBackSet($depositId, $draftId)
{
    if ((int) $depositId > 0 && (int) $draftId > 0) {
        $_SESSION['ksef_deposit_back'][(int) $depositId] = (int) $draftId;
    }
}

/**
 * @brief Back to settlement
 * @param DoliDB $db
 * @param Facture $invoice Deposit or draft
 * @return string HTML row
 */
function ksefDepositBackRow($db, $invoice)
{
    global $langs, $user;
    if (empty($_SESSION['ksef_deposit_back']) || !is_array($_SESSION['ksef_deposit_back'])) {
        return '';
    }
    foreach ($_SESSION['ksef_deposit_back'] as $dep => $draft) {
        if ((int) $draft === (int) $invoice->id) {
            unset($_SESSION['ksef_deposit_back'][$dep]);
        }
    }
    $draftId = (int) ($_SESSION['ksef_deposit_back'][(int) $invoice->id] ?? 0);
    if ($draftId <= 0 || (int) $invoice->type !== Facture::TYPE_DEPOSIT) {
        return '';
    }
    $draft = new Facture($db);
    if ($draft->fetch($draftId) <= 0 || (int) $draft->status !== Facture::STATUS_DRAFT || !ksefUserCanReadInvoice($user, $draftId)) {
        unset($_SESSION['ksef_deposit_back'][(int) $invoice->id]);
        return '';
    }
    $langs->load('ksef@ksef');
    $st = ksefDepositPendingState($db, (int) $invoice->id);
    $key = $st['state'] === 'credit' ? 'KSEF_DepositBackReady' : 'KSEF_DepositBackWaiting';
    $link = '<a class="ksef-deposit-back" href="' . DOL_URL_ROOT . '/compta/facture/card.php?facid=' . $draftId . '">' . dol_escape_htmltag($draft->ref) . '</a>';
    return '<tr class="ksef-deposit-back-row" data-state="' . dol_escape_htmltag($st['state']) . '"><td class="titlefieldcreate">' . $langs->trans('KSEF_DepositBackLabel') . '</td><td colspan="3">'
        . '<span class="fa fa-arrow-left paddingright"></span>' . ksefTransHtml($key, array($link)) . '</td></tr>';
}

/**
 * @brief Deposit pick info
 * @param DoliDB $db
 * @param array $ids Deposit IDs
 * @return string HTML script
 */
function ksefDepositPickScript($db, $ids)
{
    global $langs;
    $langs->load('ksef@ksef');
    $info = array();
    foreach ($ids as $id) {
        $st = ksefDepositPendingState($db, (int) $id);
        if ($st['dep'] === null) {
            continue;
        }
        $o = ksefDepositOrder($db, $st['dep']);
        $info[(int) $id] = array(
            'order' => $o ? (string) $o['ref'] : '',
            'text' => ($o ? $langs->transnoentitiesnoconv($o['type'] === 'commande' ? 'KSEF_PickDepositOrder' : 'KSEF_PickDepositProposal', $o['ref'])
                : $langs->transnoentitiesnoconv('KSEF_KindNoOrder')) . ' · ' . $langs->transnoentitiesnoconv('KSEF_PickDepositState_' . $st['state']),
            'state' => $st['state'],
        );
    }
    if (!$info) {
        return '';
    }
    return '<script>jQuery(function($) { var info = ' . json_encode($info, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ';'
        . ' $("table.liste tr.oddeven").each(function() { var tr = $(this), done = false;'
        . ' tr.find("a[href*=\'/compta/facture/card.php\']").each(function() { var m = /[?&](?:id|facid)=(\\d+)(?:&|$)/.exec(this.href);'
        . ' if (done || !m || !info[m[1]]) { return; } done = true; var d = info[m[1]];'
        . ' tr.attr({"data-ksef-order": d.order, "data-ksef-deposit-state": d.state});'
        . ' $(this).closest("td").append($("<div>").addClass("small opacitymedium ksef-pick-order").text(d.text)); }); }); });</script>';
}

/**
 * @brief Ready order deposits
 * @param DoliDB $db
 * @param Facture $invoice
 * @return array ready, waiting (ref => id)
 */
function ksefDepositReadyList($db, $invoice)
{
    $out = array('ready' => array(), 'waiting' => array());
    $seen = array();
    foreach (ksefInvoiceOrderSources($db, (int) $invoice->id) as $src) {
        foreach (ksefOrderDeposits($db, $src['type'], $src['id']) as $d) {
            if (isset($seen[$d['id']])) {
                continue;
            }
            $seen[$d['id']] = true;
            $st = ksefDepositPendingState($db, (int) $d['id']);
            if ($st['dep'] === null || $st['state'] === 'used') {
                continue;
            }
            $ok = $st['state'] === 'credit' && (int) $st['dep']->socid === (int) $invoice->socid
                && ksefGetInvoiceCurrency($st['dep']) === ksefGetInvoiceCurrency($invoice);
            $out[$ok ? 'ready' : 'waiting'][$st['dep']->ref] = array('id' => (int) $d['id'], 'rows' => $st['rows']);
        }
    }
    return $out;
}

/**
 * @brief Apply ready deposits
 * @param DoliDB $db
 * @param Facture $invoice Draft
 * @return array error, applied, waiting
 */
function ksefDepositApplyReady($db, $invoice)
{
    global $langs, $conf;
    $langs->load('ksef@ksef');
    $out = array('error' => '', 'applied' => array(), 'waiting' => array());
    if ((int) $invoice->status !== Facture::STATUS_DRAFT) {
        $out['error'] = $langs->trans('KSEF_DepositApplyRefused');
        return $out;
    }
    $list = ksefDepositReadyList($db, $invoice);
    $out['waiting'] = array_keys($list['waiting']);
    if (!$list['ready']) {
        $out['error'] = $langs->trans('KSEF_DepositApplyNone');
        return $out;
    }
    $cur = ksefGetInvoiceCurrency($invoice);
    $foreign = $cur !== $conf->currency;
    $sum = 0.0;
    foreach ($list['ready'] as $d) {
        foreach ($d['rows'] as $r) {
            $sum += $foreign ? $r['mttc'] : $r['ttc'];
        }
    }
    $total = $foreign ? (float) $invoice->multicurrency_total_ttc : (float) $invoice->total_ttc;
    if ($sum - $total >= 0.005) {
        $out['error'] = $langs->trans('KSEF_DepositApplyTooBig', implode(', ', array_keys($list['ready'])), price($sum, 0, $langs, 1, -1, -1, $cur), price($total, 0, $langs, 1, -1, -1, $cur));
        return $out;
    }
    $db->begin();
    foreach ($list['ready'] as $ref => $d) {
        foreach ($d['rows'] as $r) {
            if ($invoice->insert_discount($r['id']) <= 0) {
                $db->rollback();
                $out['error'] = $invoice->error !== '' ? $invoice->error : $langs->trans('KSEF_DepositApplyRefused');
                return $out;
            }
        }
        $out['applied'][] = $ref;
    }
    $db->commit();
    return $out;
}

/**
 * @brief Credit row script
 * @param DoliDB $db
 * @param Facture $invoice
 * @return string
 */
function ksefDepositCreditRowScript($db, $invoice)
{
    if ((int) $invoice->status !== Facture::STATUS_DRAFT || (int) $invoice->socid <= 0) {
        return '';
    }
    $refs = array();
    $res = $db->query("SELECT re.rowid, f.ref FROM " . MAIN_DB_PREFIX . "societe_remise_except re"
        . " JOIN " . MAIN_DB_PREFIX . "facture f ON f.rowid = re.fk_facture_source AND f.type = " . Facture::TYPE_DEPOSIT
        . " WHERE re.fk_soc = " . (int) $invoice->socid . " AND re.fk_facture IS NULL AND re.fk_facture_line IS NULL AND re.entity IN (" . getEntity('invoice') . ")");
    while ($res && ($o = $db->fetch_object($res))) {
        $refs[(int) $o->rowid] = (string) $o->ref;
    }
    return '<script>jQuery(function($) { var refs = ' . json_encode($refs ? $refs : new stdClass(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ', sel = $("select[name=remise_id]");'
        . ' sel.find("option").each(function() { var r = refs[this.value]; if (r && $(this).text().indexOf(r) === -1) { $(this).text($(this).text() + " (" + r + ")"); } });'
        . ' var btn = $("tr.ksef-deposit-summary a.ksef-deposit-apply").first();'
        . ' if (btn.length && sel.length) { sel.closest("td").prepend($("<div>").addClass("ksef-deposit-apply-top").append(btn.removeClass("smallpaddingimp"))); } });</script>';
}

/**
 * @brief Deduction line numbers
 * @param DoliDB $db
 * @param FactureFournisseur $invoice
 * @return string
 */
function ksefSupplierDeductionScript($db, $invoice)
{
    $nums = array();
    $res = $db->query("SELECT DISTINCT re.fk_invoice_supplier_source as dep, i.ksef_number FROM " . MAIN_DB_PREFIX . "facture_fourn_det d"
        . " JOIN " . MAIN_DB_PREFIX . "societe_remise_except re ON re.rowid = d.fk_remise_except"
        . " JOIN " . MAIN_DB_PREFIX . "ksef_incoming i ON i.fk_facture_fourn = re.fk_invoice_supplier_source AND i.ksef_number <> ''"
        . " WHERE d.fk_facture_fourn = " . (int) $invoice->id);
    while ($res && ($o = $db->fetch_object($res))) {
        $nums[(int) $o->dep] = (string) $o->ksef_number;
    }
    if (!$nums) {
        return '';
    }
    return '<script>jQuery(function($) { var nums = ' . json_encode($nums, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ';'
        . ' $.each(nums, function(id, num) { $("#tablelines a[href*=\'/fourn/facture/card.php?facid=" + id + "\']").filter(function() { return /facid=(\\d+)/.exec(this.href)[1] === id; })'
        . '.after($("<span>").addClass("opacitymedium small ksef-deduction-ksef").text(" KSeF: " + num)); }); });</script>';
}

/**
 * @brief Apply button HTML
 * @param DoliDB $db
 * @param Facture $invoice
 * @return string
 */
function ksefDepositApplyButton($db, $invoice)
{
    global $langs, $user;
    if ((int) $invoice->status !== Facture::STATUS_DRAFT || !$user->hasRight('facture', 'creer')) {
        return '';
    }
    $n = count(ksefDepositReadyList($db, $invoice)['ready']);
    if ($n === 0) {
        return '';
    }
    return ' <a class="butAction smallpaddingimp ksef-row-button ksef-deposit-apply" href="' . dol_escape_htmltag(DOL_URL_ROOT . '/compta/facture/card.php?facid=' . (int) $invoice->id
        . '&action=ksef_apply_deposit&token=' . newToken()) . '">' . $langs->trans($n > 1 ? 'KSEF_DepositApplyButtonMany' : 'KSEF_DepositApplyButton') . '</a>';
}

/**
 * @brief Negative rates text
 * @param array $ctx ksefDepositContext()
 * @param string $currency
 * @return string
 */
function ksefSettlementNegativeRatesText($ctx, $currency)
{
    global $langs;
    if (empty($ctx['negative_rates'])) {
        return '';
    }
    $parts = array();
    foreach ($ctx['negative_rates'] as $k => $v) {
        $parts[] = $langs->transnoentitiesnoconv('KSEF_SettlementRateNegativePart', ksefDepositRateLabel((string) $k),
            price((float) $v['ht'], 0, $langs, 1, -1, -1, $currency), price((float) $v['tva'], 0, $langs, 1, -1, -1, $currency));
    }
    return implode('; ', $parts);
}

/**
 * @brief Ack notice kinds
 * @param array $ctx ksefDepositContext()
 * @return array
 */
function ksefDepositAckKinds($ctx)
{
    return array('deposits' => !empty($ctx['partial']) || !empty($ctx['pending']), 'rates' => !empty($ctx['negative_rates']) && empty($ctx['below_zero']));
}

/**
 * @brief Deposit summary HTML
 * @param DoliDB $db
 * @param Facture $invoice
 * @param array|null $ctx
 * @return string
 */
function ksefDepositSummaryHtml($db, $invoice, $ctx = null)
{
    global $langs;
    $langs->load("ksef@ksef");
    if ($ctx === null) {
        $ctx = ksefDepositContext($db, $invoice);
    }
    $ack = ksefDepositAckMessage($db, $invoice, $ctx, true);
    if ($ack !== '') {
        $kst = ksefSubmissionState($db, (int) $invoice->id, ksefGetEnvironment());
        if (!empty($kst['is_accepted']) || !empty($kst['is_offline'])) {
            $kinds = ksefDepositAckKinds($ctx);
            $sent = array();
            if ($kinds['deposits']) {
                $sent[] = $langs->transnoentitiesnoconv('KSEF_DepositsSentAnyway');
            }
            if ($kinds['rates']) {
                $sent[] = $langs->transnoentitiesnoconv('KSEF_SettlementRateNegativeSent', ksefSettlementNegativeRatesText($ctx, ksefGetInvoiceCurrency($invoice)));
            }
            $ack = dol_escape_htmltag(implode(' ', $sent));
        }
    }
    if (!in_array($ctx['type'], array('ROZ', 'ZAL', 'KOR_ZAL', 'KOR_ROZ'), true)) {
        $usedList = ksefOrderDepositUsedElsewhere($db, (int) $invoice->id);
        $usedSent = false;
        if ($usedList) {
            $kstU = ksefSubmissionState($db, (int) $invoice->id, ksefGetEnvironment());
            $usedSent = !empty($kstU['is_accepted']) || !empty($kstU['is_offline']);
        }
        $usedElsewhere = ksefOrderDepositUsedElsewhereText($db, $usedList, true, $usedSent);
        if ($usedElsewhere !== '') {
            $usedElsewhere = '<span class="ksef-order-deposit-used">' . $usedElsewhere . '</span>';
            $ack = $ack !== '' ? $ack . '<br>' . $usedElsewhere : $usedElsewhere;
        }
        return $ack !== '' ? '<strong>' . $langs->trans('KSEF_KindVAT') . '</strong> · <span class="warning">' . $ack . '</span>' : '';
    }
    // 1.4.x: VAT ROZ
    if ($ctx['type'] === 'ROZ' && ksefSentInvoiceType($db, (int) $invoice->id) === 'VAT') {
        return '<span class="classfortooltip" title="' . dol_escape_htmltag($langs->trans('KSEF_KindSentAs', 'VAT')) . '"><strong>' . $langs->trans('KSEF_KindVAT') . '</strong></span>';
    }
    $depLink = function ($d) use ($db, $langs) {
        $c = $d['citation'];
        $dep = new Facture($db);
        $link = $dep->fetch((int) $d['id']) > 0 ? $dep->getNomUrl(1) : dol_escape_htmltag($d['ref']);
        if (!$c['citable']) {
            $tip = $langs->trans($c['reason']);
        } else {
            $tip = $c['outside'] ? $langs->trans('KSEF_DepositOutsideKsef') : $langs->trans('KSEF_Number') . ': ' . $c['ksef_number'];
        }
        return '<span class="classfortooltip" title="' . dol_escape_htmltag($tip) . '">' . $link . '</span>';
    };
    $orderLink = function ($o) use ($db) {
        if ($o['type'] === 'commande') {
            require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
            $obj = new Commande($db);
        } else {
            require_once DOL_DOCUMENT_ROOT . '/comm/propal/class/propal.class.php';
            $obj = new Propal($db);
        }
        return $obj->fetch((int) $o['id']) > 0 ? $obj->getNomUrl(1) : dol_escape_htmltag($o['ref']);
    };
    $kind = $ctx['type'] === 'ROZ' && $ctx['zero'] ? 'KSEF_KindROZZero' : 'KSEF_Kind' . $ctx['type'];
    $tip = $langs->trans('KSEF_KindSentAs', $ctx['type']);
    if ($ctx['type'] === 'ROZ' && $ctx['zero']) {
        $tip .= '. ' . $langs->trans('KSEF_KindZeroHelp');
    }
    if ($ctx['type'] === 'ZAL' && $ctx['last']) {
        $tip .= '. ' . $langs->trans('KSEF_KindLastHelp');
    }
    $out = '<span class="classfortooltip" title="' . dol_escape_htmltag($tip) . '"><strong>' . $langs->trans($kind) . '</strong></span>';
    if ($ctx['type'] === 'ROZ' && $ctx['deposits']) {
        $out .= ' &middot; ' . $langs->trans('KSEF_KindDeposits') . ': ' . implode(', ', array_map($depLink, $ctx['deposits']));
    }
    if ($ctx['type'] === 'ZAL' || $ctx['type'] === 'KOR_ZAL') {
        if ($ctx['order']) {
            $currency = ksefGetInvoiceCurrency($invoice);
            $depId = $ctx['type'] === 'ZAL' ? (int) $invoice->id : (int) $ctx['root_id'];
            $sent = $depId > 0 ? ksefDepositSentOrder($db, $depId) : null;
            $now = ksefOrderValue($ctx['order'], $currency);
            $shown = $sent ? $sent['value'] : $now;
            $out .= ' &middot; ' . $orderLink($ctx['order']) . ' <span class="opacitymedium">(' . price($shown, 0, $langs, 1, -1, -1, $currency) . ')</span>';
            if ($sent && abs($sent['value'] - $now) >= 0.005) {
                $out .= ' <span class="warning classfortooltip" title="' . dol_escape_htmltag($langs->trans('KSEF_KindOrderChangedHelp')) . '">'
                    . $langs->trans('KSEF_KindOrderChanged', price($now, 0, $langs, 1, -1, -1, $currency)) . '</span>';
            }
        } else {
            $out .= ' &middot; <span class="opacitymedium classfortooltip" title="' . dol_escape_htmltag($langs->trans('KSEF_KindNoOrderHelp')) . '">' . $langs->trans('KSEF_KindNoOrder') . '</span>';
        }
        if ($ctx['type'] === 'ZAL' && $ctx['last']) {
            $out .= ' &middot; ' . $langs->trans('KSEF_KindEarlier') . ': ' . implode(', ', array_map($depLink, $ctx['earlier']));
        }
    }
    $block = ksefDepositBlockHtml($db, $invoice, $ctx);
    if ($block !== '') {
        $out .= '<br><span class="error">' . $block . '</span>';
    }
    if ($ack !== '') {
        $out .= '<br><span class="warning">' . $ack . '</span>';
    }
    return $out . ksefSettlementWarningHtml($db, $invoice, $ctx);
}

/**
 * @brief Stale settlement warning
 * @param DoliDB $db
 * @param Facture $invoice
 * @param array $ctx ksefDepositContext()
 * @return string
 */
function ksefSettlementWarningHtml($db, $invoice, $ctx)
{
    global $langs, $user;
    $out = '';
    $amount = function ($v, $cur) use ($langs) {
        return price($v, 0, $langs, 1, -1, -1, $cur !== '' ? $cur : 'PLN');
    };
    if ($ctx['type'] === 'ROZ' || $ctx['type'] === 'KOR_ROZ') {
        $need = ksefSettlementNeedsCorrection($db, (int) $invoice->id);
        foreach ($need as $n) {
            $dep = new Facture($db);
            $link = $dep->fetch((int) $n['id']) > 0 ? $dep->getNomUrl(1) : dol_escape_htmltag($n['ref']);
            $out .= '<br><span class="warning ksef-settlement-needs-kor">' . img_warning() . ' '
                . $langs->trans('KSEF_SettlementNeedsKorRoz', $link, $amount($n['deducted'], $n['currency']), $amount($n['settle'], $n['currency'])) . '</span>';
        }
        $src = $need ? ksefCorrectionSources($db, (int) $invoice->socid) : false;
        if ($need && !empty($src[(int) $invoice->id]['eligible']) && $user->hasRight('facture', 'creer') && getDolGlobalString('INVOICE_DISABLE_REPLACEMENT')) {
            $out .= ' <span class="butActionRefused smallpaddingimp ksef-row-button classfortooltip ksef-correction-disabled ' . ksefToneClass('problem') . '" title="' . dol_escape_htmltag($langs->trans('KSEF_CorrectionDisabledByCore')) . '">'
                . $langs->trans('KSEF_CreateSettlementCorrection') . '</span>';
        } elseif ($need && !empty($src[(int) $invoice->id]['eligible']) && $user->hasRight('facture', 'creer')) {
            $out .= ' <a class="butAction smallpaddingimp ksef-row-button ksef-settlement-correction-link" href="' . dol_escape_htmltag(ksefCorrectionCreateUrl($invoice)) . '">'
                . $langs->trans('KSEF_CreateSettlementCorrection') . '</a>';
        }
        return $out;
    }
    $depId = $ctx['type'] === 'ZAL' ? (int) $invoice->id : ($ctx['type'] === 'KOR_ZAL' ? (int) ksefDepositRootId($db, (int) $invoice->id) : 0);
    if ($depId <= 0) {
        return '';
    }
    foreach (ksefDepositSettlementsToCorrect($db, $depId) as $n) {
        $fin = new Facture($db);
        $link = $fin->fetch((int) $n['inv']) > 0 ? $fin->getNomUrl(1) : dol_escape_htmltag($n['inv_ref']);
        $out .= '<br><span class="warning ksef-deposit-settlement-needs-kor">' . img_warning() . ' '
            . $langs->trans('KSEF_DepositSettlementNeedsKorRoz', $link, $amount($n['deducted'], $n['currency']), $amount($n['settle'], $n['currency'])) . '</span>';
    }
    return $out;
}

/**
 * @brief Deposit FX policy
 * @return string
 */
function ksefDepositFxPolicy()
{
    return ksefFxSetting('KSEF_DEPOSIT_FX_POLICY');
}

/**
 * @brief Deposit payment parts
 * @param DoliDB $db
 * @param Facture $invoice Deposit
 * @param array $opt
 * @return array
 */
function ksefDepositPaymentParts($db, $invoice, $opt = array())
{
    $out = array();
    if (empty($invoice->id) || (int) $invoice->type !== Facture::TYPE_DEPOSIT) {
        return $out;
    }
    $foreign = ksefInvoiceIsForeign($invoice);
    $excl = (int) ($opt['exclude_payment'] ?? 0);
    $chain = !empty($opt['chain']);
    $ids = $chain ? ksefCorrectionChainIds($db, (int) $invoice->id) : array((int) $invoice->id);
    $res = $db->query("SELECT p.rowid, p.datep, pf.amount, pf.multicurrency_amount FROM " . MAIN_DB_PREFIX . "paiement_facture AS pf"
        . " INNER JOIN " . MAIN_DB_PREFIX . "paiement AS p ON p.rowid = pf.fk_paiement WHERE pf.fk_facture IN (" . implode(',', array_map('intval', $ids)) . ")"
        . ($excl > 0 ? " AND p.rowid <> " . $excl : '') . " ORDER BY p.datep, p.rowid");
    $total = (float) ($chain && isset($opt['total']) ? $opt['total'] : ($foreign ? $invoice->multicurrency_total_ttc : $invoice->total_ttc));
    $days = array();
    $paid = 0.0;
    while ($res && ($o = $db->fetch_object($res))) {
        $a = (float) ($foreign ? $o->multicurrency_amount : $o->amount);
        if ($chain) {
            $a = $a < 0 ? $a : min($a, max(0.0, round($total - $paid, 2)));
        }
        $paid += $a;
        $day = ksefMomentDay($db->jdate($o->datep));
        $days[$day] = ($days[$day] ?? 0.0) + $a;
    }
    if ($chain) {
        $days = array_filter($days, function ($a) {
            return abs($a) >= 0.005;
        });
    }
    if (count($days) < 2 || (float) price2num($paid, 'MT') + 0.005 < (float) price2num($total, 'MT')) {
        return $out;
    }
    $left = round($total, 2);
    $n = count($days);
    $i = 0;
    foreach ($days as $day => $a) {
        $i++;
        $amount = $i === $n ? $left : round($a, 2);
        $left = round($left - $amount, 2);
        $out[] = array('day' => (string) $day, 'amount' => $amount, 'kurs' => 0.0);
    }
    if (!$foreign || !empty($opt['no_rates'])) {
        return $out;
    }
    dol_include_once('/ksef/class/ksef_nbp_currency_rate.class.php');
    $nbp = new KsefNbpCurrencyRate($db);
    $wdt = ksefInvoiceIsWdtOnly($db, $invoice);
    $code = strtoupper((string) $invoice->multicurrency_code);
    foreach ($out as $j => $p) {
        if ($wdt) {
            $out[$j]['kurs'] = round(ksefInvoiceKurs($invoice, array('db' => $db)), 6);
            continue;
        }
        $ref = $nbp->getReferenceDate($invoice, 0, false, array('paid' => (int) strtotime($p['day'])));
        $r = !empty($opt['cached_only']) ? $nbp->getCachedRateForDate($code, $ref) : $nbp->getRateForDateCached($code, $ref);
        $out[$j]['kurs'] = ($r && (float) $r['rate'] > 0) ? round((float) $r['rate'], 6) : 0.0;
    }
    return $out;
}

/**
 * @brief Parts to correct
 * @param DoliDB $db
 * @param Facture $root Deposit
 * @param array $opt
 * @return array
 */
function ksefDepositKorParts($db, $root, $opt = array())
{
    if (empty($root->id) || (int) $root->type !== Facture::TYPE_DEPOSIT) {
        return array();
    }
    $xml = ksefLatestSentXml($db, array((int) $root->id));
    if ($xml === '') {
        return ksefDepositPaymentParts($db, $root, $opt + array('chain' => true));
    }
    return ksefXmlPaymentParts($xml);
}

/**
 * @brief XML ZaliczkaCzesciowa entries
 * @param string $xml
 * @return array
 */
function ksefXmlPaymentParts($xml)
{
    $out = array();
    $doc = new DOMDocument();
    if ((string) $xml === '' || !@$doc->loadXML((string) $xml)) {
        return $out;
    }
    $xp = new DOMXPath($doc);
    foreach ($xp->query('//*[local-name()="Fa"]/*[local-name()="ZaliczkaCzesciowa"]') as $n) {
        $get = function ($name) use ($xp, $n) {
            $l = $xp->query('*[local-name()="' . $name . '"]', $n);
            return $l->length ? trim($l->item(0)->textContent) : '';
        };
        $out[] = array('day' => $get('P_6Z'), 'amount' => round((float) $get('P_15Z'), 2), 'kurs' => round((float) $get('KursWalutyZW'), 6));
    }
    return $out;
}

/**
 * @brief Split over parts
 * @param array $parts
 * @param float $total
 * @param string $rule 'proportional' or 'newest_first'
 * @return array
 */
function ksefDepositKorAlloc($parts, $total, $rule = 'proportional')
{
    if ($rule === 'newest_first') {
        $out = array();
        $left = round((float) $total, 2);
        $n = count($parts);
        $i = 0;
        foreach ($parts as $p) {
            $i++;
            $a = $i === $n ? $left : max(0.0, min($left, round((float) $p['amount'], 2)));
            $left = round($left - $a, 2);
            $out[] = $a;
        }
        return $out;
    }
    $weight = 0.0;
    foreach ($parts as $p) {
        $weight += (float) $p['amount'];
    }
    $out = array();
    $left = round((float) $total, 2);
    $n = count($parts);
    $i = 0;
    foreach ($parts as $p) {
        $i++;
        $a = $i === $n ? $left : (abs($weight) >= 0.005 ? round((float) $total * (float) $p['amount'] / $weight, 2) : 0.0);
        $left = round($left - $a, 2);
        $out[] = $a;
    }
    return $out;
}

/**
 * @brief Deposit XML head
 * @param string $xml
 * @return array|null Null if unreadable
 */
function ksefXmlDepositHead($xml)
{
    $doc = new DOMDocument();
    if ((string) $xml === '' || !@$doc->loadXML((string) $xml)) {
        return null;
    }
    $xp = new DOMXPath($doc);
    $get = function ($path) use ($xp) {
        $l = $xp->query($path);
        return $l->length ? trim($l->item(0)->textContent) : '';
    };
    $kurs = $get('//*[local-name()="Fa"]/*[local-name()="KursWalutyZ"]');
    if ($kurs === '') {
        $kurs = $get('//*[local-name()="Fa"]/*[local-name()="FaWiersz"]/*[local-name()="KursWaluty"]');
    }
    return array('p1' => $get('//*[local-name()="Fa"]/*[local-name()="P_1"]'), 'p6' => $get('//*[local-name()="Fa"]/*[local-name()="P_6"]'),
        'p15' => round((float) $get('//*[local-name()="Fa"]/*[local-name()="P_15"]'), 2), 'kurs' => round((float) $kurs, 6), 'parts' => ksefXmlPaymentParts($xml));
}

/**
 * @brief Stated deposit payments
 * @param DoliDB $db
 * @param Facture $root Deposit
 * @param int $beforeId 0: all
 * @return array|null Null if unsent
 */
function ksefDepositStatedParts($db, $root, $beforeId = 0)
{
    $head = ksefXmlDepositHead(ksefLatestSentXml($db, array((int) $root->id)));
    if ($head === null) {
        return null;
    }
    $parts = array();
    $add = function ($day, $kurs, $amount) use (&$parts) {
        $k = $day . '|' . number_format((float) $kurs, 6, '.', '');
        if (!isset($parts[$k])) {
            $parts[$k] = array('day' => (string) $day, 'kurs' => round((float) $kurs, 6), 'amount' => 0.0);
        }
        $parts[$k]['amount'] = round($parts[$k]['amount'] + (float) $amount, 2);
    };
    $single = $head['p6'] !== '' ? $head['p6'] : $head['p1'];
    if ($head['parts']) {
        foreach ($head['parts'] as $p) {
            $add($p['day'], $p['kurs'], $p['amount']);
        }
    } else {
        $add($single, $head['kurs'], $head['p15']);
    }
    foreach (ksefCorrectionChainIds($db, (int) $root->id) as $cid) {
        if ((int) $cid === (int) $root->id || ((int) $beforeId > 0 && (int) $cid >= (int) $beforeId)) {
            continue;
        }
        $h = ksefXmlDepositHead(ksefLatestSentXml($db, array((int) $cid)));
        if ($h === null) {
            continue;
        }
        if ($h['parts']) {
            foreach ($h['parts'] as $p) {
                $add($p['day'], $p['kurs'], $p['amount']);
            }
        } else {
            $add($single, $head['kurs'], $h['p15']);
        }
    }
    uasort($parts, function ($a, $b) {
        return strcmp($a['day'], $b['day']) ?: ($a['kurs'] <=> $b['kurs']);
    });
    return array_values($parts);
}

/**
 * @brief Legal-rate deposit parts
 * @param DoliDB $db
 * @param Facture $root Deposit
 * @param array $opt
 * @return array
 */
function ksefDepositTrueParts($db, $root, $opt = array())
{
    if (empty($root->id) || (int) $root->type !== Facture::TYPE_DEPOSIT || !ksefInvoiceIsForeign($root) || ksefInvoiceIsWdtOnly($db, $root)) {
        return array();
    }
    $head = ksefXmlDepositHead(ksefLatestSentXml($db, array((int) $root->id)));
    if ($head === null || $head['parts'] || $head['p1'] === '' || $head['kurs'] <= 0 || $head['p15'] <= 0) {
        return array();
    }
    $res = $db->query("SELECT p.datep, pf.multicurrency_amount FROM " . MAIN_DB_PREFIX . "paiement_facture AS pf INNER JOIN " . MAIN_DB_PREFIX . "paiement AS p ON p.rowid = pf.fk_paiement"
        . " WHERE pf.fk_facture IN (" . implode(',', array_map('intval', ksefCorrectionChainIds($db, (int) $root->id))) . ") ORDER BY p.datep, p.rowid");
    $left = $head['p15'];
    $early = array();
    while ($res && ($o = $db->fetch_object($res))) {
        $a = round((float) $o->multicurrency_amount, 2);
        if ($a < 0) {
            $left = round($left - $a, 2);
            continue;
        }
        $a = min($a, max(0.0, $left));
        $left = round($left - $a, 2);
        $day = ksefMomentDay($db->jdate($o->datep));
        if ($a >= 0.005 && $day < $head['p1']) {
            $early[$day] = round(($early[$day] ?? 0.0) + $a, 2);
        }
    }
    if (!$early) {
        return array();
    }
    dol_include_once('/ksef/class/ksef_nbp_currency_rate.class.php');
    $nbp = new KsefNbpCurrencyRate($db);
    $code = strtoupper((string) $root->multicurrency_code);
    $out = array();
    $other = false;
    $rest = $head['p15'];
    foreach ($early as $day => $a) {
        $ref = $nbp->getReferenceDate($root, 0, false, array('paid' => (int) strtotime($day)));
        $r = !empty($opt['cached_only']) ? $nbp->getCachedRateForDate($code, $ref) : $nbp->getRateForDateCached($code, $ref);
        $k = ($r && (float) $r['rate'] > 0) ? round((float) $r['rate'], 6) : 0.0;
        $other = $other || abs($k - $head['kurs']) >= 5e-7;
        $out[] = array('day' => (string) $day, 'kurs' => $k, 'amount' => $a);
        $rest = round($rest - $a, 2);
    }
    if (!$other) {
        return array();
    }
    if ($rest >= 0.005) {
        $out[] = array('day' => $head['p6'] !== '' ? $head['p6'] : $head['p1'], 'kurs' => $head['kurs'], 'amount' => $rest);
    }
    usort($out, function ($a, $b) {
        return strcmp($a['day'], $b['day']);
    });
    return $out;
}

/**
 * @brief Rate correction due
 * @param DoliDB $db
 * @param Facture $root Deposit
 * @param array $opt
 * @return array
 */
function ksefDepositRateRestatement($db, $root, $opt = array())
{
    $out = array('due' => false, 'payments' => array());
    $true = ksefDepositTrueParts($db, $root, $opt);
    if (!$true) {
        return $out;
    }
    $stated = ksefDepositStatedParts($db, $root);
    if (!$stated) {
        return $out;
    }
    $sum = 0.0;
    foreach ($stated as $p) {
        $sum += $p['amount'];
    }
    $rule = ksefFxRulesOf($db, (int) $root->id)['dkor'];
    $want = array();
    foreach (ksefDepositKorAlloc($true, round($sum, 2), $rule) as $i => $a) {
        $k = number_format($true[$i]['kurs'], 6, '.', '');
        $want[$k] = round(($want[$k] ?? 0.0) + $a, 2);
    }
    $have = array();
    foreach ($stated as $p) {
        $k = number_format($p['kurs'], 6, '.', '');
        $have[$k] = round(($have[$k] ?? 0.0) + $p['amount'], 2);
    }
    foreach (array_unique(array_merge(array_keys($want), array_keys($have))) as $k) {
        if (abs(($want[$k] ?? 0.0) - ($have[$k] ?? 0.0)) >= 0.005) {
            $out['due'] = true;
        }
    }
    if ($out['due']) {
        $head = ksefXmlDepositHead(ksefLatestSentXml($db, array((int) $root->id)));
        foreach ($true as $p) {
            if ($head && $p['day'] < $head['p1']) {
                $out['payments'][] = array('day' => $p['day'], 'amount' => $p['amount'], 'kurs' => $p['kurs'], 'stated_kurs' => $head['kurs']);
            }
        }
    }
    return $out;
}

/**
 * @brief Deposit correction plan
 * @param DoliDB $db
 * @param Facture $corr Correction
 * @param array $opt
 * @return array|null Null: proportional
 */
function ksefDepositKorPlan($db, $corr, $opt = array())
{
    if (empty($corr->id) || !in_array((int) $corr->type, array(Facture::TYPE_REPLACEMENT, Facture::TYPE_CREDIT_NOTE), true) || (int) $corr->fk_facture_source <= 0) {
        return null;
    }
    $root = ksefCorrectionRoot($db, $corr);
    if (!$root || (int) $root->type !== Facture::TYPE_DEPOSIT) {
        return null;
    }
    $rule = ksefFxRulesOf($db, (int) $corr->id)['dkor'];
    $stated = ksefDepositKorParts($db, $root, array('no_rates' => !ksefInvoiceIsForeign($root)) + $opt);
    $true = ksefDepositTrueParts($db, $root, $opt);
    if (!$true && ($rule === 'proportional' || count($stated) < 2)) {
        return null;
    }
    $foreign = ksefInvoiceIsForeign($root);
    $base = $true ? $true : $stated;
    $korBefore = ksefCorrectionStatedBefore($db, $corr);
    if ($korBefore === null) {
        $cv = ksefDepositCurrentValue($db, (int) $root->id, (int) $corr->id);
        $korBefore = $cv ? (float) ($foreign ? $cv['mttc'] : $cv['ttc']) : null;
    }
    if ($korBefore === null) {
        $korBefore = 0.0;
        foreach ($base as $p) {
            $korBefore += (float) $p['amount'];
        }
    }
    $korBefore = round((float) $korBefore, 2);
    $own = (float) ($foreign ? $corr->multicurrency_total_ttc : $corr->total_ttc);
    $change = $own;
    if ((int) $corr->type === Facture::TYPE_REPLACEMENT) {
        $src = new Facture($db);
        if ($src->fetch((int) $corr->fk_facture_source) <= 0) {
            return null;
        }
        $change = $own - (float) ($foreign ? $src->multicurrency_total_ttc : $src->total_ttc);
    }
    $change = round($change, 2);
    $list = function ($parts, $amounts) {
        $o = array();
        foreach (array_values($parts) as $i => $p) {
            $o[] = array('day' => (string) $p['day'], 'kurs' => round((float) $p['kurs'], 6), 'amount' => (float) $amounts[$i]);
        }
        return $o;
    };
    $after = $list($base, ksefDepositKorAlloc($base, round($korBefore + $change, 2), $rule));
    $before = null;
    if ($true) {
        $sp = ksefDepositStatedParts($db, $root, (int) $corr->id);
        if ($sp) {
            $sum = 0.0;
            foreach ($sp as $p) {
                $sum += $p['amount'];
            }
            $before = abs(round($sum, 2) - $korBefore) < 0.005 ? $sp : $list($sp, ksefDepositKorAlloc($sp, $korBefore, 'proportional'));
        }
    }
    if ($before === null) {
        $before = $list($base, ksefDepositKorAlloc($base, $korBefore, $rule));
    }
    $union = array();
    foreach (array(array($before, -1), array($after, 1)) as $side) {
        foreach ($side[0] as $p) {
            $k = $p['day'] . '|' . number_format($p['kurs'], 6, '.', '');
            if (!isset($union[$k])) {
                $union[$k] = array('day' => $p['day'], 'kurs' => $p['kurs'], 'amount' => 0.0);
            }
            $union[$k]['amount'] = round($union[$k]['amount'] + $side[1] * $p['amount'], 2);
        }
    }
    uasort($union, function ($a, $b) {
        return strcmp($a['day'], $b['day']) ?: ($a['kurs'] <=> $b['kurs']);
    });
    $parts = array_values($union);
    $nonZero = function ($l) {
        return array_values(array_filter($l, function ($p) {
            return abs((float) $p['amount']) >= 0.005;
        }));
    };
    $ownW = (int) $corr->type === Facture::TYPE_REPLACEMENT ? $nonZero($after) : $nonZero($parts);
    return array('parts' => $parts, 'own' => $ownW ? $ownW : $after, 'before' => $nonZero($before), 'restate' => (bool) $true, 'rule' => $rule);
}

/**
 * @brief Undocumented deposit money
 * @param DoliDB $db
 * @param Facture $dep Deposit
 * @return array
 */
function ksefDepositUnstatedPayments($db, $dep)
{
    $out = array('state' => '', 'currency' => ksefGetInvoiceCurrency($dep), 'payments' => array(), 'refund' => array(), 'lowered' => array());
    if (empty($dep->id) || (int) $dep->type !== Facture::TYPE_DEPOSIT || (int) $dep->statut === Facture::STATUS_DRAFT) {
        return $out;
    }
    $foreign = ksefInvoiceIsForeign($dep);
    $res = $db->query("SELECT p.datep, pf.amount, pf.multicurrency_amount FROM " . MAIN_DB_PREFIX . "paiement_facture AS pf"
        . " INNER JOIN " . MAIN_DB_PREFIX . "paiement AS p ON p.rowid = pf.fk_paiement WHERE pf.fk_facture IN ("
        . implode(',', array_map('intval', ksefCorrectionChainIds($db, (int) $dep->id))) . ") ORDER BY p.datep, p.rowid");
    $pays = array();
    while ($res && ($o = $db->fetch_object($res))) {
        $pays[] = array('day' => ksefMomentDay($db->jdate($o->datep)), 'amount' => (float) ($foreign ? $o->multicurrency_amount : $o->amount));
    }
    if (!$pays) {
        return $out;
    }
    $stated = null;
    $p15 = function ($xml) {
        $doc = new DOMDocument();
        if ((string) $xml === '' || !@$doc->loadXML((string) $xml)) {
            return null;
        }
        $n = (new DOMXPath($doc))->query('//*[local-name()="Fa"]/*[local-name()="P_15"]');
        return $n->length && is_numeric(trim($n->item(0)->textContent)) ? (float) trim($n->item(0)->textContent) : null;
    };
    foreach (ksefCorrectionChainIds($db, (int) $dep->id) as $cid) {
        $v = $p15(ksefLatestSentXml($db, array((int) $cid)));
        if ($v !== null) {
            $stated = (float) $stated + $v;
            if ($v < 0 && (int) $cid !== (int) $dep->id && ($k = $db->getRow("SELECT ref, datef FROM " . MAIN_DB_PREFIX . "facture WHERE rowid = " . (int) $cid))) {
                $out['lowered'] = array('ref' => (string) $k->ref, 'day' => ksefMomentDay($db->jdate($k->datef)));
            }
        } elseif ((int) $cid === (int) $dep->id) {
            break;
        }
    }
    if ($stated !== null) {
        $out['state'] = 'sent';
    } elseif (ksefInvoiceInAccounting($db, (int) $dep->id)) {
        $out['state'] = 'booked';
        $cv = ksefDepositCurrentValue($db, (int) $dep->id);
        $stated = $cv ? (float) ($foreign ? $cv['mttc'] : $cv['ttc']) : (float) ($foreign ? $dep->multicurrency_total_ttc : $dep->total_ttc);
    } else {
        return $out;
    }
    $stated = round((float) $stated, 2);
    $net = 0.0;
    $refunded = 0.0;
    $refundDay = '';
    foreach ($pays as $p) {
        $net = round($net + $p['amount'], 2);
        if ($p['amount'] < 0) {
            $refunded = round($refunded - $p['amount'], 2);
            $refundDay = (string) $p['day'];
        }
    }
    // art. 106j(1)(4)
    $cvNow = $refunded >= 0.005 ? ksefDepositCurrentValue($db, (int) $dep->id) : false;
    $kept = $cvNow ? min($stated, round((float) ($foreign ? $cvNow['mttc'] : $cvNow['ttc']), 2)) : $stated;
    $noKor = round(min($refunded, max(0.0, $kept - $net)), 2);
    if ($noKor >= 0.005) {
        $out['refund'] = array('day' => $refundDay, 'amount' => $noKor);
    }
    $beyond = round(max(0.0, $net - $stated), 2);
    $byDay = array();
    for ($i = count($pays) - 1; $i >= 0 && $beyond >= 0.005; $i--) {
        if ($pays[$i]['amount'] <= 0) {
            continue;
        }
        $part = round(min($beyond, $pays[$i]['amount']), 2);
        $beyond = round($beyond - $part, 2);
        $byDay[$pays[$i]['day']] = round(($byDay[$pays[$i]['day']] ?? 0.0) + $part, 2);
    }
    ksort($byDay);
    foreach ($byDay as $day => $amount) {
        $out['payments'][] = array('day' => (string) $day, 'amount' => $amount);
    }
    return $out;
}

/**
 * @brief Align credit rows
 * @param DoliDB $db
 * @param int $depositId
 * @return int Rows changed, -1 error
 */
function ksefDepositCreditPlnFollow($db, $depositId)
{
    $res = $db->query("SELECT tva_tx, vat_src_code, total_ht, total_tva, total_ttc, multicurrency_total_ttc FROM " . MAIN_DB_PREFIX . "facturedet WHERE fk_facture = " . (int) $depositId);
    if (!$res) {
        return -1;
    }
    $want = array();
    while ($o = $db->fetch_object($res)) {
        $k = ksefDepositRateKey($o->tva_tx, (string) $o->vat_src_code);
        if (!isset($want[$k])) {
            $want[$k] = array('ht' => 0.0, 'tva' => 0.0, 'ttc' => 0.0, 'mttc' => 0.0);
        }
        $want[$k]['ht'] += (float) $o->total_ht;
        $want[$k]['tva'] += (float) $o->total_tva;
        $want[$k]['ttc'] += (float) $o->total_ttc;
        $want[$k]['mttc'] += (float) $o->multicurrency_total_ttc;
    }
    $byKey = array();
    foreach (ksefDepositRows($db, (int) $depositId) as $r) {
        $byKey[$r['key']][] = $r;
    }
    $n = 0;
    foreach ($byKey as $k => $rows) {
        $r = $rows[0];
        if (count($rows) !== 1 || (int) $r['inv'] > 0 || (int) $r['line'] > 0 || !isset($want[$k]) || abs((float) $r['mttc'] - round($want[$k]['mttc'], 2)) >= 0.005) {
            continue;
        }
        $w = $want[$k];
        if (abs((float) $r['ht'] - round($w['ht'], 2)) < 0.005 && abs((float) $r['tva'] - round($w['tva'], 2)) < 0.005 && abs((float) $r['ttc'] - round($w['ttc'], 2)) < 0.005) {
            continue;
        }
        if (!$db->query("UPDATE " . MAIN_DB_PREFIX . "societe_remise_except SET amount_ht = " . price2num($w['ht'], 'MT') . ", amount_tva = " . price2num($w['tva'], 'MT')
            . ", amount_ttc = " . price2num($w['ttc'], 'MT') . " WHERE rowid = " . (int) $r['id'])) {
            return -1;
        }
        $n++;
    }
    return $n;
}

/**
 * @brief Credit rate gap
 * @param DoliDB $db
 * @param Facture|FactureFournisseur $invoice
 * @param array $opt
 * @return array
 */
function ksefDepositCreditPlnState($db, $invoice, $opt = array())
{
    global $conf, $langs;
    $out = array('applies' => false, 'currency' => '', 'remain' => 0.0, 'remain_pln' => 0.0, 'covered' => 0.0, 'deposits' => 0, 'notes' => 0, 'kind' => '', 'rates' => array(), 'rate' => 0.0,
        'fx_net' => 0.0, 'fx_vat' => 0.0, 'fx_note' => 0.0, 'fx_note_net' => 0.0, 'text' => '');
    if (!is_object($invoice) || empty($invoice->id) || !in_array($invoice->element ?? '', array('facture', 'invoice_supplier'), true)) {
        return $out;
    }
    $code = (string) $invoice->multicurrency_code;
    if ($code === '' || $code === $conf->currency) {
        return $out;
    }
    $kind = ksefInvoiceKind($invoice);
    if (!empty($opt['open']) && ((int) $invoice->status !== Facture::STATUS_VALIDATED || !empty($invoice->paye) || !empty($invoice->paid))) {
        return $out;
    }
    $out['currency'] = $code;
    $out['remain'] = (float) price2num($invoice->getRemainToPay(1), 'MT');
    $out['remain_pln'] = (float) price2num($invoice->getRemainToPay(0), 'MT');
    if ($out['remain_pln'] >= 0 || $out['remain'] < 0) {
        return $out;
    }
    $P = MAIN_DB_PREFIX;
    $supplier = $kind['element'] === 'invoice_supplier';
    $res = $db->query("SELECT re.multicurrency_amount_ttc, re.multicurrency_amount_ht, re.amount_ht, re.amount_ttc, src.rowid AS id, src.type, src.multicurrency_code, src.multicurrency_tx" . ($supplier ? "" : ", src.fk_facture_source")
        . " FROM " . $P . "societe_remise_except AS re INNER JOIN " . $P . $kind['table'] . " AS src ON src.rowid = re." . $kind['col_source']
        . " WHERE re." . $kind['col'] . " = " . (int) $invoice->id
        . " AND ((re.description = '" . $db->escape(KSEF_DEPOSIT_CREDIT_DESCRIPTION) . "' AND src.type = " . (int) Facture::TYPE_DEPOSIT . ")"
        . " OR (re.description LIKE '(CREDIT\\_NOTE)%' AND src.type = " . (int) Facture::TYPE_CREDIT_NOTE . " AND src.multicurrency_code = '" . $db->escape($code) . "'))"
        . " ORDER BY src.rowid");
    $sources = array();
    // avoids customer extrafields
    $kurs = function ($row) use ($supplier) {
        if ($supplier) {
            return ksefInvoiceKurs((object) array('multicurrency_code' => $row->multicurrency_code, 'multicurrency_tx' => $row->multicurrency_tx, 'type' => 0), array('own' => true));
        }
        return ksefInvoiceKurs($row);
    };
    $invK = round((float) $kurs($invoice), 6);
    $booked = array('dep' => array('ht' => 0.0, 'ttc' => 0.0, 'mc_ht' => 0.0, 'mc_ttc' => 0.0), 'note' => array('ht' => 0.0, 'ttc' => 0.0, 'mc_ht' => 0.0, 'mc_ttc' => 0.0));
    while ($res && ($o = $db->fetch_object($res))) {
        if ((int) $o->type === Facture::TYPE_CREDIT_NOTE && $invK > 0 && round((float) $kurs($o), 6) === $invK) {
            continue;
        }
        if ((int) $o->type === Facture::TYPE_DEPOSIT && $invK > 0 && round((float) $kurs($o), 6) === $invK && !ksefDepositCreditMultiRate($db, $o, $supplier)) {
            continue;
        }
        $b = (int) $o->type === Facture::TYPE_CREDIT_NOTE ? 'note' : 'dep';
        $booked[$b]['ht'] += (float) $o->amount_ht;
        $booked[$b]['ttc'] += (float) $o->amount_ttc;
        $booked[$b]['mc_ht'] += (float) $o->multicurrency_amount_ht;
        $booked[$b]['mc_ttc'] += (float) $o->multicurrency_amount_ttc;
        $out['covered'] += (float) $o->multicurrency_amount_ttc;
        if (!isset($sources[(int) $o->id])) {
            $sources[(int) $o->id] = $o;
        }
    }
    if (!$sources) {
        return $out;
    }
    foreach ($sources as $src) {
        $k = $kurs($src);
        if ($k > 0 && !in_array($k, $out['rates'], true)) {
            $out['rates'][] = $k;
        }
        if ((int) $src->type === Facture::TYPE_CREDIT_NOTE) {
            $out['notes']++;
        } else {
            $out['deposits']++;
        }
    }
    $out['kind'] = $out['notes'] === 0 ? 'deposit' : ($out['deposits'] === 0 ? 'note' : 'mixed');
    $out['rate'] = $kurs($invoice);
    $out['covered'] = (float) price2num($out['covered'], 'MT');
    $out['applies'] = true;
    $langs->load('ksef@ksef');
    $money = function ($v) use ($langs, $code) {
        return str_replace("\xc2\xa0", ' ', html_entity_decode(price($v, 0, $langs, 1, -1, 2, $code), ENT_QUOTES, 'UTF-8'));
    };
    $rate = function ($k) use ($langs) {
        $n = max(2, strlen(rtrim(substr(number_format((float) $k, 6, '.', ''), -6), '0')));
        return price((float) $k, 0, $langs, 0, $n, $n);
    };
    $many = $out['kind'] !== 'mixed' && ($out['deposits'] + $out['notes']) > 1 ? 'Many' : '';
    $base = $out['kind'] === 'note' ? 'KSEF_CreditNoteCredit' : ($out['kind'] === 'mixed' ? 'KSEF_CreditsMixed' : 'KSEF_DepositCredit');
    $parts = array($langs->transnoentitiesnoconv($base . 'Covered' . $many, $money($out['covered'])));
    $parts[] = $out['remain'] > 0 ? $langs->transnoentitiesnoconv('KSEF_DepositCreditLeftToPay', $money($out['remain']))
        : $langs->transnoentitiesnoconv('KSEF_DepositCreditNothingLeft', $code);
    $parts[] = $langs->transnoentitiesnoconv($base . 'PlnBelowZero' . $many,
        implode(', ', array_map($rate, $out['rates'])), $out['rate'] > 0 ? $rate($out['rate']) : '?');
    // uor art. 30(4)
    $out['fx_net'] = 0.0;
    $out['fx_vat'] = 0.0;
    if ($out['rate'] > 0) {
        $plnMoney = function ($v) use ($langs) {
            return str_replace("\xc2\xa0", ' ', html_entity_decode(price($v, 0, $langs, 1, -1, 2, 'PLN'), ENT_QUOTES, 'UTF-8'));
        };
        $k = round((float) $out['rate'], 6);
        $dep = $booked['dep'];
        $out['fx_net'] = (float) price2num($dep['ht'] - round($dep['mc_ht'] * $k, 2), 'MT');
        $gross = (float) price2num($dep['ttc'] - round($dep['mc_ttc'] * $k, 2), 'MT');
        $out['fx_vat'] = (float) price2num($gross - $out['fx_net'], 'MT');
        // art. 31b
        $note = $booked['note'];
        $out['fx_note_net'] = (float) price2num($note['ht'] - round($note['mc_ht'] * $k, 2), 'MT');
        $out['fx_note'] = (float) price2num($note['ttc'] - round($note['mc_ttc'] * $k, 2), 'MT');
        $mixed = $out['kind'] === 'mixed';
        if (abs($out['fx_net']) >= 0.005 || abs($out['fx_vat']) >= 0.005) {
            $positive = ($out['fx_net'] > 0) !== $supplier;
            $policy = ksefDepositFxPolicy();
            $word = $policy === 'historical' ? $langs->transnoentitiesnoconv($supplier ? 'KSEF_DepositFxCost' : 'KSEF_DepositFxRevenue')
                : $langs->transnoentitiesnoconv($positive ? 'KSEF_DepositFxPositive' : 'KSEF_DepositFxNegative');
            $parts[] = $mixed
                ? $langs->transnoentitiesnoconv('KSEF_DepositFxSplitMixed_' . $policy, $plnMoney(abs($out['fx_net'] + $out['fx_vat'])), $plnMoney(abs($out['fx_net'])), $word)
                : $langs->transnoentitiesnoconv('KSEF_DepositFxSplit_' . $policy, $plnMoney(abs($out['fx_net'])), $word);
            if (abs($out['fx_vat']) >= 0.005) {
                $parts[] = $langs->transnoentitiesnoconv($mixed ? 'KSEF_DepositFxVatPartMixed' : 'KSEF_DepositFxVatPart', $plnMoney(abs($out['fx_vat'])));
            }
        }
        if (abs($out['fx_note']) >= 0.005) {
            $parts[] = $langs->transnoentitiesnoconv($mixed ? 'KSEF_CreditNoteFxSplitMixed' : 'KSEF_CreditNoteFxSplit', $plnMoney(abs($out['fx_note'])),
                $langs->transnoentitiesnoconv((($out['fx_note'] > 0) !== $supplier) ? 'KSEF_DepositFxPositive' : 'KSEF_DepositFxNegative'), $plnMoney(abs($out['fx_note_net'])));
        }
    }
    $out['text'] = str_replace("\xc2\xa0", ' ', implode(' ', $parts));
    return $out;
}

/**
 * @brief Multi-rate deposit
 * @param DoliDB $db
 * @param object $row Deposit row
 * @param bool $supplier
 * @return bool
 */
function ksefDepositCreditMultiRate($db, $row, $supplier)
{
    if ($supplier || empty($row->id)) {
        return false;
    }
    $dep = new Facture($db);
    if ($dep->fetch((int) $row->id) <= 0) {
        return false;
    }
    return count(ksefDepositPaymentParts($db, $dep, array('no_rates' => true))) > 1;
}
