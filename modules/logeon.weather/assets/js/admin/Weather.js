(function () {
    'use strict';

    var AdminWeather = {
        initialized: false,
        root: null,
        grid: null,
        locationGrid: null,
        rowsById: {},
        locationRowsById: {},
        climateForm: null,
        pendingDeleteId: 0,
        weatherOptions: null,
        allAreas: [],

        worldIdInput: null,
        worldWeatherSelect: null,
        worldDegreesInput: null,
        worldMoonSelect: null,
        worldOptions: [],

        // Weather & Climate v2
        weatherTypeGrid: null,
        seasonGrid: null,
        climateZoneGrid: null,
        profileGrid: null,
        assignmentGrid: null,
        overrideGrid: null,

        weatherTypeRowsById: {},
        seasonRowsById: {},
        climateZoneRowsById: {},
        profileRowsById: {},
        assignmentRowsById: {},
        overrideRowsById: {},

        weatherTypeForm: null,
        seasonForm: null,
        climateZoneForm: null,
        profileForm: null,
        assignmentForm: null,
        overrideForm: null,
        profileWeightsProfileIdInput: null,
        profileWeightsList: null,

        weatherTypesRef: [],
        seasonsRef: [],
        climateZonesRef: [],
        scopeMaps: [],
        scopeLocations: [],
        filterTimers: {},

        init: function () {
            if (this.initialized) { return this; }

            this.root = document.querySelector('#admin-page [data-admin-page^="weather"]');
            if (!this.root) { return this; }

            this.climateForm = document.getElementById('admin-climate-form');

            this.worldIdInput = document.getElementById('admin-world-id');
            this.worldWeatherSelect = document.getElementById('admin-world-weather-key');
            this.worldDegreesInput = document.getElementById('admin-world-degrees');
            this.worldMoonSelect = document.getElementById('admin-world-moon-phase');

            this.weatherTypeForm = document.getElementById('admin-weather-type-form');
            this.seasonForm = document.getElementById('admin-weather-season-form');
            this.climateZoneForm = document.getElementById('admin-weather-zone-form');
            this.profileForm = document.getElementById('admin-weather-profile-form');
            this.assignmentForm = document.getElementById('admin-weather-assignment-form');
            this.overrideForm = document.getElementById('admin-weather-override-form');
            this.profileWeightsProfileIdInput = document.getElementById('admin-weather-profile-weights-profile-id');
            this.profileWeightsList = this.root ? this.root.querySelector('[data-weather-weights-list]') : null;

            this.bindEvents();
            this.loadWeatherOptions();
            this.loadWorldOptions();
            this.loadScopeCatalogs();
            this.loadAllAreas();
            this.initGrid();
            this.initLocationGrid();
            this.loadGrid();
            this.loadLocationGrid();
            this.initWeatherTypeGrid();
            this.initSeasonGrid();
            this.initClimateZoneGrid();
            this.initProfileGrid();
            this.initAssignmentGrid();
            this.initOverrideGrid();
            this.loadWeatherClimateReferences();
            this.loadWeatherTypeGrid();
            this.loadSeasonGrid();
            this.loadClimateZoneGrid();
            this.loadProfileGrid();
            this.loadAssignmentGrid();
            this.loadOverrideGrid();

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
                    case 'admin-weather-reload-all':      event.preventDefault(); self.reloadAllClimateData(); break;

                    case 'admin-climate-reload':           event.preventDefault(); self.loadGrid(); break;
                    case 'admin-climate-create':           event.preventDefault(); self.openModal('create'); break;
                    case 'admin-climate-save':             event.preventDefault(); self.saveArea(); break;
                    case 'admin-climate-edit':             event.preventDefault(); self.openModal('edit', self.findRow(trigger)); break;
                    case 'admin-climate-delete':           event.preventDefault(); self.confirmDelete(self.findRow(trigger)); break;
                    case 'admin-climate-delete-confirm':   event.preventDefault(); self.deleteArea(); break;
                    case 'admin-climate-locations-reload': event.preventDefault(); self.loadLocationGrid(); break;
                    case 'admin-climate-location-assign':  event.preventDefault(); self.assignLocation(trigger); break;
                    case 'admin-weather-world-load':       event.preventDefault(); self.loadWorldState(); break;
                    case 'admin-weather-world-save':       event.preventDefault(); self.saveWorldOverride(); break;
                    case 'admin-weather-world-clear':      event.preventDefault(); self.clearWorldOverride(); break;

                    case 'admin-weather-types-reload':     event.preventDefault(); self.loadWeatherTypeGrid(); break;
                    case 'admin-weather-types-create':     event.preventDefault(); self.openWeatherTypeModal('create'); break;
                    case 'admin-weather-types-edit':       event.preventDefault(); self.openWeatherTypeModal('edit', self.findEntityRow(trigger, self.weatherTypeRowsById)); break;
                    case 'admin-weather-types-save':       event.preventDefault(); self.saveWeatherType(); break;
                    case 'admin-weather-types-delete':     event.preventDefault(); self.deleteWeatherType(self.findEntityRow(trigger, self.weatherTypeRowsById)); break;

                    case 'admin-weather-seasons-reload':   event.preventDefault(); self.loadSeasonGrid(); break;
                    case 'admin-weather-seasons-create':   event.preventDefault(); self.openSeasonModal('create'); break;
                    case 'admin-weather-seasons-edit':     event.preventDefault(); self.openSeasonModal('edit', self.findEntityRow(trigger, self.seasonRowsById)); break;
                    case 'admin-weather-seasons-save':     event.preventDefault(); self.saveSeason(); break;
                    case 'admin-weather-seasons-delete':   event.preventDefault(); self.deleteSeason(self.findEntityRow(trigger, self.seasonRowsById)); break;

                    case 'admin-weather-zones-reload':     event.preventDefault(); self.loadClimateZoneGrid(); break;
                    case 'admin-weather-zones-create':     event.preventDefault(); self.openClimateZoneModal('create'); break;
                    case 'admin-weather-zones-edit':       event.preventDefault(); self.openClimateZoneModal('edit', self.findEntityRow(trigger, self.climateZoneRowsById)); break;
                    case 'admin-weather-zones-save':       event.preventDefault(); self.saveClimateZone(); break;
                    case 'admin-weather-zones-delete':     event.preventDefault(); self.deleteClimateZone(self.findEntityRow(trigger, self.climateZoneRowsById)); break;

                    case 'admin-weather-profiles-reload':  event.preventDefault(); self.loadProfileGrid(); break;
                    case 'admin-weather-profiles-create':  event.preventDefault(); self.openProfileModal('create'); break;
                    case 'admin-weather-profiles-edit':    event.preventDefault(); self.openProfileModal('edit', self.findEntityRow(trigger, self.profileRowsById)); break;
                    case 'admin-weather-profiles-weights': event.preventDefault(); self.openProfileWeightsModal(self.findEntityRow(trigger, self.profileRowsById)); break;
                    case 'admin-weather-profiles-save':    event.preventDefault(); self.saveProfile(); break;
                    case 'admin-weather-profiles-delete':  event.preventDefault(); self.deleteProfile(self.findEntityRow(trigger, self.profileRowsById)); break;
                    case 'admin-weather-profile-weight-add-row': event.preventDefault(); self.addProfileWeightRow(); break;
                    case 'admin-weather-profile-weight-remove-row':
                        event.preventDefault();
                        var weightRow = trigger.closest('[data-weather-weight-row]');
                        if (weightRow && weightRow.parentNode) {
                            weightRow.parentNode.removeChild(weightRow);
                        }
                        if (self.profileWeightsList && self.profileWeightsList.children.length === 0) {
                            self.addProfileWeightRow();
                        }
                        break;
                    case 'admin-weather-profile-weights-save': event.preventDefault(); self.saveProfileWeights(); break;

                    case 'admin-weather-assignments-reload': event.preventDefault(); self.loadAssignmentGrid(); break;
                    case 'admin-weather-assignments-create': event.preventDefault(); self.openAssignmentModal('create'); break;
                    case 'admin-weather-assignments-edit': event.preventDefault(); self.openAssignmentModal('edit', self.findEntityRow(trigger, self.assignmentRowsById)); break;
                    case 'admin-weather-assignments-save': event.preventDefault(); self.saveAssignment(); break;
                    case 'admin-weather-assignments-delete': event.preventDefault(); self.deleteAssignment(self.findEntityRow(trigger, self.assignmentRowsById)); break;

                    case 'admin-weather-overrides-reload': event.preventDefault(); self.loadOverrideGrid(); break;
                    case 'admin-weather-overrides-create': event.preventDefault(); self.openOverrideModal('create'); break;
                    case 'admin-weather-overrides-edit':   event.preventDefault(); self.openOverrideModal('edit', self.findEntityRow(trigger, self.overrideRowsById)); break;
                    case 'admin-weather-overrides-save':   event.preventDefault(); self.saveOverride(); break;
                    case 'admin-weather-overrides-delete': event.preventDefault(); self.deleteOverride(self.findEntityRow(trigger, self.overrideRowsById)); break;

                    case 'admin-weather-types-reset-filters': event.preventDefault(); self.resetFilters('types'); break;
                    case 'admin-weather-seasons-reset-filters': event.preventDefault(); self.resetFilters('seasons'); break;
                    case 'admin-weather-zones-reset-filters': event.preventDefault(); self.resetFilters('zones'); break;
                    case 'admin-weather-profiles-reset-filters': event.preventDefault(); self.resetFilters('profiles'); break;
                    case 'admin-weather-assignments-reset-filters': event.preventDefault(); self.resetFilters('assignments'); break;
                    case 'admin-weather-overrides-reset-filters': event.preventDefault(); self.resetFilters('overrides'); break;
                    case 'admin-climate-areas-reset-filters': event.preventDefault(); self.resetFilters('climate-areas'); break;
                    case 'admin-climate-locations-reset-filters': event.preventDefault(); self.resetFilters('climate-locations'); break;
                    case 'admin-weather-scope-pick': event.preventDefault(); self.pickScopeSuggestion(trigger); break;
                }
            });

            document.addEventListener('click', function (event) {
                if (!self.root) { return; }
                if (!self.root.contains(event.target)) {
                    self.hideScopeSuggestions();
                    return;
                }
                if (!event.target.closest('[data-role=\"admin-weather-assignment-scope-suggestions\"], [data-role=\"admin-weather-override-scope-suggestions\"], [name=\"scope_label\"]')) {
                    self.hideScopeSuggestions();
                }
            });

            this.root.addEventListener('input', function (event) {
                var target = event.target;
                if (!target) { return; }

                if (target.matches && target.matches('[data-weather-filter-target]')) {
                    self.queueFilterReload(String(target.getAttribute('data-weather-filter-target') || ''), 220);
                    return;
                }

                if (target.name === 'scope_label') {
                    var form = target.closest('form');
                    if (!form) { return; }
                    self.syncScopeHidden(form);
                    self.renderScopeSuggestions(form, target.value || '');
                }
            });

            this.root.addEventListener('change', function (event) {
                var target = event.target;
                if (!target) { return; }

                if (target.id === 'admin-world-id') {
                    self.loadWorldState();
                    return;
                }

                if (target.matches && target.matches('[data-weather-filter-target]')) {
                    self.queueFilterReload(String(target.getAttribute('data-weather-filter-target') || ''), 0);
                    return;
                }

                if (target.name === 'scope_type') {
                    var form = target.closest('form');
                    if (!form) { return; }
                    self.resetScopeSelection(form);
                    self.renderScopeSuggestions(form, '');
                }
            });

            this.root.addEventListener('submit', function (event) {
                var form = event.target;
                if (!form || !form.matches) { return; }
                if (form.matches('[data-role=\"admin-weather-types-filters\"], [data-role=\"admin-weather-seasons-filters\"], [data-role=\"admin-weather-zones-filters\"], [data-role=\"admin-weather-profiles-filters\"], [data-role=\"admin-weather-assignments-filters\"], [data-role=\"admin-weather-overrides-filters\"], [data-role=\"admin-climate-areas-filters\"], [data-role=\"admin-climate-locations-filters\"]')) {
                    event.preventDefault();
                }
            });
        },

        queueFilterReload: function (target, delayMs) {
            var key = String(target || '').trim();
            if (!key) { return; }
            var self = this;
            var delay = parseInt(delayMs || 0, 10);
            if (!isFinite(delay) || delay < 0) { delay = 0; }
            if (this.filterTimers[key]) {
                window.clearTimeout(this.filterTimers[key]);
            }
            this.filterTimers[key] = window.setTimeout(function () {
                self.reloadTargetGrid(key);
            }, delay);
        },

        reloadTargetGrid: function (target) {
            switch (String(target || '').trim()) {
                case 'weather-types': this.loadWeatherTypeGrid(); break;
                case 'weather-seasons': this.loadSeasonGrid(); break;
                case 'weather-zones': this.loadClimateZoneGrid(); break;
                case 'weather-profiles': this.loadProfileGrid(); break;
                case 'weather-assignments': this.loadAssignmentGrid(); break;
                case 'weather-overrides': this.loadOverrideGrid(); break;
                case 'climate-areas': this.loadGrid(); break;
                case 'climate-locations': this.loadLocationGrid(); break;
            }
        },

        resetFilters: function (section) {
            var key = String(section || '').trim();
            if (!key || !this.root) { return; }

            var map = {
                'types': '[data-role=\"admin-weather-types-filters\"]',
                'seasons': '[data-role=\"admin-weather-seasons-filters\"]',
                'zones': '[data-role=\"admin-weather-zones-filters\"]',
                'profiles': '[data-role=\"admin-weather-profiles-filters\"]',
                'assignments': '[data-role=\"admin-weather-assignments-filters\"]',
                'overrides': '[data-role=\"admin-weather-overrides-filters\"]',
                'climate-areas': '[data-role=\"admin-climate-areas-filters\"]',
                'climate-locations': '[data-role=\"admin-climate-locations-filters\"]'
            };

            var selector = map[key] || '';
            if (!selector) { return; }
            var form = this.root.querySelector(selector);
            if (form && typeof form.reset === 'function') {
                form.reset();
            }

            var targetMap = {
                'types': 'weather-types',
                'seasons': 'weather-seasons',
                'zones': 'weather-zones',
                'profiles': 'weather-profiles',
                'assignments': 'weather-assignments',
                'overrides': 'weather-overrides',
                'climate-areas': 'climate-areas',
                'climate-locations': 'climate-locations'
            };
            this.reloadTargetGrid(targetMap[key] || '');
        },

        loadWeatherOptions: function () {
            var self = this;
            this.requestPost('/weather/options', {}, function (response) {
                if (response && response.dataset) {
                    self.weatherOptions = response.dataset;
                    self.populateOptionSelects();
                    self.renderWorldStateSummary(null);
                }
            });
        },

        loadWorldOptions: function (preferredWorldId) {
            var self = this;
            this.requestPost('/weather/world/options', {}, function (response) {
                var list = (response && Array.isArray(response.dataset)) ? response.dataset : [];
                self.worldOptions = list;
                self.populateWorldSelect(preferredWorldId);
                self.loadWorldState();
            });
        },

        populateWorldSelect: function (preferredWorldId) {
            if (!this.worldIdInput) { return; }

            var current = String(preferredWorldId || this.worldIdInput.value || '');
            var options = [];
            if (Array.isArray(this.worldOptions)) {
                options = this.worldOptions.slice();
            }

            if (options.length === 0) {
                options = [{ id: 1, name: 'Mondo 1', has_override: false }];
            }

            var html = '';
            options.forEach(function (item) {
                var id = parseInt(item.id || '0', 10);
                if (id <= 0) { return; }
                var label = String(item.name || ('Mondo ' + id));
                if (item.has_override) {
                    label += ' [forzatura]';
                }
                html += '<option value="' + id + '">' + this.escapeHtml(label) + '</option>';
            }, this);
            this.worldIdInput.innerHTML = html;

            var hasCurrent = false;
            var currentId = parseInt(current, 10);
            if (currentId > 0) {
                for (var i = 0; i < options.length; i++) {
                    if (parseInt(options[i].id || '0', 10) === currentId) {
                        hasCurrent = true;
                        break;
                    }
                }
            }

            if (hasCurrent) {
                this.worldIdInput.value = String(currentId);
            } else if (options.length > 0) {
                this.worldIdInput.value = String(options[0].id || '1');
            } else {
                this.worldIdInput.value = '1';
            }
        },

        loadScopeCatalogs: function () {
            var self = this;
            this.requestPost('/admin/maps/list', { results: 500, page: 1, orderBy: 'maps.position|ASC' }, function (response) {
                var rows = (response && Array.isArray(response.dataset)) ? response.dataset : [];
                self.scopeMaps = rows.map(function (row) {
                    var id = parseInt(row.id || 0, 10) || 0;
                    var name = String(row.name || ('Mappa #' + id));
                    return { id: id, label: name };
                }).filter(function (row) { return row.id > 0; });
            });

            this.requestPost('/admin/locations/list', { results: 500, page: 1, orderBy: 'locations.name|ASC' }, function (response) {
                var rows = (response && Array.isArray(response.dataset)) ? response.dataset : [];
                self.scopeLocations = rows.map(function (row) {
                    var id = parseInt(row.id || 0, 10) || 0;
                    var name = String(row.name || ('Luogo #' + id));
                    var mapName = String(row.map_name || '').trim();
                    var label = mapName ? (name + ' [' + mapName + ']') : name;
                    return { id: id, label: label };
                }).filter(function (row) { return row.id > 0; });
            });
        },

        scopeTypeLabel: function (scopeType) {
            var map = {
                world: 'Mondo',
                map: 'Mappa',
                location: 'Luogo',
                region: 'Regione',
                area: 'Area'
            };
            var key = String(scopeType || '').toLowerCase();
            return map[key] || (scopeType || 'N/D');
        },

        scopeReferenceLabel: function (scopeType, scopeId) {
            var type = String(scopeType || '').toLowerCase();
            var id = parseInt(scopeId || 0, 10) || 0;
            if (id <= 0) { return '-'; }

            if (type === 'world') {
                for (var i = 0; i < this.worldOptions.length; i++) {
                    if (parseInt(this.worldOptions[i].id || 0, 10) === id) {
                        return String(this.worldOptions[i].name || ('Mondo ' + id));
                    }
                }
                return 'Mondo #' + id;
            }

            if (type === 'map') {
                for (var m = 0; m < this.scopeMaps.length; m++) {
                    if (parseInt(this.scopeMaps[m].id || 0, 10) === id) {
                        return String(this.scopeMaps[m].label || ('Mappa #' + id));
                    }
                }
                return 'Mappa #' + id;
            }

            if (type === 'location') {
                for (var l = 0; l < this.scopeLocations.length; l++) {
                    if (parseInt(this.scopeLocations[l].id || 0, 10) === id) {
                        return String(this.scopeLocations[l].label || ('Luogo #' + id));
                    }
                }
                return 'Luogo #' + id;
            }

            if (type === 'region' || type === 'area') {
                for (var a = 0; a < this.allAreas.length; a++) {
                    var area = this.allAreas[a] || {};
                    if (parseInt(area.id || 0, 10) === id) {
                        return String(area.name || area.code || ('Area #' + id));
                    }
                }
                return this.scopeTypeLabel(type) + ' #' + id;
            }

            return '#' + id;
        },

        scopeSuggestionRows: function (scopeType) {
            var type = String(scopeType || '').toLowerCase();
            if (type === 'world') { return this.worldOptions || []; }
            if (type === 'map') { return this.scopeMaps || []; }
            if (type === 'location') { return this.scopeLocations || []; }
            if (type === 'region' || type === 'area') { return this.allAreas || []; }
            return [];
        },

        resetScopeSelection: function (form) {
            if (!form) { return; }
            var hidden = form.querySelector('[name=\"scope_id\"]');
            var label = form.querySelector('[name=\"scope_label\"]');
            if (hidden) { hidden.value = ''; }
            if (label) { label.value = ''; }
            this.hideScopeSuggestions(form);
        },

        syncScopeHidden: function (form) {
            if (!form) { return; }
            var hidden = form.querySelector('[name=\"scope_id\"]');
            if (hidden) {
                hidden.value = '';
            }
        },

        renderScopeSuggestions: function (form, searchTerm) {
            if (!form) { return; }
            var scopeField = form.querySelector('[name=\"scope_type\"]');
            var listNode = form.querySelector('[data-role=\"admin-weather-assignment-scope-suggestions\"], [data-role=\"admin-weather-override-scope-suggestions\"]');
            if (!scopeField || !listNode) { return; }

            var scopeType = String(scopeField.value || '').trim().toLowerCase();
            var sourceRows = this.scopeSuggestionRows(scopeType);
            var term = String(searchTerm || '').trim().toLowerCase();
            var matches = [];

            for (var i = 0; i < sourceRows.length; i++) {
                var row = sourceRows[i] || {};
                var id = parseInt(row.id || 0, 10) || 0;
                if (id <= 0) { continue; }
                var label = String(row.label || row.name || row.code || (this.scopeTypeLabel(scopeType) + ' #' + id));
                if (term !== '' && label.toLowerCase().indexOf(term) === -1) {
                    continue;
                }
                matches.push({ id: id, label: label, scope_type: scopeType });
                if (matches.length >= 10) { break; }
            }

            if (matches.length === 0) {
                listNode.classList.add('d-none');
                listNode.innerHTML = '';
                return;
            }

            var html = '';
            for (var m = 0; m < matches.length; m++) {
                var item = matches[m];
                html += '<button type=\"button\" class=\"list-group-item list-group-item-action\" data-action=\"admin-weather-scope-pick\"'
                    + ' data-scope-type=\"' + this.escapeHtml(item.scope_type) + '\"'
                    + ' data-scope-id=\"' + item.id + '\"'
                    + ' data-scope-label=\"' + this.escapeHtml(item.label) + '\">'
                    + this.escapeHtml(item.label)
                    + '</button>';
            }
            listNode.innerHTML = html;
            listNode.classList.remove('d-none');
        },

        pickScopeSuggestion: function (trigger) {
            if (!trigger) { return; }
            var form = trigger.closest('form');
            if (!form) { return; }
            var scopeId = parseInt(trigger.getAttribute('data-scope-id') || '0', 10) || 0;
            var scopeLabel = String(trigger.getAttribute('data-scope-label') || '').trim();
            var hidden = form.querySelector('[name=\"scope_id\"]');
            var label = form.querySelector('[name=\"scope_label\"]');
            if (hidden) { hidden.value = (scopeId > 0 ? String(scopeId) : ''); }
            if (label) { label.value = scopeLabel; }
            this.hideScopeSuggestions(form);
        },

        hideScopeSuggestions: function (form) {
            var root = form || this.root;
            if (!root) { return; }
            var lists = root.querySelectorAll('[data-role=\"admin-weather-assignment-scope-suggestions\"], [data-role=\"admin-weather-override-scope-suggestions\"]');
            for (var i = 0; i < lists.length; i++) {
                lists[i].classList.add('d-none');
                lists[i].innerHTML = '';
            }
        },

        populateOptionSelects: function () {
            var opts = this.weatherOptions;
            if (!opts) { return; }

            this.populateSelectOptions(
                document.getElementById('admin-climate-weather-key'),
                opts.conditions,
                'key',
                'title'
            );
            this.populateSelectOptions(
                document.getElementById('admin-climate-moon-phase'),
                opts.moon_phases,
                'phase',
                'title'
            );
            this.populateSelectOptions(
                this.worldWeatherSelect,
                opts.conditions,
                'key',
                'title'
            );
            this.populateSelectOptions(
                this.worldMoonSelect,
                opts.moon_phases,
                'phase',
                'title'
            );
        },

        populateSelectOptions: function (select, items, valueKey, labelKey) {
            if (!select || !Array.isArray(items)) { return; }

            var current = String(select.value || '');
            var keepFirst = select.options.length > 0 ? select.options[0].outerHTML : '<option value=""></option>';
            select.innerHTML = keepFirst;

            items.forEach(function (item) {
                var option = document.createElement('option');
                option.value = String(item[valueKey] || '');
                option.textContent = String(item[labelKey] || item[valueKey] || '');
                select.appendChild(option);
            });

            select.value = current;
        },

        filterValue: function (selector) {
            if (!this.root) { return ''; }
            var node = this.root.querySelector(selector);
            if (!node) { return ''; }
            return String(node.value || '').trim();
        },

        parseStateFilter: function (selector) {
            var raw = this.filterValue(selector);
            if (raw === '' || raw === 'all') { return null; }
            return (raw === '1') ? 1 : 0;
        },

        weatherTypeFilterPayload: function () {
            var payload = {};
            var query = this.filterValue('[data-role=\"admin-weather-types-filter-query\"]');
            var state = this.parseStateFilter('[data-role=\"admin-weather-types-filter-state\"]');
            if (query !== '') { payload.query = query; }
            if (state !== null) { payload.is_active = state; }
            return payload;
        },

        seasonFilterPayload: function () {
            var payload = {};
            var query = this.filterValue('[data-role=\"admin-weather-seasons-filter-query\"]');
            var state = this.parseStateFilter('[data-role=\"admin-weather-seasons-filter-state\"]');
            if (query !== '') { payload.query = query; }
            if (state !== null) { payload.is_active = state; }
            return payload;
        },

        climateZoneFilterPayload: function () {
            var payload = {};
            var query = this.filterValue('[data-role=\"admin-weather-zones-filter-query\"]');
            var state = this.parseStateFilter('[data-role=\"admin-weather-zones-filter-state\"]');
            if (query !== '') { payload.query = query; }
            if (state !== null) { payload.is_active = state; }
            return payload;
        },

        profileFilterPayload: function () {
            var payload = {};
            var query = this.filterValue('[data-role=\"admin-weather-profiles-filter-query\"]');
            var state = this.parseStateFilter('[data-role=\"admin-weather-profiles-filter-state\"]');
            if (query !== '') { payload.query = query; }
            if (state !== null) { payload.is_active = state; }
            return payload;
        },

        assignmentFilterPayload: function () {
            var payload = {};
            var query = this.filterValue('[data-role=\"admin-weather-assignments-filter-query\"]');
            var scope = this.filterValue('[data-role=\"admin-weather-assignments-filter-scope\"]');
            var state = this.parseStateFilter('[data-role=\"admin-weather-assignments-filter-state\"]');
            if (query !== '') { payload.query = query; }
            if (scope !== '') { payload.scope_type = scope; }
            if (state !== null) { payload.is_active = state; }
            return payload;
        },

        overrideFilterPayload: function () {
            var payload = {};
            var query = this.filterValue('[data-role=\"admin-weather-overrides-filter-query\"]');
            var scope = this.filterValue('[data-role=\"admin-weather-overrides-filter-scope\"]');
            var state = this.parseStateFilter('[data-role=\"admin-weather-overrides-filter-state\"]');
            if (query !== '') { payload.query = query; }
            if (scope !== '') { payload.scope_type = scope; }
            if (state !== null) { payload.is_active = state; }
            return payload;
        },

        climateAreasFilterPayload: function () {
            var payload = {};
            var query = this.filterValue('[data-role=\"admin-climate-areas-filter-query\"]');
            var state = this.parseStateFilter('[data-role=\"admin-climate-areas-filter-state\"]');
            if (query !== '') { payload.query = query; }
            if (state !== null) { payload.is_active = state; }
            return payload;
        },

        climateLocationsFilterPayload: function () {
            var payload = {};
            var query = this.filterValue('[data-role=\"admin-climate-locations-filter-query\"]');
            if (query !== '') { payload.search = query; }
            return payload;
        },

        initGrid: function () {
            if (!document.getElementById('grid-admin-climate-areas')) { return; }
            var self = this;
            this.grid = new Datagrid('grid-admin-climate-areas', {
                name: 'AdminClimateAreas',
                autoindex: 'id',
                orderable: true,
                thead: true,
                handler: { url: '/weather/climate-areas', action: 'list' },
                nav: { display: 'bottom', urlupdate: 0, results: 20, page: 1 },
                columns: [
                    { label: 'ID', field: 'id', sortable: true, style: { width: '60px' } },
                    { label: 'Codice', field: 'code', sortable: true },
                    { label: 'Nome', field: 'name', sortable: true, style: { textAlign: 'left' } },
                    {
                        label: 'Meteo', field: 'weather_key', sortable: false,
                        format: function (row) {
                            return row.weather_key
                                ? '<span class="badge text-bg-info">' + self.escapeHtml(row.weather_key) + '</span>'
                                : '<span class="text-muted small">Automatico</span>';
                        }
                    },
                    {
                        label: 'Temp.', field: 'degrees', sortable: false,
                        format: function (row) {
                            return row.degrees !== null && row.degrees !== ''
                                ? row.degrees + '&deg;C'
                                : '<span class="text-muted small">Automatico</span>';
                        }
                    },
                    {
                        label: 'Luna', field: 'moon_phase', sortable: false,
                        format: function (row) {
                            return row.moon_phase
                                ? '<span class="badge text-bg-secondary">' + self.escapeHtml(row.moon_phase) + '</span>'
                                : '<span class="text-muted small">Automatico</span>';
                        }
                    },
                    {
                        label: 'Attiva', field: 'is_active', sortable: true,
                        format: function (row) {
                            return parseInt(row.is_active, 10) === 1
                                ? '<span class="badge text-bg-success">Sì</span>'
                                : '<span class="badge text-bg-secondary">No</span>';
                        }
                    },
                    {
                        label: 'Azioni', sortable: false, style: { textAlign: 'left' },
                        format: function (row) {
                            var id = parseInt(row.id, 10) || 0;
                            if (id > 0) { self.rowsById[id] = row; }
                            return '<div class="d-flex flex-wrap gap-1">'
                                + '<button type="button" class="btn btn-sm btn-outline-primary" data-action="admin-climate-edit" data-id="' + id + '">Modifica</button>'
                                + '<button type="button" class="btn btn-sm btn-outline-danger" data-action="admin-climate-delete" data-id="' + id + '">Elimina</button>'
                                + '</div>';
                        }
                    }
                ]
            });
        },

        loadGrid: function () {
            if (!this.grid) { return; }
            this.rowsById = {};
            this.grid.loadData(this.climateAreasFilterPayload(), 20, 1, 'name|ASC');
            this.loadAllAreas();
        },

        loadAllAreas: function () {
            var self = this;
            this.requestPost('/weather/climate-areas', {}, function (r) {
                self.allAreas = (r && r.dataset) ? r.dataset : [];
                self.populateAreaSelects();
            });
        },

        populateAreaSelects: function () {
            var self = this;
            var selects = self.root ? self.root.querySelectorAll('[data-climate-area-select]') : [];
            selects.forEach(function (sel) {
                var current = sel.value;
                sel.innerHTML = '<option value="">Nessuna</option>';
                self.allAreas.forEach(function (area) {
                    var opt = document.createElement('option');
                    opt.value = String(area.id || '');
                    opt.textContent = area.name || area.code || String(area.id);
                    sel.appendChild(opt);
                });
                sel.value = current;
            });
        },

        initLocationGrid: function () {
            var self = this;

            if (!document.getElementById('grid-admin-climate-locations')) { return; }

            this.locationGrid = new Datagrid('grid-admin-climate-locations', {
                name: 'AdminClimateLocations',
                autoindex: 'id',
                orderable: true,
                thead: true,
                handler: { url: '/admin/locations/list', action: 'listLocations' },
                nav: { display: 'bottom', urlupdate: 0, results: 25, page: 1 },
                columns: [
                    { label: 'ID', field: 'id', sortable: true, style: { width: '60px' } },
                    { label: 'Luogo', field: 'name', sortable: true, style: { textAlign: 'left' } },
                    {
                        label: 'Area climatica attuale', sortable: false,
                        format: function (row) {
                            var id = parseInt(row.id, 10) || 0;
                            self.locationRowsById[id] = row;
                            var areaId = row.climate_area_id ? String(row.climate_area_id) : '';
                            var options = '<option value="">Nessuna</option>';
                            self.allAreas.forEach(function (a) {
                                var sel = (String(a.id) === areaId) ? ' selected' : '';
                                options += '<option value="' + self.escapeHtml(String(a.id)) + '"' + sel + '>' + self.escapeHtml(a.name || a.code) + '</option>';
                            });
                            return '<select class="form-select form-select-sm" data-climate-area-select data-location-id="' + id + '">' + options + '</select>';
                        }
                    },
                    {
                        label: '', sortable: false, style: { width: '100px' },
                        format: function (row) {
                            var id = parseInt(row.id, 10) || 0;
                            return '<button type="button" class="btn btn-sm btn-outline-primary" data-action="admin-climate-location-assign" data-location-id="' + id + '">Salva</button>';
                        }
                    }
                ]
            });
        },

        loadLocationGrid: function () {
            if (!this.locationGrid) { return; }
            this.locationRowsById = {};
            this.locationGrid.loadData(this.climateLocationsFilterPayload(), 25, 1, 'name|ASC');
        },

        assignLocation: function (trigger) {
            var locationId = parseInt(trigger.getAttribute('data-location-id') || '0', 10) || 0;
            if (!locationId) { return; }

            var row = trigger.closest && trigger.closest('tr');
            var select = row ? row.querySelector('[data-climate-area-select]') : null;
            if (!select) {
                select = this.root ? this.root.querySelector('[data-climate-area-select][data-location-id="' + locationId + '"]') : null;
            }
            var climateAreaId = (select && select.value) ? parseInt(select.value, 10) : null;

            var self = this;
            this.requestPost('/weather/climate-areas/assign', { location_id: locationId, climate_area_id: climateAreaId }, function () {
                Toast.show({ body: 'Assegnazione aggiornata.', type: 'success' });
                self.loadLocationGrid();
            });
        },

        loadWorldState: function () {
            var worldId = this.getWorldId(false);
            if (!worldId) {
                this.renderWorldStateSummary(null);
                return;
            }
            var self = this;
            this.requestPost('/get/weather', { world_id: worldId }, function (response) {
                var state = self.extractWeatherState(response);
                self.fillWorldForm(state);
                self.renderWorldStateSummary(state);
            });
        },

        saveWorldOverride: function () {
            var worldId = this.getWorldId();
            if (!worldId) { return; }

            var payload = {
                world_id: worldId,
                weather_key: this.worldWeatherSelect ? String(this.worldWeatherSelect.value || '').trim() : '',
                degrees: this.worldDegreesInput ? String(this.worldDegreesInput.value || '').trim() : '',
                moon_phase: this.worldMoonSelect ? String(this.worldMoonSelect.value || '').trim() : ''
            };
            if (payload.weather_key === '') { payload.weather_key = null; }
            if (payload.degrees === '') { payload.degrees = null; }
            if (payload.moon_phase === '') { payload.moon_phase = null; }

            var self = this;
            this.requestPost('/weather/world/set', payload, function (response) {
                var state = self.extractWeatherState(response);
                self.fillWorldForm(state);
                self.renderWorldStateSummary(state);
                self.loadWorldOptions(worldId);
                Toast.show({ body: 'Forzatura mondo aggiornata.', type: 'success' });
            });
        },

        clearWorldOverride: function () {
            var worldId = this.getWorldId();
            if (!worldId) { return; }

            var self = this;
            this.requestPost('/weather/world/clear', { world_id: worldId }, function (response) {
                var state = self.extractWeatherState(response);
                self.fillWorldForm(state);
                self.renderWorldStateSummary(state);
                self.loadWorldOptions(worldId);
                Toast.show({ body: 'Forzatura mondo rimossa.', type: 'success' });
            });
        },

        getWorldId: function (showWarning) {
            var shouldWarn = (showWarning !== false);
            var worldRaw = this.worldIdInput ? String(this.worldIdInput.value || '') : '';

            var worldId = parseInt(worldRaw || '0', 10);
            if (worldId <= 0) {
                if (shouldWarn) {
                    Toast.show({ body: 'Seleziona un mondo valido.', type: 'warning' });
                }
                return 0;
            }
            return worldId;
        },

        fillWorldForm: function (state) {
            var worldOverride = state && state.world_override_data ? state.world_override_data : null;
            if (this.worldWeatherSelect) {
                this.worldWeatherSelect.value = worldOverride && worldOverride.weather_key ? String(worldOverride.weather_key) : '';
            }
            if (this.worldDegreesInput) {
                this.worldDegreesInput.value = (worldOverride && worldOverride.degrees !== null && worldOverride.degrees !== '')
                    ? String(worldOverride.degrees)
                    : '';
            }
            if (this.worldMoonSelect) {
                this.worldMoonSelect.value = worldOverride && worldOverride.moon_phase ? String(worldOverride.moon_phase) : '';
            }
        },

        renderWorldStateSummary: function (state) {
            var scopeEl = this.root ? this.root.querySelector('[data-world-weather-scope]') : null;
            var weatherEl = this.root ? this.root.querySelector('[data-world-weather-condition]') : null;
            var tempEl = this.root ? this.root.querySelector('[data-world-weather-temp]') : null;
            var moonEl = this.root ? this.root.querySelector('[data-world-weather-moon]') : null;

            if (!scopeEl || !weatherEl || !tempEl || !moonEl) { return; }

            if (!state || typeof state !== 'object') {
                scopeEl.textContent = 'n/d';
                weatherEl.textContent = 'n/d';
                tempEl.textContent = 'n/d';
                moonEl.textContent = 'n/d';
                return;
            }

            scopeEl.textContent = this.scopeTitle(state.scope || state.scope_type || '');
            weatherEl.textContent = this.conditionTitle(state);

            var deg = (state.temperatures && state.temperatures.degrees !== undefined && state.temperatures.degrees !== null)
                ? String(state.temperatures.degrees) + ' C'
                : 'n/d';
            tempEl.textContent = deg;
            moonEl.textContent = this.moonTitle(state);
        },

        scopeTitle: function (scope) {
            var map = {
                location: 'Forzatura luogo',
                climate_area: 'Area climatica',
                world: 'Forzatura mondo',
                global: 'Forzatura globale',
                auto: 'Automatico'
            };
            return map[String(scope || '').toLowerCase()] || 'Automatico';
        },

        conditionTitle: function (state) {
            if (state.weather && state.weather.title) { return String(state.weather.title); }
            var key = state.condition || (state.weather && state.weather.key) || '';
            if (!key) { return 'n/d'; }
            if (this.weatherOptions && Array.isArray(this.weatherOptions.conditions)) {
                for (var i = 0; i < this.weatherOptions.conditions.length; i++) {
                    var condition = this.weatherOptions.conditions[i];
                    if (String(condition.key) === String(key)) { return String(condition.title || key); }
                }
            }
            return String(key);
        },

        moonTitle: function (state) {
            if (state.moon && state.moon.title) { return String(state.moon.title); }
            var phase = state.moon_phase || (state.moon && state.moon.phase) || '';
            if (!phase) { return 'n/d'; }
            if (this.weatherOptions && Array.isArray(this.weatherOptions.moon_phases)) {
                for (var i = 0; i < this.weatherOptions.moon_phases.length; i++) {
                    var moon = this.weatherOptions.moon_phases[i];
                    if (String(moon.phase) === String(phase)) { return String(moon.title || phase); }
                }
            }
            return String(phase);
        },

        extractWeatherState: function (response) {
            if (!response || typeof response !== 'object') { return null; }
            if (response.dataset && typeof response.dataset === 'object') { return response.dataset; }
            return response;
        },

        findRow: function (trigger) {
            var id = parseInt(trigger.getAttribute('data-id') || '0', 10) || 0;
            return id > 0 ? (this.rowsById[id] || null) : null;
        },

        openModal: function (mode, row) {
            var createMode = (mode !== 'edit');

            if (this.climateForm) {
                this.climateForm.reset();
                var f = this.climateForm.elements;
                f.id.value = '';
                f.is_active.value = '1';
            }

            var title = document.getElementById('admin-climate-modal-title');
            if (title) { title.textContent = createMode ? 'Nuova area climatica' : 'Modifica area climatica'; }

            if (!createMode && row && this.climateForm) {
                var f = this.climateForm.elements;
                f.id.value = String(row.id || '');
                f.code.value = row.code || '';
                f.name.value = row.name || '';
                f.description.value = row.description || '';
                f.weather_key.value = row.weather_key || '';
                f.degrees.value = (row.degrees !== null && row.degrees !== '') ? String(row.degrees) : '';
                f.moon_phase.value = row.moon_phase || '';
                f.is_active.value = parseInt(row.is_active, 10) === 1 ? '1' : '0';
            }

            this.showModal('admin-climate-modal');
        },

        saveArea: function () {
            if (!this.climateForm) { return; }
            var f = this.climateForm.elements;

            var code = String(f.code.value || '').trim();
            var name = String(f.name.value || '').trim();
            if (!code || !name) {
                Toast.show({ body: 'Codice e nome sono obbligatori.', type: 'warning' });
                return;
            }

            var payload = {
                code: code,
                name: name,
                description: String(f.description.value || '').trim(),
                weather_key: String(f.weather_key.value || '').trim() || null,
                degrees: f.degrees.value !== '' ? parseInt(f.degrees.value, 10) : null,
                moon_phase: String(f.moon_phase.value || '').trim() || null,
                is_active: parseInt(f.is_active.value || '1', 10) === 1 ? 1 : 0
            };

            var id = parseInt(f.id.value || '0', 10) || 0;
            if (id > 0) { payload.id = id; }

            var isEdit = id > 0;
            var endpoint = isEdit ? '/weather/climate-areas/update' : '/weather/climate-areas/create';
            var self = this;

            this.requestPost(endpoint, payload, function () {
                self.hideModal('admin-climate-modal');
                Toast.show({ body: isEdit ? 'Area climatica aggiornata.' : 'Area climatica creata.', type: 'success' });
                self.loadGrid();
            });
        },

        confirmDelete: function (row) {
            if (!row || !row.id) { return; }
            this.pendingDeleteId = parseInt(row.id, 10) || 0;
            this.showModal('admin-climate-delete-modal');
        },

        deleteArea: function () {
            if (!this.pendingDeleteId) { return; }
            var id = this.pendingDeleteId;
            var self = this;
            this.hideModal('admin-climate-delete-modal');
            this.requestPost('/weather/climate-areas/delete', { id: id }, function () {
                Toast.show({ body: 'Area climatica eliminata.', type: 'success' });
                self.pendingDeleteId = 0;
                self.loadGrid();
            });
        },

        reloadAllClimateData: function () {
            var self = this;
            this.loadWeatherOptions();
            this.loadWorldOptions();
            this.loadGrid();
            this.loadLocationGrid();
            this.loadWeatherClimateReferences(function () {
                self.populateWeatherClimateReferenceSelects();
                self.loadWeatherTypeGrid();
                self.loadSeasonGrid();
                self.loadClimateZoneGrid();
                self.loadProfileGrid();
                self.loadAssignmentGrid();
                self.loadOverrideGrid();
            });
        },

        findEntityRow: function (trigger, map) {
            var id = parseInt(trigger.getAttribute('data-id') || '0', 10) || 0;
            return id > 0 ? (map[id] || null) : null;
        },

        loadWeatherClimateReferences: function (done) {
            var self = this;
            this.requestPost('/weather/types', {}, function (typesResponse) {
                self.weatherTypesRef = (typesResponse && Array.isArray(typesResponse.dataset)) ? typesResponse.dataset : [];
                self.requestPost('/weather/seasons', {}, function (seasonsResponse) {
                    self.seasonsRef = (seasonsResponse && Array.isArray(seasonsResponse.dataset)) ? seasonsResponse.dataset : [];
                    self.requestPost('/weather/zones', {}, function (zonesResponse) {
                        self.climateZonesRef = (zonesResponse && Array.isArray(zonesResponse.dataset)) ? zonesResponse.dataset : [];
                        if (typeof done === 'function') { done(); }
                    });
                });
            });
        },

        populateWeatherClimateReferenceSelects: function () {
            this.populateWeatherTypeSelects();
            this.populateSeasonSelects();
            this.populateClimateZoneSelects();
        },

        populateWeatherTypeSelects: function () {
            var self = this;
            var selects = this.root ? this.root.querySelectorAll('[data-weather-type-select]') : [];
            selects.forEach(function (select) {
                var current = String(select.value || '');
                var first = select.options.length > 0 ? select.options[0].outerHTML : '<option value="">Nessuno</option>';
                select.innerHTML = first;
                self.weatherTypesRef.forEach(function (row) {
                    var id = parseInt(row.id || '0', 10);
                    if (id <= 0) { return; }
                    var label = String(row.name || row.slug || ('#' + id));
                    if (parseInt(row.is_active || '0', 10) !== 1) { label += ' [disattivo]'; }
                    var option = document.createElement('option');
                    option.value = String(id);
                    option.textContent = label;
                    select.appendChild(option);
                });
                select.value = current;
            });
        },

        populateSeasonSelects: function () {
            var self = this;
            var selects = this.root ? this.root.querySelectorAll('[data-weather-season-select]') : [];
            selects.forEach(function (select) {
                var current = String(select.value || '');
                var first = select.options.length > 0 ? select.options[0].outerHTML : '<option value="">Seleziona stagione</option>';
                select.innerHTML = first;
                self.seasonsRef.forEach(function (row) {
                    var id = parseInt(row.id || '0', 10);
                    if (id <= 0) { return; }
                    var label = String(row.name || row.slug || ('#' + id));
                    if (parseInt(row.is_active || '0', 10) !== 1) { label += ' [disattivo]'; }
                    var option = document.createElement('option');
                    option.value = String(id);
                    option.textContent = label;
                    select.appendChild(option);
                });
                select.value = current;
            });
        },

        populateClimateZoneSelects: function () {
            var self = this;
            var selects = this.root ? this.root.querySelectorAll('[data-weather-zone-select]') : [];
            selects.forEach(function (select) {
                var current = String(select.value || '');
                var first = select.options.length > 0 ? select.options[0].outerHTML : '<option value="">Seleziona zona</option>';
                select.innerHTML = first;
                self.climateZonesRef.forEach(function (row) {
                    var id = parseInt(row.id || '0', 10);
                    if (id <= 0) { return; }
                    var label = String(row.name || row.slug || ('#' + id));
                    if (parseInt(row.is_active || '0', 10) !== 1) { label += ' [disattivo]'; }
                    var option = document.createElement('option');
                    option.value = String(id);
                    option.textContent = label;
                    select.appendChild(option);
                });
                select.value = current;
            });
        },

        initWeatherTypeGrid: function () {
            if (!document.getElementById('grid-admin-weather-types')) { return; }
            var self = this;
            this.weatherTypeGrid = new Datagrid('grid-admin-weather-types', {
                name: 'AdminWeatherTypes',
                autoindex: 'id',
                orderable: true,
                thead: true,
                handler: { url: '/weather/types', action: 'list' },
                nav: { display: 'bottom', urlupdate: 0, results: 12, page: 1 },
                columns: [
                    { label: 'ID', field: 'id', sortable: true, style: { width: '56px' } },
                    { label: 'Nome', field: 'name', sortable: true, style: { textAlign: 'left' } },
                    { label: 'Slug', field: 'slug', sortable: true, style: { textAlign: 'left' } },
                    { label: 'Gruppo', field: 'visual_group', sortable: true },
                    {
                        label: 'Attivo', field: 'is_active', sortable: true,
                        format: function (row) {
                            return parseInt(row.is_active, 10) === 1
                                ? '<span class="badge text-bg-success">Sì</span>'
                                : '<span class="badge text-bg-secondary">No</span>';
                        }
                    },
                    {
                        label: 'Azioni', sortable: false,
                        format: function (row) {
                            var id = parseInt(row.id, 10) || 0;
                            if (id > 0) { self.weatherTypeRowsById[id] = row; }
                            return '<div class="d-flex gap-1">'
                                + '<button type="button" class="btn btn-sm btn-outline-primary" data-action="admin-weather-types-edit" data-id="' + id + '">Modifica</button>'
                                + '<button type="button" class="btn btn-sm btn-outline-danger" data-action="admin-weather-types-delete" data-id="' + id + '">Elimina</button>'
                                + '</div>';
                        }
                    }
                ]
            });
        },

        loadWeatherTypeGrid: function () {
            if (!this.weatherTypeGrid) { return; }
            this.weatherTypeRowsById = {};
            this.weatherTypeGrid.loadData(this.weatherTypeFilterPayload(), 12, 1, 'sort_order|ASC');
        },

        initSeasonGrid: function () {
            if (!document.getElementById('grid-admin-weather-seasons')) { return; }
            var self = this;
            this.seasonGrid = new Datagrid('grid-admin-weather-seasons', {
                name: 'AdminWeatherSeasons',
                autoindex: 'id',
                orderable: true,
                thead: true,
                handler: { url: '/weather/seasons', action: 'list' },
                nav: { display: 'bottom', urlupdate: 0, results: 12, page: 1 },
                columns: [
                    { label: 'ID', field: 'id', sortable: true, style: { width: '56px' } },
                    { label: 'Nome', field: 'name', sortable: true, style: { textAlign: 'left' } },
                    { label: 'Slug', field: 'slug', sortable: true },
                    {
                        label: 'Periodo', sortable: false,
                        format: function (row) {
                            var sm = row.starts_at_month ? String(row.starts_at_month) : '-';
                            var sd = row.starts_at_day ? String(row.starts_at_day) : '-';
                            var em = row.ends_at_month ? String(row.ends_at_month) : '-';
                            var ed = row.ends_at_day ? String(row.ends_at_day) : '-';
                            return sm + '/' + sd + ' - ' + em + '/' + ed;
                        }
                    },
                    {
                        label: 'Attiva', field: 'is_active', sortable: true,
                        format: function (row) {
                            return parseInt(row.is_active, 10) === 1
                                ? '<span class="badge text-bg-success">Sì</span>'
                                : '<span class="badge text-bg-secondary">No</span>';
                        }
                    },
                    {
                        label: 'Azioni', sortable: false,
                        format: function (row) {
                            var id = parseInt(row.id, 10) || 0;
                            if (id > 0) { self.seasonRowsById[id] = row; }
                            return '<div class="d-flex gap-1">'
                                + '<button type="button" class="btn btn-sm btn-outline-primary" data-action="admin-weather-seasons-edit" data-id="' + id + '">Modifica</button>'
                                + '<button type="button" class="btn btn-sm btn-outline-danger" data-action="admin-weather-seasons-delete" data-id="' + id + '">Elimina</button>'
                                + '</div>';
                        }
                    }
                ]
            });
        },

        loadSeasonGrid: function () {
            if (!this.seasonGrid) { return; }
            this.seasonRowsById = {};
            this.seasonGrid.loadData(this.seasonFilterPayload(), 12, 1, 'sort_order|ASC');
        },

        initClimateZoneGrid: function () {
            if (!document.getElementById('grid-admin-weather-zones')) { return; }
            var self = this;
            this.climateZoneGrid = new Datagrid('grid-admin-weather-zones', {
                name: 'AdminClimateZones',
                autoindex: 'id',
                orderable: true,
                thead: true,
                handler: { url: '/weather/zones', action: 'list' },
                nav: { display: 'bottom', urlupdate: 0, results: 12, page: 1 },
                columns: [
                    { label: 'ID', field: 'id', sortable: true, style: { width: '56px' } },
                    { label: 'Nome', field: 'name', sortable: true, style: { textAlign: 'left' } },
                    { label: 'Slug', field: 'slug', sortable: true, style: { textAlign: 'left' } },
                    {
                        label: 'Attiva', field: 'is_active', sortable: true,
                        format: function (row) {
                            return parseInt(row.is_active, 10) === 1
                                ? '<span class="badge text-bg-success">Sì</span>'
                                : '<span class="badge text-bg-secondary">No</span>';
                        }
                    },
                    {
                        label: 'Azioni', sortable: false,
                        format: function (row) {
                            var id = parseInt(row.id, 10) || 0;
                            if (id > 0) { self.climateZoneRowsById[id] = row; }
                            return '<div class="d-flex gap-1">'
                                + '<button type="button" class="btn btn-sm btn-outline-primary" data-action="admin-weather-zones-edit" data-id="' + id + '">Modifica</button>'
                                + '<button type="button" class="btn btn-sm btn-outline-danger" data-action="admin-weather-zones-delete" data-id="' + id + '">Elimina</button>'
                                + '</div>';
                        }
                    }
                ]
            });
        },

        loadClimateZoneGrid: function () {
            if (!this.climateZoneGrid) { return; }
            this.climateZoneRowsById = {};
            this.climateZoneGrid.loadData(this.climateZoneFilterPayload(), 12, 1, 'sort_order|ASC');
        },

        initProfileGrid: function () {
            if (!document.getElementById('grid-admin-weather-profiles')) { return; }
            var self = this;
            this.profileGrid = new Datagrid('grid-admin-weather-profiles', {
                name: 'AdminWeatherProfiles',
                autoindex: 'id',
                orderable: true,
                thead: true,
                handler: { url: '/weather/profiles', action: 'list' },
                nav: { display: 'bottom', urlupdate: 0, results: 15, page: 1 },
                columns: [
                    { label: 'ID', field: 'id', sortable: true, style: { width: '56px' } },
                    { label: 'Zona', field: 'climate_zone_name', sortable: true, style: { textAlign: 'left' } },
                    { label: 'Stagione', field: 'season_name', sortable: true, style: { textAlign: 'left' } },
                    {
                        label: 'Temperatura', sortable: false,
                        format: function (row) {
                            var min = (row.temperature_min !== null && row.temperature_min !== '') ? row.temperature_min : 'n/d';
                            var max = (row.temperature_max !== null && row.temperature_max !== '') ? row.temperature_max : 'n/d';
                            return min + ' - ' + max;
                        }
                    },
                    {
                        label: 'Meteo predefinito', field: 'default_weather_name', sortable: true,
                        format: function (row) {
                            return row.default_weather_name
                                ? self.escapeHtml(String(row.default_weather_name))
                                : '<span class="text-muted small">Nessuno</span>';
                        }
                    },
                    {
                        label: 'Attivo', field: 'is_active', sortable: true,
                        format: function (row) {
                            return parseInt(row.is_active, 10) === 1
                                ? '<span class="badge text-bg-success">Sì</span>'
                                : '<span class="badge text-bg-secondary">No</span>';
                        }
                    },
                    {
                        label: 'Azioni', sortable: false,
                        format: function (row) {
                            var id = parseInt(row.id, 10) || 0;
                            if (id > 0) { self.profileRowsById[id] = row; }
                            return '<div class="d-flex gap-1">'
                                + '<button type="button" class="btn btn-sm btn-outline-primary" data-action="admin-weather-profiles-edit" data-id="' + id + '">Modifica</button>'
                                + '<button type="button" class="btn btn-sm btn-outline-info" data-action="admin-weather-profiles-weights" data-id="' + id + '">Pesi</button>'
                                + '<button type="button" class="btn btn-sm btn-outline-danger" data-action="admin-weather-profiles-delete" data-id="' + id + '">Elimina</button>'
                                + '</div>';
                        }
                    }
                ]
            });
        },

        loadProfileGrid: function () {
            if (!this.profileGrid) { return; }
            this.profileRowsById = {};
            this.profileGrid.loadData(this.profileFilterPayload(), 15, 1, 'id|DESC');
        },

        initAssignmentGrid: function () {
            if (!document.getElementById('grid-admin-weather-assignments')) { return; }
            var self = this;
            this.assignmentGrid = new Datagrid('grid-admin-weather-assignments', {
                name: 'AdminWeatherAssignments',
                autoindex: 'id',
                orderable: true,
                thead: true,
                handler: { url: '/weather/assignments', action: 'list' },
                nav: { display: 'bottom', urlupdate: 0, results: 12, page: 1 },
                columns: [
                    { label: 'ID', field: 'id', sortable: true, style: { width: '56px' } },
                    {
                        label: 'Ambito', field: 'scope_type', sortable: true,
                        format: function (row) {
                            return self.escapeHtml(self.scopeTypeLabel(row.scope_type || ''));
                        }
                    },
                    {
                        label: 'Riferimento', field: 'scope_id', sortable: true, style: { textAlign: 'left' },
                        format: function (row) {
                            return self.escapeHtml(self.scopeReferenceLabel(row.scope_type || '', row.scope_id || 0));
                        }
                    },
                    { label: 'Zona', field: 'climate_zone_name', sortable: true, style: { textAlign: 'left' } },
                    { label: 'Priorità', field: 'priority', sortable: true },
                    {
                        label: 'Attiva', field: 'is_active', sortable: true,
                        format: function (row) {
                            return parseInt(row.is_active, 10) === 1
                                ? '<span class="badge text-bg-success">Sì</span>'
                                : '<span class="badge text-bg-secondary">No</span>';
                        }
                    },
                    {
                        label: 'Azioni', sortable: false,
                        format: function (row) {
                            var id = parseInt(row.id, 10) || 0;
                            if (id > 0) { self.assignmentRowsById[id] = row; }
                            return '<div class="d-flex gap-1">'
                                + '<button type="button" class="btn btn-sm btn-outline-primary" data-action="admin-weather-assignments-edit" data-id="' + id + '">Modifica</button>'
                                + '<button type="button" class="btn btn-sm btn-outline-danger" data-action="admin-weather-assignments-delete" data-id="' + id + '">Elimina</button>'
                                + '</div>';
                        }
                    }
                ]
            });
        },

        loadAssignmentGrid: function () {
            if (!this.assignmentGrid) { return; }
            this.assignmentRowsById = {};
            this.assignmentGrid.loadData(this.assignmentFilterPayload(), 12, 1, 'id|DESC');
        },

        initOverrideGrid: function () {
            if (!document.getElementById('grid-admin-weather-overrides')) { return; }
            var self = this;
            this.overrideGrid = new Datagrid('grid-admin-weather-overrides', {
                name: 'AdminWeatherOverrides',
                autoindex: 'id',
                orderable: true,
                thead: true,
                handler: { url: '/weather/overrides', action: 'list' },
                nav: { display: 'bottom', urlupdate: 0, results: 12, page: 1 },
                columns: [
                    { label: 'ID', field: 'id', sortable: true, style: { width: '56px' } },
                    {
                        label: 'Ambito', field: 'scope_type', sortable: true,
                        format: function (row) {
                            return self.escapeHtml(self.scopeTypeLabel(row.scope_type || ''));
                        }
                    },
                    {
                        label: 'Riferimento', field: 'scope_id', sortable: true, style: { textAlign: 'left' },
                        format: function (row) {
                            return self.escapeHtml(self.scopeReferenceLabel(row.scope_type || '', row.scope_id || 0));
                        }
                    },
                    { label: 'Meteo', field: 'weather_type_name', sortable: true, style: { textAlign: 'left' } },
                    { label: 'Temp.', field: 'temperature_override', sortable: true },
                    {
                        label: 'Attivo', field: 'is_active', sortable: true,
                        format: function (row) {
                            return parseInt(row.is_active, 10) === 1
                                ? '<span class="badge text-bg-success">Sì</span>'
                                : '<span class="badge text-bg-secondary">No</span>';
                        }
                    },
                    {
                        label: 'Azioni', sortable: false,
                        format: function (row) {
                            var id = parseInt(row.id, 10) || 0;
                            if (id > 0) { self.overrideRowsById[id] = row; }
                            return '<div class="d-flex gap-1">'
                                + '<button type="button" class="btn btn-sm btn-outline-primary" data-action="admin-weather-overrides-edit" data-id="' + id + '">Modifica</button>'
                                + '<button type="button" class="btn btn-sm btn-outline-danger" data-action="admin-weather-overrides-delete" data-id="' + id + '">Elimina</button>'
                                + '</div>';
                        }
                    }
                ]
            });
        },

        loadOverrideGrid: function () {
            if (!this.overrideGrid) { return; }
            this.overrideRowsById = {};
            this.overrideGrid.loadData(this.overrideFilterPayload(), 12, 1, 'id|DESC');
        },

        toDateTimeLocalValue: function (value) {
            var text = String(value || '').trim();
            if (!text) { return ''; }
            return text.replace(' ', 'T').slice(0, 16);
        },

        openWeatherTypeModal: function (mode, row) {
            if (!this.weatherTypeForm) { return; }
            var createMode = (mode !== 'edit');
            this.weatherTypeForm.reset();
            var f = this.weatherTypeForm.elements;
            f.id.value = '';
            f.sort_order.value = '0';
            f.is_active.value = '1';
            f.is_precipitation.value = '0';
            f.is_snow.value = '0';
            f.is_storm.value = '0';
            f.reduces_visibility.value = '0';

            var title = document.getElementById('admin-weather-type-modal-title');
            if (title) { title.textContent = createMode ? 'Nuovo tipo meteo' : 'Modifica tipo meteo'; }
            if (!createMode && row) {
                f.id.value = String(row.id || '');
                f.name.value = row.name || '';
                f.slug.value = row.slug || '';
                f.visual_group.value = row.visual_group || '';
                f.description.value = row.description || '';
                f.sort_order.value = String(row.sort_order || '0');
                f.is_active.value = parseInt(row.is_active || '0', 10) === 1 ? '1' : '0';
                f.is_precipitation.value = parseInt(row.is_precipitation || '0', 10) === 1 ? '1' : '0';
                f.is_snow.value = parseInt(row.is_snow || '0', 10) === 1 ? '1' : '0';
                f.is_storm.value = parseInt(row.is_storm || '0', 10) === 1 ? '1' : '0';
                f.reduces_visibility.value = parseInt(row.reduces_visibility || '0', 10) === 1 ? '1' : '0';
            }
            this.showModal('admin-weather-type-modal');
        },

        saveWeatherType: function () {
            if (!this.weatherTypeForm) { return; }
            var f = this.weatherTypeForm.elements;
            var name = String(f.name.value || '').trim();
            var slug = String(f.slug.value || '').trim();
            if (!name || !slug) {
                Toast.show({ body: 'Nome e slug sono obbligatori.', type: 'warning' });
                return;
            }
            var payload = {
                name: name,
                slug: slug,
                visual_group: String(f.visual_group.value || '').trim() || null,
                description: String(f.description.value || '').trim() || null,
                sort_order: parseInt(f.sort_order.value || '0', 10) || 0,
                is_active: parseInt(f.is_active.value || '1', 10) === 1 ? 1 : 0,
                is_precipitation: parseInt(f.is_precipitation.value || '0', 10) === 1 ? 1 : 0,
                is_snow: parseInt(f.is_snow.value || '0', 10) === 1 ? 1 : 0,
                is_storm: parseInt(f.is_storm.value || '0', 10) === 1 ? 1 : 0,
                reduces_visibility: parseInt(f.reduces_visibility.value || '0', 10) === 1 ? 1 : 0
            };
            var id = parseInt(f.id.value || '0', 10) || 0;
            if (id > 0) { payload.id = id; }
            var self = this;
            this.requestPost(id > 0 ? '/weather/types/update' : '/weather/types/create', payload, function () {
                self.hideModal('admin-weather-type-modal');
                self.loadWeatherClimateReferences(function () {
                    self.populateWeatherClimateReferenceSelects();
                    self.loadWeatherTypeGrid();
                    self.loadWeatherOptions();
                });
                Toast.show({ body: id > 0 ? 'Tipo meteo aggiornato.' : 'Tipo meteo creato.', type: 'success' });
            });
        },

        deleteWeatherType: function (row) {
            if (!row || !row.id) { return; }
            if (!window.confirm('Eliminare il tipo meteo selezionato?')) { return; }
            var self = this;
            this.requestPost('/weather/types/delete', { id: parseInt(row.id, 10) || 0 }, function () {
                self.loadWeatherClimateReferences(function () {
                    self.populateWeatherClimateReferenceSelects();
                    self.loadWeatherTypeGrid();
                    self.loadWeatherOptions();
                });
                Toast.show({ body: 'Tipo meteo eliminato.', type: 'success' });
            });
        },

        openSeasonModal: function (mode, row) {
            if (!this.seasonForm) { return; }
            var createMode = (mode !== 'edit');
            this.seasonForm.reset();
            var f = this.seasonForm.elements;
            f.id.value = '';
            f.sort_order.value = '0';
            f.is_active.value = '1';

            var title = document.getElementById('admin-weather-season-modal-title');
            if (title) { title.textContent = createMode ? 'Nuova stagione' : 'Modifica stagione'; }
            if (!createMode && row) {
                f.id.value = String(row.id || '');
                f.name.value = row.name || '';
                f.slug.value = row.slug || '';
                f.description.value = row.description || '';
                f.sort_order.value = String(row.sort_order || '0');
                f.is_active.value = parseInt(row.is_active || '0', 10) === 1 ? '1' : '0';
                f.starts_at_month.value = row.starts_at_month || '';
                f.starts_at_day.value = row.starts_at_day || '';
                f.ends_at_month.value = row.ends_at_month || '';
                f.ends_at_day.value = row.ends_at_day || '';
            }
            this.showModal('admin-weather-season-modal');
        },

        saveSeason: function () {
            if (!this.seasonForm) { return; }
            var f = this.seasonForm.elements;
            var name = String(f.name.value || '').trim();
            var slug = String(f.slug.value || '').trim();
            if (!name || !slug) {
                Toast.show({ body: 'Nome e slug sono obbligatori.', type: 'warning' });
                return;
            }
            var payload = {
                name: name,
                slug: slug,
                description: String(f.description.value || '').trim() || null,
                sort_order: parseInt(f.sort_order.value || '0', 10) || 0,
                is_active: parseInt(f.is_active.value || '1', 10) === 1 ? 1 : 0,
                starts_at_month: f.starts_at_month.value !== '' ? (parseInt(f.starts_at_month.value, 10) || null) : null,
                starts_at_day: f.starts_at_day.value !== '' ? (parseInt(f.starts_at_day.value, 10) || null) : null,
                ends_at_month: f.ends_at_month.value !== '' ? (parseInt(f.ends_at_month.value, 10) || null) : null,
                ends_at_day: f.ends_at_day.value !== '' ? (parseInt(f.ends_at_day.value, 10) || null) : null
            };
            var id = parseInt(f.id.value || '0', 10) || 0;
            if (id > 0) { payload.id = id; }
            var self = this;
            this.requestPost(id > 0 ? '/weather/seasons/update' : '/weather/seasons/create', payload, function () {
                self.hideModal('admin-weather-season-modal');
                self.loadWeatherClimateReferences(function () {
                    self.populateWeatherClimateReferenceSelects();
                    self.loadSeasonGrid();
                });
                Toast.show({ body: id > 0 ? 'Stagione aggiornata.' : 'Stagione creata.', type: 'success' });
            });
        },

        deleteSeason: function (row) {
            if (!row || !row.id) { return; }
            if (!window.confirm('Eliminare la stagione selezionata?')) { return; }
            var self = this;
            this.requestPost('/weather/seasons/delete', { id: parseInt(row.id, 10) || 0 }, function () {
                self.loadWeatherClimateReferences(function () {
                    self.populateWeatherClimateReferenceSelects();
                    self.loadSeasonGrid();
                });
                Toast.show({ body: 'Stagione eliminata.', type: 'success' });
            });
        },

        openClimateZoneModal: function (mode, row) {
            if (!this.climateZoneForm) { return; }
            var createMode = (mode !== 'edit');
            this.climateZoneForm.reset();
            var f = this.climateZoneForm.elements;
            f.id.value = '';
            f.sort_order.value = '0';
            f.is_active.value = '1';
            var title = document.getElementById('admin-weather-zone-modal-title');
            if (title) { title.textContent = createMode ? 'Nuova zona climatica' : 'Modifica zona climatica'; }
            if (!createMode && row) {
                f.id.value = String(row.id || '');
                f.name.value = row.name || '';
                f.slug.value = row.slug || '';
                f.description.value = row.description || '';
                f.sort_order.value = String(row.sort_order || '0');
                f.is_active.value = parseInt(row.is_active || '0', 10) === 1 ? '1' : '0';
            }
            this.showModal('admin-weather-zone-modal');
        },

        saveClimateZone: function () {
            if (!this.climateZoneForm) { return; }
            var f = this.climateZoneForm.elements;
            var name = String(f.name.value || '').trim();
            var slug = String(f.slug.value || '').trim();
            if (!name || !slug) {
                Toast.show({ body: 'Nome e slug sono obbligatori.', type: 'warning' });
                return;
            }
            var payload = {
                name: name,
                slug: slug,
                description: String(f.description.value || '').trim() || null,
                sort_order: parseInt(f.sort_order.value || '0', 10) || 0,
                is_active: parseInt(f.is_active.value || '1', 10) === 1 ? 1 : 0
            };
            var id = parseInt(f.id.value || '0', 10) || 0;
            if (id > 0) { payload.id = id; }
            var self = this;
            this.requestPost(id > 0 ? '/weather/zones/update' : '/weather/zones/create', payload, function () {
                self.hideModal('admin-weather-zone-modal');
                self.loadWeatherClimateReferences(function () {
                    self.populateWeatherClimateReferenceSelects();
                    self.loadClimateZoneGrid();
                });
                Toast.show({ body: id > 0 ? 'Zona climatica aggiornata.' : 'Zona climatica creata.', type: 'success' });
            });
        },

        deleteClimateZone: function (row) {
            if (!row || !row.id) { return; }
            if (!window.confirm('Eliminare la zona climatica selezionata?')) { return; }
            var self = this;
            this.requestPost('/weather/zones/delete', { id: parseInt(row.id, 10) || 0 }, function () {
                self.loadWeatherClimateReferences(function () {
                    self.populateWeatherClimateReferenceSelects();
                    self.loadClimateZoneGrid();
                });
                Toast.show({ body: 'Zona climatica eliminata.', type: 'success' });
            });
        },

        openProfileModal: function (mode, row) {
            if (!this.profileForm) { return; }
            var createMode = (mode !== 'edit');
            this.profileForm.reset();
            this.populateWeatherClimateReferenceSelects();
            var f = this.profileForm.elements;
            f.id.value = '';
            f.temperature_round_mode.value = 'round';
            f.is_active.value = '1';

            var title = document.getElementById('admin-weather-profile-modal-title');
            if (title) { title.textContent = createMode ? 'Nuovo profilo stagionale' : 'Modifica profilo stagionale'; }
            if (!createMode && row) {
                f.id.value = String(row.id || '');
                f.climate_zone_id.value = row.climate_zone_id ? String(row.climate_zone_id) : '';
                f.season_id.value = row.season_id ? String(row.season_id) : '';
                f.temperature_min.value = (row.temperature_min !== null && row.temperature_min !== '') ? String(row.temperature_min) : '';
                f.temperature_max.value = (row.temperature_max !== null && row.temperature_max !== '') ? String(row.temperature_max) : '';
                f.temperature_round_mode.value = row.temperature_round_mode || 'round';
                f.default_weather_type_id.value = row.default_weather_type_id ? String(row.default_weather_type_id) : '';
                f.is_active.value = parseInt(row.is_active || '0', 10) === 1 ? '1' : '0';
            }
            this.showModal('admin-weather-profile-modal');
        },

        saveProfile: function () {
            if (!this.profileForm) { return; }
            var f = this.profileForm.elements;
            var zoneId = parseInt(f.climate_zone_id.value || '0', 10) || 0;
            var seasonId = parseInt(f.season_id.value || '0', 10) || 0;
            if (zoneId <= 0 || seasonId <= 0) {
                Toast.show({ body: 'Zona climatica e stagione sono obbligatorie.', type: 'warning' });
                return;
            }
            var payload = {
                climate_zone_id: zoneId,
                season_id: seasonId,
                temperature_min: f.temperature_min.value !== '' ? parseFloat(f.temperature_min.value) : null,
                temperature_max: f.temperature_max.value !== '' ? parseFloat(f.temperature_max.value) : null,
                temperature_round_mode: String(f.temperature_round_mode.value || 'round'),
                default_weather_type_id: f.default_weather_type_id.value !== '' ? (parseInt(f.default_weather_type_id.value, 10) || null) : null,
                is_active: parseInt(f.is_active.value || '1', 10) === 1 ? 1 : 0
            };
            var id = parseInt(f.id.value || '0', 10) || 0;
            if (id > 0) { payload.id = id; }
            var self = this;
            this.requestPost('/weather/profiles/upsert', payload, function () {
                self.hideModal('admin-weather-profile-modal');
                self.loadProfileGrid();
                Toast.show({ body: id > 0 ? 'Profilo aggiornato.' : 'Profilo creato.', type: 'success' });
            });
        },

        deleteProfile: function (row) {
            if (!row || !row.id) { return; }
            if (!window.confirm('Eliminare il profilo stagionale selezionato?')) { return; }
            var self = this;
            this.requestPost('/weather/profiles/delete', { id: parseInt(row.id, 10) || 0 }, function () {
                self.loadProfileGrid();
                Toast.show({ body: 'Profilo eliminato.', type: 'success' });
            });
        },

        openProfileWeightsModal: function (row) {
            if (!row || !row.id || !this.profileWeightsProfileIdInput || !this.profileWeightsList) { return; }
            if (!Array.isArray(this.weatherTypesRef) || this.weatherTypesRef.length === 0) {
                var self = this;
                this.loadWeatherClimateReferences(function () {
                    self.openProfileWeightsModal(row);
                });
                return;
            }
            var profileId = parseInt(row.id, 10) || 0;
            if (profileId <= 0) { return; }

            this.profileWeightsProfileIdInput.value = String(profileId);
            this.profileWeightsList.innerHTML = '';

            var title = document.getElementById('admin-weather-profile-weights-title');
            if (title) {
                var zone = row.climate_zone_name ? String(row.climate_zone_name) : 'Zona n/d';
                var season = row.season_name ? String(row.season_name) : 'Stagione n/d';
                title.textContent = 'Pesi meteo profilo - ' + zone + ' / ' + season;
            }

            var self = this;
            this.requestPost('/weather/profiles/weights', { profile_id: profileId }, function (response) {
                var rows = (response && Array.isArray(response.dataset)) ? response.dataset : [];
                if (rows.length === 0) {
                    self.addProfileWeightRow();
                } else {
                    rows.forEach(function (entry) {
                        self.addProfileWeightRow(entry);
                    });
                }
                self.showModal('admin-weather-profile-weights-modal');
            }, function (error) {
                Toast.show({ body: self.requestErrorMessage(error), type: 'error' });
            });
        },

        addProfileWeightRow: function (entry) {
            if (!this.profileWeightsList) { return; }
            if (!Array.isArray(this.weatherTypesRef) || this.weatherTypesRef.length === 0) {
                this.loadWeatherClimateReferences();
            }

            var weatherTypeId = entry && entry.weather_type_id ? String(entry.weather_type_id) : '';
            var weight = entry && entry.weight !== null && entry.weight !== undefined ? String(entry.weight) : '1';
            var isActive = (!entry || parseInt(entry.is_active || '1', 10) === 1) ? '1' : '0';

            var weatherOptions = '<option value="">Tipo meteo...</option>';
            this.weatherTypesRef.forEach(function (item) {
                var id = parseInt(item.id || '0', 10);
                if (id <= 0) { return; }
                var selected = String(id) === weatherTypeId ? ' selected' : '';
                weatherOptions += '<option value="' + id + '"' + selected + '>' + this.escapeHtml(String(item.name || ('#' + id))) + '</option>';
            }, this);

            var wrapper = document.createElement('div');
            wrapper.setAttribute('data-weather-weight-row', '1');
            wrapper.className = 'card border-0 shadow-none bg-transparent';
            wrapper.innerHTML =
                '<div class="row g-2 align-items-end">'
                + '<div class="col-12 col-md-6">'
                + '<label class="form-label mb-1">Tipo meteo</label>'
                + '<select class="form-select form-select-sm" data-weather-weight-type>'
                + weatherOptions
                + '</select>'
                + '</div>'
                + '<div class="col-6 col-md-3">'
                + '<label class="form-label mb-1">Peso</label>'
                + '<input type="number" class="form-control form-control-sm" data-weather-weight-value min="0" step="0.1" value="' + this.escapeHtml(weight) + '">'
                + '</div>'
                + '<div class="col-6 col-md-2">'
                + '<label class="form-label mb-1">Attivo</label>'
                + '<select class="form-select form-select-sm" data-weather-weight-active>'
                + '<option value="1"' + (isActive === '1' ? ' selected' : '') + '>Sì</option>'
                + '<option value="0"' + (isActive !== '1' ? ' selected' : '') + '>No</option>'
                + '</select>'
                + '</div>'
                + '<div class="col-12 col-md-1 d-grid">'
                + '<button type="button" class="btn btn-sm btn-outline-danger" data-action="admin-weather-profile-weight-remove-row" title="Rimuovi riga">'
                + '<i class="bi bi-trash"></i>'
                + '</button>'
                + '</div>'
                + '</div>';

            this.profileWeightsList.appendChild(wrapper);
        },

        collectProfileWeights: function () {
            if (!this.profileWeightsList) { return []; }
            var out = [];
            var rows = this.profileWeightsList.querySelectorAll('[data-weather-weight-row]');
            rows.forEach(function (row) {
                var typeField = row.querySelector('[data-weather-weight-type]');
                var weightField = row.querySelector('[data-weather-weight-value]');
                var activeField = row.querySelector('[data-weather-weight-active]');
                if (!typeField || !weightField || !activeField) { return; }
                var weatherTypeId = parseInt(typeField.value || '0', 10) || 0;
                if (weatherTypeId <= 0) { return; }
                var weight = parseFloat(weightField.value || '0');
                if (!isFinite(weight) || weight < 0) {
                    weight = 0;
                }
                out.push({
                    weather_type_id: weatherTypeId,
                    weight: weight,
                    is_active: parseInt(activeField.value || '0', 10) === 1 ? 1 : 0
                });
            });
            return out;
        },

        saveProfileWeights: function () {
            if (!this.profileWeightsProfileIdInput) { return; }
            var profileId = parseInt(this.profileWeightsProfileIdInput.value || '0', 10) || 0;
            if (profileId <= 0) {
                Toast.show({ body: 'Profilo non valido.', type: 'warning' });
                return;
            }

            var weights = this.collectProfileWeights();
            var self = this;
            this.requestPost('/weather/profiles/weights/sync', {
                profile_id: profileId,
                weights: weights
            }, function () {
                self.hideModal('admin-weather-profile-weights-modal');
                Toast.show({ body: 'Pesi meteo salvati.', type: 'success' });
            });
        },

        openAssignmentModal: function (mode, row) {
            if (!this.assignmentForm) { return; }
            var createMode = (mode !== 'edit');
            this.assignmentForm.reset();
            this.populateWeatherClimateReferenceSelects();
            var f = this.assignmentForm.elements;
            f.id.value = '';
            f.scope_type.value = 'location';
            f.scope_id.value = '';
            if (f.scope_label) { f.scope_label.value = ''; }
            f.priority.value = '0';
            f.is_active.value = '1';

            var title = document.getElementById('admin-weather-assignment-modal-title');
            if (title) { title.textContent = createMode ? 'Nuova assegnazione climatica' : 'Modifica assegnazione climatica'; }
            if (!createMode && row) {
                f.id.value = String(row.id || '');
                f.scope_type.value = row.scope_type || 'location';
                f.scope_id.value = row.scope_id || '';
                if (f.scope_label) {
                    f.scope_label.value = this.scopeReferenceLabel(row.scope_type || '', row.scope_id || 0);
                }
                f.climate_zone_id.value = row.climate_zone_id ? String(row.climate_zone_id) : '';
                f.priority.value = row.priority !== null && row.priority !== undefined ? String(row.priority) : '0';
                f.is_active.value = parseInt(row.is_active || '0', 10) === 1 ? '1' : '0';
            }
            this.hideScopeSuggestions(this.assignmentForm);
            this.showModal('admin-weather-assignment-modal');
        },

        saveAssignment: function () {
            if (!this.assignmentForm) { return; }
            var f = this.assignmentForm.elements;
            var scopeType = String(f.scope_type.value || '').trim();
            var scopeId = parseInt(f.scope_id.value || '0', 10) || 0;
            var climateZoneId = parseInt(f.climate_zone_id.value || '0', 10) || 0;
            if (!scopeType || scopeId <= 0 || climateZoneId <= 0) {
                Toast.show({ body: 'Ambito, riferimento e zona climatica sono obbligatori.', type: 'warning' });
                return;
            }
            var payload = {
                scope_type: scopeType,
                scope_id: scopeId,
                climate_zone_id: climateZoneId,
                priority: parseInt(f.priority.value || '0', 10) || 0,
                is_active: parseInt(f.is_active.value || '1', 10) === 1 ? 1 : 0
            };
            var id = parseInt(f.id.value || '0', 10) || 0;
            if (id > 0) { payload.id = id; }
            var self = this;
            this.requestPost('/weather/assignments/upsert', payload, function () {
                self.hideModal('admin-weather-assignment-modal');
                self.loadAssignmentGrid();
                Toast.show({ body: id > 0 ? 'Assegnazione aggiornata.' : 'Assegnazione creata.', type: 'success' });
            });
        },

        deleteAssignment: function (row) {
            if (!row || !row.id) { return; }
            if (!window.confirm('Eliminare l\'assegnazione climatica selezionata?')) { return; }
            var self = this;
            this.requestPost('/weather/assignments/delete', { id: parseInt(row.id, 10) || 0 }, function () {
                self.loadAssignmentGrid();
                Toast.show({ body: 'Assegnazione eliminata.', type: 'success' });
            });
        },

        openOverrideModal: function (mode, row) {
            if (!this.overrideForm) { return; }
            var createMode = (mode !== 'edit');
            this.overrideForm.reset();
            this.populateWeatherClimateReferenceSelects();
            var f = this.overrideForm.elements;
            f.id.value = '';
            f.scope_type.value = 'location';
            f.scope_id.value = '';
            if (f.scope_label) { f.scope_label.value = ''; }
            f.weather_type_id.value = '';
            f.temperature_override.value = '';
            f.reason.value = '';
            f.starts_at.value = '';
            f.expires_at.value = '';
            f.is_active.value = '1';

            var title = document.getElementById('admin-weather-override-modal-title');
            if (title) { title.textContent = createMode ? 'Nuova forzatura meteo' : 'Modifica forzatura meteo'; }
            if (!createMode && row) {
                f.id.value = String(row.id || '');
                f.scope_type.value = row.scope_type || 'location';
                f.scope_id.value = row.scope_id || '';
                if (f.scope_label) {
                    f.scope_label.value = this.scopeReferenceLabel(row.scope_type || '', row.scope_id || 0);
                }
                f.weather_type_id.value = row.weather_type_id ? String(row.weather_type_id) : '';
                f.temperature_override.value = (row.temperature_override !== null && row.temperature_override !== '') ? String(row.temperature_override) : '';
                f.reason.value = row.reason || '';
                f.starts_at.value = this.toDateTimeLocalValue(row.starts_at || '');
                f.expires_at.value = this.toDateTimeLocalValue(row.expires_at || '');
                f.is_active.value = parseInt(row.is_active || '0', 10) === 1 ? '1' : '0';
            }
            this.hideScopeSuggestions(this.overrideForm);
            this.showModal('admin-weather-override-modal');
        },

        saveOverride: function () {
            if (!this.overrideForm) { return; }
            var f = this.overrideForm.elements;
            var scopeType = String(f.scope_type.value || '').trim();
            var scopeId = parseInt(f.scope_id.value || '0', 10) || 0;
            if (!scopeType || scopeId <= 0) {
                Toast.show({ body: 'Ambito e riferimento sono obbligatori.', type: 'warning' });
                return;
            }
            var payload = {
                scope_type: scopeType,
                scope_id: scopeId,
                weather_type_id: f.weather_type_id.value !== '' ? (parseInt(f.weather_type_id.value, 10) || null) : null,
                temperature_override: f.temperature_override.value !== '' ? parseFloat(f.temperature_override.value) : null,
                reason: String(f.reason.value || '').trim() || null,
                starts_at: String(f.starts_at.value || '').trim() || null,
                expires_at: String(f.expires_at.value || '').trim() || null,
                is_active: parseInt(f.is_active.value || '1', 10) === 1 ? 1 : 0
            };
            var id = parseInt(f.id.value || '0', 10) || 0;
            if (id > 0) { payload.id = id; }
            var self = this;
            this.requestPost('/weather/overrides/upsert', payload, function () {
                self.hideModal('admin-weather-override-modal');
                self.loadOverrideGrid();
                Toast.show({ body: id > 0 ? 'Forzatura aggiornata.' : 'Forzatura creata.', type: 'success' });
            });
        },

        deleteOverride: function (row) {
            if (!row || !row.id) { return; }
            if (!window.confirm('Eliminare la forzatura meteo selezionata?')) { return; }
            var self = this;
            this.requestPost('/weather/overrides/delete', { id: parseInt(row.id, 10) || 0 }, function () {
                self.loadOverrideGrid();
                Toast.show({ body: 'Forzatura eliminata.', type: 'success' });
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
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }
    };

    window.AdminWeather = AdminWeather;
})();
