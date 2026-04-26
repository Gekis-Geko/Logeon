# Indice Documentazione Logeon

Ultimo aggiornamento: 2026-04-26

## Scopo
Indice unico della documentazione pubblica, pronta per pubblicazione su GitBook.

## Percorso consigliato per nuovi contributori
1. `README.md`
2. `CONTRIBUTING.md`
3. `docs/guida-contributori.md`
4. `docs/guida-architettura-frontend.md`
5. `docs/guida-temi-layout.md`
6. `docs/guida-runtime-db-schema.md`
7. `docs/contratti-api-backend.md`

Nota:
`CONTRIBUTING.md` e il riferimento autoritativo per workflow Git, policy Pull Request, convenzioni commit/branch e esempi di stile codice.

## Struttura GitBook consigliata
Usa come indice principale `docs/SUMMARY.md` e trascrivi solo le pagine elencate in quel file.

## Guide operative pubbliche
1. `docs/guida-contributori.md`
2. `docs/guida-personalizzazione-gioco.md`
3. `docs/guida-temi-layout.md`
4. `docs/guida-installazione-produzione.md`
5. `docs/guida-runtime-db-schema.md`
6. `docs/guida-upgrade-versioni.md`
7. `docs/guida-backup-ripristino.md`
8. `docs/guida-troubleshooting.md`
9. `docs/guida-build-release.md`
10. `docs/guida-architettura-frontend.md`
11. `docs/guida-permessi-ui-attributi.md`
12. `docs/guida-autenticazione-sessioni.md`
13. `docs/matrice-ruoli-permessi.md`
14. `docs/guida-sistema-moduli.md`
15. `docs/guida-creazione-moduli.md`
16. `docs/guida-intensita-quest.md`
17. `docs/changelog.md`

## Aggiornamenti recenti (2026-04-26)
1. `README.md`, `CONTRIBUTING.md` e `docs/guida-build-release.md`: allineati al pacchetto `source-dev`, che ora include `scripts/`, sorgenti Sass e le guide operative utili a sviluppatori e contributori.
2. `dist/release/README.md`: note packaging aggiornate con esempi pratici per generare il pacchetto `ready` o il pacchetto `source-dev`.
3. `docs/guida-sistema-moduli.md` e `docs/guida-creazione-moduli.md`: rese piu autosufficienti con esempi concreti di lifecycle, manifest e creazione modulo.

## Aggiornamenti recenti (2026-04-23)
1. `CONTRIBUTING.md`: aggiunte policy Git/PR ufficiali, naming branch/commit, lifecycle review/merge, Definition of Done e esempi concreti di stile codice (PHP/JS/Twig).
2. `docs/guida-contributori.md`: aggiunta sintesi operativa su workflow Git/PR con collegamento al riferimento completo in `CONTRIBUTING.md`.

## Aggiornamenti recenti (2026-04-15 - v0.8.0)
1. `description_project.md` aggiornato: rimossa data, aggiunto stato v0.8.0, contratti, modelli, tree shaking, disaccoppiamento moduli.
2. `guida-contributori.md`: aggiunte regole su `app/Contracts/`, `app/Models/`, disaccoppiamento via `CustomEvent`, ESM obbligatorio.
3. `guida-architettura-frontend.md`: aggiunta sezione disaccoppiamento core/moduli via `CustomEvent`; aggiunta sezione bundler ESM e `window-globals-registry.json`.
4. `guida-sistema-moduli.md` e `guida-creazione-moduli.md`: aggiornate con regola di disaccoppiamento via DOM events e regole ESM.

## Riferimenti tecnici rapidi
1. `docs/contratti-api-backend.md`
2. `docs/riferimento-componenti-frontend.md`
3. `docs/riferimento-servizi-frontend.md`
4. `docs/logeon-module-governance-system.md`

## Smoke runtime (CLI)
1. Suite core:
   - `C:\xampp\php\php.exe scripts/php/smoke-core-runtime.php`
2. Check core separati:
   - `C:\xampp\php\php.exe scripts/php/smoke-core-db-runtime.php`
   - `C:\xampp\php\php.exe scripts/php/smoke-core-auth-runtime.php`
3. Check domini estesi (se presenti/abilitati):
   - `C:\xampp\php\php.exe scripts/php/smoke-system-events-runtime.php`
   - `C:\xampp\php\php.exe scripts/php/smoke-quests-runtime.php`
   - `C:\xampp\php\php.exe scripts/php/smoke-theme-runtime.php`

## Regole manutenzione docs
1. Niente file di checklist operativa o tracking task in `docs/` pubblica.
2. Ogni documento deve avere la riga `Ultimo aggiornamento`.
3. Preferire guide unificate e stabili rispetto a note temporanee.
