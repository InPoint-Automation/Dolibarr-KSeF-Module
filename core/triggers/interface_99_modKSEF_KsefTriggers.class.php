<?php
/* Copyright (C) 2025 InPoint Automation Sp z o.o.
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
 * \file    core/triggers/interface_99_modKSEF_KsefTriggers.class.php
 * \ingroup ksef
 * \brief   KSeF event triggers
 */

require_once DOL_DOCUMENT_ROOT . '/core/triggers/dolibarrtriggers.class.php';

class InterfaceKsefTriggers extends DolibarrTriggers
{
    /** @var array */
    private static $cancelState = array();

    /** @var array */
    private static $setoffFrom = array();

    /** @var array */
    private static $supplierPaymentSoc = array();

    /**
     * @brief Constructor
     * @param DoliDB $db
     */
    public function __construct($db)
    {
        $this->db = $db;
        $this->name = preg_replace('/^Interface/i', '', get_class($this));
        $this->family = "financial";
        $this->description = "KSEF database event triggers";
        $this->version = '1.0.0';
        $this->picto = 'ksef@ksef';
    }

    /**
     * @brief Discount row ids
     * @param int $invoiceId
     * @return array|false
     */
    private function invoiceDiscountIds($invoiceId)
    {
        $sql_disc = "SELECT rc.rowid"
            . " FROM " . MAIN_DB_PREFIX . "societe_remise_except as rc"
            . " WHERE rc.fk_facture = " . (int) $invoiceId;
        $res_disc = $this->db->query($sql_disc);
        if (!$res_disc) {
            return false;
        }
        $disc_ids = array();
        while ($obj_disc = $this->db->fetch_object($res_disc)) {
            $disc_ids[] = (int) $obj_disc->rowid;
        }
        $this->db->free($res_disc);
        return $disc_ids;
    }

    /**
     * @brief Is payment rejection
     * @return bool
     */
    private function isPaymentRejection()
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $frame) {
            $class = isset($frame['class']) ? strtolower($frame['class']) : '';
            $function = isset($frame['function']) ? strtolower($frame['function']) : '';
            if (($class === 'remisecheque' && $function === 'rejectcheck') || ($class === 'rejetprelevement' && $function === 'create')) {
                return true;
            }
        }
        return false;
    }

    /**
     * @brief Is module linking
     * @return bool
     */
    private function isModuleDiscountMove()
    {
        $own = 0;
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 60) as $frame) {
            $class = isset($frame['class']) ? strtolower($frame['class']) : '';
            if ($class === strtolower(get_class($this)) && ($frame['function'] ?? '') === 'runTrigger' && ++$own > 1) {
                return true;
            }
            if (strpos($class, 'ksef') === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * @brief Guard locked fields
     * @param Facture $object
     * @param Translate $langs
     * @return int -1 if refused
     */
    private function guardXmlFields($object, $langs)
    {
        if (empty($object->id) || $object->element !== 'facture' || (int) $object->status <= Facture::STATUS_DRAFT
            || (int) ($GLOBALS['ksef_adjusting_invoice'] ?? 0) === (int) $object->id) {
            return 0;
        }
        dol_include_once('/ksef/lib/ksef.lib.php');
        $lock = ksefInvoiceEditLock($this->db, (int) $object->id, 'modify');
        if (!in_array($lock['reason'], array('issued', 'offline', 'issued_test', 'offline_test'), true) || empty($lock['row_id'])) {
            return 0;
        }
        $field = ksefLockedFieldsDrift($this->db, $object, $lock['row_id']);
        if ($field === '') {
            return 0;
        }
        if ($lock['result'] != 'blocked') {
            dol_syslog("KsefTriggers: BILL_MODIFY of " . $object->ref . " changes " . $field . " sent to KSeF: allowed, " . $lock['reason'], LOG_WARNING);
            return 0;
        }
        $lock = ksefFieldChangeLock($this->db, (int) $object->id, $field);
        $langs->load('ksef@ksef');
        $this->error = $langs->transnoentitiesnoconv($lock['key'], ...$lock['args']);
        $this->errors[] = $this->error;
        dol_syslog("KsefTriggers: BILL_MODIFY of " . $object->ref . " refused: " . $field . " was sent to KSeF (" . $lock['reason'] . ")", LOG_WARNING);
        return -1;
    }

    /**
     * @brief Track set-off date
     * @param DiscountAbsolute $object
     * @return int -1 on error
     */
    private function recordSetoffDate($object)
    {
        dol_include_once('/ksef/lib/ksef.lib.php');
        $k = ksefInvoiceKind(!empty($object->discount_type) ? 'invoice_supplier' : 'facture');
        $col = $k['col'];
        if (empty($object->id) || empty($object->$col) || strpos((string) $object->description, $k['excess']) !== 0) {
            return 0;
        }
        $res = $this->db->query("SELECT " . $col . " AS inv FROM " . MAIN_DB_PREFIX . "societe_remise_except WHERE rowid = " . (int) $object->id);
        $row = $res ? $this->db->fetch_object($res) : null;
        if (!$res) {
            $this->error = "Cannot read discount " . (int) $object->id . ": " . $this->db->lasterror();
            return -1;
        }
        $linked = $row && (int) $row->inv === (int) $object->$col;
        // Warsaw date
        $date = $linked ? ksefWarsawToday() : null;
        $from = (int) (self::$setoffFrom[(int) $object->id] ?? 0);
        if ($linked && $from > 0) {
            unset(self::$setoffFrom[(int) $object->id]);
            $date = ksefDepositState($this->db, $from, $k['element'])['setoff'][(int) $object->id] ?? null;
            if ($from !== (int) $object->$col && !ksefSetoffDateSet($this->db, $from, (int) $object->id, null, $k['element'])) {
                $this->error = "Cannot move the set-off date of discount " . (int) $object->id . ": " . $this->db->lasterror();
                return -1;
            }
        }
        if (!ksefSetoffDateSet($this->db, (int) $object->$col, (int) $object->id, $date, $k['element'])) {
            $this->error = "Cannot record the set-off date of discount " . (int) $object->id . ": " . $this->db->lasterror();
            return -1;
        }
        return 0;
    }

    /**
     * @brief Redate pre-number correction
     * @param Facture $object
     * @return int 1 moved, -1 error
     */
    private function redateEarlyCorrection($object)
    {
        global $langs;
        if (!in_array((int) $object->type, array(Facture::TYPE_CREDIT_NOTE, Facture::TYPE_REPLACEMENT), true) || empty($object->fk_facture_source)) {
            return 0;
        }
        $early = ksefCorrectionNumberDayCheck($this->db, $object);
        if (!$early) {
            return 0;
        }
        list($y, $m, $d) = array_map('intval', explode('-', ksefWarsawToday()));
        $object->date = dol_mktime(12, 0, 0, $m, $d, $y, 'tzserver');
        $object->date_lim_reglement = $object->calculate_date_lim_reglement();
        $ok = $this->db->query("UPDATE " . MAIN_DB_PREFIX . "facture SET datef = '" . $this->db->idate($object->date) . "'"
            . ", date_lim_reglement = " . (!empty($object->date_lim_reglement) ? "'" . $this->db->idate($object->date_lim_reglement) . "'" : "NULL")
            . " WHERE rowid = " . (int) $object->id);
        if (!$ok) {
            $this->error = "Cannot move the date of " . $object->ref . " to today: " . $this->db->lasterror();
            return -1;
        }
        $langs->load('ksef@ksef');
        $srcLink = '<a href="' . DOL_URL_ROOT . '/compta/facture/card.php?facid=' . (int) $early['source_id'] . '">' . dol_escape_htmltag($early['source_ref']) . '</a>';
        setEventMessages(ksefTransHtml('KSEF_CorrectionRedatedAfterSourceNumber', array($srcLink, dol_print_date(dol_stringtotime($early['number_day'] . ' 12:00:00'), 'day'),
            dol_print_date($object->date, 'day'))), null, 'warnings');
        dol_syslog("KsefTriggers: BILL_VALIDATE of correction " . $object->ref . " moved from " . $early['day'] . " to today: original's KSeF number assigned " . $early['number_day'], LOG_INFO);
        return 1;
    }

    /**
     * @brief Guard deposit (un)link
     * @param DiscountAbsolute $object
     * @param Translate $langs
     * @return int -1 if refused
     */
    private function guardDepositDiscount($object, $langs)
    {
        if (!empty($object->discount_type) || empty($object->id) || empty($object->fk_facture) || $this->isModuleDiscountMove()) {
            return 0;
        }
        dol_include_once('/ksef/lib/ksef.lib.php');
        if (!ksefIsDepositDiscount($this->db, (int) $object->id)) {
            return 0;
        }
        $lock = ksefDiscountChangeLock($this->db, (int) $object->fk_facture);
        if ($lock['result'] == 'warn') {
            dol_syslog("KsefTriggers: DISCOUNT_MODIFY of deposit discount " . (int) $object->id . " on invoice " . (int) $object->fk_facture . " allowed: " . $lock['reason'], LOG_WARNING);
        }
        if ($lock['result'] != 'blocked') {
            return 0;
        }
        $res = $this->db->query("SELECT fk_facture FROM " . MAIN_DB_PREFIX . "societe_remise_except WHERE rowid = " . (int) $object->id);
        $row = $res ? $this->db->fetch_object($res) : null;
        $linked = $row && (int) $row->fk_facture === (int) $object->fk_facture;
        $undo = $linked ? "fk_facture = NULL" : "fk_facture = " . (int) $object->fk_facture;
        if (!$this->db->query("UPDATE " . MAIN_DB_PREFIX . "societe_remise_except SET " . $undo . " WHERE rowid = " . (int) $object->id)) {
            dol_syslog("KsefTriggers: DISCOUNT_MODIFY undo of discount " . (int) $object->id . ": " . $this->db->lasterror(), LOG_ERR);
        }
        $langs->load('ksef@ksef');
        $this->error = $langs->transnoentitiesnoconv($lock['key'], ...$lock['args']);
        $this->errors[] = $this->error;
        dol_syslog("KsefTriggers: DISCOUNT_MODIFY of deposit discount " . (int) $object->id . " on invoice " . (int) $object->fk_facture . " refused (" . ($linked ? 'link' : 'unlink') . "): " . $lock['reason'], LOG_WARNING);
        return -1;
    }

    /**
     * @brief PLN follows currency
     * @param FactureLigne $line
     * @return int -1 on error
     */
    private function linePlnFollowsCurrency($line)
    {
        global $conf, $mysoc;
        $old = $line->oldline ?? null;
        if (!is_object($old) || empty($line->fk_facture) || empty($line->id)) {
            return 0;
        }
        $h = $this->db->getRow("SELECT fk_statut, multicurrency_code, multicurrency_tx FROM " . MAIN_DB_PREFIX . "facture WHERE rowid = " . (int) $line->fk_facture);
        if (!$h || (int) $h->fk_statut !== 0 || (string) $h->multicurrency_code === '' || (string) $h->multicurrency_code === (string) $conf->currency || (float) $h->multicurrency_tx <= 0) {
            return 0;
        }
        // currency price edited
        if (abs((float) $line->multicurrency_subprice - (float) $old->multicurrency_subprice) < 0.000001 || abs((float) $line->subprice - (float) $old->subprice) >= 0.000001) {
            return 0;
        }
        require_once DOL_DOCUMENT_ROOT . '/core/lib/price.lib.php';
        $pu = (float) price2num((float) $line->multicurrency_subprice / (float) $h->multicurrency_tx, 'MU');
        $sit = ($line->situation_percent ?? null) !== null ? (float) $line->situation_percent : 100;
        $tab = calcul_price_total((float) $line->qty, $pu, (float) $line->remise_percent, (float) $line->tva_tx, 0, 0, 0, 'HT', (int) $line->info_bits, (int) $line->product_type, $mysoc, array(), $sit);
        $num = function ($v) {
            return (string) price2num((float) $v, 'MT');
        };
        if (!$this->db->query("UPDATE " . MAIN_DB_PREFIX . "facturedet SET subprice = " . price2num($pu, 'MU') . ", total_ht = " . $num($tab[0]) . ", total_tva = " . $num($tab[1])
            . ", total_ttc = " . $num($tab[2]) . " WHERE rowid = " . (int) $line->id)) {
            $this->error = $this->db->lasterror();
            $this->errors[] = $this->error;
            return -1;
        }
        $line->subprice = $pu;
        $line->total_ht = $tab[0];
        $line->total_tva = $tab[1];
        $line->total_ttc = $tab[2];
        return 0;
    }

    /**
     * @brief Close credit-paid FX
     * @param User $user
     * @param DiscountAbsolute $object
     * @return int -1 on error
     */
    private function closeForeignPaidByCredit($user, $object)
    {
        if (empty($object->id)) {
            return 0;
        }
        $P = MAIN_DB_PREFIX;
        $res = $this->db->query("SELECT r.fk_facture, r.fk_invoice_supplier, d.fk_facture AS line_inv, sd.fk_facture_fourn AS line_sup FROM " . $P . "societe_remise_except AS r"
            . " LEFT JOIN " . $P . "facturedet AS d ON d.rowid = r.fk_facture_line LEFT JOIN " . $P . "facture_fourn_det AS sd ON sd.rowid = r.fk_invoice_supplier_line"
            . " WHERE r.rowid = " . (int) $object->id);
        $row = $res ? $this->db->fetch_object($res) : null;
        if (!$row) {
            return 0;
        }
        $supplier = (int) $row->fk_invoice_supplier > 0 || (int) $row->line_sup > 0;
        $invoiceId = $supplier ? max((int) $row->fk_invoice_supplier, (int) $row->line_sup) : max((int) $row->fk_facture, (int) $row->line_inv);
        if ($invoiceId <= 0) {
            return 0;
        }
        dol_include_once('/ksef/lib/ksef.lib.php');
        $kind = ksefInvoiceKind($supplier ? 'invoice_supplier' : 'facture');
        $invoice = new $kind['class']($this->db);
        if ($invoice->fetch($invoiceId) <= 0) {
            return 0;
        }
        if (ksefCloseForeignPaid($this->db, $user, $invoice) < 0) {
            $this->error = (string) $invoice->error;
            $this->errors = array_merge($this->errors, array($this->error), (array) $invoice->errors);
            dol_syslog("KsefTriggers: DISCOUNT_MODIFY close of " . $invoice->ref . ": " . $this->error, LOG_ERR);
            return -1;
        }
        return 0;
    }

    /**
     * @brief Clear copied extrafields
     * @param CommonInvoice $object
     * @param array $fields
     * @return int 1 cleared, -1 error
     */
    private function clearCopiedKsefState($object, $fields)
    {
        $cleared = array();
        foreach ($fields as $name) {
            $key = 'options_' . $name;
            if (isset($object->array_options[$key]) && $object->array_options[$key] !== '' && $object->array_options[$key] !== null) {
                $object->array_options[$key] = null;
                $cleared[] = $name;
            }
        }
        if (empty($cleared)) {
            return 0;
        }
        if ($object->insertExtraFields() < 0) {
            dol_syslog("KsefTriggers: copy " . $object->ref . " keeps the source's KSeF fields, write failed: " . $object->error, LOG_ERR);
            return -1;
        }
        dol_syslog("KsefTriggers: copy " . $object->ref . " starts without the source's KSeF fields (" . implode(', ', $cleared) . ")", LOG_INFO);
        return 1;
    }

    /**
     * @brief Copy Podmiot3 list
     * @param Facture $object
     * @return int 1 copied, -1 error
     */
    private function copySourcePodmiot3($object)
    {
        dol_include_once('/ksef/lib/ksef.lib.php');
        if (ksefPodmiot3Entries($object)) {
            return 0;
        }
        $src = new Facture($this->db);
        if ($src->fetch((int) $object->fk_facture_source) <= 0) {
            return 0;
        }
        $src->fetch_optionals();
        if (!ksefPodmiot3Entries($src)) {
            return 0;
        }
        $object->array_options['options_ksef_podmiot3'] = $src->array_options['options_ksef_podmiot3'];
        if (isset($src->array_options['options_ksef_podmiot3_role'])) {
            $object->array_options['options_ksef_podmiot3_role'] = $src->array_options['options_ksef_podmiot3_role'];
        }
        if ($object->insertExtraFields() < 0) {
            $this->error = "Failed to copy the third entities of invoice " . (int) $object->fk_facture_source . " to " . $object->ref . ": " . $object->error;
            $this->errors[] = $this->error;
            dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
            return -1;
        }
        dol_syslog("KsefTriggers: BILL_CREATE - " . $object->ref . " takes the third entities of invoice " . (int) $object->fk_facture_source, LOG_INFO);
        return 1;
    }

    /**
     * @brief Deposit chain rate
     * @param Facture $object
     * @return int 1 set, -1 error
     */
    private function copyDepositRate($object)
    {
        global $conf;
        if ($object->element !== 'facture' || !in_array((int) $object->type, array(Facture::TYPE_CREDIT_NOTE, Facture::TYPE_REPLACEMENT), true) || empty($object->fk_facture_source)) {
            return 0;
        }
        dol_include_once('/ksef/lib/ksef.lib.php');
        $depId = ksefDepositRootId($this->db, (int) $object->fk_facture_source);
        if ($depId <= 0) {
            return 0;
        }
        $P = MAIN_DB_PREFIX;
        $res = $this->db->query("SELECT multicurrency_code, multicurrency_tx, (SELECT COUNT(*) FROM " . $P . "facturedet WHERE fk_facture = " . (int) $object->id . ") AS n"
            . " FROM " . $P . "facture WHERE rowid = " . $depId);
        $o = $res ? $this->db->fetch_object($res) : null;
        $code = $o ? (string) $o->multicurrency_code : '';
        if (!$o || $code === '' || $code === $conf->currency || $code !== (string) $object->multicurrency_code || (float) $o->multicurrency_tx <= 0
            || abs((float) $o->multicurrency_tx - (float) $object->multicurrency_tx) < 1e-9 || (int) $o->n > 0) {
            return 0;
        }
        if (!$this->db->query("UPDATE " . $P . "facture SET multicurrency_tx = " . (float) $o->multicurrency_tx . " WHERE rowid = " . (int) $object->id)) {
            $this->error = "Cannot set the deposit's rate on " . $object->ref . ": " . $this->db->lasterror();
            dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
            return -1;
        }
        dol_syslog("KsefTriggers: " . $object->ref . " takes the rate of deposit " . $depId . " (" . $object->multicurrency_tx . " -> " . $o->multicurrency_tx . ")", LOG_INFO);
        $object->multicurrency_tx = (float) $o->multicurrency_tx;
        return 1;
    }

    /**
     * @brief Validated invoice number
     * @param CommonInvoice $object
     * @return string
     */
    private function validatedRef($object)
    {
        if (empty($object->newref) || empty($object->id)) {
            return (string) $object->ref;
        }
        $table = $object->element === 'invoice_supplier' ? 'facture_fourn' : 'facture';
        $res = $this->db->query("SELECT ref FROM " . MAIN_DB_PREFIX . $table . " WHERE rowid = " . ((int) $object->id));
        $o = $res ? $this->db->fetch_object($res) : null;
        return ($o && (string) $o->ref !== '') ? (string) $o->ref : (string) $object->newref;
    }

    /**
     * @brief Korekta currency rate
     * @param Facture $object
     * @param Translate $langs
     * @return int 1 changed, -1 error
     */
    private function korektaRate($object, $langs)
    {
        dol_include_once('/ksef/lib/ksef.lib.php');
        dol_include_once('/ksef/class/ksef_nbp_currency_rate.class.php');
        if (ksefGetInvoiceCurrency($object) === 'PLN' || (float) $object->multicurrency_tx <= 0) {
            return 0;
        }
        $ref = $this->validatedRef($object);
        $ctx = ksefDepositContext($this->db, $object);
        if (ksefKorZalRateRefusal($this->db, $object, $ctx) !== '') {
            $dep = new Facture($this->db);
            if ($dep->fetch((int) $ctx['root_id']) <= 0) {
                $this->error = "Cannot fetch deposit " . (int) $ctx['root_id'] . " of " . $ref;
                dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                return -1;
            }
            $dep->fetch_optionals();
            $own = 1 / (float) $object->multicurrency_tx;
            $object->fetch_lines();
            if ($object->setMulticurrencyRate((float) $dep->multicurrency_tx, 1) <= 0 || abs((float) $object->multicurrency_tx - (float) $dep->multicurrency_tx) > 1e-12) {
                $this->error = "Cannot set the deposit's rate on " . $ref . ": " . ($object->error ?: $this->db->lasterror());
                dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                return -1;
            }
            // core returns 1
            $resLeft = $this->db->query("SELECT COUNT(*) AS nb FROM " . MAIN_DB_PREFIX . "facturedet WHERE fk_facture = " . (int) $object->id
                . " AND ABS(total_ttc - multicurrency_total_ttc / " . number_format((float) $dep->multicurrency_tx, 10, '.', '') . ") > 0.05");
            $left = $resLeft ? $this->db->fetch_object($resLeft) : null;
            if (!$left || (int) $left->nb > 0) {
                $this->error = "Cannot recalculate the PLN amounts of " . $ref . " at the deposit's rate: " . ($left ? (int) $left->nb . " line(s) not updated " . $object->error : $this->db->lasterror());
                dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                return -1;
            }
            if (!empty($dep->array_options['options_ksef_kurs_data'])) {
                $object->array_options['options_ksef_kurs_data'] = $dep->array_options['options_ksef_kurs_data'];
                if ($object->updateExtraField('ksef_kurs_data') < 0) {
                    $this->error = "Cannot store the deposit's NBP table day on " . $ref . ": " . $object->error;
                    dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                    return -1;
                }
            }
            $langs->load('ksef@ksef');
            $fmt = function ($r) {
                return number_format((float) $r, 4, ',', '');
            };
            setEventMessages($langs->trans('KSEF_KorZalRateRestored', $ref, $dep->ref, $fmt($own), $fmt(1 / (float) $dep->multicurrency_tx)), null, 'warnings');
            dol_syslog("KsefTriggers: " . $ref . " takes the rate of deposit " . $dep->ref . " back at validation (" . $own . " -> " . (1 / (float) $dep->multicurrency_tx) . " PLN)", LOG_WARNING);
            return 1;
        }
        if (!in_array((int) $object->type, array(Facture::TYPE_REPLACEMENT, Facture::TYPE_CREDIT_NOTE), true)) {
            return 0;
        }
        $nbp = new KsefNbpCurrencyRate($this->db);
        $rc = $nbp->checkCorrectionRate($object);
        if ($rc['state'] === 'other') {
            setEventMessages($nbp->correctionRateWarning($rc, true), null, 'warnings');
            dol_syslog("KsefTriggers: " . $ref . " validated at the typed rate " . $rc['rate'] . " (source " . $rc['source_rate'] . ", NBP " . $rc['exact']['rate'] . " of " . $rc['exact']['date'] . ")", LOG_WARNING);
            return 0;
        }
        $day = $rc['state'] === 'source' ? (int) $rc['source_day'] : ($rc['state'] === 'exact' ? (int) dol_stringtotime((string) $rc['exact']['date']) : 0);
        $kurs = $object->array_options['options_ksef_kurs_data'] ?? '';
        $kursTs = is_numeric($kurs) ? (int) $kurs : (int) strtotime((string) $kurs);
        if ($day <= 0 || $kursTs <= 0 || dol_print_date($kursTs, '%Y-%m-%d') === dol_print_date($day, '%Y-%m-%d')) {
            return 0;
        }
        $object->array_options['options_ksef_kurs_data'] = $day;
        if ($object->updateExtraField('ksef_kurs_data') < 0) {
            $this->error = "Cannot store the NBP table day on " . $ref . ": " . $object->error;
            dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
            return -1;
        }
        dol_syslog("KsefTriggers: " . $ref . " NBP table day set to " . dol_print_date($day, '%Y-%m-%d') . " (rate of the " . $rc['state'] . " table)", LOG_INFO);
        return 1;
    }

    /**
     * @brief Add forgiven parts
     * @param array &$calc
     * @param float $room
     * @param float $roomMc Room, invoice currency
     * @return void
     */
    private function carryKeptShortfall(&$calc, $room, $roomMc)
    {
        if ($calc['keep'] > 0.005 || $calc['keep_mc'] > 0.005) {
            $calc['settled'] = (float) price2num(min($calc['settled'] + $calc['keep'], max($calc['settled'], $room)), 'MT');
            $calc['settled_mc'] = (float) price2num(min($calc['settled_mc'] + $calc['keep_mc'], max($calc['settled_mc'], $roomMc)), 'MT');
        }
    }

    /**
     * @brief Copy note lines
     * @param Facture $korekta
     * @param array $noteIds
     * @return int -1 on error
     */
    private function absorbNoteLines($korekta, $noteIds)
    {
        $res = $this->db->query("SELECT rowid, description, subprice, multicurrency_subprice, qty, tva_tx, vat_src_code, localtax1_tx, localtax2_tx, fk_product, remise_percent,"
            . " date_start, date_end, info_bits, product_type, special_code, buy_price_ht, fk_unit FROM " . MAIN_DB_PREFIX . "facturedet"
            . " WHERE fk_facture IN (" . implode(',', array_map('intval', $noteIds)) . ") AND fk_remise_except IS NULL ORDER BY fk_facture, rang, rowid");
        if (!$res) {
            $this->error = "Cannot read the lines of credit notes " . implode(',', $noteIds) . ": " . $this->db->lasterror();
            return -1;
        }
        $lines = array();
        while ($o = $this->db->fetch_object($res)) {
            $lines[] = $o;
        }
        foreach ($lines as $l) {
            $vat = (string) $l->tva_tx . ((string) $l->vat_src_code !== '' ? ' (' . $l->vat_src_code . ')' : '');
            $r = $korekta->addline($l->description, (float) $l->subprice, (float) $l->qty, $vat, (float) $l->localtax1_tx, (float) $l->localtax2_tx, (int) $l->fk_product,
                (float) $l->remise_percent, $this->db->jdate($l->date_start), $this->db->jdate($l->date_end), 0, (int) $l->info_bits, 0, 'HT', 0, (int) $l->product_type, -1,
                (int) $l->special_code, '', 0, 0, null, (float) $l->buy_price_ht, '', array(), 100, (int) $l->rowid, $l->fk_unit, (float) $l->multicurrency_subprice);
            if ($r <= 0) {
                $this->error = "Failed to copy credit note line " . (int) $l->rowid . " into " . $korekta->ref . ": " . $korekta->error;
                return -1;
            }
        }
        return 1;
    }

    /**
     * @brief Link free credit
     * @param array $rows
     * @param int $invoiceId Korekta id
     * @param float $room Amount still due
     * @param bool $foreign Use invoice currency
     * @return array|false
     */
    private function linkFreeCredit($rows, $invoiceId, $room, $foreign)
    {
        global $user;
        $out = array('ids' => array(), 'ttc' => 0.0, 'mttc' => 0.0);
        foreach ($rows as $o) {
            $amt = (float) ($foreign ? $o->multicurrency_amount_ttc : $o->amount_ttc);
            if ($room < 0.005 || $amt < 0.005) {
                break;
            }
            $d = new DiscountAbsolute($this->db);
            if ($d->fetch((int) $o->rowid) <= 0) {
                $this->error = "Cannot read credit " . (int) $o->rowid . ": " . $d->error;
                return false;
            }
            if ($amt - $room > 0.005) {
                $k = $room / $amt;
                $part = clone $d;
                foreach (array('amount_ht', 'amount_tva', 'amount_ttc', 'multicurrency_amount_ht', 'multicurrency_amount_tva', 'multicurrency_amount_ttc') as $col) {
                    $part->$col = (float) price2num((float) $d->$col * $k, 'MT');
                    $d->$col = (float) price2num((float) $d->$col - $part->$col, 'MT');
                }
                $part->id = 0;
                if ($part->create($user) <= 0 || !$this->db->query("UPDATE " . MAIN_DB_PREFIX . "societe_remise_except SET amount_ht = " . (float) $d->amount_ht . ", amount_tva = " . (float) $d->amount_tva
                    . ", amount_ttc = " . (float) $d->amount_ttc . ", multicurrency_amount_ht = " . (float) $d->multicurrency_amount_ht . ", multicurrency_amount_tva = " . (float) $d->multicurrency_amount_tva
                    . ", multicurrency_amount_ttc = " . (float) $d->multicurrency_amount_ttc . " WHERE rowid = " . (int) $d->id)) {
                    $this->error = "Failed to split credit " . (int) $d->id . ": " . $part->error . ' ' . $this->db->lasterror();
                    return false;
                }
                $d = $part;
            }
            if ($d->link_to_invoice(0, $invoiceId) < 0) {
                $this->error = "Failed to link credit " . (int) $d->id . " to invoice $invoiceId: " . $d->error;
                return false;
            }
            $out['ids'][] = (int) $d->id;
            $out['ttc'] += (float) $d->amount_ttc;
            $out['mttc'] += (float) $d->multicurrency_amount_ttc;
            $room -= (float) ($foreign ? $d->multicurrency_amount_ttc : $d->amount_ttc);
        }
        return $out;
    }

    /**
     * @brief Chain correction discount
     * @param User $user
     * @param Translate $langs
     * @param Conf $conf
     * @param Facture $member
     * @param Facture $source
     * @param array $calc
     * @return int -1 on error
     */
    private function createCorrectionDiscount($user, $langs, $conf, $member, $source, $calc)
    {
        require_once DOL_DOCUMENT_ROOT . '/core/class/discount.class.php';
        $langs->load("ksef@ksef");
        $settled = $calc['settled'];
        $settled_mc = $calc['settled_mc'];
        $rootInvoice = $calc['root'];

        $kind = ksefInvoiceKind($member);
        $discount = new DiscountAbsolute($this->db);
        $discount->socid = $member->socid;
        $discount->discount_type = $kind['discount_type'];
        $discount->amount_ttc = $settled;
        if ($rootInvoice->total_ttc != 0) {
            $discount->amount_ht = $settled * ($rootInvoice->total_ht / $rootInvoice->total_ttc);
        } else {
            $discount->amount_ht = $settled;
        }
        $discount->amount_tva = $settled - $discount->amount_ht;
        $discount->tva_tx = 0;
        if ($kind['discount_type']) {
            $discount->fk_invoice_supplier_source = $rootInvoice->id;
        } else {
            $discount->fk_facture_source = $rootInvoice->id;
        }
        $discount->description = $langs->trans('KSEF_CorrectionDiscountDescription', $source->ref);

        if (!empty($rootInvoice->multicurrency_code)
            && $rootInvoice->multicurrency_code != $conf->currency
            && abs($settled_mc) > 0.005) {
            $discount->multicurrency_amount_ttc = $settled_mc;
            if ($rootInvoice->multicurrency_total_ttc != 0) {
                $discount->multicurrency_amount_ht = $settled_mc * ($rootInvoice->multicurrency_total_ht / $rootInvoice->multicurrency_total_ttc);
            } else {
                $discount->multicurrency_amount_ht = $settled_mc;
            }
            $discount->multicurrency_amount_tva = $settled_mc - $discount->multicurrency_amount_ht;
            $discount->multicurrency_code = $rootInvoice->multicurrency_code;
            $discount->multicurrency_tx = $rootInvoice->multicurrency_tx;
        } elseif (empty($rootInvoice->multicurrency_code) || $rootInvoice->multicurrency_code == $conf->currency) {
            $discount->multicurrency_amount_ht = $discount->amount_ht;
            $discount->multicurrency_amount_tva = $discount->amount_tva;
            $discount->multicurrency_amount_ttc = $discount->amount_ttc;
            $discount->multicurrency_code = $conf->currency;
            $discount->multicurrency_tx = 1;
        }

        $discount_id = $discount->create($user);
        if ($discount_id <= 0) {
            $this->error = "Failed to create correction discount: " . $discount->error;
            dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
            return -1;
        }
        if ($discount->link_to_invoice(0, $member->id) < 0) {
            $this->error = "Failed to link correction discount $discount_id to invoice " . $member->id . ": " . $discount->error;
            dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
            return -1;
        }
        $member->array_options['options_ksef_correction_discount_id'] = $discount_id;
        $member->array_options['options_ksef_correction_settled_amount'] = $settled;
        $member->array_options['options_ksef_correction_settled_amount_mc'] = $settled_mc;
        if ($member->insertExtraFields() < 0) {
            $this->error = "Failed to store correction discount $discount_id on invoice " . $member->id . ": " . $member->error;
            dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
            return -1;
        }
        return $discount_id;
    }

    /**
     * @brief Excess free credit
     * @param User $user
     * @param Facture $member
     * @param Facture $root
     * @param float $amount Company currency
     * @param float $amountMc Invoice currency
     * @return int -1 on error
     */
    private function createExcessCredit($user, $member, $root, $amount, $amountMc)
    {
        global $conf;
        require_once DOL_DOCUMENT_ROOT . '/core/class/discount.class.php';
        $kind = ksefInvoiceKind($member);
        $credit = new DiscountAbsolute($this->db);
        $credit->socid = $member->socid;
        $credit->discount_type = $kind['discount_type'];
        $credit->amount_ttc = $credit->amount_ht = (float) price2num($amount, 'MT');
        $credit->amount_tva = 0;
        $credit->tva_tx = 0;
        if ($kind['discount_type']) {
            $credit->fk_invoice_supplier_source = $root->id;
            $credit->description = $kind['excess'];
        } else {
            $credit->fk_facture_source = $root->id;
            $credit->description = KSEF_EXCESS_RECEIVED_DESCRIPTION;
        }
        if ($amountMc > 0) {
            $credit->multicurrency_amount_ttc = $credit->multicurrency_amount_ht = (float) price2num($amountMc, 'MT');
            $credit->multicurrency_amount_tva = 0;
            $credit->multicurrency_code = $root->multicurrency_code;
            $credit->multicurrency_tx = $root->multicurrency_tx;
        } elseif (empty($root->multicurrency_code) || $root->multicurrency_code == $conf->currency) {
            $credit->multicurrency_amount_ttc = $credit->multicurrency_amount_ht = $credit->amount_ttc;
            $credit->multicurrency_amount_tva = 0;
            $credit->multicurrency_code = $conf->currency;
            $credit->multicurrency_tx = 1;
        }
        $id = $credit->create($user);
        if ($id <= 0) {
            $this->error = "Failed to create the excess credit of " . $member->ref . ": " . $credit->error;
            dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
            return -1;
        }
        return (int) $id;
    }

    /**
     * @brief Restore replacement currency
     * @param int $replacementId
     * @param Facture $source
     * @return int -1 on error
     */
    private function restoreReplacementCurrency($replacementId, $source)
    {
        $copy = new Facture($this->db);
        if ($copy->fetch($replacementId) <= 0 || $copy->fetch_lines() < 0 || $source->fetch_lines() < 0) {
            $this->error = "Cannot read replacement $replacementId or its source " . $source->ref . ": " . $copy->error . ' ' . $source->error;
            dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
            return -1;
        }
        $srcLines = array();
        foreach ($source->lines as $srcLine) {
            $srcLines[(int) $srcLine->id] = $srcLine;
        }
        $exact = count($copy->lines) === count($source->lines);
        foreach ($copy->lines as $copyLine) {
            $exact = $exact && isset($srcLines[(int) $copyLine->fk_prev_id]);
        }
        $P = MAIN_DB_PREFIX;
        $mcSet = "fk_multicurrency = " . ((int) $source->fk_multicurrency) . ", multicurrency_code = '" . $this->db->escape($source->multicurrency_code) . "'";
        $ok = (bool) $this->db->query("UPDATE " . $P . "facture SET " . $mcSet . " WHERE rowid = " . ((int) $replacementId));
        if ($ok && $exact) {
            $cols = array('subprice', 'total_ht', 'total_tva', 'total_ttc', 'multicurrency_subprice', 'multicurrency_total_ht', 'multicurrency_total_tva', 'multicurrency_total_ttc');
            foreach ($copy->lines as $copyLine) {
                $s = $srcLines[(int) $copyLine->fk_prev_id];
                $set = array($mcSet);
                foreach ($cols as $c) {
                    $set[] = $c . " = " . (is_numeric($s->$c) ? (string) $s->$c : '0');
                }
                $ok = $ok && $this->db->query("UPDATE " . $P . "facturedet SET " . implode(', ', $set) . " WHERE rowid = " . ((int) $copyLine->id));
            }
            $set = array("multicurrency_tx = " . (is_numeric($source->multicurrency_tx) ? (string) $source->multicurrency_tx : '1'));
            foreach (array('total_ht', 'total_tva', 'total_ttc', 'multicurrency_total_ht', 'multicurrency_total_tva', 'multicurrency_total_ttc') as $c) {
                $set[] = $c . " = " . (is_numeric($source->$c) ? (string) $source->$c : '0');
            }
            $ok = $ok && $this->db->query("UPDATE " . $P . "facture SET " . implode(', ', $set) . " WHERE rowid = " . ((int) $replacementId));
        } elseif ($ok) {
            $copy->multicurrency_code = $source->multicurrency_code;
            foreach ($copy->lines as $copyLine) {
                $prev = (int) $copyLine->fk_prev_id;
                $copyLine->multicurrency_subprice = isset($srcLines[$prev])
                    ? $srcLines[$prev]->multicurrency_subprice
                    : (float) $copyLine->subprice * (float) $source->multicurrency_tx;
            }
            $ok = $copy->setMulticurrencyRate((float) $source->multicurrency_tx, 1) > 0;
        }
        if (!$ok) {
            $this->error = "Failed to give replacement $replacementId the currency of " . $source->ref . ": " . $this->db->lasterror() . ' ' . $copy->error;
            dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
            return -1;
        }
        return 1;
    }

    /**
     * @brief Open stock rows
     * @param Facture|FactureFournisseur $inv
     * @param bool $supplier
     * @return array|false
     */
    private function correctionStockSlots($inv, $supplier)
    {
        dol_include_once('/ksef/lib/ksef.lib.php');
        $slots = ksefCorrectionStockSlots($this->db, $inv, $supplier);
        if ($slots === false) {
            $this->error = "Cannot read the stock movements of " . $inv->ref . ": " . $this->db->lasterror();
            dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
        }
        return $slots;
    }

    /**
     * @brief Compensate korekta stock
     * @param User $user
     * @param Translate $langs
     * @param Facture|FactureFournisseur $korekta
     * @param Facture|FactureFournisseur $source
     * @param array $absorbed
     * @return array|false
     */
    private function compensateCorrectionStock($user, $langs, $korekta, $source, $absorbed = array())
    {
        $supplier = $korekta->element === 'invoice_supplier';
        if (!isModEnabled('stock') || !getDolGlobalString($supplier ? 'STOCK_CALCULATE_ON_SUPPLIER_BILL' : 'STOCK_CALCULATE_ON_BILL') || !empty($GLOBALS['ksef_import_no_stock'])) {
            return array();
        }
        $slots = $this->correctionStockSlots($source, $supplier);
        $own = $slots === false ? false : $this->correctionStockSlots($korekta, $supplier);
        if ($own === false) {
            return false;
        }
        if (!$slots || (!$own && $korekta->hasProductsOrServices(getDolGlobalString('STOCK_SUPPORTS_SERVICES') ? 1 : 2))) {
            return array();
        }
        if (empty($source->lines) && $source->fetch_lines() < 0) {
            $this->error = "Cannot read the lines of " . $source->ref . ": " . $source->error;
            dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
            return false;
        }
        require_once DOL_DOCUMENT_ROOT . '/product/stock/class/mouvementstock.class.php';
        require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
        $langs->load('ksef@ksef');
        $label = $langs->transnoentitiesnoconv($supplier ? 'KSEF_CorrectionStockSourceBackSupplier' : 'KSEF_CorrectionStockSourceBack', $this->validatedRef($korekta), $source->ref);
        $unit = function ($line) {
            $price = (float) $line->subprice;
            return (!empty($line->remise_percent) && !getDolGlobalString('STOCK_EXCLUDE_DISCOUNT_FOR_PMP')) ? (float) price2num($price * (100 - $line->remise_percent) / 100, 'MU') : $price;
        };
        $prods = array();
        foreach ($source->lines as $line) {
            $p = (int) $line->fk_product;
            if (isset($slots[$p]) && !isset($prods[$p])) {
                $prod = new Product($this->db);
                if ($prod->fetch($p) <= 0) {
                    $this->error = "Cannot read product $p: " . $prod->error;
                    dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                    return false;
                }
                $kin = 0;
                foreach (!empty($own[$p]) ? $own[$p] : array() as $s) {
                    $kin += $s['q'];
                }
                $prods[$p] = array('lot' => isModEnabled('productbatch') && $prod->hasbatch(), 'before' => (float) $prod->stock_reel - $kin, 'need' => 0,
                    's1' => (float) $prod->stock_reel, 'v1' => (float) $prod->stock_reel * (float) $prod->pmp, 'sval' => 0.0);
            }
            if (isset($prods[$p]) && (float) $line->qty > 0) {
                $prods[$p]['need'] += (float) $line->qty;
                $prods[$p]['sval'] += (float) $line->qty * $unit($line);
            }
        }
        foreach ($supplier ? $prods : array() as $p => $pr) {
            $whs = array();
            foreach ($slots[$p] as $sl) {
                $whs[$sl['w']] = 0.0;
            }
            foreach (!empty($own[$p]) ? $own[$p] : array() as $sl) {
                if (isset($whs[$sl['w']])) {
                    $whs[$sl['w']] -= $sl['q'];
                }
            }
            $res = $this->db->query("SELECT fk_entrepot, reel FROM " . MAIN_DB_PREFIX . "product_stock WHERE fk_product = " . (int) $p . " AND fk_entrepot IN (" . implode(',', array_map('intval', array_keys($whs))) . ")");
            while ($res && ($o = $this->db->fetch_object($res))) {
                $whs[(int) $o->fk_entrepot] += (float) $o->reel;
            }
            $kq = $kv = 0.0;
            foreach ($korekta->lines as $kl) {
                if ((int) $kl->fk_product === $p) {
                    $kq += (float) $kl->qty;
                    $kv += (float) $kl->qty * $unit($kl);
                }
            }
            $a = max(0.0, min(array_sum($whs), $pr['need']));
            $ps = $pr['need'] > 0 ? $pr['sval'] / $pr['need'] : 0.0;
            $x = $pr['need'] > 0 ? ($a * $ps + ($pr['need'] - $a) * ($kq > 0 ? $kv / $kq : $ps)) / $pr['need'] : 0.0;
            $prods[$p]['x'] = (float) price2num(max(0.0, min($x, $pr['v1'] / max($pr['need'], 1e-9))), 'MU');
            $prods[$p]['input'] = $pr['s1'] - $pr['need'] > 1e-9;
            $prods[$p]['v0'] = $pr['before'] > 1e-9 ? $pr['v1'] - $kv : null;
            $prods[$p]['kq'] = $kq;
        }
        $moves = array();
        foreach ($source->lines as $line) {
            $p = (int) $line->fk_product;
            $qty = (float) $line->qty;
            if (!isset($prods[$p]) || $qty == 0 || ((int) $line->product_type !== 0 && !getDolGlobalString('STOCK_SUPPORTS_SERVICES'))) {
                continue;
            }
            $parts = array();
            if (!$prods[$p]['lot'] || $qty < 0) {
                $parts[] = array($slots[$p][0]['w'], '', $qty);
            } else {
                $left = $qty;
                foreach ($slots[$p] as $i => $s) {
                    $take = min($left, $s['q']);
                    if ($s['b'] === '' || $take <= 0) {
                        continue;
                    }
                    $parts[] = array($s['w'], $s['b'], $take);
                    $slots[$p][$i]['q'] -= $take;
                    $left -= $take;
                }
                if ($left > 1e-9) {
                    $last = end($slots[$p]);
                    $parts[] = array($last['w'], $last['b'], $left);
                }
            }
            $input = $supplier && $prods[$p]['input'];
            $price = $input ? $prods[$p]['x'] : ($supplier ? $unit($line) : 0);
            foreach ($parts as $part) {
                list($w, $b, $q) = $part;
                $mv = new MouvementStock($this->db);
                $mv->setOrigin($korekta->element, $korekta->id);
                // keeps PMP value
                if ($input) {
                    $r = $mv->_create($user, $p, $w, -$q, 3, $price, $label, '', '', '', '', $b);
                } elseif ($supplier) {
                    $r = $mv->livraison($user, $p, $w, $q, $price, $label, '', '', '', $b);
                } else {
                    $r = $mv->reception($user, $p, $w, $q, 0, $label, '', '', $b);
                }
                if ($r < 0) {
                    $this->error = "Failed to move back the stock of " . $source->ref . " for " . $korekta->ref . ": " . ($mv->error ?: implode(', ', $mv->errors));
                    dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                    return false;
                }
                $moves[] = array('p' => $p, 'w' => $w, 'q' => $q, 'pr' => $price, 'b' => $b);
            }
        }
        foreach ($moves as $i => $m) {
            if (!$supplier || $prods[$m['p']]['v0'] === null) {
                continue;
            }
            if (!isset($prods[$m['p']]['dv'])) {
                $res = $this->db->query("SELECT stock, pmp FROM " . MAIN_DB_PREFIX . "product WHERE rowid = " . (int) $m['p']);
                $o = $res ? $this->db->fetch_object($res) : null;
                if (!$o) {
                    $this->error = "Cannot read the stock of product " . $m['p'] . ": " . $this->db->lasterror();
                    dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                    return false;
                }
                $prods[$m['p']]['dv'] = (float) price2num($prods[$m['p']]['v0'] - (float) $o->stock * (float) $o->pmp, 'MT');
                $prods[$m['p']]['ks'] = (float) $o->stock;
                $res = $this->db->query("SELECT MAX(rowid) AS m FROM " . MAIN_DB_PREFIX . "stock_mouvement WHERE fk_product = " . (int) $m['p']);
                $o = $res ? $this->db->fetch_object($res) : null;
                $prods[$m['p']]['km'] = $o ? (int) $o->m : 0;
            }
            $moves[$i]['dv'] = $prods[$m['p']]['dv'];
            $moves[$i]['kq'] = $prods[$m['p']]['kq'];
            $moves[$i]['ks'] = $prods[$m['p']]['ks'];
            $moves[$i]['km'] = $prods[$m['p']]['km'];
        }
        $noteLines = array();
        $res = $absorbed ? $this->db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "facturedet WHERE fk_facture IN (" . implode(',', array_map('intval', $absorbed)) . ")") : false;
        while ($res && ($o = $this->db->fetch_object($res))) {
            $noteLines[(int) $o->rowid] = true;
        }
        foreach ($noteLines ? $korekta->lines : array() as $line) {
            $p = (int) $line->fk_product;
            if (!isset($noteLines[(int) $line->fk_prev_id]) || empty($own[$p]) || (float) $line->qty <= 0) {
                continue;
            }
            $mv = new MouvementStock($this->db);
            $mv->setOrigin($korekta->element, $korekta->id);
            if ($mv->reception($user, $p, $own[$p][0]['w'], (float) $line->qty, 0, $label, '', '', $own[$p][0]['b']) < 0) {
                $this->error = "Failed to move back the returned goods of " . $korekta->ref . ": " . ($mv->error ?: implode(', ', $mv->errors));
                dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                return false;
            }
            $moves[] = array('p' => $p, 'w' => $own[$p][0]['w'], 'q' => (float) $line->qty, 'pr' => 0, 'b' => $own[$p][0]['b']);
        }
        return $moves;
    }

    /**
     * @brief Delete restock warehouse
     * @return int -1 if none
     */
    private function deleteWarehouse()
    {
        return $this->coreWarehouse('Facture', 'delete', 2);
    }

    /**
     * @brief Caller warehouse argument
     * @param string $class
     * @param string $function
     * @param int $arg Argument position
     * @return int -1 if none
     */
    private function coreWarehouse($class, $function, $arg)
    {
        foreach (debug_backtrace(0, 20) as $f) {
            if ($f['function'] === $function && isset($f['class']) && is_a($f['class'], $class, true)) {
                return isset($f['args'][$arg]) ? (int) $f['args'][$arg] : -1;
            }
        }
        return -1;
    }

    /**
     * @brief Unreversed korekta stock
     * @param Facture|FactureFournisseur $korekta
     * @param array $state
     * @param bool $delete Is BILL_DELETE
     * @param array $incoming
     * @return array|false
     */
    private function correctionStockSkipped($korekta, $state, $delete, &$incoming)
    {
        $supplier = $korekta->element === 'invoice_supplier';
        $w = $delete ? $this->deleteWarehouse() : $this->coreWarehouse($supplier ? 'FactureFournisseur' : 'Facture', 'setDraft', 1);
        $skip = ksefCorrectionStockSkipped($this->db, $korekta, $state, $delete, $w, $incoming);
        if ($skip === false) {
            $this->error = "Cannot read the stock movements of " . $korekta->ref . ": " . $this->db->lasterror();
        }
        return $skip;
    }

    /**
     * @brief Undo korekta stock
     * @param User $user
     * @param Translate $langs
     * @param Facture|FactureFournisseur $korekta
     * @param array $moves
     * @param array $skip
     * @param array $incoming
     * @return int -1 on error
     */
    private function revertCorrectionStock($user, $langs, $korekta, $moves, $skip = array(), $incoming = array())
    {
        global $conf;
        require_once DOL_DOCUMENT_ROOT . '/product/stock/class/mouvementstock.class.php';
        $langs->load('ksef@ksef');
        $supplier = $korekta->element === 'invoice_supplier';
        $label = $langs->transnoentitiesnoconv('KSEF_CorrectionStockSourceBackUndo', $korekta->ref);
        list($cut, $cutP) = ksefCorrectionStockCut($moves, $skip);
        $short = $force = array();
        if (!$supplier && getDolGlobalInt('STOCK_DISALLOW_NEGATIVE_TRANSFER')) {
            $sh = ksefCorrectionStockShort($this->db, $moves, $cut, $incoming);
            if ($sh === false) {
                $this->error = "Cannot read the stock to undo the stock compensation of " . $korekta->ref . ": " . $this->db->lasterror();
                return -1;
            }
            if ($sh['rows'] && empty($GLOBALS['ksef_stock_short_ok'][(int) $korekta->id])) {
                $this->error = $langs->transnoentitiesnoconv('KSEF_CorrectionStockUndoShortRefused', $korekta->ref, ksefCorrectionStockShortText($this->db, $langs, $sh['rows']));
                $this->errors[] = $this->error;
                dol_syslog("KsefTriggers: stock undo of " . $korekta->ref . " refused: " . $this->error, LOG_WARNING);
                return -1;
            }
            $cut = $sh['cut'];
            $force = $sh['force'];
            $short = $sh['short'];
            $shortText = $sh['rows'] ? ksefCorrectionStockShortText($this->db, $langs, $sh['rows']) : '';
        }
        $supText = '';
        if ($supplier && getDolGlobalInt('STOCK_DISALLOW_NEGATIVE_TRANSFER')) {
            $rows = ksefCorrectionStockSupplierShort($this->db, $korekta, $moves, $skip, array());
            if ($rows === false) {
                $this->error = "Cannot read the stock to undo the stock compensation of " . $korekta->ref . ": " . $this->db->lasterror();
                return -1;
            }
            if ($rows) {
                $supText = ksefCorrectionStockShortText($this->db, $langs, $rows);
                if (empty($GLOBALS['ksef_stock_short_ok'][(int) $korekta->id])) {
                    $this->error = $langs->transnoentitiesnoconv('KSEF_CorrectionStockUndoSkippedRefused', $korekta->ref, $supText);
                    $this->errors[] = $this->error;
                    dol_syslog("KsefTriggers: stock undo of " . $korekta->ref . " refused: " . $this->error, LOG_WARNING);
                    return -1;
                }
            }
        }
        $back = array();
        foreach ($supplier ? ksefCorrectionStockBack($this->db, $korekta, $moves, $cutP) : array() as $p => $bk) {
            $back[$p] = $bk['r'];
        }
        foreach ($moves as $i => $m) {
            $b = isset($m['b']) ? (string) $m['b'] : '';
            $q = (float) $m['q'] - ($cut[$i] ?? 0);
            if (abs($q) < 1e-9) {
                continue;
            }
            $mv = new MouvementStock($this->db);
            $mv->setOrigin($korekta->element, $korekta->id);
            $noNeg = $conf->global->STOCK_DISALLOW_NEGATIVE_TRANSFER ?? null;
            if (!empty($force[$i])) {
                $conf->global->STOCK_DISALLOW_NEGATIVE_TRANSFER = 0;
            }
            $r = $supplier
                ? $mv->reception($user, (int) $m['p'], (int) $m['w'], $q, $back[(int) $m['p']] ?? (float) ($m['pr'] ?? 0), $label, '', '', $b)
                : $mv->livraison($user, (int) $m['p'], (int) $m['w'], $q, (float) ($m['pr'] ?? 0), $label, '', '', '', $b);
            if (!empty($force[$i])) {
                $conf->global->STOCK_DISALLOW_NEGATIVE_TRANSFER = $noNeg;
            }
            if ($r < 0) {
                $this->error = "Failed to undo the stock compensation of " . $korekta->ref . ": " . ($mv->error ?: implode(', ', $mv->errors));
                dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                return -1;
            }
        }
        $why = array('neg' => array(), 'none' => array(), 'short' => $short);
        foreach ($cutP as $p => $q) {
            $why[$skip[$p]['why'] ?? 'none'][$p] = $q;
        }
        $keys = array('neg' => 'KSEF_CorrectionStockUndoSkipped', 'none' => 'KSEF_CorrectionStockUndoNoCore', 'short' => 'KSEF_CorrectionStockUndoShort');
        foreach ($why as $cause => $qs) {
            if (!$qs) {
                continue;
            }
            $refs = array();
            $res = $this->db->query("SELECT ref FROM " . MAIN_DB_PREFIX . "product WHERE rowid IN (" . implode(',', array_map('intval', array_keys($qs))) . ") ORDER BY ref");
            while ($res && ($o = $this->db->fetch_object($res))) {
                $refs[] = $o->ref;
            }
            if ($cause === 'short') {
                $msg = $langs->trans($keys[$cause], $korekta->ref, $shortText);
            } elseif ($cause === 'neg' && $supText !== '') {
                $msg = $langs->trans('KSEF_CorrectionStockUndoSkippedOverride', $korekta->ref, $supText);
            } else {
                $msg = $langs->trans($keys[$cause], $korekta->ref, implode(', ', $refs));
            }
            setEventMessages($msg, null, 'warnings');
            dol_syslog("KsefTriggers: stock compensation of " . $korekta->ref . " not undone ($cause) for " . json_encode($qs), LOG_WARNING);
        }
        return 1;
    }

    /**
     * @brief Restate deposit credit
     * @param User $user
     * @param int $invoiceId Deposit or chain member
     * @param int $excludeId
     * @param bool $convert Create missing credit
     * @param int $excludePayment
     * @param string $element
     * @return int -1 on error
     */
    private function restateDeposit($user, $invoiceId, $excludeId = 0, $convert = false, $excludePayment = 0, $element = 'facture')
    {
        dol_include_once('/ksef/lib/ksef.lib.php');
        $depId = ksefDepositRootId($this->db, (int) $invoiceId, $element);
        if ($depId <= 0) {
            return 0;
        }
        $rs = ksefDepositRestate($this->db, $user, $depId, (int) $excludeId, $convert, (int) $excludePayment, $element);
        if ($rs['result'] < 0) {
            $this->error = "Failed to restate the credit of deposit $depId: " . $rs['error'];
            $this->errors[] = $this->error;
            dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
            return -1;
        }
        return 0;
    }

    /**
     * @brief Restate ROZ deposits
     * @param User $user
     * @param int $rozId
     * @param bool $drop Drop cover first
     * @return int -1 on error
     */
    private function restateCoveredDeposits($user, $rozId, $drop)
    {
        dol_include_once('/ksef/lib/ksef.lib.php');
        $deps = ksefDepositsCoveredBy($this->db, (int) $rozId);
        if ($deps === false) {
            $this->error = "Cannot find the deposits covered by settlement $rozId: " . $this->db->lasterror();
            $this->errors[] = $this->error;
            dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
            return -1;
        }
        foreach ($deps as $depId) {
            if ($drop && !ksefDepositCoverSet($this->db, $depId, (int) $rozId, array())) {
                $this->error = "Failed to remove the cover of deposit $depId by settlement $rozId: " . $this->db->lasterror();
                $this->errors[] = $this->error;
                dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                return -1;
            }
            if ($this->restateDeposit($user, $depId, $drop ? (int) $rozId : 0, false, 0, 'invoice_supplier') < 0) {
                return -1;
            }
        }
        return 0;
    }

    /**
     * @brief Drop unused credit
     * @param int $replId
     * @param array $state
     * @param array $kind
     * @return int -1 on error
     */
    private function undoDepositConversion($replId, $state, $kind)
    {
        $conv = array_filter(array_map('intval', (array) ($state['conv'] ?? array())));
        if ($conv && !$this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "societe_remise_except WHERE rowid IN (" . implode(',', $conv) . ") AND " . $kind['col'] . " IS NULL AND " . $kind['col_line'] . " IS NULL")) {
            $this->error = "Failed to remove the deposit credit created by replacement $replId: " . $this->db->lasterror();
            dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
            return -1;
        }
        return 1;
    }

    /**
     * @brief Handle trigger events
     * @param string $action
     * @param CommonObject $object
     * @param User $user
     * @param Translate $langs
     * @param Conf $conf
     * @return int -1 on error
     */
    public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
    {
        if (!isModEnabled('ksef')) {
            return 0;
        }
        if (strpos($action, 'BILL_SUPPLIER_') === 0 && !class_exists('Facture')) {
            require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
        }

        switch ($action) {

            case 'BILL_CREATE':
                $object->fetch_optionals();
                if ($this->copyDepositRate($object) < 0) {
                    return -1;
                }
                if (!empty($object->context['createfromclone'])) {
                    $copied = array('ksef_number', 'ksef_status', 'ksef_submission_date', 'ksef_send_skipped',
                        'ksef_correction_original_ht', 'ksef_correction_original_tva', 'ksef_correction_original_ttc',
                        'ksef_correction_discount_id', 'ksef_correction_settled_amount', 'ksef_correction_settled_amount_mc',
                        'ksef_correction_original_discount_ids', 'ksef_correction_state');
                    if ($object->context['createfromclone'] === 'createfromclone') {
                        $copied[] = 'ksef_kurs_data';
                    }
                    $this->clearCopiedKsefState($object, $copied);
                }
                // createFromClone flag only
                $isClone = ($object->context['createfromclone'] ?? null) === 'createfromclone';
                if ($isClone || empty($object->array_options['options_ksef_sale_date'])) {
                    $sale_date = null;
                    $saleDateSource = getDolGlobalString('KSEF_FA3_SALE_DATE_SOURCE', 'delivery_date');

                    if ($saleDateSource === 'delivery_date') {
                        $object->fetchObjectLinked(null, '', null, '', 'OR', 1, 'sourcetype', 1);

                        foreach (array('expedition', 'shipping') as $shipment_key) {
                            if (!empty($object->linkedObjects[$shipment_key])) {
                                foreach ($object->linkedObjects[$shipment_key] as $shipment) {
                                    if (!empty($shipment->date_delivery)) {
                                        $sale_date = $shipment->date_delivery;
                                        break 2;
                                    }
                                    if (!empty($shipment->date_expedition)) {
                                        $sale_date = $shipment->date_expedition;
                                        break 2;
                                    }
                                }
                            }
                        }

                        if (empty($sale_date) && !empty($object->linkedObjects['commande'])) {
                            foreach ($object->linkedObjects['commande'] as $order) {
                                if (!empty($order->delivery_date)) {
                                    $sale_date = $order->delivery_date;
                                    break;
                                }
                                if (!empty($order->date_livraison)) {
                                    $sale_date = $order->date_livraison;
                                    break;
                                }
                            }
                            if (empty($sale_date)) {
                                foreach ($object->linkedObjects['commande'] as $order) {
                                    if (!empty($order->date_commande)) {
                                        $sale_date = $order->date_commande;
                                        break;
                                    }
                                    if (!empty($order->date)) {
                                        $sale_date = $order->date;
                                        break;
                                    }
                                }
                            }
                        }
                    }
                    if (!empty($sale_date)) {
                        $object->array_options['options_ksef_sale_date'] = $sale_date;
                        $object->insertExtraFields();
                    } elseif ($isClone && !empty($object->array_options['options_ksef_sale_date'])) {
                        $object->array_options['options_ksef_sale_date'] = '';
                        $object->insertExtraFields();
                    }
                }

                $is_kor = ($object->type == Facture::TYPE_CREDIT_NOTE
                    || $object->type == Facture::TYPE_REPLACEMENT);
                if ($is_kor) {
                    $changed = false;

                    $default_reason = getDolGlobalString('KSEF_DEFAULT_CORRECTION_REASON', '');
                    $needs_reason = !empty($default_reason) && (
                        empty($object->array_options['options_ksef_correction_reason'])
                        || $object->type == Facture::TYPE_REPLACEMENT
                    );
                    if ($needs_reason) {
                        $object->array_options['options_ksef_correction_reason'] = $default_reason;
                        $changed = true;
                    }

                    $default_type = getDolGlobalString('KSEF_DEFAULT_CORRECTION_TYPE', '');
                    $needs_type = !empty($default_type) && (
                        empty($object->array_options['options_ksef_correction_type'])
                        || $object->type == Facture::TYPE_REPLACEMENT
                    );
                    if ($needs_type) {
                        $object->array_options['options_ksef_correction_type'] = $default_type;
                        $changed = true;
                    }

                    if ($changed) {
                        $object->insertExtraFields();
                    }
                }

                if ($is_kor && !empty($object->fk_facture_source) && $this->copySourcePodmiot3($object) < 0) {
                    return -1;
                }

                if ($object->type == Facture::TYPE_REPLACEMENT && !empty($object->fk_facture_source)) {
                    $disc_ids = $this->invoiceDiscountIds((int) $object->fk_facture_source);
                    if (!empty($disc_ids)) {
                        $object->array_options['options_ksef_correction_original_discount_ids'] = implode(',', $disc_ids);
                        $object->insertExtraFields();
                        dol_syslog("KsefTriggers: BILL_CREATE - Captured " . count($disc_ids) . " discount IDs from original " . $object->fk_facture_source, LOG_INFO);
                    }
                }

                // drops source currency
                if ($object->type == Facture::TYPE_REPLACEMENT && !empty($object->fk_facture_source)
                    && !empty($object->context['createfromclone']) && isModEnabled('multicurrency')) {
                    $mcSource = new Facture($this->db);
                    if ($mcSource->fetch((int) $object->fk_facture_source) > 0
                        && !empty($mcSource->multicurrency_code) && $mcSource->multicurrency_code != $conf->currency
                        && (empty($object->multicurrency_code) || $object->multicurrency_code == $conf->currency)) {
                        if ($this->restoreReplacementCurrency((int) $object->id, $mcSource) < 0) {
                            $this->errors[] = $this->error;
                            return -1;
                        }
                        $object->fk_multicurrency = $mcSource->fk_multicurrency;
                        $object->multicurrency_code = $mcSource->multicurrency_code;
                        $object->multicurrency_tx = $mcSource->multicurrency_tx;
                        dol_syslog("KsefTriggers: BILL_CREATE - replacement " . $object->ref . " in " . $mcSource->multicurrency_code . " at " . $mcSource->multicurrency_tx . " like " . $mcSource->ref, LOG_INFO);
                    }
                }

                $korChoice = $GLOBALS['ksef_kor_choice'] ?? null;
                if ($object->type == Facture::TYPE_REPLACEMENT && !empty($object->fk_facture_source) && is_array($korChoice)) {
                    dol_include_once('/ksef/lib/ksef.lib.php');
                    $korNotes = ksefCorrectionNotes($this->db, (int) $object->fk_facture_source);
                    foreach ($korChoice['base'] === 'a' && $korNotes ? $korNotes : array() as $n) {
                        if ($n['corrected'] !== '') {
                            $langs->load('ksef@ksef');
                            $this->error = $langs->transnoentitiesnoconv('KSEF_AbsorbNoteCorrected', $object->ref, $n['ref'], $n['corrected']);
                            $this->errors[] = $this->error;
                            dol_syslog("KsefTriggers: BILL_CREATE of " . $object->ref . " refused: credit note " . $n['ref'] . " has the korekta " . $n['corrected'], LOG_WARNING);
                            return -1;
                        }
                    }
                    if ($korNotes === false ||($korNotes && $korChoice['base'] === 'a' && $this->absorbNoteLines($object, array_keys($korNotes)) < 0)) {
                        $this->error = $this->error ?: "Cannot read the credit notes of invoice " . (int) $object->fk_facture_source . ": " . $this->db->lasterror();
                        $this->errors[] = $this->error;
                        dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                        return -1;
                    }
                    if ($korNotes && !ksefDepositStateSet($this->db, (int) $object->id, $korChoice['base'] === 'a' ? array('absorb' => array_keys($korNotes)) : array('cn_use' => $korChoice['credit'] === 'use' ? 1 : 0))) {
                        $this->error = "Failed to store the credit note choice of " . $object->ref . ": " . $this->db->lasterror();
                        $this->errors[] = $this->error;
                        dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                        return -1;
                    }
                }

                if ($object->type == Facture::TYPE_REPLACEMENT && !empty($object->fk_facture_source)) {
                    dol_include_once('/ksef/lib/ksef.lib.php');
                    if (!ksefZeroDiscountLines($this->db, $user, (int) $object->id, false)) {
                        $this->error = "Failed to copy the 0.00 discount lines of invoice " . (int) $object->fk_facture_source . " to " . $object->ref . ": " . $this->db->lasterror();
                        $this->errors[] = $this->error;
                        dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                        return -1;
                    }
                    $pre = ksefSettlementPrefill($this->db, (int) $object->id, (int) $object->fk_facture_source);
                    if ($pre['result'] < 0) {
                        $this->error = $pre['error'];
                        $this->errors[] = $this->error;
                        return -1;
                    }
                    if ($pre['changed'] || $pre['missing']) {
                        $langs->load('ksef@ksef');
                        foreach ($pre['changed'] as $c) {
                            setEventMessages($langs->trans('KSEF_SettlementCorrectionPrefilled', $c['ref'], price($c['to'], 0, $langs, 1, -1, -1, $c['currency']),
                                price($c['from'], 0, $langs, 1, -1, -1, $c['currency'])), null, 'warnings');
                        }
                        foreach ($pre['missing'] as $m) {
                            setEventMessages($langs->trans('KSEF_SettlementCorrectionRateMissing', $m['ref'], ksefDepositRateLabel($m['rate'])), null, 'warnings');
                        }
                    }
                }
                return 0;

            case 'BILL_SUPPLIER_CREATE':
                // clones only
                if (($object->context['createfromclone'] ?? null) === 'createfromclone') {
                    $object->fetch_optionals();
                    $this->clearCopiedKsefState($object, array('ksef_number', 'ksef_status', 'ksef_submission_date'));
                }
                return 0;

            case 'BILL_VALIDATE':
            case 'BILL_SUPPLIER_VALIDATE':
                $object->fetch_optionals();
                dol_include_once('/ksef/lib/ksef.lib.php');
                if ($object->element === 'facture' && empty($GLOBALS['ksef_importing_sales']) && $this->redateEarlyCorrection($object) < 0) {
                    $this->errors[] = $this->error;
                    dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                    return -1;
                }
                if ($object->element === 'facture' && (int) $object->type === Facture::TYPE_STANDARD && ksefAdoptOrderDepositLines($this->db, (int) $object->id) < 0) {
                    $this->error = "Cannot put the deposit credits on " . $object->ref . ": " . $this->db->lasterror();
                    $this->errors[] = $this->error;
                    return -1;
                }
                if ($object->element === 'facture') {
                    if (ksefFxRulesStamp($this->db, $object) < 0) {
                        $this->error = "Cannot record the PLN rules of " . $object->ref . ": " . $this->db->lasterror();
                        $this->errors[] = $this->error;
                        return -1;
                    }
                    // NBP never blocks
                    $langs->load('ksef@ksef');
                    $lineRates = ksefLineKursRefresh($this->db, $object, 0, array('nbp_soft' => true));
                    if ($lineRates['result'] >= 0) {
                        $typedRates = ksefLineKursFillCorrection($this->db, $object, array('nbp_soft' => true));
                        $lineRates = $typedRates['result'] < 0 || $typedRates['nbp'] ? $typedRates : $lineRates;
                    }
                    if ($lineRates['result'] < 0) {
                        $this->error = $langs->transnoentitiesnoconv('KSEF_LineRatesFailed', $object->ref, $lineRates['error']);
                        $this->errors[] = $this->error;
                        dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                        return -1;
                    }
                    if (!empty($lineRates['nbp'])) {
                        setEventMessages($langs->trans('KSEF_LineRatesNbpPending', $object->ref), null, 'warnings');
                    }
                    $derived = ksefDerivePlnFromForeign($this->db, (int) $object->id);
                    if ($derived['result'] < 0) {
                        $this->error = "Cannot derive the PLN amounts of " . $object->ref . ": " . $derived['error'];
                        $this->errors[] = $this->error;
                        dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                        return -1;
                    }
                    $moved = $derived['result'];
                } elseif (!empty($GLOBALS['ksef_importing_purchase_pln']) && (int) $GLOBALS['ksef_importing_purchase_pln'] === (int) $object->id) {
                    $moved = 0;
                } else {
                    $perBucket = ksefSupplierPlnPerBucket($this->db, (int) $object->id);
                    $moved = $perBucket['result'];
                    if ($moved < 0) {
                        dol_syslog("KsefTriggers: PLN amounts of " . $object->ref . ": " . $perBucket['error'], LOG_ERR);
                    }
                }
                $resTot = $moved > 0 ? $this->db->query("SELECT total_ht, total_tva, total_ttc, multicurrency_total_ht, multicurrency_total_tva, multicurrency_total_ttc FROM "
                    . MAIN_DB_PREFIX . ksefInvoiceKind($object)['table'] . " WHERE rowid = " . (int) $object->id) : null;
                $tot = $resTot ? $this->db->fetch_object($resTot) : null;
                if ($moved < 0 || ($moved > 0 && !$tot)) {
                    $this->error = "Cannot set the deposit lines of " . $object->ref . " at its rate: " . $this->db->lasterror();
                    $this->errors[] = $this->error;
                    dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                    return -1;
                }
                foreach ($tot ? (array) $tot : array() as $col => $v) {
                    $object->$col = (float) $v;
                }
                if ($object->element === 'facture' && in_array((int) $object->type, array(Facture::TYPE_CREDIT_NOTE, Facture::TYPE_REPLACEMENT), true)
                    && empty($GLOBALS['ksef_importing_sales']) && empty($object->array_options['options_ksef_number']) && $this->korektaRate($object, $langs) < 0) {
                    $this->errors[] = $this->error;
                    return -1;
                }
                if (!empty($object->array_options['options_ksef_number'])) {
                    dol_syslog("KsefTriggers: Invoice " . $object->ref . " validated with KSEF number: " . $object->array_options['options_ksef_number'], LOG_INFO);
                }

                if ($object->element === 'facture' && $object->type == Facture::TYPE_CREDIT_NOTE) {
                    dol_include_once('/ksef/lib/ksef.lib.php');
                    if (!empty($object->fk_facture_source) && !$this->isModuleDiscountMove()) {
                        $inForce = ksefGetActiveReplacement($this->db, (int) $object->fk_facture_source);
                        $resSrc = $inForce ? $this->db->query("SELECT ref FROM " . MAIN_DB_PREFIX . "facture WHERE rowid = " . (int) $object->fk_facture_source) : null;
                        $objSrc = $resSrc ? $this->db->fetch_object($resSrc) : null;
                        if ($inForce === false || ($inForce && !$objSrc)) {
                            $this->error = "Cannot check the replacements of invoice " . (int) $object->fk_facture_source . ": " . $this->db->lasterror();
                            $this->errors[] = $this->error;
                            dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                            return -1;
                        }
                        if ($inForce) {
                            $langs->load('ksef@ksef');
                            $this->error = $langs->transnoentitiesnoconv('KSEF_CreditNoteOfReplacedRefused', $objSrc->ref, $inForce->ref, $inForce->ref);
                            $this->errors[] = $this->error;
                            dol_syslog("KsefTriggers: BILL_VALIDATE of credit note " . $object->ref . " refused: " . $objSrc->ref . " is corrected by " . $inForce->ref, LOG_WARNING);
                            return -1;
                        }
                    }
                    // core: card only
                    if ((float) $object->total_ht > 0 || (float) $object->total_ttc > 0) {
                        $langs->load('ksef@ksef');
                        $this->error = $langs->transnoentitiesnoconv('KSEF_CreditNotePositiveRefused', $this->validatedRef($object));
                        $this->errors[] = $this->error;
                        dol_syslog("KsefTriggers: BILL_VALIDATE of credit note " . $object->ref . " refused: positive total " . $object->total_ttc, LOG_WARNING);
                        return -1;
                    }
                    $depId = ksefDepositRootId($this->db, (int) $object->id);
                    $depVal = ($depId > 0 && empty($GLOBALS['ksef_importing_sales']) && empty($object->array_options['options_ksef_number'])) ? ksefDepositCurrentValue($this->db, $depId) : false;
                    foreach ($depVal ? $depVal['rates'] : array() as $rk => $r) {
                        if ($r[$depVal['foreign'] ? 'mttc' : 'ttc'] < -0.005) {
                            $langs->load('ksef@ksef');
                            $this->error = $langs->transnoentitiesnoconv('KSEF_DepositCreditNoteRateBelowZero', $object->ref, $depVal['ref'], ksefDepositRateLabel($rk));
                            $this->errors[] = $this->error;
                            dol_syslog("KsefTriggers: BILL_VALIDATE of credit note " . $object->ref . " refused: deposit " . $depVal['ref'] . " rate " . $rk . " below zero", LOG_WARNING);
                            return -1;
                        }
                    }
                    return $this->restateDeposit($user, (int) $object->id, 0, false);
                }
                if ($object->element === 'invoice_supplier' && $object->type == Facture::TYPE_CREDIT_NOTE) {
                    return $this->restateDeposit($user, (int) $object->id, 0, false, 0, 'invoice_supplier');
                }

                if ($object->type == Facture::TYPE_REPLACEMENT
                    && !empty($object->fk_facture_source)) {

                    $original_id = (int) $object->fk_facture_source;
                    $replacement_id = (int) $object->id;
                    dol_include_once('/ksef/lib/ksef.lib.php');
                    require_once DOL_DOCUMENT_ROOT . '/core/class/discount.class.php';
                    $kind = ksefInvoiceKind($object);
                    $supplier = $kind['element'] === 'invoice_supplier';
                    $cls = $kind['class'];
                    $T = MAIN_DB_PREFIX . $kind['table'];

                    $originalInvoice = new $cls($this->db);
                    if ($originalInvoice->fetch($original_id) <= 0) {
                        $this->error = "Cannot fetch original invoice $original_id";
                        dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                        return -1;
                    }
                    $originalInvoice->fetch_optionals();

                    // no double credit
                    $sibling = ksefGetActiveReplacement($this->db, $original_id, $replacement_id, true, $kind['element']);
                    if ($sibling === false) {
                        $this->error = "Cannot check the replacements of invoice $original_id: " . $this->db->lasterror();
                        dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                        return -1;
                    }
                    if ($sibling) {
                        $langs->load('bills');
                        $this->error = $langs->transnoentitiesnoconv('ErrorInvoiceAlreadyReplaced', $originalInvoice->ref, $sibling->ref);
                        $this->errors[] = $this->error;
                        dol_syslog("KsefTriggers: BILL_VALIDATE of " . $object->ref . " refused: " . $originalInvoice->ref . " is already corrected by " . $sibling->ref, LOG_WARNING);
                        return -1;
                    }
                    $abandoned = ksefFindReplacementOf($this->db, $original_id, (string) Facture::STATUS_ABANDONED, $replacement_id, true, $kind['element']);
                    if ($abandoned === false) {
                        $this->error = "Cannot check the replacements of invoice $original_id: " . $this->db->lasterror();
                        dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                        return -1;
                    }
                    if ($abandoned) {
                        $langs->load('ksef@ksef');
                        $resNr = $supplier ? false : $this->db->query("SELECT ksef_number FROM " . MAIN_DB_PREFIX . "facture_extrafields WHERE fk_object = " . ((int) $abandoned->rowid));
                        $objNr = $resNr ? $this->db->fetch_object($resNr) : null;
                        $sent = $supplier || ($objNr && !empty($objNr->ksef_number));
                        $this->error = $langs->transnoentitiesnoconv($sent ? 'KSEF_CorrectionAbandonedSiblingSent' : 'KSEF_CorrectionAbandonedSiblingUnsent', $originalInvoice->ref, $abandoned->ref, $abandoned->ref);
                        $this->errors[] = $this->error;
                        dol_syslog("KsefTriggers: BILL_VALIDATE of " . $object->ref . " refused: " . $originalInvoice->ref . " has the abandoned korekta " . $abandoned->ref . ($sent ? ' (sent)' : ' (unsent)'), LOG_WARNING);
                        return -1;
                    }
                    $collRef = (!$supplier && empty($GLOBALS['ksef_importing_sales']) && empty($object->array_options['options_ksef_number'])) ? ksefCorrectionCollectiveRefused($this->db, $original_id) : '';
                    if ($collRef !== '') {
                        $langs->load('ksef@ksef');
                        $this->error = $langs->transnoentitiesnoconv('KSEF_CorrectionCollectiveKorRefused', $originalInvoice->ref);
                        $this->errors[] = $this->error;
                        dol_syslog("KsefTriggers: BILL_VALIDATE of " . $object->ref . " refused: " . $originalInvoice->ref . " is tied to a collective KOR (" . $collRef . ")", LOG_WARNING);
                        return -1;
                    }
                    $noteRoot = (!$supplier && empty($GLOBALS['ksef_importing_sales']) && empty($object->array_options['options_ksef_number'])) ? ksefNoteChainRoot($this->db, $original_id) : 0;
                    if ($noteRoot > 0) {
                        $langs->load('ksef@ksef');
                        $resNote = $this->db->query("SELECT f.ref, f.fk_soc, f.fk_facture_source, fe.ksef_number, s.ref AS src_ref FROM " . MAIN_DB_PREFIX . "facture AS f"
                            . " LEFT JOIN " . MAIN_DB_PREFIX . "facture_extrafields AS fe ON fe.fk_object = f.rowid"
                            . " LEFT JOIN " . MAIN_DB_PREFIX . "facture AS s ON s.rowid = f.fk_facture_source WHERE f.rowid = " . $noteRoot);
                        $objNote = $resNote ? $this->db->fetch_object($resNote) : null;
                        $noteKey = '';
                        $noteArg = '';
                        if ($objNote && trim((string) $objNote->ksef_number) === '' && !ksefIsCustomerExcluded((int) $objNote->fk_soc)) {
                            $noteArg = (string) $objNote->src_ref;
                            if (ksefNoteSendInFlight($this->db, $noteRoot)) {
                                $noteKey = 'KSEF_NoteKorektaNoteInFlight';
                            } else {
                                $noteKey = $noteArg !== '' ? 'KSEF_NoteKorektaNotKsef' : 'KSEF_NoteKorektaNotKsefNoSource';
                            }
                        } elseif ((float) $object->total_ttc > 0.005) {
                            $noteKey = 'KSEF_NoteKorektaPositive';
                        } elseif (ksefDepositRootId($this->db, $noteRoot) > 0) {
                            $noteKey = 'KSEF_NoteKorektaDeposit';
                        } elseif ($objNote && ($noteArg = ksefNoteAbsorbedBy($this->db, $noteRoot, (int) $objNote->fk_facture_source)) !== '') {
                            $noteKey = 'KSEF_NoteKorektaAbsorbed';
                        }
                        if ($noteKey !== '') {
                            $this->error = $langs->transnoentitiesnoconv($noteKey, $object->ref, $objNote ? $objNote->ref : '', $noteArg);
                            $this->errors[] = $this->error;
                            dol_syslog("KsefTriggers: BILL_VALIDATE of " . $object->ref . " refused: korekta of credit note " . $noteRoot . " (" . $noteKey . ")", LOG_WARNING);
                            return -1;
                        }
                    }
                    $below = ($supplier || (empty($GLOBALS['ksef_importing_sales']) && empty($object->array_options['options_ksef_number']))) ? ksefDepositKorektaBelowZero($this->db, $replacement_id, $kind['element']) : null;
                    if ($below) {
                        $langs->load('ksef@ksef');
                        $this->error = $langs->transnoentitiesnoconv('KSEF_DepositKorektaRateBelowZero', $object->ref, $below['ref'], $below['label']);
                        $this->errors[] = $this->error;
                        dol_syslog("KsefTriggers: BILL_VALIDATE of " . $object->ref . " refused: deposit " . $below['ref'] . " rate " . $below['rate'] . " below zero", LOG_WARNING);
                        return -1;
                    }

                    if ($supplier) {
                        // core keeps source
                        $srcStatus = (int) (isset($originalInvoice->status) ? $originalInvoice->status : $originalInvoice->statut);
                        if (!in_array($srcStatus, array(Facture::STATUS_VALIDATED, Facture::STATUS_CLOSED), true)) {
                            $langs->load('ksef@ksef');
                            $this->error = $langs->transnoentitiesnoconv('KSEF_SupplierCorrectionSourceState', $originalInvoice->ref);
                            $this->errors[] = $this->error;
                            dol_syslog("KsefTriggers: BILL_SUPPLIER_VALIDATE of " . $object->ref . " refused: source " . $originalInvoice->ref . " has status $srcStatus", LOG_WARNING);
                            return -1;
                        }
                        $pre = array('st' => $srcStatus, 'paye' => (int) $originalInvoice->paye, 'cc' => $originalInvoice->close_code, 'cn' => $originalInvoice->close_note);
                    } else {
                        $pre = self::$cancelState[$original_id] ?? null;
                        unset(self::$cancelState[$original_id]);
                        if ($pre === null || (int) $originalInvoice->status !== Facture::STATUS_ABANDONED || $originalInvoice->close_code !== Facture::CLOSECODE_REPLACED) {
                            $pre = array('st' => null, 'paye' => (int) $originalInvoice->paye, 'cc' => null, 'cn' => $originalInvoice->close_note);
                        }
                    }
                    $draftState = ksefCorrectionState($object);
                    $correctionState = array('src' => $pre) + array_intersect_key($draftState, array('short_ans' => 1, 'absorb' => 1, 'cn_use' => 1));
                    $shortAns = (string) ($draftState['short_ans'] ?? '');
                    $absNotes = array();
                    $absSum = array('ht' => 0.0, 'tva' => 0.0, 'ttc' => 0.0, 'out' => 0.0, 'out_mc' => 0.0);
                    if (!$supplier && !empty($draftState['absorb'])) {
                        $absIds = array_map('intval', (array) $draftState['absorb']);
                        $absNotes = ksefCorrectionNotes($this->db, $original_id, $absIds);
                        if ($absNotes === false) {
                            $this->error = "Cannot read the credit notes of " . $originalInvoice->ref . ": " . $this->db->lasterror();
                            dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                            return -1;
                        }
                        if (count($absNotes) !== count($absIds)) {
                            $langs->load('ksef@ksef');
                            $this->error = $langs->transnoentitiesnoconv('KSEF_AbsorbNoteGone', $this->validatedRef($object));
                            $this->errors[] = $this->error;
                            dol_syslog("KsefTriggers: BILL_VALIDATE of " . $object->ref . " refused: a credit note it took in is no longer in force", LOG_WARNING);
                            return -1;
                        }
                        $corrNotes = array_filter($absNotes, function ($n) {
                            return $n['corrected'] !== '';
                        });
                        if ($corrNotes) {
                            $langs->load('ksef@ksef');
                            $cn1 = reset($corrNotes);
                            $this->error = $langs->transnoentitiesnoconv('KSEF_AbsorbNoteCorrected', $this->validatedRef($object), $cn1['ref'], $cn1['corrected']);
                            $this->errors[] = $this->error;
                            dol_syslog("KsefTriggers: BILL_VALIDATE of " . $object->ref . " refused: credit note " . $cn1['ref'] . " it took in has the korekta " . $cn1['corrected'], LOG_WARNING);
                            return -1;
                        }
                        foreach ($absNotes as $n) {
                            foreach ($absSum as $col => $v) {
                                $absSum[$col] = $v + $n[$col];
                            }
                        }
                    }

                    $object->array_options['options_ksef_correction_original_ht'] = (float) price2num($originalInvoice->total_ht + $absSum['ht'], 'MT');
                    $object->array_options['options_ksef_correction_original_tva'] = (float) price2num($originalInvoice->total_tva + $absSum['tva'], 'MT');
                    $object->array_options['options_ksef_correction_original_ttc'] = (float) price2num($originalInvoice->total_ttc + $absSum['ttc'], 'MT');
                    if ($object->insertExtraFields() < 0) {
                        $this->error = "Failed to store correction metadata: " . $object->error;
                        dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                        return -1;
                    }

                    $orig_discount_id = (int) ($originalInvoice->array_options['options_ksef_correction_discount_id'] ?? 0);
                    if ($orig_discount_id > 0) {
                        // core already unlinked
                        if (ksefDeleteCorrectionDiscount($this->db, $orig_discount_id, $original_id, $kind['element']) < 0) {
                            $this->error = "Failed to delete the correction discount $orig_discount_id of " . $originalInvoice->ref . ": " . $this->db->lasterror();
                            dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                            return -1;
                        }
                        $sql_clear = "UPDATE " . MAIN_DB_PREFIX . $kind['extrafields'] . "_extrafields SET ksef_correction_discount_id = NULL WHERE fk_object = " . $original_id;
                        if (!$this->db->query($sql_clear)) {
                            $this->error = "Failed to clear the correction discount of " . $originalInvoice->ref . ": " . $this->db->lasterror();
                            dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                            return -1;
                        }
                        dol_syslog("KsefTriggers: BILL_VALIDATE - Deleted chain discount ID $orig_discount_id of parent $original_id", LOG_INFO);
                    }

                    if ($supplier) {
                        // core keeps credits
                        $resSrcDisc = $this->db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "societe_remise_except WHERE fk_invoice_supplier = " . $original_id);
                        if (!$resSrcDisc) {
                            $this->error = "Cannot read the credits of " . $originalInvoice->ref . ": " . $this->db->lasterror();
                            dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                            return -1;
                        }
                        $srcDiscIds = array();
                        while ($o = $this->db->fetch_object($resSrcDisc)) {
                            $srcDiscIds[] = (int) $o->rowid;
                        }
                        $this->db->free($resSrcDisc);
                        foreach ($srcDiscIds as $disc_id) {
                            $mv = new DiscountAbsolute($this->db);
                            if ($mv->fetch($disc_id) <= 0 || $mv->unlink_invoice(1) < 0) {
                                $this->error = "Failed to free credit $disc_id of " . $originalInvoice->ref . ": " . $mv->error;
                                dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                                return -1;
                            }
                        }
                        $object->array_options['options_ksef_correction_original_discount_ids'] = $srcDiscIds ? implode(',', $srcDiscIds) : null;
                        if ($object->insertExtraFields() < 0) {
                            $this->error = "Failed to store the credits of " . $originalInvoice->ref . " on " . $object->ref . ": " . $object->error;
                            dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                            return -1;
                        }
                    }

                    $absBack = array('a' => 0.0, 'm' => 0.0);
                    $stored_ids_str = $object->array_options['options_ksef_correction_original_discount_ids'] ?? '';
                    if (!empty($stored_ids_str)) {
                        $stored_ids = array_map('intval', explode(',', $stored_ids_str));
                        foreach ($stored_ids as $disc_id) {
                            if ($disc_id <= 0) {
                                continue;
                            }
                            $relink_disc = new DiscountAbsolute($this->db);
                            if ($relink_disc->fetch($disc_id) > 0 && empty($supplier ? $relink_disc->fk_invoice_supplier : $relink_disc->fk_facture)) {
                                $home = isset($absNotes[(int) $relink_disc->fk_facture_source]);
                                self::$setoffFrom[$disc_id] = $original_id;
                                if ($relink_disc->link_to_invoice(0, $home ? $original_id : $replacement_id) < 0) {
                                    $this->error = "Failed to re-link discount $disc_id to invoice " . ($home ? $original_id : $replacement_id) . ": " . $relink_disc->error;
                                    dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                                    return -1;
                                }
                                if ($home) {
                                    $absBack['a'] += (float) $relink_disc->amount_ttc;
                                    $absBack['m'] += (float) $relink_disc->multicurrency_amount_ttc;
                                }
                                dol_syslog("KsefTriggers: BILL_VALIDATE - Re-linked discount ID $disc_id to " . ($home ? "original $original_id" : "replacement $replacement_id"), LOG_INFO);
                            }
                        }
                    }
                    if ($absNotes) {
                        $absState = array('home' => array(), 'closed' => array(), 'out' => (float) price2num($absSum['out'], 'MT'), 'out_mc' => (float) price2num($absSum['out_mc'], 'MT'));
                        $resFree = $this->db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "societe_remise_except WHERE fk_facture_source IN (" . implode(',', array_keys($absNotes)) . ")"
                            . " AND fk_facture IS NULL AND fk_facture_line IS NULL ORDER BY rowid");
                        while ($resFree && ($o = $this->db->fetch_object($resFree))) {
                            $absState['home'][] = (int) $o->rowid;
                        }
                        foreach ($absState['home'] as $disc_id) {
                            $relink_disc = new DiscountAbsolute($this->db);
                            if ($relink_disc->fetch($disc_id) <= 0 || $relink_disc->link_to_invoice(0, $original_id) < 0) {
                                $this->error = "Failed to use credit $disc_id on " . $originalInvoice->ref . ": " . $relink_disc->error;
                                dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                                return -1;
                            }
                        }
                        $langs->load('ksef@ksef');
                        foreach ($absNotes as $n) {
                            if ($n['st'] !== Facture::STATUS_VALIDATED) {
                                continue;
                            }
                            if (!$this->db->query("UPDATE " . $T . " SET fk_statut = " . Facture::STATUS_CLOSED . ", paye = 1, close_note = '"
                                . $this->db->escape($langs->transnoentitiesnoconv('KSEF_AbsorbedNoteCloseNote', $this->validatedRef($object))) . "' WHERE rowid = " . $n['id'])) {
                                $this->error = "Failed to close credit note " . $n['ref'] . ": " . $this->db->lasterror();
                                dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                                return -1;
                            }
                            $absState['closed'][$n['id']] = array('st' => $n['st'], 'paye' => $n['paye'], 'cc' => $n['cc'], 'cn' => $n['cn']);
                        }
                        $correctionState['abs'] = $absState;
                        $absBack['a'] = (float) price2num($absBack['a'], 'MT');
                        $absBack['m'] = (float) price2num($absBack['m'], 'MT');
                    }

                    $depRootId = ksefDepositRootId($this->db, $original_id, $kind['element']);
                    if ($depRootId > 0) {
                        $correctionState['dep'] = $depRootId;
                    }

                    $langs->load('ksef@ksef');
                    $sql_restore = "UPDATE " . $T
                        . " SET fk_statut = " . Facture::STATUS_CLOSED
                        . ", close_code = NULL"
                        . ", paye = " . (($depRootId > 0 && (int) $pre['paye'] !== 1) ? 0 : 1)
                        . ", close_note = '" . $this->db->escape($langs->transnoentitiesnoconv('KSEF_CorrectedSourceCloseNote', $this->validatedRef($object))) . "'"
                        . " WHERE rowid = " . $original_id;
                    if (!$this->db->query($sql_restore)) {
                        $this->error = "Failed to restore original invoice status: " . $this->db->lasterror();
                        dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                        return -1;
                    }
                    dol_syslog("KsefTriggers: BILL_VALIDATE - Restored original $original_id to STATUS_CLOSED", LOG_INFO);

                    if (!$supplier) {
                        $refill = ksefSettlementPrefill($this->db, $replacement_id, $original_id, true);
                        if ($refill['result'] < 0) {
                            $this->error = $refill['error'];
                            $this->errors[] = $this->error;
                            return -1;
                        }
                        $langs->load('ksef@ksef');
                        foreach ($refill['changed'] as $c) {
                            if (abs($c['to'] - $c['from']) >= 0.005) {
                                setEventMessages($langs->trans('KSEF_SettlementCorrectionRefreshed', $c['ref'], price($c['to'], 0, $langs, 1, -1, -1, $c['currency']),
                                    price($c['from'], 0, $langs, 1, -1, -1, $c['currency'])), null, 'warnings');
                            }
                        }
                        foreach ($refill['missing'] as $m) {
                            setEventMessages($langs->trans('KSEF_SettlementCorrectionRateMissingAtValidate', $m['ref'], ksefDepositRateLabel($m['rate'])), null, 'warnings');
                        }
                        if ($refill['result'] > 0) {
                            $resTot = $this->db->query("SELECT total_ht, total_tva, total_ttc, multicurrency_total_ht, multicurrency_total_tva, multicurrency_total_ttc FROM "
                                . MAIN_DB_PREFIX . "facture WHERE rowid = " . $replacement_id);
                            $tot = $resTot ? $this->db->fetch_object($resTot) : null;
                            if ($tot) {
                                foreach ((array) $tot as $col => $val) {
                                    $object->$col = (float) $val;
                                }
                            }
                        }
                    }

                    if (!$supplier) {
                        $depPaid = array();
                        foreach (ksefConsumedDeposits($this->db, $replacement_id) as $dId => $d) {
                            if ($d['mode'] !== 'payment') {
                                continue;
                            }
                            $rows = array();
                            $resRows = $this->db->query("SELECT rowid, amount_ht, amount_tva, amount_ttc, multicurrency_amount_ht, multicurrency_amount_tva, multicurrency_amount_ttc"
                                . " FROM " . MAIN_DB_PREFIX . "societe_remise_except WHERE fk_facture = " . $replacement_id . " AND fk_facture_source = " . (int) $dId
                                . " AND description = '" . $this->db->escape(KSEF_DEPOSIT_CREDIT_DESCRIPTION) . "'");
                            while ($resRows && ($o = $this->db->fetch_object($resRows))) {
                                $rows[(int) $o->rowid] = array((float) $o->amount_ht, (float) $o->amount_tva, (float) $o->amount_ttc,
                                    (float) $o->multicurrency_amount_ht, (float) $o->multicurrency_amount_tva, (float) $o->multicurrency_amount_ttc);
                            }
                            $depPaid[$dId] = array('ttc' => $d['ttc'], 'mc_ttc' => $d['mc_ttc'], 'rates' => $d['rates'], 'rows' => $rows);
                        }
                        foreach (array_keys($depPaid) as $dId) {
                            $rs = ksefDepositRestate($this->db, $user, (int) $dId);
                            if ($rs['result'] < 0) {
                                $this->error = "Failed to restate the credit of deposit $dId: " . $rs['error'];
                                $this->errors[] = $this->error;
                                return -1;
                            }
                        }
                        if ($depPaid) {
                            $correctionState['dep_paid'] = $depPaid;
                        }
                    }

                    $calc = ksefCorrectionSettled($this->db, $originalInvoice);
                    if ($calc === false) {
                        $this->error = "Cannot find root invoice for correction chain from invoice $original_id";
                        dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                        return -1;
                    }
                    $settled = $calc['settled'];
                    $settled_mc = $calc['settled_mc'];

                    dol_syslog("KsefTriggers: BILL_VALIDATE - Chain settled=$settled, replacement=$replacement_id", LOG_INFO);

                    $resLinked = $this->db->query("SELECT COALESCE(SUM(amount_ttc), 0) AS a, COALESCE(SUM(multicurrency_amount_ttc), 0) AS m FROM " . MAIN_DB_PREFIX . "societe_remise_except WHERE " . $kind['col'] . " = " . $replacement_id);
                    $objLinked = $resLinked ? $this->db->fetch_object($resLinked) : null;
                    if (!$objLinked) {
                        $this->error = "Cannot read the credits of replacement $replacement_id: " . $this->db->lasterror();
                        dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                        return -1;
                    }
                    $rootInvoice = $calc['root'];
                    $foreign = !empty($rootInvoice->multicurrency_code) && $rootInvoice->multicurrency_code != $conf->currency;
                    $room = (float) $object->total_ttc - (float) $objLinked->a;
                    $roomMc = (float) $object->multicurrency_total_ttc - (float) $objLinked->m;
                    $short = (int) $pre['st'] === Facture::STATUS_CLOSED ? ksefShortfall($originalInvoice, $calc, (float) $objLinked->a + $absBack['a'], (float) $objLinked->m + $absBack['m'], (string) $pre['cc'], $shortAns) : null;
                    if ($short) {
                        $correctionState['short'] = $short;
                        if ($short['ans'] === 'paid') {
                            $calc['settled'] = $settled = (float) price2num($settled + $short['ttc'], 'MT');
                            $calc['settled_mc'] = $settled_mc = (float) price2num($settled_mc + $short['mttc'], 'MT');
                        } elseif ($short['ans'] === 'keep') {
                            $calc['keep'] += $short['ttc'];
                            $calc['keep_mc'] += $short['mttc'];
                        }
                        dol_syslog("KsefTriggers: BILL_VALIDATE - " . $originalInvoice->ref . " closed '" . $short['code'] . "' with " . $short['ttc'] . " forgiven: " . $short['ans'], LOG_INFO);
                    }
                    if ($absNotes) {
                        $calc['settled'] = $settled = (float) price2num($settled - $absSum['out'], 'MT');
                        $calc['settled_mc'] = $settled_mc = (float) price2num($settled_mc - $absSum['out_mc'], 'MT');
                        if ($settled < -0.005 || ($foreign && $settled_mc < -0.005)) {
                            $langs->load('ksef@ksef');
                            $this->error = $langs->transnoentitiesnoconv('KSEF_AbsorbNoteSpent', implode(', ', array_column($absNotes, 'ref')), $originalInvoice->ref);
                            $this->errors[] = $this->error;
                            dol_syslog("KsefTriggers: BILL_VALIDATE of " . $object->ref . " refused: its credit notes gave back " . $absSum['out'] . " more than was paid", LOG_WARNING);
                            return -1;
                        }
                    }
                    $excess = $excessMc = 0.0;
                    if ($foreign ? ($settled_mc - $roomMc > 0.0101) : ($settled - $room > 0.005)) {
                        $excess = max(0.0, (float) price2num($settled - $room, 'MT'));
                        $excessMc = $foreign ? max(0.0, (float) price2num($settled_mc - $roomMc, 'MT')) : 0.0;
                        $calc['settled'] = $settled = (float) price2num($settled - $excess, 'MT');
                        $calc['settled_mc'] = $settled_mc = (float) price2num($settled_mc - $excessMc, 'MT');
                    }
                    $want = $excess > 0 || $excessMc > 0 ? 0.0 : (float) price2num($foreign ? min(0.0, $roomMc) - $settled_mc : min(0.0, $room) - $settled, 'MT');
                    if ($want > 0.005) {
                        $rec = ksefRecoverExcessCredit($this->db, (int) $calc['root']->id, $want, $foreign, $kind['element']);
                        if ($rec === false) {
                            $this->error = "Cannot take back the excess credit of " . $calc['root']->ref . ": " . $this->db->lasterror();
                            dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                            return -1;
                        }
                        $langs->load('ksef@ksef');
                        $cur = $foreign ? $calc['root']->multicurrency_code : $conf->currency;
                        $ref = $this->validatedRef($object);
                        if ($rec['rows']) {
                            $correctionState['recov'] = $rec['rows'];
                            $calc['settled'] = $settled = (float) price2num($settled + $rec['ttc'], 'MT');
                            $calc['settled_mc'] = $settled_mc = (float) price2num($settled_mc + $rec['mttc'], 'MT');
                            setEventMessages($langs->trans('KSEF_CorrectionCreditRecovered', $ref, price($foreign ? $rec['mttc'] : $rec['ttc'], 0, $langs, 1, -1, -1, $cur), $calc['root']->ref), null, 'mesgs');
                        }
                        $unrec = (float) price2num($want - ($foreign ? $rec['mttc'] : $rec['ttc']), 'MT');
                        if ($unrec > 0.005) {
                            setEventMessages($langs->trans('KSEF_CorrectionCreditNotRecovered', $ref, price($unrec, 0, $langs, 1, -1, -1, $cur), $calc['root']->ref), null, 'warnings');
                        }
                        dol_syslog("KsefTriggers: BILL_VALIDATE - " . $object->ref . " took back " . $rec['ttc'] . " of the excess credit at " . $calc['root']->ref . ", $unrec stays due", LOG_INFO);
                    }
                    $this->carryKeptShortfall($calc, $room, $roomMc);
                    $settled = $calc['settled'];
                    $settled_mc = $calc['settled_mc'];

                    if (!$absNotes && !empty($draftState['cn_use']) && $excess <= 0 && $excessMc <= 0) {
                        $resFree = $this->db->query("SELECT rowid, amount_ttc, multicurrency_amount_ttc FROM " . MAIN_DB_PREFIX . "societe_remise_except"
                            . " WHERE fk_facture_source IN (SELECT rowid FROM " . $T . " WHERE fk_facture_source = " . $original_id . " AND type = " . Facture::TYPE_CREDIT_NOTE
                            . " AND fk_statut IN (" . Facture::STATUS_VALIDATED . ", " . Facture::STATUS_CLOSED . ")) AND fk_facture IS NULL AND fk_facture_line IS NULL ORDER BY rowid");
                        $freeRows = array();
                        while ($resFree && ($o = $this->db->fetch_object($resFree))) {
                            $freeRows[] = $o;
                        }
                        $used = $resFree ? $this->linkFreeCredit($freeRows, $replacement_id, $foreign ? $roomMc - $settled_mc : $room - $settled, $foreign) : false;
                        if ($used === false) {
                            $this->error = $this->error ?: "Cannot read the credit notes of " . $originalInvoice->ref . ": " . $this->db->lasterror();
                            dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                            return -1;
                        }
                        if ($used['ids']) {
                            $correctionState['cn_used'] = $used['ids'];
                            $objLinked->a = (float) $objLinked->a + $used['ttc'];
                            $objLinked->m = (float) $objLinked->m + $used['mttc'];
                            dol_syslog("KsefTriggers: BILL_VALIDATE - free credit " . implode(',', $used['ids']) . " of the credit notes of " . $originalInvoice->ref . " pays " . $object->ref, LOG_INFO);
                        }
                    }

                    // base amount required
                    if ($settled > 0 || ($want > 0.005 && ($foreign ? $settled_mc < min(0.0, $roomMc) - 0.005 : $settled < min(0.0, $room) - 0.005))) {
                        $discount_id = $this->createCorrectionDiscount($user, $langs, $conf, $object, $originalInvoice, $calc);
                        if ($discount_id < 0) {
                            return -1;
                        }
                        dol_syslog("KsefTriggers: BILL_VALIDATE - Created discount ID $discount_id (amount=$settled) linked to replacement $replacement_id", LOG_INFO);
                    }
                    if ($excess > 0 || $excessMc > 0) {
                        $excess_id = $this->createExcessCredit($user, $object, $rootInvoice, $excess, $excessMc);
                        if ($excess_id < 0) {
                            return -1;
                        }
                        $correctionState['excess'] = $excess_id;
                        $correctionState['excess_root'] = (int) $rootInvoice->id;
                        // split deletes row
                        $correctionState['excess_amt'] = (float) price2num($excess, 'MT');
                        $resDc = $this->db->query("SELECT datec FROM " . MAIN_DB_PREFIX . "societe_remise_except WHERE rowid = " . (int) $excess_id);
                        $objDc = $resDc ? $this->db->fetch_object($resDc) : null;
                        $correctionState['excess_datec'] = $objDc ? (string) $objDc->datec : '';
                        $correctionState['excess_pay'] = (float) price2num(max(0.0, $excess + min(0.0, $settled)), 'MT');
                        dol_syslog("KsefTriggers: BILL_VALIDATE - Excess $excess" . ($foreign ? " ($excessMc " . $rootInvoice->multicurrency_code . ")" : '') . " of replacement $replacement_id is free credit ID $excess_id of the " . ($supplier ? 'supplier' : 'customer'), LOG_INFO);
                    }

                    $correction_ttc = (float) $object->total_ttc;
                    $covered = $settled + (float) $objLinked->a;
                    $coveredMc = $settled_mc + (float) $objLinked->m;
                    $correctionMc = (float) $object->multicurrency_total_ttc;
                    if ($excess > 0 || $excessMc > 0 || ($covered >= $correction_ttc && ($covered - $correction_ttc) < 0.01)
                        || ($foreign && $coveredMc >= $correctionMc && ($coveredMc - $correctionMc) < 0.01)) {
                        $sql_paid = "UPDATE " . $T
                            . " SET fk_statut = " . Facture::STATUS_CLOSED
                            . ", paye = 1"
                            . ", fk_user_closing = " . ((int) $user->id)
                            . ", date_closing = '" . $this->db->idate(dol_now()) . "'"
                            . " WHERE rowid = " . $replacement_id;
                        if (!$this->db->query($sql_paid)) {
                            $this->error = "Failed to classify correction $replacement_id as paid: " . $this->db->lasterror();
                            dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                            return -1;
                        }
                        dol_syslog("KsefTriggers: BILL_VALIDATE - Auto-classified correction $replacement_id as paid (settled=$settled matches total=$correction_ttc)", LOG_INFO);
                    }

                    $stockComp = $this->compensateCorrectionStock($user, $langs, $object, $originalInvoice, array_keys($absNotes));
                    if ($stockComp === false) {
                        return -1;
                    }
                    if ($stockComp) {
                        $correctionState['stock'] = $stockComp;
                        $res = $this->db->query("SELECT MAX(rowid) AS m FROM " . MAIN_DB_PREFIX . "stock_mouvement");
                        $o = $res ? $this->db->fetch_object($res) : null;
                        $correctionState['stock_mk'] = $o ? (int) $o->m : 0;
                    }
                    $early = array_filter(array('short' => $short ?: null, 'abs' => $correctionState['abs'] ?? null));
                    if ($depRootId > 0 && $early && !ksefDepositStateSet($this->db, $replacement_id, $early, $kind['element'])) {
                        $this->error = "Failed to store the correction state of " . $object->ref . ": " . $this->db->lasterror();
                        dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                        return -1;
                    }
                    if ($depRootId > 0) {
                        $rs = ksefDepositRestate($this->db, $user, $depRootId, 0, true, 0, $kind['element']);
                        if ($rs['result'] < 0) {
                            $this->error = "Failed to restate the credit of deposit $depRootId: " . $rs['error'];
                            $this->errors[] = $this->error;
                            return -1;
                        }
                        if ($rs['created']) {
                            $correctionState['conv'] = $rs['created'];
                        }
                    }

                    if (ksefCorrectionStateStorable($this->db, $kind['element'])) {
                        $setoff = ksefDepositState($this->db, $replacement_id, $kind['element'])['setoff'] ?? null;
                        if ($setoff) {
                            $correctionState['setoff'] = $setoff;
                        }
                        $object->array_options['options_ksef_correction_state'] = json_encode($correctionState);
                        if ($object->insertExtraFields() < 0) {
                            $this->error = "Failed to store the correction state of " . $object->ref . ": " . $object->error;
                            dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                            return -1;
                        }
                    }
                }
                return 0;

            case 'BILL_CANCEL':
            case 'BILL_SUPPLIER_CANCEL':
                if ($object->element === 'facture' && !empty($object->id)) {
                    self::$cancelState[(int) $object->id] = array(
                        'st' => (int) (isset($object->status) ? $object->status : $object->statut),
                        'paye' => (int) $object->paye,
                        'cc' => $object->close_code,
                        'cn' => $object->close_note,
                    );
                }
                $cancelSupplier = $object->element === 'invoice_supplier';
                if (($object->element === 'facture' || $cancelSupplier) && !empty($object->id) && $object->type == Facture::TYPE_REPLACEMENT) {
                    $object->fetch_optionals();
                    $resCc = $this->db->query("SELECT close_code FROM " . MAIN_DB_PREFIX . ($cancelSupplier ? "facture_fourn" : "facture") . " WHERE rowid = " . ((int) $object->id));
                    $objCc = $resCc ? $this->db->fetch_object($resCc) : null;
                    if (!$objCc) {
                        $this->error = "Cannot read the close code of " . $object->ref . ": " . $this->db->lasterror();
                        dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                        return -1;
                    }
                    $ids = array_map('intval', explode(',', (string) ($object->array_options['options_ksef_correction_original_discount_ids'] ?? '')));
                    $ids[] = (int) ($object->array_options['options_ksef_correction_discount_id'] ?? 0);
                    $ids = array_filter($ids);
                    $isKorekta = $objCc->close_code !== Facture::CLOSECODE_REPLACED && !empty($object->array_options['options_ksef_correction_original_ht']);
                    dol_include_once('/ksef/lib/ksef.lib.php');
                    if ($isKorekta && ksefDepositRootId($this->db, (int) $object->id, $object->element) > 0 && ksefInvoiceSentToKsef($this->db, (int) $object->id, $object->element)) {
                        $langs->load('ksef@ksef');
                        $this->error = $langs->transnoentitiesnoconv('KSEF_DepositCorrectionAbandonSent', $object->ref);
                        $this->errors[] = $this->error;
                        dol_syslog("KsefTriggers: BILL_CANCEL of sent deposit korekta " . $object->ref . " refused", LOG_WARNING);
                        return -1;
                    }
                    if ($isKorekta && !$cancelSupplier && empty($object->array_options['options_ksef_number'])) {
                        $langs->load('ksef@ksef');
                        $this->error = $langs->transnoentitiesnoconv('KSEF_CorrectionAbandonUnsent', $object->ref);
                        $this->errors[] = $this->error;
                        dol_syslog("KsefTriggers: BILL_CANCEL of unsent korekta " . $object->ref . " refused", LOG_WARNING);
                        return -1;
                    }
                    if ($isKorekta && $ids) {
                        $c = $cancelSupplier ? 'fk_invoice_supplier' : 'fk_facture';
                        $sql_keep = "UPDATE " . MAIN_DB_PREFIX . "societe_remise_except SET " . $c . " = " . ((int) $object->id)
                            . " WHERE rowid IN (" . implode(',', $ids) . ") AND " . $c . " IS NULL AND " . $c . "_line IS NULL";
                        if (!$this->db->query($sql_keep)) {
                            $this->error = "Failed to keep the credits of abandoned korekta " . $object->ref . ": " . $this->db->lasterror();
                            dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                            return -1;
                        }
                        dol_syslog("KsefTriggers: BILL_CANCEL - abandoned korekta " . $object->ref . " keeps its credits (" . implode(',', $ids) . ")", LOG_INFO);
                    }
                }
                if ($cancelSupplier && !empty($object->id)) {
                    return $this->restateCoveredDeposits($user, (int) $object->id, false);
                }
                return 0;

            case 'BILL_PAYED':
            case 'BILL_SUPPLIER_PAYED':
                if (in_array($object->element, array('facture', 'invoice_supplier'), true) && !empty($object->id)) {
                    dol_include_once('/ksef/lib/ksef.lib.php');
                    $owed = ksefSetPaidOwedRefusal($this->db, $object);
                    if ($owed['why'] !== '') {
                        $this->error = $owed['why'];
                        $this->errors[] = $owed['why'];
                        // core: invoice error
                        if ($owed['call'] === 'rest') {
                            $object->error = $owed['why'];
                        }
                        dol_syslog("KsefTriggers: " . $action . " of " . $object->ref . " refused (" . $owed['call'] . "): currency still owed, no close code", LOG_WARNING);
                        return -1;
                    }
                    dol_include_once('/ksef/lib/ksef_writeoff.lib.php');
                    $woErr = ksefWoRecord($this->db, $user, $object);
                    if ($woErr !== '') {
                        $this->error = $woErr;
                        $this->errors[] = $woErr;
                        return -1;
                    }
                }
                if (in_array($object->element, array('facture', 'invoice_supplier'), true) && !empty($object->id) && in_array((int) $object->type, array(Facture::TYPE_DEPOSIT, Facture::TYPE_REPLACEMENT), true)) {
                    return $this->restateDeposit($user, (int) $object->id, 0, true, 0, $object->element);
                }
                return 0;

            case 'PAYMENT_SUPPLIER_CREATE':
                $doneDeposits = array();
                foreach (is_array($object->amounts ?? null) ? array_keys($object->amounts) : array() as $facid) {
                    dol_include_once('/ksef/lib/ksef.lib.php');
                    $depId = ksefDepositRootId($this->db, (int) $facid, 'invoice_supplier');
                    if ($depId > 0 && !isset($doneDeposits[$depId])) {
                        $doneDeposits[$depId] = true;
                        if ($this->restateDeposit($user, $depId, 0, true, 0, 'invoice_supplier') < 0) {
                            return -1;
                        }
                    }
                }
                return 0;

            case 'BANKACCOUNTLINE_DELETE':
                // links already gone
                if (!empty($object->id)) {
                    $resUrl = $this->db->query("SELECT u1.url_id AS pay, u2.url_id AS soc FROM " . MAIN_DB_PREFIX . "bank_url AS u1 INNER JOIN " . MAIN_DB_PREFIX . "bank_url AS u2"
                        . " ON u2.fk_bank = u1.fk_bank AND u2.type = 'company' WHERE u1.fk_bank = " . (int) $object->id . " AND u1.type = 'payment_supplier'");
                    while ($resUrl && ($o = $this->db->fetch_object($resUrl))) {
                        self::$supplierPaymentSoc[(int) $o->pay] = (int) $o->soc;
                    }
                }
                return 0;

            case 'PAYMENT_SUPPLIER_DELETE':
                // links already dropped
                dol_include_once('/ksef/lib/ksef.lib.php');
                $payInvoices = ksefSupplierPaymentInvoices(null, (int) ($object->id ?? 0));
                if ($payInvoices !== null) {
                    $doneDeposits = array();
                    foreach ($payInvoices as $facid) {
                        $depId = ksefDepositRootId($this->db, (int) $facid, 'invoice_supplier');
                        if ($depId > 0 && !isset($doneDeposits[$depId])) {
                            $doneDeposits[$depId] = true;
                            if ($this->restateDeposit($user, $depId, 0, false, 0, 'invoice_supplier') < 0) {
                                return -1;
                            }
                        }
                    }
                    return 0;
                }
                $paySoc = (int) ($object->socid ?? 0);
                if ($paySoc <= 0 && !empty($object->id) && isset(self::$supplierPaymentSoc[(int) $object->id])) {
                    $paySoc = self::$supplierPaymentSoc[(int) $object->id];
                }
                if ($paySoc > 0) {
                    foreach (ksefDepositsWithCredit($this->db, 'invoice_supplier', $paySoc) as $depId) {
                        if ($this->restateDeposit($user, $depId, 0, false, 0, 'invoice_supplier') < 0) {
                            return -1;
                        }
                    }
                    return 0;
                }
                dol_syslog("KsefTriggers: PAYMENT_SUPPLIER_DELETE " . (int) ($object->id ?? 0) . " without a known supplier: deposits used on validated invoices not rechecked", LOG_WARNING);
                $langs->load('ksef@ksef');
                setEventMessages($langs->trans('KSEF_SupplierPaymentDeleteUnknown'), null, 'warnings');
                foreach (ksefDepositsWithCredit($this->db, 'invoice_supplier', 0) as $depId) {
                    $plan = ksefDepositRestate($this->db, $user, $depId, 0, false, 0, 'invoice_supplier', true);
                    if ((int) $plan['result'] <= 0) {
                        continue;
                    }
                    if ($this->restateDeposit($user, $depId, 0, false, 0, 'invoice_supplier') < 0) {
                        return -1;
                    }
                }
                return 0;

            case 'PAYMENT_CUSTOMER_CREATE':
            case 'PAYMENT_CUSTOMER_DELETE':
                // rows removed after
                $deleting = $action === 'PAYMENT_CUSTOMER_DELETE' ? (int) $object->id : 0;
                $payInvoices = is_array($object->amounts ?? null) ? array_keys($object->amounts) : array();
                if ($deleting > 0) {
                    $payInvoices = array();
                    $resPay = $this->db->query("SELECT fk_facture FROM " . MAIN_DB_PREFIX . "paiement_facture WHERE fk_paiement = " . $deleting);
                    while ($resPay && ($o = $this->db->fetch_object($resPay))) {
                        $payInvoices[] = (int) $o->fk_facture;
                    }
                }
                dol_include_once('/ksef/lib/ksef.lib.php');
                $partPaid = array();
                foreach ($payInvoices as $facid) {
                    $dep = new Facture($this->db);
                    if ($dep->fetch((int) $facid) <= 0 || !ksefInvoiceIsForeign($dep)) {
                        continue;
                    }
                    if ((int) $dep->type !== Facture::TYPE_DEPOSIT) {
                        if ((int) $dep->type !== Facture::TYPE_REPLACEMENT || ksefDepositRootId($this->db, (int) $facid) <= 0
                            || ksefInvoiceSentToKsef($this->db, (int) $facid) || ksefInvoiceInAccounting($this->db, (int) $facid)) {
                            continue;
                        }
                        $dryKor = ksefDerivePlnFromForeign($this->db, (int) $facid, array('dry_run' => true, 'exclude_payment' => $deleting));
                        if ($dryKor['result'] > 0 && ksefDerivePlnFromForeign($this->db, (int) $facid, array('exclude_payment' => $deleting))['result'] < 0) {
                            $this->error = "Cannot derive the PLN amounts of " . $dep->ref;
                            $this->errors[] = $this->error;
                            return -1;
                        }
                        continue;
                    }
                    if (ksefInvoiceSentToKsef($this->db, (int) $facid) || ksefInvoiceInAccounting($this->db, (int) $facid)) {
                        continue;
                    }
                    $partsNow = ksefDepositPaymentParts($this->db, $dep, array('no_rates' => true, 'exclude_payment' => $deleting));
                    $partsBefore = $deleting > 0 ? ksefDepositPaymentParts($this->db, $dep, array('no_rates' => true)) : array();
                    if ($partsNow || $partsBefore) {
                        $derived = ksefDerivePlnFromForeign($this->db, (int) $facid, array('exclude_payment' => $deleting));
                        if ($derived['result'] < 0) {
                            $this->error = "Cannot derive the PLN amounts of " . $dep->ref . ": " . $derived['error'];
                            $this->errors[] = $this->error;
                            dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                            return -1;
                        }
                        $partPaid[] = (int) $facid;
                    }
                }
                $doneDeposits = array();
                foreach ($payInvoices as $facid) {
                    dol_include_once('/ksef/lib/ksef.lib.php');
                    $depId = ksefDepositRootId($this->db, (int) $facid);
                    if ($depId > 0 && !isset($doneDeposits[$depId])) {
                        $doneDeposits[$depId] = true;
                        if ($this->restateDeposit($user, $depId, 0, $deleting === 0, $deleting) < 0) {
                            return -1;
                        }
                    }
                }
                foreach ($partPaid as $facid) {
                    if (ksefDepositCreditPlnFollow($this->db, $facid) < 0) {
                        $this->error = "Cannot update the credit of deposit invoice " . $facid . ": " . $this->db->lasterror();
                        $this->errors[] = $this->error;
                        return -1;
                    }
                }
                return 0;

            case 'BILL_UNPAYED':
            case 'BILL_SUPPLIER_UNPAYED':
                if (($object->element === 'facture' || $object->element === 'invoice_supplier') && !empty($object->id)) {
                    dol_include_once('/ksef/lib/ksef.lib.php');
                    $corrector = ksefGetCorrectingInvoice($this->db, (int) $object->id, 0, $object->element);
                    if ($corrector) {
                        if ($this->isPaymentRejection()) {
                            dol_syslog("KsefTriggers: BILL_UNPAYED of " . $object->ref . " by a payment rejection, although it is corrected by " . $corrector->ref . ": allowed, check the korekta", LOG_WARNING);
                            return 0;
                        }
                        $langs->load('ksef@ksef');
                        $this->error = $langs->transnoentitiesnoconv('KSEF_CorrectionReopenBlocked', $corrector->ref);
                        $this->errors[] = $this->error;
                        dol_syslog("KsefTriggers: BILL_UNPAYED of " . $object->ref . " refused: corrected by " . $corrector->ref, LOG_WARNING);
                        return -1;
                    }
                    dol_include_once('/ksef/lib/ksef_writeoff.lib.php');
                    $woErr = ksefWoOnReopen($this->db, $object);
                    if ($woErr !== '') {
                        $this->error = $woErr;
                        $this->errors[] = $woErr;
                        dol_syslog("KsefTriggers: BILL_UNPAYED of " . $object->ref . " refused: write-off in the ledger", LOG_WARNING);
                        return -1;
                    }
                }
                if ($object->element === 'invoice_supplier' && !empty($object->id)) {
                    return $this->restateCoveredDeposits($user, (int) $object->id, false);
                }
                return 0;

            case 'BILL_MODIFY':
                if (!empty($object->array_options['options_ksef_number'])) {
                    dol_syslog("KsefTriggers: WARNING - Invoice " . $object->ref . " with KSEF number " . $object->array_options['options_ksef_number'] . " was modified", LOG_WARNING);
                }
                return $this->guardXmlFields($object, $langs);

            case 'CURRENCYRATE_DELETE':
            case 'CURRENCYRATE_MODIFY':
                if (!empty($object->id)) {
                    dol_include_once('/ksef/class/ksef_nbp_currency_rate.class.php');
                    $nbp = new KsefNbpCurrencyRate($this->db);
                    if ($nbp->forgetRateRow((int) $object->id, $action === 'CURRENCYRATE_DELETE') < 0) {
                        dol_syslog("KsefTriggers: " . $action . " rate row " . (int) $object->id . ": " . $this->db->lasterror(), LOG_WARNING);
                    }
                }
                return 0;

            case 'DISCOUNT_MODIFY':
                if ($this->guardDepositDiscount($object, $langs) < 0) {
                    return -1;
                }
                if ($this->recordSetoffDate($object) < 0) {
                    return -1;
                }
                if (!empty($object->discount_type) && !empty($object->id) && !$this->isModuleDiscountMove()) {
                    return $this->closeForeignPaidByCredit($user, $object);
                }
                if (!empty($object->discount_type) || empty($object->id) || $this->isModuleDiscountMove()) {
                    return 0;
                }
                dol_include_once('/ksef/lib/ksef.lib.php');
                // REST: no trigger
                if ((!empty($object->fk_facture) || !empty($object->fk_facture_line)) && ksefIsDepositDiscount($this->db, (int) $object->id)) {
                    $this->db->begin();
                    if ($this->restateDeposit($user, (int) $object->fk_facture_source) < 0) {
                        $this->db->rollback();
                        return -1;
                    }
                    $this->db->commit();
                }
                // unlinks credits first
                if (!empty($object->fk_facture)) {
                    $P = MAIN_DB_PREFIX;
                    $resDraft = $this->db->query("SELECT f.rowid, ef.rowid AS ef FROM " . $P . "facture AS f LEFT JOIN " . $P . "facture_extrafields AS ef ON ef.fk_object = f.rowid"
                        . " WHERE f.fk_facture_source = " . (int) $object->fk_facture . " AND f.type = 1 AND f.fk_statut = 0");
                    $drafts = array();
                    while ($resDraft && ($o = $this->db->fetch_object($resDraft))) {
                        $drafts[(int) $o->rowid] = (int) $o->ef;
                    }
                    $discIds = $drafts ? $this->invoiceDiscountIds((int) $object->fk_facture) : array();
                    $ids = $discIds ? "'" . implode(',', $discIds) . "'" : "NULL";
                    foreach ($drafts as $draftId => $ef) {
                        $sql = $ef ? "UPDATE " . $P . "facture_extrafields SET ksef_correction_original_discount_ids = " . $ids . " WHERE rowid = " . $ef
                            : "INSERT INTO " . $P . "facture_extrafields (fk_object, ksef_correction_original_discount_ids) VALUES (" . $draftId . ", " . $ids . ")";
                        if (!$resDraft || $discIds === false || !$this->db->query($sql)) {
                            $this->error = "Failed to refresh the credits of draft korekta $draftId: " . $this->db->lasterror();
                            $this->errors[] = $this->error;
                            dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                            return -1;
                        }
                    }
                }
                return $this->closeForeignPaidByCredit($user, $object);

            case 'LINEBILL_MODIFY':
                return $this->linePlnFollowsCurrency($object);

            case 'LINEBILL_INSERT':
                if (!empty($object->fk_remise_except) && !empty($object->fk_facture) && !$this->isModuleDiscountMove()) {
                    dol_include_once('/ksef/lib/ksef.lib.php');
                    $lock = ksefDiscountChangeLock($this->db, (int) $object->fk_facture);
                    if ($lock['result'] == 'blocked') {
                        $langs->load('ksef@ksef');
                        $this->error = $langs->transnoentitiesnoconv($lock['key'], ...$lock['args']);
                        $this->errors[] = $this->error;
                        dol_syslog("KsefTriggers: LINEBILL_INSERT of discount " . (int) $object->fk_remise_except . " on invoice " . (int) $object->fk_facture . " refused: " . $lock['reason'], LOG_WARNING);
                        return -1;
                    }
                    if ($lock['result'] == 'warn') {
                        dol_syslog("KsefTriggers: LINEBILL_INSERT of discount " . (int) $object->fk_remise_except . " on invoice " . (int) $object->fk_facture . " allowed: " . $lock['reason'], LOG_WARNING);
                    }
                    if (!empty($object->id) && strpos((string) ($object->desc ?? ''), KSEF_DEPOSIT_CREDIT_DESCRIPTION) === 0) {
                        $adopted = ksefAdoptOrderDepositLines($this->db, (int) $object->fk_facture, (int) $object->id);
                        if ($adopted < 0) {
                            $this->error = "Cannot put the deposit credit on line " . (int) $object->id . ": " . $this->db->lasterror();
                            $this->errors[] = $this->error;
                            return -1;
                        }
                        if ($adopted > 0) {
                            $res = $this->db->query("SELECT fk_remise_except FROM " . MAIN_DB_PREFIX . "facturedet WHERE rowid = " . (int) $object->id);
                            $row = $res ? $this->db->fetch_object($res) : null;
                            if ($row) {
                                $object->fk_remise_except = (int) $row->fk_remise_except;
                            }
                        }
                    }
                }
                if (!empty($object->id) && empty($object->array_options['options_ksef_uu_id'])) {
                    $uuid = sprintf(
                        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                        mt_rand(0, 0xffff),
                        mt_rand(0, 0x0fff) | 0x4000,
                        mt_rand(0, 0x3fff) | 0x8000,
                        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
                    );
                    $object->array_options['options_ksef_uu_id'] = $uuid;
                    $object->insertExtraFields();
                }
                return 0;

            case 'BILL_DELETE':
            case 'BILL_UNVALIDATE':
            case 'BILL_SUPPLIER_DELETE':
            case 'BILL_SUPPLIER_UNVALIDATE':
                dol_include_once('/ksef/lib/ksef.lib.php');
                $undoKind = ksefInvoiceKind($object);
                $undoSupplier = $undoKind['element'] === 'invoice_supplier';
                $undoDelete = ($action == 'BILL_DELETE' || $action == 'BILL_SUPPLIER_DELETE');
                if ($action == 'BILL_SUPPLIER_DELETE' && !empty($object->id) && $this->restateCoveredDeposits($user, (int) $object->id, true) < 0) {
                    return -1;
                }
                // no card hook
                if (!empty($object->id) && $object->element === 'facture') {
                    $lock = $action == 'BILL_DELETE' ? ksefInvoiceEditLock($this->db, $object->id, 'delete') : ksefInvoiceModifyLock($this->db, $object->id);
                    // adjustInvoice may reset
                    $adjusting = (int) ($GLOBALS['ksef_adjusting_invoice'] ?? 0) === (int) $object->id;
                    $blockReasons = ($action == 'BILL_DELETE' || $adjusting) ? array('in_flight', 'check_first', 'db_error') : array('in_flight', 'check_first', 'db_error', 'issued', 'offline', 'corrected');
                    if ($lock['result'] == 'blocked' && in_array($lock['reason'], $blockReasons, true)) {
                        $langs->load('ksef@ksef');
                        $this->error = $langs->transnoentitiesnoconv($lock['key'], ...$lock['args']);
                        $this->errors[] = $this->error;
                        dol_syslog("KsefTriggers: " . $action . " of " . $object->ref . " refused: " . $lock['reason'], LOG_WARNING);
                        return -1;
                    }
                    if ($action == 'BILL_UNVALIDATE' && $lock['result'] == 'warn' && in_array($lock['reason'], array('issued_test', 'offline_test'), true)) {
                        dol_syslog("KsefTriggers: BILL_UNVALIDATE of " . $object->ref . " allowed: its KSeF record is in " . $lock['state']['submission']->environment, LOG_WARNING);
                    }
                }
                if ($action == 'BILL_DELETE' && !empty($object->array_options['options_ksef_number'])) {
                    dol_syslog("KsefTriggers: CRITICAL - Invoice " . $object->ref . " with KSEF number " . $object->array_options['options_ksef_number'] . " was deleted", LOG_ALERT);
                }
                if ($object->type == Facture::TYPE_CREDIT_NOTE && !$undoSupplier && !empty($object->fk_facture_source)) {
                    $inForce = ksefGetActiveReplacement($this->db, (int) $object->fk_facture_source);
                    if ($inForce === false) {
                        $this->error = "Cannot check the replacements of invoice " . (int) $object->fk_facture_source . ": " . $this->db->lasterror();
                        $this->errors[] = $this->error;
                        dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                        return -1;
                    }
                    if ($inForce && in_array((int) $object->id, array_map('intval', (array) (ksefDepositState($this->db, (int) $inForce->rowid)['absorb'] ?? array())), true)) {
                        $langs->load('ksef@ksef');
                        $this->error = $langs->transnoentitiesnoconv('KSEF_AbsorbedNoteLocked', $object->ref, $inForce->ref);
                        $this->errors[] = $this->error;
                        dol_syslog("KsefTriggers: $action of credit note " . $object->ref . " refused: korekta " . $inForce->ref . " took it in", LOG_WARNING);
                        return -1;
                    }
                }
                if ($object->type == Facture::TYPE_CREDIT_NOTE && !$undoSupplier) {
                    $revoke = ksefRevokeCreditNoteConversion($this->db, (int) $object->id);
                    if ($revoke['result'] < 0) {
                        if ($revoke['used_by']) {
                            $langs->load('ksef@ksef');
                            $this->error = $langs->transnoentitiesnoconv('KSEF_CreditNoteUsedCannotDraft', $object->ref, implode(', ', $revoke['used_by']));
                            $this->errors[] = $this->error;
                            dol_syslog("KsefTriggers: $action of credit note " . $object->ref . " refused: its credit is used by " . implode(', ', $revoke['used_by']), LOG_WARNING);
                        } else {
                            $this->error = "Failed to revoke the converted credit of credit note " . $object->ref . ": " . $this->db->lasterror();
                            $this->errors[] = $this->error;
                            dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                        }
                        return -1;
                    }
                    if ($revoke['removed'] > 0) {
                        dol_syslog("KsefTriggers: $action - revoked " . $revoke['removed'] . " unused converted discount(s) for credit note " . $object->id, LOG_INFO);
                    }
                    if ($this->restateDeposit($user, (int) $object->id, (int) $object->id, false) < 0) {
                        return -1;
                    }
                }
                if ($object->type == Facture::TYPE_CREDIT_NOTE && $undoSupplier && $this->restateDeposit($user, (int) $object->id, (int) $object->id, false, 0, 'invoice_supplier') < 0) {
                    return -1;
                }

                $draftDelete = $undoDelete && (int) (isset($object->status) ? $object->status : $object->statut) === Facture::STATUS_DRAFT;
                if ($draftDelete && $object->type == Facture::TYPE_REPLACEMENT) {
                    dol_syslog("KsefTriggers: BILL_DELETE of draft replacement " . $object->ref . " - nothing to undo on the original", LOG_DEBUG);
                }
                if ($object->type == Facture::TYPE_REPLACEMENT && !empty($object->fk_facture_source) && !$draftDelete) {
                    $replacement_id = (int) $object->id;
                    $original_id = (int) $object->fk_facture_source;
                    $object->fetch_optionals();

                    $corrector = ksefGetCorrectingInvoice($this->db, $replacement_id, 0, $undoKind['element']);
                    if ($corrector === false) {
                        $this->error = "Cannot check the replacements of invoice $replacement_id: " . $this->db->lasterror();
                        dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                        return -1;
                    }
                    if ($corrector) {
                        $langs->load("ksef@ksef");
                        $this->error = $langs->trans('KSEF_CorrectionChainBlocked', $corrector->ref);
                        $this->errors[] = $this->error;
                        dol_syslog("KsefTriggers: $action blocked - invoice $replacement_id is corrected by " . $corrector->ref, LOG_WARNING);
                        return -1;
                    }

                    $correctionState = ksefCorrectionState($object);
                    $excess_id = (int) ($correctionState['excess'] ?? 0);
                    $excessRows = $excess_id > 0 ? ksefExcessCreditRows($this->db, $correctionState, $undoKind['element']) : array();
                    if ($excessRows === null) {
                        $langs->load('ksef@ksef');
                        $this->error = $langs->transnoentitiesnoconv('KSEF_CorrectionExcessSplit', $object->ref);
                        $this->errors[] = $this->error;
                        dol_syslog("KsefTriggers: $action of " . $object->ref . " refused: the pieces of its split excess credit $excess_id cannot be told apart", LOG_WARNING);
                        return -1;
                    }
                    if ($excessRows === false) {
                        $this->error = "Cannot read the excess credit $excess_id of " . $object->ref . ": " . $this->db->lasterror();
                        dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                        return -1;
                    }
                    foreach ($excessRows as $excess_id) {
                        $rcExc = ksefDeleteCorrectionDiscount($this->db, $excess_id, 0, $undoKind['element']);
                        if ($rcExc < 0) {
                            $users = ksefDiscountUsers($this->db, "rc.rowid IN (" . implode(',', $excessRows) . ")", $undoKind['element']);
                            if ($users) {
                                $langs->load('ksef@ksef');
                                $this->error = $langs->transnoentitiesnoconv('KSEF_CorrectionExcessUsed', $object->ref, implode(', ', $users));
                                dol_syslog("KsefTriggers: $action of " . $object->ref . " refused: its excess credit $excess_id is used by " . implode(', ', $users), LOG_WARNING);
                            } else {
                                $this->error = "Failed to delete the excess credit $excess_id of " . $object->ref . ": " . $this->db->lasterror();
                                dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                            }
                            $this->errors[] = $this->error;
                            return -1;
                        }
                        if ($rcExc > 0) {
                            dol_syslog("KsefTriggers: $action - Deleted excess credit ID $excess_id", LOG_INFO);
                        }
                    }
                    if (!empty($correctionState['recov'])) {
                        $back = ksefRestoreRecoveredCredit($this->db, (array) $correctionState['recov'], $undoKind['element']);
                        if ($back['result'] < 0) {
                            $langs->load('ksef@ksef');
                            $this->error = $back['used_by'] ? $langs->transnoentitiesnoconv('KSEF_CorrectionRecoveredCreditUsed', $object->ref, implode(', ', $back['used_by']))
                                : ($back['changed'] ? $langs->transnoentitiesnoconv('KSEF_CorrectionRecoveredCreditChanged', $object->ref) : "Failed to give back the credit " . $object->ref . " took back: " . $this->db->lasterror());
                            $this->errors[] = $this->error;
                            dol_syslog("KsefTriggers: $action of " . $object->ref . " refused: " . $this->error, LOG_WARNING);
                            return -1;
                        }
                    }

                    require_once DOL_DOCUMENT_ROOT . '/core/class/discount.class.php';
                    $abs = isset($correctionState['abs']) && is_array($correctionState['abs']) ? $correctionState['abs'] : array();
                    foreach (array_merge((array) ($correctionState['cn_used'] ?? array()), (array) ($abs['home'] ?? array())) as $disc_id) {
                        $free = new DiscountAbsolute($this->db);
                        if ($free->fetch((int) $disc_id) > 0 && !empty($free->fk_facture) && $free->unlink_invoice(1) < 0) {
                            $this->error = "Failed to free credit $disc_id again: " . $free->error;
                            dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                            return -1;
                        }
                    }
                    foreach ((array) ($abs['closed'] ?? array()) as $noteId => $ns) {
                        if (!$this->db->query("UPDATE " . MAIN_DB_PREFIX . "facture SET fk_statut = " . (int) $ns['st'] . ", paye = " . (int) $ns['paye']
                            . ", close_code = " . ((string) $ns['cc'] !== '' ? "'" . $this->db->escape($ns['cc']) . "'" : 'NULL')
                            . ", close_note = " . ((string) $ns['cn'] !== '' ? "'" . $this->db->escape($ns['cn']) . "'" : 'NULL') . " WHERE rowid = " . (int) $noteId)) {
                            $this->error = "Failed to reopen credit note $noteId: " . $this->db->lasterror();
                            dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                            return -1;
                        }
                    }

                    $discount_id = $object->array_options['options_ksef_correction_discount_id'] ?? null;
                    if (!empty($discount_id)) {
                        $res_src = $this->db->query("SELECT ksef_correction_discount_id FROM " . MAIN_DB_PREFIX . $undoKind['extrafields'] . "_extrafields WHERE fk_object = " . $original_id);
                        $obj_src = $res_src ? $this->db->fetch_object($res_src) : null;
                        if ($obj_src && (int) $obj_src->ksef_correction_discount_id === (int) $discount_id) {
                            dol_syslog("KsefTriggers: $action - discount ID $discount_id belongs to original $original_id (copied link), not deleted", LOG_WARNING);
                            $discount_id = null;
                        }
                    }
                    if (!empty($discount_id)) {
                        $rcDel = ksefDeleteCorrectionDiscount($this->db, (int) $discount_id, $replacement_id, $undoKind['element']);
                        if ($rcDel < 0) {
                            $this->error = "Failed to delete correction discount $discount_id: " . $this->db->lasterror();
                            dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                            return -1;
                        }
                        if ($rcDel > 0) {
                            dol_syslog("KsefTriggers: $action - Deleted discount ID $discount_id", LOG_INFO);
                        }
                    }

                    foreach ((array) ($correctionState['dep_paid'] ?? array()) as $dp) {
                        foreach ((array) ($dp['rows'] ?? array()) as $rowId => $v) {
                            $sqlBack = "UPDATE " . MAIN_DB_PREFIX . "societe_remise_except SET amount_ht = " . (float) $v[0] . ", amount_tva = " . (float) $v[1] . ", amount_ttc = " . (float) $v[2]
                                . ", multicurrency_amount_ht = " . (float) $v[3] . ", multicurrency_amount_tva = " . (float) $v[4] . ", multicurrency_amount_ttc = " . (float) $v[5]
                                . " WHERE rowid = " . (int) $rowId . " AND fk_facture = " . $replacement_id;
                            if (!$this->db->query($sqlBack)) {
                                $this->error = "Failed to restore deposit row $rowId: " . $this->db->lasterror();
                                dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                                return -1;
                            }
                        }
                    }

                    require_once DOL_DOCUMENT_ROOT . '/core/class/discount.class.php';
                    $stored_ids_str = $object->array_options['options_ksef_correction_original_discount_ids'] ?? '';
                    if (!empty($stored_ids_str)) {
                        $stored_ids = array_map('intval', explode(',', $stored_ids_str));
                        foreach ($stored_ids as $disc_id) {
                            if ($disc_id <= 0) {
                                continue;
                            }
                            $relink_disc = new DiscountAbsolute($this->db);
                            if ($relink_disc->fetch($disc_id) > 0 && ($undoSupplier ? $relink_disc->fk_invoice_supplier : $relink_disc->fk_facture) == $replacement_id) {
                                self::$setoffFrom[$disc_id] = $replacement_id;
                                if ($relink_disc->unlink_invoice(1) < 0 || $relink_disc->link_to_invoice(0, $original_id) < 0) {
                                    $this->error = "Failed to re-link discount $disc_id back to original $original_id: " . $relink_disc->error;
                                    dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                                    return -1;
                                }
                                dol_syslog("KsefTriggers: $action - Re-linked discount ID $disc_id back to original $original_id", LOG_INFO);
                            }
                        }
                    }

                    $depUndo = !empty($correctionState['dep']) ? (int) $correctionState['dep'] : 0;
                    if ($depUndo > 0 && $this->undoDepositConversion($replacement_id, $correctionState, $undoKind) < 0) {
                        return -1;
                    }

                    $undoCls = $undoKind['class'];
                    $undoT = MAIN_DB_PREFIX . $undoKind['table'];
                    $originalInvoice = new $undoCls($this->db);
                    if ($originalInvoice->fetch($original_id) > 0) {
                        $originalInvoice->fetch_optionals();
                        $is_chain = ($originalInvoice->type == Facture::TYPE_REPLACEMENT
                            && !empty($originalInvoice->array_options['options_ksef_correction_original_ht']));

                        $srcState = (isset($correctionState['src']) && is_array($correctionState['src'])) ? $correctionState['src'] : null;
                        $srcKnown = $srcState !== null && $srcState['st'] !== null;
                        $srcExact = $srcKnown && ((int) $srcState['st'] === Facture::STATUS_ABANDONED || (string) $srcState['cc'] !== '');
                        $noteSql = ($srcState !== null && (string) $srcState['cn'] !== '') ? "'" . $this->db->escape($srcState['cn']) . "'" : 'NULL';
                        $noteSet = $srcState !== null ? ", close_note = " . $noteSql : '';
                        $srcSet = $srcKnown ? "fk_statut = " . ((int) $srcState['st']) . ", paye = " . ((int) $srcState['paye'])
                            . ", close_code = " . ((string) $srcState['cc'] !== '' ? "'" . $this->db->escape($srcState['cc']) . "'" : 'NULL')
                            . ", close_note = " . $noteSql : '';
                        $new_discount_id = 0;

                        if ($is_chain) {
                            $grandparent = new $undoCls($this->db);
                            $calc = false;
                            if ((int) $originalInvoice->fk_facture_source > 0 && $grandparent->fetch((int) $originalInvoice->fk_facture_source) > 0) {
                                $calc = ksefCorrectionSettled($this->db, $grandparent);
                            }
                            if ($calc === false) {
                                $this->error = "Cannot read the correction chain of invoice $original_id";
                                dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                                return -1;
                            }
                            $parentState = ksefCorrectionState($originalInvoice);
                            $parentShort = $parentState['short'] ?? null;
                            if (is_array($parentShort) && in_array($parentShort['ans'] ?? '', array('keep', 'paid'), true)) {
                                $sk = $parentShort['ans'] === 'paid' ? 'settled' : 'keep';
                                $calc[$sk] = (float) price2num($calc[$sk] + (float) $parentShort['ttc'], 'MT');
                                $calc[$sk . '_mc'] = (float) price2num($calc[$sk . '_mc'] + (float) $parentShort['mttc'], 'MT');
                            }
                            $calc['settled'] = (float) price2num($calc['settled'] - (float) ($parentState['abs']['out'] ?? 0), 'MT');
                            $calc['settled_mc'] = (float) price2num($calc['settled_mc'] - (float) ($parentState['abs']['out_mc'] ?? 0), 'MT');
                            $resPl = $this->db->query("SELECT COALESCE(SUM(amount_ttc), 0) AS a, COALESCE(SUM(multicurrency_amount_ttc), 0) AS m FROM " . MAIN_DB_PREFIX . "societe_remise_except WHERE " . $undoKind['col'] . " = " . $original_id);
                            $objPl = $resPl ? $this->db->fetch_object($resPl) : null;
                            if (!$objPl) {
                                $this->error = "Cannot read the credits of chain parent $original_id: " . $this->db->lasterror();
                                dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                                return -1;
                            }
                            $parentRoom = (float) $originalInvoice->total_ttc - (float) $objPl->a;
                            $this->carryKeptShortfall($calc, $parentRoom, (float) $originalInvoice->multicurrency_total_ttc - (float) $objPl->m);
                            $settled = $calc['settled'];
                            $settled_mc = $calc['settled_mc'];

                            dol_syslog("KsefTriggers: $action - Chain settled=$settled before chain parent $original_id", LOG_INFO);

                            if ($settled > 0 || (empty($parentState['excess']) && $settled < min(0.0, $parentRoom) - 0.005)) {
                                $new_discount_id = $this->createCorrectionDiscount($user, $langs, $conf, $originalInvoice, $grandparent, $calc);
                                if ($new_discount_id < 0) {
                                    return -1;
                                }
                                dol_syslog("KsefTriggers: $action - Re-created discount ID $new_discount_id (amount=$settled) for chain parent $original_id", LOG_INFO);
                            } elseif (!empty($originalInvoice->array_options['options_ksef_correction_discount_id'])) {
                                if (!$this->db->query("UPDATE " . MAIN_DB_PREFIX . $undoKind['extrafields'] . "_extrafields SET ksef_correction_discount_id = NULL WHERE fk_object = " . $original_id)) {
                                    $this->error = "Failed to clear the correction discount of chain parent $original_id: " . $this->db->lasterror();
                                    dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                                    return -1;
                                }
                            }

                            if ($srcExact) {
                                if ((int) $srcState['st'] === Facture::STATUS_ABANDONED && !empty($new_discount_id)
                                    && !$this->db->query("UPDATE " . MAIN_DB_PREFIX . "societe_remise_except SET " . $undoKind['col'] . " = NULL WHERE rowid = " . ((int) $new_discount_id))) {
                                    $this->error = "Failed to free the correction discount of abandoned chain parent $original_id: " . $this->db->lasterror();
                                    dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                                    return -1;
                                }
                                $sql_chain_restore = "UPDATE " . $undoT . " SET " . $srcSet . " WHERE rowid = " . $original_id;
                                $remain = 'snapshot';
                            } else {
                                $parentNow = new $undoCls($this->db);
                                if ($parentNow->fetch($original_id) <= 0) {
                                    $this->error = "Cannot fetch chain parent $original_id";
                                    dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                                    return -1;
                                }
                                $remain = (float) price2num($parentNow->getRemainToPay(0), 'MT');
                                $parentClosed = $remain < 0.005;
                                $sql_chain_restore = "UPDATE " . $undoT
                                    . " SET fk_statut = " . ($parentClosed ? Facture::STATUS_CLOSED : Facture::STATUS_VALIDATED)
                                    . ", paye = " . ($parentClosed ? 1 : 0)
                                    . ", close_code = NULL" . $noteSet
                                    . " WHERE rowid = " . $original_id;
                            }
                            if (!$this->db->query($sql_chain_restore)) {
                                $this->error = "Failed to restore chain parent status: " . $this->db->lasterror();
                                dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                                return -1;
                            }
                            dol_syslog("KsefTriggers: $action - Restored chain parent $original_id status (settled=$settled, remain=$remain)", LOG_INFO);
                        } elseif ($srcKnown) {
                            $sql_restore = "UPDATE " . $undoT . " SET " . $srcSet . " WHERE rowid = " . $original_id;
                            if (!$this->db->query($sql_restore)) {
                                $this->error = "Failed to restore original invoice: " . $this->db->lasterror();
                                dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                                return -1;
                            }
                            dol_syslog("KsefTriggers: $action - Restored original $original_id to its state before " . $object->ref . " (status " . $srcState['st'] . ", close code " . var_export($srcState['cc'], true) . ")", LOG_INFO);
                        } else {
                            $orig_paid = $originalInvoice->getSommePaiement(0);
                            if ($orig_paid > 0 && $orig_paid >= $originalInvoice->total_ttc) {
                                $restore_status = Facture::STATUS_CLOSED;
                                $restore_paye = 1;
                            } else {
                                $restore_status = Facture::STATUS_VALIDATED;
                                $restore_paye = 0;
                            }
                            $sql_restore = "UPDATE " . $undoT
                                . " SET fk_statut = " . $restore_status
                                . ", close_code = NULL"
                                . ", paye = " . $restore_paye . $noteSet
                                . " WHERE rowid = " . $original_id;
                            if (!$this->db->query($sql_restore)) {
                                $this->error = "Failed to restore original invoice: " . $this->db->lasterror();
                                dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                                return -1;
                            }
                            dol_syslog("KsefTriggers: $action - Restored original $original_id to status=$restore_status paye=$restore_paye", LOG_INFO);
                        }
                    }

                    // setDraft keeps paye
                    if (!$undoDelete) {
                        if (!$this->db->query("UPDATE " . $undoT . " SET paye = 0, close_code = NULL WHERE rowid = " . $replacement_id)) {
                            $this->error = "Failed to reopen " . $object->ref . ": " . $this->db->lasterror();
                            dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                            return -1;
                        }
                        $object->paye = 0;
                        $object->close_code = null;
                    }

                    $object->array_options['options_ksef_correction_original_ht'] = null;
                    $object->array_options['options_ksef_correction_original_tva'] = null;
                    $object->array_options['options_ksef_correction_original_ttc'] = null;
                    $object->array_options['options_ksef_correction_discount_id'] = null;
                    $object->array_options['options_ksef_correction_settled_amount'] = null;
                    $object->array_options['options_ksef_correction_settled_amount_mc'] = null;
                    if (array_key_exists('options_ksef_correction_state', $object->array_options)) {
                        $keepState = array_intersect_key($correctionState, array('short_ans' => 1, 'absorb' => 1, 'cn_use' => 1));
                        $object->array_options['options_ksef_correction_state'] = $keepState ? json_encode($keepState) : null;
                    }

                    $coreBack = $action == 'BILL_UNVALIDATE' || $action == 'BILL_SUPPLIER_UNVALIDATE'
                        || ($action == 'BILL_DELETE' && isModEnabled('stock') && getDolGlobalString('STOCK_CALCULATE_ON_BILL') && $this->deleteWarehouse() != -1);
                    if ($coreBack && !empty($correctionState['stock'])) {
                        $incoming = array();
                        $skip = $this->correctionStockSkipped($object, $correctionState, $action == 'BILL_DELETE', $incoming);
                        if ($skip === false || $this->revertCorrectionStock($user, $langs, $object, $correctionState['stock'], $skip, $incoming) < 0) {
                            return -1;
                        }
                    }

                    if ($action == 'BILL_UNVALIDATE') {
                        $disc_ids = $this->invoiceDiscountIds($original_id);
                        if ($disc_ids !== false) {
                            $object->array_options['options_ksef_correction_original_discount_ids'] =
                                !empty($disc_ids) ? implode(',', $disc_ids) : null;
                        }
                    } else {
                        $object->array_options['options_ksef_correction_original_discount_ids'] = null;
                    }

                    if ($object->insertExtraFields() < 0) {
                        $this->error = "Failed to clear the correction fields of " . $object->ref . ": " . $object->error;
                        dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                        return -1;
                    }
                    if ($depUndo > 0 && $this->restateDeposit($user, $depUndo, $replacement_id, false, 0, $undoKind['element']) < 0) {
                        return -1;
                    }
                }
                // core frees discounts
                if ($action == 'BILL_DELETE' && $object->type == Facture::TYPE_REPLACEMENT && !ksefDeleteCopiedDiscounts($this->db, (int) $object->id)) {
                    $this->error = "Failed to delete the discount rows copied onto " . $object->ref . ": " . $this->db->lasterror();
                    $this->errors[] = $this->error;
                    dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                    return -1;
                }
                return 0;

            case 'LINEBILL_DELETE':
                if (!empty($object->fk_remise_except) && !empty($object->fk_facture)) {
                    dol_include_once('/ksef/lib/ksef.lib.php');
                    if (!ksefDeleteCopiedDiscounts($this->db, (int) $object->fk_facture, (int) $object->id)) {
                        $this->error = "Failed to delete the discount row copied onto line " . (int) $object->id . ": " . $this->db->lasterror();
                        $this->errors[] = $this->error;
                        dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                        return -1;
                    }
                }
                return 0;

            default:
                return 0;
        }
    }
}