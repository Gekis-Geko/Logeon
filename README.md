# Logeon

Motore open-source per giochi play-by-chat con backend PHP/Twig e runtime frontend modulare.

Ultimo aggiornamento: 2026-04-03

## Scopo del progetto
Logeon ti permette di costruire un gioco browser RPG/PbC con:
1. area pubblica (homepage, regolamento, storyboard)
2. area gioco autenticata (`/game`)
3. API JSON per gameplay e admin
4. struttura estendibile senza toccare continuamente il core

## Funzionalita principali
1. autenticazione utente e gestione profilo/personaggio
2. mappe, location e chat contestuale di location
3. messaggi privati e forum/thread/risposte
4. inventario, equip, shop e valute
5. gilde, ruoli, candidature, eventi e annunci
6. meteo globale e per location
7. quest con step, condizioni, ricompense e storico
8. conflitti (risoluzione narrativa e casuale) con log dei tiri
9. eventi di sistema e narrativi
10. news/avvisi in-game
11. pannello admin (40+ pagine CRUD + 7 log analitici)
12. installer guidato (`/install`) con lock di installazione

## Accesso account (runtime)
1. Login standard con email/password (`POST /signin`).
2. Login Google OAuth governato da impostazioni admin:
   - `auth_google_enabled`
   - `auth_google_client_id`
   - `auth_google_client_secret`
   - `auth_google_redirect_uri`
3. Supporto personaggi multipli per account:
   - `multi_character_enabled`
   - `multi_character_max_per_user` (1-10)
4. Se multi-personaggio e attivo e l'utente ha piu personaggi:
   - il login risponde con `error_character_select`
   - il frontend apre la modale selezione personaggio
   - finalizzazione con `POST /signin/character/select`.

## Stato attuale del refactor
Il codice e stato modernizzato in modo incrementale senza big bang.

Milestone corrente:
1. R1 chiusa (baseline stabile) in data 2026-02-27
2. R2 in corso — backend gameplay consolidato, admin maturo
3. refactor core residuo pianificato per tranche successive

Blocchi gia completati:
1. frontend runtime modulare (`assets/js/app/*`) con separazione `core`, `features`, `modules`
2. boundary HTTP in PHP (`Core\Http\*`) con gestione errori centralizzata
3. introduzione `AuthGuard` come gate unico per autorizzazioni backend
4. `Template` allineato a Twig-only (niente fallback pre-Origin0)
5. progressiva riduzione dei pattern pre-Origin0 (`echo json_encode`, `die`, parser request sparsi)
6. autoload consolidato su Composer PSR-4 per classi namespaced
7. pannello admin completo con registry modulare (`admin.registry.js`)
8. log analitici (conflitti, valute, esperienza, fama, gilde, lavori, accessi location, sistema)
9. log accessi location: sia accessi consentiti che negati vengono registrati

Dettagli aggiornati: `docs/README.md`.

## Requisiti
1. PHP 8.1+ (consigliato 8.2)
2. MySQL/MariaDB
3. Composer
4. ambiente web locale (XAMPP o equivalente)

## Installazione rapida
1. Clona il repository in webroot.
2. Installa dipendenze: `composer install`
3. Configura i file:
   - `configs/config.php`
   - `configs/db.php`
   - `configs/app.php`
4. Runtime DB:
   - il progetto usa adapter `mysqli` come profilo unico (nessun fallback pre-Origin0).
5. Sanity check CLI DB/core:
   - `C:\xampp\php\php.exe scripts/php/smoke-core-db-runtime.php`
6. Sanity check CLI Auth/Session core:
   - `C:\xampp\php\php.exe scripts/php/smoke-core-auth-runtime.php`
7. Suite unica sanity check core:
   - `C:\xampp\php\php.exe scripts/php/smoke-core-runtime.php`
8. Se e una nuova installazione, avvia il wizard su `/install`.
9. Lo step `init-db` dell'installer importa il file unico `database/logeon_db_core.sql`.
10. Se usi dump SQL manuale, importa direttamente `database/logeon_db_core.sql`.
11. Apri `/` per area pubblica e `/game` per il gioco.

Nota: il bootstrap richiede `vendor/autoload.php`; senza `composer install` l'app non parte.

## Struttura progetto
1. `app/controllers` endpoint e orchestrazione HTTP
2. `app/models` modelli dati storici compatibili
3. `app/services` logica dominio riusabile
4. `app/views` template Twig (`app`, `admin`, `sys`)
5. `app/routes` registrazione route (`public`, `game`, `install`, `api`)
6. `core` infrastruttura framework (router, auth, db, http boundary, template)
7. `assets/js/app` runtime applicativo moderno
8. `assets/js/components` componenti UI shared
9. `assets/js/services` servizi HTTP/storage/auth lato client
10. `custom` estensioni locali (`bootstrap.php`, `routes.php`)
11. `docs` documentazione tecnica e guide operative

## Flusso applicativo (alto livello)
1. richiesta browser -> `index.php` -> `autoload.php`
2. bootstrap config + Composer + hook custom
3. `app/routes.php` monta route da `app/routes/*.php`
4. controller gestisce request con `RequestData`/`AuthGuard`
5. risposta via `Template::view(...)` (HTML Twig) o `ResponseEmitter` (JSON)
6. frontend runtime monta feature/moduli in base alla pagina

## Regola chiave per personalizzazioni
Personalizza prima nei punti di estensione, non nel core.

Punti sicuri:
1. `custom/routes.php` per route custom
2. `custom/bootstrap.php` per hook e bootstrap locale
3. `app/views/*` per UI Twig
4. `assets/js/app/features/*` e `assets/js/app/modules/*` per logica frontend
5. `app/services/*` per nuova logica backend
6. `app/controllers/*` per nuovi endpoint

File ad alto rischio (toccarli solo con refactor mirato):
1. `core/Models.php`
2. `core/Router.php`
3. `core/SessionGuard.php`
4. `core/Template.php`
5. `core/Database/MysqliDbAdapter.php`
6. `core/Database/DbAdapterFactory.php`
7. `app/services/AuthService.php`
8. `autoload.php`
9. `app/routes.php` (preferire i file in `app/routes/*` e `custom/routes.php`)

## Sicurezza e policy
1. autorizzazione server-side sempre obbligatoria (`Core\AuthGuard`)
2. il client gestisce solo UX e visibilita
3. in produzione usare `CONFIG['debug'] = false` in `configs/config.php`
4. per upload usare `UploadManager` e policy backend, non validazioni solo client

## Documentazione da leggere
1. indice docs: `docs/README.md`
2. regole contributo repository: `CONTRIBUTING.md`
3. guida contributori: `docs/guida-contributori.md`
4. guida personalizzazione gioco: `docs/guida-personalizzazione-gioco.md`
5. guida architettura frontend: `docs/guida-architettura-frontend.md`
6. guida sistema moduli: `docs/guida-sistema-moduli.md`
7. guida runtime DB e schema: `docs/guida-runtime-db-schema.md`
8. contratti API backend: `docs/contratti-api-backend.md`

## Sviluppo CSS/Sass
1. CSS compilato: `assets/css/framework.css`
2. override custom: `assets/css/style.css`
3. sorgente Sass: `assets/sass/framework.scss`
4. regola di progetto: `assets/sass/*` e la fonte; `assets/css/*` e output compilato
5. evita modifiche manuali dirette ai file compilati quando possibile
6. applica le modifiche stilistiche nei file Sass e poi ricompila
7. build one-shot:
   `sass.cmd assets/sass/framework.scss assets/css/framework.css --style=expanded --no-source-map`
8. watch:
   `sass.cmd --watch assets/sass/framework.scss:assets/css/framework.css --style=expanded --no-source-map`