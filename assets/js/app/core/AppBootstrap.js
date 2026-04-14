(function (window) {
    'use strict';

    function isPlainObject(value) {
        return Object.prototype.toString.call(value) === '[object Object]';
    }

    function asArray(value) {
        if (Array.isArray(value)) {
            return value.slice();
        }
        if (typeof value === 'undefined' || value === null || value === '') {
            return [];
        }
        return [value];
    }

    function uniqueStrings(values) {
        var seen = {};
        var out = [];
        for (var i = 0; i < values.length; i++) {
            var item = String(values[i] || '').trim();
            if (!item || seen[item]) {
                continue;
            }
            seen[item] = true;
            out.push(item);
        }
        return out;
    }

    function AppBootstrap(options) {
        this.options = isPlainObject(options) ? options : {};

        if (!window.AppCore || !window.AppCore.Context || typeof window.AppCore.Context.create !== 'function') {
            throw new Error('AppCore.Context.create is not available.');
        }

        if (typeof window.ModuleRegistry !== 'function') {
            throw new Error('ModuleRegistry is not available.');
        }

        var contextOptions = isPlainObject(this.options.context) ? this.options.context : {};
        this.context = window.AppCore.Context.create(contextOptions);
        this.registry = new window.ModuleRegistry(this.context);
        this.started = false;
        this.activeModules = [];
    }

    AppBootstrap.prototype.register = function (name, factory) {
        this.registry.register(name, factory);
        return this;
    };

    AppBootstrap.prototype.resolvePage = function () {
        var pageConfig = isPlainObject(this.options.page) ? this.options.page : {};
        var selector = String(pageConfig.selector || '[data-app-page]').trim();
        var attr = String(pageConfig.attribute || 'data-app-page').trim();
        var node = document.querySelector(selector);
        if (!node) {
            return '';
        }
        return String(node.getAttribute(attr) || '').trim().toLowerCase();
    };

    AppBootstrap.prototype.resolveModules = function () {
        var out = [];
        var pageConfig = isPlainObject(this.options.page) ? this.options.page : {};
        var staticModules = asArray(this.options.modules);
        if (staticModules.length) {
            out = out.concat(staticModules);
        }

        var page = this.resolvePage();
        if (page && isPlainObject(pageConfig.modules) && typeof pageConfig.modules[page] !== 'undefined') {
            out = out.concat(asArray(pageConfig.modules[page]));
        }

        return uniqueStrings(out);
    };

    AppBootstrap.prototype.getModuleOptions = function (moduleName) {
        var globalOptions = isPlainObject(this.options.moduleOptions) ? this.options.moduleOptions : {};
        var pageConfig = isPlainObject(this.options.page) ? this.options.page : {};
        var pageOptions = isPlainObject(pageConfig.moduleOptions) ? pageConfig.moduleOptions : {};

        var merged = {};
        if (isPlainObject(globalOptions[moduleName])) {
            merged = Object.assign(merged, globalOptions[moduleName]);
        }
        if (isPlainObject(pageOptions[moduleName])) {
            merged = Object.assign(merged, pageOptions[moduleName]);
        }
        return merged;
    };

    AppBootstrap.prototype.start = function () {
        if (this.started) {
            return this;
        }

        var modules = this.resolveModules();
        for (var i = 0; i < modules.length; i++) {
            var moduleName = modules[i];
            try {
                this.registry.mount(moduleName, this.getModuleOptions(moduleName));
            } catch (error) {
                console.error('[AppBootstrap] mount failed for ' + moduleName, error);
            }
        }

        this.activeModules = modules;
        this.started = true;
        return this;
    };

    AppBootstrap.prototype.stop = function () {
        for (var i = 0; i < this.activeModules.length; i++) {
            this.registry.unmount(this.activeModules[i]);
        }
        this.activeModules = [];
        this.started = false;
        return this;
    };

    AppBootstrap.create = function (options) {
        return new AppBootstrap(options || {});
    };

    window.AppCore = window.AppCore || {};
    window.AppCore.AppBootstrap = AppBootstrap;
    window.AppBootstrap = AppBootstrap;
})(window);
