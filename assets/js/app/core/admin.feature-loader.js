const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

const SHARED_FEATURES = [
    '/assets/js/app/features/admin/AdminImageUploader.js'
];

const PAGE_FEATURE_SCRIPTS = {
    dashboard: ['/assets/js/app/features/admin/Dashboard.js'],
    users: ['/assets/js/app/features/admin/Users.js'],
    characters: ['/assets/js/app/features/admin/Characters.js'],
    blacklist: ['/assets/js/app/features/admin/Blacklist.js'],
    themes: ['/assets/js/app/features/admin/Themes.js'],
    modules: ['/assets/js/app/features/admin/Modules.js'],
    maps: ['/assets/js/app/features/admin/Maps.js'],
    currencies: ['/assets/js/app/features/admin/Currencies.js'],
    shops: ['/assets/js/app/features/admin/Shops.js'],
    conflicts: ['/assets/js/app/features/admin/Conflicts.js'],
    'narrative-events': ['/assets/js/app/features/admin/NarrativeEvents.js'],
    'narrative-states': ['/assets/js/app/features/admin/NarrativeStates.js'],
    'system-events': ['/assets/js/app/features/admin/SystemEvents.js'],
    'character-lifecycle': ['/assets/js/app/features/admin/CharacterLifecycle.js'],
    'character-requests': ['/assets/js/app/features/admin/CharacterRequests.js'],
    'inventory-shop': ['/assets/js/app/features/admin/ShopInventory.js'],
    locations: ['/assets/js/app/features/admin/Locations.js'],
    jobs: ['/assets/js/app/features/admin/Jobs.js'],
    'jobs-tasks': ['/assets/js/app/features/admin/JobsTasks.js'],
    'jobs-levels': ['/assets/js/app/features/admin/JobsLevels.js'],
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
    'narrative-tags': ['/assets/js/app/features/admin/NarrativeTags.js'],
    'message-reports': ['/assets/js/app/features/admin/MessageReports.js'],
    news: ['/assets/js/app/features/admin/News.js'],
    'narrative-delegation-grants': ['/assets/js/app/features/admin/NarrativeDelegationGrants.js'],
    'narrative-npcs': ['/assets/js/app/features/admin/NarrativeNpcs.js'],
    'logs-narrative': ['/assets/js/app/features/admin/LogsNarrative.js'],
    'location-position-tags': ['/assets/js/app/features/admin/LocationPositionTags.js']
};

const PAGE_BUNDLE_SCRIPTS = {
    dashboard: '/assets/js/dist/admin-priority.bundle.js',
    users: '/assets/js/dist/admin-priority.bundle.js',
    characters: '/assets/js/dist/admin-governance.bundle.js',
    blacklist: '/assets/js/dist/admin-governance.bundle.js',
    themes: '/assets/js/dist/admin-governance.bundle.js',
    modules: '/assets/js/dist/admin-governance.bundle.js',
    maps: '/assets/js/dist/admin-governance.bundle.js',
    'character-lifecycle': '/assets/js/dist/admin-governance.bundle.js',
    'character-requests': '/assets/js/dist/admin-governance.bundle.js',
    locations: '/assets/js/dist/admin-governance.bundle.js',
    jobs: '/assets/js/dist/admin-governance.bundle.js',
    'jobs-tasks': '/assets/js/dist/admin-governance.bundle.js',
    'jobs-levels': '/assets/js/dist/admin-governance.bundle.js',
    guilds: '/assets/js/dist/admin-governance.bundle.js',
    'guild-alignments': '/assets/js/dist/admin-governance.bundle.js',
    'guilds-reqs': '/assets/js/dist/admin-governance.bundle.js',
    'guilds-locations': '/assets/js/dist/admin-governance.bundle.js',
    'guilds-events': '/assets/js/dist/admin-governance.bundle.js',
    settings: '/assets/js/dist/admin-governance.bundle.js',
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
    'logs-narrative': '/assets/js/dist/admin-logs.bundle.js',
    'location-position-tags': '/assets/js/dist/admin-governance.bundle.js'
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
            if (!shouldUsePageBundles()) {
                return loadSequence(uniqSrcs(SHARED_FEATURES.concat(PAGE_FEATURE_SCRIPTS[key] || [])));
            }
            const bundleSrc = PAGE_BUNDLE_SCRIPTS[key] || '';
            if (!bundleSrc) {
                return loadSequence(uniqSrcs(PAGE_FEATURE_SCRIPTS[key] || []));
            }
            return import(bundleSrc)
                .then(function () {})
                .catch(function () {
                    return loadSequence(uniqSrcs(PAGE_FEATURE_SCRIPTS[key] || []));
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

const AdminFeatureLoaderApi = {
    loadForCurrentPage,
    registerPageScripts
};

globalWindow.AdminFeatureLoader = globalWindow.AdminFeatureLoader || {};
globalWindow.AdminFeatureLoader.loadForCurrentPage = loadForCurrentPage;
globalWindow.AdminFeatureLoader.registerPageScripts = registerPageScripts;

export default AdminFeatureLoaderApi;
