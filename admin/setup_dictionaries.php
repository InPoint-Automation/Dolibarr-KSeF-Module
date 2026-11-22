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
 * \file    admin/setup_dictionaries.php
 * \ingroup ksef
 * \brief   KSeF dictionaries tab
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

if (!$user->admin) {
    accessforbidden();
}

$langs->loadLangs(array("admin", "bills", "products", "other", "languages", "ksef@ksef"));

$action = GETPOST('action', 'aZ09');
$transLangs = array('pl_PL', 'en_US');

/**
 * @brief Applied name overrides
 * @param DoliDB $db
 * @return array
 */
function ksefTransApplied($db)
{
    $out = array();
    foreach (ksefGetCurrentTranslationOverrides($db) as $ov) {
        $out[$ov['lang']][$ov['transkey']] = $ov['transvalue'];
    }
    return $out;
}

if ($action == 'update_dictionaries') {
    $unitMap = array();
    $postedUnits = GETPOST('ksef_unit', 'array');
    if (is_array($postedUnits)) {
        foreach ($postedUnits as $code => $txt) {
            $code = preg_replace('/[^A-Za-z0-9_]/', '', (string) $code);
            $txt = trim(dol_string_nohtmltag((string) $txt));
            if ($code !== '' && $txt !== '') {
                $unitMap[$code] = dol_trunc($txt, 256, 'right', 'UTF-8', 1);
            }
        }
    }
    dolibarr_set_const($db, 'KSEF_FA3_UNIT_MAP', $unitMap ? json_encode($unitMap) : '', 'chaine', 0, '', $conf->entity);
    $formMap = array();
    $postedForms = GETPOST('ksef_payform', 'array');
    if (is_array($postedForms)) {
        foreach ($postedForms as $code => $want) {
            $code = preg_replace('/[^A-Za-z0-9_]/', '', (string) $code);
            if ($code !== '' && preg_match('/^([1-7]|other|omit)$/', (string) $want)) {
                $formMap[$code] = (string) $want;
            }
        }
    }
    dolibarr_set_const($db, 'KSEF_FA3_PAYMENT_FORM_MAP', $formMap ? json_encode($formMap) : '', 'chaine', 0, '', $conf->entity);

    $applied = ksefTransApplied($db);
    $apply = array();
    $remove = array();
    foreach ($transLangs as $lg) {
        if (GETPOST('trans_lang_' . $lg, 'alpha')) {
            $apply[] = $lg;
        } elseif (!empty($applied[$lg])) {
            $remove[] = $lg;
        }
    }
    if ($apply && ksefApplyTranslationOverrides($db, $apply) <= 0) {
        setEventMessages($langs->trans("KSEF_TransOverridesError"), null, 'errors');
    } else {
        setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
    }
    header("Location: " . $_SERVER["PHP_SELF"] . ($remove ? '?action=ask_remove_trans_overrides&trans_langs=' . implode(',', $remove) . '&token=' . newToken() : ''));
    exit;
}

if ($action == 'confirm_remove_trans_overrides' && GETPOST('confirm', 'alpha') == 'yes') {
    $remove = array_values(array_intersect($transLangs, explode(',', GETPOST('trans_langs', 'alphanohtml'))));
    if ($remove && ksefRemoveTranslationOverrides($db, $remove) > 0) {
        setEventMessages($langs->trans("KSEF_TransOverridesRemoved", implode(', ', $remove)), null, 'mesgs');
    } elseif ($remove) {
        setEventMessages($langs->trans("KSEF_TransOverridesError"), null, 'errors');
    }
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

$form = new Form($db);
$page_name = "KSEF_Setup";

llxHeader('', $langs->trans($page_name), ksefHelpUrl('config'));

$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1">' . $langs->trans("KSEF_BackToModuleList") . '</a>';
print load_fiche_titre($langs->trans($page_name), ksefHelpTitleButton($user, 'dictionaries', array('config' => 'KSEF_ConfigurationSection'), array()) . $linkback, 'title_setup');

$head = ksefAdminPrepareHead();
print dol_get_fiche_head($head, 'dictionaries', $langs->trans("KSEF_Module"), -1, 'ksef@ksef');

if ($action == 'ask_remove_trans_overrides') {
    $askLangs = array_values(array_intersect($transLangs, explode(',', GETPOST('trans_langs', 'alphanohtml'))));
    if ($askLangs) {
        $names = array_map(function ($lg) use ($langs) {
            return $langs->trans('Language_' . $lg);
        }, $askLangs);
        print $form->formconfirm($_SERVER["PHP_SELF"] . '?trans_langs=' . implode(',', $askLangs), $langs->trans("KSEF_RemoveTransOverrides"),
            $langs->trans("KSEF_ConfirmRemoveTransOverrides", implode(', ', $names)), 'confirm_remove_trans_overrides', '', 0, 1);
    }
}

echo ksefShowAdminBanners();

$warnings = ksefGetConfigWarnings();
if (!empty($warnings)) {
    echo ksefRenderConfigWarnings($warnings, 'dictionaries');
}

print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '" data-ksef-guarded="1">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="update_dictionaries">';

print '<div class="ksef-settings-box">';

$unitUse = array();
$_sqls = array(
    'p' => "SELECT fk_unit, COUNT(*) as n FROM " . MAIN_DB_PREFIX . "product WHERE fk_unit > 0 AND entity IN (" . getEntity('product') . ") GROUP BY fk_unit",
    'l' => "SELECT d.fk_unit, COUNT(*) as n FROM " . MAIN_DB_PREFIX . "facturedet d JOIN " . MAIN_DB_PREFIX . "facture f ON f.rowid = d.fk_facture WHERE d.fk_unit > 0 AND f.entity IN (" . getEntity('invoice') . ") GROUP BY d.fk_unit",
);
foreach ($_sqls as $_k => $_sql) {
    $_res = $db->query($_sql);
    while ($_res && ($_o = $db->fetch_object($_res))) {
        $unitUse[(int) $_o->fk_unit][$_k] = (int) $_o->n;
    }
}
$unitGroups = array('used' => array());
$_kinds = array('weight', 'size', 'surface', 'volume', 'qty', 'time');
foreach ($_kinds as $_kind) {
    $unitGroups[$_kind] = array();
}
$unitGroups['other'] = array();
$_res = $db->query("SELECT rowid, code, label, short_label, unit_type FROM " . MAIN_DB_PREFIX . "c_units WHERE active = 1 ORDER BY sortorder, code");
while ($_res && ($_u = $db->fetch_object($_res))) {
    $_g = isset($unitUse[(int) $_u->rowid]) ? 'used' : (in_array($_u->unit_type, $_kinds, true) ? $_u->unit_type : 'other');
    $unitGroups[$_g][] = $_u;
}
$usedNames = array_map(function ($u) use ($langs) {
    return $langs->trans($u->label);
}, $unitGroups['used']);
$_others = array_sum(array_map('count', $unitGroups)) - count($unitGroups['used']);
$unitDesc = $usedNames ? $langs->trans('KSEF_UnitsSummary', implode(', ', $usedNames), $_others) : $langs->trans('KSEF_UnitsSummaryNone', $_others);
$unitMap = ksefUnitMap();

print '<table class="noborder centpercent">';
print '<tr class="liste_titre ksef-section-head"><td colspan="2">' . $form->textwithpicto($langs->trans('KSEF_FA3_UNIT_MAP_Title'), $langs->trans('KSEF_FA3_UNIT_MAP_Help'));
print '<div class="ksef-section-desc opacitymedium small">' . dol_escape_htmltag($unitDesc) . '</div></td></tr>';
print '<tr class="liste_titre" id="ksef-units"><td>' . $langs->trans('KSEF_FA3_UNIT_Code') . '</td><td>' . $langs->trans('KSEF_FA3_UNIT_Text') . '</td></tr>';
foreach ($unitGroups as $_g => $_units) {
    if (!$_units) {
        continue;
    }
    print '<tr class="liste_titre"><td colspan="2" class="small">' . $langs->trans('KSEF_UnitGroup_' . $_g) . ' (' . count($_units) . ')</td></tr>';
    foreach ($_units as $_u) {
        $_code = (string) $_u->code;
        $_use = $unitUse[(int) $_u->rowid] ?? array();
        print '<tr class="oddeven"><td>' . dol_escape_htmltag($langs->trans($_u->label)) . ' <span class="opacitymedium">(' . dol_escape_htmltag($_code) . ')</span>';
        if ($_use) {
            print '<br><span class="opacitymedium small">' . $langs->trans('KSEF_UnitUsage', (int) ($_use['p'] ?? 0), (int) ($_use['l'] ?? 0)) . '</span>';
        }
        print '</td><td><input type="text" class="maxwidth150" name="ksef_unit[' . dol_escape_htmltag($_code) . ']" value="' . dol_escape_htmltag($unitMap[$_code] ?? '') . '"';
        print ' placeholder="' . dol_escape_htmltag(ksefUnitDefaultText($_code, $_u->short_label)) . '"></td></tr>';
    }
}
print '</table>';

print '<br><table class="noborder centpercent">';
print '<tr class="liste_titre ksef-section-head"><td colspan="2">' . $form->textwithpicto($langs->trans('KSEF_FA3_PAYMENT_FORM_MAP_Title'), $langs->trans('KSEF_FA3_PAYMENT_FORM_MAP_Help')) . ksefSectionDesc('KSEF_FA3_PAYMENT_FORM_MAP_Title_Desc') . '</td></tr>';
$_forms = ksefPaymentFormMap();
$_def = ksefPaymentFormDefaults();
$_res = $db->query("SELECT code, libelle FROM " . MAIN_DB_PREFIX . "c_paiement WHERE active = 1 AND entity IN (" . getEntity('c_paiement') . ") ORDER BY code");
while ($_res && ($_p = $db->fetch_object($_res))) {
    $_code = (string) $_p->code;
    $_opts = array('' => array(isset($_def[$_code]) ? ksefGetPaymentMethodLabel($_def[$_code]) : $langs->trans('KSEF_PaymentFormOther'), $_def[$_code] ?? ''));
    $_opts[''][0] = $langs->trans('KSEF_PaymentFormDefault', $_opts[''][0]);
    for ($_i = 1; $_i <= 7; $_i++) {
        $_opts[(string) $_i] = array(ksefGetPaymentMethodLabel((string) $_i), (string) $_i);
    }
    $_opts['other'] = array($langs->trans('KSEF_PaymentFormOther'), '');
    $_opts['omit'] = array($langs->trans('KSEF_PaymentFormOmit'), '');
    $_label = $langs->transnoentitiesnoconv('PaymentType' . $_code);
    if ($_label === 'PaymentType' . $_code) {
        $_label = $_p->libelle;
    }
    $_cur = (string) ($_forms[$_code] ?? '');
    print '<tr class="oddeven"><td class="titlefield"><label for="ksef_payform_' . dol_escape_htmltag($_code) . '">' . dol_escape_htmltag($_label) . '</label></td><td>';
    print '<select class="flat minwidth300" id="ksef_payform_' . dol_escape_htmltag($_code) . '" name="ksef_payform[' . dol_escape_htmltag($_code) . ']">';
    foreach ($_opts as $_v => $_o) {
        print '<option value="' . $_v . '"' . ($_o[1] !== '' ? ' title="' . dol_escape_htmltag($langs->trans('KSEF_PaymentFormCode', $_o[1])) . '"' : '') . ((string) $_v === $_cur ? ' selected' : '') . '>' . dol_escape_htmltag($_o[0]) . '</option>';
    }
    print '</select></td></tr>';
}
print '</table>';

$applied = ksefTransApplied($db);
$want = ksefGetManagedTranslationOverrides();
print '<br><table class="noborder centpercent" id="ksef-trans-names">';
print '<tr class="liste_titre ksef-section-head"><td colspan="3">' . $langs->trans("KSEF_TranslationOverrides") . ksefSectionDesc('KSEF_TranslationOverrides_Desc') . '</td></tr>';
print '<tr class="oddeven"><td colspan="3"><span class="opacitymedium">' . $langs->trans("KSEF_TransOverridesDesc") . '</span></td></tr>';
print '<tr class="liste_titre"><td>' . $langs->trans("KSEF_TransOverridesField") . '</td>';
foreach ($transLangs as $lg) {
    print '<td class="center"><label><input type="checkbox" class="ksef-trans-lang" name="trans_lang_' . $lg . '" id="trans_lang_' . $lg . '" value="1" data-lang="' . $lg . '"' . (!empty($applied[$lg]) ? ' checked' : '') . '> ' . $langs->trans('Language_' . $lg) . '</label></td>';
}
print '</tr>';
foreach ($want as $tk => $tv) {
    if (strpos($tk, 'Short') !== false) {
        continue;
    }
    print '<tr class="oddeven"><td><strong>' . dol_escape_htmltag($tv) . '</strong><br><span class="opacitymedium small">' . dol_escape_htmltag($tk) . '</span></td>';
    foreach ($transLangs as $lg) {
        $stale = !empty($applied[$lg]) && ($applied[$lg][$tk] ?? '') !== $tv;
        print '<td class="center" data-lang="' . $lg . '"><span class="ksef-trans-on">' . dol_escape_htmltag($tv) . ($stale ? ' ' . img_warning($langs->trans('KSEF_TransNeedsSave')) : '') . '</span>';
        print '<span class="ksef-trans-off opacitymedium">' . $langs->trans('KSEF_TransDolibarrName') . '</span></td>';
    }
    print '</tr>';
}
print '</table>';

print '</div>';

print '<div class="center" style="margin-top: 10px;">';
print '<input type="submit" class="button button-save" value="' . $langs->trans("Save") . '">';
print '</div>';
print '</form>';

print '<script>
jQuery(function($) {
    function show() {
        $(".ksef-trans-lang").each(function() {
            var on = this.checked, cells = $("#ksef-trans-names td[data-lang=" + $(this).data("lang") + "]");
            cells.find(".ksef-trans-on").toggle(on);
            cells.find(".ksef-trans-off").toggle(!on);
        });
    }
    $(".ksef-trans-lang").on("change", show);
    show();
});
</script>';

print dol_get_fiche_end();

print ksefAdminDirtyGuardJs();
print ksefCollapsibleSectionsJs();
print ksefHelpFooter($db, $user);
llxFooter();
