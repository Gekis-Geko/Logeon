# Logeon Multi Currency Module

## Scope (E-2)
- scaffold del modulo `logeon.multi-currency`;
- autoload e entrypoint runtime (`bootstrap.php`, `routes.php`);
- servizio base `AdditionalCurrencyService` per:
  - lista valute aggiuntive attive;
  - wallet extra per personaggio;
  - merge lista valute disponibili.

## Scope (E-3)
- CRUD admin valute aggiuntive tramite endpoint modulo:
  - `POST /admin/multi-currencies/list`
  - `POST /admin/multi-currencies/create`
  - `POST /admin/multi-currencies/update`
  - `POST /admin/multi-currencies/delete`
- controller: `Modules\Logeon\MultiCurrency\Controllers\AdminCurrencies`;
- service: `Modules\Logeon\MultiCurrency\Services\AdditionalCurrencyAdminService`.

## Scope (E-4)
- registrazione hook runtime modulo:
  - `currency.extra_wallets` (registrato, comportamento neutro in questa fase);
  - `currency.available_list` (estensione lista con valute aggiuntive attive).

## Scope (E-5)
- integrazione Twig hook-based per UI additiva:
  - registrazione `twig.view_paths` verso `modules/logeon.multi-currency/views`;
  - slot `twig.slot.character.profile.wallets` con fragment modulo;
  - slot `twig.slot.shop.price.extra` con fragment modulo.
- nessun hardcode del modulo nel core: il core espone i punti slot e il modulo si aggancia.

## Scope (E-6)
- validato cutover runtime OFF/ON con smoke dedicato:
  - `scripts/php/smoke-multi-currency-module-cutover-runtime.php`.
- integrazione in suite core runtime:
  - `scripts/php/smoke-core-runtime.php` include `Multi-currency module cutover runtime`.

## Note operative
- i hook runtime base sono collegati in E-4;
- con modulo OFF, il core resta in modalita single-currency invariata.
