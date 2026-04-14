(function () {
    'use strict';

    var AdminItemsCategories = {
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
            if (this.initialized) {
                return this;
            }

            this.root = document.querySelector('#admin-page [data-admin-page="items-categories"]');
            if (!this.root) {
                return this;
            }

            this.filtersForm = this.root.querySelector('#admin-items-categories-filters');
            this.modalNode   = this.root.querySelector('#admin-items-categories-modal');
            this.modalForm   = this.root.querySelector('#admin-items-categories-form');

            if (!this.filtersForm || !this.modalNode || !this.modalForm) {
                return this;
            }

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

                if (action === 'admin-items-categories-reload') {
                    event.preventDefault();
                    self.loadGrid();
                } else if (action === 'admin-items-categories-create') {
                    event.preventDefault();
                    self.openCreate();
                } else if (action === 'admin-items-categories-edit') {
                    event.preventDefault();
                    var id = parseInt(trigger.getAttribute('data-id') || '0', 10);
                    self.openEdit(id);
                } else if (action === 'admin-items-categories-save') {
                    event.preventDefault();
                    self.save();
                } else if (action === 'admin-items-categories-delete') {
                    event.preventDefault();
                    self.remove();
                }
            });
        },

        initGrid: function () {
            var self = this;

            this.grid = new Datagrid('grid-admin-items-categories', {
                name: 'AdminItemsCategories',
                autoindex: 'id',
                orderable: true,
                thead: true,
                handler: { url: '/admin/categories/list', action: 'list' },
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
                        label: 'Nome',
                        field: 'name',
                        sortable: true,
                        style: { textAlign: 'left' },
                        format: function (row) {
                            return self.escapeHtml(row.name || '-');
                        }
                    },
                    {
                        label: 'Descrizione',
                        field: 'description',
                        sortable: false,
                        style: { textAlign: 'left' },
                        format: function (row) {
                            return row.description
                                ? '<span class="small text-muted">' + self.escapeHtml(row.description) + '</span>'
                                : '<span class="text-muted">—</span>';
                        }
                    },
                    {
                        label: 'Icona',
                        field: 'icon',
                        sortable: false,
                        style: { textAlign: 'left', width: '180px' },
                        format: function (row) {
                            return row.icon
                                ? '<code class="small">' + self.escapeHtml(row.icon) + '</code>'
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
                        label: 'Azioni',
                        sortable: false,
                        style: { textAlign: 'center', width: '80px' },
                        format: function (row) {
                            var id = self.escapeAttr(String(row.id || ''));
                            return '<button type="button" class="btn btn-sm btn-outline-secondary"'
                                + ' data-action="admin-items-categories-edit" data-id="' + id + '">'
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
                if (name) { q.name = name; }
            }
            return q;
        },

        // ── Modal ─────────────────────────────────────────────────────────────

        openCreate: function () {
            this.editingRow = null;
            if (this.modalForm) { this.modalForm.reset(); }
            this.setField('id', '');
            this.setField('sort_order', '0');
            this.toggleDelete(false);
            this.modal.show();
        },

        openEdit: function (id) {
            var row = this.rowsById[id] || null;
            if (!row) { return; }
            this.editingRow = row;
            if (this.modalForm) { this.modalForm.reset(); }
            this.setField('id', String(row.id));
            this.setField('name', row.name || '');
            this.setField('description', row.description || '');
            this.setField('icon', row.icon || '');
            this.setField('sort_order', String(row.sort_order || '0'));
            this.toggleDelete(true);
            this.modal.show();
        },

        toggleDelete: function (show) {
            if (!this.modalNode) { return; }
            var btn = this.modalNode.querySelector('[data-action="admin-items-categories-delete"]');
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

        collectPayload: function () {
            return {
                id:          parseInt(this.getField('id'), 10) || 0,
                name:        this.getField('name').trim(),
                description: this.getField('description').trim(),
                icon:        this.getField('icon').trim(),
                sort_order:  parseInt(this.getField('sort_order'), 10) || 0
            };
        },

        save: function () {
            var payload = this.collectPayload();
            if (!payload.name) {
                if (typeof Toast !== 'undefined') { Toast.show({ body: 'Il nome è obbligatorio.', type: 'error' }); }
                return;
            }

            var isNew = !payload.id;
            var url   = isNew ? '/admin/categories/create' : '/admin/categories/update';
            var self  = this;

            this.post(url, payload, function () {
                self.modal.hide();
                if (typeof Toast !== 'undefined') {
                    Toast.show({ body: isNew ? 'Categoria creata.' : 'Categoria aggiornata.', type: 'success' });
                }
                self.loadGrid();
            });
        },

        remove: function () {
            var payload = this.collectPayload();
            if (!payload.id) { return; }
            if (!confirm('Eliminare questa categoria? L\'operazione non può essere annullata.')) { return; }

            var self = this;
            this.post('/admin/categories/delete', { id: payload.id }, function () {
                self.modal.hide();
                if (typeof Toast !== 'undefined') { Toast.show({ body: 'Categoria eliminata.', type: 'success' }); }
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

    window.AdminItemsCategories = AdminItemsCategories;
})();
