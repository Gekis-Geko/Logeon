const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function resolveModule(name) {
    if (!globalWindow.RuntimeBootstrap || typeof globalWindow.RuntimeBootstrap.resolveAppModule !== 'function') {
        return null;
    }
    try { return globalWindow.RuntimeBootstrap.resolveAppModule(name); } catch (e) { return null; }
}

function showToast(type, body) {
    if (globalWindow.Toast && typeof globalWindow.Toast.show === 'function') {
        globalWindow.Toast.show({ type: type, body: body });
    }
}

function errorMessage(error, fallback) {
    if (globalWindow.Request && typeof globalWindow.Request.getErrorMessage === 'function') {
        return globalWindow.Request.getErrorMessage(error, fallback || 'Operazione non riuscita.');
    }
    if (error && typeof error.message === 'string' && error.message.trim() !== '') {
        return error.message.trim();
    }
    return fallback || 'Operazione non riuscita.';
}

function parseRows(response) {
    if (!response) { return []; }
    var dataset = response.dataset;
    if (Array.isArray(dataset)) { return dataset; }
    if (dataset && Array.isArray(dataset.rows)) { return dataset.rows; }
    return [];
}

function parseObject(response) {
    if (!response) { return null; }
    var dataset = response.dataset;
    if (dataset && !Array.isArray(dataset) && typeof dataset === 'object') {
        return dataset;
    }
    return null;
}

function fmtDate(value) {
    var raw = String(value || '').trim();
    if (!raw) { return ''; }
    var date = new Date(raw.replace(' ', 'T'));
    if (isNaN(date.getTime())) { return raw; }
    return String(date.getDate()).padStart(2, '0')
        + '/' + String(date.getMonth() + 1).padStart(2, '0')
        + '/' + date.getFullYear()
        + ' '
        + String(date.getHours()).padStart(2, '0')
        + ':' + String(date.getMinutes()).padStart(2, '0');
}

function escapeHtml(value) {
    return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function shortText(value, max) {
    var text = String(value || '');
    var limit = parseInt(max, 10) || 120;
    if (text.length <= limit) { return text; }
    return text.substring(0, limit) + '...';
}

function statusLabel(status) {
    var map = {
        draft: 'Bozza',
        scheduled: 'Programmato',
        active: 'Attivo',
        completed: 'Completato',
        cancelled: 'Annullato'
    };
    var key = String(status || '').toLowerCase();
    return map[key] || (key || '-');
}

function statusBadge(status) {
    var map = {
        draft: 'text-bg-secondary',
        scheduled: 'text-bg-info',
        active: 'text-bg-success',
        completed: 'text-bg-primary',
        cancelled: 'text-bg-danger'
    };
    return map[String(status || '').toLowerCase()] || 'text-bg-dark';
}

function scopeLabel(scopeType) {
    var map = {
        global: 'Globale',
        map: 'Mappa',
        location: 'Luogo',
        faction: 'Fazione',
        character: 'Personaggio'
    };
    var key = String(scopeType || '').toLowerCase();
    return map[key] || (key || '-');
}

function participantLabel(mode) {
    return String(mode || '').toLowerCase() === 'faction' ? 'Fazione' : 'Personaggio';
}

function GameSystemEventsPage(extension) {
    var page = {
        offcanvas: null,
        moduleApi: null,
        currentRows: [],
        selectedEventId: 0,
        selectedEvent: null,
        myFactions: [],
        tagsCatalog: [],
        indicatorPollKey: 'game.system-events.feed-indicator',
        indicatorPollMs: 45000,
        indicatorStorageKey: 'game.feed.system-events.seen_marker',
        indicatorLatestMarker: 0,

        resolveModuleApi: function () {
            if (this.moduleApi
                && typeof this.moduleApi.list === 'function'
                && typeof this.moduleApi.get === 'function'
                && typeof this.moduleApi.join === 'function'
                && typeof this.moduleApi.leave === 'function') {
                return this.moduleApi;
            }
            return resolveModule('game.system-events');
        },

        init: function () {
            var el = document.getElementById('offcanvasSystemEvents');
            if (!el) { return this; }
            this.offcanvas = el;
            this.detailModal = document.getElementById('system-event-detail-modal');
            this.bind();
            this.startIndicatorPoll();
            this.pollIndicator(false);
            return this;
        },

        bind: function () {
            var self = this;
            var el = this.offcanvas;
            if (!el) { return; }

            el.addEventListener('show.bs.offcanvas', function () {
                self.loadTagsCatalog();
                self.loadFactions();
                self.load();
                self.pollIndicator(true);
            });

            el.addEventListener('click', function (event) {
                var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
                if (!trigger) { return; }
                var action = String(trigger.getAttribute('data-action') || '');
                if (!action) { return; }
                event.preventDefault();

                if (action === 'system-events-reload') {
                    self.load();
                    return;
                }
                if (action === 'system-event-open') {
                    self.openDetail(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0);
                    return;
                }
                if (action === 'system-event-join') {
                    self.handleParticipation('join', parseInt(trigger.getAttribute('data-id') || '0', 10) || 0);
                    return;
                }
                if (action === 'system-event-leave') {
                    self.handleParticipation('leave', parseInt(trigger.getAttribute('data-id') || '0', 10) || 0);
                    return;
                }
            });

            if (self.detailModal) {
                self.detailModal.addEventListener('click', function (event) {
                    var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
                    if (!trigger) { return; }
                    var action = String(trigger.getAttribute('data-action') || '');
                    if (!action) { return; }
                    event.preventDefault();
                    if (action === 'system-event-join') { self.handleParticipation('join', parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); return; }
                    if (action === 'system-event-leave') { self.handleParticipation('leave', parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); return; }
                });
            }

            var status = el.querySelector('#system-events-status-filter');
            var type = el.querySelector('#system-events-type-filter');
            var scope = el.querySelector('#system-events-scope-filter');
            var tag = el.querySelector('#system-events-tag-filter');
            [status, type, scope, tag].forEach(function (node) {
                if (!node) { return; }
                node.addEventListener('change', function () {
                    self.load();
                });
            });
        },

        loadTagsCatalog: function () {
            var self = this;
            if (!globalWindow.Request || !Request.http || typeof Request.http.post !== 'function') {
                return;
            }
            Request.http.post('/list/narrative-tags', { entity_type: 'system_event' }).then(function (response) {
                var rows = parseRows(response);
                self.tagsCatalog = Array.isArray(rows) ? rows : [];
                self.populateTagFilter();
            }).catch(function () {});
        },

        populateTagFilter: function () {
            var select = document.getElementById('system-events-tag-filter');
            if (!select) { return; }
            var current = String(select.value || '');
            var html = '<option value="">Tutti i tag</option>';
            for (var i = 0; i < this.tagsCatalog.length; i += 1) {
                var row = this.tagsCatalog[i] || {};
                var id = parseInt(row.id || '0', 10) || 0;
                if (id <= 0) { continue; }
                html += '<option value="' + id + '">' + escapeHtml(row.label || row.slug || ('Tag #' + id)) + '</option>';
            }
            select.innerHTML = html;
            if (current !== '') {
                select.value = current;
            }
        },

        readSeenMarker: function () {
            if (!globalWindow.localStorage) { return 0; }
            var raw = globalWindow.localStorage.getItem(this.indicatorStorageKey);
            var value = parseInt(raw || '0', 10);
            return value > 0 ? value : 0;
        },

        writeSeenMarker: function (value) {
            if (!globalWindow.localStorage) { return; }
            var marker = parseInt(value || '0', 10);
            if (marker > 0) {
                globalWindow.localStorage.setItem(this.indicatorStorageKey, String(marker));
            }
        },

        extractRowMarker: function (row) {
            var marker = parseInt((row && row.id) || '0', 10);
            return marker > 0 ? marker : 0;
        },

        latestMarkerFromRows: function (rows) {
            var latest = 0;
            var list = Array.isArray(rows) ? rows : [];
            for (var i = 0; i < list.length; i += 1) {
                var marker = this.extractRowMarker(list[i]);
                if (marker > latest) {
                    latest = marker;
                }
            }
            return latest;
        },

        countNewRows: function (rows, seenMarker) {
            var count = 0;
            var list = Array.isArray(rows) ? rows : [];
            for (var i = 0; i < list.length; i += 1) {
                if (this.extractRowMarker(list[i]) > seenMarker) {
                    count += 1;
                }
            }
            return count;
        },

        setIndicatorBadge: function (count) {
            var safeCount = parseInt(count || '0', 10);
            if (safeCount < 0) { safeCount = 0; }
            var labels = document.querySelectorAll('[data-feed-badge="system-events"]');
            for (var i = 0; i < labels.length; i += 1) {
                var label = labels[i];
                if (safeCount > 0) {
                    label.textContent = safeCount > 99 ? '99+' : String(safeCount);
                    label.classList.remove('d-none');
                    label.classList.add('feed-badge-pulse');
                } else {
                    label.textContent = '0';
                    label.classList.remove('feed-badge-pulse');
                    label.classList.add('d-none');
                }
            }
        },

        processIndicatorRows: function (rows, markSeen) {
            var latest = this.latestMarkerFromRows(rows);
            if (latest > this.indicatorLatestMarker) {
                this.indicatorLatestMarker = latest;
            }

            var seen = this.readSeenMarker();

            if (markSeen === true && latest > 0) {
                seen = latest;
                this.writeSeenMarker(latest);
            }

            var newCount = 0;
            if (markSeen !== true && latest > seen) {
                newCount = this.countNewRows(rows, seen);
            }
            this.setIndicatorBadge(newCount);
        },

        pollIndicator: function (markSeen) {
            var mod = this.resolveModuleApi();
            if (!mod || typeof mod.list !== 'function') { return; }
            var self = this;
            mod.list({ limit: 40, page: 1 }).then(function (response) {
                var ds = parseObject(response);
                var rows = ds && Array.isArray(ds.rows) ? ds.rows : parseRows(response);
                self.processIndicatorRows(Array.isArray(rows) ? rows : [], markSeen === true);
            }).catch(function () {});
        },

        startIndicatorPoll: function () {
            var manager = globalWindow.AppLifecycle && typeof globalWindow.AppLifecycle.getPollManager === 'function'
                ? globalWindow.AppLifecycle.getPollManager()
                : null;
            if (!manager || typeof manager.start !== 'function') {
                return;
            }
            var self = this;
            manager.start(this.indicatorPollKey, function () {
                self.pollIndicator(false);
            }, this.indicatorPollMs);
        },

        stopIndicatorPoll: function () {
            var manager = globalWindow.AppLifecycle && typeof globalWindow.AppLifecycle.getPollManager === 'function'
                ? globalWindow.AppLifecycle.getPollManager()
                : null;
            if (!manager || typeof manager.stop !== 'function') {
                return;
            }
            manager.stop(this.indicatorPollKey);
        },

        loadFactions: function () {
            var self = this;
            var mod = this.resolveModuleApi();
            if (!mod || typeof mod.myFactions !== 'function') {
                return;
            }
            mod.myFactions({}).then(function (response) {
                var rows = parseRows(response);
                self.myFactions = Array.isArray(rows) ? rows : [];
            }).catch(function () {
                self.myFactions = [];
            });
        },

        load: function () {
            var list = document.getElementById('system-events-list');
            var empty = document.getElementById('system-events-empty');
            var loading = document.getElementById('system-events-loading');
            if (list) { list.innerHTML = ''; }
            if (empty) { empty.classList.add('d-none'); }
            if (loading) { loading.classList.remove('d-none'); }

            var mod = this.resolveModuleApi();
            if (!mod || typeof mod.list !== 'function') {
                if (loading) { loading.classList.add('d-none'); }
                return;
            }

            var statusNode = document.getElementById('system-events-status-filter');
            var typeNode = document.getElementById('system-events-type-filter');
            var scopeNode = document.getElementById('system-events-scope-filter');
            var tagNode = document.getElementById('system-events-tag-filter');
            var payload = { limit: 40, page: 1 };
            if (statusNode && statusNode.value) { payload.status = statusNode.value; }
            if (typeNode && typeNode.value) { payload.type = typeNode.value; }
            if (scopeNode && scopeNode.value) { payload.scope_type = scopeNode.value; }
            if (tagNode) {
                var tagId = parseInt(tagNode.value || '0', 10) || 0;
                if (tagId > 0) {
                    payload.tag_ids = [tagId];
                }
            }

            var self = this;
            mod.list(payload).then(function (response) {
                if (loading) { loading.classList.add('d-none'); }
                var ds = parseObject(response);
                var rows = ds && Array.isArray(ds.rows) ? ds.rows : parseRows(response);
                self.currentRows = Array.isArray(rows) ? rows : [];
                if (!self.currentRows.length) {
                    if (empty) { empty.classList.remove('d-none'); }
                    self.resetDetail();
                    return;
                }
                self.renderList(self.currentRows);
                if (self.selectedEventId > 0) {
                    self.openDetail(self.selectedEventId);
                }
            }).catch(function (error) {
                if (loading) { loading.classList.add('d-none'); }
                showToast('error', errorMessage(error, 'Errore caricamento eventi di sistema.'));
            });
        },

        renderList: function (rows) {
            var list = document.getElementById('system-events-list');
            if (!list) { return; }
            var self = this;
            list.innerHTML = rows.map(function (ev) {
                var id = parseInt(ev.id || '0', 10) || 0;
                var joined = parseInt(ev.viewer_joined || '0', 10) === 1;
                var scopeText = scopeLabel(ev.scope_type) + ((parseInt(ev.scope_id || '0', 10) || 0) > 0 ? (' #' + (parseInt(ev.scope_id || '0', 10) || 0)) : '');
                return '<div class="list-group-item px-3 py-3">'
                    + '<div class="d-flex justify-content-between align-items-start gap-2 mb-1">'
                    + '  <div class="small fw-bold">' + escapeHtml(ev.title || ('Evento #' + id)) + '</div>'
                    + '  <span class="badge ' + statusBadge(ev.status) + '">' + escapeHtml(statusLabel(ev.status)) + '</span>'
                    + '</div>'
                    + '<div class="small text-muted mb-2">'
                    + escapeHtml(scopeText) + ' • ' + escapeHtml(participantLabel(ev.participant_mode))
                    + (ev.starts_at ? (' • Inizio: ' + escapeHtml(fmtDate(ev.starts_at))) : '')
                    + '</div>'
                    + (ev.description ? '<p class="small mb-2">' + escapeHtml(shortText(ev.description, 120)) + '</p>' : '')
                    + self.renderTagBadges(ev.narrative_tags)
                    + '<div class="d-flex justify-content-between align-items-center">'
                    + '  <button type="button" class="btn btn-sm btn-outline-secondary" data-action="system-event-open" data-id="' + id + '">Dettaglio</button>'
                    + '  <span class="small ' + (joined ? 'text-success' : 'text-muted') + '">' + (joined ? 'Adesione attiva' : 'Non aderito') + '</span>'
                    + '</div>'
                    + '</div>';
            }).join('');
        },

        resetDetail: function () {
            this.selectedEventId = 0;
            this.selectedEvent = null;
            var body = document.getElementById('system-event-detail-modal-body');
            if (body) { body.innerHTML = ''; }
            var el = document.getElementById('system-event-detail-modal');
            if (el && globalWindow.bootstrap && globalWindow.bootstrap.Modal) {
                var modal = globalWindow.bootstrap.Modal.getInstance(el);
                if (modal) { modal.hide(); }
            }
        },

        openDetail: function (eventId) {
            eventId = parseInt(eventId || '0', 10) || 0;
            if (eventId <= 0) { return; }

            var mod = this.resolveModuleApi();
            if (!mod || typeof mod.get !== 'function') { return; }
            var self = this;
            mod.get({ event_id: eventId }).then(function (response) {
                var eventObj = parseObject(response);
                if (!eventObj || !eventObj.id) {
                    showToast('warning', 'Dettaglio evento non disponibile.');
                    return;
                }
                self.selectedEventId = parseInt(eventObj.id, 10) || 0;
                self.selectedEvent = eventObj;
                self.renderDetail(eventObj);
            }).catch(function (error) {
                showToast('error', errorMessage(error, 'Impossibile aprire il dettaglio evento.'));
            });
        },

        renderDetail: function (ev) {
            var el = document.getElementById('system-event-detail-modal');
            var detail = document.getElementById('system-event-detail-modal-body');
            if (!el || !detail) { return; }

            var joined = parseInt(ev.viewer_joined || '0', 10) === 1;
            var eventId = parseInt(ev.id || '0', 10) || 0;
            var scopeText = scopeLabel(ev.scope_type) + ((parseInt(ev.scope_id || '0', 10) || 0) > 0 ? (' #' + (parseInt(ev.scope_id || '0', 10) || 0)) : '');
            var mode = String(ev.participant_mode || 'character').toLowerCase();
            var joinButton = joined
                ? '<button type="button" class="btn btn-sm btn-outline-danger" data-action="system-event-leave" data-id="' + eventId + '">Ritira adesione</button>'
                : '<button type="button" class="btn btn-sm btn-primary" data-action="system-event-join" data-id="' + eventId + '">Aderisci</button>';

            var factionField = '';
            if (mode === 'faction') {
                var options = this.factionOptionsHtml();
                factionField = '<div class="mt-2">'
                    + '<label class="form-label small mb-1">Fazione</label>'
                    + '<select id="system-events-faction-select" class="form-select form-select-sm">' + options + '</select>'
                    + '</div>';
            }

            detail.innerHTML = '<h4>' + escapeHtml(ev.title || ('Evento #' + eventId)) + '</h4>'
                + '<div class="small text-muted mb-1">Tipo: <b>' + escapeHtml(ev.type || '-') + '</b> • Stato: <b>' + escapeHtml(statusLabel(ev.status)) + '</b></div>'
                + '<div class="small text-muted mb-1">Scope: <b>' + escapeHtml(scopeText) + '</b> • Partecipazione: <b>' + escapeHtml(participantLabel(ev.participant_mode)) + '</b></div>'
                + '<div class="small text-muted mb-2">Inizio: <b>' + escapeHtml(fmtDate(ev.starts_at) || '-') + '</b> • Fine: <b>' + escapeHtml(fmtDate(ev.ends_at) || '-') + '</b></div><hr/>'
                + (ev.description ? '<div class="mb-2">' + escapeHtml(ev.description) + '</div>' : '')
                + this.renderTagBadges(ev.narrative_tags)
                + factionField
                + '<hr/><div class="d-flex justify-content-between align-items-center mt-2">'
                + '  <span class="small ' + (joined ? 'text-success' : 'text-muted') + '">' + (joined ? 'Adesione registrata' : 'Non hai aderito') + '</span>'
                + joinButton
                + '</div>';

            if (globalWindow.bootstrap && globalWindow.bootstrap.Modal) {
                globalWindow.bootstrap.Modal.getOrCreateInstance(el).show();
            }
        },

        renderTagBadges: function (tags) {
            var rows = Array.isArray(tags) ? tags : [];
            if (!rows.length) { return ''; }
            var html = '<div class="d-flex flex-wrap gap-1 mb-2">';
            for (var i = 0; i < rows.length; i += 1) {
                var row = rows[i] || {};
                html += '<span class="badge text-bg-secondary">' + escapeHtml(row.label || row.slug || 'Tag') + '</span>';
            }
            html += '</div>';
            return html;
        },

        factionOptionsHtml: function () {
            var allowedRoles = { leader: true, officer: true, advisor: true };
            var options = [];
            for (var i = 0; i < this.myFactions.length; i += 1) {
                var row = this.myFactions[i] || {};
                var role = String(row.role || '').toLowerCase();
                if (!allowedRoles[role]) { continue; }
                var id = parseInt(row.faction_id || row.id || '0', 10) || 0;
                if (id <= 0) { continue; }
                var name = String(row.faction_name || row.name || ('Fazione #' + id));
                options.push('<option value="' + id + '">' + escapeHtml(name) + ' (' + escapeHtml(role) + ')</option>');
            }
            if (!options.length) {
                return '<option value="0">Nessuna fazione idonea</option>';
            }
            return options.join('');
        },

        handleParticipation: function (action, eventId) {
            var ev = this.selectedEvent;
            eventId = parseInt(eventId || (ev ? ev.id : 0), 10) || 0;
            if (eventId <= 0) { return; }

            var mod = this.resolveModuleApi();
            if (!mod) { return; }

            var payload = { event_id: eventId };
            if (ev && String(ev.participant_mode || '').toLowerCase() === 'faction') {
                var factionSelect = document.getElementById('system-events-faction-select');
                var factionId = factionSelect ? (parseInt(factionSelect.value || '0', 10) || 0) : 0;
                if (factionId <= 0) {
                    showToast('warning', 'Seleziona una fazione valida.');
                    return;
                }
                payload.faction_id = factionId;
            }

            var fn = (String(action || '') === 'leave') ? mod.leave : mod.join;
            if (typeof fn !== 'function') { return; }

            var self = this;
            fn.call(mod, payload).then(function () {
                showToast('success', String(action || '') === 'leave' ? 'Adesione ritirata.' : 'Adesione registrata.');
                self.load();
                self.openDetail(eventId);
            }).catch(function (error) {
                showToast('error', errorMessage(error, 'Operazione adesione non riuscita.'));
            });
        },

        destroy: function () {
            this.stopIndicatorPoll();
            return this;
        },
        unmount: function () { return this.destroy(); }
    };

    var instance = Object.assign({}, page, extension || {});
    return instance.init();
}

globalWindow.GameSystemEventsPage = GameSystemEventsPage;
export { GameSystemEventsPage as GameSystemEventsPage };
export default GameSystemEventsPage;

