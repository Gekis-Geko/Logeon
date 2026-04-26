const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createAdminNarrativeStatesModule() {
    return {
        ctx: null,
        options: {},

        mount: function (ctx, options) {
            this.ctx = ctx || null;
            this.options = options || {};

            if (typeof globalWindow.AdminNarrativeStates !== 'undefined'
                && globalWindow.AdminNarrativeStates
                && typeof globalWindow.AdminNarrativeStates.init === 'function') {
                globalWindow.AdminNarrativeStates.init();
            }

            return this;
        },

        unmount: function () {},

        list: function (payload) {
            return this.request('/admin/narrative-states/list', 'list', payload || {});
        },

        create: function (payload) {
            return this.request('/admin/narrative-states/create', 'create', payload || {});
        },

        update: function (payload) {
            return this.request('/admin/narrative-states/update', 'update', payload || {});
        },

        remove: function (payload) {
            return this.request('/admin/narrative-states/delete', 'delete', payload || {});
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

globalWindow.AdminNarrativeStatesModuleFactory = createAdminNarrativeStatesModule;
export { createAdminNarrativeStatesModule as AdminNarrativeStatesModuleFactory };
export default createAdminNarrativeStatesModule;

