<?php
/* Copyright (C) 2025-2026 InPoint Automation Sp z o.o.
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
 * \file       ksef/ksef_help.php
 * \ingroup    ksef
 * \brief      Tour endpoint
 */

// no CSRF renewal
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    if (!defined('NOTOKENRENEWAL')) {
        define('NOTOKENRENEWAL', '1');
    }
    if (!defined('NOREQUIREMENU')) {
        define('NOREQUIREMENU', '1');
    }
    if (!defined('NOREQUIREHTML')) {
        define('NOREQUIREHTML', '1');
    }
    if (!defined('NOREQUIREAJAX')) {
        define('NOREQUIREAJAX', '1');
    }
} elseif (!defined('CSRFCHECK_WITH_TOKEN')) {
    define('CSRFCHECK_WITH_TOKEN', '1');
}

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Include of main fails");

global $conf, $langs, $user, $db;

dol_include_once('/ksef/lib/ksef.lib.php');
dol_include_once('/ksef/lib/ksef_tour.lib.php');

$langs->loadLangs(array('ksef@ksef'));
$action = GETPOST('action', 'aZ09');

if (!isModEnabled('ksef') || !ksefUserCanReadModule($user)) {
    accessforbidden();
}

if ($action === 'dismiss' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once DOL_DOCUMENT_ROOT . '/core/lib/functions2.lib.php';
    dol_set_user_param($db, $conf, $user, array(KSEF_HELP_BANNER_PARAM => '1'));
    // no open redirect
    $back = GETPOST('backtopage', 'alpha');
    if (!preg_match('#^/(?![/\\\\])#', $back)) {
        $back = dol_buildpath('/ksef/ksefindex.php', 1);
    }
    header('Location: ' . $back);
    exit;
}

if ($action === 'displaytour') {
    $id = GETPOST('id', 'aZ09');
    $defs = ksefTourDefinitions();
    top_httphead('application/json');
    if (!isset($defs[$id]) || !ksefTourAllowed($defs[$id], $user)) {
        http_response_code(404);
        print json_encode(array('error' => 'unknown tour'));
        exit;
    }
    print json_encode(ksefTourPayload($id), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    exit;
}

if ($action === 'displayitem') {
    $id = GETPOST('tour', 'aZ09');
    $mine = array();
    foreach (ksefHelpChecklist($db) as $key => $it) {
        if ($it['tour'] === $id) {
            $mine[$key] = $it;
        }
    }
    $open = null;
    if (count($mine) === 1 && reset($mine)['done'] === false) {
        $open = array('item' => $langs->transnoentitiesnoconv('KSEF_Help_Item_' . key($mine)), 'detail' => trim(dol_string_nohtmltag((string) reset($mine)['detail'])));
        if (array_key_exists('none', reset($mine)) && reset($mine)['none'] === false && !empty($user->admin)) {
            // not used here
            $open['none'] = array('text' => $langs->transnoentitiesnoconv('KSEF_Tour_' . $id . '_none'), 'label' => $langs->transnoentitiesnoconv('KSEF_Help_VatNoneSet'),
                'url' => dol_buildpath('/ksef/ksef_fix.php', 1) . '?action=vatnone&undo=0&token=' . newToken());
        }
    }
    top_httphead('application/json');
    print json_encode(array('open' => $open), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    exit;
}

if ($action === 'displaycfg') {
    top_httphead('application/json');
    print json_encode(array('cfg' => ksefHelpConfig($user), 'js' => ksefHelpScriptUrl()), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    exit;
}

http_response_code(400);
exit;
