const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

var AdminCharacters = {
    initialized: false,
    root: null,
    form: null,
    grid: null,
    state: null,
    rowsById: {},
    modals: {},
    logGrids: {},
    currentCharacterId: 0,
    currentCharacterName: '',
    currentUserIsSuperuser: false,
    extensions: [],

    defaults: {
        name: '',
        email: '',
        visibility: 'all',
        online: 'all',
        page: 1,
        results: 20,
        orderBy: 'name|ASC'
    },

    init: function () {
        if (this.initialized) {
            return this;
        }

        this.root = document.querySelector('#admin-page [data-admin-page="characters"]');
        if (!this.root) {
            return this;
        }

        this.form = document.getElementById('admin-characters-filters');
        var gridNode = document.getElementById('grid-admin-characters');
        if (!this.form || !gridNode) {
            return this;
        }
        this.currentUserIsSuperuser = parseInt(gridNode.getAttribute('data-current-user-is-superuser') || '0', 10) === 1;

        this.ensureModals();
        this.state = this.getStateFromUrl();
        this.applyStateToForm();
        this.bindEvents();
        this.initGrid();
        this.loadGrid();

        this.initialized = true;
        return this;
    },

    ensureModals: function () {
        var ids = {
            profile: 'admin-characters-profile-modal',
            editChar: 'admin-characters-edit-modal',
            experienceLogs: 'admin-characters-experience-logs-modal',
            economyLogs: 'admin-characters-economy-logs-modal',
            sessionLogs: 'admin-characters-session-logs-modal'
        };

        for (var key in ids) {
            if (!Object.prototype.hasOwnProperty.call(ids, key)) {
                continue;
            }
            var node = document.getElementById(ids[key]);
            if (node) {
                this.modals[key] = new bootstrap.Modal(node);
            }
        }

        return this;
    },

    bindEvents: function () {
        var self = this;

        this.form.addEventListener('submit', function (event) {
            event.preventDefault();
            self.state.name = self.normalizeText(self.form.elements.name ? self.form.elements.name.value : '');
            self.state.email = self.normalizeText(self.form.elements.email ? self.form.elements.email.value : '');
            self.state.visibility = self.normalizeVisibility(self.form.elements.visibility ? self.form.elements.visibility.value : 'all');
            self.state.online = self.normalizeOnline(self.form.elements.online ? self.form.elements.online.value : 'all');
            self.state.page = 1;
            self.loadGrid();
        });

        var visibilityInput = this.form.elements.visibility;
        if (visibilityInput) {
            visibilityInput.addEventListener('change', function () {
                self.form.dispatchEvent(new Event('submit'));
            });
        }

        var onlineInput = this.form.elements.online;
        if (onlineInput) {
            onlineInput.addEventListener('change', function () {
                self.form.dispatchEvent(new Event('submit'));
            });
        }

        if (this.root && this.root.addEventListener) {
            this.root.addEventListener('click', function (event) {
                var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
                if (!trigger) {
                    return;
                }
                self.handleAction(trigger, event);
            });
        }

        globalWindow.addEventListener('popstate', function () {
            self.state = self.getStateFromUrl();
            self.applyStateToForm();
            self.loadGrid();
        });

        return this;
    },

    handleAction: function (trigger, event) {
        var action = String(trigger.getAttribute('data-action') || '').trim();
        var isOwnAction = action.indexOf('admin-characters-') === 0 || action.indexOf('admin-edit-') === 0;
        if (!isOwnAction) {
            return this;
        }

        event.preventDefault();

        // Modal-internal actions don't carry data-character-id
        if (action.indexOf('admin-edit-save-') === 0) {
            this.handleEditSave(action.replace('admin-edit-save-', ''));
            return this;
        }
        if (action === 'admin-edit-tab') {
            this.switchEditTab(trigger.getAttribute('data-tab') || 'identity', trigger);
            return this;
        }

        var characterId = this.datasetInt(trigger, 'characterId', 0);
        if (characterId <= 0) {
            return this;
        }

        if (action === 'admin-characters-open-profile') {
            this.openProfileModal(characterId);
            return this;
        }
        if (action === 'admin-characters-open-edit') {
            this.openEditModal(characterId);
            return this;
        }
        if (action === 'admin-characters-open-experience-logs') {
            this.openLogModal('experience', characterId);
            return this;
        }
        if (action === 'admin-characters-open-economy-logs') {
            this.openLogModal('economy', characterId);
            return this;
        }
        if (action === 'admin-characters-open-session-logs') {
            this.openLogModal('session', characterId);
            return this;
        }

        return this;
    },

    initGrid: function () {
        var self = this;

        this.grid = new Datagrid('grid-admin-characters', {
            name: 'AdminCharacters',
            autoindex: 'id',
            orderable: true,
            thead: true,
            handler: { url: '/admin/characters/list', action: 'list' },
            nav: { display: 'bottom', urlupdate: 0, results: this.state.results, page: this.state.page },
            onGetDataSuccess: function (response) {
                self.rowsById = {};
                var rows = (response && Array.isArray(response.dataset)) ? response.dataset : [];
                for (var i = 0; i < rows.length; i++) {
                    var row = rows[i] || {};
                    var id = parseInt(row.id || 0, 10) || 0;
                    if (id > 0) {
                        self.rowsById[id] = row;
                    }
                }
            },
            columns: [
                { label: 'Personaggio', field: 'name', sortable: true, style: { textAlign: 'left' }, format: function (row) {
                    var name = self.buildCharacterName(row);
                    return '<div class="fw-semibold">' + self.escapeHtml(name) + '</div><div class="small text-muted">ID #' + String(parseInt(row.id, 10) || 0) + '</div>';
                }},
                { label: 'Proprietario', sortable: false, format: function (row) {
                    if (self.currentUserIsSuperuser !== true) {
                        return '<span class="text-muted">Privato</span>';
                    }
                    return self.escapeHtml(row.email || '-');
                }},
                { label: 'Visibilita', field: 'is_visible', sortable: true, format: function (row) {
                    var visible = parseInt(row.is_visible || 0, 10) === 1;
                    return visible ? '<span class="badge text-bg-success">Visibile</span>' : '<span class="badge text-bg-secondary">Invisibile</span>';
                }},
                { label: 'Stato', sortable: false, format: function (row) {
                    var online = String(row.online_status || '').toLowerCase() === 'online';
                    return online ? '<span class="badge text-bg-primary">Online</span>' : '<span class="badge text-bg-dark">Offline</span>';
                }},
                { label: 'Posizione', sortable: false, style: { textAlign: 'left' }, format: function (row) { return self.escapeHtml(self.renderPositionText(row)); } },
                { label: 'Ultimo accesso', field: 'date_last_signin', sortable: true, format: function (row) { return self.formatDateTime(row.date_last_signin); } },
                { label: 'Ultima attivita', field: 'date_last_seed', sortable: true, format: function (row) { return self.formatDateTime(row.date_last_seed); } },
                { label: 'Azioni', sortable: false, style: { textAlign: 'left' }, format: function (row) {
                    var characterId = parseInt(row.id || 0, 10) || 0;
                    if (characterId <= 0) {
                        return '-';
                    }

                    return '<div class="d-flex flex-wrap gap-1">'
                        + '<button type="button" class="btn btn-sm btn-outline-primary" data-action="admin-characters-open-profile" data-character-id="' + characterId + '">Scheda</button>'
                        + '<button type="button" class="btn btn-sm btn-outline-warning" data-action="admin-characters-open-edit" data-character-id="' + characterId + '">Modifica</button>'
                        + '<button type="button" class="btn btn-sm btn-outline-info" data-action="admin-characters-open-experience-logs" data-character-id="' + characterId + '">Log EXP</button>'
                        + '<button type="button" class="btn btn-sm btn-outline-info" data-action="admin-characters-open-economy-logs" data-character-id="' + characterId + '">Log economia</button>'
                        + '<button type="button" class="btn btn-sm btn-outline-info" data-action="admin-characters-open-session-logs" data-character-id="' + characterId + '">Log accessi</button>'
                        + '</div>';
                }}
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

        this.grid.loadData({
            name: this.normalizeText(this.state.name),
            email: this.normalizeText(this.state.email),
            visibility: this.normalizeVisibility(this.state.visibility),
            online: this.normalizeOnline(this.state.online)
        }, this.state.results, this.state.page, this.state.orderBy);

        return this;
    },

    getStateFromUrl: function () {
        var url = new URL(globalWindow.location.href);
        return {
            name: this.normalizeText(url.searchParams.get('name') || this.defaults.name),
            email: this.normalizeText(url.searchParams.get('email') || this.defaults.email),
            visibility: this.normalizeVisibility(url.searchParams.get('visibility') || this.defaults.visibility),
            online: this.normalizeOnline(url.searchParams.get('online') || this.defaults.online),
            page: this.toPositiveInt(url.searchParams.get('page'), this.defaults.page),
            results: this.toPositiveInt(url.searchParams.get('results'), this.defaults.results),
            orderBy: this.normalizeOrderBy(url.searchParams.get('orderBy') || this.defaults.orderBy)
        };
    },

    applyStateToForm: function () {
        if (!this.form) {
            return this;
        }
        if (this.form.elements.name) {
            this.form.elements.name.value = this.state.name;
        }
        if (this.form.elements.email) {
            this.form.elements.email.value = this.state.email;
        }
        if (this.form.elements.visibility) {
            this.form.elements.visibility.value = this.state.visibility;
        }
        if (this.form.elements.online) {
            this.form.elements.online.value = this.state.online;
        }
        return this;
    },

    syncStateFromGrid: function (query, results, page, orderBy) {
        query = (query && typeof query === 'object') ? query : {};

        this.state.name = this.normalizeText(query.name || this.defaults.name);
        this.state.email = this.normalizeText(query.email || this.defaults.email);
        this.state.visibility = this.normalizeVisibility(query.visibility || this.defaults.visibility);
        this.state.online = this.normalizeOnline(query.online || this.defaults.online);
        this.state.page = this.toPositiveInt(page, this.defaults.page);
        this.state.results = this.toPositiveInt(results, this.defaults.results);
        this.state.orderBy = this.normalizeOrderBy(orderBy || this.defaults.orderBy);

        this.setUrlState();
        this.applyStateToForm();
        return this;
    },
    setUrlState: function () {
        var url = new URL(globalWindow.location.href);
        var next = {
            name: this.normalizeText(this.state.name),
            email: this.normalizeText(this.state.email),
            visibility: this.normalizeVisibility(this.state.visibility),
            online: this.normalizeOnline(this.state.online),
            page: this.toPositiveInt(this.state.page, this.defaults.page),
            results: this.toPositiveInt(this.state.results, this.defaults.results),
            orderBy: this.normalizeOrderBy(this.state.orderBy)
        };

        this.setUrlParam(url, 'name', next.name, this.defaults.name);
        this.setUrlParam(url, 'email', next.email, this.defaults.email);
        this.setUrlParam(url, 'visibility', next.visibility, this.defaults.visibility);
        this.setUrlParam(url, 'online', next.online, this.defaults.online);
        this.setUrlParam(url, 'page', String(next.page), String(this.defaults.page));
        this.setUrlParam(url, 'results', String(next.results), String(this.defaults.results));
        this.setUrlParam(url, 'orderBy', next.orderBy, this.defaults.orderBy);

        globalWindow.history.replaceState({}, '', url.pathname + url.search + url.hash);
        return this;
    },

    setUrlParam: function (url, key, value, fallback) {
        if (String(value || '') !== String(fallback || '')) {
            url.searchParams.set(key, String(value));
        } else {
            url.searchParams.delete(key);
        }
    },

    openProfileModal: function (characterId) {
        var self = this;
        var row = this.rowsById[characterId] || {};
        this.currentCharacterId = characterId;
        this.currentCharacterName = this.buildCharacterName(row);
        this.updateLogModalCharacterName();
        this.setProfileLoading(true);
        this.showModal('profile');

        this.requestPost('/admin/characters/get', { character_id: characterId }, function (response) {
            var dataset = (response && response.dataset) ? response.dataset : row;
            self.renderProfile(dataset || {});
            self.setProfileLoading(false);
        });
    },

    setProfileLoading: function (loading) {
        var loadingNode = this.root.querySelector('[data-role="admin-characters-profile-loading"]');
        var contentNode = this.root.querySelector('[data-role="admin-characters-profile-content"]');
        if (loadingNode) {
            loadingNode.classList.toggle('d-none', loading !== true);
        }
        if (contentNode) {
            contentNode.classList.toggle('d-none', loading === true);
        }
    },

    renderProfile: function (data) {
        var avatar = this.normalizeText(data.avatar || '');
        if (avatar === '') {
            avatar = '/assets/imgs/defaults-images/default-profile.png';
        }
        this.currentCharacterName = this.buildCharacterName(data);
        this.updateLogModalCharacterName();

        this.setNodeText('[data-role="admin-characters-profile-name"]', this.currentCharacterName);
        this.setNodeText('[data-role="admin-characters-profile-owner"]', this.currentUserIsSuperuser === true ? this.normalizeNullable(data.email, '-') : 'Privato');
        this.setNodeHtml('[data-role="admin-characters-profile-visibility"]', this.renderVisibilityBadge(data.is_visible));
        this.setNodeHtml('[data-role="admin-characters-profile-online"]', this.renderOnlineBadge(data.online_status));
        this.setNodeText('[data-role="admin-characters-profile-position"]', this.renderPositionText(data));
        this.setNodeText('[data-role="admin-characters-profile-created"]', this.formatDateTime(data.date_created));
        this.setNodeText('[data-role="admin-characters-profile-last-signin"]', this.formatDateTime(data.date_last_signin));
        this.setNodeText('[data-role="admin-characters-profile-last-seed"]', this.formatDateTime(data.date_last_seed));
        this.setNodeText('[data-role="admin-characters-profile-rank"]', this.normalizeNullable(data.rank, '-'));
        this.setNodeText('[data-role="admin-characters-profile-exp"]', this.formatNumber(data.experience));
        this.setNodeText('[data-role="admin-characters-profile-health"]', this.formatHealth(data.health, data.health_max));
        this.setNodeText('[data-role="admin-characters-profile-fame"]', this.formatNumber(data.fame));
        this.setNodeText('[data-role="admin-characters-profile-money"]', this.formatNumber(data.money));
        this.setNodeText('[data-role="admin-characters-profile-bank"]', this.formatNumber(data.bank));
        this.setNodeText('[data-role="admin-characters-profile-gender"]', this.renderGender(data.gender));
        this.setNodeText('[data-role="admin-characters-profile-height"]', this.normalizeNullable(data.height, '-'));
        this.setNodeText('[data-role="admin-characters-profile-weight"]', this.normalizeNullable(data.weight, '-'));
        this.setNodeText('[data-role="admin-characters-profile-eyes"]', this.normalizeNullable(data.eyes, '-'));
        this.setNodeText('[data-role="admin-characters-profile-hair"]', this.normalizeNullable(data.hair, '-'));
        this.setNodeText('[data-role="admin-characters-profile-skin"]', this.normalizeNullable(data.skin, '-'));
        this.setNodeText('[data-role="admin-characters-profile-signs"]', this.normalizeNullable(data.particular_signs, '-'));
        this.setNodeHtml('[data-role="admin-characters-profile-body"]', this.normalizeHtmlSection(data.description_body));
        this.setNodeHtml('[data-role="admin-characters-profile-temper"]', this.normalizeHtmlSection(data.description_temper));
        this.setNodeHtml('[data-role="admin-characters-profile-story"]', this.normalizeHtmlSection(data.background_story));
        this.setNodeText('[data-role="admin-characters-profile-master-notes"]', this.normalizeNullable(data.mod_status, '-'));

        var avatarNode = this.root.querySelector('#admin-characters-profile-avatar');
        if (avatarNode) {
            avatarNode.setAttribute('src', avatar);
        }
    },

    openEditModal: function (characterId) {
        var self = this;
        var row = this.rowsById[characterId] || {};
        this.currentCharacterId = characterId;
        this.currentCharacterName = this.buildCharacterName(row);

        var nameNode = document.querySelector('[data-role="admin-edit-char-name"]');
        if (nameNode) { nameNode.textContent = this.currentCharacterName || '-'; }

        var charIdNode = document.getElementById('admin-edit-char-id');
        if (charIdNode) { charIdNode.value = String(characterId); }

        this.showModal('editChar');
        this.switchEditTab('identity', null);
        this.notifyExtensions('onEditOpen', {
            characterId: characterId,
            modal: document.getElementById('admin-characters-edit-modal')
        });

        this.requestPost('/admin/characters/get', { character_id: characterId }, function (response) {
            var dataset = (response && response.dataset) ? response.dataset : row;
            self.fillEditModal(dataset || {});
            self.notifyExtensions('onEditDataLoaded', {
                characterId: characterId,
                dataset: dataset || {},
                modal: document.getElementById('admin-characters-edit-modal')
            });
        });

        this.loadSocialStatusesForEdit();
    },

    fillEditModal: function (data) {
        var modal = document.getElementById('admin-characters-edit-modal');
        if (!modal) { return; }
        var f = function (name) { return modal.querySelector('[name="' + name + '"]'); };
        var setVal = function (name, val) { var el = f(name); if (el) { el.value = val !== null && val !== undefined ? String(val) : ''; } };

        setVal('ae_surname', data.surname);
        setVal('ae_loanface', data.loanface);
        setVal('ae_height', data.height);
        setVal('ae_weight', data.weight);
        setVal('ae_eyes', data.eyes);
        setVal('ae_hair', data.hair);
        setVal('ae_skin', data.skin);
        setVal('ae_particular_signs', data.particular_signs);
        setVal('ae_description_body', data.description_body);
        setVal('ae_description_temper', data.description_temper);
        setVal('ae_background_story', data.background_story);
        setVal('ae_health', data.health);
        setVal('ae_health_max', data.health_max);
        setVal('ae_rank', data.rank);
        setVal('ae_experience_delta', '');
        setVal('ae_experience_reason', '');
        setVal('ae_money_delta', '');
        setVal('ae_bank_delta', '');
        setVal('ae_fame_set', '');
        setVal('ae_social_status_id', data.socialstatus_id || '');
        setVal('ae_mod_status', data.mod_status);
    },

    loadSocialStatusesForEdit: function () {
        var sel = document.querySelector('#admin-characters-edit-modal [name="ae_social_status_id"]');
        if (!sel || sel.dataset.loaded === '1') { return; }
        var endpoints = globalWindow.LogeonModuleEndpoints || {};
        var socialStatusListEndpoint = String(endpoints.socialStatusList || '').trim();
        if (!socialStatusListEndpoint) {
            return;
        }
        this.requestPost(socialStatusListEndpoint, {}, function (r) {
            var rows = (r && Array.isArray(r.dataset)) ? r.dataset : [];
            while (sel.options.length > 1) { sel.remove(1); }
            for (var i = 0; i < rows.length; i++) {
                var opt = document.createElement('option');
                opt.value = String(rows[i].id || '');
                opt.textContent = String(rows[i].name || rows[i].id || '');
                sel.appendChild(opt);
            }
            sel.dataset.loaded = '1';
        }, function () {});
        
    },

    switchEditTab: function (tab, trigger) {
        var tabKey = String(tab || '').trim();
        var modal = document.getElementById('admin-characters-edit-modal');
        if (!modal) { return; }

        var tabBtns = modal.querySelectorAll('[data-action="admin-edit-tab"]');
        tabBtns.forEach(function (b) { b.classList.remove('active'); });
        if (trigger) { trigger.classList.add('active'); }
        else {
            var activeBtn = modal.querySelector('[data-action="admin-edit-tab"][data-tab="' + tabKey + '"]');
            if (activeBtn) { activeBtn.classList.add('active'); }
        }

        var panels = modal.querySelectorAll('[id^="admin-edit-panel-"]');
        for (var i = 0; i < panels.length; i++) {
            var panel = panels[i];
            if (!panel || !panel.id) {
                continue;
            }
            var panelTab = String(panel.id).replace('admin-edit-panel-', '');
            panel.style.display = panelTab === tabKey ? '' : 'none';
        }
    },

    handleEditSave: function (section) {
        var self = this;
        var charId = parseInt((document.getElementById('admin-edit-char-id') || {}).value || '0', 10) || 0;
        if (!charId) { Toast.show({ body: 'Personaggio non valido.', type: 'error' }); return; }

        var modal = document.getElementById('admin-characters-edit-modal');
        if (!modal) { return; }
        var fv = function (name) {
            var el = modal.querySelector('[name="' + name + '"]');
            return el ? String(el.value || '').trim() : '';
        };

        var payload = { character_id: charId };
        var endpoint = '';

        if (section === 'identity') {
            endpoint = '/admin/characters/admin-edit-identity';
            payload.surname = fv('ae_surname');
            payload.loanface = fv('ae_loanface');
            payload.height = fv('ae_height');
            payload.weight = fv('ae_weight');
            payload.eyes = fv('ae_eyes');
            payload.hair = fv('ae_hair');
            payload.skin = fv('ae_skin');
            payload.particular_signs = fv('ae_particular_signs');
        } else if (section === 'narrative') {
            endpoint = '/admin/characters/admin-edit-narrative';
            payload.description_body = fv('ae_description_body');
            payload.description_temper = fv('ae_description_temper');
            payload.background_story = fv('ae_background_story');
        } else if (section === 'stats') {
            endpoint = '/admin/characters/admin-edit-stats';
            payload.health = fv('ae_health');
            payload.health_max = fv('ae_health_max');
            payload.rank = fv('ae_rank');
            payload.experience_delta = fv('ae_experience_delta');
            payload.experience_reason = fv('ae_experience_reason');
            if (payload.experience_delta !== '' && !payload.experience_reason) {
                Toast.show({ body: 'Inserisci una motivazione per il delta esperienza.', type: 'warning' });
                return;
            }
        } else if (section === 'economy') {
            endpoint = '/admin/characters/admin-edit-economy';
            payload.money_delta = fv('ae_money_delta');
            payload.bank_delta = fv('ae_bank_delta');
            payload.fame_set = fv('ae_fame_set');
            payload.social_status_id = fv('ae_social_status_id');
        } else if (section === 'notes') {
            endpoint = '/admin/characters/admin-edit-notes';
            payload.mod_status = fv('ae_mod_status');
        } else if (this.handleExtensionEditSave(section, {
            characterId: charId,
            modal: modal,
            fv: fv
        })) {
            return;
        } else {
            return;
        }

        if (!endpoint) { return; }

        this.requestPost(endpoint, payload, function () {
            Toast.show({ body: 'Modifiche salvate.', type: 'success' });
            self.loadGrid();
        });
    },

    openLogModal: function (kind, characterId) {
        this.currentCharacterId = characterId;
        var row = this.rowsById[characterId] || {};
        this.currentCharacterName = this.buildCharacterName(row);
        this.updateLogModalCharacterName();

        if (kind === 'experience') {
            this.showModal('experienceLogs');
        } else if (kind === 'economy') {
            this.showModal('economyLogs');
        } else if (kind === 'session') {
            this.showModal('sessionLogs');
        }

        this.loadLogGrid(kind);
        return this;
    },
    updateLogModalCharacterName: function () {
        var nodes = this.root.querySelectorAll('[data-role="admin-characters-log-character-name"]');
        for (var i = 0; i < nodes.length; i++) {
            nodes[i].textContent = this.currentCharacterName || '-';
        }
    },

    ensureLogGrid: function (kind) {
        if (this.logGrids[kind]) {
            return this.logGrids[kind];
        }

        var conf = this.getLogConfig(kind);
        if (!conf || !document.getElementById(conf.gridId)) {
            return null;
        }

        var self = this;
        var grid = new Datagrid(conf.gridId, {
            name: conf.name,
            autoindex: 'id',
            orderable: true,
            thead: true,
            handler: { url: conf.endpoint, action: conf.action },
            nav: { display: 'bottom', urlupdate: 0, results: 10, page: 1 },
            columns: conf.columns,
            onGetDataSuccess: function (response) {
                self.showLogLoading(kind, false);
                self.updateLogEmptyState(kind, response);
            }
        });

        this.logGrids[kind] = grid;
        return grid;
    },

    loadLogGrid: function (kind) {
        var grid = this.ensureLogGrid(kind);
        if (!grid || this.currentCharacterId <= 0) {
            return this;
        }

        this.showLogLoading(kind, true);
        grid.loadData({ character_id: this.currentCharacterId }, 10, 1, 'date_created|DESC');
        return this;
    },

    getLogConfig: function (kind) {
        var self = this;
        if (kind === 'experience') {
            return {
                name: 'AdminCharactersExperienceLogs',
                gridId: 'admin-characters-experience-grid',
                endpoint: '/admin/characters/logs/experience',
                action: 'adminExperienceLogs',
                columns: [
                    { label: 'Data/Ora', field: 'date_created', sortable: true, format: function (r) { return self.formatDateTime(r.date_created); } },
                    { label: 'Delta', sortable: false, format: function (r) { return self.formatSigned(r.delta); } },
                    { label: 'Prima', sortable: false, format: function (r) { return self.formatNumber(r.experience_before); } },
                    { label: 'Dopo', sortable: false, format: function (r) { return self.formatNumber(r.experience_after); } },
                    { label: 'Causale', sortable: false, style: { textAlign: 'left' }, format: function (r) { return self.escapeHtml(r.reason || '-'); } },
                    { label: 'Origine', sortable: false, format: function (r) { return self.escapeHtml(r.source_label || r.source || '-'); } },
                    { label: 'Autore', sortable: false, format: function (r) { return self.escapeHtml(r.author_username || '-'); } }
                ]
            };
        }
        if (kind === 'economy') {
            return {
                name: 'AdminCharactersEconomyLogs',
                gridId: 'admin-characters-economy-grid',
                endpoint: '/admin/characters/logs/economy',
                action: 'adminEconomyLogs',
                columns: [
                    { label: 'Data/Ora', field: 'date_created', sortable: true, format: function (r) { return self.formatDateTime(r.date_created); } },
                    { label: 'Valuta', sortable: false, format: function (r) { return self.escapeHtml(r.currency_label || r.currency_name || '-'); } },
                    { label: 'Importo', sortable: false, format: function (r) { return self.formatSigned(r.amount); } },
                    { label: 'Saldo', sortable: false, format: function (r) { return self.formatNumber(r.balance_after); } },
                    { label: 'Origine', sortable: false, format: function (r) { return self.escapeHtml(r.source_label || r.source || '-'); } },
                    { label: 'Meta', sortable: false, style: { textAlign: 'left' }, format: function (r) { return r.meta_label ? String(r.meta_label) : '-'; } }
                ]
            };
        }
        if (kind === 'session') {
            return {
                name: 'AdminCharactersSessionLogs',
                gridId: 'admin-characters-session-grid',
                endpoint: '/admin/characters/logs/sessions',
                action: 'adminSessionLogs',
                columns: [
                    { label: 'Data/Ora', field: 'date_created', sortable: true, format: function (r) { return self.formatDateTime(r.date_created); } },
                    { label: 'Azione', field: 'action', sortable: true, format: function (r) { return self.renderSessionAction(r.action); } },
                    { label: 'Area', sortable: false, format: function (r) { return self.escapeHtml(r.area || '-'); } },
                    { label: 'URL', sortable: false, style: { textAlign: 'left' }, format: function (r) { return self.escapeHtml(r.url || '-'); } }
                ]
            };
        }
        return null;
    },

    showLogLoading: function (kind, loading) {
        var node = this.root.querySelector('[data-role="admin-characters-' + kind + '-loading"]');
        if (node) {
            node.classList.toggle('d-none', loading !== true);
        }
    },

    updateLogEmptyState: function (kind, response) {
        var node = this.root.querySelector('[data-role="admin-characters-' + kind + '-empty"]');
        if (!node) {
            return;
        }
        var rows = (response && Array.isArray(response.dataset)) ? response.dataset : [];
        node.classList.toggle('d-none', rows.length > 0);
    },

    showModal: function (key) {
        var modal = this.modals[key];
        if (modal && typeof modal.show === 'function') {
            modal.show();
        }
    },

    normalizeVisibility: function (value) {
        value = String(value || '').toLowerCase();
        return (value === 'visible' || value === 'hidden') ? value : 'all';
    },

    normalizeOnline: function (value) {
        value = String(value || '').toLowerCase();
        return (value === 'online' || value === 'offline') ? value : 'all';
    },

    normalizeOrderBy: function (value) {
        var fields = ['name', 'date_created', 'date_last_signin', 'date_last_seed', 'is_visible'];
        var parts = String(value || '').split('|');
        var field = String(parts[0] || '').trim();
        var dir = String(parts[1] || 'ASC').trim().toUpperCase();
        if (fields.indexOf(field) === -1) {
            field = 'name';
        }
        if (dir !== 'DESC') {
            dir = 'ASC';
        }
        return field + '|' + dir;
    },

    normalizeText: function (value) {
        return String(value || '').trim();
    },

    toPositiveInt: function (value, fallback) {
        var parsed = parseInt(value, 10);
        return (!isFinite(parsed) || parsed < 1) ? fallback : parsed;
    },

    datasetInt: function (node, key, fallback) {
        if (!node || !node.dataset) {
            return parseInt(fallback, 10) || 0;
        }
        var parsed = parseInt(node.dataset[key], 10);
        return isNaN(parsed) ? (parseInt(fallback, 10) || 0) : parsed;
    },

    setNodeText: function (selector, value) {
        var node = this.root.querySelector(selector);
        if (node) {
            node.textContent = String(value == null ? '' : value);
        }
    },

    setNodeHtml: function (selector, value) {
        var node = this.root.querySelector(selector);
        if (node) {
            node.innerHTML = String(value == null ? '' : value);
        }
    },

    renderPositionText: function (row) {
        var mapName = this.normalizeText(row.map_name || '');
        var locationName = this.normalizeText(row.location_name || '');
        if (!mapName && !locationName) return 'Alle mappe';
        if (mapName && !locationName) return mapName;
        if (!mapName && locationName) return locationName;
        return mapName + ' / ' + locationName;
    },

    buildCharacterName: function (row) {
        var name = this.normalizeText(row.name || '');
        var surname = this.normalizeText(row.surname || '');
        return (name + ' ' + surname).trim() || 'Personaggio senza nome';
    },

    renderVisibilityBadge: function (raw) {
        return parseInt(raw || 0, 10) === 1
            ? '<span class="badge text-bg-success">Visibile</span>'
            : '<span class="badge text-bg-secondary">Invisibile</span>';
    },

    renderOnlineBadge: function (raw) {
        return String(raw || '').toLowerCase() === 'online'
            ? '<span class="badge text-bg-primary">Online</span>'
            : '<span class="badge text-bg-dark">Offline</span>';
    },

    renderSessionAction: function (raw) {
        var action = String(raw || '').toLowerCase();
        if (action === 'signin') return '<span class="badge text-bg-success">Signin</span>';
        if (action === 'signout') return '<span class="badge text-bg-secondary">Signout</span>';
        return this.escapeHtml(action || '-');
    },

    renderGender: function (raw) {
        var value = parseInt(raw || 0, 10);
        if (value === 1) return 'Maschio';
        if (value === 2) return 'Femmina';
        return '-';
    },

    formatDateTime: function (value) {
        var raw = String(value || '').trim();
        if (!raw) return '-';
        var date = new Date(raw.replace(' ', 'T'));
        if (isNaN(date.getTime())) return this.escapeHtml(raw);
        return date.toLocaleString('it-IT', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' });
    },

    formatNumber: function (value) {
        var parsed = parseFloat(value);
        if (isNaN(parsed)) return '-';
        if (typeof Utils === 'function') return Utils().formatNumber(parsed);
        return String(parsed);
    },

    formatSigned: function (value) {
        var parsed = parseFloat(value);
        if (isNaN(parsed)) return '-';
        return (parsed > 0 ? '+' : '') + this.formatNumber(parsed);
    },

    formatHealth: function (health, healthMax) {
        var current = parseFloat(health);
        var max = parseFloat(healthMax);
        if (isNaN(current)) current = 0;
        if (isNaN(max) || max <= 0) return this.formatNumber(current);
        return this.formatNumber(current) + ' / ' + this.formatNumber(max);
    },

    normalizeNullable: function (value, fallback) {
        var text = String(value == null ? '' : value).trim();
        return text === '' ? (fallback || '-') : text;
    },

    normalizeHtmlSection: function (value) {
        var html = String(value == null ? '' : value).trim();
        if (html === '' || html === '<p><br></p>') {
            return '<span class="text-muted">-</span>';
        }
        return html;
    },

    registerExtension: function (extension) {
        if (!extension || typeof extension !== 'object') {
            return false;
        }
        this.extensions = Array.isArray(this.extensions) ? this.extensions : [];
        if (this.extensions.indexOf(extension) !== -1) {
            return true;
        }
        this.extensions.push(extension);
        return true;
    },

    buildExtensionContext: function (payload) {
        var self = this;
        var data = (payload && typeof payload === 'object') ? payload : {};
        return {
            section: String(data.section || '').trim(),
            characterId: parseInt(data.characterId || 0, 10) || 0,
            modal: data.modal || document.getElementById('admin-characters-edit-modal'),
            dataset: data.dataset || {},
            fv: (typeof data.fv === 'function') ? data.fv : function () { return ''; },
            requestPost: function (url, reqPayload, onSuccess, onError) {
                self.requestPost(url, reqPayload, onSuccess, onError);
            },
            showToast: function (body, type) {
                if (typeof Toast === 'undefined' || !Toast || typeof Toast.show !== 'function') {
                    return;
                }
                Toast.show({
                    body: String(body || ''),
                    type: String(type || 'info')
                });
            },
            switchTab: function (tab) {
                self.switchEditTab(String(tab || ''), null);
            }
        };
    },

    notifyExtensions: function (method, payload) {
        var list = Array.isArray(this.extensions) ? this.extensions : [];
        if (!list.length) {
            return false;
        }

        var handled = false;
        var ctx = this.buildExtensionContext(payload);
        for (var i = 0; i < list.length; i++) {
            var extension = list[i];
            if (!extension || typeof extension[method] !== 'function') {
                continue;
            }
            try {
                extension[method](ctx);
                handled = true;
            } catch (error) {}
        }
        return handled;
    },

    handleExtensionEditSave: function (section, payload) {
        var list = Array.isArray(this.extensions) ? this.extensions : [];
        if (!list.length) {
            return false;
        }

        var handled = false;
        var ctxPayload = Object.assign({}, (payload && typeof payload === 'object') ? payload : {}, {
            section: section
        });
        var ctx = this.buildExtensionContext(ctxPayload);

        for (var i = 0; i < list.length; i++) {
            var extension = list[i];
            if (!extension || typeof extension.handleEditSave !== 'function') {
                continue;
            }
            try {
                if (extension.handleEditSave(ctx) === true) {
                    handled = true;
                }
            } catch (error) {}
        }
        return handled;
    },

    requestPost: function (url, payload, onSuccess, onError) {
        if (typeof Request !== 'function' || !Request.http || typeof Request.http.post !== 'function') {
            Toast.show({ body: 'Servizio non disponibile.', type: 'error' });
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
            var message = 'Operazione non riuscita.';
            if (typeof Request.getErrorMessage === 'function') {
                message = Request.getErrorMessage(error, message);
            }
            Toast.show({ body: message, type: 'error' });
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
    }
};

globalWindow.AdminCharacters = AdminCharacters;
export { AdminCharacters as AdminCharacters };
export default AdminCharacters;

