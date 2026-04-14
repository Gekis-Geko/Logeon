const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function getApi(name) {
    return function () {
        if (globalWindow[name] && typeof globalWindow[name] === 'object') {
            return globalWindow[name];
        }
        return null;
    };
}

function getRuntimeBootstrap() {
    if (!globalWindow.RuntimeBootstrap || typeof globalWindow.RuntimeBootstrap !== 'object') {
        return null;
    }
    return globalWindow.RuntimeBootstrap;
}

export function getConfig() {
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

export function boot() {
    const runtime = getRuntimeBootstrap();
    if (!runtime || typeof runtime.boot !== 'function') {
        return null;
    }
    return runtime.boot(getConfig());
}

export function start() {
    const runtime = getRuntimeBootstrap();
    if (!runtime || typeof runtime.start !== 'function') {
        return;
    }
    runtime.start(getConfig());
}

export function stop() {
    const runtime = getRuntimeBootstrap();
    if (!runtime || typeof runtime.stop !== 'function') {
        return null;
    }
    return runtime.stop(getConfig());
}

export const GameRuntimeApi = { boot, start, stop, getConfig };

globalWindow.GameRuntime = globalWindow.GameRuntime || {};
globalWindow.GameRuntime.boot = boot;
globalWindow.GameRuntime.start = start;
globalWindow.GameRuntime.stop = stop;

export default GameRuntimeApi;
