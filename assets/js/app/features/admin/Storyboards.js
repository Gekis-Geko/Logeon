(function () {
    'use strict';

    var AdminStoryboards = {
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

            this.root = document.querySelector('#admin-page [data-admin-page="storyboards"]');
            if (!this.root) {
                return this;
            }

            this.filtersForm = this.root.querySelector('#admin-storyboards-filters');
            this.modalNode   = this.root.querySelector('#admin-storyboards-modal');
            this.modalForm   = this.root.querySelector('#admin-storyboards-form');

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

                if (action === 'admin-storyboards-reload') {
                    event.preventDefault();
                    self.loadGrid();
                } else if (action === 'admin-storyboards-create') {
                    event.preventDefault();
                    self.openCreate();
                } else if (action === 'admin-storyboards-edit') {
                    event.preventDefault();
                    var id = parseInt(trigger.getAttribute('data-id') || '0', 10);
                    self.openEdit(id);
                } else if (action === 'admin-storyboards-save') {
                    event.preventDefault();
                    self.save();
                } else if (action === 'admin-storyboards-delete') {
                    event.preventDefault();
                    self.remove();
                }
            });
        },

        initGrid: function () {
            var self = this;

            this.grid = new Datagrid('grid-admin-storyboards', {
                name: 'AdminStoryboards',
                autoindex: 'id',
                orderable: true,
                thead: true,
                handler: { url: '/admin/storyboards/list', action: 'list' },
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
                        label: 'Cap.',
                        field: 'chapter',
                        sortable: true,
                        style: { textAlign: 'center', width: '70px' }
                    },
                    {
                        label: 'Sotto.',
                        field: 'subchapter',
                        sortable: true,
                        style: { textAlign: 'center', width: '70px' }
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
                        label: 'Aggiornato',
                        field: 'date_updated',
                        sortable: true,
                        style: { textAlign: 'center', width: '140px' },
                        format: function (row) {
                            return row.date_updated
                                ? '<span class="small">' + self.escapeHtml(self.formatDatetime(row.date_updated)) + '</span>'
                                : '<span class="text-muted">—</span>';
                        }
                    },
                    {
                        label: 'Azioni',
                        sortable: false,
                        style: { textAlign: 'center', width: '80px' },
                        format: function (row) {
                            var id = self.escapeAttr(String(row.id || ''));
                            return '<button type="button" class="btn btn-sm btn-outline-secondary"'
                                + ' data-action="admin-storyboards-edit" data-id="' + id + '">'
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
            this.grid.loadData(this.buildFiltersPayload(), 30, 1, 'chapter|ASC');
            return this;
        },

        buildFiltersPayload: function () {
            var q = {};
            if (this.filtersForm) {
                var title   = (this.filtersForm.querySelector('[name="title"]') || {}).value || '';
                var chapter = (this.filtersForm.querySelector('[name="chapter"]') || {}).value || '';
                if (title)   { q.title   = title; }
                if (chapter) { q.chapter = chapter; }
            }
            return q;
        },

        // ── Modal ─────────────────────────────────────────────────────────────

        openCreate: function () {
            this.editingRow = null;
            if (this.modalForm) { this.modalForm.reset(); }
            this.setField('id', '');
            this.setField('subchapter', '0');
            this.toggleDelete(false);
            this.modal.show();
        },

        openEdit: function (id) {
            var row = this.rowsById[id] || null;
            if (!row) { return; }
            this.editingRow = row;
            if (this.modalForm) { this.modalForm.reset(); }
            this.setField('id', String(row.id));
            this.setField('chapter', String(row.chapter || ''));
            this.setField('subchapter', String(row.subchapter || '0'));
            this.setField('title', row.title || '');
            this.setField('body', row.body || '');
            this.toggleDelete(true);
            this.modal.show();
        },

        toggleDelete: function (show) {
            if (!this.modalNode) { return; }
            var btn = this.modalNode.querySelector('[data-action="admin-storyboards-delete"]');
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
                chapter:    parseInt(this.getField('chapter'), 10) || 0,
                subchapter: parseInt(this.getField('subchapter'), 10) || 0,
                title:      this.getField('title').trim(),
                body:       this.getField('body')
            };
        },

        save: function () {
            var payload = this.collectPayload();
            if (!payload.chapter) {
                if (typeof Toast !== 'undefined') { Toast.show({ body: 'Il numero capitolo è obbligatorio.', type: 'error' }); }
                return;
            }
            if (!payload.title) {
                if (typeof Toast !== 'undefined') { Toast.show({ body: 'Il titolo è obbligatorio.', type: 'error' }); }
                return;
            }

            var isNew = !payload.id;
            var url   = isNew ? '/admin/storyboards/create' : '/admin/storyboards/update';
            var self  = this;

            this.post(url, payload, function () {
                self.modal.hide();
                if (typeof Toast !== 'undefined') {
                    Toast.show({ body: isNew ? 'Capitolo creato.' : 'Capitolo aggiornato.', type: 'success' });
                }
                self.loadGrid();
            });
        },

        remove: function () {
            var payload = this.collectPayload();
            if (!payload.id) { return; }
            if (!confirm('Eliminare questo capitolo? L\'operazione non può essere annullata.')) { return; }

            var self = this;
            this.post('/admin/storyboards/delete', { id: payload.id }, function () {
                self.modal.hide();
                if (typeof Toast !== 'undefined') { Toast.show({ body: 'Capitolo eliminato.', type: 'success' }); }
                self.loadGrid();
            });
        },

        // ── Helpers ───────────────────────────────────────────────────────────

        formatDatetime: function (dbDatetime) {
            if (!dbDatetime) { return ''; }
            var s = String(dbDatetime);
            var parts = s.split(' ');
            if (parts.length < 2) { return s; }
            var d = parts[0].split('-');
            if (d.length < 3) { return s; }
            return d[2] + '/' + d[1] + '/' + d[0] + ' ' + parts[1].substring(0, 5);
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

    window.AdminStoryboards = AdminStoryboards;
})();
