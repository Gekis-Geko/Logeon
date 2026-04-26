const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function App(extension) {
    function emitAppEvent(eventName) {
        if (typeof globalWindow.AppEvents !== 'undefined' && typeof globalWindow.AppEvents.emit === 'function') {
            globalWindow.AppEvents.emit(eventName);
        }
    }

    function safeShowReloadToast() {
        if (typeof globalWindow.Toast === 'undefined' || typeof globalWindow.Toast.show !== 'function') {
            return;
        }

        globalWindow.Toast.show({
            body: '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Aggiornamento in corso ...',
        });
    }

    function buildPageMethod(factoryName, argBuilder) {
        return function () {
            let args = (typeof argBuilder === 'function')
                ? argBuilder.apply(null, arguments)
                : [arguments[0] || {}];
            return this.callFactory(factoryName, args);
        };
    }

    let pageFactories = {
        News: 'GameNewsPage',
        AvailabilityObserver: 'GameAvailabilityObserverPage',
        Settings: 'GameSettingsPage',
        Jobs: 'GameJobsPage',
        JobSummary: 'GameJobSummaryPage',
        Guilds: 'GameGuildsPage',
        Guild: 'GameGuildPage',
        Equips: 'GameEquipsPage',
        Forum: 'GameForumPage',
        Maps: 'GameMapsPage',
        Location: 'GameLocationPage',
        LocationChat: 'GameLocationChatPage',
        LocationSidebar: 'GameLocationSidebarPage',
        LocationWhispers: 'GameLocationWhispersPage',
        LocationDrops: 'GameLocationDropsPage',
        LocationInvites: 'GameLocationInvitesPage',
        Messages: 'GameMessagesPage',
        Shop: 'GameShopPage',
        Bank: 'GameBankPage',
    };

    let pageFactoriesWithArgs = {
        Profile: {
            factory: 'GameProfilePage',
            args: function (character_id, extension) {
                return [character_id, extension || {}];
            }
        },
        Bag: {
            factory: 'GameBagPage',
            args: function (char_id, extension) {
                return [char_id, extension || {}];
            }
        },
        Threads: {
            factory: 'GameThreadsPage',
            args: function (forum_id, extension) {
                return [forum_id, extension || {}];
            }
        },
        Thread: {
            factory: 'GameThreadPage',
            args: function (thread_id, extension) {
                return [thread_id, extension || {}];
            }
        },
        Locations: {
            factory: 'GameLocationsPage',
            args: function (id, extension) {
                return [id, extension || {}];
            }
        },
        Onlines: {
            factory: 'GameOnlinesPage',
            args: function (extension, mode) {
                return [extension || {}, (typeof mode === 'undefined') ? 'smart' : mode];
            }
        }
    };

    let base = {
        _instance: null,

        resolveFactory: function (factoryName) {
            let key = String(factoryName || '').trim();
            if (!key) {
                return null;
            }

            if (typeof globalWindow[key] === 'function') {
                return globalWindow[key];
            }

            return null;
        },

        callFactory: function (factoryName, args, scope) {
            let factory = this.resolveFactory(factoryName);
            if (typeof factory !== 'function') {
                return null;
            }

            try {
                return factory.apply(scope || globalWindow, Array.isArray(args) ? args : []);
            } catch (error) {
                return null;
            }
        },

        init: function () {
            let result = this.callFactory('GameAppInit', [], this);
            return (result !== null) ? result : this;
        },

        sync: function () {
            let result = this.callFactory('GameAppSync', [], this);
            if (result !== null) {
                return result;
            }
            this.init();
            emitAppEvent('app:sync');
        },

        reload: function () {
            let result = this.callFactory('GameAppReload', [], this);
            if (result !== null) {
                return result;
            }
            safeShowReloadToast();
            this.init();
            emitAppEvent('app:reload');
        }
    };

    Object.keys(pageFactories).forEach(function (methodName) {
        base[methodName] = buildPageMethod(pageFactories[methodName]);
    });

    Object.keys(pageFactoriesWithArgs).forEach(function (methodName) {
        let config = pageFactoriesWithArgs[methodName];
        base[methodName] = buildPageMethod(config.factory, config.args);
    });

    let o = Object.assign({}, base, extension);
    return o.init();
}

if (typeof window !== 'undefined' && typeof globalWindow.App !== 'function') {
    globalWindow.App = App;
}
export { App as App };
export default App;

