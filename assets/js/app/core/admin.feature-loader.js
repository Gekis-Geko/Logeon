(function (window) {
    'use strict';

    var SHARED_FEATURE_SCRIPTS = [
        '/assets/js/app/features/admin/AdminImageUploader.js'
    ];

    var PAGE_FEATURE_SCRIPTS = {
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
        news: ['/assets/js/app/features/admin/News.js']
    };

    var resolved = false;
    var resolving = null;
    var loadedScripts = {};

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
        var node = document.querySelector('#admin-page [data-admin-page]');
        if (node && node.getAttribute) {
            var fromAttr = normalizePageKey(node.getAttribute('data-admin-page'));
            if (fromAttr) {
                return fromAttr;
            }
        }

        if (window.AdminPage && typeof window.AdminPage.detectPageKey === 'function') {
            try {
                var detected = normalizePageKey(window.AdminPage.detectPageKey());
                if (detected) {
                    return detected;
                }
            } catch (error) {}
        }

        return '';
    }

    function uniqStrings(list) {
        var out = [];
        var seen = {};
        for (var i = 0; i < list.length; i++) {
            var item = String(list[i] || '').trim();
            if (!item || seen[item]) {
                continue;
            }
            seen[item] = true;
            out.push(item);
        }
        return out;
    }

    function isScriptAlreadyPresent(src) {
        var nodes = document.querySelectorAll('script[src]');
        for (var i = 0; i < nodes.length; i++) {
            var value = String(nodes[i].getAttribute('src') || '').trim();
            if (!value) {
                continue;
            }
            if (value === src || value.indexOf(src + '?') === 0) {
                return true;
            }
        }
        return false;
    }

    function loadScript(src) {
        var key = String(src || '').trim();
        if (!key) {
            return Promise.resolve();
        }
        if (loadedScripts[key] === true || isScriptAlreadyPresent(key)) {
            loadedScripts[key] = true;
            return Promise.resolve();
        }

        return new Promise(function (resolve, reject) {
            var node = document.createElement('script');
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

    function loadSequence(sources) {
        var chain = Promise.resolve();
        for (var i = 0; i < sources.length; i++) {
            (function (src) {
                chain = chain.then(function () {
                    return loadScript(src);
                });
            })(sources[i]);
        }
        return chain;
    }

    function resolveSourcesForPage(pageKey) {
        var key = normalizePageKey(pageKey);
        var pageSources = PAGE_FEATURE_SCRIPTS[key] || [];
        return uniqStrings(SHARED_FEATURE_SCRIPTS.concat(pageSources));
    }

    function loadForCurrentPage() {
        if (resolved) {
            return Promise.resolve();
        }
        if (resolving) {
            return resolving;
        }

        resolving = whenDomReady()
            .then(function () {
                var pageKey = getCurrentPageKey();
                var sources = resolveSourcesForPage(pageKey);
                return loadSequence(sources);
            })
            .then(function () {
                resolved = true;
            })
            .finally(function () {
                resolving = null;
            });

        return resolving;
    }

    window.AdminFeatureLoader = window.AdminFeatureLoader || {};
    window.AdminFeatureLoader.loadForCurrentPage = loadForCurrentPage;
})(window);
