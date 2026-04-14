(function () {
    'use strict';

    var AdminSystemEvents = {
        initialized: false,
        root: null,
        grid: null,
        rowsById: {},
        selectedEventId: 0,
        detailPanel: null,
        detailEmpty: null,
        detailActions: [],
        filterStatus: null,
        filterMode: null,
        filterTag: null,
        tagsCatalog: [],
        eventForm: null,
        effectForm: null,
        participationForm: null,
        rewardForm: null,
        homeFeedSwitch: null,
        debounceTimers: {},

        init: function () {
            if (this.initialized) { return this; }
            this.root = document.querySelector('#admin-page [data-admin-page="system-events"]');
            if (!this.root) { return this; }

            this.filterStatus = document.getElementById('admin-system-events-filter-status');
            this.filterMode = document.getElementById('admin-system-events-filter-mode');
            this.filterTag = document.getElementById('admin-system-events-filter-tag');
            this.eventForm = document.getElementById('admin-system-event-form');
            this.effectForm = document.getElementById('admin-system-event-effect-form');
            this.participationForm = document.getElementById('admin-system-event-participation-form');
            this.rewardForm = document.getElementById('admin-system-event-reward-form');
            this.detailPanel = document.getElementById('admin-system-event-detail');
            this.detailEmpty = document.getElementById('admin-system-event-detail-empty');
            this.detailActions = Array.prototype.slice.call(this.root.querySelectorAll('[data-role="admin-system-events-detail-action"]'));

            if (!this.eventForm || !document.getElementById('grid-admin-system-events')) { return this; }

            this.bindEvents();
            this.loadTagCatalog();
            this.initGrid();
            this.setDetailActionsEnabled(false);
            this.setScopeInputState();
            this.initSwitches();
            this.loadGrid();

            this.initialized = true;
            return this;
        },

        bindEvents: function () {
            var self = this;
            var filtersForm = document.getElementById('admin-system-events-filters');
            if (filtersForm) {
                filtersForm.addEventListener('submit', function (event) {
                    event.preventDefault();
                    self.loadGrid();
                });
            }
            if (this.filterStatus) { this.filterStatus.addEventListener('change', function () { self.loadGrid(); }); }
            if (this.filterMode) { this.filterMode.addEventListener('change', function () { self.loadGrid(); }); }
            if (this.filterTag) { this.filterTag.addEventListener('change', function () { self.loadGrid(); }); }

            var scopeType = this.eventForm.querySelector('[data-role="admin-system-event-scope-type"]');
            if (scopeType) {
                scopeType.addEventListener('change', function () {
                    self.eventForm.elements.scope_id.value = '0';
                    var input = self.eventForm.querySelector('[data-role="admin-system-event-scope-label"]');
                    if (input) { input.value = ''; }
                    self.hideSuggestions(self.eventForm.querySelector('[data-role="admin-system-event-scope-suggestions"]'));
                    self.setScopeInputState();
                });
            }

            var scopeLabel = this.eventForm.querySelector('[data-role="admin-system-event-scope-label"]');
            if (scopeLabel) {
                scopeLabel.addEventListener('input', function () {
                    self.debounce('scope', 180, function () { self.searchScopeSuggestions(); });
                });
            }

            this.bindAutocompleteInputs();

            this.root.addEventListener('click', function (event) {
                if (self.handleSuggestionClick(event)) { return; }

                var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
                if (!trigger) { return; }
                var action = String(trigger.getAttribute('data-action') || '');
                if (!action) { return; }

                if (action === 'admin-system-events-reload') { event.preventDefault(); self.loadGrid(); return; }
                if (action === 'admin-system-events-filters-reset') {
                    event.preventDefault();
                    if (self.filterStatus) { self.filterStatus.value = ''; }
                    if (self.filterMode) { self.filterMode.value = ''; }
                    if (self.filterTag) { self.filterTag.value = ''; }
                    self.loadGrid();
                    return;
                }
                if (action === 'admin-system-events-maintenance') { event.preventDefault(); self.runMaintenance(); return; }
                if (action === 'admin-system-events-create') { event.preventDefault(); self.openEventModal(null); return; }
                if (action === 'admin-system-event-save') { event.preventDefault(); self.saveEvent(); return; }
                if (action === 'admin-system-event-view') { event.preventDefault(); self.openDetailModal(self.findRowByTrigger(trigger)); return; }
                if (action === 'admin-system-event-edit') { event.preventDefault(); self.openEventModal(self.findRowByTrigger(trigger)); return; }
                if (action === 'admin-system-event-status-next') { event.preventDefault(); self.rotateStatus(self.findRowByTrigger(trigger)); return; }
                if (action === 'admin-system-event-delete') { event.preventDefault(); self.deleteEvent(self.findRowByTrigger(trigger)); return; }
                if (action === 'admin-system-events-open-effects') { event.preventDefault(); self.openEffectsModal(); return; }
                if (action === 'admin-system-events-open-participations') { event.preventDefault(); self.openParticipationsModal(); return; }
                if (action === 'admin-system-events-open-rewards') { event.preventDefault(); self.openRewardsModal(); return; }
                if (action === 'admin-system-event-effect-save') { event.preventDefault(); self.saveEffect(); return; }
                if (action === 'admin-system-event-effect-delete') { event.preventDefault(); self.deleteEffect(parseInt(trigger.getAttribute('data-effect-id') || '0', 10) || 0); return; }
                if (action === 'admin-system-event-participation-save') { event.preventDefault(); self.saveParticipation(); return; }
                if (action === 'admin-system-event-participation-remove') { event.preventDefault(); self.removeParticipation(parseInt(trigger.getAttribute('data-participation-id') || '0', 10) || 0); return; }
                if (action === 'admin-system-event-reward-assign') { event.preventDefault(); self.assignReward(); return; }
            });

            document.addEventListener('click', function (event) {
                if (!event.target || !event.target.closest) { return; }
                self.hideOutsideSuggestions(event.target);
            });
        },

        initSwitches: function () {
            if (!this.eventForm || typeof window.SwitchGroup !== 'function') { return; }
            var input = this.eventForm.elements.show_on_homepage_feed;
            if (!input) { return; }
            this.homeFeedSwitch = window.SwitchGroup(input, {
                preset: 'yesNo',
                trueLabel: 'Visibile',
                falseLabel: 'Nascosto',
                trueValue: '1',
                falseValue: '0',
                defaultValue: '0'
            });
        },

        initGrid: function () {
            var self = this;
            this.grid = new Datagrid('grid-admin-system-events', {
                name: 'AdminSystemEvents',
                autoindex: 'id',
                orderable: true,
                thead: true,
                handler: { url: '/admin/system-events/list', action: 'list' },
                nav: { display: 'bottom', urlupdate: 0, results: 20, page: 1 },
                onGetDataSuccess: function (response) { self.storeRows(self.parseRows(response)); },
                onGetDataError: function () { self.storeRows([]); },
                columns: [
                    { label: 'ID', field: 'id', sortable: true },
                    { label: 'Titolo', field: 'title', sortable: true, style: { textAlign: 'left' }, format: function (row) { return '<b>' + self.e(row.title || ('Evento #' + row.id)) + '</b>'; } },
                    { label: 'Tipo', field: 'type', sortable: true, format: function (row) { return self.e(self.typeLabel(row.type)); } },
                    { label: 'Stato', field: 'status', sortable: true, format: function (row) { return '<span class="badge ' + self.statusBadge(row.status) + '">' + self.e(self.statusLabel(row.status)) + '</span>'; } },
                    { label: 'Visibilita', field: 'visibility', sortable: true, format: function (row) { return self.e(self.visibilityLabel(row.visibility)); } },
                    {
                        label: 'Homepage',
                        field: 'show_on_homepage_feed',
                        sortable: true,
                        format: function (row) {
                            return (parseInt(row.show_on_homepage_feed || '0', 10) === 1)
                                ? '<span class="badge text-bg-success">Visibile</span>'
                                : '<span class="badge text-bg-secondary">Nascosto</span>';
                        }
                    },
                    { label: 'Ambito', field: 'scope_type', sortable: true, format: function (row) { return self.e(self.scopeLabel(row.scope_type) + ((parseInt(row.scope_id || '0', 10) || 0) > 0 ? (' #' + row.scope_id) : '')); } },
                    {
                        label: 'Tag',
                        field: 'narrative_tags',
                        sortable: false,
                        format: function (row) {
                            var tags = Array.isArray(row.narrative_tags) ? row.narrative_tags : [];
                            if (!tags.length) { return '<span class="text-muted small">-</span>'; }
                            return tags.slice(0, 3).map(function (tag) {
                                return '<span class="badge text-bg-secondary me-1">' + self.e(tag.label || tag.slug || 'Tag') + '</span>';
                            }).join('');
                        }
                    },
                    { label: 'Partecipanti', field: 'participants_count', sortable: true },
                    { label: 'Azioni', sortable: false, style: { textAlign: 'left' }, format: function (row) { var id = parseInt(row.id || '0', 10) || 0; if (id > 0) { self.rowsById[id] = row; } return '<div class="d-flex flex-wrap gap-1"><button class="btn btn-sm btn-outline-secondary" data-action="admin-system-event-view" data-id="' + id + '">Dettaglio</button><button class="btn btn-sm btn-outline-primary" data-action="admin-system-event-edit" data-id="' + id + '">Modifica</button><button class="btn btn-sm btn-outline-warning" data-action="admin-system-event-status-next" data-id="' + id + '">Stato</button><button class="btn btn-sm btn-outline-danger" data-action="admin-system-event-delete" data-id="' + id + '">Elimina</button></div>'; } }
                ]
            });
        },

        loadGrid: function () {
            if (!this.grid) { return; }
            this.rowsById = {};
            this.grid.loadData({
                status: this.filterStatus ? (this.filterStatus.value || '') : '',
                participant_mode: this.filterMode ? (this.filterMode.value || '') : '',
                tag_ids: this.filterTag && this.filterTag.value ? [parseInt(this.filterTag.value || '0', 10) || 0] : []
            }, 20, 1, 'starts_at|DESC');
        },

        loadTagCatalog: function () {
            var self = this;
            if (!this.filterTag) { return; }
            this.request('/list/narrative-tags', { entity_type: 'system_event' }, function (response) {
                var rows = self.parseRows(response);
                self.tagsCatalog = Array.isArray(rows) ? rows : [];
                self.renderTagFilterOptions();
            });
        },

        renderTagFilterOptions: function () {
            if (!this.filterTag) { return; }
            var current = String(this.filterTag.value || '');
            var html = '<option value="">Tutti i tag</option>';
            for (var i = 0; i < this.tagsCatalog.length; i += 1) {
                var row = this.tagsCatalog[i] || {};
                var id = parseInt(row.id || '0', 10) || 0;
                if (id <= 0) { continue; }
                html += '<option value="' + id + '">' + this.e(row.label || row.slug || ('Tag #' + id)) + '</option>';
            }
            this.filterTag.innerHTML = html;
            if (current !== '') {
                this.filterTag.value = current;
            }
        },

        storeRows: function (rows) {
            this.rowsById = {};
            for (var i = 0; i < rows.length; i += 1) {
                var id = parseInt(rows[i].id || '0', 10) || 0;
                if (id > 0) { this.rowsById[id] = rows[i]; }
            }
        },

        openEventModal: function (row) {
            this.eventForm.reset();
            this.eventForm.elements.id.value = '';
            this.eventForm.elements.scope_id.value = '0';
            this.eventForm.elements.status.value = 'draft';
            this.eventForm.elements.visibility.value = 'public';
            this.eventForm.elements.show_on_homepage_feed.value = '0';
            this.eventForm.elements.scope_type.value = 'global';
            this.eventForm.elements.participant_mode.value = 'character';
            this.eventForm.elements.recurrence.value = 'none';
            if (this.homeFeedSwitch && typeof this.homeFeedSwitch.setValue === 'function') {
                this.homeFeedSwitch.setValue('0');
            }
            var scopeLabel = this.eventForm.querySelector('[data-role="admin-system-event-scope-label"]');
            if (scopeLabel) { scopeLabel.value = ''; }
            this.setScopeInputState();
            this.renderTagCheckboxes([]);
            if (!row || !row.id) { this.showModal('admin-system-event-modal'); return; }
            var self = this;
            this.request('/admin/system-events/get', { event_id: row.id }, function (res) {
                var ev = (res && res.dataset) ? res.dataset : row;
                var f = self.eventForm.elements;
                f.id.value = String(ev.id || '');
                f.title.value = String(ev.title || '');
                f.description.value = String(ev.description || '');
                self.setTypeValue(String(ev.type || 'general'));
                f.status.value = String(ev.status || 'draft');
                f.visibility.value = String(ev.visibility || 'public');
                f.show_on_homepage_feed.value = (parseInt(ev.show_on_homepage_feed || '0', 10) === 1) ? '1' : '0';
                if (self.homeFeedSwitch && typeof self.homeFeedSwitch.setValue === 'function') {
                    self.homeFeedSwitch.setValue(f.show_on_homepage_feed.value);
                }
                f.scope_type.value = String(ev.scope_type || 'global');
                f.scope_id.value = String(parseInt(ev.scope_id || '0', 10) || 0);
                f.participant_mode.value = String(ev.participant_mode || 'character');
                f.starts_at.value = self.toInputDate(ev.starts_at);
                f.ends_at.value = self.toInputDate(ev.ends_at);
                f.recurrence.value = String(ev.recurrence || 'none');
                self.setScopeInputState();
                if (scopeLabel) { scopeLabel.value = (parseInt(f.scope_id.value || '0', 10) || 0) > 0 ? (self.scopeLabel(f.scope_type.value) + ' #' + f.scope_id.value) : ''; }
                self.renderTagCheckboxes(Array.isArray(ev.narrative_tag_ids) ? ev.narrative_tag_ids : []);
                self.showModal('admin-system-event-modal');
            });
        },

        renderTagCheckboxes: function (selectedIds) {
            var container = document.getElementById('admin-system-event-tags-container');
            if (!container) { return; }
            if (!this.tagsCatalog.length) {
                container.innerHTML = '<span class="text-muted small">Nessun tag disponibile.</span>';
                return;
            }
            var selected = {};
            for (var i = 0; i < selectedIds.length; i++) {
                selected[parseInt(selectedIds[i], 10)] = true;
            }
            var html = '';
            for (var j = 0; j < this.tagsCatalog.length; j++) {
                var tag = this.tagsCatalog[j];
                var id = parseInt(tag.id || '0', 10);
                if (!id) { continue; }
                var checked = selected[id] ? ' checked' : '';
                html += '<div class="form-check form-check-inline mb-0">'
                    + '<input class="form-check-input" type="checkbox" name="tag_ids" value="' + id + '" id="se-tag-' + id + '"' + checked + '>'
                    + '<label class="form-check-label small" for="se-tag-' + id + '">' + this.e(tag.label || tag.slug || ('Tag #' + id)) + '</label>'
                    + '</div>';
            }
            container.innerHTML = html || '<span class="text-muted small">Nessun tag disponibile.</span>';
        },

        collectTagIds: function () {
            var container = document.getElementById('admin-system-event-tags-container');
            if (!container) { return []; }
            var checks = container.querySelectorAll('input[type="checkbox"][name="tag_ids"]:checked');
            var ids = [];
            for (var i = 0; i < checks.length; i++) {
                var v = parseInt(checks[i].value || '0', 10);
                if (v > 0) { ids.push(v); }
            }
            return ids;
        },

        saveEvent: function () {
            var f = this.eventForm.elements;
            var payload = {
                id: parseInt(f.id.value || '0', 10) || 0,
                title: String(f.title.value || '').trim(),
                description: String(f.description.value || '').trim(),
                type: String(f.type.value || 'general').trim(),
                status: String(f.status.value || 'draft').trim(),
                visibility: String(f.visibility.value || 'public').trim(),
                show_on_homepage_feed: (parseInt(f.show_on_homepage_feed.value || '0', 10) === 1) ? 1 : 0,
                scope_type: String(f.scope_type.value || 'global').trim(),
                scope_id: parseInt(f.scope_id.value || '0', 10) || 0,
                participant_mode: String(f.participant_mode.value || 'character').trim(),
                starts_at: this.toMysqlDate(f.starts_at.value || ''),
                ends_at: this.toMysqlDate(f.ends_at.value || ''),
                recurrence: String(f.recurrence.value || 'none').trim(),
                tag_ids: this.collectTagIds()
            };
            if (payload.scope_type === 'global') { payload.scope_id = 0; }
            if (!payload.title) { Toast.show({ body: 'Titolo obbligatorio.', type: 'warning' }); return; }
            if (payload.status === 'scheduled' && !payload.starts_at) { Toast.show({ body: 'Data inizio obbligatoria.', type: 'warning' }); return; }
            var self = this;
            this.request(payload.id > 0 ? '/admin/system-events/update' : '/admin/system-events/create', payload, function (res) {
                self.hideModal('admin-system-event-modal');
                self.loadGrid();
                Toast.show({ body: payload.id > 0 ? 'Evento aggiornato.' : 'Evento creato.', type: 'success' });
                if (res && res.dataset && res.dataset.id) { self.viewDetail({ id: res.dataset.id }); }
            });
        },

        openDetailModal: function (row) {
            this.viewDetail(row, true);
        },

        viewDetail: function (row, openModalAfterLoad) {
            if (!row || !row.id) { return; }
            var self = this;
            this.request('/admin/system-events/get', { event_id: row.id }, function (res) {
                var ev = (res && res.dataset) ? res.dataset : null;
                if (!ev || !self.detailPanel || !self.detailEmpty) { return; }
                self.selectedEventId = parseInt(ev.id || '0', 10) || 0;
                var effects = Array.isArray(ev.effects) ? ev.effects.length : 0;
                var parts = Array.isArray(ev.participations) ? ev.participations.length : 0;
                var tags = Array.isArray(ev.narrative_tags) ? ev.narrative_tags : [];
                self.detailPanel.innerHTML = '<h4>' + self.e(ev.title || ('Evento #' + ev.id)) + '</h4>'
                    + '<div class="small text-muted mb-2">Stato: <b>' + self.e(self.statusLabel(ev.status)) + '</b> &bull; Visibilita: <b>' + self.e(self.visibilityLabel(ev.visibility)) + '</b></div>'
                    + '<div class="small text-muted mb-2">Feed homepage: <b>' + (parseInt(ev.show_on_homepage_feed || '0', 10) === 1 ? 'Visibile' : 'Nascosto') + '</b></div>'
                    + '<div class="small text-muted mb-2">Scope: <b>' + self.e(self.scopeLabel(ev.scope_type) + ((parseInt(ev.scope_id || '0', 10) || 0) > 0 ? (' #' + ev.scope_id) : '')) + '</b> &bull; Partecipazione: <b>' + self.e(self.participantLabel(ev.participant_mode)) + '</b></div>'
                    + '<div class="small text-muted mb-2">Inizio: <b>' + self.e(self.fmtDate(ev.starts_at) || '-') + '</b> &bull; Fine: <b>' + self.e(self.fmtDate(ev.ends_at) || '-') + '</b></div>'
                    + (ev.description ? '<hr /><div class="">' + self.e(ev.description) + '</div>' : '')
                    + (tags.length ? ('<div class="small text-muted mb-2">Tag: '
                        + tags.map(function (tag) { return '<span class="badge text-bg-secondary me-1">' + self.e(tag.label || tag.slug || 'Tag') + '</span>'; }).join('')
                        + '</div>') : '')
                    + '<hr/><div class="mt-3">Effetti: <b>' + effects + '</b> &bull; Partecipazioni: <b>' + parts + '</b></div>';
                self.detailEmpty.classList.add('d-none');
                self.detailPanel.classList.remove('d-none');
                self.setDetailActionsEnabled(true);
                if (openModalAfterLoad === true) {
                    self.showModal('admin-system-event-detail-modal');
                }
            });
        },

        rotateStatus: function (row) {
            if (!row || !row.id) { return; }
            var map = { draft: 'scheduled', scheduled: 'active', active: 'completed', completed: 'scheduled', cancelled: 'draft' };
            var next = map[String(row.status || 'draft').toLowerCase()] || 'draft';
            var self = this;
            this.request('/admin/system-events/status/set', { event_id: row.id, status: next }, function () {
                Toast.show({ body: 'Stato aggiornato: ' + self.statusLabel(next), type: 'success' });
                self.loadGrid();
                if (self.selectedEventId === (parseInt(row.id, 10) || 0)) { self.viewDetail(row); }
            });
        },

        deleteEvent: function (row) {
            if (!row || !row.id) { return; }
            var self = this;
            Dialog('danger', { title: 'Elimina evento di sistema', body: '<p>Confermi eliminazione di <b>' + self.e(row.title || ('Evento #' + row.id)) + '</b>?</p>' }, function () {
                self.hideConfirm();
                self.request('/admin/system-events/delete', { event_id: row.id }, function () {
                    Toast.show({ body: 'Evento eliminato.', type: 'success' });
                    self.loadGrid();
                    if (self.selectedEventId === (parseInt(row.id, 10) || 0)) {
                        self.selectedEventId = 0;
                        if (self.detailPanel) { self.detailPanel.classList.add('d-none'); self.detailPanel.innerHTML = ''; }
                        if (self.detailEmpty) { self.detailEmpty.classList.remove('d-none'); }
                        self.setDetailActionsEnabled(false);
                    }
                });
            }).show();
        },

        runMaintenance: function () {
            var self = this;
            this.request('/admin/system-events/maintenance/run', { force: 1 }, function (res) {
                var data = (res && res.dataset) ? res.dataset : {};
                var a = Array.isArray(data.activated_ids) ? data.activated_ids.length : 0;
                var c = Array.isArray(data.completed_ids) ? data.completed_ids.length : 0;
                var g = Array.isArray(data.generated_ids) ? data.generated_ids.length : 0;
                Toast.show({ body: 'Manutenzione completata. Attivati: ' + a + ', completati: ' + c + ', ricorrenti: ' + g + '.', type: 'success' });
                self.loadGrid();
                if (self.selectedEventId > 0) { self.viewDetail({ id: self.selectedEventId }); }
            });
        },

        setDetailActionsEnabled: function (enabled) {
            for (var i = 0; i < this.detailActions.length; i += 1) {
                this.detailActions[i].disabled = !enabled;
            }
        },

        openEffectsModal: function () {
            if (this.selectedEventId <= 0) { Toast.show({ body: 'Seleziona prima un evento.', type: 'warning' }); return; }
            this.effectForm.reset();
            this.effectForm.elements.effect_id.value = '0';
            this.effectForm.elements.currency_id.value = '0';
            this.showModal('admin-system-event-effects-modal');
            this.loadEffects();
        },

        loadEffects: function () {
            var self = this;
            this.request('/admin/system-events/effects/list', { event_id: this.selectedEventId }, function (res) {
                self.renderEffects(self.parseRows(res));
            });
        },

        renderEffects: function (rows) {
            var box = document.getElementById('admin-system-event-effects-list');
            if (!box) { return; }
            if (!rows.length) { box.innerHTML = '<div class="small text-muted">Nessun effetto configurato.</div>'; return; }
            var self = this;
            box.innerHTML = rows.map(function (r) {
                var id = parseInt(r.id || '0', 10) || 0;
                return '<div class="list-group-item d-flex justify-content-between align-items-center"><div><b>' + self.e(r.currency_name || ('Valuta #' + r.currency_id)) + '</b> + ' + self.e(r.amount || 0) + '</div><button class="btn btn-sm btn-outline-danger" data-action="admin-system-event-effect-delete" data-effect-id="' + id + '">Rimuovi</button></div>';
            }).join('');
        },

        saveEffect: function () {
            var cid = parseInt(this.effectForm.elements.currency_id.value || '0', 10) || 0;
            var amount = parseInt(this.effectForm.elements.amount.value || '0', 10) || 0;
            if (cid <= 0 || amount <= 0) { Toast.show({ body: 'Valuta e importo obbligatori.', type: 'warning' }); return; }
            var self = this;
            this.request('/admin/system-events/effects/upsert', {
                event_id: this.selectedEventId,
                effect_id: parseInt(this.effectForm.elements.effect_id.value || '0', 10) || 0,
                effect_type: 'currency_reward',
                currency_id: cid,
                amount: amount,
                is_enabled: 1
            }, function () {
                Toast.show({ body: 'Effetto salvato.', type: 'success' });
                self.loadEffects();
                self.viewDetail({ id: self.selectedEventId });
            });
        },

        deleteEffect: function (effectId) {
            if (effectId <= 0) { return; }
            var self = this;
            this.request('/admin/system-events/effects/delete', { event_id: this.selectedEventId, effect_id: effectId }, function () {
                self.loadEffects();
                self.viewDetail({ id: self.selectedEventId });
                Toast.show({ body: 'Effetto rimosso.', type: 'success' });
            });
        },

        openParticipationsModal: function () {
            if (this.selectedEventId <= 0) { Toast.show({ body: 'Seleziona prima un evento.', type: 'warning' }); return; }
            this.participationForm.reset();
            this.participationForm.elements.character_id.value = '0';
            this.participationForm.elements.faction_id.value = '0';
            this.showModal('admin-system-event-participations-modal');
            this.loadParticipations();
        },

        loadParticipations: function () {
            var self = this;
            this.request('/admin/system-events/participations/list', { event_id: this.selectedEventId }, function (res) {
                self.renderParticipations(self.parseRows(res));
            });
        },

        renderParticipations: function (rows) {
            var box = document.getElementById('admin-system-event-participations-list');
            if (!box) { return; }
            if (!rows.length) { box.innerHTML = '<div class="small text-muted">Nessuna partecipazione presente.</div>'; return; }
            var self = this;
            box.innerHTML = rows.map(function (r) {
                var id = parseInt(r.id || '0', 10) || 0;
                return '<div class="list-group-item d-flex justify-content-between align-items-center"><div><b>' + self.e(r.participant_label || '-') + '</b><div class="small text-muted">' + self.e(self.participantLabel(r.participant_mode)) + ' &bull; ' + self.e(self.participationStatusLabel(r.status)) + '</div></div><button class="btn btn-sm btn-outline-danger" data-action="admin-system-event-participation-remove" data-participation-id="' + id + '">Rimuovi</button></div>';
            }).join('');
        },

        saveParticipation: function () {
            var mode = String(this.participationForm.elements.participant_mode.value || 'character');
            var ch = parseInt(this.participationForm.elements.character_id.value || '0', 10) || 0;
            var fa = parseInt(this.participationForm.elements.faction_id.value || '0', 10) || 0;
            if ((mode === 'character' && ch <= 0) || (mode === 'faction' && fa <= 0)) { Toast.show({ body: 'Seleziona un destinatario valido.', type: 'warning' }); return; }
            var self = this;
            this.request('/admin/system-events/participations/upsert', {
                event_id: this.selectedEventId,
                participant_mode: mode,
                character_id: mode === 'character' ? ch : 0,
                faction_id: mode === 'faction' ? fa : 0,
                status: 'joined'
            }, function () {
                self.loadParticipations();
                self.viewDetail({ id: self.selectedEventId });
                Toast.show({ body: 'Partecipazione salvata.', type: 'success' });
            });
        },

        removeParticipation: function (id) {
            if (id <= 0) { return; }
            var self = this;
            this.request('/admin/system-events/participations/remove', { event_id: this.selectedEventId, id: id }, function () {
                self.loadParticipations();
                self.viewDetail({ id: self.selectedEventId });
                Toast.show({ body: 'Partecipazione rimossa.', type: 'success' });
            });
        },

        openRewardsModal: function () {
            if (this.selectedEventId <= 0) { Toast.show({ body: 'Seleziona prima un evento.', type: 'warning' }); return; }
            this.rewardForm.reset();
            this.rewardForm.elements.character_id.value = '0';
            this.rewardForm.elements.currency_id.value = '0';
            this.showModal('admin-system-event-rewards-modal');
            this.loadRewardsLog();
        },

        assignReward: function () {
            var ch = parseInt(this.rewardForm.elements.character_id.value || '0', 10) || 0;
            var cu = parseInt(this.rewardForm.elements.currency_id.value || '0', 10) || 0;
            var amount = parseInt(this.rewardForm.elements.amount.value || '0', 10) || 0;
            if (ch <= 0 || cu <= 0 || amount <= 0) { Toast.show({ body: 'Personaggio, valuta e importo obbligatori.', type: 'warning' }); return; }
            var self = this;
            this.request('/admin/system-events/rewards/assign', { event_id: this.selectedEventId, character_id: ch, currency_id: cu, amount: amount }, function () {
                Toast.show({ body: 'Ricompensa assegnata.', type: 'success' });
                self.loadRewardsLog();
            });
        },

        loadRewardsLog: function () {
            var self = this;
            this.request('/admin/system-events/rewards/log', { event_id: this.selectedEventId, limit: 50, page: 1 }, function (res) {
                var ds = res && res.dataset ? res.dataset : {};
                var rows = Array.isArray(ds.rows) ? ds.rows : self.parseRows(res);
                self.renderRewardsLog(rows);
            });
        },

        renderRewardsLog: function (rows) {
            var box = document.getElementById('admin-system-event-rewards-log');
            if (!box) { return; }
            if (!rows.length) { box.innerHTML = '<div class="small text-muted">Nessuna ricompensa registrata.</div>'; return; }
            var self = this;
            box.innerHTML = rows.map(function (r) {
                return '<div class="list-group-item d-flex justify-content-between align-items-center"><div><b>' + self.e(r.character_name || ('PG #' + (r.character_id || '-'))) + '</b> + ' + self.e(r.amount || 0) + ' ' + self.e(r.currency_name || ('Valuta #' + (r.currency_id || '-'))) + '</div><div class="small text-muted">' + self.e(self.fmtDate(r.date_created) || '-') + '</div></div>';
            }).join('');
        },

        bindAutocompleteInputs: function () {
            var self = this;
            if (this.effectForm) {
                var effectCurrency = this.effectForm.querySelector('[data-role="admin-system-event-currency-label"]');
                if (effectCurrency) {
                    effectCurrency.addEventListener('input', function () {
                        self.debounce('effect-currency', 180, function () {
                            self.searchCurrencySuggestions(effectCurrency, self.effectForm.elements.currency_id, self.effectForm.querySelector('[data-role="admin-system-event-currency-suggestions"]'), 'admin-system-event-currency-suggestion');
                        });
                    });
                }
            }
            if (this.participationForm) {
                var participant = this.participationForm.querySelector('[data-role="admin-system-event-participant-label"]');
                var participantMode = this.participationForm.querySelector('[data-role="admin-system-event-participant-mode"]');
                if (participant) {
                    participant.addEventListener('input', function () {
                        self.debounce('participant', 180, function () { self.searchParticipantSuggestions(); });
                    });
                }
                if (participantMode) {
                    participantMode.addEventListener('change', function () {
                        self.participationForm.elements.character_id.value = '0';
                        self.participationForm.elements.faction_id.value = '0';
                        if (participant) { participant.value = ''; }
                        self.hideSuggestions(self.participationForm.querySelector('[data-role="admin-system-event-participant-suggestions"]'));
                    });
                }
            }
            if (this.rewardForm) {
                var rewardCharacter = this.rewardForm.querySelector('[data-role="admin-system-event-reward-character-label"]');
                var rewardCurrency = this.rewardForm.querySelector('[data-role="admin-system-event-reward-currency-label"]');
                if (rewardCharacter) {
                    rewardCharacter.addEventListener('input', function () {
                        self.debounce('reward-character', 180, function () {
                            self.searchCharacterSuggestions(rewardCharacter, self.rewardForm.elements.character_id, self.rewardForm.querySelector('[data-role="admin-system-event-reward-character-suggestions"]'), 'admin-system-event-reward-character-suggestion');
                        });
                    });
                }
                if (rewardCurrency) {
                    rewardCurrency.addEventListener('input', function () {
                        self.debounce('reward-currency', 180, function () {
                            self.searchCurrencySuggestions(rewardCurrency, self.rewardForm.elements.currency_id, self.rewardForm.querySelector('[data-role="admin-system-event-reward-currency-suggestions"]'), 'admin-system-event-reward-currency-suggestion');
                        });
                    });
                }
            }
        },

        handleSuggestionClick: function (event) {
            var node = event.target && event.target.closest ? event.target.closest('[data-role]') : null;
            if (!node) { return false; }
            var role = String(node.getAttribute('data-role') || '');
            if (role === 'admin-system-event-scope-suggestion') { event.preventDefault(); this.pickSuggestion(this.eventForm, node, 'scope_id', '[data-role="admin-system-event-scope-label"]'); return true; }
            if (role === 'admin-system-event-currency-suggestion') { event.preventDefault(); this.pickSuggestion(this.effectForm, node, 'currency_id', '[data-role="admin-system-event-currency-label"]'); return true; }
            if (role === 'admin-system-event-participant-suggestion') { event.preventDefault(); this.pickParticipantSuggestion(node); return true; }
            if (role === 'admin-system-event-reward-character-suggestion') { event.preventDefault(); this.pickSuggestion(this.rewardForm, node, 'character_id', '[data-role="admin-system-event-reward-character-label"]'); return true; }
            if (role === 'admin-system-event-reward-currency-suggestion') { event.preventDefault(); this.pickSuggestion(this.rewardForm, node, 'currency_id', '[data-role="admin-system-event-reward-currency-label"]'); return true; }
            return false;
        },

        hideOutsideSuggestions: function (target) {
            var pairs = [
                { box: '[data-role="admin-system-event-scope-suggestions"]', input: this.eventForm ? this.eventForm.querySelector('[data-role="admin-system-event-scope-label"]') : null },
                { box: '[data-role="admin-system-event-currency-suggestions"]', input: this.effectForm ? this.effectForm.querySelector('[data-role="admin-system-event-currency-label"]') : null },
                { box: '[data-role="admin-system-event-participant-suggestions"]', input: this.participationForm ? this.participationForm.querySelector('[data-role="admin-system-event-participant-label"]') : null },
                { box: '[data-role="admin-system-event-reward-character-suggestions"]', input: this.rewardForm ? this.rewardForm.querySelector('[data-role="admin-system-event-reward-character-label"]') : null },
                { box: '[data-role="admin-system-event-reward-currency-suggestions"]', input: this.rewardForm ? this.rewardForm.querySelector('[data-role="admin-system-event-reward-currency-label"]') : null }
            ];
            for (var i = 0; i < pairs.length; i += 1) {
                var pair = pairs[i];
                var box = document.querySelector(pair.box);
                if (!box) { continue; }
                if (target.closest(pair.box) || target === pair.input) { continue; }
                this.hideSuggestions(box);
            }
        },

        searchScopeSuggestions: function () {
            var scopeType = String(this.eventForm.elements.scope_type.value || 'global');
            var labelInput = this.eventForm.querySelector('[data-role="admin-system-event-scope-label"]');
            var hidden = this.eventForm.elements.scope_id;
            var box = this.eventForm.querySelector('[data-role="admin-system-event-scope-suggestions"]');
            if (!labelInput || !hidden || !box || scopeType === 'global') { return; }
            hidden.value = '0';
            if (scopeType === 'character') { this.searchCharacterSuggestions(labelInput, hidden, box, 'admin-system-event-scope-suggestion'); return; }
            var endpoint = scopeType === 'faction' ? '/admin/factions/list' : (scopeType === 'location' ? '/list/locations' : '/list/maps');
            var query = String(labelInput.value || '').trim().toLowerCase();
            var self = this;
            this.request(endpoint, { limit: 400, page: 1 }, function (res) {
                var rows = self.parseRows(res).map(function (r) { return { id: parseInt(r.id || r.map_id || r.location_id || '0', 10) || 0, label: String(r.name || r.code || r.title || '').trim() }; }).filter(function (r) { return r.id > 0 && r.label && ((query === '') || r.label.toLowerCase().indexOf(query) !== -1); }).slice(0, 12);
                self.renderSuggestionBox(box, rows, 'admin-system-event-scope-suggestion');
            }, function () { self.hideSuggestions(box); });
        },

        searchParticipantSuggestions: function () {
            if (!this.participationForm) { return; }
            var mode = String(this.participationForm.elements.participant_mode.value || 'character');
            var input = this.participationForm.querySelector('[data-role="admin-system-event-participant-label"]');
            var box = this.participationForm.querySelector('[data-role="admin-system-event-participant-suggestions"]');
            if (!input || !box) { return; }
            this.participationForm.elements.character_id.value = '0';
            this.participationForm.elements.faction_id.value = '0';
            var query = String(input.value || '').trim().toLowerCase();
            var self = this;
            if (mode === 'faction') {
                this.request('/admin/factions/list', { limit: 400, page: 1 }, function (res) {
                    var rows = self.parseRows(res).map(function (r) { return { id: parseInt(r.id || r.faction_id || '0', 10) || 0, label: String(r.name || r.code || '').trim(), mode: 'faction' }; }).filter(function (r) { return r.id > 0 && r.label && ((query === '') || r.label.toLowerCase().indexOf(query) !== -1); }).slice(0, 12);
                    self.renderSuggestionBox(box, rows, 'admin-system-event-participant-suggestion');
                }, function () { self.hideSuggestions(box); });
                return;
            }
            this.searchCharacterSuggestions(input, this.participationForm.elements.character_id, box, 'admin-system-event-participant-suggestion');
        },

        searchCharacterSuggestions: function (input, hidden, box, role) {
            var query = String(input.value || '').trim();
            hidden.value = '0';
            if (query.length < 2) { this.hideSuggestions(box); return; }
            var self = this;
            this.request('/list/characters/search', { query: query }, function (res) {
                var rows = self.parseRows(res).map(function (r) {
                    var id = parseInt(r.id || r.character_id || '0', 10) || 0;
                    var label = String((r.name || '') + ' ' + (r.surname || '')).trim();
                    return { id: id, label: label || ('PG #' + id), mode: role === 'admin-system-event-participant-suggestion' ? 'character' : '' };
                }).filter(function (r) { return r.id > 0; }).slice(0, 12);
                self.renderSuggestionBox(box, rows, role);
            }, function () { self.hideSuggestions(box); });
        },

        searchCurrencySuggestions: function (input, hidden, box, role) {
            var query = String(input.value || '').trim().toLowerCase();
            hidden.value = '0';
            var self = this;
            this.request('/admin/currencies/list', { limit: 400, page: 1 }, function (res) {
                var rows = self.parseRows(res).map(function (r) {
                    var id = parseInt(r.id || r.currency_id || '0', 10) || 0;
                    var label = String(r.name || '').trim();
                    if (r.code) { label += ' (' + r.code + ')'; }
                    return { id: id, label: label || ('Valuta #' + id) };
                }).filter(function (r) { return r.id > 0 && ((query === '') || r.label.toLowerCase().indexOf(query) !== -1); }).slice(0, 12);
                self.renderSuggestionBox(box, rows, role);
            }, function () { self.hideSuggestions(box); });
        },

        renderSuggestionBox: function (box, rows, role) {
            if (!box) { return; }
            box.innerHTML = '';
            if (!rows.length) { box.classList.add('d-none'); return; }
            for (var i = 0; i < rows.length; i += 1) {
                var row = rows[i];
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'list-group-item list-group-item-action small py-1';
                btn.setAttribute('data-role', role);
                btn.setAttribute('data-id', String(row.id || 0));
                btn.setAttribute('data-label', String(row.label || ''));
                if (row.mode) { btn.setAttribute('data-mode', row.mode); }
                btn.textContent = String(row.label || '');
                box.appendChild(btn);
            }
            box.classList.remove('d-none');
        },

        pickSuggestion: function (form, node, hiddenName, labelSelector) {
            if (!form || !node) { return; }
            var id = parseInt(node.getAttribute('data-id') || '0', 10) || 0;
            if (id <= 0) { return; }
            form.elements[hiddenName].value = String(id);
            var input = form.querySelector(labelSelector);
            if (input) { input.value = String(node.getAttribute('data-label') || ''); }
            this.hideSuggestions(node.parentElement);
        },

        pickParticipantSuggestion: function (node) {
            if (!this.participationForm || !node) { return; }
            var id = parseInt(node.getAttribute('data-id') || '0', 10) || 0;
            if (id <= 0) { return; }
            var mode = String(node.getAttribute('data-mode') || this.participationForm.elements.participant_mode.value || 'character');
            this.participationForm.elements.character_id.value = mode === 'character' ? String(id) : '0';
            this.participationForm.elements.faction_id.value = mode === 'faction' ? String(id) : '0';
            var input = this.participationForm.querySelector('[data-role="admin-system-event-participant-label"]');
            if (input) { input.value = String(node.getAttribute('data-label') || ''); }
            this.hideSuggestions(node.parentElement);
        },

        hideSuggestions: function (box) {
            if (!box) { return; }
            box.classList.add('d-none');
            box.innerHTML = '';
        },

        setScopeInputState: function () {
            if (!this.eventForm) { return; }
            var scopeType = String(this.eventForm.elements.scope_type ? (this.eventForm.elements.scope_type.value || 'global') : 'global').toLowerCase();
            var input = this.eventForm.querySelector('[data-role="admin-system-event-scope-label"]');
            var box = this.eventForm.querySelector('[data-role="admin-system-event-scope-suggestions"]');
            if (!input) { return; }

            var placeholderMap = {
                global: 'Nessun target richiesto',
                map: 'Cerca mappa...',
                location: 'Cerca luogo...',
                faction: 'Cerca fazione...',
                character: 'Cerca personaggio...'
            };

            if (scopeType === 'global') {
                input.value = '';
                input.disabled = true;
                if (this.eventForm.elements.scope_id) { this.eventForm.elements.scope_id.value = '0'; }
                this.hideSuggestions(box);
            } else {
                input.disabled = false;
            }

            input.placeholder = placeholderMap[scopeType] || 'Cerca...';
        },

        parseRows: function (response) {
            if (!response) { return []; }
            var dataset = response.dataset;
            if (Array.isArray(dataset)) { return dataset; }
            if (dataset && Array.isArray(dataset.rows)) { return dataset.rows; }
            if (response.properties && Array.isArray(response.properties.dataset)) { return response.properties.dataset; }
            return [];
        },

        request: function (url, payload, onSuccess, onError) {
            var self = this;
            if (!window.Request || !Request.http || typeof Request.http.post !== 'function') {
                Toast.show({ body: 'Servizio non disponibile.', type: 'error' });
                return this;
            }

            Request.http.post(String(url || ''), payload || {}).then(function (response) {
                if (typeof onSuccess === 'function') {
                    onSuccess(response || null);
                }
            }).catch(function (error) {
                if (typeof onError === 'function') {
                    onError(error || null);
                    return;
                }
                Toast.show({ body: self.errMsg(error), type: 'error' });
            });
            return this;
        },

        errMsg: function (error) {
            if (window.Request && typeof window.Request.getErrorMessage === 'function') {
                return window.Request.getErrorMessage(error, 'Operazione non riuscita.');
            }
            if (error && typeof error.message === 'string' && error.message.trim() !== '') {
                return error.message.trim();
            }
            return 'Operazione non riuscita.';
        },

        showModal: function (id) {
            var node = document.getElementById(id);
            if (!node) { return; }
            if (window.bootstrap && window.bootstrap.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(node).show();
                return;
            }
            if (typeof window.$ === 'function') {
                window.$(node).modal('show');
            }
        },

        hideModal: function (id) {
            var node = document.getElementById(id);
            if (!node) { return; }
            if (window.bootstrap && window.bootstrap.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(node).hide();
                return;
            }
            if (typeof window.$ === 'function') {
                window.$(node).modal('hide');
            }
        },

        hideConfirm: function () {
            if (window.SystemDialogs && typeof window.SystemDialogs.ensureGeneralConfirm === 'function') {
                var dialog = window.SystemDialogs.ensureGeneralConfirm();
                if (dialog && typeof dialog.hide === 'function') {
                    dialog.hide();
                    return;
                }
            }
            if (window.generalConfirm && typeof window.generalConfirm.hide === 'function') {
                window.generalConfirm.hide();
            }
        },

        toInputDate: function (value) {
            var raw = String(value || '').trim();
            if (!raw) { return ''; }
            var normalized = raw.replace(' ', 'T');
            if (normalized.length >= 16) {
                return normalized.substring(0, 16);
            }
            return normalized;
        },

        toMysqlDate: function (value) {
            var raw = String(value || '').trim();
            if (!raw) { return null; }
            if (raw.indexOf('T') !== -1) {
                return raw.replace('T', ' ') + ':00';
            }
            return raw;
        },

        fmtDate: function (value) {
            var raw = String(value || '').trim();
            if (!raw) { return ''; }
            var date = new Date(raw.replace(' ', 'T'));
            if (isNaN(date.getTime())) { return raw; }
            var dd = String(date.getDate()).padStart(2, '0');
            var mm = String(date.getMonth() + 1).padStart(2, '0');
            var yyyy = String(date.getFullYear());
            var hh = String(date.getHours()).padStart(2, '0');
            var ii = String(date.getMinutes()).padStart(2, '0');
            return dd + '/' + mm + '/' + yyyy + ' ' + hh + ':' + ii;
        },

        statusLabel: function (status) {
            var map = {
                draft: 'Bozza',
                scheduled: 'Programmato',
                active: 'Attivo',
                completed: 'Completato',
                cancelled: 'Annullato'
            };
            var key = String(status || '').toLowerCase();
            return map[key] || (key || '-');
        },

        statusBadge: function (status) {
            var map = {
                draft: 'text-bg-secondary',
                scheduled: 'text-bg-info',
                active: 'text-bg-success',
                completed: 'text-bg-primary',
                cancelled: 'text-bg-danger'
            };
            return map[String(status || '').toLowerCase()] || 'text-bg-dark';
        },

        visibilityLabel: function (visibility) {
            var map = {
                public: 'Pubblica',
                private: 'Privata',
                staff_only: 'Solo staff'
            };
            var key = String(visibility || '').toLowerCase();
            return map[key] || (key || '-');
        },

        scopeLabel: function (scope) {
            var map = {
                global: 'Globale',
                map: 'Mappa',
                location: 'Luogo',
                faction: 'Fazione',
                character: 'Personaggio'
            };
            var key = String(scope || '').toLowerCase();
            return map[key] || (key || '-');
        },

        participantLabel: function (mode) {
            return String(mode || '').toLowerCase() === 'faction' ? 'Fazione' : 'Personaggio';
        },

        debounce: function (key, delayMs, fn) {
            var id = String(key || '');
            if (!id || typeof fn !== 'function') { return; }
            if (this.debounceTimers[id]) {
                window.clearTimeout(this.debounceTimers[id]);
                this.debounceTimers[id] = null;
            }
            this.debounceTimers[id] = window.setTimeout(function () { fn(); }, Math.max(0, parseInt(delayMs, 10) || 0));
        },

        e: function (value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        },

        typeLabel: function (type) {
            var map = {
                general: 'Generale',
                war: 'Guerra',
                festival: 'Festival',
                tournament: 'Torneo',
                raid: 'Incursione',
                seasonal: 'Stagionale',
                diplomatic: 'Diplomatico',
                crisis: 'Crisi',
                ceremony: 'Cerimonia'
            };
            var key = String(type || '').toLowerCase();
            return map[key] || (key || '-');
        },

        participationStatusLabel: function (status) {
            var map = {
                joined: 'Aderisce',
                left: 'Uscito',
                completed: 'Completato',
                cancelled: 'Annullato',
                pending: 'In attesa'
            };
            var key = String(status || '').toLowerCase();
            return map[key] || (key || '-');
        },

        setTypeValue: function (value) {
            if (!this.eventForm) { return; }
            var select = this.eventForm.elements.type;
            if (!select) { return; }
            var normalized = String(value || 'general').trim();
            var prev = select.querySelector('[data-custom-type]');
            if (prev) { select.removeChild(prev); }
            select.value = normalized;
            if (select.value !== normalized) {
                var option = document.createElement('option');
                option.value = normalized;
                option.textContent = 'Personalizzato: ' + normalized;
                option.setAttribute('data-custom-type', '1');
                select.appendChild(option);
                select.value = normalized;
            }
        },

        findRowByTrigger: function (trigger) {
            var id = parseInt(trigger.getAttribute('data-id') || '0', 10) || 0;
            return id > 0 ? (this.rowsById[id] || { id: id }) : null;
        }
    };

    window.AdminSystemEvents = AdminSystemEvents;
})();
