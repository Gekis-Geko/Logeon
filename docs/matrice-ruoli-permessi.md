# Matrice Ruoli e Permessi

Ultimo aggiornamento: 2026-04-03

## Scopo
Definire in modo chiaro la gerarchia ruoli e le regole operative principali.

## Gerarchia ruoli
1. `Superuser` (utente installatore/owner tecnico).
2. `Admin`.
3. `Moderatore`.
4. `Master`.
5. `Utente`.

Relazioni:
1. `Admin` include i permessi di `Moderatore` e `Master`.
2. `Moderatore` include i permessi di `Master`.
3. `Master` ha solo i permessi del suo livello.

## Regole globali attuali
1. `/admin` e area riservata al ruolo admin.
2. Strumenti staff in `/game` possono essere visibili a `admin|moderatore|master` in base alla pagina.
3. I permessi reali vengono sempre controllati lato backend.

## Regole sensibili utenti
1. La modifica permessi utenti e riservata a livello alto (con vincoli sul superuser).
2. Un admin non puo auto-rimuoversi privilegi in modo distruttivo.
3. Gli account admin non vengono gestiti come utenti standard senza controlli aggiuntivi.

## Privacy dati
1. Visibilita email limitata:
   - superuser: visione completa.
   - altri ruoli staff: visione ridotta in base alle policy.
2. Le ricerche in UI devono rispettare la stessa policy.

## Note operative
1. In caso di conflitto, prevale sempre la policy backend sul comportamento UI.
2. Le regole di questa matrice vanno mantenute allineate con:
   - `docs/guida-permessi-ui-attributi.md`
   - `docs/contratti-api-backend.md`
