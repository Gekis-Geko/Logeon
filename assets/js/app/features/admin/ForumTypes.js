(function () {
    'use strict';

    var AdminForumTypes = {
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

            this.root = document.querySelector('#admin-page [data-admin-page="forums-types"]');
            if (!this.root) {
                return this;
            }

            this.filtersForm = this.root.querySelector('#admin-forum-types-filters');
            this.modalNode   = this.root.querySelector('#admin-forum-types-modal');
            this.modalForm   = this.root.querySelector('#admin-forum-types-form');

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

                if (action === 'admin-forum-types-reload') {
                    event.preventDefault();
                    self.loadGrid();
                } else if (action === 'admin-forum-types-create') {
                    event.preventDefault();
                    self.openCreate();
                } else if (action === 'admin-forum-types-edit') {
                    event.preventDefault();
                    var id = parseInt(trigger.getAttribute('data-id') || '0', 10);
                    self.openEdit(id);
                } else if (action === 'admin-forum-types-save') {
                    event.preventDefault();
                    self.save();
                } else if (action === 'admin-forum-types-delete') {
                    event.preventDefault();
                    self.remove();
                }
            });
        },

        initGrid: function () {
            var self = this;

            this.grid = new Datagrid('grid-admin-forum-types', {
                name: 'AdminForumTypes',
                autoindex: 'id',
                orderable: true,
                thead: true,
                handler: { url: '/admin/forum-types/list', action: 'list' },
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
                        label: 'Titolo',
                        field: 'title',
                        sortable: true,
                        style: { textAlign: 'left' },
                        format: function (row) {
                            return self.escapeHtml(row.title || '-');
                        }
                    },
                    {
                        label: 'Sottotitolo',
                        field: 'subtitle',
                        sortable: false,
                        style: { textAlign: 'left' },
                        format: function (row) {
                            return row.subtitle
                                ? '<span class="small text-muted">' + self.escapeHtml(row.subtitle) + '</span>'
                                : '<span class="text-muted">—</span>';
                        }
                    },
                    {
                        label: 'Visibilità',
                        field: 'is_on_game',
                        sortable: true,
                        style: { textAlign: 'center', width: '110px' },
                        format: function (row) {
                            return parseInt(row.is_on_game, 10) === 1
                                ? '<span class="badge bg-success">In-game</span>'
                                : '<span class="badge bg-secondary">Off-game</span>';
                        }
                    },
                    {
                        label: 'Azioni',
                        sortable: false,
                        style: { textAlign: 'center', width: '80px' },
                        format: function (row) {
                            var id = self.escapeAttr(String(row.id || ''));
                            return '<button type="button" class="btn btn-sm btn-outline-secondary"'
                                + ' data-action="admin-forum-types-edit" data-id="' + id + '">'
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
            this.grid.loadData(this.buildFiltersPayload(), 30, 1, 'title|ASC');
            return this;
        },

        buildFiltersPayload: function () {
            var q = {};
            if (this.filtersForm) {
                var title     = (this.filtersForm.querySelector('[name="title"]') || {}).value || '';
                var isOnGame  = (this.filtersForm.querySelector('[name="is_on_game"]') || {}).value || '';
                if (title)    { q.title     = title; }
                if (isOnGame !== '') { q.is_on_game = isOnGame; }
            }
            return q;
        },

        // ── Modal ─────────────────────────────────────────────────────────────

        openCreate: function () {
            this.editingRow = null;
            if (this.modalForm) { this.modalForm.reset(); }
            this.setField('id', '');
            this.setField('is_on_game', '0');
            this.toggleDelete(false);
            this.modal.show();
        },

        openEdit: function (id) {
            var row = this.rowsById[id] || null;
            if (!row) { return; }
            this.editingRow = row;
            if (this.modalForm) { this.modalForm.reset(); }
            this.setField('id', String(row.id));
            this.setField('title', row.title || '');
            this.setField('is_on_game', String(parseInt(row.is_on_game, 10) || 0));
            this.setField('subtitle', row.subtitle || '');
            this.toggleDelete(true);
            this.modal.show();
        },

        toggleDelete: function (show) {
            if (!this.modalNode) { return; }
            var btn = this.modalNode.querySelector('[data-action="admin-forum-types-delete"]');
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
                id:         parseInt(this.getField('id'), 10) || 0,
                title:      this.getField('title').trim(),
                subtitle:   this.getField('subtitle').trim(),
                is_on_game: parseInt(this.getField('is_on_game'), 10) || 0
            };
        },

        save: function () {
            var payload = this.collectPayload();
            if (!payload.title) {
                if (typeof Toast !== 'undefined') { Toast.show({ body: 'Il titolo è obbligatorio.', type: 'error' }); }
                return;
            }

            var isNew = !payload.id;
            var url   = isNew ? '/admin/forum-types/create' : '/admin/forum-types/update';
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
            this.post('/admin/forum-types/delete', { id: payload.id }, function () {
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

        // ── Utils ──────────────────────────────────────────────────────────────

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

    window.AdminForumTypes = AdminForumTypes;
})();
