(function (window) {
    'use strict';

    function isPlainObject(value) {
        return Object.prototype.toString.call(value) === '[object Object]';
    }

    function isRuntimeDebugEnabled() {
        if (typeof window.__APP_RUNTIME_DEBUG === 'boolean') {
            return window.__APP_RUNTIME_DEBUG === true;
        }
        if (window.APP_CONFIG && parseInt(window.APP_CONFIG.runtime_debug, 10) === 1) {
            return true;
        }
        try {
            if (window.localStorage && window.localStorage.getItem('app.runtime.debug') === '1') {
                return true;
            }
        } catch (error) {}
        return false;
    }

    function createNotifier() {
        function show(body, type) {
            if (typeof window.Toast !== 'undefined' && window.Toast && typeof window.Toast.show === 'function') {
                window.Toast.show({
                    body: body,
                    type: type
                });
                return;
            }
            if (type === 'error') {
                console.error(body);
                return;
            }
            console.log(body);
        }

        return {
            info: function (body) {
                show(body, 'info');
            },
            success: function (body) {
                show(body, 'success');
            },
            warning: function (body) {
                show(body, 'warning');
            },
            error: function (body) {
                show(body, 'error');
            }
        };
    }

    function requestUnavailableMessage() {
        if (window.Request && typeof window.Request.getUnavailableMessage === 'function') {
            return window.Request.getUnavailableMessage();
        }
        return 'Servizio comunicazione non disponibile. Ricarica la pagina e riprova.';
    }

    function createHttpService() {
        return {
            request: function (config) {
                config = isPlainObject(config) ? config : {};

                var url = String(config.url || '');
                var action = String(config.action || '');
                var payload = isPlainObject(config.payload) ? config.payload : {};
                var method = String(config.method || config.type || 'POST').trim().toUpperCase();

                return new Promise(function (resolve, reject) {
                    if (typeof window.Request !== 'function') {
                        reject(new Error(requestUnavailableMessage()));
                        return;
                    }

                    if (window.Request.http && typeof window.Request.http.request === 'function') {
                        window.Request.http.request({
                            url: url,
                            method: method,
                            data: payload
                        }).then(resolve).catch(reject);
                        return;
                    }

                    reject(new Error(requestUnavailableMessage()));
                });
            },

            post: function (url, payload, config) {
                var cfg = isPlainObject(config) ? Object.assign({}, config) : {};
                cfg.url = url;
                cfg.payload = isPlainObject(payload) ? payload : {};
                cfg.method = 'POST';
                return this.request(cfg);
            },

            get: function (url, payload, config) {
                var cfg = isPlainObject(config) ? Object.assign({}, config) : {};
                cfg.url = url;
                cfg.payload = isPlainObject(payload) ? payload : {};
                cfg.method = 'GET';
                return this.request(cfg);
            }
        };
    }

    function normalizeCommandToken(token) {
        token = String(token || '').trim().toLowerCase();
        if (token === '') {
            return '';
        }
        return token.charAt(0) === '/' ? token : ('/' + token);
    }

    function collectCommandTokensByKind(commands, kind) {
        var list = Array.isArray(commands) ? commands : [];
        var targetKind = String(kind || '').trim().toLowerCase();
        var out = [];

        for (var i = 0; i < list.length; i++) {
            var row = list[i];
            if (!row || typeof row !== 'object') {
                continue;
            }
            if (String(row.kind || '').trim().toLowerCase() !== targetKind) {
                continue;
            }

            var key = normalizeCommandToken(row.key);
            if (key && out.indexOf(key) < 0) {
                out.push(key);
            }

            if (Array.isArray(row.aliases)) {
                for (var j = 0; j < row.aliases.length; j++) {
                    var alias = normalizeCommandToken(row.aliases[j]);
                    if (alias && out.indexOf(alias) < 0) {
                        out.push(alias);
                    }
                }
            }
        }

        return out;
    }

    function buildCommandParserConfigFromAppConfig(baseConfig) {
        var config = isPlainObject(baseConfig) ? Object.assign({}, baseConfig) : {};
        var appConfig = (window.APP_CONFIG && typeof window.APP_CONFIG === 'object') ? window.APP_CONFIG : {};
        var commands = Array.isArray(appConfig.chat_commands) ? appConfig.chat_commands : null;

        if (!commands || commands.length === 0) {
            return config;
        }

        config.commands = commands;

        var whisperTokens = collectCommandTokensByKind(commands, 'whisper');
        if (whisperTokens.length > 0) {
            config.whisperCommandTokens = whisperTokens;
        }

        var diceTokens = collectCommandTokensByKind(commands, 'dice');
        if (diceTokens.length > 0) {
            config.diceCommandTokens = diceTokens;
        }

        return config;
    }

    function configureRuntimeDomainHelpers(eventBus) {
        var runtimeDebug = isRuntimeDebugEnabled();

        if (typeof window.CommandParser === 'function') {
            try {
                window.CommandParser(buildCommandParserConfigFromAppConfig({
                    emitEvents: runtimeDebug === true,
                    eventBus: eventBus || null
                }));
            } catch (error) {}
        }

        bindCommandParserDebugListeners(eventBus, runtimeDebug);
    }

    function bindCommandParserDebugListeners(eventBus, runtimeDebug) {
        if (runtimeDebug !== true) {
            return;
        }
        if (!eventBus || typeof eventBus.on !== 'function') {
            return;
        }
        if (window.__command_parser_debug_listeners_bound === true) {
            return;
        }

        var events = [
            'command:parsed',
            'command:suggested',
            'command:validated',
            'command:rejected'
        ];

        function logEvent(eventName, payload) {
            if (typeof console === 'undefined' || !console || typeof console.info !== 'function') {
                return;
            }
            console.info('[CommandParser]', eventName, payload);
        }

        var unsubs = [];
        for (var i = 0; i < events.length; i++) {
            (function (eventName) {
                var off = eventBus.on(eventName, function (payload) {
                    logEvent(eventName, payload);
                });
                if (typeof off === 'function') {
                    unsubs.push(off);
                }
            })(events[i]);
        }

        window.__command_parser_debug_listeners_bound = true;
        window.__command_parser_debug_listeners_off = function () {
            for (var j = 0; j < unsubs.length; j++) {
                try {
                    unsubs[j]();
                } catch (error) {}
            }
            unsubs = [];
            window.__command_parser_debug_listeners_bound = false;
        };
    }

    function createContext(options) {
        options = isPlainObject(options) ? options : {};

        var mode = String(options.mode || 'game').trim() || 'game';
        var rootSelector = String(options.rootSelector || '#app').trim() || '#app';
        var root = document.querySelector(rootSelector) || document;

        var eventBus = (typeof window.EventBus === 'function') ? window.EventBus() : null;
        var permissionGate = (typeof window.PermissionGate === 'function')
            ? window.PermissionGate({
                emitEvents: true,
                eventBus: eventBus
            })
            : null;
        var configStore = (typeof window.ConfigStore === 'function')
            ? window.ConfigStore({
                emit_events: true,
                event_bus: eventBus
            })
            : null;

        configureRuntimeDomainHelpers(eventBus);

        return {
            mode: mode,
            rootSelector: rootSelector,
            root: root,
            services: {
                http: createHttpService(),
                form: (typeof window.Form === 'function') ? window.Form() : null,
                storage: (typeof window.Storage === 'function') ? window.Storage() : null,
                date: (typeof window.Dates === 'function') ? window.Dates() : null,
                url: (typeof window.Urls === 'function') ? window.Urls() : null,
                auth: (typeof window.Auth === 'function') ? window.Auth : null,
                notify: createNotifier(),
                dialog: (typeof window.Dialog === 'function') ? window.Dialog : null
            },
            ui: {
                modal: window.Modal || null,
                tooltip: (typeof window.resolveTooltipService === 'function')
                    ? window.resolveTooltipService
                    : (window.Tooltip || null),
                search: window.Search || null,
                dashboard: window.Dashboard || null,
                datagrid: window.Datagrid || null,
                paginator: window.Paginator || null
            },
            security: {
                policy: String(options.policy || 'observe'),
                permissionGate: permissionGate
            },
            events: eventBus,
            config: {
                app: window.APP_CONFIG || {},
                store: configStore
            }
        };
    }

    window.AppCore = window.AppCore || {};
    window.AppCore.Context = {
        create: createContext
    };
})(window);
