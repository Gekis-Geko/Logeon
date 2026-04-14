(function (window) {
    'use strict';

    function createLocationInvitesModule() {
        return {
            ctx: null,
            options: {},

            mount: function (ctx, options) {
                this.ctx = ctx || null;
                this.options = options || {};
                return this;
            },

            unmount: function () {},

            pending: function (payload) {
                return this.request('/location/invites', 'getLocationInvites', payload || {});
            },

            ownerUpdates: function (payload) {
                return this.request('/location/invite/owner-updates', 'getLocationInviteUpdates', payload || {});
            },

            respond: function (payload) {
                return this.request('/location/invite/respond', 'respondLocationInvite', payload || {});
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

    window.GameLocationInvitesModuleFactory = createLocationInvitesModule;
})(window);
