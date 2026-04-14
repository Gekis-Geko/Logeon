(function () {
    'use strict';

    var TYPE_LABELS = {
        min_fame:            'Fama minima',
        min_socialstatus_id: 'Stato sociale',
        job_id:              'Lavoro richiesto',
        no_job:              'Senza lavoro'
    };

    var AdminGuildReqs = {
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
        socialStatuses: [],
        jobs: [],
        editingRow: null,
        pendingLoads: 0,

        init: function () {
            if (this.initialized) {
                return this;
            }

            this.root = document.querySelector('#admin-page [data-admin-page="guilds-reqs"]');
            if (!this.root) {
                return this;
            }

            this.filtersForm = this.root.querySelector('#admin-guild-reqs-filters');
            this.modalNode   = this.root.querySelector('#admin-guild-reqs-modal');
            this.modalForm   = this.root.querySelector('#admin-guild-reqs-form');

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

            var typeSelect = this.modalForm.querySelector('[name="type"]');
            if (typeSelect) {
                typeSelect.addEventListener('change', function () {
                    self.syncValueField(typeSelect.value);
                });
            }

            this.root.addEventListener('click', function (event) {
                var trigger = event.target.closest('[data-action]');
                if (!trigger) { return; }
                var action = String(trigger.getAttribute('data-action') || '').trim();

                if (action === 'admin-guild-reqs-reload') {
                    event.preventDefault();
                    self.loadGrid();
                } else if (action === 'admin-guild-reqs-create') {
                    event.preventDefault();
                    self.openCreate();
                } else if (action === 'admin-guild-reqs-edit') {
                    event.preventDefault();
                    var id = parseInt(trigger.getAttribute('data-id') || '0', 10);
                    self.openEdit(id);
                } else if (action === 'admin-guild-reqs-save') {
                    event.preventDefault();
                    self.save();
                } else if (action === 'admin-guild-reqs-delete') {
                    event.preventDefault();
                    self.remove();
                }
            });
        },

        initGrid: function () {
            var self = this;

            this.grid = new Datagrid('grid-admin-guild-reqs', {
                name: 'AdminGuildReqs',
                autoindex: 'id',
                orderable: true,
                thead: true,
                handler: { url: '/admin/guild-requirements/list', action: 'list' },
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
                        label: 'Tipo',
                        field: 'type',
                        sortable: true,
                        style: { textAlign: 'left', width: '160px' },
                        format: function (row) {
                            var label = TYPE_LABELS[row.type] || self.escapeHtml(row.type || '-');
                            return '<span class="badge text-bg-secondary">' + label + '</span>';
                        }
                    },
                    {
                        label: 'Valore',
                        field: 'value',
                        sortable: false,
                        style: { textAlign: 'left', width: '160px' },
                        format: function (row) {
                            if (row.type === 'no_job') {
                                return '<span class="text-muted">—</span>';
                            }
                            if (row.type === 'job_id' && row.job_name) {
                                return self.escapeHtml(row.job_name);
                            }
                            if (row.type === 'min_socialstatus_id' && row.socialstatus_name) {
                                return self.escapeHtml(row.socialstatus_name);
                            }
                            return self.escapeHtml(String(row.value || '-'));
                        }
                    },
                    {
                        label: 'Etichetta',
                        field: 'label',
                        sortable: false,
                        style: { textAlign: 'left' },
                        format: function (row) {
                            return row.label
                                ? '<span class="text-muted small">' + self.escapeHtml(row.label) + '</span>'
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
                                + ' data-action="admin-guild-reqs-edit" data-id="' + id + '">'
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
                var guildId = (this.filtersForm.querySelector('[name="guild_id"]') || {}).value || '';
                var type    = (this.filtersForm.querySelector('[name="type"]') || {}).value || '';
                if (guildId) { q.guild_id = guildId; }
                if (type)    { q.type = type; }
            }
            return q;
        },

        // ── Dependencies ──────────────────────────────────────────────────────

        loadDependencies: function () {
            var self = this;
            var done = 0;
            var total = 3;

            function onLoad() {
                done++;
                if (done >= total) {
                    self.fillGuildSelects();
                    self.fillSocialStatusSelect();
                    self.fillJobSelect();
                    self.loadGrid();
                }
            }

            this.post('/admin/guilds/admin-list', { page: 1, results: 200, orderBy: 'name|ASC', query: {} }, function (res) {
                self.guilds = (res && Array.isArray(res.dataset)) ? res.dataset : [];
                onLoad();
            }, function () { onLoad(); });

            this.post('/admin/social-status/list', {}, function (res) {
                self.socialStatuses = (res && Array.isArray(res.dataset)) ? res.dataset : [];
                onLoad();
            }, function () { onLoad(); });

            this.post('/admin/jobs/list', { page: 1, results: 200, orderBy: 'name|ASC', query: { is_active: 1 } }, function (res) {
                self.jobs = (res && Array.isArray(res.dataset)) ? res.dataset : [];
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

        fillSocialStatusSelect: function () {
            var sel = this.modalForm ? this.modalForm.querySelector('#admin-guild-reqs-value-socialstatus') : null;
            if (!sel) { return; }
            var cur = sel.value;
            while (sel.options.length > 1) { sel.remove(1); }
            for (var i = 0; i < this.socialStatuses.length; i++) {
                var ss = this.socialStatuses[i];
                var opt = document.createElement('option');
                opt.value = String(ss.id);
                opt.textContent = ss.name || String(ss.id);
                sel.appendChild(opt);
            }
            if (cur) { sel.value = cur; }
        },

        fillJobSelect: function () {
            var sel = this.modalForm ? this.modalForm.querySelector('#admin-guild-reqs-value-job') : null;
            if (!sel) { return; }
            var cur = sel.value;
            while (sel.options.length > 1) { sel.remove(1); }
            for (var i = 0; i < this.jobs.length; i++) {
                var j = this.jobs[i];
                var opt = document.createElement('option');
                opt.value = String(j.id);
                opt.textContent = j.name || String(j.id);
                sel.appendChild(opt);
            }
            if (cur) { sel.value = cur; }
        },

        // ── Value field sync ──────────────────────────────────────────────────

        syncValueField: function (type) {
            if (!this.modalForm) { return; }
            var wrap = this.modalForm.querySelector('#admin-guild-reqs-value-wrap');
            var numEl  = this.modalForm.querySelector('#admin-guild-reqs-value-number');
            var ssEl   = this.modalForm.querySelector('#admin-guild-reqs-value-socialstatus');
            var jobEl  = this.modalForm.querySelector('#admin-guild-reqs-value-job');
            var noEl   = this.modalForm.querySelector('#admin-guild-reqs-value-nojob');
            var label  = this.modalForm.querySelector('#admin-guild-reqs-value-label');

            if (wrap) { wrap.classList.toggle('d-none', type === ''); }

            this.toggleEl(numEl,  type === 'min_fame');
            this.toggleEl(ssEl,   type === 'min_socialstatus_id');
            this.toggleEl(jobEl,  type === 'job_id');
            this.toggleEl(noEl,   type === 'no_job');

            if (label) {
                var labels = {
                    min_fame: 'Fama minima richiesta',
                    min_socialstatus_id: 'Stato sociale minimo',
                    job_id: 'Lavoro richiesto',
                    no_job: ''
                };
                label.textContent = labels[type] || 'Valore';
            }
        },

        toggleEl: function (el, show) {
            if (!el) { return; }
            if (show) {
                el.classList.remove('d-none');
            } else {
                el.classList.add('d-none');
            }
        },

        // ── Modal ─────────────────────────────────────────────────────────────

        openCreate: function () {
            this.editingRow = null;
            if (this.modalForm) { this.modalForm.reset(); }
            this.setField('id', '');
            this.fillGuildSelects();
            this.fillSocialStatusSelect();
            this.fillJobSelect();
            this.syncValueField('');
            this.toggleDelete(false);
            this.modal.show();
        },

        openEdit: function (id) {
            var row = this.rowsById[id] || null;
            if (!row) { return; }
            this.editingRow = row;
            if (this.modalForm) { this.modalForm.reset(); }
            this.fillGuildSelects();
            this.fillSocialStatusSelect();
            this.fillJobSelect();
            this.setField('id', String(row.id));
            this.setField('guild_id', String(row.guild_id));
            this.setField('type', row.type || '');
            this.setField('label', row.label || '');
            this.syncValueField(row.type || '');
            this.setValueFromRow(row);
            this.toggleDelete(true);
            this.modal.show();
        },

        setValueFromRow: function (row) {
            if (!this.modalForm) { return; }
            if (row.type === 'min_fame') {
                var numEl = this.modalForm.querySelector('#admin-guild-reqs-value-number');
                if (numEl) { numEl.value = row.value || ''; }
            } else if (row.type === 'min_socialstatus_id') {
                var ssEl = this.modalForm.querySelector('#admin-guild-reqs-value-socialstatus');
                if (ssEl) { ssEl.value = String(row.value || ''); }
            } else if (row.type === 'job_id') {
                var jobEl = this.modalForm.querySelector('#admin-guild-reqs-value-job');
                if (jobEl) { jobEl.value = String(row.value || ''); }
            }
        },

        toggleDelete: function (show) {
            if (!this.modalNode) { return; }
            var btn = this.modalNode.querySelector('[data-action="admin-guild-reqs-delete"]');
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

        collectValue: function () {
            var type = this.getField('type');
            if (type === 'no_job')              { return '1'; }
            if (type === 'min_fame') {
                var numEl = this.modalForm ? this.modalForm.querySelector('#admin-guild-reqs-value-number') : null;
                return numEl ? numEl.value : '';
            }
            if (type === 'min_socialstatus_id') {
                var ssEl = this.modalForm ? this.modalForm.querySelector('#admin-guild-reqs-value-socialstatus') : null;
                return ssEl ? ssEl.value : '';
            }
            if (type === 'job_id') {
                var jobEl = this.modalForm ? this.modalForm.querySelector('#admin-guild-reqs-value-job') : null;
                return jobEl ? jobEl.value : '';
            }
            return '';
        },

        collectPayload: function () {
            return {
                id:       parseInt(this.getField('id'), 10) || 0,
                guild_id: parseInt(this.getField('guild_id'), 10) || 0,
                type:     this.getField('type').trim(),
                value:    this.collectValue(),
                label:    this.getField('label').trim() || null
            };
        },

        save: function () {
            var payload = this.collectPayload();
            if (!payload.guild_id) {
                if (typeof Toast !== 'undefined') { Toast.show({ body: 'Seleziona una gilda.', type: 'error' }); }
                return;
            }
            if (!payload.type) {
                if (typeof Toast !== 'undefined') { Toast.show({ body: 'Seleziona un tipo.', type: 'error' }); }
                return;
            }
            if (payload.type !== 'no_job' && !payload.value) {
                if (typeof Toast !== 'undefined') { Toast.show({ body: 'Inserisci un valore.', type: 'error' }); }
                return;
            }

            var isNew = !payload.id;
            var url   = isNew ? '/admin/guild-requirements/create' : '/admin/guild-requirements/update';
            var self  = this;

            this.post(url, payload, function () {
                self.modal.hide();
                if (typeof Toast !== 'undefined') {
                    Toast.show({ body: isNew ? 'Requisito creato.' : 'Requisito aggiornato.', type: 'success' });
                }
                self.loadGrid();
            });
        },

        remove: function () {
            var payload = this.collectPayload();
            if (!payload.id) { return; }
            if (!confirm('Eliminare questo requisito? L\'operazione non può essere annullata.')) { return; }

            var self = this;
            this.post('/admin/guild-requirements/delete', { id: payload.id }, function () {
                self.modal.hide();
                if (typeof Toast !== 'undefined') { Toast.show({ body: 'Requisito eliminato.', type: 'success' }); }
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

    window.AdminGuildReqs = AdminGuildReqs;
})();
