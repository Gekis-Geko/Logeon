const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createPresenceModule() {
    return {
        ctx: null,
        options: {},

        mount: function (ctx, options) {
            this.ctx = ctx || null;
            this.options = options || {};
            return this;
        },

        unmount: function () {},

        setAvailability: function (payload) {
            return this.request('/profile/availability', 'setAvailability', payload || {});
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

globalWindow.GamePresenceModuleFactory = createPresenceModule;
export { createPresenceModule as GamePresenceModuleFactory };
export default createPresenceModule;

