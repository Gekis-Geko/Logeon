const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

var AdminNews = {
    initialized: false,
    root: null,
    filtersForm: null,
    grid: null,
    modalNode: null,
    modal: null,
    modalForm: null,
    rows: [],
    rowsById: {},

    init: function () {
        if (this.initialized) {
            return;
        }
        this.initialized = true;

        this.root = document.querySelector('[data-admin-page="news"]');
        if (!this.root) {
            return;
        }

        this.filtersForm = this.root.querySelector('#admin-news-filters');
        this.modalNode   = document.getElementById('admin-news-modal');
        this.modalForm   = document.getElementById('admin-news-form');

        if (this.modalNode && typeof globalWindow.bootstrap !== 'undefined') {
            this.modal = new globalWindow.bootstrap.Modal(this.modalNode);
        }

        this.initGrid();
        this.bind();
        this.loadGrid();
    },

    bind: function () {
        var self = this;

        if (this.filtersForm) {
            this.filtersForm.addEventListener('submit', function (e) {
                e.preventDefault();
                self.loadGrid();
            });
        }

        var root = this.root || document;
        root.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-action]');
            if (!btn) {
                return;
            }
            var action = btn.getAttribute('data-action');
            if (action === 'admin-news-reload') {
                self.loadGrid();
            } else if (action === 'admin-news-create') {
                self.openCreate();
            } else if (action === 'admin-news-save') {
                self.save();
            } else if (action === 'admin-news-delete') {
                self.remove();
            } else if (action === 'admin-news-edit') {
                var id  = parseInt(btn.getAttribute('data-id'), 10);
                var row = self.rowsById[id] || null;
                self.openEdit(row);
            }
        });
    },

    initGrid: function () {
        var self = this;

        this.grid = new Datagrid('grid-admin-news', {
            name: 'AdminNews',
            autoindex: 'id',
            orderable: true,
            thead: true,
            handler: { url: '/admin/news/list', action: 'list' },
            nav: { display: 'bottom', urlupdate: 0, results: 20, page: 1 },
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
                        var img = row.image
                            ? '<img src="' + self.escapeAttr(row.image) + '" width="24" height="24" class="me-2" style="object-fit:cover;border-radius:3px;vertical-align:middle;" alt="">'
                            : '';
                        return img + self.escapeHtml(row.title || '-');
                    }
                },
                {
                    label: 'Tipo',
                    field: 'type',
                    sortable: true,
                    style: { textAlign: 'center', width: '100px' },
                    format: function (row) {
                        return row.type ? self.escapeHtml(row.type) : '<span class="text-muted">—</span>';
                    }
                },
                {
                    label: 'Pubblicata',
                    field: 'is_published',
                    sortable: true,
                    style: { textAlign: 'center', width: '100px' },
                    format: function (row) {
                        return parseInt(row.is_published || 0, 10) === 1
                            ? '<span class="badge text-bg-success">Si</span>'
                            : '<span class="badge text-bg-secondary">No</span>';
                    }
                },
                {
                    label: 'Evidenza',
                    field: 'is_pinned',
                    sortable: true,
                    style: { textAlign: 'center', width: '90px' },
                    format: function (row) {
                        return parseInt(row.is_pinned || 0, 10) === 1
                            ? '<span class="badge text-bg-warning text-dark">Si</span>'
                            : '<span class="badge text-bg-secondary">No</span>';
                    }
                },
                {
                    label: 'Data',
                    field: 'date_created',
                    sortable: true,
                    style: { textAlign: 'center', width: '130px' },
                    format: function (row) {
                        var d = row.date_published || row.date_created || '';
                        return d ? self.escapeHtml(d.substring(0, 10)) : '<span class="text-muted">—</span>';
                    }
                },
                {
                    label: 'Azioni',
                    sortable: false,
                    style: { textAlign: 'center', width: '80px' },
                    format: function (row) {
                        var id = self.escapeAttr(String(row.id || ''));
                        return '<button type="button" class="btn btn-sm btn-outline-secondary"'
                            + ' data-action="admin-news-edit" data-id="' + id + '">'
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
            if (r && r.id) {
                this.rowsById[r.id] = r;
            }
        }
    },

    loadGrid: function () {
        if (!this.grid || typeof this.grid.loadData !== 'function') {
            return this;
        }
        this.grid.loadData(this.buildFiltersPayload(), 20, 1, 'news.id|DESC');
        return this;
    },

    buildFiltersPayload: function () {
        var q = {};
        if (this.filtersForm) {
            var title       = (this.filtersForm.querySelector('[name="title"]') || {}).value || '';
            var type        = (this.filtersForm.querySelector('[name="type"]') || {}).value || '';
            var isPublished = (this.filtersForm.querySelector('[name="is_published"]') || {}).value || '';

            if (title)           { q.title        = title; }
            if (type)            { q.type         = type; }
            if (isPublished !== '') { q.is_published = isPublished; }
        }
        return q;
    },

    openCreate: function () {
        if (this.modalForm) {
            this.modalForm.reset();
            this.setField('id', '');
            this.setField('is_published', '0');
            this.setField('is_pinned', '0');
            this.setField('date_published', '');
        }
        this.toggleDelete(false);
        if (this.modal) {
            this.modal.show();
        }
    },

    openEdit: function (row) {
        if (!row) {
            return;
        }
        this.setField('id', String(row.id));
        this.setField('title', row.title || '');
        this.setField('excerpt', row.excerpt || '');
        this.setField('body', row.body || '');
        this.setField('type', row.type || '');
        this.setField('image', row.image || '');
        this.setField('is_published', String(parseInt(row.is_published || 0, 10)));
        this.setField('is_pinned', String(parseInt(row.is_pinned || 0, 10)));
        var dp = row.date_published ? row.date_published.substring(0, 16) : '';
        this.setField('date_published', dp);
        this.toggleDelete(true);
        if (this.modal) {
            this.modal.show();
        }
    },

    setField: function (name, value) {
        if (!this.modalForm) {
            return;
        }
        var el = this.modalForm.querySelector('[name="' + name + '"]');
        if (el) {
            el.value = value;
        }
    },

    getField: function (name) {
        if (!this.modalForm) {
            return '';
        }
        var el = this.modalForm.querySelector('[name="' + name + '"]');
        return el ? el.value : '';
    },

    toggleDelete: function (show) {
        if (!this.modalNode) {
            return;
        }
        var btn = this.modalNode.querySelector('[data-action="admin-news-delete"]');
        if (btn) {
            btn.classList.toggle('d-none', !show);
        }
    },

    collectPayload: function () {
        var dp = this.getField('date_published').trim();
        return {
            id:             parseInt(this.getField('id'), 10) || 0,
            title:          this.getField('title').trim(),
            excerpt:        this.getField('excerpt').trim(),
            body:           this.getField('body').trim(),
            type:           this.getField('type').trim(),
            image:          this.getField('image').trim(),
            is_published:   parseInt(this.getField('is_published'), 10) || 0,
            is_pinned:      parseInt(this.getField('is_pinned'), 10) || 0,
            date_published: dp || null
        };
    },

    save: function () {
        var payload = this.collectPayload();
        if (!payload.title) {
            if (typeof Toast !== 'undefined') {
                Toast.show({ body: 'Il titolo è obbligatorio.', type: 'error' });
            }
            return;
        }

        var isNew = !payload.id;
        var url   = isNew ? '/admin/news/create' : '/admin/news/update';
        var self  = this;

        this.post(url, payload, function () {
            if (self.modal) {
                self.modal.hide();
            }
            if (typeof Toast !== 'undefined') {
                Toast.show({ body: isNew ? 'Novità creata.' : 'Novità aggiornata.', type: 'success' });
            }
            self.loadGrid();
        });
    },

    remove: function () {
        var payload = this.collectPayload();
        if (!payload.id) {
            return;
        }
        if (!confirm('Eliminare questa novità? L\'operazione non può essere annullata.')) {
            return;
        }

        var self = this;
        this.post('/admin/news/delete', { id: payload.id }, function () {
            if (self.modal) {
                self.modal.hide();
            }
            if (typeof Toast !== 'undefined') {
                Toast.show({ body: 'Novità eliminata.', type: 'success' });
            }
            self.loadGrid();
        });
    },

    post: function (url, payload, onSuccess, onError) {
        if (typeof Request !== 'function' || !Request.http || typeof Request.http.post !== 'function') {
            if (typeof Toast !== 'undefined') {
                Toast.show({ body: 'Servizio non disponibile.', type: 'error' });
            }
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

globalWindow.AdminNews = AdminNews;
export { AdminNews as AdminNews };
export default AdminNews;

