const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

var AdminRules = {
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

        this.root = document.querySelector('#admin-page [data-admin-page="rules"]');
        if (!this.root) {
            return this;
        }

        this.filtersForm = this.root.querySelector('#admin-rules-filters');
        this.modalNode   = this.root.querySelector('#admin-rules-modal');
        this.modalForm   = this.root.querySelector('#admin-rules-form');

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

            if (action === 'admin-rules-reload') {
                event.preventDefault();
                self.loadGrid();
            } else if (action === 'admin-rules-create') {
                event.preventDefault();
                self.openCreate();
            } else if (action === 'admin-rules-edit') {
                event.preventDefault();
                var id = parseInt(trigger.getAttribute('data-id') || '0', 10);
                self.openEdit(id);
            } else if (action === 'admin-rules-save') {
                event.preventDefault();
                self.save();
            } else if (action === 'admin-rules-delete') {
                event.preventDefault();
                self.remove();
            }
        });
    },

    initGrid: function () {
        var self = this;

        this.grid = new Datagrid('grid-admin-rules', {
            name: 'AdminRules',
            autoindex: 'id',
            orderable: true,
            thead: true,
            handler: { url: '/admin/rules/list', action: 'list' },
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
                    label: 'Art.',
                    field: 'article',
                    sortable: true,
                    style: { textAlign: 'center', width: '70px' }
                },
                {
                    label: 'Sotto.',
                    field: 'subarticle',
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
                    label: 'Azioni',
                    sortable: false,
                    style: { textAlign: 'center', width: '80px' },
                    format: function (row) {
                        var id = self.escapeAttr(String(row.id || ''));
                        return '<button type="button" class="btn btn-sm btn-outline-secondary"'
                            + ' data-action="admin-rules-edit" data-id="' + id + '">'
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
        this.grid.loadData(this.buildFiltersPayload(), 30, 1, 'article|ASC');
        return this;
    },

    buildFiltersPayload: function () {
        var q = {};
        if (this.filtersForm) {
            var title   = (this.filtersForm.querySelector('[name="title"]') || {}).value || '';
            var article = (this.filtersForm.querySelector('[name="article"]') || {}).value || '';
            if (title)   { q.title   = title; }
            if (article) { q.article = article; }
        }
        return q;
    },

    // ── Modal ─────────────────────────────────────────────────────────────

    openCreate: function () {
        this.editingRow = null;
        if (this.modalForm) { this.modalForm.reset(); }
        this.setField('id', '');
        this.setField('subarticle', '0');
        this.toggleDelete(false);
        this.modal.show();
    },

    openEdit: function (id) {
        var row = this.rowsById[id] || null;
        if (!row) { return; }
        this.editingRow = row;
        if (this.modalForm) { this.modalForm.reset(); }
        this.setField('id', String(row.id));
        this.setField('article', String(row.article || ''));
        this.setField('subarticle', String(row.subarticle || '0'));
        this.setField('title', row.title || '');
        this.setField('body', row.body || '');
        this.toggleDelete(true);
        this.modal.show();
    },

    toggleDelete: function (show) {
        if (!this.modalNode) { return; }
        var btn = this.modalNode.querySelector('[data-action="admin-rules-delete"]');
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
            article:    parseInt(this.getField('article'), 10) || 0,
            subarticle: parseInt(this.getField('subarticle'), 10) || 0,
            title:      this.getField('title').trim(),
            body:       this.getField('body')
        };
    },

    save: function () {
        var payload = this.collectPayload();
        if (!payload.article) {
            if (typeof Toast !== 'undefined') { Toast.show({ body: 'Il numero articolo è obbligatorio.', type: 'error' }); }
            return;
        }
        if (!payload.title) {
            if (typeof Toast !== 'undefined') { Toast.show({ body: 'Il titolo è obbligatorio.', type: 'error' }); }
            return;
        }

        var isNew = !payload.id;
        var url   = isNew ? '/admin/rules/create' : '/admin/rules/update';
        var self  = this;

        this.post(url, payload, function () {
            self.modal.hide();
            if (typeof Toast !== 'undefined') {
                Toast.show({ body: isNew ? 'Articolo creato.' : 'Articolo aggiornato.', type: 'success' });
            }
            self.loadGrid();
        });
    },

    remove: function () {
        var payload = this.collectPayload();
        if (!payload.id) { return; }
        if (!confirm('Eliminare questo articolo? L\'operazione non può essere annullata.')) { return; }

        var self = this;
        this.post('/admin/rules/delete', { id: payload.id }, function () {
            self.modal.hide();
            if (typeof Toast !== 'undefined') { Toast.show({ body: 'Articolo eliminato.', type: 'success' }); }
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

globalWindow.AdminRules = AdminRules;
export { AdminRules as AdminRules };
export default AdminRules;

