<?php
/**
 * AutoBackup v.1.0
 * Copyright (2026) by Onix.it
 * Licenza MIT: vedi il file LICENSE
 * @license MIT
 *
 * Script autonomo senza dipendenze
 * Compatibile con PHP 7.4 e 8.x; usa solo mysqli, zlib (gzopen) e ZipArchive/PharData.
 *
 * COME SI USA
 *  1. Backup giornaliero del database (include semplice, ad esempio dopo il login del backend):
 *         include __DIR__ . '/autobackup.php';
 *     Il primo include della giornata salva il database in <cartella>/db_<nome>_<AAAA-MM-GG>.sql.gz,
 *     gli altri trovano il file di oggi e non fanno nulla. Non produce output e non lancia eccezioni:
 *     gli errori finiscono in <cartella>/autobackup.log.
 *  2. Dal browser, con la chiave segreta 'chiave_web' (vuota = accesso dal web disabilitato, risponde 404):
 *         autobackup.php?azione=test&chiave=...   controlla percorsi, cartella, connessione e ambiente
 *         autobackup.php?azione=db&chiave=...     dump del database subito (nome con data e ora)
 *         autobackup.php?azione=sito&chiave=...   archivio compresso di tutto il sito + dump del database
 *  3. Da riga di comando: php autobackup.php test|db|sito
 *
 * I file sono scritti in 'cartella' (predefinita: fuori dalla radice web, accanto alla cartella del sito),
 * creata con .htaccess e index.php di protezione. Si conservano gli ultimi 'conserva_db' dump di giorni
 * diversi e gli ultimi 'conserva_sito' archivi del sito.
 *
 * Questo file contiene le credenziali del database: va trattato come config.php.
 */

$autobackup_config = array(
    // Cartella dei backup (percorso assoluto). Predefinita: cartella "backup" accanto alla radice del sito,
    // quindi fuori dalla cartella pubblica (es. /home/utente/backup accanto a /home/utente/public_html).
    'cartella'          => dirname(__DIR__) . '/backup',

    // Radice del sito da archiviare con azione=sito.
    'radice_sito'       => __DIR__,

    // Fuso orario per data e ora nei nomi dei file e nel log.
    'fuso_orario'       => 'Europe/Rome',

    // Credenziali del database.
    'db'                => array(
        'host'     => 'localhost',
        'porta'    => 3306,
        'utente'   => '',
        'password' => '',
        'nome'     => '',
    ),
    // Tabelle da NON salvare: per prefisso e per nome esatto.
    'escludi_prefissi'  => array(),            // es. array('stats_')
    'escludi_tabelle'   => array(),

    // Quanti file conservare (i piu' recenti).
    'conserva_db'       => 30,
    'conserva_sito'     => 5,

    // Chiave segreta per le azioni dal browser (?azione=...&chiave=...). Vuota = nessun accesso dal web.
    'chiave_web'        => '',

    // Cartelle e file del sito esclusi dall'archivio (percorsi relativi alla radice, con /).
    'sito_escludi'      => array('.git', 'node_modules'),

    // Dimensione massima (byte) di un singolo INSERT multiplo nel dump.
    'blocco_insert'     => 524288,
);

if (!function_exists('autobackup_avvia')) {

    /**
     * Punto di ingresso: decide la modalita' (include, web, riga di comando) ed esegue.
     * Non lascia uscire eccezioni: in modalita' include il chiamante non deve accorgersi di nulla.
     */
    function autobackup_avvia(array $cfg)
    {
        static $avviato = false;
        if ($avviato) {
            return; // incluso due volte nella stessa richiesta: il lavoro e' gia' stato fatto
        }
        $avviato = true;
        $diretto = false;
        if (isset($_SERVER['SCRIPT_FILENAME'])) {
            $script = realpath($_SERVER['SCRIPT_FILENAME']);
            $diretto = $script !== false && $script === realpath(__FILE__);
        }
        $cli = PHP_SAPI === 'cli';

        // --- Include da un altro script: backup giornaliero silenzioso -------------------------------
        if (!$diretto) {
            try {
                @ignore_user_abort(true);
                @set_time_limit(300);
                $cartella = autobackup_cartella($cfg, $errore);
                if ($cartella === false) {
                    autobackup_log(false, 'Cartella dei backup non disponibile: ' . $errore);
                    return;
                }
                $esito = autobackup_db_giornaliero($cfg, $cartella, false);
                if ($esito['stato'] === 'creato') {
                    autobackup_log($cartella, 'Backup giornaliero creato: ' . $esito['file'] . ' (' . $esito['dettaglio'] . ')');
                }
            } catch (Throwable $e) {
                autobackup_log(isset($cartella) && $cartella !== false ? $cartella : false, 'ERRORE backup giornaliero: ' . $e->getMessage());
            }
            return;
        }

        // --- Richiesta diretta: dal browser (con chiave) o da riga di comando ------------------------
        if ($cli) {
            $azione = isset($_SERVER['argv'][1]) ? (string)$_SERVER['argv'][1] : 'test';
        } else {
            $chiave = isset($_GET['chiave']) ? (string)$_GET['chiave'] : '';
            if ($cfg['chiave_web'] === '' || $chiave === '' || !hash_equals((string)$cfg['chiave_web'], $chiave)) {
                http_response_code(404);
                exit;
            }
            $azione = isset($_GET['azione']) ? (string)$_GET['azione'] : '';
            header('Content-Type: text/plain; charset=utf-8');
            header('X-Robots-Tag: noindex, nofollow');
            header('Cache-Control: no-store');
        }
        if (!in_array($azione, array('test', 'db', 'sito'), true)) {
            if ($cli) {
                echo "Uso: php autobackup.php test|db|sito\n";
                exit(1);
            }
            http_response_code(404);
            exit;
        }

        @ignore_user_abort(true);
        @set_time_limit($azione === 'sito' ? 0 : 300);
        $righe = array();
        $ok = true;
        $cartella = false;
        try {
            if ($azione === 'test') {
                $righe = autobackup_test($cfg, $ok);
            } else {
                $cartella = autobackup_cartella($cfg, $errore);
                if ($cartella === false) {
                    throw new Exception('Cartella dei backup non disponibile: ' . $errore);
                }
                if ($azione === 'db') {
                    $esito = autobackup_db_giornaliero($cfg, $cartella, true);
                    if ($esito['stato'] !== 'creato') {
                        throw new Exception('Backup non eseguito: ' . $esito['stato']);
                    }
                    autobackup_log($cartella, 'Dump su richiesta creato: ' . $esito['file'] . ' (' . $esito['dettaglio'] . ')');
                    $righe[] = 'OK  dump del database: ' . $esito['file'] . ' (' . $esito['dettaglio'] . ')';
                } else {
                    $esito = autobackup_sito($cfg, $cartella);
                    autobackup_log($cartella, 'Archivio del sito creato: ' . $esito['file'] . ' (' . $esito['dettaglio'] . ')');
                    $righe[] = 'OK  archivio del sito: ' . $esito['file'] . ' (' . $esito['dettaglio'] . ')';
                }
                $righe[] = '    cartella: ' . $cartella;
            }
        } catch (Throwable $e) {
            $ok = false;
            $righe[] = 'ERRORE  ' . $e->getMessage();
            autobackup_log($cartella, 'ERRORE azione ' . $azione . ': ' . $e->getMessage());
        }
        if (!$cli && !$ok) {
            http_response_code(500);
        }
        echo "autobackup - azione: " . $azione . " - " . autobackup_ora($cfg, 'Y-m-d H:i:s') . "\n\n";
        echo implode("\n", $righe) . "\n";
        if ($cli) {
            exit($ok ? 0 : 1);
        }
        exit;
    }

    /** Data e ora correnti nel fuso orario configurato (UTC se non valido). */
    function autobackup_ora(array $cfg, $formato)
    {
        try {
            $tz = new DateTimeZone($cfg['fuso_orario']);
        } catch (Exception $e) {
            $tz = new DateTimeZone('UTC');
        }
        autobackup_fuso($tz);
        $d = new DateTime('now', $tz);
        return $d->format($formato);
    }

    /** Ricorda l'ultimo fuso orario valido (usato dal log, che non riceve la configurazione). */
    function autobackup_fuso($tz = null)
    {
        static $memo = null;
        if ($tz !== null) {
            $memo = $tz;
        }
        return $memo;
    }

    /** Crea (se serve) e protegge la cartella dei backup; restituisce il percorso o false con $errore. */
    function autobackup_cartella(array $cfg, &$errore)
    {
        $errore = '';
        $dir = rtrim((string)$cfg['cartella'], '/\\');
        if ($dir === '') {
            $errore = 'percorso vuoto';
            return false;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            $errore = 'impossibile creare ' . $dir;
            return false;
        }
        if (!is_writable($dir)) {
            $errore = 'cartella non scrivibile ' . $dir;
            return false;
        }
        $reale = realpath($dir);
        if ($reale !== false) {
            $dir = $reale;
        }
        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess,
                "# Backup: nessun accesso dal web\n"
                . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n");
        }
        $index = $dir . '/index.php';
        if (!file_exists($index)) {
            @file_put_contents($index, "<?php\nhttp_response_code(404);\n");
        }
        // resti di esecuzioni interrotte: file temporanei piu' vecchi di un'ora
        foreach ((array)@scandir($dir) as $f) {
            if (preg_match('/^(\..+|.+\.tmp(\.gz)?)$/', $f) && $f !== '.htaccess' && $f !== '.lock'
                && is_file($dir . '/' . $f) && @filemtime($dir . '/' . $f) < time() - 3600) {
                @unlink($dir . '/' . $f);
            }
        }
        return $dir;
    }

    /** Scrive una riga nel log della cartella dei backup (o nel log di PHP se la cartella manca). */
    function autobackup_log($cartella, $messaggio)
    {
        $tz = autobackup_fuso();
        $ora = $tz instanceof DateTimeZone ? new DateTime('now', $tz) : new DateTime('now');
        $riga = '[' . $ora->format('Y-m-d H:i:s') . '] ' . $messaggio;
        if ($cartella === false || $cartella === '') {
            @error_log('autobackup: ' . $messaggio);
            return;
        }
        $file = $cartella . '/autobackup.log';
        if (is_file($file) && filesize($file) > 1048576) {
            @rename($file, $file . '.old');
        }
        @file_put_contents($file, $riga . "\n", FILE_APPEND | LOCK_EX);
    }

    /** Nome di file sicuro (solo lettere, numeri, trattini). */
    function autobackup_nome_sicuro($testo)
    {
        $s = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)$testo);
        return $s === '' ? 'db' : $s;
    }

    /** Apre una connessione mysqli dedicata; false e $errore se fallisce (anche in modalita' MYSQLI_REPORT_STRICT). */
    function autobackup_connetti(array $db, &$errore)
    {
        $errore = '';
        if ((string)$db['nome'] === '' || (string)$db['utente'] === '') {
            $errore = 'credenziali non compilate nel blocco di configurazione (utente o nome del database vuoti)';
            return false;
        }
        try {
            $c = mysqli_init();
            if (!$c) {
                $errore = 'mysqli non disponibile';
                return false;
            }
            @mysqli_options($c, MYSQLI_OPT_CONNECT_TIMEOUT, 5);
            $ok = @mysqli_real_connect($c, $db['host'], $db['utente'], $db['password'], $db['nome'], (int)$db['porta']);
            if (!$ok) {
                $errore = mysqli_connect_error();
                return false;
            }
            if (!mysqli_set_charset($c, 'utf8mb4')) {
                $errore = 'charset utf8mb4 non impostabile: ' . mysqli_error($c);
                mysqli_close($c);
                return false;
            }
            return $c;
        } catch (Exception $e) {
            $errore = $e->getMessage();
            return false;
        }
    }

    /** Esegue una query e lancia un'eccezione con il messaggio di MySQL se fallisce. */
    function autobackup_query($c, $sql, $modo = MYSQLI_STORE_RESULT)
    {
        $r = mysqli_query($c, $sql, $modo);
        if ($r === false) {
            throw new Exception('Query fallita (' . mysqli_error($c) . '): ' . substr($sql, 0, 120));
        }
        return $r;
    }

    /** True se la tabella va esclusa dal dump. */
    function autobackup_tabella_esclusa($nome, array $cfg)
    {
        $n = strtolower($nome);
        foreach ($cfg['escludi_tabelle'] as $t) {
            if (strtolower($t) === $n) {
                return true;
            }
        }
        foreach ($cfg['escludi_prefissi'] as $p) {
            if ($p !== '' && strpos($n, strtolower($p)) === 0) {
                return true;
            }
        }
        return false;
    }

    /** Elenco delle tabelle del database: array('salva' => [...], 'escluse' => [...], 'viste' => [...]). */
    function autobackup_tabelle($c, array $cfg)
    {
        $out = array('salva' => array(), 'escluse' => array(), 'viste' => array());
        $r = autobackup_query($c, 'SHOW FULL TABLES');
        while ($riga = mysqli_fetch_row($r)) {
            if (isset($riga[1]) && stripos($riga[1], 'VIEW') !== false) {
                $out['viste'][] = $riga[0];
            } elseif (autobackup_tabella_esclusa($riga[0], $cfg)) {
                $out['escluse'][] = $riga[0];
            } else {
                $out['salva'][] = $riga[0];
            }
        }
        mysqli_free_result($r);
        return $out;
    }

    /** Scrive sul file (gz o normale). */
    function autobackup_scrivi($h, $gz, $testo)
    {
        $n = $gz ? gzwrite($h, $testo) : fwrite($h, $testo);
        if ($n === false) {
            throw new Exception('Scrittura del backup fallita (disco pieno o permessi)');
        }
    }

    /** Valore SQL di un campo in base al tipo della colonna. */
    function autobackup_valore_sql($c, $valore, $campo)
    {
        if ($valore === null) {
            return 'NULL';
        }
        static $numerici = array(
            MYSQLI_TYPE_TINY, MYSQLI_TYPE_SHORT, MYSQLI_TYPE_LONG, MYSQLI_TYPE_LONGLONG, MYSQLI_TYPE_INT24,
            MYSQLI_TYPE_FLOAT, MYSQLI_TYPE_DOUBLE, MYSQLI_TYPE_DECIMAL, MYSQLI_TYPE_NEWDECIMAL, MYSQLI_TYPE_YEAR,
        );
        if (in_array($campo->type, $numerici, true) && preg_match('/^-?\d+(\.\d+)?([eE][-+]?\d+)?$/', $valore)) {
            return $valore;
        }
        if ($campo->type === MYSQLI_TYPE_BIT || (($campo->flags & MYSQLI_BINARY_FLAG) && $campo->charsetnr == 63)) {
            return $valore === '' ? "''" : '0x' . bin2hex($valore);
        }
        return "'" . mysqli_real_escape_string($c, $valore) . "'";
    }

    /**
     * Dump SQL del database in $destinazione (gzip se zlib e' disponibile).
     * Restituisce array con tabelle, righe e byte scritti. Lancia eccezioni in caso di errore.
     */
    function autobackup_dump(array $cfg, array $db, $destinazione, $gz)
    {
        $c = autobackup_connetti($db, $errore);
        if ($c === false) {
            throw new Exception('Connessione al database fallita: ' . $errore);
        }
        $h = $gz ? @gzopen($destinazione, 'wb6') : @fopen($destinazione, 'wb');
        if (!$h) {
            mysqli_close($c);
            throw new Exception('Impossibile creare il file ' . $destinazione);
        }
        $righeTotali = 0;
        $tabelleSalvate = 0;
        try {
            $tabelle = autobackup_tabelle($c, $cfg);
            // escape con backslash garantito (NO_BACKSLASH_ESCAPES disattivato) e lettura coerente
            // di tutte le tabelle allo stesso istante (InnoDB), senza bloccare il sito
            autobackup_query($c, "SET SESSION sql_mode = ''");
            // i TIMESTAMP vengono letti in UTC, lo stesso fuso dichiarato in testa al file: il ripristino non li sposta
            autobackup_query($c, "SET SESSION time_zone = '+00:00'");
            autobackup_query($c, 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            autobackup_query($c, 'START TRANSACTION WITH CONSISTENT SNAPSHOT');

            $testa = "-- autobackup.php - dump del database `" . $db['nome'] . "`\n"
                . "-- Creato il " . autobackup_ora($cfg, 'Y-m-d H:i:s') . " (" . $cfg['fuso_orario'] . ")\n"
                . "-- Server: " . mysqli_get_server_info($c) . " - PHP " . PHP_VERSION . "\n"
                . "-- Tabelle salvate: " . count($tabelle['salva'])
                . ($tabelle['escluse'] ? " - escluse: " . implode(', ', $tabelle['escluse']) : '')
                . ($tabelle['viste'] ? " - viste ignorate: " . implode(', ', $tabelle['viste']) : '') . "\n\n"
                . "SET NAMES utf8mb4;\n"
                . "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n"
                . "SET time_zone = '+00:00';\n"
                . "SET FOREIGN_KEY_CHECKS = 0;\n\n";
            autobackup_scrivi($h, $gz, $testa);

            foreach ($tabelle['salva'] as $tabella) {
                $tq = '`' . str_replace('`', '``', $tabella) . '`';
                $r = autobackup_query($c, 'SHOW CREATE TABLE ' . $tq);
                $cr = mysqli_fetch_row($r);
                mysqli_free_result($r);
                autobackup_scrivi($h, $gz, "--\n-- Tabella " . $tq . "\n--\n\nDROP TABLE IF EXISTS " . $tq . ";\n" . $cr[1] . ";\n\n");

                // colonne esplicite: salta quelle generate (non si possono inserire) e include le invisibili
                $colonne = array();
                $rc = autobackup_query($c, "SELECT COLUMN_NAME, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '"
                    . mysqli_real_escape_string($c, $tabella) . "' ORDER BY ORDINAL_POSITION");
                while ($col = mysqli_fetch_row($rc)) {
                    // EXTRA vale "VIRTUAL GENERATED" / "STORED GENERATED" per le colonne calcolate;
                    // "DEFAULT_GENERATED" (default con espressione, es. CURRENT_TIMESTAMP) va invece salvata
                    if (!preg_match('/(VIRTUAL|STORED) GENERATED/i', (string)$col[1])) {
                        $colonne[] = '`' . str_replace('`', '``', $col[0]) . '`';
                    }
                }
                mysqli_free_result($rc);
                if (!$colonne) {
                    $tabelleSalvate++;
                    continue;
                }
                $r = autobackup_query($c, 'SELECT ' . implode(', ', $colonne) . ' FROM ' . $tq, MYSQLI_USE_RESULT);
                $campi = mysqli_fetch_fields($r);
                $inizio = 'INSERT INTO ' . $tq . ' (' . implode(', ', $colonne) . ") VALUES\n";
                $buffer = '';
                $righe = 0;
                while ($riga = mysqli_fetch_row($r)) {
                    $valori = array();
                    foreach ($riga as $i => $v) {
                        $valori[] = autobackup_valore_sql($c, $v, $campi[$i]);
                    }
                    $buffer .= ($buffer === '' ? $inizio : ",\n") . '(' . implode(', ', $valori) . ')';
                    $righe++;
                    if (strlen($buffer) >= (int)$cfg['blocco_insert']) {
                        autobackup_scrivi($h, $gz, $buffer . ";\n");
                        $buffer = '';
                    }
                }
                mysqli_free_result($r);
                if ($buffer !== '') {
                    autobackup_scrivi($h, $gz, $buffer . ";\n");
                }
                autobackup_scrivi($h, $gz, "\n");
                $righeTotali += $righe;
                $tabelleSalvate++;
            }
            autobackup_scrivi($h, $gz, "SET FOREIGN_KEY_CHECKS = 1;\n-- fine del dump\n");
        } catch (Exception $e) {
            if ($gz) { gzclose($h); } else { fclose($h); }
            mysqli_close($c);
            throw $e;
        }
        if ($gz) { gzclose($h); } else { fclose($h); }
        mysqli_close($c);
        return array('tabelle' => $tabelleSalvate, 'righe' => $righeTotali, 'byte' => (int)@filesize($destinazione));
    }

    /**
     * Backup del database nella cartella: una volta al giorno (o subito se $forzato, con ora nel nome).
     * Restituisce array('stato' => 'creato'|'esiste'|'occupato', 'file' => ..., 'dettaglio' => ...).
     */
    function autobackup_db_giornaliero(array $cfg, $cartella, $forzato)
    {
        $db = $cfg['db'];
        $gz = function_exists('gzopen');
        $nome = 'db_' . autobackup_nome_sicuro($db['nome']) . '_'
            . autobackup_ora($cfg, $forzato ? 'Y-m-d_His' : 'Y-m-d') . ($gz ? '.sql.gz' : '.sql');
        $dest = $cartella . '/' . $nome;
        if (!$forzato && file_exists($dest)) {
            return array('stato' => 'esiste', 'file' => $nome, 'dettaglio' => '');
        }
        $lock = @fopen($cartella . '/.lock', 'c');
        if (!$lock) {
            throw new Exception('Impossibile creare il file di lock in ' . $cartella);
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return array('stato' => 'occupato', 'file' => $nome, 'dettaglio' => 'un altro backup e\' in corso');
        }
        try {
            if (!$forzato && file_exists($dest)) {
                return array('stato' => 'esiste', 'file' => $nome, 'dettaglio' => '');
            }
            $tmp = $dest . '.tmp';
            $inizio = microtime(true);
            try {
                $info = autobackup_dump($cfg, $db, $tmp, $gz);
            } catch (Exception $e) {
                @unlink($tmp);
                throw $e;
            }
            if (!@rename($tmp, $dest)) {
                @unlink($tmp);
                throw new Exception('Impossibile rinominare il file temporaneo in ' . $nome);
            }
            autobackup_ruota($cartella, '/^db_.+\.sql(\.gz)?$/', (int)$cfg['conserva_db']);
            $dettaglio = $info['tabelle'] . ' tabelle, ' . $info['righe'] . ' righe, '
                . autobackup_dimensione($info['byte']) . ', ' . round(microtime(true) - $inizio, 2) . ' s';
            return array('stato' => 'creato', 'file' => $nome, 'dettaglio' => $dettaglio);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Conserva solo gli ultimi $conserva file che corrispondono al pattern (ordine per nome = per data). */
    function autobackup_ruota($cartella, $pattern, $conserva)
    {
        if ($conserva < 1) {
            return 0;
        }
        $file = array();
        foreach ((array)@scandir($cartella) as $f) {
            if (preg_match($pattern, $f)) {
                $file[] = $f;
            }
        }
        rsort($file, SORT_STRING);
        $cancellati = 0;
        foreach (array_slice($file, $conserva) as $f) {
            if (@unlink($cartella . '/' . $f)) {
                $cancellati++;
            }
        }
        return $cancellati;
    }

    /** Dimensione leggibile. */
    function autobackup_dimensione($byte)
    {
        $byte = (float)$byte;
        if ($byte >= 1048576) {
            return round($byte / 1048576, 1) . ' MB';
        }
        if ($byte >= 1024) {
            return round($byte / 1024) . ' KB';
        }
        return (int)$byte . ' byte';
    }

    /** Percorso relativo alla radice (separatore /) di un elemento del sito. */
    function autobackup_relativo($radice, $percorso)
    {
        $rel = substr($percorso, strlen($radice));
        return ltrim(str_replace('\\', '/', $rel), '/');
    }

    /** True se il percorso relativo e' tra le esclusioni (voce esatta o cartella padre). */
    function autobackup_sito_escluso($relativo, array $esclusi)
    {
        foreach ($esclusi as $e) {
            $e = trim(str_replace('\\', '/', $e), '/');
            if ($e === '') {
                continue;
            }
            if ($relativo === $e || strpos($relativo, $e . '/') === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Archivio compresso di tutto il sito (zip, oppure tar.gz se ZipArchive manca) con dentro un dump del database.
     * Restituisce array('file' => nome, 'dettaglio' => ...).
     */
    function autobackup_sito(array $cfg, $cartella)
    {
        $radice = realpath($cfg['radice_sito']);
        if ($radice === false || !is_dir($radice)) {
            throw new Exception('Radice del sito non trovata: ' . $cfg['radice_sito']);
        }
        $usaZip = class_exists('ZipArchive');
        if (!$usaZip && !class_exists('PharData')) {
            throw new Exception('Ne\' ZipArchive ne\' PharData sono disponibili: impossibile creare l\'archivio');
        }
        $inizio = microtime(true);
        $db = $cfg['db'];
        $gz = function_exists('gzopen');

        // esclusioni: quelle configurate + la cartella dei backup se sta dentro al sito
        $esclusi = (array)$cfg['sito_escludi'];
        $cartellaReale = realpath($cartella);
        if ($cartellaReale !== false && strpos($cartellaReale, $radice . DIRECTORY_SEPARATOR) === 0) {
            $esclusi[] = autobackup_relativo($radice, $cartellaReale);
        }

        // dump del database in un file temporaneo da includere nell'archivio
        $tmpDb = $cartella . '/.db_' . uniqid('', true) . ($gz ? '.sql.gz' : '.sql');
        $infoDb = autobackup_dump($cfg, $db, $tmpDb, $gz);
        $nomeDb = '_database_' . autobackup_nome_sicuro($db['nome']) . ($gz ? '.sql.gz' : '.sql');

        $timbro = autobackup_ora($cfg, 'Y-m-d_His');
        $nome = 'sito_' . $timbro . ($usaZip ? '.zip' : '.tar.gz');
        $dest = $cartella . '/' . $nome;
        $tmp = $cartella . '/.sito_' . $timbro . '_' . substr(md5(uniqid('', true)), 0, 6) . ($usaZip ? '.zip' : '.tar');
        $nFile = 0;
        $byteOriginali = 0;
        try {
            if ($usaZip) {
                $zip = new ZipArchive();
                $apertura = $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
                if ($apertura !== true) {
                    throw new Exception('ZipArchive non apre ' . $tmp . ' (codice ' . $apertura . ')');
                }
                $zip->addFile($tmpDb, $nomeDb);
            } else {
                $zip = new PharData($tmp);
                $zip->addFile($tmpDb, $nomeDb);
            }

            $iter = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($radice, FilesystemIterator::SKIP_DOTS),
                    function ($corrente, $chiave, $iteratore) use ($radice, $esclusi) {
                        return !autobackup_sito_escluso(autobackup_relativo($radice, $corrente->getPathname()), $esclusi);
                    }
                ),
                RecursiveIteratorIterator::SELF_FIRST
            );
            $giaCompressi = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'woff', 'woff2', 'zip', 'gz', 'mp4', 'pdf');
            $storePossibile = $usaZip && method_exists($zip, 'setCompressionName');
            foreach ($iter as $elemento) {
                $rel = autobackup_relativo($radice, $elemento->getPathname());
                if ($elemento->isDir()) {
                    $zip->addEmptyDir($rel);
                    continue;
                }
                if (!$elemento->isFile() || !$elemento->isReadable()) {
                    continue;
                }
                $zip->addFile($elemento->getPathname(), $rel);
                if ($storePossibile && in_array(strtolower($elemento->getExtension()), $giaCompressi, true)) {
                    $zip->setCompressionName($rel, ZipArchive::CM_STORE); // gia' compressi: solo archiviati, piu' veloce
                }
                $nFile++;
                $byteOriginali += (int)$elemento->getSize();
                // ZipArchive tiene aperti i file fino alla chiusura: ogni 500 file chiude e riapre l'archivio
                if ($usaZip && $nFile % 500 === 0) {
                    if (!$zip->close()) {
                        throw new Exception('Chiusura intermedia dello zip fallita: ' . $zip->getStatusString());
                    }
                    if ($zip->open($tmp) !== true) {
                        throw new Exception('Riapertura dello zip fallita');
                    }
                }
            }

            if ($usaZip) {
                if (!$zip->close()) {
                    throw new Exception('Chiusura dello zip fallita: ' . $zip->getStatusString());
                }
                if (!@rename($tmp, $dest)) {
                    throw new Exception('Impossibile rinominare l\'archivio in ' . $nome);
                }
            } else {
                $zip->compress(Phar::GZ);
                unset($zip);
                @unlink($tmp);
                if (!@rename($tmp . '.gz', $dest)) {
                    throw new Exception('Impossibile rinominare l\'archivio in ' . $nome);
                }
            }
        } catch (Exception $e) {
            @unlink($tmp);
            @unlink($tmp . '.gz');
            @unlink($tmpDb);
            throw $e;
        }
        @unlink($tmpDb);
        autobackup_ruota($cartella, '/^sito_.+\.(zip|tar\.gz)$/', (int)$cfg['conserva_sito']);
        $dettaglio = $nFile . ' file, ' . autobackup_dimensione($byteOriginali) . ' originali -> '
            . autobackup_dimensione((int)@filesize($dest)) . ', database ' . $infoDb['tabelle'] . ' tabelle/'
            . $infoDb['righe'] . ' righe, ' . round(microtime(true) - $inizio, 1) . ' s';
        return array('file' => $nome, 'dettaglio' => $dettaglio);
    }

    /** Controlli di configurazione e ambiente (azione test): righe del rapporto, $ok = tutti superati. */
    function autobackup_test(array $cfg, &$ok)
    {
        $ok = true;
        $r = array();
        $riga = function ($esito, $testo) use (&$r, &$ok) {
            $r[] = ($esito ? 'OK      ' : 'ERRORE  ') . $testo;
            if (!$esito) {
                $ok = false;
            }
        };

        // 1. percorsi
        $r[] = '== Percorsi';
        $r[] = '        script: ' . __FILE__;
        $r[] = '        cartella di lavoro corrente: ' . (($cwd = @getcwd()) !== false ? $cwd : 'non rilevabile');
        $r[] = '        radice web (DOCUMENT_ROOT): ' . (isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== '' ? $_SERVER['DOCUMENT_ROOT'] : 'non impostata');
        $radice = realpath($cfg['radice_sito']);
        $riga($radice !== false && is_dir($radice) && is_readable($radice), 'radice del sito: ' . ($radice !== false ? $radice : $cfg['radice_sito']));
        $cartella = autobackup_cartella($cfg, $errore);
        if ($cartella === false) {
            $riga(false, 'cartella dei backup: ' . $cfg['cartella'] . ' (' . $errore . ')');
        } else {
            $reale = realpath($cartella);
            $riga(true, 'cartella dei backup: ' . $reale);
            $riga(file_exists($cartella . '/.htaccess') && file_exists($cartella . '/index.php'), 'protezioni .htaccess e index.php presenti nella cartella');
            $docroot = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== '' ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
            $dentroWeb = false;
            foreach (array($docroot, $radice) as $base) {
                if ($base !== false && $reale !== false && strpos($reale . DIRECTORY_SEPARATOR, $base . DIRECTORY_SEPARATOR) === 0) {
                    $dentroWeb = true;
                }
            }
            $r[] = ($dentroWeb ? 'AVVISO  ' : 'OK      ') . ($dentroWeb
                ? 'la cartella sta dentro la radice web: resta protetta solo dal suo .htaccess'
                : 'la cartella sta fuori dalla radice web');

            // 2. scrittura
            $r[] = '== Scrittura';
            $prova = $cartella . '/.test_' . substr(md5(uniqid('', true)), 0, 8);
            $contenuto = 'autobackup ' . microtime(true);
            $scritto = @file_put_contents($prova, $contenuto) !== false;
            $riletto = $scritto && @file_get_contents($prova) === $contenuto;
            @unlink($prova);
            $riga($scritto && $riletto && !file_exists($prova), 'creazione, lettura e cancellazione di un file di prova');
            $libero = @disk_free_space($cartella);
            $riga($libero === false || $libero > 52428800, 'spazio libero: ' . ($libero === false ? 'non rilevabile' : autobackup_dimensione($libero)));
        }

        // 3. database
        $r[] = '== Database';
        $db = $cfg['db'];
        $r[] = '        connessione configurata: ' . $db['utente'] . '@' . $db['host'] . ':' . $db['porta'] . ' database ' . $db['nome'];
        $c = autobackup_connetti($db, $errore);
        if ($c === false) {
            $riga(false, 'connessione: ' . $errore);
        } else {
            $riga(true, 'connessione riuscita, server MySQL ' . mysqli_get_server_info($c));
            try {
                $tab = autobackup_tabelle($c, $cfg);
                $riga(count($tab['salva']) > 0, 'tabelle da salvare: ' . count($tab['salva']) . ' (' . implode(', ', $tab['salva']) . ')');
                $r[] = '        tabelle escluse: ' . (count($tab['escluse']) ? implode(', ', $tab['escluse']) : 'nessuna');
                if ($tab['viste']) {
                    $r[] = '        viste ignorate: ' . implode(', ', $tab['viste']);
                }
            } catch (Exception $e) {
                $riga(false, 'elenco tabelle: ' . $e->getMessage());
            }
            mysqli_close($c);
        }

        // 4. ambiente
        $r[] = '== Ambiente';
        $riga(true, 'PHP ' . PHP_VERSION . ' (' . PHP_SAPI . ')');
        $riga(function_exists('gzopen'), 'compressione gzip (zlib) ' . (function_exists('gzopen') ? 'disponibile' : 'assente: i dump saranno .sql non compressi'));
        $zipOk = class_exists('ZipArchive');
        $r[] = ($zipOk || class_exists('PharData') ? 'OK      ' : 'ERRORE  ') . 'archivio del sito: '
            . ($zipOk ? 'ZipArchive disponibile' : (class_exists('PharData') ? 'ZipArchive assente, verra\' usato PharData (tar.gz)' : 'nessuna libreria disponibile'));
        if (!$zipOk && !class_exists('PharData')) {
            $ok = false;
        }
        $r[] = '        memory_limit ' . ini_get('memory_limit') . ', max_execution_time ' . ini_get('max_execution_time') . ', flock ' . (function_exists('flock') ? 'si' : 'no');
        try {
            new DateTimeZone($cfg['fuso_orario']);
            $riga(true, 'fuso orario ' . $cfg['fuso_orario'] . ', adesso ' . autobackup_ora($cfg, 'Y-m-d H:i:s'));
        } catch (Exception $e) {
            $riga(false, 'fuso orario non valido: ' . $cfg['fuso_orario']);
        }
        $r[] = ($cfg['chiave_web'] !== '' ? 'OK      ' : 'AVVISO  ') . 'chiave web ' . ($cfg['chiave_web'] !== '' ? 'impostata' : 'vuota: azioni dal browser disabilitate');
        // chiave di esempio, nuova a ogni esecuzione: da copiare in 'chiave_web' se si vuole cambiarla
        try {
            $esempio = bin2hex(random_bytes(16));
        } catch (Exception $e) {
            $esempio = md5(uniqid('', true) . mt_rand());
        }
        $r[] = '        esempio di chiave da usare in chiave_web (generata ora, non salvata): ' . $esempio;

        // 5. stato
        $r[] = '== Stato';
        if ($cartella !== false) {
            $gz = function_exists('gzopen');
            $atteso = 'db_' . autobackup_nome_sicuro($db['nome']) . '_' . autobackup_ora($cfg, 'Y-m-d') . ($gz ? '.sql.gz' : '.sql');
            $r[] = '        file di oggi: ' . $atteso . (file_exists($cartella . '/' . $atteso) ? ' (gia\' presente)' : ' (non ancora creato)');
            $nDb = 0;
            $nSito = 0;
            foreach ((array)@scandir($cartella) as $f) {
                if (preg_match('/^db_.+\.sql(\.gz)?$/', $f)) {
                    $nDb++;
                } elseif (preg_match('/^sito_.+\.(zip|tar\.gz)$/', $f)) {
                    $nSito++;
                }
            }
            $r[] = '        backup presenti: ' . $nDb . ' del database (conserva ' . (int)$cfg['conserva_db'] . '), ' . $nSito . ' del sito (conserva ' . (int)$cfg['conserva_sito'] . ')';
            $log = $cartella . '/autobackup.log';
            if (is_file($log)) {
                $righeLog = array_slice(array_filter(array_map('rtrim', (array)@file($log))), -5);
                $r[] = '        ultime righe del log:';
                foreach ($righeLog as $l) {
                    $r[] = '          ' . $l;
                }
            } else {
                $r[] = '        log: ancora vuoto';
            }
        }
        $r[] = '';
        $r[] = $ok ? 'Tutti i controlli superati.' : 'Alcuni controlli non sono superati: vedi le righe ERRORE.';
        return $r;
    }
}

autobackup_avvia($autobackup_config);
unset($autobackup_config);
