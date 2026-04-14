# Aurora Shell

Tema demo per testare il sistema temi Logeon.

## Include
1. shell `public` con palette personalizzata;
2. shell `game` con layout:
   - navbar in alto
   - aside sinistro (navigazione rapida)
   - area contenuto a destra;
3. widget aside custom `Narrative Radar` (stato live + scorciatoie offcanvas);
3. CSS/JS dedicati per public e game.

## Attivazione
1. impostare `sys_configs.theme_system_enabled = 1`
2. impostare `sys_configs.active_theme = aurora-shell`
3. svuotare cache Twig se necessario (`tmp/twig-cache`).

## Note
1. admin resta non themable a livello strutturale;
2. il tema rispetta il contract v1 (extends layout core + blocchi shell/content).

## Come aggiungere un blocco custom nell'aside
1. apri `views/app/layouts/theme-game.twig`;
2. dentro `.aurora-aside-stack` aggiungi una nuova `<section class="card ...">`;
3. usa `data-*` per i mount JS del tuo widget (es. `data-aurora-radar`);
4. definisci stile in `assets/css/game.css`;
5. collega comportamento in `assets/js/game.js`.
