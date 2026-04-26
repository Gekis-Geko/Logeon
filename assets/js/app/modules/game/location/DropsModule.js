const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createLocationDropsModule() {
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
            return this.request('/location/drops/list', 'getLocationDrops', payload || {});
        },

        pick: function (payload) {
            return this.request('/location/drops/pick', 'pickDrop', payload || {});
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

globalWindow.GameLocationDropsModuleFactory = createLocationDropsModule;
export { createLocationDropsModule as GameLocationDropsModuleFactory };
export default createLocationDropsModule;

