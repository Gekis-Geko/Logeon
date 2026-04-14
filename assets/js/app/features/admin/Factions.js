(function () {
    'use strict';

    var ROLE_LABELS = { member: 'Membro', leader: 'Leader', advisor: 'Consigliere', agent: 'Agente', initiate: 'Iniziato' };
    var REL_LABELS  = { ally: 'Alleato', neutral: 'Neutrale', rival: 'Rivale', enemy: 'Nemico', vassal: 'Vassallo', overlord: 'Signore' };
    var TYPE_LABELS = { political: 'Politica', military: 'Militare', religious: 'Religiosa', criminal: 'Criminale', mercantile: 'Mercantile', other: 'Altra' };
    var SCOPE_LABELS = { local: 'Locale', regional: 'Regionale', global: 'Globale' };

    var AdminFactions = {
        initialized: false,
        root: null,
        grid: null,
        rowsById: {},
        factionForm: null,
        memberForm: null,
        relationForm: null,
        activeFactionId: 0,
        allFactions: [],
        memberNameInput: null,
        memberIdInput: null,
        memberSuggestions: null,
        memberSearchTimer: null,

        tagsCatalog: [],

        // Filter inputs
        filterSearch: null,
        filterType: null,
        filterScope: null,
        filterIsActive: null,
        filterIsPublic: null,

        init: function () {
            if (this.initialized) { return this; }

            this.root = document.querySelector('#admin-page [data-admin-page="factions"]');
            if (!this.root) { return this; }

            this.factionForm  = document.getElementById('admin-faction-form');
            this.memberForm   = document.getElementById('admin-faction-member-form');
            this.relationForm = document.getElementById('admin-faction-relation-form');
            this.memberNameInput = this.memberForm ? this.memberForm.querySelector('input[name="character_name"]') : null;
            this.memberIdInput = this.memberForm ? this.memberForm.querySelector('input[name="character_id"]') : null;
            this.memberSuggestions = this.memberForm ? this.memberForm.querySelector('[data-role="admin-faction-member-suggestions"]') : null;

            this.filterSearch   = document.getElementById('admin-factions-filter-search');
            this.filterType     = document.getElementById('admin-factions-filter-type');
            this.filterScope    = document.getElementById('admin-factions-filter-scope');
            this.filterIsActive = document.getElementById('admin-factions-filter-is-active');
            this.filterIsPublic = document.getElementById('admin-factions-filter-is-public');

            if (!this.factionForm || !document.getElementById('grid-admin-factions')) { return this; }

            this.bindEvents();
            this.initGrid();
            this.loadGrid();
            this.loadTagCatalog();

            this.initialized = true;
            return this;
        },

        buildFiltersPayload: function () {
            var payload = {};
            var search = this.filterSearch ? String(this.filterSearch.value || '').trim() : '';
            if (search) { payload.search = search; }
            var type = this.filterType ? String(this.filterType.value || '').trim() : '';
            if (type) { payload.type = type; }
            var scope = this.filterScope ? String(this.filterScope.value || '').trim() : '';
            if (scope) { payload.scope = scope; }
            var isActive = this.filterIsActive ? String(this.filterIsActive.value || '').trim() : '';
            if (isActive !== '') { payload.is_active = parseInt(isActive, 10); }
            var isPublic = this.filterIsPublic ? String(this.filterIsPublic.value || '').trim() : '';
            if (isPublic !== '') { payload.is_public = parseInt(isPublic, 10); }
            return payload;
        },

        bindEvents: function () {
            var self = this;

            var filterInputs = [this.filterSearch, this.filterType, this.filterScope, this.filterIsActive, this.filterIsPublic];
            filterInputs.forEach(function (el) {
                if (!el) { return; }
                el.addEventListener('change', function () { self.loadGrid(); });
                if (el.type === 'search') {
                    el.addEventListener('input', function () {
                        if (el.value === '') { self.loadGrid(); }
                    });
                    el.addEventListener('search', function () { self.loadGrid(); });
                }
            });

            this.root.addEventListener('click', function (event) {
                var suggestion = event.target && event.target.closest ? event.target.closest('[data-role="admin-faction-member-suggestion"]') : null;
                if (suggestion) {
                    event.preventDefault();
                    self.selectMemberSuggestion(suggestion);
                    return;
                }

                var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
                if (!trigger) { return; }
                var action = String(trigger.getAttribute('data-action') || '').trim();

                switch (action) {
                    case 'admin-factions-reload':          event.preventDefault(); self.loadGrid(); break;
                    case 'admin-factions-filters-reset':   event.preventDefault(); self.resetFilters(); break;
                    case 'admin-faction-create':           event.preventDefault(); self.openFactionModal('create'); break;
                    case 'admin-faction-save':             event.preventDefault(); self.saveFaction(); break;
                    case 'admin-faction-edit':             event.preventDefault(); self.openFactionModal('edit', self.findRowByTrigger(trigger)); break;
                    case 'admin-faction-delete':           event.preventDefault(); self.confirmFactionDelete(self.findRowByTrigger(trigger)); break;
                    case 'admin-faction-manage':           event.preventDefault(); self.openManagePanel(self.findRowByTrigger(trigger)); break;
                    case 'admin-faction-member-add':       event.preventDefault(); self.addMember(); break;
                    case 'admin-faction-member-remove':    event.preventDefault(); self.removeMember(parseInt(trigger.getAttribute('data-character-id') || '0', 10) || 0); break;
                    case 'admin-faction-relation-set':     event.preventDefault(); self.setRelation(); break;
                    case 'admin-faction-relation-remove':  event.preventDefault(); self.removeRelation(parseInt(trigger.getAttribute('data-target-id') || '0', 10) || 0); break;
                }
            });

            if (this.memberNameInput) {
                this.memberNameInput.addEventListener('input', function () {
                    self.handleMemberSearchInput();
                });
            }

            document.addEventListener('click', function (event) {
                if (!self.memberSuggestions || !self.memberNameInput) { return; }
                if (!event.target.closest || (!event.target.closest('[data-role="admin-faction-member-suggestions"]') && event.target !== self.memberNameInput)) {
                    self.hideMemberSuggestions(true);
                }
            });
        },

        resetFilters: function () {
            if (this.filterSearch)   { this.filterSearch.value   = ''; }
            if (this.filterType)     { this.filterType.value     = ''; }
            if (this.filterScope)    { this.filterScope.value    = ''; }
            if (this.filterIsActive) { this.filterIsActive.value = ''; }
            if (this.filterIsPublic) { this.filterIsPublic.value = ''; }
            this.loadGrid();
        },

        initGrid: function () {
            var self = this;

            this.grid = new Datagrid('grid-admin-factions', {
                name: 'AdminFactions',
                autoindex: 'id',
                orderable: true,
                thead: true,
                handler: { url: '/admin/factions/list', action: 'list' },
                nav: { display: 'bottom', urlupdate: 0, results: 20, page: 1 },
                columns: [
                    { label: 'ID', field: 'id', sortable: true },
                    { label: 'Codice', field: 'code', sortable: true, style: { textAlign: 'left' } },
                    {
                        label: 'Nome', field: 'name', sortable: true, style: { textAlign: 'left' },
                        format: function (row) {
                            var color = row.color_hex || '#6c757d';
                            var icon  = row.icon ? '<img src="' + self.escapeHtml(row.icon) + '" width="16" height="16" class="me-1" style="object-fit:contain;vertical-align:middle;" alt="">' : '';
                            return '<span style="color:' + self.escapeHtml(color) + '">' + icon + self.escapeHtml(row.name || '') + '</span>';
                        }
                    },
                    {
                        label: 'Tipo', field: 'type', sortable: true,
                        format: function (row) { return TYPE_LABELS[row.type] || self.escapeHtml(row.type || ''); }
                    },
                    {
                        label: 'Portata', field: 'scope', sortable: true,
                        format: function (row) { return SCOPE_LABELS[row.scope] || self.escapeHtml(row.scope || ''); }
                    },
                    { label: 'Potere', field: 'power_level', sortable: true },
                    {
                        label: 'Pubblica', field: 'is_public', sortable: true,
                        format: function (row) {
                            return parseInt(row.is_public, 10) === 1
                                ? '<span class="badge text-bg-success">Si</span>'
                                : '<span class="badge text-bg-secondary">No</span>';
                        }
                    },
                    {
                        label: 'Adesioni', field: 'allow_join_requests', sortable: false,
                        format: function (row) {
                            return parseInt(row.allow_join_requests, 10) === 1
                                ? '<span class="badge text-bg-info">Aperte</span>'
                                : '<span class="badge text-bg-secondary">Chiuse</span>';
                        }
                    },
                    {
                        label: 'Attiva', field: 'is_active', sortable: true,
                        format: function (row) {
                            return parseInt(row.is_active, 10) === 1
                                ? '<span class="badge text-bg-success">Si</span>'
                                : '<span class="badge text-bg-secondary">No</span>';
                        }
                    },
                    {
                        label: 'Azioni', sortable: false, style: { textAlign: 'left' },
                        format: function (row) {
                            var id = parseInt(row.id, 10) || 0;
                            if (id > 0) { self.rowsById[id] = row; }
                            return '<div class="d-flex flex-wrap gap-1">'
                                + '<button type="button" class="btn btn-sm btn-outline-secondary" data-action="admin-faction-manage" data-id="' + id + '">Gestisci</button>'
                                + '<button type="button" class="btn btn-sm btn-outline-primary" data-action="admin-faction-edit" data-id="' + id + '">Modifica</button>'
                                + '<button type="button" class="btn btn-sm btn-outline-danger" data-action="admin-faction-delete" data-id="' + id + '">Elimina</button>'
                                + '</div>';
                        }
                    }
                ]
            });
        },

        loadGrid: function () {
            if (!this.grid) { return this; }
            this.rowsById = {};
            this.grid.loadData(this.buildFiltersPayload(), 20, 1, 'name|ASC');
            this.loadAllFactions();
            return this;
        },

        loadAllFactions: function () {
            var self = this;
            this.requestPost('/admin/factions/list', { limit: 200, page: 1 }, function (response) {
                var dataset = (response && response.dataset) ? response.dataset : [];
                if (dataset && Array.isArray(dataset.rows)) {
                    self.allFactions = dataset.rows;
                } else if (Array.isArray(dataset)) {
                    self.allFactions = dataset;
                } else {
                    self.allFactions = [];
                }
                self.renderRelationFactionSelect();
            });
        },

        loadTagCatalog: function () {
            var self = this;
            this.requestPost('/list/narrative-tags', { entity_type: 'faction' }, function (res) {
                self.tagsCatalog = (res && Array.isArray(res.dataset)) ? res.dataset : [];
            });
        },

        renderTagCheckboxes: function (selectedIds) {
            var container = document.getElementById('admin-faction-tags-container');
            if (!container) { return; }
            if (!this.tagsCatalog.length) {
                container.innerHTML = '<span class="text-muted small">Nessun tag disponibile.</span>';
                return;
            }
            var selected = {};
            for (var i = 0; i < selectedIds.length; i++) {
                selected[parseInt(selectedIds[i], 10)] = true;
            }
            var html = '';
            for (var j = 0; j < this.tagsCatalog.length; j++) {
                var tag = this.tagsCatalog[j];
                var id = parseInt(tag.id || '0', 10);
                if (!id) { continue; }
                var checked = selected[id] ? ' checked' : '';
                html += '<div class="form-check form-check-inline mb-0">'
                    + '<input class="form-check-input" type="checkbox" value="' + id + '" id="fac-tag-' + id + '"' + checked + '>'
                    + '<label class="form-check-label small" for="fac-tag-' + id + '">' + this.escapeHtml(tag.label || tag.slug || ('Tag #' + id)) + '</label>'
                    + '</div>';
            }
            container.innerHTML = html || '<span class="text-muted small">Nessun tag disponibile.</span>';
        },

        collectTagIds: function () {
            var container = document.getElementById('admin-faction-tags-container');
            if (!container) { return []; }
            var checks = container.querySelectorAll('input[type="checkbox"]:checked');
            var ids = [];
            for (var i = 0; i < checks.length; i++) {
                var v = parseInt(checks[i].value || '0', 10);
                if (v > 0) { ids.push(v); }
            }
            return ids;
        },

        findRowByTrigger: function (trigger) {
            var id = parseInt(trigger.getAttribute('data-id') || '0', 10) || 0;
            return id > 0 ? (this.rowsById[id] || null) : null;
        },

        openFactionModal: function (mode, row) {
            var createMode = (mode !== 'edit');

            if (this.factionForm) {
                this.factionForm.reset();
                var f = this.factionForm.elements;
                f.id.value                  = '';
                f.type.value                = 'political';
                f.scope.value               = 'regional';
                f.power_level.value         = '5';
                f.is_public.value           = '1';
                f.is_active.value           = '1';
                f.allow_join_requests.value = '0';
            }

            if (!createMode && row && this.factionForm) {
                var f = this.factionForm.elements;
                f.id.value                  = String(row.id || '');
                f.code.value                = row.code || '';
                f.name.value                = row.name || '';
                f.description.value         = row.description || '';
                f.type.value                = row.type || 'political';
                f.scope.value               = row.scope || 'regional';
                f.alignment.value           = row.alignment || '';
                f.power_level.value         = String(row.power_level != null ? row.power_level : '5');
                f.is_public.value           = parseInt(row.is_public, 10) === 1 ? '1' : '0';
                f.is_active.value           = parseInt(row.is_active, 10) === 1 ? '1' : '0';
                f.allow_join_requests.value = parseInt(row.allow_join_requests, 10) === 1 ? '1' : '0';
                f.color_hex.value           = row.color_hex || '';
                f.icon.value                = row.icon || '';
            }

            this.renderTagCheckboxes([]);
            if (!createMode && row && row.id) {
                var self = this;
                this.requestPost('/admin/factions/get', { id: row.id }, function (res) {
                    var tagIds = res && res.dataset ? (res.dataset.narrative_tag_ids || []) : [];
                    self.renderTagCheckboxes(tagIds);
                });
            }

            this.showModal('admin-faction-modal');
        },

        saveFaction: function () {
            if (!this.factionForm) { return; }
            var f = this.factionForm.elements;
            var payload = {
                code:                 String(f.code.value || '').trim(),
                name:                 String(f.name.value || '').trim(),
                description:          String(f.description.value || '').trim(),
                type:                 String(f.type.value || 'political').trim(),
                scope:                String(f.scope.value || 'regional').trim(),
                alignment:            String(f.alignment.value || '').trim(),
                power_level:          parseInt(f.power_level.value || '5', 10) || 5,
                is_public:            parseInt(f.is_public.value || '1', 10) === 1 ? 1 : 0,
                is_active:            parseInt(f.is_active.value || '1', 10) === 1 ? 1 : 0,
                allow_join_requests:  parseInt(f.allow_join_requests.value || '0', 10) === 1 ? 1 : 0,
                color_hex:            String(f.color_hex.value || '').trim(),
                icon:                 String(f.icon.value || '').trim(),
                tag_ids:              this.collectTagIds()
            };

            if (!payload.code || !payload.name) {
                Toast.show({ body: 'Codice e nome sono obbligatori.', type: 'warning' });
                return;
            }

            var id = parseInt(f.id.value || '0', 10) || 0;
            if (id > 0) { payload.id = id; }

            var isEdit   = id > 0;
            var endpoint = isEdit ? '/admin/factions/update' : '/admin/factions/create';
            var self     = this;

            this.requestPost(endpoint, payload, function () {
                self.hideModal('admin-faction-modal');
                Toast.show({ body: isEdit ? 'Fazione aggiornata.' : 'Fazione creata.', type: 'success' });
                self.loadGrid();
            });
        },

        confirmFactionDelete: function (row) {
            if (!row || !row.id) { return; }
            var self = this;
            Dialog('danger', {
                title: 'Elimina fazione',
                body: '<p>Confermi l\'eliminazione di <b>' + this.escapeHtml(row.name || row.code || '') + '</b>?</p>'
                    + '<p class="small text-muted">Le membership attive bloccano l\'eliminazione.</p>'
            }, function () {
                self.hideConfirmDialog();
                self.requestPost('/admin/factions/delete', { id: row.id }, function () {
                    Toast.show({ body: 'Fazione eliminata.', type: 'success' });
                    self.loadGrid();
                });
            }).show();
        },

        openManagePanel: function (row) {
            if (!row || !row.id) { return; }
            this.activeFactionId = parseInt(row.id, 10) || 0;
            var titleEl = document.getElementById('admin-faction-manage-title');
            if (titleEl) { titleEl.textContent = 'Gestione: ' + (row.name || row.code || ''); }
            if (this.memberForm) { this.memberForm.reset(); }
            if (this.memberIdInput) { this.memberIdInput.value = ''; }
            this.hideMemberSuggestions(true);
            this.loadMembers();
            this.loadRelations();
            this.showModal('admin-faction-manage-modal');
        },

        loadMembers: function () {
            if (!this.activeFactionId) { return; }
            var self = this;
            this.requestPost('/admin/factions/members/list', { faction_id: this.activeFactionId }, function (response) {
                self.renderMembers((response && response.dataset) ? response.dataset : []);
            });
        },

        renderMembers: function (rows) {
            var container = document.getElementById('admin-faction-members-list');
            if (!container) { return; }
            if (!rows.length) {
                container.innerHTML = '<div class="small text-muted">Nessun membro.</div>';
                return;
            }
            var self = this;
            container.innerHTML = rows.map(function (m) {
                var roleLabel = ROLE_LABELS[m.role] || self.escapeHtml(m.role || 'member');
                return '<div class="d-flex justify-content-between align-items-center py-1 border-bottom">'
                    + '<div>'
                    + '<span class="small fw-bold">' + self.escapeHtml(m.character_name || '#' + m.character_id) + '</span>'
                    + ' <span class="badge text-bg-secondary">' + roleLabel + '</span>'
                    + (m.rank ? ' <span class="small text-muted">' + self.escapeHtml(m.rank) + '</span>' : '')
                    + '</div>'
                    + '<button type="button" class="btn btn-sm btn-outline-danger" data-action="admin-faction-member-remove" data-character-id="' + parseInt(m.character_id, 10) + '">Rimuovi</button>'
                    + '</div>';
            }).join('');
        },

        addMember: function () {
            if (!this.memberForm || !this.activeFactionId) { return; }
            var f           = this.memberForm.elements;
            var characterId = parseInt(f.character_id.value || '0', 10) || 0;
            if (characterId <= 0) { Toast.show({ body: 'Seleziona un personaggio dalla lista.', type: 'warning' }); return; }
            var self = this;
            this.requestPost('/admin/factions/members/add', {
                faction_id:   this.activeFactionId,
                character_id: characterId,
                role:         String(f.role.value || 'member').trim(),
                rank:         String(f.rank.value || '').trim()
            }, function () {
                Toast.show({ body: 'Membro aggiunto.', type: 'success' });
                self.memberForm.reset();
                if (self.memberIdInput) { self.memberIdInput.value = ''; }
                self.hideMemberSuggestions(true);
                self.loadMembers();
            });
        },

        handleMemberSearchInput: function () {
            var self = this;
            if (!this.memberNameInput || !this.memberIdInput) { return; }
            var query = String(this.memberNameInput.value || '').trim();
            this.memberIdInput.value = '';

            if (this.memberSearchTimer) {
                window.clearTimeout(this.memberSearchTimer);
                this.memberSearchTimer = null;
            }
            if (query.length < 2) {
                this.hideMemberSuggestions(true);
                return;
            }

            this.memberSearchTimer = window.setTimeout(function () {
                self.requestPost('/list/characters/search', { query: query }, function (response) {
                    self.renderMemberSuggestions(response && response.dataset ? response.dataset : []);
                }, function () {
                    self.hideMemberSuggestions(true);
                });
            }, 180);
        },

        renderMemberSuggestions: function (rows) {
            if (!this.memberSuggestions) { return; }
            this.memberSuggestions.innerHTML = '';
            if (!Array.isArray(rows) || rows.length === 0) {
                this.memberSuggestions.classList.add('d-none');
                return;
            }

            for (var i = 0; i < rows.length; i += 1) {
                var row = rows[i] || {};
                var id = parseInt(row.id || '0', 10) || 0;
                if (id <= 0) { continue; }
                var label = (String(row.name || '') + ' ' + String(row.surname || '')).trim();
                if (!label) { label = 'PG #' + id; }

                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'list-group-item list-group-item-action small py-1';
                btn.setAttribute('data-role', 'admin-faction-member-suggestion');
                btn.setAttribute('data-character-id', String(id));
                btn.setAttribute('data-character-label', label);
                btn.textContent = label;
                this.memberSuggestions.appendChild(btn);
            }

            if (this.memberSuggestions.children.length === 0) {
                this.memberSuggestions.classList.add('d-none');
                return;
            }
            this.memberSuggestions.classList.remove('d-none');
        },

        selectMemberSuggestion: function (node) {
            if (!node) { return; }
            var characterId = parseInt(node.getAttribute('data-character-id') || '0', 10) || 0;
            var label = String(node.getAttribute('data-character-label') || '').trim();
            if (characterId <= 0 || !this.memberNameInput || !this.memberIdInput) { return; }
            this.memberIdInput.value = String(characterId);
            this.memberNameInput.value = label;
            this.hideMemberSuggestions(true);
        },

        hideMemberSuggestions: function (clear) {
            if (!this.memberSuggestions) { return; }
            this.memberSuggestions.classList.add('d-none');
            if (clear === true) {
                this.memberSuggestions.innerHTML = '';
            }
        },

        removeMember: function (characterId) {
            if (!this.activeFactionId || !characterId) { return; }
            var self = this;
            this.requestPost('/admin/factions/members/remove', {
                faction_id: this.activeFactionId, character_id: characterId
            }, function () {
                Toast.show({ body: 'Membro rimosso.', type: 'success' });
                self.loadMembers();
            });
        },

        loadRelations: function () {
            if (!this.activeFactionId) { return; }
            var self = this;
            this.requestPost('/admin/factions/relations/list', { faction_id: this.activeFactionId }, function (response) {
                self.renderRelations((response && response.dataset) ? response.dataset : []);
            });
        },

        renderRelations: function (rows) {
            var container = document.getElementById('admin-faction-relations-list');
            if (!container) { return; }
            if (!rows.length) {
                container.innerHTML = '<div class="small text-muted">Nessuna relazione.</div>';
                return;
            }
            var colorMap = { ally:'text-bg-success', neutral:'text-bg-secondary', rival:'text-bg-warning', enemy:'text-bg-danger', vassal:'text-bg-info', overlord:'text-bg-primary' };
            var self = this;
            container.innerHTML = rows.map(function (r) {
                var rel = r.relation_type || 'neutral';
                var relLabel = REL_LABELS[rel] || self.escapeHtml(rel);
                return '<div class="d-flex justify-content-between align-items-center py-1 border-bottom">'
                    + '<div>'
                    + '<span class="small fw-bold">' + self.escapeHtml(r.target_faction_name || '#' + r.target_faction_id) + '</span>'
                    + ' <span class="badge ' + (colorMap[rel] || 'text-bg-secondary') + '">' + relLabel + '</span>'
                    + ' <span class="small text-muted">int.' + parseInt(r.intensity, 10) + '/10</span>'
                    + '</div>'
                    + '<button type="button" class="btn btn-sm btn-outline-danger" data-action="admin-faction-relation-remove" data-target-id="' + parseInt(r.target_faction_id, 10) + '">Rimuovi</button>'
                    + '</div>';
            }).join('');
        },

        renderRelationFactionSelect: function () {
            var select = document.getElementById('admin-faction-relation-target-select');
            if (!select) { return; }
            var self = this;
            select.innerHTML = '<option value="">Seleziona fazione...</option>';
            this.allFactions.forEach(function (f) {
                if (parseInt(f.id, 10) === self.activeFactionId) { return; }
                var opt = document.createElement('option');
                opt.value = f.id;
                opt.textContent = f.name + ' (' + f.code + ')';
                select.appendChild(opt);
            });
        },

        setRelation: function () {
            if (!this.relationForm || !this.activeFactionId) { return; }
            var f        = this.relationForm.elements;
            var targetId = parseInt(f.target_faction_id.value || '0', 10) || 0;
            if (targetId <= 0) { Toast.show({ body: 'Seleziona una fazione target.', type: 'warning' }); return; }
            var self = this;
            this.requestPost('/admin/factions/relations/set', {
                faction_id:        this.activeFactionId,
                target_faction_id: targetId,
                relation_type:     String(f.relation_type.value || 'neutral').trim(),
                intensity:         parseInt(f.intensity.value || '5', 10) || 5,
                notes:             String(f.notes.value || '').trim()
            }, function () {
                Toast.show({ body: 'Relazione aggiornata.', type: 'success' });
                self.relationForm.reset();
                self.loadRelations();
            });
        },

        removeRelation: function (targetFactionId) {
            if (!this.activeFactionId || !targetFactionId) { return; }
            var self = this;
            this.requestPost('/admin/factions/relations/remove', {
                faction_id: this.activeFactionId, target_faction_id: targetFactionId
            }, function () {
                Toast.show({ body: 'Relazione rimossa.', type: 'success' });
                self.loadRelations();
            });
        },

        requestPost: function (url, payload, onSuccess, onError) {
            var self = this;
            if (!window.Request || !Request.http || typeof Request.http.post !== 'function') {
                Toast.show({ body: 'Servizio non disponibile.', type: 'error' });
                return this;
            }
            Request.http.post(url, payload || {}).then(function (r) {
                if (typeof onSuccess === 'function') { onSuccess(r || null); }
            }).catch(function (e) {
                if (typeof onError === 'function') { onError(e); return; }
                Toast.show({ body: self.requestErrorMessage(e), type: 'error' });
            });
            return this;
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

        hideConfirmDialog: function () {
            if (window.SystemDialogs && typeof window.SystemDialogs.ensureGeneralConfirm === 'function') {
                var d = window.SystemDialogs.ensureGeneralConfirm();
                if (d && typeof d.hide === 'function') { d.hide(); }
            } else if (window.generalConfirm && typeof window.generalConfirm.hide === 'function') {
                window.generalConfirm.hide();
            }
        },

        escapeHtml: function (value) {
            return String(value || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
        }
    };

    window.AdminFactions = AdminFactions;
})();
