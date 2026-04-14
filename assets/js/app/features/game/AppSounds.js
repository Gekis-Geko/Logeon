(function (window) {
    'use strict';

    var KEYS = {
        dm:            'lf_sound_dm',
        notifications: 'lf_sound_notifications',
        whispers:      'lf_sound_whispers',
        global:        'lf_sound_global'
    };

    var played = {
        dm:            false,
        notifications: false,
        whispers:      false,
        global:        false
    };

    var pending = {};
    var debounceTimer = null;
    var DEBOUNCE_MS = 300;

    function getUrl(type) {
        if (!KEYS[type]) { return ''; }
        try {
            return (window.localStorage.getItem(KEYS[type]) || '').trim();
        } catch (e) {
            return '';
        }
    }

    function setUrl(type, url) {
        if (!KEYS[type]) { return; }
        try {
            var trimmed = (url || '').trim();
            if (trimmed !== '') {
                window.localStorage.setItem(KEYS[type], trimmed);
            } else {
                window.localStorage.removeItem(KEYS[type]);
            }
        } catch (e) {}
    }

    function playUrl(url) {
        if (!url || url === '') { return; }
        try {
            var audio = new Audio(url);
            var p = audio.play();
            if (p && typeof p.catch === 'function') {
                p.catch(function () {});
            }
        } catch (e) {}
    }

    function flushPending() {
        debounceTimer = null;
        var pendingTypes = Object.keys(pending).filter(function (t) { return pending[t]; });
        pending = {};

        if (pendingTypes.length === 0) { return; }

        var globalUrl = getUrl('global');
        if (pendingTypes.length >= 2 && globalUrl !== '' && !played.global) {
            played.global = true;
            for (var i = 0; i < pendingTypes.length; i++) {
                played[pendingTypes[i]] = true;
            }
            playUrl(globalUrl);
            return;
        }

        for (var j = 0; j < pendingTypes.length; j++) {
            var t = pendingTypes[j];
            if (played[t]) { continue; }
            var url = getUrl(t);
            if (url === '') { continue; }
            played[t] = true;
            playUrl(url);
        }
    }

    var AppSounds = {
        /**
         * Trigger a sound for the given type (dm | notifications | whispers).
         * Calls are batched via a 300ms debounce window.
         * If 2+ types arrive simultaneously AND a global sound is configured, the global plays once.
         * Otherwise each type plays its own specific sound, each at most once per page session.
         */
        play: function (type) {
            if (!KEYS[type] || type === 'global') { return; }
            if (played[type]) { return; }

            pending[type] = true;

            if (debounceTimer) { clearTimeout(debounceTimer); }
            debounceTimer = setTimeout(flushPending, DEBOUNCE_MS);
        },

        /** Preview a sound by type from current localStorage value. */
        preview: function (type) {
            playUrl(getUrl(type));
        },

        /** Preview an arbitrary URL (for test button before saving). */
        previewUrl: function (url) {
            playUrl((url || '').trim());
        },

        get: function (type) {
            return getUrl(type);
        },

        set: function (type, url) {
            setUrl(type, url);
        },

        clear: function (type) {
            setUrl(type, '');
        },

        types: function () {
            return Object.keys(KEYS);
        }
    };

    window.AppSounds = AppSounds;

})(window);
