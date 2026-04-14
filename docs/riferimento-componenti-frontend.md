# Riferimento Componenti Frontend

Ultimo aggiornamento: 2026-04-02

## Scopo
Riferimento sintetico dei componenti JS usati nel runtime Logeon.
Questa guida sostituisce la vecchia documentazione frammentata in molti file.

## Dove si trovano
1. Componenti shared: `assets/js/components/*`
2. Utility pure: `assets/js/components/utils/*`

## Componenti UI principali
1. `Modal.js`: wrapper modali Bootstrap.
2. `Dialog.js`: conferme/alert standard.
3. `Toast.js`: notifiche toast.
4. `Tooltip.js`: bootstrap tooltip runtime-safe.
5. `DataGrid.js`: tabelle con sorting/filter/paginazione.
6. `Paginator.js`: paginazione dataset.
7. `Search.js`: ricerca locale su liste/griglie.
8. `Uploader.js`: upload file con progress.

## Componenti di selezione input
1. `SelectionGroup.js`: base comune.
2. `RadioGroup.js`: selezione singola.
3. `CheckGroup.js`: selezione multipla.
4. `SwitchGroup.js`: toggle guidato (on/off, si/no).

## Componenti runtime e supporto
1. `Navbar.js`: comportamento navbar/offcanvas.
2. `Dashboard.js`: helper pagine dashboard/admin.
3. `PollManager.js`: polling periodico.
4. `EventBus.js`: evento pub/sub leggero.
5. `PermissionGate.js`: gating UI da permessi.
6. `ConfigStore.js`: accesso config frontend.
7. `Calendar.js`: utility calendario.
8. `DocsRender.js`: rendering contenuti docs.
9. `SlideShow.js`: slideshow immagini.
10. `DiceEngine.js` e `Dices.js`: motore dadi.
11. `CommandParser.js`: parser comandi chat.

## Runtime area admin
1. Le pagine admin non usano componenti `Admin*.js` come sorgente primaria.
2. La logica pagina vive in `assets/js/app/features/admin/*`.
3. L'orchestrazione pagina/modulo passa da:
   - `assets/js/app/core/admin.registry.js`
   - `assets/js/app/core/admin.runtime.js`
4. I componenti shared restano in `assets/js/components/*` (DataGrid, SwitchGroup, Dialog, Modal, ecc.).

## Utility pure (`assets/js/components/utils`)
1. `Utils.js`: helper base generici.
2. `Dates.js`: formattazione date/ora.
3. `Form.js`: serializzazione e helper form.
4. `Cookie.js`: helper cookie.

## Regole d'uso rapide
1. Riusa un componente shared prima di crearne uno nuovo.
2. Evita logica di dominio dentro i componenti UI.
3. Permessi UI sempre come supporto UX, mai come sicurezza.
4. Se aggiungi un componente shared, documenta almeno: scopo, API minima, esempio uso.
