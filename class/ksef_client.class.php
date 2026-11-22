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
 * \file    ksef/class/ksef_client.class.php
 * \ingroup ksef
 * \brief   KSeF API client
 */

dol_include_once('/ksef/lib/ksef.lib.php');

use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\PublicKeyLoader;

class KsefClient
{
    private $db;
    private $api_url;
    private $environment;
    private $nip;
    private $ksef_token;
    private $session_token;
    private $refresh_token;
    private $session_expiry;
    private $timeout;
    private $public_key_pem;
    private $public_key_id;
    private $public_key_certs;
    private $export_keys = array();
    private $in_reauth = false;
    private $last_curl_errno = 0;
    private $auth_stage = '';
    public $errors = [];
    public $error;
    public $last_error_code;
    public $last_session_reference;
    public $last_invoice_reference;
    public $last_error_details = [];
    public $last_http_code;
    public $last_transport_error = false;
    public $last_response_headers = array();
    public $retry_after_seconds;
    public $rate_limited_hits = 0;
    public $system_warnings = array();
    public $retry_wait_cap = 10;
    public $last_upo_hash;
    public $last_upo_hash_ok;
    public $last_part_hash = '';
    public $last_session_pages = 0;
    public $last_session_complete = true;

    const SESSION_INVOICE_PAGE_SIZE = 1000;
    const SESSION_INVOICE_PAGES = 3;

    private $sessionExpiryBuffer = 300;
    private $auth_method;
    private $auth_certificate_pem;
    private $auth_private_key_pem;
    private $auth_certificate_password;

    // not /api/v2
    const API_TEST = 'https://api-test.ksef.mf.gov.pl/v2';
    const API_DEMO = 'https://api-demo.ksef.mf.gov.pl/v2';
    const API_PROD = 'https://api.ksef.mf.gov.pl/v2';

    const ERROR_CODES = array(
        9101 => 'Nieprawidłowy dokument',
        9102 => 'Brak podpisu',
        9103 => 'Przekroczona liczba dozwolonych podpisów',
        9104 => 'Niewystarczająca liczba wymaganych podpisów',
        9105 => 'Nieprawidłowa treść podpisu',
        9106 => 'Nieprawidłowa liczba referencji podpisu',
        9107 => 'Niezgodność lub nieprawidłowa referencja podpisanych danych',
        9108 => 'Nieprawidłowa liczba danych referencji podpisu',
        9109 => 'Brak danych referencji podpisu',
        9110 => 'Brak referencji do danych podpisu',
        20001 => 'Niedozwolone sekcje dokumentu',
        20002 => 'Niedozwolone sekcje dokumentu [DTD]',
        20003 => 'Niedozwolone sekcje dokumentu [CDATA]',
        20004 => 'Nieprawidłowo zakodowana treść (base64)',
        20005 => 'Nieprawidłowo zaszyfrowana treść',
        21001 => 'Nieczytelna treść',
        21111 => 'Nieprawidłowe wyzwanie autoryzacyjne',
        21112 => 'Nieprawidłowy czas tokena',
        21113 => 'Żądanie autoryzacji wygasło',
        21114 => 'Nieprawidłowy kontekst Profilu Zaufanego (PZ)',
        21115 => 'Nieprawidłowy certyfikat',
        21116 => 'Nieprawidłowy token',
        21121 => 'Limit żądań osiągnięty',
        21132 => 'Brak treści żądania wysyłki potwierdzenia',
        21133 => 'Brak treści faktury żądania wysyłki faktury',
        21134 => 'Brak treści potwierdzenia żądania wysyłki potwierdzenia',
        21135 => 'Brak definicji pakietu',
        21136 => 'Brak definicji szyfrowania',
        21137 => 'Brak sygnatury pliku faktury',
        21138 => 'Brak sygnatury pliku potwierdzenia',
        21139 => 'Brak sygnatury pliku pakietu',
        21140 => 'Brak sygnatury pakietu',
        21141 => 'Brak listy części pakietu',
        21142 => 'Nieprawidłowy typ algorytmu szyfrowania',
        21143 => 'Nieprawidłowy typ klucza szyfrującego',
        21144 => 'Nieprawidłowy typ wektora inicjalizacyjnego',
        21145 => 'Nieprawidłowy typ dokumentu',
        21146 => 'Nieprawidłowy typ żądania wysyłki faktury',
        21147 => 'Sprzeczny typ żądania wysyłki faktury',
        21148 => 'Brak numeru referencyjnego',
        21149 => 'Brak sesji',
        21150 => 'Sesja wsadowa wygasła',
        21151 => 'Sesja wsadowa nieaktywna',
        21153 => 'Sesja interaktywna nieaktywna',
        21154 => 'Sesja interaktywna zakończona',
        21156 => 'Nieprawidłowa definicja części pakietu',
        21157 => 'Nieprawidłowy rozmiar części pakietu',
        21158 => 'Nieprawidłowy skrót części pakietu',
        21159 => 'Nieprawidłowy podpis',
        21160 => 'Nieprawidłowy kontekst',
        21161 => 'Incorrect range',
        21162 => 'Nieprawidłowe żądanie',
        21164 => 'Faktura o podanym identyfikatorze nie istnieje',
        21167 => 'Nie znaleziono zapytania o paczkę z podanym numerem referencyjnym',
        21168 => 'Poświadczenia o podanym identyfikatorze nie istnieją',
        21169 => 'Brak autoryzacji lub faktura o podanym identyfikatorze nie istnieje',
        21170 => 'Sesja interaktywna wygasła',
        21171 => 'Brak tokena sesyjnego',
        21172 => 'Pusta treść żądania',
        21173 => 'Brak sesji o wskazanym numerze referencyjnym',
        21174 => 'Brak nazwy części',
        21175 => 'Wynik zapytania o podanym identyfikatorze nie istnieje',
        21176 => 'Duplikat faktury w kontekście sesji',
        21177 => 'Przekroczona maksymalna liczba wyników. Doprecyzuj kryteria',
        21204 => 'Pakiet nie może być zduplikowany',
        21205 => 'Pakiet nie może być pusty',
        21206 => 'Część listy pakietu nie może być pusta',
        21207 => 'Lista elementów pakietu nie może być pusta',
        21208 => 'Czas oczekiwania na requesty upload lub finish został przekroczony',
        21211 => 'Nieprawidłowa deklaracja formularza dokumentu',
        21212 => 'Nieprawidłowy wystawca dokumentu',
        21213 => 'Nieprawidłowy klucz szyfrujący',
        21214 => 'Nieprawidłowe kodowanie dokumentu',
        21215 => 'Nieprawidłowy kontekst szyfrowania',
        21216 => 'Nieprawidłowa kompresja',
        21217 => 'Nieprawidłowe kodowanie znaków',
        21218 => 'Duplikat faktury w kontekście pakietu',
        21301 => 'Brak autoryzacji',
        21302 => 'Token nieaktywny',
        21303 => 'Token unieważniony',
        21304 => 'Brak uwierzytelnienia',
        21305 => 'Brak uwierzytelnienia certyfikatu',
        21401 => 'Dokument nie jest zgodny ze schemą (xsd)',
        21402 => 'Nieprawidłowy rozmiar pliku',
        21403 => 'Nieprawidłowy skrót pliku',
        21404 => 'Nieprawidłowy format dokumentu (json)',
        21405 => 'Dokument nie jest zgodny ze schemą (json)',
        21406 => 'Konflikt podpisu i typu uwierzytelnienia',
        21407 => 'Nieprawidłowy podmiot podpisu',
        21408 => 'Nieprawidłowy numer referencyjny',
        21409 => 'Token o podanym identyfikatorze nie istnieje',
        21178 => 'Brak UPO dla wskazanej faktury',
        21180 => 'Status sesji nie pozwala na wykonanie operacji',
        21182 => 'Osiągnięto limit trwających eksportów',
        21184 => 'Sesja tymczasowo niedostępna, otwórz nową sesję',
        21470 => 'Nieznany lub wycofany identyfikator klucza publicznego',
        23001 => 'Brak treści',
        100 => 'Sesja interaktywna otwarta',
        200 => 'Przetwarzanie zakończone sukcesem',
        440 => 'Duplikat faktury - numer faktury już istnieje w KSeF',
        445 => 'Błąd weryfikacji, brak poprawnych faktur'
    );

    /**
     * @brief Constructor
     * @param DoliDB $db Database handler
     * @param string|null $environment Null: configured
     */
    public function __construct($db, $environment = null)
    {
        require_once DOL_DOCUMENT_ROOT . '/core/lib/security.lib.php';

        $this->db = $db;

        if ($environment === null) {
            $environment = ksefGetEnvironment();
        }
        $this->environment = strtoupper($environment);

        switch ($this->environment) {
            case 'PRODUCTION':
                $this->api_url = self::API_PROD;
                break;
            case 'DEMO':
                $this->api_url = self::API_DEMO;
                break;
            case 'TEST':
            default:
                $this->api_url = self::API_TEST;
                break;
        }
        $this->api_url = ksefEndpointOverride('KSEF', $this->api_url);

        $envKey = $this->environment;
        $this->nip = ksefSellerNip();
        $this->auth_method = getDolGlobalString('KSEF_AUTH_METHOD_' . $envKey, 'token');
        $encrypted_token = getDolGlobalString('KSEF_AUTH_TOKEN_' . $envKey, '');
        $this->ksef_token = !empty($encrypted_token) ? dol_decode($encrypted_token) : '';
        $this->auth_certificate_pem = '';
        $this->auth_private_key_pem = '';
        $this->timeout = ksefGetTimeout();
    }

    /**
     * @brief Load auth certificate
     * @return bool
     */
    private function loadAuthCertificateCredentials()
    {
        $credentials = ksefLoadAuthCertificate($this->environment);
        if (!$credentials) {
            $this->error = ksefMsg('KSEF_ErrAuthCertNotConfigured');
            return false;
        }

        $this->auth_certificate_pem = $credentials['certificate_pem'];
        $this->auth_private_key_pem = $credentials['private_key_pem'];

        dol_syslog("KsefClient: Authentication credentials loaded successfully", LOG_DEBUG);
        return true;
    }

    /**
     * @brief Error code description
     * @param $code
     * @return string
     */
    public function getErrorDescription($code)
    {
        return isset(self::ERROR_CODES[$code]) ? self::ERROR_CODES[$code] : 'Unknown error';
    }

    /**
     * @brief Parse error body
     * @param $response
     * @return array
     */
    private function parseErrorResponse($response)
    {
        $errorDetails = array(
            'code' => null,
            'description' => null,
            'details' => array(),
            'service_code' => null,
            'timestamp' => null,
            'title' => null,
            'reason_code' => null,
            'trace_id' => null
        );

        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $response)));
            if ($text !== '') {
                $errorDetails['description'] = dol_trunc($text, 200);
            }
            return $errorDetails;
        }

        $lists = array();
        if (isset($data['exception']['exceptionDetailList']) && is_array($data['exception']['exceptionDetailList'])) {
            foreach ($data['exception']['exceptionDetailList'] as $x) {
                $lists[] = array($x['exceptionCode'] ?? null, $x['exceptionDescription'] ?? '', $x['details'] ?? array());
            }
        }
        if (isset($data['errors']) && is_array($data['errors'])) {
            foreach ($data['errors'] as $x) {
                if (is_array($x)) {
                    $lists[] = array($x['code'] ?? null, $x['description'] ?? '', $x['details'] ?? array());
                }
            }
        }
        foreach ($lists as $entry) {
            list($code, $desc, $details) = $entry;
            $code = is_numeric($code) ? (int) $code : null;
            if ($errorDetails['code'] === null && $errorDetails['description'] === null) {
                $errorDetails['code'] = $code;
                $errorDetails['description'] = (string) $desc;
            } elseif ($code !== null || $desc !== '') {
                $errorDetails['details'][] = trim(($code !== null ? $code . ': ' : '') . $desc);
            }
            foreach ((array) $details as $d) {
                if (is_scalar($d) && (string) $d !== '') {
                    $errorDetails['details'][] = (string) $d;
                }
            }
        }

        if (isset($data['exception']['serviceCode'])) {
            $errorDetails['service_code'] = $data['exception']['serviceCode'];
        }
        if (isset($data['exception']['timestamp'])) {
            $errorDetails['timestamp'] = $data['exception']['timestamp'];
        }

        if (isset($data['status']) && is_array($data['status'])) {
            if ($errorDetails['code'] === null && isset($data['status']['code']) && is_numeric($data['status']['code'])) {
                $errorDetails['code'] = (int) $data['status']['code'];
            }
            if (!$errorDetails['description'] && isset($data['status']['description'])) {
                $errorDetails['description'] = (string) $data['status']['description'];
            }
            foreach ((array) ($data['status']['details'] ?? array()) as $d) {
                if (is_scalar($d) && (string) $d !== '') {
                    $errorDetails['details'][] = (string) $d;
                }
            }
        }

        if (isset($data['title']) || isset($data['detail'])) {
            $errorDetails['title'] = isset($data['title']) ? (string) $data['title'] : null;
            $detail = isset($data['detail']) ? trim((string) $data['detail']) : '';
            if (!$errorDetails['description']) {
                $errorDetails['description'] = $detail !== '' ? $detail : $errorDetails['title'];
            } elseif ($detail !== '' && $detail !== $errorDetails['description']) {
                array_unshift($errorDetails['details'], $detail);
            }
            if (isset($data['reasonCode']) && is_scalar($data['reasonCode'])) {
                $errorDetails['reason_code'] = (string) $data['reasonCode'];
            }
            if (isset($data['traceId']) && is_scalar($data['traceId'])) {
                $errorDetails['trace_id'] = (string) $data['traceId'];
            }
            if (!$errorDetails['timestamp'] && isset($data['timestamp'])) {
                $errorDetails['timestamp'] = $data['timestamp'];
            }
        }

        $errorDetails['details'] = array_values(array_unique($errorDetails['details']));
        return $errorDetails;
    }

    /**
     * @brief Format error details
     * @param $errorDetails
     * @return string
     */
    public function formatErrorMessage($errorDetails)
    {
        $message = '';

        if (!empty($errorDetails['code'])) {
            $known = isset(self::ERROR_CODES[$errorDetails['code']]) ? self::ERROR_CODES[$errorDetails['code']] : '';
            if ($known !== '') {
                $message .= "KSeF Error {$errorDetails['code']}: {$known}";
                if (!empty($errorDetails['description']) && $errorDetails['description'] != $known) {
                    $message .= " ({$errorDetails['description']})";
                }
            } else {
                $message .= "KSeF Error {$errorDetails['code']}: " . (!empty($errorDetails['description']) ? $errorDetails['description'] : $this->getErrorDescription($errorDetails['code']));
            }
        } elseif (!empty($errorDetails['description'])) {
            $message .= "KSeF Error: {$errorDetails['description']}";
        }

        if (!empty($errorDetails['reason_code'])) {
            $message .= " (reason: {$errorDetails['reason_code']})";
        }

        if (!empty($errorDetails['details'])) {
            $message .= "\nDetails: " . implode('; ', $errorDetails['details']);
        }

        if (!empty($errorDetails['service_code'])) {
            $message .= "\nService Code: {$errorDetails['service_code']}";
        }

        if (!empty($errorDetails['trace_id'])) {
            $message .= "\nTrace ID: {$errorDetails['trace_id']}";
        }

        return $message;
    }

    /**
     * @brief Parse invoice status
     * @param array $statusData
     * @return array
     */
    public function parseInvoiceStatus($statusData)
    {
        $statusData = is_array($statusData) ? $statusData : array();
        $st = isset($statusData['status']) && is_array($statusData['status']) ? $statusData['status'] : array();
        $code = isset($st['code']) ? (int) $st['code'] : null;
        $details = isset($st['details']) ? (array) $st['details'] : array();
        $result = array(
            'status' => 'PENDING',
            'code' => $code,
            'description' => isset($st['description']) ? (string) $st['description'] : '',
            'details' => array_values(array_filter(array_map('strval', $details), 'strlen')),
            'extensions' => isset($st['extensions']) && is_array($st['extensions']) ? $st['extensions'] : array(),
            'ksef_number' => isset($statusData['ksefNumber']) ? $statusData['ksefNumber'] : null,
            'reference_number' => isset($statusData['referenceNumber']) ? $statusData['referenceNumber'] : null,
            'invoice_hash' => isset($statusData['invoiceHash']) ? $statusData['invoiceHash'] : null,
            'acquisition_date' => isset($statusData['acquisitionDate']) ? $statusData['acquisitionDate'] : null,
            'invoicing_mode' => isset($statusData['invoicingMode']) ? $statusData['invoicingMode'] : null,
            'upo_download_url' => isset($statusData['upoDownloadUrl']) ? $statusData['upoDownloadUrl'] : null,
            'raw_response' => $statusData
        );
        if ($code === 200 && !empty($result['ksef_number'])) {
            $result['status'] = 'ACCEPTED';
        } elseif ($code !== null && $code >= 400) {
            $result['status'] = 'FAILED';
        }
        return $result;
    }

    /**
     * @brief Failed status details
     * @param array $status
     * @return array
     */
    private function rejectionDetails($status)
    {
        $d = array(
            'code' => $status['code'],
            'description' => $status['description'],
            'details' => $status['details'],
        );
        if (!empty($status['extensions'])) {
            $d['extensions'] = $status['extensions'];
        }
        if ((int) $status['code'] === 440) {
            $d['original_ksef_number'] = $status['extensions']['originalKsefNumber'] ?? null;
            $d['original_session'] = $status['extensions']['originalSessionReferenceNumber'] ?? null;
        }
        return $d;
    }

    /**
     * @brief Failed status message
     * @param array $status
     * @return string
     */
    private function rejectionMessage($status)
    {
        if ((int) $status['code'] === 440) {
            $msg = 'Duplicate invoice number - already exists in KSeF';
            if (!empty($status['extensions']['originalKsefNumber'])) {
                $msg .= ' as ' . $status['extensions']['originalKsefNumber'];
            }
            return $msg . ' (KSeF 440)';
        }
        $msg = 'Invoice rejected by KSeF (' . $status['code'] . ')';
        if ($status['description'] !== '') {
            $msg .= ': ' . $status['description'];
        }
        if (!empty($status['details'])) {
            $msg .= ' - ' . implode('; ', $status['details']);
        }
        return $msg;
    }

    /**
     * @brief Get invoice status
     * @param $sessionRef
     * @param $invoiceRef
     * @return array|false
     */
    public function checkInvoiceInSession($sessionRef, $invoiceRef)
    {
        $this->authenticate();

        try {
            $response = $this->makeRequest('GET', "/sessions/{$sessionRef}/invoices/{$invoiceRef}", null, array('Accept: application/json'),
                array('auth' => true, 'retry' => 'safe'));

            if (!$response) throw new Exception($this->failText('Failed to check invoice status in session'));

            $result = $this->parseInvoiceStatus(json_decode($response, true));

            if ($result['status'] == 'FAILED') {
                $this->last_error_code = $result['code'];
                $this->last_error_details = $this->rejectionDetails($result);
                dol_syslog("KsefClient: Invoice rejected - " . $this->rejectionMessage($result), LOG_WARNING);
            }

            return $result;

        } catch (Exception $e) {
            $this->error = "Invoice status check failed: " . $e->getMessage();
            dol_syslog("KsefClient::checkInvoiceInSession ERROR: " . $this->error, LOG_ERR);
            return false;
        }
    }

    /**
     * @brief Set request timeout
     * @param $timeout Seconds
     * @return void
     */
    public function setTimeout($timeout)
    {
        $this->timeout = (int)$timeout;
    }

    /**
     * @brief Test connection
     * @return bool
     */
    public function testConnection()
    {
        try {
            dol_syslog("KsefClient: Testing connection to {$this->environment}", LOG_INFO);
            $response = $this->makeRequest('GET', '/security/public-key-certificates', null, array('Accept: application/json'));

            if ($response === false) {
                $this->error = $this->failText("Failed to connect to KSeF API");
                return false;
            }

            $keys = json_decode($response, true);
            if (empty($keys) || !is_array($keys)) {
                $this->error = "Invalid response from KSeF API";
                return false;
            }
            return true;

        } catch (Exception $e) {
            $this->error = "Connection test failed: " . $e->getMessage();
            return false;
        }
    }

    /**
     * @brief Fetch public certificates
     * @param bool $refresh
     * @return array|false
     */
    private function getPublicKeyCertificates($refresh = false)
    {
        if (!$refresh && is_array($this->public_key_certs)) {
            return $this->public_key_certs;
        }
        $response = $this->makeRequest('GET', '/security/public-key-certificates', null, array('Accept: application/json'), array('retry' => 'safe'));
        if (!$response) {
            $this->error = $this->failText('Failed to fetch public key certificates');
            return false;
        }
        $data = json_decode($response, true);
        $list = is_array($data) && isset($data['certificates']) ? $data['certificates'] : $data;
        if (empty($list) || !is_array($list)) {
            $this->error = 'Invalid certificates response';
            return false;
        }
        $this->public_key_certs = array_values(array_filter($list, 'is_array'));
        return $this->public_key_certs;
    }

    /**
     * @brief Pick public key
     * @param string $usage
     * @param bool $refresh
     * @return array|false
     */
    public function selectPublicKey($usage, $refresh = false)
    {
        $certs = $this->getPublicKeyCertificates($refresh);
        if ($certs === false) {
            return false;
        }
        $now = time();
        $valid = array();
        $started = array();
        $future = array();
        foreach ($certs as $cert) {
            $usages = isset($cert['usage']) ? (array) $cert['usage'] : array();
            $match = false;
            foreach ($usages as $u) {
                $match = $match || (is_string($u) && strcasecmp($u, $usage) === 0);
            }
            $der = $cert['certificate'] ?? '';
            if (!$match || $der === '') {
                continue;
            }
            $from = !empty($cert['validFrom']) ? strtotime($cert['validFrom']) : 0;
            $to = !empty($cert['validTo']) ? strtotime($cert['validTo']) : PHP_INT_MAX;
            $from = $from === false ? 0 : $from;
            $to = $to === false ? PHP_INT_MAX : $to;
            $entry = array('pem' => "-----BEGIN CERTIFICATE-----\n" . chunk_split($der, 64, "\n") . "-----END CERTIFICATE-----\n",
                'public_key_id' => !empty($cert['publicKeyId']) ? (string) $cert['publicKeyId'] : null,
                'valid_from' => $from, 'valid_to' => $to);
            if ($from <= $now && $now < $to) {
                $valid[] = $entry;
            } elseif ($from <= $now) {
                $started[] = $entry;
            } else {
                $future[] = $entry;
            }
        }
        $latestFirst = function ($a, $b) {
            return $b['valid_from'] <=> $a['valid_from'];
        };
        usort($valid, $latestFirst);
        usort($started, $latestFirst);
        usort($future, function ($a, $b) {
            return $a['valid_from'] <=> $b['valid_from'];
        });
        if ($valid) {
            return $valid[0];
        }
        $pick = $started ? $started[0] : ($future ? $future[0] : null);
        if ($pick === null) {
            $this->error = "No certificate with $usage usage found";
            return false;
        }
        dol_syslog("KsefClient: no $usage certificate is valid now; using one valid " . dol_print_date($pick['valid_from'], 'dayhourrfc') . " - "
            . ($pick['valid_to'] === PHP_INT_MAX ? '?' : dol_print_date($pick['valid_to'], 'dayhourrfc')) . " (check the server clock)", LOG_WARNING);
        return $pick;
    }

    /**
     * @brief Load token key
     * @param bool $refresh
     * @return bool
     */
    private function loadKsefPublicKey($refresh = false)
    {
        $key = $this->selectPublicKey('KsefTokenEncryption', $refresh);
        if (!$key) {
            $this->error = "Failed to load public key: " . $this->error;
            return false;
        }
        $this->public_key_pem = $key['pem'];
        $this->public_key_id = $key['public_key_id'];
        return true;
    }

    /**
     * @brief Is authenticated
     * @return bool
     */
    private function isAuthenticated()
    {
        if (empty($this->session_token)) return false;
        if (!empty($this->session_expiry) && ($this->session_expiry - $this->sessionExpiryBuffer) < time()) {
            $this->session_token = null;
            $this->refresh_token = null;
            return false;
        }
        return true;
    }

    /**
     * @brief Load phpseclib
     */
    private function loadCrypto()
    {
        require_once __DIR__ . '/../lib/vendor/autoload.php';
    }

    /**
     * @brief Submit invoice
     * @param $invoiceXml
     * @param array $options
     * @return array|false
     */
    public function submitInvoice($invoiceXml, $options = array())
    {
        $this->last_error_code = null;
        $this->last_transport_error = false;
        $this->last_error_details = array();
        $this->last_session_reference = null;
        $this->last_invoice_reference = null;
        $this->loadCrypto();
        $this->authenticate();

        $sessionRef = null;
        $closed = false;
        try {
            dol_syslog("KsefClient: Starting invoice submission", LOG_INFO);

            $session = $this->openOnlineSession();
            $sessionRef = $session['ref'];

            $invoiceHash = !empty($options['invoice_hash'])
                ? $options['invoice_hash']
                : base64_encode(hash('sha256', $invoiceXml, true));

            // 21184: new session
            $invoiceRef = null;
            $resent = false;
            for ($round = 1; ; $round++) {
                $invoicePayload = $this->invoicePayload($invoiceXml, $invoiceHash, $session, $options);
                $invoiceResp = $this->makeRequest('POST', "/sessions/online/{$sessionRef}/invoices", json_encode($invoicePayload), array(
                    'Content-Type: application/json',
                    'Accept: application/json'
                ), array('auth' => true, 'retry' => 'rate'));
                if (!$invoiceResp && (int) $this->last_error_code === 21184 && $round === 1) {
                    dol_syslog("KsefClient: session $sessionRef temporarily unavailable (21184), sending in a new session", LOG_WARNING);
                    $this->closeOnlineSession($sessionRef);
                    $session = $this->openOnlineSession();
                    $sessionRef = $session['ref'];
                    continue;
                }
                if (!$invoiceResp && $this->ambiguousSendFailure()) {
                    $noAnswer = (int) $this->last_http_code === 0;
                    $timedOut = $noAnswer && (int) $this->last_curl_errno === 28;
                    $failed = $this->errorState();
                    $landed = $this->landedInSession($sessionRef, $invoiceHash, $noAnswer);
                    $this->restoreErrorState($failed);
                    if (is_array($landed)) {
                        dol_syslog("KsefClient: invoice POST failed (" . $this->error . ") but the session holds it as " . $landed['reference_number'], LOG_WARNING);
                        $invoiceRef = $landed['reference_number'];
                        break;
                    }
                    if ($landed === null && !$resent) {
                        dol_syslog("KsefClient: invoice POST failed (" . $this->error . "), the session holds nothing: sending once more", LOG_WARNING);
                        $resent = true;
                        continue;
                    }
                    if ($landed === false || $timedOut) {
                        // late POST lands
                        dol_syslog("KsefClient: invoice POST failed (" . $this->error . "), outcome unknown", LOG_WARNING);
                        global $langs;
                        $langs->load('ksef@ksef');
                        return $this->unknownOutcome($sessionRef, null, $invoiceHash, $langs->transnoentitiesnoconv($noAnswer ? 'KSEF_UploadNoAnswer' : 'KSEF_UploadOutcomeUnknown', (string) $this->error));
                    }
                }
                if (!$invoiceResp) throw new Exception($this->failText('Failed to submit invoice'));
                $invoiceResult = json_decode($invoiceResp, true);
                $invoiceRef = $invoiceResult['referenceNumber'] ?? null;
                break;
            }

            if (!$invoiceRef) throw new Exception('Invoice reference missing');
            $this->last_invoice_reference = $invoiceRef;

            dol_syslog("KsefClient: Invoice submitted with reference: $invoiceRef", LOG_INFO);
            if (!empty($options['on_progress']) && is_callable($options['on_progress'])) {
                call_user_func($options['on_progress'], $sessionRef, $invoiceRef);
            }

            // session counters unreliable
            $final = null;
            for ($attempt = 1; $attempt <= KSEF_SEND_MAX_POLLS; $attempt++) {
                $this->pollWait($attempt);
                $invoiceStatus = $this->checkInvoiceInSession($sessionRef, $invoiceRef);
                if ($invoiceStatus && $invoiceStatus['status'] !== 'PENDING') {
                    $final = $invoiceStatus;
                    break;
                }
            }

            $closed = true;
            $this->closeOnlineSession($sessionRef);

            if ($final === null) {
                return $this->unknownOutcome($sessionRef, $invoiceRef, $invoiceHash, ksefMsg('KSEF_ErrStillProcessing', KSEF_SEND_MAX_POLLS));
            }

            if ($final['status'] === 'FAILED') {
                throw new Exception($this->rejectionMessage($final));
            }

            // KSeF decides mode
            $declared = !empty($options['offline_mode']) ? 'Offline' : 'Online';
            $mismatch = !empty($final['invoicing_mode']) && strcasecmp((string) $final['invoicing_mode'], $declared) !== 0;
            if ($mismatch) {
                dol_syslog("KsefClient: KSeF recorded invoice " . $final['ksef_number'] . " as " . $final['invoicing_mode'] . " although it was sent as $declared", LOG_WARNING);
            }

            $endpoint = "/sessions/{$sessionRef}/invoices/{$invoiceRef}/upo";
            $upoXml = $this->fetchUpo($endpoint);
            if ($upoXml === false) {
                dol_syslog("KsefClient: UPO download failed - HTTP " . $this->last_http_code . " - " . $this->error, LOG_WARNING);
                $upoXml = null;
            }

            return array(
                'status' => 'ACCEPTED',
                'reference_number' => $invoiceRef,
                'session_reference' => $sessionRef,
                'ksef_number' => $final['ksef_number'],
                'invoice_hash' => $final['invoice_hash'] ?: $invoiceHash,
                'invoicing_mode' => $final['invoicing_mode'],
                'invoicing_mode_mismatch' => $mismatch,
                'acquisition_date' => $final['acquisition_date'],
                'upo_xml' => $upoXml
            );

        } catch (Throwable $e) {
            $this->error = "Invoice submission error: " . $e->getMessage();
            dol_syslog("KsefClient: " . $this->error, LOG_ERR);
            if ($sessionRef !== null && !$closed) {
                $this->closeOnlineSession($sessionRef);
            }
            return false;
        }
    }

    /**
     * @brief Open online session
     * @return array
     */
    private function openOnlineSession()
    {
        $symmetricKey = random_bytes(32);
        $iv = random_bytes(16);
        for ($attempt = 1; ; $attempt++) {
            $enc = $this->encryptSymmetricKey($symmetricKey, $attempt > 1);
            if (!$enc) throw new Exception('No suitable public key found for symmetric encryption: ' . $this->error);

            $sessionRequest = array(
                'formCode' => array('systemCode' => 'FA (3)', 'schemaVersion' => '1-0E', 'value' => 'FA'),
                'encryption' => $this->encryptionInfo($enc, $iv)
            );
            $sessionResp = $this->makeRequest('POST', '/sessions/online', json_encode($sessionRequest), array(
                'Content-Type: application/json',
                'Accept: application/json',
            ), array('auth' => true, 'retry' => 'safe'));
            if (!$sessionResp && (int) $this->last_error_code === 21470 && $attempt === 1) {
                dol_syslog("KsefClient: session open 21470: public key withdrawn, fetching the keys again", LOG_WARNING);
                continue;
            }
            if (!$sessionResp) throw new Exception(ksefMsg('KSEF_ErrSessionOpen', $this->failText('Failed to open invoice submission session')));
            break;
        }

        $sessionData = json_decode($sessionResp, true);
        $sessionRef = $sessionData['referenceNumber'] ?? null;
        if (!$sessionRef) throw new Exception('Session reference number missing');
        $this->last_session_reference = $sessionRef;
        return array('ref' => $sessionRef, 'key' => $symmetricKey, 'iv' => $iv);
    }

    /**
     * @brief Build send request
     * @param string $invoiceXml
     * @param string $invoiceHash
     * @param array $session
     * @param array $options
     * @return array
     */
    private function invoicePayload($invoiceXml, $invoiceHash, $session, $options)
    {
        $encryptedInvoice = openssl_encrypt($invoiceXml, 'AES-256-CBC', $session['key'], OPENSSL_RAW_DATA, $session['iv']);
        if ($encryptedInvoice === false) throw new Exception('Failed to encrypt invoice XML');

        $invoicePayload = array(
            'invoiceHash' => $invoiceHash,
            'invoiceSize' => strlen($invoiceXml),
            'encryptedInvoiceHash' => base64_encode(hash('sha256', $encryptedInvoice, true)),
            'encryptedInvoiceSize' => strlen($encryptedInvoice),
            'encryptedInvoiceContent' => base64_encode($encryptedInvoice)
        );

        if (!empty($options['offline_mode'])) {
            $invoicePayload['offlineMode'] = true;
            dol_syslog("KsefClient: Submitting in OFFLINE mode", LOG_INFO);
        }

        if (!empty($options['corrected_hash'])) {
            $invoicePayload['hashOfCorrectedInvoice'] = $options['corrected_hash'];
            dol_syslog("KsefClient: Including correction reference to hash: " . substr($options['corrected_hash'], 0, 20) . "...", LOG_INFO);
        }
        return $invoicePayload;
    }

    /**
     * @brief Close online session
     * @param string $sessionRef
     * @return bool
     */
    public function closeOnlineSession($sessionRef)
    {
        $state = $this->errorState();
        $ok = $this->makeRequest('POST', "/sessions/online/{$sessionRef}/close", null, array(), array('auth' => true, 'retry' => 'safe')) !== false;
        if (!$ok && (int) $this->last_error_code === 21180) {
            // already closed
            $ok = true;
        }
        if (!$ok) {
            dol_syslog("KsefClient: closing session $sessionRef failed, KSeF closes it when it expires: " . $this->error, LOG_WARNING);
        }
        $this->restoreErrorState($state);
        return $ok;
    }

    /**
     * @brief POST maybe landed
     * @return bool
     */
    private function ambiguousSendFailure()
    {
        $http = (int) $this->last_http_code;
        return $http === 0 || in_array($http, array(500, 502, 503, 504), true);
    }

    /**
     * @brief Find landed invoice
     * @param string $sessionRef
     * @param string $invoiceHash
     * @param bool $wait
     * @return array|null|false false: KSeF unreachable
     */
    private function landedInSession($sessionRef, $invoiceHash, $wait)
    {
        if ($wait) {
            $this->pause(2);
        }
        $scan = $this->scanSessionInvoices($sessionRef, $invoiceHash, self::SESSION_INVOICE_PAGES);
        if ($scan === false) {
            dol_syslog("KsefClient: could not list the invoices of session $sessionRef: " . $this->error, LOG_WARNING);
            return false;
        }
        $pick = $scan['match'] !== null ? $scan['match'] : $scan['first'];
        if ($pick === null) {
            return null;
        }
        $st = $this->parseInvoiceStatus($pick);
        return !empty($st['reference_number']) ? $st : null;
    }

    /**
     * @brief Build TIMEOUT result
     * @param string $sessionRef
     * @param string|null $invoiceRef
     * @param string $invoiceHash
     * @param string $why
     * @return array
     */
    private function unknownOutcome($sessionRef, $invoiceRef, $invoiceHash, $why)
    {
        $this->error = $why;
        dol_syslog("KsefClient: outcome unknown, " . $why . " (session $sessionRef, invoice " . ($invoiceRef ?: '-') . ")", LOG_WARNING);
        return array(
            'status' => 'TIMEOUT',
            'session_reference' => $sessionRef,
            'reference_number' => $invoiceRef,
            'invoice_hash' => $invoiceHash,
            'error' => $why
        );
    }

    /**
     * @brief Download invoice
     * @param string $ksefNumber
     * @return array|false
     */
    public function downloadInvoiceXml($ksefNumber)
    {
        $this->last_error_code = null;
        $this->authenticate();
        $xml = $this->makeRequest('GET', "/invoices/ksef/{$ksefNumber}", null, array('Accept: application/xml'), array('auth' => true, 'retry' => 'safe'));
        if ($xml === false || $xml === '') {
            dol_syslog("KsefClient::downloadInvoiceXml $ksefNumber failed - HTTP " . $this->last_http_code . " - " . $this->error, LOG_WARNING);
            return false;
        }
        return array(
            'xml' => $xml,
            'hash' => base64_encode(hash('sha256', $xml, true)),
            'header_hash' => (string) ($this->last_response_headers['x-ms-meta-hash'] ?? '')
        );
    }

    /**
     * @brief Find by hash
     * @param string $sessionRef
     * @param string $invoiceHash
     * @param int $maxPages
     * @return array|false|null Null: not found
     */
    public function findInvoiceInSession($sessionRef, $invoiceHash, $maxPages = self::SESSION_INVOICE_PAGES)
    {
        $scan = $this->scanSessionInvoices($sessionRef, $invoiceHash, $maxPages);
        if ($scan === false) {
            return false;
        }
        return $scan['match'] !== null ? $this->parseInvoiceStatus($scan['match']) : null;
    }

    /**
     * @brief Scan session pages
     * @param string $sessionRef
     * @param string $invoiceHash
     * @param int $maxPages
     * @return array|false
     */
    private function scanSessionInvoices($sessionRef, $invoiceHash, $maxPages)
    {
        $this->last_session_pages = 0;
        $this->last_session_complete = false;
        $first = null;
        $token = null;
        $maxPages = max(1, (int) $maxPages);
        do {
            $list = $this->listSessionInvoices($sessionRef, $token, self::SESSION_INVOICE_PAGE_SIZE);
            if ($list === false) {
                return false;
            }
            $this->last_session_pages++;
            foreach ($list['invoices'] as $entry) {
                if ($first === null) {
                    $first = $entry;
                }
                if ((string) ($entry['invoiceHash'] ?? '') === (string) $invoiceHash) {
                    return array('match' => $entry, 'first' => $first);
                }
            }
            $token = $list['continuation_token'];
        } while (!empty($token) && $this->last_session_pages < $maxPages);
        $this->last_session_complete = empty($token);
        return array('match' => null, 'first' => $first);
    }

    /**
     * @brief List session invoices
     * @param string $sessionRef
     * @param string|null $continuationToken
     * @param int $pageSize 0: KSeF default
     * @return array|false
     */
    public function listSessionInvoices($sessionRef, $continuationToken = null, $pageSize = 0)
    {
        $this->authenticate();
        $headers = array('Accept: application/json');
        if (!empty($continuationToken)) {
            $headers[] = 'x-continuation-token: ' . $continuationToken;
        }
        $opts = array('auth' => true, 'retry' => 'safe');
        if ((int) $pageSize > 0) {
            $opts['query'] = 'pageSize=' . (int) $pageSize;
        }
        $resp = $this->makeRequest('GET', "/sessions/{$sessionRef}/invoices", null, $headers, $opts);
        if (!$resp) {
            $this->error = $this->failText('Session invoice list failed');
            return false;
        }
        $data = json_decode($resp, true);
        if (!is_array($data)) {
            $this->error = 'Session invoice list failed: invalid response';
            return false;
        }
        return array(
            'invoices' => isset($data['invoices']) && is_array($data['invoices']) ? array_values(array_filter($data['invoices'], 'is_array')) : array(),
            'continuation_token' => !empty($data['continuationToken']) ? $data['continuationToken'] : null,
        );
    }

    /**
     * @brief Authenticate
     * @return bool
     */
    public function authenticate()
    {
        $this->loadCrypto();
        if ($this->isAuthenticated()) return true;

        for ($attempt = 1; ; $attempt++) {
            $this->auth_stage = 'config';
            try {
                if ($this->auth_method == 'certificate') {
                    return $this->authenticateWithCertificate();
                }
                return $this->authenticateWithToken();
            } catch (Exception $e) {
                $wait = $attempt === 1 ? $this->authRetryWait() : false;
                if ($wait === false) {
                    throw $e;
                }
                dol_syslog("KsefClient: authentication failed (" . $e->getMessage() . "), starting again from a new challenge", LOG_WARNING);
                $this->pause($wait);
            }
        }
    }

    /**
     * @brief Reauth delay
     * @return int|false
     */
    private function authRetryWait()
    {
        if (!in_array($this->auth_stage, array('challenge', 'init'), true)) {
            return false;
        }
        if ((int) $this->last_error_code === 21470 && $this->auth_stage === 'init') {
            $this->public_key_pem = null;
            $this->public_key_certs = null;
            return 0;
        }
        return $this->retryWait('safe');
    }

    /**
     * @brief Token authentication
     * @return bool
     */
    private function authenticateWithToken()
    {
        if (empty($this->ksef_token)) throw new Exception(ksefMsg('KSEF_ErrTokenNotConfigured', $this->environment));

        $this->auth_stage = 'challenge';
        $challengeResp = $this->makeRequest('POST', '/auth/challenge', json_encode(array('contextIdentifier' => array('type' => 'Nip', 'value' => $this->nip))), array('Content-Type: application/json', 'Accept: application/json'));
        if (!$challengeResp) throw new Exception(ksefMsg('KSEF_ErrAuthFailed', $this->failText('Failed to obtain authentication challenge')));

        $challengeData = json_decode($challengeResp, true);
        if (empty($challengeData['challenge'])) throw new Exception('Invalid challenge response from KSeF');

        $challenge = $challengeData['challenge'];
        $timestamp = $challengeData['timestamp'];

        if (empty($this->public_key_pem)) {
            if (!$this->loadKsefPublicKey()) throw new Exception('Unable to load KSeF public key certificate: ' . $this->error);
        }

        $dt = new DateTime($timestamp, new DateTimeZone('UTC'));
        $toEncrypt = $this->ksef_token . '|' . $dt->format('Uv');

        $key = PublicKeyLoader::load($this->public_key_pem);
        $key = $key->withPadding(RSA::ENCRYPTION_OAEP)->withHash('sha256')->withMGFHash('sha256');
        $encryptedB64 = base64_encode($key->encrypt($toEncrypt));

        $initRequest = array(
            'challenge' => $challenge,
            'contextIdentifier' => array('type' => 'Nip', 'value' => $this->nip),
            'encryptedToken' => $encryptedB64
        );
        if (!empty($this->public_key_id)) {
            $initRequest['publicKeyId'] = $this->public_key_id;
        }
        $this->auth_stage = 'init';
        $authResp = $this->makeRequest('POST', '/auth/ksef-token', json_encode($initRequest), array('Content-Type: application/json', 'Accept: application/json'));

        if (!$authResp) throw new Exception(ksefMsg('KSEF_ErrAuthFailed', $this->failText('Authentication request failed')));

        $authData = json_decode($authResp, true);
        $authToken = $authData['authenticationToken']['token'] ?? null;
        $referenceNumber = $authData['referenceNumber'] ?? null;

        if (!$authToken || !$referenceNumber) throw new Exception('Invalid authentication response received');

        $this->auth_stage = 'status';
        $this->pollAuthStatus($referenceNumber, $authToken, 12, 'Authentication');

        $this->auth_stage = 'redeem';
        $this->redeemTokens($referenceNumber, $authToken);
        return true;
    }

    /**
     * @brief Poll auth status
     * @param string $referenceNumber
     * @param string $authToken
     * @param int $maxAttempts
     * @param string $label
     * @param float $cap Max wait
     * @return void
     */
    private function pollAuthStatus($referenceNumber, $authToken, $maxAttempts, $label, $cap = 2)
    {
        $statusCode = 0;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $statusResp = $this->makeRequest('GET', '/auth/' . $referenceNumber, null, array("Authorization: Bearer {$authToken}", 'Accept: application/json'));
            if ($statusResp) {
                $statusData = json_decode($statusResp, true);
                $statusCode = isset($statusData['status']['code']) ? (int)$statusData['status']['code'] : 0;
                dol_syslog("KsefClient: Auth status poll attempt $attempt: code $statusCode", LOG_DEBUG);
                if ($statusCode === 200) {
                    return;
                }
                if ($statusCode >= 400) {
                    $desc = trim(($statusData['status']['description'] ?? '') . ' ' . implode('; ', (array) ($statusData['status']['details'] ?? array())));
                    dol_syslog("KsefClient: $label failed (status $statusCode)" . ($desc !== '' ? ": $desc" : ''), LOG_WARNING);
                    throw new Exception(ksefMsg('KSEF_ErrAuthStatus', $statusCode, $desc !== '' ? ': ' . $desc : ''));
                }
            }
            if ($attempt < $maxAttempts) {
                $this->pollWait($attempt, $cap);
            }
        }
        dol_syslog("KsefClient: $label status verification timeout", LOG_WARNING);
        throw new Exception(ksefMsg('KSEF_ErrAuthTimeout', !empty($this->error) && (int) $this->last_http_code !== 200 ? ': ' . $this->error : ''));
    }

    /**
     * @brief Redeem tokens once
     * @param string $referenceNumber
     * @param string $authToken
     * @return void
     */
    private function redeemTokens($referenceNumber, $authToken)
    {
        $redeemResp = $this->makeRequest('POST', '/auth/token/redeem', json_encode(array('referenceNumber' => $referenceNumber, 'authenticationToken' => array('token' => $authToken))), array("Authorization: Bearer {$authToken}", "Content-Type: application/json", "Accept: application/json"));

        if (!$redeemResp) throw new Exception(ksefMsg('KSEF_ErrAuthFailed', $this->failText('Token redeem request failed')));

        $tokenData = json_decode($redeemResp, true);
        $this->session_token = $tokenData['accessToken']['token'] ?? null;
        $this->refresh_token = $tokenData['refreshToken']['token'] ?? null;
        $this->session_expiry = isset($tokenData['accessToken']['validUntil']) ? strtotime($tokenData['accessToken']['validUntil']) : null;

        if (!$this->session_token) throw new Exception('Invalid tokens returned on redeem');
    }

    /**
     * @brief Certificate authentication
     * @return bool
     */
    private function authenticateWithCertificate()
    {
        if (!$this->loadAuthCertificateCredentials()) {
            throw new Exception($this->error);
        }

        $this->auth_stage = 'challenge';
        $challengeResp = $this->makeRequest('POST', '/auth/challenge',
            json_encode(array('contextIdentifier' => array('type' => 'Nip', 'value' => $this->nip))),
            array('Content-Type: application/json', 'Accept: application/json')
        );

        if (!$challengeResp) {
            throw new Exception(ksefMsg('KSEF_ErrAuthFailed', $this->failText('Failed to obtain authentication challenge')));
        }

        $challengeData = json_decode($challengeResp, true);
        if (empty($challengeData['challenge'])) {
            throw new Exception('Invalid challenge response from KSeF');
        }

        $challenge = $challengeData['challenge'];
        $timestamp = $challengeData['timestamp'];

        $authTokenRequestXml = $this->buildAuthTokenRequestXml($challenge, $timestamp);

        $this->auth_stage = 'sign';
        $signedXml = $this->signXadesAuthRequest($authTokenRequestXml);

        if (!$signedXml) {
            throw new Exception('Failed to sign authentication request: ' . $this->error);
        }

        $this->auth_stage = 'init';
        $authResp = $this->makeRequest('POST', '/auth/xades-signature', $signedXml, array(
            'Content-Type: application/xml',
            'Accept: application/json'
        ));

        if (!$authResp) {
            throw new Exception(ksefMsg('KSEF_ErrAuthFailed', 'Certificate authentication request failed: ' . $this->error));
        }

        $authData = json_decode($authResp, true);
        $authToken = $authData['authenticationToken']['token'] ?? null;
        $referenceNumber = $authData['referenceNumber'] ?? null;

        if (!$authToken || !$referenceNumber) {
            dol_syslog("KsefClient: Auth response: " . $authResp, LOG_ERR);
            throw new Exception('Invalid authentication response received');
        }

        $this->auth_stage = 'status';
        $this->pollAuthStatus($referenceNumber, $authToken, 22, 'Certificate authentication', 3);

        $this->auth_stage = 'redeem';
        $this->redeemTokens($referenceNumber, $authToken);

        return true;
    }

    /**
     * @brief Build AuthTokenRequest
     * @param string $challenge
     * @param string $timestamp Unused
     * @return string
     */
    private function buildAuthTokenRequestXml($challenge, $timestamp)
    {
        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;
        $namespace = 'http://ksef.mf.gov.pl/auth/token/2.0';

        $root = $xml->createElementNS($namespace, 'AuthTokenRequest');
        $root->setAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'xsi:schemaLocation',
            'http://ksef.mf.gov.pl/auth/token/2.0 http://ksef.mf.gov.pl/schema/gtw/svc/auth/request/2.0/authv2.xsd');
        $xml->appendChild($root);

        $challengeEl = $xml->createElement('Challenge', $challenge);
        $root->appendChild($challengeEl);

        $contextIdent = $xml->createElement('ContextIdentifier');
        $nipEl = $xml->createElement('Nip', $this->nip);
        $contextIdent->appendChild($nipEl);
        $root->appendChild($contextIdent);

        $subjectType = $xml->createElement('SubjectIdentifierType', 'certificateSubject');
        $root->appendChild($subjectType);

        return $xml->saveXML();
    }

    /**
     * @brief XAdES-BES sign
     * @param string $xml
     * @return string|false
     */
    private function signXadesAuthRequest($xml)
    {
        try {
            $privateKey = openssl_pkey_get_private($this->auth_private_key_pem);
            if (!$privateKey) {
                throw new Exception(ksefMsg('KSEF_ErrPrivateKeyLoad', (string) openssl_error_string()));
            }

            $keyDetails = openssl_pkey_get_details($privateKey);
            $isEcKey = ($keyDetails['type'] == OPENSSL_KEYTYPE_EC);

            $dom = new DOMDocument();
            $dom->loadXML($xml);
            $dom->preserveWhiteSpace = false;
            $dom->formatOutput = false;

            $canonicalXml = $dom->C14N(true, false);
            $digest = base64_encode(hash('sha256', $canonicalXml, true));

            $signatureId = 'Signature-' . bin2hex(random_bytes(8));
            $signedPropertiesId = 'SignedProperties-' . bin2hex(random_bytes(8));
            $keyInfoId = 'KeyInfo-' . bin2hex(random_bytes(8));
            $signingTime = gmdate('Y-m-d\TH:i:s\Z');

            $certDer = ksefPemToDer($this->auth_certificate_pem);
            $certDigest = base64_encode(hash('sha256', $certDer, true));

            $certInfo = openssl_x509_parse($this->auth_certificate_pem);
            $issuerName = ksefFormatIssuerNameRfc2253($certInfo['issuer']);
            $serialNumber = $certInfo['serialNumber'];

            $signedPropertiesXml = $this->buildSignedProperties(
                $signedPropertiesId, $signingTime, $certDigest, $issuerName, $serialNumber
            );

            $spDom = new DOMDocument();
            $spDom->loadXML($signedPropertiesXml);
            $spCanonical = $spDom->C14N(true, false);
            $spDigest = base64_encode(hash('sha256', $spCanonical, true));

            $signatureMethod = $isEcKey
                ? 'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256'
                : 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';

            $signedInfoXml = '<ds:SignedInfo xmlns:ds="http://www.w3.org/2000/09/xmldsig#">' .
                '<ds:CanonicalizationMethod Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"/>' .
                '<ds:SignatureMethod Algorithm="' . $signatureMethod . '"/>' .
                '<ds:Reference URI="">' .
                '<ds:Transforms>' .
                '<ds:Transform Algorithm="http://www.w3.org/2000/09/xmldsig#enveloped-signature"/>' .
                '<ds:Transform Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"/>' .
                '</ds:Transforms>' .
                '<ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>' .
                '<ds:DigestValue>' . $digest . '</ds:DigestValue>' .
                '</ds:Reference>' .
                '<ds:Reference URI="#' . $signedPropertiesId . '" Type="http://uri.etsi.org/01903#SignedProperties">' .
                '<ds:Transforms>' .
                '<ds:Transform Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"/>' .
                '</ds:Transforms>' .
                '<ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>' .
                '<ds:DigestValue>' . $spDigest . '</ds:DigestValue>' .
                '</ds:Reference>' .
                '</ds:SignedInfo>';

            $siDom = new DOMDocument();
            $siDom->loadXML($signedInfoXml);
            $canonicalSignedInfo = $siDom->C14N(true, false);

            $signature = '';
            if (!openssl_sign($canonicalSignedInfo, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
                throw new Exception('Signing failed: ' . openssl_error_string());
            }

            if ($isEcKey) {
                $curveName = $keyDetails['ec']['curve_name'] ?? 'prime256v1';
                $componentSize = 32;
                if (strpos($curveName, '384') !== false) {
                    $componentSize = 48;
                } elseif (strpos($curveName, '521') !== false) {
                    $componentSize = 66;
                }
                $signature = ksefConvertEcdsaDerToRaw($signature, $componentSize);
            }

            $signatureValue = base64_encode($signature);

            $certClean = preg_replace('/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s/', '', $this->auth_certificate_pem);

            $signatureElement = '<ds:Signature xmlns:ds="http://www.w3.org/2000/09/xmldsig#" Id="' . $signatureId . '">' .
                $signedInfoXml .
                '<ds:SignatureValue>' . $signatureValue . '</ds:SignatureValue>' .
                '<ds:KeyInfo Id="' . $keyInfoId . '">' .
                '<ds:X509Data>' .
                '<ds:X509Certificate>' . $certClean . '</ds:X509Certificate>' .
                '</ds:X509Data>' .
                '</ds:KeyInfo>' .
                '<ds:Object>' .
                '<xades:QualifyingProperties xmlns:xades="http://uri.etsi.org/01903/v1.3.2#" Target="#' . $signatureId . '">' .
                $signedPropertiesXml .
                '</xades:QualifyingProperties>' .
                '</ds:Object>' .
                '</ds:Signature>';

            $signatureDom = new DOMDocument();
            $signatureDom->loadXML($signatureElement);
            $signatureNode = $dom->importNode($signatureDom->documentElement, true);
            $dom->documentElement->appendChild($signatureNode);

            $result = $dom->saveXML();

            dol_syslog("KsefClient: XAdES signature created successfully", LOG_DEBUG);

            return $result;

        } catch (Exception $e) {
            $this->error = "XAdES signing failed: " . $e->getMessage();
            dol_syslog("KsefClient::signXadesAuthRequest ERROR: " . $this->error, LOG_ERR);
            return false;
        }
    }

    /**
     * @brief Build SignedProperties
     * @param string $id
     * @param string $signingTime ISO 8601
     * @param string $certDigest Base64 SHA-256
     * @param string $issuerName RFC 2253 DN
     * @param string $serialNumber
     * @return string
     */
    private function buildSignedProperties($id, $signingTime, $certDigest, $issuerName, $serialNumber)
    {
        $issuerSerialV2Base64 = ksefGenerateIssuerSerialV2DER($this->auth_certificate_pem);

        return '<xades:SignedProperties xmlns:xades="http://uri.etsi.org/01903/v1.3.2#" Id="' . $id . '">' .
            '<xades:SignedSignatureProperties>' .
            '<xades:SigningTime>' . $signingTime . '</xades:SigningTime>' .
            '<xades:SigningCertificateV2>' .
            '<xades:Cert>' .
            '<xades:CertDigest>' .
            '<ds:DigestMethod xmlns:ds="http://www.w3.org/2000/09/xmldsig#" Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>' .
            '<ds:DigestValue xmlns:ds="http://www.w3.org/2000/09/xmldsig#">' . $certDigest . '</ds:DigestValue>' .
            '</xades:CertDigest>' .
            '<xades:IssuerSerialV2>' . $issuerSerialV2Base64 . '</xades:IssuerSerialV2>' .
            '</xades:Cert>' .
            '</xades:SigningCertificateV2>' .
            '</xades:SignedSignatureProperties>' .
            '</xades:SignedProperties>';
    }

    /**
     * @brief Get invoice status
     * @param string $sessionRef
     * @param string $invoiceRef
     * @return array|false
     */
    public function checkStatus($sessionRef, $invoiceRef = '')
    {
        if (empty($sessionRef) || empty($invoiceRef)) {
            $this->error = 'Status check needs the session and the invoice reference';
            return false;
        }
        return $this->checkInvoiceInSession($sessionRef, $invoiceRef);
    }

    /**
     * @brief Download UPO
     * @param $ksefNumber
     * @param $sessionRef
     * @return string|false
     */
    public function downloadUPO($ksefNumber, $sessionRef = null)
    {
        $this->last_upo_hash = null;
        $this->last_upo_hash_ok = null;
        if (empty($sessionRef)) {
            $this->error = 'UPO download failed: Session reference required (KSeF serves the UPO only within the sending session)';
            return false;
        }
        $this->authenticate();

        $endpoint = "/sessions/{$sessionRef}/invoices/ksef/{$ksefNumber}/upo";
        $upoXml = $this->fetchUpo($endpoint);
        if ($upoXml !== false) return $upoXml;
        dol_syslog("KsefClient::downloadUPO failed - HTTP " . $this->last_http_code . " - " . $this->error, LOG_WARNING);
        $this->error = !empty($this->error) ? $this->error : 'Failed to download UPO';
        return false;
    }

    /**
     * @brief Fetch checked UPO
     * @param string $endpoint
     * @return string|false
     */
    private function fetchUpo($endpoint)
    {
        $this->last_upo_hash = null;
        $this->last_upo_hash_ok = null;
        $upoXml = $this->makeRequest('GET', $endpoint, null, array('Accept: application/xml'), array('auth' => true, 'retry' => 'safe'));
        if ($upoXml === false || $upoXml === '') {
            return false;
        }
        $header = (string) ($this->last_response_headers['x-ms-meta-hash'] ?? '');
        if ($header !== '') {
            $this->last_upo_hash = $header;
            $this->last_upo_hash_ok = hash_equals($header, base64_encode(hash('sha256', $upoXml, true)));
            if (!$this->last_upo_hash_ok) {
                $this->error = 'UPO hash mismatch (x-ms-meta-hash): the downloaded UPO is damaged';
                dol_syslog("KsefClient: " . $this->error . " - $endpoint", LOG_WARNING);
                return false;
            }
        }
        return $upoXml;
    }

    /**
     * @brief List sessions
     * @param array $filters
     * @param string|null $continuationToken
     * @return array|false
     */
    public function listSessions($filters = array(), $continuationToken = null)
    {
        $this->authenticate();
        $filters = (array) $filters;
        $query = array('sessionType' => !empty($filters['sessionType']) ? $filters['sessionType'] : 'Online');
        foreach (array('referenceNumber', 'dateCreatedFrom', 'dateCreatedTo', 'dateClosedFrom', 'dateClosedTo', 'dateModifiedFrom', 'dateModifiedTo', 'pageSize') as $k) {
            if (isset($filters[$k]) && $filters[$k] !== '' && $filters[$k] !== null) {
                $v = $filters[$k];
                $query[$k] = (strpos($k, 'date') === 0 && is_numeric($v)) ? gmdate('Y-m-d\TH:i:s\Z', (int) $v) : $v;
            }
        }
        $qs = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        foreach ((array) ($filters['statuses'] ?? array()) as $st) {
            $qs .= '&statuses=' . rawurlencode($st);
        }
        $headers = array('Accept: application/json');
        if (!empty($continuationToken)) {
            $headers[] = 'x-continuation-token: ' . $continuationToken;
        }
        $resp = $this->makeRequest('GET', '/sessions', null, $headers, array('auth' => true, 'retry' => 'safe', 'query' => $qs));
        if (!$resp) {
            $this->error = $this->failText('Session list failed');
            return false;
        }
        $data = json_decode($resp, true);
        if (!is_array($data)) {
            $this->error = 'Session list failed: invalid response';
            return false;
        }
        $out = array();
        foreach ((array) ($data['sessions'] ?? array()) as $sess) {
            if (!is_array($sess)) {
                continue;
            }
            $out[] = array(
                'reference_number' => $sess['referenceNumber'] ?? null,
                'status_code' => isset($sess['status']['code']) ? (int) $sess['status']['code'] : null,
                'status_description' => (string) ($sess['status']['description'] ?? ''),
                'date_created' => $sess['dateCreated'] ?? null,
                'date_updated' => $sess['dateUpdated'] ?? null,
                'valid_until' => $sess['validUntil'] ?? null,
                'total_count' => (int) ($sess['totalInvoiceCount'] ?? 0),
                'successful_count' => (int) ($sess['successfulInvoiceCount'] ?? 0),
                'failed_count' => (int) ($sess['failedInvoiceCount'] ?? 0),
            );
        }
        return array('sessions' => $out, 'continuation_token' => !empty($data['continuationToken']) ? $data['continuationToken'] : null);
    }

    /**
     * @brief API request
     * @param $method
     * @param $endpoint
     * @param $data
     * @param $headers
     * @param array $opts
     * @return string|false
     */
    protected function makeRequest($method, $endpoint, $data = null, $headers = array(), $opts = array())
    {
        $auth = !empty($opts['auth']);
        $retry = isset($opts['retry']) ? $opts['retry'] : false;
        $path = $endpoint . (!empty($opts['query']) ? '?' . $opts['query'] : '');
        $reauthed = false;
        $retried = false;
        while (true) {
            $h = $headers;
            if ($auth) {
                $h[] = "Authorization: Bearer {$this->session_token}";
            }
            $response = $this->httpOnce($method, $path, $data, $h);
            if ($response !== false) {
                return $response;
            }
            if ($auth && !$reauthed && !$this->in_reauth && (int) $this->last_http_code === 401) {
                $reauthed = true;
                $failed = $this->errorState();
                if ($this->reauthenticate()) {
                    continue;
                }
                $this->restoreErrorState($failed);
                return false;
            }
            if (!$retried && $retry) {
                $wait = $this->retryWait($retry);
                if ($wait !== false) {
                    $retried = true;
                    dol_syslog("KsefClient: $method $endpoint failed (HTTP " . (int) $this->last_http_code . "), retrying once in {$wait} s", LOG_WARNING);
                    $this->pause($wait);
                    continue;
                }
            }
            return false;
        }
    }

    /**
     * @brief Retry delay
     * @param string $retry 'safe' or 'rate'
     * @return int|false
     */
    private function retryWait($retry)
    {
        $http = (int) $this->last_http_code;
        if ($http === 429) {
            $wait = (int) $this->retry_after_seconds;
            if ($wait > (int) $this->retry_wait_cap) {
                dol_syslog("KsefClient: rate limited, Retry-After {$wait} s is above the {$this->retry_wait_cap} s cap: not retried", LOG_WARNING);
                return false;
            }
            return max(0, $wait);
        }
        if ($retry !== 'safe') {
            return false;
        }
        if (in_array($http, array(500, 502, 503, 504), true)) {
            return 1;
        }
        // nothing reached KSeF
        if ($http === 0 && in_array((int) $this->last_curl_errno, array(6, 7), true)) {
            return 1;
        }
        return false;
    }

    /**
     * @brief Wait seconds
     * @param float $seconds
     * @return void
     */
    private function pause($seconds)
    {
        if ($seconds <= 0 || (defined('KSEF_ALLOW_TEST_OVERRIDE') && constant('KSEF_ALLOW_TEST_OVERRIDE') && getDolGlobalString('KSEF_TEST_SKIP_SLEEP'))) {
            return;
        }
        usleep((int) round($seconds * 1000000));
    }

    /**
     * @brief Poll backoff
     * @param int $attempt 1-based
     * @param float $cap Max wait
     * @return void
     */
    private function pollWait($attempt, $cap = 2)
    {
        $this->pause(min($cap, 0.5 * pow(2, max(0, $attempt - 1))));
    }

    /**
     * @brief Save error fields
     * @return array
     */
    private function errorState()
    {
        return array($this->error, $this->last_error_code, $this->last_error_details, $this->last_http_code, $this->retry_after_seconds, $this->last_transport_error);
    }

    /**
     * @brief Restore error fields
     * @param array $state
     * @return void
     */
    private function restoreErrorState($state)
    {
        list($this->error, $this->last_error_code, $this->last_error_details, $this->last_http_code, $this->retry_after_seconds, $this->last_transport_error) = $state;
    }

    /**
     * @brief Append failure reason
     * @param string $what
     * @return string
     */
    private function failText($what)
    {
        return $what . (!empty($this->error) ? ': ' . $this->error : '');
    }

    /**
     * @brief Reauthenticate after 401
     * @return bool
     */
    private function reauthenticate()
    {
        $this->in_reauth = true;
        $this->session_token = null;
        $this->session_expiry = null;
        try {
            if (!empty($this->refresh_token)) {
                $resp = $this->makeRequest('POST', '/auth/token/refresh', null, array("Authorization: Bearer {$this->refresh_token}", 'Accept: application/json'));
                $data = $resp ? json_decode($resp, true) : null;
                if (!empty($data['accessToken']['token'])) {
                    $this->session_token = $data['accessToken']['token'];
                    $this->session_expiry = isset($data['accessToken']['validUntil']) ? strtotime($data['accessToken']['validUntil']) : null;
                    dol_syslog("KsefClient: access token refused (401), new one obtained with the refresh token", LOG_WARNING);
                    return true;
                }
                $this->refresh_token = null;
            }
            dol_syslog("KsefClient: access token refused (401), authenticating again", LOG_WARNING);
            return $this->authenticate();
        } catch (Exception $e) {
            $this->error = 'Re-authentication failed: ' . $e->getMessage();
            dol_syslog("KsefClient: " . $this->error, LOG_WARNING);
            return false;
        } finally {
            $this->in_reauth = false;
        }
    }

    /**
     * @brief Log system warning
     * @param string $raw Header value
     * @param string $endpoint
     * @return void
     */
    private function noteSystemWarning($raw, $endpoint)
    {
        $found = array();
        if (preg_match_all('/\[([^\]]+)\]: ([^|]+)/', $raw, $m, PREG_SET_ORDER)) {
            foreach ($m as $w) {
                $found[trim($w[1])] = trim($w[2]);
            }
        } else {
            $found[''] = trim($raw);
        }
        foreach ($found as $code => $msg) {
            if (array_key_exists($code, $this->system_warnings)) {
                continue;
            }
            $this->system_warnings[$code] = $msg;
            dol_syslog("KsefClient: KSeF system warning on $endpoint: " . ($code !== '' ? "[$code]: " : '') . $msg, LOG_WARNING);
        }
    }

    /**
     * @brief Single HTTP call
     * @param $method
     * @param $endpoint
     * @param $data
     * @param $headers
     * @return string|false
     */
    private function httpOnce($method, $endpoint, $data = null, $headers = array())
    {
        $url = $this->api_url . $endpoint;
        $this->last_transport_error = false;
        $this->last_error_code = null;
        $this->last_error_details = array();
        $this->last_curl_errno = 0;

        $hasAccept = false;
        $hasErrorFormat = false;
        foreach ($headers as $hdr) {
            $hasAccept = $hasAccept || stripos($hdr, 'Accept:') === 0;
            $hasErrorFormat = $hasErrorFormat || stripos($hdr, 'X-Error-Format:') === 0;
        }
        $defaultHeaders = $hasAccept ? array() : array('Accept: application/json');
        if (!$hasErrorFormat) {
            $defaultHeaders[] = 'X-Error-Format: problem-details';
        }
        $defaultHeaders[] = 'User-Agent: Dolibarr-KSeF/2.0';
        $headers = array_merge($defaultHeaders, $headers);

        $this->retry_after_seconds = null;
        $this->last_http_code = null;
        $responseHeaders = [];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        ksefCurlApplyProxy($ch);

        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $header) use (&$responseHeaders) {
            $len = strlen($header);
            $header = explode(':', $header, 2);
            if (count($header) < 2) {
                return $len;
            }
            $responseHeaders[strtolower(trim($header[0]))] = trim($header[1]);
            return $len;
        });

        switch (strtoupper($method)) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                if ($data !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                break;
            case 'GET':
                curl_setopt($ch, CURLOPT_HTTPGET, true);
                break;
            default:
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                if ($data !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                break;
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $this->last_curl_errno = curl_errno($ch);
        curl_close($ch);

        $this->last_http_code = $httpCode;
        $this->last_response_headers = $responseHeaders;

        if (!empty($responseHeaders['x-system-warning'])) {
            $this->noteSystemWarning($responseHeaders['x-system-warning'], strtok($endpoint, '?'));
        }

        if ($curlError || (int) $httpCode === 0) {
            $this->last_transport_error = true;
            $this->error = "Curl error: " . ($curlError ?: 'no HTTP answer');
            dol_syslog("KsefClient::makeRequest Curl error: " . ($curlError ?: 'no HTTP answer'), LOG_ERR);
            return false;
        }

        if ($httpCode >= 400) {
            $errorDetails = $this->parseErrorResponse($response);
            $this->last_error_code = $errorDetails['code'];
            $this->last_error_details = $errorDetails;

            if ($httpCode == 429) {
                $this->rate_limited_hits++;
                if (isset($responseHeaders['retry-after']) && is_numeric($responseHeaders['retry-after'])) {
                    $this->retry_after_seconds = (int)$responseHeaders['retry-after'];
                    dol_syslog("KsefClient::makeRequest Rate limited (429), Retry-After: {$this->retry_after_seconds}s", LOG_WARNING);
                } else {
                    $this->retry_after_seconds = 60;
                    dol_syslog("KsefClient::makeRequest Rate limited (429) but no Retry-After header, defaulting to 60s", LOG_WARNING);
                }
            }

            $text = $this->formatErrorMessage($errorDetails);
            if ($text === '') {
                $plain = array(401 => 'Unauthorized: the access token was refused', 403 => 'Forbidden: missing permissions for this operation',
                    404 => 'Not found', 429 => 'Too many requests', 500 => 'KSeF internal error', 502 => 'Bad gateway', 503 => 'KSeF unavailable', 504 => 'Gateway timeout');
                $text = isset($plain[(int) $httpCode]) ? $plain[(int) $httpCode] : 'Unknown error';
            }
            $this->error = "HTTP $httpCode" . ($httpCode == 429 ? " (retry after {$this->retry_after_seconds} s)" : '') . ": " . $text;

            dol_syslog("KsefClient::makeRequest Error: " . $this->error, LOG_ERR);
            dol_syslog("KsefClient::makeRequest Response: " . $response, LOG_DEBUG);

            return false;
        }

        return $response;
    }

    /**
     * @brief Symmetric key certificate
     * @param bool $refresh Fetch certificates again
     * @return string|false
     */
    public function fetchKsefPublicKey($refresh = false)
    {
        $key = $this->selectPublicKey('SymmetricKeyEncryption', $refresh);
        if (!$key) {
            $this->error = "Fetch public key failed: " . $this->error;
            dol_syslog("KsefClient::fetchKsefPublicKey Error: " . $this->error, LOG_ERR);
            return false;
        }
        return $key['pem'];
    }

    /**
     * @brief Encrypt AES key
     * @param string $aesKey
     * @param bool $refresh Fetch certificates again
     * @return array|false
     */
    private function encryptSymmetricKey($aesKey, $refresh = false)
    {
        $this->loadCrypto();
        $key = $this->selectPublicKey('SymmetricKeyEncryption', $refresh);
        if (!$key) {
            return false;
        }
        $pub = PublicKeyLoader::load($key['pem']);
        $pub = $pub->withPadding(RSA::ENCRYPTION_OAEP)->withHash('sha256')->withMGFHash('sha256');
        return array('encrypted' => base64_encode($pub->encrypt($aesKey)), 'public_key_id' => $key['public_key_id']);
    }

    /**
     * @brief Build EncryptionInfo
     * @param array $enc
     * @param string $iv
     * @return array
     */
    private function encryptionInfo($enc, $iv)
    {
        $info = array('encryptedSymmetricKey' => $enc['encrypted'], 'initializationVector' => base64_encode($iv));
        if (!empty($enc['public_key_id'])) {
            $info['publicKeyId'] = $enc['public_key_id'];
        }
        return $info;
    }

    /**
     * @brief Generate export keys
     * @return array|false
     */
    public function generateEncryptionData()
    {
        $this->loadCrypto();
        try {
            $aesKey = random_bytes(32);
            $iv = random_bytes(16);

            $enc = $this->encryptSymmetricKey($aesKey);
            if (!$enc) {
                throw new Exception("Failed to fetch KSeF public key: " . $this->error);
            }
            $this->export_keys[$enc['encrypted']] = array($aesKey, $iv);

            dol_syslog("KsefClient::generateEncryptionData Generated AES key and encrypted with RSA (OAEP SHA-256)", LOG_DEBUG);

            return array(
                'aes_key' => $aesKey,
                'iv' => $iv,
                'encryption_info' => $this->encryptionInfo($enc, $iv),
            );

        } catch (Exception $e) {
            $this->error = "Generate encryption data failed: " . $e->getMessage();
            dol_syslog("KsefClient::generateEncryptionData Error: " . $this->error, LOG_ERR);
            return false;
        }
    }

    /**
     * @brief Start HWM export
     * @param string $fromDateISO
     * @param string $subjectType
     * @param array $encryptionInfo
     * @param string|null $toDateISO
     * @return array|false
     */
    public function initHwmExport($fromDateISO, $subjectType, $encryptionInfo, $toDateISO = null)
    {
        try {
            $this->authenticate();
            $dateRange = array(
                'dateType' => 'PermanentStorage',
                'from' => $fromDateISO,
                'restrictToPermanentStorageHwmDate' => true,
            );

            if ($toDateISO !== null) {
                $dateRange['to'] = $toDateISO;
            }

            $exportRequest = array(
                'filters' => array(
                    'subjectType' => ucfirst(strtolower($subjectType)),
                    'dateRange' => $dateRange,
                ),
                'encryption' => $encryptionInfo,
            );

            $response = $this->makeRequest('POST', '/invoices/exports', json_encode($exportRequest), array(
                'Content-Type: application/json',
                'Accept: application/json',
            ), array('auth' => true, 'retry' => 'rate'));

            $raw = $this->export_keys[$encryptionInfo['encryptedSymmetricKey'] ?? ''] ?? null;
            if (!$response && (int) $this->last_error_code === 21470 && $raw !== null) {
                dol_syslog("KsefClient::initHwmExport 21470: public key withdrawn, encrypting with the current key and starting again", LOG_WARNING);
                $enc = $this->encryptSymmetricKey($raw[0], true);
                if ($enc) {
                    $exportRequest['encryption'] = $this->encryptionInfo($enc, $raw[1]);
                    $response = $this->makeRequest('POST', '/invoices/exports', json_encode($exportRequest), array(
                        'Content-Type: application/json',
                        'Accept: application/json',
                    ), array('auth' => true, 'retry' => 'rate'));
                }
            }

            if (!$response) {
                throw new Exception('Failed to init export: ' . $this->error);
            }

            $data = json_decode($response, true);

            if (empty($data['referenceNumber'])) {
                throw new Exception('No reference number in response');
            }

            dol_syslog("KsefClient::initHwmExport Started export: " . $data['referenceNumber'], LOG_INFO);

            return array(
                'referenceNumber' => $data['referenceNumber'],
                'timestamp' => $data['timestamp'] ?? null,
            );

        } catch (Exception $e) {
            $this->error = "Init HWM export failed: " . $e->getMessage();
            dol_syslog("KsefClient::initHwmExport Error: " . $this->error, LOG_ERR);
            return false;
        }
    }

    /**
     * @brief Get export status
     * @param string $referenceNumber
     * @return array|false
     */
    public function getExportStatus($referenceNumber)
    {
        try {
            $this->authenticate();
            $response = $this->makeRequest('GET', '/invoices/exports/' . urlencode($referenceNumber), null, array('Accept: application/json'), array('auth' => true, 'retry' => 'safe'));

            if (!$response) {
                throw new Exception('Failed to get export status: ' . $this->error);
            }

            $data = json_decode($response, true);

            $result = array(
                'status' => 'PROCESSING',
                'processingCode' => null,
                'description' => isset($data['status']['description']) ? (string) $data['status']['description'] : '',
                'parts' => array(),
                'isTruncated' => false,
                'permanentStorageHwmDate' => null,
                'lastPermanentStorageDate' => null,
                'numberOfElements' => 0,
                'raw' => $data,
            );

            $code = null;
            if (isset($data['status']['code'])) {
                $code = (int)$data['status']['code'];
            } elseif (isset($data['processingCode'])) {
                $code = (int)$data['processingCode'];
            }

            if ($code !== null) {
                $result['processingCode'] = $code;

                if ($code === 200) {
                    $result['status'] = 'COMPLETED';
                } elseif ($code >= 100 && $code < 200) {
                    $result['status'] = 'PROCESSING';
                } else {
                    $result['status'] = 'FAILED';
                    if ($result['description'] === '') {
                        $result['description'] = $code === 210 ? 'Export expired, the package is no longer available' : 'Export failed';
                    }
                }
            }

            $package = isset($data['package']) ? $data['package'] : $data;

            if (isset($package['parts']) && is_array($package['parts'])) {
                $result['parts'] = $package['parts'];
            } elseif (isset($data['packageParts']['parts'])) {
                $result['parts'] = $data['packageParts']['parts'];
            }

            $result['isTruncated'] = !empty($package['isTruncated']);

            if (isset($package['permanentStorageHwmDate']) && !empty($package['permanentStorageHwmDate'])) {
                $ts = strtotime($package['permanentStorageHwmDate']);
                $result['permanentStorageHwmDate'] = ($ts !== false && $ts > 0) ? $ts : null;
            }

            if (isset($package['lastPermanentStorageDate']) && !empty($package['lastPermanentStorageDate'])) {
                $ts = strtotime($package['lastPermanentStorageDate']);
                $result['lastPermanentStorageDate'] = ($ts !== false && $ts > 0) ? $ts : null;
            }

            if (isset($data['completedDate'])) {
                $result['completedDate'] = $data['completedDate'];
            }

            $result['numberOfElements'] = isset($data['numberOfElements']) ? (int)$data['numberOfElements'] : 0;

            $statusCode = isset($data['status']['code']) ? $data['status']['code'] : 'not found';
            $partsCount = count($result['parts']);
            dol_syslog("KsefClient::getExportStatus Parsed: status.code={$statusCode}, result_status={$result['status']}, Truncated: " . ($result['isTruncated'] ? 'yes' : 'no') . ", Parts: {$partsCount}", LOG_DEBUG);

            return $result;

        } catch (Exception $e) {
            $this->error = "Get export status failed: " . $e->getMessage();
            dol_syslog("KsefClient::getExportStatus Error: " . $this->error, LOG_ERR);
            return false;
        }
    }

    /**
     * @brief Download package part
     * @param array $part
     * @return string|false
     */
    public function downloadPackagePart($part)
    {
        $partUrl = $part['url'] ?? $part['partUrl'] ?? null;
        if (!$partUrl) {
            $this->error = "No URL in part data";
            return false;
        }

        try {
            $isFullUrl = (strpos($partUrl, 'http') === 0);

            if ($isFullUrl) {
                $url = $partUrl;
            } else {
                $url = $this->api_url . $partUrl;
            }

            $this->last_part_hash = '';
            for ($attempt = 1; ; $attempt++) {
                $partHeaders = array();
                $httpCode = 0;
                $curlError = '';
                $hopUrl = $url;
                // token stays on-host
                for ($hop = 0; ; $hop++) {
                    $response = $this->partRequestOnce($hopUrl, $partHeaders, $httpCode, $curlError);
                    if ($curlError || $httpCode < 300 || $httpCode >= 400 || empty($partHeaders['location'])) {
                        break;
                    }
                    if ($hop >= 5) {
                        throw new Exception("Too many redirects");
                    }
                    $next = $this->resolvePartRedirect($hopUrl, $partHeaders['location']);
                    if ($next === '') {
                        throw new Exception("Redirect to an unsupported URL");
                    }
                    $hopUrl = $next;
                }

                $this->last_http_code = $httpCode;
                $this->retry_after_seconds = null;
                if ($httpCode === 429) {
                    $this->rate_limited_hits++;
                    $this->retry_after_seconds = isset($partHeaders['retry-after']) && is_numeric($partHeaders['retry-after']) ? (int) $partHeaders['retry-after'] : 60;
                }
                if (!$curlError && $httpCode > 0 && $httpCode < 400) {
                    break;
                }
                $wait = $attempt === 1 ? $this->retryWait('safe') : false;
                if ($wait === false) {
                    if ($curlError) {
                        throw new Exception("Curl error: $curlError");
                    }
                    throw new Exception("HTTP $httpCode" . ($httpCode === 429 ? " (retry after {$this->retry_after_seconds} s)" : ''));
                }
                dol_syslog("KsefClient::downloadPackagePart HTTP $httpCode" . ($curlError ? " ($curlError)" : '') . ", retrying once in {$wait} s", LOG_WARNING);
                $this->pause($wait);
            }

            $this->last_response_headers = $partHeaders;
            $this->last_part_hash = (string) ($partHeaders['x-ms-meta-hash'] ?? '');
            dol_syslog("KsefClient::downloadPackagePart Downloaded " . strlen($response) . " bytes", LOG_DEBUG);
            return $response;

        } catch (Exception $e) {
            $this->error = "Download part failed: " . $e->getMessage();
            dol_syslog("KsefClient::downloadPackagePart Error: " . $this->error, LOG_ERR);
            return false;
        }
    }

    /**
     * @brief GET part once
     * @param string $url
     * @param array $partHeaders Out: headers
     * @param int $httpCode Out
     * @param string $curlError Out
     * @return string|false
     */
    private function partRequestOnce($url, &$partHeaders, &$httpCode, &$curlError)
    {
        $headers = array('Accept: application/octet-stream');
        if ($this->partUrlGetsToken($url)) {
            $this->authenticate();
            $headers[] = "Authorization: Bearer {$this->session_token}";
        }

        $partHeaders = array();
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        ksefCurlApplyProxy($ch);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $header) use (&$partHeaders) {
            $p = explode(':', $header, 2);
            if (count($p) === 2) {
                $partHeaders[strtolower(trim($p[0]))] = trim($p[1]);
            }
            return strlen($header);
        });

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $this->last_curl_errno = curl_errno($ch);
        curl_close($ch);

        return $response;
    }

    /**
     * @brief URL gets token
     * @param string $url
     * @return bool
     */
    private function partUrlGetsToken($url)
    {
        // presigned: own signature
        if (strpos($url, '/storage/') !== false) {
            return false;
        }
        $target = parse_url($url);
        $api = parse_url($this->api_url);
        if (!is_array($target) || !is_array($api) || empty($target['host']) || empty($api['host'])) {
            return false;
        }
        $defaultPorts = array('http' => 80, 'https' => 443);
        $targetScheme = strtolower($target['scheme'] ?? '');
        $apiScheme = strtolower($api['scheme'] ?? '');
        $targetPort = isset($target['port']) ? (int) $target['port'] : ($defaultPorts[$targetScheme] ?? 0);
        $apiPort = isset($api['port']) ? (int) $api['port'] : ($defaultPorts[$apiScheme] ?? 0);
        return $targetScheme === $apiScheme && strtolower($target['host']) === strtolower($api['host']) && $targetPort === $apiPort;
    }

    /**
     * @brief Resolve redirect URL
     * @param string $current
     * @param string $location
     * @return string '' when unusable
     */
    private function resolvePartRedirect($current, $location)
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location)) {
            return '';
        }
        $base = parse_url($current);
        if (!is_array($base) || empty($base['scheme']) || empty($base['host'])) {
            return '';
        }
        if (strpos($location, '//') === 0) {
            return $base['scheme'] . ':' . $location;
        }
        $origin = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
        if (strpos($location, '/') === 0) {
            return $origin . $location;
        }
        $dir = isset($base['path']) ? substr($base['path'], 0, strrpos($base['path'], '/') + 1) : '/';
        return $origin . ($dir === '' ? '/' : $dir) . $location;
    }

    /**
     * @brief Verify export part
     * @param array $part
     * @param string $encrypted
     * @param string|null $decrypted Null: skip
     * @return string '' on match
     */
    public function verifyPartHash($part, $encrypted, $decrypted = null)
    {
        $checks = array(array('encryptedPartHash', 'encryptedPartSize', $encrypted));
        if ($decrypted !== null) {
            $checks[] = array('partHash', 'partSize', $decrypted);
        }
        foreach ($checks as $c) {
            list($hashKey, $sizeKey, $bytes) = $c;
            if (isset($part[$sizeKey]) && is_numeric($part[$sizeKey]) && (int) $part[$sizeKey] !== strlen((string) $bytes)) {
                return "$sizeKey mismatch (" . strlen((string) $bytes) . " bytes, expected " . (int) $part[$sizeKey] . ")";
            }
            if (!empty($part[$hashKey]) && !hash_equals((string) $part[$hashKey], base64_encode(hash('sha256', (string) $bytes, true)))) {
                return "$hashKey mismatch";
            }
        }
        return '';
    }

    /**
     * @brief Decrypt package
     * @param string $encryptedData
     * @param string $aesKey 32 bytes
     * @param string $iv 16 bytes
     * @return string|false
     */
    public function decryptPackageData($encryptedData, $aesKey, $iv)
    {
        try {
            $decrypted = openssl_decrypt(
                $encryptedData,
                'aes-256-cbc',
                $aesKey,
                OPENSSL_RAW_DATA,
                $iv
            );

            if ($decrypted === false) {
                throw new Exception("Decryption failed: " . openssl_error_string());
            }

            dol_syslog("KsefClient::decryptPackageData Decrypted " . strlen($encryptedData) . " -> " . strlen($decrypted) . " bytes", LOG_DEBUG);
            return $decrypted;

        } catch (Exception $e) {
            $this->error = "Decrypt failed: " . $e->getMessage();
            dol_syslog("KsefClient::decryptPackageData Error: " . $this->error, LOG_ERR);
            return false;
        }
    }

    /**
     * @brief Process ZIP batches
     * @param string $zipFilePath
     * @param int $batchSize
     * @param callable $callback
     * @return array|false
     */
    public function processPackageInBatches($zipFilePath, $batchSize, $callback)
    {
        $zip = null;
        try {
            if (!file_exists($zipFilePath)) {
                throw new Exception("ZIP file not found: $zipFilePath");
            }

            $zip = new ZipArchive();
            $openResult = $zip->open($zipFilePath);

            if ($openResult !== true) {
                $zip = null;
                throw new Exception("Failed to open ZIP: error $openResult");
            }

            $totalFiles = $zip->numFiles;
            dol_syslog("KsefClient::processPackageInBatches Opening ZIP with {$totalFiles} files, batch size {$batchSize}", LOG_INFO);

            $metadata = array();
            $metaIndex = $zip->locateName('_metadata.json', ZipArchive::FL_NOCASE);
            if ($metaIndex !== false) {
                $decoded = json_decode((string) $zip->getFromIndex($metaIndex), true);
                $list = is_array($decoded) ? ($decoded['invoices'] ?? $decoded) : array();
                foreach ((array) $list as $m) {
                    if (is_array($m) && !empty($m['ksefNumber'])) {
                        $metadata[(string) $m['ksefNumber']] = $m;
                    }
                }
            }

            $batch = array();
            $batchNum = 0;
            $invoiceCount = 0;
            $seen = array();

            for ($i = 0; $i < $totalFiles; $i++) {
                $filename = $zip->getNameIndex($i);

                if (strcasecmp($filename, '_metadata.json') === 0 || !preg_match('/\.xml$/i', $filename)) {
                    continue;
                }
                $content = $zip->getFromIndex($i);

                $ksefNumber = $this->extractKsefNumberFromContent($content, $filename);
                if ($ksefNumber) {
                    if (isset($batch[$ksefNumber])) {
                        dol_syslog("KsefClient::processPackageInBatches WARNING: duplicate KSeF number {$ksefNumber} from file {$filename} - overwriting previous entry", LOG_WARNING);
                    }
                    $batch[$ksefNumber] = $content;
                    $seen[$ksefNumber] = true;
                    $invoiceCount++;
                } else {
                    dol_syslog("KsefClient::processPackageInBatches WARNING: could not extract KSeF number from file {$filename} - skipping", LOG_WARNING);
                }

                if (count($batch) >= $batchSize) {
                    $batchNum++;
                    dol_syslog("KsefClient::processPackageInBatches Processing batch {$batchNum} ({$invoiceCount} invoices so far)", LOG_INFO);
                    $callback($batch, $batchNum, $totalFiles, $metadata);
                    $batch = array();
                }
            }

            if (!empty($batch)) {
                $batchNum++;
                dol_syslog("KsefClient::processPackageInBatches Processing final batch {$batchNum} ({$invoiceCount} invoices total)", LOG_INFO);
                $callback($batch, $batchNum, $totalFiles, $metadata);
            }

            $zip->close();
            dol_syslog("KsefClient::processPackageInBatches Complete: {$invoiceCount} invoices in {$batchNum} batches", LOG_INFO);

            return array(
                'total' => $invoiceCount,
                'batches' => $batchNum,
                'metadata' => $metadata,
                'missing' => array_values(array_diff(array_keys($metadata), array_keys($seen))),
            );

        } catch (Throwable $e) {
            if ($zip) {
                @$zip->close();
            }
            $this->error = "Batch processing failed: " . $e->getMessage();
            dol_syslog("KsefClient::processPackageInBatches Error: " . $this->error, LOG_ERR);
            return false;
        }
    }

    /**
     * @brief Extract KSeF number
     * @param string $content
     * @param string $filename
     * @return string|null
     */
    private function extractKsefNumberFromContent($content, $filename)
    {
        $basename = pathinfo($filename, PATHINFO_FILENAME);
        if (preg_match('/^(\d{10}-\d{8}-[A-Z0-9-]+)$/i', $basename)) {
            return $basename;
        }
        return $basename ?: null;
    }
}