# Guida Contributori

Ultimo aggiornamento: 2026-04-23

## Scopo
Guida unica per contribuire a Logeon senza regressioni su `/game`, `/admin` e core runtime.

## Lettura consigliata (ordine)
1. `README.md`
2. `CONTRIBUTING.md`
3. `docs/README.md`
4. `docs/guida-architettura-frontend.md`
5. `docs/guida-runtime-db-schema.md`
6. `docs/contratti-api-backend.md`

## Setup locale minimo
1. `composer install`
2. Configura `configs/config.php`, `configs/db.php`, `configs/app.php`
3. Inizializza DB da installer (`/install`) o import dello schema unico
4. Verifica ambiente:
   - `C:\xampp\php\php.exe scripts/php/smoke-core-runtime.php`

## Regole obbligatorie
1. Niente JS inline nei template Twig.
2. Niente CSS inline nei template Twig.
3. Permessi sempre server-side con `Core\AuthGuard`.
4. Nuovi endpoint JSON con `Core\Http\RequestData` + `Core\Http\ResponseEmitter`.
5. Non introdurre nuovi pattern legacy (`echo json_encode(...)`, `die(...)`, parsing diretto `$_POST['data']`).
6. Se una logica e modulo-specifica, non metterla nel core.
7. Le view pubbliche che includono la modale login devono ricevere dal backend il contesto `google_auth` (almeno `google_auth.enabled`).
8. Le interfacce di dominio vanno in `app/Contracts/`, non mescolate con i file di service.
9. Il core non deve importare o richiamare direttamente codice di moduli opzionali: usare `CustomEvent` neutrali o hook (`Core\Hooks`).
10. I nuovi file JS devono usare ESM (`import`/`export`), non IIFE ne assegnamenti `window.*`.

## File ad alto rischio (toccare solo con task mirato)
1. `core/Models.php`
2. `core/Router.php`
3. `core/SessionGuard.php`
4. `core/Template.php`
5. `core/Database/MysqliDbAdapter.php`
6. `core/Database/DbAdapterFactory.php`
7. `app/services/AuthService.php`
8. `autoload.php`
9. `app/routes.php`

## Workflow standard per una feature
1. Definisci comportamento + permessi.
2. Implementa service backend.
3. Implementa controller + route.
4. Aggiorna UI Twig con hook `data-*`.
5. Implementa logica frontend in `assets/js/app/features/*` o `assets/js/app/modules/*`.
6. Se e una nuova pagina admin, registrala in **tutti e tre** i punti:
   - `MODULE_FACTORY_MAP` in `admin.registry.js`
   - `getPageModules()` in `admin.registry.js` (fonte autoritativa — non ometterlo)
   - `modules` map in `admin.runtime.js`
7. Aggiorna docs coinvolte.

## Workflow Git e Pull Request (sintesi)
1. Crea branch breve da `main` aggiornato.
2. Apri PR in `Draft` appena il perimetro e chiaro.
3. Porta la PR a `Ready for review` solo dopo i check minimi.
4. Risolvi commenti review con commit dedicati.
5. Merge preferito: `Squash and merge`.

Convenzioni rapide:
1. Branch: `<tipo>/<area>-<descrizione-breve>`
   - esempio: `fix/admin-settings-toast-save`
2. Commit: `<type>(<scope>): <summary>`
   - esempio: `refactor(quests): replace hardcoded navbar slot with extension point`

Riferimento completo:
1. `CONTRIBUTING.md` (sezioni workflow Git, policy review/merge, esempi codice, Definition of Done)

### Nota operativa su login
1. Endpoint base login: `POST /signin`.
2. Se il backend risponde `error_character_select`, il frontend deve aprire la modale selezione personaggio e usare:
   - `POST /signin/characters/list`
   - `POST /signin/character/select`
3. Non bypassare la selezione lato client: la sessione personaggio va sempre finalizzata server-side.

## Standard frontend
1. Ogni pagina usa feature controller dedicato.
2. Logica API in moduli (`assets/js/app/modules/*`), non nelle view.
3. Componenti shared in `assets/js/components/*` solo se riuso reale.
4. UI permessi con DSL `data-requires-*`, ma backend resta autoritativo.
5. Modali/offcanvas: lifecycle pulito (no overlay incrociati).
6. Pipeline stili: `assets/sass/*` e sorgente, `assets/css/*` e compilato.
7. Le modifiche tema/framework vanno fatte in Sass, poi ricompilate in CSS.

## Standard backend
1. Validazione input lato controller.
2. Business rules nei service.
3. Error code coerenti e stabili.
4. Patch SQL idempotenti; una volta integrate in `database/logeon_db_core.sql` i file patch separati vanno eliminati.
5. Nessuna dipendenza hard del core da moduli opzionali.
6. Per nuove dipendenze runtime nel core, preferire i contratti esposti da `Core\AppContext` (session, auth context, renderer, config repository, db provider) invece di chiamate statiche dirette.
7. Le interfacce di dominio appartengono a `app/Contracts/` (namespace `App\Contracts`), non a `app/Services/`.
8. I modelli (`app/Models/`) estendono `Core\Models` con `$table`, `$primary_key` e `$fillable`; nessuna logica di business al loro interno.

## Verifiche minime prima di commit
1. `php -l` su ogni file PHP toccato.
2. `node --check` sui file JS toccati.
3. Standard PHP (PSR tranche attive):
   - `composer run lint:php`
   - `composer run psr:check`
4. Smoke core:
   - `C:\xampp\php\php.exe scripts/php/smoke-core-db-runtime.php`
   - `C:\xampp\php\php.exe scripts/php/smoke-core-auth-runtime.php`
   - `C:\xampp\php\php.exe scripts/php/smoke-core-runtime.php`
5. Smoke funzionale della feature toccata.

## Checklist Pull Request
1. Problema e obiettivo descritti chiaramente.
2. Soluzione spiegata per blocchi (backend/frontend/DB).
3. Permessi verificati.
4. Contratti API aggiornati se necessario.
5. Changelog aggiornato in `docs/changelog.md` con sezione `Aggiunto/Modificato/Bugfix/Verifica tecnica`.
6. Nessun file temporaneo (`tmp_*`, patch helper) lasciato nel repo.

## Manutenzione documentazione
1. Nomi file parlanti e senza data nel filename.
2. Ogni guida deve avere riga `Ultimo aggiornamento`.
3. Preferire documenti unificati invece di tanti file frammentati.
