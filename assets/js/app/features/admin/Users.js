(function () {
    var AdminUsers = {
        initialized: false,
        grid: null,
        root: null,
        form: null,
        gridContainer: null,
        currentUserId: 0,
        currentUserIsAdmin: false,
        currentUserIsSuperuser: false,
        currentUserIsModerator: false,
        state: null,

        defaults: {
            search: '',
            status: 'all',
            page: 1,
            results: 20,
            orderBy: 'date_created|DESC'
        },

        init: function () {
            if (this.initialized) {
                return this;
            }

            this.root = document.querySelector('#admin-page [data-admin-page="users"]');
            if (!this.root) {
                return this;
            }

            this.form = document.getElementById('admin-users-filters');
            this.gridContainer = document.getElementById('grid-admin-users');
            var gridContainer = this.gridContainer;
            if (!this.form || !gridContainer) {
                return this;
            }

            this.currentUserId = parseInt(gridContainer.getAttribute('data-current-user-id') || '0', 10) || 0;
            this.currentUserIsAdmin = parseInt(gridContainer.getAttribute('data-current-user-is-admin') || '0', 10) === 1;
            this.currentUserIsSuperuser = parseInt(gridContainer.getAttribute('data-current-user-is-superuser') || '0', 10) === 1;
            this.currentUserIsModerator = parseInt(gridContainer.getAttribute('data-current-user-is-moderator') || '0', 10) === 1;
            this.state = this.getStateFromUrl();
            this.applyStateToForm();
            this.bindEvents();
            this.initGrid();
            this.loadGrid();

            this.initialized = true;
            return this;
        },

        bindEvents: function () {
            var self = this;

            this.form.addEventListener('submit', function (event) {
                event.preventDefault();
                self.state.search = self.normalizeSearch(self.form.elements.search ? self.form.elements.search.value : '');
                self.state.status = self.normalizeStatus(self.form.elements.status ? self.form.elements.status.value : 'all');
                self.state.page = 1;
                self.loadGrid();
            });

            var statusInput = this.form.elements.status;
            if (statusInput) {
                statusInput.addEventListener('change', function () {
                    self.form.dispatchEvent(new Event('submit'));
                });
            }

            window.addEventListener('popstate', function () {
                self.state = self.getStateFromUrl();
                self.applyStateToForm();
                self.loadGrid();
            });

            if (this.root && this.root.addEventListener) {
                this.root.addEventListener('click', function (event) {
                    var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
                    if (!trigger) {
                        return;
                    }
                    self.handleGridAction(trigger, event);
                });
            }

            return this;
        },

        handleGridAction: function (trigger, event) {
            if (!trigger) {
                return this;
            }

            var action = String(trigger.getAttribute('data-action') || '').trim();
            if (!action || action.indexOf('admin-users-') !== 0) {
                return this;
            }

            event.preventDefault();

            var userId = this.datasetInt(trigger, 'userId', 0);
            if (userId < 1) {
                return this;
            }

            if (action === 'admin-users-reset-password') {
                if (this.currentUserIsAdmin !== true) {
                    Toast.show({ body: 'Operazione riservata agli amministratori.', type: 'warning' });
                    return this;
                }
                this.actionResetPassword(userId);
                return this;
            }

            if (action === 'admin-users-permissions') {
                if (this.currentUserIsSuperuser !== true) {
                    Toast.show({ body: 'Operazione riservata al superuser.', type: 'warning' });
                    return this;
                }
                this.actionPermissions(
                    userId,
                    this.datasetInt(trigger, 'isAdministrator', 0),
                    this.datasetInt(trigger, 'isModerator', 0),
                    this.datasetInt(trigger, 'isMaster', 0),
                    this.datasetInt(trigger, 'lockAdmin', 0),
                    this.datasetInt(trigger, 'isSuperuser', 0)
                );
                return this;
            }

            if (action === 'admin-users-disconnect') {
                if (!this.canModerateUsers()) {
                    Toast.show({ body: 'Operazione riservata a moderatori e amministratori.', type: 'warning' });
                    return this;
                }
                this.actionDisconnect(userId);
                return this;
            }

            if (action === 'admin-users-restrict') {
                this.actionRestrict(userId, this.datasetInt(trigger, 'isRestricted', 0));
                return this;
            }

            return this;
        },

        getStateFromUrl: function () {
            var url = new URL(window.location.href);
            var state = {
                search: this.normalizeSearch(url.searchParams.get('search') || url.searchParams.get('email') || this.defaults.search),
                status: this.normalizeStatus(url.searchParams.get('status') || this.defaults.status),
                page: this.toPositiveInt(url.searchParams.get('page'), this.defaults.page),
                results: this.toPositiveInt(url.searchParams.get('results'), this.defaults.results),
                orderBy: this.normalizeOrderBy(url.searchParams.get('orderBy') || this.defaults.orderBy)
            };

            return state;
        },

        applyStateToForm: function () {
            if (!this.form) {
                return this;
            }

            if (this.form.elements.search) {
                this.form.elements.search.value = this.state.search;
            }
            if (this.form.elements.status) {
                this.form.elements.status.value = this.state.status;
            }

            return this;
        },

        setUrlState: function () {
            var url = new URL(window.location.href);
            var next = {
                search: this.normalizeSearch(this.state.search),
                status: this.normalizeStatus(this.state.status),
                page: this.toPositiveInt(this.state.page, this.defaults.page),
                results: this.toPositiveInt(this.state.results, this.defaults.results),
                orderBy: this.normalizeOrderBy(this.state.orderBy)
            };

            if (next.search) {
                url.searchParams.set('search', next.search);
            } else {
                url.searchParams.delete('search');
                url.searchParams.delete('email');
            }

            if (next.status !== this.defaults.status) {
                url.searchParams.set('status', next.status);
            } else {
                url.searchParams.delete('status');
            }

            if (next.page !== this.defaults.page) {
                url.searchParams.set('page', String(next.page));
            } else {
                url.searchParams.delete('page');
            }

            if (next.results !== this.defaults.results) {
                url.searchParams.set('results', String(next.results));
            } else {
                url.searchParams.delete('results');
            }

            if (next.orderBy !== this.defaults.orderBy) {
                url.searchParams.set('orderBy', next.orderBy);
            } else {
                url.searchParams.delete('orderBy');
            }

            window.history.replaceState({}, '', url.pathname + url.search + url.hash);
            return this;
        },

        initGrid: function () {
            var self = this;

            this.grid = new Datagrid('grid-admin-users', {
                name: 'AdminUsers',
                autoindex: 'id',
                orderable: true,
                thead: true,
                handler: {
                    url: '/admin/users/list',
                    action: 'list'
                },
                nav: {
                    display: 'bottom',
                    urlupdate: 0,
                    results: this.state.results,
                    page: this.state.page
                },
                columns: [
                    {
                        label: 'Utente',
                        field: this.currentUserIsSuperuser === true ? 'email' : 'character_name',
                        sortable: true,
                        style: { textAlign: 'left' },
                        format: function (r) {
                            return self.renderIdentity(r);
                        }
                    },
                    {
                        label: 'Stato',
                        sortable: false,
                        format: function (r) {
                            return self.statusBadge(r.status || 'pending');
                        }
                    },
                    {
                        label: 'Ruoli',
                        sortable: false,
                        format: function (r) {
                            return self.rolesBadge(r);
                        }
                    },
                    {
                        label: 'Creazione',
                        field: 'date_created',
                        sortable: true,
                        format: function (r) {
                            return self.formatDateTime(r.date_created);
                        }
                    },
                    {
                        label: 'Attivazione',
                        field: 'date_actived',
                        sortable: true,
                        format: function (r) {
                            return self.formatDateTime(r.date_actived);
                        }
                    },
                    {
                        label: 'Ultimo signin',
                        field: 'date_last_signin',
                        sortable: true,
                        format: function (r) {
                            return self.formatDateTime(r.date_last_signin);
                        }
                    },
                    {
                        label: 'Ultimo signout',
                        field: 'date_last_signout',
                        sortable: true,
                        format: function (r) {
                            return self.formatDateTime(r.date_last_signout);
                        }
                    },
                    {
                        label: 'Azioni',
                        sortable: false,
                        style: { textAlign: 'left' },
                        format: function (r) {
                            return self.renderActions(r);
                        }
                    }
                ]
            });

            this.grid.onGetDataStart = function (query, results, page, orderBy) {
                self.syncStateFromGrid(query, results, page, orderBy);
            };

            return this;
        },

        loadGrid: function () {
            if (!this.grid) {
                return this;
            }

            var query = {
                search: this.normalizeSearch(this.state.search),
                status: this.normalizeStatus(this.state.status)
            };

            this.grid.loadData(query, this.state.results, this.state.page, this.state.orderBy);
            return this;
        },

        syncStateFromGrid: function (query, results, page, orderBy) {
            query = (query && typeof query === 'object') ? query : {};
            this.state.search = this.normalizeSearch(query.search || query.email || '');
            this.state.status = this.normalizeStatus(query.status || this.defaults.status);
            this.state.results = this.toPositiveInt(results, this.defaults.results);
            this.state.page = this.toPositiveInt(page, this.defaults.page);
            this.state.orderBy = this.normalizeOrderBy(orderBy || this.defaults.orderBy);
            this.setUrlState();
            this.applyStateToForm();

            return this;
        },

        renderActions: function (row) {
            var userId = parseInt(row.id, 10) || 0;
            var isSelf = this.currentUserId > 0 && userId === this.currentUserId;
            var isRestricted = parseInt(row.is_restricted || 0, 10) === 1;
            var isAdministrator = parseInt(row.is_administrator || 0, 10) === 1;
            var isModerator = parseInt(row.is_moderator || 0, 10) === 1;
            var isMaster = parseInt(row.is_master || 0, 10) === 1;
            var isSuperuser = parseInt(row.is_superuser || 0, 10) === 1;
            var restrictLabel = isRestricted ? 'Sblocca' : 'Restringi';
            var restrictClass = isRestricted ? 'btn-outline-success' : 'btn-outline-warning';
            var banUrl = '/admin/blacklist?user_id=' + encodeURIComponent(String(userId));

            var html = '';
            html += '<div class="d-flex flex-wrap gap-1">';
            if (this.currentUserIsAdmin === true) {
                html += '<button type="button" class="btn btn-sm btn-outline-primary" data-action="admin-users-reset-password" data-user-id="' + userId + '">Reset password</button>';
            }
            var canEditPermissions = !isSelf && !isSuperuser && (
                this.currentUserIsSuperuser === true ||
                (this.currentUserIsAdmin === true && !isAdministrator)
            );
            if (canEditPermissions) {
                html += '<button type="button" class="btn btn-sm btn-outline-secondary" data-action="admin-users-permissions" data-user-id="' + userId + '" data-is-administrator="' + (isAdministrator ? 1 : 0) + '" data-is-moderator="' + (isModerator ? 1 : 0) + '" data-is-master="' + (isMaster ? 1 : 0) + '" data-lock-admin="' + (isAdministrator && this.currentUserIsSuperuser !== true ? 1 : 0) + '" data-is-superuser="' + (isSuperuser ? 1 : 0) + '">Permessi</button>';
            }
            if (isSelf) {
                html += '<span class="badge text-bg-secondary align-self-center">Utente corrente</span>';
            } else {
                if (this.canModerateUsers()) {
                    html += '<button type="button" class="btn btn-sm btn-outline-danger" data-action="admin-users-disconnect" data-user-id="' + userId + '">Disconnetti</button>';
                }
                html += '<button type="button" class="btn btn-sm ' + restrictClass + '" data-action="admin-users-restrict" data-user-id="' + userId + '" data-is-restricted="' + (isRestricted ? 1 : 0) + '">' + restrictLabel + '</button>';
                if (this.canModerateUsers()) {
                    html += '<a class="btn btn-sm btn-outline-dark" href="' + banUrl + '">Banna</a>';
                }
            }
            html += '</div>';

            return html;
        },

        canModerateUsers: function () {
            return this.currentUserIsAdmin === true || this.currentUserIsModerator === true;
        },

        actionResetPassword: function (userId) {
            var self = this;
            Dialog('warning', {
                title: 'Reset password',
                body: '<p>Inviare email di reset password a questo utente?</p>'
            }, function () {
                self.hideConfirmDialog();
                self.requestPost('/admin/users/reset-password', { user_id: userId }, function () {
                    Toast.show({ body: 'Email di reset inviata.', type: 'success' });
                    self.loadGrid();
                });
            }).show();
        },

        actionPermissions: function (userId, isAdministrator, isModerator, isMaster, lockAdmin, isSuperuser) {
            var self = this;
            var flags = this.normalizePermissionsHierarchy(isAdministrator, isModerator, isMaster);
            var lockAdminOn = parseInt(lockAdmin, 10) === 1;
            var lockSuperuserOn = parseInt(isSuperuser, 10) === 1;
            var adminLockedHint = lockAdminOn
                ? '<div class="alert alert-info py-2 px-3 small mt-3 mb-0">Questo account è già amministratore: il ruolo Admin non può essere rimosso.</div>'
                : '';
            if (lockSuperuserOn) {
                Toast.show({ body: 'I permessi dell\'account superuser non sono modificabili.', type: 'warning' });
                return this;
            }
            var body = ''
                + '<form id="admin-users-permissions-form" class="text-start">'
                + '<div class="mb-3">'
                + '<label class="form-label d-block mb-1">Admin</label>'
                + '<input type="hidden" id="admin-users-perm-admin" name="is_administrator" value="' + String(flags.is_administrator) + '">'
                + '<div class="form-text mt-1">Super utente: include automaticamente i permessi di Moderatore e Master.</div>'
                + '</div>'
                + '<div class="mb-3">'
                + '<label class="form-label d-block mb-1">Moderatore</label>'
                + '<input type="hidden" id="admin-users-perm-moderator" name="is_moderator" value="' + String(flags.is_moderator) + '">'
                + '<div class="form-text mt-1">Include automaticamente i permessi di Master.</div>'
                + '</div>'
                + '<div class="mb-0">'
                + '<label class="form-label d-block mb-1">Master</label>'
                + '<input type="hidden" id="admin-users-perm-master" name="is_master" value="' + String(flags.is_master) + '">'
                + '</div>'
                + adminLockedHint
                + '</form>';

            var dialog = Dialog('default', {
                title: 'Permessi utente',
                body: body
            }, function () {
                var form = document.getElementById('admin-users-permissions-form');
                if (!form) {
                    self.hideConfirmDialog();
                    return;
                }

                var payload = {
                    user_id: userId,
                    is_administrator: self.permissionInputToInt(form.elements.is_administrator, 0),
                    is_moderator: self.permissionInputToInt(form.elements.is_moderator, 0),
                    is_master: self.permissionInputToInt(form.elements.is_master, 0)
                };
                payload = self.normalizePermissionsHierarchy(payload.is_administrator, payload.is_moderator, payload.is_master);
                payload.user_id = userId;

                self.hideConfirmDialog();
                self.requestPost('/admin/users/permissions', payload, function () {
                    Toast.show({ body: 'Permessi aggiornati.', type: 'success' });
                    self.loadGrid();
                });
            });

            dialog.show();
            window.setTimeout(function () {
                var form = document.getElementById('admin-users-permissions-form');
                if (!form) {
                    return;
                }
                self.initPermissionsHierarchySwitches(form, {
                    lockAdminOn: lockAdminOn
                });
            }, 0);
        },

        normalizePermissionsHierarchy: function (isAdministrator, isModerator, isMaster) {
            var admin = parseInt(isAdministrator, 10) === 1;
            var moderator = admin || parseInt(isModerator, 10) === 1;
            var master = admin || moderator || parseInt(isMaster, 10) === 1;
            return {
                is_administrator: admin ? 1 : 0,
                is_moderator: moderator ? 1 : 0,
                is_master: master ? 1 : 0
            };
        },

        permissionInputToInt: function (input, fallback) {
            if (!input) {
                return fallback === 1 ? 1 : 0;
            }
            var raw = String(input.value == null ? '' : input.value).trim();
            if (raw === '1') return 1;
            if (raw === '0') return 0;
            return fallback === 1 ? 1 : 0;
        },

        setPermissionInputValue: function (input, value) {
            if (!input) {
                return;
            }
            var normalized = parseInt(value, 10) === 1 ? '1' : '0';
            if (String(input.value || '') === normalized) {
                return;
            }
            input.value = normalized;
            if (typeof window.$ === 'function') {
                window.$(input).trigger('change');
            }
            if (typeof input.dispatchEvent === 'function') {
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
        },

        setPermissionInputDisabled: function (input, switchRef, disabled) {
            var state = disabled === true;
            if (switchRef && typeof switchRef.setDisabled === 'function') {
                switchRef.setDisabled(state);
                return;
            }
            if (input) {
                input.disabled = state;
            }
        },

        initPermissionsHierarchySwitches: function (form, options) {
            if (!form || !form.elements) {
                return this;
            }
            options = (options && typeof options === 'object') ? options : {};

            var self = this;
            var adminInput = form.elements.is_administrator;
            var moderatorInput = form.elements.is_moderator;
            var masterInput = form.elements.is_master;
            var adminSwitch = null;
            var moderatorSwitch = null;
            var masterSwitch = null;
            var isSyncing = false;
            var lockAdminOn = options.lockAdminOn === true;
            if (lockAdminOn) {
                this.setPermissionInputValue(adminInput, 1);
            }

            var previousAdmin = this.permissionInputToInt(adminInput, 0) === 1 ? 1 : 0;
            var previousModerator = this.permissionInputToInt(moderatorInput, 0) === 1 ? 1 : 0;

            if (typeof window.SwitchGroup === 'function') {
                adminSwitch = window.SwitchGroup(adminInput, {
                    preset: 'activeInactive',
                    trueLabel: 'Attivo',
                    falseLabel: 'Disattivo',
                    trueValue: '1',
                    falseValue: '0',
                    defaultValue: '0'
                });
                moderatorSwitch = window.SwitchGroup(moderatorInput, {
                    preset: 'activeInactive',
                    trueLabel: 'Attivo',
                    falseLabel: 'Disattivo',
                    trueValue: '1',
                    falseValue: '0',
                    defaultValue: '0'
                });
                masterSwitch = window.SwitchGroup(masterInput, {
                    preset: 'activeInactive',
                    trueLabel: 'Attivo',
                    falseLabel: 'Disattivo',
                    trueValue: '1',
                    falseValue: '0',
                    defaultValue: '0'
                });
            }

            var syncHierarchy = function () {
                if (isSyncing) {
                    return;
                }
                isSyncing = true;

                try {
                    var admin = self.permissionInputToInt(adminInput, 0) === 1;
                    var moderator = self.permissionInputToInt(moderatorInput, 0) === 1;
                    var master = self.permissionInputToInt(masterInput, 0) === 1;

                    if (lockAdminOn && !admin) {
                        self.setPermissionInputValue(adminInput, 1);
                        admin = true;
                    }

                    // Cascata in disattivazione:
                    // Admin OFF -> Moderatore OFF + Master OFF
                    if (previousAdmin === 1 && !admin) {
                        if (moderator) {
                            self.setPermissionInputValue(moderatorInput, 0);
                            moderator = false;
                        }
                        if (master) {
                            self.setPermissionInputValue(masterInput, 0);
                            master = false;
                        }
                    }

                    // Cascata in disattivazione:
                    // Moderatore OFF -> Master OFF
                    if (!admin && previousModerator === 1 && !moderator) {
                        if (master) {
                            self.setPermissionInputValue(masterInput, 0);
                            master = false;
                        }
                    }

                    // Cascata in attivazione (gerarchia):
                    // Admin ON -> Moderatore ON + Master ON
                    if (admin) {
                        if (!moderator) {
                            self.setPermissionInputValue(moderatorInput, 1);
                            moderator = true;
                        }
                        if (!master) {
                            self.setPermissionInputValue(masterInput, 1);
                            master = true;
                        }
                    } else if (moderator && !master) {
                        // Moderatore ON -> Master ON
                        self.setPermissionInputValue(masterInput, 1);
                        master = true;
                    }

                    self.setPermissionInputDisabled(moderatorInput, moderatorSwitch, admin);
                    self.setPermissionInputDisabled(masterInput, masterSwitch, admin || moderator);
                    self.setPermissionInputDisabled(adminInput, adminSwitch, lockAdminOn);
                    if (moderatorSwitch && typeof moderatorSwitch.refresh === 'function') {
                        moderatorSwitch.refresh();
                    }
                    if (masterSwitch && typeof masterSwitch.refresh === 'function') {
                        masterSwitch.refresh();
                    }
                    if (adminSwitch && typeof adminSwitch.refresh === 'function') {
                        adminSwitch.refresh();
                    }

                    previousAdmin = admin ? 1 : 0;
                    previousModerator = moderator ? 1 : 0;
                } finally {
                    isSyncing = false;
                }
            };

            if (typeof window.$ === 'function') {
                if (adminInput) window.$(adminInput).off('change.adminUsersPerm').on('change.adminUsersPerm', syncHierarchy);
                if (moderatorInput) window.$(moderatorInput).off('change.adminUsersPerm').on('change.adminUsersPerm', syncHierarchy);
                if (masterInput) window.$(masterInput).off('change.adminUsersPerm').on('change.adminUsersPerm', syncHierarchy);
            } else {
                if (adminInput && adminInput.addEventListener) adminInput.addEventListener('change', syncHierarchy);
                if (moderatorInput && moderatorInput.addEventListener) moderatorInput.addEventListener('change', syncHierarchy);
                if (masterInput && masterInput.addEventListener) masterInput.addEventListener('change', syncHierarchy);
            }

            var normalized = this.normalizePermissionsHierarchy(
                this.permissionInputToInt(adminInput, 0),
                this.permissionInputToInt(moderatorInput, 0),
                this.permissionInputToInt(masterInput, 0)
            );
            this.setPermissionInputValue(adminInput, normalized.is_administrator);
            this.setPermissionInputValue(moderatorInput, normalized.is_moderator);
            this.setPermissionInputValue(masterInput, normalized.is_master);
            syncHierarchy();

            return this;
        },

        actionDisconnect: function (userId) {
            var self = this;
            Dialog('danger', {
                title: 'Disconnetti utente',
                body: '<p>Confermi la disconnessione forzata dell\'utente?</p>'
            }, function () {
                self.hideConfirmDialog();
                self.requestPost('/admin/users/disconnect', { user_id: userId }, function () {
                    Toast.show({ body: 'Sessioni utente invalidate.', type: 'success' });
                    self.loadGrid();
                });
            }).show();
        },

        actionRestrict: function (userId, isRestricted) {
            var self = this;
            var makeRestricted = (parseInt(isRestricted, 10) !== 1);
            var title = makeRestricted ? 'Restringi utente' : 'Sblocca utente';
            var body = makeRestricted
                ? '<p>L\'utente non potra usare chat, messaggi privati e forum.</p>'
                : '<p>Rimuovere le restrizioni di comunicazione per questo utente?</p>';

            Dialog('warning', { title: title, body: body }, function () {
                self.hideConfirmDialog();
                self.requestPost('/admin/users/restrict', {
                    user_id: userId,
                    is_restricted: makeRestricted ? 1 : 0
                }, function () {
                    Toast.show({
                        body: makeRestricted ? 'Utente ristretto con successo.' : 'Restrizioni rimosse.',
                        type: 'success'
                    });
                    self.loadGrid();
                });
            }).show();
        },

        requestPost: function (url, payload, onSuccess, onError) {
            var self = this;
            if (typeof Request !== 'function') {
                if (typeof onError === 'function') {
                    onError(this.requestUnavailableMessage());
                } else {
                    Dialog('danger', {
                        title: 'Errore',
                        body: '<p>' + this.requestUnavailableMessage() + '</p>'
                    }).show();
                }
                return this;
            }

            if (!Request.http || typeof Request.http.post !== 'function') {
                if (typeof onError === 'function') {
                    onError(this.requestUnavailableMessage());
                    return this;
                }
                Dialog('danger', {
                    title: 'Errore',
                    body: '<p>' + this.requestUnavailableMessage() + '</p>'
                }).show();
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
                Dialog('danger', {
                    title: 'Errore',
                    body: '<p>' + self.requestErrorMessage(error) + '</p>'
                }).show();
            });

            return this;
        },

        requestErrorMessage: function (error) {
            if (typeof window !== 'undefined' && window.Request && typeof window.Request.getErrorMessage === 'function') {
                return window.Request.getErrorMessage(error, 'Operazione non riuscita.');
            }
            if (typeof error === 'string' && error.trim() !== '') {
                return error.trim();
            }
            if (error && typeof error.message === 'string' && error.message.trim() !== '') {
                return error.message.trim();
            }
            if (error && error.payload && typeof error.payload === 'object') {
                if (typeof error.payload.error === 'string' && error.payload.error.trim() !== '') {
                    return error.payload.error.trim();
                }
                if (typeof error.payload.message === 'string' && error.payload.message.trim() !== '') {
                    return error.payload.message.trim();
                }
            }
            return 'Operazione non riuscita.';
        },

        requestUnavailableMessage: function () {
            if (typeof window !== 'undefined' && window.Request && typeof window.Request.getUnavailableMessage === 'function') {
                return window.Request.getUnavailableMessage();
            }
            return 'Servizio comunicazione non disponibile. Ricarica la pagina e riprova.';
        },

        getConfirmDialog: function () {
            if (window.SystemDialogs && typeof window.SystemDialogs.ensureGeneralConfirm === 'function') {
                return window.SystemDialogs.ensureGeneralConfirm();
            }
            if (window.generalConfirm && typeof window.generalConfirm.show === 'function') {
                return window.generalConfirm;
            }
            return null;
        },

        hideConfirmDialog: function () {
            var dialog = this.getConfirmDialog();
            if (dialog && typeof dialog.hide === 'function') {
                dialog.hide();
            }
        },

        normalizeSearch: function (value) {
            return String(value || '').trim().toLowerCase();
        },

        normalizeStatus: function (value) {
            var status = String(value || '').trim().toLowerCase();
            if (status !== 'active' && status !== 'disabled' && status !== 'pending') {
                status = 'all';
            }
            return status;
        },

        normalizeOrderBy: function (value) {
            var allowed = {
                email: true,
                character_name: true,
                date_created: true,
                date_actived: true,
                date_last_signin: true,
                date_last_signout: true
            };
            var raw = String(value || '').trim();
            if (!raw) {
                return this.defaults.orderBy;
            }

            var pieces = raw.split('|');
            var field = String(pieces[0] || '').trim();
            var dir = String(pieces[1] || '').trim().toUpperCase();

            if (!allowed[field]) {
                return this.defaults.orderBy;
            }
            if (dir !== 'DESC') {
                dir = 'ASC';
            }

            return field + '|' + dir;
        },

        toPositiveInt: function (value, fallback) {
            var parsed = parseInt(value, 10);
            if (isNaN(parsed) || parsed < 1) {
                return parseInt(fallback, 10) || 1;
            }
            return parsed;
        },

        datasetInt: function (node, key, fallback) {
            if (!node || !node.dataset) {
                return parseInt(fallback, 10) || 0;
            }

            var parsed = parseInt(node.dataset[key], 10);
            if (isNaN(parsed)) {
                return parseInt(fallback, 10) || 0;
            }
            return parsed;
        },

        formatDateTime: function (value) {
            var raw = String(value || '').trim();
            if (!raw) {
                return '<span class="text-muted">-</span>';
            }

            var parsed = new Date(raw.replace(' ', 'T'));
            if (isNaN(parsed.getTime())) {
                return this.escapeHtml(raw);
            }

            return parsed.toLocaleString('it-IT', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit'
            });
        },

        statusBadge: function (status) {
            var s = String(status || '').toLowerCase();
            if (s === 'active') {
                return '<span class="badge text-bg-success">Attivo</span>';
            }
            if (s === 'disabled') {
                return '<span class="badge text-bg-warning">Disattivo</span>';
            }
            return '<span class="badge text-bg-secondary">Da attivare</span>';
        },

        rolesBadge: function (row) {
            var tags = [];
            if (parseInt(row.is_administrator || 0, 10) === 1) {
                tags.push('<span class="badge text-bg-danger">Admin</span>');
            }
            if (parseInt(row.is_superuser || 0, 10) === 1) {
                tags.push('<span class="badge text-bg-warning text-dark">Superuser</span>');
            }
            if (parseInt(row.is_moderator || 0, 10) === 1) {
                tags.push('<span class="badge text-bg-info">Moderatore</span>');
            }
            if (parseInt(row.is_master || 0, 10) === 1) {
                tags.push('<span class="badge text-bg-primary">Master</span>');
            }
            if (!tags.length) {
                tags.push('<span class="text-muted">Nessuno</span>');
            }
            return tags.join(' ');
        },

        renderIdentity: function (row) {
            var characterName = this.renderCharacterName(row);
            var email = String(row && row.email ? row.email : '').trim();

            if (this.currentUserIsSuperuser === true) {
                var line1 = '<div class="fw-semibold">' + this.escapeHtml(email || '-') + '</div>';
                var line2 = '<div class="small text-muted">' + this.escapeHtml(characterName) + '</div>';
                return line1 + line2;
            }

            return '<div class="fw-semibold">' + this.escapeHtml(characterName) + '</div>';
        },

        renderCharacterName: function (row) {
            var name = String(row && row.character_name ? row.character_name : '').trim();
            var surname = String(row && row.character_surname ? row.character_surname : '').trim();
            var full = (name + ' ' + surname).trim();
            if (full !== '') {
                return full;
            }
            return 'Nessun personaggio associato';
        },

        escapeHtml: function (value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }
    };

    window.AdminUsers = AdminUsers;
})();
