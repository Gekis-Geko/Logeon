const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

var AdminForums = {
    initialized: false,
    root: null,
    filtersForm: null,
    grid: null,
    modalNode: null,
    modal: null,
    modalForm: null,
    rows: [],
    rowsById: {},
    types: [],
    editingRow: null,

    init: function () {
        if (this.initialized) {
            return this;
        }

        this.root = document.querySelector('#admin-page [data-admin-page="forums"]');
        if (!this.root) {
            return this;
        }

        this.filtersForm = this.root.querySelector('#admin-forums-filters');
        this.modalNode   = this.root.querySelector('#admin-forums-modal');
        this.modalForm   = this.root.querySelector('#admin-forums-form');

        if (!this.filtersForm || !this.modalNode || !this.modalForm) {
            return this;
        }

        this.modal = new bootstrap.Modal(this.modalNode);
        this.bind();
        this.initGrid();
        this.loadTypes(function () {
            this.loadGrid();
        }.bind(this));

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

            if (action === 'admin-forums-reload') {
                event.preventDefault();
                self.loadGrid();
            } else if (action === 'admin-forums-create') {
                event.preventDefault();
                self.openCreate();
            } else if (action === 'admin-forums-edit') {
                event.preventDefault();
                var id = parseInt(trigger.getAttribute('data-id') || '0', 10);
                self.openEdit(id);
            } else if (action === 'admin-forums-save') {
                event.preventDefault();
                self.save();
            } else if (action === 'admin-forums-delete') {
                event.preventDefault();
                self.remove();
            }
        });
    },

    initGrid: function () {
        var self = this;

        this.grid = new Datagrid('grid-admin-forums', {
            name: 'AdminForums',
            autoindex: 'id',
            orderable: true,
            thead: true,
            handler: { url: '/admin/forums/list', action: 'list' },
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
                    label: 'Tipo',
                    field: 'type',
                    sortable: true,
                    style: { textAlign: 'left', width: '140px' },
                    format: function (row) {
                        return row.type_title
                            ? '<span class="small">' + self.escapeHtml(row.type_title) + '</span>'
                            : '<span class="text-muted">—</span>';
                    }
                },
                {
                    label: 'Thread',
                    field: 'count_thread',
                    sortable: false,
                    style: { textAlign: 'center', width: '80px' },
                    format: function (row) {
                        return '<span class="badge bg-secondary">' + (parseInt(row.count_thread, 10) || 0) + '</span>';
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
                    label: 'Azioni',
                    sortable: false,
                    style: { textAlign: 'center', width: '80px' },
                    format: function (row) {
                        var id = self.escapeAttr(String(row.id || ''));
                        return '<button type="button" class="btn btn-sm btn-outline-secondary"'
                            + ' data-action="admin-forums-edit" data-id="' + id + '">'
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
        this.grid.loadData(this.buildFiltersPayload(), 30, 1, 'name|ASC');
        return this;
    },

    buildFiltersPayload: function () {
        var q = {};
        if (this.filtersForm) {
            var name   = (this.filtersForm.querySelector('[name="name"]') || {}).value || '';
            var typeId = (this.filtersForm.querySelector('[name="type"]') || {}).value || '';
            if (name)   { q.name = name; }
            if (typeId) { q.type = typeId; }
        }
        return q;
    },

    // ── Types ─────────────────────────────────────────────────────────────

    loadTypes: function (cb) {
        var self = this;
        this.post('/admin/forums/types-list', {}, function (res) {
            self.types = (res && Array.isArray(res.types)) ? res.types : [];
            self.fillTypeSelects();
            if (typeof cb === 'function') { cb(); }
        }, function () {
            if (typeof cb === 'function') { cb(); }
        });
    },

    fillTypeSelects: function () {
        var selects = [
            this.filtersForm ? this.filtersForm.querySelector('[name="type"]') : null,
            this.modalForm   ? this.modalForm.querySelector('[name="type"]')   : null
        ];
        for (var s = 0; s < selects.length; s++) {
            var sel = selects[s];
            if (!sel) { continue; }
            var cur = sel.value;
            while (sel.options.length > 1) { sel.remove(1); }
            for (var i = 0; i < this.types.length; i++) {
                var t = this.types[i];
                var opt = document.createElement('option');
                opt.value = String(t.id);
                opt.textContent = t.title || String(t.id);
                sel.appendChild(opt);
            }
            if (cur) { sel.value = cur; }
        }
    },

    // ── Modal ─────────────────────────────────────────────────────────────

    openCreate: function () {
        this.editingRow = null;
        if (this.modalForm) { this.modalForm.reset(); }
        this.setField('id', '');
        this.fillTypeSelects();
        this.toggleDelete(false);
        this.modal.show();
    },

    openEdit: function (id) {
        var row = this.rowsById[id] || null;
        if (!row) { return; }
        this.editingRow = row;
        if (this.modalForm) { this.modalForm.reset(); }
        this.fillTypeSelects();
        this.setField('id', String(row.id));
        this.setField('name', row.name || '');
        this.setField('type', String(row.type || ''));
        this.setField('description', row.description || '');
        this.toggleDelete(true);
        this.modal.show();
    },

    toggleDelete: function (show) {
        if (!this.modalNode) { return; }
        var btn = this.modalNode.querySelector('[data-action="admin-forums-delete"]');
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
            type:        parseInt(this.getField('type'), 10) || 0,
            description: this.getField('description').trim()
        };
    },

    save: function () {
        var payload = this.collectPayload();
        if (!payload.name) {
            if (typeof Toast !== 'undefined') { Toast.show({ body: 'Il nome è obbligatorio.', type: 'error' }); }
            return;
        }
        if (!payload.type) {
            if (typeof Toast !== 'undefined') { Toast.show({ body: 'Seleziona un tipo.', type: 'error' }); }
            return;
        }

        var isNew = !payload.id;
        var url   = isNew ? '/admin/forums/create' : '/admin/forums/update';
        var self  = this;

        this.post(url, payload, function () {
            self.modal.hide();
            if (typeof Toast !== 'undefined') {
                Toast.show({ body: isNew ? 'Forum creato.' : 'Forum aggiornato.', type: 'success' });
            }
            self.loadGrid();
        });
    },

    remove: function () {
        var payload = this.collectPayload();
        if (!payload.id) { return; }
        if (!confirm('Eliminare questo forum? L\'operazione non può essere annullata.')) { return; }

        var self = this;
        this.post('/admin/forums/delete', { id: payload.id }, function () {
            self.modal.hide();
            if (typeof Toast !== 'undefined') { Toast.show({ body: 'Forum eliminato.', type: 'success' }); }
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

globalWindow.AdminForums = AdminForums;
export { AdminForums as AdminForums };
export default AdminForums;

