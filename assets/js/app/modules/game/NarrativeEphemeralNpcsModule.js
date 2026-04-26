const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createNarrativeEphemeralNpcsModule() {
    return {
        ctx: null,
        options: {},

        mount: function (ctx, options) {
            this.ctx     = ctx || null;
            this.options = options || {};
            return this;
        },

        unmount: function () {},

        spawn: function (payload) {
            return this.request('/narrative-ephemeral-npcs/spawn', 'spawnEphemeralNpc', payload || {});
        },

        list: function (payload) {
            return this.request('/narrative-ephemeral-npcs/list', 'listEphemeralNpcs', payload || {});
        },

        delete: function (payload) {
            return this.request('/narrative-ephemeral-npcs/delete', 'deleteEphemeralNpc', payload || {});
        },

        request: function (url, action, payload) {
            if (!this.ctx || !this.ctx.services || !this.ctx.services.http) {
                return Promise.reject(new Error('HTTP service not available.'));
            }
            return this.ctx.services.http.request({ url: url, action: action, payload: payload || {} });
        }
    };
}

globalWindow.NarrativeEphemeralNpcsModuleFactory = createNarrativeEphemeralNpcsModule;
export { createNarrativeEphemeralNpcsModule as NarrativeEphemeralNpcsModuleFactory };
export default createNarrativeEphemeralNpcsModule;

