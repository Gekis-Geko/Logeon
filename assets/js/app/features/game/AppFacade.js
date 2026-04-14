(function (window) {
    'use strict';

    if (typeof window.App !== 'function') {
        return;
    }

    var appFactory = window.App;

    function getRuntime() {
        if (window.AdminApp) {
            return window.AdminApp;
        }
        if (window.GameApp) {
            return window.GameApp;
        }
        if (window.AppRuntime) {
            return window.AppRuntime;
        }
        return null;
    }

    function getRegistry(runtime) {
        if (!runtime || !runtime.registry) {
            return null;
        }
        return runtime.registry;
    }

    function createModuleShortcut(moduleKey) {
        return function (options) {
            return this.mount(moduleKey, options || {});
        };
    }

    function attachMissingMethods(target, source, methodNames) {
        methodNames.forEach(function (methodName) {
            if (typeof target[methodName] !== 'function' && typeof source[methodName] === 'function') {
                target[methodName] = source[methodName].bind(source);
            }
        });
    }

    function createFacade(extension) {
        var runtime = getRuntime();
        var facade = {
            runtime: runtime,

            get: function (name) {
                var registry = getRegistry(this.runtime);
                if (!registry || typeof registry.get !== 'function') {
                    return null;
                }
                return registry.get(name);
            },

            mount: function (name, options) {
                var registry = getRegistry(this.runtime);
                if (!registry || typeof registry.mount !== 'function') {
                    return null;
                }
                return registry.mount(name, options || {});
            },

            call: function (name, method, payload) {
                var module = this.get(name) || this.mount(name, {});
                if (!module || typeof module[method] !== 'function') {
                    return Promise.reject(new Error('Module method not found: ' + name + '.' + method));
                }
                return module[method](payload);
            }
        };

        var shortcuts = {
            Dashboard: 'admin.dashboard',
            Users: 'admin.users'
        };

        Object.keys(shortcuts).forEach(function (methodName) {
            facade[methodName] = createModuleShortcut(shortcuts[methodName]);
        });

        if (extension && typeof extension === 'object') {
            facade = Object.assign(facade, extension);
        }

        return facade;
    }

    window.App = function (extension) {
        if (window.APP_BOOTSTRAP_ENABLED !== true) {
            return appFactory(extension);
        }

        var appInstance = appFactory(extension);
        if (!appInstance || typeof appInstance !== 'object') {
            return createFacade(extension);
        }

        var facade = createFacade(extension);
        attachMissingMethods(appInstance, facade, ['get', 'mount', 'call', 'Dashboard', 'Users']);

        appInstance.runtime = facade.runtime;
        return appInstance;
    };

    window.__app_facade_loaded = true;
})(window);
