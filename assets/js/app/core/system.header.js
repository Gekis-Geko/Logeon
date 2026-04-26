function readMeta(name) {
    var selector = 'meta[name="' + name + '"]';
    var node = document.querySelector(selector);
    if (!node) {
        return '';
    }
    return String(node.getAttribute('content') || '').trim();
}

function readJsonScript(id, fallback) {
    var node = document.getElementById(id);
    if (!node) {
        return fallback;
    }

    var raw = String(node.textContent || node.innerText || '').trim();
    if (raw === '') {
        return fallback;
    }

    try {
        return JSON.parse(raw);
    } catch (error) {
        return fallback;
    }
}

function toInt(value, fallback) {
    var parsed = parseInt(value, 10);
    if (isNaN(parsed)) {
        return fallback;
    }
    return parsed;
}

function initAppConfig() {
    window.APP_CONFIG = window.APP_CONFIG || {};

    var idleMinutes = readMeta('app-config-availability-idle-minutes');
    var autoToast = readMeta('app-config-onlines-auto-toast');
    var chatCommands = readJsonScript('app-config-chat-commands', []);

    window.APP_CONFIG.availability_idle_minutes = toInt(idleMinutes, 20);
    window.APP_CONFIG.onlines_auto_toast = toInt(autoToast, 0);
    window.APP_CONFIG.chat_commands = Array.isArray(chatCommands) ? chatCommands : [];
}

function initModuleEndpoints() {
    var defaults = {
        currenciesList: '/admin/core-currencies/list',
        currenciesCreate: '/admin/core-currencies/create',
        currenciesUpdate: '/admin/core-currencies/update',
        currenciesDelete: '/admin/core-currencies/delete'
    };
    var fromHooks = readJsonScript('app-module-endpoints', {});
    var hookMap = (fromHooks && typeof fromHooks === 'object' && !Array.isArray(fromHooks))
        ? fromHooks
        : {};

    var current = (typeof window.LogeonModuleEndpoints === 'object' && window.LogeonModuleEndpoints)
        ? window.LogeonModuleEndpoints
        : {};

    window.LogeonModuleEndpoints = Object.assign({}, defaults, hookMap, current);
}

function initJqueryContains() {
    if (typeof window.jQuery === 'undefined') {
        return;
    }
    window.jQuery.expr[':'].contains = function (a, i, m) {
        return window.jQuery(a).text().toUpperCase().indexOf(m[3].toUpperCase()) >= 0;
    };
}

function start() {
    initAppConfig();
    initModuleEndpoints();
    initJqueryContains();
}

start();

window.SystemHeader = window.SystemHeader || {};
window.SystemHeader.start = start;
window.SystemHeader.initAppConfig = initAppConfig;
window.SystemHeader.initModuleEndpoints = initModuleEndpoints;
window.SystemHeader.initJqueryContains = initJqueryContains;
