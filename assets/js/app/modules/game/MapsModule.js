const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createMapsModule() {
    return {
        ctx: null,
        options: {},

        mount: function (ctx, options) {
            this.ctx = ctx || null;
            this.options = options || {};
            return this;
        },

        unmount: function () {},

        listMaps: function (payload, action) {
            return this.request('/list/maps', action || 'getMaps', payload || {});
        },

        listLocations: function (payload, action) {
            return this.request('/list/locations', action || 'getLocations', payload || {});
        },

        mapNeighbors: function (payload) {
            return this.request('/list/maps', 'getMapNeighbors', payload || {});
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

globalWindow.GameMapsModuleFactory = createMapsModule;
export { createMapsModule as GameMapsModuleFactory };
export default createMapsModule;

