const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createLocationWhispersModule() {
    return {
        ctx: null,
        options: {},

        mount: function (ctx, options) {
            this.ctx = ctx || null;
            this.options = options || {};
            return this;
        },

        unmount: function () {},

        searchTargets: function (payload) {
            return this.request('/list/characters/search', 'searchWhisperTargets', payload || {});
        },

        list: function (payload) {
            return this.request('/location/whispers/list', 'getLocationWhispers', payload || {});
        },

        threads: function (payload) {
            return this.request('/location/whispers/threads', 'getLocationWhispersThreads', payload || {});
        },

        unread: function (payload) {
            return this.request('/location/whispers/unread', 'getLocationWhispersUnread', payload || {});
        },

        send: function (payload) {
            return this.request('/location/whispers/send', 'sendLocationWhisper', payload || {});
        },

        setPolicy: function (payload) {
            return this.request('/location/whispers/policy', 'setLocationWhisperPolicy', payload || {});
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

globalWindow.GameLocationWhispersModuleFactory = createLocationWhispersModule;
export { createLocationWhispersModule as GameLocationWhispersModuleFactory };
export default createLocationWhispersModule;

