(function (window) {
    'use strict';

    function createDashboardModule() {
        return {
            ctx: null,
            options: {},

            mount: function (ctx, options) {
                this.ctx = ctx || null;
                this.options = options || {};

                if (typeof window.Dashboard !== 'undefined' && window.Dashboard && typeof window.Dashboard.init === 'function') {
                    var config = this.options.config || window.DASHBOARD_CONFIG || window.ADMIN_DASHBOARD_CONFIG || null;
                    window.Dashboard.init(config);
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

    window.DashboardModuleFactory = createDashboardModule;
})(window);
