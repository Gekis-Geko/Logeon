# Guida Installazione Produzione

Ultimo aggiornamento: 2026-04-03

## Scopo
Portare Logeon in produzione in modo sicuro e ripetibile.

## Prerequisiti
1. PHP 8.1+ (consigliato 8.2).
2. MySQL/MariaDB.
3. Composer.
4. HTTPS attivo sul dominio pubblico.

## Procedura consigliata
1. Carica codice sorgente su server.
2. Esegui `composer install --no-dev --optimize-autoloader`.
3. Configura solo il minimo necessario in `configs/config.php` (base URL, debug, opzioni runtime).
4. Avvia `/install` e completa il wizard:
   - dati applicazione;
   - connessione database;
   - scrittura automatica di `configs/app.php` e `configs/db.php`;
   - inizializzazione DB da `database/logeon_db_core.sql`;
   - finalizzazione installazione.
5. Imposta `CONFIG['debug'] = false`.
6. Verifica login utente e accesso admin.

## Hardening minimo
1. Usa HTTPS obbligatorio.
2. Proteggi accesso DB da rete pubblica.
3. Limita privilegi utente MySQL al solo database applicativo.
4. Non esporre file di backup SQL in directory pubbliche.
5. Mantieni `.env`/config fuori da export pubblici del web server.

## Verifiche post-installazione
1. Smoke core:
   - `C:\xampp\php\php.exe scripts/php/smoke-core-db-runtime.php`
   - `C:\xampp\php\php.exe scripts/php/smoke-core-auth-runtime.php`
   - `C:\xampp\php\php.exe scripts/php/smoke-core-runtime.php`
2. Verifica area pubblica `/`.
3. Verifica area gioco `/game`.
4. Verifica area admin `/admin`.

## Operazioni pianificate consigliate
1. Backup DB giornaliero.
2. Backup file configurazione e upload.
3. Monitoraggio log PHP/web server.
