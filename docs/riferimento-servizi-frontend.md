# Riferimento Servizi Frontend

Ultimo aggiornamento: 2026-04-27

## Scopo
Documentazione sintetica dei service wrapper client condivisi.

## Dove si trovano
`assets/js/services/*`

## Servizi disponibili
1. `Request.js`
   - wrapper HTTP standard (jQuery.ajax sotto il cofano)
   - normalizza gestione errori e risposta JSON
   - espone due pattern: factory con observer (`Request(url, name, data, ext)`) e API Promise-based moderna (`Request.http`)
2. `Auth.js`
   - flussi auth client (signin, signout, forgot password, reset)
   - supporto flusso multi-personaggio (`/signin/characters/list`, `/signin/character/select`)
   - integra storage sessione frontend
3. `Storage.js`
   - wrapper local/session storage
   - accesso centralizzato alle chiavi runtime
4. `Urls.js`
   - helper URL e path base applicazione

## Request.js — API di riferimento

### API Promise-based (`Request.http`) — preferita per nuovi moduli ESM

```js
// POST verso un endpoint core
Request.http.post('/location/messages/send', { location_id: 12, message: 'ciao' })
    .then(function (payload) {
        // payload e il JSON di risposta normalizzato
    })
    .catch(function (err) {
        var msg  = Request.getErrorMessage(err, 'Errore generico');
        var code = Request.getErrorCode(err); // es. 'location_chat_rate_limited'
        if (Request.hasErrorCode(err, 'session_expired')) { /* redirect login */ }
    });

// GET con query string
Request.http.get('/list/locations', { map_id: 3 })
    .then(function (payload) { /* payload.locations */ });
```

Helper statici su `window.Request`:
- `Request.getErrorMessage(err, fallback)` — stringa leggibile dall'errore.
- `Request.getErrorCode(err, fallback)` — `error_code` machine-readable.
- `Request.getErrorInfo(err)` — `{ message, errorCode, raw }`.
- `Request.hasErrorCode(err, code)` — `true` se il codice corrisponde (accetta stringa o array).

### API factory con pattern observer — usata nei moduli esistenti

```js
Request('/guilds/get', 'loadGuild', { guild_id: 5 }, {
    onLoadGuildSuccess: function (payload) {
        // payload.guild, payload.members, ...
    },
    onLoadGuildError: function (message, info) {
        // info.errorCode — es. 'guild_not_found'
        // info.message   — stringa leggibile
    }
});
```

Il `callbackName` (qui `'loadGuild'`) risolve automaticamente i metodi
`on{Name}Success` e `on{Name}Error` sull'oggetto extension passato come quarto argomento.
Se `onError` non e definito, la libreria mostra il dialog di errore standard.

## Pattern consigliato
1. Le feature pagina chiamano i moduli (`assets/js/app/modules/*`).
2. I moduli usano i service wrapper.
3. Non chiamare endpoint direttamente nelle view Twig.

## Regole pratiche
1. Mantieni i servizi idempotenti e senza dipendenza da DOM.
2. Non inserire business logic di pagina nei servizi globali.
3. Se estendi il contratto di `Request`, verifica tutte le feature principali.
4. Se tocchi `Auth`, esegui smoke auth/session.

## Smoke rapido consigliato
1. Login/logout
2. Login con multi-personaggio attivo (apertura modale selezione e redirect `/game`)
3. una pagina `/game` con chiamata API
4. una pagina `/admin` con datagrid
