# Guida Upgrade Versioni

Ultimo aggiornamento: 2026-04-03

## Scopo
Aggiornare Logeon riducendo rischio di downtime e regressioni.

## Procedura consigliata
1. Esegui backup completo (DB + file + config).
2. Metti applicazione in manutenzione.
3. Aggiorna codice sorgente (git pull o pacchetto nuovo).
4. Esegui `composer install --no-dev --optimize-autoloader`.
5. Allinea il DB importando `database/logeon_db_core.sql` (o eseguendo migrazione dati equivalente).
6. Esegui smoke runtime.
7. Disattiva manutenzione e verifica flussi principali.

## Checklist upgrade
1. Login standard.
2. Login Google (se attivo).
3. Accesso `/game`.
4. Accesso `/admin`.
5. Una pagina CRUD admin.
6. Una feature gameplay con API.

## Smoke consigliati
1. `C:\xampp\php\php.exe scripts/php/smoke-core-db-runtime.php`
2. `C:\xampp\php\php.exe scripts/php/smoke-core-auth-runtime.php`
3. `C:\xampp\php\php.exe scripts/php/smoke-core-runtime.php`

## Rollback rapido
1. Ripristina codice versione precedente.
2. Ripristina dump DB pre-upgrade.
3. Ripristina config e upload.
4. Riesegui smoke minimi.
