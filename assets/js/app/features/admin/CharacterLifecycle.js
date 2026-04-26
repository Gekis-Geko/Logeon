const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

var AdminCharacterLifecycle = {
    initialized: false,
    root: null,
    grid: null,
    rowsById: {},
    phaseForm: null,
    transitionForm: null,
    phases: [],
    transitionNameInput: null,
    transitionIdInput: null,
    transitionSuggestions: null,
    transitionSearchTimer: null,

    init: function () {
        if (this.initialized) { return this; }

        this.root = document.querySelector('#admin-page [data-admin-page="character-lifecycle"]');
        if (!this.root) { return this; }

        this.phaseForm      = document.getElementById('admin-lifecycle-phase-form');
        this.transitionForm = document.getElementById('admin-lifecycle-transition-form');
        this.transitionNameInput = document.getElementById('admin-lifecycle-transition-character-name');
        this.transitionIdInput = document.getElementById('admin-lifecycle-transition-character-id');
        this.transitionSuggestions = document.getElementById('admin-lifecycle-transition-character-suggestions');

        if (!this.phaseForm || !document.getElementById('grid-admin-lifecycle-phases')) { return this; }

        this.bindEvents();
        this.initGrid();
        this.loadGrid();
        this.loadPhasesForSelect();

        this.initialized = true;
        return this;
    },

    bindEvents: function () {
        var self = this;

        this.root.addEventListener('click', function (event) {
            var suggestion = event.target && event.target.closest ? event.target.closest('[data-role="admin-lifecycle-character-suggestion"]') : null;
            if (suggestion) {
                event.preventDefault();
                self.selectTransitionCharacter(suggestion);
                return;
            }

            var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
            if (!trigger) { return; }
            var action = String(trigger.getAttribute('data-action') || '').trim();

            switch (action) {
                case 'admin-lifecycle-phases-reload':      event.preventDefault(); self.loadGrid(); break;
                case 'admin-lifecycle-phase-create':       event.preventDefault(); self.openPhaseModal('create'); break;
                case 'admin-lifecycle-phase-save':         event.preventDefault(); self.savePhase(); break;
                case 'admin-lifecycle-phase-edit':         event.preventDefault(); self.openPhaseModal('edit', self.findRowByTrigger(trigger)); break;
                case 'admin-lifecycle-phase-delete':       event.preventDefault(); self.confirmPhaseDelete(self.findRowByTrigger(trigger)); break;
                case 'admin-lifecycle-transition-lookup':  event.preventDefault(); self.lookupCharacterPhase(); break;
                case 'admin-lifecycle-transition-save':    event.preventDefault(); self.saveTransition(); break;
            }
        });

        if (this.transitionNameInput) {
            this.transitionNameInput.addEventListener('input', function () {
                self.handleTransitionCharacterInput();
            });
        }

        document.addEventListener('click', function (event) {
            if (!self.transitionSuggestions || !self.transitionNameInput) { return; }
            if (!event.target.closest || (!event.target.closest('#admin-lifecycle-transition-character-suggestions') && event.target !== self.transitionNameInput)) {
                self.hideTransitionSuggestions(true);
            }
        });
    },

    initGrid: function () {
        var self = this;

        this.grid = new Datagrid('grid-admin-lifecycle-phases', {
            name: 'AdminLifecyclePhases',
            autoindex: 'id',
            orderable: true,
            thead: true,
            handler: { url: '/admin/lifecycle/phases/list', action: 'list' },
            nav: { display: 'bottom', urlupdate: 0, results: 30, page: 1 },
            columns: [
                { label: 'ID', field: 'id', sortable: true },
                { label: 'Codice', field: 'code', sortable: true, style: { textAlign: 'left' } },
                {
                    label: 'Nome', field: 'name', sortable: true, style: { textAlign: 'left' },
                    format: function (row) {
                        var color = row.color_hex || '#6c757d';
                        var icon  = row.icon ? '<img src="' + self.escapeHtml(row.icon) + '" width="16" height="16" class="me-1" style="object-fit:contain;vertical-align:middle;" alt="">' : '';
                        return '<span style="color:' + self.escapeHtml(color) + '">' + icon + self.escapeHtml(row.name || '') + '</span>';
                    }
                },
                { label: 'Categoria', field: 'category', sortable: true, format: function (row) { return self.escapeHtml(row.category || '-'); } },
                { label: 'Ordine', field: 'sort_order', sortable: true },
                {
                    label: 'Iniziale', field: 'is_initial', sortable: true,
                    format: function (row) {
                        return parseInt(row.is_initial, 10) === 1
                            ? '<span class="badge text-bg-info">Si</span>'
                            : '<span class="badge text-bg-light text-dark">No</span>';
                    }
                },
                {
                    label: 'Terminale', field: 'is_terminal', sortable: true,
                    format: function (row) {
                        return parseInt(row.is_terminal, 10) === 1
                            ? '<span class="badge text-bg-danger">Si</span>'
                            : '<span class="badge text-bg-light text-dark">No</span>';
                    }
                },
                {
                    label: 'Attiva', field: 'is_active', sortable: true,
                    format: function (row) {
                        return parseInt(row.is_active, 10) === 1
                            ? '<span class="badge text-bg-success">Si</span>'
                            : '<span class="badge text-bg-secondary">No</span>';
                    }
                },
                {
                    label: 'Azioni', sortable: false, style: { textAlign: 'left' },
                    format: function (row) {
                        var id = parseInt(row.id, 10) || 0;
                        if (id > 0) { self.rowsById[id] = row; }
                        return '<div class="d-flex flex-wrap gap-1">'
                            + '<button type="button" class="btn btn-sm btn-outline-primary" data-action="admin-lifecycle-phase-edit" data-id="' + id + '">Modifica</button>'
                            + '<button type="button" class="btn btn-sm btn-outline-danger" data-action="admin-lifecycle-phase-delete" data-id="' + id + '">Elimina</button>'
                            + '</div>';
                    }
                }
            ]
        });
    },

    loadGrid: function () {
        if (!this.grid) { return this; }
        this.rowsById = {};
        this.grid.loadData({ include_inactive: 1 }, 30, 1, 'sort_order|ASC');
        return this;
    },

    loadPhasesForSelect: function () {
        var self = this;
        this.requestPost('/admin/lifecycle/phases/list', { include_inactive: 0 }, function (response) {
            self.phases = (response && response.dataset) ? response.dataset : [];
            self.renderPhaseSelect();
        });
    },

    renderPhaseSelect: function () {
        var select = document.getElementById('admin-lifecycle-transition-phase-select');
        if (!select) { return; }
        select.innerHTML = '<option value="">Seleziona fase...</option>';
        this.phases.forEach(function (p) {
            var opt = document.createElement('option');
            opt.value = p.id;
            opt.textContent = p.name + ' (' + p.code + ')';
            select.appendChild(opt);
        });
    },

    findRowByTrigger: function (trigger) {
        var id = parseInt(trigger.getAttribute('data-id') || '0', 10) || 0;
        return id > 0 ? (this.rowsById[id] || null) : null;
    },

    openPhaseModal: function (mode, row) {
        var createMode = (mode !== 'edit');

        if (this.phaseForm) {
            this.phaseForm.reset();
            this.phaseForm.elements.id.value          = '';
            this.phaseForm.elements.sort_order.value  = '0';
            this.phaseForm.elements.is_initial.value  = '0';
            this.phaseForm.elements.is_terminal.value = '0';
            this.phaseForm.elements.is_active.value   = '1';
            this.phaseForm.elements.visible_to_players.value = '1';
        }

        if (!createMode && row && this.phaseForm) {
            var f = this.phaseForm.elements;
            f.id.value          = String(row.id || '');
            f.code.value        = row.code || '';
            f.name.value        = row.name || '';
            f.description.value = row.description || '';
            f.category.value    = row.category || '';
            f.sort_order.value  = String(row.sort_order != null ? row.sort_order : '0');
            f.is_initial.value  = parseInt(row.is_initial, 10) === 1 ? '1' : '0';
            f.is_terminal.value = parseInt(row.is_terminal, 10) === 1 ? '1' : '0';
            f.is_active.value   = parseInt(row.is_active, 10) === 1 ? '1' : '0';
            f.visible_to_players.value = parseInt(row.visible_to_players, 10) === 1 ? '1' : '0';
            f.color_hex.value   = row.color_hex || '';
            f.icon.value  = row.icon || '';
        }

        this.showModal('admin-lifecycle-phase-modal');
    },

    savePhase: function () {
        if (!this.phaseForm) { return; }
        var payload = this.collectPhasePayload();
        if (!payload.code || !payload.name) {
            Toast.show({ body: 'Codice e nome sono obbligatori.', type: 'warning' });
            return;
        }
        var isEdit   = parseInt(payload.id || 0, 10) > 0;
        var endpoint = isEdit ? '/admin/lifecycle/phases/update' : '/admin/lifecycle/phases/create';
        var self     = this;
        this.requestPost(endpoint, payload, function () {
            self.hideModal('admin-lifecycle-phase-modal');
            Toast.show({ body: isEdit ? 'Fase aggiornata.' : 'Fase creata.', type: 'success' });
            self.loadGrid();
            self.loadPhasesForSelect();
        });
    },

    collectPhasePayload: function () {
        var f = this.phaseForm.elements;
        var payload = {
            code:               String(f.code.value || '').trim(),
            name:               String(f.name.value || '').trim(),
            description:        String(f.description.value || '').trim(),
            category:           String(f.category.value || '').trim(),
            sort_order:         parseInt(f.sort_order.value || '0', 10) || 0,
            is_initial:         parseInt(f.is_initial.value || '0', 10) === 1 ? 1 : 0,
            is_terminal:        parseInt(f.is_terminal.value || '0', 10) === 1 ? 1 : 0,
            is_active:          parseInt(f.is_active.value || '1', 10) === 1 ? 1 : 0,
            visible_to_players: parseInt(f.visible_to_players.value || '1', 10) === 1 ? 1 : 0,
            color_hex:          String(f.color_hex.value || '').trim(),
            icon:         String(f.icon.value || '').trim()
        };
        var id = parseInt(f.id.value || '0', 10) || 0;
        if (id > 0) { payload.id = id; }
        return payload;
    },

    confirmPhaseDelete: function (row) {
        if (!row || !row.id) { return; }
        var self = this;
        Dialog('danger', {
            title: 'Elimina fase',
            body: '<p>Confermi l\'eliminazione della fase <b>' + this.escapeHtml(row.name || row.code || '') + '</b>?</p>'
                + '<p class="small text-muted">Non eliminabile se assegnata a personaggi.</p>'
        }, function () {
            self.hideConfirmDialog();
            self.requestPost('/admin/lifecycle/phases/delete', { id: row.id }, function () {
                Toast.show({ body: 'Fase eliminata.', type: 'success' });
                self.loadGrid();
                self.loadPhasesForSelect();
            });
        }).show();
    },

    lookupCharacterPhase: function () {
        var charIdEl  = this.transitionIdInput || document.getElementById('admin-lifecycle-transition-character-id');
        var resultEl  = document.getElementById('admin-lifecycle-character-current');
        if (!charIdEl || !resultEl) { return; }
        var characterId = parseInt(charIdEl.value || '0', 10) || 0;
        if (characterId <= 0) {
            Toast.show({ body: 'Seleziona un personaggio dalla lista.', type: 'warning' });
            return;
        }
        this.requestPost('/admin/lifecycle/characters/current', { character_id: characterId }, function (response) {
            var phase = response && response.dataset;
            resultEl.textContent = !phase
                ? 'Nessuna fase assegnata.'
                : 'Fase attuale: ' + (phase.phase_name || phase.to_phase_name || '-')
                    + ' (' + (phase.phase_code || phase.to_phase_code || '-') + ')';
        });
    },

    saveTransition: function () {
        if (!this.transitionForm) { return; }
        var f           = this.transitionForm.elements;
        var characterId = parseInt(f.character_id.value || '0', 10) || 0;
        var toPhaseId   = parseInt(f.to_phase_id.value || '0', 10) || 0;
        if (characterId <= 0) { Toast.show({ body: 'Seleziona un personaggio dalla lista.', type: 'warning' }); return; }
        if (toPhaseId <= 0)   { Toast.show({ body: 'Seleziona una fase destinazione.', type: 'warning' }); return; }

        var self = this;
        this.requestPost('/admin/lifecycle/characters/transition', {
            character_id: characterId,
            to_phase_id:  toPhaseId,
            triggered_by: String(f.triggered_by.value || 'admin').trim(),
            notes:        String(f.notes.value || '').trim()
        }, function () {
            Toast.show({ body: 'Transizione applicata.', type: 'success' });
            self.transitionForm.reset();
            if (self.transitionIdInput) { self.transitionIdInput.value = ''; }
            self.hideTransitionSuggestions(true);
            var resultEl = document.getElementById('admin-lifecycle-character-current');
            if (resultEl) { resultEl.textContent = ''; }
            self.loadPhasesForSelect();
        });
    },

    handleTransitionCharacterInput: function () {
        var self = this;
        if (!this.transitionNameInput || !this.transitionIdInput) { return; }
        var query = String(this.transitionNameInput.value || '').trim();
        this.transitionIdInput.value = '';

        if (this.transitionSearchTimer) {
            globalWindow.clearTimeout(this.transitionSearchTimer);
            this.transitionSearchTimer = null;
        }
        if (query.length < 2) {
            this.hideTransitionSuggestions(true);
            return;
        }

        this.transitionSearchTimer = globalWindow.setTimeout(function () {
            self.requestPost('/list/characters/search', { query: query }, function (response) {
                self.renderTransitionSuggestions(response && response.dataset ? response.dataset : []);
            }, function () {
                self.hideTransitionSuggestions(true);
            });
        }, 180);
    },

    renderTransitionSuggestions: function (rows) {
        if (!this.transitionSuggestions) { return; }
        this.transitionSuggestions.innerHTML = '';
        if (!Array.isArray(rows) || rows.length === 0) {
            this.transitionSuggestions.classList.add('d-none');
            return;
        }

        for (var i = 0; i < rows.length; i += 1) {
            var row = rows[i] || {};
            var id = parseInt(row.id || '0', 10) || 0;
            if (id <= 0) { continue; }
            var label = (String(row.name || '') + ' ' + String(row.surname || '')).trim();
            if (!label) { label = 'PG #' + id; }

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'list-group-item list-group-item-action small py-1';
            btn.setAttribute('data-role', 'admin-lifecycle-character-suggestion');
            btn.setAttribute('data-character-id', String(id));
            btn.setAttribute('data-character-label', label);
            btn.textContent = label;
            this.transitionSuggestions.appendChild(btn);
        }

        if (this.transitionSuggestions.children.length === 0) {
            this.transitionSuggestions.classList.add('d-none');
            return;
        }
        this.transitionSuggestions.classList.remove('d-none');
    },

    selectTransitionCharacter: function (node) {
        if (!node || !this.transitionNameInput || !this.transitionIdInput) { return; }
        var id = parseInt(node.getAttribute('data-character-id') || '0', 10) || 0;
        var label = String(node.getAttribute('data-character-label') || '').trim();
        if (id <= 0) { return; }
        this.transitionIdInput.value = String(id);
        this.transitionNameInput.value = label;
        this.hideTransitionSuggestions(true);
    },

    hideTransitionSuggestions: function (clear) {
        if (!this.transitionSuggestions) { return; }
        this.transitionSuggestions.classList.add('d-none');
        if (clear === true) {
            this.transitionSuggestions.innerHTML = '';
        }
    },

    requestPost: function (url, payload, onSuccess, onError) {
        var self = this;
        if (!globalWindow.Request || !Request.http || typeof Request.http.post !== 'function') {
            Toast.show({ body: 'Servizio non disponibile.', type: 'error' });
            return this;
        }
        Request.http.post(url, payload || {}).then(function (r) {
            if (typeof onSuccess === 'function') { onSuccess(r || null); }
        }).catch(function (e) {
            if (typeof onError === 'function') { onError(e); return; }
            Toast.show({ body: self.requestErrorMessage(e), type: 'error' });
        });
        return this;
    },

    requestErrorMessage: function (error) {
        if (globalWindow.Request && typeof globalWindow.Request.getErrorMessage === 'function') {
            return globalWindow.Request.getErrorMessage(error, 'Operazione non riuscita.');
        }
        if (error && typeof error.message === 'string' && error.message.trim()) { return error.message.trim(); }
        return 'Operazione non riuscita.';
    },

    showModal: function (id) {
        var n = document.getElementById(id);
        if (!n) { return; }
        if (globalWindow.bootstrap && globalWindow.bootstrap.Modal) { globalWindow.bootstrap.Modal.getOrCreateInstance(n).show(); return; }
        if (typeof $ === 'function') { $(n).modal('show'); }
    },

    hideModal: function (id) {
        var n = document.getElementById(id);
        if (!n) { return; }
        if (globalWindow.bootstrap && globalWindow.bootstrap.Modal) { globalWindow.bootstrap.Modal.getOrCreateInstance(n).hide(); return; }
        if (typeof $ === 'function') { $(n).modal('hide'); }
    },

    hideConfirmDialog: function () {
        if (globalWindow.SystemDialogs && typeof globalWindow.SystemDialogs.ensureGeneralConfirm === 'function') {
            var d = globalWindow.SystemDialogs.ensureGeneralConfirm();
            if (d && typeof d.hide === 'function') { d.hide(); }
        } else if (globalWindow.generalConfirm && typeof globalWindow.generalConfirm.hide === 'function') {
            globalWindow.generalConfirm.hide();
        }
    },

    escapeHtml: function (value) {
        return String(value || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }
};

globalWindow.AdminCharacterLifecycle = AdminCharacterLifecycle;
export { AdminCharacterLifecycle as AdminCharacterLifecycle };
export default AdminCharacterLifecycle;

