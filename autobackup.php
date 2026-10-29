<?php
/**
 * AutoBackup v.1.0
 * Copyright (2026) by Onix.it
 * MIT License: see the LICENSE file
 * @license MIT
 *
 * Standalone script, no dependencies.
 * Compatible with PHP 7.4 and 8.x; uses only mysqli, zlib (gzopen) and ZipArchive/PharData.
 *
 * HOW TO USE
 *  1. Daily database backup (plain include, for example after the admin panel login):
 *         include __DIR__ . '/autobackup.php';
 *     The first include of the day saves the database to <folder>/db_<name>_<YYYY-MM-DD>.sql.gz,
 *     later includes find today's file and do nothing. No output, no exceptions:
 *     errors go to <folder>/autobackup.log.
 *  2. From the browser, with the secret 'web_key' (empty = web access disabled, responds 404):
 *         autobackup.php?action=test&key=...   checks paths, folder, connection and environment
 *         autobackup.php?action=db&key=...     immediate database dump (name with date and time)
 *         autobackup.php?action=site&key=...   compressed archive of the whole site + database dump
 *  3. From the command line: php autobackup.php test|db|site
 *
 * Files are written to 'folder' (default: outside the web root, next to the site folder),
 * created with a protective .htaccess and index.php. The last 'keep_db' dumps of different days
 * and the last 'keep_site' site archives are kept.
 *
 * This file contains the database credentials: treat it like a configuration file.
 */

$autobackup_config = array(
    // Backup folder (absolute path). Default: a "backup" folder next to the site root,
    // hence outside the public folder (e.g. /home/user/backup next to /home/user/public_html).
    'folder'            => dirname(__DIR__) . '/backup',

    // Site root to archive with action=site.
    'site_root'         => __DIR__,

    // Time zone for dates and times in file names and in the log.
    'timezone'          => 'Europe/Rome',

    // Database credentials.
    'db'                => array(
        'host'     => 'localhost',
        'port'     => 3306,
        'user'     => '',
        'password' => '',
        'name'     => '',
    ),
    // Tables NOT to back up: by prefix and by exact name.
    'exclude_prefixes'  => array(),            // e.g. array('stats_')
    'exclude_tables'    => array(),

    // How many files to keep (the most recent ones).
    'keep_db'           => 30,
    'keep_site'         => 5,

    // Secret key for browser actions (?action=...&key=...). Empty = no web access.
    'web_key'           => '',

    // Site folders and files excluded from the archive (paths relative to the root, with /).
    'site_exclude'      => array('.git', 'node_modules'),

    // Maximum size (bytes) of a single multi-row INSERT in the dump.
    'insert_block'      => 524288,
);

if (!function_exists('autobackup_run')) {

    /**
     * Entry point: detects the mode (include, web, command line) and runs.
     * Lets no exception out: in include mode the caller must not notice anything.
     */
    function autobackup_run(array $cfg)
    {
        static $started = false;
        if ($started) {
            return; // included twice in the same request: the work is already done
        }
        $started = true;
        $direct = false;
        if (isset($_SERVER['SCRIPT_FILENAME'])) {
            $script = realpath($_SERVER['SCRIPT_FILENAME']);
            $direct = $script !== false && $script === realpath(__FILE__);
        }
        $cli = PHP_SAPI === 'cli';

        // --- Included by another script: silent daily backup -----------------------------------------
        if (!$direct) {
            try {
                @ignore_user_abort(true);
                @set_time_limit(300);
                $folder = autobackup_folder($cfg, $error);
                if ($folder === false) {
                    autobackup_log(false, 'Backup folder not available: ' . $error);
                    return;
                }
                $result = autobackup_db_daily($cfg, $folder, false);
                if ($result['status'] === 'created') {
                    autobackup_log($folder, 'Daily backup created: ' . $result['file'] . ' (' . $result['detail'] . ')');
                }
            } catch (Throwable $e) {
                autobackup_log(isset($folder) && $folder !== false ? $folder : false, 'ERROR daily backup: ' . $e->getMessage());
            }
            return;
        }

        // --- Direct request: from the browser (with key) or from the command line ---------------------
        if ($cli) {
            $action = isset($_SERVER['argv'][1]) ? (string)$_SERVER['argv'][1] : 'test';
        } else {
            $key = isset($_GET['key']) ? (string)$_GET['key'] : '';
            if ($cfg['web_key'] === '' || $key === '' || !hash_equals((string)$cfg['web_key'], $key)) {
                http_response_code(404);
                exit;
            }
            $action = isset($_GET['action']) ? (string)$_GET['action'] : '';
            header('Content-Type: text/plain; charset=utf-8');
            header('X-Robots-Tag: noindex, nofollow');
            header('Cache-Control: no-store');
        }
        if (!in_array($action, array('test', 'db', 'site'), true)) {
            if ($cli) {
                echo "Usage: php autobackup.php test|db|site\n";
                exit(1);
            }
            http_response_code(404);
            exit;
        }

        @ignore_user_abort(true);
        @set_time_limit($action === 'site' ? 0 : 300);
        $lines = array();
        $ok = true;
        $folder = false;
        try {
            if ($action === 'test') {
                $lines = autobackup_test($cfg, $ok);
            } else {
                $folder = autobackup_folder($cfg, $error);
                if ($folder === false) {
                    throw new Exception('Backup folder not available: ' . $error);
                }
                if ($action === 'db') {
                    $result = autobackup_db_daily($cfg, $folder, true);
                    if ($result['status'] !== 'created') {
                        throw new Exception('Backup not performed: ' . $result['status']);
                    }
                    autobackup_log($folder, 'On-demand dump created: ' . $result['file'] . ' (' . $result['detail'] . ')');
                    $lines[] = 'OK  database dump: ' . $result['file'] . ' (' . $result['detail'] . ')';
                } else {
                    $result = autobackup_site($cfg, $folder);
                    autobackup_log($folder, 'Site archive created: ' . $result['file'] . ' (' . $result['detail'] . ')');
                    $lines[] = 'OK  site archive: ' . $result['file'] . ' (' . $result['detail'] . ')';
                }
                $lines[] = '    folder: ' . $folder;
            }
        } catch (Throwable $e) {
            $ok = false;
            $lines[] = 'ERROR  ' . $e->getMessage();
            autobackup_log($folder, 'ERROR action ' . $action . ': ' . $e->getMessage());
        }
        if (!$cli && !$ok) {
            http_response_code(500);
        }
        echo "autobackup - action: " . $action . " - " . autobackup_now($cfg, 'Y-m-d H:i:s') . "\n\n";
        echo implode("\n", $lines) . "\n";
        if ($cli) {
            exit($ok ? 0 : 1);
        }
        exit;
    }

    /** Current date and time in the configured time zone (UTC if invalid). */
    function autobackup_now(array $cfg, $format)
    {
        try {
            $tz = new DateTimeZone($cfg['timezone']);
        } catch (Exception $e) {
            $tz = new DateTimeZone('UTC');
        }
        autobackup_tz($tz);
        $d = new DateTime('now', $tz);
        return $d->format($format);
    }

    /** Remembers the last valid time zone (used by the log, which does not receive the configuration). */
    function autobackup_tz($tz = null)
    {
        static $memo = null;
        if ($tz !== null) {
            $memo = $tz;
        }
        return $memo;
    }

    /** Creates (if needed) and protects the backup folder; returns its path, or false with $error. */
    function autobackup_folder(array $cfg, &$error)
    {
        $error = '';
        $dir = rtrim((string)$cfg['folder'], '/\\');
        if ($dir === '') {
            $error = 'empty path';
            return false;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            $error = 'cannot create ' . $dir;
            return false;
        }
        if (!is_writable($dir)) {
            $error = 'folder not writable ' . $dir;
            return false;
        }
        $real = realpath($dir);
        if ($real !== false) {
            $dir = $real;
        }
        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess,
                "# Backups: no web access\n"
                . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n");
        }
        $index = $dir . '/index.php';
        if (!file_exists($index)) {
            @file_put_contents($index, "<?php\nhttp_response_code(404);\n");
        }
        // leftovers of interrupted runs: temporary files older than one hour
        foreach ((array)@scandir($dir) as $f) {
            if (preg_match('/^(\..+|.+\.tmp(\.gz)?)$/', $f) && $f !== '.htaccess' && $f !== '.lock'
                && is_file($dir . '/' . $f) && @filemtime($dir . '/' . $f) < time() - 3600) {
                @unlink($dir . '/' . $f);
            }
        }
        return $dir;
    }

    /** Appends a line to the log in the backup folder (or to the PHP log if the folder is missing). */
    function autobackup_log($folder, $message)
    {
        $tz = autobackup_tz();
        $now = $tz instanceof DateTimeZone ? new DateTime('now', $tz) : new DateTime('now');
        $line = '[' . $now->format('Y-m-d H:i:s') . '] ' . $message;
        if ($folder === false || $folder === '') {
            @error_log('autobackup: ' . $message);
            return;
        }
        $file = $folder . '/autobackup.log';
        if (is_file($file) && filesize($file) > 1048576) {
            @rename($file, $file . '.old');
        }
        @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /** Safe file name (letters, digits, dashes only). */
    function autobackup_safe_name($text)
    {
        $s = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)$text);
        return $s === '' ? 'db' : $s;
    }

    /** Opens a dedicated mysqli connection; false and $error on failure (also under MYSQLI_REPORT_STRICT). */
    function autobackup_connect(array $db, &$error)
    {
        $error = '';
        if ((string)$db['name'] === '' || (string)$db['user'] === '') {
            $error = 'credentials not filled in the configuration block (user or database name empty)';
            return false;
        }
        try {
            $c = mysqli_init();
            if (!$c) {
                $error = 'mysqli not available';
                return false;
            }
            @mysqli_options($c, MYSQLI_OPT_CONNECT_TIMEOUT, 5);
            $ok = @mysqli_real_connect($c, $db['host'], $db['user'], $db['password'], $db['name'], (int)$db['port']);
            if (!$ok) {
                $error = mysqli_connect_error();
                return false;
            }
            if (!mysqli_set_charset($c, 'utf8mb4')) {
                $error = 'cannot set charset utf8mb4: ' . mysqli_error($c);
                mysqli_close($c);
                return false;
            }
            return $c;
        } catch (Exception $e) {
            $error = $e->getMessage();
            return false;
        }
    }

    /** Runs a query and throws an exception with the MySQL message on failure. */
    function autobackup_query($c, $sql, $mode = MYSQLI_STORE_RESULT)
    {
        $r = mysqli_query($c, $sql, $mode);
        if ($r === false) {
            throw new Exception('Query failed (' . mysqli_error($c) . '): ' . substr($sql, 0, 120));
        }
        return $r;
    }

    /** True if the table must be excluded from the dump. */
    function autobackup_table_excluded($name, array $cfg)
    {
        $n = strtolower($name);
        foreach ($cfg['exclude_tables'] as $t) {
            if (strtolower($t) === $n) {
                return true;
            }
        }
        foreach ($cfg['exclude_prefixes'] as $p) {
            if ($p !== '' && strpos($n, strtolower($p)) === 0) {
                return true;
            }
        }
        return false;
    }

    /** Database tables: array('save' => [...], 'excluded' => [...], 'views' => [...]). */
    function autobackup_tables($c, array $cfg)
    {
        $out = array('save' => array(), 'excluded' => array(), 'views' => array());
        $r = autobackup_query($c, 'SHOW FULL TABLES');
        while ($row = mysqli_fetch_row($r)) {
            if (isset($row[1]) && stripos($row[1], 'VIEW') !== false) {
                $out['views'][] = $row[0];
            } elseif (autobackup_table_excluded($row[0], $cfg)) {
                $out['excluded'][] = $row[0];
            } else {
                $out['save'][] = $row[0];
            }
        }
        mysqli_free_result($r);
        return $out;
    }

    /** Writes to the file (gz or plain). */
    function autobackup_write($h, $gz, $text)
    {
        $n = $gz ? gzwrite($h, $text) : fwrite($h, $text);
        if ($n === false) {
            throw new Exception('Writing the backup failed (disk full or permissions)');
        }
    }

    /** SQL literal of a field according to the column type. */
    function autobackup_sql_value($c, $value, $field)
    {
        if ($value === null) {
            return 'NULL';
        }
        static $numeric = array(
            MYSQLI_TYPE_TINY, MYSQLI_TYPE_SHORT, MYSQLI_TYPE_LONG, MYSQLI_TYPE_LONGLONG, MYSQLI_TYPE_INT24,
            MYSQLI_TYPE_FLOAT, MYSQLI_TYPE_DOUBLE, MYSQLI_TYPE_DECIMAL, MYSQLI_TYPE_NEWDECIMAL, MYSQLI_TYPE_YEAR,
        );
        if (in_array($field->type, $numeric, true) && preg_match('/^-?\d+(\.\d+)?([eE][-+]?\d+)?$/', $value)) {
            return $value;
        }
        if ($field->type === MYSQLI_TYPE_BIT || (($field->flags & MYSQLI_BINARY_FLAG) && $field->charsetnr == 63)) {
            return $value === '' ? "''" : '0x' . bin2hex($value);
        }
        return "'" . mysqli_real_escape_string($c, $value) . "'";
    }

    /**
     * SQL dump of the database to $target (gzip if zlib is available).
     * Returns an array with tables, rows and bytes written. Throws on error.
     */
    function autobackup_dump(array $cfg, array $db, $target, $gz)
    {
        $c = autobackup_connect($db, $error);
        if ($c === false) {
            throw new Exception('Database connection failed: ' . $error);
        }
        $h = $gz ? @gzopen($target, 'wb6') : @fopen($target, 'wb');
        if (!$h) {
            mysqli_close($c);
            throw new Exception('Cannot create file ' . $target);
        }
        $totalRows = 0;
        $savedTables = 0;
        try {
            $tables = autobackup_tables($c, $cfg);
            // guaranteed backslash escaping (NO_BACKSLASH_ESCAPES off) and consistent read
            // of all tables at the same instant (InnoDB), without locking the site
            autobackup_query($c, "SET SESSION sql_mode = ''");
            // TIMESTAMPs are read in UTC, the same zone declared at the top of the file: restoring does not shift them
            autobackup_query($c, "SET SESSION time_zone = '+00:00'");
            autobackup_query($c, 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            autobackup_query($c, 'START TRANSACTION WITH CONSISTENT SNAPSHOT');

            $head = "-- autobackup.php - dump of database `" . $db['name'] . "`\n"
                . "-- Created on " . autobackup_now($cfg, 'Y-m-d H:i:s') . " (" . $cfg['timezone'] . ")\n"
                . "-- Server: " . mysqli_get_server_info($c) . " - PHP " . PHP_VERSION . "\n"
                . "-- Tables saved: " . count($tables['save'])
                . ($tables['excluded'] ? " - excluded: " . implode(', ', $tables['excluded']) : '')
                . ($tables['views'] ? " - views skipped: " . implode(', ', $tables['views']) : '') . "\n\n"
                . "SET NAMES utf8mb4;\n"
                . "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n"
                . "SET time_zone = '+00:00';\n"
                . "SET FOREIGN_KEY_CHECKS = 0;\n\n";
            autobackup_write($h, $gz, $head);

            foreach ($tables['save'] as $table) {
                $tq = '`' . str_replace('`', '``', $table) . '`';
                $r = autobackup_query($c, 'SHOW CREATE TABLE ' . $tq);
                $cr = mysqli_fetch_row($r);
                mysqli_free_result($r);
                autobackup_write($h, $gz, "--\n-- Table " . $tq . "\n--\n\nDROP TABLE IF EXISTS " . $tq . ";\n" . $cr[1] . ";\n\n");

                // explicit columns: skip generated ones (they cannot be inserted) and include invisible ones
                $columns = array();
                $rc = autobackup_query($c, "SELECT COLUMN_NAME, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '"
                    . mysqli_real_escape_string($c, $table) . "' ORDER BY ORDINAL_POSITION");
                while ($col = mysqli_fetch_row($rc)) {
                    // EXTRA is "VIRTUAL GENERATED" / "STORED GENERATED" for computed columns;
                    // "DEFAULT_GENERATED" (expression default, e.g. CURRENT_TIMESTAMP) must be saved instead
                    if (!preg_match('/(VIRTUAL|STORED) GENERATED/i', (string)$col[1])) {
                        $columns[] = '`' . str_replace('`', '``', $col[0]) . '`';
                    }
                }
                mysqli_free_result($rc);
                if (!$columns) {
                    $savedTables++;
                    continue;
                }
                $r = autobackup_query($c, 'SELECT ' . implode(', ', $columns) . ' FROM ' . $tq, MYSQLI_USE_RESULT);
                $fields = mysqli_fetch_fields($r);
                $start = 'INSERT INTO ' . $tq . ' (' . implode(', ', $columns) . ") VALUES\n";
                $buffer = '';
                $rows = 0;
                while ($row = mysqli_fetch_row($r)) {
                    $values = array();
                    foreach ($row as $i => $v) {
                        $values[] = autobackup_sql_value($c, $v, $fields[$i]);
                    }
                    $buffer .= ($buffer === '' ? $start : ",\n") . '(' . implode(', ', $values) . ')';
                    $rows++;
                    if (strlen($buffer) >= (int)$cfg['insert_block']) {
                        autobackup_write($h, $gz, $buffer . ";\n");
                        $buffer = '';
                    }
                }
                mysqli_free_result($r);
                if ($buffer !== '') {
                    autobackup_write($h, $gz, $buffer . ";\n");
                }
                autobackup_write($h, $gz, "\n");
                $totalRows += $rows;
                $savedTables++;
            }
            autobackup_write($h, $gz, "SET FOREIGN_KEY_CHECKS = 1;\n-- end of dump\n");
        } catch (Exception $e) {
            if ($gz) { gzclose($h); } else { fclose($h); }
            mysqli_close($c);
            throw $e;
        }
        if ($gz) { gzclose($h); } else { fclose($h); }
        mysqli_close($c);
        return array('tables' => $savedTables, 'rows' => $totalRows, 'bytes' => (int)@filesize($target));
    }

    /**
     * Database backup into the folder: once a day (or right now if $forced, with the time in the name).
     * Returns array('status' => 'created'|'exists'|'busy', 'file' => ..., 'detail' => ...).
     */
    function autobackup_db_daily(array $cfg, $folder, $forced)
    {
        $db = $cfg['db'];
        $gz = function_exists('gzopen');
        $name = 'db_' . autobackup_safe_name($db['name']) . '_'
            . autobackup_now($cfg, $forced ? 'Y-m-d_His' : 'Y-m-d') . ($gz ? '.sql.gz' : '.sql');
        $dest = $folder . '/' . $name;
        if (!$forced && file_exists($dest)) {
            return array('status' => 'exists', 'file' => $name, 'detail' => '');
        }
        $lock = @fopen($folder . '/.lock', 'c');
        if (!$lock) {
            throw new Exception('Cannot create the lock file in ' . $folder);
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return array('status' => 'busy', 'file' => $name, 'detail' => 'another backup is running');
        }
        try {
            if (!$forced && file_exists($dest)) {
                return array('status' => 'exists', 'file' => $name, 'detail' => '');
            }
            $tmp = $dest . '.tmp';
            $start = microtime(true);
            try {
                $info = autobackup_dump($cfg, $db, $tmp, $gz);
            } catch (Exception $e) {
                @unlink($tmp);
                throw $e;
            }
            if (!@rename($tmp, $dest)) {
                @unlink($tmp);
                throw new Exception('Cannot rename the temporary file to ' . $name);
            }
            autobackup_rotate($folder, '/^db_.+\.sql(\.gz)?$/', (int)$cfg['keep_db']);
            $detail = $info['tables'] . ' tables, ' . $info['rows'] . ' rows, '
                . autobackup_size($info['bytes']) . ', ' . round(microtime(true) - $start, 2) . ' s';
            return array('status' => 'created', 'file' => $name, 'detail' => $detail);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Keeps only the last $keep files matching the pattern (name order = date order). */
    function autobackup_rotate($folder, $pattern, $keep)
    {
        if ($keep < 1) {
            return 0;
        }
        $files = array();
        foreach ((array)@scandir($folder) as $f) {
            if (preg_match($pattern, $f)) {
                $files[] = $f;
            }
        }
        rsort($files, SORT_STRING);
        $deleted = 0;
        foreach (array_slice($files, $keep) as $f) {
            if (@unlink($folder . '/' . $f)) {
                $deleted++;
            }
        }
        return $deleted;
    }

    /** Human-readable size. */
    function autobackup_size($bytes)
    {
        $bytes = (float)$bytes;
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024) . ' KB';
        }
        return (int)$bytes . ' bytes';
    }

    /** Path of a site item relative to the root (with / separators). */
    function autobackup_relative($root, $path)
    {
        $rel = substr($path, strlen($root));
        return ltrim(str_replace('\\', '/', $rel), '/');
    }

    /** True if the relative path is excluded (exact entry or parent folder). */
    function autobackup_site_excluded($relative, array $excluded)
    {
        foreach ($excluded as $e) {
            $e = trim(str_replace('\\', '/', $e), '/');
            if ($e === '') {
                continue;
            }
            if ($relative === $e || strpos($relative, $e . '/') === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Compressed archive of the whole site (zip, or tar.gz if ZipArchive is missing) with a database dump inside.
     * Returns array('file' => name, 'detail' => ...).
     */
    function autobackup_site(array $cfg, $folder)
    {
        $root = realpath($cfg['site_root']);
        if ($root === false || !is_dir($root)) {
            throw new Exception('Site root not found: ' . $cfg['site_root']);
        }
        $useZip = class_exists('ZipArchive');
        if (!$useZip && !class_exists('PharData')) {
            throw new Exception('Neither ZipArchive nor PharData is available: cannot create the archive');
        }
        $start = microtime(true);
        $db = $cfg['db'];
        $gz = function_exists('gzopen');

        // exclusions: the configured ones + the backup folder if it sits inside the site
        $excluded = (array)$cfg['site_exclude'];
        $realFolder = realpath($folder);
        if ($realFolder !== false && strpos($realFolder, $root . DIRECTORY_SEPARATOR) === 0) {
            $excluded[] = autobackup_relative($root, $realFolder);
        }

        // database dump to a temporary file, to be added to the archive
        $tmpDb = $folder . '/.db_' . uniqid('', true) . ($gz ? '.sql.gz' : '.sql');
        $infoDb = autobackup_dump($cfg, $db, $tmpDb, $gz);
        $dbName = '_database_' . autobackup_safe_name($db['name']) . ($gz ? '.sql.gz' : '.sql');

        $stamp = autobackup_now($cfg, 'Y-m-d_His');
        $name = 'site_' . $stamp . ($useZip ? '.zip' : '.tar.gz');
        $dest = $folder . '/' . $name;
        $tmp = $folder . '/.site_' . $stamp . '_' . substr(md5(uniqid('', true)), 0, 6) . ($useZip ? '.zip' : '.tar');
        $nFiles = 0;
        $originalBytes = 0;
        try {
            if ($useZip) {
                $zip = new ZipArchive();
                $opened = $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
                if ($opened !== true) {
                    throw new Exception('ZipArchive cannot open ' . $tmp . ' (code ' . $opened . ')');
                }
                $zip->addFile($tmpDb, $dbName);
            } else {
                $zip = new PharData($tmp);
                $zip->addFile($tmpDb, $dbName);
            }

            $iter = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                    function ($current, $key, $iterator) use ($root, $excluded) {
                        return !autobackup_site_excluded(autobackup_relative($root, $current->getPathname()), $excluded);
                    }
                ),
                RecursiveIteratorIterator::SELF_FIRST
            );
            $alreadyCompressed = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'woff', 'woff2', 'zip', 'gz', 'mp4', 'pdf');
            $canStore = $useZip && method_exists($zip, 'setCompressionName');
            foreach ($iter as $item) {
                $rel = autobackup_relative($root, $item->getPathname());
                if ($item->isDir()) {
                    $zip->addEmptyDir($rel);
                    continue;
                }
                if (!$item->isFile() || !$item->isReadable()) {
                    continue;
                }
                $zip->addFile($item->getPathname(), $rel);
                if ($canStore && in_array(strtolower($item->getExtension()), $alreadyCompressed, true)) {
                    $zip->setCompressionName($rel, ZipArchive::CM_STORE); // already compressed: stored only, faster
                }
                $nFiles++;
                $originalBytes += (int)$item->getSize();
                // ZipArchive keeps files open until close: every 500 files close and reopen the archive
                if ($useZip && $nFiles % 500 === 0) {
                    if (!$zip->close()) {
                        throw new Exception('Intermediate zip close failed: ' . $zip->getStatusString());
                    }
                    if ($zip->open($tmp) !== true) {
                        throw new Exception('Reopening the zip failed');
                    }
                }
            }

            if ($useZip) {
                if (!$zip->close()) {
                    throw new Exception('Zip close failed: ' . $zip->getStatusString());
                }
                if (!@rename($tmp, $dest)) {
                    throw new Exception('Cannot rename the archive to ' . $name);
                }
            } else {
                $zip->compress(Phar::GZ);
                unset($zip);
                @unlink($tmp);
                if (!@rename($tmp . '.gz', $dest)) {
                    throw new Exception('Cannot rename the archive to ' . $name);
                }
            }
        } catch (Exception $e) {
            @unlink($tmp);
            @unlink($tmp . '.gz');
            @unlink($tmpDb);
            throw $e;
        }
        @unlink($tmpDb);
        autobackup_rotate($folder, '/^site_.+\.(zip|tar\.gz)$/', (int)$cfg['keep_site']);
        $detail = $nFiles . ' files, ' . autobackup_size($originalBytes) . ' original -> '
            . autobackup_size((int)@filesize($dest)) . ', database ' . $infoDb['tables'] . ' tables/'
            . $infoDb['rows'] . ' rows, ' . round(microtime(true) - $start, 1) . ' s';
        return array('file' => $name, 'detail' => $detail);
    }

    /** Configuration and environment checks (test action): report lines, $ok = all passed. */
    function autobackup_test(array $cfg, &$ok)
    {
        $ok = true;
        $r = array();
        $line = function ($passed, $text) use (&$r, &$ok) {
            $r[] = ($passed ? 'OK       ' : 'ERROR    ') . $text;
            if (!$passed) {
                $ok = false;
            }
        };

        // 1. paths
        $r[] = '== Paths';
        $r[] = '         script: ' . __FILE__;
        $r[] = '         current working directory: ' . (($cwd = @getcwd()) !== false ? $cwd : 'not detectable');
        $r[] = '         web root (DOCUMENT_ROOT): ' . (isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== '' ? $_SERVER['DOCUMENT_ROOT'] : 'not set');
        $root = realpath($cfg['site_root']);
        $line($root !== false && is_dir($root) && is_readable($root), 'site root: ' . ($root !== false ? $root : $cfg['site_root']));
        $folder = autobackup_folder($cfg, $error);
        if ($folder === false) {
            $line(false, 'backup folder: ' . $cfg['folder'] . ' (' . $error . ')');
        } else {
            $real = realpath($folder);
            $line(true, 'backup folder: ' . $real);
            $line(file_exists($folder . '/.htaccess') && file_exists($folder . '/index.php'), 'protective .htaccess and index.php present in the folder');
            $docroot = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== '' ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
            $insideWeb = false;
            foreach (array($docroot, $root) as $base) {
                if ($base !== false && $real !== false && strpos($real . DIRECTORY_SEPARATOR, $base . DIRECTORY_SEPARATOR) === 0) {
                    $insideWeb = true;
                }
            }
            $r[] = ($insideWeb ? 'WARNING  ' : 'OK       ') . ($insideWeb
                ? 'the folder is inside the web root: it is protected only by its .htaccess'
                : 'the folder is outside the web root');

            // 2. writing
            $r[] = '== Writing';
            $probe = $folder . '/.test_' . substr(md5(uniqid('', true)), 0, 8);
            $content = 'autobackup ' . microtime(true);
            $written = @file_put_contents($probe, $content) !== false;
            $readBack = $written && @file_get_contents($probe) === $content;
            @unlink($probe);
            $line($written && $readBack && !file_exists($probe), 'creating, reading and deleting a probe file');
            $free = @disk_free_space($folder);
            $line($free === false || $free > 52428800, 'free space: ' . ($free === false ? 'not detectable' : autobackup_size($free)));
        }

        // 3. database
        $r[] = '== Database';
        $db = $cfg['db'];
        $r[] = '         configured connection: ' . $db['user'] . '@' . $db['host'] . ':' . $db['port'] . ' database ' . $db['name'];
        $c = autobackup_connect($db, $error);
        if ($c === false) {
            $line(false, 'connection: ' . $error);
        } else {
            $line(true, 'connected, MySQL server ' . mysqli_get_server_info($c));
            try {
                $tab = autobackup_tables($c, $cfg);
                $line(count($tab['save']) > 0, 'tables to save: ' . count($tab['save']) . ' (' . implode(', ', $tab['save']) . ')');
                $r[] = '         excluded tables: ' . (count($tab['excluded']) ? implode(', ', $tab['excluded']) : 'none');
                if ($tab['views']) {
                    $r[] = '         views skipped: ' . implode(', ', $tab['views']);
                }
            } catch (Exception $e) {
                $line(false, 'table list: ' . $e->getMessage());
            }
            mysqli_close($c);
        }

        // 4. environment
        $r[] = '== Environment';
        $line(true, 'PHP ' . PHP_VERSION . ' (' . PHP_SAPI . ')');
        $line(function_exists('gzopen'), 'gzip compression (zlib) ' . (function_exists('gzopen') ? 'available' : 'missing: dumps will be uncompressed .sql'));
        $zipOk = class_exists('ZipArchive');
        $r[] = ($zipOk || class_exists('PharData') ? 'OK       ' : 'ERROR    ') . 'site archive: '
            . ($zipOk ? 'ZipArchive available' : (class_exists('PharData') ? 'ZipArchive missing, PharData (tar.gz) will be used' : 'no library available'));
        if (!$zipOk && !class_exists('PharData')) {
            $ok = false;
        }
        $r[] = '         memory_limit ' . ini_get('memory_limit') . ', max_execution_time ' . ini_get('max_execution_time') . ', flock ' . (function_exists('flock') ? 'yes' : 'no');
        try {
            new DateTimeZone($cfg['timezone']);
            $line(true, 'time zone ' . $cfg['timezone'] . ', now ' . autobackup_now($cfg, 'Y-m-d H:i:s'));
        } catch (Exception $e) {
            $line(false, 'invalid time zone: ' . $cfg['timezone']);
        }
        $r[] = ($cfg['web_key'] !== '' ? 'OK       ' : 'WARNING  ') . 'web key ' . ($cfg['web_key'] !== '' ? 'set' : 'empty: browser actions disabled');
        // sample key, new on every run: copy it into 'web_key' if you want to change it
        try {
            $sample = bin2hex(random_bytes(16));
        } catch (Exception $e) {
            $sample = md5(uniqid('', true) . mt_rand());
        }
        $r[] = '         sample key for web_key (generated now, not saved): ' . $sample;

        // 5. status
        $r[] = '== Status';
        if ($folder !== false) {
            $gz = function_exists('gzopen');
            $expected = 'db_' . autobackup_safe_name($db['name']) . '_' . autobackup_now($cfg, 'Y-m-d') . ($gz ? '.sql.gz' : '.sql');
            $r[] = '         today\'s file: ' . $expected . (file_exists($folder . '/' . $expected) ? ' (already present)' : ' (not created yet)');
            $nDb = 0;
            $nSite = 0;
            foreach ((array)@scandir($folder) as $f) {
                if (preg_match('/^db_.+\.sql(\.gz)?$/', $f)) {
                    $nDb++;
                } elseif (preg_match('/^site_.+\.(zip|tar\.gz)$/', $f)) {
                    $nSite++;
                }
            }
            $r[] = '         backups present: ' . $nDb . ' database (keep ' . (int)$cfg['keep_db'] . '), ' . $nSite . ' site (keep ' . (int)$cfg['keep_site'] . ')';
            $log = $folder . '/autobackup.log';
            if (is_file($log)) {
                $logLines = array_slice(array_filter(array_map('rtrim', (array)@file($log))), -5);
                $r[] = '         last log lines:';
                foreach ($logLines as $l) {
                    $r[] = '           ' . $l;
                }
            } else {
                $r[] = '         log: still empty';
            }
        }
        $r[] = '';
        $r[] = $ok ? 'All checks passed.' : 'Some checks failed: see the ERROR lines.';
        return $r;
    }
}

autobackup_run($autobackup_config);
unset($autobackup_config);
