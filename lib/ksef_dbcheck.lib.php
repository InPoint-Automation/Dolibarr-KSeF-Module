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
 * \file    lib/ksef_dbcheck.lib.php
 * \ingroup ksef
 * \brief   Database check, repair
 */

/**
 * @brief Strip SQL comments
 * @param string $sql
 * @return string
 */
function ksefDbCheckStripComments($sql)
{
    return preg_replace('/^\s*--.*$/m', '', (string) $sql);
}

/**
 * @brief Split top-level commas
 * @param string $s
 * @return string[]
 */
function ksefDbCheckSplitTop($s)
{
    $out = array();
    $depth = 0;
    $cur = '';
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $c = $s[$i];
        if ($c == '(') {
            $depth++;
        } elseif ($c == ')') {
            $depth--;
        }
        if ($c == ',' && $depth == 0) {
            $out[] = trim($cur);
            $cur = '';
            continue;
        }
        $cur .= $c;
    }
    if (trim($cur) !== '') {
        $out[] = trim($cur);
    }
    return $out;
}

/**
 * @brief Parse column list
 * @param string $s Without parentheses
 * @return string[]
 */
function ksefDbCheckColumnList($s)
{
    return array_values(array_filter(array_map(function ($c) {
        return strtolower(trim($c, " \t\n\r`"));
    }, explode(',', $s)), 'strlen'));
}

/**
 * @brief Prefixed table name
 * @param string $table Without prefix
 * @return string
 */
function ksefDbCheckTableName($table)
{
    return MAIN_DB_PREFIX . $table;
}

/**
 * @brief Apply table prefix
 * @param string $sql
 * @return string
 */
function ksefDbCheckPrefix($sql)
{
    return preg_replace('/\bllx_/', MAIN_DB_PREFIX, $sql);
}

/**
 * @brief Parse module schema
 * @param string $dir SQL dir
 * @return array
 */
function ksefDbCheckSchema($dir = '')
{
    static $cache = array();
    if ($dir === '') {
        $dir = dol_buildpath('/ksef/sql', 0);
    }
    if (isset($cache[$dir])) {
        return $cache[$dir];
    }
    $schema = array();
    $files = glob($dir . '/llx_ksef_*.sql');
    $files = is_array($files) ? $files : array();
    sort($files);
    foreach ($files as $file) {
        if (preg_match('/\.key\.sql$/', $file)) {
            continue;
        }
        $sql = trim(ksefDbCheckStripComments(file_get_contents($file)));
        $sql = rtrim($sql, "; \n\r\t");
        if (!preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?llx_(\w+)\s*\((.*)\)([^()]*)$/is', $sql, $m)) {
            continue;
        }
        $table = strtolower($m[1]);
        $columns = array();
        foreach (ksefDbCheckSplitTop($m[2]) as $part) {
            if (preg_match('/^(PRIMARY|UNIQUE|KEY|INDEX|CONSTRAINT|FOREIGN)\b/i', $part)) {
                continue;
            }
            if (!preg_match('/^`?(\w+)`?\s+(\w+)\s*(\([^)]*\))?(.*)$/s', $part, $cm)) {
                continue;
            }
            $rest = trim(preg_replace('/\s+/', ' ', $cm[4]));
            $pk = (bool) preg_match('/\bPRIMARY\s+KEY\b/i', $rest);
            $columns[strtolower($cm[1])] = array(
                'def' => strtoupper($cm[2]) . (isset($cm[3]) ? str_replace(' ', '', $cm[3]) : '') . ($rest !== '' ? ' ' . $rest : ''),
                'base' => ksefDbCheckBaseType($cm[2]),
                'args' => isset($cm[3]) ? str_replace(' ', '', trim($cm[3], '()')) : '',
                'notnull' => $pk || (bool) preg_match('/\bNOT\s+NULL\b/i', $rest),
                'null_explicit' => (bool) preg_match('/(?<!NOT )\bNULL\b/i', $rest),
                'pk' => $pk,
            );
        }
        $schema[$table] = array('file' => basename($file), 'create' => $sql, 'columns' => $columns, 'keys' => array());
    }
    foreach ($files as $file) {
        if (!preg_match('/\.key\.sql$/', $file)) {
            continue;
        }
        $text = ksefDbCheckStripComments(file_get_contents($file));
        foreach (explode(';', $text) as $stmt) {
            $stmt = trim(preg_replace('/\s+/', ' ', $stmt));
            if ($stmt === '') {
                continue;
            }
            $key = null;
            if (preg_match('/^ALTER TABLE llx_(\w+) ADD (UNIQUE )?(?:INDEX|KEY) (\w+) ?\(([^)]*)\)/i', $stmt, $km)) {
                $key = array(strtolower($km[1]), $km[3], array('type' => $km[2] ? 'unique' : 'index', 'columns' => ksefDbCheckColumnList($km[4])));
            } elseif (preg_match('/^CREATE (UNIQUE )?INDEX (\w+) ON llx_(\w+) ?\(([^)]*)\)/i', $stmt, $km)) {
                $key = array(strtolower($km[3]), $km[2], array('type' => $km[1] ? 'unique' : 'index', 'columns' => ksefDbCheckColumnList($km[4])));
            } elseif (preg_match('/^ALTER TABLE llx_(\w+) ADD CONSTRAINT (\w+) FOREIGN KEY ?\(([^)]*)\) REFERENCES llx_(\w+) ?\(([^)]*)\)/i', $stmt, $km)) {
                $key = array(strtolower($km[1]), $km[2], array('type' => 'fk', 'columns' => ksefDbCheckColumnList($km[3]),
                    'ref_table' => strtolower($km[4]), 'ref_columns' => ksefDbCheckColumnList($km[5]),
                    'on_delete' => preg_match('/ON DELETE (CASCADE|SET NULL)/i', $stmt, $om) ? strtoupper($om[1]) : ''));
            }
            if ($key && isset($schema[$key[0]])) {
                $key[2]['sql'] = $stmt;
                $schema[$key[0]]['keys'][$key[1]] = $key[2];
            }
        }
    }
    $cache[$dir] = $schema;
    return $schema;
}

/**
 * @brief Normalize type name
 * @param string $type
 * @return string
 */
function ksefDbCheckBaseType($type)
{
    $type = strtolower($type);
    $alias = array('integer' => 'int', 'bool' => 'tinyint', 'boolean' => 'tinyint', 'real' => 'double');
    return isset($alias[$type]) ? $alias[$type] : $type;
}

/**
 * @brief Compare column definitions
 * @param array $exp Declared column
 * @param object $live SHOW COLUMNS row
 * @return array
 */
function ksefDbCheckCompareColumn($exp, $live)
{
    $liveType = strtolower((string) $live->Type);
    preg_match('/^(\w+)(?:\(([^)]*)\))?/', $liveType, $lm);
    $lbase = isset($lm[1]) ? $lm[1] : $liveType;
    $largs = isset($lm[2]) ? str_replace(' ', '', $lm[2]) : '';
    $lnull = (strtoupper((string) $live->Null) == 'YES');
    $ebase = $exp['base'];
    $eargs = strtolower($exp['args']);

    $families = array(
        array('tinyint', 'smallint', 'mediumint', 'int', 'bigint'),
        array('tinytext', 'text', 'mediumtext', 'longtext'),
    );
    $typeOk = false;
    $narrower = false;
    if ($lbase == $ebase) {
        if (in_array($ebase, array('varchar', 'char'))) {
            $typeOk = ((int) $largs >= (int) $eargs);
            $narrower = !$typeOk;
        } elseif (in_array($ebase, array('double', 'decimal', 'float'))) {
            $typeOk = ($largs == $eargs);
        } else {
            $typeOk = true;
        }
    } else {
        foreach ($families as $fam) {
            $li = array_search($lbase, $fam);
            $ei = array_search($ebase, $fam);
            if ($li !== false && $ei !== false) {
                $typeOk = ($li >= $ei);
                $narrower = !$typeOk;
            }
        }
    }
    $nullOk = $exp['pk'] || ($exp['notnull'] == !$lnull);
    // explicit_defaults_for_timestamp
    if (!$nullOk && $ebase == 'timestamp' && !$exp['notnull'] && empty($exp['null_explicit']) && !$lnull) {
        $nullOk = true;
    }
    $tightNull = (!$exp['pk'] && !$exp['notnull'] && !$lnull);
    $printExp = $ebase . ($eargs !== '' && !in_array($ebase, array('int', 'tinyint', 'smallint', 'mediumint', 'bigint')) ? '(' . $eargs . ')' : '') . ($exp['notnull'] ? ' NOT NULL' : ' NULL');
    $printLive = $liveType . ($lnull ? ' NULL' : ' NOT NULL');
    $ok = $typeOk && $nullOk;
    return array(
        'ok' => $ok,
        'widen' => !$ok && !$exp['pk'] && ($typeOk || $narrower) && ($nullOk || $tightNull),
        'live' => $printLive,
        'expected' => $printExp,
    );
}

/**
 * @brief Check table exists
 * @param DoliDB $db
 * @param string $fullName Prefixed name
 * @return bool
 */
function ksefDbCheckTableExists($db, $fullName)
{
    return (bool) $db->DDLListTables($db->database_name, $fullName);
}

/**
 * @brief Live table columns
 * @param DoliDB $db
 * @param string $fullName Prefixed name
 * @return array
 */
function ksefDbCheckLiveColumns($db, $fullName)
{
    $out = array();
    $pg = $db->type == 'pgsql';
    $resql = $pg ? $db->DDLDescTable($fullName) : $db->query("SHOW COLUMNS FROM " . $fullName);
    if ($resql) {
        while ($obj = $db->fetch_object($resql)) {
            $out[strtolower($pg ? $obj->attname : $obj->Field)] = $obj;
        }
    }
    return $out;
}

/**
 * @brief Live table indexes
 * @param DoliDB $db
 * @param string $fullName Prefixed name
 * @return array
 */
function ksefDbCheckLiveIndexes($db, $fullName)
{
    $out = array();
    $resql = $db->query("SHOW INDEX FROM " . $fullName);
    if ($resql) {
        while ($obj = $db->fetch_object($resql)) {
            $name = $obj->Key_name;
            if (!isset($out[$name])) {
                $out[$name] = array('unique' => ((int) $obj->Non_unique == 0), 'columns' => array());
            }
            $out[$name]['columns'][(int) $obj->Seq_in_index] = strtolower($obj->Column_name);
        }
    }
    foreach ($out as $name => $idx) {
        ksort($idx['columns']);
        $out[$name]['columns'] = array_values($idx['columns']);
    }
    return $out;
}

/**
 * @brief Live foreign keys
 * @param DoliDB $db
 * @param string $fullName Prefixed name
 * @return array
 */
function ksefDbCheckLiveForeignKeys($db, $fullName)
{
    $out = array();
    $resql = $db->query("SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE"
        . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $db->escape($fullName) . "' AND REFERENCED_TABLE_NAME IS NOT NULL"
        . " ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION");
    if ($resql) {
        while ($obj = $db->fetch_object($resql)) {
            if (!isset($out[$obj->CONSTRAINT_NAME])) {
                $out[$obj->CONSTRAINT_NAME] = array('columns' => array(), 'ref_table' => strtolower($obj->REFERENCED_TABLE_NAME));
            }
            $out[$obj->CONSTRAINT_NAME]['columns'][] = strtolower($obj->COLUMN_NAME);
        }
    }
    return $out;
}

/**
 * @brief Query count
 * @param DoliDB $db
 * @param string $sql Selects n
 * @return int
 */
function ksefDbCheckCount($db, $sql)
{
    $resql = $db->query($sql);
    if ($resql && ($obj = $db->fetch_object($resql))) {
        return (int) $obj->n;
    }
    return 0;
}

/**
 * @brief Unique key duplicates
 * @param DoliDB $db
 * @param string $fullName Prefixed name
 * @param string[] $columns Key columns
 * @return array
 */
function ksefDbCheckDuplicates($db, $fullName, $columns)
{
    $cols = implode(', ', $columns);
    $notNull = implode(' AND ', array_map(function ($c) {
        return $c . ' IS NOT NULL';
    }, $columns));
    $resql = $db->query("SELECT COUNT(*) AS v, COALESCE(SUM(n), 0) AS r FROM (SELECT COUNT(*) AS n FROM " . $fullName
        . " WHERE " . $notNull . " GROUP BY " . $cols . " HAVING COUNT(*) > 1) AS d");
    if ($resql && ($obj = $db->fetch_object($resql))) {
        return array('values' => (int) $obj->v, 'rows' => (int) $obj->r);
    }
    return array('values' => 0, 'rows' => 0);
}

/**
 * @brief Count orphan rows
 * @param DoliDB $db
 * @param string $fullName Prefixed name
 * @param array $key Declared FK
 * @return int
 */
function ksefDbCheckOrphans($db, $fullName, $key)
{
    if (count($key['columns']) != 1 || count($key['ref_columns']) != 1) {
        return 0;
    }
    $col = $key['columns'][0];
    $ref = $key['ref_columns'][0];
    return ksefDbCheckCount($db, "SELECT COUNT(*) AS n FROM " . $fullName . " AS t LEFT JOIN " . ksefDbCheckTableName($key['ref_table']) . " AS r"
        . " ON r." . $ref . " = t." . $col . " WHERE t." . $col . " IS NOT NULL AND r." . $ref . " IS NULL");
}

/**
 * @brief Module table stats
 * @param DoliDB $db
 * @param string $table '' for all
 * @return array
 */
function ksefDbCheckTableStats($db, $table = '')
{
    global $conf;
    $out = array();
    foreach (ksefDbCheckSchema() as $name => $def) {
        if ($table !== '' && $name !== $table) {
            continue;
        }
        $full = ksefDbCheckTableName($name);
        $st = array('exists' => ksefDbCheckTableExists($db, $full), 'rows' => 0, 'prod' => 0, 'other_entities' => 0, 'entities' => array());
        if ($st['exists']) {
            $cols = ksefDbCheckLiveColumns($db, $full);
            $st['rows'] = ksefDbCheckCount($db, "SELECT COUNT(*) AS n FROM " . $full);
            if (isset($cols['environment'])) {
                $st['prod'] = ksefDbCheckCount($db, "SELECT COUNT(*) AS n FROM " . $full . " WHERE environment = 'PRODUCTION'");
            }
            $sql = '';
            if (isset($cols['entity'])) {
                $sql = "SELECT entity AS e, COUNT(*) AS n FROM " . $full . " WHERE entity <> " . (int) $conf->entity . " GROUP BY entity ORDER BY entity";
            } elseif (isset($cols['fk_facture'])) {
                $sql = "SELECT f.entity AS e, COUNT(*) AS n FROM " . $full . " AS s INNER JOIN " . MAIN_DB_PREFIX . "facture AS f ON f.rowid = s.fk_facture"
                    . " WHERE f.entity <> " . (int) $conf->entity . " GROUP BY f.entity ORDER BY f.entity";
            }
            $resql = $sql !== '' ? $db->query($sql) : false;
            if ($resql) {
                while ($obj = $db->fetch_object($resql)) {
                    $st['entities'][(int) $obj->e] = (int) $obj->n;
                    $st['other_entities'] += (int) $obj->n;
                }
            }
        }
        $out[$name] = $st;
    }
    return $out;
}

/**
 * @brief Module table health
 * @param DoliDB $db
 * @param array $problems ksefDbCheckRun() result
 * @return array
 */
function ksefDbCheckTableHealth($db, $problems)
{
    $schemaCodes = array('column_missing', 'column_type', 'fk_missing', 'fk_blocked', 'index_differs', 'index_other_name', 'unique_blocked', 'table_charset', 'table_collation');
    $out = array();
    foreach (ksefDbCheckSchema() as $table => $def) {
        $full = ksefDbCheckTableName($table);
        $h = array('state' => 'ok', 'detail' => '', 'schema' => 0, 'data' => 0);
        if (!ksefDbCheckTableExists($db, $full)) {
            $h['state'] = 'missing';
            $out[$table] = $h;
            continue;
        }
        foreach ((array) $problems as $p) {
            $o = (string) $p['object'];
            if ($p['severity'] == 'info' || ($o !== $full && strpos($o, $full . '.') !== 0 && strpos($o, $full . ' /') !== 0)) {
                continue;
            }
            if (in_array($p['code'], $schemaCodes, true)) {
                $h['schema']++;
            } else {
                $h['data']++;
            }
        }
        if ($db->type != 'pgsql') {
            $res = $db->query("CHECK TABLE " . $full);
            if (!$res) {
                $h['state'] = 'corrupt';
                $h['detail'] = $db->lasterror();
            } else {
                while ($obj = $db->fetch_object($res)) {
                    $type = strtolower((string) $obj->Msg_type);
                    $text = (string) $obj->Msg_text;
                    if ($type == 'error' || ($type == 'status' && !in_array($text, array('OK', 'Table is already up to date'), true))) {
                        $h['state'] = 'corrupt';
                        $h['detail'] = trim($h['detail'] . ' ' . $text);
                    }
                }
            }
        }
        if ($h['state'] == 'ok' && $h['schema'] > 0) {
            $h['state'] = 'differs';
        } elseif ($h['state'] == 'ok' && $h['data'] > 0) {
            $h['state'] = 'data';
        }
        $out[$table] = $h;
    }
    return $out;
}

/**
 * @brief Class constant values
 * @param string $class
 * @param string $prefix
 * @return string[]
 */
function ksefDbCheckConstValues($class, $prefix)
{
    $out = array();
    if (!class_exists($class)) {
        return $out;
    }
    $rc = new ReflectionClass($class);
    foreach ($rc->getConstants() as $name => $value) {
        if (strpos($name, $prefix) === 0 && is_string($value)) {
            $out[] = $value;
        }
    }
    return $out;
}

/**
 * @brief Add problem
 * @param array $problems
 * @param string $severity error|warning|info
 * @param string $code
 * @param string $object
 * @param string $problem
 * @param string $fix
 * @param array|null $repair Repair step
 * @param array $extra Extra fields
 * @return void
 */
function ksefDbCheckAdd(&$problems, $severity, $code, $object, $problem, $fix, $repair = null, $extra = array())
{
    $problems[] = array_merge(array('severity' => $severity, 'code' => $code, 'object' => $object, 'problem' => $problem, 'fix' => $fix, 'repair' => $repair), $extra);
}

/**
 * @brief Problem object label
 * @param string $object
 * @return string
 */
function ksefDbCheckObjectLabel($object)
{
    global $langs;
    if (preg_match('/^(KSEF_CronJob_\w+):ksef@ksef$/', (string) $object, $m)) {
        $langs->load('ksef@ksef');
        $name = $langs->transnoentitiesnoconv($m[1]);
        return $name !== $m[1] ? $name : (string) $object;
    }
    return (string) $object;
}

/**
 * @brief Run database check
 * @param DoliDB $db
 * @param modKSEF $mod
 * @return array Problems
 */
function ksefDbCheckRun($db, $mod)
{
    global $conf, $langs;
    $langs->loadLangs(array('ksef@ksef'));
    $problems = array();
    $repairFix = $langs->trans('KSEF_DbF_Repair');
    $pg = $db->type == 'pgsql';
    if ($pg) {
        ksefDbCheckAdd($problems, 'info', 'pgsql_partial', 'PostgreSQL', $langs->trans('KSEF_DbP_NotCheckedPgsql'), $langs->trans('KSEF_DbF_Info'));
    }

    foreach (ksefDbCheckSchema() as $table => $def) {
        $full = ksefDbCheckTableName($table);
        if (!ksefDbCheckTableExists($db, $full)) {
            ksefDbCheckAdd($problems, 'error', 'table_missing', $full, $langs->trans('KSEF_DbP_TableMissing'), $repairFix, array('op' => 'create_table', 'table' => $table));
            continue;
        }
        $live = ksefDbCheckLiveColumns($db, $full);
        $prev = '';
        foreach ($def['columns'] as $col => $cdef) {
            if (!isset($live[$col])) {
                ksefDbCheckAdd($problems, 'error', 'column_missing', $full . '.' . $col, $langs->trans('KSEF_DbP_ColumnMissing'),
                    $cdef['pk'] ? $langs->trans('KSEF_DbF_ResetTable') : $repairFix,
                    $cdef['pk'] ? null : array('op' => 'add_column', 'table' => $table, 'column' => $col, 'after' => $prev));
            } elseif (!$pg) {
                $cmp = ksefDbCheckCompareColumn($cdef, $live[$col]);
                if (!$cmp['ok']) {
                    $sql = "ALTER TABLE " . $full . " MODIFY " . $col . " " . $cdef['def'];
                    ksefDbCheckAdd($problems, 'warning', 'column_type', $full . '.' . $col, $langs->trans('KSEF_DbP_ColumnType', $cmp['live'], $cmp['expected']),
                        $cmp['widen'] ? $langs->trans('KSEF_DbF_RepairModify', $cmp['expected']) : $langs->trans('KSEF_DbF_ByHand', $sql),
                        $cmp['widen'] ? array('op' => 'modify_column', 'table' => $table, 'column' => $col) : null);
                }
            }
            $prev = $col;
        }
        foreach ($live as $col => $obj) {
            if (!isset($def['columns'][$col])) {
                ksefDbCheckAdd($problems, 'info', 'column_extra', $full . '.' . $col, $langs->trans('KSEF_DbP_ColumnExtra'), $langs->trans('KSEF_DbF_ColumnExtra'));
            }
        }
        if ($pg) {
            continue;
        }
        $indexes = ksefDbCheckLiveIndexes($db, $full);
        $fks = null;
        foreach ($def['keys'] as $name => $key) {
            $obj = $full . ' / ' . $name;
            if ($key['type'] == 'fk') {
                if ($fks === null) {
                    $fks = ksefDbCheckLiveForeignKeys($db, $full);
                }
                if (isset($fks[$name])) {
                    continue;
                }
                $missingCols = array_diff($key['columns'], array_keys($live));
                $orphans = $missingCols ? 0 : ksefDbCheckOrphans($db, $full, $key);
                if ($orphans > 0) {
                    $nullable = $key['on_delete'] === 'SET NULL';
                    ksefDbCheckAdd($problems, $nullable ? 'warning' : 'info', 'fk_blocked', $obj, $langs->trans('KSEF_DbP_FkBlocked', $orphans), $langs->trans($nullable ? 'KSEF_DbF_FkOrphansNull' : 'KSEF_DbF_FkBlocked'),
                        $nullable ? array('op' => 'fk_orphans', 'table' => $table, 'key' => $name) : null,
                        array('n' => $orphans, 'rows' => array('table' => $full, 'where' => ksefDbCheckOrphanWhere($key))));
                } else {
                    ksefDbCheckAdd($problems, 'error', 'fk_missing', $obj, $langs->trans('KSEF_DbP_FkMissing'), $repairFix, array('op' => 'add_key', 'table' => $table, 'key' => $name));
                }
                continue;
            }
            if (isset($indexes[$name])) {
                if ($indexes[$name]['columns'] != $key['columns'] || $indexes[$name]['unique'] != ($key['type'] == 'unique')) {
                    ksefDbCheckAdd($problems, 'warning', 'index_differs', $obj, $langs->trans('KSEF_DbP_IndexDiffers',
                        ($indexes[$name]['unique'] ? 'UNIQUE ' : '') . implode(', ', $indexes[$name]['columns']), ($key['type'] == 'unique' ? 'UNIQUE ' : '') . implode(', ', $key['columns'])),
                        $langs->trans('KSEF_DbF_IndexDiffers'));
                }
                continue;
            }
            $other = '';
            foreach ($indexes as $iname => $idx) {
                if ($idx['columns'] == $key['columns'] && ($idx['unique'] || $key['type'] != 'unique')) {
                    $other = $iname;
                    break;
                }
            }
            if ($other !== '') {
                ksefDbCheckAdd($problems, 'info', 'index_other_name', $obj, $langs->trans('KSEF_DbP_IndexOtherName', $other), $langs->trans('KSEF_DbF_Info'));
                continue;
            }
            if (array_diff($key['columns'], array_keys($live))) {
                ksefDbCheckAdd($problems, 'error', $key['type'] == 'unique' ? 'unique_missing' : 'index_missing', $obj,
                    $langs->trans($key['type'] == 'unique' ? 'KSEF_DbP_UniqueMissing' : 'KSEF_DbP_IndexMissing'), $repairFix, array('op' => 'add_key', 'table' => $table, 'key' => $name));
                continue;
            }
            if ($key['type'] == 'unique') {
                $dup = ksefDbCheckDuplicates($db, $full, $key['columns']);
                if ($dup['values'] > 0) {
                    $cols = implode(', ', $key['columns']);
                    ksefDbCheckAdd($problems, 'warning', 'unique_blocked', $obj, $langs->trans('KSEF_DbP_UniqueBlocked', $dup['values'], $dup['rows']), $langs->trans('KSEF_DbF_UniqueBlocked'),
                        array('op' => 'dedupe', 'table' => $table, 'key' => $name),
                        array('n' => $dup['rows'], 'rows' => array('table' => $full, 'where' => "(" . $cols . ") IN (SELECT " . $cols . " FROM (SELECT " . $cols . " FROM " . $full
                            . " GROUP BY " . $cols . " HAVING COUNT(*) > 1) AS d)")));
                    continue;
                }
            }
            ksefDbCheckAdd($problems, 'error', $key['type'] == 'unique' ? 'unique_missing' : 'index_missing', $obj,
                $langs->trans($key['type'] == 'unique' ? 'KSEF_DbP_UniqueMissing' : 'KSEF_DbP_IndexMissing'), $repairFix, array('op' => 'add_key', 'table' => $table, 'key' => $name));
        }
    }

    foreach ($mod->getKsefExtraFieldDefs() as $group) {
        foreach ($group['elements'] as $element) {
            $efTable = MAIN_DB_PREFIX . $element . '_extrafields';
            $efCols = ksefDbCheckTableExists($db, $efTable) ? ksefDbCheckLiveColumns($db, $efTable) : array();
            $declared = array();
            $resql = $db->query("SELECT name FROM " . MAIN_DB_PREFIX . "extrafields WHERE elementtype = '" . $db->escape($element) . "'"
                . " AND entity IN (0, " . (int) $conf->entity . ")");
            if ($resql) {
                while ($obj = $db->fetch_object($resql)) {
                    $declared[strtolower($obj->name)] = true;
                }
            }
            foreach ($group['defs'] as $name => $fdef) {
                if (isset($fdef['element_types']) && is_array($fdef['element_types']) && !in_array($element, $fdef['element_types'])) {
                    continue;
                }
                $repair = array('op' => 'extrafield', 'element' => $element, 'name' => $name);
                if (!isset($declared[$name])) {
                    ksefDbCheckAdd($problems, 'error', 'extrafield_missing', $element . ' / ' . $name, $langs->trans('KSEF_DbP_ExtrafieldMissing'), $repairFix, $repair);
                } elseif (!isset($efCols[$name])) {
                    ksefDbCheckAdd($problems, 'error', 'extrafield_column_missing', $efTable . '.' . $name, $langs->trans('KSEF_DbP_ExtrafieldColumnMissing'), $repairFix, $repair);
                }
            }
        }
    }

    $lastInit = getDolGlobalString('KSEF_LAST_INIT_VERSION', '');
    $migration = getDolGlobalString('KSEF_MIGRATION_VERSION', '');
    $preSquash = $mod->migrationLevel($migration, getDolGlobalString('KSEF_MIGRATION_SQUASHED', '')) !== $migration;
    if (ksefNeedsReactivation($mod->version, $lastInit) || $migration === '' || $preSquash) {
        ksefDbCheckAdd($problems, 'error', 'migration_behind', 'KSEF_MIGRATION_VERSION',
            $langs->trans('KSEF_DbP_MigrationBehind', $mod->version, $lastInit !== '' ? $lastInit : '-', $migration !== '' ? $migration : '-'),
            $langs->trans('KSEF_DbF_Migrate'), array('op' => 'migrate'));
    }

    if (is_array($mod->cronjobs)) {
        foreach ($mod->cronjobs as $job) {
            $entity = isset($job['entity']) ? (int) $job['entity'] : (int) $conf->entity;
            $obj = ksefCronFind($db, $job, $entity);
            if (!$obj) {
                ksefDbCheckAdd($problems, 'error', 'cron_missing', $job['label'], $langs->trans('KSEF_DbP_CronMissing'), $repairFix, array('op' => 'cron'));
                continue;
            }
            $want = $job['class'] . ' ' . $job['objectname'] . '::' . $job['method'];
            $have = $obj->classesname . ' ' . $obj->objectname . '::' . $obj->methodename;
            if (isset($job['jobtype']) && $job['jobtype'] == 'method' && $want !== $have) {
                ksefDbCheckAdd($problems, 'warning', 'cron_differs', $job['label'], $langs->trans('KSEF_DbP_CronDiffers', $have, $want), $repairFix,
                    array('op' => 'cron_fix', 'rowid' => (int) $obj->rowid, 'job' => $job));
            }
        }
    }

    $schema = ksefDbCheckSchema();
    $sub = ksefDbCheckTableName('ksef_submissions');
    if (isset($schema['ksef_submissions']) && ksefDbCheckTableExists($db, $sub)) {
        $cols = ksefDbCheckLiveColumns($db, $sub);
        if (isset($cols['fk_facture'])) {
            $n = ksefDbCheckCount($db, "SELECT COUNT(*) AS n FROM " . $sub . " AS s LEFT JOIN " . MAIN_DB_PREFIX . "facture AS f ON f.rowid = s.fk_facture WHERE f.rowid IS NULL");
            $listed = false;
            foreach ($problems as $p) {
                $listed = $listed || ($p['code'] == 'fk_blocked' && $p['object'] === $sub . ' / fk_ksef_submissions_fk_facture');
            }
            if ($n > 0 && !$listed) {
                ksefDbCheckAdd($problems, 'info', 'orphan_submissions', $sub . '.fk_facture', $langs->trans('KSEF_DbP_OrphanSubmissions', $n), $langs->trans('KSEF_DbF_Info'), null,
                    array('n' => $n, 'rows' => array('table' => $sub, 'where' => "t.fk_facture IS NOT NULL AND NOT EXISTS (SELECT 1 FROM " . MAIN_DB_PREFIX . "facture AS f WHERE f.rowid = t.fk_facture)")));
            }
        }
    }
    dol_include_once('/ksef/class/ksef_submission.class.php');
    dol_include_once('/ksef/class/ksef_incoming.class.php');
    dol_include_once('/ksef/class/ksef_sales.class.php');
    $incomingStatuses = ksefDbCheckConstValues('KsefIncoming', 'STATUS_');
    $statusSets = array(
        array('ksef_submissions', 'status', ksefDbCheckConstValues('KsefSubmission', 'STATUS_')),
        array('ksef_incoming', 'import_status', $incomingStatuses),
        array('ksef_sales', 'import_status', $incomingStatuses),
        array('ksef_sales', 'link_status', ksefDbCheckConstValues('KsefSales', 'LINK_')),
    );
    foreach ($statusSets as $set) {
        list($table, $col, $known) = $set;
        $full = ksefDbCheckTableName($table);
        if (!$known || !isset($schema[$table]) || !ksefDbCheckTableExists($db, $full)) {
            continue;
        }
        $cols = ksefDbCheckLiveColumns($db, $full);
        if (!isset($cols[$col])) {
            continue;
        }
        $quoted = implode(', ', array_map(function ($v) use ($db) {
            return "'" . $db->escape($v) . "'";
        }, $known));
        $resql = $db->query("SELECT " . $col . " AS v, COUNT(*) AS n FROM " . $full . " WHERE " . $col . " IS NOT NULL AND " . $col . " <> ''"
            . " AND " . $col . " NOT IN (" . $quoted . ") GROUP BY " . $col . " ORDER BY " . $col);
        $vals = array();
        $n = 0;
        if ($resql) {
            while ($obj = $db->fetch_object($resql)) {
                $vals[] = $obj->v;
                $n += (int) $obj->n;
            }
        }
        if ($n > 0) {
            ksefDbCheckAdd($problems, 'warning', 'unknown_status', $full . '.' . $col, $langs->trans('KSEF_DbP_UnknownStatus', $n, $col, dol_trunc(implode(', ', $vals), 80)),
                $langs->trans('KSEF_DbF_UnknownStatus'), array('op' => 'status_reset', 'table' => $table, 'column' => $col, 'to' => ksefDbCheckErrorStatus($table, $col)),
                array('n' => $n, 'values' => dol_trunc(implode(', ', $vals), 80),
                'rows' => array('table' => $full, 'where' => "t." . $col . " IS NOT NULL AND t." . $col . " <> '' AND t." . $col . " NOT IN (" . $quoted . ")")));
        }
    }

    foreach (ksefDbHealthCharset($db) as $table => $cs) {
        if ($cs['kind'] == 'charset') {
            ksefDbCheckAdd($problems, 'warning', 'table_charset', $table, $langs->trans('KSEF_DbP_TableCharset', $cs['collation']), $langs->trans('KSEF_DbF_RepairCharset', $cs['sql']),
                array('op' => 'table_charset', 'table' => $table));
        } else {
            ksefDbCheckAdd($problems, 'warning', 'table_collation', $table, $langs->trans('KSEF_DbP_TableCollation', $cs['collation'], $cs['target']), $langs->trans('KSEF_DbF_RepairCharset', $cs['sql']),
                array('op' => 'table_charset', 'table' => $table));
        }
    }
    return ksefDbCheckSeverityRule($problems);
}

/**
 * @brief Adjust severities
 * @param array $problems
 * @return array
 */
function ksefDbCheckSeverityRule($problems)
{
    foreach ($problems as $i => $p) {
        if ($p['severity'] == 'info' && ksefDbCheckTier($p) > 0) {
            $problems[$i]['severity'] = 'warning';
        } elseif ($p['severity'] == 'error' && in_array($p['code'], array('index_missing', 'unique_missing'), true)) {
            $problems[$i]['severity'] = 'warning';
        }
    }
    return $problems;
}

/**
 * @brief Orphan rows WHERE
 * @param array $key Declared FK
 * @return string
 */
function ksefDbCheckOrphanWhere($key)
{
    $col = $key['columns'][0];
    return "t." . $col . " IS NOT NULL AND NOT EXISTS (SELECT 1 FROM " . ksefDbCheckTableName($key['ref_table']) . " AS r WHERE r." . $key['ref_columns'][0] . " = t." . $col . ")";
}

/**
 * @brief Finding repair tier
 * @param array $p Problem
 * @return int 0 none, 1 structure, 2 records
 */
function ksefDbCheckTier($p)
{
    if (empty($p['repair']['op']) || $p['repair']['op'] == 'migrate') {
        return 0;
    }
    return in_array($p['repair']['op'], array('create_table', 'add_column', 'modify_column', 'add_key', 'extrafield', 'cron', 'cron_fix', 'table_charset'), true) ? 1 : 2;
}

/**
 * @brief Finding key
 * @param array $p Problem
 * @return string
 */
function ksefDbCheckFindingKey($p)
{
    return substr(md5($p['code'] . '|' . $p['object'] . '|' . json_encode($p['repair'])), 0, 12);
}

/**
 * @brief Finding group
 * @param array $p Problem
 * @return string '' for invoices
 */
function ksefDbCheckGroup($p)
{
    if (in_array($p['code'], array('extrafield_missing', 'extrafield_column_missing'), true)) {
        return 'extrafields';
    }
    if (in_array($p['code'], array('cron_missing', 'cron_differs'), true)) {
        return 'cron';
    }
    if (in_array($p['code'], array('migration_behind', 'pgsql_partial'), true)) {
        return 'module';
    }
    foreach (array_keys(ksefDbCheckSchema()) as $table) {
        $full = ksefDbCheckTableName($table);
        $o = (string) $p['object'];
        if ($o === $full || strpos($o, $full . '.') === 0 || strpos($o, $full . ' /') === 0) {
            return $table;
        }
    }
    return '';
}

/**
 * @brief Affected rows HTML
 * @param DoliDB $db
 * @param array $sel table, where
 * @param int $limit
 * @return string[]
 */
function ksefDbCheckAffectedRows($db, $sel, $limit = 10)
{
    $cols = ksefDbCheckLiveColumns($db, $sel['table']);
    $hasInv = isset($cols['fk_facture']);
    $sql = "SELECT t.rowid" . (isset($cols['ksef_number']) ? ", t.ksef_number" : "") . ($hasInv ? ", f.rowid AS fid, f.ref AS fref" : "")
        . " FROM " . $sel['table'] . " AS t" . ($hasInv ? " LEFT JOIN " . MAIN_DB_PREFIX . "facture AS f ON f.rowid = t.fk_facture" : "")
        . " WHERE " . $sel['where'] . " ORDER BY t.rowid" . $db->plimit((int) $limit);
    $out = array();
    $res = $db->query($sql);
    while ($res && ($o = $db->fetch_object($res))) {
        if ($hasInv && !empty($o->fid)) {
            $out[] = '<a href="' . ksefDbCheckInvoiceUrl($o->fid, 'facture') . '">' . dol_escape_htmltag($o->fref) . '</a>';
        } else {
            $out[] = '#' . (int) $o->rowid . (!empty($o->ksef_number) ? ' (KSeF ' . dol_escape_htmltag($o->ksef_number) . ')' : '');
        }
    }
    return $out;
}

/**
 * @brief Finding plain text
 * @param array $p Problem
 * @return string
 */
function ksefDbCheckPlainText($p)
{
    global $langs;
    $code = $p['code'];
    if ($code == 'column_missing' && empty($p['repair'])) {
        $code = 'column_pk';
    } elseif ($code == 'column_type' && empty($p['repair'])) {
        $code = 'column_byhand';
    } elseif ($code == 'fk_blocked' && !empty($p['repair'])) {
        $code = 'fk_orphans';
    } elseif ($code == 'unknown_status' && !empty($p['repair']) && $p['repair']['to'] === null) {
        $code = 'unknown_status_clear';
    } elseif ($code == 'cron_missing' || $code == 'cron_differs') {
        return $langs->trans('KSEF_DbPlain_' . $code, dol_escape_htmltag(ksefDbCheckObjectLabel($p['object'])));
    } elseif ($code == 'extrafield_missing' || $code == 'extrafield_column_missing') {
        return $langs->trans('KSEF_DbPlain_extrafield', dol_escape_htmltag(preg_replace('/^.*[.\/]\s*/', '', $p['object'])));
    }
    $key = 'KSEF_DbPlain_' . $code;
    $text = $langs->trans($key, isset($p['n']) ? (int) $p['n'] : '', isset($p['values']) ? dol_escape_htmltag($p['values']) : '');
    return $text !== $key ? $text : dol_escape_htmltag($p['problem']);
}

/**
 * @brief Check imported totals
 * @param DoliDB $db
 * @param string $element '' for both
 * @param int $onlyId 0 for all
 * @param array|null $rateProblems
 * @return array Problems
 */
function ksefDbCheckImportTotals($db, $element = '', $onlyId = 0, $rateProblems = null)
{
    global $conf, $langs;
    dol_include_once('/ksef/lib/ksef.lib.php');
    dol_include_once('/ksef/class/ksef_incoming.class.php');
    dol_include_once('/ksef/class/ksef_sales.class.php');
    $P = MAIN_DB_PREFIX;
    $out = array();
    $sets = array('invoice_supplier' => array('ksef_incoming', 'fk_facture_fourn', 'KsefIncoming'),
        'facture' => array('ksef_sales', 'fk_facture', 'KsefSales'));
    $cur = $db->escape((string) $conf->currency);
    $wrongRate = array();
    if ($element === '' || $element === 'invoice_supplier') {
        foreach ($rateProblems !== null ? $rateProblems : ksefDbCheckImportRates($db, $onlyId) as $p) {
            foreach ($p['ids'] as $wid) {
                $wrongRate[(int) $wid] = true;
            }
        }
    }
    foreach ($sets as $el => $s) {
        if ($element !== '' && $element !== $el) {
            continue;
        }
        list($table, $col, $cls) = $s;
        if (!ksefDbCheckTableExists($db, $P . $table)) {
            continue;
        }
        $k = ksefInvoiceKind($el);
        $sql = "SELECT r.rowid AS rid, f.rowid AS fid FROM " . $P . $table . " AS r INNER JOIN " . $P . $k['table'] . " AS f ON f.rowid = r." . $col
            . " WHERE r.import_status = 'IMPORTED' AND f.type IN (0, 1, 3) AND f.fk_statut IN (0, 1, 2) AND f.entity IN (" . getEntity($k['entity']) . ")"
            . ($onlyId > 0 ? " AND f.rowid = " . (int) $onlyId : "")
            // imported invoices only
            . ($el === 'facture' ? " AND EXISTS (SELECT 1 FROM " . $P . "ksef_submissions AS s WHERE s.fk_facture = f.rowid AND s.ksef_number = r.ksef_number AND s.source = 'linked_from_sync'"
                . " AND s.api_response LIKE '%\"from\":\"ksef_sales\"%') AND NOT EXISTS (SELECT 1 FROM " . $P . "ksef_submissions AS s2 WHERE s2.fk_facture = f.rowid AND s2.source IN ('sent', 'sent_offline'))" : "")
            . " AND (r.invoice_type LIKE 'KOR%' OR (COALESCE(f.multicurrency_code, '') IN ('', '" . $cur . "') AND (ABS(f.total_ht - r.total_net) >= 0.005 OR ABS(f.total_tva - r.total_vat) >= 0.005"
            . " OR ABS(f.total_ttc - r.total_gross) >= 0.005)) OR COALESCE(f.multicurrency_code, '') NOT IN ('', '" . $cur . "')) ORDER BY f.rowid";
        $res = $db->query($sql);
        $rows = array();
        while ($res && ($o = $db->fetch_object($res))) {
            $rows[] = $o;
        }
        foreach ($rows as $o) {
            $rec = new $cls($db);
            $inv = new $k['class']($db);
            if ($el === 'invoice_supplier' && isset($wrongRate[(int) $o->fid])) {
                continue;
            }
            if ($rec->fetch((int) $o->rid) <= 0 || $inv->fetch((int) $o->fid) <= 0) {
                continue;
            }
            $cmp = ksefCompareIncomingTotals($rec, $inv, true);
            if ($cmp['ok'] || $cmp['skipped']) {
                continue;
            }
            $foreign = ksefInvoiceIsForeign($inv);
            $target = ksefImportTargetTotals($rec, $inv, $foreign);
            $settle = $target ? ksefSettleImportedTotals($db, $inv, $target, $foreign, $target['korekta'] ? 'none' : 'xml', true, ksefImportRoundingSlack($rec, $target, $foreign, true))
                : array('moved' => 0, 'far' => array('-' => 1), 'error' => '');
            $id = (int) $inv->id;
            $state = ksefDbCheckImportLocked($db, $el, $inv) ? 'locked' : ($settle['far'] || !$settle['moved'] ? 'far' : 'repairable');
            $money = function ($v) use ($langs, $cmp) {
                return price($v, 0, $langs, 1, -1, 2, $cmp['currency']);
            };
            $rates = array();
            foreach ($cmp['rates'] as $rate => $r) {
                if ($r['xml_net'] != $r['doli_net'] || $r['xml_vat'] != $r['doli_vat']) {
                    $rates[] = $rate . '%: ' . price($r['doli_net'], 0, $langs, 1, -1, 2) . ' / ' . price($r['doli_vat'], 0, $langs, 1, -1, 2)
                        . ' - ' . price($r['xml_net'], 0, $langs, 1, -1, 2) . ' / ' . price($r['xml_vat'], 0, $langs, 1, -1, 2);
                }
            }
            foreach ($cmp['pln'] as $rate => $r) {
                $rates[] = $rate . '% (' . $langs->transnoentitiesnoconv('KSEF_DbK_VatPln') . '): ' . price($r['doli_vat'], 0, $langs, 1, -1, 2) . ' - ' . price($r['xml_vat'], 0, $langs, 1, -1, 2);
            }
            $fix = array('repairable' => 'KSEF_DbF_ImportTotals', 'locked' => 'KSEF_DbF_ImportTotalsLocked', 'far' => $cmp['oss_differs'] ? 'KSEF_DbF_ImportTotalsOss' : 'KSEF_DbF_ImportTotalsFar');
            $object = (string) $inv->ref . ($el === 'invoice_supplier' && (string) $inv->ref_supplier !== '' ? ' (' . $inv->ref_supplier . ')' : '');
            $out[] = array('severity' => 'warning', 'code' => 'import_totals', 'object' => $object, 'url' => ksefDbCheckInvoiceUrl($id, $el),
                'problem' => $langs->transnoentitiesnoconv('KSEF_DbP_ImportTotals', $money($cmp['doli_ttc']), $money($cmp['xml_ttc']), implode('; ', $rates)),
                'fix' => $langs->transnoentitiesnoconv($fix[$state]) . ($state === 'far' && $cmp['oss_differs'] && $cmp['other_differs'] ? ' ' . $langs->transnoentitiesnoconv('KSEF_ImportTotalsOssAlsoOther') : ''), 'repair' => $state === 'repairable' ? array('op' => 'import_totals', 'element' => $el, 'id' => $id) : null,
                'state' => $state, 'element' => $el, 'id' => $id,
                'rows' => array(array($langs->transnoentitiesnoconv('TotalTTC'), $money($cmp['doli_ttc']), $money($cmp['xml_ttc']))));
        }
    }
    return $out;
}

/**
 * @brief Repair imported totals
 * @param DoliDB $db
 * @param array $r Repair step
 * @return array
 */
function ksefDbCheckRepairImportTotals($db, $r)
{
    global $langs;
    $element = $r['element'] === 'invoice_supplier' ? 'invoice_supplier' : 'facture';
    $now = ksefDbCheckImportTotals($db, $element, (int) $r['id']);
    if (!$now || $now[0]['state'] !== 'repairable') {
        return array('result' => 'skipped', 'detail' => $langs->transnoentitiesnoconv('KSEF_DbR_DepositChanged'));
    }
    $k = ksefInvoiceKind($element);
    $cls = $element === 'invoice_supplier' ? 'KsefIncoming' : 'KsefSales';
    $inv = new $k['class']($db);
    $rec = new $cls($db);
    $o = $db->getRow("SELECT rowid FROM " . MAIN_DB_PREFIX . ($element === 'invoice_supplier' ? "ksef_incoming WHERE fk_facture_fourn = " : "ksef_sales WHERE fk_facture = ") . (int) $r['id']
        . " AND import_status = 'IMPORTED' ORDER BY rowid DESC");
    if (!$o || $rec->fetch((int) $o->rowid) <= 0 || $inv->fetch((int) $r['id']) <= 0) {
        return array('result' => 'skipped', 'detail' => $langs->transnoentitiesnoconv('KSEF_DbR_DepositChanged'));
    }
    $foreign = ksefInvoiceIsForeign($inv);
    $before = (float) ($foreign ? $inv->multicurrency_total_ttc : $inv->total_ttc);
    $db->begin();
    $target = ksefImportTargetTotals($rec, $inv, $foreign);
    $s = ksefSettleImportedTotals($db, $inv, $target, $foreign, $target['korekta'] ? 'none' : 'xml', false, ksefImportRoundingSlack($rec, $target, $foreign, true));
    if ($s['error'] !== '') {
        $db->rollback();
        return array('result' => 'failed', 'detail' => $s['error']);
    }
    $inv->fetch($inv->id);
    if (ksefSyncBaseMulticurrencyTotals($db, $inv) < 0) {
        $db->rollback();
        return array('result' => 'failed', 'detail' => $db->lasterror());
    }
    $db->commit();
    $inv->fetch($inv->id);
    $cur = $foreign ? $inv->multicurrency_code : 'auto';
    return array('result' => 'ok', 'detail' => $langs->transnoentitiesnoconv('KSEF_DbR_ImportTotalsFixed', price($before, 0, $langs, 1, -1, 2, $cur),
        price((float) ($foreign ? $inv->multicurrency_total_ttc : $inv->total_ttc), 0, $langs, 1, -1, 2, $cur)));
}

/**
 * @brief Check invoice locked
 * @param DoliDB $db
 * @param string $el Element
 * @param Facture|FactureFournisseur $inv
 * @return bool
 */
function ksefDbCheckImportLocked($db, $el, $inv)
{
    $P = MAIN_DB_PREFIX;
    $k = ksefInvoiceKind($el);
    $id = (int) $inv->id;
    $docType = $k['element'] === 'invoice_supplier' ? 'supplier_invoice' : 'customer_invoice';
    $paid = ksefDbCheckCount($db, "SELECT COUNT(*) AS n FROM " . $P . $k['pay_table'] . " WHERE " . $k['pay_fk'] . " = " . $id)
        + ksefDbCheckCount($db, "SELECT COUNT(*) AS n FROM " . $P . "societe_remise_except WHERE " . $k['col'] . " = " . $id)
        + ksefDbCheckCount($db, "SELECT COUNT(*) AS n FROM " . $P . "societe_remise_except WHERE " . $k['col_source'] . " = " . $id);
    if ($paid > 0) {
        return true;
    }
    if (ksefDbCheckTableExists($db, $P . 'accounting_bookkeeping')
        && ksefDbCheckCount($db, "SELECT COUNT(*) AS n FROM " . $P . "accounting_bookkeeping WHERE doc_type = '" . $docType . "' AND fk_doc = " . $id) > 0) {
        return true;
    }
    $replaced = ksefDbCheckCount($db, "SELECT COUNT(*) AS n FROM " . $P . $k['table'] . " WHERE fk_facture_source = " . $id . " AND type = 1 AND fk_statut > 0") > 0;
    return (!empty($inv->paye) || !empty($inv->paid)) && !$replaced;
}

/**
 * @brief Foreign PLN amounts
 * @param DoliDB $db
 * @param int $onlyId 0 for all
 * @return array Problems
 */
function ksefDbCheckForeignPln($db, $onlyId = 0)
{
    global $conf, $langs;
    dol_include_once('/ksef/lib/ksef.lib.php');
    dol_include_once('/ksef/class/ksef_nbp_currency_rate.class.php');
    $P = MAIN_DB_PREFIX;
    $out = array();
    $cur = $db->escape((string) $conf->currency);
    $sql = "SELECT f.rowid, f.ref, f.fk_soc, f.fk_statut, f.total_ht, f.total_tva, f.multicurrency_code, f.multicurrency_total_tva FROM " . $P . "facture AS f"
        . " WHERE f.fk_statut IN (1, 2) AND f.type IN (0, 1, 2, 3, 5) AND f.entity IN (" . getEntity('invoice') . ")"
        . " AND COALESCE(f.multicurrency_code, '') NOT IN ('', '" . $cur . "') AND f.multicurrency_tx > 0"
        . ($onlyId > 0 ? " AND f.rowid = " . (int) $onlyId : "")
        . (ksefDbCheckTableExists($db, $P . 'ksef_sales') ? " AND NOT EXISTS (SELECT 1 FROM " . $P . "ksef_sales AS s WHERE s.fk_facture = f.rowid)" : "")
        . " ORDER BY f.rowid";
    $res = $db->query($sql);
    $heads = array();
    while ($res && ($o = $db->fetch_object($res))) {
        $heads[(int) $o->rowid] = $o;
    }
    $nbp = new KsefNbpCurrencyRate($db);
    $countries = array();
    require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
    foreach ($heads as $id => $h) {
        $socId = (int) $h->fk_soc;
        if (!isset($countries[$socId])) {
            $soc = new Societe($db);
            $countries[$socId] = $soc->fetch($socId) > 0 ? ksefInferCountryCode($soc) : 'PL';
        }
        $d = ksefDerivePlnFromForeign($db, $id, array('dry_run' => true, 'check_sent' => true, 'country' => $countries[$socId], 'no_sales_check' => true));
        if ($d['result'] < 0) {
            dol_syslog('ksefDbCheckForeignPln invoice ' . $id . ': ' . $d['error'], LOG_WARNING);
            continue;
        }
        if (!$d['lines'] || ($d['skip'] !== '' && $d['skip'] !== 'sent')) {
            continue;
        }
        $inv = new Facture($db);
        $inv->id = $id;
        $inv->ref = (string) $h->ref;
        $inv->statut = $inv->status = (int) $h->fk_statut;
        $inv->total_ht = (float) $h->total_ht;
        $inv->total_tva = (float) $h->total_tva;
        $inv->multicurrency_code = (string) $h->multicurrency_code;
        $inv->multicurrency_total_tva = (float) $h->multicurrency_total_tva;
        $parts = array();
        if ($d['skip'] === 'sent') {
            $state = 'sent';
        } else {
            $parts = $nbp->rateChangeBlocked($inv);
            $state = ($parts === false || !empty($parts)) ? 'locked' : 'repairable';
        }
        $dht = $dtva = $dmtva = 0.0;
        foreach ($d['lines'] as $l) {
            $dht += $l['ht'] - $l['old']['ht'];
            $dtva += $l['tva'] - $l['old']['tva'];
            // currency VAT moves
            if (isset($l['mtva'])) {
                $dmtva += $l['mtva'] - $l['old']['mtva'];
            }
        }
        $dmtva = round($dmtva, 2);
        $pln = function ($v) use ($langs, $conf) {
            return price($v, 0, $langs, 1, -1, 2, $conf->currency);
        };
        $problem = $langs->transnoentitiesnoconv('KSEF_DbP_ForeignPln', price($d['kurs'], 0, $langs, 0, 2, -1), $inv->multicurrency_code,
            $pln((float) $inv->total_ht) . ' / ' . $pln((float) $inv->total_tva), $pln(round((float) $inv->total_ht + $dht, 2)) . ' / ' . $pln(round((float) $inv->total_tva + $dtva, 2)));
        if ($dmtva != 0) {
            $mc = function ($v) use ($langs, $inv) {
                return price($v, 0, $langs, 1, -1, 2, $inv->multicurrency_code);
            };
            $problem .= ' ' . $langs->transnoentitiesnoconv('KSEF_DbP_ForeignPlnMc', $inv->multicurrency_code, $mc((float) $inv->multicurrency_total_tva),
                $mc(round((float) $inv->multicurrency_total_tva + $dmtva, 2)));
        }
        $out[] = array('severity' => $state === 'repairable' ? 'warning' : 'info', 'code' => 'foreign_pln', 'object' => (string) $inv->ref, 'url' => ksefDbCheckInvoiceUrl($id, 'facture'),
            'problem' => $problem, 'fix' => '', 'repair' => $state === 'repairable' ? array('op' => 'foreign_pln', 'element' => 'facture', 'id' => $id) : null,
            'state' => $state, 'element' => 'facture', 'id' => $id, 'kurs' => (float) $d['kurs'], 'currency' => $inv->multicurrency_code,
            'locked_by' => $parts ? $nbp->rateChangeBookedParts($parts) : '',
            'pln' => array('ht' => (float) $inv->total_ht, 'tva' => (float) $inv->total_tva, 'new_ht' => round((float) $inv->total_ht + $dht, 2), 'new_tva' => round((float) $inv->total_tva + $dtva, 2),
                'mtva' => (float) $inv->multicurrency_total_tva, 'new_mtva' => round((float) $inv->multicurrency_total_tva + $dmtva, 2)));
    }
    return $out;
}

/**
 * @brief Repair foreign PLN
 * @param DoliDB $db
 * @param array $r Repair step
 * @return array
 */
function ksefDbCheckRepairForeignPln($db, $r)
{
    global $conf, $langs;
    $now = ksefDbCheckForeignPln($db, (int) $r['id']);
    if (!$now || $now[0]['state'] !== 'repairable') {
        return array('result' => 'skipped', 'detail' => $langs->transnoentitiesnoconv('KSEF_DbR_DepositChanged'));
    }
    $inv = new Facture($db);
    $inv->fetch((int) $r['id']);
    $before = (float) $inv->total_tva;
    $d = ksefDerivePlnFromForeign($db, (int) $r['id']);
    if ($d['result'] < 0) {
        return array('result' => 'failed', 'detail' => $d['error']);
    }
    $inv->fetch((int) $r['id']);
    return array('result' => 'ok', 'detail' => $langs->transnoentitiesnoconv('KSEF_DbR_ForeignPlnFixed', price($before, 0, $langs, 1, -1, 2, $conf->currency),
        price((float) $inv->total_tva, 0, $langs, 1, -1, 2, $conf->currency)));
}

/**
 * @brief Excess while owed
 * @param DoliDB $db
 * @param string $element '' for both
 * @param int $onlyId 0 for all
 * @return array Problems
 */
function ksefDbCheckExcessWhileOwed($db, $element = '', $onlyId = 0)
{
    global $conf, $langs;
    dol_include_once('/ksef/lib/ksef.lib.php');
    $out = array();
    if (!isModEnabled('multicurrency')) {
        return $out;
    }
    $P = MAIN_DB_PREFIX;
    $cur = $db->escape((string) $conf->currency);
    foreach (array('facture', 'invoice_supplier') as $el) {
        if ($element !== '' && $element !== $el) {
            continue;
        }
        $k = ksefInvoiceKind($el);
        $sql = "SELECT f.rowid, re.rowid AS credit, re.amount_ttc, re." . $k['col'] . " AS used_on FROM " . $P . $k['table'] . " AS f"
            . " INNER JOIN " . $P . "societe_remise_except AS re ON re." . $k['col_source'] . " = f.rowid AND re.description LIKE '" . $db->escape($k['excess']) . "%'"
            . " WHERE f.fk_statut IN (1, 2) AND f.type IN (0, 1" . ($el === 'facture' ? ", 5" : "") . ")"
            . " AND f.entity IN (" . getEntity($k['entity']) . ") AND COALESCE(f.multicurrency_code, '') NOT IN ('', '" . $cur . "')"
            . ($onlyId > 0 ? " AND f.rowid = " . (int) $onlyId : "")
            . " AND NOT EXISTS (SELECT 1 FROM " . $P . $k['table'] . " AS r WHERE r.fk_facture_source = f.rowid AND r.type = 1 AND r.fk_statut > 0) ORDER BY f.rowid, re.rowid";
        $res = $db->query($sql);
        $rows = array();
        while ($res && ($o = $db->fetch_object($res))) {
            $rows[(int) $o->rowid][] = $o;
        }
        foreach ($rows as $id => $credits) {
            $inv = new $k['class']($db);
            if ($inv->fetch($id) <= 0) {
                continue;
            }
            $remain = (float) price2num($inv->getRemainToPay(1), 'MT');
            if ($remain <= 0) {
                continue;
            }
            $amount = 0.0;
            $usedOn = array();
            foreach ($credits as $c) {
                $amount += (float) $c->amount_ttc;
                if (!empty($c->used_on)) {
                    $usedOn[(int) $c->used_on] = (int) $c->used_on;
                }
            }
            $object = (string) $inv->ref . ($el === 'invoice_supplier' && (string) $inv->ref_supplier !== '' ? ' (' . $inv->ref_supplier . ')' : '');
            $fix = $usedOn ? $langs->transnoentitiesnoconv('KSEF_DbF_ExcessWhileOwedUsed', implode(', ', array_column(ksefDbCheckInvoiceRefs($db, array_values($usedOn), $el), 'ref')))
                : $langs->transnoentitiesnoconv('KSEF_DbF_ExcessWhileOwed');
            $out[] = array('severity' => 'warning', 'code' => 'excess_while_owed', 'object' => $object, 'url' => ksefDbCheckInvoiceUrl($id, $el),
                'problem' => $langs->transnoentitiesnoconv('KSEF_DbP_ExcessWhileOwed', price($amount, 0, $langs, 1, -1, 2, $conf->currency), $inv->multicurrency_code,
                    price($remain, 0, $langs, 1, -1, 2, $inv->multicurrency_code)),
                'fix' => $fix, 'repair' => null, 'element' => $el, 'id' => $id);
        }
    }
    return $out;
}

/**
 * @brief 1.5.0 migration time
 * @return int 0 if unknown
 */
function ksefDbCheckUpgradeMoment()
{
    return getDolGlobalInt('KSEF_NBP_STALE_STRICT_SINCE');
}

/**
 * @brief Payment currency mismatches
 * @param DoliDB $db
 * @param string $element
 * @param int $onlyId 0 for all
 * @return array
 */
function ksefDbCheckPaymentColumn($db, $element, $onlyId = 0)
{
    global $conf;
    $out = array();
    if ($element !== 'facture' || !isModEnabled('multicurrency') || !isModEnabled('bank')) {
        return $out;
    }
    $k = ksefInvoiceKind($element);
    $P = MAIN_DB_PREFIX;
    $cur = $db->escape((string) $conf->currency);
    $ptable = 'paiement';
    $typed = "COALESCE(NULLIF(pf.multicurrency_code, ''), '" . $cur . "')";
    $acc = "COALESCE(NULLIF(ba.currency_code, ''), '" . $cur . "')";
    $sql = "SELECT f.rowid AS facid, p.rowid AS pid, p.ref, " . $typed . " AS typed, " . $acc . " AS acc, ba.label, b.amount"
        . " FROM " . $P . $k['pay_table'] . " AS pf INNER JOIN " . $P . $ptable . " AS p ON p.rowid = pf." . $k['pay_id']
        . " INNER JOIN " . $P . $k['table'] . " AS f ON f.rowid = pf." . $k['pay_fk']
        . " INNER JOIN " . $P . "bank AS b ON b.rowid = p.fk_bank INNER JOIN " . $P . "bank_account AS ba ON ba.rowid = b.fk_account"
        . " WHERE COALESCE(b.rappro, 0) = 0 AND f.entity IN (" . getEntity($k['entity']) . ") AND " . $typed . " <> " . $acc
        . " AND (" . $acc . " = '" . $cur . "' OR " . $acc . " = COALESCE(NULLIF(f.multicurrency_code, ''), '" . $cur . "'))"
        . ($onlyId > 0 ? " AND f.rowid = " . (int) $onlyId : "") . " ORDER BY f.rowid DESC, p.rowid";
    $res = $db->query($sql);
    while ($res && ($o = $db->fetch_object($res))) {
        $out[] = array('id' => (int) $o->facid, 'payment' => (int) $o->pid, 'ref' => (string) $o->ref, 'typed' => (string) $o->typed,
            'account_cur' => (string) $o->acc, 'account' => (string) $o->label, 'bank_amount' => (float) $o->amount);
    }
    return $out;
}

/**
 * @brief Paid while owed
 * @param DoliDB $db
 * @param string $element '' for both
 * @param int $onlyId 0 for all
 * @return array Problems
 */
function ksefDbCheckPaidWhileOwed($db, $element = '', $onlyId = 0)
{
    global $conf, $langs;
    dol_include_once('/ksef/lib/ksef.lib.php');
    $out = array();
    if (!isModEnabled('multicurrency')) {
        return $out;
    }
    $P = MAIN_DB_PREFIX;
    $cur = $db->escape((string) $conf->currency);
    $since = ksefDbCheckUpgradeMoment();
    foreach (array('facture', 'invoice_supplier') as $el) {
        if ($element !== '' && $element !== $el) {
            continue;
        }
        $k = ksefInvoiceKind($el);
        $sql = "SELECT f.rowid, f.date_closing FROM " . $P . $k['table'] . " AS f WHERE f.fk_statut = 2 AND f.paye = 1 AND COALESCE(f.close_code, '') = ''"
            . " AND f.type IN (0, 1, 3" . ($el === 'facture' ? ", 5" : "") . ")"
            . " AND f.entity IN (" . getEntity($k['entity']) . ") AND COALESCE(f.multicurrency_code, '') NOT IN ('', '" . $cur . "')"
            . ($onlyId > 0 ? " AND f.rowid = " . (int) $onlyId : "")
            . " AND NOT EXISTS (SELECT 1 FROM " . $P . "societe_remise_except AS re WHERE re." . $k['col_source'] . " = f.rowid AND re.description LIKE '" . $db->escape($k['excess']) . "%')"
            . " AND NOT EXISTS (SELECT 1 FROM " . $P . $k['table'] . " AS r WHERE r.fk_facture_source = f.rowid AND r.type = 1 AND r.fk_statut > 0)"
           
            . " AND COALESCE((SELECT SUM(pf.multicurrency_amount) FROM " . $P . $k['pay_table'] . " AS pf WHERE pf." . $k['pay_fk'] . " = f.rowid), 0) < f.multicurrency_total_ttc"
            . " ORDER BY f.rowid";
        $res = $db->query($sql);
        $ids = array();
        while ($res && ($o = $db->fetch_object($res))) {
            $ids[(int) $o->rowid] = $o->date_closing === null || $o->date_closing === '' ? null : (int) $db->jdate($o->date_closing);
        }
        foreach ($ids as $id => $closedAt) {
            $inv = new $k['class']($db);
            if ($inv->fetch($id) <= 0) {
                continue;
            }
            $remain = (float) price2num($inv->getRemainToPay(1), 'MT');
            if ($remain <= 0) {
                continue;
            }
            $before = $since > 0 && ($closedAt === null || $closedAt < $since);
            $object = (string) $inv->ref . ($el === 'invoice_supplier' && (string) $inv->ref_supplier !== '' ? ' (' . $inv->ref_supplier . ')' : '');
            $out[] = array('severity' => $before ? 'info' : 'warning', 'code' => 'paid_while_owed', 'object' => $object, 'url' => ksefDbCheckInvoiceUrl($id, $el),
                'problem' => $langs->transnoentitiesnoconv('KSEF_DbP_PaidWhileOwed', $inv->multicurrency_code, price($remain, 0, $langs, 1, -1, 2, $inv->multicurrency_code),
                    price((float) price2num($inv->getRemainToPay(0), 'MT'), 0, $langs, 1, -1, 2, $conf->currency)),
                'fix' => $before ? $langs->transnoentitiesnoconv('KSEF_DbF_PaidWhileOwedBefore', dol_print_date($since, 'dayhour')) : $langs->transnoentitiesnoconv('KSEF_DbF_PaidWhileOwed'),
                'repair' => null, 'element' => $el, 'id' => $id);
        }
    }
    return $out;
}

/**
 * @brief Expected import rate
 * @param KsefIncoming $rec
 * @return array
 */
function ksefDbCheckImportRateExpected($rec)
{
    $out = array('rate' => 0.0, 'line' => false, 'vat' => array());
    if (empty($rec->fa3_xml)) {
        return $out;
    }
    $parser = new FA3Parser($rec->db);
    $parsed = $parser->parse($rec->fa3_xml);
    if (!$parsed) {
        return $out;
    }
    $out['line'] = ksefParsedKurs($parsed) > 0;
    $out['rate'] = (float) $rec->getExchangeRate();
    foreach ((array) ($parsed['vat_summary'] ?? array()) as $r) {
        if (isset($r['vat_pln']) && $r['vat_pln'] !== null && (float) $r['vat'] != 0) {
            $out['vat'][] = array((float) $r['vat'], (float) $r['vat_pln']);
        }
    }
    return $out;
}

/**
 * @brief Wrong import rate
 * @param array $exp Expected rate
 * @param float $tx multicurrency_tx
 * @return bool
 */
function ksefDbCheckImportRateWrong($exp, $tx)
{
    if ($tx <= 0) {
        return true;
    }
    if ($exp['line']) {
        return abs(1 / $tx - $exp['rate']) > $exp['rate'] * 0.00001;
    }
    // 6 dp tolerance
    if (abs(1 / $tx - $exp['rate']) <= 0.000001) {
        return false;
    }
    $tol = 0.01 + 0.005 * count($exp['vat']);
    foreach ($exp['vat'] as $v) {
        if (abs($v[0] / $tx - $v[1]) > $tol) {
            return true;
        }
    }
    return false;
}

/**
 * @brief Check import rates
 * @param DoliDB $db
 * @param int $onlyId 0 for all
 * @return array Problems
 */
function ksefDbCheckImportRates($db, $onlyId = 0)
{
    global $conf, $langs;
    dol_include_once('/ksef/lib/ksef.lib.php');
    dol_include_once('/ksef/class/ksef_incoming.class.php');
    $P = MAIN_DB_PREFIX;
    $out = array();
    if (!ksefDbCheckTableExists($db, $P . 'ksef_incoming')) {
        return $out;
    }
    $k = ksefInvoiceKind('invoice_supplier');
    $root = 0;
    if ($onlyId > 0) {
        $inv = new FactureFournisseur($db);
        if ($inv->fetch((int) $onlyId) <= 0) {
            return $out;
        }
        $chain = ksefBuildCorrectionChain($inv, $db);
        $root = $chain ? (int) $chain[0]->id : (int) $onlyId;
    }
    $cur = $db->escape((string) $conf->currency);
    $sql = "SELECT r.rowid AS rid, f.rowid AS fid FROM " . $P . "ksef_incoming AS r INNER JOIN " . $P . "facture_fourn AS f ON f.rowid = r.fk_facture_fourn"
        . " WHERE r.import_status = 'IMPORTED' AND r.invoice_type NOT LIKE 'KOR%' AND f.type IN (0, 3) AND f.fk_statut IN (0, 1, 2)"
        . " AND f.entity IN (" . getEntity($k['entity']) . ") AND COALESCE(f.multicurrency_code, '') NOT IN ('', '" . $cur . "')"
        . ($root > 0 ? " AND f.rowid = " . $root : "") . " ORDER BY f.rowid";
    $res = $db->query($sql);
    $rows = array();
    while ($res && ($o = $db->fetch_object($res))) {
        $rows[] = $o;
    }
    foreach ($rows as $o) {
        $rec = new KsefIncoming($db);
        $inv = new FactureFournisseur($db);
        if ($rec->fetch((int) $o->rid) <= 0 || $inv->fetch((int) $o->fid) <= 0) {
            continue;
        }
        $exp = ksefDbCheckImportRateExpected($rec);
        if ($exp['rate'] <= 0) {
            continue;
        }
        $wrong = array();
        $chainIds = array();
        foreach (ksefBuildCorrectionChain($inv, $db) as $c) {
            $chainIds[] = (int) $c->id;
            if ((int) $c->type !== FactureFournisseur::TYPE_CREDIT_NOTE && ksefDbCheckImportRateWrong($exp, (float) $c->multicurrency_tx)) {
                $wrong[(int) $c->id] = $c;
            }
        }
        if (!$wrong) {
            continue;
        }
        $locked = ksefDbCheckCount($db, "SELECT COUNT(*) AS n FROM " . $P . "facture_fourn WHERE type = 2 AND fk_facture_source IN (" . implode(',', $chainIds) . ")") > 0;
        foreach ($wrong as $c) {
            $locked = $locked || ksefDbCheckImportLocked($db, 'invoice_supplier', $c);
        }
        $state = $locked ? 'locked' : 'repairable';
        $tx = (float) $inv->multicurrency_tx;
        $ksefVat = 0.0;
        foreach ($exp['vat'] as $v) {
            $ksefVat += $v[1];
        }
        if (!$exp['vat']) {
            $ksefVat = round((float) $inv->multicurrency_total_tva * $exp['rate'], 2);
        }
        $rateTxt = function ($v) use ($langs) {
            return price($v, 0, $langs, 0, -1, 4);
        };
        $problem = $langs->transnoentitiesnoconv('KSEF_DbP_ImportRate', $rateTxt($tx > 0 ? 1 / $tx : 0), $rateTxt($exp['rate']), $inv->multicurrency_code,
            price((float) $inv->total_tva, 0, $langs, 1, -1, 2, $conf->currency), price($ksefVat, 0, $langs, 1, -1, 2, $conf->currency));
        if ($tx > 0 && abs($tx - $exp['rate']) <= $exp['rate'] * 0.0001) {
            $problem .= ' ' . $langs->transnoentitiesnoconv('KSEF_DbP_ImportRateInverted');
        }
        $others = array();
        foreach ($wrong as $id => $c) {
            if ($id !== (int) $inv->id) {
                $others[] = $c->ref;
            }
        }
        if ($others) {
            $problem .= ' ' . $langs->transnoentitiesnoconv('KSEF_DbP_ImportRateChain', implode(', ', $others));
        }
        $id = (int) $inv->id;
        $ids = array_map('intval', array_keys($wrong));
        $object = (string) $inv->ref . ((string) $inv->ref_supplier !== '' ? ' (' . $inv->ref_supplier . ')' : '');
        $out[] = array('severity' => 'warning', 'code' => 'import_rate', 'object' => $object, 'url' => ksefDbCheckInvoiceUrl($id, 'invoice_supplier'),
            'problem' => $problem, 'fix' => '',
            'repair' => $locked ? null : array('op' => 'import_rate', 'element' => 'invoice_supplier', 'id' => $id, 'ids' => $ids),
            'state' => $state, 'element' => 'invoice_supplier', 'id' => $id, 'ids' => $ids, 'rate' => $exp['rate'],
            'rate_now' => $tx > 0 ? 1 / $tx : 0, 'currency' => $inv->multicurrency_code, 'vat_now' => (float) $inv->total_tva, 'vat_ksef' => $ksefVat, 'others' => $others);
    }
    return $out;
}

/**
 * @brief Rebase invoice rate
 * @param DoliDB $db
 * @param FactureFournisseur $inv
 * @param float $rate PLN per unit
 * @return string '' or error
 */
function ksefDbCheckImportRateRebase($db, $inv, $rate)
{
    $P = MAIN_DB_PREFIX;
    $num = function ($v, $mode) {
        return (string) price2num((float) $v, $mode);
    };
    $oldTx = (float) $inv->multicurrency_tx;
    $res = $db->query("SELECT rowid, tva_tx, pu_ht, multicurrency_subprice, multicurrency_total_ht, multicurrency_total_tva FROM " . $P . "facture_fourn_det WHERE fk_facture_fourn = " . (int) $inv->id);
    if (!$res) {
        return $db->lasterror();
    }
    $lines = array();
    while ($o = $db->fetch_object($res)) {
        $lines[] = $o;
    }
    $head = array('ht' => 0.0, 'tva' => 0.0);
    foreach ($lines as $o) {
        $ht = (float) price2num((float) $o->multicurrency_total_ht * $rate, 'MT');
        $tva = (float) price2num((float) $o->multicurrency_total_tva * $rate, 'MT');
        $pu = ((float) $o->multicurrency_subprice != 0 || $oldTx <= 0) ? (float) $o->multicurrency_subprice * $rate : (float) $o->pu_ht * $rate * $oldTx;
        $pu = (float) price2num($pu, 'MU');
        $sql = "UPDATE " . $P . "facture_fourn_det SET pu_ht = " . $num($pu, 'MU') . ", pu_ttc = " . $num($pu * (1 + (float) $o->tva_tx / 100), 'MU')
            . ", total_ht = " . $num($ht, 'MT') . ", tva = " . $num($tva, 'MT') . ", total_ttc = " . $num($ht + $tva, 'MT') . " WHERE rowid = " . (int) $o->rowid;
        if (!$db->query($sql)) {
            return $db->lasterror();
        }
        $head['ht'] += $ht;
        $head['tva'] += $tva;
    }
    $sql = "UPDATE " . $P . "facture_fourn SET multicurrency_tx = " . sprintf('%.10F', 1 / $rate) . ", total_ht = " . $num($head['ht'], 'MT') . ", total_tva = " . $num($head['tva'], 'MT')
        . ", tva = " . $num($head['tva'], 'MT') . ", total_ttc = " . $num($head['ht'] + $head['tva'], 'MT') . " WHERE rowid = " . (int) $inv->id;
    return $db->query($sql) ? '' : $db->lasterror();
}

/**
 * @brief Repair import rate
 * @param DoliDB $db
 * @param array $r Repair step
 * @return array
 */
function ksefDbCheckRepairImportRate($db, $r)
{
    global $conf, $langs;
    $p = null;
    foreach (ksefDbCheckImportRates($db, (int) $r['id']) as $x) {
        if ((int) $x['id'] === (int) $r['id']) {
            $p = $x;
        }
    }
    $want = array_map('intval', (array) ($r['ids'] ?? array()));
    sort($want);
    $have = $p ? $p['ids'] : array();
    sort($have);
    if (!$p || $p['state'] !== 'repairable' || $want !== $have) {
        return array('result' => 'skipped', 'detail' => $langs->transnoentitiesnoconv('KSEF_DbR_DepositChanged'));
    }
    $rate = (float) $p['rate'];
    $root = new FactureFournisseur($db);
    $root->fetch((int) $p['id']);
    $before = array((float) $root->multicurrency_tx > 0 ? 1 / (float) $root->multicurrency_tx : 0, (float) $root->total_tva);
    $db->begin();
    foreach ($p['ids'] as $id) {
        $inv = new FactureFournisseur($db);
        $err = $inv->fetch((int) $id) > 0 ? ksefDbCheckImportRateRebase($db, $inv, $rate) : 'fetch ' . (int) $id;
        if ($err !== '') {
            $db->rollback();
            return array('result' => 'failed', 'detail' => $err);
        }
    }
    foreach ($p['ids'] as $id) {
        $inv = new FactureFournisseur($db);
        $rec = new KsefIncoming($db);
        $o = $db->getRow("SELECT rowid FROM " . MAIN_DB_PREFIX . "ksef_incoming WHERE fk_facture_fourn = " . (int) $id . " AND import_status = 'IMPORTED' ORDER BY rowid DESC");
        if (!$o || $rec->fetch((int) $o->rowid) <= 0 || $inv->fetch((int) $id) <= 0) {
            continue;
        }
        $target = ksefImportTargetTotals($rec, $inv, true);
        if (!$target) {
            continue;
        }
        $s = ksefSettleImportedTotals($db, $inv, $target, true, $target['korekta'] ? 'none' : 'xml', false, ksefImportRoundingSlack($rec, $target, true, true));
        if ($s['error'] !== '') {
            $db->rollback();
            return array('result' => 'failed', 'detail' => $s['error']);
        }
    }
    $db->commit();
    $root->fetch($root->id);
    return array('result' => 'ok', 'detail' => $langs->transnoentitiesnoconv('KSEF_DbR_ImportRateFixed', price($before[0], 0, $langs, 0, -1, 4), price($rate, 0, $langs, 0, -1, 4),
        price($before[1], 0, $langs, 1, -1, 2, $conf->currency), price((float) $root->total_tva, 0, $langs, 1, -1, 2, $conf->currency)));
}

/**
 * @brief Check anything repairable
 * @param array $problems
 * @return bool
 */
function ksefDbCheckRepairable($problems)
{
    foreach ($problems as $p) {
        if (!empty($p['repair'])) {
            return true;
        }
    }
    return false;
}

/**
 * @brief Create module table
 * @param DoliDB $db
 * @param string $table Without prefix
 * @return array
 */
function ksefDbCheckCreateTable($db, $table)
{
    $schema = ksefDbCheckSchema();
    if (!isset($schema[$table])) {
        return array('ok' => false, 'error' => 'unknown table', 'keys_failed' => array());
    }
    $sql = ksefDbCheckPrefix(preg_replace('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?/i', 'CREATE TABLE IF NOT EXISTS ', $schema[$table]['create']));
    if (!$db->query($sql)) {
        return array('ok' => false, 'error' => $db->lasterror(), 'keys_failed' => array());
    }
    $failed = array();
    foreach ($schema[$table]['keys'] as $name => $key) {
        if (!$db->query(ksefDbCheckPrefix($key['sql']))) {
            $failed[] = $name . ': ' . $db->lasterror();
        }
    }
    return array('ok' => true, 'error' => '', 'keys_failed' => $failed);
}

/**
 * @brief Repair findings
 * @param DoliDB $db
 * @param modKSEF $mod
 * @param User $user
 * @param int $tier 0 for both
 * @param string $only Finding key
 * @return array Results
 */
function ksefDbCheckRepair($db, $mod, $user, $tier = 0, $only = '')
{
    global $langs;
    $langs->loadLangs(array('ksef@ksef'));
    $done = array();
    $schema = ksefDbCheckSchema();
    $journal = array('rows' => array(), 'keys' => array());
    $backupFile = '';
    $problems = array();
    foreach (ksefDbCheckRun($db, $mod) as $p) {
        if ((!$tier || ksefDbCheckTier($p) == $tier) && ($only === '' || ksefDbCheckFindingKey($p) === $only)) {
            $problems[] = $p;
        }
    }
    $cronDone = false;
    $migrate = false;
    $who = is_object($user) ? $user->login : '';

    if (ksefDbCheckRepairable($problems)) {
        $b = ksefDbBackupBefore($db, $user, 'repair', '', array(), ksefDbCheckRepairBackupSpec($db, $user, $problems));
        if (!$b['ok'] || $b['file'] !== '') {
            $done[] = array('object' => $b['ok'] ? $b['file'] : 'ksef_backup', 'action' => $langs->trans('KSEF_DbBkAction'), 'result' => $b['ok'] ? 'ok' : 'failed',
                'detail' => $b['ok'] ? $langs->transnoentitiesnoconv('KSEF_DbBkRowsTotal', $b['rows']) : $b['error']);
        }
        if (!$b['ok']) {
            dol_syslog('ksefDbCheckRepair by ' . $who . ': refused, ' . $b['error'], LOG_ERR);
            return $done;
        }
        $backupFile = $b['file'];
    }

    foreach ($problems as $p) {
        $r = $p['repair'];
        if (empty($r)) {
            continue;
        }
        $res = array('object' => $p['object'], 'action' => '', 'result' => 'ok', 'detail' => '');
        if ($r['op'] == 'create_table') {
            $res['action'] = $langs->trans('KSEF_DbA_CreateTable');
            $c = ksefDbCheckCreateTable($db, $r['table']);
            if (!$c['ok']) {
                $res['result'] = 'failed';
                $res['detail'] = $c['error'];
            } elseif ($c['keys_failed']) {
                $res['detail'] = implode('; ', $c['keys_failed']);
            }
        } elseif ($r['op'] == 'add_column' || $r['op'] == 'modify_column') {
            $cdef = $schema[$r['table']]['columns'][$r['column']];
            $full = ksefDbCheckTableName($r['table']);
            if ($r['op'] == 'add_column') {
                $res['action'] = $langs->trans('KSEF_DbA_AddColumn');
                $sql = "ALTER TABLE " . $full . " ADD COLUMN " . $r['column'] . " " . $cdef['def'] . ($r['after'] !== '' ? " AFTER " . $r['after'] : " FIRST");
            } else {
                $res['action'] = $langs->trans('KSEF_DbA_ModifyColumn');
                $sql = "ALTER TABLE " . $full . " MODIFY " . $r['column'] . " " . $cdef['def'];
            }
            if (!$db->query($sql)) {
                $res['result'] = 'failed';
                $res['detail'] = $db->lasterror();
            }
        } elseif ($r['op'] == 'add_key') {
            $key = $schema[$r['table']]['keys'][$r['key']];
            $full = ksefDbCheckTableName($r['table']);
            $res['action'] = $langs->trans($key['type'] == 'fk' ? 'KSEF_DbA_AddFk' : 'KSEF_DbA_AddIndex');
            if ($key['type'] == 'unique') {
                $dup = ksefDbCheckDuplicates($db, $full, $key['columns']);
                if ($dup['values'] > 0) {
                    $res['result'] = 'skipped';
                    $res['detail'] = $langs->trans('KSEF_DbP_UniqueBlocked', $dup['values'], $dup['rows']);
                }
            } elseif ($key['type'] == 'fk') {
                $orphans = ksefDbCheckOrphans($db, $full, $key);
                if ($orphans > 0) {
                    $res['result'] = 'skipped';
                    $res['detail'] = $langs->trans('KSEF_DbP_FkBlocked', $orphans);
                }
            }
            if ($res['result'] == 'ok' && !$db->query(ksefDbCheckPrefix($key['sql']))) {
                $res['result'] = 'failed';
                $res['detail'] = $db->lasterror();
            }
        } elseif ($r['op'] == 'extrafield') {
            $res['action'] = $langs->trans('KSEF_DbA_Extrafield');
            $fdef = null;
            foreach ($mod->getKsefExtraFieldDefs() as $group) {
                if (in_array($r['element'], $group['elements']) && isset($group['defs'][$r['name']])) {
                    $fdef = $group['defs'][$r['name']];
                }
            }
            include_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
            $ef = new ExtraFields($db);
            $ret = $mod->addDeclaredExtraField($ef, $r['name'], $fdef, $r['element']);
            if ($ret < 0) {
                $cols = ksefDbCheckLiveColumns($db, MAIN_DB_PREFIX . $r['element'] . '_extrafields');
                $resql = $db->query("SELECT COUNT(*) AS n FROM " . MAIN_DB_PREFIX . "extrafields WHERE name = '" . $db->escape($r['name']) . "' AND elementtype = '" . $db->escape($r['element']) . "'");
                $declared = ($resql && ($o = $db->fetch_object($resql)) && (int) $o->n > 0);
                if (!isset($cols[$r['name']]) || !$declared) {
                    $res['result'] = 'failed';
                    $res['detail'] = $ef->error;
                }
            }
        } elseif ($r['op'] == 'cron') {
            if ($cronDone) {
                continue;
            }
            $cronDone = true;
            $jobs = array();
            foreach ($problems as $q) {
                if ($q['code'] == 'cron_missing') {
                    $jobs[] = ksefDbCheckObjectLabel($q['object']);
                }
            }
            $res['object'] = implode(', ', $jobs);
            $res['action'] = $langs->trans('KSEF_DbA_Cron');
            // else core duplicates
            ksefCronRelabel($db);
            if ($mod->insert_cronjobs() != 0) {
                $res['result'] = 'failed';
                $res['detail'] = $mod->error;
            }
        } elseif ($r['op'] == 'cron_fix') {
            $res['action'] = $langs->trans('KSEF_DbA_CronFix');
            $job = $r['job'];
            if (!$db->query("UPDATE " . MAIN_DB_PREFIX . "cronjob SET classesname = '" . $db->escape($job['class']) . "', objectname = '" . $db->escape($job['objectname']) . "',"
                . " methodename = '" . $db->escape($job['method']) . "' WHERE rowid = " . (int) $r['rowid'])) {
                $res['result'] = 'failed';
                $res['detail'] = $db->lasterror();
            }
        } elseif ($r['op'] == 'table_charset') {
            $cs = ksefDbHealthCharset($db);
            $res['action'] = (($cs[$r['table']]['kind'] ?? 'charset') == 'charset') ? $langs->trans('KSEF_DbA_TableCharset') : $langs->trans('KSEF_DbA_TableCollation', $cs[$r['table']]['target']);
            if (!isset($cs[$r['table']])) {
                $res['result'] = 'skipped';
            } elseif (!$db->query("ALTER TABLE " . $db->sanitize($r['table']) . " CONVERT TO CHARACTER SET " . $db->sanitize($cs[$r['table']]['cs']) . " COLLATE " . $db->sanitize($cs[$r['table']]['target']))) {
                $res['result'] = 'failed';
                $res['detail'] = $db->lasterror();
            } else {
                $res['detail'] = $cs[$r['table']]['sql'];
            }
        } elseif (in_array($r['op'], array('fk_orphans', 'dedupe', 'status_reset'), true)) {
            $actions = array('fk_orphans' => 'KSEF_DbA_FkOrphans', 'dedupe' => 'KSEF_DbA_Dedupe', 'status_reset' => 'KSEF_DbA_StatusReset');
            $res['action'] = $langs->transnoentitiesnoconv($actions[$r['op']]);
            $res = array_merge($res, ksefDbCheckApplyRecordChanges($db, $p, $journal));
        } elseif ($r['op'] == 'migrate') {
            // run last
            $migrate = true;
            continue;
        } else {
            continue;
        }
        dol_syslog('ksefDbCheckRepair by ' . $who . ': ' . $res['action'] . ' ' . $res['object'] . ' ' . $res['result'] . ($res['detail'] !== '' ? ' (' . $res['detail'] . ')' : ''),
            $res['result'] == 'failed' ? LOG_ERR : LOG_WARNING);
        $done[] = $res;
    }

    if ($migrate) {
        $done[] = ksefDbCheckApplyUpdate($mod, $user, false);
    }
    if ($journal['rows']) {
        ksefDbCheckUndoSave($db, $journal, $backupFile, $user);
    }
    return $done;
}

/**
 * @brief Apply pending migrations
 * @param modKSEF $mod
 * @param User $user
 * @param bool $backup Backup first
 * @return array
 */
function ksefDbCheckApplyUpdate($mod, $user, $backup = true)
{
    global $langs;
    $langs->loadLangs(array('ksef@ksef'));
    $who = is_object($user) ? $user->login : '';
    $res = array('object' => 'KSEF_MIGRATION_VERSION', 'action' => $langs->trans('KSEF_DbA_Migrate'), 'result' => 'ok', 'detail' => '', 'backup' => '');
    if ($backup) {
        $b = ksefDbBackupBefore($mod->db, $user, 'update');
        if (!$b['ok']) {
            $res['result'] = 'failed';
            $res['detail'] = $b['error'];
            dol_syslog('ksefDbCheckRepair by ' . $who . ': apply update refused, ' . $b['error'], LOG_ERR);
            return $res;
        }
        $res['backup'] = $b['file'];
    }
    // insert_menus refuses existing
    $mod->delete_menus();
    $mod->delete_tabs();
    $mod->delete_module_parts();
    if ($mod->init() <= 0) {
        $res['result'] = 'failed';
        $initError = $mod->error;
        $mod->delete_menus();
        $mod->delete_tabs();
        $mod->delete_module_parts();
        $restoreErr = $mod->insert_module_parts() + $mod->insert_tabs() + $mod->insert_menus();
        $res['detail'] = $langs->transnoentitiesnoconv($restoreErr ? 'KSEF_DbA_MigrateRestoreFailed' : 'KSEF_DbA_MigrateRestored', $initError, $restoreErr ? $mod->error : '');
        dol_syslog('ksefDbCheckRepair by ' . $who . ': init() failed (' . $initError . '), module parts / tabs / menus restored'
            . ($restoreErr ? ' with ' . $restoreErr . ' error(s): ' . $mod->error : ''), $restoreErr ? LOG_ERR : LOG_WARNING);
    } else {
        $res['detail'] = getDolGlobalString('KSEF_MIGRATION_VERSION');
    }
    dol_syslog('ksefDbCheckRepair by ' . $who . ': ' . $res['action'] . ' ' . $res['result'] . ' (' . $res['detail'] . ')', $res['result'] == 'failed' ? LOG_ERR : LOG_WARNING);
    return $res;
}

/**
 * @brief Download table dump
 * @param DoliDB $db
 * @param string $table Without prefix
 * @param string $format csv|sql
 * @return bool
 */
function ksefDbCheckDump($db, $table, $format)
{
    $schema = ksefDbCheckSchema();
    $full = ksefDbCheckTableName($table);
    if (!isset($schema[$table]) || !ksefDbCheckTableExists($db, $full)) {
        return false;
    }
    $cols = array_keys(ksefDbCheckLiveColumns($db, $full));
    $hasRowid = in_array('rowid', $cols);
    $filename = $full . '-' . dol_print_date(dol_now(), '%Y%m%d-%H%M%S') . '.' . ($format == 'sql' ? 'sql' : 'csv');
    header('Content-Type: ' . ($format == 'sql' ? 'application/sql' : 'text/csv') . '; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    if ($format == 'sql') {
        fwrite($out, "-- KSeF module: copy of " . $full . " (" . dol_print_date(dol_now(), 'dayhourrfc') . ")\n");
        $resql = $db->query("SHOW CREATE TABLE " . $full);
        if ($resql && ($row = $db->fetch_array($resql))) {
            fwrite($out, "-- Table definition when copied:\n-- " . str_replace("\n", "\n-- ", (string) $row[1]) . "\n\n");
        }
    } else {
        fputcsv($out, $cols, ',', '"', '\\');
    }
    $last = 0;
    $offset = 0;
    $chunk = 200;
    while (true) {
        $sql = "SELECT * FROM " . $full . ($hasRowid ? " WHERE rowid > " . (int) $last . " ORDER BY rowid" : "") . " LIMIT " . $chunk . ($hasRowid ? "" : " OFFSET " . $offset);
        $resql = $db->query($sql);
        if (!$resql) {
            break;
        }
        $n = 0;
        while ($row = $db->fetch_array($resql)) {
            $n++;
            $vals = array();
            foreach ($cols as $c) {
                $vals[] = array_key_exists($c, $row) ? $row[$c] : null;
            }
            if ($format == 'sql') {
                fwrite($out, "INSERT INTO " . $full . " (" . implode(', ', $cols) . ") VALUES (" . implode(', ', array_map(function ($v) use ($db) {
                    return $v === null ? 'NULL' : "'" . $db->escape($v) . "'";
                }, $vals)) . ");\n");
            } else {
                fputcsv($out, array_map('ksefDbCheckCsvCell', $vals), ',', '"', '\\');
            }
            if ($hasRowid) {
                $last = (int) $row['rowid'];
            }
        }
        $db->free($resql);
        if ($n < $chunk) {
            break;
        }
        $offset += $chunk;
    }
    fclose($out);
    return true;
}

/**
 * @brief Escape CSV formula
 * @param mixed $v
 * @return mixed
 */
function ksefDbCheckCsvCell($v)
{
    if ($v === null || $v === '') {
        return $v;
    }
    $v = (string) $v;
    if (preg_match('/^[-+]?\d+(\.\d+)?$/', $v)) {
        return $v;
    }
    return strpbrk($v[0], "=+-@\t\r") !== false ? "'" . $v : $v;
}

/**
 * @brief Reset module table
 * @param DoliDB $db
 * @param string $table Without prefix
 * @param User $user
 * @return array
 */
function ksefDbCheckResetTable($db, $table, $user)
{
    $schema = ksefDbCheckSchema();
    if (!isset($schema[$table])) {
        return array('ok' => false, 'rows' => 0, 'error' => 'unknown table');
    }
    $full = ksefDbCheckTableName($table);
    $b = ksefDbBackupBefore($db, $user, 'reset', $table, array($table));
    if (!$b['ok']) {
        dol_syslog('ksefDbCheckResetTable by ' . (is_object($user) ? $user->login : '') . ': ' . $full . ' refused, ' . $b['error'], LOG_ERR);
        return array('ok' => false, 'rows' => 0, 'error' => $b['error'], 'backup' => '');
    }
    $stats = ksefDbCheckTableStats($db, $table);
    $rows = $stats[$table]['rows'];
    dol_syslog('ksefDbCheckResetTable by ' . (is_object($user) ? $user->login : '') . ': dropping ' . $full . ' with ' . $rows . ' rows (' . $stats[$table]['prod'] . ' PRODUCTION)', LOG_WARNING);
    if ($stats[$table]['exists'] && !$db->query("DROP TABLE " . $full)) {
        return array('ok' => false, 'rows' => 0, 'error' => $db->lasterror(), 'backup' => $b['file']);
    }
    $c = ksefDbCheckCreateTable($db, $table);
    if (!$c['ok']) {
        dol_syslog('ksefDbCheckResetTable: create ' . $full . ' failed: ' . $c['error'], LOG_ERR);
        return array('ok' => false, 'rows' => $rows, 'error' => $c['error'], 'backup' => $b['file']);
    }
    if ($c['keys_failed']) {
        dol_syslog('ksefDbCheckResetTable: keys of ' . $full . ' failed: ' . implode('; ', $c['keys_failed']), LOG_ERR);
    }
    dol_syslog('ksefDbCheckResetTable: ' . $full . ' recreated', LOG_WARNING);
    if ($table === 'ksef_session_invoice') {
        dol_include_once('/ksef/class/ksef_session_cache.class.php');
        KsefSessionCache::resetState($db);
    }
    return array('ok' => true, 'rows' => $rows, 'error' => implode('; ', $c['keys_failed']), 'backup' => $b['file']);
}

/**
 * @brief Reset confirm text
 * @param string $table Without prefix
 * @param array $st Table stats
 * @return string HTML
 */
function ksefDbCheckResetQuestion($table, $st)
{
    global $langs;
    $html = '';
    if ($st['prod'] > 0) {
        $html .= '<div class="warning"><strong>' . $langs->trans('KSEF_DbResetProdTitle') . '</strong><br>'
            . $langs->trans($table == 'ksef_submissions' ? 'KSEF_DbResetProdSubmissions' : 'KSEF_DbResetProdRefetch', $st['prod']) . '</div>';
    }
    $html .= $langs->trans('KSEF_DbResetConfirm', ksefDbCheckTableName($table), $st['rows']);
    if ($st['other_entities'] > 0) {
        $html .= ' ' . $langs->trans('KSEF_DbResetOtherEntities', $st['other_entities']);
        if (!empty($st['entities'])) {
            $names = ksefDbCheckEntityNames(array_keys($st['entities']));
            $parts = array();
            foreach ($st['entities'] as $e => $n) {
                $parts[] = dol_escape_htmltag($names[$e]) . ': ' . (int) $n;
            }
            $html .= ' ' . $langs->trans('KSEF_DbResetOtherEntitiesList', implode(', ', $parts));
        }
    }
    return $html;
}

/**
 * @brief Entity names
 * @param int[] $ids
 * @return array
 */
function ksefDbCheckEntityNames($ids)
{
    global $db;
    $out = array();
    foreach ($ids as $id) {
        $out[(int) $id] = '#' . (int) $id;
    }
    if ($out && ksefDbCheckTableExists($db, MAIN_DB_PREFIX . 'entity')) {
        $resql = $db->query("SELECT rowid, label FROM " . MAIN_DB_PREFIX . "entity WHERE rowid IN (" . implode(',', array_keys($out)) . ")");
        while ($resql && ($obj = $db->fetch_object($resql))) {
            if ((string) $obj->label !== '') {
                $out[(int) $obj->rowid] = $obj->label . ' (#' . (int) $obj->rowid . ')';
            }
        }
    }
    return $out;
}

/**
 * @brief Table change rights
 * @param User $user
 * @return bool
 */
function ksefDbCheckMayChangeTables($user)
{
    if (!is_object($user) || empty($user->admin)) {
        return false;
    }
    return !isModEnabled('multicompany') || empty($user->entity);
}

/**
 * @brief Group state
 * @param array $list Problems
 * @param array|null $st Table stats
 * @return string
 */
function ksefDbCheckGroupState($list, $st)
{
    $state = 'ok';
    if ($st && isset($st['health']) && in_array($st['health']['state'], array('missing', 'corrupt'), true)) {
        $state = $st['health']['state'] == 'missing' ? 'missing' : 'damaged';
    }
    foreach ($list as $p) {
        if ($p['severity'] == 'info') {
            continue;
        }
        if (!ksefDbCheckTier($p) && in_array($p['code'], array('column_missing', 'column_type', 'index_differs'), true)) {
            $state = $state == 'missing' ? $state : 'damaged';
        } elseif ($state == 'ok') {
            $state = 'fix';
        }
    }
    return $state;
}

/**
 * @brief Rebuildable tables
 * @param array $problems
 * @param array $stats
 * @return string[]
 */
function ksefDbCheckRebuildable($problems, $stats)
{
    $groups = array();
    foreach ((array) $problems as $p) {
        $groups[ksefDbCheckGroup($p)][] = $p;
    }
    $out = array();
    foreach ((array) $stats as $table => $st) {
        $list = isset($groups[$table]) ? $groups[$table] : array();
        if (!$st['exists'] || ksefDbCheckGroupState($list, $st) != 'damaged') {
            continue;
        }
        foreach ($list as $p) {
            if (ksefDbCheckTier($p)) {
                continue 2;
            }
        }
        $out[] = $table;
    }
    return $out;
}

/**
 * @brief Affected rows text
 * @param DoliDB $db
 * @param array $p Problem
 * @return string HTML
 */
function ksefDbCheckAffectedText($db, $p)
{
    global $langs;
    if (empty($p['rows'])) {
        return '';
    }
    $items = ksefDbCheckAffectedRows($db, $p['rows'], 10);
    if (!$items) {
        return '';
    }
    $more = (isset($p['n']) ? (int) $p['n'] : 0) - count($items);
    return '<br><span class="ksef-dbaffected">' . implode(', ', $items) . ($more > 0 ? ' ' . $langs->trans('KSEF_DbAffectedMore', $more) : '') . '</span>';
}

/**
 * @brief Render check section
 * @param array|null $problems
 * @param array|null $repairDone
 * @param array|null $stats
 * @param string $rebuildTable Open rebuild box
 * @param string $restoreFile Open restore box
 * @param array|null $health
 * @return string HTML
 */
function ksefDbCheckRenderSection($problems, $repairDone, $stats, $rebuildTable = '', $restoreFile = '', $health = null)
{
    global $langs, $db, $user;
    $self = dol_buildpath('/ksef/admin/about.php', 1);
    $mayChange = ksefDbCheckMayChangeTables($user);
    $checked = is_array($problems);
    $probe = isset($health['probe']) ? $health['probe'] : null;
    $probeFailed = $probe && !$probe['ok'] && $probe['step'] !== 'open_transaction';
    $lost = isset($health['lost']) ? $health['lost'] : array('count' => 0, 'refs' => array());
    $undo = isset($health['undo']) ? $health['undo'] : array('state' => 'none');
    $recordCodes = array('unique_blocked', 'fk_blocked', 'unknown_status', 'orphan_submissions');
    $nStruct = 0;
    $nRecords = 0;
    $tier1 = 0;
    $tier2 = 0;
    $groups = array();
    $records = array();
    foreach ($checked ? $problems : array() as $p) {
        $g = ksefDbCheckGroup($p);
        if ($g === '') {
            continue;
        }
        $tier1 += ksefDbCheckTier($p) == 1 ? 1 : 0;
        $tier2 += ksefDbCheckTier($p) == 2 ? 1 : 0;
        if (in_array($p['code'], $recordCodes, true)) {
            $records[] = $p;
            $nRecords += $p['severity'] != 'info' ? 1 : 0;
        } else {
            $nStruct += $p['severity'] != 'info' ? 1 : 0;
        }
        $groups[$g][] = $p;
    }
    $bad = $nStruct + $nRecords + $lost['count'] + ($probeFailed ? 1 : 0);
    $date = function ($ts) {
        return dol_print_date($ts, 'dayhour', 'tzuserrel');
    };
    $attrs = function ($p) {
        return ' data-code="' . dol_escape_htmltag($p['code']) . '" data-severity="' . dol_escape_htmltag($p['severity']) . '" data-tier="' . ksefDbCheckTier($p) . '" data-key="' . ksefDbCheckFindingKey($p) . '"';
    };

    $html = '<a id="dbcheck"></a>';
    $html .= '<table class="noborder centpercent ksef-dbcheck">';
    $html .= '<tr class="liste_titre"><th colspan="4"><span class="fa fa-database paddingright"></span>' . $langs->trans('KSEF_DbCheck');
    if ($checked || $bad) {
        $html .= $bad ? ' <span class="badge ' . ($probeFailed ? 'badge-danger ' . ksefToneClass('problem') : 'badge-warning ' . ksefToneClass('offline')) . ' ksef-dbcheck-state" data-state="fix" style="float: right;">' . $langs->trans('KSEF_DbCheckFound', $bad) . '</span>'
            : ' <span class="badge badge-success ' . ksefToneClass('ok') . ' ksef-dbcheck-state" data-state="ok" style="float: right;">' . $langs->trans('KSEF_DbCheckOk') . '</span>';
    }
    $html .= '</th></tr>';
    $html .= '<tr class="oddeven"><td colspan="4"><span class="opacitymedium">' . $langs->trans('KSEF_DbCheckIntro') . '</span>';
    if ($db->type == 'pgsql') {
        $html .= '<br><span class="warning">' . $langs->trans('KSEF_DbCheckMysqlOnly') . '</span>';
    }
    if (!$mayChange) {
        $html .= '<br><span class="opacitymedium ksef-dbcheck-refused"><span class="fa fa-lock paddingright"></span>' . $langs->trans('KSEF_DbSuperadminOnly') . '</span>';
    }
    $html .= '</td></tr>';
    if (is_array($repairDone)) {
        $html .= '<tr class="liste_titre"><td>' . $langs->trans('KSEF_DbCheckColObject') . '</td><td>' . $langs->trans('KSEF_DbRepairColAction') . '</td><td colspan="2">' . $langs->trans('KSEF_DbRepairColResult') . '</td></tr>';
        if (!$repairDone) {
            $html .= '<tr class="oddeven"><td colspan="4">' . $langs->trans('KSEF_DbRepairNothing') . '</td></tr>';
        }
        foreach ($repairDone as $d) {
            $badge = $d['result'] == 'ok' ? 'badge-status4 ' . ksefToneClass('ok') : ($d['result'] == 'skipped' ? 'badge-warning ' . ksefToneClass('info') : 'badge-danger ' . ksefToneClass('problem'));
            $html .= '<tr class="oddeven ksef-dbrepair-row" data-result="' . dol_escape_htmltag($d['result']) . '"><td>' . dol_escape_htmltag(ksefDbCheckObjectLabel($d['object'])) . '</td><td>' . dol_escape_htmltag($d['action']) . '</td>'
                . '<td colspan="2"><span class="badge ' . $badge . '">' . $langs->trans('KSEF_DbRepair_' . $d['result']) . '</span>'
                . ($d['detail'] !== '' ? ' <span class="opacitymedium">' . dol_escape_htmltag($d['detail']) . '</span>' : '') . '</td></tr>';
        }
    }
    $html .= '</table>';

    if ($probeFailed) {
        $html .= '<div class="error ksef-dbprobe-failed"><strong>' . $langs->trans('KSEF_DbProbeFailTitle') . '</strong><br>' . $langs->trans('KSEF_DbProbeFailMore')
            . '<br>' . $langs->trans('KSEF_DbProbeFailWhat') . '<br><span class="small">' . dol_escape_htmltag($langs->transnoentitiesnoconv('KSEF_DbProbeDetail',
                $langs->transnoentitiesnoconv('KSEF_DbProbeStep_' . $probe['step']), $probe['table'], $probe['error'])) . '</span></div>';
    }
    if ($mayChange && $undo['state'] == 'ok') {
        $html .= '<div class="info ksef-dbundo" data-state="ok">' . $langs->trans('KSEF_DbUndoBanner', $date($undo['created_at'])) . ' ' . $langs->trans('KSEF_DbUndoValid') . ' '
            . dolGetButtonAction('', '<span class="fa fa-undo paddingright"></span>' . $langs->trans('KSEF_DbUndoDo'), 'default', $self . '?action=dbundo&token=' . newToken() . ksefDbNavParam() . '#dbcheck', '', 1,
                array('attr' => array('class' => 'ksef-dbundo-do'))) . '</div>';
    } elseif ($mayChange && $undo['state'] == 'late') {
        $html .= '<div class="warning ksef-dbundo" data-state="late">' . $langs->trans('KSEF_DbUndoLate', $date($undo['created_at'])) . ' <a href="#dbbackup">' . $langs->trans('KSEF_DbBkTitle') . '</a></div>';
    }
    if ($checked) {
        if (!$bad) {
            $html .= '<div class="info ksef-dbsummary" data-state="ok"><span class="fa fa-check-circle paddingright" style="color: var(--ksef-tone-ok);"></span><strong>' . $langs->trans('KSEF_DbSumOk') . '</strong><br>'
                . $langs->trans('KSEF_DbSumOkMore') . '</div>';
        } else {
            $html .= '<div class="warning ksef-dbsummary" data-state="fix"><strong>' . $langs->trans('KSEF_DbSumFix', $nStruct, $nRecords, $lost['count']) . '</strong><br>'
                . $langs->trans($tier1 ? 'KSEF_DbSumFixMore' : ($tier2 ? 'KSEF_DbSumFixStep2' : 'KSEF_DbSumFixNoButton')) . '</div>';
        }
    }

    if ($checked && is_array($stats)) {
        $lastBk = array();
        foreach ($mayChange ? ksefDbBackupList() : array() as $b) {
            foreach ($b['header'] ? array_keys($b['header']['tables']) : array() as $tb) {
                $lastBk[$tb] = max(isset($lastBk[$tb]) ? $lastBk[$tb] : 0, (int) $b['header']['created_at']);
            }
        }
        $stateBadges = array('ok' => 'badge-status4 ' . ksefToneClass('ok'), 'fix' => 'badge-warning ' . ksefToneClass('offline'),
            'damaged' => 'badge-danger ' . ksefToneClass('problem'), 'missing' => 'badge-danger ' . ksefToneClass('problem'));
        $rows = $stats + array('extrafields' => null, 'cron' => null) + (isset($groups['module']) ? array('module' => null) : array());
        $html .= '<table class="noborder centpercent ksef-dbhealth-overview ksef-dbstep1">';
        $html .= '<tr class="liste_titre"><th colspan="4">' . $langs->trans('KSEF_DbStep1') . '</th></tr>';
        $html .= '<tr class="liste_titre"><td>' . $langs->trans('KSEF_DbOverviewCol') . '</td><td>' . $langs->trans('KSEF_DbHealthCol') . '</td><td class="right">' . $langs->trans('KSEF_DbTableRows') . '</td>'
            . '<td class="right hideonsmartphone">' . $langs->trans('KSEF_DbLastBackup') . '</td></tr>';
        foreach ($rows as $name => $st) {
            $list = isset($groups[$name]) ? $groups[$name] : array();
            $state = ksefDbCheckGroupState($list, $st);
            $label = $langs->trans('KSEF_DbTableName_' . $name);
            $cell = $label;
            if ($list || $state != 'ok') {
                $plain = '';
                $tech = '';
                $step2 = false;
                $hs = $st && isset($st['health']) ? $st['health']['state'] : '';
                if ($hs == 'corrupt') {
                    $plain .= '<li>' . $langs->trans('KSEF_DbPlain_corrupt') . '</li>';
                    $tech .= dol_escape_htmltag($st['health']['detail']) . '<br>';
                } elseif ($hs == 'missing' && !$list) {
                    $plain .= '<li>' . $langs->trans('KSEF_DbPlain_table_missing') . '</li>';
                }
                foreach ($list as $p) {
                    $tech .= dol_escape_htmltag(ksefDbCheckObjectLabel($p['object'])) . ': ' . dol_escape_htmltag($p['problem']) . '. ' . dol_escape_htmltag($p['fix']) . '<br>';
                    if (in_array($p['code'], $recordCodes, true)) {
                        $step2 = true;
                        continue;
                    }
                    $plain .= '<li class="ksef-dbcheck-problem"' . $attrs($p) . '>' . ksefDbCheckPlainText($p) . ksefDbCheckAffectedText($db, $p)
                        . ($p['code'] == 'migration_behind' && $mayChange ? ' <a class="button smallpaddingimp ksef-dbapplyupdate" href="' . $self . '?action=ksef_reinit_ask&token=' . newToken() . '">' . $langs->trans('KSEF_ApplyUpdate') . '</a>' : '') . '</li>';
                }
                if ($step2) {
                    $plain .= '<li class="ksef-dbstep2-ref opacitymedium">' . $langs->trans('KSEF_DbStep2Ref') . '</li>';
                }
                $cell = '<details class="ksef-dbrow"' . ($state != 'ok' ? ' open' : '') . '><summary><strong>' . $label . '</strong></summary><ul class="ksef-dbplain" style="margin: 4px 0;">' . $plain . '</ul>'
                    . '<details class="ksef-dbtech"><summary class="opacitymedium small">' . $langs->trans('KSEF_DbDetails') . '</summary><div class="opacitymedium small">' . $tech . '</div></details></details>';
            }
            $html .= '<tr class="oddeven ksef-dbhealth-row" data-table="' . dol_escape_htmltag($name) . '" data-state="' . $state . '"><td>' . $cell . '</td>'
                . '<td><span class="badge ' . $stateBadges[$state] . ' ksef-dbhealth" data-state="' . $state . '">' . $langs->trans('KSEF_DbState_' . $state) . '</span></td>'
                . '<td class="right">' . ($st && $st['exists'] ? (int) $st['rows'] : '-') . '</td>'
                . '<td class="right nowraponall hideonsmartphone">' . ($st && !empty($lastBk[$name]) ? $date($lastBk[$name]) : '-') . '</td></tr>';
        }
        $html .= '</table>';
    }
    $html .= '<div class="tabsAction ksef-dbstep1-actions">';
    if ($checked && $tier1) {
        $html .= '<span class="opacitymedium paddingright">' . $langs->trans('KSEF_DbStep1Help') . '</span>'
            . dolGetButtonAction($mayChange ? '' : $langs->trans('KSEF_DbSuperadminOnly'), $langs->trans('KSEF_DbRepairN', $tier1), 'default', $self . '?action=dbrepair&token=' . newToken() . ksefDbNavParam() . '#dbcheck', '', $mayChange ? 1 : -1,
                array('attr' => array('class' => 'ksef-dbfix-structure' . ($mayChange ? '' : ' ksef-dbrepair-refused'))));
    }
    $html .= dolGetButtonAction('', $langs->trans('KSEF_DbCheckRun'), 'default', $self . '?action=dbcheck&token=' . newToken() . '#dbcheck', '', 1, array('attr' => array('class' => 'ksef-dbcheck-run')));
    $html .= '</div>';

    if ($records) {
        $html .= '<table class="noborder centpercent ksef-dbstep2">';
        $html .= '<tr class="liste_titre"><th colspan="3">' . $langs->trans('KSEF_DbStep2') . '</th></tr>';
        $html .= '<tr class="liste_titre"><td>' . $langs->trans('KSEF_DbOverviewCol') . '</td><td>' . $langs->trans('KSEF_DbStep2Rules') . '</td><td class="right">' . $langs->trans('KSEF_DbTableRows') . '</td></tr>';
        foreach ($records as $p) {
            $html .= '<tr class="oddeven ksef-dbcheck-problem"' . $attrs($p) . '><td>' . $langs->trans('KSEF_DbTableName_' . ksefDbCheckGroup($p)) . '</td>'
                . '<td>' . ksefDbCheckPlainText($p) . ksefDbCheckAffectedText($db, $p) . '</td><td class="right">' . (isset($p['n']) ? (int) $p['n'] : '') . '</td></tr>';
        }
        $html .= '</table>';
        if ($tier2) {
            $wait = $tier1 > 0;
            $html .= '<div class="tabsAction ksef-dbstep2-actions"><span class="opacitymedium paddingright' . ($wait ? ' ksef-dbtier2-wait' : '') . '">' . $langs->trans($wait ? 'KSEF_DbStep2Wait' : 'KSEF_DbStep2Help') . '</span>'
                . dolGetButtonAction($wait ? $langs->trans('KSEF_DbStep2Wait') : ($mayChange ? '' : $langs->trans('KSEF_DbSuperadminOnly')), $langs->trans('KSEF_DbTier2All', $tier2), 'default',
                    $self . '?action=dbfixdata&key=all&token=' . newToken() . ksefDbNavParam() . '#dbcheck', '', ($mayChange && !$wait) ? 1 : -1, array('attr' => array('class' => 'ksef-dbfix-all' . ($mayChange ? '' : ' ksef-dbrepair-refused'))))
                . '</div>';
        }
    }
    if ($lost['count'] > 0) {
        $html .= '<table class="noborder centpercent ksef-dblost">';
        $html .= '<tr class="liste_titre"><th colspan="2">' . $langs->trans('KSEF_DbLostTitle', $lost['count']) . '</th></tr>';
        $html .= '<tr class="oddeven"><td colspan="2" class="opacitymedium">' . $langs->trans('KSEF_DbLostText') . '</td></tr>';
        foreach ($lost['refs'] as $id => $ref) {
            $html .= '<tr class="oddeven ksef-dblost-row" data-id="' . (int) $id . '"><td>' . dol_escape_htmltag($ref) . '</td><td class="right">'
                . '<a class="button smallpaddingimp ksef-dblost-open" href="' . DOL_URL_ROOT . '/compta/facture/card.php?id=' . (int) $id . '">' . $langs->trans('KSEF_DbLostOpen') . '</a></td></tr>';
        }
        if ($lost['count'] > count($lost['refs'])) {
            $html .= '<tr class="oddeven"><td colspan="2" class="opacitymedium">' . $langs->trans('KSEF_DbAffectedMore', $lost['count'] - count($lost['refs'])) . '</td></tr>';
        }
        $html .= '</table><br>';
    }

    if ($mayChange && is_array($stats)) {
        $damaged = $checked ? ksefDbCheckRebuildable($problems, $stats) : array();
        if ($probeFailed && strpos($probe['table'], MAIN_DB_PREFIX) === 0) {
            $pt = substr($probe['table'], strlen(MAIN_DB_PREFIX));
            if (!empty($stats[$pt]['exists']) && !in_array($pt, $damaged, true)) {
                $damaged[] = $pt;
            }
        }
        $others = array();
        foreach ($stats as $table => $st) {
            if ($st['exists'] && !in_array($table, $damaged, true)) {
                $others[] = $table;
            }
        }
        $button = function ($table) use ($self, $langs) {
            return dolGetButtonAction('', $langs->trans('KSEF_DbRebuild', $langs->transnoentitiesnoconv('KSEF_DbTableName_' . $table)), 'delete',
                $self . '?action=dbrebuild&table=' . urlencode($table) . '&token=' . newToken() . ksefDbNavParam() . '#dbcheck', '', 1, array('attr' => array('class' => 'ksef-dbrebuild')));
        };
        $html .= '<details class="ksef-dbadvanced"' . ($damaged || $rebuildTable !== '' ? ' open' : '') . ' style="margin-bottom: 12px;"><summary><strong>' . $langs->trans($checked && $bad ? 'KSEF_DbStep3' : 'KSEF_DbTier3Title') . '</strong>'
            . ' <span class="opacitymedium">' . $langs->trans('KSEF_DbStep3Sub') . '</span></summary><p class="opacitymedium">' . $langs->trans('KSEF_DbTier3Help') . '</p>';
        $html .= '<div class="ksef-dbrebuild-damaged">';
        foreach ($damaged as $table) {
            $html .= $button($table);
        }
        $html .= $damaged ? '' : '<span class="opacitymedium">' . $langs->trans('KSEF_DbTier3None') . '</span>';
        $html .= '</div>';
        if ($others) {
            $html .= '<details class="ksef-dbrebuild-all"' . (in_array($rebuildTable, $others, true) ? ' open' : '') . '><summary class="opacitymedium">' . $langs->trans('KSEF_DbTier3ShowAll') . '</summary>';
            foreach ($others as $table) {
                $html .= $button($table);
            }
            $html .= '</details>';
        }
        if ($rebuildTable !== '' && !empty($stats[$rebuildTable]['exists'])) {
            $html .= ksefDbCheckRebuildBox($rebuildTable, $stats[$rebuildTable]);
        }
        $html .= '</details>';
    }
    $html .= ksefDbBackupRenderBlock($restoreFile);
    return $html;
}

/**
 * @brief Rebuild confirm box
 * @param string $table Without prefix
 * @param array $st Table stats
 * @return string HTML
 */
function ksefDbCheckRebuildBox($table, $st)
{
    global $langs;
    $self = dol_buildpath('/ksef/admin/about.php', 1);
    $full = ksefDbCheckTableName($table);
    $html = '<div class="warning ksef-dbrebuild-box"><strong>' . $langs->trans('KSEF_DbRebuildTitle', $langs->transnoentitiesnoconv('KSEF_DbTableName_' . $table)) . '</strong><br>'
        . ksefDbCheckResetQuestion($table, $st) . '<br><span class="opacitymedium">' . $langs->trans('KSEF_DbTableAbout_' . $table) . '</span>';
    $html .= '<br>' . $langs->trans('KSEF_DbResetDownloadFirst') . ' ';
    foreach (array('csv' => 'KSEF_DbDownloadCsv', 'sql' => 'KSEF_DbDownloadSql') as $fmt => $label) {
        $html .= '<a class="button smallpaddingimp" href="' . $self . '?action=dbdump&table=' . urlencode($table) . '&format=' . $fmt . '&token=' . newToken() . '">'
            . '<span class="fa fa-download paddingright"></span>' . $langs->trans($label) . '</a> ';
    }
    $opts = '<option value="">' . $langs->trans('KSEF_DbRebuildFromNone') . '</option>';
    foreach (ksefDbBackupList() as $b) {
        if ($b['header'] && isset($b['header']['tables'][$table])) {
            $opts .= '<option value="' . dol_escape_htmltag($b['name']) . '">' . $langs->trans('KSEF_DbRebuildFromBackup', dol_print_date($b['header']['created_at'], 'dayhour', 'tzuserrel'),
                (int) $b['header']['tables'][$table]['rows']) . '</option>';
        }
    }
    $html .= '<form method="POST" action="' . $self . '#dbcheck" style="margin-top: 8px;">'
        . '<input type="hidden" name="token" value="' . newToken() . '">'
        . '<input type="hidden" name="action" value="confirm_dbrebuild">'
        . '<input type="hidden" name="table" value="' . dol_escape_htmltag($table) . '">'
        . '<label>' . $langs->trans('KSEF_DbRebuildFrom') . ' <select name="file" class="ksef-dbrebuild-from">' . $opts . '</select></label><br>'
        . '<label>' . $langs->trans('KSEF_DbRebuildType', '<strong>' . $full . '</strong>') . ' <input type="text" name="typed" autocomplete="off" class="ksef-dbrebuild-typed"></label> '
        . '<input type="submit" class="button button-delete" value="' . dol_escape_htmltag($langs->trans('KSEF_DbRebuildDo')) . '">'
        . ' <a class="button button-cancel" href="' . $self . '?action=dbcheck&token=' . newToken() . '#dbcheck">' . $langs->trans('Cancel') . '</a>'
        . '</form></div>';
    return $html;
}

/**
 * @brief Error status value
 * @param string $table Without prefix
 * @param string $col
 * @return string|null null to clear
 */
function ksefDbCheckErrorStatus($table, $col)
{
    $map = array('ksef_submissions.status' => 'FAILED', 'ksef_incoming.import_status' => 'ERROR', 'ksef_sales.import_status' => 'ERROR');
    return isset($map[$table . '.' . $col]) ? $map[$table . '.' . $col] : null;
}

/**
 * @brief Fetch encoded row
 * @param DoliDB $db
 * @param string $full Prefixed name
 * @param int $id
 * @return array|null
 */
function ksefDbCheckRowGet($db, $full, $id)
{
    $res = $db->query("SELECT * FROM " . $full . " WHERE rowid = " . (int) $id);
    $o = $res ? $db->fetch_object($res) : null;
    if (!$o) {
        return null;
    }
    return array_map('ksefDbBackupEncodeValue', (array) $o);
}

/**
 * @brief Tier 2 changes
 * @param DoliDB $db
 * @param array $p Problem
 * @return array
 */
function ksefDbCheckRecordChanges($db, $p)
{
    global $langs;
    $r = $p['repair'];
    $schema = ksefDbCheckSchema();
    $out = array();
    if (!isset($schema[$r['table']])) {
        return $out;
    }
    $full = ksefDbCheckTableName($r['table']);
    $cols = ksefDbCheckLiveColumns($db, $full);
    $num = isset($cols['ksef_number']) ? ", t.ksef_number AS knum" : "";
    $label = function ($o) {
        return '#' . (int) $o->rowid . (!empty($o->knum) ? ' (' . $o->knum . ')' : '');
    };
    if ($r['op'] == 'dedupe') {
        $kc = $schema[$r['table']]['keys'][$r['key']]['columns'];
        $score = array('0');
        if (isset($cols['status'])) {
            $score[] = "(CASE WHEN t.status = 'ACCEPTED' THEN 2 ELSE 0 END)";
        }
        if (isset($cols['upo_xml'])) {
            $score[] = "(CASE WHEN t.upo_xml IS NOT NULL AND t.upo_xml <> '' THEN 2 ELSE 0 END)";
        }
        if (isset($cols['import_status'])) {
            $score[] = "(CASE WHEN t.import_status = 'IMPORTED' THEN 1 ELSE 0 END)";
        }
        foreach (array('fk_facture', 'fk_facture_fourn') as $c) {
            if (isset($cols[$c])) {
                $score[] = "(CASE WHEN t." . $c . " > 0 THEN 1 ELSE 0 END)";
            }
        }
        $on = array();
        $notNull = array();
        $order = array();
        foreach ($kc as $c) {
            $on[] = "d." . $c . " = t." . $c;
            $notNull[] = $c . " IS NOT NULL";
            $order[] = "t." . $c;
        }
        $state = isset($cols['status']) ? "t.status" : (isset($cols['import_status']) ? "t.import_status" : "''");
        $sql = "SELECT t.rowid, " . implode(' + ', $score) . " AS score, " . $state . " AS st, CONCAT_WS(', ', " . implode(', ', $order) . ") AS kv" . $num
            . (isset($cols['upo_xml']) ? ", (t.upo_xml IS NOT NULL AND t.upo_xml <> '') AS upo" : ", 0 AS upo")
            . " FROM " . $full . " AS t INNER JOIN (SELECT " . implode(', ', $kc) . " FROM " . $full . " WHERE " . implode(' AND ', $notNull)
            . " GROUP BY " . implode(', ', $kc) . " HAVING COUNT(*) > 1) AS d ON " . implode(' AND ', $on)
            . " ORDER BY " . implode(', ', $order) . ", score DESC, t.rowid DESC";
        $res = $db->query($sql);
        $keep = array();
        while ($res && ($o = $db->fetch_object($res))) {
            $now = $langs->transnoentitiesnoconv('KSEF_DbChgDup', $o->kv) . ((string) $o->st !== '' ? ', ' . $langs->transnoentitiesnoconv('KSEF_DbChgStatus', $o->st) : '')
                . (!empty($o->upo) ? ', UPO' : '');
            if (!isset($keep[$o->kv])) {
                $keep[$o->kv] = (int) $o->rowid;
                $out[] = array('table' => $r['table'], 'rowid' => (int) $o->rowid, 'label' => $label($o), 'op' => 'keep', 'set' => array(), 'now' => $now,
                    'after' => $langs->transnoentitiesnoconv('KSEF_DbChgKeep'));
            } else {
                $out[] = array('table' => $r['table'], 'rowid' => (int) $o->rowid, 'label' => $label($o), 'op' => 'delete', 'set' => array(), 'now' => $now,
                    'after' => $langs->transnoentitiesnoconv('KSEF_DbChgDelete', $keep[$o->kv]));
            }
        }
    } elseif ($r['op'] == 'status_reset' && !empty($p['rows']['where'])) {
        $res = $db->query("SELECT t.rowid, t." . $r['column'] . " AS v" . $num . " FROM " . $full . " AS t WHERE " . $p['rows']['where'] . " ORDER BY t.rowid");
        while ($res && ($o = $db->fetch_object($res))) {
            $out[] = array('table' => $r['table'], 'rowid' => (int) $o->rowid, 'label' => $label($o), 'op' => 'set', 'set' => array($r['column'] => $r['to']),
                'now' => $langs->transnoentitiesnoconv('KSEF_DbChgStatus', $o->v),
                'after' => $r['to'] === null ? $langs->transnoentitiesnoconv('KSEF_DbChgStatusEmpty') : $langs->transnoentitiesnoconv('KSEF_DbChgStatusError', $r['to']));
        }
    } elseif ($r['op'] == 'fk_orphans') {
        $key = $schema[$r['table']]['keys'][$r['key']];
        if ($key['on_delete'] !== 'SET NULL') {
            return $out;
        }
        $col = $key['columns'][0];
        $res = $db->query("SELECT t.rowid, t." . $col . " AS v" . $num . " FROM " . $full . " AS t WHERE " . ksefDbCheckOrphanWhere($key) . " ORDER BY t.rowid");
        while ($res && ($o = $db->fetch_object($res))) {
            $out[] = array('table' => $r['table'], 'rowid' => (int) $o->rowid, 'label' => $label($o), 'op' => 'set', 'set' => array($col => null),
                'now' => $langs->transnoentitiesnoconv('KSEF_DbChgOrphan', $o->v), 'after' => $langs->transnoentitiesnoconv('KSEF_DbChgCleared'));
        }
    }
    return $out;
}

/**
 * @brief Apply tier 2
 * @param DoliDB $db
 * @param array $p Problem
 * @param array $journal Undo journal
 * @return array
 */
function ksefDbCheckApplyRecordChanges($db, $p, &$journal)
{
    $r = $p['repair'];
    $full = ksefDbCheckTableName($r['table']);
    $rows = array();
    $db->begin();
    foreach (ksefDbCheckRecordChanges($db, $p) as $c) {
        $before = ksefDbCheckRowGet($db, $full, $c['rowid']);
        if ($before === null) {
            continue;
        }
        // undo needs row
        if ($c['op'] == 'keep') {
            $rows[] = array('table' => $r['table'], 'rowid' => (int) $c['rowid'], 'keep' => true, 'before' => $before, 'after' => $before);
            continue;
        }
        if ($c['op'] == 'delete') {
            $sql = "DELETE FROM " . $full . " WHERE rowid = " . (int) $c['rowid'];
        } else {
            $parts = array();
            foreach ($c['set'] as $col => $v) {
                $parts[] = $col . " = " . ($v === null ? "NULL" : "'" . $db->escape($v) . "'");
            }
            $sql = "UPDATE " . $full . " SET " . implode(', ', $parts) . " WHERE rowid = " . (int) $c['rowid'];
        }
        if (!$db->query($sql)) {
            $err = $db->lasterror();
            $db->rollback();
            return array('result' => 'failed', 'detail' => $err);
        }
        $rows[] = array('table' => $r['table'], 'rowid' => (int) $c['rowid'], 'before' => $before, 'after' => $c['op'] == 'delete' ? null : ksefDbCheckRowGet($db, $full, $c['rowid']));
    }
    $db->commit();
    $n = 0;
    foreach ($rows as $row) {
        $journal['rows'][] = $row;
        $n += empty($row['keep']) ? 1 : 0;
    }
    if (!empty($r['key'])) {
        $schema = ksefDbCheckSchema();
        if (!$db->query(ksefDbCheckPrefix($schema[$r['table']]['keys'][$r['key']]['sql']))) {
            return array('result' => 'failed', 'detail' => $db->lasterror());
        }
        $journal['keys'][] = array('table' => $r['table'], 'key' => $r['key']);
    }
    return array('result' => 'ok', 'detail' => (string) $n);
}

/**
 * @brief Undo journal path
 * @return string
 */
function ksefDbCheckUndoPath()
{
    return ksefDbBackupDir() . '/ksef-undo.json';
}

/**
 * @brief Save undo journal
 * @param DoliDB $db
 * @param array $journal
 * @param string $backup Backup file
 * @param User $user
 * @return bool
 */
function ksefDbCheckUndoSave($db, $journal, $backup, $user)
{
    $max = array();
    foreach (array('ksef_submissions', 'ksef_incoming', 'ksef_sales') as $table) {
        $full = ksefDbCheckTableName($table);
        $max[$table] = ksefDbCheckTableExists($db, $full) ? ksefDbCheckCount($db, "SELECT MAX(rowid) AS n FROM " . $full) : 0;
    }
    if (!is_dir(ksefDbBackupDir())) {
        dol_mkdir(ksefDbBackupDir());
    }
    $data = array('created_at' => dol_now(), 'backup' => (string) $backup, 'by' => is_object($user) ? (string) $user->login : '', 'max' => $max,
        'rows' => $journal['rows'], 'keys' => $journal['keys']);
    return @file_put_contents(ksefDbCheckUndoPath(), json_encode($data)) !== false;
}

/**
 * @brief Undo state
 * @param DoliDB $db
 * @return array
 */
function ksefDbCheckUndoState($db)
{
    $path = ksefDbCheckUndoPath();
    $j = is_file($path) ? json_decode((string) @file_get_contents($path), true) : null;
    if (!is_array($j) || empty($j['rows']) || dol_now() - (int) $j['created_at'] > 14 * 86400) {
        return array('state' => 'none');
    }
    $out = array('state' => 'ok', 'created_at' => (int) $j['created_at'], 'backup' => (string) $j['backup'], 'n' => count(array_filter($j['rows'], function ($row) {
        return empty($row['keep']);
    })), 'journal' => $j);
    foreach ((array) $j['max'] as $table => $max) {
        $full = ksefDbCheckTableName($table);
        if (ksefDbCheckTableExists($db, $full) && ksefDbCheckCount($db, "SELECT MAX(rowid) AS n FROM " . $full) > (int) $max) {
            $out['state'] = 'late';
            return $out;
        }
    }
    foreach ($j['rows'] as $row) {
        if (json_encode(ksefDbCheckRowGet($db, ksefDbCheckTableName($row['table']), $row['rowid'])) !== json_encode($row['after'])) {
            $out['state'] = 'late';
            return $out;
        }
    }
    return $out;
}

/**
 * @brief Undo last fix
 * @param DoliDB $db
 * @param User $user
 * @return array
 */
function ksefDbCheckUndo($db, $user)
{
    global $langs;
    $langs->loadLangs(array('ksef@ksef'));
    $st = ksefDbCheckUndoState($db);
    if ($st['state'] != 'ok') {
        return array('ok' => false, 'rows' => 0, 'error' => $langs->transnoentitiesnoconv('KSEF_DbUndoGone'), 'backup' => '');
    }
    $b = ksefDbBackupBefore($db, $user, 'undo');
    if (!$b['ok']) {
        return array('ok' => false, 'rows' => 0, 'error' => $b['error'], 'backup' => '');
    }
    $j = $st['journal'];
    $schema = ksefDbCheckSchema();
    foreach ($j['keys'] as $k) {
        $full = ksefDbCheckTableName($k['table']);
        $isFk = $schema[$k['table']]['keys'][$k['key']]['type'] == 'fk';
        $live = $isFk ? ksefDbCheckLiveForeignKeys($db, $full) : ksefDbCheckLiveIndexes($db, $full);
        if (isset($live[$k['key']]) && !$db->query("ALTER TABLE " . $full . ($isFk ? " DROP FOREIGN KEY " : " DROP INDEX ") . $k['key'])) {
            return array('ok' => false, 'rows' => 0, 'error' => $db->lasterror(), 'backup' => $b['file']);
        }
    }
    $db->begin();
    $n = 0;
    foreach (array_reverse($j['rows']) as $row) {
        if (!empty($row['keep'])) {
            continue;
        }
        $n++;
        $full = ksefDbCheckTableName($row['table']);
        $cols = array();
        $vals = array();
        $ok = true;
        foreach ($row['before'] as $col => $v) {
            $v = ksefDbBackupDecodeValue($v, $ok);
            $cols[] = $col;
            $vals[$col] = $v === null ? "NULL" : "'" . $db->escape($v) . "'";
        }
        if ($row['after'] === null) {
            $sql = "INSERT INTO " . $full . " (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")";
        } else {
            $set = array();
            foreach ($vals as $col => $v) {
                $set[] = $col . " = " . $v;
            }
            $sql = "UPDATE " . $full . " SET " . implode(', ', $set) . " WHERE rowid = " . (int) $row['rowid'];
        }
        if (!$ok || !$db->query($sql)) {
            $err = $ok ? $db->lasterror() : 'bad value';
            $db->rollback();
            return array('ok' => false, 'rows' => 0, 'error' => $err, 'backup' => $b['file']);
        }
    }
    $db->commit();
    @unlink(ksefDbCheckUndoPath());
    dol_syslog('ksefDbCheckUndo by ' . (is_object($user) ? $user->login : '') . ': ' . $n . ' rows back, ' . count($j['keys']) . ' keys dropped', LOG_WARNING);
    return array('ok' => true, 'rows' => $n, 'error' => '', 'backup' => $b['file']);
}

/**
 * @brief Preview tier 2
 * @param DoliDB $db
 * @param array $problems Findings
 * @param string $key Finding key or 'all'
 * @param int $limit Max rows
 * @return array
 */
function ksefDbCheckRecordPlan($db, $problems, $key = 'all', $limit = 50)
{
    global $langs;
    $n = 0;
    $list = array();
    foreach ((array) $problems as $p) {
        if (ksefDbCheckTier($p) != 2 || ($key !== 'all' && ksefDbCheckFindingKey($p) !== $key)) {
            continue;
        }
        $n++;
        if (in_array($p['repair']['op'], array('dedupe', 'fk_orphans', 'status_reset'), true)) {
            $list = array_merge($list, ksefDbCheckRecordChanges($db, $p));
        }
    }
    $html = '<table class="noborder centpercent ksef-dbfix-plan"><tr class="liste_titre"><th>' . $langs->trans('KSEF_DbPlanTable') . '</th><th>' . $langs->trans('KSEF_DbPlanRow') . '</th>'
        . '<th>' . $langs->trans('KSEF_DbPlanNow') . '</th><th>' . $langs->trans('KSEF_DbPlanAfter') . '</th></tr>';
    foreach (array_slice($list, 0, $limit) as $c) {
        $html .= '<tr class="oddeven" data-op="' . $c['op'] . '" data-rowid="' . (int) $c['rowid'] . '"><td>' . $langs->trans('KSEF_DbTableName_' . $c['table']) . '</td><td class="nowraponall">' . dol_escape_htmltag($c['label']) . '</td>'
            . '<td>' . dol_escape_htmltag($c['now']) . '</td><td>' . ($c['op'] == 'keep' ? '' : '<strong>') . dol_escape_htmltag($c['after']) . ($c['op'] == 'keep' ? '' : '</strong>') . '</td></tr>';
    }
    if (count($list) > $limit) {
        $html .= '<tr class="oddeven"><td colspan="4" class="opacitymedium">' . $langs->trans('KSEF_DbAffectedMore', count($list) - $limit) . '</td></tr>';
    }
    $html .= '</table>';
    return array('n' => $n, 'rows' => count($list), 'html' => $html);
}

/**
 * @brief Missing submission rows
 * @param DoliDB $db
 * @param int $limit Max refs
 * @return array
 */
function ksefDbHealthLostRows($db, $limit = 20)
{
    dol_include_once('/ksef/lib/ksef.lib.php');
    $out = array('count' => 0, 'refs' => array());
    $sub = MAIN_DB_PREFIX . 'ksef_submissions';
    if (!ksefDbCheckTableExists($db, $sub) || !ksefDbCheckTableExists($db, MAIN_DB_PREFIX . 'facture_extrafields')) {
        return $out;
    }
    $cols = ksefDbCheckLiveColumns($db, MAIN_DB_PREFIX . 'facture_extrafields');
    if (!isset($cols['ksef_number'])) {
        return $out;
    }
    $sql = "SELECT f.rowid, f.ref FROM " . MAIN_DB_PREFIX . "facture AS f"
        . " INNER JOIN " . MAIN_DB_PREFIX . "facture_extrafields AS fe ON fe.fk_object = f.rowid"
        . " WHERE f.fk_statut > 0 AND f.entity IN (" . getEntity('invoice') . ")"
        . " AND fe.ksef_number IS NOT NULL AND fe.ksef_number <> ''"
        . " AND NOT EXISTS (SELECT 1 FROM " . $sub . " AS s WHERE s.fk_facture = f.rowid AND s.status IN ('ACCEPTED', 'OFFLINE'))"
        . " ORDER BY f.rowid";
    $resql = $db->query($sql);
    while ($resql && ($o = $db->fetch_object($resql))) {
        if (ksefNumberWithoutRow($db, (int) $o->rowid) === '') {
            continue;
        }
        $out['count']++;
        if (count($out['refs']) < $limit) {
            $out['refs'][(int) $o->rowid] = (string) $o->ref;
        }
    }
    return $out;
}

/**
 * @brief Probe table writes
 * @param DoliDB $db
 * @return array
 */
function ksefDbHealthProbe($db)
{
    global $conf;
    $out = array('ok' => true, 'step' => '', 'table' => '', 'error' => '');
    if (!empty($db->transaction_opened)) {
        // caller's work kept
        return array('ok' => false, 'step' => 'open_transaction', 'table' => '', 'error' => '');
    }
    $fk = ksefDbCheckCount($db, "SELECT MIN(rowid) AS n FROM " . MAIN_DB_PREFIX . "facture");
    $probes = array();
    if ($fk > 0) {
        $probes[MAIN_DB_PREFIX . 'ksef_submissions'] = array(
            "INSERT INTO " . MAIN_DB_PREFIX . "ksef_submissions (fk_facture, status, environment, error_message) VALUES (" . $fk . ", 'PROBE', 'TEST', 'ksef write probe')",
            'fk_facture = ' . $fk . " AND status = 'PROBE'",
            "error_message = 'ksef write probe 2'",
        );
    } else {
        $out['step'] = 'no_invoice';
    }
    $mark = 'PROBE-' . dol_getmypid() . '-' . mt_rand(100000, 999999);
    $probes[MAIN_DB_PREFIX . 'ksef_incoming'] = array(
        "INSERT INTO " . MAIN_DB_PREFIX . "ksef_incoming (ksef_number, fetch_date, entity) VALUES ('" . $db->escape($mark) . "', " . (int) dol_now() . ", " . (int) $conf->entity . ")",
        "ksef_number = '" . $db->escape($mark) . "'",
        "fetch_date = fetch_date + 1",
    );
    $db->begin();
    foreach ($probes as $table => $p) {
        if (!$db->query($p[0])) {
            $out = array('ok' => false, 'step' => 'insert', 'table' => $table, 'error' => $db->lasterror());
            break;
        }
        if (ksefDbCheckCount($db, "SELECT COUNT(*) AS n FROM " . $table . " WHERE " . $p[1]) < 1) {
            $out = array('ok' => false, 'step' => 'select', 'table' => $table, 'error' => $db->lasterror());
            break;
        }
        if (!$db->query("UPDATE " . $table . " SET " . $p[2] . " WHERE " . $p[1]) || $db->affected_rows(true) < 1) {
            $out = array('ok' => false, 'step' => 'update', 'table' => $table, 'error' => $db->lasterror());
            break;
        }
        if (!$db->query("DELETE FROM " . $table . " WHERE " . $p[1]) || $db->affected_rows(true) < 1) {
            $out = array('ok' => false, 'step' => 'delete', 'table' => $table, 'error' => $db->lasterror());
            break;
        }
    }
    $db->rollback();
    return $out;
}

/**
 * @brief Module table collation
 * @param DoliDB $db
 * @return array cs, co ('' on PostgreSQL)
 */
function ksefDbCharsetTarget($db)
{
    $out = array('cs' => '', 'co' => '');
    if ($db->type == 'pgsql') {
        return $out;
    }
    $res = $db->query("SELECT DEFAULT_CHARACTER_SET_NAME AS cs, DEFAULT_COLLATION_NAME AS co FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()");
    $d = $res ? $db->fetch_object($res) : null;
    $res = $db->query("SELECT c.CHARACTER_SET_NAME AS cs, t.TABLE_COLLATION AS co FROM information_schema.TABLES t"
        . " LEFT JOIN information_schema.COLLATIONS c ON c.COLLATION_NAME = t.TABLE_COLLATION"
        . " WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = '" . $db->escape(MAIN_DB_PREFIX . "facture") . "'");
    $f = $res ? $db->fetch_object($res) : null;
    if ($f && (string) $f->co !== '' && (string) $f->cs === '') {
        $f->cs = preg_replace('/_.*$/', '', (string) $f->co);
    }
    $dbMb4 = $d && strpos((string) $d->cs, 'utf8mb4') === 0;
    // llx_facture, else default
    if ($f && (string) $f->co !== '' && (!$dbMb4 || strpos((string) $f->cs, 'utf8mb4') === 0)) {
        return array('cs' => (string) $f->cs, 'co' => (string) $f->co);
    }
    return $d ? array('cs' => (string) $d->cs, 'co' => (string) $d->co) : $out;
}

/**
 * @brief Other collation tables
 * @param DoliDB $db
 * @return array
 */
function ksefDbHealthCharset($db)
{
    $out = array();
    $target = ksefDbCharsetTarget($db);
    if ($target['co'] === '') {
        return $out;
    }
    $toMb4 = strpos($target['cs'], 'utf8mb4') === 0;
    $names = array_map(function ($table) use ($db) {
        return "'" . $db->escape(ksefDbCheckTableName($table)) . "'";
    }, array_keys(ksefDbCheckSchema()));
    $res = $db->query("SELECT TABLE_NAME AS t, TABLE_COLLATION AS co FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()"
        . " AND TABLE_NAME IN (" . implode(', ', $names) . ")");
    while ($res && ($t = $db->fetch_object($res))) {
        $isMb4 = strpos((string) $t->co, 'utf8mb4') === 0;
        // never drop utf8mb4
        if ((string) $t->co === '' || (string) $t->co === $target['co'] || ($isMb4 && !$toMb4)) {
            continue;
        }
        $out[(string) $t->t] = array('collation' => (string) $t->co, 'kind' => ($toMb4 && !$isMb4) ? 'charset' : 'collation',
            'sql' => 'ALTER TABLE ' . $t->t . ' CONVERT TO CHARACTER SET ' . $target['cs'] . ' COLLATE ' . $target['co'] . ';', 'target' => $target['co'], 'cs' => $target['cs']);
    }
    return $out;
}

/**
 * @brief Copied sale dates
 * @param DoliDB $db
 * @param int $onlyId Only this draft
 * @param bool $preUpgrade Pre-1.5.0 only
 * @return array
 */
function ksefDbCheckStaleSaleDates($db, $onlyId = 0, $preUpgrade = false)
{
    $since = $preUpgrade ? ksefDbCheckUpgradeMoment() : 0;
    $out = array();
    $ef = MAIN_DB_PREFIX . 'facture_extrafields';
    if (!ksefDbCheckTableExists($db, $ef) || !isset(ksefDbCheckLiveColumns($db, $ef)['ksef_sale_date'])) {
        return $out;
    }
    $sql = "SELECT f.rowid, f.ref FROM " . MAIN_DB_PREFIX . "facture AS f INNER JOIN " . $ef . " AS fe ON fe.fk_object = f.rowid"
        . " WHERE f.fk_statut = 0 AND f.entity IN (" . getEntity('invoice') . ") AND fe.ksef_sale_date IS NOT NULL AND DATE(fe.ksef_sale_date) = DATE(f.datef)"
        . ($onlyId > 0 ? " AND f.rowid = " . (int) $onlyId : "")
        . ($since > 0 ? " AND f.datec < '" . $db->idate($since) . "'" : "")
        . " AND NOT EXISTS (SELECT 1 FROM " . MAIN_DB_PREFIX . "element_element AS ee WHERE ee.fk_target = f.rowid AND ee.targettype = 'facture'"
        . " AND ee.sourcetype IN ('commande', 'shipping'))"
        . " ORDER BY f.rowid";
    $resql = $db->query($sql);
    while ($resql && ($o = $db->fetch_object($resql))) {
        $out[(int) $o->rowid] = (string) $o->ref;
    }
    return $out;
}

/**
 * @brief Invoice card URL
 * @param int $id Invoice ID
 * @param string $element
 * @return string
 */
function ksefDbCheckInvoiceUrl($id, $element)
{
    return DOL_URL_ROOT . ($element === 'invoice_supplier' ? '/fourn/facture/card.php?facid=' : '/compta/facture/card.php?id=') . (int) $id;
}

/**
 * @brief Invoice refs, statuses
 * @param DoliDB $db
 * @param array $ids Invoice IDs
 * @param string $element
 * @return array
 */
function ksefDbCheckInvoiceRefs($db, $ids, $element)
{
    $out = array();
    $ids = array_filter(array_map('intval', (array) $ids));
    if (!$ids) {
        return $out;
    }
    $res = $db->query("SELECT rowid, ref, fk_statut FROM " . MAIN_DB_PREFIX . ksefInvoiceKind($element)['table'] . " WHERE rowid IN (" . implode(',', $ids) . ") ORDER BY rowid");
    while ($res && ($o = $db->fetch_object($res))) {
        $out[(int) $o->rowid] = array('ref' => (string) $o->ref, 'status' => (int) $o->fk_statut);
    }
    return $out;
}

/**
 * @brief Deposit restate dry-run
 * @param DoliDB $db
 * @param User $user
 * @param int $depositId
 * @param string $element
 * @return array
 */
function ksefDbCheckDepositPlan($db, $user, $depositId, $element)
{
    $plan = ksefDepositRestate($db, $user, $depositId, 0, true, 0, $element, true);
    $t = ksefDepositCreditTarget($db, $depositId, 0, 0, $element);
    $out = array('plan' => $plan, 'changes' => count($plan['plan']), 'held' => array(), 'locked' => array(), 'safe' => false,
        'credit' => 0.0, 'target' => 0.0, 'value' => 0.0, 'received' => 0.0, 'currency' => '');
    if (!$t || $plan['result'] < 0) {
        $out['changes'] = 0;
        return $out;
    }
    $K = $t['key'];
    foreach (ksefDepositRows($db, $depositId, $element) as $r) {
        $out['credit'] += $r[$K];
    }
    $out['credit'] = (float) price2num($out['credit'], 'MT');
    $out['target'] = $t['target'][$K];
    $out['value'] = $t[$K];
    $out['received'] = $t['received'][$K];
    $out['currency'] = $t['foreign'] ? $t['currency'] : '';
    foreach (ksefDbCheckInvoiceRefs($db, $plan['touched'], $element) as $id => $inv) {
        if ($inv['status'] !== 0) {
            $out['held'][$id] = $inv['ref'];
        }
    }
    foreach (ksefDbCheckInvoiceRefs($db, $plan['locked'], $element) as $id => $inv) {
        $out['locked'][$id] = $inv['ref'];
    }
    $out['safe'] = $out['changes'] > 0 && !$out['held'];
    return $out;
}

/**
 * @brief Deposit paid korekta
 * @param DoliDB $db
 * @param int $depositId
 * @param string $element
 * @return bool
 */
function ksefDbCheckDepositPaidAtKorekta($db, $depositId, $element)
{
    $T = MAIN_DB_PREFIX . ksefInvoiceKind($element)['table'];
    $res = $db->query("SELECT MIN(rowid) AS repl FROM " . $T . " WHERE fk_facture_source = " . (int) $depositId . " AND type = 1 AND fk_statut IN (1, 2)");
    $o = $res ? $db->fetch_object($res) : null;
    return $o && (int) $o->repl > 0 && (int) (ksefDepositState($db, (int) $o->repl, $element)['src']['paye'] ?? 0) === 1;
}

/**
 * @brief Deposit repair step
 * @param DoliDB $db
 * @param User $user
 * @param array $r Repair step
 * @return array
 */
function ksefDbCheckRepairDeposit($db, $user, $r)
{
    global $langs;
    dol_include_once('/ksef/lib/ksef.lib.php');
    $element = $r['element'] === 'invoice_supplier' ? 'invoice_supplier' : 'facture';
    $res = array('result' => 'ok', 'detail' => '');
    $skip = function ($detail) use ($db, &$res) {
        $db->commit();
        $res['result'] = 'skipped';
        $res['detail'] = $detail;
        return $res;
    };
    $fail = function ($what) use ($db, &$res) {
        $db->rollback();
        $res['result'] = 'failed';
        $res['detail'] = $what;
        return $res;
    };
    $db->begin();
    if ($r['op'] === 'deposit_restate') {
        $depId = (int) $r['deposit'];
        $p = ksefDbCheckDepositPlan($db, $user, $depId, $element);
        if (!$p['safe']) {
            if ($p['held']) {
                return $skip($langs->transnoentitiesnoconv('KSEF_DbP_DepositCreditHeld', implode(', ', $p['held'])));
            }
            return $skip($langs->transnoentitiesnoconv($p['changes'] === 0 ? 'KSEF_DbR_DepositNothingLeft' : 'KSEF_DbR_DepositChanged'));
        }
        $rs = ksefDepositRestate($db, $user, $depId, 0, true, 0, $element);
        if ($rs['result'] < 0) {
            return $fail($rs['error']);
        }
        $after = ksefDbCheckDepositPlan($db, $user, $depId, $element);
        $db->commit();
        $cur = $after['currency'] !== '' ? $after['currency'] : 'auto';
        $res['detail'] = $langs->transnoentitiesnoconv('KSEF_DbR_DepositRestated', price($p['credit'], 0, $langs, 1, -1, -1, $cur), price($after['credit'], 0, $langs, 1, -1, -1, $cur),
            $p['changes'] - count($rs['created']), count($rs['created']));
        return $res;
    }
    return $skip('');
}

/**
 * @brief Repair backup rows
 * @param DoliDB $db
 * @param User $user
 * @param array $problems Findings
 * @return array
 */
function ksefDbCheckRepairBackupSpec($db, $user, $problems)
{
    dol_include_once('/ksef/lib/ksef.lib.php');
    $P = MAIN_DB_PREFIX;
    $spec = array();
    $add = function ($table, $ids, $scope = '') use (&$spec) {
        if (!isset($spec[$table])) {
            $spec[$table] = array('ids' => array(), 'scope' => array());
        }
        foreach (array_filter(array_map('intval', (array) $ids)) as $id) {
            if ($scope !== '') {
                $spec[$table]['scope'][$scope][$id] = $id;
            } else {
                $spec[$table]['ids'][$id] = $id;
            }
        }
    };
    $ids = function ($sql) use ($db) {
        $out = array();
        $res = $db->query($sql);
        while ($res && ($o = $db->fetch_object($res))) {
            $out[] = (int) $o->rowid;
        }
        return $out;
    };
    foreach ($problems as $p) {
        $r = isset($p['repair']) ? $p['repair'] : null;
        if (!empty($r['op']) && $r['op'] === 'clear_sale_dates') {
            $drafts = array_filter(array_map('intval', (array) $r['ids']));
            if ($drafts) {
                $add('facture_extrafields', $ids("SELECT rowid FROM " . $P . "facture_extrafields WHERE fk_object IN (" . implode(',', $drafts) . ")"));
            }
            continue;
        }
        if (!empty($r['op']) && ($r['op'] === 'import_totals' || $r['op'] === 'import_rate' || $r['op'] === 'foreign_pln' || $r['op'] === 'line_vat' || $r['op'] === 'draft_currency')) {
            $k = ksefInvoiceKind($r['element'] === 'invoice_supplier' ? 'invoice_supplier' : 'facture');
            foreach ($r['op'] === 'import_rate' ? (array) $r['ids'] : array($r['id']) as $iid) {
                $iid = (int) $iid;
                $add($k['table'], array($iid));
                if (!in_array($r['op'], array('foreign_pln', 'line_vat', 'draft_currency'), true)) {
                    $spec[$k['table']]['amounts'][$iid] = $iid;
                }
                $add($k['extrafields'] . '_extrafields', array($iid), 'fk_object');
                $add($k['line_table'], $ids("SELECT rowid FROM " . $P . $k['line_table'] . " WHERE " . $k['line_fk'] . " = " . $iid));
            }
            continue;
        }
        if (empty($r['op']) || $r['op'] !== 'deposit_restate') {
            continue;
        }
        $element = $r['element'] === 'invoice_supplier' ? 'invoice_supplier' : 'facture';
        $k = ksefInvoiceKind($element);
        $depId = (int) $r['deposit'];
        $add($k['extrafields'] . '_extrafields', array($depId), 'fk_object');
        $invoices = array($depId);
        $add('societe_remise_except', array($depId), $k['col_source']);
        foreach (ksefDepositRows($db, $depId, $element) as $row) {
            $invoices[] = $row['inv'];
        }
        $plan = ksefDepositRestate($db, $user, $depId, 0, true, 0, $element, true);
        $invoices = array_merge($invoices, (array) $plan['touched']);
        $invoices = array_values(array_unique(array_filter(array_map('intval', $invoices))));
        $add($k['table'], $invoices);
        $add($k['extrafields'] . '_extrafields', $invoices, 'fk_object');
        $lines = $ids("SELECT rowid FROM " . $P . $k['line_table'] . " WHERE " . $k['line_fk'] . " IN (" . implode(',', $invoices) . ")");
        $add($k['line_table'], $lines);
        $add('societe_remise_except', $ids("SELECT rowid FROM " . $P . "societe_remise_except WHERE " . $k['col'] . " IN (" . implode(',', $invoices) . ")"
            . ($lines ? " OR " . $k['col_line'] . " IN (" . implode(',', $lines) . ")" : "")));
    }
    foreach ($spec as $table => $s) {
        $spec[$table]['ids'] = array_values($s['ids']);
        if (isset($s['amounts'])) {
            $spec[$table]['amounts'] = array_values($s['amounts']);
        }
        foreach ($s['scope'] as $col => $v) {
            $spec[$table]['scope'][$col] = array_values($v);
        }
    }
    return $spec;
}

if (!defined('KSEF_DB_BACKUP_MAGIC')) {
    define('KSEF_DB_BACKUP_MAGIC', 'KSEF-MODULE-BACKUP');
    // 2: adds rows
    define('KSEF_DB_BACKUP_FORMAT', 2);
    define('KSEF_DB_BACKUP_KEEP', 10);
    define('KSEF_DB_BACKUP_MAX_UPLOAD', 200 * 1024 * 1024);
    define('KSEF_DB_BACKUP_MAX_UNZIPPED', 2048 * 1024 * 1024);
    define('KSEF_DB_BACKUP_MAX_LINE', 64 * 1024 * 1024);
}

/**
 * @brief Backup directory
 * @return string
 */
function ksefDbBackupDir()
{
    return DOL_DATA_ROOT . '/ksef_backup';
}

/**
 * @brief Backup supported
 * @param DoliDB $db
 * @return bool
 */
function ksefDbBackupSupported($db)
{
    return strpos((string) $db->type, 'mysql') === 0;
}

/**
 * @brief Is backup name
 * @param string $name File name
 * @return bool
 */
function ksefDbBackupFileOk($name)
{
    return is_string($name) && preg_match('/^ksef-backup-\d{8}-\d{6}-[0-9a-f]{6}(-upload)?\.jsonl\.gz$/', $name) === 1;
}

/**
 * @brief New backup name
 * @param string $suffix '' or '-upload'
 * @return string
 */
function ksefDbBackupNewName($suffix)
{
    do {
        list($usec, $sec) = explode(' ', microtime());
        $name = 'ksef-backup-' . gmdate('Ymd-His', (int) $sec) . '-' . sprintf('%06d', (int) round($usec * 999999)) . $suffix . '.jsonl.gz';
    } while (file_exists(ksefDbBackupDir() . '/' . $name));
    return $name;
}

/**
 * @brief Backup error text
 * @param string $code
 * @return string
 */
function ksefDbBackupErr($code)
{
    global $langs;
    $langs->loadLangs(array('ksef@ksef'));
    $args = func_get_args();
    $args[0] = 'KSEF_DbBkErr_' . $code;
    return call_user_func_array(array($langs, 'transnoentitiesnoconv'), $args);
}

/**
 * @brief Encode backup value
 * @param mixed $v
 * @return mixed
 */
function ksefDbBackupEncodeValue($v)
{
    if ($v === null) {
        return null;
    }
    $v = (string) $v;
    return preg_match('//u', $v) ? $v : array('b' => base64_encode($v));
}

/**
 * @brief Decode backup value
 * @param mixed $v
 * @param bool $ok False if invalid
 * @return string|null
 */
function ksefDbBackupDecodeValue($v, &$ok)
{
    if ($v === null || is_string($v)) {
        return $v;
    }
    if (is_array($v) && count($v) == 1 && isset($v['b']) && is_string($v['b'])) {
        $raw = base64_decode($v['b'], true);
        if ($raw !== false) {
            return $raw;
        }
    }
    $ok = false;
    return null;
}

/**
 * @brief Backup core tables
 * @return array
 */
function ksefDbBackupCoreTables()
{
    $credit = "description = '(DEPOSIT)'";
    $head = array('total_ht', 'total_tva', 'total_ttc', 'multicurrency_tx', 'multicurrency_total_ht', 'multicurrency_total_tva', 'multicurrency_total_ttc');
    $line = array('total_ht', 'total_ttc', 'multicurrency_subprice', 'multicurrency_total_ht', 'multicurrency_total_tva', 'multicurrency_total_ttc');
    $ef = array('ksef_correction_state', 'ksef_sale_date');
    return array(
        'societe_remise_except' => array('mode' => 'upsert', 'scope' => array('fk_facture_source', 'fk_invoice_supplier_source'), 'where' => $credit, 'reset' => array(),
            'write' => array('amount_ht', 'amount_tva', 'amount_ttc', 'multicurrency_amount_ht', 'multicurrency_amount_tva', 'multicurrency_amount_ttc', 'fk_facture_source', 'fk_invoice_supplier_source')),
        'facture' => array('mode' => 'update', 'scope' => array(), 'where' => '', 'reset' => array(), 'write' => $head),
        'facture_fourn' => array('mode' => 'update', 'scope' => array(), 'where' => '', 'reset' => array(), 'write' => array_merge($head, array('tva'))),
        'facturedet' => array('mode' => 'update', 'scope' => array(), 'where' => '', 'reset' => array(), 'write' => array_merge($line, array('subprice', 'total_tva'))),
        'facture_fourn_det' => array('mode' => 'update', 'scope' => array(), 'where' => '', 'reset' => array(), 'write' => array_merge($line, array('pu_ht', 'pu_ttc', 'tva'))),
        'facture_extrafields' => array('mode' => 'update', 'scope' => array('fk_object'), 'where' => '', 'reset' => array('ksef_correction_state'), 'write' => $ef),
        'facture_fourn_extrafields' => array('mode' => 'update', 'scope' => array('fk_object'), 'where' => '', 'reset' => array('ksef_correction_state'), 'write' => $ef),
    );
}

/**
 * @brief Core rows condition
 * @param string $table Unprefixed table
 * @param array $ids Row ids
 * @param array $scope column => ids
 * @return string '0' when nothing
 */
function ksefDbBackupCoreWhere($table, $ids, $scope)
{
    $def = ksefDbBackupCoreTables()[$table];
    $or = array();
    $ids = array_filter(array_map('intval', (array) $ids));
    if ($ids) {
        $or[] = "rowid IN (" . implode(',', $ids) . ")";
    }
    foreach ((array) $scope as $col => $v) {
        $v = array_filter(array_map('intval', (array) $v));
        if ($v && in_array($col, $def['scope'], true)) {
            $or[] = "(" . $col . " IN (" . implode(',', $v) . ")" . ($def['where'] !== '' ? " AND " . $def['where'] : "") . ")";
        }
    }
    return $or ? implode(' OR ', $or) : '0';
}

/**
 * @brief Write backup
 * @param DoliDB $db
 * @param User $user
 * @param string $reason Reason code
 * @param string $detail Reason detail
 * @param array $options tolerate, protect, core
 * @return array
 */
function ksefDbBackupWrite($db, $user, $reason, $detail = '', $options = array())
{
    dol_include_once('/ksef/lib/ksef.lib.php');
    $out = array('ok' => false, 'file' => '', 'rows' => 0, 'error' => '');
    $who = is_object($user) ? (string) $user->login : '';
    if (!ksefDbBackupSupported($db)) {
        $out['error'] = ksefDbBackupErr('mysql');
        return $out;
    }
    $dir = ksefDbBackupDir();
    if (!is_dir($dir) && !file_exists($dir)) {
        dol_mkdir($dir);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        $out['error'] = ksefDbBackupErr('write', $dir);
        dol_syslog('ksefDbBackupWrite by ' . $who . ': ' . $out['error'], LOG_ERR);
        return $out;
    }
    if (!file_exists($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    }
    $tolerate = isset($options['tolerate']) ? (array) $options['tolerate'] : array();
    $name = ksefDbBackupNewName('');
    $tmp = $dir . '/.' . $name . '.tmp';

    $db->begin();
    $tables = array();
    $unreadable = array();
    foreach (ksefDbCheckSchema() as $table => $def) {
        $full = ksefDbCheckTableName($table);
        if (!ksefDbCheckTableExists($db, $full)) {
            continue;
        }
        $cols = array_keys(ksefDbCheckLiveColumns($db, $full));
        $resql = $cols ? $db->query("SELECT COUNT(*) AS n FROM " . $full) : false;
        $obj = $resql ? $db->fetch_object($resql) : null;
        if (!$obj) {
            if (in_array($table, $tolerate, true)) {
                $unreadable[] = $table;
                continue;
            }
            $out['error'] = ksefDbBackupErr('read', $full, $db->lasterror());
            $db->rollback();
            dol_syslog('ksefDbBackupWrite by ' . $who . ': ' . $out['error'], LOG_ERR);
            return $out;
        }
        $tables[$table] = array('columns' => $cols, 'rows' => (int) $obj->n);
    }
    $core = array();
    $coreRows = array();
    $coreDefs = ksefDbBackupCoreTables();
    foreach (isset($options['core']) ? (array) $options['core'] : array() as $table => $spec) {
        $full = ksefDbCheckTableName($table);
        if (!isset($coreDefs[$table]) || !ksefDbCheckTableExists($db, $full)) {
            continue;
        }
        $ids = isset($spec['ids']) ? $spec['ids'] : array();
        $scope = array();
        foreach (isset($spec['scope']) ? (array) $spec['scope'] : array() as $col => $v) {
            if (in_array($col, $coreDefs[$table]['scope'], true)) {
                $scope[$col] = array_values(array_filter(array_map('intval', (array) $v)));
            }
        }
        $where = ksefDbBackupCoreWhere($table, $ids, $scope);
        if ($where === '0') {
            continue;
        }
        $cols = array_keys(ksefDbCheckLiveColumns($db, $full));
        $resql = $db->query("SELECT * FROM " . $full . " WHERE " . $where . " ORDER BY rowid");
        if (!$resql) {
            $out['error'] = ksefDbBackupErr('read', $full, $db->lasterror());
            $db->rollback();
            dol_syslog('ksefDbBackupWrite by ' . $who . ': ' . $out['error'], LOG_ERR);
            return $out;
        }
        $coreRows[$table] = array();
        $found = array();
        while ($row = $db->fetch_array($resql)) {
            $vals = array();
            foreach ($cols as $c) {
                $vals[] = ksefDbBackupEncodeValue(array_key_exists($c, $row) ? $row[$c] : null);
            }
            $coreRows[$table][] = $vals;
            $found[] = (int) $row['rowid'];
        }
        $db->free($resql);
        $core[$table] = array('columns' => $cols, 'rows' => count($found), 'ids' => $found, 'scope' => $scope);
        $amounts = array_values(array_intersect($found, array_map('intval', isset($spec['amounts']) ? (array) $spec['amounts'] : array())));
        if ($amounts) {
            $core[$table]['amounts'] = $amounts;
        }
    }
    $header = array(
        'magic' => KSEF_DB_BACKUP_MAGIC,
        'format' => $core ? 2 : 1,
        'module_version' => (string) ksefGetModuleVersion($db),
        'migration_version' => getDolGlobalString('KSEF_MIGRATION_VERSION'),
        'db_type' => (string) $db->type,
        'created_at' => (int) dol_now(),
        'created_by' => $who,
        'reason' => (string) $reason,
        'detail' => (string) $detail,
        'tables' => $tables,
        'unreadable' => $unreadable,
    );
    if ($core) {
        $header['core'] = $core;
    }
    $fh = @gzopen($tmp, 'wb6');
    if (!$fh || gzwrite($fh, json_encode($header, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n") === false) {
        if ($fh) {
            gzclose($fh);
        }
        @unlink($tmp);
        $db->rollback();
        $out['error'] = ksefDbBackupErr('write', $dir);
        dol_syslog('ksefDbBackupWrite by ' . $who . ': ' . $out['error'], LOG_ERR);
        return $out;
    }
    $error = '';
    $total = 0;
    foreach ($tables as $table => $th) {
        $full = ksefDbCheckTableName($table);
        $hasRowid = in_array('rowid', $th['columns'], true);
        $written = 0;
        $last = 0;
        $offset = 0;
        $chunk = 200;
        while ($error === '') {
            $sql = "SELECT * FROM " . $full . ($hasRowid ? " WHERE rowid > " . (int) $last . " ORDER BY rowid" : "") . " LIMIT " . $chunk . ($hasRowid ? "" : " OFFSET " . $offset);
            $resql = $db->query($sql);
            if (!$resql) {
                $error = ksefDbBackupErr('read', $full, $db->lasterror());
                break;
            }
            $n = 0;
            while ($row = $db->fetch_array($resql)) {
                $n++;
                $vals = array();
                foreach ($th['columns'] as $c) {
                    $vals[] = ksefDbBackupEncodeValue(array_key_exists($c, $row) ? $row[$c] : null);
                }
                if (gzwrite($fh, json_encode(array('t' => $table, 'r' => $vals), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n") === false) {
                    $error = ksefDbBackupErr('write', $dir);
                    break;
                }
                $written++;
                if ($hasRowid) {
                    $last = (int) $row['rowid'];
                }
            }
            $db->free($resql);
            if ($n < $chunk) {
                break;
            }
            $offset += $chunk;
        }
        if ($error === '' && $written != $th['rows']) {
            $error = ksefDbBackupErr('changed', $full);
        }
        $total += $written;
    }
    foreach ($coreRows as $table => $rows) {
        foreach ($rows as $vals) {
            if ($error === '' && gzwrite($fh, json_encode(array('c' => $table, 'r' => $vals), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n") === false) {
                $error = ksefDbBackupErr('write', $dir);
            }
            $total++;
        }
    }
    if ($error === '' && gzwrite($fh, json_encode(array('end' => 1, 'rows' => $total)) . "\n") === false) {
        $error = ksefDbBackupErr('write', $dir);
    }
    $closed = gzclose($fh);
    $db->commit();
    if ($error === '' && (!$closed || !@rename($tmp, $dir . '/' . $name))) {
        $error = ksefDbBackupErr('write', $dir);
    }
    if ($error !== '') {
        @unlink($tmp);
        $out['error'] = $error;
        dol_syslog('ksefDbBackupWrite by ' . $who . ' (' . $reason . '): failed: ' . $error, LOG_ERR);
        return $out;
    }
    dolChmod($dir . '/' . $name);
    ksefDbBackupPrune(KSEF_DB_BACKUP_KEEP, isset($options['protect']) ? (string) $options['protect'] : '');
    $out['ok'] = true;
    $out['file'] = $name;
    $out['rows'] = $total;
    dol_syslog('ksefDbBackupWrite by ' . $who . ' (' . $reason . ($detail !== '' ? ' ' . $detail : '') . '): ' . $name . ', ' . count($tables) . ' tables, ' . $total . ' rows'
        . ($core ? ', Dolibarr rows: ' . json_encode(array_map(function ($c) {
            return $c['rows'];
        }, $core)) : '')
        . ($unreadable ? ', unreadable left out: ' . implode(', ', $unreadable) : ''), LOG_WARNING);
    return $out;
}

/**
 * @brief Backup before change
 * @param DoliDB $db
 * @param User $user
 * @param string $reason Reason code
 * @param string $detail Reason detail
 * @param array $tolerate Skippable tables
 * @param array $core Dolibarr rows
 * @return array
 */
function ksefDbBackupBefore($db, $user, $reason, $detail = '', $tolerate = array(), $core = array())
{
    global $langs;
    if (!ksefDbBackupSupported($db)) {
        dol_syslog('ksefDbBackupBefore: no backup on ' . $db->type . ' before ' . $reason, LOG_WARNING);
        return array('ok' => true, 'file' => '', 'rows' => 0, 'error' => '');
    }
    $b = ksefDbBackupWrite($db, $user, $reason, $detail, array('tolerate' => $tolerate, 'core' => $core));
    if (!$b['ok']) {
        $langs->loadLangs(array('ksef@ksef'));
        $b['error'] = $langs->transnoentitiesnoconv('KSEF_DbBkFailed', $b['error']);
    }
    return $b;
}

/**
 * @brief Prune old backups
 * @param int $keep Backups kept
 * @param string $protect Never deleted
 * @return int Files deleted
 */
function ksefDbBackupPrune($keep, $protect = '')
{
    $names = array();
    $found = glob(ksefDbBackupDir() . '/ksef-backup-*.jsonl.gz');
    foreach (is_array($found) ? $found : array() as $path) {
        if (ksefDbBackupFileOk(basename($path))) {
            $names[] = basename($path);
        }
    }
    sort($names);
    $n = 0;
    $left = count($names);
    foreach ($names as $name) {
        if ($left <= $keep) {
            break;
        }
        if ($name === $protect) {
            continue;
        }
        if (@unlink(ksefDbBackupDir() . '/' . $name)) {
            $n++;
            $left--;
            dol_syslog('ksefDbBackupPrune: ' . $name . ' deleted (keep ' . $keep . ')', LOG_INFO);
        }
    }
    return $n;
}

/**
 * @brief Read capped line
 * @param resource $fh gzopen() handle
 * @param int $unzipped Bytes read
 * @return string|false|null null when too long
 */
function ksefDbBackupReadLine($fh, &$unzipped)
{
    $line = gzgets($fh, KSEF_DB_BACKUP_MAX_LINE + 2);
    if ($line === false || $line === '') {
        return false;
    }
    $unzipped += strlen($line);
    if ($unzipped > KSEF_DB_BACKUP_MAX_UNZIPPED || (substr($line, -1) !== "\n" && !gzeof($fh))) {
        return null;
    }
    return rtrim($line, "\n");
}

/**
 * @brief Check backup header
 * @param mixed $h Decoded header
 * @return string '' when fine
 */
function ksefDbBackupCheckHeader($h)
{
    if (!is_array($h) || !isset($h['magic']) || $h['magic'] !== KSEF_DB_BACKUP_MAGIC || !isset($h['format']) || !is_int($h['format']) || $h['format'] < 1) {
        return ksefDbBackupErr('header');
    }
    if ($h['format'] > KSEF_DB_BACKUP_FORMAT) {
        return ksefDbBackupErr('format', $h['format']);
    }
    if (!isset($h['created_at']) || !is_int($h['created_at']) || !isset($h['tables']) || !is_array($h['tables']) || !$h['tables']) {
        return ksefDbBackupErr('header');
    }
    $schema = ksefDbCheckSchema();
    foreach ($h['tables'] as $table => $th) {
        if (!is_string($table) || !isset($schema[$table])) {
            return ksefDbBackupErr('table', preg_replace('/[^A-Za-z0-9_]/', '?', dol_trunc((string) $table, 64, 'right', 'UTF-8', 1)));
        }
        if (!is_array($th) || !isset($th['columns'], $th['rows']) || !is_array($th['columns']) || !$th['columns'] || !is_int($th['rows']) || $th['rows'] < 0
            || array_values($th['columns']) !== $th['columns'] || count(array_unique($th['columns'])) != count($th['columns'])) {
            return ksefDbBackupErr('header');
        }
        foreach ($th['columns'] as $c) {
            if (!is_string($c) || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $c)) {
                return ksefDbBackupErr('header');
            }
        }
    }
    foreach (array('module_version', 'migration_version', 'db_type', 'created_by', 'reason', 'detail') as $k) {
        if (isset($h[$k]) && !is_string($h[$k])) {
            return ksefDbBackupErr('header');
        }
    }
    if (!isset($h['core'])) {
        return '';
    }
    $coreDefs = ksefDbBackupCoreTables();
    if ($h['format'] < 2 || !is_array($h['core'])) {
        return ksefDbBackupErr('header');
    }
    foreach ($h['core'] as $table => $th) {
        if (!is_string($table) || !isset($coreDefs[$table])) {
            return ksefDbBackupErr('table', preg_replace('/[^A-Za-z0-9_]/', '?', dol_trunc((string) $table, 64, 'right', 'UTF-8', 1)));
        }
        if (!is_array($th) || !isset($th['columns'], $th['rows'], $th['ids']) || !is_array($th['columns']) || !in_array('rowid', $th['columns'], true) || !is_int($th['rows'])
            || !is_array($th['ids']) || count($th['ids']) !== $th['rows'] || array_values($th['columns']) !== $th['columns'] || count(array_unique($th['columns'])) != count($th['columns'])
            || (isset($th['scope']) && !is_array($th['scope']))
            || (isset($th['amounts']) && (!is_array($th['amounts']) || !in_array($table, array('facture', 'facture_fourn'), true)
                || count(array_filter($th['amounts'], 'is_int')) !== count($th['amounts']) || array_diff($th['amounts'], $th['ids'])))) {
            return ksefDbBackupErr('header');
        }
        foreach ($th['columns'] as $c) {
            if (!is_string($c) || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $c)) {
                return ksefDbBackupErr('header');
            }
        }
        foreach ($th['ids'] as $id) {
            if (!is_int($id) || $id <= 0) {
                return ksefDbBackupErr('header');
            }
        }
        foreach (isset($th['scope']) ? $th['scope'] : array() as $col => $v) {
            if (!in_array($col, $coreDefs[$table]['scope'], true) || !is_array($v)) {
                return ksefDbBackupErr('header');
            }
            foreach ($v as $id) {
                if (!is_int($id) || $id <= 0) {
                    return ksefDbBackupErr('header');
                }
            }
        }
    }
    return '';
}

/**
 * @brief Read backup file
 * @param string $path
 * @param callable|null $onRow Row callback
 * @return array
 */
function ksefDbBackupScan($path, $onRow = null)
{
    $out = array('ok' => false, 'error' => '', 'header' => null);
    if (@file_get_contents($path, false, null, 0, 2) !== "\x1f\x8b" || !($fh = @gzopen($path, 'rb'))) {
        $out['error'] = ksefDbBackupErr('gzip');
        return $out;
    }
    $unzipped = 0;
    $line = ksefDbBackupReadLine($fh, $unzipped);
    $h = is_string($line) ? json_decode($line, true, 64) : null;
    $err = ksefDbBackupCheckHeader($h);
    if ($err !== '') {
        gzclose($fh);
        $out['error'] = $err;
        return $out;
    }
    $counts = array_fill_keys(array_keys($h['tables']), 0);
    $core = isset($h['core']) ? $h['core'] : array();
    $coreCounts = array_fill_keys(array_keys($core), 0);
    $coreIds = array();
    foreach ($core as $table => $th) {
        $coreIds[$table] = array_flip($th['ids']);
    }
    $ended = false;
    $lineNo = 1;
    while (($line = ksefDbBackupReadLine($fh, $unzipped)) !== false) {
        $lineNo++;
        if ($line === null) {
            $err = ksefDbBackupErr('size');
            break;
        }
        $row = $ended ? null : json_decode($line, true, 8);
        if (is_array($row) && isset($row['end'])) {
            $ended = true;
            if (!isset($row['rows']) || $row['rows'] !== array_sum($counts) + array_sum($coreCounts)) {
                $err = ksefDbBackupErr('row', $lineNo);
                break;
            }
            continue;
        }
        $isCore = is_array($row) && isset($row['c']) && !isset($row['t']);
        $table = is_array($row) ? ($isCore ? $row['c'] : ($row['t'] ?? null)) : null;
        $def = !is_string($table) ? null : ($isCore ? ($core[$table] ?? null) : ($h['tables'][$table] ?? null));
        if (!$def || !isset($row['r']) || !is_array($row['r']) || array_values($row['r']) !== $row['r'] || count($row['r']) != count($def['columns'])) {
            $err = ksefDbBackupErr('row', $lineNo);
            break;
        }
        $ok = true;
        $vals = array();
        foreach ($row['r'] as $v) {
            $vals[] = ksefDbBackupDecodeValue($v, $ok);
        }
        if ($isCore && $ok) {
            $rid = $vals[array_search('rowid', $def['columns'], true)];
            $ok = is_string($rid) && ctype_digit($rid) && isset($coreIds[$table][(int) $rid]);
        }
        if (!$ok) {
            $err = ksefDbBackupErr('row', $lineNo);
            break;
        }
        if ($isCore) {
            $coreCounts[$table]++;
        } else {
            $counts[$table]++;
        }
        if ($onRow !== null) {
            $err = (string) call_user_func($onRow, $table, $vals, $isCore);
            if ($err !== '') {
                break;
            }
        }
    }
    gzclose($fh);
    if ($err === '' && !$ended) {
        $err = ksefDbBackupErr('end');
    }
    if ($err === '') {
        foreach ($h['tables'] as $table => $th) {
            if ($counts[$table] != $th['rows']) {
                $err = ksefDbBackupErr('count', ksefDbCheckTableName($table), $th['rows'], $counts[$table]);
                break;
            }
        }
        foreach ($core as $table => $th) {
            if ($err === '' && $coreCounts[$table] != $th['rows']) {
                $err = ksefDbBackupErr('count', ksefDbCheckTableName($table), $th['rows'], $coreCounts[$table]);
            }
        }
    }
    $out['header'] = $h;
    $out['error'] = $err;
    $out['ok'] = ($err === '');
    return $out;
}

/**
 * @brief Read backup header
 * @param string $path
 * @return array|null
 */
function ksefDbBackupReadHeader($path)
{
    if (@file_get_contents($path, false, null, 0, 2) !== "\x1f\x8b" || !($fh = @gzopen($path, 'rb'))) {
        return null;
    }
    $unzipped = 0;
    $line = ksefDbBackupReadLine($fh, $unzipped);
    gzclose($fh);
    $h = is_string($line) ? json_decode($line, true, 64) : null;
    return ksefDbBackupCheckHeader($h) === '' ? $h : null;
}

/**
 * @brief List backups
 * @return array
 */
function ksefDbBackupList()
{
    $out = array();
    $found = glob(ksefDbBackupDir() . '/ksef-backup-*.jsonl.gz');
    foreach (is_array($found) ? $found : array() as $path) {
        $name = basename($path);
        if (ksefDbBackupFileOk($name)) {
            $out[$name] = array('name' => $name, 'size' => (int) @filesize($path), 'uploaded' => (strpos($name, '-upload.') !== false), 'header' => ksefDbBackupReadHeader($path));
        }
    }
    krsort($out);
    return array_values($out);
}

/**
 * @brief Send backup file
 * @param string $name File name
 * @return bool
 */
function ksefDbBackupSend($name)
{
    $path = ksefDbBackupDir() . '/' . $name;
    if (!ksefDbBackupFileOk($name) || !is_file($path)) {
        return false;
    }
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-store');
    readfile($path);
    return true;
}

/**
 * @brief Delete backup
 * @param string $name File name
 * @param User $user
 * @return bool
 */
function ksefDbBackupDelete($name, $user)
{
    $path = ksefDbBackupDir() . '/' . $name;
    if (!ksefDbBackupFileOk($name) || !is_file($path) || !@unlink($path)) {
        return false;
    }
    dol_syslog('ksefDbBackupDelete by ' . (is_object($user) ? $user->login : '') . ': ' . $name, LOG_WARNING);
    return true;
}

/**
 * @brief Add uploaded backup
 * @param string $src Temporary path
 * @param int $size Bytes
 * @param User $user
 * @return array
 */
function ksefDbBackupStoreUpload($src, $size, $user)
{
    $who = is_object($user) ? $user->login : '';
    $out = array('ok' => false, 'file' => '', 'header' => null, 'error' => '');
    if ($size > KSEF_DB_BACKUP_MAX_UPLOAD || @filesize($src) > KSEF_DB_BACKUP_MAX_UPLOAD) {
        $out['error'] = ksefDbBackupErr('size');
        return $out;
    }
    $v = ksefDbBackupScan($src);
    if (!$v['ok']) {
        $out['error'] = $v['error'];
        dol_syslog('ksefDbBackupStoreUpload by ' . $who . ': refused: ' . $v['error'], LOG_WARNING);
        return $out;
    }
    $dir = ksefDbBackupDir();
    if (!is_dir($dir) && !file_exists($dir)) {
        dol_mkdir($dir);
    }
    $name = ksefDbBackupNewName('-upload');
    if (!is_dir($dir) || !@copy($src, $dir . '/' . $name)) {
        $out['error'] = ksefDbBackupErr('write', $dir);
        return $out;
    }
    dolChmod($dir . '/' . $name);
    ksefDbBackupPrune(KSEF_DB_BACKUP_KEEP, $name);
    dol_syslog('ksefDbBackupStoreUpload by ' . $who . ': ' . $name . ' (made ' . dol_print_date($v['header']['created_at'], 'dayhourrfc') . ')', LOG_WARNING);
    $out['ok'] = true;
    $out['file'] = $name;
    $out['header'] = $v['header'];
    return $out;
}

/**
 * @brief Index core rows
 * @param array $core Header core
 * @param array $rows Rows by table
 * @return array
 */
function ksefDbBackupCoreAssoc($core, $rows)
{
    $out = array();
    foreach ($core as $table => $th) {
        $cols = array_map('strtolower', $th['columns']);
        $out[$table] = array();
        foreach (isset($rows[$table]) ? $rows[$table] : array() as $vals) {
            $r = array_combine($cols, $vals);
            $out[$table][(int) $r['rowid']] = $r;
        }
    }
    return $out;
}

/**
 * @brief Correction discount ids
 * @param DoliDB $db
 * @param array $A Core rows
 * @param array $ids Credit row ids
 * @return array
 */
function ksefDbBackupCoreCorrectionIds($db, $A, $ids)
{
    $out = array();
    $ids = array_filter(array_map('intval', (array) $ids));
    foreach (array('facture_extrafields', 'facture_fourn_extrafields') as $table) {
        foreach (isset($A[$table]) ? $A[$table] : array() as $r) {
            if (!empty($r['ksef_correction_discount_id'])) {
                $out[(int) $r['ksef_correction_discount_id']] = (int) $r['ksef_correction_discount_id'];
            }
        }
        $full = ksefDbCheckTableName($table);
        if ($ids && isset(ksefDbCheckLiveColumns($db, $full)['ksef_correction_discount_id'])) {
            $res = $db->query("SELECT ksef_correction_discount_id AS id FROM " . $full . " WHERE ksef_correction_discount_id IN (" . implode(',', $ids) . ")");
            while ($res && ($o = $db->fetch_object($res))) {
                $out[(int) $o->id] = (int) $o->id;
            }
        }
    }
    return $out;
}

/**
 * @brief Credit row reinsertable
 * @param array $row Backup row
 * @param array $core Header core
 * @param array $corrIds Correction ids
 * @return bool
 */
function ksefDbBackupCoreInsertable($row, $core, $corrIds)
{
    $desc = isset($row['description']) ? (string) $row['description'] : '';
    $excess = strpos($desc, '(EXCESS RECEIVED)') === 0 || strpos($desc, '(EXCESS PAID)') === 0;
    if ($desc !== '(DEPOSIT)' && !$excess && !isset($corrIds[(int) $row['rowid']])) {
        return false;
    }
    foreach (array('fk_facture_source' => 'facture', 'fk_invoice_supplier_source' => 'facture_fourn') as $col => $table) {
        $src = isset($row[$col]) ? (int) $row[$col] : 0;
        if ($src > 0) {
            $in = isset($core['societe_remise_except']['scope'][$col]) ? array_map('intval', (array) $core['societe_remise_except']['scope'][$col]) : array();
            $in = array_merge($in, isset($core[$table]['ids']) ? $core[$table]['ids'] : array());
            return in_array($src, $in, true);
        }
    }
    return false;
}

/**
 * @brief Core restore blockers
 * @param DoliDB $db
 * @param array $core Header core
 * @param array $rows Rows by table
 * @param array|null $subs Submission statuses
 * @param array $linesChanged Changed invoice refs
 * @return array Empty when allowed
 */
function ksefDbBackupCoreRefusals($db, $core, $rows, $subs, &$linesChanged = null)
{
    global $langs;
    dol_include_once('/ksef/lib/ksef.lib.php');
    $langs->loadLangs(array('ksef@ksef'));
    $P = MAIN_DB_PREFIX;
    $defs = ksefDbBackupCoreTables();
    $A = ksefDbBackupCoreAssoc($core, $rows);
    $kinds = array('facture' => ksefInvoiceKind('facture'), 'facture_fourn' => ksefInvoiceKind('invoice_supplier'));
    $check = array('facture' => array(), 'facture_fourn' => array());
    $ef = array('facture' => array(), 'facture_fourn' => array());
    $badInv = array('facture' => array(), 'facture_fourn' => array());
    $badCredit = array();
    $lineInv = array();
    $idList = function ($ids) {
        return implode(',', array_map('intval', $ids));
    };
    foreach ($kinds as $tb => $k) {
        foreach (isset($A[$tb]) ? $A[$tb] : array() as $id => $r) {
            $check[$tb][$id] = true;
        }
        foreach (isset($A[$k['extrafields'] . '_extrafields']) ? $A[$k['extrafields'] . '_extrafields'] : array() as $r) {
            $o = isset($r['fk_object']) ? (int) $r['fk_object'] : 0;
            if ($o > 0) {
                $ef[$tb][$o] = $r;
                $check[$tb][$o] = true;
            }
        }
        $lt = $k['line_table'];
        $lineInv[$lt] = array();
        if (!empty($A[$lt])) {
            $res = $db->query("SELECT rowid, " . $k['line_fk'] . " AS inv FROM " . $P . $lt . " WHERE rowid IN (" . $idList(array_keys($A[$lt])) . ")");
            while ($res && ($o = $db->fetch_object($res))) {
                $lineInv[$lt][(int) $o->rowid] = (int) $o->inv;
            }
            foreach ($A[$lt] as $id => $r) {
                if (!isset($lineInv[$lt][$id])) {
                    continue;
                }
                $inv = $lineInv[$lt][$id];
                $check[$tb][$inv] = true;
                if ((isset($r[$k['line_fk']]) && (int) $r[$k['line_fk']] !== $inv) || !isset($A[$tb][$inv])) {
                    $badInv[$tb][$inv] = true;
                }
            }
        }
    }
    $R = isset($A['societe_remise_except']) ? $A['societe_remise_except'] : array();
    $used = array('fk_facture' => 'facture', 'fk_invoice_supplier' => 'facture_fourn');
    $usedLine = array('fk_facture_line' => array('facture', 'facturedet', 'fk_facture'), 'fk_invoice_supplier_line' => array('facture_fourn', 'facture_fourn_det', 'fk_facture_fourn'));
    $creditInv = array();
    if ($R) {
        $liveR = array();
        $res = $db->query("SELECT rowid, fk_facture, fk_facture_line, fk_invoice_supplier, fk_invoice_supplier_line FROM " . $P . "societe_remise_except WHERE rowid IN (" . $idList(array_keys($R)) . ")");
        while ($res && ($o = $db->fetch_array($res))) {
            $liveR[(int) $o['rowid']] = $o;
        }
        $corrIds = ksefDbBackupCoreCorrectionIds($db, $A, array_keys($R));
        foreach ($R as $id => $r) {
            $l = isset($liveR[$id]) ? $liveR[$id] : null;
            if ($l) {
                foreach (array_merge(array_keys($used), array_keys($usedLine)) as $c) {
                    if (array_key_exists($c, $r) && (int) $r[$c] !== (int) $l[$c]) {
                        $badCredit[$id] = $id;
                    }
                }
            } elseif (!ksefDbBackupCoreInsertable($r, $core, $corrIds)) {
                $badCredit[$id] = $id;
            }
            $u = $l ? $l : $r;
            foreach ($used as $c => $tb) {
                if (!empty($u[$c])) {
                    $check[$tb][(int) $u[$c]] = true;
                    $creditInv[$id] = array($tb, (int) $u[$c]);
                }
            }
            foreach ($usedLine as $c => $x) {
                if (!empty($u[$c])) {
                    $lo = $db->getRow("SELECT " . $x[2] . " AS inv FROM " . $P . $x[1] . " WHERE rowid = " . (int) $u[$c]);
                    $inv = $lo ? (int) $lo->inv : 0;
                    if ($inv > 0) {
                        $check[$x[0]][$inv] = true;
                        $creditInv[$id] = array($x[0], $inv);
                    }
                }
            }
        }
    }
    $th = isset($core['societe_remise_except']) ? $core['societe_remise_except'] : null;
    if ($th && !empty($th['scope'])) {
        $where = ksefDbBackupCoreWhere('societe_remise_except', array(), $th['scope']);
        $res = $where === '0' ? false : $db->query("SELECT rowid FROM " . $P . "societe_remise_except WHERE (" . $where . ")" . ($th['ids'] ? " AND rowid NOT IN (" . $idList($th['ids']) . ")" : "")
            . " AND (COALESCE(fk_facture, 0) > 0 OR COALESCE(fk_facture_line, 0) > 0 OR COALESCE(fk_invoice_supplier, 0) > 0 OR COALESCE(fk_invoice_supplier_line, 0) > 0)");
        while ($res && ($o = $db->fetch_object($res))) {
            $badCredit[(int) $o->rowid] = (int) $o->rowid;
        }
    }
    $refs = array('facture' => array(), 'facture_fourn' => array());
    $sent = array('facture' => array(), 'facture_fourn' => array());
    foreach ($kinds as $tb => $k) {
        $ids = array_keys($check[$tb]);
        if (!$ids) {
            continue;
        }
        $E = $P . $k['extrafields'] . '_extrafields';
        $eCols = ksefDbCheckTableExists($db, $E) ? ksefDbCheckLiveColumns($db, $E) : array();
        $hasNum = isset($eCols['ksef_number']);
        $hasSd = isset($eCols['ksef_sale_date']);
        $liveSubs = array();
        if ($tb === 'facture' && $subs !== null && ksefDbCheckTableExists($db, $P . 'ksef_submissions')) {
            $res = $db->query("SELECT fk_facture, rowid, status FROM " . $P . "ksef_submissions WHERE fk_facture IN (" . $idList($ids) . ")");
            while ($res && ($o = $db->fetch_object($res))) {
                $liveSubs[(int) $o->fk_facture][(int) $o->rowid] = (string) $o->status;
            }
        }
        $res = $db->query("SELECT f.rowid, f.ref, f.fk_statut" . ($hasNum ? ", e.ksef_number" : "") . ($hasSd ? ", e.ksef_sale_date" : "") . " FROM " . $P . $k['table'] . " AS f"
            . ($eCols ? " LEFT JOIN " . $E . " AS e ON e.fk_object = f.rowid" : "") . " WHERE f.rowid IN (" . $idList($ids) . ")");
        while ($res && ($o = $db->fetch_object($res))) {
            $id = (int) $o->rowid;
            $refs[$tb][$id] = (string) $o->ref;
            $ls = (int) $o->fk_statut;
            $b = isset($A[$tb][$id]) ? $A[$tb][$id] : null;
            if ($b && array_key_exists('fk_statut', $b)) {
                $bs = (int) $b['fk_statut'];
                if ($bs !== $ls && !(in_array($bs, array(1, 2), true) && in_array($ls, array(1, 2), true))) {
                    $badInv[$tb][$id] = true;
                }
            }
            $bNum = isset($ef[$tb][$id]['ksef_number']) ? trim((string) $ef[$tb][$id]['ksef_number']) : '';
            if ($bNum !== ($hasNum ? trim((string) $o->ksef_number) : '')) {
                $badInv[$tb][$id] = true;
            }
            if ($tb === 'facture' && $subs !== null) {
                $a = isset($liveSubs[$id]) ? $liveSubs[$id] : array();
                $bb = isset($subs[$id]) ? $subs[$id] : array();
                ksort($a);
                ksort($bb);
                if ($a !== $bb) {
                    $badInv[$tb][$id] = true;
                }
            }
            if ($hasSd && $ls !== 0 && isset($ef[$tb][$id]) && array_key_exists('ksef_sale_date', $ef[$tb][$id])
                && (string) $ef[$tb][$id]['ksef_sale_date'] !== (string) $o->ksef_sale_date) {
                $badInv[$tb][$id] = true;
            }
            $sent[$tb][$id] = ksefInvoiceSentToKsef($db, $id, $k['element']);
        }
    }
    $linesChanged = array();
    foreach ($kinds as $tb => $k) {
        $lt = $k['line_table'];
        if (empty($A[$tb]) || !isset($core[$lt]) || !ksefDbCheckTableExists($db, $P . $lt)) {
            continue;
        }
        $liveCols = ksefDbCheckLiveColumns($db, $P . $lt);
        $cmp = array_values(array_filter(array('qty', $k['line_pu'], 'tva_tx', 'remise_percent'), function ($c) use ($liveCols, $core, $lt) {
            return isset($liveCols[$c]) && in_array($c, array_map('strtolower', $core[$lt]['columns']), true);
        }));
        $hasRem = isset($liveCols['fk_remise_except']);
        $mcPu = isset($liveCols['multicurrency_subprice']) && in_array('multicurrency_subprice', array_map('strtolower', $core[$lt]['columns']), true);
        $moved = array();
        foreach ($mcPu && isset($core[$tb]['amounts']) ? array_map('intval', (array) $core[$tb]['amounts']) : array() as $mid) {
            $o = isset($A[$tb][$mid]) && array_key_exists('multicurrency_tx', $A[$tb][$mid])
                ? $db->getRow("SELECT multicurrency_tx FROM " . $P . $k['table'] . " WHERE rowid = " . $mid) : null;
            if ($o && abs((float) $o->multicurrency_tx - (float) $A[$tb][$mid]['multicurrency_tx']) > 0.0000000001) {
                $moved[$mid] = $mid;
            }
        }
        $bLines = array();
        foreach (isset($A[$lt]) ? $A[$lt] : array() as $lid => $r) {
            $inv = isset($r[$k['line_fk']]) ? (int) $r[$k['line_fk']] : (isset($lineInv[$lt][$lid]) ? $lineInv[$lt][$lid] : 0);
            $bLines[$inv][$lid] = $r;
        }
        $live = array();
        $res = $db->query("SELECT rowid, " . $k['line_fk'] . " AS inv" . ($cmp ? ", " . implode(', ', $cmp) : "") . ($hasRem ? ", fk_remise_except" : "")
            . ($moved ? ", multicurrency_subprice" : "") . " FROM " . $P . $lt . " WHERE " . $k['line_fk'] . " IN (" . $idList(array_keys($A[$tb])) . ")");
        while ($res && ($o = $db->fetch_array($res))) {
            $live[(int) $o['inv']][(int) $o['rowid']] = $o;
        }
        foreach (array_keys($A[$tb]) as $id) {
            if (!isset($refs[$tb][$id])) {
                continue;
            }
            $b = isset($bLines[$id]) ? $bLines[$id] : array();
            $l = isset($live[$id]) ? $live[$id] : array();
            $ids = array_keys($l);
            $bIds = array_keys($b);
            sort($ids);
            sort($bIds);
            $bad = $ids !== $bIds;
            foreach ($bad ? array() : $l as $lid => $o) {
                foreach ($cmp as $c) {
                    // moves deduction price
                    if ($c === $k['line_pu'] && $hasRem && (int) $o['fk_remise_except'] > 0) {
                        continue;
                    }
                    $c = ($c === $k['line_pu'] && isset($moved[$id])) ? 'multicurrency_subprice' : $c;
                    if (abs((float) $b[$lid][$c] - (float) $o[$c]) > 0.000001) {
                        $bad = true;
                    }
                }
            }
            if ($bad) {
                $linesChanged[] = $refs[$tb][$id] !== '' ? $refs[$tb][$id] : '#' . $id;
            }
        }
    }
    $differs = function ($table, $ids, $cols) use ($db, $A, $idList) {
        $out = array();
        if (!$ids || !ksefDbCheckTableExists($db, ksefDbCheckTableName($table))) {
            return $out;
        }
        $live = ksefDbCheckLiveColumns($db, ksefDbCheckTableName($table));
        $cols = array_values(array_filter($cols, function ($c) use ($live) {
            return isset($live[$c]);
        }));
        if (!$cols) {
            return $out;
        }
        $res = $db->query("SELECT rowid, " . implode(', ', $cols) . " FROM " . ksefDbCheckTableName($table) . " WHERE rowid IN (" . $idList($ids) . ")");
        while ($res && ($o = $db->fetch_array($res))) {
            $b = $A[$table][(int) $o['rowid']];
            foreach ($cols as $c) {
                if (array_key_exists($c, $b) && abs((float) $b[$c] - (float) $o[$c]) > 0.000001) {
                    $out[(int) $o['rowid']] = true;
                }
            }
        }
        return $out;
    };
    $status = array('fk_statut', 'paye', 'close_code', 'fk_user_closing', 'date_closing');
    foreach ($kinds as $tb => $k) {
        $rec = $tb === 'facture' ? array('ksef_sales', 'fk_facture') : array('ksef_incoming', 'fk_facture_fourn');
        $amounts = isset($core[$tb]['amounts']) ? array_map('intval', (array) $core[$tb]['amounts']) : array();
        if ($amounts && ksefDbCheckTableExists($db, $P . $rec[0])) {
            $res = $db->query("SELECT DISTINCT " . $rec[1] . " AS id FROM " . $P . $rec[0] . " WHERE import_status = 'IMPORTED' AND " . $rec[1] . " IN (" . $idList($amounts) . ")");
            while ($res && ($o = $db->fetch_object($res))) {
                $sent[$tb][(int) $o->id] = false;
            }
        }
        $ids = array_keys(array_filter(array_intersect_key($sent[$tb], isset($A[$tb]) ? $A[$tb] : array())));
        foreach ($differs($tb, $ids, array_diff($defs[$tb]['write'], $status)) as $id => $x) {
            $badInv[$tb][$id] = true;
        }
        $lt = $k['line_table'];
        $lines = array();
        foreach ($lineInv[$lt] as $lid => $inv) {
            if (!empty($sent[$tb][$inv]) && isset($A[$lt][$lid])) {
                $lines[] = $lid;
            }
        }
        foreach ($differs($lt, $lines, $defs[$lt]['write']) as $lid => $x) {
            $badInv[$tb][$lineInv[$lt][$lid]] = true;
        }
    }
    $onSent = array();
    foreach ($creditInv as $id => $ti) {
        if (!empty($sent[$ti[0]][$ti[1]])) {
            $onSent[] = $id;
        }
    }
    $remiseAmounts = array_diff($defs['societe_remise_except']['write'], array('fk_facture_source', 'fk_invoice_supplier_source'));
    foreach ($differs('societe_remise_except', $onSent, $remiseAmounts) as $id => $x) {
        $badCredit[$id] = $id;
    }
    $out = array();
    foreach ($badInv as $tb => $ids) {
        foreach (array_keys($ids) as $id) {
            $out[] = isset($refs[$tb][$id]) && $refs[$tb][$id] !== '' ? $refs[$tb][$id] : '#' . $id;
        }
    }
    foreach ($badCredit as $id) {
        $out[] = $langs->transnoentitiesnoconv('KSEF_DbBkRefCredit', $id);
    }
    $linesChanged = array_values(array_diff(array_unique($linesChanged), $out));
    return array_values(array_unique($out));
}

/**
 * @brief Restore core rows
 * @param DoliDB $db
 * @param array $core Header core
 * @param array $rows Rows by table
 * @return array
 */
function ksefDbBackupRestoreCore($db, $core, $rows)
{
    $out = array('error' => '', 'tables' => array(), 'dropped' => array(), 'inserted' => array());
    $defs = ksefDbBackupCoreTables();
    $A = ksefDbBackupCoreAssoc($core, $rows);
    $corrIds = isset($A['societe_remise_except']) ? ksefDbBackupCoreCorrectionIds($db, $A, array_keys($A['societe_remise_except'])) : array();
    foreach ($core as $table => $th) {
        $full = ksefDbCheckTableName($table);
        $out['tables'][$full] = 0;
        if (!ksefDbCheckTableExists($db, $full)) {
            continue;
        }
        $def = $defs[$table];
        $live = array_keys(ksefDbCheckLiveColumns($db, $full));
        $use = array();
        foreach ($th['columns'] as $i => $c) {
            if (in_array(strtolower($c), $live, true)) {
                $use[$i] = strtolower($c);
            } else {
                $out['dropped'][] = $full . '.' . $c;
            }
        }
        $ridAt = array_search('rowid', $th['columns'], true);
        $upsert = $def['mode'] === 'upsert';
        if (!empty($th['scope'])) {
            $where = ksefDbBackupCoreWhere($table, array(), $th['scope']);
            $notIds = $th['ids'] ? " AND rowid NOT IN (" . implode(',', array_map('intval', $th['ids'])) . ")" : "";
            $reset = array_intersect($def['reset'], $live);
            $sql = '';
            if ($where !== '0' && $upsert) {
                $sql = "DELETE FROM " . $full . " WHERE (" . $where . ")" . $notIds;
            } elseif ($where !== '0' && $reset) {
                $sql = "UPDATE " . $full . " SET " . implode(' = NULL, ', $reset) . " = NULL WHERE (" . $where . ")" . $notIds;
            }
            if ($sql !== '' && !$db->query($sql)) {
                return array('error' => $full . ': ' . $db->lasterror()) + $out;
            }
        }
        foreach (isset($rows[$table]) ? $rows[$table] : array() as $vals) {
            $rid = (int) $vals[$ridAt];
            $set = $cols = $sqlVals = array();
            foreach ($use as $i => $col) {
                $v = $vals[$i] === null ? 'NULL' : "'" . $db->escape($vals[$i]) . "'";
                if (in_array($col, $def['write'], true)) {
                    $set[] = $col . " = " . $v;
                }
                $cols[] = $col;
                $sqlVals[] = $v;
            }
            $exists = ksefDbCheckCount($db, "SELECT COUNT(*) AS n FROM " . $full . " WHERE rowid = " . $rid) > 0;
            if ($exists) {
                $ok = !$set || $db->query("UPDATE " . $full . " SET " . implode(', ', $set) . " WHERE rowid = " . $rid);
            } elseif ($upsert) {
                if (!ksefDbBackupCoreInsertable($A[$table][$rid], $core, $corrIds)) {
                    return array('error' => $full . ' ' . $rid . ': ' . ksefDbBackupErr('core_changed', $rid)) + $out;
                }
                $ok = $db->query("INSERT INTO " . $full . " (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $sqlVals) . ")");
                if ($ok) {
                    $out['inserted'][$table][] = $rid;
                }
            } else {
                continue;
            }
            if (!$ok) {
                return array('error' => $full . ' ' . $rid . ': ' . $db->lasterror()) + $out;
            }
            $out['tables'][$full]++;
        }
    }
    $err = ksefDbBackupRestorePaid($db, $core);
    if ($err !== '') {
        $out['error'] = $err;
    }
    return $out;
}

/**
 * @brief Restore paid flags
 * @param DoliDB $db
 * @param array $core Header core
 * @return string '' on success
 */
function ksefDbBackupRestorePaid($db, $core)
{
    global $conf, $user;
    dol_include_once('/ksef/lib/ksef.lib.php');
    $P = MAIN_DB_PREFIX;
    foreach (array('facture', 'invoice_supplier') as $element) {
        $k = ksefInvoiceKind($element);
        if (empty($core[$k['table']]['ids'])) {
            continue;
        }
        foreach (array_map('intval', $core[$k['table']]['ids']) as $id) {
            $cls = $k['class'];
            $f = new $cls($db);
            if ($f->fetch($id) <= 0 || (int) $f->status === Facture::STATUS_DRAFT || (int) $f->status === Facture::STATUS_ABANDONED) {
                continue;
            }
            $code = (string) $f->multicurrency_code;
            $remain = (float) $f->getRemainToPay($code !== '' && $code !== $conf->currency ? 1 : 0);
            // skip setPaid() triggers
            $sql = '';
            if ((int) $f->paye === 1 && $remain > 0.005 && (string) $f->close_code === ''
                && !((int) $f->type === Facture::TYPE_DEPOSIT && ksefDbCheckDepositPaidAtKorekta($db, $id, $element))) {
                $sql = "UPDATE " . $P . $k['table'] . " SET fk_statut = " . Facture::STATUS_VALIDATED . ", paye = 0, close_code = NULL, fk_user_closing = NULL, date_closing = NULL WHERE rowid = " . $id;
            } elseif ((int) $f->status === Facture::STATUS_VALIDATED && (int) $f->paye === 0 && abs($remain) < 0.005) {
                $sql = "UPDATE " . $P . $k['table'] . " SET fk_statut = " . Facture::STATUS_CLOSED . ", paye = 1, fk_user_closing = " . (is_object($user) ? (int) $user->id : 0)
                    . ", date_closing = '" . $db->idate(dol_now()) . "' WHERE rowid = " . $id;
            }
            if ($sql !== '' && !$db->query($sql)) {
                return $P . $k['table'] . ' ' . $id . ': ' . $db->lasterror();
            }
        }
    }
    return '';
}

/**
 * @brief Revert core rows
 * @param DoliDB $db
 * @param string $path Before-restore backup
 * @param array $inserted Inserted rows
 * @return string '' on success
 */
function ksefDbBackupRevertCore($db, $path, $inserted)
{
    $rows = array();
    $scan = ksefDbBackupScan($path, function ($table, $vals, $isCore) use (&$rows) {
        if ($isCore) {
            $rows[$table][] = $vals;
        }
        return '';
    });
    if (!$scan['ok']) {
        return $scan['error'];
    }
    $db->begin();
    foreach ((array) $inserted as $table => $ids) {
        $ids = array_filter(array_map('intval', (array) $ids));
        if ($ids && isset(ksefDbBackupCoreTables()[$table]) && !$db->query("DELETE FROM " . ksefDbCheckTableName($table) . " WHERE rowid IN (" . implode(',', $ids) . ")")) {
            $err = $db->lasterror();
            $db->rollback();
            return $err;
        }
    }
    $c = ksefDbBackupRestoreCore($db, isset($scan['header']['core']) ? $scan['header']['core'] : array(), $rows);
    if ($c['error'] !== '') {
        $db->rollback();
        return $c['error'];
    }
    $db->commit();
    return '';
}

/**
 * @brief Restore from backup
 * @param DoliDB $db
 * @param string $name File name
 * @param User $user
 * @param string $onlyTable Single table, '' all
 * @return array
 */
function ksefDbBackupRestore($db, $name, $user, $onlyTable = '')
{
    global $langs;
    $langs->loadLangs(array('ksef@ksef'));
    $who = is_object($user) ? (string) $user->login : '';
    $out = array('ok' => false, 'error' => '', 'message' => '', 'backup' => '', 'tables' => array(), 'core' => array(), 'dropped' => array(), 'fk_failed' => array(), 'untouched' => array());
    if (!ksefDbBackupSupported($db)) {
        $out['error'] = ksefDbBackupErr('mysql');
        return $out;
    }
    $path = ksefDbBackupDir() . '/' . $name;
    if (!ksefDbBackupFileOk($name) || !is_file($path)) {
        $out['error'] = $langs->transnoentitiesnoconv('KSEF_DbBkNotFound');
        return $out;
    }
    $h0 = ksefDbBackupReadHeader($path);
    $coreBuf = array();
    $subs = array();
    $subAt = array();
    if ($h0 && isset($h0['tables']['ksef_submissions'])) {
        foreach (array('rowid', 'fk_facture', 'status') as $c) {
            $subAt[$c] = array_search($c, $h0['tables']['ksef_submissions']['columns'], true);
        }
    }
    $v = ksefDbBackupScan($path, function ($table, $vals, $isCore) use (&$coreBuf, &$subs, $subAt) {
        if ($isCore) {
            $coreBuf[$table][] = $vals;
        } elseif ($table === 'ksef_submissions' && $subAt && !in_array(false, $subAt, true)) {
            $subs[(int) $vals[$subAt['fk_facture']]][(int) $vals[$subAt['rowid']]] = (string) $vals[$subAt['status']];
        }
        return '';
    });
    if (!$v['ok']) {
        $out['error'] = $v['error'];
        return $out;
    }
    $h = $v['header'];
    if ($onlyTable !== '') {
        if (!isset($h['tables'][$onlyTable])) {
            $out['error'] = $langs->transnoentitiesnoconv('KSEF_DbBkNotFound');
            return $out;
        }
        $h['tables'] = array($onlyTable => $h['tables'][$onlyTable]);
        $h['core'] = array();
    }
    if (!empty($h['core'])) {
        $linesChanged = array();
        $refused = ksefDbBackupCoreRefusals($db, $h['core'], $coreBuf, $subs, $linesChanged);
        if ($refused || $linesChanged) {
            $err = array();
            if ($refused) {
                $err[] = ksefDbBackupErr('core_changed', implode(', ', $refused));
            }
            if ($linesChanged) {
                $err[] = ksefDbBackupErr('lines_changed', implode(', ', $linesChanged));
            }
            $out['error'] = implode('; ', $err);
            dol_syslog('ksefDbBackupRestore by ' . $who . ': ' . $name . ' refused, invoices or credits changed since the backup: ' . implode(', ', $refused)
                . ($linesChanged ? ', lines changed: ' . implode(', ', $linesChanged) : ''), LOG_WARNING);
            return $out;
        }
    }
    $coreSpec = array();
    foreach (isset($h['core']) ? $h['core'] : array() as $table => $th) {
        $coreSpec[$table] = array('ids' => $th['ids'], 'scope' => isset($th['scope']) ? $th['scope'] : array());
    }
    $before = ksefDbBackupWrite($db, $user, 'before_restore', $name, array('protect' => $name, 'core' => $coreSpec));
    if (!$before['ok']) {
        $out['error'] = $langs->transnoentitiesnoconv('KSEF_DbBkFailed', $before['error']);
        return $out;
    }
    $out['backup'] = $before['file'];
    dol_syslog('ksefDbBackupRestore by ' . $who . ': restoring ' . $name . ' (made ' . dol_print_date($h['created_at'], 'dayhourrfc') . ', module '
        . (isset($h['module_version']) ? $h['module_version'] : '?') . '), state before saved as ' . $before['file'], LOG_WARNING);

    $schema = ksefDbCheckSchema();
    $plan = array();
    foreach ($h['tables'] as $table => $th) {
        $full = ksefDbCheckTableName($table);
        if (!ksefDbCheckTableExists($db, $full)) {
            $c = ksefDbCheckCreateTable($db, $table);
            if (!$c['ok']) {
                $out['error'] = $full . ': ' . $c['error'];
                return $out;
            }
        }
        $live = array_keys(ksefDbCheckLiveColumns($db, $full));
        $use = array();
        foreach ($th['columns'] as $i => $c) {
            $lc = strtolower($c);
            if (in_array($lc, $live, true)) {
                $use[$i] = $lc;
            } else {
                $out['dropped'][] = $full . '.' . $c;
            }
        }
        $plan[$table] = array('full' => $full, 'stage' => $full . '_ksefrestore', 'old' => $full . '_ksefold', 'use' => $use, 'rows' => $th['rows'], 'buf' => array(), 'len' => 0);
    }
    foreach ($schema as $table => $def) {
        if (!isset($plan[$table])) {
            $out['untouched'][] = ksefDbCheckTableName($table);
        }
    }
    $fail = function ($error) use ($db, &$plan, &$out, $who, $name) {
        foreach ($plan as $p) {
            $db->query("DROP TABLE IF EXISTS " . $p['stage']);
        }
        $out['error'] = $error;
        dol_syslog('ksefDbBackupRestore by ' . $who . ': ' . $name . ' failed, live tables untouched: ' . $error, LOG_ERR);
        return $out;
    };

    foreach ($plan as $p) {
        $db->query("DROP TABLE IF EXISTS " . $p['old']);
        if (!$db->query("DROP TABLE IF EXISTS " . $p['stage']) || !$db->query("CREATE TABLE " . $p['stage'] . " LIKE " . $p['full'])) {
            return $fail($p['stage'] . ': ' . $db->lasterror());
        }
    }
    $flush = function ($table) use ($db, &$plan) {
        if (!$plan[$table]['buf']) {
            return '';
        }
        $sql = "INSERT INTO " . $plan[$table]['stage'] . " (" . implode(', ', $plan[$table]['use']) . ") VALUES " . implode(', ', $plan[$table]['buf']);
        $plan[$table]['buf'] = array();
        $plan[$table]['len'] = 0;
        return $db->query($sql) ? '' : $plan[$table]['stage'] . ': ' . $db->lasterror();
    };
    $scan = ksefDbBackupScan($path, function ($table, $vals, $isCore) use ($db, &$plan, $flush) {
        if ($isCore) {
            return '';
        }
        if (!isset($plan[$table]) || !$plan[$table]['use']) {
            return '';
        }
        $sqlVals = array();
        foreach ($plan[$table]['use'] as $i => $col) {
            $sqlVals[] = $vals[$i] === null ? 'NULL' : "'" . $db->escape($vals[$i]) . "'";
        }
        $tuple = '(' . implode(', ', $sqlVals) . ')';
        $plan[$table]['buf'][] = $tuple;
        $plan[$table]['len'] += strlen($tuple);
        if (count($plan[$table]['buf']) >= 200 || $plan[$table]['len'] > 1024 * 1024) {
            return $flush($table);
        }
        return '';
    });
    if (!$scan['ok']) {
        return $fail($scan['error']);
    }
    foreach (array_keys($plan) as $table) {
        $err = $flush($table);
        if ($err !== '') {
            return $fail($err);
        }
    }
    foreach ($plan as $p) {
        $n = ksefDbCheckCount($db, "SELECT COUNT(*) AS n FROM " . $p['stage']);
        if ($n != ($p['use'] ? $p['rows'] : 0)) {
            return $fail(ksefDbBackupErr('count', $p['stage'], $p['rows'], $n));
        }
    }

    // RENAME commits implicitly
    $c = null;
    if (!empty($h['core'])) {
        $db->begin();
        $c = ksefDbBackupRestoreCore($db, $h['core'], $coreBuf);
        if ($c['error'] !== '') {
            $db->rollback();
            return $fail($c['error']);
        }
        $db->commit();
        $out['dropped'] = array_merge($out['dropped'], $c['dropped']);
        $out['core'] = $c['tables'];
    }

    $pairs = array();
    foreach ($plan as $p) {
        $pairs[] = $p['full'] . " TO " . $p['old'] . ", " . $p['stage'] . " TO " . $p['full'];
    }
    if (!$db->query("RENAME TABLE " . implode(', ', $pairs))) {
        $err = 'RENAME TABLE: ' . $db->lasterror();
        if ($c === null) {
            return $fail($err);
        }
        $back = ksefDbBackupRevertCore($db, ksefDbBackupDir() . '/' . $before['file'], $c['inserted']);
        $fail($err);
        $out['core'] = array();
        $out['message'] = $back === '' ? $langs->transnoentitiesnoconv('KSEF_DbBkRestoreSwapFailed', $err)
            : $langs->transnoentitiesnoconv('KSEF_DbBkRestoreSwapStuck', $err, $back, $before['file']);
        dol_syslog('ksefDbBackupRestore by ' . $who . ': ' . $name . ' swap failed after the Dolibarr rows were written, '
            . ($back === '' ? 'they were written back as before' : 'writing them back failed: ' . $back), LOG_ERR);
        return $out;
    }
    foreach ($plan as $table => $p) {
        if (!$db->query("DROP TABLE IF EXISTS " . $p['old'])) {
            dol_syslog('ksefDbBackupRestore: drop ' . $p['old'] . ' failed: ' . $db->lasterror(), LOG_ERR);
        }
        // LIKE skips FKs
        $liveFks = ksefDbCheckLiveForeignKeys($db, $p['full']);
        foreach ($schema[$table]['keys'] as $keyName => $key) {
            if ($key['type'] == 'fk' && !isset($liveFks[$keyName]) && !$db->query(ksefDbCheckPrefix($key['sql']))) {
                $out['fk_failed'][] = $keyName;
                dol_syslog('ksefDbBackupRestore: foreign key ' . $keyName . ' not added: ' . $db->lasterror(), LOG_WARNING);
            }
        }
        $out['tables'][$p['full']] = $p['use'] ? $p['rows'] : 0;
    }
    $out['tables'] += $out['core'];
    if (isset($plan['ksef_session_invoice'])) {
        dol_include_once('/ksef/class/ksef_session_cache.class.php');
        KsefSessionCache::resetState($db);
    }
    $out['ok'] = true;
    dol_syslog('ksefDbBackupRestore by ' . $who . ': ' . $name . ' restored: ' . json_encode($out['tables'])
        . ($out['dropped'] ? ', columns skipped: ' . implode(', ', $out['dropped']) : '') . ($out['fk_failed'] ? ', foreign keys not added: ' . implode(', ', $out['fk_failed']) : ''), LOG_WARNING);
    return $out;
}

/**
 * @brief Backup reason label
 * @param array $h Header
 * @return string
 */
function ksefDbBackupReasonLabel($h)
{
    global $langs;
    $reason = isset($h['reason']) && preg_match('/^[a-z_]+$/', $h['reason']) ? $h['reason'] : '';
    $key = 'KSEF_DbBkReason_' . $reason;
    $label = $reason !== '' && $langs->transnoentitiesnoconv($key) !== $key ? $langs->transnoentitiesnoconv($key) : ($reason !== '' ? $reason : '-');
    if (!empty($h['detail'])) {
        $detail = $h['detail'];
        if ($reason == 'reset') {
            $detail = ksefDbCheckTableName($detail);
        } elseif (ksefDbBackupFileOk($detail) && ($rh = ksefDbBackupReadHeader(ksefDbBackupDir() . '/' . $detail))) {
            $detail = dol_print_date($rh['created_at'], 'dayhour', 'tzuserrel');
        }
        $label .= ': ' . $detail;
    }
    return $label;
}

/**
 * @brief Header table summary
 * @param array $h Header
 * @return array
 */
function ksefDbBackupSummary($h)
{
    $parts = array();
    $rows = 0;
    foreach ($h['tables'] + (isset($h['core']) ? $h['core'] : array()) as $table => $th) {
        $parts[] = ksefDbCheckTableName($table) . ' (' . (int) $th['rows'] . ')';
        $rows += (int) $th['rows'];
    }
    return array('tables' => implode(', ', $parts), 'rows' => $rows);
}

/**
 * @brief Unique link param
 * @return string
 */
function ksefDbNavParam()
{
    return '&nav=' . substr(uniqid(), -8);
}

/**
 * @brief Backups block HTML
 * @param string $restoreFile Open restore box
 * @return string
 */
function ksefDbBackupRenderBlock($restoreFile = '')
{
    global $langs, $db, $user;
    $self = dol_buildpath('/ksef/admin/about.php', 1);
    $mayChange = ksefDbCheckMayChangeTables($user);
    $supported = ksefDbBackupSupported($db);
    $list = $mayChange ? ksefDbBackupList() : array();
    $html = '<a id="dbbackup"></a><div class="div-table-responsive-no-min">';
    $html .= '<table class="noborder centpercent ksef-dbbackup">';
    $html .= '<tr class="liste_titre"><th colspan="6"><span class="fa fa-archive paddingright"></span>' . $langs->trans('KSEF_DbBkTitle') . '</th></tr>';
    $fullLink = '<a href="' . DOL_URL_ROOT . '/admin/tools/dolibarr_export.php">' . $langs->transnoentitiesnoconv('KSEF_DbBkFullLink') . '</a>';
    $html .= '<tr class="oddeven"><td colspan="6"><span class="opacitymedium ksef-dbbackup-scope">' . $langs->trans('KSEF_DbBkScope', $fullLink, KSEF_DB_BACKUP_KEEP) . '</span>';
    if (!$supported) {
        $html .= '<br><span class="warning">' . $langs->trans('KSEF_DbBkErr_mysql') . '</span>';
    }
    $html .= '</td></tr>';
    if ($mayChange) {
        $html .= '<tr class="liste_titre"><td>' . $langs->trans('KSEF_DbBkColDate') . '</td><td class="hideonsmartphone">' . $langs->trans('KSEF_DbBkColWho') . '</td><td>' . $langs->trans('KSEF_DbBkColReason') . '</td>'
            . '<td>' . $langs->trans('KSEF_DbBkColTables') . '</td><td class="right hideonsmartphone">' . $langs->trans('KSEF_DbBkColSize') . '</td><td></td></tr>';
        if (!$list) {
            $html .= '<tr class="oddeven"><td colspan="6"><span class="opacitymedium">' . $langs->trans('KSEF_DbBkNone') . '</span></td></tr>';
        }
        $token = newToken();
        foreach ($list as $b) {
            $h = $b['header'];
            $html .= '<tr class="oddeven ksef-dbbackup-row" data-file="' . dol_escape_htmltag($b['name']) . '">';
            if ($h) {
                $sum = ksefDbBackupSummary($h);
                $html .= '<td>' . dol_print_date($h['created_at'], 'dayhour', 'tzuserrel') . ($b['uploaded'] ? ' <span class="badge badge-info ' . ksefToneClass('info') . '">' . $langs->trans('KSEF_DbBkUploaded') . '</span>' : '') . '</td>'
                    . '<td class="hideonsmartphone">' . dol_escape_htmltag(isset($h['created_by']) ? $h['created_by'] : '') . '</td>'
                    . '<td>' . dol_escape_htmltag(ksefDbBackupReasonLabel($h)) . '</td>'
                    . '<td class="ksef-dbbackup-tables"><span class="hideonsmartphone">' . dol_escape_htmltag($sum['tables']) . '<br></span><span class="opacitymedium small">' . $langs->trans('KSEF_DbBkRowsTotal', $sum['rows']) . '</span></td>';
            } else {
                $html .= '<td colspan="4"><span class="badge badge-danger ' . ksefToneClass('problem') . '">' . $langs->trans('KSEF_DbBkDamaged') . '</span> ' . dol_escape_htmltag($b['name']) . '</td>';
            }
            $html .= '<td class="right nowraponall hideonsmartphone">' . dol_print_size($b['size'], 1, 1) . '</td><td class="right">';
            if ($h) {
                $html .= '<a class="button smallpaddingimp" style="vertical-align: middle;" href="' . $self . '?action=dbbackupdl&file=' . urlencode($b['name']) . '&token=' . $token . '"><span class="fa fa-download paddingright"></span>' . $langs->trans('KSEF_DbBkDownload') . '</a> ';
                if ($supported) {
                    $html .= '<form method="POST" action="' . $self . '#dbbackup" class="inline-block" style="vertical-align: middle;">'
                        . '<input type="hidden" name="token" value="' . $token . '"><input type="hidden" name="action" value="dbrestoreask">'
                        . '<input type="hidden" name="file" value="' . dol_escape_htmltag($b['name']) . '">'
                        . '<button type="submit" class="button smallpaddingimp"><span class="fa fa-undo paddingright"></span>' . $langs->trans('KSEF_DbBkRestore') . '</button></form> ';
                }
            }
            $html .= '<form method="POST" action="' . $self . '#dbbackup" class="inline-block" style="vertical-align: middle;">'
                . '<input type="hidden" name="token" value="' . $token . '"><input type="hidden" name="action" value="dbbackupdel">'
                . '<input type="hidden" name="file" value="' . dol_escape_htmltag($b['name']) . '">'
                . '<button type="submit" class="button button-delete smallpaddingimp"><span class="fa fa-trash paddingright"></span>' . $langs->trans('KSEF_DbBkDelete') . '</button></form>';
            $html .= '</td></tr>';
        }
    }
    $html .= '</table></div>';

    if ($mayChange && $restoreFile !== '') {
        $path = ksefDbBackupDir() . '/' . $restoreFile;
        $h = ksefDbBackupFileOk($restoreFile) && is_file($path) ? ksefDbBackupReadHeader($path) : null;
        if ($h) {
            $date = dol_print_date($h['created_at'], 'dayhour', 'tzuserrel');
            $sum = ksefDbBackupSummary($h);
            $html .= '<div class="warning ksef-dbrestore-box"><strong>' . $langs->trans('KSEF_DbBkRestoreTitle', $date) . '</strong><br>'
                . $langs->trans('KSEF_DbBkRestoreInfo', $sum['tables']);
            $hv = isset($h['module_version']) ? $h['module_version'] : '';
            $hm = isset($h['migration_version']) ? $h['migration_version'] : '';
            if ($hv !== ksefGetModuleVersion($db) || $hm !== getDolGlobalString('KSEF_MIGRATION_VERSION')) {
                $html .= '<br>' . dol_escape_htmltag($langs->transnoentitiesnoconv('KSEF_DbBkRestoreVersion', dol_trunc($hv, 20), dol_trunc($hm, 20), ksefGetModuleVersion($db), getDolGlobalString('KSEF_MIGRATION_VERSION')));
            }
            $html .= '<br>' . $langs->trans('KSEF_DbBkSyncNote')
                . '<form method="POST" action="' . $self . '#dbbackup" style="margin-top: 8px;">'
                . '<input type="hidden" name="token" value="' . newToken() . '">'
                . '<input type="hidden" name="action" value="confirm_dbrestore">'
                . '<input type="hidden" name="file" value="' . dol_escape_htmltag($restoreFile) . '">'
                . '<label><input type="checkbox" name="ack" value="1"> ' . $langs->trans(empty($h['core']) ? 'KSEF_DbBkRestoreAck' : 'KSEF_DbBkRestoreAckCore', $date) . '</label> '
                . '<input type="submit" class="button" value="' . dol_escape_htmltag($langs->trans('KSEF_DbBkRestoreDo')) . '">'
                . ' <a class="button button-cancel" href="' . $self . '#dbbackup">' . $langs->trans('Cancel') . '</a>'
                . '</form></div>';
        }
    }

    if ($mayChange && $supported) {
        $html .= '<div class="ksef-dbbackup-actions" style="display: flex; flex-wrap: wrap; justify-content: flex-end; align-items: flex-start; gap: 16px; margin: 12px 0;">';
        $html .= '<form method="POST" action="' . $self . '#dbbackup">'
            . '<input type="hidden" name="token" value="' . newToken() . '"><input type="hidden" name="action" value="dbbackup">'
            . '<input type="submit" class="butAction" style="margin: 0;" value="' . dol_escape_htmltag($langs->trans('KSEF_DbBkMake')) . '"></form>';
        $html .= '<form method="POST" action="' . $self . '#dbbackup" enctype="multipart/form-data" class="ksef-dbbackup-upload" style="max-width: 520px;">'
            . '<input type="hidden" name="token" value="' . newToken() . '"><input type="hidden" name="action" value="dbbackupupload">'
            . '<input type="file" name="backupfile" accept=".gz,application/gzip"> '
            . '<input type="submit" class="butAction" style="margin: 0;" value="' . dol_escape_htmltag($langs->trans('KSEF_DbBkUpload')) . '">'
            . '<div class="opacitymedium small" style="margin-top: 4px;">' . $langs->trans('KSEF_DbBkUploadHelp') . '</div></form>';
        $html .= '</div>';
    }
    return $html;
}
