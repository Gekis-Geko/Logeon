(function (window) {
    'use strict';

    function getApi(name) {
        return function () {
            if (window[name] && typeof window[name] === 'object') {
                return window[name];
            }
            return null;
        };
    }

    function getConfig() {
        return {
            appGlobal: 'GameApp',
            guard: {
                startKey: '__gameRuntimeStartBound',
                bootKey: '__gameRuntimeBooted'
            },
            context: {
                mode: 'game',
                rootSelector: '#page-content',
                policy: 'observe'
            },
            page: {
                selector: '#page-content',
                attribute: 'data-app-page',
                modules: {}
            },
            pageApi: getApi('GamePage'),
            registryApi: getApi('GameRegistry'),
            binders: [
                { api: getApi('GameModals'), method: 'bind' },
                { api: getApi('GameGlobals'), method: 'bind' },
                { api: getApi('GameUi'), method: 'bind' }
            ]
        };
    }

    function boot() {
        if (!window.RuntimeBootstrap || typeof window.RuntimeBootstrap.boot !== 'function') {
            return null;
        }
        return window.RuntimeBootstrap.boot(getConfig());
    }

    function start() {
        if (!window.RuntimeBootstrap || typeof window.RuntimeBootstrap.start !== 'function') {
            return;
        }
        window.RuntimeBootstrap.start(getConfig());
    }

    function stop() {
        if (!window.RuntimeBootstrap || typeof window.RuntimeBootstrap.stop !== 'function') {
            return null;
        }
        return window.RuntimeBootstrap.stop(getConfig());
    }

    window.GameRuntime = window.GameRuntime || {};
    window.GameRuntime.boot = boot;
    window.GameRuntime.start = start;
    window.GameRuntime.stop = stop;
})(window);
