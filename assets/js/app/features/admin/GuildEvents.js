const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

var AdminGuildEvents = {
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
    editingRow: null,

    init: function () {
        if (this.initialized) {
            return this;
        }

        this.root = document.querySelector('#admin-page [data-admin-page="guilds-events"]');
        if (!this.root) {
            return this;
        }

        this.filtersForm = this.root.querySelector('#admin-guild-events-filters');
        this.modalNode   = this.root.querySelector('#admin-guild-events-modal');
        this.modalForm   = this.root.querySelector('#admin-guild-events-form');

        if (!this.filtersForm || !this.modalNode || !this.modalForm) {
            return this;
        }

        this.modal = new bootstrap.Modal(this.modalNode);
        this.bind();
        this.initGrid();
        this.loadGuilds(function () {
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

        this.root.addEventListener('click', function (event) {
            var trigger = event.target.closest('[data-action]');
            if (!trigger) { return; }
            var action = String(trigger.getAttribute('data-action') || '').trim();

            if (action === 'admin-guild-events-reload') {
                event.preventDefault();
                self.loadGrid();
            } else if (action === 'admin-guild-events-create') {
                event.preventDefault();
                self.openCreate();
            } else if (action === 'admin-guild-events-edit') {
                event.preventDefault();
                var id = parseInt(trigger.getAttribute('data-id') || '0', 10);
                self.openEdit(id);
            } else if (action === 'admin-guild-events-save') {
                event.preventDefault();
                self.save();
            } else if (action === 'admin-guild-events-delete') {
                event.preventDefault();
                self.remove();
            }
        });
    },

    initGrid: function () {
        var self = this;

        this.grid = new Datagrid('grid-admin-guild-events', {
            name: 'AdminGuildEvents',
            autoindex: 'id',
            orderable: true,
            thead: true,
            handler: { url: '/admin/guilds/events-list', action: 'list' },
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
                    style: { textAlign: 'left', width: '160px' },
                    format: function (row) {
                        return self.escapeHtml(row.guild_name || '-');
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
                    label: 'Inizio',
                    field: 'starts_at',
                    sortable: true,
                    style: { textAlign: 'center', width: '150px' },
                    format: function (row) {
                        return row.starts_at
                            ? '<span class="small">' + self.escapeHtml(self.formatDatetime(row.starts_at)) + '</span>'
                            : '<span class="text-muted">—</span>';
                    }
                },
                {
                    label: 'Fine',
                    field: 'ends_at',
                    sortable: false,
                    style: { textAlign: 'center', width: '150px' },
                    format: function (row) {
                        return row.ends_at
                            ? '<span class="small">' + self.escapeHtml(self.formatDatetime(row.ends_at)) + '</span>'
                            : '<span class="text-muted">—</span>';
                    }
                },
                {
                    label: 'Creato da',
                    field: 'creator_name',
                    sortable: false,
                    style: { textAlign: 'left', width: '130px' },
                    format: function (row) {
                        return row.creator_name
                            ? '<span class="small text-muted">' + self.escapeHtml(row.creator_name) + '</span>'
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
                            + ' data-action="admin-guild-events-edit" data-id="' + id + '">'
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
        this.grid.loadData(this.buildFiltersPayload(), 30, 1, 'starts_at|DESC');
        return this;
    },

    buildFiltersPayload: function () {
        var q = {};
        if (this.filtersForm) {
            var guildId = (this.filtersForm.querySelector('[name="guild_id"]') || {}).value || '';
            var title   = (this.filtersForm.querySelector('[name="title"]') || {}).value || '';
            if (guildId) { q.guild_id = guildId; }
            if (title)   { q.title    = title; }
        }
        return q;
    },

    // ── Guilds ────────────────────────────────────────────────────────────

    loadGuilds: function (cb) {
        var self = this;
        this.post('/admin/guilds/admin-list', { page: 1, results: 200, orderBy: 'name|ASC', query: {} }, function (res) {
            self.guilds = (res && Array.isArray(res.dataset)) ? res.dataset : [];
            self.fillGuildSelects();
            if (typeof cb === 'function') { cb(); }
        }, function () {
            if (typeof cb === 'function') { cb(); }
        });
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

    // ── Modal ─────────────────────────────────────────────────────────────

    openCreate: function () {
        this.editingRow = null;
        if (this.modalForm) { this.modalForm.reset(); }
        this.setField('id', '');
        this.fillGuildSelects();
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
        this.setField('title', row.title || '');
        this.setField('body_html', row.body_html || '');
        this.setField('starts_at', this.toDatetimeLocal(row.starts_at));
        this.setField('ends_at', row.ends_at ? this.toDatetimeLocal(row.ends_at) : '');
        this.toggleDelete(true);
        this.modal.show();
    },

    toggleDelete: function (show) {
        if (!this.modalNode) { return; }
        var btn = this.modalNode.querySelector('[data-action="admin-guild-events-delete"]');
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
        var startsAt = this.getField('starts_at').replace('T', ' ');
        var endsAt   = this.getField('ends_at').trim();
        return {
            id:       parseInt(this.getField('id'), 10) || 0,
            guild_id: parseInt(this.getField('guild_id'), 10) || 0,
            title:    this.getField('title').trim(),
            body_html: this.getField('body_html').trim(),
            starts_at: startsAt,
            ends_at:   endsAt ? endsAt.replace('T', ' ') : null
        };
    },

    save: function () {
        var payload = this.collectPayload();
        if (!payload.guild_id) {
            if (typeof Toast !== 'undefined') { Toast.show({ body: 'Seleziona una gilda.', type: 'error' }); }
            return;
        }
        if (!payload.title) {
            if (typeof Toast !== 'undefined') { Toast.show({ body: 'Il titolo è obbligatorio.', type: 'error' }); }
            return;
        }
        if (!payload.starts_at) {
            if (typeof Toast !== 'undefined') { Toast.show({ body: 'La data di inizio è obbligatoria.', type: 'error' }); }
            return;
        }

        var isNew = !payload.id;
        var url   = isNew ? '/admin/guilds/events-create' : '/admin/guilds/events-update';
        var self  = this;

        this.post(url, payload, function () {
            self.modal.hide();
            if (typeof Toast !== 'undefined') {
                Toast.show({ body: isNew ? 'Evento creato.' : 'Evento aggiornato.', type: 'success' });
            }
            self.loadGrid();
        });
    },

    remove: function () {
        var payload = this.collectPayload();
        if (!payload.id) { return; }
        if (!confirm('Eliminare questo evento? L\'operazione non può essere annullata.')) { return; }

        var self = this;
        this.post('/admin/guilds/events-delete', { id: payload.id }, function () {
            self.modal.hide();
            if (typeof Toast !== 'undefined') { Toast.show({ body: 'Evento eliminato.', type: 'success' }); }
            self.loadGrid();
        });
    },

    // ── Helpers ───────────────────────────────────────────────────────────

    toDatetimeLocal: function (dbDatetime) {
        if (!dbDatetime) { return ''; }
        // "2026-03-20 18:00:00" → "2026-03-20T18:00"
        return String(dbDatetime).replace(' ', 'T').substring(0, 16);
    },

    formatDatetime: function (dbDatetime) {
        if (!dbDatetime) { return ''; }
        var s = String(dbDatetime);
        // "2026-03-20 18:00:00" → "20/03/2026 18:00"
        var parts = s.split(' ');
        if (parts.length < 2) { return s; }
        var d = parts[0].split('-');
        if (d.length < 3) { return s; }
        return d[2] + '/' + d[1] + '/' + d[0] + ' ' + parts[1].substring(0, 5);
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

globalWindow.AdminGuildEvents = AdminGuildEvents;
export { AdminGuildEvents as AdminGuildEvents };
export default AdminGuildEvents;

