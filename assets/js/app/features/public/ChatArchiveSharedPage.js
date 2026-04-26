var ChatArchiveSharedPage = {
    initialized: false,
    root: null,

    init: function () {
        if (this.initialized) {
            return this;
        }
        this.root = document.getElementById('chat-archive-shared-page');
        if (!this.root) {
            return this;
        }
        this.initialized = true;
        this.load();
        return this;
    },

    escapeHtml: function (value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    },

    formatDatetime: function (value) {
        if (!value) { return ''; }
        var d = new Date(String(value).replace(' ', 'T'));
        if (isNaN(d.getTime())) { return String(value); }
        return d.toLocaleDateString('it-IT', { day: '2-digit', month: '2-digit', year: 'numeric' })
            + ' ' + d.toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' });
    },

    parseArchiveMetadata: function (archive) {
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
    },

    participantNames: function (rows) {
        if (!Array.isArray(rows)) {
            return [];
        }

        var names = [];
        for (var i = 0; i < rows.length; i += 1) {
            var row = rows[i] || {};
            var name = String(row.name || '').trim();
            if (name) {
                names.push(name);
            }
        }

        return names;
    },

    load: function () {
        var self = this;
        var token = this.root.getAttribute('data-archive-token') || '';

        if (!token) {
            this.showNotFound();
            return;
        }

        var httpPost = function (url, data) {
            if (!window.Request || !window.Request.http || typeof window.Request.http.post !== 'function') {
                return Promise.reject(new Error('Request unavailable'));
            }
            return window.Request.http.post(url, data);
        };

        httpPost('/shared/chat-archive/load', { token: token }).then(function (response) {
            var data = response && response.dataset ? response.dataset : {};
            var archive = data.archive || {};
            var messages = Array.isArray(data.messages) ? data.messages : [];
            self.render(archive, messages);
        }).catch(function () {
            self.showNotFound();
        });
    },

    showNotFound: function () {
        var loading = document.getElementById('ca-shared-loading');
        var notFound = document.getElementById('ca-shared-not-found');
        if (loading) { loading.classList.add('d-none'); }
        if (notFound) { notFound.classList.remove('d-none'); }
    },

    renderSummary: function (archive) {
        var metadata = this.parseArchiveMetadata(archive);
        var summary = document.getElementById('ca-shared-summary');
        var excludedWrap = document.getElementById('ca-shared-excluded-wrap');
        var excluded = document.getElementById('ca-shared-excluded-participants');
        var createdBy = document.getElementById('ca-shared-created-by');
        var excludedNames = this.participantNames(metadata.excluded_participants);
        var createdByName = String(metadata.created_by_name || '').trim();
        var completeness = String(archive.completeness_level || 'complete') === 'partial'
            ? '<span class="badge bg-warning text-dark">Parziale</span>'
            : '<span class="badge bg-success">Completo</span>';

        if (summary) {
            summary.innerHTML = [
                completeness,
                '<span>Messaggi inclusi: ' + parseInt(String(archive.included_messages_count || 0), 10) + ' / ' + parseInt(String(archive.total_messages_in_range || 0), 10) + '</span>',
                '<span>Partecipanti inclusi: ' + parseInt(String(archive.included_participants_count || 0), 10) + ' / ' + parseInt(String(archive.total_participants_in_range || 0), 10) + '</span>'
            ].join('');
        }

        if (excludedWrap && excluded) {
            excluded.textContent = excludedNames.join(', ');
            excludedWrap.classList.toggle('d-none', excludedNames.length === 0);
        }

        if (createdBy) {
            createdBy.textContent = createdByName
                ? ('Archivio creato da ' + createdByName + '. Include solo i messaggi selezionati dall\'autore.')
                : '';
            createdBy.classList.toggle('d-none', !createdByName);
        }
    },

    render: function (archive, messages) {
        var loading = document.getElementById('ca-shared-loading');
        var content = document.getElementById('ca-shared-content');
        var titleEl = document.getElementById('ca-shared-title');
        var metaEl = document.getElementById('ca-shared-meta');
        var messagesEl = document.getElementById('ca-shared-messages');

        if (loading) { loading.classList.add('d-none'); }
        if (!content) { return; }

        if (titleEl) {
            titleEl.textContent = String(archive.title || 'Archivio di giocata');
        }

        if (metaEl) {
            var parts = [];
            if (archive.location_name) {
                parts.push('<span><i class="bi bi-geo-alt me-1"></i>' + this.escapeHtml(String(archive.location_name)) + '</span>');
            }
            if (archive.started_at) {
                parts.push(
                    '<span><i class="bi bi-calendar me-1"></i>'
                    + this.escapeHtml(this.formatDatetime(archive.started_at))
                    + ' &rarr; '
                    + this.escapeHtml(this.formatDatetime(archive.ended_at))
                    + '</span>'
                );
            }
            metaEl.innerHTML = parts.join('');
        }

        this.renderSummary(archive);

        if (messagesEl) {
            if (!messages.length) {
                messagesEl.innerHTML = '<p class="text-muted small p-3">Nessun messaggio in questo archivio.</p>';
            } else {
                var html = [];
                for (var i = 0; i < messages.length; i += 1) {
                    var msg = messages[i] || {};
                    html.push(
                        '<div class="p-2 border-bottom">'
                        + '<div class="small fw-semibold mb-1">'
                        + this.escapeHtml(String(msg.character_name_snapshot || ''))
                        + ' <span class="text-muted fw-normal">'
                        + this.escapeHtml(this.formatDatetime(msg.sent_at))
                        + '</span></div>'
                        + '<div class="small">' + String(msg.message_html || '') + '</div>'
                        + '</div>'
                    );
                }
                messagesEl.innerHTML = html.join('');
            }
        }

        content.classList.remove('d-none');
    }
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
        ChatArchiveSharedPage.init();
    });
} else {
    ChatArchiveSharedPage.init();
}

window.ChatArchiveSharedPage = ChatArchiveSharedPage;
export { ChatArchiveSharedPage as ChatArchiveSharedPage };
export default ChatArchiveSharedPage;
