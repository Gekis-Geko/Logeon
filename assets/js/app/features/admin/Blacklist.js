(function () {
    'use strict';

    var AdminBlacklist = {
        initialized: false,
        root: null,
        grid: null,
        modalNode: null,
        modal: null,
        form: null,
        filtersForm: null,
        suggestionsNode: null,
        rowsById: {},
        state: null,
        userSearchTimer: null,
        prefillUserId: 0,
        onlyActiveSwitch: null,

        defaults: {
            email: '',
            status: 'active',
            onlyActiveNow: 1,
            page: 1,
            results: 20,
            orderBy: 'date_start|DESC'
        },

        init: function () {
            if (this.initialized) {
                return this;
            }

            this.root = document.querySelector('#admin-page [data-admin-page="blacklist"]');
            if (!this.root) {
                return this;
            }

            this.filtersForm = document.getElementById('admin-blacklist-filters');
            this.modalNode = document.getElementById('admin-blacklist-modal');
            this.form = document.getElementById('admin-blacklist-form');
            this.suggestionsNode = document.getElementById('admin-blacklist-user-suggestions');
            if (!this.filtersForm || !this.modalNode || !this.form || !document.getElementById('grid-admin-blacklist')) {
                return this;
            }

            this.prefillUserId = this.readPrefillUserId();
            this.state = this.getStateFromUrl();
            this.applyStateToFilters();
            this.modal = new bootstrap.Modal(this.modalNode);
            this.initSwitches();

            this.bind();
            this.initGrid();
            this.reload();

            this.initialized = true;
            return this;
        },

        bind: function () {
            var self = this;

            this.filtersForm.addEventListener('submit', function (event) {
                event.preventDefault();
                self.state.email = self.normalizeText(self.filtersForm.elements.email ? self.filtersForm.elements.email.value : '');
                self.state.onlyActiveNow = self.getOnlyActiveNowValue();
                self.state.status = self.normalizeStatus(self.filtersForm.elements.status ? self.filtersForm.elements.status.value : 'active');
                if (self.state.onlyActiveNow === 1) {
                    self.state.status = 'active';
                }
                self.state.page = 1;
                self.reload();
            });

            var statusInput = this.filtersForm.elements.status;
            if (statusInput) {
                statusInput.addEventListener('change', function () {
                    if (self.getOnlyActiveInput()) {
                        var statusValue = self.normalizeStatus(self.filtersForm.elements.status.value || 'all');
                        if (statusValue !== 'active') {
                            self.setOnlyActiveNowValue(0);
                            self.state.onlyActiveNow = 0;
                        }
                    }
                    self.filtersForm.dispatchEvent(new Event('submit'));
                });
            }
            var onlyActiveInput = this.getOnlyActiveInput();
            if (onlyActiveInput) {
                onlyActiveInput.addEventListener('change', function () {
                    self.syncOnlyActiveFilterUi();
                    self.filtersForm.dispatchEvent(new Event('submit'));
                });
            }

            this.root.addEventListener('click', function (event) {
                var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
                if (!trigger) {
                    return;
                }

                var action = String(trigger.getAttribute('data-action') || '').trim();
                if (action === 'admin-blacklist-reload') {
                    event.preventDefault();
                    self.reload();
                    return;
                }
                if (action === 'admin-blacklist-create') {
                    event.preventDefault();
                    self.openCreate();
                    return;
                }
                if (action === 'admin-blacklist-edit') {
                    event.preventDefault();
                    self.openEdit(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0);
                    return;
                }
                if (action === 'admin-blacklist-save') {
                    event.preventDefault();
                    self.save();
                    return;
                }
                if (action === 'admin-blacklist-delete') {
                    event.preventDefault();
                    self.removeCurrent();
                    return;
                }
                if (action === 'admin-blacklist-select-user') {
                    event.preventDefault();
                    self.selectUserSuggestion(trigger);
                    return;
                }
            });

            var userSearch = document.getElementById('admin-blacklist-user-search');
            if (userSearch) {
                userSearch.addEventListener('input', function () {
                    var query = self.normalizeText(userSearch.value || '');
                    self.scheduleUserSearch(query);
                });
                userSearch.addEventListener('focus', function () {
                    var query = self.normalizeText(userSearch.value || '');
                    if (query.length >= 2) {
                        self.scheduleUserSearch(query);
                    }
                });
            }

            this.modalNode.addEventListener('hidden.bs.modal', function () {
                self.clearSuggestions();
                self.clearForm();
            });
        },
        initSwitches: function () {
            var input = this.getOnlyActiveInput();
            if (!input || typeof window.SwitchGroup !== 'function') {
                return this;
            }
            this.onlyActiveSwitch = window.SwitchGroup(input, {
                preset: 'yesNo',
                trueLabel: 'Si',
                falseLabel: 'No',
                trueValue: '1',
                falseValue: '0',
                defaultValue: '1'
            });
            return this;
        },

        initGrid: function () {
            var self = this;

            this.grid = new Datagrid('grid-admin-blacklist', {
                name: 'AdminBlacklist',
                autoindex: 'id',
                orderable: true,
                thead: true,
                handler: { url: '/admin/blacklist/list', action: 'list' },
                nav: { display: 'bottom', urlupdate: 0, results: this.state.results, page: this.state.page },
                onGetDataSuccess: function (response) {
                    self.rowsById = {};
                    var rows = (response && Array.isArray(response.dataset)) ? response.dataset : [];
                    for (var i = 0; i < rows.length; i++) {
                        var id = parseInt(rows[i].id || 0, 10) || 0;
                        if (id > 0) {
                            self.rowsById[id] = rows[i];
                        }
                    }
                },
                columns: [
                    { label: 'ID', field: 'id', sortable: true, style: { width: '70px' } },
                    {
                        label: 'Creato il',
                        field: 'date_created',
                        sortable: true,
                        format: function (row) {
                            return self.formatDateTime(row.date_created);
                        }
                    },
                    {
                        label: 'Utente',
                        field: 'banned_email',
                        sortable: true,
                        style: { textAlign: 'left' },
                        format: function (row) {
                            var email = self.escapeHtml(row.banned_email || 'Utente sconosciuto');
                            var userId = parseInt(row.banned_id || 0, 10) || 0;
                            return '<div><b>' + email + '</b></div><div class="small text-muted">ID utente #' + String(userId) + '</div>';
                        }
                    },
                    {
                        label: 'Motivazione',
                        sortable: false,
                        style: { textAlign: 'left' },
                        format: function (row) {
                            return self.escapeHtml(row.motivation || '');
                        }
                    },
                    {
                        label: 'Inizio',
                        field: 'date_start',
                        sortable: true,
                        format: function (row) {
                            return self.formatDateTime(row.date_start);
                        }
                    },
                    {
                        label: 'Fine',
                        field: 'date_end',
                        sortable: true,
                        format: function (row) {
                            return self.formatDateTime(row.date_end);
                        }
                    },
                    {
                        label: 'Stato',
                        sortable: false,
                        format: function (row) {
                            return self.renderStatusBadge(row.status || '');
                        }
                    },
                    {
                        label: 'Autore',
                        field: 'author_email',
                        sortable: true,
                        format: function (row) {
                            return self.escapeHtml(row.author_email || '-');
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
                            return '<button type="button" class="btn btn-sm btn-outline-primary" data-action="admin-blacklist-edit" data-id="' + id + '">Modifica</button>';
                        }
                    }
                ]
            });

            this.grid.onGetDataStart = function (query, results, page, orderBy) {
                self.syncStateFromGrid(query, results, page, orderBy);
            };
        },

        reload: function () {
            if (!this.grid) {
                return this;
            }

            this.grid.loadData({
                email: this.normalizeText(this.state.email),
                status: this.effectiveStatus(),
                only_active_now: this.toBinaryInt(this.state.onlyActiveNow)
            }, this.state.results, this.state.page, this.state.orderBy);

            return this;
        },

        openCreate: function () {
            this.clearForm();
            this.toggleDeleteButton(false);
            this.setModalTitle('Nuovo ban');

            if (this.prefillUserId > 0) {
                this.setField('banned_id', String(this.prefillUserId));
                var userSearch = document.getElementById('admin-blacklist-user-search');
                if (userSearch) {
                    userSearch.value = 'ID utente #' + String(this.prefillUserId);
                }
            }

            this.modal.show();
            return this;
        },

        openEdit: function (id) {
            var row = this.rowsById[id] || null;
            if (!row) {
                return this;
            }

            this.clearForm();
            this.setModalTitle('Modifica ban');
            this.toggleDeleteButton(true);

            this.setField('id', String(parseInt(row.id || 0, 10) || 0));
            this.setField('banned_id', String(parseInt(row.banned_id || 0, 10) || 0));
            this.setField('motivation', row.motivation || '');
            this.setField('date_start', this.toDatetimeLocalValue(row.date_start));
            this.setField('date_end', this.toDatetimeLocalValue(row.date_end));

            var searchInput = document.getElementById('admin-blacklist-user-search');
            if (searchInput) {
                var email = this.normalizeText(row.banned_email || '');
                var userId = parseInt(row.banned_id || 0, 10) || 0;
                searchInput.value = email !== '' ? (email + ' (#' + String(userId) + ')') : ('ID utente #' + String(userId));
            }

            this.modal.show();
            return this;
        },

        save: function () {
            var payload = this.collectPayload();
            if (payload.banned_id <= 0) {
                Toast.show({ body: 'Seleziona un utente valido.', type: 'warning' });
                return this;
            }
            if (!payload.motivation) {
                Toast.show({ body: 'Motivazione obbligatoria.', type: 'warning' });
                return this;
            }

            var self = this;
            var url = payload.id > 0 ? '/admin/blacklist/update' : '/admin/blacklist/create';
            this.post(url, payload, function () {
                Toast.show({ body: 'Blacklist aggiornata.', type: 'success' });
                self.modal.hide();
                self.reload();
            });

            return this;
        },

        removeCurrent: function () {
            var id = parseInt(this.getField('id') || '0', 10) || 0;
            if (id <= 0) {
                return this;
            }

            var self = this;
            Dialog('warning', {
                title: 'Conferma eliminazione',
                body: '<p>Vuoi eliminare questo record blacklist?</p>',
                buttons: [
                    { text: 'Annulla', class: 'btn btn-secondary', dismiss: true },
                    {
                        text: 'Elimina',
                        class: 'btn btn-danger',
                        click: function () {
                            self.post('/admin/blacklist/delete', { id: id }, function () {
                                Toast.show({ body: 'Record eliminato.', type: 'success' });
                                self.modal.hide();
                                self.reload();
                            });
                        }
                    }
                ]
            }).show();

            return this;
        },

        collectPayload: function () {
            var payload = {
                id: parseInt(this.getField('id') || '0', 10) || 0,
                banned_id: parseInt(this.getField('banned_id') || '0', 10) || 0,
                motivation: this.normalizeText(this.getField('motivation')),
                date_start: this.fromDatetimeLocalValue(this.getField('date_start')),
                date_end: this.fromDatetimeLocalValue(this.getField('date_end'))
            };

            return payload;
        },

        scheduleUserSearch: function (query) {
            var self = this;
            if (this.userSearchTimer) {
                window.clearTimeout(this.userSearchTimer);
                this.userSearchTimer = null;
            }

            if (query.length < 2) {
                this.clearSuggestions();
                return;
            }

            this.userSearchTimer = window.setTimeout(function () {
                self.searchUsers(query);
            }, 220);
        },

        searchUsers: function (query) {
            var self = this;
            this.post('/admin/users/list', {
                query: { email: query, status: 'all' },
                page: 1,
                results: 8,
                orderBy: 'email|ASC'
            }, function (response) {
                var rows = (response && Array.isArray(response.dataset)) ? response.dataset : [];
                self.renderSuggestions(rows);
            });
        },

        renderSuggestions: function (rows) {
            if (!this.suggestionsNode) {
                return;
            }

            if (!rows || !rows.length) {
                this.clearSuggestions();
                return;
            }

            var html = '';
            for (var i = 0; i < rows.length; i++) {
                var row = rows[i] || {};
                var userId = parseInt(row.id || 0, 10) || 0;
                if (userId <= 0) {
                    continue;
                }
                var email = this.escapeHtml(row.email || '');
                html += '<button type="button" class="list-group-item list-group-item-action" data-action="admin-blacklist-select-user" data-user-id="' + userId + '" data-user-email="' + email + '">'
                    + email + ' <span class="small text-muted">#' + String(userId) + '</span>'
                    + '</button>';
            }

            if (html === '') {
                this.clearSuggestions();
                return;
            }

            this.suggestionsNode.innerHTML = html;
            this.suggestionsNode.classList.remove('d-none');
        },

        selectUserSuggestion: function (trigger) {
            var userId = parseInt(trigger.getAttribute('data-user-id') || '0', 10) || 0;
            if (userId <= 0) {
                return this;
            }

            var email = this.normalizeText(trigger.getAttribute('data-user-email') || '');
            this.setField('banned_id', String(userId));

            var searchInput = document.getElementById('admin-blacklist-user-search');
            if (searchInput) {
                searchInput.value = email !== '' ? email : ('ID utente #' + String(userId));
            }

            this.clearSuggestions();
            return this;
        },

        clearSuggestions: function () {
            if (!this.suggestionsNode) {
                return;
            }
            this.suggestionsNode.innerHTML = '';
            this.suggestionsNode.classList.add('d-none');
        },

        clearForm: function () {
            this.setField('id', '');
            this.setField('banned_id', '');
            this.setField('motivation', '');
            this.setField('date_start', '');
            this.setField('date_end', '');

            var searchInput = document.getElementById('admin-blacklist-user-search');
            if (searchInput) {
                searchInput.value = '';
            }
        },

        toggleDeleteButton: function (visible) {
            var node = this.root.querySelector('[data-action="admin-blacklist-delete"]');
            if (!node) {
                return;
            }
            node.classList.toggle('d-none', visible !== true);
        },

        setModalTitle: function (title) {
            var node = document.getElementById('admin-blacklist-modal-title');
            if (node) {
                node.textContent = title;
            }
        },

        getField: function (name) {
            var field = this.form.querySelector('[name="' + name + '"]');
            return field ? String(field.value || '').trim() : '';
        },

        setField: function (name, value) {
            var field = this.form.querySelector('[name="' + name + '"]');
            if (!field) {
                return;
            }
            field.value = value == null ? '' : String(value);
        },

        toDatetimeLocalValue: function (value) {
            var source = this.normalizeText(value);
            if (source === '') {
                return '';
            }
            source = source.replace(' ', 'T');
            return source.length >= 16 ? source.slice(0, 16) : source;
        },

        fromDatetimeLocalValue: function (value) {
            var source = this.normalizeText(value);
            if (source === '') {
                return '';
            }
            source = source.replace('T', ' ');
            if (source.length === 16) {
                source += ':00';
            }
            return source;
        },

        readPrefillUserId: function () {
            try {
                var url = new URL(window.location.href);
                return parseInt(url.searchParams.get('user_id') || '0', 10) || 0;
            } catch (e) {
                return 0;
            }
        },

        getStateFromUrl: function () {
            var url = new URL(window.location.href);
            var hasOnlyActiveParam = url.searchParams.has('only_active_now');
            var hasStatusParam = url.searchParams.has('status');
            var status = this.normalizeStatus(url.searchParams.get('status') || this.defaults.status);
            var onlyActiveNow = hasOnlyActiveParam
                ? this.toBinaryInt(url.searchParams.get('only_active_now'))
                : ((hasStatusParam && status !== 'active') ? 0 : this.defaults.onlyActiveNow);

            return {
                email: this.normalizeText(url.searchParams.get('email') || this.defaults.email),
                status: status,
                onlyActiveNow: onlyActiveNow,
                page: this.toPositiveInt(url.searchParams.get('page'), this.defaults.page),
                results: this.toPositiveInt(url.searchParams.get('results'), this.defaults.results),
                orderBy: this.normalizeOrderBy(url.searchParams.get('orderBy') || this.defaults.orderBy)
            };
        },

        applyStateToFilters: function () {
            if (!this.filtersForm) {
                return this;
            }
            if (this.filtersForm.elements.email) {
                this.filtersForm.elements.email.value = this.state.email;
            }
            if (this.filtersForm.elements.status) {
                this.filtersForm.elements.status.value = this.state.status;
            }
            this.setOnlyActiveNowValue(this.state.onlyActiveNow);
            this.syncOnlyActiveFilterUi();
            return this;
        },

        syncStateFromGrid: function (query, results, page, orderBy) {
            query = (query && typeof query === 'object') ? query : {};
            this.state.email = this.normalizeText(query.email || this.defaults.email);
            this.state.status = this.normalizeStatus(query.status || this.defaults.status);
            if (Object.prototype.hasOwnProperty.call(query, 'only_active_now')) {
                this.state.onlyActiveNow = this.toBinaryInt(query.only_active_now);
            } else {
                this.state.onlyActiveNow = (this.state.status === 'active') ? 1 : 0;
            }
            this.state.page = this.toPositiveInt(page, this.defaults.page);
            this.state.results = this.toPositiveInt(results, this.defaults.results);
            this.state.orderBy = this.normalizeOrderBy(orderBy || this.defaults.orderBy);
            this.setUrlState();
            this.applyStateToFilters();
            return this;
        },

        setUrlState: function () {
            var url = new URL(window.location.href);
            var next = {
                email: this.normalizeText(this.state.email),
                status: this.effectiveStatus(),
                onlyActiveNow: this.toBinaryInt(this.state.onlyActiveNow),
                page: this.toPositiveInt(this.state.page, this.defaults.page),
                results: this.toPositiveInt(this.state.results, this.defaults.results),
                orderBy: this.normalizeOrderBy(this.state.orderBy),
                userId: this.prefillUserId > 0 ? this.prefillUserId : 0
            };

            this.setUrlParam(url, 'email', next.email, this.defaults.email);
            this.setUrlParam(url, 'status', next.status, this.defaults.status);
            this.setUrlParam(url, 'only_active_now', String(next.onlyActiveNow), String(this.defaults.onlyActiveNow));
            this.setUrlParam(url, 'page', String(next.page), String(this.defaults.page));
            this.setUrlParam(url, 'results', String(next.results), String(this.defaults.results));
            this.setUrlParam(url, 'orderBy', next.orderBy, this.defaults.orderBy);
            if (next.userId > 0) {
                url.searchParams.set('user_id', String(next.userId));
            } else {
                url.searchParams.delete('user_id');
            }

            window.history.replaceState({}, '', url.pathname + url.search + url.hash);
        },

        setUrlParam: function (url, key, value, fallback) {
            if (String(value || '') !== String(fallback || '')) {
                url.searchParams.set(key, String(value));
            } else {
                url.searchParams.delete(key);
            }
        },

        renderStatusBadge: function (statusRaw) {
            var status = String(statusRaw || '').toLowerCase();
            if (status === 'active') {
                return '<span class="badge text-bg-danger">Attivo</span>';
            }
            if (status === 'expired') {
                return '<span class="badge text-bg-secondary">Scaduto</span>';
            }
            if (status === 'permanent') {
                return '<span class="badge text-bg-dark">Permanente</span>';
            }
            return '<span class="badge text-bg-info">Programmato</span>';
        },

        formatDateTime: function (value) {
            var source = this.normalizeText(value);
            if (source === '') {
                return '-';
            }

            var date = new Date(source.replace(' ', 'T'));
            if (isNaN(date.getTime())) {
                return this.escapeHtml(source);
            }

            return date.toLocaleDateString('it-IT', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric'
            }) + ' ' + date.toLocaleTimeString('it-IT', {
                hour: '2-digit',
                minute: '2-digit'
            });
        },

        normalizeText: function (value) {
            return String(value || '').trim();
        },

        normalizeStatus: function (value) {
            value = String(value || '').toLowerCase();
            return ['all', 'active', 'expired', 'permanent'].indexOf(value) >= 0 ? value : 'all';
        },

        normalizeOrderBy: function (value) {
            var allowed = ['id', 'date_created', 'date_start', 'date_end', 'banned_email', 'author_email'];
            var parts = String(value || '').split('|');
            var field = String(parts[0] || '').trim();
            var dir = String(parts[1] || 'DESC').trim().toUpperCase();
            if (allowed.indexOf(field) === -1) {
                field = 'date_start';
            }
            if (dir !== 'ASC') {
                dir = 'DESC';
            }
            return field + '|' + dir;
        },

        effectiveStatus: function () {
            if (this.toBinaryInt(this.state.onlyActiveNow) === 1) {
                return 'active';
            }
            return this.normalizeStatus(this.state.status);
        },

        syncOnlyActiveFilterUi: function () {
            if (!this.filtersForm || !this.filtersForm.elements) {
                return this;
            }

            var onlyActive = this.getOnlyActiveNowValue() === 1;
            var statusField = this.filtersForm.elements.status;
            if (statusField) {
                if (onlyActive) {
                    statusField.value = 'active';
                }
            }
            return this;
        },

        toPositiveInt: function (value, fallback) {
            var parsed = parseInt(value, 10);
            if (!isFinite(parsed) || parsed < 1) {
                return fallback;
            }
            return parsed;
        },

        toBinaryInt: function (value) {
            if (value === true || value === '1' || value === 1 || value === 'true') {
                return 1;
            }
            return 0;
        },
        getOnlyActiveInput: function () {
            return this.filtersForm && this.filtersForm.elements ? this.filtersForm.elements.only_active_now : null;
        },

        getOnlyActiveNowValue: function () {
            var input = this.getOnlyActiveInput();
            if (!input) {
                return 0;
            }
            return this.toBinaryInt(input.value);
        },

        setOnlyActiveNowValue: function (value) {
            var input = this.getOnlyActiveInput();
            if (!input) {
                return this;
            }
            input.value = this.toBinaryInt(value) === 1 ? '1' : '0';
            if (this.onlyActiveSwitch && typeof this.onlyActiveSwitch.refresh === 'function') {
                this.onlyActiveSwitch.refresh();
            }
            return this;
        },

        post: function (url, payload, onSuccess) {
            if (typeof Request !== 'function' || !Request.http || typeof Request.http.post !== 'function') {
                Toast.show({ body: 'Servizio non disponibile.', type: 'error' });
                return;
            }

            Request.http.post(url, payload || {})
                .then(function (response) {
                    if (typeof onSuccess === 'function') {
                        onSuccess(response || null);
                    }
                })
                .catch(function (error) {
                    var message = 'Operazione non riuscita.';
                    if (typeof Request.getErrorMessage === 'function') {
                        message = Request.getErrorMessage(error, message);
                    }
                    Toast.show({ body: message, type: 'error' });
                });
        },

        escapeHtml: function (value) {
            return String(value == null ? '' : value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }
    };

    window.AdminBlacklist = AdminBlacklist;
})();
