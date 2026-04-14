# Guida Creazione Moduli

Ultimo aggiornamento: 2026-04-03

## Scopo
Creare un modulo Logeon senza modificare il core, mantenendo separazione netta tra:
1. orchestrazione core (`/admin/modules/*`);
2. feature del modulo (rotte, servizi, UI, documentazione propria).

## Prerequisiti
1. leggere `docs/guida-sistema-moduli.md`;
2. allinearsi a `docs/logeon-module-governance-system.md`.

## Struttura base consigliata
1. `modules/<vendor.modulo>/module.json`
2. `modules/<vendor.modulo>/bootstrap.php` (opzionale)
3. `modules/<vendor.modulo>/routes.php` (opzionale)
4. `modules/<vendor.modulo>/migrations/` (opzionale)
5. `modules/<vendor.modulo>/assets/` (opzionale)
6. `modules/<vendor.modulo>/docs/` (consigliato)

## `module.json` minimo
Campi fondamentali:
1. `id`
2. `name`
3. `version`
4. `vendor`
5. `description`
6. `dependencies` (se presenti)
7. `compat` (range versione core)

## Regole progettuali
1. Nessuna dipendenza hard del core dal modulo.
2. Tutte le rotte del modulo esistono solo quando il modulo e attivo.
3. Migrazioni SQL idempotenti.
4. Nessun campo JSON libero nei form admin core: usare payload guidati.
5. Namespace e naming coerenti col dominio del modulo.

## UI modulo
1. Inserire menu tramite `menus` nel manifest (slot supportati dal core).
2. Non modificare direttamente menu core per aggiungere pagine modulo.
3. Riutilizzare componenti UI esistenti (Datagrid, modali, SelectionGroup, Paginator) dove possibile.

## Documentazione del modulo
1. Ogni modulo deve avere una guida dedicata (scopo, API, setup, limiti).
2. Le API funzionali modulo non vanno nel contratto API core.
3. Nel contratto API core restano solo gli endpoint di gestione moduli (`/admin/modules/*`).

## Test minimi prima del rilascio
1. Modulo rilevato in `/admin/modules`.
2. Attivazione riuscita (dipendenze/compatibilita OK).
3. Feature modulo operative.
4. Disattivazione senza regressioni sul core.
5. Disinstallazione (`safe` e, se previsto, `purge`) verificata.
6. Audit moduli senza errori critici.

## Packaging
1. Usare lo script di packaging del repository (`scripts/modules/build-zips.ps1`).
2. Non versionare i pacchetti zip generati in `dist/`.

## Anti-pattern da evitare
1. Hardcode di rotte modulo nel core.
2. Migrazioni distruttive senza percorso di rollback.
3. Dipendenze circolari tra moduli.
4. UI non guidate con input tecnici non necessari per l'utente finale.
