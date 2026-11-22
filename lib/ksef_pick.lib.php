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
 * \file    lib/ksef_pick.lib.php
 * \ingroup ksef
 * \brief   Pick list eligibility
 */

/**
 * @brief Pick kinds
 * @return array kind => table, alias
 */
function ksefPickKinds()
{
    return array(
        'kor' => array('table' => 'facture', 'alias' => 'f'),
        'korzal' => array('table' => 'facture', 'alias' => 'f'),
        'roz' => array('table' => 'facture', 'alias' => 'f'),
        'offline' => array('table' => 'facture', 'alias' => 'f'),
        'recv_import' => array('table' => 'ksef_incoming', 'alias' => 'k'),
        'recv_kor' => array('table' => 'ksef_incoming', 'alias' => 'k'),
        'recv_deposits' => array('table' => 'ksef_incoming', 'alias' => 'k'),
        'sales' => array('table' => 'ksef_sales', 'alias' => 'k'),
    );
}

/**
 * @brief Pick filter SQL
 * @param DoliDB $db
 * @param string $pick Pick kind
 * @return string SQL starting with AND
 */
function ksefPickWhere($db, $pick)
{
    $P = MAIN_DB_PREFIX;
    $env = "'" . $db->escape(ksefGetEnvironment()) . "'";
    $sub = function ($statuses) use ($P, $env) {
        return "EXISTS (SELECT 1 FROM " . $P . "ksef_submissions AS ksefps WHERE ksefps.fk_facture = f.rowid AND ksefps.environment = " . $env
            . " AND ksefps.status IN ('" . implode("','", $statuses) . "'))";
    };
    $accepted = $sub(array('ACCEPTED'));
    $settles = "EXISTS (SELECT 1 FROM " . $P . "facturedet AS ksefpd INNER JOIN " . $P . "societe_remise_except AS ksefpr ON ksefpr.rowid = ksefpd.fk_remise_except"
        . " INNER JOIN " . $P . "facture AS ksefpz ON ksefpz.rowid = ksefpr.fk_facture_source WHERE ksefpd.fk_facture = f.rowid AND ksefpz.type = 3)";
    $open = "k.environment = " . $env . " AND k.import_status IN ('NEW', 'ERROR')";
    switch ($pick) {
        case 'kor':
            return " AND f.type = 0 AND " . $accepted . " AND NOT " . $settles;
        case 'korzal':
            return " AND (f.type = 3 OR (f.type = 0 AND " . $settles . ")) AND " . $accepted;
        case 'roz':
            return " AND f.type = 3 AND f.fk_statut > 0 AND " . $accepted . " AND NOT EXISTS (SELECT 1 FROM " . $P . "societe_remise_except AS ksefpu"
                . " WHERE ksefpu.fk_facture_source = f.rowid AND (ksefpu.fk_facture IS NOT NULL OR ksefpu.fk_facture_line IS NOT NULL))";
        case 'offline':
            return ksefPickOfflineWhere($db, $env);
        case 'recv_import':
            return " AND " . $open . " AND k.invoice_type IN ('VAT', 'UPR')";
        case 'recv_kor':
            return " AND " . $open . " AND k.invoice_type LIKE 'KOR%' AND EXISTS (SELECT 1 FROM " . $P . "ksef_incoming AS ksefpo"
                . " WHERE ksefpo.ksef_number = k.corrected_ksef_number AND ksefpo.entity IN (" . getEntity('ksef_incoming') . "))";
        case 'recv_deposits':
            return " AND " . $open . " AND k.invoice_type IN ('ZAL', 'ROZ')";
        case 'sales':
            return " AND k.environment = " . $env . " AND k.import_status NOT IN ('SKIPPED', 'FETCH_ERROR') AND k.fk_facture IS NULL"
                . " AND (k.link_status IS NULL OR k.link_status = '' OR k.link_status = 'IMPORTABLE')";
    }
    return " AND 1 = 0";
}

/**
 * @brief Offline pick rows
 * @param DoliDB $db
 * @param string $env Quoted environment
 * @return string SQL
 */
function ksefPickOfflineWhere($db, $env)
{
    require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
    $P = MAIN_DB_PREFIX;
    $sql = "SELECT f.rowid, s.rowid AS sid, s.status, s.offline_mode, s.ksef_reference, s.failure_kind, s.error_code FROM " . $P . "facture AS f"
        . " LEFT JOIN " . $P . "ksef_submissions AS s ON s.rowid = (SELECT MAX(s2.rowid) FROM " . $P . "ksef_submissions AS s2 WHERE s2.fk_facture = f.rowid AND s2.environment = " . $env . ")"
        . " WHERE f.entity IN (" . getEntity('invoice') . ") AND f.type IN (0, 1, 3) AND f.fk_statut IN (0, 1, 2)"
        . " AND (s.rowid IS NOT NULL OR f.datef < '" . $db->idate(dol_get_first_hour(dol_now())) . "') ORDER BY f.rowid DESC LIMIT 2000";
    $ids = array();
    $res = $db->query($sql);
    while ($res && ($o = $db->fetch_object($res))) {
        // card's own rule
        if (empty($o->sid) || ksefOfflineIssueRefusal($o, null, false) === '') {
            $ids[] = (int) $o->rowid;
        }
    }
    return $ids ? " AND f.rowid IN (" . implode(',', $ids) . ")" : " AND 1 = 0";
}

/**
 * @brief Eligible ids
 * @param DoliDB $db
 * @param string $pick Pick kind
 * @param int $limit
 * @return int[]
 */
function ksefPickEligibleIds($db, $pick, $limit = 200)
{
    $kinds = ksefPickKinds();
    if (!isset($kinds[$pick])) {
        return array();
    }
    $k = $kinds[$pick];
    $entity = $k['table'] === 'facture' ? getEntity('invoice') : getEntity($k['table']);
    $sql = "SELECT " . $k['alias'] . ".rowid FROM " . MAIN_DB_PREFIX . $k['table'] . " AS " . $k['alias'] . " WHERE " . $k['alias'] . ".entity IN (" . $entity . ")"
        . ksefPickWhere($db, $pick) . " ORDER BY " . $k['alias'] . ".rowid DESC" . ($limit > 0 ? " LIMIT " . (int) $limit : '');
    $ids = array();
    $res = $db->query($sql);
    while ($res && ($o = $db->fetch_object($res))) {
        $ids[] = (int) $o->rowid;
    }
    return $ids;
}

/**
 * @brief One row eligible
 * @param DoliDB $db
 * @param string $pick Pick kind
 * @param int $id Row id
 * @return bool
 */
function ksefPickEligible($db, $pick, $id)
{
    $kinds = ksefPickKinds();
    if (!isset($kinds[$pick]) || (int) $id <= 0) {
        return false;
    }
    $k = $kinds[$pick];
    $res = $db->query("SELECT " . $k['alias'] . ".rowid FROM " . MAIN_DB_PREFIX . $k['table'] . " AS " . $k['alias'] . " WHERE " . $k['alias'] . ".rowid = " . (int) $id
        . ksefPickWhere($db, $pick));
    return $res && $db->fetch_object($res) ? true : false;
}

/**
 * @brief Row pick kinds
 * @param DoliDB $db
 * @param string[] $picks Pick kinds
 * @param int[] $ids Row ids
 * @return array id => kinds
 */
function ksefPickKindsOfRows($db, $picks, $ids)
{
    $kinds = ksefPickKinds();
    $ids = array_filter(array_map('intval', (array) $ids));
    $out = array();
    foreach ($ids ? $picks : array() as $pick) {
        if (!isset($kinds[$pick])) {
            continue;
        }
        $a = $kinds[$pick]['alias'];
        $res = $db->query("SELECT " . $a . ".rowid FROM " . MAIN_DB_PREFIX . $kinds[$pick]['table'] . " AS " . $a . " WHERE " . $a . ".rowid IN (" . implode(',', $ids) . ")"
            . ksefPickWhere($db, $pick));
        while ($res && ($o = $db->fetch_object($res))) {
            $out[(int) $o->rowid][] = $pick;
        }
    }
    return $out;
}

/**
 * @brief Row pick attributes
 * @param array $rowKinds ksefPickKindsOfRows()
 * @param int $id
 * @return string HTML attributes
 */
function ksefPickRowAttrs($rowKinds, $id)
{
    if (empty($rowKinds[(int) $id])) {
        return '';
    }
    return ' data-ksef-pick-id="' . (int) $id . '" data-ksef-pick="' . dol_escape_htmltag(implode(' ', $rowKinds[(int) $id])) . '"';
}

/**
 * @brief Source pick warning
 * @param DoliDB $db
 * @param int $id Source invoice
 * @return string Unescaped, '' if none
 */
function ksefPickSourceWarning($db, $id)
{
    global $langs;
    require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
    $o = $db->getRow("SELECT rowid, ref, type, fk_soc FROM " . MAIN_DB_PREFIX . "facture WHERE rowid = " . (int) $id . " AND entity IN (" . getEntity('invoice') . ")");
    if (!$o || ksefIsCustomerExcluded((int) $o->fk_soc)) {
        return '';
    }
    if (in_array((int) $o->type, array(Facture::TYPE_STANDARD, Facture::TYPE_DEPOSIT), true)
        && (ksefPickEligible($db, 'kor', $id) || ksefPickEligible($db, 'korzal', $id))) {
        return '';
    }
    $langs->load('ksef@ksef');
    $env = ksefGetEnvironment();
    $corr = ksefCorrectionSourceRef($db, (object) array('type' => Facture::TYPE_REPLACEMENT, 'fk_facture_source' => (int) $id));
    if ($corr['state'] === 'accepted') {
        return '';
    }
    if ($corr['state'] === 'rejected') {
        return $langs->transnoentitiesnoconv('KSEF_PickSourceRejected', $o->ref, ksefEnvironmentLabel($env));
    }
    if ($corr['state'] === 'awaiting_number') {
        return $langs->transnoentitiesnoconv('KSEF_PickSourceAwaiting', $o->ref, ksefEnvironmentLabel($env));
    }
    $other = ksefAcceptedOnlyElsewhere($db, (int) $id);
    if ($other === '' && ksefProductionIssuedBlock($db, (int) $id)) {
        $other = 'PRODUCTION';
    }
    if ($other !== '') {
        return $langs->transnoentitiesnoconv($env === 'PRODUCTION' ? 'KSEF_PickSourceOtherEnv' : 'KSEF_PickSourceElsewhere', $o->ref, ksefEnvironmentLabel($other), ksefEnvironmentLabel($env));
    }
    $number = ksefNumberWithoutRow($db, (int) $id);
    if ($number !== '') {
        return $langs->transnoentitiesnoconv('KSEF_PickSourceNumberNotLinked', $o->ref, $number);
    }
    return $langs->transnoentitiesnoconv('KSEF_PickSourceUnsent', $o->ref, ksefEnvironmentLabel($env));
}

/**
 * @brief Customer source warnings
 * @param DoliDB $db
 * @param int $socid
 * @param int $limit Detailed checks
 * @return array id => text
 */
function ksefPickSourceWarnings($db, $socid, $limit = 200)
{
    global $langs;
    $out = array();
    if ((int) $socid <= 0 || ksefIsCustomerExcluded((int) $socid)) {
        return $out;
    }
    $P = MAIN_DB_PREFIX;
    $res = $db->query("SELECT f.rowid, f.ref, fe.ksef_number, (SELECT COUNT(*) FROM " . $P . "ksef_submissions AS s WHERE s.fk_facture = f.rowid) AS nsub"
        . " FROM " . $P . "facture AS f LEFT JOIN " . $P . "facture_extrafields AS fe ON fe.fk_object = f.rowid"
        . " WHERE f.fk_soc = " . (int) $socid . " AND f.entity IN (" . getEntity('invoice') . ") AND f.fk_statut IN (1, 2, 3) AND f.type IN (0, 1, 2, 3)"
        . " ORDER BY f.rowid DESC");
    $rows = array();
    while ($res && ($o = $db->fetch_object($res))) {
        $rows[(int) $o->rowid] = $o;
    }
    if (!$rows) {
        return $out;
    }
    $langs->load('ksef@ksef');
    $ok = ksefPickKindsOfRows($db, array('kor', 'korzal'), array_keys($rows));
    $env = ksefEnvironmentLabel(ksefGetEnvironment());
    foreach ($rows as $id => $o) {
        if (isset($ok[$id])) {
            continue;
        }
        if ((int) $o->nsub > 0 || trim((string) $o->ksef_number) !== '') {
            // full rule
            $w = $limit-- > 0 ? ksefPickSourceWarning($db, $id) : '';
        } else {
            $w = $langs->transnoentitiesnoconv('KSEF_PickSourceUnsent', $o->ref, $env);
        }
        if ($w !== '') {
            $out[$id] = $w;
        }
    }
    return $out;
}

/**
 * @brief Deposit pick warning
 * @param DoliDB $db
 * @param int $id Deposit
 * @return string Unescaped, '' if none
 */
function ksefPickDepositWarning($db, $id)
{
    global $langs;
    if (ksefPickEligible($db, 'roz', $id)) {
        return '';
    }
    $c = ksefDepositCitation($db, (int) $id);
    if ($c['citable'] || $c['ref'] === '') {
        return '';
    }
    $langs->load('ksef@ksef');
    $env = ksefEnvironmentLabel(ksefGetEnvironment());
    if ($c['reason'] === 'KSEF_DepositOtherEnvOnly') {
        return $c['env'] === 'PRODUCTION' ? $langs->transnoentitiesnoconv('KSEF_DepositProductionOnly', $c['ref'], $env)
            : $langs->transnoentitiesnoconv('KSEF_DepositOtherEnvFirst', $c['ref'], ksefEnvironmentLabel($c['env']), $env);
    }
    if ($c['reason'] === 'KSEF_DepositNumberNotLinked') {
        return $langs->transnoentitiesnoconv('KSEF_DepositLinkNumberFirst', $c['ref'], $c['number']);
    }
    return $langs->transnoentitiesnoconv('KSEF_PickDepositNotAccepted', $c['ref'], $env);
}
