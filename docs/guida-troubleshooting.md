# Guida Troubleshooting

Ultimo aggiornamento: 2026-04-03

## Scopo
Risolvere rapidamente i problemi piu comuni in ambiente locale o produzione.

## Errori comuni e fix

## 1) `csrf_invalid` (403)
Cause tipiche:
1. token CSRF mancante nel payload.
2. sessione scaduta.
3. pagina lasciata aperta troppo tempo.

Fix:
1. ricarica la pagina (`Ctrl+F5`).
2. rifai login.
3. verifica che le chiamate API passino `data._csrf` e header `X-CSRF-Token`.

## 2) `Unknown column ...` in admin/game
Cause tipica:
1. schema DB non allineato al codice.

Fix:
1. verifica che lo schema importato sia `database/logeon_db_core.sql` aggiornato.
2. se necessario reimporta lo schema su DB pulito.
3. ripeti test su endpoint coinvolto.

## 3) errore 500 dopo update
Cause tipiche:
1. schema DB non allineato al file unico.
2. cache browser con JS vecchio.
3. errore sintassi in file aggiornati.

Fix:
1. `php -l` su file PHP toccati.
2. `node --check` su file JS toccati.
3. hard refresh browser.

## 4) Google login non visibile
Cause tipiche:
1. `auth_google_enabled = 0` in `/admin/settings`.
2. client id/secret mancanti.

Fix:
1. attiva switch Google OAuth in admin.
2. configura `auth_google_client_id` e `auth_google_client_secret`.
3. controlla `auth_google_redirect_uri`.

## 5) Login bloccato su selezione personaggio
Cause tipiche:
1. multi-personaggio attivo con piu personaggi associati.

Fix:
1. seleziona il personaggio dalla modale dedicata.
2. se necessario verifica `multi_character_enabled` e `multi_character_max_per_user` in admin settings.

## Comandi diagnostici utili
1. `C:\xampp\php\php.exe -l <file.php>`
2. `node --check <file.js>`
3. `C:\xampp\php\php.exe scripts/php/smoke-core-runtime.php`

## Quando aprire una issue
Apri una issue di progetto quando:
1. il problema e riproducibile dopo hard refresh + re-login.
2. il DB e allineato ma l'errore persiste.
3. c'e regressione su flusso core (`/signin`, `/game`, `/admin`).