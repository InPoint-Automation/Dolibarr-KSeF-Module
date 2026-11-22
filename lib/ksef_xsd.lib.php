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
 * \file    lib/ksef_xsd.lib.php
 * \ingroup ksef
 * \brief   Local FA(3) schema check
 */

/**
 * @brief Shipped schema path
 * @return string '' when missing
 */
function ksefFa3XsdPath()
{
    $path = __DIR__ . '/fa3_v1-0E.xsd';
    return is_readable($path) ? $path : '';
}

/**
 * @brief Schema errors
 * @param string $xml
 * @return array|null null when not checked
 */
function ksefFa3SchemaErrors($xml)
{
    $xsd = ksefFa3XsdPath();
    if ($xsd === '' || !is_string($xml) || $xml === '' || !class_exists('DOMDocument')) {
        dol_syslog('KSEF schema check skipped: ' . ($xsd === '' ? 'schema files missing' : 'no XML'), LOG_WARNING);
        return null;
    }
    $prev = libxml_use_internal_errors(true);
    libxml_clear_errors();
    $dom = new DOMDocument();
    $errors = array();
    if (!$dom->loadXML($xml, LIBXML_NONET)) {
        foreach (libxml_get_errors() as $e) {
            $errors[] = array('line' => (int) $e->line, 'path' => '', 'element' => '', 'message' => trim($e->message), 'node' => null);
        }
    } elseif (!$dom->schemaValidate($xsd)) {
        $lines = array();
        foreach ($dom->getElementsByTagName('*') as $el) {
            $ln = $el->getLineNo();
            if (!isset($lines[$ln])) {
                $lines[$ln] = $el;
            }
        }
        foreach (libxml_get_errors() as $e) {
            if ($e->level < LIBXML_ERR_ERROR) {
                continue;
            }
            $msg = trim($e->message);
            $element = preg_match("/^Element '(?:\\{[^}]*\\})?([^']+)'/", $msg, $m) ? $m[1] : '';
            $node = $lines[(int) $e->line] ?? null;
            if ($node && $element !== '' && $node->localName !== $element) {
                foreach ($node->getElementsByTagName($element) as $c) {
                    $node = $c;
                    break;
                }
            }
            $errors[] = array('line' => (int) $e->line, 'path' => $node ? ksefFa3NodePath($node) : '', 'element' => $element,
                'message' => preg_replace("/\\{https?:[^}]*\\}/", '', $msg), 'node' => $node);
        }
    }
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if ($errors) {
        dol_syslog('KSEF schema check: ' . count($errors) . ' error(s), first: ' . $errors[0]['path'] . ' ' . $errors[0]['message'], LOG_WARNING);
    }
    return $errors;
}

/**
 * @brief Element path
 * @param DOMNode $node
 * @return string
 */
function ksefFa3NodePath($node)
{
    $parts = array();
    while ($node && $node->nodeType === XML_ELEMENT_NODE && $node->localName !== 'Faktura') {
        array_unshift($parts, $node->localName);
        $node = $node->parentNode;
    }
    return implode('/', $parts);
}

/**
 * @brief Plain schema refusal
 * @param DoliDB $db
 * @param Facture $invoice
 * @param array $errors ksefFa3SchemaErrors()
 * @return array text, html, url, kind
 */
function ksefFa3SchemaProblem($db, $invoice, $errors)
{
    global $langs;
    $langs->load('ksef@ksef');
    $e = $errors[0];
    $path = $e['path'];
    $missing = '';
    if (strpos($e['message'], 'Missing child element') !== false && preg_match('/Expected is(?: one of)? \( ?([^)]*)\)/', $e['message'], $m)) {
        $missing = trim(explode(',', $m[1])[0]);
    }
    $full = $path . ($missing !== '' ? '/' . $missing : '');
    $buyer = '';
    $socid = (int) ($invoice->socid ?? 0);
    if ($socid > 0 && preg_match('#^Podmiot2#', $full)) {
        require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
        $soc = new Societe($db);
        $buyer = $soc->fetch($socid) > 0 ? trim((string) $soc->name) : '';
        $buyer = $buyer !== '' ? $buyer : (trim((string) ($soc->code_client ?? '')) !== '' ? trim((string) $soc->code_client) : '#' . $socid);
    }
    $company = DOL_URL_ROOT . '/admin/company.php';
    $buyerUrl = DOL_URL_ROOT . '/societe/card.php?socid=' . $socid . '&action=edit';
    $invoiceUrl = DOL_URL_ROOT . '/compta/facture/card.php?facid=' . (int) $invoice->id;
    $rules = array(
        array('#^Podmiot1/DaneIdentyfikacyjne/NIP#', 'seller_nip', dol_buildpath('/ksef/admin/setup.php', 1), array()),
        array('#^Podmiot1/DaneIdentyfikacyjne#', 'seller_name', $company, array()),
        array('#^Podmiot1/Adres#', 'seller_address', $company, array()),
        array('#^Podmiot1#', 'seller', $company, array()),
        array('#^Podmiot2/DaneIdentyfikacyjne/(NIP|KodUE|NrVatUE|KodKraju|NrID|BrakID)#', 'buyer_id', $buyerUrl, array($buyer)),
        array('#^Podmiot2/DaneIdentyfikacyjne#', 'buyer_name', $buyerUrl, array($buyer)),
        array('#^Podmiot2/Adres#', 'buyer_address', $buyerUrl, array($buyer)),
        array('#^Podmiot2#', 'buyer', $buyerUrl, array($buyer)),
        array('#^Podmiot3#', 'third_party', $invoiceUrl, array()),
        array('#^Fa/FaWiersz#', 'line', $invoiceUrl, array(ksefFa3LineNumber($e['node']))),
    );
    $kind = 'other';
    $url = $invoiceUrl;
    $args = array($e['element'] . ': ' . $e['message']);
    foreach ($rules as $r) {
        if (preg_match($r[0], $full)) {
            list(, $kind, $url, $args) = $r;
            break;
        }
    }
    $key = 'KSEF_Xsd_' . str_replace(' ', '', ucwords(str_replace('_', ' ', $kind)));
    $text = $langs->transnoentitiesnoconv('KSEF_Xsd_Refused') . ' ' . $langs->transnoentitiesnoconv($key, ...array_map('strval', $args));
    $html = '<span class="ksef-preflight-xsd" data-kind="' . dol_escape_htmltag($kind) . '">' . dol_escape_htmltag($text)
        . ' <a href="' . dol_escape_htmltag($url) . '">' . dol_escape_htmltag($langs->transnoentitiesnoconv('KSEF_Xsd_FixLink')) . '</a></span>';
    return array('kind' => $kind, 'text' => $text, 'html' => $html, 'url' => $url, 'detail' => $full . ': ' . $e['message']);
}

/**
 * @brief Line number
 * @param DOMNode|null $node
 * @return string
 */
function ksefFa3LineNumber($node)
{
    while ($node && $node->nodeType === XML_ELEMENT_NODE && $node->localName !== 'FaWiersz') {
        $node = $node->parentNode;
    }
    if (!$node || $node->nodeType !== XML_ELEMENT_NODE) {
        return '?';
    }
    foreach ($node->childNodes as $c) {
        if ($c->nodeType === XML_ELEMENT_NODE && $c->localName === 'NrWierszaFa') {
            return trim($c->textContent);
        }
    }
    return '?';
}

/**
 * @brief Check invoice XML
 * @param DoliDB $db
 * @param Facture $invoice
 * @param string $xml
 * @return array|null null when valid
 */
function ksefFa3SchemaRefusal($db, $invoice, $xml)
{
    $errors = ksefFa3SchemaErrors($xml);
    if (empty($errors)) {
        return null;
    }
    return ksefFa3SchemaProblem($db, $invoice, $errors);
}
