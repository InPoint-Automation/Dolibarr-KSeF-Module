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
 * \file    ksef/class/ksef_session_cache.class.php
 * \ingroup ksef
 * \brief   KSeF session cache
 */

dol_include_once('/ksef/class/ksef_sync_state.class.php');

/**
 * @brief Session invoice cache
 */
class KsefSessionCache
{
    const SYNC_TYPE = 'upo';

    const WINDOW_DAYS = 30;

    const RUN_STALE_SECONDS = 600;

    /** @var array */
    public $limits = array('list' => 6, 'sessions' => 15, 'upo' => 50, 'page_size' => 1000);

    /** @var string */
    public $error = '';

    /** @var DoliDB */
    private $db;

    /**
     * @brief Constructor
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * @brief Load sweep state
     * @param string $environment KSeF environment
     * @return KsefSyncState
     */
    public function loadState($environment)
    {
        $state = new KsefSyncState($this->db);
        $state->load(self::SYNC_TYPE);
        if ((string) $state->environment !== (string) $environment) {
            $state->environment = $environment;
            $state->hwm_date = '';
            $state->last_sync_errors = '';
            $state->saveSweep();
        }
        return $state;
    }

    /**
     * @brief Coverage dot
     * @param string $environment KSeF environment
     * @return array|null
     */
    public function coverageDot($environment)
    {
        global $langs, $conf;
        $state = new KsefSyncState($this->db);
        $state->load(self::SYNC_TYPE);
        $own = (string) $state->environment === (string) $environment;
        $ts = $own ? (int) $state->getContinuationDate() : 0;
        $res = $this->db->query("SELECT MAX(date_session) AS d FROM " . MAIN_DB_PREFIX . "ksef_session_invoice"
            . " WHERE entity = " . (int) $conf->entity . " AND environment = '" . $this->db->escape($environment) . "'");
        $o = $res ? $this->db->fetch_object($res) : null;
        $ts = max($ts, $o ? (int) $o->d : 0);
        if ($this->missingSessionCount($environment) === 0) {
            $res = $this->db->query("SELECT MAX(s.date_acceptance) AS d FROM " . MAIN_DB_PREFIX . "ksef_submissions AS s"
                . " INNER JOIN " . MAIN_DB_PREFIX . "facture AS f ON f.rowid = s.fk_facture"
                . " WHERE s.status = 'ACCEPTED' AND s.environment = '" . $this->db->escape($environment) . "'"
                . " AND f.entity IN (" . getEntity('invoice') . ") AND s.ksef_number IS NOT NULL AND s.ksef_number <> ''");
            $o = $res ? $this->db->fetch_object($res) : null;
            $ts = max($ts, $o ? (int) $o->d : 0);
        }
        $missing = $this->missingUpoCount($environment);
        if ($ts <= 0 && $missing === 0) {
            return null;
        }
        $title = $ts > 0 ? $langs->transnoentitiesnoconv('KSEF_UpoSweepDot', dol_print_date($ts, 'day'), $missing)
            : $langs->transnoentitiesnoconv('KSEF_UpoSweepDotNotStarted', $missing);
        return array('ts' => $ts, 'title' => $title, 'warning' => $own ? (string) $state->last_sync_errors : '');
    }

    /**
     * @brief Reset sweep state
     * @param DoliDB $db Database handler
     * @return void
     */
    public static function resetState($db)
    {
        $state = new KsefSyncState($db);
        $state->load(self::SYNC_TYPE);
        $state->hwm_date = '';
        $state->last_sync_errors = '';
        $state->saveSweep();
    }

    /**
     * @brief Cached session reference
     * @param string $ksefNumber KSeF number
     * @param string $environment KSeF environment
     * @return string '' when not cached
     */
    public function sessionFor($ksefNumber, $environment)
    {
        global $conf;
        $sql = "SELECT session_reference FROM " . MAIN_DB_PREFIX . "ksef_session_invoice"
            . " WHERE entity = " . (int) $conf->entity
            . " AND environment = '" . $this->db->escape($environment) . "'"
            . " AND ksef_number = '" . $this->db->escape($ksefNumber) . "'";
        $resql = $this->db->query($sql);
        $obj = $resql ? $this->db->fetch_object($resql) : null;
        return $obj ? (string) $obj->session_reference : '';
    }

    /**
     * @brief Session is cached
     * @param string $sessionRef Session reference
     * @param string $environment KSeF environment
     * @return bool
     */
    private function hasSession($sessionRef, $environment)
    {
        global $conf;
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "ksef_session_invoice"
            . " WHERE entity = " . (int) $conf->entity
            . " AND environment = '" . $this->db->escape($environment) . "'"
            . " AND session_reference = '" . $this->db->escape($sessionRef) . "'";
        $resql = $this->db->query($sql . $this->db->plimit(1));
        return $resql && $this->db->fetch_object($resql);
    }

    /**
     * @brief Store session invoices
     * @param string $environment KSeF environment
     * @param string $sessionRef Session reference
     * @param int $dateSession
     * @param array $invoices
     * @return int <0 on error
     */
    public function store($environment, $sessionRef, $dateSession, $invoices)
    {
        global $conf;
        $n = 0;
        $table = MAIN_DB_PREFIX . "ksef_session_invoice";
        foreach ($invoices as $inv) {
            $num = (string) ($inv['ksefNumber'] ?? '');
            if ($num === '' || (int) ($inv['status']['code'] ?? 0) !== 200) {
                continue;
            }
            $acq = !empty($inv['acquisitionDate']) ? strtotime($inv['acquisitionDate']) : false;
            $ref = (string) ($inv['referenceNumber'] ?? '');
            $resql = $this->db->query("SELECT rowid, session_reference FROM " . $table . " WHERE entity = " . (int) $conf->entity
                . " AND environment = '" . $this->db->escape($environment) . "' AND ksef_number = '" . $this->db->escape($num) . "'");
            if (!$resql) {
                $this->error = $this->db->lasterror();
                return -1;
            }
            $obj = $this->db->fetch_object($resql);
            if ($obj && (string) $obj->session_reference === (string) $sessionRef) {
                continue;
            }
            if ($obj) {
                $sql = "UPDATE " . $table . " SET session_reference = '" . $this->db->escape($sessionRef) . "'"
                    . ", invoice_reference = " . ($ref !== '' ? "'" . $this->db->escape($ref) . "'" : "NULL")
                    . ", date_session = " . ((int) $dateSession ?: "NULL")
                    . ", date_acquisition = " . ($acq ? (int) $acq : "NULL")
                    . " WHERE rowid = " . (int) $obj->rowid;
            } else {
                $sql = "INSERT INTO " . $table . " (entity, environment, session_reference, ksef_number, invoice_reference, date_session, date_acquisition, date_creation)"
                    . " VALUES (" . (int) $conf->entity . ", '" . $this->db->escape($environment) . "', '" . $this->db->escape($sessionRef) . "'"
                    . ", '" . $this->db->escape($num) . "', " . ($ref !== '' ? "'" . $this->db->escape($ref) . "'" : "NULL")
                    . ", " . ((int) $dateSession ?: "NULL") . ", " . ($acq ? (int) $acq : "NULL") . ", " . (int) dol_now() . ")";
            }
            if (!$this->db->query($sql)) {
                $this->error = $this->db->lasterror();
                return -1;
            }
            $n++;
        }
        return $n;
    }

    /**
     * @brief No-session SQL condition
     * @param string $environment KSeF environment
     * @return string
     */
    private function noSessionSql($environment)
    {
        return " s.status = 'ACCEPTED' AND s.environment = '" . $this->db->escape($environment) . "'"
            . " AND f.entity IN (" . getEntity('invoice') . ")"
            . " AND s.ksef_number IS NOT NULL AND s.ksef_number <> ''"
            . " AND (s.upo_xml IS NULL OR s.upo_xml = '')"
            . " AND (s.ksef_session_reference IS NULL OR s.ksef_session_reference = '')"
            . " AND (s.api_response IS NULL OR s.api_response NOT LIKE '%\"session_reference\":\"_%')"
            . " AND (s.ksef_reference IS NULL OR s.ksef_reference NOT LIKE '%-SO-%')";
    }

    /**
     * @brief Rows without session
     * @param string $environment KSeF environment
     * @return int
     */
    public function missingSessionCount($environment)
    {
        $sql = "SELECT COUNT(*) AS n FROM " . MAIN_DB_PREFIX . "ksef_submissions AS s"
            . " INNER JOIN " . MAIN_DB_PREFIX . "facture AS f ON f.rowid = s.fk_facture"
            . " WHERE" . $this->noSessionSql($environment);
        $resql = $this->db->query($sql);
        $obj = $resql ? $this->db->fetch_object($resql) : null;
        return $obj ? (int) $obj->n : 0;
    }

    /**
     * @brief Rows without UPO
     * @param string $environment KSeF environment
     * @return int
     */
    public function missingUpoCount($environment)
    {
        $sql = "SELECT COUNT(*) AS n FROM " . MAIN_DB_PREFIX . "ksef_submissions AS s"
            . " INNER JOIN " . MAIN_DB_PREFIX . "facture AS f ON f.rowid = s.fk_facture"
            . " WHERE s.status = 'ACCEPTED' AND s.environment = '" . $this->db->escape($environment) . "'"
            . " AND f.entity IN (" . getEntity('invoice') . ")"
            . " AND s.ksef_number IS NOT NULL AND s.ksef_number <> ''"
            . " AND (s.upo_xml IS NULL OR s.upo_xml = '')";
        $resql = $this->db->query($sql);
        $obj = $resql ? $this->db->fetch_object($resql) : null;
        return $obj ? (int) $obj->n : 0;
    }

    /**
     * @brief First sweep start
     * @param string $environment KSeF environment
     * @return int 0 when none
     */
    public function startPoint($environment)
    {
        $sql = "SELECT MIN(s.date_acceptance) AS da, MIN(s.date_submission) AS ds, MIN(SUBSTRING(s.ksef_number, 12, 8)) AS kd"
            . " FROM " . MAIN_DB_PREFIX . "ksef_submissions AS s"
            . " INNER JOIN " . MAIN_DB_PREFIX . "facture AS f ON f.rowid = s.fk_facture"
            . " WHERE" . $this->noSessionSql($environment);
        $resql = $this->db->query($sql);
        $obj = $resql ? $this->db->fetch_object($resql) : null;
        if (!$obj) {
            return 0;
        }
        $cands = array();
        foreach (array($obj->da, $obj->ds) as $ts) {
            if ((int) $ts > 0) {
                $cands[] = (int) $ts;
            }
        }
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', (string) $obj->kd, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $cands[] = (new DateTime($m[1] . '-' . $m[2] . '-' . $m[3] . ' 00:00:00', new DateTimeZone('Europe/Warsaw')))->getTimestamp();
        }
        return $cands ? min($cands) - 86400 : 0;
    }

    /**
     * @brief Fill session references
     * @param string $environment KSeF environment
     * @return int Rows updated
     */
    public function matchSubmissions($environment)
    {
        global $conf;
        $sql = "SELECT s.rowid, s.ksef_reference, s.api_response, c.session_reference"
            . " FROM " . MAIN_DB_PREFIX . "ksef_submissions AS s"
            . " INNER JOIN " . MAIN_DB_PREFIX . "facture AS f ON f.rowid = s.fk_facture"
            . " INNER JOIN " . MAIN_DB_PREFIX . "ksef_session_invoice AS c ON c.ksef_number = s.ksef_number"
            . " AND c.environment = s.environment AND c.entity = " . (int) $conf->entity
            . " WHERE s.status = 'ACCEPTED' AND s.environment = '" . $this->db->escape($environment) . "'"
            . " AND f.entity IN (" . getEntity('invoice') . ")"
            . " AND (s.ksef_session_reference IS NULL OR s.ksef_session_reference = '')";
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return 0;
        }
        dol_include_once('/ksef/class/ksef_submission.class.php');
        $rows = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $probe = new KsefSubmission($this->db);
            $probe->ksef_reference = $obj->ksef_reference;
            $probe->api_response = $obj->api_response;
            if ($probe->getSessionReference() === '') {
                $rows[(int) $obj->rowid] = (string) $obj->session_reference;
            }
        }
        $this->db->free($resql);
        $n = 0;
        foreach ($rows as $rowid => $ref) {
            $resql = $this->db->query("UPDATE " . MAIN_DB_PREFIX . "ksef_submissions SET ksef_session_reference = '" . $this->db->escape($ref) . "'"
                . " WHERE rowid = " . (int) $rowid . " AND status = 'ACCEPTED' AND (ksef_session_reference IS NULL OR ksef_session_reference = '')");
            if ($resql && $this->db->affected_rows($resql) > 0) {
                $n++;
            }
        }
        if ($n > 0) {
            dol_syslog("KsefSessionCache: session reference set on " . $n . " accepted row(s) from the session cache", LOG_INFO);
        }
        return $n;
    }

    /**
     * @brief Run sweep step
     * @param KsefClient $client
     * @param KsefSyncState $state
     * @param string $environment KSeF environment
     * @return array
     */
    public function sweep($client, $state, $environment)
    {
        $r = array('sessions' => 0, 'invoices' => 0, 'list_calls' => 0, 'session_calls' => 0, 'rate_limited' => false, 'error' => '', 'until' => 0);
        $hwm = (int) $state->getContinuationDate();
        if ($hwm <= 0) {
            $hwm = $this->startPoint($environment);
        }
        $r['until'] = $hwm;
        if ($hwm <= 0) {
            return $r;
        }
        $now = dol_now();
        $window = self::WINDOW_DAYS * 86400;
        $stop = false;
        while (!$stop && $hwm < $now) {
            if ($this->limits['list'] - $r['list_calls'] < 2) {
                break;
            }
            $to = min($hwm + $window, $now);
            $sessions = array();
            foreach (array('Online', 'Batch') as $type) {
                $token = null;
                do {
                    if ($r['list_calls'] >= $this->limits['list']) {
                        $stop = true;
                        break 2;
                    }
                    $page = $client->listSessions(array('sessionType' => $type, 'dateCreatedFrom' => $hwm, 'dateCreatedTo' => $to, 'pageSize' => $this->limits['page_size']), $token);
                    $r['list_calls']++;
                    if ($page === false) {
                        return $this->sweepFailed($r, $client, $state, $hwm);
                    }
                    // halve window
                    if ($token === null && !empty($page['continuation_token']) && $to - $hwm > 86400) {
                        $window = (int) (($to - $hwm) / 2);
                        continue 3;
                    }
                    $sessions = array_merge($sessions, $page['sessions']);
                    $token = $page['continuation_token'];
                } while (!empty($token));
            }
            if ($stop) {
                break;
            }
            usort($sessions, function ($a, $b) {
                return strcmp((string) $a['date_created'], (string) $b['date_created']);
            });
            $newHwm = $to;
            foreach ($sessions as $sess) {
                $ref = (string) $sess['reference_number'];
                $created = !empty($sess['date_created']) ? (int) strtotime($sess['date_created']) : 0;
                $closed = (int) $sess['status_code'] >= 200;
                if ($ref === '' || ($closed && ((int) $sess['successful_count'] === 0 || $this->hasSession($ref, $environment)))) {
                    continue;
                }
                $invoices = array();
                $token = null;
                do {
                    if ($r['session_calls'] >= $this->limits['sessions']) {
                        $newHwm = min($newHwm, $created ?: $hwm);
                        $stop = true;
                        break 2;
                    }
                    $page = $client->listSessionInvoices($ref, $token, $this->limits['page_size']);
                    $r['session_calls']++;
                    if ($page === false) {
                        return $this->sweepFailed($r, $client, $state, min($newHwm, $created ?: $hwm));
                    }
                    $invoices = array_merge($invoices, $page['invoices']);
                    $token = $page['continuation_token'];
                } while (!empty($token));
                $n = $this->store($environment, $ref, $created, $invoices);
                if ($n < 0) {
                    $r['error'] = $this->error;
                    return $this->saveUntil($r, $state, min($newHwm, $created ?: $hwm));
                }
                $r['sessions']++;
                $r['invoices'] += $n;
                if (!$closed) {
                    $newHwm = min($newHwm, $created ?: $hwm);
                }
            }
            if ($newHwm <= $hwm) {
                break;
            }
            $hwm = $newHwm;
            $window = min($window * 2, self::WINDOW_DAYS * 86400);
            $this->saveUntil($r, $state, $hwm);
        }
        $r['until'] = $hwm;
        return $r;
    }

    /**
     * @brief Save continuation point
     * @param array $r
     * @param KsefSyncState $state
     * @param int $until
     * @return array
     */
    private function saveUntil($r, $state, $until)
    {
        if ($until > 0 && $until > (int) $state->getContinuationDate()) {
            $state->hwm_date = date('c', $until);
            $state->saveSweep();
        }
        $r['until'] = max((int) $until, (int) $state->getContinuationDate());
        return $r;
    }

    /**
     * @brief Handle failed call
     * @param array $r
     * @param KsefClient $client
     * @param KsefSyncState $state
     * @param int $until
     * @return array
     */
    private function sweepFailed($r, $client, $state, $until)
    {
        if ((int) $client->last_http_code === 429) {
            $r['rate_limited'] = true;
            $state->setRateLimit((int) $client->retry_after_seconds);
        }
        $r['error'] = (string) $client->error;
        dol_syslog("KsefSessionCache: sweep stopped: HTTP " . (int) $client->last_http_code . " " . $r['error'], LOG_WARNING);
        return $this->saveUntil($r, $state, $until);
    }
}
