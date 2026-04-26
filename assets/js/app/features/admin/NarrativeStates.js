const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

var AdminNarrativeStates = {
    initialized: false,
    root: null,
    grid: null,
    rowsById: {},
    form: null,
    includeInactive: null,
    filterSearch: null,
    filterCategory: null,
    filterScope: null,
    filterStackMode: null,
    filterVisible: null,
    includeInactiveSwitch: null,
    formSwitches: {},
    debounceTimers: {},
    valuesCache: null,

    init: function () {
        if (this.initialized) {
            return this;
        }

        this.root = document.querySelector('#admin-page [data-admin-page="narrative-states"]');
        if (!this.root) {
            return this;
        }

        this.form = document.getElementById('admin-narrative-state-form');
        this.includeInactive = document.getElementById('admin-narrative-states-include-inactive');
        this.filterSearch = document.getElementById('admin-narrative-states-filter-search');
        this.filterCategory = document.getElementById('admin-narrative-states-filter-category');
        this.filterScope = document.getElementById('admin-narrative-states-filter-scope');
        this.filterStackMode = document.getElementById('admin-narrative-states-filter-stack-mode');
        this.filterVisible = document.getElementById('admin-narrative-states-filter-visible');
        if (!this.form || !document.getElementById('grid-admin-narrative-states')) {
            return this;
        }

        this.initSwitches();
        this.bindEvents();
        this.initGrid();
        this.loadGrid();

        this.initialized = true;
        return this;
    },

    bindEvents: function () {
        var self = this;

        if (this.includeInactive) {
            this.includeInactive.addEventListener('change', function () {
                self.loadGrid();
            });
        }
        if (this.filterScope) {
            this.filterScope.addEventListener('change', function () {
                self.loadGrid();
            });
        }
        if (this.filterStackMode) {
            this.filterStackMode.addEventListener('change', function () {
                self.loadGrid();
            });
        }
        if (this.filterVisible) {
            this.filterVisible.addEventListener('change', function () {
                self.loadGrid();
            });
        }
        if (this.filterSearch) {
            this.filterSearch.addEventListener('input', function () {
                self.debounce('narrative-states-search', 220, function () {
                    self.loadGrid();
                });
            });
        }
        if (this.filterCategory) {
            this.filterCategory.addEventListener('input', function () {
                self.debounce('narrative-states-category', 220, function () {
                    self.loadGrid();
                });
            });
        }

        this.root.addEventListener('click', function (event) {
            var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
            if (!trigger) {
                return;
            }

            var action = String(trigger.getAttribute('data-action') || '').trim();
            if (action === 'admin-narrative-states-reload') {
                event.preventDefault();
                self.loadGrid();
                return;
            }
            if (action === 'admin-narrative-states-create') {
                event.preventDefault();
                self.openModal('create');
                return;
            }
            if (action === 'admin-narrative-states-filters-reset') {
                event.preventDefault();
                if (self.filterSearch) { self.filterSearch.value = ''; }
                if (self.filterCategory) { self.filterCategory.value = ''; }
                if (self.filterScope) { self.filterScope.value = ''; }
                if (self.filterStackMode) { self.filterStackMode.value = ''; }
                if (self.filterVisible) { self.filterVisible.value = ''; }
                if (self.includeInactiveSwitch && typeof self.includeInactiveSwitch.setValue === 'function') {
                    self.includeInactiveSwitch.setValue('0');
                } else if (self.includeInactive) {
                    self.includeInactive.value = '0';
                }
                self.loadGrid();
                return;
            }
            if (action === 'admin-narrative-state-save') {
                event.preventDefault();
                self.saveState();
                return;
            }
            if (action === 'admin-narrative-state-edit') {
                event.preventDefault();
                self.openModal('edit', self.findRowByTrigger(trigger));
                return;
            }
            if (action === 'admin-narrative-state-delete') {
                event.preventDefault();
                self.confirmDelete(self.findRowByTrigger(trigger));
            }
        });
    },

    initSwitches: function () {
        if (typeof globalWindow.SwitchGroup !== 'function') {
            return;
        }

        if (this.includeInactive) {
            this.includeInactiveSwitch = globalWindow.SwitchGroup(this.includeInactive, {
                trueValue: '0',
                falseValue: '1',
                defaultValue: '0',
                trueLabel: 'Solo attivi',
                falseLabel: 'Mostra disattivati'
            });
        }

        this.formSwitches = this.formSwitches || {};

        if (this.form && this.form.elements && this.form.elements.is_active) {
            this.formSwitches.is_active = globalWindow.SwitchGroup(this.form.elements.is_active, {
                trueValue: '1',
                falseValue: '0',
                defaultValue: '1',
                trueLabel: 'Attivo',
                falseLabel: 'Disattivo',
                preset: 'activeInactive'
            });
        }

        if (this.form && this.form.elements && this.form.elements.visible_to_players) {
            this.formSwitches.visible_to_players = globalWindow.SwitchGroup(this.form.elements.visible_to_players, {
                trueValue: '1',
                falseValue: '0',
                defaultValue: '1',
                trueLabel: 'Visibile',
                falseLabel: 'Nascosto'
            });
        }
    },

    refreshSwitches: function () {
        if (!this.formSwitches) {
            return;
        }

        if (this.formSwitches.is_active && typeof this.formSwitches.is_active.refresh === 'function') {
            this.formSwitches.is_active.refresh();
        }
        if (this.formSwitches.visible_to_players && typeof this.formSwitches.visible_to_players.refresh === 'function') {
            this.formSwitches.visible_to_players.refresh();
        }
    },

    initGrid: function () {
        var self = this;

        this.grid = new Datagrid('grid-admin-narrative-states', {
            name: 'AdminNarrativeStates',
            autoindex: 'id',
            orderable: true,
            thead: true,
            handler: {
                url: '/admin/narrative-states/list',
                action: 'list'
            },
            nav: {
                display: 'bottom',
                urlupdate: 0,
                results: 20,
                page: 1
            },
            columns: [
                { label: 'ID', field: 'id', sortable: true, style: { width: '55px' } },
                {
                    label: 'Codice',
                    field: 'code',
                    sortable: true,
                    style: { textAlign: 'left' },
                    format: function (row) {
                        return '<code class="small">' + self.escapeHtml(row.code || '-') + '</code>';
                    }
                },
                {
                    label: 'Nome',
                    field: 'name',
                    sortable: true,
                    style: { textAlign: 'left' },
                    format: function (row) {
                        var name = self.escapeHtml(row.name || '-');
                        var cat = row.category ? '<div class="small text-muted">' + self.escapeHtml(row.category) + '</div>' : '';
                        return name + cat;
                    }
                },
                {
                    label: 'Ambito',
                    field: 'scope',
                    sortable: true,
                    style: { textAlign: 'center' },
                    format: function (row) {
                        return self.scopeBadge(row.scope);
                    }
                },
                {
                    label: 'Accumulo',
                    field: 'stack_mode',
                    sortable: true,
                    style: { textAlign: 'center' },
                    format: function (row) {
                        return self.stackModeBadge(row.stack_mode, row.max_stacks);
                    }
                },
                {
                    label: 'Gruppo conflitto',
                    field: 'conflict_group',
                    sortable: true,
                    style: { textAlign: 'left' },
                    format: function (row) {
                        if (!row.conflict_group) { return '<span class="text-muted">—</span>'; }
                        return '<code class="small">' + self.escapeHtml(row.conflict_group) + '</code>'
                            + ' <span class="text-muted small">p.' + self.escapeHtml(String(row.priority || 0)) + '</span>';
                    }
                },
                {
                    label: 'Attivo',
                    field: 'is_active',
                    sortable: true,
                    style: { textAlign: 'center' },
                    format: function (row) {
                        return parseInt(row.is_active, 10) === 1
                            ? '<span class="badge text-bg-success">Si</span>'
                            : '<span class="badge text-bg-secondary">No</span>';
                    }
                },
                {
                    label: 'Visibile',
                    field: 'visible_to_players',
                    sortable: true,
                    style: { textAlign: 'center' },
                    format: function (row) {
                        return parseInt(row.visible_to_players, 10) === 1
                            ? '<span class="badge text-bg-info">Si</span>'
                            : '<span class="badge text-bg-light text-dark">No</span>';
                    }
                },
                {
                    label: 'Azioni',
                    sortable: false,
                    style: { textAlign: 'center', width: '100px' },
                    format: function (row) {
                        var id = parseInt(row.id, 10) || 0;
                        if (id > 0) {
                            self.rowsById[id] = row;
                        }
                        return '<div class="d-flex gap-1 justify-content-center">'
                            + '<button type="button" class="btn btn-sm btn-outline-primary" data-action="admin-narrative-state-edit" data-id="' + id + '"><i class="bi bi-pencil"></i></button>'
                            + '<button type="button" class="btn btn-sm btn-outline-danger" data-action="admin-narrative-state-delete" data-id="' + id + '"><i class="bi bi-trash"></i></button>'
                            + '</div>';
                    }
                }
            ]
        });
    },

    loadGrid: function () {
        if (!this.grid) {
            return this;
        }

        this.rowsById = {};
        this.grid.loadData({
            include_inactive: this.includeInactive && this.includeInactive.value === '1' ? 1 : 0,
            search:           this.filterSearch    ? String(this.filterSearch.value    || '').trim() : '',
            category:         this.filterCategory  ? String(this.filterCategory.value  || '').trim() : '',
            scope:            this.filterScope     ? String(this.filterScope.value     || '').trim() : '',
            stack_mode:       this.filterStackMode ? String(this.filterStackMode.value || '').trim() : '',
            visible_to_players: this.filterVisible ? String(this.filterVisible.value   || '').trim() : ''
        }, 20, 1, 'priority|DESC');
        return this;
    },

    debounce: function (key, delayMs, fn) {
        var id = String(key || '');
        if (!id || typeof fn !== 'function') {
            return;
        }
        if (this.debounceTimers[id]) {
            globalWindow.clearTimeout(this.debounceTimers[id]);
            this.debounceTimers[id] = null;
        }
        this.debounceTimers[id] = globalWindow.setTimeout(function () {
            fn();
        }, Math.max(0, parseInt(delayMs, 10) || 0));
    },

    findRowByTrigger: function (trigger) {
        var id = parseInt(trigger.getAttribute('data-id') || '0', 10) || 0;
        if (id <= 0) {
            return null;
        }
        return this.rowsById[id] || null;
    },

    openModal: function (mode, row) {
        var createMode = (mode !== 'edit');

        this.form.reset();
        this.form.elements.id.value = '';
        this.form.elements.scope.value = 'character';
        this.form.elements.stack_mode.value = 'replace';
        this.form.elements.max_stacks.value = '1';
        this.form.elements.priority.value = '0';
        this.form.elements.is_active.value = '1';
        this.form.elements.visible_to_players.value = '1';

        if (!createMode && row) {
            this.form.elements.id.value = String(row.id || '');
            this.form.elements.code.value = row.code || '';
            this.form.elements.name.value = row.name || '';
            this.form.elements.description.value = row.description || '';
            this.form.elements.category.value = row.category || '';
            this.form.elements.scope.value = row.scope || 'character';
            this.form.elements.stack_mode.value = row.stack_mode || 'replace';
            this.form.elements.max_stacks.value = (row.max_stacks != null) ? String(row.max_stacks) : '1';
            this.form.elements.conflict_group.value = row.conflict_group || '';
            this.form.elements.priority.value = (row.priority != null) ? String(row.priority) : '0';
            this.form.elements.is_active.value = parseInt(row.is_active, 10) === 1 ? '1' : '0';
            this.form.elements.visible_to_players.value = parseInt(row.visible_to_players, 10) === 1 ? '1' : '0';
        }

        this.refreshSwitches();
        var self = this;
        this.ensureValuesCache(function (cache) {
            self.populateDatalist('admin-narrative-state-category-list', cache.categories);
            self.populateDatalist('admin-narrative-state-conflict-group-list', cache.conflictGroups);
        });
        this.showModal('admin-narrative-state-modal');
    },

    saveState: function () {
        var payload = this.collectPayload();
        if (!payload.code || !payload.name) {
            Toast.show({ body: 'Codice e nome sono obbligatori.', type: 'warning' });
            return;
        }

        var isEdit = parseInt(payload.id || 0, 10) > 0;
        var endpoint = isEdit ? '/admin/narrative-states/update' : '/admin/narrative-states/create';
        var self = this;
        this.requestPost(endpoint, payload, function () {
            self.hideModal('admin-narrative-state-modal');
            self.valuesCache = null;
            Toast.show({
                body: isEdit ? 'Stato narrativo aggiornato.' : 'Stato narrativo creato.',
                type: 'success'
            });
            self.loadGrid();
        });
    },

    collectPayload: function () {
        var payload = {
            id: parseInt(this.form.elements.id.value || '0', 10) || 0,
            code: String(this.form.elements.code.value || '').trim(),
            name: String(this.form.elements.name.value || '').trim(),
            description: String(this.form.elements.description.value || '').trim(),
            category: String(this.form.elements.category.value || '').trim(),
            scope: String(this.form.elements.scope.value || 'character').trim(),
            stack_mode: String(this.form.elements.stack_mode.value || 'replace').trim(),
            max_stacks: parseInt(this.form.elements.max_stacks.value || '1', 10) || 1,
            conflict_group: String(this.form.elements.conflict_group.value || '').trim(),
            priority: parseInt(this.form.elements.priority.value || '0', 10) || 0,
            is_active: parseInt(this.form.elements.is_active.value || '0', 10) === 1 ? 1 : 0,
            visible_to_players: parseInt(this.form.elements.visible_to_players.value || '0', 10) === 1 ? 1 : 0
        };

        if (payload.max_stacks < 1) {
            payload.max_stacks = 1;
        }
        if (!payload.conflict_group) {
            delete payload.conflict_group;
        }
        if (!payload.id) {
            delete payload.id;
        }

        return payload;
    },

    confirmDelete: function (row) {
        if (!row || !row.id) {
            return;
        }
        var self = this;
        Dialog('danger', {
            title: 'Elimina stato narrativo',
            body: '<p>Confermi l\'eliminazione di <b>' + this.escapeHtml(row.name || row.code || 'stato') + '</b>?</p>'
        }, function () {
            self.hideConfirmDialog();
            self.requestPost('/admin/narrative-states/delete', { state_id: row.id }, function () {
                Toast.show({ body: 'Stato narrativo eliminato.', type: 'success' });
                self.loadGrid();
            });
        }).show();
    },

    requestPost: function (url, payload, onSuccess, onError) {
        var self = this;
        if (!globalWindow.Request || !Request.http || typeof Request.http.post !== 'function') {
            Toast.show({
                body: 'Servizio comunicazione non disponibile. Ricarica la pagina e riprova.',
                type: 'error'
            });
            return this;
        }

        Request.http.post(url, payload || {}).then(function (response) {
            if (typeof onSuccess === 'function') {
                onSuccess(response || null);
            }
        }).catch(function (error) {
            if (typeof onError === 'function') {
                onError(error);
                return;
            }
            Toast.show({
                body: self.requestErrorMessage(error),
                type: 'error'
            });
        });

        return this;
    },

    requestErrorMessage: function (error) {
        if (globalWindow.Request && typeof globalWindow.Request.getErrorMessage === 'function') {
            return globalWindow.Request.getErrorMessage(error, 'Operazione non riuscita.');
        }
        if (typeof error === 'string' && error.trim() !== '') {
            return error.trim();
        }
        if (error && typeof error.message === 'string' && error.message.trim() !== '') {
            return error.message.trim();
        }
        return 'Operazione non riuscita.';
    },

    showModal: function (modalId) {
        var node = document.getElementById(modalId);
        if (!node) {
            return;
        }
        if (globalWindow.bootstrap && globalWindow.bootstrap.Modal) {
            globalWindow.bootstrap.Modal.getOrCreateInstance(node).show();
            return;
        }
        if (typeof $ === 'function') {
            $(node).modal('show');
        }
    },

    hideModal: function (modalId) {
        var node = document.getElementById(modalId);
        if (!node) {
            return;
        }
        if (globalWindow.bootstrap && globalWindow.bootstrap.Modal) {
            globalWindow.bootstrap.Modal.getOrCreateInstance(node).hide();
            return;
        }
        if (typeof $ === 'function') {
            $(node).modal('hide');
        }
    },

    hideConfirmDialog: function () {
        if (globalWindow.SystemDialogs && typeof globalWindow.SystemDialogs.ensureGeneralConfirm === 'function') {
            var dialog = globalWindow.SystemDialogs.ensureGeneralConfirm();
            if (dialog && typeof dialog.hide === 'function') {
                dialog.hide();
            }
        } else if (globalWindow.generalConfirm && typeof globalWindow.generalConfirm.hide === 'function') {
            globalWindow.generalConfirm.hide();
        }
    },

    scopeBadge: function (scope) {
        var map = {
            'character': '<span class="badge text-bg-primary">Personaggio</span>',
            'scene':     '<span class="badge text-bg-info">Scena</span>',
            'both':      '<span class="badge text-bg-secondary">Entrambi</span>'
        };
        return map[String(scope || '').toLowerCase()] || '<span class="badge text-bg-light text-dark">' + this.escapeHtml(scope || '—') + '</span>';
    },

    stackModeBadge: function (mode, maxStacks) {
        var label = { replace: 'Sostituisci', stack: 'Accumula', refresh: 'Aggiorna' }[String(mode || '').toLowerCase()] || this.escapeHtml(mode || '—');
        var badge = '<span class="badge text-bg-light text-dark">' + label + '</span>';
        if (String(mode || '').toLowerCase() === 'stack' && parseInt(maxStacks, 10) > 1) {
            badge += ' <span class="text-muted small">x' + parseInt(maxStacks, 10) + '</span>';
        }
        return badge;
    },

    ensureValuesCache: function (onReady) {
        var self = this;
        if (this.valuesCache !== null) {
            if (typeof onReady === 'function') onReady(this.valuesCache);
            return;
        }
        this.valuesCache = { categories: [], conflictGroups: [] };
        if (!globalWindow.Request || !Request.http || typeof Request.http.post !== 'function') {
            if (typeof onReady === 'function') onReady(this.valuesCache);
            return;
        }
        Request.http.post('/admin/narrative-states/list', { include_inactive: 1 })
            .then(function (response) {
                var rows = response && Array.isArray(response.dataset) ? response.dataset : [];
                var cats = {}, groups = {};
                for (var i = 0; i < rows.length; i++) {
                    var r = rows[i] || {};
                    var cat = String(r.category || '').trim();
                    var grp = String(r.conflict_group || '').trim();
                    if (cat) cats[cat] = 1;
                    if (grp) groups[grp] = 1;
                }
                self.valuesCache = {
                    categories: Object.keys(cats).sort(),
                    conflictGroups: Object.keys(groups).sort()
                };
                if (typeof onReady === 'function') onReady(self.valuesCache);
            })
            .catch(function () {
                if (typeof onReady === 'function') onReady(self.valuesCache);
            });
    },

    populateDatalist: function (listId, values) {
        var list = document.getElementById(listId);
        if (!list) return;
        list.innerHTML = '';
        for (var i = 0; i < values.length; i++) {
            var opt = document.createElement('option');
            opt.value = values[i];
            list.appendChild(opt);
        }
    },

    escapeHtml: function (value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }
};

globalWindow.AdminNarrativeStates = AdminNarrativeStates;
export { AdminNarrativeStates as AdminNarrativeStates };
export default AdminNarrativeStates;

