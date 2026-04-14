(function (window) {
    'use strict';

    var MODULE_FACTORY_MAP = {
        'admin.dashboard': 'DashboardModuleFactory',
        'admin.users': 'AdminUsersModuleFactory',
        'admin.characters': 'AdminCharactersModuleFactory',
        'admin.blacklist': 'AdminBlacklistModuleFactory',
        'admin.themes': 'AdminThemesModuleFactory',
        'admin.modules': 'AdminModulesModuleFactory',
        'admin.character-attributes': 'AdminCharacterAttributesModuleFactory',
        'admin.maps': 'AdminMapsModuleFactory',
        'admin.currencies': 'AdminCurrenciesModuleFactory',
        'admin.shops': 'AdminShopsModuleFactory',
        'admin.conflicts': 'AdminConflictsModuleFactory',
        'admin.narrative-events': 'AdminNarrativeEventsModuleFactory',
        'admin.narrative-states': 'AdminNarrativeStatesModuleFactory',
        'admin.quests': 'AdminQuestsModuleFactory',
        'admin.system-events': 'AdminSystemEventsModuleFactory',
        'admin.character-lifecycle': 'AdminCharacterLifecycleModuleFactory',
        'admin.factions': 'AdminFactionsModuleFactory',
        'admin.weather': 'AdminWeatherModuleFactory',
        'admin.weather-overview': 'AdminWeatherModuleFactory',
        'admin.weather-catalogs': 'AdminWeatherModuleFactory',
        'admin.weather-profiles': 'AdminWeatherModuleFactory',
        'admin.weather-overrides': 'AdminWeatherModuleFactory',
        'admin.character-requests': 'AdminCharacterRequestsModuleFactory',
        'admin.shop-inventory': 'AdminShopInventoryModuleFactory',
        'admin.locations': 'AdminLocationsModuleFactory',
        'admin.jobs': 'AdminJobsModuleFactory',
        'admin.jobs-tasks': 'AdminJobsTasksModuleFactory',
        'admin.jobs-levels': 'AdminJobsLevelsModuleFactory',
        'admin.social-status': 'AdminSocialStatusModuleFactory',
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
        'admin.archetypes': 'AdminArchetypesModuleFactory',
        'admin.narrative-tags': 'AdminNarrativeTagsModuleFactory',
        'admin.message-reports': 'AdminMessageReportsModuleFactory',
        'admin.news': 'AdminNewsModuleFactory',
        'admin.narrative-delegation-grants': 'AdminNarrativeDelegationGrantsModuleFactory',
        'admin.narrative-npcs': 'AdminNarrativeNpcsModuleFactory'
    };

    function createNoopFactory() {
        return function () {
            return {
                mount: function () {},
                unmount: function () {}
            };
        };
    }

    function createDashboardFallbackFactory() {
        return function () {
            return {
                mount: function (_ctx, options) {
                    if (typeof window.Dashboard !== 'undefined' && window.Dashboard && typeof window.Dashboard.init === 'function') {
                        var config = (options && options.config) || window.DASHBOARD_CONFIG || window.ADMIN_DASHBOARD_CONFIG || null;
                        window.Dashboard.init(config);
                    }
                },
                unmount: function () {}
            };
        };
    }

    function createUsersFallbackFactory() {
        return function () {
            return {
                mount: function () {
                    if (typeof window.AdminUsers !== 'undefined' && window.AdminUsers && typeof window.AdminUsers.init === 'function') {
                        window.AdminUsers.init();
                    }
                },
                unmount: function () {}
            };
        };
    }

    function createCharactersFallbackFactory() {
        return function () {
            return {
                mount: function () {
                    if (typeof window.AdminCharacters !== 'undefined' && window.AdminCharacters && typeof window.AdminCharacters.init === 'function') {
                        window.AdminCharacters.init();
                    }
                },
                unmount: function () {}
            };
        };
    }

    function createBlacklistFallbackFactory() {
        return function () {
            return {
                mount: function () {
                    if (typeof window.AdminBlacklist !== 'undefined' && window.AdminBlacklist && typeof window.AdminBlacklist.init === 'function') {
                        window.AdminBlacklist.init();
                    }
                },
                unmount: function () {}
            };
        };
    }

    function createModulesFallbackFactory() {
        return function () {
            return {
                mount: function () {
                    if (typeof window.AdminModules !== 'undefined' && window.AdminModules && typeof window.AdminModules.init === 'function') {
                        window.AdminModules.init();
                    }
                },
                unmount: function () {}
            };
        };
    }

    function createThemesFallbackFactory() {
        return function () {
            return {
                mount: function () {
                    if (typeof window.AdminThemes !== 'undefined' && window.AdminThemes && typeof window.AdminThemes.init === 'function') {
                        window.AdminThemes.init();
                    }
                },
                unmount: function () {}
            };
        };
    }

    function createCharacterAttributesFallbackFactory() {
        return function () {
            return {
                mount: function () {
                    if (typeof window.AdminCharacterAttributes !== 'undefined' && window.AdminCharacterAttributes && typeof window.AdminCharacterAttributes.init === 'function') {
                        window.AdminCharacterAttributes.init();
                    }
                },
                unmount: function () {}
            };
        };
    }

    function createMapsFallbackFactory() {
        return function () {
            return {
                mount: function () {
                    if (typeof window.AdminMaps !== 'undefined' && window.AdminMaps && typeof window.AdminMaps.init === 'function') {
                        window.AdminMaps.init();
                    }
                },
                unmount: function () {}
            };
        };
    }

    function createCurrenciesFallbackFactory() {
        return function () {
            return {
                mount: function () {
                    if (typeof window.AdminCurrencies !== 'undefined' && window.AdminCurrencies && typeof window.AdminCurrencies.init === 'function') {
                        window.AdminCurrencies.init();
                    }
                },
                unmount: function () {}
            };
        };
    }

    function createShopsFallbackFactory() {
        return function () {
            return {
                mount: function () {
                    if (typeof window.AdminShops !== 'undefined' && window.AdminShops && typeof window.AdminShops.init === 'function') {
                        window.AdminShops.init();
                    }
                },
                unmount: function () {}
            };
        };
    }

    function createConflictsFallbackFactory() {
        return function () {
            return {
                mount: function () {
                    if (typeof window.AdminConflicts !== 'undefined' && window.AdminConflicts && typeof window.AdminConflicts.init === 'function') {
                        window.AdminConflicts.init();
                    }
                },
                unmount: function () {}
            };
        };
    }

    function createSystemEventsFallbackFactory() {
        return function () {
            return {
                mount: function () {
                    if (typeof window.AdminSystemEvents !== 'undefined' && window.AdminSystemEvents && typeof window.AdminSystemEvents.init === 'function') {
                        window.AdminSystemEvents.init();
                    }
                },
                unmount: function () {}
            };
        };
    }

    function resolveFactory(moduleName) {
        var key = String(moduleName || '').trim();
        if (!key) {
            return createNoopFactory();
        }

        var factoryGlobalName = MODULE_FACTORY_MAP[key];
        if (factoryGlobalName && typeof window[factoryGlobalName] === 'function') {
            return window[factoryGlobalName];
        }

        if (key === 'admin.dashboard') {
            return createDashboardFallbackFactory();
        }
        if (key === 'admin.users') {
            return createUsersFallbackFactory();
        }
        if (key === 'admin.characters') {
            return createCharactersFallbackFactory();
        }
        if (key === 'admin.blacklist') {
            return createBlacklistFallbackFactory();
        }
        if (key === 'admin.themes') {
            return createThemesFallbackFactory();
        }
        if (key === 'admin.modules') {
            return createModulesFallbackFactory();
        }
        if (key === 'admin.character-attributes') {
            return createCharacterAttributesFallbackFactory();
        }
        if (key === 'admin.maps') {
            return createMapsFallbackFactory();
        }
        if (key === 'admin.currencies') {
            return createCurrenciesFallbackFactory();
        }
        if (key === 'admin.shops') {
            return createShopsFallbackFactory();
        }
        if (key === 'admin.conflicts') {
            return createConflictsFallbackFactory();
        }
        if (key === 'admin.quests') {
            return function () {
                return {
                    mount: function () {
                        if (typeof window.AdminQuests !== 'undefined' && window.AdminQuests && typeof window.AdminQuests.init === 'function') {
                            window.AdminQuests.init();
                        }
                    },
                    unmount: function () {}
                };
            };
        }
        if (key === 'admin.system-events') {
            return createSystemEventsFallbackFactory();
        }

        return createNoopFactory();
    }

    function getPageModules() {
        return {
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
            'weather-overview': ['admin.weather-overview'],
            'weather-catalogs': ['admin.weather-catalogs'],
            'weather-profiles': ['admin.weather-profiles'],
            'weather-overrides': ['admin.weather-overrides'],
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
            'forums': ['admin.forums'],
            'forums-types': ['admin.forum-types'],
            'storyboards': ['admin.storyboards'],
            'rules': ['admin.rules'],
            'how-to-play': ['admin.how-to-play'],
            'items': ['admin.items'],
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
            'settings': ['admin.settings'],
            'archetypes': ['admin.archetypes'],
            'narrative-tags': ['admin.narrative-tags'],
            'message-reports': ['admin.message-reports'],
            'news': ['admin.news'],
            'narrative-delegation-grants': ['admin.narrative-delegation-grants'],
            'narrative-npcs': ['admin.narrative-npcs']
        };
    }

    function getPageConfig() {
        return {
            selector: '#admin-page [data-admin-page]',
            attribute: 'data-admin-page',
            modules: getPageModules()
        };
    }

    function registerModules(app) {
        if (!app || typeof app.register !== 'function') {
            return;
        }

        Object.keys(MODULE_FACTORY_MAP).forEach(function (moduleName) {
            app.register(moduleName, resolveFactory(moduleName));
        });
    }

    window.AdminRegistry = window.AdminRegistry || {};
    window.AdminRegistry.resolveFactory = resolveFactory;
    window.AdminRegistry.getPageModules = getPageModules;
    window.AdminRegistry.getPageConfig = getPageConfig;
    window.AdminRegistry.registerModules = registerModules;
})(window);
