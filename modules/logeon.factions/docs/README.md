# Logeon Factions Module

## Scopo
Modulo opzionale per il dominio Fazioni.

Il modulo copre:
- provider runtime `faction.provider`;
- route API game/admin (`/factions/*`, `/admin/factions/*`);
- route pagina game (`/game/factions`);
- rendering pagina admin via slot Twig `admin.dashboard.factions`;
- asset frontend game/admin (`dist/game.js`, `dist/admin.js`);
- menu runtime (organizzazioni game + voce admin aside).

## Hook registrati
- `faction.provider`:
  - restituisce `Modules\Logeon\Factions\FactionsModuleProvider`.

## Provider esposto
`FactionsModuleProvider` implementa `App\Contracts\FactionProviderInterface`:
- `getMembershipsForCharacter(int $characterId): array<int>`
- `joinEventAsFaction(int $factionId, int $eventId, int $characterId): bool`
- `leaveEventAsFaction(int $factionId, int $eventId, int $characterId): bool`
- `inviteFactionToEvent(int $factionId, int $eventId, int $inviterCharacterId): bool`

Policy attuale:
- modulo OFF: fallback core no-op (`CoreFactionProvider`);
- modulo ON: provider modulo attivo;
- `characterId <= 0` nelle operazioni evento/fazione e considerato contesto admin/sistema.

## Struttura
- `module.json`
- `bootstrap.php`
- `routes.php`
- `src/FactionsModuleProvider.php`
- `assets/js/*`
- `views/factions/*`
- `docs/README.md`

## Note operative
- Nessuna dipendenza hard del core verso il modulo.
- Core compatibile con modulo disattivato (feature Fazioni non esposte nel runtime).
