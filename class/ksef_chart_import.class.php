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
 * \file    ksef/class/ksef_chart_import.class.php
 * \ingroup ksef
 * \brief   Chart import checks
 */

dol_include_once('/ksef/lib/ksef_chart.lib.php');

/**
 * @brief Chart import rules
 */
class KsefChartImport
{
    /** @var DoliDB */
    public $db;

    /** @var string */
    public $error = '';

    /** @var array */
    public $errors = array();

    /**
     * @brief Constructor
     * @param DoliDB $db
     */
    public function __construct($db)
    {
        global $langs;
        $this->db = $db;
        $langs->loadLangs(array('ksef@ksef', 'admin', 'accountancy'));
    }

    /**
     * @brief Active chart code
     * @return string '' if none
     */
    private function chart()
    {
        static $cache = array();
        $id = getDolGlobalInt('CHARTOFACCOUNTS');
        if (!isset($cache[$id])) {
            $res = $id > 0 ? $this->db->query("SELECT pcg_version FROM " . MAIN_DB_PREFIX . "accounting_system WHERE rowid = " . $id) : false;
            $o = $res ? $this->db->fetch_object($res) : null;
            $cache[$id] = $o ? (string) $o->pcg_version : '';
        }
        return $cache[$id];
    }

    /**
     * @brief Cell value
     * @param array $record
     * @param int $pos
     * @return string
     */
    private function cell($record, $pos)
    {
        return isset($record[$pos]['val']) ? trim((string) $record[$pos]['val']) : '';
    }

    /**
     * @brief Account rowid
     * @param string $pcg
     * @param string $number
     * @return int
     */
    private function accountId($pcg, $number)
    {
        global $conf;
        $res = $this->db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "accounting_account WHERE entity = " . ((int) $conf->entity)
            . " AND fk_pcg_version = '" . $this->db->escape($pcg) . "' AND account_number = '" . $this->db->escape($number) . "'");
        $o = $res ? $this->db->fetch_object($res) : null;
        return $o ? (int) $o->rowid : 0;
    }

    /**
     * @brief Check account number
     * @param array $arrayrecord
     * @param array $arrayfield
     * @param int $pos
     * @return string
     */
    public function computeNumber(&$arrayrecord, $arrayfield, $pos)
    {
        global $langs, $user;
        $n = $this->cell($arrayrecord, $pos);
        if (!$user->hasRight('accounting', 'chartofaccount')) {
            $this->error = $langs->transnoentitiesnoconv('KSEF_ChartImpRights', $langs->transnoentitiesnoconv('Permission50440'));
            return $n;
        }
        if ($n !== '' && substr($n, -1) === '0' && !getDolGlobalString('ACCOUNTING_MANAGE_ZERO')) {
            $this->error = $langs->transnoentitiesnoconv('KSEF_ChartImpZero', $n, rtrim($n, '0'), $langs->transnoentitiesnoconv('ACCOUNTING_MANAGE_ZERO'));
        }
        $chart = $this->chart();
        if ($chart !== '' && $n !== '' && $this->accountId($chart, $n) > 0) {
            $this->error = trim($this->error . ' ' . $langs->transnoentitiesnoconv('KSEF_ChartImpExists', $n, $chart));
        }
        return $n;
    }

    /**
     * @brief Parent to rowid
     * @param array $arrayrecord
     * @param array $arrayfield
     * @param int $pos
     * @return string
     */
    public function computeParent(&$arrayrecord, $arrayfield, $pos)
    {
        global $langs;
        $p = preg_replace('/^ref:/i', '', $this->cell($arrayrecord, $pos));
        $n = isset($arrayfield['aa.account_number']) ? $this->cell($arrayrecord, $arrayfield['aa.account_number']) : '';
        $chart = $this->chart();
        if ($p === '' || $p === $n || $chart === '') {
            return '0';
        }
        $id = $this->accountId($chart, $p);
        if ($id <= 0) {
            $this->error = $langs->transnoentitiesnoconv('KSEF_ChartImpNoParent', $p, $chart);
        }
        return (string) $id;
    }

    /**
     * @brief Account type
     * @param array $arrayrecord
     * @param array $arrayfield
     * @param int $pos
     * @return string
     */
    public function computeType(&$arrayrecord, $arrayfield, $pos)
    {
        $t = strtoupper($this->cell($arrayrecord, $pos));
        if ($t === '' && isset($arrayfield['aa.account_number'])) {
            $t = ksefChartTypeFor($this->cell($arrayrecord, $arrayfield['aa.account_number']));
        }
        if (isset($arrayrecord[$pos])) {
            $arrayrecord[$pos]['type'] = $t === '' ? 0 : 1;
        }
        return $t;
    }

    /**
     * @brief Active chart code
     * @param array $arrayrecord
     * @param array $arrayfield
     * @param int $pos
     * @return string
     */
    public function computePcgVersion(&$arrayrecord, $arrayfield, $pos)
    {
        global $langs;
        $chart = $this->chart();
        if ($chart === '') {
            $this->error = $langs->transnoentitiesnoconv('KSEF_ChartImpNoChart', $langs->transnoentitiesnoconv('Selectchartofaccounts'));
        }
        return $chart;
    }
}
