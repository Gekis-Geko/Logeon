# Guida: Build pacchetti di release

Ultimo aggiornamento: 2026-04-26

## Cosa fa lo script

`scripts/release/build-core-zip.ps1` genera uno o due archivi `.zip` pronti per il deploy di Logeon, escludendo automaticamente file sensibili, cache, upload e dipendenze di sviluppo.

Gli archivi vengono scritti in `dist/release/`.

---

## Pacchetti generati

| Nome archivio | Contiene `vendor/` | Scopo |
|---|---|---|
| `logeon-core-ready.zip` | Si | Deploy su server senza Composer |
| `logeon-core-source-dev.zip` | No | Distribuzione sorgente; il destinatario esegue `composer install` |

---

## Comandi

Eseguire dalla root del progetto in PowerShell o dal terminale di VS Code.

```powershell
# Entrambi i pacchetti (default)
powershell -ExecutionPolicy Bypass -File scripts/release/build-core-zip.ps1

# Solo pacchetto "ready" (richiede che vendor/ esista)
powershell -ExecutionPolicy Bypass -File scripts/release/build-core-zip.ps1 -Variant ready

# Solo pacchetto "source-dev" (senza vendor)
powershell -ExecutionPolicy Bypass -File scripts/release/build-core-zip.ps1 -Variant source-dev

# Alias compatibile (equivale a source-dev)
powershell -ExecutionPolicy Bypass -File scripts/release/build-core-zip.ps1 -Variant source
```

### Parametri opzionali

| Parametro | Default | Descrizione |
|---|---|---|
| `-Variant` | `all` | `all` / `ready` / `source-dev` / `source` (alias) |
| `-OutputRoot` | `dist/release` | Cartella di destinazione degli zip |
| `-StagingRoot` | `dist/release/staging` | Cartella di staging temporanea |
| `-ReadyIncludeJsSource` | `false` | Rollback rapido: reinclude i sorgenti JS runtime nel pacchetto `ready` |
| `-SkipReadySmokeChecks` | `false` | Salta i controlli automatici dist-only JS sul pacchetto `ready` |

---

## Prerequisiti

- **Per `-Variant ready` o `all`**: la cartella `vendor/` deve esistere. Eseguire prima `composer install --no-dev` se non presente.
- **Per il frontend**: eseguire prima `npm run build:frontend:release` cosi i bundle in `assets/js/dist/` sono aggiornati. Lo script li include nell'archivio.

---

## File e cartelle esclusi

Lo script esclude sempre:

| Tipo | Percorsi |
|---|---|
| File di configurazione sensibili | `configs/db.php`, `configs/installed.php` |
| Variabili d'ambiente | `.env`, `.env.*` |
| Dati di sviluppo/VCS | `.git/`, `.claude/`, `.pr/` |
| Dipendenze Node | `node_modules/` |
| Output di build | `dist/` |
| Cache e upload | `tmp/cache/`, `tmp/twig-cache/`, `tmp/uploads/`, `tmp/uploader/`, `tmp/build-meta/`, `assets/imgs/uploads/` |
| Log | `logs/`, file `*.log` |
| Moduli opzionali | `modules/` (la cartella vuota viene ricreata con un `.gitkeep`) |

Regole specifiche per variante:

1. `ready`:
   - include `vendor/`;
   - esclude `assets/sass/`;
   - esclude `scripts/`;
   - esclude i sorgenti JS runtime (`assets/js/app/`, `assets/js/components/`, `assets/js/services/`);
   - mantiene il runtime tramite bundle `assets/js/dist/*.bundle.js`;
   - esegue smoke automatico dist-only JS su staging prima dello zip finale;
   - esclude file di repo come `.gitignore`, `.gitlab-ci.yml`, `.gitattributes`, `.editorconfig`.
2. `source-dev`:
   - esclude `vendor/`;
   - include `scripts/`;
   - include `assets/sass/`;
   - include le guide operative in `docs/` utili a setup, debug e contributi;
   - include i file di repo utili ai contributori (es. `.gitignore`).

## Esempi pratici

### Esempio 1: pacchetto per pubblicare subito il gioco

Scenario: vuoi caricare Logeon su un hosting PHP gia pronto e non vuoi eseguire `composer install` sul server.

Comando:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/release/build-core-zip.ps1 -Variant ready
```

Risultato:
1. ottieni `dist/release/logeon-core-ready.zip`;
2. il pacchetto include `vendor/`;
3. il runtime frontend usa direttamente i bundle in `assets/js/dist/`.

### Esempio 2: pacchetto per un collaboratore che deve fare debug

Scenario: devi passare il progetto a un collaboratore che deve eseguire smoke test, leggere le guide e rigenerare asset.

Comando:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/release/build-core-zip.ps1 -Variant source-dev
```

Risultato:
1. ottieni `dist/release/logeon-core-source-dev.zip`;
2. il pacchetto include `scripts/` per smoke e tooling CLI;
3. il pacchetto include i sorgenti Sass e le guide operative in `docs/`;
4. il destinatario esegue `composer install` dopo l'estrazione.

---

## Output atteso

```
== Build logeon-core-ready ==
Pacchetto creato: dist/release/logeon-core-ready.zip
Staging: dist/release/staging/logeon-core-ready
File copiati: XXXX

== Build logeon-core-source-dev ==
Pacchetto creato: dist/release/logeon-core-source-dev.zip
Staging: dist/release/staging/logeon-core-source-dev
File copiati: XXXX
```

---

## Workflow consigliato prima di una release

```powershell
# 1. Build frontend ottimizzata per produzione
npm run build:frontend:release

# 2. Dipendenze PHP senza pacchetti di sviluppo
composer install --no-dev --optimize-autoloader

# 3. Genera i pacchetti
powershell -ExecutionPolicy Bypass -File scripts/release/build-core-zip.ps1
```

Gli zip risultanti sono in `dist/release/` e possono essere caricati direttamente su Altervista o su qualsiasi hosting PHP 8.1+.

Nota runtime JS in `ready`:
1. il pacchetto pronto uso usa bundle dist-only (`assets/js/dist/`);
2. entrypoint principali inclusi: `runtime-core.bundle.js`, `public-core.bundle.js`, `game-core.bundle.js`, `admin-core.bundle.js`.
3. smoke manuale disponibile: `npm run smoke:ready:dist-only-js` (usa staging di default).

## Rollback rapido (solo emergenza)

Se una release `ready` richiede rollback immediato del modello dist-only JS:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/release/build-core-zip.ps1 -Variant ready -ReadyIncludeJsSource
```

Effetto:
1. il pacchetto `ready` reinclude `assets/js/app`, `assets/js/components`, `assets/js/services`;
2. lo smoke dist-only viene saltato automaticamente per questa build.
