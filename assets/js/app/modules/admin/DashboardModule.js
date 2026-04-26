const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createDashboardModule() {
    return {
        ctx: null,
        options: {},

        mount: function (ctx, options) {
            this.ctx = ctx || null;
            this.options = options || {};

            if (typeof globalWindow.Dashboard !== 'undefined' && globalWindow.Dashboard && typeof globalWindow.Dashboard.init === 'function') {
                var config = this.options.config || globalWindow.DASHBOARD_CONFIG || globalWindow.ADMIN_DASHBOARD_CONFIG || null;
                globalWindow.Dashboard.init(config);
            }

            return this;
        },

        unmount: function () {},

        summary: function (payload) {
            if (!this.ctx || !this.ctx.services || !this.ctx.services.http) {
                return Promise.reject(new Error('HTTP service not available.'));
            }

            return this.ctx.services.http.request({
                url: '/admin/dashboard/summary',
                action: 'boDashboardSummary',
                payload: payload || {}
            });
        }
    };
}

globalWindow.DashboardModuleFactory = createDashboardModule;
export { createDashboardModule as DashboardModuleFactory };
export default createDashboardModule;

