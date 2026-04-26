const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

var REASON_LABELS = {
    'offensive_language':    'Linguaggio offensivo',
    'harassment':            'Molestia / intimidazione',
    'spam_flood':            'Spam o flood',
    'inappropriate_content': 'Contenuto inappropriato',
    'rule_violation':        'Violazione regolamento',
    'other':                 'Altro'
};

var STATUS_LABELS = {
    'open':      '<span class="badge bg-danger">Aperta</span>',
    'in_review': '<span class="badge bg-warning text-dark">In revisione</span>',
    'resolved':  '<span class="badge bg-success">Risolta</span>',
    'dismissed': '<span class="badge bg-secondary">Non fondata</span>',
    'archived':  '<span class="badge bg-dark">Archiviata</span>'
};

var PRIORITY_LABELS = {
    'low':    '<span class="badge bg-light text-dark border">Bassa</span>',
    'medium': '<span class="badge bg-warning text-dark">Media</span>',
    'high':   '<span class="badge bg-danger">Alta</span>'
};

var STATUS_TEXT = {
    'open':      'Aperta',
    'in_review': 'In revisione',
    'resolved':  'Risolta',
    'dismissed': 'Non fondata',
    'archived':  'Archiviata'
};

var CLOSED_STATUSES = ['resolved', 'dismissed', 'archived'];

var AdminMessageReports = {
    initialized: false,
    root: null,
    filtersForm: null,
    grid: null,
    modalNode: null,
    modal: null,
    currentReportId: null,
    rows: [],
    rowsById: {},

    init: function () {
        if (this.initialized) { return this; }

        this.root = document.querySelector('#admin-page [data-admin-page="message-reports"]');
        if (!this.root) { return this; }

        this.filtersForm = this.root.querySelector('#admin-message-reports-filters');
        this.modalNode   = this.root.querySelector('#admin-message-reports-modal');

        if (!this.filtersForm || !this.modalNode) { return this; }

        this.modal = new bootstrap.Modal(this.modalNode);
        this.bind();
        this.initGrid();
        this.loadGrid();

        this.initialized = true;
        return this;
    },

    bind: function () {
        var self = this;

        this.filtersForm.addEventListener('submit', function (e) {
            e.preventDefault();
            self.loadGrid();
        });

        this.root.addEventListener('click', function (e) {
            var trigger = e.target && e.target.closest ? e.target.closest('[data-action]') : null;
            if (!trigger) { return; }
            var action = String(trigger.getAttribute('data-action') || '').trim();

            switch (action) {
                case 'admin-message-reports-reload':
                    e.preventDefault();
                    self.loadGrid();
                    break;
                case 'admin-mr-open':
                    e.preventDefault();
                    self.openDetail(parseInt(trigger.getAttribute('data-id') || '0', 10));
                    break;
                case 'admin-mr-take-charge':
                    e.preventDefault();
                    self.takeCharge();
                    break;
                case 'admin-mr-update-status':
                    e.preventDefault();
                    self.updateStatus();
                    break;
            }
        });
    },

    initGrid: function () {
        var self = this;

        this.grid = new Datagrid('grid-admin-message-reports', {
            name: 'AdminMessageReports',
            autoindex: 'id',
            orderable: true,
            thead: true,
            handler: { url: '/admin/message-reports/list', action: 'list' },
            nav: { display: 'bottom', urlupdate: 0, results: 25, page: 1 },
            onGetDataSuccess: function (response) {
                self.setRows(response && Array.isArray(response.dataset) ? response.dataset : []);
            },
            onGetDataError: function () { self.setRows([]); },
            columns: [
                {
                    label: 'ID', field: 'id', sortable: true, width: '55px',
                    format: function (row) {
                        return '<span class="text-muted small">#' + self.escapeHtml(String(row.id || '')) + '</span>';
                    }
                },
                {
                    label: 'Data', field: 'created_at', sortable: true,
                    format: function (row) {
                        return '<span class="small">' + self.escapeHtml((row.created_at || '').substr(0, 16)) + '</span>';
                    }
                },
                {
                    label: 'Stato', field: 'status', sortable: true,
                    format: function (row) {
                        return STATUS_LABELS[row.status] || '<span class="badge bg-secondary">' + self.escapeHtml(STATUS_TEXT[row.status] || row.status || '') + '</span>';
                    }
                },
                {
                    label: 'Priorità', field: 'priority', sortable: true,
                    format: function (row) {
                        return PRIORITY_LABELS[row.priority] || self.escapeHtml(row.priority || '');
                    }
                },
                {
                    label: 'Segnalatore', field: 'reporter_name', sortable: false,
                    format: function (row) {
                        var name = ((row.reporter_name || '') + ' ' + (row.reporter_surname || '')).trim();
                        return self.escapeHtml(name || '—');
                    }
                },
                {
                    label: 'Segnalato', field: 'reported_name', sortable: false,
                    format: function (row) {
                        var name = ((row.reported_name || '') + ' ' + (row.reported_surname || '')).trim();
                        return self.escapeHtml(name || '—');
                    }
                },
                {
                    label: 'Motivo', field: 'reason_code', sortable: false,
                    format: function (row) {
                        return self.escapeHtml(REASON_LABELS[row.reason_code] || row.reason_code || '—');
                    }
                },
                {
                    label: 'Luogo', field: 'location_name', sortable: false,
                    format: function (row) {
                        return self.escapeHtml(row.location_name || '—');
                    }
                },
                {
                    label: '', field: '_actions', sortable: false,
                    format: function (row) {
                        return '<button class="btn btn-xs btn-outline-primary" data-action="admin-mr-open" data-id="' + self.escapeAttr(String(row.id || '')) + '">'
                            + '<i class="bi bi-eye"></i></button>';
                    }
                }
            ]
        });
    },

    buildFiltersPayload: function () {
        var payload = {};
        var statusEl   = this.root.querySelector('#admin-message-reports-filter-status');
        var priorityEl = this.root.querySelector('#admin-message-reports-filter-priority');
        if (statusEl   && statusEl.value)   { payload.status   = statusEl.value; }
        if (priorityEl && priorityEl.value) { payload.priority = priorityEl.value; }
        return payload;
    },

    loadGrid: function () {
        if (!this.grid || typeof this.grid.loadData !== 'function') { return; }
        this.grid.loadData(this.buildFiltersPayload(), 25, 1, 'created_at|DESC');
    },

    setRows: function (rows) {
        this.rows = rows;
        this.rowsById = {};
        for (var i = 0; i < rows.length; i++) {
            if (rows[i] && rows[i].id != null) {
                this.rowsById[rows[i].id] = rows[i];
            }
        }
    },

    openDetail: function (id) {
        var self = this;
        if (!id || id <= 0) { return; }
        this.currentReportId = id;

        this.post('/admin/message-reports/get', { report_id: id }, function (response) {
            var report = response && response.dataset ? response.dataset : {};
            self.fillModal(report);
            self.modal.show();
        });
    },

    fillModal: function (report) {
        var self = this;

        var setField = function (field, value) {
            var el = self.modalNode.querySelector('[data-field="' + field + '"]');
            if (el) { el.innerHTML = value; }
        };

        var status   = String(report.status || '');
        var isClosed = CLOSED_STATUSES.indexOf(status) !== -1;

        // Campi informativi
        setField('id',             '#' + self.escapeHtml(String(report.id || '')));
        setField('status_badge',   STATUS_LABELS[status] || '<span class="badge bg-secondary">' + self.escapeHtml(STATUS_TEXT[status] || status) + '</span>');
        setField('priority_badge', PRIORITY_LABELS[report.priority] || self.escapeHtml(String(report.priority || '—')));
        setField('reason_label',   self.escapeHtml(REASON_LABELS[report.reason_code] || report.reason_code || '—'));
        setField('created_at',     self.escapeHtml((String(report.created_at || '')).substr(0, 16) || '—'));
        setField('location_name',  self.escapeHtml(String(report.location_name || '—')));

        // Persone
        var reporterName = ((report.reporter_name || '') + ' ' + (report.reporter_surname || '')).trim();
        var reportedName = ((report.reported_name || '') + ' ' + (report.reported_surname || '')).trim();
        setField('reporter_name',      self.escapeHtml(reporterName || '—'));
        setField('reported_name',      self.escapeHtml(reportedName || '—'));
        setField('assigned_username',  self.escapeHtml(String(report.assigned_username || '—')));
        setField('reviewed_username',  self.escapeHtml(String(report.reviewed_username || '—')));
        setField('reviewed_at',        self.escapeHtml((String(report.reviewed_at || '')).substr(0, 16) || '—'));

        // Snapshot tipo messaggio
        var snapshotMeta = {};
        if (report.message_snapshot_meta_json) {
            try { snapshotMeta = JSON.parse(report.message_snapshot_meta_json); } catch (e) {}
        }
        var msgType = String(snapshotMeta.type || '');
        var typeBadge = msgType === 'on'
            ? '<span class="badge text-bg-dark">ON — In gioco</span>'
            : (msgType === 'off'
                ? '<span class="badge text-bg-secondary">OFF — Fuori gioco</span>'
                : '<span class="badge text-bg-light text-dark border">—</span>');
        setField('snapshot_type_badge', typeBadge);
        setField('message_snapshot_text', self.escapeHtml(String(report.message_snapshot_text || '—')));

        // Descrizione segnalatore
        var reasonTextWrap = document.getElementById('admin-mr-reason-text-wrap');
        var reasonText = String(report.reason_text || '').trim();
        if (reasonTextWrap) { reasonTextWrap.style.display = reasonText ? '' : 'none'; }
        setField('reason_text', self.escapeHtml(reasonText || '—'));

        // Nota staff esistente
        var reviewNoteWrap = document.getElementById('admin-mr-review-note-wrap');
        var reviewNote = String(report.review_note || '').trim();
        if (reviewNoteWrap) { reviewNoteWrap.style.display = reviewNote ? '' : 'none'; }
        setField('review_note', self.escapeHtml(reviewNote || '—'));

        // Stato chiuso
        var closedNotice  = document.getElementById('admin-mr-closed-notice');
        var actionsWrap   = document.getElementById('admin-mr-actions-wrap');
        var takeChargeBtn = document.getElementById('admin-mr-take-charge-btn');
        var updateBtn     = document.getElementById('admin-mr-update-status-btn');

        if (closedNotice) { closedNotice.classList.toggle('d-none', !isClosed); }
        if (actionsWrap)  { actionsWrap.style.display = isClosed ? 'none' : ''; }
        if (takeChargeBtn){ takeChargeBtn.disabled = isClosed; }
        if (updateBtn)    { updateBtn.disabled = isClosed; }

        // Reset form azioni
        var statusSel = document.getElementById('admin-mr-new-status');
        if (statusSel) { statusSel.value = ''; }
        var noteEl = document.getElementById('admin-mr-review-note');
        if (noteEl) { noteEl.value = ''; }
        var resEl = document.getElementById('admin-mr-resolution-code');
        if (resEl) { resEl.value = ''; }
    },

    takeCharge: function () {
        var self = this;
        if (!this.currentReportId) { return; }

        var btn = document.getElementById('admin-mr-take-charge-btn');
        if (btn) { btn.disabled = true; }

        this.post('/admin/message-reports/assign', { report_id: this.currentReportId }, function (response) {
            var report = response && response.dataset ? response.dataset : {};
            self.fillModal(report);
            self.loadGrid();
            self.showToast('Segnalazione presa in carico.', 'success');
        }, function () {
            if (btn) { btn.disabled = false; }
        });
    },

    updateStatus: function () {
        var self = this;
        if (!this.currentReportId) { return; }

        var statusEl = document.getElementById('admin-mr-new-status');
        var status = statusEl ? statusEl.value : '';
        if (!status) {
            self.showToast('Seleziona uno stato prima di procedere.', 'warning');
            return;
        }

        var noteEl = document.getElementById('admin-mr-review-note');
        var resEl  = document.getElementById('admin-mr-resolution-code');
        var updateBtn = document.getElementById('admin-mr-update-status-btn');

        if (updateBtn) { updateBtn.disabled = true; }

        this.post('/admin/message-reports/update-status', {
            report_id:       this.currentReportId,
            status:          status,
            review_note:     noteEl ? noteEl.value.trim() : '',
            resolution_code: resEl  ? resEl.value.trim()  : ''
        }, function (response) {
            var report = response && response.dataset ? response.dataset : {};
            self.fillModal(report);
            self.loadGrid();
            self.showToast('Stato aggiornato correttamente.', 'success');
        }, function () {
            if (updateBtn) { updateBtn.disabled = false; }
        });
    },

    post: function (url, payload, onSuccess, onError) {
        var self = this;
        var http = globalWindow.Request && globalWindow.Request.http ? globalWindow.Request.http : null;
        if (http && typeof http.post === 'function') {
            http.post(url, payload)
                .then(function (r) { if (typeof onSuccess === 'function') { onSuccess(r); } })
                .catch(function (err) {
                    var msg = globalWindow.Request && typeof globalWindow.Request.getErrorMessage === 'function'
                        ? globalWindow.Request.getErrorMessage(err) : 'Errore nella richiesta.';
                    self.showToast(msg, 'error');
                    if (typeof onError === 'function') { onError(err); }
                });
            return;
        }
        if (typeof globalWindow.$ === 'function') {
            var meta = document.querySelector('meta[name="csrf-token"]');
            var csrfToken = meta ? (meta.getAttribute('content') || '') : '';
            globalWindow.$.ajax({
                url: url,
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrfToken },
                data: { data: JSON.stringify(payload) },
                success: function (r) { if (typeof onSuccess === 'function') { onSuccess(r); } },
                error: function () {
                    self.showToast('Errore nella richiesta.', 'error');
                    if (typeof onError === 'function') { onError(); }
                }
            });
        }
    },

    showToast: function (message, type) {
        if (globalWindow.Toast && typeof globalWindow.Toast.show === 'function') {
            globalWindow.Toast.show({ body: message, type: type || 'info' });
            return;
        }
        if (type === 'error' || type === 'warning') {
            alert(message);
        }
    },

    escapeHtml: function (str) {
        return String(str || '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    },

    escapeAttr: function (str) {
        return String(str || '').replace(/"/g, '&quot;');
    }
};

globalWindow.AdminMessageReports = AdminMessageReports;
export { AdminMessageReports as AdminMessageReports };
export default AdminMessageReports;

