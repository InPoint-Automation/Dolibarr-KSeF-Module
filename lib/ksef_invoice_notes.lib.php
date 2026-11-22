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
 * \file    ksef/lib/ksef_invoice_notes.lib.php
 * \ingroup ksef
 * \brief   Invoice card notes
 */

dol_include_once('/ksef/lib/ksef.lib.php');
dol_include_once('/ksef/lib/ksef_dbcheck.lib.php');

if (!defined('KSEF_NOTES_INDEX_SCAN')) {
    define('KSEF_NOTES_INDEX_SCAN', 20);
}

/**
 * @brief Format money
 * @param float $v
 * @param string $cur Currency code
 * @return string
 */
function ksefNoteMoney($v, $cur = '')
{
    global $conf, $langs;
    return price($v, 0, $langs, 1, -1, 2, $cur !== '' ? $cur : $conf->currency);
}

/**
 * @brief Check fix right
 * @param User $user
 * @param string $element
 * @return bool
 */
function ksefNoteUserCanFix($user, $element)
{
    if ($element === 'invoice_supplier') {
        return $user->hasRight('fournisseur', 'facture', 'creer') || $user->hasRight('supplier_invoice', 'creer');
    }
    return (bool) $user->hasRight('facture', 'creer');
}

/**
 * @brief Corrected deposit ids
 * @param DoliDB $db
 * @param Facture|FactureFournisseur $object
 * @return array
 */
function ksefNoteDepositIds($db, $object)
{
    $el = $object->element;
    $k = ksefInvoiceKind($el);
    $P = MAIN_DB_PREFIX;
    $ids = array();
    if ((int) $object->type === 3) {
        $ids[] = (int) $object->id;
    } elseif ((int) $object->type === 0) {
        if ($el === 'facture') {
            foreach ((array) ksefDepositContextOnce($db, $object)['deposits'] as $d) {
                $ids[] = (int) $d['id'];
            }
        } else {
            $res = $db->query("SELECT DISTINCT re." . $k['col_source'] . " AS src FROM " . $P . "societe_remise_except AS re"
                . " LEFT JOIN " . $P . $k['line_table'] . " AS l ON l.rowid = re." . $k['col_line']
                . " WHERE re.description = '" . KSEF_DEPOSIT_CREDIT_DESCRIPTION . "' AND (re." . $k['col'] . " = " . (int) $object->id . " OR l." . $k['line_fk'] . " = " . (int) $object->id . ")");
            while ($res && ($o = $db->fetch_object($res))) {
                $ids[] = (int) $o->src;
            }
        }
    }
    $ids = array_values(array_unique(array_filter($ids)));
    if (!$ids) {
        return array();
    }
    $T = $P . $k['table'];
    $out = array();
    $res = $db->query("SELECT d.rowid FROM " . $T . " AS d WHERE d.rowid IN (" . implode(',', $ids) . ") AND d.type = 3 AND d.fk_statut > 0"
        . " AND EXISTS (SELECT 1 FROM " . $T . " AS c WHERE c.fk_facture_source = d.rowid AND c.type IN (1, 2) AND c.fk_statut > 0) ORDER BY d.rowid");
    while ($res && ($o = $db->fetch_object($res))) {
        $out[] = (int) $o->rowid;
    }
    return $out;
}

/**
 * @brief Deposit credit notes
 * @param DoliDB $db
 * @param Facture|FactureFournisseur $object Invoice shown
 * @param int $depId Deposit id
 * @return array
 */
function ksefNoteDeposit($db, $object, $depId)
{
    global $langs, $user;
    $el = $object->element;
    $supplier = $el === 'invoice_supplier';
    $out = array();
    $p = ksefDbCheckDepositPlan($db, $user, $depId, $el);
    $refs = ksefDbCheckInvoiceRefs($db, array($depId), $el);
    $depRef = isset($refs[$depId]) ? $refs[$depId]['ref'] : (string) $depId;
    $cur = $p['currency'];
    $title = $langs->transnoentitiesnoconv('KSEF_NoteDepositStale', $depRef, ksefNoteMoney($p['credit'], $cur), ksefNoteMoney($p['target'], $cur));
    if ($p['safe']) {
        $rows = array(array($langs->transnoentitiesnoconv('KSEF_NoteRowDepositCredit', $depRef), ksefNoteMoney($p['credit'], $cur), ksefNoteMoney($p['target'], $cur)));
        $byInv = array();
        foreach ($p['plan']['plan'] as $r) {
            if ((int) $r['inv'] > 0) {
                $byInv[(int) $r['inv']] = array(($byInv[(int) $r['inv']][0] ?? 0) + $r['from'], ($byInv[(int) $r['inv']][1] ?? 0) + $r['to']);
            }
        }
        foreach (ksefDbCheckInvoiceRefs($db, array_keys($byInv), $el) as $iid => $inv) {
            $rows[] = array($langs->transnoentitiesnoconv('KSEF_NoteRowDeduction', $inv['ref']), ksefNoteMoney($byInv[$iid][0], $cur), ksefNoteMoney($byInv[$iid][1], $cur));
        }
        $out[] = array('code' => 'deposit_stale', 'key' => 'deposit_stale:' . $depId, 'tone' => 'warning', 'icon' => 'fix', 'title' => $title,
            'text' => $langs->transnoentitiesnoconv('KSEF_NoteDepositFix'), 'fix' => array('op' => 'deposit_restate', 'element' => $el, 'deposit' => $depId),
            'confirm' => $langs->transnoentitiesnoconv('KSEF_NoteDepositConfirm'), 'rows' => $rows);
    } elseif ($p['changes'] > 0) {
        $out[] = array('code' => 'deposit_held', 'key' => 'deposit_held:' . $depId, 'tone' => 'warning', 'icon' => 'lock', 'title' => $title,
            'text' => $langs->transnoentitiesnoconv('KSEF_NoteDepositHeld', implode(', ', $p['held'])), 'fix' => null);
    }
    if ($p['locked']) {
        $here = isset($p['locked'][(int) $object->id]);
        $others = $p['locked'];
        unset($others[(int) $object->id]);
        $out[] = array('code' => 'deposit_sent', 'key' => 'deposit_sent:' . $depId, 'tone' => $supplier ? 'info' : 'warning', 'icon' => 'hand',
            'title' => $langs->transnoentitiesnoconv('KSEF_NoteDepositSent', implode(', ', $p['locked']), $depRef),
            'text' => $langs->transnoentitiesnoconv($supplier ? 'KSEF_NoteDepositSentSupplier' : ($here ? 'KSEF_NoteDepositSentFixHere' : 'KSEF_NoteDepositSentFix')), 'fix' => null,
            'links' => $supplier ? array() : array_map(function ($id) {
                return ksefDbCheckInvoiceUrl($id, 'facture');
            }, array_flip($others)));
    }
    return $out;
}

/**
 * @brief Foreign PLN note
 * @param array $e ksefDbCheckForeignPln() entry
 * @return array
 */
function ksefNoteForeignPln($e)
{
    global $langs;
    $pl = $e['pln'];
    $parts = array();
    $rows = array();
    foreach (array('ht' => 'KSEF_NoteNet', 'tva' => 'KSEF_NoteVat') as $c => $label) {
        if (abs($pl['new_' . $c] - $pl[$c]) >= 0.005) {
            $parts[] = $langs->transnoentitiesnoconv('KSEF_NoteAmountShould', $langs->transnoentitiesnoconv($label), ksefNoteMoney($pl[$c]), ksefNoteMoney($pl['new_' . $c]));
            $rows[] = array($langs->transnoentitiesnoconv($label . 'Pln'), ksefNoteMoney($pl[$c]), ksefNoteMoney($pl['new_' . $c]));
        }
    }
    $round = abs($pl['new_mtva'] - $pl['mtva']) >= 0.005;
    if ($round) {
        $rows[] = array($langs->transnoentitiesnoconv('KSEF_NoteVatIn', $e['currency']), ksefNoteMoney($pl['mtva'], $e['currency']), ksefNoteMoney($pl['new_mtva'], $e['currency']));
    }
    $diff = abs($pl['new_tva'] - $pl['tva']) >= 0.005 ? abs($pl['new_tva'] - $pl['tva']) : abs($pl['new_ht'] - $pl['ht']);
    $note = array('code' => 'foreign_pln', 'key' => 'foreign_pln:' . (int) $e['id'], 'tone' => 'warning', 'fix' => null,
        'title' => $langs->transnoentitiesnoconv('KSEF_NoteForeignPln', price($e['kurs'], 0, $langs, 0, 2, -1), $e['currency'], implode('; ', $parts)));
    if ($e['state'] === 'repairable') {
        $note['icon'] = 'fix';
        $note['text'] = $langs->transnoentitiesnoconv($round ? 'KSEF_NoteForeignPlnFixRound' : 'KSEF_NoteForeignPlnFix', $e['currency']);
        $note['fix'] = $e['repair'];
        $note['confirm'] = $langs->transnoentitiesnoconv('KSEF_NoteForeignPlnConfirm');
        $note['rows'] = $rows;
    } elseif ($e['state'] === 'sent') {
        $note['icon'] = 'lock';
        $note['text'] = $langs->transnoentitiesnoconv('KSEF_NoteForeignPlnSent', ksefNoteMoney($diff));
    } else {
        $note['icon'] = 'lock';
        $note['text'] = $langs->transnoentitiesnoconv('KSEF_NoteForeignPlnLocked', $e['locked_by'] !== '' ? $e['locked_by'] : '-', ksefNoteMoney($diff));
    }
    return $note;
}

/**
 * @brief Line VAT note
 * @param DoliDB $db
 * @param Facture $object
 * @return array|null
 */
function ksefNoteLineVat($db, $object)
{
    global $langs;
    $id = (int) $object->id;
    if (ksefIsCustomerExcluded($object->socid) || ksefInvoiceSentToKsef($db, $id)) {
        return null;
    }
    $off = ksefLineVatOff($db, $id);
    if (!$off) {
        return null;
    }
    $steps = $rows = array();
    foreach ($off as $d) {
        $steps[] = ksefLineVatItem($d);
        $rows[] = array($langs->transnoentitiesnoconv('KSEF_NoteRowLineVat', $d['pos'], $d['currency']), ksefNoteMoney($d['vat'], $d['currency']), ksefNoteMoney($d['want'], $d['currency']));
        $rows[] = array($langs->transnoentitiesnoconv('KSEF_NoteRowLineGross', $d['pos'], $d['currency']), ksefNoteMoney($d['net'] + $d['vat'], $d['currency']), ksefNoteMoney($d['ttc'], $d['currency']));
    }
    $note = array('code' => 'line_vat', 'key' => 'line_vat:' . $id, 'tone' => 'warning', 'icon' => 'fix',
        'title' => $langs->transnoentitiesnoconv('KSEF_NoteLineVat'), 'steps' => $steps,
        'text' => $langs->transnoentitiesnoconv('KSEF_NoteLineVatWhy'), 'fix' => array('op' => 'line_vat', 'id' => $id, 'element' => 'facture'),
        'confirm' => $langs->transnoentitiesnoconv('KSEF_NoteLineVatConfirm'), 'rows' => $rows);
    if ((int) $object->status === 1 && ksefInvoiceInAccounting($db, $id)) {
        $note['icon'] = 'lock';
        $note['fix'] = null;
        $note['text'] = $langs->transnoentitiesnoconv('KSEF_NoteLineVatBooked');
    }
    return $note;
}

/**
 * @brief Import rate note
 * @param array $e ksefDbCheckImportRates() entry
 * @return array
 */
function ksefNoteImportRate($e)
{
    global $langs;
    $rate = function ($v) use ($langs) {
        return price($v, 0, $langs, 0, -1, 4);
    };
    $note = array('code' => 'import_rate', 'key' => 'import_rate:' . (int) $e['id'], 'tone' => 'warning', 'fix' => null,
        'title' => $langs->transnoentitiesnoconv('KSEF_NoteImportRate', $rate($e['rate_now']), $e['currency'], $rate($e['rate']), $e['currency']));
    if ($e['state'] === 'repairable') {
        $note['icon'] = 'fix';
        $note['text'] = $langs->transnoentitiesnoconv('KSEF_NoteImportRateFix', $e['currency']) . ($e['others'] ? ' ' . $langs->transnoentitiesnoconv('KSEF_NoteImportRateAlso', implode(', ', $e['others'])) : '');
        $note['fix'] = $e['repair'];
        $note['confirm'] = $langs->transnoentitiesnoconv('KSEF_NoteImportRateConfirm');
        $note['rows'] = array(array($langs->transnoentitiesnoconv('KSEF_NoteRowRate', $e['currency']), $rate($e['rate_now']), $rate($e['rate'])),
            array($langs->transnoentitiesnoconv('KSEF_NoteVatPln'), ksefNoteMoney($e['vat_now']), ksefNoteMoney($e['vat_ksef'])));
    } else {
        $note['icon'] = 'lock';
        $note['text'] = $langs->transnoentitiesnoconv('KSEF_NoteImportRateLocked', ksefNoteMoney($e['vat_ksef']), ksefNoteMoney($e['vat_now']));
    }
    return $note;
}

/**
 * @brief Invoice findings
 * @param DoliDB $db
 * @param Facture|FactureFournisseur $object
 * @return array
 */
function ksefInvoiceNotes($db, $object)
{
    global $conf, $langs;
    $out = array();
    if (!is_object($object) || empty($object->id) || !in_array($object->element ?? '', array('facture', 'invoice_supplier'), true)) {
        return $out;
    }
    $langs->loadLangs(array('bills', 'banks', 'ksef@ksef'));
    $el = $object->element;
    $id = (int) $object->id;
    $status = (int) $object->status;
    $cur = (string) $object->multicurrency_code;
    $foreign = $cur !== '' && $cur !== $conf->currency;

    if ($el === 'facture' && $status === 0 && !empty($object->array_options['options_ksef_sale_date']) && ksefDbCheckStaleSaleDates($db, $id, true)) {
        $out[] = array('code' => 'sale_date', 'key' => 'sale_date:' . $id, 'tone' => 'warning', 'icon' => 'fix',
            'title' => $langs->transnoentitiesnoconv('KSEF_NoteSaleDate', dol_print_date($object->array_options['options_ksef_sale_date'], 'day')),
            'text' => $langs->transnoentitiesnoconv('KSEF_NoteSaleDateFix'), 'fix' => array('op' => 'clear_sale_dates', 'ids' => array($id)),
            'confirm' => $langs->transnoentitiesnoconv('KSEF_NoteSaleDateConfirm'),
            'rows' => array(array($langs->transnoentitiesnoconv('KSEF_NoteRowSaleDate'), dol_print_date($object->array_options['options_ksef_sale_date'], 'day'),
                $langs->transnoentitiesnoconv('KSEF_NoteRowSaleDateEmpty'))));
    }

    if ($el === 'facture' && $status >= 0 && ksefCorrectionMissingSource($object) && !ksefIsCustomerExcluded($object->socid) && !ksefSubmissionState($db, $id)['locked']) {
        $out[] = array('code' => 'correction_no_source', 'key' => 'correction_no_source:' . $id, 'tone' => 'warning', 'icon' => 'hand', 'fix' => null,
            'title' => $langs->transnoentitiesnoconv('KSEF_NoteCorrectionNoSource'), 'text' => $langs->transnoentitiesnoconv('KSEF_CorrectionNoSource'));
    }

    if ($el === 'facture' && $status >= 0 && $status <= 1 && !isModEnabled('multicurrency') && ksefInvoiceCurrencyStray($object) !== '' && !ksefInvoiceSentToKsef($db, $id)) {
        $stray = ksefInvoiceCurrencyStray($object);
        $note = array('code' => 'currency_stray', 'key' => 'currency_stray:' . $id, 'tone' => 'warning', 'icon' => 'lock', 'fix' => null,
            'title' => $langs->transnoentitiesnoconv('KSEF_NoteCurrencyStray', $stray, $conf->currency), 'text' => $langs->transnoentitiesnoconv('KSEF_NoteCurrencyStrayValidated'));
        if ($status === 0 && abs((float) $object->multicurrency_tx - 1) < 0.000001) {
            $note['icon'] = 'fix';
            $note['text'] = $langs->transnoentitiesnoconv('KSEF_NoteCurrencyStrayFix', $conf->currency);
            $note['fix'] = array('op' => 'draft_currency', 'id' => $id, 'element' => 'facture');
            $note['confirm'] = $langs->transnoentitiesnoconv('KSEF_NoteCurrencyStrayConfirm', $conf->currency);
            $note['rows'] = array(array($langs->transnoentitiesnoconv('Currency'), $stray, $conf->currency));
        }
        $out[] = $note;
    }

    if ($el === 'facture' && ($status === 0 || $status === 1)) {
        $lineVat = ksefNoteLineVat($db, $object);
        if ($lineVat) {
            $out[] = $lineVat;
        }
    }

    $otherEnv = ($el === 'facture' && ($status === 0 || $status === 1)) ? ksefCorrectionOtherEnvRefusal($db, $object) : '';
    if ($otherEnv !== '' && !ksefSubmissionState($db, $id)['locked']) {
        $out[] = array('code' => 'correction_other_env', 'key' => 'correction_other_env:' . $id, 'tone' => 'warning', 'icon' => 'hand', 'fix' => null,
            'title' => $langs->transnoentitiesnoconv('KSEF_NoteCorrectionOtherEnv'), 'text' => $otherEnv,
            'links' => array($langs->transnoentitiesnoconv('KSEF_NoteOpenOriginal') => DOL_URL_ROOT . '/compta/facture/card.php?facid=' . (int) $object->fk_facture_source));
    }

    if (((int) $object->type === 0 && $status >= 0) || ((int) $object->type === 3 && $status > 0)) {
        foreach (ksefNoteDepositIds($db, $object) as $depId) {
            $out = array_merge($out, ksefNoteDeposit($db, $object, $depId));
        }
    }

    if ($el === 'facture' && $status >= 0 && ksefExemptBasisMissing($db, $object)) {
        $st = ksefSubmissionState($db, $id);
        if (empty($st['is_accepted']) && empty($st['is_offline'])) {
            $out[] = array('code' => 'exempt_basis', 'key' => 'exempt_basis:' . $id, 'tone' => 'warning', 'icon' => 'hand', 'fix' => null,
                'title' => $langs->transnoentitiesnoconv('KSEF_NoteExemptBasis'), 'text' => $langs->transnoentitiesnoconv('KSEF_ExemptNoBasis'),
                'links' => !empty($GLOBALS['user']->admin) ? array($langs->transnoentitiesnoconv('KSEF_Tab_Fields') => dol_buildpath('/ksef/admin/setup_fields.php', 1) . '#KSEF_ZWOLNIENIE_TYPE') : array());
        }
    }

    $rateNotes = ($el === 'invoice_supplier' && $foreign) ? ksefDbCheckImportRates($db, $id) : array();
    foreach (ksefDbCheckImportTotals($db, $el, $id, $rateNotes) as $e) {
        $out[] = array('code' => 'import_totals', 'key' => 'import_totals:' . $id, 'tone' => 'warning',
            'icon' => $e['state'] === 'repairable' ? 'fix' : ($e['state'] === 'far' ? 'hand' : 'lock'),
            'title' => $e['problem'], 'text' => $e['fix'], 'fix' => $e['repair'], 'rows' => $e['rows'],
            'confirm' => $langs->transnoentitiesnoconv('KSEF_NoteImportTotalsConfirm'));
    }

    if (!$foreign) {
        return $out;
    }
    $acc = ($el === 'facture' && $status >= 0 && $status <= 1 && (int) $object->fk_account > 0)
        ? $db->getRow("SELECT ref, label, currency_code FROM " . MAIN_DB_PREFIX . "bank_account WHERE rowid = " . (int) $object->fk_account) : null;
    if ($acc && (string) $acc->currency_code !== '' && (string) $acc->currency_code !== $cur && !ksefInvoiceSentToKsef($db, $id)) {
        $langs->loadLangs(array('banks', 'bills'));
        $field = $langs->transnoentitiesnoconv(ksefDolibarrAtLeast('23.0') ? 'DefaultBankAccount' : 'BankAccount');
        $match = ksefBankAccountFor($db, 0, $cur);
        $matchRow = $match > 0 ? $db->getRow("SELECT ref FROM " . MAIN_DB_PREFIX . "bank_account WHERE rowid = " . $match) : null;
        if ($matchRow) {
            $out[] = array('code' => 'account_currency', 'key' => 'account_currency:' . $id, 'tone' => 'info', 'icon' => 'hand', 'fix' => null,
                'title' => $langs->transnoentitiesnoconv('KSEF_NoteAccountCurrency', (string) $acc->ref, (string) $acc->currency_code, $cur),
                'text' => $langs->transnoentitiesnoconv('KSEF_NoteAccountCurrencyHow', (string) $acc->currency_code, $cur, $field, (string) $matchRow->ref));
        }
    }
    if ($el === 'facture') {
        if ($status < 1) {
            return $out;
        }
        foreach (ksefDbCheckForeignPln($db, $id) as $e) {
            $out[] = ksefNoteForeignPln($e);
        }
    } else {
        foreach ($rateNotes as $e) {
            if ((int) $e['id'] === $id || in_array($id, $e['ids'], true)) {
                $out[] = ksefNoteImportRate($e);
            }
        }
    }
    if ($status < 1 || !isModEnabled('multicurrency')) {
        return $out;
    }
    $owed = function () use ($object, $cur) {
        return ksefNoteMoney((float) price2num($object->getRemainToPay(1), 'MT'), $cur);
    };
    $reopen = $langs->transnoentitiesnoconv('ReOpen');
    $pay = $langs->transnoentitiesnoconv('DoPayment');
    $closeShort = $langs->transnoentitiesnoconv('ClassifyPaidPartially');
    $zero = $langs->transnoentitiesnoconv('RemainderToPay') . ' ' . price(0, 0, $langs, 1, -1, 2);
    $sup = $el === 'invoice_supplier' ? 'Supplier' : '';
    foreach (ksefDbCheckPaymentColumn($db, $el, $id) as $e) {
        $out[] = array('code' => 'payment_column', 'key' => 'payment_column:' . $e['payment'], 'tone' => 'warning', 'icon' => 'hand', 'fix' => null,
            'title' => $langs->transnoentitiesnoconv('KSEF_NotePayColumn', $e['ref'], $e['typed'], $e['account'], $e['account_cur']),
            'text' => $langs->transnoentitiesnoconv('KSEF_NotePayColumnWhy', ksefNoteMoney(abs($e['bank_amount']), $e['account_cur']), $e['account_cur']),
            'steps' => array($langs->transnoentitiesnoconv('KSEF_NotePayColumnOk', $langs->transnoentitiesnoconv('Conciliate')),
                $status === 2 ? $langs->transnoentitiesnoconv('KSEF_NotePayColumnRedoClosed', $reopen, $e['account_cur']) : $langs->transnoentitiesnoconv('KSEF_NotePayColumnRedo', $e['account_cur'])),
            'links' => array($e['ref'] => DOL_URL_ROOT . ($el === 'invoice_supplier' ? '/fourn/paiement/card.php?id=' : '/compta/paiement/card.php?id=') . $e['payment']));
    }
    if ($status === 2 && !empty($object->paye)) {
        foreach (ksefDbCheckPaidWhileOwed($db, $el, $id) as $e) {
            $before = $e['severity'] === 'info';
            $note = array('code' => 'paid_owed', 'key' => 'paid_owed:' . $id, 'tone' => $before ? 'info' : 'warning', 'icon' => 'hand', 'fix' => null,
                'title' => $langs->transnoentitiesnoconv('KSEF_NotePaidOwed', $cur, $owed()),
                'text' => ($before ? $langs->transnoentitiesnoconv('KSEF_NotePaidOwedBefore') . ' ' : '') . $langs->transnoentitiesnoconv('KSEF_NotePaidOwedHow', $cur, $zero) . ' ');
            $note['text'] .= $langs->transnoentitiesnoconv('KSEF_NotePaidOwedWhat');
            $note['steps'] = array($langs->transnoentitiesnoconv('KSEF_NotePaidOwedPayReopen' . $sup, $reopen, $cur, $pay),
                ($sup ? $langs->transnoentitiesnoconv('KSEF_NotePaidOwedCloseSupplier', $reopen, $closeShort, $langs->transnoentitiesnoconv('Other'))
                    : $langs->transnoentitiesnoconv('KSEF_NotePaidOwedClose', $reopen, $closeShort, $langs->transnoentitiesnoconv('ConfirmClassifyPaidPartiallyReasonBankCharge'),
                    $langs->transnoentitiesnoconv('Other'))) . (isModEnabled('accounting') && (float) price2num($object->getRemainToPay(0), 'MT') > 0 ? ' ' . $langs->transnoentitiesnoconv('KSEF_NotePaidOwedCloseAccount') : ''),
                $langs->transnoentitiesnoconv('KSEF_NotePaidOwedKeep' . $sup, $cur),
                $langs->transnoentitiesnoconv('KSEF_NotePaidOwedWrongReopen', $reopen, $langs->transnoentitiesnoconv('Delete')));
            $out[] = $note;
        }
    }
    $k = ksefInvoiceKind($el);
    foreach (ksefDbCheckExcessWhileOwed($db, $el, $id) as $e) {
        $o = $db->getRow("SELECT SUM(amount_ttc) AS amount FROM " . MAIN_DB_PREFIX . "societe_remise_except"
            . " WHERE " . $k['col_source'] . " = " . $id . " AND description LIKE '" . $db->escape($k['excess']) . "%'");
        $usedOn = array();
        $res = $db->query("SELECT DISTINCT " . $k['col'] . " AS inv FROM " . MAIN_DB_PREFIX . "societe_remise_except WHERE " . $k['col_source'] . " = " . $id
            . " AND description LIKE '" . $db->escape($k['excess']) . "%' AND " . $k['col'] . " > 0");
        while ($res && ($u = $db->fetch_object($res))) {
            $usedOn[] = (int) $u->inv;
        }
        $refs = array_column(ksefDbCheckInvoiceRefs($db, $usedOn, $el), 'ref');
        $out[] = array('code' => 'excess_owed', 'key' => 'excess_owed:' . $id, 'tone' => 'warning', 'icon' => 'hand', 'fix' => null,
            'title' => $langs->transnoentitiesnoconv('KSEF_NoteExcessOwed', ksefNoteMoney($o ? (float) $o->amount : 0), $cur, $owed()),
            'text' => $langs->transnoentitiesnoconv('KSEF_NoteExcessOwedWhy', $cur, $zero),
            'ordered' => true, 'steps' => array($refs ? $langs->transnoentitiesnoconv('KSEF_NoteExcessOwedUsed', implode(', ', $refs)) : $langs->transnoentitiesnoconv('KSEF_NoteExcessOwedDo'),
                $langs->transnoentitiesnoconv('KSEF_NoteExcessOwedReopen', $reopen), $langs->transnoentitiesnoconv('KSEF_NoteExcessOwedPay' . $sup, $cur, $pay, $closeShort)),
            'links' => array($langs->transnoentitiesnoconv('KSEF_NoteDiscountsLink') => DOL_URL_ROOT . '/comm/remx.php?id=' . (int) $object->socid));
    }
    return $out;
}

/**
 * @brief Notes for card
 * @param DoliDB $db
 * @param Facture|FactureFournisseur $object
 * @return array
 */
function ksefInvoiceNotesOnCard($db, $object)
{
    $notes = ksefInvoiceNotes($db, $object);
    if (($object->element ?? '') !== 'facture') {
        return $notes;
    }
    $out = array();
    foreach ($notes as $n) {
        if ($n['code'] !== 'deposit_sent' || !ksefNoteSentInline($db, $object, (int) substr($n['key'], strlen('deposit_sent:')))) {
            $out[] = $n;
        }
    }
    return $out;
}

/**
 * @brief Sent warning inline
 * @param DoliDB $db
 * @param Facture $object
 * @param int $depId Deposit id
 * @return bool
 */
function ksefNoteSentInline($db, $object, $depId)
{
    $ctx = ksefDepositContextOnce($db, $object);
    if ($ctx['type'] === 'ROZ' && ksefSentInvoiceType($db, (int) $object->id) === 'VAT') {
        return false;
    }
    if ($ctx['type'] === 'ROZ' || $ctx['type'] === 'KOR_ROZ') {
        return isset(ksefSettlementNeedsCorrection($db, (int) $object->id)[$depId]);
    }
    if ($ctx['type'] === 'ZAL' || $ctx['type'] === 'KOR_ZAL') {
        $root = $ctx['type'] === 'ZAL' ? (int) $object->id : (int) ksefDepositRootId($db, (int) $object->id);
        return $root === $depId && (bool) ksefDepositSettlementsToCorrect($db, $depId);
    }
    return false;
}

/**
 * @brief Render notes
 * @param array $notes
 * @param Facture|FactureFournisseur $object
 * @param bool $canFix
 * @return string
 */
function ksefInvoiceNotesHtml($notes, $object, $canFix)
{
    global $langs;
    $icons = array('fix' => 'fa-wrench', 'lock' => 'fa-lock', 'hand' => 'fa-arrow-right');
    $html = '';
    foreach ($notes as $n) {
        $info = $n['tone'] === 'info';
        $html .= '<div class="' . ($info ? 'info ksef-note-info' : 'warning ksef-note-check') . ' ksef-invoice-note" data-note="' . dol_escape_htmltag($n['code']) . '"'
            . ' style="margin-bottom: 10px; padding: 10px; border-radius: 4px;">';
        $html .= '<div><span class="fas ' . ($info ? 'fa-info-circle' : 'fa-exclamation-triangle') . ' paddingright"></span>'
            . '<strong>' . dol_escape_htmltag($n['title']) . '</strong><br>'
            . '<span class="fas ' . $icons[$n['icon']] . ' paddingright opacitymedium"></span>' . dol_escape_htmltag($n['text']);
        if (!empty($n['steps'])) {
            $tag = empty($n['ordered']) ? 'ul' : 'ol';
            $html .= '<' . $tag . ' class="ksef-note-steps">';
            foreach ($n['steps'] as $s) {
                $html .= '<li>' . dol_escape_htmltag($s) . '</li>';
            }
            $html .= '</' . $tag . '>';
        }
        $html .= '</div>';
        $buttons = '';
        foreach ((array) ($n['links'] ?? array()) as $label => $url) {
            $buttons .= '<a class="butAction" href="' . dol_escape_htmltag($url) . '">' . dol_escape_htmltag($label) . '</a>';
        }
        if (!empty($n['fix']) && $canFix) {
            $buttons .= '<a class="butAction ksef-note-fix" href="' . $_SERVER['PHP_SELF'] . '?facid=' . (int) $object->id . '&action=ksef_note_ask&note=' . urlencode($n['key'])
                . '&token=' . newToken() . '">' . $langs->trans('KSEF_NoteFixButton') . '</a>';
        }
        if ($buttons !== '') {
            $html .= '<div class="ksef-note-buttons">' . $buttons . '</div>';
        }
        $html .= '</div>';
    }
    return $html;
}

/**
 * @brief Fix confirm dialog
 * @param Facture|FactureFournisseur $object
 * @param array $note
 * @return string
 */
function ksefInvoiceNoteConfirm($object, $note)
{
    global $db, $langs;
    require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
    $q = '<table class="noborder centpercent ksef-note-plan"><tr class="liste_titre"><th>' . $langs->trans('KSEF_NoteColWhat') . '</th><th class="right">'
        . $langs->trans('KSEF_NoteColNow') . '</th><th class="right">' . $langs->trans('KSEF_NoteColAfter') . '</th></tr>';
    foreach ((array) $note['rows'] as $r) {
        $q .= '<tr class="oddeven"><td>' . dol_escape_htmltag($r[0]) . '</td><td class="right nowraponall">' . dol_escape_htmltag($r[1]) . '</td><td class="right nowraponall"><strong>'
            . dol_escape_htmltag($r[2]) . '</strong></td></tr>';
    }
    $q .= '</table><div class="opacitymedium" style="margin-top: 8px;">' . $langs->trans('KSEF_NoteBackupFirst') . '</div>';
    $form = new Form($db);
    return $form->formconfirm($_SERVER['PHP_SELF'] . '?facid=' . (int) $object->id . '&note=' . urlencode($note['key']), $note['confirm'], $q, 'ksef_note_fix', '', 'yes', 1, 0, 560);
}

/**
 * @brief Run note fix
 * @param DoliDB $db
 * @param User $user
 * @param Facture|FactureFournisseur $object
 * @param string $key Note key
 * @return array
 */
function ksefInvoiceNoteFix($db, $user, $object, $key)
{
    global $langs;
    $langs->load('ksef@ksef');
    $note = null;
    foreach (ksefInvoiceNotes($db, $object) as $n) {
        if ($n['key'] === $key && !empty($n['fix'])) {
            $note = $n;
        }
    }
    if (!$note) {
        return array('result' => 'skipped', 'detail' => $langs->transnoentitiesnoconv('KSEF_NoteChanged'));
    }
    $r = $note['fix'];
    $b = ksefDbBackupBefore($db, $user, 'repair', (string) $object->ref, array(), ksefDbCheckRepairBackupSpec($db, $user, array(array('repair' => $r))));
    if (!$b['ok']) {
        return array('result' => 'failed', 'detail' => $b['error']);
    }
    if ($r['op'] === 'clear_sale_dates') {
        $ids = array_keys(ksefDbCheckStaleSaleDates($db, (int) $object->id, true));
        $res = !$ids ? array('result' => 'skipped', 'detail' => $langs->transnoentitiesnoconv('KSEF_NoteChanged'))
            : ($db->query("UPDATE " . MAIN_DB_PREFIX . "facture_extrafields SET ksef_sale_date = NULL WHERE fk_object = " . (int) $object->id)
                ? array('result' => 'ok', 'detail' => '') : array('result' => 'failed', 'detail' => $db->lasterror()));
    } elseif ($r['op'] === 'deposit_restate') {
        $res = ksefDbCheckRepairDeposit($db, $user, $r);
    } elseif ($r['op'] === 'foreign_pln') {
        $res = ksefDbCheckRepairForeignPln($db, $r);
    } elseif ($r['op'] === 'import_rate') {
        $res = ksefDbCheckRepairImportRate($db, $r);
    } elseif ($r['op'] === 'import_totals') {
        $res = ksefDbCheckRepairImportTotals($db, $r);
    } elseif ($r['op'] === 'draft_currency') {
        $res = ksefDraftCurrencyFix($db, $object);
    } elseif ($r['op'] === 'line_vat') {
        $res = ksefLineVatFix($db, (int) $object->id);
        if ($res['result'] === 'ok' && $object->fetch((int) $object->id) > 0) {
            ksefNoteRefreshPdf($object);
        }
    } else {
        $res = array('result' => 'skipped', 'detail' => '');
    }
    dol_syslog('ksefInvoiceNoteFix by ' . $user->login . ': ' . $key . ' on ' . $object->element . ' ' . (int) $object->id . ' ' . $res['result']
        . ($res['detail'] !== '' ? ' (' . $res['detail'] . ')' : '') . ($b['file'] !== '' ? ' backup ' . $b['file'] : ''), $res['result'] === 'failed' ? LOG_ERR : LOG_WARNING);
    return $res;
}

/**
 * @brief Draft main currency
 * @param DoliDB $db
 * @param Facture $object
 * @return array
 */
function ksefDraftCurrencyFix($db, $object)
{
    global $conf;
    $P = MAIN_DB_PREFIX;
    $id = (int) $object->id;
    if ((int) $object->status !== 0 || ksefInvoiceCurrencyStray($object) === '' || abs((float) $object->multicurrency_tx - 1) >= 0.000001) {
        return array('result' => 'skipped', 'detail' => '');
    }
    $db->begin();
    $ok = $db->query("UPDATE " . $P . "facturedet SET multicurrency_subprice = subprice, multicurrency_total_ht = total_ht, multicurrency_total_tva = total_tva,"
        . " multicurrency_total_ttc = total_ttc WHERE fk_facture = " . $id)
        && $db->query("UPDATE " . $P . "facture SET multicurrency_code = '" . $db->escape((string) $conf->currency) . "', multicurrency_tx = 1, multicurrency_total_ht = total_ht,"
        . " multicurrency_total_tva = total_tva, multicurrency_total_ttc = total_ttc WHERE rowid = " . $id . " AND fk_statut = 0");
    if (!$ok) {
        $err = $db->lasterror();
        $db->rollback();
        return array('result' => 'failed', 'detail' => $err);
    }
    $db->commit();
    return array('result' => 'ok', 'detail' => '');
}

/**
 * @brief Rebuild card PDF
 * @param Facture $object
 * @return void
 */
function ksefNoteRefreshPdf($object)
{
    global $langs;
    if (getDolGlobalString('MAIN_DISABLE_PDF_AUTOUPDATE') || empty($object->model_pdf)) {
        return;
    }
    $object->fetch_thirdparty();
    $outlangs = $langs;
    if (getDolGlobalInt('MAIN_MULTILANGS') && !empty($object->thirdparty->default_lang)) {
        $outlangs = new Translate('', $GLOBALS['conf']);
        $outlangs->setDefaultLang($object->thirdparty->default_lang);
        $outlangs->load('bills');
    }
    if ($object->generateDocument($object->model_pdf, $outlangs) <= 0) {
        dol_syslog('ksefNoteRefreshPdf ' . $object->ref . ': ' . $object->error, LOG_WARNING);
    }
}

/**
 * @brief Step deposit credit
 * @param DoliDB $db
 * @param int $depId Deposit id
 * @param string $el
 * @return bool
 */
function ksefNoteDepositInStep($db, $depId, $el)
{
    $rows = ksefDepositRows($db, $depId, $el);
    $t = $rows ? ksefDepositCreditTarget($db, $depId, 0, 0, $el) : false;
    if (!$t || ($el === 'invoice_supplier' && ksefDepositCovers($db, $depId))) {
        return false;
    }
    $K = $t['key'];
    $sum = array();
    $invs = array();
    foreach ($rows as $r) {
        $sum[$r['key']] = ($sum[$r['key']] ?? 0) + $r[$K];
        if ($r['inv'] > 0) {
            $invs[$r['inv']] = $r['inv'];
        }
    }
    foreach (array_unique(array_merge(array_keys($sum), array_keys($t['target']['rates']))) as $rk) {
        if (abs(($sum[$rk] ?? 0) - ($t['target']['rates'][$rk][$K] ?? 0)) >= 0.005) {
            return false;
        }
    }
    $k = ksefInvoiceKind($el);
    return !$invs || ksefDbCheckCount($db, "SELECT COUNT(*) AS n FROM " . MAIN_DB_PREFIX . $k['table'] . " WHERE fk_facture_source IN (" . implode(',', $invs) . ") AND type IN (1, 2)") === 0;
}

/**
 * @brief Index notes list
 * @param DoliDB $db
 * @param User $user
 * @param bool $more Scan limit hit
 * @return array
 */
function ksefInvoiceNotesIndex($db, $user, &$more = false)
{
    global $conf, $langs;
    $langs->load('ksef@ksef');
    $P = MAIN_DB_PREFIX;
    $n = (int) KSEF_NOTES_INDEX_SCAN;
    $cur = $db->escape((string) $conf->currency);
    $more = false;
    $out = array();
    $add = function ($el, $id, $ref, $title) use (&$out) {
        $out[$el . ':' . $id] = array('element' => $el, 'id' => (int) $id, 'ref' => (string) $ref, 'title' => $title, 'url' => ksefDbCheckInvoiceUrl($id, $el));
    };
    $ids = function ($sql) use ($db, $n, &$more) {
        $r = array();
        $res = $db->query($sql . " ORDER BY f.rowid DESC LIMIT " . $n);
        while ($res && ($o = $db->fetch_object($res))) {
            $r[] = (int) $o->rowid;
        }
        $more = $more || count($r) >= $n;
        return $r;
    };
    $booked = ksefDbCheckTableExists($db, $P . 'accounting_bookkeeping');
    $els = array();
    if ($user->hasRight('facture', 'lire')) {
        $els[] = 'facture';
    }
    if (isModEnabled('supplier_invoice') && ($user->hasRight('fournisseur', 'facture', 'lire') || $user->hasRight('supplier_invoice', 'lire'))) {
        $els[] = 'invoice_supplier';
    }
    if (in_array('facture', $els, true)) {
        $sd = ksefDbCheckStaleSaleDates($db, 0, true);
        $more = $more || count($sd) > $n;
        foreach (array_slice($sd, -$n, null, true) as $id => $ref) {
            $add('facture', $id, $ref, $langs->transnoentitiesnoconv('KSEF_NoteIndexSaleDate'));
        }
        // mirror rateChangeBlocked()
        if (!getDolGlobalString('INVOICE_CAN_NEVER_BE_EDITED')) {
            $free = getDolGlobalString('INVOICE_CAN_BE_EDITED_EVEN_IF_PAYMENT_DONE') ? "" : " AND f.paye = 0"
                . " AND NOT EXISTS (SELECT 1 FROM " . $P . "paiement_facture AS pf WHERE pf.fk_facture = f.rowid)"
                . " AND NOT EXISTS (SELECT 1 FROM " . $P . "societe_remise_except AS re WHERE re.fk_facture = f.rowid)";
            $sql = "SELECT f.rowid FROM " . $P . "facture AS f WHERE f.fk_statut = 1 AND f.type IN (0, 1, 2, 3, 5) AND f.entity IN (" . getEntity('invoice') . ")"
                . " AND COALESCE(f.multicurrency_code, '') NOT IN ('', '" . $cur . "') AND f.multicurrency_tx > 0" . $free
                . ($booked ? " AND NOT EXISTS (SELECT 1 FROM " . $P . "accounting_bookkeeping AS b WHERE b.doc_type = 'customer_invoice' AND b.fk_doc = f.rowid)" : "")
                . " AND NOT EXISTS (SELECT 1 FROM " . $P . "facture AS c WHERE c.fk_facture_source = f.rowid AND c.type IN (1, 2) AND c.fk_statut > 0)";
            foreach ($ids($sql) as $id) {
                foreach (ksefDbCheckForeignPln($db, $id) as $e) {
                    if ($e['state'] === 'repairable') {
                        $add('facture', $id, $e['object'], $langs->transnoentitiesnoconv('KSEF_NoteIndexForeignPln'));
                    }
                }
            }
        }
    }
    if (in_array('invoice_supplier', $els, true) && ksefDbCheckTableExists($db, $P . 'ksef_incoming')) {
        // mirror ksefDbCheckImportLocked()
        $sql = "SELECT f.rowid FROM " . $P . "ksef_incoming AS r INNER JOIN " . $P . "facture_fourn AS f ON f.rowid = r.fk_facture_fourn"
            . " WHERE r.import_status = 'IMPORTED' AND r.invoice_type NOT LIKE 'KOR%' AND f.type IN (0, 3) AND f.fk_statut IN (0, 1, 2)"
            . " AND f.entity IN (" . getEntity('supplier_invoice') . ") AND COALESCE(f.multicurrency_code, '') NOT IN ('', '" . $cur . "')"
            . " AND NOT EXISTS (SELECT 1 FROM " . $P . "facture_fourn AS c WHERE c.fk_facture_source = f.rowid AND c.type = 2)"
            . " AND (EXISTS (SELECT 1 FROM " . $P . "facture_fourn AS c WHERE c.fk_facture_source = f.rowid AND c.type = 1) OR ("
            . "NOT EXISTS (SELECT 1 FROM " . $P . "paiementfourn_facturefourn AS pf WHERE pf.fk_facturefourn = f.rowid)"
            . " AND NOT EXISTS (SELECT 1 FROM " . $P . "societe_remise_except AS re WHERE re.fk_invoice_supplier = f.rowid OR re.fk_invoice_supplier_source = f.rowid)"
            . ($booked ? " AND NOT EXISTS (SELECT 1 FROM " . $P . "accounting_bookkeeping AS b WHERE b.doc_type = 'supplier_invoice' AND b.fk_doc = f.rowid)" : "") . "))";
        foreach ($ids($sql) as $id) {
            foreach (ksefDbCheckImportRates($db, $id) as $e) {
                if ($e['state'] === 'repairable') {
                    $add('invoice_supplier', $e['id'], $e['object'], $langs->transnoentitiesnoconv('KSEF_NoteIndexImportRate'));
                }
            }
        }
    }
    foreach ($els as $el) {
        $k = ksefInvoiceKind($el);
        $T = $P . $k['table'];
        $deps = $ids("SELECT f.rowid FROM " . $T . " AS f WHERE f.type = 3 AND f.fk_statut > 0 AND f.entity IN (" . getEntity($k['entity']) . ")"
            . " AND EXISTS (SELECT 1 FROM " . $T . " AS c WHERE c.fk_facture_source = f.rowid AND c.type IN (1, 2) AND c.fk_statut > 0)");
        foreach ($deps as $depId) {
            if (ksefNoteDepositInStep($db, $depId, $el)) {
                continue;
            }
            $p = ksefDbCheckDepositPlan($db, $user, $depId, $el);
            if ($p['changes'] > 0) {
                $refs = ksefDbCheckInvoiceRefs($db, array($depId), $el);
                $add($el, $depId, $refs[$depId]['ref'] ?? (string) $depId, $langs->transnoentitiesnoconv('KSEF_NoteIndexDeposit'));
            } elseif ($p['locked'] && $el === 'facture') {
                foreach ($p['locked'] as $sid => $sref) {
                    $add($el, $sid, $sref, $langs->transnoentitiesnoconv('KSEF_NoteIndexDepositSent'));
                }
            }
        }
        if (isModEnabled('multicurrency')) {
            foreach (ksefDbCheckPaidWhileOwed($db, $el) as $e) {
                if ($e['severity'] === 'warning') {
                    $add($el, $e['id'], $e['object'], $langs->transnoentitiesnoconv('KSEF_NoteIndexPaidOwed'));
                }
            }
            foreach (ksefDbCheckExcessWhileOwed($db, $el) as $e) {
                $add($el, $e['id'], $e['object'], $langs->transnoentitiesnoconv('KSEF_NoteIndexExcessOwed'));
            }
            $pc = ksefDbCheckPaymentColumn($db, $el);
            $more = $more || count($pc) > $n;
            $pcIds = array_slice(array_unique(array_column($pc, 'id')), 0, $n);
            foreach (ksefDbCheckInvoiceRefs($db, $pcIds, $el) as $pid => $r) {
                $add($el, $pid, $r['ref'], $langs->transnoentitiesnoconv('KSEF_NoteIndexPayColumn'));
            }
        }
    }
    uasort($out, function ($a, $b) {
        return $b['id'] <=> $a['id'];
    });
    return array_values($out);
}

/**
 * @brief Payment column hint
 * @param DoliDB $db
 * @param string $element
 * @param int $facid
 * @param int $socid 0: from invoice
 * @return string
 */
function ksefPayColumnHintJs($db, $element, $facid, $socid = 0)
{
    global $conf, $langs;
    if (!isModEnabled('multicurrency') || !isModEnabled('bank')) {
        return '';
    }
    $k = ksefInvoiceKind($element);
    $P = MAIN_DB_PREFIX;
    if ($socid <= 0 && $facid > 0) {
        $socid = (int) $db->getRow("SELECT fk_soc FROM " . $P . $k['table'] . " WHERE rowid = " . (int) $facid)->fk_soc;
    }
    if ($socid <= 0) {
        return '';
    }
    $inv = $pre = array();
    $res = $db->query("SELECT rowid, multicurrency_code, fk_account FROM " . $P . $k['table'] . " WHERE fk_soc = " . (int) $socid . " AND fk_statut = 1 AND entity IN (" . getEntity($k['entity']) . ")");
    while ($res && ($o = $db->fetch_object($res))) {
        $inv[(int) $o->rowid] = (string) $o->multicurrency_code !== '' ? (string) $o->multicurrency_code : $conf->currency;
        $pre[(int) $o->rowid] = (int) $o->fk_account;
    }
    $acc = array();
    $res = $db->query("SELECT rowid, currency_code FROM " . $P . "bank_account WHERE entity IN (" . getEntity('bank_account') . ")");
    while ($res && ($o = $db->fetch_object($res))) {
        $acc[(int) $o->rowid] = (string) $o->currency_code !== '' ? (string) $o->currency_code : $conf->currency;
    }
    if (!$inv || !$acc) {
        return '';
    }
    // invoice account first
    $preset = 0;
    if ($facid > 0 && isset($inv[(int) $facid])) {
        foreach (array($pre[(int) $facid], $element === 'facture' ? getDolGlobalInt('FACTURE_RIB_NUMBER') : 0) as $a) {
            if ($a > 0 && ($acc[$a] ?? '') === $inv[(int) $facid]) {
                $preset = $a;
                break;
            }
        }
    }
    $langs->loadLangs(array('ksef@ksef', 'bills', 'main', 'multicurrency'));
    $colMain = $langs->transnoentitiesnoconv('PaymentAmount');
    $colCur = (int) DOL_VERSION >= 23 ? $colMain . ' (' . $langs->transnoentitiesnoconv('MulticurrencyOriginalCurrency') . ')' : $langs->transnoentitiesnoconv('MulticurrencyPaymentAmount');
    return '<script>
jQuery(function ($) {
    var acc = ' . json_encode($acc) . ', inv = ' . json_encode($inv) . ', main = ' . json_encode($conf->currency) . ';
    var text = ' . json_encode($langs->transnoentitiesnoconv('KSEF_PayColumnHint', '%CUR%', '%COL%')) . ';
    var other = ' . json_encode($langs->transnoentitiesnoconv('KSEF_PayAccountOtherCurrency', '%CUR%', '%INV%')) . ', facid = ' . (int) $facid . ', preset = ' . (int) $preset . ';
    var colMain = ' . json_encode($colMain) . ', colCur = ' . json_encode($colCur) . ';
    var form = $("#payment_form");
    var sel = form.find("[name=accountid]");
    if (!form.length || !sel.length) {
        return;
    }
    var hint = $("<div class=\"warning ksef-paycol-hint maxwidth500\" style=\"display: none; margin: 6px 0 0 0;\"></div>");
    sel.closest("td").append(hint);
    if (preset > 0 && !(parseInt(sel.val(), 10) > 0) && sel.find("option[value=\"" + preset + "\"]").length) {
        sel.val(String(preset)).trigger("change");
    }
    function nonzero(el) {
        var v = String($(el).val() || "").replace(/\s/g, "").replace(",", ".");
        return v !== "" && parseFloat(v) !== 0 && !isNaN(parseFloat(v));
    }
    function check() {
        var a = acc[sel.val()], typed = {}, cols = {}, n = 0, t = "", own = [];
        form.find("input.amount[type=text], input.multicurrency_amount").each(function () {
            var c = inv[parseInt(this.name.replace(/^(multicurrency_)?amount_/, ""), 10)];
            if (c && nonzero(this) && own.indexOf(c) < 0) {
                own.push(c);
            }
        });
        if (!own.length && inv[facid]) {
            own.push(inv[facid]);
        }
        form.find("input.multicurrency_amount").each(function () {
            var c = inv[parseInt(this.name.replace("multicurrency_amount_", ""), 10)];
            if (c) {
                cols[c] = 1;
                if (nonzero(this)) {
                    typed[c] = 1;
                }
            }
        });
        form.find("input.amount[type=text]").each(function () {
            cols[main] = 1;
            if (nonzero(this)) {
                typed[main] = 1;
            }
        });
        for (var c in typed) {
            n++;
            t = c;
        }
        if (a && n === 1 && t !== a && cols[a]) {
            hint.text(text.split("%CUR%").join(a).split("%COL%").join(a === main ? colMain : colCur)).show();
        } else if (a && a !== main && own.length && own.indexOf(a) < 0) {
            hint.text(other.split("%CUR%").join(a).split("%INV%").join(own.join(", "))).show();
        } else {
            hint.hide();
        }
    }
    form.on("change keyup", "input.amount, input.multicurrency_amount", check);
    sel.on("change", check);
    $(document).ajaxComplete(check);
    check();
});
</script>';
}
