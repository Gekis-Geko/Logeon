(function (window) {
    'use strict';

    function createLocationChatModule() {
        return {
            ctx: null,
            options: {},

            mount: function (ctx, options) {
                this.ctx = ctx || null;
                this.options = options || {};
                return this;
            },

            unmount: function () {},

            list: function (payload) {
                return this.request('/location/messages/list', 'getLocationMessages', payload || {});
            },

            send: function (payload) {
                return this.request('/location/messages/send', 'sendLocationMessage', payload || {});
            },

            searchTargets: function (payload, action) {
                return this.request('/list/characters/search', action || 'searchLocationWhisperTargets', payload || {});
            },

            request: function (url, action, payload) {
                if (!this.ctx || !this.ctx.services || !this.ctx.services.http) {
                    return Promise.reject(new Error('HTTP service not available.'));
                }

                return this.ctx.services.http.request({
                    url: url,
                    action: action,
                    payload: payload || {}
                });
            }
        };
    }

    window.GameLocationChatModuleFactory = createLocationChatModule;
})(window);
