function getErrorInfo(error, fallback) {
    var fb = fallback || 'Operazione non riuscita.';
    if (window.GameFeatureError && typeof window.GameFeatureError.info === 'function') {
        return window.GameFeatureError.info(error, fb);
    }
    if (window.Request && typeof window.Request.getErrorInfo === 'function') {
        return window.Request.getErrorInfo(error, fb);
    }
    if (window.Request && typeof window.Request.getErrorMessage === 'function') {
        return {
            message: window.Request.getErrorMessage(error, fb),
            errorCode: '',
            raw: error
        };
    }
    if (typeof error === 'string' && error.trim() !== '') {
        return { message: error.trim(), errorCode: '', raw: error };
    }
    if (error && typeof error.message === 'string' && error.message.trim() !== '') {
        return { message: error.message.trim(), errorCode: '', raw: error };
    }
    return { message: fb, errorCode: '', raw: error };
}

function resolveErrorType(errorInfo) {
    var code = String((errorInfo && errorInfo.errorCode) || '').trim().toLowerCase();
    if (!code) {
        return 'error';
    }

    var warningCodes = [
        'validation_error',
        'thread_invalid',
        'thread_forbidden',
        'thread_title_required',
        'thread_body_required',
        'thread_locked'
    ];

    if (warningCodes.indexOf(code) !== -1) {
        return 'warning';
    }

    if (code.indexOf('invalid') !== -1 || code.indexOf('required') !== -1 || code.indexOf('forbidden') !== -1 || code.indexOf('locked') !== -1) {
        return 'warning';
    }

    return 'error';
}

function resolveModule(name) {
    if (window.RuntimeBootstrap && typeof window.RuntimeBootstrap.resolveAppModule === 'function') {
        try {
            return window.RuntimeBootstrap.resolveAppModule(name);
        } catch (error) {
            return null;
        }
    }
    return null;
}

function initNewsModal() {
    var mod = resolveModule('game.news');
    if (mod && typeof mod.widget === 'function') {
        try {
            window.News = mod.widget({});
            return window.News;
        } catch (error) {}
    }

    if (typeof window.GameNewsPage === 'function') {
        try {
            window.News = window.GameNewsPage({});
            return window.News;
        } catch (error) {}
    }

    return null;
}

function initInboxModal() {
    var mod = resolveModule('game.messages');
    if (mod && typeof mod.widget === 'function') {
        try {
            var moduleInbox = mod.widget({ key: 'modal', root: '#inbox-modal' });
            if (moduleInbox && typeof moduleInbox.loadThreads === 'function') {
                moduleInbox.loadThreads();
            }
            window.InboxMessages = moduleInbox;
            return moduleInbox;
        } catch (error) {}
    }

    if (typeof window.GameMessagesPage === 'function') {
        try {
            var neutralInbox = window.GameMessagesPage({ key: 'modal', root: '#inbox-modal' });
            if (neutralInbox && typeof neutralInbox.loadThreads === 'function') {
                neutralInbox.loadThreads();
            }
            window.InboxMessages = neutralInbox;
            return neutralInbox;
        } catch (error) {}
    }

    return null;
}

function initLocationInviteModal() {
    if (typeof window.Modal !== 'function') {
        return null;
    }
    if (!document.getElementById('location-invite-modal')) {
        return null;
    }
    if (typeof window.locationInviteModal !== 'undefined' && window.locationInviteModal) {
        return window.locationInviteModal;
    }

    window.locationInviteModal = window.Modal('location-invite-modal', {
        settings: {
            backdrop: 'static',
            keyboard: false,
            viewer: true
        },
        beforeShow: function () {
            this.modal_id.find('[name="owner_name"]').text(this.datafield.owner_name || '');
            this.modal_id.find('[name="location_name"]').text(this.datafield.location_name || '');
        }
    });

    return window.locationInviteModal;
}

function bind() {
    if (window.__gameModalsBound === true) {
        return;
    }

    if (typeof window.Modal !== 'function') {
        return;
    }

    if (document.getElementById('news-modal') && typeof window.newsModal === 'undefined') {
        window.newsModal = window.Modal('news-modal', {
            settings: {
                viewer: true
            },
            beforeShow: function () {
                initNewsModal();
            }
        });
    }
    if (document.getElementById('inbox-modal') && typeof window.inboxModal === 'undefined') {
        window.inboxModal = window.Modal('inbox-modal', {
            settings: {
                viewer: true
            },
            beforeShow: function () {
                initInboxModal();
            }
        });
    }

    if (document.getElementById('rules-modal') && typeof window.rulesModal === 'undefined') {
        window.rulesModal = window.Modal('rules-modal', {
            settings: {
                viewer: true
            },
            afterShow: function () {
                if (typeof window.DocsRender === 'function') {
                    window.rulesModalDocsRenderer = window.DocsRender('#rules-modal-body', {
                        url: '/rules/list',
                        prefix: 'rules-modal',
                        label: 'Regola',
                        subLabel: 'Sottoregola',
                        emptyText: 'Nessuna regola disponibile.'
                    });
                }
            }
        });
    }

    if (document.getElementById('storyboards-modal') && typeof window.storyboardsModal === 'undefined') {
        window.storyboardsModal = window.Modal('storyboards-modal', {
            settings: {
                viewer: true
            },
            afterShow: function () {
                if (typeof window.DocsRender === 'function') {
                    window.storyboardsModalDocsRenderer = window.DocsRender('#storyboards-modal-body', {
                        url: '/storyboards/list',
                        prefix: 'storyboards-modal',
                        label: 'Capitolo',
                        subLabel: 'Sottocapitolo',
                        emptyText: 'Nessun capitolo disponibile.'
                    });
                }
            }
        });
    }

    if (document.getElementById('how-to-play-modal') && typeof window.howToPlayModal === 'undefined') {
        window.howToPlayModal = window.Modal('how-to-play-modal', {
            settings: {
                viewer: true
            },
            afterShow: function () {
                if (typeof window.DocsRender === 'function') {
                    window.howToPlayModalDocsRenderer = window.DocsRender('#how-to-play-modal-body', {
                        url: '/how-to-play/list',
                        prefix: 'how-to-play-modal',
                        label: 'Passo',
                        subLabel: 'Sottopasso',
                        emptyText: 'Nessuna guida disponibile.'
                    });
                }
            }
        });
    }

    if (document.getElementById('thread-edit-modal') && typeof window.editThreadModal === 'undefined') {
        var threadNode = document.getElementById('thread-edit-modal');
        window.editThreadModal = window.Modal('thread-edit-modal', {
            settings: {
                urls: {
                    create: '/game/forum/thread/create',
                    update: '/game/forum/thread/update'
                }
            },
            datafield: {
                id: String(threadNode.getAttribute('data-id') || ''),
                forum_id: String(threadNode.getAttribute('data-forum-id') || ''),
                father_id: String(threadNode.getAttribute('data-father-id') || ''),
                character_id: String(threadNode.getAttribute('data-character-id') || '')
            },
            defaults: {
                forum_id: String(threadNode.getAttribute('data-forum-id') || ''),
                father_id: String(threadNode.getAttribute('data-father-id') || ''),
                character_id: String(threadNode.getAttribute('data-character-id') || '')
            },
            beforeShow: function () {
                if (!this.datafield || typeof this.datafield !== 'object') {
                    this.datafield = {};
                }

                if ((!this.datafield.forum_id || String(this.datafield.forum_id).trim() === '') && this.defaults && this.defaults.forum_id) {
                    this.datafield.forum_id = this.defaults.forum_id;
                }
                if (!Object.prototype.hasOwnProperty.call(this.datafield, 'father_id') && this.defaults) {
                    this.datafield.father_id = this.defaults.father_id || null;
                }
                if ((!this.datafield.character_id || String(this.datafield.character_id).trim() === '') && this.defaults && this.defaults.character_id) {
                    this.datafield.character_id = this.defaults.character_id;
                }

                if (typeof window.Form === 'function') {
                    window.Form().setFields(this.form, this.datafield);
                }
            },
            send: function (action) {
                var self = this;
                if (typeof window.Form !== 'function') {
                    return this;
                }

                this.dataform = window.Form().getFields(this.form) || {};
                this.beforeSend();

                var payload = Object.assign({}, this.dataform);
                if ((!payload.forum_id || String(payload.forum_id).trim() === '') && this.datafield && this.datafield.forum_id) {
                    payload.forum_id = this.datafield.forum_id;
                }
                if ((!payload.forum_id || String(payload.forum_id).trim() === '') && this.defaults && this.defaults.forum_id) {
                    payload.forum_id = this.defaults.forum_id;
                }
                if (!Object.prototype.hasOwnProperty.call(payload, 'father_id')) {
                    payload.father_id = (this.datafield && this.datafield.father_id) ? this.datafield.father_id : null;
                }
                if (payload.father_id === '' || payload.father_id === '0' || payload.father_id === 0) {
                    payload.father_id = null;
                }

                payload.title = String(payload.title || '').trim();
                if (!payload.title) {
                    Toast.show({
                        body: 'Inserisci un titolo per il thread.',
                        type: 'warning'
                    });
                    return this;
                }

                var isUpdate = payload.id != null && String(payload.id).trim() !== '';
                var method = isUpdate ? 'updateThread' : 'createThread';
                var onSuccess = function (response) {
                    self.response = response || null;
                    self.onSend(response);
                };
                var onError = function (error) {
                    var errorInfo = getErrorInfo(error, 'C\'e stato un errore: non e possibile salvare il thread.');
                    Toast.show({
                        body: errorInfo.message,
                        type: resolveErrorType(errorInfo)
                    });
                };

                var mod = resolveModule('game.forum');
                if (mod && typeof mod[method] === 'function') {
                    mod[method](payload).then(onSuccess).catch(onError);
                    return this;
                }

                if (typeof window.Request !== 'function') {
                    onError();
                    return this;
                }

                var url = isUpdate ? this.settings.urls.update : this.settings.urls.create;
                if (!window.Request.http || typeof window.Request.http.post !== 'function') {
                    onError();
                    return this;
                }
                window.Request.http.post(url, payload).then(onSuccess).catch(onError);

                return this;
            },
            onSend: function () {
                this.hide();
                if (window.Threads && typeof window.Threads.sync === 'function') {
                    window.Threads.sync();
                }
            }
        });
    }
    $('#thread-edit-modal').off('click.game-thread-edit-modal', '[data-action="thread-edit-send"]');
    $('#thread-edit-modal').on('click.game-thread-edit-modal', '[data-action="thread-edit-send"]', function (event) {
        event.preventDefault();
        if (window.editThreadModal && typeof window.editThreadModal.send === 'function') {
            window.editThreadModal.send('edit');
        }
    });

    if (document.getElementById('edit-diary-modal') && typeof window.editDiaryModal === 'undefined') {
        window.editDiaryModal = window.Modal('edit-diary-modal', {
            settings: {
                urls: {
                    create: '/events/create',
                    update: '/events/update'
                }
            },
            onInit: function () {
                var selector = '#' + this.form + ' [name="is_visible"]';
                var field = $(selector);

                if (!field.length) {
                    return;
                }

                // Cleanup legacy SelectionGroup wrappers to avoid stale radio rendering.
                var legacyWrapper = field.closest('.radio-group, .check-group');
                if (legacyWrapper.length) {
                    legacyWrapper.before(field);
                    legacyWrapper.remove();
                }

                field.next('[data-role="switch-group"]').remove();

                if (typeof window.SwitchGroup === 'function') {
                    window.SwitchGroup(field, {
                        preset: 'yesNo',
                        trueValue: '1',
                        falseValue: '0',
                        defaultValue: '1',
                        trueStyle: 'primary',
                        falseStyle: 'outline-secondary'
                    });
                    return;
                }

                if (typeof window.RadioGroup === 'function') {
                    window.RadioGroup(field, {
                        options: [
                            { label: 'Si', value: '1', style: 'primary' },
                            { label: 'No', value: '0', style: 'outline-secondary' }
                        ]
                    });
                }
            },
            beforeShow: function () {
                var isEdit = (this.datafield && this.datafield.id);
                if (!isEdit && typeof window.Form === 'function') {
                    window.Form().resetField(this.form);
                }
                if (window.Profile && typeof window.Profile.loadEventLocations === 'function') {
                    window.Profile.loadEventLocations(this.datafield ? this.datafield.location_id : null);
                    if (this.datafield && this.datafield.character_id) {
                        $('#' + this.form + ' [name="character_id"]').val(this.datafield.character_id);
                    }
                }
                if (this.datafield && this.datafield.is_visible !== undefined && this.datafield.is_visible !== null && this.datafield.is_visible !== '') {
                    $('#' + this.form + ' [name="is_visible"]').val(this.datafield.is_visible).change();
                } else {
                    $('#' + this.form + ' [name="is_visible"]').val('1').change();
                }
                $('#edit-diary-submit').text(isEdit ? 'Salva' : 'Crea');
            },
            onSend: function () {
                if (window.Profile && typeof window.Profile.loadEvents === 'function') {
                    window.Profile.loadEvents();
                }
                this.hide();
            }
        });
    }
    $('#edit-diary-modal-form').off('submit.game-edit-diary-modal');
    $('#edit-diary-modal-form').on('submit.game-edit-diary-modal', function (event) {
        event.preventDefault();
        if (window.editDiaryModal && typeof window.editDiaryModal.send === 'function') {
            window.editDiaryModal.send('edit');
        }
    });

    if (document.getElementById('location-invite-modal')) {
        initLocationInviteModal();
    }
    $('#location-invite-modal').off('click.game-location-invite-modal');
    $('#location-invite-modal').on('click.game-location-invite-modal', '[data-action="location-invite-accept"]', function (event) {
        event.preventDefault();
        if (window.LocationInvites && typeof window.LocationInvites.accept === 'function') {
            window.LocationInvites.accept();
        }
    });
    $('#location-invite-modal').on('click.game-location-invite-modal', '[data-action="location-invite-decline"]', function (event) {
        event.preventDefault();
        if (window.LocationInvites && typeof window.LocationInvites.decline === 'function') {
            window.LocationInvites.decline();
        }
    });

    window.__gameModalsBound = true;
}

function unbind() {
    if (typeof window.$ !== 'undefined') {
        window.$('#news-modal').off('click.game-news-modal');
        window.$('#inbox-modal').off('click.game-inbox-modal');
        window.$('#rules-modal').off('click.game-rules-modal');
        window.$('#storyboards-modal').off('click.game-storyboards-modal');
        window.$('#how-to-play-modal').off('click.game-how-to-play-modal');
        window.$('#thread-edit-modal').off('click.game-thread-edit-modal');
        window.$('#edit-diary-modal').off('click.game-edit-diary-modal');
        window.$('#edit-diary-modal-form').off('submit.game-edit-diary-modal');
        window.$('#location-invite-modal').off('click.game-location-invite-modal');
    }

    window.__gameModalsBound = false;
}

window.GameModals = window.GameModals || {};
window.GameModals.bind = bind;
window.GameModals.unbind = unbind;
window.GameModals.initNewsModal = initNewsModal;
window.GameModals.initInboxModal = initInboxModal;
window.GameModals.initLocationInviteModal = initLocationInviteModal;
