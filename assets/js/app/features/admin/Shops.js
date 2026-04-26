const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

var AdminShops = {
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

        this.root = document.querySelector('#admin-page [data-admin-page="shops"]');
        if (!this.root) {
            return this;
        }

        this.filtersForm = this.root.querySelector('#admin-shops-filters');
        this.modalNode = this.root.querySelector('#admin-shops-modal');
        this.modalForm = this.root.querySelector('#admin-shops-form');

        if (!this.filtersForm || !this.modalNode || !this.modalForm || !document.getElementById('grid-admin-shops')) {
            return this;
        }

        this.modal = new bootstrap.Modal(this.modalNode);
        this.bind();
        this.initGrid();
        this.loadLocations(function () {
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

        var filterLocationInput = this.filtersForm.querySelector('[name="location_label"]');
        if (filterLocationInput) {
            filterLocationInput.addEventListener('input', function () {
                self.syncLocationHidden(self.filtersForm);
                self.renderLocationSuggestions(
                    self.filtersForm,
                    '[data-role="admin-shops-filter-location-suggestions"]',
                    filterLocationInput.value
                );
            });
        }

        var modalLocationInput = this.modalForm.querySelector('[name="location_label"]');
        if (modalLocationInput) {
            modalLocationInput.addEventListener('input', function () {
                self.syncLocationHidden(self.modalForm);
                self.renderLocationSuggestions(
                    self.modalForm,
                    '[data-role="admin-shops-location-suggestions"]',
                    modalLocationInput.value
                );
            });
        }

        document.addEventListener('click', function (event) {
            if (!self.root || !self.root.contains(event.target)) {
                return;
            }

            var suggestion = event.target && event.target.closest ? event.target.closest('[data-action="admin-shops-pick-location"]') : null;
            if (suggestion) {
                event.preventDefault();
                var scope = String(suggestion.getAttribute('data-scope') || 'form').trim();
                self.pickLocation(scope === 'filter' ? self.filtersForm : self.modalForm, suggestion);
                return;
            }

            if (!event.target.closest('[data-role="admin-shops-filter-location-suggestions"], [data-role="admin-shops-location-suggestions"], [name="location_label"]')) {
                self.hideLocationSuggestions();
            }
        });

        this.root.addEventListener('click', function (event) {
            var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
            if (!trigger) {
                return;
            }

            var action = String(trigger.getAttribute('data-action') || '').trim();
            if (!action) {
                return;
            }

            if (action === 'admin-shops-reload') {
                event.preventDefault();
                self.loadGrid();
                return;
            }

            if (action === 'admin-shops-create') {
                event.preventDefault();
                self.openCreate();
                return;
            }

            if (action === 'admin-shops-edit') {
                event.preventDefault();
                self.openEdit(trigger);
                return;
            }

            if (action === 'admin-shops-save') {
                event.preventDefault();
                self.save();
                return;
            }

            if (action === 'admin-shops-delete') {
                event.preventDefault();
                self.remove();
                return;
            }
        });
    },

    initGrid: function () {
        var self = this;

        this.grid = new Datagrid('grid-admin-shops', {
            name: 'AdminShops',
            autoindex: 'id',
            orderable: true,
            thead: true,
            handler: { url: '/admin/shops/list', action: 'list' },
            nav: { display: 'bottom', urlupdate: 0, results: 20, page: 1 },
            onGetDataSuccess: function (response) {
                self.setRows(response && Array.isArray(response.dataset) ? response.dataset : []);
            },
            onGetDataError: function () {
                self.setRows([]);
            },
            columns: [
                { label: 'ID', field: 'id', sortable: true },
                {
                    label: 'Nome',
                    field: 'name',
                    sortable: true,
                    style: { textAlign: 'left' }
                },
                {
                    label: 'Tipo',
                    field: 'type',
                    sortable: true,
                    format: function (row) {
                        var type = self.normalizeType(row.type || 'global');
                        return type === 'global'
                            ? '<span class="badge text-bg-secondary">Globale</span>'
                            : '<span class="badge text-bg-info">Luogo</span>';
                    }
                },
                {
                    label: 'Luogo',
                    field: 'location_id',
                    sortable: true,
                    style: { textAlign: 'left' },
                    format: function (row) {
                        var locationId = parseInt(row.location_id || 0, 10) || 0;
                        if (locationId <= 0) {
                            return '<span class="text-muted">-</span>';
                        }
                        return self.escapeHtml(self.locationLabelById(locationId));
                    }
                },
                {
                    label: 'Stato',
                    sortable: false,
                    format: function (row) {
                        return (parseInt(row.is_active || 0, 10) === 1)
                            ? '<span class="badge text-bg-success">Attivo</span>'
                            : '<span class="badge text-bg-secondary">Inattivo</span>';
                    }
                },
                {
                    label: 'Azioni',
                    sortable: false,
                    format: function (row) {
                        var id = parseInt(row.id || 0, 10) || 0;
                        if (id <= 0) {
                            return '-';
                        }
                        return '<button type="button" class="btn btn-sm btn-outline-primary" data-action="admin-shops-edit" data-id="' + id + '">Modifica</button>';
                    }
                }
            ]
        });
    },

    loadLocations: function (onDone) {
        var self = this;
        this.post('/admin/locations/list', { results: 500, page: 1, orderBy: 'locations.name|ASC' }, function (response) {
            var rows = response && Array.isArray(response.dataset) ? response.dataset : [];
            self.setLocations(rows);
            if (typeof onDone === 'function') {
                onDone();
            }
        }, function () {
            self.setLocations([]);
            if (typeof onDone === 'function') {
                onDone();
            }
        });
    },

    setLocations: function (rows) {
        this.locations = [];
        this.locationsById = {};

        if (!Array.isArray(rows)) {
            return this;
        }

        for (var i = 0; i < rows.length; i++) {
            var row = rows[i] || {};
            var id = parseInt(row.id || row.location_id || 0, 10) || 0;
            if (id <= 0) {
                continue;
            }
            var name = String(row.name || row.location_name || ('Location #' + id)).trim();
            var mapName = String(row.map_name || '').trim();
            var label = mapName ? (name + ' [' + mapName + ']') : name;
            var item = { id: id, name: name, map_name: mapName, label: label };
            this.locations.push(item);
            this.locationsById[id] = item;
        }

        return this;
    },

    locationLabelById: function (locationId) {
        var id = parseInt(locationId || 0, 10) || 0;
        if (id <= 0) {
            return '-';
        }
        var item = this.locationsById[id] || null;
        if (!item) {
            return 'Location #' + id;
        }
        return item.label;
    },

    loadGrid: function () {
        if (!this.grid || typeof this.grid.loadData !== 'function') {
            return this;
        }

        this.grid.loadData(this.buildFiltersPayload(), 20, 1, 'name|ASC');
        return this;
    },

    buildFiltersPayload: function () {
        var query = {};
        var name = this.getFilterValue('name');
        var type = String(this.getFilterValue('type') || 'all').trim().toLowerCase();
        var status = String(this.getFilterValue('status') || 'all').trim().toLowerCase();
        var locationId = parseInt(this.getFilterValue('location_id') || '0', 10) || 0;

        if (name !== '') {
            query.name = name;
        }
        if (type === 'global' || type === 'location') {
            query.type = type;
        }
        if (status === 'active') {
            query.is_active = 1;
        } else if (status === 'inactive') {
            query.is_active = 0;
        }
        if (locationId > 0) {
            query.location_id = locationId;
        }

        return query;
    },

    getFilterValue: function (name) {
        if (!this.filtersForm || !this.filtersForm.elements || !this.filtersForm.elements[name]) {
            return '';
        }
        return String(this.filtersForm.elements[name].value || '').trim();
    },

    syncLocationHidden: function (form) {
        if (!form) {
            return;
        }
        var hidden = form.querySelector('[name="location_id"]');
        if (hidden) {
            hidden.value = '';
        }
    },

    renderLocationSuggestions: function (form, boxSelector, searchTerm) {
        if (!form) {
            return;
        }
        var box = form.querySelector(boxSelector);
        if (!box) {
            return;
        }

        var term = String(searchTerm || '').trim().toLowerCase();
        if (term.length < 1) {
            box.classList.add('d-none');
            box.innerHTML = '';
            return;
        }

        var scope = (form === this.filtersForm) ? 'filter' : 'form';
        var matches = [];
        for (var i = 0; i < this.locations.length; i++) {
            var item = this.locations[i];
            if (String(item.label || '').toLowerCase().indexOf(term) === -1
                && String(item.name || '').toLowerCase().indexOf(term) === -1) {
                continue;
            }
            matches.push(item);
            if (matches.length >= 8) {
                break;
            }
        }

        if (!matches.length) {
            box.classList.add('d-none');
            box.innerHTML = '';
            return;
        }

        var html = '';
        for (var m = 0; m < matches.length; m++) {
            var loc = matches[m];
            html += '<button type="button" class="list-group-item list-group-item-action"'
                + ' data-action="admin-shops-pick-location"'
                + ' data-scope="' + scope + '"'
                + ' data-location-id="' + loc.id + '"'
                + ' data-location-label="' + this.escapeAttr(loc.label) + '">'
                + this.escapeHtml(loc.label)
                + '</button>';
        }

        box.innerHTML = html;
        box.classList.remove('d-none');
    },

    pickLocation: function (form, trigger) {
        if (!form || !trigger) {
            return this;
        }
        var id = parseInt(trigger.getAttribute('data-location-id') || '0', 10) || 0;
        var label = String(trigger.getAttribute('data-location-label') || '').trim();

        var input = form.querySelector('[name="location_label"]');
        var hidden = form.querySelector('[name="location_id"]');
        if (input) {
            input.value = label;
        }
        if (hidden) {
            hidden.value = (id > 0) ? String(id) : '';
        }
        this.hideLocationSuggestions();
        return this;
    },

    hideLocationSuggestions: function () {
        var boxes = this.root.querySelectorAll('[data-role="admin-shops-filter-location-suggestions"], [data-role="admin-shops-location-suggestions"]');
        for (var i = 0; i < boxes.length; i++) {
            boxes[i].classList.add('d-none');
            boxes[i].innerHTML = '';
        }
        return this;
    },

    setRows: function (rows) {
        this.rows = Array.isArray(rows) ? rows.slice() : [];
        this.rowsById = {};
        for (var i = 0; i < this.rows.length; i++) {
            var id = parseInt(this.rows[i].id || 0, 10) || 0;
            if (id > 0) {
                this.rowsById[id] = this.rows[i];
            }
        }
        return this;
    },

    rowFromTrigger: function (trigger) {
        var id = parseInt(String(trigger.getAttribute('data-id') || '0'), 10) || 0;
        if (id <= 0) {
            return null;
        }
        return this.rowsById[id] || null;
    },

    openCreate: function () {
        this.editingRow = null;
        this.fillModalForm({});
        this.toggleDelete(false);
        this.modal.show();
        return this;
    },

    openEdit: function (trigger) {
        var row = this.rowFromTrigger(trigger);
        if (!row) {
            return this;
        }
        this.editingRow = row;
        this.fillModalForm(row);
        this.toggleDelete(true);
        this.modal.show();
        return this;
    },

    fillModalForm: function (row) {
        var data = row || {};
        var locationId = parseInt(data.location_id || 0, 10) || 0;

        this.setModalField('id', data.id || '');
        this.setModalField('name', data.name || '');
        this.setModalField('type', this.normalizeType(data.type || 'global'));
        this.setModalField('is_active', (parseInt(data.is_active || 0, 10) === 1) ? '1' : '0');
        this.setModalField('location_id', locationId > 0 ? String(locationId) : '');
        this.setModalField('location_label', locationId > 0 ? this.locationLabelById(locationId) : '');
        this.hideLocationSuggestions();

        return this;
    },

    setModalField: function (name, value) {
        if (!this.modalForm) {
            return this;
        }
        var node = this.modalForm.querySelector('[name="' + name + '"]');
        if (!node) {
            return this;
        }
        node.value = value == null ? '' : String(value);
        return this;
    },

    getModalField: function (name) {
        if (!this.modalForm) {
            return '';
        }
        var node = this.modalForm.querySelector('[name="' + name + '"]');
        return node ? String(node.value || '').trim() : '';
    },

    normalizeType: function (value) {
        var type = String(value || '').trim().toLowerCase();
        return (type === 'location') ? 'location' : 'global';
    },

    toggleDelete: function (visible) {
        var button = this.root.querySelector('[data-action="admin-shops-delete"]');
        if (!button) {
            return this;
        }
        button.classList.toggle('d-none', visible !== true);
        return this;
    },

    collectPayload: function () {
        var type = this.normalizeType(this.getModalField('type'));
        var locationId = parseInt(this.getModalField('location_id') || '0', 10) || 0;
        return {
            id: parseInt(this.getModalField('id') || '0', 10) || 0,
            name: this.getModalField('name'),
            type: type,
            location_id: (type === 'location' && locationId > 0) ? locationId : null,
            is_active: (this.getModalField('is_active') === '1') ? 1 : 0
        };
    },

    save: function () {
        var payload = this.collectPayload();
        if (!payload.name) {
            Toast.show({ body: 'Nome negozio obbligatorio.', type: 'warning' });
            return this;
        }

        var self = this;
        var url = payload.id > 0 ? '/admin/shops/update' : '/admin/shops/create';
        this.post(url, payload, function () {
            Toast.show({ body: 'Negozio salvato.', type: 'success' });
            self.modal.hide();
            self.reloadGridKeepingPosition();
        });
        return this;
    },

    remove: function () {
        var id = parseInt(this.getModalField('id') || '0', 10) || 0;
        if (id <= 0) {
            return this;
        }

        var self = this;
        Dialog('warning', {
            title: 'Conferma eliminazione',
            body: '<p>Vuoi eliminare questo negozio?</p>',
            buttons: [
                {
                    text: 'Annulla',
                    class: 'btn btn-secondary',
                    dismiss: true
                },
                {
                    text: 'Elimina',
                    class: 'btn btn-danger',
                    click: function () {
                        self.post('/admin/shops/delete', { id: id }, function () {
                            Toast.show({ body: 'Negozio eliminato.', type: 'success' });
                            self.modal.hide();
                            self.reloadGridKeepingPosition();
                        });
                    }
                }
            ]
        }).show();

        return this;
    },

    reloadGridKeepingPosition: function () {
        if (this.grid && typeof this.grid.reloadData === 'function') {
            this.grid.reloadData();
            return this;
        }
        return this.loadGrid();
    },

    post: function (url, payload, onSuccess, onError) {
        if (typeof Request !== 'function' || !Request.http || typeof Request.http.post !== 'function') {
            Toast.show({ body: 'Servizio non disponibile.', type: 'error' });
            return this;
        }

        Request.http.post(url, payload || {}).then(function (response) {
            if (typeof onSuccess === 'function') {
                onSuccess(response || null);
            }
        }).catch(function (error) {
            if (typeof onError === 'function') {
                onError(error);
                return;
            }
            var message = 'Operazione non riuscita.';
            if (typeof Request.getErrorMessage === 'function') {
                message = Request.getErrorMessage(error, message);
            }
            Toast.show({ body: message, type: 'error' });
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
    globalWindow.AdminShops = AdminShops;
}
export { AdminShops as AdminShops };
export default AdminShops;

