(function () {
    'use strict';

    var AdminItemsRarities = {
        initialized: false,
        root: null,
        filtersForm: null,
        grid: null,
        modalNode: null,
        modal: null,
        modalForm: null,
        rows: [],
        rowsById: {},
        editingRow: null,

        init: function () {
            if (this.initialized) { return this; }

            this.root = document.querySelector('#admin-page [data-admin-page="items-rarities"]');
            if (!this.root) { return this; }

            this.filtersForm = this.root.querySelector('#admin-items-rarities-filters');
            this.modalNode   = this.root.querySelector('#admin-items-rarities-modal');
            this.modalForm   = this.root.querySelector('#admin-items-rarities-form');

            if (!this.filtersForm || !this.modalNode || !this.modalForm) { return this; }

            this.modal = new bootstrap.Modal(this.modalNode);
            this.bind();
            this.initGrid();
            this.loadGrid();

            this.initialized = true;
            return this;
        },

        bind: function () {
            var self = this;

            this.filtersForm.addEventListener('submit', function (event) {
                event.preventDefault();
                self.loadGrid();
            });

            this.root.addEventListener('click', function (event) {
                var trigger = event.target.closest('[data-action]');
                if (!trigger) { return; }
                var action = String(trigger.getAttribute('data-action') || '').trim();

                if (action === 'admin-items-rarities-reload') {
                    event.preventDefault();
                    self.loadGrid();
                } else if (action === 'admin-items-rarities-create') {
                    event.preventDefault();
                    self.openCreate();
                } else if (action === 'admin-items-rarities-edit') {
                    event.preventDefault();
                    var id = parseInt(trigger.getAttribute('data-id') || '0', 10);
                    self.openEdit(id);
                } else if (action === 'admin-items-rarities-save') {
                    event.preventDefault();
                    self.save();
                } else if (action === 'admin-items-rarities-delete') {
                    event.preventDefault();
                    self.remove();
                }
            });

            // Sync color picker ↔ text input
            this.modalNode.addEventListener('input', function (event) {
                var el = event.target;
                if (el.name === 'color_hex_picker') {
                    var textEl = self.modalForm.querySelector('[name="color_hex"]');
                    if (textEl) { textEl.value = el.value; }
                } else if (el.name === 'color_hex') {
                    var pickerEl = self.modalForm.querySelector('[name="color_hex_picker"]');
                    if (pickerEl && /^#[0-9a-fA-F]{6}$/.test(el.value)) {
                        pickerEl.value = el.value;
                    }
                }
            });
        },

        initGrid: function () {
            var self = this;

            this.grid = new Datagrid('grid-admin-items-rarities', {
                name: 'AdminItemsRarities',
                autoindex: 'id',
                orderable: true,
                thead: true,
                handler: { url: '/admin/items/rarities/admin-list', action: 'list' },
                nav: { display: 'bottom', urlupdate: 0, results: 30, page: 1 },
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
                        label: 'Colore',
                        field: 'color_hex',
                        sortable: false,
                        style: { textAlign: 'center', width: '70px' },
                        format: function (row) {
                            var hex = self.escapeAttr(row.color_hex || '#6c757d');
                            return '<span style="display:inline-block;width:28px;height:28px;border-radius:4px;background:' + hex + ';border:1px solid rgba(0,0,0,.2)"></span>';
                        }
                    },
                    {
                        label: 'Codice',
                        field: 'code',
                        sortable: true,
                        style: { width: '120px' },
                        format: function (row) {
                            return '<code>' + self.escapeHtml(row.code || '-') + '</code>';
                        }
                    },
                    {
                        label: 'Nome',
                        field: 'name',
                        sortable: true,
                        format: function (row) {
                            var hex = self.escapeAttr(row.color_hex || '#6c757d');
                            return '<span style="color:' + hex + ';font-weight:600">' + self.escapeHtml(row.name || '-') + '</span>';
                        }
                    },
                    {
                        label: 'Descrizione',
                        field: 'description',
                        sortable: false,
                        format: function (row) {
                            return row.description
                                ? '<span class="small text-muted">' + self.escapeHtml(row.description) + '</span>'
                                : '<span class="text-muted">—</span>';
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
                        style: { textAlign: 'center', width: '80px' },
                        format: function (row) {
                            return row.is_active == 1
                                ? '<span class="badge bg-success">Sì</span>'
                                : '<span class="badge bg-secondary">No</span>';
                        }
                    },
                    {
                        label: 'Azioni',
                        sortable: false,
                        style: { textAlign: 'center', width: '80px' },
                        format: function (row) {
                            var id = self.escapeAttr(String(row.id || ''));
                            return '<button type="button" class="btn btn-sm btn-outline-secondary"'
                                + ' data-action="admin-items-rarities-edit" data-id="' + id + '">'
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
            this.grid.loadData(this.buildFiltersPayload(), 30, 1, 'sort_order|ASC');
            return this;
        },

        buildFiltersPayload: function () {
            var q = {};
            if (this.filtersForm) {
                var name = (this.filtersForm.querySelector('[name="name"]') || {}).value || '';
                var isActive = (this.filtersForm.querySelector('[name="is_active"]') || {}).value || '';
                if (name)     { q.name      = name; }
                if (isActive !== '') { q.is_active = isActive; }
            }
            return q;
        },

        // ── Modal ──────────────────────────────────────────────────────────────

        openCreate: function () {
            this.editingRow = null;
            if (this.modalForm) { this.modalForm.reset(); }
            this.setField('id', '');
            this.setField('color_hex', '#6c757d');
            this.setField('color_hex_picker', '#6c757d');
            this.setField('sort_order', '0');
            this.setSelect('is_active', '1');
            this.toggleDelete(false);
            this.modal.show();
        },

        openEdit: function (id) {
            var row = this.rowsById[id] || null;
            if (!row) { return; }
            this.editingRow = row;
            if (this.modalForm) { this.modalForm.reset(); }
            this.setField('id',               String(row.id));
            this.setField('code',             row.code || '');
            this.setField('name',             row.name || '');
            this.setField('description',      row.description || '');
            this.setField('color_hex',        row.color_hex || '#6c757d');
            this.setField('color_hex_picker', row.color_hex || '#6c757d');
            this.setField('sort_order',       String(row.sort_order || '0'));
            this.setSelect('is_active',       String(row.is_active ?? '1'));
            this.toggleDelete(true);
            this.modal.show();
        },

        toggleDelete: function (show) {
            if (!this.modalNode) { return; }
            var btn = this.modalNode.querySelector('[data-action="admin-items-rarities-delete"]');
            if (btn) { btn.classList.toggle('d-none', !show); }
        },

        setField: function (name, value) {
            if (!this.modalForm) { return; }
            var el = this.modalForm.querySelector('[name="' + name + '"]');
            if (el) { el.value = value; }
        },

        setSelect: function (name, value) {
            if (!this.modalForm) { return; }
            var el = this.modalForm.querySelector('[name="' + name + '"]');
            if (el) { el.value = value; }
        },

        getField: function (name) {
            if (!this.modalForm) { return ''; }
            var el = this.modalForm.querySelector('[name="' + name + '"]');
            return el ? el.value : '';
        },

        collectPayload: function () {
            return {
                id:          parseInt(this.getField('id'), 10) || 0,
                code:        this.getField('code').trim().toLowerCase(),
                name:        this.getField('name').trim(),
                description: this.getField('description').trim(),
                color_hex:   this.getField('color_hex').trim() || '#6c757d',
                sort_order:  parseInt(this.getField('sort_order'), 10) || 0,
                is_active:   parseInt(this.getField('is_active'), 10) || 0
            };
        },

        save: function () {
            var payload = this.collectPayload();
            if (!payload.name) {
                if (typeof Toast !== 'undefined') { Toast.show({ body: 'Il nome è obbligatorio.', type: 'error' }); }
                return;
            }
            if (!payload.code) {
                if (typeof Toast !== 'undefined') { Toast.show({ body: 'Il codice è obbligatorio.', type: 'error' }); }
                return;
            }

            var isNew = !payload.id;
            var url   = isNew ? '/admin/items/rarities/create' : '/admin/items/rarities/update';
            var self  = this;

            this.post(url, payload, function () {
                self.modal.hide();
                if (typeof Toast !== 'undefined') {
                    Toast.show({ body: isNew ? 'Rarità creata.' : 'Rarità aggiornata.', type: 'success' });
                }
                self.loadGrid();
            });
        },

        remove: function () {
            var payload = this.collectPayload();
            if (!payload.id) { return; }
            if (!confirm('Eliminare questa rarità? L\'operazione non può essere annullata.')) { return; }

            var self = this;
            this.post('/admin/items/rarities/delete', { id: payload.id }, function () {
                self.modal.hide();
                if (typeof Toast !== 'undefined') { Toast.show({ body: 'Rarità eliminata.', type: 'success' }); }
                self.loadGrid();
            });
        },

        // ── HTTP helper ────────────────────────────────────────────────────────

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

    window.AdminItemsRarities = AdminItemsRarities;
})();
