(function (window) {
    'use strict';

    function createJobsModule() {
        return {
            ctx: null,
            options: {},

            mount: function (ctx, options) {
                this.ctx = ctx || null;
                this.options = options || {};
                return this;
            },

            unmount: function () {},

            current: function (payload, action) {
                return this.request('/jobs/current', action || 'getJobsCurrent', payload || null);
            },

            summary: function () {
                return this.request('/jobs/current', 'getJobSummary', null);
            },

            available: function (payload) {
                return this.request('/jobs/available', 'getJobsAvailable', payload || {});
            },

            assign: function (payload) {
                return this.request('/jobs/assign', 'assignJob', payload || {});
            },

            leave: function (payload) {
                return this.request('/jobs/leave', 'leaveJob', payload || {});
            },

            completeTask: function (payload) {
                return this.request('/jobs/task/complete', 'completeJobTask', payload || {});
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

    window.GameJobsModuleFactory = createJobsModule;
})(window);


