# Modulo Logeon Archetypes

## Scopo
Questo modulo registra un provider Archetipi via hook `character.archetype.provider`, usando il contract interno `Modules\Logeon\Archetypes\Contracts\ArchetypeProviderInterface`.

## Stato attuale
- versione iniziale di bootstrap/bridge;
- implementazione provider nel modulo (`src/ArchetypesModuleProvider.php`);
- nessuna route o asset aggiuntiva.

## Integrazione runtime
1. `module.json` dichiara `entrypoints.bootstrap = bootstrap.php`.
2. `bootstrap.php` registra `Hooks::add('character.archetype.provider', ...)`.
3. Il core risolve il provider via hook `Core\Hooks::filter('character.archetype.provider', null)`.
4. Per retrocompatibilita, il bootstrap mantiene anche l'alias legacy `archetypes.provider`.

## Note operative
- Nessuna dipendenza hard del core verso questo modulo.
- Con modulo disattivo il core degrada gracefully senza provider archetipi.
- Con modulo attivo il provider modulo viene selezionato dal hook runtime.
