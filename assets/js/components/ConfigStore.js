/**
 * @typedef {{ op: 'add'|'set'|'remove', key: string, path: string, oldValue: *, newValue: * }} ConfigChange
 * Rappresenta una singola modifica emessa dall'evento `config:changed`.
 */

/**
 * @typedef {Object} ConfigStoreInstance
 * @property {boolean} emit_events             - Se true, emette `config:changed` via EventBus ad ogni modifica.
 * @property {Object|null} event_bus           - EventBus esplicito da usare; se null usa `window.AppEvents`.
 * @property {string} event_name_changed       - Nome dell'evento emesso (default: `'config:changed'`).
 * @property {function(): Object} getRoot      - Restituisce `window.APP_CONFIG` (crea l'oggetto se assente).
 * @property {function(string, *=): *} get     - Legge una chiave radice; restituisce `fallback` se assente/vuota.
 * @property {function(string, *=): number} getInt - Come `get` ma converte in intero.
 * @property {function(string, boolean=): boolean} getBool - Come `get` ma converte in boolean (1 = true).
 * @property {function(string): boolean} has   - True se la chiave esiste in radice.
 * @property {function(string, *): *} set      - Imposta una chiave radice; emette evento se il valore cambia.
 * @property {function(string): boolean} remove - Rimuove una chiave radice.
 * @property {function(Object): Object} merge  - Merge shallow su radice; emette un evento per ogni chiave modificata.
 * @property {function(Object): Object} replace - Sostituisce l'intero contenuto radice.
 * @property {function(): Object} clear        - Svuota tutte le chiavi radice.
 * @property {function(): string[]} keys       - Restituisce le chiavi radice.
 * @property {function(string|string[], *=): *} getPath    - Legge un valore annidato (dot-path o array di token).
 * @property {function(string|string[]): boolean} hasPath  - True se il percorso annidato esiste.
 * @property {function(string|string[], *): *} setPath     - Imposta un valore annidato (crea i nodi mancanti).
 * @property {function(string|string[]): boolean} removePath - Rimuove un valore annidato.
 */

/**
 * Factory che restituisce il singleton ConfigStore globale.
 * Legge e scrive su `window.APP_CONFIG`; può emettere eventi `config:changed` via EventBus.
 *
 * Pattern singleton identico a EventBus: la prima chiamata crea l'istanza
 * in `window.__config_store_instance`; le successive la restituiscono invariata.
 *
 * @param {Object} [extension] - Proprietà da mixare nell'istanza.
 * @returns {ConfigStoreInstance}
 */
function ConfigStore(extension) {
    function normalizePath(path) {
        if (Array.isArray(path)) {
            return path.filter(function (part) {
                return part !== null && part !== undefined && String(part).trim() !== '';
            }).map(function (part) {
                return String(part).trim();
            });
        }

        let value = String(path || '').trim();
        if (!value) {
            return [];
        }

        return value.split('.').map(function (part) {
            return String(part || '').trim();
        }).filter(function (part) {
            return part !== '';
        });
    }

    function getContainerForPath(root, tokens, createMissing) {
        let ref = root;
        if (!ref || typeof ref !== 'object') {
            return null;
        }

        for (let i = 0; i < tokens.length - 1; i++) {
            let key = tokens[i];
            if (!Object.prototype.hasOwnProperty.call(ref, key) || ref[key] == null || typeof ref[key] !== 'object') {
                if (createMissing !== true) {
                    return null;
                }
                ref[key] = {};
            }
            ref = ref[key];
        }

        return ref;
    }

    function hasOwnPath(root, tokens) {
        let ref = root;
        if (!ref || typeof ref !== 'object') {
            return false;
        }
        for (let i = 0; i < tokens.length; i++) {
            if (ref == null || typeof ref !== 'object' || !Object.prototype.hasOwnProperty.call(ref, tokens[i])) {
                return false;
            }
            ref = ref[tokens[i]];
        }
        return true;
    }

    function getPathValueRaw(root, tokens) {
        let ref = root;
        if (!ref || typeof ref !== 'object') {
            return undefined;
        }
        for (let i = 0; i < tokens.length; i++) {
            if (ref == null || typeof ref !== 'object' || !Object.prototype.hasOwnProperty.call(ref, tokens[i])) {
                return undefined;
            }
            ref = ref[tokens[i]];
        }
        return ref;
    }

    let base = {
        emit_events: false,
        event_bus: null,
        event_name_changed: 'config:changed',

        getRoot: function () {
            if (typeof window === 'undefined') {
                return {};
            }
            if (!window.APP_CONFIG || typeof window.APP_CONFIG !== 'object') {
                window.APP_CONFIG = {};
            }
            return window.APP_CONFIG;
        },

        get: function (key, fallback) {
            let root = this.getRoot();
            if (root[key] === undefined || root[key] === null || root[key] === '') {
                return fallback;
            }
            return root[key];
        },

        resolveEventBus: function () {
            if (this.event_bus && typeof this.event_bus.emit === 'function') {
                return this.event_bus;
            }
            if (typeof window !== 'undefined' && window.AppEvents && typeof window.AppEvents.emit === 'function') {
                return window.AppEvents;
            }
            return null;
        },

        shouldEmitEvents: function () {
            return this.emit_events === true && !!this.resolveEventBus();
        },

        emitConfigChanged: function (changes, meta) {
            if (!this.shouldEmitEvents()) {
                return 0;
            }

            let list = Array.isArray(changes) ? changes.filter(function (change) {
                return !!change && typeof change === 'object';
            }) : [];
            if (list.length === 0) {
                return 0;
            }

            let payload = Object.assign({
                changes: list,
                root: this.getRoot()
            }, (meta && typeof meta === 'object') ? meta : {});

            if (payload.change == null && list.length === 1) {
                payload.change = list[0];
            }

            let bus = this.resolveEventBus();
            return bus ? bus.emit(this.event_name_changed, payload) : 0;
        },

        has: function (key) {
            let root = this.getRoot();
            return Object.prototype.hasOwnProperty.call(root, key);
        },

        getInt: function (key, fallback) {
            let value = parseInt(this.get(key, fallback), 10);
            if (isNaN(value)) {
                return fallback;
            }
            return value;
        },

        getBool: function (key, fallback) {
            let value = this.get(key, fallback ? 1 : 0);
            return parseInt(value, 10) === 1;
        },

        set: function (key, value) {
            let root = this.getRoot();
            let exists = Object.prototype.hasOwnProperty.call(root, key);
            let previous = exists ? root[key] : undefined;
            root[key] = value;
            if (!exists || previous !== value) {
                this.emitConfigChanged([{
                    op: exists ? 'set' : 'add',
                    key: key,
                    path: String(key),
                    oldValue: previous,
                    newValue: value
                }], { operation: exists ? 'set' : 'add', key: key, path: String(key) });
            }
            return value;
        },

        remove: function (key) {
            let root = this.getRoot();
            if (Object.prototype.hasOwnProperty.call(root, key)) {
                let previous = root[key];
                delete root[key];
                this.emitConfigChanged([{
                    op: 'remove',
                    key: key,
                    path: String(key),
                    oldValue: previous,
                    newValue: undefined
                }], { operation: 'remove', key: key, path: String(key) });
                return true;
            }
            return false;
        },

        merge: function (data) {
            if (!data || typeof data !== 'object') {
                return this.getRoot();
            }
            let root = this.getRoot();
            let changes = [];
            for (let key in data) {
                if (Object.prototype.hasOwnProperty.call(data, key)) {
                    let exists = Object.prototype.hasOwnProperty.call(root, key);
                    let previous = exists ? root[key] : undefined;
                    root[key] = data[key];
                    if (!exists || previous !== data[key]) {
                        changes.push({
                            op: exists ? 'set' : 'add',
                            key: key,
                            path: String(key),
                            oldValue: previous,
                            newValue: data[key]
                        });
                    }
                }
            }
            this.emitConfigChanged(changes, { operation: 'merge' });
            return root;
        },

        replace: function (data) {
            let root = this.getRoot();
            let next = (data && typeof data === 'object') ? data : {};
            let changes = [];
            let keys = Object.keys(root);
            for (let i = 0; i < keys.length; i++) {
                changes.push({
                    op: 'remove',
                    key: keys[i],
                    path: String(keys[i]),
                    oldValue: root[keys[i]],
                    newValue: undefined
                });
                delete root[keys[i]];
            }
            let nextKeys = Object.keys(next);
            for (let n = 0; n < nextKeys.length; n++) {
                let nextKey = nextKeys[n];
                root[nextKey] = next[nextKey];
                changes.push({
                    op: 'add',
                    key: nextKey,
                    path: String(nextKey),
                    oldValue: undefined,
                    newValue: next[nextKey]
                });
            }
            this.emitConfigChanged(changes, { operation: 'replace' });
            return root;
        },

        clear: function () {
            let root = this.getRoot();
            let keys = Object.keys(root);
            if (keys.length === 0) {
                return root;
            }

            let changes = [];
            for (let i = 0; i < keys.length; i++) {
                let key = keys[i];
                changes.push({
                    op: 'remove',
                    key: key,
                    path: String(key),
                    oldValue: root[key],
                    newValue: undefined
                });
                delete root[key];
            }

            this.emitConfigChanged(changes, { operation: 'clear' });
            return root;
        },

        keys: function () {
            return Object.keys(this.getRoot());
        },

        getPath: function (path, fallback) {
            let tokens = normalizePath(path);
            if (tokens.length === 0) {
                return fallback;
            }

            let ref = this.getRoot();
            ref = getPathValueRaw(ref, tokens);

            if (ref === undefined || ref === null || ref === '') {
                return fallback;
            }
            return ref;
        },

        hasPath: function (path) {
            let tokens = normalizePath(path);
            if (tokens.length === 0) {
                return false;
            }
            return hasOwnPath(this.getRoot(), tokens);
        },

        setPath: function (path, value) {
            let tokens = normalizePath(path);
            if (tokens.length === 0) {
                return value;
            }

            let root = this.getRoot();
            let container = getContainerForPath(root, tokens, true);
            if (!container) {
                return value;
            }
            let lastKey = tokens[tokens.length - 1];
            let exists = Object.prototype.hasOwnProperty.call(container, lastKey);
            let previous = exists ? container[lastKey] : undefined;
            container[lastKey] = value;
            if (!exists || previous !== value) {
                let pathString = tokens.join('.');
                this.emitConfigChanged([{
                    op: exists ? 'set' : 'add',
                    key: lastKey,
                    path: pathString,
                    oldValue: previous,
                    newValue: value
                }], { operation: exists ? 'setPath' : 'addPath', key: lastKey, path: pathString });
            }
            return value;
        },

        removePath: function (path) {
            let tokens = normalizePath(path);
            if (tokens.length === 0) {
                return false;
            }

            let root = this.getRoot();
            let container = getContainerForPath(root, tokens, false);
            if (!container) {
                return false;
            }

            let last = tokens[tokens.length - 1];
            if (!Object.prototype.hasOwnProperty.call(container, last)) {
                return false;
            }
            let previous = container[last];
            delete container[last];
            let pathString = tokens.join('.');
            this.emitConfigChanged([{
                op: 'remove',
                key: last,
                path: pathString,
                oldValue: previous,
                newValue: undefined
            }], { operation: 'removePath', key: last, path: pathString });
            return true;
        }
    };

    if (typeof window !== 'undefined') {
        if (!window.__config_store_instance) {
            window.__config_store_instance = Object.assign({}, base);
        }
        if (extension && typeof extension === 'object') {
            Object.assign(window.__config_store_instance, extension);
        }
        return window.__config_store_instance;
    }

    return Object.assign({}, base, extension);
}

if (typeof window !== 'undefined') {
    window.ConfigStore = ConfigStore;
}
