const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createShopModule() {
    return {
        ctx: null,
        options: {},

        mount: function (ctx, options) {
            this.ctx = ctx || null;
            this.options = options || {};
            return this;
        },

        unmount: function () {},

        items: function (payload) {
            return this.request('/shop/items', 'getShopItems', payload || {});
        },

        buy: function (payload) {
            return this.request('/shop/buy', 'buyItem', payload || {});
        },

        sell: function (payload) {
            return this.request('/shop/sell', 'sellItem', payload || {});
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

globalWindow.GameShopModuleFactory = createShopModule;
export { createShopModule as GameShopModuleFactory };
export default createShopModule;

