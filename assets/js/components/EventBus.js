/**
 * @typedef {Object} EventBusInstance
 * @property {{ [eventName: string]: Function[] }} events - Mappa degli handler attivi per evento.
 * @property {function(string, function): function} on        - Registra un handler; restituisce la funzione di cleanup.
 * @property {function(string, function): function} once      - Registra un handler one-shot; restituisce la funzione di cleanup.
 * @property {function(string, function=): EventBusInstance} off - Rimuove un handler o tutti gli handler dell'evento.
 * @property {function(string, *=): number} emit              - Emette un evento; restituisce il numero di handler invocati.
 * @property {function(string=): EventBusInstance} clear      - Rimuove tutti gli handler (o quelli di un evento specifico).
 * @property {function(string): boolean} hasListeners         - True se l'evento ha almeno un listener.
 * @property {function(string): number} listenerCount         - Numero di handler attivi per l'evento.
 * @property {function(): string[]} names                     - Nomi degli eventi con listener attivi.
 * @property {function(string, function): function} subscribe   - Alias di `on`.
 * @property {function(string, function): EventBusInstance} unsubscribe - Alias di `off`.
 * @property {function(string, *=): number} publish           - Alias di `emit`.
 * @property {function(): EventBusInstance} destroy           - Alias di `clear` (rimuove tutti).
 */

/**
 * Factory che restituisce il singleton EventBus globale condiviso tra tutti i moduli.
 * Fuori dal contesto browser restituisce un'istanza isolata (utile per test).
 *
 * Pattern singleton: la prima chiamata crea l'istanza in `window.__event_bus_instance`;
 * le chiamate successive restituiscono la stessa istanza. Il parametro `extension`
 * permette di mixare proprietà aggiuntive (override di metodi o stato extra).
 *
 * @param {Object} [extension] - Proprietà da mixare nell'istanza dopo la creazione.
 * @returns {EventBusInstance}
 */
function EventBus(extension) {
    function normalizeEventName(eventName) {
        return String(eventName || '').trim();
    }

    function ensureHandlersBucket(bus, eventName) {
        if (!bus.events[eventName]) {
            bus.events[eventName] = [];
        }
        return bus.events[eventName];
    }

    function safeHandlerErrorLog(eventName, error) {
        if (typeof console !== 'undefined' && console && typeof console.error === 'function') {
            console.error('EventBus handler error for event:', eventName, error);
        }
    }

    let base = {
        events: {},

        on: function (eventName, handler) {
            eventName = normalizeEventName(eventName);
            if (!eventName || typeof handler !== 'function') {
                return function () {};
            }
            ensureHandlersBucket(this, eventName).push(handler);

            var self = this;
            return function () {
                self.off(eventName, handler);
            };
        },

        once: function (eventName, handler) {
            eventName = normalizeEventName(eventName);
            if (!eventName || typeof handler !== 'function') {
                return function () {};
            }
            var off = null;
            var wrapper = function (payload) {
                if (off) {
                    off();
                }
                handler(payload);
            };
            off = this.on(eventName, wrapper);
            return off;
        },

        off: function (eventName, handler) {
            eventName = normalizeEventName(eventName);
            if (!eventName || !this.events[eventName]) {
                return this;
            }
            if (typeof handler !== 'function') {
                delete this.events[eventName];
                return this;
            }
            this.events[eventName] = this.events[eventName].filter(function (cb) {
                return cb !== handler;
            });
            if (this.events[eventName].length === 0) {
                delete this.events[eventName];
            }
            return this;
        },

        emit: function (eventName, payload) {
            eventName = normalizeEventName(eventName);
            if (!eventName || !this.events[eventName]) {
                return 0;
            }
            var queue = this.events[eventName].slice();
            for (var i = 0; i < queue.length; i++) {
                try {
                    queue[i](payload);
                } catch (e) {
                    safeHandlerErrorLog(eventName, e);
                }
            }
            return queue.length;
        },

        clear: function (eventName) {
            eventName = normalizeEventName(eventName);
            if (!eventName) {
                this.events = {};
                return this;
            }
            delete this.events[eventName];
            return this;
        },

        hasListeners: function (eventName) {
            return this.listenerCount(eventName) > 0;
        },

        listenerCount: function (eventName) {
            eventName = normalizeEventName(eventName);
            if (!eventName || !this.events[eventName]) {
                return 0;
            }
            return this.events[eventName].length;
        },

        names: function () {
            return Object.keys(this.events);
        },

        subscribe: function (eventName, handler) {
            return this.on(eventName, handler);
        },

        unsubscribe: function (eventName, handler) {
            return this.off(eventName, handler);
        },

        publish: function (eventName, payload) {
            return this.emit(eventName, payload);
        },

        destroy: function () {
            return this.clear();
        }
    };

    if (typeof window !== 'undefined') {
        if (!window.__event_bus_instance) {
            window.__event_bus_instance = Object.assign({}, base);
        }
        if (extension && typeof extension === 'object') {
            Object.assign(window.__event_bus_instance, extension);
        }
        return window.__event_bus_instance;
    }

    return Object.assign({}, base, extension);
}

if (typeof window !== 'undefined') {
    window.EventBus = EventBus;
}
