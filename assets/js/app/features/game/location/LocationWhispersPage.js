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

    function normalizeWhisperError(error, fallback) {
        if (window.GameFeatureError && typeof window.GameFeatureError.normalize === 'function') {
            return window.GameFeatureError.normalize(error, fallback || 'Operazione non riuscita.');
        }
        if (typeof error === 'string' && error.trim() !== '') {
            return error.trim();
        }
        if (error && typeof error.message === 'string' && error.message.trim() !== '') {
            return error.message.trim();
        }
        return fallback || 'Operazione non riuscita.';
    }

    function toInt(value, fallback) {
        var parsed = parseInt(value, 10);
        if (isNaN(parsed)) {
            return fallback;
        }
        return parsed;
    }

    function GameLocationWhispersPage(extension) {
        var page = {
            location_id: 0,
            character_id: 0,
            target_id: 0,
            whispersModule: null,
            unreadPolling: null,
            hubThreads: [],
            hubFilteredThreads: [],
            hubActiveThreadId: 0,
            hubPaginator: null,
            hubLoading: false,
            elements: {},

            init: function () {
                this.location_id = toInt($('[name="location_id"]').val(), 0);
                this.character_id = toInt($('[name="character_id"]').val(), 0);
                this.cache();
                if (!this.location_id || (!this.elements.hubOpen.length && !this.elements.hubModal.length)) {
                    return this;
                }
                this.bind();
                this.refreshUnread();
                this.startUnreadPolling();
                return this;
            },

            cache: function () {
                this.elements = {
                    target: $('[name="whisper_target"]'),
                    targetId: $('[name="whisper_target_id"]'),
                    suggestions: $('.whisper-suggest'),
                    list: $('#whisper_messages'),
                    body: $('[name="whisper_body"]'),
                    send: $('[data-whisper-send]'),
                    unreadBadge: $('[data-role="whispers-unread-badge"]'),
                    hubOpen: $('[data-whispers-hub-open]'),
                    hubModal: $('#whispers-hub-modal'),
                    hubFilter: $('[data-whispers-hub-filter]'),
                    hubThreads: $('#whispers-hub-threads'),
                    hubPagination: $('#whispers-hub-pagination'),
                    hubMessages: $('#whispers-hub-messages'),
                    hubTitle: $('[data-whispers-hub-thread-title]'),
                    hubBody: $('[name="whispers_hub_body"]'),
                    hubSend: $('[data-whispers-hub-send]'),
                    hubNewSearch: $('#whispers-hub-new-search'),
                    hubNewSuggestions: $('#whispers-hub-new-suggestions')
                };
            },

            bind: function () {
                var self = this;

                this.elements.target.on('keyup', function () {
                    var value = $(this).val();
                    if (!value || value.length < 2) {
                        self.elements.suggestions.addClass('d-none').empty();
                        return;
                    }
                    self.call('searchTargets', { query: value, location_id: self.location_id }, function (response) {
                        self.renderSuggestions(response ? response.dataset : []);
                    }, function () {
                        self.renderSuggestions([]);
                    });
                });

                this.elements.body.on('keydown', function (event) {
                    if (event.key === 'Enter' && !event.shiftKey) {
                        event.preventDefault();
                        self.send(self.elements.body, false);
                    }
                });
                this.elements.send.on('click', function () {
                    self.send(self.elements.body, false);
                });

                this.elements.hubOpen.on('click', function () {
                    self.openHub();
                });
                this.elements.hubFilter.on('input', function () {
                    self.applyHubFilter();
                });
                this.elements.hubNewSearch.on('input', function () {
                    var value = $(this).val();
                    if (!value || value.length < 2) {
                        self.elements.hubNewSuggestions.addClass('d-none').empty();
                        return;
                    }
                    self.call('searchTargets', { query: value, location_id: self.location_id }, function (response) {
                        self.renderHubNewSuggestions(response ? response.dataset : []);
                    }, function () {
                        self.renderHubNewSuggestions([]);
                    });
                });
                this.elements.hubBody.on('keydown', function (event) {
                    if (event.key === 'Enter' && !event.shiftKey) {
                        event.preventDefault();
                        self.send(self.elements.hubBody, true);
                    }
                });
                this.elements.hubSend.on('click', function () {
                    self.send(self.elements.hubBody, true);
                });
                this.elements.hubModal.on('shown.bs.modal', function () {
                    self.loadHubThreads(false);
                });

                $(window).off('beforeunload.locationWhispers').on('beforeunload.locationWhispers', function () {
                    self.stopUnreadPolling();
                });
            },

            getModule: function () {
                if (this.whispersModule) {
                    return this.whispersModule;
                }
                this.whispersModule = resolveModule('game.location.whispers');
                return this.whispersModule;
            },

            call: function (method, payload, onSuccess, onError) {
                var mod = this.getModule();
                if (!mod || typeof mod[method] !== 'function') {
                    if (typeof onError === 'function') {
                        onError(new Error('Whispers module not available: ' + method));
                    }
                    return false;
                }
                mod[method](payload || {}).then(function (response) {
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

            isHubOpen: function () {
                return !!(this.elements.hubModal && this.elements.hubModal.length && this.elements.hubModal.hasClass('show'));
            },

            showHubModal: function () {
                var node = document.querySelector('#whispers-hub-modal');
                if (!node) {
                    return;
                }
                if (window.bootstrap && window.bootstrap.Modal) {
                    window.bootstrap.Modal.getOrCreateInstance(node).show();
                } else if (typeof $ === 'function') {
                    $(node).modal('show');
                }
            },

            startUnreadPolling: function () {
                var self = this;
                this.stopUnreadPolling();
                this.unreadPolling = setInterval(function () {
                    if (self.isHubOpen()) {
                        self.loadHubThreads(true);
                    } else {
                        self.refreshUnread();
                    }
                }, 5000);
            },

            stopUnreadPolling: function () {
                if (this.unreadPolling) {
                    clearInterval(this.unreadPolling);
                    this.unreadPolling = null;
                }
            },

            setUnreadBadge: function (value) {
                var unread = toInt(value, 0);
                if (unread < 0) {
                    unread = 0;
                }
                if (unread > 0 && window.AppSounds && typeof window.AppSounds.play === 'function') {
                    window.AppSounds.play('whispers');
                }
                this.elements.unreadBadge.text(unread);
                this.elements.unreadBadge.toggleClass('d-none', unread <= 0);
            },

            refreshUnread: function (recipientId) {
                var self = this;
                var payload = { location_id: this.location_id };
                var parsed = toInt(recipientId, 0);
                if (parsed > 0) {
                    payload.recipient_id = parsed;
                }
                this.call('unread', payload, function (response) {
                    var dataset = response && response.dataset ? response.dataset : {};
                    self.setUnreadBadge(dataset.total_unread || 0);
                }, function () {
                    self.setUnreadBadge(0);
                });
            },

            pollActiveAsideThread: function () {
                var self = this;
                var recipientId = toInt(this.target_id, 0);
                if (!recipientId) {
                    this.refreshUnread();
                    return;
                }

                this.call('list', {
                    location_id: this.location_id,
                    recipient_id: recipientId
                }, function (response) {
                    var dataset = response && response.dataset ? response.dataset : [];
                    self.renderMessages(dataset, self.elements.list, 'Nessun sussurro.');
                    if (response && response.unread) {
                        self.setUnreadBadge(response.unread.total_unread || 0);
                        self.setThreadUnread(recipientId, response.unread.thread_unread || 0);
                    } else {
                        self.refreshUnread(recipientId);
                    }
                    self.updateThreadPreview(recipientId, dataset);
                }, function () {
                    self.refreshUnread(recipientId);
                });
            },

            renderSuggestions: function (dataset) {
                var self = this;
                this.elements.suggestions.empty();
                if (!dataset || !dataset.length) {
                    this.elements.suggestions.addClass('d-none');
                    return;
                }
                for (var i = 0; i < dataset.length; i++) {
                    var row = dataset[i];
                    var label = ((row.name || '') + ' ' + (row.surname || '')).trim();
                    var item = $('<button type="button" class="list-group-item list-group-item-action"></button>');
                    item.text(label);
                    item.on('click', (function (selected) {
                        return function () {
                            self.selectTarget(selected);
                        };
                    })(row));
                    this.elements.suggestions.append(item);
                }
                this.elements.suggestions.removeClass('d-none');
            },

            renderHubNewSuggestions: function (dataset) {
                var self = this;
                this.elements.hubNewSuggestions.empty();
                if (!dataset || !dataset.length) {
                    this.elements.hubNewSuggestions.addClass('d-none');
                    return;
                }
                for (var i = 0; i < dataset.length; i++) {
                    var row = dataset[i];
                    var label = ((row.name || '') + ' ' + (row.surname || '')).trim();
                    var item = $('<button type="button" class="list-group-item list-group-item-action small"></button>');
                    item.text(label);
                    item.on('click', (function (selected) {
                        return function () {
                            self.elements.hubNewSearch.val('');
                            self.elements.hubNewSuggestions.addClass('d-none').empty();
                            self.selectHubThread(selected);
                        };
                    })(row));
                    this.elements.hubNewSuggestions.append(item);
                }
                this.elements.hubNewSuggestions.removeClass('d-none');
            },

            selectHubThread: function (row) {
                var recipientId = toInt(row ? row.id : 0, 0);
                if (!recipientId) {
                    return;
                }
                this.target_id = recipientId;
                this.elements.targetId.val(recipientId);
                var name = ((row.name || '') + ' ' + (row.surname || '')).trim();
                this.elements.target.val(name);
                this.hubActiveThreadId = recipientId;
                this.elements.hubTitle.text('Conversazione con ' + (name || ('Personaggio #' + recipientId)));
                this.loadThread(recipientId, true, true);
            },

            selectTarget: function (row) {
                var recipientId = toInt(row ? row.id : 0, 0);
                if (!recipientId) {
                    return;
                }
                this.target_id = recipientId;
                this.elements.targetId.val(recipientId);
                this.elements.target.val(((row.name || '') + ' ' + (row.surname || '')).trim()).blur();
                this.elements.suggestions.addClass('d-none').empty();
                this.loadThread(recipientId, true, this.isHubOpen());
            },

            load: function () {
                if (!this.target_id) {
                    return;
                }
                this.loadThread(this.target_id, true, this.isHubOpen());
            },

            loadThread: function (recipientId, renderSidebar, renderHub) {
                var self = this;
                var parsed = toInt(recipientId, 0);
                if (!parsed) {
                    return;
                }
                this.target_id = parsed;
                this.elements.targetId.val(parsed);
                this.call('list', { location_id: this.location_id, recipient_id: parsed }, function (response) {
                    var dataset = response && response.dataset ? response.dataset : [];
                    if (renderSidebar) {
                        self.renderMessages(dataset, self.elements.list, 'Nessun sussurro.');
                    }
                    if (renderHub) {
                        self.renderMessages(dataset, self.elements.hubMessages, 'Nessun sussurro in questa conversazione.');
                    }
                    if (response && response.unread) {
                        self.setUnreadBadge(response.unread.total_unread || 0);
                        self.setThreadUnread(parsed, response.unread.thread_unread || 0);
                    } else {
                        self.refreshUnread(parsed);
                    }
                    self.updateThreadPreview(parsed, dataset);
                }, function () {
                    if (renderSidebar) {
                        self.renderMessages([], self.elements.list, 'Nessun sussurro.');
                    }
                    if (renderHub) {
                        self.renderMessages([], self.elements.hubMessages, 'Nessun sussurro in questa conversazione.');
                    }
                    self.refreshUnread(parsed);
                });
            },

            renderMessages: function (dataset, container, emptyText) {
                container.empty();
                if (!dataset || !dataset.length) {
                    container.append('<div class="small text-muted">' + emptyText + '</div>');
                    this.scrollContainerToBottom(container);
                    return;
                }
                for (var i = 0; i < dataset.length; i++) {
                    var row = dataset[i];
                    var isMe = String(row.character_id) === String(this.character_id);
                    var name = isMe ? 'Tu' : (row.character_name || 'Personaggio');
                    var time = row.date_created ? String(row.date_created).substr(11, 5) : '';
                    var body = row.body_rendered || row.body || '';
                    var item = $('<div class="list-group-item bg-transparent border-0 px-0"></div>');
                    var rowId = toInt(row.id, 0);
                    if (rowId > 0) {
                        item.attr('data-whisper-id', rowId);
                    }
                    item.html('<div class="small"><b>' + name + '</b> <span class="text-muted">' + time + '</span></div><div>' + body + '</div>');
                    container.append(item);
                }
                this.scrollContainerToBottom(container);
            },

            scrollContainerToBottom: function (container) {
                if (!container || !container.length) {
                    return;
                }
                var node = container.get(0);
                if (!node) {
                    return;
                }
                node.scrollTop = node.scrollHeight;
            },

            appendMessageRow: function (container, row) {
                if (!container || !container.length || !row) {
                    return;
                }
                var rowId = toInt(row.id, 0);
                if (rowId > 0 && container.find('[data-whisper-id="' + rowId + '"]').length > 0) {
                    return;
                }

                var isMe = String(row.character_id) === String(this.character_id);
                var name = isMe ? 'Tu' : (row.character_name || 'Personaggio');
                var time = row.date_created ? String(row.date_created).substr(11, 5) : '';
                var body = row.body_rendered || row.body || '';
                var item = $('<div class="list-group-item bg-transparent border-0 px-0"></div>');
                if (rowId > 0) {
                    item.attr('data-whisper-id', rowId);
                }
                item.html('<div class="small"><b>' + name + '</b> <span class="text-muted">' + time + '</span></div><div>' + body + '</div>');

                var emptyPlaceholder = container.children('.small.text-muted').first();
                if (emptyPlaceholder.length) {
                    emptyPlaceholder.remove();
                }

                container.append(item);
                this.scrollContainerToBottom(container);
            },

            appendMessage: function (row) {
                if (row && this.target_id && (String(row.character_id) === String(this.target_id) || String(row.recipient_id) === String(this.target_id))) {
                    this.loadThread(this.target_id, true, this.isHubOpen());
                    return;
                }
                this.refreshUnread();
                if (this.isHubOpen()) {
                    this.loadHubThreads(true);
                }
            },

            normalizePolicy: function (value) {
                var policy = String(value || '').toLowerCase().trim();
                if (policy === 'mute') {
                    return 'mute';
                }
                if (policy === 'block') {
                    return 'block';
                }
                return 'allow';
            },

            getThreadPolicy: function (recipientId) {
                var row = this.findThread(recipientId);
                if (!row) {
                    return 'allow';
                }
                return this.normalizePolicy(row.policy);
            },

            setThreadPolicy: function (recipientId, policy) {
                var self = this;
                var parsedRecipientId = toInt(recipientId, 0);
                var normalizedPolicy = this.normalizePolicy(policy);
                if (!parsedRecipientId) {
                    return;
                }

                this.call('setPolicy', {
                    location_id: this.location_id,
                    recipient_id: parsedRecipientId,
                    policy: normalizedPolicy
                }, function (response) {
                    var dataset = response && response.dataset ? response.dataset : {};
                    var row = self.findThread(parsedRecipientId);
                    if (row) {
                        row.policy = self.normalizePolicy(dataset.policy || normalizedPolicy);
                        if (row.policy !== 'allow') {
                            row.unread_count = 0;
                        }
                    }
                    self.setUnreadBadge(dataset.total_unread || 0);
                    self.refreshHubPaginator();

                    if (row && row.policy === 'block' && String(self.target_id) === String(parsedRecipientId)) {
                        self.elements.hubBody.val('');
                    }

                    var message = 'Policy aggiornata.';
                    if (row && row.policy === 'mute') {
                        message = 'Conversazione silenziata.';
                    } else if (row && row.policy === 'block') {
                        message = 'Conversazione bloccata.';
                    } else if (row && row.policy === 'allow') {
                        message = 'Conversazione riattivata.';
                    }
                    Toast.show({
                        body: message,
                        type: 'success'
                    });
                }, function (error) {
                    Toast.show({
                        body: normalizeWhisperError(error, 'Aggiornamento policy sussurro non riuscito.'),
                        type: 'error'
                    });
                });
            },

            send: function (input, fromHub) {
                if (!this.target_id) {
                    Dialog('warning', { title: 'Sussurri', body: '<p>Seleziona un destinatario.</p>' }).show();
                    return;
                }
                if (this.getThreadPolicy(this.target_id) === 'block') {
                    Toast.show({
                        body: 'Conversazione bloccata. Sblocca il thread per inviare nuovi sussurri.',
                        type: 'warning'
                    });
                    return;
                }
                var text = input.val();
                if (!text || text.trim() === '') {
                    return;
                }
                var self = this;
                this.call('send', {
                    location_id: this.location_id,
                    recipient_id: this.target_id,
                    body: text
                }, function (response) {
                    input.val('');
                    if (response && response.dataset) {
                        self.touchThreadFromMessage(response.dataset);
                        if (!fromHub && !self.isHubOpen()) {
                            self.appendMessageRow(self.elements.list, response.dataset);
                        }
                    }
                    if (fromHub || self.isHubOpen()) {
                        self.loadThread(self.target_id, true, self.isHubOpen());
                    }
                    if (fromHub && self.isHubOpen()) {
                        self.loadHubThreads(true);
                    }
                }, function (error) {
                    Toast.show({ body: normalizeWhisperError(error, 'Invio sussurro non riuscito.'), type: 'error' });
                });
            },

            openHub: function () {
                this.showHubModal();
                if (this.isHubOpen()) {
                    this.loadHubThreads(false);
                }
            },

            loadHubThreads: function (background) {
                if (this.hubLoading) {
                    return;
                }
                var self = this;
                this.hubLoading = true;
                this.call('threads', { location_id: this.location_id, limit: 200 }, function (response) {
                    self.hubLoading = false;
                    self.hubThreads = response && response.dataset ? response.dataset : [];
                    for (var hi = 0; hi < self.hubThreads.length; hi++) {
                        self.hubThreads[hi].policy = self.normalizePolicy(self.hubThreads[hi].policy);
                    }
                    self.hubThreads.sort(function (a, b) {
                        return toInt(b.last_message_id, 0) - toInt(a.last_message_id, 0);
                    });
                    if (response && response.unread) {
                        self.setUnreadBadge(response.unread.total_unread || 0);
                    }
                    if (!self.hubActiveThreadId && self.hubThreads.length) {
                        self.hubActiveThreadId = toInt(self.hubThreads[0].recipient_id, 0);
                    }
                    if (self.target_id) {
                        self.hubActiveThreadId = toInt(self.target_id, self.hubActiveThreadId);
                    }
                    self.applyHubFilter();
                }, function (error) {
                    self.hubLoading = false;
                    if (!background) {
                        Toast.show({ body: normalizeWhisperError(error, 'Caricamento hub sussurri non riuscito.'), type: 'error' });
                    }
                });
            },

            applyHubFilter: function () {
                var term = String(this.elements.hubFilter.val() || '').toLowerCase().trim();
                this.hubFilteredThreads = [];
                for (var i = 0; i < this.hubThreads.length; i++) {
                    var row = this.hubThreads[i];
                    var name = ((row.character_name || '') + ' ' + (row.character_surname || '')).toLowerCase();
                    if (!term || name.indexOf(term) !== -1) {
                        this.hubFilteredThreads.push(row);
                    }
                }
                if (!this.hubFilteredThreads.length) {
                    this.hubActiveThreadId = 0;
                    this.elements.hubTitle.text('Seleziona una conversazione');
                    this.renderMessages([], this.elements.hubMessages, 'Nessuna conversazione disponibile.');
                } else if (!this.findThread(this.hubActiveThreadId, this.hubFilteredThreads)) {
                    this.hubActiveThreadId = toInt(this.hubFilteredThreads[0].recipient_id, 0);
                }

                var active = this.findThread(this.hubActiveThreadId, this.hubThreads);
                if (active) {
                    var activeName = ((active.character_name || '') + ' ' + (active.character_surname || '')).trim() || ('Personaggio #' + this.hubActiveThreadId);
                    this.target_id = this.hubActiveThreadId;
                    this.elements.targetId.val(this.hubActiveThreadId);
                    this.elements.target.val(activeName);
                    this.elements.hubTitle.text('Conversazione con ' + activeName);
                }

                this.ensureHubPaginator();
                this.refreshHubPaginator();
                if (this.hubActiveThreadId) {
                    this.loadThread(this.hubActiveThreadId, false, true);
                }
            },

            ensureHubPaginator: function () {
                if (this.hubPaginator || typeof Paginator !== 'function') {
                    return;
                }
                var self = this;
                var p = new Paginator();
                p.urlupdate = false;
                p.range = 2;
                p.div = '#whispers-hub-pagination';
                p.onDatasetUpdate = function () {
                    self.renderThreadList(this.dataset || []);
                };
                p.loadByCriteria = function (criteria) {
                    var normalized = this.normalizeCriteria(criteria, this.nav);
                    var results = toInt(normalized.results, 6);
                    var page = toInt(normalized.page, 1);
                    var rows = self.hubFilteredThreads || [];
                    var totalPages = rows.length ? Math.ceil(rows.length / results) : 1;
                    if (page > totalPages) {
                        page = totalPages;
                    }
                    if (page < 1) {
                        page = 1;
                    }
                    var start = (page - 1) * results;
                    this.complete({
                        properties: {
                            query: normalized.query || {},
                            page: page,
                            results: results,
                            orderBy: normalized.orderBy || '',
                            tot: { count: rows.length }
                        },
                        dataset: rows.slice(start, start + results)
                    });
                    self.elements.hubPagination.toggleClass('d-none', rows.length <= results);
                    return this;
                };
                p.setNav({ query: {}, orderBy: '', page: 1, results: 6, tot: { count: 0 } });
                this.hubPaginator = p;
            },

            refreshHubPaginator: function () {
                if (this.hubPaginator && typeof this.hubPaginator.loadByCriteria === 'function') {
                    var nav = this.hubPaginator.nav || {};
                    this.hubPaginator.loadByCriteria({
                        query: nav.query || {},
                        page: nav.page || 1,
                        results: nav.results || 6,
                        orderBy: nav.orderBy || ''
                    });
                    return;
                }
                this.renderThreadList(this.hubFilteredThreads || []);
                this.elements.hubPagination.addClass('d-none').empty();
            },

            renderThreadList: function (rows) {
                var self = this;
                this.elements.hubThreads.empty();
                if (!rows || !rows.length) {
                    this.elements.hubThreads.append('<div class="small text-muted py-2">Nessuna conversazione.</div>');
                    return;
                }
                for (var i = 0; i < rows.length; i++) {
                    var row = rows[i];
                    var recipientId = toInt(row.recipient_id, 0);
                    var name = ((row.character_name || '') + ' ' + (row.character_surname || '')).trim() || ('Personaggio #' + recipientId);
                    var preview = String(row.last_message_body || '').replace(/\\s+/g, ' ').trim();
                    if (preview.length > 72) {
                        preview = preview.substring(0, 72) + '...';
                    }
                    if (preview === '') {
                        preview = 'Nessun messaggio';
                    }
                    var time = row.last_message_date ? String(row.last_message_date).substr(11, 5) : '';
                    var unread = toInt(row.unread_count, 0);
                    var policy = self.normalizePolicy(row.policy);

                    var item = $('<button type="button" class="list-group-item list-group-item-action text-start"></button>');
                    item.attr('data-recipient-id', recipientId);
                    item.toggleClass('active', String(recipientId) === String(self.hubActiveThreadId));
                    var top = $('<div class="d-flex justify-content-between align-items-center"></div>');
                    top.append($('<strong></strong>').text(name));
                    top.append($('<span class="small text-muted"></span>').text(time));
                    item.append(top);
                    var bottom = $('<div class="d-flex justify-content-between align-items-center gap-2 mt-1"></div>');
                    bottom.append($('<span class="small text-muted"></span>').text(preview));
                    if (unread > 0) {
                        bottom.append($('<span class="badge text-bg-danger"></span>').text(unread));
                    }
                    item.append(bottom);

                    var actions = $('<div class="d-flex justify-content-between align-items-center mt-2"></div>');
                    var policyBadge = $('<span class="badge text-bg-secondary">Attiva</span>');
                    if (policy === 'mute') {
                        policyBadge.removeClass('text-bg-secondary').addClass('text-bg-warning').text('Silenziata');
                    } else if (policy === 'block') {
                        policyBadge.removeClass('text-bg-secondary').addClass('text-bg-danger').text('Bloccata');
                    }
                    actions.append(policyBadge);

                    var policyButtons = $('<div class="btn-group btn-group-sm" role="group" aria-label="Policy sussurri"></div>');
                    var allowBtn = $('<button type="button" class="btn" title="Consenti"><i class="bi bi-bell"></i></button>');
                    var muteBtn = $('<button type="button" class="btn" title="Silenzia"><i class="bi bi-bell-slash"></i></button>');
                    var blockBtn = $('<button type="button" class="btn" title="Blocca"><i class="bi bi-slash-circle"></i></button>');

                    allowBtn.addClass(policy === 'allow' ? 'btn-success' : 'btn-outline-secondary');
                    muteBtn.addClass(policy === 'mute' ? 'btn-warning' : 'btn-outline-secondary');
                    blockBtn.addClass(policy === 'block' ? 'btn-danger' : 'btn-outline-secondary');

                    allowBtn.on('click', (function (id) {
                        return function (event) {
                            event.preventDefault();
                            event.stopPropagation();
                            self.setThreadPolicy(id, 'allow');
                        };
                    })(recipientId));
                    muteBtn.on('click', (function (id) {
                        return function (event) {
                            event.preventDefault();
                            event.stopPropagation();
                            self.setThreadPolicy(id, 'mute');
                        };
                    })(recipientId));
                    blockBtn.on('click', (function (id) {
                        return function (event) {
                            event.preventDefault();
                            event.stopPropagation();
                            self.setThreadPolicy(id, 'block');
                        };
                    })(recipientId));

                    policyButtons.append(allowBtn).append(muteBtn).append(blockBtn);
                    actions.append(policyButtons);
                    item.append(actions);

                    item.on('click', (function (id, threadName) {
                        return function () {
                            self.hubActiveThreadId = id;
                            self.target_id = id;
                            self.elements.targetId.val(id);
                            self.elements.target.val(threadName);
                            self.elements.hubTitle.text('Conversazione con ' + threadName);
                            self.refreshHubPaginator();
                            self.loadThread(id, true, true);
                        };
                    })(recipientId, name));
                    this.elements.hubThreads.append(item);
                }
            },

            findThread: function (recipientId, dataset) {
                var list = dataset || this.hubThreads;
                for (var i = 0; i < list.length; i++) {
                    if (String(list[i].recipient_id) === String(recipientId)) {
                        return list[i];
                    }
                }
                return null;
            },

            setThreadUnread: function (recipientId, unread) {
                var row = this.findThread(recipientId);
                if (!row) {
                    return;
                }
                row.unread_count = toInt(unread, 0);
                if (this.isHubOpen()) {
                    this.refreshHubPaginator();
                }
            },

            updateThreadPreview: function (recipientId, dataset) {
                if (!dataset || !dataset.length) {
                    return;
                }
                var row = this.findThread(recipientId);
                if (!row) {
                    return;
                }
                var last = dataset[dataset.length - 1];
                row.last_message_id = toInt(last.id, row.last_message_id || 0);
                row.last_message_body = String(last.body || '');
                row.last_message_date = String(last.date_created || row.last_message_date || '');
                if (this.isHubOpen()) {
                    this.hubThreads.sort(function (a, b) {
                        return toInt(b.last_message_id, 0) - toInt(a.last_message_id, 0);
                    });
                    this.hubFilteredThreads.sort(function (a, b) {
                        return toInt(b.last_message_id, 0) - toInt(a.last_message_id, 0);
                    });
                    this.refreshHubPaginator();
                }
            },

            touchThreadFromMessage: function (message) {
                var recipientId = toInt(message ? message.recipient_id : 0, 0);
                if (!recipientId) {
                    return;
                }
                var row = this.findThread(recipientId);
                if (!row) {
                    row = {
                        recipient_id: recipientId,
                        character_name: String(this.elements.target.val() || '').trim(),
                        character_surname: '',
                        unread_count: 0,
                        policy: 'allow'
                    };
                    this.hubThreads.push(row);
                }
                row.last_message_id = toInt(message.id, row.last_message_id || 0);
                row.last_message_body = String(message.body || '');
                row.last_message_date = String(message.date_created || row.last_message_date || '');
                row.unread_count = 0;
            }
        };

        var whispers = Object.assign({}, page, extension);
        return whispers.init();
    }

    window.GameLocationWhispersPage = GameLocationWhispersPage;
})(window);
