const globalWindow = (typeof window !== 'undefined') ? window : globalThis;
const BRIDGE_KEY = 'LogeonLegacyBridge';

function createBridge() {
    return {
        version: 'phase-2',
        status: 'temporary',
        note: 'Temporary bridge for legacy window APIs. TODO: remove by phase-4.',
        has: function (name) {
            const key = String(name || '').trim();
            if (!key) {
                return false;
            }
            return Object.prototype.hasOwnProperty.call(globalWindow, key);
        },
        get: function (name) {
            const key = String(name || '').trim();
            if (!key) {
                return undefined;
            }
            return globalWindow[key];
        },
        keys: function () {
            return Object.keys(globalWindow).sort();
        }
    };
}

if (!globalWindow[BRIDGE_KEY]) {
    Object.defineProperty(globalWindow, BRIDGE_KEY, {
        value: createBridge(),
        writable: false,
        configurable: true,
        enumerable: false
    });
}

export default globalWindow[BRIDGE_KEY];
