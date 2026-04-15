# Contributing to Logeon

Grazie per il contributo.

Questo file definisce le regole pratiche per contribuire senza introdurre regressioni su gameplay e core.

## 1. Prima di iniziare
1. Leggi `README.md`.
2. Leggi `docs/README.md`.
3. Leggi `docs/guida-contributori.md`.
4. Se tocchi frontend, leggi `docs/guida-architettura-frontend.md`.
5. Se tocchi core PHP/DB, leggi `docs/guida-runtime-db-schema.md`.

## 2. Setup locale
1. `composer install`
2. configura `configs/config.php`, `configs/db.php`, `configs/app.php`
3. completa installazione DB (`/install` oppure import manuale)

## 3. Regole di sviluppo (obbligatorie)
1. Niente JS inline nelle view Twig.
2. Niente CSS inline nelle view Twig.
3. Permessi sempre validati lato server (`Core\AuthGuard`).
4. Nuovi endpoint JSON con:
   - `Core\Http\RequestData`
   - `Core\Http\ResponseEmitter`
5. Evitare nuovi pattern pre-Origin0:
   - `echo json_encode(...)` nei controller
   - `die(...)` in flow applicativi
   - parsing diretto di `$_POST['data']`

## 4. Dove contribuire in sicurezza
Aree consigliate:
1. `app/controllers/*`
2. `app/services/*`
3. `app/views/*`
4. `assets/js/app/*`
5. `assets/js/components/*`
6. `assets/js/services/*`
7. `custom/routes.php`
8. `custom/bootstrap.php`

File ad alto rischio (solo con refactor dedicato):
1. `core/Models.php`
2. `core/Router.php`
3. `core/SessionGuard.php`
4. `core/Template.php`
5. `core/Database/MysqliDbAdapter.php`
6. `core/Database/DbAdapterFactory.php`
7. `app/services/AuthService.php`
8. `autoload.php`
9. `app/routes.php`

## 5. Workflow consigliato per feature/fix
1. Definisci il comportamento atteso e i permessi.
2. Implementa backend (service/controller/route).
3. Implementa frontend (feature/module/component).
4. Se la feature e una nuova pagina admin, registrala in tutti e tre i punti obbligatori:
   - `MODULE_FACTORY_MAP` in `assets/js/app/core/admin.registry.js`
   - `getPageModules()` in `assets/js/app/core/admin.registry.js` (fonte autoritativa)
   - `modules` map in `assets/js/app/core/admin.runtime.js`
5. Aggiorna documentazione se introduci nuove convenzioni.
6. Esegui verifiche minime.
7. Apri PR con descrizione tecnica chiara.

## 6. Verifiche minime prima di PR
1. `php -l` su ogni file PHP toccato
2. `node --check` sui file JS toccati
3. se tocchi core DB/Auth/Session/Models, esegui:
   - `C:\xampp\php\php.exe scripts/php/smoke-core-runtime.php`
4. in alternativa (debug mirato), esegui i check separati:
   - `C:\xampp\php\php.exe scripts/php/smoke-core-db-runtime.php`
   - `C:\xampp\php\php.exe scripts/php/smoke-core-auth-runtime.php`
5. smoke manuale dei flussi impattati
6. nessun fatal PHP o loop anomalo di richieste in browser

## 7. Cosa includere nella Pull Request
1. contesto del problema
2. soluzione implementata
3. file principali modificati
4. impatti su permessi/sicurezza
5. test eseguiti
6. eventuali rischi residui

## 8. PHPStan e il baseline

Logeon usa PHPStan (level 4) per l'analisi statica di `app/Services`, `app/Controllers` e `core`.

### Come funziona `phpstan-baseline.neon`

Il file `phpstan-baseline.neon` è una funzionalità nativa di PHPStan, non un workaround custom. Sopprime errori su **righe specifiche di file esistenti**: ogni entry nel baseline è ancorata a un file e a un pattern di messaggio preciso. Il codice che scrivi tu — nuovi file, nuove righe — viene analizzato normalmente. Se introduci lo stesso tipo di errore in un file o riga non listati nel baseline, PHPStan lo segnala e la CI fallisce.

### Perché esiste un baseline così ampio

La maggior parte delle voci riguarda file core ad alto rischio (`core/Template.php`, `core/Logs.php`, `core/RateLimiter.php`, `core/FeatureFlags.php`, `core/Cache.php`…) che per policy del progetto si toccano solo con refactor dedicati e verificati. Il baseline è stato creato per non bloccare il lavoro sulle aree sicure mentre quei file vengono risanati in modo controllato.

### Regola obbligatoria: il baseline può solo ridursi

**Non è accettabile aggiungere nuove voci al baseline in una PR.** Se PHPStan segnala un errore nel codice che stai scrivendo:

1. correggi il codice — è quasi sempre la scelta giusta;
2. se l'errore è un falso positivo documentabile (es. pattern legacy intenzionale in un file ad alto rischio), apri una discussione prima di procedere;
3. non eseguire `phpstan --generate-baseline` per sopprimere il problema.

### Riduzione progressiva del baseline

Ogni PR che tocca uno dei file core elencati sopra deve ridurre le voci del baseline relative a quel file. La riduzione del baseline è backlog tecnico esplicito del progetto, non lavoro opzionale.

## 9. Convenzioni docs
1. Documenti attivi e baseline in docs/.
2. Evitare archivi storici locali: tenere una sola guida aggiornata per argomento.
3. La storia tecnica del progetto e nel repository Git.

## 10. Collegamenti rapidi
1. `README.md`
2. `docs/README.md`
3. `docs/guida-contributori.md`
4. `docs/guida-personalizzazione-gioco.md`
5. `docs/guida-architettura-frontend.md`
6. `docs/guida-sistema-moduli.md`
7. `docs/guida-runtime-db-schema.md`
