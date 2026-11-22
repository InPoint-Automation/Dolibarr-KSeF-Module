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
 * \file    ksef/class/ksef_sync_state.class.php
 * \ingroup ksef
 * \brief   Incoming sync state
 */

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/security.lib.php';

/**
 * @brief Incoming sync state
 */
class KsefSyncState
{
    private $db;
    private $entity;
    private $sync_type;
    public $hwm_date;
    public $last_sync;
    public $last_sync_new;
    public $last_sync_existing;
    public $last_sync_total;
    public $last_sync_errors;
    /** @var int */
    public $last_sync_fetch_errors;
    /** @var int */
    public $last_sync_unsupported;
    public $rate_limit_until;
    /** @var string ts|http */
    public $last_fail;
    public $fetch_reference;
    public $fetch_status;
    public $fetch_started;
    public $fetch_key;
    public $fetch_iv;
    public $fetch_error;
    public $process_file;
    public $process_total;
    public $process_offset;
    public $process_new;
    public $process_existing;
    public $fetch_parts;
    public $fetch_hwm_data;
    public $heartbeat;
    public $environment;
    private $other_process_file = '';

    const FETCH_STATUS_PROCESSING = 'PROCESSING';
    const FETCH_STATUS_READY_TO_PROCESS = 'READY_TO_PROCESS';
    const FETCH_STATUS_DOWNLOADING = 'DOWNLOADING';
    const FETCH_STATUS_PROCESSING_BATCHES = 'PROCESSING_BATCHES';
    const FETCH_STATUS_FAILED = 'FAILED';
    const FETCH_STATUS_TIMEOUT = 'TIMEOUT';

    const PROCESS_BATCH_SIZE = 1000;

    const DEFAULT_HWM = '2026-01-31T00:00:00+01:00';

    const CONST_PREFIX = 'KSEF_SYNC_';
    const FETCH_TIMEOUT_SECONDS = 1200;
    const PROCESS_TIMEOUT_SECONDS = 900;

    /**
     * @brief Constructor
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        global $conf;
        $this->db = $db;
        $this->entity = $conf->entity;
    }

    /**
     * @brief Constant name
     * @param string $field
     * @return string
     */
    private function getConstName($field)
    {
        return self::CONST_PREFIX . strtoupper($field) . '_' . strtoupper($this->sync_type);
    }

    /**
     * @brief Load state
     * @param string $sync_type
     * @return int
     */
    public function load($sync_type)
    {
        $this->sync_type = $sync_type;

        $this->hwm_date = getDolGlobalString($this->getConstName('HWM_DATE'), '');
        $this->last_sync = getDolGlobalInt($this->getConstName('LAST_SYNC'), 0);
        $this->last_sync_new = getDolGlobalInt($this->getConstName('LAST_NEW'), 0);
        $this->last_sync_existing = getDolGlobalInt($this->getConstName('LAST_EXISTING'), 0);
        $this->last_sync_total = getDolGlobalInt($this->getConstName('LAST_TOTAL'), 0);
        $this->last_sync_errors = getDolGlobalString($this->getConstName('LAST_ERRORS'), '');
        $this->last_sync_fetch_errors = getDolGlobalInt($this->getConstName('LAST_FETCH_ERRORS'), 0);
        $this->last_sync_unsupported = getDolGlobalInt($this->getConstName('LAST_UNSUPPORTED'), 0);
        $this->rate_limit_until = getDolGlobalInt($this->getConstName('RATE_LIMIT'), 0);
        $this->last_fail = getDolGlobalString($this->getConstName('LAST_FAIL'), '');

        $this->fetch_reference = getDolGlobalString($this->getConstName('FETCH_REF'), '');
        $this->fetch_status = getDolGlobalString($this->getConstName('FETCH_STATUS'), '');
        $this->fetch_started = getDolGlobalInt($this->getConstName('FETCH_STARTED'), 0);
        $this->fetch_key = dol_decode(getDolGlobalString($this->getConstName('FETCH_KEY'), ''));
        $this->fetch_iv = dol_decode(getDolGlobalString($this->getConstName('FETCH_IV'), ''));
        $this->fetch_error = getDolGlobalString($this->getConstName('FETCH_ERROR'), '');
        $this->fetch_parts = getDolGlobalString($this->getConstName('FETCH_PARTS'), '');
        $this->fetch_hwm_data = getDolGlobalString($this->getConstName('FETCH_HWM'), '');

        $this->process_file = getDolGlobalString($this->getConstName('PROC_FILE'), '');
        $this->process_total = getDolGlobalInt($this->getConstName('PROC_TOTAL'), 0);
        $this->process_offset = getDolGlobalInt($this->getConstName('PROC_OFFSET'), 0);
        $this->process_new = getDolGlobalInt($this->getConstName('PROC_NEW'), 0);
        $this->process_existing = getDolGlobalInt($this->getConstName('PROC_EXISTING'), 0);
        $this->heartbeat = getDolGlobalInt($this->getConstName('HEARTBEAT'), 0);
        $this->environment = getDolGlobalString($this->getConstName('ENV'), '');
        if (!function_exists('ksefGetEnvironment')) {
            dol_include_once('/ksef/lib/ksef.lib.php');
        }
        if ($this->environment !== '' && $this->environment !== ksefGetEnvironment()) {
            $this->clearForOtherEnvironment();
        }
        $this->getDisplayValues();

        return 1;
    }

    /**
     * @brief Hide other environment
     * @return void
     */
    private function clearForOtherEnvironment()
    {
        $this->hwm_date = self::DEFAULT_HWM;
        $this->last_sync = 0;
        $this->last_sync_new = 0;
        $this->last_sync_existing = 0;
        $this->last_sync_total = 0;
        $this->last_sync_errors = '';
        $this->last_sync_fetch_errors = 0;
        $this->last_sync_unsupported = 0;
        $this->rate_limit_until = 0;
        $this->last_fail = '';
        $this->fetch_reference = '';
        $this->fetch_status = '';
        $this->fetch_started = 0;
        $this->fetch_key = '';
        $this->fetch_iv = '';
        $this->fetch_error = '';
        $this->fetch_parts = '';
        $this->fetch_hwm_data = '';
        $this->other_process_file = $this->process_file;
        $this->process_file = '';
        $this->process_total = 0;
        $this->process_offset = 0;
        $this->process_new = 0;
        $this->process_existing = 0;
    }

    /**
     * @brief Save state
     * @return int <0 on error
     */
    public function save()
    {
        $error = 0;

        if (dolibarr_set_const($this->db, $this->getConstName('HWM_DATE'), $this->hwm_date ?: '', 'chaine', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('LAST_SYNC'), $this->last_sync ?: 0, 'int', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('LAST_NEW'), $this->last_sync_new ?: 0, 'int', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('LAST_EXISTING'), $this->last_sync_existing ?: 0, 'int', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('LAST_TOTAL'), $this->last_sync_total ?: 0, 'int', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('LAST_ERRORS'), $this->last_sync_errors ?: '', 'chaine', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('LAST_FETCH_ERRORS'), $this->last_sync_fetch_errors ?: 0, 'int', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('LAST_UNSUPPORTED'), $this->last_sync_unsupported ?: 0, 'int', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('RATE_LIMIT'), $this->rate_limit_until ?: 0, 'int', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('LAST_FAIL'), $this->last_fail ?: '', 'chaine', 0, '', $this->entity) < 0) $error++;

        if (dolibarr_set_const($this->db, $this->getConstName('FETCH_REF'), $this->fetch_reference ?: '', 'chaine', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('FETCH_STATUS'), $this->fetch_status ?: '', 'chaine', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('FETCH_STARTED'), $this->fetch_started ?: 0, 'int', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('FETCH_KEY'), dol_encode($this->fetch_key ?: ''), 'chaine', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('FETCH_IV'), dol_encode($this->fetch_iv ?: ''), 'chaine', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('FETCH_ERROR'), $this->fetch_error ?: '', 'chaine', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('FETCH_PARTS'), $this->fetch_parts ?: '', 'chaine', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('FETCH_HWM'), $this->fetch_hwm_data ?: '', 'chaine', 0, '', $this->entity) < 0) $error++;

        if (dolibarr_set_const($this->db, $this->getConstName('PROC_FILE'), $this->process_file ?: '', 'chaine', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('PROC_TOTAL'), $this->process_total ?: 0, 'int', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('PROC_OFFSET'), $this->process_offset ?: 0, 'int', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('PROC_NEW'), $this->process_new ?: 0, 'int', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('PROC_EXISTING'), $this->process_existing ?: 0, 'int', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('HEARTBEAT'), $this->heartbeat ?: 0, 'int', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('ENV'), $this->environment ?: '', 'chaine', 0, '', $this->entity) < 0) $error++;
        $this->getDisplayValues();

        return $error ? -1 : 1;
    }

    /**
     * @brief Save sweep fields
     * @return int <0 on error
     */
    public function saveSweep()
    {
        $error = 0;
        if (dolibarr_set_const($this->db, $this->getConstName('HWM_DATE'), $this->hwm_date ?: '', 'chaine', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('ENV'), $this->environment ?: '', 'chaine', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('LAST_SYNC'), $this->last_sync ?: 0, 'int', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('LAST_ERRORS'), $this->last_sync_errors ?: '', 'chaine', 0, '', $this->entity) < 0) $error++;
        $this->getDisplayValues();
        return $error ? -1 : 1;
    }

    /**
     * @brief Claim sweep run
     * @param int $staleAfter Stale seconds
     * @return bool True if claimed
     */
    public function claimRun($staleAfter)
    {
        $name = $this->getConstName('HEARTBEAT');
        $now = dol_now();
        $sql = "SELECT value FROM " . MAIN_DB_PREFIX . "const WHERE name = '" . $this->db->escape($name) . "' AND entity = " . (int) $this->entity;
        $resql = $this->db->query($sql);
        if ($resql && !$this->db->fetch_object($resql)) {
            dolibarr_set_const($this->db, $name, '0', 'chaine', 0, '', $this->entity);
        }
        // same-length timestamps
        $sql = "UPDATE " . MAIN_DB_PREFIX . "const SET value = '" . (int) $now . "'";
        $sql .= " WHERE name = '" . $this->db->escape($name) . "' AND entity = " . (int) $this->entity;
        $sql .= " AND (value = '' OR value = '0' OR value < '" . (int) ($now - (int) $staleAfter) . "')";
        $resql = $this->db->query($sql);
        if (!$resql || (int) $this->db->affected_rows($resql) !== 1) {
            return false;
        }
        $this->heartbeat = $now;
        return true;
    }

    /**
     * @brief Release run
     * @return void
     */
    public function releaseRun()
    {
        $this->heartbeat = 0;
        dolibarr_set_const($this->db, $this->getConstName('HEARTBEAT'), '0', 'chaine', 0, '', $this->entity);
    }

    /**
     * @brief Save progress
     * @return int 0 if reset, <0 on error
     */
    public function saveProgress()
    {
        $error = 0;
        if ((string) $this->fetch_reference !== '' && $this->storedFetchReference() !== (string) $this->fetch_reference) {
            return 0;
        }
        $this->heartbeat = dol_now();
        if (!empty($this->process_file) && file_exists($this->process_file)) {
            @touch($this->process_file);
        }
        if (dolibarr_set_const($this->db, $this->getConstName('FETCH_STATUS'), $this->fetch_status ?: '', 'chaine', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('PROC_FILE'), $this->process_file ?: '', 'chaine', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('PROC_TOTAL'), $this->process_total ?: 0, 'int', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('PROC_OFFSET'), $this->process_offset ?: 0, 'int', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('PROC_NEW'), $this->process_new ?: 0, 'int', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('PROC_EXISTING'), $this->process_existing ?: 0, 'int', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('HEARTBEAT'), $this->heartbeat, 'int', 0, '', $this->entity) < 0) $error++;
        return $error ? -1 : 1;
    }

    /**
     * @brief Stored fetch reference
     * @return string
     */
    public function storedFetchReference()
    {
        $sql = "SELECT value FROM " . MAIN_DB_PREFIX . "const";
        $sql .= " WHERE name = '" . $this->db->escape($this->getConstName('FETCH_REF')) . "'";
        $sql .= " AND entity = " . (int) $this->entity;
        $resql = $this->db->query($sql);
        if ($resql && ($obj = $this->db->fetch_object($resql))) {
            return (string) $obj->value;
        }
        return '';
    }

    /**
     * @brief Save environment
     * @param string $environment
     * @return int <0 on error
     */
    public function adoptEnvironment($environment)
    {
        $this->environment = $environment;
        return dolibarr_set_const($this->db, $this->getConstName('ENV'), $environment, 'chaine', 0, '', $this->entity) < 0 ? -1 : 1;
    }

    /**
     * @brief Reset state
     * @param string $environment
     * @param string $hwm Continuation point
     * @return int <0 on error
     */
    public function resetAll($environment, $hwm = '')
    {
        foreach (array($this->process_file, $this->other_process_file) as $file) {
            if (!empty($file) && file_exists($file)) {
                @unlink($file);
            }
        }
        $this->other_process_file = '';
        $this->hwm_date = $hwm !== '' ? $hwm : self::DEFAULT_HWM;
        $this->last_sync = 0;
        $this->last_sync_new = 0;
        $this->last_sync_existing = 0;
        $this->last_sync_total = 0;
        $this->last_sync_errors = '';
        $this->last_sync_fetch_errors = 0;
        $this->last_sync_unsupported = 0;
        $this->rate_limit_until = 0;
        $this->last_fail = '';
        $this->fetch_reference = '';
        $this->fetch_status = '';
        $this->fetch_started = 0;
        $this->fetch_key = '';
        $this->fetch_iv = '';
        $this->fetch_error = '';
        $this->fetch_parts = '';
        $this->fetch_hwm_data = '';
        $this->process_file = '';
        $this->process_total = 0;
        $this->process_offset = 0;
        $this->process_new = 0;
        $this->process_existing = 0;
        $this->heartbeat = 0;
        $this->environment = $environment;
        return $this->save();
    }

    /**
     * @brief Export running
     * @return bool
     */
    public function isFetchInProgress()
    {
        if (empty($this->fetch_reference)) {
            return false;
        }
        return in_array($this->fetch_status, array(
            self::FETCH_STATUS_PROCESSING,
            self::FETCH_STATUS_READY_TO_PROCESS,
            self::FETCH_STATUS_DOWNLOADING,
            self::FETCH_STATUS_PROCESSING_BATCHES,
        ));
    }

    /**
     * @brief Export timed out
     * @return bool
     */
    public function isFetchTimedOut()
    {
        if (!$this->isFetchInProgress() || empty($this->fetch_started)) {
            return false;
        }
        return (dol_now() - max((int) $this->fetch_started, (int) $this->heartbeat)) > self::FETCH_TIMEOUT_SECONDS;
    }

    /**
     * @brief Clear fetch state
     * @return int <0 on error
     */
    public function clearFetchState()
    {
        $this->fetch_reference = '';
        $this->fetch_status = '';
        $this->fetch_started = 0;
        $this->fetch_key = '';
        $this->fetch_iv = '';
        $this->fetch_error = '';
        $this->fetch_parts = '';
        $this->fetch_hwm_data = '';
        return $this->save();
    }

    /**
     * @brief Processing running
     * @return bool
     */
    public function isProcessingInProgress()
    {
        return !empty($this->process_file) && file_exists($this->process_file);
    }

    /**
     * @brief Processing timed out
     * @return bool
     */
    public function isProcessingTimedOut()
    {
        if (!$this->isProcessingInProgress()) {
            return false;
        }
        $mtime = @filemtime($this->process_file);
        if ($mtime === false) {
            return true;
        }
        return (dol_now() - max((int) $mtime, (int) $this->heartbeat)) > self::PROCESS_TIMEOUT_SECONDS;
    }

    /**
     * @brief Clear processing state
     * @return int <0 on error
     */
    public function clearProcessingState()
    {
        if (!empty($this->process_file) && file_exists($this->process_file)) {
            @unlink($this->process_file);
        }
        $this->process_file = '';
        $this->process_total = 0;
        $this->process_offset = 0;
        $this->process_new = 0;
        $this->process_existing = 0;
        return $this->save();
    }

    /**
     * @brief Processing progress
     * @return array|null Null when idle
     */
    public function getProcessingProgress()
    {
        if (!$this->isProcessingInProgress()) {
            return null;
        }
        return array(
            'total' => $this->process_total,
            'processed' => $this->process_offset,
            'new' => $this->process_new,
            'existing' => $this->process_existing,
            'percent' => $this->process_total > 0 ? round(($this->process_offset / $this->process_total) * 100) : 0
        );
    }

    /**
     * @brief Continuation date
     * @return int|null
     */
    public function getContinuationDate()
    {
        if (empty($this->hwm_date)) return null;
        return strtotime($this->hwm_date);
    }

    public $continuation_date;
    public $last_sync_date;

    /**
     * @brief Fill display dates
     * @return void
     */
    public function getDisplayValues()
    {
        $this->continuation_date = $this->getContinuationDate();
        $this->last_sync_date = $this->last_sync ?: null;
    }

    /**
     * @brief Rate limited
     * @return bool
     */
    public function isRateLimited()
    {
        return $this->rate_limit_until > dol_now();
    }

    /**
     * @brief Record rate limit
     * @param int $seconds Retry-After
     * @return int <0 on error
     */
    public function setRateLimit($seconds)
    {
        $seconds = (int) $seconds > 0 ? (int) $seconds : 60;
        $this->rate_limit_until = dol_now() + $seconds;
        return dolibarr_set_const($this->db, $this->getConstName('RATE_LIMIT'), $this->rate_limit_until, 'int', 0, '', $this->entity) < 0 ? -1 : 1;
    }

    /**
     * @brief Record start failure
     * @param int|null $httpCode null clears
     * @return int <0 on error
     */
    public function noteStartFailure($httpCode)
    {
        $this->last_fail = $httpCode === null ? '' : dol_now() . '|' . (int) $httpCode;
        return dolibarr_set_const($this->db, $this->getConstName('LAST_FAIL'), $this->last_fail, 'chaine', 0, '', $this->entity) < 0 ? -1 : 1;
    }

    /**
     * @brief Rate limit left
     * @return int
     */
    public function secondsUntilNextSync()
    {
        if (!$this->isRateLimited()) return 0;
        return $this->rate_limit_until - dol_now();
    }

    /**
     * @brief Rate limit expiry
     * @return string
     */
    public function getRateLimitExpiryFormatted()
    {
        if (!$this->isRateLimited()) return '';
        return dol_print_date($this->rate_limit_until, 'dayhour');
    }

    /**
     * @brief Sync allowed now
     * @return bool
     */
    public function canSyncNow()
    {
        if ($this->isFetchInProgress()) {
            if ($this->isFetchTimedOut()) {
                $this->fetch_status = self::FETCH_STATUS_TIMEOUT;
                $this->clearFetchState();
            } else {
                return false;
            }
        }
        if ($this->isProcessingInProgress()) {
            if ($this->isProcessingTimedOut()) {
                $this->clearProcessingState();
            } else {
                return false;
            }
        }
        if ($this->isRateLimited()) return false;
        return true;
    }

    /**
     * @brief Fetch resumable
     * @return bool
     */
    public function canResumeFetch()
    {
        if (!$this->isFetchInProgress() || $this->isFetchTimedOut()) {
            return false;
        }
        if (!in_array($this->fetch_status, array(self::FETCH_STATUS_PROCESSING, self::FETCH_STATUS_READY_TO_PROCESS), true)) {
            return false;
        }
        return !$this->isProcessingInProgress() && !$this->isRateLimited();
    }

    /**
     * @brief Claim fetch status
     * @param string $from Expected status
     * @param string $to
     * @return bool True if claimed
     */
    public function claimFetchStatus($from, $to)
    {
        $sql = "UPDATE " . MAIN_DB_PREFIX . "const SET value = '" . $this->db->escape($to) . "'";
        $sql .= " WHERE name = '" . $this->db->escape($this->getConstName('FETCH_STATUS')) . "'";
        $sql .= " AND entity = " . (int) $this->entity;
        $sql .= " AND value = '" . $this->db->escape($from) . "'";
        $resql = $this->db->query($sql);
        if (!$resql || (int) $this->db->affected_rows($resql) !== 1) {
            return false;
        }
        $this->fetch_status = $to;
        return true;
    }
}