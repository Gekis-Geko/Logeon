(function (window) {
    'use strict';

    function resolveModule(name) {
        if (!window.RuntimeBootstrap || typeof window.RuntimeBootstrap.resolveAppModule !== 'function') {
            return null;
        }
        try {
            return window.RuntimeBootstrap.resolveAppModule(name);
        } catch (error) {
            return null;
        }
    }


    function GameMessagesPage(extension) {
            if (!window.__messagesInstances) {
                window.__messagesInstances = {};
            }

            let key = (extension && extension.key) ? extension.key : 'modal';
            if (window.__messagesInstances[key]) {
                if (extension) {
                    Object.assign(window.__messagesInstances[key], extension);
                }
                return window.__messagesInstances[key];
            }

            let page = {
                key: key,
                root: '#inbox-modal',
                autoLoad: false,
                initialized: false,
                search_term: '',
                search_timer: null,
                compose_mode: false,
                compose_recipient_id: null,
                recipient_timer: null,
                threads: [],
                messages: [],
                messages_limit: 30,
                messages_before_id: null,
                messages_has_more: false,
                thread_id: null,
                other_id: null,
                other: null,
                thread: null,
                messagesModule: null,
                composeTypeControl: null,
                replyTypeControl: null,
                unreadPollTimer: null,
                unreadPollKey: null,
                unreadPollMs: 10000,

                $root: function () {
                    return $(this.root);
                },
                $el: function (role, fallback) {
                    let root = this.$root();
                    let el = root.find('[data-role="' + role + '"]');
                    if (!el.length && fallback) {
                        el = root.find(fallback);
                    }
                    if (!el.length && fallback) {
                        el = $(fallback);
                    }
                    return el;
                },
                $template: function (name) {
                    let tpl = this.$root().find('template[name="' + name + '"]');
                    if (!tpl.length) {
                        tpl = $('template[name="' + name + '"]');
                    }
                    return tpl;
                },
                init: function () {
                    if (this.initialized) {
                        return this;
                    }

                    this.initialized = true;
                    this.bind();
                    this.initTypePickers();
                    this.loadUnread();
                    this.startUnreadPolling();
                    if (this.autoLoad) {
                        this.loadThreads();
                    }

                    return this;
                },
                getMessagesModule: function () {
                    if (this.messagesModule) {
                        return this.messagesModule;
                    }
                    if (typeof resolveModule !== 'function') {
                        return null;
                    }

                    this.messagesModule = resolveModule('game.messages');
                    return this.messagesModule;
                },
                normalizeError: function (error, fallback) {
                    return this.getErrorInfo(error, fallback).message;
                },
                getErrorInfo: function (error, fallback) {
                    var fb = fallback || 'Operazione non riuscita.';
                    if (window.GameFeatureError && typeof window.GameFeatureError.info === 'function') {
                        return window.GameFeatureError.info(error, fb);
                    }
                    if (window.GameFeatureError && typeof window.GameFeatureError.normalize === 'function') {
                        return {
                            message: window.GameFeatureError.normalize(error, fb),
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
                    if (error && typeof error.error === 'string' && error.error.trim() !== '') {
                        return { message: error.error.trim(), errorCode: '', raw: error };
                    }
                    return { message: fb, errorCode: '', raw: error };
                },
                showErrorToast: function (error, fallback) {
                    if (window.GameFeatureError && typeof window.GameFeatureError.toastMapped === 'function') {
                        return window.GameFeatureError.toastMapped(error, fallback, {
                            map: {
                                message_empty: 'Inserisci un messaggio prima di inviare.',
                                message_too_long: 'Messaggio troppo lungo.',
                                subject_required: 'Oggetto obbligatorio.',
                                subject_too_long: 'Oggetto troppo lungo.',
                                dm_rate_limited: 'Stai inviando troppi messaggi privati. Attendi qualche secondo e riprova.',
                                character_invalid: 'Destinatario non valido.',
                                thread_invalid: 'Conversazione non valida.',
                                thread_forbidden: 'Non sei autorizzato ad accedere a questa conversazione.',
                                message_type_invalid: 'Tipo messaggio non valido.',
                                validation_error: 'Dati non validi. Controlla i campi e riprova.'
                            },
                            validationCodes: [
                                'message_empty',
                                'message_too_long',
                                'subject_required',
                                'subject_too_long',
                                'dm_rate_limited',
                                'character_invalid',
                                'thread_invalid',
                                'thread_forbidden',
                                'message_type_invalid',
                                'validation_error'
                            ],
                            validationType: 'warning',
                            defaultType: 'error',
                            preferServerMessageCodes: ['dm_rate_limited']
                        });
                    }

                    var errorInfo = this.getErrorInfo(error, fallback);
                    Toast.show({
                        body: errorInfo.message || fallback || 'Operazione non riuscita.',
                        type: 'error'
                    });
                    return errorInfo;
                },
                callMessages: function (method, payload, onSuccess, onError) {
                    var mod = this.getMessagesModule();
                    var fn = String(method || '').trim();
                    if (!mod || fn === '' || typeof mod[fn] !== 'function') {
                        if (typeof onError === 'function') {
                            onError(new Error('Messages module not available: ' + fn));
                        }
                        return false;
                    }

                    mod[fn](payload).then(function (response) {
                        if (typeof onSuccess === 'function') {
                            onSuccess(response);
                        }
                    }).catch(function (error) {
                        if (typeof onError === 'function') {
                            onError(error);
                        }
                    });

                    return true;
                },
                bind: function () {
                    var self = this;
                    let form = this.$el('inbox-message-form', '#inbox-message-form');
                    if (!form.length) {
                        return;
                    }
                    form.off('submit').on('submit', function (e) {
                        e.preventDefault();
                        self.send();
                    });

                    let search = this.$el('inbox-search', '#inbox-search');
                    if (search.length) {
                        search.off('input').on('input', function () {
                            self.search_term = $(this).val().toLowerCase().trim();
                            if (self.search_timer) {
                                window.clearTimeout(self.search_timer);
                            }
                            self.search_timer = window.setTimeout(function () {
                                self.loadThreads();
                            }, 300);
                        });
                    }

                    let composeBtn = this.$el('inbox-compose-btn', '[data-role="inbox-compose-btn"]');
                    composeBtn.off('click').on('click', function () {
                        let otherName = self.other ? ((self.other.name || '') + ' ' + ((null == self.other.surname) ? '' : self.other.surname)) : '';
                        self.startCompose(self.other_id, otherName.trim());
                    });

                    let composeCancel = this.$el('inbox-compose-cancel', '[data-role="inbox-compose-cancel"]');
                    composeCancel.off('click').on('click', function () {
                        self.hideCompose();
                    });

                    let composeSend = this.$el('inbox-compose-send', '[data-role="inbox-compose-send"]');
                    composeSend.off('click').on('click', function () {
                        self.sendNew();
                    });

                    let loadMore = this.$el('inbox-load-more', '[data-role="inbox-load-more"]');
                    loadMore.off('click').on('click', function (e) {
                        e.preventDefault();
                        self.loadMore();
                    });

                    let newThreadBtn = this.$el('inbox-new-thread', '[data-role="inbox-new-thread"]');
                    newThreadBtn.off('click').on('click', function () {
                        let otherName = self.other ? ((self.other.name || '') + ' ' + ((null == self.other.surname) ? '' : self.other.surname)) : '';
                        self.startCompose(self.other_id, otherName.trim());
                    });

                    let deleteThreadBtn = this.$el('inbox-delete-thread', '[data-role="inbox-delete-thread"]');
                    deleteThreadBtn.off('click').on('click', function () {
                        self.deleteThread();
                    });

                    let recipientInput = this.$el('compose-recipient-name', '[data-role="compose-recipient-name"]');
                    let recipientIdInput = this.$el('compose-recipient-id', '[data-role="compose-recipient-id"]');
                    let suggestions = this.$el('compose-recipient-suggestions', '[data-role="compose-recipient-suggestions"]');
                    if (recipientInput.length) {
                        recipientInput.off('input').on('input', function () {
                            let val = $(this).val().trim();
                            recipientIdInput.val('');
                            self.compose_recipient_id = null;
                            if (val.length < 2) {
                                suggestions.empty().addClass('d-none');
                                return;
                            }
                            if (self.recipient_timer) {
                                window.clearTimeout(self.recipient_timer);
                            }
                            self.recipient_timer = window.setTimeout(function () {
                                self.searchRecipients(val);
                            }, 250);
                        });
                    }

                    $(window).off('beforeunload.messagesUnread.' + this.key).on('beforeunload.messagesUnread.' + this.key, function () {
                        self.stopUnreadPolling();
                    });
                },
                initTypePickers: function () {
                    if (typeof RadioGroup !== 'function') {
                        return;
                    }

                    if (this.composeTypeControl && typeof this.composeTypeControl.destroy === 'function') {
                        this.composeTypeControl.destroy();
                    }
                    if (this.replyTypeControl && typeof this.replyTypeControl.destroy === 'function') {
                        this.replyTypeControl.destroy();
                    }

                    let composeInput = this.$el('compose-message-type', '#inbox-compose-form [name="message_type"]');
                    if (composeInput.length) {
                        if (!composeInput.val()) {
                            composeInput.val('on');
                        }
                        this.composeTypeControl = RadioGroup('#inbox-compose-form [name="message_type"]', {
                            options: [
                                { label: 'ON (In game)', value: 'on' },
                                { label: 'OFF (Fuori gioco)', value: 'off' }
                            ]
                        });
                    }

                    let replyInput = this.$el('reply-message-type', '#inbox-message-form [name="message_type"]');
                    if (replyInput.length) {
                        if (!replyInput.val()) {
                            replyInput.val('on');
                        }
                        this.replyTypeControl = RadioGroup('#inbox-message-form [name="message_type"]', {
                            class: 'me-2',
                            options: [
                                { label: 'ON', value: 'on' },
                                { label: 'OFF', value: 'off' }
                            ]
                        });
                    }
                },
                resetView: function () {
                    this.$el('inbox-empty', '#inbox-empty').removeClass('d-none');
                    this.$el('inbox-thread', '#inbox-thread').addClass('d-none');
                    this.$el('inbox-compose', '#inbox-compose').addClass('d-none');
                    this.$el('inbox-message-list', '#inbox-message-list').empty();
                    this.$el('inbox-thread-subject', '[data-role="inbox-thread-subject"]').text('');
                    this.$el('inbox-thread-participants', '[data-role="inbox-thread-participants"]').text('');
                    this.$el('inbox-load-more', '[data-role="inbox-load-more"]').addClass('d-none');
                },
                startCompose: function (other_id, other_name) {
                    this.compose_mode = true;
                    this.thread_id = null;
                    this.$el('inbox-empty', '#inbox-empty').addClass('d-none');
                    this.$el('inbox-thread', '#inbox-thread').addClass('d-none');
                    this.$el('inbox-compose', '#inbox-compose').removeClass('d-none');

                    let recipientInput = this.$el('compose-recipient-id', '[data-role="compose-recipient-id"]');
                    let recipientName = this.$el('compose-recipient-name', '[data-role="compose-recipient-name"]');
                    let suggestions = this.$el('compose-recipient-suggestions', '[data-role="compose-recipient-suggestions"]');
                    if (other_id) {
                        recipientInput.val(other_id);
                        recipientName.val(other_name || '');
                        this.compose_recipient_id = other_id;
                        suggestions.empty().addClass('d-none');
                    } else {
                        recipientInput.val('');
                        recipientName.val('');
                        this.compose_recipient_id = null;
                        suggestions.empty().addClass('d-none');
                    }

                    let typeInput = this.$el('compose-message-type', '#inbox-compose-form [name="message_type"]');
                    if (typeInput.length) {
                        typeInput.val('on').change();
                    }

                    $('#inbox-compose-form input[name="subject"]').val('');
                    $('#inbox-compose-form textarea[name="body"]').val('');
                },
                hideCompose: function () {
                    this.compose_mode = false;
                    this.$el('inbox-compose', '#inbox-compose').addClass('d-none');
                    if (this.thread_id) {
                        this.$el('inbox-thread', '#inbox-thread').removeClass('d-none');
                        this.$el('inbox-empty', '#inbox-empty').addClass('d-none');
                    } else {
                        this.$el('inbox-empty', '#inbox-empty').removeClass('d-none');
                    }
                },
                loadThreads: function () {
                    var self = this;
                    this.callMessages('list', { search: self.search_term }, function (response) {
                        self.threads = (response && response.dataset) ? response.dataset : [];
                        self.buildThreads();
                        self.updateUnreadBadge();
                    }, function (error) {
                        self.threads = [];
                        self.buildThreads();
                        self.updateUnreadBadge(0);
                        self.showErrorToast(error, 'Impossibile caricare le conversazioni.');
                    });
                },
                loadUnread: function () {
                    var self = this;
                    this.callMessages('unread', {}, function (response) {
                        if (response && typeof response.unread !== 'undefined') {
                            self.updateUnreadBadge(response.unread);
                        }
                    }, function () {
                        self.updateUnreadBadge();
                    });
                },
                startUnreadPolling: function () {
                    var self = this;
                    if (this.key !== 'modal') {
                        return;
                    }

                    this.stopUnreadPolling();

                    if (typeof PollManager === 'function') {
                        this.unreadPollKey = 'messages.unread.badge';
                        this.unreadPollTimer = PollManager().start(this.unreadPollKey, function () {
                            self.loadUnread();
                        }, this.unreadPollMs);
                        return;
                    }

                    this.unreadPollTimer = setInterval(function () {
                        self.loadUnread();
                    }, this.unreadPollMs);
                },
                stopUnreadPolling: function () {
                    if (this.unreadPollTimer) {
                        clearInterval(this.unreadPollTimer);
                        this.unreadPollTimer = null;
                    }

                    if (this.unreadPollKey && typeof PollManager === 'function') {
                        PollManager().stop(this.unreadPollKey);
                    }
                    this.unreadPollKey = null;
                },
                buildThreads: function () {
                    let block = this.$el('inbox-threads', '#inbox-threads').empty();
                    var self = this;

                    let rows = this.threads || [];
                    if (this.search_term) {
                        rows = rows.filter(function (row) {
                            let name = ((row.other_name || '') + ' ' + (row.other_surname || '')).toLowerCase();
                            let body = (row.last_message_body || '').toLowerCase();
                            let subject = (row.subject || '').toLowerCase();
                            return name.indexOf(self.search_term) !== -1 || body.indexOf(self.search_term) !== -1 || subject.indexOf(self.search_term) !== -1;
                        });
                    }

                    this.$el('inbox-thread-count', '[data-role="inbox-thread-count"]').text(rows.length);

                    if (!rows || rows.length === 0) {
                        block.append('<div class="list-group-item text-muted">Nessuna conversazione.</div>');
                        if (!this.compose_mode) {
                            this.resetView();
                        }
                        return;
                    }

                    for (var i in rows) {
                        let row = rows[i];
                        let tpl = this.$template('template_inbox_thread');
                        let template = $((tpl.html()));
                        let name = row.other_name + ' ' + ((null == row.other_surname) ? '' : row.other_surname);
                        let subject = row.subject && row.subject !== '' ? row.subject : 'Senza oggetto';
                        let type = row.last_message_type && row.last_message_type !== '' ? row.last_message_type : 'on';
                        let body = (row.last_message_body != null) ? row.last_message_body : '';
                        if (body.length > 80) {
                            body = body.substr(0, 80) + '...';
                        }

                        template.find('[name="name"]').text(name.trim() === '' ? 'Sconosciuto' : name);
                        template.find('[name="subject"]').text(subject);
                        template.find('[name="type"]').text(type.toUpperCase());
                        template.find('[name="type"]').toggleClass('text-bg-dark', type === 'on');
                        template.find('[name="type"]').toggleClass('text-bg-secondary', type !== 'on');
                        template.find('[name="body"]').text(body);
                        template.find('[name="date"]').text(this.formatDate(row.date_last_message));
                        if (row.unread_count && row.unread_count > 0) {
                            template.find('[name="unread"]').text(row.unread_count).removeClass('d-none');
                            template.addClass('mail-thread-item--unread');
                        }

                        if (this.thread_id && this.thread_id == row.id) {
                            template.addClass('active');
                        }

                        template.on('click', (function (id) {
                            return function (e) {
                                e.preventDefault();
                                self.openThread(id);
                            };
                        })(row.id));

                        template.appendTo(block);
                    }
                },
                openThread: function (thread_id) {
                    var self = this;
                    this.thread_id = thread_id;
                    this.messages_before_id = null;
                    this.messages_has_more = false;
                    this.messages = [];

                    this.compose_mode = false;
                    this.$el('inbox-empty', '#inbox-empty').addClass('d-none');
                    this.$el('inbox-compose', '#inbox-compose').addClass('d-none');
                    this.$el('inbox-thread', '#inbox-thread').removeClass('d-none');
                    this.$el('inbox-message-list', '#inbox-message-list').html('<div class="text-muted">Caricamento...</div>');

                    this.loadThread(false);
                },
                loadThread: function (prepend) {
                    var self = this;
                    let payload = {
                        thread_id: self.thread_id,
                        limit: self.messages_limit
                    };
                    if (prepend && self.messages_before_id) {
                        payload.before_id = self.messages_before_id;
                    }
                    this.callMessages('thread', payload, function (response) {
                        self.thread_id = response.thread.id;
                        self.other_id = response.other ? response.other.id : null;
                        self.other = response.other;
                        self.thread = response.thread;

                        let incoming = response.messages || [];
                        if (prepend) {
                            self.messages = incoming.concat(self.messages);
                            self.buildMessages(true);
                        } else {
                            self.messages = incoming;
                            self.buildThread();
                        }

                        self.messages_has_more = response.paging && response.paging.has_more ? true : false;
                        self.messages_before_id = (response.paging && response.paging.next_before_id) ? response.paging.next_before_id : self.messages_before_id;
                        self.updateLoadMore();
                        self.loadThreads();
                        self.loadUnread();
                    }, function (error) {
                        self.$el('inbox-message-list', '#inbox-message-list').html('<div class="text-danger">' + self.normalizeError(error, 'Impossibile caricare la conversazione.') + '</div>');
                    });
                },
                loadMore: function () {
                    if (!this.thread_id || !this.messages_has_more) {
                        return;
                    }
                    this.loadThread(true);
                },
                deleteThread: function () {
                    if (!this.thread_id) {
                        return;
                    }
                    var self = this;
                    var threadId = this.thread_id;
                    this.callMessages('deleteThread', { thread_id: threadId }, function () {
                        self.thread_id = null;
                        self.other_id = null;
                        self.other = null;
                        self.thread = null;
                        self.messages = [];
                        self.resetView();
                        self.loadThreads();
                    }, function (error) {
                        self.showErrorToast(error, 'Impossibile eliminare la conversazione.');
                    });
                },
                buildThread: function () {
                    if (this.other) {
                        let name = this.other.name + ' ' + ((null == this.other.surname) ? '' : this.other.surname);
                        this.$el('inbox-thread-participants', '[data-role="inbox-thread-participants"]').text(name);
                    }

                    if (this.thread) {
                        let subject = this.thread.subject && this.thread.subject !== '' ? this.thread.subject : 'Senza oggetto';
                        this.$el('inbox-thread-subject', '[data-role="inbox-thread-subject"]').text(subject);
                    }

                    this.buildMessages(false);
                    this.updateLoadMore();
                },
                updateLoadMore: function () {
                    let btn = this.$el('inbox-load-more', '[data-role="inbox-load-more"]');
                    if (!btn.length) {
                        return;
                    }
                    btn.toggleClass('d-none', !this.messages_has_more);
                },
                buildMessages: function (preserveScroll) {
                    let block = this.$el('inbox-message-list', '#inbox-message-list');
                    let prevHeight = 0;
                    let prevTop = 0;
                    if (preserveScroll && block.length && block[0]) {
                        prevHeight = block[0].scrollHeight;
                        prevTop = block.scrollTop();
                    }
                    block.empty();

                    if (!this.messages || this.messages.length === 0) {
                        block.append('<div class="text-muted">Nessun messaggio.</div>');
                        return;
                    }

                    let me = Storage().get('characterId');
                    for (var i in this.messages) {
                        let msg = this.messages[i];
                        let isMe = (me && msg.sender_id == me);
                        let row = $('<div class="mail-message"></div>');
                        let header = $('<div class="mail-message__meta"></div>');
                        let sender = isMe ? 'Tu' : ((msg.name || '') + ' ' + (msg.surname || '')).trim();
                        let type = (msg.message_type || 'on').toUpperCase();
                        header.text(sender + ' - ' + type + ' - ' + this.formatDate(msg.date_created));

                        let body = $('<div class="mail-message__body"></div>').text(msg.body);
                        row.append(header).append(body);
                        block.append(row);
                    }

                    if (block.length && block[0]) {
                        if (preserveScroll) {
                            let newHeight = block[0].scrollHeight;
                            block.scrollTop(newHeight - prevHeight + prevTop);
                        } else {
                            block.scrollTop(block[0].scrollHeight);
                        }
                    }
                },
                formatDate: function (dateStr) {
                    if (!dateStr) {
                        return '';
                    }
                    return Dates().formatHumanDate(dateStr) + ' ' + Dates().formatHumanTime(dateStr);
                },
                send: function () {
                    if (this.compose_mode || !this.thread_id) {
                        return this.sendNew();
                    }

                    var self = this;
                    let input = this.$el('inbox-message-input', '#inbox-message-form [name="body"]');
                    let body = input.val().trim();
                    if (body == '') {
                        return;
                    }
                    let type = $('#inbox-message-form [name="message_type"]').val() || 'on';
                    let sendPayload = {
                        thread_id: self.thread_id,
                        character_id: self.other_id,
                        message_type: type,
                        body: body
                    };
                    this.callMessages('send', sendPayload, function (response) {
                        if (response && response.message) {
                            self.messages.push(response.message);
                            self.buildMessages();
                            input.val('');
                            self.loadThreads();
                            self.loadUnread();
                        }
                    }, function (error) {
                        self.showErrorToast(error, 'Impossibile inviare il messaggio.');
                    });
                },
                sendNew: function () {
                    var self = this;
                    let formId = 'inbox-compose-form';
                    let data = Form().getFields(formId);
                    let recipientId = (data.recipient_id || '').toString().trim();
                    let subject = (data.subject || '').toString().trim();
                    let type = (data.message_type || 'on').toString().trim();
                    let body = (data.body || '').toString().trim();

                    if (recipientId === '' || subject === '' || body === '') {
                        Toast.show({
                            body: 'Completa destinatario, oggetto e messaggio.',
                            type: 'error'
                        });
                        return;
                    }
                    let sendPayload = {
                        character_id: recipientId,
                        subject: subject,
                        message_type: type,
                        body: body
                    };
                    this.callMessages('send', sendPayload, function (response) {
                        if (response && response.thread_id) {
                            self.compose_mode = false;
                            self.thread_id = response.thread_id;
                            self.thread = response.thread || null;
                            self.hideCompose();
                            self.loadThreads();
                            self.openThread(response.thread_id);
                        }
                    }, function (error) {
                        self.showErrorToast(error, 'Impossibile inviare il messaggio.');
                    });
                },
                updateUnreadBadge: function (forcedCount) {
                    var count = forcedCount;
                    if (typeof count === 'undefined') {
                        count = 0;
                        if (this.threads && this.threads.length) {
                            for (var i in this.threads) {
                                count += (this.threads[i].unread_count ? parseInt(this.threads[i].unread_count, 10) : 0);
                            }
                        }
                    }

                    if (count > 0 && window.AppSounds && typeof window.AppSounds.play === 'function') {
                        window.AppSounds.play('dm');
                    }

                    var show = count > 0;
                    var label = (count > 99) ? '99+' : String(count);
                    var badges = document.querySelectorAll('[data-feed-badge="messages"]');
                    for (var i = 0; i < badges.length; i++) {
                        badges[i].textContent = label;
                        badges[i].classList.toggle('d-none', !show);
                        badges[i].classList.toggle('feed-badge-pulse', show);
                    }
                },
                renderRecipientSuggestions: function (list) {
                    var self = this;
                    let suggestions = this.$el('compose-recipient-suggestions', '[data-role="compose-recipient-suggestions"]');
                    suggestions.empty();

                    if (!list || !list.length) {
                        suggestions.addClass('d-none');
                        return;
                    }

                    for (var i in list) {
                        var row = list[i] || {};
                        var label = ((row.name || '') + ' ' + ((row.surname || '') ? row.surname : '')).trim();
                        var name = label === '' ? 'Sconosciuto' : label;
                        var rowId = row.id;
                        var item = $('<a href="#" class="list-group-item list-group-item-action"></a>');
                        item.text(name);
                        item.on('click', (function (recipientId, recipientName) {
                            return function (e) {
                                e.preventDefault();
                                self.$el('compose-recipient-name', '[data-role="compose-recipient-name"]').val(recipientName);
                                self.$el('compose-recipient-id', '[data-role="compose-recipient-id"]').val(recipientId);
                                self.compose_recipient_id = recipientId;
                                suggestions.empty().addClass('d-none');
                            };
                        })(rowId, name));
                        suggestions.append(item);
                    }

                    suggestions.removeClass('d-none');
                },
                searchRecipients: function (query) {
                    var self = this;
                    this.callMessages('searchRecipients', query, function (response) {
                        let list = (response && response.dataset) ? response.dataset : [];
                        self.renderRecipientSuggestions(list);
                    }, function () {
                        self.renderRecipientSuggestions([]);
                    });
                }
            };

            let obj = Object.assign({}, page, extension);
            obj.key = key;
            window.__messagesInstances[key] = obj;
            return obj.init();
    }
    window.GameMessagesPage = GameMessagesPage;
})(window);



