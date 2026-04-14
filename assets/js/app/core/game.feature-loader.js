(function (window) {
    'use strict';

    var SHARED_FEATURE_SCRIPTS = [
        '/assets/js/app/features/game/FeatureError.js',
        '/assets/js/app/features/game/AppCore.js',
        '/assets/js/app/features/game/AppFacade.js',
        '/assets/js/app/features/game/AppLifecycle.js',
        '/assets/js/app/features/game/AppSounds.js',
        '/assets/js/app/features/game/OnlinesPage.js'
    ];

    var PAGE_FEATURE_SCRIPTS = {
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
        ]
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
        var root = document.getElementById('page-content');
        if (root && root.getAttribute) {
            var fromAttr = normalizePageKey(root.getAttribute('data-app-page'));
            if (fromAttr) {
                return fromAttr;
            }
        }

        if (window.GamePage && typeof window.GamePage.detectPageKey === 'function') {
            try {
                var detected = normalizePageKey(window.GamePage.detectPageKey());
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

    window.GameFeatureLoader = window.GameFeatureLoader || {};
    window.GameFeatureLoader.loadForCurrentPage = loadForCurrentPage;
})(window);
