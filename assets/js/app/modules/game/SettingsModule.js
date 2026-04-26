const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createSettingsModule() {
    return {
        ctx: null,
        options: {},

        mount: function (ctx, options) {
            this.ctx = ctx || null;
            this.options = options || {};
            return this;
        },

        unmount: function () {},

        getProfile: function (characterId) {
            return this.request('/get/profile', 'getProfile', { id: characterId });
        },

        updateSettings: function (payload) {
            return this.request('/profile/settings', 'updateSettings', payload || {});
        },

        changePassword: function (payload) {
            return this.request('/settings/password', 'changePassword', payload || {});
        },

        revokeSessions: function (payload) {
            return this.request('/settings/sessions/revoke', 'revokeSessions', payload || {});
        },

        requestNameChange: function (payload) {
            return this.request('/profile/name-request', 'requestNameChange', payload || {});
        },

        requestDelete: function (payload) {
            return this.request('/profile/delete', 'requestDelete', payload || {});
        },

        cancelDelete: function (payload) {
            return this.request('/profile/delete/cancel', 'cancelDelete', payload || {});
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

globalWindow.GameSettingsModuleFactory = createSettingsModule;
export { createSettingsModule as GameSettingsModuleFactory };
export default createSettingsModule;

