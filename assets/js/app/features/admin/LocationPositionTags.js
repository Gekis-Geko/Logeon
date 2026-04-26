const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

var AdminLocationPositionTags = {
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
    editingRow: null,

    init: function () {
        if (this.initialized) {
            return this;
        }

        this.root = document.querySelector('#admin-page [data-admin-page="location-position-tags"]');
        if (!this.root) {
            return this;
        }

        this.filtersForm = this.root.querySelector('#admin-location-position-tags-filters');
        this.modalNode   = this.root.querySelector('#admin-location-position-tags-modal');
        this.modalForm   = this.root.querySelector('#admin-location-position-tags-form');

        if (!this.filtersForm || !this.modalNode || !this.modalForm || !document.getElementById('grid-admin-location-position-tags')) {
            return this;
        }

        this.modal = new bootstrap.Modal(this.modalNode);
        this.bind();
        this.initGrid();

        var self = this;
        this.loadLocations(function () {
            self.loadGrid();
        });

        this.initialized = true;
        return this;
    },

    bind: function () {
        var self = this;
        var debounceTimer = null;

        this.filtersForm.addEventListener('submit', function (event) {
            event.preventDefault();
            self.loadGrid();
        });

        var searchInput = this.filtersForm.querySelector('[name="search"]');
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                if (debounceTimer) { clearTimeout(debounceTimer); }
                debounceTimer = setTimeout(function () { self.loadGrid(); }, 300);
            });
        }

        var filterLocationInput = this.filtersForm.querySelector('[name="location_label"]');
        if (filterLocationInput) {
            filterLocationInput.addEventListener('input', function () {
                self.syncHidden(self.filtersForm, 'location_id');
                self.renderLocationSuggestions(
                    self.filtersForm,
                    '[data-role="admin-lpt-filter-location-suggestions"]',
                    filterLocationInput.value,
                    'admin-lpt-pick-location',
                    'filter'
                );
            });
        }

        var modalLocationInput = this.modalForm.querySelector('[name="location_label"]');
        if (modalLocationInput) {
            modalLocationInput.addEventListener('input', function () {
                self.syncHidden(self.modalForm, 'location_id');
                self.renderLocationSuggestions(
                    self.modalForm,
                    '[data-role="admin-lpt-modal-location-suggestions"]',
                    modalLocationInput.value,
                    'admin-lpt-pick-location',
                    'form'
                );
            });
        }

        document.addEventListener('click', function (event) {
            if (!self.root || !self.root.contains(event.target)) {
                return;
            }

            var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
            if (!trigger) {
                if (!event.target.closest('[name="location_label"]')) {
                    self.hideAllSuggestions();
                }
                return;
            }

            var action = String(trigger.getAttribute('data-action') || '').trim();

            if (action === 'admin-lpt-pick-location') {
                event.preventDefault();
                var scope = String(trigger.getAttribute('data-scope') || 'form');
                self.pickLocation(scope === 'filter' ? self.filtersForm : self.modalForm, trigger);
                return;
            }
        });

        this.root.addEventListener('click', function (event) {
            var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
            if (!trigger) {
                return;
            }

            var action = String(trigger.getAttribute('data-action') || '').trim();

            if (action === 'admin-location-position-tags-reload') {
                event.preventDefault();
                self.loadGrid();
                return;
            }
            if (action === 'admin-location-position-tags-create') {
                event.preventDefault();
                self.openCreate();
                return;
            }
            if (action === 'admin-location-position-tags-edit') {
                event.preventDefault();
                self.openEdit(trigger);
                return;
            }
            if (action === 'admin-location-position-tag-save') {
                event.preventDefault();
                self.save();
                return;
            }
            if (action === 'admin-location-position-tag-delete') {
                event.preventDefault();
                self.remove();
                return;
            }
        });
    },

    initGrid: function () {
        var self = this;

        this.grid = new Datagrid('grid-admin-location-position-tags', {
            name: 'AdminLocationPositionTags',
            autoindex: 'id',
            orderable: true,
            thead: true,
            handler: { url: '/admin/location-position-tags/list', action: 'list' },
            nav: { display: 'bottom', urlupdate: 0, results: 25, page: 1 },
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
                    label: 'Location',
                    field: 'location_name',
                    sortable: true,
                    style: { textAlign: 'left' },
                    format: function (row) {
                        return row.location_name
                            ? self.escapeHtml(row.location_name)
                            : '<span class="text-muted">—</span>';
                    }
                },
                {
                    label: 'Nome',
                    field: 'name',
                    sortable: true,
                    style: { textAlign: 'left' },
                    format: function (row) {
                        var name = self.escapeHtml(row.name || '-');
                        var desc = row.short_description
                            ? '<div class="small text-muted">' + self.escapeHtml(row.short_description) + '</div>'
                            : '';
                        return name + desc;
                    }
                },
                {
                    label: 'Stato',
                    field: 'is_active',
                    sortable: true,
                    style: { textAlign: 'center' },
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
                            + ' data-action="admin-location-position-tags-edit" data-id="' + id + '">'
                            + '<i class="bi bi-pencil"></i></button>';
                    }
                }
            ]
        });
    },

    // ── Data loading ─────────────────────────────────────────────────────

    loadLocations: function (onDone) {
        var self = this;
        this.post('/admin/locations/list', { results: 500, page: 1, orderBy: 'locations.id|ASC' }, function (response) {
            var rows = response && Array.isArray(response.dataset) ? response.dataset : [];
            self.locations = [];
            self.locationsById = {};
            for (var i = 0; i < rows.length; i++) {
                var r = rows[i] || {};
                var id = parseInt(r.id || 0, 10) || 0;
                if (id <= 0) { continue; }
                var loc = { id: id, label: String(r.name || ('Location #' + id)) };
                self.locations.push(loc);
                self.locationsById[id] = loc;
            }
            if (typeof onDone === 'function') { onDone(); }
        }, function () {
            self.locations = [];
            if (typeof onDone === 'function') { onDone(); }
        });
    },

    loadGrid: function () {
        if (!this.grid || typeof this.grid.loadData !== 'function') {
            return this;
        }
        this.grid.loadData(this.buildFiltersPayload(), 25, 1, 'lpt.name|ASC');
        return this;
    },

    buildFiltersPayload: function () {
        var query      = {};
        var search     = this.getFilterValue('search');
        var locationId = parseInt(this.getFilterValue('location_id') || '0', 10) || 0;
        var isActive   = this.getFilterValue('is_active');

        if (search !== '')     { query.search      = search; }
        if (locationId > 0)    { query.location_id = locationId; }
        if (isActive !== '')   { query.is_active   = parseInt(isActive, 10); }

        return query;
    },

    getFilterValue: function (name) {
        if (!this.filtersForm || !this.filtersForm.elements || !this.filtersForm.elements[name]) {
            return '';
        }
        return String(this.filtersForm.elements[name].value || '').trim();
    },

    // ── Location autocomplete ─────────────────────────────────────────────

    syncHidden: function (form, hiddenName) {
        var hidden = form ? form.querySelector('[name="' + hiddenName + '"]') : null;
        if (hidden) { hidden.value = ''; }
    },

    renderLocationSuggestions: function (form, boxSelector, searchTerm, pickAction, scope) {
        if (!form) { return; }
        var box = form.querySelector(boxSelector);
        if (!box) { return; }

        var term = String(searchTerm || '').trim().toLowerCase();
        if (term.length < 1) {
            box.classList.add('d-none');
            box.innerHTML = '';
            return;
        }

        var results = [];
        for (var i = 0; i < this.locations.length && results.length < 8; i++) {
            if (this.locations[i].label.toLowerCase().indexOf(term) >= 0) {
                results.push(this.locations[i]);
            }
        }

        if (!results.length) {
            box.classList.add('d-none');
            box.innerHTML = '';
            return;
        }

        var html = '';
        for (var j = 0; j < results.length; j++) {
            html += '<button type="button" class="list-group-item list-group-item-action"'
                + ' data-action="' + pickAction + '"'
                + ' data-id="' + this.escapeAttr(String(results[j].id)) + '"'
                + ' data-label="' + this.escapeAttr(results[j].label) + '"'
                + ' data-scope="' + this.escapeAttr(scope) + '">'
                + this.escapeHtml(results[j].label)
                + '</button>';
        }
        box.innerHTML = html;
        box.classList.remove('d-none');
    },

    pickLocation: function (form, trigger) {
        if (!form || !trigger) { return; }
        var id    = parseInt(String(trigger.getAttribute('data-id') || '0'), 10) || 0;
        var label = String(trigger.getAttribute('data-label') || '').trim();
        var labelInput  = form.querySelector('[name="location_label"]');
        var hiddenInput = form.querySelector('[name="location_id"]');
        if (labelInput)  { labelInput.value  = label; }
        if (hiddenInput) { hiddenInput.value  = id > 0 ? String(id) : ''; }
        this.hideAllSuggestions();
    },

    hideAllSuggestions: function () {
        if (!this.root) { return; }
        var boxes = this.root.querySelectorAll('[data-role$="-suggestions"]');
        for (var i = 0; i < boxes.length; i++) {
            boxes[i].classList.add('d-none');
            boxes[i].innerHTML = '';
        }
    },

    locationLabelById: function (id) {
        var loc = this.locationsById[id];
        return loc ? loc.label : ('Location #' + id);
    },

    // ── Modal management ─────────────────────────────────────────────────

    openCreate: function () {
        this.editingRow = null;
        this.fillModalForm({});
        this.toggleDelete(false);
        this.modal.show();
        return this;
    },

    openEdit: function (trigger) {
        var row = this.rowFromTrigger(trigger);
        if (!row) { return this; }
        this.editingRow = row;
        this.fillModalForm(row);
        this.toggleDelete(true);
        this.modal.show();
        return this;
    },

    fillModalForm: function (row) {
        var data       = row || {};
        var locationId = parseInt(data.location_id || 0, 10) || 0;

        this.setModalField('id',                data.id || '');
        this.setModalField('location_id',       locationId > 0 ? String(locationId) : '');
        this.setModalField('location_label',    locationId > 0 ? this.locationLabelById(locationId) : '');
        this.setModalField('name',              data.name || '');
        this.setModalField('short_description', data.short_description || '');
        this.setModalField('thumbnail',         data.thumbnail || '');
        this.setModalField('is_active',         data.is_active != null ? String(parseInt(data.is_active, 10)) : '1');
        this.hideAllSuggestions();
        return this;
    },

    setModalField: function (name, value) {
        if (!this.modalForm) { return this; }
        var node = this.modalForm.querySelector('[name="' + name + '"]');
        if (!node) { return this; }
        node.value = value == null ? '' : String(value);
        return this;
    },

    getModalField: function (name) {
        if (!this.modalForm) { return ''; }
        var node = this.modalForm.querySelector('[name="' + name + '"]');
        return node ? String(node.value || '').trim() : '';
    },

    toggleDelete: function (visible) {
        var button = this.root ? this.root.querySelector('[data-action="admin-location-position-tag-delete"]') : null;
        if (!button) { return this; }
        button.classList.toggle('d-none', visible !== true);
        return this;
    },

    // ── CRUD operations ──────────────────────────────────────────────────

    collectPayload: function () {
        var locationId = parseInt(this.getModalField('location_id') || '0', 10) || 0;
        return {
            id:                parseInt(this.getModalField('id') || '0', 10) || 0,
            location_id:       locationId > 0 ? locationId : null,
            name:              this.getModalField('name'),
            short_description: this.getModalField('short_description') || null,
            thumbnail:         this.getModalField('thumbnail') || null,
            is_active:         this.getModalField('is_active') === '1' ? 1 : 0
        };
    },

    save: function () {
        var payload = this.collectPayload();
        if (!payload.name) {
            if (typeof Toast !== 'undefined') {
                Toast.show({ body: 'Il nome del tag è obbligatorio.', type: 'warning' });
            }
            return this;
        }
        if (!payload.location_id) {
            if (typeof Toast !== 'undefined') {
                Toast.show({ body: 'Seleziona una location.', type: 'warning' });
            }
            return this;
        }

        var self = this;
        var url  = payload.id > 0 ? '/admin/location-position-tags/update' : '/admin/location-position-tags/create';
        this.post(url, payload, function () {
            if (typeof Toast !== 'undefined') {
                Toast.show({ body: 'Tag salvato.', type: 'success' });
            }
            self.modal.hide();
            self.reloadGridKeepingPosition();
        });
        return this;
    },

    remove: function () {
        var id = parseInt(this.getModalField('id') || '0', 10) || 0;
        if (id <= 0) { return this; }

        var self = this;
        var dlg = Dialog('warning', {
            title: 'Conferma eliminazione',
            body: '<p>Vuoi eliminare questo tag? L\'operazione è irreversibile.</p>',
            confirmLabel: 'Elimina'
        }, function () {
            self.post('/admin/location-position-tags/delete', { id: id }, function () {
                dlg.hide();
                if (typeof Toast !== 'undefined') {
                    Toast.show({ body: 'Tag eliminato.', type: 'success' });
                }
                self.modal.hide();
                self.reloadGridKeepingPosition();
            });
        });
        dlg.show();

        return this;
    },

    // ── Helpers ──────────────────────────────────────────────────────────

    reloadGridKeepingPosition: function () {
        if (this.grid && typeof this.grid.reloadData === 'function') {
            this.grid.reloadData();
            return this;
        }
        return this.loadGrid();
    },

    setRows: function (rows) {
        this.rows = Array.isArray(rows) ? rows.slice() : [];
        this.rowsById = {};
        for (var i = 0; i < this.rows.length; i++) {
            var id = parseInt(this.rows[i].id || 0, 10) || 0;
            if (id > 0) { this.rowsById[id] = this.rows[i]; }
        }
        return this;
    },

    rowFromTrigger: function (trigger) {
        var id = parseInt(String(trigger.getAttribute('data-id') || '0'), 10) || 0;
        if (id <= 0) { return null; }
        return this.rowsById[id] || null;
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
                return;
            }
            var message = 'Operazione non riuscita.';
            if (typeof Request.getErrorMessage === 'function') {
                message = Request.getErrorMessage(error, message);
            }
            if (typeof Toast !== 'undefined') {
                Toast.show({ body: message, type: 'error' });
            }
        });

        return this;
    },

    escapeHtml: function (value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    },

    escapeAttr: function (value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }
};

if (typeof window !== 'undefined') {
    globalWindow.AdminLocationPositionTags = AdminLocationPositionTags;
}
export { AdminLocationPositionTags as AdminLocationPositionTags };
export default AdminLocationPositionTags;
