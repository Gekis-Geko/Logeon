const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

const MODULE_FACTORY_MAP = {
    'game.notifications': 'NotificationsModuleFactory',
    'game.news': 'GameNewsModuleFactory',
    'game.messages': 'GameMessagesModuleFactory',
    'game.forum': 'GameForumModuleFactory',
    'game.profile': 'GameProfileModuleFactory',
    'game.presence': 'GamePresenceModuleFactory',
    'game.onlines': 'GameOnlinesModuleFactory',
    'game.weather': 'GameWeatherModuleFactory',
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
    'game.quests': 'QuestsModuleFactory',
    'game.narrative-events': 'NarrativeEventsModuleFactory',
    'game.narrative-ephemeral-npcs': 'NarrativeEphemeralNpcsModuleFactory',
    'game.system-events': 'SystemEventsModuleFactory',
    'game.factions': 'FactionsModuleFactory',
    'game.lifecycle': 'GameLifecycleModuleFactory',
    'game.narrative-states': 'GameNarrativeStatesModuleFactory'
};

function createNoopFactory() {
    return function () {
        return {
            mount: function () {},
            unmount: function () {}
        };
    };
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

export function getPageModules() {
    return {
        home: ['game.notifications', 'game.quests', 'game.narrative-events', 'game.system-events', 'game.news', 'game.messages'],
        forum: ['game.notifications', 'game.quests', 'game.narrative-events', 'game.system-events', 'game.news', 'game.messages', 'game.forum'],
        threads: ['game.notifications', 'game.quests', 'game.narrative-events', 'game.system-events', 'game.forum'],
        thread: ['game.notifications', 'game.quests', 'game.narrative-events', 'game.system-events', 'game.messages', 'game.forum'],
        profile: ['game.notifications', 'game.quests', 'game.narrative-events', 'game.system-events', 'game.news', 'game.messages', 'game.profile', 'game.lifecycle', 'game.narrative-states'],
        onlines: ['game.notifications', 'game.quests', 'game.narrative-events', 'game.system-events', 'game.onlines'],
        location: ['game.notifications', 'game.quests', 'game.narrative-events', 'game.narrative-ephemeral-npcs', 'game.system-events', 'game.messages', 'game.location.page', 'game.location.chat', 'game.location.whispers', 'game.location.drops', 'game.location.invites'],
        shop: ['game.notifications', 'game.quests', 'game.narrative-events', 'game.system-events', 'game.shop'],
        bank: ['game.notifications', 'game.quests', 'game.narrative-events', 'game.system-events', 'game.bank'],
        maps: ['game.notifications', 'game.quests', 'game.narrative-events', 'game.system-events', 'game.maps'],
        locations: ['game.notifications', 'game.quests', 'game.narrative-events', 'game.system-events', 'game.maps'],
        settings: ['game.notifications', 'game.quests', 'game.narrative-events', 'game.system-events', 'game.settings'],
        'profile-edit': ['game.notifications', 'game.quests', 'game.narrative-events', 'game.system-events', 'game.profile'],
        jobs: ['game.notifications', 'game.quests', 'game.narrative-events', 'game.system-events', 'game.jobs'],
        guilds: ['game.notifications', 'game.quests', 'game.narrative-events', 'game.system-events', 'game.guilds'],
        guild: ['game.notifications', 'game.quests', 'game.narrative-events', 'game.system-events', 'game.guilds'],
        'quests-history': ['game.notifications', 'game.quests', 'game.narrative-events', 'game.system-events'],
        bag: ['game.notifications', 'game.quests', 'game.narrative-events', 'game.system-events', 'game.inventory'],
        equips: ['game.notifications', 'game.quests', 'game.narrative-events', 'game.system-events', 'game.inventory'],
        factions: ['game.notifications', 'game.quests', 'game.narrative-events', 'game.system-events', 'game.factions']
    };
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
    registerModules
};

globalWindow.GameRegistry = globalWindow.GameRegistry || {};
globalWindow.GameRegistry.getPageModules = getPageModules;
globalWindow.GameRegistry.resolveFactory = resolveFactory;
globalWindow.GameRegistry.registerModules = registerModules;

export default GameRegistryApi;
