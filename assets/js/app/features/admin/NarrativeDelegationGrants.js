(function () {
    'use strict';

    var GRANTEE_REF_HINTS = {
        guild_role:   ['leader', 'officer'],
        faction_role: ['leader', 'advisor', 'agent', 'initiate'],
        user_role:    []
    };

    var GRANTEE_TYPE_LABELS = {
        guild_role:   'Gilda',
        faction_role: 'Fazione',
        user_role:    'Utente'
    };

    var IMPACT_LABELS = ['Zero', 'Limitato', 'Alto'];
    var IMPACT_BADGES = ['bg-success', 'bg-warning text-dark', 'bg-danger'];

    var AdminNarrativeDelegationGrants = {
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
        capabilities: [],

        init: function () {
            if (this.initialized) { return this; }

            this.root = document.querySelector('#admin-page [data-admin-page="narrative-delegation-grants"]');
            if (!this.root) { return this; }

            this.filtersForm = this.root.querySelector('#admin-ndg-filters');
            this.modalNode   = document.getElementById('admin-ndg-modal');
            this.modalForm   = document.getElementById('admin-ndg-form');
            this.modalAlert  = document.getElementById('admin-ndg-modal-alert');

            if (!this.filtersForm || !this.modalNode || !this.modalForm) { return this; }

            this.modal = new bootstrap.Modal(this.modalNode);
            this.loadCapabilities();
            this.initGrid();
            this.loadGrid();
            this.bind();

            this.initialized = true;
            return this;
        },

        // ── Capabilities ──────────────────────────────────────────────────────

        loadCapabilities: function () {
            var self = this;
            this.post('/admin/narrative-delegation/capabilities/list', {}, function (res) {
                self.capabilities = (res && Array.isArray(res.dataset)) ? res.dataset : [];
                self.populateCapabilitySelects();
            });
        },

        populateCapabilitySelects: function () {
            var caps = this.capabilities;

            // filter dropdown
            var filterSel = document.getElementById('admin-ndg-filter-cap');
            if (filterSel) {
                var firstOpt = filterSel.options[0];
                filterSel.innerHTML = '';
                filterSel.appendChild(firstOpt);
                caps.forEach(function (c) {
                    var opt = document.createElement('option');
                    opt.value = c.name || '';
                    opt.textContent = (c.label || c.name || '') + (c.staff_only == 1 ? ' (staff only)' : '');
                    filterSel.appendChild(opt);
                });
            }

            // modal select
            var modalSel = document.getElementById('admin-ndg-capability');
            if (modalSel) {
                modalSel.innerHTML = '';
                caps.forEach(function (c) {
                    var opt = document.createElement('option');
                    opt.value = c.name || '';
                    opt.textContent = (c.label || c.name || '') + (c.staff_only == 1 ? ' (staff only)' : '');
                    modalSel.appendChild(opt);
                });
            }
        },

        // ── Grid ──────────────────────────────────────────────────────────────

        initGrid: function () {
            var self = this;

            this.grid = new Datagrid('grid-admin-ndg', {
                name: 'AdminNDGrants',
                autoindex: 'id',
                orderable: true,
                thead: true,
                handler: { url: '/admin/narrative-delegation/grants/list', action: 'list' },
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
                        label: 'Tipo', field: 'grantee_type', sortable: true, width: '130px',
                        format: function (r) {
                            var label = GRANTEE_TYPE_LABELS[r.grantee_type] || self.escapeHtml(String(r.grantee_type || ''));
                            return '<span class="badge bg-secondary">' + self.escapeHtml(label) + '</span>';
                        }
                    },
                    {
                        label: 'Ruolo', field: 'grantee_ref', sortable: true,
                        format: function (r) {
                            return '<code class="small">' + self.escapeHtml(String(r.grantee_ref || '')) + '</code>';
                        }
                    },
                    {
                        label: 'Capability', field: 'capability', sortable: true,
                        format: function (r) {
                            return '<span class="fw-semibold">' + self.escapeHtml(String(r.capability_label || r.capability || '')) + '</span>'
                                + '<br><code class="text-muted small">' + self.escapeHtml(String(r.capability || '')) + '</code>';
                        }
                    },
                    {
                        label: 'Livello', field: 'max_impact_level', sortable: true, width: '120px',
                        format: function (r) {
                            var lvl = parseInt(r.max_impact_level, 10) || 0;
                            return '<span class="badge ' + (IMPACT_BADGES[lvl] || 'bg-secondary') + '">'
                                + lvl + ' — ' + (IMPACT_LABELS[lvl] || '?') + '</span>';
                        }
                    },
                    {
                        label: 'Scope', field: 'scope_restriction', sortable: false, width: '100px',
                        format: function (r) {
                            return r.scope_restriction
                                ? '<code class="small">' + self.escapeHtml(String(r.scope_restriction)) + '</code>'
                                : '<span class="text-muted small">—</span>';
                        }
                    },
                    {
                        label: '', field: 'id', sortable: false, width: '50px',
                        format: function (r) {
                            return '<button class="btn btn-xs btn-outline-primary" data-action="admin-ndg-edit" data-id="' + self.escapeAttr(String(r.id || '')) + '">'
                                + '<i class="bi bi-pencil"></i></button>';
                        }
                    }
                ]
            });
        },

        buildFiltersPayload: function () {
            var payload = {};
            var typeEl = this.root.querySelector('#admin-ndg-filter-type');
            var capEl  = this.root.querySelector('#admin-ndg-filter-cap');
            if (typeEl && typeEl.value) { payload.grantee_type = typeEl.value; }
            if (capEl  && capEl.value)  { payload.capability   = capEl.value;  }
            return payload;
        },

        loadGrid: function () {
            if (!this.grid || typeof this.grid.loadData !== 'function') { return; }
            this.grid.loadData(this.buildFiltersPayload(), 25, 1, 'id|ASC');
        },

        setRows: function (rows) {
            this.rows = rows;
            this.rowsById = {};
            for (var i = 0; i < rows.length; i++) {
                this.rowsById[String(rows[i].id)] = rows[i];
            }
        },

        // ── Events ────────────────────────────────────────────────────────────

        bind: function () {
            var self = this;

            this.filtersForm.addEventListener('submit', function (e) {
                e.preventDefault();
                self.loadGrid();
            });

            this.root.addEventListener('click', function (e) {
                var trigger = e.target && e.target.closest ? e.target.closest('[data-action]') : null;
                if (!trigger) { return; }
                var action = String(trigger.getAttribute('data-action') || '');
                switch (action) {
                    case 'admin-ndg-create':
                        e.preventDefault();
                        self.openCreate();
                        break;
                    case 'admin-ndg-reload':
                        e.preventDefault();
                        self.loadGrid();
                        break;
                    case 'admin-ndg-edit':
                        e.preventDefault();
                        self.openEdit(String(trigger.getAttribute('data-id') || ''));
                        break;
                    case 'admin-ndg-save':
                        e.preventDefault();
                        self.save();
                        break;
                    case 'admin-ndg-delete':
                        e.preventDefault();
                        self.remove();
                        break;
                }
            });

            // update datalist hints when grantee_type changes
            var typeSelect = document.getElementById('admin-ndg-grantee-type');
            if (typeSelect) {
                typeSelect.addEventListener('change', function () {
                    self.refreshGranteeRefHints(typeSelect.value);
                });
            }
        },

        refreshGranteeRefHints: function (granteeType) {
            var hints = GRANTEE_REF_HINTS[granteeType] || [];
            var dl = document.getElementById('admin-ndg-grantee-ref-hints');
            if (!dl) { return; }
            dl.innerHTML = '';
            hints.forEach(function (h) {
                var opt = document.createElement('option');
                opt.value = h;
                dl.appendChild(opt);
            });
        },

        // ── Modal ─────────────────────────────────────────────────────────────

        openCreate: function () {
            this.editingRow = null;
            this.resetForm();
            this.setModalTitle('Nuovo permesso narrativo');
            this.toggleDeleteButton(false);
            this.toggleGranteeFields(true);
            this.hideModalAlert();
            this.modal.show();
        },

        openEdit: function (idStr) {
            var row = this.rowsById[idStr];
            if (!row) { return; }
            this.editingRow = row;
            this.resetForm();
            this.setModalTitle('Modifica permesso narrativo');

            this.setField('id',               row.id || '');
            this.setField('grantee_type',      row.grantee_type || 'guild_role');
            this.setField('grantee_ref',       row.grantee_ref || '');
            this.setField('capability',        row.capability || '');
            this.setField('max_impact_level',  String(row.max_impact_level !== undefined ? row.max_impact_level : 0));
            this.setField('scope_restriction', row.scope_restriction || '');

            this.refreshGranteeRefHints(row.grantee_type || 'guild_role');
            this.toggleDeleteButton(true);
            this.toggleGranteeFields(false); // lock type+ref+capability on edit
            this.hideModalAlert();
            this.modal.show();
        },

        resetForm: function () {
            if (!this.modalForm) { return; }
            this.modalForm.reset();
            this.setField('id', '');
        },

        setModalTitle: function (title) {
            var el = document.getElementById('admin-ndg-modal-title');
            if (el) { el.textContent = title; }
        },

        toggleDeleteButton: function (show) {
            var btn = this.modalNode ? this.modalNode.querySelector('[data-action="admin-ndg-delete"]') : null;
            if (btn) { btn.classList.toggle('d-none', !show); }
        },

        toggleGranteeFields: function (editable) {
            ['admin-ndg-grantee-type', 'admin-ndg-grantee-ref', 'admin-ndg-capability'].forEach(function (id) {
                var el = document.getElementById(id);
                if (el) { el.disabled = !editable; }
            });
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
            var payload = {
                data: {
                    id:               parseInt(this.getField('id'), 10) || 0,
                    grantee_type:     this.getField('grantee_type'),
                    grantee_ref:      this.getField('grantee_ref').trim(),
                    capability:       this.getField('capability'),
                    max_impact_level: parseInt(this.getField('max_impact_level'), 10) || 0,
                    scope_restriction: this.getField('scope_restriction').trim()
                }
            };

            var url = isEdit
                ? '/admin/narrative-delegation/grants/update'
                : '/admin/narrative-delegation/grants/create';

            this.post(url, payload, function () {
                self.modal.hide();
                self.loadGrid();
                if (typeof Toast !== 'undefined') { Toast.show({ body: isEdit ? 'Permesso aggiornato.' : 'Permesso creato.', type: 'success' }); }
            }, function (err) {
                self.showModalAlert('danger', self.err(err));
            });
        },

        remove: function () {
            if (!this.editingRow) { return; }
            var self = this;
            var id   = parseInt(this.getField('id'), 10) || 0;
            if (!id) { return; }

            if (!window.confirm('Eliminare questo permesso narrativo?')) { return; }

            this.post('/admin/narrative-delegation/grants/delete', { data: { id: id } }, function () {
                self.modal.hide();
                self.loadGrid();
                if (typeof Toast !== 'undefined') { Toast.show({ body: 'Permesso eliminato.', type: 'success' }); }
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

    window.AdminNarrativeDelegationGrants = AdminNarrativeDelegationGrants;
})();
