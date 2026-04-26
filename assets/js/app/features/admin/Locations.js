const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

var AdminLocations = {
    initialized: false,
    root: null,
    filtersForm: null,
    grid: null,
    modalNode: null,
    modal: null,
    modalForm: null,
    rows: [],
    rowsById: {},
    maps: [],
    mapsById: {},
    editingRow: null,
    tagsCatalog: [],

    init: function () {
        if (this.initialized) {
            return this;
        }

        this.root = document.querySelector('#admin-page [data-admin-page="locations"]');
        if (!this.root) {
            return this;
        }

        this.filtersForm = this.root.querySelector('#admin-locations-filters');
        this.modalNode   = this.root.querySelector('#admin-locations-modal');
        this.modalForm   = this.root.querySelector('#admin-locations-form');

        if (!this.filtersForm || !this.modalNode || !this.modalForm || !document.getElementById('grid-admin-locations')) {
            return this;
        }

        this.modal = new bootstrap.Modal(this.modalNode);
        this.bind();
        this.initGrid();
        this.loadTagCatalog();

        var self = this;
        this.loadMaps(function () {
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

        // Live text filter — debounced
        var nameInput = this.filtersForm.querySelector('[name="name"]');
        if (nameInput) {
            nameInput.addEventListener('input', function () {
                if (debounceTimer) { clearTimeout(debounceTimer); }
                debounceTimer = setTimeout(function () { self.loadGrid(); }, 300);
            });
        }

        // Filter map autocomplete
        var filterMapInput = this.filtersForm.querySelector('[name="map_label"]');
        if (filterMapInput) {
            filterMapInput.addEventListener('input', function () {
                self.syncHidden(self.filtersForm, 'map_id');
                self.renderMapSuggestions(
                    self.filtersForm,
                    '[data-role="admin-locations-filter-map-suggestions"]',
                    filterMapInput.value,
                    'admin-locations-pick-map',
                    'filter'
                );
            });
        }

        // Modal map autocomplete
        var modalMapInput = this.modalForm.querySelector('[name="map_label"]');
        if (modalMapInput) {
            modalMapInput.addEventListener('input', function () {
                self.syncHidden(self.modalForm, 'map_id');
                self.renderMapSuggestions(
                    self.modalForm,
                    '[data-role="admin-locations-map-suggestions"]',
                    modalMapInput.value,
                    'admin-locations-pick-map',
                    'form'
                );
            });
        }

        // Global click: pick suggestions + close on outside click
        document.addEventListener('click', function (event) {
            if (!self.root || !self.root.contains(event.target)) {
                return;
            }

            var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
            if (!trigger) {
                if (!event.target.closest('[name="map_label"]')) {
                    self.hideAllSuggestions();
                }
                return;
            }

            var action = String(trigger.getAttribute('data-action') || '').trim();

            if (action === 'admin-locations-pick-map') {
                event.preventDefault();
                var scope = String(trigger.getAttribute('data-scope') || 'form');
                self.pickMap(scope === 'filter' ? self.filtersForm : self.modalForm, trigger);
                return;
            }
        });

        // Root action delegation
        this.root.addEventListener('click', function (event) {
            var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
            if (!trigger) {
                return;
            }

            var action = String(trigger.getAttribute('data-action') || '').trim();

            if (action === 'admin-locations-reload') {
                event.preventDefault();
                self.loadGrid();
                return;
            }
            if (action === 'admin-locations-filters-reset') {
                event.preventDefault();
                self.filtersForm.reset();
                var hiddenMapId = self.filtersForm.querySelector('[name="map_id"]');
                if (hiddenMapId) { hiddenMapId.value = ''; }
                self.loadGrid();
                return;
            }
            if (action === 'admin-locations-create') {
                event.preventDefault();
                self.openCreate();
                return;
            }
            if (action === 'admin-locations-edit') {
                event.preventDefault();
                self.openEdit(trigger);
                return;
            }
            if (action === 'admin-locations-save') {
                event.preventDefault();
                self.save();
                return;
            }
            if (action === 'admin-locations-delete') {
                event.preventDefault();
                self.remove();
                return;
            }
        });
    },

    initGrid: function () {
        var self = this;

        this.grid = new Datagrid('grid-admin-locations', {
            name: 'AdminLocations',
            autoindex: 'id',
            orderable: true,
            thead: true,
            handler: { url: '/admin/locations/list', action: 'list' },
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
                    style: { textAlign: 'left' },
                    format: function (row) {
                        var name = self.escapeHtml(row.name || '-');
                        var desc = row.short_description ? '<div class="small text-muted">' + self.escapeHtml(row.short_description) + '</div>' : '';
                        return name + desc;
                    }
                },
                {
                    label: 'Mappa',
                    field: 'map_name',
                    sortable: true,
                    style: { textAlign: 'left' },
                    format: function (row) {
                        return row.map_name
                            ? self.escapeHtml(row.map_name)
                            : '<span class="text-muted">—</span>';
                    }
                },
                {
                    label: 'Stato',
                    field: 'status',
                    sortable: true,
                    style: { textAlign: 'center' },
                    format: function (row) {
                        return self.statusBadge(row.status);
                    }
                },
                {
                    label: 'Accesso',
                    field: 'access_policy',
                    sortable: true,
                    style: { textAlign: 'center' },
                    format: function (row) {
                        return self.accessPolicyBadge(row.access_policy);
                    }
                },
                {
                    label: 'Casa',
                    field: 'is_house',
                    sortable: false,
                    style: { textAlign: 'center' },
                    format: function (row) {
                        return parseInt(row.is_house || 0, 10) === 1
                            ? '<span class="badge text-bg-info">Si</span>'
                            : '<span class="badge text-bg-light text-dark">No</span>';
                    }
                },
                {
                    label: 'Chat',
                    field: 'is_chat',
                    sortable: false,
                    style: { textAlign: 'center' },
                    format: function (row) {
                        if (parseInt(row.is_chat || 0, 10) !== 1) {
                            return '<span class="badge text-bg-light text-dark">No</span>';
                        }
                        var type = row.chat_type ? (' <span class="text-muted small">(' + self.escapeHtml(row.chat_type) + ')</span>') : '';
                        return '<span class="badge text-bg-info">Si</span>' + type;
                    }
                },
                {
                    label: 'Privata',
                    field: 'is_private',
                    sortable: false,
                    style: { textAlign: 'center' },
                    format: function (row) {
                        return parseInt(row.is_private || 0, 10) === 1
                            ? '<span class="badge text-bg-warning">Si</span>'
                            : '<span class="badge text-bg-light text-dark">No</span>';
                    }
                },
                {
                    label: 'Azioni',
                    sortable: false,
                    style: { textAlign: 'center', width: '80px' },
                    format: function (row) {
                        var id = self.escapeAttr(String(row.id || ''));
                        return '<button type="button" class="btn btn-sm btn-outline-secondary"'
                            + ' data-action="admin-locations-edit" data-id="' + id + '">'
                            + '<i class="bi bi-pencil"></i></button>';
                    }
                }
            ]
        });
    },

    // ── Data loading ─────────────────────────────────────────────────────

    loadMaps: function (onDone) {
        var self = this;
        this.post('/admin/maps/list', { results: 200, page: 1, orderBy: 'name|ASC' }, function (response) {
            var rows = response && Array.isArray(response.dataset) ? response.dataset : [];
            self.maps = [];
            self.mapsById = {};
            for (var i = 0; i < rows.length; i++) {
                var r = rows[i] || {};
                var id = parseInt(r.id || 0, 10) || 0;
                if (id <= 0) { continue; }
                var map = { id: id, label: String(r.name || ('Mappa #' + id)) };
                self.maps.push(map);
                self.mapsById[id] = map;
            }
            if (typeof onDone === 'function') { onDone(); }
        }, function () {
            self.maps = [];
            if (typeof onDone === 'function') { onDone(); }
        });
    },

    loadGrid: function () {
        if (!this.grid || typeof this.grid.loadData !== 'function') {
            return this;
        }
        this.grid.loadData(this.buildFiltersPayload(), 20, 1, 'locations.id|ASC');
        return this;
    },

    buildFiltersPayload: function () {
        var query = {};
        var name         = this.getFilterValue('name');
        var mapId        = parseInt(this.getFilterValue('map_id') || '0', 10) || 0;
        var status       = this.getFilterValue('status');
        var isHouse      = this.getFilterValue('is_house');
        var isChat       = this.getFilterValue('is_chat');
        var accessPolicy = this.getFilterValue('access_policy');

        if (name !== '')         { query.name          = name; }
        if (mapId > 0)           { query.map_id        = mapId; }
        if (status !== '')       { query.status        = status; }
        if (isHouse !== '')      { query.is_house      = parseInt(isHouse, 10); }
        if (isChat !== '')       { query.is_chat       = parseInt(isChat, 10); }
        if (accessPolicy !== '') { query.access_policy = accessPolicy; }

        return query;
    },

    getFilterValue: function (name) {
        if (!this.filtersForm || !this.filtersForm.elements || !this.filtersForm.elements[name]) {
            return '';
        }
        return String(this.filtersForm.elements[name].value || '').trim();
    },

    // ── Map autocomplete ──────────────────────────────────────────────────

    syncHidden: function (form, hiddenName) {
        var hidden = form ? form.querySelector('[name="' + hiddenName + '"]') : null;
        if (hidden) { hidden.value = ''; }
    },

    renderMapSuggestions: function (form, boxSelector, searchTerm, pickAction, scope) {
        if (!form) { return; }
        var box = form.querySelector(boxSelector);
        if (!box) { return; }

        var term = String(searchTerm || '').trim().toLowerCase();
        if (term.length < 1) {
            box.style.display = 'none';
            box.innerHTML = '';
            return;
        }

        var results = [];
        for (var i = 0; i < this.maps.length && results.length < 8; i++) {
            if (this.maps[i].label.toLowerCase().indexOf(term) >= 0) {
                results.push(this.maps[i]);
            }
        }

        if (!results.length) {
            box.style.display = 'none';
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
        box.style.display = 'block';
    },

    pickMap: function (form, trigger) {
        if (!form || !trigger) { return; }
        var id    = parseInt(String(trigger.getAttribute('data-id') || '0'), 10) || 0;
        var label = String(trigger.getAttribute('data-label') || '').trim();
        var labelInput  = form.querySelector('[name="map_label"]');
        var hiddenInput = form.querySelector('[name="map_id"]');
        if (labelInput)  { labelInput.value  = label; }
        if (hiddenInput) { hiddenInput.value  = id > 0 ? String(id) : ''; }
        this.hideAllSuggestions();
    },

    hideAllSuggestions: function () {
        if (!this.root) { return; }
        var boxes = this.root.querySelectorAll('[data-role$="-suggestions"]');
        for (var i = 0; i < boxes.length; i++) {
            boxes[i].style.display = 'none';
            boxes[i].innerHTML = '';
        }
    },

    mapLabelById: function (id) {
        var map = this.mapsById[id];
        return map ? map.label : ('Mappa #' + id);
    },

    // ── Modal management ──────────────────────────────────────────────────

    openCreate: function () {
        this.editingRow = null;
        this.fillModalForm({});
        this.renderTagCheckboxes([]);
        this.toggleDelete(false);
        this.modal.show();
        return this;
    },

    openEdit: function (trigger) {
        var row = this.rowFromTrigger(trigger);
        if (!row) { return this; }
        this.editingRow = row;
        this.fillModalForm(row);
        this.renderTagCheckboxes([]);
        this.toggleDelete(true);
        this.modal.show();
        var self = this;
        this.post('/admin/locations/get', { id: row.id }, function (res) {
            var tagIds = res && res.dataset ? (res.dataset.narrative_tag_ids || []) : [];
            self.renderTagCheckboxes(tagIds);
        });
        return this;
    },

    loadTagCatalog: function () {
        var self = this;
        this.post('/list/narrative-tags', { entity_type: 'scene' }, function (res) {
            self.tagsCatalog = (res && Array.isArray(res.dataset)) ? res.dataset : [];
        });
    },

    renderTagCheckboxes: function (selectedIds) {
        var container = document.getElementById('admin-locations-tags-container');
        if (!container) { return; }
        if (!this.tagsCatalog.length) {
            container.innerHTML = '<span class="text-muted small">Nessun tag disponibile.</span>';
            return;
        }
        var selected = {};
        for (var i = 0; i < selectedIds.length; i++) {
            selected[parseInt(selectedIds[i], 10)] = true;
        }
        var html = '';
        for (var j = 0; j < this.tagsCatalog.length; j++) {
            var tag = this.tagsCatalog[j];
            var id = parseInt(tag.id || '0', 10);
            if (!id) { continue; }
            var checked = selected[id] ? ' checked' : '';
            html += '<div class="form-check form-check-inline mb-0">'
                + '<input class="form-check-input" type="checkbox" value="' + id + '" id="loc-tag-' + id + '"' + checked + '>'
                + '<label class="form-check-label small" for="loc-tag-' + id + '">' + this.escapeHtml(tag.label || tag.slug || ('Tag #' + id)) + '</label>'
                + '</div>';
        }
        container.innerHTML = html || '<span class="text-muted small">Nessun tag disponibile.</span>';
    },

    collectTagIds: function () {
        var container = document.getElementById('admin-locations-tags-container');
        if (!container) { return []; }
        var checks = container.querySelectorAll('input[type="checkbox"]:checked');
        var ids = [];
        for (var i = 0; i < checks.length; i++) {
            var v = parseInt(checks[i].value || '0', 10);
            if (v > 0) { ids.push(v); }
        }
        return ids;
    },

    fillModalForm: function (row) {
        var data  = row || {};
        var mapId = parseInt(data.map_id || 0, 10) || 0;

        this.setModalField('id',                data.id || '');
        this.setModalField('name',              data.name || '');
        this.setModalField('short_description', data.short_description || '');
        this.setModalField('description',       data.description || '');
        this.setModalField('map_id',            mapId > 0 ? String(mapId) : '');
        this.setModalField('map_label',         mapId > 0 ? this.mapLabelById(mapId) : '');
        this.setModalField('status',            data.status || 'open');
        this.setModalField('image',             data.image || '');
        this.setModalField('icon',              data.icon || '');
        this.setModalField('is_house',          String(parseInt(data.is_house || 0, 10)));
        this.setModalField('is_chat',           String(parseInt(data.is_chat || 0, 10)));
        this.setModalField('is_private',        String(parseInt(data.is_private || 0, 10)));
        this.setModalField('chat_type',         data.chat_type || '');
        this.setModalField('access_policy',     data.access_policy || 'open');
        this.setModalField('max_guests',        data.max_guests != null && data.max_guests !== '' ? String(data.max_guests) : '');
        this.setModalField('cost',              data.cost != null ? String(parseInt(data.cost || 0, 10)) : '0');
        this.setModalField('min_fame',          data.min_fame != null ? String(parseInt(data.min_fame || 0, 10)) : '0');
        this.setModalField('map_x',             data.map_x != null && data.map_x !== '' ? String(data.map_x) : '');
        this.setModalField('map_y',             data.map_y != null && data.map_y !== '' ? String(data.map_y) : '');
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
        var button = this.root ? this.root.querySelector('[data-action="admin-locations-delete"]') : null;
        if (!button) { return this; }
        button.classList.toggle('d-none', visible !== true);
        return this;
    },

    // ── CRUD operations ───────────────────────────────────────────────────

    collectPayload: function () {
        var mapId     = parseInt(this.getModalField('map_id') || '0', 10) || 0;
        var maxGuests = this.getModalField('max_guests');
        var mapX      = this.getModalField('map_x');
        var mapY      = this.getModalField('map_y');

        return {
            id:                parseInt(this.getModalField('id') || '0', 10) || 0,
            name:              this.getModalField('name'),
            short_description: this.getModalField('short_description'),
            description:       this.getModalField('description') || null,
            map_id:            mapId > 0 ? mapId : null,
            status:            this.getModalField('status') || 'open',
            image:             this.getModalField('image') || null,
            icon:              this.getModalField('icon') || null,
            is_house:          this.getModalField('is_house') === '1' ? 1 : 0,
            is_chat:           this.getModalField('is_chat') === '1' ? 1 : 0,
            is_private:        this.getModalField('is_private') === '1' ? 1 : 0,
            chat_type:         this.getModalField('chat_type') || null,
            access_policy:     this.getModalField('access_policy') || 'open',
            max_guests:        maxGuests !== '' ? parseInt(maxGuests, 10) : null,
            cost:              parseInt(this.getModalField('cost') || '0', 10) || 0,
            min_fame:          parseInt(this.getModalField('min_fame') || '0', 10) || 0,
            map_x:             mapX !== '' ? parseFloat(mapX) : null,
            map_y:             mapY !== '' ? parseFloat(mapY) : null,
            tag_ids:           this.collectTagIds()
        };
    },

    save: function () {
        var payload = this.collectPayload();
        if (!payload.name) {
            if (typeof Toast !== 'undefined') {
                Toast.show({ body: 'Nome luogo obbligatorio.', type: 'warning' });
            }
            return this;
        }

        var self = this;
        var url  = payload.id > 0 ? '/admin/locations/edit' : '/admin/locations/create';
        this.post(url, payload, function () {
            if (typeof Toast !== 'undefined') {
                Toast.show({ body: 'Luogo salvato.', type: 'success' });
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
        Dialog('warning', {
            title: 'Conferma eliminazione',
            body: '<p>Vuoi eliminare questo luogo? L\'operazione è irreversibile.</p>',
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
                        self.post('/admin/locations/delete', { id: id }, function () {
                            if (typeof Toast !== 'undefined') {
                                Toast.show({ body: 'Luogo eliminato.', type: 'success' });
                            }
                            self.modal.hide();
                            self.reloadGridKeepingPosition();
                        });
                    }
                }
            ]
        }).show();

        return this;
    },

    // ── Helpers ───────────────────────────────────────────────────────────

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

    statusBadge: function (status) {
        var map = {
            'open':    '<span class="badge text-bg-success">Aperto</span>',
            'closed':  '<span class="badge text-bg-secondary">Chiuso</span>',
            'locked':  '<span class="badge text-bg-danger">Bloccato</span>',
            'private': '<span class="badge text-bg-warning">Privato</span>'
        };
        return map[String(status || '').toLowerCase()] || '<span class="badge text-bg-light text-dark">' + this.escapeHtml(status || '—') + '</span>';
    },

    accessPolicyBadge: function (policy) {
        var map = {
            'open':   '<span class="badge text-bg-success">Aperta</span>',
            'guild':  '<span class="badge text-bg-info">Gilda</span>',
            'invite': '<span class="badge text-bg-warning">Invito</span>',
            'owner':  '<span class="badge text-bg-danger">Proprietario</span>'
        };
        return map[String(policy || '').toLowerCase()] || '<span class="badge text-bg-light text-dark">—</span>';
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
    globalWindow.AdminLocations = AdminLocations;
}
export { AdminLocations as AdminLocations };
export default AdminLocations;

