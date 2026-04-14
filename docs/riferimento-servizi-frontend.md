# Riferimento Servizi Frontend

Ultimo aggiornamento: 2026-04-02

## Scopo
Documentazione sintetica dei service wrapper client condivisi.

## Dove si trovano
`assets/js/services/*`

## Servizi disponibili
1. `Request.js`
   - wrapper HTTP standard (AJAX/fetch compatibile con runtime attuale)
   - normalizza gestione errori e risposta JSON
2. `Auth.js`
   - flussi auth client (signin, signout, forgot password, reset)
   - supporto flusso multi-personaggio (`/signin/characters/list`, `/signin/character/select`)
   - integra storage sessione frontend
3. `Storage.js`
   - wrapper local/session storage
   - accesso centralizzato alle chiavi runtime
4. `Urls.js`
   - helper URL e path base applicazione

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
