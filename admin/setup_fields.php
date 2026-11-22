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
 * \file    admin/setup_fields.php
 * \ingroup ksef
 * \brief   Invoice fields tab
 */

if (!defined('CSRFCHECK_WITH_TOKEN')) {
    define('CSRFCHECK_WITH_TOKEN', '1');
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
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
dol_include_once('/ksef/lib/ksef.lib.php');
dol_include_once('/ksef/lib/ksef_tour.lib.php');
dol_include_once('/core/class/extrafields.class.php');

if (!$user->admin) {
    accessforbidden();
}

$langs->loadLangs(array("admin", "bills", "ksef@ksef"));

$action = GETPOST('action', 'aZ09');

/**
 * @brief Offered field types
 * @return array type => lang key
 */
function ksefEfTypes()
{
    return array(
        'varchar' => 'KSEF_DODATKOWY_OPIS_TYPE_VARCHAR',
        'text' => 'KSEF_DODATKOWY_OPIS_TYPE_TEXT',
        'int' => 'KSEF_DODATKOWY_OPIS_TYPE_INT',
        'double' => 'KSEF_DODATKOWY_OPIS_TYPE_DOUBLE',
        'date' => 'KSEF_DODATKOWY_OPIS_TYPE_DATE',
        'datetime' => 'KSEF_DODATKOWY_OPIS_TYPE_DATETIME',
        'select' => 'KSEF_DODATKOWY_OPIS_TYPE_SELECT',
        'boolean' => 'KSEF_DODATKOWY_OPIS_TYPE_BOOLEAN',
    );
}

/**
 * @brief Field targets
 * @return array
 */
function ksefEfTargets()
{
    return array(
        'facture' => array('KSEF_EfWhere_facture', 'KSEF_DODATKOWY_OPIS_EXTRAFIELDS'),
        'facturedet' => array('KSEF_EfWhere_facturedet', 'KSEF_DODATKOWY_OPIS_DET_EXTRAFIELDS'),
        'societe' => array('KSEF_EfWhere_societe', 'KSEF_DODATKOWY_OPIS_SOCIETE_EXTRAFIELDS'),
        'product' => array('KSEF_EfWhere_product', 'KSEF_DODATKOWY_OPIS_PRODUCT_EXTRAFIELDS'),
        'projet' => array('KSEF_EfWhere_projet', 'KSEF_DODATKOWY_OPIS_PROJECT_EXTRAFIELDS'),
    );
}

/**
 * @brief Code from label
 * @param string $label
 * @return string
 */
function ksefFieldCodeFromLabel($label)
{
    $s = strtr((string) $label, array('ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N', 'Ó' => 'O', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z'));
    return trim(strtolower(preg_replace('/[^a-z0-9]+/i', '_', dol_string_unaccent($s))), '_');
}

/**
 * @brief Parse option lines
 * @param string $optionsRaw code|label lines
 * @return array
 */
function ksefParseExtrafieldOptions($optionsRaw)
{
    $options = array();
    foreach (preg_split('/\r\n|\r|\n/', $optionsRaw) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (strpos($line, '|') !== false) {
            list($optCode, $optLabel) = explode('|', $line, 2);
            $optCode = trim($optCode);
            $optLabel = trim($optLabel);
        } else {
            $optLabel = $line;
            $optCode = ksefFieldCodeFromLabel($optLabel);
        }
        if ($optCode !== '' && $optLabel !== '') {
            $options[$optCode] = $optLabel;
        }
    }
    return array('options' => $options);
}

/**
 * @brief Field stored size
 * @param string $type Field type
 * @param string $old Current size
 * @return string
 */
function ksefEfSize($type, $old)
{
    if ($type === 'varchar') {
        $n = GETPOSTISSET('ef_size') ? GETPOSTINT('ef_size') : 0;
        if ($n <= 0) {
            $n = (int) $old > 0 ? (int) $old : 255;
        }
        return (string) min(255, max(1, $n));
    }
    if ($type === 'double') {
        if (GETPOSTISSET('ef_decimals') && GETPOST('ef_decimals', 'alpha') !== '') {
            return '24,' . min(8, max(0, GETPOSTINT('ef_decimals')));
        }
        return preg_match('/^\d+,\d+$/', (string) $old) ? (string) $old : '24,2';
    }
    return '';
}

/**
 * @brief Settings using field
 * @param string $target Element
 * @param string $code Field code
 * @return array Const names
 */
function ksefEfSourceUses($target, $code)
{
    $refs = array(
        'facture' => array('extrafield:' => array('KSEF_NR_ZAMOWIENIA_SOURCE', 'KSEF_NR_UMOWY_SOURCE', 'KSEF_TP_SOURCE', 'KSEF_FA3_MPP_SOURCE', 'KSEF_FA3_FP_SOURCE', 'KSEF_P17_SOURCE', 'KSEF_P18_SOURCE', 'KSEF_FA3_GROSS_SOURCE')),
        'societe' => array('thirdparty_extrafield:' => array('KSEF_NR_UMOWY_SOURCE', 'KSEF_TP_SOURCE', 'KSEF_IDNABYWCY_SOURCE', 'KSEF_IDWEW_SOURCE', 'KSEF_P17_TP_SOURCE', 'KSEF_P18_TP_SOURCE', 'KSEF_FA3_GROSS_SOURCE')),
        'product' => array('product_extrafield:' => array('KSEF_GTU_SOURCE', 'KSEF_PROCEDURA_SOURCE'), '' => array('KSEF_ZWOLNIENIE_PRODUCT_FIELD')),
    );
    $used = array();
    foreach (($refs[$target] ?? array()) as $prefix => $consts) {
        foreach ($consts as $const) {
            if (getDolGlobalString($const) === $prefix . $code) {
                $used[] = $const;
            }
        }
    }
    return $used;
}

/**
 * @brief Set invoice placement
 * @param DoliDB $db
 * @param string $target Element
 * @param string $code Field code
 * @param string $type Field type
 * @param string $mode none|dodatkowy|stopka
 * @return void
 */
function ksefEfSetOnInvoice($db, $target, $code, $type, $mode)
{
    global $conf;
    $targets = ksefEfTargets();
    $key = $targets[$target][1];
    $list = array_values(array_filter(array_map('trim', explode(',', getDolGlobalString($key))), function ($e) use ($code) {
        return $e !== '' && explode(':', $e, 2)[0] !== $code;
    }));
    if (in_array($mode, array('dodatkowy', 'stopka'), true) && !in_array($type, ksefDodatkowyOpisUnsupportedTypes(), true)) {
        $list[] = in_array($target, array('facture', 'societe', 'projet'), true) ? $code . ':' . $mode : $code;
    }
    dolibarr_set_const($db, $key, implode(',', $list), 'chaine', 0, '', $conf->entity);
}

/**
 * @brief Field use count
 * @param DoliDB $db
 * @param string $target Element
 * @param string $code Field code
 * @param string $type Field type
 * @return int
 */
function ksefEfInvoiceUseCount($db, $target, $code, $type = 'varchar')
{
    if (!preg_match('/^[a-z][a-z0-9_]*$/', $code)) {
        return 0;
    }
    $p = MAIN_DB_PREFIX;
    $set = "e." . $code . " IS NOT NULL" . (in_array($type, array('varchar', 'text', 'select'), true) ? " AND e." . $code . " <> ''" : "");
    $ent = " AND f.entity IN (" . getEntity('invoice') . ")";
    $sql = array(
        'facture' => "SELECT COUNT(*) as n FROM " . $p . "facture_extrafields e JOIN " . $p . "facture f ON f.rowid = e.fk_object WHERE " . $set . $ent,
        'facturedet' => "SELECT COUNT(DISTINCT d.fk_facture) as n FROM " . $p . "facturedet_extrafields e JOIN " . $p . "facturedet d ON d.rowid = e.fk_object JOIN " . $p . "facture f ON f.rowid = d.fk_facture WHERE " . $set . $ent,
        'societe' => "SELECT COUNT(*) as n FROM " . $p . "facture f JOIN " . $p . "societe_extrafields e ON e.fk_object = f.fk_soc WHERE " . $set . $ent,
        'product' => "SELECT COUNT(DISTINCT d.fk_facture) as n FROM " . $p . "facturedet d JOIN " . $p . "product_extrafields e ON e.fk_object = d.fk_product JOIN " . $p . "facture f ON f.rowid = d.fk_facture WHERE " . $set . $ent,
        'projet' => "SELECT COUNT(*) as n FROM " . $p . "facture f JOIN " . $p . "projet_extrafields e ON e.fk_object = f.fk_projet WHERE " . $set . $ent,
    );
    if (!isset($sql[$target])) {
        return 0;
    }
    $res = $db->query($sql[$target]);
    $obj = $res ? $db->fetch_object($res) : null;
    return $obj ? (int) $obj->n : 0;
}

/**
 * @brief Is system field
 * @param string $target Element
 * @param string $code Field code
 * @return bool
 */
function ksefEfReserved($target, $code)
{
    return strpos($code, 'ksef_') === 0 || in_array($code, array('rowid', 'fk_object', 'tms', 'import_key'), true)
        || ($target === 'societe' && in_array($code, array('is_vat_group', 'is_jst'), true));
}

/**
 * @brief Field display label
 * @param ExtraFields $ef Loaded extrafields
 * @param string $target Element
 * @param string $code Field code
 * @return string
 */
function ksefEfDisplayName($ef, $target, $code)
{
    global $langs;
    $label = (string) ($ef->attributes[$target]['label'][$code] ?? '');
    return $label !== '' ? $langs->transnoentitiesnoconv($label) : $code;
}

// this tab only
if ($action == 'update') {
    $fa3_nrklienta_val = GETPOST('KSEF_FA3_INCLUDE_NRKLIENTA', 'alpha') ? '1' : '0';
    dolibarr_set_const($db, 'KSEF_FA3_INCLUDE_NRKLIENTA', $fa3_nrklienta_val, 'chaine', 0, '', $conf->entity);

    $fa3_indeks_val = GETPOST('KSEF_FA3_INCLUDE_INDEKS', 'alpha') ? '1' : '0';
    dolibarr_set_const($db, 'KSEF_FA3_INCLUDE_INDEKS', $fa3_indeks_val, 'chaine', 0, '', $conf->entity);

    $fa3_gtin_val = GETPOST('KSEF_FA3_INCLUDE_GTIN', 'alpha') ? '1' : '0';
    dolibarr_set_const($db, 'KSEF_FA3_INCLUDE_GTIN', $fa3_gtin_val, 'chaine', 0, '', $conf->entity);

    $fa3_unit_val = GETPOST('KSEF_FA3_INCLUDE_UNIT', 'alpha') ? '1' : '0';
    dolibarr_set_const($db, 'KSEF_FA3_INCLUDE_UNIT', $fa3_unit_val, 'chaine', 0, '', $conf->entity);

    if (isModEnabled('bank')) {
        $fa3_bankdesc_val = GETPOST('KSEF_FA3_INCLUDE_BANK_DESC', 'alpha') ? '1' : '0';
        dolibarr_set_const($db, 'KSEF_FA3_INCLUDE_BANK_DESC', $fa3_bankdesc_val, 'chaine', 0, '', $conf->entity);
    }

    $fa3_contact_val = GETPOST('KSEF_FA3_INCLUDE_CONTACT', 'alpha') ? '1' : '0';
    dolibarr_set_const($db, 'KSEF_FA3_INCLUDE_CONTACT', $fa3_contact_val, 'chaine', 0, '', $conf->entity);

    $place_of_issue_mode = GETPOST('KSEF_FA3_PLACE_OF_ISSUE_MODE', 'alpha');
    if (in_array($place_of_issue_mode, array('disabled', 'company', 'custom'))) {
        dolibarr_set_const($db, 'KSEF_FA3_PLACE_OF_ISSUE_MODE', $place_of_issue_mode, 'chaine', 0, '', $conf->entity);
    }
    $place_of_issue_custom = GETPOST('KSEF_FA3_PLACE_OF_ISSUE_CUSTOM', 'alphanohtml');
    dolibarr_set_const($db, 'KSEF_FA3_PLACE_OF_ISSUE_CUSTOM', trim($place_of_issue_custom), 'chaine', 0, '', $conf->entity);

    $sale_date_source = GETPOST('KSEF_FA3_SALE_DATE_SOURCE', 'alpha');
    if (in_array($sale_date_source, array('invoice_date', 'delivery_date'))) {
        dolibarr_set_const($db, 'KSEF_FA3_SALE_DATE_SOURCE', $sale_date_source, 'chaine', 0, '', $conf->entity);
    }

    $nr_umowy_parse_date = GETPOST('KSEF_NR_UMOWY_PARSE_DATE', 'alpha') ? '1' : '0';
    dolibarr_set_const($db, 'KSEF_NR_UMOWY_PARSE_DATE', $nr_umowy_parse_date, 'chaine', 0, '', $conf->entity);

    $zwolnienie_type = GETPOST('KSEF_ZWOLNIENIE_TYPE', 'alpha');
    if (in_array($zwolnienie_type, array('disabled', 'P_19A', 'P_19B', 'P_19C'))) {
        dolibarr_set_const($db, 'KSEF_ZWOLNIENIE_TYPE', $zwolnienie_type, 'chaine', 0, '', $conf->entity);
    }
    $zwolnienie_podstawa = GETPOST('KSEF_ZWOLNIENIE_PODSTAWA', 'alphanohtml');
    dolibarr_set_const($db, 'KSEF_ZWOLNIENIE_PODSTAWA', mb_substr(trim($zwolnienie_podstawa), 0, 256, 'UTF-8'), 'chaine', 0, '', $conf->entity);

    $zwolnienie_product_field = GETPOST('KSEF_ZWOLNIENIE_PRODUCT_FIELD', 'alphanohtml');
    dolibarr_set_const($db, 'KSEF_ZWOLNIENIE_PRODUCT_FIELD', trim($zwolnienie_product_field), 'chaine', 0, '', $conf->entity);

    $uu_id_val = GETPOST('KSEF_FA3_INCLUDE_UU_ID', 'alpha') ? '1' : '0';
    dolibarr_set_const($db, 'KSEF_FA3_INCLUDE_UU_ID', $uu_id_val, 'chaine', 0, '', $conf->entity);

    $ksefFlagAllowed = array(
        'KSEF_NR_ZAMOWIENIA_SOURCE' => array('ref_client', 'linked_order', 'disabled', 'extrafield:'),
        'KSEF_NR_UMOWY_SOURCE' => array('disabled', 'thirdparty_extrafield:', 'extrafield:'),
        'KSEF_IDNABYWCY_SOURCE' => array('disabled', 'code_client', 'thirdparty_extrafield:'),
        'KSEF_GTU_SOURCE' => array('disabled', 'product_extrafield:'),
        'KSEF_PROCEDURA_SOURCE' => array('disabled', 'product_extrafield:'),
        'KSEF_TP_SOURCE' => array('disabled', 'thirdparty_extrafield:', 'extrafield:'),
        'KSEF_IDWEW_SOURCE' => array('disabled', 'thirdparty_extrafield:'),
        'KSEF_FA3_MPP_SOURCE' => array('disabled', 'extrafield:'),
        'KSEF_FA3_FP_SOURCE'  => array('disabled', 'extrafield:'),
        'KSEF_P16_SOURCE'     => array('disabled', 'always_on'),
        'KSEF_P17_SOURCE'     => array('disabled', 'extrafield:'),
        'KSEF_P17_TP_SOURCE'  => array('disabled', 'thirdparty_extrafield:'),
        'KSEF_P18_SOURCE'     => array('disabled', 'always_on', 'extrafield:'),
        'KSEF_P18_TP_SOURCE'  => array('disabled', 'thirdparty_extrafield:'),
    );
    foreach ($ksefFlagAllowed as $_flagConst => $_allowed) {
        $_v = GETPOST($_flagConst, 'alphanohtml');
        $_ok = false;
        foreach ($_allowed as $_mode) {
            if (substr($_mode, -1) === ':') {
                if (strpos($_v, $_mode) === 0 && strlen($_v) > strlen($_mode)) { $_ok = true; break; }
            } elseif ($_v === $_mode) {
                $_ok = true;
                break;
            }
        }
        // keep legacy value
        if (!$_ok && $_v !== '' && $_v === getDolGlobalString($_flagConst)) {
            $_ok = true;
        }
        if ($_ok) {
            dolibarr_set_const($db, $_flagConst, $_v, 'chaine', 0, '', $conf->entity);
        }
    }

    $p3_enable = GETPOST('KSEF_PODMIOT3_SOURCE', 'alpha') ? 'enabled' : 'disabled';
    dolibarr_set_const($db, 'KSEF_PODMIOT3_SOURCE', $p3_enable, 'chaine', 0, '', $conf->entity);

    $p3_role = GETPOST('KSEF_PODMIOT3_ROLE', 'alphanohtml');
    if (in_array($p3_role, array('6', '2', '3', '4', '5', '7', '8', '9', '10', '11', '1'), true)) {
        dolibarr_set_const($db, 'KSEF_PODMIOT3_ROLE', $p3_role, 'chaine', 0, '', $conf->entity);
    }

    $combined_note = GETPOST('KSEF_NOTE_COMBINED_MODE', 'alpha');
    $noteModeMaps = array(
        'disabled'           => array('mode' => 'disabled', 'target' => 'stopka_faktury'),
        'simple_stopka'      => array('mode' => 'simple',   'target' => 'stopka_faktury'),
        'simple_dodatkowy'   => array('mode' => 'simple',   'target' => 'dodatkowy_opis'),
        'keyvalue_dodatkowy' => array('mode' => 'keyvalue', 'target' => 'dodatkowy_opis'),
    );
    if (isset($noteModeMaps[$combined_note])) {
        dolibarr_set_const($db, 'KSEF_DODATKOWY_OPIS_NOTE_MODE', $noteModeMaps[$combined_note]['mode'], 'chaine', 0, '', $conf->entity);
        dolibarr_set_const($db, 'KSEF_NOTE_PUBLIC_TARGET', $noteModeMaps[$combined_note]['target'], 'chaine', 0, '', $conf->entity);
    }

    $stopka_boilerplate = GETPOST('KSEF_STOPKA_BOILERPLATE', 'restricthtml');
    $stopka_boilerplate = trim(strip_tags($stopka_boilerplate));
    if (mb_strlen($stopka_boilerplate, 'UTF-8') > 3500) {
        $stopka_boilerplate = mb_substr($stopka_boilerplate, 0, 3500, 'UTF-8');
    }
    dolibarr_set_const($db, 'KSEF_STOPKA_BOILERPLATE', $stopka_boilerplate, 'chaine', 0, '', $conf->entity);

    $ef_save = new ExtraFields($db);
    $_dodUnsupportedTypes = ksefDodatkowyOpisUnsupportedTypes();

    $ef_save->fetch_name_optionals_label('facture');
    $extrafields_val = array();
    $facture_assign_nr_zamowienia = '';
    $facture_assign_nr_umowy = '';
    $facture_assign_tp = '';
    $facture_assign_mpp = '';
    $facture_assign_fp = '';
    $facture_assign_p17 = '';
    $facture_assign_p18 = '';
    foreach (array_keys($ef_save->attributes['facture']['label'] ?? array()) as $fname) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $_t = $ef_save->attributes['facture']['type'][$fname] ?? '';
        if ($_t !== 'boolean' && in_array($_t, $_dodUnsupportedTypes)) continue;
        $assign = GETPOST('KSEF_ASSIGN_FACTURE_' . $fname, 'alphanohtml');
        if (($assign === 'dodatkowy' || $assign === 'stopka') && $_t !== 'boolean') {
            $extrafields_val[] = $fname . ':' . $assign;
        } elseif ($assign === 'nr_zamowienia') {
            $facture_assign_nr_zamowienia = $fname;
        } elseif ($assign === 'nr_umowy') {
            $facture_assign_nr_umowy = $fname;
        } elseif ($assign === 'tp') {
            $facture_assign_tp = $fname;
        } elseif ($assign === 'mpp') {
            $facture_assign_mpp = $fname;
        } elseif ($assign === 'fp') {
            $facture_assign_fp = $fname;
        } elseif ($assign === 'p17') {
            $facture_assign_p17 = $fname;
        } elseif ($assign === 'p18') {
            $facture_assign_p18 = $fname;
        }
    }
    dolibarr_set_const($db, 'KSEF_DODATKOWY_OPIS_EXTRAFIELDS', implode(',', $extrafields_val), 'chaine', 0, '', $conf->entity);
    if ($facture_assign_nr_zamowienia !== '') {
        dolibarr_set_const($db, 'KSEF_NR_ZAMOWIENIA_SOURCE', 'extrafield:' . $facture_assign_nr_zamowienia, 'chaine', 0, '', $conf->entity);
    }
    if ($facture_assign_nr_umowy !== '') {
        dolibarr_set_const($db, 'KSEF_NR_UMOWY_SOURCE', 'extrafield:' . $facture_assign_nr_umowy, 'chaine', 0, '', $conf->entity);
    }
    if ($facture_assign_tp !== '') {
        dolibarr_set_const($db, 'KSEF_TP_SOURCE', 'extrafield:' . $facture_assign_tp, 'chaine', 0, '', $conf->entity);
    }
    if ($facture_assign_mpp !== '') {
        dolibarr_set_const($db, 'KSEF_FA3_MPP_SOURCE', 'extrafield:' . $facture_assign_mpp, 'chaine', 0, '', $conf->entity);
    }
    if ($facture_assign_fp !== '') {
        dolibarr_set_const($db, 'KSEF_FA3_FP_SOURCE', 'extrafield:' . $facture_assign_fp, 'chaine', 0, '', $conf->entity);
    }
    if ($facture_assign_p17 !== '') {
        dolibarr_set_const($db, 'KSEF_P17_SOURCE', 'extrafield:' . $facture_assign_p17, 'chaine', 0, '', $conf->entity);
    }
    if ($facture_assign_p18 !== '') {
        dolibarr_set_const($db, 'KSEF_P18_SOURCE', 'extrafield:' . $facture_assign_p18, 'chaine', 0, '', $conf->entity);
    }

    $ef_save->fetch_name_optionals_label('facturedet');
    $det_extrafields_val = array();
    foreach (array_keys($ef_save->attributes['facturedet']['label'] ?? array()) as $fname) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $_t = $ef_save->attributes['facturedet']['type'][$fname] ?? '';
        if (in_array($_t, $_dodUnsupportedTypes)) continue;
        $assign = GETPOST('KSEF_ASSIGN_FACTUREDET_' . $fname, 'alphanohtml');
        if ($assign === 'dodatkowy') {
            $det_extrafields_val[] = $fname;
        }
    }
    dolibarr_set_const($db, 'KSEF_DODATKOWY_OPIS_DET_EXTRAFIELDS', implode(',', $det_extrafields_val), 'chaine', 0, '', $conf->entity);

    $ef_save->fetch_name_optionals_label('product');
    $product_extrafields_val = array();
    $product_assign_gtu = '';
    $product_assign_procedura = '';
    $product_assign_zwolnienie = '';
    foreach (array_keys($ef_save->attributes['product']['label'] ?? array()) as $fname) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $_t = $ef_save->attributes['product']['type'][$fname] ?? '';
        if (in_array($_t, $_dodUnsupportedTypes)) continue;
        $assign = GETPOST('KSEF_ASSIGN_PRODUCT_' . $fname, 'alphanohtml');
        if ($assign === 'dodatkowy') {
            $product_extrafields_val[] = $fname;
        } elseif ($assign === 'gtu') {
            $product_assign_gtu = $fname;
        } elseif ($assign === 'procedura') {
            $product_assign_procedura = $fname;
        } elseif ($assign === 'zwolnienie') {
            $product_assign_zwolnienie = $fname;
        }
    }
    dolibarr_set_const($db, 'KSEF_DODATKOWY_OPIS_PRODUCT_EXTRAFIELDS', implode(',', $product_extrafields_val), 'chaine', 0, '', $conf->entity);
    if ($product_assign_gtu !== '') {
        dolibarr_set_const($db, 'KSEF_GTU_SOURCE', 'product_extrafield:' . $product_assign_gtu, 'chaine', 0, '', $conf->entity);
    }
    if ($product_assign_procedura !== '') {
        dolibarr_set_const($db, 'KSEF_PROCEDURA_SOURCE', 'product_extrafield:' . $product_assign_procedura, 'chaine', 0, '', $conf->entity);
    }
    if ($product_assign_zwolnienie !== '') {
        dolibarr_set_const($db, 'KSEF_ZWOLNIENIE_PRODUCT_FIELD', $product_assign_zwolnienie, 'chaine', 0, '', $conf->entity);
    }

    $ef_save->fetch_name_optionals_label('societe');
    $societe_extrafields_val = array();
    $societe_assign_idnabywcy = '';
    $societe_assign_nr_umowy = '';
    $societe_assign_tp = '';
    $societe_assign_idwew = '';
    $societe_assign_p17 = '';
    $societe_assign_p18 = '';
    foreach (array_keys($ef_save->attributes['societe']['label'] ?? array()) as $fname) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $_t = $ef_save->attributes['societe']['type'][$fname] ?? '';
        if ($_t !== 'boolean' && in_array($_t, $_dodUnsupportedTypes)) continue;
        $assign = GETPOST('KSEF_ASSIGN_SOCIETE_' . $fname, 'alphanohtml');
        if (($assign === 'dodatkowy' || $assign === 'stopka') && $_t !== 'boolean') {
            $societe_extrafields_val[] = $fname . ':' . $assign;
        } elseif ($assign === 'idnabywcy') {
            $societe_assign_idnabywcy = $fname;
        } elseif ($assign === 'nr_umowy') {
            $societe_assign_nr_umowy = $fname;
        } elseif ($assign === 'tp') {
            $societe_assign_tp = $fname;
        } elseif ($assign === 'idwew') {
            $societe_assign_idwew = $fname;
        } elseif ($assign === 'p17') {
            $societe_assign_p17 = $fname;
        } elseif ($assign === 'p18') {
            $societe_assign_p18 = $fname;
        }
    }
    dolibarr_set_const($db, 'KSEF_DODATKOWY_OPIS_SOCIETE_EXTRAFIELDS', implode(',', $societe_extrafields_val), 'chaine', 0, '', $conf->entity);
    if ($societe_assign_idnabywcy !== '') {
        dolibarr_set_const($db, 'KSEF_IDNABYWCY_SOURCE', 'thirdparty_extrafield:' . $societe_assign_idnabywcy, 'chaine', 0, '', $conf->entity);
    }
    if ($societe_assign_nr_umowy !== '') {
        dolibarr_set_const($db, 'KSEF_NR_UMOWY_SOURCE', 'thirdparty_extrafield:' . $societe_assign_nr_umowy, 'chaine', 0, '', $conf->entity);
    }
    if ($societe_assign_tp !== '') {
        dolibarr_set_const($db, 'KSEF_TP_SOURCE', 'thirdparty_extrafield:' . $societe_assign_tp, 'chaine', 0, '', $conf->entity);
    }
    if ($societe_assign_idwew !== '') {
        dolibarr_set_const($db, 'KSEF_IDWEW_SOURCE', 'thirdparty_extrafield:' . $societe_assign_idwew, 'chaine', 0, '', $conf->entity);
    }
    if ($societe_assign_p17 !== '') {
        dolibarr_set_const($db, 'KSEF_P17_TP_SOURCE', 'thirdparty_extrafield:' . $societe_assign_p17, 'chaine', 0, '', $conf->entity);
    }
    if ($societe_assign_p18 !== '') {
        dolibarr_set_const($db, 'KSEF_P18_TP_SOURCE', 'thirdparty_extrafield:' . $societe_assign_p18, 'chaine', 0, '', $conf->entity);
    }

    $ef_save->fetch_name_optionals_label('projet');
    $project_extrafields_val = array();
    foreach (array_keys($ef_save->attributes['projet']['label'] ?? array()) as $fname) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $_t = $ef_save->attributes['projet']['type'][$fname] ?? '';
        if (in_array($_t, $_dodUnsupportedTypes)) continue;
        $assign = GETPOST('KSEF_ASSIGN_PROJET_' . $fname, 'alphanohtml');
        if ($assign === 'dodatkowy' || $assign === 'stopka') {
            $project_extrafields_val[] = $fname . ':' . $assign;
        }
    }
    dolibarr_set_const($db, 'KSEF_DODATKOWY_OPIS_PROJECT_EXTRAFIELDS', implode(',', $project_extrafields_val), 'chaine', 0, '', $conf->entity);

    foreach (array('KSEF_FA3_INCLUDE_WZ', 'KSEF_FA3_PERIOD_FIELDS', 'KSEF_FA3_ORDER_DATE_FIELD', 'KSEF_NR_ZAMOWIENIA_PARSE_DATE') as $k) {
        dolibarr_set_const($db, $k, GETPOST($k, 'alpha') ? '1' : '0', 'chaine', 0, '', $conf->entity);
    }
    $lineVat = GETPOST('KSEF_FA3_INCLUDE_LINE_VAT', 'aZ09');
    if (in_array($lineVat, array('auto', '1'), true)) {
        dolibarr_set_const($db, 'KSEF_FA3_INCLUDE_LINE_VAT', $lineVat, 'chaine', 0, '', $conf->entity);
    }
    $lineDates = GETPOST('KSEF_FA3_LINE_DATES', 'aZ09');
    dolibarr_set_const($db, 'KSEF_FA3_LINE_DATES', $lineDates === 'auto' ? 'auto' : 'disabled', 'chaine', 0, '', $conf->entity);
    $gross = GETPOST('KSEF_FA3_GROSS_SOURCE', 'alphanohtml');
    if ($gross === 'disabled' || $gross === 'always_on' || preg_match('/^(thirdparty_)?extrafield:[a-z0-9_]+$/i', $gross)) {
        dolibarr_set_const($db, 'KSEF_FA3_GROSS_SOURCE', $gross, 'chaine', 0, '', $conf->entity);
    }

    setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

if ($action == 'create_dodatkowy_extrafield') {
    $label = trim(GETPOST('ef_label', 'alphanohtml'));
    $type = GETPOST('ef_type', 'alpha');
    $optionsRaw = GETPOST('ef_options', 'restricthtml');
    $efTarget = GETPOST('ef_target', 'alpha');
    if (!array_key_exists($efTarget, ksefEfTargets())) $efTarget = 'facture';

    $err = '';
    $code = '';
    $ef_create = new ExtraFields($db);
    $ef_create->fetch_name_optionals_label($efTarget);

    if ($label === '') {
        $err = $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_ERROR_LABEL');
    } elseif (!array_key_exists($type, ksefEfTypes())) {
        $err = $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_ERROR_TYPE');
    } else {
        $labelLower = mb_strtolower($label, 'UTF-8');
        foreach (($ef_create->attributes[$efTarget]['label'] ?? array()) as $existingName => $existingLabel) {
            if (strpos($existingName, 'ksef_') === 0) continue;
            if (mb_strtolower($langs->trans($existingLabel), 'UTF-8') === $labelLower) {
                $err = $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_ERROR_DUP_LABEL');
                break;
            }
        }
    }

    if (!$err) {
        // avoid SQL keywords
        $baseCode = ksefFieldCodeFromLabel($label);
        if (strpos($baseCode, 'ksef_') === 0) {
            $baseCode = substr($baseCode, 5);
        }
        if (strpos($baseCode, 'cf_') !== 0) {
            $baseCode = 'cf_' . $baseCode;
        }
        $baseCode = rtrim(substr($baseCode, 0, 50), '_');
        // orphans collide too
        $efColumns = array();
        $efDesc = $db->DDLDescTable(MAIN_DB_PREFIX . $efTarget . '_extrafields');
        while ($efDesc && ($efCol = $db->fetch_row($efDesc))) {
            $efColumns[strtolower((string) $efCol[0])] = true;
        }
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $baseCode)) {
            $err = $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_ERROR_LABEL');
        } else {
            $code = $baseCode;
            $counter = 2;
            while (isset($ef_create->attributes[$efTarget]['label'][$code]) || isset($efColumns[$code])) {
                $code = $baseCode . '_' . $counter;
                $counter++;
                if ($counter > 100) { $err = ksefTransHtml('KSEF_ExtraFieldCreateFailed', array(ksefEscapeText($label))); break; }
            }
        }
    }

    if (!$err) {
        $param = ($type === 'select') ? ksefParseExtrafieldOptions($optionsRaw) : '';
        $maxPos = 200;
        if (!empty($ef_create->attributes[$efTarget]['pos'])) {
            $maxPos = max(array_map('intval', $ef_create->attributes[$efTarget]['pos'])) + 10;
        }
        $result = $ef_create->addExtraField(
            $code, $label, $type, $maxPos, ksefEfSize($type, ''), $efTarget,
            0, 0, '', $param, 1, '', '1', '', '', '', 'ksef@ksef', '1', 0, 0
        );
        if ($result > 0) {
            $on = GETPOST('ef_on_invoice', 'aZ09');
            ksefEfSetOnInvoice($db, $efTarget, $code, $type, $on === '' ? 'dodatkowy' : $on);
            setEventMessages($langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_SUCCESS', $label), null, 'mesgs');
        } else {
            dol_syslog('ksef setup_fields: extrafield ' . $code . ' on ' . $efTarget . ' not created: ' . $ef_create->error . ' ' . $db->lasterror(), LOG_ERR);
            setEventMessages(ksefTransHtml('KSEF_ExtraFieldCreateFailed', array(ksefEscapeText($label))), null, 'errors');
        }
    } else {
        setEventMessages($err, null, 'errors');
    }

    header("Location: " . $_SERVER["PHP_SELF"] . '#ksef-extrafields');
    exit;
}

if ($action == 'edit_dodatkowy_extrafield') {
    $code = trim(GETPOST('ef_code', 'alphanohtml'));
    $label = trim(GETPOST('ef_label', 'alphanohtml'));
    $type = GETPOST('ef_type', 'alpha');
    $optionsRaw = GETPOST('ef_options', 'restricthtml');
    $efTarget = GETPOST('ef_target', 'alpha');
    if (!array_key_exists($efTarget, ksefEfTargets())) $efTarget = 'facture';

    $err = '';
    $ef_edit = new ExtraFields($db);
    $ef_edit->fetch_name_optionals_label($efTarget);

    if (!isset($ef_edit->attributes[$efTarget]['label'][$code])) {
        $err = $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_ERROR_NOT_FOUND');
    } elseif (ksefEfReserved($efTarget, $code)) {
        $err = $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_ERROR_RESERVED');
    } elseif (($ef_edit->attributes[$efTarget]['langfile'][$code] ?? '') !== 'ksef@ksef') {
        $err = $langs->trans('KSEF_ExtraFieldNotOurs', $code);
    } elseif ($label === '') {
        $err = $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_ERROR_LABEL');
    } elseif (!array_key_exists($type, ksefEfTypes())) {
        $err = $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_ERROR_TYPE');
    } else {
        $labelLower = mb_strtolower($label, 'UTF-8');
        foreach (($ef_edit->attributes[$efTarget]['label'] ?? array()) as $existingName => $existingLabel) {
            if ($existingName === $code || strpos($existingName, 'ksef_') === 0) continue;
            if (mb_strtolower($langs->trans($existingLabel), 'UTF-8') === $labelLower) {
                $err = $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_ERROR_DUP_LABEL');
                break;
            }
        }
    }

    if (!$err) {
        $param = ($type === 'select') ? ksefParseExtrafieldOptions($optionsRaw) : '';
        $attrs = $ef_edit->attributes[$efTarget];
        $oldType = $attrs['type'][$code] ?? '';
        $size = ksefEfSize($type, $oldType === $type ? (string) ($attrs['size'][$code] ?? '') : '');
        $result = $ef_edit->updateExtraField(
            $code,
            $label,
            $type,
            $attrs['pos'][$code] ?? 200,
            $size,
            $efTarget,
            $attrs['unique'][$code] ?? 0,
            $attrs['required'][$code] ?? 0,
            $attrs['default'][$code] ?? '',
            $param,
            $attrs['alwayseditable'][$code] ?? 1,
            $attrs['perms'][$code] ?? '',
            $attrs['list'][$code] ?? '1',
            $attrs['help'][$code] ?? '',
            $attrs['computed'][$code] ?? '',
            $attrs['entityid'][$code] ?? '',
            $attrs['langfile'][$code] ?? '',
            $attrs['enabled'][$code] ?? '1',
            $attrs['totalizable'][$code] ?? 0,
            $attrs['printable'][$code] ?? 0
        );

        if ($result > 0) {
            if (in_array($type, ksefDodatkowyOpisUnsupportedTypes(), true)) {
                ksefEfSetOnInvoice($db, $efTarget, $code, $type, 'none');
            } elseif (GETPOSTISSET('ef_on_invoice') && !ksefEfSourceUses($efTarget, $code)) {
                ksefEfSetOnInvoice($db, $efTarget, $code, $type, GETPOST('ef_on_invoice', 'aZ09'));
            }
            setEventMessages($langs->trans('KSEF_DODATKOWY_OPIS_UPDATE_SUCCESS', $label), null, 'mesgs');
        } else {
            dol_syslog('ksef setup_fields: extrafield ' . $code . ' on ' . $efTarget . ' not updated: ' . $ef_edit->error . ' ' . $db->lasterror(), LOG_ERR);
            setEventMessages(ksefTransHtml('KSEF_ExtraFieldUpdateFailed', array(ksefEscapeText($label))), null, 'errors');
        }
    } else {
        setEventMessages($err, null, 'errors');
    }

    header("Location: " . $_SERVER["PHP_SELF"] . '#ksef-extrafields');
    exit;
}

if ($action == 'confirm_delete_dodatkowy_extrafield' && GETPOST('confirm', 'alpha') == 'yes') {
    $code = trim(GETPOST('ef_code', 'alphanohtml'));
    $efTarget = GETPOST('ef_target', 'alpha');
    if (!array_key_exists($efTarget, ksefEfTargets())) $efTarget = 'facture';

    $err = '';
    if ($code === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $code)) {
        $err = $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_ERROR_CODE');
    } elseif (ksefEfReserved($efTarget, $code)) {
        $err = $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_ERROR_RESERVED');
    } else {
        $ef_del = new ExtraFields($db);
        $ef_del->fetch_name_optionals_label($efTarget);
        if (!isset($ef_del->attributes[$efTarget]['label'][$code])) {
            $err = $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_ERROR_NOT_FOUND');
        } elseif (($ef_del->attributes[$efTarget]['langfile'][$code] ?? '') !== 'ksef@ksef') {
            $err = $langs->trans('KSEF_ExtraFieldNotOurs', $code);
        } elseif ($efUsedBy = ksefEfSourceUses($efTarget, $code)) {
            $err = $langs->trans('KSEF_ExtraFieldInUse', ksefEfDisplayName($ef_del, $efTarget, $code), implode(', ', array_map(array($langs, 'transnoentitiesnoconv'), $efUsedBy)));
        }
    }

    if (!$err) {
        $type = $ef_del->attributes[$efTarget]['type'][$code] ?? '';
        $delName = ksefEfDisplayName($ef_del, $efTarget, $code);
        if ($ef_del->delete($code, $efTarget) >= 0) {
            ksefEfSetOnInvoice($db, $efTarget, $code, $type, 'none');
            setEventMessages($langs->trans('KSEF_DODATKOWY_OPIS_DELETE_SUCCESS', $delName), null, 'mesgs');
        } else {
            dol_syslog('ksef setup_fields: extrafield ' . $code . ' on ' . $efTarget . ' not deleted: ' . $ef_del->error . ' ' . $db->lasterror(), LOG_ERR);
            setEventMessages(ksefTransHtml('KSEF_ExtraFieldDeleteFailed', array(ksefEscapeText($delName))), null, 'errors');
        }
    } else {
        setEventMessages($err, null, 'errors');
    }

    header("Location: " . $_SERVER["PHP_SELF"] . '#ksef-extrafields');
    exit;
}

$form = new Form($db);
$page_name = "KSEF_Setup";

llxHeader('', $langs->trans($page_name), ksefHelpUrl('quickstart'));

$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1">' . $langs->trans("KSEF_BackToModuleList") . '</a>';
print load_fiche_titre($langs->trans($page_name), ksefHelpTitleButton($user, 'fields', array('config' => 'KSEF_ConfigurationSection'), array('setup_defaults')) . $linkback, 'title_setup');

$head = ksefAdminPrepareHead();
print dol_get_fiche_head($head, 'fields', $langs->trans("KSEF_Module"), -1, 'ksef@ksef');

if ($action == 'ask_delete_dodatkowy_extrafield') {
    $askCode = trim(GETPOST('ef_code', 'alphanohtml'));
    $askTarget = GETPOST('ef_target', 'aZ09');
    if (preg_match('/^[a-z][a-z0-9_]*$/', $askCode) && array_key_exists($askTarget, ksefEfTargets())) {
        $ef_ask = new ExtraFields($db);
        $ef_ask->fetch_name_optionals_label($askTarget);
        print $form->formconfirm($_SERVER["PHP_SELF"] . '?ef_target=' . $askTarget . '&ef_code=' . urlencode($askCode), $langs->trans('Delete'),
            $langs->trans('KSEF_DODATKOWY_OPIS_DELETE_CONFIRM', ksefEfDisplayName($ef_ask, $askTarget, $askCode)), 'confirm_delete_dodatkowy_extrafield', '', 0, 1);
    }
}

echo ksefShowAdminBanners();

$warnings = ksefGetConfigWarnings();
if (!empty($warnings)) {
    echo ksefRenderConfigWarnings($warnings, 'fields');
}

$ef_entity = new ExtraFields($db);
foreach (array_keys(ksefEfTargets()) as $_el) {
    $ef_entity->fetch_name_optionals_label($_el);
}
$_dodUnsupportedTypes = ksefDodatkowyOpisUnsupportedTypes();

print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '" data-ksef-guarded="1">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="update">';

print '<div class="ksef-settings-box">';

/**
 * @brief Flag source row
 * @param array $fr Row spec
 * @param Form $form
 * @param Translate $langs
 * @param ExtraFields $ef_entity Loaded extrafields
 * @return void
 */
function ksefRenderFlagSourceRow($fr, $form, $langs, $ef_entity)
{
    print '<tr class="oddeven">';
    print '<td class="titlefield">' . $form->textwithpicto($langs->trans($fr['const']), $langs->trans($fr['const'] . '_Help')) . '</td>';
    print '<td>';
    $flag_options = array('disabled' => $langs->trans($fr['const'] . '_DISABLED'));
    if ($fr['always_on']) {
        $flag_options['always_on'] = $langs->trans($fr['const'] . '_ALWAYS_ON');
    }
    if ($fr['fkey'] !== '' && !empty($ef_entity->attributes['facture']['label'])) {
        foreach ($ef_entity->attributes['facture']['label'] as $fname => $flabel) {
            if (strpos($fname, 'ksef_') === 0) continue;
            $ftype = $ef_entity->attributes['facture']['type'][$fname] ?? '';
            if (in_array($ftype, array('boolean', 'select', 'varchar', 'int'))) {
                $flag_options['extrafield:' . $fname] = $langs->trans($fr['const'] . '_EXTRAFIELD') . ': ' . $langs->trans($flabel);
            }
        }
    }
    if ($fr['skey'] !== '' && !empty($ef_entity->attributes['societe']['label'])) {
        foreach ($ef_entity->attributes['societe']['label'] as $fname => $flabel) {
            if (strpos($fname, 'ksef_') === 0) continue;
            $ftype = $ef_entity->attributes['societe']['type'][$fname] ?? '';
            if (in_array($ftype, array('boolean', 'select', 'varchar', 'int'))) {
                $flag_options['thirdparty_extrafield:' . $fname] = $langs->trans($fr['const'] . '_THIRDPARTY_EXTRAFIELD') . ': ' . $langs->trans($flabel);
            }
        }
    }
    $flag_current = getDolGlobalString($fr['const'], 'disabled');
    // keep stored value
    if ($flag_current === 'always_on') {
        $flag_options = ksefSelectKeepCurrent($flag_options, $flag_current, $langs->trans($fr['const'] . '_ALWAYS_ON'));
    } else {
        $flag_options = ksefSelectKeepCurrent($flag_options, $flag_current, $langs->trans('KSEF_FieldMissingOption', preg_replace('/^[a-z_]*extrafield:/', '', $flag_current)));
    }
    $flag_sync = array();
    if ($fr['fkey'] !== '') {
        $flag_sync[] = 'ksefSyncFromDropdown(\'facture\', \'' . $fr['const'] . '\', \'' . $fr['fkey'] . '\', \'extrafield:\')';
    }
    if ($fr['skey'] !== '') {
        $flag_sync[] = 'ksefSyncFromDropdown(\'societe\', \'' . $fr['const'] . '\', \'' . $fr['skey'] . '\', \'thirdparty_extrafield:\')';
    }
    $flag_onchange = $flag_sync ? 'onchange="' . implode('; ', $flag_sync) . '"' : '';
    print $form->selectarray($fr['const'], $flag_options, $flag_current, 0, 0, 0, $flag_onchange, 0, 0, 0, '', 'minwidth300');
    if ($flag_current === 'always_on' && !$fr['always_on'] && $fr['const'] === 'KSEF_FA3_FP_SOURCE') {
        print '<div class="warning ksef-flag-always-on">' . $langs->trans($fr['const'] . '_ALWAYS_ON_Warn') . '</div>';
    }
    print '</td></tr>';
}

print '<br><table class="noborder centpercent">';
print '<tr class="liste_titre ksef-section-head"><td colspan="2">' . $langs->trans('KSEF_DODATKOWY_OPIS_FACTURE_TITLE') . ksefSectionDesc('KSEF_DODATKOWY_OPIS_FACTURE_TITLE_Desc') . '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_FA3_INCLUDE_BANK_DESC'), $langs->trans('KSEF_FA3_INCLUDE_BANK_DESC_Help')) . '</td>';
print '<td>';
print '<input type="checkbox" name="KSEF_FA3_INCLUDE_BANK_DESC" id="KSEF_FA3_INCLUDE_BANK_DESC" value="1" ' . (getDolGlobalInt('KSEF_FA3_INCLUDE_BANK_DESC') ? 'checked' : '') . (isModEnabled('bank') ? '' : ' disabled') . '>';
print ' <label for="KSEF_FA3_INCLUDE_BANK_DESC">' . $langs->trans("KSEF_Enabled") . '</label>';
if (!isModEnabled('bank')) {
    print ' ' . ksefRequiresModuleText('Module85Name');
}
print '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_FA3_INCLUDE_CONTACT'), $langs->trans('KSEF_FA3_INCLUDE_CONTACT_Help')) . '</td>';
print '<td>';
print '<input type="checkbox" name="KSEF_FA3_INCLUDE_CONTACT" id="KSEF_FA3_INCLUDE_CONTACT" value="1" ' . (getDolGlobalString('KSEF_FA3_INCLUDE_CONTACT', '1') !== '0' ? 'checked' : '') . '>';
print ' <label for="KSEF_FA3_INCLUDE_CONTACT">' . $langs->trans("KSEF_Enabled") . '</label>';
print '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_FA3_PLACE_OF_ISSUE'), $langs->trans('KSEF_FA3_PLACE_OF_ISSUE_Help')) . '</td>';
print '<td>';
$place_modes = array(
    'disabled' => $langs->trans('KSEF_FA3_PLACE_OF_ISSUE_DISABLED'),
    'company' => $langs->trans('KSEF_FA3_PLACE_OF_ISSUE_COMPANY'),
    'custom' => $langs->trans('KSEF_FA3_PLACE_OF_ISSUE_CUSTOM'),
);
$current_place_mode = getDolGlobalString('KSEF_FA3_PLACE_OF_ISSUE_MODE', 'disabled');
print $form->selectarray('KSEF_FA3_PLACE_OF_ISSUE_MODE', $place_modes, $current_place_mode, 0, 0, 0, 'onchange="togglePlaceOfIssueCustom()"', 0, 0, 0, '', 'minwidth200');
print '<span id="place_of_issue_custom_wrapper" style="margin-left: 10px; ' . ($current_place_mode != 'custom' ? 'display:none;' : '') . '">';
print '<input type="text" name="KSEF_FA3_PLACE_OF_ISSUE_CUSTOM" class="flat minwidth200" value="' . dol_escape_htmltag(getDolGlobalString('KSEF_FA3_PLACE_OF_ISSUE_CUSTOM', '')) . '" placeholder="' . $langs->trans("KSEF_FA3_PLACE_OF_ISSUE_CUSTOM_Placeholder") . '">';
print '</span>';
print '</td></tr>';
print '<script>
function togglePlaceOfIssueCustom() {
    var mode = document.querySelector(\'select[name="KSEF_FA3_PLACE_OF_ISSUE_MODE"]\').value;
    var wrapper = document.getElementById("place_of_issue_custom_wrapper");
    wrapper.style.display = (mode == "custom") ? "inline" : "none";
}
</script>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_FA3_SALE_DATE_SOURCE'), $langs->trans('KSEF_FA3_SALE_DATE_SOURCE_Help')) . '</td>';
print '<td>';
$sale_date_modes = array(
    'invoice_date' => $langs->trans('KSEF_FA3_SALE_DATE_SOURCE_INVOICE'),
    'delivery_date' => $langs->trans('KSEF_FA3_SALE_DATE_SOURCE_DELIVERY'),
);
$current_sale_date_source = getDolGlobalString('KSEF_FA3_SALE_DATE_SOURCE', 'delivery_date');
print $form->selectarray('KSEF_FA3_SALE_DATE_SOURCE', $sale_date_modes, $current_sale_date_source, 0, 0, 0, '', 0, 0, 0, '', 'minwidth300');
print '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_PODMIOT3_SOURCE'), $langs->trans('KSEF_PODMIOT3_SOURCE_Help')) . '</td>';
print '<td>';
print '<input type="checkbox" name="KSEF_PODMIOT3_SOURCE" id="KSEF_PODMIOT3_SOURCE" value="1" ' . (getDolGlobalString('KSEF_PODMIOT3_SOURCE', 'disabled') === 'enabled' ? 'checked' : '') . '>';
print ' <label for="KSEF_PODMIOT3_SOURCE">' . $langs->trans("KSEF_Enabled") . '</label>';
print '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_PODMIOT3_ROLE'), $langs->trans('KSEF_PODMIOT3_ROLE_Help')) . '</td>';
print '<td>';
$p3_role_options = array();
foreach (array('6', '2', '3', '4', '5', '7', '8', '9', '10', '11', '1') as $r) {
    $p3_role_options[$r] = $r . ' - ' . $langs->trans('KSEF_Podmiot3_Role' . $r);
}
$current_p3_role = getDolGlobalString('KSEF_PODMIOT3_ROLE', '6');
print $form->selectarray('KSEF_PODMIOT3_ROLE', $p3_role_options, $current_p3_role, 0, 0, 0, '', 0, 0, 0, '', 'minwidth300');
print '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_NR_ZAMOWIENIA_SOURCE'), $langs->trans('KSEF_NR_ZAMOWIENIA_SOURCE_Help')) . '</td>';
print '<td>';
$zamowienia_options = array(
    'ref_client' => $langs->trans('KSEF_NR_ZAMOWIENIA_SOURCE_REF_CLIENT'),
    'linked_order' => $langs->trans('KSEF_NR_ZAMOWIENIA_SOURCE_LINKED_ORDER') . (isModEnabled('order') ? '' : ' (' . $langs->transnoentitiesnoconv('KSEF_RequiresModule', $langs->transnoentitiesnoconv('Module25Name')) . ')'),
    'disabled' => $langs->trans('KSEF_NR_ZAMOWIENIA_SOURCE_DISABLED'),
);
if (!empty($ef_entity->attributes['facture']['label'])) {
    foreach ($ef_entity->attributes['facture']['label'] as $fname => $flabel) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $ftype = $ef_entity->attributes['facture']['type'][$fname] ?? '';
        if (in_array($ftype, array('varchar', 'text', 'int'))) {
            $zamowienia_options['extrafield:' . $fname] = $langs->trans('KSEF_NR_ZAMOWIENIA_SOURCE_EXTRAFIELD') . ': ' . $langs->trans($flabel);
        }
    }
}
$current_zamowienia = getDolGlobalString('KSEF_NR_ZAMOWIENIA_SOURCE', 'ref_client');
$zamowienia_options = ksefSelectKeepCurrent($zamowienia_options, $current_zamowienia, $langs->trans('KSEF_FieldMissingOption', preg_replace('/^[a-z_]*extrafield:/', '', $current_zamowienia)));
print $form->selectarray('KSEF_NR_ZAMOWIENIA_SOURCE', $zamowienia_options, $current_zamowienia, 0, 0, 0, 'onchange="ksefSyncFromDropdown(\'facture\', \'KSEF_NR_ZAMOWIENIA_SOURCE\', \'nr_zamowienia\', \'extrafield:\')"', 0, 0, 0, '', 'minwidth300');
print '</td></tr>';

$ksefInvoiceFlagRows = array(
    array('const' => 'KSEF_FA3_MPP_SOURCE', 'always_on' => false, 'fkey' => 'mpp', 'skey' => ''),
    array('const' => 'KSEF_FA3_FP_SOURCE',  'always_on' => false, 'fkey' => 'fp',  'skey' => ''),
    array('const' => 'KSEF_P16_SOURCE',     'always_on' => true,  'fkey' => '',    'skey' => ''),
    array('const' => 'KSEF_P17_SOURCE',     'always_on' => false, 'fkey' => 'p17', 'skey' => ''),
    array('const' => 'KSEF_P18_SOURCE',     'always_on' => true,  'fkey' => 'p18', 'skey' => ''),
);
foreach ($ksefInvoiceFlagRows as $fr) {
    ksefRenderFlagSourceRow($fr, $form, $langs, $ef_entity);
}
print '</table>';

print '<br><table class="noborder centpercent">';
print '<tr class="liste_titre ksef-section-head"><td colspan="2">' . $langs->trans('KSEF_DODATKOWY_OPIS_SOCIETE_TITLE') . ksefSectionDesc('KSEF_DODATKOWY_OPIS_SOCIETE_TITLE_Desc') . '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_IDNABYWCY_SOURCE'), $langs->trans('KSEF_IDNABYWCY_SOURCE_Help')) . '</td>';
print '<td>';
$idnabywcy_options = array(
    'disabled' => $langs->trans('KSEF_IDNABYWCY_SOURCE_DISABLED'),
    'code_client' => $langs->trans('KSEF_IDNABYWCY_SOURCE_CODE_CLIENT'),
);
if (!empty($ef_entity->attributes['societe']['label'])) {
    foreach ($ef_entity->attributes['societe']['label'] as $fname => $flabel) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $ftype = $ef_entity->attributes['societe']['type'][$fname] ?? '';
        if (in_array($ftype, array('varchar', 'text', 'int'))) {
            $idnabywcy_options['thirdparty_extrafield:' . $fname] = $langs->trans('KSEF_IDNABYWCY_SOURCE_EXTRAFIELD') . ': ' . $langs->trans($flabel);
        }
    }
}
$current_idnabywcy = getDolGlobalString('KSEF_IDNABYWCY_SOURCE', 'disabled');
$idnabywcy_options = ksefSelectKeepCurrent($idnabywcy_options, $current_idnabywcy, $langs->trans('KSEF_FieldMissingOption', preg_replace('/^[a-z_]*extrafield:/', '', $current_idnabywcy)));
print $form->selectarray('KSEF_IDNABYWCY_SOURCE', $idnabywcy_options, $current_idnabywcy, 0, 0, 0, 'onchange="ksefSyncFromDropdown(\'societe\', \'KSEF_IDNABYWCY_SOURCE\', \'idnabywcy\', \'thirdparty_extrafield:\')"', 0, 0, 0, '', 'minwidth300');
print '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_NR_UMOWY_SOURCE'), $langs->trans('KSEF_NR_UMOWY_SOURCE_Help')) . '</td>';
print '<td>';
$umowy_options = array(
    'disabled' => $langs->trans('KSEF_NR_UMOWY_SOURCE_DISABLED'),
);
if (!empty($ef_entity->attributes['societe']['label'])) {
    foreach ($ef_entity->attributes['societe']['label'] as $fname => $flabel) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $ftype = $ef_entity->attributes['societe']['type'][$fname] ?? '';
        if (in_array($ftype, array('varchar', 'text', 'int'))) {
            $umowy_options['thirdparty_extrafield:' . $fname] = $langs->trans('KSEF_NR_UMOWY_SOURCE_THIRDPARTY_EXTRAFIELD') . ': ' . $langs->trans($flabel);
        }
    }
}
if (!empty($ef_entity->attributes['facture']['label'])) {
    foreach ($ef_entity->attributes['facture']['label'] as $fname => $flabel) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $ftype = $ef_entity->attributes['facture']['type'][$fname] ?? '';
        if (in_array($ftype, array('varchar', 'text', 'int'))) {
            $umowy_options['extrafield:' . $fname] = $langs->trans('KSEF_NR_UMOWY_SOURCE_EXTRAFIELD') . ': ' . $langs->trans($flabel);
        }
    }
}
$current_umowy = getDolGlobalString('KSEF_NR_UMOWY_SOURCE', 'disabled');
$umowy_options = ksefSelectKeepCurrent($umowy_options, $current_umowy, $langs->trans('KSEF_FieldMissingOption', preg_replace('/^[a-z_]*extrafield:/', '', $current_umowy)));
print $form->selectarray('KSEF_NR_UMOWY_SOURCE', $umowy_options, $current_umowy, 0, 0, 0, 'onchange="ksefSyncFromDropdown(\'societe\', \'KSEF_NR_UMOWY_SOURCE\', \'nr_umowy\', \'thirdparty_extrafield:\'); ksefSyncFromDropdown(\'facture\', \'KSEF_NR_UMOWY_SOURCE\', \'nr_umowy\', \'extrafield:\')"', 0, 0, 0, '', 'minwidth300');
if (count($umowy_options) <= 1) {
    print '<br><span class="small">' . $langs->trans('KSEF_NR_UMOWY_NO_EXTRAFIELDS') . '</span>';
}
print '<br><label class="paddingtop"><input type="checkbox" name="KSEF_NR_UMOWY_PARSE_DATE" id="KSEF_NR_UMOWY_PARSE_DATE" value="1" ' . (getDolGlobalInt('KSEF_NR_UMOWY_PARSE_DATE') ? 'checked' : '') . '> ' . $langs->trans('KSEF_NR_UMOWY_PARSE_DATE') . '</label>';
print '<br><span class="opacitymedium small">' . $langs->trans('KSEF_NR_UMOWY_PARSE_DATE_Help') . '</span>';
print '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_TP_SOURCE'), $langs->trans('KSEF_TP_SOURCE_Help')) . '</td>';
print '<td>';
$tp_options = array(
    'disabled' => $langs->trans('KSEF_TP_SOURCE_DISABLED'),
);
if (!empty($ef_entity->attributes['societe']['label'])) {
    foreach ($ef_entity->attributes['societe']['label'] as $fname => $flabel) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $ftype = $ef_entity->attributes['societe']['type'][$fname] ?? '';
        if (in_array($ftype, array('boolean', 'select', 'varchar', 'int'))) {
            $tp_options['thirdparty_extrafield:' . $fname] = $langs->trans('KSEF_TP_SOURCE_THIRDPARTY_EXTRAFIELD') . ': ' . $langs->trans($flabel);
        }
    }
}
if (!empty($ef_entity->attributes['facture']['label'])) {
    foreach ($ef_entity->attributes['facture']['label'] as $fname => $flabel) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $ftype = $ef_entity->attributes['facture']['type'][$fname] ?? '';
        if (in_array($ftype, array('boolean', 'select', 'varchar', 'int'))) {
            $tp_options['extrafield:' . $fname] = $langs->trans('KSEF_TP_SOURCE_EXTRAFIELD') . ': ' . $langs->trans($flabel);
        }
    }
}
$current_tp = getDolGlobalString('KSEF_TP_SOURCE', 'disabled');
$tp_options = ksefSelectKeepCurrent($tp_options, $current_tp, $langs->trans('KSEF_FieldMissingOption', preg_replace('/^[a-z_]*extrafield:/', '', $current_tp)));
print $form->selectarray('KSEF_TP_SOURCE', $tp_options, $current_tp, 0, 0, 0, 'onchange="ksefSyncFromDropdown(\'societe\', \'KSEF_TP_SOURCE\', \'tp\', \'thirdparty_extrafield:\'); ksefSyncFromDropdown(\'facture\', \'KSEF_TP_SOURCE\', \'tp\', \'extrafield:\')"', 0, 0, 0, '', 'minwidth300');
print '</td></tr>';

$ksefThirdpartyFlagRows = array(
    array('const' => 'KSEF_P17_TP_SOURCE', 'always_on' => false, 'fkey' => '', 'skey' => 'p17'),
    array('const' => 'KSEF_P18_TP_SOURCE', 'always_on' => false, 'fkey' => '', 'skey' => 'p18'),
);
foreach ($ksefThirdpartyFlagRows as $fr) {
    ksefRenderFlagSourceRow($fr, $form, $langs, $ef_entity);
}

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_IDWEW_SOURCE'), $langs->trans('KSEF_IDWEW_SOURCE_Help')) . '</td>';
print '<td>';
$idwew_options = array(
    'disabled' => $langs->trans('KSEF_IDWEW_SOURCE_DISABLED'),
);
if (!empty($ef_entity->attributes['societe']['label'])) {
    foreach ($ef_entity->attributes['societe']['label'] as $fname => $flabel) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $ftype = $ef_entity->attributes['societe']['type'][$fname] ?? '';
        if (in_array($ftype, array('varchar', 'text'))) {
            $idwew_options['thirdparty_extrafield:' . $fname] = $langs->trans('KSEF_IDWEW_SOURCE_THIRDPARTY_EXTRAFIELD') . ': ' . $langs->trans($flabel);
        }
    }
}
$current_idwew = getDolGlobalString('KSEF_IDWEW_SOURCE', 'disabled');
$idwew_options = ksefSelectKeepCurrent($idwew_options, $current_idwew, $langs->trans('KSEF_FieldMissingOption', preg_replace('/^[a-z_]*extrafield:/', '', $current_idwew)));
print $form->selectarray('KSEF_IDWEW_SOURCE', $idwew_options, $current_idwew, 0, 0, 0, 'onchange="ksefSyncFromDropdown(\'societe\', \'KSEF_IDWEW_SOURCE\', \'idwew\', \'thirdparty_extrafield:\')"', 0, 0, 0, '', 'minwidth300');
print '</td></tr>';
print '</table>';

print '<br><table class="noborder centpercent">';
print '<tr class="liste_titre ksef-section-head"><td colspan="2">' . $langs->trans('KSEF_DODATKOWY_OPIS_PRODUCT_TITLE') . ksefSectionDesc('KSEF_DODATKOWY_OPIS_PRODUCT_TITLE_Desc') . '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_GTU_SOURCE'), $langs->trans('KSEF_GTU_SOURCE_Help')) . '</td>';
print '<td>';
$gtu_options = array(
    'disabled' => $langs->trans('KSEF_GTU_SOURCE_DISABLED'),
);
if (!empty($ef_entity->attributes['product']['label'])) {
    foreach ($ef_entity->attributes['product']['label'] as $fname => $flabel) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $ftype = $ef_entity->attributes['product']['type'][$fname] ?? '';
        if (in_array($ftype, array('varchar', 'select', 'text'))) {
            $gtu_options['product_extrafield:' . $fname] = $langs->trans('KSEF_GTU_SOURCE_PRODUCT_EXTRAFIELD') . ': ' . $langs->trans($flabel);
        }
    }
}
$current_gtu = getDolGlobalString('KSEF_GTU_SOURCE', 'disabled');
$gtu_options = ksefSelectKeepCurrent($gtu_options, $current_gtu, $langs->trans('KSEF_FieldMissingOption', preg_replace('/^[a-z_]*extrafield:/', '', $current_gtu)));
print $form->selectarray('KSEF_GTU_SOURCE', $gtu_options, $current_gtu, 0, 0, 0, 'onchange="ksefSyncFromDropdown(\'product\', \'KSEF_GTU_SOURCE\', \'gtu\', \'product_extrafield:\')"', 0, 0, 0, '', 'minwidth300');
print '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_PROCEDURA_SOURCE'), $langs->trans('KSEF_PROCEDURA_SOURCE_Help')) . '</td>';
print '<td>';
$procedura_options = array(
    'disabled' => $langs->trans('KSEF_PROCEDURA_SOURCE_DISABLED'),
);
if (!empty($ef_entity->attributes['product']['label'])) {
    foreach ($ef_entity->attributes['product']['label'] as $fname => $flabel) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $ftype = $ef_entity->attributes['product']['type'][$fname] ?? '';
        if (in_array($ftype, array('varchar', 'select', 'text'))) {
            $procedura_options['product_extrafield:' . $fname] = $langs->trans('KSEF_PROCEDURA_SOURCE_PRODUCT_EXTRAFIELD') . ': ' . $langs->trans($flabel);
        }
    }
}
$current_procedura = getDolGlobalString('KSEF_PROCEDURA_SOURCE', 'disabled');
$procedura_options = ksefSelectKeepCurrent($procedura_options, $current_procedura, $langs->trans('KSEF_FieldMissingOption', preg_replace('/^[a-z_]*extrafield:/', '', $current_procedura)));
print $form->selectarray('KSEF_PROCEDURA_SOURCE', $procedura_options, $current_procedura, 0, 0, 0, 'onchange="ksefSyncFromDropdown(\'product\', \'KSEF_PROCEDURA_SOURCE\', \'procedura\', \'product_extrafield:\')"', 0, 0, 0, '', 'minwidth300');
print '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_ZWOLNIENIE_PRODUCT_FIELD'), $langs->trans('KSEF_ZWOLNIENIE_PRODUCT_FIELD_Help')) . '</td>';
print '<td>';
$zwolnienie_field_options = array('' => $langs->trans('KSEF_ZWOLNIENIE_PRODUCT_FIELD_DISABLED'));
if (!empty($ef_entity->attributes['product']['label'])) {
    foreach ($ef_entity->attributes['product']['label'] as $fname => $flabel) {
        $ftype = $ef_entity->attributes['product']['type'][$fname] ?? '';
        if (in_array($ftype, array('varchar', 'text', 'select'))) {
            $zwolnienie_field_options[$fname] = $langs->trans($flabel) . ' (' . $fname . ')';
        }
    }
}
$current_zwolnienie_field = getDolGlobalString('KSEF_ZWOLNIENIE_PRODUCT_FIELD', '');
$zwolnienie_field_options = ksefSelectKeepCurrent($zwolnienie_field_options, $current_zwolnienie_field, $langs->trans('KSEF_FieldMissingOption', preg_replace('/^[a-z_]*extrafield:/', '', $current_zwolnienie_field)));
print $form->selectarray('KSEF_ZWOLNIENIE_PRODUCT_FIELD', $zwolnienie_field_options, $current_zwolnienie_field, 0, 0, 0, 'onchange="ksefSyncFromDropdown(\'product\', \'KSEF_ZWOLNIENIE_PRODUCT_FIELD\', \'zwolnienie\', \'\')"', 0, 0, 0, '', 'minwidth300');
print '</td></tr>';
print '</table>';

print '<br><table class="noborder centpercent">';
print '<tr class="liste_titre ksef-section-head"><td colspan="2">' . $langs->trans('KSEF_DODATKOWY_OPIS_DET_TITLE') . ksefSectionDesc('KSEF_DODATKOWY_OPIS_DET_TITLE_Desc') . '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_FA3_INCLUDE_NRKLIENTA'), $langs->trans('KSEF_FA3_INCLUDE_NRKLIENTA_Help')) . '</td>';
print '<td>';
print '<input type="checkbox" name="KSEF_FA3_INCLUDE_NRKLIENTA" id="KSEF_FA3_INCLUDE_NRKLIENTA" value="1" ' . (getDolGlobalInt('KSEF_FA3_INCLUDE_NRKLIENTA') ? 'checked' : '') . '>';
print ' <label for="KSEF_FA3_INCLUDE_NRKLIENTA">' . $langs->trans("KSEF_Enabled") . '</label>';
print '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_FA3_INCLUDE_INDEKS'), $langs->trans('KSEF_FA3_INCLUDE_INDEKS_Help')) . '</td>';
print '<td>';
print '<input type="checkbox" name="KSEF_FA3_INCLUDE_INDEKS" id="KSEF_FA3_INCLUDE_INDEKS" value="1" ' . (getDolGlobalInt('KSEF_FA3_INCLUDE_INDEKS') ? 'checked' : '') . '>';
print ' <label for="KSEF_FA3_INCLUDE_INDEKS">' . $langs->trans("KSEF_Enabled") . '</label>';
print '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_FA3_INCLUDE_GTIN'), $langs->trans('KSEF_FA3_INCLUDE_GTIN_Help')) . '</td>';
print '<td>';
print '<input type="checkbox" name="KSEF_FA3_INCLUDE_GTIN" id="KSEF_FA3_INCLUDE_GTIN" value="1" ' . (getDolGlobalInt('KSEF_FA3_INCLUDE_GTIN') ? 'checked' : '') . '>';
print ' <label for="KSEF_FA3_INCLUDE_GTIN">' . $langs->trans("KSEF_Enabled") . '</label>';
print '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_FA3_INCLUDE_UNIT'), $langs->trans('KSEF_FA3_INCLUDE_UNIT_Help')) . '</td>';
print '<td>';
print '<input type="checkbox" name="KSEF_FA3_INCLUDE_UNIT" id="KSEF_FA3_INCLUDE_UNIT" value="1" ' . (getDolGlobalInt('KSEF_FA3_INCLUDE_UNIT') ? 'checked' : '') . '>';
print ' <label for="KSEF_FA3_INCLUDE_UNIT">' . $langs->trans("KSEF_Enabled") . '</label>';
print '<br><span class="opacitymedium small">' . $langs->trans('KSEF_UnitTextsOnDictionaries', dol_buildpath('/ksef/admin/setup_dictionaries.php', 1) . '#ksef-units') . '</span>';
print '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_FA3_INCLUDE_UU_ID'), $langs->trans('KSEF_FA3_INCLUDE_UU_ID_Help')) . '</td>';
print '<td>';
print '<input type="checkbox" name="KSEF_FA3_INCLUDE_UU_ID" id="KSEF_FA3_INCLUDE_UU_ID" value="1" ' . (getDolGlobalInt('KSEF_FA3_INCLUDE_UU_ID') ? 'checked' : '') . '>';
print ' <label for="KSEF_FA3_INCLUDE_UU_ID">' . $langs->trans("KSEF_Enabled") . '</label>';
print '</td></tr>';
print '</table>';

print '<br><table class="noborder centpercent">';
print '<tr class="liste_titre ksef-section-head"><td colspan="2">' . $langs->trans('KSEF_FA3_CONTENT_SECTION') . ksefSectionDesc('KSEF_FA3_CONTENT_SECTION_Desc') . '</td></tr>';
foreach (array('KSEF_FA3_INCLUDE_WZ', 'KSEF_FA3_PERIOD_FIELDS', 'KSEF_FA3_ORDER_DATE_FIELD', 'KSEF_NR_ZAMOWIENIA_PARSE_DATE') as $_fck) {
    print '<tr class="oddeven"><td class="titlefield">' . $form->textwithpicto($langs->trans($_fck), $langs->trans($_fck . '_Help')) . '</td><td>';
    print '<input type="checkbox" name="' . $_fck . '" id="' . $_fck . '" value="1"' . (getDolGlobalInt($_fck) ? ' checked' : '') . '>';
    print ' <label for="' . $_fck . '">' . $langs->trans('KSEF_Enabled') . '</label></td></tr>';
}
print '<tr class="oddeven"><td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_FA3_INCLUDE_LINE_VAT'), $langs->trans('KSEF_FA3_INCLUDE_LINE_VAT_Help')) . '</td><td>';
print $form->selectarray('KSEF_FA3_INCLUDE_LINE_VAT', array('auto' => $langs->trans('KSEF_FA3_INCLUDE_LINE_VAT_auto'), '1' => $langs->trans('KSEF_FA3_INCLUDE_LINE_VAT_1')), ksefLineVatMode(), 0, 0, 0, '', 0, 0, 0, '', 'minwidth300');
print '</td></tr>';
print '<tr class="oddeven"><td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_FA3_LINE_DATES'), $langs->trans('KSEF_FA3_LINE_DATES_Help')) . '</td><td>';
print $form->selectarray('KSEF_FA3_LINE_DATES', array('disabled' => $langs->trans('KSEF_FA3_LINE_DATES_disabled'), 'auto' => $langs->trans('KSEF_FA3_LINE_DATES_auto')), getDolGlobalString('KSEF_FA3_LINE_DATES', 'disabled'), 0, 0, 0, '', 0, 0, 0, '', 'minwidth300');
print '</td></tr>';
$_fcgross = array('disabled' => $langs->trans('KSEF_SourceDisabled'), 'always_on' => $langs->trans('KSEF_SourceAlwaysOn'));
foreach (array('facture' => 'extrafield:', 'societe' => 'thirdparty_extrafield:') as $_fcel => $_fcprefix) {
    if (!empty($ef_entity->attributes[$_fcel]['label'])) {
        foreach ($ef_entity->attributes[$_fcel]['label'] as $_fcname => $_fclabel) {
            if (strpos($_fcname, 'ksef_') !== 0 && ($ef_entity->attributes[$_fcel]['type'][$_fcname] ?? '') === 'boolean') {
                $_fcgross[$_fcprefix . $_fcname] = $langs->trans($_fcel === 'facture' ? 'KSEF_SourceInvoiceField' : 'KSEF_SourceThirdpartyField') . ': ' . $langs->trans($_fclabel);
            }
        }
    }
}
print '<tr class="oddeven"><td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_FA3_GROSS_SOURCE'), $langs->trans('KSEF_FA3_GROSS_SOURCE_Help')) . '</td><td>';
$_fcgrossCur = getDolGlobalString('KSEF_FA3_GROSS_SOURCE', 'disabled');
$_fcgross = ksefSelectKeepCurrent($_fcgross, $_fcgrossCur, $langs->trans('KSEF_FieldMissingOption', preg_replace('/^[a-z_]*extrafield:/', '', $_fcgrossCur)));
print $form->selectarray('KSEF_FA3_GROSS_SOURCE', $_fcgross, $_fcgrossCur, 0, 0, 0, '', 0, 0, 0, '', 'minwidth300');
if (getDolGlobalInt('MAIN_ROUNDOFTOTAL_NOT_TOTALOFROUND')) {
    print '<br><span class="warning">' . $langs->trans('KSEF_GrossMethodRoundingMode') . '</span>';
}
print '</td></tr>';
print '</table>';

print '<br><table class="noborder centpercent">';
print '<tr class="liste_titre ksef-section-head"><td colspan="2">' . $langs->trans("KSEF_VAT_EXEMPTION_SECTION") . ksefSectionDesc('KSEF_VAT_EXEMPTION_SECTION_Desc') . '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_ZWOLNIENIE_PODSTAWA'), $langs->trans('KSEF_ZWOLNIENIE_PODSTAWA_Help')) . '</td>';
print '<td>';
$zwolnienie_types = array(
    'disabled' => $langs->trans('KSEF_ZWOLNIENIE_TYPE_DISABLED'),
    'P_19A' => $langs->trans('KSEF_ZWOLNIENIE_TYPE_P19A'),
    'P_19B' => $langs->trans('KSEF_ZWOLNIENIE_TYPE_P19B'),
    'P_19C' => $langs->trans('KSEF_ZWOLNIENIE_TYPE_P19C'),
);
$current_zwolnienie_type = getDolGlobalString('KSEF_ZWOLNIENIE_TYPE', 'disabled');
print $form->selectarray('KSEF_ZWOLNIENIE_TYPE', $zwolnienie_types, $current_zwolnienie_type, 0, 0, 0, 'onchange="ksefToggleZwolnienie()"', 0, 0, 0, '', 'minwidth200');
print '<br>';
$zwolnienie_hidden = ($current_zwolnienie_type === 'disabled') ? ' style="display:none;"' : '';
print '<span id="ksef_zwolnienie_text_row"' . $zwolnienie_hidden . '>';
print '<input type="text" name="KSEF_ZWOLNIENIE_PODSTAWA" class="flat minwidth400" maxlength="256" value="' . dol_escape_htmltag(getDolGlobalString('KSEF_ZWOLNIENIE_PODSTAWA', '')) . '" placeholder="' . dol_escape_htmltag($langs->trans('KSEF_ZWOLNIENIE_PODSTAWA_Placeholder')) . '">';
print '</span>';
print '<script>function ksefToggleZwolnienie(){var s=document.getElementById("KSEF_ZWOLNIENIE_TYPE");var d=(s&&s.value==="disabled");document.getElementById("ksef_zwolnienie_text_row").style.display=d?"none":"";}</script>';
print '</td></tr>';

print '</table>';

print '<br><table class="noborder centpercent">';
print '<tr class="liste_titre ksef-section-head"><td colspan="2">' . $langs->trans("KSEF_NOTE_SETTINGS_SECTION") . ksefSectionDesc('KSEF_NOTE_SETTINGS_SECTION_Desc') . '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_STOPKA_BOILERPLATE'), $langs->trans('KSEF_STOPKA_BOILERPLATE_Help')) . '</td>';
print '<td>';
print '<textarea name="KSEF_STOPKA_BOILERPLATE" class="flat minwidth300" rows="4" maxlength="3500">';
print dol_escape_htmltag(getDolGlobalString('KSEF_STOPKA_BOILERPLATE', ''));
print '</textarea>';
print '<br><span class="small">' . $langs->trans('KSEF_STOPKA_BOILERPLATE_Limit') . '</span>';
print '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_DODATKOWY_OPIS_NOTE_MODE'), $langs->trans('KSEF_DODATKOWY_OPIS_NOTE_MODE_Help')) . '</td>';
print '<td>';
$combined_note_modes = array(
    'simple_stopka' => $langs->trans('KSEF_NOTE_COMBINED_SIMPLE_STOPKA'),
    'simple_dodatkowy' => $langs->trans('KSEF_NOTE_COMBINED_SIMPLE_DODATKOWY'),
    'keyvalue_dodatkowy' => $langs->trans('KSEF_NOTE_COMBINED_KEYVALUE_DODATKOWY'),
    'disabled' => $langs->trans('KSEF_DODATKOWY_OPIS_NOTE_MODE_DISABLED'),
);
$_cm = getDolGlobalString('KSEF_DODATKOWY_OPIS_NOTE_MODE', 'simple');
$_ct = getDolGlobalString('KSEF_NOTE_PUBLIC_TARGET', 'stopka_faktury');
if ($_cm === 'disabled') {
    $current_combined_note = 'disabled';
} elseif ($_cm === 'keyvalue') {
    $current_combined_note = 'keyvalue_dodatkowy';
} elseif ($_ct === 'dodatkowy_opis') {
    $current_combined_note = 'simple_dodatkowy';
} else {
    $current_combined_note = 'simple_stopka';
}
print $form->selectarray('KSEF_NOTE_COMBINED_MODE', $combined_note_modes, $current_combined_note, 0, 0, 0, '', 0, 0, 0, '', 'minwidth300');
print '</td></tr>';

print '</table>';

$efTargets = ksefEfTargets();
$efTypes = ksefEfTypes();
$efAssign = array();
$efSourceMap = array(
    'facture' => array('extrafield:' => array('nr_zamowienia' => 'KSEF_NR_ZAMOWIENIA_SOURCE', 'nr_umowy' => 'KSEF_NR_UMOWY_SOURCE', 'tp' => 'KSEF_TP_SOURCE',
        'mpp' => 'KSEF_FA3_MPP_SOURCE', 'fp' => 'KSEF_FA3_FP_SOURCE', 'p17' => 'KSEF_P17_SOURCE', 'p18' => 'KSEF_P18_SOURCE')),
    'societe' => array('thirdparty_extrafield:' => array('idnabywcy' => 'KSEF_IDNABYWCY_SOURCE', 'nr_umowy' => 'KSEF_NR_UMOWY_SOURCE', 'tp' => 'KSEF_TP_SOURCE',
        'idwew' => 'KSEF_IDWEW_SOURCE', 'p17' => 'KSEF_P17_TP_SOURCE', 'p18' => 'KSEF_P18_TP_SOURCE')),
    'product' => array('product_extrafield:' => array('gtu' => 'KSEF_GTU_SOURCE', 'procedura' => 'KSEF_PROCEDURA_SOURCE'), '' => array('zwolnienie' => 'KSEF_ZWOLNIENIE_PRODUCT_FIELD')),
);
foreach ($efSourceMap as $_el => $_byPrefix) {
    foreach ($_byPrefix as $_prefix => $_map) {
        foreach ($_map as $_key => $_const) {
            $_v = getDolGlobalString($_const);
            if ($_v !== '' && ($_prefix === '' || strpos($_v, $_prefix) === 0)) {
                $efAssign[$_el][substr($_v, strlen($_prefix))] = $_key;
            }
        }
    }
}
foreach ($efTargets as $_el => $_t) {
    $_cfg = getDolGlobalString($_t[1]);
    $_lst = in_array($_el, array('facture', 'societe', 'projet'), true) ? ksefParseEfConfig($_cfg) : array_fill_keys(array_filter(array_map('trim', explode(',', $_cfg))), 'dodatkowy');
    foreach ($_lst as $_f => $_m) {
        if (!isset($efAssign[$_el][$_f])) {
            $efAssign[$_el][$_f] = $_m;
        }
    }
}

print '<br><table class="noborder centpercent">';
print '<tr class="liste_titre ksef-section-head"><td colspan="5">';
print $langs->trans('KSEF_EfTitle') . ksefSectionDesc('KSEF_EfTitle_Desc') . '</td>';
print '<td class="right nowraponall">' . dolGetButtonTitle($langs->trans('KSEF_EfNew'), '', 'fa fa-plus-circle', '#', 'ksef-ef-new', 1) . '</td></tr>';
print '<tr class="liste_titre" id="ksef-extrafields">';
print '<td>' . $langs->trans('KSEF_EfColField') . '</td><td class="hideonsmartphone">' . $langs->trans('KSEF_EfColCode') . '</td><td>' . $langs->trans('KSEF_EfWhere') . '</td>';
print '<td class="hideonsmartphone">' . $langs->trans('KSEF_EfType') . '</td><td>' . $langs->trans('KSEF_EfOnInvoice') . '</td><td></td></tr>';
$efAny = false;
foreach ($efTargets as $_el => $_t) {
    $attrs = $ef_entity->attributes[$_el] ?? array();
    foreach (($attrs['label'] ?? array()) as $fname => $flabel) {
        $ftype = $attrs['type'][$fname] ?? '';
        if (strpos($fname, 'ksef_') === 0 || ($ftype !== 'boolean' && in_array($ftype, $_dodUnsupportedTypes))) continue;
        // module boxes system
        if ($_el === 'societe' && in_array($fname, array('is_vat_group', 'is_jst'), true) && $ftype === 'boolean'
            && ($attrs['langfile'][$fname] ?? '') === 'ksef@ksef') continue;
        $efAny = true;
        $isBool = ($ftype === 'boolean');
        $opts = array('' => $langs->trans('KSEF_ASSIGN_NONE'));
        if (!$isBool) {
            $opts['dodatkowy'] = $langs->trans('KSEF_ASSIGN_DODATKOWY');
            if (!in_array($_el, array('facturedet', 'product'), true)) {
                $opts['stopka'] = $langs->trans('KSEF_ASSIGN_STOPKA');
            }
        }
        $txt = in_array($ftype, array('varchar', 'text', 'int'), true);
        $flag = in_array($ftype, array('boolean', 'select', 'varchar', 'int'), true);
        if ($_el === 'facture') {
            if ($txt) {
                $opts['nr_zamowienia'] = $langs->trans('KSEF_ASSIGN_NR_ZAMOWIENIA');
                $opts['nr_umowy'] = $langs->trans('KSEF_ASSIGN_NR_UMOWY');
            }
            if ($flag) {
                foreach (array('tp', 'mpp', 'fp', 'p17', 'p18') as $_k) {
                    $opts[$_k] = $langs->trans('KSEF_ASSIGN_' . strtoupper($_k));
                }
            }
        } elseif ($_el === 'societe') {
            if ($txt) {
                $opts['idnabywcy'] = $langs->trans('KSEF_ASSIGN_IDNABYWCY');
                $opts['nr_umowy'] = $langs->trans('KSEF_ASSIGN_NR_UMOWY');
            }
            if (in_array($ftype, array('varchar', 'text'), true)) {
                $opts['idwew'] = $langs->trans('KSEF_ASSIGN_IDWEW');
            }
            if ($flag) {
                foreach (array('tp', 'p17', 'p18') as $_k) {
                    $opts[$_k] = $langs->trans('KSEF_ASSIGN_' . strtoupper($_k));
                }
            }
        } elseif ($_el === 'product' && in_array($ftype, array('varchar', 'select', 'text'), true)) {
            foreach (array('gtu', 'procedura', 'zwolnienie') as $_k) {
                $opts[$_k] = $langs->trans('KSEF_ASSIGN_' . strtoupper($_k));
            }
        }
        $cur = $efAssign[$_el][$fname] ?? '';
        $synced = in_array($_el, array('facture', 'societe', 'product'), true);
        $translatedLabel = $langs->trans($flabel);
        $isOurs = (($attrs['langfile'][$fname] ?? '') === 'ksef@ksef');

        print '<tr class="oddeven">';
        print '<td>' . dol_escape_htmltag($translatedLabel) . ($isOurs ? '' : ' <span class="badge badge-secondary" title="' . dol_escape_htmltag($langs->trans('KSEF_EXTRAFIELD_EXTERNAL')) . '">ext</span>') . '</td>';
        print '<td class="hideonsmartphone"><code>' . dol_escape_htmltag($fname) . '</code></td>';
        print '<td>' . $langs->trans($_t[0]) . '</td>';
        print '<td class="hideonsmartphone">' . (isset($efTypes[$ftype]) ? $langs->trans($efTypes[$ftype]) : dol_escape_htmltag($ftype)) . '</td>';
        print '<td><select name="KSEF_ASSIGN_' . strtoupper($_el) . '_' . dol_escape_htmltag($fname) . '" class="flat maxwidth200 maxwidth150onsmartphone' . ($synced ? ' ksef-assign-' . $_el : '') . '" data-field="' . dol_escape_htmltag($fname) . '"'
            . ($synced ? ' onchange="ksefSyncFromRow(\'' . $_el . '\', this)"' : '') . '>';
        foreach ($opts as $aval => $alabel) {
            print '<option value="' . dol_escape_htmltag($aval) . '"' . ($cur === $aval ? ' selected' : '') . '>' . $alabel . '</option>';
        }
        print '</select></td>';
        print '<td class="right nowraponall">';
        if ($isOurs && !ksefEfReserved($_el, $fname)) {
            $optLines = array();
            foreach (($attrs['param'][$fname]['options'] ?? array()) as $ocode => $olabel) {
                $optLines[] = $ocode . '|' . $olabel;
            }
            $size = (string) ($attrs['size'][$fname] ?? '');
            $uses = ksefEfSourceUses($_el, $fname);
            print '<a href="#" class="ksef-ef-edit marginrightonly" data-code="' . dol_escape_htmltag($fname) . '" data-label="' . dol_escape_htmltag($translatedLabel) . '"';
            print ' data-type="' . dol_escape_htmltag($ftype) . '" data-target="' . $_el . '" data-options="' . dol_escape_htmltag(implode("\n", $optLines), 0, 1) . '"';
            print ' data-size="' . ($ftype === 'varchar' ? (int) $size : '') . '" data-decimals="' . (preg_match('/^\d+,(\d+)$/', $size, $_m) ? (int) $_m[1] : '') . '"';
            print ' data-on="' . (in_array($cur, array('dodatkowy', 'stopka'), true) ? $cur : 'none') . '"';
            print ' data-source="' . dol_escape_htmltag(implode(', ', array_map(array($langs, 'transnoentitiesnoconv'), $uses))) . '"';
            print ' data-used="' . ksefEfInvoiceUseCount($db, $_el, $fname, $ftype) . '">' . img_edit() . '</a>';
            print '<a href="' . $_SERVER["PHP_SELF"] . '?action=ask_delete_dodatkowy_extrafield&ef_target=' . $_el . '&ef_code=' . urlencode($fname) . '&token=' . newToken() . '" class="ksef-ef-delete" data-code="' . dol_escape_htmltag($fname) . '" data-target="' . $_el . '">' . img_delete() . '</a>';
        }
        print '</td></tr>';
    }
}
if (!$efAny) {
    print '<tr class="oddeven"><td colspan="6"><span class="opacitymedium">' . $langs->trans('KSEF_EfNone') . '</span></td></tr>';
}
print '</table>';

print '</div>';

print '<div class="center" style="margin-top: 10px;">';
print '<input type="submit" class="button button-save" value="' . $langs->trans("Save") . '">';
print '</div>';
print '</form>';

print '<div id="ksef-ef-dialog" class="hideobject">';
print '<form id="ksef-ef-form" method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="create_dodatkowy_extrafield">';
print '<input type="hidden" name="ef_code" value="">';
print '<input type="hidden" name="ef_options" value="">';
print '<p class="ksef-ef-intro opacitymedium"></p>';
print '<div class="error ksef-ef-err hideobject"></div>';
print '<table class="border centpercent">';
print '<tr><td class="fieldrequired titlefieldmiddle"><label for="ksef_ef_label">' . $langs->trans('KSEF_EfName') . '</label></td><td>';
print '<input type="text" id="ksef_ef_label" name="ef_label" class="flat minwidth200 maxwidth300 widthcentpercentminusx" maxlength="255">';
print '<div class="opacitymedium small">' . $langs->trans('KSEF_EfColCode') . ': <code class="ksef-ef-codeshow"></code></div></td></tr>';
print '<tr><td>' . $langs->trans('KSEF_EfWhere') . '</td><td><select id="ksef_ef_target" name="ef_target" class="flat minwidth200">';
foreach ($efTargets as $_el => $_t) {
    print '<option value="' . $_el . '" data-hint="' . dol_escape_htmltag($langs->trans('KSEF_EfWhereHint_' . $_el)) . '">' . $langs->trans($_t[0]) . '</option>';
}
print '</select><strong class="ksef-ef-target-fixed"></strong><div class="opacitymedium small ksef-ef-hint-target"></div></td></tr>';
print '<tr><td><label for="ksef_ef_type">' . $langs->trans('KSEF_EfType') . '</label></td><td><select id="ksef_ef_type" name="ef_type" class="flat minwidth200">';
foreach ($efTypes as $_ty => $_k) {
    print '<option value="' . $_ty . '" data-hint="' . dol_escape_htmltag($langs->trans('KSEF_EfTypeHint_' . $_ty)) . '">' . $langs->trans($_k) . '</option>';
}
print '</select><div class="opacitymedium small ksef-ef-hint-type"></div></td></tr>';
print '<tr class="ksef-ef-for ksef-ef-for-varchar"><td><label for="ksef_ef_size">' . $langs->trans('KSEF_EfMaxLength') . '</label></td><td>';
print '<input type="number" id="ksef_ef_size" name="ef_size" class="flat width75" min="1" max="255" value="255"> ' . $langs->trans('KSEF_EfMaxLengthUnit');
print '<div class="opacitymedium small">' . $langs->trans('KSEF_EfMaxLengthHint') . '</div></td></tr>';
print '<tr class="ksef-ef-for ksef-ef-for-double"><td><label for="ksef_ef_decimals">' . $langs->trans('KSEF_EfDecimals') . '</label></td><td>';
print '<input type="number" id="ksef_ef_decimals" name="ef_decimals" class="flat width75" min="0" max="8" value="2">';
print '<div class="opacitymedium small">' . $langs->trans('KSEF_EfDecimalsHint') . '</div></td></tr>';
print '<tr class="ksef-ef-for ksef-ef-for-select"><td>' . $langs->trans('KSEF_EfOptions') . '</td><td>';
print '<div class="ksef-ef-opts"></div>';
print '<a href="#" class="ksef-ef-addopt">' . img_picto('', 'add', 'class="paddingright"') . $langs->trans('KSEF_EfOptAdd') . '</a>';
print '<div class="opacitymedium small">' . $langs->trans('KSEF_EfOptHint') . '</div></td></tr>';
print '<tr><td><label for="ksef_ef_on">' . $langs->trans('KSEF_EfOnInvoice') . '</label></td><td><select id="ksef_ef_on" name="ef_on_invoice" class="flat minwidth200">';
foreach (array('none', 'dodatkowy', 'stopka') as $_on) {
    print '<option value="' . $_on . '" data-hint="' . dol_escape_htmltag($langs->trans('KSEF_EfOnHint_' . $_on)) . '"' . ($_on === 'dodatkowy' ? ' selected' : '') . '>' . $langs->trans('KSEF_EfOn_' . $_on) . '</option>';
}
print '</select><span class="ksef-ef-source"></span><div class="opacitymedium small ksef-ef-hint-on"></div></td></tr>';
print '</table>';
print '</form>';
print '</div>';
print '<div id="ksef-ef-used-confirm" class="hideobject" title="' . dol_escape_htmltag($langs->trans('KSEF_EfEditTitle')) . '"><p></p></div>';

$efJs = array(
    'newTitle' => $langs->transnoentitiesnoconv('KSEF_EfNew'),
    'editTitle' => $langs->transnoentitiesnoconv('KSEF_EfEditTitle'),
    'intro' => $langs->transnoentitiesnoconv('KSEF_EfDialogIntro'),
    'introEdit' => $langs->transnoentitiesnoconv('KSEF_EfDialogIntroEdit', '%s'),
    'create' => $langs->transnoentitiesnoconv('KSEF_EfCreate'),
    'save' => $langs->transnoentitiesnoconv('Save'),
    'cancel' => $langs->transnoentitiesnoconv('Cancel'),
    'yes' => $langs->transnoentitiesnoconv('Yes'),
    'no' => $langs->transnoentitiesnoconv('No'),
    'optName' => $langs->transnoentitiesnoconv('KSEF_EfOptName'),
    'optCode' => $langs->transnoentitiesnoconv('KSEF_EfOptCode'),
    'optKeep' => $langs->transnoentitiesnoconv('KSEF_EfOptKeep'),
    'optInvalid' => $langs->transnoentitiesnoconv('KSEF_EfOptInvalid'),
    'nameRequired' => $langs->transnoentitiesnoconv('KSEF_DODATKOWY_OPIS_CREATE_FIELD_ERROR_LABEL'),
    'noFooter' => $langs->transnoentitiesnoconv('KSEF_EfOnNoFooter'),
    'boolHint' => $langs->transnoentitiesnoconv('KSEF_EfOnBoolean'),
    'sourceIs' => $langs->transnoentitiesnoconv('KSEF_EfSourceIs', '%s'),
    'used' => $langs->transnoentitiesnoconv('KSEF_EfUsedWarn', '%s'),
    'usedOne' => $langs->transnoentitiesnoconv('KSEF_EfUsedWarnOne'),
    'usedType' => $langs->transnoentitiesnoconv('KSEF_EfUsedWarnType', '%s'),
    'usedTypeOne' => $langs->transnoentitiesnoconv('KSEF_EfUsedWarnTypeOne'),
    'del' => img_delete(),
);
print '<script>
var ksefFieldSourceMap = {
    facture: {
        nr_zamowienia: {dropdown: "KSEF_NR_ZAMOWIENIA_SOURCE", prefix: "extrafield:"},
        nr_umowy: {dropdown: "KSEF_NR_UMOWY_SOURCE", prefix: "extrafield:"},
        tp: {dropdown: "KSEF_TP_SOURCE", prefix: "extrafield:"},
        mpp: {dropdown: "KSEF_FA3_MPP_SOURCE", prefix: "extrafield:"},
        fp: {dropdown: "KSEF_FA3_FP_SOURCE", prefix: "extrafield:"},
        p17: {dropdown: "KSEF_P17_SOURCE", prefix: "extrafield:"},
        p18: {dropdown: "KSEF_P18_SOURCE", prefix: "extrafield:"}
    },
    societe: {
        idnabywcy: {dropdown: "KSEF_IDNABYWCY_SOURCE", prefix: "thirdparty_extrafield:"},
        nr_umowy: {dropdown: "KSEF_NR_UMOWY_SOURCE", prefix: "thirdparty_extrafield:"},
        tp: {dropdown: "KSEF_TP_SOURCE", prefix: "thirdparty_extrafield:"},
        idwew: {dropdown: "KSEF_IDWEW_SOURCE", prefix: "thirdparty_extrafield:"},
        p17: {dropdown: "KSEF_P17_TP_SOURCE", prefix: "thirdparty_extrafield:"},
        p18: {dropdown: "KSEF_P18_TP_SOURCE", prefix: "thirdparty_extrafield:"}
    },
    product: {
        gtu: {dropdown: "KSEF_GTU_SOURCE", prefix: "product_extrafield:"},
        procedura: {dropdown: "KSEF_PROCEDURA_SOURCE", prefix: "product_extrafield:"},
        zwolnienie: {dropdown: "KSEF_ZWOLNIENIE_PRODUCT_FIELD", prefix: ""}
    }
};

function ksefSyncFromRow(entity, sel) {
    var fieldName = sel.dataset.field;
    var assignVal = sel.value;
    var map = ksefFieldSourceMap[entity] || {};
    if (map[assignVal]) {
        var cfg = map[assignVal];
        var dd = document.querySelector("select[name=\"" + cfg.dropdown + "\"]");
        if (dd) {
            var newVal = cfg.prefix + fieldName;
            if (!Array.prototype.some.call(dd.options, function(o) { return o.value === newVal; })) {
                var opt = document.createElement("option");
                opt.value = newVal;
                opt.text = fieldName;
                dd.appendChild(opt);
            }
            dd.value = newVal;
        }
        document.querySelectorAll("select.ksef-assign-" + entity).forEach(function(other) {
            if (other !== sel && other.value === assignVal) {
                other.value = "";
            }
        });
    }
    if (["", "dodatkowy", "stopka"].indexOf(assignVal) !== -1) {
        Object.keys(map).forEach(function(srcKey) {
            var cfg = map[srcKey];
            var dd = document.querySelector("select[name=\"" + cfg.dropdown + "\"]");
            if (dd && dd.value === cfg.prefix + fieldName) {
                dd.value = "disabled";
            }
        });
    }
}

function ksefSyncFromDropdown(entity, dropdownName, assignKey, prefix) {
    var dd = document.querySelector("select[name=\"" + dropdownName + "\"]");
    if (!dd) return;
    var val = dd.value;
    var rows = document.querySelectorAll("select.ksef-assign-" + entity);
    rows.forEach(function(sel) {
        if (sel.value === assignKey) {
            sel.value = "";
        }
    });
    var efName = prefix !== "" ? (val.indexOf(prefix) === 0 ? val.substring(prefix.length) : "") : (val !== "disabled" ? val : "");
    if (efName !== "") {
        rows.forEach(function(sel) {
            if (sel.dataset.field === efName) {
                sel.value = assignKey;
            }
        });
    }
}

document.addEventListener("DOMContentLoaded", function() {
    Object.keys(ksefFieldSourceMap).forEach(function(entity) {
        var map = ksefFieldSourceMap[entity];
        Object.keys(map).forEach(function(assignKey) {
            var cfg = map[assignKey];
            var dd = document.querySelector("select[name=\"" + cfg.dropdown + "\"]");
            if (dd) {
                dd.addEventListener("change", function() {
                    ksefSyncFromDropdown(entity, cfg.dropdown, assignKey, cfg.prefix);
                });
            }
        });
    });
});

jQuery(function($) {
    var T = ' . json_encode($efJs) . ';
    var $d = $("#ksef-ef-dialog"), $f = $("#ksef-ef-form"), edit = null;
    var PL = {"ą":"a","ć":"c","ę":"e","ł":"l","ń":"n","ó":"o","ś":"s","ź":"z","ż":"z","Ą":"A","Ć":"C","Ę":"E","Ł":"L","Ń":"N","Ó":"O","Ś":"S","Ź":"Z","Ż":"Z"};
    function slug(s) {
        s = String(s || "").replace(/[ąćęłńóśźżĄĆĘŁŃÓŚŹŻ]/g, function(c) { return PL[c]; }).normalize("NFD").replace(/[̀-ͯ]/g, "");
        return s.replace(/[^a-z0-9]+/gi, "_").toLowerCase().replace(/^_+|_+$/g, "");
    }
    function fieldCode(label) {
        var c = slug(label);
        if (c.indexOf("ksef_") === 0) c = c.substring(5);
        if (c.indexOf("cf_") !== 0) c = "cf_" + c;
        return c.substring(0, 50).replace(/_+$/, "");
    }
    function hint(sel, box) {
        var o = $(sel).find("option:selected");
        $f.find(box).text(o.data("hint") || "");
    }
    function addOpt(label, code, keep) {
        var $r = $("<div class=\"ksef-ef-opt nowraponall paddingbottom\"></div>");
        var $l = $("<input type=\"text\" class=\"flat ksef-ef-opt-label maxwidth150\">").attr("placeholder", T.optName).val(label || "");
        var $c = $("<input type=\"text\" class=\"flat ksef-ef-opt-code maxwidth100\">").attr("placeholder", T.optCode).val(code || "");
        if (keep) {
            $c.prop("readonly", true).addClass("opacitymedium").attr("title", T.optKeep).attr("data-orig", code).attr("data-orig-label", label);
        }
        var $x = $("<a href=\"#\" class=\"ksef-ef-opt-del paddingleft\"></a>").html(T.del);
        $l.on("input", function() { if (!keep) $c.attr("placeholder", slug($l.val()) || T.optCode); });
        $x.on("click", function(e) { e.preventDefault(); $r.remove(); });
        $r.append($l, " ", $c, $x);
        $f.find(".ksef-ef-opts").append($r);
    }
    function sync() {
        var type = $f.find("[name=ef_type]").val(), target = $f.find("[name=ef_target]").val();
        $f.find(".ksef-ef-for").hide();
        $f.find(".ksef-ef-for-" + type).show();
        if (type === "select" && !$f.find(".ksef-ef-opt").length) { addOpt(); addOpt(); }
        hint("#ksef_ef_type", ".ksef-ef-hint-type");
        hint("#ksef_ef_target", ".ksef-ef-hint-target");
        var $on = $f.find("#ksef_ef_on"), noFoot = (target === "facturedet" || target === "product"), isBool = (type === "boolean");
        $on.find("option[value=stopka]").prop("disabled", noFoot || isBool);
        $on.find("option[value=dodatkowy]").prop("disabled", isBool);
        if ($on.find("option:selected").prop("disabled")) $on.val(isBool ? "none" : "dodatkowy");
        hint("#ksef_ef_on", ".ksef-ef-hint-on");
        var extra = isBool ? T.boolHint : (noFoot ? T.noFooter : "");
        if (extra) $f.find(".ksef-ef-hint-on").append(document.createTextNode(" " + extra));
        if (!edit) $f.find(".ksef-ef-codeshow").text(fieldCode($f.find("[name=ef_label]").val()));
    }
    function open(btn) {
        edit = btn ? $(btn).data() : null;
        $f[0].reset();
        $f.find(".ksef-ef-opts").empty();
        $f.find(".ksef-ef-err").addClass("hideobject").text("");
        $f.find("[name=action]").val(edit ? "edit_dodatkowy_extrafield" : "create_dodatkowy_extrafield");
        $f.find("[name=ef_code]").val(edit ? edit.code : "");
        $f.find("#ksef_ef_target").toggle(!edit);
        $f.find(".ksef-ef-target-fixed").text(edit ? $("#ksef_ef_target option[value=" + edit.target + "]").text() : "");
        $f.find(".ksef-ef-source").text("");
        $f.find("#ksef_ef_on").show().prop("disabled", false);
        if (edit) {
            $f.find("[name=ef_label]").val(edit.label);
            $f.find("[name=ef_target]").val(edit.target);
            $f.find("[name=ef_type]").val(edit.type);
            if (edit.size) $f.find("[name=ef_size]").val(edit.size);
            if (edit.decimals !== "") $f.find("[name=ef_decimals]").val(edit.decimals);
            String(edit.options || "").split("\n").forEach(function(line) {
                if (line === "") return;
                var i = line.indexOf("|");
                addOpt(line.substring(i + 1), line.substring(0, i), true);
            });
            $f.find(".ksef-ef-codeshow").text(edit.code);
            $f.find(".ksef-ef-intro").text(T.introEdit.replace("%s", edit.label));
            if (edit.source) {
                $f.find("#ksef_ef_on").hide().prop("disabled", true);
                $f.find(".ksef-ef-source").text(T.sourceIs.replace("%s", edit.source));
            } else {
                $f.find("#ksef_ef_on").val(edit.on);
            }
        } else {
            $f.find(".ksef-ef-intro").text(T.intro);
        }
        sync();
        if (edit && edit.source) $f.find(".ksef-ef-hint-on").text("");
        $d.removeClass("hideobject").dialog({
            title: edit ? T.editTitle : T.newTitle,
            modal: true,
            width: Math.min(640, $(window).width() - 20),
            buttons: [
                {text: edit ? T.save : T.create, "class": "button", click: submit},
                {text: T.cancel, "class": "button button-cancel", click: function() { $(this).dialog("close"); }}
            ]
        });
        $f.find("[name=ef_label]").trigger("focus");
    }
    function fail(msg, $el) {
        $f.find(".ksef-ef-err").removeClass("hideobject").text(msg);
        if ($el) $el.addClass("error").one("input", function() { $el.removeClass("error"); });
    }
    function submit() {
        $f.find(".ksef-ef-err").addClass("hideobject");
        var $label = $f.find("[name=ef_label]");
        if ($.trim($label.val()) === "") { fail(T.nameRequired, $label); return; }
        var type = $f.find("[name=ef_type]").val(), lines = [], seen = {}, bad = null, now = {};
        if (type === "select") {
            $f.find(".ksef-ef-opt").each(function() {
                var $l = $(this).find(".ksef-ef-opt-label"), $c = $(this).find(".ksef-ef-opt-code");
                var l = $.trim($l.val()), c = $.trim($c.val());
                if (l === "" && c === "") return;
                if (c === "") c = slug(l);
                if (l === "" || c === "" || seen[c]) { bad = bad || (l === "" ? $l : $c); return; }
                seen[c] = 1;
                now[c] = l;
                lines.push(c + "|" + l);
            });
            if (bad) { fail(T.optInvalid, bad); return; }
        }
        $f.find("[name=ef_options]").val(lines.join("\n"));
        var optChanged = false, colChanged = false, n = edit ? parseInt(edit.used, 10) || 0 : 0;
        if (edit) {
            colChanged = type !== edit.type
                || (type === "varchar" && edit.size !== "" && parseInt($f.find("[name=ef_size]").val(), 10) < parseInt(edit.size, 10))
                || (type === "double" && edit.decimals !== "" && parseInt($f.find("[name=ef_decimals]").val(), 10) < parseInt(edit.decimals, 10));
        }
        if (edit && edit.type === "select" && type === "select") {
            String(edit.options || "").split("\n").forEach(function(line) {
                var i = line.indexOf("|");
                if (line !== "" && now[line.substring(0, i)] !== line.substring(i + 1)) optChanged = true;
            });
        }
        if ((optChanged || colChanged) && n > 0) {
            var $c = $("#ksef-ef-used-confirm");
            var msg = colChanged ? (n === 1 ? T.usedTypeOne : T.usedType) : (n === 1 ? T.usedOne : T.used);
            $c.find("p").text(msg.replace("%s", n));
            $c.removeClass("hideobject").dialog({
                modal: true,
                width: Math.min(500, $(window).width() - 20),
                buttons: [
                    {text: T.yes, "class": "button", click: function() { $f[0].submit(); }},
                    {text: T.no, "class": "button button-cancel", click: function() { $(this).dialog("close"); }}
                ]
            });
            return;
        }
        $f[0].submit();
    }
    $f.on("change", "select", sync);
    $f.on("input", "[name=ef_label]", sync);
    $f.on("click", ".ksef-ef-addopt", function(e) { e.preventDefault(); addOpt(); });
    $f.on("submit", function(e) { e.preventDefault(); submit(); });
    $("#ksef-ef-new").on("click", function(e) { e.preventDefault(); open(null); });
    $(".ksef-ef-edit").on("click", function(e) { e.preventDefault(); open(this); });
});
</script>';

print dol_get_fiche_end();

print ksefAdminDirtyGuardJs();
print ksefCollapsibleSectionsJs();
print ksefHelpFooter($db, $user);
llxFooter();
