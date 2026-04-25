# Guida Creazione Moduli

Ultimo aggiornamento: 2026-04-25

## Scopo
Creare un modulo Logeon **Classe B (Optional Third-party)** che:
1. vive esclusivamente nella propria cartella `modules/<vendor.modulo>/`;
2. non modifica mai `/app/` né `/core/`;
3. si installa, disattiva e disinstalla senza lasciare codice residuo nel sistema.

> **Nota — Classe A (Bundled Standard)**: i moduli estratti dal core (archetypes, attributes, factions, multi-currency, novelty, quests, social-status, weather) seguono regole diverse. Non supportano uninstall/purge e dichiarano `"class": "bundled"` nel manifest. Vedi `docs/adr/ADR-008-moduli-bundled-standard.md` e `docs/guida-sistema-moduli.md` (sezione *Tassonomia moduli*).

## Prerequisiti
1. leggere `docs/guida-sistema-moduli.md`;
2. allinearsi a `docs/logeon-module-governance-system.md`.

---

## Regola fondamentale

**Tutto il codice del modulo vive in `modules/<vendor.modulo>/`.**

Controller, service, model, rotte, asset, migrazioni, template — tutto nella cartella del modulo.
Nulla va creato in `/app/` o `/core/`.

Se un modulo viene disinstallato e la sua cartella viene eliminata, non deve restare
nessun file né riga di codice del modulo altrove nel progetto.

---

## Struttura completa

```
modules/<vendor.modulo>/
├── module.json
├── bootstrap.php
├── routes.php
├── src/
│   ├── Controllers/
│   ├── Services/
│   ├── Models/
│   └── Provider/
├── migrations/
│   ├── install.sql
│   └── uninstall.sql
├── assets/
│   ├── js/
│   └── css/
├── views/
└── docs/
    └── README.md
```

---

## `module.json` minimo

```json
{
  "id": "vendor.nome-modulo",
  "name": "Nome Modulo",
  "version": "1.0.0",
  "vendor": "vendor",
  "description": "Descrizione breve del modulo.",
  "dependencies": [],
  "compat": {
    "min": "0.8.0",
    "max": ""
  }
}
```

Campi obbligatori: `id`, `name`, `version`, `vendor`, `compat`.

Il campo `class` è omesso nei moduli Classe B (default `optional`). I moduli Classe A dichiarano `"class": "bundled"` e non vengono creati tramite questa guida.

---

## `bootstrap.php` — autoloader e hook

Il bootstrap fa due cose: registra l'autoloader PSR-4 del modulo e aggancia i propri
handler agli hook del core.

```php
<?php
// modules/<vendor.modulo>/bootstrap.php

// 1. Autoloader PSR-4 per le classi del modulo
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

// 2. Registrazione handler sugli hook del core
\Core\Hooks::addFilter('nome.hook.core', function ($default) {
    return new \Modules\Vendor\NomeModulo\Provider\MioProvider();
});
```

Il namespace radice segue la convenzione `Modules\<Vendor>\<NomeModulo>\`.
Il namespace va in PascalCase anche se l'id del modulo usa kebab-case o dot-notation.

---

## `routes.php` — rotte del modulo

Le rotte del modulo vengono caricate da `ModuleRuntime` solo quando il modulo è attivo.
Usare i metodi del Router core esattamente come in `app/routes/`.

```php
<?php
// modules/<vendor.modulo>/routes.php

use Core\Router;

Router::post('/mio-modulo/endpoint', [
    \Modules\Vendor\NomeModulo\Controllers\MioController::class,
    'mioMetodo',
]);
```

Le rotte del modulo non vanno aggiunte a `app/routes/api.php` né a `app/routes/game.php`.

---

## Comunicazione con il core: solo hook

Il core non importa mai classi del modulo. Il canale di comunicazione è `Core\Hooks`.

Il core emette un hook e usa il risultato come dato generico.
Il modulo registra un handler in `bootstrap.php` e restituisce la propria implementazione.

```php
// Come il core chiama il modulo (codice core esistente, da non modificare):
$provider = \Core\Hooks::filter('nome.hook', null);
if ($provider !== null) {
    $data = $provider->getData();
}

// Come il modulo risponde (in bootstrap.php del modulo):
\Core\Hooks::addFilter('nome.hook', function ($default) {
    return new \Modules\Vendor\NomeModulo\Provider\MioProvider();
});
```

**Non aggiungere mai interfacce a `app/Contracts/`** per far funzionare un modulo.
Se il core ha bisogno di un nuovo punto di estensione, il modo corretto è aggiungere
un hook al core (modifica al core con PR dedicata), non aggiungere un'interfaccia da
implementare fuori dal core.

---

## Migrazioni

### `install.sql`
Eseguito all'attivazione del modulo. Deve essere idempotente.

```sql
-- Usa sempre IF NOT EXISTS
CREATE TABLE IF NOT EXISTS `modulo_tabella` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per colonne aggiunte a tabelle esistenti:
ALTER TABLE `tabella_core`
    ADD COLUMN IF NOT EXISTS `campo_modulo` TINYINT(1) NOT NULL DEFAULT 0;
```

### `uninstall.sql`
Eseguito con `purge=1` alla disinstallazione. Deve rimuovere tutto ciò che `install.sql` ha creato.

```sql
-- Rimuovi in ordine inverso rispetto all'install
ALTER TABLE `tabella_core`
    DROP COLUMN IF EXISTS `campo_modulo`;

DROP TABLE IF EXISTS `modulo_tabella`;
```

L'`uninstall.sql` deve portare il DB esattamente allo stato precedente all'installazione.

---

## Controller del modulo

I controller vivono in `src/Controllers/` e seguono la stessa struttura dei controller
in `app/controllers/`, ma con namespace del modulo.

```php
<?php
// modules/<vendor.modulo>/src/Controllers/MioController.php

namespace Modules\Vendor\NomeModulo\Controllers;

use Core\Http\AppError;
use Core\Http\ErrorResponder;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;

class MioController
{
    public function mioMetodo(RequestData $request): array
    {
        $data = $request->input();
        // logica...
        return ResponseEmitter::json(['ok' => true]);
    }
}
```

---

## Service del modulo

I service del modulo seguono lo stesso pattern dei service in `app/Services/`,
ma vivono in `src/Services/` con namespace del modulo.

Possono usare il DB adapter passato dalla rotta o dal controller, come nel resto del progetto.

---

## UI modulo

1. Inserire menu tramite `menus` nel manifest (slot supportati dal core).
2. Non modificare template core.
3. I template Twig del modulo stanno in `views/` dentro la cartella del modulo.
4. I file JS/CSS del modulo stanno in `assets/`.
5. Riutilizzare componenti UI esistenti (Datagrid, modali, SelectionGroup, Paginator) dove possibile.
6. I nuovi file JS del modulo usano ESM (`import`/`export`).

### Menu admin — `menus.admin.aside`

Il campo `section` determina il gruppo visuale nella sidebar admin.

**Sezioni note (merge automatico)** — la voce viene aggiunta in coda al gruppo già
presente nell'interfaccia. Usare il nome esatto, rispettando maiuscole e spazi:

| Nome sezione | Contesto tipico |
|---|---|
| `Utenti e personaggi` | Gestione utenti, personaggi |
| `Richieste e segnalazioni` | Moderazione |
| `Oggetti` | Inventario e oggetti |
| `Parametri ed entita` | Attributi, archetipi, stati sociali |
| `Commercio` | Negozi, valute, inventari |
| `Mondo e navigazione` | Mappe, luoghi |
| `Narrativa` | Quest, eventi, stati narrativi |
| `Economia` | Lavori, livelli |
| `Gruppi e fazioni` | Gilde, fazioni |
| `Comunicazione` | Forum, news |
| `Documentazione` | Ambientazione, regolamento |
| `Logs` | Tutti i log operativi |

**Nuova sezione standalone** — usare un nome diverso da tutti quelli sopra. Il gruppo
comparirà in fondo alla sidebar, separato dalle sezioni core.

Il campo `page` identifica la pagina admin raggiungibile tramite `/admin/<page>`.
Deve essere **univoco** e non coincidere con nessuna pagina già gestita dal core.
Se coincide, la pagina del modulo non viene mai mostrata (il core ha la priorità).
Per evitare collisioni, prefissare il valore col nome del modulo: es. `weather-overview`,
`social-status`, `archetypes`. Vedi il registro delle pagine riservate in
`docs/guida-sistema-moduli.md` (sezione *Pagine admin riservate*).

---

## Documentazione del modulo

1. Ogni modulo ha una guida in `docs/README.md` (scopo, API, setup, limiti noti).
2. Le API funzionali del modulo non vanno nel contratto API core.
3. Nel contratto API core restano solo gli endpoint di gestione moduli (`/admin/modules/*`).

---

## Test minimi prima del rilascio

1. Modulo rilevato in `/admin/modules` come `detected`.
2. Attivazione riuscita: dipendenze OK, compatibilita OK, `install.sql` applicato.
3. Feature del modulo operative.
4. Disattivazione senza regressioni sul core.
5. Riattivazione dopo disattivazione: feature tornano operative.
6. Uninstall con `purge=0`: stato rimosso, dati intatti, cartella ancora presente.
7. Uninstall con `purge=1`: DB riportato allo stato pre-installazione.
8. Dopo uninstall purge + eliminazione cartella: zero file e zero codice del modulo nel progetto.

I punti 6-8 si applicano esclusivamente ai moduli **Classe B (optional)**. I moduli Classe A non supportano uninstall/purge.

---

## Packaging

1. Usare lo script di packaging del repository (`scripts/modules/build-zips.ps1`).
2. Non versionare i pacchetti zip generati in `dist/`.

---

## Anti-pattern da evitare

1. **Creare file in `/app/` o `/core/`** per far funzionare il modulo — viola l'isolamento,
   lascia dead code alla disinstallazione.
2. **Aggiungere interfacce a `app/Contracts/`** — il modulo non deve richiedere modifiche al core;
   usare gli hook esistenti o richiedere un nuovo hook al core tramite PR dedicata.
3. **Aggiungere rotte del modulo a `app/routes/api.php`** — le rotte del modulo vanno in `routes.php`
   nella cartella del modulo.
4. **Hardcode dell'id modulo nel core** — il core non deve sapere che un modulo specifico esiste.
5. **Migrazioni distruttive senza `uninstall.sql`** — ogni `ALTER TABLE` o `CREATE TABLE` di un modulo Classe B deve avere il corrispondente rollback in `uninstall.sql`. I moduli Classe A non devono avere un `uninstall.sql` che tocca tabelle core.
6. **Dipendenze circolari tra moduli** — modulo A non deve dipendere da modulo B se B dipende da A.
7. **UI invasiva** — non modificare template core; usare gli slot menu e i propri template.
8. **Nome `section` con variante grafica di una sezione esistente** — es. `"Gruppi E Fazioni"` invece
   di `"Gruppi e fazioni"`. Il match è case-sensitive: la voce finirebbe in una sezione standalone
   duplicata invece di unirsi a quella hardcoded. Usare esattamente i nomi della tabella sopra.
9. **Valore `page` che coincide con una pagina core riservata** — es. `"guilds"`, `"users"`, `"items"`.
   Il template `dashboard.twig` gestisce quelle pagine con branch `{% elseif %}` dedicati; la pagina
   del modulo non verrebbe mai mostrata. Consultare il registro in `docs/guida-sistema-moduli.md`.
