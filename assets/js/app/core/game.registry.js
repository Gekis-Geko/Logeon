const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

const MODULE_FACTORY_MAP = {
    'game.notifications': 'NotificationsModuleFactory',
    'game.news': 'GameNewsModuleFactory',
    'game.messages': 'GameMessagesModuleFactory',
    'game.forum': 'GameForumModuleFactory',
    'game.profile': 'GameProfileModuleFactory',
    'game.presence': 'GamePresenceModuleFactory',
    'game.onlines': 'GameOnlinesModuleFactory',
    'game.shop': 'GameShopModuleFactory',
    'game.bank': 'GameBankModuleFactory',
    'game.maps': 'GameMapsModuleFactory',
    'game.settings': 'GameSettingsModuleFactory',
    'game.jobs': 'GameJobsModuleFactory',
    'game.guilds': 'GameGuildsModuleFactory',
    'game.inventory': 'GameInventoryModuleFactory',
    'game.location.whispers': 'GameLocationWhispersModuleFactory',
    'game.location.drops': 'GameLocationDropsModuleFactory',
    'game.location.invites': 'GameLocationInvitesModuleFactory',
    'game.location.chat': 'GameLocationChatModuleFactory',
    'game.location.page': 'GameLocationPageModuleFactory',
    'game.narrative-events': 'NarrativeEventsModuleFactory',
    'game.narrative-ephemeral-npcs': 'NarrativeEphemeralNpcsModuleFactory',
    'game.system-events': 'SystemEventsModuleFactory',
    'game.lifecycle': 'GameLifecycleModuleFactory',
    'game.narrative-states': 'GameNarrativeStatesModuleFactory'
};

const PAGE_MODULES = {
    home: ['game.notifications', 'game.narrative-events', 'game.system-events', 'game.news', 'game.messages'],
    forum: ['game.notifications', 'game.narrative-events', 'game.system-events', 'game.news', 'game.messages', 'game.forum'],
    threads: ['game.notifications', 'game.narrative-events', 'game.system-events', 'game.forum'],
    thread: ['game.notifications', 'game.narrative-events', 'game.system-events', 'game.messages', 'game.forum'],
    profile: ['game.notifications', 'game.narrative-events', 'game.system-events', 'game.news', 'game.messages', 'game.profile', 'game.lifecycle', 'game.narrative-states'],
    onlines: ['game.notifications', 'game.narrative-events', 'game.system-events', 'game.onlines'],
    location: ['game.notifications', 'game.narrative-events', 'game.narrative-ephemeral-npcs', 'game.system-events', 'game.messages', 'game.location.page', 'game.location.chat', 'game.location.whispers', 'game.location.drops', 'game.location.invites'],
    shop: ['game.notifications', 'game.narrative-events', 'game.system-events', 'game.messages', 'game.shop'],
    bank: ['game.notifications', 'game.narrative-events', 'game.system-events', 'game.messages', 'game.bank'],
    maps: ['game.notifications', 'game.narrative-events', 'game.system-events', 'game.messages', 'game.maps'],
    locations: ['game.notifications', 'game.narrative-events', 'game.system-events', 'game.messages', 'game.maps'],
    settings: ['game.notifications', 'game.narrative-events', 'game.system-events', 'game.messages', 'game.settings'],
    'profile-edit': ['game.notifications', 'game.narrative-events', 'game.system-events', 'game.profile'],
    jobs: ['game.notifications', 'game.narrative-events', 'game.system-events', 'game.jobs'],
    guilds: ['game.notifications', 'game.narrative-events', 'game.system-events', 'game.guilds'],
    guild: ['game.notifications', 'game.narrative-events', 'game.system-events', 'game.guilds'],
    bag: ['game.notifications', 'game.narrative-events', 'game.system-events', 'game.inventory'],
    equips: ['game.notifications', 'game.narrative-events', 'game.system-events', 'game.inventory']
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

export function resolveFactory(factoryName) {
    const key = String(factoryName || '').trim();
    if (!key) {
        return createNoopFactory();
    }
    if (typeof globalWindow[key] === 'function') {
        return globalWindow[key];
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

export function registerModules(app) {
    if (!app || typeof app.register !== 'function') {
        return;
    }
    Object.keys(MODULE_FACTORY_MAP).forEach(function (moduleName) {
        app.register(moduleName, resolveFactory(MODULE_FACTORY_MAP[moduleName]));
    });
}

export const GameRegistryApi = {
    getPageModules,
    resolveFactory,
    registerModules,
    registerModule,
    extendPage
};

globalWindow.GameRegistry = globalWindow.GameRegistry || {};
globalWindow.GameRegistry.getPageModules = getPageModules;
globalWindow.GameRegistry.resolveFactory = resolveFactory;
globalWindow.GameRegistry.registerModules = registerModules;
globalWindow.GameRegistry.registerModule = registerModule;
globalWindow.GameRegistry.extendPage = extendPage;

export default GameRegistryApi;
