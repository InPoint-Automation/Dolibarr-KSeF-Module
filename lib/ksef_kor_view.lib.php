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
 * \file    lib/ksef_kor_view.lib.php
 * \ingroup ksef
 * \brief   Correction before/after view
 */

dol_include_once('/ksef/lib/ksef.lib.php');

/**
 * @brief Row side
 * @param float $qty
 * @param float|null $price Unit net
 * @param float $net
 * @param string $rate VAT rate label
 * @param string $unit
 * @return array
 */
function ksefKorViewSide($qty, $price, $net, $rate, $unit = '')
{
    return array('qty' => (float) $qty, 'price' => $price === null ? null : (float) $price, 'net' => (float) $net, 'rate' => (string) $rate, 'unit' => (string) $unit);
}

/**
 * @brief Sides differ
 * @param array $a
 * @param array $b
 * @return bool
 */
function ksefKorViewDiffers($a, $b)
{
    return abs($a['qty'] - $b['qty']) > 0.0000001 || abs($a['net'] - $b['net']) > 0.0049 || $a['rate'] !== $b['rate']
        || ($a['price'] !== null && $b['price'] !== null && abs($a['price'] - $b['price']) > 0.0000001);
}

/**
 * @brief Rate label
 * @param mixed $rate
 * @return string
 */
function ksefKorViewRate($rate)
{
    $rate = trim((string) $rate);
    return is_numeric($rate) ? vatrate($rate, true) : $rate;
}

/**
 * @brief XML rows
 * @param DoliDB $db
 * @param string $xml
 * @return array|string Rows, or reason key
 */
function ksefKorViewXmlRows($db, $xml)
{
    dol_include_once('/ksef/class/ksef_incoming.class.php');
    $parser = new FA3Parser($db);
    $parsed = $parser->parse($xml);
    if (!$parsed) {
        return 'KSEF_KorViewWhyNoXml';
    }
    $after = array_values((array) ($parsed['lines'] ?? array()));
    $before = array_values((array) ($parsed['lines_before'] ?? array()));
    if (empty($before)) {
        return 'KSEF_KorViewWhyKsefDiff';
    }
    $side = function ($l) {
        $net = $l['net_amount'] !== null ? (float) $l['net_amount'] : (float) $l['quantity'] * (float) $l['unit_price_net'];
        return ksefKorViewSide($l['quantity'], $l['unit_price_net'], $net, ksefKorViewRate($l['vat_rate']), (string) ($l['unit'] ?? ''));
    };
    $pairs = KsefIncoming::pairCorrectionRows($after, $before);
    $rows = array();
    $used = array();
    foreach ($after as $i => $a) {
        $b = isset($pairs[$i]) ? $before[$pairs[$i]] : null;
        if ($b !== null) {
            $used[$pairs[$i]] = true;
        }
        $rows[] = array('num' => (int) ($a['line_num'] ?? 0), 'desc' => (string) ($a['description'] !== '' ? $a['description'] : ($b['description'] ?? '')),
            'before' => $b !== null ? $side($b) : null, 'after' => $side($a));
    }
    foreach ($before as $j => $b) {
        if (!isset($used[$j])) {
            $rows[] = array('num' => (int) ($b['line_num'] ?? 0), 'desc' => (string) $b['description'], 'before' => $side($b), 'after' => null);
        }
    }
    usort($rows, function ($x, $y) {
        return $x['num'] <=> $y['num'];
    });
    return $rows;
}

/**
 * @brief Before/after data
 * @param DoliDB $db
 * @param object $record KSeF record (KsefIncoming / KsefSales): invoice_type, fa3_xml, currency
 * @return array|null Null if not a correction
 */
function ksefKorViewData($db, $record)
{
    global $conf;
    dol_include_once('/ksef/class/ksef_incoming.class.php');
    if (!KsefIncoming::isCorrectionType((string) ($record->invoice_type ?? ''))) {
        return null;
    }
    $cur = strtoupper((string) ($record->currency ?? ''));
    $out = array('ok' => false, 'why' => '', 'rows' => array(), 'unchanged' => 0, 'currency' => $cur !== '' ? $cur : $conf->currency);
    $xml = (string) ($record->fa3_xml ?? '');
    $rows = trim($xml) === '' ? 'KSEF_KorViewWhyNoXml' : ksefKorViewXmlRows($db, $xml);
    if (!is_array($rows)) {
        $out['why'] = $rows;
        return $out;
    }
    foreach ($rows as $r) {
        if ($r['before'] && $r['after'] && !ksefKorViewDiffers($r['before'], $r['after'])) {
            $out['unchanged']++;
            continue;
        }
        $out['rows'][] = $r;
    }
    $out['ok'] = !empty($out['rows']);
    if (!$out['ok']) {
        $out['why'] = 'KSEF_KorViewWhyNoChange';
    }
    return $out;
}

/**
 * @brief Format signed number
 * @param float $v
 * @param string $cur Currency, '' for quantity
 * @return string
 */
function ksefKorViewSigned($v, $cur = '')
{
    global $langs;
    if (abs($v) < ($cur === '' ? 0.0000001 : 0.005)) {
        return '';
    }
    $s = $cur === '' ? price(price2num($v, 'MS'), 0, $langs, 0, 0) : price($v, 0, $langs, 1, -1, -1, $cur);
    return ($v > 0 ? '+' : '') . $s;
}

/**
 * @brief Table cells
 * @param array|null $s Side
 * @param string $cur
 * @return string
 */
function ksefKorViewCells($s, $cur)
{
    global $langs;
    if ($s === null) {
        return '<td class="right">-</td><td class="right">-</td><td class="right">-</td><td class="right">-</td>';
    }
    return '<td class="right nowraponall">' . price(price2num($s['qty'], 'MS'), 0, $langs, 0, 0) . ($s['unit'] !== '' ? ' ' . dol_escape_htmltag($s['unit']) : '') . '</td>'
        . '<td class="right nowraponall">' . ($s['price'] === null ? '' : price($s['price'], 0, $langs, 1, -1, -1, $cur)) . '</td>'
        . '<td class="right nowraponall">' . dol_escape_htmltag($s['rate']) . '</td>'
        . '<td class="right nowraponall">' . price($s['net'], 0, $langs, 1, -1, -1, $cur) . '</td>';
}

/**
 * @brief Record view HTML
 * @param DoliDB $db
 * @param object $record KSeF record of a correction
 * @return string Script, '' if none
 */
function ksefKorViewHtml($db, $record)
{
    global $langs;
    $data = ksefKorViewData($db, $record);
    if ($data === null) {
        return '';
    }
    $langs->loadLangs(array('main', 'bills', 'ksef@ksef'));
    $mode = 'dolibarr';
    $cur = $data['currency'];
    $html = '<div class="ksef-korview" id="ksef-korview" data-mode="' . $mode . '">';
    $html .= '<div class="ksef-korview-bar"><span class="opacitymedium">' . $langs->trans('KSEF_KorViewSwitch') . '</span>';
    $html .= '<span class="ksef-korview-switch" role="group">';
    if ($data['ok']) {
        $html .= '<button type="button" class="ksef-korview-btn" data-mode="both" aria-pressed="false">' . $langs->trans('KSEF_KorViewBoth') . '</button>';
    }
    $html .= '<button type="button" class="ksef-korview-btn" data-mode="dolibarr" aria-pressed="false">' . $langs->trans('KSEF_KorViewDolibarr') . '</button></span>';
    if (!$data['ok']) {
        $html .= '<span class="opacitymedium ksef-korview-why">' . dol_escape_htmltag($langs->transnoentitiesnoconv('KSEF_KorViewUnavailable', $langs->transnoentitiesnoconv($data['why']))) . '</span>';
    }
    $html .= '</div>';
    if ($data['ok']) {
        $html .= '<div class="ksef-korview-both div-table-responsive-no-min"><table class="noborder centpercent ksef-korview-table">';
        $html .= '<tr class="liste_titre"><td class="center">' . $langs->trans('KSEF_KorViewLineNo') . '</td><td>' . $langs->trans('Description') . '</td><td></td>'
            . '<td class="right">' . $langs->trans('Qty') . '</td><td class="right">' . $langs->trans('PriceUHT') . '</td><td class="right">' . $langs->trans('VAT') . '</td>'
            . '<td class="right">' . $langs->trans('TotalHTShort') . '</td></tr>';
        $total = 0.0;
        foreach ($data['rows'] as $r) {
            $b = $r['before'];
            $a = $r['after'];
            $diffNet = ($a ? $a['net'] : 0) - ($b ? $b['net'] : 0);
            $total += $diffNet;
            $html .= '<tr class="ksef-korview-before ksef-korview-first"><td class="center" rowspan="3">' . (int) $r['num'] . '</td><td rowspan="3">' . dol_escape_htmltag($r['desc']) . '</td>'
                . '<td class="nowraponall opacitymedium">' . $langs->trans('KSEF_KorViewBefore') . '</td>' . ksefKorViewCells($b, $cur) . '</tr>';
            $html .= '<tr class="ksef-korview-after"><td class="nowraponall">' . $langs->trans('KSEF_KorViewAfter') . '</td>' . ksefKorViewCells($a, $cur) . '</tr>';
            $qty = ksefKorViewSigned(($a ? $a['qty'] : 0) - ($b ? $b['qty'] : 0));
            $price = ($a && $b && $a['price'] !== null && $b['price'] !== null) ? ksefKorViewSigned($a['price'] - $b['price'], $cur) : '';
            $rate = ($a && $b && $a['rate'] !== $b['rate']) ? dol_escape_htmltag($b['rate'] . ' → ' . $a['rate']) : '';
            $html .= '<tr class="ksef-korview-diff"><td class="nowraponall">' . $langs->trans('KSEF_KorViewDiff') . '</td><td class="right nowraponall">' . $qty . '</td>'
                . '<td class="right nowraponall">' . $price . '</td><td class="right nowraponall">' . $rate . '</td><td class="right nowraponall">' . ksefKorViewSigned($diffNet, $cur) . '</td></tr>';
        }
        $html .= '<tr class="liste_total ksef-korview-total"><td colspan="6" class="right">' . $langs->trans('KSEF_KorViewTotal') . '</td><td class="right nowraponall">' . (abs($total) < 0.005 ? price(0, 0, $langs, 1, -1, -1, $cur) : ksefKorViewSigned($total, $cur)) . '</td></tr>';
        $html .= '</table>';
        $notes = array();
        $notes[] = $langs->trans('KSEF_KorViewSourceKsef');
        if ($data['unchanged'] > 0) {
            $notes[] = $langs->trans('KSEF_KorViewUnchanged', $data['unchanged']);
        }
        $html .= '<div class="opacitymedium small ksef-korview-note">' . implode(' ', $notes) . '</div></div>';
    }
    $html .= '</div>';
    $f = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    return '<div id="ksef-korview-slot"></div><script>jQuery(function ($) {'
        . ' if ($("#ksef-korview").length) return; var lines = $("#tablelines").first();'
        . ' var core = lines.length ? lines.closest(".div-table-responsive-no-min") : $(); if (lines.length && !core.length) core = lines;'
        . ' var box = $(' . json_encode($html, $f) . '); if (core.length) core.before(box); else $("#ksef-korview-slot").append(box);'
        . ' function show(m) { box.attr("data-mode", m); box.find(".ksef-korview-btn").each(function () { var on = $(this).data("mode") === m;'
        . ' $(this).toggleClass("ksef-korview-on", on).attr("aria-pressed", on ? "true" : "false"); });'
        . ' box.find(".ksef-korview-both").toggle(m === "both"); core.toggle(m !== "both"); }'
        . ' box.on("click", ".ksef-korview-btn", function () { show($(this).data("mode")); });'
        . ' show(box.attr("data-mode"));'
        . ' });</script>';
}
