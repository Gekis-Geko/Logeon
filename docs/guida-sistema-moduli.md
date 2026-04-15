# Guida Sistema Moduli

Ultimo aggiornamento: 2026-04-15

## Scopo
Questa guida descrive come Logeon gestisce i moduli nel core:
1. rilevamento su filesystem;
2. stato persistito su database;
3. attivazione/disattivazione/disinstallazione da area admin;
4. integrazione UI additiva senza hardcode nel core.

## Confine Core vs Moduli
1. Il core documenta e gestisce solo il sistema di orchestrazione moduli (`/admin/modules/*`).
2. Le API funzionali di un modulo (es. endpoint gameplay specifici) vanno documentate nella guida del modulo stesso.
3. `docs/contratti-api-backend.md` include i contratti core, inclusa la gestione moduli, ma non i contratti di feature opzionali.
4. Il core non importa codice di moduli opzionali. La comunicazione avviene tramite `CustomEvent` DOM neutrali o hook (`Core\Hooks`). Il modulo ascolta gli eventi che il core emette e agisce di conseguenza, senza che il core sappia dell'esistenza del modulo.

## Stati modulo
Stati supportati:
1. `detected` (presente su filesystem, non installato)
2. `installed` (installato, non attivo)
3. `active` (attivo runtime)
4. `inactive` (installato ma disattivato)
5. `error` (errore rilevato da runtime/audit)

## Struttura minima modulo
Percorso consigliato: `modules/<vendor.modulo>/`

File minimi:
1. `module.json`
2. `bootstrap.php` (opzionale)
3. `routes.php` (opzionale)
4. `migrations/*.sql` (opzionale)
5. `assets/` (opzionale)

## Manifest `module.json`
Campi principali:
1. `id`, `name`, `version`, `vendor`
2. `description`
3. `dependencies` (dipendenze richieste/opzionali)
4. `compat` (versione core supportata)
5. `menus` (iniezione menu UI)

## Runtime
1. Il core monta sempre le proprie rotte.
2. `ModuleRuntime` carica bootstrap/rotte/assets solo dei moduli attivi.
3. Lo stato modulo non dipende solo dai file presenti: e sempre verificato/persistito su DB.

## API admin moduli (core)
Permesso richiesto: `settings.manage`.

### `POST /admin/modules/list`
Uso: elenco moduli con stato e metadati.

### `POST /admin/modules/activate`
Request:
1. `module_id`

Effetti:
1. valida dipendenze e compatibilita core;
2. applica migrazioni modulo idempotenti (se presenti);
3. imposta stato `active`.

### `POST /admin/modules/deactivate`
Request:
1. `module_id`
2. `cascade` (`0|1`, opzionale)

Effetti:
1. disattiva il modulo;
2. con `cascade=1` disattiva anche i dipendenti attivi.

### `POST /admin/modules/uninstall`
Request:
1. `module_id`
2. `purge` (`0|1`, opzionale)

Effetti:
1. richiede modulo inattivo;
2. `purge=0` rimuove installazione e metadati runtime mantenendo eventuali dati;
3. `purge=1` esegue anche uninstall SQL (se dichiarato dal modulo).

### `POST /admin/modules/audit`
Uso: controllo coerenza runtime (stati, artifact, orfani).

## Error code modulo (core)
1. `module_not_found`
2. `module_not_installed`
3. `module_dependency_missing`
4. `module_incompatible_core`
5. `module_activation_failed`
6. `module_deactivation_failed`
7. `module_deactivation_requires_confirmation`
8. `module_uninstall_requires_inactive`
9. `module_uninstall_failed`
10. `module_audit_failed`

## Iniezione menu e asset
Twig helpers disponibili nel core:
1. `module_assets(channel)`
2. `module_active(moduleId)`
3. `module_menu_entries(channel, slot, context)`
4. `module_menu_sections(channel, slot, context)`

Slot attualmente usati:
1. `game.profile_dropdown`
2. `game.profile_offcanvas`
3. `admin.aside`

## Flusso operativo consigliato
1. Installa file modulo in `modules/`.
2. Verifica in `/admin/modules` che compaia come `detected`.
3. Attiva il modulo (gestendo eventuali dipendenze).
4. Verifica menu/rotte/asset del modulo.
5. Se necessario, disattiva con o senza cascata.
6. Disinstalla solo quando il modulo e inattivo.

## Checklist rapida
1. Manifest completo e coerente.
2. Migrazioni idempotenti.
3. Nessuna dipendenza circolare.
4. UI del modulo additiva (non invasiva sul core).
5. Documentazione modulo separata da quella core.
6. Test attivo/disattivo/audit prima del rilascio.
