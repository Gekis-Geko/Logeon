# Contratti API Backend

Ultimo aggiornamento: 2026-04-04

## Scopo
Questo documento descrive i contratti API **core** esposti dal backend Logeon.

Include:
1. convenzioni di trasporto e sicurezza;
2. contratto errori;
3. mappa completa degli endpoint runtime (game + admin) presenti in `app/routes/api.php`;
4. note operative sui flussi core piu sensibili.

Non include:
1. API funzionali interne dei moduli opzionali;
2. checklist o cronologia di sviluppo interna.

## Convenzioni comuni

### Trasporto
1. Metodo standard: `POST`.
2. Content-Type standard: `application/x-www-form-urlencoded`.
3. Payload applicativo nel campo `data` contenente un JSON object.
4. CSRF: token in payload (`_csrf`) e header `X-CSRF-Token`.

Esempio body form:

```text
data={"thread_id":12,"limit":30,"_csrf":"..."}
```

### Autenticazione
1. Quasi tutti gli endpoint richiedono sessione valida.
2. Molti endpoint gameplay richiedono anche personaggio attivo (`requireCharacter`).
3. Le policy vengono sempre applicate lato backend, non solo lato UI.

### Contratto errore
1. Formato minimo: `{"error":"Messaggio"}`.
2. Formato esteso: `{"error":"Messaggio","error_code":"codice_stabile"}`.
3. HTTP status tipici:
   - `400`: validazione/policy/rate-limit applicativo;
   - `403`: non autorizzato;
   - `404`: risorsa non trovata.

Codici base ricorrenti:
1. `validation_error`
2. `unauthorized`
3. `not_found`
4. `system_error`
5. `csrf_invalid`
6. `session_missing`
7. `session_expired`
8. `session_revoked`

## Codici business principali

### Economy / Inventory
1. `shop_unavailable`, `shop_item_invalid`, `shop_item_unavailable`
2. `quantity_invalid`, `quantity_unavailable`
3. `stock_insufficient`, `shop_limit_reached`, `shop_daily_limit_reached`
4. `insufficient_funds`, `currency_unavailable`, `character_invalid`
5. `item_not_found`, `item_equipped`, `item_not_sellable`, `sell_price_invalid`, `sell_failed`
6. `item_invalid`, `item_not_equippable`, `item_not_usable`, `item_cooldown_active`
7. `slot_unavailable`, `slot_required`, `slot_invalid`
8. `inventory_capacity_reached`, `inventory_stack_limit_reached`

### Social / Location
1. `message_empty`, `message_too_long`, `subject_required`, `subject_too_long`
2. `dm_rate_limited`, `message_type_invalid`, `thread_invalid`, `thread_forbidden`
3. `location_invalid`, `location_access_denied`
4. `location_chat_rate_limited`, `location_whisper_rate_limited`
5. `command_invalid`, `command_argument_invalid`, `dice_format_invalid`
6. `whisper_invalid`, `whisper_empty`, `whisper_too_long`
7. `recipient_invalid`, `recipient_self_not_allowed`, `recipient_not_in_location`
8. `invite_payload_invalid`, `invite_self_not_allowed`
9. `location_invite_rate_limited`, `location_invite_respond_rate_limited`
10. `invite_owner_required`, `invite_private_only`, `invite_limit_reached`, `invite_not_found_or_expired`

### Character / Profile
1. `character_invalid`, `character_not_found`, `profile_forbidden`
2. `payload_missing`, `password_invalid`, `character_delete_already_scheduled`
3. `social_status_invalid`
4. `profile_url_invalid`, `profile_height_invalid`, `profile_weight_invalid`
5. `availability_invalid`, `dm_policy_invalid`, `invite_policy_invalid`
6. `character_name_invalid`, `character_name_too_long`
7. `character_name_same_as_current`, `character_name_already_used`
8. `character_name_request_pending`, `character_name_request_cooldown`
9. `event_required_fields`, `event_forbidden`, `event_invalid`, `event_not_found`
10. `profile_logs_forbidden`, `profile_session_logs_forbidden`

### Moduli (core orchestrazione)
1. `module_not_found`, `module_not_installed`
2. `module_dependency_missing`, `module_incompatible_core`
3. `module_activation_failed`, `module_deactivation_failed`
4. `module_deactivation_requires_confirmation`
5. `module_uninstall_requires_inactive`, `module_uninstall_failed`
6. `module_audit_failed`

### Attributi personaggio
1. `attributes_system_disabled`
2. `attribute_definition_not_found`, `attribute_slug_conflict`
3. `attribute_range_invalid`, `attribute_default_out_of_range`
4. `attribute_rule_invalid`, `attribute_rule_step_invalid`
5. `attribute_override_not_allowed`, `attribute_update_forbidden`
6. `attribute_recompute_failed`

### Stati narrativi
1. `state_not_found`, `state_conflict_blocked`
2. `state_apply_failed`, `state_remove_failed`

### Eventi di sistema
1. `system_event_not_found`, `system_event_invalid_state`
2. `system_event_schedule_invalid`
3. `system_event_participation_forbidden`, `system_event_participation_conflict`
4. `system_event_reward_invalid`, `system_event_effect_unsupported`
5. `system_event_feature_disabled`

### Quest
1. `quest_not_found`, `quest_definition_invalid`
2. `quest_instance_invalid_state`
3. `quest_participation_forbidden`, `quest_participation_conflict`
4. `quest_condition_invalid`, `quest_outcome_invalid`, `quest_trigger_unsupported`
5. `quest_feature_disabled`
6. `quest_closure_invalid`, `quest_closure_forbidden`
7. `quest_reward_invalid`, `quest_reward_unsupported`, `quest_history_forbidden`

### Auth / accesso account
1. `google_auth_disabled`, `google_auth_not_configured`, `google_oauth_denied`
2. `auth_google_client_id_invalid`, `auth_google_client_secret_invalid`, `auth_google_redirect_uri_invalid`
3. `multi_character_max_per_user_invalid`

## Mappa completa endpoint core

### Accesso account
1. `POST /signin`
2. `POST /signin/characters/list`
3. `POST /signin/character/select`
4. `POST /signout`
5. `POST /forgot-password`
6. `POST /reset-password`
7. `GET /auth/google/start` (flusso web auth)
8. `GET /auth/google/callback` (flusso web auth)

### Messaggi privati
1. `POST /message/threads`
2. `POST /message/thread`
3. `POST /message/send`
4. `POST /message/unread`
5. `POST /message/reports/create`

### Lavori
1. `POST /jobs/available`
2. `POST /jobs/assign`
3. `POST /jobs/leave`
4. `POST /jobs/current`
5. `POST /jobs/task/complete`

### Gilde (gameplay)
1. `POST /guilds/list`
2. `POST /guilds/character/list`
3. `POST /guilds/get`
4. `POST /guilds/apply`
5. `POST /guilds/members`
6. `POST /guilds/roles`
7. `POST /guilds/requirements/options`
8. `POST /guilds/requirements/upsert`
9. `POST /guilds/requirements/delete`
10. `POST /guilds/applications`
11. `POST /guilds/application/decide`
12. `POST /guilds/kick/request`
13. `POST /guilds/kick/decide`
14. `POST /guilds/kick`
15. `POST /guilds/promote`
16. `POST /guilds/salary/claim`
17. `POST /guilds/primary`
18. `POST /guilds/logs`
19. `POST /guilds/announcements`
20. `POST /guilds/announcement/create`
21. `POST /guilds/announcement/delete`
22. `POST /guilds/events`
23. `POST /guilds/event/create`
24. `POST /guilds/event/delete`

### Luogo, chat, sussurri, inviti, drop
1. `POST /location/invites`
2. `POST /location/invite`
3. `POST /location/invite/respond`
4. `POST /location/invite/owner-updates`
5. `POST /location/messages/list`
6. `POST /location/messages/send`
7. `POST /location/whispers/list`
8. `POST /location/whispers/threads`
9. `POST /location/whispers/unread`
10. `POST /location/whispers/send`
11. `POST /location/whispers/policy`
12. `POST /location/drops/list`
13. `POST /location/drops/drop`
14. `POST /location/drops/pick`

### Shop
1. `POST /shop/sellables`
2. `POST /shop/sell`
3. `POST /shop/items`
4. `POST /shop/buy`

### Banca
1. `POST /bank/summary`
2. `POST /bank/deposit`
3. `POST /bank/withdraw`
4. `POST /bank/transfer`

### Settings gameplay
1. `POST /settings/upload`
2. `POST /settings/password`
3. `POST /settings/sessions/revoke`

### Eventi personaggio
1. `POST /events/list`
2. `POST /events/create`
3. `POST /events/update`
4. `POST /events/delete`

### Conflitti
1. `POST /conflicts/list`
2. `POST /conflicts/get`
3. `POST /conflicts/open`
4. `POST /conflicts/propose`
5. `POST /conflicts/proposal/respond`
6. `POST /conflicts/location/feed`
7. `POST /conflicts/participants/upsert`
8. `POST /conflicts/action/add`
9. `POST /conflicts/action/execute`
10. `POST /conflicts/status/set`
11. `POST /conflicts/roll`
12. `POST /conflicts/resolve`
13. `POST /conflicts/close`

### Inventario ed equip
1. `POST /inventory/slots`
2. `POST /inventory/available`
3. `POST /inventory/equipped`
4. `POST /inventory/equip`
5. `POST /inventory/unequip`
6. `POST /inventory/destroy`
7. `POST /inventory/swap`
8. `POST /inventory/categories`
9. `POST /items/use`

### Profilo e relazioni
1. `POST /profile/update`
2. `POST /profile/availability`
3. `POST /profile/visibility`
4. `POST /profile/settings`
5. `POST /profile/name-request`
6. `POST /profile/loanface-request`
7. `POST /profile/identity-request`
8. `POST /profile/delete`
9. `POST /profile/delete/cancel`
10. `POST /profile/relationships/html/get`
11. `POST /profile/relationships/html/update`
12. `POST /profile/bonds/list`
13. `POST /profile/bonds/request`
14. `POST /profile/bonds/request/respond`
15. `POST /profile/logs/experience`
16. `POST /profile/logs/economy`
17. `POST /profile/logs/sessions`
18. `POST /profile/master-notes/update`
19. `POST /profile/health/update`
20. `POST /profile/experience/assign`
21. `POST /profile/attributes/list`
22. `POST /profile/attributes/update-values`
23. `POST /profile/attributes/recompute`

### Archetipi e creazione personaggio
1. `POST /character/create`
2. `POST /archetypes/list`
3. `POST /archetypes/my`

### Tag narrativi (game)
1. `POST /narrative-tags/entity`

### Endpoint lista (`/list/*`)
1. `POST /list/nationalities`
2. `POST /list/archetypes`
3. `POST /list/narrative-tags`
4. `POST /list/maps`
5. `POST /list/locations`
6. `POST /list/profile/bag`
7. `POST /list/onlines`
8. `POST /list/onlines/complete`
9. `POST /list/characters/search`
10. `POST /list/forum-types`
11. `POST /list/forum`
12. `POST /list/news`
13. `POST /list/forum/threads`
14. `POST /list/items`

### Endpoint get (`/get/*`)
1. `POST /get/weather`
2. `POST /get/profile`
3. `POST /get/bag`
4. `POST /get/forum`
5. `POST /get/forum/thread`

### Meteo e clima
1. `POST /weather/options`
2. `POST /weather/global/set`
3. `POST /weather/global/clear`
4. `POST /weather/world/options`
5. `POST /weather/world/set`
6. `POST /weather/world/clear`
7. `POST /weather/location/set`
8. `POST /weather/location/clear`
9. `POST /weather/climate-areas`
10. `POST /weather/climate-areas/create`
11. `POST /weather/climate-areas/update`
12. `POST /weather/climate-areas/delete`
13. `POST /weather/climate-areas/assign`
14. `POST /weather/types`
15. `POST /weather/types/create`
16. `POST /weather/types/update`
17. `POST /weather/types/delete`
18. `POST /weather/seasons`
19. `POST /weather/seasons/create`
20. `POST /weather/seasons/update`
21. `POST /weather/seasons/delete`
22. `POST /weather/zones`
23. `POST /weather/zones/create`
24. `POST /weather/zones/update`
25. `POST /weather/zones/delete`
26. `POST /weather/profiles`
27. `POST /weather/profiles/upsert`
28. `POST /weather/profiles/delete`
29. `POST /weather/profiles/weights`
30. `POST /weather/profiles/weights/sync`
31. `POST /weather/assignments`
32. `POST /weather/assignments/upsert`
33. `POST /weather/assignments/delete`
34. `POST /weather/overrides`
35. `POST /weather/overrides/upsert`
36. `POST /weather/overrides/delete`

### Stati narrativi (core)
1. `POST /narrative-states/catalog`
2. `POST /narrative-states/apply`
3. `POST /narrative-states/remove`
4. `POST /admin/narrative-states/list`
5. `POST /admin/narrative-states/create`
6. `POST /admin/narrative-states/update`
7. `POST /admin/narrative-states/delete`

### Upload file
1. `POST /uploader`

### Registro eventi narrativi (core)
1. `POST /narrative-events/list`
2. `POST /narrative-events/get`
3. `POST /admin/narrative-events/list`
4. `POST /admin/narrative-events/get`
5. `POST /admin/narrative-events/create`
6. `POST /admin/narrative-events/update`
7. `POST /admin/narrative-events/attach`
8. `POST /admin/narrative-events/delete`

### Eventi di sistema (core)
1. `POST /system-events/list`
2. `POST /system-events/get`
3. `POST /system-events/participation/join`
4. `POST /system-events/participation/leave`

### Quest (core)
1. `POST /quests/list`
2. `POST /quests/get`
3. `POST /quests/history/list`
4. `POST /quests/history/get`
5. `POST /quests/participation/join`
6. `POST /quests/participation/leave`
7. `POST /quests/staff/instances/list`
8. `POST /quests/staff/step/confirm`
9. `POST /quests/staff/instance/status-set`
10. `POST /quests/staff/instance/force-progress`
11. `POST /quests/staff/closure/get`
12. `POST /quests/staff/closure/finalize`

### Lifecycle personaggio (core)
1. `POST /lifecycle/current`
2. `POST /lifecycle/history`
3. `POST /admin/lifecycle/phases/list`
4. `POST /admin/lifecycle/phases/create`
5. `POST /admin/lifecycle/phases/update`
6. `POST /admin/lifecycle/phases/delete`
7. `POST /admin/lifecycle/characters/current`
8. `POST /admin/lifecycle/characters/history`
9. `POST /admin/lifecycle/characters/transition`

### Fazioni (core)
1. `POST /factions/list`
2. `POST /factions/get`
3. `POST /factions/my`
4. `POST /admin/factions/list`
5. `POST /admin/factions/get`
6. `POST /admin/factions/create`
7. `POST /admin/factions/update`
8. `POST /admin/factions/delete`
9. `POST /admin/factions/members/list`
10. `POST /admin/factions/members/add`
11. `POST /admin/factions/members/update`
12. `POST /admin/factions/members/remove`
13. `POST /admin/factions/relations/list`
14. `POST /admin/factions/relations/set`
15. `POST /admin/factions/relations/remove`

### Notifiche
1. `POST /notifications/list`
2. `POST /notifications/read`
3. `POST /notifications/read-delete`
4. `POST /notifications/delete`
5. `POST /notifications/read-all`
6. `POST /notifications/respond`
7. `POST /notifications/unread-count`

## Admin: mappa completa endpoint

### Dashboard, utenti, personaggi, blacklist
1. `POST /admin/dashboard/summary`
2. `POST /admin/users/list`
3. `POST /admin/users/reset-password`
4. `POST /admin/users/permissions`
5. `POST /admin/users/disconnect`
6. `POST /admin/users/restrict`
7. `POST /admin/characters/list`
8. `POST /admin/characters/get`
9. `POST /admin/characters/logs/experience`
10. `POST /admin/characters/logs/economy`
11. `POST /admin/characters/logs/sessions`
12. `POST /admin/blacklist/list`
13. `POST /admin/blacklist/create`
14. `POST /admin/blacklist/update`
15. `POST /admin/blacklist/delete`

### Cataloghi oggetti, equip, categorie
1. `POST /admin/items/list`
2. `POST /admin/items/types`
3. `POST /admin/items/rarities/list`
4. `POST /admin/items/rarities/admin-list`
5. `POST /admin/items/rarities/create`
6. `POST /admin/items/rarities/update`
7. `POST /admin/items/rarities/delete`
8. `POST /admin/items/create`
9. `POST /admin/items/update`
10. `POST /admin/items/delete`
11. `POST /admin/equipment-slots/list`
12. `POST /admin/equipment-slots/create`
13. `POST /admin/equipment-slots/update`
14. `POST /admin/equipment-slots/delete`
15. `POST /admin/item-equipment-rules/list`
16. `POST /admin/item-equipment-rules/create`
17. `POST /admin/item-equipment-rules/update`
18. `POST /admin/item-equipment-rules/delete`
19. `POST /admin/categories/list`
20. `POST /admin/categories/create`
21. `POST /admin/categories/update`
22. `POST /admin/categories/delete`

### Valute, negozi, inventario negozi
1. `POST /admin/currencies/list`
2. `POST /admin/currencies/create`
3. `POST /admin/currencies/update`
4. `POST /admin/currencies/delete`
5. `POST /admin/shops/list`
6. `POST /admin/shops/create`
7. `POST /admin/shops/update`
8. `POST /admin/shops/delete`
9. `POST /admin/inventory/list`
10. `POST /admin/inventory/create`
11. `POST /admin/inventory/update`
12. `POST /admin/inventory/delete`

### Mappe e luoghi
1. `POST /admin/maps/list`
2. `POST /admin/maps/create`
3. `POST /admin/maps/update`
4. `POST /admin/maps/delete`
5. `POST /admin/locations/list`
6. `POST /admin/locations/create`
7. `POST /admin/locations/edit`
8. `POST /admin/locations/delete`
9. `POST /admin/locations/update`

### Contenuti
1. `POST /admin/storyboards/list`
2. `POST /admin/storyboards/create`
3. `POST /admin/storyboards/update`
4. `POST /admin/storyboards/delete`
5. `POST /admin/rules/list`
6. `POST /admin/rules/create`
7. `POST /admin/rules/update`
8. `POST /admin/rules/delete`
9. `POST /admin/how-to-play/list`
10. `POST /admin/how-to-play/create`
11. `POST /admin/how-to-play/update`
12. `POST /admin/how-to-play/delete`
13. `POST /admin/news/list`
14. `POST /admin/news/create`
15. `POST /admin/news/update`
16. `POST /admin/news/delete`

### Social status e richieste personaggio
1. `POST /admin/characters/social-status`
2. `POST /admin/social-status/list`
3. `POST /admin/social-status/admin-list`
4. `POST /admin/social-status/create`
5. `POST /admin/social-status/update`
6. `POST /admin/social-status/delete`
7. `POST /admin/characters/name-requests/list`
8. `POST /admin/characters/loanface-requests/list`
9. `POST /admin/characters/identity-requests/list`
10. `POST /admin/characters/admin-edit-identity`
11. `POST /admin/characters/admin-edit-narrative`
12. `POST /admin/characters/admin-edit-stats`
13. `POST /admin/characters/admin-edit-economy`
14. `POST /admin/characters/admin-edit-notes`
15. `POST /admin/characters/name-request/decide`
16. `POST /admin/characters/loanface-request/decide`
17. `POST /admin/characters/identity-request/decide`

### Lavori admin
1. `POST /admin/jobs/list`
2. `POST /admin/jobs/create`
3. `POST /admin/jobs/update`
4. `POST /admin/jobs/delete`
5. `POST /admin/jobs-levels/list`
6. `POST /admin/jobs-levels/create`
7. `POST /admin/jobs-levels/update`
8. `POST /admin/jobs-levels/delete`
9. `POST /admin/jobs-tasks/list`
10. `POST /admin/jobs-tasks/get`
11. `POST /admin/jobs-tasks/create`
12. `POST /admin/jobs-tasks/update`
13. `POST /admin/jobs-tasks/delete`

### Gilde admin e cataloghi correlati
1. `POST /admin/guild-alignments/list`
2. `POST /admin/guild-alignments/create`
3. `POST /admin/guild-alignments/update`
4. `POST /admin/guild-alignments/delete`
5. `POST /admin/guilds/list`
6. `POST /admin/guilds/create`
7. `POST /admin/guilds/update`
8. `POST /admin/guilds/delete`
9. `POST /admin/guilds/admin-list`
10. `POST /admin/guilds/admin-create`
11. `POST /admin/guilds/admin-update`
12. `POST /admin/guilds/admin-delete`
13. `POST /admin/guilds/roles-list`
14. `POST /admin/guilds/roles-create`
15. `POST /admin/guilds/roles-update`
16. `POST /admin/guilds/roles-delete`
17. `POST /admin/guilds/events-list`
18. `POST /admin/guilds/events-create`
19. `POST /admin/guilds/events-update`
20. `POST /admin/guilds/events-delete`
21. `POST /admin/guild-roles/list`
22. `POST /admin/guild-roles/create`
23. `POST /admin/guild-roles/update`
24. `POST /admin/guild-roles/delete`
25. `POST /admin/guild-role-scopes/list`
26. `POST /admin/guild-role-scopes/create`
27. `POST /admin/guild-role-scopes/update`
28. `POST /admin/guild-role-scopes/delete`
29. `POST /admin/guild-requirements/list`
30. `POST /admin/guild-requirements/create`
31. `POST /admin/guild-requirements/update`
32. `POST /admin/guild-requirements/delete`
33. `POST /admin/guild-role-locations/list`
34. `POST /admin/guild-role-locations/create`
35. `POST /admin/guild-role-locations/update`
36. `POST /admin/guild-role-locations/delete`

### Forum admin
1. `POST /admin/forums/list`
2. `POST /admin/forums/types-list`
3. `POST /admin/forums/create`
4. `POST /admin/forums/update`
5. `POST /admin/forums/delete`
6. `POST /admin/forum-types/list`
7. `POST /admin/forum-types/create`
8. `POST /admin/forum-types/update`
9. `POST /admin/forum-types/delete`

### Settings admin
1. `POST /admin/settings/upload`
2. `POST /admin/settings/get`
3. `POST /admin/settings/update`

### Moduli (core governance)
1. `POST /admin/modules/list`
2. `POST /admin/modules/activate`
3. `POST /admin/modules/deactivate`
4. `POST /admin/modules/uninstall`
5. `POST /admin/modules/audit`

### Attributi personaggio (core)
1. `POST /admin/character-attributes/settings/get`
2. `POST /admin/character-attributes/settings/update`
3. `POST /admin/character-attributes/definitions/list`
4. `POST /admin/character-attributes/definitions/create`
5. `POST /admin/character-attributes/definitions/update`
6. `POST /admin/character-attributes/definitions/deactivate`
7. `POST /admin/character-attributes/definitions/reorder`
8. `POST /admin/character-attributes/rules/get`
9. `POST /admin/character-attributes/rules/upsert`
10. `POST /admin/character-attributes/rules/delete`
11. `POST /admin/character-attributes/recompute`

### Log amministrativi
1. `POST /admin/logs/conflicts/list`
2. `POST /admin/logs/currency/list`
3. `POST /admin/logs/experience/list`
4. `POST /admin/logs/fame/list`
5. `POST /admin/logs/guild/list`
6. `POST /admin/logs/job/list`
7. `POST /admin/logs/location-access/list`
8. `POST /admin/logs/sys/list`

### Archetipi admin
1. `POST /admin/archetypes/list`
2. `POST /admin/archetypes/create`
3. `POST /admin/archetypes/update`
4. `POST /admin/archetypes/delete`
5. `POST /admin/archetypes/config/get`
6. `POST /admin/archetypes/config/update`
7. `POST /admin/archetypes/character/list`
8. `POST /admin/archetypes/character/assign`
9. `POST /admin/archetypes/character/remove`

### Segnalazioni messaggi
1. `POST /admin/message-reports/list`
2. `POST /admin/message-reports/get`
3. `POST /admin/message-reports/update-status`
4. `POST /admin/message-reports/assign`

### Tag narrativi admin
1. `POST /admin/narrative-tags/list`
2. `POST /admin/narrative-tags/create`
3. `POST /admin/narrative-tags/update`
4. `POST /admin/narrative-tags/delete`
5. `POST /admin/narrative-tags/entity/get`
6. `POST /admin/narrative-tags/entity/sync`
7. `POST /admin/narrative-tags/entity/search`

### Conflitti admin
1. `POST /admin/conflicts/settings/get`
2. `POST /admin/conflicts/settings/update`
3. `POST /admin/conflicts/force-open`
4. `POST /admin/conflicts/force-close`
5. `POST /admin/conflicts/edit-log`
6. `POST /admin/conflicts/override-roll`

### Eventi di sistema admin
1. `POST /admin/system-events/list`
2. `POST /admin/system-events/get`
3. `POST /admin/system-events/create`
4. `POST /admin/system-events/update`
5. `POST /admin/system-events/delete`
6. `POST /admin/system-events/status/set`
7. `POST /admin/system-events/effects/list`
8. `POST /admin/system-events/effects/upsert`
9. `POST /admin/system-events/effects/delete`
10. `POST /admin/system-events/participations/list`
11. `POST /admin/system-events/participations/upsert`
12. `POST /admin/system-events/participations/remove`
13. `POST /admin/system-events/rewards/assign`
14. `POST /admin/system-events/rewards/log`
15. `POST /admin/system-events/maintenance/run`

### Quest admin
1. `POST /admin/quests/definitions/list`
2. `POST /admin/quests/definitions/create`
3. `POST /admin/quests/definitions/update`
4. `POST /admin/quests/definitions/publish`
5. `POST /admin/quests/definitions/archive`
6. `POST /admin/quests/definitions/delete`
7. `POST /admin/quests/definitions/reorder`
8. `POST /admin/quests/steps/list`
9. `POST /admin/quests/steps/upsert`
10. `POST /admin/quests/steps/delete`
11. `POST /admin/quests/steps/reorder`
12. `POST /admin/quests/conditions/list`
13. `POST /admin/quests/conditions/upsert`
14. `POST /admin/quests/conditions/delete`
15. `POST /admin/quests/outcomes/list`
16. `POST /admin/quests/outcomes/upsert`
17. `POST /admin/quests/outcomes/delete`
18. `POST /admin/quests/instances/list`
19. `POST /admin/quests/instances/get`
20. `POST /admin/quests/instances/assign`
21. `POST /admin/quests/instances/status/set`
22. `POST /admin/quests/instances/step/set`
23. `POST /admin/quests/closures/list`
24. `POST /admin/quests/closures/get`
25. `POST /admin/quests/closures/upsert`
26. `POST /admin/quests/rewards/list`
27. `POST /admin/quests/rewards/assign`
28. `POST /admin/quests/rewards/remove`
29. `POST /admin/quests/links/list`
30. `POST /admin/quests/links/upsert`
31. `POST /admin/quests/links/delete`
32. `POST /admin/quests/logs/list`
33. `POST /admin/quests/maintenance/run`

## Contratti operativi dettagliati (core)

Questa sezione aggiunge il dettaglio operativo ai gruppi endpoint principali:
1. scopo dell'endpoint;
2. request principali;
3. response principali;
4. errori funzionali ricorrenti.

Le route restano quelle elencate nella mappa completa sopra.

### Accesso account, OAuth Google, multi-personaggio

#### `POST /signin`
Scopo:
1. autenticazione account;
2. instradamento flusso verso gioco, creazione personaggio o selezione personaggio.

Response tipiche:
1. login completo: `user`, `character`;
2. creazione personaggio richiesta: `error_character` (`title`, `body`, `user`);
3. selezione personaggio richiesta: `error_character_select` (`title`, `body`, `user`, `characters`, `max_characters`);
4. errore autenticazione: `error_auth`.

#### `POST /signin/characters/list`
Scopo:
1. elenco personaggi associati all'account autenticato in sessione.

Response:
1. `characters[]` con record sanitizzati (`id`, `name`, `surname`, `gender`, `availability`, `last_map`, `last_location`, `date_last_signin`);
2. `max_characters`.

#### `POST /signin/character/select`
Request:
1. `character_id` (obbligatorio).

Response:
1. login finalizzato con `user` + `character`;
2. errore auth su personaggio non appartenente all'account.

#### `GET /auth/google/start` e `GET /auth/google/callback`
Regole:
1. abilitazione runtime tramite `auth_google_enabled=1`;
2. configurazione su `/admin/settings/get|update`:
   - `auth_google_enabled`
   - `auth_google_client_id`
   - `auth_google_client_secret`
   - `auth_google_redirect_uri`;
3. il pulsante Google in UI appare solo se il backend espone la feature attiva.

#### Multi-personaggio
Chiavi runtime (`sys_configs`):
1. `multi_character_enabled` (`0|1`);
2. `multi_character_max_per_user` (`1..10`).

Comportamento:
1. se `multi_character_enabled=0`, il flusso resta single-character;
2. se `multi_character_enabled=1`, login puo richiedere selezione personaggio.

### Messaggi privati e segnalazioni messaggi

#### `POST /message/threads`
Scopo:
1. elenco thread DM del personaggio.

Request principali:
1. filtri opzionali di pagina/limite.

Response:
1. dataset thread con metadati unread/timestamp.

#### `POST /message/thread`
Scopo:
1. dettaglio singolo thread DM.

Request:
1. `thread_id` (obbligatorio).

#### `POST /message/send`
Request principali:
1. `to_character_id`;
2. `message`;
3. opzionale `subject`.

Errori ricorrenti:
1. `message_empty`, `message_too_long`, `subject_required`, `subject_too_long`;
2. `dm_rate_limited`, `thread_forbidden`, `character_invalid`.

#### `POST /message/unread`
Scopo:
1. conteggio/estrazione DM non lette.

#### `POST /message/reports/create`
Scopo:
1. apertura segnalazione moderazione su messaggio.

Request:
1. `message_id` (obbligatorio);
2. `reason_code` (obbligatorio);
3. `reason_text` (opzionale).

Errori:
1. `message_not_found`;
2. `message_report_invalid_reason`.

### Lavori (`/jobs/*`)

#### `POST /jobs/available`
Scopo:
1. elenco lavori disponibili per il personaggio corrente.

#### `POST /jobs/assign`
Request:
1. `job_id` (obbligatorio).

#### `POST /jobs/leave`
Scopo:
1. uscita dal lavoro attivo.

#### `POST /jobs/current`
Scopo:
1. dettaglio lavoro corrente e avanzamento.

#### `POST /jobs/task/complete`
Request tipica:
1. `job_task_id`;
2. eventuale scelta/contesto (`job_choice`, `location_id`) in base al task.

Errori ricorrenti:
1. `job_task_invalid`, `job_task_not_available`, `job_choice_invalid`, `job_location_required`.

### Gilde gameplay (`/guilds/*`)

Endpoint e scopo:
1. `/guilds/list`: elenco pubblico gilde;
2. `/guilds/character/list`: gilde del personaggio;
3. `/guilds/get`: dettaglio gilda;
4. `/guilds/apply`: candidatura;
5. `/guilds/members`: membri e ruoli;
6. `/guilds/roles`: ruoli disponibili;
7. `/guilds/requirements/options|upsert|delete`: requisiti di ingresso;
8. `/guilds/applications` + `/guilds/application/decide`: gestione candidature;
9. `/guilds/kick/request|decide|kick`: rimozione membri;
10. `/guilds/promote`: promozione/cambio ruolo;
11. `/guilds/salary/claim`: riscossione stipendio;
12. `/guilds/primary`: gilda primaria;
13. `/guilds/logs`: log gilda;
14. `/guilds/announcements` + `announcement/create|delete`: annunci;
15. `/guilds/events` + `event/create|delete`: eventi gilda.

Errori ricorrenti:
1. `guild_not_found`, `guild_forbidden`, `guild_membership_required`;
2. `guild_application_already_pending`;
3. `guild_leader_required`, `guild_role_scope_forbidden`;
4. `guild_salary_already_claimed`.

### Luogo/chat/sussurri/inviti/drop (`/location/*`)

#### Inviti luogo
1. `/location/invites`: elenco inviti ricevuti;
2. `/location/invite`: invio invito (`target_character_id`, `location_id`);
3. `/location/invite/respond`: risposta invito (`invite_id`, `decision`);
4. `/location/invite/owner-updates`: feed aggiornamenti inviti del proprietario.

Errori:
1. `invite_payload_invalid`, `invite_self_not_allowed`;
2. `invite_private_only`, `invite_limit_reached`, `invite_not_found_or_expired`.

#### Messaggi luogo
1. `/location/messages/list`: timeline location;
2. `/location/messages/send`: invio messaggio o comando.

Request comuni:
1. `location_id`;
2. `thread_id`/`before_id`/`after_id`/`limit` (quando previsti dal feed);
3. `message`.

Errori:
1. `location_invalid`, `location_access_denied`;
2. `location_chat_rate_limited`;
3. `command_invalid`, `dice_format_invalid`.

#### Sussurri
1. `/location/whispers/list`: feed sussurri;
2. `/location/whispers/threads`: elenco thread sussurri;
3. `/location/whispers/unread`: conteggio non letti;
4. `/location/whispers/send`: invio sussurro (`recipient_character_id`, `message`);
5. `/location/whispers/policy`: policy DM/sussurri personaggio.

Errori:
1. `whisper_invalid`, `whisper_empty`, `whisper_too_long`;
2. `recipient_not_in_location`, `recipient_self_not_allowed`;
3. `location_whisper_rate_limited`.

#### Drop oggetti
1. `/location/drops/list`: elenco drop nel luogo;
2. `/location/drops/drop`: rilascio oggetto;
3. `/location/drops/pick`: raccolta oggetto.

Errori:
1. `item_not_droppable`, `item_equipped_remove_first`;
2. `drop_not_available`, `drop_not_in_location`.

### Shop, inventario e uso oggetti

#### `POST /shop/items`
Scopo:
1. catalogo shop risolto per `shop_id` o `location_id`.

Response:
1. `shop`, `items`, `categories`, `currencies`, `balances`, `social_discount`.

#### `POST /shop/buy`
Request:
1. `shop_item_id` (obbligatorio);
2. `quantity` (opzionale, default 1).

Response:
1. `success`;
2. `balances` aggiornati.

#### `POST /shop/sellables`
Scopo:
1. elenco oggetti del personaggio vendibili nello shop corrente.

#### `POST /shop/sell`
Request:
1. identificatore item stack/istanza (`character_item_instance_id` o `character_item_id` o fallback `item_id`);
2. `quantity` opzionale;
3. `shop_id`/`location_id` opzionali.

#### Inventory runtime
1. `/inventory/slots`: slot equip disponibili;
2. `/inventory/available`: inventario disponibile;
3. `/inventory/equipped`: oggetti equipaggiati;
4. `/inventory/equip`: equip item;
5. `/inventory/unequip`: rimozione equip;
6. `/inventory/destroy`: distruzione item;
7. `/inventory/swap`: scambio slot equip;
8. `/inventory/categories`: elenco categorie inventario (inclusa categoria vuota senza oggetti).

#### `POST /items/use`
Scopo:
1. uso oggetto inventario (consumo/cooldown/effetti supportati dal core).

Errori economy/inventory:
1. `insufficient_funds`, `stock_insufficient`;
2. `item_not_equippable`, `slot_required`, `slot_invalid`;
3. `item_not_usable`, `item_cooldown_active`;
4. `inventory_capacity_reached`, `inventory_stack_limit_reached`.

### Banca (`/bank/*`)

#### `POST /bank/summary`
Request:
1. `limit` opzionale (default 20).

Response:
1. riepilogo saldo wallet/banca;
2. movimenti recenti.

#### `POST /bank/deposit`
Request:
1. `amount` (obbligatorio, > 0).

Response:
1. `dataset.operation = deposit`;
2. `dataset.result`;
3. `dataset.summary` aggiornato.

#### `POST /bank/withdraw`
Request:
1. `amount` (obbligatorio, > 0).

Response:
1. `dataset.operation = withdraw`;
2. `dataset.result`;
3. `dataset.summary` aggiornato.

#### `POST /bank/transfer`
Request:
1. target accettati: `target_character_id` oppure `recipient_character_id` oppure `to_character_id`;
2. `amount` (obbligatorio, > 0);
3. `note` (opzionale).

Response:
1. `dataset.operation = transfer`;
2. `dataset.result`;
3. `dataset.summary` aggiornato.

### Profilo personaggio (`/profile/*`)

Endpoint principali:
1. `/profile/update`: aggiornamento anagrafica/narrativa;
2. `/profile/availability`: stato disponibilita;
3. `/profile/visibility`: visibilita personaggio (staff con regole gerarchiche);
4. `/profile/settings`: preferenze DM/inviti;
5. `/profile/name-request|loanface-request|identity-request`: richieste moderabili in admin;
6. `/profile/delete` + `/profile/delete/cancel`: cancellazione schedulata;
7. `/profile/relationships/html/get|update`: conoscenze/relazioni html;
8. `/profile/bonds/list|request|request/respond`: legami tra personaggi;
9. `/profile/logs/experience|economy|sessions`: log personali;
10. `/profile/master-notes/update`: note staff;
11. `/profile/health/update`: HP;
12. `/profile/experience/assign`: assegnazione esperienza;
13. `/profile/attributes/list|update-values|recompute`: attributi personaggio core.

Errori ricorrenti:
1. `profile_forbidden`, `availability_invalid`, `dm_policy_invalid`;
2. `character_name_already_used`, `character_name_request_pending`.

### Archetipi (`/character/create`, `/archetypes/*`, `/admin/archetypes/*`)

#### `POST /character/create`
Scopo:
1. creazione personaggio guidata dal runtime archetipi.

Request principali:
1. `name` (obbligatorio);
2. `gender` (obbligatorio);
3. archetipi:
   - `archetype_id` (singolo) oppure
   - `archetype_ids[]` (multipla, se consentita da config).

Regole:
1. `archetypes_enabled` puo disattivare completamente la selezione;
2. `archetype_required` impone selezione obbligatoria;
3. `multiple_archetypes_allowed` abilita multi-selezione;
4. rispetto limite personaggi per account (`multi_character_*`).

Errori:
1. `character_name_required`, `character_creation_failed`;
2. `archetype_required`, `archetype_not_selectable`, `archetype_assignment_failed`;
3. `character_limit_reached`, `character_already_exists`.

#### Game archetypes
1. `/archetypes/list`: catalogo archetipi attivi/selezionabili;
2. `/archetypes/my`: archetipi del personaggio corrente;
3. `/list/archetypes`: alias list per catalogo pubblico.

#### Admin archetypes
1. `/admin/archetypes/config/get|update`: toggle sistema, obbligatorieta, multi-selezione;
2. `/admin/archetypes/list|create|update|delete`: CRUD catalogo;
3. `/admin/archetypes/character/list|assign|remove`: gestione assegnazioni su personaggio.

### Tag narrativi (`/narrative-tags/*`, `/admin/narrative-tags/*`)

#### Game
1. `/list/narrative-tags`: catalogo tag attivi (filtro opzionale `entity_type`);
2. `/narrative-tags/entity`: tag assegnati a specifica entita (`entity_type`, `entity_id`).

#### Admin catalogo
1. `/admin/narrative-tags/list`: datagrid (`search`, `category`, `is_active`, paginazione/sort);
2. `/admin/narrative-tags/create|update|delete`: CRUD tag.

#### Admin assegnazioni
1. `/admin/narrative-tags/entity/get`: tag correnti entita;
2. `/admin/narrative-tags/entity/sync`: sincronizzazione completa set tag (`tag_ids[]`);
3. `/admin/narrative-tags/entity/search`: ricerca entita per widget (`entity_type`, `query`, `limit`).

Errori:
1. `missing_params`, `missing_id` sui campi obbligatori.

### Endpoint lista/get aggregati

#### `/list/*`
Scopo:
1. endpoint read-only rapidi per popolamento UI (dropdown, datagrid game, sidebar).

Set attuale:
1. `nationalities`, `maps`, `locations`, `items`, `forum-types`, `forum`, `forum/threads`, `news`;
2. `profile/bag`, `onlines`, `onlines/complete`, `characters/search`;
3. `archetypes`, `narrative-tags`.

#### `/get/*`
Scopo:
1. dettaglio rapido singola risorsa.

Set attuale:
1. `weather`, `profile`, `bag`, `forum`, `forum/thread`.

### Meteo e clima (`/weather/*`)

Blocchi endpoint:
1. opzioni e override globali/mondo/luogo:
   - `/weather/options`
   - `/weather/global/set|clear`
   - `/weather/world/options|set|clear`
   - `/weather/location/set|clear`;
2. cataloghi:
   - `/weather/types/*`
   - `/weather/seasons/*`
   - `/weather/zones/*`;
3. climatologia:
   - `/weather/climate-areas`
   - `/weather/climate-areas/create|update|delete|assign`;
4. profili e pesi:
   - `/weather/profiles`
   - `/weather/profiles/upsert|delete`
   - `/weather/profiles/weights`
   - `/weather/profiles/weights/sync`;
5. assegnazioni e override runtime:
   - `/weather/assignments`
   - `/weather/assignments/upsert|delete`
   - `/weather/overrides`
   - `/weather/overrides/upsert|delete`.

Errori ricorrenti:
1. `weather_invalid`, `weather_forbidden`;
2. `temperature_invalid`, `temperature_out_of_range`;
3. `climate_area_invalid`, `climate_area_code_required`, `climate_area_name_required`.

### Stati narrativi (`/narrative-states/*`, `/admin/narrative-states/*`)

#### Game
1. `/narrative-states/catalog`: catalogo stati runtime;
2. `/narrative-states/apply`: applicazione stato (target `character|scene`);
3. `/narrative-states/remove`: rimozione stato.

Campi frequenti apply/remove:
1. `state_id` o `state_code`;
2. `target_type`, `target_id`, `scene_id`;
3. opzionali `intensity`, `duration_value`, `duration_unit`, `reason`.

#### Admin
1. `/admin/narrative-states/list|create|update|delete`: CRUD catalogo.

Campi principali:
1. `code`, `name`, `description`, `category`, `scope`;
2. `stack_mode`, `max_stacks`, `conflict_group`, `priority`;
3. `is_active`, `visible_to_players`, `metadata_json` (tecnico).

### Upload file (`POST /uploader`)

Scopo:
1. upload file autenticato con utente+personaggio validi.

Regole:
1. endpoint protetto da `requireUserCharacter`;
2. usa le policy upload attive in settings (`/admin/settings/upload`).

### Registro eventi, eventi di sistema, quest, lifecycle, fazioni

#### Registro eventi (`/narrative-events/*`)
1. game: `/narrative-events/list|get`;
2. admin: `/admin/narrative-events/list|get|create|update|attach|delete`.

#### Eventi di sistema (`/system-events/*`, `/admin/system-events/*`)
1. game: `list|get|participation/join|participation/leave`;
2. admin:
   - CRUD evento: `list|get|create|update|delete|status/set`;
   - effetti: `effects/list|upsert|delete`;
   - partecipazioni: `participations/list|upsert|remove`;
   - ricompense: `rewards/assign|log`;
   - manutenzione: `maintenance/run`.

#### Quest (`/quests/*`, `/admin/quests/*`)
1. game: `list|get|history/list|history/get|participation/join|participation/leave`;
2. staff in-game:
   - `staff/instances/list`
   - `staff/step/confirm`
   - `staff/instance/status-set`
   - `staff/instance/force-progress`
   - `staff/closure/get`
   - `staff/closure/finalize`;
3. admin completo:
   - definizioni: `definitions/*`
   - step: `steps/*`
   - condizioni: `conditions/*`
   - outcome: `outcomes/*`
   - istanze: `instances/*`
   - chiusure: `closures/*`
   - ricompense: `rewards/*`
   - link: `links/*`
   - log: `logs/list`
   - manutenzione: `maintenance/run`.

#### Lifecycle (`/lifecycle/*`, `/admin/lifecycle/*`)
1. game: `current`, `history`;
2. admin fasi: `phases/list|create|update|delete`;
3. admin personaggi: `characters/current|history|transition`.

#### Fazioni (`/factions/*`, `/admin/factions/*`)
1. game: `list|get|my`;
2. admin fazioni: `list|get|create|update|delete`;
3. admin membri: `members/list|add|update|remove`;
4. admin relazioni: `relations/list|set|remove`.

### Notifiche (`/notifications/*`)

#### `POST /notifications/list`
Request principali:
1. `page`, `results`;
2. `unread_only` (`0|1`);
3. `pending_only` (`0|1`);
4. `kind` (opzionale).

Response:
1. dataset paginato notifiche per utente/character corrente.

#### `POST /notifications/read`
Request:
1. `notification_id` o `id`.

Response:
1. esito mark-read.

#### `POST /notifications/read-delete`
Scopo:
1. segna letta e rimuove notifica.

#### `POST /notifications/delete`
Scopo:
1. elimina notifica senza marcatura lettura.

#### `POST /notifications/read-all`
Scopo:
1. segna lette tutte le notifiche correnti, con filtri opzionali (`kind`, `pending_only`).

#### `POST /notifications/respond`
Scopo:
1. risposta ad azione pendente (`decision`).

Request:
1. `notification_id` o `id`;
2. `decision` (obbligatorio per notifiche azionabili).

#### `POST /notifications/unread-count`
Response:
1. `unread_count`.

Errori:
1. `notification_invalid`;
2. `character_invalid` quando la risposta richiede personaggio valido.

### Admin: dettagli operativi per famiglia

#### Utenti, personaggi, blacklist
1. utenti:
   - `users/list`: datagrid (`query.search`, `query.status`, `page`, `results`, `orderBy`);
   - `users/reset-password`: `user_id`;
   - `users/permissions`: `user_id`, `is_administrator`, `is_moderator`, `is_master`;
   - `users/disconnect`: `user_id`;
   - `users/restrict`: `user_id`, `is_restricted`;
2. personaggi:
   - `characters/list|get`;
   - log: `characters/logs/experience|economy|sessions`;
3. blacklist:
   - `blacklist/list|create|update|delete`.

#### Cataloghi contenuto/economia
1. items: `list|types|rarities/*|create|update|delete`;
2. equipment slots: `list|create|update|delete`;
3. regole equip: `item-equipment-rules/list|create|update|delete`;
4. categorie: `categories/list|create|update|delete`;
5. valute: `currencies/list|create|update|delete`;
6. negozi: `shops/list|create|update|delete`;
7. inventario negozi: `inventory/list|create|update|delete`.

#### Geografia e contenuti testuali
1. mappe: `maps/list|create|update|delete`;
2. luoghi: `locations/list|create|edit|update|delete`;
3. storyboards/rules/how-to-play/news: endpoint CRUD list/create/update/delete.

#### Social status e richieste identita
1. social status catalogo: `social-status/admin-list|create|update|delete`;
2. social status personaggio: `characters/social-status`, `social-status/list`;
3. richieste: `characters/name-requests/list`, `loanface-requests/list`, `identity-requests/list`;
4. decisioni/modifiche: `name-request/decide`, `loanface-request/decide`, `identity-request/decide`;
5. editing staff diretto: `admin-edit-identity|narrative|stats|economy|notes`.

#### Lavori e gilde admin
1. jobs: `jobs/*`, `jobs-levels/*`, `jobs-tasks/*`;
2. gilde:
   - cataloghi: `guild-alignments/*`, `guild-roles/*`, `guild-role-scopes/*`, `guild-role-locations/*`, `guild-requirements/*`;
   - gestione gilda: `guilds/*`, `guilds/admin-*`, `guilds/roles-*`, `guilds/events-*`.

#### Forum admin
1. forum: `forums/list|types-list|create|update|delete`;
2. tipi forum: `forum-types/list|create|update|delete`.

#### Settings admin
1. `settings/get|update`: configurazioni applicative core;
2. `settings/upload`: limiti/policy upload file.

#### Moduli (solo orchestrazione core)
1. `modules/list`: stato, compatibilita, dipendenze;
2. `modules/activate`: attivazione con validazione dipendenze/compat;
3. `modules/deactivate`: disattivazione con eventuale cascata;
4. `modules/uninstall`: disinstallazione `safe|purge`;
5. `modules/audit`: verifica residui/moduli incoerenti.

Nota:
1. il contratto API core include solo questi endpoint moduli;
2. le API funzionali dei moduli opzionali vanno in documentazione modulo dedicata.

#### Attributi personaggio admin
1. settings: `character-attributes/settings/get|update`;
2. definizioni: `definitions/list|create|update|deactivate|reorder`;
3. regole: `rules/get|upsert|delete`;
4. ricalcolo: `recompute`.

#### Log amministrativi
1. `logs/conflicts/list`
2. `logs/currency/list`
3. `logs/experience/list`
4. `logs/fame/list`
5. `logs/guild/list`
6. `logs/job/list`
7. `logs/location-access/list`
8. `logs/sys/list`

Tutti espongono datagrid con filtri/paginazione/sort.

#### Archetipi admin
1. `archetypes/config/get|update`;
2. `archetypes/list|create|update|delete`;
3. `archetypes/character/list|assign|remove`.

#### Segnalazioni messaggi admin
1. `message-reports/list`:
   - filtri: `status`, `priority`, `location_id`, `reported_character_id`, `reporter_character_id`;
2. `message-reports/get`: `report_id`;
3. `message-reports/update-status`: `report_id`, `status`, opzionali `review_note`, `resolution_code`;
4. `message-reports/assign`: `report_id`, opzionale `assigned_to_user_id`.

#### Tag narrativi admin
1. catalogo: `narrative-tags/list|create|update|delete`;
2. assegnazioni: `narrative-tags/entity/get|sync|search`.

#### Conflitti admin
1. settings: `admin/conflicts/settings/get|update`;
2. azioni staff: `admin/conflicts/force-open|force-close|edit-log|override-roll`.

#### Eventi di sistema admin
1. CRUD e lifecycle: `list|get|create|update|delete|status/set`;
2. effetti: `effects/list|upsert|delete`;
3. partecipazioni: `participations/list|upsert|remove`;
4. reward: `rewards/assign|log`;
5. manutenzione: `maintenance/run`.

#### Quest admin
1. definizioni: `definitions/*`;
2. step: `steps/*`;
3. condizioni: `conditions/*`;
4. outcome: `outcomes/*`;
5. istanze: `instances/*`;
6. chiusure: `closures/*`;
7. ricompense: `rewards/*`;
8. link: `links/*`;
9. log/manutenzione: `logs/list`, `maintenance/run`.

## Stabilita del documento
1. Questo file rappresenta il riferimento pubblico dei contratti API core correnti.
2. Ogni modifica a endpoint, payload o `error_code` core richiede aggiornamento contestuale di questo documento.
