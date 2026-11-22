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
 * \file    ksef/lib/ksef_correction.lib.php
 * \ingroup ksef
 * \brief   Correction helpers
 */

/**
 * @brief Invoice korekta
 * @param DoliDB $db
 * @param int $invoiceId
 * @return object|null|false False on error
 */
function ksefKorektaOf($db, $invoiceId)
{
    $rep = ksefGetCorrectingInvoice($db, $invoiceId);
    if ($rep !== null) {
        return $rep;
    }
    $res = $db->query("SELECT rowid, ref, fk_statut FROM " . MAIN_DB_PREFIX . "facture"
        . " WHERE fk_facture_source = " . ((int) $invoiceId) . " AND type = " . Facture::TYPE_CREDIT_NOTE
        . " AND fk_statut IN (" . Facture::STATUS_VALIDATED . "," . Facture::STATUS_CLOSED . "," . Facture::STATUS_ABANDONED . ")"
        . " AND entity IN (" . getEntity('invoice') . ")"
        . " ORDER BY fk_statut ASC, rowid DESC");
    if (!$res) {
        dol_syslog("ksefKorektaOf: " . $db->lasterror(), LOG_ERR);
        return false;
    }
    $obj = $db->fetch_object($res);
    $db->free($res);
    return $obj ? $obj : null;
}

/**
 * @brief Build correction chain
 * @param Facture|FactureFournisseur $invoice Any chain member
 * @param DoliDB $db
 * @param int $maxDepth
 * @param bool $noteIsRoot Credit note is root
 * @return Facture[] Root first
 */
function ksefBuildCorrectionChain($invoice, $db, $maxDepth = 20, $noteIsRoot = false)
{
    $kind = ksefInvoiceKind($invoice);
    $cls = $kind['class'];
    // roots its chain
    $ownChain = $noteIsRoot && (int) $invoice->type === Facture::TYPE_CREDIT_NOTE;
    if (!$ownChain && (int) $invoice->type === Facture::TYPE_CREDIT_NOTE && !empty($invoice->id)) {
        $resOwn = $db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . $kind['table'] . " WHERE fk_facture_source = " . ((int) $invoice->id)
            . " AND type = " . Facture::TYPE_REPLACEMENT . " AND fk_statut > 0 LIMIT 1");
        $ownChain = $resOwn && $db->num_rows($resOwn) > 0;
    }
    for ($i = 0; !$ownChain && (int) $invoice->type === Facture::TYPE_CREDIT_NOTE && !empty($invoice->fk_facture_source) && $i < $maxDepth; $i++) {
        $src = new $cls($db);
        if ($src->fetch((int) $invoice->fk_facture_source) <= 0) {
            break;
        }
        $invoice = $src;
    }
    $current = $invoice;
    $backChain = array($current);
    $depth = 0;
    while ($current->type == Facture::TYPE_REPLACEMENT
        && !empty($current->fk_facture_source)
        && $depth < $maxDepth) {
        $parent = new $cls($db);
        if ($parent->fetch((int) $current->fk_facture_source) <= 0) {
            break;
        }
        array_unshift($backChain, $parent);
        $current = $parent;
        $depth++;
    }

    // replacement beats abandoned
    $chain = $backChain;
    $current = $invoice;
    $depth = 0;
    while ($depth < $maxDepth) {
        $sql_next = "SELECT rowid FROM " . MAIN_DB_PREFIX . $kind['table']
            . " WHERE fk_facture_source = " . ((int) $current->id)
            . " AND type = " . Facture::TYPE_REPLACEMENT
            . " AND fk_statut > 0"
            . " ORDER BY CASE WHEN fk_statut IN (" . Facture::STATUS_VALIDATED . "," . Facture::STATUS_CLOSED . ") THEN 0 ELSE 1 END, rowid DESC LIMIT 1";
        $res_next = $db->query($sql_next);
        $nextId = 0;
        if ($res_next && ($obj_next = $db->fetch_object($res_next))) {
            $nextId = (int) $obj_next->rowid;
        }
        if ($nextId <= 0) {
            break;
        }
        $next = new $cls($db);
        if ($next->fetch($nextId) <= 0) {
            break;
        }
        $chain[] = $next;
        $current = $next;
        $depth++;
    }

    return $chain;
}

/**
 * @brief Korekta expected totals
 * @param DoliDB $db
 * @param Facture|FactureFournisseur $facture Korekta
 * @param array $decl KOR declared totals
 * @param bool $foreign Invoice currency
 * @return array|null
 */
function ksefKorektaExpectedTotals($db, $facture, $decl, $foreign)
{
    $cls = ksefInvoiceKind($facture)['class'];
    $src = new $cls($db);
    if ($src->fetch((int) $facture->fk_facture_source) <= 0) {
        return null;
    }
    $state = ksefInvoiceKsefState($db, $src, $foreign);
    foreach ($decl['rates'] as $rate => $x) {
        $decl['rates'][$rate] = array('net' => (float) $x['net'], 'vat' => (float) $x['vat'], 'vat_pln' => null);
    }
    foreach ($state['rates'] as $rate => $b) {
        $x = isset($decl['rates'][$rate]) ? $decl['rates'][$rate] : array('net' => 0.0, 'vat' => 0.0);
        $decl['rates'][$rate] = array('net' => (float) $x['net'] + (float) $b['net'], 'vat' => (float) $x['vat'] + (float) $b['vat'], 'vat_pln' => null);
    }
    $decl['ttc'] = (float) $decl['ttc'] + (float) $state['ttc'];
    $state['invoice'] = $src;
    $decl['source'] = $state;
    return $decl;
}

/**
 * @brief Korekta source note
 * @param DoliDB $db
 * @param array|null $decl Expected totals
 * @param bool $foreign Invoice currency
 * @param string $currency
 * @return string
 */
function ksefKorektaSourceNote($db, $decl, $foreign, $currency)
{
    global $langs;
    if (empty($decl['source']['known']) || empty($decl['source']['invoice'])) {
        return '';
    }
    $src = $decl['source']['invoice'];
    $doli = ksefSupplierInvoiceRateTotals($db, $src, $foreign);
    $differs = round((float) $decl['source']['ttc'], 2) != round((float) ($foreign ? $src->multicurrency_total_ttc : $src->total_ttc), 2);
    foreach ($decl['source']['rates'] as $rate => $x) {
        $d = isset($doli[$rate]) ? $doli[$rate] : array('net' => 0.0, 'vat' => 0.0);
        if (round($x['net'], 2) != round($d['net'], 2) || round($x['vat'], 2) != round($d['vat'], 2)) {
            $differs = true;
        }
    }
    if (!$differs) {
        return '';
    }
    $langs->load('ksef@ksef');
    $amount = function ($v) use ($langs, $currency) {
        return str_replace("\xc2\xa0", ' ', html_entity_decode(price($v, 0, $langs, 1, -1, 2, $currency), ENT_QUOTES, 'UTF-8'));
    };
    return $langs->transnoentitiesnoconv('KSEF_ImportKorektaSourceDiffers', $src->ref, $amount(round((float) ($foreign ? $src->multicurrency_total_ttc : $src->total_ttc), 2)),
        $amount(round((float) $decl['source']['ttc'], 2)));
}

/**
 * @brief Korekta PLN XML
 * @param DoliDB $db
 * @param Facture|FactureFournisseur $facture
 * @param array $summary KOR vat_summary
 * @return int Rates moved, -1 error
 */
function ksefKorektaPlnFromXml($db, $facture, $summary)
{
    $k = ksefInvoiceKind($facture);
    $src = new $k['class']($db);
    if ($src->fetch((int) $facture->fk_facture_source) <= 0) {
        return -1;
    }
    $before = ksefSupplierInvoiceRateTotals($db, $src, false);
    $now = ksefSupplierInvoiceRateTotals($db, $facture, false);
    $key = function ($o) {
        return (float) $o->tva_tx . '|' . (float) $o->qty . '|' . price2num((float) $o->multicurrency_total_ht, 'MT') . '|' . (int) $o->fk_product;
    };
    $sql = "SELECT rowid, tva_tx, qty, multicurrency_total_ht, fk_product FROM " . MAIN_DB_PREFIX . $k['line_table'] . " WHERE " . $k['line_fk'] . " = ";
    $seen = $line = $diff = array();
    $res = $db->query($sql . (int) $src->id);
    while ($res && ($o = $db->fetch_object($res))) {
        $seen[$key($o)] = ($seen[$key($o)] ?? 0) + 1;
    }
    $res = $db->query($sql . (int) $facture->id . " AND (fk_remise_except IS NULL OR fk_remise_except = 0) ORDER BY rang, rowid");
    if (!$res) {
        return -1;
    }
    while ($o = $db->fetch_object($res)) {
        $r = (string) (float) $o->tva_tx;
        if (!empty($seen[$key($o)])) {
            $seen[$key($o)]--;
            $line[$r] = $line[$r] ?? (int) $o->rowid;
        } else {
            $diff[$r] = (int) $o->rowid;
        }
    }
    $moved = 0;
    foreach ((array) $summary as $sk => $x) {
        $r = is_numeric($sk) ? (string) (float) $sk : '0';
        $rateOnly = isset($x['vat_pln']) && abs((float) $x['vat']) < 0.005 && abs((float) $x['vat_pln']) >= 0.005 && (float) $r > 0;
        if (!isset($x['vat_pln']) || $x['vat_pln'] === null || abs((float) $x['vat']) < 0.005 && !$rateOnly || !isset($diff[$r]) && !isset($line[$r])) {
            continue;
        }
        $b = $before[$r] ?? array('net' => 0.0, 'vat' => 0.0);
        $n = $now[$r] ?? array('net' => 0.0, 'vat' => 0.0);
        $tva = (float) price2num($b['vat'] + (float) $x['vat_pln'] - $n['vat'], 'MT');
        $netPln = $rateOnly ? (float) $x['vat_pln'] * 100 / (float) $r : (float) $x['net'] * (float) $x['vat_pln'] / (float) $x['vat'];
        $ht = (float) price2num($b['net'] + (float) price2num($netPln, 'MT') - $n['net'], 'MT');
        if (abs($ht) < 0.005 && abs($tva) < 0.005) {
            continue;
        }
        // update_price() recomputes VAT
        $d = array('ht' => -$ht, 'tva' => -$tva, 'ttc' => -(float) price2num($ht + $tva, 'MT'), 'mht' => 0.0, 'mtva' => 0.0, 'mttc' => 0.0, 'pu' => 0.0);
        if (ksefInvoiceLineShift($db, $diff[$r] ?? $line[$r], (int) $facture->id, $d, $k['element']) !== '') {
            return -1;
        }
        $moved++;
    }
    return $moved;
}

/**
 * @brief Correction chain IDs
 * @param DoliDB $db
 * @param int $rootId
 * @param int $excludeId
 * @return array Root first
 */
function ksefCorrectionChainIds($db, $rootId, $excludeId = 0)
{
    $ids = array((int) $rootId);
    $next = array((int) $rootId);
    for ($i = 0; $next && $i < 20; $i++) {
        $res = $db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "facture WHERE fk_facture_source IN (" . implode(',', $next) . ") AND type IN (1, 2)");
        $next = array();
        while ($res && ($o = $db->fetch_object($res))) {
            if (!in_array((int) $o->rowid, $ids, true)) {
                $ids[] = (int) $o->rowid;
                $next[] = (int) $o->rowid;
            }
        }
    }
    return array_values(array_diff($ids, array((int) $excludeId)));
}

/**
 * @brief Correction P_15ZK
 * @param DoliDB $db
 * @param Facture $invoice
 * @return float|null Null if unsent
 */
function ksefCorrectionStatedBefore($db, $invoice)
{
    $P = MAIN_DB_PREFIX;
    $p15 = function ($xml) {
        $doc = new DOMDocument();
        if ($xml === '' || !@$doc->loadXML($xml)) {
            return null;
        }
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('fa', KSEF_FA3_NAMESPACE);
        $n = $xp->query('//fa:Fa/fa:P_15');
        return $n->length && is_numeric(trim($n->item(0)->textContent)) ? (float) trim($n->item(0)->textContent) : null;
    };
    $members = array();
    $cur = (int) $invoice->fk_facture_source;
    for ($i = 0; $cur > 0 && $i < 20 && !in_array($cur, $members, true); $i++) {
        $members[] = $cur;
        $res = $db->query("SELECT type, fk_facture_source FROM " . $P . "facture WHERE rowid = " . $cur);
        $o = $res ? $db->fetch_object($res) : null;
        $cur = ($o && (int) $o->type === 1) ? (int) $o->fk_facture_source : 0;
    }
    if (!$members) {
        return null;
    }
    $sum = 0.0;
    foreach ($members as $id) {
        $v = $p15(ksefLatestSentXml($db, array($id)));
        if ($v === null) {
            return null;
        }
        $sum += $v;
    }
    $mc = ksefGetInvoiceCurrency($invoice) !== 'PLN';
    $own = ksefCorrectionIssued($db, (int) $invoice->id);
    $res = $db->query("SELECT rowid, fk_statut, total_ttc, multicurrency_total_ttc FROM " . $P . "facture WHERE fk_facture_source IN (" . implode(',', $members) . ") AND type = 2"
        . " AND rowid <> " . (int) $invoice->id . " AND fk_statut IN (1, 2, 3)");
    $rows = array();
    while ($res && ($o = $db->fetch_object($res))) {
        $rows[] = $o;
    }
    foreach ($rows as $o) {
        $sent = ksefCorrectionIssued($db, (int) $o->rowid);
        // KSeF, then creation
        if ($sent ? ($own && $sent > $own) : ($own || (int) $o->fk_statut === 3 || (int) $o->rowid > (int) $invoice->id)) {
            continue;
        }
        $v = $p15(ksefLatestSentXml($db, array((int) $o->rowid)));
        $sum += $v === null ? (float) ($mc ? $o->multicurrency_total_ttc : $o->total_ttc) : $v;
    }
    return (float) price2num($sum, 'MT');
}

/**
 * @brief First issued submission
 * @param DoliDB $db
 * @param int $id Invoice ID
 * @return int 0 if none
 */
function ksefCorrectionIssued($db, $id)
{
    $res = $db->query("SELECT rowid, status, ksef_number, offline_mode, CASE WHEN fa3_xml IS NOT NULL AND fa3_xml <> '' THEN 'x' ELSE '' END AS fa3_xml"
        . " FROM " . MAIN_DB_PREFIX . "ksef_submissions WHERE fk_facture = " . (int) $id . ksefEnvironmentSql($db) . " ORDER BY rowid");
    while ($res && ($o = $db->fetch_object($res))) {
        if (ksefRowIsIssued($o)) {
            return (int) $o->rowid;
        }
    }
    return 0;
}

/**
 * @brief Earlier unsent correction
 * @param DoliDB $db
 * @param Facture $invoice
 * @return array|null
 */
function ksefEarlierCorrectionUnsent($db, $invoice)
{
    $P = MAIN_DB_PREFIX;
    if (!in_array((int) $invoice->type, array(Facture::TYPE_CREDIT_NOTE, Facture::TYPE_REPLACEMENT), true) || empty($invoice->fk_facture_source)) {
        return null;
    }
    $issued = function ($id) use ($db) {
        return ksefCorrectionIssued($db, $id);
    };
    if ($issued($invoice->id)) {
        return null;
    }
    $root = (int) $invoice->fk_facture_source;
    for ($i = 0; $i < 20; $i++) {
        $res = $db->query("SELECT type, fk_facture_source FROM " . $P . "facture WHERE rowid = " . $root);
        $o = $res ? $db->fetch_object($res) : null;
        if (!$o || !in_array((int) $o->type, array(1, 2), true) || empty($o->fk_facture_source)) {
            break;
        }
        $root = (int) $o->fk_facture_source;
    }
    if (!$issued($root)) {
        return null;
    }
    $ids = ksefCorrectionChainIds($db, $root, (int) $invoice->id);
    // abandoned: never sent
    $res = $db->query("SELECT rowid, ref FROM " . $P . "facture WHERE rowid IN (" . implode(',', $ids) . ") AND type IN (1, 2)"
        . " AND fk_statut IN (1, 2) AND (rowid < " . (int) $invoice->id
        . " OR EXISTS (SELECT 1 FROM " . $P . "ksef_submissions s WHERE s.fk_facture = " . $P . "facture.rowid"
        . " AND s.status IN ('PENDING', 'SUBMITTED', 'TIMEOUT')" . ksefEnvironmentSql($db, 's.')
        . " AND NOT EXISTS (SELECT 1 FROM " . $P . "ksef_submissions n WHERE n.fk_facture = s.fk_facture AND n.environment = s.environment AND n.rowid > s.rowid)"
        . ")) ORDER BY rowid");
    $list = array();
    while ($res && ($o = $db->fetch_object($res))) {
        $list[] = array('id' => (int) $o->rowid, 'ref' => (string) $o->ref);
    }
    foreach ($list as $c) {
        if (!$issued($c['id'])) {
            return $c;
        }
    }
    return null;
}

/**
 * @brief Earlier correction refusal
 * @param array $earlier
 * @return string
 */
function ksefEarlierCorrectionRefusal($earlier)
{
    global $langs;
    $langs->load('ksef@ksef');
    $link = '<a href="' . DOL_URL_ROOT . '/compta/facture/card.php?facid=' . (int) $earlier['id'] . '">' . dol_escape_htmltag($earlier['ref']) . '</a>';
    return ksefTransHtml('KSEF_EarlierCorrectionUnsent', array($link));
}

/**
 * @brief Correction source citation
 * @param DoliDB $db
 * @param Facture $invoice Correction
 * @return array
 */
function ksefCorrectionSourceRef($db, $invoice)
{
    $out = array('state' => '', 'source_id' => 0, 'source_ref' => '');
    if (!is_object($invoice) || !in_array((int) $invoice->type, array(Facture::TYPE_CREDIT_NOTE, Facture::TYPE_REPLACEMENT), true)) {
        return $out;
    }
    $srcId = (int) ($invoice->fk_facture_source ?? 0);
    if ($srcId <= 0) {
        return $out;
    }
    $out['source_id'] = $srcId;
    $res = $db->query("SELECT ref FROM " . MAIN_DB_PREFIX . "facture WHERE rowid = " . $srcId);
    if ($res && ($o = $db->fetch_object($res))) {
        $out['source_ref'] = (string) $o->ref;
    }
    dol_include_once('/ksef/class/ksef_submission.class.php');
    // never cite TEST
    $acc = $db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "ksef_submissions WHERE fk_facture = " . $srcId
        . " AND status = 'ACCEPTED' AND ksef_number IS NOT NULL AND ksef_number <> '' AND ksef_number NOT LIKE 'OFFLINE%'"
        . " AND environment = '" . $db->escape(ksefGetEnvironment()) . "' LIMIT 1");
    if ($acc && $db->num_rows($acc)) {
        $out['state'] = 'accepted';
        return $out;
    }
    $latest = new KsefSubmission($db);
    // TEST never blocks
    if ($latest->fetchByInvoice($srcId, ksefGetEnvironment()) > 0 && in_array($latest->status, array('FAILED', 'REJECTED'), true)
        && !ksefProductionIssuedBlock($db, $srcId)) {
        $out['state'] = 'rejected';
        return $out;
    }
    // art. 106nda(12), 106nf(11)
    $await = ksefSourceAwaitingRow($db, $srcId);
    if ($await) {
        $out['state'] = 'awaiting_number';
        $out['awaiting_kind'] = $await['kind'];
        return $out;
    }
    $out['state'] = 'none';
    return $out;
}

/**
 * @brief Awaiting-number refusal text
 * @param array $corr Source ref
 * @param bool $html
 * @return string
 */
function ksefCorrectionAwaitingRefusal($corr, $html = true)
{
    global $langs;
    $langs->load("ksef@ksef");
    $key = ($corr['awaiting_kind'] ?? '') === 'online' ? 'KSEF_CorrectionOriginalAwaitingAcceptance' : 'KSEF_CorrectionOriginalAwaitingNumber';
    if (!$html) {
        return $langs->transnoentitiesnoconv($key, (string) $corr['source_ref']);
    }
    $link = '<a href="' . DOL_URL_ROOT . '/compta/facture/card.php?facid=' . (int) $corr['source_id'] . '">' . dol_escape_htmltag($corr['source_ref']) . '</a>';
    return ksefTransHtml($key, array($link));
}

/**
 * @brief Correction dated early
 * @param DoliDB $db
 * @param Facture $invoice Correction
 * @param array|null $corr Source ref
 * @return array|null null when fine
 */
function ksefCorrectionNumberDayCheck($db, $invoice, $corr = null)
{
    if ($corr === null) {
        $corr = ksefCorrectionSourceRef($db, $invoice);
    }
    if (($corr['state'] ?? '') !== 'accepted' || empty($invoice->date)) {
        return null;
    }
    // offline: XML fixed
    $own = $db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "ksef_submissions WHERE fk_facture = " . (int) $invoice->id
        . " AND environment = '" . $db->escape(ksefGetEnvironment()) . "' AND fa3_xml IS NOT NULL"
        . " AND ((offline_mode IS NOT NULL AND offline_mode <> '') OR status = 'OFFLINE')");
    if ($own && $db->num_rows($own)) {
        return null;
    }
    $numberDay = ksefOfflineSourceNumberDay($db, (int) $corr['source_id']);
    $day = dol_print_date((int) $invoice->date, '%Y-%m-%d', 'tzserver');
    if ($numberDay === '' || $day >= $numberDay) {
        return null;
    }
    return array('day' => $day, 'number_day' => $numberDay, 'source_id' => (int) $corr['source_id'], 'source_ref' => (string) $corr['source_ref']);
}

/**
 * @brief Too-early correction refusal
 * @param array $early Day check result
 * @param bool $html
 * @return string
 */
function ksefCorrectionNumberDayRefusal($early, $html = true)
{
    global $langs;
    $langs->load("ksef@ksef");
    $day = dol_print_date(dol_stringtotime($early['day'] . ' 12:00:00'), 'day');
    $numberDay = dol_print_date(dol_stringtotime($early['number_day'] . ' 12:00:00'), 'day');
    if (!$html) {
        return $langs->transnoentitiesnoconv('KSEF_CorrectionBeforeSourceNumber', $day, (string) $early['source_ref'], $numberDay);
    }
    $link = '<a href="' . DOL_URL_ROOT . '/compta/facture/card.php?facid=' . (int) $early['source_id'] . '">' . dol_escape_htmltag($early['source_ref']) . '</a>';
    return ksefTransHtml('KSEF_CorrectionBeforeSourceNumber', array($day, $link, $numberDay));
}

/**
 * @brief Rejected-source refusal
 * @param array $corr Source ref
 * @return string HTML
 */
function ksefCorrectionSourceRefusal($corr)
{
    global $langs;
    $langs->load("ksef@ksef");
    $link = '<a href="' . DOL_URL_ROOT . '/compta/facture/card.php?facid=' . (int) $corr['source_id'] . '">' . dol_escape_htmltag($corr['source_ref']) . '</a>';
    return ksefTransHtml('KSEF_CorrectionOriginalRejected', array($link));
}

/**
 * @brief Accepted elsewhere only
 * @param DoliDB $db
 * @param int $invoiceId
 * @return string Other environment or ''
 */
function ksefAcceptedOnlyElsewhere($db, $invoiceId)
{
    $P = MAIN_DB_PREFIX;
    $env = $db->escape(ksefGetEnvironment());
    $o = $db->getRow("SELECT environment FROM " . $P . "ksef_submissions WHERE fk_facture = " . (int) $invoiceId . " AND status = 'ACCEPTED' AND environment <> '" . $env . "'"
        . " AND NOT EXISTS (SELECT 1 FROM " . $P . "ksef_submissions AS h WHERE h.fk_facture = " . (int) $invoiceId . " AND h.environment = '" . $env . "' AND h.status IN ('ACCEPTED', 'OFFLINE'))"
        . " ORDER BY rowid DESC");
    return $o ? (string) $o->environment : '';
}

/**
 * @brief Source other env
 * @param DoliDB $db
 * @param Facture $invoice
 * @return string Refusal or ''
 */
function ksefCorrectionOtherEnvRefusal($db, $invoice)
{
    global $langs;
    if (ksefGetEnvironment() !== 'PRODUCTION' || !is_object($invoice) || (int) ($invoice->fk_facture_source ?? 0) <= 0
        || !in_array((int) $invoice->type, array(Facture::TYPE_CREDIT_NOTE, Facture::TYPE_REPLACEMENT), true)) {
        return '';
    }
    $other = ksefAcceptedOnlyElsewhere($db, (int) $invoice->fk_facture_source);
    if ($other === '' || $other === 'PRODUCTION') {
        return '';
    }
    $o = $db->getRow("SELECT ref FROM " . MAIN_DB_PREFIX . "facture WHERE rowid = " . (int) $invoice->fk_facture_source);
    $langs->load('ksef@ksef');
    return $langs->transnoentitiesnoconv('KSEF_CorrectionSourceOtherEnv', $o ? (string) $o->ref : '', ksefEnvironmentLabel($other), ksefEnvironmentLabel('PRODUCTION'));
}

/**
 * @brief Correction without original
 * @param Facture $invoice
 * @return bool
 */
function ksefCorrectionMissingSource($invoice)
{
    // art. 106j(2)(2)
    return is_object($invoice) && in_array((int) $invoice->type, array(Facture::TYPE_CREDIT_NOTE, Facture::TYPE_REPLACEMENT), true)
        && (int) ($invoice->fk_facture_source ?? 0) <= 0;
}

/**
 * @brief Correction create URL
 * @param Facture $invoice
 * @return string
 */
function ksefCorrectionCreateUrl($invoice)
{
    return DOL_URL_ROOT . '/compta/facture/card.php?action=create&socid=' . (int) $invoice->socid . '&type=1&fac_replacement=' . (int) $invoice->id;
}

/**
 * @brief Correction chain root
 * @param DoliDB $db
 * @param Facture $invoice
 * @return Facture|null
 */
function ksefCorrectionRoot($db, $invoice)
{
    $cur = $invoice;
    for ($i = 0; $i < 20 && !empty($cur->fk_facture_source); $i++) {
        $src = new Facture($db);
        if ($src->fetch((int) $cur->fk_facture_source) <= 0) {
            return null;
        }
        if ((int) $src->type !== Facture::TYPE_REPLACEMENT && (int) $src->type !== Facture::TYPE_CREDIT_NOTE) {
            return $src;
        }
        $cur = $src;
    }
    return null;
}

/**
 * @brief Chain correction type
 * @param DoliDB $db
 * @param Facture $invoice
 * @param Facture $root
 * @return string '' if unknown
 */
function ksefCorrectionSentType($db, $invoice, $root)
{
    $map = array('VAT' => 'KOR', 'UPR' => 'KOR', 'KOR' => 'KOR', 'ZAL' => 'KOR_ZAL', 'KOR_ZAL' => 'KOR_ZAL', 'ROZ' => 'KOR_ROZ', 'KOR_ROZ' => 'KOR_ROZ');
    $sent = ksefSentInvoiceType($db, (int) $root->id);
    if (isset($map[$sent])) {
        return $map[$sent];
    }
    $cur = $invoice;
    for ($i = 0; $i < 20 && !empty($cur->fk_facture_source) && (int) $cur->fk_facture_source !== (int) $root->id; $i++) {
        $sent = ksefSentInvoiceType($db, (int) $cur->fk_facture_source);
        if (isset($map[$sent])) {
            return $map[$sent];
        }
        $src = new Facture($db);
        if ($src->fetch((int) $cur->fk_facture_source) <= 0) {
            break;
        }
        $cur = $src;
    }
    return '';
}

/**
 * @brief Correction cited number
 * @param DoliDB $db
 * @param Facture $invoice
 * @return array|null
 */
function ksefCorrectionCitation($db, $invoice)
{
    if (!in_array((int) $invoice->type, array(1, 2), true) || empty($invoice->fk_facture_source)) {
        return null;
    }
    $out = array('source_id' => (int) $invoice->fk_facture_source, 'source_ref' => '', 'sent' => false, 'cited' => '', 'will_cite' => '', 'mismatch' => false);
    $resql = $db->query("SELECT ref FROM " . MAIN_DB_PREFIX . "facture WHERE rowid = " . (int) $invoice->fk_facture_source);
    if ($resql && ($obj = $db->fetch_object($resql))) {
        $out['source_ref'] = (string) $obj->ref;
    }
    $out['will_cite'] = (string) ksefAcceptedKsefNumber($db, $invoice->fk_facture_source);
    $await = $out['will_cite'] === '' ? ksefSourceAwaitingRow($db, (int) $invoice->fk_facture_source) : null;
    $out['awaiting'] = $await !== null;
    $out['awaiting_kind'] = $await ? $await['kind'] : '';
    $sql = "SELECT fa3_xml FROM " . MAIN_DB_PREFIX . "ksef_submissions WHERE fk_facture = " . (int) $invoice->id;
    $sql .= " AND status IN ('ACCEPTED', 'OFFLINE') AND fa3_xml IS NOT NULL" . ksefEnvironmentSql($db) . " ORDER BY rowid DESC LIMIT 1";
    $resql = $db->query($sql);
    if ($resql && ($obj = $db->fetch_object($resql)) && $obj->fa3_xml !== '') {
        $out['sent'] = true;
        if (preg_match('#<(?:\w+:)?NrKSeFFaKorygowanej>([^<]+)</#', $obj->fa3_xml, $m)) {
            $out['cited'] = trim($m[1]);
        }
        $out['mismatch'] = ($out['cited'] === '' && $out['will_cite'] !== '');
    }
    return $out;
}

/**
 * @brief Invoice corrections
 * @param DoliDB $db
 * @param int $invoiceId
 * @return array
 */
function ksefInvoiceCorrections($db, $invoiceId)
{
    $out = array();
    $sql = "SELECT f.rowid, f.ref, f.type,";
    $sql .= " (SELECT s.status FROM " . MAIN_DB_PREFIX . "ksef_submissions s WHERE s.fk_facture = f.rowid" . ksefEnvironmentSql($db, 's.') . " ORDER BY s.date_submission DESC, s.rowid DESC LIMIT 1) AS sub_status,";
    $sql .= " (SELECT s2.ksef_number FROM " . MAIN_DB_PREFIX . "ksef_submissions s2 WHERE s2.fk_facture = f.rowid AND s2.status = 'ACCEPTED'" . ksefEnvironmentSql($db, 's2.') . " ORDER BY s2.rowid DESC LIMIT 1) AS ksef_number";
    $sql .= " FROM " . MAIN_DB_PREFIX . "facture f WHERE f.fk_facture_source = " . (int) $invoiceId . " AND f.type IN (1, 2)";
    $sql .= " ORDER BY f.rowid";
    $resql = $db->query($sql);
    if ($resql) {
        while ($obj = $db->fetch_object($resql)) {
            $out[] = array('id' => (int) $obj->rowid, 'ref' => (string) $obj->ref, 'type' => (int) $obj->type, 'status' => (string) $obj->sub_status, 'ksef_number' => (string) $obj->ksef_number);
        }
    }
    return $out;
}

/**
 * @brief Non-draft replacement
 * @param DoliDB $db
 * @param int $invoiceId
 * @param int $excludeId
 * @param string $element
 * @return object|null|false false on error
 */
function ksefGetCorrectingInvoice($db, $invoiceId, $excludeId = 0, $element = 'facture')
{
    ksefInvoiceKind($element); // loads Facture constants
    return ksefFindReplacementOf($db, $invoiceId, Facture::STATUS_VALIDATED . ',' . Facture::STATUS_CLOSED . ',' . Facture::STATUS_ABANDONED, $excludeId, false, $element);
}

/**
 * @brief Chain settled amount
 * @param DoliDB $db
 * @param Facture $source
 * @param int $maxDepth
 * @return array|false false on error
 */
function ksefCorrectionSettled($db, $source, $maxDepth = 20)
{
    $kind = ksefInvoiceKind($source);
    $cls = $kind['class'];
    $settled = 0.0;
    $settledMc = 0.0;
    $current = $source;
    $depth = 0;
    $members = array();
    while (true) {
        if ($current->type == Facture::TYPE_REPLACEMENT) {
            $members[] = (int) $current->id;
        }
        $settled += (float) $current->getSommePaiement(0);
        $settledMc += (float) $current->getSommePaiement(1);
        $sql = "SELECT COALESCE(SUM(amount_ttc), 0) AS a, COALESCE(SUM(multicurrency_amount_ttc), 0) AS m"
            . " FROM " . MAIN_DB_PREFIX . "societe_remise_except"
            . " WHERE " . $kind['col_source'] . " = " . ((int) $current->id)
            . " AND description LIKE '" . $db->escape($kind['excess']) . "%'";
        $res = $db->query($sql);
        if (!$res) {
            dol_syslog("ksefCorrectionSettled: " . $db->lasterror(), LOG_ERR);
            return false;
        }
        $obj = $db->fetch_object($res);
        $db->free($res);
        $settled -= (float) $obj->a;
        $settledMc -= (float) $obj->m;
        if ($current->type == Facture::TYPE_CREDIT_NOTE && $kind['element'] === 'facture') {
            $res = $db->query("SELECT COALESCE(SUM(amount_ttc), 0) AS a, COALESCE(SUM(multicurrency_amount_ttc), 0) AS m FROM " . MAIN_DB_PREFIX . "societe_remise_except"
                . " WHERE fk_facture_source = " . ((int) $current->id) . " AND description LIKE '(CREDIT_NOTE)%'");
            $obj = $res ? $db->fetch_object($res) : null;
            if (!$obj) {
                dol_syslog("ksefCorrectionSettled: " . $db->lasterror(), LOG_ERR);
                return false;
            }
            $settled -= (float) $obj->a;
            $settledMc -= (float) $obj->m;
        }
        if ($current->type != Facture::TYPE_REPLACEMENT || empty($current->fk_facture_source) || $depth >= $maxDepth) {
            break;
        }
        $parent = new $cls($db);
        if ($parent->fetch((int) $current->fk_facture_source) <= 0) {
            dol_syslog("ksefCorrectionSettled: cannot fetch chain member " . $current->fk_facture_source, LOG_ERR);
            return false;
        }
        $current = $parent;
        $depth++;
    }
    $cov = ksefShortfallCovers($db, $members, $kind['element']);
    return array(
        'settled' => (float) price2num($settled + $cov['paid'] - $cov['out'], 'MT'),
        'settled_mc' => (float) price2num($settledMc + $cov['paid_mc'] - $cov['out_mc'], 'MT'),
        'keep' => $cov['keep'],
        'keep_mc' => $cov['keep_mc'],
        'root' => $current,
    );
}

/**
 * @brief Delete correction discount
 * @param DoliDB $db
 * @param int $discountId
 * @param int $linkedTo 0: unlinked
 * @param string $element
 * @return int 1 ok, 0 none, -1 error
 */
function ksefDeleteCorrectionDiscount($db, $discountId, $linkedTo = 0, $element = 'facture')
{
    $kind = ksefInvoiceKind($element);
    $res = $db->query("SELECT rowid, " . $kind['col'] . " AS fk_facture, " . $kind['col_line'] . " AS fk_facture_line FROM " . MAIN_DB_PREFIX . "societe_remise_except WHERE rowid = " . ((int) $discountId));
    if (!$res) {
        dol_syslog("ksefDeleteCorrectionDiscount: " . $db->lasterror(), LOG_ERR);
        return -1;
    }
    $row = $db->fetch_object($res);
    $db->free($res);
    if (!$row) {
        return 0;
    }
    if (!empty($row->fk_facture_line) || (!empty($row->fk_facture) && (int) $row->fk_facture !== (int) $linkedTo)) {
        dol_syslog("ksefDeleteCorrectionDiscount: discount " . (int) $discountId . " is used by invoice " . (int) $row->fk_facture . " / line " . (int) $row->fk_facture_line . ", not removed", LOG_WARNING);
        return -1;
    }
    if (!$db->query("DELETE FROM " . MAIN_DB_PREFIX . "societe_remise_except WHERE rowid = " . ((int) $discountId))) {
        dol_syslog("ksefDeleteCorrectionDiscount: " . $db->lasterror(), LOG_ERR);
        return -1;
    }
    return 1;
}

/**
 * @brief Collective KOR ids
 * @param DoliDB $db
 * @param int $socid
 * @param array|null $rows
 * @return array rowid => true
 */
function ksefCollectiveKorIds($db, $socid, $rows = null)
{
    if ($rows === null) {
        $rows = array();
        $res = $db->query("SELECT f.rowid, f.ref, fe.ksef_number FROM " . MAIN_DB_PREFIX . "facture as f"
            . " LEFT JOIN " . MAIN_DB_PREFIX . "facture_extrafields as fe ON fe.fk_object = f.rowid"
            . " WHERE f.fk_soc = " . ((int) $socid) . " AND f.entity IN (" . getEntity('invoice') . ")");
        while ($res && ($obj = $db->fetch_object($res))) {
            $rows[(int) $obj->rowid] = $obj;
        }
    }
    $byKey = array();
    foreach ($rows as $id => $r) {
        $byKey[(string) $r->ref] = $id;
        if (trim((string) $r->ksef_number) !== '') {
            $byKey[trim((string) $r->ksef_number)] = $id;
        }
    }
    $collective = array();
    $res = $db->query("SELECT k.fk_facture, k.correction_data FROM " . MAIN_DB_PREFIX . "ksef_sales as k"
        . " INNER JOIN " . MAIN_DB_PREFIX . "facture as f ON f.rowid = k.fk_facture"
        . " WHERE f.fk_soc = " . ((int) $socid) . " AND k.invoice_type IN ('KOR', 'KOR_ZAL', 'KOR_ROZ')");
    while ($res && ($obj = $db->fetch_object($res))) {
        $cd = json_decode((string) $obj->correction_data, true);
        $keys = array();
        foreach ((array) ($cd['corrected_invoices'] ?? array()) as $c) {
            $keys[!empty($c['ksef_number']) ? $c['ksef_number'] : (string) ($c['invoice_number'] ?? '')] = true;
        }
        if (count($keys) > 1) {
            $collective[(int) $obj->fk_facture] = true;
            foreach (array_keys($keys) as $k) {
                if (isset($byKey[$k])) {
                    $collective[$byKey[$k]] = true;
                }
            }
            $lres = $db->query("SELECT fk_target FROM " . MAIN_DB_PREFIX . "element_element WHERE sourcetype = 'facture' AND targettype = 'facture'"
                . " AND fk_source = " . ((int) $obj->fk_facture));
            while ($lres && ($l = $db->fetch_object($lres))) {
                $collective[(int) $l->fk_target] = true;
            }
        }
    }
    return $collective;
}

/**
 * @brief Collective KOR refusal
 * @param DoliDB $db
 * @param int $sourceId
 * @return string Ref, '' if allowed
 */
function ksefCorrectionCollectiveRefused($db, $sourceId)
{
    $res = $db->query("SELECT fk_soc FROM " . MAIN_DB_PREFIX . "facture WHERE rowid = " . ((int) $sourceId));
    $obj = $res ? $db->fetch_object($res) : null;
    if (!$obj) {
        return '';
    }
    $collective = ksefCollectiveKorIds($db, (int) $obj->fk_soc);
    for ($x = (int) $sourceId, $i = 0; $x > 0 && $i < 20; $i++) {
        $res = $db->query("SELECT ref, fk_facture_source FROM " . MAIN_DB_PREFIX . "facture WHERE rowid = " . $x);
        $o = $res ? $db->fetch_object($res) : null;
        if (!$o) {
            break;
        }
        if (isset($collective[$x])) {
            return (string) $o->ref;
        }
        $x = (int) $o->fk_facture_source;
    }
    return '';
}

/**
 * @brief Correction sources
 * @param DoliDB $db
 * @param int $socid
 * @return array|false false on error
 */
function ksefCorrectionSources($db, $socid)
{
    $sql = "SELECT f.rowid, f.ref, f.type, f.fk_statut, f.paye, f.close_code, f.datef, f.fk_facture_source, fe.ksef_number,"
        . " (SELECT COUNT(*) FROM " . MAIN_DB_PREFIX . "paiement_facture as pf WHERE pf.fk_facture = f.rowid) as nbpay"
        . " FROM " . MAIN_DB_PREFIX . "facture as f"
        . " LEFT JOIN " . MAIN_DB_PREFIX . "facture_extrafields as fe ON fe.fk_object = f.rowid"
        . " WHERE f.fk_soc = " . ((int) $socid)
        . " AND f.entity IN (" . getEntity('invoice') . ")"
        . " AND f.fk_statut IN (" . Facture::STATUS_VALIDATED . ", " . Facture::STATUS_CLOSED . ", " . Facture::STATUS_ABANDONED . ")"
        . " ORDER BY f.datef DESC, f.rowid DESC";
    $res = $db->query($sql);
    if (!$res) {
        dol_syslog("ksefCorrectionSources: " . $db->lasterror(), LOG_ERR);
        return false;
    }
    $rows = array();
    while ($obj = $db->fetch_object($res)) {
        $rows[(int) $obj->rowid] = $obj;
    }
    $db->free($res);

    $children = array();
    $notes = array();
    $sql = "SELECT ff.rowid, ff.ref, ff.type, ff.fk_statut, ff.fk_facture_source, ffe.ksef_number"
        . " FROM " . MAIN_DB_PREFIX . "facture as ff"
        . " INNER JOIN " . MAIN_DB_PREFIX . "facture as f ON f.rowid = ff.fk_facture_source"
        . " LEFT JOIN " . MAIN_DB_PREFIX . "facture_extrafields as ffe ON ffe.fk_object = ff.rowid"
        . " WHERE f.fk_soc = " . ((int) $socid)
        . " AND f.entity IN (" . getEntity('invoice') . ")"
        . " ORDER BY ff.fk_statut ASC, ff.rowid DESC";
    $res = $db->query($sql);
    if (!$res) {
        dol_syslog("ksefCorrectionSources: " . $db->lasterror(), LOG_ERR);
        return false;
    }
    while ($obj = $db->fetch_object($res)) {
        $src = (int) $obj->fk_facture_source;
        if ((int) $obj->type == Facture::TYPE_CREDIT_NOTE && (int) $obj->fk_statut != Facture::STATUS_DRAFT) {
            if ((int) $obj->fk_statut != Facture::STATUS_ABANDONED) {
                $notes[$src][] = $obj->ref;
            }
            continue;
        }
        if (!isset($children[$src]) || ((int) $obj->type == Facture::TYPE_REPLACEMENT && (int) $children[$src]->type != Facture::TYPE_REPLACEMENT && (int) $children[$src]->fk_statut != Facture::STATUS_DRAFT)) {
            $children[$src] = $obj;
        }
    }
    $db->free($res);

    $collective = ksefCollectiveKorIds($db, $socid, $rows);
    $excluded = ksefIsCustomerExcluded($socid);
    // art. 106nda(12), 106nf(11)
    $awaiting = array();
    $resOff = $excluded ? false : $db->query("SELECT DISTINCT s.fk_facture FROM " . MAIN_DB_PREFIX . "ksef_submissions AS s"
        . " INNER JOIN " . MAIN_DB_PREFIX . "facture AS f ON f.rowid = s.fk_facture"
        . " WHERE f.fk_soc = " . ((int) $socid) . " AND s.environment = '" . $db->escape(ksefGetEnvironment()) . "'"
        . " AND ((s.offline_mode IS NOT NULL AND s.offline_mode <> '') OR s.status IN ('OFFLINE', 'SUBMITTED', 'PENDING', 'TIMEOUT'))"
        . " AND NOT EXISTS (SELECT 1 FROM " . MAIN_DB_PREFIX . "ksef_submissions AS a WHERE a.fk_facture = s.fk_facture"
        . " AND a.environment = s.environment AND a.status = 'ACCEPTED' AND a.ksef_number IS NOT NULL AND a.ksef_number <> ''"
        . " AND a.ksef_number NOT LIKE '%OFFLINE%' AND a.ksef_number NOT LIKE '%PENDING%' AND a.ksef_number NOT LIKE '%ERROR%')");
    while ($resOff && ($o = $db->fetch_object($resOff))) {
        $await = isset($rows[(int) $o->fk_facture]) ? ksefSourceAwaitingRow($db, (int) $o->fk_facture) : null;
        if ($await) {
            $awaiting[(int) $o->fk_facture] = $await['kind'];
        }
    }

    $out = array();
    foreach ($rows as $id => $r) {
        $type = (int) $r->type;
        $status = (int) $r->fk_statut;
        $paye = (int) $r->paye;
        $hasPayment = (int) $r->nbpay > 0;
        $ksef = trim((string) $r->ksef_number);
        $child = isset($children[$id]) ? $children[$id] : null;

        $via = '';
        if ($type == Facture::TYPE_CREDIT_NOTE) {
            $via = ($status != Facture::STATUS_ABANDONED && ($ksef !== '' || $excluded)) ? 'note' : '';
        } elseif ($status == Facture::STATUS_VALIDATED && !$paye && !$hasPayment) {
            $via = 'core';
        } elseif ($status == Facture::STATUS_ABANDONED && $r->close_code == Facture::CLOSECODE_ABANDONED && !$paye && !$hasPayment) {
            $via = 'core';
        } elseif ($status == Facture::STATUS_ABANDONED && $ksef !== '' && $r->close_code != Facture::CLOSECODE_REPLACED && !$paye && !$hasPayment
            && in_array($type, array(Facture::TYPE_STANDARD, Facture::TYPE_REPLACEMENT, Facture::TYPE_DEPOSIT))) {
            $via = 'abandoned';
        } elseif ($status == Facture::STATUS_CLOSED && $paye) {
            $via = 'paid';
        } elseif ($type == Facture::TYPE_REPLACEMENT && in_array($status, array(Facture::STATUS_VALIDATED, Facture::STATUS_CLOSED)) && $ksef !== '') {
            $via = 'kor';
        } elseif ($status == Facture::STATUS_VALIDATED && in_array($type, array(Facture::TYPE_STANDARD, Facture::TYPE_REPLACEMENT, Facture::TYPE_DEPOSIT))) {
            $via = 'partial';
        } elseif ($status == Facture::STATUS_CLOSED && !$paye && $ksef !== '' && in_array($type, array(Facture::TYPE_STANDARD, Facture::TYPE_DEPOSIT))) {
            $via = 'closed';
        }

        $reason = '';
        if ($child) {
            if ((int) $child->fk_statut == Facture::STATUS_DRAFT) {
                $reason = (int) $child->type == Facture::TYPE_CREDIT_NOTE ? 'KSEF_CorrSrcDraftCreditNote' : 'KSEF_CorrSrcDraftChild';
            } elseif ((int) $child->type == Facture::TYPE_REPLACEMENT && (int) $child->fk_statut == Facture::STATUS_ABANDONED && trim((string) $child->ksef_number) === '') {
                $reason = 'KSEF_CorrSrcAbandonedUnsent';
            } elseif ((int) $child->type == Facture::TYPE_REPLACEMENT) {
                $reason = 'KSEF_CorrSrcCorrected';
            } else {
                $reason = 'KSEF_CorrSrcLinked';
            }
        } elseif ($collective) {
            for ($x = $id, $i = 0; $x > 0 && $i < 20 && $reason === ''; $i++) {
                $reason = isset($collective[$x]) ? 'KSEF_CorrSrcCollectiveKor' : '';
                $x = isset($rows[$x]) ? (int) $rows[$x]->fk_facture_source : 0;
            }
        }
        $reasonRef = '';
        if ($reason === '' && !$child && $type == Facture::TYPE_CREDIT_NOTE && $status != Facture::STATUS_ABANDONED) {
            $noteRefusal = ksefNoteKorektaRefusal($db, $id, (int) $r->fk_facture_source, $ksef, $excluded);
            if ($noteRefusal['reason'] !== '') {
                $reason = $noteRefusal['reason'];
                $reasonRef = $noteRefusal['ref'];
                $via = '';
            }
        } elseif ($reason === '' && !$child && $via === '') {
            if ($status == Facture::STATUS_CLOSED) {
                $reason = 'KSEF_CorrSrcClosedUnpaid';
            } elseif ($status == Facture::STATUS_VALIDATED) {
                $reason = 'KSEF_CorrSrcPaymentNotKsef';
            }
        }
        $awaitingSrc = $reason === '' && !$child && $via !== '' && isset($awaiting[$id]);
        if ($awaitingSrc) {
            $reason = $awaiting[$id] === 'online' ? 'KSEF_CorrSrcAwaitingAcceptance' : 'KSEF_CorrSrcAwaitingNumber';
            $via = '';
        }
        if ($reason !== '' && !$awaitingSrc && ($status == Facture::STATUS_ABANDONED || !in_array($type, array(Facture::TYPE_STANDARD, Facture::TYPE_REPLACEMENT, Facture::TYPE_DEPOSIT, Facture::TYPE_CREDIT_NOTE)))) {
            continue;
        }
        if ($via === '' && $reason === '') {
            continue;
        }
        $out[$id] = array(
            'id' => $id,
            'ref' => $r->ref,
            'type' => $type,
            'status' => $status,
            'paye' => $paye,
            'date' => (int) $db->jdate($r->datef),
            'has_payment' => $hasPayment,
            'ksef_number' => $ksef,
            'eligible' => !$child && $via !== '' && $reason === '',
            'via' => $child ? '' : $via,
            'reason' => $reason,
            'child_ref' => $child ? $child->ref : $reasonRef,
            'child_id' => $child ? (int) $child->rowid : 0,
            'child_type' => $child ? (int) $child->type : -1,
            'child_status' => $child ? (int) $child->fk_statut : -1,
            'child_sent' => $child ? (trim((string) $child->ksef_number) !== '') : false,
            'notes' => $notes[$id] ?? array(),
        );
    }
    return $out;
}

/**
 * @brief Note correction refusal
 * @param DoliDB $db
 * @param int $noteId
 * @param int $sourceId
 * @param string $ksefNumber
 * @param bool $excluded
 * @return array
 */
function ksefNoteKorektaRefusal($db, $noteId, $sourceId, $ksefNumber, $excluded = false)
{
    if (trim((string) $ksefNumber) === '' && !$excluded) {
        if (ksefNoteSendInFlight($db, (int) $noteId)) {
            return array('reason' => 'KSEF_CorrSrcNoteInFlight', 'ref' => '');
        }
        return array('reason' => (int) $sourceId > 0 ? 'KSEF_CorrSrcNoteNotKsef' : 'KSEF_CorrSrcNoteNotKsefNoSource', 'ref' => '');
    }
    if (ksefDepositRootId($db, (int) $noteId) > 0) {
        return array('reason' => 'KSEF_CorrSrcNoteDeposit', 'ref' => '');
    }
    $absorber = ksefNoteAbsorbedBy($db, (int) $noteId, (int) $sourceId);
    if ($absorber !== '') {
        return array('reason' => 'KSEF_CorrSrcNoteAbsorbed', 'ref' => $absorber);
    }
    return array('reason' => '', 'ref' => '');
}

/**
 * @brief Note absorbing correction
 * @param DoliDB $db
 * @param int $noteId
 * @param int $sourceId
 * @return string Ref, '' if none
 */
function ksefNoteAbsorbedBy($db, $noteId, $sourceId)
{
    if ((int) $sourceId <= 0) {
        return '';
    }
    $inForce = ksefGetActiveReplacement($db, (int) $sourceId);
    if (!$inForce) {
        return '';
    }
    $absorb = array_map('intval', (array) (ksefDepositState($db, (int) $inForce->rowid)['absorb'] ?? array()));
    return in_array((int) $noteId, $absorb, true) ? (string) $inForce->ref : '';
}

/**
 * @brief Chain root note
 * @param DoliDB $db
 * @param int $invoiceId
 * @return int 0 if none
 */
function ksefNoteChainRoot($db, $invoiceId)
{
    $id = (int) $invoiceId;
    for ($i = 0; $id > 0 && $i < 20; $i++) {
        $res = $db->query("SELECT type, fk_facture_source FROM " . MAIN_DB_PREFIX . "facture WHERE rowid = " . $id);
        $o = $res ? $db->fetch_object($res) : null;
        if (!$o) {
            return 0;
        }
        if ((int) $o->type === Facture::TYPE_CREDIT_NOTE) {
            return $id;
        }
        if ((int) $o->type !== Facture::TYPE_REPLACEMENT) {
            return 0;
        }
        $id = (int) $o->fk_facture_source;
    }
    return 0;
}

/**
 * @brief Note correction active
 * @param DoliDB $db
 * @param int $noteId
 * @return object|null
 */
function ksefNoteKorektaInForce($db, $noteId)
{
    $tip = null;
    $id = (int) $noteId;
    for ($i = 0; $id > 0 && $i < 20; $i++) {
        $res = $db->query("SELECT rowid, ref, total_ht, total_tva, total_ttc, multicurrency_total_ht, multicurrency_total_tva, multicurrency_total_ttc"
            . " FROM " . MAIN_DB_PREFIX . "facture WHERE fk_facture_source = " . $id . " AND type = " . Facture::TYPE_REPLACEMENT
            . " AND fk_statut IN (" . Facture::STATUS_VALIDATED . ", " . Facture::STATUS_CLOSED . ") ORDER BY rowid DESC LIMIT 1");
        $o = $res ? $db->fetch_object($res) : null;
        if (!$o) {
            break;
        }
        $tip = $o;
        $id = (int) $o->rowid;
    }
    return $tip;
}

/**
 * @brief Notes in force
 * @param DoliDB $db
 * @param int $sourceId
 * @param array $only Note ids
 * @return array|false false on error
 */
function ksefCorrectionNotes($db, $sourceId, $only = array())
{
    $P = MAIN_DB_PREFIX;
    $R = $P . "societe_remise_except";
    $src = (int) $sourceId;
    $sql = "SELECT f.rowid, f.ref, f.fk_statut, f.paye, f.close_code, f.close_note, f.total_ht, f.total_tva, f.total_ttc,"
        . " f.multicurrency_total_ht, f.multicurrency_total_tva, f.multicurrency_total_ttc,"
        . " (SELECT COUNT(*) FROM " . $R . " r WHERE r.fk_facture_source = f.rowid) AS nrows,"
        . " (SELECT COALESCE(SUM(r.amount_ttc), 0) FROM " . $R . " r WHERE r.fk_facture_source = f.rowid AND r.fk_facture IS NULL AND r.fk_facture_line IS NULL) AS free,"
        . " (SELECT COALESCE(SUM(r.multicurrency_amount_ttc), 0) FROM " . $R . " r WHERE r.fk_facture_source = f.rowid AND r.fk_facture IS NULL AND r.fk_facture_line IS NULL) AS free_mc,"
        . " (SELECT COALESCE(SUM(r.amount_ttc), 0) FROM " . $R . " r LEFT JOIN " . $P . "facturedet d ON d.rowid = r.fk_facture_line"
        . "   WHERE r.fk_facture_source = f.rowid AND COALESCE(r.fk_facture, d.fk_facture) IS NOT NULL AND COALESCE(r.fk_facture, d.fk_facture) <> " . $src . ") AS used,"
        . " (SELECT COALESCE(SUM(r.multicurrency_amount_ttc), 0) FROM " . $R . " r LEFT JOIN " . $P . "facturedet d ON d.rowid = r.fk_facture_line"
        . "   WHERE r.fk_facture_source = f.rowid AND COALESCE(r.fk_facture, d.fk_facture) IS NOT NULL AND COALESCE(r.fk_facture, d.fk_facture) <> " . $src . ") AS used_mc,"
        . " (SELECT COALESCE(SUM(pf.amount), 0) FROM " . $P . "paiement_facture pf WHERE pf.fk_facture = f.rowid) AS paid,"
        . " (SELECT COALESCE(SUM(pf.multicurrency_amount), 0) FROM " . $P . "paiement_facture pf WHERE pf.fk_facture = f.rowid) AS paid_mc"
        . " FROM " . $P . "facture f WHERE f.fk_facture_source = " . $src . " AND f.type = " . Facture::TYPE_CREDIT_NOTE
        . " AND f.fk_statut IN (" . Facture::STATUS_VALIDATED . ", " . Facture::STATUS_CLOSED . ")"
        . ($only ? " AND f.rowid IN (" . implode(',', array_map('intval', $only)) . ")" : '') . " ORDER BY f.rowid";
    $res = $db->query($sql);
    if (!$res) {
        dol_syslog("ksefCorrectionNotes: " . $db->lasterror(), LOG_ERR);
        return false;
    }
    $out = array();
    while ($o = $db->fetch_object($res)) {
        $out[(int) $o->rowid] = array('id' => (int) $o->rowid, 'ref' => (string) $o->ref, 'st' => (int) $o->fk_statut, 'paye' => (int) $o->paye,
            'cc' => (string) $o->close_code, 'cn' => (string) $o->close_note,
            'ht' => (float) $o->total_ht, 'tva' => (float) $o->total_tva, 'ttc' => (float) $o->total_ttc,
            'mht' => (float) $o->multicurrency_total_ht, 'mtva' => (float) $o->multicurrency_total_tva, 'mttc' => (float) $o->multicurrency_total_ttc,
            'rows' => (int) $o->nrows, 'free' => (float) $o->free, 'free_mc' => (float) $o->free_mc,
            'out' => (float) price2num((float) $o->used - (float) $o->paid, 'MT'), 'out_mc' => (float) price2num((float) $o->used_mc - (float) $o->paid_mc, 'MT'),
            'corrected' => '');
    }
    $db->free($res);
    foreach ($out as $nid => $n) {
        $tip = ksefNoteKorektaInForce($db, $nid);
        if ($tip) {
            $out[$nid] = array_merge($n, array('corrected' => (string) $tip->ref,
                'ht' => (float) $tip->total_ht, 'tva' => (float) $tip->total_tva, 'ttc' => (float) $tip->total_ttc,
                'mht' => (float) $tip->multicurrency_total_ht, 'mtva' => (float) $tip->multicurrency_total_tva, 'mttc' => (float) $tip->multicurrency_total_ttc));
        }
    }
    return $out;
}

/**
 * @brief State field exists
 * @param DoliDB $db
 * @param string $element
 * @return bool
 */
function ksefCorrectionStateStorable($db, $element = 'facture')
{
    static $ok = array();
    $t = ksefInvoiceKind($element)['extrafields'];
    if (!isset($ok[$t])) {
        require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
        $ef = new ExtraFields($db);
        $ef->fetch_name_optionals_label($t);
        $ok[$t] = isset($ef->attributes[$t]['type']['ksef_correction_state']);
    }
    return $ok[$t];
}

/**
 * @brief Stored correction state
 * @param Facture $invoice Replacement
 * @return array
 */
function ksefCorrectionState($invoice)
{
    $raw = $invoice->array_options['options_ksef_correction_state'] ?? '';
    if ($raw === '' || $raw === null) {
        return array();
    }
    $state = json_decode((string) $raw, true);
    return is_array($state) ? $state : array();
}

/**
 * @brief Invoice stock slots
 * @param DoliDB $db
 * @param Facture|FactureFournisseur $inv
 * @param bool $supplier
 * @return array|false false on error
 */
function ksefCorrectionStockSlots($db, $inv, $supplier)
{
    $res = $db->query("SELECT fk_product, fk_entrepot, batch, SUM(value) AS v, MAX(rowid) AS r FROM " . MAIN_DB_PREFIX . "stock_mouvement"
        . " WHERE fk_origin = " . ((int) $inv->id) . " AND origintype = '" . $db->escape($inv->element) . "'"
        . " GROUP BY fk_product, fk_entrepot, batch ORDER BY r DESC");
    if (!$res) {
        return false;
    }
    $comp = array();
    $state = ksefCorrectionState($inv);
    foreach (!empty($state['stock']) ? $state['stock'] : array() as $m) {
        $k = (int) $m['p'] . '|' . (int) $m['w'] . '|' . (isset($m['b']) ? (string) $m['b'] : '');
        $comp[$k] = ($comp[$k] ?? 0) + (float) $m['q'];
    }
    $slots = array();
    while ($o = $db->fetch_object($res)) {
        $k = (int) $o->fk_product . '|' . (int) $o->fk_entrepot . '|' . (string) $o->batch;
        $q = ($supplier ? (float) $o->v : -(float) $o->v) + ($comp[$k] ?? 0);
        if ($q > 1e-9) {
            $slots[(int) $o->fk_product][] = array('w' => (int) $o->fk_entrepot, 'b' => (string) $o->batch, 'q' => $q);
        }
    }
    $db->free($res);
    return $slots;
}

/**
 * @brief Stock core skips
 * @param DoliDB $db
 * @param Facture|FactureFournisseur $korekta
 * @param array $state
 * @param bool $foresee Core not moved yet
 * @param int $w Warehouse, -1 none
 * @param array $incoming
 * @return array|false false on error
 */
function ksefCorrectionStockSkipped($db, $korekta, $state, $foresee, $w, &$incoming)
{
    $incoming = array();
    $supplier = $korekta->element === 'invoice_supplier';
    $mark = (int) ($state['stock_mk'] ?? 0);
    if (!$foresee && $mark <= 0) {
        return array();
    }
    if ($foresee && empty($korekta->lines)) {
        $korekta->fetch_lines();
    }
    $want = array();
    foreach ($state['stock'] ?? array() as $m) {
        if ((float) $m['q'] > 0) {
            $want[(int) $m['p']] = 0.0;
        }
    }
    foreach ($korekta->lines as $line) {
        if (isset($want[(int) $line->fk_product]) && (float) $line->qty > 0) {
            $want[(int) $line->fk_product] += (float) $line->qty;
        }
    }
    if (!$want) {
        return array();
    }
    $sql = $foresee
        ? "SELECT rowid AS fk_product, " . (isModEnabled('productbatch') ? "tobatch" : "0") . " AS lot FROM " . MAIN_DB_PREFIX . "product WHERE rowid IN (" . implode(',', array_keys($want)) . ")"
        : "SELECT fk_product, SUM(value) AS v FROM " . MAIN_DB_PREFIX . "stock_mouvement WHERE fk_origin = " . (int) $korekta->id
            . " AND origintype = '" . $db->escape($korekta->element) . "' AND rowid > " . $mark . " AND value " . ($supplier ? "< 0" : "> 0")
            . " AND fk_product IN (" . implode(',', array_keys($want)) . ") GROUP BY fk_product";
    $res = $db->query($sql);
    if (!$res) {
        dol_syslog("ksefCorrectionStockSkipped: cannot read the stock movements of " . $korekta->ref . ": " . $db->lasterror(), LOG_ERR);
        return false;
    }
    while ($o = $db->fetch_object($res)) {
        $p = (int) $o->fk_product;
        if (!$foresee) {
            $want[$p] -= abs((float) $o->v);
        } elseif ($w > 0 && empty($o->lot)) {
            $incoming[$p] = array($w => $want[$p]);
            $want[$p] = 0.0;
        }
    }
    $why = $supplier && $w > 0 && getDolGlobalInt('STOCK_DISALLOW_NEGATIVE_TRANSFER') && getDolGlobalString('STOCK_CALCULATE_ON_SUPPLIER_BILL') ? 'neg' : 'none';
    $skip = array();
    foreach ($want as $p => $q) {
        if ($q > 1e-9) {
            $skip[$p] = array('q' => $q, 'w' => $w, 'why' => $why);
        }
    }
    return $skip;
}

/**
 * @brief Undo moves skip
 * @param array $moves
 * @param array $skip
 * @return array
 */
function ksefCorrectionStockCut($moves, $skip)
{
    $cut = $cutP = array();
    foreach (array(true, false) as $first) {
        foreach ($moves as $i => $m) {
            $p = (int) $m['p'];
            $left = isset($skip[$p]) ? $skip[$p]['q'] - ($cutP[$p] ?? 0) : 0;
            if ($left > 1e-9 && (float) $m['q'] > 0 && ((int) $m['w'] === (int) $skip[$p]['w']) === $first) {
                $cut[$i] = min($left, (float) $m['q']);
                $cutP[$p] = ($cutP[$p] ?? 0) + $cut[$i];
            }
        }
    }
    return array($cut, $cutP);
}

/**
 * @brief Undo stock shortfall
 * @param DoliDB $db
 * @param array $moves
 * @param array $cut
 * @param array $incoming
 * @return array|false false on error
 */
function ksefCorrectionStockShort($db, $moves, $cut, $incoming)
{
    $room = $short = $force = $rows = array();
    foreach ($moves as $i => $m) {
        $q = (float) $m['q'] - ($cut[$i] ?? 0);
        if ($q <= 1e-9) {
            continue;
        }
        $p = (int) $m['p'];
        $w = (int) $m['w'];
        $lot = isModEnabled('productbatch') && isset($m['b']) && (string) $m['b'] !== '' ? (string) $m['b'] : '';
        $k = $p . '|' . $w . '|' . $lot;
        if (!isset($room[$k])) {
            $sql = "SELECT pr.stockable_product AS sp, " . ($lot !== '' ? "pb.qty" : "ps.reel") . " AS q FROM " . MAIN_DB_PREFIX . "product pr"
                . " LEFT JOIN " . MAIN_DB_PREFIX . "product_stock ps ON ps.fk_product = pr.rowid AND ps.fk_entrepot = " . $w
                . ($lot !== '' ? " LEFT JOIN " . MAIN_DB_PREFIX . "product_batch pb ON pb.fk_product_stock = ps.rowid AND pb.batch = '" . $db->escape($lot) . "'" : "")
                . " WHERE pr.rowid = " . $p;
            $res = $db->query($sql);
            $o = $res ? $db->fetch_object($res) : null;
            if (!$o) {
                dol_syslog("ksefCorrectionStockShort: cannot read the stock of product $p: " . $db->lasterror(), LOG_ERR);
                return false;
            }
            $in = $lot === '' ? (float) ($incoming[$p][$w] ?? 0) : 0.0;
            $room[$k] = (int) $o->sp === 1 ? array('s' => (float) $o->q, 'in' => $in, 'all' => (float) $o->q + $in, 'out' => 0.0, 'took' => 0.0) : null;
        }
        if ($room[$k] === null) {
            continue;
        }
        $r = &$room[$k];
        $take = min($q, max(0.0, $r['s'] + $r['in']));
        if ($take < $q - 1e-9) {
            $cut[$i] = ($cut[$i] ?? 0) + $q - $take;
            $short[$p] = ($short[$p] ?? 0) + $q - $take;
        }
        $force[$i] = $take > $r['s'] + 1e-9;
        $r['s'] -= $take;
        $r['out'] += $q;
        $r['took'] += $take;
        unset($r);
    }
    foreach ($room as $k => $r) {
        if ($r !== null && $r['took'] < $r['out'] - 1e-9) {
            list($p, $w, $lot) = explode('|', $k, 3);
            $rows[] = array('p' => (int) $p, 'w' => (int) $w, 'b' => $lot, 'should' => $r['all'] - $r['out'], 'ends' => $r['all'] - $r['took']);
        }
    }
    return array('cut' => $cut, 'force' => $force, 'short' => $short, 'rows' => $rows);
}

/**
 * @brief Supplier undo prices
 * @param DoliDB $db
 * @param FactureFournisseur $korekta
 * @param array $moves
 * @param array $cutP
 * @param array $offset
 * @return array
 */
function ksefCorrectionStockBack($db, $korekta, $moves, $cutP, $offset = array())
{
    $back = $out = array();
    foreach ($moves as $m) {
        if (isset($m['dv'])) {
            $back[(int) $m['p']] = array('dv' => (float) $m['dv'], 'kq' => (float) $m['kq'], 'q' => ($back[(int) $m['p']]['q'] ?? 0) + (float) $m['q'],
                'ks' => isset($m['ks']) ? (float) $m['ks'] : null, 'km' => (int) ($m['km'] ?? 0));
        }
    }
    foreach ($back as $p => $bk) {
        if ($bk['ks'] !== null && $bk['km'] > 0) {
            $res = $db->query("SELECT value FROM " . MAIN_DB_PREFIX . "stock_mouvement WHERE fk_product = " . (int) $p . " AND rowid > " . $bk['km']
                . " AND NOT (fk_origin = " . (int) $korekta->id . " AND origintype = '" . $db->escape($korekta->element) . "') ORDER BY rowid");
            $sk = $bk['ks'];
            while ($res && ($o = $db->fetch_object($res))) {
                $v = (float) $o->value;
                if ($v < 0) {
                    $bk['dv'] *= $sk > 1e-9 ? max(0.0, 1 + $v / $sk) : 0.0;
                }
                $sk += $v;
            }
        }
        $res = $db->query("SELECT stock, pmp FROM " . MAIN_DB_PREFIX . "product WHERE rowid = " . (int) $p);
        $o = $res ? $db->fetch_object($res) : null;
        $cq = $cutP[$p] ?? 0;
        $bk['q'] -= $cq;
        $out[$p] = array('r' => null, 'q' => max(0.0, $bk['q']), 'pmp' => $o ? (float) $o->pmp : 0.0);
        if ($o && $bk['q'] > 1e-9) {
            $s = (float) $o->stock + (float) ($offset[$p] ?? 0);
            $pmp = (float) $o->pmp;
            $target = ($s - $cq + $bk['kq']) * $pmp + $bk['dv'];
            $r = $s > 0 && $pmp > 0 ? ($target - $s * $pmp) / $bk['q'] : ($s + $bk['q'] > 1e-9 ? $target / ($s + $bk['q']) : null);
            if ($r !== null) {
                $r = (float) price2num(max(0.0, $r), 'MU');
                $out[$p]['r'] = $r;
                $out[$p]['pmp'] = $s > 0 && $pmp > 0 ? ($s * $pmp + $bk['q'] * $r) / ($s + $bk['q']) : $r;
            }
        }
    }
    return $out;
}

/**
 * @brief Supplier undo shortfall
 * @param DoliDB $db
 * @param FactureFournisseur $korekta
 * @param array $moves
 * @param array $skip
 * @param array $pending
 * @return array|false false on error
 */
function ksefCorrectionStockSupplierShort($db, $korekta, $moves, $skip, $pending)
{
    $neg = array();
    foreach ($skip as $p => $s) {
        if ($s['why'] === 'neg') {
            $neg[$p] = $s;
        }
    }
    if (!$neg) {
        return array();
    }
    list($cut, $cutP) = ksefCorrectionStockCut($moves, $neg);
    $u = $c = array();
    foreach ($moves as $i => $m) {
        $p = (int) $m['p'];
        if (isset($neg[$p]) && (float) $m['q'] > 0) {
            $u[$p][(int) $m['w']] = ($u[$p][(int) $m['w']] ?? 0) + (float) $m['q'];
            $c[$p][(int) $m['w']] = ($c[$p][(int) $m['w']] ?? 0) + ($cut[$i] ?? 0);
        }
    }
    $rows = array();
    $full = $part = array();
    foreach ($neg as $p => $s) {
        $ws = array_keys(($u[$p] ?? array()) + array((int) $s['w'] => 0));
        foreach ($ws as $w) {
            $st = $db->query("SELECT reel FROM " . MAIN_DB_PREFIX . "product_stock WHERE fk_product = " . (int) $p . " AND fk_entrepot = " . (int) $w);
            if (!$st) {
                dol_syslog("ksefCorrectionStockSupplierShort: cannot read the stock of product $p: " . $db->lasterror(), LOG_ERR);
                return false;
            }
            $o = $db->fetch_object($st);
            $base = ($o ? (float) $o->reel : 0.0) - (float) ($pending[$p][$w] ?? 0);
            $refused = $w === (int) $s['w'] ? (float) $s['q'] : 0.0;
            $should = $base - $refused + ($u[$p][$w] ?? 0);
            $ends = $base + ($u[$p][$w] ?? 0) - ($c[$p][$w] ?? 0);
            if (abs($should - $ends) > 1e-9) {
                $rows[] = array('p' => (int) $p, 'w' => (int) $w, 'b' => '', 'should' => $should, 'ends' => $ends);
            }
        }
        $pend = array_sum($pending[$p] ?? array());
        $full[$p] = -$pend - (float) $s['q'];
        $part[$p] = -$pend;
    }
    $a = ksefCorrectionStockBack($db, $korekta, $moves, array(), $full);
    $b = ksefCorrectionStockBack($db, $korekta, $moves, $cutP, $part);
    foreach ($neg as $p => $s) {
        $should = isset($a[$p]) ? (float) price2num($a[$p]['pmp'], 'MT') : null;
        $ends = isset($b[$p]) ? (float) price2num($b[$p]['pmp'], 'MT') : null;
        if ($should !== null && $ends !== null && abs($should - $ends) > 0.001) {
            $rows[] = array('p' => (int) $p, 'pmp' => 1, 'w' => 0, 'b' => '', 'should' => $should, 'ends' => $ends);
        }
    }
    return $rows;
}

/**
 * @brief Stock shortfall text
 * @param DoliDB $db
 * @param Translate $langs
 * @param array $rows
 * @param bool $html
 * @return string
 */
function ksefCorrectionStockShortText($db, $langs, $rows, $html = false)
{
    $langs->load('ksef@ksef');
    $out = array();
    foreach ($rows as $r) {
        $pref = $wref = '';
        $res = $db->query("SELECT ref FROM " . MAIN_DB_PREFIX . "product WHERE rowid = " . (int) $r['p']);
        if ($res && ($o = $db->fetch_object($res))) {
            $pref = $o->ref;
        }
        $res = $r['w'] > 0 ? $db->query("SELECT ref FROM " . MAIN_DB_PREFIX . "entrepot WHERE rowid = " . (int) $r['w']) : false;
        if ($res && ($o = $db->fetch_object($res))) {
            $wref = $o->ref;
        }
        $where = $wref . ($r['b'] !== '' ? ', ' . $langs->transnoentitiesnoconv('KSEF_CorrectionStockShortLot', $r['b']) : '');
        $line = !empty($r['pmp'])
            ? $langs->transnoentitiesnoconv('KSEF_CorrectionStockShortPmpRow', $pref, price2num($r['should'], 'MT'), price2num($r['ends'], 'MT'))
            : $langs->transnoentitiesnoconv('KSEF_CorrectionStockShortRow', $pref, $where, price2num($r['should'], 'MS'), price2num($r['ends'], 'MS'));
        $out[] = $html ? '<li>' . dol_escape_htmltag($line) . '</li>' : $line;
    }
    return $html ? '<ul>' . implode('', $out) . '</ul>' : implode('; ', $out);
}

/**
 * @brief Precheck korekta undo
 * @param DoliDB $db
 * @param Facture|FactureFournisseur $korekta
 * @param int $w Warehouse
 * @return array|false false on error
 */
function ksefCorrectionStockPrecheck($db, $korekta, $w)
{
    $supplier = $korekta->element === 'invoice_supplier';
    if (!in_array($korekta->element, array('facture', 'invoice_supplier'), true) || (int) $korekta->type !== 1 || (int) $korekta->status < 1 || $w <= 0
        || !isModEnabled('stock') || !getDolGlobalString($supplier ? 'STOCK_CALCULATE_ON_SUPPLIER_BILL' : 'STOCK_CALCULATE_ON_BILL')
        || !getDolGlobalInt('STOCK_DISALLOW_NEGATIVE_TRANSFER')) {
        return array();
    }
    if (empty($korekta->array_options)) {
        $korekta->fetch_optionals();
    }
    $state = ksefCorrectionState($korekta);
    if (empty($state['stock'])) {
        return array();
    }
    if (empty($korekta->lines)) {
        $korekta->fetch_lines();
    }
    if ($supplier) {
        // mirrors core livraison()
        $undo = $stock = $pending = $refused = array();
        foreach ($state['stock'] as $m) {
            if ((float) $m['q'] > 0) {
                $undo[(int) $m['p']] = 1;
            }
        }
        foreach ($korekta->lines as $line) {
            $p = (int) $line->fk_product;
            $q = (float) $line->qty;
            if ($p <= 0 || $q <= 0) {
                continue;
            }
            if (!isset($stock[$p])) {
                $res = $db->query("SELECT pr.stockable_product AS sp, ps.reel FROM " . MAIN_DB_PREFIX . "product pr LEFT JOIN " . MAIN_DB_PREFIX . "product_stock ps"
                    . " ON ps.fk_product = pr.rowid AND ps.fk_entrepot = " . (int) $w . " WHERE pr.rowid = " . $p);
                $o = $res ? $db->fetch_object($res) : null;
                if (!$o) {
                    return false;
                }
                $stock[$p] = (int) $o->sp === 1 ? (float) $o->reel : null;
            }
            if ($stock[$p] === null) {
                continue;
            }
            if ($stock[$p] >= $q) {
                $stock[$p] -= $q;
                $pending[$p][(int) $w] = ($pending[$p][(int) $w] ?? 0) + $q;
            } elseif (isset($undo[$p])) {
                $refused[$p] = ($refused[$p] ?? 0) + $q;
            }
        }
        $skip = array();
        foreach ($refused as $p => $q) {
            $skip[$p] = array('q' => $q, 'w' => (int) $w, 'why' => 'neg');
        }
        return ksefCorrectionStockSupplierShort($db, $korekta, $state['stock'], $skip, $pending);
    }
    $incoming = array();
    $skip = ksefCorrectionStockSkipped($db, $korekta, $state, true, (int) $w, $incoming);
    if ($skip === false) {
        return false;
    }
    list($cut) = ksefCorrectionStockCut($state['stock'], $skip);
    $short = ksefCorrectionStockShort($db, $state['stock'], $cut, $incoming);
    return $short === false ? false : $short['rows'];
}

/**
 * @brief Correction child links
 * @param array $src
 * @param User $user
 * @return array
 */
function ksefCorrectionChildLinks($src, $user)
{
    global $langs;

    if (empty($src['child_id']) || !isset($src['child_status'])) {
        return array();
    }
    $langs->load('ksef@ksef');
    $card = '/compta/facture/card.php?facid=' . ((int) $src['child_id']);
    $links = array();
    if ((int) $src['child_status'] == Facture::STATUS_DRAFT) {
        $note = isset($src['child_type']) && (int) $src['child_type'] == Facture::TYPE_CREDIT_NOTE;
        $links[] = array('href' => $card, 'label' => $langs->trans($note ? 'KSEF_CorrSrcOpenDraftCreditNote' : 'KSEF_CorrSrcOpenDraft'), 'delete' => false);
        // as core's $usercandelete
        if ($user->hasRight('facture', 'supprimer') || $user->hasRight('facture', 'creer')) {
            $links[] = array('href' => $card . '&action=delete&token=' . newToken(), 'label' => $langs->trans($note ? 'KSEF_CorrSrcDiscardDraftCreditNote' : 'KSEF_CorrSrcDiscardDraft'), 'delete' => true);
        }
    } elseif ((int) $src['child_status'] == Facture::STATUS_ABANDONED && (int) $src['child_type'] == Facture::TYPE_REPLACEMENT && empty($src['child_sent'])) {
        $links[] = array('href' => $card, 'label' => $langs->trans('KSEF_CorrSrcOpenAbandoned', $src['child_ref']), 'delete' => false);
    }
    return $links;
}
