<?php
/* Copyright (C) 2025-2026      InPoint Automation Sp z o.o.
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
 * \file    ksef/css/ksef.css.php
 * \ingroup ksef
 * \brief   Serve ksef.css
 */
if (!defined('NOREQUIRESOC')) {
    define('NOREQUIRESOC', '1');
}
if (!defined('NOCSRFCHECK')) {
    define('NOCSRFCHECK', 1);
}
if (!defined('NOTOKENRENEWAL')) {
    define('NOTOKENRENEWAL', 1);
}
if (!defined('NOLOGIN')) {
    define('NOLOGIN', 1);
}
if (!defined('NOREQUIREMENU')) {
    define('NOREQUIREMENU', 1);
}
if (!defined('NOREQUIREHTML')) {
    define('NOREQUIREHTML', 1);
}
if (!defined('NOREQUIREAJAX')) {
    define('NOREQUIREAJAX', '1');
}

session_cache_limiter('public');

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res && file_exists("../../../../main.inc.php")) $res = @include "../../../../main.inc.php";
if (!$res) die("Include of main fails");

$darkMode = GETPOSTISSET('THEME_DARKMODEENABLED') ? GETPOSTINT('THEME_DARKMODEENABLED') : getDolGlobalInt('THEME_DARKMODEENABLED');

$file = __DIR__ . '/ksef.css';
$mtime = (int) @filemtime($file);
$etag = '"' . md5($mtime . '-' . (int) @filesize($file) . '-' . $darkMode) . '"';

header('Content-Type: text/css; charset=UTF-8');
header('Cache-Control: no-cache');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('ETag: ' . $etag);

$ifNoneMatch = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim((string) $_SERVER['HTTP_IF_NONE_MATCH']) : '';
if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
    http_response_code(304);
    exit;
}

print ksefCssDarkMode((string) @file_get_contents($file), $darkMode);

/**
 * @brief Filter dark blocks
 * @param string $css
 * @param int $darkMode 0 drop, 1 keep, 2 force
 * @return string
 */
function ksefCssDarkMode($css, $darkMode)
{
    if ($darkMode == 1) {
        return $css;
    }
    $out = '';
    $pos = 0;
    while (preg_match('/@media\s*\(\s*prefers-color-scheme\s*:\s*dark\s*\)\s*\{/i', $css, $m, PREG_OFFSET_CAPTURE, $pos)) {
        $start = $m[0][1];
        $open = $start + strlen($m[0][0]);
        $depth = 1;
        $i = $open;
        $len = strlen($css);
        while ($i < $len && $depth > 0) {
            if ($css[$i] === '{') {
                $depth++;
            } elseif ($css[$i] === '}') {
                $depth--;
            }
            $i++;
        }
        $out .= substr($css, $pos, $start - $pos);
        if ($darkMode == 2) {
            $out .= '@media not print {' . substr($css, $open, $i - $open);
        }
        $pos = $i;
    }
    return $out . substr($css, $pos);
}
