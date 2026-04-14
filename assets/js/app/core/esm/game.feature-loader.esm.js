const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

const SHARED_FEATURES = [
    {
        src: '/assets/js/app/features/game/FeatureError.js',
        ready: function () {
            return !!(globalWindow.GameFeatureError && typeof globalWindow.GameFeatureError.toastMapped === 'function');
        }
    },
    {
        src: '/assets/js/app/features/game/AppCore.js',
        ready: function () {
            return typeof globalWindow.App === 'function';
        }
    },
    {
        src: '/assets/js/app/features/game/AppFacade.js',
        ready: function () {
            return globalWindow.__app_facade_loaded === true;
        }
    },
    {
        src: '/assets/js/app/features/game/AppLifecycle.js',
        ready: function () {
            return typeof globalWindow.GameAppInit === 'function';
        }
    },
    {
        src: '/assets/js/app/features/game/AppSounds.js',
        ready: function () {
            return !!globalWindow.AppSounds;
        }
    },
    {
        src: '/assets/js/app/features/game/OnlinesPage.js',
        ready: function () {
            return typeof globalWindow.GameOnlinesPage === 'function';
        }
    }
];

const DEFAULT_PAGE_FEATURE_SCRIPTS = [
    '/assets/js/app/features/game/NotificationsPage.js',
    '/assets/js/app/features/game/MessagesModal.js',
    '/assets/js/app/features/game/MessagesPage.js',
    '/assets/js/app/features/game/QuestsPage.js',
    '/assets/js/app/features/game/NarrativeEventsPage.js',
    '/assets/js/app/features/game/SystemEventsPage.js',
    '/assets/js/app/features/game/WeatherPage.js'
];

const PAGE_FEATURE_SCRIPTS = {
    home: [
        '/assets/js/app/features/game/NotificationsPage.js',
        '/assets/js/app/features/game/NewsPage.js',
        '/assets/js/app/features/game/MessagesModal.js',
        '/assets/js/app/features/game/MessagesPage.js',
        '/assets/js/app/features/game/QuestsPage.js',
        '/assets/js/app/features/game/NarrativeEventsPage.js',
        '/assets/js/app/features/game/SystemEventsPage.js',
        '/assets/js/app/features/game/WeatherPage.js'
    ],
    forum: [
        '/assets/js/app/features/game/NotificationsPage.js',
        '/assets/js/app/features/game/NewsPage.js',
        '/assets/js/app/features/game/MessagesModal.js',
        '/assets/js/app/features/game/MessagesPage.js',
        '/assets/js/app/features/game/QuestsPage.js',
        '/assets/js/app/features/game/NarrativeEventsPage.js',
        '/assets/js/app/features/game/SystemEventsPage.js',
        '/assets/js/app/features/game/forum/ForumPage.js',
        '/assets/js/app/features/game/WeatherPage.js'
    ],
    threads: [
        '/assets/js/app/features/game/NotificationsPage.js',
        '/assets/js/app/features/game/QuestsPage.js',
        '/assets/js/app/features/game/NarrativeEventsPage.js',
        '/assets/js/app/features/game/SystemEventsPage.js',
        '/assets/js/app/features/game/forum/ThreadsPage.js',
        '/assets/js/app/features/game/WeatherPage.js'
    ],
    thread: [
        '/assets/js/app/features/game/NotificationsPage.js',
        '/assets/js/app/features/game/MessagesModal.js',
        '/assets/js/app/features/game/MessagesPage.js',
        '/assets/js/app/features/game/QuestsPage.js',
        '/assets/js/app/features/game/NarrativeEventsPage.js',
        '/assets/js/app/features/game/SystemEventsPage.js',
        '/assets/js/app/features/game/forum/ThreadPage.js',
        '/assets/js/app/features/game/WeatherPage.js'
    ],
    profile: [
        '/assets/js/app/features/game/NotificationsPage.js',
        '/assets/js/app/features/game/NewsPage.js',
        '/assets/js/app/features/game/MessagesModal.js',
        '/assets/js/app/features/game/MessagesPage.js',
        '/assets/js/app/features/game/ProfilePage.js',
        '/assets/js/app/features/game/QuestsPage.js',
        '/assets/js/app/features/game/NarrativeEventsPage.js',
        '/assets/js/app/features/game/SystemEventsPage.js',
        '/assets/js/app/features/game/WeatherPage.js'
    ],
    'profile-edit': [
        '/assets/js/app/features/game/NotificationsPage.js',
        '/assets/js/app/features/game/ProfilePage.js',
        '/assets/js/app/features/game/QuestsPage.js',
        '/assets/js/app/features/game/NarrativeEventsPage.js',
        '/assets/js/app/features/game/SystemEventsPage.js',
        '/assets/js/app/features/game/WeatherPage.js'
    ],
    onlines: [
        '/assets/js/app/features/game/NotificationsPage.js',
        '/assets/js/app/features/game/OnlinesPage.js',
        '/assets/js/app/features/game/QuestsPage.js',
        '/assets/js/app/features/game/NarrativeEventsPage.js',
        '/assets/js/app/features/game/SystemEventsPage.js',
        '/assets/js/app/features/game/WeatherPage.js'
    ],
    location: [
        '/assets/js/app/features/game/NotificationsPage.js',
        '/assets/js/app/features/game/MessagesModal.js',
        '/assets/js/app/features/game/MessagesPage.js',
        '/assets/js/app/features/game/QuestsPage.js',
        '/assets/js/app/features/game/NarrativeEventsPage.js',
        '/assets/js/app/features/game/SystemEventsPage.js',
        '/assets/js/app/features/game/location/LocationPage.js',
        '/assets/js/app/features/game/location/LocationChatPage.js',
        '/assets/js/app/features/game/location/LocationSidebarPage.js',
        '/assets/js/app/features/game/location/LocationWhispersPage.js',
        '/assets/js/app/features/game/location/LocationDropsPage.js',
        '/assets/js/app/features/game/location/LocationInvitesPage.js',
        '/assets/js/app/features/game/WeatherPage.js'
    ],
    shop: [
        '/assets/js/app/features/game/NotificationsPage.js',
        '/assets/js/app/features/game/ShopPage.js',
        '/assets/js/app/features/game/QuestsPage.js',
        '/assets/js/app/features/game/NarrativeEventsPage.js',
        '/assets/js/app/features/game/SystemEventsPage.js',
        '/assets/js/app/features/game/WeatherPage.js'
    ],
    bank: [
        '/assets/js/app/features/game/NotificationsPage.js',
        '/assets/js/app/features/game/BankPage.js',
        '/assets/js/app/features/game/QuestsPage.js',
        '/assets/js/app/features/game/NarrativeEventsPage.js',
        '/assets/js/app/features/game/SystemEventsPage.js',
        '/assets/js/app/features/game/WeatherPage.js'
    ],
    maps: [
        '/assets/js/app/features/game/NotificationsPage.js',
        '/assets/js/app/features/game/MapsPage.js',
        '/assets/js/app/features/game/QuestsPage.js',
        '/assets/js/app/features/game/NarrativeEventsPage.js',
        '/assets/js/app/features/game/SystemEventsPage.js',
        '/assets/js/app/features/game/WeatherPage.js'
    ],
    locations: [
        '/assets/js/app/features/game/NotificationsPage.js',
        '/assets/js/app/features/game/LocationsPage.js',
        '/assets/js/app/features/game/QuestsPage.js',
        '/assets/js/app/features/game/NarrativeEventsPage.js',
        '/assets/js/app/features/game/SystemEventsPage.js',
        '/assets/js/app/features/game/WeatherPage.js'
    ],
    settings: [
        '/assets/js/app/features/game/NotificationsPage.js',
        '/assets/js/app/features/game/SettingsPage.js',
        '/assets/js/app/features/game/QuestsPage.js',
        '/assets/js/app/features/game/NarrativeEventsPage.js',
        '/assets/js/app/features/game/SystemEventsPage.js',
        '/assets/js/app/features/game/WeatherPage.js'
    ],
    jobs: [
        '/assets/js/app/features/game/NotificationsPage.js',
        '/assets/js/app/features/game/JobsPage.js',
        '/assets/js/app/features/game/QuestsPage.js',
        '/assets/js/app/features/game/NarrativeEventsPage.js',
        '/assets/js/app/features/game/SystemEventsPage.js',
        '/assets/js/app/features/game/WeatherPage.js'
    ],
    guilds: [
        '/assets/js/app/features/game/NotificationsPage.js',
        '/assets/js/app/features/game/GuildsPage.js',
        '/assets/js/app/features/game/QuestsPage.js',
        '/assets/js/app/features/game/NarrativeEventsPage.js',
        '/assets/js/app/features/game/SystemEventsPage.js',
        '/assets/js/app/features/game/WeatherPage.js'
    ],
    guild: [
        '/assets/js/app/features/game/NotificationsPage.js',
        '/assets/js/app/features/game/GuildsPage.js',
        '/assets/js/app/features/game/QuestsPage.js',
        '/assets/js/app/features/game/NarrativeEventsPage.js',
        '/assets/js/app/features/game/SystemEventsPage.js',
        '/assets/js/app/features/game/WeatherPage.js'
    ],
    bag: [
        '/assets/js/app/features/game/NotificationsPage.js',
        '/assets/js/app/features/game/InventoryPage.js',
        '/assets/js/app/features/game/QuestsPage.js',
        '/assets/js/app/features/game/NarrativeEventsPage.js',
        '/assets/js/app/features/game/SystemEventsPage.js',
        '/assets/js/app/features/game/WeatherPage.js'
    ],
    equips: [
        '/assets/js/app/features/game/NotificationsPage.js',
        '/assets/js/app/features/game/InventoryPage.js',
        '/assets/js/app/features/game/QuestsPage.js',
        '/assets/js/app/features/game/NarrativeEventsPage.js',
        '/assets/js/app/features/game/SystemEventsPage.js',
        '/assets/js/app/features/game/WeatherPage.js'
    ],
    'quests-history': [
        '/assets/js/app/features/game/NotificationsPage.js',
        '/assets/js/app/features/game/QuestHistoryPage.js',
        '/assets/js/app/features/game/QuestsPage.js',
        '/assets/js/app/features/game/NarrativeEventsPage.js',
        '/assets/js/app/features/game/SystemEventsPage.js',
        '/assets/js/app/features/game/WeatherPage.js'
    ],
    factions: [
        '/assets/js/app/features/game/NotificationsPage.js',
        '/assets/js/app/features/game/FactionsPage.js',
        '/assets/js/app/features/game/QuestsPage.js',
        '/assets/js/app/features/game/NarrativeEventsPage.js',
        '/assets/js/app/features/game/SystemEventsPage.js',
        '/assets/js/app/features/game/WeatherPage.js'
    ],
    anagrafica: [
        '/assets/js/app/features/game/NotificationsPage.js',
        '/assets/js/app/features/game/AnagraficaPage.js',
        '/assets/js/app/features/game/MessagesModal.js',
        '/assets/js/app/features/game/MessagesPage.js',
        '/assets/js/app/features/game/QuestsPage.js',
        '/assets/js/app/features/game/NarrativeEventsPage.js',
        '/assets/js/app/features/game/SystemEventsPage.js',
        '/assets/js/app/features/game/WeatherPage.js'
    ],
    archetypes: [
        '/assets/js/app/features/game/NotificationsPage.js',
        '/assets/js/app/features/game/MessagesModal.js',
        '/assets/js/app/features/game/MessagesPage.js',
        '/assets/js/app/features/game/QuestsPage.js',
        '/assets/js/app/features/game/NarrativeEventsPage.js',
        '/assets/js/app/features/game/SystemEventsPage.js',
        '/assets/js/app/features/game/WeatherPage.js'
    ]
};

const PAGE_BUNDLE_SCRIPTS = {
    home: '/assets/js/dist/game-home.bundle.js',
    location: '/assets/js/dist/game-location.bundle.js',
    forum: '/assets/js/dist/game-community.bundle.js',
    threads: '/assets/js/dist/game-community.bundle.js',
    thread: '/assets/js/dist/game-community.bundle.js',
    onlines: '/assets/js/dist/game-community.bundle.js',
    anagrafica: '/assets/js/dist/game-community.bundle.js',
    profile: '/assets/js/dist/game-character.bundle.js',
    'profile-edit': '/assets/js/dist/game-character.bundle.js',
    jobs: '/assets/js/dist/game-character.bundle.js',
    guilds: '/assets/js/dist/game-character.bundle.js',
    guild: '/assets/js/dist/game-character.bundle.js',
    bag: '/assets/js/dist/game-character.bundle.js',
    equips: '/assets/js/dist/game-character.bundle.js',
    'quests-history': '/assets/js/dist/game-character.bundle.js',
    factions: '/assets/js/dist/game-character.bundle.js',
    archetypes: '/assets/js/dist/game-character.bundle.js',
    shop: '/assets/js/dist/game-world.bundle.js',
    bank: '/assets/js/dist/game-world.bundle.js',
    maps: '/assets/js/dist/game-world.bundle.js',
    locations: '/assets/js/dist/game-world.bundle.js',
    settings: '/assets/js/dist/game-world.bundle.js'
};

const FEATURE_READY_CHECKS = {
    '/assets/js/app/features/game/NotificationsPage.js': function () {
        return !!(globalWindow.NotificationsPage && typeof globalWindow.NotificationsPage.init === 'function');
    },
    '/assets/js/app/features/game/NewsPage.js': function () {
        return typeof globalWindow.GameNewsPage === 'function';
    },
    '/assets/js/app/features/game/MessagesModal.js': function () {
        return !!(globalWindow.GameMessagesModal && typeof globalWindow.GameMessagesModal.openMessageModal === 'function');
    },
    '/assets/js/app/features/game/MessagesPage.js': function () {
        return typeof globalWindow.GameMessagesPage === 'function';
    },
    '/assets/js/app/features/game/QuestsPage.js': function () {
        return typeof globalWindow.GameQuestsPage === 'function';
    },
    '/assets/js/app/features/game/NarrativeEventsPage.js': function () {
        return typeof globalWindow.GameNarrativeEventsPage === 'function';
    },
    '/assets/js/app/features/game/SystemEventsPage.js': function () {
        return typeof globalWindow.GameSystemEventsPage === 'function';
    },
    '/assets/js/app/features/game/WeatherPage.js': function () {
        return typeof globalWindow.GameWeatherPage === 'function';
    },
    '/assets/js/app/features/game/location/LocationPage.js': function () {
        return typeof globalWindow.GameLocationPage === 'function';
    },
    '/assets/js/app/features/game/location/LocationChatPage.js': function () {
        return typeof globalWindow.GameLocationChatPage === 'function';
    },
    '/assets/js/app/features/game/location/LocationSidebarPage.js': function () {
        return typeof globalWindow.GameLocationSidebarPage === 'function';
    },
    '/assets/js/app/features/game/location/LocationWhispersPage.js': function () {
        return typeof globalWindow.GameLocationWhispersPage === 'function';
    },
    '/assets/js/app/features/game/location/LocationDropsPage.js': function () {
        return typeof globalWindow.GameLocationDropsPage === 'function';
    },
    '/assets/js/app/features/game/location/LocationInvitesPage.js': function () {
        return typeof globalWindow.GameLocationInvitesPage === 'function';
    },
    '/assets/js/app/features/game/OnlinesPage.js': function () {
        return typeof globalWindow.GameOnlinesPage === 'function';
    }
};

let resolved = false;
let resolving = null;
const loadedScripts = {};
const loadedBundles = {};
const featureAvailabilityCache = {};

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
    const root = document.getElementById('page-content');
    if (root && root.getAttribute) {
        const fromAttr = normalizePageKey(root.getAttribute('data-app-page'));
        if (fromAttr) {
            return fromAttr;
        }
    }

    if (globalWindow.GamePage && typeof globalWindow.GamePage.detectPageKey === 'function') {
        try {
            const detected = normalizePageKey(globalWindow.GamePage.detectPageKey());
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
    return isScriptAlreadyPresent('/assets/js/dist/game-core.bundle.js');
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

function probeFeatureSourceAvailability(src) {
    const key = String(src || '').trim();
    if (!key) {
        return Promise.resolve(false);
    }
    if (featureAvailabilityCache[key] === true || featureAvailabilityCache[key] === false) {
        return Promise.resolve(featureAvailabilityCache[key]);
    }

    return fetch(key, { method: 'HEAD', cache: 'no-store' })
        .then(function (response) {
            const available = !!(response && response.ok);
            featureAvailabilityCache[key] = available;
            return available;
        })
        .catch(function () {
            featureAvailabilityCache[key] = false;
            return false;
        });
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
    const pageConfig = PAGE_FEATURE_SCRIPTS[key] || DEFAULT_PAGE_FEATURE_SCRIPTS;
    const pageSources = asFeatureList(pageConfig);

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
            const forcePageBundles = shouldUsePageBundles();
            if (forcePageBundles) {
                return loadPageBundle(pageKey).then(function (bundleSatisfied) {
                    const features = resolveSourcesForPage(pageKey, bundleSatisfied);
                    return loadSequence(features);
                });
            }

            const features = resolveSourcesForPage(pageKey, false);
            if (!isBundleMode() || !features.length) {
                return loadSequence(features);
            }

            return probeFeatureSourceAvailability(features[0].src).then(function (sourceAvailable) {
                if (sourceAvailable) {
                    return loadSequence(features);
                }

                return loadPageBundle(pageKey).then(function (bundleSatisfied) {
                    if (bundleSatisfied) {
                        const fallbackFeatures = resolveSourcesForPage(pageKey, true);
                        return loadSequence(fallbackFeatures);
                    }
                    return loadSequence(features);
                });
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

const GameFeatureLoaderApi = {
    loadForCurrentPage
};

globalWindow.GameFeatureLoader = globalWindow.GameFeatureLoader || {};
globalWindow.GameFeatureLoader.loadForCurrentPage = loadForCurrentPage;

export default GameFeatureLoaderApi;



