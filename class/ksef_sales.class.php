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
 * \file    ksef/class/ksef_sales.class.php
 * \ingroup ksef
 * \brief   KSeF sales invoices
 */

dol_include_once('/ksef/class/ksef_incoming.class.php');

/**
 * @brief KSeF sales row
 */
class KsefSales extends KsefIncoming
{
    public $element = 'ksef_sales';
    public $table_element = 'ksef_sales';
    public $picto = 'bill';

    protected $linkColumn = 'fk_facture';
    protected $triggerPrefix = 'KSEF_SALES';
    protected $productRefPrefix = 'KSEFS-';

    /**
     * @brief Outgoing record
     * @return bool
     */
    protected function isOutgoingRecord()
    {
        return true;
    }

    public $fk_facture;
    public $invoice_hash;
    public $link_status;
    public $link_candidate;
    public $link_note;
    /** @var int */
    public $created_customer_id = 0;

    const LINK_OK = 'OK';
    const LINK_DIFFERS = 'DIFFERS';
    const LINK_LINKABLE = 'LINKABLE';
    const LINK_SUGGEST = 'SUGGEST';
    const LINK_IMPORTABLE = 'IMPORTABLE';
    const LINK_CONFLICT = 'CONFLICT';
    const LINK_UNSUPPORTED = 'UNSUPPORTED';
    const LINK_SKIPPED = 'SKIPPED';

    const GAP_MISSING = 'MISSING';
    const GAP_NOT_SENT = 'NOT_SENT';
    const GAP_OPTIONAL = 'OPTIONAL';

    protected $cardPage = '/ksef/sales_card.php';
    protected $cardLabel = 'KSEF_SalesInvoice';

    const IMPORT_TYPES = array('VAT', 'KOR', 'ZAL', 'UPR', 'ROZ', 'KOR_ZAL', 'KOR_ROZ');

    /**
     * @brief Own insert columns
     * @return array
     */
    protected function insertExtraColumns()
    {
        return array(
            'invoice_hash' => $this->invoice_hash ? "'" . $this->db->escape($this->invoice_hash) . "'" : "NULL",
            'link_status' => $this->link_status ? "'" . $this->db->escape($this->link_status) . "'" : "NULL",
            'link_candidate' => $this->link_candidate ? (int) $this->link_candidate : "NULL",
            'link_note' => $this->link_note ? "'" . $this->db->escape(dol_trunc($this->link_note, 250, 'right', 'UTF-8', 1)) . "'" : "NULL",
        );
    }

    /**
     * @brief Read own columns
     * @param object $obj Database row
     * @return void
     */
    protected function fetchExtraColumns($obj)
    {
        $this->invoice_hash = $obj->invoice_hash ?? null;
        $this->link_status = $obj->link_status ?? null;
        $this->link_candidate = $obj->link_candidate ?? null;
        $this->link_note = $obj->link_note ?? null;
    }

    /**
     * @brief Store parsed invoice
     * @param $parsedData FA3Parser output
     * @param $rawXml
     * @param $ksefNumber
     * @param $environment TEST/DEMO/PRODUCTION
     * @param $user User object
     * @param $notransaction Caller owns transaction
     * @return int <0 on error
     */
    public function createFromParsed($parsedData, $rawXml, $ksefNumber, $environment, $user, $notransaction = false)
    {
        $this->invoice_hash = ksefCalculateHash($rawXml);
        return parent::createFromParsed($parsedData, $rawXml, $ksefNumber, $environment, $user, $notransaction);
    }

    /**
     * @brief Store unparsed invoice
     * @param $ksefNumber
     * @param $rawXml Null if missing
     * @param $environment
     * @param $message Reason
     * @param $meta Package metadata
     * @param $user User object
     * @return int 0 if stored, <0 error
     */
    public function createFailed($ksefNumber, $rawXml, $environment, $message, $meta, $user)
    {
        $this->invoice_hash = is_string($rawXml) && $rawXml !== '' ? ksefCalculateHash($rawXml) : (!empty($meta['invoiceHash']) ? (string) $meta['invoiceHash'] : null);
        return parent::createFailed($ksefNumber, $rawXml, $environment, $message, $meta, $user);
    }

    /**
     * @brief Link status label
     * @param string $status
     * @return string
     */
    public static function linkLabel($status)
    {
        global $langs;
        $langs->load('ksef@ksef');
        return $langs->trans('KSEF_SalesLink_' . ($status ?: 'NONE'));
    }

    /**
     * @brief Link status badge
     * @param string $status
     * @param string $title Tooltip
     * @param int|null $count Compact count
     * @return string HTML
     */
    public static function linkBadge($status, $title = '', $count = null)
    {
        $tones = array(
            self::LINK_OK => 'ok',
            self::LINK_DIFFERS => 'action',
            self::LINK_LINKABLE => 'action',
            self::LINK_SUGGEST => 'action',
            self::LINK_IMPORTABLE => 'action',
            self::GAP_NOT_SENT => 'action',
            self::LINK_CONFLICT => 'problem',
            self::GAP_MISSING => 'problem',
            self::STATUS_FETCH_ERROR => 'problem',
            self::LINK_UNSUPPORTED => 'info',
            self::LINK_SKIPPED => 'info',
            self::GAP_OPTIONAL => 'info',
        );
        $icons = array(
            self::LINK_OK => 'fa-check', self::LINK_DIFFERS => 'fa-exchange-alt', self::LINK_LINKABLE => 'fa-link',
            self::LINK_SUGGEST => 'fa-question', self::LINK_IMPORTABLE => 'fa-file-import', self::GAP_NOT_SENT => 'fa-paper-plane',
            self::LINK_CONFLICT => 'fa-ban', self::GAP_MISSING => 'fa-search-minus', self::STATUS_FETCH_ERROR => 'fa-exclamation-triangle',
            self::LINK_UNSUPPORTED => 'fa-puzzle-piece', self::LINK_SKIPPED => 'fa-eye-slash', self::GAP_OPTIONAL => 'fa-user',
        );
        $class = isset($tones[$status]) ? ksefToneBadgeClass($tones[$status]) : 'badge-status0';
        if ($count !== null) {
            $title = self::linkLabel($status) . ($title !== '' ? ': ' . $title : '');
            $icon = isset($icons[$status]) ? '<span class="fa ' . $icons[$status] . ' paddingright"></span>' : '';
            return '<span class="badge badge-status ' . $class . ' classfortooltip" title="' . dol_escape_htmltag($title) . '">' . $icon . (int) $count . '</span>';
        }
        return '<span class="badge badge-status ' . $class . ($title !== '' ? ' classfortooltip' : '') . '"' . ($title !== '' ? ' title="' . dol_escape_htmltag($title) . '"' : '') . '>'
            . dol_escape_htmltag(self::linkLabel($status)) . '</span>';
    }

    /**
     * @brief Invoice by ref
     * @param string $ref
     * @return int 0 if none
     */
    public function findInvoiceByRef($ref)
    {
        $ref = trim((string) $ref);
        if ($ref === '') {
            return 0;
        }
        $res = $this->db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "facture WHERE ref = '" . $this->db->escape($ref) . "'"
            . " AND entity IN (" . getEntity('invoice') . ") ORDER BY rowid LIMIT 1");
        if ($res && ($o = $this->db->fetch_object($res))) {
            return (int) $o->rowid;
        }
        return 0;
    }

    /**
     * @brief Differing key fields
     * @param Facture $invoice
     * @return array
     */
    public function keyFieldDiff($invoice)
    {
        $diff = array();
        if ((int) $this->invoice_date && date('Y-m-d', (int) $this->invoice_date) !== date('Y-m-d', (int) $invoice->date)) {
            $diff[] = 'P_1';
        }
        if ($this->buyerNipDiffers($invoice)) {
            $diff[] = 'NIP';
        }
        $currency = strtoupper((string) ($this->currency ?: 'PLN'));
        $invCurrency = strtoupper((string) (!empty($invoice->multicurrency_code) ? $invoice->multicurrency_code : 'PLN'));
        if ($currency !== $invCurrency) {
            $diff[] = 'KodWaluty';
        }
        $total = $currency !== 'PLN' ? (float) $invoice->multicurrency_total_ttc : (float) $invoice->total_ttc;
        if (abs($total - (float) $this->total_gross) >= 0.005 && ($stated = $this->statedTotals($invoice))) {
            $total = $stated['gross'];
        }
        if (abs($total - (float) $this->total_gross) >= 0.005) {
            $diff[] = 'P_15';
        }
        return $diff;
    }

    /**
     * @brief Buyer NIP differs
     * @param Facture $invoice
     * @return bool
     */
    private function buyerNipDiffers($invoice)
    {
        if (empty($invoice->thirdparty)) {
            $invoice->fetch_thirdparty();
        }
        $theirs = ksefCleanNIP((string) $this->buyer_nip);
        $ours = !empty($invoice->thirdparty) ? ksefCleanNIP((string) ksefGetIdentifierField($invoice->thirdparty, 'NIP')) : '';
        return $theirs !== '' && $ours !== '' && $theirs !== $ours && substr($ours, -strlen($theirs)) !== $theirs;
    }

    /**
     * @brief Totals FA(3) states
     * @param Facture $invoice
     * @return array|null
     */
    private function statedTotals($invoice)
    {
        if (empty($invoice->id)) {
            return null;
        }
        dol_include_once('/ksef/class/fa3_builder.class.php');
        dol_include_once('/ksef/class/fa3_parser.class.php');
        $builder = new FA3Builder($this->db);
        $xml = $builder->buildFromInvoice((int) $invoice->id);
        if (!is_string($xml) || $xml === '') {
            return null;
        }
        $parser = new FA3Parser($this->db);
        $p = $parser->parse($xml);
        if (empty($p['invoice'])) {
            return null;
        }
        return array('net' => (float) $p['invoice']['total_net'], 'vat' => (float) $p['invoice']['total_vat'], 'gross' => (float) $p['invoice']['total_gross']);
    }

    /**
     * @brief Differing fields
     * @param Facture $invoice
     * @return array
     */
    public function contentDiff($invoice)
    {
        $diff = $this->keyFieldDiff($invoice);
        if (self::isCorrectionType($this->invoice_type)) {
            return $diff;
        }
        $foreign = strtoupper((string) ($this->currency ?: 'PLN')) !== 'PLN';
        $net = $foreign ? (float) $invoice->multicurrency_total_ht : (float) $invoice->total_ht;
        $vat = $foreign ? (float) $invoice->multicurrency_total_tva : (float) $invoice->total_tva;
        if ((abs($net - (float) $this->total_net) >= 0.005 || abs($vat - (float) $this->total_vat) >= 0.005) && ($stated = $this->statedTotals($invoice))) {
            $net = $stated['net'];
            $vat = $stated['vat'];
        }
        if (abs($net - (float) $this->total_net) >= 0.005) {
            $diff[] = 'P_13';
        }
        if (abs($vat - (float) $this->total_vat) >= 0.005) {
            $diff[] = 'P_14';
        }
        if ($diff && !array_diff($diff, array('P_13', 'P_14')) && $this->lineRoundingOnly($invoice)) {
            return array();
        }
        return array_values(array_unique($diff));
    }

    /**
     * @brief Amount in grosz
     * @param float|string $amount
     * @return int
     */
    private static function grosz($amount)
    {
        return (int) round((float) $amount * 100);
    }

    /**
     * @brief Rounding differs only
     * @param Facture $invoice
     * @return bool
     */
    public function lineRoundingOnly($invoice)
    {
        if (self::isCorrectionType($this->invoice_type) || (int) $invoice->type === Facture::TYPE_CREDIT_NOTE || empty($invoice->id)) {
            return false;
        }
        $foreign = strtoupper((string) ($this->currency ?: 'PLN')) !== 'PLN';
        $decl = ksefIncomingDeclaredTotals($this);
        if (self::grosz($foreign ? $invoice->multicurrency_total_ttc : $invoice->total_ttc) !== self::grosz($decl['ttc'])) {
            return false;
        }
        $doli = ksefSupplierInvoiceRateTotals($this->db, $invoice, $foreign);
        $count = array();
        $res = $this->db->query("SELECT tva_tx, COUNT(*) AS n FROM " . MAIN_DB_PREFIX . "facturedet WHERE fk_facture = " . (int) $invoice->id . " GROUP BY tva_tx");
        if (!$res) {
            return false;
        }
        while ($o = $this->db->fetch_object($res)) {
            $rate = (string) (float) $o->tva_tx;
            $count[$rate] = ($count[$rate] ?? 0) + (int) $o->n;
        }
        foreach (array_unique(array_merge(array_keys($decl['rates']), array_keys($doli))) as $rate) {
            $x = $decl['rates'][$rate] ?? array('net' => 0.0, 'vat' => 0.0);
            $d = $doli[$rate] ?? array('net' => 0.0, 'vat' => 0.0);
            if (self::grosz($x['net']) + self::grosz($x['vat']) !== self::grosz($d['net']) + self::grosz($d['vat'])) {
                return false;
            }
            if (abs(self::grosz($x['vat']) - self::grosz($d['vat'])) > ($count[$rate] ?? 0)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @brief Accepted submission row
     * @return array|null
     */
    private function recordedSubmission()
    {
        $res = $this->db->query("SELECT s.fk_facture, s.invoice_hash, s.fa3_xml FROM " . MAIN_DB_PREFIX . "ksef_submissions AS s"
            . " JOIN " . MAIN_DB_PREFIX . "facture AS f ON f.rowid = s.fk_facture"
            . " WHERE s.ksef_number = '" . $this->db->escape($this->ksef_number) . "' AND s.status IN ('ACCEPTED', 'OFFLINE')"
            . " ORDER BY s.rowid DESC LIMIT 1");
        if ($res && ($o = $this->db->fetch_object($res))) {
            return array('fk_facture' => (int) $o->fk_facture, 'invoice_hash' => (string) $o->invoice_hash, 'fa3_xml' => (string) $o->fa3_xml);
        }
        return null;
    }

    /**
     * @brief Link proven locally
     * @param int $fk_facture Invoice id
     * @return bool
     */
    private function provenLocally($fk_facture)
    {
        if (!empty($this->invoice_hash)) {
            $res = $this->db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "ksef_submissions WHERE fk_facture = " . (int) $fk_facture
                . " AND invoice_hash = '" . $this->db->escape($this->invoice_hash) . "' LIMIT 1");
            if ($res && $this->db->num_rows($res)) {
                return true;
            }
        }
        $res = $this->db->query("SELECT ksef_number FROM " . MAIN_DB_PREFIX . "facture_extrafields WHERE fk_object = " . (int) $fk_facture);
        if ($res && ($o = $this->db->fetch_object($res))) {
            return trim((string) $o->ksef_number) === (string) $this->ksef_number;
        }
        return false;
    }

    /**
     * @brief Single lookalike invoice
     * @return int 0 if none
     */
    private function findSuggestion()
    {
        if (!(int) $this->invoice_date) {
            return 0;
        }
        $foreign = strtoupper((string) ($this->currency ?: 'PLN')) !== 'PLN';
        $totalCol = $foreign ? 'f.multicurrency_total_ttc' : 'f.total_ttc';
        $day = date('Y-m-d', (int) $this->invoice_date);
        $sql = "SELECT f.rowid FROM " . MAIN_DB_PREFIX . "facture f"
            . " WHERE f.entity IN (" . getEntity('invoice') . ") AND f.fk_statut > 0"
            . " AND f.datef = '" . $this->db->escape($day) . "'"
            . " AND ABS(" . $totalCol . " - (" . ((float) $this->total_gross) . ")) < 0.005"
            . " AND NOT EXISTS (SELECT 1 FROM " . MAIN_DB_PREFIX . "ksef_submissions s WHERE s.fk_facture = f.rowid AND s.status IN ('ACCEPTED', 'OFFLINE'))"
            . " ORDER BY f.rowid LIMIT 5";
        $res = $this->db->query($sql);
        $found = array();
        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
        while ($res && ($o = $this->db->fetch_object($res))) {
            $inv = new Facture($this->db);
            if ($inv->fetch((int) $o->rowid) > 0 && !$this->keyFieldDiff($inv)) {
                $found[] = (int) $o->rowid;
            }
        }
        return count($found) === 1 ? $found[0] : 0;
    }

    /**
     * @brief Reconcile with Dolibarr
     * @param User $user
     * @param bool $autoLink Link on proof
     * @return string Link status
     */
    public function reconcile($user, $autoLink = true)
    {
        global $langs;
        $langs->load('ksef@ksef');
        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
        dol_include_once('/ksef/class/ksef_submission.class.php');

        if ($this->import_status === self::STATUS_FETCH_ERROR) {
            return (string) $this->link_status;
        }
        $this->link_candidate = null;
        $this->link_note = null;

        $rec = $this->recordedSubmission();
        if ($rec) {
            $this->fk_facture = $rec['fk_facture'];
            $ours = $rec['invoice_hash'] !== '' ? $rec['invoice_hash'] : ($rec['fa3_xml'] !== '' ? ksefCalculateHash($rec['fa3_xml']) : '');
            $this->link_status = ($ours === '' || empty($this->invoice_hash) || $ours === $this->invoice_hash) ? self::LINK_OK : self::LINK_DIFFERS;
            $inv = new Facture($this->db);
            if ($inv->fetch($this->fk_facture) > 0) {
                $diff = $this->contentDiff($inv);
                if ($diff) {
                    $this->link_status = self::LINK_DIFFERS;
                    $this->link_note = $langs->transnoentitiesnoconv('KSEF_SalesDiffersFields', implode(', ', $diff));
                    if ($ours !== '' && $ours === (string) $this->invoice_hash && !$this->sourceLinesAddUp()) {
                        $this->link_note = $langs->transnoentitiesnoconv('KSEF_SalesSourceLinesNotAddUp');
                    }
                }
            }
            if ($this->link_status === self::LINK_OK && $ours === '') {
                $this->link_note = $langs->transnoentitiesnoconv('KSEF_SalesNoHashToCompare');
            }
            $this->import_status = self::STATUS_IMPORTED;
            $this->update($user, 1);
            return $this->link_status;
        }
        $this->fk_facture = null;
        if ($this->import_status === self::STATUS_IMPORTED) {
            $this->import_status = self::STATUS_NEW;
        }

        $fid = $this->findInvoiceByRef($this->invoice_number);
        if ($fid > 0) {
            $inv = new Facture($this->db);
            $inv->fetch($fid);
            $latest = new KsefSubmission($this->db);
            // other environment
            $hasRow = $latest->fetchByInvoice($fid, ksefGetEnvironment()) > 0;
            $this->link_candidate = $fid;
            if ($hasRow && in_array($latest->status, array('ACCEPTED', 'OFFLINE'), true) && !empty($latest->ksef_number) && $latest->ksef_number !== $this->ksef_number) {
                $this->link_status = self::LINK_CONFLICT;
                $this->link_note = $langs->transnoentitiesnoconv('KSEF_SalesConflictOtherNumber', $inv->ref, $latest->ksef_number);
            } elseif ($diff = $this->keyFieldDiff($inv)) {
                $this->link_status = self::LINK_CONFLICT;
                $this->link_note = $langs->transnoentitiesnoconv('KSEF_SalesConflictFields', $inv->ref, implode(', ', $diff));
            } else {
                $this->link_status = self::LINK_LINKABLE;
                if ($autoLink && $this->provenLocally($fid)) {
                    // link reads row
                    $this->update($user, 1);
                    dol_include_once('/ksef/class/ksef_service.class.php');
                    $svc = new KsefService($this->db);
                    $r = $svc->linkKsefNumber($fid, $this->ksef_number, $user, KsefSubmission::SOURCE_LINKED_FROM_SYNC);
                    if (($r['status'] ?? '') === 'ACCEPTED') {
                        return $this->reconcile($user, false);
                    }
                    $this->link_note = (string) ($r['error'] ?? '');
                }
            }
            $this->update($user, 1);
            return $this->link_status;
        }

        $sid = $this->findSuggestion();
        if ($sid > 0) {
            $this->link_candidate = $sid;
            $this->link_status = self::LINK_SUGGEST;
        } elseif (!in_array((string) $this->invoice_type, self::IMPORT_TYPES, true)) {
            $this->link_status = self::LINK_UNSUPPORTED;
            $this->link_note = $langs->transnoentitiesnoconv('KSEF_SalesTypeNotImportable', (string) $this->invoice_type);
        } else {
            $this->link_status = self::LINK_IMPORTABLE;
        }
        $this->update($user, 1);
        return $this->link_status;
    }

    /**
     * @brief Reconcile all rows
     * @param string $environment
     * @param User $user
     * @param bool $onlyNew Only never reconciled rows
     * @return array status => count
     */
    public function reconcileAll($environment, $user, $onlyNew = false)
    {
        $counts = array();
        $res = $this->db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . $this->table_element
            . " WHERE environment = '" . $this->db->escape($environment) . "' AND entity IN (" . getEntity($this->element) . ")"
            . " AND import_status <> '" . self::STATUS_FETCH_ERROR . "'" . ($onlyNew ? " AND link_status IS NULL" : "") . " ORDER BY invoice_date ASC, rowid ASC");
        $ids = array();
        while ($res && ($o = $this->db->fetch_object($res))) {
            $ids[] = (int) $o->rowid;
        }
        foreach ($ids as $id) {
            $row = new KsefSales($this->db);
            if ($row->fetch($id) > 0) {
                $st = $row->reconcile($user);
                $counts[$st] = ($counts[$st] ?? 0) + 1;
            }
        }
        return $counts;
    }

    /**
     * @brief Outgoing sync end
     * @return int 0 before first round
     */
    public static function syncedUntil()
    {
        global $db;
        dol_include_once('/ksef/class/ksef_sync_state.class.php');
        $st = new KsefSyncState($db);
        $st->load('outgoing');
        if (empty($st->hwm_date) || empty($st->last_sync)) {
            return 0;
        }
        $ts = strtotime($st->hwm_date);
        return $ts ? (int) $ts : 0;
    }

    /**
     * @brief Dolibarr-side gaps
     * @param string $environment
     * @param int $limit Max rows per kind
     * @return array
     */
    public function getDolibarrGaps($environment, $limit = 200)
    {
        $until = self::syncedUntil();
        $out = array();
        if (!$until) {
            return $out;
        }
        $P = MAIN_DB_PREFIX;
        $sql = "SELECT s.fk_facture, s.ksef_number, s.date_acceptance, f.ref FROM " . $P . "ksef_submissions s"
            . " JOIN " . $P . "facture f ON f.rowid = s.fk_facture"
            . " WHERE s.environment = '" . $this->db->escape($environment) . "' AND s.status = 'ACCEPTED' AND s.ksef_number IS NOT NULL AND s.ksef_number <> ''"
            . " AND f.entity IN (" . getEntity('invoice') . ")"
            . " AND s.rowid = (SELECT MAX(s2.rowid) FROM " . $P . "ksef_submissions s2 WHERE s2.fk_facture = s.fk_facture)"
            . " AND (s.date_acceptance IS NULL OR s.date_acceptance <= " . (int) $until . ")"
            . " AND NOT EXISTS (SELECT 1 FROM " . $P . "ksef_sales k WHERE k.ksef_number = s.ksef_number)"
            . " ORDER BY f.datef ASC LIMIT " . (int) $limit;
        $res = $this->db->query($sql);
        while ($res && ($o = $this->db->fetch_object($res))) {
            $out[] = array('kind' => self::GAP_MISSING, 'fk_facture' => (int) $o->fk_facture, 'ref' => $o->ref, 'ksef_number' => $o->ksef_number,
                'date' => (int) $o->date_acceptance);
        }
        $nsql = self::notSentSql($this->db, $environment, $until);
        if ($nsql !== '') {
            $res = $this->db->query("SELECT g.* FROM (" . $nsql . ") g ORDER BY g.datef ASC LIMIT " . (int) (3 * $limit));
            while ($res && ($o = $this->db->fetch_object($res))) {
                $out[] = array('kind' => $o->gap_kind, 'fk_facture' => (int) $o->rowid, 'ref' => $o->ref, 'ksef_number' => '', 'date' => $this->db->jdate($o->datef));
            }
        }
        return $out;
    }

    /**
     * @brief Lines add up
     * @return bool
     */
    public function sourceLinesAddUp()
    {
        if (self::isCorrectionType($this->invoice_type) || empty($this->fa3_xml)) {
            return true;
        }
        $foreign = strtoupper((string) ($this->currency ?: 'PLN')) !== 'PLN';
        $vs = null;
        $lines = $this->prepareImportLines($this->loadImportLines(false, $vs), array(), false, $foreign, $vs, 'STOCK_CALCULATE_ON_BILL');
        if (empty($lines)) {
            return true;
        }
        $sumNet = 0.0;
        foreach ($lines as $l) {
            $amount = round((float) $l['qty'] * (float) ($foreign ? $l['pu_devise'] : $l['pu']), 2);
            if ($l['price_base'] === 'TTC') {
                $rate = (float) $l['txtva'];
                $amount = $amount - round($amount * $rate / (100 + $rate), 2);
            }
            $sumNet += $amount;
        }
        $declared = ksefIncomingDeclaredTotals($this);
        $declNet = 0.0;
        foreach ($declared['rates'] as $r) {
            $declNet += (float) $r['net'];
        }
        return abs(round($sumNet - $declNet, 2)) < 0.005;
    }

    /**
     * @brief Restore KSeF dates
     * @param Facture $facture
     * @param int $wantDue Due date
     * @return void
     */
    private function restoreImportedDates($facture, $wantDue)
    {
        $wantDate = (int) ($this->invoice_date ?: 0);
        if (!$wantDate) {
            return;
        }
        $due = $wantDue ?: $wantDate;
        $sameDate = dol_print_date($facture->date, '%Y-%m-%d') === dol_print_date($wantDate, '%Y-%m-%d');
        $sameDue = dol_print_date($facture->date_lim_reglement, '%Y-%m-%d') === dol_print_date($due, '%Y-%m-%d');
        if ($sameDate && $sameDue) {
            return;
        }
        $this->db->query("UPDATE " . MAIN_DB_PREFIX . "facture SET datef = '" . $this->db->idate($wantDate) . "', date_lim_reglement = '" . $this->db->idate($due) . "'"
            . " WHERE rowid = " . (int) $facture->id);
        $facture->fetch($facture->id);
    }

    /**
     * @brief Gap check start
     * @param DoliDB $db
     * @param string $environment
     * @return string Y-m-d or ''
     */
    public static function gapStartDate($db, $environment)
    {
        $set = trim(getDolGlobalString('KSEF_OUTGOING_GAP_START'));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $set)) {
            return $set;
        }
        $res = $db->query("SELECT MIN(f.datef) as d FROM " . MAIN_DB_PREFIX . "ksef_submissions s JOIN " . MAIN_DB_PREFIX . "facture f ON f.rowid = s.fk_facture"
            . " WHERE s.status = 'ACCEPTED' AND s.environment = '" . $db->escape($environment) . "' AND f.entity IN (" . getEntity('invoice') . ")");
        if ($res && ($o = $db->fetch_object($res)) && !empty($o->d)) {
            return dol_print_date($db->jdate($o->d), '%Y-%m-%d');
        }
        return '';
    }

    /**
     * @brief Skip extrafield exists
     * @param DoliDB $db
     * @return bool
     */
    public static function hasSkipField($db)
    {
        static $has = null;
        if ($has === null) {
            $has = ksefDbColumnExists($db, MAIN_DB_PREFIX . "facture_extrafields", 'ksef_send_skipped');
        }
        return $has;
    }

    /**
     * @brief Never-sent invoices SQL
     * @param DoliDB $db
     * @param string $environment
     * @param int $until Period end
     * @return string '' if no check
     */
    public static function notSentSql($db, $environment, $until)
    {
        $start = self::gapStartDate($db, $environment);
        if ($start === '' || !$until) {
            return '';
        }
        $P = MAIN_DB_PREFIX;
        $env = $db->escape($environment);
        $nipCol = ksefFieldToColumn(ksefGetFieldName('NIP'));
        $nipCol = preg_match('/^[a-z0-9_]+$/', $nipCol) ? $nipCol : 'siren';
        $optional = "(COALESCE(TRIM(soc." . $nipCol . "), '') = '' OR (cc.code IS NOT NULL AND cc.code <> 'PL'))";
        $skip = self::hasSkipField($db);
        $kind = "CASE" . ($skip ? " WHEN fe.ksef_send_skipped = 1 THEN '" . self::LINK_SKIPPED . "'" : '')
            . " WHEN " . $optional . " THEN '" . self::GAP_OPTIONAL . "' ELSE '" . self::GAP_NOT_SENT . "' END";
        $excluded = array_filter(array_map('intval', explode(',', getDolGlobalString('KSEF_EXCLUDED_CUSTOMERS'))));
        // case-sensitive ref match
        $refMatch = ksefDbIsMysql($db) ? "BINARY k.invoice_number = BINARY f.ref" : "k.invoice_number = f.ref";
        return "SELECT f.rowid, f.ref, f.datef, f.fk_soc, " . $kind . " as gap_kind FROM " . $P . "facture f"
            . " LEFT JOIN " . $P . "societe soc ON soc.rowid = f.fk_soc"
            . " LEFT JOIN " . $P . "c_country cc ON cc.rowid = soc.fk_pays"
            . ($skip ? " LEFT JOIN " . $P . "facture_extrafields fe ON fe.fk_object = f.rowid" : '')
            . " WHERE f.entity IN (" . getEntity('invoice') . ") AND f.fk_statut IN (1, 2) AND f.type IN (0, 1, 2, 3)"
            . " AND f.datef >= '" . $db->escape($start) . "' AND f.datef <= '" . $db->idate($until) . "'"
            . ($excluded ? " AND f.fk_soc NOT IN (" . implode(',', $excluded) . ")" : '')
            . " AND NOT EXISTS (SELECT 1 FROM " . $P . "ksef_submissions s WHERE s.fk_facture = f.rowid AND s.environment = '" . $env . "')"
            . " AND NOT EXISTS (SELECT 1 FROM " . $P . "ksef_sales k WHERE (" . $refMatch . " OR k.link_candidate = f.rowid) AND k.environment = '" . $env . "')";
    }

    /**
     * @brief Set send skipped
     * @param int $fkFacture Invoice id
     * @param bool $skip
     * @return int <0 on error
     */
    public function setSendSkipped($fkFacture, $skip)
    {
        if (!self::hasSkipField($this->db)) {
            $this->error = 'ksef_send_skipped extrafield missing: re-enable the module';
            return -1;
        }
        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
        $inv = new Facture($this->db);
        if ($inv->fetch((int) $fkFacture) <= 0) {
            $this->error = 'Invoice not found';
            return -1;
        }
        $inv->fetch_optionals();
        $inv->array_options['options_ksef_send_skipped'] = $skip ? 1 : 0;
        if ($inv->insertExtraFields() < 0) {
            $this->error = $inv->error;
            return -1;
        }
        return 1;
    }

    /**
     * @brief Reconcile counts
     * @param string $environment
     * @return array
     */
    public function getReconcileCounts($environment)
    {
        $counts = array();
        $res = $this->db->query("SELECT link_status, import_status, COUNT(*) as n FROM " . MAIN_DB_PREFIX . $this->table_element
            . " WHERE environment = '" . $this->db->escape($environment) . "' AND entity IN (" . getEntity($this->element) . ")"
            . " GROUP BY link_status, import_status");
        while ($res && ($o = $this->db->fetch_object($res))) {
            $key = $o->import_status === self::STATUS_FETCH_ERROR ? self::STATUS_FETCH_ERROR : ($o->import_status === self::STATUS_SKIPPED ? self::LINK_SKIPPED : ($o->link_status ?: self::LINK_IMPORTABLE));
            $counts[$key] = ($counts[$key] ?? 0) + (int) $o->n;
        }
        foreach ($this->getDolibarrGaps($environment) as $g) {
            $counts[$g['kind']] = ($counts[$g['kind']] ?? 0) + 1;
        }
        $gaps = 0;
        foreach (array(self::LINK_DIFFERS, self::LINK_LINKABLE, self::LINK_SUGGEST, self::LINK_IMPORTABLE, self::LINK_CONFLICT, self::GAP_MISSING, self::GAP_NOT_SENT) as $k) {
            $gaps += (int) ($counts[$k] ?? 0);
        }
        $counts['gaps'] = $gaps;
        return $counts;
    }

    /**
     * @brief Buyer identifiers
     * @return array NIP, VAT ID
     */
    public function partyIdentifiers()
    {
        $p = $this->partyInfo();
        return array(preg_replace('/[^0-9]/', '', (string) $p['nip']), (string) $p['vat_id']);
    }

    /**
     * @brief Matching customer
     * @return int 0 if none
     */
    public function findMatchingThirdParty()
    {
        $nip = preg_replace('/[^0-9]/', '', (string) $this->buyer_nip);
        $vatId = '';
        $parsed = $this->parsedXml();
        if ($parsed && !empty($parsed['buyer']['kod_ue']) && !empty($parsed['buyer']['nr_vat_ue'])) {
            $vatId = $parsed['buyer']['kod_ue'] . $parsed['buyer']['nr_vat_ue'];
        }
        if ($nip === '' && $vatId === '') {
            return 0;
        }
        $res = $this->db->query($this->buildThirdPartyMatchSQL($nip, $vatId, '1 = 1'));
        if ($res && ($o = $this->db->fetch_object($res))) {
            return (int) $o->rowid;
        }
        return 0;
    }

    /**
     * @brief Buyer as invoiced
     * @return array
     */
    public function partyInfo()
    {
        $parsed = $this->parsedXml();
        $b = $parsed ? (array) $parsed['buyer'] : array();
        $vatId = (!empty($b['kod_ue']) && !empty($b['nr_vat_ue'])) ? $b['kod_ue'] . $b['nr_vat_ue'] : '';
        return array(
            'name' => (string) (!empty($b['name']) ? $b['name'] : $this->buyer_name),
            'nip' => (string) (!empty($b['nip']) ? $b['nip'] : $this->buyer_nip),
            'vat_id' => $vatId,
            'country' => (string) (!empty($b['country']) ? $b['country'] : 'PL'),
            'address' => trim((string) ($b['address'] ?? '')),
        );
    }

    /**
     * @brief Buyer change info
     * @param Facture $invoice
     * @return array|null
     */
    public function buyerChange($invoice)
    {
        $parsed = $this->parsedXml();
        if (!$parsed) {
            return null;
        }
        if (empty($invoice->thirdparty)) {
            $invoice->fetch_thirdparty();
        }
        if (empty($invoice->thirdparty)) {
            return null;
        }
        $b = (array) $parsed['buyer'];
        $now = ksefBuyerAddressLines($invoice->thirdparty);
        $sides = array(
            'ksef' => array('name' => (string) (!empty($b['name']) ? $b['name'] : $this->buyer_name), 'address' => trim((string) ($b['address'] ?? '')), 'country' => (string) ($b['country'] ?? '')),
            'dolibarr' => array('name' => $now['name'], 'address' => trim($now['l1'] . ($now['l2'] !== '' ? "\n" . $now['l2'] : '')), 'country' => $now['country']),
        );
        $loose = function ($s) {
            return mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $s)));
        };
        $changed = false;
        $what = array();
        foreach ($sides['ksef'] as $k => $v) {
            if ($loose($v) !== $loose($sides['dolibarr'][$k])) {
                $changed = true;
                $what[$k === 'name' ? 'name' : 'address'] = true;
            }
        }
        if ($changed && in_array('NIP', $this->keyFieldDiff($invoice), true)) {
            $changed = false;
            $what = array();
        }
        $sides['changed'] = $changed;
        $sides['changed_what'] = count($what) > 1 ? 'both' : (string) key($what);
        return $sides;
    }

    /**
     * @brief Parsed XML (cached)
     * @return array|false
     */
    public function parsedXml()
    {
        static $cache = array();
        if (empty($this->fa3_xml)) {
            return false;
        }
        $key = md5($this->fa3_xml);
        if (!isset($cache[$key])) {
            dol_include_once('/ksef/class/fa3_parser.class.php');
            $parser = new FA3Parser($this->db);
            $cache = array($key => $parser->parse($this->fa3_xml));
        }
        return $cache[$key];
    }

    /**
     * @brief Create buyer customer
     * @param User $user
     * @param array|null $gusData GUS data
     * @param array|null $notes User notices
     * @return int <0 on error
     */
    public function autoCreateCustomer($user, $gusData = null, &$notes = null)
    {
        global $langs;
        require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
        require_once DOL_DOCUMENT_ROOT . '/core/lib/company.lib.php';
        $parsed = $this->parsedXml();
        $buyer = $parsed ? $parsed['buyer'] : array('name' => $this->buyer_name, 'nip' => $this->buyer_nip, 'country' => 'PL', 'address' => '');

        $soc = $this->partySociete($buyer, (string) $this->buyer_name, 'KSeF ' . $this->buyer_nip);
        $soc->client = 1;
        $soc->fournisseur = 0;
        $soc->code_client = '-1';
        if (!empty($gusData) && is_array($gusData)) {
            foreach (array('name', 'address', 'zip', 'town') as $k) {
                if (!empty($gusData[$k])) {
                    $soc->$k = $gusData[$k];
                }
            }
            foreach (array('REGON' => 'regon', 'KRS' => 'krs') as $ident => $k) {
                if (!empty($gusData[$k])) {
                    $field = ksefGetFieldName($ident);
                    if (!empty($field) && $field !== 'tva_intra') {
                        $soc->$field = trim($gusData[$k]);
                    }
                }
            }
        }
        $flags = $this->buyerFlagOptions();
        foreach ($flags as $key => $v) {
            $soc->array_options[$key] = $v;
        }
        $res = $soc->create($user);
        if ($res <= 0) {
            $this->error = $soc->error;
            $this->errors = $soc->errors;
            return -1;
        }
        if ($flags) {
            if (!is_array($notes)) {
                $notes = array();
            }
            $db = $this->db;
            $notes[] = $langs->transnoentitiesnoconv('KSEF_SalesBuyerFlagSet', $soc->name, implode(', ', array_map(function ($k) use ($db) {
                return ksefBuyerFlagField($db, substr($k, strlen('options_')))['label'];
            }, array_keys($flags))));
        }
        return $res;
    }

    /**
     * @brief Buyer GV/JST extrafields
     * @return array
     */
    public function buyerFlagOptions()
    {
        $parsed = $this->parsedXml();
        $out = array();
        if (!$parsed) {
            return $out;
        }
        require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
        $ef = new ExtraFields($this->db);
        $ef->fetch_name_optionals_label('societe', true);
        foreach (array('gv' => 'is_vat_group', 'jst' => 'is_jst') as $k => $field) {
            if ((string) ($parsed['buyer'][$k] ?? '') !== '1' || empty($ef->attributes['societe']['label'][$field])) {
                continue;
            }
            $value = 1;
            $type = (string) ($ef->attributes['societe']['type'][$field] ?? '');
            if (in_array($type, array('select', 'radio', 'checkbox'), true)) {
                $value = null;
                foreach (array_keys((array) ($ef->attributes['societe']['param'][$field]['options'] ?? array())) as $key) {
                    // builder reads key
                    if (ksefFlagIsYes($key)) {
                        $value = $key;
                        break;
                    }
                }
                if ($value === null) {
                    continue;
                }
            }
            $out['options_' . $field] = $value;
        }
        return $out;
    }

    /**
     * @brief Unsaved party thirdparty
     * @param array $party Parsed party
     * @param string $fallbackName
     * @param string $lastName Last-resort name
     * @return Societe
     */
    private function partySociete($party, $fallbackName, $lastName)
    {
        require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
        require_once DOL_DOCUMENT_ROOT . '/core/lib/company.lib.php';
        $buyer = $party;
        $soc = new Societe($this->db);
        $soc->name = (string) (!empty($buyer['name']) ? $buyer['name'] : $fallbackName);
        if ($soc->name === '') {
            $soc->name = $lastName;
        }
        $country = strtoupper((string) (!empty($buyer['country']) ? $buyer['country'] : 'PL'));
        $nip = (string) ($buyer['nip'] ?? '');
        $nipField = ksefGetFieldName('NIP');
        if ($nip !== '' && $country === 'PL') {
            if (!empty($nipField) && $nipField !== 'tva_intra') {
                $soc->$nipField = $nip;
            } else {
                $soc->tva_intra = $nip;
            }
        }
        if (!empty($buyer['kod_ue']) && !empty($buyer['nr_vat_ue'])) {
            $soc->tva_intra = $buyer['kod_ue'] . $buyer['nr_vat_ue'];
        } elseif ($nip !== '' && $country !== 'PL') {
            $soc->tva_intra = $country . $nip;
        }
        $split = ksefSplitAddress((string) ($buyer['address'] ?? ''), $country);
        $soc->address = $split['address'];
        if ($split['zip'] !== '') {
            $soc->zip = $split['zip'];
            $soc->town = $split['town'];
        }
        if (!empty($buyer['email'])) {
            $soc->email = $buyer['email'];
        }
        if (!empty($buyer['phone'])) {
            $soc->phone = $buyer['phone'];
        }
        $countryId = getCountry($country, '3');
        if ($countryId > 0) {
            $soc->country_id = $countryId;
            $soc->country_code = $country;
        }
        return $soc;
    }

    /**
     * @brief Import Podmiot3 list
     * @param User $user
     * @param array $parsed Parsed XML
     * @param bool $create Create missing
     * @param array|null $notCarried Skipped indexes
     * @return array|false
     */
    private function importPodmiot3List($user, $parsed, $create = true, &$notCarried = null)
    {
        global $langs;
        $list = array();
        $notCarried = array();
        $idwewField = '';
        $src = getDolGlobalString('KSEF_IDWEW_SOURCE', 'disabled');
        if (strpos($src, 'thirdparty_extrafield:') === 0) {
            $idwewField = preg_replace('/[^a-z0-9_]/i', '', substr($src, strlen('thirdparty_extrafield:')));
        }
        if ($idwewField !== '') {
            // aborts PostgreSQL transaction
            require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
            $ef = new ExtraFields($this->db);
            $ef->fetch_name_optionals_label('societe', true);
            if (empty($ef->attributes['societe']['type'][$idwewField])) {
                $idwewField = '';
            }
        }
        foreach (array_slice((array) ($parsed['third_parties'] ?? array()), 0, 20) as $idx => $tp) {
            $name = trim((string) ($tp['name'] ?? ''));
            $label = $name !== '' ? $name : $langs->transnoentitiesnoconv('KSEF_Podmiot3_Party');
            $role = (string) ($tp['role'] ?? '');
            if (!preg_match('/^([1-9]|1[01])$/', $role)) {
                $this->warnings[] = $langs->transnoentitiesnoconv('KSEF_SalesPodmiot3NotCarried', $label, $langs->transnoentitiesnoconv('KSEF_SalesPodmiot3OtherRole'));
                $notCarried[] = $idx;
                continue;
            }
            $idwew = trim((string) ($tp['idwew'] ?? ''));
            $nrId = trim((string) ($tp['nr_id'] ?? ''));
            $nip = ($idwew === '' && $nrId === '') ? preg_replace('/[^0-9]/', '', (string) ($tp['nip'] ?? '')) : '';
            $vatId = '';
            if (!empty($tp['kod_ue']) && !empty($tp['nr_vat_ue'])) {
                $vatId = $tp['kod_ue'] . $tp['nr_vat_ue'];
            } elseif ($nrId !== '') {
                $vatId = (string) ($tp['kod_kraju_id'] ?? '') . $nrId;
            }
            $socid = 0;
            $sql = '';
            if ($idwew !== '') {
                if ($idwewField === '') {
                    $this->warnings[] = $langs->transnoentitiesnoconv('KSEF_SalesPodmiot3NotCarried', $label, $langs->transnoentitiesnoconv('KSEF_SalesPodmiot3NoIdwewField', $idwew));
                    $notCarried[] = $idx;
                    continue;
                }
                $sql = "SELECT s.rowid FROM " . MAIN_DB_PREFIX . "societe s JOIN " . MAIN_DB_PREFIX . "societe_extrafields e ON e.fk_object = s.rowid"
                    . " WHERE s.entity IN (" . getEntity('societe') . ") AND e." . $idwewField . " = '" . $this->db->escape($idwew) . "' ORDER BY s.rowid";
            } elseif ($nip !== '' || $vatId !== '') {
                $sql = $this->buildThirdPartyMatchSQL($nip, $vatId, '1 = 1');
            } elseif ($name !== '') {
                $sql = "SELECT s.rowid FROM " . MAIN_DB_PREFIX . "societe s WHERE s.entity IN (" . getEntity('societe') . ")"
                    . " AND LOWER(s.nom) = '" . $this->db->escape(mb_strtolower($name)) . "' ORDER BY s.rowid";
            }
            if ($sql !== '') {
                $res = $this->db->query($sql);
                if (!$res) {
                    $this->error = $langs->transnoentitiesnoconv('KSEF_SalesPodmiot3MatchFailed', $label, $this->db->lasterror());
                    return false;
                }
                if ($o = $this->db->fetch_object($res)) {
                    $socid = (int) $o->rowid;
                }
            }
            if ($socid <= 0) {
                $why = '';
                if (getDolGlobalString('KSEF_PODMIOT3_SOURCE', 'disabled') !== 'enabled') {
                    $why = $langs->transnoentitiesnoconv('KSEF_SalesPodmiot3SettingOffNoCreate');
                } elseif (!ksefUserCan($user, 'create_customer')) {
                    $why = $langs->transnoentitiesnoconv('KSEF_SalesPodmiot3NoRight', $langs->transnoentitiesnoconv('KSEF_RightNameCreateThirdparty'));
                } elseif (!$create) {
                    $why = $langs->transnoentitiesnoconv('KSEF_SalesPodmiot3CreateOff');
                }
                $soc = null;
                if ($why === '') {
                    $party = $tp;
                    // IDWew = NIP+unit
                    $party['nip'] = $idwew !== '' ? substr(preg_replace('/[^0-9]/', '', $idwew), 0, 10) : (string) ($tp['nip'] ?? '');
                    $soc = $this->partySociete($party, '', 'KSeF ' . $label);
                    $soc->client = 0;
                    $soc->fournisseur = 0;
                    if ($idwew !== '') {
                        $soc->array_options['options_' . $idwewField] = $idwew;
                    }
                    if ($soc->verify() < 0) {
                        $langs->load('errors');
                        $why = $langs->transnoentitiesnoconv('KSEF_SalesPodmiot3Invalid', implode(', ', array_map(array($langs, 'transnoentitiesnoconv'), (array) $soc->errors)));
                    }
                }
                if ($why !== '') {
                    $this->warnings[] = $langs->transnoentitiesnoconv('KSEF_SalesPodmiot3NotCarried', $label, $why);
                    $notCarried[] = $idx;
                    continue;
                }
                $socid = $soc->create($user);
                if ($socid <= 0) {
                    $this->error = $langs->transnoentitiesnoconv('KSEF_SalesPodmiot3CreateFailed', $label, $soc->error ?: implode(', ', (array) $soc->errors));
                    return false;
                }
                $this->warnings[] = $langs->transnoentitiesnoconv('KSEF_SalesPodmiot3Created', $soc->name, $langs->transnoentitiesnoconv('KSEF_Podmiot3_Role' . $role));
            }
            $list[] = array('id' => $socid, 'role' => $role, 'udzial' => $role === '4' ? trim((string) ($tp['udzial'] ?? '')) : '');
        }
        return $list;
    }

    /**
     * @brief Podmiot3 compare key
     * @param array $list
     * @return string
     */
    private function podmiot3Key($list)
    {
        $default = getDolGlobalString('KSEF_PODMIOT3_ROLE', '6');
        $keys = array();
        foreach ((array) $list as $e) {
            $role = (string) ($e['role'] ?? '');
            $keys[] = (int) ($e['id'] ?? 0) . ':' . ($role !== '' ? $role : $default) . ':' . trim((string) ($e['udzial'] ?? ''));
        }
        sort($keys);
        return implode('|', $keys);
    }

    /**
     * @brief Podmiot3 names
     * @param array $list
     * @return string
     */
    private function podmiot3Names($list)
    {
        global $langs;
        require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
        $names = array();
        foreach ((array) $list as $e) {
            $soc = new Societe($this->db);
            $names[] = $soc->fetch((int) $e['id']) > 0 ? $soc->name : '#' . (int) $e['id'];
        }
        return $names ? implode(', ', $names) : $langs->transnoentitiesnoconv('KSEF_SalesPodmiot3None');
    }

    /**
     * @brief Warn GV/JST mismatch
     * @param User $user
     * @param int $socid
     * @param array $parsed Parsed XML
     * @param array $p3 Carried entities
     * @return int Warnings added
     */
    private function importBuyerFlags($user, $socid, $parsed, $p3 = array())
    {
        global $langs;
        $want = array();
        foreach (array('gv' => 'is_vat_group', 'jst' => 'is_jst') as $k => $field) {
            $v = (string) ($parsed['buyer'][$k] ?? '');
            if ($v === '1' || $v === '2') {
                $want[$k] = $field;
            }
        }
        if (!$want) {
            return 0;
        }
        $roles = array_map(function ($e) {
            return (string) $e['role'];
        }, (array) $p3);
        require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
        $soc = new Societe($this->db);
        if ($soc->fetch((int) $socid) <= 0) {
            return 0;
        }
        $soc->fetch_optionals();
        $n = 0;
        foreach ($want as $k => $field) {
            $xmlYes = ((string) $parsed['buyer'][$k] === '1');
            $member = in_array($k === 'gv' ? '10' : '8', $roles, true);
            $what = $langs->transnoentitiesnoconv('KSEF_BuyerFlagWhat_' . $k);
            $fld = ksefBuyerFlagField($this->db, $field);
            if (!$fld['exists']) {
                if ($xmlYes && !$member) {
                    $this->warnings[] = $langs->transnoentitiesnoconv('KSEF_SalesBuyerFlagNoField', $what, $fld['label']);
                    $n++;
                }
                continue;
            }
            $hidden = $fld['on_card'] ? '' : ' ' . $langs->transnoentitiesnoconv('KSEF_BuyerFlagHidden', $fld['label']);
            $custYes = ksefFlagIsYes($soc->array_options['options_' . $field] ?? null);
            if ($xmlYes && !$custYes && !$member) {
                $this->warnings[] = $langs->transnoentitiesnoconv('KSEF_SalesBuyerFlagNotOnCustomer', $what, $fld['label'], $soc->name) . $hidden;
                $n++;
            } elseif (!$xmlYes && $custYes) {
                $this->warnings[] = $langs->transnoentitiesnoconv('KSEF_SalesBuyerFlagOnCustomerOnly', $what, $fld['label'], $soc->name) . $hidden;
                $n++;
            }
        }
        return $n;
    }

    /**
     * @brief Own bank account
     * @return int 0 if none
     */
    private function findOwnBankAccount()
    {
        if (!isModEnabled('bank')) {
            return 0;
        }
        $parsed = $this->parsedXml();
        $nr = $parsed ? preg_replace('/[^0-9A-Z]/', '', strtoupper((string) ($parsed['payment']['bank_account'] ?? ''))) : '';
        if ($nr === '') {
            return 0;
        }
        $res = $this->db->query("SELECT rowid, iban_prefix, number FROM " . MAIN_DB_PREFIX . "bank_account WHERE entity IN (" . getEntity('bank_account') . ") AND clos = 0");
        while ($res && ($o = $this->db->fetch_object($res))) {
            foreach (array($o->iban_prefix, $o->number) as $v) {
                $v = preg_replace('/[^0-9A-Z]/', '', strtoupper((string) $v));
                if ($v !== '' && (substr($v, -strlen($nr)) === $nr || substr($nr, -strlen($v)) === $v) && strlen($v) >= 10) {
                    return (int) $o->rowid;
                }
            }
        }
        return 0;
    }

    /**
     * @brief Corrected invoice
     * @return int 0 if none
     */
    public function findCorrectedInvoice()
    {
        foreach ($this->citedInvoices() as $c) {
            if ($c['invoice']) {
                return (int) $c['invoice']->id;
            }
        }
        return 0;
    }

    /**
     * @brief Cited invoices
     * @return array
     */
    public function citedInvoices()
    {
        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
        $cd = $this->getCorrectionData();
        $list = !empty($cd['corrected_invoices']) ? $cd['corrected_invoices']
            : array(array('ksef_number' => (string) $this->corrected_ksef_number, 'invoice_number' => (string) $this->corrected_invoice_number,
                'invoice_date' => $this->corrected_invoice_date ? date('Y-m-d', (int) $this->corrected_invoice_date) : ''));
        $out = array();
        foreach ($list as $c) {
            $ksef = trim((string) ($c['ksef_number'] ?? ''));
            $num = trim((string) ($c['invoice_number'] ?? ''));
            if ($ksef === '' && $num === '') {
                continue;
            }
            $id = 0;
            if ($ksef !== '') {
                $res = $this->db->query("SELECT fk_facture FROM " . MAIN_DB_PREFIX . "ksef_submissions WHERE ksef_number = '" . $this->db->escape($ksef) . "'"
                    . " AND status IN ('ACCEPTED', 'OFFLINE') ORDER BY rowid DESC LIMIT 1");
                if ($res && ($o = $this->db->fetch_object($res))) {
                    $id = (int) $o->fk_facture;
                }
            }
            if ($id <= 0) {
                $id = $this->citedByRef($c);
            }
            $rec = new KsefSales($this->db);
            $hasRec = ($ksef !== '' && $rec->fetch(0, $ksef) > 0) || ($num !== '' && $rec->fetchBySellerAndInvoice((string) $this->seller_nip, $num, (string) $this->environment) > 0);
            if ($id <= 0 && $hasRec && !empty($rec->fk_facture)) {
                $id = (int) $rec->fk_facture;
            }
            $inv = new Facture($this->db);
            $out[] = array('label' => $num !== '' ? $num : $ksef, 'key' => $ksef !== '' ? $ksef : $num, 'ksef' => $ksef, 'number' => $num,
                'date' => (string) ($c['invoice_date'] ?? ''), 'invoice' => ($id > 0 && $inv->fetch($id) > 0) ? $inv : null, 'record' => $hasRec ? $rec : null);
        }
        return $out;
    }

    /**
     * @brief Corrects cited note
     * @param Facture $src Cited invoice
     * @return bool
     */
    protected function correctsCitedNote($src)
    {
        if (!is_object($src) || $src->element !== 'facture' || (int) $src->type !== Facture::TYPE_CREDIT_NOTE || $this->korekta_cited > 1) {
            return false;
        }
        $parsed = $this->parsedXml();
        $before = $parsed ? (array) ($parsed['lines_before'] ?? array()) : array();
        if (!$before) {
            return false;
        }
        $net = 0.0;
        foreach ($before as $l) {
            $net += (float) ($l['net_amount'] ?? 0);
        }
        if ($net > -0.005) {
            return false;
        }
        return ksefDepositRootId($this->db, (int) $src->id) <= 0 && ksefNoteAbsorbedBy($this->db, (int) $src->id, (int) $src->fk_facture_source) === '';
    }

    /**
     * @brief Cited by ref
     * @param array $c Cited block
     * @return int 0 if none
     */
    private function citedByRef($c)
    {
        $fid = $this->findInvoiceByRef((string) ($c['invoice_number'] ?? ''));
        $inv = new Facture($this->db);
        if ($fid <= 0 || $inv->fetch($fid) <= 0) {
            return 0;
        }
        $day = (string) ($c['invoice_date'] ?? '');
        if (($day !== '' && $day !== date('Y-m-d', (int) $inv->date)) || $this->buyerNipDiffers($inv)) {
            return 0;
        }
        $ksef = trim((string) ($c['ksef_number'] ?? ''));
        dol_include_once('/ksef/class/ksef_submission.class.php');
        $sub = new KsefSubmission($this->db);
        if ($ksef !== '' && $sub->fetchByInvoice($fid, ksefGetEnvironment()) > 0 && in_array($sub->status, array('ACCEPTED', 'OFFLINE'), true)
            && !empty($sub->ksef_number) && $sub->ksef_number !== $ksef) {
            return 0;
        }
        return $fid;
    }

    /**
     * @brief Import notes
     * @param array $notCarried Skipped Podmiot3 indexes
     * @return array public, private
     */
    private function importNotes($notCarried = array())
    {
        global $langs;
        $parsed = $this->parsedXml();
        $public = array();
        $private = array('KSeF: ' . $this->ksef_number);
        if ($parsed) {
            foreach ((array) ($parsed['additional_desc'] ?? array()) as $item) {
                $public[] = trim(($item['key'] ?? '') . ': ' . ($item['value'] ?? ''), ': ');
            }
            foreach ((array) ($parsed['stopka'] ?? array()) as $text) {
                $public[] = (string) $text;
            }
            foreach ((array) ($parsed['third_parties'] ?? array()) as $i => $p3) {
                $who = trim(($p3['name'] ?? '') . ' ' . ($p3['nip'] ?? ''));
                if (in_array($i, (array) $notCarried, true)) {
                    $who .= ' (' . $langs->transnoentitiesnoconv('KSEF_SalesNoteThirdPartyNotCarried') . ')';
                }
                $private[] = $langs->transnoentitiesnoconv('KSEF_SalesNoteThirdParty', (string) ($p3['role'] ?? ''), $who, (string) ($p3['address'] ?? ''));
            }
            foreach ((array) ($parsed['warunki']['zamowienia'] ?? array()) as $z) {
                $private[] = $langs->transnoentitiesnoconv('KSEF_SalesNoteOrder', (string) ($z['nr'] ?? ''), (string) ($z['data'] ?? ''));
            }
            foreach ((array) ($parsed['warunki']['umowy'] ?? array()) as $u) {
                $private[] = $langs->transnoentitiesnoconv('KSEF_SalesNoteContract', (string) ($u['nr'] ?? ''), (string) ($u['data'] ?? ''));
            }
            if (!empty($parsed['payment']['bank_account'])) {
                $private[] = $langs->transnoentitiesnoconv('KSEF_SalesNoteBank', (string) $parsed['payment']['bank_account']);
            }
            $payText = $this->ksefPaymentText();
            if ($payText !== '') {
                $private[] = $payText;
            }
        }
        return array('public' => implode("\n", array_filter($public)), 'private' => implode("\n", array_filter($private)));
    }

    /**
     * @brief Settled deposits
     * @return array
     */
    public function settledDeposits()
    {
        $out = array();
        $parsed = $this->parsedXml();
        foreach ((array) ($parsed['deposits'] ?? array()) as $d) {
            $id = 0;
            if ($d['ksef_number'] !== '') {
                $res = $this->db->query("SELECT s.fk_facture FROM " . MAIN_DB_PREFIX . "ksef_submissions AS s JOIN " . MAIN_DB_PREFIX . "facture AS f ON f.rowid = s.fk_facture"
                    . " WHERE s.ksef_number = '" . $this->db->escape($d['ksef_number']) . "' AND s.status = 'ACCEPTED' AND f.type = 3 ORDER BY s.rowid DESC LIMIT 1");
                if ($res && ($o = $this->db->fetch_object($res))) {
                    $id = (int) $o->fk_facture;
                }
            } elseif ($d['number'] !== '') {
                $res = $this->db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "facture WHERE ref = '" . $this->db->escape($d['number']) . "' AND type = 3"
                    . " AND entity IN (" . getEntity('invoice') . ") LIMIT 1");
                if ($res && ($o = $this->db->fetch_object($res))) {
                    $id = (int) $o->rowid;
                }
            }
            $out[] = array('label' => $d['ksef_number'] !== '' ? $d['ksef_number'] : $d['number'], 'id' => $id,
                'ksef_number' => (string) $d['ksef_number'], 'number' => (string) $d['number']);
        }
        return $out;
    }

    /**
     * @brief Import preview lines
     * @return array
     */
    public function previewImportLines()
    {
        $lines = parent::previewImportLines();
        if ($this->invoice_type === 'ROZ') {
            $lines = array_values(array_filter($lines, function ($l) {
                return empty($l['deposit_settlement']);
            }));
        }
        return $lines;
    }

    /**
     * @brief Deposit preview rows
     * @return array
     */
    public function depositPreviewLines()
    {
        global $langs;
        $out = array();
        if ($this->invoice_type !== 'ROZ') {
            return $out;
        }
        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
        $missing = false;
        foreach ($this->settledDeposits() as $d) {
            if ($d['id'] <= 0) {
                $missing = true;
                continue;
            }
            $dep = new Facture($this->db);
            if ($dep->fetch($d['id']) <= 0) {
                $missing = true;
                continue;
            }
            $rates = array();
            foreach ((array) ksefDepositDiscounts($this->db, $dep) as $disc) {
                $k = (string) (float) $disc['rate'];
                $rates[$k] = ($rates[$k] ?? 0) + (float) $disc['ht'];
            }
            foreach ($rates as $rate => $ht) {
                $label = $langs->trans('KSEF_SalesDepositRow') . ' ' . $dep->getNomUrl(1) . ($d['ksef_number'] !== '' ? ' <span class="opacitymedium small">' . dol_escape_htmltag($d['ksef_number']) . '</span>' : '');
                $out[] = array('line_num' => '', 'description' => '', 'description_html' => $label, 'quantity' => 1, 'unit' => '', 'unit_price_net' => -$ht,
                    'vat_rate' => $rate, 'net_amount' => -$ht, 'deposit_settlement' => 1);
            }
        }
        if ($missing) {
            $out = array();
            $vs = null;
            $lineNet = array();
            foreach ($this->loadImportLines(false, $vs) as $l) {
                if (!self::isProductLine($l)) {
                    continue;
                }
                $k = (string) (float) ($l['vat_rate'] ?? 0);
                $lineNet[$k] = ($lineNet[$k] ?? 0) + (float) ($l['net_amount'] ?? 0);
            }
            $declared = ksefIncomingDeclaredTotals($this);
            foreach ($lineNet as $rate => $net) {
                $diff = round($net - (float) ($declared['rates'][$rate]['net'] ?? 0), 2);
                if ($diff > 0.005) {
                    $out[] = array('line_num' => '', 'description' => $langs->transnoentitiesnoconv('KSEF_SalesDepositsSettledRow'), 'quantity' => 1, 'unit' => '',
                        'unit_price_net' => -$diff, 'vat_rate' => $rate, 'net_amount' => -$diff, 'deposit_settlement' => 1);
                }
            }
        }
        return $out;
    }

    /**
     * @brief Use settled deposits
     * @param User $user
     * @param Facture $facture Draft ROZ
     * @return int <0 on error
     */
    private function consumeSettledDeposits($user, $facture)
    {
        global $langs;
        require_once DOL_DOCUMENT_ROOT . '/core/class/discount.class.php';
        foreach ($this->settledDeposits() as $d) {
            if ($d['id'] <= 0) {
                $this->error = $langs->transnoentitiesnoconv('KSEF_SalesImportDepositsFirst', $d['label']);
                return -1;
            }
            $dep = new Facture($this->db);
            $dep->fetch($d['id']);
            $dep->fetch_lines();
            $discounts = ksefDepositDiscounts($this->db, $dep, $user);
            if (!is_array($discounts)) {
                $this->error = $discounts;
                return -1;
            }
            // restate first
            $rs = ksefDepositRestate($this->db, $user, (int) $dep->id);
            if ($rs['result'] < 0) {
                $this->error = $rs['error'];
                return -1;
            }
            $discounts = ksefDepositDiscounts($this->db, $dep);
            $ids = array();
            foreach ($discounts as $disc) {
                if ($disc['used_by'] > 0) {
                    $this->error = $langs->transnoentitiesnoconv('KSEF_SalesDepositAlreadyUsed', $dep->ref);
                    return -1;
                }
                if (abs($disc['ttc']) < 0.005 && abs($disc['mttc']) < 0.005) {
                    continue;
                }
                $ids[] = $disc['id'];
            }
            foreach ($ids as $did) {
                if ($facture->insert_discount($did) <= 0) {
                    $this->error = $facture->error ?: implode(', ', (array) $facture->errors);
                    return -1;
                }
            }
        }
        return 1;
    }

    /**
     * @brief Import refusal reason
     * @param string $code Refusal code
     * @param int $correctionSourceId Cited invoice id
     * @param int $socid
     * @return string '' if importable
     */
    public function importRefusal(&$code = null, $correctionSourceId = 0, $socid = 0)
    {
        global $langs;
        $langs->load('ksef@ksef');
        $code = '';
        if ($this->import_status === self::STATUS_FETCH_ERROR) {
            $code = 'fetch_error';
            return $langs->transnoentitiesnoconv('KSEF_FetchErrorNotImportable');
        }
        if ($this->import_status === self::STATUS_SKIPPED) {
            $code = 'skipped';
            return $langs->transnoentitiesnoconv('KSEF_InvoiceSkipped');
        }
        if ($this->import_status === self::STATUS_IMPORTED || !empty($this->fk_facture)) {
            $code = 'imported';
            return $langs->transnoentitiesnoconv('KSEF_ImportErrorAlreadyImported');
        }
        if (!in_array((string) $this->invoice_type, self::IMPORT_TYPES, true)) {
            $code = 'type';
            return $langs->transnoentitiesnoconv('KSEF_SalesTypeNotImportable', (string) $this->invoice_type);
        }
        $fid = $this->findInvoiceByRef($this->invoice_number);
        if ($fid > 0) {
            $code = 'ref_taken';
            if ($this->link_status === self::LINK_CONFLICT && (string) $this->link_note !== '') {
                return (string) $this->link_note;
            }
            return $langs->transnoentitiesnoconv('KSEF_SalesRefTaken', (string) $this->invoice_number);
        }
        if ($this->link_status === self::LINK_SUGGEST && (int) $this->link_candidate > 0) {
            $code = 'suggestion';
            return $langs->transnoentitiesnoconv('KSEF_SalesHasSuggestion');
        }
        if ($this->invoice_type === 'ROZ') {
            $missing = array();
            foreach ($this->settledDeposits() as $d) {
                if ($d['id'] <= 0) {
                    $missing[] = $d['label'];
                }
            }
            if ($missing) {
                $code = 'deposits_missing';
                return $langs->transnoentitiesnoconv('KSEF_SalesImportDepositsFirst', implode(', ', $missing));
            }
        }
        $currency = $this->importCurrencyRefusal();
        if ($currency !== '') {
            $code = 'currency';
            return $currency;
        }
        if (self::isCorrectionType($this->invoice_type) && $this->findCorrectedInvoice() <= 0) {
            $code = 'source_missing';
            return (string) $this->resolveKorektaSource();
        }
        if (self::isCorrectionType($this->invoice_type)) {
            $k = $this->resolveKorektaSource($correctionSourceId, $socid);
            if (!is_object($k)) {
                $code = 'korekta_not_ready';
                return $k;
            }
        }
        return '';
    }

    /**
     * @brief Rebuild korekta lines
     * @param Facture $facture Korekta draft
     * @param Facture $source Corrected invoice
     * @param array $lineProductMap
     * @param bool $foreign
     * @return int <0 on error
     */
    private function rebuildSalesKorektaLines($facture, $source, $lineProductMap, $foreign)
    {
        $facture->fetch($facture->id);
        $have = (float) ($foreign ? $facture->multicurrency_total_ht : $facture->total_ht);
        $want = (float) ($foreign ? $source->multicurrency_total_ht : $source->total_ht) + (float) $this->total_net;
        // totals may coincide
        if ($this->korekta_cited <= 1 && abs($have - $want) < 0.015) {
            return 1;
        }
        foreach ($facture->lines as $l) {
            if ($facture->deleteLine($l->id) < 0) {
                $this->error = $facture->error;
                return -1;
            }
        }
        if (empty($source->lines)) {
            $source->fetch_lines();
        }
        foreach ($source->lines as $l) {
            // MAIN_MAX_DECIMALS_UNIT rounding
            $pu = ksefLineExactUnitPrice($l, $foreign);
            $r = $facture->addline($l->desc, $foreign ? 0 : $pu, $l->qty, $l->tva_tx, $l->localtax1_tx, $l->localtax2_tx, $l->fk_product, $l->remise_percent,
                $l->date_start, $l->date_end, 0, $l->info_bits, 0, 'HT', 0, $l->product_type, -1, 0, '', 0, 0, null, 0, '', array(), 100, 0, $l->fk_unit,
                $foreign ? $pu : 0, $l->ref_ext);
            if ($r <= 0) {
                $this->error = $facture->error ?: implode(', ', (array) $facture->errors);
                return -1;
            }
        }
        $vs = null;
        $diff = $this->prepareImportLines($this->loadImportLines(true, $vs), $lineProductMap, false, $foreign, $vs, 'STOCK_CALCULATE_ON_BILL');
        foreach ($diff as $pl) {
            if ($this->addPreparedLine($facture, $pl) <= 0) {
                $this->error = $facture->error ?: implode(', ', (array) $facture->errors);
                return -1;
            }
        }
        return 1;
    }

    /**
     * @brief Add prepared line
     * @param Facture $facture
     * @param array $pl Prepared line
     * @return int
     */
    private function addPreparedLine($facture, $pl)
    {
        $qty = (float) $pl['qty'];
        $pu = (float) $pl['pu'];
        $puDevise = (float) $pl['pu_devise'];
        if ($qty < 0) {
            $qty = -$qty;
            $pu = -$pu;
            $puDevise = -$puDevise;
        }
        $ttc = $pl['price_base'] === 'TTC';
        return $facture->addline($pl['desc'], $ttc ? 0 : $pu, $qty, $pl['txtva'], 0, 0, (int) $pl['fk_product'], 0, '', '', 0, 0, 0,
            $ttc ? 'TTC' : 'HT', $ttc ? $pu : 0, (int) $pl['type'], -1, 0, '', 0, 0, null, 0, '', array(), 100, 0, null, $puDevise, $pl['ref']);
    }

    /**
     * @brief Import as invoice
     * @param User $user
     * @param int $socid
     * @param array $lineProductMap
     * @param int $correctionSourceId Cited invoice id
     * @param string $upwardMode Unused
     * @param array $options
     * @return int <0 on error
     */
    public function importToDolibarr($user, $socid, $lineProductMap = array(), $correctionSourceId = 0, $upwardMode = '', $options = array())
    {
        global $langs, $conf;
        $langs->load('ksef@ksef');
        $this->warnings = array();
        $this->error = '';
        $this->errors = array();

        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
        require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
        dol_include_once('/ksef/class/fa3_parser.class.php');
        dol_include_once('/ksef/class/ksef_submission.class.php');

        if (!ksefUserCan($user, 'import_sales')) {
            $this->error = $langs->trans('NotEnoughPermissions');
            return -1;
        }
        if (!ksefVisibleSociete($this->db, $socid)) {
            $this->error = $langs->trans('KSEF_SalesImportNoCustomer');
            return -1;
        }
        $lineProductMap = $this->knownProducts($lineProductMap);
        $code = '';
        $refusal = $this->importRefusal($code, $correctionSourceId, $socid);
        if ($refusal !== '') {
            $this->error = $refusal;
            return -2;
        }
        $parsed = $this->parsedXml();
        if (!$parsed) {
            $this->error = $langs->trans('KSEF_FetchErrorNotImportable');
            return -1;
        }

        $this->db->begin();
        $lock = $this->db->query("SELECT import_status, fk_facture FROM " . MAIN_DB_PREFIX . $this->table_element . " WHERE rowid = " . (int) $this->id . " FOR UPDATE");
        $lockObj = $lock ? $this->db->fetch_object($lock) : null;
        if ($lockObj && ($lockObj->import_status === self::STATUS_IMPORTED || (int) $lockObj->fk_facture > 0)) {
            $this->db->rollback();
            $this->error = $langs->trans('KSEF_ImportErrorAlreadyImported');
            return -2;
        }

        $isCorrection = self::isCorrectionType($this->invoice_type);
        $korektaSource = null;
        $facture = new Facture($this->db);
        $facture->socid = (int) $socid;
        $facture->date = $this->invoice_date ?: dol_now();
        if (!empty($this->payment_due_date)) {
            $facture->date_lim_reglement = $this->payment_due_date;
        }
        $notes = $this->importNotes();
        $facture->note_public = $notes['public'];
        $facture->note_private = $notes['private'];
        if (!empty($this->payment_method)) {
            $facture->mode_reglement_id = $this->mapKsefPaymentMethod($this->payment_method);
        }
        $facture->fk_account = $this->findOwnBankAccount();
        if ($isCorrection) {
            $korektaSource = $this->resolveKorektaSource($correctionSourceId, $socid);
            if (!is_object($korektaSource)) {
                $this->error = $korektaSource;
                $this->db->rollback();
                return -2;
            }
            $facture->type = Facture::TYPE_REPLACEMENT;
            $facture->fk_facture_source = $korektaSource->id;
        } elseif ($this->invoice_type === 'ZAL') {
            $facture->type = Facture::TYPE_DEPOSIT;
        } else {
            $facture->type = Facture::TYPE_STANDARD;
        }
        $foreign = !empty($this->currency) && strtoupper($this->currency) !== 'PLN';
        $currencyRefusal = $this->importCurrencyRefusal($facture);
        if ($currencyRefusal !== '') {
            $this->error = $currencyRefusal;
            $this->db->rollback();
            return -2;
        }
        if ($korektaSource) {
            $facture->multicurrency_code = $korektaSource->multicurrency_code;
            $facture->multicurrency_tx = $korektaSource->multicurrency_tx;
            $foreign = !empty($korektaSource->multicurrency_code) && $korektaSource->multicurrency_code !== $conf->currency;
        }

        $wantDue = !empty($this->payment_due_date) ? (int) $this->payment_due_date : 0;
        $fid = $facture->create($user, 0, $wantDue);
        if ($fid <= 0) {
            $this->error = $facture->error ?: implode(', ', (array) $facture->errors);
            $this->db->rollback();
            return -3;
        }

        if ($korektaSource) {
            // override BILL_CREATE defaults
            $cd = $this->getCorrectionData();
            $facture->fetch($fid);
            $facture->array_options['options_ksef_correction_reason'] = (string) ($cd['reason'] ?? '');
            if (!empty($cd['type'])) {
                $facture->array_options['options_ksef_correction_type'] = (string) $cd['type'];
            }
            if ($facture->insertExtraFields() < 0) {
                $this->error = $facture->error ?: implode(', ', (array) $facture->errors);
                $this->db->rollback();
                return -5;
            }
        }

        $vatSummary = null;
        $lines = $this->loadImportLines(false, $vatSummary);
        if ($this->invoice_type === 'ROZ') {
            $lines = array_values(array_filter($lines, function ($l) {
                return empty($l['deposit_settlement']);
            }));
        }
        $prepared = $this->prepareImportLines($lines, $lineProductMap, false, $foreign, $vatSummary, 'STOCK_CALCULATE_ON_BILL');
        if ($prepared === false) {
            $this->db->rollback();
            return -4;
        }
        $lineOfRow = array();
        foreach ($prepared as $pl) {
            $newLine = $this->addPreparedLine($facture, $pl);
            if ($newLine <= 0) {
                $this->error = $facture->error ?: implode(', ', (array) $facture->errors);
                $this->db->rollback();
                return -4;
            }
            if ((int) ($pl['line_num'] ?? 0) > 0) {
                $lineOfRow[(int) $pl['line_num']] = (int) $newLine;
            }
        }

        if ($this->invoice_type === 'ROZ' && $this->consumeSettledDeposits($user, $facture) < 0) {
            $this->db->rollback();
            return -4;
        }
        if ($korektaSource && ($this->rebuildSalesKorektaLines($facture, $korektaSource, $lineProductMap, $foreign) < 0 || $this->linkCitedInvoices($facture) < 0)) {
            $this->db->rollback();
            return -4;
        }
        if ($korektaSource && $foreign && $vatSummary && ksefKorektaPlnFromXml($this->db, $facture, $vatSummary) < 0) {
            $this->error = ksefDbErrorForUser($user, 'KSEF_DbErrorGeneric', $this->db->lasterror());
            $this->db->rollback();
            return -4;
        }

        if ($this->partyIdentityState($socid) === 'mismatch') {
            $this->warnings[] = $langs->transnoentitiesnoconv('KSEF_PartyIdMismatch');
        }

        $facture->fetch($fid);
        $warehouse = (int) ($options['warehouse_id'] ?? 0);
        if ($warehouse > 0 && !ksefUserCan($user, 'stock_movement')) {
            $warehouse = 0;
            $this->warnings[] = $langs->transnoentitiesnoconv('KSEF_SalesNoStockRight');
        }
        $GLOBALS['ksef_importing_sales'] = 1;
        $GLOBALS['ksef_import_no_stock'] = $warehouse > 0 ? 0 : 1;
        $vres = $facture->validate($user, (string) $this->invoice_number, $warehouse > 0 ? $warehouse : 0);
        unset($GLOBALS['ksef_importing_sales'], $GLOBALS['ksef_import_no_stock']);
        if ($vres < 0) {
            $this->error = $facture->error ?: implode(', ', (array) $facture->errors);
            $this->db->rollback();
            return -5;
        }
        $check = new Facture($this->db);
        $check->fetch($fid);
        $settled = ksefApplyIncomingVatRounding($this, $check);
        if ($settled['error'] !== '') {
            $this->error = ksefDbErrorForUser($user, 'KSEF_DbErrorGeneric', $settled['error']);
            $this->db->rollback();
            return -5;
        }
        if ($settled['note'] !== '') {
            $this->warnings[] = $settled['note'];
        }
        $plnSet = ksefIncomingPlnFromXml($this->db, $check, $this);
        if ($plnSet['result'] < 0) {
            $this->error = ksefDbErrorForUser($user, 'KSEF_DbErrorGeneric', $plnSet['error']);
            $this->db->rollback();
            return -5;
        }
        if ($plnSet['result'] > 0) {
            $check->fetch($fid);
        }
        // art. 31b(1)
        $rowRates = ksefLineKursFromXml($this->db, $fid, (array) ($parsed['lines'] ?? array()), $lineOfRow);
        if ($rowRates < 0) {
            $this->error = ksefDbErrorForUser($user, 'KSEF_DbErrorGeneric', $this->db->lasterror());
            $this->db->rollback();
            return -5;
        }
        $cmp = ksefCompareIncomingTotals($this, $check);
        $differs = !$cmp['ok'] && !$cmp['skipped'] && !$this->lineRoundingOnly($check);
        if ($differs) {
            $this->warnings[] = ksefIncomingTotalsWarning($cmp);
        }
        $facture->fetch($fid);
        $this->restoreImportedDates($facture, $wantDue);
        if ((string) $facture->ref !== (string) $this->invoice_number) {
            $this->error = $langs->trans('KSEF_SalesRefNotKept', $this->invoice_number, $facture->ref);
            $this->db->rollback();
            return -5;
        }
        if (!empty($this->sale_date)) {
            $facture->array_options['options_ksef_sale_date'] = $this->sale_date;
        }
        $facture->array_options['options_ksef_number'] = $this->ksef_number;
        $facture->array_options['options_ksef_status'] = 'ACCEPTED';
        $createParties = !array_key_exists('create_parties', (array) $options) || !empty($options['create_parties']);
        $p3NotCarried = array();
        $p3 = $this->importPodmiot3List($user, $parsed, $createParties, $p3NotCarried);
        if ($p3 === false) {
            $this->db->rollback();
            return -5;
        }
        if ($p3NotCarried) {
            $n = $this->importNotes($p3NotCarried);
            // no mid-import BILL_MODIFY
            if ($facture->update_note($n['private'], '_private', 1) < 0) {
                $this->error = $facture->error;
                $this->db->rollback();
                return -5;
            }
        }
        $this->importBuyerFlags($user, $socid, $parsed, $p3);
        $copied = $korektaSource ? ksefPodmiot3Entries($facture) : array();
        $facture->array_options['options_ksef_podmiot3'] = $p3 ? json_encode($p3) : '';
        if (array_key_exists('options_ksef_podmiot3_role', (array) $facture->array_options)) {
            $facture->array_options['options_ksef_podmiot3_role'] = '';
        }
        if ($copied && $this->podmiot3Key($copied) !== $this->podmiot3Key($p3)) {
            $this->warnings[] = $langs->transnoentitiesnoconv('KSEF_SalesPodmiot3NotFromSource', $this->podmiot3Names($p3));
        }
        if ($p3 && getDolGlobalString('KSEF_PODMIOT3_SOURCE', 'disabled') !== 'enabled') {
            $this->warnings[] = $langs->transnoentitiesnoconv('KSEF_SalesPodmiot3SettingOff');
        }
        if ($facture->insertExtraFields() < 0) {
            $this->error = $facture->error ?: implode(', ', (array) $facture->errors);
            $this->db->rollback();
            return -5;
        }

        $row = new KsefSubmission($this->db);
        $row->fk_facture = $fid;
        $row->ksef_number = $this->ksef_number;
        $row->fa3_xml = $this->fa3_xml;
        $row->invoice_hash = $this->invoice_hash ?: ksefCalculateHash((string) $this->fa3_xml);
        $row->invoice_type = $this->invoice_type;
        $row->fa3_creation_date = $this->fa3_creation_date;
        $row->status = 'ACCEPTED';
        $row->source = KsefSubmission::SOURCE_LINKED_FROM_SYNC;
        $row->environment = $this->environment;
        $row->date_submission = $this->fa3_creation_date ?: dol_now();
        $row->date_acceptance = $this->fetch_date ?: dol_now();
        $row->api_response = json_encode(array('ksef_number' => $this->ksef_number, 'imported_by' => $user->login, 'from' => 'ksef_sales'));
        if ($row->create($user) <= 0) {
            $this->error = ksefDbErrorForUser($user, 'KSEF_SubmissionNotRecorded', $row->error);
            $this->db->rollback();
            return -6;
        }

        if (!empty($options['record_payment']) && $this->recordPayments($user, $facture, (int) ($options['bank_account_id'] ?? 0)) < 0) {
            $this->db->rollback();
            return -8;
        } elseif (empty($options['record_payment']) && $this->ksefPayments() && $facture->type != Facture::TYPE_CREDIT_NOTE) {
            $this->warnings[] = $langs->transnoentitiesnoconv('KSEF_SalesPaymentNotRecordedOff');
        }

        $this->fk_facture = $fid;
        $this->import_status = self::STATUS_IMPORTED;
        $this->import_date = dol_now();
        $this->import_error = null;
        $this->link_status = $differs ? self::LINK_DIFFERS : self::LINK_OK;
        $this->link_candidate = null;
        $diffKey = 'KSEF_SalesImportTotalsDiffer';
        if (!$this->sourceLinesAddUp()) {
            $diffKey = 'KSEF_SalesSourceLinesNotAddUp';
        } elseif (!empty($cmp['vat_differs']) && abs((float) $cmp['xml_ttc'] - (float) $cmp['doli_ttc']) < 0.005) {
            $diffKey = 'KSEF_SalesImportRatesDiffer';
        }
        $this->link_note = $this->link_status === self::LINK_DIFFERS ? $langs->transnoentitiesnoconv($diffKey) : null;
        if ($this->update($user, 1) < 0) {
            $this->db->rollback();
            return -7;
        }
        $this->db->commit();
        return $fid;
    }

    /**
     * @brief Batch import
     * @param User $user
     * @param array $opts
     * @param array $createdCustomers NIP => socid
     * @return int <0 on error
     */
    public function prepareAndImport($user, $opts, &$createdCustomers = array())
    {
        global $langs;
        $langs->load('ksef@ksef');
        $warnings = array();
        $this->created_customer_id = 0;
        $code = '';
        $refusal = $this->importRefusal($code, (int) ($opts['correction_source_id'] ?? 0), (int) ($opts['socid'] ?? 0));
        if ($refusal !== '') {
            $this->error = $refusal;
            return -2;
        }
        $socid = (int) ($opts['socid'] ?? 0);
        if ($socid <= 0) {
            $socid = $this->findMatchingThirdParty();
        }
        $nip = ksefCleanNIP((string) $this->buyer_nip);
        if ($socid <= 0 && $nip !== '' && isset($createdCustomers[$nip])) {
            $socid = (int) $createdCustomers[$nip];
        }
        if ($socid <= 0 && !ksefUserCan($user, 'create_customer')) {
            $this->error = $langs->transnoentitiesnoconv('KSEF_SalesNoRightCreateCustomer');
            return -1;
        }
        if ($socid <= 0 && !empty($opts['create_customer'])) {
            $gus = null;
            if (!empty($opts['gus_data']) && is_array($opts['gus_data'])) {
                $gus = $opts['gus_data'];
            } elseif (!empty($opts['gus']) && getDolGlobalString('KSEF_GUS_ENABLED') && $nip !== '' && strlen($nip) === 10) {
                if (!array_key_exists('gus_live', (array) $opts) || !empty($opts['gus_live'])) {
                    dol_include_once('/ksef/class/ksef_gus_client.class.php');
                    $gc = new KsefGusClient($this->db, getDolGlobalString('KSEF_GUS_ENV', 'TEST'));
                    $gus = $gc->lookupByNip($nip);
                    $gc->logout();
                }
                if (!is_array($gus)) {
                    $gus = null;
                    $warnings[] = $langs->transnoentitiesnoconv('KSEF_BatchGusNoData');
                }
            }
            $socid = $this->autoCreateCustomer($user, $gus, $warnings);
            if ($socid <= 0) {
                return -1;
            }
            $this->created_customer_id = $socid;
            if ($nip !== '') {
                $createdCustomers[$nip] = $socid;
            }
        }
        if ($socid <= 0) {
            $this->error = $langs->transnoentitiesnoconv('KSEF_SalesImportNoCustomer');
            return -1;
        }

        $lines = $this->previewImportLines();
        $map = array();
        foreach ($this->autoMatchLineProducts($lines) as $ln => $m) {
            $map[$ln] = (int) $m['product_id'];
        }
        foreach ((array) ($opts['line_products'] ?? array()) as $ln => $pid) {
            if ((int) $pid > 0) {
                $map[(int) $ln] = (int) $pid;
            } else {
                unset($map[(int) $ln]);
            }
        }
        if (!empty($opts['create_products'])) {
            if (!ksefUserCan($user, 'create_product')) {
                if (count($map) < count($lines)) {
                    $warnings[] = $langs->transnoentitiesnoconv('KSEF_BatchNoRightCreateProduct');
                }
            } else {
                foreach ($lines as $line) {
                    $ln = (int) ($line['line_num'] ?? 0);
                    if (isset($map[$ln]) || !empty($line['price_only']) || !self::isProductLine($line)) {
                        continue;
                    }
                    $lw = array();
                    $line['product_type'] = (int) ($opts['product_type'] ?? 0);
                    $this->error = '';
                    $pid = $this->autoCreateProduct($user, $line, $lw);
                    $warnings = array_merge($warnings, $lw);
                    if ($pid > 0) {
                        $map[$ln] = $pid;
                    } elseif ((string) $this->error !== '') {
                        $warnings[] = $langs->transnoentitiesnoconv('KSEF_BatchProductNotCreated', $ln, $this->error);
                    }
                }
            }
        }
        $fid = $this->importToDolibarr($user, $socid, $map, (int) ($opts['correction_source_id'] ?? 0), '', $opts);
        $this->warnings = array_merge($warnings, (array) $this->warnings);
        return $fid;
    }

    /**
     * @brief Stated payments
     * @param int $fallbackDate
     * @return array
     */
    public function ksefPayments($fallbackDate = 0)
    {
        $parsed = $this->parsedXml();
        $pay = $parsed ? (array) $parsed['payment'] : array();
        $items = array();
        if (!empty($pay['partial_payments'])) {
            foreach ($pay['partial_payments'] as $p) {
                if ((float) ($p['amount'] ?? 0) != 0) {
                    $items[] = array('amount' => (float) $p['amount'], 'date' => strtotime((string) ($p['date'] ?? '')) ?: $fallbackDate);
                }
            }
        } elseif (($pay['status'] ?? '') === 'paid') {
            $items[] = array('amount' => (float) $this->total_gross, 'date' => strtotime((string) ($pay['payment_date'] ?? '')) ?: $fallbackDate);
        }
        return $items;
    }

    /**
     * @brief Payment bank account
     * @return int 0 if none
     */
    public function defaultBankAccount()
    {
        return $this->findOwnBankAccount();
    }

    /**
     * @brief Bank account problem
     * @return string '' if none
     */
    public function bankAccountProblem()
    {
        global $langs;
        if (!isModEnabled('bank')) {
            return $langs->transnoentitiesnoconv('KSEF_SalesPaymentNotRecordedBank');
        }
        $parsed = $this->parsedXml();
        $nr = $parsed ? preg_replace('/[^0-9A-Z]/', '', strtoupper((string) ($parsed['payment']['bank_account'] ?? ''))) : '';
        if ($nr === '') {
            return $langs->transnoentitiesnoconv('KSEF_SalesPaymentNoAccountOnInvoice');
        }
        if ($this->findOwnBankAccount() > 0) {
            return '';
        }
        $masked = strlen($nr) > 8 ? substr($nr, 0, 4) . ' ... ' . substr($nr, -4) : $nr;
        return $langs->transnoentitiesnoconv('KSEF_SalesPaymentAccountNotFound', $masked);
    }

    /**
     * @brief Payment summary
     * @return string
     */
    public function ksefPaymentText()
    {
        global $langs;
        $items = $this->ksefPayments();
        if (!$items) {
            return '';
        }
        $parts = array();
        foreach ($items as $it) {
            $parts[] = ($it['date'] ? dol_print_date($it['date'], 'day') . ' ' : '') . price($it['amount'], 0, $langs, 1, -1, -1, $this->currency ?: 'PLN');
        }
        $parsed = $this->parsedXml();
        $full = (($parsed['payment']['status'] ?? '') === 'paid') && empty($parsed['payment']['partial_payments']);
        return $langs->transnoentitiesnoconv($full ? 'KSEF_SalesKsefPaid' : 'KSEF_SalesKsefPartlyPaid', implode(', ', $parts));
    }

    /**
     * @brief Record stated payments
     * @param User $user
     * @param Facture $facture
     * @param int $bankId Bank account
     * @return int -1 on error
     */
    private function recordPayments($user, $facture, $bankId)
    {
        global $langs;
        $parsed = $this->parsedXml();
        $pay = $parsed ? (array) $parsed['payment'] : array();
        $items = $this->ksefPayments($facture->date);
        if (!$items || $facture->type == Facture::TYPE_CREDIT_NOTE) {
            return 0;
        }
        if (!ksefUserCan($user, 'record_payment')) {
            $this->warnings[] = $langs->transnoentitiesnoconv('KSEF_SalesPaymentNotRecordedRight');
            return 0;
        }
        if ($bankId <= 0) {
            $bankId = $this->defaultBankAccount();
        }
        if ($bankId <= 0 || !isModEnabled('bank')) {
            $why = $this->bankAccountProblem();
            $this->warnings[] = $langs->transnoentitiesnoconv('KSEF_SalesPaymentNotRecordedWhy', $why !== '' ? $why : $langs->transnoentitiesnoconv('KSEF_SalesPaymentNotRecordedBank'), $this->ksefPaymentText());
            return 0;
        }
        require_once DOL_DOCUMENT_ROOT . '/compta/paiement/class/paiement.class.php';
        $foreign = !empty($this->currency) && strtoupper($this->currency) !== 'PLN';
        $done = 0;
        $capped = false;
        foreach ($items as $it) {
            if ((float) $it['amount'] <= 0) {
                continue;
            }
            if ((int) $facture->type === Facture::TYPE_REPLACEMENT) {
                $facture->fetch($facture->id);
                $left = (float) price2num($facture->getRemainToPay($foreign ? 1 : 0), 'MT');
                if ($left < 0.005) {
                    $capped = true;
                    break;
                }
                if ((float) $it['amount'] > $left) {
                    $it['amount'] = $left;
                    $capped = true;
                }
            }
            $p = new Paiement($this->db);
            $p->datepaye = $it['date'];
            if ($foreign) {
                $p->multicurrency_amounts = array($facture->id => $it['amount']);
                $p->amounts = array($facture->id => price2num($it['amount'] / ($facture->multicurrency_tx ?: 1), 'MT'));
                // else PLN, rate=1
                $p->multicurrency_code = array($facture->id => $facture->multicurrency_code);
                $p->multicurrency_tx = array($facture->id => $facture->multicurrency_tx);
            } else {
                $p->amounts = array($facture->id => $it['amount']);
            }
            $p->paiementid = $facture->mode_reglement_id ?: $this->mapKsefPaymentMethod('6');
            $p->note_private = 'KSeF: ' . $this->ksef_number;
            if ($p->create($user, 1) < 0 || $p->addPaymentToBank($user, 'payment', '(CustomerInvoicePayment)', $bankId, '', '') < 0) {
                $langs->load('errors');
                $this->error = $langs->transnoentitiesnoconv('KSEF_SalesPaymentFailedNotImported', $p->error ? $langs->transnoentitiesnoconv($p->error) : implode(', ', (array) $p->errors));
                return -1;
            }
            $done++;
        }
        if ($capped) {
            $this->warnings[] = $langs->transnoentitiesnoconv('KSEF_SalesKorektaPaymentCapped');
        }
        if ($done && ($pay['status'] ?? '') === 'paid' && empty($pay['partial_payments'])) {
            $facture->fetch($facture->id);
            $left = (float) $facture->getRemainToPay($foreign ? 1 : 0);
            if ($left > 0 && $left <= 0.05 && empty($facture->paye)) {
                $facture->setPaid($user, '', $langs->transnoentitiesnoconv('KSEF_SalesPaidRoundingNote', $this->ksef_number));
            }
        }
        return $done;
    }

    /**
     * @brief Adjust refusal reason
     * @param Facture $invoice
     * @return string '' if allowed
     */
    public function adjustRefusal($invoice)
    {
        global $langs;
        $langs->load('ksef@ksef');
        if ($this->link_status !== self::LINK_DIFFERS || (int) $this->fk_facture !== (int) $invoice->id) {
            return $langs->transnoentitiesnoconv('KSEF_AdjustNotNeeded');
        }
        if (!$this->parsedXml()) {
            return $langs->transnoentitiesnoconv('KSEF_FetchErrorNotImportable');
        }
        if ($invoice->type != Facture::TYPE_STANDARD && $invoice->type != Facture::TYPE_DEPOSIT && $invoice->type != Facture::TYPE_CREDIT_NOTE) {
            return $langs->transnoentitiesnoconv('KSEF_AdjustTypeNotSupported');
        }
        if (!$this->sourceLinesAddUp()) {
            return $langs->transnoentitiesnoconv('KSEF_AdjustRefusedSourceLines');
        }
        if ((float) $invoice->getSommePaiement() != 0 || (float) $invoice->getSumCreditNotesUsed() != 0 || (float) $invoice->getSumDepositsUsed() != 0) {
            return $langs->transnoentitiesnoconv('KSEF_AdjustRefusedPayments');
        }
        if (isModEnabled('accounting')) {
            $res = $this->db->query("SELECT COUNT(*) as n FROM " . MAIN_DB_PREFIX . "accounting_bookkeeping WHERE doc_type = 'customer_invoice' AND fk_doc = " . (int) $invoice->id);
            if ($res && ($o = $this->db->fetch_object($res)) && (int) $o->n > 0) {
                return $langs->transnoentitiesnoconv('KSEF_AdjustRefusedAccounting');
            }
        }
        if (isModEnabled('stock')) {
            $res = $this->db->query("SELECT COUNT(*) as n FROM " . MAIN_DB_PREFIX . "stock_mouvement WHERE origintype = 'facture' AND fk_origin = " . (int) $invoice->id);
            if ($res && ($o = $this->db->fetch_object($res)) && (int) $o->n > 0) {
                return $langs->transnoentitiesnoconv('KSEF_AdjustRefusedStock');
            }
        }
        return '';
    }

    /**
     * @brief Adjust preview
     * @param Facture $invoice
     * @return array
     */
    public function adjustPreview($invoice)
    {
        $parsed = $this->parsedXml();
        if (empty($invoice->lines)) {
            $invoice->fetch_lines();
        }
        $foreign = strtoupper((string) ($this->currency ?: 'PLN')) !== 'PLN';
        $header = array(
            'P_1' => array(dol_print_date($invoice->date, 'day'), dol_print_date($this->invoice_date, 'day')),
            'P_6' => array(dol_print_date(ksefGetSaleDate($invoice), 'day'), dol_print_date($this->sale_date ?: $this->invoice_date, 'day')),
            'KodWaluty' => array(!empty($invoice->multicurrency_code) ? $invoice->multicurrency_code : 'PLN', $this->currency ?: 'PLN'),
            'NIP' => array(!empty($invoice->thirdparty) ? (string) ksefGetIdentifierField($invoice->thirdparty, 'NIP') : '', (string) $this->buyer_nip),
        );
        $dl = array();
        foreach ($invoice->lines as $l) {
            $dl[] = array('desc' => $l->product_ref ? $l->product_ref . ' - ' . $l->desc : $l->desc, 'qty' => (float) $l->qty,
                'pu' => $foreign ? (float) $l->multicurrency_subprice : (float) $l->subprice, 'vat' => price2num((float) $l->tva_tx) . (!empty($l->vat_src_code) ? ' (' . strtoupper($l->vat_src_code) . ')' : ''),
                'net' => $foreign ? (float) $l->multicurrency_total_ht : (float) $l->total_ht);
        }
        $kl = array();
        $vs = null;
        foreach ($this->loadImportLines(true, $vs) as $l) {
            $a = self::resolveLineAmount($l);
            $kl[] = array('desc' => (string) ($l['description'] ?? ''), 'qty' => (float) $a['qty'], 'pu' => (float) $a['pu'], 'vat' => (string) (is_string($kv = ksefVatKeyToDolibarr($l['vat_rate'] ?? '')) ? $kv : price2num($kv)),
                'net' => (float) ($l['net_amount'] ?? 0));
        }
        $totals = array(
            'net' => array($foreign ? (float) $invoice->multicurrency_total_ht : (float) $invoice->total_ht, (float) $this->total_net),
            'vat' => array($foreign ? (float) $invoice->multicurrency_total_tva : (float) $invoice->total_tva, (float) $this->total_vat),
            'gross' => array($foreign ? (float) $invoice->multicurrency_total_ttc : (float) $invoice->total_ttc, (float) $this->total_gross),
        );
        return array('header' => $header, 'buyer' => $this->buyerChange($invoice), 'lines_dolibarr' => $dl, 'lines_ksef' => $kl, 'totals' => $totals, 'parsed' => (bool) $parsed);
    }

    /**
     * @brief Rewrite from KSeF
     * @param User $user
     * @param Facture $invoice
     * @param array $lineProductMap
     * @param int $socid New customer
     * @return int <0 on error
     */
    public function adjustInvoice($user, $invoice, $lineProductMap = null, $socid = 0)
    {
        global $langs;
        $langs->load('ksef@ksef');
        dol_include_once('/ksef/class/ksef_submission.class.php');
        if (!ksefUserCan($user, 'adjust_invoice')) {
            $this->error = $langs->trans('NotEnoughPermissions');
            return -1;
        }
        $refusal = $this->adjustRefusal($invoice);
        if ($refusal !== '') {
            $this->error = $refusal;
            return -2;
        }
        $ref = (string) $invoice->ref;
        $socid = (int) $socid === (int) $invoice->socid ? 0 : (int) $socid;
        if ($socid > 0 && !ksefVisibleSociete($this->db, $socid)) {
            $this->error = $langs->trans('ErrorRecordNotFound');
            return -2;
        }
        if ($lineProductMap === null) {
            dol_include_once('/ksef/class/fa3_builder.class.php');
            $builder = new FA3Builder($this->db);
            $lineProductMap = array();
            if (empty($invoice->lines)) {
                $invoice->fetch_lines();
            }
            $pool = array();
            foreach ($invoice->lines as $cur) {
                if ((int) $cur->fk_product > 0) {
                    $pool[] = $cur;
                }
            }
            $vs = null;
            foreach ($this->loadImportLines(true, $vs) as $l) {
                $indeks = (string) ($l['indeks'] ?? '');
                foreach ($pool as $i => $cur) {
                    if ((string) ($l['description'] ?? '') === $builder->lineP7Text($cur) || ($indeks !== '' && $indeks === trim(substr((string) $cur->product_ref, 0, 50)))) {
                        $lineProductMap[(int) ($l['line_num'] ?? 0)] = (int) $cur->fk_product;
                        unset($pool[$i]);
                        break;
                    }
                }
            }
        }

        $this->db->begin();
        $GLOBALS['ksef_adjusting_invoice'] = (int) $invoice->id;
        if ($invoice->setDraft($user, -1) < 0) {
            unset($GLOBALS['ksef_adjusting_invoice']);
            $this->error = $invoice->error ?: implode(', ', (array) $invoice->errors);
            $this->db->rollback();
            return -3;
        }
        if ($socid > 0) {
            if ($invoice->setValueFrom('fk_soc', $socid, '', null, 'int', '', $user, 'BILL_MODIFY') < 0 || $invoice->delete_linked_contact('external') < 0) {
                unset($GLOBALS['ksef_adjusting_invoice']);
                $this->error = $invoice->error ?: implode(', ', (array) $invoice->errors);
                $this->db->rollback();
                return -3;
            }
            $invoice->thirdparty = null;
        }
        $invoice->fetch($invoice->id);
        $invoice->fetch_lines();
        foreach ($invoice->lines as $l) {
            if ($invoice->deleteLine($l->id) < 0) {
                unset($GLOBALS['ksef_adjusting_invoice']);
                $this->error = $invoice->error;
                $this->db->rollback();
                return -4;
            }
        }
        $invoice->date = $this->invoice_date ?: $invoice->date;
        if (!empty($this->payment_due_date)) {
            $invoice->date_lim_reglement = $this->payment_due_date;
        }
        $invoice->update($user, 1);
        $foreign = strtoupper((string) ($this->currency ?: 'PLN')) !== 'PLN';
        $vs = null;
        $prepared = $this->prepareImportLines($this->loadImportLines(true, $vs), $lineProductMap, (int) $invoice->type === Facture::TYPE_CREDIT_NOTE, $foreign, $vs, 'STOCK_CALCULATE_ON_BILL');
        if ($prepared === false) {
            unset($GLOBALS['ksef_adjusting_invoice']);
            $this->db->rollback();
            return -5;
        }
        foreach ($prepared as $pl) {
            if ($this->addPreparedLine($invoice, $pl) <= 0) {
                unset($GLOBALS['ksef_adjusting_invoice']);
                $this->error = $invoice->error ?: implode(', ', (array) $invoice->errors);
                $this->db->rollback();
                return -5;
            }
        }
        $invoice->fetch($invoice->id);
        if ($invoice->validate($user, $ref, 0) < 0) {
            unset($GLOBALS['ksef_adjusting_invoice']);
            $this->error = $invoice->error ?: implode(', ', (array) $invoice->errors);
            $this->db->rollback();
            return -6;
        }
        unset($GLOBALS['ksef_adjusting_invoice']);
        $invoice->fetch($invoice->id);
        $settled = ksefApplyIncomingVatRounding($this, $invoice);
        if ($settled['error'] !== '') {
            $this->error = ksefDbErrorForUser($user, 'KSEF_DbErrorGeneric', $settled['error']);
            $this->db->rollback();
            return -6;
        }
        $plnSet = ksefIncomingPlnFromXml($this->db, $invoice, $this);
        if ($plnSet['result'] < 0) {
            $this->error = ksefDbErrorForUser($user, 'KSEF_DbErrorGeneric', $plnSet['error']);
            $this->db->rollback();
            return -6;
        }
        $this->restoreImportedDates($invoice, !empty($this->payment_due_date) ? (int) $this->payment_due_date : 0);
        if (!empty($this->sale_date)) {
            $invoice->fetch($invoice->id);
            $invoice->array_options['options_ksef_sale_date'] = $this->sale_date;
            if ($invoice->insertExtraFields() < 0) {
                $this->error = $invoice->error ?: implode(', ', (array) $invoice->errors);
                $this->db->rollback();
                return -6;
            }
        }
        $snapshot = ksefLockedFieldsSnapshot($this->db, (int) $invoice->id);
        $res = $this->db->query("UPDATE " . MAIN_DB_PREFIX . "ksef_submissions SET fa3_xml = '" . $this->db->escape((string) $this->fa3_xml) . "',"
            . " invoice_hash = '" . $this->db->escape((string) $this->invoice_hash) . "'"
            . ($snapshot !== '' ? ", locked_fields = '" . $this->db->escape($snapshot) . "'" : '')
            . " WHERE fk_facture = " . (int) $invoice->id . " AND ksef_number = '" . $this->db->escape($this->ksef_number) . "' AND status = 'ACCEPTED'");
        if (!$res) {
            $this->error = ksefDbErrorForUser($user, 'KSEF_DbErrorGeneric', $this->db->lasterror());
            $this->db->rollback();
            return -7;
        }
        $cut = ksefDbRejectTruncated($this->db, '', 'KsefSales::adjustInvoice ' . $ref);
        if ($cut !== '') {
            $this->error = $cut;
            $this->db->rollback();
            return -7;
        }
        $invoice->fetch($invoice->id);
        $invoice->thirdparty = null;
        $diff = $this->contentDiff($invoice);
        $this->link_status = $diff ? self::LINK_DIFFERS : self::LINK_OK;
        $this->link_note = $diff ? $langs->transnoentitiesnoconv('KSEF_SalesDiffersFields', implode(', ', $diff)) : null;
        if ($this->update($user, 1) < 0) {
            $this->db->rollback();
            return -7;
        }
        $this->db->commit();
        dol_syslog("KsefSales::adjustInvoice invoice " . $ref . " rewritten from KSeF " . $this->ksef_number . ($socid > 0 ? " (customer " . $socid . ")" : '') . " by " . $user->login, LOG_INFO);
        return 1;
    }

    /**
     * @brief Status badge
     * @param int $mode
     * @return string
     */
    public function getLibStatut($mode = 0)
    {
        return self::linkBadge($this->import_status === self::STATUS_FETCH_ERROR ? self::STATUS_FETCH_ERROR : ($this->import_status === self::STATUS_SKIPPED ? self::LINK_SKIPPED : (string) $this->link_status));
    }
}
