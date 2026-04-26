const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createProfileModule() {
    return {
        ctx: null,
        options: {},

        mount: function (ctx, options) {
            this.ctx = ctx || null;
            this.options = options || {};
            return this;
        },

        unmount: function () {},

        uploadSettings: function (payload) {
            return this.request('/settings/upload', 'uploadSettings', payload || {});
        },

        getProfile: function (characterId) {
            return this.request('/get/profile', 'getProfile', { id: characterId });
        },

        updateProfile: function (payload) {
            return this.request('/profile/update', 'updateProfile', payload || {});
        },

        requestLoanfaceChange: function (payload) {
            return this.request('/profile/loanface-request', 'requestLoanfaceChange', payload || {});
        },

        requestIdentityChange: function (payload) {
            return this.request('/profile/identity-request', 'requestIdentityChange', payload || {});
        },

        getFriendsKnowledgeHtml: function (characterId) {
            return this.request('/profile/relationships/html/get', 'getFriendsKnowledgeHtml', { id: characterId });
        },

        updateFriendsKnowledgeHtml: function (payload) {
            return this.request('/profile/relationships/html/update', 'updateFriendsKnowledgeHtml', payload || {});
        },

        listBonds: function (payload) {
            return this.request('/profile/bonds/list', 'listBonds', payload || {});
        },

        requestBond: function (payload) {
            return this.request('/profile/bonds/request', 'requestBond', payload || {});
        },

        respondBondRequest: function (payload) {
            return this.request('/profile/bonds/request/respond', 'respondBondRequest', payload || {});
        },

        experienceLogs: function (payload) {
            return this.request('/profile/logs/experience', 'experienceLogs', payload || {});
        },

        economyLogs: function (payload) {
            return this.request('/profile/logs/economy', 'economyLogs', payload || {});
        },

        sessionLogs: function (payload) {
            return this.request('/profile/logs/sessions', 'sessionLogs', payload || {});
        },

        updateMasterNotes: function (payload) {
            return this.request('/profile/master-notes/update', 'updateMasterNotes', payload || {});
        },

        updateHealth: function (payload) {
            return this.request('/profile/health/update', 'updateHealth', payload || {});
        },

        assignExperience: function (payload) {
            return this.request('/profile/experience/assign', 'assignExperience', payload || {});
        },

        listAttributes: function (payload) {
            return this.request('/profile/attributes/list', 'listAttributes', payload || {});
        },

        updateAttributeValues: function (payload) {
            return this.request('/profile/attributes/update-values', 'updateAttributeValues', payload || {});
        },

        recomputeAttributes: function (payload) {
            return this.request('/profile/attributes/recompute', 'recomputeAttributes', payload || {});
        },

        listEvents: function (characterId) {
            return this.request('/events/list', 'getCharacterEvents', { character_id: characterId });
        },

        deleteEvent: function (eventId) {
            return this.request('/events/delete', 'deleteCharacterEvent', { id: eventId });
        },

        listLocations: function (payload) {
            return this.request('/list/locations', 'getEventLocations', payload || {
                results: 500,
                page: 1,
                orderBy: 'id|ASC'
            });
        },

        finalizeUpload: function (payload) {
            var data = payload || {};
            var token = data.token ? String(data.token).trim() : '';
            if (token === '') {
                return Promise.reject(new Error('Upload token mancante.'));
            }
            return this.request('/uploader?action=uploadFinalize&token=' + encodeURIComponent(token), 'uploadFinalize', {
                target: data.target || ''
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

globalWindow.GameProfileModuleFactory = createProfileModule;
export { createProfileModule as GameProfileModuleFactory };
export default createProfileModule;

