# Guida Personalizzazione Gioco

Ultimo aggiornamento: 2026-04-15

Guida step-by-step per chi scarica Logeon e vuole creare il proprio gioco personalizzato.

## 1. Setup iniziale
1. Copia il progetto nella webroot (es. `C:\xampp\htdocs\logeon`).
2. Esegui `composer install`.
3. Verifica che `vendor/autoload.php` sia presente.
4. Configura:
   - `configs/config.php`
   - `configs/db.php`
   - `configs/app.php`

## 2. Prima installazione del database
Scegli un percorso.

Percorso A (consigliato): wizard installer.
1. usa un database vuoto
2. apri `/install`
3. completa i 5 step (app config, DB test, write config, init DB, finalize)
4. nello step `init DB`, Logeon importa lo schema unico `database/logeon_db_core.sql`

Percorso B: import manuale.
1. importa `database/logeon_db_core.sql`
2. verifica `configs/installed.php` con `INSTALLED = true`

## 3. Branding base del tuo gioco
Modifica `configs/app.php`:
1. `baseurl`
2. `name`
3. `title`
4. `description`
5. contatti staff/supporto
6. logo (`brand_logo_icon`, `brand_logo_wordmark`)

## 4. Personalizzare le pagine pubbliche
Intervieni in `app/views/`:
1. homepage: `app/views/index.twig`
2. regolamento: `app/views/rules.twig`
3. storyboard: `app/views/storyboard.twig`

Regola: niente JS/CSS inline nelle view.

## 5. Personalizzare area gioco (`/game`)
Template principali:
1. `app/views/app/maps.twig`
2. `app/views/app/location.twig`
3. `app/views/app/profile.twig`
4. `app/views/app/forum.twig`
5. `app/views/app/shop.twig`

Logica frontend:
1. feature pagina in `assets/js/app/features/game/*`
2. moduli API in `assets/js/app/modules/game/*`
3. componenti shared in `assets/js/components/*`

## 6. Aggiungere una nuova funzionalita (pattern consigliato)
Esempio standard:
1. crea endpoint backend in `app/controllers/*` + route in `app/routes/api.php` o `custom/routes.php`
2. applica i controlli autorizzazione con `AuthGuard`
3. crea modulo frontend in `assets/js/app/modules/game/*`
4. collega la feature pagina in `assets/js/app/features/game/*`
5. aggiungi hook `data-*` nel Twig
6. testa il flusso completo

## 7. Regole per non rompere il core
Non toccare questi file per personalizzazioni normali:
1. `core/Models.php`
2. `core/Router.php`
3. `core/SessionGuard.php`
4. `core/Template.php`
5. `core/Database/MysqliDbAdapter.php`
6. `core/Database/DbAdapterFactory.php`
7. `app/services/AuthService.php`
8. `autoload.php`
9. `app/routes.php`

Usa questi punti di estensione:
1. `custom/routes.php`
2. `custom/bootstrap.php`
3. `app/services/*`
4. `app/controllers/*`
5. `app/views/*`
6. `assets/js/app/*`

## 8. Permessi e sicurezza
1. backend autoritativo: i permessi si validano sempre in PHP
2. frontend solo per UX/visibilita
3. per operazioni sensibili usa `AuthGuard::api()->requireAbility(...)`
4. mantieni `CONFIG['debug'] = false` in produzione

## 9. Stile e tema
1. base CSS: `assets/css/framework.css`
2. override tema: `assets/css/style.css`
3. CSS admin: `assets/css/admin.css`
4. personalizzazioni del creatore di gioco: `assets/css/custom.css`
5. sorgenti Sass: `assets/sass/framework.scss`, `assets/sass/style.scss`, `assets/sass/admin.scss`
6. policy: `assets/sass/*` e la sorgente autorevole, `assets/css/*` e output compilato
7. `assets/css/custom.css` resta volutamente vuoto e va usato solo per override del gioco, non per stili core
8. build:
   `sass.cmd --load-path=assets/vendor/bootstrap-5.3.8/scss assets/sass/style.scss assets/css/style.css --style=expanded --source-map`
   `sass.cmd assets/sass/framework.scss assets/css/framework.css --style=expanded --source-map`
   `sass.cmd assets/sass/admin.scss assets/css/admin.css --style=expanded --source-map`

## 10. Configurazione del pannello admin
Prima di pubblicare, configura le entita di gioco dall'area `/admin`:
1. mappe e location
2. oggetti, categorie, rarita, slot equipaggiamento
3. botteghe e inventario delle botteghe
4. valute
5. lavori e compiti
6. gilde (struttura base)
7. news e regolamento

## 11. Accesso Google e personaggi multipli
Configurazione in `/admin/settings`:
1. `Google OAuth`:
   - `auth_google_enabled`: abilita/disabilita login Google.
   - `auth_google_client_id`, `auth_google_client_secret`, `auth_google_redirect_uri`.
   - se disabilitato, il pulsante Google non viene mostrato nella modale di login.
2. `Personaggi multipli`:
   - `multi_character_enabled`: abilita account con piu personaggi.
   - `multi_character_max_per_user`: massimo personaggi per utenza (1-10).

Comportamento runtime:
1. se personaggi multipli disattivi: login diretto come prima.
2. se attivi e l'utente ha piu personaggi: dopo il login viene richiesta la selezione del personaggio.
3. la selezione crea la sessione completa del personaggio scelto (user + character + config runtime).

## 12. Checklist prima di pubblicare il tuo gioco
1. login/logout funzionanti
2. mappe/location/chat funzionanti
3. forum/thread/messaggi funzionanti
4. shop/inventario/equip funzionanti
5. upload profilo funzionante
6. pannello admin accessibile e funzionante
7. nessun fatal error PHP nei log
8. nessun loop anomalo di richieste nel browser
9. smoke core runtime CLI verde:
   `C:\xampp\php\php.exe scripts/php/smoke-core-runtime.php`
10. debug disattivato

## 13. Dove approfondire
1. overview documentazione: `docs/README.md`
2. guida contributori: `docs/guida-contributori.md`
3. runtime frontend: `docs/guida-architettura-frontend.md`
4. sistema moduli: `docs/guida-sistema-moduli.md`
5. contratti API: `docs/contratti-api-backend.md`
