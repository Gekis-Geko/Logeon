const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

const SHARED_FEATURES = [
    {
        src: '/assets/js/app/features/admin/AdminImageUploader.js',
        ready: function () {
            return globalWindow.__admin_image_uploader_loaded === true;
        }
    }
];

const PAGE_FEATURE_SCRIPTS = {
    dashboard: ['/assets/js/app/features/admin/Dashboard.js'],
    users: ['/assets/js/app/features/admin/Users.js'],
    characters: ['/assets/js/app/features/admin/Characters.js'],
    blacklist: ['/assets/js/app/features/admin/Blacklist.js'],
    themes: ['/assets/js/app/features/admin/Themes.js'],
    modules: ['/assets/js/app/features/admin/Modules.js'],
    'character-attributes': ['/assets/js/app/features/admin/CharacterAttributes.js'],
    maps: ['/assets/js/app/features/admin/Maps.js'],
    currencies: ['/assets/js/app/features/admin/Currencies.js'],
    shops: ['/assets/js/app/features/admin/Shops.js'],
    conflicts: ['/assets/js/app/features/admin/Conflicts.js'],
    'narrative-events': ['/assets/js/app/features/admin/NarrativeEvents.js'],
    'narrative-states': ['/assets/js/app/features/admin/NarrativeStates.js'],
    quests: ['/assets/js/app/features/admin/Quests.js'],
    'system-events': ['/assets/js/app/features/admin/SystemEvents.js'],
    'character-lifecycle': ['/assets/js/app/features/admin/CharacterLifecycle.js'],
    factions: ['/assets/js/app/features/admin/Factions.js'],
    weather: ['/assets/js/app/features/admin/Weather.js'],
    'weather-overview': ['/assets/js/app/features/admin/Weather.js'],
    'weather-catalogs': ['/assets/js/app/features/admin/Weather.js'],
    'weather-profiles': ['/assets/js/app/features/admin/Weather.js'],
    'weather-overrides': ['/assets/js/app/features/admin/Weather.js'],
    'character-requests': ['/assets/js/app/features/admin/CharacterRequests.js'],
    'inventory-shop': ['/assets/js/app/features/admin/ShopInventory.js'],
    locations: ['/assets/js/app/features/admin/Locations.js'],
    jobs: ['/assets/js/app/features/admin/Jobs.js'],
    'jobs-tasks': ['/assets/js/app/features/admin/JobsTasks.js'],
    'jobs-levels': ['/assets/js/app/features/admin/JobsLevels.js'],
    'social-status': ['/assets/js/app/features/admin/SocialStatus.js'],
    guilds: ['/assets/js/app/features/admin/Guilds.js'],
    'guild-alignments': ['/assets/js/app/features/admin/GuildAlignments.js'],
    'guilds-reqs': ['/assets/js/app/features/admin/GuildReqs.js'],
    'guilds-locations': ['/assets/js/app/features/admin/GuildLocations.js'],
    'guilds-events': ['/assets/js/app/features/admin/GuildEvents.js'],
    forums: ['/assets/js/app/features/admin/Forums.js'],
    'forums-types': ['/assets/js/app/features/admin/ForumTypes.js'],
    storyboards: ['/assets/js/app/features/admin/Storyboards.js'],
    rules: ['/assets/js/app/features/admin/Rules.js'],
    'how-to-play': ['/assets/js/app/features/admin/HowToPlay.js'],
    items: ['/assets/js/app/features/admin/Items.js'],
    'items-categories': ['/assets/js/app/features/admin/ItemsCategories.js'],
    'items-rarities': ['/assets/js/app/features/admin/ItemsRarities.js'],
    'equipment-slots': ['/assets/js/app/features/admin/EquipmentSlots.js'],
    'item-equipment-rules': ['/assets/js/app/features/admin/ItemEquipmentRules.js'],
    'logs-conflicts': ['/assets/js/app/features/admin/LogsConflicts.js'],
    'logs-currency': ['/assets/js/app/features/admin/LogsCurrency.js'],
    'logs-experience': ['/assets/js/app/features/admin/LogsExperience.js'],
    'logs-fame': ['/assets/js/app/features/admin/LogsFame.js'],
    'logs-guild': ['/assets/js/app/features/admin/LogsGuild.js'],
    'logs-job': ['/assets/js/app/features/admin/LogsJob.js'],
    'logs-location-access': ['/assets/js/app/features/admin/LogsLocationAccess.js'],
    'logs-sys': ['/assets/js/app/features/admin/LogsSys.js'],
    settings: ['/assets/js/app/features/admin/Settings.js'],
    archetypes: ['/assets/js/app/features/admin/Archetypes.js'],
    'narrative-tags': ['/assets/js/app/features/admin/NarrativeTags.js'],
    'message-reports': ['/assets/js/app/features/admin/MessageReports.js'],
    news: ['/assets/js/app/features/admin/News.js'],
    'narrative-delegation-grants': ['/assets/js/app/features/admin/NarrativeDelegationGrants.js'],
    'narrative-npcs': ['/assets/js/app/features/admin/NarrativeNpcs.js'],
    'logs-narrative': ['/assets/js/app/features/admin/LogsNarrative.js']
};

const PAGE_BUNDLE_SCRIPTS = {
    dashboard: '/assets/js/dist/admin-priority.bundle.js',
    users: '/assets/js/dist/admin-priority.bundle.js',
    weather: '/assets/js/dist/admin-weather.bundle.js',
    'weather-overview': '/assets/js/dist/admin-weather.bundle.js',
    'weather-catalogs': '/assets/js/dist/admin-weather.bundle.js',
    'weather-profiles': '/assets/js/dist/admin-weather.bundle.js',
    'weather-overrides': '/assets/js/dist/admin-weather.bundle.js',
    characters: '/assets/js/dist/admin-governance.bundle.js',
    blacklist: '/assets/js/dist/admin-governance.bundle.js',
    themes: '/assets/js/dist/admin-governance.bundle.js',
    modules: '/assets/js/dist/admin-governance.bundle.js',
    'character-attributes': '/assets/js/dist/admin-governance.bundle.js',
    maps: '/assets/js/dist/admin-governance.bundle.js',
    'character-lifecycle': '/assets/js/dist/admin-governance.bundle.js',
    factions: '/assets/js/dist/admin-governance.bundle.js',
    'character-requests': '/assets/js/dist/admin-governance.bundle.js',
    locations: '/assets/js/dist/admin-governance.bundle.js',
    jobs: '/assets/js/dist/admin-governance.bundle.js',
    'jobs-tasks': '/assets/js/dist/admin-governance.bundle.js',
    'jobs-levels': '/assets/js/dist/admin-governance.bundle.js',
    'social-status': '/assets/js/dist/admin-governance.bundle.js',
    guilds: '/assets/js/dist/admin-governance.bundle.js',
    'guild-alignments': '/assets/js/dist/admin-governance.bundle.js',
    'guilds-reqs': '/assets/js/dist/admin-governance.bundle.js',
    'guilds-locations': '/assets/js/dist/admin-governance.bundle.js',
    'guilds-events': '/assets/js/dist/admin-governance.bundle.js',
    settings: '/assets/js/dist/admin-governance.bundle.js',
    archetypes: '/assets/js/dist/admin-governance.bundle.js',
    currencies: '/assets/js/dist/admin-economy-content.bundle.js',
    shops: '/assets/js/dist/admin-economy-content.bundle.js',
    'inventory-shop': '/assets/js/dist/admin-economy-content.bundle.js',
    forums: '/assets/js/dist/admin-economy-content.bundle.js',
    'forums-types': '/assets/js/dist/admin-economy-content.bundle.js',
    storyboards: '/assets/js/dist/admin-economy-content.bundle.js',
    rules: '/assets/js/dist/admin-economy-content.bundle.js',
    'how-to-play': '/assets/js/dist/admin-economy-content.bundle.js',
    items: '/assets/js/dist/admin-economy-content.bundle.js',
    'items-categories': '/assets/js/dist/admin-economy-content.bundle.js',
    'items-rarities': '/assets/js/dist/admin-economy-content.bundle.js',
    'equipment-slots': '/assets/js/dist/admin-economy-content.bundle.js',
    'item-equipment-rules': '/assets/js/dist/admin-economy-content.bundle.js',
    news: '/assets/js/dist/admin-economy-content.bundle.js',
    conflicts: '/assets/js/dist/admin-narrative.bundle.js',
    'narrative-events': '/assets/js/dist/admin-narrative.bundle.js',
    'narrative-states': '/assets/js/dist/admin-narrative.bundle.js',
    quests: '/assets/js/dist/admin-narrative.bundle.js',
    'system-events': '/assets/js/dist/admin-narrative.bundle.js',
    'narrative-tags': '/assets/js/dist/admin-narrative.bundle.js',
    'message-reports': '/assets/js/dist/admin-narrative.bundle.js',
    'logs-conflicts': '/assets/js/dist/admin-logs.bundle.js',
    'logs-currency': '/assets/js/dist/admin-logs.bundle.js',
    'logs-experience': '/assets/js/dist/admin-logs.bundle.js',
    'logs-fame': '/assets/js/dist/admin-logs.bundle.js',
    'logs-guild': '/assets/js/dist/admin-logs.bundle.js',
    'logs-job': '/assets/js/dist/admin-logs.bundle.js',
    'logs-location-access': '/assets/js/dist/admin-logs.bundle.js',
    'logs-sys': '/assets/js/dist/admin-logs.bundle.js',
    'narrative-delegation-grants': '/assets/js/dist/admin-narrative.bundle.js',
    'narrative-npcs': '/assets/js/dist/admin-narrative.bundle.js',
    'logs-narrative': '/assets/js/dist/admin-logs.bundle.js'
};

const FEATURE_READY_CHECKS = {
    '/assets/js/app/features/admin/Dashboard.js': function () {
        return !!(globalWindow.Dashboard && typeof globalWindow.Dashboard.init === 'function');
    },
    '/assets/js/app/features/admin/Users.js': function () {
        return !!(globalWindow.AdminUsers && typeof globalWindow.AdminUsers.init === 'function');
    },
    '/assets/js/app/features/admin/Weather.js': function () {
        return !!(globalWindow.AdminWeather && typeof globalWindow.AdminWeather.init === 'function');
    }
};

let resolved = false;
let resolving = null;
const loadedScripts = {};
const loadedBundles = {};

function normalizePageKey(value) {
    return String(value || '')
        .trim()
        .toLowerCase()
        .replace(/_+/g, '-')
        .replace(/-page$/i, '');
}

function whenDomReady() {
    if (document.readyState !== 'loading') {
        return Promise.resolve();
    }
    return new Promise(function (resolve) {
        document.addEventListener('DOMContentLoaded', function () {
            resolve();
        }, { once: true });
    });
}

function getCurrentPageKey() {
    const node = document.querySelector('#admin-page [data-admin-page]');
    if (node && node.getAttribute) {
        const fromAttr = normalizePageKey(node.getAttribute('data-admin-page'));
        if (fromAttr) {
            return fromAttr;
        }
    }

    if (globalWindow.AdminPage && typeof globalWindow.AdminPage.detectPageKey === 'function') {
        try {
            const detected = normalizePageKey(globalWindow.AdminPage.detectPageKey());
            if (detected) {
                return detected;
            }
        } catch (error) {}
    }

    return '';
}

function asFeatureList(items) {
    const list = Array.isArray(items) ? items : [];
    return list.map(function (item) {
        if (typeof item === 'string') {
            const key = String(item || '').trim();
            return { src: key, ready: FEATURE_READY_CHECKS[key] || null };
        }
        const src = String(item && item.src ? item.src : '').trim();
        const ready = (item && typeof item.ready === 'function') ? item.ready : (FEATURE_READY_CHECKS[src] || null);
        return { src, ready };
    }).filter(function (item) {
        return item.src !== '';
    });
}

function uniqFeatures(list) {
    const out = [];
    const seen = {};
    for (let i = 0; i < list.length; i += 1) {
        const feature = list[i];
        const key = String(feature && feature.src ? feature.src : '').trim();
        if (!key || seen[key]) {
            continue;
        }
        seen[key] = true;
        out.push(feature);
    }
    return out;
}

function isScriptAlreadyPresent(src) {
    const nodes = document.querySelectorAll('script[src]');
    for (let i = 0; i < nodes.length; i += 1) {
        const value = String(nodes[i].getAttribute('src') || '').trim();
        if (!value) {
            continue;
        }
        if (value === src || value.indexOf(src + '?') === 0) {
            return true;
        }
    }
    return false;
}

function isBundleMode() {
    return isScriptAlreadyPresent('/assets/js/dist/admin-core.bundle.js');
}

function shouldUsePageBundles() {
    if (!isBundleMode()) {
        return false;
    }
    if (globalWindow.__APP_USE_PAGE_BUNDLES === false) {
        return false;
    }
    return true;
}

function isFeatureReady(feature) {
    if (!feature || typeof feature.ready !== 'function') {
        return false;
    }
    try {
        return feature.ready() === true;
    } catch (error) {
        return false;
    }
}

function loadBundleByUrl(src) {
    const key = String(src || '').trim();
    if (!key) {
        return Promise.resolve();
    }
    if (loadedBundles[key] === true || isScriptAlreadyPresent(key)) {
        loadedBundles[key] = true;
        return Promise.resolve();
    }

    return new Promise(function (resolve, reject) {
        const node = document.createElement('script');
        node.type = 'text/javascript';
        node.async = false;
        node.src = key;
        node.onload = function () {
            loadedBundles[key] = true;
            resolve();
        };
        node.onerror = function () {
            reject(new Error('Failed to load bundle: ' + key));
        };
        document.head.appendChild(node);
    });
}

function loadPageBundle(pageKey) {
    const key = normalizePageKey(pageKey);
    const bundleSrc = String(PAGE_BUNDLE_SCRIPTS[key] || '').trim();
    if (!bundleSrc) {
        return Promise.resolve(false);
    }
    return loadBundleByUrl(bundleSrc)
        .then(function () {
            return true;
        })
        .catch(function () {
            return false;
        });
}
function loadScript(feature) {
    const key = String(feature && feature.src ? feature.src : '').trim();
    if (!key) {
        return Promise.resolve();
    }
    if (isFeatureReady(feature)) {
        loadedScripts[key] = true;
        return Promise.resolve();
    }
    if (loadedScripts[key] === true || isScriptAlreadyPresent(key)) {
        loadedScripts[key] = true;
        return Promise.resolve();
    }

    return new Promise(function (resolve, reject) {
        const node = document.createElement('script');
        node.type = 'text/javascript';
        node.async = false;
        node.src = key;
        node.onload = function () {
            loadedScripts[key] = true;
            resolve();
        };
        node.onerror = function () {
            reject(new Error('Failed to load script: ' + key));
        };
        document.head.appendChild(node);
    });
}

function loadSequence(features) {
    let chain = Promise.resolve();
    for (let i = 0; i < features.length; i += 1) {
        const feature = features[i];
        chain = chain.then(function () {
            return loadScript(feature);
        });
    }
    return chain;
}

function resolveSourcesForPage(pageKey, bundleSatisfied) {
    const key = normalizePageKey(pageKey);
    const pageSources = asFeatureList(PAGE_FEATURE_SCRIPTS[key] || []);

    if (isBundleMode()) {
        if (bundleSatisfied === true) {
            return [];
        }
        return uniqFeatures(pageSources);
    }

    return uniqFeatures(asFeatureList(SHARED_FEATURES).concat(pageSources));
}

export function loadForCurrentPage() {
    if (resolved) {
        return Promise.resolve();
    }
    if (resolving) {
        return resolving;
    }

    resolving = whenDomReady()
        .then(function () {
            const pageKey = getCurrentPageKey();
            if (!shouldUsePageBundles()) {
                const features = resolveSourcesForPage(pageKey, false);
                return loadSequence(features);
            }
            return loadPageBundle(pageKey).then(function (bundleSatisfied) {
                const features = resolveSourcesForPage(pageKey, bundleSatisfied);
                return loadSequence(features);
            });
        })
        .then(function () {
            resolved = true;
        })
        .finally(function () {
            resolving = null;
        });

    return resolving;
}

const AdminFeatureLoaderApi = {
    loadForCurrentPage
};

globalWindow.AdminFeatureLoader = globalWindow.AdminFeatureLoader || {};
globalWindow.AdminFeatureLoader.loadForCurrentPage = loadForCurrentPage;

export default AdminFeatureLoaderApi;



