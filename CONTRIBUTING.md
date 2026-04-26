# Contributing to Logeon

Ultimo aggiornamento: 2026-04-26

Grazie per il contributo.
Questo documento definisce il processo ufficiale per sviluppare, revisionare e integrare codice nel repository.

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

## 3. Regole di sviluppo obbligatorie
1. Niente JS inline nelle view Twig.
2. Niente CSS inline nelle view Twig.
3. Permessi sempre validati lato server (`Core\AuthGuard`).
4. Nuovi endpoint JSON con:
   - `Core\Http\RequestData`
   - `Core\Http\ResponseEmitter`
5. Evitare nuovi pattern legacy:
   - `echo json_encode(...)` nei controller
   - `die(...)` in flow applicativi
   - parsing diretto `$_POST['data']`
6. Nessun hardcode di moduli di dominio nel core:
   - non aggiungere riferimenti diretti a moduli specifici in `app/` o `core/`
   - usa slot Twig, hook runtime e registri modulo
   - se un modulo viene estratto, elimina i residui non piu necessari nel core
7. Dipendenze core/moduli unidirezionali:
   - il core espone extension points
   - il modulo si aggancia agli extension points
   - il core non dipende da namespace, route o template del modulo
8. Tassonomia moduli (vedi `docs/guida-sistema-moduli.md`):
   - Classe A (bundled): moduli distribuiti con Logeon, estratti dal core — solo activate/deactivate, uninstall non supportato
   - Classe B (optional): moduli additivi di terze parti — ciclo di vita completo (install/activate/deactivate/uninstall/purge)
   - i nuovi moduli scritti da contributori sono Classe B; non dichiarare `"class": "bundled"` senza approvazione esplicita nel core

## 4. Workflow Git ufficiale
Logeon usa branch brevi e pull request piccole.

1. Crea branch da `main` aggiornato.
2. Fai commit atomici con messaggi chiari.
3. Apri PR in stato `Draft` appena il perimetro e chiaro.
4. Porta la PR a `Ready for review` solo dopo i check minimi.
5. Risolvi commenti review con nuovi commit (no squash locale durante review).
6. Merge finale con `Squash and merge` salvo eccezioni concordate.

### 4.1 Naming branch
Formato:
`<tipo>/<area>-<breve-descrizione>`

Tipi consigliati:
1. `feat/`
2. `fix/`
3. `refactor/`
4. `docs/`
5. `chore/`

Esempi:
1. `feat/weather-core-runtime`
2. `fix/admin-settings-toast-save`
3. `refactor/narrative-events-source-labels`
4. `docs/contributing-pr-policy`

### 4.2 Allineamento branch
Comandi tipici:

```bash
git checkout main
git pull
git checkout -b feat/weather-core-runtime
```

Se `main` avanza durante la PR:

```bash
git fetch origin
git rebase origin/main
git push --force-with-lease
```

## 5. Convenzioni commit
Formato consigliato:
`<type>(<scope>): <summary>`

Tipi:
1. `feat`
2. `fix`
3. `refactor`
4. `docs`
5. `test`
6. `chore`

Esempi:
1. `feat(weather): move simple weather endpoints to core`
2. `fix(settings): restore save toast feedback`
3. `refactor(quests): replace hardcoded navbar slot with generic extension point`
4. `docs(contributing): add pr workflow and code style examples`

Regole:
1. summary al presente, massimo ~72 caratteri
2. un commit deve avere un obiettivo tecnico singolo
3. evita commit "misc", "fix stuff", "update"

## 6. Pull request: cosa deve contenere
Ogni PR deve includere:
1. contesto del problema
2. soluzione implementata
3. file principali modificati
4. impatti su permessi/sicurezza
5. test eseguiti
6. rischi residui e rollback strategy

Template consigliato:

```md
## Contesto
- ...

## Soluzione
- ...

## File toccati
- app/controllers/...
- assets/js/app/features/...

## Verifiche
- php -l ...
- node --check ...
- smoke ...

## Rischi/Note
- ...
```

## 7. Policy review e merge
1. almeno una approvazione e nessun commento bloccante aperto
2. i commenti bloccanti vanno chiusi con fix o decisione esplicita
3. chi apre la PR non fa self-merge su cambi ad alto rischio (`core/*`, auth, db runtime) senza seconda approvazione
4. preferire PR piccole e verticali; evitare PR monolitiche

## 8. Verifiche minime prima di PR
1. `php -l` su ogni file PHP toccato
2. `node --check` sui file JS toccati
3. se tocchi core DB/Auth/Session/Models, esegui:
   - `C:\xampp\php\php.exe scripts/php/smoke-core-runtime.php`
4. in alternativa (debug mirato), esegui i check separati:
   - `C:\xampp\php\php.exe scripts/php/smoke-core-db-runtime.php`
   - `C:\xampp\php\php.exe scripts/php/smoke-core-auth-runtime.php`
5. smoke manuale dei flussi impattati
6. nessun fatal PHP o loop anomalo di richieste in browser

## 9. Convenzioni di scrittura codice
Le convenzioni sotto sono operative, non estetiche: servono a ridurre regressioni e tempi di review.

### 9.1 PHP
1. usa `declare(strict_types=1);`
2. preferisci guard clause e return anticipati
3. validazione input in controller, business logic nei service
4. niente query SQL duplicate se gia esiste un service dedicato

Esempio consigliato:

```php
<?php
declare(strict_types=1);

final class ExampleController
{
    public function save(): void
    {
        $data = RequestData::fromGlobals()->postJson('data', [], true);
        if (!isset($data['name']) || trim((string) $data['name']) === '') {
            throw AppError::validation('Nome obbligatorio', [], 'name_required');
        }

        $result = $this->service->save($data);
        ResponseEmitter::emit(ApiResponse::json(['dataset' => $result]));
    }
}
```

Da evitare:

```php
echo json_encode(['ok' => true]);
die();
```

### 9.2 JavaScript frontend
1. usa ESM (`import`/`export`)
2. niente logica inline nelle Twig
3. i controller pagina devono essere idempotenti in `init()`/`destroy()`
4. preferisci moduli `assets/js/app/features/*` e `assets/js/app/modules/*`

Esempio consigliato:

```js
import { Request } from '../../services/request.js';

export function loadProfile() {
  return Request.http.post('/profile/get', {});
}
```

Da evitare:

```js
window.MyGlobal = function () {};
```

### 9.3 Twig
1. Twig per rendering, non per business logic
2. usa slot/hook per estensioni modulo
3. non hardcodare riferimenti a moduli opzionali nel core

Esempio consigliato:

```twig
{{ slot('game.navbar.organizations.after_bank') }}
```

Da evitare:

```twig
{% if module_active('logeon.quests') %}
  <a data-bs-target="#offcanvasQuests">Quest</a>
{% endif %}
```

## 10. PHPStan e baseline
Logeon usa PHPStan (level 4) su `app/Services`, `app/Controllers` e `core`.

### 10.1 Regola chiave
Il baseline puo solo ridursi.
Non aggiungere nuove voci al `phpstan-baseline.neon` per bypassare errori introdotti dalla PR.

### 10.2 Comportamento atteso
1. se PHPStan segnala errore in codice nuovo, correggi il codice
2. se pensi sia falso positivo, documentalo in PR e chiedi review tecnica
3. non rigenerare baseline in modo massivo

## 11. Dove contribuire in sicurezza
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

## 12. Definizione di Done per una PR
Una PR e considerata pronta al merge se:
1. rispetta regole architetturali e convenzioni commit
2. check minimi eseguiti e riportati in PR
3. documentazione aggiornata se cambia comportamento o convenzioni
4. review completata e commenti bloccanti risolti
5. nessun residuo temporaneo nel repository (`tmp_*`, script usa-e-getta, debug leftovers)

## 13. Convenzioni docs
1. documenti attivi in `docs/`
2. evitare archivi storici locali: una guida aggiornata per argomento
3. ogni guida deve includere `Ultimo aggiornamento`
4. ogni guida pubblica deve essere autosufficiente: usare esempi concreti, spiegare i passaggi operativi direttamente nel testo ed evitare rimandi a materiali non pubblicati
5. se aggiorni questo file, verifica coerenza con `docs/guida-contributori.md` e `docs/README.md`

## 14. Collegamenti rapidi
1. `README.md`
2. `docs/README.md`
3. `docs/guida-contributori.md`
4. `docs/guida-personalizzazione-gioco.md`
5. `docs/guida-architettura-frontend.md`
6. `docs/guida-sistema-moduli.md`
7. `docs/guida-runtime-db-schema.md`
