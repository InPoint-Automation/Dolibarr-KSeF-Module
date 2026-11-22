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
 * \file    lib/ksef_writeoff.lib.php
 * \ingroup ksef
 * \brief   Close write-off
 */

require_once __DIR__ . '/ksef.lib.php';

define('KSEF_WRITEOFF_DOC', 'ksef_writeoff');

/**
 * @brief Write-off close codes
 * @param string $element Invoice element
 * @return string[]
 */
function ksefWoCodes($element)
{
    return $element === 'invoice_supplier' ? array('badsupplier', 'other') : array('badcustomer', 'bankcharge', 'other');
}

/**
 * @brief Write-off table present
 * @param DoliDB $db
 * @return bool
 */
function ksefWoTable($db)
{
    static $has = null;
    if ($has === null) {
        $has = (bool) $db->DDLListTables($db->database_name, MAIN_DB_PREFIX . 'ksef_writeoff');
    }
    return $has;
}

/**
 * @brief PLN unpaid rest
 * @param Facture|FactureFournisseur $invoice
 * @return float
 */
function ksefWoRemain($invoice)
{
    return (float) price2num($invoice->getRemainToPay(0), 'MT');
}

/**
 * @brief Picker applies
 * @param DoliDB $db
 * @param Facture|FactureFournisseur $invoice
 * @return bool
 */
function ksefWoApplies($db, $invoice)
{
    if (!isModEnabled('accounting') || !is_object($invoice) || empty($invoice->id) || !in_array($invoice->element ?? '', array('facture', 'invoice_supplier'), true)
        || !ksefWoTable($db)) {
        return false;
    }
    return (int) $invoice->status === 1 && empty($invoice->paye) && empty($invoice->paid) && ksefWoRemain($invoice) > 0;
}

/**
 * @brief Pending write-off
 * @param array|null $set Null to read
 * @return array
 */
function ksefWoPending($set = null)
{
    static $p = array();
    if ($set !== null) {
        $p = $set;
    }
    return $p;
}

/**
 * @brief Record pending write-off
 * @param DoliDB $db
 * @param User $user
 * @param Facture|FactureFournisseur $invoice Invoice being closed
 * @return string '' if done
 */
function ksefWoRecord($db, $user, $invoice)
{
    global $conf;
    $p = ksefWoPending();
    if (!$p || (int) $p['id'] !== (int) $invoice->id || $p['element'] !== $invoice->element) {
        return '';
    }
    ksefWoPending(array());
    $amount = ksefWoRemain($invoice);
    if ($amount <= 0) {
        return '';
    }
    $P = MAIN_DB_PREFIX;
    $db->query("DELETE FROM " . $P . "ksef_writeoff WHERE element = '" . $db->escape($invoice->element) . "' AND fk_invoice = " . (int) $invoice->id);
    $sql = "INSERT INTO " . $P . "ksef_writeoff (entity, element, fk_invoice, amount, account, close_code, date_close, fk_user) VALUES ("
        . (int) $conf->entity . ", '" . $db->escape($invoice->element) . "', " . (int) $invoice->id . ", " . price2num($amount, 'MT') . ", '" . $db->escape($p['account']) . "', '"
        . $db->escape((string) $p['code']) . "', '" . $db->idate(dol_now()) . "', " . (int) $user->id . ")";
    if (!$db->query($sql)) {
        return $db->lasterror();
    }
    ksefWoRememberAccount($db, $user, ($invoice->element === 'invoice_supplier' ? 'supplier' : 'customer'), $p['account']);
    dol_syslog('ksefWoRecord ' . $invoice->element . ' ' . (int) $invoice->id . ': ' . $amount . ' to ' . $p['account'], LOG_INFO);
    return '';
}

/**
 * @brief Invoice write-off
 * @param DoliDB $db
 * @param string $element
 * @param int $id Invoice id
 * @return object|null
 */
function ksefWoFetch($db, $element, $id)
{
    if (!ksefWoTable($db)) {
        return null;
    }
    $o = $db->getRow("SELECT rowid, amount, account, close_code, date_close FROM " . MAIN_DB_PREFIX . "ksef_writeoff WHERE element = '" . $db->escape($element) . "' AND fk_invoice = " . (int) $id);
    return $o ? $o : null;
}

/**
 * @brief Write-off in ledger
 * @param DoliDB $db
 * @param int $woId
 * @return bool
 */
function ksefWoInLedger($db, $woId)
{
    if (!$db->DDLListTables($db->database_name, MAIN_DB_PREFIX . 'accounting_bookkeeping')) {
        return false;
    }
    $o = $db->getRow("SELECT COUNT(*) AS n FROM " . MAIN_DB_PREFIX . "accounting_bookkeeping WHERE doc_type = '" . KSEF_WRITEOFF_DOC . "' AND fk_doc = " . (int) $woId);
    return $o && (int) $o->n > 0;
}

/**
 * @brief Reopen drops write-off
 * @param DoliDB $db
 * @param Facture|FactureFournisseur $invoice
 * @return string '' if allowed
 */
function ksefWoOnReopen($db, $invoice)
{
    global $langs;
    $wo = ksefWoFetch($db, $invoice->element, (int) $invoice->id);
    if (!$wo) {
        return '';
    }
    if (ksefWoInLedger($db, (int) $wo->rowid)) {
        $langs->loadLangs(array('ksef@ksef', 'accountancy'));
        return $langs->transnoentitiesnoconv('KSEF_WoReopenLedger', $invoice->ref, $langs->transnoentitiesnoconv('Bookkeeping'));
    }
    if (!$db->query("DELETE FROM " . MAIN_DB_PREFIX . "ksef_writeoff WHERE rowid = " . (int) $wo->rowid)) {
        return $db->lasterror();
    }
    return '';
}

/**
 * @brief Journal write-offs
 * @param DoliDB $db
 * @param User $user
 * @param AccountingJournal $journal Various journal
 * @param string $type 'view', 'bookkeeping' or 'csv'
 * @param int|null $start
 * @param int|null $end
 * @param string $inBook 'already' or 'notyet'
 * @return array
 */
function ksefWoJournalData($db, $user, $journal, $type, $start, $end, $inBook)
{
    global $conf, $langs;
    $out = array();
    if (!isModEnabled('accounting')) {
        return $out;
    }
    require_once DOL_DOCUMENT_ROOT . '/core/lib/accounting.lib.php';
    require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
    require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
    require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
    $langs->loadLangs(array('ksef@ksef', 'bills', 'companies'));
    $P = MAIN_DB_PREFIX;
    $ledger = $db->DDLListTables($db->database_name, $P . 'accounting_bookkeeping');
    if (!$ledger && $inBook === 'already') {
        return $out;
    }
    if (!ksefWoTable($db)) {
        return $out;
    }
    $sql = "SELECT u.* FROM (SELECT w.rowid, '" . KSEF_WRITEOFF_DOC . "' AS doc_type, w.element, w.fk_invoice, w.amount, w.account, w.date_close AS doc_date FROM "
        . $P . "ksef_writeoff AS w WHERE w.entity = " . (int) $conf->entity . ") AS u WHERE 1 = 1";
    if ($start && $end) {
        $sql .= " AND u.doc_date >= '" . $db->idate($start) . "' AND u.doc_date <= '" . $db->idate($end) . "'";
    }
    if (getDolGlobalString('ACCOUNTING_DATE_START_BINDING')) {
        $sql .= " AND u.doc_date >= '" . $db->idate(getDolGlobalInt('ACCOUNTING_DATE_START_BINDING')) . "'";
    }
    if ($ledger && in_array($inBook, array('already', 'notyet'), true)) {
        $sql .= " AND " . ($inBook === 'notyet' ? "NOT " : "") . "EXISTS (SELECT 1 FROM " . $P . "accounting_bookkeeping AS ab WHERE ab.doc_type = u.doc_type AND ab.fk_doc = u.rowid)";
    }
    $sql .= " ORDER BY u.doc_date, u.rowid";
    $res = $db->query($sql);
    $now = dol_now();
    $journalLabel = $langs->transnoentities($journal->label);
    while ($res && ($o = $db->fetch_object($res))) {
        $supplier = $o->element === 'invoice_supplier';
        $inv = $supplier ? new FactureFournisseur($db) : new Facture($db);
        if ($inv->fetch((int) $o->fk_invoice) <= 0) {
            continue;
        }
        $soc = new Societe($db);
        $soc->fetch((int) $inv->socid);
        if ($supplier) {
            $general = !empty($soc->accountancy_code_supplier_general) ? (string) $soc->accountancy_code_supplier_general : getDolGlobalString('ACCOUNTING_ACCOUNT_SUPPLIER');
            $sub = (string) $soc->code_compta_fournisseur;
            $code = (string) $soc->code_fournisseur;
        } else {
            $general = !empty($soc->accountancy_code_customer_general) ? (string) $soc->accountancy_code_customer_general : getDolGlobalString('ACCOUNTING_ACCOUNT_CUSTOMER');
            $sub = (string) $soc->code_compta_client;
            $code = (string) $soc->code_client;
        }
        $amount = (float) price2num($o->amount, 'MT');
        $date = $db->jdate($o->doc_date);
        $label = dol_trunc($soc->name, 16, 'right', 'UTF-8', 1) . ' - ' . $inv->ref . ' - ' . $langs->transnoentitiesnoconv('KSEF_WoJournalLabel');
        // Dr/Cr by side
        $legs = array(
            array('account' => (string) $o->account, 'sub' => '', 'sublabel' => '', 'debit' => !$supplier),
            array('account' => $general, 'sub' => $sub, 'sublabel' => (string) $soc->name, 'debit' => $supplier),
        );
        if ($supplier) {
            $legs = array_reverse($legs);
        }
        $lines = array();
        foreach ($legs as $leg) {
            $info = $journal->getAccountingAccountInfos($leg['account']);
            if ($type === 'view') {
                $lines[] = array('date' => dol_print_date($date, 'day'), 'piece' => $inv->getNomUrl(1), 'account_accounting' => length_accountg($leg['account']),
                    'subledger_account' => $leg['sub'] !== '' ? length_accounta($leg['sub']) : '', 'label_operation' => $label,
                    'debit' => $leg['debit'] ? price($amount) : '', 'credit' => $leg['debit'] ? '' : price($amount));
            } elseif ($type === 'bookkeeping') {
                $lines[] = array('doc_date' => $date, 'date_lim_reglement' => '', 'doc_ref' => (string) $inv->ref, 'date_creation' => $now, 'doc_type' => (string) $o->doc_type,
                    'fk_doc' => (int) $o->rowid, 'fk_docdet' => (int) $inv->id, 'thirdparty_code' => $code, 'subledger_account' => $leg['sub'], 'subledger_label' => $leg['sublabel'],
                    'numero_compte' => $leg['account'], 'label_compte' => $info['label'], 'label_operation' => $label, 'montant' => $amount, 'sens' => $leg['debit'] ? 'D' : 'C',
                    'debit' => $leg['debit'] ? $amount : 0, 'credit' => $leg['debit'] ? 0 : $amount, 'code_journal' => $journal->code, 'journal_label' => $journalLabel,
                    'piece_num' => '', 'import_key' => '', 'fk_user_author' => $user->id, 'entity' => $conf->entity);
            } else {
                $lines[] = array($date, (string) $inv->ref, $info['code_formatted_1'], $label, $leg['debit'] ? price($amount) : '', $leg['debit'] ? '' : price($amount));
            }
        }
        $out[] = array('ref' => dol_trunc($inv->ref, 16, 'right', 'UTF-8', 1), 'error' => '', 'blocks' => array($lines));
    }
    return $out;
}

/**
 * @brief Active chart accounts
 * @param DoliDB $db
 * @return array Number => label
 */
function ksefWoChartAccounts($db)
{
    global $conf;
    $out = array();
    if (!isModEnabled('accounting') || getDolGlobalInt('CHARTOFACCOUNTS') <= 0) {
        return $out;
    }
    $res = $db->query("SELECT a.account_number, a.label FROM " . MAIN_DB_PREFIX . "accounting_account AS a INNER JOIN " . MAIN_DB_PREFIX . "accounting_system AS s"
        . " ON s.pcg_version = a.fk_pcg_version WHERE s.rowid = " . getDolGlobalInt('CHARTOFACCOUNTS') . " AND a.active = 1 AND a.entity = " . ((int) $conf->entity)
        . " ORDER BY a.account_number");
    while ($res && ($o = $db->fetch_object($res))) {
        $out[(string) $o->account_number] = (string) $o->label;
    }
    return $out;
}

/**
 * @brief Last write-off account
 * @param User $user
 * @param string $side 'customer' or 'supplier'
 * @return string
 */
function ksefWoLastAccount($user, $side)
{
    $k = 'KSEF_WO_ACC_' . strtoupper($side);
    return (is_object($user) && isset($user->conf->$k)) ? (string) $user->conf->$k : '';
}

/**
 * @brief Remember write-off account
 * @param DoliDB $db
 * @param User $user
 * @param string $side 'customer' or 'supplier'
 * @param string $account
 * @return void
 */
function ksefWoRememberAccount($db, $user, $side, $account)
{
    global $conf;
    if (!is_object($user) || empty($user->id) || $account === '' || ksefWoLastAccount($user, $side) === $account) {
        return;
    }
    $k = 'KSEF_WO_ACC_' . strtoupper($side);
    $P = MAIN_DB_PREFIX;
    $db->query("DELETE FROM " . $P . "user_param WHERE fk_user = " . (int) $user->id . " AND entity = " . (int) $conf->entity . " AND param = '" . $db->escape($k) . "'");
    if ($db->query("INSERT INTO " . $P . "user_param (fk_user, entity, param, value) VALUES (" . (int) $user->id . ", " . (int) $conf->entity . ", '" . $db->escape($k) . "', '" . $db->escape($account) . "')")) {
        if (!is_object($user->conf)) {
            $user->conf = new stdClass();
        }
        $user->conf->$k = $account;
    }
}

/**
 * @brief Format plain amount
 * @param float $amount
 * @param string $cur Currency code
 * @return string
 */
function ksefWoMoney($amount, $cur = '')
{
    global $langs;
    $s = html_entity_decode(price($amount, 0, $langs, 1, -1, 2, $cur), ENT_QUOTES, 'UTF-8');
    return trim(str_replace(array("\xc2\xa0", "\xe2\x80\xaf"), ' ', $s));
}

/**
 * @brief Close dialog scripts
 * @param DoliDB $db
 * @param User $user
 * @param Facture|FactureFournisseur $invoice
 * @return string '' if n/a
 */
function ksefCloseDialogJs($db, $user, $invoice)
{
    global $conf, $langs;
    if (empty($conf->use_javascript_ajax) || !is_object($invoice) || empty($invoice->id)) {
        return '';
    }
    $langs->loadLangs(array('ksef@ksef', 'bills'));
    $supplier = $invoice->element === 'invoice_supplier';
    $plain = $supplier || ksefIsCustomerExcluded($invoice->socid);
    $url = $plain ? DOL_URL_ROOT . ($supplier ? '/fourn' : '/compta') . '/facture/card.php?socid=' . (int) $invoice->socid . '&fac_avoir=' . (int) $invoice->id . '&action=create&type=2' : ksefCorrectionCreateUrl($invoice);
    $cfg = array('url' => $url, 'L' => array('NoVat' => $langs->transnoentitiesnoconv('KSEF_CloseNoVat'), 'Kor' => $langs->transnoentitiesnoconv('KSEF_CloseDiscountKor'),
        'KorLink' => $langs->transnoentitiesnoconv($plain ? 'CreateCreditNote' : 'KSEF_CreateCorrection'), 'KorLater' => $plain ? '' : $langs->transnoentitiesnoconv('KSEF_CloseKorLater', $langs->transnoentitiesnoconv('KSEF_CreateCorrection'))));
    $flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
    return '<script src="' . dol_buildpath('/ksef/js/ksef_close_dialog.js', 1) . '?v=' . (int) @filemtime(dirname(__DIR__) . '/js/ksef_close_dialog.js') . '"></script>'
        . '<script>jQuery(function() { if (window.ksefCloseDialog) { window.ksefCloseDialog(' . json_encode($cfg, $flags) . '); } });</script>' . ksefWoDialogJs($db, $user, $invoice);
}

/**
 * @brief Close dialog picker
 * @param DoliDB $db
 * @param User $user
 * @param Facture|FactureFournisseur $invoice
 * @return string '' if n/a
 */
function ksefWoDialogJs($db, $user, $invoice)
{
    global $conf, $langs;
    if (empty($conf->use_javascript_ajax) || !ksefWoApplies($db, $invoice)) {
        return '';
    }
    $chart = array();
    foreach (ksefWoChartAccounts($db) as $n => $l) {
        $chart[] = array('n' => (string) $n, 'l' => html_entity_decode((string) $l, ENT_QUOTES, 'UTF-8'));
    }
    if (!$chart) {
        return '';
    }
    $langs->loadLangs(array('ksef@ksef', 'accountancy', 'main'));
    $supplier = $invoice->element === 'invoice_supplier';
    $remain = ksefWoRemain($invoice);
    $cfg = array(
        'codes' => ksefWoCodes($invoice->element),
        'chart' => $chart,
        'last' => ksefWoLastAccount($user, $supplier ? 'supplier' : 'customer'),
        'url' => $_SERVER['PHP_SELF'] . '?facid=' . (int) $invoice->id . ($supplier ? '' : '&resteapayer=' . (float) $remain),
        'token' => newToken(),
        'L' => array(
            'Account' => $langs->transnoentitiesnoconv('AccountAccounting'),
            'Help' => $langs->transnoentitiesnoconv($supplier ? 'KSEF_WoHelpSupplier' : 'KSEF_WoHelp', ksefWoMoney($remain, (string) $conf->currency)),
            'Select' => $langs->transnoentitiesnoconv('KSEF_WoJsSelect'),
            'Required' => $langs->transnoentitiesnoconv('KSEF_WoJsAccount'),
        ),
    );
    $flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
    return '<script>jQuery(function() { if (window.ksefWoDialog) { window.ksefWoDialog(' . json_encode($cfg, $flags) . '); } });</script>';
}

/**
 * @brief Check posted write-off
 * @param DoliDB $db
 * @param Facture|FactureFournisseur $invoice
 * @return string '' if valid
 */
function ksefWoFromPost($db, $invoice)
{
    global $langs;
    ksefWoPending(array());
    if (GETPOST('close_code', 'aZ09') === 'discount_vat') {
        $langs->loadLangs(array('ksef@ksef', 'bills'));
        return $langs->transnoentitiesnoconv('KSEF_CloseDiscountRefused', $langs->transnoentitiesnoconv('CreateCreditNote'));
    }
    if (!GETPOST('ksef_wo', 'int') || !ksefWoApplies($db, $invoice) || !in_array(GETPOST('close_code', 'aZ09'), ksefWoCodes($invoice->element), true)) {
        return '';
    }
    $langs->loadLangs(array('ksef@ksef', 'accountancy'));
    $account = trim(GETPOST('ksef_wo_account', 'alphanohtml'));
    if ($account === '' || $account === '-1') {
        return $langs->transnoentitiesnoconv('ErrorFieldRequired', $langs->transnoentitiesnoconv('AccountAccounting'));
    }
    if (!isset(ksefWoChartAccounts($db)[$account])) {
        return $langs->transnoentitiesnoconv('KSEF_WoErrAccountUnknown', $invoice->ref, $account);
    }
    ksefWoPending(array('element' => $invoice->element, 'id' => (int) $invoice->id, 'account' => $account, 'code' => GETPOST('close_code', 'aZ09')));
    return '';
}
