# Modulo Logeon Quests

## Scopo
Questo modulo espone il dominio Quest come feature opzionale:
1. trigger runtime quest via `QuestTriggerService::bootstrap()` (listener su hook core);
2. endpoint API quest (`/quests/*`) e admin (`/admin/quests/*`) caricati da `routes.php`;
3. viste Quest game/admin caricate da `views/` tramite slot Twig.

## Integrazione runtime
1. `module.json` dichiara `entrypoints.bootstrap` e `entrypoints.routes`.
2. `bootstrap.php` registra:
   - autoloader PSR-4 del modulo;
   - hook Twig tramite `QuestModuleBootstrap::registerHooks()`:
     - `twig.view_paths`
     - `twig.slot.game.modals`
     - `twig.slot.game.navbar.quests`
     - `twig.slot.game.offcanvas.mobile.quests`
     - `twig.slot.admin.dashboard.quests`
   - bootstrap trigger quest tramite `QuestModuleBootstrap::bootstrapTriggers()`.
3. `routes.php` registra le route quest solo quando il modulo e attivo:
   - `/quests/*`
   - `/admin/quests/*`
   - `/game/quests/history`
4. `views/` contiene i template quest game/admin estratti dal core.

## Note operative
- Con modulo OFF:
  - route quest assenti;
  - nessun listener trigger quest attivo;
  - nessun rendering UI quest in navbar/offcanvas/dashboard admin.
- Con modulo ON:
  - API e pagine quest operative con comportamento equivalente al legacy.
- Nessuna dipendenza hardcoded del core verso ID modulo o classi modulo.
