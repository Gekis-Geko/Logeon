(function (window) {
    'use strict';

    function buildThreadsGridConfig() {
        return {
            name: 'Threads',
            autoindex: 'id',
            orderable: false,
            thead: false,
            handler: {
                url: '/list/forum/threads',
                action: 'list'
            },
            nav: {
                display: 'bottom',
                urlupdate: 1,
                results: 10,
                page: 1
            },
            columns: [
                {
                    label: '',
                    sortable: false,
                    style: {
                        textAlign: 'left'
                    },
                    format: function (response) {
                        var date = Dates().formatHumanDateTime(response.date_created);
                        var author = '<h6>Autore: <b>' + response.name + '</b></h6>';
                        var important = (response.is_important) ? '<i class="bi bi-star-fill text-warning"></i>' : '';
                        var closed = (response.is_closed) ? '<i class="bi bi-lock-fill text-danger"></i>' : '';
                        var title = '<div class="d-flex justify-content-between"><h5>' + important + closed + ' <a href="/game/forum/' + response.forum_id + '/thread/' + response.id + '/"><b>' + response.title + '</b></a></h5> <small class="ms-3">Scritto il: <b>' + date + '</b></small></div>';
                        var body = '';
                        if (response.body.length > 250) {
                            body = '<hr class="" /><p>' + response.body.substr(0, 500) + '</p> <a href="/game/forum/' + response.forum_id + '/thread/' + response.id + '/"><small>Continua ...</small></a>';
                        } else {
                            body = '<p>' + response.body + '</p>';
                        }

                        return '<div class="p-3">' + title + author + body + '</div>';
                    }
                }
            ]
        };
    }

    function buildAnswersGridConfig() {
        return {
            name: 'Answers',
            autoindex: 'id',
            orderable: false,
            thead: false,
            handler: {
                url: '/list/forum/threads',
                action: 'list'
            },
            nav: {
                display: 1,
                urlupdate: 1,
                results: 10,
                page: 1
            },
            columns: [
                {
                    label: '',
                    sortable: false,
                    style: {
                        textAlign: 'left'
                    },
                    format: function (response) {
                        var template = $($('[name="template_thread_answer"]').html());

                        if (null != response.date_updated) {
                            template.find('[name="answer_date"]').append('Scritto il ' + Dates().formatHumanDateTime(response.date_created) + '<small> - Modificato il: ' + Dates().formatHumanDateTime(response.date_updated) + '</small>');
                        } else {
                            template.find('[name="answer_date"]').html('Scritto il ' + Dates().formatHumanDateTime(response.date_created));
                        }

                        template.find('[name="answer_character"]').html(response.name);
                        template.find('[name="link_profile"]').attr('href', '/game/profile/' + response.character_id);

                        template.find('[name="answer_body"]').html('<p>' + response.body + '</p>');

                        if ((typeof PermissionGate === 'function' && PermissionGate().canAdminForum()) || response.character_id == Storage().get('characterId')) {
                            template.find('[name="answer_buttons"]').append(
                                '<button type="button" title="Modifica" class="btn btn-sm btn-secondary mx-1" data-action="thread-answer-edit" data-answer-id="' + response.id + '"><i class="bi bi-pencil-fill"></i></button>'
                            );
                        }

                        template.find('[name="answer_buttons"]').append(
                            '<button type="button" title="Quota la risposta" class="btn btn-sm btn-secondary btn-quotes mx-1" data-action="thread-answer-quote"'
                            + ' data-quote-name="' + encodeURIComponent(response.name || '') + '"'
                            + ' data-quote-body="' + encodeURIComponent(response.body || '') + '"'
                            + '><i class="bi bi-quote"></i></button>'
                        );

                        if ((typeof PermissionGate === 'function' && PermissionGate().canAdminForum()) || response.character_id == Storage().get('characterId')) {
                            template.find('[name="answer_buttons"]').append(
                                '<button type="button" title="Elimina" class="btn btn-sm btn-danger mx-1" data-action="thread-answer-delete" data-answer-id="' + response.id + '"><i class="bi bi-trash2-fill"></i></button>'
                            );
                        }

                        return template.html();
                    }
                }
            ]
        };
    }

    function createForumModule() {
        return {
            ctx: null,
            options: {},

            mount: function (ctx, options) {
                this.ctx = ctx || null;
                this.options = options || {};
                return this;
            },

            unmount: function () {},

            listForums: function (payload) {
                return this.request('/list/forum', 'getForum', payload || {});
            },

            listTypes: function (payload) {
                return this.request('/list/forum-types', 'getTypes', payload || {});
            },

            getForum: function (payload) {
                return this.request('/get/forum', 'getForum', payload || {});
            },

            getThread: function (payload) {
                return this.request('/get/forum/thread', 'getThread', payload || {});
            },

            createThread: function (payload) {
                return this.request('/game/forum/thread/create', 'createThread', payload || {});
            },

            updateThread: function (payload) {
                return this.request('/game/forum/thread/update', 'updateThread', payload || {});
            },

            answer: function (payload) {
                return this.request('/game/forum/thread/answer', 'answered', payload || {});
            },

            setImportantCommon: function (payload) {
                return this.request('/game/forum/thread/set/common', 'setImportant', payload || {});
            },

            setImportant: function (payload) {
                return this.request('/game/forum/thread/set/important', 'setImportant', payload || {});
            },

            unlockThread: function (payload) {
                return this.request('/game/forum/thread/set/unlock', 'setLock', payload || {});
            },

            lockThread: function (payload) {
                return this.request('/game/forum/thread/set/lock', 'setLock', payload || {});
            },

            deleteThread: function (payload) {
                return this.request('/game/forum/thread/delete', 'deleteThread', payload || {});
            },

            moveThread: function (payload) {
                return this.request('/game/forum/thread/move', 'moveThread', payload || {});
            },

            threadsGridConfig: function () {
                return buildThreadsGridConfig();
            },

            answersGridConfig: function () {
                return buildAnswersGridConfig();
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

    window.GameForumModuleFactory = createForumModule;
})(window);
