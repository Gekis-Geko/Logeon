/* Compatibility facade.
 * Core implementation lives in:
 * - /assets/js/app/features/game/AppCore.js
 * Runtime bridge lives in:
 * - /assets/js/app/features/game/AppFacade.js
 */
(function (window) {
    'use strict';

    if (typeof window === 'undefined' || typeof window.App === 'function') {
        return;
    }

    function resolveRuntime() {
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

    window.App = function () {
        var runtime = resolveRuntime();
        return {
            runtime: runtime,
            get: function (name) {
                if (!runtime || !runtime.registry || typeof runtime.registry.get !== 'function') {
                    return null;
                }
                return runtime.registry.get(name);
            },
            mount: function (name, options) {
                if (!runtime || !runtime.registry || typeof runtime.registry.mount !== 'function') {
                    return null;
                }
                return runtime.registry.mount(name, options || {});
            },
            call: function (name, method, payload) {
                var module = this.get(name) || this.mount(name, {});
                if (!module || typeof module[method] !== 'function') {
                    return Promise.reject(new Error('Module method not found: ' + name + '.' + method));
                }
                return module[method](payload);
            }
        };
    };
})(window);
