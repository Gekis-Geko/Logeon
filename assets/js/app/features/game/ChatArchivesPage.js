const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function escapeHtml(value) {
    return String(value == null ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function toInt(value, fallback) {
    var parsed = parseInt(String(value == null ? '' : value), 10);
    return isFinite(parsed) ? parsed : (parseInt(String(fallback == null ? '0' : fallback), 10) || 0);
}

function showToast(type, body) {
    if (globalWindow.Toast && typeof globalWindow.Toast.show === 'function') {
        globalWindow.Toast.show({ type: type, body: body });
    }
}

function httpPost(url, payload) {
    if (!globalWindow.Request || !globalWindow.Request.http || typeof globalWindow.Request.http.post !== 'function') {
        return Promise.reject(new Error('Request non disponibile'));
    }
    return globalWindow.Request.http.post(url, payload);
}

function showError(error, fallback) {
    var msg = fallback || 'Operazione non riuscita.';
    if (globalWindow.GameFeatureError && typeof globalWindow.GameFeatureError.toastMapped === 'function') {
        globalWindow.GameFeatureError.toastMapped(error, msg, {});
        return;
    }
    if (globalWindow.Request && typeof globalWindow.Request.getErrorMessage === 'function') {
        msg = globalWindow.Request.getErrorMessage(error, msg);
    }
    showToast('error', msg);
}

function bootstrapModal(id) {
    var el = document.getElementById(id);
    if (!el) { return null; }
    if (globalWindow.bootstrap && globalWindow.bootstrap.Modal) {
        return globalWindow.bootstrap.Modal.getOrCreateInstance(el);
    }
    return null;
}

function formatDatetime(value) {
    if (!value) { return ''; }
    var d = new Date(String(value).replace(' ', 'T'));
    if (isNaN(d.getTime())) { return String(value); }
    return d.toLocaleDateString('it-IT', { day: '2-digit', month: '2-digit', year: 'numeric' })
        + ' ' + d.toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' });
}

function completenessLabel(level) {
    return level === 'partial'
        ? '<span class="badge bg-warning text-dark">Parziale</span>'
        : '<span class="badge bg-success">Completo</span>';
}

function parseArchiveMetadata(archive) {
    if (archive && archive.metadata && typeof archive.metadata === 'object') {
        return archive.metadata;
    }

    if (!archive || typeof archive.metadata_json !== 'string' || archive.metadata_json.trim() === '') {
        return {};
    }

    try {
        var parsed = JSON.parse(archive.metadata_json);
        return parsed && typeof parsed === 'object' ? parsed : {};
    } catch (error) {
        return {};
    }
}

function participantNames(list) {
    if (!Array.isArray(list)) {
        return [];
    }

    var names = [];
    for (var i = 0; i < list.length; i += 1) {
        var row = list[i] || {};
        var name = String(row.name || '').trim();
        if (name) {
            names.push(name);
        }
    }

    return names;
}

function formatDiaryEventLabel(row) {
    if (!row || typeof row !== 'object') {
        return '';
    }

    var title = String(row.title || '').trim();
    var parts = [];

    if (row.date_event) {
        parts.push(formatDatetime(row.date_event));
    } else if (row.date_created) {
        parts.push(formatDatetime(row.date_created));
    }

    if (row.location_name) {
        parts.push(String(row.location_name));
    }

    return title + (parts.length ? (' - ' + parts.join(' - ')) : '');
}

function formatDiaryEventMeta(row) {
    if (!row || typeof row !== 'object') {
        return '';
    }

    var parts = [];
    if (row.date_event) {
        parts.push(formatDatetime(row.date_event));
    } else if (row.date_created) {
        parts.push(formatDatetime(row.date_created));
    }
    if (row.location_name) {
        parts.push(String(row.location_name));
    }

    return parts.join(' - ');
}

function GameChatArchivesPage(extension) {
    var page = {
        root: null,
        busy: false,
        currentViewId: null,
        currentEditArchiveId: null,
        publicToggleSwitch: null,
        publicToggleSync: false,
        diarySearchTimer: null,
        autoOpenArchiveId: 0,
        autoOpenArchiveDone: false,
        autoOpenDiaryEventId: 0,

        init: function () {
            this.root = document.getElementById('chat-archives-page');
            if (!this.root) { return this; }
            this.autoOpenArchiveId = this.readArchiveQueryParam();
            this.autoOpenDiaryEventId = this.readDiaryEventQueryParam();
            this.initPublicToggleSwitch();
            this.bind();
            this.loadList();
            return this;
        },

        readArchiveQueryParam: function () {
            try {
                var params = new URLSearchParams(globalWindow.location.search || '');
                return toInt(params.get('archive'), 0);
            } catch (error) {
                return 0;
            }
        },

        readDiaryEventQueryParam: function () {
            try {
                var params = new URLSearchParams(globalWindow.location.search || '');
                return toInt(params.get('diary_event'), 0);
            } catch (error) {
                return 0;
            }
        },

        initPublicToggleSwitch: function () {
            var input = document.getElementById('ca-view-public-toggle');
            if (!input) {
                return this;
            }

            if (this.publicToggleSwitch && typeof this.publicToggleSwitch.destroy === 'function') {
                this.publicToggleSwitch.destroy();
            }

            if (typeof globalWindow.SwitchGroup === 'function') {
                this.publicToggleSwitch = globalWindow.SwitchGroup(input, {
                    trueLabel: 'Pubblico',
                    falseLabel: 'Privato',
                    trueValue: '1',
                    falseValue: '0',
                    defaultValue: '0'
                });
            }

            return this;
        },

        setPublicToggleValue: function (enabled, silent) {
            var input = document.getElementById('ca-view-public-toggle');
            var value = toInt(enabled, 0) === 1 ? '1' : '0';
            if (!input) {
                return this;
            }

            this.publicToggleSync = (silent === true);

            if (this.publicToggleSwitch && typeof this.publicToggleSwitch.setValue === 'function') {
                this.publicToggleSwitch.setValue(value);
            } else {
                input.value = value;
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }

            this.publicToggleSync = false;
            return this;
        },

        bind: function () {
            var self = this;
            document.addEventListener('click', function (event) {
                var trigger = event.target && event.target.closest
                    ? event.target.closest('[data-action]')
                    : null;
                if (!trigger) { return; }
                var action = trigger.getAttribute('data-action');

                if (action === 'ca-view-open') {
                    event.preventDefault();
                    var id = toInt(trigger.getAttribute('data-archive-id'), 0);
                    if (id > 0) { self.openViewModal(id); }
                    return;
                }
                if (action === 'ca-edit-open') {
                    event.preventDefault();
                    var editId = toInt(trigger.getAttribute('data-archive-id'), 0);
                    if (editId > 0) { self.openEditModal(editId); }
                    return;
                }
                if (action === 'chat-archives-edit-save') {
                    event.preventDefault();
                    self.submitEdit();
                    return;
                }
                if (action === 'ca-delete') {
                    event.preventDefault();
                    var delId = toInt(trigger.getAttribute('data-archive-id'), 0);
                    if (delId > 0) {
                        var dlg = Dialog('warning', {
                            title: 'Conferma eliminazione',
                            body: '<p>Vuoi eliminare definitivamente questo archivio? L\'operazione è irreversibile.</p>',
                            confirmLabel: 'Elimina'
                        }, function () {
                            dlg.hide();
                            self.deleteArchive(delId);
                        });
                        dlg.show();
                    }
                    return;
                }
                if (action === 'ca-copy-public-url') {
                    event.preventDefault();
                    self.copyPublicUrl();
                    return;
                }
                if (action === 'ca-edit-diary-select') {
                    event.preventDefault();
                    self.selectDiarySuggestion(trigger);
                    return;
                }
                if (action === 'ca-edit-diary-clear') {
                    event.preventDefault();
                    self.clearDiarySelection(true);
                }
            });

            document.addEventListener('change', function (event) {
                var target = event.target;
                if (!target || target.id !== 'ca-view-public-toggle') {
                    return;
                }
                if (self.publicToggleSync) {
                    return;
                }
                self.togglePublicFromInput(target);
            });

            document.addEventListener('input', function (event) {
                var target = event.target;
                if (!target || target.id !== 'ca-edit-diary-event-search') {
                    return;
                }
                self.handleDiarySearchInput(target.value);
            });

            document.addEventListener('click', function (event) {
                var target = event.target;
                var searchInput = document.getElementById('ca-edit-diary-event-search');
                var suggestions = document.getElementById('ca-edit-diary-event-suggestions');
                if (!suggestions || suggestions.classList.contains('d-none')) {
                    return;
                }
                if (target === searchInput) {
                    return;
                }
                if (target && target.closest && target.closest('#ca-edit-diary-event-suggestions')) {
                    return;
                }
                self.hideDiarySuggestions();
            });
        },

        loadList: function () {
            var self = this;
            var loading = document.getElementById('chat-archives-loading');
            var empty = document.getElementById('chat-archives-empty');
            var list = document.getElementById('chat-archives-list');

            if (loading) { loading.classList.remove('d-none'); }
            if (list) { list.innerHTML = ''; }
            if (empty) { empty.classList.add('d-none'); }

            httpPost('/chat-archives/list', {}).then(function (response) {
                if (loading) { loading.classList.add('d-none'); }
                var rows = response && Array.isArray(response.dataset) ? response.dataset : [];
                self.renderList(rows);
                self.tryAutoOpenArchive();
            }).catch(function (error) {
                if (loading) { loading.classList.add('d-none'); }
                showError(error, 'Errore nel caricamento degli archivi.');
            });
        },

        tryAutoOpenArchive: function () {
            if (this.autoOpenArchiveDone || this.autoOpenArchiveId <= 0) {
                return this;
            }

            this.autoOpenArchiveDone = true;
            this.openViewModal(this.autoOpenArchiveId, this.autoOpenDiaryEventId);
            return this;
        },

        renderList: function (rows) {
            var list = document.getElementById('chat-archives-list');
            var empty = document.getElementById('chat-archives-empty');
            if (!list) { return; }

            list.innerHTML = '';

            if (!rows.length) {
                if (empty) { empty.classList.remove('d-none'); }
                return;
            }

            var html = [];
            for (var i = 0; i < rows.length; i += 1) {
                var row = rows[i] || {};
                var metadata = parseArchiveMetadata(row);
                var id = toInt(row.id, 0);
                var title = String(row.title || ('Archivio #' + id));
                var location = String(row.location_name || '');
                var started = formatDatetime(row.started_at);
                var ended = formatDatetime(row.ended_at);
                var isPublic = toInt(row.public_enabled, 0) === 1;
                var msgCount = toInt(row.included_messages_count, 0);
                var totalMsg = toInt(row.total_messages_in_range, 0);
                var includedParticipants = toInt(row.included_participants_count, 0);
                var totalParticipants = toInt(row.total_participants_in_range, 0);
                var hasDiaryLink = toInt(row.diary_event_id, 0) > 0;
                var createdBy = String(metadata.created_by_name || '').trim();

                html.push(
                    '<div class="card" data-archive-id="' + id + '">'
                    + '<div class="card-body d-flex justify-content-between align-items-start gap-2 py-3 px-3">'
                    + '<div class="flex-grow-1">'
                    + '<div class="fw-semibold">' + escapeHtml(title) + '</div>'
                    + '<div class="text-muted small">'
                    + (location ? escapeHtml(location) + ' &mdash; ' : '')
                    + escapeHtml(started) + ' &rarr; ' + escapeHtml(ended)
                    + '</div>'
                    + '<div class="mt-2 d-flex flex-wrap gap-2 align-items-center">'
                    + completenessLabel(row.completeness_level)
                    + '<span class="text-muted small">Messaggi inclusi: ' + msgCount + ' / ' + totalMsg + '</span>'
                    + '<span class="text-muted small">Partecipanti inclusi: ' + includedParticipants + ' / ' + totalParticipants + '</span>'
                    + (isPublic ? '<span class="badge bg-info text-dark"><i class="bi bi-share me-1"></i>Pubblico</span>' : '')
                    + (hasDiaryLink ? '<span class="badge bg-secondary">Diario #' + toInt(row.diary_event_id, 0) + '</span>' : '')
                    + '</div>'
                    + (createdBy ? '<div class="small text-muted mt-2">Archivio creato da ' + escapeHtml(createdBy) + '.</div>' : '')
                    + '</div>'
                    + '<div class="d-flex gap-1 flex-shrink-0">'
                    + '<button type="button" class="btn btn-sm btn-outline-secondary" data-action="ca-view-open" data-archive-id="' + id + '" title="Visualizza"><i class="bi bi-eye"></i></button>'
                    + '<button type="button" class="btn btn-sm btn-outline-secondary" data-action="ca-edit-open" data-archive-id="' + id + '" title="Modifica"><i class="bi bi-pencil"></i></button>'
                    + '<button type="button" class="btn btn-sm btn-outline-danger" data-action="ca-delete" data-archive-id="' + id + '" title="Elimina"><i class="bi bi-trash"></i></button>'
                    + '</div>'
                    + '</div>'
                    + '</div>'
                );
            }

            list.innerHTML = html.join('');
        },

        updateViewTransparency: function (archive) {
            var metadata = parseArchiveMetadata(archive);
            var summary = document.getElementById('ca-view-summary');
            var excludedWrap = document.getElementById('ca-view-excluded-wrap');
            var excluded = document.getElementById('ca-view-excluded-participants');
            var createdBy = document.getElementById('ca-view-created-by');
            var excludedNames = participantNames(metadata.excluded_participants);
            var createdByName = String(metadata.created_by_name || '').trim();

            if (summary) {
                summary.innerHTML = [
                    completenessLabel(archive.completeness_level),
                    '<span>Messaggi inclusi: ' + toInt(archive.included_messages_count, 0) + ' / ' + toInt(archive.total_messages_in_range, 0) + '</span>',
                    '<span>Partecipanti inclusi: ' + toInt(archive.included_participants_count, 0) + ' / ' + toInt(archive.total_participants_in_range, 0) + '</span>'
                ].join('');
            }

            if (excludedWrap && excluded) {
                excluded.textContent = excludedNames.join(', ');
                excludedWrap.classList.toggle('d-none', excludedNames.length === 0);
            }

            if (createdBy) {
                createdBy.textContent = createdByName ? ('Archivio creato da ' + createdByName + '.') : '';
                createdBy.classList.toggle('d-none', !createdByName);
            }
        },

        renderMessages: function (messages, targetId, emptyText) {
            var target = document.getElementById(targetId);
            if (!target) { return; }

            if (!messages.length) {
                target.innerHTML = '<p class="text-muted small p-3">' + escapeHtml(emptyText) + '</p>';
                return;
            }

            var html = [];
            for (var i = 0; i < messages.length; i += 1) {
                var msg = messages[i] || {};
                html.push(
                    '<div class="p-2 border-bottom">'
                    + '<div class="small fw-semibold">'
                    + escapeHtml(String(msg.character_name_snapshot || ''))
                    + ' <span class="text-muted fw-normal">'
                    + escapeHtml(formatDatetime(msg.sent_at))
                    + '</span></div>'
                    + '<div class="small">' + String(msg.message_html || '') + '</div>'
                    + '</div>'
                );
            }

            target.innerHTML = html.join('');
        },

        openViewModal: function (id, diaryEventId) {
            var self = this;
            var titleEl = document.getElementById('ca-view-title');
            var metaEl = document.getElementById('ca-view-meta');
            var messagesEl = document.getElementById('ca-view-messages');
            var excludedWrap = document.getElementById('ca-view-excluded-wrap');
            var createdBy = document.getElementById('ca-view-created-by');

            if (titleEl) { titleEl.textContent = 'Caricamento...'; }
            if (metaEl) { metaEl.textContent = ''; }
            if (messagesEl) { messagesEl.innerHTML = ''; }
            if (excludedWrap) { excludedWrap.classList.add('d-none'); }
            if (createdBy) {
                createdBy.textContent = '';
                createdBy.classList.add('d-none');
            }

            var modal = bootstrapModal('chat-archives-view-modal');
            if (modal) { modal.show(); }

            var payload = { id: id };
            if (toInt(diaryEventId, 0) > 0) {
                payload.diary_event_id = toInt(diaryEventId, 0);
            }

            httpPost('/chat-archives/get', payload).then(function (response) {
                var data = response && response.dataset ? response.dataset : {};
                var archive = data.archive || {};
                var messages = Array.isArray(data.messages) ? data.messages : [];

                self.currentViewId = toInt(archive.id, 0);

                if (titleEl) { titleEl.textContent = String(archive.title || 'Archivio'); }
                if (metaEl) {
                    metaEl.innerHTML = [
                        archive.location_name ? '<span><i class="bi bi-geo-alt me-1"></i>' + escapeHtml(String(archive.location_name)) + '</span>' : '',
                        '<span><i class="bi bi-calendar me-1"></i>'
                            + escapeHtml(formatDatetime(archive.started_at))
                            + ' &rarr; '
                            + escapeHtml(formatDatetime(archive.ended_at))
                            + '</span>'
                    ].filter(Boolean).join('');
                }

                self.updateViewTransparency(archive);
                self.renderMessages(messages, 'ca-view-messages', 'Nessun messaggio in questo archivio.');
                self.setPublicToggleValue(toInt(archive.public_enabled, 0), true);
                self.updatePublicUrlDisplay(archive);
            }).catch(function (error) {
                showError(error, 'Errore nel caricamento dell\'archivio.');
            });
        },

        togglePublicFromInput: function (toggleInput) {
            var self = this;
            if (!this.currentViewId) { return; }
            var enabled = String(toggleInput && toggleInput.value || '0') === '1';

            httpPost('/chat-archives/public/set', {
                id: this.currentViewId,
                public_enabled: enabled ? 1 : 0
            }).then(function (response) {
                var data = response && response.dataset ? response.dataset : {};
                self.updatePublicUrlDisplay(data);
                self.loadList();
                showToast('success', enabled ? 'Archivio reso pubblico.' : 'Archivio reso privato.');
            }).catch(function (error) {
                self.setPublicToggleValue(enabled ? 0 : 1, true);
                showError(error, 'Errore nell\'aggiornamento della visibilita.');
            });
        },

        updatePublicUrlDisplay: function (archive) {
            var wrap = document.getElementById('ca-view-public-url-wrap');
            var urlInput = document.getElementById('ca-view-public-url');
            if (!wrap || !urlInput) { return; }

            var isPublic = toInt(archive.public_enabled, 0) === 1;
            var token = String(archive.public_token || '');
            if (isPublic && token) {
                urlInput.value = globalWindow.location.origin + '/shared/chat-archive/' + token;
                wrap.classList.remove('d-none');
            } else {
                wrap.classList.add('d-none');
                urlInput.value = '';
            }
        },

        copyPublicUrl: function () {
            var urlInput = document.getElementById('ca-view-public-url');
            if (!urlInput || !urlInput.value) { return; }

            if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                navigator.clipboard.writeText(urlInput.value).then(function () {
                    showToast('success', 'URL copiato negli appunti.');
                });
            } else {
                urlInput.select();
                document.execCommand('copy');
                showToast('success', 'URL copiato.');
            }
        },

        clearDiarySelection: function (clearInput) {
            var hiddenInput = document.getElementById('ca-edit-diary-event-id');
            var searchInput = document.getElementById('ca-edit-diary-event-search');
            var selected = document.getElementById('ca-edit-diary-event-selected');

            if (hiddenInput) {
                hiddenInput.value = '0';
            }

            if (clearInput === true && searchInput) {
                searchInput.value = '';
            }

            if (selected) {
                selected.textContent = '';
                selected.classList.add('d-none');
            }

            this.hideDiarySuggestions();
            return this;
        },

        setDiarySelection: function (row) {
            var hiddenInput = document.getElementById('ca-edit-diary-event-id');
            var searchInput = document.getElementById('ca-edit-diary-event-search');
            var selected = document.getElementById('ca-edit-diary-event-selected');
            var eventId = toInt(row && row.id, 0);
            var label = formatDiaryEventLabel(row);

            if (hiddenInput) {
                hiddenInput.value = eventId > 0 ? String(eventId) : '0';
            }

            if (searchInput) {
                searchInput.value = String(row && row.title || '');
            }

            if (selected) {
                selected.textContent = label || '';
                selected.classList.toggle('d-none', label === '');
            }

            this.hideDiarySuggestions();
            return this;
        },

        hideDiarySuggestions: function () {
            var suggestions = document.getElementById('ca-edit-diary-event-suggestions');
            if (!suggestions) {
                return this;
            }
            suggestions.innerHTML = '';
            suggestions.classList.add('d-none');
            return this;
        },

        renderDiarySuggestions: function (rows) {
            var suggestions = document.getElementById('ca-edit-diary-event-suggestions');
            if (!suggestions) {
                return this;
            }

            if (!Array.isArray(rows) || !rows.length) {
                suggestions.innerHTML = '<div class="list-group-item small text-muted">Nessun avvenimento trovato.</div>';
                suggestions.classList.remove('d-none');
                return this;
            }

            var html = [];
            for (var i = 0; i < rows.length; i += 1) {
                var row = rows[i] || {};
                html.push(
                    '<button type="button" class="list-group-item list-group-item-action text-start"'
                    + ' data-action="ca-edit-diary-select"'
                    + ' data-event-id="' + toInt(row.id, 0) + '"'
                    + ' data-event-title="' + escapeHtml(String(row.title || '')) + '"'
                    + ' data-event-date="' + escapeHtml(String(row.date_event || row.date_created || '')) + '"'
                    + ' data-event-location="' + escapeHtml(String(row.location_name || '')) + '">'
                    + '<div class="fw-semibold small">' + escapeHtml(String(row.title || 'Evento diario')) + '</div>'
                    + '<div class="small text-muted">' + escapeHtml(formatDiaryEventMeta(row)) + '</div>'
                    + '</button>'
                );
            }

            suggestions.innerHTML = html.join('');
            suggestions.classList.remove('d-none');
            return this;
        },

        selectDiarySuggestion: function (trigger) {
            this.setDiarySelection({
                id: toInt(trigger.getAttribute('data-event-id'), 0),
                title: trigger.getAttribute('data-event-title') || '',
                date_event: trigger.getAttribute('data-event-date') || '',
                location_name: trigger.getAttribute('data-event-location') || ''
            });
        },

        handleDiarySearchInput: function (rawValue) {
            var self = this;
            var value = String(rawValue || '').trim();

            this.clearDiarySelection(false);

            if (this.diarySearchTimer) {
                globalWindow.clearTimeout(this.diarySearchTimer);
                this.diarySearchTimer = null;
            }

            if (value.length < 2) {
                this.hideDiarySuggestions();
                return;
            }

            this.diarySearchTimer = globalWindow.setTimeout(function () {
                self.searchDiaryEvents(value);
            }, 220);
        },

        searchDiaryEvents: function (query) {
            var self = this;
            if (!this.currentEditArchiveId) {
                return;
            }

            httpPost('/chat-archives/diary/search', {
                id: this.currentEditArchiveId,
                query: query
            }).then(function (response) {
                var rows = response && Array.isArray(response.dataset) ? response.dataset : [];
                self.renderDiarySuggestions(rows);
            }).catch(function () {
                self.hideDiarySuggestions();
            });
        },

        openEditModal: function (id) {
            var self = this;
            var idInput = document.getElementById('ca-edit-id');
            var titleInput = document.getElementById('ca-edit-title');
            var descInput = document.getElementById('ca-edit-description');
            var diaryInput = document.getElementById('ca-edit-diary-event-id');
            var diarySearchInput = document.getElementById('ca-edit-diary-event-search');

            this.currentEditArchiveId = id;

            if (idInput) { idInput.value = String(id); }
            if (titleInput) { titleInput.value = ''; }
            if (descInput) { descInput.value = ''; }
            if (diaryInput) { diaryInput.value = '0'; }
            if (diarySearchInput) { diarySearchInput.value = ''; }
            this.clearDiarySelection(false);

            httpPost('/chat-archives/get', { id: id }).then(function (response) {
                var archive = response && response.dataset && response.dataset.archive
                    ? response.dataset.archive : {};
                if (titleInput) { titleInput.value = String(archive.title || ''); }
                if (descInput) { descInput.value = String(archive.description || ''); }
                if (toInt(archive.diary_event_id, 0) > 0) {
                    self.setDiarySelection({
                        id: toInt(archive.diary_event_id, 0),
                        title: String(archive.diary_event_title || ('Evento #' + toInt(archive.diary_event_id, 0))),
                        date_event: '',
                        location_name: ''
                    });
                }
            }).catch(function () {});

            var modal = bootstrapModal('chat-archives-edit-modal');
            if (modal) { modal.show(); }
        },

        submitEdit: function () {
            if (this.busy) { return; }

            var idInput = document.getElementById('ca-edit-id');
            var titleInput = document.getElementById('ca-edit-title');
            var descInput = document.getElementById('ca-edit-description');
            var diaryInput = document.getElementById('ca-edit-diary-event-id');
            var id = toInt(idInput ? idInput.value : 0, 0);
            var title = (titleInput ? titleInput.value : '').trim();
            var diaryEventId = toInt(diaryInput ? diaryInput.value : 0, 0);

            if (id <= 0 || !title) {
                showToast('warning', 'Compila tutti i campi obbligatori.');
                return;
            }

            var self = this;
            this.busy = true;

            httpPost('/chat-archives/update', {
                id: id,
                title: title,
                description: descInput ? descInput.value.trim() : ''
            }).then(function () {
                return httpPost('/chat-archives/diary/link', {
                    id: id,
                    diary_event_id: diaryEventId > 0 ? diaryEventId : 0
                });
            }).then(function () {
                self.busy = false;
                var modal = bootstrapModal('chat-archives-edit-modal');
                if (modal) { modal.hide(); }
                showToast('success', 'Archivio aggiornato.');
                self.loadList();
            }).catch(function (error) {
                self.busy = false;
                showError(error, 'Errore nell\'aggiornamento.');
            });
        },

        deleteArchive: function (id) {
            var self = this;
            httpPost('/chat-archives/delete', { id: id }).then(function () {
                showToast('success', 'Archivio eliminato.');
                self.loadList();
            }).catch(function (error) {
                showError(error, 'Errore nell\'eliminazione.');
            });
        }
    };

    var options = (extension && typeof extension === 'object') ? extension : {};
    return Object.assign(page, options).init();
}

globalWindow.GameChatArchivesPage = GameChatArchivesPage;
export { GameChatArchivesPage as GameChatArchivesPage };
export default GameChatArchivesPage;
