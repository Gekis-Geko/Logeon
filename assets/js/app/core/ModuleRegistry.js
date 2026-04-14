(function (window) {
    'use strict';

    function ModuleRegistry(context) {
        this.context = context || {};
        this.factories = {};
        this.instances = {};
    }

    ModuleRegistry.prototype.register = function (name, factory) {
        var key = String(name || '').trim();
        if (!key) {
            throw new Error('Module name is required.');
        }
        if (typeof factory !== 'function' && (typeof factory !== 'object' || factory === null)) {
            throw new Error('Module factory must be a function or an object.');
        }

        this.factories[key] = factory;
        return this;
    };

    ModuleRegistry.prototype.getFactory = function (name) {
        var key = String(name || '').trim();
        return this.factories[key] || null;
    };

    ModuleRegistry.prototype.get = function (name) {
        var key = String(name || '').trim();
        if (!this.instances[key]) {
            return null;
        }
        return this.instances[key].instance || null;
    };

    ModuleRegistry.prototype.has = function (name) {
        var key = String(name || '').trim();
        return !!this.factories[key];
    };

    ModuleRegistry.prototype.isMounted = function (name) {
        var key = String(name || '').trim();
        return !!this.instances[key];
    };

    ModuleRegistry.prototype.mount = function (name, options) {
        var key = String(name || '').trim();
        if (!key) {
            throw new Error('Module name is required.');
        }

        var force = !!(options && options.__forceRemount);
        if (this.instances[key] && !force) {
            return this.instances[key].instance;
        }

        if (force && this.instances[key]) {
            this.unmount(key);
        }

        var factory = this.factories[key];
        if (!factory) {
            throw new Error('Module not registered: ' + key);
        }

        var module = (typeof factory === 'function') ? factory(this.context, options || {}) : factory;
        if (!module || typeof module !== 'object') {
            module = {};
        }

        var mountResult = null;
        if (typeof module.mount === 'function') {
            mountResult = module.mount(this.context, options || {});
        }

        var instance = (mountResult && typeof mountResult === 'object') ? mountResult : module;

        this.instances[key] = {
            module: module,
            instance: instance
        };

        return instance;
    };

    ModuleRegistry.prototype.unmount = function (name) {
        var key = String(name || '').trim();
        var record = this.instances[key];
        if (!record) {
            return this;
        }

        if (record.instance && typeof record.instance.unmount === 'function') {
            record.instance.unmount();
        } else if (record.module && typeof record.module.unmount === 'function') {
            record.module.unmount();
        }

        delete this.instances[key];
        return this;
    };

    ModuleRegistry.prototype.unmountAll = function () {
        var names = Object.keys(this.instances);
        for (var i = 0; i < names.length; i++) {
            this.unmount(names[i]);
        }
        return this;
    };

    window.ModuleRegistry = ModuleRegistry;
})(window);
