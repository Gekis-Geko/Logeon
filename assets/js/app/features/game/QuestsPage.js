(function (window) {
    'use strict';

    function resolveModule(name) {
        if (!window.RuntimeBootstrap || typeof window.RuntimeBootstrap.resolveAppModule !== 'function') {
            return null;
        }
        try { return window.RuntimeBootstrap.resolveAppModule(name); } catch (e) { return null; }
    }

    function showToast(type, body) {
        if (window.Toast && typeof window.Toast.show === 'function') {
            window.Toast.show({ type: type, body: body });
        }
    }

    function errorMessage(error, fallback) {
        if (window.Request && typeof window.Request.getErrorMessage === 'function') {
            return window.Request.getErrorMessage(error, fallback || 'Operazione non riuscita.');
        }
        if (error && typeof error.message === 'string' && error.message.trim() !== '') {
            return error.message.trim();
        }
        return fallback || 'Operazione non riuscita.';
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
        var limit = parseInt(max, 10) || 140;
        if (text.length <= limit) { return text; }
        return text.substring(0, limit) + '...';
    }

    function statusLabel(status) {
        var map = {
            locked: 'Bloccata',
            available: 'Disponibile',
            active: 'Attiva',
            completed: 'Completata',
            failed: 'Fallita',
            cancelled: 'Annullata',
            expired: 'Scaduta'
        };
        var key = String(status || '').toLowerCase();
        return map[key] || (key || '-');
    }

    function statusBadge(status) {
        var map = {
            locked: 'text-bg-secondary',
            available: 'text-bg-info',
            active: 'text-bg-primary',
            completed: 'text-bg-success',
            failed: 'text-bg-danger',
            cancelled: 'text-bg-warning',
            expired: 'text-bg-dark'
        };
        var key = String(status || '').toLowerCase();
        return map[key] || 'text-bg-secondary';
    }

    function scopeLabel(scopeType) {
        var map = {
            world: 'Mondo',
            character: 'Personaggio',
            faction: 'Fazione',
            guild: 'Gilda',
            map: 'Mappa',
            location: 'Luogo'
        };
        var key = String(scopeType || '').toLowerCase();
        return map[key] || (key || '-');
    }

    function intensityLabel(level) {
        var map = {
            CHILL: 'Chill',
            SOFT: 'Soft',
            STANDARD: 'Standard',
            HIGH: 'High',
            CRITICAL: 'Critical'
        };
        var key = String(level || '').trim().toUpperCase();
        return map[key] || (key || 'Standard');
    }

    function intensityBadgeClass(level) {
        var map = {
            CHILL: 'text-bg-success',
            SOFT: 'text-bg-info',
            STANDARD: 'text-bg-warning',
            HIGH: 'text-bg-primary',
            CRITICAL: 'text-bg-danger'
        };
        var key = String(level || '').trim().toUpperCase();
        return map[key] || 'text-bg-secondary';
    }

    function intensityMeta(row) {
        if (!row || String(row.intensity_visibility || '').toLowerCase() !== 'visible') {
            return null;
        }
        var level = String(
            row.intensity_level
            || row.effective_intensity_level
            || row.instance_intensity_level
            || row.definition_intensity_level
            || ''
        ).trim().toUpperCase();
        if (!level) { return null; }
        if (['CHILL', 'SOFT', 'STANDARD', 'HIGH', 'CRITICAL'].indexOf(level) === -1) {
            return null;
        }
        return {
            level: level,
            label: intensityLabel(level),
            badgeClass: intensityBadgeClass(level)
        };
    }

    function refreshTooltips(container) {
        if (!window.bootstrap || !window.bootstrap.Tooltip) { return; }
        var root = container && container.querySelectorAll ? container : document;
        var nodes = root.querySelectorAll('[data-bs-toggle="tooltip"]');
        for (var i = 0; i < nodes.length; i += 1) {
            window.bootstrap.Tooltip.getOrCreateInstance(nodes[i]);
        }
    }

    function isStaffFromDom() {
        var modal = document.getElementById('quest-staff-modal');
        return !!modal;
    }

    function GameQuestsPage(extension) {
        var page = {
            offcanvas: null,
            moduleApi: null,
            currentRows: [],
            selectedQuestId: 0,
            selectedQuest: null,
            tagsCatalog: [],
            staffRewardRecipientCharacterId: 0,
            indicatorPollKey: 'game.quests.feed-indicator',
            indicatorPollMs: 45000,
            indicatorStorageKey: 'game.feed.quests.seen_marker',
            indicatorLatestMarker: 0,

            resolveModuleApi: function () {
                if (this.moduleApi
                    && typeof this.moduleApi.list === 'function'
                    && typeof this.moduleApi.get === 'function'
                    && typeof this.moduleApi.join === 'function'
                    && typeof this.moduleApi.leave === 'function') {
                    return this.moduleApi;
                }
                return resolveModule('game.quests');
            },

            init: function () {
                this.offcanvas = document.getElementById('offcanvasQuests');
                if (!this.offcanvas) { return this; }
                this.detailModal = document.getElementById('quest-detail-modal');
                this.bind();
                this.bindStaff();
                this.startIndicatorPoll();
                this.pollIndicator(false);
                return this;
            },

            bind: function () {
                var self = this;
                this.offcanvas.addEventListener('show.bs.offcanvas', function () {
                    self.loadTagsCatalog();
                    self.load();
                    self.pollIndicator(true);
                });

                this.offcanvas.addEventListener('hide.bs.offcanvas', function () {
                    self.resetDetail();
                });

                this.offcanvas.addEventListener('click', function (event) {
                    var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
                    if (!trigger) { return; }
                    var action = String(trigger.getAttribute('data-action') || '');
                    if (!action) { return; }
                    event.preventDefault();

                    if (action === 'quests-reload') { self.load(); return; }
                    if (action === 'quest-open-detail') { self.openDetail(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); return; }
                    if (action === 'quest-join') { self.handleParticipation('join', parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); return; }
                    if (action === 'quest-leave') { self.handleParticipation('leave', parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); return; }
                    if (action === 'quests-open-staff') { self.openStaffModal(); return; }
                });

                if (self.detailModal) {
                    self.detailModal.addEventListener('click', function (event) {
                        var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
                        if (!trigger) { return; }
                        var action = String(trigger.getAttribute('data-action') || '');
                        if (!action) { return; }
                        event.preventDefault();
                        if (action === 'quest-join') { self.handleParticipation('join', parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); return; }
                        if (action === 'quest-leave') { self.handleParticipation('leave', parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); return; }
                    });
                }

                ['quests-status-filter', 'quests-scope-filter', 'quests-tag-filter'].forEach(function (id) {
                    var node = document.getElementById(id);
                    if (!node) { return; }
                    node.addEventListener('change', function () {
                        self.load();
                    });
                });
            },

            loadTagsCatalog: function () {
                var self = this;
                if (!window.Request || !Request.http || typeof Request.http.post !== 'function') {
                    return;
                }
                Request.http.post('/list/narrative-tags', { entity_type: 'quest_definition' }).then(function (response) {
                    var rows = response && response.dataset && Array.isArray(response.dataset) ? response.dataset : [];
                    self.tagsCatalog = rows;
                    self.populateTagFilter();
                }).catch(function () {});
            },

            populateTagFilter: function () {
                var select = document.getElementById('quests-tag-filter');
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
                if (!window.localStorage) { return 0; }
                var raw = window.localStorage.getItem(this.indicatorStorageKey);
                var value = parseInt(raw || '0', 10);
                return value > 0 ? value : 0;
            },

            writeSeenMarker: function (value) {
                if (!window.localStorage) { return; }
                var marker = parseInt(value || '0', 10);
                if (marker > 0) {
                    window.localStorage.setItem(this.indicatorStorageKey, String(marker));
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
                var labels = document.querySelectorAll('[data-feed-badge="quests"]');
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
                mod.list({ limit: 50, page: 1 }).then(function (response) {
                    var ds = response && response.dataset ? response.dataset : {};
                    var rows = Array.isArray(ds.rows) ? ds.rows : [];
                    self.processIndicatorRows(rows, markSeen === true);
                }).catch(function () {});
            },

            startIndicatorPoll: function () {
                var manager = window.AppLifecycle && typeof window.AppLifecycle.getPollManager === 'function'
                    ? window.AppLifecycle.getPollManager()
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
                var manager = window.AppLifecycle && typeof window.AppLifecycle.getPollManager === 'function'
                    ? window.AppLifecycle.getPollManager()
                    : null;
                if (!manager || typeof manager.stop !== 'function') {
                    return;
                }
                manager.stop(this.indicatorPollKey);
            },

            load: function () {
                var list = document.getElementById('quests-list');
                var empty = document.getElementById('quests-empty');
                var loading = document.getElementById('quests-loading');
                if (list) { list.innerHTML = ''; }
                if (empty) { empty.classList.add('d-none'); }
                if (loading) { loading.classList.remove('d-none'); }

                var mod = this.resolveModuleApi();
                if (!mod || typeof mod.list !== 'function') {
                    if (loading) { loading.classList.add('d-none'); }
                    return;
                }

                var statusNode = document.getElementById('quests-status-filter');
                var scopeNode = document.getElementById('quests-scope-filter');
                var tagNode = document.getElementById('quests-tag-filter');
                var payload = { limit: 60, page: 1 };
                if (statusNode && statusNode.value) { payload.status = statusNode.value; }
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
                    var ds = response && response.dataset ? response.dataset : {};
                    var rows = Array.isArray(ds.rows) ? ds.rows : [];
                    self.currentRows = rows;
                    if (!rows.length) {
                        if (empty) { empty.classList.remove('d-none'); }
                        self.resetDetail();
                        return;
                    }
                    self.renderList(rows);
                    if (self.selectedQuestId > 0) {
                        self.openDetail(self.selectedQuestId);
                    }
                }).catch(function (error) {
                    if (loading) { loading.classList.add('d-none'); }
                    showToast('error', errorMessage(error, 'Errore caricamento quest.'));
                });
            },

            renderList: function (rows) {
                var self = this;
                var list = document.getElementById('quests-list');
                if (!list) { return; }

                list.innerHTML = rows.map(function (row) {
                    var id = parseInt(row.id || '0', 10) || 0;
                    var status = String(row.instance_status || 'available');
                    var canJoin = parseInt(row.can_join || '0', 10) === 1;
                    var canLeave = parseInt(row.can_leave || '0', 10) === 1;
                    var intensity = intensityMeta(row);
                    var actionButton = '';

                    if (canJoin) {
                        actionButton = '<button type="button" class="btn btn-sm btn-primary" data-action="quest-join" data-id="' + id + '">Aderisci</button>';
                    } else if (canLeave) {
                        actionButton = '<button type="button" class="btn btn-sm btn-outline-danger" data-action="quest-leave" data-id="' + id + '">Ritira</button>';
                    } else {
                        actionButton = '<span class="small text-muted">Nessuna azione</span>';
                    }

                    return '<div class="list-group-item px-3 py-3">'
                        + '<div class="d-flex justify-content-between align-items-start gap-2 mb-1">'
                        + '  <div class="small fw-bold">' + escapeHtml(row.title || ('Quest #' + id)) + '</div>'
                        + '  <div class="d-flex flex-wrap align-items-center justify-content-end gap-1">'
                        + (intensity ? ('<span class="badge ' + intensity.badgeClass + '" data-bs-toggle="tooltip" data-bs-title="Pressione narrativa e peso delle conseguenze" title="Pressione narrativa e peso delle conseguenze">' + escapeHtml(intensity.label) + '</span>') : '')
                        + '<span class="badge ' + statusBadge(status) + '">' + escapeHtml(statusLabel(status)) + '</span>'
                        + '  </div>'
                        + '</div>'
                        + '<div class="small text-muted mb-2">'
                        + escapeHtml(scopeLabel(row.scope_type)) + (row.scope_id ? (' #' + escapeHtml(row.scope_id)) : '')
                        + '</div>'
                        + self.renderTagBadges(row.narrative_tags)
                        + (row.summary ? '<p class="small mb-2">' + escapeHtml(shortText(row.summary, 140)) + '</p>' : '')
                        + '<div class="d-flex justify-content-between align-items-center gap-2">'
                        + '  <button type="button" class="btn btn-sm btn-outline-secondary" data-action="quest-open-detail" data-id="' + id + '">Dettaglio</button>'
                        + '  ' + actionButton
                        + '</div>'
                        + '</div>';
                }).join('');
                refreshTooltips(list);
            },

            resetDetail: function () {
                this.selectedQuestId = 0;
                this.selectedQuest = null;
                var body = document.getElementById('quest-detail-modal-body');
                if (body) { body.innerHTML = ''; }
                var el = document.getElementById('quest-detail-modal');
                if (el && window.bootstrap && window.bootstrap.Modal) {
                    var modal = window.bootstrap.Modal.getInstance(el);
                    if (modal) { modal.hide(); }
                }
            },

            openDetail: function (questId) {
                questId = parseInt(questId || '0', 10) || 0;
                if (questId <= 0) { return; }
                var mod = this.resolveModuleApi();
                if (!mod || typeof mod.get !== 'function') { return; }

                var self = this;
                mod.get({ quest_definition_id: questId }).then(function (response) {
                    var ds = response && response.dataset ? response.dataset : null;
                    if (!ds || !ds.definition) {
                        showToast('warning', 'Dettaglio quest non disponibile.');
                        return;
                    }
                    self.selectedQuestId = questId;
                    self.selectedQuest = ds;
                    self.renderDetail(ds);
                }).catch(function (error) {
                    showToast('error', errorMessage(error, 'Impossibile aprire il dettaglio quest.'));
                });
            },

            renderDetail: function (ds) {
                var el = document.getElementById('quest-detail-modal');
                var detail = document.getElementById('quest-detail-modal-body');
                if (!el || !detail) { return; }

                var def = ds.definition || {};
                var instance = ds.instance || null;
                var steps = Array.isArray(ds.steps) ? ds.steps : [];
                var status = instance ? String(instance.current_status || 'available') : 'available';
                var canJoin = parseInt(ds.can_join || '0', 10) === 1;
                var canLeave = parseInt(ds.can_leave || '0', 10) === 1;
                var intensity = intensityMeta({
                    intensity_visibility: ds.intensity_visibility || def.intensity_visibility,
                    intensity_level: ds.intensity_level || def.intensity_level,
                    effective_intensity_level: ds.effective_intensity_level || instance && instance.effective_intensity_level
                });

                var actionButton = '';
                if (canJoin) {
                    actionButton = '<button type="button" class="btn btn-sm btn-primary" data-action="quest-join" data-id="' + (parseInt(def.id || '0', 10) || 0) + '">Aderisci</button>';
                } else if (canLeave) {
                    actionButton = '<button type="button" class="btn btn-sm btn-outline-danger" data-action="quest-leave" data-id="' + (parseInt(def.id || '0', 10) || 0) + '">Ritira adesione</button>';
                }

                var stepsHtml = '';
                if (steps.length) {
                    stepsHtml = '<div class="text-muted mt-3 mb-2">Step</div><ul class="list-group list-group-flush mb-3">';
                    for (var i = 0; i < steps.length; i += 1) {
                        var step = steps[i] || {};
                        var stepStatus = String(step.progress_status || 'locked');
                        stepsHtml += '<li class="list-group-item px-0 py-1 d-flex justify-content-between align-items-start">'
                            + '<span class="small">' + escapeHtml(step.step_title || step.title || ('Step #' + (i + 1))) + '</span>'
                            + '<span class="badge ' + statusBadge(stepStatus) + '">' + escapeHtml(statusLabel(stepStatus)) + '</span>'
                            + '</li>';
                    }
                    stepsHtml += '</ul>';
                } else {
                    stepsHtml = '<div class="small text-muted">Nessuno step configurato.</div>';
                }

                detail.innerHTML = '<h4>' + escapeHtml(def.title || ('Quest #' + (def.id || '-'))) + '</h4>'
                    + '<div class="small text-muted mb-1">Stato: <b>' + escapeHtml(statusLabel(status)) + '</b> - Ambito: <b>' + escapeHtml(scopeLabel(def.scope_type)) + '</b></div>'
                    + (intensity ? ('<div class="small text-muted mb-2">Intensita narrativa: <span class="badge ' + intensity.badgeClass + '" data-bs-toggle="tooltip" data-bs-title="Pressione narrativa e peso delle conseguenze" title="Pressione narrativa e peso delle conseguenze">' + escapeHtml(intensity.label) + '</span></div>') : '')
                    + this.renderTagBadges(def.narrative_tags)
                    + (def.summary ? '<p class="mb-2">' + escapeHtml(def.summary) + '</p>' : '')
                    + (def.description ? '<div class="mb-2">' + escapeHtml(def.description) + '</div>' : '')
                    + stepsHtml
                    + '<hr/>'
                    + '<div class="d-flex justify-content-end">' + actionButton + '</div>';

                if (window.bootstrap && window.bootstrap.Modal) {
                    window.bootstrap.Modal.getOrCreateInstance(el).show();
                }
                refreshTooltips(detail);
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

            handleParticipation: function (action, questId) {
                questId = parseInt(questId || '0', 10) || 0;
                if (questId <= 0) { return; }
                var mod = this.resolveModuleApi();
                if (!mod) { return; }
                var fn = (String(action || '') === 'leave') ? mod.leave : mod.join;
                if (typeof fn !== 'function') { return; }

                var self = this;
                fn.call(mod, { quest_definition_id: questId }).then(function () {
                    showToast('success', String(action || '') === 'leave' ? 'Adesione ritirata.' : 'Adesione registrata.');
                    self.load();
                    self.openDetail(questId);
                }).catch(function (error) {
                    showToast('error', errorMessage(error, 'Operazione quest non riuscita.'));
                });
            },

            bindStaff: function () {
                if (!isStaffFromDom()) { return; }
                var self = this;
                var modal = document.getElementById('quest-staff-modal');
                if (!modal) { return; }
                this.bindStaffRewardAutocomplete();

                modal.addEventListener('show.bs.modal', function () {
                    self.loadStaffInstances();
                });

                modal.addEventListener('click', function (event) {
                    var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
                    if (!trigger) { return; }
                    var action = String(trigger.getAttribute('data-action') || '');
                    if (!action) { return; }
                    event.preventDefault();

                    if (action === 'quest-staff-reload') { self.loadStaffInstances(); return; }
                    if (action === 'quest-staff-select') { self.selectStaffInstance(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); return; }
                    if (action === 'quest-staff-set-status') { self.staffSetStatus(); return; }
                    if (action === 'quest-staff-confirm-step') { self.staffConfirmStep(); return; }
                    if (action === 'quest-staff-force-progress') { self.staffForceProgress(); return; }
                    if (action === 'quest-staff-load-closure') { self.loadStaffClosure(); return; }
                    if (action === 'quest-staff-finalize') { self.staffFinalize(); return; }
                    if (action === 'quest-staff-pick-item') { self.pickRewardSuggestion(trigger); return; }
                });
            },

            openStaffModal: function () {
                var el = document.getElementById('quest-staff-modal');
                if (!el || !window.bootstrap || !window.bootstrap.Modal) { return; }
                var modal = window.bootstrap.Modal.getOrCreateInstance(el);
                var offcanvasEl = this.offcanvas || document.getElementById('offcanvasQuests');
                if (!offcanvasEl || !window.bootstrap.Offcanvas) {
                    modal.show();
                    return;
                }

                var offcanvas = window.bootstrap.Offcanvas.getOrCreateInstance(offcanvasEl);
                if (!offcanvasEl.classList.contains('show')) {
                    modal.show();
                    return;
                }

                var shown = false;
                var onHidden = function () {
                    if (shown) { return; }
                    shown = true;
                    offcanvasEl.removeEventListener('hidden.bs.offcanvas', onHidden);
                    modal.show();
                };
                offcanvasEl.addEventListener('hidden.bs.offcanvas', onHidden);
                offcanvas.hide();
            },

            loadStaffInstances: function () {
                var mod = this.resolveModuleApi();
                if (!mod || typeof mod.staffInstancesList !== 'function') { return; }

                var statusFilter = document.getElementById('quest-staff-status-filter');
                var assigneeFilter = document.getElementById('quest-staff-assignee-filter');
                var payload = {
                    limit: 80,
                    page: 1,
                    status: statusFilter ? (statusFilter.value || '') : '',
                    assignee_type: assigneeFilter ? (assigneeFilter.value || '') : ''
                };

                var box = document.getElementById('quest-staff-list');
                if (box) { box.innerHTML = '<div class="small text-muted">Caricamento...</div>'; }

                mod.staffInstancesList(payload).then(function (response) {
                    var ds = response && response.dataset ? response.dataset : {};
                    var rows = Array.isArray(ds.rows) ? ds.rows : [];
                    if (!box) { return; }
                    if (!rows.length) {
                        box.innerHTML = '<div class="small text-muted">Nessuna istanza trovata.</div>';
                        return;
                    }
                    box.innerHTML = rows.map(function (row) {
                        var id = parseInt(row.id || '0', 10) || 0;
                        var intensity = intensityMeta(row);
                        return '<button type="button" class="list-group-item list-group-item-action" data-action="quest-staff-select" data-id="' + id + '">'
                            + '<div class="d-flex justify-content-between align-items-center">'
                            + '<span><b>#' + id + '</b> ' + escapeHtml(row.quest_title || row.quest_slug || 'Quest') + '</span>'
                            + '<span class="badge ' + statusBadge(row.current_status) + '">' + escapeHtml(statusLabel(row.current_status)) + '</span>'
                            + '</div>'
                            + '<div class="small text-muted">' + escapeHtml(row.assignee_label || '-') + (intensity ? (' · ' + intensityLabel(intensity.level)) : '') + '</div>'
                            + '</button>';
                    }).join('');
                }).catch(function (error) {
                    if (box) { box.innerHTML = '<div class="small text-danger">' + escapeHtml(errorMessage(error, 'Errore caricamento istanze.')) + '</div>'; }
                });
            },

            selectStaffInstance: function (instanceId) {
                instanceId = parseInt(instanceId || '0', 10) || 0;
                if (instanceId <= 0) { return; }
                var instanceInput = document.getElementById('quest-staff-instance-id');
                var instanceLabel = document.getElementById('quest-staff-instance-label');
                if (instanceInput) { instanceInput.value = String(instanceId); }
                if (instanceLabel) { instanceLabel.value = 'Istanza #' + instanceId; }
                this.populateStaffStepOptions([]);
                showToast('info', 'Istanza #' + instanceId + ' selezionata.');
                this.loadStaffClosure();
            },

            staffSetStatus: function () {
                var mod = this.resolveModuleApi();
                if (!mod || typeof mod.staffStatusSet !== 'function') { return; }
                var instanceId = parseInt((document.getElementById('quest-staff-instance-id') || {}).value || '0', 10) || 0;
                var status = String((document.getElementById('quest-staff-instance-status') || {}).value || '').trim();
                if (instanceId <= 0 || !status) {
                    showToast('warning', 'Seleziona prima una istanza valida.');
                    return;
                }
                var self = this;
                mod.staffStatusSet({ quest_instance_id: instanceId, status: status }).then(function () {
                    showToast('success', 'Stato istanza aggiornato.');
                    self.loadStaffInstances();
                    self.load();
                }).catch(function (error) {
                    showToast('error', errorMessage(error, 'Aggiornamento stato non riuscito.'));
                });
            },

            staffConfirmStep: function () {
                var mod = this.resolveModuleApi();
                if (!mod || typeof mod.staffStepConfirm !== 'function') { return; }
                var instanceId = parseInt((document.getElementById('quest-staff-instance-id') || {}).value || '0', 10) || 0;
                var stepId = parseInt((document.getElementById('quest-staff-step-id') || {}).value || '0', 10) || 0;
                var status = String((document.getElementById('quest-staff-step-status') || {}).value || '').trim();
                if (instanceId <= 0 || stepId <= 0) {
                    showToast('warning', 'Seleziona una istanza e uno step validi.');
                    return;
                }
                var self = this;
                mod.staffStepConfirm({
                    quest_instance_id: instanceId,
                    step_instance_id: stepId,
                    status: status || 'completed'
                }).then(function () {
                    showToast('success', 'Step aggiornato.');
                    self.loadStaffInstances();
                    self.load();
                }).catch(function (error) {
                    showToast('error', errorMessage(error, 'Conferma step non riuscita.'));
                });
            },

            staffForceProgress: function () {
                var mod = this.resolveModuleApi();
                if (!mod || typeof mod.staffForceProgress !== 'function') { return; }
                var instanceId = parseInt((document.getElementById('quest-staff-instance-id') || {}).value || '0', 10) || 0;
                if (instanceId <= 0) {
                    showToast('warning', 'Seleziona una istanza valida.');
                    return;
                }
                var self = this;
                mod.staffForceProgress({ quest_instance_id: instanceId }).then(function () {
                    showToast('success', 'Avanzamento forzato eseguito.');
                    self.loadStaffInstances();
                    self.load();
                }).catch(function (error) {
                    showToast('error', errorMessage(error, 'Forzatura avanzamento non riuscita.'));
                });
            },

            bindStaffRewardAutocomplete: function () {
                var self = this;
                var input = document.getElementById('quest-staff-reward-item-label');
                if (!input) { return; }
                input.addEventListener('input', function () {
                    var hidden = document.getElementById('quest-staff-reward-item-id');
                    if (hidden) { hidden.value = '0'; }
                    if (self.__questStaffItemTimer) {
                        window.clearTimeout(self.__questStaffItemTimer);
                        self.__questStaffItemTimer = null;
                    }
                    self.__questStaffItemTimer = window.setTimeout(function () {
                        self.searchRewardItems();
                    }, 200);
                });

                document.addEventListener('click', function (event) {
                    var target = event.target;
                    if (!target || !target.closest) { return; }
                    var box = document.getElementById('quest-staff-reward-item-suggestions');
                    if (!box || box.classList.contains('d-none')) { return; }
                    if (target.closest('#quest-staff-reward-item-suggestions') || target.closest('#quest-staff-reward-item-label')) {
                        return;
                    }
                    self.clearRewardSuggestions();
                });
            },

            searchRewardItems: function () {
                var input = document.getElementById('quest-staff-reward-item-label');
                var box = document.getElementById('quest-staff-reward-item-suggestions');
                if (!input || !box || !window.Request || !Request.http || typeof Request.http.post !== 'function') {
                    return;
                }
                var query = String(input.value || '').trim().toLowerCase();
                if (query.length < 2) {
                    this.clearRewardSuggestions();
                    return;
                }
                Request.http.post('/list/items', {}).then(function (response) {
                    var rows = response && Array.isArray(response.dataset) ? response.dataset : [];
                    rows = rows.map(function (row) {
                        var id = parseInt(row.id || '0', 10) || 0;
                        var label = String(row.name || '').trim();
                        return { id: id, label: label };
                    }).filter(function (row) {
                        return row.id > 0 && row.label && row.label.toLowerCase().indexOf(query) !== -1;
                    }).slice(0, 20);

                    if (!rows.length) {
                        box.classList.add('d-none');
                        box.innerHTML = '';
                        return;
                    }

                    box.innerHTML = rows.map(function (row) {
                        return '<button type="button" class="list-group-item list-group-item-action small py-1" data-action="quest-staff-pick-item" data-id="' + row.id + '" data-label="' + escapeHtml(row.label) + '">' + escapeHtml(row.label) + '</button>';
                    }).join('');
                    box.classList.remove('d-none');
                }).catch(function () {
                    box.classList.add('d-none');
                    box.innerHTML = '';
                });
            },

            pickRewardSuggestion: function (node) {
                if (!node) { return; }
                var id = parseInt(node.getAttribute('data-id') || '0', 10) || 0;
                var label = String(node.getAttribute('data-label') || '');
                if (id <= 0 || label === '') { return; }
                var input = document.getElementById('quest-staff-reward-item-label');
                var hidden = document.getElementById('quest-staff-reward-item-id');
                if (input) { input.value = label; }
                if (hidden) { hidden.value = String(id); }
                this.clearRewardSuggestions();
            },

            clearRewardSuggestions: function () {
                var box = document.getElementById('quest-staff-reward-item-suggestions');
                if (!box) { return; }
                box.classList.add('d-none');
                box.innerHTML = '';
            },

            loadStaffClosure: function () {
                var mod = this.resolveModuleApi();
                if (!mod || typeof mod.staffClosureGet !== 'function') { return; }
                var instanceId = parseInt((document.getElementById('quest-staff-instance-id') || {}).value || '0', 10) || 0;
                if (instanceId <= 0) {
                    showToast('warning', 'Seleziona prima una istanza.');
                    return;
                }
                var self = this;
                mod.staffClosureGet({ quest_instance_id: instanceId }).then(function (response) {
                    var ds = response && response.dataset ? response.dataset : {};
                    var report = ds.closure_report || null;
                    var instance = ds.instance || null;
                    self.populateStaffStepOptions(ds.steps || [], instance && instance.current_step_id ? instance.current_step_id : 0);
                    self.populateStaffClosure(report, instance);
                    self.renderStaffRewards(ds.rewards || []);
                }).catch(function (error) {
                    showToast('error', errorMessage(error, 'Impossibile caricare i dati di chiusura quest.'));
                });
            },

            populateStaffStepOptions: function (steps, selectedStepId) {
                var select = document.getElementById('quest-staff-step-id');
                if (!select) { return; }

                var rows = Array.isArray(steps) ? steps : [];
                var selected = parseInt(selectedStepId || '0', 10) || 0;
                var fallbackActive = 0;
                var fallbackAny = 0;

                select.innerHTML = '<option value="">Seleziona uno step</option>';
                for (var i = 0; i < rows.length; i += 1) {
                    var row = rows[i] || {};
                    var stepId = parseInt(row.id || row.step_instance_id || '0', 10) || 0;
                    if (stepId <= 0) { continue; }
                    var status = String(row.progress_status || '');
                    var title = String(row.step_title || ('Step #' + stepId));

                    var option = document.createElement('option');
                    option.value = String(stepId);
                    option.textContent = title + ' [' + statusLabel(status || '-') + ']';
                    select.appendChild(option);

                    if (fallbackAny <= 0) {
                        fallbackAny = stepId;
                    }
                    if (fallbackActive <= 0 && (status === 'active' || status === 'pending')) {
                        fallbackActive = stepId;
                    }
                }

                if (selected > 0) {
                    select.value = String(selected);
                }
                if (String(select.value || '') === '' && fallbackActive > 0) {
                    select.value = String(fallbackActive);
                }
                if (String(select.value || '') === '' && fallbackAny > 0) {
                    select.value = String(fallbackAny);
                }
            },

            populateStaffClosure: function (report, instance) {
                var finalStatus = document.getElementById('quest-staff-final-status');
                var closureType = document.getElementById('quest-staff-closure-type');
                var playerVisible = document.getElementById('quest-staff-player-visible');
                var outcomeLabel = document.getElementById('quest-staff-outcome-label');
                var summaryPublic = document.getElementById('quest-staff-summary-public');
                var summaryPrivate = document.getElementById('quest-staff-summary-private');
                var staffNotes = document.getElementById('quest-staff-notes');
                var rewardExp = document.getElementById('quest-staff-reward-exp');
                var rewardItemLabel = document.getElementById('quest-staff-reward-item-label');
                var rewardItemId = document.getElementById('quest-staff-reward-item-id');
                var rewardItemQty = document.getElementById('quest-staff-reward-item-qty');

                this.staffRewardRecipientCharacterId = 0;
                if (instance && String(instance.assignee_type || '').toLowerCase() === 'character') {
                    this.staffRewardRecipientCharacterId = parseInt(instance.assignee_id || '0', 10) || 0;
                }

                if (finalStatus) { finalStatus.value = String((instance && instance.current_status) ? instance.current_status : 'completed'); }
                if (closureType) { closureType.value = String((report && report.closure_type) ? report.closure_type : 'success'); }
                if (playerVisible) { playerVisible.value = String((report && parseInt(report.player_visible || '0', 10) === 0) ? '0' : '1'); }
                if (outcomeLabel) { outcomeLabel.value = String((report && report.outcome_label) ? report.outcome_label : ''); }
                if (summaryPublic) { summaryPublic.value = String((report && report.summary_public) ? report.summary_public : ''); }
                if (summaryPrivate) { summaryPrivate.value = String((report && report.summary_private) ? report.summary_private : ''); }
                if (staffNotes) { staffNotes.value = String((report && report.staff_notes) ? report.staff_notes : ''); }
                if (rewardExp) { rewardExp.value = ''; }
                if (rewardItemLabel) { rewardItemLabel.value = ''; }
                if (rewardItemId) { rewardItemId.value = '0'; }
                if (rewardItemQty) { rewardItemQty.value = '1'; }
            },

            renderStaffRewards: function (rows) {
                var box = document.getElementById('quest-staff-rewards-list');
                if (!box) { return; }
                var rewards = Array.isArray(rows) ? rows : [];
                if (!rewards.length) {
                    box.innerHTML = '<div class="small text-muted">Nessuna ricompensa registrata.</div>';
                    return;
                }
                box.innerHTML = rewards.map(function (row) {
                    return '<div class="list-group-item px-2 py-2">'
                        + '<div class="small fw-semibold">' + escapeHtml(row.reward_label || '-') + '</div>'
                        + '<div class="small text-muted">' + escapeHtml(row.visibility || 'public') + (row.assigned_at ? (' · ' + escapeHtml(row.assigned_at)) : '') + '</div>'
                        + '</div>';
                }).join('');
            },

            collectFinalizePayload: function () {
                var instanceId = parseInt((document.getElementById('quest-staff-instance-id') || {}).value || '0', 10) || 0;
                var finalStatus = String((document.getElementById('quest-staff-final-status') || {}).value || 'completed').trim();
                var closureType = String((document.getElementById('quest-staff-closure-type') || {}).value || 'success').trim();
                var playerVisible = parseInt((document.getElementById('quest-staff-player-visible') || {}).value || '1', 10) === 0 ? 0 : 1;
                var outcomeLabel = String((document.getElementById('quest-staff-outcome-label') || {}).value || '').trim();
                var summaryPublic = String((document.getElementById('quest-staff-summary-public') || {}).value || '').trim();
                var summaryPrivate = String((document.getElementById('quest-staff-summary-private') || {}).value || '').trim();
                var staffNotes = String((document.getElementById('quest-staff-notes') || {}).value || '').trim();

                var rewards = [];
                var recipientId = parseInt(this.staffRewardRecipientCharacterId || '0', 10) || 0;
                var expValue = parseFloat(String((document.getElementById('quest-staff-reward-exp') || {}).value || '0').replace(',', '.')) || 0;
                if (expValue > 0 && recipientId > 0) {
                    rewards.push({
                        recipient_type: 'character',
                        recipient_id: recipientId,
                        reward_type: 'experience',
                        reward_value: expValue,
                        visibility: String((document.getElementById('quest-staff-reward-visibility') || {}).value || 'public')
                    });
                }

                var itemId = parseInt((document.getElementById('quest-staff-reward-item-id') || {}).value || '0', 10) || 0;
                var itemQty = parseInt((document.getElementById('quest-staff-reward-item-qty') || {}).value || '1', 10) || 1;
                if (itemId > 0 && recipientId > 0) {
                    rewards.push({
                        recipient_type: 'character',
                        recipient_id: recipientId,
                        reward_type: 'item',
                        reward_reference_id: itemId,
                        reward_value: itemQty > 0 ? itemQty : 1,
                        visibility: String((document.getElementById('quest-staff-reward-visibility') || {}).value || 'public')
                    });
                }

                return {
                    quest_instance_id: instanceId,
                    final_status: finalStatus,
                    closure_type: closureType,
                    player_visible: playerVisible,
                    outcome_label: outcomeLabel,
                    summary_public: summaryPublic,
                    summary_private: summaryPrivate,
                    staff_notes: staffNotes,
                    rewards: rewards
                };
            },

            staffFinalize: function () {
                var mod = this.resolveModuleApi();
                if (!mod || typeof mod.staffClosureFinalize !== 'function') { return; }

                var payload = this.collectFinalizePayload();
                var expRaw = parseFloat(String((document.getElementById('quest-staff-reward-exp') || {}).value || '0').replace(',', '.')) || 0;
                var itemRaw = parseInt((document.getElementById('quest-staff-reward-item-id') || {}).value || '0', 10) || 0;
                if ((payload.quest_instance_id || 0) <= 0) {
                    showToast('warning', 'Seleziona prima una istanza.');
                    return;
                }
                if ((expRaw > 0 || itemRaw > 0) && (this.staffRewardRecipientCharacterId || 0) <= 0) {
                    showToast('warning', 'Le ricompense v1 sono disponibili solo per quest assegnate a personaggio.');
                    return;
                }

                var self = this;
                mod.staffClosureFinalize(payload).then(function () {
                    showToast('success', 'Chiusura quest salvata con successo.');
                    self.loadStaffInstances();
                    self.loadStaffClosure();
                    self.load();
                }).catch(function (error) {
                    showToast('error', errorMessage(error, 'Chiusura quest non riuscita.'));
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

    window.GameQuestsPage = GameQuestsPage;
})(window);
