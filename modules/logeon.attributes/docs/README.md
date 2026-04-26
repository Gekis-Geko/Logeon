# Logeon Attributes Module

## Scopo
`logeon.attributes` incapsula il dominio Attributi personaggio:
- provider runtime via hook `attribute.provider`;
- endpoint API admin/profilo `/admin/character-attributes/*` e `/profile/attributes/*`;
- viste admin e modale profilo Attributi.

Con modulo attivo il comportamento resta equivalente alla versione core precedente.

## Integrazione core
- Hook provider: `attribute.provider`
- Slot Twig admin: `twig.slot.admin.dashboard.character-attributes`
- Slot Twig game: `twig.slot.game.profile.modals`

## Entrypoints
- `bootstrap.php`
- `routes.php`

## Note operative
- Il provider modulo delega a `CharacterAttributesFacadeService`.
- Con modulo disattivo il core usa `CoreAttributeProvider` come fallback no-op.
