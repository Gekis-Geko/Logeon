const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

var AdminJobsTasks = {
    initialized: false,
    root: null,
    filtersForm: null,
    grid: null,
    modalNode: null,
    modal: null,
    modalForm: null,
    choicesBody: null,
    choicesEmpty: null,
    rows: [],
    rowsById: {},
    jobs: [],
    jobsById: {},
    locations: [],
    editingRow: null,

    init: function () {
        if (this.initialized) {
            return this;
        }

        this.root = document.querySelector('#admin-page [data-admin-page="jobs-tasks"]');
        if (!this.root) {
            return this;
        }

        this.filtersForm  = this.root.querySelector('#admin-jobs-tasks-filters');
        this.modalNode    = this.root.querySelector('#admin-jobs-tasks-modal');
        this.modalForm    = this.root.querySelector('#admin-jobs-tasks-form');
        this.choicesBody  = this.root.querySelector('#admin-jobs-tasks-choices-body');
        this.choicesEmpty = this.root.querySelector('#admin-jobs-tasks-choices-empty');

        if (!this.filtersForm || !this.modalNode || !this.modalForm) {
            return this;
        }

        this.modal = new bootstrap.Modal(this.modalNode);
        this.bind();
        this.initGrid();

        var self = this;
        this.loadJobs(function () {
            self.loadLocations(function () {
                self.loadGrid();
            });
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

        // Modal location autocomplete
        var modalLocInput = this.modalForm.querySelector('[name="requires_location_label"]');
        if (modalLocInput) {
            modalLocInput.addEventListener('input', function () {
                self.renderLocationSuggestions(this.value);
            });
            modalLocInput.addEventListener('focus', function () {
                if (this.value.length >= 1) {
                    self.renderLocationSuggestions(this.value);
                }
            });
        }

        // Global click: suggestion pick / dismiss
        document.addEventListener('click', function (event) {
            if (!self.root || !self.root.contains(event.target)) {
                return;
            }
            var trigger = event.target.closest('[data-action]');
            if (!trigger) {
                if (!event.target.closest('[name="requires_location_label"]')) {
                    self.hideAllSuggestions();
                }
                return;
            }

            var action = String(trigger.getAttribute('data-action') || '').trim();

            if (action === 'admin-jobs-tasks-pick-location') {
                event.preventDefault();
                self.pickLocation(trigger);
                return;
            }
            if (action === 'admin-jobs-tasks-remove-choice') {
                event.preventDefault();
                var row = trigger.closest('tr');
                if (row) { row.parentNode.removeChild(row); }
                self.syncChoicesEmpty();
                return;
            }
        });

        // Root action delegation
        this.root.addEventListener('click', function (event) {
            var trigger = event.target.closest('[data-action]');
            if (!trigger) {
                return;
            }
            var action = String(trigger.getAttribute('data-action') || '').trim();

            if (action === 'admin-jobs-tasks-reload') {
                event.preventDefault();
                self.loadGrid();
            } else if (action === 'admin-jobs-tasks-create') {
                event.preventDefault();
                self.openCreate();
            } else if (action === 'admin-jobs-tasks-edit') {
                event.preventDefault();
                var id = parseInt(trigger.getAttribute('data-id') || '0', 10);
                self.openEdit(id);
            } else if (action === 'admin-jobs-tasks-save') {
                event.preventDefault();
                self.save();
            } else if (action === 'admin-jobs-tasks-delete') {
                event.preventDefault();
                self.remove();
            } else if (action === 'admin-jobs-tasks-add-choice') {
                event.preventDefault();
                self.addChoiceRow();
            }
        });
    },

    initGrid: function () {
        var self = this;

        this.grid = new Datagrid('grid-admin-jobs-tasks', {
            name: 'AdminJobsTasks',
            autoindex: 'id',
            orderable: true,
            thead: true,
            handler: { url: '/admin/jobs-tasks/list', action: 'list' },
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
                    style: { textAlign: 'left', width: '220px' },
                    format: function (row) {
                        return self.escapeHtml(row.title || '-');
                    }
                },
                {
                    label: 'Lavoro',
                    field: 'job_name',
                    sortable: true,
                    style: { textAlign: 'left', width: '160px' },
                    format: function (row) {
                        return row.job_name
                            ? self.escapeHtml(row.job_name)
                            : '<span class="text-muted">—</span>';
                    }
                },
                {
                    label: 'Liv. min.',
                    field: 'min_level',
                    sortable: true,
                    style: { textAlign: 'center', width: '80px' },
                    format: function (row) {
                        return self.escapeHtml(String(row.min_level || 1));
                    }
                },
                {
                    label: 'Luogo richiesto',
                    field: 'requires_location_name',
                    sortable: false,
                    style: { textAlign: 'left' },
                    format: function (row) {
                        return row.requires_location_name
                            ? self.escapeHtml(row.requires_location_name)
                            : '<span class="text-muted">—</span>';
                    }
                },
                {
                    label: 'Stato',
                    field: 'is_active',
                    sortable: true,
                    style: { textAlign: 'center', width: '90px' },
                    format: function (row) {
                        return parseInt(row.is_active || 0, 10) === 1
                            ? '<span class="badge text-bg-success">Attivo</span>'
                            : '<span class="badge text-bg-secondary">Inattivo</span>';
                    }
                },
                {
                    label: 'Azioni',
                    sortable: false,
                    style: { textAlign: 'center', width: '80px' },
                    format: function (row) {
                        var id = self.escapeAttr(String(row.id || ''));
                        return '<button type="button" class="btn btn-sm btn-outline-secondary"'
                            + ' data-action="admin-jobs-tasks-edit" data-id="' + id + '">'
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
        this.grid.loadData(this.buildFiltersPayload(), 20, 1, 'jt.id|ASC');
        return this;
    },

    buildFiltersPayload: function () {
        var q = {};
        if (this.filtersForm) {
            var title    = (this.filtersForm.querySelector('[name="title"]') || {}).value || '';
            var jobId    = (this.filtersForm.querySelector('[name="job_id"]') || {}).value || '';
            var isActive = (this.filtersForm.querySelector('[name="is_active"]') || {}).value || '';

            if (title)    { q.title    = title; }
            if (jobId)    { q.job_id   = jobId; }
            if (isActive !== '') { q.is_active = isActive; }
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

    loadLocations: function (onDone) {
        var self = this;
        this.post('/admin/locations/list', { query: {}, results: 300, page: 1, orderBy: 'locations.name|ASC' }, function (response) {
            self.locations = (response && Array.isArray(response.dataset)) ? response.dataset : [];
            if (typeof onDone === 'function') { onDone(); }
        }, function () {
            if (typeof onDone === 'function') { onDone(); }
        });
    },

    fillJobSelects: function () {
        var html = '<option value="">Tutti</option>';
        for (var i = 0; i < this.jobs.length; i++) {
            var j = this.jobs[i];
            html += '<option value="' + this.escapeAttr(String(j.id)) + '">'
                + this.escapeHtml(j.name) + '</option>';
        }
        var filterSel = this.filtersForm ? this.filtersForm.querySelector('[name="job_id"]') : null;
        if (filterSel) { filterSel.innerHTML = html; }
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

    // ── Location autocomplete ────────────────────────────────────────────

    renderLocationSuggestions: function (term) {
        var suggestionsEl = this.modalForm
            ? this.modalForm.querySelector('[data-role="admin-jobs-tasks-location-suggestions"]')
            : null;
        if (!suggestionsEl) { return; }

        term = (term || '').toLowerCase().trim();
        if (term.length < 1) {
            suggestionsEl.style.display = 'none';
            suggestionsEl.innerHTML = '';
            return;
        }

        var self    = this;
        var matches = this.locations.filter(function (l) {
            return l.name.toLowerCase().indexOf(term) !== -1;
        }).slice(0, 8);

        if (!matches.length) {
            suggestionsEl.style.display = 'none';
            suggestionsEl.innerHTML = '';
            return;
        }

        suggestionsEl.innerHTML = matches.map(function (l) {
            return '<button type="button" class="list-group-item list-group-item-action py-1 small"'
                + ' data-action="admin-jobs-tasks-pick-location"'
                + ' data-id="' + self.escapeAttr(String(l.id)) + '"'
                + ' data-label="' + self.escapeAttr(l.name) + '">'
                + self.escapeHtml(l.name) + '</button>';
        }).join('');
        suggestionsEl.style.display = 'block';
    },

    pickLocation: function (trigger) {
        this.hideAllSuggestions();
        var id    = trigger.getAttribute('data-id') || '';
        var label = trigger.getAttribute('data-label') || '';
        var inputEl  = this.modalForm ? this.modalForm.querySelector('[name="requires_location_label"]') : null;
        var hiddenEl = this.modalForm ? this.modalForm.querySelector('[name="requires_location_id"]') : null;
        if (inputEl)  { inputEl.value  = label; }
        if (hiddenEl) { hiddenEl.value = id; }
    },

    hideAllSuggestions: function () {
        if (!this.root) { return; }
        this.root.querySelectorAll('[data-role="admin-jobs-tasks-location-suggestions"]').forEach(function (el) {
            el.style.display = 'none';
            el.innerHTML = '';
        });
    },

    // ── Choices ──────────────────────────────────────────────────────────

    renderChoices: function (choices) {
        if (!this.choicesBody) { return; }
        this.choicesBody.innerHTML = '';
        var items = choices || [];
        for (var i = 0; i < items.length; i++) {
            this.addChoiceRow(items[i]);
        }
        this.syncChoicesEmpty();
    },

    addChoiceRow: function (data) {
        if (!this.choicesBody) { return; }
        data = data || {};
        var code   = this.escapeAttr(String(data.choice_code || 'on'));
        var label  = this.escapeAttr(String(data.label || ''));
        var pay    = parseInt(data.pay || 0, 10);
        var fame   = parseInt(data.fame || 0, 10);
        var points = parseInt(data.points || 0, 10);

        var tr = document.createElement('tr');
        tr.innerHTML =
            '<td><input type="text" class="form-control form-control-sm" name="choice_code" maxlength="8" value="' + code + '" placeholder="on"></td>'
            + '<td><input type="text" class="form-control form-control-sm" name="choice_label" maxlength="100" value="' + label + '" placeholder="Etichetta" required></td>'
            + '<td><input type="number" class="form-control form-control-sm" name="choice_pay" value="' + pay + '" min="0"></td>'
            + '<td><input type="number" class="form-control form-control-sm" name="choice_fame" value="' + fame + '"></td>'
            + '<td><input type="number" class="form-control form-control-sm" name="choice_points" value="' + points + '" min="0"></td>'
            + '<td><button type="button" class="btn btn-sm btn-outline-danger" data-action="admin-jobs-tasks-remove-choice"><i class="bi bi-trash"></i></button></td>';
        this.choicesBody.appendChild(tr);
        this.syncChoicesEmpty();
    },

    collectChoices: function () {
        if (!this.choicesBody) { return []; }
        var choices = [];
        var rows = this.choicesBody.querySelectorAll('tr');
        for (var i = 0; i < rows.length; i++) {
            var tr     = rows[i];
            var code   = (tr.querySelector('[name="choice_code"]') || {}).value || 'on';
            var label  = ((tr.querySelector('[name="choice_label"]') || {}).value || '').trim();
            var pay    = parseInt((tr.querySelector('[name="choice_pay"]') || {}).value || '0', 10);
            var fame   = parseInt((tr.querySelector('[name="choice_fame"]') || {}).value || '0', 10);
            var points = parseInt((tr.querySelector('[name="choice_points"]') || {}).value || '0', 10);
            if (!label) { continue; }
            choices.push({ choice_code: code || 'on', label: label, pay: pay, fame: fame, points: points });
        }
        return choices;
    },

    syncChoicesEmpty: function () {
        if (!this.choicesEmpty || !this.choicesBody) { return; }
        var hasRows = this.choicesBody.querySelectorAll('tr').length > 0;
        this.choicesEmpty.style.display = hasRows ? 'none' : 'block';
    },

    // ── Modal ────────────────────────────────────────────────────────────

    openCreate: function () {
        this.editingRow = null;
        if (this.modalForm) { this.modalForm.reset(); }
        this.setField('id', '');
        this.setField('is_active', '1');
        this.setField('min_level', '1');
        this.setField('requires_location_id', '');
        this.setField('requires_location_label', '');
        this.fillModalJobSelect('');
        this.renderChoices([]);
        this.toggleDelete(false);
        this.modal.show();
    },

    openEdit: function (id) {
        if (!id) { return; }
        var self = this;
        this.post('/admin/jobs-tasks/get', { id: id }, function (response) {
            var task = response && response.task ? response.task : null;
            if (!task) {
                if (typeof Toast !== 'undefined') {
                    Toast.show({ body: 'Compito non trovato.', type: 'error' });
                }
                return;
            }
            self.editingRow = task;
            self.setField('id', String(task.id));
            self.setField('title', task.title || '');
            self.setField('body', task.body || '');
            self.setField('is_active', String(task.is_active));
            self.setField('min_level', String(task.min_level || 1));
            self.setField('requires_location_id', task.requires_location_id ? String(task.requires_location_id) : '');
            self.setField('requires_location_label', task.requires_location_name || '');
            self.fillModalJobSelect(task.job_id || '');
            self.renderChoices(task.choices || []);
            self.toggleDelete(true);
            self.modal.show();
        });
    },

    toggleDelete: function (show) {
        if (!this.modalNode) { return; }
        var btn = this.modalNode.querySelector('[data-action="admin-jobs-tasks-delete"]');
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
            id:                   parseInt(this.getField('id'), 10) || 0,
            job_id:               parseInt(this.getField('job_id'), 10) || 0,
            title:                this.getField('title').trim(),
            body:                 this.getField('body').trim(),
            min_level:            parseInt(this.getField('min_level'), 10) || 1,
            requires_location_id: this.getField('requires_location_id') || null,
            is_active:            parseInt(this.getField('is_active'), 10),
            choices:              this.collectChoices()
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
        var url   = isNew ? '/admin/jobs-tasks/create' : '/admin/jobs-tasks/update';
        var self  = this;

        this.post(url, payload, function () {
            self.modal.hide();
            if (typeof Toast !== 'undefined') {
                Toast.show({ body: isNew ? 'Compito creato.' : 'Compito aggiornato.', type: 'success' });
            }
            self.loadGrid();
        });
    },

    remove: function () {
        var payload = this.collectPayload();
        if (!payload.id) { return; }
        if (!confirm('Eliminare questo compito? L\'operazione non può essere annullata.')) { return; }

        var self = this;
        this.post('/admin/jobs-tasks/delete', { id: payload.id }, function () {
            self.modal.hide();
            if (typeof Toast !== 'undefined') {
                Toast.show({ body: 'Compito eliminato.', type: 'success' });
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

globalWindow.AdminJobsTasks = AdminJobsTasks;
export { AdminJobsTasks as AdminJobsTasks };
export default AdminJobsTasks;

