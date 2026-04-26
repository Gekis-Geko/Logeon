# Modulo Logeon Novelty

## Scopo
Questo modulo espone il dominio novelty/news come feature opzionale:
1. feed homepage via hook `novelty.homepage_feed`;
2. endpoint API news (`/admin/news/*`, `/list/news`) registrati da `routes.php`.

## Integrazione runtime
1. `module.json` dichiara `entrypoints.bootstrap` e `entrypoints.routes`.
2. `bootstrap.php` registra:
   - autoloader modulo;
   - hook `novelty.homepage_feed`;
   - `twig.view_paths` + slot Twig (`admin.dashboard.news`, `game.modals`, `game.navbar.news_link`, `game.offcanvas.mobile.news_link`).
3. `routes.php` registra le route news solo quando il modulo e attivo.
4. `views/` contiene i template game/admin prima residenti nel core.

## Note operative
- Con modulo OFF: homepage senza news (fallback `[]`) e route news assenti.
- Con modulo ON: comportamento news equivalente al legacy.
