# Guida Sistema Moduli

Ultimo aggiornamento: 2026-04-25

## Scopo
Questa guida descrive come Logeon gestisce i moduli:
1. rilevamento su filesystem;
2. stato persistito su database;
3. lifecycle completo (attivazione, disattivazione, disinstallazione);
4. integrazione UI additiva senza toccare il core;
5. isolamento completo del codice modulo dalla cartella `/app/`.

---

## Principio fondamentale: isolamento totale

**Un modulo non tocca mai `/app/` né `/core/`.**

Il codice del modulo (controller, service, model, rotte, asset, migrazioni) vive esclusivamente
nella propria cartella `modules/<vendor.modulo>/`.

La comunicazione tra core e modulo avviene esclusivamente tramite il sistema hook (`Core\Hooks`).
Il core non importa classi del modulo. Il modulo non modifica file del core.

Questa regola garantisce che disinstallare un modulo significhi:
1. eseguire `uninstall.sql` per ripulire DB;
2. eliminare la cartella `modules/<vendor.modulo>/`.

Zero codice residuo in `/app/` o `/core/`.

---

## Confine Core vs Moduli

1. Il core gestisce solo l'orchestrazione moduli (`/admin/modules/*`) e il sistema hook.
2. Le API funzionali di un modulo vanno documentate nella guida del modulo stesso.
3. `docs/contratti-api-backend.md` include solo i contratti core; le API dei moduli sono escluse.
4. Il core non conosce le classi concrete del modulo. Usa i risultati degli hook senza sapere chi li produce.

---

## Comunicazione core ↔ modulo via hook

Il sistema hook (`Core\Hooks`) è l'unico canale di comunicazione bidirezionale.

Il core emette un hook (filter o action) e lavora con il risultato come dato grezzo.
Il modulo registra un handler sull'hook nel proprio `bootstrap.php`.

Esempio — il core chiede il provider archetipi:
```php
// core (già presente, non va modificato)
$provider = \Core\Hooks::filter('character.archetype.provider', null);
if ($provider !== null) {
    $list = $provider->list();
}
```

Esempio — il modulo risponde:
```php
// modules/logeon.archetypes/bootstrap.php
\Core\Hooks::addFilter('character.archetype.provider', function () {
    return new \Modules\Logeon\Archetypes\Provider\ArchetypesModuleProvider();
});
```

Il core non importa `ArchetypesModuleProvider`. Il modulo non aggiunge nulla a `/app/Contracts/`.

---

## Autoloading classi del modulo

Il modulo registra il proprio autoloader PSR-4 nel `bootstrap.php`.
Non va modificato `composer.json` del core.

Schema standard:
```php
// modules/<vendor.modulo>/bootstrap.php
spl_autoload_register(function (string $class): void {
    $prefix = 'Modules\\Vendor\\NomeModulo\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
    $file = __DIR__ . '/src/' . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
```

Il namespace radice consigliato segue la convenzione `Modules\<Vendor>\<NomeModulo>\`.

---

## Struttura completa modulo

```
modules/<vendor.modulo>/
├── module.json            ← manifest obbligatorio
├── bootstrap.php          ← autoloader + registrazione hook
├── routes.php             ← definizione rotte modulo (caricate solo se attivo)
├── src/
│   ├── Controllers/       ← controller HTTP del modulo
│   ├── Services/          ← logica di business del modulo
│   ├── Models/            ← modelli dati (se presenti)
│   └── Provider/          ← implementazioni provider per gli hook core
├── migrations/
│   ├── install.sql        ← schema aggiunto all'attivazione
│   └── uninstall.sql      ← schema rimosso alla disinstallazione (purge)
├── assets/                ← JS/CSS del modulo (opzionale)
├── views/                 ← template Twig del modulo (opzionale)
└── docs/
    └── README.md          ← documentazione del modulo
```

Tutto il codice PHP del modulo sta in `src/`. Nulla va in `/app/`.

---

## Manifest `module.json`

Campi principali:
1. `id`, `name`, `version`, `vendor`
2. `description`
3. `class` — tassonomia modulo: `"bundled"` (Classe A) oppure omesso / `"optional"` (Classe B, default). Vedi sezione *Tassonomia moduli*.
4. `dependencies` (dipendenze richieste/opzionali)
5. `compat` — range versione core: `{"min": "0.8.0", "max": ""}`
6. `menus` — iniezione menu UI negli slot core

---

## Tassonomia moduli

### Classe A — Bundled Standard

Moduli distribuiti con Logeon, estratti dal core. Hanno colonne FK nelle tabelle core preesistenti (es. `characters.socialstatus_id`, `characters.faction_id`). Non possono essere rimossi senza `ALTER TABLE` sulle tabelle core.

- **Ciclo di vita supportato**: activate / deactivate.
- **Uninstall e purge**: non supportati. `ModuleManager::uninstall()` restituisce `error_code: module_bundled_no_purge`.
- **Identificazione**: `"class": "bundled"` in `module.json`.
- **Moduli Classe A correnti**: `logeon.archetypes`, `logeon.attributes`, `logeon.factions`, `logeon.multi-currency`, `logeon.novelty`, `logeon.quests`, `logeon.social-status`, `logeon.weather`.

### Classe B — Optional Third-party

Moduli aggiuntivi con schema completamente additivo: nessuna colonna nelle tabelle core, solo tabelle proprie.

- **Ciclo di vita supportato**: install / activate / deactivate / uninstall / purge.
- **Identificazione**: nessun campo `class` in `module.json` (default `optional`).

La guida `docs/guida-creazione-moduli.md` e valida per i moduli Classe B. Per i moduli Classe A vedi `docs/adr/ADR-008-moduli-bundled-standard.md`.

---

## Stati modulo

| Stato | Significato |
|---|---|
| `detected` | Presente su filesystem, non installato |
| `installed` | Installato, non attivo |
| `active` | Attivo e caricato a runtime |
| `inactive` | Installato ma disattivato |
| `error` | Errore rilevato da runtime o audit |

La transizione da `detected` a `installed` avviene alla prima attivazione.
La disattivazione porta da `active` a `inactive` senza perdita dati.
La disinstallazione rimuove lo stato DB; con `purge=1` esegue anche `uninstall.sql`.

---

## Runtime

1. Il core monta sempre le proprie rotte.
2. `ModuleRuntime` carica `bootstrap.php` e `routes.php` solo per i moduli con stato `active`.
3. Lo stato modulo è sempre verificato e persistito su DB, non solo dai file presenti.
4. Se un modulo è `active` ma i file sono stati rimossi, `audit` lo segnala come `error`.

---

## API admin moduli (core)

Permesso richiesto: `settings.manage`.

### `POST /admin/modules/list`
Uso: elenco moduli con stato, versione, compatibilita, dipendenze.

### `POST /admin/modules/activate`
Request: `module_id`

Effetti:
1. valida compatibilita core (`compat.min`/`compat.max`);
2. verifica dipendenze soddisfatte;
3. applica `install.sql` (idempotente);
4. imposta stato `active`.

### `POST /admin/modules/deactivate`
Request: `module_id`, `cascade` (`0|1`, opzionale)

Effetti:
1. imposta stato `inactive`;
2. con `cascade=1` disattiva anche i moduli dipendenti attivi.

### `POST /admin/modules/uninstall`
Request: `module_id`, `purge` (`0|1`, opzionale)

Precondizioni: modulo deve essere `inactive`.

**Nota**: i moduli Classe A (bundled) non supportano questa operazione. La chiamata restituisce `error_code: module_bundled_no_purge`. Per i moduli Classe A usare solo `deactivate`.

Effetti (solo moduli Classe B — optional):
1. rimuove metadati runtime e stato DB;
2. con `purge=0`: mantiene eventuali dati applicativi;
3. con `purge=1`: esegue `uninstall.sql` (rimozione tabelle, colonne, dati);
4. l'eliminazione della cartella `modules/<vendor.modulo>/` è manuale dopo l'uninstall.

### `POST /admin/modules/audit`
Uso: verifica coerenza runtime (stati inconsistenti, file mancanti, orfani).

---

## Error code modulo (core)

1. `module_not_found`
2. `module_not_installed`
3. `module_dependency_missing`
4. `module_incompatible_core`
5. `module_activation_failed`
6. `module_deactivation_failed`
7. `module_deactivation_requires_confirmation`
8. `module_uninstall_requires_inactive`
9. `module_bundled_no_purge` — tentata disinstallazione di un modulo Classe A (bundled)
10. `module_uninstall_failed`
11. `module_audit_failed`

---

## Iniezione menu e asset

Twig helpers disponibili nel core:
1. `module_assets(channel)`
2. `module_active(moduleId)`
3. `module_menu_entries(channel, slot, context)`
4. `module_menu_sections(channel, slot, context)`

Slot attualmente supportati:
1. `game.profile_dropdown`
2. `game.profile_offcanvas`
3. `admin.aside`

### Sezioni sidebar admin

Quando un modulo registra una voce in `menus.admin.aside`, il campo `section` determina
il gruppo visuale nella sidebar (`app/views/admin/layouts/aside.twig`).

Il template distingue due comportamenti in base al nome della sezione:

- **Sezione nota** — la voce viene iniettata in coda al gruppo hardcoded corrispondente.
  Il nome deve corrispondere esattamente (case-sensitive) a uno di questi valori:
  `Utenti e personaggi`, `Richieste e segnalazioni`, `Oggetti`, `Parametri ed entita`,
  `Commercio`, `Mondo e navigazione`, `Narrativa`, `Economia`, `Gruppi e fazioni`,
  `Comunicazione`, `Documentazione`, `Logs`.

- **Sezione standalone** — qualsiasi altro nome crea un nuovo gruppo in fondo alla sidebar,
  separato dalle sezioni core. Usare questa modalità solo per sezioni concettualmente distinte
  (es. `Meteo`).

Se si aggiunge una nuova sezione hardcoded ad `aside.twig`, il suo nome deve essere
aggiunto alla lista `_known_sec_keys` nel template e a questa guida.

### Pagine admin riservate

Il campo `page` in `menus.admin.aside` e il nome dello slot `twig.slot.admin.dashboard.<page>`
determinano quale contenuto viene mostrato nell'area principale quando si naviga su `/admin/<page>`.

Il template `app/views/admin/dashboard.twig` gestisce le pagine seguenti con branch `{% elseif %}`
dedicati. Un modulo non deve usare nessuno di questi valori come `page`:

```
dashboard, users, characters, blacklist,
maps, currencies, shops, conflicts,
narrative-events, narrative-states, system-events, character-lifecycle,
character-requests, locations, inventory-shop,
jobs, jobs-tasks, jobs-levels,
guilds, guild-alignments, guilds-reqs, guilds-locations, guild-locations, guilds-events,
forums, forums-types,
storyboards, rules, how-to-play,
items, items-categories, items-rarities, equipment-slots, item-equipment-rules,
settings,
narrative-tags, narrative-delegation, narrative-delegation-grants, narrative-npcs,
message-reports,
logs-conflicts, logs-currency, logs-experience, logs-fame,
logs-guild, logs-job, logs-location-access, logs-sys, logs-narrative,
themes, modules
```

Tutti gli altri valori di `page` vanno nel ramo `{% else %}` del template, che risolve
il contenuto tramite lo slot hook `twig.slot.admin.dashboard.<page>` oppure tramite
`module_slot('admin.dashboard.<page>')`. È qui che i moduli devono registrare i propri
slot per mostrare contenuti nell'area principale.

Convenzione consigliata: prefissare il `page` col nome del modulo per garantire unicità
(es. `weather-overview`, `social-status`, `archetypes`).

> **Nota tecnica — rendering dello slot**: il contenuto restituito dagli slot
> (`slot()` e `module_slot()`) viene assegnato a una variabile Twig prima di essere
> stampato. Le variabili Twig perdono il flag `is_safe`, quindi l'output verrebbe
> auto-escaped se non si usa il filtro `|raw`. Il template core gestisce già questo
> nel ramo `{% else %}` di `dashboard.twig`. Se si crea un template custom che usa
> `{% set x = slot(...) %}` seguito da `{{ x }}`, aggiungere sempre `{{ x|raw }}`.

---

## Flusso operativo

1. Copia la cartella modulo in `modules/<vendor.modulo>/`.
2. Verifica in `/admin/modules` che compaia come `detected`.
3. Attiva il modulo; il sistema valida compatibilita e dipendenze, poi esegue `install.sql`.
4. Verifica menu, rotte e feature del modulo.
5. Per disattivare: `/admin/modules/deactivate` — i dati restano, il modulo smette di caricarsi.
6. Per disinstallare (solo moduli Classe B — optional): il modulo deve essere `inactive`; esegui `/admin/modules/uninstall?purge=1` per pulizia completa DB, poi elimina la cartella.
7. I moduli Classe A (bundled) non supportano il passo 6: usare solo `deactivate`.

---

## Checklist pre-rilascio modulo

1. Nessun file PHP del modulo si trova in `/app/` o `/core/`.
2. Autoloader registrato in `bootstrap.php`.
3. Tutte le rotte definite in `routes.php` del modulo.
4. `install.sql` idempotente (usa `CREATE TABLE IF NOT EXISTS`, `ALTER TABLE ... ADD COLUMN IF NOT EXISTS`).
5. `uninstall.sql` ripulisce tutto il DB aggiunto dal modulo (**Classe B — optional only**; i moduli Classe A non hanno `uninstall.sql` significativo).
6. Comunicazione col core solo via `Core\Hooks`.
7. Nessun file aggiunto a `/app/Contracts/` o `/core/`.
8. UI additiva: menu via slot, nessuna modifica a template core.
9. Test attivazione → feature operative → disattivazione senza regressioni core → reinstallazione.
10. Per moduli Classe B: test uninstall purge + verifica DB pulito.
11. Documentazione modulo in `docs/README.md` separata dai doc core.

