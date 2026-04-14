# Guida Architettura Frontend

Ultimo aggiornamento: 2026-04-02

## Obiettivo
Definire lo standard operativo del frontend modulare basato su `AppBootstrap`, con priorita su gameplay (`app/views/app`) e view leggere.

## Principi
- componente shared per comportamento condiviso
- feature/module per logica di dominio
- doppia validazione: client + backend
- fallback espliciti solo dove necessari
- nessuna dipendenza da alias globali legacy

## Architettura runtime

### Core
- `assets/js/app/core/Context.js`
- `assets/js/app/core/ModuleRegistry.js`
- `assets/js/app/core/AppBootstrap.js`
- `assets/js/app/core/RuntimeBootstrap.js`
- `assets/js/app/core/game.*.js`
- `assets/js/app/core/system.dialogs.js`

### Layer applicativo
- `assets/js/app/features/game/*`:
  controller/factory di pagina (`Game*Page`).
- `assets/js/app/modules/game/*`:
  servizi modulo per list/create/update/delete e operazioni specializzate.
- `assets/js/app/App.js`:
  facade minima (`get`, `mount`, `call`).

## Contratti tecnici

### Facade
- `App().get(name)`
- `App().mount(name, options)`
- `App().call(name, method, payload)`

### Runtime API pubblica
- `RuntimeBootstrap.boot(config)`
- `RuntimeBootstrap.start(config)`
- `RuntimeBootstrap.stop(config)`
- `RuntimeBootstrap.resolveAppModule(moduleName)`
- `RuntimeBootstrap.restartGameRuntime(options)`

Nota: metodi interni non necessari non vengono esposti come API pubblica.

### Moduli
Ogni modulo registrato deve esporre API coerente:
- `list(payload)`
- `create(payload)`
- `update(payload)`
- `delete(payload)`

Quando necessario:
- `get(payload)`
- azioni verticali (`assign`, `promote`, `setPrimary`, ecc.)

## Bootstrap per pagina

### Game (`app/views/app`)
- bootstrap da `assets/js/app/core/bootstrap.game.js`
- mount moduli in base a `pageKey`
- binder UI da `game.ui`/`game.page`

### Admin (`app/views/admin`)
- bootstrap da `assets/js/app/core/bootstrap.admin.js`
- runtime modulare operativo con registry come fonte autoritativa

#### Registry admin (pattern obbligatorio per nuove pagine admin)
Il routing pagina→modulo passa per `admin.registry.js`, non per `admin.runtime.js`.
`RuntimeBootstrap.boot()` chiama `applyRegistryToPageConfig()` che sovrascrive la mappa `modules` del runtime con il risultato di `AdminRegistry.getPageConfig()`.

Ogni nuova pagina admin richiede **tre punti di registrazione**:
1. `MODULE_FACTORY_MAP` in `admin.registry.js` — risoluzione factory del modulo
2. `getPageModules()` in `admin.registry.js` — fonte autoritativa per routing pagina→modulo
3. `modules` map in `admin.runtime.js` — necessario ma secondario (sovrascritto dal registry)

Omettere il punto 2 causa mancato mount del modulo anche se il punto 3 è presente.

## Regole di implementazione
- nessun JS inline nelle view
- nessun CSS inline nelle view
- script caricati da layout con ordine core -> features -> modules -> bootstrap
- filtri/datagrid in GET dove richiesto dal flusso pagina
- fallback legacy solo se documentato e temporaneo
- le view pubbliche che includono login devono ricevere `google_auth.enabled` dal backend per mostrare/nascondere il pulsante OAuth

## Flusso login multi-personaggio
1. `POST /signin` puo rispondere con `error_character_select`.
2. In quel caso la UI apre la modale selezione personaggio.
3. Conferma selezione via `POST /signin/character/select`.
4. Solo il backend finalizza sessione character e redirect a `/game`.

## Guide complementari
- Guida contributori (moduli/pagine/funzionalita):
  `docs/guida-contributori.md`
- UI authz dichiarativa (`data-requires-*`, refresh su `authz:changed`):
  `docs/guida-permessi-ui-attributi.md`
- Riferimento componenti frontend:
  `docs/riferimento-componenti-frontend.md`
- Usare gli attributi `data-requires-*` per downgrade/refresh runtime dei permessi lato client.
- Mantenere comunque il gating server-side (Twig/PHP) e l'autorizzazione backend.

## Guardrail runtime
Script di controllo:
- `scripts/smoke-runtime-guardrails.mjs`
- `scripts/guardrails/check-runtime-deprecations.mjs`
- `scripts/guardrails/check-selectiongroup-integration.mjs`

Vincoli:
- bloccare token/alias deprecati
- bloccare include a file rimossi
- mantenere integrazione `SelectionGroup`/`RadioGroup` coerente

## Stato consolidato
- Runtime gameplay operativo in modalita module-first.
- Runtime admin operativo con registry modulare.
- Helper legacy globali consolidati nel core.
- `App.js` ridotto a facade runtime.
- Componenti shared in fase continua di hardening, senza dipendenze nascoste.

## Pattern Datagrid admin — note operative
- Usare `grid.loadData(payload, limit, page, orderBy)` per pagine con filtri liberi.
- Usare `grid.setFilters(filters)` per pagine log. **Non chiamare `grid.load()` dopo `setFilters()`**: `setFilters()` chiama già `load()` internamente. Una doppia chiamata manda una seconda richiesta con criteri vuoti che può sovrascrivere il risultato filtrato.

## Flusso consigliato per nuovi sviluppi
1. Definire contratto modulo (API + permessi backend).
2. Implementare modulo in `modules/game`.
3. Implementare controller di pagina in `features/game`.
4. Collegare bootstrap `pageKey -> module/factory`.
5. Validare con guardrail + smoke manuale mirato.
6. Documentare delta in `docs/riferimento-componenti-frontend.md` o doc tecnica dedicata.
