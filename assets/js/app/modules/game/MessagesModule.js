(function (window) {
    'use strict';

    function createMessagesModule() {
        return {
            ctx: null,
            options: {},

            mount: function (ctx, options) {
                this.ctx = ctx || null;
                this.options = options || {};
                return this;
            },

            unmount: function () {},

            widget: function (extension) {
                if (typeof window.GameMessagesPage === 'function') {
                    try {
                        return window.GameMessagesPage(extension || {});
                    } catch (error) {}
                }

                return null;
            },

            list: function (payload) {
                return this.request('/message/threads', 'listMessageThreads', payload || {});
            },

            unread: function (payload) {
                return this.request('/message/unread', 'getMessageUnread', payload || {});
            },

            thread: function (payload) {
                return this.request('/message/thread', 'getMessageThread', payload || {});
            },

            send: function (payload) {
                return this.request('/message/send', 'sendMessage', payload || {});
            },

            deleteThread: function (payload) {
                return this.request('/message/delete-thread', 'deleteMessageThread', payload || {});
            },

            searchRecipients: function (query) {
                return this.request('/list/characters/search', 'searchRecipients', {
                    query: String(query || '').trim()
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

    window.GameMessagesModuleFactory = createMessagesModule;
})(window);
