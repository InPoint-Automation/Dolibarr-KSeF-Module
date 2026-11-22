<?php
/* Copyright (C) 2025-2026      InPoint Automation Sp z o.o.
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
 * \file    ksef/lib/ksef_log.lib.php
 * \ingroup ksef
 * \brief   Log state, errors
 */

/**
 * @brief Syslog state
 * @return array
 */
function ksefLogState()
{
    $names = array(LOG_EMERG => 'LOG_EMERG', LOG_ALERT => 'LOG_ALERT', LOG_CRIT => 'LOG_CRIT', LOG_ERR => 'LOG_ERR',
        LOG_WARNING => 'LOG_WARNING', LOG_NOTICE => 'LOG_NOTICE', LOG_INFO => 'LOG_INFO', LOG_DEBUG => 'LOG_DEBUG');
    $level = getDolGlobalInt('SYSLOG_LEVEL', LOG_ERR);
    $handlers = json_decode(getDolGlobalString('SYSLOG_HANDLERS', '["mod_syslog_file"]'), true);
    $fileHandler = is_array($handlers) && in_array('mod_syslog_file', $handlers, true);
    $state = array(
        'module_on' => isModEnabled('syslog'),
        'level' => $level,
        'level_name' => isset($names[$level]) ? $names[$level] : ($level > LOG_DEBUG ? 'LOG_DEBUG' : (string) $level),
        'file_handler' => $fileHandler,
        'path' => '',
        'exists' => false,
        'size' => 0,
        'setup_url' => DOL_URL_ROOT . '/admin/syslog.php',
    );
    if ($fileHandler) {
        // as core mod_syslog_file::getFilename()
        $state['path'] = getDolGlobalString('SYSLOG_FILE') !== '' ? str_replace('DOL_DATA_ROOT', DOL_DATA_ROOT, getDolGlobalString('SYSLOG_FILE')) : DOL_DATA_ROOT . '/dolibarr.log';
        $state['exists'] = is_file($state['path']) && is_readable($state['path']);
        $state['size'] = $state['exists'] ? (int) @filesize($state['path']) : 0;
    }
    return $state;
}

/**
 * @brief Secrets to scrub
 * @param DoliDB $db
 * @return array Longest first
 */
function ksefLogSecrets($db)
{
    global $dolibarr_main_db_pass;
    $raw = array();
    $res = $db->query("SELECT name, value FROM " . MAIN_DB_PREFIX . "const WHERE name LIKE 'KSEF\\_%'");
    while ($res && ($o = $db->fetch_object($res))) {
        if (!ksefLogIsSecretName((string) $o->name) || (string) $o->value === '') {
            continue;
        }
        $val = (string) $o->value;
        if (strpos($val, 'dolcrypt:') === 0) {
            require_once DOL_DOCUMENT_ROOT . '/core/lib/security.lib.php';
            $raw[] = $val;
            $val = (string) dolDecrypt($val);
        }
        $raw[] = $val;
        $dec = dol_decode($val);
        if (is_string($dec) && $dec !== '') {
            $raw[] = $dec;
        }
    }
    if (!empty($dolibarr_main_db_pass)) {
        $raw[] = (string) $dolibarr_main_db_pass;
    }
    $out = array();
    foreach ($raw as $s) {
        $s = trim($s);
        foreach (array($s, base64_encode($s), rtrim(strtr(base64_encode($s), '+/', '-_'), '='), rawurlencode($s), urlencode($s), substr((string) json_encode($s), 1, -1)) as $form) {
            if (strlen($form) >= 8) {
                $out[$form] = true;
            }
        }
    }
    $out = array_keys($out);
    usort($out, function ($a, $b) {
        return strlen($b) - strlen($a);
    });
    return $out;
}

/**
 * @brief Check secret name
 * @param string $name Constant name
 * @return bool
 */
function ksefLogIsSecretName($name)
{
    return preg_match('/(TOKEN|PASSWORD|PRIVATE_KEY|GUS_KEY|FETCH_KEY|FETCH_IV)/', $name) === 1 && strpos($name, 'UPDATED_AT') === false;
}

/**
 * @brief Encrypt stored secrets
 * @param DoliDB $db
 * @return int Rows encrypted
 */
function ksefEncryptStoredSecrets($db)
{
    require_once DOL_DOCUMENT_ROOT . '/core/lib/security.lib.php';
    $n = 0;
    $res = $db->query("SELECT rowid, name, value FROM " . MAIN_DB_PREFIX . "const WHERE name LIKE 'KSEF\\_%' AND value NOT LIKE 'dolcrypt:%' AND value <> ''");
    while ($res && ($o = $db->fetch_object($res))) {
        // not credentials
        if (!ksefLogIsSecretName((string) $o->name) || preg_match('/FETCH_(KEY|IV)/', (string) $o->name)) {
            continue;
        }
        $enc = dolEncrypt((string) $o->value);
        if (strpos($enc, 'dolcrypt:') === 0
            && $db->query("UPDATE " . MAIN_DB_PREFIX . "const SET value = '" . $db->escape($enc) . "' WHERE rowid = " . (int) $o->rowid)) {
            $n++;
        }
    }
    return $n;
}

/**
 * @brief Scrub log text
 * @param string $text
 * @param array $secrets
 * @return string
 */
function ksefLogScrub($text, $secrets)
{
    if ($secrets) {
        $text = str_replace($secrets, '[secret scrubbed]', $text);
    }
    $text = preg_replace(array(
        '/eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}/',
        '/(Bearer\s+)(?!\[)[A-Za-z0-9._~+\/=-]{12,}/i',
        '/\b(DOLAPIKEY|api_?key|apikey|token|password|passwd|pass|pwd|secret)(["\']?\s*[=:]\s*["\']?)(?!\[)[^\s"\'&,;)]{4,}/i',
    ), array('[jwt scrubbed]', '$1[scrubbed]', '$1$2[scrubbed]'), $text);
    $lines = explode("\n", $text);
    foreach ($lines as $i => $line) {
        if (preg_match('/KSEF_[A-Z0-9_]*(TOKEN|PASSWORD|PRIVATE_KEY|GUS_KEY|FETCH_KEY|FETCH_IV)/', $line) && strpos($line, 'UPDATED_AT') === false) {
            $lines[$i] = preg_replace('/([\'"])(?!KSEF_)[^\'"]{8,}\1/', '$1[scrubbed]$1', $line);
        }
    }
    return implode("\n", $lines);
}

/**
 * @brief Recent error lines
 * @param DoliDB $db
 * @param string $path Log file
 * @param int $max Lines kept
 * @return string|false False if unreadable
 */
function ksefLogRecentErrors($db, $path, $max = 200)
{
    $fh = @fopen($path, 'rb');
    if (!$fh) {
        return false;
    }
    $size = (int) @filesize($path);
    $chunk = 8 * 1024 * 1024;
    if ($size > $chunk) {
        fseek($fh, -$chunk, SEEK_END);
        fgets($fh);
    }
    $keep = array();
    while (($line = fgets($fh)) !== false) {
        $line = rtrim($line, "\r\n");
        $isSql = preg_match('/SQL Error|DB_ERROR|Error SQL|sql error/i', $line) === 1;
        $isKsefProblem = stripos($line, 'ksef') !== false && preg_match('/\s(ERR|WARNING|CRIT|ALERT|EMERG)\s/', $line) === 1;
        if (!$isSql && !$isKsefProblem) {
            continue;
        }
        $keep[] = strlen($line) > 4000 ? substr($line, 0, 4000) . ' [...]' : $line;
        if (count($keep) > $max) {
            array_shift($keep);
        }
    }
    fclose($fh);
    return ksefLogScrub(implode("\n", $keep), ksefLogSecrets($db));
}

/**
 * @brief Log size text
 * @param int $size Bytes
 * @return string
 */
function ksefLogSizeText($size)
{
    global $langs;
    $size = (int) $size;
    if ($size < 1048576) {
        return dol_print_size($size, 1, 1);
    }
    return price(round($size / 1048576, 1), 0, $langs, 0, -1, 1) . ' MB';
}
