(function (window) {
    'use strict';

    var gameRestartInFlight = false;
    var gameRestartLastAt = 0;

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

    function runtimeDebug() {
        if (!isRuntimeDebugEnabled() || typeof console === 'undefined' || typeof console.info !== 'function') {
            return;
        }
        var args = Array.prototype.slice.call(arguments);
        args.unshift('[RuntimeBootstrap]');
        console.info.apply(console, args);
    }

    function isObject(value) {
        return value && typeof value === 'object' && !Array.isArray(value);
    }

    function resolveApi(candidate) {
        if (!candidate) {
            return null;
        }
        if (typeof candidate === 'function') {
            try {
                return candidate();
            } catch (error) {
                return null;
            }
        }
        return candidate;
    }

    function clonePageConfig(pageConfig) {
        var source = isObject(pageConfig) ? pageConfig : {};
        var out = Object.assign({}, source);
        if (isObject(source.modules)) {
            out.modules = Object.assign({}, source.modules);
        }
        return out;
    }

    function applyRegistryToPageConfig(pageConfig, registryApi) {
        if (!registryApi) {
            return pageConfig;
        }

        var config = clonePageConfig(pageConfig);
        if (typeof registryApi.getPageConfig === 'function') {
            var fromRegistry = registryApi.getPageConfig();
            if (isObject(fromRegistry)) {
                config = Object.assign(config, fromRegistry);
            }
            return config;
        }

        if (typeof registryApi.getPageModules === 'function') {
            config.modules = registryApi.getPageModules() || config.modules || {};
        }

        return config;
    }

    function runBinders(binders) {
        var list = Array.isArray(binders) ? binders : [];
        for (var i = 0; i < list.length; i++) {
            var entry = list[i];
            if (!entry) {
                continue;
            }
            var api = resolveApi(entry.api);
            var method = String(entry.method || 'bind').trim();
            if (api && method && typeof api[method] === 'function') {
                try {
                    runtimeDebug('binder:start', method);
                    api[method]();
                    runtimeDebug('binder:done', method);
                } catch (error) {
                    if (typeof console !== 'undefined' && typeof console.error === 'function') {
                        console.error('[RuntimeBootstrap] binder failed:', method, error);
                    }
                }
            }
        }
    }

    function runUnbinders(binders) {
        var list = Array.isArray(binders) ? binders : [];
        for (var i = 0; i < list.length; i++) {
            var entry = list[i];
            if (!entry) {
                continue;
            }
            var api = resolveApi(entry.api);
            var method = String(entry.unbindMethod || 'unbind').trim();
            if (api && method && typeof api[method] === 'function') {
                try {
                    runtimeDebug('unbinder:start', method);
                    api[method]();
                    runtimeDebug('unbinder:done', method);
                } catch (error) {
                    if (typeof console !== 'undefined' && typeof console.error === 'function') {
                        console.error('[RuntimeBootstrap] unbinder failed:', method, error);
                    }
                }
            }
        }
    }

    function getExistingRuntime(config) {
        var appGlobal = String((config && config.appGlobal) || '').trim();
        if (appGlobal && typeof window[appGlobal] !== 'undefined') {
            return window[appGlobal];
        }
        if (typeof window.AppRuntime !== 'undefined') {
            return window.AppRuntime;
        }
        return null;
    }

    function markGuard(flagName, value) {
        var key = String(flagName || '').trim();
        if (!key) {
            return;
        }
        window[key] = value;
    }

    function isGuardEnabled(flagName) {
        var key = String(flagName || '').trim();
        if (!key) {
            return false;
        }
        return window[key] === true;
    }

    function resolveRuntimeForModules() {
        if (window.GameApp) {
            return window.GameApp;
        }
        if (window.AppRuntime) {
            return window.AppRuntime;
        }
        if (window.AdminApp) {
            return window.AdminApp;
        }

        return null;
    }

    function resolveAppModule(moduleName) {
        var name = String(moduleName || '').trim();
        if (!name) {
            return null;
        }

        try {
            var runtime = resolveRuntimeForModules();
            if (!runtime || !runtime.registry) {
                return null;
            }

            var module = (typeof runtime.registry.get === 'function') ? runtime.registry.get(name) : null;
            if (module) {
                return module;
            }

            if (typeof runtime.registry.mount === 'function') {
                return runtime.registry.mount(name, {});
            }
        } catch (error) {
            return null;
        }

        return null;
    }

    function isGameBootstrapRuntime() {
        return window.APP_BOOTSTRAP_ENABLED === true && window.APP_BOOTSTRAP_RUNTIME === 'game';
    }

    function restartGameRuntime(options) {
        var opts = isObject(options) ? options : {};
        var minIntervalMs = parseInt(opts.minIntervalMs, 10);
        if (isNaN(minIntervalMs) || minIntervalMs < 250) {
            minIntervalMs = 750;
        }

        if (!isGameBootstrapRuntime()) {
            return false;
        }
        if (!window.GameRuntime) {
            return false;
        }

        var now = Date.now();
        if (gameRestartInFlight === true) {
            return false;
        }
        if (opts.force !== true && (now - gameRestartLastAt) < minIntervalMs) {
            return false;
        }

        gameRestartInFlight = true;
        try {
            if (opts.stop !== false && typeof window.GameRuntime.stop === 'function') {
                window.GameRuntime.stop();
            }
            if (typeof window.GameRuntime.start === 'function') {
                window.GameRuntime.start();
            } else {
                return false;
            }

            gameRestartLastAt = Date.now();
            return true;
        } catch (error) {
            return false;
        } finally {
            gameRestartInFlight = false;
        }
    }

    function boot(config) {
        var cfg = isObject(config) ? config : {};
        var guard = isObject(cfg.guard) ? cfg.guard : {};
        runtimeDebug('boot:requested', cfg.appGlobal || 'AppRuntime');

        if (isGuardEnabled(guard.bootKey)) {
            runtimeDebug('boot:skip-already-booted', guard.bootKey);
            return getExistingRuntime(cfg);
        }

        if (typeof window.AppBootstrap !== 'function') {
            runtimeDebug('boot:skip-missing-app-bootstrap');
            return null;
        }

        var pageApi = resolveApi(cfg.pageApi);
        if (pageApi && typeof pageApi.detectPageKey === 'function') {
            pageApi.detectPageKey();
        }

        var registryApi = resolveApi(cfg.registryApi);
        var pageConfig = applyRegistryToPageConfig(cfg.page, registryApi);

        var createOptions = {
            context: isObject(cfg.context) ? cfg.context : {},
            page: pageConfig
        };
        if (Array.isArray(cfg.modules)) {
            createOptions.modules = cfg.modules.slice();
        }
        if (isObject(cfg.moduleOptions)) {
            createOptions.moduleOptions = Object.assign({}, cfg.moduleOptions);
        }

        var app = window.AppBootstrap.create(createOptions);
        if (registryApi && typeof registryApi.registerModules === 'function') {
            registryApi.registerModules(app);
        }
        app.start();

        window.AppRuntime = app;
        if (cfg.appGlobal) {
            window[cfg.appGlobal] = app;
        }

        if (pageApi && typeof pageApi.bind === 'function') {
            pageApi.bind();
        }
        runBinders(cfg.binders);
        if (pageApi && typeof pageApi.initSharedWidgets === 'function') {
            pageApi.initSharedWidgets();
        }
        if (pageApi && typeof pageApi.initPageController === 'function') {
            pageApi.initPageController();
        }

        markGuard(guard.bootKey, true);
        runtimeDebug('boot:done', guard.bootKey || '(no-guard)');
        return app;
    }

    function start(config) {
        var cfg = isObject(config) ? config : {};
        var guard = isObject(cfg.guard) ? cfg.guard : {};
        runtimeDebug('start:requested', cfg.appGlobal || 'AppRuntime');

        if (isGuardEnabled(guard.startKey)) {
            runtimeDebug('start:skip-already-started', guard.startKey);
            return;
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () {
                boot(cfg);
            }, { once: true });
        } else {
            boot(cfg);
        }

        markGuard(guard.startKey, true);
        runtimeDebug('start:done', guard.startKey || '(no-guard)');
    }

    function stop(config) {
        var cfg = isObject(config) ? config : {};
        var guard = isObject(cfg.guard) ? cfg.guard : {};
        var pageApi = resolveApi(cfg.pageApi);
        var runtime = getExistingRuntime(cfg);
        runtimeDebug('stop:requested', cfg.appGlobal || 'AppRuntime');

        if (pageApi && typeof pageApi.unbind === 'function') {
            pageApi.unbind();
        }
        runUnbinders(cfg.binders);

        if (runtime && typeof runtime.stop === 'function') {
            runtime.stop();
        }

        if (cfg.appGlobal && typeof window[cfg.appGlobal] !== 'undefined') {
            try {
                delete window[cfg.appGlobal];
            } catch (error) {
                window[cfg.appGlobal] = undefined;
            }
        }
        if (window.AppRuntime === runtime) {
            window.AppRuntime = undefined;
        }

        markGuard(guard.bootKey, false);
        markGuard(guard.startKey, false);
        runtimeDebug('stop:done', guard.bootKey || '(no-guard)', guard.startKey || '(no-guard)');

        return runtime;
    }

    window.AppCore = window.AppCore || {};
    window.AppCore.RuntimeBootstrap = window.AppCore.RuntimeBootstrap || {};
    window.AppCore.RuntimeBootstrap.boot = boot;
    window.AppCore.RuntimeBootstrap.start = start;
    window.AppCore.RuntimeBootstrap.stop = stop;
    window.AppCore.RuntimeBootstrap.resolveAppModule = resolveAppModule;
    window.AppCore.RuntimeBootstrap.restartGameRuntime = restartGameRuntime;
    window.RuntimeBootstrap = window.AppCore.RuntimeBootstrap;
})(window);

