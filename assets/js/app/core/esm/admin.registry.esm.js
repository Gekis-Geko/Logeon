const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

const MODULE_FACTORY_MAP = {
    'admin.dashboard': 'DashboardModuleFactory',
    'admin.users': 'AdminUsersModuleFactory',
    'admin.characters': 'AdminCharactersModuleFactory',
    'admin.blacklist': 'AdminBlacklistModuleFactory',
    'admin.themes': 'AdminThemesModuleFactory',
    'admin.modules': 'AdminModulesModuleFactory',
    'admin.character-attributes': 'AdminCharacterAttributesModuleFactory',
    'admin.maps': 'AdminMapsModuleFactory',
    'admin.currencies': 'AdminCurrenciesModuleFactory',
    'admin.shops': 'AdminShopsModuleFactory',
    'admin.conflicts': 'AdminConflictsModuleFactory',
    'admin.narrative-events': 'AdminNarrativeEventsModuleFactory',
    'admin.narrative-states': 'AdminNarrativeStatesModuleFactory',
    'admin.quests': 'AdminQuestsModuleFactory',
    'admin.system-events': 'AdminSystemEventsModuleFactory',
    'admin.character-lifecycle': 'AdminCharacterLifecycleModuleFactory',
    'admin.factions': 'AdminFactionsModuleFactory',
    'admin.weather': 'AdminWeatherModuleFactory',
    'admin.weather-overview': 'AdminWeatherModuleFactory',
    'admin.weather-catalogs': 'AdminWeatherModuleFactory',
    'admin.weather-profiles': 'AdminWeatherModuleFactory',
    'admin.weather-overrides': 'AdminWeatherModuleFactory',
    'admin.character-requests': 'AdminCharacterRequestsModuleFactory',
    'admin.shop-inventory': 'AdminShopInventoryModuleFactory',
    'admin.locations': 'AdminLocationsModuleFactory',
    'admin.jobs': 'AdminJobsModuleFactory',
    'admin.jobs-tasks': 'AdminJobsTasksModuleFactory',
    'admin.jobs-levels': 'AdminJobsLevelsModuleFactory',
    'admin.social-status': 'AdminSocialStatusModuleFactory',
    'admin.guilds': 'AdminGuildsModuleFactory',
    'admin.guild-alignments': 'AdminGuildAlignmentsModuleFactory',
    'admin.guild-reqs': 'AdminGuildReqsModuleFactory',
    'admin.guild-locations': 'AdminGuildLocationsModuleFactory',
    'admin.guild-events': 'AdminGuildEventsModuleFactory',
    'admin.forums': 'AdminForumsModuleFactory',
    'admin.forum-types': 'AdminForumTypesModuleFactory',
    'admin.storyboards': 'AdminStoryboardsModuleFactory',
    'admin.rules': 'AdminRulesModuleFactory',
    'admin.how-to-play': 'AdminHowToPlayModuleFactory',
    'admin.items': 'AdminItemsModuleFactory',
    'admin.items-categories': 'AdminItemsCategoriesModuleFactory',
    'admin.settings': 'AdminSettingsModuleFactory',
    'admin.items-rarities': 'AdminItemsRaritiesModuleFactory',
    'admin.equipment-slots': 'AdminEquipmentSlotsModuleFactory',
    'admin.item-equipment-rules': 'AdminItemEquipmentRulesModuleFactory',
    'admin.logs-conflicts': 'AdminLogsConflictsModuleFactory',
    'admin.logs-currency': 'AdminLogsCurrencyModuleFactory',
    'admin.logs-experience': 'AdminLogsExperienceModuleFactory',
    'admin.logs-fame': 'AdminLogsFameModuleFactory',
    'admin.logs-guild': 'AdminLogsGuildModuleFactory',
    'admin.logs-job': 'AdminLogsJobModuleFactory',
    'admin.logs-location-access': 'AdminLogsLocationAccessModuleFactory',
    'admin.logs-sys': 'AdminLogsSysModuleFactory',
    'admin.logs-narrative': 'AdminLogsNarrativeModuleFactory',
    'admin.archetypes': 'AdminArchetypesModuleFactory',
    'admin.narrative-tags': 'AdminNarrativeTagsModuleFactory',
    'admin.message-reports': 'AdminMessageReportsModuleFactory',
    'admin.news': 'AdminNewsModuleFactory',
    'admin.narrative-delegation-grants': 'AdminNarrativeDelegationGrantsModuleFactory',
    'admin.narrative-npcs': 'AdminNarrativeNpcsModuleFactory'
};

function createNoopFactory() {
    return function () {
        return {
            mount: function () {},
            unmount: function () {}
        };
    };
}

function createInitFallback(globalName, configResolver) {
    return function () {
        return {
            mount: function (_ctx, options) {
                if (typeof globalWindow[globalName] === 'undefined') {
                    return;
                }
                if (!globalWindow[globalName] || typeof globalWindow[globalName].init !== 'function') {
                    return;
                }
                if (typeof configResolver === 'function') {
                    globalWindow[globalName].init(configResolver(options || {}));
                    return;
                }
                globalWindow[globalName].init();
            },
            unmount: function () {}
        };
    };
}

const FALLBACK_FACTORIES = {
    'admin.dashboard': createInitFallback('Dashboard', function (options) {
        return (options && options.config) || globalWindow.DASHBOARD_CONFIG || globalWindow.ADMIN_DASHBOARD_CONFIG || null;
    }),
    'admin.users': createInitFallback('AdminUsers'),
    'admin.characters': createInitFallback('AdminCharacters'),
    'admin.blacklist': createInitFallback('AdminBlacklist'),
    'admin.themes': createInitFallback('AdminThemes'),
    'admin.modules': createInitFallback('AdminModules'),
    'admin.character-attributes': createInitFallback('AdminCharacterAttributes'),
    'admin.maps': createInitFallback('AdminMaps'),
    'admin.currencies': createInitFallback('AdminCurrencies'),
    'admin.shops': createInitFallback('AdminShops'),
    'admin.conflicts': createInitFallback('AdminConflicts'),
    'admin.quests': createInitFallback('AdminQuests'),
    'admin.system-events': createInitFallback('AdminSystemEvents')
};

export function resolveFactory(moduleName) {
    const key = String(moduleName || '').trim();
    if (!key) {
        return createNoopFactory();
    }

    const factoryGlobalName = MODULE_FACTORY_MAP[key];
    if (factoryGlobalName && typeof globalWindow[factoryGlobalName] === 'function') {
        return globalWindow[factoryGlobalName];
    }

    if (Object.prototype.hasOwnProperty.call(FALLBACK_FACTORIES, key)) {
        return FALLBACK_FACTORIES[key];
    }

    return createNoopFactory();
}

export function getPageModules() {
    return {
        dashboard: ['admin.dashboard'],
        users: ['admin.users'],
        characters: ['admin.characters'],
        blacklist: ['admin.blacklist'],
        themes: ['admin.themes'],
        modules: ['admin.modules'],
        'character-attributes': ['admin.character-attributes'],
        maps: ['admin.maps'],
        currencies: ['admin.currencies'],
        shops: ['admin.shops'],
        conflicts: ['admin.conflicts'],
        'narrative-events': ['admin.narrative-events'],
        'narrative-states': ['admin.narrative-states'],
        quests: ['admin.quests'],
        'system-events': ['admin.system-events'],
        'character-lifecycle': ['admin.character-lifecycle'],
        factions: ['admin.factions'],
        weather: ['admin.weather'],
        'weather-overview': ['admin.weather-overview'],
        'weather-catalogs': ['admin.weather-catalogs'],
        'weather-profiles': ['admin.weather-profiles'],
        'weather-overrides': ['admin.weather-overrides'],
        'character-requests': ['admin.character-requests'],
        'inventory-shop': ['admin.shop-inventory'],
        locations: ['admin.locations'],
        jobs: ['admin.jobs'],
        'jobs-tasks': ['admin.jobs-tasks'],
        'jobs-levels': ['admin.jobs-levels'],
        'social-status': ['admin.social-status'],
        guilds: ['admin.guilds'],
        'guild-alignments': ['admin.guild-alignments'],
        'guilds-reqs': ['admin.guild-reqs'],
        'guilds-locations': ['admin.guild-locations'],
        'guilds-events': ['admin.guild-events'],
        forums: ['admin.forums'],
        'forums-types': ['admin.forum-types'],
        storyboards: ['admin.storyboards'],
        rules: ['admin.rules'],
        'how-to-play': ['admin.how-to-play'],
        items: ['admin.items'],
        'items-categories': ['admin.items-categories'],
        'items-rarities': ['admin.items-rarities'],
        'equipment-slots': ['admin.equipment-slots'],
        'item-equipment-rules': ['admin.item-equipment-rules'],
        'logs-conflicts': ['admin.logs-conflicts'],
        'logs-currency': ['admin.logs-currency'],
        'logs-experience': ['admin.logs-experience'],
        'logs-fame': ['admin.logs-fame'],
        'logs-guild': ['admin.logs-guild'],
        'logs-job': ['admin.logs-job'],
        'logs-location-access': ['admin.logs-location-access'],
        'logs-sys': ['admin.logs-sys'],
        'logs-narrative': ['admin.logs-narrative'],
        settings: ['admin.settings'],
        archetypes: ['admin.archetypes'],
        'narrative-tags': ['admin.narrative-tags'],
        'message-reports': ['admin.message-reports'],
        news: ['admin.news'],
        'narrative-delegation-grants': ['admin.narrative-delegation-grants'],
        'narrative-npcs': ['admin.narrative-npcs']
    };
}

export function getPageConfig() {
    return {
        selector: '#admin-page [data-admin-page]',
        attribute: 'data-admin-page',
        modules: getPageModules()
    };
}

export function registerModules(app) {
    if (!app || typeof app.register !== 'function') {
        return;
    }

    Object.keys(MODULE_FACTORY_MAP).forEach(function (moduleName) {
        app.register(moduleName, resolveFactory(moduleName));
    });
}

export const AdminRegistryApi = {
    resolveFactory,
    getPageModules,
    getPageConfig,
    registerModules
};

globalWindow.AdminRegistry = globalWindow.AdminRegistry || {};
globalWindow.AdminRegistry.resolveFactory = resolveFactory;
globalWindow.AdminRegistry.getPageModules = getPageModules;
globalWindow.AdminRegistry.getPageConfig = getPageConfig;
globalWindow.AdminRegistry.registerModules = registerModules;

export default AdminRegistryApi;
