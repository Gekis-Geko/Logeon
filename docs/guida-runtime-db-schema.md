# Guida Runtime DB

Ultimo aggiornamento: 2026-04-03

## Scopo
Documento unico per:
1. runtime DB attuale (adapter `mysqli`)
2. policy installer
3. file SQL unico di bootstrap
4. smoke operativi

## Runtime DB ufficiale
1. Profilo runtime unico: `mysqli`.
2. Factory runtime: `core/Database/DbAdapterFactory.php`.
3. Bootstrap applicativo:
   - `custom/bootstrap.php`
4. Non usare adapter legacy nei nuovi sviluppi.

## Flusso ufficiale installazione DB
Lo step `init-db` dell'installer esegue:
1. import file unico: `database/logeon_db_core.sql`
2. nessuna applicazione patch separata durante l'installazione guidata

## Policy schema SQL unico
1. il file `database/logeon_db_core.sql` e la fonte ufficiale di bootstrap.
2. deve essere coerente con il runtime core corrente.
3. deve evitare dati di test/smoke non essenziali.
4. deve mantenere almeno l'utenza admin di baseline.

## Checklist prima rilascio DB
1. import su DB vuoto completato senza errori.
2. login admin funzionante.
3. `/game` e `/admin` raggiungibili.
4. nessun dato smoke non essenziale in tabelle runtime.

## Pulizia locale baseline (facoltativa)
Per ripulire un DB locale mantenendo solo l'utenza Admin/superuser:
1. `C:\xampp\mysql\bin\mysql.exe -u root -e "source scripts/sql/cleanup-local-db.sql"`
2. rigenera il dump ufficiale:
   - `C:\xampp\mysql\bin\mysqldump.exe -u root --default-character-set=utf8mb4 --single-transaction --routines --triggers appdb --result-file=database/logeon_db_core.sql`

## Smoke consigliati
1. `C:\xampp\php\php.exe scripts/php/smoke-core-db-runtime.php`
2. `C:\xampp\php\php.exe scripts/php/smoke-core-auth-runtime.php`
3. `C:\xampp\php\php.exe scripts/php/smoke-core-runtime.php`
4. `C:\xampp\php\php.exe scripts/php/smoke-theme-runtime.php`
5. `C:\xampp\php\php.exe scripts/php/themes-validate.php`

## Troubleshooting rapido
1. Fatal su classi DB mancanti:
   - verifica deploy completo + cache pulite
2. Errori su import schema:
   - verifica integrita di `database/logeon_db_core.sql`
3. Runtime anomalo dopo update:
   - esegui smoke core e isolare endpoint coinvolto
