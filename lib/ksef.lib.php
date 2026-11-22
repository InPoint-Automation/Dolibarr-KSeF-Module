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
 * \file    lib/ksef.lib.php
 * \ingroup ksef
 * \brief   common functions
 */

define('KSEF_PORTAL_URL_PRODUCTION', 'https://ksef.mf.gov.pl');
define('KSEF_PORTAL_URL_TEST', 'https://ksef-test.mf.gov.pl');
define('KSEF_PORTAL_URL_DEMO', 'https://ksef-demo.mf.gov.pl');

define('KSEF_QR_URL_PRODUCTION', 'https://qr.ksef.mf.gov.pl');
define('KSEF_QR_URL_TEST', 'https://qr-test.ksef.mf.gov.pl');
define('KSEF_QR_URL_DEMO', 'https://qr-demo.ksef.mf.gov.pl');

require_once __DIR__ . '/ksef_fx.lib.php';
require_once __DIR__ . '/ksef_deposit.lib.php';
require_once __DIR__ . '/ksef_correction.lib.php';
require_once __DIR__ . '/ksef_requirements.lib.php';
require_once __DIR__ . '/ksef_xsd.lib.php';
require_once __DIR__ . '/ksef_pick.lib.php';

define('KSEF_FA3_NAMESPACE', 'http://crd.gov.pl/wzor/2025/06/25/13775/');
define('KSEF_FA3_SCHEMA_VERSION', '1-0E');

define('KSEF_EXCESS_RECEIVED_DESCRIPTION', '(EXCESS RECEIVED)');
// matches core text
define('KSEF_DEPOSIT_CREDIT_DESCRIPTION', '(DEPOSIT)');

define('KSEF_SEND_MAX_POLLS', 30);
define('KSEF_SEND_RIGHT_MIGRATION', '1.5.0');

/**
 * @brief Module needs reactivation
 * @param string $currentVersion
 * @param string $lastInitVersion
 * @return bool
 */
function ksefNeedsReactivation($currentVersion, $lastInitVersion)
{
    if (empty($lastInitVersion)) return true;
    return version_compare($lastInitVersion, $currentVersion, '<');
}

/**
 * @brief Unsupported DodatkowyOpis types
 * @return string[]
 */
function ksefDodatkowyOpisUnsupportedTypes()
{
    return array('boolean', 'html', 'link', 'checkbox', 'chkbxlst', 'stars', 'radio', 'separate', 'password');
}

/**
 * @brief Portal base URL
 * @param string|null $environment Null for configured
 * @return string
 */
function ksefGetPortalBaseUrl($environment = null)
{
    if (empty($environment)) {
        $environment = ksefGetEnvironment();
    }

    switch (strtoupper($environment)) {
        case 'PRODUCTION':
            return KSEF_PORTAL_URL_PRODUCTION;
        case 'DEMO':
            return KSEF_PORTAL_URL_DEMO;
        case 'TEST':
        default:
            return KSEF_PORTAL_URL_TEST;
    }
}

/**
 * @brief Test endpoint override
 * @param string $service KSEF, LATARNIA, NBP, GUS
 * @param string $default
 * @return string
 */
function ksefEndpointOverride($service, $default)
{
    if (!defined('KSEF_ALLOW_TEST_OVERRIDE') || !constant('KSEF_ALLOW_TEST_OVERRIDE')) {
        return $default;
    }
    $url = getDolGlobalString('KSEF_TEST_URL_' . strtoupper($service));
    if ($url === '') {
        return $default;
    }
    return rtrim($url, '/');
}

/**
 * @brief Curl proxy options
 * @return array
 */
function ksefCurlProxyOptions()
{
    $opts = array();
    if (!getDolGlobalInt('MAIN_PROXY_USE')) {
        return $opts;
    }
    $opts[CURLOPT_PROXY] = getDolGlobalString('MAIN_PROXY_HOST') . ':' . getDolGlobalInt('MAIN_PROXY_PORT');
    if (getDolGlobalString('MAIN_PROXY_USER')) {
        $opts[CURLOPT_PROXYUSERPWD] = getDolGlobalString('MAIN_PROXY_USER') . ':' . getDolGlobalString('MAIN_PROXY_PASS');
    }
    return $opts;
}

/**
 * @brief Apply curl proxy
 * @param resource|CurlHandle $ch
 * @return void
 */
function ksefCurlApplyProxy($ch)
{
    $opts = ksefCurlProxyOptions();
    if (!empty($opts)) {
        curl_setopt_array($ch, $opts);
    }
}

/**
 * @brief Sleep between polls
 * @param int $seconds
 * @return void
 */
function ksefSleep($seconds)
{
    if (defined('KSEF_ALLOW_TEST_OVERRIDE') && constant('KSEF_ALLOW_TEST_OVERRIDE') && getDolGlobalString('KSEF_TEST_SKIP_SLEEP')) {
        return;
    }
    sleep($seconds);
}

/**
 * @brief Request timeout
 * @return int Seconds
 */
function ksefGetTimeout()
{
    $raw = trim(getDolGlobalString('KSEF_TIMEOUT', ''));
    if ($raw === '' || !preg_match('/^\d+$/', $raw) || (int) $raw <= 0) {
        return 7;
    }
    return max(3, min(120, (int) $raw));
}

/**
 * @brief Stale send age
 * @return int Seconds
 */
function ksefSendStaleAfter()
{
    $t = ksefGetTimeout();
    $worst = 5 * $t + KSEF_SEND_MAX_POLLS * (2 + $t);
    return max(600, 2 * $worst);
}

/**
 * @brief Endpoint override active
 * @param string $service
 * @return bool
 */
function ksefEndpointIsOverridden($service)
{
    return ksefEndpointOverride($service, '') !== '';
}

/**
 * @brief QR base URL
 * @param string|null $environment Null for configured
 * @return string
 */
function ksefGetQrBaseUrl($environment = null)
{
    if (empty($environment)) {
        $environment = ksefGetEnvironment();
    }

    switch (strtoupper($environment)) {
        case 'PRODUCTION':
            return KSEF_QR_URL_PRODUCTION;
        case 'DEMO':
            return KSEF_QR_URL_DEMO;
        case 'TEST':
        default:
            return KSEF_QR_URL_TEST;
    }
}

/**
 * @brief Latarnia status badge
 * @param string $status
 * @return string HTML
 */
function ksefGetLatarniaStatusBadge($status)
{
    global $langs;
    $langs->load("ksef@ksef");

    $badges = array(
        'AVAILABLE'     => '<span class="badge ' . ksefToneBadgeClass('ok') . ' badge-status">' . $langs->trans('KSEF_LATARNIA_AVAILABLE') . '</span>',
        'MAINTENANCE'   => '<span class="badge badge-status1 ' . ksefToneClass('offline') . ' badge-status">' . $langs->trans('KSEF_LATARNIA_MAINTENANCE') . '</span>',
        'FAILURE'       => '<span class="badge ' . ksefToneBadgeClass('problem') . ' badge-status">' . $langs->trans('KSEF_LATARNIA_FAILURE') . '</span>',
        'TOTAL_FAILURE' => '<span class="badge ' . ksefToneBadgeClass('problem') . ' badge-status">' . $langs->trans('KSEF_LATARNIA_TOTAL_FAILURE') . '</span>',
        'UNREACHABLE'   => '<span class="badge badge-status0 ' . ksefToneClass('info') . ' badge-status"><i class="fas fa-exclamation-triangle"></i> ' . $langs->trans('KSEF_LATARNIA_UNREACHABLE') . '</span>',
        'UNKNOWN'       => '<span class="badge badge-status0 ' . ksefToneClass('info') . ' badge-status">' . $langs->trans('KSEF_LATARNIA_UNKNOWN') . '</span>',
    );

    return isset($badges[$status]) ? $badges[$status] : '<span class="badge badge-status0">' . htmlspecialchars($status) . '</span>';
}

/**
 * @brief Admin head tabs
 * @return array
 */
function ksefAdminPrepareHead()
{
    global $langs, $conf;
    $langs->load("ksef@ksef");

    $h = 0;
    $head = array();

    $head[$h][0] = dol_buildpath("/ksef/admin/setup.php", 1);
    $head[$h][1] = $langs->trans("KSEF_Tab_General");
    $head[$h][2] = 'general';
    $h++;

    $head[$h][0] = dol_buildpath("/ksef/admin/setup_auth.php", 1);
    $head[$h][1] = $langs->trans("KSEF_Tab_Authentication");
    $head[$h][2] = 'auth';
    $h++;

    $head[$h][0] = dol_buildpath("/ksef/admin/setup_outgoing.php", 1);
    $head[$h][1] = $langs->trans("KSEF_Tab_Outgoing");
    $head[$h][2] = 'outgoing';
    $h++;

    $head[$h][0] = dol_buildpath("/ksef/admin/setup_fields.php", 1);
    $head[$h][1] = $langs->trans("KSEF_Tab_Fields");
    $head[$h][2] = 'fields';
    $h++;

    $head[$h][0] = dol_buildpath("/ksef/admin/setup_incoming.php", 1);
    $head[$h][1] = $langs->trans("KSEF_Tab_Incoming");
    $head[$h][2] = 'incoming';
    $h++;

    $head[$h][0] = dol_buildpath("/ksef/admin/setup_dictionaries.php", 1);
    $head[$h][1] = $langs->trans("KSEF_Tab_Dictionaries");
    $head[$h][2] = 'dictionaries';
    $h++;

    $head[$h][0] = dol_buildpath("/ksef/admin/howtouse.php", 1);
    $head[$h][1] = $langs->trans("KSEF_HowToUse");
    $head[$h][2] = 'howtouse';
    $h++;

    $head[$h][0] = dol_buildpath("/ksef/admin/about.php", 1);
    $head[$h][1] = $langs->trans("KSEF_About");
    $head[$h][2] = 'about';
    $h++;

    complete_head_from_modules($conf, $langs, null, $head, $h, 'ksef');
    global $user;
    if (empty($user->admin)) {
        $head = array_values(array_filter($head, function ($tab) {
            return isset($tab[2]) && $tab[2] === 'howtouse';
        }));
    }
    return $head;
}

/**
 * @brief Format NIP
 * @param $nip
 * @return string
 */
function ksefFormatNIP($nip)
{
    return ksefCleanNIP($nip);
}

/**
 * @brief Clean NIP digits
 * @param $nip
 * @return string
 */
function ksefCleanNIP($nip)
{
    return preg_replace('/[^0-9]/', '', (string) $nip);
}

/**
 * @brief Seller NIP
 * @return string
 */
function ksefSellerNip()
{
    global $mysoc;
    $nip = ksefCleanNIP(getDolGlobalString('KSEF_COMPANY_NIP'));
    if ($nip === '' && is_object($mysoc)) {
        $nip = ksefCleanNIP(ksefGetIdentifierField($mysoc, 'NIP'));
    }
    return $nip;
}

/**
 * @brief Clean REGON
 * @param $regon
 * @return string
 */
function ksefCleanREGON($regon)
{
    return substr(ksefCleanNIP($regon), 0, 9);
}

/**
 * @brief Escape XML value
 * @param $value
 * @return string
 */
function ksefXmlEscape($value)
{
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $value);
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * @brief Validate NIP
 * @param $nip
 * @return bool
 */
function ksefValidateNIP($nip)
{
    $nip = ksefCleanNIP((string) $nip);
    if (strlen($nip) != 10) return false;
    // FA(3) TNrNIP pattern
    if (!preg_match('/^[1-9]((\d[1-9])|([1-9]\d))\d{7}$/', $nip)) return false;

    $weights = array(6, 5, 7, 2, 3, 4, 5, 6, 7);
    $sum = 0;
    for ($i = 0; $i < 9; $i++) {
        $sum += (int)$nip[$i] * $weights[$i];
    }

    $checksum = $sum % 11;
    if ($checksum == 10) return false;

    return ($checksum == (int)$nip[9]);
}

/**
 * @brief Strip VAT prefix
 * @param string $vatId
 * @return string
 */
function ksefStripVATPrefix($vatId)
{
    $vatId = trim($vatId);
    if (preg_match('/^[A-Za-z]{2}(.+)$/', $vatId, $m)) {
        return $m[1];
    }
    return $vatId;
}

/**
 * @brief EU country code
 * @param string $cc ISO code
 * @return string
 */
function ksefKodUE($cc)
{
    $cc = strtoupper((string) $cc);
    return $cc === 'GR' ? 'EL' : $cc;
}

/**
 * @brief NrVatUE value
 * @param string $vat
 * @return string
 */
function ksefNrVatUE($vat)
{
    return ksefStripVATPrefix(preg_replace('/[^0-9A-Z+*]/', '', strtoupper((string) $vat)));
}

/**
 * @brief Buyer address lines
 * @param Societe $thirdparty
 * @return array
 */
function ksefBuyerAddressLines($thirdparty)
{
    $l2 = array();
    if (!empty($thirdparty->zip)) {
        $l2[] = trim($thirdparty->zip);
    }
    if (!empty($thirdparty->town)) {
        $l2[] = trim($thirdparty->town);
    }
    return array(
        'name' => trim((string) $thirdparty->name),
        'country' => ksefInferCountryCode($thirdparty),
        'l1' => !empty($thirdparty->address) ? trim($thirdparty->address) : 'Brak danych',
        'l2' => implode(' ', $l2),
    );
}

/**
 * @brief Infer country code
 * @param object $thirdparty
 * @return string
 */
function ksefInferCountryCode($thirdparty)
{
    if (!empty($thirdparty->country_code)) {
        return strtoupper($thirdparty->country_code);
    }
    if (!empty($thirdparty->tva_intra) && preg_match('/^([A-Za-z]{2})/', trim($thirdparty->tva_intra), $m)) {
        return strtoupper($m[1]);
    }
    return 'PL';
}

/**
 * @brief Identifier field name
 * @param string $identifier NIP, KRS, REGON, BDO, EORI
 * @return string '' when disabled
 */
function ksefGetFieldName($identifier)
{
    global $conf;

    $defaults = array(
        'NIP'   => 'idprof1',
        'KRS'   => 'idprof2',
        'REGON' => 'idprof3',
        'BDO'   => 'idprof4',
        'EORI'  => 'idprof5',
    );

    $validFields = array('idprof1', 'idprof2', 'idprof3', 'idprof4', 'idprof5', 'idprof6', 'tva_intra');

    $constName = 'KSEF_FIELD_' . strtoupper($identifier);
    if (!empty($conf->global->$constName)) {
        $val = $conf->global->$constName;
        if ($val === 'disabled' && strtoupper($identifier) !== 'NIP') {
            return '';
        }
        if (in_array($val, $validFields)) {
            return $val;
        }
        return isset($defaults[$identifier]) ? $defaults[$identifier] : '';
    }

    return isset($defaults[$identifier]) ? $defaults[$identifier] : '';
}

/**
 * @brief Property to column
 * @param string $fieldName
 * @return string
 */
function ksefFieldToColumn($fieldName)
{
    $map = array(
        'idprof1' => 'siren',
        'idprof2' => 'siret',
        'idprof3' => 'ape',
    );
    return isset($map[$fieldName]) ? $map[$fieldName] : $fieldName;
}

/**
 * @brief FormaPlatnosci label
 * @param string|null $code
 * @return string|null
 */
function ksefGetPaymentMethodLabel($code)
{
    $labels = array(
        '1' => 'Gotówka',
        '2' => 'Karta',
        '3' => 'Bon',
        '4' => 'Czek',
        '5' => 'Kredyt',
        '6' => 'Przelew',
        '7' => 'Mobilna',
    );
    return isset($labels[$code]) ? $labels[$code] : null;
}

/**
 * @brief Identifier field value
 * @param object $thirdparty
 * @param string $identifier NIP, KRS, REGON, BDO, EORI
 * @return string
 */
function ksefGetIdentifierField($thirdparty, $identifier)
{
    $fieldName = ksefGetFieldName($identifier);

    if (!empty($fieldName) && !empty($thirdparty->$fieldName)) {
        return $thirdparty->$fieldName;
    }

    if ($identifier === 'NIP' && $fieldName !== 'tva_intra' && !empty($thirdparty->tva_intra)) {
        return $thirdparty->tva_intra;
    }

    return '';
}

/**
 * @brief Company identifier
 * @param Societe $mysoc
 * @param string $identifier NIP, KRS, REGON, BDO, EORI
 * @return string
 */
function ksefSetupCompanyValue($mysoc, $identifier)
{
    $val = (string) ksefGetIdentifierField($mysoc, $identifier);
    return $identifier === 'NIP' ? (string) ksefCleanNIP($val) : trim($val);
}

/**
 * @brief Identifier field options
 * @param bool $includeNone
 * @return array field => label
 */
function ksefGetFieldOptions($includeNone = false)
{
    global $langs, $mysoc;

    $country_code = !empty($mysoc->country_code) ? $mysoc->country_code : 'PL';
    $langs->load("companies");

    $options = array();
    if ($includeNone) {
        // no empty value
        $options['disabled'] = $langs->trans("KSEF_FIELD_NONE");
    }

    for ($i = 1; $i <= 6; $i++) {
        $longKey = "ProfId" . $i;
        $longLabel = $langs->transcountry($longKey, $country_code);
        if (empty($longLabel) || $longLabel === $longKey . $country_code || $longLabel === $longKey) {
            $longLabel = '';
        }
        $shortLabel = $langs->trans('KSEF_ProfIdShort', $i);
        if (!empty($longLabel) && $longLabel !== $shortLabel) {
            $options['idprof' . $i] = $shortLabel . ' - ' . $longLabel . ' (idprof' . $i . ')';
        } else {
            $options['idprof' . $i] = $shortLabel . ' (idprof' . $i . ')';
        }
    }

    $vatLabel = $langs->trans("VATIntra");
    if (empty($vatLabel) || $vatLabel === 'VATIntra') {
        $vatLabel = 'VAT Intra';
    }
    $options['tva_intra'] = $vatLabel . ' (tva_intra)';

    return $options;
}

/**
 * @brief Identifier translation overrides
 * @return array key => value
 */
function ksefGetManagedTranslationOverrides()
{
    $overrides = array();

    $identifiers = array(
        'NIP'   => 'NIP',
        'KRS'   => 'KRS',
        'REGON' => 'REGON',
        'BDO'   => 'BDO',
        'EORI'  => 'EORI',
    );

    foreach ($identifiers as $ident => $label) {
        $fieldName = ksefGetFieldName($ident);
        if (empty($fieldName)) {
            continue;
        }

        if ($fieldName === 'tva_intra') {
            if ($ident === 'NIP') {
                $overrides['VATIntra'] = 'VAT/NIP';
                $overrides['VATIntraShort'] = 'VAT/NIP';
            } else {
                $overrides['VATIntra'] = $label;
                $overrides['VATIntraShort'] = $label;
            }
        } elseif (preg_match('/^idprof(\d+)$/', $fieldName, $m)) {
            $overrides['ProfId' . $m[1] . 'PL'] = $label;
            $overrides['ProfId' . $m[1] . 'ShortPL'] = $label;
        }
    }

    if (!isset($overrides['VATIntra'])) {
        $overrides['VATIntra'] = 'VAT';
        $overrides['VATIntraShort'] = 'VAT';
    }

    return $overrides;
}

/**
 * @brief Managed translation keys
 * @return array
 */
function ksefGetAllManagedTransKeys()
{
    $keys = array('VATIntra', 'VATIntraShort');
    for ($i = 1; $i <= 6; $i++) {
        $keys[] = 'ProfId' . $i . 'PL';
        $keys[] = 'ProfId' . $i . 'ShortPL';
    }
    return $keys;
}

/**
 * @brief Apply translation overrides
 * @param DoliDB $db
 * @param array $languages
 * @return int -1 on error
 */
function ksefApplyTranslationOverrides($db, $languages)
{
    global $conf;

    if (empty($languages)) {
        return -1;
    }

    if (empty($conf->global->MAIN_ENABLE_OVERWRITE_TRANSLATION)) {
        dolibarr_set_const($db, 'MAIN_ENABLE_OVERWRITE_TRANSLATION', '1', 'chaine', 0, '', $conf->entity);
    }

    $overrides = ksefGetManagedTranslationOverrides();
    $allManagedKeys = ksefGetAllManagedTransKeys();

    $db->begin();

    foreach ($allManagedKeys as $transkey) {
        foreach ($languages as $lang) {
            $sql = "DELETE FROM " . MAIN_DB_PREFIX . "overwrite_trans";
            $sql .= " WHERE transkey = '" . $db->escape($transkey) . "'";
            $sql .= " AND lang = '" . $db->escape($lang) . "'";
            $sql .= " AND entity IN (0, " . ((int) $conf->entity) . ")";
            $db->query($sql);
        }
    }

    foreach ($overrides as $transkey => $transvalue) {
        foreach ($languages as $lang) {
            $sql = "INSERT INTO " . MAIN_DB_PREFIX . "overwrite_trans (lang, transkey, transvalue, entity)";
            $sql .= " VALUES ('" . $db->escape($lang) . "', '" . $db->escape($transkey) . "',";
            $sql .= " '" . $db->escape($transvalue) . "', " . ((int) $conf->entity) . ")";
            if (!$db->query($sql)) {
                $db->rollback();
                return -1;
            }
        }
    }

    $db->commit();
    return 1;
}

/**
 * @brief Remove translation overrides
 * @param DoliDB $db
 * @param array $languages
 * @return int -1 on error
 */
function ksefRemoveTranslationOverrides($db, $languages)
{
    global $conf;

    if (empty($languages)) {
        return -1;
    }

    $allManagedKeys = ksefGetAllManagedTransKeys();

    $db->begin();

    foreach ($allManagedKeys as $transkey) {
        foreach ($languages as $lang) {
            $sql = "DELETE FROM " . MAIN_DB_PREFIX . "overwrite_trans";
            $sql .= " WHERE transkey = '" . $db->escape($transkey) . "'";
            $sql .= " AND lang = '" . $db->escape($lang) . "'";
            $sql .= " AND entity IN (0, " . ((int) $conf->entity) . ")";
            if (!$db->query($sql)) {
                $db->rollback();
                return -1;
            }
        }
    }

    $db->commit();
    return 1;
}

/**
 * @brief Stored translation overrides
 * @param DoliDB $db
 * @return array
 */
function ksefGetCurrentTranslationOverrides($db)
{
    global $conf;

    $allManagedKeys = ksefGetAllManagedTransKeys();
    $escaped = array_map(function ($k) use ($db) {
        return "'" . $db->escape($k) . "'";
    }, $allManagedKeys);

    $sql = "SELECT lang, transkey, transvalue FROM " . MAIN_DB_PREFIX . "overwrite_trans";
    $sql .= " WHERE transkey IN (" . implode(',', $escaped) . ")";
    $sql .= " AND entity IN (0, " . ((int) $conf->entity) . ")";
    $sql .= " ORDER BY lang, transkey";

    $result = array();
    $resql = $db->query($sql);
    if ($resql) {
        while ($obj = $db->fetch_object($resql)) {
            $result[] = array(
                'lang' => $obj->lang,
                'transkey' => $obj->transkey,
                'transvalue' => $obj->transvalue,
            );
        }
    }

    return $result;
}

/**
 * @brief Languages in use
 * @param DoliDB $db
 * @return array pl_PL and / or en_US
 */
function ksefTranslationLanguagesInUse($db)
{
    global $conf;
    $all = array('pl_PL', 'en_US');
    $used = array(getDolGlobalString('MAIN_LANG_DEFAULT', 'auto'));
    $res = $db->query("SELECT DISTINCT p.value FROM " . MAIN_DB_PREFIX . "user_param AS p INNER JOIN " . MAIN_DB_PREFIX . "user AS u ON u.rowid = p.fk_user"
        . " WHERE p.param = 'MAIN_LANG_DEFAULT' AND u.statut = 1 AND p.entity IN (0, " . ((int) $conf->entity) . ")");
    while ($res && ($o = $db->fetch_object($res))) {
        $used[] = (string) $o->value;
    }
    if (in_array('auto', $used, true) || in_array('', $used, true)) {
        return $all;
    }
    $out = array_values(array_intersect($all, $used));
    return $out ? $out : $all;
}

/**
 * @brief Languages label
 * @param DoliDB $db
 * @return string
 */
function ksefTranslationLanguagesLabel($db)
{
    global $langs;
    $langs->load('languages');
    return implode(', ', array_map(function ($lg) use ($langs) {
        return $langs->transnoentitiesnoconv('Language_' . $lg);
    }, ksefTranslationLanguagesInUse($db)));
}

/**
 * @brief Overrides fully applied
 * @param DoliDB $db
 * @param array $languages
 * @return bool
 */
function ksefTranslationOverridesReady($db, $languages = null)
{
    global $conf;

    if (empty($conf->global->MAIN_ENABLE_OVERWRITE_TRANSLATION)) {
        return false;
    }
    if ($languages === null) {
        $languages = ksefTranslationLanguagesInUse($db);
    }

    $want = ksefGetManagedTranslationOverrides();

    $have = array();
    foreach (ksefGetCurrentTranslationOverrides($db) as $ov) {
        $have[$ov['lang']][$ov['transkey']] = $ov['transvalue'];
    }

    foreach ($want as $key => $value) {
        if (preg_match('/Short/', $key)) {
            continue;
        }
        foreach ($languages as $lang) {
            if (!isset($have[$lang][$key]) || $have[$lang][$key] !== $value) {
                return false;
            }
        }
    }

    return true;
}

/**
 * @brief Identifier input selector
 * @param $identifier NIP, KRS, REGON, BDO, EORI
 * @return string|null Null if unmapped
 */
function ksefGusDomFieldId($identifier)
{
    $fieldName = ksefGetFieldName($identifier);

    if ($fieldName === 'tva_intra') {
        return '#intra_vat';
    }
    if (preg_match('/^idprof[1-6]$/', $fieldName)) {
        return '#' . $fieldName;
    }

    return null;
}

/**
 * @brief JS translation literal
 * @param string $key
 * @return string
 */
function ksefJsLang($key)
{
    global $langs;

    return json_encode($langs->transnoentitiesnoconv($key, '%s', '%s', '%s', '%s'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
}

/**
 * @brief JS translations object
 * @param array $map JS name => key
 * @return string
 */
function ksefJsLangs($map)
{
    global $langs;

    $out = array();
    foreach ($map as $name => $key) {
        $out[$name] = $langs->transnoentitiesnoconv($key, '%s', '%s', '%s', '%s');
    }
    return json_encode($out, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
}

/**
 * @brief GUS request email
 * @return string
 */
function ksefGusRequestEmail()
{
    global $mysoc;

    $name = (is_object($mysoc) && !empty($mysoc->name)) ? $mysoc->name : '[nazwa firmy]';

    $nip = trim(getDolGlobalString('KSEF_COMPANY_NIP'));
    if ($nip === '') {
        $nip = trim(ksefGetIdentifierField($mysoc, 'NIP'));
    }
    if ($nip === '') {
        $nip = '[NIP]';
    }
    $regon = trim(getDolGlobalString('KSEF_COMPANY_REGON'));
    if ($regon === '') {
        $regon = trim(ksefGetIdentifierField($mysoc, 'REGON'));
    }
    if ($regon === '') {
        $regon = '[REGON]';
    }
    $email = !empty($mysoc->email) ? $mysoc->email : '[adres e-mail]';
    $phone = !empty($mysoc->phone) ? $mysoc->phone : '[+48 numer telefonu]';

    return "Do: regon_bir@stat.gov.pl\n"
        . "Temat: Wniosek o wydanie Klucza Użytkownika do usługi BIR1\n\n"
        . "Dzień dobry,\n\n"
        . "proszę o wydanie Klucza Użytkownika do środowiska produkcyjnego usługi BIR1.\n\n"
        . "Podmiot: " . $name . "\n"
        . "REGON: " . $regon . "\n"
        . "NIP: " . $nip . "\n"
        . "Osoba kontaktowa: [imię i nazwisko]\n"
        . "E-mail: " . $email . "\n"
        . "Telefon: " . $phone . "\n"
        . "Adresy IP: zmienne (połączenia bezpośrednio z własnego systemu)\n"
        . "Przybliżona liczba jednoczesnych użytkowników: [np. 2]\n\n"
        . "Klucz będzie wykorzystywany w naszym systemie Dolibarr z modułem KSeF (https://github.com/InPoint-Automation/Dolibarr-KSeF-Module) do weryfikacji danych rejestrowych kontrahentów na podstawie numeru NIP.\n\n"
        . "W razie potrzeby chętnie przekażę dodatkowe informacje.\n\n"
        . "Z poważaniem,\n"
        . "[imię i nazwisko]";
}

/**
 * @brief Tone CSS class
 * @param string $tone ok, action, check, offline, problem, info, id
 * @return string
 */
function ksefToneClass($tone)
{
    return in_array($tone, array('ok', 'action', 'check', 'offline', 'problem', 'info', 'id'), true) ? 'ksef-tone-' . $tone : '';
}

/**
 * @brief Tone text class
 * @param string $tone
 * @return string
 */
function ksefToneTextClass($tone)
{
    return ksefToneClass($tone) !== '' ? 'ksef-tone-text-' . $tone : '';
}

/**
 * @brief Tone badge classes
 * @param string $tone
 * @return string
 */
function ksefToneBadgeClass($tone)
{
    $core = array('ok' => 'badge-status4', 'action' => 'badge-status1', 'check' => 'badge-ksef-timeout',
        'offline' => 'badge-ksef-offline', 'problem' => 'badge-status8', 'info' => 'badge-status6');
    return isset($core[$tone]) ? $core[$tone] . ' ' . ksefToneClass($tone) : 'badge-status0';
}

/**
 * @brief Send status tone
 * @param string $status
 * @return string
 */
function ksefOutgoingStatusTone($status)
{
    $tones = array('PENDING' => 'action', 'SUBMITTED' => 'action', 'ACCEPTED' => 'ok', 'REJECTED' => 'problem',
        'FAILED' => 'problem', 'TIMEOUT' => 'check', 'INTERRUPTED' => 'check', 'OFFLINE' => 'offline',
        'NOT_FOUND' => 'problem', 'REPLACED' => 'info',
        'IN_SALES_UNLINKED' => 'action', 'AMBIGUOUS' => 'check', 'PROBABLY_IN_KSEF' => 'check');
    return isset($tones[$status]) ? $tones[$status] : '';
}

/**
 * @brief Status badge
 * @param $status
 * @param bool $short
 * @return string HTML
 */
function ksefGetStatusBadge($status, $short = false)
{
    global $langs;
    $langs->load("ksef@ksef");

    $tone = ksefOutgoingStatusTone($status);
    if ($tone === '') {
        return '<span class="badge badge-status">'.htmlspecialchars($status).'</span>';
    }
    $class = 'badge badge-status ' . ksefToneBadgeClass($tone);
    if ($short && in_array($status, array('TIMEOUT', 'INTERRUPTED', 'OFFLINE'), true)) {
        return '<span class="'.$class.' classfortooltip" title="'.dol_escape_htmltag($langs->transnoentitiesnoconv('KSEF_STATUS_'.$status)).'">'.$langs->trans('KSEF_STATUS_'.$status.'_SHORT').'</span>';
    }
    return '<span class="'.$class.'">'.$langs->trans('KSEF_STATUS_'.$status).'</span>';
}

/**
 * @brief Attention count badge
 * @param string $url
 * @param int    $count
 * @param string $tone
 * @param string $label Tooltip
 * @return string HTML
 */
function ksefAttentionBadge($url, $count, $tone, $label)
{
    return '<a href="' . dol_escape_htmltag($url) . '" class="classfortooltip marginrightonly" title="' . dol_escape_htmltag($label) . '">'
        . '<span class="badge ' . ksefToneBadgeClass($tone) . '">' . (int) $count . '</span></a>';
}

/**
 * @brief Environment badge
 * @param $environment
 * @param bool $sending
 * @return string HTML
 */
function ksefGetEnvironmentBadge($environment, $sending = false)
{
    global $langs;
    $badges = array(
        'TEST' => '<span class="badge badge-status1 '.ksefToneClass('check').' badge-status">'.$langs->trans('KSEF_ENV_TEST').'</span>',
        'DEMO' => '<span class="badge badge-status7 '.ksefToneClass('action').' badge-status">'.$langs->trans('KSEF_ENV_DEMO').'</span>',
        'PRODUCTION' => '<span class="badge '.ksefToneBadgeClass($sending ? 'problem' : 'ok').' badge-status">'.$langs->trans('KSEF_ENV_PRODUCTION').'</span>'
    );
    return isset($badges[$environment]) ? $badges[$environment] : '<span class="badge badge-status">'.htmlspecialchars($environment).'</span>';
}

/**
 * @brief Environment name
 * @param string $environment
 * @return string
 */
function ksefEnvironmentLabel($environment)
{
    global $langs;
    $environment = (string) $environment;
    return in_array($environment, array('TEST', 'DEMO', 'PRODUCTION'), true) ? $langs->transnoentitiesnoconv('KSEF_ENV_' . $environment) : $environment;
}

/**
 * @brief Hidden environments note
 * @param DoliDB $db
 * @param string $environment
 * @param array $tables
 * @param string $langKey
 * @return string HTML
 */
function ksefOtherEnvRowsHint($db, $environment, $tables, $langKey = 'KSEF_OtherEnvRowsHint')
{
    global $langs;
    $entities = getEntity('invoice');
    $parts = array();
    foreach ($tables as $table) {
        if ($table === 'submissions') {
            $parts[] = "SELECT s.environment AS e FROM " . MAIN_DB_PREFIX . "ksef_submissions as s INNER JOIN " . MAIN_DB_PREFIX . "facture as f ON f.rowid = s.fk_facture WHERE f.entity IN (" . $entities . ")";
        } elseif ($table === 'incoming' || $table === 'sales') {
            $parts[] = "SELECT environment AS e FROM " . MAIN_DB_PREFIX . "ksef_" . $table . " WHERE entity IN (" . $entities . ")";
        }
    }
    if (empty($parts)) {
        return '';
    }
    $count = 0;
    $res = $db->query("SELECT COUNT(*) AS n FROM (" . implode(" UNION ALL ", $parts) . ") envs WHERE e <> '" . $db->escape((string) $environment) . "'");
    if ($res && ($obj = $db->fetch_object($res))) {
        $count = (int) $obj->n;
    }
    if ($count <= 0) {
        return '';
    }
    return '<div class="opacitymedium small ksef-env-hint">' . $langs->trans($langKey, ksefEnvironmentLabel($environment), $count) . '</div>';
}

/**
 * @brief Verification URL
 * @param $ksef_number
 * @param $invoice_hash
 * @param $environment
 * @param $nip Seller NIP
 * @param $invoice_date Timestamp
 * @param $fa3_xml Sent XML, fills date and NIP
 * @return string
 * @throws Exception
 */
function ksefGetVerificationURL($ksef_number, $invoice_hash = null, $environment = null, $nip = null, $invoice_date = null, $fa3_xml = null)
{
    global $conf, $mysoc;

    // KOD I: XML
    if (!empty($fa3_xml) && (empty($invoice_date) || empty($nip))) {
        $xmlInfo = ksefXmlIssueInfo($fa3_xml);
        if (empty($invoice_date) && $xmlInfo['date'] !== '') {
            $invoice_date = $xmlInfo['date'];
        }
        if (empty($nip) && $xmlInfo['nip'] !== '') {
            $nip = $xmlInfo['nip'];
        }
    }

    if (empty($environment)) {
        $environment = ksefGetEnvironment();
    }

    $base_url = ksefGetQrBaseUrl($environment);

    if (empty($nip)) {
        if (!empty($ksef_number)) {
            $parts = explode('-', $ksef_number);
            $nip = $parts[0] ?? '';
        }
        if (empty($nip)) {
            $nip = ksefCleanNIP(ksefGetIdentifierField($mysoc, 'NIP'));
        }
    }

    if (empty($nip)) {
        throw new Exception("NIP is required for verification URL");
    }

    if (!empty($invoice_date)) {
        if (is_numeric($invoice_date)) {
            $formattedDate = date('d-m-Y', $invoice_date);
        } else {
            $formattedDate = date('d-m-Y', strtotime($invoice_date));
        }
    } elseif (!empty($ksef_number)) {
        $parts = explode('-', $ksef_number);
        if (count($parts) >= 2) {
            $dateStr = $parts[1];
            $date = DateTime::createFromFormat('Ymd', $dateStr);
            $formattedDate = $date ? $date->format('d-m-Y') : date('d-m-Y');
        } else {
            $formattedDate = date('d-m-Y');
        }
    } else {
        $formattedDate = date('d-m-Y');
    }

    if (!empty($invoice_hash)) {
        $decoded = base64_decode($invoice_hash);
        $base64Url = rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=');
    } else {
        dol_syslog("ksefGetVerificationURL: no invoice hash for " . ($ksef_number ?: 'QR I') . ", no verification URL", LOG_WARNING);
        return '';
    }

    return $base_url . '/invoice/' . $nip . '/' . $formattedDate . '/' . $base64Url;
}

/**
 * @brief Submission invoice hash
 * @param object $submission
 * @return string Base64 SHA-256
 */
function ksefSubmissionHash($submission)
{
    if (!empty($submission->invoice_hash)) {
        return (string) $submission->invoice_hash;
    }
    return !empty($submission->fa3_xml) ? ksefCalculateHash((string) $submission->fa3_xml) : '';
}

/**
 * @brief Customer excluded
 * @param $socid
 * @return bool
 */
function ksefIsCustomerExcluded($socid) {
    if ((int) $socid <= 0) return false;
    return in_array((int) $socid, ksefGetExcludedCustomers(), true);
}

/**
 * @brief Visible third party
 * @param DoliDB $db
 * @param int $socid
 * @return Societe|null
 */
function ksefVisibleSociete($db, $socid)
{
    require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
    $soc = new Societe($db);
    if ((int) $socid <= 0 || $soc->fetch((int) $socid) <= 0 || !in_array((int) $soc->entity, array_map('intval', explode(',', getEntity('societe'))), true)) {
        return null;
    }
    return $soc;
}

/**
 * @brief Escape external text
 * @param string $text
 * @param bool $multiline Newlines to br
 * @return string HTML
 */
function ksefEscapeText($text, $multiline = false)
{
    $out = htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return $multiline ? nl2br($out) : $out;
}

/**
 * @brief KSeF number badge
 * @param string $knum
 * @return string HTML
 */
function ksefNumberBadgeText($knum)
{
    if (preg_match('/^([^-]+-[^-]+-)(.+)$/', (string) $knum, $m)) return ksefEscapeText($m[1]) . '<br>' . ksefEscapeText($m[2]);
    return ksefEscapeText($knum);
}

/**
 * @brief Hide unauthorized controls
 * @param User $user
 * @return bool
 */
function ksefHideUnauthorized($user)
{
    return getDolGlobalString('MAIN_BUTTON_HIDE_UNAUTHORIZED') && empty($user->admin);
}

/**
 * @brief Invoice KSeF state
 * @param DoliDB $db
 * @param int $fk_facture
 * @param string $environment '' current first, '*' any
 * @return array
 */
function ksefSubmissionState($db, $fk_facture, $environment = '')
{
    dol_include_once('/ksef/class/ksef_submission.class.php');
    $state = array(
        'has_submission' => false,
        'submission' => null,
        'status' => '',
        'ksef_number' => '',
        'has_real_number' => false,
        'is_accepted' => false,
        'is_offline' => false,
        'is_pending' => false,
        'is_in_flight' => false,
        'locked' => false,
        'db_error' => false,
        'needs_reactivation' => false,
        'number_without_row' => '',
        'is_stale' => false,
        'outcome_unknown' => false,
        'can_check' => false,
        'is_duplicate' => false,
        'duplicate_number' => '',
        'other_env' => '',
    );
    if ((int) $fk_facture <= 0) {
        return $state;
    }
    $submission = new KsefSubmission($db);
    $found = $submission->fetchByInvoice((int) $fk_facture, $environment);
    if ($found < 0) {
        $state['db_error'] = true;
        $state['needs_reactivation'] = ksefNeedsReactivation(ksefGetModuleVersion($db), getDolGlobalString('KSEF_LAST_INIT_VERSION'));
        return $state;
    }
    $state['number_without_row'] = ksefNumberWithoutRow($db, (int) $fk_facture);
    if ($found == 0) {
        return $state;
    }
    $number = (string) $submission->ksef_number;
    $state['has_submission'] = true;
    $state['submission'] = $submission;
    $state['other_env'] = (string) $submission->environment !== ksefGetEnvironment() ? (string) $submission->environment : '';
    $state['status'] = (string) $submission->status;
    $state['ksef_number'] = $number;
    $state['has_real_number'] = ksefIsRealKsefNumber($number);
    $state['is_accepted'] = $state['status'] == KsefSubmission::STATUS_ACCEPTED && $state['has_real_number'];
    $state['is_offline'] = $state['status'] == KsefSubmission::STATUS_OFFLINE && !empty($submission->fa3_xml);
    $state['is_pending'] = $state['status'] == KsefSubmission::STATUS_PENDING;
    $state['is_in_flight'] = in_array($state['status'], array(KsefSubmission::STATUS_PENDING, KsefSubmission::STATUS_SUBMITTED, KsefSubmission::STATUS_TIMEOUT), true);
    $state['locked'] = $state['is_accepted'] || $state['is_offline'] || (bool) ksefIssuedRows($db, (int) $fk_facture);
    $state['is_stale'] = $submission->isStale();
    $state['outcome_unknown'] = $state['status'] == KsefSubmission::STATUS_TIMEOUT || $state['is_stale'];
    $state['can_check'] = in_array($state['status'], array(KsefSubmission::STATUS_PENDING, KsefSubmission::STATUS_SUBMITTED, KsefSubmission::STATUS_TIMEOUT), true)
        && (($submission->getSessionReference() !== '' && (!empty($submission->ksef_reference) || !empty($submission->invoice_hash)))
            || ($submission->getSessionReference() === '' && !empty($submission->invoice_hash)));
    $state['is_duplicate'] = $submission->isDuplicate();
    $state['duplicate_number'] = $state['is_duplicate'] ? $submission->getDuplicateOriginal()['ksef_number'] : '';
    return $state;
}

/**
 * @brief In-flight send lock
 * @param KsefSubmission $submission
 * @return array|null Null when idle
 */
function ksefInFlightLock($submission)
{
    dol_include_once('/ksef/class/ksef_submission.class.php');
    if (!is_object($submission) || !in_array($submission->status, array(KsefSubmission::STATUS_PENDING, KsefSubmission::STATUS_SUBMITTED, KsefSubmission::STATUS_TIMEOUT), true)) {
        return null;
    }
    $unknown = $submission->status == KsefSubmission::STATUS_TIMEOUT || $submission->isStale();
    if (!$unknown) {
        return array('result' => 'blocked', 'key' => 'KSEF_LockInFlight', 'reason' => 'in_flight');
    }
    $refs = $submission->getSessionReference() !== '' && (!empty($submission->ksef_reference) || !empty($submission->invoice_hash));
    if ($refs) {
        return array('result' => 'blocked', 'key' => 'KSEF_LockCheckFirst', 'reason' => 'check_first');
    }
    return array('result' => 'warn', 'key' => 'KSEF_LockMayBeInKsef', 'reason' => 'may_be_in_ksef');
}

/**
 * @brief Row is issued
 * @param KsefSubmission|object $row
 * @return bool
 */
function ksefRowIsIssued($row)
{
    dol_include_once('/ksef/class/ksef_submission.class.php');
    if ($row->status == KsefSubmission::STATUS_ACCEPTED && ksefIsRealKsefNumber($row->ksef_number)) {
        return true;
    }
    return !empty($row->offline_mode) || ($row->status == KsefSubmission::STATUS_OFFLINE && !empty($row->fa3_xml));
}

/**
 * @brief Issued rows
 * @param DoliDB $db
 * @param int $fk_facture
 * @return array
 */
function ksefIssuedRows($db, $fk_facture)
{
    $out = array();
    // pgsql 'f' truthy
    $res = $db->query("SELECT rowid, status, ksef_number, offline_mode, environment,"
        . " CASE WHEN fa3_xml IS NOT NULL AND fa3_xml <> '' THEN 1 ELSE 0 END AS has_xml,"
        . " CASE WHEN upo_xml IS NOT NULL AND upo_xml <> '' THEN 1 ELSE 0 END AS has_upo, '' AS fa3_xml"
        . " FROM " . MAIN_DB_PREFIX . "ksef_submissions WHERE fk_facture = " . (int) $fk_facture . " ORDER BY rowid");
    while ($res && ($o = $db->fetch_object($res))) {
        $o->has_xml = (int) $o->has_xml;
        $o->has_upo = (int) $o->has_upo;
        $o->fa3_xml = $o->has_xml ? 'x' : '';
        if (ksefRowIsIssued($o)) {
            $out[] = array('id' => (int) $o->rowid, 'status' => $o->status, 'ksef_number' => (string) $o->ksef_number, 'environment' => (string) $o->environment,
                'has_xml' => (bool) $o->has_xml, 'has_upo' => (bool) $o->has_upo);
        }
    }
    return $out;
}

/**
 * @brief Production issue block
 * @param DoliDB $db
 * @param int $fk_facture
 * @param string|null $environment Null for current
 * @return array|null Null when allowed
 */
function ksefProductionIssuedBlock($db, $fk_facture, $environment = null)
{
    $env = $environment === null ? ksefGetEnvironment() : (string) $environment;
    if ($env === 'PRODUCTION' || (int) $fk_facture <= 0) {
        return null;
    }
    $offline = null;
    foreach (ksefIssuedRows($db, (int) $fk_facture) as $r) {
        if ($r['environment'] !== 'PRODUCTION') {
            continue;
        }
        if (ksefIsRealKsefNumber($r['ksef_number'])) {
            return $r;
        }
        if ($offline === null) {
            $offline = $r;
        }
    }
    return $offline;
}

/**
 * @brief Production block text
 * @param array $row
 * @param string|null $environment Null for current
 * @return string Unescaped
 */
function ksefProductionIssuedRefusal($row, $environment = null)
{
    global $langs;
    $langs->load("ksef@ksef");
    $env = $environment === null ? ksefGetEnvironment() : (string) $environment;
    $num = (string) ($row['ksef_number'] ?? '');
    return $langs->transnoentitiesnoconv(ksefIsRealKsefNumber($num) ? 'KSEF_IssuedInProductionNoSend' : 'KSEF_IssuedInProductionNoSendOffline',
        ksefEnvironmentLabel($env), $num);
}

/**
 * @brief Invoice edit lock
 * @param DoliDB $db
 * @param int $fk_facture
 * @param string $what modify, delete
 * @return array
 */
function ksefInvoiceEditLock($db, $fk_facture, $what = 'modify')
{
    $state = ksefSubmissionState($db, $fk_facture, '*');
    $lock = array('result' => 'allowed', 'key' => '', 'args' => array(), 'reason' => '', 'state' => $state, 'issued' => array(), 'row_id' => 0);
    if ($state['db_error']) {
        return array('result' => 'blocked', 'key' => $state['needs_reactivation'] ? 'KSEF_MODULE_NEEDS_REACTIVATION' : 'KSEF_DbErrorGeneric', 'reason' => 'db_error') + $lock;
    }
    if (!$state['has_submission']) {
        return $lock;
    }
    $sub = $state['submission'];
    $flight = ksefInFlightLock($sub);
    if ($flight && $flight['result'] == 'blocked') {
        return $flight + $lock;
    }
    if ($what == 'delete') {
        $lock['issued'] = ksefIssuedRows($db, $fk_facture);
        if ($lock['issued']) {
            $lock['result'] = 'warn';
            $lock['key'] = 'KSEF_DeleteInvoiceIssuedWarn';
            $lock['reason'] = 'issued';
        } elseif ($flight) {
            $lock = $flight + $lock;
        }
        return $lock;
    }
    $row = ksefLockingIssuedRow($db, $fk_facture);
    if (!$row) {
        if ($flight) {
            return $flight + $lock;
        }
        if (in_array($sub->status, array(KsefSubmission::STATUS_FAILED, KsefSubmission::STATUS_REJECTED), true)) {
            return array('result' => 'info', 'key' => 'KSEF_ModifyInfoFailed', 'reason' => 'failed') + $lock;
        }
        return $lock;
    }
    $lock['row_id'] = $row['id'];
    $testRecord = in_array($row['environment'], array('TEST', 'DEMO'), true);
    if ($testRecord) {
        global $langs;
        $langs->loadLangs(array("ksef@ksef", "banks"));
    }
    if ($row['accepted']) {
        if ($testRecord) {
            return array('result' => 'warn', 'key' => 'KSEF_ModifyWarningKSeF', 'args' => array($row['ksef_number'], ksefEnvironmentLabel($row['environment'])), 'reason' => 'issued_test') + $lock;
        }
        return array('result' => 'blocked', 'key' => 'KSEF_ModifyRefusedKSeF', 'args' => array($row['ksef_number']), 'reason' => 'issued') + $lock;
    }
    if ($testRecord) {
        return array('result' => 'warn', 'key' => 'KSEF_ModifyWarningOffline', 'args' => array(ksefEnvironmentLabel($row['environment'])), 'reason' => 'offline_test') + $lock;
    }
    return array('result' => 'blocked', 'key' => 'KSEF_ModifyRefusedOffline', 'reason' => 'offline') + $lock;
}

/**
 * @brief Back-to-draft lock
 * @param DoliDB $db
 * @param int $fk_facture
 * @return array
 */
function ksefInvoiceModifyLock($db, $fk_facture)
{
    $lock = ksefInvoiceEditLock($db, $fk_facture, 'modify');
    if ($lock['result'] == 'blocked' || empty($lock['row_id'])) {
        return $lock;
    }
    $korekta = ksefKorektaOf($db, $fk_facture);
    if ($korekta === false) {
        return array('result' => 'blocked', 'key' => 'KSEF_DbErrorGeneric', 'args' => array(), 'reason' => 'db_error') + $lock;
    }
    if ($korekta) {
        global $langs;
        $langs->load('ksef@ksef');
        return array('result' => 'blocked', 'key' => 'KSEF_ModifyRefusedCorrected', 'args' => array($korekta->ref), 'reason' => 'corrected') + $lock;
    }
    return $lock;
}

/**
 * @brief Locking issued row
 * @param DoliDB $db
 * @param int $fk_facture
 * @return array|null
 */
function ksefLockingIssuedRow($db, $fk_facture)
{
    $best = null;
    $bestRank = -1;
    foreach (ksefIssuedRows($db, $fk_facture) as $r) {
        $accepted = $r['status'] == KsefSubmission::STATUS_ACCEPTED && ksefIsRealKsefNumber($r['ksef_number']);
        $rank = (in_array($r['environment'], array('TEST', 'DEMO'), true) ? 0 : 2) + ($accepted ? 1 : 0);
        if ($rank >= $bestRank) {
            $best = array('id' => $r['id'], 'ksef_number' => $r['ksef_number'], 'environment' => $r['environment'], 'accepted' => $accepted);
            $bestRank = $rank;
        }
    }
    return $best;
}

/**
 * @brief Discount change lock
 * @param DoliDB $db
 * @param int $fk_facture
 * @return array
 */
function ksefDiscountChangeLock($db, $fk_facture)
{
    $lock = ksefInvoiceEditLock($db, $fk_facture, 'modify');
    $out = array('result' => 'allowed', 'key' => '', 'args' => array(), 'reason' => $lock['reason']);
    if ($lock['result'] == 'blocked') {
        $keys = array('issued' => 'KSEF_DiscountRefusedKSeF', 'offline' => 'KSEF_DiscountRefusedOffline');
        return array('result' => 'blocked', 'key' => $keys[$lock['reason']] ?? $lock['key'], 'args' => $lock['args']) + $out;
    }
    if ($lock['result'] == 'warn') {
        $keys = array('issued_test' => 'KSEF_DiscountWarningKSeF', 'offline_test' => 'KSEF_DiscountWarningOffline');
        return array('result' => 'warn', 'key' => $keys[$lock['reason']] ?? $lock['key'], 'args' => $lock['args']) + $out;
    }
    return $out;
}

/**
 * @brief XML field lock
 * @param DoliDB $db
 * @param int $fk_facture
 * @param string $label
 * @return array
 */
function ksefFieldChangeLock($db, $fk_facture, $label)
{
    $lock = ksefInvoiceEditLock($db, $fk_facture, 'modify');
    $out = array('result' => 'allowed', 'key' => '', 'args' => array(), 'reason' => $lock['reason'], 'row_id' => $lock['row_id']);
    $keys = array('issued' => 'KSEF_FieldRefusedKSeF', 'offline' => 'KSEF_FieldRefusedOffline', 'issued_test' => 'KSEF_FieldWarningKSeF', 'offline_test' => 'KSEF_FieldWarningOffline');
    if (!in_array($lock['result'], array('blocked', 'warn'), true)) {
        return $out;
    }
    if (isset($keys[$lock['reason']])) {
        return array('result' => $lock['result'], 'key' => $keys[$lock['reason']], 'args' => array_merge($lock['args'], array($label))) + $out;
    }
    return array('result' => $lock['result'], 'key' => $lock['key'], 'args' => $lock['args']) + $out;
}

/**
 * @brief Public note sent
 * @param Facture $invoice
 * @return bool
 */
function ksefNotePublicInXml($invoice)
{
    dol_include_once('/ksef/class/fa3_builder.class.php');
    $override = trim((string) ($invoice->array_options['options_ksef_dodatkowy_opis_mode'] ?? ''));
    if ($override === 'disabled') {
        return false;
    }
    $parsed = $override !== '' ? FA3Builder::parseCombinedNoteMode($override) : null;
    $mode = $parsed ? $parsed['mode'] : getDolGlobalString('KSEF_DODATKOWY_OPIS_NOTE_MODE', 'simple');
    return $mode !== 'disabled';
}

/**
 * @brief Extrafields sent
 * @param Facture $invoice
 * @return array
 */
function ksefInvoiceXmlExtrafields($invoice)
{
    $out = array('ksef_dodatkowy_opis_mode', 'ksef_podmiot3', 'ksef_podmiot3_role', 'ksef_period_from', 'ksef_period_to', 'ksef_order_date');
    if (in_array((int) $invoice->type, array(Facture::TYPE_CREDIT_NOTE, Facture::TYPE_REPLACEMENT), true)) {
        $out[] = 'ksef_correction_reason';
        $out[] = 'ksef_correction_type';
    }
    foreach (array_filter(array_map('trim', explode(',', getDolGlobalString('KSEF_DODATKOWY_OPIS_EXTRAFIELDS')))) as $entry) {
        $out[] = explode(':', $entry, 2)[0];
    }
    foreach (array('KSEF_NR_ZAMOWIENIA_SOURCE', 'KSEF_NR_UMOWY_SOURCE', 'KSEF_FA3_GROSS_SOURCE', 'KSEF_FA3_FP_SOURCE', 'KSEF_FA3_MPP_SOURCE', 'KSEF_TP_SOURCE',
        'KSEF_P16_SOURCE', 'KSEF_P17_SOURCE', 'KSEF_P17_TP_SOURCE', 'KSEF_P18_SOURCE', 'KSEF_P18_TP_SOURCE') as $const) {
        $source = getDolGlobalString($const);
        if (strpos($source, 'extrafield:') === 0 && substr($source, 11) !== '') {
            $out[] = substr($source, 11);
        }
    }
    return array_values(array_unique($out));
}

/**
 * @brief Action XML field
 * @param DoliDB $db
 * @param Facture $invoice
 * @param string $action
 * @return string Label, '' if none
 */
function ksefInvoiceXmlField($db, $invoice, $action)
{
    global $langs;
    $langs->load('ksef@ksef');
    $map = array('setinvoicedate' => 'Date', 'setref' => 'Ref', 'setref_client' => 'RefClient', 'setconditions' => 'Conditions', 'setpaymentterm' => 'DueDate',
        'setmode' => 'PaymentMode', 'setbankaccount' => 'BankAccount', 'setmulticurrencycode' => 'Currency', 'setmulticurrencyrate' => 'Currency',
        'setrevenuestamp' => 'Amounts', 'setremisepercent' => 'Amounts', 'setnote_public' => 'NotePublic');
    if ($action === 'update_extras') {
        $attribute = GETPOST('attribute', 'aZ09');
        foreach (ksefInvoiceXmlExtrafields($invoice) as $name) {
            if ($attribute === $name || ($attribute === '' && (GETPOSTISSET('options_' . $name) || GETPOSTISSET('options_' . $name . 'day')))) {
                return ksefInvoiceExtrafieldLabel($db, $name);
            }
        }
        return '';
    }
    $code = $map[$action] ?? '';
    $credit = (int) $invoice->type === Facture::TYPE_CREDIT_NOTE;
    if ($code === 'RefClient' && getDolGlobalString('KSEF_NR_ZAMOWIENIA_SOURCE', 'ref_client') !== 'ref_client') {
        $code = '';
    } elseif (in_array($code, array('Conditions', 'DueDate'), true) && $credit) {
        $code = '';
    } elseif ($code === 'BankAccount') {
        $mode = '';
        $res = $db->query("SELECT code FROM " . MAIN_DB_PREFIX . "c_paiement WHERE id = " . (int) $invoice->mode_reglement_id);
        if ($res && ($o = $db->fetch_object($res))) {
            $mode = (string) $o->code;
        }
        // transfers only
        $form = ksefPaymentForm($db, $mode);
        if ($form['form'] !== '6' && ($form['form'] !== '' || $form['other'] !== '')) {
            $code = '';
        }
    } elseif ($code === 'NotePublic' && !ksefNotePublicInXml($invoice)) {
        $code = '';
    }
    return $code === '' ? '' : $langs->transnoentitiesnoconv('KSEF_XmlField_' . $code);
}

/**
 * @brief Locked field value
 * @param Facture $invoice
 * @param string $key
 * @return string
 */
function ksefLockedFieldValue($invoice, $key)
{
    $day = function ($v) {
        return empty($v) ? '' : dol_print_date($v, '%Y-%m-%d');
    };
    switch ($key) {
        case 'date':
            return $day($invoice->date);
        case 'date_lim_reglement':
            return $day($invoice->date_lim_reglement);
        case 'ref':
            return trim((string) $invoice->ref);
        case 'ref_client':
            return trim((string) $invoice->ref_client);
        case 'note_public':
            return trim((string) $invoice->note_public);
        case 'multicurrency_code':
            return ksefGetInvoiceCurrency($invoice);
        case 'multicurrency_tx':
            return number_format((float) $invoice->multicurrency_tx, 8, '.', '');
        case 'total_ttc':
        case 'revenuestamp':
            return number_format((float) $invoice->$key, 2, '.', '');
        case 'cond_reglement_id':
        case 'mode_reglement_id':
        case 'fk_account':
            return (string) (int) $invoice->$key;
    }
    if (strpos($key, 'options_') !== 0) {
        return '';
    }
    $v = $invoice->array_options[$key] ?? '';
    if (is_array($v)) {
        return implode(',', $v);
    }
    $v = trim((string) $v);
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $v, $m)) {
        return $m[0];
    }
    if (ctype_digit($v) && (int) $v >= 100000000) {
        return dol_print_date((int) $v, '%Y-%m-%d');
    }
    return $v;
}

/**
 * @brief Locked field keys
 * @param DoliDB $db
 * @param Facture $invoice
 * @return array
 */
function ksefLockedFieldKeys($db, $invoice)
{
    $keys = array('date', 'ref', 'multicurrency_code', 'multicurrency_tx', 'total_ttc', 'revenuestamp', 'mode_reglement_id');
    $byAction = array('cond_reglement_id' => 'setconditions', 'date_lim_reglement' => 'setpaymentterm', 'fk_account' => 'setbankaccount',
        'ref_client' => 'setref_client', 'note_public' => 'setnote_public');
    foreach ($byAction as $key => $action) {
        if (ksefInvoiceXmlField($db, $invoice, $action) !== '') {
            $keys[] = $key;
        }
    }
    foreach (array_merge(ksefInvoiceXmlExtrafields($invoice), array('ksef_sale_date', 'ksef_kurs_data')) as $name) {
        $keys[] = 'options_' . $name;
    }
    return array_values(array_unique($keys));
}

/**
 * @brief Locked fields snapshot
 * @param DoliDB $db
 * @param int $fk_facture
 * @return string JSON
 */
function ksefLockedFieldsSnapshot($db, $fk_facture)
{
    $invoice = new Facture($db);
    if ((int) $fk_facture <= 0 || $invoice->fetch((int) $fk_facture) <= 0) {
        return '';
    }
    $invoice->fetch_optionals();
    $out = array();
    foreach (ksefLockedFieldKeys($db, $invoice) as $key) {
        $out[$key] = ksefLockedFieldValue($invoice, $key);
    }
    return json_encode($out);
}

/**
 * @brief Locked field label
 * @param DoliDB $db
 * @param string $key
 * @return string
 */
function ksefLockedFieldLabel($db, $key)
{
    global $langs;
    $codes = array('date' => 'Date', 'ref' => 'Ref', 'multicurrency_code' => 'Currency', 'multicurrency_tx' => 'Currency', 'total_ttc' => 'Amounts', 'revenuestamp' => 'Amounts',
        'mode_reglement_id' => 'PaymentMode', 'cond_reglement_id' => 'Conditions', 'date_lim_reglement' => 'DueDate', 'fk_account' => 'BankAccount',
        'ref_client' => 'RefClient', 'note_public' => 'NotePublic');
    if (isset($codes[$key])) {
        $langs->load('ksef@ksef');
        return $langs->transnoentitiesnoconv('KSEF_XmlField_' . $codes[$key]);
    }
    return ksefInvoiceExtrafieldLabel($db, substr($key, 8));
}

/**
 * @brief Changed locked field
 * @param DoliDB $db
 * @param Facture $invoice
 * @param int $rowId
 * @return string Label, '' if none
 */
function ksefLockedFieldsDrift($db, $invoice, $rowId)
{
    global $langs;
    $res = $db->query("SELECT locked_fields, fa3_xml FROM " . MAIN_DB_PREFIX . "ksef_submissions WHERE rowid = " . (int) $rowId);
    $o = $res ? $db->fetch_object($res) : null;
    if (!$o) {
        return '';
    }
    if (empty($invoice->array_options)) {
        $invoice->fetch_optionals();
    }
    $snap = !empty($o->locked_fields) ? json_decode((string) $o->locked_fields, true) : null;
    if (is_array($snap)) {
        foreach ($snap as $key => $was) {
            if (ksefLockedFieldValue($invoice, $key) !== (string) $was) {
                return ksefLockedFieldLabel($db, $key);
            }
        }
        return '';
    }
    $dom = new DOMDocument();
    if (empty($o->fa3_xml) || !@$dom->loadXML((string) $o->fa3_xml, LIBXML_NONET)) {
        return '';
    }
    $sent = function ($name) use ($dom) {
        $n = $dom->getElementsByTagNameNS('*', $name);
        return $n->length ? trim($n->item(0)->textContent) : null;
    };
    $now = array(
        'date' => 'P_1',
        'ref' => 'P_2',
        'multicurrency_code' => 'KodWaluty',
        'date_lim_reglement' => 'Termin',
    );
    foreach ($now as $key => $element) {
        $was = $sent($element);
        if ($was !== null && $was !== ksefLockedFieldValue($invoice, $key)) {
            return ksefLockedFieldLabel($db, $key);
        }
    }
    return '';
}

/**
 * @brief Issued XML row
 * @param DoliDB $db
 * @param int $fk_facture
 * @return int 0 if none
 */
function ksefSentXmlRowId($db, $fk_facture)
{
    $fallback = 0;
    $res = $db->query("SELECT rowid, status, ksef_number, offline_mode,"
        . " CASE WHEN fa3_xml IS NOT NULL AND fa3_xml <> '' THEN 1 ELSE 0 END AS has_xml"
        . " FROM " . MAIN_DB_PREFIX . "ksef_submissions WHERE fk_facture = " . (int) $fk_facture . ksefEnvironmentSql($db) . " ORDER BY date_submission DESC, rowid DESC");
    while ($res && ($o = $db->fetch_object($res))) {
        if (!(int) $o->has_xml) {
            continue;
        }
        $o->fa3_xml = 'x';
        if (ksefRowIsIssued($o)) {
            return (int) $o->rowid;
        }
        if (!$fallback) {
            $fallback = (int) $o->rowid;
        }
    }
    return $fallback;
}

/**
 * @brief Extrafield label
 * @param DoliDB $db
 * @param string $name
 * @return string
 */
function ksefInvoiceExtrafieldLabel($db, $name)
{
    global $langs;
    static $labels = null;
    if ($labels === null) {
        require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
        $ef = new ExtraFields($db);
        $ef->fetch_name_optionals_label('facture');
        $labels = $ef->attributes['facture']['label'] ?? array();
    }
    return $langs->transnoentitiesnoconv($labels[$name] ?? $name);
}

/**
 * @brief Row delete lock
 * @param KsefSubmission $submission
 * @param User $user
 * @return array
 */
function ksefSubmissionRowLock($submission, $user)
{
    $flight = ksefInFlightLock($submission);
    if ($flight) {
        return $flight;
    }
    if (ksefRowIsIssued($submission) && (string) $submission->environment === 'PRODUCTION') {
        if (empty($user->admin)) {
            return array('result' => 'blocked', 'key' => 'KSEF_DeleteIssuedRowAdminOnly', 'reason' => 'issued_admin_only');
        }
        return array('result' => 'warn', 'key' => 'KSEF_DeleteIssuedRowWarn', 'reason' => 'issued');
    }
    return array('result' => 'allowed', 'key' => '', 'reason' => '');
}

/**
 * @brief Issued download links
 * @param int $fk_facture
 * @param array $rows
 * @return string HTML
 */
function ksefIssuedDownloadLinks($fk_facture, $rows)
{
    global $langs;
    $out = array();
    foreach ($rows as $r) {
        $base = dol_buildpath('/ksef/tab_ksef.php', 1) . '?id=' . (int) $fk_facture . '&sub_id=' . (int) $r['id'] . '&token=' . newToken();
        $links = array();
        if ($r['has_xml']) {
            $links[] = '<a class="butAction ksef-act-xml smallpaddingimp" href="' . $base . '&action=download_xml">XML</a>';
        }
        if ($r['has_upo']) {
            $links[] = '<a class="butAction ksef-act-upo smallpaddingimp" href="' . $base . '&action=download_upo">UPO</a>';
        }
        $label = ksefIsRealKsefNumber($r['ksef_number']) ? $r['ksef_number'] : $langs->trans('KSEF_OfflineIssued');
        $out[] = '<div class="ksef-delete-records">' . dol_escape_htmltag($label) . ' (' . dol_escape_htmltag($r['environment']) . ')'
            . '<div class="ksef-delete-record-links">' . ($links ? implode(' ', $links) : '<span class="opacitymedium">' . $langs->trans('KSEF_NoFilesStored') . '</span>') . '</div></div>';
    }
    return implode('', $out);
}

/**
 * @brief Real KSeF number
 * @param string $number
 * @return bool
 */
function ksefIsRealKsefNumber($number)
{
    $number = (string) $number;
    return $number !== '' && strpos($number, 'OFFLINE') === false && strpos($number, 'PENDING') === false && strpos($number, 'ERROR') === false;
}

/**
 * @brief KSeF number format
 * @param string $number
 * @return bool
 */
function ksefIsKsefNumber($number)
{
    return (bool) preg_match('/^([1-9](\\d[1-9]|[1-9]\\d)\\d{7})-(20[2-9][0-9]|2[1-9]\\d{2}|[3-9]\\d{3})(0[1-9]|1[0-2])(0[1-9]|[12]\\d|3[01])-([0-9A-F]{6})-?([0-9A-F]{6})-([0-9A-F]{2})$/', (string) $number);
}

/**
 * @brief Orphan invoice number
 * @param DoliDB $db
 * @param int $fk_facture
 * @return string
 */
function ksefNumberWithoutRow($db, $fk_facture)
{
    $res = $db->query("SELECT e.ksef_number, f.fk_facture_source FROM " . MAIN_DB_PREFIX . "facture as f"
        . " LEFT JOIN " . MAIN_DB_PREFIX . "facture_extrafields as e ON e.fk_object = f.rowid"
        . " WHERE f.rowid = " . (int) $fk_facture);
    $obj = $res ? $db->fetch_object($res) : null;
    $number = $obj && !empty($obj->ksef_number) ? trim((string) $obj->ksef_number) : '';
    if ($number === '' && $obj) {
        $res = $db->query("SELECT replaced_number, status FROM " . MAIN_DB_PREFIX . "ksef_submissions WHERE fk_facture = " . (int) $fk_facture . " ORDER BY rowid DESC");
        while ($res && ($row = $db->fetch_object($res))) {
            if (in_array((string) $row->status, array('ACCEPTED', 'OFFLINE'), true)) {
                $number = '';
                break;
            }
            if ($number === '' && !empty($row->replaced_number)) {
                $number = trim((string) $row->replaced_number);
            }
        }
    }
    if ($number === '' || !ksefIsKsefNumber($number)) {
        return '';
    }
    $res = $db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "ksef_submissions WHERE ksef_number = '" . $db->escape($number) . "'"
        . " AND (fk_facture <> " . (int) $fk_facture . " OR status IN ('ACCEPTED', 'OFFLINE')) LIMIT 1");
    if ($res && $db->num_rows($res)) {
        return '';
    }
    if (!empty($obj->fk_facture_source)) {
        $res = $db->query("SELECT ksef_number FROM " . MAIN_DB_PREFIX . "facture_extrafields WHERE fk_object = " . (int) $obj->fk_facture_source);
        $src = $res ? $db->fetch_object($res) : null;
        if ($src && trim((string) $src->ksef_number) === $number) {
            return '';
        }
    }
    return $number;
}

/**
 * @brief User DB error
 * @param User $user
 * @param string $key Lang key
 * @param string $detail
 * @return string
 */
function ksefDbErrorForUser($user, $key, $detail = '')
{
    global $langs;
    $langs->load("ksef@ksef");
    if ($detail !== '') {
        dol_syslog('KSeF database error (' . $key . '): ' . $detail, LOG_ERR);
    }
    $msg = $langs->transnoentitiesnoconv($key);
    if (!empty($user->admin) && $detail !== '') {
        $msg .= ' (' . $detail . ')';
    }
    return $msg;
}

/**
 * @brief Database is MySQL
 * @param DoliDB $db
 * @return bool
 */
function ksefDbIsMysql($db)
{
    return in_array($db->type, array('mysqli', 'mysql'), true);
}

/**
 * @brief Table has column
 * @param DoliDB $db
 * @param string $table With prefix
 * @param string $col
 * @return bool
 */
function ksefDbColumnExists($db, $table, $col)
{
    $r = $db->DDLDescTable($table, $col);
    return $r && $db->num_rows($r) > 0;
}

/**
 * @brief Truncation warnings
 * @param DoliDB $db
 * @return array
 */
function ksefDbTruncationWarnings($db)
{
    $found = array();
    if (!ksefDbIsMysql($db)) {
        return $found;
    }
    $res = $db->query('SHOW WARNINGS');
    while ($res && ($obj = $db->fetch_object($res))) {
        // 1366/1265/1264 codes
        if (in_array((int) $obj->Code, array(1264, 1265, 1366), true)) {
            $found[] = (int) $obj->Code . ': ' . $obj->Message;
        }
    }
    return $found;
}

/**
 * @brief Set write savepoint
 * @param DoliDB $db
 * @param string $name
 * @return string '' if unset
 */
function ksefDbWriteSavepoint($db, $name)
{
    if (!ksefDbIsMysql($db) || (int) $db->transaction_opened <= 0) {
        return '';
    }
    return $db->query('SAVEPOINT ' . $name) ? $name : '';
}

/**
 * @brief Undo truncated write
 * @param DoliDB $db
 * @param string $savepoint
 * @param string $where Log context
 * @return string Error, '' if none
 */
function ksefDbRejectTruncated($db, $savepoint, $where)
{
    global $langs;
    $cut = ksefDbTruncationWarnings($db);
    if (!$cut) {
        return '';
    }
    if ($savepoint !== '') {
        $db->query('ROLLBACK TO SAVEPOINT ' . $savepoint);
    }
    dol_syslog($where . ' database cut data (' . implode('; ', $cut) . '), not stored', LOG_ERR);
    $langs->load('ksef@ksef');
    return $langs->transnoentitiesnoconv('KSEF_ErrDbDataTruncated', implode('; ', $cut));
}

/**
 * @brief Send result notices
 * @param array|false $result
 * @return bool True if handled
 */
function ksefWarnIfNotRecorded($result)
{
    global $langs;
    if (is_array($result) && isset($result['record_saved']) && $result['record_saved'] === false) {
        setEventMessages(ksefEscapeText(!empty($result['warning']) ? $result['warning'] : 'KSeF number not recorded'), null, 'errors');
        return true;
    }
    if (!is_array($result)) {
        return false;
    }
    $langs->load("ksef@ksef");
    if (($result['status'] ?? '') === 'ACCEPTED' && !empty($result['adopted'])) {
        setEventMessages($langs->trans('KSEF_AdoptedDuplicate', ksefEscapeText($result['ksef_number'] ?? '')), null, 'mesgs');
    }
    if (($result['status'] ?? '') === 'TIMEOUT') {
        setEventMessages(ksefEscapeText(!empty($result['error']) ? $result['error'] : $langs->transnoentitiesnoconv('KSEF_SendOutcomeUnknown')), null, 'warnings');
        return true;
    }
    return false;
}

/**
 * @brief Missing right tooltip
 * @param string $what
 * @return string
 */
function ksefMissingRightText($what)
{
    global $langs;
    $langs->loadLangs(array('ksef@ksef'));
    $names = array(
        'send' => 'KSEF_RightNameSend',
        'validate_and_send' => 'KSEF_RightNameValidateSend',
        'create_supplier' => 'KSEF_RightNameCreateThirdparty',
        'create_customer' => 'KSEF_RightNameCreateThirdparty',
        'create_product' => 'KSEF_RightNameCreateProduct',
        'import_incoming' => 'KSEF_RightNameImportIncoming',
        'delete_incoming' => 'KSEF_RightNameImportIncoming',
        'import_sales' => 'KSEF_RightNameImportSales',
        'delete_sales' => 'KSEF_RightNameImportSales',
        'adjust_invoice' => 'KSEF_RightNameImportSales',
        'link_ksef_number' => 'KSEF_RightNameImportSales',
        'search_by_hash' => 'KSEF_RightNameKsefWrite',
    );
    if (!isset($names[$what])) {
        return $langs->transnoentitiesnoconv('NotEnoughPermissions');
    }
    // needs MAIN_USE_ADVANCED_PERMS
    if ($what === 'validate_and_send' && !getDolGlobalString('MAIN_USE_ADVANCED_PERMS')) {
        $what = 'send';
    }
    return $langs->transnoentitiesnoconv('KSEF_RightRequired', $langs->transnoentitiesnoconv($names[$what]));
}

/**
 * @brief Card send button
 * @param string $html Escaped
 * @param string $url
 * @param string $processing Overlay text
 * @param string $what
 * @param string $style
 * @param string $title Escaped
 * @param string $id
 * @param string $refusal
 * @param string $tone
 * @return string HTML
 */
function ksefSendButton($html, $url, $processing, $what, $style = '', $title = '', $id = '', $refusal = '', $tone = '')
{
    global $user;
    if (!ksefUserCan($user, $what)) {
        return dolGetButtonAction('', $html, 'default', '', $id, false, array('attr' => array('title' => ksefMissingRightText($what))));
    }
    if ($refusal !== '') {
        return dolGetButtonAction('', $html, 'default', '', $id, false, array('attr' => array('title' => $refusal)));
    }
    return '<a class="butAction' . ($tone !== '' ? ' ' . ksefToneClass($tone) : '') . '"' . ($id !== '' ? ' id="' . dol_escape_htmltag($id) . '"' : '') . ($style !== '' ? ' ' . $style : '')
        . ' href="' . $url . '" data-processing-text="' . dol_escape_htmltag($processing) . '..." onclick="return (typeof ksefShowSpinner === \'function\') ? ksefShowSpinner(event, this) : true;"'
        . ($title !== '' ? ' title="' . $title . '"' : '') . '>' . $html . '</a>';
}

/**
 * @brief Send right migrated
 * @return bool
 */
function ksefSendRightMigrated()
{
    $done = getDolGlobalString('KSEF_MIGRATION_VERSION', '');
    return $done !== '' && version_compare($done, KSEF_SEND_RIGHT_MIGRATION, '>=');
}

/**
 * @brief User may act
 * @param User $user
 * @param string $what
 * @param array|null $state
 * @return bool
 */
function ksefUserCan($user, $what, $state = null)
{
    if (empty($user) || empty($user->id)) {
        return false;
    }
    switch ($what) {
        case 'edit_ksef_data':
            return $user->hasRight('facture', 'creer') && !(is_array($state) && !empty($state['locked']));
        case 'generate_pdf':
            return (bool) $user->hasRight('facture', 'creer');
        case 'refresh_submission':
            return (bool) $user->hasRight('ksef', 'read');
        case 'search_by_hash':
            // limits company-wide
            return $user->hasRight('ksef', 'read') && $user->hasRight('ksef', 'write');
        case 'send':
            if (!ksefSendRightMigrated()) {
                return (bool) $user->hasRight('facture', 'creer');
            }
            return $user->hasRight('ksef', 'write') && $user->hasRight('facture', 'creer');
        case 'validate_and_send':
            if (!ksefUserCan($user, 'send')) {
                return false;
            }
            return !getDolGlobalString('MAIN_USE_ADVANCED_PERMS') || (bool) $user->hasRight('facture', 'invoice_advance', 'validate');
        case 'edit_invoice_rate':
            return (bool) $user->hasRight('facture', 'creer');
        case 'retry_submission':
            return ksefUserCan($user, 'send');
        case 'mark_not_sent':
            return (bool) $user->hasRight('ksef', 'write');
        case 'link_ksef_number':
            return $user->hasRight('ksef', 'write') && $user->hasRight('facture', 'creer');
        case 'delete_submission':
            return $user->hasRight('ksef', 'write') && $user->hasRight('facture', 'supprimer');
        case 'create_supplier_invoice':
            return $user->hasRight('fournisseur', 'facture', 'creer') || $user->hasRight('supplier_invoice', 'creer');
        case 'import_incoming':
        case 'delete_incoming':
            return $user->hasRight('ksef', 'write') && ksefUserCan($user, 'create_supplier_invoice');
        case 'create_supplier':
            return (bool) $user->hasRight('societe', 'creer');
        case 'create_product':
            return count(ksefCreatableProductTypes($user)) > 0;
        case 'create_customer':
            return (bool) $user->hasRight('societe', 'creer');
        case 'import_sales':
        case 'delete_sales':
        case 'adjust_invoice':
            return $user->hasRight('ksef', 'write') && $user->hasRight('facture', 'creer');
        case 'stock_movement':
            return isModEnabled('stock') && (bool) $user->hasRight('stock', 'mouvement', 'creer');
        case 'record_payment':
            return isModEnabled('bank') && (bool) $user->hasRight('facture', 'paiement');
    }
    return false;
}

/**
 * @brief KSeF code meaning
 * @param string|int $code
 * @return string
 */
function ksefKsefCodeLabel($code)
{
    global $langs;
    $langs->load("ksef@ksef");
    $code = trim((string) $code);
    if ($code === '') {
        return $langs->transnoentitiesnoconv('KSEF_KsefCodeNone');
    }
    $key = 'KSEF_KsefCode' . $code;
    if (preg_match('/^\d+$/', $code) && $langs->transnoentitiesnoconv($key) !== $key) {
        return $langs->transnoentitiesnoconv($key);
    }
    if (class_exists('KsefClient') && isset(KsefClient::ERROR_CODES[(int) $code])) {
        return KsefClient::ERROR_CODES[(int) $code];
    }
    return $langs->transnoentitiesnoconv('KSEF_KsefCodeUnknown', $code);
}

/**
 * @brief Code retry class
 * @param int|string $code
 * @return string retry, fix, duplicate, ''
 */
function ksefRetryHint($code)
{
    $code = (int) $code;
    if ($code === 440) {
        return 'duplicate';
    }
    if (in_array($code, array(405, 500, 550, 21184), true)) {
        return 'retry';
    }
    if (in_array($code, array(410, 415, 430, 435, 445, 450), true)) {
        return 'fix';
    }
    return '';
}

/**
 * @brief Code next step
 * @param int|string $code
 * @return string
 */
function ksefRetryHintText($code)
{
    global $langs;
    $keys = array('retry' => 'KSEF_RetryHintRetry', 'fix' => 'KSEF_RetryHintFix', 'duplicate' => 'KSEF_RetryHintDuplicate');
    $hint = ksefRetryHint($code);
    return isset($keys[$hint]) ? $langs->transnoentitiesnoconv($keys[$hint]) : '';
}

/**
 * @brief UPO link tooltip
 * @param KsefSubmission $submission
 * @return string
 */
function ksefUpoDownloadTitle($submission)
{
    global $langs;
    $title = $langs->transnoentitiesnoconv('KSEF_DownloadUPO');
    if (empty($submission->upo_xml) && $submission->getSessionReference() === '') {
        $title .= ' - ' . $langs->transnoentitiesnoconv('KSEF_DownloadUPOSearchHint');
    }
    return $title;
}

/**
 * @brief Status check refusal
 * @param User $user
 * @param KsefSubmission|object $row
 * @return string '' when allowed
 */
function ksefStatusCheckRefusal($user, $row)
{
    global $langs;
    $langs->load('ksef@ksef');
    if (!ksefStatusCheckSearches($row)) {
        return ksefUserCan($user, 'refresh_submission') ? '' : $langs->transnoentitiesnoconv('NotEnoughPermissions');
    }
    return ksefUserCan($user, 'search_by_hash') ? '' : $langs->transnoentitiesnoconv('KSEF_HashSearchNoRight', $langs->transnoentitiesnoconv('KSEF_RightNameKsefWrite'));
}

/**
 * @brief Check uses hash
 * @param KsefSubmission|object $row
 * @return bool
 */
function ksefStatusCheckSearches($row)
{
    $session = method_exists($row, 'getSessionReference') ? $row->getSessionReference() : (string) ($row->ksef_session_reference ?? '');
    return $session === '' && !empty($row->invoice_hash) && (empty($row->offline_mode) || ($row->status ?? '') !== 'OFFLINE');
}

/**
 * @brief Row action button
 * @param string $kind
 * @param string $href Escaped
 * @param string $title Escaped
 * @param string $icon
 * @param string $attrs
 * @return string HTML
 */
function ksefActIcon($kind, $href, $title, $icon, $attrs = '')
{
    return '<a class="butAction ksef-act-' . $kind . ' classfortooltip" href="' . $href . '" title="' . $title . '" aria-label="' . $title . '"' . $attrs . '><span class="fa ' . $icon . '"></span></a>';
}

/**
 * @brief Common errors box
 * @param array $rows
 * @param bool $open
 * @return void
 */
function ksefPrintErrorSummary($rows, $open)
{
    global $langs;
    if (empty($rows)) {
        return;
    }
    $total = 0;
    foreach ($rows as $r) {
        $total += (int) $r[2];
    }
    print '<details class="ksef-error-summary info"' . ($open ? ' open' : '') . '>';
    print '<summary><span class="fa fa-exclamation-triangle ksef-error-icon paddingright"></span><strong>' . $langs->trans("KSEF_CommonErrors") . '</strong> <span class="badge ' . ksefToneBadgeClass('problem') . ' badge-status">' . $total . '</span></summary>';
    print '<table class="noborder centpercent"><tr class="liste_titre"><th>' . $langs->trans("KSEF_ErrorCode") . '</th><th>' . $langs->trans("Description") . '</th><th class="right">' . $langs->trans("KSEF_ErrorCount") . '</th></tr>';
    foreach ($rows as $r) {
        $code = (string) $r[0];
        print '<tr class="oddeven"><td><strong>' . ($code !== '' ? ksefEscapeText($code) : '-') . '</strong></td><td>' . ksefEscapeText((string) $r[1]) . '</td><td class="right"><span class="badge badge-warning">' . (int) $r[2] . '</span></td></tr>';
    }
    print '</table></details>';
}

/**
 * @brief Slotted row actions
 * @param array $order
 * @param array $actions Slot => HTML
 * @param array|null $used Null for all
 * @return string HTML
 */
function ksefRowActions($order, $actions, $used = null)
{
    $out = '<span class="ksef-slots">';
    foreach ($order as $slot) {
        if ($used !== null && !in_array($slot, $used, true)) {
            continue;
        }
        $html = isset($actions[$slot]) ? (string) $actions[$slot] : '';
        $out .= '<span class="ksef-slot ksef-slot-' . $slot . '">' . $html . '</span>';
    }
    return $out . '</span>';
}

/**
 * @brief Row actions placeholder
 * @param array $rowActs
 * @param array $actions Slot => HTML
 * @return string
 */
function ksefRowActionsHold(&$rowActs, $actions)
{
    $rowActs[] = $actions;
    return '<!--ksef-row-actions:' . (count($rowActs) - 1) . '-->';
}

/**
 * @brief Fill row actions
 * @param string $html
 * @param array $order
 * @param array $rowActs
 * @return string HTML
 */
function ksefRowActionsFill($html, $order, $rowActs)
{
    $used = array();
    foreach ($rowActs as $acts) {
        foreach ($acts as $slot => $btn) {
            if ((string) $btn !== '') {
                $used[$slot] = true;
            }
        }
    }
    $used = array_keys($used);
    return preg_replace_callback('/<!--ksef-row-actions:(\d+)-->/', function ($m) use ($order, $rowActs, $used) {
        return ksefRowActions($order, isset($rowActs[(int) $m[1]]) ? $rowActs[(int) $m[1]] : array(), $used);
    }, $html);
}

/**
 * @brief Failed send tooltip
 * @param int|string|null $code
 * @param string|null $message
 * @return string HTML
 */
function ksefErrorTooltip($code, $message)
{
    global $langs;
    $langs->load("ksef@ksef");
    $parts = array();
    $code = trim((string) $code);
    if ($code !== '') {
        $parts[] = '<strong>' . ksefEscapeText($code . ': ' . ksefKsefCodeLabel($code)) . '</strong>';
    }
    if (trim((string) $message) !== '') {
        $parts[] = ksefEscapeText(dol_trunc((string) $message, 300));
    }
    $hint = $code !== '' ? ksefRetryHintText($code) : '';
    if ($hint !== '') {
        $parts[] = '<em>' . ksefEscapeText($hint) . '</em>';
    }
    if (!$parts) {
        $parts[] = ksefEscapeText($langs->transnoentitiesnoconv('KSEF_NoErrorDetails'));
    }
    return implode('<br>', $parts);
}

/**
 * @brief Import rights
 * @param User $user
 * @return array
 */
function ksefIncomingImportRights($user)
{
    return array(
        'import' => ksefUserCan($user, 'import_incoming'),
        'create_supplier' => ksefUserCan($user, 'create_supplier'),
        'create_product' => ksefUserCan($user, 'create_product'),
    );
}

/**
 * @brief Can read invoice
 * @param User $user
 * @param int $fk_facture
 * @return bool
 */
function ksefUserCanReadInvoice($user, $fk_facture)
{
    if ((int) $fk_facture <= 0 || empty($user) || empty($user->id)) {
        return false;
    }
    if (!$user->hasRight('ksef', 'read') || !$user->hasRight('facture', 'lire')) {
        return false;
    }
    require_once DOL_DOCUMENT_ROOT . '/core/lib/security.lib.php';
    return (bool) checkUserAccessToObject($user, array('facture'), (int) $fk_facture, '', '', 'fk_soc', 'rowid');
}

/**
 * @brief Load readable submission
 * @param DoliDB $db
 * @param User $user
 * @param int $sub_id
 * @param int $fk_facture 0 for any
 * @return KsefSubmission|null
 */
function ksefFetchSubmissionForUser($db, $user, $sub_id, $fk_facture = 0)
{
    if ((int) $sub_id <= 0) {
        return null;
    }
    dol_include_once('/ksef/class/ksef_submission.class.php');
    $submission = new KsefSubmission($db);
    if ($submission->fetch((int) $sub_id) <= 0) {
        return null;
    }
    if ((int) $fk_facture > 0 && (int) $submission->fk_facture !== (int) $fk_facture) {
        return null;
    }
    if (!ksefUserCanReadInvoice($user, $submission->fk_facture)) {
        return null;
    }
    return $submission;
}

/**
 * @brief Update invoice extrafields
 * @param $db
 * @param $invoice_id
 * @param $ksef_number
 * @param $status
 * @param $submission_date Timestamp
 * @param $regenerate_pdf
 * @return int -1 on error
 */
function ksefUpdateInvoiceExtrafields($db, $invoice_id, $ksef_number = null, $status = null, $submission_date = null, $regenerate_pdf = false)
{
    global $langs;

    require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

    $invoice = new Facture($db);
    if ($invoice->fetch($invoice_id) <= 0) {
        return -1;
    }

    if (empty($invoice->array_options)) {
        $invoice->array_options = array();
    }

    if ($ksef_number !== null) {
        $invoice->array_options['options_ksef_number'] = $ksef_number;
    }
    if ($status !== null) {
        $invoice->array_options['options_ksef_status'] = $status;
    }
    if ($submission_date !== null) {
        $invoice->array_options['options_ksef_submission_date'] = $submission_date;
    }

    $result = $invoice->insertExtraFields();

    if ($result >= 0 && $regenerate_pdf && !empty($ksef_number)) {
        $invoice->fetch($invoice_id);
        try {
            $pdf_result = $invoice->generateDocument($invoice->model_pdf, $langs, 0, 0, 0);
        } catch (Throwable $e) {
            $pdf_result = -1;
            $invoice->error = $e->getMessage();
        }
        if ($pdf_result > 0) {
            dol_syslog("KSeF: PDF regenerated for invoice " . $invoice->ref . " with KSEF number", LOG_INFO);
        } else {
            dol_syslog("KSeF: PDF regeneration failed for invoice " . $invoice->ref . ": " . $invoice->error, LOG_ERR);
        }
    }

    return $result >= 0 ? 1 : -1;
}

/**
 * @brief Date is weekday
 * @param int|string $date
 * @return bool
 */
function ksefIsWeekDay($date)
{
    if (!is_numeric($date)) {
        $date = strtotime($date);
    }
    $dayOfWeek = date('N', $date);
    return ($dayOfWeek >= 1 && $dayOfWeek <= 5);
}

/**
 * @brief Next working day
 * @param int|string $date
 * @return int Day end, Warsaw
 */
function ksefGetNextWeekday($date)
{
    if (!is_numeric($date)) {
        $date = strtotime($date);
    }

    $nextDay = strtotime('+1 day', strtotime('00:00:00', $date));

    while (!ksefIsWorkingDay($nextDay)) {
        $nextDay = strtotime('+1 day', $nextDay);
    }

    // Warsaw day end
    $end = new DateTime(date('Y-m-d', $nextDay) . ' 23:59:59', new DateTimeZone('Europe/Warsaw'));
    return $end->getTimestamp();
}

/**
 * @brief Offline24 deadline
 * @param int|string $invoice_date
 * @return int
 */
function ksefCalculateOfflineDeadline($invoice_date)
{
    if (!is_numeric($invoice_date)) {
        $invoice_date = strtotime($invoice_date);
    }
    return ksefGetNextWeekday($invoice_date);
}

/**
 * @brief Add working days
 * @param int|string $date
 * @param int $days
 * @return int Day end
 */
function ksefAddWorkingDays($date, $days)
{
    if (!is_numeric($date)) {
        $date = strtotime($date);
    }
    for ($i = 0; $i < (int) $days; $i++) {
        $date = ksefGetNextWeekday($date);
    }
    return $date;
}

/**
 * @brief Warsaw day start
 * @param int $ts
 * @return int Server-zone midnight
 */
function ksefWarsawDay($ts)
{
    $d = new DateTime('@' . (int) $ts);
    $d->setTimezone(new DateTimeZone('Europe/Warsaw'));
    return strtotime($d->format('Y-m-d'));
}

/**
 * @brief Offline deadline info
 * @param int|string $invoice_date
 * @param array|null $events Null reads stored
 * @param int $stored
 * @param string|null $environment
 * @return array
 */
function ksefOfflineDeadlineInfo($invoice_date, $events = null, $stored = 0, $environment = null)
{
    if (!is_numeric($invoice_date)) {
        $invoice_date = strtotime($invoice_date);
    }
    if ($events === null) {
        dol_include_once('/ksef/class/ksef_latarnia.class.php');
        $events = KsefLatarnia::storedEvents(KsefLatarnia::environmentLabel($environment));
    }
    $day = strtotime(date('Y-m-d', $invoice_date));
    $deadline = ksefCalculateOfflineDeadline($day);
    $rule = 'offline24';
    $eventId = '';
    $pending = '';

    $list = array();
    foreach ((array) $events as $ev) {
        if (is_array($ev) && !empty($ev['start'])) {
            $ev['s'] = ksefWarsawDay((int) $ev['start']);
            $ev['e'] = !empty($ev['end']) ? ksefWarsawDay((int) $ev['end']) : 0;
            $list[] = $ev;
        }
    }
    usort($list, function ($a, $b) {
        return $a['start'] - $b['start'];
    });

    foreach ($list as $ev) {
        if ($ev['category'] !== 'MAINTENANCE' || $ev['s'] > $day || ($ev['e'] && $ev['e'] < $day)) {
            continue;
        }
        if (!$ev['e']) {
            $pending = (string) $ev['id'];
            continue;
        }
        $cand = ksefGetNextWeekday($ev['e']);
        if ($cand > $deadline) {
            $deadline = $cand;
            $rule = 'offline';
            $eventId = (string) $ev['id'];
        }
    }

    do {
        $changed = false;
        $limit = $pending !== '' ? PHP_INT_MAX : $deadline;
        foreach ($list as $ev) {
            if (!in_array($ev['category'], array('FAILURE', 'TOTAL_FAILURE'), true) || $ev['s'] > $limit || ($ev['e'] && $ev['e'] < $day)) {
                continue;
            }
            if ($ev['category'] === 'TOTAL_FAILURE') {
                return array('state' => 'exempt', 'deadline' => 0, 'rule' => 'total_failure', 'event' => (string) $ev['id']);
            }
            if (!$ev['e']) {
                if ($pending === '') {
                    $pending = (string) $ev['id'];
                    $changed = true;
                }
                continue;
            }
            $cand = ksefAddWorkingDays($ev['e'], 7);
            if ($cand > $deadline) {
                $deadline = $cand;
                $rule = 'failure';
                $eventId = (string) $ev['id'];
                $changed = true;
            }
        }
    } while ($changed);

    if ($pending !== '') {
        return array('state' => 'pending', 'deadline' => 0, 'rule' => 'failure', 'event' => $pending);
    }
    return array('state' => 'due', 'deadline' => max($deadline, (int) $stored), 'rule' => $rule, 'event' => $eventId);
}

/**
 * @brief Invoice offline deadline
 * @param DoliDB $db
 * @param int $fk_facture
 * @param string $environment
 * @param int $stored
 * @param int|null $invoice_date
 * @return array
 */
function ksefOfflineDeadlineForInvoice($db, $fk_facture, $environment, $stored, $invoice_date = null)
{
    dol_include_once('/ksef/class/ksef_latarnia.class.php');
    $events = KsefLatarnia::storedEvents(KsefLatarnia::environmentLabel($environment));
    $plain = array('state' => 'due', 'deadline' => (int) $stored, 'rule' => 'offline24', 'event' => '');
    if (empty($events)) {
        return $plain;
    }
    if (empty($invoice_date)) {
        $res = $db->query("SELECT datef FROM " . MAIN_DB_PREFIX . "facture WHERE rowid = " . (int) $fk_facture);
        $obj = $res ? $db->fetch_object($res) : null;
        if (!$obj || empty($obj->datef)) {
            return $plain;
        }
        $invoice_date = $db->jdate($obj->datef);
    }
    return ksefOfflineDeadlineInfo($invoice_date, $events, $stored);
}

/**
 * @brief Detect backdated invoice
 * @param int|string $invoice_date
 * @return array
 */
function ksefDetectBackdating($invoice_date)
{
    if (!is_numeric($invoice_date)) {
        $invoice_date = strtotime($invoice_date);
    }

    // Warsaw days
    $invoice_day = date('Y-m-d', $invoice_date);
    $days_behind = (int) round((strtotime(ksefWarsawToday()) - strtotime($invoice_day)) / 86400);

    $result = array(
        'is_backdated' => false,
        'days_behind' => $days_behind,
        'reason' => '',
        'deadline' => 0
    );

    if ($days_behind > 0) {
        $result['is_backdated'] = true;
        $result['deadline'] = ksefOfflineCreationDeadline($invoice_date);
        // dialogs switch on
        $result['reason'] = 'backdated';
    }

    return $result;
}

/**
 * @brief Deadline passed
 * @param int $deadline
 * @return bool
 */
function ksefIsDeadlinePassed($deadline)
{
    return (dol_now() > $deadline);
}

/**
 * @brief New offline deadline
 * @param int|string $invoice_date
 * @return int
 */
function ksefOfflineCreationDeadline($invoice_date)
{
    $info = ksefOfflineDeadlineInfo($invoice_date);
    return $info['state'] === 'due' ? $info['deadline'] : ksefCalculateOfflineDeadline($invoice_date);
}

/**
 * @brief Deadline text
 * @param array $info
 * @return string
 */
function ksefOfflineDeadlineText($info)
{
    if ($info['state'] !== 'due') {
        return ksefOfflineDeadlineNote($info);
    }
    return dol_print_date($info['deadline'], 'dayhour');
}

/**
 * @brief Deadline rule note
 * @param array $info
 * @param bool $help Longer text
 * @return string
 */
function ksefOfflineDeadlineNote($info, $help = false)
{
    global $langs;
    $langs->load("ksef@ksef");

    if (!is_array($info)) {
        return '';
    }
    if ($info['state'] === 'pending') {
        return $langs->trans($help ? 'KSEF_DeadlineAfterOutage_Help' : 'KSEF_DeadlineAfterOutage');
    }
    if ($info['state'] === 'exempt') {
        return $langs->trans($help ? 'KSEF_DeadlineNotRequired_Help' : 'KSEF_DeadlineNotRequired');
    }
    $keys = array('offline' => 'KSEF_DeadlineMovedUnavailable', 'failure' => 'KSEF_DeadlineMovedFailure');
    return isset($keys[$info['rule']]) ? $langs->trans($keys[$info['rule']]) : '';
}

/**
 * @brief Format offline deadline
 * @param int $deadline
 * @param bool $compact
 * @param array|null $info
 * @return string HTML
 */
function ksefFormatDeadline($deadline, $compact = false, $info = null)
{
    global $langs;
    $langs->load("ksef@ksef");

    $moved = ksefOfflineDeadlineNote($info);
    if (is_array($info) && $info['state'] !== 'due') {
        return '<span class="badge badge-info ' . ksefToneClass('info') . ' classfortooltip" title="' . dol_escape_htmltag(ksefOfflineDeadlineNote($info, true)) . '">' . $moved . '</span>';
    }
    if (is_array($info) && !empty($info['deadline'])) {
        $deadline = $info['deadline'];
    }

    $now = dol_now();
    $hours_remaining = ($deadline - $now) / 3600;

    $formatted = dol_print_date($deadline, 'dayhour');

    if ($hours_remaining < 0) {
        $class = 'badge-danger ' . ksefToneClass('problem');
        $note = $langs->trans('KSEF_DeadlinePassed');
    } elseif ($hours_remaining < 4) {
        $class = 'badge-warning ' . ksefToneClass('check');
        $note = $langs->trans('KSEF_DeadlineUrgent');
    } elseif ($moved !== '') {
        return '<span class="badge badge-info ' . ksefToneClass('info') . ' classfortooltip" title="' . dol_escape_htmltag($moved) . '">' . $formatted . '</span>';
    } else {
        return '<span class="badge badge-info ' . ksefToneClass('info') . '">' . $formatted . '</span>';
    }
    if ($compact) {
        if ($hours_remaining < 0) {
            return '<span class="badge ' . $class . ' ksef-offline-overdue classfortooltip" title="' . dol_escape_htmltag($formatted . ' - ' . $note) . '">' . $langs->trans('KSEF_OfflineOverdueShort') . '</span>';
        }
        return '<span class="badge ' . $class . ' classfortooltip" title="' . $note . '">' . $formatted . '</span>';
    }
    return '<span class="badge ' . $class . '">' . $formatted . ' (' . $note . ')</span>';
}

/**
 * @brief Offline mode badge
 * @param string $mode
 * @return string HTML
 */
function ksefGetOfflineModeBadge($mode)
{
    global $langs;
    $langs->load("ksef@ksef");

    if (empty($mode)) {
        return '';
    }

    $badges = array(
        'OFFLINE' => '<span class="badge badge-warning ' . ksefToneClass('offline') . ' badge-status">' . $langs->trans('KSEF_MODE_OFFLINE') . '</span>',
    );

    return isset($badges[$mode]) ? $badges[$mode] : '<span class="badge badge-status">' . htmlspecialchars($mode) . '</span>';
}

/**
 * @brief Auth certificate status
 * @return array
 */
function ksefCheckAuthCertificate($environment = null)
{
    if ($environment === null) {
        $environment = ksefGetEnvironment();
    }
    $environment = strtoupper($environment);

    $result = array(
        'configured' => false,
        'valid' => false,
        'expires_soon' => false,
        'serial' => '',
        'valid_to' => null
    );

    if (empty(getDolGlobalString('KSEF_AUTH_CERTIFICATE_' . $environment)) ||
        empty(getDolGlobalString('KSEF_AUTH_PRIVATE_KEY_' . $environment)) || ksefKeyPasswordMissing('AUTH', $environment)) {
        return $result;
    }

    $result['configured'] = true;
    $result['serial'] = getDolGlobalString('KSEF_AUTH_CERT_SERIAL_' . $environment, '');

    $valid_to = getDolGlobalString('KSEF_AUTH_CERT_VALID_TO_' . $environment, '');
    $valid_to = is_numeric($valid_to) ? (int) $valid_to : 0;
    if ($valid_to > 0) {
        $result['valid_to'] = $valid_to;

        $now = dol_now();
        if ($valid_to > $now) {
            $result['valid'] = true;

            $thirty_days = 30 * 24 * 3600;
            if (($valid_to - $now) < $thirty_days) {
                $result['expires_soon'] = true;
            }
        }
    } else {
        $result['valid'] = true;
    }

    return $result;
}

/**
 * @brief Base64 to Base64URL
 * @param string $base64
 * @return string
 */
function ksefBase64ToBase64URL($base64)
{
    return rtrim(strtr($base64, '+/', '-_'), '=');
}

/**
 * @brief Base64URL to Base64
 * @param string $base64url
 * @return string
 */
function ksefBase64URLToBase64($base64url)
{
    $base64 = strtr($base64url, '-_', '+/');
    $padding = strlen($base64) % 4;
    if ($padding) {
        $base64 .= str_repeat('=', 4 - $padding);
    }
    return $base64;
}

/**
 * @brief PEM to DER
 * @param string $pem
 * @return string
 */
function ksefPemToDer($pem)
{
    $begin = '-----BEGIN CERTIFICATE-----';
    $end = '-----END CERTIFICATE-----';
    $pem = str_replace(array($begin, $end, "\r", "\n", " "), '', $pem);
    return base64_decode($pem);
}

/**
 * @brief ECDSA DER->raw
 * @param string $derSignature
 * @param int $componentSize R/S size
 * @return string
 */
function ksefConvertEcdsaDerToRaw($derSignature, $componentSize = 32)
{
    $offset = 0;
    $len = strlen($derSignature);

    if ($len < 2 || ord($derSignature[$offset]) != 0x30) {
        return $derSignature;
    }
    $offset++;

    $seqLen = ord($derSignature[$offset]);
    $offset++;
    if ($seqLen > 0x80) {
        $offset += ($seqLen - 0x80);
    }

    if ($offset >= $len || ord($derSignature[$offset]) != 0x02) {
        return $derSignature;
    }
    $offset++;
    $rLength = ord($derSignature[$offset]);
    $offset++;
    $r = substr($derSignature, $offset, $rLength);
    $offset += $rLength;

    if ($offset >= $len || ord($derSignature[$offset]) != 0x02) {
        return $derSignature;
    }
    $offset++;
    $sLength = ord($derSignature[$offset]);
    $offset++;
    $s = substr($derSignature, $offset, $sLength);

    $r = ltrim($r, "\x00");
    $s = ltrim($s, "\x00");

    $r = str_pad($r, $componentSize, "\x00", STR_PAD_LEFT);
    $s = str_pad($s, $componentSize, "\x00", STR_PAD_LEFT);

    return $r . $s;
}

/**
 * @brief Load offline certificate
 * @return array|false
 */
function ksefLoadOfflineCertificate($environment = null)
{
    require_once DOL_DOCUMENT_ROOT . '/core/lib/security.lib.php';

    if ($environment === null) {
        $environment = ksefGetEnvironment();
    }
    $environment = strtoupper($environment);

    $cert_base64 = getDolGlobalString('KSEF_OFFLINE_CERTIFICATE_' . $environment, '');
    if (empty($cert_base64)) {
        dol_syslog("ksefLoadOfflineCertificate: Certificate not configured for $environment", LOG_WARNING);
        return false;
    }

    $key_base64 = getDolGlobalString('KSEF_OFFLINE_PRIVATE_KEY_' . $environment, '');
    if (empty($key_base64)) {
        dol_syslog("ksefLoadOfflineCertificate: Private key not configured for $environment", LOG_WARNING);
        return false;
    }

    $encrypted_password = getDolGlobalString('KSEF_OFFLINE_KEY_PASSWORD_' . $environment, '');

    $certificate_pem = base64_decode($cert_base64);
    $encrypted_key_pem = base64_decode($key_base64);
    $password = ($encrypted_password !== '') ? dol_decode($encrypted_password) : '';

    $private_key = openssl_pkey_get_private($encrypted_key_pem, $password);
    if (!$private_key) {
        dol_syslog("ksefLoadOfflineCertificate: Failed to decrypt key for $environment: " . openssl_error_string(), LOG_ERR);
        return false;
    }

    $private_key_pem = '';
    openssl_pkey_export($private_key, $private_key_pem);

    return array(
        'certificate_pem' => $certificate_pem,
        'private_key_pem' => $private_key_pem,
        'serial' => getDolGlobalString('KSEF_OFFLINE_CERT_SERIAL_' . $environment, '')
    );
}

/**
 * @brief Check offline certificate
 * @return array
 */
function ksefIsOfflineCertificateConfigured($environment = null)
{
    if ($environment === null) {
        $environment = ksefGetEnvironment();
    }
    $environment = strtoupper($environment);

    $result = array(
        'configured' => false,
        'missing' => array()
    );

    if (empty(getDolGlobalString('KSEF_OFFLINE_CERTIFICATE_' . $environment))) {
        $result['missing'][] = 'certificate';
    }
    if (empty(getDolGlobalString('KSEF_OFFLINE_PRIVATE_KEY_' . $environment))) {
        $result['missing'][] = 'private_key';
    }
    if (ksefKeyPasswordMissing('OFFLINE', $environment)) {
        $result['missing'][] = 'password';
    }
    $result['configured'] = empty($result['missing']);
    $result['valid_to'] = null;
    $result['valid'] = $result['configured'];
    $result['expires_soon'] = false;
    $validTo = getDolGlobalString('KSEF_OFFLINE_CERT_VALID_TO_' . $environment, '');
    if ($result['configured'] && $validTo !== '') {
        $result['valid_to'] = $validTo;
        $result['valid'] = ((int) $validTo > dol_now());
        $result['expires_soon'] = $result['valid'] && ((int) $validTo - dol_now()) < 30 * 86400;
    }

    return $result;
}

/**
 * @brief Offline cert refusal
 * @param string $missingKey Fallback lang key
 * @param array|null $cert Certificate check result
 * @return string
 */
function ksefOfflineCertRefusalText($missingKey = 'KSEF_OfflineCertificateRequired', $cert = null)
{
    global $langs;
    $langs->load("ksef@ksef");
    if ($cert === null) {
        $cert = ksefIsOfflineCertificateConfigured();
    }
    if (ksefOfflineCertExpired($cert)) {
        return $langs->transnoentitiesnoconv('KSEF_OfflineCertExpiredOn', dol_print_date((int) $cert['valid_to'], 'day'));
    }
    return $langs->transnoentitiesnoconv($missingKey);
}

/**
 * @brief Offline certificate expired
 * @param array $cert Certificate check result
 * @return bool
 */
function ksefOfflineCertExpired($cert)
{
    return !empty($cert['configured']) && empty($cert['valid']) && !empty($cert['valid_to']);
}

/**
 * @brief KSeF PDF path
 * @param Facture $invoice
 * @param bool $preview
 * @return string
 */
function ksefInvoicePdfPath($invoice, $preview = false)
{
    global $conf;
    $ref = dol_sanitizeFileName($invoice->ref);
    $entity = !empty($invoice->entity) ? $invoice->entity : $conf->entity;
    // hides from core
    return $conf->invoice->multidir_output[$entity] . '/' . $ref . '/' . $ref . ($preview ? '-ksef_podglad.pdf' : '_ksef.pdf');
}

/**
 * @brief Invoice document URL
 * @param Facture $invoice
 * @param string $path
 * @param bool $inline Show in browser
 * @return string
 */
function ksefInvoiceDocumentUrl($invoice, $path, $inline)
{
    global $conf;
    $ref = dol_sanitizeFileName($invoice->ref);
    $entity = !empty($invoice->entity) ? (int) $invoice->entity : (int) $conf->entity;
    return DOL_URL_ROOT . '/document.php?modulepart=facture&entity=' . $entity . '&file=' . urlencode($ref . '/' . basename((string) $path))
        . '&attachment=' . ($inline ? '0' : '1');
}

/**
 * @brief Preview PDF name
 * @param string $path
 * @return bool
 */
function ksefIsPreviewPdfName($path)
{
    return (bool) preg_match('/[-_]ksef_podglad\.pdf$/i', (string) $path);
}

/**
 * @brief Rename old previews
 * @param Facture $invoice
 * @param bool $always Even if {ref}.pdf exists
 * @return bool True if moved
 */
function ksefMoveOldPreviewPdf($invoice, $always = false)
{
    $final = ksefInvoicePdfPath($invoice);
    $preview = ksefInvoicePdfPath($invoice, true);
    $dir = dirname($final);
    $ref = dol_sanitizeFileName($invoice->ref);
    if (!$always && is_file($dir . '/' . $ref . '.pdf')) {
        return false;
    }
    $moved = false;
    foreach (array($final, $dir . '/' . $ref . '_ksef_podglad.pdf') as $old) {
        if (!is_file($old) || ($old === $final && !ksefPdfIsPreview($old))) {
            continue;
        }
        if (is_file($preview) ? !@unlink($old) : !@rename($old, $preview)) {
            dol_syslog("ksefMoveOldPreviewPdf - cannot move " . $old, LOG_WARNING);
            continue;
        }
        dol_syslog("ksefMoveOldPreviewPdf - old preview " . basename($old) . " of invoice " . (int) $invoice->id . " kept out of the mass action", LOG_INFO);
        $moved = true;
    }
    return $moved;
}

/**
 * @brief PDF is preview
 * @param string $path
 * @return bool
 */
function ksefPdfIsPreview($path)
{
    if (!is_file($path) || !is_readable($path)) {
        return false;
    }
    // metadata at end
    $size = (int) @filesize($path);
    $fh = @fopen($path, 'rb');
    if (!$fh) {
        return false;
    }
    if ($size > 262144) {
        fseek($fh, -262144, SEEK_END);
    }
    $tail = (string) fread($fh, 262144);
    fclose($fh);
    $utf16 = "\xFE\xFF" . mb_convert_encoding('PODGLĄD', 'UTF-16BE', 'UTF-8');
    if (strpos($tail, '/Title (' . $utf16) !== false || strpos($tail, '>PODGLĄD - ') !== false) {
        return true;
    }
    // 1.4.x: no marker
    if ($size <= 0 || $size > 20971520 || !preg_match('/(_ksef|[-_]ksef_podglad)\.pdf$/i', $path)) {
        return false;
    }
    $data = (string) @file_get_contents($path);
    $word = '[(' . mb_convert_encoding('PODGLĄD', 'UTF-16BE', 'UTF-8') . ')] TJ';
    if (!preg_match_all('/\/FlateDecode[^>]*>>\s*stream\r?\n/', $data, $m, PREG_OFFSET_CAPTURE)) {
        return false;
    }
    foreach ($m[0] as $hit) {
        $start = $hit[1] + strlen($hit[0]);
        $end = strpos($data, 'endstream', $start);
        if ($end === false) {
            break;
        }
        $plain = @gzuncompress(rtrim(substr($data, $start, $end - $start), "\r\n"));
        if ($plain !== false && strpos($plain, $word) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * @brief Remove preview PDF
 * @param Facture $invoice
 * @return void
 */
function ksefRemovePreviewPdf($invoice)
{
    $preview = ksefInvoicePdfPath($invoice, true);
    // 1.5 dev name
    foreach (array($preview, dirname($preview) . '/' . dol_sanitizeFileName($invoice->ref) . '_ksef_podglad.pdf') as $file) {
        if (is_file($file) && !@unlink($file)) {
            dol_syslog("ksefRemovePreviewPdf - cannot delete " . $file, LOG_WARNING);
        }
    }
}

/**
 * @brief Save KSeF PDF
 * @param DoliDB $db
 * @param KsefSubmission $submission
 * @param int $invoice_id
 * @param string &$errorMsg
 * @return bool
 */
function ksefAutoGeneratePdf($db, $submission, $invoice_id, &$errorMsg = '')
{
    global $conf;

    try {
        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
        dol_include_once('/ksef/class/ksef_invoice_pdf.class.php');
        dol_include_once('/ksef/class/actions_ksef.class.php');

        $invoice = new Facture($db);
        if ($invoice->fetch($invoice_id) <= 0) {
            $errorMsg = "Could not fetch invoice $invoice_id";
            dol_syslog("ksefAutoGeneratePdf - " . $errorMsg, LOG_WARNING);
            return false;
        }
        // even on failure
        ksefRemovePreviewPdf($invoice);

        $actions = new ActionsKSEF($db);
        $invoiceData = $actions->createPdfDataFromSubmission($submission, $invoice);

        $pdfGenerator = new KsefInvoicePdf($db);
        $pdfContent = $pdfGenerator->generate($invoiceData);

        if ($pdfContent === false) {
            $errorMsg = $pdfGenerator->error ?: 'PDF generation failed';
            dol_syslog("ksefAutoGeneratePdf - generate failed: " . $errorMsg, LOG_ERR);
            return false;
        }

        $sanitizedRef = dol_sanitizeFileName($invoice->ref);
        $outputDir = $conf->invoice->multidir_output[$invoice->entity ?? $conf->entity] . '/' . $sanitizedRef;
        if (!is_dir($outputDir)) {
            $mkdirResult = dol_mkdir($outputDir);
            if ($mkdirResult < 0) {
                $errorMsg = "Could not create directory: $outputDir";
                dol_syslog("ksefAutoGeneratePdf - dol_mkdir failed for $outputDir (result=$mkdirResult)", LOG_ERR);
                return false;
            }
        }

        $filepath = ksefInvoicePdfPath($invoice);
        $bytesWritten = @file_put_contents($filepath, $pdfContent);
        if ($bytesWritten === false && ksefPdfIsPreview($filepath) && @unlink($filepath)) {
            $bytesWritten = @file_put_contents($filepath, $pdfContent);
        }
        if ($bytesWritten === false) {
            $lastError = error_get_last();
            $detail = $lastError ? $lastError['message'] : 'unknown';
            // path in log
            $errorMsg = "Could not write file " . basename($filepath);
            dol_syslog("ksefAutoGeneratePdf - file_put_contents failed: $filepath - $detail", LOG_ERR);
            return false;
        }

        dol_syslog("ksefAutoGeneratePdf - saved $filepath ($bytesWritten bytes)", LOG_INFO);
        return true;

    } catch (\Throwable $e) {
        $errorMsg = $e->getMessage();
        dol_syslog("ksefAutoGeneratePdf ERROR: " . get_class($e) . ': ' . $errorMsg, LOG_ERR);
        return false;
    }
}

/**
 * @brief SHA-256 Base64 hash
 * @param string $data
 * @return string
 */
function ksefCalculateHash($data)
{
    return base64_encode(hash('sha256', $data, true));
}

/**
 * @brief ASN.1 length
 * @param int $length
 * @return string
 */
function ksefBuildASN1Length($length)
{
    if ($length < 128) {
        return chr($length);
    }
    $lengthBytes = '';
    $temp = $length;
    while ($temp > 0) {
        $lengthBytes = chr($temp & 0xFF) . $lengthBytes;
        $temp >>= 8;
    }
    return chr(0x80 | strlen($lengthBytes)) . $lengthBytes;
}

/**
 * @brief ASN.1 SEQUENCE
 * @param string $content
 * @return string
 */
function ksefBuildASN1Sequence($content)
{
    return chr(0x30) . ksefBuildASN1Length(strlen($content)) . $content;
}

/**
 * @brief ASN.1 SET
 * @param string $content
 * @return string
 */
function ksefBuildASN1Set($content)
{
    return chr(0x31) . ksefBuildASN1Length(strlen($content)) . $content;
}

/**
 * @brief ASN.1 INTEGER
 * @param string $number Decimal or hex
 * @return string
 */
function ksefBuildASN1Integer($number)
{
    if (preg_match('/^[0-9A-Fa-f]+$/', $number) && strlen($number) % 2 === 0 && strlen($number) > 2) {
        $bytes = hex2bin($number);
    } else {
        if (function_exists('gmp_init')) {
            $hex = gmp_strval(gmp_init($number, 10), 16);
        } else {
            $hex = dechex($number);
        }
        if (strlen($hex) % 2) $hex = '0' . $hex;
        $bytes = hex2bin($hex);
    }
    if (ord($bytes[0]) & 0x80) {
        $bytes = "\x00" . $bytes;
    }
    return chr(0x02) . ksefBuildASN1Length(strlen($bytes)) . $bytes;
}

/**
 * @brief Encode OID component
 * @param int $value
 * @return string
 */
function ksefEncodeOIDComponent($value)
{
    if ($value < 128) {
        return chr($value);
    }
    $bytes = array();
    while ($value > 0) {
        array_unshift($bytes, $value & 0x7F);
        $value >>= 7;
    }
    $encoded = '';
    for ($i = 0; $i < count($bytes); $i++) {
        $byte = $bytes[$i];
        if ($i < count($bytes) - 1) {
            $byte |= 0x80;
        }
        $encoded .= chr($byte);
    }
    return $encoded;
}

/**
 * @brief ASN.1 OBJECT IDENTIFIER
 * @param string $oid Dotted OID
 * @return string
 */
function ksefBuildASN1ObjectIdentifier($oid)
{
    $parts = explode('.', $oid);
    $encoded = chr(40 * intval($parts[0]) + intval($parts[1]));
    for ($i = 2; $i < count($parts); $i++) {
        $encoded .= ksefEncodeOIDComponent(intval($parts[$i]));
    }
    return chr(0x06) . ksefBuildASN1Length(strlen($encoded)) . $encoded;
}

/**
 * @brief ASN.1 UTF8String
 * @param string $string
 * @return string
 */
function ksefBuildASN1UTF8String($string)
{
    return chr(0x0C) . ksefBuildASN1Length(strlen($string)) . $string;
}

/**
 * @brief ASN.1 PrintableString
 * @param string $string
 * @return string
 */
function ksefBuildASN1PrintableString($string)
{
    return chr(0x13) . ksefBuildASN1Length(strlen($string)) . $string;
}

/**
 * @brief DER RDN
 * @param string $attr Attribute name
 * @param string $value
 * @return string
 */
function ksefBuildRDN($attr, $value)
{
    $oids = array(
        'C'            => '2.5.4.6',
        'ST'           => '2.5.4.8',
        'L'            => '2.5.4.7',
        'O'            => '2.5.4.10',
        'OU'           => '2.5.4.11',
        'CN'           => '2.5.4.3',
        'serialNumber' => '2.5.4.5',
    );
    $oid = isset($oids[$attr]) ? $oids[$attr] : $attr;
    $oidDER = ksefBuildASN1ObjectIdentifier($oid);
    if ($attr === 'C') {
        $valueDER = ksefBuildASN1PrintableString($value);
    } else {
        $valueDER = ksefBuildASN1UTF8String($value);
    }
    $attrTypeAndValue = ksefBuildASN1Sequence($oidDER . $valueDER);
    return ksefBuildASN1Set($attrTypeAndValue);
}

/**
 * @brief DER X.500 name
 * @param array $components Parsed issuer
 * @return string
 */
function ksefBuildX500NameDER($components)
{
    $order = array('CN', 'OU', 'O', 'L', 'ST', 'C', 'serialNumber');
    $rdns = '';

    foreach ($order as $key) {
        $value = null;
        if (isset($components[$key])) {
            $value = $components[$key];
        } elseif (isset($components[strtolower($key)])) {
            $value = $components[strtolower($key)];
        }
        if ($value !== null) {
            if (is_array($value)) {
                $value = $value[0];
            }
            $rdns .= ksefBuildRDN($key, $value);
        }
    }
    return ksefBuildASN1Sequence($rdns);
}

/**
 * @brief RFC 2253 issuer
 * @param array $issuer Parsed issuer
 * @return string
 */
function ksefFormatIssuerNameRfc2253($issuer)
{
    $parts = array();
    $order = array('CN', 'OU', 'O', 'L', 'ST', 'C');

    foreach ($order as $key) {
        if (isset($issuer[$key])) {
            $val = $issuer[$key];
            $val = str_replace(
                array('\\', ',', '+', '"', '<', '>', ';'),
                array('\\\\', '\\,', '\\+', '\\"', '\\<', '\\>', '\\;'),
                $val
            );
            $parts[] = $key . '=' . $val;
        }
    }
    return implode(',', $parts);
}

/**
 * @brief XAdES IssuerSerialV2
 * @param string $certificatePem
 * @return string Base64 DER
 */
function ksefGenerateIssuerSerialV2DER($certificatePem)
{
    $certInfo = openssl_x509_parse($certificatePem);
    if (!$certInfo) {
        return '';
    }

    $issuerNameDER = ksefBuildX500NameDER($certInfo['issuer']);
    $generalNameDER = chr(0xA4) . ksefBuildASN1Length(strlen($issuerNameDER)) . $issuerNameDER;
    $generalNamesDER = ksefBuildASN1Sequence($generalNameDER);
    $serialDER = ksefBuildASN1Integer($certInfo['serialNumber']);
    $issuerSerialDER = ksefBuildASN1Sequence($generalNamesDER . $serialDER);

    return base64_encode($issuerSerialDER);
}

/**
 * @brief Load auth certificate
 * @return array|false
 */
function ksefLoadAuthCertificate($environment = null)
{
    require_once DOL_DOCUMENT_ROOT . '/core/lib/security.lib.php';

    if ($environment === null) {
        $environment = ksefGetEnvironment();
    }
    $environment = strtoupper($environment);

    $cert_base64 = getDolGlobalString('KSEF_AUTH_CERTIFICATE_' . $environment, '');
    if (empty($cert_base64)) {
        dol_syslog("ksefLoadAuthCertificate: Certificate not configured for $environment", LOG_WARNING);
        return false;
    }

    $key_base64 = getDolGlobalString('KSEF_AUTH_PRIVATE_KEY_' . $environment, '');
    if (empty($key_base64)) {
        dol_syslog("ksefLoadAuthCertificate: Private key not configured for $environment", LOG_WARNING);
        return false;
    }

    $encrypted_password = getDolGlobalString('KSEF_AUTH_KEY_PASSWORD_' . $environment, '');

    $certificate_pem = base64_decode($cert_base64);
    $encrypted_key_pem = base64_decode($key_base64);
    $password = ($encrypted_password !== '') ? dol_decode($encrypted_password) : '';

    $private_key = openssl_pkey_get_private($encrypted_key_pem, $password);
    if (!$private_key) {
        dol_syslog("ksefLoadAuthCertificate: Failed to decrypt key for $environment: " . openssl_error_string(), LOG_ERR);
        return false;
    }

    $private_key_pem = '';
    openssl_pkey_export($private_key, $private_key_pem);

    return array(
        'certificate_pem' => $certificate_pem,
        'private_key_pem' => $private_key_pem,
        'serial' => getDolGlobalString('KSEF_AUTH_CERT_SERIAL_' . $environment, '')
    );
}

/**
 * @brief Write session value
 * @param string $key
 * @param mixed $value
 * @param bool $closeAfter
 * @return bool
 */
function ksefSessionWrite($key, $value, $closeAfter = true)
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        } else {
            dol_syslog("ksefSessionWrite: Cannot start session, status=" . session_status(), LOG_WARNING);
            return false;
        }
    }

    $_SESSION[$key] = $value;

    if ($closeAfter) {
        session_write_close();
    }

    return true;
}

/**
 * @brief Unset session value
 * @param string $key
 * @param bool $closeAfter
 * @return bool
 */
function ksefSessionUnset($key, $closeAfter = true)
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        } else {
            return false;
        }
    }

    unset($_SESSION[$key]);

    if ($closeAfter) {
        session_write_close();
    }

    return true;
}

/**
 * @brief Read session value
 * @param string $key
 * @param mixed $default
 * @param bool $closeAfter
 * @return mixed
 */
function ksefSessionRead($key, $default = null, $closeAfter = true)
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        } else {
            return $default;
        }
    }

    $value = isset($_SESSION[$key]) ? $_SESSION[$key] : $default;

    if ($closeAfter) {
        session_write_close();
    }

    return $value;
}

/**
 * @brief Incoming status badge
 * @param $status
 * @param string $importError Import error text
 * @return string
 */
function ksefGetIncomingStatusBadge($status, $importError = '')
{
    global $langs;
    $langs->load("ksef@ksef");

    $map = ksefIncomingStatusMap();
    if ($status === 'FETCH_ERROR' && strpos((string) $importError, 'unsupported_form:') === 0) {
        return '<span class="badge badge-status0 ' . ksefToneClass('info') . ' badge-status">' . $langs->trans('KSEF_FetchErrorUnsupported') . '</span>';
    }
    if (isset($map[$status])) {
        return '<span class="badge badge-' . $map[$status][0] . ' ' . ksefToneClass($map[$status][2]) . ' badge-status">' . $langs->trans($map[$status][1]) . '</span>';
    }
    return '<span class="badge badge-status0 badge-status">' . dol_escape_htmltag($status) . '</span>';
}

/**
 * @brief Import status map
 * @return array
 */
function ksefIncomingStatusMap()
{
    return array(
        'NEW' => array('status1', 'KSEF_ImportStatusNEW', 'action'),
        'IMPORTED' => array('status4', 'KSEF_ImportStatusIMPORTED', 'ok'),
        'SKIPPED' => array('status6', 'KSEF_ImportStatusSKIPPED', 'info'),
        'ERROR' => array('status8', 'KSEF_ImportStatusERROR', 'problem'),
        'FETCH_ERROR' => array('status8', 'KSEF_ImportStatusFETCH_ERROR', 'problem'),
    );
}

/**
 * @brief Set KSeF environment
 * @param DoliDB $db
 * @param string $environment
 * @return int 1 changed, 0 unchanged, <0 error
 */
function ksefSetEnvironment($db, $environment)
{
    global $conf;

    require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
    $environment = strtoupper((string) $environment);
    if (!in_array($environment, array('TEST', 'DEMO', 'PRODUCTION'), true)) {
        return -1;
    }
    $old = getDolGlobalString('KSEF_ENVIRONMENT', '');
    if (dolibarr_set_const($db, 'KSEF_ENVIRONMENT', $environment, 'chaine', 0, '', $conf->entity) < 0) {
        return -1;
    }
    if ($old === $environment) {
        return 0;
    }
    dol_include_once('/ksef/class/ksef_sync_state.class.php');
    foreach (array('incoming', 'outgoing') as $direction) {
        $syncState = new KsefSyncState($db);
        $syncState->load($direction);
        if ((string) $syncState->environment !== $environment) {
            $syncState->resetAll($environment);
        }
    }
    return 1;
}

/**
 * @brief Invoice type badge
 * @param $type
 * @return string
 */
function ksefGetInvoiceTypeBadge($type)
{
    $type = (string) $type;
    $classes = array('VAT' => 'ksef-type-vat', 'UPR' => 'ksef-type-upr', 'ZAL' => 'ksef-type-zal', 'ROZ' => 'ksef-type-roz',
        'KOR' => 'ksef-type-kor', 'KOR_ZAL' => 'ksef-type-kor', 'KOR_ROZ' => 'ksef-type-kor');
    $class = isset($classes[$type]) ? $classes[$type] : 'ksef-type-upr';
    $text = dol_escape_htmltag($type);
    if ($type === 'KOR_ZAL' || $type === 'KOR_ROZ') {
        $sub = substr($type, 4);
        $text = 'KOR_<span class="ksef-type-sub-' . strtolower($sub) . '">' . $sub . '</span>';
    }
    return '<span class="badge ksef-type ' . $class . ' classfortooltip" title="' . dol_escape_htmltag(ksefInvoiceTypeLabel($type)) . '">' . $text . '</span>';
}

/**
 * @brief Incoming card tabs
 * @param $object
 * @return array
 */
function ksef_incoming_prepare_head($object)
{
    global $langs, $conf;
    $langs->load("ksef@ksef");

    $h = 0;
    $head = array();

    $head[$h][0] = dol_buildpath("/ksef/incoming_card.php", 1) . '?id=' . $object->id;
    $head[$h][1] = $langs->trans("Card");
    $head[$h][2] = 'card';
    $h++;

    $head[$h][0] = dol_buildpath("/ksef/incoming_import.php", 1) . '?id=' . $object->id;
    $head[$h][1] = $langs->trans("KSEF_ImportToDolibarr");
    $head[$h][2] = 'import';
    $h++;

    complete_head_from_modules($conf, $langs, $object, $head, $h, 'ksef_incoming');

    complete_head_from_modules($conf, $langs, $object, $head, $h, 'ksef_incoming', 'remove');

    return $head;
}

/**
 * @brief Sales card tabs
 * @param KsefSales $object
 * @return array
 */
function ksef_sales_prepare_head($object)
{
    global $langs, $conf;
    $langs->load("ksef@ksef");

    $h = 0;
    $head = array();

    $head[$h][0] = dol_buildpath("/ksef/sales_card.php", 1) . '?id=' . $object->id;
    $head[$h][1] = $langs->trans("Card");
    $head[$h][2] = 'card';
    $h++;

    $head[$h][0] = dol_buildpath("/ksef/sales_import.php", 1) . '?id=' . $object->id;
    $head[$h][1] = $langs->trans("KSEF_ImportToDolibarr");
    $head[$h][2] = 'import';
    $h++;

    if ($object->link_status === 'DIFFERS' && !empty($object->fk_facture)) {
        $head[$h][0] = dol_buildpath("/ksef/sales_adjust.php", 1) . '?id=' . $object->id;
        $head[$h][1] = $langs->trans("KSEF_AdjustToKsef");
        $head[$h][2] = 'adjust';
        $h++;
    }

    complete_head_from_modules($conf, $langs, $object, $head, $h, 'ksef_sales');

    complete_head_from_modules($conf, $langs, $object, $head, $h, 'ksef_sales', 'remove');

    return $head;
}

/**
 * @brief XML date, NIP
 * @param string $xml
 * @return array
 */
function ksefXmlIssueInfo($xml)
{
    $out = array('date' => '', 'nip' => '');
    if (preg_match('#<(?:\w+:)?P_1>\s*(\d{4}-\d{2}-\d{2})\s*</(?:\w+:)?P_1>#', (string) $xml, $m)) {
        $out['date'] = $m[1];
    }
    if (preg_match('#<(?:\w+:)?Podmiot1>.*?<(?:\w+:)?NIP>\s*(\d{10})\s*</#s', (string) $xml, $m)) {
        $out['nip'] = $m[1];
    }
    return $out;
}

/**
 * @brief Verification URL
 * @param $ksef_number
 * @param $fa3_xml
 * @param $environment
 * @return string
 */
function ksefGetVerificationUrlFromXml($ksef_number, $fa3_xml, $environment = null)
{
    if (empty($ksef_number) || empty($fa3_xml)) {
        return '';
    }

    $hash = base64_encode(hash('sha256', $fa3_xml, true));

    try {
        return ksefGetVerificationURL($ksef_number, $hash, $environment, null, null, $fa3_xml);
    } catch (Exception $e) {
        dol_syslog("ksefGetVerificationUrlFromXml: " . $e->getMessage(), LOG_WARNING);
        return '';
    }
}

/**
 * @brief Supplier invoice card
 * @param FactureFournisseur $invoice
 * @param string $title
 * @param string $icon
 * @param string $iconColor
 * @param string $description
 * @param FactureFournisseur|null $originalInv Credited invoice
 * @return void
 */
function ksefPrintSupplierInvoiceCard($invoice, $title, $icon = '', $iconColor = '', $description = '', $originalInv = null)
{
    global $langs;

    $invoice->fetch_lines();

    $currency = !empty($invoice->multicurrency_code) ? $invoice->multicurrency_code : 'PLN';
    $isMulticurrency = ($currency !== 'PLN');

    print '<div class="div-table-responsive-no-min" style="margin-top: 15px; margin-bottom: 5px;">';
    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre">';
    print '<td class="liste_titre">';
    if ($icon) {
        print '<span class="fa ' . $icon . '" style="color: ' . dol_escape_htmltag($iconColor) . '; margin-right: 6px;"></span>';
    }
    print dol_escape_htmltag($title);
    print '</td>';
    print '</tr>';
    print '</table>';
    print '</div>';

    print '<div class="fichecenter">';

    print '<div class="fichehalfleft">';
    print '<table class="border tableforfield centpercent">';

    print '<tr><td class="titlefield">' . $langs->trans("Ref") . '</td>';
    print '<td>' . $invoice->getNomUrl(1) . ' ' . ksefInvoiceStatusBadge($invoice, 5) . '</td></tr>';

    print '<tr><td>' . $langs->trans("Type") . '</td><td>';
    if ($invoice->type == FactureFournisseur::TYPE_CREDIT_NOTE) {
        print '<span class="badgeneutral">' . $langs->trans("InvoiceAvoir") . '</span>';
    } else {
        print '<span class="badgeneutral">' . $langs->trans("InvoiceStandard") . '</span>';
    }
    print '</td></tr>';

    if ($originalInv) {
        print '<tr><td>' . $langs->trans("KSEF_ReplaceCreditNoteDesc_Source") . '</td>';
        print '<td>' . $originalInv->getNomUrl(1) . ' ' . ksefInvoiceStatusBadge($originalInv, 5) . '</td></tr>';
    }

    if (!empty($invoice->ref_supplier)) {
        print '<tr><td>' . $langs->trans("RefSupplierBill") . '</td>';
        print '<td>' . dol_escape_htmltag($invoice->ref_supplier) . '</td></tr>';
    }

    print '<tr><td>' . $langs->trans("DateInvoice") . '</td>';
    print '<td>' . dol_print_date($invoice->date, 'day') . '</td></tr>';

    if (!empty($description)) {
        print '<tr><td>' . $langs->trans("Description") . '</td>';
        print '<td><span class="opacitymedium">' . dol_escape_htmltag($description) . '</span></td></tr>';
    }

    print '</table>';
    print '</div>';

    print '<div class="fichehalfright">';
    print '<table class="border tableforfield centpercent">';

    if ($isMulticurrency) {
        print '<tr><td class="titlefieldmiddle">' . $langs->trans("Currency") . '</td>';
        print '<td>' . dol_escape_htmltag($currency) . '</td></tr>';
    }

    print '<tr>';
    print '<td class="titlefieldmiddle">' . $langs->trans('AmountHT') . '</td>';
    if ($isMulticurrency) {
        print '<td class="nowrap amountcard right">' . price($invoice->multicurrency_total_ht, 0, $langs, 0, -1, -1, $currency) . '</td>';
    } else {
        print '<td class="nowrap amountcard right">' . price($invoice->total_ht, 0, $langs, 0, -1, -1, $currency) . '</td>';
    }
    print '</tr>';

    print '<tr>';
    print '<td>' . $langs->trans('AmountVAT') . '</td>';
    if ($isMulticurrency) {
        print '<td class="nowrap amountcard right">' . price($invoice->multicurrency_total_tva, 0, $langs, 0, -1, -1, $currency) . '</td>';
    } else {
        print '<td class="nowrap amountcard right">' . price($invoice->total_tva, 0, $langs, 0, -1, -1, $currency) . '</td>';
    }
    print '</tr>';

    print '<tr>';
    print '<td>' . $langs->trans('AmountTTC') . '</td>';
    if ($isMulticurrency) {
        print '<td class="nowrap amountcard right">' . price($invoice->multicurrency_total_ttc, 0, $langs, 0, -1, -1, $currency) . '</td>';
    } else {
        print '<td class="nowrap amountcard right">' . price($invoice->total_ttc, 0, $langs, 0, -1, -1, $currency) . '</td>';
    }
    print '</tr>';

    print '</table>';
    print '</div>';

    print '</div>';
    print '<div class="clearboth"></div>';

    if (!empty($invoice->lines)) {
        print '<div class="div-table-responsive-no-min">';
        print '<table class="noborder noshadow centpercent">';

        print '<tr class="liste_titre nodrag nodrop">';
        print '<td class="linecoldescription">' . $langs->trans("Description") . '</td>';
        print '<td class="linecolvat right nowraponall">' . $langs->trans("VAT") . '</td>';
        print '<td class="linecoluht right nowraponall">' . $langs->trans("PriceUHT") . '</td>';
        print '<td class="linecolqty right">' . $langs->trans("Qty") . '</td>';
        print '<td class="linecolht right">' . $langs->trans("TotalHT") . '</td>';
        print '</tr>';

        foreach ($invoice->lines as $line) {
            $unitPrice = $isMulticurrency ? $line->multicurrency_subprice : $line->subprice;
            $totalHT = $isMulticurrency ? $line->multicurrency_total_ht : $line->total_ht;

            print '<tr class="oddeven">';
            print '<td class="linecoldescription">' . dolPrintHTML($line->desc) . '</td>';
            print '<td class="linecolvat nowrap right">' . vatrate($line->tva_tx) . '%</td>';
            print '<td class="linecoluht nowraponall right">' . price($unitPrice, 0, '', 1, -1, 2) . '</td>';
            print '<td class="linecolqty nowraponall right">' . price($line->qty, 0, '', 1, -1, 4) . '</td>';
            print '<td class="linecolht nowrap right">' . price($totalHT, 0, '', 1, -1, 2) . '</td>';
            print '</tr>';
        }

        $totalHT = $isMulticurrency ? $invoice->multicurrency_total_ht : $invoice->total_ht;
        print '<tr class="liste_total">';
        print '<td colspan="4" class="right">' . $langs->trans("Total") . '</td>';
        print '<td class="right nowraponall">' . price($totalHT, 0, $langs, 0, -1, -1, $currency) . '</td>';
        print '</tr>';

        print '</table>';
        print '</div>';
    }
}

/**
 * @brief Parse extrafield config
 * @param string $configStr
 * @return array
 */
function ksefParseEfConfig($configStr)
{
    $result = array();
    $items = array_filter(array_map('trim', explode(',', $configStr)));
    foreach ($items as $item) {
        $parts = explode(':', $item, 2);
        $fname = $parts[0];
        $target = isset($parts[1]) ? $parts[1] : 'dodatkowy';
        if ($fname !== '') $result[$fname] = $target;
    }
    return $result;
}

/**
 * @brief Module version
 * @param DoliDB $db
 * @return string
 */
function ksefGetModuleVersion($db)
{
    static $version = null;
    if ($version === null) {
        dol_include_once('/ksef/core/modules/modKSEF.class.php');
        $module = new modKSEF($db);
        $version = $module->version;
    }
    return $version;
}

/**
 * @brief Render banner box
 * @param string $iconClass
 * @param string $tone
 * @param string $innerHtml Pre-escaped HTML
 * @return string
 */
function ksefRenderBanner($iconClass, $tone, $innerHtml)
{
    $h = '<div class="ksef-note-' . $tone . '" style="margin-bottom: 15px; padding: 12px; border-radius: 4px;">';
    $h .= '<i class="' . $iconClass . ' ' . ksefToneTextClass($tone) . '" style="margin-right: 6px;"></i>';
    $h .= $innerHtml;
    $h .= '</div>';
    return $h;
}

/**
 * @brief Render download link
 * @param string $latest Latest version
 * @param string $zipUrl
 * @param string $attrs Extra attributes
 * @return string
 */
function ksefRenderDownloadLink($latest, $zipUrl, $attrs = '')
{
    global $langs;
    if (!ksefUpdateUrlTrusted($zipUrl)) {
        return '';
    }
    $confirm = dol_escape_js($langs->trans('KSEF_UpdateDownloadConfirm', $latest));
    return '<a href="' . dol_escape_htmltag($zipUrl) . '" target="_blank" rel="noopener noreferrer"' . ($attrs !== '' ? ' ' . $attrs : '') . ' onclick="return confirm(\'' . $confirm . '\');">' . $langs->trans('KSEF_DownloadUpdate') . '</a>';
}

/**
 * @brief Reactivation warning banner
 * @return string
 */
function ksefShowReactivationWarning()
{
    global $db, $langs, $user;
    $langs->load("ksef@ksef");

    $currentVersion = ksefGetModuleVersion($db);
    $lastInit = getDolGlobalString('KSEF_LAST_INIT_VERSION', '');

    if (!ksefNeedsReactivation($currentVersion, $lastInit)) {
        return '';
    }

    $fromVersion = $lastInit !== '' ? $lastInit : $langs->trans('KSEF_VERSION_UNKNOWN');
    $inner = '<strong>' . $langs->trans('KSEF_MODULE_NEEDS_REACTIVATION') . '</strong><br>';
    $inner .= dol_escape_htmltag($langs->trans('KSEF_MODULE_NEEDS_REACTIVATION_Desc', $fromVersion, $currentVersion));
    // no purge-on-disable
    dol_include_once('/ksef/lib/ksef_dbcheck.lib.php');
    if (ksefDbCheckMayChangeTables($user)) {
        $inner .= ' <a href="' . dol_buildpath('/ksef/admin/about.php', 1) . '?action=ksef_reinit_ask&token=' . newToken() . '" class="button button-small ksef-update-apply" style="margin-left: 8px;">' . $langs->trans('KSEF_ApplyUpdate') . '</a>';
        dol_include_once('/ksef/lib/ksef_tour.lib.php');
        $inner .= ' ' . ksefTourButton('update_apply', $langs->trans('KSEF_Tour_UpdateHowTo'));
    } elseif (!empty($user->admin)) {
        $inner .= '<br>' . $langs->trans('KSEF_DbSuperadminOnly');
    }
    if (getDolGlobalInt('KSEF_PURGE_ON_DISABLE')) {
        $inner .= '<br><strong class="' . ksefToneTextClass('problem') . '">' . $langs->trans('KSEF_MODULE_NEEDS_REACTIVATION_PurgeWarn') . '</strong>';
    }

    return ksefRenderBanner('fa fa-exclamation-triangle', 'check', $inner);
}

/**
 * @brief Minimum Dolibarr version
 * @param DoliDB $db
 * @return array
 */
function ksefGetMinDolibarrVersion($db)
{
    static $need = null;
    if ($need === null) {
        dol_include_once('/ksef/core/modules/modKSEF.class.php');
        $module = new modKSEF($db);
        $need = (is_array($module->need_dolibarr_version) && !empty($module->need_dolibarr_version)) ? $module->need_dolibarr_version : array(22, 0);
    }
    return $need;
}

/**
 * @brief Running Dolibarr version
 * @return string
 */
function ksefRunningDolibarrVersion()
{
    if (defined('KSEF_ALLOW_TEST_OVERRIDE') && constant('KSEF_ALLOW_TEST_OVERRIDE') && getDolGlobalString('KSEF_TEST_DOL_VERSION') !== '') {
        return getDolGlobalString('KSEF_TEST_DOL_VERSION');
    }
    return DOL_VERSION;
}

/**
 * @brief Dolibarr version check
 * @param array|string $wanted
 * @param string|null $running Null: current
 * @return bool
 */
function ksefDolibarrAtLeast($wanted, $running = null)
{
    if ($running === null) {
        $running = ksefRunningDolibarrVersion();
    }
    $run = preg_replace('/[^0-9.].*$/', '', trim((string) $running));
    $want = is_array($wanted) ? implode('.', $wanted) : (string) $wanted;
    if ($run === '') {
        return true;
    }
    return version_compare($run, $want, '>=');
}

/**
 * @brief Dolibarr too old
 * @param string|null $running Null: current
 * @return bool
 */
function ksefDolibarrVersionTooOld($running = null)
{
    global $db;
    return !ksefDolibarrAtLeast(ksefGetMinDolibarrVersion($db), $running);
}

/**
 * @brief Dolibarr version warning
 * @param string|null $running Null: current
 * @return string
 */
function ksefShowDolibarrVersionWarning($running = null)
{
    global $db, $langs;
    if ($running === null) {
        $running = ksefRunningDolibarrVersion();
    }
    if (!ksefDolibarrVersionTooOld($running)) {
        return '';
    }
    $langs->load("ksef@ksef");
    $inner = '<strong>' . $langs->trans('KSEF_DolibarrTooOld') . '</strong><br>';
    $inner .= dol_escape_htmltag($langs->trans('KSEF_DolibarrTooOld_Desc', $running, implode('.', ksefGetMinDolibarrVersion($db))));
    return ksefRenderBanner('fa fa-exclamation-triangle', 'problem', $inner);
}

/**
 * @brief Update URL trusted
 * @param string $url
 * @return bool
 */
function ksefUpdateUrlTrusted($url)
{
    $url = (string) $url;
    if ($url === '' || preg_match('/[\s"\'<>\\\\]/', $url)) {
        return false;
    }
    $prefixes = array(
        'https://github.com/InPoint-Automation/Dolibarr-KSeF-Module/releases',
        'https://api.github.com/repos/InPoint-Automation/Dolibarr-KSeF-Module/zipball/',
    );
    $fake = ksefEndpointOverride('GITHUB', '');
    if ($fake !== '' && preg_match('#^(https?://[^/]+)/#', $fake . '/', $m)) {
        $prefixes[] = $m[1] . '/';
    }
    foreach ($prefixes as $p) {
        if (strpos($url, $p) !== 0) {
            continue;
        }
        // reject look-alikes
        $next = (string) substr($url, strlen($p), 1);
        if (substr($p, -1) === '/' || $next === '' || in_array($next, array('/', '?', '#'), true)) {
            return true;
        }
    }
    return false;
}

/**
 * @brief Check for update
 * @param DoliDB $db
 * @return array
 */
function ksefCheckForUpdate($db)
{
    global $conf, $langs;
    require_once DOL_DOCUMENT_ROOT . '/core/lib/geturl.lib.php';

    $current = ksefGetModuleVersion($db);

    $result = array('status' => 'error', 'current' => $current, 'latest' => '', 'available' => false, 'release_url' => 'https://github.com/InPoint-Automation/Dolibarr-KSeF-Module/releases', 'zip_url' => '', 'message' => '');

    $apiurl = ksefEndpointOverride('GITHUB', 'https://api.github.com/repos/InPoint-Automation/Dolibarr-KSeF-Module/releases/latest');
    $headers = array('Accept: application/vnd.github+json', 'User-Agent: Dolibarr-KSeF-Module');

    dol_syslog("ksefCheckForUpdate GET $apiurl", LOG_INFO);
    $isTestFake = ksefEndpointIsOverridden('GITHUB');
    // no redirects
    $res = getURLContent($apiurl, 'GET', '', 0, $headers, $isTestFake ? array('http', 'https') : array('https'), $isTestFake ? 2 : 0, -1, 5, 10);

    if (!empty($res['curl_error_no'])) {
        $result['message'] = $res['curl_error_msg'];
        return $result;
    }
    if (empty($res['http_code']) || $res['http_code'] != 200 || empty($res['content'])) {
        $result['message'] = 'HTTP ' . (empty($res['http_code']) ? '0' : $res['http_code']);
        return $result;
    }

    $data = json_decode($res['content'], true);
    $latest = (!empty($data) && !empty($data['tag_name'])) ? ltrim((string) $data['tag_name'], 'vV') : '';
    if (!preg_match('/^\d+(\.\d+){1,3}$/', $latest)) {
        $latest = '';
    }
    if ($latest === '') {
        $result['message'] = $langs->trans('KSEF_UpdateCheckParseError');
        return $result;
    }

    if (!empty($data['html_url']) && ksefUpdateUrlTrusted($data['html_url'])) {
        $result['release_url'] = $data['html_url'];
    }

    $zipUrl = '';
    if (!empty($data['assets']) && is_array($data['assets'])) {
        foreach ($data['assets'] as $asset) {
            if (!empty($asset['browser_download_url']) && !empty($asset['name']) && preg_match('/\.zip$/i', $asset['name']) && ksefUpdateUrlTrusted($asset['browser_download_url'])) {
                $zipUrl = $asset['browser_download_url'];
                break;
            }
        }
    }
    if ($zipUrl === '' && !empty($data['zipball_url']) && ksefUpdateUrlTrusted($data['zipball_url'])) {
        $zipUrl = $data['zipball_url'];
    }

    $available = (version_compare($current, $latest) < 0);

    $result['status'] = 'ok';
    $result['latest'] = $latest;
    $result['available'] = $available;
    $result['zip_url'] = $zipUrl;

    dolibarr_set_const($db, 'KSEF_UPDATE_LATEST_VERSION', $latest, 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'KSEF_UPDATE_RELEASE_URL', $result['release_url'], 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'KSEF_UPDATE_ZIP_URL', $zipUrl, 'chaine', 0, '', $conf->entity);

    return $result;
}

/**
 * @brief Update banner
 * @return string
 */
function ksefShowUpdateBanner()
{
    global $db, $langs;
    $langs->load("ksef@ksef");

    $latest = getDolGlobalString('KSEF_UPDATE_LATEST_VERSION', '');
    $current = ksefGetModuleVersion($db);
    if ($latest === '' || version_compare($current, $latest) >= 0) {
        return '';
    }

    $releaseUrl = getDolGlobalString('KSEF_UPDATE_RELEASE_URL', '');
    if (!ksefUpdateUrlTrusted($releaseUrl)) {
        $releaseUrl = 'https://github.com/InPoint-Automation/Dolibarr-KSeF-Module/releases';
    }
    $zipUrl = getDolGlobalString('KSEF_UPDATE_ZIP_URL', '');
    if (!preg_match('/^\d+(\.\d+){1,3}$/', $latest)) {
        return '';
    }

    $inner = '<strong>' . $langs->trans('KSEF_UpdateBannerTitle') . '</strong> ';
    $inner .= dol_escape_htmltag($langs->trans('KSEF_UpdateBannerDesc', $latest, $current));
    if ($zipUrl !== '') {
        $inner .= ' ' . ksefRenderDownloadLink($latest, $zipUrl, 'class="button button-small" style="margin-left: 8px;"');
    }
    $inner .= ' <a href="' . dol_escape_htmltag($releaseUrl) . '" target="_blank" rel="noopener noreferrer" style="margin-left: 8px;">' . $langs->trans('KSEF_Support_Releases') . '</a>';

    return ksefRenderBanner('fa fa-arrow-circle-up', 'info', $inner);
}

/**
 * @brief Run update check
 * @param DoliDB $db
 * @return array|null
 */
function ksefMaybeRunUpdateCheck($db)
{
    if (GETPOST('action', 'aZ09') === 'ksef_checkupdate') {
        return ksefCheckForUpdate($db);
    }
    return null;
}

/**
 * @brief Admin banners
 * @return string
 */
function ksefShowAdminBanners()
{
    global $langs;
    $langs->load("ksef@ksef");
    $mc = isModEnabled('multicompany') ? ksefRenderBanner('fa fa-exclamation-triangle', 'problem', '<strong>' . $langs->trans('KSEF_MulticompanyNotSupported') . '</strong><br>' . $langs->trans('KSEF_MulticompanyNotSupportedDesc')) : '';
    return $mc . ksefMainCurrencyWarning() . ksefShowDolibarrVersionWarning() . ksefShowReactivationWarning() . ksefShowUpdateBanner();
}

/**
 * @brief Main currency banner
 * @return string
 */
function ksefMainCurrencyWarning()
{
    global $conf, $langs;
    if ((string) $conf->currency === 'PLN') {
        return '';
    }
    $langs->loadLangs(array('admin', 'ksef@ksef'));
    return ksefRenderBanner('fa fa-exclamation-triangle', 'problem', '<strong class="ksef-main-currency">' . dol_escape_htmltag($langs->transnoentitiesnoconv('KSEF_MainCurrencyNotPln', $conf->currency)) . '</strong><br>'
        . ksefMainCurrencyFixHtml());
}

/**
 * @brief Main currency fix
 * @return string HTML
 */
function ksefMainCurrencyFixHtml()
{
    global $langs;
    $langs->loadLangs(array('admin', 'ksef@ksef'));
    $html = dol_escape_htmltag($langs->transnoentitiesnoconv('KSEF_MainCurrencyNotPlnFix', $langs->transnoentitiesnoconv('CompanyCurrency')));
    if (!empty($GLOBALS['user']->admin)) {
        $html .= ' <a href="' . DOL_URL_ROOT . '/admin/company.php">' . dol_escape_htmltag($langs->transnoentitiesnoconv('KSEF_MainCurrencyOpen')) . '</a>';
    }
    return $html;
}

/**
 * @brief Render support box
 * @param string $selfurl '' for PHP_SELF
 * @param array|null $update Update check result
 * @return string
 */
function ksefRenderSupportBox($selfurl = '', $update = null)
{
    global $langs;
    $langs->load("ksef@ksef");

    if ($selfurl === '') {
        $selfurl = $_SERVER['PHP_SELF'];
    }

    $sep = (strpos($selfurl, '?') !== false) ? '&' : '?';

    $h = '<table class="noborder centpercent">';
    $h .= '<tr class="liste_titre"><th><span class="fa fa-life-ring paddingright"></span>' . $langs->trans('KSEF_NeedHelp') . '</th></tr>';
    $h .= '<tr class="oddeven"><td>';

    $h .= '<p><strong>' . $langs->trans('KSEF_UpdatesTitle') . '</strong></p>';
    $h .= '<a href="' . $selfurl . $sep . 'action=ksef_checkupdate&token=' . newToken() . '" class="button small"><span class="fas fa-sync paddingright"></span>' . $langs->trans('KSEF_CheckForUpdates') . '</a>';

    if (!empty($update)) {
        if ($update['status'] === 'ok' && $update['available']) {
            $h .= '<div class="warning" style="margin-top:10px;"><span class="fas fa-arrow-circle-up paddingright"></span> ';
            // escape args only
            $h .= $langs->trans('KSEF_UpdateAvailable', dol_escape_htmltag($update['latest']), dol_escape_htmltag($update['current']));
            if (!empty($update['zip_url'])) {
                $h .= ' ' . ksefRenderDownloadLink($update['latest'], $update['zip_url']);
            } else {
                $h .= ' <a href="' . dol_escape_htmltag($update['release_url']) . '" target="_blank" rel="noopener noreferrer">' . $langs->trans('KSEF_Support_Releases') . '</a>';
            }
            $h .= '</div>';
        } elseif ($update['status'] === 'ok') {
            $h .= '<div class="ok" style="margin-top:10px;"><span class="fas fa-check-circle paddingright"></span> ' . $langs->trans('KSEF_UpToDate', $update['current']) . '</div>';
        } else {
            $h .= '<div class="warning" style="margin-top:10px;"><span class="fas fa-exclamation-triangle paddingright"></span> ';
            $h .= ksefTransHtml('KSEF_UpdateCheckFailed', array(ksefEscapeText($update['message'])));
            $h .= ' <a href="https://github.com/InPoint-Automation/Dolibarr-KSeF-Module/releases" target="_blank" rel="noopener noreferrer">' . $langs->trans('KSEF_Support_Releases') . '</a></div>';
        }
    }

    $h .= '<p style="margin-top:15px;"><strong>' . $langs->trans('KSEF_NeedHelp') . '</strong></p>';
    $h .= '<p>' . $langs->trans('KSEF_Support_Intro') . '</p>';
    $h .= '<ul>';
    $h .= '<li><a href="https://github.com/InPoint-Automation/Dolibarr-KSeF-Module/issues" target="_blank" rel="noopener noreferrer"><span class="fab fa-github paddingright"></span>' . $langs->trans('KSEF_Support_Issues') . '</a> - ' . $langs->trans('KSEF_Support_IssuesDesc') . '</li>';
    $h .= '<li><a href="https://www.dolibarr.org/forum/t/ksef-module-for-dolibarr/30788" target="_blank" rel="noopener noreferrer"><span class="fas fa-comments paddingright"></span>' . $langs->trans('KSEF_Support_Forum') . '</a> - ' . $langs->trans('KSEF_Support_ForumDesc') . '</li>';
    $h .= '<li><a href="https://inpointautomation.com/content/ksef-integration-module-for-dolibarr/" target="_blank" rel="noopener noreferrer"><span class="fas fa-book paddingright"></span>' . $langs->trans('KSEF_Support_DocsEN') . '</a></li>';
    $h .= '<li><a href="https://inpointautomation.com/pl/content/modul-ksef-dla-dolibarr/" target="_blank" rel="noopener noreferrer"><span class="fas fa-book paddingright"></span>' . $langs->trans('KSEF_Support_DocsPL') . '</a></li>';
    $h .= '<li><a href="https://github.com/InPoint-Automation/Dolibarr-KSeF-Module/releases" target="_blank" rel="noopener noreferrer"><span class="fas fa-tag paddingright"></span>' . $langs->trans('KSEF_Support_Releases') . '</a></li>';
    $h .= '</ul>';

    $h .= '</td></tr></table>';

    return $h;
}

/**
 * @brief Config warnings
 * @return array
 */
function ksefGetConfigWarnings()
{
    global $db, $langs;
    $langs->load("ksef@ksef");

    $warnings = array();
    $env = ksefGetEnvironment();

    $missing = array();
    if (!extension_loaded('openssl')) $missing[] = 'OpenSSL';
    if (!extension_loaded('curl')) $missing[] = 'cURL';
    if (!extension_loaded('dom')) $missing[] = 'DOM';
    if (!empty($missing)) {
        $warnings[] = array(
            'code' => 'php_extensions',
            'message' => $langs->trans("KSEF_WARNING_MISSING_EXTENSIONS", implode(', ', $missing)),
            'severity' => 'error',
            'tab' => 'general'
        );
    }

    $nip = ksefSellerNip();
    if (empty($nip)) {
        $warnings[] = array(
            'code' => 'nip_missing',
            'message' => $langs->trans("KSEF_WARNING_NO_NIP"),
            'severity' => 'warning',
            'tab' => 'general'
        );
    } elseif (!ksefValidateNIP($nip)) {
        $warnings[] = array(
            'code' => 'nip_invalid',
            'message' => $langs->trans("KSEF_WARNING_NIP_INVALID", dol_escape_htmltag($nip)),
            'severity' => ($env === 'PRODUCTION') ? 'error' : 'warning',
            'tab' => 'general'
        );
    }

    $configuredFields = array();
    foreach (array('NIP', 'KRS', 'REGON', 'BDO', 'EORI') as $ident) {
        $fv = ksefGetFieldName($ident);
        if (!empty($fv)) {
            $configuredFields[$fv][] = $ident;
        }
    }
    foreach ($configuredFields as $fv => $idents) {
        if (count($idents) > 1) {
            $warnings[] = array(
                'code' => 'duplicate_field',
                'message' => $langs->trans("KSEF_WARNING_DUPLICATE_FIELD", implode(', ', $idents), $fv),
                'severity' => 'warning',
                'tab' => 'general'
            );
        }
    }

    $currentOverrides = ksefGetCurrentTranslationOverrides($db);
    if (empty($currentOverrides)) {
        $warnings[] = array(
            'code' => 'trans_overrides',
            'message' => $langs->trans("KSEF_WARNING_NO_TRANS_OVERRIDES"),
            'severity' => 'warning',
            'tab' => 'dictionaries'
        );
    }

    $auth = ksefAuthReadiness($env);
    $hasCertFile = getDolGlobalString('KSEF_AUTH_CERTIFICATE_' . $env) !== '';
    $hasPrivKey = getDolGlobalString('KSEF_AUTH_PRIVATE_KEY_' . $env) !== '';
    if (!$auth['token'] && !$auth['cert']['configured']) {
        if ($hasCertFile || $hasPrivKey) {
            $missingParts = array();
            if (!$hasCertFile) $missingParts[] = $langs->trans("KSEF_Certificate");
            if (!$hasPrivKey) $missingParts[] = $langs->trans("KSEF_PrivateKey");
            if (ksefKeyPasswordMissing('AUTH', $env)) $missingParts[] = $langs->trans("KSEF_Password");
            $warnings[] = array(
                'code' => 'auth_cert_incomplete',
                'message' => '<strong>' . $langs->trans("KSEF_WARNING_AUTH_CERT_INCOMPLETE") . '</strong> - ' . $langs->trans("KSEF_WARNING_AUTH_CERT_MISSING_PARTS", implode(', ', $missingParts)),
                'severity' => 'error',
                'tab' => 'auth'
            );
        } else {
            $warnings[] = array(
                'code' => 'auth_none',
                'message' => '<strong>' . $langs->trans("KSEF_WARNING_NO_AUTH") . '</strong>: ' . $langs->trans("KSEF_WARNING_CONFIGURE_TOKEN_OR_CERT"),
                'severity' => 'error',
                'tab' => 'auth'
            );
        }
    } elseif ($auth['reason'] === 'no_token') {
        $warnings[] = array(
            'code' => 'auth_no_token',
            'message' => $langs->trans("KSEF_WARNING_NO_TOKEN"),
            'severity' => 'error',
            'tab' => 'auth'
        );
    } elseif ($auth['reason'] === 'no_cert') {
        $missingParts = array();
        if (!$hasCertFile) $missingParts[] = $langs->trans("KSEF_Certificate");
        if (!$hasPrivKey) $missingParts[] = $langs->trans("KSEF_PrivateKey");
        if (ksefKeyPasswordMissing('AUTH', $env)) $missingParts[] = $langs->trans("KSEF_Password");
        $warnings[] = array(
            'code' => 'auth_no_cert',
            'message' => $langs->trans("KSEF_WARNING_NO_AUTH_CERTIFICATE") . '. ' . $langs->trans("KSEF_WARNING_AUTH_CERT_MISSING_PARTS", implode(', ', $missingParts)),
            'severity' => 'error',
            'tab' => 'auth'
        );
    }

    if ($auth['method'] === 'certificate' && $auth['cert']['configured']) {
        if (!$auth['cert']['valid']) {
            $warnings[] = array(
                'code' => 'auth_cert_expired',
                'message' => $langs->trans("KSEF_WARNING_AUTH_CERT_EXPIRED"),
                'severity' => 'error',
                'tab' => 'auth'
            );
        } elseif ($auth['cert']['expires_soon']) {
            $warnings[] = array(
                'code' => 'auth_cert_expires_soon',
                'message' => $langs->trans("KSEF_WARNING_AUTH_CERT_EXPIRES_SOON", dol_print_date((int) $auth['cert']['valid_to'], 'day')),
                'severity' => 'warning',
                'tab' => 'auth'
            );
        }
    }
    foreach (array('AUTH' => 'KSEF_WARNING_AUTH_CERT_UNUSABLE', 'OFFLINE' => 'KSEF_WARNING_OFFLINE_CERT_UNUSABLE') as $kind => $msgKey) {
        if ($kind === 'AUTH' && $auth['method'] !== 'certificate') {
            continue;
        }
        $problem = ksefStoredCredentialProblem($kind, $env);
        if ($problem !== '') {
            $warnings[] = array(
                'code' => strtolower($kind) . '_cert_unusable',
                'message' => $langs->trans($msgKey, $langs->transnoentitiesnoconv($problem === 'key' ? 'KSEF_PrivateKeyPasswordMismatch' : 'KSEF_CertKeyMismatch')),
                'severity' => ($kind === 'AUTH') ? 'error' : 'warning',
                'tab' => 'auth'
            );
        }
    }

    $offlineCertCheck = ksefIsOfflineCertificateConfigured($env);
    if ($offlineCertCheck['configured'] && !empty($offlineCertCheck['valid_to'])) {
        if (!$offlineCertCheck['valid']) {
            $warnings[] = array(
                'code' => 'offline_cert_expired',
                'message' => $langs->trans("KSEF_WARNING_OFFLINE_CERT_EXPIRED"),
                'severity' => 'warning',
                'tab' => 'auth'
            );
        } elseif ($offlineCertCheck['expires_soon']) {
            $warnings[] = array(
                'code' => 'offline_cert_expires_soon',
                'message' => $langs->trans("KSEF_WARNING_OFFLINE_CERT_EXPIRES_SOON", dol_print_date((int) $offlineCertCheck['valid_to'], 'day')),
                'severity' => 'warning',
                'tab' => 'auth'
            );
        }
    }

    if (!getDolGlobalInt('KSEF_ADD_QR')) {
        $warnings[] = array(
            'code' => 'qr_off',
            'message' => $langs->trans("KSEF_WARNING_QR_OFF"),
            'severity' => 'warning',
            'tab' => 'outgoing'
        );
    }

    if (getDolGlobalString('KSEF_FA3_FP_SOURCE') === 'always_on') {
        $warnings[] = array(
            'code' => 'fp_always_on',
            'message' => $langs->trans("KSEF_FA3_FP_SOURCE_ALWAYS_ON_Warn"),
            'severity' => 'warning',
            'tab' => 'fields'
        );
    }

    $cronProblem = ksefCronEngineProblem($db);
    if ($cronProblem !== '') {
        $warnings[] = array(
            'code' => 'cron_' . $cronProblem,
            'message' => $langs->trans('KSEF_WARNING_CRON_' . strtoupper($cronProblem)),
            'severity' => 'warning',
            'tab' => 'about'
        );
    }

    return $warnings;
}

/**
 * @brief Render config warnings
 * @param array $warnings
 * @param string $currentTab
 * @return string
 */
function ksefRenderConfigWarnings($warnings, $currentTab = '')
{
    global $langs;
    $langs->load("ksef@ksef");

    if (empty($warnings)) {
        return '';
    }

    $tabLabels = array(
        'general'  => 'KSEF_Tab_General',
        'auth'     => 'KSEF_Tab_Authentication',
        'outgoing' => 'KSEF_Tab_Outgoing',
        'fields'   => 'KSEF_Tab_Fields',
        'incoming' => 'KSEF_Tab_Incoming',
        'dictionaries' => 'KSEF_Tab_Dictionaries',
        'about'    => 'KSEF_About',
    );

    $buckets = array('error' => '', 'warning' => '', 'info' => '');
    foreach ($warnings as $w) {
        $sev = (isset($w['severity']) && isset($buckets[$w['severity']])) ? $w['severity'] : 'warning';
        if ($sev === 'info' && (empty($w['tab']) || $w['tab'] !== $currentTab)) {
            continue;
        }
        $line = '&bull; ' . $w['message'];
        if ($sev !== 'info' && !empty($w['tab']) && $w['tab'] !== $currentTab && isset($tabLabels[$w['tab']])) {
            $line = rtrim($line, ' .') . '. ' . $langs->trans('KSEF_WarningFixOnTab', ksefConfigTabUrl($w['tab']), $langs->transnoentitiesnoconv($tabLabels[$w['tab']])) . '.';
        }
        $buckets[$sev] .= '<div class="ksef-config-warning" data-code="' . dol_escape_htmltag($w['code'] ?? '') . '">' . $line . '</div>';
    }

    $html = '';
    foreach ($buckets as $sev => $lines) {
        if ($lines !== '') {
            $html .= '<div class="' . $sev . '" style="margin-bottom: 15px;">' . $lines . '</div>';
        }
    }
    return $html;
}

/**
 * @brief Invoice kind metadata
 * @param object|string $x Invoice or element
 * @return array
 */
function ksefInvoiceKind($x)
{
    $el = is_object($x) ? (string) $x->element : (string) $x;
    if (!class_exists('Facture')) {
        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
    }
    if ($el === 'invoice_supplier' || $el === 'facture_fourn') {
        if (!class_exists('FactureFournisseur')) {
            require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
        }
        return array('class' => 'FactureFournisseur', 'table' => 'facture_fourn', 'element' => 'invoice_supplier', 'entity' => 'supplier_invoice',
            'extrafields' => 'facture_fourn', 'discount_type' => 1, 'col' => 'fk_invoice_supplier', 'col_line' => 'fk_invoice_supplier_line',
            'col_source' => 'fk_invoice_supplier_source', 'line_table' => 'facture_fourn_det', 'line_fk' => 'fk_facture_fourn', 'excess' => '(EXCESS PAID)',
            'pay_table' => 'paiementfourn_facturefourn', 'pay_fk' => 'fk_facturefourn', 'pay_id' => 'fk_paiementfourn', 'line_tva' => 'tva', 'line_pu' => 'pu_ht');
    }
    return array('class' => 'Facture', 'table' => 'facture', 'element' => 'facture', 'entity' => 'invoice',
        'extrafields' => 'facture', 'discount_type' => 0, 'col' => 'fk_facture', 'col_line' => 'fk_facture_line',
        'col_source' => 'fk_facture_source', 'line_table' => 'facturedet', 'line_fk' => 'fk_facture', 'excess' => '(EXCESS RECEIVED)',
        'pay_table' => 'paiement_facture', 'pay_fk' => 'fk_facture', 'pay_id' => 'fk_paiement', 'line_tva' => 'total_tva', 'line_pu' => 'subprice');
}

/**
 * @brief Parse contract date
 * @param string $text
 * @return array{date: ?string, nr: string}
 */
function ksefParseContractDate($text)
{
    $text = (string) $text;
    if (preg_match('/^\s*(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{2,4})\s*(?:[-\x{2013}\x{2014}]\s*)?(.*)$/su', $text, $m)) {
        $day = (int) $m[1];
        $month = (int) $m[2];
        $year = (int) $m[3];
        if (strlen($m[3]) <= 2) {
            $year += 2000;
        }
        // TDataU 1990..2050
        $iso = sprintf('%04d-%02d-%02d', $year, $month, $day);
        if (checkdate($month, $day, $year) && $iso >= '1990-01-01' && $iso <= '2050-01-01') {
            return array(
                'date' => sprintf('%04d-%02d-%02d', $year, $month, $day),
                'nr'   => trim($m[4]),
            );
        }
    }
    return array('date' => null, 'nr' => trim($text));
}

/**
 * @brief Declared KSeF totals
 * @param KsefIncoming $incoming
 * @return array
 */
function ksefIncomingDeclaredTotals($incoming)
{
    $summary = array();
    $ttc = (float) $incoming->total_gross;
    $oss = false;
    if (!empty($incoming->fa3_xml)) {
        dol_include_once('/ksef/class/fa3_parser.class.php');
        $parser = new FA3Parser($incoming->db);
        $parsed = $parser->parse($incoming->fa3_xml);
        if ($parsed) {
            $summary = isset($parsed['vat_summary']) ? $parsed['vat_summary'] : array();
            if (isset($parsed['invoice']['total_gross'])) {
                $ttc = (float) $parsed['invoice']['total_gross'];
            }
            foreach ((array) ($parsed['lines'] ?? array()) as $l) {
                $oss = $oss || !empty($l['oss']);
            }
        }
    }
    if (empty($summary)) {
        $summary = $incoming->getVatSummary();
    }
    $rates = array();
    foreach ($summary as $key => $amounts) {
        $rate = is_numeric($key) ? (string) (float) $key : '0';
        $oss = $oss || $key === 'oss';
        if (!isset($rates[$rate])) {
            $rates[$rate] = array('net' => 0.0, 'vat' => 0.0, 'vat_pln' => null);
        }
        $rates[$rate]['net'] += (float) ($amounts['net'] ?? 0);
        $rates[$rate]['vat'] += (float) ($amounts['vat'] ?? 0);
        if (isset($amounts['vat_pln']) && $amounts['vat_pln'] !== null && $amounts['vat_pln'] !== '') {
            $rates[$rate]['vat_pln'] = (float) $rates[$rate]['vat_pln'] + (float) $amounts['vat_pln'];
        }
    }
    return array('rates' => $rates, 'ttc' => $ttc, 'oss' => $oss);
}

/**
 * @brief Supplier rate totals
 * @param DoliDB $db
 * @param FactureFournisseur $facture
 * @param bool $foreign Multicurrency amounts
 * @return array
 */
function ksefSupplierInvoiceRateTotals($db, $facture, $foreign)
{
    $customer = isset($facture->element) && $facture->element === 'facture';
    $net = $foreign ? 'multicurrency_total_ht' : 'total_ht';
    $vat = $foreign ? 'multicurrency_total_tva' : ($customer ? 'total_tva' : 'tva');
    $table = $customer ? 'facturedet' : 'facture_fourn_det';
    $fk = $customer ? 'fk_facture' : 'fk_facture_fourn';
    $out = array();
    $res = $db->query("SELECT tva_tx, SUM(" . $net . ") AS net, SUM(" . $vat . ") AS vat FROM " . MAIN_DB_PREFIX . $table . " WHERE " . $fk . " = " . (int) $facture->id . " GROUP BY tva_tx");
    if ($res) {
        while ($obj = $db->fetch_object($res)) {
            $rate = (string) (float) $obj->tva_tx;
            if (!isset($out[$rate])) {
                $out[$rate] = array('net' => 0.0, 'vat' => 0.0);
            }
            $out[$rate]['net'] += (float) $obj->net;
            $out[$rate]['vat'] += (float) $obj->vat;
        }
    }
    return $out;
}

/**
 * @brief Compare imported totals
 * @param KsefIncoming $incoming
 * @param FactureFournisseur $facture
 * @param bool $pln Also check PLN VAT
 * @return array
 */
function ksefCompareIncomingTotals($incoming, $facture, $pln = false)
{
    global $conf;
    $foreign = !empty($incoming->currency) && $incoming->currency !== $conf->currency;
    $out = array('ok' => true, 'skipped' => false, 'xml_ttc' => 0.0, 'doli_ttc' => 0.0, 'currency' => $foreign ? $incoming->currency : $conf->currency,
        'vat_differs' => false, 'rates' => array(), 'pln' => array(), 'oss' => false, 'oss_differs' => false, 'other_differs' => false, 'other_vat_differs' => false);
    $isKor = strpos((string) $incoming->invoice_type, 'KOR') === 0;
    if ($isKor && (int) $incoming->fk_credit_note > 0) {
        $out['skipped'] = true;
        return $out;
    }
    $decl = ksefIncomingDeclaredTotals($incoming);
    $out['oss'] = !empty($decl['oss']);
    if ($isKor && (int) $facture->type === 1 && !empty($facture->fk_facture_source)) {
        $decl = ksefKorektaExpectedTotals($incoming->db, $facture, $decl, $foreign);
        if ($decl === null) {
            $out['skipped'] = true;
            return $out;
        }
    }
    // no after-state VAT
    if ($pln && $foreign && !$isKor && (int) $facture->type !== 2) {
        $havePln = ksefSupplierInvoiceRateTotals($incoming->db, $facture, false);
        foreach ($decl['rates'] as $rate => $x) {
            if (!isset($x['vat_pln']) || $x['vat_pln'] === null) {
                continue;
            }
            $row = array('xml_vat' => round((float) $x['vat_pln'], 2), 'doli_vat' => round((float) ($havePln[$rate]['vat'] ?? 0), 2));
            if ($row['xml_vat'] != $row['doli_vat']) {
                $out['ok'] = false;
                $out['pln'][$rate] = $row;
            }
        }
    }
    $doli = ksefSupplierInvoiceRateTotals($incoming->db, $facture, $foreign);
    $sign = ((int) $facture->type === 2 && $decl['ttc'] > 0) ? -1 : 1;
    $out['xml_ttc'] = round($sign * $decl['ttc'], 2);
    $out['doli_ttc'] = round((float) ($foreign ? $facture->multicurrency_total_ttc : $facture->total_ttc), 2);
    foreach (array_unique(array_merge(array_keys($decl['rates']), array_keys($doli))) as $rate) {
        $x = isset($decl['rates'][$rate]) ? $decl['rates'][$rate] : array('net' => 0.0, 'vat' => 0.0);
        $d = isset($doli[$rate]) ? $doli[$rate] : array('net' => 0.0, 'vat' => 0.0);
        $row = array('xml_net' => round($sign * $x['net'], 2), 'doli_net' => round($d['net'], 2), 'xml_vat' => round($sign * $x['vat'], 2), 'doli_vat' => round($d['vat'], 2));
        if ($row['xml_net'] != $row['doli_net'] || $row['xml_vat'] != $row['doli_vat']) {
            $out['ok'] = false;
            if ($row['xml_vat'] != $row['doli_vat'] && $row['xml_net'] == $row['doli_net']) {
                $out['vat_differs'] = true;
            }
        }
        $out['rates'][$rate] = $row;
    }
    if ($out['xml_ttc'] != $out['doli_ttc']) {
        $out['ok'] = false;
    }
    // OSS at 0%
    $ossRow = null;
    foreach ($out['rates'] as $rate => $row) {
        $bad = $row['xml_net'] != $row['doli_net'] || $row['xml_vat'] != $row['doli_vat'];
        if ($bad && $out['oss'] && (string) $rate === '0') {
            $out['oss_differs'] = true;
            $ossRow = $row;
        } elseif ($bad) {
            $out['other_differs'] = true;
            if ($row['xml_vat'] != $row['doli_vat'] && $row['xml_net'] == $row['doli_net']) {
                $out['other_vat_differs'] = true;
            }
        }
    }
    $ossGap = $ossRow ? ($ossRow['xml_net'] + $ossRow['xml_vat']) - ($ossRow['doli_net'] + $ossRow['doli_vat']) : 0.0;
    if (abs(($out['xml_ttc'] - $out['doli_ttc']) - $ossGap) >= 0.005) {
        $out['other_differs'] = true;
    }
    return $out;
}

/**
 * @brief Invoice KSeF record
 * @param DoliDB $db
 * @param Facture|FactureFournisseur $invoice
 * @return KsefIncoming|null
 */
function ksefInvoiceKsefRecord($db, $invoice)
{
    dol_include_once('/ksef/class/ksef_incoming.class.php');
    $id = (int) $invoice->id;
    if ($id <= 0) {
        return null;
    }
    if (ksefInvoiceKind($invoice)['element'] === 'invoice_supplier') {
        $o = $db->getRow("SELECT rowid FROM " . MAIN_DB_PREFIX . "ksef_incoming WHERE fk_facture_fourn = " . $id . " AND import_status = 'IMPORTED' ORDER BY rowid DESC");
        $rec = new KsefIncoming($db);
        return ($o && $rec->fetch((int) $o->rowid) > 0 && !empty($rec->fa3_xml)) ? $rec : null;
    }
    dol_include_once('/ksef/class/ksef_sales.class.php');
    $o = $db->getRow("SELECT rowid FROM " . MAIN_DB_PREFIX . "ksef_sales WHERE fk_facture = " . $id . " ORDER BY rowid DESC");
    $rec = new KsefSales($db);
    if ($o && $rec->fetch((int) $o->rowid) > 0 && !empty($rec->fa3_xml)) {
        return $rec;
    }
    $o = $db->getRow("SELECT fa3_xml, invoice_type FROM " . MAIN_DB_PREFIX . "ksef_submissions WHERE fk_facture = " . $id . " AND status = 'ACCEPTED'"
        . " AND fa3_xml IS NOT NULL ORDER BY rowid DESC");
    if (!$o || (string) $o->fa3_xml === '') {
        return null;
    }
    $rec = new KsefIncoming($db);
    $rec->fa3_xml = $o->fa3_xml;
    $rec->invoice_type = (string) $o->invoice_type;
    $rec->total_gross = 0;
    return $rec;
}

/**
 * @brief Invoice KSeF state
 * @param DoliDB $db
 * @param Facture|FactureFournisseur $invoice
 * @param bool $foreign Invoice currency
 * @param int $depth Recursion guard
 * @return array
 */
function ksefInvoiceKsefState($db, $invoice, $foreign, $depth = 0)
{
    $doli = function () use ($db, $invoice, $foreign) {
        return array('rates' => ksefSupplierInvoiceRateTotals($db, $invoice, $foreign), 'ttc' => (float) ($foreign ? $invoice->multicurrency_total_ttc : $invoice->total_ttc), 'known' => false);
    };
    $rec = ($depth < 20 && (int) $invoice->type !== 2) ? ksefInvoiceKsefRecord($db, $invoice) : null;
    if ($rec === null) {
        return $doli();
    }
    $decl = ksefIncomingDeclaredTotals($rec);
    $out = array('rates' => array(), 'ttc' => (float) $decl['ttc'], 'known' => true);
    foreach ($decl['rates'] as $rate => $x) {
        $out['rates'][$rate] = array('net' => (float) $x['net'], 'vat' => (float) $x['vat']);
    }
    if (strpos((string) $rec->invoice_type, 'KOR') !== 0) {
        return $out;
    }
    $cls = ksefInvoiceKind($invoice)['class'];
    $src = new $cls($db);
    if ((int) $invoice->type !== 1 || empty($invoice->fk_facture_source) || $src->fetch((int) $invoice->fk_facture_source) <= 0) {
        return $doli();
    }
    $base = ksefInvoiceKsefState($db, $src, $foreign, $depth + 1);
    foreach ($base['rates'] as $rate => $b) {
        $x = isset($out['rates'][$rate]) ? $out['rates'][$rate] : array('net' => 0.0, 'vat' => 0.0);
        $out['rates'][$rate] = array('net' => $x['net'] + (float) $b['net'], 'vat' => $x['vat'] + (float) $b['vat']);
    }
    $out['ttc'] += (float) $base['ttc'];
    $out['known'] = $base['known'];
    return $out;
}

/**
 * @brief Totals mismatch warning
 * @param array $cmp Comparison result
 * @return string
 */
function ksefIncomingTotalsWarning($cmp)
{
    global $langs;
    $langs->load('ksef@ksef');
    // escaped where printed
    $amount = function ($v) use ($langs, $cmp) {
        return html_entity_decode(price($v, 0, $langs, 1, -1, 2, $cmp['currency']), ENT_QUOTES, 'UTF-8');
    };
    $parts = array();
    if (!empty($cmp['oss_differs'])) {
        $parts[] = $langs->transnoentitiesnoconv('KSEF_ImportTotalsOss', $amount($cmp['xml_ttc']), $amount($cmp['doli_ttc']));
    }
    $other = !empty($cmp['other_differs']) || (!array_key_exists('other_differs', $cmp) && !$parts && empty($cmp['pln']));
    if ($other) {
        $vatOnly = array_key_exists('other_vat_differs', $cmp) ? !empty($cmp['other_vat_differs']) : !empty($cmp['vat_differs']);
        $msg = $parts ? $langs->transnoentitiesnoconv('KSEF_ImportTotalsOssAlsoOther')
            : $langs->transnoentitiesnoconv('KSEF_ImportTotalsMismatch', $amount($cmp['xml_ttc']), $amount($cmp['doli_ttc']));
        if ($vatOnly) {
            $msg .= ' ' . $langs->transnoentitiesnoconv('KSEF_ImportTotalsMismatchVatHint');
        } elseif (!$parts && $cmp['xml_ttc'] == $cmp['doli_ttc']) {
            $msg .= ' ' . $langs->transnoentitiesnoconv('KSEF_ImportTotalsMismatchRatesHint');
        }
        $parts[] = $msg;
    }
    if (!empty($cmp['pln'])) {
        global $conf;
        $rows = array();
        foreach ($cmp['pln'] as $rate => $r) {
            $rows[] = $langs->transnoentitiesnoconv('KSEF_ImportTotalsPlnVatRate', $rate,
                html_entity_decode(price($r['xml_vat'], 0, $langs, 1, -1, 2, $conf->currency), ENT_QUOTES, 'UTF-8'),
                html_entity_decode(price($r['doli_vat'], 0, $langs, 1, -1, 2, $conf->currency), ENT_QUOTES, 'UTF-8'));
        }
        $parts[] = $langs->transnoentitiesnoconv('KSEF_ImportTotalsPlnVat', implode('; ', $rows));
    }
    return str_replace("\xc2\xa0", ' ', implode(' ', $parts));
}

/**
 * @brief Import target totals
 * @param KsefIncoming $record
 * @param Facture|FactureFournisseur $facture
 * @param bool $foreign Invoice currency
 * @return array|null
 */
function ksefImportTargetTotals($record, $facture, $foreign)
{
    $isKor = strpos((string) $record->invoice_type, 'KOR') === 0;
    // legacy replace mode
    if ($isKor && (int) $record->fk_credit_note > 0 || (int) $facture->type === 2) {
        return null;
    }
    $decl = ksefIncomingDeclaredTotals($record);
    $decl['korekta'] = false;
    if ($isKor && (int) $facture->type === 1 && !empty($facture->fk_facture_source)) {
        $decl = ksefKorektaExpectedTotals($record->db, $facture, $decl, $foreign);
        if ($decl === null) {
            return null;
        }
        $decl['korekta'] = true;
    }
    return $decl;
}

/**
 * @brief Import rounding slack
 * @param KsefIncoming $record
 * @param array $target Target totals
 * @param bool $foreign Invoice currency
 * @param bool $legacy 1.4.5 line formula
 * @return array
 */
function ksefImportRoundingSlack($record, $target, $foreign, $legacy)
{
    $out = array();
    $add = function ($rate, $v) use (&$out) {
        $out[$rate] = ($out[$rate] ?? 0.0) + abs((float) $v);
    };
    if (!empty($target['korekta']) && !empty($target['source']['known']) && !empty($target['source']['invoice'])) {
        $doli = ksefSupplierInvoiceRateTotals($record->db, $target['source']['invoice'], $foreign);
        foreach ($target['source']['rates'] as $rate => $x) {
            $d = $doli[$rate] ?? array('net' => 0.0, 'vat' => 0.0);
            $add($rate, max(abs((float) $x['net'] - $d['net']), abs((float) $x['vat'] - $d['vat'])));
        }
    }
    if ($legacy && !empty($record->fa3_xml)) {
        dol_include_once('/ksef/class/fa3_parser.class.php');
        $parser = new FA3Parser($record->db);
        $parsed = $parser->parse($record->fa3_xml);
        foreach (($parsed ? (array) $parsed['lines'] : array()) as $l) {
            $has = isset($l['declared']) ? (array) $l['declared'] : array();
            $rate = is_numeric(trim((string) $l['vat_rate'])) ? (string) (float) trim((string) $l['vat_rate']) : '0';
            $qty = in_array('P_8B', $has, true) ? (float) $l['quantity'] : 1.0;
            if (in_array('P_9A', $has, true) && in_array('P_11', $has, true)) {
                $add($rate, $qty * (float) $l['unit_price_net'] - (float) $l['net_amount']);
            } elseif (in_array('P_9B', $has, true) && in_array('P_11A', $has, true)) {
                $add($rate, $qty * (float) $l['unit_price_gross'] - (float) $l['gross_amount']);
            }
        }
    }
    return $out;
}

/**
 * @brief Settle imported totals
 * @param DoliDB $db
 * @param Facture|FactureFournisseur $facture
 * @param array $target
 * @param bool $foreign Invoice currency
 * @param string $pln xml, carry or none
 * @param bool $dryRun
 * @param array $slack
 * @return array
 */
function ksefSettleImportedTotals($db, $facture, $target, $foreign, $pln = 'xml', $dryRun = false, $slack = array())
{
    $k = ksefInvoiceKind($facture);
    $P = MAIN_DB_PREFIX;
    $out = array('moved' => 0, 'far' => array(), 'error' => '');
    $res = $db->query("SELECT rowid, tva_tx FROM " . $P . $k['line_table'] . " WHERE " . $k['line_fk'] . " = " . (int) $facture->id
        . " AND (fk_remise_except IS NULL OR fk_remise_except = 0) AND product_type < 9 ORDER BY rang, rowid");
    if (!$res) {
        $out['error'] = $db->lasterror();
        return $out;
    }
    $last = $count = array();
    while ($o = $db->fetch_object($res)) {
        $r = (string) (float) $o->tva_tx;
        $last[$r] = (int) $o->rowid;
        $count[$r] = ($count[$r] ?? 0) + 1;
    }
    $have = ksefSupplierInvoiceRateTotals($db, $facture, $foreign);
    $havePln = $foreign ? ksefSupplierInvoiceRateTotals($db, $facture, false) : $have;
    $tx = (float) $facture->multicurrency_tx > 0 ? (float) $facture->multicurrency_tx : 1.0;
    $head = array('ht' => 0.0, 'tva' => 0.0, 'mht' => 0.0, 'mtva' => 0.0);
    $changes = array();
    foreach ((array) $target['rates'] as $r => $x) {
        if (!isset($last[$r])) {
            continue;
        }
        $h = $have[$r] ?? array('net' => 0.0, 'vat' => 0.0);
        $dn = (float) price2num(round((float) $x['net'], 2) - round((float) $h['net'], 2), 'MT');
        $dv = (float) price2num(round((float) $x['vat'], 2) - round((float) $h['vat'], 2), 'MT');
        $tol = 0.01 * $count[$r] + 0.005 + (float) ($slack[$r] ?? 0);
        if (abs($dn) > $tol || abs($dv) > $tol) {
            $out['far'][$r] = array('net' => $dn, 'vat' => $dv);
            continue;
        }
        if (!$foreign) {
            $d = array('ht' => $dn, 'tva' => $dv, 'mht' => $dn, 'mtva' => $dv);
        } else {
            $d = array('ht' => 0.0, 'tva' => 0.0, 'mht' => $dn, 'mtva' => $dv);
            if ($pln !== 'none') {
                $d['ht'] = (float) price2num($dn / $tx, 'MT');
                $d['tva'] = (float) price2num($dv / $tx, 'MT');
                $hp = $havePln[$r] ?? array('net' => 0.0, 'vat' => 0.0);
                if ($pln === 'xml' && isset($x['vat_pln']) && $x['vat_pln'] !== null) {
                    $want = (float) price2num(round((float) $x['vat_pln'], 2) - round((float) $hp['vat'], 2), 'MT');
                    if (abs($want) <= $tol * max(1.0, 1 / $tx) + abs($d['tva'])) {
                        $d['tva'] = $want;
                    } else {
                        $out['far'][$r] = array('net' => 0.0, 'vat' => 0.0, 'vat_pln' => (float) price2num($want - $d['tva'], 'MT'));
                    }
                }
            }
        }
        if (abs($d['ht']) < 0.005 && abs($d['tva']) < 0.005 && abs($d['mht']) < 0.005 && abs($d['mtva']) < 0.005) {
            continue;
        }
        $changes[$last[$r]] = $d;
        foreach ($head as $f => $v) {
            $head[$f] = $v + $d[$f];
        }
        $out['moved']++;
    }
    if ($dryRun || !$changes) {
        return $out;
    }
    $num = function ($v) {
        return (string) price2num((float) $v, 'MT');
    };
    foreach ($changes as $lineId => $d) {
        $sql = "UPDATE " . $P . $k['line_table'] . " SET total_ht = total_ht + " . $num($d['ht']) . ", " . $k['line_tva'] . " = " . $k['line_tva'] . " + " . $num($d['tva'])
            . ", total_ttc = total_ttc + " . $num($d['ht'] + $d['tva']) . ", multicurrency_total_ht = multicurrency_total_ht + " . $num($d['mht'])
            . ", multicurrency_total_tva = multicurrency_total_tva + " . $num($d['mtva']) . ", multicurrency_total_ttc = multicurrency_total_ttc + " . $num($d['mht'] + $d['mtva'])
            . " WHERE rowid = " . (int) $lineId;
        if (!$db->query($sql)) {
            $out['error'] = $db->lasterror();
            return $out;
        }
    }
    $sql = "UPDATE " . $P . $k['table'] . " SET total_ht = total_ht + " . $num($head['ht']) . ", total_tva = total_tva + " . $num($head['tva'])
        . ($k['element'] === 'invoice_supplier' ? ", tva = tva + " . $num($head['tva']) : "")
        . ", total_ttc = total_ttc + " . $num($head['ht'] + $head['tva']) . ", multicurrency_total_ht = multicurrency_total_ht + " . $num($head['mht'])
        . ", multicurrency_total_tva = multicurrency_total_tva + " . $num($head['mtva']) . ", multicurrency_total_ttc = multicurrency_total_ttc + " . $num($head['mht'] + $head['mtva'])
        . " WHERE rowid = " . (int) $facture->id;
    if (!$db->query($sql)) {
        $out['error'] = $db->lasterror();
    }
    return $out;
}

/**
 * @brief Import VAT rounding
 * @param KsefIncoming $incoming
 * @param Facture|FactureFournisseur $facture
 * @return array
 */
function ksefApplyIncomingVatRounding($incoming, $facture)
{
    global $conf;
    $out = array('moved' => false, 'note' => '', 'error' => '');
    $foreign = ksefInvoiceIsForeign($facture);
    $target = ksefImportTargetTotals($incoming, $facture, $foreign);
    if ($target === null) {
        return $out;
    }
    if ($target['korekta']) {
        $out['note'] = ksefKorektaSourceNote($incoming->db, $target, $foreign, $foreign ? $facture->multicurrency_code : $conf->currency);
    }
    $s = ksefSettleImportedTotals($incoming->db, $facture, $target, $foreign, $target['korekta'] ? 'none' : 'xml', false, ksefImportRoundingSlack($incoming, $target, $foreign, false));
    $out['error'] = $s['error'];
    if ($s['moved'] > 0 && $s['error'] === '') {
        $out['moved'] = true;
        $facture->fetch($facture->id);
    }
    return $out;
}

/**
 * @brief Multiline text HTML
 * @param string $text
 * @return string
 */
function ksefHtmlMultiline($text)
{
    return dol_nl2br(dol_escape_htmltag((string) $text, 0, 1));
}

/**
 * @brief Split KSeF address
 * @param string $address
 * @param string $country
 * @return array
 */
function ksefSplitAddress($address, $country = 'PL')
{
    $parts = preg_split('/\r?\n/', trim((string) $address));
    $l1 = trim((string) ($parts[0] ?? ''));
    $l2 = trim(implode(' ', array_slice($parts, 1)));
    if (strtoupper((string) $country) === 'PL' && preg_match('/^(\d{2}-\d{3})\s+(.+)$/u', $l2, $m)) {
        $town = trim(preg_replace('/,\s*(Polska|Poland|PL)\s*$/iu', '', trim($m[2])));
        return array('address' => $l1, 'zip' => $m[1], 'town' => $town);
    }
    return array('address' => trim($l1 . ($l2 !== '' ? ', ' . $l2 : ''), ', '), 'zip' => '', 'town' => '');
}

/**
 * @brief Single-line truncated text
 * @param string $text
 * @param int $max
 * @return string
 */
function ksefSingleLine($text, $max = 255)
{
    $t = trim(preg_replace('/\s+/u', ' ', (string) $text));
    return mb_substr($t, 0, $max, 'UTF-8');
}

/**
 * @brief VAT key rate
 * @param string $key P_12 or summary key
 * @return float|string
 */
function ksefVatKeyToDolibarr($key)
{
    $k = trim((string) $key);
    if ($k === '' || is_numeric($k)) {
        return (float) $k;
    }
    $map = array('zw' => 'ZW', 'np i' => 'NP', 'np' => 'NP', 'np ii' => 'NP2', 'oo' => 'RC', '0 wdt' => 'WDT', '0 ex' => 'EX');
    $low = strtolower($k);
    if (isset($map[$low])) {
        return '0 (' . $map[$low] . ')';
    }
    return (float) preg_replace('/[^0-9.]/', '', $k);
}

/**
 * @brief Invoice sale date
 * @param Facture $invoice
 * @return int
 */
function ksefGetSaleDate($invoice)
{
    $v = isset($invoice->array_options['options_ksef_sale_date']) ? $invoice->array_options['options_ksef_sale_date'] : '';
    if (!empty($v)) {
        return is_numeric($v) ? (int) $v : (int) strtotime((string) $v);
    }
    return (int) $invoice->date;
}

/**
 * @brief Keep sale date
 * @param Facture $invoice
 * @param int|null $now
 * @return bool
 */
function ksefCanKeepSaleDate($invoice, $now = null)
{
    if ((int) $invoice->statut !== Facture::STATUS_DRAFT || empty($invoice->date)) {
        return false;
    }
    if (!empty($invoice->array_options['options_ksef_sale_date'])) {
        return false;
    }
    return dol_print_date((int) $invoice->date, '%Y-%m-%d', 'tzserver') !== ksefWarsawToday($now);
}

/**
 * @brief Redate draft today
 * @param Facture $invoice
 * @param User $user
 * @param bool $keepSaleDate
 * @param int|null $now
 * @return array
 */
function ksefRedateDraftToday($invoice, $user, $keepSaleDate, $now = null)
{
    if (empty($invoice->array_options)) {
        $invoice->fetch_optionals();
    }
    $kept = 0;
    if ($keepSaleDate && ksefCanKeepSaleDate($invoice, $now)) {
        $kept = (int) $invoice->date;
        $invoice->array_options['options_ksef_sale_date'] = $kept;
    }
    // Warsaw day
    list($y, $m, $d) = array_map('intval', explode('-', ksefWarsawToday($now)));
    $invoice->date = dol_mktime(12, 0, 0, $m, $d, $y, 'tzserver');
    $invoice->date_lim_reglement = $invoice->calculate_date_lim_reglement();
    if ($invoice->update($user) <= 0) {
        return array('ok' => false, 'error' => $invoice->error, 'errors' => $invoice->errors, 'kept' => 0);
    }
    return array('ok' => true, 'error' => '', 'errors' => array(), 'kept' => $kept);
}

/**
 * @brief Is EU country
 * @param string $countryCode
 * @return bool
 */
function ksefIsEuCountry($countryCode)
{
    $eu = array(
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
        'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
        'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE'
    );
    return in_array(strtoupper((string) $countryCode), $eu, true);
}

/**
 * @brief Zero rate key
 * @param string $code vat_src_code
 * @param string $countryCode Buyer country
 * @param bool $isEu
 * @param bool $isService
 * @return string
 */
function ksefZeroRateKey($code, $countryCode, $isEu, $isService = false)
{
    $code = strtoupper(trim((string) $code));
    if ($code !== '') {
        if ($code === 'ZW') return 'zw';
        if ($code === 'RC' || $code === 'OO') return 'oo';
        if ($code === 'NP2' || $code === 'NPII' || $code === 'NP II') return 'np II';
        if ($code === 'NP' || $code === 'NP1' || $code === 'NPI' || $code === 'NP I') return 'np I';
        if ($code === 'WDT') return '0 WDT';
        if ($code === 'EX') return '0 EX';

        if (strpos($code, 'ZW') !== false) return 'zw';
        if (strpos($code, 'RC') !== false || strpos($code, 'OO') !== false) return 'oo';
        if (strpos($code, 'WDT') !== false) return '0 WDT';
        if (strpos($code, 'EX') !== false) return '0 EX';
        if (strpos($code, 'NP2') !== false || strpos($code, 'NPII') !== false) return 'np II';
        if (strpos($code, 'NP') !== false) return 'np I';
    }
    if ((string) $countryCode !== '') {
        if ($countryCode === 'PL') {
            return '0 KR';
        }
        if ($isService) {
            return $isEu ? 'np II' : 'np I';
        }
        return $isEu ? '0 WDT' : '0 EX';
    }
    return '0 KR';
}

/**
 * @brief WDT-only invoice
 * @param DoliDB $db
 * @param Facture $invoice
 * @return bool
 */
function ksefInvoiceIsWdtOnly($db, $invoice)
{
    if (empty($invoice->lines) && !empty($invoice->id)) {
        $invoice->fetch_lines();
    }
    $soc = (!empty($invoice->thirdparty) && (int) $invoice->thirdparty->id === (int) $invoice->socid) ? $invoice->thirdparty : null;
    if ($soc === null) {
        require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
        $soc = new Societe($db);
        if (empty($invoice->socid) || $soc->fetch((int) $invoice->socid) <= 0) {
            return false;
        }
    }
    $country = ksefInferCountryCode($soc);
    $isEu = ksefIsEuCountry($country);
    $priced = 0;
    foreach ((array) $invoice->lines as $line) {
        if (abs((float) $line->total_ht) < 0.005 && abs((float) ($line->multicurrency_total_ht ?? 0)) < 0.005) {
            continue;
        }
        if (ksefMarginSubtype($line) !== '' || (int) round((float) $line->tva_tx) !== 0
            || ksefZeroRateKey($line->vat_src_code ?? '', $country, $isEu, (int) ($line->product_type ?? 0) === 1) !== '0 WDT') {
            return false;
        }
        $priced++;
    }
    return $priced > 0;
}

/**
 * @brief WDT tax point
 * @param int $delivery Delivery date
 * @return int 0 if no date
 */
function ksefWdtTaxPointCap($delivery)
{
    if (empty($delivery)) {
        return 0;
    }
    $y = (int) dol_print_date($delivery, '%Y', 'tzserver');
    $m = (int) dol_print_date($delivery, '%m', 'tzserver') + 1;
    if ($m > 12) {
        $m = 1;
        $y++;
    }
    return (int) dol_mktime(0, 0, 0, $m, 15, $y, 'tzserver');
}

/**
 * @brief Environment SQL filter
 * @param DoliDB $db
 * @param string $alias Alias with dot
 * @return string
 */
function ksefEnvironmentSql($db, $alias = '')
{
    return " AND (" . $alias . "environment = '" . $db->escape(ksefGetEnvironment()) . "' OR " . $alias . "environment IS NULL OR " . $alias . "environment = '')";
}

/**
 * @brief Latest sent XML
 * @param DoliDB $db
 * @param array $ids
 * @return string
 */
function ksefLatestSentXml($db, $ids)
{
    $ids = array_filter(array_map('intval', (array) $ids));
    if (!$ids) {
        return '';
    }
    $res = $db->query("SELECT fa3_xml FROM " . MAIN_DB_PREFIX . "ksef_submissions WHERE fk_facture IN (" . implode(',', $ids) . ")"
        . " AND (status = 'ACCEPTED' OR status = 'OFFLINE') AND fa3_xml IS NOT NULL" . ksefEnvironmentSql($db) . " ORDER BY rowid DESC LIMIT 1");
    $o = $res ? $db->fetch_object($res) : null;
    return $o ? (string) $o->fa3_xml : '';
}

/**
 * @brief KOR_ZAL rate refusal
 * @param DoliDB $db
 * @param Facture $invoice
 * @param array $ctx Deposit context
 * @return string
 */
function ksefKorZalRateRefusal($db, $invoice, $ctx)
{
    global $langs;
    if (($ctx['type'] ?? '') !== 'KOR_ZAL' || empty($ctx['root_id']) || ksefGetInvoiceCurrency($invoice) === 'PLN' || (float) $invoice->multicurrency_tx <= 0) {
        return '';
    }
    $res = $db->query("SELECT ref, multicurrency_tx FROM " . MAIN_DB_PREFIX . "facture WHERE rowid = " . (int) $ctx['root_id']);
    $o = $res ? $db->fetch_object($res) : null;
    if (!$o || (float) $o->multicurrency_tx <= 0) {
        return '';
    }
    $depRate = number_format(1 / (float) $o->multicurrency_tx, 6, '.', '');
    $ownRate = number_format(1 / (float) $invoice->multicurrency_tx, 6, '.', '');
    if ($depRate === $ownRate) {
        return '';
    }
    $langs->load('ksef@ksef');
    return $langs->trans('KSEF_KorZalRateMismatch', $o->ref, $depRate, $ownRate, price2num((float) $o->multicurrency_tx));
}

/**
 * @brief Warsaw today
 * @param int|null $now
 * @return string Y-m-d
 */
function ksefWarsawToday($now = null)
{
    $d = new DateTime('@' . ($now === null ? dol_now() : (int) $now));
    $d->setTimezone(new DateTimeZone('Europe/Warsaw'));
    return $d->format('Y-m-d');
}

/**
 * @brief Payment moment day
 * @param int $ts
 * @return string Y-m-d
 */
function ksefMomentDay($ts)
{
    $ts = (int) $ts;
    if ($ts <= 0) {
        return '';
    }
    $day = date('Y-m-d', $ts);
    if ($ts === (int) strtotime($day)) {
        return $day;
    }
    return ksefWarsawToday($ts);
}

/**
 * @brief Server zone Warsaw
 * @param string|null $tz Zone name
 * @return bool
 */
function ksefServerTimezoneIsWarsaw($tz = null)
{
    $tz = $tz === null ? date_default_timezone_get() : (string) $tz;
    return in_array($tz, array('Europe/Warsaw', 'Poland'), true);
}

/**
 * @brief Exempt basis missing
 * @param DoliDB $db
 * @param Facture $object Invoice
 * @return bool
 */
function ksefExemptBasisMissing($db, $object)
{
    if (empty($object->id) || ($object->element ?? '') !== 'facture') {
        return false;
    }
    if (empty($object->lines)) {
        $object->fetch_lines();
    }
    $zw = false;
    foreach ((array) $object->lines as $l) {
        if ((int) round((float) $l->tva_tx) === 0 && strpos(strtoupper((string) ($l->vat_src_code ?? '')), 'ZW') !== false) {
            $zw = true;
            break;
        }
    }
    if (!$zw) {
        return false;
    }
    dol_include_once('/ksef/class/fa3_builder.class.php');
    $builder = new FA3Builder($db);
    list($has, $basis) = $builder->exemptionBasis($object);
    return $has && $basis === '';
}

/**
 * @brief Non-Polish line rates
 * @param array $lines
 * @return array
 */
function ksefNonPolishRates($lines)
{
    $out = array();
    foreach ((array) $lines as $l) {
        $x = (float) $l->tva_tx;
        if ((int) $l->product_type !== 9 && $x > 0 && ksefMarginSubtype($l) === '' && (abs($x - round($x)) > 0.001 || ksefPolishRateKey($x) === '')) {
            $p = price2num($x);
            $out[$p] = vatrate((string) $p);
        }
    }
    return $out;
}

/**
 * @brief Send preflight checks
 * @param DoliDB $db
 * @param Facture $object Invoice
 * @param string $context Check context
 * @param bool $acceptStoredRate
 * @param bool $depositsAcknowledged
 * @return array
 */
function ksefPreflightChecks($db, $object, $context, $acceptStoredRate = false, $depositsAcknowledged = false)
{
    global $langs;
    $langs->load("ksef@ksef");
    dol_include_once('/ksef/class/ksef_nbp_currency_rate.class.php');
    if (empty($object->array_options)) {
        $object->fetch_optionals();
    }
    $cert = ksefIsOfflineCertificateConfigured();
    $out = array('ok' => true, 'code' => '', 'level' => '', 'message' => '', 'rate' => array(),
        'backdated' => ksefDetectBackdating($object->date), 'offline_cert' => !empty($cert['valid']), 'warnings' => array());
    $fail = function ($code, $level, $message) use (&$out) {
        $out['ok'] = false;
        $out['code'] = $code;
        $out['level'] = $level;
        $out['message'] = $message;
        return $out;
    };
    $isValidate = in_array($context, array('validate_preview', 'validate_submit'), true);
    if (!$isValidate && !in_array((int) $object->statut, array(Facture::STATUS_VALIDATED, Facture::STATUS_CLOSED), true)) {
        return $fail('not_validated', 'error', $langs->trans('KSEF_InvoiceMustBeValidated'));
    }
    if (!ksefRequirementRow($db, 'main_currency')['met']) {
        return $fail('main_currency_not_pln', 'error', dol_escape_htmltag($langs->transnoentitiesnoconv('KSEF_MainCurrencyNotPln', $GLOBALS['conf']->currency)) . ' ' . ksefMainCurrencyFixHtml());
    }
    $company = ksefRequirementSendRefusal($db);
    if ($company) {
        return $fail($company['code'], 'error', $company['message']);
    }
    if (!isModEnabled('multicurrency') && ksefInvoiceCurrencyStray($object) !== '') {
        return $fail('currency_no_multicurrency', 'error', dol_escape_htmltag($langs->transnoentitiesnoconv('KSEF_CurrencyNoMulticurrency', ksefInvoiceCurrencyStray($object))));
    }
    // keep order: checkInvoiceSubmittable()
    $prodRow = ksefProductionIssuedBlock($db, (int) $object->id);
    if ($prodRow) {
        return $fail('issued_in_production', 'error', dol_escape_htmltag(ksefProductionIssuedRefusal($prodRow)));
    }
    if (ksefIsCustomerExcluded($object->socid)) {
        return $fail('excluded', 'warning', $langs->trans('KSEF_CustomerExcluded'));
    }
    if (ksefCorrectionMissingSource($object) && !ksefSubmissionState($db, (int) $object->id)['is_offline']) {
        return $fail('correction_no_source', 'error', $langs->trans('KSEF_CorrectionNoSource'));
    }
    $otherEnv = ksefCorrectionOtherEnvRefusal($db, $object);
    if ($otherEnv !== '' && !ksefSubmissionState($db, (int) $object->id)['is_offline']) {
        return $fail('correction_source_other_env', 'error', dol_escape_htmltag($otherEnv));
    }
    $corr = ksefCorrectionSourceRef($db, $object);
    if ($corr['state'] === 'rejected') {
        return $fail('correction_source_rejected', 'error', ksefCorrectionSourceRefusal($corr));
    }
    // art. 106nda(12), 106nf(11)
    if ($corr['state'] === 'awaiting_number') {
        return $fail('correction_source_awaiting_number', 'error', ksefCorrectionAwaitingRefusal($corr));
    }
    $early = ksefCorrectionNumberDayCheck($db, $object, $corr);
    if ($early) {
        return $fail('correction_before_source_number', 'error', ksefCorrectionNumberDayRefusal($early));
    }
    if ($corr['state'] === 'none' && !ksefIsCustomerExcluded($object->socid)) {
        $srcProd = ksefProductionIssuedBlock($db, (int) $corr['source_id']);
        if ($srcProd) {
            $prodKey = 'KSEF_CorrectionOriginalProductionOnly';
            if (!ksefIsRealKsefNumber($srcProd['ksef_number'])) {
                $prodLatest = new KsefSubmission($db);
                $prodRejected = $prodLatest->fetchByInvoice((int) $corr['source_id'], 'PRODUCTION') > 0 && in_array($prodLatest->status, array('FAILED', 'REJECTED'), true);
                $prodKey = $prodRejected ? 'KSEF_CorrectionOriginalProductionRejected' : 'KSEF_CorrectionOriginalProductionOffline';
            }
            $out['warnings'][] = $langs->transnoentitiesnoconv($prodKey, dol_escape_htmltag($corr['source_ref']), ksefEnvironmentLabel(ksefGetEnvironment()));
        } else {
            $out['warnings'][] = $langs->transnoentitiesnoconv('KSEF_CorrectionOriginalNoKsef');
        }
    }
    $earlier = $context === 'validate_preview' ? null : ksefEarlierCorrectionUnsent($db, $object);
    if ($earlier) {
        return $fail('earlier_correction_unsent', 'error', ksefEarlierCorrectionRefusal($earlier));
    }
    $ctx = ksefDepositContext($db, $object);
    $rateRefusal = ($isValidate && (int) $object->statut === Facture::STATUS_DRAFT) ? '' : ksefKorZalRateRefusal($db, $object, $ctx);
    if ($rateRefusal !== '') {
        return $fail('korzal_rate_mismatch', 'error', $rateRefusal);
    }
    $blockCode = ksefDepositBlockCode($ctx);
    if ($blockCode !== '') {
        $codes = array('currency_mismatch' => 'deposit_currency_mismatch', 'below_zero' => 'settlement_below_zero');
        return $fail($codes[$blockCode] ?? 'deposit_not_in_ksef', 'error', ksefDepositBlockHtml($db, $object, $ctx));
    }
    if (!$depositsAcknowledged && ksefDepositAckMessage($db, $object, $ctx) !== '') {
        return $fail('deposits_ack', 'warning', ksefDepositAckMessage($db, $object, $ctx, true));
    }
    // art. 106f(1)(3)
    $vatMismatch = ksefDepositVatMismatch($object, $ctx);
    if ($vatMismatch !== '') {
        return $fail('deposit_vat_mismatch', 'error', $vatMismatch);
    }
    if (in_array($ctx['type'], array('ROZ', 'KOR_ROZ'), true) && (int) $object->statut !== Facture::STATUS_DRAFT) {
        $bad = ksefSettlementDepositMismatch($db, (int) $object->id);
        if ($bad) {
            $b = reset($bad);
            return $fail('deposit_value_mismatch', 'error', $langs->trans('KSEF_DepositDeductionMismatch', dol_escape_htmltag($b['ref']),
                price($b['deducted'], 0, $langs, 1, -1, -1, $b['currency']), price($b['current'], 0, $langs, 1, -1, -1, $b['currency'])));
        }
    }
    if (ksefExemptBasisMissing($db, $object)) {
        $out['warnings'][] = $langs->transnoentitiesnoconv('KSEF_ExemptNoBasis');
    }
    $lineVat = !empty($object->id) && !ksefInvoiceSentToKsef($db, (int) $object->id) ? ksefLineVatOff($db, (int) $object->id) : array();
    if ($lineVat) {
        $out['warnings'][] = ksefLineVatSendWarning($db, $object, $lineVat);
    }
    $usedElsewhere = ksefOrderDepositUsedElsewhereText($db, ksefOrderDepositUsedElsewhere($db, (int) $object->id));
    if ($usedElsewhere !== '') {
        $out['warnings'][] = dol_escape_htmltag($usedElsewhere);
    }
    if (in_array($ctx['type'], array('ZAL', 'KOR_ZAL'), true) && empty($ctx['order'])) {
        $out['warnings'][] = $langs->transnoentitiesnoconv('KSEF_DepositNoOrderWarning');
    } elseif ($ctx['type'] === 'ZAL' && !ksefOrderRowLines($ctx['order'])) {
        // XSD: needs ZamowienieWiersz
        $out['warnings'][] = $langs->transnoentitiesnoconv('KSEF_DepositOrderNoLinesWarning');
    }
    // XSD: max 31
    if (in_array($ctx['type'], array('ZAL', 'KOR_ZAL'), true) && !empty($object->id)) {
        $partsRoot = (int) $object->type === Facture::TYPE_DEPOSIT ? $object : ksefCorrectionRoot($db, $object);
        $partsCount = 0;
        if ($partsRoot && (int) $partsRoot->type === Facture::TYPE_DEPOSIT) {
            $partsCount = count((int) $object->type === Facture::TYPE_DEPOSIT ? ksefDepositPaymentParts($db, $partsRoot, array('no_rates' => true))
                : ksefDepositKorParts($db, $partsRoot, array('no_rates' => true)));
            $plan = (int) $object->type === Facture::TYPE_DEPOSIT ? null : ksefDepositKorPlan($db, $object, array('cached_only' => true));
            if ($plan !== null) {
                $partsCount = count($plan['parts']);
            }
        }
        if ($partsCount > 31) {
            return $fail('deposit_parts_max', 'error', $langs->trans('KSEF_DepositPartsMax', dol_escape_htmltag($partsRoot->ref), $partsCount));
        }
    }
    // XSD: max 100
    $cited = $ctx['type'] === 'ROZ' ? $ctx['deposits'] : (($ctx['type'] === 'ZAL' && !empty($ctx['last'])) ? $ctx['earlier'] : array());
    if (count($cited) > 100) {
        $out['warnings'][] = $langs->transnoentitiesnoconv('KSEF_DepositCitesMax', count($cited));
    }
    $nbp = new KsefNbpCurrencyRate($db);
    if ($nbp->invoiceNeedsNBPRate($object) && $nbp->invoiceHasNBPRate($object)) {
        $linePrep = ksefLineKursSendPrepare($db, $object, 'trans');
        if (!$linePrep['ok']) {
            return $fail($linePrep['code'], 'error', $linePrep['message']);
        }
        if ($linePrep['notice'] !== '') {
            $out['warnings'][] = $linePrep['notice'];
        }
        foreach (ksefTypedRateSendWarnings($db, $object, 'trans') as $w) {
            $out['warnings'][] = $w;
        }
    }
    // art. 31b(1)
    if ($nbp->invoiceNeedsNBPRate($object) && !empty($object->id)
        && in_array((int) $object->type, array(Facture::TYPE_REPLACEMENT, Facture::TYPE_CREDIT_NOTE), true)) {
        $lineMap = ksefLineKursMap($db, $object);
        if ($lineMap['missing']) {
            if (empty($object->lines)) {
                $object->fetch_lines();
            }
            $label = '#' . (int) $lineMap['missing'][0];
            foreach ((array) $object->lines as $l) {
                if ((int) $l->id === (int) $lineMap['missing'][0]) {
                    $label = dol_trunc(trim(dol_string_nohtmltag((string) (!empty($l->product_label) ? $l->product_label : ($l->desc ?? '')))), 60);
                }
            }
            return $fail('line_rate_missing', 'error', ksefTransHtml('KSEF_LineRateMissing', array(ksefEscapeText($label))));
        }
        $root = (int) $object->fk_facture_source > 0 ? ksefCorrectionRoot($db, $object) : null;
        if ($root && (int) $root->type === Facture::TYPE_DEPOSIT) {
            $parts = ksefDepositKorParts($db, $root, array('cached_only' => true));
            if (array_filter($parts, function ($pp) {
                return (float) $pp['kurs'] <= 0;
            })) {
                $parts = ksefDepositKorParts($db, $root);
            }
            $plan = ksefDepositKorPlan($db, $object);
            if ($plan !== null) {
                $parts = $plan['parts'];
            }
            foreach ($parts as $pp) {
                if ((float) $pp['kurs'] <= 0) {
                    return $fail('nbp_missing', 'error', $langs->trans('KSEF_NBPRateRequired'));
                }
            }
        }
    }
    if ($nbp->invoiceNeedsNBPRate($object) && !$nbp->invoiceHasNBPRate($object)) {
        return $fail('nbp_missing', 'error', $langs->trans('KSEF_NBPRateRequired'));
    }
    $rate = $nbp->checkStoredRate($object);
    $out['rate'] = $rate;
    if ($rate['stale']) {
        if ($rate['strict']) {
            $refusal = $nbp->rateFetchRefusal($object);
            if (!empty($refusal['parts'])) {
                return $fail('nbp_stale', 'error', $nbp->rateChangeBookedMessage('KSEF_NBPRateStaleBooked', $object, $refusal['parts'], array($rate['stored_label'], $rate['reference_label']), 'trans'));
            }
            return $fail('nbp_stale', 'error', $langs->trans('KSEF_NBPRateStale', $rate['stored_label'], $rate['reference_label']));
        }
        if (!$acceptStoredRate) {
            return $fail('nbp_stale_choice', 'warning', $langs->trans('KSEF_NBPRateStaleChoice', $rate['stored_label'], $rate['reference_label']));
        }
        dol_syslog('KSEF preflight: invoice ' . (int) $object->id . ' sent with the stored NBP rate of ' . $rate['stored']
            . ' (reference date ' . $rate['reference'] . '), chosen by the user', LOG_WARNING);
    }
    $typedRate = $nbp->checkCorrectionRate($object);
    if ($typedRate['state'] === 'other') {
        $out['warnings'][] = $nbp->correctionRateWarning($typedRate, (int) $object->statut !== Facture::STATUS_DRAFT);
    }
    if ($nbp->invoiceNeedsNBPRate($object) && !empty($object->id)) {
        $plnVat = ksefForeignPlnVatCheck($db, $object);
        if ($plnVat['level'] === 'error') {
            return $fail('foreign_pln_vat', 'error', $plnVat['message']);
        }
        if ($plnVat['level'] === 'warning') {
            $out['warnings'][] = $plnVat['message'];
        }
    }
    // XSD: Polish rates
    if (empty($object->lines) && !empty($object->id)) {
        $object->fetch_lines();
    }
    $foreignRates = ksefNonPolishRates($object->lines);
    if ($foreignRates) {
        return $fail('vat_rate_not_polish', 'error', $langs->transnoentitiesnoconv('KSEF_VatRateNotPolish', implode(', ', $foreignRates)));
    }
    foreach (ksefLongPhoneParties($db, $object) as $name) {
        $out['warnings'][] = $langs->transnoentitiesnoconv('KSEF_PhoneTooLongLeftOut', dol_escape_htmltag($name));
    }
    foreach (ksefGvJstWarnings($db, $object) as $w) {
        $out['warnings'][] = $w;
    }
    if (!empty($object->id) && in_array((int) $object->statut, array(Facture::STATUS_VALIDATED, Facture::STATUS_CLOSED), true)) {
        dol_include_once('/ksef/class/fa3_builder.class.php');
        $builder = new FA3Builder($db);
        $xml = $builder->buildFromInvoice((int) $object->id, in_array($context, array('offline_create', 'offline_confirmed'), true) ? array('offline_mode' => true) : array());
        $xsd = $xml === false ? null : ksefFa3SchemaRefusal($db, $object, $xml);
        if ($xsd) {
            return $fail('xsd_invalid', 'error', $xsd['html']);
        }
    }
    return $out;
}

/**
 * @brief Currency without module
 * @param Facture $object
 * @return string Invoice currency or ''
 */
function ksefInvoiceCurrencyStray($object)
{
    global $conf;
    $cur = strtoupper((string) ($object->multicurrency_code ?? ''));
    return ($cur !== '' && $cur !== (string) $conf->currency && $cur !== 'PLN') ? $cur : '';
}

/**
 * @brief Line VAT warning
 * @param DoliDB $db
 * @param Facture $object
 * @param array $lines ksefLineVatOff() result
 * @return string HTML
 */
function ksefLineVatSendWarning($db, $object, $lines)
{
    global $langs, $user;
    $items = array_map('ksefLineVatItem', array_values($lines));
    $html = dol_escape_htmltag($langs->transnoentitiesnoconv('KSEF_LineVatSendWarning', implode('; ', $items)));
    if (is_object($user) && $user->hasRight('facture', 'creer') && in_array((int) $object->statut, array(Facture::STATUS_DRAFT, Facture::STATUS_VALIDATED), true)
        && !((int) $object->statut === Facture::STATUS_VALIDATED && ksefInvoiceInAccounting($db, (int) $object->id))) {
        $html .= ' <a class="ksef-line-vat-fix" href="' . DOL_URL_ROOT . '/compta/facture/card.php?facid=' . (int) $object->id . '&action=ksef_note_ask&note='
            . urlencode('line_vat:' . (int) $object->id) . '&token=' . newToken() . '">' . dol_escape_htmltag($langs->transnoentitiesnoconv('KSEF_LineVatFixLink')) . '</a>';
    }
    return $html;
}

/**
 * @brief Failed send kind
 * @param int|string|null $code KSeF failure code
 * @return string rejected|transport
 */
function ksefFailureKind($code)
{
    if ($code === null || $code === '' || !is_numeric($code)) {
        return 'transport';
    }
    $hint = ksefRetryHint($code);
    if ($hint === 'retry') {
        return 'transport';
    }
    if ($hint !== '') {
        return 'rejected';
    }
    $code = (int) $code;
    if ($code === 429 || ($code >= 401 && $code <= 403) || ($code >= 500 && $code <= 599)) {
        return 'transport';
    }
    return 'rejected';
}

/**
 * @brief Submission failure kind
 * @param KsefSubmission|object $sub Submission row
 * @return string
 */
function ksefRowFailureKind($sub)
{
    global $langs;
    if (!empty($sub->failure_kind)) {
        return (string) $sub->failure_kind;
    }
    if (($sub->status ?? '') === 'REJECTED') {
        return 'rejected';
    }
    if (!empty($sub->ksef_reference)) {
        return 'unknown';
    }
    $code = $sub->error_code ?? null;
    if ($code === null || $code === '' || !is_numeric($code)) {
        return 'unknown';
    }
    return ksefFailureKind($code);
}

/**
 * @brief Offline issue refusal
 * @param KsefSubmission|null $sub Latest submission
 * @param Facture|null $invoice
 * @param bool $checkCert Require offline cert
 * @return string '' when allowed
 */
function ksefOfflineIssueRefusal($sub, $invoice = null, $checkCert = true)
{
    if ($checkCert) {
        $cert = ksefIsOfflineCertificateConfigured();
        if (empty($cert['valid'])) {
            return 'cert_missing';
        }
    }
    if (!is_object($sub) || empty($sub->rowid)) {
        return '';
    }
    $status = (string) $sub->status;
    if ($status === 'ACCEPTED' || $status === 'OFFLINE' || (!empty($sub->offline_mode) && $status !== 'FAILED')) {
        return 'issued';
    }
    if (in_array($status, array('PENDING', 'SUBMITTED'), true) && !(method_exists($sub, 'isStale') && $sub->isStale())) {
        return 'in_flight';
    }
    if ($status === 'TIMEOUT' || in_array($status, array('PENDING', 'SUBMITTED'), true)) {
        return 'outcome_unknown';
    }
    $kind = ksefRowFailureKind($sub);
    if ($kind === 'rejected') {
        return 'rejected';
    }
    if (!empty($sub->ksef_reference)) {
        return 'uploaded';
    }
    if ($kind === 'transport') {
        return '';
    }
    if ($kind === 'unknown') {
        return 'outcome_unknown';
    }
    return 'local';
}

/**
 * @brief Offline refusal text
 * @param string $code Refusal code
 * @return string
 */
function ksefOfflineRefusalText($code)
{
    global $langs;
    $langs->load("ksef@ksef");
    if ($code === 'cert_missing') {
        return ksefOfflineCertRefusalText();
    }
    $keys = array('issued' => 'KSEF_OfflineRefusedIssued',
        'in_flight' => 'KSEF_SubmissionInProgress', 'uploaded' => 'KSEF_OfflineRefusedUploaded',
        'outcome_unknown' => 'KSEF_OfflineRefusedUnknown', 'rejected' => 'KSEF_OfflineNotForRejected', 'local' => 'KSEF_OfflineRefusedLocal');
    return isset($keys[$code]) ? $langs->transnoentitiesnoconv($keys[$code]) : '';
}

/**
 * @brief Submission display status
 * @param KsefSubmission $sub Submission row
 * @return string
 */
function ksefDisplayStatus($sub)
{
    if (!is_object($sub)) {
        return '';
    }
    if ((string) $sub->status === 'FAILED' && !empty($sub->error_details)) {
        $d = json_decode((string) $sub->error_details, true);
        $reason = is_array($d) ? (string) ($d['reason'] ?? '') : '';
        if ($reason === 'NOT_RECEIVED') {
            return 'NOT_FOUND';
        }
        if ($reason === 'REPLACED') {
            return 'REPLACED';
        }
    }
    $stop = ksefHashStopState($sub);
    if ($stop !== '') {
        return $stop;
    }
    $interrupted =empty($sub->offline_mode) && empty($sub->ksef_reference);
    if (method_exists($sub, 'isStale')) {
        $unknown = $sub->isStale() || (string) $sub->status === 'TIMEOUT';
        return $unknown ? ($interrupted ? 'INTERRUPTED' : 'TIMEOUT') : (string) $sub->status;
    }
    if ((string) $sub->status === 'TIMEOUT' || (in_array((string) $sub->status, array('PENDING', 'SUBMITTED'), true) && empty($sub->offline_mode)
        && !empty($sub->date_submission) && dol_now() - (int) $sub->date_submission > ksefSendStaleAfter())) {
        return $interrupted ? 'INTERRUPTED' : 'TIMEOUT';
    }
    // sync: KsefSubmission::isStale()
    if ((string) $sub->status === 'PENDING' && !empty($sub->offline_mode)) {
        $since = max((int) ($sub->date_submission ?? 0), (int) ($sub->date_last_check ?? 0));
        if ($since > 0 && dol_now() - $since > ksefSendStaleAfter()) {
            return 'TIMEOUT';
        }
    }
    return (string) $sub->status;
}

/**
 * @brief Hash-stopped send state
 * @param object $sub Submission row
 * @return string
 */
function ksefHashStopState($sub)
{
    if (!is_object($sub) || (string) ($sub->status ?? '') !== 'PENDING' || !empty($sub->offline_mode) || empty($sub->error_details)) {
        return '';
    }
    $d = json_decode((string) $sub->error_details, true);
    if (!is_array($d)) {
        return '';
    }
    if (isset($d['held']) && is_array($d['held']) && (string) ($d['held']['reason'] ?? '') === 'duplicate') {
        return 'PROBABLY_IN_KSEF';
    }
    $n = (isset($d['hash_search']) && is_array($d['hash_search'])) ? $d['hash_search'] : array();
    if (!empty($n['final'])) {
        if ((string) ($n['outcome'] ?? '') === 'in_sales') {
            return 'IN_SALES_UNLINKED';
        }
        if ((string) ($n['outcome'] ?? '') === 'ambiguous') {
            return 'AMBIGUOUS';
        }
    }
    return '';
}

/**
 * @brief Sales row
 * @param DoliDB $db
 * @param object $sub Submission row
 * @return array|null
 */
function ksefHashStopSalesRow($db, $sub)
{
    if (!is_object($sub) || empty($sub->invoice_hash)) {
        return null;
    }
    $env = (string) (!empty($sub->environment) ? $sub->environment : ksefGetEnvironment());
    $res = $db->query("SELECT rowid, ksef_number FROM " . MAIN_DB_PREFIX . "ksef_sales WHERE invoice_hash = '" . $db->escape((string) $sub->invoice_hash) . "'"
        . " AND environment = '" . $db->escape($env) . "' AND entity IN (" . getEntity('invoice') . ") ORDER BY rowid ASC" . $db->plimit(1));
    $o = $res ? $db->fetch_object($res) : null;
    return $o ? array('id' => (int) $o->rowid, 'ksef_number' => (string) $o->ksef_number) : null;
}

/**
 * @brief Hash-stop hint text
 * @param DoliDB $db
 * @param object $sub Submission row
 * @return string Unescaped text
 */
function ksefHashStopHint($db, $sub)
{
    global $langs;
    $langs->load("ksef@ksef");
    $state = ksefHashStopState($sub);
    if ($state === '') {
        return '';
    }
    $d = json_decode((string) $sub->error_details, true);
    if ($state === 'PROBABLY_IN_KSEF') {
        $num = (string) ($d['held']['ksef_number'] ?? '');
        return $num !== '' ? $langs->transnoentitiesnoconv('KSEF_HeldByDuplicate', $num) : $langs->transnoentitiesnoconv('KSEF_HeldByDuplicateNoNumber');
    }
    if ($state === 'AMBIGUOUS') {
        return $langs->transnoentitiesnoconv('KSEF_HashStopAmbiguous', max(2, (int) ($d['hash_search']['matches'] ?? 0)));
    }
    $sales = ksefHashStopSalesRow($db, $sub);
    return ($sales && $sales['ksef_number'] !== '') ? $langs->transnoentitiesnoconv('KSEF_HashStopInSales', $sales['ksef_number'])
        : $langs->transnoentitiesnoconv('KSEF_HashStopInSalesNoRow');
}

/**
 * @brief Hash-stop card note
 * @param DoliDB $db
 * @param object $sub Submission row
 * @return string HTML
 */
function ksefHashStopNoteHtml($db, $sub)
{
    global $langs;
    $text = ksefHashStopHint($db, $sub);
    if ($text === '') {
        return '';
    }
    $html = '<br><small class="ksef-hash-stop-hint ' . ksefToneTextClass(ksefOutgoingStatusTone(ksefHashStopState($sub))) . '">' . ksefEscapeText($text);
    if (ksefHashStopState($sub) === 'IN_SALES_UNLINKED') {
        $sales = ksefHashStopSalesRow($db, $sub);
        if ($sales) {
            $html .= ' <a class="ksef-hash-stop-sales" href="' . dol_buildpath('/ksef/sales_card.php', 1) . '?id=' . (int) $sales['id'] . '">'
                . img_object('', 'bill', 'class="pictofixedwidth"') . $langs->trans('KSEF_HashStopOpenSales') . '</a>';
        }
    }
    return $html . '</small>';
}

/**
 * @brief Unknown-outcome hint
 * @param KsefSubmission $sub Submission row
 * @return string
 */
function ksefTimeoutHint($sub)
{
    global $langs;
    $langs->load("ksef@ksef");
    return empty($sub->ksef_reference) ? $langs->trans('KSEF_TimeoutNoRefHint') : $langs->trans('KSEF_TimeoutHint');
}

/**
 * @brief Unadopted 440 hint
 * @param string $reason
 * @return string Unescaped text
 */
function ksefDuplicateNextStep($reason)
{
    global $langs;
    $langs->load("ksef@ksef");
    $keys = array(
        'not_found' => 'KSEF_DupNextNotFound',
        'hash_mismatch' => 'KSEF_DupNextNotOurs',
        'fields' => 'KSEF_DupNextNotOurs',
        'no_permission' => 'KSEF_DupNextNoPermission',
        'gateway' => 'KSEF_DupNextGateway',
        'no_original' => 'KSEF_DupNextNoOriginal',
    );
    return $langs->transnoentitiesnoconv(isset($keys[$reason]) ? $keys[$reason] : 'KSEF_DupNextLink');
}

/**
 * @brief Retry flash message
 * @param array|mixed $result Retry result
 * @param KsefService $ksef
 * @param int $invoiceId
 * @return array
 */
function ksefRetryResultMessage($result, $ksef, $invoiceId)
{
    global $langs;
    $langs->load("ksef@ksef");
    $status = is_array($result) ? (string) ($result['status'] ?? '') : '';
    if (is_array($result) && isset($result['record_saved']) && $result['record_saved'] === false) {
        $num = (string) ($result['ksef_number'] ?? '');
        $txt = !empty($result['warning']) ? (string) $result['warning'] : $langs->transnoentitiesnoconv('KSEF_AcceptedButNotRecorded', $num);
        return array(ksefEscapeText(dol_html_entity_decode($txt, ENT_QUOTES)), 'errors', 'error');
    }
    if ($status === 'ACCEPTED') {
        return array($langs->trans('KSEF_RetryResultAccepted', ksefEscapeText((string) ($result['ksef_number'] ?? ''))), 'mesgs', 'accepted');
    }
    if ($status === 'OFFLINE') {
        return array($langs->trans('KSEF_OfflineInvoiceCreated'), 'mesgs', 'accepted');
    }
    if (in_array($status, array('TIMEOUT', 'PENDING'), true)) {
        return array($langs->trans('KSEF_RetryResultProcessing'), 'warnings', 'processing');
    }
    if (in_array($status, array('NEEDS_CONFIRMATION', 'NEEDS_OFFLINE_CONFIRMATION'), true)) {
        $card = DOL_URL_ROOT . '/compta/facture/card.php?facid=' . (int) $invoiceId;
        return array($langs->trans('KSEF_RetryResultOnCard', '<a href="' . $card . '">', '</a>'), 'warnings', 'skipped');
    }
    if (is_array($result) && (string) ($result['error_code'] ?? '') === 'DEPOSITS_ACK_REQUIRED') {
        $card = DOL_URL_ROOT . '/compta/facture/card.php?facid=' . (int) $invoiceId;
        return array($langs->trans('KSEF_RetrySkippedDepositAck', '<a href="' . $card . '">', '</a>'), 'warnings', 'skipped');
    }
    if (is_array($result) && (string) ($result['error_code'] ?? '') === 'NEGATIVE_RATE_ACK_REQUIRED') {
        $card = DOL_URL_ROOT . '/compta/facture/card.php?facid=' . (int) $invoiceId;
        return array($langs->trans('KSEF_RetrySkippedNegativeRateAck', '<a href="' . $card . '">', '</a>'), 'warnings', 'skipped');
    }
    if (is_array($result) && in_array((string) ($result['error_code'] ?? ''), array('ISSUED_IN_PRODUCTION', 'CORRECTION_SOURCE_AWAITING_NUMBER', 'CORRECTION_BEFORE_SOURCE_NUMBER'), true)) {
        return array(ksefEscapeText((string) ($result['error'] ?? '')), 'warnings', 'skipped');
    }
    // trans() HTML-encoded
    if (is_array($result) && !empty($result['not_retryable'])) {
        return array(ksefEscapeText(dol_html_entity_decode((string) ($ksef->error ?: ($result['error'] ?? '')), ENT_QUOTES)), 'warnings', 'skipped');
    }
    $msg = dol_html_entity_decode(is_array($result) && !empty($result['error']) ? (string) $result['error'] : (string) $ksef->error, ENT_QUOTES);
    if (is_array($result) && !empty($result['error_code']) && ctype_digit((string) $result['error_code'])) {
        $msg = $result['error_code'] . ': ' . ksefKsefCodeLabel((string) $result['error_code']);
    }
    return array($langs->trans('KSEF_RetryResultFailed') . ($msg !== '' ? ': ' . ksefEscapeText($msg) : ''), 'errors', 'error');
}

/**
 * @brief Pending online send
 * @param DoliDB $db
 * @param int $fk_facture Invoice ID
 * @param string|null $environment
 * @return array|null
 */
function ksefOnlinePendingRow($db, $fk_facture, $environment = null)
{
    if ((int) $fk_facture <= 0) {
        return null;
    }
    $env = $environment === null ? ksefGetEnvironment() : (string) $environment;
    $res = $db->query("SELECT rowid, status, ksef_number, offline_mode FROM " . MAIN_DB_PREFIX . "ksef_submissions WHERE fk_facture = " . (int) $fk_facture
        . " AND environment = '" . $db->escape($env) . "' ORDER BY date_submission DESC, rowid DESC");
    $latest = null;
    while ($res && ($o = $db->fetch_object($res))) {
        if ($latest === null) {
            $latest = $o;
        }
        if ($o->status === 'ACCEPTED' && ksefIsRealKsefNumber($o->ksef_number)) {
            return null;
        }
    }
    if ($latest === null || !empty($latest->offline_mode) || !in_array((string) $latest->status, array('SUBMITTED', 'PENDING', 'TIMEOUT'), true)) {
        return null;
    }
    if (ksefProductionIssuedBlock($db, (int) $fk_facture, $env)) {
        return null;
    }
    return array('id' => (int) $latest->rowid, 'status' => (string) $latest->status);
}

/**
 * @brief Correction source pending
 * @param DoliDB $db
 * @param int $fk_facture Original invoice ID
 * @param string|null $environment
 * @return array|null
 */
function ksefSourceAwaitingRow($db, $fk_facture, $environment = null)
{
    $row = ksefOfflineAwaitingRow($db, $fk_facture, $environment);
    if ($row) {
        return $row + array('kind' => 'offline');
    }
    $row = ksefOnlinePendingRow($db, $fk_facture, $environment);
    return $row ? $row + array('kind' => 'online') : null;
}

/**
 * @brief Unnumbered offline issue
 * @param DoliDB $db
 * @param int $fk_facture Invoice ID
 * @param string|null $environment
 * @return array|null
 */
function ksefOfflineAwaitingRow($db, $fk_facture, $environment = null)
{
    if ((int) $fk_facture <= 0) {
        return null;
    }
    $env = $environment === null ? ksefGetEnvironment() : (string) $environment;
    $res = $db->query("SELECT rowid, status, ksef_number, offline_mode,"
        . " CASE WHEN fa3_xml IS NOT NULL AND fa3_xml <> '' THEN 1 ELSE 0 END AS has_xml"
        . " FROM " . MAIN_DB_PREFIX . "ksef_submissions WHERE fk_facture = " . (int) $fk_facture
        . " AND environment = '" . $db->escape($env) . "' ORDER BY date_submission DESC, rowid DESC");
    $latest = null;
    $offline = null;
    while ($res && ($o = $db->fetch_object($res))) {
        if ($latest === null) {
            $latest = $o;
        }
        if ($o->status === 'ACCEPTED' && ksefIsRealKsefNumber($o->ksef_number)) {
            return null;
        }
        $o->fa3_xml = (int) $o->has_xml ? 'x' : '';
        if ($offline === null && (!empty($o->offline_mode) || $o->status === 'OFFLINE') && ksefRowIsIssued($o)) {
            $offline = $o;
        }
    }
    if ($offline === null || in_array((string) $latest->status, array('FAILED', 'REJECTED'), true)) {
        return null;
    }
    return array('id' => (int) $offline->rowid, 'status' => (string) $offline->status, 'ksef_number' => (string) $offline->ksef_number);
}

/**
 * @brief Offline numbering day
 * @param DoliDB $db
 * @param int $srcId Original invoice
 * @return string Y-m-d or ''
 */
function ksefOfflineSourceNumberDay($db, $srcId)
{
    $P = MAIN_DB_PREFIX;
    $envSql = " AND environment = '" . $db->escape(ksefGetEnvironment()) . "'";
    $res = $db->query("SELECT COUNT(*) AS nb FROM " . $P . "ksef_submissions WHERE fk_facture = " . (int) $srcId . $envSql
        . " AND ((offline_mode IS NOT NULL AND offline_mode <> '') OR status = 'OFFLINE')");
    $o = $res ? $db->fetch_object($res) : null;
    if (!$o || (int) $o->nb === 0) {
        return '';
    }
    $res = $db->query("SELECT ksef_number, api_response, date_acceptance FROM " . $P . "ksef_submissions WHERE fk_facture = " . (int) $srcId . $envSql
        . " AND status = 'ACCEPTED' AND ksef_number IS NOT NULL ORDER BY date_submission DESC, rowid DESC");
    while ($res && ($o = $db->fetch_object($res))) {
        if (!ksefIsRealKsefNumber($o->ksef_number)) {
            continue;
        }
        if (preg_match('/^\d{10}-(\d{4})(\d{2})(\d{2})-/', (string) $o->ksef_number, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $m[1] . '-' . $m[2] . '-' . $m[3];
        }
        $api = json_decode((string) $o->api_response, true);
        $acq = is_array($api) && !empty($api['acquisition_date']) ? strtotime((string) $api['acquisition_date']) : false;
        if ($acq) {
            return ksefWarsawToday($acq);
        }
        return ksefMomentDay((int) $o->date_acceptance);
    }
    return '';
}

/**
 * @brief Left for KSeF
 * @param DoliDB $db
 * @param int $invoiceId
 * @return bool
 */
function ksefInvoiceLeftDolibarr($db, $invoiceId)
{
    $st = ksefSubmissionState($db, (int) $invoiceId, '*');
    if ($st['is_accepted'] || $st['is_offline'] || $st['locked'] || $st['is_in_flight'] || $st['outcome_unknown'] || $st['number_without_row'] !== '') {
        return true;
    }
    return $st['has_submission'] && $st['status'] === 'REJECTED' && !empty($st['submission']->offline_mode);
}

/**
 * @brief Excess credit set-offs
 * @param DoliDB $db
 * @param int $invoiceId Paying invoice
 * @param string $element facture|invoice_supplier
 * @return array|false false on error
 */
function ksefExcessUseDates($db, $invoiceId, $element = 'facture')
{
    $k = ksefInvoiceKind($element);
    $P = MAIN_DB_PREFIX;
    $res = $db->query("SELECT r.rowid, r.amount_ttc, r." . $k['col_source'] . " AS src, r.datec, x.datef, x.date_closing,"
        . " (SELECT MAX(p.datep) FROM " . $P . $k['pay_table'] . " AS pf INNER JOIN " . $P . ($k['discount_type'] ? "paiementfourn" : "paiement") . " AS p ON p.rowid = pf." . $k['pay_id']
        . " WHERE pf." . $k['pay_fk'] . " = x.rowid AND pf.amount > 0) AS paid"
        . " FROM " . $P . "societe_remise_except AS r INNER JOIN " . $P . $k['table'] . " AS x ON x.rowid = r." . $k['col']
        . " WHERE r." . $k['col'] . " = " . (int) $invoiceId . " AND r.description LIKE '" . $db->escape($k['excess']) . "%' ORDER BY r.rowid");
    if (!$res) {
        dol_syslog('ksefExcessUseDates: ' . $db->lasterror(), LOG_ERR);
        return false;
    }
    $rows = array();
    while ($o = $db->fetch_object($res)) {
        $rows[] = $o;
    }
    $db->free($res);
    $rec = (array) (ksefDepositState($db, $invoiceId, $element)['setoff'] ?? array());
    $chains = array();
    $out = array();
    foreach ($rows as $o) {
        $src = (int) $o->src;
        if ($src > 0 && !isset($chains[$src])) {
            $chains[$src] = array();
            for ($ids = array($src), $i = 0; $ids && $i < 50; $i++) {
                $rc = $db->query("SELECT rowid, datef, datec, fk_statut FROM " . $P . $k['table'] . " WHERE fk_facture_source IN (" . implode(',', $ids) . ")"
                    . " AND type = " . Facture::TYPE_REPLACEMENT);
                if (!$rc) {
                    dol_syslog('ksefExcessUseDates: ' . $db->lasterror(), LOG_ERR);
                    return false;
                }
                $ids = array();
                while ($c = $db->fetch_object($rc)) {
                    $ids[] = (int) $c->rowid;
                    if ((int) $c->fk_statut > 0) {
                        $chains[$src][] = array($db->jdate($c->datef), $db->jdate($c->datec));
                    }
                }
                $db->free($rc);
            }
        }
        $datec = $db->jdate($o->datec);
        $kor = 0;
        foreach ($chains[$src] ?? array() as $c) {
            if ($c[1] <= $datec && $c[0] > $kor) {
                $kor = $c[0];
            }
        }
        $recorded = isset($rec[(int) $o->rowid]);
        $out[(int) $o->rowid] = array(
            'date' => $db->jdate($recorded ? $rec[(int) $o->rowid] : ($o->paid ?: ($o->date_closing ?: $o->datef))),
            'recorded' => $recorded,
            'amount' => (float) $o->amount_ttc,
            'source' => $src,
            'floor' => $kor ?: $datec,
            'korekta' => $kor > 0,
        );
    }
    return $out;
}

/**
 * @brief Record set-off date
 * @param DoliDB $db
 * @param int $invoiceId Paying invoice
 * @param int $discountId
 * @param string|null $date Null removes
 * @param string $element facture|invoice_supplier
 * @return bool
 */
function ksefSetoffDateSet($db, $invoiceId, $discountId, $date, $element = 'facture')
{
    $dates = (array) (ksefDepositState($db, $invoiceId, $element)['setoff'] ?? array());
    if ($date === null) {
        unset($dates[(int) $discountId]);
    } else {
        $dates[(int) $discountId] = $date;
    }
    return ksefDepositStateSet($db, $invoiceId, array('setoff' => $dates ? $dates : null), $element);
}

/**
 * @brief Deleted payment invoices
 * @param DoliDB|null $db Null to read
 * @param int $paymentId
 * @return array|null
 */
function ksefSupplierPaymentInvoices($db, $paymentId)
{
    static $kept = array();
    $paymentId = (int) $paymentId;
    if ($paymentId <= 0) {
        return null;
    }
    if ($db) {
        $ids = array();
        $res = $db->query("SELECT fk_facturefourn FROM " . MAIN_DB_PREFIX . "paiementfourn_facturefourn WHERE fk_paiementfourn = " . $paymentId);
        while ($res && ($o = $db->fetch_object($res))) {
            $ids[] = (int) $o->fk_facturefourn;
        }
        if ($ids) {
            $kept[$paymentId] = $ids;
        }
    }
    return $kept[$paymentId] ?? null;
}

/**
 * @brief Shift deduction line
 * @param DoliDB $db
 * @param int $lineId
 * @param int $invoiceId
 * @param array $d Amount deltas
 * @param string $element facture|invoice_supplier
 * @param bool $realign Restate PLN after
 * @return string '' on success
 */
function ksefInvoiceLineShift($db, $lineId, $invoiceId, $d, $element = 'facture', $realign = true)
{
    $P = MAIN_DB_PREFIX;
    $k = ksefInvoiceKind($element);
    $set = $head = array();
    $fields = array($k['line_pu'] => isset($d['pu']) ? 'pu' : 'ht', 'total_ht' => 'ht', $k['line_tva'] => 'tva', 'total_ttc' => 'ttc', 'multicurrency_subprice' => 'mht',
        'multicurrency_total_ht' => 'mht', 'multicurrency_total_tva' => 'mtva', 'multicurrency_total_ttc' => 'mttc');
    if ($k['element'] === 'invoice_supplier') {
        $fields['pu_ttc'] = isset($d['pu']) ? 'pu' : 'ttc';
    }
    foreach ($fields as $field => $col) {
        $set[$field] = $field . " = " . $field . " - (" . (float) $d[$col] . ")";
    }
    foreach (array('total_ht' => 'ht', 'total_tva' => 'tva', 'total_ttc' => 'ttc', 'multicurrency_total_ht' => 'mht', 'multicurrency_total_tva' => 'mtva',
        'multicurrency_total_ttc' => 'mttc') as $field => $col) {
        $head[$field] = $field . " = " . $field . " - (" . (float) $d[$col] . ")";
    }
    if ($k['element'] === 'invoice_supplier') {
        $head['tva'] = "tva = tva - (" . (float) $d['tva'] . ")";
    }
    $sql = "UPDATE " . $P . $k['line_table'] . " SET " . implode(', ', $set) . " WHERE rowid = " . (int) $lineId;
    $sql2 = "UPDATE " . $P . $k['table'] . " SET " . implode(', ', $head) . " WHERE rowid = " . (int) $invoiceId;
    if (!$db->query($sql) || !$db->query($sql2)) {
        return 'deposit line ' . (int) $lineId . ': ' . $db->lasterror();
    }
    if ($realign && ksefDepositLinesAtInvoiceRate($db, $invoiceId, $element) < 0) {
        return 'deposit lines of ' . (int) $invoiceId . ' at its rate: ' . $db->lasterror();
    }
    return '';
}

/**
 * @brief Invoice in KSeF
 * @param DoliDB $db
 * @param int $invoiceId
 * @param string $element facture|invoice_supplier
 * @return bool
 */
function ksefInvoiceSentToKsef($db, $invoiceId, $element = 'facture')
{
    $k = ksefInvoiceKind($element);
    if ($k['element'] === 'facture' && ksefInvoiceLeftDolibarr($db, (int) $invoiceId)) {
        return true;
    }
    $res = $db->query("SELECT ksef_number FROM " . MAIN_DB_PREFIX . $k['extrafields'] . "_extrafields WHERE fk_object = " . (int) $invoiceId);
    $o = $res ? $db->fetch_object($res) : null;
    return $o && trim((string) $o->ksef_number) !== '';
}

/**
 * @brief Invoice is booked
 * @param DoliDB $db
 * @param int $invoiceId
 * @param string $element facture|invoice_supplier
 * @return bool
 */
function ksefInvoiceInAccounting($db, $invoiceId, $element = 'facture')
{
    if (!isModEnabled('accounting')) {
        return false;
    }
    $type = ksefInvoiceKind($element)['element'] === 'invoice_supplier' ? 'supplier_invoice' : 'customer_invoice';
    $res = $db->query("SELECT COUNT(*) AS n FROM " . MAIN_DB_PREFIX . "accounting_bookkeeping WHERE doc_type = '" . $type . "' AND fk_doc = " . (int) $invoiceId);
    $o = $res ? $db->fetch_object($res) : null;
    return $o && (int) $o->n > 0;
}

/**
 * @brief Invoice source orders
 * @param DoliDB $db
 * @param int $invoiceId
 * @return array
 */
function ksefInvoiceOrderSources($db, $invoiceId)
{
    $P = MAIN_DB_PREFIX;
    $out = array();
    $seen = array();
    $add = function ($type, $id) use (&$out, &$seen) {
        $k = $type . ':' . (int) $id;
        if ((int) $id > 0 && !isset($seen[$k])) {
            $seen[$k] = true;
            $out[] = array('type' => $type, 'id' => (int) $id);
        }
    };
    if ((int) $invoiceId <= 0) {
        return $out;
    }
    $res = $db->query("SELECT sourcetype, fk_source FROM " . $P . "element_element WHERE targettype = 'facture' AND fk_target = " . (int) $invoiceId
        . " AND sourcetype IN ('commande', 'propal')");
    $orders = array();
    while ($res && ($o = $db->fetch_object($res))) {
        $add((string) $o->sourcetype, $o->fk_source);
        if ($o->sourcetype === 'commande') {
            $orders[] = (int) $o->fk_source;
        }
    }
    foreach ($orders as $oid) {
        $res = $db->query("SELECT fk_source FROM " . $P . "element_element WHERE sourcetype = 'propal' AND targettype = 'commande' AND fk_target = " . $oid);
        while ($res && ($o = $db->fetch_object($res))) {
            $add('propal', $o->fk_source);
        }
    }
    return $out;
}

/**
 * @brief Order gross value
 * @param array $order ksefDepositOrder()
 * @param string $currency
 * @return float
 */
function ksefOrderValue($order, $currency)
{
    $useMulti = $currency !== 'PLN';
    $value = 0.0;
    foreach (ksefOrderRowLines($order) as $line) {
        $value += $useMulti ? (float) $line->multicurrency_total_ttc : (float) $line->total_ttc;
    }
    return $value;
}

/**
 * @brief Order rows lines
 * @param array $order ksefDepositOrder()
 * @return array
 */
function ksefOrderRowLines($order)
{
    $out = array();
    foreach ((array) ($order['lines'] ?? array()) as $k => $line) {
        if ((int) ($line->product_type ?? 0) === 9 || ((float) $line->total_ht == 0 && (float) $line->qty == 0)) {
            continue;
        }
        $out[$k] = $line;
    }
    return $out;
}

/**
 * @brief XML RodzajFaktury
 * @param string $xml
 * @return string '' if absent
 */
function ksefXmlInvoiceType($xml)
{
    if (!is_string($xml) || $xml === '' || !preg_match('/<(?:[A-Za-z0-9_.-]+:)?RodzajFaktury\s*>\s*([A-Z_]{3,7})\s*</', $xml, $m)) {
        return '';
    }
    return $m[1];
}

/**
 * @brief Sent RodzajFaktury
 * @param DoliDB $db
 * @param int $fk_facture
 * @return string '' if unknown
 */
function ksefSentInvoiceType($db, $fk_facture)
{
    $res = $db->query("SELECT rowid, status, ksef_number, offline_mode, invoice_type,"
        . " CASE WHEN fa3_xml IS NOT NULL AND fa3_xml <> '' THEN 1 ELSE 0 END AS has_xml"
        . " FROM " . MAIN_DB_PREFIX . "ksef_submissions WHERE fk_facture = " . (int) $fk_facture . ksefEnvironmentSql($db) . " ORDER BY rowid DESC");
    while ($res && ($o = $db->fetch_object($res))) {
        $o->fa3_xml = (int) $o->has_xml ? 'x' : '';
        if (!ksefRowIsIssued($o)) {
            continue;
        }
        if ((string) $o->invoice_type !== '') {
            return (string) $o->invoice_type;
        }
        if (!(int) $o->has_xml) {
            return '';
        }
        $x = $db->query("SELECT fa3_xml FROM " . MAIN_DB_PREFIX . "ksef_submissions WHERE rowid = " . (int) $o->rowid);
        $xo = $x ? $db->fetch_object($x) : null;
        return $xo ? ksefXmlInvoiceType((string) $xo->fa3_xml) : '';
    }
    return '';
}

/**
 * @brief Backfill sent types
 * @param DoliDB $db
 * @return int Rows, -1 on error
 */
function ksefBackfillSentInvoiceTypes($db)
{
    $res = $db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "ksef_submissions"
        . " WHERE (invoice_type IS NULL OR invoice_type = '') AND fa3_xml IS NOT NULL AND fa3_xml <> ''");
    if (!$res) {
        dol_syslog('ksefBackfillSentInvoiceTypes: ' . $db->lasterror(), LOG_ERR);
        return -1;
    }
    $ids = array();
    while ($o = $db->fetch_object($res)) {
        $ids[] = (int) $o->rowid;
    }
    $db->free($res);
    $n = 0;
    foreach ($ids as $id) {
        // MEDIUMTEXT: one XML
        $x = $db->query("SELECT fa3_xml FROM " . MAIN_DB_PREFIX . "ksef_submissions WHERE rowid = " . $id);
        $xo = $x ? $db->fetch_object($x) : null;
        $type = $xo ? ksefXmlInvoiceType((string) $xo->fa3_xml) : '';
        if ($type === '') {
            continue;
        }
        if ($db->query("UPDATE " . MAIN_DB_PREFIX . "ksef_submissions SET invoice_type = '" . $db->escape($type) . "' WHERE rowid = " . $id)) {
            $n++;
        } else {
            dol_syslog('ksefBackfillSentInvoiceTypes: row ' . $id . ': ' . $db->lasterror(), LOG_ERR);
        }
    }
    return $n;
}

/**
 * @brief Legacy failure kinds
 * @param DoliDB $db
 * @return int Rows marked transport, -1 on error
 */
function ksefBackfillLegacyFailureKind($db)
{
    // 1.4.5 pre-upload messages
    $exact = array('Failed to obtain authentication challenge', 'Invalid challenge response from KSeF', 'Unable to load KSeF public key certificate',
        'Authentication request failed', 'Invalid authentication response received', 'Authentication status verification timeout',
        'Token redeem request failed', 'Invalid tokens returned on redeem', 'Certificate authentication status verification timeout',
        'Invoice submission error: Failed to get public keys', 'Invoice submission error: No suitable public key found for symmetric encryption',
        'Invoice submission error: Failed to open invoice submission session', 'Invoice submission error: Session reference number missing');
    $prefix = array('Authentication failed', 'Certificate authentication request failed: ', 'Certificate authentication failed (status ');
    $in = array();
    foreach ($exact as $m) {
        $in[] = "'" . $db->escape($m) . "'";
    }
    $match = array("error_message IN (" . implode(', ', $in) . ")");
    foreach ($prefix as $p) {
        $match[] = "error_message LIKE '" . $db->escape($p) . "%'";
    }
    $sql = "UPDATE " . MAIN_DB_PREFIX . "ksef_submissions SET failure_kind = 'transport', tms = tms"
        . " WHERE status = 'FAILED' AND failure_kind IS NULL AND error_code IS NULL"
        . " AND (ksef_reference IS NULL OR ksef_reference = '') AND (ksef_number IS NULL OR ksef_number = '')"
        . " AND (offline_mode IS NULL OR offline_mode = '') AND (" . implode(' OR ', $match) . ")";
    $res = $db->query($sql);
    if (!$res) {
        dol_syslog('ksefBackfillLegacyFailureKind: ' . $db->lasterror(), LOG_ERR);
        return -1;
    }
    return (int) $db->affected_rows($res);
}

/**
 * @brief Export start error
 * @param int $httpCode 0: no answer
 * @param int $retryAfter Seconds
 * @return string
 */
function ksefFetchErrorMessage($httpCode, $retryAfter = 0)
{
    global $langs;
    $langs->load("ksef@ksef");
    $httpCode = (int) $httpCode;
    if ($httpCode === 429) {
        return $langs->transnoentitiesnoconv('KSEF_FetchErrRateLimited', max(1, (int) $retryAfter));
    }
    if ($httpCode === 0 || $httpCode >= 500) {
        return $langs->transnoentitiesnoconv('KSEF_FetchErrUnavailable');
    }
    return $langs->transnoentitiesnoconv('KSEF_FetchErrGeneric', $httpCode);
}

/**
 * @brief Invoice status badge
 * @param Facture|FactureFournisseur $inv
 * @param int $mode getLibStatut() mode
 * @return string
 */
function ksefInvoiceStatusBadge($inv, $mode = 5)
{
    if (!is_object($inv) || empty($inv->id)) {
        return '';
    }
    $paid = max(0, (float) $inv->getSommePaiement()) + (float) $inv->getSumCreditNotesUsed() + (float) $inv->getSumDepositsUsed();
    return $inv->getLibStatut($mode, $paid);
}

/**
 * @brief Creatable product types
 * @param User $user
 * @return array
 */
function ksefCreatableProductTypes($user)
{
    $out = array();
    if (isModEnabled('product') && $user->hasRight('produit', 'creer')) {
        $out[] = 0;
    }
    if (isModEnabled('service') && $user->hasRight('service', 'creer')) {
        $out[] = 1;
    }
    return $out;
}

/**
 * @brief Last sync result
 * @param KsefSyncState $syncState
 * @return string
 */
function ksefSyncResultText($syncState)
{
    global $langs;
    $txt = $langs->trans('KSEF_SyncResultShort', (int) $syncState->last_sync_new, (int) $syncState->last_sync_existing, (int) $syncState->last_sync_total);
    if ((int) $syncState->last_sync_fetch_errors > 0 || (int) $syncState->last_sync_unsupported > 0) {
        $txt .= $langs->trans('KSEF_SyncResultExtra', (int) $syncState->last_sync_fetch_errors, (int) $syncState->last_sync_unsupported);
    }
    return $txt;
}

/**
 * @brief Add sync more/until
 * @param array $result checkIncomingFetchStatus()
 * @param string $direction incoming, outgoing
 * @return array
 */
function ksefSyncAddMore($result, $direction)
{
    global $db;
    if (!is_array($result) || ($result['status'] ?? '') !== 'COMPLETED') {
        return $result;
    }
    dol_include_once('/ksef/class/ksef_sync_state.class.php');
    $st = new KsefSyncState($db);
    $st->load($direction);
    $cont = $st->getContinuationDate();
    $result['more'] = ($cont && $cont < dol_now() - 86400) || !empty($result['truncated']);
    $result['until'] = $cont ? dol_print_date($cont, 'day') : '';
    return $result;
}

/**
 * @brief Export start error
 * @param KsefService $ksef
 * @return array
 */
function ksefFetchInitErrorResult($ksef)
{
    return array('status' => 'ERROR', 'error' => ksefFetchErrorMessage($ksef->last_http_code, $ksef->last_retry_after),
        'error_raw' => (string) $ksef->error, 'error_http_code' => (int) $ksef->last_http_code, 'retry_after' => (int) $ksef->last_retry_after);
}

/**
 * @brief Sync directions allowed
 * @param User $user
 * @param bool $run Needs write
 * @return array incoming, outgoing
 */
function ksefSyncSides($user, $run = false)
{
    if (empty($user) || empty($user->id) || !empty($user->socid) || !$user->hasRight('ksef', 'read')) {
        return array();
    }
    if ($run && !$user->hasRight('ksef', 'write')) {
        return array();
    }
    $out = array('incoming');
    if ($user->hasRight('facture', 'lire')) {
        $out[] = 'outgoing';
    }
    return $out;
}

/**
 * @brief Sync AJAX step
 * @param KSEFService $ksef
 * @param User $user
 * @param string $action
 * @param string $pageDir Default direction
 * @return void
 */
function ksefSyncAjax($ksef, $user, $action, $pageDir)
{
    $steps = array('init_fetch', 'check_fetch_status', 'process_fetch', 'process_incoming', 'process_outgoing', 'upo_sweep');
    if (!in_array($action, $steps, true)) {
        return;
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache');
    $dir = GETPOST('dir', 'aZ09');
    if ($action === 'process_incoming' || $action === 'process_outgoing') {
        $dir = substr($action, 8);
    } elseif ($action === 'upo_sweep') {
        $dir = 'outgoing';
    }
    if ($dir === '') {
        $dir = $pageDir;
    }
    $allowed = ksefSyncSides($user, $action !== 'check_fetch_status');
    if (!in_array($dir, $allowed, true)) {
        echo json_encode(array('status' => 'ERROR', 'error' => 'Access denied'));
        exit;
    }
    session_write_close();
    if ($action === 'init_fetch') {
        // KSeF may refuse
        if ($dir === 'outgoing' && !GETPOSTINT('cont')) {
            $ksef->reconcileSales(ksefGetEnvironment(), $user);
        }
        $r = $ksef->initIncomingFetch($user, $dir);
        echo json_encode($r === false ? ksefFetchInitErrorResult($ksef) : $r);
        exit;
    }
    if ($action === 'check_fetch_status') {
        echo json_encode(ksefSyncAddMore($ksef->checkIncomingFetchStatus($user, $dir), $dir));
        exit;
    }
    if ($action === 'upo_sweep') {
        echo json_encode($ksef->syncUpoSessions($user));
        exit;
    }
    ignore_user_abort(true);
    set_time_limit(0);
    echo json_encode(array('status' => 'STARTED'));
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        if (ob_get_level()) {
            ob_end_flush();
        }
        flush();
    }
    $ksef->processIncomingDownload($user, $dir);
    exit;
}

/**
 * @brief Start sync fallback
 * @param KSEFService $ksef
 * @param User $user
 * @return bool True if started
 */
function ksefSyncStart($ksef, $user)
{
    $started = false;
    foreach (ksefSyncSides($user, true) as $dir) {
        if ($dir === 'outgoing') {
            $ksef->reconcileSales(ksefGetEnvironment(), $user);
        }
        $r = $ksef->initIncomingFetch($user, $dir);
        if ($r === false) {
            setEventMessages($ksef->error, $ksef->errors, 'errors');
            break;
        }
        if ($r['status'] === 'INITIATED' || $r['status'] === 'ALREADY_PROCESSING') {
            $started = true;
        }
    }
    return $started;
}

/**
 * @brief Cancel running sync
 * @param KSEFService $ksef
 * @param User $user
 * @param string $pageDir
 * @return bool
 */
function ksefSyncReset($ksef, $user, $pageDir)
{
    $dir = GETPOST('dir', 'aZ09');
    $sides = ksefSyncSides($user, true);
    $ok = true;
    foreach ($sides as $d) {
        if ($dir !== '' && $d !== $dir) {
            continue;
        }
        if ($dir === '' && $d !== $pageDir) {
            $st = $ksef->getIncomingSyncState($d);
            if (!$st->isFetchInProgress() && !in_array($st->fetch_status, array(KsefSyncState::FETCH_STATUS_FAILED, KsefSyncState::FETCH_STATUS_TIMEOUT), true)) {
                continue;
            }
        }
        $ok = $ksef->resetIncomingFetch($d) && $ok;
    }
    return $ok;
}

/**
 * @brief Sync button state
 * @param array $states direction => KsefSyncState
 * @return array can, title
 */
function ksefSyncButtonState($states)
{
    global $langs;
    foreach ($states as $st) {
        if (!$st->canSyncNow()) {
            if ($st->isRateLimited()) {
                return array('can' => false, 'title' => $langs->trans('KSEF_RateLimitActive', $st->getRateLimitExpiryFormatted()));
            }
            return array('can' => false, 'title' => $langs->trans('KSEF_FetchAlreadyInProgress'));
        }
    }
    return array('can' => true, 'title' => '');
}

/**
 * @brief User sync states
 * @param KSEFService $ksef
 * @param User $user
 * @return array direction => KsefSyncState
 */
function ksefSyncStates($ksef, $user)
{
    $out = array();
    foreach (ksefSyncSides($user) as $dir) {
        $out[$dir] = $ksef->getIncomingSyncState($dir);
    }
    return $out;
}

/**
 * @brief Print sync button
 * @param array $states direction => KsefSyncState
 * @param string $url Fallback link
 * @return void
 */
function ksefPrintSyncButton($states, $url)
{
    global $langs, $user;
    if (!ksefSyncSides($user, true)) {
        print dolGetButtonAction($langs->trans('NotEnoughPermissions'), $langs->trans('KSEF_SyncNow'), 'default', '#', 'ksef-sync-btn', false);
        return;
    }
    $b = ksefSyncButtonState($states);
    print '<a id="ksef-sync-btn" class="' . ($b['can'] ? 'butAction' : 'butActionRefused') . '"' . ($b['title'] !== '' ? ' title="' . dol_escape_htmltag($b['title']) . '"' : '')
        . ($b['can'] ? ' onclick="return ksefInitSync(event);" href="' . $url . (strpos($url, '?') === false ? '?' : '&') . 'action=sync&token=' . newToken() . '"' : '') . '>';
    print '<span class="fa fa-sync"></span> ' . $langs->trans('KSEF_SyncNow') . '</a>';
}

/**
 * @brief Last sync outcome
 * @param KsefSyncState $st
 * @return array state, reason
 */
function ksefSyncLastResult($st)
{
    global $langs;
    if ($st->isFetchInProgress() || $st->isProcessingInProgress()) {
        return array('state' => 'running', 'reason' => '');
    }
    if ($st->fetch_status === KsefSyncState::FETCH_STATUS_FAILED) {
        return array('state' => 'error', 'reason' => $langs->trans('KSEF_DashSyncReasonExport'));
    }
    if ($st->fetch_status === KsefSyncState::FETCH_STATUS_TIMEOUT) {
        return array('state' => 'error', 'reason' => $langs->trans('KSEF_DashSyncReasonTimeout'));
    }
    if ($st->isRateLimited()) {
        return array('state' => 'error', 'reason' => $langs->trans('KSEF_DashSyncReasonLimit', $st->getRateLimitExpiryFormatted()));
    }
    $fail = explode('|', (string) $st->last_fail);
    if (count($fail) === 2 && (int) $fail[0] >= (int) $st->last_sync) {
        $code = (int) $fail[1];
        if ($code === 429) {
            $reason = $langs->trans('KSEF_DashSyncReasonLimitShort');
        } elseif ($code === 0) {
            $reason = $langs->trans('KSEF_DashSyncReasonConn');
        } elseif ($code >= 500) {
            $reason = $langs->trans('KSEF_DashSyncReasonDown');
        } else {
            $reason = $langs->trans('KSEF_DashSyncReasonRefused', $code);
        }
        return array('state' => 'error', 'reason' => $reason);
    }
    return array('state' => $st->last_sync ? 'ok' : 'never', 'reason' => '');
}

/**
 * @brief Print fetch overlay
 * @param array $states direction => KsefSyncState
 * @param bool $showFailed
 * @return void
 */
function ksefPrintFetchOverlay($states, $showFailed = true)
{
    global $langs, $db, $conf, $user;
    $multi = count($states) > 1;
    $labels = array();
    $resume = array();
    $failed = array();
    $started = 0;
    foreach ($states as $dir => $st) {
        $labels[$dir] = $multi ? $langs->transnoentitiesnoconv($dir === 'outgoing' ? 'KSEF_OutgoingInvoices' : 'KSEF_IncomingInvoices') : '';
        if ($st->isFetchInProgress()) {
            $resume[] = $dir;
            $started = $started ? min($started, (int) $st->fetch_started) : (int) $st->fetch_started;
        } elseif ($st->fetch_status === KsefSyncState::FETCH_STATUS_FAILED || $st->fetch_status === KsefSyncState::FETCH_STATUS_TIMEOUT) {
            $failed[$dir] = $st;
        }
    }
    $isPolling = !empty($resume);
    $showFailedState = $showFailed && !$isPolling && !empty($failed);
    $runDirs = array_values(array_intersect(array_keys($states), ksefSyncSides($user, true)));

    if (!empty($conf->use_javascript_ajax) && empty($conf->dol_use_jmobile)) {
        $form = new Form($db);
        print $form->formconfirm($_SERVER["PHP_SELF"], $langs->trans('Cancel'), $langs->trans('KSEF_ConfirmCancelFetch'), 'reset_fetch', '', 0, 'ksef_cancel_fetch');
        print '<script>jQuery(document).on("click", "a.ksef-fetch-cancel", function (e) { e.preventDefault(); jQuery("#dialog-confirm-ksef_cancel_fetch").dialog("open"); });</script>';
    }

    print '<div id="ksef-fetch-overlay" class="' . ($showFailedState ? 'error' : 'info') . '" style="padding: 15px; margin-bottom: 15px; position: relative;' . ($isPolling || $showFailedState ? '' : ' display:none;') . '">';

    if ($showFailedState) {
        foreach ($failed as $dir => $st) {
            print '<div class="ksef-sync-failed">';
            print '<span class="fa fa-exclamation-triangle ' . ksefToneTextClass('problem') . '" style="margin-right: 10px;"></span>';
            print '<strong class="' . ksefToneTextClass('problem') . '">' . ($labels[$dir] !== '' ? dol_escape_htmltag($labels[$dir]) . ': ' : '') . $langs->trans('KSEF_FetchFailed') . '</strong>';
            if (!empty($st->fetch_error)) {
                print '<details class="small opacitymedium" style="margin-top: 6px;"><summary>' . $langs->trans('KSEF_TechnicalDetails') . '</summary>' . dol_escape_htmltag($st->fetch_error) . '</details>';
            }
            print '</div>';
        }
        print '<a href="' . $_SERVER["PHP_SELF"] . '?action=reset_fetch&token=' . newToken() . '" class="butAction" style="margin-left: 0; margin-top: 6px;">';
        print $langs->trans('KSEF_ClearFetchError');
        print '</a>';
    } else {
        $elapsed = $isPolling ? (dol_now() - $started) : 0;
        print '<div id="ksef-sync-done"></div>';
        print '<div class="ksef-fetch-row"><span><span class="fa fa-spinner fa-spin" style="margin-right: 10px;"></span>';
        print '<strong id="ksef-phase-msg">' . $langs->trans('KSEF_FetchInProgress') . '</strong></span>';
        print '<span id="ksef-elapsed" class="opacitymedium">';
        print $langs->trans('KSEF_ElapsedTime') . ': <span id="elapsed-val">' . gmdate("i:s", max(0, $elapsed)) . '</span>';
        print '</span>';
        print '<a href="' . $_SERVER["PHP_SELF"] . '?action=reset_fetch&token=' . newToken() . '" class="butAction ksef-fetch-cancel">';
        print '<span class="fa fa-times"></span> ' . $langs->trans('Cancel');
        print '</a></div>';
    }

    print '</div>';

    print '<script>
    (function() {
        var overlayEl = document.getElementById("ksef-fetch-overlay");
        var elapsedEl = document.getElementById("elapsed-val");
        var elapsedTimer = null;
        var startTime = ' . ($isPolling ? (int) $started : 'null') . ';
        var token = "' . newToken() . '";
        var ajaxToken = "' . currentToken() . '";
        var baseUrl = "' . $_SERVER["PHP_SELF"] . '";
        var labels = ' . json_encode((object) $labels) . ';
        var runDirs = ' . json_encode($runDirs) . ';
        var resumeDirs = ' . json_encode($resume) . ';
        var afterFor = { outgoing: "upo_sweep" };

        var langFetchInProgress = ' . ksefJsLang('KSEF_FetchInProgress') . ';
        var langElapsedTime = ' . ksefJsLang('KSEF_ElapsedTime') . ';
        var langFetchCompleted = ' . ksefJsLang('KSEF_FetchCompleted') . ';
        var langNewInvoices = ' . ksefJsLang('KSEF_NewInvoices') . ';
        var langExistingInvoices = ' . ksefJsLang('KSEF_ExistingInvoices') . ';
        var langFetchFailed = ' . ksefJsLang('KSEF_FetchFailed') . ';
        var langRetry = ' . ksefJsLang('KSEF_RetryShort') . ';
        var langClearError = ' . ksefJsLang('KSEF_ClearFetchError') . ';
        var langReloadSoon = ' . ksefJsLang('KSEF_SyncReloadSoon') . ';
        var langTechnicalDetails = ' . ksefJsLang('KSEF_TechnicalDetails') . ';
        var langInitFailed = ' . ksefJsLang('KSEF_FetchErrUnavailable') . ';
        var langConnectionError = ' . ksefJsLang('KSEF_ConnectionError') . ';
        var langCancel = ' . ksefJsLang('Cancel') . ';
        var langInitiating = ' . ksefJsLang('KSEF_Initiating') . ';
        var langDownloading = ' . ksefJsLang('KSEF_Downloading') . ';
        var langProcessingBatches = ' . ksefJsLang('KSEF_ProcessingBatches') . ';
        var langReadyToProcess = ' . ksefJsLang('KSEF_ReadyToProcess') . ';
        var langNextWindow = ' . ksefJsLang('KSEF_SyncNextWindow') . ';
        var langWindowPrefix = ' . ksefJsLang('KSEF_SyncWindowPrefix') . ';
        var langFetchErrorsShort = ' . ksefJsLang('KSEF_SyncFetchErrorsShort') . ';
        var langUnsupportedShort = ' . ksefJsLang('KSEF_SyncUnsupportedShort') . ';
        var langAfterStep = ' . ksefJsLang('KSEF_UpoSweepRunning') . ';
        var queue = [], qi = 0, dir = "", resuming = false, results = [];
        var syncRound = 0;
        var syncTotals = null;
        var processIncomingFired = false;
        var windowPrefix = "";

        function url(action, extra) {
            return baseUrl + "?action=" + action + "&dir=" + dir + (extra || "") + "&token=" + ajaxToken;
        }

        function prefix(d) { return labels[d] ? labels[d] + ": " : ""; }

        function escTxt(t) { var d = document.createElement("div"); d.textContent = t || ""; return d.innerHTML; }

        function formatElapsed(seconds) {
            return String(Math.floor(seconds/60)).padStart(2,"0") + ":" + String(seconds%60).padStart(2,"0");
        }

        function startElapsedTimer() {
            if (elapsedTimer) clearInterval(elapsedTimer);
            elapsedTimer = setInterval(function() {
                var elapsed = Math.floor(Date.now() / 1000) - startTime;
                if (elapsedEl) elapsedEl.textContent = formatElapsed(elapsed);
            }, 1000);
        }

        function showProcessingOverlay(phaseMsg) {
            var msg = phaseMsg || langFetchInProgress;
            overlayEl.className = "info";
            overlayEl.style.display = "block";
            overlayEl.innerHTML = \'<div id="ksef-sync-done"></div><div class="ksef-fetch-row"><span><span class="fa fa-spinner fa-spin" style="margin-right:10px;"></span>\' +
                \'<strong id="ksef-phase-msg">\' + escTxt(msg) + \'</strong></span>\' +
                \'<span id="ksef-elapsed" class="opacitymedium">\' + langElapsedTime + \': <span id="elapsed-val">00:00</span></span>\' +
                \'<a href="\' + baseUrl + \'?action=reset_fetch&token=\' + token + \'" class="butAction ksef-fetch-cancel"><span class="fa fa-times"></span> \' + langCancel + \'</a></div>\';
            elapsedEl = document.getElementById("elapsed-val");
        }

        function updatePhaseMessage(msg) {
            var el = document.getElementById("ksef-phase-msg");
            if (el) el.textContent = prefix(dir) + (windowPrefix !== "" && msg.indexOf(windowPrefix) !== 0 ? windowPrefix + " " : "") + msg;
        }

        function countsText(t) {
            return langNewInvoices + ": " + (t["new"]||0) + ", " + langExistingInvoices + ": " + (t.existing||0) +
                ((t.fetch_errors||t.unsupported) ? ", " + langFetchErrorsShort + ": " + (t.fetch_errors||0) + ", " + langUnsupportedShort + ": " + (t.unsupported||0) : "");
        }

        function addDone(r) {
            var box = document.getElementById("ksef-sync-done");
            if (!box || !labels[r.dir]) return;
            var d = document.createElement("div");
            d.className = "ksef-sync-line";
            d.innerHTML = \'<span class="fa \' + (r.ok ? "fa-check-circle ksef-tone-text-ok" : "fa-exclamation-triangle ksef-tone-text-problem") + \' paddingright"></span>\' +
                escTxt(prefix(r.dir) + (r.ok ? countsText(r.totals) : langFetchFailed + (r.error ? ": " + r.error : "")));
            box.appendChild(d);
        }

        function reload(delay) { setTimeout(function() { location.href = baseUrl; }, delay); }

        function showCompletedOverlay(data, warning) {
            if (elapsedTimer) clearInterval(elapsedTimer);
            overlayEl.className = "info";
            overlayEl.innerHTML = \'<span class="fa fa-check-circle ksef-tone-text-ok" style="margin-right:10px;"></span>\' +
                \'<strong class="ksef-tone-text-ok">\' + langFetchCompleted + \'</strong> - \' + escTxt(countsText(data)) +
                (warning ? \'<div class="warning ksef-after-warning" style="margin-top:6px;">\' + escTxt(warning) + \'</div>\' : \'\');
        }

        function enableSyncButton() {
            var sb = document.getElementById("ksef-sync-btn");
            if (sb) { sb.className = "butAction"; sb.onclick = function(ev) { return ksefInitSync(ev); }; }
        }

        function showErrorOverlay(error, raw, initFailure) {
            if (elapsedTimer) clearInterval(elapsedTimer);
            overlayEl.className = "error";
            var btn = initFailure
                ? \' <a href="#" class="butAction" style="margin-left:15px;" onclick="return ksefInitSync(event);">\' + langRetry + \'</a>\'
                : \' <a href="\' + baseUrl + \'?action=reset_fetch&token=\' + token + \'" class="butAction" style="margin-left:15px;">\' + langClearError + \'</a>\';
            overlayEl.innerHTML = \'<span class="fa fa-exclamation-triangle" style="margin-right:10px;"></span>\' +
                \'<strong>\' + langFetchFailed + \'</strong>\' + (error ? \': \' + escTxt(error) : \'\') + btn +
                (raw && raw !== error ? \'<details class="small opacitymedium" style="margin-top:6px;"><summary>\' + langTechnicalDetails + \'</summary>\' + escTxt(raw) + \'</details>\' : \'\');
            if (initFailure) enableSyncButton();
        }

        // per-direction lines
        function showSummary() {
            if (elapsedTimer) clearInterval(elapsedTimer);
            var failed = results.filter(function(r) { return !r.ok; });
            var html = failed.length
                ? \'<span class="fa fa-exclamation-triangle" style="margin-right:10px;"></span><strong>\' + langFetchFailed + \'</strong>\'
                : \'<span class="fa fa-check-circle ksef-tone-text-ok" style="margin-right:10px;"></span><strong class="ksef-tone-text-ok">\' + langFetchCompleted + \'</strong>\';
            var warn = "";
            results.forEach(function(r) {
                html += \'<div class="ksef-sync-line \' + (r.ok ? "ksef-sync-line-ok" : "ksef-sync-line-error") + \'" data-dir="\' + r.dir + \'" style="margin-top:4px;">\' +
                    \'<span class="fa \' + (r.ok ? "fa-check-circle ksef-tone-text-ok" : "fa-exclamation-triangle") + \' paddingright"></span>\' +
                    escTxt(prefix(r.dir) + (r.ok ? countsText(r.totals) : langFetchFailed + (r.error ? ": " + r.error : ""))) +
                    (!r.ok && r.raw && r.raw !== r.error ? \'<details class="small opacitymedium"><summary>\' + langTechnicalDetails + \'</summary>\' + escTxt(r.raw) + \'</details>\' : \'\') + \'</div>\';
                if (r.warning) warn = r.warning;
            });
            if (warn) html += \'<div class="warning ksef-after-warning" style="margin-top:6px;">\' + escTxt(warn) + \'</div>\';
            if (failed.length) {
                var initOnly = failed.every(function(r) { return r.init; });
                html += \'<div style="margin-top:6px;">\' + (initOnly
                    ? \'<a href="#" class="butAction" style="margin-left:0;" onclick="return ksefInitSync(event);">\' + langRetry + \'</a>\'
                    : \'<a href="\' + baseUrl + \'?action=reset_fetch&token=\' + token + \'" class="butAction" style="margin-left:0;">\' + langClearError + \'</a>\') + \'</div>\';
            }
            // show new anyway
            var gotNew = failed.length && results.some(function(r) { return r.ok && r.totals && ((r.totals["new"] || 0) + (r.totals.fetch_errors || 0) + (r.totals.unsupported || 0)) > 0; });
            if (gotNew) html += \'<div class="opacitymedium small ksef-sync-reload-note" style="margin-top:6px;">\' + escTxt(langReloadSoon) + \'</div>\';
            overlayEl.className = failed.length ? "error" : "info";
            overlayEl.innerHTML = html;
            if (gotNew) {
                reload(8000);
            } else if (failed.length) {
                if (failed.every(function(r) { return r.init; })) enableSyncButton();
            } else {
                reload(warn ? 6000 : 2000);
            }
        }

        function finishAll() {
            if (results.length === 0) { reload(500); return; }
            if (results.length === 1 && !labels[results[0].dir]) {
                var r = results[0];
                if (!r.ok) { showErrorOverlay(r.error, r.raw, r.init); return; }
                showCompletedOverlay(r.totals, r.warning);
                reload(r.warning ? 6000 : 2000);
                return;
            }
            showSummary();
        }

        function nextDir() {
            if (qi >= queue.length) { finishAll(); return; }
            dir = queue[qi++];
            syncRound = 0;
            windowPrefix = "";
            processIncomingFired = false;
            syncTotals = { "new": 0, existing: 0, fetch_errors: 0, unsupported: 0 };
            if (resuming) {
                updatePhaseMessage(langFetchInProgress);
                setTimeout(poll, 1000);
                return;
            }
            updatePhaseMessage(langInitiating);
            fetch(url("init_fetch"))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.status === "ERROR") {
                    dirFailed(data.error || langInitFailed, data.error_raw || "", true);
                } else {
                    updatePhaseMessage(langFetchInProgress);
                    setTimeout(poll, 1000);
                }
            })
            .catch(function(e) {
                console.error("Init error:", e);
                dirFailed(langConnectionError, "", true);
            });
        }

        function dirFailed(error, raw, initFailure) {
            var r = { dir: dir, ok: false, error: error, raw: raw, init: initFailure };
            results.push(r);
            addDone(r);
            nextDir();
        }

        // only a warning
        function dirDone() {
            var r = { dir: dir, ok: true, totals: syncTotals, warning: "" };
            var fin = function() { results.push(r); addDone(r); nextDir(); };
            if (!afterFor[dir]) { fin(); return; }
            windowPrefix = "";
            updatePhaseMessage(langAfterStep);
            fetch(url(afterFor[dir]))
            .then(function(x) { return x.json(); })
            .then(function(d) { r.warning = d && d.warning ? d.warning : ""; fin(); })
            .catch(function() { fin(); });
        }

        function fireProcessIncoming() {
            if (processIncomingFired) return;
            processIncomingFired = true;
            fetch(url("process_fetch")).catch(function() {});
        }

        function poll() {
            fetch(url("check_fetch_status"))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.status === "COMPLETED") {
                    syncTotals["new"] += (data["new"] || 0);
                    syncTotals.existing += (data.existing || 0);
                    syncTotals.fetch_errors += (data.fetch_errors || 0);
                    syncTotals.unsupported += (data.unsupported || 0);
                    if (data.more && syncRound < 10) {
                        syncRound++;
                        windowPrefix = langWindowPrefix.replace("%s", syncRound + 1).replace("%s", data.until || "?");
                        updatePhaseMessage(langNextWindow);
                        processIncomingFired = false;
                        startNextWindow();
                    } else {
                        dirDone();
                    }
                } else if (data.status === "PROCESSING") {
                    updatePhaseMessage(langFetchInProgress);
                    setTimeout(poll, 3000);
                } else if (data.status === "READY_TO_PROCESS") {
                    updatePhaseMessage(langReadyToProcess);
                    fireProcessIncoming();
                    setTimeout(poll, 500);
                } else if (data.status === "DOWNLOADING") {
                    var dlMsg = langDownloading;
                    if (data.parts_done && data.parts_total) {
                        dlMsg += " (" + data.parts_done + "/" + data.parts_total + ")";
                    }
                    updatePhaseMessage(dlMsg);
                    setTimeout(poll, 500);
                } else if (data.status === "PROCESSING_BATCHES") {
                    var msg = langProcessingBatches;
                    if (data.total) {
                        msg += " (" + (data.processed||0) + "/" + data.total + ")";
                    }
                    if (data["new"] || data.existing) {
                        msg += ", +" + (data["new"]||0) + " " + langNewInvoices.toLowerCase() + ", " + (data.existing||0) + " " + langExistingInvoices.toLowerCase();
                    }
                    updatePhaseMessage(msg);
                    setTimeout(poll, 500);
                } else if (data.status === "RATE_LIMITED") {
                    setTimeout(poll, (data.retry_after || 10) * 1000);
                } else if (data.status === "FAILED" || data.status === "TIMEOUT" || data.status === "DOWNLOAD_FAILED" || data.status === "CHECK_FAILED") {
                    dirFailed(data.error || "", "", false);
                } else if (data.status === "NO_PENDING_FETCH" || data.status === "ERROR") {
                    dirDone();
                } else {
                    setTimeout(poll, 3000);
                }
            })
            .catch(function(e) { console.error("Poll error:", e); setTimeout(poll, 6000); });
        }

        // skips leading reconcile
        function startNextWindow() {
            fetch(url("init_fetch", "&cont=1"))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.status === "INITIATED" || data.status === "ALREADY_PROCESSING") {
                    setTimeout(poll, 1000);
                } else {
                    dirDone();
                }
            })
            .catch(function() { dirDone(); });
        }

        function runQueue(dirs, resume) {
            queue = dirs.slice();
            qi = 0;
            results = [];
            resuming = resume;
            nextDir();
        }

        window.ksefInitSync = function(e) {
            if (e) e.preventDefault();
            startTime = Math.floor(Date.now() / 1000);
            showProcessingOverlay(langInitiating);
            startElapsedTimer();
            var btn = document.getElementById("ksef-sync-btn");
            if (btn) {
                btn.className = "butActionRefused";
                btn.onclick = function() { return false; };
            }
            runQueue(runDirs, false);
            return false;
        };

        if (resumeDirs.length && startTime) {
            startElapsedTimer();
            runQueue(resumeDirs, true);
        }
    })();
    </script>';
}

/**
 * @brief Sync coverage row
 * @param KsefSyncState|null $syncState
 * @param bool $adminLink
 * @param array|null $dot ts, title
 * @return void
 */
function ksefPrintSyncCoverageRow($syncState, $adminLink, $dot = null)
{
    global $langs;
    $ksefStartDate = strtotime('2026-02-01');
    $ksefPreStartDate = strtotime('2026-01-31');
    $today = dol_now();
    $hwmTimestamp = $syncState ? $syncState->getContinuationDate() : null;
    if ($hwmTimestamp && $hwmTimestamp < $ksefPreStartDate) $hwmTimestamp = $ksefPreStartDate;
    if ($hwmTimestamp && $hwmTimestamp > $today) $hwmTimestamp = $today;
    $totalSpan = max($today - $ksefStartDate, 1);
    $hwmPercent = $hwmTimestamp ? round((($hwmTimestamp - $ksefStartDate) / $totalSpan) * 100, 1) : 0;
    if ($hwmPercent < 0) $hwmPercent = 0;
    if ($hwmPercent > 100) $hwmPercent = 100;
    $startLabel = dol_print_date($ksefStartDate, 'day');
    $hwmLabel = $hwmTimestamp ? dol_print_date($hwmTimestamp, 'day') : '';
    $todayLabel = dol_print_date($today, 'day');

    $timelineDividers = array();
    $spanMonths = (int) round(($today - $ksefStartDate) / (30.44 * 86400));
    $divStep = ($spanMonths <= 12) ? 'month' : '6months';
    $divDate = strtotime('+1 month', strtotime(date('Y-m-01', $ksefStartDate)));
    while ($divDate < $today) {
        $divPercent = round((($divDate - $ksefStartDate) / $totalSpan) * 100, 1);
        if ($divPercent > 2 && $divPercent < 98) {
            $timelineDividers[] = array('percent' => $divPercent, 'label' => dol_print_date($divDate, '%m/%Y'));
        }
        $divDate = strtotime(($divStep === 'month' ? '+1 month' : '+6 months'), $divDate);
    }

    if ($hwmPercent >= 95) {
        $barColor = '#4CAF50';
        $barStatus = $langs->trans("KSEF_SyncProgressUpToDate");
    } elseif ($hwmPercent >= 50) {
        $barColor = '#FF9800';
        $barStatus = $langs->trans("KSEF_SyncProgressPartial");
    } elseif ($hwmTimestamp) {
        $barColor = '#f44336';
        $barStatus = $langs->trans("KSEF_SyncProgressBehind");
    } else {
        $barColor = 'var(--ksef-text-faint)';
        $barStatus = $langs->trans("KSEF_SyncNotStarted");
    }

    print '<tr class="oddeven"><td colspan="2" style="padding: 8px;">';
    print '<div class="liste_titre" style="margin-bottom: 0; min-height: 20px; padding-bottom: 0;">' . $langs->trans("KSEF_SyncProgress") . '</div>';

    print '<div style="position: relative; height: 18px; font-size: 0.7em; color: var(--ksef-text-faint); font-weight: 600;">';
    foreach ($timelineDividers as $div) {
        if ($div['percent'] < 5 || $div['percent'] > 95) continue;
        print '<div style="position: absolute; left: ' . $div['percent'] . '%; transform: translateX(-50%); bottom: 0; text-align: center; white-space: nowrap;">' . $div['label'] . '</div>';
    }
    print '</div>';

    print '<div style="position: relative; height: 28px; background: var(--ksef-surface-border); border-radius: 3px; overflow: visible; border-left: 3px solid var(--ksef-text-muted); border-right: 3px solid var(--ksef-text-muted);">';
    if ($hwmPercent > 0) {
        print '<div style="position: absolute; top: 0; left: 0; height: 100%; width: ' . $hwmPercent . '%; background: ' . $barColor . '; transition: width 0.5s;"></div>';
    }
    if ($hwmTimestamp && $hwmPercent > 0 && $hwmPercent < 100) {
        print '<div style="position: absolute; top: -4px; left: ' . $hwmPercent . '%; width: 3px; height: calc(100% + 8px); background: var(--ksef-text-muted); z-index: 2; transform: translateX(-50%);"></div>';
    }
    foreach ($timelineDividers as $div) {
        if ($div['percent'] < 5 || $div['percent'] > 95) continue;
        print '<div style="position: absolute; top: 0; left: ' . $div['percent'] . '%; width: 0; height: 100%; border-left: 1px dashed rgba(0,0,0,0.25); z-index: 1;"></div>';
    }
    if (is_array($dot)) {
        $dotTs = min(max((int) $dot['ts'], $ksefStartDate), $today);
        $dotPercent = round((($dotTs - $ksefStartDate) / $totalSpan) * 100, 1);
        print '<span class="ksef-upo-dot classfortooltip" title="' . dol_escape_htmltag($dot['title']) . '" style="left: ' . $dotPercent . '%;"></span>';
    }
    print '</div>';

    print '<div style="position: relative; height: 36px; margin-top: 2px; font-size: 0.72em; font-weight: 600; overflow: visible;">';
    print '<div style="position: absolute; left: 0; top: 2px; text-align: left; white-space: nowrap; color: #333;">' . $startLabel . '</div>';
    if ($hwmTimestamp && $hwmPercent > 8 && $hwmPercent < 88) {
        print '<div style="position: absolute; left: ' . $hwmPercent . '%; text-align: center; transform: translateX(-50%); top: 2px; white-space: nowrap;">';
        print '<span style="color: ' . $barColor . ';">SYNC</span><br><span style="color: ' . $barColor . ';">' . $hwmLabel . '</span>';
        print '</div>';
    }
    print '<div style="position: absolute; right: 0; top: 2px; text-align: right; white-space: nowrap; color: #333;">' . $langs->trans("Today") . '<br>' . $todayLabel . '</div>';
    print '</div>';

    print '<div style="position: relative; text-align: center; margin-top: 0; font-size: 0.8em;">';
    print '<span style="color: ' . $barColor . '; font-weight: 600;">' . $barStatus . '</span>';
    if ($hwmTimestamp && $hwmPercent < 100) {
        print ' <span style="color: var(--ksef-text-faint);">(' . $hwmPercent . '%)</span>';
    }
    if ($adminLink) {
        print '<a href="#" id="ksef-admin-options-link" class="opacitymedium" style="position: absolute; right: 0; top: 0;"><span class="fa fa-cog paddingright"></span>' . $langs->trans("KSEF_AdminOptions") . '</a>';
    }
    print '</div>';

    print '</td></tr>';
}

/**
 * @brief Print audit notice
 * @param string $direction incoming, outgoing
 * @return void
 */
function ksefPrintSyncAuditNotice($direction)
{
    global $db, $conf, $langs;
    $dir = strtoupper($direction === 'outgoing' ? 'outgoing' : 'incoming');
    $running = getDolGlobalString('KSEF_SYNC_AUDIT_' . $dir, '');
    $done = getDolGlobalString('KSEF_SYNC_AUDIT_DONE_' . $dir, '');
    if ($running !== '') {
        print '<div class="info">' . $langs->trans('KSEF_SyncAuditRunning', (int) $running) . '</div>';
    } elseif ($done !== '') {
        print '<div class="ok">' . $langs->trans('KSEF_SyncAuditDone', (int) $done) . '</div>';
        require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
        dolibarr_del_const($db, 'KSEF_SYNC_AUDIT_DONE_' . $dir, $conf->entity);
    }
}

/**
 * @brief Admin options dialog
 * @param string $environment
 * @param string $clearDescKey Lang key
 * @return void
 */
function ksefPrintAdminOptionsDialog($environment, $clearDescKey)
{
    global $langs;
    print '<div id="ksef-admin-options-dialog" title="' . dol_escape_htmltag($langs->trans("KSEF_AdminOptions")) . '" style="display: none;">';
    print '<p class="opacitymedium">' . $langs->trans("KSEF_AdminOptionsDesc", ksefEnvironmentLabel($environment)) . '</p>';
    print '<table class="noborder centpercent">';
    print '<tr class="oddeven"><td class="nowraponall">';
    print '<a class="butAction" href="' . $_SERVER["PHP_SELF"] . '?action=reset&token=' . newToken() . '">';
    print '<span class="fa fa-undo"></span> ' . $langs->trans("KSEF_ResetSyncState");
    print '</a></td>';
    print '<td class="opacitymedium">' . $langs->trans("KSEF_AdminResetDesc") . '</td></tr>';
    print '<tr class="oddeven"><td class="nowraponall">';
    print '<a class="butActionDelete" href="' . $_SERVER["PHP_SELF"] . '?action=clearall&token=' . newToken() . '">';
    print '<span class="fa fa-trash"></span> ' . $langs->trans("KSEF_ClearAll");
    print '</a></td>';
    print '<td class="opacitymedium">' . $langs->trans($clearDescKey) . '</td></tr>';
    print '</table>';
    print '</div>';
    print '<script>
    $(document).ready(function() {
        $("#ksef-admin-options-link").on("click", function(e) {
            e.preventDefault();
            $("#ksef-admin-options-dialog").dialog({ modal: true, width: 520, resizable: false });
        });
    });
    </script>';
}

/**
 * @brief Accepted KSeF number
 * @param DoliDB $db
 * @param int $invoiceId
 * @return string|null
 */
function ksefAcceptedKsefNumber($db, $invoiceId)
{
    $sql = "SELECT ksef_number FROM " . MAIN_DB_PREFIX . "ksef_submissions";
    $sql .= " WHERE fk_facture = " . (int) $invoiceId . " AND status = 'ACCEPTED' AND ksef_number IS NOT NULL";
    $sql .= " AND environment = '" . $db->escape(ksefGetEnvironment()) . "'";
    $sql .= " ORDER BY date_submission DESC, rowid DESC LIMIT 1";
    $resql = $db->query($sql);
    if ($resql && ($obj = $db->fetch_object($resql))) {
        return (string) $obj->ksef_number;
    }
    return null;
}

/**
 * @brief Requires module note
 * @param string $moduleLabelKey
 * @return string
 */
function ksefRequiresModuleText($moduleLabelKey)
{
    global $langs;
    return '<span class="opacitymedium"><span class="fa fa-info-circle paddingright"></span>' . $langs->trans('KSEF_RequiresModule', $langs->transnoentitiesnoconv($moduleLabelKey)) . '</span>';
}

/**
 * @brief Translate error key
 * @param string $msg
 * @return string Not escaped
 */
function ksefTranslateError($msg)
{
    global $langs;
    $msg = (string) $msg;
    if ($msg !== '' && preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $msg)) {
        $langs->load('errors');
        return $langs->transnoentitiesnoconv($msg);
    }
    return $msg;
}

/**
 * @brief Trans HTML params
 * @param string $key
 * @param array $htmlParams Safe HTML
 * @return string
 */
function ksefTransHtml($key, $htmlParams)
{
    global $langs;
    $ph = array();
    foreach (array_values($htmlParams) as $i => $v) {
        $ph['__KSEFHTML' . $i . '__'] = (string) $v;
    }
    $args = array_merge(array($key), array_keys($ph));
    $out = call_user_func_array(array($langs, 'trans'), $args);
    return strtr($out, $ph);
}

/**
 * @brief Polish public holidays
 * @param int $year
 * @return array 'Y-m-d' => true
 */
function ksefPolishPublicHolidays($year)
{
    static $cache = array();
    $year = (int) $year;
    if (isset($cache[$year])) {
        return $cache[$year];
    }

    $a = $year % 19;
    $b = intdiv($year, 100);
    $c = $year % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $easterMonth = intdiv($h + $l - 7 * $m + 114, 31);
    $easterDay = (($h + $l - 7 * $m + 114) % 31) + 1;

    $days = array();
    $fixed = array('01-01', '05-01', '05-03', '08-15', '11-01', '11-11', '12-25', '12-26');
    if ($year >= 2011) {
        $fixed[] = '01-06';
    }
    if ($year >= 2025) {
        $fixed[] = '12-24';
    }
    foreach ($fixed as $md) {
        $days[$year . '-' . $md] = true;
    }
    // movable holidays
    foreach (array(0, 1, 49, 60) as $offset) {
        $days[gmdate('Y-m-d', gmmktime(0, 0, 0, $easterMonth, $easterDay + $offset, $year))] = true;
    }

    $cache[$year] = $days;
    return $days;
}

/**
 * @brief Is Polish holiday
 * @param int|string $date
 * @return bool
 */
function ksefIsPolishPublicHoliday($date)
{
    if (!is_numeric($date)) {
        $date = strtotime($date);
    }
    $holidays = ksefPolishPublicHolidays((int) date('Y', $date));
    return isset($holidays[date('Y-m-d', $date)]);
}

/**
 * @brief Is working day
 * @param int|string $date
 * @return bool
 */
function ksefIsWorkingDay($date)
{
    if (!is_numeric($date)) {
        $date = strtotime($date);
    }
    return ksefIsWeekDay($date) && !ksefIsPolishPublicHoliday($date);
}

/**
 * @brief Sign KOD II
 * @param string $data No scheme, no trailing '/'
 * @param string $privateKeyPem
 * @return string|false
 */
function ksefSignQrPath($data, $privateKeyPem)
{
    $privateKey = openssl_pkey_get_private($privateKeyPem);
    if (!$privateKey) {
        dol_syslog("ksefSignQrPath: Failed to load private key: " . openssl_error_string(), LOG_ERR);
        return false;
    }
    $keyDetails = openssl_pkey_get_details($privateKey);

    if ($keyDetails['type'] == OPENSSL_KEYTYPE_RSA) {
        // no PSS
        if (!class_exists('\phpseclib3\Crypt\PublicKeyLoader')) {
            require_once __DIR__ . '/vendor/autoload.php';
        }
        try {
            $key = \phpseclib3\Crypt\PublicKeyLoader::load($privateKeyPem);
            $signature = $key->withPadding(\phpseclib3\Crypt\RSA::SIGNATURE_PSS)
                ->withHash('sha256')
                ->withMGFHash('sha256')
                ->withSaltLength(32)
                ->sign($data);
        } catch (Exception $e) {
            dol_syslog("ksefSignQrPath: RSA-PSS signing failed: " . $e->getMessage(), LOG_ERR);
            return false;
        }
    } elseif ($keyDetails['type'] == OPENSSL_KEYTYPE_EC) {
        $signature = '';
        if (!openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            dol_syslog("ksefSignQrPath: Signing failed: " . openssl_error_string(), LOG_ERR);
            return false;
        }
        $curveName = $keyDetails['ec']['curve_name'] ?? 'prime256v1';
        $componentSize = 32;
        if (strpos($curveName, '384') !== false) {
            $componentSize = 48;
        } elseif (strpos($curveName, '521') !== false) {
            $componentSize = 66;
        }
        $signature = ksefConvertEcdsaDerToRaw($signature, $componentSize);
    } else {
        dol_syslog("ksefSignQrPath: unsupported key type " . $keyDetails['type'], LOG_ERR);
        return false;
    }

    if (!is_string($signature) || $signature === '') {
        return false;
    }
    return ksefBase64ToBase64URL(base64_encode($signature));
}

/**
 * @brief KOD II link
 * @param string $environment
 * @param string $sellerNip
 * @param string $invoiceHash SHA-256
 * @param string|null $contextNip
 * @param string|null $fa3Xml
 * @param string $contextType
 * @return string|false
 */
function ksefBuildCertificateVerificationUrl($environment, $sellerNip, $invoiceHash, $contextNip = null, $fa3Xml = null, $contextType = 'Nip')
{
    if (empty($environment)) {
        $environment = ksefGetEnvironment();
    }
    $environment = strtoupper($environment);

    if (!empty($fa3Xml)) {
        $xmlInfo = ksefXmlIssueInfo($fa3Xml);
        if ($xmlInfo['nip'] !== '') {
            $sellerNip = $xmlInfo['nip'];
        }
    }
    $sellerNip = ksefCleanNIP((string) $sellerNip);
    if (empty($contextNip)) {
        $contextNip = ksefSellerNip();
    }
    $contextNip = ksefCleanNIP((string) $contextNip);
    if ($contextNip === '') {
        $contextNip = $sellerNip;
    }
    $hashBin = base64_decode(ksefBase64URLToBase64((string) $invoiceHash), true);
    if ($sellerNip === '' || $hashBin === false || strlen($hashBin) !== 32) {
        dol_syslog("ksefBuildCertificateVerificationUrl: seller NIP or invoice hash missing", LOG_WARNING);
        return false;
    }

    $credentials = ksefLoadOfflineCertificate($environment);
    if (!$credentials) {
        dol_syslog("ksefBuildCertificateVerificationUrl: offline certificate for $environment not configured", LOG_WARNING);
        return false;
    }
    $serial = '';
    $certInfo = @openssl_x509_parse($credentials['certificate_pem']);
    if (is_array($certInfo) && !empty($certInfo['serialNumberHex'])) {
        $serial = $certInfo['serialNumberHex'];
    }
    if ($serial === '') {
        $serial = (string) $credentials['serial'];
    }
    $serial = strtoupper($serial);
    if ($serial === '') {
        dol_syslog("ksefBuildCertificateVerificationUrl: offline certificate serial unknown for $environment", LOG_WARNING);
        return false;
    }

    $base = rtrim(ksefGetQrBaseUrl($environment), '/');
    $scheme = 'https';
    if (preg_match('#^(https?)://#i', $base, $m)) {
        $scheme = strtolower($m[1]);
        $base = substr($base, strlen($m[0]));
    }
    $path = $base . '/certificate/' . $contextType . '/' . $contextNip . '/' . $sellerNip . '/' . $serial
        . '/' . ksefBase64ToBase64URL(base64_encode($hashBin));

    $signature = ksefSignQrPath($path, $credentials['private_key_pem']);
    if (!$signature) {
        return false;
    }
    return $scheme . '://' . $path . '/' . $signature;
}

/**
 * @brief Find replacement
 * @param DoliDB $db
 * @param int $sourceId
 * @param string $statuses SQL list
 * @param int $excludeId
 * @param bool $lock FOR UPDATE
 * @param string $element
 * @return object|null|false false on error
 */
function ksefFindReplacementOf($db, $sourceId, $statuses, $excludeId = 0, $lock = false, $element = 'facture')
{
    $kind = ksefInvoiceKind($element);
    $sql = "SELECT rowid, ref, fk_statut FROM " . MAIN_DB_PREFIX . $kind['table']
        . " WHERE fk_facture_source = " . ((int) $sourceId)
        . " AND type = " . Facture::TYPE_REPLACEMENT
        . " AND fk_statut IN (" . $db->sanitize($statuses) . ")"
        . " AND entity IN (" . getEntity($kind['entity']) . ")"
        . ((int) $excludeId > 0 ? " AND rowid <> " . ((int) $excludeId) : '')
        . " ORDER BY fk_statut ASC, rowid DESC"
        . ($lock ? " FOR UPDATE" : '');
    $res = $db->query($sql);
    if (!$res) {
        dol_syslog("ksefFindReplacementOf: " . $db->lasterror(), LOG_ERR);
        return false;
    }
    $obj = $db->fetch_object($res);
    $db->free($res);
    return $obj ? $obj : null;
}

/**
 * @brief Active replacement
 * @param DoliDB $db
 * @param int $sourceId
 * @param int $excludeId
 * @param bool $lock FOR UPDATE
 * @param string $element
 * @return object|null|false false on error
 */
function ksefGetActiveReplacement($db, $sourceId, $excludeId = 0, $lock = false, $element = 'facture')
{
    ksefInvoiceKind($element); // loads Facture constants
    return ksefFindReplacementOf($db, $sourceId, Facture::STATUS_VALIDATED . ',' . Facture::STATUS_CLOSED, $excludeId, $lock, $element);
}

/**
 * @brief Shortfall handling kind
 * @param string $code Close code
 * @param string $answer keep, ask
 * @return string '' if none
 */
function ksefShortfallKind($code, $answer = '')
{
    if (in_array($code, array('bankcharge', 'withholdingtax'), true)) {
        return 'paid';
    }
    $default = in_array($code, array('discount_vat', 'other', 'product_returned'), true) ? 'keep' : (in_array($code, array('badcustomer', 'badsupplier'), true) ? 'ask' : '');
    return ($default !== '' && in_array($answer, array('keep', 'ask'), true)) ? $answer : $default;
}

/**
 * @brief Forgiven shortfall part
 * @param Facture|FactureFournisseur $source
 * @param array $calc ksefCorrectionSettled()
 * @param float $credits
 * @param float $creditsMc
 * @param string $code Close code
 * @param string $answer keep, ask
 * @return array|null
 */
function ksefShortfall($source, $calc, $credits, $creditsMc, $code, $answer = '')
{
    $ans = ksefShortfallKind((string) $code, (string) $answer);
    $ttc = (float) price2num((float) $source->total_ttc - $calc['settled'] - $calc['keep'] - $credits, 'MT');
    $mttc = (float) price2num((float) $source->multicurrency_total_ttc - $calc['settled_mc'] - $calc['keep_mc'] - $creditsMc, 'MT');
    if ($ans === '' || ($ttc < 0.005 && $mttc < 0.005)) {
        return null;
    }
    return array('ttc' => max(0.0, $ttc), 'mttc' => max(0.0, $mttc), 'code' => (string) $code, 'ans' => $ans);
}

/**
 * @brief Recorded shortfall covers
 * @param DoliDB $db
 * @param array $ids
 * @param string $element
 * @return array
 */
function ksefShortfallCovers($db, $ids, $element = 'facture')
{
    $out = array('recorded' => false, 'keep' => 0.0, 'keep_mc' => 0.0, 'paid' => 0.0, 'paid_mc' => 0.0, 'out' => 0.0, 'out_mc' => 0.0, 'absorb' => array());
    $ids = array_filter(array_map('intval', (array) $ids));
    if (!$ids || !ksefCorrectionStateStorable($db, $element)) {
        return $out;
    }
    $res = $db->query("SELECT ksef_correction_state FROM " . MAIN_DB_PREFIX . ksefInvoiceKind($element)['extrafields'] . "_extrafields"
        . " WHERE fk_object IN (" . implode(',', $ids) . ") AND (ksef_correction_state LIKE '%\"short\":%' OR ksef_correction_state LIKE '%\"abs\":%')");
    while ($res && ($o = $db->fetch_object($res))) {
        $s = json_decode((string) $o->ksef_correction_state, true);
        if (is_array($s) && isset($s['abs']) && is_array($s['abs'])) {
            $out['out'] += (float) ($s['abs']['out'] ?? 0);
            $out['out_mc'] += (float) ($s['abs']['out_mc'] ?? 0);
            $out['absorb'] = array_merge($out['absorb'], array_map('intval', (array) ($s['absorb'] ?? array())));
        }
        if (!is_array($s) || !isset($s['short']) || !is_array($s['short'])) {
            continue;
        }
        $out['recorded'] = true;
        $a = (string) ($s['short']['ans'] ?? '');
        if ($a === 'keep' || $a === 'paid') {
            $out[$a] += (float) $s['short']['ttc'];
            $out[$a . '_mc'] += (float) $s['short']['mttc'];
        }
    }
    return $out;
}

/**
 * @brief Draft shortfall question
 * @param DoliDB $db
 * @param Facture|FactureFournisseur $invoice
 * @return array|null
 */
function ksefShortfallQuestion($db, $invoice)
{
    global $conf;
    $kind = ksefInvoiceKind($invoice);
    $st = (int) (isset($invoice->status) ? $invoice->status : $invoice->statut);
    if ((int) $invoice->type !== Facture::TYPE_REPLACEMENT || empty($invoice->fk_facture_source) || $st !== Facture::STATUS_DRAFT) {
        return null;
    }
    $cls = $kind['class'];
    $src = new $cls($db);
    if ($src->fetch((int) $invoice->fk_facture_source) <= 0 || (int) (isset($src->status) ? $src->status : $src->statut) !== Facture::STATUS_CLOSED) {
        return null;
    }
    $src->fetch_optionals();
    $calc = ksefCorrectionSettled($db, $src);
    $res = $db->query("SELECT COALESCE(SUM(amount_ttc), 0) AS a, COALESCE(SUM(multicurrency_amount_ttc), 0) AS m FROM " . MAIN_DB_PREFIX . "societe_remise_except"
        . " WHERE " . $kind['col'] . " = " . (int) $src->id . " AND rowid <> " . (int) ($src->array_options['options_ksef_correction_discount_id'] ?? 0));
    $o = $res ? $db->fetch_object($res) : null;
    if (!$calc || !$o) {
        return null;
    }
    $short = ksefShortfall($src, $calc, (float) $o->a, (float) $o->m, (string) $src->close_code, (string) (ksefCorrectionState($invoice)['short_ans'] ?? ''));
    if (!$short || $short['ans'] === 'paid') {
        return null;
    }
    $mc = !empty($calc['root']->multicurrency_code) && $calc['root']->multicurrency_code != $conf->currency;
    $credits = (float) ($mc ? $o->m : $o->a);
    $carried = $mc ? $calc['settled_mc'] + $calc['keep_mc'] : $calc['settled'] + $calc['keep'];
    $value = (float) ($mc ? $invoice->multicurrency_total_ttc : $invoice->total_ttc);
    $ask = (float) price2num($value - $credits - $carried, 'MT');
    if ($ask < 0.005) {
        return null;
    }
    return array('short' => $short, 'ref' => $src->ref, 'value' => $value, 'paid' => (float) price2num($credits + ($mc ? $calc['settled_mc'] : $calc['settled']), 'MT'),
        'keep' => max(0.0, (float) price2num($ask - $short[$mc ? 'mttc' : 'ttc'], 'MT')), 'ask' => $ask, 'forgiven' => $short[$mc ? 'mttc' : 'ttc'],
        'currency' => $mc ? $calc['root']->multicurrency_code : $conf->currency);
}

/**
 * @brief Excess credit rows
 * @param DoliDB $db
 * @param array $state
 * @param string $element
 * @return array|false|null false on error
 */
function ksefExcessCreditRows($db, $state, $element = 'facture')
{
    $k = ksefInvoiceKind($element);
    $id = (int) ($state['excess'] ?? 0);
    $res = $db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "societe_remise_except WHERE rowid = " . $id);
    if (!$res) {
        dol_syslog("ksefExcessCreditRows: " . $db->lasterror(), LOG_ERR);
        return false;
    }
    if ($db->fetch_object($res)) {
        return array($id);
    }
    if (empty($state['excess_root'])) {
        return array();
    }
    $res = $db->query("SELECT rowid, amount_ttc FROM " . MAIN_DB_PREFIX . "societe_remise_except WHERE " . $k['col_source'] . " = " . (int) $state['excess_root']
        . " AND description LIKE '" . $db->escape($k['excess']) . "%' AND rowid > " . $id
        . (!empty($state['excess_datec']) ? " AND datec = '" . $db->escape($state['excess_datec']) . "'" : ''));
    if (!$res) {
        dol_syslog("ksefExcessCreditRows: " . $db->lasterror(), LOG_ERR);
        return false;
    }
    $ids = array();
    $sum = 0.0;
    while ($o = $db->fetch_object($res)) {
        $ids[] = (int) $o->rowid;
        $sum += (float) $o->amount_ttc;
    }
    if ($ids && $sum - (float) ($state['excess_amt'] ?? 0) > 0.005) {
        return null;
    }
    return $ids;
}

/**
 * @brief Recover excess credit
 * @param DoliDB $db
 * @param int $rootId
 * @param float $amount
 * @param bool $foreign
 * @param string $element
 * @return array|false false on error
 */
function ksefRecoverExcessCredit($db, $rootId, $amount, $foreign, $element = 'facture')
{
    $k = ksefInvoiceKind($element);
    $T = MAIN_DB_PREFIX . "societe_remise_except";
    $out = array('rows' => array(), 'ttc' => 0.0, 'mttc' => 0.0);
    $note = $element === 'facture' && ksefNoteChainRoot($db, (int) $rootId) === (int) $rootId;
    $res = $db->query("SELECT * FROM " . $T . " WHERE " . $k['col_source'] . " = " . (int) $rootId
        . " AND (description LIKE '" . $db->escape($k['excess']) . "%'" . ($note ? " OR description LIKE '(CREDIT_NOTE)%'" : '') . ")"
        . " AND " . $k['col'] . " IS NULL AND " . $k['col_line'] . " IS NULL ORDER BY " . ($note ? "CASE WHEN description LIKE '(CREDIT_NOTE)%' THEN 1 ELSE 0 END, " : '') . "rowid DESC");
    if (!$res) {
        dol_syslog("ksefRecoverExcessCredit: " . $db->lasterror(), LOG_ERR);
        return false;
    }
    $rows = array();
    while ($o = $db->fetch_object($res)) {
        $rows[] = (array) $o;
    }
    $cols = array('amount_ht', 'amount_tva', 'amount_ttc', 'multicurrency_amount_ht', 'multicurrency_amount_tva', 'multicurrency_amount_ttc');
    $left = (float) $amount;
    foreach ($rows as $r) {
        $amt = (float) $r[$foreign ? 'multicurrency_amount_ttc' : 'amount_ttc'];
        if ($left < 0.005) {
            break;
        }
        if ($amt < 0.005) {
            continue;
        }
        if ($amt - $left < 0.005) {
            if (!$db->query("DELETE FROM " . $T . " WHERE rowid = " . (int) $r['rowid'])) {
                dol_syslog("ksefRecoverExcessCredit: " . $db->lasterror(), LOG_ERR);
                return false;
            }
            $out['rows'][] = array('row' => $r);
            $d = array('amount_ttc' => (float) $r['amount_ttc'], 'multicurrency_amount_ttc' => (float) $r['multicurrency_amount_ttc']);
        } else {
            $d = array();
            $set = array();
            foreach ($cols as $c) {
                $d[$c] = (float) price2num((float) $r[$c] * $left / $amt, 'MT');
                $set[] = $c . " = " . $c . " - " . $d[$c];
            }
            if (!$db->query("UPDATE " . $T . " SET " . implode(', ', $set) . " WHERE rowid = " . (int) $r['rowid'])) {
                dol_syslog("ksefRecoverExcessCredit: " . $db->lasterror(), LOG_ERR);
                return false;
            }
            $out['rows'][] = array('id' => (int) $r['rowid'], 'a' => (float) price2num((float) $r['amount_ttc'] - $d['amount_ttc'], 'MT'), 'd' => $d);
        }
        $left -= $foreign ? $d['multicurrency_amount_ttc'] : $d['amount_ttc'];
        $out['ttc'] += $d['amount_ttc'];
        $out['mttc'] += $d['multicurrency_amount_ttc'];
    }
    $out['ttc'] = (float) price2num($out['ttc'], 'MT');
    $out['mttc'] = (float) price2num($out['mttc'], 'MT');
    return $out;
}

/**
 * @brief Restore recovered credit
 * @param DoliDB $db
 * @param array $recov Undo records
 * @param string $element
 * @return array
 */
function ksefRestoreRecoveredCredit($db, $recov, $element = 'facture')
{
    $k = ksefInvoiceKind($element);
    $T = MAIN_DB_PREFIX . "societe_remise_except";
    $out = array('result' => -1, 'used_by' => array(), 'changed' => false);
    foreach ($recov as $r) {
        if (empty($r['id'])) {
            continue;
        }
        $res = $db->query("SELECT amount_ttc, " . $k['col'] . " AS l, " . $k['col_line'] . " AS ll FROM " . $T . " WHERE rowid = " . (int) $r['id']);
        if (!$res) {
            dol_syslog("ksefRestoreRecoveredCredit: " . $db->lasterror(), LOG_ERR);
            return $out;
        }
        $o = $db->fetch_object($res);
        if ($o && (!empty($o->l) || !empty($o->ll))) {
            $out['used_by'] = (array) ksefDiscountUsers($db, "rc.rowid = " . (int) $r['id'], $element);
            return $out;
        }
        if (!$o || abs((float) $o->amount_ttc - (float) $r['a']) > 0.005) {
            $out['changed'] = true;
            return $out;
        }
    }
    foreach ($recov as $r) {
        if (!empty($r['id'])) {
            $set = array();
            foreach ($r['d'] as $c => $v) {
                $set[] = preg_replace('/[^a-z_]/', '', $c) . " = " . preg_replace('/[^a-z_]/', '', $c) . " + " . (float) $v;
            }
            $sql = "UPDATE " . $T . " SET " . implode(', ', $set) . " WHERE rowid = " . (int) $r['id'];
        } else {
            $vals = array();
            foreach ($r['row'] as $c => $v) {
                $vals[preg_replace('/[^a-z0-9_]/', '', $c)] = $v === null ? 'NULL' : "'" . $db->escape((string) $v) . "'";
            }
            $sql = "INSERT INTO " . $T . " (" . implode(', ', array_keys($vals)) . ") VALUES (" . implode(', ', $vals) . ")";
        }
        if (!$db->query($sql)) {
            dol_syslog("ksefRestoreRecoveredCredit: " . $db->lasterror(), LOG_ERR);
            return $out;
        }
    }
    $out['result'] = 1;
    return $out;
}

/**
 * @brief Delete copied discounts
 * @param DoliDB $db
 * @param int $invoiceId
 * @param int $lineId 0: all
 * @return bool
 */
function ksefDeleteCopiedDiscounts($db, $invoiceId, $lineId = 0)
{
    $P = MAIN_DB_PREFIX;
    return (bool) $db->query("DELETE FROM " . $P . "societe_remise_except WHERE (fk_facture_source IS NULL OR fk_facture_source = 0) AND fk_facture IS NULL"
        . " AND fk_facture_line IN (SELECT d.rowid FROM " . $P . "facturedet AS d JOIN " . $P . "facture AS f ON f.rowid = d.fk_facture"
        . " WHERE f.rowid = " . (int) $invoiceId . " AND f.type = 1 AND d.fk_prev_id > 0" . ((int) $lineId > 0 ? " AND d.rowid = " . (int) $lineId : '') . ")");
}

/**
 * @brief Zero discount lines
 * @param DoliDB $db
 * @param User $user
 * @param int $invoiceId
 * @param bool $unlink Before copy
 * @return bool
 */
function ksefZeroDiscountLines($db, $user, $invoiceId, $unlink)
{
    $P = MAIN_DB_PREFIX;
    if ($unlink) {
        return (bool) $db->query("UPDATE " . $P . "facturedet SET fk_remise_except = NULL WHERE fk_facture = " . (int) $invoiceId
            . " AND ABS(total_ttc) < 0.005 AND fk_remise_except IN (SELECT rowid FROM " . $P . "societe_remise_except WHERE ABS(amount_ht) < 0.005 AND ABS(amount_ttc) < 0.005)");
    }
    $res = $db->query("SELECT d.rowid, d.fk_prev_id FROM " . $P . "facturedet AS d JOIN " . $P . "facturedet AS p ON p.rowid = d.fk_prev_id"
        . " WHERE d.fk_facture = " . (int) $invoiceId . " AND (d.fk_remise_except IS NULL OR d.fk_remise_except = 0)"
        . " AND EXISTS (SELECT 1 FROM " . $P . "societe_remise_except AS r WHERE r.fk_facture_line = p.rowid)");
    if (!$res) {
        return false;
    }
    $lines = array();
    while ($o = $db->fetch_object($res)) {
        $lines[(int) $o->rowid] = (int) $o->fk_prev_id;
    }
    require_once DOL_DOCUMENT_ROOT . '/core/class/discount.class.php';
    foreach ($lines as $lineId => $prevId) {
        $resRow = $db->query("SELECT MIN(rowid) AS id FROM " . $P . "societe_remise_except WHERE fk_facture_line = " . $prevId);
        $o = $resRow ? $db->fetch_object($resRow) : null;
        $src = new DiscountAbsolute($db);
        if (!$o || $src->fetch((int) $o->id) <= 0
            || !$db->query("UPDATE " . $P . "facturedet SET fk_remise_except = " . (int) $o->id . " WHERE rowid = " . $prevId)) {
            return false;
        }
        $copy = new DiscountAbsolute($db);
        $copy->socid = $src->socid;
        $copy->description = $src->description;
        $copy->tva_tx = $src->tva_tx;
        $copy->vat_src_code = $src->vat_src_code;
        $copy->amount_ht = $copy->amount_tva = $copy->amount_ttc = 0;
        if ($copy->create($user) <= 0
            || !$db->query("UPDATE " . $P . "facturedet SET fk_remise_except = " . (int) $copy->id . " WHERE rowid = " . $lineId)
            || !$db->query("UPDATE " . $P . "societe_remise_except SET fk_facture_line = " . $lineId . " WHERE rowid = " . (int) $copy->id)) {
            return false;
        }
    }
    return true;
}

/**
 * @brief Exact unit price
 * @param object $line
 * @param bool $foreign Multicurrency
 * @return float
 */
function ksefLineExactUnitPrice($line, $foreign)
{
    $qty = (float) $line->qty;
    $rem = (float) $line->remise_percent;
    $sub = (float) ($foreign ? $line->multicurrency_subprice : $line->subprice);
    $total = (float) ($foreign ? $line->multicurrency_total_ht : $line->total_ht);
    if (abs($qty) < 1e-9 || $rem >= 100) {
        return $sub;
    }
    $pu = $total / ($qty * (1 - $rem / 100));
    return abs($pu - $sub) <= pow(10, -getDolGlobalInt('MAIN_MAX_DECIMALS_UNIT', 5)) ? $pu : $sub;
}

/**
 * @brief Send in flight
 * @param DoliDB $db
 * @param int $noteId
 * @return bool
 */
function ksefNoteSendInFlight($db, $noteId)
{
    if ((int) $noteId <= 0) {
        return false;
    }
    dol_include_once('/ksef/class/ksef_submission.class.php');
    $sub = new KsefSubmission($db);
    if ($sub->fetchByInvoice((int) $noteId, ksefGetEnvironment()) <= 0) {
        return false;
    }
    return in_array((string) $sub->status, array(KsefSubmission::STATUS_PENDING, KsefSubmission::STATUS_SUBMITTED, KsefSubmission::STATUS_TIMEOUT), true);
}

/**
 * @brief TCPDF throws errors
 * @return void
 */
function ksefPdfThrowErrors()
{
    global $conf;
    if (!isset($conf->global->TCPDF_THROW_ERRORS_INSTEAD_OF_DIE)) {
        $conf->global->TCPDF_THROW_ERRORS_INSTEAD_OF_DIE = 1;
    }
    if (defined('K_TCPDF_EXTERNAL_CONFIG') && !defined('K_TCPDF_THROW_EXCEPTION_ERROR')) {
        define('K_TCPDF_THROW_EXCEPTION_ERROR', true);
    }
}

/**
 * @brief Regenerate main PDF
 * @param DoliDB $db
 * @param int $invoice_id
 * @param string &$errorMsg
 * @return int 1 ok, 0 skipped, -1 error
 */
function ksefRegenerateMainPdf($db, $invoice_id, &$errorMsg = '')
{
    global $conf, $langs;
    $errorMsg = '';
    if (getDolGlobalString('MAIN_DISABLE_PDF_AUTOUPDATE')) {
        return 0;
    }
    ksefPdfThrowErrors();
    try {
        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
        $invoice = new Facture($db);
        if ($invoice->fetch((int) $invoice_id) <= 0) {
            $errorMsg = 'Invoice ' . (int) $invoice_id . ' not found';
            return -1;
        }
        $model = $invoice->model_pdf ?: getDolGlobalString('FACTURE_ADDON_PDF');
        if (empty($model)) {
            return 0;
        }
        $outputlangs = $langs;
        if (getDolGlobalInt('MAIN_MULTILANGS')) {
            $invoice->fetch_thirdparty();
            $newlang = !empty($invoice->thirdparty->default_lang) ? $invoice->thirdparty->default_lang : '';
            if ($newlang !== '') {
                $outputlangs = new Translate('', $conf);
                $outputlangs->setDefaultLang($newlang);
                $outputlangs->load('products');
            }
        }
        $hidedetails = getDolGlobalString('MAIN_GENERATE_DOCUMENTS_HIDE_DETAILS') ? 1 : 0;
        $hidedesc = getDolGlobalString('MAIN_GENERATE_DOCUMENTS_HIDE_DESC') ? 1 : 0;
        $hideref = getDolGlobalString('MAIN_GENERATE_DOCUMENTS_HIDE_REF') ? 1 : 0;
        $r = $invoice->generateDocument($model, $outputlangs, $hidedetails, $hidedesc, $hideref);
        if ($r <= 0) {
            $errorMsg = $invoice->error ?: implode(', ', (array) $invoice->errors);
            dol_syslog("ksefRegenerateMainPdf invoice " . $invoice->ref . " not regenerated: " . $errorMsg, LOG_ERR);
            return -1;
        }
        dol_syslog("ksefRegenerateMainPdf invoice " . $invoice->ref . " regenerated with its KSeF data", LOG_INFO);
        return 1;
    } catch (\Throwable $e) {
        $errorMsg = $e->getMessage();
        dol_syslog("ksefRegenerateMainPdf ERROR: " . get_class($e) . ': ' . $errorMsg, LOG_ERR);
        return -1;
    }
}

/**
 * @brief Short submission error
 * @param int|string|null $code
 * @param string|null $message
 * @param int $max Max length
 * @return string Not escaped
 */
function ksefShortSubmissionError($code, $message, $max = 150)
{
    $parts = array();
    $code = trim((string) $code);
    if ($code !== '' && ctype_digit($code)) {
        $parts[] = $code . ': ' . ksefKsefCodeLabel($code);
    }
    $message = trim((string) $message);
    do {
        $before = $message;
        $message = trim((string) preg_replace('/^(KSeF Error(\s+\d+)?|Invoice submission error|Invoice rejected by KSeF \(\d+\)|Failed to submit invoice):\s*/i', '', $message));
    } while ($message !== $before);
    if ($message !== '') {
        $parts[] = dol_trunc($message, $max);
    }
    return implode(' - ', $parts);
}

/**
 * @brief Sees all customers
 * @param User $user
 * @return bool
 */
function ksefUserSeesAllCustomers($user)
{
    return empty($user->socid) && (bool) $user->hasRight('societe', 'client', 'voir');
}

/**
 * @brief Can read sales
 * @param User $user
 * @param KsefSales|object $row
 * @return bool
 */
function ksefUserCanReadSales($user, $row)
{
    if (!$user->hasRight('ksef', 'read') || !$user->hasRight('facture', 'lire')) {
        return false;
    }
    return ksefUserSeesAllCustomers($user) || (!empty($row->fk_facture) && ksefUserCanReadInvoice($user, (int) $row->fk_facture));
}

/**
 * @brief Invoice visibility SQL
 * @param User $user
 * @param string $alias llx_facture alias
 * @return string " AND ..."
 */
function ksefSqlInvoiceVisibility($user, $alias = 'f')
{
    $a = preg_replace('/[^A-Za-z0-9_]/', '', (string) $alias);
    $sql = " AND " . $a . ".entity IN (" . getEntity('invoice') . ")";
    if (!empty($user->socid)) {
        $sql .= " AND " . $a . ".fk_soc = " . (int) $user->socid;
    } elseif (!$user->hasRight('societe', 'client', 'voir')) {
        $sql .= " AND EXISTS (SELECT 1 FROM " . MAIN_DB_PREFIX . "societe_commerciaux as ksc WHERE ksc.fk_soc = " . $a . ".fk_soc AND ksc.fk_user = " . (int) $user->id . ")";
    }
    return $sql;
}

/**
 * @brief Submission QR codes
 * @param object $row
 * @param string $sellerNip Fallback
 * @param int|string|null $issueDate Fallback
 * @param bool $withKod2
 * @return array
 */
function ksefQrCodesForSubmission($row, $sellerNip = '', $issueDate = null, $withKod2 = true)
{
    $number = (string) ($row->ksef_number ?? '');
    if (!ksefIsRealKsefNumber($number)) {
        $number = '';
    }
    $offline = !empty($row->offline_mode) || (($row->status ?? '') === 'OFFLINE');
    $out = array('offline' => $offline, 'number' => $number, 'kod1' => '', 'kod1_label' => '', 'kod2' => '');
    if (!$offline && $number === '') {
        return $out;
    }

    $hash = ksefSubmissionHash($row);
    if ($hash === '') {
        return $out;
    }
    $xml = !empty($row->fa3_xml) ? (string) $row->fa3_xml : null;
    $env = !empty($row->environment) ? $row->environment : ksefGetEnvironment();
    $info = $xml !== null ? ksefXmlIssueInfo($xml) : array('date' => '', 'nip' => '');
    $nip = $info['nip'] !== '' ? $info['nip'] : ksefCleanNIP((string) $sellerNip);
    $date = $info['date'] !== '' ? $info['date'] : $issueDate;

    try {
        $out['kod1'] = (string) ksefGetVerificationURL($number !== '' ? $number : null, $hash, $env, $nip !== '' ? $nip : null, $date, $xml);
    } catch (Exception $e) {
        dol_syslog("ksefQrCodesForSubmission: KOD I - " . $e->getMessage(), LOG_WARNING);
    }
    $out['kod1_label'] = $number !== '' ? $number : ($offline ? 'OFFLINE' : '');

    if ($offline && $withKod2) {
        $kod2 = ksefBuildCertificateVerificationUrl($env, $nip, $hash, null, $xml);
        $out['kod2'] = $kod2 ? (string) $kod2 : '';
    }
    return $out;
}

/**
 * @brief Recompute offline deadlines
 * @param DoliDB $db
 * @return int Rows, -1 on error
 */
function ksefRecomputeOfflineDeadlines($db)
{
    $res = $db->query("SELECT s.rowid, s.offline_deadline, f.datef FROM " . MAIN_DB_PREFIX . "ksef_submissions as s"
        . " INNER JOIN " . MAIN_DB_PREFIX . "facture as f ON f.rowid = s.fk_facture"
        . " WHERE (s.offline_mode IS NOT NULL OR s.status = 'OFFLINE') AND s.status <> 'ACCEPTED'"
        . " AND s.offline_deadline IS NOT NULL AND f.datef IS NOT NULL");
    if (!$res) {
        dol_syslog("ksefRecomputeOfflineDeadlines: " . $db->lasterror(), LOG_ERR);
        return -1;
    }
    $rows = array();
    while ($obj = $db->fetch_object($res)) {
        $rows[] = $obj;
    }
    $db->free($res);
    $updated = 0;
    foreach ($rows as $obj) {
        $deadline = ksefCalculateOfflineDeadline($db->jdate($obj->datef));
        if ($deadline <= (int) $obj->offline_deadline) {
            continue;
        }
        if ($db->query("UPDATE " . MAIN_DB_PREFIX . "ksef_submissions SET offline_deadline = " . (int) $deadline . " WHERE rowid = " . (int) $obj->rowid)) {
            $updated++;
        } else {
            dol_syslog("ksefRecomputeOfflineDeadlines: row " . (int) $obj->rowid . " - " . $db->lasterror(), LOG_ERR);
        }
    }
    return $updated;
}

/**
 * @brief Apply outage deadlines
 * @param DoliDB $db
 * @param string $source Latarnia source
 * @return int Rows, -1 on error
 */
function ksefApplyOutageDeadlines($db, $source)
{
    dol_include_once('/ksef/class/ksef_latarnia.class.php');
    $events = KsefLatarnia::storedEvents($source);
    if (empty($events)) {
        return 0;
    }
    $res = $db->query("SELECT s.rowid, s.offline_deadline, s.environment, f.datef FROM " . MAIN_DB_PREFIX . "ksef_submissions as s"
        . " INNER JOIN " . MAIN_DB_PREFIX . "facture as f ON f.rowid = s.fk_facture"
        . " WHERE (s.offline_mode IS NOT NULL OR s.status = 'OFFLINE') AND s.status <> 'ACCEPTED'"
        . " AND s.offline_deadline IS NOT NULL AND f.datef IS NOT NULL");
    if (!$res) {
        dol_syslog("ksefApplyOutageDeadlines: " . $db->lasterror(), LOG_ERR);
        return -1;
    }
    $rows = array();
    while ($obj = $db->fetch_object($res)) {
        if (KsefLatarnia::environmentLabel($obj->environment) === $source) {
            $rows[] = $obj;
        }
    }
    $db->free($res);
    $updated = 0;
    foreach ($rows as $obj) {
        $info = ksefOfflineDeadlineInfo($db->jdate($obj->datef), $events, (int) $obj->offline_deadline);
        if ($info['state'] !== 'due' || $info['deadline'] <= (int) $obj->offline_deadline) {
            continue;
        }
        if ($db->query("UPDATE " . MAIN_DB_PREFIX . "ksef_submissions SET offline_deadline = " . (int) $info['deadline'] . " WHERE rowid = " . (int) $obj->rowid)) {
            $updated++;
        } else {
            dol_syslog("ksefApplyOutageDeadlines: row " . (int) $obj->rowid . " - " . $db->lasterror(), LOG_ERR);
        }
    }
    return $updated;
}

/**
 * @brief Due offline rows
 * @param DoliDB $db
 * @param array $subs fetchCronJobs() rows
 * @param int $hours_ahead
 * @return array
 */
function ksefEffectiveDueRows($db, $subs, $hours_ahead = 24)
{
    $limit = dol_now() + $hours_ahead * 3600;
    $out = array();
    foreach ((array) $subs as $sub) {
        $info = ksefOfflineDeadlineForInvoice($db, $sub->fk_facture, $sub->environment, (int) $sub->offline_deadline);
        if ($info['state'] !== 'due' || $info['deadline'] > $limit) {
            continue;
        }
        $sub->offline_deadline = $info['deadline'];
        $out[] = $sub;
    }
    usort($out, function ($a, $b) {
        return $a->offline_deadline - $b->offline_deadline;
    });
    return $out;
}

/**
 * @brief Offline without deadline
 * @param DoliDB $db
 * @return array Invoice ids
 */
function ksefOfflineNoDeadlineInvoices($db)
{
    dol_include_once('/ksef/class/ksef_latarnia.class.php');
    if (getDolGlobalString('KSEF_LATARNIA_EVENTS', '') === '') {
        return array();
    }
    $res = $db->query("SELECT s.fk_facture, s.offline_deadline, s.environment, f.datef FROM " . MAIN_DB_PREFIX . "ksef_submissions as s"
        . " INNER JOIN " . MAIN_DB_PREFIX . "facture as f ON f.rowid = s.fk_facture"
        . " WHERE s.offline_mode IS NOT NULL AND s.status <> 'ACCEPTED' AND s.offline_deadline IS NOT NULL AND f.datef IS NOT NULL");
    if (!$res) {
        dol_syslog("ksefOfflineNoDeadlineInvoices: " . $db->lasterror(), LOG_ERR);
        return array();
    }
    $events = array();
    $ids = array();
    while ($obj = $db->fetch_object($res)) {
        $source = KsefLatarnia::environmentLabel($obj->environment);
        if (!isset($events[$source])) {
            $events[$source] = KsefLatarnia::storedEvents($source);
        }
        if (empty($events[$source])) {
            continue;
        }
        $info = ksefOfflineDeadlineInfo($db->jdate($obj->datef), $events[$source], (int) $obj->offline_deadline);
        if ($info['state'] !== 'due') {
            $ids[(int) $obj->fk_facture] = (int) $obj->fk_facture;
        }
    }
    $db->free($res);
    return array_values($ids);
}

/**
 * @brief Production test-offline rows
 * @param DoliDB $db
 * @return array Row ids
 */
function ksefProductionIssuedTestOfflineRows($db)
{
    $res = $db->query("SELECT rowid, fk_facture, environment FROM " . MAIN_DB_PREFIX . "ksef_submissions"
        . " WHERE offline_mode IS NOT NULL AND status <> 'ACCEPTED' AND offline_deadline IS NOT NULL AND environment <> 'PRODUCTION'");
    if (!$res) {
        dol_syslog("ksefProductionIssuedTestOfflineRows: " . $db->lasterror(), LOG_ERR);
        return array();
    }
    $rows = array();
    while ($obj = $db->fetch_object($res)) {
        $rows[] = $obj;
    }
    $db->free($res);
    $ids = array();
    $issued = array();
    foreach ($rows as $obj) {
        $key = (int) $obj->fk_facture . '|' . $obj->environment;
        if (!isset($issued[$key])) {
            $issued[$key] = (bool) ksefProductionIssuedBlock($db, (int) $obj->fk_facture, (string) $obj->environment);
        }
        if ($issued[$key]) {
            $ids[] = (int) $obj->rowid;
        }
    }
    return $ids;
}

/**
 * @brief Payment terms rows
 * @param string $fa3xml
 * @return array
 */
function ksefPaymentTermsAccountsRows($fa3xml)
{
    global $db, $langs;

    $out = array('terms' => '', 'accounts' => '');
    if (empty($fa3xml)) return $out;
    dol_include_once('/ksef/class/fa3_parser.class.php');
    $parser = new FA3Parser($db);
    $parsed = $parser->parse($fa3xml);
    if (empty($parsed['payment'])) return $out;
    $pay = $parsed['payment'];
    $langs->loadLangs(array("ksef@ksef", "banks"));

    $day = function ($iso) {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string) $iso, $m)) {
            return dol_print_date(dol_mktime(12, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]), 'day');
        }
        return dol_escape_htmltag((string) $iso);
    };

    $terms = !empty($pay['due_dates']) ? $pay['due_dates'] : array();
    $hasDesc = false;
    $dated = 0;
    foreach ($terms as $term) {
        if (!empty($term['description'])) $hasDesc = true;
        if (!empty($term['date'])) $dated++;
    }
    if (count($terms) > 1 || $hasDesc) {
        $lines = array();
        foreach ($terms as $term) {
            $parts = array();
            if (!empty($term['date'])) $parts[] = $day($term['date']);
            if (!empty($term['description'])) $parts[] = dol_escape_htmltag($term['description']);
            $lines[] = implode(' - ', $parts);
        }
        $html = implode('<br>', $lines);
        if ($dated > 1) {
            $html .= '<br><span class="opacitymedium small">' . $langs->trans('KSEF_PaymentTermsEarliestNote') . '</span>';
        }
        $out['terms'] = '<tr><td>' . $langs->trans('KSEF_PaymentTermsAll') . '</td><td>' . $html . '</td></tr>';
    }

    $accounts = array();
    foreach ((!empty($pay['bank_accounts']) ? $pay['bank_accounts'] : array()) as $acc) {
        $accounts[] = array($acc, false);
    }
    foreach ((!empty($pay['factor_bank_accounts']) ? $pay['factor_bank_accounts'] : array()) as $acc) {
        $accounts[] = array($acc, true);
    }
    if (!empty($accounts)) {
        $lines = array();
        foreach ($accounts as $entry) {
            list($acc, $factor) = $entry;
            $line = '<span class="nowraponall">' . dol_escape_htmltag($acc['bank_account']) . '</span>';
            $extra = array();
            if (!empty($acc['bank_swift'])) $extra[] = 'SWIFT: ' . dol_escape_htmltag($acc['bank_swift']);
            if (!empty($acc['bank_name'])) $extra[] = dol_escape_htmltag($acc['bank_name']);
            if (!empty($acc['bank_description'])) $extra[] = dol_escape_htmltag($acc['bank_description']);
            if ($factor) $extra[] = $langs->trans('KSEF_BankAccountFactor');
            if (!empty($extra)) $line .= ' <span class="opacitymedium">(' . implode(', ', $extra) . ')</span>';
            $lines[] = $line;
        }
        $label = count($accounts) > 1 ? $langs->trans('KSEF_BankAccountsAll') : $langs->trans('BankAccount');
        $out['accounts'] = '<tr><td>' . $label . '</td><td>' . implode('<br>', $lines) . '</td></tr>';
    }
    return $out;
}

/**
 * @brief Format XSD decimal
 * @param float $value
 * @param int $maxDp Max decimals
 * @param int $minDp Min decimals
 * @return string
 */
function ksefFormatDecimal($value, $maxDp, $minDp = 2)
{
    $s = number_format(round((float) $value, (int) $maxDp), (int) $maxDp, '.', '');
    if (strpos($s, '.') !== false) {
        list($int, $frac) = explode('.', $s, 2);
        $frac = rtrim($frac, '0');
        if (strlen($frac) < (int) $minDp) {
            $frac = str_pad($frac, (int) $minDp, '0');
        }
        $s = ($frac === '') ? $int : $int . '.' . $frac;
    }
    if (preg_match('/^-0(\.0*)?$/', $s)) {
        $s = substr($s, 1);
    }
    return $s;
}

/**
 * @brief Polish translations
 * @param bool $reset Drop cache
 * @return Translate
 */
function ksefPolishLangs($reset = false)
{
    global $conf;
    static $pl = null;
    if ($pl === null || $reset) {
        require_once DOL_DOCUMENT_ROOT . '/core/class/translate.class.php';
        $pl = new Translate('', $conf);
        $pl->setDefaultLang('pl_PL');
        $pl->loadLangs(array('main', 'products', 'bills', 'ksef@ksef'));
    }
    return $pl;
}

/**
 * @brief Default unit text
 * @param string $code c_units.code
 * @param string $shortLabel
 * @return string
 */
function ksefUnitDefaultText($code, $shortLabel)
{
    $pl = ksefPolishLangs();
    $key = 'KSEF_UNIT_' . strtoupper((string) $code);
    $txt = $pl->transnoentitiesnoconv($key);
    if ($txt !== '' && $txt !== $key) {
        return $txt;
    }
    return trim((string) $pl->transnoentitiesnoconv((string) $shortLabel));
}

/**
 * @brief Admin unit map
 * @return array code => text
 */
function ksefUnitMap()
{
    $map = json_decode(getDolGlobalString('KSEF_FA3_UNIT_MAP', ''), true);
    return is_array($map) ? $map : array();
}

/**
 * @brief Line unit text
 * @param DoliDB $db
 * @param int $fkUnit c_units rowid
 * @return string
 */
function ksefUnitText($db, $fkUnit)
{
    static $rows = array();
    $fkUnit = (int) $fkUnit;
    if ($fkUnit <= 0) {
        return '';
    }
    if (!array_key_exists($fkUnit, $rows)) {
        $res = $db->query("SELECT code, short_label FROM " . MAIN_DB_PREFIX . "c_units WHERE rowid = " . $fkUnit);
        $o = $res ? $db->fetch_object($res) : null;
        $rows[$fkUnit] = $o ? array((string) $o->code, (string) $o->short_label) : null;
    }
    if ($rows[$fkUnit] === null) {
        return '';
    }
    list($code, $short) = $rows[$fkUnit];
    $map = ksefUnitMap();
    if (isset($map[$code]) && trim((string) $map[$code]) !== '') {
        return trim((string) $map[$code]);
    }
    return ksefUnitDefaultText($code, $short);
}

/**
 * @brief Line margin subtype
 * @param object $line
 * @return string
 */
function ksefMarginSubtype($line)
{
    $map = array('MARZA2' => '2', 'MARZA31' => '3_1', 'MARZA32' => '3_2', 'MARZA33' => '3_3');
    $code = strtoupper(trim((string) (is_object($line) ? ($line->vat_src_code ?? '') : $line)));
    return isset($map[$code]) ? $map[$code] : '';
}

/**
 * @brief Default payment forms
 * @return array
 */
function ksefPaymentFormDefaults()
{
    return array(
        'TIP' => '6', 'VIR' => '6', 'PRE' => '6', 'LIQ' => '1', 'CB' => '2', 'CHQ' => '4',
        'VAD' => '6', 'TRA' => '6', 'LCR' => '6', 'FAC' => '6',
        'KLA' => '6', 'SOF' => '6', 'BANCON' => '6', 'IDE' => '6', 'GIR' => '6', 'PPL' => '6',
    );
}

/**
 * @brief Payment form map
 * @return array
 */
function ksefPaymentFormMap()
{
    $map = json_decode(getDolGlobalString('KSEF_FA3_PAYMENT_FORM_MAP', ''), true);
    return is_array($map) ? $map : array();
}

/**
 * @brief Payment mode label
 * @param DoliDB $db
 * @param string $code c_paiement.code
 * @return string
 */
function ksefPaymentModeLabel($db, $code)
{
    $pl = ksefPolishLangs();
    $key = 'PaymentType' . $code;
    $txt = $pl->transnoentitiesnoconv($key);
    if ($txt !== '' && $txt !== $key) {
        return $txt;
    }
    $res = $db->query("SELECT libelle FROM " . MAIN_DB_PREFIX . "c_paiement WHERE code = '" . $db->escape($code) . "' AND entity IN (" . getEntity('c_paiement') . ") ORDER BY entity DESC");
    $o = $res ? $db->fetch_object($res) : null;
    return $o ? trim((string) $o->libelle) : (string) $code;
}

/**
 * @brief Payment mode form
 * @param DoliDB $db
 * @param string $code c_paiement.code
 * @return array form, other
 */
function ksefPaymentForm($db, $code)
{
    $code = trim((string) $code);
    $out = array('form' => '', 'other' => '');
    if ($code === '') {
        return $out;
    }
    $map = ksefPaymentFormMap();
    $want = isset($map[$code]) ? (string) $map[$code] : '';
    if ($want === '') {
        $defaults = ksefPaymentFormDefaults();
        $want = isset($defaults[$code]) ? $defaults[$code] : 'other';
    }
    if (preg_match('/^[1-7]$/', $want)) {
        $out['form'] = $want;
    } elseif ($want === 'other') {
        $out['other'] = dol_trunc(ksefPaymentModeLabel($db, $code), 256, 'right', 'UTF-8', 1);
    }
    return $out;
}

/**
 * @brief Detect settlement line
 * @param DoliDB $db
 * @param object $line FactureLigne
 * @param int $depth
 * @return array|null kind, ref; null if normal
 */
function ksefSettlementLine($db, $line, $depth = 0)
{
    static $cache = array();
    $re = (int) ($line->fk_remise_except ?? 0);
    if ($re > 0) {
        if (!array_key_exists($re, $cache)) {
            $res = $db->query("SELECT re.fk_facture_source, re.description, src.type, src.ref FROM " . MAIN_DB_PREFIX . "societe_remise_except AS re"
                . " LEFT JOIN " . MAIN_DB_PREFIX . "facture AS src ON src.rowid = re.fk_facture_source WHERE re.rowid = " . $re);
            $o = $res ? $db->fetch_object($res) : null;
            $cache[$re] = false;
            if ($o && (int) $o->fk_facture_source > 0 && $o->type !== null) {
                if ((int) $o->type === 2) {
                    $cache[$re] = array('kind' => 'credit_note', 'ref' => (string) $o->ref);
                } elseif (strpos((string) $o->description, KSEF_EXCESS_RECEIVED_DESCRIPTION) === 0) {
                    $cache[$re] = array('kind' => 'excess', 'ref' => (string) $o->ref);
                } else {
                    $cache[$re] = null;
                }
            }
        }
        if ($cache[$re] !== false) {
            return $cache[$re];
        }
    }
    $prev = (int) ($line->fk_prev_id ?? 0);
    if ($prev > 0 && $depth < 20) {
        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
        $pl = new FactureLigne($db);
        if ($pl->fetch($prev) > 0) {
            return ksefSettlementLine($db, $pl, $depth + 1);
        }
    }
    if ($re > 0 && empty($line->fk_product)) {
        $desc = (string) ($line->desc ?? $line->description ?? '');
        if (strpos($desc, '(CREDIT_NOTE)') === 0) {
            return array('kind' => 'credit_note', 'ref' => '');
        }
        if (strpos($desc, '(EXCESS RECEIVED)') === 0) {
            return array('kind' => 'excess', 'ref' => '');
        }
    }
    return null;
}

/**
 * @brief Line supply dates
 * @param array $lines FaWiersz lines
 * @param string $saleDay Y-m-d
 * @return array mode, p6, from, to, lines
 */
function ksefSupplyDates($lines, $saleDay)
{
    $out = array('mode' => 'p6', 'p6' => $saleDay, 'from' => '', 'to' => '', 'lines' => array());
    $day = function ($ts) {
        return (!empty($ts) && (int) $ts > 0) ? dol_print_date((int) $ts, '%Y-%m-%d', 'tzserver') : '';
    };
    $periods = array();
    $dates = array();
    $anyDated = false;
    foreach ($lines as $i => $l) {
        if ((int) ($l->product_type ?? 0) === 9 || ((float) $l->qty == 0 && (float) $l->total_ht == 0)) {
            continue;
        }
        $s = $day($l->date_start ?? null);
        $e = $day($l->date_end ?? null);
        $periods[$i] = ($s !== '' && $e !== '' && $s < $e) ? $s . '|' . $e : '';
        $d = ($e !== '') ? $e : $s;
        if ($d !== '') {
            $anyDated = true;
        }
        $dates[$i] = $d;
    }
    if (!$dates) {
        return $out;
    }
    $uniqPeriods = array_unique($periods);
    if (count($uniqPeriods) === 1 && reset($uniqPeriods) !== '') {
        list($out['from'], $out['to']) = explode('|', reset($uniqPeriods));
        $out['mode'] = 'okres';
        return $out;
    }
    if (!$anyDated) {
        return $out;
    }
    foreach ($dates as $i => $d) {
        $dates[$i] = ($d !== '') ? $d : $saleDay;
    }
    $uniq = array_unique($dates);
    if (count($uniq) === 1) {
        $out['p6'] = reset($uniq);
        return $out;
    }
    $out['mode'] = 'p6a';
    $out['lines'] = $dates;
    return $out;
}

/**
 * @brief Discount user refs
 * @param DoliDB $db
 * @param string $where SQL on alias rc
 * @param string $element
 * @return array|false false on error
 */
function ksefDiscountUsers($db, $where, $element = 'facture')
{
    $P = MAIN_DB_PREFIX;
    $k = ksefInvoiceKind($element);
    $sql = "SELECT DISTINCT f.ref FROM " . $P . "societe_remise_except as rc"
        . " LEFT JOIN " . $P . $k['line_table'] . " as fd ON fd.rowid = rc." . $k['col_line']
        . " INNER JOIN " . $P . $k['table'] . " as f ON f.rowid = COALESCE(rc." . $k['col'] . ", fd." . $k['line_fk'] . ")"
        . " WHERE (" . $where . ") AND (rc." . $k['col'] . " IS NOT NULL OR rc." . $k['col_line'] . " IS NOT NULL)"
        . " ORDER BY f.ref";
    $res = $db->query($sql);
    if (!$res) {
        dol_syslog("ksefDiscountUsers: " . $db->lasterror(), LOG_ERR);
        return false;
    }
    $refs = array();
    while ($o = $db->fetch_object($res)) {
        $refs[] = $o->ref;
    }
    $db->free($res);
    return $refs;
}

/**
 * @brief Revoke credit conversion
 * @param DoliDB $db
 * @param int $noteId
 * @return array result, used_by, removed
 */
function ksefRevokeCreditNoteConversion($db, $noteId)
{
    $P = MAIN_DB_PREFIX;
    $noteId = (int) $noteId;
    $out = array('result' => -1, 'used_by' => array(), 'removed' => 0);
    $res = $db->query("SELECT rowid, fk_facture, fk_facture_line FROM " . $P . "societe_remise_except WHERE fk_facture_source = " . $noteId
        . " AND entity IN (" . getEntity('invoice') . ") FOR UPDATE");
    if (!$res) {
        dol_syslog("ksefRevokeCreditNoteConversion: " . $db->lasterror(), LOG_ERR);
        return $out;
    }
    $rows = 0;
    $used = 0;
    while ($o = $db->fetch_object($res)) {
        $rows++;
        if (!empty($o->fk_facture) || !empty($o->fk_facture_line)) {
            $used++;
        }
    }
    $db->free($res);
    if ($used > 0) {
        $refs = ksefDiscountUsers($db, "rc.fk_facture_source = " . $noteId);
        $out['used_by'] = $refs === false ? array() : $refs;
        return $out;
    }
    if ($rows === 0) {
        $out['result'] = 0;
        return $out;
    }
    if (!$db->query("DELETE FROM " . $P . "societe_remise_except WHERE fk_facture_source = " . $noteId
        . " AND fk_facture IS NULL AND fk_facture_line IS NULL AND entity IN (" . getEntity('invoice') . ")")) {
        dol_syslog("ksefRevokeCreditNoteConversion: " . $db->lasterror(), LOG_ERR);
        return $out;
    }
    $out['removed'] = $rows;
    if (!$db->query("UPDATE " . $P . "facture SET paye = 0 WHERE rowid = " . $noteId)) {
        dol_syslog("ksefRevokeCreditNoteConversion: " . $db->lasterror(), LOG_ERR);
        return $out;
    }
    $out['result'] = 1;
    return $out;
}

/**
 * @brief Maintenance banner
 * @param array|null $cached Latarnia cache
 * @return string HTML
 */
function ksefLatarniaUpcomingBanner($cached = null)
{
    global $langs;

    dol_include_once('/ksef/class/ksef_latarnia.class.php');
    if ($cached === null) {
        $cached = KsefLatarnia::getCachedStatus();
    }
    $windows = KsefLatarnia::upcomingMaintenance($cached);
    if (empty($windows)) {
        return '';
    }
    $langs->load('ksef@ksef');

    $html = '<div class="warning ksef-maintenance-planned" style="margin-bottom: 10px; padding: 8px 12px; border-radius: 4px;">';
    foreach ($windows as $msg) {
        $start = dol_print_date($msg['start_ts'], 'dayhour', 'tzuserrel');
        $line = $msg['end_ts'] > 0
            ? $langs->trans('KSEF_MaintenancePlannedWindow', $start, dol_print_date($msg['end_ts'], 'dayhour', 'tzuserrel'))
            : $langs->trans('KSEF_MaintenancePlannedFrom', $start);
        $html .= '<div><i class="fas fa-tools" style="margin-right: 5px;"></i> <strong>' . $line . '</strong>';
        if (!empty($msg['title'])) {
            $html .= ' - ' . dol_escape_htmltag($msg['title']);
        }
        $html .= '</div>';
    }
    $html .= '<small>' . $langs->trans('KSEF_MaintenancePlannedHint') . '</small>';
    $html .= '</div>';

    return $html;
}

/**
 * @brief Supplier pay rights
 * @param User $user
 * @return array create, validate
 */
function ksefSupplierPayRights($user)
{
    $create = $user->hasRight('fournisseur', 'facture', 'creer') || $user->hasRight('supplier_invoice', 'creer');
    $validate = getDolGlobalString('MAIN_USE_ADVANCED_PERMS') ? (bool) $user->hasRight('fournisseur', 'supplier_invoice_advance', 'validate') : $create;
    return array('create' => (bool) $create, 'validate' => $validate);
}

/**
 * @brief Incoming pay plan
 * @param KsefIncoming $incoming
 * @param FactureFournisseur $facture
 * @return array
 */
function ksefIncomingPayPlan($incoming, $facture)
{
    global $conf;

    $foreign = !empty($incoming->currency) && $incoming->currency !== $conf->currency;
    $parsed = null;
    if (!empty($incoming->fa3_xml)) {
        dol_include_once('/ksef/class/fa3_parser.class.php');
        $parser = new FA3Parser($incoming->db);
        $parsed = $parser->parse($incoming->fa3_xml);
    }
    $p15 = isset($parsed['invoice']['total_gross']) ? (float) $parsed['invoice']['total_gross'] : (float) $incoming->total_gross;
    $total = round((float) ($foreign ? $facture->multicurrency_total_ttc : $facture->total_ttc), 2);
    $remain = round((float) $facture->getRemainToPay($foreign ? 1 : 0), 2);
    $carried = 0.0;
    if ((int) $facture->type === FactureFournisseur::TYPE_REPLACEMENT) {
        if (empty($facture->array_options)) {
            $facture->fetch_optionals();
        }
        $carried = (float) ($facture->array_options['options_ksef_correction_settled_amount' . ($foreign ? '_mc' : '')] ?? 0);
    }
    $plan = array(
        'foreign' => $foreign, 'currency' => $foreign ? $incoming->currency : $conf->currency,
        'p15' => round($p15, 2), 'total' => $total, 'remain' => $remain, 'already_paid' => round($total - $remain - $carried, 2),
        'settlement' => null, 'due' => round($p15, 2), 'verified' => true, 'nothing_to_pay' => false,
    );

    $s = is_array($parsed) && !empty($parsed['settlement']) ? $parsed['settlement'] : null;
    if ($s) {
        $sum = function ($rows) {
            $x = 0.0;
            foreach ($rows as $r) {
                $x += (float) $r['amount'];
            }
            return round($x, 2);
        };
        $charges = $s['total_charges'] !== null ? round((float) $s['total_charges'], 2) : $sum($s['charges']);
        $deductions = $s['total_deductions'] !== null ? round((float) $s['total_deductions'], 2) : $sum($s['deductions']);
        $sumsOk = (empty($s['charges']) || $charges == $sum($s['charges'])) && (empty($s['deductions']) || $deductions == $sum($s['deductions']));
        $computed = round($p15 + $charges - $deductions, 2);
        $plan['settlement'] = array(
            'charges' => $charges, 'deductions' => $deductions, 'computed' => $computed, 'sums_ok' => $sumsOk,
            'to_pay' => $s['to_pay'] !== null ? round((float) $s['to_pay'], 2) : null,
            'to_settle' => $s['to_settle'] !== null ? round((float) $s['to_settle'], 2) : null,
            'deduction_rows' => $s['deductions'], 'charge_rows' => $s['charges'],
        );
        if ($s['to_settle'] !== null) {
            $plan['due'] = 0.0;
            $plan['verified'] = $sumsOk && round(-$computed, 2) == $plan['settlement']['to_settle'];
            $plan['nothing_to_pay'] = true;
        } elseif ($s['to_pay'] !== null) {
            $plan['due'] = $plan['settlement']['to_pay'];
            $plan['verified'] = $sumsOk && $plan['settlement']['to_pay'] == $computed;
        } else {
            $plan['due'] = $computed;
            $plan['verified'] = false;
        }
    }
    $plan['target'] = round($plan['due'] - $plan['already_paid'], 2);
    if ($plan['due'] <= 0) {
        $plan['nothing_to_pay'] = true;
    }
    $plan['proposed'] = max(0.0, min($plan['target'], $remain));
    $plan['charges_beyond'] = $plan['target'] > $remain;
    $plan['close_with_note'] = $plan['settlement'] !== null && $plan['verified'] && !$plan['nothing_to_pay']
        && $plan['target'] > 0 && $plan['target'] < $remain;
    return $plan;
}

/**
 * @brief Supplier pay state
 * @param User $user
 * @param FactureFournisseur $facture
 * @param KsefIncoming $incoming
 * @return array show, refused, validate, plan
 */
function ksefSupplierPayState($user, $facture, $incoming)
{
    global $langs;

    $out = array('show' => false, 'refused' => '', 'validate' => false, 'plan' => null);
    if (empty($incoming->id) || empty($facture->id) || (int) $facture->type === FactureFournisseur::TYPE_CREDIT_NOTE
        || !in_array((string) $incoming->payment_status, array('paid', 'paid_installments'), true)) {
        return $out;
    }
    $rights = ksefSupplierPayRights($user);
    $status = (int) $facture->status;
    if (!$rights['create'] || ($status === FactureFournisseur::STATUS_DRAFT && !$rights['validate'])) {
        return $out;
    }
    if ($status === FactureFournisseur::STATUS_VALIDATED) {
        // replay guard
        if (!empty($facture->paid) || !empty($facture->paye) || (float) $facture->getRemainToPay(0) <= 0) {
            return $out;
        }
    } elseif ($status !== FactureFournisseur::STATUS_DRAFT) {
        return $out;
    }
    $langs->load('ksef@ksef');
    $out['show'] = true;
    $out['validate'] = $status === FactureFournisseur::STATUS_DRAFT;
    $cmp = ksefCompareIncomingTotals($incoming, $facture);
    if (!$cmp['ok'] && !$cmp['skipped']) {
        $out['refused'] = $langs->trans('KSEF_PaymentRefusedTotalsMismatch', price($cmp['xml_ttc'], 0, $langs, 1, -1, 2, $cmp['currency']), price($cmp['doli_ttc'], 0, $langs, 1, -1, 2, $cmp['currency']));
        return $out;
    }
    $out['plan'] = ksefIncomingPayPlan($incoming, $facture);
    if ($out['plan']['nothing_to_pay']) {
        $settle = $out['plan']['settlement'] !== null && $out['plan']['settlement']['to_settle'] !== null ? $out['plan']['settlement']['to_settle'] : 0;
        $out['refused'] = $langs->trans('KSEF_PayNothingToPay', price($settle, 0, $langs, 1, -1, 2, $out['plan']['currency']));
    }
    return $out;
}

/**
 * @brief Warehouse needed
 * @param FactureFournisseur $facture
 * @return bool
 */
function ksefSupplierPayNeedsWarehouse($facture)
{
    if (!isModEnabled('stock') || !getDolGlobalString('STOCK_CALCULATE_ON_SUPPLIER_BILL')) {
        return false;
    }
    return (bool) $facture->hasProductsOrServices(getDolGlobalString('STOCK_SUPPORTS_SERVICES') ? 1 : 2);
}

/**
 * @brief Pay plan deductions
 * @param array $plan
 * @return array
 */
function ksefSupplierPayDeductionItems($plan)
{
    global $langs;
    $items = array();
    if ($plan['settlement'] === null) {
        return $items;
    }
    foreach ($plan['settlement']['deduction_rows'] as $r) {
        $reason = trim((string) $r['reason']);
        $items[] = ($reason !== '' ? $reason : '?') . ' (' . price((float) $r['amount'], 0, $langs, 1, -1, 2) . ')';
    }
    return $items;
}

/**
 * @brief Pay confirm dialog
 * @param DoliDB $db
 * @param FactureFournisseur $facture
 * @param KsefIncoming $incoming
 * @param array $state
 * @return string HTML
 */
function ksefSupplierPayFormConfirm($db, $facture, $incoming, $state)
{
    global $langs, $conf;
    require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
    $langs->loadLangs(array('ksef@ksef', 'banks', 'bills'));
    $form = new Form($db);
    $plan = $state['plan'];
    $cur = $plan['currency'];
    $money = function ($v) use ($langs, $cur) {
        return price($v, 0, $langs, 1, -1, 2, $cur);
    };

    $rows = array(array($langs->trans('KSEF_PayKsefTotal'), $money($plan['p15'])));
    if ($plan['settlement'] !== null) {
        foreach ($plan['settlement']['charge_rows'] as $r) {
            $rows[] = array($langs->trans('KSEF_PayCharge') . ': ' . dol_escape_htmltag((string) $r['reason']), '+ ' . $money((float) $r['amount']));
        }
        foreach ($plan['settlement']['deduction_rows'] as $r) {
            $rows[] = array($langs->trans('KSEF_PayDeduction') . ': ' . dol_escape_htmltag((string) $r['reason']), '- ' . $money((float) $r['amount']));
        }
        $rows[] = array('<strong>' . $langs->trans('KSEF_PayKsefDue') . '</strong>', '<strong>' . $money($plan['due']) . '</strong>');
    }
    if ($plan['already_paid'] != 0) {
        $rows[] = array($langs->trans('AlreadyPaid'), $money($plan['already_paid']));
    }
    $rows[] = array($langs->trans('RemainderToPay'), $money($plan['remain']));
    $q = '<div class="ksef-pay-confirm">' . $langs->trans($state['validate'] ? 'KSEF_PayConfirmQuestionValidate' : 'KSEF_PayConfirmQuestion', $facture->ref);
    $q .= '<table class="noborder centpercent ksef-pay-summary" style="margin-top: 6px;">';
    foreach ($rows as $r) {
        $q .= '<tr><td>' . $r[0] . '</td><td class="right nowraponall">' . $r[1] . '</td></tr>';
    }
    $q .= '</table>';
    $notes = array();
    if ($plan['settlement'] !== null) {
        $s = $plan['settlement'];
        if ($s['to_pay'] === null) {
            $notes[] = array('warning', $langs->trans('KSEF_PayDueMissing', $money($s['computed'])));
        } elseif (!$plan['verified']) {
            $notes[] = array('warning', $langs->trans('KSEF_PayDueMismatch', $money($s['to_pay']), $money($s['computed'])));
        } elseif ($plan['close_with_note']) {
            $notes[] = array('info', $langs->trans('KSEF_PayCloseInfo', $money($plan['target'])));
        }
    }
    if ($plan['charges_beyond']) {
        $notes[] = array('warning', $langs->trans('KSEF_PayChargesBeyondTotal', $money($plan['target']), $money($plan['remain'])));
    }
    foreach ($notes as $n) {
        $q .= '<div class="' . $n[0] . ' ksef-pay-note" style="margin-top: 6px;">' . $n[1] . '</div>';
    }
    $q .= '</div>';

    $fq = array();
    if ($state['validate'] && ksefSupplierPayNeedsWarehouse($facture)) {
        $langs->load('stocks');
        require_once DOL_DOCUMENT_ROOT . '/product/class/html.formproduct.class.php';
        $formproduct = new FormProduct($db);
        $fq[] = array('type' => 'other', 'name' => 'idwarehouse', 'label' => $langs->trans('SelectWarehouseForStockIncrease'),
            'value' => $formproduct->selectWarehouses(GETPOST('idwarehouse') ? GETPOST('idwarehouse') : 'ifone', 'idwarehouse', '', 1));
    }

    $form->load_cache_types_paiements();
    $modes = array();
    foreach ($form->cache_types_paiements as $id => $pm) {
        if ((int) $pm['active'] === 1 && (int) $pm['type'] !== 0) {
            $modes[$id] = $pm['label'];
        }
    }
    $mode = !empty($incoming->payment_method) ? $incoming->mapKsefPaymentMethod($incoming->payment_method) : 0;
    if ($mode <= 0 || !isset($modes[$mode])) {
        $mode = isset($modes[(int) $facture->mode_reglement_id]) ? (int) $facture->mode_reglement_id : getDolGlobalInt('KSEF_DEFAULT_PAYMENT_METHOD_ID');
    }
    if (!isset($modes[$mode])) {
        $mode = -1;
    }
    $fq[] = array('type' => 'select', 'name' => 'paiementid', 'label' => $langs->trans('PaymentMode'), 'values' => $modes, 'default' => $mode, 'morecss' => 'minwidth200');

    if (isModEnabled('bank')) {
        $accounts = array();
        $res = $db->query("SELECT rowid, ref, label, currency_code FROM " . MAIN_DB_PREFIX . "bank_account WHERE entity IN (" . getEntity('bank_account') . ") AND clos = 0 ORDER BY label");
        while ($res && ($o = $db->fetch_object($res))) {
            $accounts[(int) $o->rowid] = $o->label . ' (' . ($o->currency_code ? $o->currency_code : $conf->currency) . ')';
        }
        $acc = (int) $facture->fk_account;
        if (!isset($accounts[$acc])) {
            $soc = new Societe($db);
            $acc = ($facture->socid > 0 && $soc->fetch($facture->socid) > 0) ? (int) $soc->fk_account : 0;
        }
        if (!isset($accounts[$acc])) {
            $invCur = !empty($facture->multicurrency_code) ? $facture->multicurrency_code : $conf->currency;
            $same = array_keys(array_filter($accounts, function ($label) use ($invCur) {
                return substr($label, -strlen($invCur) - 2) === '(' . $invCur . ')';
            }));
            $acc = count($same) === 1 ? (int) $same[0] : 0;
        }
        $fq[] = array('type' => 'select', 'name' => 'accountid', 'label' => $langs->trans('BankAccount'), 'values' => $accounts,
            'default' => isset($accounts[$acc]) ? $acc : -1, 'morecss' => 'minwidth200');
    }
    $fq[] = array('type' => 'date', 'name' => 'ksef_pay_date', 'label' => $langs->trans('DatePayment'),
        'value' => !empty($incoming->payment_date) ? $incoming->payment_date : -1);
    $fq[] = array('type' => 'text', 'name' => 'ksef_pay_amount', 'label' => $langs->trans('KSEF_PayAmount') . ' (' . $cur . ')',
        'value' => price($plan['proposed'], 0, $langs, 0, -1, 2), 'morecss' => 'width100 right');

    // auto height ignores
    $height = 260 + 40 * count($fq) + 26 * count($rows) + 50 * count($notes);
    $required = array('paiementid' => $langs->trans('PaymentMode'), 'ksef_pay_amount' => $langs->trans('KSEF_PayAmount'));
    if (isModEnabled('bank')) {
        $required['accountid'] = $langs->trans('BankAccount');
    }
    return $form->formconfirm($_SERVER['PHP_SELF'] . '?id=' . (int) $facture->id, $langs->trans('KSEF_PayConfirmTitle'), $q, 'ksef_pay_confirm', $fq, 'yes', 1, $height, 700)
        . ksefConfirmRequiredJs($required);
}

/**
 * @brief Confirm required fields
 * @param array $fields name => label
 * @return string HTML script
 */
function ksefConfirmRequiredJs($fields)
{
    global $langs;
    $langs->load('errors');
    $msgs = array();
    foreach ($fields as $name => $label) {
        $msgs[$name] = $langs->transnoentitiesnoconv('ErrorFieldRequired', $label);
    }
    return '<script>
jQuery(function($) {
    var fields = ' . json_encode($msgs) . ';
    // before core handler
    document.addEventListener("click", function(e) {
        var dlg = $("#dialog-confirm");
        var yes = dlg.closest(".ui-dialog").find(".ui-dialog-buttonpane button").first().get(0);
        if (!yes || !(e.target === yes || yes.contains(e.target))) return;
        var missing = [];
        $.each(fields, function(name, msg) {
            var v = $.trim(String($("#" + name).val() || ""));
            if (v === "" || v === "-1" || v === "0") missing.push(msg);
        });
        dlg.find(".ksef-confirm-required").remove();
        if (missing.length) {
            e.stopImmediatePropagation();
            e.preventDefault();
            dlg.prepend($("<div class=\"error ksef-confirm-required\"></div>").html(missing.map(function(m) { return $("<span>").text(m).html(); }).join("<br>")));
        }
    }, true);
});
</script>';
}

/**
 * @brief Pay from KSeF
 * @param DoliDB $db
 * @param User $user
 * @param FactureFournisseur $facture
 * @param KsefIncoming $incoming
 * @param array $in Form input
 * @return array
 */
function ksefSupplierPayFromKsef($db, $user, $facture, $incoming, $in)
{
    global $langs;
    require_once DOL_DOCUMENT_ROOT . '/fourn/class/paiementfourn.class.php';
    $langs->loadLangs(array('ksef@ksef', 'banks', 'bills', 'stocks'));
    $out = array('ok' => false, 'errors' => array(), 'warnings' => array(), 'payment_id' => 0, 'validated' => false, 'closed' => '', 'left' => 0.0);

    $state = ksefSupplierPayState($user, $facture, $incoming);
    if (!$state['show']) {
        $out['errors'][] = $langs->trans('KSEF_PayRefusedState');
        return $out;
    }
    if ($state['refused'] !== '') {
        $out['errors'][] = $state['refused'];
        return $out;
    }
    $plan = $state['plan'];
    $money = function ($v) use ($langs, $plan) {
        return price($v, 0, $langs, 1, -1, 2, $plan['currency']);
    };
    $amount = (float) price2num((string) ($in['amount'] ?? ''), 'MT');
    if ($amount <= 0 || $amount > $plan['remain']) {
        $out['errors'][] = $langs->trans('KSEF_PayRefusedAmount', $money($plan['remain']));
    }
    if ((int) ($in['paiementid'] ?? 0) <= 0) {
        $out['errors'][] = $langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('PaymentMode'));
    }
    if (isModEnabled('bank') && (int) ($in['accountid'] ?? 0) <= 0) {
        $out['errors'][] = $langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('BankAccount'));
    }
    if (empty($in['datepaye'])) {
        $out['errors'][] = $langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('DatePayment'));
    }
    $idwarehouse = (int) ($in['idwarehouse'] ?? 0);
    if ($state['validate'] && ksefSupplierPayNeedsWarehouse($facture) && $idwarehouse <= 0) {
        $out['errors'][] = $langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('Warehouse'));
    }
    if ($out['errors']) {
        return $out;
    }

    $db->begin();
    if ($state['validate']) {
        if ($facture->validate($user, '', $idwarehouse) <= 0) {
            $db->rollback();
            $out['errors'] = array_merge(array($facture->error), (array) $facture->errors);
            return $out;
        }
        $out['validated'] = true;
        $facture->fetch($facture->id);
    }

    $deductions = ksefSupplierPayDeductionItems($plan);
    $paiement = new PaiementFourn($db);
    $paiement->datepaye = (int) $in['datepaye'];
    if ($plan['foreign']) {
        $paiement->amounts = array();
        $paiement->multicurrency_amounts = array($facture->id => $amount);
        $paiement->multicurrency_code = array($facture->id => $facture->multicurrency_code);
        $paiement->multicurrency_tx = array($facture->id => $facture->multicurrency_tx);
    } else {
        $paiement->amounts = array($facture->id => $amount);
        // core 22 needs
        $paiement->multicurrency_code = array($facture->id => $facture->multicurrency_code);
        $paiement->multicurrency_tx = array($facture->id => $facture->multicurrency_tx);
    }
    $paiement->paiementid = (int) $in['paiementid'];
    $paiement->num_payment = 'KSeF';
    $paiement->fk_account = (int) ($in['accountid'] ?? 0);
    $paiement->note_private = $langs->transnoentitiesnoconv('KSEF_PayPaymentNote', (string) $incoming->ksef_number)
        . ($deductions ? "\n" . $langs->transnoentitiesnoconv('KSEF_PayDeduction') . ': ' . implode('; ', $deductions) : '');
    $pid = $paiement->create($user, 1);
    if ($pid <= 0) {
        $db->rollback();
        $out['errors'] = array_merge(array($paiement->error), (array) $paiement->errors);
        return $out;
    }
    if (isModEnabled('bank') && $paiement->addPaymentToBank($user, 'payment_supplier', '(SupplierInvoicePayment)', (int) $in['accountid'], '', '') <= 0) {
        $db->rollback();
        $out['errors'] = array_merge(array($paiement->error), (array) $paiement->errors);
        return $out;
    }

    $facture->fetch($facture->id);
    $left = round((float) $facture->getRemainToPay($plan['foreign'] ? 1 : 0), 2);
    if (!empty($facture->paid) || !empty($facture->paye)) {
        $out['closed'] = 'paid';
    } elseif ($left > 0 && $plan['close_with_note'] && abs($amount - $plan['target']) < 0.005) {
        $note = dol_trunc($langs->transnoentitiesnoconv('KSEF_PayCloseNote', implode('; ', $deductions)), 125, 'right', 'UTF-8', 1);
        if ($facture->setPaid($user, 'other', $note) <= 0) {
            $db->rollback();
            $out['errors'] = array_merge(array($facture->error), (array) $facture->errors);
            return $out;
        }
        $out['closed'] = 'deductions';
    } elseif ($left > 0) {
        $out['warnings'][] = $langs->trans('KSEF_PayLeftOpen', $money($left));
        if ($plan['settlement'] !== null && !$plan['verified']) {
            $out['warnings'][] = $plan['settlement']['to_pay'] === null ? $langs->trans('KSEF_PayDueMissing', $money($plan['settlement']['computed']))
                : $langs->trans('KSEF_PayDueMismatch', $money($plan['settlement']['to_pay']), $money($plan['settlement']['computed']));
        }
    }
    $db->commit();
    $out['ok'] = true;
    $out['payment_id'] = (int) $pid;
    $out['left'] = $left;
    return $out;
}

/**
 * @brief Default pay modes
 * @return array form => code
 */
function ksefImportPaymentModeDefaults()
{
    return array('1' => 'LIQ', '2' => 'CB', '3' => 'LIQ', '4' => 'CHQ', '5' => 'VIR', '6' => 'VIR', '7' => 'CB');
}

/**
 * @brief Admin pay modes
 * @return array form => code
 */
function ksefImportPaymentModeMap()
{
    $map = json_decode(getDolGlobalString('KSEF_IMPORT_PAYMENT_MODE_MAP', ''), true);
    $out = array();
    if (is_array($map)) {
        foreach ($map as $form => $code) {
            $form = (string) $form;
            $code = preg_replace('/[^A-Za-z0-9_]/', '', (string) $code);
            if (preg_match('/^[1-7]$/', $form) && $code !== '') {
                $out[$form] = $code;
            }
        }
    }
    return $out;
}

/**
 * @brief Pay mode codes
 * @param string $form FormaPlatnosci
 * @return array
 */
function ksefImportPaymentModeCodes($form)
{
    $form = trim((string) $form);
    $codes = array();
    $map = ksefImportPaymentModeMap();
    if (isset($map[$form])) {
        $codes[] = $map[$form];
    }
    $defaults = ksefImportPaymentModeDefaults();
    $codes[] = isset($defaults[$form]) ? $defaults[$form] : 'VIR';
    return array_values(array_unique($codes));
}

/**
 * @brief Phone for XML
 * @param string $phone
 * @return string '' if too long
 */
function ksefPhoneForXml($phone)
{
    $tel = trim((string) $phone);
    if ($tel === '' || mb_strlen($tel, 'UTF-8') <= 16) {
        return $tel;
    }
    $tel = preg_replace('/[\s.()\-]+/u', '', $tel);
    return mb_strlen($tel, 'UTF-8') > 16 ? '' : $tel;
}

/**
 * @brief Phone too long
 * @param string $phone
 * @return bool
 */
function ksefPhoneTooLong($phone)
{
    return trim((string) $phone) !== '' && ksefPhoneForXml($phone) === '';
}

/**
 * @brief Long phone parties
 * @param DoliDB $db
 * @param Facture $object
 * @return array Party names
 */
function ksefLongPhoneParties($db, $object)
{
    global $mysoc;
    require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
    $out = array();
    if (is_object($mysoc) && ksefPhoneTooLong($mysoc->phone ?? '')) {
        $out[] = (string) $mysoc->name;
    }
    $withContact = function ($party) {
        return getDolGlobalString('KSEF_FA3_INCLUDE_CONTACT', '1') !== '0' && empty($party->array_options['options_ksef_hide_contact']);
    };
    $ids = array((int) $object->socid);
    if (getDolGlobalString('KSEF_PODMIOT3_SOURCE', 'disabled') === 'enabled') {
        $raw = $object->array_options['options_ksef_podmiot3'] ?? '';
        $decoded = (is_string($raw) && $raw !== '') ? json_decode($raw, true) : null;
        if (is_array($decoded)) {
            foreach (array_slice($decoded, 0, 20) as $e) {
                if ((int) ($e['id'] ?? 0) > 0) {
                    $ids[] = (int) $e['id'];
                }
            }
        } elseif ((int) $raw > 0) {
            $ids[] = (int) $raw;
        }
    }
    foreach (array_unique($ids) as $id) {
        $party = new Societe($db);
        if ($id <= 0 || $party->fetch($id) <= 0) {
            continue;
        }
        $party->fetch_optionals();
        if ($withContact($party) && ksefPhoneTooLong($party->phone ?? '')) {
            $out[] = (string) $party->name;
        }
    }
    return $out;
}

/**
 * @brief Value means yes
 * @param mixed $v
 * @return bool
 */
function ksefFlagIsYes($v)
{
    if ($v === true) {
        return true;
    }
    if (!is_scalar($v) || $v === false) {
        return false;
    }
    return in_array(mb_strtolower(trim((string) $v)), array('1', 'tak', 'yes', 'true'), true);
}

/**
 * @brief Buyer flag state
 * @param DoliDB $db
 * @param string $field
 * @return array exists, label, on_card
 */
function ksefBuyerFlagField($db, $field)
{
    global $langs;
    require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
    $ef = new ExtraFields($db);
    $ef->fetch_name_optionals_label('societe');
    $a = $ef->attributes['societe'] ?? array();
    $out = array('exists' => !empty($a['label'][$field]), 'on_card' => false,
        'label' => $langs->transnoentitiesnoconv($field === 'is_jst' ? 'KSEF_ExtraFieldJst' : 'KSEF_ExtraFieldVatGroup'));
    if (!$out['exists']) {
        return $out;
    }
    if (!empty($a['langfile'][$field])) {
        $langs->load($a['langfile'][$field]);
    }
    $out['label'] = $langs->transnoentitiesnoconv($a['label'][$field]);
    $list = abs((int) dol_eval((string) ($a['list'][$field] ?? '1'), 1, 1, '2'));
    $enabled = trim((string) ($a['enabled'][$field] ?? ''));
    $out['on_card'] = in_array($list, array(1, 3, 4), true) && ($enabled === '' || (bool) dol_eval($enabled, 1, 1, '2'));
    return $out;
}

/**
 * @brief Invoice Podmiot3 entries
 * @param object $object
 * @return array
 */
function ksefPodmiot3Entries($object)
{
    $raw = $object->array_options['options_ksef_podmiot3'] ?? '';
    $list = array();
    $decoded = (is_string($raw) && $raw !== '') ? json_decode($raw, true) : null;
    if (is_array($decoded)) {
        foreach ($decoded as $e) {
            $id = (int) ($e['id'] ?? 0);
            if ($id > 0) {
                $list[] = array('id' => $id, 'role' => (string) ($e['role'] ?? ''), 'udzial' => trim((string) ($e['udzial'] ?? '')));
            }
        }
    } elseif (is_scalar($raw) && (int) $raw > 0) {
        $list[] = array('id' => (int) $raw, 'role' => trim((string) ($object->array_options['options_ksef_podmiot3_role'] ?? '')), 'udzial' => '');
    }
    return $list;
}

/**
 * @brief Buyer GV/JST flags
 * @param object $customer
 * @param object|null $object
 * @return array
 */
function ksefGvJstFlags($customer, $object = null)
{
    $opt = (is_object($customer) && isset($customer->array_options) && is_array($customer->array_options)) ? $customer->array_options : array();
    $out = array('gv_flag' => ksefFlagIsYes($opt['options_is_vat_group'] ?? null), 'jst_flag' => ksefFlagIsYes($opt['options_is_jst'] ?? null),
        'gv_role' => false, 'jst_role' => false, 'stored_roles' => array(),
        'p3_on' => getDolGlobalString('KSEF_PODMIOT3_SOURCE', 'disabled') === 'enabled');
    if (is_object($object)) {
        $default = getDolGlobalString('KSEF_PODMIOT3_ROLE', '6');
        // builder: max 20
        foreach (array_slice(ksefPodmiot3Entries($object), 0, 20) as $e) {
            $out['stored_roles'][] = $e['role'] !== '' ? $e['role'] : $default;
        }
        if ($out['p3_on']) {
            $sent = ksefPodmiot3SentRoles(array_slice(ksefPodmiot3Entries($object), 0, 20));
            $out['gv_role'] = in_array('10', $sent, true);
            $out['jst_role'] = in_array('8', $sent, true);
        }
    }
    $out['gv'] = ($out['gv_flag'] || $out['gv_role']) ? '1' : '2';
    $out['jst'] = ($out['jst_flag'] || $out['jst_role']) ? '1' : '2';
    return $out;
}

/**
 * @brief Sent Podmiot3 roles
 * @param array $entries
 * @return array
 */
function ksefPodmiot3SentRoles($entries)
{
    global $db;
    if (empty($entries)) {
        return array();
    }
    $ids = array_unique(array_map(function ($e) {
        return (int) $e['id'];
    }, $entries));
    $found = array();
    $res = $db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "societe WHERE rowid IN (" . implode(',', $ids) . ")");
    while ($res && ($o = $db->fetch_object($res))) {
        $found[(int) $o->rowid] = true;
    }
    $default = getDolGlobalString('KSEF_PODMIOT3_ROLE', '6');
    $out = array();
    foreach ($entries as $e) {
        if (isset($found[(int) $e['id']])) {
            $out[] = $e['role'] !== '' ? $e['role'] : $default;
        }
    }
    return $out;
}

/**
 * @brief GV/JST role warnings
 * @param DoliDB $db
 * @param Facture $object
 * @return array
 */
function ksefGvJstWarnings($db, $object)
{
    global $langs;
    require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
    $soc = new Societe($db);
    if ((int) $object->socid <= 0 || $soc->fetch((int) $object->socid) <= 0) {
        return array();
    }
    $soc->fetch_optionals();
    $f = ksefGvJstFlags($soc, $object);
    $out = array();
    $storedMember = array_intersect(array('10', '8'), $f['stored_roles']);
    if (!$f['p3_on'] && $storedMember) {
        $out[] = $langs->transnoentitiesnoconv('KSEF_GvJstPodmiot3Off');
    }
    if ($f['p3_on']) {
        $storedMember = array();
    }
    $checks = array(
        array($f['gv_flag'] && !$f['gv_role'] && !in_array('10', $storedMember, true), 'is_vat_group', 'KSEF_GvFlagNoMember'),
        array($f['jst_flag'] && !$f['jst_role'] && !in_array('8', $storedMember, true), 'is_jst', 'KSEF_JstFlagNoUnit'),
    );
    foreach ($checks as $c) {
        if (!$c[0]) {
            continue;
        }
        $fld = ksefBuyerFlagField($db, $c[1]);
        $msg = $langs->transnoentitiesnoconv($c[2], dol_escape_htmltag($soc->name), dol_escape_htmltag($fld['label']));
        if (!$fld['on_card']) {
            $msg .= ' ' . $langs->transnoentitiesnoconv('KSEF_BuyerFlagHidden', dol_escape_htmltag($fld['label']));
        }
        $out[] = $msg;
    }
    return $out;
}

/**
 * @brief Invoice supply mode
 * @param Facture $object
 * @param array|null $lines
 * @return array
 */
function ksefInvoiceSupplyMode($object, $lines = null)
{
    // no TZ shift
    $saleDay = dol_print_date(ksefGetSaleDate($object), '%Y-%m-%d', 'tzserver');
    $out = array('mode' => 'p6', 'p6' => $saleDay, 'from' => '', 'to' => '', 'lines' => array());
    if (in_array((int) $object->type, array(Facture::TYPE_DEPOSIT, Facture::TYPE_REPLACEMENT, Facture::TYPE_CREDIT_NOTE), true)) {
        return $out;
    }
    $day = function ($v) {
        if ($v === '' || $v === null) {
            return '';
        }
        if (is_numeric($v)) {
            return ((int) $v > 0) ? dol_print_date((int) $v, '%Y-%m-%d', 'tzserver') : '';
        }
        return preg_match('/^(\d{4}-\d{2}-\d{2})/', (string) $v, $m) ? $m[1] : '';
    };
    if (getDolGlobalInt('KSEF_FA3_PERIOD_FIELDS')) {
        $from = $day($object->array_options['options_ksef_period_from'] ?? '');
        $to = $day($object->array_options['options_ksef_period_to'] ?? '');
        if ($from !== '' && $to !== '' && $from <= $to) {
            $out['mode'] = 'okres';
            $out['from'] = $from;
            $out['to'] = $to;
            return $out;
        }
    }
    if (getDolGlobalString('KSEF_FA3_LINE_DATES', 'disabled') === 'auto') {
        if ($lines === null) {
            if (empty($object->lines)) {
                $object->fetch_lines();
            }
            $lines = $object->lines;
        }
        return ksefSupplyDates(is_array($lines) ? array_values($lines) : array(), $saleDay);
    }
    return $out;
}

/**
 * @brief Active environment
 * @return string
 */
function ksefGetEnvironment()
{
    $env = strtoupper(trim(getDolGlobalString('KSEF_ENVIRONMENT', 'DEMO')));
    return in_array($env, array('TEST', 'DEMO', 'PRODUCTION'), true) ? $env : 'DEMO';
}

/**
 * @brief Can read module
 * @param User $user
 * @return bool
 */
function ksefUserCanReadModule($user)
{
    if (empty($user) || empty($user->id) || !empty($user->socid)) {
        return false;
    }
    return !empty($user->admin) || $user->hasRight('ksef', 'read');
}

/**
 * @brief Auth readiness
 * @param string|null $env null = active
 * @return array
 */
function ksefAuthReadiness($env = null)
{
    $env = ($env === null) ? ksefGetEnvironment() : strtoupper((string) $env);
    $method = getDolGlobalString('KSEF_AUTH_METHOD_' . $env, 'token');
    $r = array(
        'env' => $env,
        'method' => ($method === 'certificate') ? 'certificate' : 'token',
        'token' => getDolGlobalString('KSEF_AUTH_TOKEN_' . $env) !== '',
        'cert' => ksefCheckAuthCertificate($env),
        'ready' => false,
        'reason' => '',
    );
    if ($r['method'] === 'certificate') {
        if (!$r['cert']['configured']) {
            $r['reason'] = 'no_cert';
        } elseif (!$r['cert']['valid']) {
            $r['reason'] = 'cert_expired';
        } else {
            $r['ready'] = true;
        }
    } elseif (!$r['token']) {
        $r['reason'] = 'no_token';
    } else {
        $r['ready'] = true;
    }
    return $r;
}

/**
 * @brief Drain OpenSSL errors
 * @return string
 */
function ksefOpensslErrors()
{
    $errs = array();
    while (($e = openssl_error_string()) !== false) {
        $errs[] = $e;
    }
    return implode('; ', $errs);
}

/**
 * @brief Stored credential problem
 * @param string $kind AUTH or OFFLINE
 * @param string $env
 * @return string '', key or pair
 */
function ksefStoredCredentialProblem($kind, $env)
{
    require_once DOL_DOCUMENT_ROOT . '/core/lib/security.lib.php';
    $prefix = 'KSEF_' . $kind . '_';
    $cert = getDolGlobalString($prefix . 'CERTIFICATE_' . $env, '');
    $key = getDolGlobalString($prefix . 'PRIVATE_KEY_' . $env, '');
    if ($cert === '' || $key === '') {
        return '';
    }
    $pass = getDolGlobalString($prefix . 'KEY_PASSWORD_' . $env, '');
    ksefOpensslErrors();
    $pk = @openssl_pkey_get_private(base64_decode($key), $pass !== '' ? dol_decode($pass) : '');
    if (!$pk) {
        ksefOpensslErrors();
        return 'key';
    }
    $x = @openssl_x509_read(base64_decode($cert));
    $ok = $x && @openssl_x509_check_private_key($x, $pk);
    ksefOpensslErrors();
    return $ok ? '' : 'pair';
}

/**
 * @brief Read uploaded file
 * @param string $field $_FILES key
 * @return array provided, content, error
 */
function ksefReadUploadedFile($field)
{
    global $langs;
    $out = array('provided' => false, 'content' => '', 'error' => '');
    if (empty($_FILES[$field]) || !isset($_FILES[$field]['error'])) {
        return $out;
    }
    $err = (int) $_FILES[$field]['error'];
    if ($err === UPLOAD_ERR_NO_FILE) {
        return $out;
    }
    $out['provided'] = true;
    $name = dol_escape_htmltag((string) ($_FILES[$field]['name'] ?? ''));
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        $out['error'] = $langs->trans('KSEF_UploadErrTooLarge', $name);
    } elseif ($err !== UPLOAD_ERR_OK) {
        $out['error'] = $langs->trans('KSEF_UploadErrOther', $name, $err);
    } elseif (empty($_FILES[$field]['tmp_name']) || !is_uploaded_file($_FILES[$field]['tmp_name'])) {
        $out['error'] = $langs->trans('KSEF_UploadErrOther', $name, 'tmp');
    } else {
        $content = @file_get_contents($_FILES[$field]['tmp_name']);
        if ($content === false) {
            $out['error'] = $langs->trans('KSEF_UploadErrOther', $name, 'read');
        } else {
            $out['content'] = trim($content);
        }
    }
    return $out;
}

/**
 * @brief Store certificate upload
 * @param DoliDB $db
 * @param string $kind AUTH or OFFLINE
 * @param string $env
 * @param string $certField $_FILES key
 * @param string $keyField $_FILES key
 * @param string $password
 * @return array
 */
function ksefStageCertificateUpload($db, $kind, $env, $certField, $keyField, $password)
{
    global $conf, $langs;
    require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
    require_once DOL_DOCUMENT_ROOT . '/core/lib/security.lib.php';

    // for coverage scan
    $names = array(
        'AUTH' => array('cert' => 'KSEF_AUTH_CERTIFICATE_', 'key' => 'KSEF_AUTH_PRIVATE_KEY_', 'pass' => 'KSEF_AUTH_KEY_PASSWORD_', 'serial' => 'KSEF_AUTH_CERT_SERIAL_',
            'from' => 'KSEF_AUTH_CERT_VALID_FROM_', 'to' => 'KSEF_AUTH_CERT_VALID_TO_'),
        'OFFLINE' => array('cert' => 'KSEF_OFFLINE_CERTIFICATE_', 'key' => 'KSEF_OFFLINE_PRIVATE_KEY_', 'pass' => 'KSEF_OFFLINE_KEY_PASSWORD_', 'serial' => 'KSEF_OFFLINE_CERT_SERIAL_',
            'from' => 'KSEF_OFFLINE_CERT_VALID_FROM_', 'to' => 'KSEF_OFFLINE_CERT_VALID_TO_'),
    );
    $n = $names[$kind === 'OFFLINE' ? 'OFFLINE' : 'AUTH'];
    $res = array('errors' => array(), 'warnings' => array(), 'messages' => array(), 'committed' => false, 'complete' => false);
    $certUp = ksefReadUploadedFile($certField);
    $keyUp = ksefReadUploadedFile($keyField);
    foreach (array($certUp, $keyUp) as $up) {
        if ($up['error'] !== '') {
            $res['errors'][] = $up['error'];
        }
    }
    $password = (string) $password;
    $storedCert = getDolGlobalString($n['cert'] . $env, '');
    $storedKey = getDolGlobalString($n['key'] . $env, '');
    $storedPass = getDolGlobalString($n['pass'] . $env, '');
    if (!$certUp['provided'] && !$keyUp['provided'] && $password === '') {
        $res['warnings'][] = $langs->trans('KSEF_NothingToUpload');
        $res['complete'] = ($storedCert !== '' && $storedKey !== '');
        return $res;
    }
    if ($res['errors']) {
        return $res;
    }

    $certPem = $certUp['provided'] ? $certUp['content'] : ($storedCert !== '' ? base64_decode($storedCert) : '');
    $certInfo = null;
    $x509 = null;
    if ($certPem !== '') {
        ksefOpensslErrors();
        $x509 = @openssl_x509_read($certPem);
        if (!$x509) {
            $res['errors'][] = $langs->trans('KSEF_CertificateInvalid') . ': ' . dol_escape_htmltag(ksefOpensslErrors());
        } else {
            $certInfo = openssl_x509_parse($x509);
            if (!$certInfo) {
                $res['errors'][] = $langs->trans('KSEF_CertificateParseError');
            } elseif ($certUp['provided']) {
                $now = dol_now();
                if (!empty($certInfo['validTo_time_t']) && (int) $certInfo['validTo_time_t'] < $now) {
                    $res['errors'][] = $langs->trans('KSEF_CertificateExpiredUpload', dol_print_date((int) $certInfo['validTo_time_t'], 'day'));
                } elseif (!empty($certInfo['validFrom_time_t']) && (int) $certInfo['validFrom_time_t'] > $now) {
                    $res['warnings'][] = $langs->trans('KSEF_CertificateNotYetValid', dol_print_date((int) $certInfo['validFrom_time_t'], 'day'));
                }
            }
        }
    }

    // typed password only
    if ($keyUp['provided']) {
        $keyPem = $keyUp['content'];
        $pass = $password;
    } else {
        $keyPem = ($storedKey !== '') ? base64_decode($storedKey) : '';
        $pass = ($password !== '') ? $password : ($storedPass !== '' ? dol_decode($storedPass) : '');
    }
    $pkey = null;
    if ($keyPem !== '') {
        ksefOpensslErrors();
        $pkey = @openssl_pkey_get_private($keyPem, $pass);
        ksefOpensslErrors();
        if (!$pkey) {
            $res['errors'][] = $langs->trans('KSEF_PrivateKeyPasswordMismatch');
        }
    } elseif ($password !== '') {
        $res['errors'][] = $langs->trans('KSEF_PrivateKeyMissingForPassword');
    }
    if ($x509 && $pkey) {
        $pairOk = @openssl_x509_check_private_key($x509, $pkey);
        ksefOpensslErrors();
        if (!$pairOk) {
            $res['errors'][] = $langs->trans('KSEF_CertKeyMismatch');
        }
    }
    if ($res['errors']) {
        return $res;
    }

    $ent = $conf->entity;
    if ($certUp['provided']) {
        $serial = $certInfo['serialNumberHex'] ?? ($certInfo['serialNumber'] ?? '');
        dolibarr_set_const($db, $n['cert'] . $env, base64_encode($certPem), 'chaine', 0, '', $ent);
        dolibarr_set_const($db, $n['serial'] . $env, $serial, 'chaine', 0, '', $ent);
        foreach (array('from' => 'validFrom_time_t', 'to' => 'validTo_time_t') as $which => $infoKey) {
            if (!empty($certInfo[$infoKey])) {
                dolibarr_set_const($db, $n[$which] . $env, (string) $certInfo[$infoKey], 'chaine', 0, '', $ent);
            } else {
                dolibarr_del_const($db, $n[$which] . $env, $ent);
            }
        }
        $res['messages'][] = $langs->trans($kind === 'AUTH' ? 'KSEF_AuthCertificateSaved' : 'KSEF_OfflineCertificateSaved');
    }
    if ($keyUp['provided']) {
        ksefSetSecretConst($db, $n['key'] . $env, base64_encode($keyPem), $ent);
        $res['messages'][] = $langs->trans($kind === 'AUTH' ? 'KSEF_AuthPrivateKeySaved' : 'KSEF_OfflinePrivateKeySaved');
    }
    if ($keyUp['provided'] || $password !== '') {
        if ($pass !== '') {
            ksefSetSecretConst($db, $n['pass'] . $env, dol_encode($pass), $ent);
        } else {
            dolibarr_del_const($db, $n['pass'] . $env, $ent);
        }
        if ($pkey) {
            $res['messages'][] = $langs->trans('KSEF_PrivateKeyValidated');
        }
    }
    $res['committed'] = true;
    $res['complete'] = ($certPem !== '' && $keyPem !== '');
    return $res;
}

/**
 * @brief Run connection test
 * @param DoliDB $db
 * @param string $env
 * @param string $kind connection, token, certificate
 * @return array ok, message
 */
function ksefRunConnectionTest($db, $env, $kind)
{
    global $conf, $langs;
    dol_include_once('/ksef/class/ksef_client.class.php');
    $env = strtoupper((string) $env);
    $label = ' (' . dol_escape_htmltag(ksefEnvironmentLabel($env)) . ')';
    if ($kind === 'token' && getDolGlobalString('KSEF_AUTH_TOKEN_' . $env) === '') {
        return array('ok' => false, 'message' => $langs->trans('KSEF_ConfigureTokenFirst') . $label);
    }
    if ($kind === 'certificate' && !ksefCheckAuthCertificate($env)['configured']) {
        return array('ok' => false, 'message' => $langs->trans('KSEF_CertificateNotConfigured') . $label);
    }
    $methodKey = 'KSEF_AUTH_METHOD_' . $env;
    $hadMethod = isset($conf->global->$methodKey);
    $origMethod = $hadMethod ? $conf->global->$methodKey : null;
    if ($kind !== 'connection') {
        $conf->global->$methodKey = $kind;
    }
    $out = array('ok' => false, 'message' => '');
    try {
        $client = new KsefClient($db, $env);
        if ($kind === 'connection') {
            $ok = $client->testConnection();
            $out['message'] = $ok ? $langs->trans('KSEF_CONNECTION_SUCCESS') . $label : $langs->trans('KSEF_CONNECTION_FAILED') . ': ' . dol_escape_htmltag($client->error);
        } else {
            $ok = $client->authenticate();
            if ($ok) {
                $out['message'] = $langs->trans($kind === 'token' ? 'KSEF_AUTH_SUCCESS' : 'KSEF_CERT_AUTH_SUCCESS') . $label;
            } else {
                $out['message'] = $langs->trans($kind === 'token' ? 'KSEF_AUTH_FAILED' : 'KSEF_CERT_AUTH_FAILED') . ': ' . dol_escape_htmltag($client->error);
            }
        }
        $out['ok'] = (bool) $ok;
    } catch (Exception $e) {
        $key = ($kind === 'connection') ? 'KSEF_CONNECTION_ERROR' : ($kind === 'token' ? 'KSEF_AUTH_ERROR' : 'KSEF_CERT_AUTH_ERROR');
        $out['message'] = $langs->trans($key) . ': ' . dol_escape_htmltag($e->getMessage());
    }
    if ($kind !== 'connection') {
        if ($hadMethod) {
            $conf->global->$methodKey = $origMethod;
        } else {
            unset($conf->global->$methodKey);
        }
    }
    if (!$out['ok'] && $kind !== 'connection' && $env === 'PRODUCTION' && !ksefValidateNIP(ksefSellerNip())) {
        $out['message'] .= ' ' . $langs->trans('KSEF_AuthHintInvalidNip');
    }
    if ($kind !== 'connection') {
        require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
        dolibarr_set_const($db, 'KSEF_AUTH_TEST_' . $env, ($out['ok'] ? 'ok' : 'fail') . ':' . $kind . ':' . dol_now(), 'chaine', 0, '', $conf->entity);
    }
    return $out;
}

/**
 * @brief Last auth test
 * @param string $env
 * @return array ok|fail|'' and method
 */
function ksefAuthLastTest($env)
{
    $raw = explode(':', getDolGlobalString('KSEF_AUTH_TEST_' . strtoupper((string) $env)));
    return array('result' => in_array($raw[0], array('ok', 'fail'), true) ? $raw[0] : '', 'kind' => (string) ($raw[1] ?? ''), 'time' => (int) ($raw[2] ?? 0));
}

/**
 * @brief Module cron jobs
 * @param DoliDB $db
 * @return array method => job
 */
function ksefGetCronJobs($db)
{
    global $conf;
    $jobs = array();
    $sql = "SELECT rowid, label, methodename, status, test, lastresult, datelastrun, datenextrun FROM " . MAIN_DB_PREFIX . "cronjob"
        . " WHERE module_name = 'ksef' AND entity IN (0, " . ((int) $conf->entity) . ") ORDER BY rowid";
    $resql = $db->query($sql);
    if (!$resql) {
        return $jobs;
    }
    while ($o = $db->fetch_object($resql)) {
        $test = (string) $o->test;
        $last = ($o->lastresult === null) ? '' : trim((string) $o->lastresult);
        $jobs[(string) $o->methodename] = array(
            'rowid' => (int) $o->rowid,
            'label' => (string) $o->label,
            'method' => (string) $o->methodename,
            'status' => (int) $o->status,
            'applicable' => ($test === '' || verifCond($test)),
            'failing' => ($last !== '' && $last !== '0'),
            'lastresult' => $last,
            'datelastrun' => $o->datelastrun ? $db->jdate($o->datelastrun) : null,
            'datenextrun' => $o->datenextrun ? $db->jdate($o->datenextrun) : null,
        );
    }
    $db->free($resql);
    return $jobs;
}

/**
 * @brief Required jobs off
 * @param array $jobs ksefGetCronJobs()
 * @return array method => job
 */
function ksefCronRequiredOff($jobs)
{
    $out = array();
    foreach (array('cronSyncIncoming', 'cronDownloadUPOs', 'cronCheckStatuses') as $m) {
        if (isset($jobs[$m]) && !empty($jobs[$m]['applicable']) && empty($jobs[$m]['status'])) {
            $out[$m] = $jobs[$m];
        }
    }
    return $out;
}

/**
 * @brief Enable required jobs
 * @param DoliDB $db
 * @param User $user
 * @return array enabled labels, error
 */
function ksefCronEnableRequired($db, $user)
{
    global $langs;
    $out = array('enabled' => array(), 'error' => '');
    if (!isModEnabled('cron')) {
        return $out;
    }
    require_once DOL_DOCUMENT_ROOT . '/cron/class/cronjob.class.php';
    foreach (ksefCronRequiredOff(ksefGetCronJobs($db)) as $m => $j) {
        $job = new Cronjob($db);
        if ($job->fetch($j['rowid']) <= 0) {
            continue;
        }
        $job->status = 1;
        if ($job->update($user) < 0) {
            $out['error'] = $job->error ?: implode(', ', (array) $job->errors);
            break;
        }
        $out['enabled'][] = $langs->transnoentitiesnoconv('KSEF_CronJob_' . $m);
    }
    return $out;
}

/**
 * @brief Cron engine problem
 * @param DoliDB $db
 * @param array|null $jobs
 * @return string '', off, never or stalled
 */
function ksefCronEngineProblem($db, $jobs = null)
{
    if (!isModEnabled('cron')) {
        return 'off';
    }
    if ($jobs === null) {
        $jobs = ksefGetCronJobs($db);
    }
    if (empty($jobs['cronCheckStatuses']) || empty($jobs['cronCheckStatuses']['status'])) {
        return '';
    }
    $last = $jobs['cronCheckStatuses']['datelastrun'];
    if (empty($last)) {
        foreach ($jobs as $j) {
            if (!empty($j['datelastrun'])) {
                return 'stalled';
            }
        }
        return 'never';
    }
    return (dol_now() - $last) > 3 * 1800 ? 'stalled' : '';
}

/**
 * @brief Section description
 * @param string $langKey
 * @return string HTML
 */
function ksefSectionDesc($langKey)
{
    global $langs;
    return '<div class="ksef-section-desc opacitymedium small">' . $langs->trans($langKey) . '</div>';
}

/**
 * @brief Collapsible sections script
 * @return string HTML script
 */
function ksefCollapsibleSectionsJs()
{
    return '<script>
(function() {
    var hash = window.location.hash ? decodeURIComponent(window.location.hash.slice(1)) : "";
    var key = "ksefOpenSections:" + window.location.pathname, kept = [];
    var samePage = false;
    try { samePage = document.referrer !== "" && new URL(document.referrer).pathname === window.location.pathname; } catch (e) {}
    try { kept = samePage ? JSON.parse(window.sessionStorage.getItem(key) || "[]") : []; } catch (e) { kept = []; }
    var heads = Array.prototype.slice.call(document.querySelectorAll("tr.ksef-section-head"));
    var tables = [];
    heads.forEach(function(head) {
        var t = head.closest("table");
        if (tables.indexOf(t) === -1) {
            tables.push(t);
            t.classList.add("ksef-section-table");
            for (var p = t.previousSibling; p && (p.nodeName === "BR" || (p.nodeType === 3 && !p.textContent.trim())); p = t.previousSibling) {
                p.parentNode.removeChild(p);
            }
        } else {
            var gap = document.createElement("tr"), td = document.createElement("td");
            gap.className = "ksef-section-gap";
            td.colSpan = 99;
            gap.appendChild(td);
            head.parentNode.insertBefore(gap, head);
        }
    });
    function remember() {
        var open = [];
        heads.forEach(function(h, i) { if (h.getAttribute("aria-expanded") === "true") open.push(i); });
        try { window.sessionStorage.setItem(key, JSON.stringify(open)); } catch (e) {}
    }
    heads.forEach(function(head, index) {
        var rows = [], stay = [], r = head.nextElementSibling;
        while (r && !r.classList.contains("ksef-section-head")) {
            if (r.classList.contains("ksef-section-keep")) stay.push(r);
            else if (!r.classList.contains("ksef-section-gap")) rows.push(r);
            r = r.nextElementSibling;
        }
        var cell = head.firstElementChild, icon = document.createElement("span");
        cell.insertBefore(icon, cell.firstChild);
        head.tabIndex = 0;
        head.setAttribute("role", "button");
        function set(open) {
            rows.forEach(function(x) { x.classList.toggle("ksef-section-folded", !open); });
            icon.className = "fa fa-chevron-" + (open ? "down" : "right") + " paddingright ksef-section-chevron";
            head.setAttribute("aria-expanded", open ? "true" : "false");
        }
        var target = hash !== "" && (head.id === hash || rows.concat(stay).some(function(x) {
            return x.id === hash || x.querySelector("[id=\"" + hash.replace(/"/g, "") + "\"], [name=\"" + hash.replace(/"/g, "") + "\"]");
        }));
        set(target || kept.indexOf(index) !== -1);
        function toggle(e) {
            // inner clicks kept
            if (e.target.closest("a, input, select, button, textarea, .classfortooltip")) return;
            set(head.getAttribute("aria-expanded") !== "true");
            remember();
        }
        head.addEventListener("click", toggle);
        head.addEventListener("keydown", function(e) {
            if ((e.key === "Enter" || e.key === " ") && e.target === head) { e.preventDefault(); toggle(e); }
        });
    });
    remember();
})();
</script>';
}

/**
 * @brief Latest row SQL
 * @param string $alias Submissions alias
 * @return string
 */
function ksefLatestSubmissionSql($alias)
{
    return $alias . ".rowid = (SELECT s2.rowid FROM " . MAIN_DB_PREFIX . "ksef_submissions s2 WHERE s2.fk_facture = " . $alias . ".fk_facture"
        . " AND s2.environment = " . $alias . ".environment ORDER BY s2.date_submission DESC, s2.rowid DESC LIMIT 1)";
}

/**
 * @brief Outgoing status counts
 * @param DoliDB $db
 * @param User $user
 * @param string $environment
 * @param int $socid 0 = all
 * @return array
 */
function ksefOutgoingStatCounts($db, $user, $environment, $socid = 0)
{
    $out = array('total' => 0, 'ACCEPTED' => 0, 'PROGRESS' => 0, 'OFFLINE' => 0, 'FAILED' => 0);
    $P = MAIN_DB_PREFIX;
    $sql = "SELECT s.status, COUNT(*) as n FROM " . $P . "ksef_submissions as s INNER JOIN " . $P . "facture as f ON f.rowid = s.fk_facture"
        . " WHERE " . ksefLatestSubmissionSql('s')
        . ksefSqlInvoiceVisibility($user, 'f')
        . ($socid > 0 ? " AND f.fk_soc = " . ((int) $socid) : '')
        . " AND s.environment = '" . $db->escape($environment) . "' GROUP BY s.status";
    $res = $db->query($sql);
    while ($res && ($o = $db->fetch_object($res))) {
        $n = (int) $o->n;
        $out['total'] += $n;
        if ($o->status === 'ACCEPTED') {
            $out['ACCEPTED'] += $n;
        } elseif (in_array($o->status, array('PENDING', 'SUBMITTED', 'TIMEOUT'), true)) {
            $out['PROGRESS'] += $n;
        } elseif (in_array($o->status, array('FAILED', 'REJECTED'), true)) {
            $out['FAILED'] += $n;
        } elseif ($o->status === 'OFFLINE') {
            $out['OFFLINE'] += $n;
        }
    }
    return $out;
}

/**
 * @brief Statistics rows
 * @param string $direction outgoing or incoming
 * @param array $counts
 * @return string HTML
 */
function ksefStatRowsHtml($direction, $counts)
{
    global $langs;
    if ($direction === 'outgoing') {
        $url = dol_buildpath('/ksef/status.php', 1);
        $rows = array(
            array('total', 'KSEF_TotalSubmissions', 'total', '', ''),
            array('accepted', 'KSEF_Accepted', 'ACCEPTED', ksefToneBadgeClass('ok'), '?search_status=ACCEPTED'),
            array('progress', 'KSEF_StatsInProgress', 'PROGRESS', ksefToneBadgeClass('action'), '?search_status=IN_PROGRESS'),
            array('offline', 'KSEF_STATUS_OFFLINE', 'OFFLINE', ksefToneBadgeClass('offline'), '?search_status=OFFLINE'),
            array('failed', 'KSEF_Failed', 'FAILED', ksefToneBadgeClass('problem'), '?search_status=FAILED_ANY'),
        );
    } else {
        $url = dol_buildpath('/ksef/incoming_list.php', 1);
        $rows = array(
            array('total', 'KSEF_StatsTotalIncoming', 'total', '', ''),
            array('imported', 'KSEF_StatsImported', 'IMPORTED', ksefToneBadgeClass('ok'), '?search_import_status=IMPORTED'),
            array('new', 'KSEF_StatsToImport', 'NEW', ksefToneBadgeClass('action'), '?search_import_status=NEW'),
            array('skipped', 'KSEF_StatsSkipped', 'SKIPPED', ksefToneBadgeClass('info'), '?search_import_status=SKIPPED'),
            array('error', 'KSEF_StatsImportFailed', 'ERROR', ksefToneBadgeClass('problem'), '?search_import_status=ERROR'),
            array('fetch_error', 'KSEF_StatsFetchFailed', 'FETCH_ERROR', ksefToneBadgeClass('problem'), '?search_import_status=FETCH_ERROR'),
        );
    }
    $html = '';
    foreach ($rows as $i => $r) {
        $n = (int) ($counts[$r[2]] ?? 0);
        if ($i > 2 && $n === 0) {
            continue;
        }
        $value = $r[3] === '' ? '<a href="' . $url . '"><strong>' . $n . '</strong></a>' : '<a href="' . $url . $r[4] . '"><span class="badge ' . $r[3] . '">' . $n . '</span></a>';
        $html .= '<tr class="oddeven ksef-stat-row" data-stat="' . $r[0] . '"><td' . ($i === 0 ? ' class="titlefield"' : '') . '>' . $langs->trans($r[1]) . '</td><td class="right nowraponall">' . $value . '</td></tr>';
    }
    return $html;
}

/**
 * @brief Cron job status
 * @param array $job
 * @return string HTML
 */
function ksefCronJobStatusHtml($job)
{
    global $langs;
    if (empty($job['applicable'])) {
        $state = '<span class="badge ' . ksefToneBadgeClass('info') . ' badge-status">' . $langs->trans('KSEF_CronNotApplicable') . '</span>';
    } elseif (empty($job['status'])) {
        $state = '<span class="badge ' . ksefToneBadgeClass('info') . ' badge-status">' . $langs->trans('KSEF_CronDisabled') . '</span>';
    } elseif (!empty($job['failing'])) {
        $state = '<span class="badge ' . ksefToneBadgeClass('problem') . ' badge-status">' . $langs->trans('KSEF_CronFailing') . '</span>';
    } else {
        $state = '<span class="badge ' . ksefToneBadgeClass('ok') . ' badge-status">' . $langs->trans('KSEF_CronEnabled') . '</span>';
    }
    $last = !empty($job['datelastrun']) ? $langs->trans('KSEF_CronLastRun', dol_print_date($job['datelastrun'], 'dayhour')) : $langs->trans('KSEF_CronNeverRun');
    return $state . ' <span class="opacitymedium">' . $last . '</span> <a href="' . DOL_URL_ROOT . '/cron/card.php?id=' . (int) $job['rowid'] . '">' . $langs->trans('KSEF_CronOpenJob') . '</a>';
}

/**
 * @brief Keep stored option
 * @param array $options value => label
 * @param string $stored
 * @param string $label
 * @return array value => label
 */
function ksefSelectKeepCurrent($options, $stored, $label = '')
{
    global $langs;
    $stored = (string) $stored;
    if ($stored !== '' && !array_key_exists($stored, $options)) {
        $options[$stored] = ($label !== '') ? $label : $langs->trans('KSEF_UnsupportedCurrentValue', $stored);
    }
    return $options;
}

/**
 * @brief Excluded customers
 * @return int[]
 */
function ksefGetExcludedCustomers()
{
    $ids = array();
    foreach (explode(',', getDolGlobalString('KSEF_EXCLUDED_CUSTOMERS', '')) as $v) {
        $v = trim($v);
        if ($v !== '' && ctype_digit($v) && (int) $v > 0) {
            $ids[(int) $v] = (int) $v;
        }
    }
    return array_values($ids);
}

/**
 * @brief Store excluded customers
 * @param DoliDB $db
 * @param int[] $ids
 * @return int <0 on error
 */
function ksefSetExcludedCustomers($db, $ids)
{
    global $conf;
    require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
    $clean = array();
    foreach ($ids as $id) {
        if ((int) $id > 0) {
            $clean[(int) $id] = (int) $id;
        }
    }
    if ($clean) {
        $found = array();
        $resql = $db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "societe WHERE rowid IN (" . implode(',', $clean) . ")");
        while ($resql && ($o = $db->fetch_object($resql))) {
            $found[(int) $o->rowid] = (int) $o->rowid;
        }
        $clean = array_intersect_key($clean, $found);
    }
    return dolibarr_set_const($db, 'KSEF_EXCLUDED_CUSTOMERS', implode(',', array_values($clean)), 'chaine', 0, '', $conf->entity);
}

/**
 * @brief GUS error text
 * @param Translate $langs
 * @param string $code
 * @return string
 */
function ksefGusErrorText($langs, $code)
{
    $map = array(
        'no_key' => 'KSEF_GusErrNoKey',
        'auth_failed' => 'KSEF_GusErrAuthFailed',
        'transport_error' => 'KSEF_GusErrTransport',
        'http_error' => 'KSEF_GusErrTransport',
        'parse_error' => 'KSEF_GusErrTransport',
        'session' => 'KSEF_GusErrSession',
        'session_error' => 'KSEF_GusErrSession',
    );
    $key = isset($map[$code]) ? $map[$code] : 'KSEF_GusError';
    $text = $langs->transnoentitiesnoconv($key);
    if ($code === 'no_key' && getDolGlobalString('KSEF_GUS_ENV', 'TEST') === 'PROD') {
        $text .= ' ' . $langs->transnoentitiesnoconv('KSEF_GusErrNoKeyHint');
    }
    return $text;
}

/**
 * @brief Unsaved changes guard
 * @return string HTML script
 */
function ksefAdminDirtyGuardJs()
{
    return '<script>
(function () {
    function mark(el) {
        var f = el && el.form;
        if (f && f.hasAttribute("data-ksef-guarded")) { f.setAttribute("data-ksef-dirty", "1"); }
    }
    document.addEventListener("input", function (e) { mark(e.target); }, true);
    document.addEventListener("change", function (e) { mark(e.target); }, true);
    // select2: jQuery only
    if (window.jQuery) { jQuery(document).on("change", "select", function () { mark(this); }); }
    document.addEventListener("submit", function (e) { if (e.target && e.target.removeAttribute) { e.target.removeAttribute("data-ksef-dirty"); } }, true);
    window.addEventListener("beforeunload", function (e) {
        if (document.querySelector("form[data-ksef-dirty]")) { e.preventDefault(); e.returnValue = ""; return ""; }
    });
})();
</script>';
}

/**
 * @brief Settings tab URL
 * @param string $tab
 * @return string '' if unknown
 */
function ksefConfigTabUrl($tab)
{
    $pages = array('general' => 'setup.php', 'auth' => 'setup_auth.php', 'outgoing' => 'setup_outgoing.php', 'fields' => 'setup_fields.php', 'incoming' => 'setup_incoming.php', 'dictionaries' => 'setup_dictionaries.php', 'about' => 'about.php');
    return isset($pages[$tab]) ? dol_buildpath('/ksef/admin/' . $pages[$tab], 1) : '';
}

/**
 * @brief Notes mode options
 * @return array value => lang key
 */
function ksefDodatkowyOpisModeOptions()
{
    return array(
        ''                   => 'KSEF_DodatkowyOpisMode_Default',
        'simple_stopka'      => 'KSEF_DodatkowyOpisMode_SimpleStopka',
        'simple_dodatkowy'   => 'KSEF_DodatkowyOpisMode_SimpleDodatkowy',
        'keyvalue_dodatkowy' => 'KSEF_DodatkowyOpisMode_KeyValueDodatkowy',
        'disabled'           => 'KSEF_DodatkowyOpisMode_Disabled',
    );
}

/**
 * @brief Ini size bytes
 * @param string $v
 * @return int
 */
function ksefIniBytes($v)
{
    $v = trim((string) $v);
    $n = (int) $v;
    switch (strtoupper(substr($v, -1))) {
        case 'G':
            $n *= 1024;
            // no break
        case 'M':
            $n *= 1024;
            // no break
        case 'K':
            $n *= 1024;
    }
    return $n;
}

/**
 * @brief Save secret const
 * @param DoliDB $db
 * @param string $name
 * @param string $value Plain stored form
 * @param int $entity
 * @return int
 */
function ksefSetSecretConst($db, $name, $value, $entity)
{
    global $conf;
    require_once DOL_DOCUMENT_ROOT . '/core/lib/security.lib.php';
    $res = dolibarr_set_const($db, $name, dolEncrypt((string) $value), 'chaine', 0, '', $entity);
    if ($res > 0) {
        $conf->global->$name = (string) $value;
    }
    return $res;
}

/**
 * @brief Key password missing
 * @param string $kind AUTH or OFFLINE
 * @param string $env
 * @return bool
 */
function ksefKeyPasswordMissing($kind, $env)
{
    $key = getDolGlobalString(($kind === 'OFFLINE' ? 'KSEF_OFFLINE_PRIVATE_KEY_' : 'KSEF_AUTH_PRIVATE_KEY_') . $env, '');
    if ($key === '' || getDolGlobalString(($kind === 'OFFLINE' ? 'KSEF_OFFLINE_KEY_PASSWORD_' : 'KSEF_AUTH_KEY_PASSWORD_') . $env, '') !== '') {
        return false;
    }
    return strpos((string) base64_decode($key), 'ENCRYPTED') !== false;
}

/**
 * @brief Download file name
 * @param string $prefix FA3 or UPO
 * @param string $number KSeF number
 * @param string $fallback
 * @param string $ext
 * @return string
 */
function ksefDownloadName($prefix, $number, $fallback, $ext)
{
    $base = trim((string) $number) !== '' ? (string) $number : (string) $fallback;
    return dol_sanitizeFileName($prefix . '_' . $base . '.' . $ext);
}

/**
 * @brief Send download
 * @param string $content
 * @param string $filename
 * @param string $mime
 * @return void
 */
function ksefSendDownload($content, $filename, $mime)
{
    header('Content-Type: ' . $mime . ($mime === 'application/xml' ? '; charset=utf-8' : ''));
    header('Content-Disposition: attachment; filename="' . dol_sanitizeFileName($filename) . '"');
    header('Content-Length: ' . strlen((string) $content));
    echo $content;
}
/**
 * @brief Status dot
 * @param string $status
 * @param string $title Tooltip
 * @param bool $incoming
 * @return string HTML
 */
function ksefStatusDotHtml($status, $title, $incoming = false)
{
    if ($incoming) {
        $map = ksefIncomingStatusMap();
        $class = isset($map[$status]) ? 'badge-' . $map[$status][0] . ' ' . ksefToneClass($map[$status][2]) : 'badge-status0';
    } else {
        $tone = ksefOutgoingStatusTone($status);
        $class = $tone !== '' ? ksefToneBadgeClass($tone) : 'badge-status0';
    }
    return '<span class="badge badge-dot ' . $class . ' classfortooltip badge-status" title="' . dol_escape_htmltag($title) . '"></span>';
}
/**
 * @brief Invoice type label
 * @param string $type
 * @return string
 */
function ksefInvoiceTypeLabel($type)
{
    global $langs;
    $key = 'KSEF_FA3Type_' . preg_replace('/[^A-Z_]/', '', strtoupper((string) $type));
    $text = $langs->transnoentitiesnoconv($key);
    return ($text !== $key) ? $text : (string) $type;
}
/**
 * @brief Pre-1.5.0 cron labels
 * @return array Method => label
 */
function ksefCronOldLabels()
{
    return array(
        'cronCheckStatuses' => 'KSEF - Check pending/submitted invoice statuses',
        'cronSyncIncoming' => 'KSEF - Sync incoming invoices',
        'cronDownloadUPOs' => 'KSEF - Download UPO confirmations',
        'cronWarnDeadlines' => 'KSEF - Warn approaching offline deadlines',
        'cronCheckLatarniaStatus' => 'KSEF - Check Latarnia (lighthouse) system status',
        'cronSyncMulticurrencyRates' => 'KSEF - Sync NBP currency rates (D-1)',
    );
}

/**
 * @brief Relabel cron jobs
 * @param DoliDB $db
 * @return int Rows renamed
 */
function ksefCronRelabel($db)
{
    $old = ksefCronOldLabels();
    $done = 0;
    $t = MAIN_DB_PREFIX . 'cronjob';
    foreach ($old as $method => $label) {
        $new = 'KSEF_CronJob_' . $method . ':ksef@ksef';
        $resql = $db->query("SELECT rowid, entity FROM " . $t . " WHERE label = '" . $db->escape($label) . "' AND methodename = '" . $db->escape($method) . "'");
        while ($resql && ($o = $db->fetch_object($resql))) {
            $db->query("DELETE FROM " . $t . " WHERE label = '" . $db->escape($new) . "' AND entity = " . (int) $o->entity . " AND rowid <> " . (int) $o->rowid);
            if ($db->query("UPDATE " . $t . " SET label = '" . $db->escape($new) . "' WHERE rowid = " . (int) $o->rowid)) {
                $done++;
            } else {
                dol_syslog('ksefCronRelabel ' . $method . ': ' . $db->lasterror(), LOG_ERR);
            }
        }
    }
    return $done;
}

/**
 * @brief Find cron row
 * @param DoliDB $db
 * @param array $job Descriptor entry
 * @param int $entity
 * @return object|null By label, else by method (label renamed by the admin)
 */
function ksefCronFind($db, $job, $entity)
{
    $sql = "SELECT rowid, classesname, objectname, methodename FROM " . MAIN_DB_PREFIX . "cronjob WHERE entity = " . (int) $entity;
    $where = array(" AND label = '" . $db->escape($job['label']) . "'");
    if (!empty($job['method'])) {
        $old = ksefCronOldLabels();
        $where[] = " AND module_name = 'ksef' AND methodename = '" . $db->escape($job['method']) . "'"
            . (isset($old[$job['method']]) ? " AND label <> '" . $db->escape($old[$job['method']]) . "'" : '') . " ORDER BY rowid";
    }
    foreach ($where as $w) {
        $resql = $db->query($sql . $w);
        if ($resql && ($obj = $db->fetch_object($resql))) {
            return $obj;
        }
    }
    return null;
}

/**
 * @brief P_11Vat setting
 * @return string 'auto' or '1'
 */
function ksefLineVatMode()
{
    $v = trim(getDolGlobalString('KSEF_FA3_INCLUDE_LINE_VAT', 'auto'));
    return $v === '1' ? '1' : 'auto';
}

/**
 * @brief Emit P_11Vat
 * @param array $sets Line sets
 * @param array $opt
 * @return bool
 */
function ksefLineVatWanted($sets, $opt = array())
{
    $mode = ksefLineVatMode();
    if (!empty($opt['gross'])) {
        return false;
    }
    if ($mode === '1') {
        return true;
    }
    $base = array('pln' => !empty($opt['pln']));
    if (isset($opt['key'])) {
        $base['key'] = $opt['key'];
    }
    foreach ((array) $sets as $lines) {
        if (empty($lines)) {
            continue;
        }
        $sum = ksefForeignRateSummary((array) $lines, $base);
        $pkt14 = ksefForeignRateSummary((array) $lines, $base + array('round_total' => true));
        foreach ($sum['keys'] as $key => $a) {
            if ($a['rate'] !== null && $a['rate'] > 0 && abs($a['vat'] - (float) $pkt14['keys'][$key]['vat']) >= 0.005) {
                return true;
            }
        }
    }
    return false;
}

/**
 * @brief Gross method invoice
 * @param DoliDB $db
 * @param int $invoiceId
 * @return bool
 */
function ksefInvoiceGrossMethod($db, $invoiceId)
{
    $src = getDolGlobalString('KSEF_FA3_GROSS_SOURCE', 'disabled');
    if ($src === '' || $src === 'disabled') {
        return false;
    }
    if ($src === 'always_on') {
        return true;
    }
    $res = $db->query("SELECT fk_soc, fk_facture_source, type FROM " . MAIN_DB_PREFIX . "facture WHERE rowid = " . (int) $invoiceId);
    $h = $res ? $db->fetch_object($res) : null;
    if (!$h) {
        return false;
    }
    $field = '';
    if (strpos($src, 'thirdparty_extrafield:') === 0) {
        $field = substr($src, strlen('thirdparty_extrafield:'));
        $table = 'societe_extrafields';
        $ids = array((int) $h->fk_soc);
    } elseif (strpos($src, 'extrafield:') === 0) {
        $field = substr($src, strlen('extrafield:'));
        $table = 'facture_extrafields';
        $ids = (int) $h->fk_facture_source > 0 && in_array((int) $h->type, array(1, 2), true) ? array((int) $h->fk_facture_source, (int) $invoiceId) : array((int) $invoiceId);
    }
    if ($field === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $field)) {
        return false;
    }
    $res = $db->query("SELECT fk_object, " . $field . " AS v FROM " . MAIN_DB_PREFIX . $table . " WHERE fk_object IN (" . implode(',', $ids) . ")");
    $vals = array();
    while ($res && ($o = $db->fetch_object($res))) {
        $vals[(int) $o->fk_object] = $o->v;
    }
    foreach ($ids as $id) {
        if (!empty($vals[$id])) {
            return true;
        }
    }
    return false;
}

/**
 * @brief Restore credit lines
 * @param DoliDB $db
 * @param int $invoiceId
 * @return int Lines, -1 error
 */
function ksefSupplierCreditLinesAsStated($db, $invoiceId)
{
    $P = MAIN_DB_PREFIX;
    $res = $db->query("SELECT d.rowid, d.total_ht, d.tva, d.total_ttc, d.multicurrency_total_ht, d.multicurrency_total_tva, d.multicurrency_total_ttc,"
        . " r.amount_ht, r.amount_tva, r.amount_ttc, r.multicurrency_amount_ht, r.multicurrency_amount_tva, r.multicurrency_amount_ttc"
        . " FROM " . $P . "facture_fourn_det AS d INNER JOIN " . $P . "societe_remise_except AS r ON r.rowid = d.fk_remise_except"
        . " WHERE d.fk_facture_fourn = " . (int) $invoiceId . " AND r.multicurrency_amount_ttc <> 0");
    if (!$res) {
        return -1;
    }
    $shift = array();
    while ($o = $db->fetch_object($res)) {
        $mtva = (float) price2num((float) $o->multicurrency_total_tva + (float) $o->multicurrency_amount_tva, 'MT');
        $mttc = (float) price2num((float) $o->multicurrency_total_ttc + (float) $o->multicurrency_amount_ttc, 'MT');
        if (abs((float) $o->multicurrency_total_ht + (float) $o->multicurrency_amount_ht) >= 0.005 || (abs($mtva) < 0.005 && abs($mttc) < 0.005)) {
            continue;
        }
        $pln = abs((float) $o->total_ht + (float) $o->amount_ht) < 0.005;
        $shift[(int) $o->rowid] = array('ht' => 0.0, 'tva' => $pln ? (float) price2num((float) $o->tva + (float) $o->amount_tva, 'MT') : 0.0,
            'ttc' => $pln ? (float) price2num((float) $o->total_ttc + (float) $o->amount_ttc, 'MT') : 0.0, 'mht' => 0.0, 'mtva' => $mtva, 'mttc' => $mttc, 'pu' => 0.0);
    }
    foreach ($shift as $lineId => $d) {
        if (ksefInvoiceLineShift($db, $lineId, $invoiceId, $d, 'invoice_supplier', false) !== '') {
            return -1;
        }
    }
    return count($shift);
}

/**
 * @brief Classify paid refusal
 * @param Facture|FactureFournisseur $invoice
 * @param string $action 'paid', 'confirm_paid' or ''
 * @return string '' if allowed
 */
function ksefClassifyPaidRefusal($invoice, $action = '')
{
    global $conf, $langs;
    if (!isModEnabled('multicurrency') || !is_object($invoice) || empty($invoice->id) || !in_array($invoice->element ?? '', array('facture', 'invoice_supplier'), true)) {
        return '';
    }
    $code = (string) $invoice->multicurrency_code;
    if ($code === '' || $code === $conf->currency) {
        return '';
    }
    $kind = ksefInvoiceKind($invoice);
    if ((int) $invoice->status !== Facture::STATUS_VALIDATED || !empty($invoice->paye) || !empty($invoice->paid)
        || !in_array((int) $invoice->type, array(Facture::TYPE_STANDARD, Facture::TYPE_REPLACEMENT, Facture::TYPE_DEPOSIT, Facture::TYPE_SITUATION), true)) {
        return '';
    }
    $remain = (float) price2num($invoice->getRemainToPay(1), 'MT');
    if ($remain <= 0) {
        return '';
    }
    if ($action === 'paid') {
        $pln = (float) price2num($invoice->getRemainToPay(0), 'MT');
        $anyway = getDolGlobalString($kind['element'] === 'invoice_supplier' ? 'SUPPLIER_INVOICE_CAN_SET_PAID_EVEN_IF_PARTIALLY_PAID' : 'INVOICE_CAN_SET_PAID_EVEN_IF_PARTIALLY_PAID')
            && $pln == (float) price2num($invoice->total_ttc, 'MT');
        if ($pln >= 0 && !$anyway) {
            return '';
        }
        if (!$anyway && ksefForeignOwedCloseState($invoice)['applies']) {
            return '';
        }
    }
    $langs->load('ksef@ksef');
    $why = $langs->transnoentitiesnoconv('KSEF_ClassifyPaidCurrencyOwed', $code, str_replace("\xc2\xa0", ' ', html_entity_decode(price($remain, 0, $langs, 1, -1, 2, $code), ENT_QUOTES, 'UTF-8')));
    if ((int) $invoice->type === Facture::TYPE_DEPOSIT) {
        $why .= ' ' . $langs->transnoentitiesnoconv($kind['element'] === 'invoice_supplier' ? 'KSEF_ClassifyPaidCurrencyOwedDepositSupplier' : 'KSEF_ClassifyPaidCurrencyOwedDeposit');
    }
    return $why;
}

/**
 * @brief Classify paid caller
 * @return string 'rest', 'card' or ''
 */
function ksefClassifyPaidCall()
{
    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $f) {
        if (($f['function'] ?? '') === 'settopaid' && in_array($f['class'] ?? '', array('Invoices', 'SupplierInvoices'), true)) {
            return 'rest';
        }
    }
    if (GETPOST('action', 'aZ09') === 'confirm_paid' && preg_match('#/(compta|fourn)/facture/card\.php$#', (string) ($_SERVER['PHP_SELF'] ?? ''))) {
        return 'card';
    }
    return '';
}

/**
 * @brief Classify paid guard
 * @param DoliDB $db
 * @param Facture|FactureFournisseur $invoice
 * @return array
 */
function ksefSetPaidOwedRefusal($db, $invoice)
{
    global $langs;
    $out = array('why' => '', 'call' => '');
    if (!is_object($invoice) || empty($invoice->id) || !in_array($invoice->element ?? '', array('facture', 'invoice_supplier'), true)) {
        return $out;
    }
    $out['call'] = ksefClassifyPaidCall();
    if ($out['call'] === '') {
        return $out;
    }
    $k = ksefInvoiceKind($invoice);
    // close_code first
    $row = $db->getRow("SELECT close_code FROM " . MAIN_DB_PREFIX . $k['table'] . " WHERE rowid = " . (int) $invoice->id);
    if (!$row || trim((string) $row->close_code) !== '') {
        return $out;
    }
    $why = ksefClassifyPaidRefusal($invoice, 'confirm_paid');
    if ($why !== '' && $out['call'] === 'rest') {
        $langs->load('ksef@ksef');
        $why .= ' ' . $langs->transnoentitiesnoconv('KSEF_ClassifyPaidCurrencyOwedRest');
    }
    $out['why'] = $why;
    return $out;
}

/**
 * @brief Plain module message
 * @param string $key Lang key
 * @return string
 */
function ksefMsg($key)
{
    global $langs;
    $args = func_get_args();
    if (!is_object($langs)) {
        return implode(' ', $args);
    }
    $langs->load('ksef@ksef');
    return call_user_func_array(array($langs, 'transnoentitiesnoconv'), $args);
}
