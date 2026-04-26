const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createSystemEventsModule() {
    return {
        ctx: null,
        options: {},
        page: null,

        mount: function (ctx, options) {
            this.ctx = ctx || null;
            this.options = options || {};

            if (typeof globalWindow.GameSystemEventsPage === 'function') {
                this.page = globalWindow.GameSystemEventsPage({ moduleApi: this });
            }
            return this;
        },

        unmount: function () {
            if (this.page && typeof this.page.unmount === 'function') {
                try { this.page.unmount(); } catch (e) {}
            }
            this.page = null;
        },

        list: function (payload) {
            return this.request('/system-events/list', 'listSystemEvents', payload || {});
        },

        get: function (payload) {
            return this.request('/system-events/get', 'getSystemEvent', payload || {});
        },

        join: function (payload) {
            return this.request('/system-events/participation/join', 'joinSystemEvent', payload || {});
        },

        leave: function (payload) {
            return this.request('/system-events/participation/leave', 'leaveSystemEvent', payload || {});
        },

        myFactions: function () {
            return this.request('/factions/my', 'myFactionsForSystemEvents', {});
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

globalWindow.SystemEventsModuleFactory = createSystemEventsModule;
export { createSystemEventsModule as SystemEventsModuleFactory };
export default createSystemEventsModule;

