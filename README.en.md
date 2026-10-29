# AutoBackup

[🇮🇹 Italiano](README.md) · 🇬🇧 English

Automatic MySQL database backup and compressed site archive, in a single dependency-free PHP file.

Include it in your admin panel code (for example after login) and, on the first access of each day, it saves the database as a `.sql.gz` file in a protected folder. On demand, from the browser or the command line, it also creates a zip of the whole site.

## Features

- **A single file**, `autobackup.php`: no external library, no `mysqldump`, no system command. Works on shared hosting too.
- **Daily backup**: one dump per day, created by the first include of the day; later includes do nothing.
- **Pure PHP dump** through `mysqli`: consistent read of all tables (InnoDB snapshot) without locking the site, streamed gzip output, constant memory use even with large tables.
- **Excluded tables** by prefix or by name (for example statistics tables).
- **Site archive**: zip of all files (with configurable exclusions) plus a fresh database dump.
- **Rotation**: the last N dumps and the last N archives are kept.
- **Robust**: lock against concurrent runs, write to a temporary file then rename, no output and no exception towards the including code; errors go to its own log.
- **Test action** that checks paths, folder, write access, connection and environment.
- Compatible with **PHP 7.4 and 8.x**.

## Requirements

- PHP 7.4 or later with the `mysqli` extension
- `zlib` for compressed dumps (without zlib the dumps are plain `.sql`)
- `ZipArchive` for the site archive (if missing, `PharData` is used and the format is `.tar.gz`)

## Quick install

1. Copy `autobackup.php` into the main folder of the site (or any other folder: default paths are relative to its location).
2. Fill in the `$autobackup_config` block at the top of the file: database credentials, backup folder, web key.
3. Add the include where the daily backup should start, for example in the code that runs after a successful admin login:

```php
include __DIR__ . '/autobackup.php';
```

The include may also sit inside a function: the script neither reads nor creates global variables and can be included several times in the same request.

4. Open `https://yoursite/autobackup.php?azione=test&chiave=YOUR_KEY` and check that every line is `OK`.

> The script contains the database credentials: treat it like a configuration file (never publish it filled in).

## Configuration

Everything lives in the `$autobackup_config` array at the top of the file. Keys and action names are in Italian (the script's native language); their meaning is listed here.

| Key | Meaning | Default |
|---|---|---|
| `cartella` | Backup folder (absolute path). Created automatically, with a protective `.htaccess` and `index.php`. Preferably outside the public folder | `dirname(__DIR__) . '/backup'`: a `backup` folder next to the site folder |
| `radice_sito` | Folder archived by the `sito` action | `__DIR__` |
| `fuso_orario` | Time zone for dates in file names and in the log | `Europe/Rome` |
| `db` | Database credentials: `host`, `porta` (port), `utente` (user), `password`, `nome` (database name) | – |
| `escludi_prefissi` | Table prefixes not to back up | none (example: `stats_`) |
| `escludi_tabelle` | Exact table names not to back up | none |
| `conserva_db` | How many dumps to keep | 30 |
| `conserva_sito` | How many site archives to keep | 5 |
| `chiave_web` | Secret key for browser actions. Empty = browser access disabled (responds 404) | empty |
| `sito_escludi` | Folders or files excluded from the archive (paths relative to the root, with `/`) | `.git`, `node_modules` |
| `blocco_insert` | Maximum size in bytes of a single multi-row `INSERT` | 524288 |

### The backup folder

The default creates a `backup` folder next to the site folder, hence outside the public folder: with the site in `/home/user/public_html`, backups go to `/home/user/backup`. To change it, use an absolute path:

```php
'cartella' => '/home/user/site_backup',
```

The folder is created on the first backup; PHP only needs write permission there. If the folder is inside the site root it is automatically excluded from the archive and the `test` action reports a warning.

### The web key

At least 24 random characters. The `test` action prints a sample key on every run; alternatively:

```
php -r "echo bin2hex(random_bytes(16));"
```

## Usage

### Daily backup (include)

Nothing to do. On every include the script checks whether today's file exists (`db_<database>_<YYYY-MM-DD>.sql.gz`): if it is missing it creates it, otherwise it returns immediately.

### From the browser

Every action requires `chiave=` equal to `chiave_web`. Without the key, with a wrong key or with an unknown action the response is a plain 404.

| URL | Effect |
|---|---|
| `autobackup.php?azione=test&chiave=…` | Check report. Creates no backup |
| `autobackup.php?azione=db&chiave=…` | Immediate database dump, with date and time in the name |
| `autobackup.php?azione=sito&chiave=…` | Zip of the whole site plus a fresh database dump |

The response is plain text; on failure the HTTP status is 500 and the text explains why.

### From the command line

```
php autobackup.php test
php autobackup.php db
php autobackup.php sito
```

Exit code 0 on success, 1 on error: handy in a cron job.

### Sample output of the `test` action

```
autobackup - azione: test - 2026-01-15 09:12:03

== Percorsi
        script: /home/user/public_html/autobackup.php
        cartella di lavoro corrente: /home/user/public_html
        radice web (DOCUMENT_ROOT): /home/user/public_html
OK      radice del sito: /home/user/public_html
OK      cartella dei backup: /home/user/backup
OK      protezioni .htaccess e index.php presenti nella cartella
OK      la cartella sta fuori dalla radice web
== Scrittura
OK      creazione, lettura e cancellazione di un file di prova
OK      spazio libero: 12345.6 MB
== Database
        connessione configurata: user@localhost:3306 database db_name
OK      connessione riuscita, server MySQL 8.0.36
OK      tabelle da salvare: 12 (articoli, pagine, ...)
        tabelle escluse: stats_visits
== Ambiente
OK      PHP 8.2.20 (fpm-fcgi)
OK      compressione gzip (zlib) disponibile
OK      archivio del sito: ZipArchive disponibile
OK      fuso orario Europe/Rome, adesso 2026-01-15 09:12:03
OK      chiave web impostata
        esempio di chiave da usare in chiave_web (generata ora, non salvata): 3f9c...
== Stato
        file di oggi: db_db_name_2026-01-15.sql.gz (non ancora creato)
        backup presenti: 7 del database (conserva 30), 1 del sito (conserva 5)

Tutti i controlli superati.
```

The report is in Italian: `OK` / `AVVISO` (warning) / `ERRORE` (error) at the start of each line, and "Tutti i controlli superati" means every check passed.

## Produced files

| File | Content |
|---|---|
| `db_<database>_<date>.sql.gz` | Daily dump: `DROP TABLE IF EXISTS` + `CREATE TABLE` + multi-row `INSERT` for every table, utf8mb4, `FOREIGN_KEY_CHECKS` disabled during import, timestamps in UTC |
| `db_<database>_<date>_<time>.sql.gz` | On-demand dump |
| `sito_<date>_<time>.zip` | All site files plus `_database_<name>.sql.gz` |
| `autobackup.log` | One line per created backup and per error (rotated above 1 MB) |
| `.htaccess`, `index.php`, `.lock` | Folder protection and lock file |

Views are not exported; generated columns are skipped in the `INSERT`s because the server recomputes them.

## Restore

From phpMyAdmin: select the database, Import, choose the `.sql.gz` file (phpMyAdmin decompresses it), run.

From the command line:

```
gzip -dc db_<database>_<date>.sql.gz | mysql -u <user> -p --default-character-set=utf8mb4 <database>
```

Tables excluded from the dump are left untouched by the import. For the site archive: extract the zip into the public folder and import the dump contained in `_database_<name>.sql.gz`.

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| No file after login | Folder cannot be created or is not writable | Run the `test` action; choose another path or create the folder by hand with write permission |
| `ERRORE connessione` in the test | Wrong credentials | Check `db` (the "connessione configurata" line shows user, host and database in use) |
| 404 even with the key | Empty `chiave_web`, different key or invalid action | Fill in `chiave_web` and use `azione=test`, `db` or `sito` |
| Today's file has the wrong date | Different server time zone | Set `fuso_orario` |
| `.sql` dump instead of `.sql.gz` | zlib missing | The file is still valid; ask the host to enable zlib |
| Timeout on the `sito` action | Large site and hosting time limit | Run from the command line or cron, or add exclusions |

## Security

- With the default folder, backups sit outside the public folder and cannot be reached from the browser. If the folder is inside the site, it is protected by its own `.htaccess` (Apache only).
- Browser actions require the key and respond 404 in every other case. The key appears in the URL, hence in the web server logs: change it if you suspect it has been seen.
- The script never shows the database password, not even in the test report.

## License

Released under the [MIT](LICENSE) license. Copyright (c) 2026 Onix.it.
