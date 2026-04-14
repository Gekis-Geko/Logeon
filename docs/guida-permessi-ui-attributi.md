# Guida Permessi UI e Attributi `data-requires-*`

Ultimo aggiornamento: 2026-03-20

Percorso implementazione: `assets/js/app/core/game.page.js`

## Obiettivo
Questa guida descrive la mini-DSL HTML usata dal binder authz di `GamePage` per:
- mostrare/nascondere elementi UI
- disabilitare elementi UI
- reagire ai cambi permesso runtime (`authz:changed`) senza reload pagina

Serve per ridurre logica condizionale JS sparsa nelle feature e rendere i vincoli UI dichiarativi direttamente nel markup Twig.

## Come funziona (in breve)
1. `GamePage.bind()` aggancia anche `bindAuthzUi()`.
2. Il binder scansiona `#page-content` per elementi con attributi `data-requires-*`.
3. Valuta i permessi tramite `PermissionGate()`.
4. Applica stato UI (`hide`/`disable`) in base al risultato.
5. Quando `PermissionGate` emette `authz:changed`, il binder richiama `refreshAuthzUi()`.

Nota: il backend resta sempre autoritativo. Questa DSL e solo UX/client-side.

## Attributi supportati

### Requisiti booleani (true/false)
Se l'attributo e presente senza valore, vale `true`.

- `data-requires-auth`
  - Richiede utente autenticato.
  - Esempio: `data-requires-auth`

- `data-requires-guest`
  - Richiede utente guest/non autenticato.
  - Esempio: `data-requires-guest`

- `data-requires-page-owner`
  - Usa `data-is-owner` della `.ui-page` corrente.
  - Esempio owner-only: `data-requires-page-owner`
  - Esempio not-owner: `data-requires-page-owner="0"`

- `data-requires-admin`
- `data-requires-moderator`
- `data-requires-master`
- `data-requires-staff`
- `data-requires-forum-admin`

Esempio:
```html
<button data-requires-staff>Strumento staff</button>
```

### Requisiti per owner specifico
- `data-requires-owner-id="123"`
  - Richiede che `PermissionGate().isOwner(123)` sia vero.
  - Utile quando il riferimento owner e noto nel markup.

### Requisiti per ruoli
- `data-requires-role="admin moderator"`
  - OR tra ruoli (almeno uno)
- `data-requires-all-roles="admin master"`
  - AND tra ruoli (tutti richiesti)

Separatori supportati: spazio o virgola.

### Requisiti per capability
- `data-requires-capability="forum.admin"`
  - OR tra capability
- `data-requires-all-capabilities="forum.admin users.manage"`
  - AND tra capability

Richiede `PermissionGate().can(...)`.

## Combinazione delle regole (`AND` / `OR`)

### Default (AND)
Se non specifichi nulla, tutte le regole presenti devono essere vere.

Esempio:
```html
<div data-requires-auth data-requires-staff>
```
Significa: autenticato AND staff.

### OR esplicito
- `data-requires-match="any"` (alias: `or`)

Esempio owner OR staff:
```html
<div data-requires-match="any" data-requires-page-owner data-requires-staff>
```

## Modalita UI (`hide` / `disable`)

### Default
Se non specifichi modalita, il binder usa:
- `hide`

### Attributo
- `data-requires-mode="hide"`
- `data-requires-mode="disable"`
- `data-requires-mode="both"`

Esempio (meglio per bottoni/azioni visibili ma non cliccabili):
```html
<a class="btn btn-light" data-requires-staff data-requires-mode="disable">
```

## Parsing booleano valori
Valori riconosciuti come `true`:
- `1`
- `true`
- `yes`

Valori riconosciuti come `false`:
- `0`
- `false`
- `no`
- assenza valore con logica invertita (es. `data-requires-page-owner="0"`)

## Cosa fa il binder quando applica gli stati

### `hide`
- aggiunge/rimuove `hidden`
- preserva e ripristina lo stato `hidden` originale dell'elemento

### `disable`
- per elementi nativamente disabilitabili (`button`, `input`, `select`, ecc.) usa `disabled`
- aggiunge `aria-disabled="true"`
- aggiunge classe `disabled`
- su elementi non disabilitabili puo aggiungere `tabindex="-1"` (se mancava)
- ripristina lo stato originale quando il requisito torna valido

## Hardening runtime automatico (attuale)
Dopo ogni `refreshAuthzUi()` il binder esegue anche:

- fallback tab Bootstrap:
  - se un tab attivo viene nascosto/disabilitato dal binder, prova ad attivare il primo tab valido visibile
- normalizzazione focus:
  - se l'elemento attivo viene nascosto/disabilitato, esegue `blur()` per evitare stati incoerenti/accessibility warning

## Esempi reali nel progetto (gia applicati)
- `app/views/app/location.twig`
  - `#location-weather-staff` con `data-requires-staff`
- `app/views/app/offcanvas/onlines.twig`
  - toggle invisibilita con `data-requires-staff` + `data-requires-mode="disable"`
- `app/views/app/thread.twig`
  - area risposta thread e pulsante risposta con `data-requires-auth`
- `app/views/app/profile.twig`
  - blocchi owner-only/not-owner
  - tab/pane/bottone avvenimenti con `owner OR staff`
- `app/views/app/profile_edit.twig`
  - form owner-only e alert not-owner
- `app/views/app/modals/profile/edit-diary.twig`
  - modale e submit con `owner OR staff`

## Requisiti per `data-requires-page-owner`
La pagina deve esporre nella `.ui-page`:

```html
<div class="ui-page" data-is-owner="1">
```

Se `data-is-owner` non esiste, `data-requires-page-owner` risulta `false`.

## Limiti attuali (importanti)
- Non crea elementi che Twig non ha renderizzato.
  - Se un bottone non e in DOM, il binder non puo mostrarlo.
- Non sostituisce i controlli backend.
  - Le action devono restare autorizzate lato PHP.
- `disable` su alcuni elementi custom/anchor puo non bastare da solo se CSS/handler custom ignorano `.disabled`.
  - In quei casi usare `hide` o aggiungere controllo handler.
- Non gestisce automaticamente casi UX avanzati:
  - cambio tab attivo se un tab/pane viene nascosto a runtime
  - fallback su altro elemento focusabile

## Best practice consigliate
- Usa Twig per il gating server-side critico.
- Aggiungi `data-requires-*` per il downgrade/refresh runtime.
- Preferisci `hide` per azioni sensibili o layout semplice.
- Usa `disable` quando vuoi mostrare l'azione ma renderla non interattiva.
- Per regole composte leggibili, usa:
  - `data-requires-match="any"`
  - `data-requires-page-owner`
  - `data-requires-staff`

## Debug rapido
- Forza refresh binder:
```js
GamePage.refreshAuthzUi()
```

- Forza solo fallback tab/focus:
```js
GamePage.ensureAuthzTabsState()
GamePage.normalizeAuthzFocus()
```

- Simula cambio permessi (se `PermissionGate` e configurato con `emitEvents: true`):
```js
PermissionGate().setFromUser({ id: 1, is_administrator: 1, is_moderator: 0, is_master: 0 })
PermissionGate().setFromUser({ id: 1, is_administrator: 0, is_moderator: 0, is_master: 0 })
```

## Estensioni future possibili
- `data-requires-page="profile location"` (gating per page key)
- `data-requires-not-capability`
- gestione auto-fallback tab/section quando un elemento attivo viene nascosto
- helper Twig/macros per generare attributi `data-requires-*` in modo consistente
