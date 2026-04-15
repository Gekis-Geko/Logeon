(function () {
    'use strict';

    var AdminArchetypes = {
        initialized: false,
        root: null,
        filtersForm: null,
        configModalNode: null,
        configModal: null,
        configNoteWrap: null,
        configNoteNode: null,
        grid: null,
        modalNode: null,
        modal: null,
        modalForm: null,
        rows: [],
        rowsById: {},
        editingRow: null,
        switches: {},

        init: function () {
            if (this.initialized) {
                return this;
            }

            this.root = document.querySelector('#admin-page [data-admin-page="archetypes"]');
            if (!this.root) {
                return this;
            }

            this.filtersForm    = this.root.querySelector('#admin-archetypes-filters');
            this.configModalNode = this.root.querySelector('#admin-archetypes-config-modal');
            this.configNoteWrap = this.root.querySelector('[data-role="admin-archetypes-config-note-wrap"]');
            this.configNoteNode = this.root.querySelector('[data-role="admin-archetypes-config-note"]');
            this.modalNode      = this.root.querySelector('#admin-archetypes-modal');
            this.modalForm      = this.root.querySelector('#admin-archetypes-form');

            if (!this.filtersForm || !this.modalNode || !this.modalForm) {
                return this;
            }

            this.modal = new bootstrap.Modal(this.modalNode);
            if (this.configModalNode) {
                this.configModal = new bootstrap.Modal(this.configModalNode);
            }
            this.initConfigSwitches();
            this.bind();
            this.initGrid();
            this.loadGrid();
            this.loadConfig();

            this.initialized = true;
            return this;
        },

        // ── Config switches ────────────────────────────────────────────────────

        initConfigSwitches: function () {
            if (!this.configModalNode) { return this; }
            if (typeof window.SwitchGroup !== 'function' || typeof window.$ !== 'function') {
                return this;
            }

            var sw = window.SwitchGroup;

            this.switches.archetypes_enabled = sw(window.$('#s-archetypes-enabled'), {
                preset: 'enableddisabled',
                trueValue: '1',
                falseValue: '0',
                defaultValue: '1'
            });

            this.switches.archetype_required = sw(window.$('#s-archetype-required'), {
                preset: 'yesno',
                trueValue: '1',
                falseValue: '0',
                defaultValue: '0'
            });

            this.switches.multiple_archetypes_allowed = sw(window.$('#s-multiple-archetypes'), {
                preset: 'yesno',
                trueValue: '1',
                falseValue: '0',
                defaultValue: '0'
            });

            var self = this;
            var enabledInput = document.getElementById('s-archetypes-enabled');
            var requiredInput = document.getElementById('s-archetype-required');
            var multipleInput = document.getElementById('s-multiple-archetypes');
            var onConfigSwitchChanged = function () {
                self.syncConfigDependencies();
            };

            if (enabledInput) {
                enabledInput.addEventListener('change', onConfigSwitchChanged);
            }
            if (requiredInput) {
                requiredInput.addEventListener('change', onConfigSwitchChanged);
            }
            if (multipleInput) {
                multipleInput.addEventListener('change', onConfigSwitchChanged);
            }

            this.syncConfigDependencies();

            return this;
        },

        initModalSwitches: function () {
            if (!this.modalForm) { return this; }
            if (typeof window.SwitchGroup !== 'function' || typeof window.$ !== 'function') {
                return this;
            }

            if (!this.switches.is_active) {
                var sw = window.SwitchGroup;
                this.switches.is_active = sw(window.$('#admin-archetypes-form [name="is_active"]'), {
                    preset: 'activeinactive',
                    trueValue: '1',
                    falseValue: '0',
                    defaultValue: '1'
                });
                this.switches.is_selectable = sw(window.$('#admin-archetypes-form [name="is_selectable"]'), {
                    preset: 'yesno',
                    trueValue: '1',
                    falseValue: '0',
                    defaultValue: '1'
                });
            }

            return this;
        },

        // ── Events ────────────────────────────────────────────────────────────

        bind: function () {
            var self = this;

            this.filtersForm.addEventListener('submit', function (event) {
                event.preventDefault();
                self.loadGrid();
            });

            this.root.addEventListener('click', function (event) {
                var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
                if (!trigger) { return; }
                var action = String(trigger.getAttribute('data-action') || '').trim();

                switch (action) {
                    case 'admin-archetypes-config-open':
                        event.preventDefault();
                        if (self.configModal) { self.configModal.show(); }
                        break;
                    case 'admin-archetypes-reload':
                        event.preventDefault();
                        self.loadGrid();
                        self.loadConfig();
                        break;
                    case 'admin-archetype-create':
                        event.preventDefault();
                        self.openCreate();
                        break;
                    case 'admin-archetype-edit':
                        event.preventDefault();
                        var id = parseInt(trigger.getAttribute('data-id') || '0', 10);
                        self.openEdit(id);
                        break;
                    case 'admin-archetype-save':
                        event.preventDefault();
                        self.save();
                        break;
                    case 'admin-archetype-delete':
                        event.preventDefault();
                        self.remove();
                        break;
                    case 'admin-archetypes-config-save':
                        event.preventDefault();
                        self.saveConfig();
                        break;
                }
            });
        },

        // ── Grid ──────────────────────────────────────────────────────────────

        initGrid: function () {
            var self = this;

            this.grid = new Datagrid('grid-admin-archetypes', {
                name: 'AdminArchetypes',
                autoindex: 'id',
                orderable: true,
                thead: true,
                handler: { url: '/admin/archetypes/list', action: 'list' },
                nav: { display: 'bottom', urlupdate: 0, results: 25, page: 1 },
                onGetDataSuccess: function (response) {
                    self.setRows(response && Array.isArray(response.dataset) ? response.dataset : []);
                },
                onGetDataError: function () {
                    self.setRows([]);
                },
                columns: [
                    {
                        label: 'ID',
                        field: 'id',
                        sortable: true,
                        style: { textAlign: 'center', width: '60px' }
                    },
                    {
                        label: 'Nome',
                        field: 'name',
                        sortable: true,
                        style: { textAlign: 'left' },
                        format: function (row) {
                            return '<div class="fw-semibold">' + self.escapeHtml(row.name || '-') + '</div>'
                                + '<div class="small text-muted font-monospace">' + self.escapeHtml(row.slug || '') + '</div>';
                        }
                    },
                    {
                        label: 'Descrizione',
                        field: 'description',
                        sortable: false,
                        style: { textAlign: 'left' },
                        format: function (row) {
                            if (!row.description) { return '<span class="text-muted">—</span>'; }
                            var text = String(row.description);
                            if (text.length > 80) { text = text.substring(0, 80) + '…'; }
                            return '<span class="small text-muted">' + self.escapeHtml(text) + '</span>';
                        }
                    },
                    {
                        label: 'Ordine',
                        field: 'sort_order',
                        sortable: true,
                        style: { textAlign: 'center', width: '80px' }
                    },
                    {
                        label: 'Attivo',
                        field: 'is_active',
                        sortable: true,
                        style: { textAlign: 'center', width: '90px' },
                        format: function (row) {
                            return parseInt(row.is_active, 10) === 1
                                ? '<span class="badge text-bg-success">Sì</span>'
                                : '<span class="badge text-bg-secondary">No</span>';
                        }
                    },
                    {
                        label: 'Selezionabile',
                        field: 'is_selectable',
                        sortable: true,
                        style: { textAlign: 'center', width: '110px' },
                        format: function (row) {
                            return parseInt(row.is_selectable, 10) === 1
                                ? '<span class="badge text-bg-primary">Sì</span>'
                                : '<span class="badge text-bg-secondary">No</span>';
                        }
                    },
                    {
                        label: 'Azioni',
                        sortable: false,
                        style: { textAlign: 'center', width: '80px' },
                        format: function (row) {
                            var id = self.escapeAttr(String(row.id || ''));
                            return '<button type="button" class="btn btn-sm btn-outline-secondary"'
                                + ' data-action="admin-archetype-edit" data-id="' + id + '">'
                                + '<i class="bi bi-pencil"></i></button>';
                        }
                    }
                ]
            });
        },

        setRows: function (rows) {
            this.rows     = rows || [];
            this.rowsById = {};
            for (var i = 0; i < this.rows.length; i++) {
                var r = this.rows[i];
                if (r && r.id) { this.rowsById[r.id] = r; }
            }
        },

        loadGrid: function () {
            if (!this.grid || typeof this.grid.loadData !== 'function') { return this; }
            var payload = this.buildFiltersPayload();
            this.grid.loadData(payload, 25, 1, 'sort_order|ASC');
            return this;
        },

        buildFiltersPayload: function () {
            var q = {};
            if (!this.filtersForm) { return q; }
            var search      = (this.filtersForm.querySelector('[name="search"]') || {}).value || '';
            var isActive    = (this.filtersForm.querySelector('[name="is_active"]') || {}).value || '';
            var isSelectable = (this.filtersForm.querySelector('[name="is_selectable"]') || {}).value || '';
            if (search)       { q.search       = search; }
            if (isActive !== '')    { q.is_active    = isActive; }
            if (isSelectable !== '') { q.is_selectable = isSelectable; }
            return q;
        },

        // ── Config ────────────────────────────────────────────────────────────

        loadConfig: function () {
            var self = this;
            this.post('/admin/archetypes/config/get', {}, function (res) {
                var cfg = (res && res.dataset) ? res.dataset : {};
                self.applyConfig(cfg);
            });
        },

        applyConfig: function (cfg) {
            var setSwitch = function (sw, value) {
                if (sw && typeof sw.setValue === 'function') {
                    sw.setValue(String(value));
                }
            };
            setSwitch(this.switches.archetypes_enabled,          String(cfg.archetypes_enabled ?? '1'));
            setSwitch(this.switches.archetype_required,          String(cfg.archetype_required ?? '0'));
            setSwitch(this.switches.multiple_archetypes_allowed, String(cfg.multiple_archetypes_allowed ?? '0'));
            this.syncConfigDependencies();
        },

        getSwitchBool: function (key, defaultValue) {
            var sw = this.switches[key];
            var fallback = defaultValue === true ? 1 : 0;
            if (!sw || typeof sw.getValue !== 'function') {
                return fallback;
            }
            return (String(sw.getValue()) === '1') ? 1 : 0;
        },

        syncConfigDependencies: function () {
            if (this._syncingDeps) { return; }
            this._syncingDeps = true;
            var enabled = this.getSwitchBool('archetypes_enabled', true) === 1;
            var requiredSwitch = this.switches.archetype_required;
            var multipleSwitch = this.switches.multiple_archetypes_allowed;

            if (!enabled) {
                if (requiredSwitch && typeof requiredSwitch.setValue === 'function') {
                    requiredSwitch.setValue('0');
                }
                if (multipleSwitch && typeof multipleSwitch.setValue === 'function') {
                    multipleSwitch.setValue('0');
                }
            }

            if (requiredSwitch && typeof requiredSwitch.setDisabled === 'function') {
                requiredSwitch.setDisabled(!enabled);
            }
            if (multipleSwitch && typeof multipleSwitch.setDisabled === 'function') {
                multipleSwitch.setDisabled(!enabled);
            }

            if (this.configNoteWrap && this.configNoteNode) {
                if (enabled) {
                    this.configNoteWrap.classList.add('d-none');
                    this.configNoteNode.textContent = '';
                } else {
                    this.configNoteNode.textContent = 'Sistema disattivato: obbligatorietà e selezione multipla sono sospese.';
                    this.configNoteWrap.classList.remove('d-none');
                }
            }
            this._syncingDeps = false;
        },

        saveConfig: function () {
            var payload = this.collectConfigPayload();
            var self    = this;
            this.post('/admin/archetypes/config/update', payload, function (res) {
                var cfg = (res && res.dataset) ? res.dataset : {};
                self.applyConfig(cfg);
                if (self.configModal) { self.configModal.hide(); }
                if (typeof Toast !== 'undefined') {
                    Toast.show({ body: 'Configurazione salvata.', type: 'success' });
                }
            });
        },

        collectConfigPayload: function () {
            var getVal = function (sw, inputName) {
                if (sw && typeof sw.getValue === 'function') { return sw.getValue(); }
                var el = document.querySelector('[name="' + inputName + '"]');
                return el ? el.value : '0';
            };
            var payload = {
                archetypes_enabled: parseInt(getVal(this.switches.archetypes_enabled, 'archetypes_enabled'), 10) || 0,
                archetype_required: parseInt(getVal(this.switches.archetype_required, 'archetype_required'), 10) || 0,
                multiple_archetypes_allowed: parseInt(getVal(this.switches.multiple_archetypes_allowed, 'multiple_archetypes_allowed'), 10) || 0
            };
            if (payload.archetypes_enabled !== 1) {
                payload.archetype_required = 0;
                payload.multiple_archetypes_allowed = 0;
            }
            return payload;
        },

        // ── Modal ─────────────────────────────────────────────────────────────

        openCreate: function () {
            this.initModalSwitches();
            this.editingRow = null;
            if (this.modalForm) { this.modalForm.reset(); }
            this.setField('id', '');
            this.setField('sort_order', '0');
            this.setField('icon', '');
            if (this.switches.is_active    && typeof this.switches.is_active.setValue === 'function')    { this.switches.is_active.setValue('1'); }
            if (this.switches.is_selectable && typeof this.switches.is_selectable.setValue === 'function') { this.switches.is_selectable.setValue('1'); }
            this.toggleDelete(false);
            this.modal.show();
        },

        openEdit: function (id) {
            var row = this.rowsById[id] || null;
            if (!row) { return; }
            this.initModalSwitches();
            this.editingRow = row;
            if (this.modalForm) { this.modalForm.reset(); }
            this.setField('id',          String(row.id));
            this.setField('name',        row.name || '');
            this.setField('description', row.description || '');
            this.setField('lore_text',   row.lore_text || '');
            this.setField('sort_order',  String(row.sort_order || '0'));
            this.setField('icon',        row.icon || '');
            if (this.switches.is_active    && typeof this.switches.is_active.setValue === 'function')    { this.switches.is_active.setValue(String(row.is_active || '0')); }
            if (this.switches.is_selectable && typeof this.switches.is_selectable.setValue === 'function') { this.switches.is_selectable.setValue(String(row.is_selectable || '0')); }
            this.toggleDelete(true);
            this.modal.show();
        },

        toggleDelete: function (show) {
            if (!this.modalNode) { return; }
            var btn = this.modalNode.querySelector('[data-action="admin-archetype-delete"]');
            if (btn) { btn.classList.toggle('d-none', !show); }
        },

        setField: function (name, value) {
            if (!this.modalForm) { return; }
            var el = this.modalForm.querySelector('[name="' + name + '"]');
            if (el) { el.value = value; }
        },

        getField: function (name) {
            if (!this.modalForm) { return ''; }
            var el = this.modalForm.querySelector('[name="' + name + '"]');
            return el ? el.value : '';
        },

        getSwitchValue: function (key, fallback) {
            if (this.switches[key] && typeof this.switches[key].getValue === 'function') {
                return this.switches[key].getValue();
            }
            return this.getField(key) || String(fallback);
        },

        collectPayload: function () {
            return {
                id:            parseInt(this.getField('id'), 10) || 0,
                name:          this.getField('name').trim(),
                description:   this.getField('description').trim(),
                lore_text:     this.getField('lore_text').trim(),
                icon:          this.getField('icon').trim(),
                sort_order:    parseInt(this.getField('sort_order'), 10) || 0,
                is_active:     parseInt(this.getSwitchValue('is_active', '1'), 10) || 0,
                is_selectable: parseInt(this.getSwitchValue('is_selectable', '1'), 10) || 0
            };
        },

        save: function () {
            var payload = this.collectPayload();
            if (!payload.name) {
                if (typeof Toast !== 'undefined') { Toast.show({ body: 'Il nome è obbligatorio.', type: 'error' }); }
                return;
            }

            var isNew = !payload.id;
            var url   = isNew ? '/admin/archetypes/create' : '/admin/archetypes/update';
            var self  = this;

            this.post(url, payload, function () {
                self.modal.hide();
                if (typeof Toast !== 'undefined') {
                    Toast.show({ body: isNew ? 'Archetipo creato.' : 'Archetipo aggiornato.', type: 'success' });
                }
                self.loadGrid();
            });
        },

        remove: function () {
            var payload = this.collectPayload();
            if (!payload.id) { return; }
            if (!confirm('Eliminare questo archetipo? L\'operazione non può essere annullata.')) { return; }

            var self = this;
            this.post('/admin/archetypes/delete', { id: payload.id }, function () {
                self.modal.hide();
                if (typeof Toast !== 'undefined') { Toast.show({ body: 'Archetipo eliminato.', type: 'success' }); }
                self.loadGrid();
            });
        },

        // ── HTTP helper ───────────────────────────────────────────────────────

        post: function (url, payload, onSuccess, onError) {
            if (typeof Request !== 'function' || !Request.http || typeof Request.http.post !== 'function') {
                if (typeof Toast !== 'undefined') { Toast.show({ body: 'Servizio non disponibile.', type: 'error' }); }
                return this;
            }
            Request.http.post(url, payload || {}).then(function (response) {
                if (typeof onSuccess === 'function') { onSuccess(response || null); }
            }).catch(function (error) {
                if (typeof onError === 'function') {
                    onError(error);
                } else if (typeof Toast !== 'undefined') {
                    var msg = (error && error.message) ? error.message : 'Errore di rete.';
                    Toast.show({ body: msg, type: 'error' });
                }
            });
            return this;
        },

        escapeHtml: function (str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        },

        escapeAttr: function (str) {
            return String(str).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }
    };

    window.AdminArchetypes = AdminArchetypes;
})();

