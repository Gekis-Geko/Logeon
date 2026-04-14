(function (window) {
    'use strict';

    function fallbackMessage(message) {
        var text = String(message || '').trim();
        if (text !== '') {
            return text;
        }
        return 'Operazione non riuscita.';
    }

    function info(error, fallback) {
        var fb = fallbackMessage(fallback);

        if (window.Request && typeof window.Request.getErrorInfo === 'function') {
            return window.Request.getErrorInfo(error, fb);
        }

        var message = '';
        if (typeof error === 'string' && error.trim() !== '') {
            message = error.trim();
        } else if (error && typeof error.message === 'string' && error.message.trim() !== '') {
            message = error.message.trim();
        } else if (error && typeof error.error === 'string' && error.error.trim() !== '') {
            message = error.error.trim();
        } else {
            message = fb;
        }

        var errorCode = '';
        if (window.Request && typeof window.Request.getErrorCode === 'function') {
            errorCode = window.Request.getErrorCode(error, '');
        } else if (error && typeof error === 'object') {
            if (typeof error.errorCode === 'string' && error.errorCode.trim() !== '') {
                errorCode = error.errorCode.trim();
            } else if (typeof error.error_code === 'string' && error.error_code.trim() !== '') {
                errorCode = error.error_code.trim();
            } else if (error.payload && typeof error.payload === 'object' && typeof error.payload.error_code === 'string' && error.payload.error_code.trim() !== '') {
                errorCode = error.payload.error_code.trim();
            }
        }

        return {
            message: message,
            errorCode: errorCode,
            raw: error
        };
    }

    function normalize(error, fallback) {
        return info(error, fallback).message;
    }

    function code(error, fallback) {
        return info(error, fallback).errorCode;
    }

    function normalizeCode(value) {
        return String(value || '').trim().toLowerCase();
    }

    function codeInList(actualCode, expectedCodes) {
        var actual = normalizeCode(actualCode);
        if (!actual) {
            return false;
        }

        if (!Array.isArray(expectedCodes) || expectedCodes.length === 0) {
            return false;
        }

        for (var i = 0; i < expectedCodes.length; i += 1) {
            if (normalizeCode(expectedCodes[i]) === actual) {
                return true;
            }
        }

        return false;
    }

    function hasCode(error, expectedCode) {
        if (window.Request && typeof window.Request.hasErrorCode === 'function') {
            return window.Request.hasErrorCode(error, expectedCode);
        }

        var actual = normalizeCode(code(error, ''));
        if (!actual) {
            return false;
        }

        if (Array.isArray(expectedCode)) {
            return codeInList(actual, expectedCode);
        }

        var expected = normalizeCode(expectedCode);
        if (!expected) {
            return false;
        }
        return actual === expected;
    }

    function resolve(error, fallback, options) {
        var details = info(error, fallback);
        var opts = (options && typeof options === 'object') ? options : {};

        var codeNormalized = normalizeCode(details.errorCode);
        var message = String(details.message || '').trim();
        var mapped = null;

        if (opts.map && typeof opts.map === 'object' && codeNormalized) {
            mapped = opts.map[codeNormalized];
        }

        var preferServer = codeInList(codeNormalized, opts.preferServerMessageCodes || []);
        if (!preferServer && typeof mapped === 'string' && mapped.trim() !== '') {
            message = mapped.trim();
        }

        if (message === '') {
            message = fallbackMessage(fallback);
        }

        var type = opts.defaultType || 'error';
        if (codeInList(codeNormalized, opts.validationCodes || [])) {
            type = opts.validationType || 'warning';
        }

        return {
            message: message,
            errorCode: details.errorCode || codeNormalized,
            code: codeNormalized,
            type: type,
            raw: details.raw
        };
    }

    function toast(error, fallback, type) {
        var details = info(error, fallback);
        var message = details.message;
        if (window.Toast && typeof window.Toast.show === 'function') {
            window.Toast.show({
                body: message,
                type: type || 'error'
            });
            return message;
        }
        if (typeof console !== 'undefined' && typeof console.error === 'function') {
            console.error('[GameFeatureError] ' + message);
        }
        return message;
    }

    function toastMapped(error, fallback, options) {
        var resolved = resolve(error, fallback, options);
        if (window.Toast && typeof window.Toast.show === 'function') {
            window.Toast.show({
                body: resolved.message,
                type: resolved.type || 'error'
            });
        } else if (typeof console !== 'undefined' && typeof console.error === 'function') {
            console.error('[GameFeatureError] ' + resolved.message);
        }
        return resolved;
    }

    window.GameFeatureError = window.GameFeatureError || {};
    window.GameFeatureError.info = info;
    window.GameFeatureError.normalize = normalize;
    window.GameFeatureError.code = code;
    window.GameFeatureError.normalizeCode = normalizeCode;
    window.GameFeatureError.codeInList = codeInList;
    window.GameFeatureError.hasCode = hasCode;
    window.GameFeatureError.resolve = resolve;
    window.GameFeatureError.toast = toast;
    window.GameFeatureError.toastMapped = toastMapped;
})(window);
