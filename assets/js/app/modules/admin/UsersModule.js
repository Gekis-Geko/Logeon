const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createUsersModule() {
    return {
        ctx: null,
        options: {},

        mount: function (ctx, options) {
            this.ctx = ctx || null;
            this.options = options || {};

            if (typeof globalWindow.AdminUsers !== 'undefined' && globalWindow.AdminUsers && typeof globalWindow.AdminUsers.init === 'function') {
                globalWindow.AdminUsers.init();
            }

            return this;
        },

        unmount: function () {},

        list: function (payload) {
            return this.request('/admin/users/list', 'list', payload || {});
        },

        resetPassword: function (userId) {
            return this.request('/admin/users/reset-password', 'boUsersResetPassword', {
                user_id: userId
            });
        },

        permissions: function (payload) {
            return this.request('/admin/users/permissions', 'boUsersPermissions', payload || {});
        },

        disconnect: function (userId) {
            return this.request('/admin/users/disconnect', 'boUsersDisconnect', {
                user_id: userId
            });
        },

        restrict: function (userId, isRestricted) {
            return this.request('/admin/users/restrict', 'boUsersRestrict', {
                user_id: userId,
                is_restricted: (parseInt(isRestricted, 10) === 1) ? 1 : 0
            });
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

globalWindow.AdminUsersModuleFactory = createUsersModule;
export { createUsersModule as AdminUsersModuleFactory };
export default createUsersModule;

