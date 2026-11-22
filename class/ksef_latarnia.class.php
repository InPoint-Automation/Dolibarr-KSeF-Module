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
 * \file    ksef/class/ksef_latarnia.class.php
 * \ingroup ksef
 * \brief   Latarnia API client
 */

dol_include_once('/ksef/lib/ksef.lib.php');

class KsefLatarnia
{
    private $db;
    public $error = '';
    public $errors = array();
    const API_URL = 'https://api-latarnia.ksef.mf.gov.pl';
    const API_URL_TEST = 'https://api-latarnia-test.ksef.mf.gov.pl';
    const API_TIMEOUT = 10;
    const CONNECT_TIMEOUT = 5;
    const STALE_THRESHOLD = 900;
    const MESSAGES_STALE_THRESHOLD = 21600;
    const UPCOMING_HOURS = 72;
    const EVENTS_KEEP_DAYS = 45;
    const EVENTS_MAX = 50;

    /**
     * @brief Constructor
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * @brief Latarnia base URL
     * @param string|null $environment Null for configured
     * @return string
     */
    public static function baseUrl($environment = null)
    {
        if ($environment === null) {
            $environment = ksefGetEnvironment();
        }
        $default = (strtoupper((string) $environment) === 'TEST') ? self::API_URL_TEST : self::API_URL;
        return ksefEndpointOverride('LATARNIA', $default);
    }

    /**
     * @brief Source label
     * @param string|null $environment Null for configured
     * @return string TEST, PRD, OVERRIDE
     */
    public static function environmentLabel($environment = null)
    {
        $baseUrl = self::baseUrl($environment);
        if ($baseUrl === self::API_URL_TEST) {
            return 'TEST';
        }
        return ($baseUrl === self::API_URL) ? 'PRD' : 'OVERRIDE';
    }

    /**
     * @brief Fetch /status
     * @return array|false False on error
     */
    public function fetchStatus()
    {
        $this->error = '';

        $url = self::baseUrl() . '/status';
        dol_syslog("KsefLatarnia::fetchStatus Fetching from: " . $url, LOG_DEBUG);

        $data = $this->makeRequest($url);
        if ($data === false) {
            return false;
        }

        if (!isset($data['status'])) {
            $this->error = 'Latarnia API: Invalid response - missing status field';
            dol_syslog("KsefLatarnia::fetchStatus ERROR: " . $this->error, LOG_ERR);
            return false;
        }

        $validStatuses = array('AVAILABLE', 'MAINTENANCE', 'FAILURE', 'TOTAL_FAILURE');
        if (!in_array($data['status'], $validStatuses)) {
            $this->error = 'Latarnia API: Unknown status: ' . $data['status'];
            dol_syslog("KsefLatarnia::fetchStatus WARNING: " . $this->error, LOG_WARNING);
        }

        if (!isset($data['messages'])) {
            $data['messages'] = array();
        }

        dol_syslog("KsefLatarnia::fetchStatus Result: " . $data['status'] . " (" . count($data['messages']) . " messages)", LOG_INFO);
        return $data;
    }

    /**
     * @brief Fetch /messages
     * @return array|false False on error
     */
    public function fetchMessages()
    {
        $this->error = '';

        $url = self::baseUrl() . '/messages';
        dol_syslog("KsefLatarnia::fetchMessages Fetching from: " . $url, LOG_DEBUG);

        $data = $this->makeRequest($url);
        if ($data === false) {
            return false;
        }

        if (!is_array($data)) {
            $this->error = 'Latarnia API: Invalid response - expected array';
            dol_syslog("KsefLatarnia::fetchMessages ERROR: " . $this->error, LOG_ERR);
            return false;
        }

        dol_syslog("KsefLatarnia::fetchMessages Result: " . count($data) . " messages", LOG_INFO);
        return $data;
    }

    /**
     * @brief Refresh status cache
     * @param bool $withMessages Also /messages
     * @return array|false False on failure
     */
    public function checkAndCache($withMessages = true)
    {
        global $conf;

        require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';

        $cached = self::getCachedStatus();
        $environment = self::environmentLabel();

        $data = $this->fetchStatus();

        if ($data === false) {
            $statusError = $this->error;
            $cached['status'] = 'UNREACHABLE';
            $cached['timestamp'] = dol_now();
            $cached['environment'] = $environment;
            $cached['error'] = $statusError;
            dolibarr_set_const($this->db, 'KSEF_LATARNIA_ERROR', $statusError, 'chaine', 0, '', $conf->entity);
            dolibarr_set_const($this->db, 'KSEF_LATARNIA_STATUS', json_encode($cached), 'chaine', 0, '', $conf->entity);
            $this->error = $statusError;
            return false;
        }

        $result = array(
            'status' => $data['status'],
            'active' => (is_array($data['messages']) ? array_values($data['messages']) : array()),
            'messages' => $cached['messages'],
            'timestamp' => dol_now(),
            'messages_timestamp' => (int) ($cached['messages_timestamp'] ?? 0),
            'environment' => $environment,
            'error' => null,
            'messages_error' => ($cached['messages_error'] ?? null),
        );

        $stored = self::storedEvents($environment);
        if (!$withMessages && self::ongoingGone($stored, $result['active'])) {
            $withMessages = true;
        }

        $messagesRead = false;
        if ($withMessages) {
            $messages = $this->fetchMessages();
            if ($messages === false) {
                dol_syslog("KsefLatarnia::checkAndCache /messages failed, keeping the cached list: " . $this->error, LOG_WARNING);
                $result['messages_error'] = $this->error;
            } else {
                $result['messages'] = array_values($messages);
                $result['messages_timestamp'] = dol_now();
                $result['messages_error'] = null;
                $messagesRead = true;
            }
        }

        dolibarr_set_const($this->db, 'KSEF_LATARNIA_STATUS', json_encode($result), 'chaine', 0, '', $conf->entity);
        dolibarr_set_const($this->db, 'KSEF_LATARNIA_ERROR', '', 'chaine', 0, '', $conf->entity);
        $this->error = '';

        // 30 days held
        $events = self::mergeEvents($messagesRead ? array() : $stored,
            $messagesRead ? array_merge($result['messages'], $result['active']) : $result['active'], dol_now());
        if ($this->saveEvents($environment, $events)) {
            ksefApplyOutageDeadlines($this->db, $environment);
        }

        return $result;
    }

    /**
     * @brief Refresh stale cache
     * @return array
     */
    public function refreshIfStale()
    {
        $cached = self::getCachedStatus();

        $sameSource = !isset($cached['environment']) || $cached['environment'] === self::environmentLabel();
        if ($sameSource && $cached['timestamp'] > 0 && (dol_now() - $cached['timestamp']) < self::STALE_THRESHOLD) {
            return $cached;
        }

        dol_syslog("KsefLatarnia::refreshIfStale Cache stale or empty, refreshing", LOG_DEBUG);
        $messagesAge = dol_now() - (int) ($cached['messages_timestamp'] ?? 0);
        $fresh = $this->checkAndCache($messagesAge >= self::MESSAGES_STALE_THRESHOLD);

        if ($fresh !== false) {
            return $fresh;
        }

        if ($cached['timestamp'] > 0) {
            dol_syslog("KsefLatarnia::refreshIfStale Fetch failed, returning stale cache", LOG_WARNING);
            return $cached;
        }

        return self::getCachedStatus();
    }

    /**
     * @brief Cached status
     * @return array
     */
    public static function getCachedStatus()
    {
        $raw = getDolGlobalString('KSEF_LATARNIA_STATUS', '');
        if (empty($raw)) {
            return array(
                'status' => 'UNKNOWN',
                'messages' => array(),
                'timestamp' => 0,
                'error' => null,
            );
        }

        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            return array(
                'status' => 'UNKNOWN',
                'messages' => array(),
                'timestamp' => 0,
                'error' => 'Failed to decode cached status',
            );
        }

        if (!isset($data['status'])) {
            $data['status'] = 'UNKNOWN';
        }
        if (!isset($data['messages'])) {
            $data['messages'] = array();
        }
        if (!isset($data['timestamp'])) {
            $data['timestamp'] = 0;
        }

        return $data;
    }

    /**
     * @brief Current status message
     * @param array $cached Cached status
     * @return array|null
     */
    public static function currentMessage($cached)
    {
        $status = $cached['status'] ?? '';
        if (!in_array($status, array('MAINTENANCE', 'FAILURE', 'TOTAL_FAILURE'), true)) {
            return null;
        }

        if (!array_key_exists('active', $cached)) {
            return !empty($cached['messages'][0]) && is_array($cached['messages'][0]) ? $cached['messages'][0] : null;
        }

        $best = null;
        $bestStart = -1;
        $fallback = null;
        foreach ((array) $cached['active'] as $msg) {
            if (!is_array($msg)) {
                continue;
            }
            if ($fallback === null) {
                $fallback = $msg;
            }
            if (($msg['category'] ?? '') !== $status) {
                continue;
            }
            $start = !empty($msg['start']) ? (int) strtotime($msg['start']) : 0;
            if ($best === null || $start > $bestStart) {
                $best = $msg;
                $bestStart = $start;
            }
        }

        return $best !== null ? $best : $fallback;
    }

    /**
     * @brief Upcoming maintenance
     * @param array    $cached Cached status
     * @param int      $hours  Lead hours
     * @param int|null $now    Null for now
     * @return array Sorted by start
     */
    public static function upcomingMaintenance($cached, $hours = self::UPCOMING_HOURS, $now = null)
    {
        if ($now === null) {
            $now = dol_now();
        }
        $byEvent = array();
        foreach ((array) ($cached['messages'] ?? array()) as $msg) {
            if (!is_array($msg) || ($msg['type'] ?? '') !== 'MAINTENANCE_ANNOUNCEMENT' || empty($msg['start'])) {
                continue;
            }
            $key = isset($msg['eventId']) ? 'e' . $msg['eventId'] : 'i' . ($msg['id'] ?? count($byEvent));
            if (!isset($byEvent[$key]) || (int) ($msg['version'] ?? 0) > (int) ($byEvent[$key]['version'] ?? 0)) {
                $byEvent[$key] = $msg;
            }
        }

        $out = array();
        foreach ($byEvent as $msg) {
            $start = (int) strtotime($msg['start']);
            $end = !empty($msg['end']) ? (int) strtotime($msg['end']) : 0;
            if ($start <= 0 || $start - $now > $hours * 3600) {
                continue;
            }
            if ($end > 0 ? $end <= $now : $start <= $now) {
                continue;
            }
            if ($start <= $now && ($cached['status'] ?? '') === 'MAINTENANCE') {
                continue;
            }
            $msg['start_ts'] = $start;
            $msg['end_ts'] = $end;
            $out[] = $msg;
        }
        usort($out, function ($a, $b) {
            return $a['start_ts'] - $b['start_ts'];
        });

        return $out;
    }

    /**
     * @brief Stored events
     * @param string|null $source Null for configured
     * @return array Newest first
     */
    public static function storedEvents($source = null)
    {
        if ($source === null) {
            $source = self::environmentLabel();
        }
        $all = json_decode(getDolGlobalString('KSEF_LATARNIA_EVENTS', ''), true);
        if (!is_array($all) || empty($all[$source]) || !is_array($all[$source])) {
            return array();
        }
        return array_values($all[$source]);
    }

    /**
     * @brief Merge into events
     * @param array $stored Stored events
     * @param array $messages
     * @param int   $now Unix time
     * @return array Newest first
     */
    public static function mergeEvents($stored, $messages, $now)
    {
        $groups = array();
        foreach ((array) $messages as $msg) {
            if (!is_array($msg) || empty($msg['start']) || empty($msg['category'])) {
                continue;
            }
            $key = isset($msg['eventId']) ? 'e' . $msg['eventId'] : 'i' . ($msg['id'] ?? '');
            $groups[$key][] = $msg;
        }

        $byKey = array();
        foreach ((array) $stored as $ev) {
            if (is_array($ev) && !empty($ev['key'])) {
                $byKey[$ev['key']] = $ev;
            }
        }
        foreach ($groups as $key => $list) {
            $ev = array('key' => $key, 'id' => '', 'event' => null, 'category' => '', 'type' => '', 'start' => 0, 'end' => 0, 'version' => 0);
            $endRank = '';
            foreach ($list as $msg) {
                $start = (int) strtotime($msg['start']);
                if ($start > 0 && ($ev['start'] === 0 || $start < $ev['start'])) {
                    $ev['start'] = $start;
                }
                if ($ev['id'] === '' || ($msg['type'] ?? '') !== 'FAILURE_END') {
                    $ev['id'] = (string) ($msg['id'] ?? '');
                }
                if ($ev['type'] !== 'FAILURE_END') {
                    $ev['type'] = (string) ($msg['type'] ?? '');
                }
                $ev['event'] = isset($msg['eventId']) ? (int) $msg['eventId'] : null;
                $ev['category'] = (string) $msg['category'];
                $ev['version'] = max($ev['version'], (int) ($msg['version'] ?? 0));
                $rank = sprintf('%010d-%s', (int) ($msg['version'] ?? 0), (string) ($msg['published'] ?? ''));
                if (!empty($msg['end']) && $rank >= $endRank) {
                    $ev['end'] = (int) strtotime($msg['end']);
                    $endRank = $rank;
                }
            }
            if ($ev['start'] > 0) {
                $byKey[$key] = $ev;
            }
        }

        $keepFrom = $now - self::EVENTS_KEEP_DAYS * 86400;
        $out = array();
        foreach ($byKey as $ev) {
            if (!in_array($ev['category'], array('MAINTENANCE', 'FAILURE', 'TOTAL_FAILURE'), true)) {
                continue;
            }
            if ((int) $ev['end'] > 0 && (int) $ev['end'] < $keepFrom) {
                continue;
            }
            $out[] = $ev;
        }
        usort($out, function ($a, $b) {
            return $b['start'] - $a['start'];
        });

        return array_slice($out, 0, self::EVENTS_MAX);
    }

    /**
     * @brief Open failure gone
     * @param array $stored Stored events
     * @param array $active /status messages
     * @return bool
     */
    public static function ongoingGone($stored, $active)
    {
        $activeKeys = array();
        foreach ((array) $active as $msg) {
            if (is_array($msg)) {
                $activeKeys[isset($msg['eventId']) ? 'e' . $msg['eventId'] : 'i' . ($msg['id'] ?? '')] = true;
            }
        }
        foreach ((array) $stored as $ev) {
            if (empty($ev['end']) && !isset($activeKeys[$ev['key'] ?? ''])) {
                return true;
            }
        }
        return false;
    }

    /**
     * @brief Save events
     * @param string $source Source label
     * @param array  $events
     * @return bool True if changed
     */
    private function saveEvents($source, $events)
    {
        global $conf;

        $all = json_decode(getDolGlobalString('KSEF_LATARNIA_EVENTS', ''), true);
        if (!is_array($all)) {
            $all = array();
        }
        if (($all[$source] ?? array()) == $events) {
            return false;
        }
        if (empty($events)) {
            unset($all[$source]);
        } else {
            $all[$source] = $events;
        }
        dolibarr_set_const($this->db, 'KSEF_LATARNIA_EVENTS', empty($all) ? '' : json_encode($all), 'chaine', 0, '', $conf->entity);
        return true;
    }

    /**
     * @brief GET JSON
     * @param string $url
     * @return array|false False on error
     */
    private function makeRequest($url)
    {
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::API_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_HTTPHEADER => array(
                'Accept: application/json',
                'User-Agent: Dolibarr-KSeF-Module/1.0'
            ),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
        ));
        ksefCurlApplyProxy($ch);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $this->error = 'Latarnia API connection error: ' . $curlError;
            dol_syslog("KsefLatarnia::makeRequest CURL ERROR: " . $this->error, LOG_ERR);
            return false;
        }

        if ($httpCode != 200) {
            $this->error = 'Latarnia API error: HTTP ' . $httpCode;
            dol_syslog("KsefLatarnia::makeRequest HTTP ERROR: " . $this->error, LOG_ERR);
            return false;
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error = 'Latarnia API error: Invalid JSON response';
            dol_syslog("KsefLatarnia::makeRequest JSON ERROR: " . $this->error, LOG_ERR);
            return false;
        }

        return $data;
    }
}
