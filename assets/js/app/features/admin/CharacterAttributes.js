(function () {
    'use strict';

    var AdminCharacterAttributes = {
        initialized: false,
        root: null,
        grid: null,
        defs: [],
        defsById: {},
        switches: {},
        settingsModal: null,
        recomputeModal: null,
        recomputeCharacterNameInput: null,
        recomputeCharacterIdInput: null,
        recomputeCharacterSuggestions: null,
        recomputeCharacterTimer: null,

        init: function () {
            if (this.initialized) return this;
            this.root = document.querySelector('#admin-page [data-admin-page="character-attributes"]');
            if (!this.root || !document.getElementById('grid-admin-character-attributes')) return this;

            if (typeof bootstrap !== 'undefined' && bootstrap && typeof bootstrap.Modal === 'function') {
                var settingsNode = this.root.querySelector('#admin-character-attributes-settings-modal');
                var recomputeNode = this.root.querySelector('#admin-character-attributes-recompute-modal');
                if (settingsNode) this.settingsModal = new bootstrap.Modal(settingsNode);
                if (recomputeNode) this.recomputeModal = new bootstrap.Modal(recomputeNode);
            }

            this.recomputeCharacterNameInput = this.root.querySelector('#admin-character-attributes-recompute-character-name');
            this.recomputeCharacterIdInput = this.root.querySelector('#admin-character-attributes-recompute-character-id');
            this.recomputeCharacterSuggestions = this.root.querySelector('#admin-character-attributes-recompute-character-suggestions');

            this.initSwitches();
            this.bind();
            this.initGrid();
            this.loadSettings();
            this.reload();

            this.initialized = true;
            return this;
        },

        bind: function () {
            var self = this;
            this.root.addEventListener('click', function (event) {
                var suggestion = event.target && event.target.closest ? event.target.closest('[data-role="admin-character-attributes-recompute-suggestion"]') : null;
                if (suggestion) {
                    event.preventDefault();
                    self.pickRecomputeCharacter(suggestion);
                    return;
                }

                var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
                if (!trigger) return;
                var action = String(trigger.getAttribute('data-action') || '').trim();
                if (!action) return;
                event.preventDefault();

                if (action === 'admin-character-attributes-reload') return self.reloadAll();
                if (action === 'admin-character-attributes-open-settings-modal') return self.openSettingsModal();
                if (action === 'admin-character-attributes-open-recompute-modal') return self.openRecomputeModal();
                if (action === 'admin-character-attributes-filter-clear') return self.clearFilters();
                if (action === 'admin-character-attribute-create') return self.openDefModal(null);
                if (action === 'admin-character-attributes-save-settings') return self.saveSettings();
                if (action === 'admin-character-attributes-recompute-single') return self.recomputeSingle();
                if (action === 'admin-character-attributes-recompute-batch') return self.recomputeBatch();
                if (action === 'admin-character-attribute-save') return self.saveDefinition();
                if (action === 'admin-character-attribute-edit') return self.openDefModal(self.row(trigger));
                if (action === 'admin-character-attribute-deactivate') return self.deactivateDefinition(self.row(trigger));
                if (action === 'admin-character-attribute-rule') return self.openRuleModal(self.row(trigger));
                if (action === 'admin-character-attribute-move-up') return self.move(self.row(trigger), -1);
                if (action === 'admin-character-attribute-move-down') return self.move(self.row(trigger), 1);
                if (action === 'admin-character-attribute-rule-add-step') return self.addRuleStep();
                if (action === 'admin-character-attribute-rule-remove-step') return self.removeRuleStep(trigger);
                if (action === 'admin-character-attribute-rule-save') return self.saveRule();
                if (action === 'admin-character-attribute-rule-delete') return self.deleteRule();
            });

            var group = this.root.querySelector('#admin-character-attributes-filter-group');
            var active = this.root.querySelector('#admin-character-attributes-filter-active');
            if (group) group.addEventListener('change', function () { self.reload(); });
            if (active) active.addEventListener('change', function () { self.reload(); });
            if (this.recomputeCharacterNameInput) {
                this.recomputeCharacterNameInput.addEventListener('input', function () { self.searchRecomputeCharacters(); });
            }

            $('#admin-character-attribute-rule-modal').on('change', '[data-field="operand_type"]', function () {
                self.toggleOperand($(this).closest('tr'));
            });

            document.addEventListener('click', function (event) {
                if (!self.recomputeCharacterSuggestions || !self.recomputeCharacterNameInput) return;
                if (!event.target.closest || (!event.target.closest('#admin-character-attributes-recompute-character-suggestions') && event.target !== self.recomputeCharacterNameInput)) {
                    self.hideRecomputeSuggestions(true);
                }
            });
        },

        initGrid: function () {
            var self = this;
            this.grid = new Datagrid('grid-admin-character-attributes', {
                name: 'AdminCharacterAttributes',
                autoindex: 'id',
                orderable: true,
                thead: true,
                handler: { url: '/admin/character-attributes/definitions/list', action: 'list' },
                nav: { display: 'bottom', urlupdate: 0, results: 20, page: 1 },
                onGetDataSuccess: function (response) {
                    self.store(response && Array.isArray(response.dataset) ? response.dataset : []);
                },
                onGetDataError: function () { self.store([]); },
                columns: [
                    { label: 'Slug', field: 'slug', sortable: true, style: { textAlign: 'left' } },
                    {
                        label: 'Attributo', field: 'name', sortable: true, style: { textAlign: 'left' },
                        format: function (row) {
                            return '<div><b>' + self.e(row.name || '-') + '</b></div>'
                                + '<div class="small text-muted">' + self.e(row.description || '') + '</div>';
                        }
                    },
                    { label: 'Gruppo', field: 'attribute_group', sortable: true, format: function (row) { return self.groupBadge(row.attribute_group); } },
                    {
                        label: 'Tipo', field: 'is_derived', sortable: true,
                        format: function (row) {
                            var out = parseInt(row.is_derived || 0, 10) === 1
                                ? '<span class="badge text-bg-secondary">Derivato</span>'
                                : '<span class="badge text-bg-light text-dark">Base</span>';
                            if (parseInt(row.allow_manual_override || 0, 10) === 1) out += ' <span class="badge text-bg-info">Override</span>';
                            if (parseInt(row.maps_to_core_health_max || 0, 10) === 1) out += ' <span class="badge text-bg-danger">HP max</span>';
                            return out;
                        }
                    },
                    {
                        label: 'Range', sortable: false,
                        format: function (row) {
                            var min = row.min_value != null && row.min_value !== '' ? self.e(row.min_value) : '-';
                            var max = row.max_value != null && row.max_value !== '' ? self.e(row.max_value) : '-';
                            return 'Min: <b>' + min + '</b> · Max: <b>' + max + '</b>';
                        }
                    },
                    {
                        label: 'Stato', field: 'is_active', sortable: true,
                        format: function (row) {
                            return parseInt(row.is_active || 0, 10) === 1
                                ? '<span class="badge text-bg-success">Attivo</span>'
                                : '<span class="badge text-bg-secondary">Inattivo</span>';
                        }
                    },
                    {
                        label: 'Azioni', sortable: false, style: { textAlign: 'left' },
                        format: function (row) {
                            var id = parseInt(row.id || 0, 10) || 0;
                            if (id <= 0) return '-';
                            var canRule = parseInt(row.is_derived || 0, 10) === 1;
                            return '<div class="d-flex flex-wrap gap-1">'
                                + '<button class="btn btn-sm btn-outline-primary" data-action="admin-character-attribute-edit" data-id="' + id + '">Modifica</button>'
                                + '<button class="btn btn-sm btn-outline-secondary" data-action="admin-character-attribute-rule" data-id="' + id + '"' + (canRule ? '' : ' disabled') + '>Regola</button>'
                                + '<button class="btn btn-sm btn-outline-warning" data-action="admin-character-attribute-deactivate" data-id="' + id + '">Disattiva</button>'
                                + '<button class="btn btn-sm btn-outline-light" data-action="admin-character-attribute-move-up" data-id="' + id + '"><i class="bi bi-arrow-up"></i></button>'
                                + '<button class="btn btn-sm btn-outline-light" data-action="admin-character-attribute-move-down" data-id="' + id + '"><i class="bi bi-arrow-down"></i></button>'
                                + '</div>';
                        }
                    }
                ]
            });
        },

        reloadAll: function () { this.loadSettings(); this.reload(); },
        reload: function () { this.grid.loadData(this.query(), 20, 1, 'position|ASC'); },

        openSettingsModal: function () {
            this.loadSettings();
            if (this.settingsModal && typeof this.settingsModal.show === 'function') {
                this.settingsModal.show();
                return;
            }
            $('#admin-character-attributes-settings-modal').modal('show');
        },

        openRecomputeModal: function () {
            if (this.recomputeCharacterNameInput) this.recomputeCharacterNameInput.value = '';
            if (this.recomputeCharacterIdInput) this.recomputeCharacterIdInput.value = '';
            this.hideRecomputeSuggestions(true);
            if (this.recomputeModal && typeof this.recomputeModal.show === 'function') {
                this.recomputeModal.show();
                return;
            }
            $('#admin-character-attributes-recompute-modal').modal('show');
        },

        query: function () {
            var q = {};
            var g = this.root.querySelector('#admin-character-attributes-filter-group');
            var a = this.root.querySelector('#admin-character-attributes-filter-active');
            if (g && g.value && g.value !== 'all') q.attribute_group = String(g.value);
            if (a && (a.value === '0' || a.value === '1')) q.is_active = String(a.value);
            return q;
        },

        clearFilters: function () {
            var g = this.root.querySelector('#admin-character-attributes-filter-group');
            var a = this.root.querySelector('#admin-character-attributes-filter-active');
            if (g) g.value = 'all';
            if (a) a.value = 'all';
            this.reload();
        },

        store: function (rows) {
            this.defs = Array.isArray(rows) ? rows.slice() : [];
            this.defsById = {};
            for (var i = 0; i < this.defs.length; i++) {
                var id = parseInt(this.defs[i].id || 0, 10) || 0;
                if (id > 0) this.defsById[id] = this.defs[i];
            }
        },

        row: function (trigger) {
            var id = parseInt(String(trigger.getAttribute('data-id') || '0'), 10) || 0;
            return id > 0 ? (this.defsById[id] || null) : null;
        },

        initSwitches: function () {
            this.switches = {};
            if (typeof window.SwitchGroup !== 'function') return;

            this.mountSwitch('#admin-character-attributes-enabled', {
                preset: 'activeInactive',
                trueLabel: 'Attivo',
                falseLabel: 'Disattivo',
                defaultValue: '0'
            });

            this.mountSwitch('#admin-character-attribute-rule-active', {
                preset: 'activeInactive',
                trueLabel: 'Attiva',
                falseLabel: 'Inattiva',
                defaultValue: '1'
            });

            this.mountSwitch('[name="is_active"]', { preset: 'activeInactive', trueLabel: 'Attivo', falseLabel: 'Inattivo', defaultValue: '1' });
            this.mountSwitch('[name="is_derived"]', { preset: 'yesNo', trueLabel: 'Si', falseLabel: 'No', defaultValue: '0' });
            this.mountSwitch('[name="allow_manual_override"]', { preset: 'yesNo', trueLabel: 'Si', falseLabel: 'No', defaultValue: '0' });
            this.mountSwitch('[name="maps_to_core_health_max"]', { preset: 'yesNo', trueLabel: 'Si', falseLabel: 'No', defaultValue: '0' });
            this.mountSwitch('[name="visible_in_profile"]', { preset: 'yesNo', trueLabel: 'Si', falseLabel: 'No', defaultValue: '1' });
            this.mountSwitch('[name="visible_in_location"]', { preset: 'yesNo', trueLabel: 'Si', falseLabel: 'No', defaultValue: '0' });
        },

        mountSwitch: function (selector, options) {
            var node = this.root.querySelector(selector);
            if (!node) return null;
            var instance = window.SwitchGroup(node, Object.assign({
                trueValue: '1',
                falseValue: '0',
                showLabels: true
            }, options || {}));
            this.switches[selector] = instance || null;
            return instance;
        },

        setSwitchValue: function (selector, value, fallback) {
            var val = String(value == null ? (fallback == null ? '0' : fallback) : value);
            var instance = this.switches && this.switches[selector] ? this.switches[selector] : null;
            if (instance && typeof instance.setValue === 'function') {
                instance.setValue(val);
                return;
            }
            var node = this.root.querySelector(selector);
            if (node) {
                node.value = val;
                $(node).trigger('change');
            }
        },

        getSwitchInt: function (selector, fallback) {
            var instance = this.switches && this.switches[selector] ? this.switches[selector] : null;
            var raw = '';
            if (instance && typeof instance.getValue === 'function') raw = String(instance.getValue() || '');
            else {
                var node = this.root.querySelector(selector);
                raw = node ? String(node.value || '') : '';
            }
            if (raw === '1') return 1;
            if (raw === '0') return 0;
            return parseInt(String(fallback == null ? 0 : fallback), 10) === 1 ? 1 : 0;
        },

        loadSettings: function () {
            var self = this;
            this.post('/admin/character-attributes/settings/get', {}, function (r) {
                var enabled = parseInt((r && r.dataset && r.dataset.enabled) || 0, 10) === 1;
                self.setSwitchValue('#admin-character-attributes-enabled', enabled ? '1' : '0', '0');
            });
        },

        saveSettings: function () {
            var self = this;
            this.post('/admin/character-attributes/settings/update', { enabled: this.getSwitchInt('#admin-character-attributes-enabled', 0) }, function () {
                if (self.settingsModal && typeof self.settingsModal.hide === 'function') self.settingsModal.hide();
                else $('#admin-character-attributes-settings-modal').modal('hide');
                Toast.show({ body: 'Impostazioni salvate.', type: 'success' });
            });
        },

        openDefModal: function (row) {
            var f = $('#admin-character-attribute-form');
            var d = row || {};
            f.find('[name="id"]').val(d.id || '');
            f.find('[name="name"]').val(d.name || '');
            f.find('[name="slug"]').val(d.slug || '');
            f.find('[name="description"]').val(d.description || '');
            f.find('[name="attribute_group"]').val(d.attribute_group || 'primary');
            f.find('[name="value_type"]').val(d.value_type || 'number');
            f.find('[name="position"]').val(d.position || 0);
            f.find('[name="round_mode"]').val(d.round_mode || 'none');
            f.find('[name="min_value"]').val(d.min_value != null ? d.min_value : '');
            f.find('[name="max_value"]').val(d.max_value != null ? d.max_value : '');
            f.find('[name="default_value"]').val(d.default_value != null ? d.default_value : '');
            f.find('[name="fallback_value"]').val(d.fallback_value != null ? d.fallback_value : '');
            this.setSwitchValue('[name="is_active"]', d.is_active != null ? String(d.is_active) : '1', '1');
            this.setSwitchValue('[name="is_derived"]', d.is_derived != null ? String(d.is_derived) : '0', '0');
            this.setSwitchValue('[name="allow_manual_override"]', d.allow_manual_override != null ? String(d.allow_manual_override) : '0', '0');
            this.setSwitchValue('[name="maps_to_core_health_max"]', d.maps_to_core_health_max != null ? String(d.maps_to_core_health_max) : '0', '0');
            this.setSwitchValue('[name="visible_in_profile"]', d.visible_in_profile != null ? String(d.visible_in_profile) : '1', '1');
            this.setSwitchValue('[name="visible_in_location"]', d.visible_in_location != null ? String(d.visible_in_location) : '0', '0');
            $('#admin-character-attribute-modal').modal('show');
        },

        saveDefinition: function () {
            var f = $('#admin-character-attribute-form');
            var payload = {
                id: parseInt(String(f.find('[name="id"]').val() || '0'), 10) || 0,
                name: String(f.find('[name="name"]').val() || '').trim(),
                slug: String(f.find('[name="slug"]').val() || '').trim(),
                description: String(f.find('[name="description"]').val() || '').trim(),
                attribute_group: String(f.find('[name="attribute_group"]').val() || 'primary'),
                value_type: String(f.find('[name="value_type"]').val() || 'number'),
                position: parseInt(String(f.find('[name="position"]').val() || '0'), 10) || 0,
                min_value: this.dec(f.find('[name="min_value"]').val()),
                max_value: this.dec(f.find('[name="max_value"]').val()),
                default_value: this.dec(f.find('[name="default_value"]').val()),
                fallback_value: this.dec(f.find('[name="fallback_value"]').val()),
                round_mode: String(f.find('[name="round_mode"]').val() || 'none'),
                is_active: this.getSwitchInt('[name="is_active"]', 1),
                is_derived: this.getSwitchInt('[name="is_derived"]', 0),
                allow_manual_override: this.getSwitchInt('[name="allow_manual_override"]', 0),
                maps_to_core_health_max: this.getSwitchInt('[name="maps_to_core_health_max"]', 0),
                visible_in_profile: this.getSwitchInt('[name="visible_in_profile"]', 1),
                visible_in_location: this.getSwitchInt('[name="visible_in_location"]', 0)
            };
            if (!payload.name || !payload.slug) return Toast.show({ body: 'Nome e slug obbligatori.', type: 'warning' });
            if (payload.description === '') payload.description = null;
            var self = this;
            this.post(payload.id > 0 ? '/admin/character-attributes/definitions/update' : '/admin/character-attributes/definitions/create', payload, function () {
                $('#admin-character-attribute-modal').modal('hide');
                Toast.show({ body: 'Attributo salvato.', type: 'success' });
                self.reload();
            });
        },

        deactivateDefinition: function (row) {
            if (!row || !row.id) return;
            var self = this;
            this.confirm('Disattiva attributo', 'Confermi la disattivazione?', function () {
                self.post('/admin/character-attributes/definitions/deactivate', { id: row.id }, function () {
                    self.reload();
                });
            });
        },

        move: function (row, dir) {
            if (!row || !row.id || this.defs.length < 2) return;
            var ids = this.defs.map(function (d) { return parseInt(d.id || 0, 10) || 0; });
            var idx = ids.indexOf(parseInt(row.id, 10));
            var to = idx + (dir < 0 ? -1 : 1);
            if (idx < 0 || to < 0 || to >= ids.length) return;
            var t = ids[idx]; ids[idx] = ids[to]; ids[to] = t;
            var self = this;
            this.post('/admin/character-attributes/definitions/reorder', { ordered_ids: ids }, function () { self.reload(); });
        },

        openRuleModal: function (row) {
            if (!row || !row.id) return;
            $('#admin-character-attribute-rule-attribute-id').val(row.id);
            $('[data-role="admin-character-attribute-rule-title"]').text((row.name || row.slug) + ' (#' + row.id + ')');
            $('#admin-character-attribute-rule-fallback').val('');
            $('#admin-character-attribute-rule-round').val('');
            this.setSwitchValue('#admin-character-attribute-rule-active', '1', '1');
            $('#admin-character-attribute-rule-steps').empty();
            $('#admin-character-attribute-rule-modal').modal('show');
            this.loadRule(row.id);
        },

        loadRule: function (attributeId) {
            var self = this;
            this.post('/admin/character-attributes/rules/get', { attribute_id: attributeId }, function (response) {
                var data = response && response.dataset ? response.dataset : {};
                var rule = data.rule || null;
                var steps = Array.isArray(data.steps) ? data.steps : [];
                $('#admin-character-attribute-rule-fallback').val(rule && rule.fallback_value != null ? rule.fallback_value : '');
                $('#admin-character-attribute-rule-round').val(rule && rule.round_mode ? rule.round_mode : '');
                self.setSwitchValue('#admin-character-attribute-rule-active', rule && parseInt(rule.is_active || 0, 10) === 0 ? '0' : '1', '1');
                if (!steps.length) return self.addRuleStep({ operator_code: 'set', operand_type: 'value', operand_value: 0 });
                for (var i = 0; i < steps.length; i++) self.addRuleStep(steps[i]);
            });
        },

        addRuleStep: function (preset) {
            var p = preset || {};
            var tbody = $('#admin-character-attribute-rule-steps');
            var count = tbody.find('tr').length + 1;
            var op = String(p.operator_code || 'set');
            var type = String(p.operand_type || 'value');
            var val = p.operand_value != null ? p.operand_value : '';
            var attr = parseInt(p.operand_attribute_id || 0, 10) || 0;
            var row = $('<tr>'
                + '<td class="small text-muted" data-role="step-order">' + count + '</td>'
                + '<td><select class="form-select form-select-sm" data-field="operator_code"><option value="set">SET</option><option value="add">ADD</option><option value="sub">SUB</option><option value="mul">MUL</option><option value="div">DIV</option><option value="min">MIN</option><option value="max">MAX</option></select></td>'
                + '<td><select class="form-select form-select-sm" data-field="operand_type"><option value="value">Valore</option><option value="attribute">Attributo</option></select></td>'
                + '<td><input type="number" step="0.01" class="form-control form-control-sm" data-field="operand_value"><select class="form-select form-select-sm mt-1 d-none" data-field="operand_attribute_id">' + this.attrOptions(attr) + '</select></td>'
                + '<td><button type="button" class="btn btn-sm btn-outline-danger" data-action="admin-character-attribute-rule-remove-step"><i class="bi bi-x-lg"></i></button></td>'
                + '</tr>');
            tbody.append(row);
            row.find('[data-field="operator_code"]').val(op);
            row.find('[data-field="operand_type"]').val(type);
            row.find('[data-field="operand_value"]').val(val);
            this.toggleOperand(row);
            this.renumberSteps();
        },

        removeRuleStep: function (trigger) {
            $(trigger).closest('tr').remove();
            if (!$('#admin-character-attribute-rule-steps tr').length) this.addRuleStep({ operator_code: 'set', operand_type: 'value', operand_value: 0 });
            this.renumberSteps();
        },

        toggleOperand: function (row) {
            var type = String(row.find('[data-field="operand_type"]').val() || 'value');
            row.find('[data-field="operand_value"]').toggleClass('d-none', type === 'attribute');
            row.find('[data-field="operand_attribute_id"]').toggleClass('d-none', type !== 'attribute');
        },

        renumberSteps: function () {
            $('#admin-character-attribute-rule-steps tr').each(function (i) { $(this).find('[data-role="step-order"]').text(i + 1); });
        },

        saveRule: function () {
            var attributeId = parseInt($('#admin-character-attribute-rule-attribute-id').val() || '0', 10) || 0;
            if (attributeId <= 0) return;
            var payload = {
                attribute_id: attributeId,
                fallback_value: this.dec($('#admin-character-attribute-rule-fallback').val()),
                round_mode: String($('#admin-character-attribute-rule-round').val() || ''),
                is_active: this.getSwitchInt('#admin-character-attribute-rule-active', 1),
                steps: []
            };
            $('#admin-character-attribute-rule-steps tr').each(function () {
                var tr = $(this), type = String(tr.find('[data-field="operand_type"]').val() || 'value');
                var step = { operator_code: String(tr.find('[data-field="operator_code"]').val() || 'set'), operand_type: type };
                if (type === 'attribute') step.operand_attribute_id = parseInt(String(tr.find('[data-field="operand_attribute_id"]').val() || '0'), 10) || 0;
                else step.operand_value = parseFloat(String(tr.find('[data-field="operand_value"]').val() || '0').replace(',', '.'));
                payload.steps.push(step);
            });
            var self = this;
            this.post('/admin/character-attributes/rules/upsert', payload, function () {
                Toast.show({ body: 'Regola salvata.', type: 'success' });
                self.reload();
            });
        },

        deleteRule: function () {
            var attributeId = parseInt($('#admin-character-attribute-rule-attribute-id').val() || '0', 10) || 0;
            if (attributeId <= 0) return;
            var self = this;
            this.confirm('Elimina regola', 'Confermi la rimozione?', function () {
                self.post('/admin/character-attributes/rules/delete', { attribute_id: attributeId }, function () {
                    Toast.show({ body: 'Regola eliminata.', type: 'success' });
                    self.loadRule(attributeId);
                    self.reload();
                });
            });
        },

        recomputeSingle: function () {
            var id = parseInt(String((document.getElementById('admin-character-attributes-recompute-character-id') || {}).value || '0'), 10) || 0;
            if (id <= 0) return Toast.show({ body: 'Seleziona un personaggio dalla lista.', type: 'warning' });
            var self = this;
            this.post('/admin/character-attributes/recompute', { character_id: id }, function () {
                if (self.recomputeModal && typeof self.recomputeModal.hide === 'function') self.recomputeModal.hide();
                else $('#admin-character-attributes-recompute-modal').modal('hide');
                Toast.show({ body: 'Ricalcolo target completato.', type: 'success' });
            });
        },

        searchRecomputeCharacters: function () {
            var self = this;
            if (!this.recomputeCharacterNameInput || !this.recomputeCharacterIdInput) return;
            var query = String(this.recomputeCharacterNameInput.value || '').trim();
            this.recomputeCharacterIdInput.value = '';

            if (this.recomputeCharacterTimer) {
                window.clearTimeout(this.recomputeCharacterTimer);
                this.recomputeCharacterTimer = null;
            }
            if (query.length < 2) {
                this.hideRecomputeSuggestions(true);
                return;
            }

            this.recomputeCharacterTimer = window.setTimeout(function () {
                self.post('/list/characters/search', { query: query }, function (response) {
                    self.renderRecomputeSuggestions(response && response.dataset ? response.dataset : []);
                });
            }, 180);
        },

        renderRecomputeSuggestions: function (rows) {
            if (!this.recomputeCharacterSuggestions) return;
            this.recomputeCharacterSuggestions.innerHTML = '';
            if (!Array.isArray(rows) || rows.length === 0) {
                this.recomputeCharacterSuggestions.classList.add('d-none');
                return;
            }
            for (var i = 0; i < rows.length; i += 1) {
                var row = rows[i] || {};
                var id = parseInt(row.id || 0, 10) || 0;
                if (id <= 0) continue;
                var label = (String(row.name || '') + ' ' + String(row.surname || '')).trim();
                if (!label) label = 'PG #' + id;

                var item = document.createElement('button');
                item.type = 'button';
                item.className = 'list-group-item list-group-item-action small py-1';
                item.setAttribute('data-role', 'admin-character-attributes-recompute-suggestion');
                item.setAttribute('data-character-id', String(id));
                item.setAttribute('data-character-label', label);
                item.textContent = label;
                this.recomputeCharacterSuggestions.appendChild(item);
            }
            if (this.recomputeCharacterSuggestions.children.length === 0) {
                this.recomputeCharacterSuggestions.classList.add('d-none');
                return;
            }
            this.recomputeCharacterSuggestions.classList.remove('d-none');
        },

        pickRecomputeCharacter: function (node) {
            if (!node || !this.recomputeCharacterNameInput || !this.recomputeCharacterIdInput) return;
            var id = parseInt(node.getAttribute('data-character-id') || '0', 10) || 0;
            var label = String(node.getAttribute('data-character-label') || '').trim();
            if (id <= 0) return;
            this.recomputeCharacterIdInput.value = String(id);
            this.recomputeCharacterNameInput.value = label;
            this.hideRecomputeSuggestions(true);
        },

        hideRecomputeSuggestions: function (clear) {
            if (!this.recomputeCharacterSuggestions) return;
            this.recomputeCharacterSuggestions.classList.add('d-none');
            if (clear === true) this.recomputeCharacterSuggestions.innerHTML = '';
        },

        recomputeBatch: function () {
            var self = this;
            this.confirm('Ricalcolo batch', 'Confermi il ricalcolo su tutti i personaggi attivi?', function () {
                self.post('/admin/character-attributes/recompute', {}, function (r) {
                    var total = parseInt((r && r.dataset && r.dataset.total) || 0, 10) || 0;
                    if (self.recomputeModal && typeof self.recomputeModal.hide === 'function') self.recomputeModal.hide();
                    else $('#admin-character-attributes-recompute-modal').modal('hide');
                    Toast.show({ body: 'Ricalcolo batch completato: ' + total + ' target.', type: 'success' });
                });
            });
        },

        attrOptions: function (selected) {
            var opts = ['<option value="">Seleziona attributo</option>'];
            for (var i = 0; i < this.defs.length; i++) {
                var d = this.defs[i] || {}, id = parseInt(d.id || 0, 10) || 0;
                if (id <= 0 || parseInt(d.is_active || 0, 10) !== 1) continue;
                opts.push('<option value="' + id + '"' + (selected === id ? ' selected' : '') + '>' + this.e(d.name || d.slug || ('#' + id)) + '</option>');
            }
            return opts.join('');
        },

        groupBadge: function (group) {
            var key = String(group || '').toLowerCase();
            if (key === 'secondary') return '<span class="badge text-bg-info">Secondario</span>';
            if (key === 'narrative') return '<span class="badge text-bg-warning">Narrativo</span>';
            return '<span class="badge text-bg-primary">Primario</span>';
        },

        dec: function (value) {
            var raw = String(value == null ? '' : value).trim().replace(',', '.');
            if (raw === '') return null;
            var num = parseFloat(raw);
            return isFinite(num) ? Math.round(num * 100) / 100 : null;
        },

        post: function (url, payload, ok) {
            var self = this;
            var data = (payload && typeof payload === 'object') ? payload : {};

            if (typeof window.Request !== 'undefined'
                && window.Request
                && window.Request.http
                && typeof window.Request.http.post === 'function') {
                window.Request.http.post(url, data)
                    .then(function (r) { if (typeof ok === 'function') ok(r || {}); })
                    .catch(function (error) { Toast.show({ body: self.err(error), type: 'danger' }); });
                return;
            }

            var csrfToken = '';
            var meta = document.querySelector('meta[name="csrf-token"]');
            if (meta) {
                csrfToken = String(meta.getAttribute('content') || '');
            }
            if (csrfToken) {
                data._csrf = csrfToken;
            }

            $.ajax({
                method: 'POST',
                url: url,
                data: { data: JSON.stringify(data) },
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': csrfToken
                }
            })
                .done(function (r) { if (typeof ok === 'function') ok(r || {}); })
                .fail(function (xhr) { Toast.show({ body: self.err(xhr), type: 'danger' }); });
        },

        confirm: function (title, body, onConfirm) {
            if (typeof Dialog === 'function') {
                Dialog('warning', { title: title, body: '<p>' + body + '</p>' }, function () {
                    if (typeof onConfirm === 'function') onConfirm();
                }).show();
                return;
            }
            if (window.confirm(title + '\n\n' + String(body || '').replace(/<[^>]+>/g, ''))) {
                if (typeof onConfirm === 'function') onConfirm();
            }
        },

        err: function (error) {
            var message = '';
            var code = '';

            if (typeof window.Request !== 'undefined' && window.Request) {
                if (typeof window.Request.getErrorCode === 'function') {
                    code = String(window.Request.getErrorCode(error, '') || '').trim();
                }
                if (typeof window.Request.getErrorMessage === 'function') {
                    message = String(window.Request.getErrorMessage(error, '') || '').trim();
                }
            }

            if (!code && error && typeof error === 'object') {
                if (typeof error.errorCode === 'string') code = String(error.errorCode).trim();
                if (!code && error.responseJSON && typeof error.responseJSON === 'object') {
                    code = String(error.responseJSON.error_code || '').trim();
                }
            }

            var map = {
                attributes_system_disabled: 'Sistema attributi disattivato.',
                attribute_definition_not_found: 'Attributo non trovato.',
                attribute_slug_conflict: 'Slug attributo gia in uso.',
                attribute_range_invalid: 'Range attributo non valido.',
                attribute_default_out_of_range: 'Default attributo fuori range.',
                attribute_rule_invalid: 'Regola attributo non valida.',
                attribute_rule_step_invalid: 'Step regola non valido.',
                attribute_override_not_allowed: 'Override non consentito.',
                attribute_recompute_failed: 'Ricalcolo attributi non riuscito.'
            };
            if (map[code]) return map[code];
            if (message) return message;

            var r = error && error.responseJSON ? error.responseJSON : {};
            return String(r.error || '').trim() || 'Operazione non riuscita.';
        },

        e: function (value) { return $('<div/>').text(value == null ? '' : String(value)).html(); }
    };

    window.AdminCharacterAttributes = AdminCharacterAttributes;
})();
