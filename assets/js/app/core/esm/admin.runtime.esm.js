const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function getApi(name) {
    return function () {
        if (globalWindow[name] && typeof globalWindow[name] === 'object') {
            return globalWindow[name];
        }
        return null;
    };
}

function getRuntimeBootstrap() {
    if (!globalWindow.RuntimeBootstrap || typeof globalWindow.RuntimeBootstrap !== 'object') {
        return null;
    }
    return globalWindow.RuntimeBootstrap;
}

export function getConfig() {
    return {
        appGlobal: 'AdminApp',
        guard: {
            startKey: '__adminRuntimeStartBound',
            bootKey: '__adminRuntimeBooted'
        },
        context: {
            mode: 'admin',
            rootSelector: '#admin-page',
            policy: 'observe'
        },
        page: {
            selector: '#admin-page [data-admin-page]',
            attribute: 'data-admin-page',
            modules: {
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
                settings: ['admin.settings'],
                archetypes: ['admin.archetypes'],
                'narrative-tags': ['admin.narrative-tags'],
                'message-reports': ['admin.message-reports'],
                news: ['admin.news']
            }
        },
        pageApi: getApi('AdminPage'),
        registryApi: getApi('AdminRegistry')
    };
}

export function boot() {
    const runtime = getRuntimeBootstrap();
    if (!runtime || typeof runtime.boot !== 'function') {
        return null;
    }
    return runtime.boot(getConfig());
}

export function start() {
    const runtime = getRuntimeBootstrap();
    if (!runtime || typeof runtime.start !== 'function') {
        return;
    }
    runtime.start(getConfig());
}

export const AdminRuntimeApi = { boot, start, getConfig };

globalWindow.AdminRuntime = globalWindow.AdminRuntime || {};
globalWindow.AdminRuntime.boot = boot;
globalWindow.AdminRuntime.start = start;

export default AdminRuntimeApi;
