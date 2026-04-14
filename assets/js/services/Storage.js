/**
 * Wrapper per sessionStorage (o localStorage) con supporto automatico al chunking.
 * I valori sono serializzati in JSON e suddivisi in blocchi da `chunk_size` byte
 * per aggirare il limite di 5MB per chiave dei Web Storage API.
 *
 * L'indice dei blocchi è mantenuto nella chiave `__Storage__index__`.
 * I chiamanti non devono conoscere il meccanismo di chunking: usano solo `get`/`set`/`unset`.
 *
 * Uso tipico:
 * ```js
 * var store = Storage();
 * store.set('user', { id: 1, name: 'Alice' });
 * var user = store.get('user'); // { id: 1, name: 'Alice' }
 * store.unset('user');
 * ```
 *
 * @returns {Object} Istanza Storage con metodi get/set/has/unset/clear/keys.
 */
function Storage() {
    var base = {
        engine: 'sessionStorage',
        index_name: '__Storage__index__',
        chunk_size: 500000,
        _storageEngine: null,
        _fallbackEngine: null,

        get: function (key) {
            var normalizedKey = this._normalizeKey(key);
            if (normalizedKey === null) {
                return null;
            }

            var index = this.getIndex();
            var chunks = index[normalizedKey];
            if (!Array.isArray(chunks) || chunks.length === 0) {
                return null;
            }

            var serialized = '';
            for (var i = 0; i < chunks.length; i++) {
                var part = this._engine().getItem(chunks[i]);
                if (part === null || typeof part === 'undefined') {
                    this.unset(normalizedKey);
                    return null;
                }
                serialized += part;
            }

            try {
                return JSON.parse(serialized);
            } catch (error) {
                this.unset(normalizedKey);
                return null;
            }
        },

        has: function (key) {
            return this.get(key) !== null;
        },

        set: function (key, value) {
            var normalizedKey = this._normalizeKey(key);
            if (normalizedKey === null) {
                return this;
            }

            var serialized = null;
            try {
                serialized = JSON.stringify(value);
            } catch (error) {
                return this;
            }

            if (typeof serialized === 'undefined') {
                serialized = 'null';
            }

            var index = this.getIndex();
            if (index[normalizedKey] != null) {
                this._removeChunks(index[normalizedKey]);
                delete index[normalizedKey];
            }

            var chunks = this._split(serialized, this.chunk_size);
            var blockKeys = [];
            for (var i = 0; i < chunks.length; i++) {
                var blockKey = this._chunkKey(normalizedKey, i);
                try {
                    this._engine().setItem(blockKey, chunks[i]);
                    blockKeys.push(blockKey);
                } catch (error) {
                    this._removeChunks(blockKeys);
                    delete index[normalizedKey];
                    this.updateIndex(index);
                    return this;
                }
            }

            index[normalizedKey] = blockKeys;
            this.updateIndex(index);

            return this;
        },

        unset: function (key) {
            var normalizedKey = this._normalizeKey(key);
            if (normalizedKey === null) {
                return this;
            }

            var index = this.getIndex();
            if (index[normalizedKey] == null) {
                return this;
            }

            this._removeChunks(index[normalizedKey]);
            delete index[normalizedKey];
            this.updateIndex(index);

            return this;
        },

        keys: function () {
            var index = this.getIndex();
            return Object.keys(index);
        },

        empty: function () {
            var index = this.getIndex();
            for (var key in index) {
                if (!Object.prototype.hasOwnProperty.call(index, key)) {
                    continue;
                }
                this._removeChunks(index[key]);
            }

            this.updateIndex({});
            return this;
        },

        updateIndex: function (value) {
            var next = this._sanitizeIndex(value);
            try {
                this._engine().setItem(this.index_name, JSON.stringify(next));
            } catch (error) {
                return false;
            }
            return true;
        },

        getIndex: function () {
            var raw = this._engine().getItem(this.index_name);
            if (raw == null || raw === '') {
                return {};
            }

            var parsed = null;
            try {
                parsed = JSON.parse(raw);
            } catch (error) {
                this._engine().removeItem(this.index_name);
                return {};
            }

            var normalized = this._sanitizeIndex(parsed);
            if (JSON.stringify(normalized) !== JSON.stringify(parsed)) {
                this.updateIndex(normalized);
            }

            return normalized;
        },

        _sanitizeIndex: function (value) {
            if (!value || typeof value !== 'object' || Array.isArray(value)) {
                return {};
            }

            var normalized = {};
            for (var key in value) {
                if (!Object.prototype.hasOwnProperty.call(value, key)) {
                    continue;
                }

                var normalizedKey = this._normalizeKey(key);
                if (normalizedKey === null) {
                    continue;
                }

                var chunks = value[key];
                if (!Array.isArray(chunks) || chunks.length === 0) {
                    continue;
                }

                var prefix = this._chunkKey(normalizedKey, '');
                var validChunks = [];
                for (var i = 0; i < chunks.length; i++) {
                    if (typeof chunks[i] !== 'string' || chunks[i] === '') {
                        continue;
                    }
                    if (chunks[i].indexOf(prefix) !== 0) {
                        continue;
                    }
                    if (validChunks.indexOf(chunks[i]) === -1) {
                        validChunks.push(chunks[i]);
                    }
                }

                if (validChunks.length > 0) {
                    normalized[normalizedKey] = validChunks;
                }
            }

            return normalized;
        },

        _removeChunks: function (chunks) {
            if (!Array.isArray(chunks)) {
                return;
            }

            for (var i = 0; i < chunks.length; i++) {
                if (typeof chunks[i] === 'string' && chunks[i] !== '') {
                    this._engine().removeItem(chunks[i]);
                }
            }
        },

        _split: function (value, chunkSize) {
            if (typeof value !== 'string' || value === '') {
                return ['null'];
            }

            var size = parseInt(chunkSize, 10);
            if (!size || size <= 0) {
                size = 500000;
            }

            var output = [];
            for (var i = 0; i < value.length; i += size) {
                output.push(value.substring(i, i + size));
            }

            return output.length ? output : ['null'];
        },

        _chunkKey: function (key, index) {
            return this.index_name + ':' + key + ':' + index;
        },

        _normalizeKey: function (key) {
            if (key == null) {
                return null;
            }

            var normalized = String(key).trim();
            if (normalized === '' || normalized === this.index_name) {
                return null;
            }

            return normalized;
        },

        _engine: function () {
            if (this._storageEngine) {
                return this._storageEngine;
            }

            var candidate = null;
            try {
                if (this.engine === 'localStorage' && typeof localStorage !== 'undefined') {
                    candidate = localStorage;
                } else if (typeof sessionStorage !== 'undefined') {
                    candidate = sessionStorage;
                }
            } catch (error) {
                candidate = null;
            }

            if (!candidate) {
                this._storageEngine = this._memory();
                return this._storageEngine;
            }

            try {
                var probeKey = this.index_name + ':probe';
                candidate.setItem(probeKey, '1');
                candidate.removeItem(probeKey);
                this._storageEngine = candidate;
            } catch (error) {
                this._storageEngine = this._memory();
            }

            return this._storageEngine;
        },

        _memory: function () {
            if (this._fallbackEngine) {
                return this._fallbackEngine;
            }

            var memory = {};
            this._fallbackEngine = {
                getItem: function (key) {
                    if (!Object.prototype.hasOwnProperty.call(memory, key)) {
                        return null;
                    }
                    return memory[key];
                },
                setItem: function (key, value) {
                    memory[key] = String(value);
                },
                removeItem: function (key) {
                    delete memory[key];
                }
            };

            return this._fallbackEngine;
        }
    };

    return base;
}

if (typeof window !== 'undefined') {
    window.Storage = Storage;
}
