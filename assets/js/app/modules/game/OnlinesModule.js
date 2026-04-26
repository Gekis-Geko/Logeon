const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createOnlinesModule() {
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
            return this.request('/list/onlines', 'getOnlines', payload || {});
        },

        complete: function (payload) {
            return this.request('/list/onlines/complete', 'getOnlinesComplete', payload || {});
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

globalWindow.GameOnlinesModuleFactory = createOnlinesModule;
export { createOnlinesModule as GameOnlinesModuleFactory };
export default createOnlinesModule;

