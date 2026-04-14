# Guida Autenticazione e Sessioni

Ultimo aggiornamento: 2026-04-03

## Scopo
Spiegare i flussi di accesso account e la gestione sessione utente/personaggio.

## Flussi di accesso supportati
1. Login standard (email + password).
2. Login/registrazione Google OAuth (se abilitato).
3. Selezione personaggio in ingresso (se multi-personaggio attivo).

## Endpoint principali
1. `POST /signin`
2. `POST /signin/characters/list`
3. `POST /signin/character/select`
4. `POST /signout`
5. `GET /auth/google/start`
6. `GET /auth/google/callback`

## Configurazioni runtime (admin settings)
1. Google OAuth:
   - `auth_google_enabled`
   - `auth_google_client_id`
   - `auth_google_client_secret`
   - `auth_google_redirect_uri`
2. Multi-personaggio:
   - `multi_character_enabled`
   - `multi_character_max_per_user` (1-10)

## Comportamento login
1. `POST /signin` con successo diretto:
   - ritorna `user` + `character`.
2. Nessun personaggio associato:
   - ritorna `error_character`.
3. Multi-personaggio attivo con piu personaggi:
   - ritorna `error_character_select`.
   - il frontend apre la modale di selezione.
   - conferma via `POST /signin/character/select`.

## Sessione
1. La sessione utente viene creata dopo login account.
2. La sessione personaggio viene finalizzata solo dopo selezione personaggio (quando richiesta).
3. Alla disconnessione (`/signout`) vengono aggiornati i campi di ultimo accesso/uscita.

## Note sicurezza
1. I permessi sono sempre verificati lato backend.
2. Il frontend gestisce solo UX e navigazione.
3. Le operazioni API richiedono token CSRF valido.
