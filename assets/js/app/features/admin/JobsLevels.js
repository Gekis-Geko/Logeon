const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

var AdminJobsLevels = {
    initialized: false,
    root: null,
    filtersForm: null,
    grid: null,
    modalNode: null,
    modal: null,
    modalForm: null,
    rows: [],
    rowsById: {},
    jobs: [],
    jobsById: {},
    editingRow: null,

    init: function () {
        if (this.initialized) {
            return this;
        }

        this.root = document.querySelector('#admin-page [data-admin-page="jobs-levels"]');
        if (!this.root) {
            return this;
        }

        this.filtersForm = this.root.querySelector('#admin-jobs-levels-filters');
        this.modalNode   = this.root.querySelector('#admin-jobs-levels-modal');
        this.modalForm   = this.root.querySelector('#admin-jobs-levels-form');

        if (!this.filtersForm || !this.modalNode || !this.modalForm) {
            return this;
        }

        this.modal = new bootstrap.Modal(this.modalNode);
        this.bind();
        this.initGrid();

        var self = this;
        this.loadJobs(function () {
            self.loadGrid();
        });

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
            if (!trigger) {
                return;
            }
            var action = String(trigger.getAttribute('data-action') || '').trim();

            if (action === 'admin-jobs-levels-reload') {
                event.preventDefault();
                self.loadGrid();
            } else if (action === 'admin-jobs-levels-create') {
                event.preventDefault();
                self.openCreate();
            } else if (action === 'admin-jobs-levels-edit') {
                event.preventDefault();
                var id = parseInt(trigger.getAttribute('data-id') || '0', 10);
                self.openEdit(id);
            } else if (action === 'admin-jobs-levels-save') {
                event.preventDefault();
                self.save();
            } else if (action === 'admin-jobs-levels-delete') {
                event.preventDefault();
                self.remove();
            }
        });
    },

    initGrid: function () {
        var self = this;

        this.grid = new Datagrid('grid-admin-jobs-levels', {
            name: 'AdminJobsLevels',
            autoindex: 'id',
            orderable: true,
            thead: true,
            handler: { url: '/admin/jobs-levels/list', action: 'list' },
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
                    label: 'Lavoro',
                    field: 'job_name',
                    sortable: true,
                    style: { textAlign: 'left', width: '200px' },
                    format: function (row) {
                        return self.escapeHtml(row.job_name || '-');
                    }
                },
                {
                    label: 'Livello',
                    field: 'level',
                    sortable: true,
                    style: { textAlign: 'center', width: '80px' },
                    format: function (row) {
                        return self.escapeHtml(String(row.level || 1));
                    }
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
                    label: 'Punti minimi',
                    field: 'min_points',
                    sortable: true,
                    style: { textAlign: 'center', width: '110px' },
                    format: function (row) {
                        return self.escapeHtml(String(row.min_points || 0));
                    }
                },
                {
                    label: 'Bonus paga %',
                    field: 'pay_bonus_percent',
                    sortable: false,
                    style: { textAlign: 'center', width: '110px' },
                    format: function (row) {
                        var val = parseInt(row.pay_bonus_percent || 0, 10);
                        return val > 0
                            ? '<span class="badge text-bg-success">+' + val + '%</span>'
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
                            + ' data-action="admin-jobs-levels-edit" data-id="' + id + '">'
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
        this.grid.loadData(this.buildFiltersPayload(), 20, 1, 'j.name|ASC');
        return this;
    },

    buildFiltersPayload: function () {
        var q = {};
        if (this.filtersForm) {
            var jobId = (this.filtersForm.querySelector('[name="job_id"]') || {}).value || '';
            var title = (this.filtersForm.querySelector('[name="title"]') || {}).value || '';
            if (jobId)  { q.job_id = jobId; }
            if (title)  { q.title  = title; }
        }
        return q;
    },

    // ── Data loaders ─────────────────────────────────────────────────────

    loadJobs: function (onDone) {
        var self = this;
        this.post('/admin/jobs/list', { query: {}, results: 200, page: 1, orderBy: 'j.name|ASC' }, function (response) {
            var rows = (response && Array.isArray(response.dataset)) ? response.dataset : [];
            self.jobs     = rows;
            self.jobsById = {};
            for (var i = 0; i < rows.length; i++) {
                if (rows[i] && rows[i].id) {
                    self.jobsById[rows[i].id] = rows[i];
                }
            }
            self.fillJobSelects();
            if (typeof onDone === 'function') { onDone(); }
        }, function () {
            if (typeof onDone === 'function') { onDone(); }
        });
    },

    fillJobSelects: function () {
        var filterHtml = '<option value="">Tutti</option>';
        var modalHtml  = '<option value="">— Seleziona —</option>';
        for (var i = 0; i < this.jobs.length; i++) {
            var j    = this.jobs[i];
            var opt  = '<option value="' + this.escapeAttr(String(j.id)) + '">'
                + this.escapeHtml(j.name) + '</option>';
            filterHtml += opt;
            modalHtml  += opt;
        }
        var filterSel = this.filtersForm ? this.filtersForm.querySelector('[name="job_id"]') : null;
        if (filterSel) { filterSel.innerHTML = filterHtml; }

        var modalSel = this.modalForm ? this.modalForm.querySelector('[name="job_id"]') : null;
        if (modalSel) { modalSel.innerHTML = modalHtml; }
    },

    fillModalJobSelect: function (selectedId) {
        if (!this.modalForm) { return; }
        var sel = this.modalForm.querySelector('[name="job_id"]');
        if (!sel) { return; }
        var html = '<option value="">— Seleziona —</option>';
        for (var i = 0; i < this.jobs.length; i++) {
            var j = this.jobs[i];
            html += '<option value="' + this.escapeAttr(String(j.id)) + '"'
                + (String(j.id) === String(selectedId) ? ' selected' : '') + '>'
                + this.escapeHtml(j.name) + '</option>';
        }
        sel.innerHTML = html;
    },

    // ── Modal ────────────────────────────────────────────────────────────

    openCreate: function () {
        this.editingRow = null;
        if (this.modalForm) { this.modalForm.reset(); }
        this.setField('id', '');
        this.setField('level', '1');
        this.setField('min_points', '0');
        this.setField('pay_bonus_percent', '0');
        this.fillModalJobSelect('');
        this.toggleDelete(false);
        this.modal.show();
    },

    openEdit: function (id) {
        var row = this.rowsById[id] || null;
        if (!row) { return; }
        this.editingRow = row;
        this.setField('id', String(row.id));
        this.setField('level', String(row.level || 1));
        this.setField('title', row.title || '');
        this.setField('min_points', String(row.min_points || 0));
        this.setField('pay_bonus_percent', String(row.pay_bonus_percent || 0));
        this.fillModalJobSelect(row.job_id || '');
        this.toggleDelete(true);
        this.modal.show();
    },

    toggleDelete: function (show) {
        if (!this.modalNode) { return; }
        var btn = this.modalNode.querySelector('[data-action="admin-jobs-levels-delete"]');
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
            id:                 parseInt(this.getField('id'), 10) || 0,
            job_id:             parseInt(this.getField('job_id'), 10) || 0,
            level:              parseInt(this.getField('level'), 10) || 1,
            title:              this.getField('title').trim(),
            min_points:         parseInt(this.getField('min_points'), 10) || 0,
            pay_bonus_percent:  parseInt(this.getField('pay_bonus_percent'), 10) || 0
        };
    },

    save: function () {
        var payload = this.collectPayload();
        if (!payload.title) {
            if (typeof Toast !== 'undefined') { Toast.show({ body: 'Il titolo è obbligatorio.', type: 'error' }); }
            return;
        }
        if (!payload.job_id) {
            if (typeof Toast !== 'undefined') { Toast.show({ body: 'Seleziona un lavoro.', type: 'error' }); }
            return;
        }

        var isNew = !payload.id;
        var url   = isNew ? '/admin/jobs-levels/create' : '/admin/jobs-levels/update';
        var self  = this;

        this.post(url, payload, function () {
            self.modal.hide();
            if (typeof Toast !== 'undefined') {
                Toast.show({ body: isNew ? 'Livello creato.' : 'Livello aggiornato.', type: 'success' });
            }
            self.loadGrid();
        });
    },

    remove: function () {
        var payload = this.collectPayload();
        if (!payload.id) { return; }
        if (!confirm('Eliminare questo livello? L\'operazione non può essere annullata.')) { return; }

        var self = this;
        this.post('/admin/jobs-levels/delete', { id: payload.id }, function () {
            self.modal.hide();
            if (typeof Toast !== 'undefined') {
                Toast.show({ body: 'Livello eliminato.', type: 'success' });
            }
            self.loadGrid();
        });
    },

    // ── HTTP helper ──────────────────────────────────────────────────────

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

    // ── Utils ────────────────────────────────────────────────────────────

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

globalWindow.AdminJobsLevels = AdminJobsLevels;
export { AdminJobsLevels as AdminJobsLevels };
export default AdminJobsLevels;

