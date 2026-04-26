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

function httpPost(url, payload) {
    if (!globalWindow.Request || !globalWindow.Request.http || typeof globalWindow.Request.http.post !== 'function') {
        return Promise.reject(new Error('Request non disponibile'));
    }
    return globalWindow.Request.http.post(url, payload);
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

function getLocationId() {
    var el = document.querySelector('[name="location_id"]');
    return el ? toInt(el.value, 0) : 0;
}

function buildParticipantName(row) {
    return String((row.character_name || '') + ' ' + (row.character_surname || '')).trim();
}

var ChatArchiveCreateModal = {
    initialized: false,
    busy: false,
    messagesAll: [],
    messagesSelected: {},
    participantsAll: [],

    init: function () {
        if (this.initialized) { return this; }
        this.bind();
        this.initialized = true;
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

            if (action === 'chat-archive-create-open') {
                event.preventDefault();
                self.open();
                return;
            }
            if (action === 'ca-loc-load-messages') {
                event.preventDefault();
                self.loadMessages();
                return;
            }
            if (action === 'ca-loc-select-all') {
                event.preventDefault();
                self.toggleSelectAll();
                return;
            }
            if (action === 'ca-loc-save') {
                event.preventDefault();
                self.save();
            }
        });

        document.addEventListener('change', function (event) {
            var target = event.target;
            if (!target) { return; }

            if (target.getAttribute('data-role') === 'ca-loc-msg-check') {
                var msgId = toInt(target.getAttribute('data-msg-id'), 0);
                if (msgId > 0) {
                    self.messagesSelected[msgId] = target.checked;
                    self.syncParticipantChecksFromMessages();
                    self.updateSummary();
                }
                return;
            }

            if (target.getAttribute('data-role') === 'ca-loc-participant-check') {
                var characterId = toInt(target.getAttribute('data-character-id'), 0);
                if (characterId > 0) {
                    self.toggleParticipantSelection(characterId, target.checked);
                }
            }
        });
    },

    open: function () {
        this.messagesAll = [];
        this.messagesSelected = {};
        this.participantsAll = [];

        var titleInput = document.getElementById('ca-loc-title');
        var descInput = document.getElementById('ca-loc-description');
        var msgList = document.getElementById('ca-loc-messages-list');
        var participantList = document.getElementById('ca-loc-participants-list');
        var participantEmpty = document.getElementById('ca-loc-participants-empty');
        var selectAllBtn = document.getElementById('ca-loc-select-all-btn');
        var startedAt = document.getElementById('ca-loc-started-at');
        var endedAt = document.getElementById('ca-loc-ended-at');
        var summary = document.getElementById('ca-loc-summary');
        var messagesEmpty = document.getElementById('ca-loc-messages-empty');

        if (titleInput) { titleInput.value = ''; }
        if (descInput) { descInput.value = ''; }
        if (msgList) { msgList.innerHTML = ''; }
        if (participantList) {
            participantList.innerHTML = '';
            participantList.classList.add('d-none');
        }
        if (participantEmpty) { participantEmpty.classList.remove('d-none'); }
        if (messagesEmpty) { messagesEmpty.classList.add('d-none'); }
        if (selectAllBtn) {
            selectAllBtn.disabled = true;
            selectAllBtn.textContent = 'Seleziona tutti';
        }
        if (summary) { summary.classList.add('d-none'); }
        this.updateSummary();

        if (startedAt && endedAt) {
            var now = new Date();
            var from = new Date(now.getTime() - 8 * 60 * 60 * 1000);
            endedAt.value = now.toISOString().slice(0, 16);
            startedAt.value = from.toISOString().slice(0, 16);
        }

        var modal = bootstrapModal('chat-archive-create-modal');
        if (modal) {
            modal.show();
        } else {
            showToast('error', 'Impossibile aprire il form: ricarica la pagina e riprova.');
        }
    },

    loadMessages: function () {
        var self = this;
        var locationId = getLocationId();
        var startedAt = ((document.getElementById('ca-loc-started-at') || {}).value || '').replace('T', ' ');
        var endedAt = ((document.getElementById('ca-loc-ended-at') || {}).value || '').replace('T', ' ');

        if (locationId <= 0) {
            showToast('warning', 'Location non riconosciuta.');
            return;
        }
        if (!startedAt || !endedAt) {
            showToast('warning', 'Seleziona l\'intervallo di date.');
            return;
        }

        var loading = document.getElementById('ca-loc-messages-loading');
        var empty = document.getElementById('ca-loc-messages-empty');
        var msgList = document.getElementById('ca-loc-messages-list');
        var selectAllBtn = document.getElementById('ca-loc-select-all-btn');
        var participantList = document.getElementById('ca-loc-participants-list');
        var participantEmpty = document.getElementById('ca-loc-participants-empty');

        if (loading) { loading.classList.remove('d-none'); }
        if (msgList) { msgList.innerHTML = ''; }
        if (participantList) {
            participantList.innerHTML = '';
            participantList.classList.add('d-none');
        }
        if (empty) { empty.classList.add('d-none'); }
        if (participantEmpty) { participantEmpty.classList.add('d-none'); }
        if (selectAllBtn) {
            selectAllBtn.disabled = true;
            selectAllBtn.textContent = 'Seleziona tutti';
        }

        self.messagesAll = [];
        self.messagesSelected = {};
        self.participantsAll = [];
        self.updateSummary();

        httpPost('/location/messages/archive-range', {
            location_id: locationId,
            started_at: startedAt,
            ended_at: endedAt
        }).then(function (response) {
            if (loading) { loading.classList.add('d-none'); }
            var rows = (response && Array.isArray(response.dataset)) ? response.dataset : [];
            self.messagesAll = rows;
            self.renderPicker(rows);
            if (selectAllBtn) {
                selectAllBtn.disabled = rows.length === 0;
            }
        }).catch(function (error) {
            if (loading) { loading.classList.add('d-none'); }
            showError(error, 'Errore nel caricamento dei messaggi.');
        });
    },

    deriveParticipants: function (rows) {
        var map = {};
        for (var i = 0; i < rows.length; i += 1) {
            var row = rows[i] || {};
            var characterId = toInt(row.character_id, 0);
            var name = buildParticipantName(row);
            if (characterId <= 0 || !name) {
                continue;
            }
            map[characterId] = {
                characterId: characterId,
                name: name
            };
        }

        var participants = [];
        for (var key in map) {
            if (Object.prototype.hasOwnProperty.call(map, key)) {
                participants.push(map[key]);
            }
        }

        participants.sort(function (left, right) {
            return String(left.name).localeCompare(String(right.name), 'it');
        });

        return participants;
    },

    renderParticipants: function (participants) {
        var participantList = document.getElementById('ca-loc-participants-list');
        var participantEmpty = document.getElementById('ca-loc-participants-empty');
        if (!participantList) { return; }

        if (!participants.length) {
            participantList.innerHTML = '';
            participantList.classList.add('d-none');
            if (participantEmpty) {
                participantEmpty.textContent = 'Nessun partecipante selezionabile trovato nel range scelto.';
                participantEmpty.classList.remove('d-none');
            }
            return;
        }

        var html = [];
        for (var i = 0; i < participants.length; i += 1) {
            var participant = participants[i];
            html.push(
                '<label class="d-flex align-items-center gap-2 p-2 border-bottom cursor-pointer">'
                + '<input type="checkbox" class="form-check-input flex-shrink-0"'
                + ' data-role="ca-loc-participant-check"'
                + ' data-character-id="' + participant.characterId + '" checked>'
                + '<span class="small">' + escapeHtml(participant.name) + '</span>'
                + '</label>'
            );
        }

        participantList.innerHTML = html.join('');
        participantList.classList.remove('d-none');
        if (participantEmpty) { participantEmpty.classList.add('d-none'); }
    },

    renderMessages: function (rows) {
        var msgList = document.getElementById('ca-loc-messages-list');
        var empty = document.getElementById('ca-loc-messages-empty');
        if (!msgList) { return; }

        if (!rows.length) {
            msgList.innerHTML = '';
            if (empty) { empty.classList.remove('d-none'); }
            return;
        }

        if (empty) { empty.classList.add('d-none'); }

        var html = [];
        for (var i = 0; i < rows.length; i += 1) {
            var row = rows[i] || {};
            var id = toInt(row.id, 0);
            var characterId = toInt(row.character_id, 0);
            var charName = buildParticipantName(row);
            var date = formatDatetime(row.date_created);
            var body = String(row.body || '').substring(0, 160);

            this.messagesSelected[id] = true;

            html.push(
                '<label class="d-flex align-items-start gap-2 p-2 border-bottom cursor-pointer">'
                + '<input type="checkbox" class="form-check-input mt-1 flex-shrink-0"'
                + ' data-role="ca-loc-msg-check"'
                + ' data-msg-id="' + id + '"'
                + ' data-character-id="' + characterId + '"'
                + ' checked>'
                + '<div class="flex-grow-1 overflow-hidden">'
                + '<div class="small fw-semibold text-truncate">' + escapeHtml(charName || 'Messaggio') 
                + ' <span class="text-muted fw-normal">' + escapeHtml(date) + '</span></div>'
                + '<div class="small text-muted">' + escapeHtml(body) + '</div>'
                + '</div>'
                + '</label>'
            );
        }

        msgList.innerHTML = html.join('');
    },

    renderPicker: function (rows) {
        this.participantsAll = this.deriveParticipants(rows);
        this.renderParticipants(this.participantsAll);
        this.renderMessages(rows);
        this.syncParticipantChecksFromMessages();
        this.updateSummary();
    },

    toggleParticipantSelection: function (characterId, selected) {
        var changed = false;

        for (var i = 0; i < this.messagesAll.length; i += 1) {
            var row = this.messagesAll[i] || {};
            if (toInt(row.character_id, 0) !== characterId) {
                continue;
            }

            var messageId = toInt(row.id, 0);
            if (messageId <= 0) {
                continue;
            }

            this.messagesSelected[messageId] = selected;
            changed = true;
        }

        if (!changed) {
            return;
        }

        var checks = document.querySelectorAll('[data-role="ca-loc-msg-check"][data-character-id="' + characterId + '"]');
        for (var j = 0; j < checks.length; j += 1) {
            checks[j].checked = selected;
        }

        this.syncParticipantChecksFromMessages();
        this.updateSummary();
    },

    syncParticipantChecksFromMessages: function () {
        var selectedByCharacterId = {};

        for (var i = 0; i < this.messagesAll.length; i += 1) {
            var row = this.messagesAll[i] || {};
            var messageId = toInt(row.id, 0);
            var characterId = toInt(row.character_id, 0);

            if (messageId <= 0 || characterId <= 0 || !this.messagesSelected[messageId]) {
                continue;
            }

            selectedByCharacterId[characterId] = true;
        }

        var checks = document.querySelectorAll('[data-role="ca-loc-participant-check"]');
        for (var j = 0; j < checks.length; j += 1) {
            var characterId = toInt(checks[j].getAttribute('data-character-id'), 0);
            checks[j].checked = !!selectedByCharacterId[characterId];
        }
    },

    toggleSelectAll: function () {
        var checks = document.querySelectorAll('[data-role="ca-loc-msg-check"]');
        var allChecked = true;

        for (var i = 0; i < checks.length; i += 1) {
            if (!checks[i].checked) {
                allChecked = false;
                break;
            }
        }

        for (var j = 0; j < checks.length; j += 1) {
            var msgId = toInt(checks[j].getAttribute('data-msg-id'), 0);
            checks[j].checked = !allChecked;
            if (msgId > 0) {
                this.messagesSelected[msgId] = !allChecked;
            }
        }

        this.syncParticipantChecksFromMessages();
        this.updateSummary();
    },

    computeSelectionSummary: function () {
        var includedMessages = 0;
        var includedParticipants = {};
        var allParticipants = {};

        for (var i = 0; i < this.messagesAll.length; i += 1) {
            var row = this.messagesAll[i] || {};
            var messageId = toInt(row.id, 0);
            var characterId = toInt(row.character_id, 0);
            var name = buildParticipantName(row);

            if (characterId > 0 && name) {
                allParticipants[characterId] = name;
            }

            if (messageId <= 0 || !this.messagesSelected[messageId]) {
                continue;
            }

            includedMessages += 1;

            if (characterId > 0 && name) {
                includedParticipants[characterId] = name;
            }
        }

        var excludedParticipants = [];
        for (var key in allParticipants) {
            if (Object.prototype.hasOwnProperty.call(allParticipants, key) && !includedParticipants[key]) {
                excludedParticipants.push(allParticipants[key]);
            }
        }

        excludedParticipants.sort(function (left, right) {
            return String(left).localeCompare(String(right), 'it');
        });

        return {
            totalMessages: this.messagesAll.length,
            includedMessages: includedMessages,
            totalParticipants: Object.keys(allParticipants).length,
            includedParticipants: Object.keys(includedParticipants).length,
            excludedParticipants: excludedParticipants,
            completenessLevel: (includedMessages > 0 && includedMessages === this.messagesAll.length) ? 'complete' : 'partial'
        };
    },

    updateSummary: function () {
        var summary = this.computeSelectionSummary();
        var messagesCounter = document.getElementById('ca-loc-messages-counter');
        var messagesTotal = document.getElementById('ca-loc-messages-total');
        var participantsCounter = document.getElementById('ca-loc-participants-counter');
        var participantsTotal = document.getElementById('ca-loc-participants-total');
        var summaryWrap = document.getElementById('ca-loc-summary');
        var summaryBadge = document.getElementById('ca-loc-summary-badge');
        var summaryMessagesIncluded = document.getElementById('ca-loc-summary-messages-included');
        var summaryMessagesTotal = document.getElementById('ca-loc-summary-messages-total');
        var summaryParticipantsIncluded = document.getElementById('ca-loc-summary-participants-included');
        var summaryParticipantsTotal = document.getElementById('ca-loc-summary-participants-total');
        var summaryExcludedWrap = document.getElementById('ca-loc-summary-excluded-wrap');
        var summaryExcluded = document.getElementById('ca-loc-summary-excluded');
        var selectAllBtn = document.getElementById('ca-loc-select-all-btn');

        if (messagesCounter) { messagesCounter.textContent = String(summary.includedMessages); }
        if (messagesTotal) { messagesTotal.textContent = String(summary.totalMessages); }
        if (participantsCounter) { participantsCounter.textContent = String(summary.includedParticipants); }
        if (participantsTotal) { participantsTotal.textContent = String(summary.totalParticipants); }
        if (summaryMessagesIncluded) { summaryMessagesIncluded.textContent = String(summary.includedMessages); }
        if (summaryMessagesTotal) { summaryMessagesTotal.textContent = String(summary.totalMessages); }
        if (summaryParticipantsIncluded) { summaryParticipantsIncluded.textContent = String(summary.includedParticipants); }
        if (summaryParticipantsTotal) { summaryParticipantsTotal.textContent = String(summary.totalParticipants); }

        if (summaryWrap) {
            summaryWrap.classList.toggle('d-none', summary.totalMessages === 0);
        }

        if (summaryBadge) {
            summaryBadge.textContent = summary.completenessLevel === 'partial' ? 'Parziale' : 'Completo';
            summaryBadge.className = 'badge ' + (summary.completenessLevel === 'partial' ? 'text-bg-warning' : 'text-bg-success');
        }

        if (summaryExcludedWrap && summaryExcluded) {
            summaryExcluded.textContent = summary.excludedParticipants.join(', ');
            summaryExcludedWrap.classList.toggle('d-none', summary.excludedParticipants.length === 0);
        }

        if (selectAllBtn) {
            selectAllBtn.textContent = (summary.totalMessages > 0 && summary.includedMessages === summary.totalMessages)
                ? 'Deseleziona tutti'
                : 'Seleziona tutti';
        }
    },

    save: function () {
        if (this.busy) { return; }

        var title = ((document.getElementById('ca-loc-title') || {}).value || '').trim();
        var description = ((document.getElementById('ca-loc-description') || {}).value || '').trim();
        var locationId = getLocationId();
        var startedAt = ((document.getElementById('ca-loc-started-at') || {}).value || '').replace('T', ' ');
        var endedAt = ((document.getElementById('ca-loc-ended-at') || {}).value || '').replace('T', ' ');
        var selectedIds = [];

        for (var key in this.messagesSelected) {
            if (this.messagesSelected[key]) {
                selectedIds.push(toInt(key, 0));
            }
        }

        if (!title) {
            showToast('warning', 'Inserisci un titolo.');
            return;
        }
        if (locationId <= 0) {
            showToast('warning', 'Location non riconosciuta.');
            return;
        }
        if (!startedAt || !endedAt) {
            showToast('warning', 'Seleziona l\'intervallo di date.');
            return;
        }
        if (!selectedIds.length) {
            showToast('warning', 'Seleziona almeno un messaggio.');
            return;
        }

        var self = this;
        this.busy = true;

        httpPost('/chat-archives/create', {
            title: title,
            description: description,
            source_location_id: locationId,
            started_at: startedAt,
            ended_at: endedAt,
            message_ids: selectedIds
        }).then(function () {
            self.busy = false;
            var modal = bootstrapModal('chat-archive-create-modal');
            if (modal) { modal.hide(); }
            showToast('success', 'Snapshot salvato. Visualizzalo in "Archivi di giocata".');
        }).catch(function (error) {
            self.busy = false;
            showError(error, 'Errore nella creazione dello snapshot.');
        });
    }
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { ChatArchiveCreateModal.init(); });
} else {
    ChatArchiveCreateModal.init();
}

globalWindow.ChatArchiveCreateModal = ChatArchiveCreateModal;
export { ChatArchiveCreateModal as ChatArchiveCreateModal };
export default ChatArchiveCreateModal;
