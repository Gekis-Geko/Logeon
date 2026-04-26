const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

const SHARED_FEATURES = [
    '/assets/js/app/features/game/FeatureError.js',
    '/assets/js/app/features/game/AppCore.js',
    '/assets/js/app/features/game/AppFacade.js',
    '/assets/js/app/features/game/AppLifecycle.js',
    '/assets/js/app/features/game/AppSounds.js',
    '/assets/js/app/features/game/OnlinesPage.js'
];

const DEFAULT_PAGE_FEATURE_SCRIPTS = [
    '/assets/js/app/features/game/NotificationsPage.js',
    '/assets/js/app/features/game/MessagesModal.js',
    '/assets/js/app/features/game/MessagesPage.js',
    '/assets/js/app/features/game/NarrativeEventsPage.js',
    '/assets/js/app/features/game/SystemEventsPage.js'
];

const PAGE_FEATURE_SCRIPTS = {
    home: [
        '/assets/js/app/features/game/NotificationsPage.js',
        '/assets/js/app/features/game/NewsPage.js',
        '/assets/js/app/features/game/MessagesModal.js',
        '/assets/js/app/features/game/MessagesPage.js',
        '/assets/js/app/features/game/NarrativeEventsPage.js',
        '/assets/js/app/features/game/SystemEventsPage.js'
    ],
    forum: [
        '/assets/js/app/features/game/NotificationsPage.js',
        '/assets/js/app/features/game/NewsPage.js',
        '/assets/js/app/features/game/MessagesModal.js',
        '/assets/js/app/features/game/MessagesPage.js',
        '/assets/js/app/features/game/NarrativeEventsPage.js',
        '/assets/js/app/features/game/SystemEventsPage.js',
        '/assets/js/app/features/game/forum/ForumPage.js'
    ],
    threads: [
        '/assets/js/app/features/game/NotificationsPage.js',
        '/assets/js/app/features/game/MessagesModal.js',
        '/assets/js/app/features/game/MessagesPage.js',
        '/assets/js/app/features/game/NarrativeEventsPage.js',
        '/assets/js/app/features/game/SystemEventsPage.js',
        '/assets/js/app/features/game/forum/ThreadsPage.js'
    ],
    thread: [
        '/assets/js/app/features/game/NotificationsPage.js',
        '/assets/js/app/features/game/MessagesModal.js',
        '/assets/js/app/features/game/MessagesPage.js',
        '/assets/js/app/features/game/NarrativeEventsPage.js',
        '/assets/js/app/features/game/SystemEventsPage.js',
        '/assets/js/app/features/game/forum/ThreadPage.js'
    ],
    profile: [
        '/assets/js/app/features/game/NotificationsPage.js',
        '/assets/js/app/features/game/NewsPage.js',
        '/assets/js/app/features/game/MessagesModal.js',
        '/assets/js/app/features/game/MessagesPage.js',
        '/assets/js/app/features/game/ProfilePage.js',
        '/assets/js/app/features/game/NarrativeEventsPage.js',
        '/assets/js/app/features/game/SystemEventsPage.js'
    ],
    'profile-edit': [
        '/assets/js/app/features/game/NotificationsPage.js',
        '/assets/js/app/features/game/MessagesModal.js',
        '/assets/js/app/features/game/MessagesPage.js',
        '/assets/js/app/features/game/ProfilePage.js',
        '/assets/js/app/features/game/NarrativeEventsPage.js',
        '/assets/js/app/features/game/SystemEventsPage.js'
    ],
    onlines: [
        '/assets/js/app/features/game/NotificationsPage.js',
        '/assets/js/app/features/game/MessagesModal.js',
        '/assets/js/app/features/game/MessagesPage.js',
        '/assets/js/app/features/game/OnlinesPage.js',
        '/assets/js/app/features/game/NarrativeEventsPage.js',
        '/assets/js/app/features/game/SystemEventsPage.js'
    ],
    location: [
        '/assets/js/app/features/game/NotificationsPage.js',
        '/assets/js/app/features/game/MessagesModal.js',
        '/assets/js/app/features/game/MessagesPage.js',
        '/assets/js/app/features/game/NarrativeEventsPage.js',
        '/assets/js/app/features/game/SystemEventsPage.js',
        '/assets/js/app/features/game/location/LocationPage.js',
        '/assets/js/app/features/game/location/LocationChatPage.js',
        '/assets/js/app/features/game/location/LocationSidebarPage.js',
        '/assets/js/app/features/game/location/LocationWhispersPage.js',
        '/assets/js/app/features/game/location/LocationDropsPage.js',
        '/assets/js/app/features/game/location/LocationInvitesPage.js',
        '/assets/js/app/features/game/location/ChatArchiveCreateModal.js'
    ],
    shop: [
        '/assets/js/app/features/game/NotificationsPage.js',
        '/assets/js/app/features/game/MessagesModal.js',
        '/assets/js/app/features/game/MessagesPage.js',
        '/assets/js/app/features/game/ShopPage.js',
        '/assets/js/app/features/game/NarrativeEventsPage.js',
        '/assets/js/app/features/game/SystemEventsPage.js'
    ],
    bank: [
        '/assets/js/app/features/game/NotificationsPage.js',
        '/assets/js/app/features/game/MessagesModal.js',
        '/assets/js/app/features/game/MessagesPage.js',
        '/assets/js/app/features/game/BankPage.js',
        '/assets/js/app/features/game/NarrativeEventsPage.js',
        '/assets/js/app/features/game/SystemEventsPage.js'
    ],
    maps: [
        '/assets/js/app/features/game/NotificationsPage.js',
        '/assets/js/app/features/game/MessagesModal.js',
        '/assets/js/app/features/game/MessagesPage.js',
        '/assets/js/app/features/game/MapsPage.js',
        '/assets/js/app/features/game/NarrativeEventsPage.js',
        '/assets/js/app/features/game/SystemEventsPage.js'
    ],
    locations: [
        '/assets/js/app/features/game/NotificationsPage.js',
        '/assets/js/app/features/game/MessagesModal.js',
        '/assets/js/app/features/game/MessagesPage.js',
        '/assets/js/app/features/game/LocationsPage.js',
        '/assets/js/app/features/game/NarrativeEventsPage.js',
        '/assets/js/app/features/game/SystemEventsPage.js'
    ],
    settings: [
        '/assets/js/app/features/game/NotificationsPage.js',
        '/assets/js/app/features/game/MessagesModal.js',
        '/assets/js/app/features/game/MessagesPage.js',
        '/assets/js/app/features/game/SettingsPage.js',
        '/assets/js/app/features/game/NarrativeEventsPage.js',
        '/assets/js/app/features/game/SystemEventsPage.js'
    ],
    'chat-archives': [
        '/assets/js/app/features/game/NotificationsPage.js',
        '/assets/js/app/features/game/MessagesModal.js',
        '/assets/js/app/features/game/MessagesPage.js',
        '/assets/js/app/features/game/ChatArchivesPage.js',
        '/assets/js/app/features/game/NarrativeEventsPage.js',
        '/assets/js/app/features/game/SystemEventsPage.js'
    ],
    jobs: [
        '/assets/js/app/features/game/NotificationsPage.js',
        '/assets/js/app/features/game/MessagesModal.js',
        '/assets/js/app/features/game/MessagesPage.js',
        '/assets/js/app/features/game/JobsPage.js',
        '/assets/js/app/features/game/NarrativeEventsPage.js',
        '/assets/js/app/features/game/SystemEventsPage.js'
    ],
    guilds: [
        '/assets/js/app/features/game/NotificationsPage.js',
        '/assets/js/app/features/game/MessagesModal.js',
        '/assets/js/app/features/game/MessagesPage.js',
        '/assets/js/app/features/game/GuildsPage.js',
        '/assets/js/app/features/game/NarrativeEventsPage.js',
        '/assets/js/app/features/game/SystemEventsPage.js'
    ],
    guild: [
        '/assets/js/app/features/game/NotificationsPage.js',
        '/assets/js/app/features/game/MessagesModal.js',
        '/assets/js/app/features/game/MessagesPage.js',
        '/assets/js/app/features/game/GuildsPage.js',
        '/assets/js/app/features/game/NarrativeEventsPage.js',
        '/assets/js/app/features/game/SystemEventsPage.js'
    ],
    bag: [
        '/assets/js/app/features/game/NotificationsPage.js',
        '/assets/js/app/features/game/MessagesModal.js',
        '/assets/js/app/features/game/MessagesPage.js',
        '/assets/js/app/features/game/InventoryPage.js',
        '/assets/js/app/features/game/NarrativeEventsPage.js',
        '/assets/js/app/features/game/SystemEventsPage.js'
    ],
    equips: [
        '/assets/js/app/features/game/NotificationsPage.js',
        '/assets/js/app/features/game/MessagesModal.js',
        '/assets/js/app/features/game/MessagesPage.js',
        '/assets/js/app/features/game/InventoryPage.js',
        '/assets/js/app/features/game/NarrativeEventsPage.js',
        '/assets/js/app/features/game/SystemEventsPage.js'
    ],
    anagrafica: [
        '/assets/js/app/features/game/NotificationsPage.js',
        '/assets/js/app/features/game/AnagraficaPage.js',
        '/assets/js/app/features/game/MessagesModal.js',
        '/assets/js/app/features/game/MessagesPage.js',
        '/assets/js/app/features/game/NarrativeEventsPage.js',
        '/assets/js/app/features/game/SystemEventsPage.js'
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
    shop: '/assets/js/dist/game-world.bundle.js',
    bank: '/assets/js/dist/game-world.bundle.js',
    maps: '/assets/js/dist/game-world.bundle.js',
    locations: '/assets/js/dist/game-world.bundle.js',
    settings: '/assets/js/dist/game-world.bundle.js',
    'chat-archives': '/assets/js/dist/game-character.bundle.js'
};

let resolved = false;
let resolving = null;

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

function shouldUsePageBundles() {
    return globalWindow.__APP_USE_PAGE_BUNDLES === true;
}

function uniqSrcs(list) {
    const out = [];
    const seen = {};
    for (let i = 0; i < list.length; i += 1) {
        const src = String(list[i] || '').trim();
        if (src && !seen[src]) {
            seen[src] = true;
            out.push(src);
        }
    }
    return out;
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

function loadSequence(srcs) {
    let chain = Promise.resolve();
    for (let i = 0; i < srcs.length; i += 1) {
        const src = srcs[i];
        chain = chain.then(function () {
            return import(src).catch(function () {});
        });
    }
    return chain;
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
            const key = normalizePageKey(getCurrentPageKey());
            const pageSrcs = PAGE_FEATURE_SCRIPTS[key] || DEFAULT_PAGE_FEATURE_SCRIPTS;
            if (!shouldUsePageBundles()) {
                return loadSequence(uniqSrcs(SHARED_FEATURES.concat(pageSrcs)));
            }
            const bundleSrc = PAGE_BUNDLE_SCRIPTS[key] || '';
            if (!bundleSrc) {
                return loadSequence(uniqSrcs(pageSrcs));
            }
            return import(bundleSrc)
                .then(function () {})
                .catch(function () {
                    return loadSequence(uniqSrcs(pageSrcs));
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

export function registerPageScripts(pageKey, paths, opts) {
    const key = normalizePageKey(pageKey);
    if (!key || !Array.isArray(paths) || !paths.length) {
        return;
    }
    if (!PAGE_FEATURE_SCRIPTS[key]) {
        PAGE_FEATURE_SCRIPTS[key] = [];
    }

    const order = normalizeOrderOptions(opts);
    const uniquePaths = [];
    for (let i = 0; i < paths.length; i += 1) {
        const p = String(paths[i] || '').trim();
        if (p && uniquePaths.indexOf(p) === -1) {
            uniquePaths.push(p);
        }
    }

    const pathsToInsert = (order.after && !order.before)
        ? uniquePaths.slice().reverse()
        : uniquePaths;
    for (let i = 0; i < pathsToInsert.length; i += 1) {
        insertUniqueWithOrder(PAGE_FEATURE_SCRIPTS[key], pathsToInsert[i], order);
    }
}

const GameFeatureLoaderApi = {
    loadForCurrentPage,
    registerPageScripts
};

globalWindow.GameFeatureLoader = globalWindow.GameFeatureLoader || {};
globalWindow.GameFeatureLoader.loadForCurrentPage = loadForCurrentPage;
globalWindow.GameFeatureLoader.registerPageScripts = registerPageScripts;

export default GameFeatureLoaderApi;
