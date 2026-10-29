# AutoBackup

🇮🇹 Italiano · [🇬🇧 English](README.en.md)

Backup automatico di un database MySQL e archivio compresso del sito, in un solo file PHP senza dipendenze.

Si include nel codice del pannello di amministrazione (per esempio dopo il login) e, al primo accesso di ogni giorno, salva il database in un file `.sql.gz` in una cartella protetta. Su richiesta, dal browser o da riga di comando, crea anche uno zip di tutto il sito.

## Caratteristiche

- **Un solo file**, `autobackup.php`: nessuna libreria esterna, nessun `mysqldump`, nessun comando di sistema. Funziona anche sugli hosting condivisi.
- **Backup giornaliero**: un dump per giorno, creato dal primo include della giornata; gli include successivi non fanno nulla.
- **Dump in PHP puro** via `mysqli`: lettura coerente di tutte le tabelle (snapshot InnoDB) senza bloccare il sito, scrittura in streaming gzip, memoria costante anche con tabelle grandi.
- **Tabelle escluse** per prefisso o per nome (per esempio le tabelle delle statistiche).
- **Archivio del sito**: zip di tutti i file (con esclusioni configurabili) più un dump fresco del database.
- **Rotazione**: si conservano gli ultimi N dump e gli ultimi N archivi.
- **Robusto**: lock contro esecuzioni simultanee, scrittura su file temporaneo e rinomina, nessun output e nessuna eccezione verso il codice che lo include; gli errori vanno nel suo log.
- **Azione di test** che controlla percorsi, cartella, scrittura, connessione e ambiente.
- Compatibile con **PHP 7.4 e 8.x**.

## Requisiti

- PHP 7.4 o superiore con estensione `mysqli`
- `zlib` per i dump compressi (senza zlib i dump escono in `.sql`)
- `ZipArchive` per l'archivio del sito (in mancanza viene usato `PharData`, formato `.tar.gz`)

## Installazione rapida

1. Copia `autobackup.php` nella cartella principale del sito (o in un'altra cartella: i percorsi predefiniti sono relativi alla sua posizione).
2. Compila il blocco `$autobackup_config` in testa al file: credenziali del database, cartella dei backup, chiave web.
3. Aggiungi l'include nel punto in cui deve partire il backup giornaliero, per esempio nel codice eseguito dopo il login riuscito del pannello di amministrazione:

```php
include __DIR__ . '/autobackup.php';
```

L'include può stare anche dentro una funzione: lo script non usa né crea variabili globali e può essere incluso più volte nella stessa richiesta.

4. Apri `https://tuosito/autobackup.php?azione=test&chiave=LA_TUA_CHIAVE` e controlla che tutte le righe siano `OK`.

> Lo script contiene le credenziali del database: trattalo come un file di configurazione (non pubblicarlo compilato).

## Configurazione

Tutto sta nell'array `$autobackup_config` in testa al file.

| Chiave | Significato | Predefinito |
|---|---|---|
| `cartella` | Cartella dei backup (percorso assoluto). Creata da sola, con `.htaccess` e `index.php` di protezione. Meglio fuori dalla cartella pubblica | `dirname(__DIR__) . '/backup'`: cartella `backup` accanto a quella del sito |
| `radice_sito` | Cartella da archiviare con l'azione `sito` | `__DIR__` |
| `fuso_orario` | Fuso per le date nei nomi dei file e nel log | `Europe/Rome` |
| `db` | Credenziali del database: `host`, `porta`, `utente`, `password`, `nome` | – |
| `escludi_prefissi` | Prefissi delle tabelle da non salvare | nessuno (esempio: `stats_`) |
| `escludi_tabelle` | Nomi esatti di tabelle da non salvare | nessuno |
| `conserva_db` | Quanti dump tenere | 30 |
| `conserva_sito` | Quanti archivi del sito tenere | 5 |
| `chiave_web` | Chiave segreta per le azioni dal browser. Vuota = accesso dal browser disabilitato (risponde 404) | vuota |
| `sito_escludi` | Cartelle o file esclusi dall'archivio (percorsi relativi alla radice, con `/`) | `.git`, `node_modules` |
| `blocco_insert` | Dimensione massima in byte di un singolo `INSERT` multiplo | 524288 |

### La cartella dei backup

Il valore predefinito crea la cartella `backup` accanto a quella del sito, quindi fuori dalla cartella pubblica: con il sito in `/home/utente/public_html` i backup finiscono in `/home/utente/backup`. Per cambiarla basta un percorso assoluto:

```php
'cartella' => '/home/utente/backup_sito',
```

La cartella viene creata al primo backup; serve solo che PHP possa scriverci. Se la cartella sta dentro la radice del sito viene esclusa automaticamente dall'archivio e l'azione `test` lo segnala con un avviso.

### La chiave web

Almeno 24 caratteri casuali. L'azione `test` ne stampa una di esempio a ogni esecuzione; in alternativa:

```
php -r "echo bin2hex(random_bytes(16));"
```

## Uso

### Backup giornaliero (include)

Non richiede nulla. A ogni include lo script controlla se esiste il file di oggi (`db_<database>_<AAAA-MM-GG>.sql.gz`): se manca lo crea, altrimenti termina subito.

### Dal browser

Tutte le azioni richiedono `chiave=` uguale a `chiave_web`. Senza chiave, con chiave sbagliata o con azione sconosciuta la risposta è un normale 404.

| Indirizzo | Effetto |
|---|---|
| `autobackup.php?azione=test&chiave=…` | Rapporto dei controlli. Non crea backup |
| `autobackup.php?azione=db&chiave=…` | Dump del database subito, con data e ora nel nome |
| `autobackup.php?azione=sito&chiave=…` | Zip di tutto il sito più un dump fresco del database |

La risposta è testo semplice; in caso di errore il codice HTTP è 500 e il testo spiega il motivo.

### Da riga di comando

```
php autobackup.php test
php autobackup.php db
php autobackup.php sito
```

Codice di uscita 0 se tutto è andato bene, 1 in caso di errore: comodo in un cron.

### Esempio di rapporto dell'azione `test`

```
autobackup - azione: test - 2026-01-15 09:12:03

== Percorsi
        script: /home/utente/public_html/autobackup.php
        cartella di lavoro corrente: /home/utente/public_html
        radice web (DOCUMENT_ROOT): /home/utente/public_html
OK      radice del sito: /home/utente/public_html
OK      cartella dei backup: /home/utente/backup
OK      protezioni .htaccess e index.php presenti nella cartella
OK      la cartella sta fuori dalla radice web
== Scrittura
OK      creazione, lettura e cancellazione di un file di prova
OK      spazio libero: 12345.6 MB
== Database
        connessione configurata: utente@localhost:3306 database nome_db
OK      connessione riuscita, server MySQL 8.0.36
OK      tabelle da salvare: 12 (articoli, pagine, ...)
        tabelle escluse: stats_visite
== Ambiente
OK      PHP 8.2.20 (fpm-fcgi)
OK      compressione gzip (zlib) disponibile
OK      archivio del sito: ZipArchive disponibile
OK      fuso orario Europe/Rome, adesso 2026-01-15 09:12:03
OK      chiave web impostata
        esempio di chiave da usare in chiave_web (generata ora, non salvata): 3f9c...
== Stato
        file di oggi: db_nome_db_2026-01-15.sql.gz (non ancora creato)
        backup presenti: 7 del database (conserva 30), 1 del sito (conserva 5)

Tutti i controlli superati.
```

## I file prodotti

| File | Contenuto |
|---|---|
| `db_<database>_<data>.sql.gz` | Dump giornaliero: `DROP TABLE IF EXISTS` + `CREATE TABLE` + `INSERT` multipli per ogni tabella, utf8mb4, `FOREIGN_KEY_CHECKS` disattivato durante l'importazione, timestamp in UTC |
| `db_<database>_<data>_<ora>.sql.gz` | Dump su richiesta |
| `sito_<data>_<ora>.zip` | Tutti i file del sito più `_database_<nome>.sql.gz` |
| `autobackup.log` | Una riga per ogni backup creato e per ogni errore (ruotato oltre 1 MB) |
| `.htaccess`, `index.php`, `.lock` | Protezione della cartella e file del lock |

Le viste non vengono esportate; le colonne calcolate (`GENERATED`) sono saltate negli `INSERT` perché il server le ricalcola.

## Ripristino

Da phpMyAdmin: selezionare il database, Importa, scegliere il file `.sql.gz` (viene decompresso da phpMyAdmin), avviare.

Da riga di comando:

```
gzip -dc db_<database>_<data>.sql.gz | mysql -u <utente> -p --default-character-set=utf8mb4 <database>
```

Le tabelle escluse dal dump non vengono toccate dall'importazione. Per l'archivio del sito: estrarre lo zip nella cartella pubblica e importare il dump contenuto in `_database_<nome>.sql.gz`.

## Problemi comuni

| Sintomo | Causa | Rimedio |
|---|---|---|
| Nessun file dopo il login | Cartella non creabile o non scrivibile | Eseguire l'azione `test`; scegliere un altro percorso o creare la cartella a mano con permessi di scrittura |
| `ERRORE connessione` nel test | Credenziali sbagliate | Controllare `db` (la riga "connessione configurata" mostra utente, host e database usati) |
| Risponde 404 anche con la chiave | `chiave_web` vuota, chiave diversa o azione non valida | Compilare `chiave_web` e usare `azione=test`, `db` o `sito` |
| Il file di oggi ha la data sbagliata | Fuso orario del server diverso | Impostare `fuso_orario` |
| Dump `.sql` invece di `.sql.gz` | zlib assente | Il file è comunque valido; chiedere all'hosting di attivare zlib |
| Timeout sull'azione `sito` | Sito grande e limite di tempo dell'hosting | Lanciare da riga di comando o cron, oppure aumentare le esclusioni |

## Sicurezza

- Con la cartella predefinita i backup stanno fuori dalla cartella pubblica e non sono raggiungibili dal browser. Se la cartella è interna al sito, resta protetta dal suo `.htaccess` (solo Apache).
- Le azioni dal browser richiedono la chiave e rispondono 404 in ogni altro caso. La chiave compare nell'URL, quindi nei log del server web: cambiarla se si sospetta che sia stata vista.
- Lo script non mostra mai la password del database, nemmeno nel rapporto di test.

## Licenza

Rilasciato con licenza [MIT](LICENSE). Copyright (c) 2026 Onix.it.
