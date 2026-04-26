const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

var AdminGuildLocations = {
    initialized: false,
    root: null,
    filtersForm: null,
    grid: null,
    modalNode: null,
    modal: null,
    modalForm: null,
    rows: [],
    rowsById: {},
    guilds: [],
    locations: [],
    locationsById: {},
    editingRow: null,

    init: function () {
        if (this.initialized) {
            return this;
        }

        this.root = document.querySelector('#admin-page [data-admin-page="guilds-locations"]');
        if (!this.root) {
            return this;
        }

        this.filtersForm = this.root.querySelector('#admin-guild-locations-filters');
        this.modalNode   = this.root.querySelector('#admin-guild-locations-modal');
        this.modalForm   = this.root.querySelector('#admin-guild-locations-form');

        if (!this.filtersForm || !this.modalNode || !this.modalForm) {
            return this;
        }

        this.modal = new bootstrap.Modal(this.modalNode);
        this.bind();
        this.initGrid();
        this.loadDependencies();

        this.initialized = true;
        return this;
    },

    bind: function () {
        var self = this;

        this.filtersForm.addEventListener('submit', function (event) {
            event.preventDefault();
            self.loadGrid();
        });

        // Filter location autocomplete
        var filterLocationInput = this.filtersForm.querySelector('[name="location_label"]');
        if (filterLocationInput) {
            filterLocationInput.addEventListener('input', function () {
                self.clearLocationHidden(self.filtersForm);
                self.renderLocationSuggestions(
                    '[data-role="admin-guild-locations-filter-location-suggestions"]',
                    self.filtersForm,
                    filterLocationInput.value
                );
            });
        }

        // Modal guild select — cascade to roles
        var modalGuildSelect = this.modalForm.querySelector('[name="guild_id"]');
        if (modalGuildSelect) {
            modalGuildSelect.addEventListener('change', function () {
                var guildId = parseInt(modalGuildSelect.value, 10) || 0;
                self.loadRolesForGuild(guildId);
            });
        }

        // Modal location autocomplete
        var modalLocationInput = this.modalForm.querySelector('[name="location_label"]');
        if (modalLocationInput) {
            modalLocationInput.addEventListener('input', function () {
                self.clearLocationHidden(self.modalForm);
                self.renderLocationSuggestions(
                    '[data-role="admin-guild-locations-modal-location-suggestions"]',
                    self.modalForm,
                    modalLocationInput.value
                );
            });
        }

        document.addEventListener('click', function (event) {
            if (!self.root || !self.root.contains(event.target)) { return; }

            var suggestion = event.target.closest('[data-action="admin-guild-locations-pick-location"]');
            if (suggestion) {
                event.preventDefault();
                var scope = String(suggestion.getAttribute('data-scope') || 'modal').trim();
                var form  = scope === 'filter' ? self.filtersForm : self.modalForm;
                self.pickLocation(form, suggestion);
                return;
            }

            var isInsideSuggestions = event.target.closest(
                '[data-role="admin-guild-locations-filter-location-suggestions"],'
                + '[data-role="admin-guild-locations-modal-location-suggestions"],'
                + '[name="location_label"]'
            );
            if (!isInsideSuggestions) {
                self.hideLocationSuggestions();
            }
        });

        this.root.addEventListener('click', function (event) {
            var trigger = event.target.closest('[data-action]');
            if (!trigger) { return; }
            var action = String(trigger.getAttribute('data-action') || '').trim();

            if (action === 'admin-guild-locations-reload') {
                event.preventDefault();
                self.loadGrid();
            } else if (action === 'admin-guild-locations-create') {
                event.preventDefault();
                self.openCreate();
            } else if (action === 'admin-guild-locations-edit') {
                event.preventDefault();
                var id = parseInt(trigger.getAttribute('data-id') || '0', 10);
                self.openEdit(id);
            } else if (action === 'admin-guild-locations-save') {
                event.preventDefault();
                self.save();
            } else if (action === 'admin-guild-locations-delete') {
                event.preventDefault();
                self.remove();
            }
        });
    },

    initGrid: function () {
        var self = this;

        this.grid = new Datagrid('grid-admin-guild-locations', {
            name: 'AdminGuildLocations',
            autoindex: 'id',
            orderable: true,
            thead: true,
            handler: { url: '/admin/guild-role-locations/list', action: 'list' },
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
                    label: 'Gilda',
                    field: 'guild_name',
                    sortable: true,
                    style: { textAlign: 'left', width: '180px' },
                    format: function (row) {
                        return self.escapeHtml(row.guild_name || '-');
                    }
                },
                {
                    label: 'Ruolo',
                    field: 'role_name',
                    sortable: true,
                    style: { textAlign: 'left', width: '150px' },
                    format: function (row) {
                        return self.escapeHtml(row.role_name || '-');
                    }
                },
                {
                    label: 'Luogo',
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
                    label: 'Azioni',
                    sortable: false,
                    style: { textAlign: 'center', width: '80px' },
                    format: function (row) {
                        var id = self.escapeAttr(String(row.id || ''));
                        return '<button type="button" class="btn btn-sm btn-outline-secondary"'
                            + ' data-action="admin-guild-locations-edit" data-id="' + id + '">'
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
        this.grid.loadData(this.buildFiltersPayload(), 30, 1, 'id|ASC');
        return this;
    },

    buildFiltersPayload: function () {
        var q = {};
        if (this.filtersForm) {
            var guildId    = (this.filtersForm.querySelector('[name="guild_id"]') || {}).value || '';
            var locationId = (this.filtersForm.querySelector('[name="location_id"]') || {}).value || '';
            if (guildId)    { q.guild_id    = guildId; }
            if (locationId) { q.location_id = locationId; }
        }
        return q;
    },

    // ── Dependencies ──────────────────────────────────────────────────────

    loadDependencies: function () {
        var self = this;
        var done = 0;

        function onLoad() {
            done++;
            if (done >= 2) {
                self.fillGuildSelects();
                self.loadGrid();
            }
        }

        this.post('/admin/guilds/admin-list', { page: 1, results: 200, orderBy: 'name|ASC', query: {} }, function (res) {
            self.guilds = (res && Array.isArray(res.dataset)) ? res.dataset : [];
            onLoad();
        }, function () { onLoad(); });

        this.post('/admin/locations/list', { page: 1, results: 500, orderBy: 'name|ASC', query: {} }, function (res) {
            self.locations = (res && Array.isArray(res.dataset)) ? res.dataset : [];
            self.locationsById = {};
            for (var i = 0; i < self.locations.length; i++) {
                var l = self.locations[i];
                if (l && l.id) { self.locationsById[l.id] = l; }
            }
            onLoad();
        }, function () { onLoad(); });
    },

    fillGuildSelects: function () {
        var selects = [
            this.filtersForm ? this.filtersForm.querySelector('[name="guild_id"]') : null,
            this.modalForm   ? this.modalForm.querySelector('[name="guild_id"]')   : null
        ];
        for (var s = 0; s < selects.length; s++) {
            var sel = selects[s];
            if (!sel) { continue; }
            var cur = sel.value;
            while (sel.options.length > 1) { sel.remove(1); }
            for (var i = 0; i < this.guilds.length; i++) {
                var g = this.guilds[i];
                var opt = document.createElement('option');
                opt.value = String(g.id);
                opt.textContent = g.name || String(g.id);
                sel.appendChild(opt);
            }
            if (cur) { sel.value = cur; }
        }
    },

    loadRolesForGuild: function (guildId, selectedRoleId) {
        var self   = this;
        var sel    = this.modalForm ? this.modalForm.querySelector('[name="role_id"]') : null;
        var loader = this.modalForm ? this.modalForm.querySelector('[data-role="admin-guild-locations-roles-loading"]') : null;
        if (!sel) { return; }

        while (sel.options.length > 0) { sel.remove(0); }
        var placeholderOpt = document.createElement('option');
        placeholderOpt.value = '';
        placeholderOpt.textContent = guildId ? '— seleziona ruolo —' : '— seleziona prima la gilda —';
        sel.appendChild(placeholderOpt);

        if (!guildId) { return; }

        if (loader) { loader.classList.remove('d-none'); }

        this.post('/admin/guilds/roles-list', { guild_id: guildId }, function (res) {
            if (loader) { loader.classList.add('d-none'); }
            var roles = (res && Array.isArray(res.roles)) ? res.roles : [];
            for (var i = 0; i < roles.length; i++) {
                var r = roles[i];
                var opt = document.createElement('option');
                opt.value = String(r.id);
                opt.textContent = r.name || String(r.id);
                sel.appendChild(opt);
            }
            if (selectedRoleId) { sel.value = String(selectedRoleId); }
        }, function () {
            if (loader) { loader.classList.add('d-none'); }
        });
    },

    // ── Location autocomplete ─────────────────────────────────────────────

    renderLocationSuggestions: function (suggestionSelector, form, query) {
        var container = form ? form.querySelector(suggestionSelector) : null;
        if (!container) { return; }

        var q = (query || '').toLowerCase().trim();
        if (!q) { container.classList.add('d-none'); container.innerHTML = ''; return; }

        var scope = (suggestionSelector.indexOf('filter') !== -1) ? 'filter' : 'modal';
        var matches = [];
        for (var i = 0; i < this.locations.length && matches.length < 8; i++) {
            var l = this.locations[i];
            if ((l.name || '').toLowerCase().indexOf(q) !== -1) { matches.push(l); }
        }

        if (!matches.length) { container.classList.add('d-none'); container.innerHTML = ''; return; }

        container.innerHTML = '';
        for (var j = 0; j < matches.length; j++) {
            var m   = matches[j];
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'list-group-item list-group-item-action small';
            btn.textContent = m.name || String(m.id);
            btn.setAttribute('data-action', 'admin-guild-locations-pick-location');
            btn.setAttribute('data-id', String(m.id));
            btn.setAttribute('data-name', m.name || '');
            btn.setAttribute('data-scope', scope);
            container.appendChild(btn);
        }
        container.classList.remove('d-none');
    },

    pickLocation: function (form, suggestion) {
        if (!form) { return; }
        var id   = suggestion.getAttribute('data-id')   || '';
        var name = suggestion.getAttribute('data-name') || '';
        var labelInput  = form.querySelector('[name="location_label"]');
        var hiddenInput = form.querySelector('[name="location_id"]');
        if (labelInput)  { labelInput.value  = name; }
        if (hiddenInput) { hiddenInput.value = id; }
        this.hideLocationSuggestions();
    },

    clearLocationHidden: function (form) {
        var el = form ? form.querySelector('[name="location_id"]') : null;
        if (el) { el.value = ''; }
    },

    hideLocationSuggestions: function () {
        if (!this.root) { return; }
        var containers = this.root.querySelectorAll(
            '[data-role="admin-guild-locations-filter-location-suggestions"],'
            + '[data-role="admin-guild-locations-modal-location-suggestions"]'
        );
        for (var i = 0; i < containers.length; i++) {
            containers[i].classList.add('d-none');
            containers[i].innerHTML = '';
        }
    },

    // ── Modal ─────────────────────────────────────────────────────────────

    openCreate: function () {
        this.editingRow = null;
        if (this.modalForm) { this.modalForm.reset(); }
        this.setField('id', '');
        this.setField('location_id', '');
        this.fillGuildSelects();
        this.loadRolesForGuild(0);
        this.toggleDelete(false);
        this.modal.show();
    },

    openEdit: function (id) {
        var row = this.rowsById[id] || null;
        if (!row) { return; }
        this.editingRow = row;
        if (this.modalForm) { this.modalForm.reset(); }
        this.fillGuildSelects();
        this.setField('id', String(row.id));
        this.setField('guild_id', String(row.guild_id));
        this.setField('location_label', row.location_name || '');
        this.setField('location_id', String(row.location_id));
        this.loadRolesForGuild(parseInt(row.guild_id, 10) || 0, row.role_id);
        this.toggleDelete(true);
        this.modal.show();
    },

    toggleDelete: function (show) {
        if (!this.modalNode) { return; }
        var btn = this.modalNode.querySelector('[data-action="admin-guild-locations-delete"]');
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
            guild_id:    parseInt(this.getField('guild_id'), 10) || 0,
            role_id:     parseInt(this.getField('role_id'), 10) || 0,
            location_id: parseInt(this.getField('location_id'), 10) || 0
        };
    },

    save: function () {
        var payload = this.collectPayload();
        if (!payload.guild_id) {
            if (typeof Toast !== 'undefined') { Toast.show({ body: 'Seleziona una gilda.', type: 'error' }); }
            return;
        }
        if (!payload.role_id) {
            if (typeof Toast !== 'undefined') { Toast.show({ body: 'Seleziona un ruolo.', type: 'error' }); }
            return;
        }
        if (!payload.location_id) {
            if (typeof Toast !== 'undefined') { Toast.show({ body: 'Seleziona un luogo.', type: 'error' }); }
            return;
        }

        var isNew = !payload.id;
        var url   = isNew ? '/admin/guild-role-locations/create' : '/admin/guild-role-locations/update';
        var self  = this;

        this.post(url, payload, function () {
            self.modal.hide();
            if (typeof Toast !== 'undefined') {
                Toast.show({ body: isNew ? 'Associazione creata.' : 'Associazione aggiornata.', type: 'success' });
            }
            self.loadGrid();
        });
    },

    remove: function () {
        var payload = this.collectPayload();
        if (!payload.id) { return; }
        if (!confirm('Eliminare questa associazione? L\'operazione non può essere annullata.')) { return; }

        var self = this;
        this.post('/admin/guild-role-locations/delete', { id: payload.id }, function () {
            self.modal.hide();
            if (typeof Toast !== 'undefined') { Toast.show({ body: 'Associazione eliminata.', type: 'success' }); }
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

globalWindow.AdminGuildLocations = AdminGuildLocations;
export { AdminGuildLocations as AdminGuildLocations };
export default AdminGuildLocations;

