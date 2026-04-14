(function (window) {
    'use strict';

    function createQuestsModule() {
        return {
            ctx: null,
            options: {},
            page: null,

            mount: function (ctx, options) {
                this.ctx = ctx || null;
                this.options = options || {};

                if (typeof window.GameQuestsPage === 'function') {
                    this.page = window.GameQuestsPage({ moduleApi: this });
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
                return this.request('/quests/list', 'listQuests', payload || {});
            },

            get: function (payload) {
                return this.request('/quests/get', 'getQuest', payload || {});
            },

            join: function (payload) {
                return this.request('/quests/participation/join', 'joinQuest', payload || {});
            },

            leave: function (payload) {
                return this.request('/quests/participation/leave', 'leaveQuest', payload || {});
            },

            staffInstancesList: function (payload) {
                return this.request('/quests/staff/instances/list', 'staffQuestInstancesList', payload || {});
            },

            staffStepConfirm: function (payload) {
                return this.request('/quests/staff/step/confirm', 'staffQuestStepConfirm', payload || {});
            },

            staffStatusSet: function (payload) {
                return this.request('/quests/staff/instance/status-set', 'staffQuestStatusSet', payload || {});
            },

            staffForceProgress: function (payload) {
                return this.request('/quests/staff/instance/force-progress', 'staffQuestForceProgress', payload || {});
            },

            staffClosureGet: function (payload) {
                return this.request('/quests/staff/closure/get', 'staffQuestClosureGet', payload || {});
            },

            staffClosureFinalize: function (payload) {
                return this.request('/quests/staff/closure/finalize', 'staffQuestClosureFinalize', payload || {});
            },

            historyList: function (payload) {
                return this.request('/quests/history/list', 'questHistoryList', payload || {});
            },

            historyGet: function (payload) {
                return this.request('/quests/history/get', 'questHistoryGet', payload || {});
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

    window.QuestsModuleFactory = createQuestsModule;
})(window);
