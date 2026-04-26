
function createFactionsModule() {
    return {
        ctx: null,
        options: {},
        page: null,

        mount: function (ctx, options) {
            this.ctx     = ctx || null;
            this.options = options || {};

            if (typeof window.GameFactionsPage === 'function') {
                this.page = window.GameFactionsPage({ moduleApi: this });
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
            return this.request('/factions/list', 'listFactions', payload || {});
        },

        get: function (payload) {
            return this.request('/factions/get', 'getFaction', payload || {});
        },

        myFactions: function (payload) {
            return this.request('/factions/my', 'myFactions', payload || {});
        },

        getFactionMembers: function (payload) {
            return this.request('/factions/members', 'getFactionMembers', payload || {});
        },

        getFactionRelations: function (payload) {
            return this.request('/factions/relations', 'getFactionRelations', payload || {});
        },

        leaveFaction: function (payload) {
            return this.request('/factions/leave', 'leaveFaction', payload || {});
        },

        sendJoinRequest: function (payload) {
            return this.request('/factions/join-request/send', 'sendJoinRequest', payload || {});
        },

        withdrawJoinRequest: function (payload) {
            return this.request('/factions/join-request/withdraw', 'withdrawJoinRequest', payload || {});
        },

        myJoinRequests: function (payload) {
            return this.request('/factions/join-request/my', 'myJoinRequests', payload || {});
        },

        leaderListRequests: function (payload) {
            return this.request('/factions/leader/requests', 'leaderListRequests', payload || {});
        },

        leaderReviewRequest: function (payload) {
            return this.request('/factions/leader/request/review', 'leaderReviewRequest', payload || {});
        },

        leaderInvite: function (payload) {
            return this.request('/factions/leader/invite', 'leaderInvite', payload || {});
        },

        leaderExpel: function (payload) {
            return this.request('/factions/leader/expel', 'leaderExpel', payload || {});
        },

        leaderProposeRelation: function (payload) {
            return this.request('/factions/leader/relation', 'leaderProposeRelation', payload || {});
        },

        request: function (url, action, payload) {
            if (!this.ctx || !this.ctx.services || !this.ctx.services.http) {
                return Promise.reject(new Error('HTTP service not available.'));
            }
            return this.ctx.services.http.request({ url: url, action: action, payload: payload || {} });
        }
    };
}

window.FactionsModuleFactory = createFactionsModule;