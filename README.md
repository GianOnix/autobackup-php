# AutoBackup ver 1.0/2026

Script PHP autonomo per il backup automatico di un database MySQL e, su richiesta, di tutto il sito.
Un solo file, nessuna dipendenza da altri file del sito: si può copiare in qualunque progetto PHP.

- PHP 7.4 o superiore (provato con 7.4 e 8.3), estensione `mysqli`.
- `zlib` per i dump compressi (`.sql.gz`); senza zlib i dump escono in `.sql` non compressi.
- `ZipArchive` per l'archivio del sito (in mancanza usa `PharData` e produce un `.tar.gz`).
- Non usa `mysqldump` né comandi di sistema: funziona anche sugli hosting condivisi.

---

## 1. Cosa fa

| Funzione | Quando | Risultato |
|---|---|---|
| Backup giornaliero del database | A ogni `include` dello script (per esempio al login del backend) | `db_<database>_<AAAA-MM-GG>.sql.gz`, solo il primo della giornata |
| Dump immediato del database | Dal browser con la chiave, o da riga di comando | `db_<database>_<AAAA-MM-GG>_<HHMMSS>.sql.gz` |
| Archivio di tutto il sito | Dal browser con la chiave, o da riga di comando | `sito_<AAAA-MM-GG>_<HHMMSS>.zip` con dentro anche un dump fresco del database |
| Controllo dell'installazione | Dal browser con la chiave, o da riga di comando | Rapporto in testo semplice su percorsi, cartella, connessione e ambiente |

Regole fisse:

- le tabelle con i prefissi o i nomi indicati nella configurazione (per esempio `stats_`) non vengono salvate;
- si conservano gli ultimi N dump (uno per giorno) e gli ultimi N archivi del sito, i più vecchi vengono cancellati;
- due esecuzioni contemporanee non si sovrappongono (lock su file); il file viene scritto come temporaneo e rinominato solo alla fine;
- in modalità include lo script non scrive mai nulla a video e non interrompe mai il programma che lo include: ogni errore va nel suo log.

---

## 2. Installazione

1. Copiare `autobackup.php` nella cartella principale del sito (o in un'altra cartella: i percorsi predefiniti sono relativi alla sua posizione).
2. Compilare il blocco `$autobackup_config` in testa al file (capitolo 3).
3. Aggiungere l'include nel punto in cui deve partire il backup giornaliero, per esempio nel codice eseguito dopo il login riuscito del pannello di amministrazione (adattare il percorso):

```php
include __DIR__ . '/autobackup.php';
```

In un altro sito basta un include con il percorso del file, anche dentro una funzione: lo script non usa né crea variabili globali e può essere incluso più volte nella stessa richiesta senza effetti.

4. Aprire `https://<sito>/autobackup.php?azione=test&chiave=<chiave>` e controllare che tutte le righe siano `OK`.

Lo script contiene le credenziali del database: va trattato come `config.php` (non copiarlo altrove, non condividerlo con i valori compilati).

---

## 3. Configurazione

Tutto sta nell'array `$autobackup_config` in testa al file.

| Chiave | Significato | Predefinito |
|---|---|---|
| `cartella` | Cartella dove salvare i backup (percorso assoluto). Creata da sola, con `.htaccess` e `index.php` di protezione. Meglio fuori dalla cartella pubblica del sito | `dirname(__DIR__) . '/backup'`: cartella `backup` accanto a quella del sito |
| `radice_sito` | Cartella da archiviare con l'azione `sito` | `__DIR__` (la cartella dello script) |
| `fuso_orario` | Fuso per le date nei nomi dei file e nel log | `Europe/Rome` |
| `db` | Credenziali del database: `host`, `porta`, `utente`, `password`, `nome` | – |
| `escludi_prefissi` | Prefissi delle tabelle da non salvare | nessuno (esempio: `stats_`) |
| `escludi_tabelle` | Nomi esatti di tabelle da non salvare | nessuno |
| `conserva_db` | Quanti dump tenere | 30 |
| `conserva_sito` | Quanti archivi del sito tenere | 5 |
| `chiave_web` | Chiave segreta per le azioni dal browser. Vuota = accesso dal browser disabilitato (risponde 404) | vuota |
| `sito_escludi` | Cartelle o file esclusi dall'archivio del sito (percorsi relativi alla radice, con `/`) | `.git`, `node_modules` |
| `blocco_insert` | Dimensione massima in byte di un singolo `INSERT` multiplo nel dump | 524288 (512 KB) |

Lo script è pensato per il server online: un solo set di credenziali, lo stesso da browser, da include e da riga di comando.

### 3.1 Dove impostare la cartella dei backup

La cartella si imposta nella chiave `cartella` del blocco `$autobackup_config`:

```php
'cartella' => dirname(__DIR__) . '/backup',
```

Il valore predefinito crea la cartella `backup` accanto a quella del sito, quindi fuori dalla cartella pubblica. In pratica:

- con il sito in `/home/utente/public_html`, la cartella è `/home/utente/backup` (sorella di `public_html`).

Per cambiarla si scrive un percorso assoluto:

```php
'cartella' => '/home/utente/backup_sito',
```

oppure un percorso relativo alla posizione dello script, costruito con `__DIR__` come nel predefinito (`__DIR__` è la cartella in cui sta `autobackup.php`, `dirname(__DIR__)` quella superiore).

Regole:

- la cartella viene creata da sola al primo backup, con `.htaccess` e `index.php` di protezione; serve solo che PHP abbia il permesso di scrivere nel punto scelto;
- meglio fuori dalla cartella pubblica del sito: così i backup non sono raggiungibili dal browser in nessun caso. Se la cartella sta dentro il sito resta protetta dal suo `.htaccess` (solo Apache) e l'azione `test` lo segnala con un `AVVISO`;
- se la cartella sta dentro la radice del sito viene esclusa automaticamente dall'archivio dell'azione `sito`;
- dopo ogni cambio, aprire `autobackup.php?azione=test&chiave=…`: la riga "cartella dei backup" mostra il percorso assoluto risolto e la sezione "Scrittura" conferma che PHP riesce a scriverci.

Consigli per la chiave: almeno 24 caratteri casuali, per esempio generati con

```
php -r "echo bin2hex(random_bytes(16));"
```

---

## 4. Uso

### 4.1 Backup giornaliero (include)

Non richiede nulla: ogni volta che lo script viene incluso controlla se esiste già il file di oggi. Se manca lo crea, altrimenti non fa niente. Il costo di un include "a vuoto" è un controllo su disco.

### 4.2 Dal browser

Tutte le azioni richiedono il parametro `chiave` uguale a `chiave_web`. Senza chiave, con chiave sbagliata o con azione sconosciuta la risposta è un normale 404, senza alcuna indicazione.

| Indirizzo | Effetto |
|---|---|
| `autobackup.php?azione=test&chiave=…` | Rapporto dei controlli (capitolo 5). Non crea backup |
| `autobackup.php?azione=db&chiave=…` | Dump del database subito, con data e ora nel nome |
| `autobackup.php?azione=sito&chiave=…` | Archivio zip di tutto il sito più un dump fresco del database (`_database_<nome>.sql.gz` dentro lo zip) |

La risposta è testo semplice con l'esito e il nome del file creato; in caso di errore il codice HTTP è 500 e il testo spiega il motivo.

### 4.3 Da riga di comando

```
php autobackup.php test
php autobackup.php db
php autobackup.php sito
```

Il codice di uscita è 0 se tutto è andato bene, 1 in caso di errore: utile in un cron dell'hosting.

---

## 5. L'azione test

Esegue in sequenza:

1. **Percorsi**: radice del sito leggibile; cartella dei backup esistente o creata ora, con `.htaccess` e `index.php`; avviso se la cartella sta dentro la radice web (in quel caso è protetta solo dal suo `.htaccess`).
2. **Scrittura**: crea, rilegge e cancella un file di prova nella cartella; mostra lo spazio libero.
3. **Database**: credenziali configurate (solo utente, host e nome del database, mai la password), connessione, versione del server, elenco delle tabelle che verranno salvate e di quelle escluse.
4. **Ambiente**: versione di PHP, zlib, ZipArchive/PharData, limiti di memoria e tempo, fuso orario con data e ora corrente, presenza della chiave web.
5. **Stato**: nome del file atteso per oggi e se esiste già, numero di backup presenti, ultime righe del log.

Ogni riga inizia con `OK`, `AVVISO` o `ERRORE`; in fondo il riepilogo.

---

## 6. I file prodotti

Nella cartella dei backup:

| File | Contenuto |
|---|---|
| `db_<database>_<data>.sql.gz` | Dump giornaliero: struttura (`DROP TABLE IF EXISTS` + `CREATE TABLE`) e dati (`INSERT` multipli) di ogni tabella, charset utf8mb4, `FOREIGN_KEY_CHECKS` disattivato durante l'importazione, timestamp in UTC |
| `db_<database>_<data>_<ora>.sql.gz` | Dump su richiesta (stesso formato) |
| `sito_<data>_<ora>.zip` | Tutti i file del sito (meno le esclusioni) più `_database_<nome>.sql.gz` |
| `autobackup.log` | Una riga per ogni backup creato e per ogni errore; ruotato in `.old` oltre 1 MB |
| `.htaccess`, `index.php` | Protezione della cartella se fosse raggiungibile dal web |
| `.lock` | File del lock, sempre presente, vuoto |

Il dump è coerente: tutte le tabelle InnoDB sono lette allo stesso istante (snapshot) senza bloccare il sito.
Le viste non vengono esportate (sono elencate nel commento in testa al file); le colonne calcolate (`GENERATED`) sono saltate negli `INSERT` perché il server le ricalcola.

---

## 7. Ripristino

**Da phpMyAdmin**: selezionare il database del sito, scheda Importa, scegliere il file `.sql.gz` (viene decompresso da phpMyAdmin) e avviare. Le tabelle esistenti vengono sostituite.

**Da riga di comando**:

```
gzip -dc db_<database>_<data>.sql.gz | mysql -u <utente> -p --default-character-set=utf8mb4 <database>
```

Su Windows con WAMP:

```
C:\wamp64\bin\mysql\mysql8.4.2\bin\mysql.exe -u root --default-character-set=utf8mb4 <database> < dump.sql
```

(dopo aver decompresso il `.sql.gz`, per esempio con 7-Zip).

Il dump non contiene le tabelle escluse (per esempio `stats_*`): importandolo, quelle tabelle non vengono toccate.

**Archivio del sito**: estrarre lo zip nella cartella pubblica del sito e importare il dump contenuto in `_database_<nome>.sql.gz` come sopra.

---

## 8. Problemi e soluzioni

| Sintomo | Causa probabile | Rimedio |
|---|---|---|
| Nessun file nella cartella dopo il login | Cartella non creabile o non scrivibile | Eseguire l'azione `test`; se la cartella non si può creare, scegliere un altro percorso in `cartella` o crearla a mano con permessi di scrittura per PHP. Nel frattempo l'errore sta nel log degli errori di PHP del sito |
| `ERRORE connessione` nel test | Credenziali sbagliate | Controllare `db` (la riga "connessione configurata" del rapporto mostra utente, host e database usati) |
| `autobackup.php` risponde 404 anche con la chiave | `chiave_web` vuota, chiave diversa, azione non valida | Compilare `chiave_web` e usare esattamente `azione=test`, `db` o `sito` |
| Il file di oggi ha la data sbagliata | Fuso orario del server diverso | Impostare `fuso_orario` |
| Dump `.sql` invece di `.sql.gz` | zlib assente | Normale: il file è comunque valido. Chiedere all'hosting di attivare zlib |
| Archivio `.tar.gz` invece di `.zip` | ZipArchive assente | Normale: si apre con 7-Zip. Chiedere all'hosting di attivare l'estensione zip |
| Timeout sull'azione `sito` | Sito molto grande e limite di tempo dell'hosting | Lanciare da riga di comando o cron, oppure aumentare le esclusioni in `sito_escludi` |
| Due login nello stesso istante | Gestito dal lock | Il secondo accesso trova il backup in corso e non fa nulla |

---

## 9. Sicurezza

- La cartella predefinita è fuori dalla cartella pubblica: i backup non sono raggiungibili dal browser. Se si sceglie una cartella interna al sito, resta la protezione del suo `.htaccess` (Apache); con altri server web va bloccata a mano.
- Le azioni dal browser richiedono la chiave e rispondono 404 in ogni altro caso; la chiave finisce nei log del server web, quindi va cambiata se si sospetta che sia stata vista.
- Lo script non mostra mai la password del database, nemmeno nel rapporto di test.
- Un include dello script non può essere usato per far partire backup dall'esterno: l'include lo decide il codice del sito.
