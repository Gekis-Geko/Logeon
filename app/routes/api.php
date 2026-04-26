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
    $route->apiPost('/messages/archive-range', 'LocationMessages@archiveRange');
    $route->apiPost('/messages/send', 'LocationMessages@send');
    $route->apiPost('/whispers/list', 'LocationMessages@whispers');
    $route->apiPost('/whispers/threads', 'LocationMessages@whispersThreads');
    $route->apiPost('/whispers/unread', 'LocationMessages@whispersUnread');
    $route->apiPost('/whispers/send', 'LocationMessages@whisper');
    $route->apiPost('/whispers/policy', 'LocationMessages@whisperPolicy');
    $route->apiPost('/drops/list', 'LocationDrops@list');
    $route->apiPost('/drops/drop', 'LocationDrops@drop');
    $route->apiPost('/drops/pick', 'LocationDrops@pickup');
    $route->apiPost('/position-tags/list', 'LocationPositionTags@list');
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

$route->apiPost('/get/weather', 'Weathers@weather');

$route->group('/weather', function ($route) {
    $route->apiPost('/options', 'Weathers@options');
    $route->apiPost('/global/set', 'Weathers@setGlobal');
    $route->apiPost('/global/clear', 'Weathers@clearGlobal');
    $route->apiPost('/world/options', 'Weathers@worldOptions');
    $route->apiPost('/world/set', 'Weathers@setWorld');
    $route->apiPost('/world/clear', 'Weathers@clearWorld');
    $route->apiPost('/location/set', 'Weathers@setLocation');
    $route->apiPost('/location/clear', 'Weathers@clearLocation');
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

    $route->apiPost('/categories/list', 'ItemsCategories@list');
    $route->apiPost('/categories/create', 'ItemsCategories@create');
    $route->apiPost('/categories/update', 'ItemsCategories@update');
    $route->apiPost('/categories/delete', 'ItemsCategories@delete');

    $route->apiPost('/core-currencies/list', 'Currencies@list');
    $route->apiPost('/core-currencies/create', 'Currencies@create');
    $route->apiPost('/core-currencies/update', 'Currencies@update');
    $route->apiPost('/core-currencies/delete', 'Currencies@delete');

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

    $route->apiPost('/location-position-tags/list', 'LocationPositionTags@adminList');
    $route->apiPost('/location-position-tags/create', 'LocationPositionTags@adminCreate');
    $route->apiPost('/location-position-tags/update', 'LocationPositionTags@adminUpdate');
    $route->apiPost('/location-position-tags/delete', 'LocationPositionTags@adminDelete');

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

    $route->apiPost('/logs/conflicts/list', 'AdminLogs@listConflicts');
    $route->apiPost('/logs/currency/list', 'AdminLogs@listCurrency');
    $route->apiPost('/logs/experience/list', 'AdminLogs@listExperience');
    $route->apiPost('/logs/fame/list', 'AdminLogs@listFame');
    $route->apiPost('/logs/guild/list', 'AdminLogs@listGuild');
    $route->apiPost('/logs/job/list', 'AdminLogs@listJob');
    $route->apiPost('/logs/location-access/list', 'AdminLogs@listLocationAccess');
    $route->apiPost('/logs/sys/list', 'AdminLogs@listSys');
    $route->apiPost('/logs/narrative/list', 'AdminLogs@listNarrative');

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

$route->group('/chat-archives', function ($route) {
    $route->apiPost('/list',       'ChatArchives@list');
    $route->apiPost('/get',        'ChatArchives@get');
    $route->apiPost('/create',     'ChatArchives@create');
    $route->apiPost('/update',     'ChatArchives@update');
    $route->apiPost('/delete',     'ChatArchives@delete');
    $route->apiPost('/public/set', 'ChatArchives@setPublic');
    $route->apiPost('/diary/link', 'ChatArchives@linkDiary');
    $route->apiPost('/diary/search', 'ChatArchives@searchDiaryEvents');
});

$route->apiPost('/shared/chat-archive/load', 'ChatArchivesPublic@show');

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
});
$route->apiPost('/character/create', 'CharacterCreation@createCharacter');

$route->group('/narrative-tags', function ($route) {
    $route->apiPost('/entity', 'NarrativeTags@entityTags');
});

$route->group('/list', function ($route) {
    $route->apiPost('/nationalities', 'Nationalities@list');
    $route->apiPost('/narrative-tags', 'NarrativeTags@publicList');
    $route->apiPost('/maps', 'Maps@list');
    $route->apiPost('/locations', 'Locations@list');
    $route->apiPost('/profile/bag', 'Inventory@bag');
    $route->apiPost('/onlines', 'Characters@onlines');
    $route->apiPost('/onlines/complete', 'Characters@onlinesComplete');
    $route->apiPost('/characters/search', 'Characters@search');
    $route->apiPost('/forum-types', 'ForumsTypes@list');
    $route->apiPost('/forum', 'Forums@list');
    $route->apiPost('/forum/threads', 'Threads@list');
    $route->apiPost('/items', 'Items@list');
});

$route->group('/get', function ($route) {
    $route->apiPost('/profile', 'Characters@getByID');
    $route->apiPost('/bag', 'Characters@getByID');
    $route->apiPost('/forum', 'Forums@getByID');
    $route->apiPost('/forum/thread', 'Threads@getByID');
});

$route->group('/narrative-states', function ($route) {
    $route->apiPost('/catalog', 'NarrativeStates@catalog');
    $route->apiPost('/apply', 'NarrativeStates@apply');
    $route->apiPost('/remove', 'NarrativeStates@remove');
    $route->apiPost('/my-states', 'NarrativeStates@myStates');
    $route->apiGet('/my-states', 'NarrativeStates@myStates');
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

$route->group('/notifications', function ($route) {
    $route->apiPost('/list', 'Notifications@list');
    $route->apiPost('/read', 'Notifications@read');
    $route->apiPost('/read-delete', 'Notifications@readDelete');
    $route->apiPost('/delete', 'Notifications@delete');
    $route->apiPost('/read-all', 'Notifications@readAll');
    $route->apiPost('/respond', 'Notifications@respond');
    $route->apiPost('/unread-count', 'Notifications@unreadCount');
});
