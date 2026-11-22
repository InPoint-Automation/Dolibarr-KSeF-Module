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
 * \file    lib/ksef_requirements.lib.php
 * \ingroup ksef
 * \brief   Guide and send requirements
 */

/**
 * @brief Requirement modules
 * @return array key => module data
 */
function ksefRequirementModules()
{
    return array(
        'mod_facture' => array('mod' => 'facture', 'class' => 'modFacture'),
        'mod_societe' => array('mod' => 'societe', 'class' => 'modSociete'),
        'mod_commande' => array('mod' => 'commande', 'class' => 'modCommande'),
        'mod_multicurrency' => array('mod' => 'multicurrency', 'class' => 'modMultiCurrency'),
        'mod_banque' => array('mod' => 'banque', 'class' => 'modBanque'),
        'mod_cron' => array('mod' => 'cron', 'class' => 'modCron'),
        'mod_supplier_invoice' => array('mod' => 'supplier_invoice', 'class' => is_file(DOL_DOCUMENT_ROOT . '/core/modules/modSupplierInvoice.class.php') ? 'modSupplierInvoice' : 'modFournisseur'),
    );
}

/**
 * @brief Guide requirement keys
 * @param string $tour Guide id
 * @param string $way '' or guide way
 * @return string[]
 */
function ksefRequirementKeys($tour, $way = '')
{
    $company = array('company_name', 'company_nip', 'company_address', 'main_currency');
    $send = array_merge($company, array('mod_facture', 'mod_societe', 'auth_saved'));
    $recv = array('company_nip', 'mod_societe', 'mod_supplier_invoice');
    $map = array(
        'setup_auth' => array('company_nip'),
        'setup_incoming' => array('mod_cron'),
        'send_vat' => $send,
        'send_kor' => $send,
        'send_zal' => $send,
        'send_roz' => $send,
        'send_kor_zal' => $send,
        'send_offline' => array_merge($send, array('offline_cert')),
        'send_sales' => array('company_nip', 'mod_facture', 'mod_societe', 'auth_saved'),
        'recv_fetch' => array('company_nip', 'auth_saved', 'mod_cron', 'cron_jobs'),
        'recv_import' => $recv,
        'recv_kor' => $recv,
        'recv_deposits' => array_merge($recv, array('mod_banque', 'bank_account')),
    );
    $keys = $map[$tour] ?? array();
    $ways = array(
        'send_vat' => array('eur' => array('mod_multicurrency', 'currency_foreign')),
        'send_zal' => array('order' => array('mod_commande')),
        'send_roz' => array('order' => array('mod_commande')),
    );
    if ($way !== '' && isset($ways[$tour][$way])) {
        $keys = array_merge($keys, $ways[$tour][$way]);
    }
    return array_values(array_unique($keys));
}

/**
 * @brief Guide requirements
 * @param DoliDB $db
 * @param string $tour Guide id
 * @param string $way '' or guide way
 * @param User|null $user null current
 * @return array
 */
function ksefGuideRequirements($db, $tour, $way = '', $user = null)
{
    return ksefRequirementRows($db, ksefRequirementKeys($tour, $way), $user);
}

/**
 * @brief Unmet requirements
 * @param DoliDB $db
 * @param string[] $keys
 * @param User|null $user
 * @return array
 */
function ksefRequirementsUnmet($db, $keys, $user = null)
{
    return array_values(array_filter(ksefRequirementRows($db, $keys, $user), function ($r) {
        return !$r['met'];
    }));
}

/**
 * @brief Requirement rows
 * @param DoliDB $db
 * @param string[] $keys
 * @param User|null $user null current
 * @return array
 */
function ksefRequirementRows($db, $keys, $user = null)
{
    global $langs;
    if ($user === null) {
        $user = $GLOBALS['user'] ?? null;
    }
    $langs->loadLangs(array('admin', 'ksef@ksef'));
    $order = array('company_name', 'company_nip', 'company_address', 'main_currency', 'mod_facture', 'mod_societe', 'mod_supplier_invoice', 'mod_commande',
        'mod_multicurrency', 'currency_foreign', 'mod_banque', 'bank_account', 'mod_cron', 'cron_jobs', 'auth_saved', 'auth', 'offline_cert');
    $rows = array();
    foreach ($order as $key) {
        if (in_array($key, (array) $keys, true)) {
            $rows[] = ksefRequirementRow($db, $key, $user);
        }
    }
    return $rows;
}

/**
 * @brief One requirement
 * @param DoliDB $db
 * @param string $key
 * @param User|null $user
 * @return array
 */
function ksefRequirementRow($db, $key, $user = null)
{
    global $langs, $mysoc, $conf;
    $admin = is_object($user) && !empty($user->admin);
    $env = ksefGetEnvironment();
    $envLabel = $langs->transnoentitiesnoconv('KSEF_ENV_' . $env);
    $row = array('key' => $key, 'met' => false, 'label' => '', 'detail' => '', 'fix' => null);
    $fix = null;
    $company = DOL_URL_ROOT . '/admin/company.php';
    $mods = ksefRequirementModules();
    if (isset($mods[$key])) {
        $row['met'] = isModEnabled($mods[$key]['mod']);
        $row['label'] = $langs->transnoentitiesnoconv('KSEF_Req_module', ksefRequirementModuleName($db, $mods[$key]['class']));
        $fix = array('kind' => 'action', 'label' => $langs->transnoentitiesnoconv('KSEF_ReqFix_Enable'), 'admin' => true);
    } elseif ($key === 'company_name') {
        $row['met'] = trim((string) $mysoc->name) !== '';
        $row['label'] = $langs->transnoentitiesnoconv('KSEF_Req_company_name');
        $fix = array('kind' => 'page', 'url' => $company, 'field' => '#name', 'label' => $langs->transnoentitiesnoconv('KSEF_ReqFix_Fill'), 'admin' => true);
    } elseif ($key === 'company_nip') {
        $nip = ksefSellerNip();
        $row['met'] = $nip !== '' && ksefValidateNIP($nip);
        $row['label'] = $langs->transnoentitiesnoconv('KSEF_Req_company_nip');
        $row['detail'] = $row['met'] ? '' : $langs->transnoentitiesnoconv('KSEF_Help_Detail_NoNip');
        $fix = array('kind' => 'page', 'url' => dol_buildpath('/ksef/admin/setup.php', 1), 'field' => 'input[name="KSEF_COMPANY_NIP"]',
            'label' => $langs->transnoentitiesnoconv('KSEF_ReqFix_Fill'), 'admin' => true);
    } elseif ($key === 'company_address') {
        $row['met'] = ksefRequirementAddressOk($mysoc);
        $row['label'] = $langs->transnoentitiesnoconv('KSEF_Req_company_address');
        $fix = array('kind' => 'page', 'url' => $company, 'field' => ksefRequirementAddressField($mysoc), 'label' => $langs->transnoentitiesnoconv('KSEF_ReqFix_Fill'), 'admin' => true);
    } elseif ($key === 'main_currency') {
        $cur = (string) $conf->currency;
        $row['met'] = $cur === 'PLN';
        $row['label'] = $langs->transnoentitiesnoconv('KSEF_Req_main_currency');
        $row['detail'] = $row['met'] ? '' : $langs->transnoentitiesnoconv('KSEF_MainCurrencyNotPln', $cur);
        $fix = array('kind' => 'action', 'label' => $langs->transnoentitiesnoconv('KSEF_ReqFix_SetPln'), 'admin' => true,
            'confirm' => $langs->transnoentitiesnoconv('KSEF_ReqFix_SetPlnConfirm', $cur, $cur));
    } elseif ($key === 'currency_foreign') {
        $n = 0;
        if (isModEnabled('multicurrency')) {
            $res = $db->query("SELECT COUNT(*) AS n FROM " . MAIN_DB_PREFIX . "multicurrency WHERE code <> 'PLN' AND entity IN (" . getEntity('multicurrency') . ")");
            $n = ($res && ($o = $db->fetch_object($res))) ? (int) $o->n : 0;
        }
        $row['met'] = $n > 0;
        $row['label'] = $langs->transnoentitiesnoconv('KSEF_Req_currency_foreign');
        $fix = array('kind' => 'action', 'label' => $langs->transnoentitiesnoconv('KSEF_ReqFix_AddEurNbp'), 'admin' => true);
    } elseif ($key === 'bank_account') {
        $n = 0;
        if (isModEnabled('banque')) {
            $res = $db->query("SELECT COUNT(*) AS n FROM " . MAIN_DB_PREFIX . "bank_account WHERE clos = 0 AND entity IN (" . getEntity('bank_account') . ")");
            $n = ($res && ($o = $db->fetch_object($res))) ? (int) $o->n : 0;
        }
        $row['met'] = $n > 0;
        $row['label'] = $langs->transnoentitiesnoconv('KSEF_Req_bank_account');
        $fix = array('kind' => 'page', 'url' => ksefRequirementBankCreateUrl(), 'field' => 'input[name="ref"]',
            'label' => $langs->transnoentitiesnoconv('KSEF_ReqFix_AddBank'), 'admin' => !(is_object($user) && $user->hasRight('banque', 'configurer')));
    } elseif ($key === 'cron_jobs') {
        $jobs = ksefGetCronJobs($db);
        $off = isModEnabled('cron') ? ksefCronRequiredOff($jobs) : array();
        $row['met'] = isModEnabled('cron') && empty($off);
        $row['label'] = $langs->transnoentitiesnoconv('KSEF_Req_cron_jobs');
        $names = array();
        foreach (array_keys($off) as $m) {
            $names[] = $langs->transnoentitiesnoconv('KSEF_CronJob_' . $m);
        }
        $row['detail'] = !isModEnabled('cron') ? $langs->transnoentitiesnoconv('KSEF_CronEngineOff') : ($names ? $langs->transnoentitiesnoconv('KSEF_Req_cron_jobs_off', implode(', ', $names)) : '');
        $fix = array('kind' => 'action', 'label' => $langs->transnoentitiesnoconv('KSEF_ReqFix_Enable'), 'admin' => true);
    } elseif ($key === 'auth_saved') {
        $auth = ksefAuthReadiness($env);
        $row['met'] = $auth['ready'];
        $row['label'] = $langs->transnoentitiesnoconv('KSEF_Req_auth_saved', $envLabel);
        $row['detail'] = $row['met'] ? '' : $langs->transnoentitiesnoconv('KSEF_Help_Detail_NoAuth', $envLabel);
        $fix = array('kind' => 'page', 'url' => dol_buildpath('/ksef/admin/setup_auth.php', 1) . '#env-' . $env, 'field' => '#env-' . $env . ' input[name="KSEF_AUTH_TOKEN"]',
            'label' => $langs->transnoentitiesnoconv('KSEF_ReqFix_Fill'), 'admin' => true);
    } elseif ($key === 'auth') {
        $auth = ksefAuthReadiness($env);
        $test = ksefAuthLastTest($env);
        $row['met'] = $auth['ready'] && $test['result'] === 'ok';
        $row['label'] = $langs->transnoentitiesnoconv('KSEF_Req_auth', $envLabel);
        $row['detail'] = $row['met'] ? '' : (!$auth['ready'] ? $langs->transnoentitiesnoconv('KSEF_Help_Detail_NoAuth', $envLabel)
            : $langs->transnoentitiesnoconv($test['result'] === 'fail' ? 'KSEF_Help_Detail_AuthTestFailed' : 'KSEF_Help_Detail_AuthNoTestRecord', $envLabel));
        $fix = array('kind' => 'page', 'url' => dol_buildpath('/ksef/admin/setup_auth.php', 1) . '#env-' . $env,
            'field' => '#env-' . $env . ($auth['ready'] ? ' a.ksef-test-active' : ' input[name="KSEF_AUTH_TOKEN"]'),
            'label' => $langs->transnoentitiesnoconv($auth['ready'] ? 'KSEF_ReqFix_Test' : 'KSEF_ReqFix_Fill'), 'admin' => true);
    } elseif ($key === 'offline_cert') {
        $cert = ksefOfflineCertUsable($env);
        $row['met'] = $cert['ok'];
        $row['label'] = $langs->transnoentitiesnoconv('KSEF_Req_offline_cert', $envLabel);
        $row['detail'] = $cert['ok'] ? '' : trim($cert['message'] . ' ' . ksefOfflineCertSourceText($env));
        $row['portal'] = ksefTaxpayerAppUrl($env);
        $fix = array('kind' => 'page', 'url' => dol_buildpath('/ksef/admin/setup_auth.php', 1) . '#env-' . $env, 'field' => '#env-' . $env . ' ' . $cert['field'],
            'label' => $langs->transnoentitiesnoconv('KSEF_ReqFix_Cert'), 'admin' => true);
    }
    if ($row['met'] || $fix === null) {
        return $row;
    }
    if (!empty($fix['admin']) && !$admin) {
        $row['detail'] = trim($row['detail'] . ' ' . $langs->transnoentitiesnoconv('KSEF_ReqAskAdmin'));
        return $row;
    }
    if ($fix['kind'] === 'action') {
        $fix['url'] = ksefRequirementFixUrl($key);
    }
    $row['fix'] = array('url' => $fix['url'], 'label' => $fix['label'], 'kind' => $fix['kind'], 'confirm' => (string) ($fix['confirm'] ?? ''), 'field' => (string) ($fix['field'] ?? ''));
    return $row;
}

/**
 * @brief Company address complete
 * @param Societe $soc
 * @return bool
 */
function ksefRequirementAddressOk($soc)
{
    return ksefRequirementAddressField($soc) === '';
}

/**
 * @brief First missing field
 * @param Societe $soc
 * @return string Selector or ''
 */
function ksefRequirementAddressField($soc)
{
    $fields = array('address' => '#MAIN_INFO_SOCIETE_ADDRESS', 'zip' => '#MAIN_INFO_SOCIETE_ZIP', 'town' => '#MAIN_INFO_SOCIETE_TOWN', 'country_code' => '#selectcountry_id');
    foreach ($fields as $f => $sel) {
        if (!is_object($soc) || trim((string) $soc->$f) === '') {
            return $sel;
        }
    }
    return '';
}

/**
 * @brief Module display name
 * @param DoliDB $db
 * @param string $class Module class
 * @return string
 */
function ksefRequirementModuleName($db, $class)
{
    global $langs;
    $file = DOL_DOCUMENT_ROOT . '/core/modules/' . $class . '.class.php';
    if (is_file($file)) {
        include_once $file;
        if (class_exists($class)) {
            $mod = new $class($db);
            return (string) $mod->getName();
        }
    }
    return $class;
}

/**
 * @brief One-click fix URL
 * @param string $key
 * @param string $back '' current page
 * @return string
 */
function ksefRequirementFixUrl($key, $back = '')
{
    if ($back === '') {
        $back = ksefRequirementBackUrl();
    }
    return dol_buildpath('/ksef/ksef_fix.php', 1) . '?action=fix&req=' . urlencode($key) . '&token=' . newToken() . ($back !== '' ? '&backtopage=' . urlencode($back) : '');
}

/**
 * @brief Fix return page
 * @return string Local path or ''
 */
function ksefRequirementBackUrl()
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if ($uri !== '' && !ksefRequirementBackRefused($uri)) {
        return $uri;
    }
    $page = (string) GETPOST('ksef_page', 'alpha');
    if ($page === '' && !empty($_SERVER['HTTP_REFERER'])) {
        $ref = parse_url((string) $_SERVER['HTTP_REFERER']);
        if (!empty($ref['path']) && (empty($ref['host']) || $ref['host'] === parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST))) {
            $page = $ref['path'] . (isset($ref['query']) ? '?' . $ref['query'] : '');
        }
    }
    if ($page !== '' && preg_match('#^/(?![/\\\\])#', $page) && !ksefRequirementBackRefused($page)) {
        return $page;
    }
    return '';
}

/**
 * @brief Return page refused
 * @param string $url
 * @return bool
 */
function ksefRequirementBackRefused($url)
{
    return (bool) preg_match('#/ksef_help\.php\?(?:.*&)?action=display|/ksef_fix\.php#', (string) $url);
}

/**
 * @brief Bank create URL
 * @param string $back '' current page
 * @return string
 */
function ksefRequirementBankCreateUrl($back = '')
{
    if ($back === '') {
        $back = ksefRequirementBackUrl();
    }
    $after = dol_buildpath('/ksef/ksef_fix.php', 1) . '?action=bankdefault&account=__ID__&token=' . newToken() . ($back !== '' ? '&backtopage=' . urlencode($back) : '');
    return DOL_URL_ROOT . '/compta/bank/card.php?action=create&backtopage=' . urlencode($after) . ($back !== '' ? '&backtopageforcancel=' . urlencode($back) : '');
}

/**
 * @brief Default new account
 * @param DoliDB $db
 * @param User $user
 * @param int $id Bank account
 * @return bool True when set
 */
function ksefRequirementBankDefault($db, $user, $id)
{
    global $conf;
    if ((int) $id <= 0 || !is_object($user) || !(!empty($user->admin) || $user->hasRight('banque', 'configurer'))) {
        return false;
    }
    $cur = getDolGlobalInt('KSEF_DEFAULT_BANK_ACCOUNT_ID');
    if ($cur > 0 && $db->getRow("SELECT rowid FROM " . MAIN_DB_PREFIX . "bank_account WHERE rowid = " . $cur . " AND clos = 0")) {
        return false;
    }
    if (!$db->getRow("SELECT rowid FROM " . MAIN_DB_PREFIX . "bank_account WHERE rowid = " . (int) $id . " AND clos = 0 AND entity IN (" . getEntity('bank_account') . ")")) {
        return false;
    }
    require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
    return dolibarr_set_const($db, 'KSEF_DEFAULT_BANK_ACCOUNT_ID', (string) (int) $id, 'chaine', 0, '', $conf->entity) > 0;
}

/**
 * @brief Add NBP currency
 * @param DoliDB $db
 * @param User $user
 * @param string $code
 * @return string Error or ''
 */
function ksefRequirementAddCurrency($db, $user, $code)
{
    global $conf, $langs;
    $code = strtoupper(trim((string) $code));
    if ((string) $conf->currency !== 'PLN') {
        return $langs->transnoentitiesnoconv('KSEF_MainCurrencyNotPln', (string) $conf->currency);
    }
    if (!isModEnabled('multicurrency')) {
        $res = activateModule('modMultiCurrency');
        if (!empty($res['errors'])) {
            return implode(', ', (array) $res['errors']);
        }
    }
    require_once DOL_DOCUMENT_ROOT . '/multicurrency/class/multicurrency.class.php';
    dol_include_once('/ksef/class/ksef_nbp_currency_rate.class.php');
    $nbp = new KsefNbpCurrencyRate($db);
    $rate = $nbp->getRateForDate($code, dol_now());
    if (!$rate || (float) $rate['rate'] <= 0) {
        return $langs->transnoentitiesnoconv('KSEF_ReqFix_NbpFailed', $code);
    }
    if (MultiCurrency::getIdFromCode($db, $code) <= 0) {
        $langs->loadCacheCurrencies('');
        $mc = new MultiCurrency($db);
        $mc->code = $code;
        $mc->name = !empty($langs->cache_currencies[$code]['label']) ? $langs->cache_currencies[$code]['label'] . ' (' . $langs->getCurrencySymbol($code) . ')' : $code;
        if ($mc->create($user) <= 0) {
            return $mc->error ? $mc->error : implode(', ', (array) $mc->errors);
        }
    }
    if (!$nbp->storeNbpRate($code, $rate, $user)) {
        return $langs->transnoentitiesnoconv('KSEF_ReqFix_NbpFailed', $code);
    }
    dol_syslog("KSEF requirement fix: currency " . $code . " added with NBP rate " . $rate['rate'] . " of " . $rate['date'] . " by user " . (int) $user->id, LOG_INFO);
    return '';
}

/**
 * @brief Offline certificate usable
 * @param string $env
 * @return array ok, message, field
 */
function ksefOfflineCertUsable($env)
{
    global $langs;
    $env = strtoupper((string) $env);
    $cert = ksefIsOfflineCertificateConfigured($env);
    $out = array('ok' => false, 'message' => '', 'field' => 'input[name="offline_cert_file"]');
    $parts = array('certificate' => 'KSEF_OfflinePart_Cert', 'private_key' => 'KSEF_OfflinePart_Key', 'password' => 'KSEF_OfflinePart_Password');
    if (!empty($cert['missing'])) {
        $names = array();
        foreach ($cert['missing'] as $m) {
            $names[] = $langs->transnoentitiesnoconv($parts[$m]);
        }
        $first = $cert['missing'][0];
        $out['field'] = $first === 'certificate' ? 'input[name="offline_cert_file"]' : ($first === 'private_key' ? 'input[name="offline_key_file"]' : 'input[name="offline_key_password"]');
        $out['message'] = $langs->transnoentitiesnoconv('KSEF_OfflineCertMissingParts', implode(', ', $names));
        return $out;
    }
    if (empty($cert['valid'])) {
        $out['message'] = ksefOfflineCertRefusalText('KSEF_OfflineCertificateRequired', $cert);
        return $out;
    }
    ksefOpensslErrors();
    $pem = (string) base64_decode(getDolGlobalString('KSEF_OFFLINE_CERTIFICATE_' . $env));
    $key = (string) base64_decode(getDolGlobalString('KSEF_OFFLINE_PRIVATE_KEY_' . $env));
    $pass = getDolGlobalString('KSEF_OFFLINE_KEY_PASSWORD_' . $env);
    $x509 = @openssl_x509_read($pem);
    $pkey = $x509 ? @openssl_pkey_get_private($key, $pass !== '' ? dol_decode($pass) : '') : false;
    ksefOpensslErrors();
    if (!$x509) {
        $out['message'] = $langs->transnoentitiesnoconv('KSEF_CertificateInvalid');
    } elseif (!$pkey) {
        $out['field'] = 'input[name="offline_key_password"]';
        $out['message'] = $langs->transnoentitiesnoconv('KSEF_PrivateKeyPasswordMismatch');
    } elseif (!@openssl_x509_check_private_key($x509, $pkey)) {
        $out['field'] = 'input[name="offline_key_file"]';
        $out['message'] = $langs->transnoentitiesnoconv('KSEF_CertKeyMismatch');
    } else {
        $out['ok'] = true;
    }
    return $out;
}

/**
 * @brief Open accounts currency
 * @param DoliDB $db
 * @return array id => currency code
 */
function ksefBankAccountCurrencies($db)
{
    $out = array();
    if (!isModEnabled('bank')) {
        return $out;
    }
    $res = $db->query("SELECT rowid, currency_code FROM " . MAIN_DB_PREFIX . "bank_account WHERE clos = 0 AND entity IN (" . getEntity('bank_account') . ") ORDER BY rowid");
    while ($res && ($o = $db->fetch_object($res))) {
        $out[(int) $o->rowid] = (string) $o->currency_code;
    }
    return $out;
}

/**
 * @brief Account for currency
 * @param DoliDB $db
 * @param int $default Default account
 * @param string $currency Invoice currency
 * @return int Account id
 */
function ksefBankAccountFor($db, $default, $currency)
{
    $all = ksefBankAccountCurrencies($db);
    if ($currency === '' || (isset($all[(int) $default]) && $all[(int) $default] === $currency)) {
        return (int) $default;
    }
    $match = array_search($currency, $all, true);
    return $match !== false ? (int) $match : (int) $default;
}

/**
 * @brief Taxpayer app URL
 * @param string $env
 * @return string
 */
function ksefTaxpayerAppUrl($env)
{
    $urls = array('TEST' => 'https://ap-test.ksef.mf.gov.pl/web/', 'DEMO' => 'https://ap-demo.ksef.mf.gov.pl/web/', 'PRODUCTION' => 'https://ap.ksef.mf.gov.pl/web/');
    $env = strtoupper((string) $env);
    return isset($urls[$env]) ? $urls[$env] : $urls['TEST'];
}

/**
 * @brief Offline cert source
 * @param string $env
 * @return string Plain text
 */
function ksefOfflineCertSourceText($env)
{
    global $langs;
    $env = strtoupper((string) $env);
    return $langs->transnoentitiesnoconv('KSEF_OfflineCertSource', $langs->transnoentitiesnoconv('KSEF_ENV_' . $env), ksefTaxpayerAppUrl($env));
}

/**
 * @brief Run one-click fix
 * @param DoliDB $db
 * @param User $user
 * @param string $key
 * @return array ok, message
 */
function ksefRequirementFix($db, $user, $key)
{
    global $langs, $conf;
    $langs->loadLangs(array('admin', 'ksef@ksef'));
    if (empty($user->admin)) {
        return array('ok' => false, 'message' => $langs->transnoentitiesnoconv('KSEF_ReqAskAdmin'));
    }
    $row = ksefRequirementRow($db, $key, $user);
    if ($row['met']) {
        return array('ok' => true, 'message' => $langs->transnoentitiesnoconv('KSEF_ReqFixDone', $row['label']));
    }
    require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
    $mods = ksefRequirementModules();
    $error = '';
    if (isset($mods[$key]) || $key === 'cron_jobs') {
        $class = isset($mods[$key]) ? $mods[$key]['class'] : 'modCron';
        if (!isModEnabled(isset($mods[$key]) ? $mods[$key]['mod'] : 'cron')) {
            $res = activateModule($class);
            if (!empty($res['errors'])) {
                $error = implode(', ', (array) $res['errors']);
            }
        }
        if ($error === '' && $key === 'cron_jobs') {
            $on = ksefCronEnableRequired($db, $user);
            $error = $on['error'];
        }
    } elseif ($key === 'currency_foreign') {
        $mcOff = !isModEnabled('multicurrency');
        $error = ksefRequirementAddCurrency($db, $user, 'EUR');
        if ($error === '') {
            // what was done
            return array('ok' => true, 'message' => $mcOff ? $langs->transnoentitiesnoconv('KSEF_ReqFixCurrencyModule', $langs->transnoentitiesnoconv('Module40000Name'), 'EUR')
                : $langs->transnoentitiesnoconv('KSEF_ReqFixCurrency', 'EUR'));
        }
    } elseif ($key === 'main_currency') {
        if (dolibarr_set_const($db, 'MAIN_MONNAIE', 'PLN', 'chaine', 0, '', $conf->entity) <= 0) {
            $error = $db->lasterror();
        } else {
            $conf->currency = 'PLN';
            dol_syslog("KSEF requirement fix: main currency switched to PLN by user " . (int) $user->id, LOG_WARNING);
        }
    } else {
        return array('ok' => false, 'message' => $row['label']);
    }
    if ($error !== '') {
        return array('ok' => false, 'message' => $langs->transnoentitiesnoconv('KSEF_ReqFixFailed', $row['label'] . ': ' . $error));
    }
    return array('ok' => true, 'message' => $langs->transnoentitiesnoconv('KSEF_ReqFixDone', $row['label']));
}

/**
 * @brief Requirements markup
 * @param array $rows
 * @return string HTML
 */
function ksefRequirementsHtml($rows)
{
    $out = '<ul class="ksef-req-list">';
    foreach ($rows as $r) {
        $out .= '<li class="ksef-req" data-ksef-req="' . dol_escape_htmltag($r['key']) . '" data-met="' . ($r['met'] ? 1 : 0) . '">'
            . '<span class="fa ' . ($r['met'] ? 'fa-check-circle ksef-req-ok' : 'fa-times-circle ksef-req-no') . ' pictofixedwidth"></span>'
            . '<span class="ksef-req-label">' . dol_escape_htmltag($r['label']) . '</span>';
        if ($r['detail'] !== '') {
            $out .= ' <span class="ksef-req-detail opacitymedium">' . dol_escape_htmltag($r['detail']) . '</span>';
        }
        if (!empty($r['fix'])) {
            $f = $r['fix'];
            $out .= ' <a class="ksef-req-fix" data-kind="' . dol_escape_htmltag($f['kind']) . '"' . ($f['confirm'] !== '' ? ' data-confirm="' . dol_escape_htmltag($f['confirm']) . '"' : '')
                . ($f['field'] !== '' ? ' data-field="' . dol_escape_htmltag($f['field']) . '"' : '') . ' href="' . dol_escape_htmltag($f['url']) . '">' . dol_escape_htmltag($f['label']) . '</a>';
        }
        $out .= '</li>';
    }
    return $out . '</ul>';
}

/**
 * @brief Preflight requirement refusal
 * @param DoliDB $db
 * @return array|null code, message HTML
 */
function ksefRequirementSendRefusal($db)
{
    global $langs;
    $unmet = ksefRequirementsUnmet($db, array('company_name', 'company_nip', 'company_address'));
    if (!$unmet) {
        return null;
    }
    $parts = array();
    foreach ($unmet as $r) {
        $parts[] = dol_escape_htmltag($r['label']) . (!empty($r['fix']) ? ' <a class="ksef-req-fix" href="' . dol_escape_htmltag($r['fix']['url']) . '">' . dol_escape_htmltag($r['fix']['label']) . '</a>' : '');
    }
    return array('code' => 'company_incomplete', 'message' => '<span class="ksef-req-refusal">' . dol_escape_htmltag($langs->transnoentitiesnoconv('KSEF_ReqSendRefused')) . ' ' . implode('; ', $parts) . '</span>');
}
