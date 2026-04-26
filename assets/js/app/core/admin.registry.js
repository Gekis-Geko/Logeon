const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

const MODULE_FACTORY_MAP = {
    'admin.dashboard': 'DashboardModuleFactory',
    'admin.users': 'AdminUsersModuleFactory',
    'admin.characters': 'AdminCharactersModuleFactory',
    'admin.blacklist': 'AdminBlacklistModuleFactory',
    'admin.themes': 'AdminThemesModuleFactory',
    'admin.modules': 'AdminModulesModuleFactory',
    'admin.maps': 'AdminMapsModuleFactory',
    'admin.currencies': 'AdminCurrenciesModuleFactory',
    'admin.shops': 'AdminShopsModuleFactory',
    'admin.conflicts': 'AdminConflictsModuleFactory',
    'admin.narrative-events': 'AdminNarrativeEventsModuleFactory',
    'admin.narrative-states': 'AdminNarrativeStatesModuleFactory',
    'admin.system-events': 'AdminSystemEventsModuleFactory',
    'admin.character-lifecycle': 'AdminCharacterLifecycleModuleFactory',
    'admin.character-requests': 'AdminCharacterRequestsModuleFactory',
    'admin.shop-inventory': 'AdminShopInventoryModuleFactory',
    'admin.locations': 'AdminLocationsModuleFactory',
    'admin.jobs': 'AdminJobsModuleFactory',
    'admin.jobs-tasks': 'AdminJobsTasksModuleFactory',
    'admin.jobs-levels': 'AdminJobsLevelsModuleFactory',
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
    'admin.narrative-tags': 'AdminNarrativeTagsModuleFactory',
    'admin.message-reports': 'AdminMessageReportsModuleFactory',
    'admin.news': 'AdminNewsModuleFactory',
    'admin.narrative-delegation-grants': 'AdminNarrativeDelegationGrantsModuleFactory',
    'admin.narrative-npcs': 'AdminNarrativeNpcsModuleFactory',
    'admin.location-position-tags': 'AdminLocationPositionTagsModuleFactory'
};

const PAGE_MODULES = {
    dashboard: ['admin.dashboard'],
    users: ['admin.users'],
    characters: ['admin.characters'],
    blacklist: ['admin.blacklist'],
    themes: ['admin.themes'],
    modules: ['admin.modules'],
    maps: ['admin.maps'],
    currencies: ['admin.currencies'],
    shops: ['admin.shops'],
    conflicts: ['admin.conflicts'],
    'narrative-events': ['admin.narrative-events'],
    'narrative-states': ['admin.narrative-states'],
    'system-events': ['admin.system-events'],
    'character-lifecycle': ['admin.character-lifecycle'],
    'character-requests': ['admin.character-requests'],
    'inventory-shop': ['admin.shop-inventory'],
    locations: ['admin.locations'],
    jobs: ['admin.jobs'],
    'jobs-tasks': ['admin.jobs-tasks'],
    'jobs-levels': ['admin.jobs-levels'],
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
    'narrative-tags': ['admin.narrative-tags'],
    'message-reports': ['admin.message-reports'],
    news: ['admin.news'],
    'narrative-delegation-grants': ['admin.narrative-delegation-grants'],
    'narrative-npcs': ['admin.narrative-npcs'],
    'location-position-tags': ['admin.location-position-tags']
};

function createNoopFactory() {
    return function () {
        return {
            mount: function () {},
            unmount: function () {}
        };
    };
}

function normalizeOrderOptions(opts) {
    if (!opts || typeof opts !== 'object') {
        return { after: '', before: '' };
    }
    return {
        after: String(opts.after || '').trim(),
        before: String(opts.before || '').trim()
    };
}

function insertUniqueWithOrder(list, value, opts) {
    if (list.indexOf(value) !== -1) {
        return;
    }

    let insertAt = list.length;
    if (opts.before) {
        const beforeIndex = list.indexOf(opts.before);
        if (beforeIndex !== -1) {
            insertAt = beforeIndex;
        }
    }
    if (insertAt === list.length && opts.after) {
        const afterIndex = list.indexOf(opts.after);
        if (afterIndex !== -1) {
            insertAt = afterIndex + 1;
        }
    }

    list.splice(insertAt, 0, value);
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
    'admin.maps': createInitFallback('AdminMaps'),
    'admin.currencies': createInitFallback('AdminCurrencies'),
    'admin.shops': createInitFallback('AdminShops'),
    'admin.conflicts': createInitFallback('AdminConflicts'),
    'admin.system-events': createInitFallback('AdminSystemEvents'),
    'admin.location-position-tags': createInitFallback('AdminLocationPositionTags')
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

export function registerModule(key, factoryName) {
    const k = String(key || '').trim();
    const f = String(factoryName || '').trim();
    if (k && f) {
        MODULE_FACTORY_MAP[k] = f;
    }
}

export function extendPage(pageKey, moduleKeys, opts) {
    const k = String(pageKey || '').trim();
    if (!k || !Array.isArray(moduleKeys) || !moduleKeys.length) {
        return;
    }
    if (!PAGE_MODULES[k]) {
        PAGE_MODULES[k] = [];
    }

    const order = normalizeOrderOptions(opts);
    const uniqueKeys = [];
    for (let i = 0; i < moduleKeys.length; i += 1) {
        const mk = String(moduleKeys[i] || '').trim();
        if (mk && uniqueKeys.indexOf(mk) === -1) {
            uniqueKeys.push(mk);
        }
    }

    const keysToInsert = (order.after && !order.before)
        ? uniqueKeys.slice().reverse()
        : uniqueKeys;
    for (let i = 0; i < keysToInsert.length; i += 1) {
        insertUniqueWithOrder(PAGE_MODULES[k], keysToInsert[i], order);
    }
}

export function getPageModules() {
    return PAGE_MODULES;
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
    registerModules,
    registerModule,
    extendPage
};

globalWindow.AdminRegistry = globalWindow.AdminRegistry || {};
globalWindow.AdminRegistry.resolveFactory = resolveFactory;
globalWindow.AdminRegistry.getPageModules = getPageModules;
globalWindow.AdminRegistry.getPageConfig = getPageConfig;
globalWindow.AdminRegistry.registerModules = registerModules;
globalWindow.AdminRegistry.registerModule = registerModule;
globalWindow.AdminRegistry.extendPage = extendPage;

export default AdminRegistryApi;
