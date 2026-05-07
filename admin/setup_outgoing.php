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
 * \file    admin/setup_outgoing.php
 * \ingroup ksef
 * \brief   KSEF outgoing invoice settings tab
 */

// CSRF Check
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
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
dol_include_once('/ksef/lib/ksef.lib.php');
dol_include_once('/core/class/extrafields.class.php');

if (!$user->admin) {
    accessforbidden();
}

$langs->loadLangs(array("admin", "ksef@ksef"));

$action = GETPOST('action', 'aZ09');

if ($action == 'update') {
    // Optional Fields
    $fa3_nrklienta_val = GETPOST('KSEF_FA3_INCLUDE_NRKLIENTA', 'alpha') ? '1' : '0';
    dolibarr_set_const($db, 'KSEF_FA3_INCLUDE_NRKLIENTA', $fa3_nrklienta_val, 'chaine', 0, '', $conf->entity);

    $fa3_indeks_val = GETPOST('KSEF_FA3_INCLUDE_INDEKS', 'alpha') ? '1' : '0';
    dolibarr_set_const($db, 'KSEF_FA3_INCLUDE_INDEKS', $fa3_indeks_val, 'chaine', 0, '', $conf->entity);

    $fa3_gtin_val = GETPOST('KSEF_FA3_INCLUDE_GTIN', 'alpha') ? '1' : '0';
    dolibarr_set_const($db, 'KSEF_FA3_INCLUDE_GTIN', $fa3_gtin_val, 'chaine', 0, '', $conf->entity);

    $fa3_unit_val = GETPOST('KSEF_FA3_INCLUDE_UNIT', 'alpha') ? '1' : '0';
    dolibarr_set_const($db, 'KSEF_FA3_INCLUDE_UNIT', $fa3_unit_val, 'chaine', 0, '', $conf->entity);

    $fa3_bankdesc_val = GETPOST('KSEF_FA3_INCLUDE_BANK_DESC', 'alpha') ? '1' : '0';
    dolibarr_set_const($db, 'KSEF_FA3_INCLUDE_BANK_DESC', $fa3_bankdesc_val, 'chaine', 0, '', $conf->entity);

    // Place of Issue
    $place_of_issue_mode = GETPOST('KSEF_FA3_PLACE_OF_ISSUE_MODE', 'alpha');
    if (in_array($place_of_issue_mode, array('disabled', 'company', 'custom'))) {
        dolibarr_set_const($db, 'KSEF_FA3_PLACE_OF_ISSUE_MODE', $place_of_issue_mode, 'chaine', 0, '', $conf->entity);
    }
    $place_of_issue_custom = GETPOST('KSEF_FA3_PLACE_OF_ISSUE_CUSTOM', 'alphanohtml');
    dolibarr_set_const($db, 'KSEF_FA3_PLACE_OF_ISSUE_CUSTOM', trim($place_of_issue_custom), 'chaine', 0, '', $conf->entity);

    // Sale Date Source
    $sale_date_source = GETPOST('KSEF_FA3_SALE_DATE_SOURCE', 'alpha');
    if (in_array($sale_date_source, array('invoice_date', 'delivery_date'))) {
        dolibarr_set_const($db, 'KSEF_FA3_SALE_DATE_SOURCE', $sale_date_source, 'chaine', 0, '', $conf->entity);
    }

    // NrZamowienia source
    $nr_zamowienia_source = GETPOST('KSEF_NR_ZAMOWIENIA_SOURCE', 'alphanohtml');
    if (in_array($nr_zamowienia_source, array('ref_client', 'linked_order', 'disabled')) || strpos($nr_zamowienia_source, 'extrafield:') === 0) {
        dolibarr_set_const($db, 'KSEF_NR_ZAMOWIENIA_SOURCE', $nr_zamowienia_source, 'chaine', 0, '', $conf->entity);
    }

    // NrUmowy source
    $nr_umowy_source = GETPOST('KSEF_NR_UMOWY_SOURCE', 'alphanohtml');
    if ($nr_umowy_source === 'disabled' || strpos($nr_umowy_source, 'thirdparty_extrafield:') === 0 || strpos($nr_umowy_source, 'extrafield:') === 0) {
        dolibarr_set_const($db, 'KSEF_NR_UMOWY_SOURCE', $nr_umowy_source, 'chaine', 0, '', $conf->entity);
    }

    // VAT Exemption legal basis
    $zwolnienie_type = GETPOST('KSEF_ZWOLNIENIE_TYPE', 'alpha');
    if (in_array($zwolnienie_type, array('disabled', 'P_19A', 'P_19B', 'P_19C'))) {
        dolibarr_set_const($db, 'KSEF_ZWOLNIENIE_TYPE', $zwolnienie_type, 'chaine', 0, '', $conf->entity);
    }
    $zwolnienie_podstawa = GETPOST('KSEF_ZWOLNIENIE_PODSTAWA', 'alphanohtml');
    dolibarr_set_const($db, 'KSEF_ZWOLNIENIE_PODSTAWA', mb_substr(trim($zwolnienie_podstawa), 0, 256, 'UTF-8'), 'chaine', 0, '', $conf->entity);

    $zwolnienie_product_field = GETPOST('KSEF_ZWOLNIENIE_PRODUCT_FIELD', 'alphanohtml');
    dolibarr_set_const($db, 'KSEF_ZWOLNIENIE_PRODUCT_FIELD', trim($zwolnienie_product_field), 'chaine', 0, '', $conf->entity);

    // QR Code checkbox
    $qr_val = GETPOST('KSEF_ADD_QR', 'alpha') ? '1' : '0';
    dolibarr_set_const($db, 'KSEF_ADD_QR', $qr_val, 'chaine', 0, '', $conf->entity);

    // Disable Validate and Upload
    $disable_validate_and_upload = GETPOST('KSEF_DISABLE_VALIDATE_AND_UPLOAD', 'alpha') ? '1' : '0';
    dolibarr_set_const($db, 'KSEF_DISABLE_VALIDATE_AND_UPLOAD', $disable_validate_and_upload, 'chaine', 0, '', $conf->entity);

    // Payment defaults
    dolibarr_set_const($db, 'KSEF_DEFAULT_PAYMENT_TERM_ID', GETPOST('KSEF_DEFAULT_PAYMENT_TERM_ID', 'int'), 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'KSEF_DEFAULT_PAYMENT_METHOD_ID', GETPOST('KSEF_DEFAULT_PAYMENT_METHOD_ID', 'int'), 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'KSEF_DEFAULT_BANK_ACCOUNT_ID', GETPOST('KSEF_DEFAULT_BANK_ACCOUNT_ID', 'int'), 'chaine', 0, '', $conf->entity);

    // Note mode
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

    // StopkaFaktury boilerplate
    $stopka_boilerplate = GETPOST('KSEF_STOPKA_BOILERPLATE', 'restricthtml');
    $stopka_boilerplate = trim(strip_tags($stopka_boilerplate));
    if (mb_strlen($stopka_boilerplate, 'UTF-8') > 3500) {
        $stopka_boilerplate = mb_substr($stopka_boilerplate, 0, 3500, 'UTF-8');
    }
    dolibarr_set_const($db, 'KSEF_STOPKA_BOILERPLATE', $stopka_boilerplate, 'chaine', 0, '', $conf->entity);

    // DodatkowyOpis - Extrafields
    $ef_save = new ExtraFields($db);
    $_dodUnsupportedTypes = ksefDodatkowyOpisUnsupportedTypes();

    // Invoice extrafields
    $ef_save->fetch_name_optionals_label('facture');
    $extrafields_val = array();
    foreach (array_keys($ef_save->attributes['facture']['label'] ?? array()) as $fname) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $_t = $ef_save->attributes['facture']['type'][$fname] ?? '';
        if (in_array($_t, $_dodUnsupportedTypes)) continue;
        if (GETPOST('KSEF_EF_' . $fname, 'alpha')) {
            $target = GETPOST('KSEF_EF_TARGET_' . $fname, 'alpha');
            $extrafields_val[] = $fname . ':' . ($target === 'stopka' ? 'stopka' : 'dodatkowy');
        }
    }
    dolibarr_set_const($db, 'KSEF_DODATKOWY_OPIS_EXTRAFIELDS', implode(',', $extrafields_val), 'chaine', 0, '', $conf->entity);

    // Line extrafields
    $ef_save->fetch_name_optionals_label('facturedet');
    $det_extrafields_val = array();
    foreach (array_keys($ef_save->attributes['facturedet']['label'] ?? array()) as $fname) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $_t = $ef_save->attributes['facturedet']['type'][$fname] ?? '';
        if (in_array($_t, $_dodUnsupportedTypes)) continue;
        if (GETPOST('KSEF_EF_DET_' . $fname, 'alpha')) {
            $det_extrafields_val[] = $fname;
        }
    }
    dolibarr_set_const($db, 'KSEF_DODATKOWY_OPIS_DET_EXTRAFIELDS', implode(',', $det_extrafields_val), 'chaine', 0, '', $conf->entity);

    // Product extrafields
    $ef_save->fetch_name_optionals_label('product');
    $product_extrafields_val = array();
    foreach (array_keys($ef_save->attributes['product']['label'] ?? array()) as $fname) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $_t = $ef_save->attributes['product']['type'][$fname] ?? '';
        if (in_array($_t, $_dodUnsupportedTypes)) continue;
        if (GETPOST('KSEF_EF_PROD_' . $fname, 'alpha')) {
            $product_extrafields_val[] = $fname;
        }
    }
    dolibarr_set_const($db, 'KSEF_DODATKOWY_OPIS_PRODUCT_EXTRAFIELDS', implode(',', $product_extrafields_val), 'chaine', 0, '', $conf->entity);

    // Societe extrafields
    $ef_save->fetch_name_optionals_label('societe');
    $societe_extrafields_val = array();
    foreach (array_keys($ef_save->attributes['societe']['label'] ?? array()) as $fname) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $_t = $ef_save->attributes['societe']['type'][$fname] ?? '';
        if (in_array($_t, $_dodUnsupportedTypes)) continue;
        if (GETPOST('KSEF_EF_SOC_' . $fname, 'alpha')) {
            $target = GETPOST('KSEF_EF_SOC_TARGET_' . $fname, 'alpha');
            $societe_extrafields_val[] = $fname . ':' . ($target === 'stopka' ? 'stopka' : 'dodatkowy');
        }
    }
    dolibarr_set_const($db, 'KSEF_DODATKOWY_OPIS_SOCIETE_EXTRAFIELDS', implode(',', $societe_extrafields_val), 'chaine', 0, '', $conf->entity);

    // Project extrafields
    $ef_save->fetch_name_optionals_label('projet');
    $project_extrafields_val = array();
    foreach (array_keys($ef_save->attributes['projet']['label'] ?? array()) as $fname) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $_t = $ef_save->attributes['projet']['type'][$fname] ?? '';
        if (in_array($_t, $_dodUnsupportedTypes)) continue;
        if (GETPOST('KSEF_EF_PROJ_' . $fname, 'alpha')) {
            $target = GETPOST('KSEF_EF_PROJ_TARGET_' . $fname, 'alpha');
            $project_extrafields_val[] = $fname . ':' . ($target === 'stopka' ? 'stopka' : 'dodatkowy');
        }
    }
    dolibarr_set_const($db, 'KSEF_DODATKOWY_OPIS_PROJECT_EXTRAFIELDS', implode(',', $project_extrafields_val), 'chaine', 0, '', $conf->entity);

    setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

// DodatkowyOpis create extrafield
if ($action == 'create_dodatkowy_extrafield') {
    $label = trim(GETPOST('ef_label', 'alphanohtml'));
    $type = GETPOST('ef_type', 'alpha');
    $optionsRaw = GETPOST('ef_options', 'restricthtml');
    $efTarget = GETPOST('ef_target', 'alpha');
    if (!in_array($efTarget, array('facture', 'facturedet', 'societe', 'product', 'projet'))) $efTarget = 'facture';

    $validTypes = array('varchar', 'text', 'int', 'double', 'date', 'datetime', 'select');

    $err = '';
    $code = '';
    $ef_create = new ExtraFields($db);
    $ef_create->fetch_name_optionals_label($efTarget);

    if ($label === '') {
        $err = $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_ERROR_LABEL');
    } elseif (!in_array($type, $validTypes)) {
        $err = $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_ERROR_TYPE');
    } else {
        // Reject duplicate label (case-insensitive)
        $labelLower = mb_strtolower($label, 'UTF-8');
        foreach (($ef_create->attributes[$efTarget]['label'] ?? array()) as $existingName => $existingLabel) {
            if (strpos($existingName, 'ksef_') === 0) continue;
            $translated = $langs->trans($existingLabel);
            if (mb_strtolower($translated, 'UTF-8') === $labelLower) {
                $err = $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_ERROR_DUP_LABEL');
                break;
            }
        }
    }

    if (!$err) {
        $baseCode = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $label));
        $baseCode = trim($baseCode, '_');
        if ($baseCode === '' || !preg_match('/^[a-z]/', $baseCode)) {
            $baseCode = 'cf_' . $baseCode;
            $baseCode = trim($baseCode, '_');
        }
        if (strpos($baseCode, 'ksef_') === 0) {
            $baseCode = 'cf_' . substr($baseCode, 5);
        }
        $baseCode = substr($baseCode, 0, 50);
        $baseCode = rtrim($baseCode, '_');
        if ($baseCode === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $baseCode)) {
            $err = $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_ERROR_LABEL');
        } else {
            // Avoid collisions
            $code = $baseCode;
            $counter = 2;
            while (isset($ef_create->attributes[$efTarget]['label'][$code])) {
                $code = $baseCode . '_' . $counter;
                $counter++;
                if ($counter > 100) { $err = 'Too many collisions'; break; }
            }
        }
    }

    if (!$err) {
        // Build param for select type
        $param = '';
        if ($type === 'select') {
            $options = array();
            $lines = preg_split('/\r\n|\r|\n/', $optionsRaw);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') continue;
                if (strpos($line, '|') !== false) {
                    list($optCode, $optLabel) = explode('|', $line, 2);
                    $optCode = trim($optCode);
                    $optLabel = trim($optLabel);
                } else {
                    $optLabel = $line;
                    $optCode = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $optLabel));
                    $optCode = trim($optCode, '_');
                }
                if ($optCode !== '' && $optLabel !== '') {
                    $options[$optCode] = $optLabel;
                }
            }
            $param = array('options' => $options);
        }

        // Compute auto pos
        $maxPos = 200;
        if (!empty($ef_create->attributes[$efTarget]['pos'])) {
            $maxPos = max(array_map('intval', $ef_create->attributes[$efTarget]['pos'])) + 10;
        }

        $size = ($type === 'varchar') ? '255' : '';

        $result = $ef_create->addExtraField(
            $code, $label, $type, $maxPos, $size, $efTarget,
            0, 0, '', $param, 1, '', '1', '', '', '', 'ksef@ksef', '1', 0, 0
        );

        if ($result > 0) {
            // Auto-add to config
            $efTargetConfigMap = array(
                'facture' => 'KSEF_DODATKOWY_OPIS_EXTRAFIELDS',
                'facturedet' => 'KSEF_DODATKOWY_OPIS_DET_EXTRAFIELDS',
                'product' => 'KSEF_DODATKOWY_OPIS_PRODUCT_EXTRAFIELDS',
                'societe' => 'KSEF_DODATKOWY_OPIS_SOCIETE_EXTRAFIELDS',
                'projet' => 'KSEF_DODATKOWY_OPIS_PROJECT_EXTRAFIELDS',
            );
            // Types with target selection
            $efTargetWithSelection = array('facture', 'societe', 'projet');
            if (isset($efTargetConfigMap[$efTarget])) {
                $configKey = $efTargetConfigMap[$efTarget];
                $currentEf = getDolGlobalString($configKey, '');
                $currentList = array_filter(array_map('trim', explode(',', $currentEf)));
                $fieldEntry = in_array($efTarget, $efTargetWithSelection) ? $code . ':dodatkowy' : $code;
                $alreadyExists = false;
                foreach ($currentList as $entry) {
                    $parts = explode(':', $entry, 2);
                    if ($parts[0] === $code) { $alreadyExists = true; break; }
                }
                if (!$alreadyExists) {
                    $currentList[] = $fieldEntry;
                }
                dolibarr_set_const($db, $configKey, implode(',', $currentList), 'chaine', 0, '', $conf->entity);
            }
            // Auto-set NrUmowy source for new extrafield
            if ($efTarget === 'societe' && getDolGlobalString('KSEF_NR_UMOWY_SOURCE', 'disabled') === 'disabled') {
                dolibarr_set_const($db, 'KSEF_NR_UMOWY_SOURCE', 'thirdparty_extrafield:' . $code, 'chaine', 0, '', $conf->entity);
            }
            setEventMessages(sprintf($langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_SUCCESS'), $label), null, 'mesgs');
        } else {
            setEventMessages($ef_create->error ?: 'Error creating extrafield', null, 'errors');
        }
    } else {
        setEventMessages($err, null, 'errors');
    }

    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

// DodatkowyOpis edit extrafield
if ($action == 'edit_dodatkowy_extrafield') {
    $code = trim(GETPOST('ef_code', 'alphanohtml'));
    $label = trim(GETPOST('ef_label', 'alphanohtml'));
    $type = GETPOST('ef_type', 'alpha');
    $optionsRaw = GETPOST('ef_options', 'restricthtml');
    $efTarget = GETPOST('ef_target', 'alpha');
    if (!in_array($efTarget, array('facture', 'facturedet', 'societe', 'product', 'projet'))) $efTarget = 'facture';

    $validTypes = array('varchar', 'text', 'int', 'double', 'date', 'datetime', 'select');

    $err = '';
    $ef_edit = new ExtraFields($db);
    $ef_edit->fetch_name_optionals_label($efTarget);

    if (!isset($ef_edit->attributes[$efTarget]['label'][$code])) {
        $err = $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_ERROR_NOT_FOUND');
    } elseif (strpos($code, 'ksef_') === 0) {
        $err = $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_ERROR_RESERVED');
    } elseif ($label === '') {
        $err = $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_ERROR_LABEL');
    } elseif (!in_array($type, $validTypes)) {
        $err = $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_ERROR_TYPE');
    } else {
        $labelLower = mb_strtolower($label, 'UTF-8');
        foreach (($ef_edit->attributes[$efTarget]['label'] ?? array()) as $existingName => $existingLabel) {
            if ($existingName === $code) continue;
            if (strpos($existingName, 'ksef_') === 0) continue;
            $translated = $langs->trans($existingLabel);
            if (mb_strtolower($translated, 'UTF-8') === $labelLower) {
                $err = $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_ERROR_DUP_LABEL');
                break;
            }
        }
    }

    if (!$err) {
        $param = '';
        if ($type === 'select') {
            $options = array();
            $lines = preg_split('/\r\n|\r|\n/', $optionsRaw);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') continue;
                if (strpos($line, '|') !== false) {
                    list($optCode, $optLabel) = explode('|', $line, 2);
                    $optCode = trim($optCode);
                    $optLabel = trim($optLabel);
                } else {
                    $optLabel = $line;
                    $optCode = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $optLabel));
                    $optCode = trim($optCode, '_');
                }
                if ($optCode !== '' && $optLabel !== '') {
                    $options[$optCode] = $optLabel;
                }
            }
            $param = array('options' => $options);
        }
        $attrs = $ef_edit->attributes[$efTarget];
        $pos = $attrs['pos'][$code] ?? 200;
        $size = ($type === 'varchar') ? (!empty($attrs['size'][$code]) ? $attrs['size'][$code] : '255') : '';
        $required = $attrs['required'][$code] ?? 0;
        $defaultValue = $attrs['default'][$code] ?? '';
        $perms = $attrs['perms'][$code] ?? '';
        $list = $attrs['list'][$code] ?? '1';
        $printable = $attrs['printable'][$code] ?? 0;
        $help = $attrs['help'][$code] ?? '';
        $computed = $attrs['computed'][$code] ?? '';
        $entity = $attrs['entityid'][$code] ?? '';
        $enabled = $attrs['enabled'][$code] ?? '1';
        $totalizable = $attrs['totalizable'][$code] ?? 0;
        $unique = $attrs['unique'][$code] ?? 0;
        $alwayseditable = $attrs['alwayseditable'][$code] ?? 1;
        $langfile = $attrs['langfile'][$code] ?? '';

        $result = $ef_edit->updateExtraField(
            $code,
            $label,
            $type,
            $pos,
            $size,
            $efTarget,
            $unique,
            $required,
            $defaultValue,
            $param,
            $alwayseditable,
            $perms,
            $list,
            $help,
            $computed,
            $entity,
            $langfile,
            $enabled,
            $totalizable,
            $printable
        );

        if ($result > 0) {
            setEventMessages(sprintf($langs->trans('KSEF_DODATKOWY_OPIS_UPDATE_SUCCESS'), $code), null, 'mesgs');
        } else {
            setEventMessages($ef_edit->error ?: 'Error updating extrafield', null, 'errors');
        }
    } else {
        setEventMessages($err, null, 'errors');
    }

    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

// DodatkowyOpis delete extrafield
if ($action == 'delete_dodatkowy_extrafield') {
    $code = trim(GETPOST('ef_code', 'alphanohtml'));
    $efTarget = GETPOST('ef_target', 'alpha');
    if (!in_array($efTarget, array('facture', 'facturedet', 'societe', 'product', 'projet'))) $efTarget = 'facture';

    $err = '';
    if ($code === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $code)) {
        $err = $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_ERROR_CODE');
    } elseif (strpos($code, 'ksef_') === 0) {
        $err = $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_ERROR_RESERVED');
    } else {
        $ef_del = new ExtraFields($db);
        $ef_del->fetch_name_optionals_label($efTarget);
        if (!isset($ef_del->attributes[$efTarget]['label'][$code])) {
            $err = $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_ERROR_NOT_FOUND');
        }
    }

    if (!$err) {
        $result = $ef_del->delete($code, $efTarget);
        if ($result >= 0) {
            $efTargetConfigMap = array(
                'facture' => 'KSEF_DODATKOWY_OPIS_EXTRAFIELDS',
                'facturedet' => 'KSEF_DODATKOWY_OPIS_DET_EXTRAFIELDS',
                'product' => 'KSEF_DODATKOWY_OPIS_PRODUCT_EXTRAFIELDS',
                'societe' => 'KSEF_DODATKOWY_OPIS_SOCIETE_EXTRAFIELDS',
                'projet' => 'KSEF_DODATKOWY_OPIS_PROJECT_EXTRAFIELDS',
            );
            $configKey = isset($efTargetConfigMap[$efTarget]) ? $efTargetConfigMap[$efTarget] : null;
            if ($configKey) {
                $currentEf = getDolGlobalString($configKey, '');
                $currentList = array_filter(array_map('trim', explode(',', $currentEf)));
                $currentList = array_values(array_filter($currentList, function($entry) use ($code) {
                    $parts = explode(':', $entry, 2);
                    return $parts[0] !== $code;
                }));
                dolibarr_set_const($db, $configKey, implode(',', $currentList), 'chaine', 0, '', $conf->entity);
            }
            setEventMessages(sprintf($langs->trans('KSEF_DODATKOWY_OPIS_DELETE_SUCCESS'), $code), null, 'mesgs');
        } else {
            setEventMessages($ef_del->error ?: 'Error deleting extrafield', null, 'errors');
        }
    } else {
        setEventMessages($err, null, 'errors');
    }

    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

// Customer exclusion management
if ($action == 'add_excluded') {
    $socid = GETPOST('socid', 'int');
    $current = getDolGlobalString('KSEF_EXCLUDED_CUSTOMERS', '');
    $excluded = array_filter(array_map('trim', explode(',', $current)));
    if (!in_array($socid, $excluded)) {
        $excluded[] = $socid;
        dolibarr_set_const($db, "KSEF_EXCLUDED_CUSTOMERS", implode(',', $excluded), 'chaine', 0, '', $conf->entity);
        setEventMessages($langs->trans("KSEF_CustomerExcludedFromKSEF"), null, 'mesgs');
    }
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

if ($action == 'remove_excluded') {
    $socid = GETPOST('socid', 'int');
    $current = getDolGlobalString('KSEF_EXCLUDED_CUSTOMERS', '');
    $excluded = array_filter(array_map('trim', explode(',', $current)));
    $excluded = array_diff($excluded, array($socid));
    dolibarr_set_const($db, "KSEF_EXCLUDED_CUSTOMERS", implode(',', $excluded), 'chaine', 0, '', $conf->entity);
    setEventMessages($langs->trans("KSEF_CustomerRemovedFromKSEFExclusions"), null, 'mesgs');
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}


$form = new Form($db);
$page_name = "KSEF_Setup";

llxHeader('', $langs->trans($page_name), '');

$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1">' . $langs->trans("KSEF_BackToModuleList") . '</a>';
print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

$head = ksefAdminPrepareHead();
print dol_get_fiche_head($head, 'outgoing', $langs->trans("KSEF_Module"), -1, 'ksef@ksef');

// Reactivation warning
echo ksefShowReactivationWarning();

// Config warnings
$warnings = ksefGetConfigWarnings();
if (!empty($warnings)) {
    echo ksefRenderConfigWarnings($warnings, 'outgoing');
}

print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="update">';

// Line Item Fields
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">' . $langs->trans("KSEF_LINE_ITEM_FIELDS") . '</td></tr>';

// NrKlienta/Customer code
print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto($langs->trans('KSEF_FA3_INCLUDE_NRKLIENTA'), $langs->trans('KSEF_FA3_INCLUDE_NRKLIENTA_Help')) . '</td>';
print '<td>';
print '<input type="checkbox" name="KSEF_FA3_INCLUDE_NRKLIENTA" id="KSEF_FA3_INCLUDE_NRKLIENTA" value="1" ' . (getDolGlobalInt('KSEF_FA3_INCLUDE_NRKLIENTA') ? 'checked' : '') . '>';
print ' <label for="KSEF_FA3_INCLUDE_NRKLIENTA">' . $langs->trans("KSEF_Enabled") . '</label>';
print '</td></tr>';

// Indeks/Product reference code
print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto($langs->trans('KSEF_FA3_INCLUDE_INDEKS'), $langs->trans('KSEF_FA3_INCLUDE_INDEKS_Help')) . '</td>';
print '<td>';
print '<input type="checkbox" name="KSEF_FA3_INCLUDE_INDEKS" id="KSEF_FA3_INCLUDE_INDEKS" value="1" ' . (getDolGlobalInt('KSEF_FA3_INCLUDE_INDEKS') ? 'checked' : '') . '>';
print ' <label for="KSEF_FA3_INCLUDE_INDEKS">' . $langs->trans("KSEF_Enabled") . '</label>';
print '</td></tr>';

// GTIN/Barcode/EAN
print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto($langs->trans('KSEF_FA3_INCLUDE_GTIN'), $langs->trans('KSEF_FA3_INCLUDE_GTIN_Help')) . '</td>';
print '<td>';
print '<input type="checkbox" name="KSEF_FA3_INCLUDE_GTIN" id="KSEF_FA3_INCLUDE_GTIN" value="1" ' . (getDolGlobalInt('KSEF_FA3_INCLUDE_GTIN') ? 'checked' : '') . '>';
print ' <label for="KSEF_FA3_INCLUDE_GTIN">' . $langs->trans("KSEF_Enabled") . '</label>';
print '</td></tr>';

// P_8A/Unit of measure
print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto($langs->trans('KSEF_FA3_INCLUDE_UNIT'), $langs->trans('KSEF_FA3_INCLUDE_UNIT_Help')) . '</td>';
print '<td>';
print '<input type="checkbox" name="KSEF_FA3_INCLUDE_UNIT" id="KSEF_FA3_INCLUDE_UNIT" value="1" ' . (getDolGlobalInt('KSEF_FA3_INCLUDE_UNIT') ? 'checked' : '') . '>';
print ' <label for="KSEF_FA3_INCLUDE_UNIT">' . $langs->trans("KSEF_Enabled") . '</label>';
print '</td></tr>';

print '</table>';

// Invoice Header Fields
print '<br><table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">' . $langs->trans("KSEF_INVOICE_HEADER_FIELDS") . '</td></tr>';

// OpisRachunku/Bank account description
print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto($langs->trans('KSEF_FA3_INCLUDE_BANK_DESC'), $langs->trans('KSEF_FA3_INCLUDE_BANK_DESC_Help')) . '</td>';
print '<td>';
print '<input type="checkbox" name="KSEF_FA3_INCLUDE_BANK_DESC" id="KSEF_FA3_INCLUDE_BANK_DESC" value="1" ' . (getDolGlobalInt('KSEF_FA3_INCLUDE_BANK_DESC') ? 'checked' : '') . '>';
print ' <label for="KSEF_FA3_INCLUDE_BANK_DESC">' . $langs->trans("KSEF_Enabled") . '</label>';
print '</td></tr>';

// P_1M/Place of Issue
print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto($langs->trans('KSEF_FA3_PLACE_OF_ISSUE'), $langs->trans('KSEF_FA3_PLACE_OF_ISSUE_Help')) . '</td>';
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

// P_6/Sale Date Source
print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto($langs->trans('KSEF_FA3_SALE_DATE_SOURCE'), $langs->trans('KSEF_FA3_SALE_DATE_SOURCE_Help')) . '</td>';
print '<td>';
$sale_date_modes = array(
    'invoice_date' => $langs->trans('KSEF_FA3_SALE_DATE_SOURCE_INVOICE'),
    'delivery_date' => $langs->trans('KSEF_FA3_SALE_DATE_SOURCE_DELIVERY'),
);
$current_sale_date_source = getDolGlobalString('KSEF_FA3_SALE_DATE_SOURCE', 'delivery_date');
print $form->selectarray('KSEF_FA3_SALE_DATE_SOURCE', $sale_date_modes, $current_sale_date_source, 0, 0, 0, '', 0, 0, 0, '', 'minwidth300');
print '</td></tr>';

// NrZamowienia source
print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto($langs->trans('KSEF_NR_ZAMOWIENIA_SOURCE'), $langs->trans('KSEF_NR_ZAMOWIENIA_SOURCE_Help')) . '</td>';
print '<td>';
$zamowienia_options = array(
    'ref_client' => $langs->trans('KSEF_NR_ZAMOWIENIA_SOURCE_REF_CLIENT'),
    'linked_order' => $langs->trans('KSEF_NR_ZAMOWIENIA_SOURCE_LINKED_ORDER'),
    'disabled' => $langs->trans('KSEF_NR_ZAMOWIENIA_SOURCE_DISABLED'),
);
$ef_zamowienia = new ExtraFields($db);
$ef_zamowienia->fetch_name_optionals_label('facture');
if (!empty($ef_zamowienia->attributes['facture']['label'])) {
    foreach ($ef_zamowienia->attributes['facture']['label'] as $fname => $flabel) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $ftype = $ef_zamowienia->attributes['facture']['type'][$fname] ?? '';
        if (in_array($ftype, array('varchar', 'text', 'int'))) {
            $zamowienia_options['extrafield:' . $fname] = $langs->trans('KSEF_NR_ZAMOWIENIA_SOURCE_EXTRAFIELD') . ': ' . $langs->trans($flabel);
        }
    }
}
$current_zamowienia = getDolGlobalString('KSEF_NR_ZAMOWIENIA_SOURCE', 'ref_client');
print $form->selectarray('KSEF_NR_ZAMOWIENIA_SOURCE', $zamowienia_options, $current_zamowienia, 0, 0, 0, '', 0, 0, 0, '', 'minwidth300');
print '</td></tr>';

// NrUmowy source
print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto($langs->trans('KSEF_NR_UMOWY_SOURCE'), $langs->trans('KSEF_NR_UMOWY_SOURCE_Help')) . '</td>';
print '<td>';
$umowy_options = array(
    'disabled' => $langs->trans('KSEF_NR_UMOWY_SOURCE_DISABLED'),
);
$ef_umowy = new ExtraFields($db);
$ef_umowy->fetch_name_optionals_label('societe');
if (!empty($ef_umowy->attributes['societe']['label'])) {
    foreach ($ef_umowy->attributes['societe']['label'] as $fname => $flabel) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $ftype = $ef_umowy->attributes['societe']['type'][$fname] ?? '';
        if (in_array($ftype, array('varchar', 'text', 'int'))) {
            $umowy_options['thirdparty_extrafield:' . $fname] = $langs->trans('KSEF_NR_UMOWY_SOURCE_THIRDPARTY_EXTRAFIELD') . ': ' . $langs->trans($flabel);
        }
    }
}
$ef_umowy->fetch_name_optionals_label('facture');
if (!empty($ef_umowy->attributes['facture']['label'])) {
    foreach ($ef_umowy->attributes['facture']['label'] as $fname => $flabel) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $ftype = $ef_umowy->attributes['facture']['type'][$fname] ?? '';
        if (in_array($ftype, array('varchar', 'text', 'int'))) {
            $umowy_options['extrafield:' . $fname] = $langs->trans('KSEF_NR_UMOWY_SOURCE_EXTRAFIELD') . ': ' . $langs->trans($flabel);
        }
    }
}
$current_umowy = getDolGlobalString('KSEF_NR_UMOWY_SOURCE', 'disabled');
print $form->selectarray('KSEF_NR_UMOWY_SOURCE', $umowy_options, $current_umowy, 0, 0, 0, '', 0, 0, 0, '', 'minwidth300');
if (count($umowy_options) <= 1) {
    print '<br><span class="small">' . $langs->trans('KSEF_NR_UMOWY_NO_EXTRAFIELDS') . '</span>';
}
print '</td></tr>';

print '</table>';

// VAT Exemption
print '<br><table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">' . $langs->trans("KSEF_VAT_EXEMPTION_SECTION") . '</td></tr>';

print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto($langs->trans('KSEF_ZWOLNIENIE_PODSTAWA'), $langs->trans('KSEF_ZWOLNIENIE_PODSTAWA_Help')) . '</td>';
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
print '<script>function ksefToggleZwolnienie(){var s=document.getElementById("KSEF_ZWOLNIENIE_TYPE");var d=(s&&s.value==="disabled");document.getElementById("ksef_zwolnienie_text_row").style.display=d?"none":"";document.getElementById("ksef_zwolnienie_product_row").style.display=d?"none":"";}</script>';
print '</td></tr>';

// Per-product exemption basis
$zwolnienie_product_hidden = ($current_zwolnienie_type === 'disabled') ? ' style="display:none;"' : '';
print '<tr class="oddeven" id="ksef_zwolnienie_product_row"' . $zwolnienie_product_hidden . '>';
print '<td>' . $form->textwithpicto($langs->trans('KSEF_ZWOLNIENIE_PRODUCT_FIELD'), $langs->trans('KSEF_ZWOLNIENIE_PRODUCT_FIELD_Help')) . '</td>';
print '<td>';
$zwolnienie_field_options = array('' => $langs->trans('KSEF_ZWOLNIENIE_PRODUCT_FIELD_DISABLED'));
$ef_product = new ExtraFields($db);
$ef_product->fetch_name_optionals_label('product');
if (!empty($ef_product->attributes['product']['label'])) {
    foreach ($ef_product->attributes['product']['label'] as $fname => $flabel) {
        $ftype = $ef_product->attributes['product']['type'][$fname] ?? '';
        if (in_array($ftype, array('varchar', 'text', 'select'))) {
            $zwolnienie_field_options[$fname] = $langs->trans($flabel) . ' (' . $fname . ')';
        }
    }
}
$current_zwolnienie_field = getDolGlobalString('KSEF_ZWOLNIENIE_PRODUCT_FIELD', '');
print $form->selectarray('KSEF_ZWOLNIENIE_PRODUCT_FIELD', $zwolnienie_field_options, $current_zwolnienie_field, 0, 0, 0, '', 0, 0, 0, '', 'minwidth300');
print '</td></tr>';

print '</table>';

// Submission & PDF Settings
print '<br><table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">' . $langs->trans("KSEF_SUBMISSION_PDF_SECTION") . '</td></tr>';

// QR Code on PDF
print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto($langs->trans('KSEF_ADD_QR'), $langs->trans('KSEF_ADD_QR_Help')) . '</td>';
print '<td>';
print '<input type="checkbox" name="KSEF_ADD_QR" id="KSEF_ADD_QR" value="1" ' . (getDolGlobalInt('KSEF_ADD_QR') ? 'checked' : '') . '>';
print ' <label for="KSEF_ADD_QR">' . $langs->trans("KSEF_Enabled") . '</label>';
print '</td></tr>';

// Disable Validate and Upload
print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto($langs->trans('KSEF_DISABLE_VALIDATE_AND_UPLOAD'), $langs->trans('KSEF_DISABLE_VALIDATE_AND_UPLOAD_Help')) . '</td>';
print '<td>';
print '<input type="checkbox" name="KSEF_DISABLE_VALIDATE_AND_UPLOAD" id="KSEF_DISABLE_VALIDATE_AND_UPLOAD" value="1" ' . (getDolGlobalInt('KSEF_DISABLE_VALIDATE_AND_UPLOAD') ? 'checked' : '') . '>';
print ' <label for="KSEF_DISABLE_VALIDATE_AND_UPLOAD">' . $langs->trans("KSEF_Enabled") . '</label>';
print '</td></tr>';

print '</table>';

// Invoice Notes & Custom Fields
print '<br><table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">' . $langs->trans("KSEF_DODATKOWY_OPIS_SECTION") . '</td></tr>';

// StopkaFaktury boilerplate
print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto($langs->trans('KSEF_STOPKA_BOILERPLATE'), $langs->trans('KSEF_STOPKA_BOILERPLATE_Help')) . '</td>';
print '<td>';
print '<textarea name="KSEF_STOPKA_BOILERPLATE" class="flat minwidth300" rows="4" maxlength="3500">';
print dol_escape_htmltag(getDolGlobalString('KSEF_STOPKA_BOILERPLATE', ''));
print '</textarea>';
print '<br><span class="small">' . $langs->trans('KSEF_STOPKA_BOILERPLATE_Limit') . '</span>';
print '</td></tr>';

// Note mode
print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto($langs->trans('KSEF_DODATKOWY_OPIS_NOTE_MODE'), $langs->trans('KSEF_DODATKOWY_OPIS_NOTE_MODE_Help')) . '</td>';
print '<td>';
$combined_note_modes = array(
    'simple_stopka' => $langs->trans('KSEF_NOTE_COMBINED_SIMPLE_STOPKA'),
    'simple_dodatkowy' => $langs->trans('KSEF_NOTE_COMBINED_SIMPLE_DODATKOWY'),
    'keyvalue_dodatkowy' => $langs->trans('KSEF_NOTE_COMBINED_KEYVALUE_DODATKOWY'),
    'disabled' => $langs->trans('KSEF_DODATKOWY_OPIS_NOTE_MODE_DISABLED'),
);
// Construct combined value from DB keys
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

// Extrafields listing
print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto($langs->trans('KSEF_DODATKOWY_OPIS_EXTRAFIELDS'), $langs->trans('KSEF_DODATKOWY_OPIS_EXTRAFIELDS_Help')) . '</td>';
print '<td>';
$ef_display = new ExtraFields($db);
$_dodUnsupportedTypes = ksefDodatkowyOpisUnsupportedTypes();

// Invoice extrafields
print '<div style="margin-bottom:16px; padding:15px; border:1px solid #ddd; border-radius:4px; background:#f8f9fa;">';
print '<strong>' . $langs->trans('KSEF_DODATKOWY_OPIS_FACTURE_TITLE') . '</strong><br>';
print '<span class="small">' . $langs->trans('KSEF_DODATKOWY_OPIS_FACTURE_SUBTITLE') . '</span>';

$ef_display->fetch_name_optionals_label('facture');
$current_ef_config = getDolGlobalString('KSEF_DODATKOWY_OPIS_EXTRAFIELDS', '');
$enabled_fields = ksefParseEfConfig($current_ef_config);
$has_facture_fields = false;

if (!empty($ef_display->attributes['facture']['label'])) {
    print '<table class="noborder" style="width:100%; margin-bottom:8px;">';
    print '<tr class="liste_titre">';
    print '<td style="width:28px;"></td>';
    print '<td>' . $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_LABEL') . '</td>';
    print '<td>' . $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_CODE') . '</td>';
    print '<td>' . $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_TYPE') . '</td>';
    print '<td>' . $langs->trans('KSEF_DODATKOWY_OPIS_TARGET_COLUMN') . '</td>';
    print '<td style="text-align:right; width:120px;"></td>';
    print '</tr>';

    foreach ($ef_display->attributes['facture']['label'] as $fname => $flabel) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $ftype = $ef_display->attributes['facture']['type'][$fname] ?? '';
        if (in_array($ftype, $_dodUnsupportedTypes)) continue;
        $has_facture_fields = true;
        $checked = isset($enabled_fields[$fname]) ? ' checked' : '';
        $currentTarget = isset($enabled_fields[$fname]) ? $enabled_fields[$fname] : 'dodatkowy';
        $translatedLabel = $langs->trans($flabel);

        $optsText = '';
        if ($ftype === 'select' && !empty($ef_display->attributes['facture']['param'][$fname]['options'])) {
            $optLines = array();
            foreach ($ef_display->attributes['facture']['param'][$fname]['options'] as $ocode => $olabel) {
                $optLines[] = $ocode . '|' . $olabel;
            }
            $optsText = implode("\n", $optLines);
        }

        $isModuleField = (($ef_display->attributes['facture']['langfile'][$fname] ?? '') === 'ksef@ksef');
        $externalBadge = $isModuleField ? '' : ' <span class="badge badge-secondary" title="' . dol_escape_htmltag($langs->trans('KSEF_EXTRAFIELD_EXTERNAL')) . '">ext</span>';

        print '<tr class="oddeven">';
        print '<td><input type="checkbox" name="KSEF_EF_' . dol_escape_htmltag($fname) . '" value="1"' . $checked . '></td>';
        print '<td>' . dol_escape_htmltag($translatedLabel) . $externalBadge . '</td>';
        print '<td><code>' . dol_escape_htmltag($fname) . '</code></td>';
        print '<td>' . dol_escape_htmltag($ftype) . '</td>';
        print '<td><select name="KSEF_EF_TARGET_' . dol_escape_htmltag($fname) . '" class="flat">';
        print '<option value="dodatkowy"' . ($currentTarget === 'dodatkowy' ? ' selected' : '') . '>' . $langs->trans('KSEF_DODATKOWY_OPIS_TARGET_DODATKOWY') . '</option>';
        print '<option value="stopka"' . ($currentTarget === 'stopka' ? ' selected' : '') . '>' . $langs->trans('KSEF_DODATKOWY_OPIS_TARGET_STOPKA') . '</option>';
        print '</select></td>';
        print '<td style="text-align:right;">';
        print '<a href="#" onclick="ksefEditExtrafield(this); return false;"';
        print ' data-code="' . dol_escape_htmltag($fname) . '"';
        print ' data-label="' . dol_escape_htmltag($translatedLabel) . '"';
        print ' data-type="' . dol_escape_htmltag($ftype) . '"';
        print ' data-options="' . dol_escape_htmltag($optsText) . '"';
        print ' data-target="facture">';
        print img_edit() . '</a> ';
        print '<a href="#" onclick="ksefDeleteExtrafield(this); return false;"';
        print ' data-code="' . dol_escape_htmltag($fname) . '"';
        print ' data-target="facture">';
        print img_delete() . '</a>';
        print '</td>';
        print '</tr>';
    }
    print '</table>';
}
if (!$has_facture_fields) {
    print '<br><span class="small">' . $langs->trans('KSEF_DODATKOWY_OPIS_FACTURE_NO_EXTRAFIELDS') . '</span>';
}
print '</div>';

// Line extrafields
print '<div style="margin-bottom:16px; padding:15px; border:1px solid #ddd; border-radius:4px; background:#f8f9fa;">';
print '<strong>' . $langs->trans('KSEF_DODATKOWY_OPIS_DET_TITLE') . '</strong><br>';
print '<span class="small">' . $langs->trans('KSEF_DODATKOWY_OPIS_DET_SUBTITLE') . '</span>';

$ef_display->fetch_name_optionals_label('facturedet');
$current_det_config = getDolGlobalString('KSEF_DODATKOWY_OPIS_DET_EXTRAFIELDS', '');
$enabled_det_fields = array_filter(array_map('trim', explode(',', $current_det_config)));
$has_det_fields = false;

if (!empty($ef_display->attributes['facturedet']['label'])) {
    print '<table class="noborder" style="width:100%; margin-bottom:8px;">';
    print '<tr class="liste_titre">';
    print '<td style="width:28px;"></td>';
    print '<td>' . $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_LABEL') . '</td>';
    print '<td>' . $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_CODE') . '</td>';
    print '<td>' . $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_TYPE') . '</td>';
    print '<td style="text-align:right; width:120px;"></td>';
    print '</tr>';

    foreach ($ef_display->attributes['facturedet']['label'] as $fname => $flabel) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $ftype = $ef_display->attributes['facturedet']['type'][$fname] ?? '';
        if (in_array($ftype, $_dodUnsupportedTypes)) continue;
        $has_det_fields = true;
        $checked = in_array($fname, $enabled_det_fields) ? ' checked' : '';
        $translatedLabel = $langs->trans($flabel);

        $detOptsText = '';
        if ($ftype === 'select' && !empty($ef_display->attributes['facturedet']['param'][$fname]['options'])) {
            $detOptLines = array();
            foreach ($ef_display->attributes['facturedet']['param'][$fname]['options'] as $ocode => $olabel) {
                $detOptLines[] = $ocode . '|' . $olabel;
            }
            $detOptsText = implode("\n", $detOptLines);
        }

        $isModuleDetField = (($ef_display->attributes['facturedet']['langfile'][$fname] ?? '') === 'ksef@ksef');
        $detExternalBadge = $isModuleDetField ? '' : ' <span class="badge badge-secondary" title="' . dol_escape_htmltag($langs->trans('KSEF_EXTRAFIELD_EXTERNAL')) . '">ext</span>';

        print '<tr class="oddeven">';
        print '<td><input type="checkbox" name="KSEF_EF_DET_' . dol_escape_htmltag($fname) . '" value="1"' . $checked . '></td>';
        print '<td>' . dol_escape_htmltag($translatedLabel) . $detExternalBadge . '</td>';
        print '<td><code>' . dol_escape_htmltag($fname) . '</code></td>';
        print '<td>' . dol_escape_htmltag($ftype) . '</td>';
        print '<td style="text-align:right;">';
        print '<a href="#" onclick="ksefEditExtrafield(this); return false;"';
        print ' data-code="' . dol_escape_htmltag($fname) . '"';
        print ' data-label="' . dol_escape_htmltag($translatedLabel) . '"';
        print ' data-type="' . dol_escape_htmltag($ftype) . '"';
        print ' data-options="' . dol_escape_htmltag($detOptsText) . '"';
        print ' data-target="facturedet">';
        print img_edit() . '</a> ';
        print '<a href="#" onclick="ksefDeleteExtrafield(this); return false;"';
        print ' data-code="' . dol_escape_htmltag($fname) . '"';
        print ' data-target="facturedet">';
        print img_delete() . '</a>';
        print '</td>';
        print '</tr>';
    }
    print '</table>';
}
if (!$has_det_fields) {
    print '<br><span class="small">' . $langs->trans('KSEF_DODATKOWY_OPIS_DET_NO_EXTRAFIELDS') . '</span>';
}
print '</div>';

// Product extrafields
print '<div style="margin-bottom:16px; padding:15px; border:1px solid #ddd; border-radius:4px; background:#f8f9fa;">';
print '<strong>' . $langs->trans('KSEF_DODATKOWY_OPIS_PRODUCT_TITLE') . '</strong><br>';
print '<span class="small">' . $langs->trans('KSEF_DODATKOWY_OPIS_PRODUCT_SUBTITLE') . '</span>';

$ef_display->fetch_name_optionals_label('product');
$current_prod_config = getDolGlobalString('KSEF_DODATKOWY_OPIS_PRODUCT_EXTRAFIELDS', '');
$enabled_prod_fields = array_filter(array_map('trim', explode(',', $current_prod_config)));
$has_prod_fields = false;

if (!empty($ef_display->attributes['product']['label'])) {
    print '<table class="noborder" style="width:100%; margin-bottom:8px;">';
    print '<tr class="liste_titre">';
    print '<td style="width:28px;"></td>';
    print '<td>' . $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_LABEL') . '</td>';
    print '<td>' . $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_CODE') . '</td>';
    print '<td>' . $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_TYPE') . '</td>';
    print '<td style="text-align:right; width:120px;"></td>';
    print '</tr>';

    foreach ($ef_display->attributes['product']['label'] as $fname => $flabel) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $ftype = $ef_display->attributes['product']['type'][$fname] ?? '';
        if (in_array($ftype, $_dodUnsupportedTypes)) continue;
        $has_prod_fields = true;
        $checked = in_array($fname, $enabled_prod_fields) ? ' checked' : '';
        $translatedLabel = $langs->trans($flabel);

        $prodOptsText = '';
        if ($ftype === 'select' && !empty($ef_display->attributes['product']['param'][$fname]['options'])) {
            $prodOptLines = array();
            foreach ($ef_display->attributes['product']['param'][$fname]['options'] as $ocode => $olabel) {
                $prodOptLines[] = $ocode . '|' . $olabel;
            }
            $prodOptsText = implode("\n", $prodOptLines);
        }

        $isModuleProdField = (($ef_display->attributes['product']['langfile'][$fname] ?? '') === 'ksef@ksef');
        $prodExternalBadge = $isModuleProdField ? '' : ' <span class="badge badge-secondary" title="' . dol_escape_htmltag($langs->trans('KSEF_EXTRAFIELD_EXTERNAL')) . '">ext</span>';

        print '<tr class="oddeven">';
        print '<td><input type="checkbox" name="KSEF_EF_PROD_' . dol_escape_htmltag($fname) . '" value="1"' . $checked . '></td>';
        print '<td>' . dol_escape_htmltag($translatedLabel) . $prodExternalBadge . '</td>';
        print '<td><code>' . dol_escape_htmltag($fname) . '</code></td>';
        print '<td>' . dol_escape_htmltag($ftype) . '</td>';
        print '<td style="text-align:right;">';
        print '<a href="#" onclick="ksefEditExtrafield(this); return false;"';
        print ' data-code="' . dol_escape_htmltag($fname) . '"';
        print ' data-label="' . dol_escape_htmltag($translatedLabel) . '"';
        print ' data-type="' . dol_escape_htmltag($ftype) . '"';
        print ' data-options="' . dol_escape_htmltag($prodOptsText) . '"';
        print ' data-target="product">';
        print img_edit() . '</a> ';
        print '<a href="#" onclick="ksefDeleteExtrafield(this); return false;"';
        print ' data-code="' . dol_escape_htmltag($fname) . '"';
        print ' data-target="product">';
        print img_delete() . '</a>';
        print '</td>';
        print '</tr>';
    }
    print '</table>';
}
if (!$has_prod_fields) {
    print '<br><span class="small">' . $langs->trans('KSEF_DODATKOWY_OPIS_PRODUCT_NO_EXTRAFIELDS') . '</span>';
}
print '</div>';

// Societe extrafields
print '<div style="margin-bottom:16px; padding:15px; border:1px solid #ddd; border-radius:4px; background:#f8f9fa;">';
print '<strong>' . $langs->trans('KSEF_DODATKOWY_OPIS_SOCIETE_TITLE') . '</strong><br>';
print '<span class="small">' . $langs->trans('KSEF_DODATKOWY_OPIS_SOCIETE_SUBTITLE') . '</span>';

$ef_display->fetch_name_optionals_label('societe');
$current_soc_config = getDolGlobalString('KSEF_DODATKOWY_OPIS_SOCIETE_EXTRAFIELDS', '');
$enabled_soc_fields = ksefParseEfConfig($current_soc_config);
$has_soc_fields = false;

if (!empty($ef_display->attributes['societe']['label'])) {
    print '<table class="noborder" style="width:100%; margin-bottom:8px;">';
    print '<tr class="liste_titre">';
    print '<td style="width:28px;"></td>';
    print '<td>' . $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_LABEL') . '</td>';
    print '<td>' . $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_CODE') . '</td>';
    print '<td>' . $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_TYPE') . '</td>';
    print '<td>' . $langs->trans('KSEF_DODATKOWY_OPIS_TARGET_COLUMN') . '</td>';
    print '<td style="text-align:right; width:120px;"></td>';
    print '</tr>';

    foreach ($ef_display->attributes['societe']['label'] as $fname => $flabel) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $ftype = $ef_display->attributes['societe']['type'][$fname] ?? '';
        if (in_array($ftype, $_dodUnsupportedTypes)) continue;
        $has_soc_fields = true;
        $checked = isset($enabled_soc_fields[$fname]) ? ' checked' : '';
        $currentTarget = isset($enabled_soc_fields[$fname]) ? $enabled_soc_fields[$fname] : 'dodatkowy';
        $translatedLabel = $langs->trans($flabel);

        $socOptsText = '';
        if ($ftype === 'select' && !empty($ef_display->attributes['societe']['param'][$fname]['options'])) {
            $socOptLines = array();
            foreach ($ef_display->attributes['societe']['param'][$fname]['options'] as $ocode => $olabel) {
                $socOptLines[] = $ocode . '|' . $olabel;
            }
            $socOptsText = implode("\n", $socOptLines);
        }

        $isModuleSocField = (($ef_display->attributes['societe']['langfile'][$fname] ?? '') === 'ksef@ksef');
        $socExternalBadge = $isModuleSocField ? '' : ' <span class="badge badge-secondary" title="' . dol_escape_htmltag($langs->trans('KSEF_EXTRAFIELD_EXTERNAL')) . '">ext</span>';

        print '<tr class="oddeven">';
        print '<td><input type="checkbox" name="KSEF_EF_SOC_' . dol_escape_htmltag($fname) . '" value="1"' . $checked . '></td>';
        print '<td>' . dol_escape_htmltag($translatedLabel) . $socExternalBadge . '</td>';
        print '<td><code>' . dol_escape_htmltag($fname) . '</code></td>';
        print '<td>' . dol_escape_htmltag($ftype) . '</td>';
        print '<td><select name="KSEF_EF_SOC_TARGET_' . dol_escape_htmltag($fname) . '" class="flat">';
        print '<option value="dodatkowy"' . ($currentTarget === 'dodatkowy' ? ' selected' : '') . '>' . $langs->trans('KSEF_DODATKOWY_OPIS_TARGET_DODATKOWY') . '</option>';
        print '<option value="stopka"' . ($currentTarget === 'stopka' ? ' selected' : '') . '>' . $langs->trans('KSEF_DODATKOWY_OPIS_TARGET_STOPKA') . '</option>';
        print '</select></td>';
        print '<td style="text-align:right;">';
        print '<a href="#" onclick="ksefEditExtrafield(this); return false;"';
        print ' data-code="' . dol_escape_htmltag($fname) . '"';
        print ' data-label="' . dol_escape_htmltag($translatedLabel) . '"';
        print ' data-type="' . dol_escape_htmltag($ftype) . '"';
        print ' data-options="' . dol_escape_htmltag($socOptsText) . '"';
        print ' data-target="societe">';
        print img_edit() . '</a> ';
        print '<a href="#" onclick="ksefDeleteExtrafield(this); return false;"';
        print ' data-code="' . dol_escape_htmltag($fname) . '"';
        print ' data-target="societe">';
        print img_delete() . '</a>';
        print '</td>';
        print '</tr>';
    }
    print '</table>';
}
if (!$has_soc_fields) {
    print '<br><span class="small">' . $langs->trans('KSEF_DODATKOWY_OPIS_SOCIETE_NO_EXTRAFIELDS') . '</span>';
}
print '</div>';

// Project extrafields
print '<div style="margin-bottom:16px; padding:15px; border:1px solid #ddd; border-radius:4px; background:#f8f9fa;">';
print '<strong>' . $langs->trans('KSEF_DODATKOWY_OPIS_PROJECT_TITLE') . '</strong><br>';
print '<span class="small">' . $langs->trans('KSEF_DODATKOWY_OPIS_PROJECT_SUBTITLE') . '</span>';

$ef_display->fetch_name_optionals_label('projet');
$current_proj_config = getDolGlobalString('KSEF_DODATKOWY_OPIS_PROJECT_EXTRAFIELDS', '');
$enabled_proj_fields = ksefParseEfConfig($current_proj_config);
$has_proj_fields = false;

if (!empty($ef_display->attributes['projet']['label'])) {
    print '<table class="noborder" style="width:100%; margin-bottom:8px;">';
    print '<tr class="liste_titre">';
    print '<td style="width:28px;"></td>';
    print '<td>' . $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_LABEL') . '</td>';
    print '<td>' . $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_CODE') . '</td>';
    print '<td>' . $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_TYPE') . '</td>';
    print '<td>' . $langs->trans('KSEF_DODATKOWY_OPIS_TARGET_COLUMN') . '</td>';
    print '<td style="text-align:right; width:120px;"></td>';
    print '</tr>';

    foreach ($ef_display->attributes['projet']['label'] as $fname => $flabel) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $ftype = $ef_display->attributes['projet']['type'][$fname] ?? '';
        if (in_array($ftype, $_dodUnsupportedTypes)) continue;
        $has_proj_fields = true;
        $checked = isset($enabled_proj_fields[$fname]) ? ' checked' : '';
        $currentTarget = isset($enabled_proj_fields[$fname]) ? $enabled_proj_fields[$fname] : 'dodatkowy';
        $translatedLabel = $langs->trans($flabel);

        $projOptsText = '';
        if ($ftype === 'select' && !empty($ef_display->attributes['projet']['param'][$fname]['options'])) {
            $projOptLines = array();
            foreach ($ef_display->attributes['projet']['param'][$fname]['options'] as $ocode => $olabel) {
                $projOptLines[] = $ocode . '|' . $olabel;
            }
            $projOptsText = implode("\n", $projOptLines);
        }

        $isModuleProjField = (($ef_display->attributes['projet']['langfile'][$fname] ?? '') === 'ksef@ksef');
        $projExternalBadge = $isModuleProjField ? '' : ' <span class="badge badge-secondary" title="' . dol_escape_htmltag($langs->trans('KSEF_EXTRAFIELD_EXTERNAL')) . '">ext</span>';

        print '<tr class="oddeven">';
        print '<td><input type="checkbox" name="KSEF_EF_PROJ_' . dol_escape_htmltag($fname) . '" value="1"' . $checked . '></td>';
        print '<td>' . dol_escape_htmltag($translatedLabel) . $projExternalBadge . '</td>';
        print '<td><code>' . dol_escape_htmltag($fname) . '</code></td>';
        print '<td>' . dol_escape_htmltag($ftype) . '</td>';
        print '<td><select name="KSEF_EF_PROJ_TARGET_' . dol_escape_htmltag($fname) . '" class="flat">';
        print '<option value="dodatkowy"' . ($currentTarget === 'dodatkowy' ? ' selected' : '') . '>' . $langs->trans('KSEF_DODATKOWY_OPIS_TARGET_DODATKOWY') . '</option>';
        print '<option value="stopka"' . ($currentTarget === 'stopka' ? ' selected' : '') . '>' . $langs->trans('KSEF_DODATKOWY_OPIS_TARGET_STOPKA') . '</option>';
        print '</select></td>';
        print '<td style="text-align:right;">';
        print '<a href="#" onclick="ksefEditExtrafield(this); return false;"';
        print ' data-code="' . dol_escape_htmltag($fname) . '"';
        print ' data-label="' . dol_escape_htmltag($translatedLabel) . '"';
        print ' data-type="' . dol_escape_htmltag($ftype) . '"';
        print ' data-options="' . dol_escape_htmltag($projOptsText) . '"';
        print ' data-target="projet">';
        print img_edit() . '</a> ';
        print '<a href="#" onclick="ksefDeleteExtrafield(this); return false;"';
        print ' data-code="' . dol_escape_htmltag($fname) . '"';
        print ' data-target="projet">';
        print img_delete() . '</a>';
        print '</td>';
        print '</tr>';
    }
    print '</table>';
}
if (!$has_proj_fields) {
    print '<br><span class="small">' . $langs->trans('KSEF_DODATKOWY_OPIS_PROJECT_NO_EXTRAFIELDS') . '</span>';
}
print '</div>';

// Create/Edit form area
$createToken = newToken();
print '<details id="ksef_ef_create_details" style="margin-top:8px; padding:8px; border:1px dashed #bbb; border-radius:4px;">';
print '<summary style="cursor:pointer; font-weight:bold;">' . $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD') . '</summary>';
print '<div id="ksef_ef_form_area" data-mode="create" style="margin-top:8px;">';
print '<input type="hidden" form="ksef_ef_create_form" name="token" value="' . $createToken . '">';
print '<input type="hidden" form="ksef_ef_edit_form" name="token" value="' . $createToken . '">';
print '<table class="noborder" style="width:100%;">';
print '<tr><td style="width:160px;"><label for="ksef_ef_target">' . $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_TARGET') . '</label></td>';
print '<td><select id="ksef_ef_target" form="ksef_ef_create_form" name="ef_target" class="flat minwidth300">';
print '<option value="facture">' . $langs->trans('KSEF_DODATKOWY_OPIS_TARGET_FACTURE') . '</option>';
print '<option value="facturedet">' . $langs->trans('KSEF_DODATKOWY_OPIS_TARGET_FACTUREDET') . '</option>';
print '<option value="product">' . $langs->trans('KSEF_DODATKOWY_OPIS_TARGET_PRODUCT') . '</option>';
print '<option value="societe">' . $langs->trans('KSEF_DODATKOWY_OPIS_TARGET_SOCIETE') . '</option>';
print '<option value="projet">' . $langs->trans('KSEF_DODATKOWY_OPIS_TARGET_PROJECT') . '</option>';
print '</select></td></tr>';
print '<tr><td style="width:160px;"><label for="ksef_ef_label">' . $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_LABEL') . '</label></td>';
print '<td><input type="text" id="ksef_ef_label" form="ksef_ef_create_form" name="ef_label" class="flat minwidth300"></td></tr>';
print '<tr id="ksef_ef_code_row" style="display:none;"><td>' . $langs->trans('KSEF_DODATKOWY_OPIS_INTERNAL_CODE') . '</td>';
print '<td><code id="ksef_ef_code_display"></code><input type="hidden" id="ksef_ef_code" form="ksef_ef_edit_form" name="ef_code" value=""></td></tr>';
print '<tr><td><label for="ksef_ef_type">' . $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_TYPE') . '</label></td>';
print '<td><select id="ksef_ef_type" form="ksef_ef_create_form" name="ef_type" onchange="ksefToggleOptions()">';
print '<option value="varchar">' . $langs->trans('KSEF_DODATKOWY_OPIS_TYPE_VARCHAR') . '</option>';
print '<option value="text">' . $langs->trans('KSEF_DODATKOWY_OPIS_TYPE_TEXT') . '</option>';
print '<option value="int">' . $langs->trans('KSEF_DODATKOWY_OPIS_TYPE_INT') . '</option>';
print '<option value="double">' . $langs->trans('KSEF_DODATKOWY_OPIS_TYPE_DOUBLE') . '</option>';
print '<option value="date">' . $langs->trans('KSEF_DODATKOWY_OPIS_TYPE_DATE') . '</option>';
print '<option value="datetime">' . $langs->trans('KSEF_DODATKOWY_OPIS_TYPE_DATETIME') . '</option>';
print '<option value="select">' . $langs->trans('KSEF_DODATKOWY_OPIS_TYPE_SELECT') . '</option>';
print '</select></td></tr>';
print '<tr id="ksef_ef_options_row" style="display:none;"><td><label for="ksef_ef_options">' . $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_OPTIONS') . '</label></td>';
print '<td><textarea id="ksef_ef_options" form="ksef_ef_create_form" name="ef_options" class="flat" rows="4" style="width:100%;"></textarea></td></tr>';
print '<tr><td></td><td>';
print '<button type="submit" id="ksef_ef_create_btn" form="ksef_ef_create_form" class="button">' . $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_SUBMIT') . '</button> ';
print '<button type="submit" id="ksef_ef_edit_btn" form="ksef_ef_edit_form" class="button" style="display:none;">' . $langs->trans('KSEF_DODATKOWY_OPIS_EDIT_FIELD_SUBMIT') . '</button> ';
print '<button type="button" id="ksef_ef_cancel_btn" class="button" style="display:none;" onclick="ksefCancelEdit()">' . $langs->trans('Cancel') . '</button>';
print '</td></tr>';
print '</table>';
print '</div>';
print '</details>';

print '</td></tr>';

print '</table>';

// extrafield
print '<script>
function ksefToggleOptions() {
    var typeSel = document.getElementById("ksef_ef_type");
    document.getElementById("ksef_ef_options_row").style.display = (typeSel.value === "select") ? "" : "none";
}
function ksefEditExtrafield(btn) {
    var area = document.getElementById("ksef_ef_form_area");
    area.dataset.mode = "edit";
    var code = btn.dataset.code || "";
    document.getElementById("ksef_ef_label").value = btn.dataset.label || "";
    document.getElementById("ksef_ef_code").value = code;
    document.getElementById("ksef_ef_code_display").textContent = code;
    document.getElementById("ksef_ef_code_row").style.display = "";
    document.getElementById("ksef_ef_type").value = btn.dataset.type || "varchar";
    document.getElementById("ksef_ef_options").value = btn.dataset.options || "";
    document.getElementById("ksef_ef_target").value = btn.dataset.target || "facture";
    var inputs = ["ksef_ef_label", "ksef_ef_type", "ksef_ef_options", "ksef_ef_target"];
    inputs.forEach(function(id) { document.getElementById(id).setAttribute("form", "ksef_ef_edit_form"); });
    document.getElementById("ksef_ef_create_btn").style.display = "none";
    document.getElementById("ksef_ef_edit_btn").style.display = "";
    document.getElementById("ksef_ef_cancel_btn").style.display = "";
    ksefToggleOptions();
    document.getElementById("ksef_ef_create_details").open = true;
    document.getElementById("ksef_ef_label").focus();
}
function ksefCancelEdit() {
    var area = document.getElementById("ksef_ef_form_area");
    area.dataset.mode = "create";
    document.getElementById("ksef_ef_label").value = "";
    document.getElementById("ksef_ef_code").value = "";
    document.getElementById("ksef_ef_code_display").textContent = "";
    document.getElementById("ksef_ef_code_row").style.display = "none";
    document.getElementById("ksef_ef_type").value = "varchar";
    document.getElementById("ksef_ef_options").value = "";
    document.getElementById("ksef_ef_target").value = "facture";
    var inputs = ["ksef_ef_label", "ksef_ef_type", "ksef_ef_options", "ksef_ef_target"];
    inputs.forEach(function(id) { document.getElementById(id).setAttribute("form", "ksef_ef_create_form"); });
    document.getElementById("ksef_ef_create_btn").style.display = "";
    document.getElementById("ksef_ef_edit_btn").style.display = "none";
    document.getElementById("ksef_ef_cancel_btn").style.display = "none";
    ksefToggleOptions();
}
function ksefDeleteExtrafield(btn) {
    var code = btn.dataset.code;
    var target = btn.dataset.target || "facture";
    var msg = "' . dol_escape_js($langs->transnoentities('KSEF_DODATKOWY_OPIS_DELETE_CONFIRM', '__CODE__')) . '".replace("__CODE__", code);
    if (confirm(msg)) {
        document.getElementById("ksef_ef_delete_code").value = code;
        document.getElementById("ksef_ef_delete_target").value = target;
        document.getElementById("ksef_ef_delete_form").submit();
    }
}
</script>';

// Default Payment Settings
print '<br><table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">' . $langs->trans("KSEF_DEFAULT_PAYMENT_CONFIG") . '</td></tr>';

// Payment terms
print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto($langs->trans('KSEF_DEFAULT_PAYMENT_TERM'), $langs->trans('KSEF_DEFAULT_PAYMENT_TERM_Help')) . '</td>';
print '<td>';
print $form->getSelectConditionsPaiements(getDolGlobalInt('KSEF_DEFAULT_PAYMENT_TERM_ID', 0), 'KSEF_DEFAULT_PAYMENT_TERM_ID', -1, 1, 0, 'minwidth300');
print '</td></tr>';

// Payment method
print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto($langs->trans('KSEF_DEFAULT_PAYMENT_METHOD'), $langs->trans('KSEF_DEFAULT_PAYMENT_METHOD_Help')) . '</td>';
print '<td>';
print $form->select_types_paiements(getDolGlobalString('KSEF_DEFAULT_PAYMENT_METHOD_ID', ''), 'KSEF_DEFAULT_PAYMENT_METHOD_ID', '', 0, 1, 0, 0, 1, 'minwidth300', 1);
print '</td></tr>';

// Bank account
print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto($langs->trans('KSEF_DEFAULT_BANK_ACCOUNT'), $langs->trans('KSEF_DEFAULT_BANK_ACCOUNT_Help')) . '</td>';
print '<td>';
print $form->select_comptes(getDolGlobalString('KSEF_DEFAULT_BANK_ACCOUNT_ID', ''), 'KSEF_DEFAULT_BANK_ACCOUNT_ID', 0, '', 1, '', 0, 'minwidth300', 1);
print '</td></tr>';

print '</table>';

print '<div class="center" style="margin-top: 10px;">';
print '<input type="submit" class="button button-save" value="' . $langs->trans("Save") . '">';
print '</div>';
print '</form>';

// extrafield create/edit/delete actions
print '<form id="ksef_ef_create_form" method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
print '<input type="hidden" name="action" value="create_dodatkowy_extrafield">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '</form>';
print '<form id="ksef_ef_edit_form" method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
print '<input type="hidden" name="action" value="edit_dodatkowy_extrafield">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '</form>';
print '<form id="ksef_ef_delete_form" method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
print '<input type="hidden" name="action" value="delete_dodatkowy_extrafield">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" id="ksef_ef_delete_code" name="ef_code" value="">';
print '<input type="hidden" id="ksef_ef_delete_target" name="ef_target" value="facture">';
print '</form>';

// Customer Exclusions
print '<br><form method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">' . $langs->trans("KSEF_CUSTOMER_EXCLUSIONS") . '</td></tr>';

print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto($langs->trans("KSEF_EXCLUDED_CUSTOMERS"), $langs->trans("KSEF_EXCLUDED_CUSTOMERS_Help")) . '</td>';
print '<td>';

// Show current exclusions
$_excludedStr = getDolGlobalString('KSEF_EXCLUDED_CUSTOMERS', '');
if (!empty($_excludedStr)) {
    $excluded_ids = array_filter(array_map('trim', explode(',', $_excludedStr)));
    foreach ($excluded_ids as $socid) {
        $tmpsoc = new Societe($db);
        if ($tmpsoc->fetch($socid) > 0) {
            print '<span class="badge badge-secondary" style="margin-right: 5px;">';
            print dol_escape_htmltag($tmpsoc->name);
            print ' <a href="' . $_SERVER["PHP_SELF"] . '?action=remove_excluded&socid=' . $socid . '&token=' . newToken() . '" style="color:white;">×</a>';
            print '</span> ';
        }
    }
    print '<br><br>';
}

// Add new exclusion
print '<select id="excluded_customer" name="socid" class="flat minwidth300">';
print '<option value="">-- ' . $langs->trans("KSEF_SelectCustomer") . ' --</option>';
$sql = "SELECT rowid, nom FROM " . MAIN_DB_PREFIX . "societe WHERE client IN (1, 3) AND entity = " . $conf->entity . " ORDER BY nom";
$resql = $db->query($sql);
if ($resql) {
    while ($obj = $db->fetch_object($resql)) {
        print '<option value="' . $obj->rowid . '">' . dol_escape_htmltag($obj->nom) . '</option>';
    }
}
print '</select> ';
print '<input type="hidden" name="action" value="add_excluded">';
print '<input type="submit" class="button small" value="' . $langs->trans("Add") . '">';
print '</td></tr>';

print '</table>';
print '</form>';

print dol_get_fiche_end();

llxFooter();
