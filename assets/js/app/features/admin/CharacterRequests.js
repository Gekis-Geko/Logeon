(function () {
    'use strict';

    var AdminCharacterRequests = {
        initialized: false,
        root: null,
        nameGrid: null,
        loanfaceGrid: null,
        identityGrid: null,
        nameRowsById: {},
        loanfaceRowsById: {},
        identityRowsById: {},
        activeTab: 'name',
        statusFilter: 'pending',

        init: function () {
            if (this.initialized) { return this; }

            this.root = document.querySelector('#admin-page [data-admin-page="character-requests"]');
            if (!this.root) { return this; }
            if (!document.getElementById('grid-char-name-requests')) { return this; }

            this.bindEvents();
            this.initGrids();
            this.loadAll();

            this.initialized = true;
            return this;
        },

        bindEvents: function () {
            var self = this;
            this.root.addEventListener('click', function (event) {
                var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
                if (!trigger) { return; }
                var action = String(trigger.getAttribute('data-action') || '').trim();

                switch (action) {
                    case 'char-requests-reload':
                        event.preventDefault();
                        self.loadAll();
                        break;
                    case 'char-requests-tab':
                        event.preventDefault();
                        self.switchTab(trigger.getAttribute('data-tab') || 'name', trigger);
                        break;
                    case 'char-request-decide':
                        event.preventDefault();
                        self.openDecideModal(
                            trigger.getAttribute('data-type') || 'name',
                            parseInt(trigger.getAttribute('data-id') || '0', 10) || 0
                        );
                        break;
                    case 'char-request-decide-identity':
                        event.preventDefault();
                        self.openDecideModal(
                            'identity',
                            parseInt(trigger.getAttribute('data-id') || '0', 10) || 0
                        );
                        break;
                    case 'char-request-approve':
                        event.preventDefault();
                        self.decide('approved');
                        break;
                    case 'char-request-reject':
                        event.preventDefault();
                        self.decide('rejected');
                        break;
                }
            });

            var statusSel = document.getElementById('char-requests-status-filter');
            if (statusSel) {
                statusSel.addEventListener('change', function () {
                    self.statusFilter = statusSel.value || 'pending';
                    self.loadAll();
                });
            }
        },

        switchTab: function (tab, trigger) {
            this.activeTab = tab;

            var namePanel     = document.getElementById('char-requests-name-panel');
            var loanfacePanel = document.getElementById('char-requests-loanface-panel');
            var identityPanel = document.getElementById('char-requests-identity-panel');
            var tabs          = this.root.querySelectorAll('[data-action="char-requests-tab"]');

            tabs.forEach(function (t) { t.classList.remove('active'); });
            if (trigger) { trigger.classList.add('active'); }

            if (namePanel)     { namePanel.style.display     = tab === 'name'     ? '' : 'none'; }
            if (loanfacePanel) { loanfacePanel.style.display = tab === 'loanface' ? '' : 'none'; }
            if (identityPanel) { identityPanel.style.display = tab === 'identity' ? '' : 'none'; }
        },

        initGrids: function () {
            var self = this;

            var statusBadge = function (status) {
                var map = { pending: 'text-bg-warning', approved: 'text-bg-success', rejected: 'text-bg-danger' };
                return '<span class="badge ' + (map[status] || 'text-bg-secondary') + '">' + self.escapeHtml(status || '') + '</span>';
            };

            var commonColumns = function (type) {
                return [
                    { label: 'ID', field: 'id', sortable: true, style: { width: '60px' } },
                    { label: 'Personaggio', field: 'character_name', sortable: true },
                    {
                        label: 'Attuale',
                        sortable: false,
                        format: function (row) {
                            var val = type === 'name' ? (row.current_name || '') : (row.current_loanface || '');
                            return self.escapeHtml(val);
                        }
                    },
                    {
                        label: 'Richiesto',
                        sortable: false,
                        format: function (row) {
                            var val = type === 'name' ? (row.new_name || '') : (row.new_loanface || '');
                            return '<strong>' + self.escapeHtml(val) + '</strong>';
                        }
                    },
                    {
                        label: 'Stato', field: 'status', sortable: true,
                        format: function (row) { return statusBadge(row.status); }
                    },
                    { label: 'Data', field: 'date_created', sortable: true },
                    {
                        label: 'Azioni', sortable: false,
                        format: function (row) {
                            var id = parseInt(row.id, 10) || 0;
                            if (type === 'name')     { self.nameRowsById[id]     = row; }
                            if (type === 'loanface') { self.loanfaceRowsById[id] = row; }

                            if (row.status !== 'pending') { return '<span class="text-muted small">Elaborata</span>'; }
                            return '<button type="button" class="btn btn-sm btn-outline-primary" data-action="char-request-decide" data-type="' + type + '" data-id="' + id + '">Gestisci</button>';
                        }
                    }
                ];
            };

            this.nameGrid = new Datagrid('grid-char-name-requests', {
                name: 'AdminNameRequests',
                autoindex: 'id',
                orderable: true,
                thead: true,
                handler: { url: '/admin/characters/name-requests/list', action: 'listNameRequests' },
                nav: { display: 'bottom', urlupdate: 0, results: 20, page: 1 },
                columns: commonColumns('name')
            });

            this.loanfaceGrid = new Datagrid('grid-char-loanface-requests', {
                name: 'AdminLoanfaceRequests',
                autoindex: 'id',
                orderable: true,
                thead: true,
                handler: { url: '/admin/characters/loanface-requests/list', action: 'listLoanfaceRequests' },
                nav: { display: 'bottom', urlupdate: 0, results: 20, page: 1 },
                columns: commonColumns('loanface')
            });

            this.identityGrid = new Datagrid('grid-char-identity-requests', {
                name: 'AdminIdentityRequests',
                autoindex: 'id',
                orderable: true,
                thead: true,
                handler: { url: '/admin/characters/identity-requests/list', action: 'listIdentityRequests' },
                nav: { display: 'bottom', urlupdate: 0, results: 20, page: 1 },
                columns: [
                    { label: 'ID', field: 'id', sortable: true, style: { width: '60px' } },
                    { label: 'Personaggio', field: 'character_name', sortable: true },
                    {
                        label: 'Campi richiesti',
                        sortable: false,
                        format: function (row) {
                            var parts = [];
                            if (row.new_surname) { parts.push('Cognome: <b>' + self.escapeHtml(row.new_surname) + '</b>'); }
                            if (row.new_height)  { parts.push('Altezza: <b>' + self.escapeHtml(String(row.new_height)) + '</b>'); }
                            if (row.new_weight)  { parts.push('Peso: <b>' + self.escapeHtml(String(row.new_weight)) + '</b>'); }
                            if (row.new_eyes)    { parts.push('Occhi: <b>' + self.escapeHtml(row.new_eyes) + '</b>'); }
                            if (row.new_hair)    { parts.push('Capelli: <b>' + self.escapeHtml(row.new_hair) + '</b>'); }
                            if (row.new_skin)    { parts.push('Pelle: <b>' + self.escapeHtml(row.new_skin) + '</b>'); }
                            return parts.length ? parts.join('<br>') : '<span class="text-muted">—</span>';
                        }
                    },
                    {
                        label: 'Motivazione', field: 'reason', sortable: false,
                        format: function (row) { return self.escapeHtml(row.reason || ''); }
                    },
                    {
                        label: 'Stato', field: 'status', sortable: true,
                        format: function (row) { return statusBadge(row.status); }
                    },
                    { label: 'Data', field: 'date_created', sortable: true },
                    {
                        label: 'Azioni', sortable: false,
                        format: function (row) {
                            var id = parseInt(row.id, 10) || 0;
                            self.identityRowsById[id] = row;
                            if (row.status !== 'pending') { return '<span class="text-muted small">Elaborata</span>'; }
                            return '<button type="button" class="btn btn-sm btn-outline-primary" data-action="char-request-decide-identity" data-id="' + id + '">Gestisci</button>';
                        }
                    }
                ]
            });
        },

        loadAll: function () {
            this.nameRowsById = {};
            this.loanfaceRowsById = {};
            this.identityRowsById = {};
            var extra = { status: this.statusFilter };
            if (this.nameGrid)     { this.nameGrid.loadData(extra, 20, 1, 'date_created|ASC'); }
            if (this.loanfaceGrid) { this.loanfaceGrid.loadData(extra, 20, 1, 'date_created|ASC'); }
            if (this.identityGrid) { this.identityGrid.loadData(extra, 20, 1, 'date_created|ASC'); }
            this.updateBadges();
        },

        updateBadges: function () {
            var resolveTotal = function (response) {
                var total = 0;
                if (response && response.dataset && typeof response.dataset.total !== 'undefined') {
                    total = parseInt(response.dataset.total, 10) || 0;
                } else if (response && response.properties && response.properties.tot && typeof response.properties.tot.count !== 'undefined') {
                    total = parseInt(response.properties.tot.count, 10) || 0;
                }
                return total;
            };

            var self = this;
            this.requestPost('/admin/characters/name-requests/list', { status: 'pending', limit: 1, page: 1 }, function (r) {
                var badge = document.getElementById('char-requests-name-badge');
                if (badge) {
                    var n = resolveTotal(r);
                    badge.textContent = n > 0 ? String(n) : '';
                    badge.style.display = n > 0 ? '' : 'none';
                }
            });
            this.requestPost('/admin/characters/loanface-requests/list', { status: 'pending', limit: 1, page: 1 }, function (r) {
                var badge = document.getElementById('char-requests-loanface-badge');
                if (badge) {
                    var n = resolveTotal(r);
                    badge.textContent = n > 0 ? String(n) : '';
                    badge.style.display = n > 0 ? '' : 'none';
                }
            });
            this.requestPost('/admin/characters/identity-requests/list', { status: 'pending', limit: 1, page: 1 }, function (r) {
                var badge = document.getElementById('char-requests-identity-badge');
                if (badge) {
                    var n = resolveTotal(r);
                    badge.textContent = n > 0 ? String(n) : '';
                    badge.style.display = n > 0 ? '' : 'none';
                }
            });
        },

        openDecideModal: function (type, id) {
            var row = type === 'name' ? this.nameRowsById[id]
                    : type === 'loanface' ? this.loanfaceRowsById[id]
                    : this.identityRowsById[id];
            if (!row) { return; }

            document.getElementById('char-request-id').value   = String(id);
            document.getElementById('char-request-type').value = type;

            var titleMap = { name: 'Richiesta cambio nome', loanface: 'Richiesta cambio presta-volto', identity: 'Richiesta cambio identità fisica' };
            document.getElementById('char-request-modal-title').textContent = titleMap[type] || 'Gestisci richiesta';
            document.getElementById('char-request-character-name').textContent = row.character_name || ('#' + row.character_id);

            var currentEl = document.getElementById('char-request-current-value');
            var newEl     = document.getElementById('char-request-new-value');
            if (type === 'name') {
                currentEl.textContent = row.current_name || '';
                newEl.textContent     = row.new_name || '';
            } else if (type === 'identity') {
                var parts = [];
                if (row.new_surname) { parts.push('Cognome: ' + row.new_surname); }
                if (row.new_height)  { parts.push('Altezza: ' + row.new_height); }
                if (row.new_weight)  { parts.push('Peso: ' + row.new_weight); }
                if (row.new_eyes)    { parts.push('Occhi: ' + row.new_eyes); }
                if (row.new_hair)    { parts.push('Capelli: ' + row.new_hair); }
                if (row.new_skin)    { parts.push('Pelle: ' + row.new_skin); }
                currentEl.textContent = '';
                newEl.textContent     = parts.join(' | ') || '-';
            } else {
                currentEl.textContent = row.current_loanface || '';
                newEl.textContent     = row.new_loanface || '';
            }

            var reasonRow = document.getElementById('char-request-reason-row');
            var reasonEl  = document.getElementById('char-request-reason');
            if (row.reason && row.reason.trim()) {
                reasonRow.style.display = '';
                reasonEl.textContent    = row.reason;
            } else {
                reasonRow.style.display = 'none';
            }

            document.getElementById('char-request-date').textContent = row.date_created || '';

            this.showModal('char-request-decide-modal');
        },

        decide: function (decision) {
            var id   = parseInt(document.getElementById('char-request-id').value || '0', 10) || 0;
            var type = String(document.getElementById('char-request-type').value || '');
            if (!id || !type) { return; }

            var endpoint = type === 'name' ? '/admin/characters/name-request/decide'
                         : type === 'identity' ? '/admin/characters/identity-request/decide'
                         : '/admin/characters/loanface-request/decide';

            var label = (decision === 'approved') ? 'approvata' : 'rifiutata';
            var self  = this;

            this.hideModal('char-request-decide-modal');
            this.requestPost(endpoint, { request_id: id, decision: decision }, function () {
                Toast.show({ body: 'Richiesta ' + label + '.', type: 'success' });
                self.loadAll();
            });
        },

        requestPost: function (url, payload, onSuccess, onError) {
            var self = this;
            if (!window.Request || !Request.http || typeof Request.http.post !== 'function') {
                Toast.show({ body: 'Servizio non disponibile.', type: 'error' });
                return;
            }
            Request.http.post(url, payload || {}).then(function (r) {
                if (typeof onSuccess === 'function') { onSuccess(r || null); }
            }).catch(function (e) {
                if (typeof onError === 'function') { onError(e); return; }
                Toast.show({ body: self.requestErrorMessage(e), type: 'error' });
            });
        },

        requestErrorMessage: function (error) {
            if (window.Request && typeof window.Request.getErrorMessage === 'function') {
                return window.Request.getErrorMessage(error, 'Operazione non riuscita.');
            }
            if (error && typeof error.message === 'string' && error.message.trim()) { return error.message.trim(); }
            return 'Operazione non riuscita.';
        },

        showModal: function (id) {
            var n = document.getElementById(id);
            if (!n) { return; }
            if (window.bootstrap && window.bootstrap.Modal) { window.bootstrap.Modal.getOrCreateInstance(n).show(); return; }
            if (typeof $ === 'function') { $(n).modal('show'); }
        },

        hideModal: function (id) {
            var n = document.getElementById(id);
            if (!n) { return; }
            if (window.bootstrap && window.bootstrap.Modal) { window.bootstrap.Modal.getOrCreateInstance(n).hide(); return; }
            if (typeof $ === 'function') { $(n).modal('hide'); }
        },

        escapeHtml: function (value) {
            return String(value || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
        }
    };

    window.AdminCharacterRequests = AdminCharacterRequests;
})();
