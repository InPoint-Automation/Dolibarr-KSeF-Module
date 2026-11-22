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
 * \file    ksef/lib/ksef_import_ui.lib.php
 * \ingroup ksef
 * \brief   Import screen renderers
 */

/**
 * @brief Import lang keys
 * @param string $direction 'incoming' or 'outgoing'
 * @return array
 */
function ksefImportPartyLangs($direction)
{
    if ($direction === 'outgoing') {
        return array(
            'party' => 'Customer',
            'matched' => 'KSEF_BatchCustomerMatched',
            'will_create' => 'KSEF_BatchCustomerWillCreate',
            'not_matched' => 'KSEF_BatchCustomerNotMatched',
            'not_matched_noright' => 'KSEF_BatchCustomerNotMatchedNoRight',
            'no_match' => 'KSEF_BatchCustomerNoMatch',
            'create_party' => 'KSEF_BatchAutoCreateCustomers',
            'create_party_help' => 'KSEF_BatchAutoCreateCustomers_Help',
            'no_right_party' => 'KSEF_SalesBatchNoCreateCustomerWarn',
            'gus' => 'KSEF_GusFetchCustomers',
            'gus_help' => 'KSEF_GusFetchCustomers_Help',
            'gus_title' => 'KSEF_GusOverrideTitleCustomer',
            'gus_intro' => 'KSEF_GusOverrideIntroCustomer',
            'gus_failed' => 'KSEF_GusPreviewFailedCustomer',
            'gus_truncated' => 'KSEF_GusPreviewTruncatedCustomer',
        );
    }
    return array(
        'party' => 'Supplier',
        'matched' => 'KSEF_BatchSupplierMatched',
        'will_create' => 'KSEF_BatchSupplierWillCreate',
        'not_matched' => 'KSEF_BatchSupplierNotMatched',
        'not_matched_noright' => 'KSEF_BatchSupplierNotMatchedNoRight',
        'no_match' => 'KSEF_BatchSupplierNoMatch',
        'create_party' => 'KSEF_BatchAutoCreateSuppliers',
        'create_party_help' => 'KSEF_BatchAutoCreateSuppliers_Help',
        'no_right_party' => 'KSEF_BatchNoRightCreateSupplierWarn',
        'gus' => 'KSEF_GusFetchSuppliers',
        'gus_help' => 'KSEF_GusFetchSuppliers_Help',
        'gus_title' => 'KSEF_GusOverrideTitle',
        'gus_intro' => 'KSEF_GusOverrideIntro',
        'gus_failed' => 'KSEF_GusPreviewFailed',
        'gus_truncated' => 'KSEF_GusPreviewTruncated',
    );
}

/**
 * @brief P_12 rate label
 * @param mixed $rate
 * @return string
 */
function ksefImportVatLabel($rate)
{
    global $langs;

    $r = trim((string) $rate);
    if ($r === '') {
        return '';
    }
    if (is_numeric($r)) {
        return ((float) $r) . '%';
    }
    $map = array(
        '0 KR' => '0% (' . $langs->transnoentitiesnoconv("KSEF_VATDomestic") . ')',
        '0 WDT' => '0% (WDT)',
        '0 EX' => '0% (' . $langs->transnoentitiesnoconv("KSEF_VATExport") . ')',
        'zw' => $langs->transnoentitiesnoconv("KSEF_VATExempt"),
        'np' => $langs->transnoentitiesnoconv("KSEF_VATNotSubject"),
        'np I' => $langs->transnoentitiesnoconv("KSEF_VATNotSubject") . ' (I)',
        'np II' => $langs->transnoentitiesnoconv("KSEF_VATNotSubject") . ' (II)',
        'oo' => $langs->transnoentitiesnoconv("KSEF_VATReverseCharge"),
        'marza' => $langs->transnoentitiesnoconv("KSEF_VATMarginScheme"),
    );
    return isset($map[$r]) ? $map[$r] : $r;
}

/**
 * @brief Line display strings
 * @param array $line
 * @return array
 */
function ksefImportLineDisplay($line)
{
    global $langs;

    return array(
        'qty' => price((float) ($line['quantity'] ?? 0), 0, $langs, 1, 0, -1),
        'unit_price_net' => price((float) ($line['unit_price_net'] ?? 0), 0, $langs, 1, -1, -1),
        'net_amount' => price((float) ($line['net_amount'] ?? 0), 0, $langs, 1, -1, -1),
        'vat_label' => ksefImportVatLabel($line['vat_rate'] ?? ''),
    );
}

/**
 * @brief Any P_10 discount
 * @param array $lines
 * @return bool
 */
function ksefLinesHaveDiscount($lines)
{
    foreach ((array) $lines as $line) {
        if (isset($line['discount']) && is_numeric($line['discount']) && (float) $line['discount'] != 0) {
            return true;
        }
    }
    return false;
}

/**
 * @brief Line discount cell
 * @param array $line
 * @return string
 */
function ksefLineDiscountCell($line)
{
    if (isset($line['discount']) && is_numeric($line['discount']) && (float) $line['discount'] != 0) {
        return price((float) $line['discount'], 0, '', 1, -1, -1);
    }
    return '';
}

/**
 * @brief Import warehouse select
 * @param DoliDB $db
 * @param User $user
 * @param string $direction 'incoming' or 'outgoing'
 * @param int $selected
 * @return string '' when no stock moves
 */
function ksefImportWarehouseSelect($db, $user, $direction, $selected = 0)
{
    global $langs;
    $incoming = ($direction === 'incoming');
    if (!ksefUserCan($user, 'stock_movement') || !getDolGlobalString($incoming ? 'STOCK_CALCULATE_ON_SUPPLIER_BILL' : 'STOCK_CALCULATE_ON_BILL')) {
        return '';
    }
    $langs->load('stocks');
    require_once DOL_DOCUMENT_ROOT . '/product/class/html.formproduct.class.php';
    $fp = new FormProduct($db);
    // select2 breaks hidden
    return $fp->selectWarehouses($selected > 0 ? (int) $selected : ($incoming ? 'ifone' : 0), 'warehouse_id', '', 1, 0, 0, $langs->trans($incoming ? 'KSEF_ImportNoWarehouseDraft' : 'KSEF_SalesNoStockMove'), 0, 1);
}

/**
 * @brief Correction source warehouse
 * @param DoliDB $db
 * @param Facture|FactureFournisseur|string|null $source Corrected invoice
 * @param bool $supplier Supplier invoice
 * @return int 0 when none
 */
function ksefImportCorrectionWarehouse($db, $source, $supplier)
{
    if (!is_object($source) || !isModEnabled('stock')) {
        return 0;
    }
    $slots = ksefCorrectionStockSlots($db, $source, $supplier);
    $sum = array();
    foreach (is_array($slots) ? $slots : array() as $rows) {
        foreach ($rows as $s) {
            $sum[$s['w']] = ($sum[$s['w']] ?? 0) + $s['q'];
        }
    }
    $best = 0;
    foreach ($sum as $w => $q) {
        if ($w > 0 && ($best === 0 || $q > $sum[$best])) {
            $best = (int) $w;
        }
    }
    return $best;
}

/**
 * @brief Import stock preview
 * @param DoliDB $db
 * @param KsefIncoming $object
 * @param string $direction 'incoming' or 'outgoing'
 * @param int $warehouse 0 = none
 * @param array $lineProducts Line => product id
 * @param Facture|FactureFournisseur|string|null $source Corrected invoice of a correction
 * @return array|false false: collective correction
 */
function ksefImportStockPreview($db, $object, $direction, $warehouse, $lineProducts, $source = null)
{
    $incoming = ($direction === 'incoming');
    $const = $incoming ? 'STOCK_CALCULATE_ON_SUPPLIER_BILL' : 'STOCK_CALCULATE_ON_BILL';
    if ($warehouse <= 0 || !isModEnabled('stock') || !getDolGlobalString($const)) {
        return array();
    }
    if (is_object($source) && KsefIncoming::isCorrectionType($object->invoice_type) && $object->korekta_cited > 1) {
        return false;
    }
    $services = getDolGlobalString('STOCK_SUPPORTS_SERVICES');
    $sign = $incoming ? 1 : -1;
    $vs = null;
    $lines = $object->loadImportLines(false, $vs);
    if (!$incoming && $object->invoice_type === 'ROZ') {
        $lines = array_values(array_filter($lines, function ($l) {
            return empty($l['deposit_settlement']);
        }));
    }
    $prepared = $object->prepareImportLines($lines, $lineProducts, false, false, $vs, $const);
    $moves = array();
    foreach (is_array($prepared) ? $prepared : array() as $pl) {
        $p = (int) $pl['fk_product'];
        $q = (float) $pl['qty'];
        if ($p <= 0 || $q == 0 || ((int) $pl['type'] !== 0 && !$services)) {
            continue;
        }
        $moves[$p][$warehouse] = ($moves[$p][$warehouse] ?? 0) + $sign * ($incoming ? $q : abs($q));
    }
    if (is_object($source) && KsefIncoming::isCorrectionType($object->invoice_type)) {
        $slots = ksefCorrectionStockSlots($db, $source, $incoming);
        if ($slots) {
            if (empty($source->lines)) {
                $source->fetch_lines();
            }
            $lot = array();
            foreach ($source->lines as $line) {
                $p = (int) $line->fk_product;
                if (empty($slots[$p]) || (float) $line->qty == 0 || ((int) $line->product_type !== 0 && !$services)) {
                    continue;
                }
                $qty = (float) $line->qty;
                if (!isset($lot[$p])) {
                    $o = isModEnabled('productbatch') ? $db->fetch_object($db->query("SELECT tobatch FROM " . MAIN_DB_PREFIX . "product WHERE rowid = " . $p)) : null;
                    $lot[$p] = $o && (int) $o->tobatch > 0;
                }
                $parts = array();
                if (!$lot[$p] || $qty < 0) {
                    $parts[] = array((int) $slots[$p][0]['w'], $qty);
                } else {
                    $left = $qty;
                    foreach ($slots[$p] as $i => $s) {
                        $take = min($left, $s['q']);
                        if ($s['b'] === '' || $take <= 0) {
                            continue;
                        }
                        $parts[] = array((int) $s['w'], $take);
                        $slots[$p][$i]['q'] -= $take;
                        $left -= $take;
                    }
                    if ($left > 1e-9) {
                        $last = end($slots[$p]);
                        $parts[] = array((int) $last['w'], $left);
                    }
                }
                foreach ($parts as $part) {
                    $moves[$p][$part[0]] = ($moves[$p][$part[0]] ?? 0) - $sign * $part[1];
                }
            }
        }
    }
    $out = array();
    foreach ($moves as $p => $byWh) {
        foreach ($byWh as $w => $d) {
            $d = (float) price2num($d, 'MS');
            if (abs($d) < 1e-9) {
                continue;
            }
            $o = $db->fetch_object($db->query("SELECT p.ref, e.ref AS wref, ps.reel FROM " . MAIN_DB_PREFIX . "product AS p"
                . " JOIN " . MAIN_DB_PREFIX . "entrepot AS e ON e.rowid = " . (int) $w
                . " LEFT JOIN " . MAIN_DB_PREFIX . "product_stock AS ps ON ps.fk_product = p.rowid AND ps.fk_entrepot = e.rowid WHERE p.rowid = " . (int) $p));
            if (!$o) {
                continue;
            }
            $before = (float) $o->reel;
            $out[] = array('product' => (string) $o->ref, 'warehouse' => (string) $o->wref, 'before' => $before, 'after' => $before + $d, 'delta' => $d);
        }
    }
    return $out;
}

/**
 * @brief Stock preview table
 * @param array|false|null $rows null: products not chosen yet
 * @return string HTML
 */
function ksefImportStockPreviewHtml($rows)
{
    global $langs;
    $langs->loadLangs(array('products', 'stocks'));
    if (!is_array($rows) || !$rows) {
        $key = $rows === null ? 'KSEF_StockPreviewPending' : ($rows === false ? 'KSEF_StockPreviewCollective' : 'KSEF_StockPreviewNone');
        return '<div class="opacitymedium">' . dol_escape_htmltag($langs->trans($key)) . '</div>';
    }
    $out = '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
    $out .= '<tr class="liste_titre"><th>' . $langs->trans('Product') . '</th><th>' . $langs->trans('Warehouse') . '</th>';
    $out .= '<th class="right">' . $langs->trans('KSEF_StockPreviewCurrent') . '</th><th class="right">' . $langs->trans('Qty') . '</th>';
    $out .= '<th class="right">' . $langs->trans('KSEF_StockPreviewAfter') . '</th></tr>';
    foreach ($rows as $r) {
        $d = price2num($r['delta'], 'MS');
        $after = price2num($r['after'], 'MS');
        $out .= '<tr class="oddeven"><td class="nowraponall">' . img_picto('', 'product', 'class="pictofixedwidth"') . dol_escape_htmltag($r['product']) . '</td>';
        $out .= '<td class="nowraponall">' . img_picto('', 'stock', 'class="pictofixedwidth"') . dol_escape_htmltag($r['warehouse']) . '</td>';
        $out .= '<td class="right">' . price2num($r['before'], 'MS') . '</td>';
        $out .= '<td class="right"><span class="' . ($d > 0 ? 'stockmovemententry">+' : 'stockmovementexit">') . $d . '</span></td>';
        $out .= '<td class="right">' . $after . ($after < 0 ? ' ' . img_warning() : '') . '</td></tr>';
    }
    return $out . '</table></div>';
}

/**
 * @brief Stock preview JSON
 * @param DoliDB $db
 * @param User $user
 * @param KsefIncoming $object
 * @param string $direction 'incoming' or 'outgoing'
 * @return void
 */
function ksefImportStockPreviewAjax($db, $user, $object, $direction)
{
    $rows = array();
    if (ksefUserCan($user, 'stock_movement') && $user->hasRight('stock', 'lire')) {
        $lineProducts = array();
        $missing = false;
        foreach ($object->previewImportLines() as $line) {
            $ln = (int) ($line['line_num'] ?? 0);
            $lineProducts[$ln] = GETPOSTINT('line_product_' . $ln);
            if ($lineProducts[$ln] <= 0 && KsefIncoming::isProductLine($line) && (float) ($line['quantity'] ?? 0) != 0) {
                $missing = true;
            }
        }
        $source = KsefIncoming::isCorrectionType($object->invoice_type) ? $object->resolveKorektaSource(GETPOSTINT('correction_source_id'), GETPOSTINT('socid') > 0 ? GETPOSTINT('socid') : $object->findMatchingThirdParty()) : null;
        $warehouse = GETPOSTINT('warehouse_id');
        $rows = ksefImportStockPreview($db, $object, $direction, $warehouse, $lineProducts, $source);
        if ($rows !== false && $missing && $warehouse > 0) {
            $rows = null;
        }
    }
    top_httphead('application/json');
    print json_encode(array('html' => ksefImportStockPreviewHtml($rows), 'state' => $rows === null ? 'pending' : ($rows === false ? 'collective' : 'ready'), 'moves' => is_array($rows) ? count($rows) : 0));
    exit;
}

/**
 * @brief Create parties default
 * @param string $direction 'incoming' or 'outgoing'
 * @return bool
 */
function ksefImportPartyBoxDefault($direction)
{
    return getDolGlobalString($direction === 'outgoing' ? 'KSEF_BATCH_AUTO_CREATE_CUSTOMERS' : 'KSEF_BATCH_AUTO_CREATE_SUPPLIERS', '1') === '1';
}

/**
 * @brief Party GUS data
 * @param DoliDB $db
 * @param string $nip
 * @param string $sessionKey
 * @param bool $live false: no GUS call
 * @return array|null
 */
function ksefImportGusData($db, $nip, $sessionKey, $live)
{
    if (!getDolGlobalString('KSEF_GUS_ENABLED')) {
        return null;
    }
    $nip = ksefCleanNIP((string) $nip);
    if (!ksefValidateNIP($nip)) {
        return null;
    }
    $stored = ksefSessionRead($sessionKey, array(), false);
    if (is_array($stored) && !empty($stored[$nip]) && is_array($stored[$nip])) {
        return $stored[$nip];
    }
    if (!$live) {
        return null;
    }
    dol_include_once('/ksef/class/ksef_gus_client.class.php');
    $gc = new KsefGusClient($db, getDolGlobalString('KSEF_GUS_ENV', 'TEST'));
    $data = $gc->lookupByNip($nip);
    return is_array($data) ? $data : null;
}

/**
 * @brief Batch GUS preview
 * @param DoliDB $db
 * @param array $parties list of array('nip', 'country', 'name', 'address')
 * @param string $sessionKey
 * @return array
 */
function ksefBatchGusPreview($db, $parties, $sessionKey)
{
    global $langs;

    dol_include_once('/ksef/class/ksef_gus_client.class.php');
    @set_time_limit(0);
    $batchMax = getDolGlobalInt('KSEF_GUS_BATCH_MAX', 25);
    if ($batchMax < 1) {
        $batchMax = 25;
    }
    $batchDelayMs = getDolGlobalInt('KSEF_GUS_BATCH_DELAY_MS', 600);
    $gusClient = new KsefGusClient($db, getDolGlobalString('KSEF_GUS_ENV', 'TEST'));
    $found = array();
    $overrides = array();
    $seen = array();
    $looked = 0;
    $truncated = 0;
    $failed = 0;
    foreach ($parties as $party) {
        $nip = ksefCleanNIP((string) $party['nip']);
        if (((string) $party['country'] ?: 'PL') !== 'PL' || !ksefValidateNIP($nip) || isset($seen[$nip])) {
            continue;
        }
        $seen[$nip] = true;
        if ($looked >= $batchMax) {
            $truncated++;
            continue;
        }
        if ($looked > 0 && $batchDelayMs > 0) {
            usleep($batchDelayMs * 1000);
        }
        $looked++;
        $data = $gusClient->lookupByNip($nip);
        if ($data === false) {
            $failed++;
            continue;
        }
        $found[$nip] = $data;
        $changes = array();
        $name = trim((string) $party['name']);
        if (!empty($data['name']) && $data['name'] !== $name) {
            $changes[] = array('label' => $langs->transnoentitiesnoconv('Name'), 'from' => $name, 'to' => $data['name']);
        }
        // newline-joined address
        $gusAddr = trim(trim(($data['address'] ?? '') . ', ' . ($data['zip'] ?? '') . ' ' . ($data['town'] ?? '')), ',');
        $addr = trim(preg_replace('/\s*\r?\n\s*/', ', ', (string) $party['address']));
        if ($gusAddr !== '' && $gusAddr !== $addr) {
            $changes[] = array('label' => $langs->transnoentitiesnoconv('Address'), 'from' => $addr, 'to' => $gusAddr);
        }
        if ($changes) {
            $overrides[] = array('nip' => $nip, 'name' => ($name !== '' ? $name : $nip), 'new_name' => (string) ($data['name'] ?? ''), 'changes' => $changes);
        }
    }
    $gusClient->logout();
    ksefSessionWrite($sessionKey, $found);
    return array('status' => 'OK', 'overrides' => $overrides, 'truncated' => $truncated, 'failed_count' => $failed);
}

/**
 * @brief Batch preview lines
 * @param array $lines previewImportLines()
 * @param array $autoMatches autoMatchLineProducts()
 * @return array
 */
function ksefBatchPreviewLines($lines, $autoMatches)
{
    $out = array();
    foreach ($lines as $line) {
        $lineNum = $line['line_num'] ?? 0;
        $d = array(
            'line_num' => $lineNum,
            'description' => $line['description'] ?? '',
            'indeks' => $line['indeks'] ?? '',
            'gtin' => $line['gtin'] ?? '',
            'unit' => $line['unit'] ?? '',
            'vat_rate' => $line['vat_rate'] ?? '',
            'product_matched' => isset($autoMatches[$lineNum]),
        ) + ksefImportLineDisplay($line);
        if (isset($autoMatches[$lineNum])) {
            $d['match_method'] = $autoMatches[$lineNum]['match_method'];
            $d['product_ref'] = $autoMatches[$lineNum]['product_ref'];
            $d['product_label'] = $autoMatches[$lineNum]['product_label'];
        }
        $out[] = $d;
    }
    return $out;
}

/**
 * @brief Create parties row
 * @param User $user
 * @param string $direction 'incoming' or 'outgoing'
 * @return void
 */
function ksefPrintImportCreatePartiesRow($user, $direction)
{
    global $langs;
    $L = ksefImportPartyLangs($direction);
    $right = ksefUserCan($user, $direction === 'outgoing' ? 'create_customer' : 'create_supplier');
    $on = $right && ksefImportPartyBoxDefault($direction);
    print '<tr id="ksef_create_parties_row"><td></td><td>';
    print '<input type="hidden" name="create_parties_box" value="1">';
    print '<label><input type="checkbox" name="create_parties" id="ksef_create_parties" value="1"' . ($on ? ' checked' : '') . ($right ? '' : ' disabled') . '> ' . $langs->trans($L['create_party']) . '</label>';
    print ' <span class="fa fa-info-circle classfortooltip" style="opacity: 0.5;" title="' . dol_escape_htmltag($langs->trans($right ? $L['create_party_help'] : $L['no_right_party'])) . '"></span>';
    if (getDolGlobalString('KSEF_GUS_ENABLED')) {
        print '<br><label><input type="checkbox" name="gus_fetch" id="ksef_gus_fetch" value="1"' . ($on ? ' checked' : '') . ($on ? '' : ' disabled') . ($right ? '' : ' data-noright="1"') . '> ' . $langs->trans($L['gus']) . '</label>';
        print ' <span class="fa fa-info-circle classfortooltip" style="opacity: 0.5;" title="' . dol_escape_htmltag($langs->trans($L['gus_help'])) . '"></span>';
        print '<input type="hidden" name="gus_previewed" id="ksef_gus_previewed" value="0">';
        print '<script>$(function () { $("#ksef_create_parties").on("change", function () { var g = $("#ksef_gus_fetch");'
            . ' if (!g.length || g.data("noright")) { return; } g.prop("disabled", !this.checked);'
            . ' if (!this.checked) { g.data("was", g.is(":checked")); g.prop("checked", false); } else if (g.data("was") !== undefined) { g.prop("checked", !!g.data("was")); } else { g.prop("checked", true); } }); });</script>';
    }
    print '</td></tr>';
}

/**
 * @brief Batch import dialog
 * @param array $cfg
 * @return void
 */
function ksefPrintBatchImportDialog($cfg)
{
    global $langs, $user;
    $L = ksefImportPartyLangs($cfg['direction']);
    $rights = $cfg['rights'];

    print '<style>
    .batch-row-inactive td { opacity: 0.45; }
    .batch-row-inactive td:first-child { opacity: 1; }
    .batch-row-inactive .batch-action-badge { display: none; }
    .batch-row-inactive .warning { opacity: 1; }
    .batch-blocked .batch-invoice-cell { opacity: 1 !important; }
    .batch-blocked .batch-supplier-cell { opacity: 1 !important; }
    </style>';

    print '<div id="batch-import-modal" style="display:none;" title="' . dol_escape_htmltag($langs->trans("KSEF_BatchImportTitle")) . '">';
    print '<div id="batch-import-loading" class="center" style="padding:20px;"><span class="fa fa-spinner fa-spin fa-2x"></span></div>';
    print '<div id="batch-import-content" style="display:none;">';
    print '<p id="batch-import-summary"></p>';
    print '<div class="div-table-responsive">';
    print '<table class="noborder centpercent" id="batch-import-table">';
    print '<thead><tr class="liste_titre">';
    print '<th class="center" style="width:30px"><input type="checkbox" id="batch-select-all" checked></th>';
    print '<th>' . $langs->trans("Invoice") . '</th>';
    print '<th>' . $langs->trans("Amount") . '</th>';
    print '<th>' . $langs->trans($L['party']) . '</th>';
    print '<th>' . $langs->trans("Products") . '</th>';
    print '</tr></thead>';
    print '<tbody></tbody>';
    print '</table>';
    print '</div>';
    print '<div style="margin-top:10px;">';
    if (!$rights['create_party'] || !$rights['create_product']) {
        print '<div class="warning" id="batch-rights-warning">';
        if (!$rights['create_party']) print $langs->trans($L['no_right_party']) . '<br>';
        if (!$rights['create_product']) print $langs->trans('KSEF_BatchNoRightCreateProductWarn');
        print '</div>';
    }
    $supplierBoxOn = $rights['create_party'] && !empty($cfg['party_box_on']);
    $productBoxOn = $rights['create_product'] && !empty($cfg['product_box_on']);
    print '<label><input type="checkbox" id="batch-auto-create-suppliers"' . ($supplierBoxOn ? ' checked' : '') . ($rights['create_party'] ? '' : ' disabled') . '> ' . $langs->trans($L['create_party']) . '</label>';
    print ' <span class="fa fa-info-circle classfortooltip" style="opacity: 0.5;" title="' . dol_escape_htmltag($langs->trans($rights['create_party'] ? $L['create_party_help'] : $L['no_right_party'])) . '"></span><br>';
    print '<label><input type="checkbox" id="batch-auto-create-products"' . ($productBoxOn ? ' checked' : '') . ($rights['create_product'] ? '' : ' disabled') . '> ' . $langs->trans("KSEF_BatchAutoCreateProducts") . '</label>';
    print ' <span class="fa fa-info-circle classfortooltip" style="opacity: 0.5;" title="' . dol_escape_htmltag($langs->trans($rights['create_product'] ? 'KSEF_BatchAutoCreateProducts_Help' : 'KSEF_BatchNoRightCreateProductWarn')) . '"></span>';
    $creatableTypes = ksefCreatableProductTypes($user);
    if ($rights['create_product'] && count($creatableTypes) > 1) {
        print ' <select id="batch-product-type" class="flat maxwidth150 valignmiddle" title="' . dol_escape_htmltag($langs->trans('KSEF_CreateAsType')) . '">';
        print '<option value="0">' . $langs->trans('Product') . '</option><option value="1">' . $langs->trans('Service') . '</option></select>';
    } else {
        print '<input type="hidden" id="batch-product-type" value="' . (int) ($creatableTypes ? $creatableTypes[0] : 0) . '">';
    }
    if (!empty(getDolGlobalString('KSEF_GUS_ENABLED'))) {
        $gusOn = $supplierBoxOn;
        print '<br><label><input type="checkbox" id="batch-gus-fetch-suppliers"' . ($gusOn ? ' checked' : '') . ($rights['create_party'] ? ($supplierBoxOn ? '' : ' disabled') : ' disabled data-noright="1"') . '> ' . $langs->trans($L['gus']) . '</label>';
        print ' <span class="fa fa-info-circle classfortooltip" style="opacity: 0.5;" title="' . dol_escape_htmltag($langs->trans($L['gus_help'])) . '"></span>';
    }
    if (!empty($cfg['extra_options_html'])) {
        print '<div id="batch-import-extra-options" style="margin-top:8px;">' . $cfg['extra_options_html'] . '</div>';
    }
    print '</div>';
    if (!empty($cfg['stock_short'])) {
        $stockShort = $cfg['stock_short'];
        $stockVerbose = $cfg['stock_verbose'];

        print '<div style="margin-top:10px; padding:8px; background:#f0f4f8; border-radius:4px;">';
        print $stockShort;
        print ' <span class="fa fa-info-circle classfortooltip" style="opacity: 0.5;" title="' . dol_escape_htmltag($stockVerbose) . '"></span>';
        print '</div>';
    }
    print '</div>';
    print '<div id="batch-import-results" style="display:none;"></div>';
    print '</div>';

    print '<script type="text/javascript">
    $(document).ready(function() {
        var ajaxToken = ' . json_encode(currentToken()) . ';
        var baseUrl = ' . json_encode($_SERVER["PHP_SELF"]) . ';
        var batchData = null;
        var valuePrefix = ' . json_encode((string) ($cfg['value_prefix'] ?? '')) . ';

        var langSupplierMatched = ' . ksefJsLang($L['matched']) . ';
        var korTypeBadges = ' . json_encode(array('KOR' => ksefGetInvoiceTypeBadge('KOR'), 'KOR_ZAL' => ksefGetInvoiceTypeBadge('KOR_ZAL'), 'KOR_ROZ' => ksefGetInvoiceTypeBadge('KOR_ROZ')), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ';
        var langSupplierWillCreate = ' . ksefJsLang($L['will_create']) . ';
        var langProductsMatched = ' . ksefJsLang("KSEF_BatchProductsMatched") . ';
        var langLinesCount = ' . ksefJsLang("KSEF_BatchLinesCount") . ';
        var langConfirmImport = ' . ksefJsLang("KSEF_BatchImportConfirm") . ';
        var langClose = ' . ksefJsLang("Close") . ';
        var langImporting = ' . ksefJsLang("KSEF_BatchImporting") . ';
        var langBatchResults = ' . ksefJsLang("KSEF_BatchImportResults") . ';
        var langBatchSummary = ' . ksefJsLang("KSEF_BatchImportSummary") . ';
        var langSkipped = ' . ksefJsLang("KSEF_BatchSkippedRow") . ';
        var langPartyRoleMissing = ' . ksefJsLang(!empty($rights['create_party']) ? 'KSEF_BatchMatchedNotSupplier' : 'KSEF_BatchMatchedNotSupplierNoRight') . ';
        var createdCardUrl = ' . json_encode(DOL_URL_ROOT . ($cfg['direction'] === 'outgoing' ? '/compta/facture/card.php?facid=' : '/fourn/facture/card.php?facid=')) . ';
        var previewSkipped = [];
        var batchImportedSomething = false;
        var langSuccess = ' . ksefJsLang("KSEF_BatchImportSuccessLine") . ';
        var langDraft = ' . ksefJsLang("Draft") . ';
        var langError = ' . ksefJsLang("Error") . ';
        var langGusOverrideTitle = ' . ksefJsLang($L['gus_title']) . ';
        var langGusOverrideIntro = ' . ksefJsLang($L['gus_intro']) . ';
        var langGusOverrideProceed = ' . ksefJsLang("KSEF_GusOverrideProceed") . ';
        var langGusPreviewFailed = ' . ksefJsLang($L['gus_failed']) . ';
        var langGusPreviewTruncated = ' . ksefJsLang($L['gus_truncated']) . ';
        var langGusPreviewFailedCount = ' . ksefJsLang('KSEF_GusPreviewFailedCount') . ';
        var langGusCancel = ' . ksefJsLang("Cancel") . ';
        var langNoImportable = ' . ksefJsLang("KSEF_BatchNoImportable") . ';
        var langConnectionError = ' . ksefJsLang("KSEF_ConnectionError") . ';
        var langMatchedByRef = ' . ksefJsLang("KSEF_MatchedByRef") . ';
        var langMatchedBySupplierRef = ' . ksefJsLang("KSEF_MatchedBySupplierRef") . ';
        var langMatchedByBarcode = ' . ksefJsLang("KSEF_MatchedByBarcode") . ';
        var langWillCreate = ' . ksefJsLang("KSEF_BatchProductWillCreate") . ';
        var langCorrectionBlocked = ' . ksefJsLang("KSEF_BatchCorrectionBlocked") . ';
        var langSupplierNotMatched = ' . ksefJsLang($rights['create_party'] ? $L['not_matched'] : $L['not_matched_noright']) . ';
        var langSupplierNoMatch = ' . ksefJsLang($L['no_match']) . ';
        var langProductFreeText = ' . ksefJsLang("KSEF_BatchProductFreeText") . ';
        var langProductsFreeTextWarning = ' . ksefJsLang("KSEF_BatchProductsFreeTextWarning") . ';
        var langDescription = ' . ksefJsLang("Description") . ';
        var langRef = ' . ksefJsLang("Ref") . ';
        var langQty = ' . ksefJsLang("Qty") . ';
        var langUnitPriceShort = ' . ksefJsLang("PriceUHT") . ';
        var langProduct = ' . ksefJsLang("Product") . ';
        var langMatchMethod = ' . ksefJsLang("KSEF_MatchMethod") . ';
        var langVAT = ' . ksefJsLang("VAT") . ';
        var langTotalHT = ' . ksefJsLang("TotalHT") . ';

        var submitSource = null;
        $("form#searchFormList").on("click", "button, input[type=submit]", function() {
            submitSource = this.name || null;
        });

        $("form#searchFormList").on("submit", function(e) {
            if (submitSource === "button_search_x" || submitSource === "button_search"
                || submitSource === "button_removefilter_x" || submitSource === "button_removefilter") {
                submitSource = null;
                return true;
            }
            submitSource = null;

            var massactionVal = $("select[name=massaction]").val();
            if (massactionVal !== ' . json_encode((string) $cfg['massaction']) . ') return true;

            e.preventDefault();

            var selectedIds = [];
            $("input.checkforselect:checked").each(function() {
                var v = String($(this).val());
                if (valuePrefix === "") {
                    selectedIds.push(v);
                } else if (v.indexOf(valuePrefix) === 0) {
                    selectedIds.push(v.substring(valuePrefix.length));
                }
            });

            if (selectedIds.length === 0) {
                alert(' . ksefJsLang("NoRecordSelected") . ');
                return false;
            }

            $("#batch-import-loading").show();
            $("#batch-import-content").hide();
            $("#batch-import-results").hide();

            batchImportedSomething = false;
            var $dialog = $("#batch-import-modal").dialog({
                modal: true,
                width: Math.min(1170, $(window).width() - 40),
                maxHeight: $(window).height() - 100,
                position: { my: "center top", at: "center top+40", of: window },
                open: function() { $(this).css({ "max-height": Math.max(200, $(window).height() - 220) + "px", "overflow-y": "auto" }); },
                closeOnEscape: true,
                close: function() { if (batchImportedSomething) { location.reload(); } },
                buttons: [
                    {
                        text: langConfirmImport,
                        id: "batch-import-btn-confirm",
                        class: "butAction",
                        click: function() { executeBatchImport(); }
                    },
                    {
                        text: langClose,
                        click: function() { $(this).dialog("close"); }
                    }
                ]
            });

            $.ajax({
                url: baseUrl + "?action=batch_import_preview&token=" + ajaxToken,
                method: "POST",
                data: { ids: selectedIds },
                dataType: "json",
                success: function(data) {
                    if (data.status !== "OK" || !data.invoices || data.invoices.length === 0) {
                        $("#batch-import-loading").hide();
                        $("#batch-import-results").html("<p class=\"warning\">" + langNoImportable + "</p>").show();
                        $("#batch-import-btn-confirm").hide();
                        return;
                    }
                    batchData = data.invoices;
                    previewSkipped = data.skipped || [];
                    renderBatchPreview(data.invoices);
                },
                error: function() {
                    $("#batch-import-loading").hide();
                    $("#batch-import-results").html("<p class=\"error\">" + escapeHtml(langConnectionError) + "</p>").show();
                }
            });

            return false;
        });

        function getMatchMethodLabel(method) {
            if (method === "product_ref") return langMatchedByRef;
            if (method === "supplier_ref") return langMatchedBySupplierRef;
            if (method === "barcode") return langMatchedByBarcode;
            return method || "";
        }

        function isSupplierBlocked(inv) {
            if (inv.supplier_matched) return false;
            return !$("#batch-auto-create-suppliers").is(":checked");
        }

        function getRowBlockedState(inv) {
            if (inv.correction_blocked) return { blocked: true, reason: "correction" };
            if (isSupplierBlocked(inv)) return { blocked: true, reason: "supplier" };
            return { blocked: false, reason: null };
        }

        function buildSupplierHtml(inv, blockState) {
            if (blockState.blocked && blockState.reason === "correction") {
                return \'<span class="opacitymedium">\' + escapeHtml(inv.supplier_matched ? inv.supplier_name : inv.seller_name) + \'</span>\';
            }
            if (inv.supplier_matched) {
                var roleNote = inv.party_role_missing ? \'<br><span class="opacitymedium small batch-role-note"><span class="fa fa-info-circle"></span> \' + escapeHtml(langPartyRoleMissing) + \'</span>\' : "";
                return \'<span class="badge badge-status4 ksef-tone-ok">\' + langSupplierMatched + \'</span><br>\' + escapeHtml(inv.supplier_name) + roleNote;
            }
            if (blockState.blocked && blockState.reason === "supplier") {
                var html = \'<span class="badge badge-status8 ksef-tone-problem">\' + langSupplierNoMatch + \'</span><br>\' + escapeHtml(inv.seller_name);
                if (inv.seller_nip) html += \'<br><span class="opacitymedium">NIP: \' + escapeHtml(inv.seller_nip) + \'</span>\';
                if (inv.seller_vat_id) html += \'<br><span class="opacitymedium">VAT: \' + escapeHtml(inv.seller_vat_id) + \'</span>\';
                return html;
            }
            var html = \'<span class="badge badge-status1 batch-action-badge ksef-tone-action">\' + langSupplierWillCreate + \'</span><br>\' + escapeHtml(inv.seller_name);
            if (inv.seller_nip) html += \'<br><span class="opacitymedium">NIP: \' + escapeHtml(inv.seller_nip) + \'</span>\';
            if (inv.seller_vat_id) html += \'<br><span class="opacitymedium">VAT: \' + escapeHtml(inv.seller_vat_id) + \'</span>\';
            return html;
        }

        function buildSupplierWarningHtml(blockState) {
            if (blockState.blocked && blockState.reason === "supplier") {
                return \'<br><span class="warning"><span class="fa fa-exclamation-triangle"></span> \' + escapeHtml(langSupplierNotMatched) + \'</span>\';
            }
            return "";
        }

        function isAutoCreateProducts() {
            return $("#batch-auto-create-products").is(":checked");
        }

        function buildProductsHtml(inv, blockState, idx) {
            if (blockState.blocked) {
                return \'<span class="opacitymedium">\' + escapeHtml(langLinesCount.replace("%s", inv.total_lines)) + \'</span>\';
            }
            var productsHtml = inv.matched_lines + "/" + inv.total_lines + " " + langProductsMatched;
            if (inv.all_products_matched) {
                productsHtml = \'<span class="badge badge-status4 ksef-tone-ok">\' + productsHtml + \'</span>\';
            } else if (isAutoCreateProducts()) {
                productsHtml = \'<span class="badge badge-status1 ksef-tone-action">\' + productsHtml + \'</span>\';
            } else {
                productsHtml = \'<span class="badge badge-status1 ksef-tone-action">\' + productsHtml + \'</span>\';
                var freeTextCount = inv.total_lines - inv.matched_lines;
                if (freeTextCount > 0) {
                    productsHtml += \' <span class="badge badge-status0 ksef-tone-info">\' + freeTextCount + "/" + inv.total_lines + " " + langProductFreeText + \'</span>\';
                }
                productsHtml += \'<br><span class="opacitymedium small">\' + escapeHtml(langProductsFreeTextWarning) + \'</span>\';
            }
            productsHtml += \' <a href="#" class="batch-toggle-lines" data-inv-idx="\' + idx + \'"><span class="fa fa-chevron-down"></span></a>\';
            return productsHtml;
        }

        function buildUnmatchedLineProductCell(line) {
            if (isAutoCreateProducts()) {
                var willCreateInfo = \'<span class="badge badge-status1 ksef-tone-action">\' + langWillCreate + \'</span>\';
                if (line.description) {
                    willCreateInfo += \'<br><span class="opacitymedium">\' + escapeHtml(line.description);
                    if (line.indeks) willCreateInfo += \' [\' + escapeHtml(line.indeks) + \']\';
                    willCreateInfo += \'</span>\';
                }
                return willCreateInfo;
            } else {
                var freeTextInfo = \'<span class="badge badge-status0 ksef-tone-info">\' + langProductFreeText + \'</span>\';
                if (line.description) {
                    freeTextInfo += \'<br><span class="opacitymedium">\' + escapeHtml(line.description);
                    if (line.indeks) freeTextInfo += \' [\' + escapeHtml(line.indeks) + \']\';
                    freeTextInfo += \'</span>\';
                }
                return freeTextInfo;
            }
        }

        function updateRowBlockedState($row, inv, idx) {
            var blockState = getRowBlockedState(inv);
            var $checkbox = $row.find(".batch-row-check");

            $row.find(".batch-supplier-cell").html(buildSupplierHtml(inv, blockState));
            $row.find(".batch-products-cell").html(buildProductsHtml(inv, blockState, idx));
            $row.find(".batch-invoice-cell .batch-supplier-warning").remove();
            var supplierWarning = buildSupplierWarningHtml(blockState);
            if (supplierWarning) {
                $row.find(".batch-invoice-cell").append(\'<span class="batch-supplier-warning">\' + supplierWarning + \'</span>\');
            }

            var $detailRow = $(".batch-detail-row[data-inv-idx=\'" + idx + "\']");

            if (blockState.blocked) {
                $checkbox.prop("checked", false).prop("disabled", true);
                if (blockState.reason === "correction") {
                    $checkbox.attr("title", inv.block_title || langCorrectionBlocked);
                } else {
                    $checkbox.attr("title", langSupplierNotMatched);
                }
                $row.addClass("batch-blocked batch-row-inactive");
                $detailRow.hide();
                $row.find(".batch-toggle-lines span").removeClass("fa-chevron-up").addClass("fa-chevron-down");
            } else {
                if ($row.hasClass("batch-blocked")) {
                    $checkbox.prop("disabled", false).prop("checked", true).removeAttr("title");
                    $row.removeClass("batch-blocked batch-row-inactive");
                }
            }
        }

        function renderBatchPreview(invoices) {
            var tbody = $("#batch-import-table tbody");
            tbody.empty();

            $.each(invoices, function(i, inv) {
                var blockState = getRowBlockedState(inv);
                var isBlocked = blockState.blocked;

                var supplierHtml = buildSupplierHtml(inv, blockState);
                var productsHtml = buildProductsHtml(inv, blockState, i);

                var typeHtml = korTypeBadges[inv.invoice_type] ? " " + korTypeBadges[inv.invoice_type] : "";

                var warningsHtml = "";
                if (inv.correction_blocked && inv.correction_issues && inv.correction_issues.length > 0) {
                    warningsHtml = \'<br>\';
                    for (var ci = 0; ci < inv.correction_issues.length; ci++) {
                        warningsHtml += \'<span class="warning"><span class="fa fa-exclamation-triangle"></span> \' + escapeHtml(inv.correction_issues[ci]) + \'</span><br>\';
                    }
                }
                var swHtml = buildSupplierWarningHtml(blockState);
                if (swHtml) warningsHtml += \'<span class="batch-supplier-warning">\' + swHtml + \'</span>\';

                var blockTitle = "";
                if (blockState.reason === "correction") blockTitle = inv.block_title || langCorrectionBlocked;
                else if (blockState.reason === "supplier") blockTitle = langSupplierNotMatched;

                var checkboxHtml = isBlocked
                    ? \'<input type="checkbox" class="batch-row-check" disabled title="\' + escapeHtml(blockTitle) + \'">\'
                    : \'<input type="checkbox" class="batch-row-check" checked>\';

                var rowClass = isBlocked ? \' class="batch-blocked batch-row-inactive"\' : \'\';

                var tr = \'<tr data-id="\' + inv.id + \'" data-inv-idx="\' + i + \'" data-socid="\' + inv.socid + \'" data-supplier-matched="\' + (inv.supplier_matched ? "1" : "0") + \'"\' + rowClass + \'>\' +
                    \'<td class="center">\' + checkboxHtml + \'</td>\' +
                    \'<td class="batch-invoice-cell">\' + escapeHtml(inv.invoice_number) + typeHtml + \'<br><span class="opacitymedium">\' + escapeHtml(String(inv.invoice_date || "")) + \'</span>\' + warningsHtml + \'</td>\' +
                    \'<td class="right nowraponall">\' + escapeHtml(String(inv.total_gross || "")) + \'</td>\' +
                    \'<td class="batch-supplier-cell">\' + supplierHtml + \'</td>\' +
                    \'<td class="batch-products-cell">\' + productsHtml + \'</td>\' +
                    \'</tr>\';
                tbody.append(tr);

                if (inv.lines && inv.lines.length > 0) {
                    var detailHtml = \'<tr class="batch-detail-row" data-inv-idx="\' + i + \'" style="display:none;">\';
                    detailHtml += \'<td></td><td colspan="4">\';
                    detailHtml += \'<table class="noborder centpercent" style="background:#f8f8f8;">\';
                    detailHtml += \'<tr class="liste_titre"><th>#</th><th>\' + langDescription + \'</th><th>\' + langRef + \'</th><th class="right">\' + langQty + \'</th><th class="right">\' + langUnitPriceShort + \'</th><th class="right">\' + langVAT + \'</th><th class="right">\' + langTotalHT + \'</th><th>\' + langProduct + \'</th></tr>\';

                    $.each(inv.lines, function(j, line) {
                        var vatDisplay = escapeHtml(line.vat_label || "");
                        var refDisplay = escapeHtml(line.indeks);
                        if (line.gtin) {
                            refDisplay += (refDisplay ? "<br>" : "") + \'<span class="opacitymedium">\' + escapeHtml(line.gtin) + \'</span>\';
                        }

                        detailHtml += \'<tr class="oddeven">\';
                        detailHtml += \'<td>\' + escapeHtml(String(line.line_num)) + \'</td>\';
                        detailHtml += \'<td>\' + escapeHtml(line.description) + \'</td>\';
                        detailHtml += \'<td>\' + (refDisplay || "&ndash;") + \'</td>\';
                        detailHtml += \'<td class="right nowraponall">\' + escapeHtml(String(line.qty)) + \'</td>\';
                        detailHtml += \'<td class="right nowraponall">\' + escapeHtml(String(line.unit_price_net)) + \'</td>\';
                        detailHtml += \'<td class="right nowraponall">\' + vatDisplay + \'</td>\';
                        detailHtml += \'<td class="right nowraponall">\' + escapeHtml(String(line.net_amount)) + \'</td>\';
                        if (line.product_matched) {
                            detailHtml += \'<td><span class="badge badge-status4 ksef-tone-ok">\' + escapeHtml(line.product_ref || "") + \'</span> \' + escapeHtml(line.product_label || "") + \'<br><span class="opacitymedium">\' + getMatchMethodLabel(line.match_method) + \'</span></td>\';
                        } else {
                            detailHtml += \'<td class="batch-unmatched-product" data-inv-idx="\' + i + \'" data-line-idx="\' + j + \'">\' + buildUnmatchedLineProductCell(line) + \'</td>\';
                        }
                        detailHtml += \'</tr>\';
                    });

                    detailHtml += \'</table></td></tr>\';
                    tbody.append(detailHtml);
                }
            });

            $("#batch-import-loading").hide();
            $("#batch-import-content").show();
            syncImportButton();
        }

        // importable rows only
        function syncImportButton() {
            var $btn = $("#batch-import-btn-confirm");
            if (!$btn.length || $btn.text() === langImporting) return;
            var $usable = $(".batch-row-check:not(:disabled)");
            $btn.prop("disabled", $usable.filter(":checked").length === 0);
            $("#batch-select-all").prop("checked", $usable.length > 0 && $usable.filter(":not(:checked)").length === 0);
        }

        $(document).on("click", ".batch-toggle-lines", function(e) {
            e.preventDefault();
            var idx = $(this).data("inv-idx");
            var $detailRow = $(".batch-detail-row[data-inv-idx=\'" + idx + "\']");
            $detailRow.toggle();
            $(this).find("span").toggleClass("fa-chevron-down fa-chevron-up");
        });

        $("#batch-select-all").on("change", function() {
            var checked = $(this).is(":checked");
            $(".batch-row-check:not(:disabled)").prop("checked", checked).trigger("change");
        });

        $("#batch-auto-create-suppliers").on("change", function() {
            var $gus = $("#batch-gus-fetch-suppliers");
            if ($gus.length && !$gus.data("noright")) {
                $gus.prop("disabled", !this.checked);
                if (!this.checked) {
                    $gus.data("was", $gus.is(":checked"));
                    $gus.prop("checked", false);
                } else if ($gus.data("was") !== undefined) {
                    $gus.prop("checked", !!$gus.data("was"));
                }
            }
            if (!batchData) return;
            $("#batch-import-table tbody tr[data-id]").each(function() {
                var $row = $(this);
                var idx = parseInt($row.data("inv-idx"));
                if (isNaN(idx) || !batchData[idx]) return;
                var inv = batchData[idx];
                if (inv.correction_blocked) return;
                updateRowBlockedState($row, inv, idx);
            });
        });

        $("#batch-auto-create-products").on("change", function() {
            if (!batchData) return;
            $(".batch-unmatched-product").each(function() {
                var $cell = $(this);
                var invIdx = parseInt($cell.data("inv-idx"));
                var lineIdx = parseInt($cell.data("line-idx"));
                if (isNaN(invIdx) || !batchData[invIdx]) return;
                var line = batchData[invIdx].lines[lineIdx];
                if (line && !line.product_matched) {
                    $cell.html(buildUnmatchedLineProductCell(line));
                }
            });
            $("#batch-import-table tbody tr[data-id]").each(function() {
                var $row = $(this);
                var idx = parseInt($row.data("inv-idx"));
                if (isNaN(idx) || !batchData[idx]) return;
                var inv = batchData[idx];
                var blockState = getRowBlockedState(inv);
                $row.find(".batch-products-cell").html(buildProductsHtml(inv, blockState, idx));
            });
            syncImportButton();
        });

        $(document).on("change", ".batch-row-check", function() {
            var $row = $(this).closest("tr");
            var idx = $row.data("inv-idx");
            var $detailRow = $(".batch-detail-row[data-inv-idx=\'" + idx + "\']");
            if ($(this).is(":checked")) {
                $row.removeClass("batch-row-inactive");
                $detailRow.removeClass("batch-row-inactive");
            } else {
                $row.addClass("batch-row-inactive");
                $detailRow.addClass("batch-row-inactive");
            }
            syncImportButton();
        });

        function executeBatchImport() {
            if (!batchData) return;

            var decisions = [];
            var autoCreateSuppliers = $("#batch-auto-create-suppliers").is(":checked");
            var autoCreateProducts = $("#batch-auto-create-products").is(":checked");
            var productType = parseInt($("#batch-product-type").val()) || 0;
            var gusFetch = $("#batch-gus-fetch-suppliers").is(":checked") && !$("#batch-gus-fetch-suppliers").prop("disabled");

            $("#batch-import-table tbody tr").each(function() {
                var $row = $(this);
                if (!$row.find(".batch-row-check").is(":checked")) return;

                decisions.push({
                    id: parseInt($row.data("id")),
                    socid: parseInt($row.data("socid")) || 0,
                    auto_create_supplier: autoCreateSuppliers && $row.data("supplier-matched") !== "1" && parseInt($row.data("socid")) <= 0,
                    auto_create_products: autoCreateProducts,
                    product_type: productType
                });
            });

            if (decisions.length === 0) return;

            var payload = {
                decisions: decisions,
                auto_create_suppliers: autoCreateSuppliers,
                auto_create_products: autoCreateProducts,
                product_type: productType,
                fetch_suppliers: gusFetch,
                options: {}
            };
            $("#batch-import-extra-options").find("select, input").each(function() {
                if (!this.name) return;
                payload.options[this.name] = (this.type === "checkbox") ? (this.checked ? 1 : 0) : $(this).val();
            });

            var supplierIds = [];
            if (gusFetch) {
                $.each(decisions, function(i, d) { if (d.auto_create_supplier) supplierIds.push(d.id); });
            }

            if (gusFetch && supplierIds.length > 0) {
                $("#batch-import-btn-confirm").prop("disabled", true);
                $.ajax({
                    url: baseUrl + "?action=gus_supplier_preview&token=" + ajaxToken,
                    method: "POST",
                    contentType: "application/json",
                    data: JSON.stringify({ ids: supplierIds }),
                    dataType: "json",
                    success: function(resp) {
                        $("#batch-import-btn-confirm").prop("disabled", false);
                        var trunc = (resp && resp.truncated) ? resp.truncated : 0;
                        var failed = (resp && resp.failed_count) ? resp.failed_count : 0;
                        if (resp && resp.status === "OK" && resp.overrides && resp.overrides.length > 0) {
                            showGusOverrideConfirm(resp.overrides, payload, trunc, failed);
                        } else if (trunc > 0 || failed > 0) {
                            var msg = [];
                            if (failed > 0) msg.push(langGusPreviewFailedCount.replace("%s", failed));
                            if (trunc > 0) msg.push(langGusPreviewTruncated.replace("%s", trunc));
                            if (confirm(msg.join("\n"))) {
                                doExecuteBatchImport(payload);
                            }
                        } else {
                            doExecuteBatchImport(payload);
                        }
                    },
                    error: function() {
                        $("#batch-import-btn-confirm").prop("disabled", false);
                        if (confirm(langGusPreviewFailed)) {
                            doExecuteBatchImport(payload);
                        }
                    }
                });
                return;
            }

            doExecuteBatchImport(payload);
        }

        function showGusOverrideConfirm(overrides, payload, truncated, failed) {
            var h = "<div style=\"max-height:300px; overflow:auto;\"><p>" + escapeHtml(langGusOverrideIntro) + "</p>";
            if (failed && failed > 0) {
                h += "<p class=\"warning\">" + escapeHtml(langGusPreviewFailedCount.replace("%s", failed)) + "</p>";
            }
            if (truncated && truncated > 0) {
                h += "<p class=\"warning\">" + escapeHtml(langGusPreviewTruncated.replace("%s", truncated)) + "</p>";
            }
            $.each(overrides, function(i, o) {
                h += "<div style=\"margin-bottom:8px;\"><strong>" + escapeHtml(o.name) + "</strong> (NIP " + escapeHtml(o.nip) + ")<ul>";
                $.each(o.changes, function(j, c) {
                    h += "<li>" + escapeHtml(c.label) + ": <span class=\"opacitymedium\">" + escapeHtml(c.from) + "</span> &rarr; " + escapeHtml(c.to) + "</li>";
                });
                h += "</ul></div>";
            });
            h += "</div>";

            $("#ksef-gus-override-dialog").remove();
            var $dlg = $("<div></div>").attr("id", "ksef-gus-override-dialog").attr("title", langGusOverrideTitle).html(h);
            $("body").append($dlg);
            $dlg.dialog({
                modal: true,
                width: 520,
                buttons: [
                    { text: langGusOverrideProceed, click: function() { $(this).dialog("close"); doExecuteBatchImport(payload); } },
                    { text: langGusCancel, click: function() { $(this).dialog("close"); } }
                ]
            });
        }

        function doExecuteBatchImport(payload) {
            $("#batch-import-btn-confirm").prop("disabled", true).text(langImporting);
            $("#batch-import-content").hide();
            $("#batch-import-loading").show();

            $.ajax({
                url: baseUrl + "?action=batch_import_execute&token=" + ajaxToken,
                method: "POST",
                contentType: "application/json",
                data: JSON.stringify(payload),
                dataType: "json",
                success: function(data) {
                    $("#batch-import-loading").hide();
                    $("#batch-import-btn-confirm").hide();

                    var html = "<h3>" + escapeHtml(langBatchResults) + "</h3><ul>";
                    var skippedCount = 0;
                    if (data.results) {
                        $.each(data.results, function(i, r) {
                            if (r.status === "OK") {
                                var okLabel = escapeHtml(r.invoice_number || "#" + r.id);
                                if (parseInt(r.facture_id, 10) > 0) {
                                    okLabel = \'<a href="\' + createdCardUrl + parseInt(r.facture_id, 10) + \'" target="_blank" rel="noopener">\' + okLabel + \'</a>\';
                                }
                                html += \'<li><span class="badge badge-status4 ksef-tone-ok">OK</span> \' + okLabel;
                                if (r.draft) { html += \' <span class="badge badge-status0 batch-draft-badge ksef-tone-info">\' + escapeHtml(langDraft) + \'</span>\'; }
                                $.each(r.warnings || [], function(j, w) { html += \' <span class="badge badge-warning ksef-tone-check">\' + escapeHtml(w) + \'</span>\'; });
                                html += \'</li>\';
                            } else if (r.status === "SKIPPED") {
                                skippedCount++;
                                html += \'<li><span class="badge badge-status6 ksef-tone-info">\' + escapeHtml(langSkipped) + \'</span> \' + escapeHtml(r.invoice_number || "#" + r.id) + (r.error ? \': \' + escapeHtml(r.error) : \'\') + \'</li>\';
                            } else {
                                html += \'<li><span class="badge badge-status8 ksef-tone-problem">\' + escapeHtml(langError) + \'</span> \' + escapeHtml(r.invoice_number || "#" + r.id) + \': \' + escapeHtml(r.error || "") + \'</li>\';
                            }
                        });
                    }
                    var decided = {};
                    $.each(payload.decisions || [], function(i, d) { decided[String(d.id)] = true; });
                    $.each(batchData || [], function(i, inv) {
                        if (decided[String(inv.id)]) return;
                        skippedCount++;
                        html += \'<li><span class="badge badge-status5 ksef-tone-info">\' + escapeHtml(langSkipped) + \'</span> \' + escapeHtml(inv.invoice_number || "#" + inv.id) + (inv.block_title ? \': \' + escapeHtml(inv.block_title) : \'\') + \'</li>\';
                    });
                    $.each(previewSkipped || [], function(i, sk) {
                        skippedCount++;
                        html += \'<li><span class="badge badge-status5 ksef-tone-info">\' + escapeHtml(langSkipped) + \'</span> \' + escapeHtml(sk.invoice_number || "#" + sk.id) + (sk.reason ? \': \' + escapeHtml(sk.reason) : \'\') + \'</li>\';
                    });
                    html += "</ul>";
                    html += \'<p><strong>\' + escapeHtml(langBatchSummary.replace("%s", data.success_count || 0).replace("%s", data.error_count || 0).replace("%s", skippedCount)) + \'</strong></p>\';

                    $("#batch-import-results").html(html).show();

                    if (data.success_count > 0) {
                        batchImportedSomething = true;
                    }
                },
                error: function() {
                    $("#batch-import-loading").hide();
                    $("#batch-import-results").html("<p class=\"error\">" + escapeHtml(langConnectionError) + "</p>").show();
                }
            });
        }

        function escapeHtml(str) {
            if (!str) return "";
            var div = document.createElement("div");
            div.appendChild(document.createTextNode(str));
            return div.innerHTML.replace(/"/g, "&quot;").replace(/\'/g, "&#39;");
        }
    });
    </script>';
}

/**
 * @brief Import amounts column
 * @param KsefIncoming $object
 * @return void
 */
function ksefPrintImportAmounts($object)
{
    global $langs;

    print '<table class="border tableforfield centpercent">';

    print '<tr><td class="titlefieldmiddle">' . $langs->trans("Currency") . '</td>';
    print '<td>' . dol_escape_htmltag($object->currency) . '</td></tr>';

    print '<tr><td>' . $langs->trans('AmountHT') . '</td>';
    print '<td class="nowrap amountcard right">' . price($object->total_net, 0, $langs, 0, -1, -1, $object->currency) . '</td></tr>';

    print '<tr><td>' . $langs->trans('AmountVAT') . '</td>';
    print '<td class="nowrap amountcard right">' . price($object->total_vat, 0, $langs, 0, -1, -1, $object->currency) . '</td></tr>';

    print '<tr><td>' . $langs->trans('AmountTTC') . '</td>';
    print '<td class="nowrap amountcard right">' . price($object->total_gross, 0, $langs, 0, -1, -1, $object->currency) . '</td></tr>';

    if ($object->payment_due_date) {
        print '<tr><td>' . $langs->trans("DateMaxPayment") . '</td>';
        print '<td>' . dol_print_date($object->payment_due_date, 'day') . '</td></tr>';
    }
    $xmlPayRows = ksefPaymentTermsAccountsRows($object->fa3_xml ?? '');
    print $xmlPayRows['terms'] . $xmlPayRows['accounts'];

    if (!empty($object->payment_status) && $object->payment_status !== 'unpaid') {
        $notSpecified = '<span class="opacitymedium">' . $langs->trans("KSEF_NotSpecifiedInXml") . '</span>';

        print '<tr><td>' . $langs->trans("KSEF_PaymentStatus") . '</td><td>';
        if ($object->payment_status === 'paid') {
            print '<span class="badge ' . ksefToneBadgeClass('ok') . ' badge-status">' . $langs->trans("KSEF_SellerMarkedPaid") . '</span>';
        } elseif ($object->payment_status === 'paid_installments') {
            print '<span class="badge ' . ksefToneBadgeClass('ok') . ' badge-status">' . $langs->trans("KSEF_SellerMarkedPaidInstallments") . '</span>';
        } elseif ($object->payment_status === 'partial') {
            print '<span class="badge ' . ksefToneBadgeClass('action') . ' badge-status">' . $langs->trans("KSEF_SellerMarkedPartial") . '</span>';
        }
        print '</td></tr>';

        print '<tr><td>' . $langs->trans("KSEF_PaymentDate") . '</td><td>';
        print $object->payment_date ? dol_print_date($object->payment_date, 'day') : $notSpecified;
        print '</td></tr>';

        print '<tr><td>' . $langs->trans("KSEF_PaymentMethod") . '</td><td>';
        $methodLabel = ksefGetPaymentMethodLabel($object->payment_method);
        print $methodLabel ? dol_escape_htmltag($methodLabel) : $notSpecified;
        print '</td></tr>';
    }

    print '</table>';
}

/**
 * @brief Create button forms
 * @param array $lines
 * @return void
 */
function ksefPrintImportHelperForms($lines)
{
    print '<form id="ksef_create_supplier_form" method="POST" action="' . DOL_URL_ROOT . '/societe/card.php"></form>';
    foreach ((array) $lines as $line) {
        print '<form id="ksef_create_product_form_' . ((int) ($line['line_num'] ?? 0)) . '" method="POST" action="' . DOL_URL_ROOT . '/product/card.php"></form>';
    }
}

/**
 * @brief Import party row
 * @param Form $form
 * @param KsefIncoming $object
 * @param array $party XML party data
 * @param int $selectedSocid
 * @param int $autoSocid Matched party
 * @param string $backtopageRaw
 * @param string $direction 'incoming' or 'outgoing'
 * @return void
 */
function ksefPrintImportPartyPicker($form, $object, $party, $selectedSocid, $autoSocid, $backtopageRaw, $direction)
{
    global $db, $langs, $user;
    require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
    $outgoing = ($direction === 'outgoing');

    print '<tr><td>' . $langs->trans($outgoing ? 'Customer' : 'Supplier') . '</td><td>';

    if ($selectedSocid > 0) {
        $supplierObj = new Societe($db);
        if ($supplierObj->fetch($selectedSocid) > 0) {
            print '<strong>' . dol_escape_htmltag($supplierObj->name) . '</strong>';
            $ownNip = trim((string) ksefGetIdentifierField($supplierObj, 'NIP'));
            $ownId = '';
            if ($ownNip !== '') {
                $ownId = 'NIP: ' . ksefFormatNIP($ownNip);
                print ' (NIP: ' . ksefFormatNIP($ownNip) . ')';
            } elseif (!empty($supplierObj->tva_intra)) {
                $ownId = 'VAT: ' . $supplierObj->tva_intra;
                print ' <span class="opacitymedium">(VAT: ' . dol_escape_htmltag($supplierObj->tva_intra) . ')</span>';
            }
            $idState = $object->partyIdentityState($selectedSocid);
            $invId = !empty($party['nip']) ? ksefFormatNIP($party['nip']) : (string) ($party['vat_id'] ?? '');
            if ($idState === 'mismatch' || $idState === 'no_id') {
                print ' <span class="badge badge-warning classfortooltip ' . ksefToneClass('check') . '" title="' . dol_escape_htmltag($langs->trans('KSEF_PartyIdMismatch')) . '">'
                    . $langs->trans($idState === 'mismatch' ? 'KSEF_PartyIdOther' : 'KSEF_PartyIdNone', dol_escape_htmltag($invId)) . '</span>';
            }
            if ($selectedSocid == $autoSocid) {
                print ' <span class="badge badge-info ' . ksefToneClass('info') . '">' . $langs->trans($outgoing ? 'KSEF_CustomerAutoMatched' : 'KSEF_SupplierAutoMatched') . '</span>';
            }
            print '<input type="hidden" name="socid" id="socid_hidden" value="' . $selectedSocid . '"';
            if ($idState === 'mismatch') {
                print ' data-ksef-idstate="mismatch" data-ksef-invoice-id="' . dol_escape_htmltag((!empty($party['nip']) ? 'NIP: ' : 'VAT: ') . $invId) . '" data-ksef-party-id="' . dol_escape_htmltag($ownId) . '"';
            }
            print '>';
            print '<br><a href="#" id="change_supplier_link" class="small">' . $langs->trans("KSEF_SupplierChangeSelection") . '</a>';
            print '<div id="supplier_selector_div" style="display:none; margin-top:5px;">';
        } else {
            $selectedSocid = 0;
        }
    }

    if ($selectedSocid <= 0) {
        $sellerIdDisplay = '';
        if (!empty($party['nip'])) {
            $sellerIdDisplay = 'NIP: ' . ksefFormatNIP($party['nip']);
        }
        if (!empty($party['vat_id'])) {
            $sellerIdDisplay .= ($sellerIdDisplay ? ', VAT: ' : 'VAT: ') . dol_escape_htmltag($party['vat_id']);
        }
        if (!empty($sellerIdDisplay)) {
            // note follows box
            $auto = ksefUserCan($user, $outgoing ? 'create_customer' : 'create_supplier') && ksefImportPartyBoxDefault($direction);
            print '<span class="warning ksef-party-missing" data-auto="0"' . ($auto ? ' style="display: none;"' : '') . '><span class="fa fa-exclamation-triangle"></span> '
                . $langs->trans($outgoing ? 'KSEF_CustomerNotFound' : 'KSEF_SupplierNotFound', $sellerIdDisplay) . '</span>';
            // GUS by checkbox
            $gus = getDolGlobalString('KSEF_GUS_ENABLED') && !empty($party['nip']);
            print '<span class="ksef-party-missing" data-auto="1" data-gus="0"' . ($auto && !$gus ? '' : ' style="display: none;"') . '><span class="fa fa-info-circle opacitymedium"></span> '
                . $langs->trans($outgoing ? 'KSEF_CustomerNotFoundAuto' : 'KSEF_SupplierNotFoundAuto', $sellerIdDisplay) . '</span>';
            if ($gus) {
                print '<span class="ksef-party-missing" data-auto="1" data-gus="1"' . ($auto ? '' : ' style="display: none;"') . '><span class="fa fa-info-circle opacitymedium"></span> '
                    . $langs->trans($outgoing ? 'KSEF_CustomerNotFoundAutoGus' : 'KSEF_SupplierNotFoundAutoGus', $sellerIdDisplay) . '</span>';
            }
            print '<br>';
            print '<script>$(function () { function upd() { var a = $("#ksef_create_parties").is(":checked") ? 1 : 0, g = $("#ksef_gus_fetch"), u = (a && g.length && g.is(":checked") && !g.prop("disabled") && $(".ksef-party-missing[data-gus=1]").length) ? 1 : 0;'
                . ' $(".ksef-party-missing").hide().filter(a ? "[data-auto=1][data-gus=" + u + "]" : "[data-auto=0]").show(); }'
                . ' $("#ksef_create_parties, #ksef_gus_fetch").on("change", function () { setTimeout(upd, 0); }); });</script>';
        }
        print '<div id="supplier_selector_div" style="margin-top:5px;">';
    }

    print $form->select_company($selectedSocid > 0 ? 0 : '', 'socid_select', $outgoing ? '(s.client:IN:1,2,3)' : '(s.fournisseur:=:1)', $langs->trans("SelectThirdParty"), 0, 0, array(), 0, 'maxwidth300 widthcentpercentminusx');
    print '<script>$(function() { $("#change_supplier_link").on("click", function(e) { e.preventDefault(); $("#supplier_selector_div").toggle(); }); $("#socid_select").on("change", function() { var v = parseInt($(this).val(), 10); if (v > 0) { var u = new URL(window.location.href); u.searchParams.set("socid", v); u.searchParams.delete("action"); window.location.href = u.toString(); } }); });</script>';

    $sForm = 'ksef_create_supplier_form';
    print ' ';
    $partyRight = $outgoing ? 'create_customer' : 'create_supplier';
    if (!ksefUserCan($user, $partyRight)) {
        print dolGetButtonTitle($langs->trans($outgoing ? 'KSEF_CreateCustomer' : 'KSEF_CreateSupplier'), '', 'fa fa-plus-circle', '', '', 0, array('attr' => array('title' => ksefMissingRightText($partyRight))));
        print '</div>';
        print '</td></tr>';
        return;
    }
    print '<input type="hidden" form="' . $sForm . '" name="token" value="' . newToken() . '">';
    print '<input type="hidden" form="' . $sForm . '" name="action" value="create">';
    print '<input type="hidden" form="' . $sForm . '" name="type" value="' . ($outgoing ? 'c' : 'f') . '">';
    print '<input type="hidden" form="' . $sForm . '" name="backtopage" value="' . dol_escape_htmltag($backtopageRaw) . '">';
    if (!empty($party['name'])) {
        print '<input type="hidden" form="' . $sForm . '" name="name" value="' . dol_escape_htmltag($party['name']) . '">';
    }
    if (!empty($party['nip']) && $party['country'] == 'PL') {
        $nipField = ksefGetFieldName('NIP');
        if (!empty($nipField) && $nipField !== 'tva_intra') {
            print '<input type="hidden" form="' . $sForm . '" name="' . dol_escape_htmltag($nipField) . '" value="' . dol_escape_htmltag($party['nip']) . '">';
        } elseif ($nipField === 'tva_intra') {
            print '<input type="hidden" form="' . $sForm . '" name="tva_intra" value="' . dol_escape_htmltag($party['nip']) . '">';
        }
    }
    if (!empty($party['vat_id'])) {
        print '<input type="hidden" form="' . $sForm . '" name="tva_intra" value="' . dol_escape_htmltag($party['vat_id']) . '">';
    } elseif (!empty($party['nip']) && $party['country'] != 'PL') {
        print '<input type="hidden" form="' . $sForm . '" name="tva_intra" value="' . dol_escape_htmltag($party['nip']) . '">';
    }
    if (!empty($party['address'])) {
        $split = ksefSplitAddress($party['address'], $party['country'] ?? 'PL');
        print '<input type="hidden" form="' . $sForm . '" name="address" value="' . dol_escape_htmltag($split['address'], 0, 1) . '">';
        if ($split['zip'] !== '') {
            print '<input type="hidden" form="' . $sForm . '" name="zipcode" value="' . dol_escape_htmltag($split['zip']) . '">';
            print '<input type="hidden" form="' . $sForm . '" name="town" value="' . dol_escape_htmltag($split['town']) . '">';
        }
    }
    if (!empty($party['country'])) {
        require_once DOL_DOCUMENT_ROOT . '/core/lib/company.lib.php';
        $cid = getCountry($party['country'], '3');
        print '<input type="hidden" form="' . $sForm . '" name="country_code" value="' . dol_escape_htmltag($party['country']) . '">';
        if ($cid > 0) {
            print '<input type="hidden" form="' . $sForm . '" name="country_id" value="' . (int) $cid . '">';
        }
    }
    if ($outgoing && is_object($object) && method_exists($object, 'buyerFlagOptions')) {
        foreach ($object->buyerFlagOptions() as $key => $v) {
            print '<input type="hidden" form="' . $sForm . '" name="' . dol_escape_htmltag($key) . '" value="' . dol_escape_htmltag((string) $v) . '">';
        }
    }
    print '<button type="submit" form="' . $sForm . '" class="btnTitle btnTitlePlus" title="' . dol_escape_htmltag($langs->trans($outgoing ? 'KSEF_CreateCustomer' : 'KSEF_CreateSupplier')) . '">';
    print '<span class="fa fa-plus-circle valignmiddle btnTitle-icon"></span>';
    print '</button>';

    print '</div>';

    print '</td></tr>';
}

/**
 * @brief Import lines table
 * @param Form $form
 * @param KsefIncoming $object
 * @param array $lines
 * @param array $autoMatches line_num => match
 * @param string $backtopageRaw
 * @param string $direction 'incoming' or 'outgoing'
 * @return void
 */
function ksefPrintImportLines($form, $object, $lines, $autoMatches, $backtopageRaw, $direction)
{
    global $langs, $user;
    $outgoing = ($direction === 'outgoing');
    $canCreateProduct = ksefUserCan($user, 'create_product');

    if (!empty($lines)) {
        print '<br>';
        print '<div class="div-table-responsive-no-min">';
        print '<table id="tablelines" class="noborder noshadow centpercent">';

        print '<tr class="liste_titre nodrag nodrop">';
        print '<td class="linecolnum center" style="width:30px">#</td>';
        print '<td class="linecoldescription">' . $langs->trans("Description") . '</td>';
        print '<td class="linecolref">' . $langs->trans("Ref") . ' / GTIN</td>';
        print '<td class="linecolqty right">' . $langs->trans("Qty") . '</td>';
        print '<td class="linecolunit">' . $langs->trans("Unit") . '</td>';
        print '<td class="linecoluht right">' . $langs->trans("PriceUHT") . '</td>';
        $hasDiscount = ksefLinesHaveDiscount($lines);
        if ($hasDiscount) {
            print '<td class="linecoldiscount right">' . $langs->trans("KSEF_LineDiscountColumn") . '</td>';
        }
        print '<td class="linecolvat right">' . $langs->trans("VAT") . '</td>';
        print '<td class="linecolht right">' . $langs->trans("TotalHT") . '</td>';
        print '<td class="linecolproduct" style="min-width:250px">' . $langs->trans("KSEF_ProductColumn") . '</td>';
        print '</tr>';

        foreach ($lines as $line) {
            $lineNum = $line['line_num'] ?? 0;
            $autoProductId = isset($autoMatches[$lineNum]) ? $autoMatches[$lineNum]['product_id'] : 0;
            $vatDisplay = dol_escape_htmltag(ksefImportVatLabel($line['vat_rate'] ?? ''));
            $refDisplay = '';
            if (!empty($line['indeks'])) {
                $refDisplay = dol_escape_htmltag($line['indeks']);
            }
            if (!empty($line['gtin'])) {
                $refDisplay .= ($refDisplay ? '<br>' : '') . '<span class="opacitymedium">' . dol_escape_htmltag($line['gtin']) . '</span>';
            }

            print '<tr class="oddeven' . (!empty($line['deposit_settlement']) ? ' ksef-import-deduction' : '') . '">';
            print '<td class="linecolnum center">' . $lineNum . '</td>';
            print '<td class="linecoldescription">' . (isset($line['description_html']) ? $line['description_html'] : ksefHtmlMultiline($line['description'] ?? '')) . '</td>';
            print '<td class="linecolref">' . ($refDisplay ?: '&ndash;') . '</td>';
            print '<td class="linecolqty right nowraponall">' . price($line['quantity'] ?? 0, 0, '', 1, 0, -1) . '</td>';
            print '<td class="linecolunit">' . dol_escape_htmltag($line['unit'] ?? '') . '</td>';
            print '<td class="linecoluht right nowraponall">' . price($line['unit_price_net'] ?? 0, 0, '', 1, -1, -1) . '</td>';
            if ($hasDiscount) {
                print '<td class="linecoldiscount right nowraponall">' . ksefLineDiscountCell($line) . '</td>';
            }
            print '<td class="linecolvat right nowraponall">' . $vatDisplay . '</td>';
            print '<td class="linecolht right nowraponall">' . price($line['net_amount'] ?? 0, 0, '', 1, -1, 2) . '</td>';

            print '<td class="linecolproduct">';
            $prodHtmlName = 'line_product_' . $lineNum;
            if (!KsefIncoming::isProductLine($line)) {
                print '<span class="opacitymedium">-</span><input type="hidden" name="' . $prodHtmlName . '" value="0">';
                print '</td>';
                print '</tr>';
                continue;
            }
            print $form->select_produits(
                $autoProductId,
                $prodHtmlName, // brackets break AJAX
                '',
                0,
                0,
                $outgoing ? 1 : -1, // any supplier product
                2,
                '',
                0,
                array(),
                0,
                '1',
                0,
                'maxwidth250',
                0,
                '',
                null,
                0,
                $outgoing ? -1 : 1
            );
            $pForm = 'ksef_create_product_form_' . ((int) $lineNum);
            print ' ';
            if (!$canCreateProduct) {
                print dolGetButtonTitle($langs->trans('KSEF_CreateProduct'), '', 'fa fa-plus-circle', '', '', 0, array('attr' => array('title' => ksefMissingRightText('create_product'))));
                print '</td>';
                print '</tr>';
                continue;
            }
            print '<input type="hidden" form="' . $pForm . '" name="token" value="' . newToken() . '">';
            print '<input type="hidden" form="' . $pForm . '" name="action" value="create">';
            $creatableTypes = ksefCreatableProductTypes($user);
            if (count($creatableTypes) > 1) {
                print '<select form="' . $pForm . '" name="type" class="flat maxwidth100 valignmiddle" title="' . dol_escape_htmltag($langs->trans('KSEF_CreateAsType')) . '">';
                print '<option value="0">' . $langs->trans('Product') . '</option><option value="1">' . $langs->trans('Service') . '</option></select> ';
            } else {
                        print '<input type="hidden" form="' . $pForm . '" name="type" value="' . (int) ($creatableTypes ? $creatableTypes[0] : 0) . '">';
            }
            print '<input type="hidden" form="' . $pForm . '" name="backtopage" value="' . dol_escape_htmltag($backtopageRaw . '&ksef_new_product=__ID__&ksef_new_line=' . ((int) $lineNum)) . '">';
            if (!empty($line['indeks'])) {
                print '<input type="hidden" form="' . $pForm . '" name="ref" value="' . dol_escape_htmltag($line['indeks']) . '">';
            }
            if (!empty($line['description'])) {
                print '<input type="hidden" form="' . $pForm . '" name="label" value="' . dol_escape_htmltag(ksefSingleLine($line['description'])) . '">';
            }
            if ($outgoing && !empty($line['unit_price_net'])) {
                print '<input type="hidden" form="' . $pForm . '" name="price" value="' . dol_escape_htmltag($line['unit_price_net']) . '">';
            }
            if (isset($line['vat_rate']) && is_numeric($line['vat_rate'])) {
                print '<input type="hidden" form="' . $pForm . '" name="tva_tx" value="' . dol_escape_htmltag($line['vat_rate']) . '">';
            }
            if (!empty($line['gtin'])) {
                print '<input type="hidden" form="' . $pForm . '" name="barcode" value="' . dol_escape_htmltag($line['gtin']) . '">';
            }
            print '<button type="submit" form="' . $pForm . '" class="btnTitle btnTitlePlus" title="' . dol_escape_htmltag($langs->trans("KSEF_CreateProduct")) . '">';
            print '<span class="fa fa-plus-circle valignmiddle paddingleft"></span>';
            print '</button>';
            print '</td>';

            print '</tr>';
        }

        print '<tr class="liste_total">';
        print '<td colspan="' . ($hasDiscount ? 8 : 7) . '" class="right">' . $langs->trans("Total") . '</td>';
        print '<td class="right nowraponall">' . price($object->total_net, 0, $langs, 0, -1, -1, $object->currency) . '</td>';
        print '<td></td>';
        print '</tr>';

        print '</table>';
        print '</div>';
    }
}

/**
 * @brief Totals after korekta
 * @param KsefIncoming|KsefSales $object
 * @param Facture|FactureFournisseur|string $source
 * @return string
 */
function ksefImportKorektaAfterText($object, $source)
{
    global $langs, $conf;
    if (!is_object($source) || empty($source->id)) {
        return '';
    }
    $cur = (string) $object->currency;
    $foreign = $cur !== '' && $cur !== $conf->currency && (string) ($source->multicurrency_code ?? '') === $cur;
    $net = (float) ($foreign ? $source->multicurrency_total_ht : $source->total_ht) + (float) $object->total_net;
    $gross = (float) ($foreign ? $source->multicurrency_total_ttc : $source->total_ttc) + (float) $object->total_gross;
    return $langs->transnoentitiesnoconv('KSEF_ImportKorektaAfter', price($net, 0, $langs, 1, -1, -1, $cur), price($gross, 0, $langs, 1, -1, -1, $cur));
}

/**
 * @brief Import confirm dialog
 * @param KsefIncoming $object
 * @param array $lines
 * @param array $cfg
 * @return void
 */
function ksefPrintImportConfirmScript($object, $lines, $cfg)
{
    global $langs;
    $outgoing = (($cfg['direction'] ?? 'incoming') === 'outgoing');
    $L = ksefImportPartyLangs($outgoing ? 'outgoing' : 'incoming');

    print '<div id="import-confirm-modal" style="display:none;" title="' . dol_escape_htmltag($langs->trans("KSEF_ImportConfirmTitle")) . '">';
    print '<div id="import-confirm-content"></div>';
    if (!empty($cfg['warehouse_html'])) {
        print '<div id="import-confirm-warehouse" style="margin-top: 8px;">' . $langs->trans('SelectWarehouseForStockIncrease') . ' ' . $cfg['warehouse_html'] . '</div>';
    }
    if (!empty($cfg['stock_preview_url'])) {
        print '<div id="import-confirm-stock" class="ksef-stock-preview" style="margin-top: 8px;"></div>';
    }
    print '</div>';

    $jsLineData = array();
    foreach ($lines as $line) {
        $lineNum = (int) ($line['line_num'] ?? 0);
        $deduction = !empty($line['deposit_settlement']) && isset($line['description_html']);
        $jsLineData[] = array_merge(array(
            'num' => $deduction ? '' : $lineNum,
            'desc' => $deduction ? trim(html_entity_decode(strip_tags($line['description_html']), ENT_QUOTES, 'UTF-8')) : ($line['description'] ?? ''),
            'indeks' => $line['indeks'] ?? '',
            'gtin' => $line['gtin'] ?? '',
            'field' => $deduction ? '' : 'line_product_' . $lineNum,
            'deduction' => $deduction ? 1 : 0,
        ), ksefImportLineDisplay($line));
    }

    print '<script type="text/javascript">
    $(document).ready(function() {
        var lineData = ' . json_encode($jsLineData) . ';
        var txtFreeText = ' . ksefJsLang("KSEF_ImportAsFreeText") . ';
        var txtLinkedTo = ' . ksefJsLang("KSEF_ImportLinkedTo") . ';
        var txtConfirmTitle = ' . ksefJsLang("KSEF_ImportConfirmTitle") . ';
        var txtConfirmImport = ' . ksefJsLang("KSEF_Import") . ';
        var txtCancel = ' . ksefJsLang("Cancel") . ';
        var txtSupplier = ' . ksefJsLang($outgoing ? 'Customer' : 'KSEF_ImportConfirmSupplier') . ';
        var txtDescription = ' . ksefJsLang("Description") . ';
        var txtRef = ' . ksefJsLang("Ref") . ';
        var txtQty = ' . ksefJsLang("Qty") . ';
        var txtUnitPrice = ' . ksefJsLang("PriceUHT") . ';
        var txtVAT = ' . ksefJsLang("VAT") . ';
        var txtTotalHT = ' . ksefJsLang("TotalHT") . ';
        var txtTotalTTC = ' . ksefJsLang("AmountTTC") . ';
        var txtDeduction = ' . ksefJsLang("KSEF_ImportDeductionBadge") . ';
        var txtProduct = ' . ksefJsLang("Product") . ';
        var txtIdMismatch = ' . ksefJsLang("KSEF_ImportConfirmIdMismatch") . ';
        var txtIdMismatchValue = ' . ksefJsLang("KSEF_ImportConfirmIdMismatchValue") . ';
        var invoiceNumber = ' . json_encode($object->invoice_number) . ';
        var partyName = ' . json_encode((string) ($cfg['party_name'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ';
        var invoiceDate = ' . json_encode(dol_print_date($object->invoice_date, 'day')) . ';
        var invoiceType = ' . json_encode($object->invoice_type) . ';
        var totalGross = ' . json_encode(price($object->total_gross, 0, $langs, 1, -1, -1, $object->currency)) . ';
        var stockModuleEnabled = ' . ((string) $cfg['stock_short'] !== '' ? 'true' : 'false') . ';
        var stockShortText = ' . json_encode((string) $cfg['stock_short'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ';
        var isMultiCorrection = ' . (!empty($cfg['multi_correction']) ? 'true' : 'false') . ';
        var isKorekta = ' . (!empty($cfg['korekta']) ? 'true' : 'false') . ';
        var korektaAfter = ' . json_encode((string) ($cfg['korekta_after'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ';
        var korTypeBadges = ' . json_encode(array('KOR' => ksefGetInvoiceTypeBadge('KOR'), 'KOR_ZAL' => ksefGetInvoiceTypeBadge('KOR_ZAL'), 'KOR_ROZ' => ksefGetInvoiceTypeBadge('KOR_ROZ')), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ';
        var txtCorrectionLinkTo = ' . ksefJsLang("KSEF_CorrectionLinkTo") . ';
        var txtUpwardKorekta = ' . ksefJsLang("KSEF_UpwardCorrectionKorektaShort") . ';
        var txtWillCreate = ' . ksefJsLang($outgoing ? 'KSEF_BatchCustomerWillCreate' : 'KSEF_BatchSupplierWillCreate') . ';

        var txtNoRightParty = ' . ksefJsLang($outgoing ? 'KSEF_SalesNoRightCreateCustomer' : 'KSEF_BatchNoRightCreateSupplier') . ';
        var txtNoParty = ' . ksefJsLang($outgoing ? 'KSEF_SalesImportNoCustomer' : 'KSEF_ImportErrorNoSupplier') . ';
        var gusPreviewUrl = ' . json_encode((string) ($cfg['gus_preview_url'] ?? '')) . ';
        var recordId = ' . (int) $object->id . ';
        var txtGusIntro = ' . ksefJsLang($L['gus_intro']) . ';
        var txtGusKeep = ' . json_encode($langs->transnoentitiesnoconv('KSEF_GusOverrideKeep', $langs->transnoentitiesnoconv($L['gus'])), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ';
        var txtGusFailed = ' . ksefJsLang($L['gus_failed']) . ';
        var gusResult = null;

        function syncStockHint() {
            var hint = $("#ksef_stock_hint");
            var wh = $("#import_form select[name=warehouse_id]");
            if (hint.attr("data-none") !== undefined && wh.length) {
                hint.text(parseInt(wh.val(), 10) > 0 ? hint.attr("data-chosen") : hint.attr("data-none"));
            }
        }
        $("#import_form select[name=warehouse_id]").on("change", syncStockHint);
        syncStockHint();

        var stockPreviewUrl = ' . json_encode((string) ($cfg['stock_preview_url'] ?? '')) . ';
        var stockPreviewToken = ' . json_encode(currentToken()) . ';
        var stockPreviewTimer = null;
        function stockWarehouse() {
            var wh = $("#import_form select[name=warehouse_id]");
            if (!wh.length) {
                wh = $("#import-confirm-warehouse select[name=warehouse_id]");
            }
            return wh.length ? (parseInt(wh.val(), 10) || 0) : 0;
        }
        function refreshStockPreview() {
            if (!stockPreviewUrl) {
                return;
            }
            var data = { token: stockPreviewToken, warehouse_id: stockWarehouse(), socid: getSocid(),
                correction_source_id: $("input[name=correction_source_id]:checked").val() || 0 };
            $.each(lineData, function(i, line) {
                if (line.field) {
                    data[line.field] = getProductId(line.field);
                }
            });
            $.post(stockPreviewUrl, data, function(resp) {
                var html = resp && resp.html ? resp.html : "";
                if (html && resp.moves > 0) {
                    html = \'<div class="paddingbottom"><span class="fa fa-cubes paddingright opacitymedium"></span><strong>\' + escapeHtml(txtStockPreview) + \'</strong></div>\' + html;
                }
                $("#ksef_stock_preview, #import-confirm-stock").html(html).attr("data-moves", resp ? resp.moves : 0).attr("data-state", resp ? resp.state : "");
            }, "json");
        }
        function queueStockPreview() {
            clearTimeout(stockPreviewTimer);
            stockPreviewTimer = setTimeout(refreshStockPreview, 150);
        }
        var txtStockPreview = ' . ksefJsLang('KSEF_StockPreviewTitle') . ';
        $(document).on("change", "select[name=warehouse_id], [name^=line_product_], input[name=correction_source_id], #socid_hidden, select[name=socid]", queueStockPreview);
        queueStockPreview();

        // created if ticked
        function willCreateParty() {
            var box = $("#ksef_create_parties");
            return box.length > 0 && box.is(":checked") && !box.prop("disabled");
        }

        // nothing to create
        function noPartyText() {
            var box = $("#ksef_create_parties");
            return (box.length > 0 && box.prop("disabled")) ? txtNoRightParty : txtNoParty;
        }

        // GUS-created party
        function wantsGus() {
            var g = $("#ksef_gus_fetch");
            return gusPreviewUrl !== "" && getSocid() <= 0 && willCreateParty() && g.length > 0 && g.is(":checked") && !g.prop("disabled");
        }

        function gusHtml() {
            if (!gusResult) {
                return "";
            }
            var h = \'<div id="import-confirm-gus" style="margin-bottom: 10px;">\';
            if (gusResult.overrides && gusResult.overrides.length > 0) {
                h += "<p>" + escapeHtml(txtGusIntro) + "</p>";
                $.each(gusResult.overrides, function(i, o) {
                    h += "<div><strong>" + escapeHtml(o.name) + "</strong> (NIP " + escapeHtml(o.nip) + ")<ul>";
                    $.each(o.changes, function(j, c) {
                        h += "<li>" + escapeHtml(c.label) + ": <span class=\"opacitymedium\">" + escapeHtml(c.from) + "</span> &rarr; " + escapeHtml(c.to) + "</li>";
                    });
                    h += "</ul></div>";
                });
                h += \'<p class="opacitymedium ksef-gus-keep">\' + escapeHtml(txtGusKeep) + "</p>";
            } else if (gusResult.failed) {
                h += \'<p class="warning">\' + escapeHtml(txtGusFailed) + "</p>";
            }
            return h + "</div>";
        }


        $("select[name=socid_select], input[name=socid_select]").on("change", function() {
            var val = $(this).val();
            if (val > 0) {
                if ($("#socid_hidden").length) {
                    $("#socid_hidden").val(val);
                }
            }
        });

        function getSocid() {
            var selectVal = 0;
            if ($("select[name=socid_select]").length) {
                selectVal = parseInt($("select[name=socid_select]").val()) || 0;
            } else if ($("input[name=socid_select]").length) {
                selectVal = parseInt($("input[name=socid_select]").val()) || 0;
            }
            if (selectVal > 0) {
                return selectVal;
            }
            if ($("#socid_hidden").length && $("#socid_hidden").val() > 0) {
                return parseInt($("#socid_hidden").val());
            }
            return 0;
        }

        function getSupplierName() {
            var sel = $("select[name=socid_select]");
            if (sel.length && parseInt(sel.val()) > 0) {
                return sel.find("option:selected").text().trim();
            }
            var searchField = $("input#search_socid_select");
            if (searchField.length && searchField.val()) {
                return searchField.val().trim();
            }
            var supplierStrong = $("td:contains(\'' . addslashes($langs->trans($outgoing ? 'Customer' : 'Supplier')) . '\')").next().find("strong").first();
            if (supplierStrong.length) {
                return supplierStrong.text().trim();
            }
            return "";
        }

        function getProductLabel(fieldName) {
            var sel = $("select[name=" + fieldName + "]");
            if (sel.length && sel.val() > 0) {
                // carries price markup
                return sel.find("option:selected").text().replace(/<[^>]*>/g, "").replace(/\s+/g, " ").trim();
            }
            var hidden = $("input[name=" + fieldName + "]");
            if (hidden.length && parseInt(hidden.val()) > 0) {
                var searchField = $("#search_" + fieldName);
                if (searchField.length && searchField.val()) {
                    return searchField.val().trim();
                }
                return "#" + hidden.val();
            }
            return "";
        }

        function getProductId(fieldName) {
            var sel = $("select[name=" + fieldName + "]");
            if (sel.length) {
                return parseInt(sel.val()) || 0;
            }
            var hidden = $("input[name=" + fieldName + "]");
            if (hidden.length) {
                return parseInt(hidden.val()) || 0;
            }
            return 0;
        }

        function escapeHtml(str) {
            if (!str) return "";
            var div = document.createElement("div");
            div.appendChild(document.createTextNode(str));
            return div.innerHTML.replace(/"/g, "&quot;").replace(/\'/g, "&#39;");
        }

        function buildConfirmDialog() {
            var supplierName = getSupplierName();
            if (getSocid() <= 0 && willCreateParty()) {
                var gusName = (gusResult && gusResult.overrides && gusResult.overrides.length) ? (gusResult.overrides[0].new_name || "") : "";
                supplierName = (gusName || partyName || supplierName) + " (" + txtWillCreate + ")";
            }
            var typeHtml = korTypeBadges[invoiceType] ? " " + korTypeBadges[invoiceType] : "";

            var html = "";

            html += \'<table class="border tableforfield centpercent" style="margin-bottom:10px;">\';
            html += \'<tr><td class="titlefield">\' + escapeHtml(txtSupplier) + \'</td>\';
            html += \'<td><strong>\' + escapeHtml(supplierName) + \'</strong></td></tr>\';
            var idHidden = $("#socid_hidden");
            if (idHidden.length && idHidden.data("ksef-idstate") === "mismatch" && parseInt(idHidden.val(), 10) === getSocid()) {
                var idText = txtIdMismatchValue.replace("%s", String(idHidden.data("ksef-invoice-id"))).replace("%s", String(idHidden.data("ksef-party-id")));
                html += \'<tr id="import-confirm-idmismatch"><td class="titlefield">\' + escapeHtml(txtIdMismatch) + \'</td>\';
                html += \'<td><span class="fa fa-exclamation-triangle warning"></span> \' + escapeHtml(idText) + \'</td></tr>\';
            }
            html += \'</table>\';
            html += gusHtml();

            html += \'<div class="div-table-responsive">\';
            html += \'<table class="noborder centpercent" style="background:#f8f8f8;">\';
            html += \'<tr class="liste_titre">\';
            html += \'<th>#</th>\';
            html += \'<th>\' + txtDescription + \'</th>\';
            html += \'<th>\' + txtRef + \'</th>\';
            html += \'<th class="right">\' + txtQty + \'</th>\';
            html += \'<th class="right">\' + txtUnitPrice + \'</th>\';
            html += \'<th class="right">\' + txtVAT + \'</th>\';
            html += \'<th class="right">\' + txtTotalHT + \'</th>\';
            html += \'<th>\' + txtProduct + \'</th>\';
            html += \'</tr>\';

            var anyProduct = false;
            for (var i = 0; i < lineData.length; i++) {
                var line = lineData[i];
                var productId = line.field ? getProductId(line.field) : 0;
                anyProduct = anyProduct || productId > 0;
                var vatDisplay = escapeHtml(line.vat_label);
                var refDisplay = escapeHtml(line.indeks);
                if (line.gtin) {
                    refDisplay += (refDisplay ? "<br>" : "") + \'<span class="opacitymedium">\' + escapeHtml(line.gtin) + \'</span>\';
                }

                html += \'<tr class="oddeven">\';
                html += \'<td>\' + escapeHtml(String(line.num)) + \'</td>\';
                html += \'<td>\' + escapeHtml(line.desc) + \'</td>\';
                html += \'<td>\' + (refDisplay || "&ndash;") + \'</td>\';
                html += \'<td class="right nowraponall">\' + escapeHtml(line.qty) + \'</td>\';
                html += \'<td class="right nowraponall">\' + escapeHtml(line.unit_price_net) + \'</td>\';
                html += \'<td class="right nowraponall">\' + vatDisplay + \'</td>\';
                html += \'<td class="right nowraponall">\' + escapeHtml(line.net_amount) + \'</td>\';

                if (line.deduction) {
                    html += \'<td><span class="badge badge-status1 ksef-tone-info">\' + escapeHtml(txtDeduction) + \'</span></td>\';
                } else if (productId > 0) {
                    var productLabel = getProductLabel(line.field);
                    html += \'<td><span class="badge badge-status4 ksef-tone-ok">\' + txtLinkedTo + \'</span> \' + escapeHtml(productLabel) + \'</td>\';
                } else {
                    html += \'<td><span class="badge badge-status1 ksef-tone-info">\' + txtFreeText + \'</span></td>\';
                }
                html += \'</tr>\';
            }

            html += \'</table></div>\';
            html += \'<div class="right ksef-import-confirm-total" style="margin-top: 6px;">\' + escapeHtml(txtTotalTTC) + \': <strong>\' + escapeHtml(totalGross) + \'</strong></div>\';
            if (korektaAfter) {
                html += \'<div class="right opacitymedium ksef-import-confirm-after">\' + escapeHtml(korektaAfter) + \'</div>\';
            }

            var strategyParts = [];
            if (isMultiCorrection && $("input[name=correction_source_id]:checked").length) {
                strategyParts.push($("input[name=correction_source_id]:checked").parent().text().trim());
            }
            if (isKorekta) {
                strategyParts.push(txtUpwardKorekta);
            }
            if (strategyParts.length > 0) {
                html += \'<div style="margin-top: 8px; padding: 6px; background: #fffbe6; border: 1px solid #ffe58f; border-radius: 3px;">\';
                html += \'<span class="fa fa-info-circle" style="margin-right: 5px;"></span>\';
                html += \'<strong>\' + escapeHtml(strategyParts.join(" · ")) + \'</strong>\';
                html += \'</div>\';
            }

            $("#import-confirm-stock").toggle(anyProduct);
            if (stockModuleEnabled && anyProduct) {
                html += \'<div class="opacitymedium" style="margin-top: 8px;"><span class="fa fa-info-circle" style="margin-right: 5px;"></span>\' + escapeHtml($("#ksef_stock_hint").attr("data-none") !== undefined ? $("#ksef_stock_hint").text() : stockShortText) + \'</div>\';
            }

            return html;
        }

        function submitForm(actionName) {
            var socid = getSocid();
            if (socid <= 0 && !willCreateParty()) {
                alert(noPartyText());
                return false;
            }
            // no second GUS
            $("#ksef_gus_previewed").val(gusResult ? "1" : "0");
            if ($("#socid_post").length == 0) {
                $("#import_form").append(\'<input type="hidden" name="socid" id="socid_post" value="0">\');
            }
            $("#socid_post").val(socid);
            var $wh = $("#import-confirm-warehouse select[name=warehouse_id]");
            if ($wh.length) {
                if ($("#warehouse_post").length == 0) {
                    $("#import_form").append(\'<input type="hidden" name="warehouse_id" id="warehouse_post" value="0">\');
                }
                $("#warehouse_post").val(parseInt($wh.val(), 10) > 0 ? parseInt($wh.val(), 10) : 0);
            }
            $("input[name=action]").val(actionName);
            $("#import_form").submit();
        }

        $("#btn_import").on("click", function(e) {
            e.preventDefault();
            if ($(this).hasClass("butActionRefused")) {
                return false;
            }
            var socid = getSocid();
            if (socid <= 0 && !willCreateParty()) {
                alert(noPartyText());
                return false;
            }
            gusResult = null;
            if (wantsGus()) {
                var $btn = $(this);
                $btn.addClass("butActionRefused");
                $.ajax({
                    url: gusPreviewUrl,
                    method: "POST",
                    contentType: "application/json",
                    data: JSON.stringify({ ids: [recordId] }),
                    dataType: "json",
                    success: function(resp) {
                        var ok = resp && resp.status === "OK";
                        gusResult = { overrides: ok && resp.overrides ? resp.overrides : [], failed: !ok || (parseInt(resp.failed_count, 10) || 0) > 0 };
                    },
                    error: function() {
                        gusResult = { overrides: [], failed: true };
                    },
                    complete: function() {
                        $btn.removeClass("butActionRefused");
                        openConfirmDialog();
                    }
                });
                return false;
            }
            openConfirmDialog();
        });

        function openConfirmDialog() {
            var html = buildConfirmDialog();
            $("#import-confirm-content").html(html);

            $("#import-confirm-modal").dialog({
                modal: true,
                width: Math.min(900, $(window).width() - 40),
                maxHeight: $(window).height() - 100,
                closeOnEscape: true,
                buttons: [
                    {
                        text: txtConfirmImport,
                        class: "butAction",
                        click: function() {
                            $(this).dialog("close");
                            submitForm("import");
                        }
                    },
                    {
                        text: txtCancel,
                        click: function() {
                            $(this).dialog("close");
                        }
                    }
                ]
            });
        }

    });
    </script>';
}

/**
 * @brief Buyer side HTML
 * @param array $side
 * @return string
 */
function ksefBuyerSideHtml($side)
{
    $html = dol_escape_htmltag($side['name']);
    if ($side['address'] !== '') {
        $html .= '<br>' . ksefHtmlMultiline($side['address']) . ($side['country'] !== '' ? ', ' . dol_escape_htmltag($side['country']) : '');
    }
    return $html;
}

/**
 * @brief Buyer differs note
 * @param string $what name, address or both
 * @return string
 */
function ksefBuyerChangedNote($what = 'both')
{
    global $langs;
    $keys = array('name' => 'KSEF_BuyerNameChangedSinceSent', 'address' => 'KSEF_BuyerAddressChangedSinceSent');
    $key = isset($keys[$what]) ? $keys[$what] : 'KSEF_BuyerChangedSinceSent';
    return '<span class="ksef-buyer-changed opacitymedium classfortooltip" title="' . dol_escape_htmltag($langs->trans('KSEF_BuyerChangedSinceSentHelp')) . '">'
        . '<span class="fa fa-info-circle"></span> ' . $langs->trans($key) . '</span>';
}

/**
 * @brief Card VAT summary
 * @param KsefIncoming $object
 * @return void
 */
function ksefPrintCardVatSummary($object)
{
    global $langs;

    $vatSummary = $object->getVatSummary();
    if (!empty($vatSummary)) {
        print '<div class="div-table-responsive-no-min">';
        print '<table class="noborder paymenttable centpercent">';

        print '<tr class="liste_titre">';
        print '<td class="liste_titre">' . $langs->trans("VATRate") . '</td>';
        print '<td class="right"><span class="hideonsmartphone">' . $langs->trans("KSEF_Net") . '</span></td>';
        print '<td class="right">' . $langs->trans("VATAmount") . '</td>';
        print '</tr>';

        $vatRateLabels = array(
            '23' => '23%', '22' => '22%', '8' => '8%', '7' => '7%', '5' => '5%',
            '4' => '4%', '3' => '3%', 'oss' => 'OSS', '0' => '0%',
            '0 KR' => '0% (' . $langs->trans("KSEF_VATDomestic") . ')',
            '0 WDT' => '0% (WDT)',
            '0 EX' => '0% (' . $langs->trans("KSEF_VATExport") . ')',
            'zw' => $langs->trans("KSEF_VATExempt"),
            'np' => $langs->trans("KSEF_VATNotSubject"),
            'np I' => $langs->trans("KSEF_VATNotSubject") . ' (I)',
            'np II' => $langs->trans("KSEF_VATNotSubject") . ' (II)',
            'oo' => $langs->trans("KSEF_VATReverseCharge"),
            'marza' => $langs->trans("KSEF_VATMarginScheme"),
        );
        foreach ($vatSummary as $rate => $amounts) {
            print '<tr class="oddeven">';
            $rateLabel = isset($vatRateLabels[$rate]) ? $vatRateLabels[$rate] : dol_escape_htmltag($rate);
            $hasVat = !empty($amounts['vat']) && $amounts['vat'] != 0;
            print '<td>' . $rateLabel . '</td>';
            print '<td class="right">' . price($amounts['net'], 0, $langs, 1, -1, -1, $object->currency) . '</td>';
            print '<td class="right">' . ($hasVat ? price($amounts['vat'], 0, $langs, 1, -1, -1, $object->currency) : '-') . '</td>';
            print '</tr>';
        }

        $sumNet = 0;
        foreach ($vatSummary as $amounts) {
            $sumNet += (float) ($amounts['net'] ?? 0);
        }
        print '<tr class="liste_total">';
        print '<td class="liste_total">' . $langs->trans("Total") . '</td>';
        print '<td class="liste_total right">' . price($object->total_net !== null && $object->total_net !== '' ? $object->total_net : $sumNet, 0, $langs, 1, -1, -1, $object->currency) . '</td>';
        print '<td class="liste_total right">' . price($object->total_vat, 0, $langs, 1, -1, -1, $object->currency) . '</td>';
        print '</tr>';

        print '</table>';
        print '</div>';
    }
}

/**
 * @brief Card payment rows
 * @param KsefIncoming $object
 * @return void
 */
function ksefPrintCardPaymentRows($object)
{
    global $langs;

    if (!empty($object->payment_status) && $object->payment_status !== 'unpaid') {
        $notSpecified = '<span class="opacitymedium">' . $langs->trans("KSEF_NotSpecifiedInXml") . '</span>';

        print '<tr><td>' . $langs->trans("KSEF_PaymentStatus") . '</td><td>';
        if ($object->payment_status === 'paid') {
            print '<span class="badge ' . ksefToneBadgeClass('ok') . ' badge-status">' . $langs->trans("KSEF_SellerMarkedPaid") . '</span>';
        } elseif ($object->payment_status === 'paid_installments') {
            print '<span class="badge ' . ksefToneBadgeClass('ok') . ' badge-status">' . $langs->trans("KSEF_SellerMarkedPaidInstallments") . '</span>';
        } elseif ($object->payment_status === 'partial') {
            print '<span class="badge ' . ksefToneBadgeClass('action') . ' badge-status">' . $langs->trans("KSEF_SellerMarkedPartial") . '</span>';
        }
        print '</td></tr>';

        print '<tr><td>' . $langs->trans("KSEF_PaymentDate") . '</td><td>';
        print $object->payment_date ? dol_print_date($object->payment_date, 'day') : $notSpecified;
        print '</td></tr>';

        print '<tr><td>' . $langs->trans("KSEF_PaymentMethod") . '</td><td>';
        $methodLabel = ksefGetPaymentMethodLabel($object->payment_method);
        print $methodLabel ? dol_escape_htmltag($methodLabel) : $notSpecified;
        print '</td></tr>';
    }
}

/**
 * @brief Card line items
 * @param KsefIncoming $object
 * @return void
 */
function ksefPrintCardLines($object)
{
    global $langs;

    $lines = $object->getLineItems();
    if (!empty($lines)) {
        $hasGrossPrice = false;
        $hasGrossAmount = false;
        foreach ($lines as $line) {
            if (!empty($line['unit_price_gross'])) $hasGrossPrice = true;
            if (!empty($line['gross_amount'])) $hasGrossAmount = true;
        }

        print '<div class="div-table-responsive-no-min">';
        print '<table id="tablelines" class="noborder noshadow centpercent">';

        print '<tr class="liste_titre nodrag nodrop">';
        print '<td class="linecoldescription">' . $langs->trans("Description") . '</td>';
        print '<td class="linerefsupplier maxwidth100">' . $langs->trans("KSEF_LineIndex") . '</td>';
        print '<td class="linecolvat right nowraponall">' . $langs->trans("VAT") . '</td>';
        print '<td class="linecoluht right">' . $langs->trans("PriceUHT") . '</td>';
        print '<td class="linecoluttc right">' . $langs->trans("PriceUTTC") . '</td>';
        print '<td class="linecolqty right">' . $langs->trans("Qty") . '</td>';
        $hasDiscount = ksefLinesHaveDiscount($lines);
        if ($hasDiscount) {
            print '<td class="linecoldiscount right">' . $langs->trans("KSEF_LineDiscountColumn") . '</td>';
        }
        print '<td class="linecolht right">' . $langs->trans("TotalHT") . '</td>';
        if ($hasGrossAmount) {
            print '<td class="linecolttc right">' . $langs->trans("TotalTTC") . '</td>';
        }
        print '</tr>';

        foreach ($lines as $line) {
            $vatRate = is_numeric($line['vat_rate']) ? (float)$line['vat_rate'] : 0;

            if (!empty($line['unit_price_gross'])) {
                $unitPriceTTC = (float)$line['unit_price_gross'];
            } else {
                $unitPriceTTC = $line['unit_price_net'] * (1 + $vatRate / 100);
            }

            print '<tr class="oddeven">';
            print '<td class="linecoldescription minwidth200imp ksef-wrap">' . ksefHtmlMultiline($line['description']) . '</td>';
            print '<td class="linecolrefsupplier tdoverflowmax100">' . dol_escape_htmltag((string) (!empty($line['indeks']) ? $line['indeks'] : ($line['supplier_ref'] ?? ''))) . '</td>';
            print '<td class="linecolvat nowrap right">' . dol_escape_htmltag(ksefImportVatLabel($line['vat_rate'] ?? '')) . '</td>';
            print '<td class="linecoluht nowraponall right">' . price($line['unit_price_net'], 0, '', 1, -1, -1) . '</td>';
            print '<td class="linecoluttc nowraponall right">' . price($unitPriceTTC, 0, '', 1, -1, 4) . '</td>';
            print '<td class="linecolqty nowraponall right">' . price($line['quantity'], 0, '', 1, 0, -1) . '</td>';
            if ($hasDiscount) {
                print '<td class="linecoldiscount nowraponall right">' . ksefLineDiscountCell($line) . '</td>';
            }
            print '<td class="linecolht nowrap right">' . price($line['net_amount'], 0, '', 1, -1, 2) . '</td>';
            if ($hasGrossAmount) {
                print '<td class="linecolttc nowrap right">' . price(!empty($line['gross_amount']) ? $line['gross_amount'] : 0, 0, '', 1, -1, 2) . '</td>';
            }
            print '</tr>';
        }

        print '</table>';
        print '</div>';
    }
}

/**
 * @brief Settled deposits row
 * @param KsefIncoming $object
 * @param int $socid 0: any
 * @return void
 */
function ksefPrintIncomingDepositsRow($object, $socid)
{
    global $db, $langs, $conf;
    if (in_array($object->invoice_type, array('KOR_ZAL', 'KOR_ROZ'), true)) {
        $check = $object->depositDeductionCheck($socid);
        if ($check !== '') {
            print '<tr class="ksef-incoming-deposits"><td>' . $langs->trans('KSEF_IncomingStatedBefore') . '</td><td><span class="warning">' . dol_escape_htmltag($check) . '</span></td></tr>';
        }
        return;
    }
    if ($object->invoice_type !== 'ROZ') {
        return;
    }
    $deposits = $object->supplierDeposits($socid);
    if (!$deposits) {
        return;
    }
    require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
    print '<tr class="ksef-incoming-deposits"><td>' . $langs->trans('KSEF_SettledDeposits') . '</td><td>';
    foreach ($deposits as $d) {
        print dol_escape_htmltag($d['label']) . ' ';
        if ($d['id'] > 0) {
            $dep = new FactureFournisseur($db);
            if ($dep->fetch($d['id']) > 0) {
                print $dep->getNomUrl(1) . ' ';
            }
        }
        if ($d['reason'] === '') {
            print '<span class="badge ' . ksefToneBadgeClass('ok') . ' badge-status">' . $langs->trans('KSEF_IncomingDepositLinked') . '</span>';
            if ($d['part']) {
                $cur = $object->currency ?: $conf->currency;
                print ' <span class="opacitymedium small">' . dol_escape_htmltag($langs->transnoentitiesnoconv('KSEF_DepositLinkedPartShort',
                    price($d['part'][0], 0, $langs, 1, -1, -1, $cur), price($d['part'][1], 0, $langs, 1, -1, -1, $cur))) . '</span>';
            }
        } else {
            print '<span class="opacitymedium small">' . dol_escape_htmltag($langs->transnoentitiesnoconv('KSEF_DepositReason_' . $d['reason'])) . '</span>';
        }
        print '<br>';
    }
    $check = $object->depositDeductionCheck($socid);
    if ($check !== '') {
        print '<span class="warning">' . dol_escape_htmltag($check) . '</span>';
    }
    print '</td></tr>';
}

/**
 * @brief Missing original options
 * @param KsefIncoming $kor
 * @param string $ksefNumber
 * @param KsefIncoming|null $record
 * @param bool $canWrite
 * @return string '' when imported
 */
function ksefCitedOriginalOffer($kor, $ksefNumber, $record, $canWrite)
{
    global $langs;
    $page = dol_buildpath('/ksef/' . (($kor instanceof KsefSales) ? 'sales_import.php' : 'incoming_import.php'), 1);
    $warn = '<span class="warning ksef-original-missing"><span class="fa fa-exclamation-triangle"></span> ';
    if ($record) {
        if (($record instanceof KsefSales) ? !empty($record->fk_facture) : $record->fk_facture_fourn > 0) {
            return '';
        }
        return $warn . $langs->trans('KSEF_OriginalNotImported') . '</span> <a class="ksef-import-original" href="' . $page . '?id=' . (int) $record->id . '&for_kor=' . (int) $kor->id . '">'
            . $langs->trans('KSEF_ImportOriginalFirst') . '</a>';
    }
    if ((string) $ksefNumber === '') {
        return $warn . $langs->trans('KSEF_OriginalNeverInKsef') . '</span>';
    }
    if (!empty($_SESSION['ksef_original_absent'][$ksefNumber])) {
        return '<span class="warning ksef-original-missing ksef-original-absent"><span class="fa fa-exclamation-triangle"></span> '
            . $langs->trans('KSEF_OriginalAbsentInKsef', dol_escape_htmltag($ksefNumber)) . '</span>';
    }
    $out = $warn . $langs->trans('KSEF_OriginalNotInSystem') . '</span>';
    if ($canWrite) {
        $out .= ' <a class="ksef-download-original" href="' . $page . '?id=' . (int) $kor->id . '&action=download_original&ksef=' . urlencode($ksefNumber) . '&token=' . newToken() . '">'
            . $langs->trans('KSEF_DownloadOriginal') . '</a>';
    }
    return $out;
}

/**
 * @brief Fetch missing original
 * @param KsefIncoming $object
 * @param string $action
 * @param User $user
 * @param bool $canWrite
 * @return void
 */
function ksefCitedOriginalActions($object, $action, $user, $canWrite)
{
    global $db, $langs;
    if ($action !== 'download_original' || !$canWrite) {
        return;
    }
    dol_include_once('/ksef/class/ksef_service.class.php');
    $svc = new KsefService($db);
    $num = GETPOST('ksef', 'alphanohtml');
    $rid = $svc->downloadCitedOriginal($object, $num, $user);
    if ($rid <= 0 && $svc->error === $langs->transnoentitiesnoconv('KSEF_OriginalNotFoundInKsef', trim((string) $num))) {
        $_SESSION['ksef_original_absent'][trim((string) $num)] = 1;
    }
    if ($rid > 0) {
        setEventMessages($langs->trans('KSEF_OriginalDownloaded', dol_escape_htmltag(GETPOST('ksef', 'alphanohtml'))), null, 'mesgs');
        header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $rid . '&for_kor=' . (int) $object->id);
        exit;
    }
    setEventMessages(ksefEscapeText($svc->error), null, 'errors');
}

/**
 * @brief Correction citing original
 * @param KsefIncoming $object
 * @return KsefIncoming|null
 */
function ksefImportForKor($object)
{
    global $db;
    $id = GETPOSTINT('for_kor');
    if ($id <= 0) {
        return null;
    }
    $class = get_class($object);
    $kor = new $class($db);
    if ($kor->fetch($id) <= 0 || !KsefIncoming::isCorrectionType($kor->invoice_type)) {
        return null;
    }
    foreach ($kor->citedInvoices() as $c) {
        if (!empty($c['record']) && (int) $c['record']->id === (int) $object->id) {
            return $kor;
        }
    }
    return null;
}
