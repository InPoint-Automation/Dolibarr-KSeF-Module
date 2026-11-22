<?php
/* Copyright (C) 2004-2017 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2021      Éric Seigne          <eric.seigne@cap-rel.fr>
 * Copyright (C) 2025-2026      InPoint Automation Sp z o.o.
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
 * \file    ksef/admin/about.php
 * \ingroup ksef
 * \brief   About page
 */

global $langs;

if (!defined('CSRFCHECK_WITH_TOKEN')) {
    define('CSRFCHECK_WITH_TOKEN', '1');
}
if (isset($_GET['action']) && in_array($_GET['action'], array('dbdump', 'dbbackupdl', 'ksef_logdl'), true) && !defined('NOTOKENRENEWAL')) {
    define('NOTOKENRENEWAL', '1');
}

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
}

$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
    $i--;
    $j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)) . "/main.inc.php")) {
    $res = @include substr($tmp, 0, ($i + 1)) . "/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php")) {
    $res = @include dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
    $res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
    $res = @include "../../../main.inc.php";
}
if (!$res) {
    die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions2.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/security.lib.php';
dol_include_once('/ksef/lib/ksef.lib.php');
dol_include_once('/ksef/lib/ksef_tour.lib.php');

$langs->loadLangs(array("errors", "admin", "ksef@ksef"));

if (!$user->admin) {
    accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');

dol_include_once('/ksef/core/modules/modKSEF.class.php');
$tmpmodule = new modKSEF($db);

dol_include_once('/ksef/lib/ksef_dbcheck.lib.php');
dol_include_once('/ksef/lib/ksef_log.lib.php');
$dbSchema = ksefDbCheckSchema();
$dbTable = GETPOST('table', 'aZ09');
if ($dbTable !== '' && !isset($dbSchema[$dbTable])) {
    $dbTable = '';
}
$dbProblems = null;
$dbRepairDone = null;
$dbStats = null;
$dbResetTable = '';
$dbRestoreFile = '';
$dbBackupFile = GETPOST('file', 'alphanohtml');
if (!ksefDbBackupFileOk($dbBackupFile)) {
    $dbBackupFile = '';
}
$dbBackupActions = array('dbbackup', 'dbbackupdl', 'dbbackupdel', 'confirm_dbbackupdel', 'dbbackupupload', 'dbrestoreask', 'confirm_dbrestore');
if (in_array($action, array_merge(array('dbdump', 'dbrepair', 'confirm_dbrepair', 'dbfixdata', 'confirm_dbfixdata', 'dbrebuild', 'confirm_dbrebuild', 'dbundo', 'confirm_dbundo', 'ksef_reinit_ask', 'confirm_ksef_reinit'), $dbBackupActions), true) && !ksefDbCheckMayChangeTables($user)) {
    setEventMessages($langs->trans('KSEF_DbSuperadminOnly'), null, 'errors');
    $action = 'dbcheck';
}
if (in_array($action, array('dbbackup', 'dbbackupdel', 'dbbackupupload', 'confirm_dbrestore', 'confirm_dbrebuild'), true) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $action = '';
}
if ($action == 'ksef_logdl') {
    $logState = ksefLogState();
    $logText = ($logState['exists'] && ksefDbCheckMayChangeTables($user)) ? ksefLogRecentErrors($db, $logState['path']) : false;
    if ($logText !== false) {
        $logHead = '# KSeF ' . $tmpmodule->getVersion() . ', Dolibarr ' . DOL_VERSION . ', PHP ' . PHP_VERSION . ', ' . dol_print_date(dol_now(), 'standard') . "\n"
            . '# ' . $logState['level_name'] . ', ' . basename($logState['path']) . ', ' . ($logText === '' ? 0 : substr_count($logText, "\n") + 1) . " lines (KSeF errors / warnings, SQL errors)\n";
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="ksef_errors_' . dol_print_date(dol_now(), '%Y%m%d_%H%M%S') . '.log"');
        header('Cache-Control: private, no-store');
        echo $logHead . $logText . "\n";
        $db->close();
        exit;
    }
    setEventMessages($langs->trans('KSEF_LogNotReadable'), null, 'errors');
    $action = '';
}
if ($action == 'ksef_enable_jobs' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $jobsOn = ksefCronEnableRequired($db, $user);
    if ($jobsOn['error'] !== '') {
        setEventMessages(dol_escape_htmltag($jobsOn['error']), null, 'errors');
    } elseif ($jobsOn['enabled']) {
        setEventMessages(dol_escape_htmltag($langs->transnoentitiesnoconv('KSEF_CronEnabledDone', implode(', ', $jobsOn['enabled']))), null, 'mesgs');
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . '#ksef-cron-jobs');
    exit;
}
if ($action == 'dbbackupdl') {
    if ($dbBackupFile !== '' && ksefDbBackupSend($dbBackupFile)) {
        $db->close();
        exit;
    }
    setEventMessages($langs->trans('KSEF_DbBkNotFound'), null, 'errors');
    $action = '';
}
if ($action == 'dbbackup') {
    $dbBk = ksefDbBackupWrite($db, $user, 'manual');
    if ($dbBk['ok']) {
        setEventMessages(dol_escape_htmltag($langs->transnoentitiesnoconv('KSEF_DbBkMade', $dbBk['file'], $dbBk['rows'])), null, 'mesgs');
    } else {
        setEventMessages(dol_escape_htmltag($langs->transnoentitiesnoconv('KSEF_DbBkMakeFailed', $dbBk['error'])), null, 'errors');
    }
    $action = '';
}
if ($action == 'dbbackupdel' && $dbBackupFile === '') {
    setEventMessages($langs->trans('KSEF_DbBkNotFound'), null, 'errors');
    $action = '';
}
if ($action == 'confirm_dbbackupdel' && GETPOST('confirm', 'alpha') == 'yes') {
    if ($dbBackupFile !== '' && ksefDbBackupDelete($dbBackupFile, $user)) {
        setEventMessages($langs->trans('KSEF_DbBkDeleted'), null, 'mesgs');
    } else {
        setEventMessages($langs->trans('KSEF_DbBkNotFound'), null, 'errors');
    }
    $action = '';
}
if ($action == 'dbbackupupload') {
    $dbUp = isset($_FILES['backupfile']) && is_array($_FILES['backupfile']) ? $_FILES['backupfile'] : null;
    if (!$dbUp || $dbUp['error'] == UPLOAD_ERR_NO_FILE || (string) $dbUp['tmp_name'] === '') {
        setEventMessages($langs->trans('KSEF_DbBkUploadNone'), null, 'errors');
    } elseif ($dbUp['error'] != UPLOAD_ERR_OK || !is_uploaded_file($dbUp['tmp_name'])) {
        setEventMessages(dol_escape_htmltag($langs->transnoentitiesnoconv('KSEF_DbBkUploadBad', ksefDbBackupErr('size'))), null, 'errors');
    } else {
        $dbStored = ksefDbBackupStoreUpload($dbUp['tmp_name'], (int) $dbUp['size'], $user);
        if ($dbStored['ok']) {
            setEventMessages($langs->trans('KSEF_DbBkUploadDone', dol_print_date($dbStored['header']['created_at'], 'dayhour', 'tzuserrel')), null, 'mesgs');
        } else {
            setEventMessages(dol_escape_htmltag($langs->transnoentitiesnoconv('KSEF_DbBkUploadBad', $dbStored['error'])), null, 'errors');
        }
    }
    $action = '';
}
if ($action == 'confirm_dbrestore' && $dbBackupFile !== '') {
    if (!GETPOSTINT('ack')) {
        setEventMessages($langs->trans('KSEF_DbBkRestoreAckMissing'), null, 'errors');
        $action = 'dbrestoreask';
    } else {
        $dbRestore = ksefDbBackupRestore($db, $dbBackupFile, $user);
        if ($dbRestore['ok']) {
            $dbRestoreParts = array();
            foreach ($dbRestore['tables'] as $dbRt => $dbRn) {
                $dbRestoreParts[] = $dbRt . ' (' . (int) $dbRn . ')';
            }
            $dbNotes = array(dol_escape_htmltag($langs->transnoentitiesnoconv('KSEF_DbBkBeforeMade', $dbRestore['backup'])), $langs->trans('KSEF_DbBkSyncNote'));
            if ($dbRestore['dropped']) {
                $dbNotes[] = dol_escape_htmltag($langs->transnoentitiesnoconv('KSEF_DbBkRestoreDropped', implode(', ', $dbRestore['dropped'])));
            }
            if ($dbRestore['fk_failed']) {
                $dbNotes[] = dol_escape_htmltag($langs->transnoentitiesnoconv('KSEF_DbBkRestoreFk', implode(', ', $dbRestore['fk_failed'])));
            }
            $dbRh = ksefDbBackupReadHeader(ksefDbBackupDir() . '/' . $dbBackupFile);
            setEventMessages(dol_escape_htmltag($langs->transnoentitiesnoconv('KSEF_DbBkRestoreDone', $dbRh ? dol_print_date($dbRh['created_at'], 'dayhour', 'tzuserrel') : '', implode(', ', $dbRestoreParts))), $dbNotes, 'mesgs');
        } else {
            setEventMessages(dol_escape_htmltag($dbRestore['message'] !== '' ? $dbRestore['message'] : $langs->transnoentitiesnoconv('KSEF_DbBkRestoreFailed', $dbRestore['error'])), null, 'errors');
        }
        $action = '';
    }
}
if ($action == 'dbrestoreask' && $dbBackupFile !== '') {
    $dbRestoreFile = $dbBackupFile;
}
if ($action == 'dbdump' && $dbTable !== '') {
    if (ksefDbCheckDump($db, $dbTable, GETPOST('format', 'aZ09') == 'sql' ? 'sql' : 'csv')) {
        $db->close();
        exit;
    }
    setEventMessages($langs->trans('KSEF_DbP_TableMissing') . ': ' . ksefDbCheckTableName($dbTable), null, 'errors');
    $action = 'dbcheck';
}
$dbUndoNote = function ($file) use ($langs) {
    $h = ksefDbBackupFileOk($file) && is_file(ksefDbBackupDir() . '/' . $file) ? ksefDbBackupReadHeader(ksefDbBackupDir() . '/' . $file) : null;
    return dol_escape_htmltag($langs->transnoentitiesnoconv('KSEF_DbUndoNote', $h ? dol_print_date($h['created_at'], 'dayhour', 'tzuserrel') : $file));
};
$dbFixKey = GETPOST('key', 'aZ09');
if (($action == 'confirm_dbrepair' || ($action == 'confirm_dbfixdata' && $dbFixKey !== '')) && GETPOST('confirm', 'alpha') == 'yes') {
    $dbRepairDone = $action == 'confirm_dbrepair' ? ksefDbCheckRepair($db, $tmpmodule, $user, 1, '') : ksefDbCheckRepair($db, $tmpmodule, $user, 2, $dbFixKey === 'all' ? '' : $dbFixKey);
    $dbFirst = $dbRepairDone ? $dbRepairDone[0] : null;
    $dbBackedUp = $dbFirst && $dbFirst['action'] === $langs->trans('KSEF_DbBkAction');
    if ($dbBackedUp && $dbFirst['result'] == 'failed') {
        setEventMessages(dol_escape_htmltag($dbFirst['detail']), null, 'errors');
    } else {
        setEventMessages($langs->trans($action == 'confirm_dbrepair' ? 'KSEF_DbRepairDone' : 'KSEF_DbFixDataDone'), $dbBackedUp && $action == 'confirm_dbrepair' ? array($dbUndoNote($dbFirst['object'])) : null, 'mesgs');
    }
    $action = 'dbcheck';
}
if ($action == 'confirm_dbundo' && GETPOST('confirm', 'alpha') == 'yes') {
    $dbUndo = ksefDbCheckUndo($db, $user);
    if ($dbUndo['ok']) {
        setEventMessages($langs->trans('KSEF_DbUndoDone', $dbUndo['rows']), null, 'mesgs');
    } else {
        setEventMessages(dol_escape_htmltag($langs->transnoentitiesnoconv('KSEF_DbUndoFailed', $dbUndo['error'])), null, 'errors');
    }
    $action = 'dbcheck';
}
if ($action == 'confirm_dbrebuild' && $dbTable !== '') {
    $dbChosen = GETPOST('file', 'alphanohtml');
    $dbProblems = ksefDbCheckRun($db, $tmpmodule);
    $dbStats = ksefDbCheckTableStats($db);
    foreach (ksefDbCheckTableHealth($db, $dbProblems) as $dbHealthTable => $dbHealth) {
        $dbStats[$dbHealthTable]['health'] = $dbHealth;
    }
    if (trim(GETPOST('typed', 'alphanohtml')) !== ksefDbCheckTableName($dbTable)) {
        setEventMessages($langs->trans('KSEF_DbRebuildTypeWrong'), null, 'errors');
        $action = 'dbrebuild';
    } elseif (empty($dbStats[$dbTable]['exists']) || ($dbChosen !== '' && !ksefDbBackupFileOk($dbChosen))) {
        setEventMessages($langs->trans('KSEF_DbRebuildNotNeeded'), null, 'errors');
        $action = 'dbcheck';
    } else {
        $dbReset = ksefDbCheckResetTable($db, $dbTable, $user);
        $dbNotes = $dbReset['backup'] !== '' ? array($dbUndoNote($dbReset['backup'])) : array();
        $dbBack = 0;
        if ($dbReset['ok'] && $dbChosen !== '') {
            $dbRestore = ksefDbBackupRestore($db, $dbChosen, $user, $dbTable);
            if ($dbRestore['ok']) {
                $dbBack = (int) reset($dbRestore['tables']);
            } else {
                $dbNotes[] = dol_escape_htmltag($langs->transnoentitiesnoconv('KSEF_DbRebuildRestoreFailed', $dbRestore['message'] !== '' ? $dbRestore['message'] : $dbRestore['error']));
            }
        }
        if ($dbReset['ok']) {
            setEventMessages($langs->trans('KSEF_DbRebuildDone', $langs->transnoentitiesnoconv('KSEF_DbTableName_' . $dbTable), $dbBack), $dbNotes ? $dbNotes : null, 'mesgs');
        } else {
            setEventMessages($langs->trans('KSEF_DbResetFailed', ksefDbCheckTableName($dbTable), $dbReset['error']), null, 'errors');
        }
        $action = 'dbcheck';
    }
}
if ($action == 'dbrebuild' && $dbTable !== '') {
    $dbResetTable = $dbTable;
}
if ($action == 'confirm_ksef_reinit' && GETPOST('confirm', 'alpha') == 'yes') {
    $reinit = ksefDbCheckApplyUpdate($tmpmodule, $user);
    if ($reinit['result'] == 'ok') {
        setEventMessages($langs->trans('KSEF_ApplyUpdateDone', $reinit['detail']), $reinit['backup'] !== '' ? array(dol_escape_htmltag($langs->transnoentitiesnoconv('KSEF_DbBkBeforeMade', $reinit['backup']))) : null, 'mesgs');
    } else {
        setEventMessages($langs->trans('KSEF_ApplyUpdateFailed', $reinit['detail']), null, 'errors');
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . ($reinit['result'] == 'ok' ? '?action=dbcheck&token=' . newToken() . '#dbcheck' : ''));
    exit;
}

if (in_array($action, array('dbcheck', 'dbrepair', 'dbfixdata', 'dbrebuild'))) {
    $dbProblems = ksefDbCheckRun($db, $tmpmodule);
    $dbStats = ksefDbCheckTableStats($db);
    foreach (ksefDbCheckTableHealth($db, $dbProblems) as $dbHealthTable => $dbHealth) {
        if (isset($dbStats[$dbHealthTable])) {
            $dbStats[$dbHealthTable]['health'] = $dbHealth;
        }
    }
}

$ksefUpdate = ksefMaybeRunUpdateCheck($db);

$requirements = array();

$php_version = phpversion();
$php_min = implode('.', $tmpmodule->phpmin);
$php_meets_requirement = version_compare($php_version, $php_min, '>=');
$requirements['php'] = array(
        'label' => $langs->trans('KSEF_Req_PHPVersion'),
        'status' => $php_meets_requirement,
        'critical' => true,
        'description' => $langs->trans('KSEF_Req_PHPVersionDesc', $php_min),
        'version' => $php_version,
        'value' => $php_version
);

$dol_min = implode('.', $tmpmodule->need_dolibarr_version);
$requirements['dolibarr'] = array(
        'label' => $langs->trans('KSEF_Req_DolibarrVersion'),
        'status' => versioncompare(versiondolibarrarray(), $tmpmodule->need_dolibarr_version) >= 0,
        'critical' => true,
        'description' => $langs->trans('KSEF_Req_DolibarrVersionDesc', $dol_min),
        'version' => DOL_VERSION,
        'value' => DOL_VERSION
);

$openssl_loaded = extension_loaded('openssl');
$openssl_version = null;
if ($openssl_loaded && defined('OPENSSL_VERSION_TEXT')) {
    $openssl_version = OPENSSL_VERSION_TEXT;
}
$requirements['openssl'] = array(
        'label' => $langs->trans('KSEF_Req_OpenSSL'),
        'status' => $openssl_loaded,
        'critical' => true,
        'description' => $langs->trans('KSEF_Req_OpenSSLDesc'),
        'version' => $openssl_version
);

$curl_loaded = extension_loaded('curl');
$curl_version = null;
if ($curl_loaded && function_exists('curl_version')) {
    $curl_info = curl_version();
    $curl_version = isset($curl_info['version']) ? $curl_info['version'] : null;
}
$requirements['curl'] = array(
        'label' => $langs->trans('KSEF_Req_cURL'),
        'status' => $curl_loaded,
        'critical' => true,
        'description' => $langs->trans('KSEF_Req_cURLDesc'),
        'version' => $curl_version
);

$dom_loaded = extension_loaded('dom');
$dom_version = null;
if ($dom_loaded && defined('LIBXML_DOTTED_VERSION')) {
    $dom_version = 'libxml ' . LIBXML_DOTTED_VERSION;
}
$requirements['dom'] = array(
        'label' => $langs->trans('KSEF_Req_DOM'),
        'status' => $dom_loaded,
        'critical' => true,
        'description' => $langs->trans('KSEF_Req_DOMDesc'),
        'version' => $dom_version
);

$mbstring_loaded = extension_loaded('mbstring');
$requirements['mbstring'] = array(
        'label' => $langs->trans('KSEF_Req_Mbstring'),
        'status' => $mbstring_loaded,
        'critical' => true,
        'description' => $langs->trans('KSEF_Req_MbstringDesc'),
        'version' => $mbstring_loaded ? phpversion('mbstring') : null
);

$json_loaded = extension_loaded('json');
$requirements['json'] = array(
        'label' => $langs->trans('KSEF_Req_JSON'),
        'status' => $json_loaded,
        'critical' => true,
        'description' => $langs->trans('KSEF_Req_JSONDesc'),
        'version' => $json_loaded ? phpversion('json') : null
);

$zip_loaded = extension_loaded('zip');
$zip_has_archive = $zip_loaded && class_exists('ZipArchive');
$requirements['zip'] = array(
        'label' => $langs->trans('KSEF_Req_Zip'),
        'status' => $zip_has_archive,
        'critical' => true,
        'description' => $langs->trans('KSEF_Req_ZipDesc'),
        'version' => $zip_loaded ? phpversion('zip') : null
);

require_once __DIR__ . '/../lib/vendor/autoload.php';

$phpseclib_status = false;
$phpseclib_version = null;

try {
    if (class_exists('phpseclib3\\Crypt\\RSA')) {
        $phpseclib_status = true;

        if (class_exists('Composer\\InstalledVersions')) {
            try {
                $phpseclib_version = \Composer\InstalledVersions::getPrettyVersion('phpseclib/phpseclib');
            } catch (Exception $e) {  }
        }

        if (!$phpseclib_version) {
            $installed_json_path = __DIR__ . '/../lib/vendor/composer/installed.json';
            if (file_exists($installed_json_path)) {
                $composer_data = json_decode(file_get_contents($installed_json_path), true);
                if ($composer_data) {
                    $packages = isset($composer_data['packages']) ? $composer_data['packages'] : $composer_data;
                    foreach ($packages as $package) {
                        if (!empty($package['name']) && $package['name'] === 'phpseclib/phpseclib') {
                            if (!empty($package['version'])) {
                                $phpseclib_version = $package['version'];
                            } elseif (!empty($package['version_normalized'])) {
                                $phpseclib_version = $package['version_normalized'];
                            }
                            break;
                        }
                    }
                }
            }
        }

        if (!$phpseclib_version) {
            $phpseclib_version = '3.x (exact version unavailable)';
        }
    }
} catch (Exception $e) {
    $phpseclib_status = false;
}

$requirements['phpseclib'] = array(
        'label' => $langs->trans('KSEF_Req_phpseclib'),
        'status' => $phpseclib_status,
        'critical' => true,
        'description' => $langs->trans('KSEF_Req_phpseclibDesc'),
        'version' => $phpseclib_version
);

$tcpdf_status = false;
$tcpdf_version = null;
$tcpdf_has_barcode = false;
try {
    if (file_exists(DOL_DOCUMENT_ROOT . '/includes/tecnickcom/tcpdf/tcpdf.php')) {
        require_once DOL_DOCUMENT_ROOT . '/includes/tecnickcom/tcpdf/tcpdf.php';
        $tcpdf_status = class_exists('TCPDF');

        if ($tcpdf_status && class_exists('TCPDF_STATIC') && property_exists('TCPDF_STATIC', 'version')) {
            $tcpdf_version = TCPDF_STATIC::$version;
        } elseif ($tcpdf_status && defined('PDF_PRODUCER')) {
            if (preg_match('/TCPDF\s+([\d.]+)/', PDF_PRODUCER, $matches)) {
                $tcpdf_version = $matches[1];
            }
        }

        if (file_exists(DOL_DOCUMENT_ROOT . '/includes/tecnickcom/tcpdf/tcpdf_barcodes_2d.php')) {
            require_once DOL_DOCUMENT_ROOT . '/includes/tecnickcom/tcpdf/tcpdf_barcodes_2d.php';
            $tcpdf_has_barcode = class_exists('TCPDF2DBarcode');
        }
    }
} catch (Exception $e) {
    $tcpdf_status = false;
}

$tcpdf_description = $langs->trans('KSEF_Req_TCPDFDesc');
if ($tcpdf_status && !$tcpdf_has_barcode) {
    $tcpdf_description .= ' ' . $langs->trans('KSEF_Req_TCPDF2DMissing');
}

$requirements['tcpdf'] = array(
        'label' => $langs->trans('KSEF_Req_TCPDF'),
        'status' => $tcpdf_status && $tcpdf_has_barcode,
        'critical' => false,
        'description' => $tcpdf_description,
        'version' => $tcpdf_version
);

$barcode_module_enabled = isModEnabled('barcode');
$barcode_module_status = $barcode_module_enabled ? $langs->trans('KSEF_Enabled') : $langs->trans('KSEF_FA3_PLACE_OF_ISSUE_DISABLED');
$requirements['barcode_module'] = array(
        'label' => $langs->trans('KSEF_Req_BarcodeModule'),
        'status' => $barcode_module_enabled,
        'critical' => false,
        'description' => $langs->trans('KSEF_Req_BarcodeModuleDesc'),
        'value' => $barcode_module_status
);

$multicurrency_enabled = isModEnabled('multicurrency');
$multicurrency_status = $multicurrency_enabled ? $langs->trans('KSEF_Enabled') : $langs->trans('KSEF_FA3_PLACE_OF_ISSUE_DISABLED');
$requirements['multicurrency_module'] = array(
        'label' => $langs->trans('KSEF_Req_MulticurrencyModule'),
        'status' => $multicurrency_enabled,
        'critical' => false,
        'description' => $langs->trans('KSEF_Req_MulticurrencyModuleDesc'),
        'value' => $multicurrency_status
);

$server_tz = date_default_timezone_get();
$requirements['server_timezone'] = array(
        'label' => $langs->trans('KSEF_Req_ServerTimezone'),
        'status' => ksefServerTimezoneIsWarsaw(),
        'critical' => false,
        'description' => $langs->trans('KSEF_Req_ServerTimezoneDesc'),
        'fail_label' => $langs->trans('KSEF_Req_ServerTimezoneOther'),
        'value' => dol_escape_htmltag($server_tz)
);

$_nip = ksefSellerNip();
$_aboutEnv = ksefGetEnvironment();
$nip_configured = ($_nip !== '');
$nip_valid = $nip_configured && ksefValidateNIP($_nip);
$requirements['nip'] = array(
        'label' => $langs->trans('KSEF_Req_CompanyNIP'),
        'status' => $nip_valid,
        'critical' => (!$nip_configured || $_aboutEnv === 'PRODUCTION'),
        'description' => $langs->trans('KSEF_Req_CompanyNIPDesc'),
        'value' => $nip_configured ? dol_escape_htmltag($_nip) . ($nip_valid ? '' : ' ' . $langs->trans('KSEF_NipChecksumWrong')) : null,
        'tab' => 'general'
);

$_authEnv = $_aboutEnv;
$_auth = ksefAuthReadiness($_authEnv);
$has_token = $_auth['token'];
$has_auth_cert = $_auth['cert']['configured'];
$auth_cert_valid_to = $has_auth_cert && !empty($_auth['cert']['valid_to']) ? (int) $_auth['cert']['valid_to'] : null;
$auth_cert_expired = $has_auth_cert && !$_auth['cert']['valid'];

$auth_value = null;
if ($has_token && $has_auth_cert) {
    $auth_value = $langs->trans('KSEF_AuthValue_TokenAndCert');
    if ($auth_cert_valid_to) {
        $auth_value .= ' ' . $langs->transnoentities($auth_cert_expired ? 'KSEF_AuthValue_Expired' : 'KSEF_AuthValue_CertExpires', dol_print_date($auth_cert_valid_to, 'day'));
    }
} elseif ($has_token) {
    $auth_value = $langs->trans('KSEF_AuthValue_Token');
} elseif ($has_auth_cert) {
    $auth_value = $langs->trans('KSEF_AuthValue_Certificate');
    if ($auth_cert_expired) {
        $auth_value .= ' ' . $langs->transnoentities('KSEF_AuthValue_Expired', dol_print_date($auth_cert_valid_to, 'day'));
    } elseif ($auth_cert_valid_to) {
        $auth_value .= ' ' . $langs->transnoentities('KSEF_AuthValue_Expires', dol_print_date($auth_cert_valid_to, 'day'));
    }
}
if (!$_auth['ready'] && ($has_token || $has_auth_cert)) {
    $auth_value .= ' - ' . $langs->trans('KSEF_AuthValue_MethodUnusable', $langs->transnoentitiesnoconv($_auth['method'] === 'certificate' ? 'KSEF_AUTH_METHOD_CERTIFICATE' : 'KSEF_AUTH_METHOD_TOKEN'));
}

$requirements['authentication'] = array(
        'label' => $langs->trans('KSEF_Req_KSeFAuth'),
        'status' => $_auth['ready'],
        'critical' => true,
        'description' => $langs->trans('KSEF_Req_KSeFAuthDesc'),
        'value' => $auth_value,
        'tab' => 'auth'
);

$_offline = ksefIsOfflineCertificateConfigured($_authEnv);
$has_offline_cert = $_offline['configured'];
$offline_cert_valid_to = $has_offline_cert && !empty($_offline['valid_to']) ? (int) $_offline['valid_to'] : null;
$offline_cert_expired = $has_offline_cert && !$_offline['valid'];

$offline_cert_status = $has_offline_cert && !$offline_cert_expired;

$offline_value = null;
if ($has_offline_cert) {
    if ($offline_cert_expired) {
        $offline_value = $langs->transnoentities('KSEF_CertValue_Expired', dol_print_date($offline_cert_valid_to, 'day'));
    } elseif ($offline_cert_valid_to) {
        $offline_value = $langs->transnoentities('KSEF_CertValue_ValidUntil', dol_print_date($offline_cert_valid_to, 'day'));
    } else {
        $offline_value = $langs->trans('KSEF_CertValue_Configured');
    }
} else {
    $offline_value = $langs->trans('KSEF_CertValue_NotConfigured');
}

$requirements['offline_certificate'] = array(
        'label' => $langs->trans('KSEF_Req_OfflineCert'),
        'status' => $offline_cert_status,
        'critical' => false,
        'description' => $langs->trans('KSEF_Req_OfflineCertDesc'),
        'value' => $offline_value,
        'tab' => 'auth'
);

$environment = $_aboutEnv;
$requirements['environment'] = array(
        'label' => $langs->trans('KSEF_Req_Environment'),
        'status' => true,
        'critical' => false,
        'description' => $langs->trans('KSEF_Req_EnvironmentDesc'),
        'value' => $environment
);

$csrf_token_enabled = !empty($conf->global->MAIN_SECURITY_CSRF_WITH_TOKEN);
$requirements['csrf'] = array(
        'label' => $langs->trans('KSEF_Req_CSRF'),
        'status' => $csrf_token_enabled,
        'critical' => false,
        'description' => $langs->trans('KSEF_Req_CSRFDesc'),
        'value' => $csrf_token_enabled ? $langs->trans('KSEF_Enabled') : $langs->trans('KSEF_FA3_PLACE_OF_ISSUE_DISABLED')
);

$cronJobs = ksefGetCronJobs($db);
$cronProblem = ksefCronEngineProblem($db, $cronJobs);
$requirements['cron'] = array(
        'label' => $langs->trans('KSEF_Req_Cron'),
        'status' => ($cronProblem === ''),
        'critical' => false,
        'description' => $langs->trans('KSEF_Req_CronDesc'),
        'value' => $cronProblem === '' ? $langs->trans('KSEF_Enabled') : $langs->trans('KSEF_WARNING_CRON_' . strtoupper($cronProblem))
);

$tmpDir = sys_get_temp_dir();
$requirements['tempdir'] = array(
        'label' => $langs->trans('KSEF_Req_TempDir'),
        'status' => is_dir($tmpDir) && is_writable($tmpDir),
        'critical' => false,
        'description' => $langs->trans('KSEF_Req_TempDirDesc'),
        'value' => dol_escape_htmltag($tmpDir)
);

$memLimit = ini_get('memory_limit');
$memBytes = ((string) $memLimit === '-1') ? -1 : ksefIniBytes($memLimit);
$maxExec = (int) ini_get('max_execution_time');
$requirements['php_limits'] = array(
        'label' => $langs->trans('KSEF_Req_PhpLimits'),
        'status' => ($memBytes === -1 || $memBytes >= 128 * 1024 * 1024) && ($maxExec === 0 || $maxExec >= 60),
        'critical' => false,
        'description' => $langs->trans('KSEF_Req_PhpLimitsDesc'),
        'value' => 'memory_limit=' . dol_escape_htmltag((string) $memLimit) . ', max_execution_time=' . $maxExec
);

$dbHealth = array('probe' => ksefDbHealthProbe($db), 'lost' => ksefDbHealthLostRows($db), 'undo' => ksefDbCheckUndoState($db));
if ($dbStats === null && !$dbHealth['probe']['ok'] && $dbHealth['probe']['step'] !== 'open_transaction') {
    $dbStats = ksefDbCheckTableStats($db);
}

$critical_issues = 0;
$warnings = 0;
$firstFailingTab = '';
$firstFailingKey = '';
foreach ($requirements as $reqKey => $req) {
    if (!$req['status']) {
        if ($req['critical'] && $firstFailingKey === '') {
            $firstFailingKey = $reqKey;
            $firstFailingTab = !empty($req['tab']) ? $req['tab'] : '';
        }
        if ($req['critical']) {
            $critical_issues++;
        } else {
            $warnings++;
        }
    }
}

$form = new Form($db);

$page_name = "KSEF_About";
llxHeader('', $langs->trans($page_name), ksefHelpUrl('quickstart'));

$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1' . '">' . $langs->trans("KSEF_BackToModuleList") . '</a>';

print load_fiche_titre($langs->trans($page_name), ksefHelpTitleButton($user, 'about', array('quickstart' => 'KSEF_QuickStartGuide', 'troubleshooting' => 'KSEF_Troubleshooting'), array('setup_incoming')) . $linkback, 'title_setup');

$head = ksefAdminPrepareHead();
print dol_get_fiche_head($head, 'about', $langs->trans("KSEF_Module"), -1, 'ksef@ksef');

echo ksefShowAdminBanners();
if ($action == 'ksef_reinit_ask') {
    print $form->formconfirm($_SERVER['PHP_SELF'], $langs->trans('KSEF_ApplyUpdate'), $langs->trans('KSEF_ApplyUpdateConfirm'), 'confirm_ksef_reinit', '', 0, 1, 'auto', 550);
}

if ($action == 'dbbackupdel') {
    $dbDelHead = is_file(ksefDbBackupDir() . '/' . $dbBackupFile) ? ksefDbBackupReadHeader(ksefDbBackupDir() . '/' . $dbBackupFile) : null;
    print $form->formconfirm($_SERVER['PHP_SELF'] . '?file=' . urlencode($dbBackupFile), $langs->trans('KSEF_DbBkDelete'),
        ksefTransHtml('KSEF_DbBkDeleteConfirm', array($dbDelHead ? dol_print_date($dbDelHead['created_at'], 'dayhour', 'tzuserrel') : ksefEscapeText($dbBackupFile))), 'confirm_dbbackupdel', '', 0, 1);
}

if ($action == 'dbrepair') {
    $dbN1 = count(array_filter((array) $dbProblems, function ($p) {
        return ksefDbCheckTier($p) == 1;
    }));
    print $form->formconfirm($_SERVER['PHP_SELF'], $langs->trans('KSEF_DbRepair'), $langs->trans('KSEF_DbRepairConfirm', $dbN1), 'confirm_dbrepair', '', 0, 1);
} elseif ($action == 'dbfixdata' && $dbFixKey !== '') {
    $dbPlan = ksefDbCheckRecordPlan($db, (array) $dbProblems, $dbFixKey);
    if ($dbPlan['n']) {
        print $form->formconfirm($_SERVER['PHP_SELF'] . '?key=' . urlencode($dbFixKey), $langs->trans('KSEF_DbTier2Title'),
            $langs->trans('KSEF_DbPlanIntro') . $dbPlan['html'] . $langs->trans('KSEF_DbPlanEnd'), 'confirm_dbfixdata', '', 0, 1, 'auto', 800);
    } else {
        setEventMessages($langs->trans('KSEF_DbRepairNothing'), null, 'warnings');
    }
} elseif ($action == 'dbundo') {
    if ($dbHealth['undo']['state'] == 'ok') {
        print $form->formconfirm($_SERVER['PHP_SELF'], $langs->trans('KSEF_DbUndoDo'), $langs->trans('KSEF_DbUndoConfirm', dol_print_date($dbHealth['undo']['created_at'], 'dayhour', 'tzuserrel'), $dbHealth['undo']['n']),
            'confirm_dbundo', '', 0, 1);
    } else {
        setEventMessages($langs->trans('KSEF_DbUndoGone'), null, 'warnings');
    }
}

?>

    <table class="noborder centpercent">
        <tr class="liste_titre">
            <th>
                <span class="fa fa-info-circle paddingright"></span>
                <?php echo $langs->trans("KSEF_ModuleInformation"); ?>
            </th>
        </tr>
        <tr class="oddeven">
            <td>
                <strong><?php echo $tmpmodule->getName(); ?></strong>
                <br>
                <span class="opacitymedium"><?php echo $tmpmodule->getDesc(); ?></span>
                <br><br>
                <strong><?php echo $langs->trans("KSEF_Version"); ?>:</strong> <?php echo $tmpmodule->getVersion(); ?>
                <br>
                <strong><?php echo $langs->trans("KSEF_Publisher"); ?>:</strong> <?php echo $tmpmodule->getPublisher(); ?>
            </td>
        </tr>
    </table>

    <br>

<?php if ($critical_issues > 0) {
    $firstLabel = dol_string_nohtmltag($requirements[$firstFailingKey]['label']);
    $summary = $critical_issues > 1 ? $langs->trans('KSEF_RequirementsSummaryMany', $critical_issues, $firstLabel) : $langs->trans('KSEF_RequirementsSummaryOne', $firstLabel); ?>
    <div class="error ksef-req-summary">
        <strong><?php echo $langs->trans("KSEF_ActionRequired"); ?>:</strong>
        <a href="#ksef-req-<?php echo dol_escape_htmltag($firstFailingKey); ?>"><?php echo $summary; ?></a>
    </div>
<?php } ?>

    <table class="noborder centpercent">
        <tr class="liste_titre">
            <th colspan="2">
                <span class="fa fa-check-circle paddingright"></span>
                <?php echo $langs->trans("KSEF_SystemRequirements"); ?>
                <?php if ($critical_issues > 0) { ?>
                    <span class="badge badge-danger" style="float: right;">
                    <?php echo $langs->trans("KSEF_CriticalIssues") . ': ' . (int) $critical_issues; ?>
                </span>
                <?php } elseif ($warnings > 0) { ?>
                    <span class="badge badge-warning" style="float: right;">
                    <?php echo $langs->trans("KSEF_Warnings") . ': ' . (int) $warnings; ?>
                </span>
                <?php } else { ?>
                    <span class="badge badge-success" style="float: right;">
                    <?php echo $langs->trans("KSEF_AllRequirementsMet"); ?>
                </span>
                <?php } ?>
            </th>
        </tr>

        <?php foreach ($requirements as $key => $req) { ?>
            <tr class="oddeven" id="ksef-req-<?php echo dol_escape_htmltag($key); ?>">
                <td style="width: 50%;">
                    <strong><?php echo $req['label']; ?></strong>
                    <?php if ($req['critical']) { ?>
                        <span class="badge badge-danger"
                              style="margin-left: 5px;"><?php echo $langs->trans("KSEF_Required"); ?></span>
                    <?php } ?>
                    <br>
                    <span class="opacitymedium"><?php echo $req['description']; ?></span>
                </td>
                <td style="text-align: right;">
                    <?php if ($req['status']) { ?>
                        <span class="badge badge-status4" style="font-size: 1.1em;">
                        <i class="fa fa-check-circle"></i> <?php echo $langs->trans("KSEF_OK"); ?>
                    </span>
                        <?php if (!empty($req['version'])) { ?>
                            <br><span class="opacitymedium"><?php echo $req['version']; ?></span>
                        <?php } ?>
                        <?php if (!empty($req['value']) && empty($req['version'])) { ?>
                            <br><span class="opacitymedium"><?php echo $req['value']; ?></span>
                        <?php } ?>
                    <?php } else { ?>
                        <?php if ($req['critical']) { ?>
                            <span class="badge badge-danger" style="font-size: 1.1em;">
                            <i class="fa fa-times-circle"></i> <?php echo $langs->trans("KSEF_Missing"); ?>
                        </span>
                        <?php } else { ?>
                            <span class="badge badge-warning" style="font-size: 1.1em;">
                            <i class="fa fa-exclamation-triangle"></i> <?php echo !empty($req['fail_label']) ? $req['fail_label'] : $langs->trans("KSEF_NotConfigured"); ?>
                        </span>
                        <?php } ?>
                        <?php if (!empty($req['value'])) { ?>
                            <br><span class="opacitymedium"><?php echo $req['value']; ?></span>
                        <?php } ?>
                    <?php } ?>
                </td>
            </tr>
        <?php } ?>
    </table>

<?php if ($critical_issues > 0) { ?>
    <br>
    <div class="error">
        <strong><?php echo $langs->trans("KSEF_ActionRequired"); ?>:</strong>
        <?php echo $langs->trans("KSEF_CriticalRequirementsNotMet"); ?>
        <a href="#ksef-req-<?php echo dol_escape_htmltag($firstFailingKey); ?>"><?php echo $langs->trans("KSEF_RequirementsSeeMissing", $langs->transnoentitiesnoconv("KSEF_Missing")); ?></a>
        <?php if ($firstFailingTab !== '') { ?>
            <a href="<?php echo ksefConfigTabUrl($firstFailingTab); ?>"><?php echo $langs->trans("KSEF_GoToSetup"); ?></a>
        <?php } ?>
    </div>
<?php } ?>

<?php if ($warnings > 0 && $critical_issues == 0) { ?>
    <br>
    <div class="warning">
        <strong><?php echo $langs->trans("KSEF_Note"); ?>:</strong>
        <?php echo $langs->trans("KSEF_SomeOptionalFeaturesUnavailable"); ?>
        <?php if (!$tcpdf_status || !$tcpdf_has_barcode) { ?>
            <br>• <?php echo $langs->trans("KSEF_TCPDFRequiredForQR"); ?>
        <?php } ?>
        <?php if (!$multicurrency_enabled) { ?>
            <br>• <?php echo $langs->trans("KSEF_EnableMulticurrencyForForeignInvoices"); ?>
        <?php } ?>
        <?php if (!$csrf_token_enabled) { ?>
            <br>• <?php echo $langs->trans("KSEF_EnableCSRFProtection"); ?>
        <?php } ?>
        <?php if (!$offline_cert_status) { ?>
            <br>• <?php echo $langs->trans("KSEF_ConfigureOfflineCertificate"); ?>
        <?php } ?>
    </div>
<?php } ?>

    <br>

<?php

dol_include_once('/ksef/class/ksef_sync_state.class.php');
$salesSync = new KsefSyncState($db);
$salesSync->load('outgoing');
if (empty($salesSync->last_sync)) {
    print '<div class="info ksef-sales-sync-hint">' . $langs->trans('KSEF_AboutSalesSyncHint', '<a href="' . dol_buildpath('/ksef/status.php', 1) . '">' . $langs->trans('KSEF_OutgoingInvoices') . '</a>', $langs->transnoentitiesnoconv('KSEF_SyncNow'))
        . ' ' . $langs->trans('KSEF_LastSync') . ': ' . $langs->trans('Never') . '</div><br>';
}

print '<table class="noborder centpercent ksef-cron-jobs" id="ksef-cron-jobs">';
print '<tr class="liste_titre"><th colspan="2"><span class="fa fa-clock paddingright"></span>' . $langs->trans('KSEF_CronJobsTitle') . '</th></tr>';
if (!$cronJobs) {
    print '<tr class="oddeven"><td colspan="2"><span class="opacitymedium">' . $langs->trans('KSEF_CronJobMissing') . '</span></td></tr>';
}
foreach ($cronJobs as $cronMethod => $cronJob) {
    print '<tr class="oddeven" data-ksef-job="' . dol_escape_htmltag($cronMethod) . '"><td>' . $langs->trans('KSEF_CronJob_' . $cronMethod) . '</td><td class="right">' . ksefCronJobStatusHtml($cronJob) . '</td></tr>';
}
$cronOff = isModEnabled('cron') ? ksefCronRequiredOff($cronJobs) : array();
if ($cronOff) {
    $cronOffNames = array();
    foreach (array_keys($cronOff) as $cronMethod) {
        $cronOffNames[] = $langs->transnoentitiesnoconv('KSEF_CronJob_' . $cronMethod);
    }
    print '<tr class="oddeven ksef-cron-required-off"><td><span class="fa fa-exclamation-triangle paddingright ' . ksefToneTextClass('offline') . '"></span>' . dol_escape_htmltag($langs->transnoentitiesnoconv('KSEF_CronRequiredOff', implode(', ', $cronOffNames))) . '</td>';
    print '<td class="right"><form method="POST" action="' . $_SERVER['PHP_SELF'] . '"><input type="hidden" name="token" value="' . newToken() . '"><input type="hidden" name="action" value="ksef_enable_jobs">';
    print '<input type="submit" class="button smallpaddingimp ksef-enable-jobs" value="' . dol_escape_htmltag($langs->trans('KSEF_CronEnableRequired')) . '"></form></td></tr>';
}
print '<tr class="oddeven"><td colspan="2"><a href="' . DOL_URL_ROOT . '/cron/list.php?search_module_name=ksef">' . $langs->trans('KSEF_HowTo_JobsListLink') . '</a></td></tr>';
print '</table><br>';

$logState = ksefLogState();
$onOff = function ($on) use ($langs) {
    return '<span class="badge ' . ($on ? 'badge-status4' : 'badge-warning') . '">' . $langs->trans($on ? 'KSEF_Enabled' : 'Disabled') . '</span>';
};
print '<table class="noborder centpercent ksef-log-state">';
print '<tr class="liste_titre"><th colspan="2"><span class="fa fa-file-alt paddingright"></span>' . $langs->trans('KSEF_LogTitle') . '</th></tr>';
print '<tr class="oddeven"><td colspan="2"><span class="opacitymedium">' . $langs->trans('KSEF_LogDesc') . '</span></td></tr>';
print '<tr class="oddeven" data-ksef-log="module"><td>' . $langs->trans('KSEF_LogModule') . '</td><td class="right">' . $onOff($logState['module_on']) . '</td></tr>';
if ($logState['module_on']) {
    print '<tr class="oddeven" data-ksef-log="level"><td>' . $langs->trans('KSEF_LogLevel') . '</td><td class="right">' . dol_escape_htmltag($logState['level_name'])
        . ($logState['level'] < LOG_DEBUG ? ' <span class="opacitymedium">(' . $langs->trans('KSEF_LogLevelHint') . ')</span>' : '') . '</td></tr>';
    print '<tr class="oddeven" data-ksef-log="file"><td>' . $langs->trans('KSEF_LogFileHandler') . '</td><td class="right">' . $onOff($logState['file_handler']) . '</td></tr>';
    if ($logState['file_handler']) {
        print '<tr class="oddeven" data-ksef-log="path"><td>' . $langs->trans('KSEF_LogFilePath') . '</td><td class="right"><span class="small">' . dol_escape_htmltag($logState['path']) . '</span> '
            . ($logState['exists'] ? '<span class="opacitymedium">(' . ksefLogSizeText($logState['size']) . ')</span>' : '<span class="badge badge-warning">' . $langs->trans('KSEF_LogFileMissing') . '</span>') . '</td></tr>';
    }
}
print '<tr class="oddeven"><td colspan="2"><a href="' . dol_escape_htmltag($logState['setup_url']) . '">' . $langs->trans('KSEF_LogSetupLink') . '</a>';
if ($logState['module_on'] && $logState['exists'] && ksefDbCheckMayChangeTables($user)) {
    print ' &nbsp; <a class="ksef-log-download" href="' . $_SERVER['PHP_SELF'] . '?action=ksef_logdl&token=' . newToken() . '"><span class="fa fa-download paddingright"></span>' . $langs->trans('KSEF_LogDownload') . '</a>'
        . '<br><span class="opacitymedium small">' . $langs->trans('KSEF_LogDownloadDesc') . '</span>';
}
print '</td></tr>';
print '</table><br>';

print ksefDbCheckRenderSection($dbProblems, $dbRepairDone, $dbStats, $dbResetTable, $dbRestoreFile, $dbHealth);

print '<br>';

print ksefRenderSupportBox('', $ksefUpdate);

print '<br>';

print dol_get_fiche_end();
print ksefHelpFooter($db, $user);
llxFooter();
$db->close();
?>