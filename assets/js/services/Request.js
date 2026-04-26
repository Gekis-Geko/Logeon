/**
 * @typedef {Object} ServerResponse
 * Shape del payload JSON atteso dal server Logeon per tutte le chiamate API.
 * @property {string} [error]        - Messaggio di errore (se presente, la chiamata è fallita).
 * @property {string} [error_code]   - Codice errore machine-readable (es. 'not_found', 'validation_error').
 * @property {string} [errorCode]    - Alias camelCase di error_code (accettato per compatibilità).
 * @property {string} [message]      - Messaggio informativo (usato anche per errori soft quando `success === false`).
 * @property {boolean} [success]     - false = errore soft con messaggio; true o assente = successo.
 * @property {*} [data]              - Payload dati della risposta (shape variabile per endpoint).
 * @property {Object} [dataset]      - Alias di data (vecchi endpoint).
 */

/**
 * Esegue una chiamata AJAX POST verso un endpoint Logeon.
 * Gestisce automaticamente: CSRF token, encoding payload, parsing JSON,
 * dispatch al callback di successo/errore, e visualizzazione dialog di errore.
 *
 * Il `callbackName` viene usato per risolvere i metodi `on{CallbackName}Success`
 * e `on{CallbackName}Error` nell'`extension` (pattern observer su oggetto).
 *
 * Uso tipico:
 * ```js
 * Request('/api/users', 'loadUsers', { page: 1 }, {
 *     onLoadUsersSuccess: function(payload) { ... },
 *     onLoadUsersError: function(message, info) { ... },
 * });
 * ```
 *
 * @param {string} url              - URL endpoint (POST).
 * @param {string} callbackName     - Nome base del callback (risolve onNameSuccess / onNameError).
 * @param {Object|Array|*} [data]   - Dati da inviare nel body (serializzati come JSON in `data=...`).
 * @param {Object} [extension]      - Mixin: override di metodi o aggiunta di handler `on{Name}Success/Error`.
 * @returns {Object} Istanza della chiamata (già inizializzata e inviata).
 */
function Request(url, callbackName, data, extension) {
    var base = {
        url: null,
        data: {},
        callbackName: null,
        request: null,
        dataset: {},

        init: function () {
            if (typeof url === 'string' && url.trim() !== '') {
                this.url = url.trim();
            } else {
                this._showErrorDialog('Ajax: url mancante', 'URL non valida per la chiamata Ajax.');
                return this;
            }

            if (typeof data !== 'undefined' && data !== null) {
                this.data = data;
            }

            if (typeof callbackName === 'string' && callbackName.trim() !== '') {
                this.callbackName = callbackName.trim();
            } else {
                this._showErrorDialog('Ajax: callback mancante', 'Nome callback non valido per la chiamata Ajax.');
                return this;
            }

            if (typeof window.$ === 'undefined' || typeof window.$.ajax !== 'function') {
                this._showErrorDialog('Ajax non disponibile', 'jQuery.ajax non e disponibile.');
                return this;
            }

            this.setRequest();
            this.call();

            return this;
        },

        setRequest: function () {
            var self = this;

            this.request = {
                url: self.url,
                type: 'POST',
                method: 'POST',
                data: self._encodePostData(),
                context: self,
                headers: {
                    'X-CSRF-Token': self._getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest'
                },
                success: self._callbackSuccess,
                error: self._callbackError
            };
        },

        call: function () {
            if (!this.request || typeof window.$ === 'undefined' || typeof window.$.ajax !== 'function') {
                return;
            }
            window.$.ajax(this.request);
        },

        _callbackSuccess: function (response) {
            var callbacks = this._getCallbackNames();

            if (response === null || typeof response === 'undefined' || response === '') {
                if (typeof this[callbacks.success] === 'function') {
                    this[callbacks.success](response);
                }
                this.dataset = response;
                return response;
            }

            var parsed = this._parseResponse(response);
            if (parsed.parseError) {
                this._callbackErrorMessage(parsed.parseError);
                return null;
            }

            var payload = parsed.payload;
            if (payload && typeof payload === 'object') {
                if (payload.error != null && payload.error !== '') {
                    this._callbackErrorMessage({
                        message: payload.error,
                        errorCode: this._extractErrorCode(payload),
                        payload: payload
                    });
                    return null;
                }
                if (payload.success === false && typeof payload.message === 'string' && payload.message.trim() !== '') {
                    this._callbackErrorMessage({
                        message: payload.message,
                        errorCode: this._extractErrorCode(payload),
                        payload: payload
                    });
                    return null;
                }
            }

            if (typeof this[callbacks.success] === 'function') {
                this[callbacks.success](payload);
            }

            this.dataset = payload;
            return payload;
        },

        _callbackError: function (obj, textStatus, errorThrown) {
            if (obj && obj.readyState === 0) {
                this._showErrorDialog('Chiamata interrotta', '<p>Chiamata interrotta.</p>', 'warning');
                return;
            }

            var details = this._extractErrorDetails(obj, errorThrown);
            if (details && details.message) {
                this._callbackErrorMessage(details);
                return;
            }

            if (typeof this.onError === 'function') {
                this.onError(obj, textStatus, errorThrown);
            }
        },

        _callbackErrorMessage: function (msg) {
            var callbacks = this._getCallbackNames();
            var message = this._normalizeErrorMessage(msg);
            var errorCode = this._normalizeErrorCode(msg);

            if (typeof this[callbacks.error] === 'function') {
                this[callbacks.error](message, {
                    message: message,
                    errorCode: errorCode,
                    raw: msg
                });
            } else {
                this._showErrorDialog('Errore', '<p>' + message + '</p>', 'danger');
            }
        },

        onError: function () {},

        _getCallbackNames: function () {
            var name = String(this.callbackName || '').trim();
            if (name === '') {
                return { success: '', error: '' };
            }

            var cap = name.charAt(0).toUpperCase() + name.slice(1);
            return {
                success: 'on' + cap + 'Success',
                error: 'on' + cap + 'Error'
            };
        },

        _parseResponse: function (response) {
            if (response === null || typeof response === 'undefined') {
                return { payload: response, parseError: null };
            }

            if (typeof response === 'object') {
                return { payload: response, parseError: null };
            }

            var raw = String(response);
            if (raw.trim() === '') {
                return { payload: raw, parseError: null };
            }

            try {
                return { payload: JSON.parse(raw), parseError: null };
            } catch (error) {
                return { payload: null, parseError: 'Errore nel parsing JSON dei dati.' };
            }
        },

        _extractErrorCode: function (payload) {
            if (!payload || typeof payload !== 'object') {
                return '';
            }

            if (typeof payload.error_code === 'string' && payload.error_code.trim() !== '') {
                return payload.error_code.trim();
            }
            if (typeof payload.errorCode === 'string' && payload.errorCode.trim() !== '') {
                return payload.errorCode.trim();
            }

            return '';
        },

        _extractErrorDetails: function (xhr, errorThrown) {
            var message = '';
            var errorCode = '';
            var payload = null;

            if (xhr && xhr.responseJSON && typeof xhr.responseJSON === 'object') {
                payload = xhr.responseJSON;
                message = this._extractErrorMessage(xhr, errorThrown);
                errorCode = this._extractErrorCode(xhr.responseJSON);
                return {
                    message: message,
                    errorCode: errorCode,
                    payload: payload
                };
            }

            if (xhr && typeof xhr.responseText === 'string' && xhr.responseText.trim() !== '') {
                var parsed = this._parseResponse(xhr.responseText);
                if (parsed.payload && typeof parsed.payload === 'object') {
                    payload = parsed.payload;
                    message = this._extractErrorMessage(xhr, errorThrown);
                    errorCode = this._extractErrorCode(parsed.payload);
                    return {
                        message: message,
                        errorCode: errorCode,
                        payload: payload
                    };
                }
            }

            message = this._extractErrorMessage(xhr, errorThrown);
            return {
                message: message,
                errorCode: '',
                payload: payload
            };
        },

        _extractErrorMessage: function (xhr, errorThrown) {
            if (xhr && xhr.responseJSON && typeof xhr.responseJSON === 'object') {
                if (typeof xhr.responseJSON.error === 'string' && xhr.responseJSON.error.trim() !== '') {
                    return xhr.responseJSON.error;
                }
                if (typeof xhr.responseJSON.message === 'string' && xhr.responseJSON.message.trim() !== '') {
                    return xhr.responseJSON.message;
                }
            }

            if (xhr && typeof xhr.responseText === 'string' && xhr.responseText.trim() !== '') {
                var parsed = this._parseResponse(xhr.responseText);
                if (parsed.payload && typeof parsed.payload === 'object') {
                    if (typeof parsed.payload.error === 'string' && parsed.payload.error.trim() !== '') {
                        return parsed.payload.error;
                    }
                    if (typeof parsed.payload.message === 'string' && parsed.payload.message.trim() !== '') {
                        return parsed.payload.message;
                    }
                }
            }

            if (typeof errorThrown === 'string' && errorThrown.trim() !== '') {
                return errorThrown;
            }

            return '';
        },

        _normalizeErrorMessage: function (msg) {
            if (typeof msg === 'string') {
                var clean = msg.trim();
                return clean !== '' ? clean : 'Si e verificato un errore.';
            }
            if (msg && typeof msg.error === 'string' && msg.error.trim() !== '') {
                return msg.error.trim();
            }
            if (msg && typeof msg.message === 'string' && msg.message.trim() !== '') {
                return msg.message.trim();
            }
            return 'Si e verificato un errore.';
        },

        _normalizeErrorCode: function (msg) {
            if (typeof msg === 'string') {
                return '';
            }
            if (msg && typeof msg.errorCode === 'string' && msg.errorCode.trim() !== '') {
                return msg.errorCode.trim();
            }
            if (msg && typeof msg.error_code === 'string' && msg.error_code.trim() !== '') {
                return msg.error_code.trim();
            }
            if (msg && msg.payload && typeof msg.payload === 'object') {
                if (typeof msg.payload.error_code === 'string' && msg.payload.error_code.trim() !== '') {
                    return msg.payload.error_code.trim();
                }
            }
            return '';
        },

        _encodePostData: function () {
            var payload = {};
            if (this.data != null) {
                if (Array.isArray(this.data)) {
                    payload = { payload: this.data.slice() };
                } else if (typeof this.data === 'object') {
                    payload = Object.assign({}, this.data);
                } else {
                    payload = { payload: this.data };
                }
            }

            payload._csrf = this._getCsrfToken();
            return 'data=' + encodeURIComponent(JSON.stringify(payload));
        },

        _getCsrfToken: function () {
            if (typeof document === 'undefined') {
                return '';
            }
            var meta = document.querySelector('meta[name="csrf-token"]');
            return meta ? (meta.getAttribute('content') || '') : '';
        },

        _showErrorDialog: function (title, body, type) {
            var level = type || 'danger';
            if (typeof window !== 'undefined' && typeof window.Dialog === 'function') {
                window.Dialog(level, {
                    title: title,
                    body: body
                }).show();
                return;
            }

            if (typeof console !== 'undefined' && typeof console.error === 'function') {
                console.error('[Request] ' + title + ': ' + String(body || ''));
            }
        },

        _getInstance: function () {
            return this;
        }
    };

    var o = Object.assign({}, base, extension);
    return o.init();
}

if (typeof window !== 'undefined') {
    window.Request = Request;
}

if (typeof window !== 'undefined' && typeof window.Request === 'function') {
    function getCsrfToken() {
        if (typeof document === 'undefined') {
            return '';
        }
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? (meta.getAttribute('content') || '') : '';
    }

    function normalizePayload(data, csrfToken) {
        var payload = {};

        if (data != null) {
            if (Array.isArray(data)) {
                payload = { payload: data.slice() };
            } else if (typeof data === 'object') {
                payload = Object.assign({}, data);
            } else {
                payload = { payload: data };
            }
        }

        if (csrfToken) {
            payload._csrf = csrfToken;
        }

        return 'data=' + encodeURIComponent(JSON.stringify(payload));
    }

    function parseJsonSafe(value) {
        if (value == null) {
            return { payload: value, parseError: null };
        }

        if (typeof value === 'object') {
            return { payload: value, parseError: null };
        }

        var raw = String(value || '');
        if (raw.trim() === '') {
            return { payload: raw, parseError: null };
        }

        try {
            return { payload: JSON.parse(raw), parseError: null };
        } catch (error) {
            return { payload: null, parseError: 'Errore nel parsing JSON dei dati.' };
        }
    }

    function extractErrorMessage(xhr, errorThrown) {
        if (xhr && xhr.responseJSON && typeof xhr.responseJSON === 'object') {
            if (typeof xhr.responseJSON.error === 'string' && xhr.responseJSON.error.trim() !== '') {
                return xhr.responseJSON.error.trim();
            }
            if (typeof xhr.responseJSON.message === 'string' && xhr.responseJSON.message.trim() !== '') {
                return xhr.responseJSON.message.trim();
            }
        }

        if (xhr && typeof xhr.responseText === 'string' && xhr.responseText.trim() !== '') {
            var parsed = parseJsonSafe(xhr.responseText);
            if (parsed.payload && typeof parsed.payload === 'object') {
                if (typeof parsed.payload.error === 'string' && parsed.payload.error.trim() !== '') {
                    return parsed.payload.error.trim();
                }
                if (typeof parsed.payload.message === 'string' && parsed.payload.message.trim() !== '') {
                    return parsed.payload.message.trim();
                }
            }
        }

        if (typeof errorThrown === 'string' && errorThrown.trim() !== '') {
            return errorThrown.trim();
        }

        return 'Si e verificato un errore.';
    }

    function normalizeErrorCode(value) {
        if (value == null) {
            return '';
        }
        if (typeof value === 'string') {
            var clean = value.trim();
            return clean !== '' ? clean : '';
        }
        if (typeof value === 'number' && isFinite(value)) {
            return String(value);
        }
        return '';
    }

    function readErrorCodeFromObject(payload) {
        if (!payload || typeof payload !== 'object') {
            return '';
        }

        var errorCode = normalizeErrorCode(payload.error_code);
        if (errorCode) {
            return errorCode;
        }

        errorCode = normalizeErrorCode(payload.errorCode);
        if (errorCode) {
            return errorCode;
        }

        errorCode = normalizeErrorCode(payload.code);
        if (errorCode) {
            return errorCode;
        }

        return '';
    }

    function extractErrorDetails(xhr, errorThrown) {
        var payload = null;
        var errorCode = '';

        if (xhr && xhr.responseJSON && typeof xhr.responseJSON === 'object') {
            payload = xhr.responseJSON;
            errorCode = readErrorCodeFromObject(payload);
            return {
                message: extractErrorMessage(xhr, errorThrown),
                errorCode: errorCode,
                payload: payload
            };
        }

        if (xhr && typeof xhr.responseText === 'string' && xhr.responseText.trim() !== '') {
            var parsed = parseJsonSafe(xhr.responseText);
            if (parsed.payload && typeof parsed.payload === 'object') {
                payload = parsed.payload;
                errorCode = readErrorCodeFromObject(payload);
            }
        }

        return {
            message: extractErrorMessage(xhr, errorThrown),
            errorCode: errorCode,
            payload: payload
        };
    }

    function normalizeMethod(method) {
        var m = String(method || 'POST').trim().toUpperCase();
        if (!m) {
            return 'POST';
        }
        return m;
    }

    function buildAjaxOptions(options) {
        var opts = (options && typeof options === 'object') ? options : {};
        var method = normalizeMethod(opts.method || opts.type);
        var csrfToken = getCsrfToken();
        var headers = Object.assign({
            'X-Requested-With': 'XMLHttpRequest'
        }, (opts.headers && typeof opts.headers === 'object') ? opts.headers : {});
        if (csrfToken && headers['X-CSRF-Token'] == null) {
            headers['X-CSRF-Token'] = csrfToken;
        }

        var ajaxData;
        var processData = (opts.processData != null) ? !!opts.processData : true;
        var contentType = (opts.contentType != null) ? opts.contentType : 'application/x-www-form-urlencoded; charset=UTF-8';

        if (method === 'GET') {
            ajaxData = (opts.data != null) ? opts.data : {};
        } else if (opts.raw === true) {
            ajaxData = opts.data;
        } else {
            ajaxData = normalizePayload(opts.data, csrfToken);
        }

        return {
            url: String(opts.url || '').trim(),
            type: method,
            method: method,
            data: ajaxData,
            context: opts.context || null,
            headers: headers,
            processData: processData,
            contentType: contentType,
            dataType: opts.dataType || undefined,
            timeout: opts.timeout || 0
        };
    }

    function requestUnavailableMessage() {
        return 'Servizio comunicazione non disponibile. Ricarica la pagina e riprova.';
    }

    function normalizeText(value) {
        if (typeof value !== 'string') {
            return '';
        }
        var out = value.trim();
        return out !== '' ? out : '';
    }

    function readErrorMessageFromObject(payload) {
        if (!payload || typeof payload !== 'object') {
            return '';
        }

        var msg = normalizeText(payload.error);
        if (msg) {
            return msg;
        }

        msg = normalizeText(payload.message);
        if (msg) {
            return msg;
        }

        return '';
    }

    function getErrorMessage(error, fallback) {
        var fallbackMessage = normalizeText(fallback) || 'Operazione non riuscita.';
        var message = '';

        message = normalizeText(error);
        if (message) {
            return message;
        }

        if (!error || typeof error !== 'object') {
            return fallbackMessage;
        }

        message = normalizeText(error.message);
        if (message) {
            return message;
        }

        message = normalizeText(error.error);
        if (message) {
            return message;
        }

        message = readErrorMessageFromObject(error.payload);
        if (message) {
            return message;
        }

        message = readErrorMessageFromObject(error.responseJSON);
        if (message) {
            return message;
        }

        if (typeof error.responseText === 'string' && error.responseText.trim() !== '') {
            var parsed = parseJsonSafe(error.responseText);
            message = readErrorMessageFromObject(parsed.payload);
            if (message) {
                return message;
            }
        }

        if (error.xhr) {
            message = normalizeText(extractErrorMessage(error.xhr, error.errorThrown));
            if (message) {
                return message;
            }
        }

        return fallbackMessage;
    }

    function getErrorCode(error, fallback) {
        var fallbackCode = normalizeErrorCode(fallback);

        if (!error || typeof error !== 'object') {
            return fallbackCode;
        }

        var code = normalizeErrorCode(error.errorCode);
        if (code) {
            return code;
        }

        code = normalizeErrorCode(error.error_code);
        if (code) {
            return code;
        }

        code = readErrorCodeFromObject(error.payload);
        if (code) {
            return code;
        }

        code = readErrorCodeFromObject(error.responseJSON);
        if (code) {
            return code;
        }

        if (typeof error.responseText === 'string' && error.responseText.trim() !== '') {
            var parsed = parseJsonSafe(error.responseText);
            code = readErrorCodeFromObject(parsed.payload);
            if (code) {
                return code;
            }
        }

        if (error.xhr) {
            var details = extractErrorDetails(error.xhr, error.errorThrown);
            code = normalizeErrorCode(details.errorCode);
            if (code) {
                return code;
            }
        }

        code = normalizeErrorCode(error.code);
        if (code) {
            return code;
        }

        return fallbackCode;
    }

    function getErrorInfo(error, fallback) {
        return {
            message: getErrorMessage(error, fallback),
            errorCode: getErrorCode(error, ''),
            raw: error
        };
    }

    function hasErrorCode(error, expectedCode) {
        var actual = normalizeErrorCode(getErrorCode(error, ''));
        if (!actual) {
            return false;
        }

        if (Array.isArray(expectedCode)) {
            for (var i = 0; i < expectedCode.length; i += 1) {
                var candidate = normalizeErrorCode(expectedCode[i]);
                if (candidate && candidate.toLowerCase() === actual.toLowerCase()) {
                    return true;
                }
            }
            return false;
        }

        var expected = normalizeErrorCode(expectedCode);
        if (!expected) {
            return false;
        }
        return expected.toLowerCase() === actual.toLowerCase();
    }

    function hasRequestHttpMethod(methodName) {
        if (typeof window.Request !== 'function') {
            return false;
        }
        if (!window.Request.http || typeof window.Request.http !== 'object') {
            return false;
        }

        var method = String(methodName || 'request').trim();
        if (!method) {
            method = 'request';
        }

        return typeof window.Request.http[method] === 'function';
    }

    /**
     * `Request.http` — API Promise-based moderna (preferita rispetto alla factory `Request()`).
     *
     * @namespace Request.http
     *
     * Utility statiche su `window.Request`:
     * - `Request.getErrorMessage(error, fallback)` — estrae il messaggio leggibile da un errore.
     * - `Request.getErrorCode(error, fallback)`    — estrae l'error_code machine-readable.
     * - `Request.getErrorInfo(error, fallback)`    — restituisce `{ message, errorCode, raw }`.
     * - `Request.hasErrorCode(error, code|code[])` — true se l'error_code corrisponde.
     * - `Request.hasHttpMethod(name)`              — true se Request.http[name] esiste.
     * - `Request.assertHttpMethod(name)`           — restituisce `{ ok, method, message }`.
     *
     * `window.HttpService` è un alias di `Request.http`.
     */
    var http = {
        /**
         * Esegue una chiamata AJAX Promise-based.
         * Risolve con il payload della risposta, rigetta con un oggetto errore strutturato.
         *
         * @param {{ url: string, method?: string, data?: *, headers?: Object,
         *           raw?: boolean, processData?: boolean, contentType?: string,
         *           timeout?: number, context?: * }} options
         * @returns {Promise<ServerResponse>}
         */
        request: function (options) {
            return new Promise(function (resolve, reject) {
                if (typeof window.$ === 'undefined' || typeof window.$.ajax !== 'function') {
                    reject({
                        message: 'jQuery.ajax non e disponibile.',
                        code: 'ajax_unavailable'
                    });
                    return;
                }

                var ajaxOptions = buildAjaxOptions(options);
                if (!ajaxOptions.url) {
                    reject({
                        message: 'URL non valida per la chiamata Ajax.',
                        code: 'invalid_url'
                    });
                    return;
                }

                window.$.ajax(ajaxOptions).done(function (response) {
                    var parsed = parseJsonSafe(response);
                    if (parsed.parseError) {
                        reject({
                            message: parsed.parseError,
                            code: 'json_parse_error'
                        });
                        return;
                    }

                    var payload = parsed.payload;
                    if (payload && typeof payload === 'object') {
                        if (payload.error != null && payload.error !== '') {
                            reject({
                                message: String(payload.error),
                                code: 'api_error',
                                errorCode: readErrorCodeFromObject(payload),
                                payload: payload
                            });
                            return;
                        }
                        if (payload.success === false && typeof payload.message === 'string' && payload.message.trim() !== '') {
                            reject({
                                message: payload.message.trim(),
                                code: 'api_error',
                                errorCode: readErrorCodeFromObject(payload),
                                payload: payload
                            });
                            return;
                        }
                    }

                    resolve(payload);
                }).fail(function (xhr, textStatus, errorThrown) {
                    var details = extractErrorDetails(xhr, errorThrown);
                    reject({
                        message: details.message,
                        code: 'http_error',
                        errorCode: details.errorCode,
                        payload: details.payload,
                        xhr: xhr,
                        textStatus: textStatus,
                        errorThrown: errorThrown
                    });
                });
            });
        },

        /**
         * GET Promise-based. I `params` sono passati come query string.
         * @param {string} url
         * @param {Object} [params]
         * @param {Object} [options]
         * @returns {Promise<ServerResponse>}
         */
        get: function (url, params, options) {
            var opts = Object.assign({}, (options && typeof options === 'object') ? options : {}, {
                url: url,
                method: 'GET',
                data: params || {}
            });
            return this.request(opts);
        },

        /**
         * POST Promise-based. Il `data` viene serializzato come `data=...` nel body.
         * @param {string} url
         * @param {Object|Array|*} [data]
         * @param {Object} [options]
         * @returns {Promise<ServerResponse>}
         */
        post: function (url, data, options) {
            var opts = Object.assign({}, (options && typeof options === 'object') ? options : {}, {
                url: url,
                method: 'POST',
                data: data || {}
            });
            return this.request(opts);
        }
    };

    window.Request.http = http;
    window.Request.request = function (options) {
        return http.request(options);
    };
    window.Request.getUnavailableMessage = function () {
        return requestUnavailableMessage();
    };
    window.Request.getErrorMessage = function (error, fallback) {
        return getErrorInfo(error, fallback).message;
    };
    window.Request.getErrorCode = function (error, fallback) {
        return getErrorCode(error, fallback);
    };
    window.Request.getErrorInfo = function (error, fallback) {
        return getErrorInfo(error, fallback);
    };
    window.Request.hasErrorCode = function (error, expectedCode) {
        return hasErrorCode(error, expectedCode);
    };
    window.Request.hasHttpMethod = function (methodName) {
        return hasRequestHttpMethod(methodName);
    };
    window.Request.assertHttpMethod = function (methodName) {
        var method = String(methodName || 'request').trim() || 'request';
        return {
            ok: hasRequestHttpMethod(method),
            method: method,
            message: requestUnavailableMessage()
        };
    };
    window.HttpService = http;
}

