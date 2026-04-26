const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createGuildsModule() {
    return {
        ctx: null,
        options: {},

        mount: function (ctx, options) {
            this.ctx = ctx || null;
            this.options = options || {};
            return this;
        },

        unmount: function () {},

        list: function (payload) {
            return this.request('/guilds/list', 'getGuilds', payload || null);
        },

        get: function (payload) {
            return this.request('/guilds/get', 'getGuild', payload || {});
        },

        roles: function (payload) {
            return this.request('/guilds/roles', 'getGuildRoles', payload || {});
        },

        requirementOptions: function (payload) {
            return this.request('/guilds/requirements/options', 'getGuildRequirementOptions', payload || {});
        },

        requirementUpsert: function (payload) {
            return this.request('/guilds/requirements/upsert', 'upsertGuildRequirement', payload || {});
        },

        requirementDelete: function (payload) {
            return this.request('/guilds/requirements/delete', 'deleteGuildRequirement', payload || {});
        },

        members: function (payload) {
            return this.request('/guilds/members', 'getGuildMembers', payload || {});
        },

        applications: function (payload) {
            return this.request('/guilds/applications', 'getGuildApplications', payload || {});
        },

        decideApplication: function (payload) {
            return this.request('/guilds/application/decide', 'decideGuildApplication', payload || {});
        },

        apply: function (payload) {
            return this.request('/guilds/apply', 'applyGuild', payload || {});
        },

        claimSalary: function (payload) {
            return this.request('/guilds/salary/claim', 'claimGuildSalary', payload || {});
        },

        setPrimary: function (payload) {
            return this.request('/guilds/primary', 'setGuildPrimary', payload || {});
        },

        requestKick: function (payload) {
            return this.request('/guilds/kick/request', 'requestGuildKick', payload || {});
        },

        directKick: function (payload) {
            return this.request('/guilds/kick', 'directGuildKick', payload || {});
        },

        promote: function (payload) {
            return this.request('/guilds/promote', 'promoteGuildMember', payload || {});
        },

        announcements: function (payload) {
            return this.request('/guilds/announcements', 'getGuildAnnouncements', payload || {});
        },

        createAnnouncement: function (payload) {
            return this.request('/guilds/announcement/create', 'createGuildAnnouncement', payload || {});
        },

        deleteAnnouncement: function (payload) {
            return this.request('/guilds/announcement/delete', 'deleteGuildAnnouncement', payload || {});
        },

        events: function (payload) {
            return this.request('/guilds/events', 'getGuildEvents', payload || {});
        },

        createEvent: function (payload) {
            return this.request('/guilds/event/create', 'createGuildEvent', payload || {});
        },

        deleteEvent: function (payload) {
            return this.request('/guilds/event/delete', 'deleteGuildEvent', payload || {});
        },

        logs: function (payload) {
            return this.request('/guilds/logs', 'getGuildLogs', payload || {});
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

globalWindow.GameGuildsModuleFactory = createGuildsModule;
export { createGuildsModule as GameGuildsModuleFactory };
export default createGuildsModule;

