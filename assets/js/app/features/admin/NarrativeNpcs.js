(function () {
    'use strict';

    var AdminNarrativeNpcs = {
        initialized: false,
        root: null,
        filtersForm: null,
        grid: null,
        modalNode: null,
        modal: null,
        modalForm: null,
        modalAlert: null,
        rows: [],
        rowsById: {},
        editingRow: null,
        guilds: [],
        factions: [],

        init: function () {
            if (this.initialized) { return this; }

            this.root = document.querySelector('#admin-page [data-admin-page="narrative-npcs"]');
            if (!this.root) { return this; }

            this.filtersForm = this.root.querySelector('#admin-npcs-filters');
            this.modalNode   = document.getElementById('admin-npcs-modal');
            this.modalForm   = document.getElementById('admin-npcs-form');
            this.modalAlert  = document.getElementById('admin-npcs-modal-alert');

            if (!this.filtersForm || !this.modalNode || !this.modalForm) { return this; }

            this.modal = new bootstrap.Modal(this.modalNode);
            this.loadGuilds();
            this.loadFactions();
            this.initGrid();
            this.loadGrid();
            this.bind();

            this.initialized = true;
            return this;
        },

        // ── Grid ──────────────────────────────────────────────────────────────

        initGrid: function () {
            var self = this;

            this.grid = new Datagrid('grid-admin-npcs', {
                name: 'AdminNPCs',
                autoindex: 'id',
                orderable: true,
                thead: true,
                handler: { url: '/admin/narrative-npcs/list', action: 'list' },
                nav: { display: 'bottom', urlupdate: 0, results: 25, page: 1 },
                onGetDataSuccess: function (response) {
                    self.setRows(response && Array.isArray(response.dataset) ? response.dataset : []);
                },
                onGetDataError: function () { self.setRows([]); },
                columns: [
                    {
                        label: 'ID', field: 'id', sortable: true, width: '60px',
                        format: function (r) {
                            return '<span class="text-muted small">' + self.escapeHtml(String(r.id || '')) + '</span>';
                        }
                    },
                    {
                        label: 'Immagine', field: 'image', sortable: false, width: '60px',
                        format: function (r) {
                            if (!r.image) {
                                return '<span class="text-muted small">—</span>';
                            }
                            return '<img src="' + self.escapeAttr(String(r.image)) + '" alt="" class="rounded" style="width:40px;height:40px;object-fit:cover;">';
                        }
                    },
                    {
                        label: 'Nome', field: 'name', sortable: true,
                        format: function (r) {
                            return '<span class="fw-semibold">' + self.escapeHtml(String(r.name || '')) + '</span>';
                        }
                    },
                    {
                        label: 'Gruppo', field: 'group_type', sortable: true, width: '160px',
                        format: function (r) {
                            if (!r.group_type || r.group_type === 'none') {
                                return '<span class="text-muted small">—</span>';
                            }
                            var typeLabel = r.group_type === 'guild' ? 'Gilda' : 'Fazione';
                            var badge = '<span class="badge bg-secondary me-1">' + self.escapeHtml(typeLabel) + '</span>';
                            var name = r.group_name ? self.escapeHtml(String(r.group_name)) : ('#' + self.escapeHtml(String(r.group_id || '')));
                            return badge + '<span class="small">' + name + '</span>';
                        }
                    },
                    {
                        label: 'Stato', field: 'is_active', sortable: true, width: '90px',
                        format: function (r) {
                            return r.is_active == 1
                                ? '<span class="badge bg-success">Attivo</span>'
                                : '<span class="badge bg-secondary">Inattivo</span>';
                        }
                    },
                    {
                        label: '', field: 'id', sortable: false, width: '50px',
                        format: function (r) {
                            return '<button class="btn btn-xs btn-outline-primary" data-action="admin-npcs-edit" data-id="' + self.escapeAttr(String(r.id || '')) + '">'
                                + '<i class="bi bi-pencil"></i></button>';
                        }
                    }
                ]
            });
        },

        buildFiltersPayload: function () {
            var payload = {};
            var searchEl     = this.root.querySelector('#admin-npcs-search');
            var groupTypeEl  = this.root.querySelector('#admin-npcs-filter-group-type');
            var isActiveEl   = this.root.querySelector('#admin-npcs-filter-active');
            if (searchEl    && searchEl.value.trim())    { payload.search     = searchEl.value.trim(); }
            if (groupTypeEl && groupTypeEl.value)        { payload.group_type = groupTypeEl.value; }
            if (isActiveEl  && isActiveEl.value !== '')  { payload.is_active  = isActiveEl.value; }
            return payload;
        },

        loadGrid: function () {
            if (!this.grid || typeof this.grid.loadData !== 'function') { return; }
            this.grid.loadData(this.buildFiltersPayload(), 25, 1, 'name|ASC');
        },

        setRows: function (rows) {
            this.rows = rows;
            this.rowsById = {};
            for (var i = 0; i < rows.length; i++) {
                this.rowsById[String(rows[i].id)] = rows[i];
            }
        },

        // ── Events ────────────────────────────────────────────────────────────

        // ── Entità guidate ────────────────────────────────────────────────────

        loadGuilds: function () {
            var self = this;
            this.post('/admin/guilds/admin-list', { page: 1, results: 500, orderBy: 'name|ASC', query: {} }, function (res) {
                self.guilds = (res && Array.isArray(res.dataset)) ? res.dataset : [];
            });
        },

        loadFactions: function () {
            var self = this;
            this.post('/admin/factions/list', { page: 1, results: 500, orderBy: 'name|ASC', query: {} }, function (res) {
                self.factions = (res && Array.isArray(res.dataset)) ? res.dataset : [];
            });
        },

        refreshGroupIdSelect: function (groupType, currentId) {
            var sel = document.getElementById('admin-npcs-group-id');
            if (!sel) { return; }

            sel.innerHTML = '';
            currentId = parseInt(currentId, 10) || 0;

            if (!groupType || groupType === 'none') {
                var opt = document.createElement('option');
                opt.value = '0';
                opt.textContent = '— nessun gruppo —';
                sel.appendChild(opt);
                sel.disabled = true;
                return;
            }

            var list = groupType === 'guild' ? this.guilds : this.factions;
            var emptyOpt = document.createElement('option');
            emptyOpt.value = '0';
            emptyOpt.textContent = groupType === 'guild' ? '— seleziona gilda —' : '— seleziona fazione —';
            sel.appendChild(emptyOpt);

            for (var i = 0; i < list.length; i++) {
                var item = list[i];
                var o = document.createElement('option');
                o.value = String(item.id);
                o.textContent = item.name || String(item.id);
                sel.appendChild(o);
            }

            sel.disabled = false;
            if (currentId) { sel.value = String(currentId); }
        },

        // ── Events ────────────────────────────────────────────────────────────

        bind: function () {
            var self = this;

            this.filtersForm.addEventListener('submit', function (e) {
                e.preventDefault();
                self.loadGrid();
            });

            var groupTypeEl = document.getElementById('admin-npcs-group-type');
            if (groupTypeEl) {
                groupTypeEl.addEventListener('change', function () {
                    self.refreshGroupIdSelect(groupTypeEl.value, 0);
                });
            }

            this.root.addEventListener('click', function (e) {
                var trigger = e.target && e.target.closest ? e.target.closest('[data-action]') : null;
                if (!trigger) { return; }
                var action = String(trigger.getAttribute('data-action') || '');
                switch (action) {
                    case 'admin-npcs-create':
                        e.preventDefault();
                        self.openCreate();
                        break;
                    case 'admin-npcs-reload':
                        e.preventDefault();
                        self.loadGrid();
                        break;
                    case 'admin-npcs-edit':
                        e.preventDefault();
                        self.openEdit(String(trigger.getAttribute('data-id') || ''));
                        break;
                    case 'admin-npcs-save':
                        e.preventDefault();
                        self.save();
                        break;
                    case 'admin-npcs-delete':
                        e.preventDefault();
                        self.remove();
                        break;
                }
            });
        },

        // ── Modal ─────────────────────────────────────────────────────────────

        openCreate: function () {
            this.editingRow = null;
            this.resetForm();
            this.setModalTitle('Nuovo PNG');
            this.toggleDeleteButton(false);
            this.hideModalAlert();
            this.modal.show();
        },

        openEdit: function (idStr) {
            var row = this.rowsById[idStr];
            if (!row) { return; }
            this.editingRow = row;
            this.resetForm();
            this.setModalTitle('Modifica PNG');

            this.setField('id',          row.id || '');
            this.setField('name',        row.name || '');
            this.setField('description', row.description || '');
            this.setField('image',       row.image || '');
            this.setField('group_type',  row.group_type || 'none');
            this.setField('is_active',   row.is_active != null ? String(row.is_active) : '1');

            this.refreshGroupIdSelect(row.group_type || 'none', row.group_id || 0);

            var previewEl = this.modalNode ? this.modalNode.querySelector('[data-img-preview]') : null;
            if (previewEl) {
                if (row.image) {
                    previewEl.src = row.image;
                    previewEl.style.display = '';
                } else {
                    previewEl.src = '';
                    previewEl.style.display = 'none';
                }
            }

            this.toggleDeleteButton(true);
            this.hideModalAlert();
            this.modal.show();
        },

        resetForm: function () {
            if (!this.modalForm) { return; }
            this.modalForm.reset();
            this.setField('id', '');
            this.setField('is_active', '1');
            this.refreshGroupIdSelect('none', 0);

            var previewEl = this.modalNode ? this.modalNode.querySelector('[data-img-preview]') : null;
            if (previewEl) { previewEl.src = ''; previewEl.style.display = 'none'; }
        },

        setModalTitle: function (title) {
            var el = document.getElementById('admin-npcs-modal-title');
            if (el) { el.textContent = title; }
        },

        toggleDeleteButton: function (show) {
            var btn = this.modalNode ? this.modalNode.querySelector('[data-action="admin-npcs-delete"]') : null;
            if (btn) { btn.classList.toggle('d-none', !show); }
        },

        setField: function (name, value) {
            if (!this.modalForm) { return; }
            var el = this.modalForm.elements[name];
            if (el) { el.value = String(value); }
        },

        getField: function (name) {
            if (!this.modalForm) { return ''; }
            var el = this.modalForm.elements[name];
            return el ? String(el.value || '') : '';
        },

        // ── Save / Delete ─────────────────────────────────────────────────────

        save: function () {
            var self = this;
            var isEdit = this.editingRow !== null;
            var groupType = this.getField('group_type');
            var groupIdSel = document.getElementById('admin-npcs-group-id');
            var groupId = groupIdSel ? (parseInt(groupIdSel.value, 10) || 0) : 0;

            var payload = {
                id:          parseInt(this.getField('id'), 10) || 0,
                name:        this.getField('name').trim(),
                description: this.getField('description').trim(),
                image:       this.getField('image').trim(),
                group_type:  groupType,
                group_id:    groupType === 'none' ? 0 : groupId,
                is_active:   parseInt(this.getField('is_active'), 10) || 0
            };

            var url = isEdit
                ? '/admin/narrative-npcs/update'
                : '/admin/narrative-npcs/create';

            this.post(url, payload, function () {
                self.modal.hide();
                self.loadGrid();
                if (typeof Toast !== 'undefined') { Toast.show({ body: isEdit ? 'PNG aggiornato.' : 'PNG creato.', type: 'success' }); }
            }, function (err) {
                self.showModalAlert('danger', self.err(err));
            });
        },

        remove: function () {
            if (!this.editingRow) { return; }
            var self = this;
            var id   = parseInt(this.getField('id'), 10) || 0;
            if (!id) { return; }

            if (!window.confirm('Eliminare questo PNG?')) { return; }

            this.post('/admin/narrative-npcs/delete', { id: id }, function () {
                self.modal.hide();
                self.loadGrid();
                if (typeof Toast !== 'undefined') { Toast.show({ body: 'PNG eliminato.', type: 'success' }); }
            }, function (err) {
                self.showModalAlert('danger', self.err(err));
            });
        },

        // ── Alerts ────────────────────────────────────────────────────────────

        showModalAlert: function (type, msg) {
            if (!this.modalAlert) { return; }
            this.modalAlert.className = 'alert alert-' + type + ' mb-3';
            this.modalAlert.textContent = msg;
        },

        hideModalAlert: function () {
            if (!this.modalAlert) { return; }
            this.modalAlert.className = 'alert d-none mb-3';
            this.modalAlert.textContent = '';
        },

        // ── Helpers ───────────────────────────────────────────────────────────

        escapeHtml: function (s) {
            return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        },

        escapeAttr: function (s) {
            return String(s || '').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        },

        post: function (url, payload, ok, fail) {
            var self = this;
            if (typeof window.Request !== 'undefined' && window.Request && window.Request.http
                && typeof window.Request.http.post === 'function') {
                window.Request.http.post(url, payload || {})
                    .then(function (r) { if (typeof ok === 'function') ok(r || {}); })
                    .catch(function (e) {
                        if (typeof fail === 'function') { fail(e); }
                        else if (typeof Toast !== 'undefined') { Toast.show({ body: self.err(e), type: 'error' }); }
                    });
                return;
            }
            if (typeof Toast !== 'undefined') { Toast.show({ body: 'Nessun client HTTP disponibile.', type: 'error' }); }
        },

        err: function (e) {
            if (typeof window.Request !== 'undefined' && window.Request
                && typeof window.Request.getErrorMessage === 'function') {
                var m = String(window.Request.getErrorMessage(e, '') || '').trim();
                if (m) { return m; }
            }
            return (e && e.message) ? String(e.message) : 'Errore sconosciuto';
        }
    };

    window.AdminNarrativeNpcs = AdminNarrativeNpcs;
})();
