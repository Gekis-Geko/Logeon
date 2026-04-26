const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

var AdminJobs = {
    initialized: false,
    root: null,
    filtersForm: null,
    grid: null,
    modalNode: null,
    modal: null,
    modalForm: null,
    rows: [],
    rowsById: {},
    locations: [],
    locationsById: {},
    socialStatuses: [],
    editingRow: null,

    init: function () {
        if (this.initialized) {
            return;
        }
        this.initialized = true;

        this.root = document.querySelector('[data-admin-page="jobs"]');
        if (!this.root) {
            return;
        }

        this.filtersForm = this.root.querySelector('#admin-jobs-filters');
        this.modalNode   = document.getElementById('admin-jobs-modal');
        this.modalForm   = document.getElementById('admin-jobs-form');

        if (this.modalNode && typeof globalWindow.bootstrap !== 'undefined') {
            this.modal = new globalWindow.bootstrap.Modal(this.modalNode);
        }

        this.initGrid();
        this.bind();
        this.bindIconPreview();
        this.loadSocialStatuses(function () {
            AdminJobs.loadGrid();
        });
    },

    bind: function () {
        var self = this;

        if (this.filtersForm) {
            this.filtersForm.addEventListener('submit', function (e) {
                e.preventDefault();
                self.loadGrid();
            });
        }

        // Filter location autocomplete
        var filterLocationInput = this.root ? this.root.querySelector('#admin-jobs-filter-location-label') : null;
        if (filterLocationInput) {
            filterLocationInput.addEventListener('input', function () {
                self.renderLocationSuggestions(this.value, 'filter');
            });
            filterLocationInput.addEventListener('focus', function () {
                if (this.value.length >= 1) {
                    self.renderLocationSuggestions(this.value, 'filter');
                }
            });
        }

        // Modal location autocomplete
        if (this.modalForm) {
            var modalLocationInput = this.modalForm.querySelector('[name="location_label"]');
            if (modalLocationInput) {
                modalLocationInput.addEventListener('input', function () {
                    self.renderLocationSuggestions(this.value, 'modal');
                });
                modalLocationInput.addEventListener('focus', function () {
                    if (this.value.length >= 1) {
                        self.renderLocationSuggestions(this.value, 'modal');
                    }
                });
            }
        }

        // Global click: pick suggestion / dismiss
        document.addEventListener('click', function (e) {
            var item = e.target.closest('[data-role="admin-jobs-location-suggestion-item"]');
            if (item) {
                var context = item.getAttribute('data-context');
                var id      = item.getAttribute('data-id');
                var label   = item.getAttribute('data-label');
                self.pickLocation(id, label, context);
                return;
            }
            self.hideAllSuggestions();
        });

        // Root click delegation
        var root = this.root || document;
        root.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-action]');
            if (!btn) {
                return;
            }
            var action = btn.getAttribute('data-action');
            if (action === 'admin-jobs-reload') {
                self.loadGrid();
            } else if (action === 'admin-jobs-create') {
                self.openCreate();
            } else if (action === 'admin-jobs-save') {
                self.save();
            } else if (action === 'admin-jobs-delete') {
                self.remove();
            } else if (action === 'admin-jobs-edit') {
                var id = parseInt(btn.getAttribute('data-id'), 10);
                var row = self.rowsById[id] || null;
                self.openEdit(row);
            }
        });
    },

    initGrid: function () {
        var self = this;

        this.grid = new Datagrid('grid-admin-jobs', {
            name: 'AdminJobs',
            autoindex: 'id',
            orderable: true,
            thead: true,
            handler: { url: '/admin/jobs/list', action: 'list' },
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
                    label: 'Nome',
                    field: 'name',
                    sortable: true,
                    style: { textAlign: 'left', width: '200px' },
                    format: function (row) {
                        return self.escapeHtml(row.name || '-');
                    }
                },
                {
                    label: 'Luogo',
                    field: 'location_name',
                    sortable: true,
                    style: { textAlign: 'left', width: '180px' },
                    format: function (row) {
                        return row.location_name
                            ? self.escapeHtml(row.location_name)
                            : '<span class="text-muted">—</span>';
                    }
                },
                {
                    label: 'Paga base',
                    field: 'base_pay',
                    sortable: true,
                    style: { textAlign: 'center', width: '90px' },
                    format: function (row) {
                        return self.escapeHtml(String(row.base_pay || 0));
                    }
                },
                {
                    label: 'Inc./giorno',
                    field: 'daily_tasks',
                    sortable: false,
                    style: { textAlign: 'center', width: '90px' },
                    format: function (row) {
                        return self.escapeHtml(String(row.daily_tasks || 0));
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
                            + ' data-action="admin-jobs-edit" data-id="' + id + '">'
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
        this.grid.loadData(this.buildFiltersPayload(), 20, 1, 'j.id|ASC');
        return this;
    },

    buildFiltersPayload: function () {
        var q = {};
        if (this.filtersForm) {
            var name       = (this.filtersForm.querySelector('[name="name"]') || {}).value || '';
            var isActive   = (this.filtersForm.querySelector('[name="is_active"]') || {}).value || '';
            var locationId = (this.filtersForm.querySelector('[name="location_id"]') || {}).value || '';

            if (name)       { q.name        = name; }
            if (isActive !== '') { q.is_active = isActive; }
            if (locationId) { q.location_id = locationId; }
        }
        return q;
    },

    // ── Location autocomplete ────────────────────────────────────────────

    renderLocationSuggestions: function (term, context) {
        term = (term || '').toLowerCase().trim();
        var suggestionsEl = context === 'filter'
            ? (this.root ? this.root.querySelector('[data-role="admin-jobs-filter-location-suggestions"]') : null)
            : (this.modalForm ? this.modalForm.querySelector('[data-role="admin-jobs-location-suggestions"]') : null);

        if (!suggestionsEl) {
            return;
        }

        if (term.length < 1) {
            suggestionsEl.style.display = 'none';
            suggestionsEl.innerHTML = '';
            return;
        }

        var matches = this.locations.filter(function (l) {
            return l.name.toLowerCase().indexOf(term) !== -1;
        }).slice(0, 8);

        if (!matches.length) {
            suggestionsEl.style.display = 'none';
            suggestionsEl.innerHTML = '';
            return;
        }

        suggestionsEl.innerHTML = matches.map(function (l) {
            return '<button type="button" class="list-group-item list-group-item-action py-1 small"' +
                ' data-role="admin-jobs-location-suggestion-item"' +
                ' data-context="' + AdminJobs.escapeAttr(context) + '"' +
                ' data-id="' + AdminJobs.escapeAttr(String(l.id)) + '"' +
                ' data-label="' + AdminJobs.escapeAttr(l.name) + '">' +
                AdminJobs.escapeHtml(l.name) +
                '</button>';
        }).join('');
        suggestionsEl.style.display = 'block';
    },

    pickLocation: function (id, label, context) {
        this.hideAllSuggestions();
        if (context === 'filter') {
            var filterInput  = this.root ? this.root.querySelector('#admin-jobs-filter-location-label') : null;
            var filterHidden = this.root ? this.root.querySelector('[name="location_id"]') : null;
            if (filterInput)  { filterInput.value  = label; }
            if (filterHidden) { filterHidden.value = id; }
        } else {
            var modalInput  = this.modalForm ? this.modalForm.querySelector('[name="location_label"]') : null;
            var modalHidden = this.modalForm ? this.modalForm.querySelector('[name="location_id"]') : null;
            if (modalInput)  { modalInput.value  = label; }
            if (modalHidden) { modalHidden.value = id; }
        }
    },

    hideAllSuggestions: function () {
        document.querySelectorAll('[data-role="admin-jobs-filter-location-suggestions"],' +
            '[data-role="admin-jobs-location-suggestions"]').forEach(function (el) {
            el.style.display = 'none';
            el.innerHTML = '';
        });
    },

    // ── Social status select ─────────────────────────────────────────────

    loadSocialStatuses: function (onDone) {
        var self = this;
        var endpoints = globalWindow.LogeonModuleEndpoints || {};
        var socialStatusListEndpoint = String(endpoints.socialStatusList || '').trim();
        if (!socialStatusListEndpoint) {
            self.socialStatuses = [];
            self.loadLocations(onDone);
            return;
        }
        this.post(socialStatusListEndpoint, {}, function (res) {
            if (res && res.dataset && Array.isArray(res.dataset)) {
                self.socialStatuses = res.dataset;
            }
            // Also load locations
            self.loadLocations(onDone);
        }, function () {
            self.socialStatuses = [];
            self.loadLocations(onDone);
        });
    },

    loadLocations: function (onDone) {
        var self = this;
        this.post('/admin/locations/list', { query: {}, results: 200, page: 1, orderBy: 'locations.name|ASC' }, function (res) {
            if (res && res.dataset && Array.isArray(res.dataset)) {
                self.locations = res.dataset;
                self.locationsById = {};
                self.locations.forEach(function (l) {
                    self.locationsById[l.id] = l;
                });
            }
            if (typeof onDone === 'function') {
                onDone();
            }
        });
    },

    fillSocialStatusSelect: function (selectedId) {
        if (!this.modalForm) {
            return;
        }
        var sel = this.modalForm.querySelector('[name="min_socialstatus_id"]');
        if (!sel) {
            return;
        }
        var html = '<option value="">Nessuno</option>';
        this.socialStatuses.forEach(function (s) {
            html += '<option value="' + AdminJobs.escapeAttr(String(s.id)) + '"' +
                (String(s.id) === String(selectedId) ? ' selected' : '') + '>' +
                AdminJobs.escapeHtml(s.name) + '</option>';
        });
        sel.innerHTML = html;
    },

    // ── Modal ────────────────────────────────────────────────────────────

    openCreate: function () {
        this.editingRow = null;
        if (this.modalForm) {
            this.modalForm.reset();
            this.setModalField('id', '');
            this.setModalField('is_active', '1');
            this.setModalField('base_pay', '0');
            this.setModalField('daily_tasks', '2');
            this.setModalField('location_id', '');
            this.setModalField('location_label', '');
        }
        this.fillSocialStatusSelect('');
        this.toggleDelete(false);
        if (this.modal) {
            this.modal.show();
        }
    },

    openEdit: function (row) {
        if (!row) {
            return;
        }
        this.editingRow = row;
        this.setModalField('id', String(row.id));
        this.setModalField('name', row.name || '');
        this.setModalField('description', row.description || '');
        this.setModalField('icon', row.icon || '');
        this.updateIconPreview(row.icon || '');
        this.setModalField('is_active', String(row.is_active));
        this.setModalField('base_pay', String(row.base_pay || 0));
        this.setModalField('daily_tasks', String(row.daily_tasks || 2));
        // Location
        this.setModalField('location_id', row.location_id ? String(row.location_id) : '');
        this.setModalField('location_label', row.location_name || '');
        // Social status
        this.fillSocialStatusSelect(row.min_socialstatus_id || '');
        this.toggleDelete(true);
        if (this.modal) {
            this.modal.show();
        }
    },

    setModalField: function (name, value) {
        if (!this.modalForm) {
            return;
        }
        var el = this.modalForm.querySelector('[name="' + name + '"]');
        if (el) {
            el.value = value;
        }
    },

    getModalField: function (name) {
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
        var btn = this.modalNode.querySelector('[data-action="admin-jobs-delete"]');
        if (btn) {
            btn.classList.toggle('d-none', !show);
        }
    },

    collectPayload: function () {
        return {
            id:                  parseInt(this.getModalField('id'), 10) || 0,
            name:                this.getModalField('name').trim(),
            description:         this.getModalField('description').trim(),
            icon:                this.getModalField('icon').trim(),
            location_id:         this.getModalField('location_id') || null,
            min_socialstatus_id: this.getModalField('min_socialstatus_id') || null,
            base_pay:            parseInt(this.getModalField('base_pay'), 10) || 0,
            daily_tasks:         parseInt(this.getModalField('daily_tasks'), 10) || 2,
            is_active:           parseInt(this.getModalField('is_active'), 10)
        };
    },

    save: function () {
        var payload = this.collectPayload();
        if (!payload.name) {
            if (typeof Toast !== 'undefined') {
                Toast.show({ body: 'Il nome è obbligatorio.', type: 'error' });
            }
            return;
        }

        var isNew = !payload.id;
        var url   = isNew ? '/admin/jobs/create' : '/admin/jobs/update';
        var self  = this;

        this.post(url, payload, function (res) {
            if (self.modal) {
                self.modal.hide();
            }
            if (typeof Toast !== 'undefined') {
                Toast.show({ body: isNew ? 'Lavoro creato.' : 'Lavoro aggiornato.', type: 'success' });
            }
            self.loadGrid();
        });
    },

    remove: function () {
        var payload = this.collectPayload();
        if (!payload.id) {
            return;
        }
        if (!confirm('Eliminare questo lavoro? L\'operazione non può essere annullata.')) {
            return;
        }

        var self = this;
        this.post('/admin/jobs/delete', { id: payload.id }, function () {
            if (self.modal) {
                self.modal.hide();
            }
            if (typeof Toast !== 'undefined') {
                Toast.show({ body: 'Lavoro eliminato.', type: 'success' });
            }
            self.loadGrid();
        });
    },

    // ── Helpers ──────────────────────────────────────────────────────────

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
    },

    updateIconPreview: function (src) {
        if (!this.modalNode) { return; }
        var img = this.modalNode.querySelector('[data-role="admin-jobs-icon-preview"]');
        if (!img) { return; }
        if (src) {
            img.src = src;
            img.style.display = '';
        } else {
            img.src = '';
            img.style.display = 'none';
        }
    },

    bindIconPreview: function () {
        var self = this;
        if (!this.modalNode) { return; }
        var iconInput = this.modalNode.querySelector('[name="icon"]');
        if (!iconInput) { return; }
        iconInput.addEventListener('input', function () {
            self.updateIconPreview(iconInput.value.trim());
        });
    }
};

globalWindow.AdminJobs = AdminJobs;
export { AdminJobs as AdminJobs };
export default AdminJobs;

