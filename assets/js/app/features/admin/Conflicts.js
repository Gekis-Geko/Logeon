(function () {
    'use strict';

    var AdminConflicts = {
        initialized: false,
        root: null,
        grid: null,
        rowsById: {},
        switches: {},
        currentId: 0,
        settingsModal: null,
        openModal: null,
        detailModal: null,
        quickModal: null,
        editLogModal: null,
        overrideRollModal: null,
        currentParticipants: [],
        currentActions: [],
        locationsCache: null,
        locationsLoading: false,
        filterLocationTimer: null,
        openLocationTimer: null,
        rollTargetTimer: null,
        overrideActorTimer: null,

        init: function () {
            if (this.initialized) return this;

            this.root = document.querySelector('#admin-page [data-admin-page="conflicts"]');
            if (!this.root || !document.getElementById('grid-admin-conflicts')) return this;

            if (typeof bootstrap !== 'undefined' && bootstrap && typeof bootstrap.Modal === 'function') {
                var settingsNode = this.root.querySelector('#admin-conflicts-settings-modal');
                var openNode = this.root.querySelector('#admin-conflict-open-modal');
                var quickNode = this.root.querySelector('#admin-conflict-quick-modal');
                var detailNode = this.root.querySelector('#admin-conflict-detail-modal');
                var editLogNode = this.root.querySelector('#admin-conflict-edit-log-modal');
                var overrideNode = this.root.querySelector('#admin-conflict-override-roll-modal');
                if (settingsNode) this.settingsModal = new bootstrap.Modal(settingsNode);
                if (openNode) this.openModal = new bootstrap.Modal(openNode);
                if (quickNode) this.quickModal = new bootstrap.Modal(quickNode);
                if (detailNode) this.detailModal = new bootstrap.Modal(detailNode);
                if (editLogNode) this.editLogModal = new bootstrap.Modal(editLogNode);
                if (overrideNode) this.overrideRollModal = new bootstrap.Modal(overrideNode);
            }

            this.initSwitches();
            this.bind();
            this.initGrid();
            this.loadSettings();
            this.reload();

            this.initialized = true;
            return this;
        },

        initSwitches: function () {
            if (typeof window.SwitchGroup !== 'function') return;
            this.mountSwitch('#admin-conflicts-mode', {
                trueValue: 'narrative',
                falseValue: 'random',
                trueLabel: 'Narrativo',
                falseLabel: 'Random',
                defaultValue: 'narrative'
            });
            this.mountSwitch('#admin-conflict-participant-active', {
                trueValue: '1',
                falseValue: '0',
                preset: 'activeInactive',
                defaultValue: '1'
            });
            this.mountSwitch('#admin-conflicts-chat-compact-events', {
                trueValue: '1',
                falseValue: '0',
                trueLabel: 'Compatto',
                falseLabel: 'Esteso',
                defaultValue: '1'
            });
        },

        mountSwitch: function (selector, options) {
            var node = this.root.querySelector(selector);
            if (!node) return null;
            this.switches[selector] = window.SwitchGroup(node, Object.assign({ showLabels: true }, options || {}));
            return this.switches[selector];
        },

        bind: function () {
            var self = this;
            this.root.addEventListener('click', function (event) {
                var filterSuggestion = event.target && event.target.closest ? event.target.closest('[data-role="admin-conflicts-filter-location-suggestion"]') : null;
                if (filterSuggestion) {
                    event.preventDefault();
                    self.pickFilterLocation(filterSuggestion);
                    return;
                }

                var openSuggestion = event.target && event.target.closest ? event.target.closest('[data-role="admin-conflicts-open-location-suggestion"]') : null;
                if (openSuggestion) {
                    event.preventDefault();
                    self.pickOpenLocation(openSuggestion);
                    return;
                }

                var rollTargetSuggestion = event.target && event.target.closest ? event.target.closest('[data-role="admin-conflicts-roll-target-suggestion"]') : null;
                if (rollTargetSuggestion) {
                    event.preventDefault();
                    self.pickRollTarget(rollTargetSuggestion);
                    return;
                }

                var overrideActorSuggestion = event.target && event.target.closest ? event.target.closest('[data-role="admin-conflicts-override-actor-suggestion"]') : null;
                if (overrideActorSuggestion) {
                    event.preventDefault();
                    self.pickOverrideActor(overrideActorSuggestion);
                    return;
                }

                var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
                if (!trigger) return;
                var action = String(trigger.getAttribute('data-action') || '').trim();
                if (!action) return;
                event.preventDefault();

                if (action === 'admin-conflicts-open-settings-modal') return self.openSettingsModal();
                if (action === 'admin-conflicts-reload') return self.reloadAll();
                if (action === 'admin-conflicts-save-settings') return self.saveSettings();
                if (action === 'admin-conflicts-filter-clear') return self.clearFilters();
                if (action === 'admin-conflicts-open-modal') return self.openCreateModal();
                if (action === 'admin-conflict-open-save') return self.openConflict();
                if (action === 'admin-conflict-quick-open') return self.openQuickFromTrigger(trigger);
                if (action === 'admin-conflict-quick-to-detail') return self.quickToDetail();
                if (action === 'admin-conflict-view') return self.openDetailFromTrigger(trigger);
                if (action === 'admin-conflict-detail-reload') return self.reloadCurrent();
                if (action === 'admin-conflict-participant-add') return self.addParticipant();
                if (action === 'admin-conflict-action-add') return self.addAction();
                if (action === 'admin-conflict-status-set') return self.setStatus();
                if (action === 'admin-conflict-roll-run') return self.runRoll();
                if (action === 'admin-conflict-force-open') return self.forceOpen();
                if (action === 'admin-conflict-force-close') return self.forceClose();
                if (action === 'admin-conflict-edit-log') return self.openEditLogModal();
                if (action === 'admin-conflict-edit-log-save') return self.saveEditLogFromModal();
                if (action === 'admin-conflict-override-roll') return self.openOverrideRollModal();
                if (action === 'admin-conflict-override-roll-save') return self.saveOverrideRollFromModal();
                if (action === 'admin-conflict-resolve') return self.resolveConflict();
                if (action === 'admin-conflict-close') return self.closeConflict();
            });

            var status = this.root.querySelector('#admin-conflicts-filter-status');
            var location = this.root.querySelector('#admin-conflicts-filter-location-id');
            var origin = this.root.querySelector('#admin-conflicts-filter-origin');
            var locationName = this.root.querySelector('#admin-conflicts-filter-location-name');
            if (status) status.addEventListener('change', function () { self.reload(); });
            if (location) location.addEventListener('change', function () { self.reload(); });
            if (origin) origin.addEventListener('change', function () { self.reload(); });
            if (locationName) {
                locationName.addEventListener('input', function () { self.searchFilterLocations(); });
            }

            var openLocationName = this.root.querySelector('#admin-conflict-open-form [name="location_name"]');
            if (openLocationName) {
                openLocationName.addEventListener('input', function () { self.searchOpenLocations(); });
            }

            var rollType = this.root.querySelector('#admin-conflict-roll-type');
            if (rollType) rollType.addEventListener('change', function () { self.syncRollFields(); });
            this.syncRollFields();

            var rollTargetName = this.root.querySelector('#admin-conflict-roll-target-name');
            if (rollTargetName) {
                rollTargetName.addEventListener('input', function () { self.searchRollTargets(); });
            }

            var editLogActionSelect = this.root.querySelector('#admin-conflict-edit-log-action-id');
            if (editLogActionSelect) {
                editLogActionSelect.addEventListener('change', function () { self.prefillEditLogBody(); });
            }

            var overrideActorName = this.root.querySelector('#admin-conflict-override-actor-name');
            if (overrideActorName) {
                overrideActorName.addEventListener('input', function () { self.searchOverrideActors(); });
            }

            var participantName = this.root.querySelector('#admin-conflict-participant-name');
            if (participantName) {
                participantName.addEventListener('input', function () {
                    var query = String(participantName.value || '').trim();
                    var box = self.root.querySelector('#admin-conflict-participant-suggestions');
                    if (!box) return;
                    if (!query || query.length < 2) {
                        box.classList.add('d-none');
                        box.innerHTML = '';
                        self.setv('#admin-conflict-participant-id', '');
                        return;
                    }
                    self.post('/list/characters/search', { query: query }, function (r) {
                        self.renderParticipantSuggestions(r ? r.dataset : []);
                    });
                });
            }

            document.addEventListener('click', function (e) {
                var box = self.root ? self.root.querySelector('#admin-conflict-participant-suggestions') : null;
                if (!box) return;
                var nameInput = self.root.querySelector('#admin-conflict-participant-name');
                if (!e.target.closest || (!e.target.closest('#admin-conflict-participant-suggestions') && e.target !== nameInput)) {
                    box.classList.add('d-none');
                    box.innerHTML = '';
                }

                var filterBox = self.root ? self.root.querySelector('#admin-conflicts-filter-location-suggestions') : null;
                var filterName = self.root ? self.root.querySelector('#admin-conflicts-filter-location-name') : null;
                if (filterBox && (!e.target.closest || (!e.target.closest('#admin-conflicts-filter-location-suggestions') && e.target !== filterName))) {
                    filterBox.classList.add('d-none');
                    filterBox.innerHTML = '';
                }

                var openBox = self.root ? self.root.querySelector('#admin-conflict-open-location-suggestions') : null;
                var openName = self.root ? self.root.querySelector('#admin-conflict-open-form [name=\"location_name\"]') : null;
                if (openBox && (!e.target.closest || (!e.target.closest('#admin-conflict-open-location-suggestions') && e.target !== openName))) {
                    openBox.classList.add('d-none');
                    openBox.innerHTML = '';
                }

                var targetBox = self.root ? self.root.querySelector('#admin-conflict-roll-target-suggestions') : null;
                var targetName = self.root ? self.root.querySelector('#admin-conflict-roll-target-name') : null;
                if (targetBox && (!e.target.closest || (!e.target.closest('#admin-conflict-roll-target-suggestions') && e.target !== targetName))) {
                    targetBox.classList.add('d-none');
                    targetBox.innerHTML = '';
                }

                var overrideBox = self.root ? self.root.querySelector('#admin-conflict-override-actor-suggestions') : null;
                var overrideName = self.root ? self.root.querySelector('#admin-conflict-override-actor-name') : null;
                if (overrideBox && (!e.target.closest || (!e.target.closest('#admin-conflict-override-actor-suggestions') && e.target !== overrideName))) {
                    overrideBox.classList.add('d-none');
                    overrideBox.innerHTML = '';
                }
            });
        },

        initGrid: function () {
            var self = this;
            this.grid = new Datagrid('grid-admin-conflicts', {
                name: 'AdminConflicts',
                autoindex: 'id',
                orderable: true,
                thead: true,
                handler: { url: '/conflicts/list', action: 'list' },
                nav: { display: 'bottom', urlupdate: 0, results: 20, page: 1 },
                onGetDataSuccess: function (response) {
                    var rows = response && Array.isArray(response.dataset) ? response.dataset : (self.grid.dataset || []);
                    self.setRows(rows);
                },
                onGetDataError: function () { self.setRows([]); },
                columns: [
                    { label: 'ID', field: 'id', sortable: true },
                    {
                        label: 'Conflitto', field: 'created_at', sortable: true, style: { textAlign: 'left' },
                        format: function (r) {
                            return '<div><b>#' + self.e(r.id || '-') + '</b> <span class="small text-muted">' + self.locLabel(r.location_id) + '</span></div>'
                                + '<div class="small text-muted">Creato: ' + self.fmt(r.created_at) + '</div>'
                                + '<div class="small text-muted">Risolto: ' + self.fmt(r.resolved_at) + '</div>';
                        }
                    },
                    {
                        label: 'Modalita', field: 'resolution_mode', sortable: true,
                        format: function (r) { return self.modeBadge(r.resolution_mode) + '<div class="small mt-1">' + self.authBadge(r.resolution_authority) + '</div>'; }
                    },
                    {
                        label: 'Origine', field: 'conflict_origin', sortable: true,
                        format: function (r) {
                            var origin = String(r.conflict_origin || 'admin').toLowerCase();
                            if (origin === 'chat') return '<span class="badge text-bg-info">Chat</span>';
                            if (origin === 'system') return '<span class="badge text-bg-secondary">Sistema</span>';
                            return '<span class="badge text-bg-light text-dark">Admin</span>';
                        }
                    },
                    { label: 'Stato', field: 'status', sortable: true, format: function (r) { return self.statusBadge(r.status); } },
                    {
                        label: 'Attivita', sortable: false,
                        format: function (r) {
                            return '<span class="badge text-bg-light text-dark me-1">Partecipanti: ' + (parseInt(r.participants_count || 0, 10) || 0) + '</span>'
                                + '<span class="badge text-bg-secondary">Tiri: ' + (parseInt(r.rolls_count || 0, 10) || 0) + '</span>';
                        }
                    },
                    {
                        label: 'Azioni',
                        sortable: false,
                        style: { textAlign: 'left' },
                        format: function (r) {
                            var id = parseInt(r.id || 0, 10) || 0;
                            if (id <= 0) return '-';
                            return '<div class="d-flex flex-wrap gap-1">'
                                + '<button class="btn btn-sm btn-outline-secondary" data-action="admin-conflict-quick-open" data-id="' + id + '">Lettura rapida</button>'
                                + '<button class="btn btn-sm btn-outline-primary" data-action="admin-conflict-view" data-id="' + id + '">Dettaglio</button>'
                                + '</div>';
                        }
                    }
                ]
            });
        },

        setRows: function (rows) {
            this.rowsById = {};
            for (var i = 0; i < (rows || []).length; i++) {
                var id = parseInt(rows[i].id || 0, 10) || 0;
                if (id > 0) this.rowsById[id] = rows[i];
            }
        },

        reloadAll: function () { this.loadSettings(); this.reload(); },
        reload: function () { if (this.grid) this.grid.loadData(this.filters(), 20, 1, 'created_at|DESC'); },
        openSettingsModal: function () {
            this.loadSettings();
            if (this.settingsModal) this.settingsModal.show();
        },
        clearFilters: function () {
            this.setv('#admin-conflicts-filter-status', '');
            this.setv('#admin-conflicts-filter-location-id', '');
            this.setv('#admin-conflicts-filter-location-name', '');
            this.setv('#admin-conflicts-filter-origin', '');
            this.hideSuggestions('#admin-conflicts-filter-location-suggestions');
            this.reload();
        },

        filters: function () {
            var out = {};
            var status = this.qv('#admin-conflicts-filter-status');
            var location = parseInt(this.qv('#admin-conflicts-filter-location-id') || '0', 10) || 0;
            var origin = this.qv('#admin-conflicts-filter-origin');
            if (status !== '') out.status = status;
            if (location > 0) out.location_id = location;
            if (origin !== '') out.origin = origin;
            return out;
        },

        loadSettings: function () {
            var self = this;
            this.post('/admin/conflicts/settings/get', {}, function (r) {
                var d = r && r.dataset ? r.dataset : {};
                self.switchSet('#admin-conflicts-mode', d.conflict_resolution_mode || 'narrative');
                self.setv('#admin-conflicts-margin-narrow-max', d.conflict_margin_narrow_max != null ? d.conflict_margin_narrow_max : 2);
                self.setv('#admin-conflicts-margin-clear-max', d.conflict_margin_clear_max != null ? d.conflict_margin_clear_max : 5);
                self.setv('#admin-conflicts-critical-failure', d.conflict_critical_failure_value != null ? d.conflict_critical_failure_value : 1);
                self.setv('#admin-conflicts-critical-success', d.conflict_critical_success_value != null ? d.conflict_critical_success_value : 0);
                self.setv('#admin-conflicts-overlap-policy', d.conflict_overlap_policy || 'warn_only');
                self.setv('#admin-conflicts-inactivity-warning-hours', d.conflict_inactivity_warning_hours != null ? d.conflict_inactivity_warning_hours : 72);
                self.setv('#admin-conflicts-inactivity-archive-days', d.conflict_inactivity_archive_days != null ? d.conflict_inactivity_archive_days : 7);
                self.switchSet('#admin-conflicts-chat-compact-events', d.conflict_chat_compact_events != null ? d.conflict_chat_compact_events : 1);
            });
        },

        saveSettings: function () {
            var payload = {
                conflict_resolution_mode: this.switchGet('#admin-conflicts-mode', 'narrative'),
                conflict_margin_narrow_max: parseInt(this.qv('#admin-conflicts-margin-narrow-max') || '2', 10) || 2,
                conflict_margin_clear_max: parseInt(this.qv('#admin-conflicts-margin-clear-max') || '5', 10) || 5,
                conflict_critical_failure_value: parseInt(this.qv('#admin-conflicts-critical-failure') || '1', 10) || 1,
                conflict_critical_success_value: parseInt(this.qv('#admin-conflicts-critical-success') || '0', 10) || 0,
                conflict_overlap_policy: this.qv('#admin-conflicts-overlap-policy') || 'warn_only',
                conflict_inactivity_warning_hours: parseInt(this.qv('#admin-conflicts-inactivity-warning-hours') || '72', 10) || 72,
                conflict_inactivity_archive_days: parseInt(this.qv('#admin-conflicts-inactivity-archive-days') || '7', 10) || 7,
                conflict_chat_compact_events: parseInt(this.switchGet('#admin-conflicts-chat-compact-events', '1'), 10) === 1 ? 1 : 0
            };
            var self = this;
            this.post('/admin/conflicts/settings/update', payload, function () {
                if (self.settingsModal) self.settingsModal.hide();
                Toast.show({ body: 'Impostazioni conflitti aggiornate.', type: 'success' });
            });
        },

        openCreateModal: function () {
            this.setv('#admin-conflict-open-form [name="location_id"]', '');
            this.setv('#admin-conflict-open-form [name="location_name"]', '');
            this.setv('#admin-conflict-open-form [name="resolution_authority"]', 'mixed');
            this.setv('#admin-conflict-open-form [name="status"]', 'open');
            this.setv('#admin-conflict-open-form [name="opening_note"]', '');
            this.hideSuggestions('#admin-conflict-open-location-suggestions');
            if (this.openModal) this.openModal.show();
        },

        openConflict: function () {
            var locationId = parseInt(this.qv('#admin-conflict-open-form [name="location_id"]') || '0', 10) || 0;
            var payload = {
                resolution_authority: this.qv('#admin-conflict-open-form [name="resolution_authority"]') || 'mixed',
                status: this.qv('#admin-conflict-open-form [name="status"]') || 'open',
                opening_note: this.qv('#admin-conflict-open-form [name="opening_note"]') || ''
            };
            if (locationId > 0) payload.location_id = locationId;
            var self = this;
            this.post('/conflicts/open', payload, function (r) {
                var detail = r && r.dataset ? r.dataset : null;
                var id = detail && detail.conflict ? (parseInt(detail.conflict.id || 0, 10) || 0) : 0;
                if (self.openModal) self.openModal.hide();
                Toast.show({ body: 'Conflitto aperto.', type: 'success' });
                self.reload();
                if (id > 0) self.openDetail(id);
            });
        },

        openDetailFromTrigger: function (trigger) {
            var id = parseInt(String(trigger.getAttribute('data-id') || '0'), 10) || 0;
            if (id > 0) this.openDetail(id);
        },

        openQuickFromTrigger: function (trigger) {
            var id = parseInt(String(trigger.getAttribute('data-id') || '0'), 10) || 0;
            if (id > 0) this.openQuick(id);
        },

        openQuick: function (id) {
            var self = this;
            this.post('/conflicts/get', { conflict_id: id }, function (r) {
                var detail = r && r.dataset ? r.dataset : null;
                if (!detail) return;
                self.renderQuick(detail);
                if (self.quickModal) self.quickModal.show();
            });
        },

        quickToDetail: function () {
            var id = parseInt(this.qv('#admin-conflict-quick-id') || '0', 10) || 0;
            if (id <= 0) return;
            if (this.quickModal) this.quickModal.hide();
            this.openDetail(id);
        },

        openDetail: function (id) {
            var self = this;
            this.post('/conflicts/get', { conflict_id: id }, function (r) {
                var detail = r && r.dataset ? r.dataset : null;
                if (!detail) return;
                self.currentId = id;
                self.renderDetail(detail);
                if (self.detailModal) self.detailModal.show();
            });
        },

        reloadCurrent: function () {
            var id = parseInt(this.currentId || 0, 10) || parseInt(this.qv('#admin-conflict-current-id') || '0', 10) || 0;
            if (id > 0) this.openDetail(id);
        },

        renderDetail: function (detail) {
            var c = detail && detail.conflict ? detail.conflict : {};
            this.currentId = parseInt(c.id || 0, 10) || 0;
            this.currentParticipants = Array.isArray(detail && detail.participants) ? detail.participants.slice() : [];
            this.currentActions = Array.isArray(detail && detail.actions) ? detail.actions.slice() : [];
            this.setv('#admin-conflict-current-id', this.currentId);
            this.html('[data-role="admin-conflict-summary-id"]', this.currentId > 0 ? ('#' + this.currentId) : '-');
            this.html('[data-role="admin-conflict-summary-status"]', this.statusBadge(c.status));
            this.html('[data-role="admin-conflict-summary-mode"]', this.modeBadge(c.resolution_mode));
            this.html('[data-role="admin-conflict-summary-authority"]', this.authBadge(c.resolution_authority));
            this.html('[data-role="admin-conflict-summary-created"]', this.fmt(c.created_at));
            this.setv('#admin-conflict-status-next', String(c.status || 'open'));
            this.setv('#admin-conflict-outcome-summary', String(c.outcome_summary || ''));
            this.setv('#admin-conflict-verdict-text', String(c.verdict_text || ''));
            this.setv('#admin-conflict-roll-type', 'single_roll');
            this.drawParticipants(detail.participants || []);
            this.drawActions(detail.actions || []);
            this.drawRolls(detail.rolls || []);
            this.syncRollFields();
        },

        renderQuick: function (detail) {
            var c = detail && detail.conflict ? detail.conflict : {};
            var id = parseInt(c.id || 0, 10) || 0;
            this.setv('#admin-conflict-quick-id', id);
            this.html('[data-role="admin-conflict-quick-summary-id"]', id > 0 ? ('#' + id) : '-');
            this.html('[data-role="admin-conflict-quick-summary-status"]', this.statusBadge(c.status));
            this.html('[data-role="admin-conflict-quick-summary-mode"]', this.modeBadge(c.resolution_mode));
            this.html('[data-role="admin-conflict-quick-summary-created"]', this.fmt(c.created_at));
            this.drawQuickParticipants(detail.participants || []);
            this.drawQuickActions(detail.actions || []);
            this.drawQuickRolls(detail.rolls || []);
        },

        drawQuickParticipants: function (rows) {
            var body = this.root.querySelector('[data-role="admin-conflict-quick-participants-list"]');
            if (!body) return;
            if (!rows.length) return body.innerHTML = '<tr><td colspan="3" class="small text-muted">Nessun partecipante.</td></tr>';
            var out = [];
            for (var i = 0; i < rows.length; i++) {
                var r = rows[i] || {};
                out.push('<tr><td><b>' + this.e((r.character_name || ('#' + (parseInt(r.character_id || 0, 10) || 0)))) + '</b></td><td>' + this.roleBadge(r.participant_role) + '</td><td>' + ((parseInt(r.is_active || 0, 10) === 1) ? '<span class="badge text-bg-success">Attivo</span>' : '<span class="badge text-bg-secondary">Inattivo</span>') + '</td></tr>');
            }
            body.innerHTML = out.join('');
        },

        drawQuickActions: function (rows) {
            var list = this.root.querySelector('[data-role="admin-conflict-quick-actions-list"]');
            if (!list) return;
            var source = Array.isArray(rows) ? rows.slice() : [];
            source = source.slice(Math.max(0, source.length - 5)).reverse();
            if (!source.length) return list.innerHTML = '<div class="list-group-item small text-muted">Nessuna azione.</div>';
            var out = [];
            for (var i = 0; i < source.length; i++) {
                var r = source[i] || {};
                var actor = String(r.actor_name || '').trim();
                var head = actor !== '' ? actor : ('ID: ' + (parseInt(r.actor_id || 0, 10) || '-'));
                out.push('<div class="list-group-item py-2"><div class="d-flex justify-content-between align-items-start gap-2"><div><b>' + this.e(head) + '</b> ' + this.actionBadge(r.action_type) + ' <span class="small text-muted">#' + this.e(parseInt(r.id || r.action_id || 0, 10) || '-') + '</span></div><span class="small text-muted">' + this.fmt(r.created_at) + '</span></div><div class="small mt-1">' + this.e(r.action_body || '') + '</div></div>');
            }
            list.innerHTML = out.join('');
        },

        drawQuickRolls: function (rows) {
            var body = this.root.querySelector('[data-role="admin-conflict-quick-rolls-list"]');
            if (!body) return;
            var source = Array.isArray(rows) ? rows.slice(0, 5) : [];
            if (!source.length) return body.innerHTML = '<tr><td colspan="4" class="small text-muted">Nessun tiro.</td></tr>';
            var out = [];
            for (var i = 0; i < source.length; i++) {
                var r = source[i] || {};
                var actorLabel = String(r.actor_name || '').trim() || ('PG #' + (parseInt(r.actor_id || 0, 10) || '-'));
                out.push('<tr><td class="small text-muted">' + this.fmt(r.timestamp) + '</td><td>' + this.e(actorLabel) + '</td><td><span class="small">' + this.rollTypeLabel(r.roll_type) + '</span></td><td><b>' + this.e(r.final_result || '-') + '</b></td></tr>');
            }
            body.innerHTML = out.join('');
        },

        drawParticipants: function (rows) {
            var body = this.root.querySelector('[data-role="admin-conflict-participants-list"]');
            if (!body) return;
            if (!rows.length) return body.innerHTML = '<tr><td colspan="3" class="small text-muted">Nessun partecipante registrato.</td></tr>';
            var out = [];
            for (var i = 0; i < rows.length; i++) {
                var r = rows[i] || {};
                out.push('<tr><td><b>' + this.e((r.character_name || ('#' + (parseInt(r.character_id || 0, 10) || 0)))) + '</b><div class="small text-muted">ID: ' + (parseInt(r.character_id || 0, 10) || 0) + '</div></td><td>' + this.roleBadge(r.participant_role) + '</td><td>' + ((parseInt(r.is_active || 0, 10) === 1) ? '<span class="badge text-bg-success">Attivo</span>' : '<span class="badge text-bg-secondary">Inattivo</span>') + '</td></tr>');
            }
            body.innerHTML = out.join('');
        },

        drawActions: function (rows) {
            var list = this.root.querySelector('[data-role="admin-conflict-actions-list"]');
            if (!list) return;
            if (!rows.length) return list.innerHTML = '<div class="list-group-item small text-muted">Nessuna azione registrata.</div>';
            var out = [];
            for (var i = 0; i < rows.length; i++) {
                var r = rows[i] || {};
                var actor = String(r.actor_name || '').trim();
                var head = actor !== '' ? actor : ('ID: ' + (parseInt(r.actor_id || 0, 10) || '-'));
                out.push('<div class="list-group-item"><div class="d-flex justify-content-between align-items-start gap-2"><div><b>' + this.e(head) + '</b> ' + this.actionBadge(r.action_type) + ' <span class="small text-muted">#' + this.e(parseInt(r.id || r.action_id || 0, 10) || '-') + '</span></div><span class="small text-muted">' + this.fmt(r.created_at) + '</span></div><div class="small mt-1">' + this.e(r.action_body || '') + '</div></div>');
            }
            list.innerHTML = out.join('');
        },

        drawRolls: function (rows) {
            var body = this.root.querySelector('[data-role="admin-conflict-rolls-list"]');
            if (!body) return;
            if (!rows.length) return body.innerHTML = '<tr><td colspan="6" class="small text-muted">Nessun tiro registrato.</td></tr>';
            var out = [];
            for (var i = 0; i < rows.length; i++) {
                var r = rows[i] || {};
                var actorLabel = String(r.actor_name || '').trim() || ('PG #' + (parseInt(r.actor_id || 0, 10) || '-'));
                out.push('<tr><td class="small text-muted">' + this.fmt(r.timestamp) + '</td><td>' + this.e(actorLabel) + '</td><td><span class="small">' + this.rollTypeLabel(r.roll_type) + '</span></td><td>' + this.e(r.die_used || '-') + '</td><td><b>' + this.e(r.final_result || '-') + '</b>' + (r.margin != null ? '<div class="small text-muted">Margine: ' + this.e(r.margin) + '</div>' : '') + '</td><td>' + this.criticalBadge(r.critical_flag) + '</td></tr>');
            }
            body.innerHTML = out.join('');
        },

        renderParticipantSuggestions: function (dataset) {
            var self = this;
            var box = this.root.querySelector('#admin-conflict-participant-suggestions');
            if (!box) return;
            box.innerHTML = '';
            if (!dataset || !dataset.length) {
                box.classList.add('d-none');
                return;
            }
            for (var i = 0; i < dataset.length; i++) {
                var row = dataset[i];
                var label = (String(row.name || '') + ' ' + String(row.surname || '')).trim();
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'list-group-item list-group-item-action small py-1';
                btn.textContent = label;
                (function (r, l) {
                    btn.addEventListener('click', function () {
                        self.setv('#admin-conflict-participant-id', r.id);
                        self.setv('#admin-conflict-participant-name', l);
                        var b = self.root.querySelector('#admin-conflict-participant-suggestions');
                        if (b) { b.classList.add('d-none'); b.innerHTML = ''; }
                    });
                })(row, label);
                box.appendChild(btn);
            }
            box.classList.remove('d-none');
        },

        searchFilterLocations: function () {
            var self = this;
            var nameInput = this.root.querySelector('#admin-conflicts-filter-location-name');
            var idInput = this.root.querySelector('#admin-conflicts-filter-location-id');
            if (!nameInput || !idInput) return;
            var query = String(nameInput.value || '').trim().toLowerCase();
            idInput.value = '';

            if (this.filterLocationTimer) {
                window.clearTimeout(this.filterLocationTimer);
                this.filterLocationTimer = null;
            }
            if (query.length < 2) {
                this.hideSuggestions('#admin-conflicts-filter-location-suggestions');
                if (query.length === 0) this.reload();
                return;
            }

            this.filterLocationTimer = window.setTimeout(function () {
                self.searchLocations(query, function (rows) {
                    self.renderLocationSuggestions('#admin-conflicts-filter-location-suggestions', rows, 'admin-conflicts-filter-location-suggestion');
                });
            }, 180);
        },

        searchOpenLocations: function () {
            var self = this;
            var nameInput = this.root.querySelector('#admin-conflict-open-form [name="location_name"]');
            var idInput = this.root.querySelector('#admin-conflict-open-form [name="location_id"]');
            if (!nameInput || !idInput) return;
            var query = String(nameInput.value || '').trim().toLowerCase();
            idInput.value = '';

            if (this.openLocationTimer) {
                window.clearTimeout(this.openLocationTimer);
                this.openLocationTimer = null;
            }
            if (query.length < 2) {
                this.hideSuggestions('#admin-conflict-open-location-suggestions');
                return;
            }

            this.openLocationTimer = window.setTimeout(function () {
                self.searchLocations(query, function (rows) {
                    self.renderLocationSuggestions('#admin-conflict-open-location-suggestions', rows, 'admin-conflicts-open-location-suggestion');
                });
            }, 180);
        },

        searchRollTargets: function () {
            var self = this;
            var nameInput = this.root.querySelector('#admin-conflict-roll-target-name');
            var idInput = this.root.querySelector('#admin-conflict-roll-target-id');
            if (!nameInput || !idInput) return;
            var query = String(nameInput.value || '').trim();
            idInput.value = '';

            if (this.rollTargetTimer) {
                window.clearTimeout(this.rollTargetTimer);
                this.rollTargetTimer = null;
            }
            if (query.length < 2) {
                this.hideSuggestions('#admin-conflict-roll-target-suggestions');
                return;
            }

            this.rollTargetTimer = window.setTimeout(function () {
                self.post('/list/characters/search', { query: query }, function (response) {
                    self.renderCharacterSuggestions('#admin-conflict-roll-target-suggestions', response && response.dataset ? response.dataset : [], 'admin-conflicts-roll-target-suggestion');
                });
            }, 180);
        },

        ensureLocationsCache: function (onReady) {
            if (Array.isArray(this.locationsCache)) {
                if (typeof onReady === 'function') onReady(this.locationsCache);
                return;
            }
            if (this.locationsLoading) return;
            this.locationsLoading = true;
            var self = this;
            if (!window.Request || !Request.http || typeof Request.http.post !== 'function') {
                this.locationsCache = [];
                this.locationsLoading = false;
                if (typeof onReady === 'function') onReady([]);
                return;
            }
            Request.http.post('/list/locations', { results: 500, page: 1, orderBy: 'id|ASC' })
                .then(function (response) {
                    var dataset = response && response.dataset ? response.dataset : [];
                    var rows = Array.isArray(dataset.rows) ? dataset.rows : (Array.isArray(dataset) ? dataset : []);
                    self.locationsCache = rows;
                    self.locationsLoading = false;
                    if (typeof onReady === 'function') onReady(rows);
                })
                .catch(function () {
                    self.locationsCache = [];
                    self.locationsLoading = false;
                    if (typeof onReady === 'function') onReady([]);
                });
        },

        searchLocations: function (queryLower, onReady) {
            this.ensureLocationsCache(function (rows) {
                var out = [];
                for (var i = 0; i < rows.length; i++) {
                    var row = rows[i] || {};
                    var id = parseInt(row.id || row.location_id || 0, 10) || 0;
                    if (id <= 0) continue;
                    var name = String(row.name || row.title || row.location_name || '').trim();
                    if (!name) name = 'Location #' + id;
                    var text = (name + ' #' + id).toLowerCase();
                    if (text.indexOf(queryLower) !== -1) out.push({ id: id, name: name });
                    if (out.length >= 12) break;
                }
                if (typeof onReady === 'function') onReady(out);
            });
        },

        renderLocationSuggestions: function (selector, rows, role) {
            var box = this.root.querySelector(selector);
            if (!box) return;
            box.innerHTML = '';
            if (!Array.isArray(rows) || rows.length === 0) {
                box.classList.add('d-none');
                return;
            }
            for (var i = 0; i < rows.length; i++) {
                var row = rows[i] || {};
                var item = document.createElement('button');
                item.type = 'button';
                item.className = 'list-group-item list-group-item-action small py-1';
                item.setAttribute('data-role', role);
                item.setAttribute('data-location-id', String(row.id || 0));
                item.setAttribute('data-location-name', String(row.name || ''));
                item.textContent = String(row.name || ('Location #' + String(row.id || '')));
                box.appendChild(item);
            }
            box.classList.remove('d-none');
        },

        renderCharacterSuggestions: function (selector, rows, role) {
            var box = this.root.querySelector(selector);
            if (!box) return;
            box.innerHTML = '';
            if (!Array.isArray(rows) || rows.length === 0) {
                box.classList.add('d-none');
                return;
            }
            for (var i = 0; i < rows.length; i++) {
                var row = rows[i] || {};
                var id = parseInt(row.id || 0, 10) || 0;
                if (id <= 0) continue;
                var label = (String(row.name || '') + ' ' + String(row.surname || '')).trim();
                if (!label) label = 'PG #' + id;
                var item = document.createElement('button');
                item.type = 'button';
                item.className = 'list-group-item list-group-item-action small py-1';
                item.setAttribute('data-role', role);
                item.setAttribute('data-character-id', String(id));
                item.setAttribute('data-character-label', label);
                item.textContent = label;
                box.appendChild(item);
            }
            if (box.children.length === 0) {
                box.classList.add('d-none');
                return;
            }
            box.classList.remove('d-none');
        },

        pickFilterLocation: function (node) {
            var id = parseInt(node.getAttribute('data-location-id') || '0', 10) || 0;
            var name = String(node.getAttribute('data-location-name') || '').trim();
            this.setv('#admin-conflicts-filter-location-id', id > 0 ? String(id) : '');
            this.setv('#admin-conflicts-filter-location-name', name);
            this.hideSuggestions('#admin-conflicts-filter-location-suggestions');
            this.reload();
        },

        pickOpenLocation: function (node) {
            var id = parseInt(node.getAttribute('data-location-id') || '0', 10) || 0;
            var name = String(node.getAttribute('data-location-name') || '').trim();
            this.setv('#admin-conflict-open-form [name="location_id"]', id > 0 ? String(id) : '');
            this.setv('#admin-conflict-open-form [name="location_name"]', name);
            this.hideSuggestions('#admin-conflict-open-location-suggestions');
        },

        pickRollTarget: function (node) {
            var id = parseInt(node.getAttribute('data-character-id') || '0', 10) || 0;
            var label = String(node.getAttribute('data-character-label') || '').trim();
            this.setv('#admin-conflict-roll-target-id', id > 0 ? String(id) : '');
            this.setv('#admin-conflict-roll-target-name', label);
            this.hideSuggestions('#admin-conflict-roll-target-suggestions');
        },

        hideSuggestions: function (selector) {
            var box = this.root.querySelector(selector);
            if (!box) return;
            box.classList.add('d-none');
            box.innerHTML = '';
        },

        addParticipant: function () {
            var id = parseInt(this.qv('#admin-conflict-current-id') || '0', 10) || 0;
            var characterId = parseInt(this.qv('#admin-conflict-participant-id') || '0', 10) || 0;
            if (id <= 0 || characterId <= 0) return Toast.show({ body: 'Seleziona un personaggio dalla lista.', type: 'warning' });
            var payload = { conflict_id: id, participants: [{ character_id: characterId, participant_role: this.qv('#admin-conflict-participant-role') || 'actor', is_active: parseInt(this.switchGet('#admin-conflict-participant-active', '1'), 10) === 1 ? 1 : 0 }] };
            var self = this;
            this.post('/conflicts/participants/upsert', payload, function (r) {
                self.setv('#admin-conflict-participant-id', '');
                self.setv('#admin-conflict-participant-name', '');
                self.renderDetail(r.dataset || {});
                self.reload();
                Toast.show({ body: 'Partecipante aggiornato.', type: 'success' });
            });
        },

        addAction: function () {
            var id = parseInt(this.qv('#admin-conflict-current-id') || '0', 10) || 0;
            var body = this.qv('#admin-conflict-action-body');
            if (id <= 0 || String(body || '').trim() === '') return Toast.show({ body: 'Inserisci il testo dell\'azione.', type: 'warning' });
            var self = this;
            this.post('/conflicts/action/add', { conflict_id: id, action_type: this.qv('#admin-conflict-action-type') || 'action', action_body: body }, function (r) { self.setv('#admin-conflict-action-body', ''); self.renderDetail(r.dataset || {}); self.reload(); Toast.show({ body: 'Azione registrata.', type: 'success' }); });
        },

        setStatus: function () {
            var id = parseInt(this.qv('#admin-conflict-current-id') || '0', 10) || 0;
            if (id <= 0) return;
            var self = this;
            this.post('/conflicts/status/set', { conflict_id: id, status: this.qv('#admin-conflict-status-next') || 'open' }, function (r) { self.renderDetail(r.dataset || {}); self.reload(); Toast.show({ body: 'Stato conflitto aggiornato.', type: 'success' }); });
        },

        runRoll: function () {
            var id = parseInt(this.qv('#admin-conflict-current-id') || '0', 10) || 0;
            if (id <= 0) return;
            var rollType = this.qv('#admin-conflict-roll-type') || 'single_roll';
            var targetId = parseInt(this.qv('#admin-conflict-roll-target-id') || '0', 10) || 0;
            if (rollType === 'opposed_roll' && targetId <= 0) return Toast.show({ body: 'Per un tiro contrapposto seleziona un bersaglio dalla lista.', type: 'warning' });
            var payload = {
                conflict_id: id,
                roll_type: rollType,
                die_used: this.qv('#admin-conflict-roll-die') || 'd20',
                actor_modifier: this.num(this.qv('#admin-conflict-roll-actor-modifier')),
                actor_attribute_slug: this.qv('#admin-conflict-roll-actor-attribute') || ''
            };
            if (targetId > 0) {
                payload.target_id = targetId;
                payload.target_modifier = this.num(this.qv('#admin-conflict-roll-target-modifier'));
                payload.target_attribute_slug = this.qv('#admin-conflict-roll-target-attribute') || '';
            }
            if (rollType === 'threshold_roll') {
                var threshold = this.num(this.qv('#admin-conflict-roll-threshold'));
                if (threshold == null || threshold <= 0) return Toast.show({ body: 'Per threshold roll devi valorizzare la soglia.', type: 'warning' });
                payload.threshold = threshold;
            }
            var self = this;
            this.post('/conflicts/roll', payload, function (r) {
                var data = r && r.dataset ? r.dataset : {};
                if (data.conflict) self.renderDetail(data.conflict);
                else self.reloadCurrent();
                self.reload();
                Toast.show({ body: 'Tiro registrato.', type: 'success' });
            });
        },

        resolveConflict: function () {
            var id = parseInt(this.qv('#admin-conflict-current-id') || '0', 10) || 0;
            var summary = this.qv('#admin-conflict-outcome-summary');
            if (id <= 0 || String(summary || '').trim() === '') return Toast.show({ body: 'Outcome summary obbligatorio.', type: 'warning' });
            var self = this;
            this.post('/conflicts/resolve', { conflict_id: id, outcome_summary: summary, verdict_text: this.qv('#admin-conflict-verdict-text') || '' }, function (r) { self.renderDetail(r.dataset || {}); self.reload(); Toast.show({ body: 'Conflitto risolto.', type: 'success' }); });
        },

        closeConflict: function () {
            var id = parseInt(this.qv('#admin-conflict-current-id') || '0', 10) || 0;
            if (id <= 0) return;
            var self = this;
            this.confirm('Chiudi conflitto', 'Confermi la chiusura formale del conflitto?', function () {
                self.post('/conflicts/close', { conflict_id: id }, function (r) { self.renderDetail(r.dataset || {}); self.reload(); Toast.show({ body: 'Conflitto chiuso.', type: 'success' }); });
            });
        },

        forceOpen: function () {
            var id = parseInt(this.qv('#admin-conflict-current-id') || '0', 10) || 0;
            if (id <= 0) return;
            var self = this;
            this.confirm('Apertura forzata', 'Confermi apertura forzata del conflitto?', function () {
                self.post('/admin/conflicts/force-open', { conflict_id: id }, function (r) {
                    self.renderDetail(r.dataset || {});
                    self.reload();
                    Toast.show({ body: 'Apertura forzata applicata.', type: 'success' });
                });
            });
        },

        forceClose: function () {
            var id = parseInt(this.qv('#admin-conflict-current-id') || '0', 10) || 0;
            if (id <= 0) return;
            var self = this;
            this.confirm('Chiusura forzata', 'Confermi chiusura forzata del conflitto?', function () {
                self.post('/admin/conflicts/force-close', { conflict_id: id }, function (r) {
                    self.renderDetail(r.dataset || {});
                    self.reload();
                    Toast.show({ body: 'Chiusura forzata applicata.', type: 'success' });
                });
            });
        },

        openEditLogModal: function () {
            var id = parseInt(this.qv('#admin-conflict-current-id') || '0', 10) || 0;
            if (id <= 0) return;
            var select = this.root.querySelector('#admin-conflict-edit-log-action-id');
            var bodyInput = this.root.querySelector('#admin-conflict-edit-log-body');
            if (!select || !bodyInput) return;

            select.innerHTML = '<option value="">Seleziona azione...</option>';
            var actions = Array.isArray(this.currentActions) ? this.currentActions : [];
            for (var i = 0; i < actions.length; i++) {
                var row = actions[i] || {};
                var actionId = parseInt(row.id || row.action_id || 0, 10) || 0;
                if (actionId <= 0) continue;
                var actor = String(row.actor_name || '').trim();
                if (actor === '') actor = 'Attore #' + (parseInt(row.actor_id || 0, 10) || '-');
                var snippet = String(row.action_body || '').trim();
                if (snippet.length > 48) snippet = snippet.substring(0, 48) + '...';
                var option = document.createElement('option');
                option.value = String(actionId);
                option.textContent = '#' + actionId + ' · ' + actor + ' · ' + snippet;
                select.appendChild(option);
            }
            if (select.options.length <= 1) {
                Toast.show({ body: 'Nessuna azione disponibile da modificare.', type: 'warning' });
                return;
            }

            bodyInput.value = '';
            if (this.editLogModal) this.editLogModal.show();
        },

        prefillEditLogBody: function () {
            var actionId = parseInt(this.qv('#admin-conflict-edit-log-action-id') || '0', 10) || 0;
            if (actionId <= 0) return;
            var actions = Array.isArray(this.currentActions) ? this.currentActions : [];
            for (var i = 0; i < actions.length; i++) {
                var row = actions[i] || {};
                var rowId = parseInt(row.id || row.action_id || 0, 10) || 0;
                if (rowId !== actionId) continue;
                this.setv('#admin-conflict-edit-log-body', String(row.action_body || ''));
                return;
            }
        },

        saveEditLogFromModal: function () {
            var id = parseInt(this.qv('#admin-conflict-current-id') || '0', 10) || 0;
            if (id <= 0) return;
            var actionId = parseInt(this.qv('#admin-conflict-edit-log-action-id') || '0', 10) || 0;
            var body = this.qv('#admin-conflict-edit-log-body');
            if (actionId <= 0) {
                Toast.show({ body: 'Seleziona una azione da modificare.', type: 'warning' });
                return;
            }
            if (String(body || '').trim() === '') {
                Toast.show({ body: 'Nuovo contenuto obbligatorio.', type: 'warning' });
                return;
            }
            var self = this;
            this.post('/admin/conflicts/edit-log', { conflict_id: id, action_id: actionId, action_body: body }, function (r) {
                if (self.editLogModal) self.editLogModal.hide();
                self.renderDetail(r.dataset || {});
                self.reload();
                Toast.show({ body: 'Log conflitto aggiornato.', type: 'success' });
            });
        },

        openOverrideRollModal: function () {
            var id = parseInt(this.qv('#admin-conflict-current-id') || '0', 10) || 0;
            if (id <= 0) return;
            if (!Array.isArray(this.currentParticipants) || this.currentParticipants.length === 0) {
                Toast.show({ body: 'Nessun partecipante disponibile per override.', type: 'warning' });
                return;
            }
            this.setv('#admin-conflict-override-actor-name', '');
            this.setv('#admin-conflict-override-actor-id', '');
            this.setv('#admin-conflict-override-final-result', '10');
            this.setv('#admin-conflict-override-critical-flag', 'none');
            this.setv('#admin-conflict-override-note', 'Override admin da pannello conflitti');
            this.hideSuggestions('#admin-conflict-override-actor-suggestions');
            if (this.overrideRollModal) this.overrideRollModal.show();
        },

        searchOverrideActors: function () {
            var self = this;
            var query = String(this.qv('#admin-conflict-override-actor-name') || '').trim().toLowerCase();
            this.setv('#admin-conflict-override-actor-id', '');
            if (this.overrideActorTimer) {
                window.clearTimeout(this.overrideActorTimer);
                this.overrideActorTimer = null;
            }
            if (query.length < 1) {
                this.hideSuggestions('#admin-conflict-override-actor-suggestions');
                return;
            }
            this.overrideActorTimer = window.setTimeout(function () {
                self.renderOverrideActorSuggestions(query);
            }, 120);
        },

        renderOverrideActorSuggestions: function (queryLower) {
            var box = this.root.querySelector('#admin-conflict-override-actor-suggestions');
            if (!box) return;
            box.innerHTML = '';
            var rows = Array.isArray(this.currentParticipants) ? this.currentParticipants : [];
            var outCount = 0;
            for (var i = 0; i < rows.length; i++) {
                var row = rows[i] || {};
                var actorId = parseInt(row.character_id || row.participant_id || 0, 10) || 0;
                if (actorId <= 0) continue;
                var label = String(row.character_name || '').trim();
                if (label === '') label = 'PG #' + actorId;
                if (label.toLowerCase().indexOf(queryLower) === -1 && String(actorId).indexOf(queryLower) === -1) continue;
                var item = document.createElement('button');
                item.type = 'button';
                item.className = 'list-group-item list-group-item-action small py-1';
                item.setAttribute('data-role', 'admin-conflicts-override-actor-suggestion');
                item.setAttribute('data-actor-id', String(actorId));
                item.setAttribute('data-actor-label', label);
                item.textContent = label;
                box.appendChild(item);
                outCount += 1;
                if (outCount >= 12) break;
            }
            if (outCount === 0) {
                box.classList.add('d-none');
                return;
            }
            box.classList.remove('d-none');
        },

        pickOverrideActor: function (node) {
            var actorId = parseInt(node.getAttribute('data-actor-id') || '0', 10) || 0;
            var label = String(node.getAttribute('data-actor-label') || '').trim();
            if (actorId <= 0) return;
            this.setv('#admin-conflict-override-actor-id', String(actorId));
            this.setv('#admin-conflict-override-actor-name', label);
            this.hideSuggestions('#admin-conflict-override-actor-suggestions');
        },

        saveOverrideRollFromModal: function () {
            var id = parseInt(this.qv('#admin-conflict-current-id') || '0', 10) || 0;
            if (id <= 0) return;
            var actorId = parseInt(this.qv('#admin-conflict-override-actor-id') || '0', 10) || 0;
            if (actorId <= 0) {
                Toast.show({ body: 'Seleziona un attore dalla lista.', type: 'warning' });
                return;
            }
            var finalResult = this.num(this.qv('#admin-conflict-override-final-result'));
            if (finalResult == null || !isFinite(finalResult)) {
                Toast.show({ body: 'Risultato finale non valido.', type: 'warning' });
                return;
            }
            var self = this;
            this.post('/admin/conflicts/override-roll', {
                conflict_id: id,
                actor_id: actorId,
                roll_type: 'single_roll',
                die_used: 'd20',
                base_roll: 1,
                modifiers: finalResult - 1,
                final_result: finalResult,
                critical_flag: this.qv('#admin-conflict-override-critical-flag') || 'none',
                note: this.qv('#admin-conflict-override-note') || 'Override admin da pannello conflitti'
            }, function (r) {
                if (self.overrideRollModal) self.overrideRollModal.hide();
                self.renderDetail(r.dataset || {});
                self.reload();
                Toast.show({ body: 'Override tiro applicato.', type: 'success' });
            });
        },

        syncRollFields: function () {
            var rollType = this.qv('#admin-conflict-roll-type') || 'single_roll';
            var needsTarget = rollType === 'opposed_roll';
            var needsThreshold = rollType === 'threshold_roll';
            this.disable('#admin-conflict-roll-target-id', !needsTarget);
            this.disable('#admin-conflict-roll-target-name', !needsTarget);
            this.disable('#admin-conflict-roll-target-modifier', !needsTarget);
            this.disable('#admin-conflict-roll-target-attribute', !needsTarget);
            this.disable('#admin-conflict-roll-threshold', !needsThreshold);
            if (!needsTarget) {
                this.setv('#admin-conflict-roll-target-id', '');
                this.setv('#admin-conflict-roll-target-name', '');
                this.setv('#admin-conflict-roll-target-modifier', '');
                this.setv('#admin-conflict-roll-target-attribute', '');
                this.hideSuggestions('#admin-conflict-roll-target-suggestions');
            }
            if (!needsThreshold) this.setv('#admin-conflict-roll-threshold', '');
        },

        disable: function (selector, disabled) {
            var node = this.root.querySelector(selector);
            if (node) node.disabled = disabled === true;
        },

        switchSet: function (selector, value) {
            var sw = this.switches[selector];
            if (sw && typeof sw.setValue === 'function') return sw.setValue(String(value == null ? '' : value));
            this.setv(selector, value);
        },
        switchGet: function (selector, fallback) {
            var sw = this.switches[selector];
            if (sw && typeof sw.getValue === 'function') {
                var value = String(sw.getValue() || '');
                if (value !== '') return value;
            }
            var field = this.qv(selector);
            return field !== '' ? field : String(fallback == null ? '' : fallback);
        },

        post: function (url, payload, ok) {
            var self = this;
            if (!window.Request || !Request.http || typeof Request.http.post !== 'function') {
                return Toast.show({ body: 'Servizio non disponibile.', type: 'error' });
            }
            Request.http.post(url, payload || {}).then(function (r) { if (typeof ok === 'function') ok(r || {}); }).catch(function (error) { Toast.show({ body: self.err(error), type: 'danger' }); });
        },

        confirm: function (title, body, onConfirm) {
            if (typeof Dialog === 'function') {
                Dialog('warning', { title: title, body: '<p>' + body + '</p>' }, function () { if (typeof onConfirm === 'function') onConfirm(); }).show();
                return;
            }
            if (window.confirm(title + '\n\n' + String(body || '').replace(/<[^>]+>/g, ''))) if (typeof onConfirm === 'function') onConfirm();
        },

        err: function (error) {
            var code = '';
            var message = '';
            if (window.Request && typeof window.Request.getErrorCode === 'function') code = String(window.Request.getErrorCode(error, '') || '').trim();
            if (window.Request && typeof window.Request.getErrorMessage === 'function') message = String(window.Request.getErrorMessage(error, '') || '').trim();
            var map = {
                conflict_system_unavailable: 'Schema conflitti non disponibile. Applica la patch core conflitti.',
                conflict_not_found: 'Conflitto non trovato.',
                conflict_open_failed: 'Apertura conflitto non riuscita.',
                conflict_manage_forbidden: 'Non hai permessi per gestire questo conflitto.',
                conflict_action_forbidden: 'Non hai permessi per aggiungere azioni.',
                conflict_action_invalid: 'Azione non valida.',
                conflict_status_invalid: 'Stato conflitto non valido o transizione non consentita.',
                conflict_mode_narrative: 'Questo conflitto e in modalita narrativa: il tiro random non e disponibile.',
                conflict_target_required: 'Per questa operazione serve un bersaglio.',
                conflict_resolution_invalid: 'Esito conflitto non valido.',
                conflict_resolution_forbidden: 'Non hai permessi per risolvere questo conflitto.',
                conflict_not_resolved: 'Il conflitto non e ancora risolto.',
                conflict_closed: 'Il conflitto e gia chiuso.',
                conflict_actor_required: 'Attore non valido per il tiro.',
                conflict_critical_threshold_invalid: 'Soglia critici non valida.',
                conflict_proposal_not_found: 'Proposta conflitto non trovata.',
                conflict_proposal_expired: 'La proposta conflitto e scaduta.',
                conflict_proposal_forbidden: 'Non hai permessi per rispondere alla proposta.',
                conflict_overlap_detected: 'Rilevata sovrapposizione conflitti in location.',
                conflict_inactivity_escalated: 'Conflitto inattivo segnalato.',
                conflict_auto_archived: 'Conflitto archiviato automaticamente per inattivita.'
            };
            if (map[code]) return map[code];
            if (message) return message;
            return 'Operazione non riuscita.';
        },

        qv: function (selector) { var node = this.root.querySelector(selector); return node ? String(node.value || '').trim() : ''; },
        setv: function (selector, value) { var node = this.root.querySelector(selector); if (node) node.value = value == null ? '' : String(value); },
        html: function (selector, value) { var node = this.root.querySelector(selector); if (node) node.innerHTML = value == null ? '' : String(value); },
        num: function (value) { var raw = String(value == null ? '' : value).trim().replace(',', '.'); if (raw === '') return null; var n = parseFloat(raw); return isFinite(n) ? n : null; },
        locLabel: function (locationId) { var id = parseInt(locationId || 0, 10) || 0; return id > 0 ? ('Luogo #' + id) : 'Nessun luogo'; },
        rollTypeLabel: function (t) { t = String(t || '').toLowerCase().trim(); if (t === 'single_roll') return 'Singolo'; if (t === 'single_roll_with_modifiers') return 'Singolo + Mod.'; if (t === 'opposed_roll') return 'Contrapposto'; if (t === 'threshold_roll') return 'Soglia'; if (t === 'override') return 'Forzatura'; return t !== '' ? this.e(t) : '-'; },
        fmt: function (value) {
            var text = String(value || '').trim();
            if (text === '' || text === '0000-00-00 00:00:00') return '-';
            var date = new Date(text.replace(' ', 'T'));
            if (isNaN(date.getTime())) return this.e(text);
            try {
                return new Intl.DateTimeFormat('it-IT', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(date);
            } catch (error) {
                return this.e(text);
            }
        },
        modeBadge: function (mode) { return String(mode || '').toLowerCase() === 'random' ? '<span class="badge text-bg-warning">Random</span>' : '<span class="badge text-bg-info">Narrativo</span>'; },
        authBadge: function (a) { a = String(a || '').toLowerCase(); if (a === 'players') return '<span class="badge text-bg-light text-dark">Giocatori</span>'; if (a === 'master') return '<span class="badge text-bg-primary">Master</span>'; if (a === 'deferred_review') return '<span class="badge text-bg-secondary">Revisione differita</span>'; return '<span class="badge text-bg-success">Misto</span>'; },
        statusBadge: function (s) { s = String(s || '').toLowerCase(); if (s === 'proposal') return '<span class="badge text-bg-warning">Proposta</span>'; if (s === 'open') return '<span class="badge text-bg-light text-dark">Aperto</span>'; if (s === 'active') return '<span class="badge text-bg-primary">Attivo</span>'; if (s === 'awaiting_resolution') return '<span class="badge text-bg-info">In attesa</span>'; if (s === 'resolved') return '<span class="badge text-bg-success">Risolto</span>'; if (s === 'closed') return '<span class="badge text-bg-secondary">Chiuso</span>'; return '<span class="badge text-bg-dark">' + this.e(s || '-') + '</span>'; },
        roleBadge: function (r) { r = String(r || '').toLowerCase(); if (r === 'target') return '<span class="badge text-bg-warning">Bersaglio</span>'; if (r === 'support') return '<span class="badge text-bg-info">Supporto</span>'; if (r === 'witness') return '<span class="badge text-bg-secondary">Testimone</span>'; if (r === 'other') return '<span class="badge text-bg-light text-dark">Altro</span>'; return '<span class="badge text-bg-primary">Attore</span>'; },
        actionBadge: function (t) { t = String(t || '').toLowerCase(); if (t === 'note') return '<span class="badge text-bg-info">Nota</span>'; if (t === 'verdict') return '<span class="badge text-bg-success">Verdetto</span>'; if (t === 'system') return '<span class="badge text-bg-secondary">Sistema</span>'; return '<span class="badge text-bg-primary">Azione</span>'; },
        criticalBadge: function (f) { f = String(f || '').toLowerCase(); if (f === 'success') return '<span class="badge text-bg-success">Critico successo</span>'; if (f === 'failure') return '<span class="badge text-bg-danger">Critico fallimento</span>'; return '<span class="badge text-bg-light text-dark">Nessuno</span>'; },
        e: function (value) { return String(value == null ? '' : value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\"/g, '&quot;').replace(/'/g, '&#039;'); }
    };

    window.AdminConflicts = AdminConflicts;
})();
