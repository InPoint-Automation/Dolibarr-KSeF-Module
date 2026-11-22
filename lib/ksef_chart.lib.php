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
 * \file    lib/ksef_chart.lib.php
 * \ingroup ksef
 * \brief   Chart import helpers
 */

/**
 * @brief Chart checklist state
 * @param DoliDB $db
 * @return array
 */
function ksefSettleChecklistState($db)
{
    global $langs;
    if (!isModEnabled('accounting')) {
        return array('done' => null, 'detail' => '');
    }
    if (ksefChartInUse($db) === null) {
        return array('done' => false, 'detail' => $langs->trans('KSEF_Help_Detail_SettleNoChart'));
    }
    return array('done' => true, 'detail' => '');
}

/**
 * @brief Active chart
 * @param DoliDB $db
 * @return array|null
 */
function ksefChartActive($db)
{
    $id = getDolGlobalInt('CHARTOFACCOUNTS');
    if ($id <= 0) {
        return null;
    }
    $res = $db->query("SELECT rowid, pcg_version, label FROM " . MAIN_DB_PREFIX . "accounting_system WHERE rowid = " . $id);
    if (!$res || !($o = $db->fetch_object($res))) {
        return null;
    }
    return array('id' => (int) $o->rowid, 'pcg_version' => (string) $o->pcg_version, 'label' => (string) $o->label,
        'count' => count(ksefChartAccounts($db, (string) $o->pcg_version)));
}

/**
 * @brief Active non-empty chart
 * @param DoliDB $db
 * @return array|null
 */
function ksefChartInUse($db)
{
    $chart = ksefChartActive($db);
    return ($chart !== null && $chart['count'] > 0) ? $chart : null;
}

/**
 * @brief Chart account numbers
 * @param DoliDB $db
 * @param string $pcgVersion Chart code
 * @return array number => rowid
 */
function ksefChartAccounts($db, $pcgVersion)
{
    global $conf;
    $out = array();
    $res = $db->query("SELECT rowid, account_number FROM " . MAIN_DB_PREFIX . "accounting_account WHERE entity = " . ((int) $conf->entity)
        . " AND fk_pcg_version = '" . $db->escape($pcgVersion) . "'");
    while ($res && ($o = $db->fetch_object($res))) {
        $out[(string) $o->account_number] = (int) $o->rowid;
    }
    return $out;
}

/**
 * @brief Guess pcg_type
 * @param string $number Account number
 * @return string
 */
function ksefChartTypeFor($number)
{
    $d = preg_replace('/\D/', '', (string) $number);
    if ($d === '' || !ctype_digit(substr((string) $number, 0, 1))) {
        return '';
    }
    $map = array('0' => 'IMMO', '1' => 'FINAN', '2' => 'THIRDPARTY', '3' => 'STOCK', '4' => 'EXPENSE', '5' => 'EXPENSE', '6' => 'STOCK', '8' => 'CAPIT');
    if ($d[0] === '7') {
        return (strlen($d) >= 3 && ((int) $d[2]) % 2 === 1) ? 'EXPENSE' : 'INCOME';
    }
    return isset($map[$d[0]]) ? $map[$d[0]] : '';
}

/**
 * @brief Accounts from JPK_KR_PD
 * @param string $path
 * @return array|null Null if not JPK_KR_PD
 */
function ksefChartJpkAccounts($path)
{
    $xr = new XMLReader();
    $prev = libxml_use_internal_errors(true);
    $out = null;
    $kod = '';
    if (@$xr->open($path, null, LIBXML_NONET)) {
        $more = @$xr->read();
        while ($more) {
            if ($xr->nodeType === XMLReader::ELEMENT && $xr->localName === 'KodFormularza') {
                $kod = trim((string) $xr->readString());
            }
            if ($xr->nodeType !== XMLReader::ELEMENT || !preg_match('/^ZOiS\d$/', $xr->localName)) {
                $more = @$xr->read();
                continue;
            }
            if ($kod !== 'JPK_KR_PD') {
                break;
            }
            $node = @$xr->expand();
            $f = array();
            if ($node) {
                foreach ($node->childNodes as $c) {
                    if ($c->nodeType === XML_ELEMENT_NODE) {
                        $f[$c->localName] = trim($c->textContent);
                    }
                }
            }
            $more = @$xr->next();
            if ($out === null) {
                $out = array();
            }
            if (empty($f['S_1'])) {
                continue;
            }
            $tags = array();
            foreach (array('S_12_1', 'S_12_2', 'S_12_3') as $k) {
                if (!empty($f[$k])) {
                    $tags[] = $f[$k];
                }
            }
            $out[] = array($f['S_1'], isset($f['S_2']) ? $f['S_2'] : '', isset($f['S_3']) ? $f['S_3'] : '', implode(', ', $tags));
        }
        $xr->close();
    }
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if ($kod !== 'JPK_KR_PD') {
        return null;
    }
    return $out === null ? array() : $out;
}

/**
 * @brief Import set code
 * @return string
 */
function ksefChartImportCode()
{
    return 'ksef_1';
}

/**
 * @brief Import set fields
 * @return array
 */
function ksefChartImportFields()
{
    return array('aa.account_number' => 'KSEF_ChartImpNumber*', 'aa.label' => 'KSEF_ChartImpName*', 'aa.account_parent' => 'KSEF_ChartImpParent', 'aa.pcg_type' => 'KSEF_ChartImpType');
}

/**
 * @brief Template rows
 * @return array
 */
function ksefChartTemplateRows()
{
    $rows = array(
        array('130', 'Rachunek bieżący', ''),
        array('130-01', 'Rachunek bieżący PLN', '130'),
        array('201', 'Rozrachunki z odbiorcami', ''),
        array('202', 'Rozrachunki z dostawcami', ''),
        array('221', 'Rozrachunki z tytułu VAT', ''),
        array('402', 'Usługi obce', ''),
        array('700', 'Przychody ze sprzedaży produktów', ''),
        array('750', 'Przychody finansowe', ''),
        array('751', 'Koszty finansowe', ''),
        array('760', 'Pozostałe przychody operacyjne', ''),
        array('761', 'Pozostałe koszty operacyjne', ''),
    );
    foreach ($rows as $i => $r) {
        $rows[$i][] = ksefChartTypeFor($r[0]);
    }
    return $rows;
}

/**
 * @brief Import CSV content
 * @param array $rows number, name, parent, type
 * @return string
 */
function ksefChartImportCsv($rows)
{
    global $langs;
    $langs->load('ksef@ksef');
    $q = function ($v) {
        return '"' . str_replace('"', '""', (string) $v) . '"';
    };
    $head = array();
    foreach (ksefChartImportFields() as $code => $label) {
        $plain = rtrim($label, '*');
        $head[] = $q($langs->transnoentitiesnoconv($plain) . ($plain !== $label ? '*' : '') . ' (' . $code . ')');
    }
    $out = implode(',', $head) . "\n";
    foreach ($rows as $r) {
        $out .= implode(',', array_map($q, array_slice(array_pad(array_values($r), 4, ''), 0, 4))) . "\n";
    }
    return $out;
}

/**
 * @brief JPK import rows
 * @param array $accounts ksefChartJpkAccounts()
 * @param array $have Chart accounts number => rowid
 * @return array rows, present
 */
function ksefChartJpkRows($accounts, $have)
{
    $rows = array();
    foreach ($accounts as $a) {
        $n = trim($a[0]);
        if ($n === '' || dol_strlen($n) > 32 || isset($rows[$n])) {
            continue;
        }
        $p = trim($a[2]);
        $rows[$n] = array('name' => trim($a[1]), 'parent' => $p !== $n && dol_strlen($p) <= 32 ? $p : '');
    }
    foreach ($rows as $n => $r) {
        $p = $r['parent'];
        if ($p !== '' && !isset($rows[$p]) && !isset($have[$p])) {
            $rows[$p] = array('name' => '', 'parent' => '');
        }
    }
    $all = $rows + $have;
    foreach ($rows as $n => $r) {
        if ($r['parent'] !== '' && isset($all[$r['parent']])) {
            continue;
        }
        $best = '';
        foreach ($all as $m => $x) {
            $m = (string) $m;
            if ($m !== (string) $n && strlen($m) > strlen($best) && strpos((string) $n, $m) === 0) {
                $best = $m;
            }
        }
        $rows[$n]['parent'] = $best;
    }
    $depth = array();
    $depthOf = function ($n) use (&$depthOf, &$depth, &$rows) {
        if (isset($depth[$n])) {
            return $depth[$n] < 0 ? 0 : $depth[$n];
        }
        $depth[$n] = -1;
        $p = $rows[$n]['parent'];
        if ($p !== '' && isset($rows[$p]) && isset($depth[$p]) && $depth[$p] < 0) {
            $rows[$n]['parent'] = '';
            $p = '';
        }
        $depth[$n] = ($p !== '' && isset($rows[$p])) ? $depthOf($p) + 1 : 0;
        return $depth[$n];
    };
    foreach (array_keys($rows) as $n) {
        $depthOf($n);
    }
    $out = array();
    $present = 0;
    foreach ($rows as $n => $r) {
        $n = (string) $n;
        if (isset($have[$n])) {
            $present++;
            continue;
        }
        $name = dol_trunc($r['name'] !== '' ? $r['name'] : $n, 255, 'right', 'UTF-8', 1);
        $out[] = array($n, $name, $r['parent'], ksefChartTypeFor($n), $depth[$n]);
    }
    usort($out, function ($a, $b) {
        return $a[4] !== $b[4] ? $a[4] - $b[4] : strnatcmp($a[0], $b[0]);
    });
    foreach ($out as $i => $r) {
        unset($out[$i][4]);
    }
    return array('rows' => $out, 'present' => $present);
}

/**
 * @brief Import wizard URL
 * @param string $file File in import temp dir
 * @return string
 */
function ksefChartWizardUrl($file = '')
{
    dol_include_once('/ksef/lib/ksef.lib.php');
    $new = ksefDolibarrAtLeast('24.0');
    $url = DOL_URL_ROOT . '/imports/import.php?datatoimport=' . ksefChartImportCode() . '&format=csv';
    if ($file === '') {
        return $url . '&step=' . ($new ? 2 : 3);
    }
    return $url . '&step=' . ($new ? 3 : 4) . '&filetoimport=' . urlencode($file);
}

/**
 * @brief Clear wizard mapping
 * @return void
 */
function ksefChartWizardReset()
{
    $_SESSION['dol_array_match_file_to_database'] = '';
    $_SESSION['dol_array_match_file_to_database_select'] = '';
}

/**
 * @brief Check import right
 * @param User $user
 * @return bool
 */
function ksefChartCanImport($user)
{
    return ksefChartCanLoad($user) && isModEnabled('import') && $user->hasRight('import', 'run');
}

/**
 * @brief JPK to wizard
 * @param DoliDB $db
 * @param array|null $f Uploaded file
 * @return array url, error, msg
 */
function ksefChartJpkImport($db, $f)
{
    global $conf, $langs;
    $langs->loadLangs(array('ksef@ksef', 'accountancy', 'exports', 'main'));
    $out = array('url' => '', 'error' => '', 'msg' => '');
    if (!$f || empty($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) {
        $out['error'] = $langs->trans('KSEF_ChartErrNoFile');
        return $out;
    }
    $chart = ksefChartActive($db);
    if ($chart === null) {
        $out['error'] = $langs->trans('KSEF_ChartImpNoChart', $langs->transnoentitiesnoconv('Selectchartofaccounts'));
        return $out;
    }
    $acc = ksefChartJpkAccounts($f['tmp_name']);
    if ($acc === null) {
        $out['error'] = $langs->trans('KSEF_ChartErrJpk');
        return $out;
    }
    if (!$acc) {
        $out['error'] = $langs->trans('KSEF_ChartErrEmpty');
        return $out;
    }
    $conv = ksefChartJpkRows($acc, ksefChartAccounts($db, $chart['pcg_version']));
    if (!$conv['rows']) {
        $out['error'] = $langs->trans('KSEF_ChartJpkAllExist', $conv['present'], $chart['pcg_version']);
        return $out;
    }
    $base = dol_sanitizeFileName(pathinfo((string) $f['name'], PATHINFO_FILENAME));
    $name = dol_print_date(dol_now(), '%Y%m%d%H%M%S') . '-' . ($base !== '' ? $base : 'JPK_KR_PD') . '.csv';
    dol_mkdir($conf->import->dir_temp);
    if (file_put_contents($conf->import->dir_temp . '/' . $name, ksefChartImportCsv($conv['rows'])) === false) {
        $out['error'] = $langs->trans('KSEF_ChartErrWrite');
        return $out;
    }
    ksefChartWizardReset();
    $out['url'] = ksefChartWizardUrl($name);
    $out['msg'] = $langs->trans('KSEF_ChartJpkReady', count($conv['rows']), $chart['pcg_version'], $conv['present'], $langs->transnoentitiesnoconv('RunSimulateImportFile'));
    return $out;
}

/**
 * @brief Chart load right
 * @param User $user
 * @return bool
 */
function ksefChartCanLoad($user)
{
    return isModEnabled('accounting') && !empty($user->admin) && $user->hasRight('accounting', 'chartofaccount');
}

/**
 * @brief Chart page URL
 * @return string
 */
function ksefChartPageUrl()
{
    return DOL_URL_ROOT . '/accountancy/admin/account.php';
}

/**
 * @brief Chart import block
 * @param DoliDB $db
 * @param User $user
 * @return string '' if n/a
 */
function ksefChartLoaderHtml($db, $user)
{
    global $langs;
    if (!ksefChartCanLoad($user)) {
        return '';
    }
    $langs->loadLangs(array('ksef@ksef', 'accountancy', 'admin', 'exports', 'main'));
    $chart = ksefChartActive($db);
    $label = $langs->trans('KSEF_ChartImportDataset');
    $out = '<details class="margintoponly ksef-chart-block" id="ksef-chart-load"' . (ksefChartInUse($db) === null ? ' open' : '') . '>'
        . '<summary class="ksef-chart-title"><span class="fas fa-file-import paddingright"></span>' . $langs->trans('KSEF_ChartLoadTitle') . '</summary>'
        . '<div class="ksef-chart-body" style="padding: 6px 0 6px 4px;">'
        . '<div class="opacitymedium small paddingbottom ksef-chart-lead">' . $langs->trans('KSEF_ChartLoadLead') . ' ' . $langs->trans('KSEF_ChartIntro') . '</div>';
    if ($chart === null) {
        $out .= '<div class="opacitymedium small paddingbottom ksef-chart-nochart">' . $langs->trans('KSEF_ChartNoChart', $langs->transnoentitiesnoconv('Selectchartofaccounts'),
            $langs->transnoentitiesnoconv('Setup') . ' > ' . $langs->transnoentitiesnoconv('Dictionary') . ' > ' . $langs->transnoentitiesnoconv('DictionaryAccountancysystem')) . '</div>';
    }
    if (!getDolGlobalString('ACCOUNTING_MANAGE_ZERO')) {
        $zeroLink = '<a href="' . DOL_URL_ROOT . '/accountancy/admin/index.php">' . $langs->trans('KSEF_ChartZeroLink') . '</a>';
        $out .= '<div class="opacitymedium small paddingbottom ksef-chart-zero">' . $langs->trans('KSEF_ChartZeroOff', $zeroLink, $langs->transnoentitiesnoconv('ACCOUNTING_MANAGE_ZERO')) . '</div>';
    }
    $tpl = '<a href="' . ksefChartPageUrl() . '?action=ksef_chart_template&token=' . newToken() . '" class="ksef-chart-template">'
        . '<span class="fas fa-download paddingright"></span>' . $langs->trans('KSEF_ChartTemplateLink') . '</a>';
    if (!ksefChartCanImport($user)) {
        $need = !isModEnabled('import') ? $langs->trans('KSEF_RequiresModule', $langs->transnoentitiesnoconv('Module250Name'))
            : $langs->trans('KSEF_ChartImpRights', $langs->transnoentitiesnoconv('Permission1251'));
        return $out . '<div class="ksef-chart-import">' . $langs->trans('KSEF_ChartImportLink') . ': <span class="opacitymedium">' . $need . '</span> &nbsp; ' . $tpl . '</div></div></details>';
    }
    $menu = $langs->transnoentitiesnoconv('Tools') . ' > ' . $langs->transnoentitiesnoconv(isModEnabled('export') ? 'ImportExportArea' : 'FormatedImport') . ' > ' . $langs->transnoentitiesnoconv('NewImport');
    $out .= '<div class="ksef-chart-import"><a href="' . ksefChartPageUrl() . '?action=ksef_chart_import&token=' . newToken() . '" class="ksef-chart-import-link">'
        . '<span class="fas fa-file-import paddingright"></span>' . $langs->trans('KSEF_ChartImportLink') . '</a> &nbsp; ' . $tpl
        . '<div class="opacitymedium small ksef-chart-import-hint">' . $langs->trans('KSEF_ChartImportHint', $menu, $label, $langs->transnoentitiesnoconv('RunSimulateImportFile')) . '</div></div>';
    $out .= '<div class="margintoponly ksef-chart-jpk"><strong>' . $langs->trans('KSEF_ChartJpkLabel') . '</strong> '
        . '<input type="file" name="ksef_chart_file" form="ksef-chart-form" accept=".xml" class="maxwidth300 widthcentpercentminusx"> '
        . '<button type="submit" form="ksef-chart-form" name="action" value="ksef_chart_jpk" class="button smallpaddingimp ksef-chart-jpk-btn">' . $langs->trans('KSEF_ChartJpkButton') . '</button>'
        . '<div class="opacitymedium small ksef-chart-jpk-hint">' . $langs->trans('KSEF_ChartJpkHint', $label) . '</div></div>';
    $out .= '</div></details>';
    $out .= '<script>(function () { var open = function () { if (window.location.hash === "#ksef-chart-load") { document.getElementById("ksef-chart-load").open = true; } };'
        . ' open(); window.addEventListener("hashchange", open); })();</script>';
    return $out;
}

/**
 * @brief Chart JPK form
 * @param User $user
 * @return string
 */
function ksefChartFormsHtml($user)
{
    if (!ksefChartCanImport($user)) {
        return '';
    }
    return '<form id="ksef-chart-form" method="POST" action="' . ksefChartPageUrl() . '" enctype="multipart/form-data"><input type="hidden" name="token" value="' . newToken() . '"></form>';
}
