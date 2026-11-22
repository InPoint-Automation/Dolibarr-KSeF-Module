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
 * \file       ksef/ksef_fix.php
 * \ingroup    ksef
 * \brief      Fixing issues in DB
 */

if (!defined('CSRFCHECK_WITH_TOKEN')) {
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

$langs->loadLangs(array('admin', 'ksef@ksef'));
$action = GETPOST('action', 'aZ09');
$key = GETPOST('req', 'aZ09');
$back = GETPOST('backtopage', 'alpha');
if (!preg_match('#^/(?![/\\\\])#', $back) || ksefRequirementBackRefused($back)) {
    $back = '';
}

if ($action === 'bankdefault' && isModEnabled('ksef') && isModEnabled('banque') && $user->hasRight('banque', 'configurer')) {
    if (ksefRequirementBankDefault($db, $user, GETPOSTINT('account'))) {
        setEventMessages($langs->trans('KSEF_BankAccountMadeDefault'), null, 'mesgs');
    }
    header('Location: ' . ($back !== '' ? $back : DOL_URL_ROOT . '/compta/bank/card.php?id=' . GETPOSTINT('account')));
    exit;
}

if (!isModEnabled('ksef') || empty($user->admin)) {
    accessforbidden();
}

if ($action === 'vatnone') {
    require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
    if (GETPOSTINT('undo')) {
        dolibarr_del_const($db, 'KSEF_VAT_CODES_NONE', $conf->entity);
    } else {
        dolibarr_set_const($db, 'KSEF_VAT_CODES_NONE', '1', 'chaine', 0, '', $conf->entity);
    }
}
if ($action === 'fix' && $key !== '') {
    $r = ksefRequirementFix($db, $user, $key);
    setEventMessages($r['message'], null, $r['ok'] ? 'mesgs' : 'errors');
}
header('Location: ' . ($back !== '' ? $back : dol_buildpath('/ksef/ksefindex.php', 1)));
exit;
