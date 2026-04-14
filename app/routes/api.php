<?php

use Core\AuthGuard;
use Core\UploadManager;

/** @var \Core\Router $route */
$route->apiPost('/signin', 'Users@signin');
$route->apiPost('/signup', 'Users@signup');
$route->apiPost('/signin/characters/list', 'Users@signinCharactersList');
$route->apiPost('/signin/character/select', 'Users@signinCharacterSelect');
$route->apiPost('/signout', 'Users@signout');
$route->apiPost('/forgot-password', 'Users@resetPassword');
$route->apiPost('/reset-password', 'Users@resetPasswordConfirm');

$route->group('/message', function ($route) {
    $route->apiPost('/threads', 'Messages@threads');
    $route->apiPost('/thread', 'Messages@thread');
    $route->apiPost('/send', 'Messages@send');
    $route->apiPost('/unread', 'Messages@unread');
    $route->apiPost('/delete-thread', 'Messages@deleteThread');
    $route->apiPost('/reports/create', 'MessageReports@create');
});

$route->group('/jobs', function ($route) {
    $route->apiPost('/available', 'Jobs@available');
    $route->apiPost('/assign', 'Jobs@assign');
    $route->apiPost('/leave', 'Jobs@leave');
    $route->apiPost('/current', 'Jobs@current');
    $route->apiPost('/task/complete', 'Jobs@completeTask');
});

$route->group('/guilds', function ($route) {
    $route->apiPost('/list', 'Guilds@publicList');
    $route->apiPost('/character/list', 'Guilds@characterGuilds');
    $route->apiPost('/get', 'Guilds@get');
    $route->apiPost('/apply', 'Guilds@apply');
    $route->apiPost('/members', 'Guilds@members');
    $route->apiPost('/roles', 'Guilds@roles');
    $route->apiPost('/requirements/options', 'Guilds@requirementsOptions');
    $route->apiPost('/requirements/upsert', 'Guilds@requirementsUpsert');
    $route->apiPost('/requirements/delete', 'Guilds@requirementsDelete');
    $route->apiPost('/applications', 'Guilds@applications');
    $route->apiPost('/application/decide', 'Guilds@decideApplication');
    $route->apiPost('/kick/request', 'Guilds@requestKick');
    $route->apiPost('/kick/decide', 'Guilds@decideKick');
    $route->apiPost('/kick', 'Guilds@directKick');
    $route->apiPost('/promote', 'Guilds@promote');
    $route->apiPost('/salary/claim', 'Guilds@claimSalary');
    $route->apiPost('/primary', 'Guilds@setPrimary');
    $route->apiPost('/logs', 'Guilds@logs');
    $route->apiPost('/announcements', 'Guilds@announcements');
    $route->apiPost('/announcement/create', 'Guilds@announcementCreate');
    $route->apiPost('/announcement/delete', 'Guilds@announcementDelete');
    $route->apiPost('/events', 'Guilds@events');
    $route->apiPost('/event/create', 'Guilds@eventCreate');
    $route->apiPost('/event/delete', 'Guilds@eventDelete');
});

$route->group('/location', function ($route) {
    $route->apiPost('/invites', 'Locations@invites');
    $route->apiPost('/invite', 'Locations@invite');
    $route->apiPost('/invite/respond', 'Locations@respondInvite');
    $route->apiPost('/invite/owner-updates', 'Locations@inviteUpdates');
    $route->apiPost('/messages/list', 'LocationMessages@list');
    $route->apiPost('/messages/send', 'LocationMessages@send');
    $route->apiPost('/whispers/list', 'LocationMessages@whispers');
    $route->apiPost('/whispers/threads', 'LocationMessages@whispersThreads');
    $route->apiPost('/whispers/unread', 'LocationMessages@whispersUnread');
    $route->apiPost('/whispers/send', 'LocationMessages@whisper');
    $route->apiPost('/whispers/policy', 'LocationMessages@whisperPolicy');
    $route->apiPost('/drops/list', 'LocationDrops@list');
    $route->apiPost('/drops/drop', 'LocationDrops@drop');
    $route->apiPost('/drops/pick', 'LocationDrops@pickup');
});

$route->group('/shop', function ($route) {
    $route->apiPost('/sellables', 'Shop@sellables');
    $route->apiPost('/sell', 'Shop@sell');
    $route->apiPost('/items', 'Shop@items');
    $route->apiPost('/buy', 'Shop@buy');
});

$route->group('/bank', function ($route) {
    $route->apiPost('/summary', 'Bank@summary');
    $route->apiPost('/deposit', 'Bank@deposit');
    $route->apiPost('/withdraw', 'Bank@withdraw');
    $route->apiPost('/transfer', 'Bank@transfer');
});

$route->group('/admin', function ($route) {
    $route->apiPost('/dashboard/summary', 'Dashboard@dashboardSummary');

    $route->apiPost('/users/list', 'Users@adminList');
    $route->apiPost('/users/reset-password', 'Users@adminResetPassword');
    $route->apiPost('/users/permissions', 'Users@adminSetPermissions');
    $route->apiPost('/users/disconnect', 'Users@adminDisconnect');
    $route->apiPost('/users/restrict', 'Users@adminSetRestriction');
    $route->apiPost('/characters/list', 'Characters@adminList');
    $route->apiPost('/characters/get', 'Characters@adminGet');
    $route->apiPost('/characters/logs/experience', 'Characters@adminExperienceLogs');
    $route->apiPost('/characters/logs/economy', 'Characters@adminEconomyLogs');
    $route->apiPost('/characters/logs/sessions', 'Characters@adminSessionLogs');
    $route->apiPost('/blacklist/list', 'Blacklist@list');
    $route->apiPost('/blacklist/create', 'Blacklist@create');
    $route->apiPost('/blacklist/update', 'Blacklist@update');
    $route->apiPost('/blacklist/delete', 'Blacklist@delete');

    $route->apiPost('/items/list', 'Items@adminList');
    $route->apiPost('/items/types', 'Items@adminTypesList');
    $route->apiPost('/items/rarities/list', 'Items@adminRaritiesList');
    $route->apiPost('/items/rarities/admin-list', 'Items@adminRaritiesAdminList');
    $route->apiPost('/items/rarities/create', 'Items@adminRarityCreate');
    $route->apiPost('/items/rarities/update', 'Items@adminRarityUpdate');
    $route->apiPost('/items/rarities/delete', 'Items@adminRarityDelete');
    $route->apiPost('/items/create', 'Items@create');
    $route->apiPost('/items/update', 'Items@update');
    $route->apiPost('/items/delete', 'Items@adminDelete');

    $route->apiPost('/equipment-slots/list', 'EquipmentSlots@list');
    $route->apiPost('/equipment-slots/create', 'EquipmentSlots@create');
    $route->apiPost('/equipment-slots/update', 'EquipmentSlots@update');
    $route->apiPost('/equipment-slots/delete', 'EquipmentSlots@delete');

    $route->apiPost('/item-equipment-rules/list', 'ItemEquipmentRules@list');
    $route->apiPost('/item-equipment-rules/create', 'ItemEquipmentRules@create');
    $route->apiPost('/item-equipment-rules/update', 'ItemEquipmentRules@update');
    $route->apiPost('/item-equipment-rules/delete', 'ItemEquipmentRules@delete');

    $route->apiPost('/categories/list', 'ItemsCategories@list');
    $route->apiPost('/categories/create', 'ItemsCategories@create');
    $route->apiPost('/categories/update', 'ItemsCategories@update');
    $route->apiPost('/categories/delete', 'ItemsCategories@delete');

    $route->apiPost('/currencies/list', 'Currencies@list');
    $route->apiPost('/currencies/create', 'Currencies@create');
    $route->apiPost('/currencies/update', 'Currencies@update');
    $route->apiPost('/currencies/delete', 'Currencies@delete');

    $route->apiPost('/shops/list', 'Shops@list');
    $route->apiPost('/shops/create', 'Shops@create');
    $route->apiPost('/shops/update', 'Shops@update');
    $route->apiPost('/shops/delete', 'Shops@delete');

    $route->apiPost('/inventory/list', 'ShopInventories@list');
    $route->apiPost('/inventory/create', 'ShopInventories@create');
    $route->apiPost('/inventory/update', 'ShopInventories@update');
    $route->apiPost('/inventory/delete', 'ShopInventories@delete');

    $route->apiPost('/maps/list', 'Maps@adminList');
    $route->apiPost('/maps/create', 'Maps@create');
    $route->apiPost('/maps/update', 'Maps@update');
    $route->apiPost('/maps/delete', 'Maps@delete');

    $route->apiPost('/locations/list', 'Locations@adminList');
    $route->apiPost('/locations/get', 'Locations@adminGet');
    $route->apiPost('/locations/create', 'Locations@adminCreate');
    $route->apiPost('/locations/edit', 'Locations@adminEdit');
    $route->apiPost('/locations/delete', 'Locations@adminDelete');
    $route->apiPost('/locations/update', 'Locations@adminUpdate');

    $route->apiPost('/storyboards/list', 'Storyboards@list');
    $route->apiPost('/storyboards/create', 'Storyboards@create');
    $route->apiPost('/storyboards/update', 'Storyboards@update');
    $route->apiPost('/storyboards/delete', 'Storyboards@delete');

    $route->apiPost('/rules/list', 'Rules@list');
    $route->apiPost('/rules/create', 'Rules@create');
    $route->apiPost('/rules/update', 'Rules@update');
    $route->apiPost('/rules/delete', 'Rules@delete');

    $route->apiPost('/how-to-play/list', 'HowToPlays@list');
    $route->apiPost('/how-to-play/create', 'HowToPlays@create');
    $route->apiPost('/how-to-play/update', 'HowToPlays@update');
    $route->apiPost('/how-to-play/delete', 'HowToPlays@delete');

    $route->apiPost('/characters/social-status', 'Characters@setSocialStatus');
    $route->apiPost('/social-status/list', 'Characters@listSocialStatus');
    $route->apiPost('/social-status/admin-list', 'SocialStatuses@adminList');
    $route->apiPost('/social-status/create', 'SocialStatuses@adminCreate');
    $route->apiPost('/social-status/update', 'SocialStatuses@adminUpdate');
    $route->apiPost('/social-status/delete', 'SocialStatuses@adminDelete');
    $route->apiPost('/characters/name-requests/list', 'Characters@listNameRequests');
    $route->apiPost('/characters/loanface-requests/list', 'Characters@listLoanfaceRequests');
    $route->apiPost('/characters/identity-requests/list', 'Characters@listIdentityRequests');
    $route->apiPost('/characters/admin-edit-identity', 'Characters@adminEditIdentity');
    $route->apiPost('/characters/admin-edit-narrative', 'Characters@adminEditNarrative');
    $route->apiPost('/characters/admin-edit-stats', 'Characters@adminEditStats');
    $route->apiPost('/characters/admin-edit-economy', 'Characters@adminEditEconomy');
    $route->apiPost('/characters/admin-edit-notes', 'Characters@adminEditNotes');
    $route->apiPost('/characters/name-request/decide', 'Characters@decideNameChange');
    $route->apiPost('/characters/loanface-request/decide', 'Characters@decideLoanfaceChange');
    $route->apiPost('/characters/identity-request/decide', 'Characters@decideIdentityChange');

    $route->apiPost('/jobs/list', 'Jobs@adminList');
    $route->apiPost('/jobs/create', 'Jobs@adminCreate');
    $route->apiPost('/jobs/update', 'Jobs@adminUpdate');
    $route->apiPost('/jobs/delete', 'Jobs@adminDelete');

    $route->apiPost('/jobs-levels/list', 'Jobs@adminLevelList');
    $route->apiPost('/jobs-levels/create', 'Jobs@adminLevelCreate');
    $route->apiPost('/jobs-levels/update', 'Jobs@adminLevelUpdate');
    $route->apiPost('/jobs-levels/delete', 'Jobs@adminLevelDelete');

    $route->apiPost('/jobs-tasks/list', 'Jobs@adminTaskList');
    $route->apiPost('/jobs-tasks/get', 'Jobs@adminTaskGet');
    $route->apiPost('/jobs-tasks/create', 'Jobs@adminTaskCreate');
    $route->apiPost('/jobs-tasks/update', 'Jobs@adminTaskUpdate');
    $route->apiPost('/jobs-tasks/delete', 'Jobs@adminTaskDelete');

    $route->apiPost('/guild-alignments/list', 'GuildAlignments@list');
    $route->apiPost('/guild-alignments/create', 'GuildAlignments@create');
    $route->apiPost('/guild-alignments/update', 'GuildAlignments@update');
    $route->apiPost('/guild-alignments/delete', 'GuildAlignments@delete');

    $route->apiPost('/guilds/list', 'Guilds@list');
    $route->apiPost('/guilds/create', 'Guilds@create');
    $route->apiPost('/guilds/update', 'Guilds@update');
    $route->apiPost('/guilds/delete', 'Guilds@delete');
    $route->apiPost('/guilds/admin-list', 'Guilds@adminList');
    $route->apiPost('/guilds/admin-create', 'Guilds@adminCreate');
    $route->apiPost('/guilds/admin-update', 'Guilds@adminUpdate');
    $route->apiPost('/guilds/admin-delete', 'Guilds@adminDelete');
    $route->apiPost('/guilds/roles-list', 'Guilds@adminRolesList');
    $route->apiPost('/guilds/roles-create', 'Guilds@adminRoleCreate');
    $route->apiPost('/guilds/roles-update', 'Guilds@adminRoleUpdate');
    $route->apiPost('/guilds/roles-delete', 'Guilds@adminRoleDelete');
    $route->apiPost('/guilds/events-list', 'Guilds@adminEventList');
    $route->apiPost('/guilds/events-create', 'Guilds@adminEventCreate');
    $route->apiPost('/guilds/events-update', 'Guilds@adminEventUpdate');
    $route->apiPost('/guilds/events-delete', 'Guilds@adminEventDelete');

    $route->apiPost('/guild-roles/list', 'GuildRoles@list');
    $route->apiPost('/guild-roles/create', 'GuildRoles@create');
    $route->apiPost('/guild-roles/update', 'GuildRoles@update');
    $route->apiPost('/guild-roles/delete', 'GuildRoles@delete');

    $route->apiPost('/guild-role-scopes/list', 'GuildRoleScopes@list');
    $route->apiPost('/guild-role-scopes/create', 'GuildRoleScopes@create');
    $route->apiPost('/guild-role-scopes/update', 'GuildRoleScopes@update');
    $route->apiPost('/guild-role-scopes/delete', 'GuildRoleScopes@delete');

    $route->apiPost('/guild-requirements/list', 'GuildRequirements@list');
    $route->apiPost('/guild-requirements/create', 'GuildRequirements@create');
    $route->apiPost('/guild-requirements/update', 'GuildRequirements@update');
    $route->apiPost('/guild-requirements/delete', 'GuildRequirements@delete');

    $route->apiPost('/guild-role-locations/list', 'GuildRoleLocations@list');
    $route->apiPost('/guild-role-locations/create', 'GuildRoleLocations@create');
    $route->apiPost('/guild-role-locations/update', 'GuildRoleLocations@update');
    $route->apiPost('/guild-role-locations/delete', 'GuildRoleLocations@delete');

    $route->apiPost('/news/list', 'Novelties@adminList');
    $route->apiPost('/news/create', 'Novelties@create');
    $route->apiPost('/news/update', 'Novelties@update');
    $route->apiPost('/news/delete', 'Novelties@adminDelete');

    $route->apiPost('/forums/list', 'Forums@adminList');
    $route->apiPost('/forums/types-list', 'Forums@adminTypesList');
    $route->apiPost('/forums/create', 'Forums@adminCreate');
    $route->apiPost('/forums/update', 'Forums@adminUpdate');
    $route->apiPost('/forums/delete', 'Forums@adminDelete');

    $route->apiPost('/forum-types/list', 'ForumsTypes@adminList');
    $route->apiPost('/forum-types/create', 'ForumsTypes@adminCreate');
    $route->apiPost('/forum-types/update', 'ForumsTypes@adminUpdate');
    $route->apiPost('/forum-types/delete', 'ForumsTypes@adminDelete');

    $route->apiPost('/settings/upload', 'Settings@updateUpload');
    $route->apiPost('/settings/get', 'Settings@adminGet');
    $route->apiPost('/settings/update', 'Settings@adminUpdate');

    $route->apiPost('/narrative-delegation/capabilities/list', 'NarrativeDelegationAdmin@listCapabilities');
    $route->apiPost('/narrative-delegation/grants/list', 'NarrativeDelegationAdmin@listGrants');
    $route->apiPost('/narrative-delegation/grants/create', 'NarrativeDelegationAdmin@createGrant');
    $route->apiPost('/narrative-delegation/grants/update', 'NarrativeDelegationAdmin@updateGrant');
    $route->apiPost('/narrative-delegation/grants/delete', 'NarrativeDelegationAdmin@deleteGrant');

    $route->apiPost('/narrative-npcs/list', 'NarrativeNpcs@adminList');
    $route->apiPost('/narrative-npcs/create', 'NarrativeNpcs@adminCreate');
    $route->apiPost('/narrative-npcs/update', 'NarrativeNpcs@adminUpdate');
    $route->apiPost('/narrative-npcs/delete', 'NarrativeNpcs@adminDelete');

    $route->apiPost('/modules/list', 'Modules@list');
    $route->apiPost('/modules/activate', 'Modules@activate');
    $route->apiPost('/modules/deactivate', 'Modules@deactivate');
    $route->apiPost('/modules/uninstall', 'Modules@uninstall');
    $route->apiPost('/modules/audit', 'Modules@audit');

    $route->apiPost('/themes/list', 'Themes@list');
    $route->apiPost('/themes/activate', 'Themes@activate');
    $route->apiPost('/themes/deactivate', 'Themes@deactivate');

    $route->apiPost('/character-attributes/settings/get', 'CharacterAttributes@adminSettingsGet');
    $route->apiPost('/character-attributes/settings/update', 'CharacterAttributes@adminSettingsUpdate');
    $route->apiPost('/character-attributes/definitions/list', 'CharacterAttributes@adminDefinitionsList');
    $route->apiPost('/character-attributes/definitions/create', 'CharacterAttributes@adminDefinitionsCreate');
    $route->apiPost('/character-attributes/definitions/update', 'CharacterAttributes@adminDefinitionsUpdate');
    $route->apiPost('/character-attributes/definitions/deactivate', 'CharacterAttributes@adminDefinitionsDeactivate');
    $route->apiPost('/character-attributes/definitions/reorder', 'CharacterAttributes@adminDefinitionsReorder');
    $route->apiPost('/character-attributes/rules/get', 'CharacterAttributes@adminRulesGet');
    $route->apiPost('/character-attributes/rules/upsert', 'CharacterAttributes@adminRulesUpsert');
    $route->apiPost('/character-attributes/rules/delete', 'CharacterAttributes@adminRulesDelete');
    $route->apiPost('/character-attributes/recompute', 'CharacterAttributes@adminRecompute');

    $route->apiPost('/logs/conflicts/list', 'AdminLogs@listConflicts');
    $route->apiPost('/logs/currency/list', 'AdminLogs@listCurrency');
    $route->apiPost('/logs/experience/list', 'AdminLogs@listExperience');
    $route->apiPost('/logs/fame/list', 'AdminLogs@listFame');
    $route->apiPost('/logs/guild/list', 'AdminLogs@listGuild');
    $route->apiPost('/logs/job/list', 'AdminLogs@listJob');
    $route->apiPost('/logs/location-access/list', 'AdminLogs@listLocationAccess');
    $route->apiPost('/logs/sys/list', 'AdminLogs@listSys');
    $route->apiPost('/logs/narrative/list', 'AdminLogs@listNarrative');

    $route->apiPost('/archetypes/list', 'Archetypes@adminList');
    $route->apiPost('/archetypes/create', 'Archetypes@adminCreate');
    $route->apiPost('/archetypes/update', 'Archetypes@adminUpdate');
    $route->apiPost('/archetypes/delete', 'Archetypes@adminDelete');
    $route->apiPost('/archetypes/config/get', 'Archetypes@adminConfigGet');
    $route->apiPost('/archetypes/config/update', 'Archetypes@adminConfigUpdate');
    $route->apiPost('/archetypes/character/list', 'Archetypes@adminCharacterArchetypes');
    $route->apiPost('/archetypes/character/assign', 'Archetypes@adminAssignArchetype');
    $route->apiPost('/archetypes/character/remove', 'Archetypes@adminRemoveArchetype');

    $route->apiPost('/message-reports/list', 'MessageReports@adminList');
    $route->apiPost('/message-reports/get', 'MessageReports@adminGet');
    $route->apiPost('/message-reports/update-status', 'MessageReports@adminUpdateStatus');
    $route->apiPost('/message-reports/assign', 'MessageReports@adminAssign');

    $route->apiPost('/narrative-tags/list', 'NarrativeTags@adminList');
    $route->apiPost('/narrative-tags/create', 'NarrativeTags@adminCreate');
    $route->apiPost('/narrative-tags/update', 'NarrativeTags@adminUpdate');
    $route->apiPost('/narrative-tags/delete', 'NarrativeTags@adminDelete');
    $route->apiPost('/narrative-tags/entity/get', 'NarrativeTags@adminEntityTags');
    $route->apiPost('/narrative-tags/entity/sync', 'NarrativeTags@adminSyncTags');
    $route->apiPost('/narrative-tags/entity/search', 'NarrativeTags@adminSearchEntities');
});

$route->group('/settings', function ($route) {
    $route->apiPost('/upload', 'Settings@upload');
    $route->apiPost('/password', 'Users@changePassword');
    $route->apiPost('/sessions/revoke', 'Users@revokeSessions');
});

$route->group('/events', function ($route) {
    $route->apiPost('/list', 'CharacterEvents@list');
    $route->apiPost('/create', 'CharacterEvents@create');
    $route->apiPost('/update', 'CharacterEvents@update');
    $route->apiPost('/delete', 'CharacterEvents@delete');
});

$route->group('/conflicts', function ($route) {
    $route->apiPost('/list', 'Conflicts@list');
    $route->apiPost('/get', 'Conflicts@get');
    $route->apiPost('/open', 'Conflicts@open');
    $route->apiPost('/propose', 'Conflicts@propose');
    $route->apiPost('/proposal/respond', 'Conflicts@proposalRespond');
    $route->apiPost('/location/feed', 'Conflicts@locationFeed');
    $route->apiPost('/participants/upsert', 'Conflicts@participantsUpsert');
    $route->apiPost('/action/add', 'Conflicts@actionAdd');
    $route->apiPost('/action/execute', 'Conflicts@actionExecute');
    $route->apiPost('/status/set', 'Conflicts@statusSet');
    $route->apiPost('/roll', 'Conflicts@roll');
    $route->apiPost('/resolve', 'Conflicts@resolve');
    $route->apiPost('/close', 'Conflicts@close');
});

$route->group('/admin/conflicts/settings', function ($route) {
    $route->apiPost('/get', 'Conflicts@settingsGet');
    $route->apiPost('/update', 'Conflicts@settingsUpdate');
});

$route->group('/admin/conflicts', function ($route) {
    $route->apiPost('/force-open', 'Conflicts@forceOpen');
    $route->apiPost('/force-close', 'Conflicts@forceClose');
    $route->apiPost('/edit-log', 'Conflicts@editLog');
    $route->apiPost('/override-roll', 'Conflicts@overrideRoll');
});

$route->group('/inventory', function ($route) {
    $route->apiPost('/slots', 'Inventory@slots');
    $route->apiPost('/available', 'Inventory@available');
    $route->apiPost('/equipped', 'Inventory@equipped');
    $route->apiPost('/equip', 'Inventory@equip');
    $route->apiPost('/unequip', 'Inventory@unequip');
    $route->apiPost('/destroy', 'Inventory@destroy');
    $route->apiPost('/swap', 'Inventory@swap');
    $route->apiPost('/maintenance', 'Inventory@maintainItem');
    $route->apiPost('/categories', 'Inventory@categories');
});

$route->group('/items', function ($route) {
    $route->apiPost('/use', 'Inventory@useItem');
    $route->apiPost('/reload', 'Inventory@reloadItem');
});

$route->group('/profile', function ($route) {
    $route->apiPost('/update', 'Characters@updateProfile');
    $route->apiPost('/availability', 'Characters@setAvailability');
    $route->apiPost('/visibility', 'Characters@setVisibility');
    $route->apiPost('/settings', 'Characters@updateSettings');
    $route->apiPost('/name-request', 'Characters@requestNameChange');
    $route->apiPost('/loanface-request', 'Characters@requestLoanfaceChange');
    $route->apiPost('/identity-request', 'Characters@requestIdentityChange');
    $route->apiPost('/delete', 'Characters@requestDelete');
    $route->apiPost('/delete/cancel', 'Characters@cancelDelete');
    $route->apiPost('/relationships/html/get', 'Characters@getFriendsKnowledgeHtml');
    $route->apiPost('/relationships/html/update', 'Characters@updateFriendsKnowledgeHtml');
    $route->apiPost('/bonds/list', 'Characters@listBonds');
    $route->apiPost('/bonds/request', 'Characters@requestBond');
    $route->apiPost('/bonds/request/respond', 'Characters@respondBondRequest');
    $route->apiPost('/logs/experience', 'Characters@experienceLogs');
    $route->apiPost('/logs/economy', 'Characters@economyLogs');
    $route->apiPost('/logs/sessions', 'Characters@sessionLogs');
    $route->apiPost('/master-notes/update', 'Characters@updateMasterNotes');
    $route->apiPost('/health/update', 'Characters@updateHealth');
    $route->apiPost('/experience/assign', 'Characters@assignExperience');
    $route->apiPost('/attributes/list', 'CharacterAttributes@profileList');
    $route->apiPost('/attributes/update-values', 'CharacterAttributes@profileUpdateValues');
    $route->apiPost('/attributes/recompute', 'CharacterAttributes@profileRecompute');
});
$route->apiPost('/character/create', 'Archetypes@createCharacter');

$route->group('/archetypes', function ($route) {
    $route->apiPost('/list', 'Archetypes@publicList');
    $route->apiPost('/my', 'Archetypes@characterArchetypes');
});

$route->group('/narrative-tags', function ($route) {
    $route->apiPost('/entity', 'NarrativeTags@entityTags');
});

$route->group('/list', function ($route) {
    $route->apiPost('/nationalities', 'Nationalities@list');
    $route->apiPost('/archetypes', 'Archetypes@publicList');
    $route->apiPost('/narrative-tags', 'NarrativeTags@publicList');
    $route->apiPost('/maps', 'Maps@list');
    $route->apiPost('/locations', 'Locations@list');
    $route->apiPost('/profile/bag', 'Inventory@bag');
    $route->apiPost('/onlines', 'Characters@onlines');
    $route->apiPost('/onlines/complete', 'Characters@onlinesComplete');
    $route->apiPost('/characters/search', 'Characters@search');
    $route->apiPost('/forum-types', 'ForumsTypes@list');
    $route->apiPost('/forum', 'Forums@list');
    $route->apiPost('/news', 'Novelties@list');
    $route->apiPost('/forum/threads', 'Threads@list');
    $route->apiPost('/items', 'Items@list');
});

$route->group('/get', function ($route) {
    $route->apiPost('/weather', 'Weathers@weather');
    $route->apiPost('/profile', 'Characters@getByID');
    $route->apiPost('/bag', 'Characters@getByID');
    $route->apiPost('/forum', 'Forums@getByID');
    $route->apiPost('/forum/thread', 'Threads@getByID');
});

$route->group('/weather', function ($route) {
    $route->apiPost('/options', 'Weathers@options');
    $route->apiPost('/global/set', 'Weathers@setGlobal');
    $route->apiPost('/global/clear', 'Weathers@clearGlobal');
    $route->apiPost('/world/options', 'Weathers@worldOptions');
    $route->apiPost('/world/set', 'Weathers@setWorld');
    $route->apiPost('/world/clear', 'Weathers@clearWorld');
    $route->apiPost('/location/set', 'Weathers@setLocation');
    $route->apiPost('/location/clear', 'Weathers@clearLocation');
    // Climate area management (requires settings.manage)
    $route->apiPost('/climate-areas', 'Weathers@climateAreaList');
    $route->apiPost('/climate-areas/create', 'Weathers@climateAreaCreate');
    $route->apiPost('/climate-areas/update', 'Weathers@climateAreaUpdate');
    $route->apiPost('/climate-areas/delete', 'Weathers@climateAreaDelete');
    $route->apiPost('/climate-areas/assign', 'Weathers@climateAreaAssign');

    // Weather & Climate v2 core domain
    $route->apiPost('/types', 'Weathers@weatherTypeList');
    $route->apiPost('/types/create', 'Weathers@weatherTypeCreate');
    $route->apiPost('/types/update', 'Weathers@weatherTypeUpdate');
    $route->apiPost('/types/delete', 'Weathers@weatherTypeDelete');

    $route->apiPost('/seasons', 'Weathers@seasonList');
    $route->apiPost('/seasons/create', 'Weathers@seasonCreate');
    $route->apiPost('/seasons/update', 'Weathers@seasonUpdate');
    $route->apiPost('/seasons/delete', 'Weathers@seasonDelete');

    $route->apiPost('/zones', 'Weathers@climateZoneList');
    $route->apiPost('/zones/create', 'Weathers@climateZoneCreate');
    $route->apiPost('/zones/update', 'Weathers@climateZoneUpdate');
    $route->apiPost('/zones/delete', 'Weathers@climateZoneDelete');

    $route->apiPost('/profiles', 'Weathers@profileList');
    $route->apiPost('/profiles/upsert', 'Weathers@profileUpsert');
    $route->apiPost('/profiles/delete', 'Weathers@profileDelete');
    $route->apiPost('/profiles/weights', 'Weathers@profileWeightsList');
    $route->apiPost('/profiles/weights/sync', 'Weathers@profileWeightsSync');

    $route->apiPost('/assignments', 'Weathers@assignmentList');
    $route->apiPost('/assignments/upsert', 'Weathers@assignmentUpsert');
    $route->apiPost('/assignments/delete', 'Weathers@assignmentDelete');

    $route->apiPost('/overrides', 'Weathers@weatherOverrideList');
    $route->apiPost('/overrides/upsert', 'Weathers@weatherOverrideUpsert');
    $route->apiPost('/overrides/delete', 'Weathers@weatherOverrideDelete');
});

$route->group('/narrative-states', function ($route) {
    $route->apiPost('/catalog', 'NarrativeStates@catalog');
    $route->apiPost('/apply', 'NarrativeStates@apply');
    $route->apiPost('/remove', 'NarrativeStates@remove');
    $route->apiPost('/my-states', 'NarrativeStates@myStates');
});

$route->group('/admin/narrative-states', function ($route) {
    $route->apiPost('/list', 'NarrativeStates@adminList');
    $route->apiPost('/create', 'NarrativeStates@create');
    $route->apiPost('/update', 'NarrativeStates@update');
    $route->apiPost('/delete', 'NarrativeStates@adminDelete');
});

$route->apiPost('/uploader', function () {
    AuthGuard::api()->requireUserCharacter();

    return UploadManager::execute();
});

// -------------------------------------------------------------------------
// Core narrative domain
// -------------------------------------------------------------------------

$route->apiPost('/narrative-npcs/list', 'NarrativeNpcs@publicList');

$route->group('/narrative-events', function ($route) {
    $route->apiPost('/list', 'NarrativeEvents@list');
    $route->apiPost('/get', 'NarrativeEvents@get');
    $route->apiPost('/create', 'NarrativeEvents@gameCreate');
    $route->apiPost('/close', 'NarrativeEvents@gameClose');
    $route->apiPost('/scenes', 'NarrativeEvents@gameListScenes');
    $route->apiPost('/capabilities', 'NarrativeEvents@gameCapabilities');
    $route->apiPost('/locations', 'NarrativeEvents@gameLocationsList');
});

$route->group('/narrative-ephemeral-npcs', function ($route) {
    $route->apiPost('/spawn', 'NarrativeEphemeralNpcs@spawn');
    $route->apiPost('/list', 'NarrativeEphemeralNpcs@list');
    $route->apiPost('/delete', 'NarrativeEphemeralNpcs@delete');
});

$route->group('/admin/narrative-events', function ($route) {
    $route->apiPost('/list', 'NarrativeEvents@adminList');
    $route->apiPost('/get', 'NarrativeEvents@adminGet');
    $route->apiPost('/create', 'NarrativeEvents@adminCreate');
    $route->apiPost('/update', 'NarrativeEvents@adminUpdate');
    $route->apiPost('/attach', 'NarrativeEvents@adminAttach');
    $route->apiPost('/delete', 'NarrativeEvents@adminDelete');
    $route->apiPost('/tags/set', 'NarrativeEvents@adminTagsSet');
});

$route->group('/system-events', function ($route) {
    $route->apiPost('/list', 'SystemEvents@list');
    $route->apiPost('/get', 'SystemEvents@get');
    $route->apiPost('/participation/join', 'SystemEvents@participationJoin');
    $route->apiPost('/participation/leave', 'SystemEvents@participationLeave');
});

$route->group('/quests', function ($route) {
    $route->apiPost('/list', 'Quests@list');
    $route->apiPost('/get', 'Quests@get');
    $route->apiPost('/history/list', 'Quests@historyList');
    $route->apiPost('/history/get', 'Quests@historyGet');
    $route->apiPost('/participation/join', 'Quests@participationJoin');
    $route->apiPost('/participation/leave', 'Quests@participationLeave');
    $route->apiPost('/staff/instances/list', 'Quests@staffInstancesList');
    $route->apiPost('/staff/step/confirm', 'Quests@staffStepConfirm');
    $route->apiPost('/staff/instance/status-set', 'Quests@staffInstanceStatusSet');
    $route->apiPost('/staff/instance/force-progress', 'Quests@staffInstanceForceProgress');
    $route->apiPost('/staff/closure/get', 'Quests@staffClosureGet');
    $route->apiPost('/staff/closure/finalize', 'Quests@staffClosureFinalize');
});

$route->group('/admin/system-events', function ($route) {
    $route->apiPost('/list', 'AdminSystemEvents@list');
    $route->apiPost('/get', 'AdminSystemEvents@get');
    $route->apiPost('/create', 'AdminSystemEvents@create');
    $route->apiPost('/update', 'AdminSystemEvents@update');
    $route->apiPost('/delete', 'AdminSystemEvents@delete');
    $route->apiPost('/status/set', 'AdminSystemEvents@statusSet');
    $route->apiPost('/effects/list', 'AdminSystemEvents@effectsList');
    $route->apiPost('/effects/upsert', 'AdminSystemEvents@effectsUpsert');
    $route->apiPost('/effects/delete', 'AdminSystemEvents@effectsDelete');
    $route->apiPost('/participations/list', 'AdminSystemEvents@participationsList');
    $route->apiPost('/participations/upsert', 'AdminSystemEvents@participationsUpsert');
    $route->apiPost('/participations/remove', 'AdminSystemEvents@participationsRemove');
    $route->apiPost('/rewards/assign', 'AdminSystemEvents@rewardsAssign');
    $route->apiPost('/rewards/log', 'AdminSystemEvents@rewardsLog');
    $route->apiPost('/maintenance/run', 'AdminSystemEvents@maintenanceRun');
});

$route->group('/admin/quests', function ($route) {
    $route->apiPost('/definitions/list', 'AdminQuests@definitionsList');
    $route->apiPost('/definitions/create', 'AdminQuests@definitionsCreate');
    $route->apiPost('/definitions/update', 'AdminQuests@definitionsUpdate');
    $route->apiPost('/definitions/publish', 'AdminQuests@definitionsPublish');
    $route->apiPost('/definitions/archive', 'AdminQuests@definitionsArchive');
    $route->apiPost('/definitions/delete', 'AdminQuests@definitionsDelete');
    $route->apiPost('/definitions/reorder', 'AdminQuests@definitionsReorder');
    $route->apiPost('/steps/list', 'AdminQuests@stepsList');
    $route->apiPost('/steps/upsert', 'AdminQuests@stepsUpsert');
    $route->apiPost('/steps/delete', 'AdminQuests@stepsDelete');
    $route->apiPost('/steps/reorder', 'AdminQuests@stepsReorder');
    $route->apiPost('/conditions/list', 'AdminQuests@conditionsList');
    $route->apiPost('/conditions/upsert', 'AdminQuests@conditionsUpsert');
    $route->apiPost('/conditions/delete', 'AdminQuests@conditionsDelete');
    $route->apiPost('/outcomes/list', 'AdminQuests@outcomesList');
    $route->apiPost('/outcomes/upsert', 'AdminQuests@outcomesUpsert');
    $route->apiPost('/outcomes/delete', 'AdminQuests@outcomesDelete');
    $route->apiPost('/instances/list', 'AdminQuests@instancesList');
    $route->apiPost('/instances/get', 'AdminQuests@instancesGet');
    $route->apiPost('/instances/assign', 'AdminQuests@instancesAssign');
    $route->apiPost('/instances/status/set', 'AdminQuests@instancesStatusSet');
    $route->apiPost('/instances/step/set', 'AdminQuests@instancesStepSet');
    $route->apiPost('/closures/list', 'AdminQuests@closuresList');
    $route->apiPost('/closures/get', 'AdminQuests@closuresGet');
    $route->apiPost('/closures/upsert', 'AdminQuests@closuresUpsert');
    $route->apiPost('/rewards/list', 'AdminQuests@rewardsList');
    $route->apiPost('/rewards/assign', 'AdminQuests@rewardsAssign');
    $route->apiPost('/rewards/remove', 'AdminQuests@rewardsRemove');
    $route->apiPost('/links/list', 'AdminQuests@linksList');
    $route->apiPost('/links/upsert', 'AdminQuests@linksUpsert');
    $route->apiPost('/links/delete', 'AdminQuests@linksDelete');
    $route->apiPost('/logs/list', 'AdminQuests@logsList');
    $route->apiPost('/maintenance/run', 'AdminQuests@maintenanceRun');
});

$route->group('/lifecycle', function ($route) {
    $route->apiPost('/current', 'CharacterLifecycle@currentPhase');
    $route->apiPost('/history', 'CharacterLifecycle@history');
});

$route->group('/admin/lifecycle', function ($route) {
    $route->apiPost('/phases/list', 'CharacterLifecycle@adminPhaseList');
    $route->apiPost('/phases/create', 'CharacterLifecycle@adminPhaseCreate');
    $route->apiPost('/phases/update', 'CharacterLifecycle@adminPhaseUpdate');
    $route->apiPost('/phases/delete', 'CharacterLifecycle@adminPhaseDelete');
    $route->apiPost('/characters/current', 'CharacterLifecycle@adminCurrentPhase');
    $route->apiPost('/characters/history', 'CharacterLifecycle@adminHistory');
    $route->apiPost('/characters/transition', 'CharacterLifecycle@adminTransition');
});

$route->group('/factions', function ($route) {
    $route->apiPost('/list', 'Factions@list');
    $route->apiPost('/get', 'Factions@get');
    $route->apiPost('/my', 'Factions@myFactions');
    $route->apiPost('/members', 'Factions@getFactionMembers');
    $route->apiPost('/relations', 'Factions@getFactionRelations');
    $route->apiPost('/leave', 'Factions@leaveFaction');
    $route->apiPost('/join-request/send', 'Factions@sendJoinRequest');
    $route->apiPost('/join-request/withdraw', 'Factions@withdrawJoinRequest');
    $route->apiPost('/join-request/my', 'Factions@myJoinRequests');
    $route->apiPost('/leader/requests', 'Factions@leaderListJoinRequests');
    $route->apiPost('/leader/request/review', 'Factions@reviewJoinRequest');
    $route->apiPost('/leader/invite', 'Factions@leaderInviteMember');
    $route->apiPost('/leader/expel', 'Factions@leaderExpelMember');
    $route->apiPost('/leader/relation', 'Factions@leaderProposeRelation');
});

$route->group('/admin/factions', function ($route) {
    $route->apiPost('/list', 'Factions@adminList');
    $route->apiPost('/get', 'Factions@adminGet');
    $route->apiPost('/create', 'Factions@adminCreate');
    $route->apiPost('/update', 'Factions@adminUpdate');
    $route->apiPost('/delete', 'Factions@adminDelete');
    $route->apiPost('/members/list', 'Factions@adminMemberList');
    $route->apiPost('/members/add', 'Factions@adminMemberAdd');
    $route->apiPost('/members/update', 'Factions@adminMemberUpdate');
    $route->apiPost('/members/remove', 'Factions@adminMemberRemove');
    $route->apiPost('/relations/list', 'Factions@adminRelationList');
    $route->apiPost('/relations/set', 'Factions@adminRelationSet');
    $route->apiPost('/relations/remove', 'Factions@adminRelationRemove');
});

$route->group('/notifications', function ($route) {
    $route->apiPost('/list', 'Notifications@list');
    $route->apiPost('/read', 'Notifications@read');
    $route->apiPost('/read-delete', 'Notifications@readDelete');
    $route->apiPost('/delete', 'Notifications@delete');
    $route->apiPost('/read-all', 'Notifications@readAll');
    $route->apiPost('/respond', 'Notifications@respond');
    $route->apiPost('/unread-count', 'Notifications@unreadCount');
});
