(function (window) {
    'use strict';

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
        var mapsViewMode = readMeta('app-config-maps-view-mode');
        var chatCommands = readJsonScript('app-config-chat-commands', []);

        window.APP_CONFIG.availability_idle_minutes = toInt(idleMinutes, 20);
        window.APP_CONFIG.onlines_auto_toast = toInt(autoToast, 0);
        window.APP_CONFIG.maps_view_mode = mapsViewMode || 'cards';
        window.APP_CONFIG.chat_commands = Array.isArray(chatCommands) ? chatCommands : [];
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
        initJqueryContains();
    }

    start();

    window.SystemHeader = window.SystemHeader || {};
    window.SystemHeader.start = start;
    window.SystemHeader.initAppConfig = initAppConfig;
    window.SystemHeader.initJqueryContains = initJqueryContains;
})(window);
