const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createNarrativeEventsModule() {
    return {
        ctx: null,
        options: {},

        mount: function (ctx, options) {
            this.ctx     = ctx || null;
            this.options = options || {};
            if (typeof globalWindow.GameNarrativeEventsPage === 'function') {
                globalWindow.GameNarrativeEventsPage();
            }
            return this;
        },

        unmount: function () {},

        list: function (payload) {
            return this.request('/narrative-events/list', 'listNarrativeEvents', payload || {});
        },

        get: function (payload) {
            return this.request('/narrative-events/get', 'getNarrativeEvent', payload || {});
        },

        create: function (payload) {
            return this.request('/narrative-events/create', 'createNarrativeEvent', payload || {});
        },

        close: function (payload) {
            return this.request('/narrative-events/close', 'closeNarrativeScene', payload || {});
        },

        listScenes: function (payload) {
            return this.request('/narrative-events/scenes', 'listNarrativeScenes', payload || {});
        },

        getCapabilities: function () {
            return this.request('/narrative-events/capabilities', 'getNarrativeCapabilities', {});
        },

        request: function (url, action, payload) {
            if (!this.ctx || !this.ctx.services || !this.ctx.services.http) {
                return Promise.reject(new Error('HTTP service not available.'));
            }
            return this.ctx.services.http.request({ url: url, action: action, payload: payload || {} });
        }
    };
}

globalWindow.NarrativeEventsModuleFactory = createNarrativeEventsModule;
export { createNarrativeEventsModule as NarrativeEventsModuleFactory };
export default createNarrativeEventsModule;

