# Logeon Social Status Module

Modulo opzionale per il dominio Stati sociali.

## Scope (E-8)
- registra provider runtime via hook `social_status.provider`;
- registra view path Twig del modulo;
- espone la pagina admin `social-status` via slot Twig `admin.dashboard.social-status`;
- aggiunge la voce menu admin via `module.json`.
- registra le route API social-status (`/admin/characters/social-status`, `/admin/social-status/*`) via `routes.php`.

## Note
- Cutover runtime ON/OFF validato con `scripts/php/smoke-social-status-module-cutover-runtime.php`.
