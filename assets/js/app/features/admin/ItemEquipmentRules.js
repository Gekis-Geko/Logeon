const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

var AdminItemEquipmentRules = {
    initialized: false,
    root: null,
    filtersForm: null,
    grid: null,
    modalNode: null,
    modal: null,
    modalForm: null,
    rows: [],
    rowsById: {},
    items: [],
    slots: [],
    endpoints: {},
    SLOT_KEY_LABELS: {
        amulet: 'Ciondolo',
        helm: 'Elmo',
        weapon_1: 'Arma primaria',
        weapon_2: 'Arma secondaria',
        gloves: 'Guanti',
        armor: 'Armatura',
        ring_1: 'Anello sinistro',
        ring_2: 'Anello destro',
        boots: 'Stivali'
    },

    resolveEndpoints: function () {
        var moduleEndpoints = globalWindow.LogeonModuleEndpoints || {};
        var list = String(moduleEndpoints.itemEquipmentRulesList || '').trim();
        var create = String(moduleEndpoints.itemEquipmentRulesCreate || '').trim();
        var update = String(moduleEndpoints.itemEquipmentRulesUpdate || '').trim();
        var remove = String(moduleEndpoints.itemEquipmentRulesDelete || '').trim();
        var equipmentSlotsList = String(moduleEndpoints.equipmentSlotsList || '').trim();
        if (!list || !create || !update || !remove) {
            return false;
        }
        this.endpoints = {
            list: list,
            create: create,
            update: update,
            'delete': remove,
            equipmentSlotsList: equipmentSlotsList
        };
        return true;
    },

    init: function () {
        if (this.initialized) {
            return this;
        }

        this.root = document.querySelector('#admin-page [data-admin-page="item-equipment-rules"]');
        if (!this.root) {
            return this;
        }

        this.filtersForm = this.root.querySelector('#admin-item-equipment-rules-filters');
        this.modalNode = this.root.querySelector('#admin-item-equipment-rules-modal');
        this.modalForm = this.root.querySelector('#admin-item-equipment-rules-form');

        if (!this.filtersForm || !this.modalNode || !this.modalForm) {
            return this;
        }
        if (!this.resolveEndpoints()) {
            return this;
        }

        this.modal = new bootstrap.Modal(this.modalNode);
        this.bind();
        this.initGrid();
        this.loadDependencies(function () {
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
        if (this.modalForm) {
            this.modalForm.addEventListener('change', function (event) {
                var target = event && event.target ? event.target : null;
                if (!target || !target.name) { return; }
                if (String(target.name) === 'requires_ammo') {
                    self.toggleAmmoFields(String(target.value || '0') === '1');
                } else if (String(target.name) === 'durability_enabled') {
                    self.toggleDurabilityFields(String(target.value || '1') === '1');
                } else if (String(target.name) === 'jam_enabled') {
                    self.toggleJamFields(String(target.value || '0') === '1');
                }
            });
        }

        this.root.addEventListener('click', function (event) {
            var trigger = event.target.closest('[data-action]');
            if (!trigger) { return; }
            var action = String(trigger.getAttribute('data-action') || '').trim();

            if (action === 'admin-item-equipment-rules-reload') {
                event.preventDefault();
                self.loadGrid();
            } else if (action === 'admin-item-equipment-rules-create') {
                event.preventDefault();
                self.openCreate();
            } else if (action === 'admin-item-equipment-rules-edit') {
                event.preventDefault();
                self.openEdit(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0);
            } else if (action === 'admin-item-equipment-rules-save') {
                event.preventDefault();
                self.save();
            } else if (action === 'admin-item-equipment-rules-delete') {
                event.preventDefault();
                self.remove();
            }
        });
    },

    initGrid: function () {
        var self = this;

        this.grid = new Datagrid('grid-admin-item-equipment-rules', {
            name: 'AdminItemEquipmentRules',
            autoindex: 'id',
            orderable: true,
            thead: true,
            handler: { url: this.endpoints.list, action: 'list' },
            nav: { display: 'bottom', urlupdate: 0, results: 30, page: 1 },
            onGetDataSuccess: function (response) {
                self.setRows(response && Array.isArray(response.dataset) ? response.dataset : []);
            },
            onGetDataError: function () {
                self.setRows([]);
            },
            columns: [
                { label: 'ID', field: 'id', sortable: true, style: { textAlign: 'center', width: '60px' } },
                {
                    label: 'Oggetto',
                    field: 'item_name',
                    sortable: true,
                    style: { textAlign: 'left' },
                    format: function (row) {
                        return self.escapeHtml(row.item_name || ('#' + (row.item_id || '-')));
                    }
                },
                {
                    label: 'Slot equipaggiamento',
                    field: 'slot_name',
                    sortable: true,
                    style: { textAlign: 'left', width: '220px' },
                    format: function (row) {
                        var slotName = row.slot_name || '';
                        var slotKey = row.slot_key || '';
                        if (!slotName && !slotKey) {
                            return '<span class="text-muted">-</span>';
                        }
                        var translated = slotName || self.getSlotKeyLabel(slotKey);
                        return self.escapeHtml(translated || slotKey)
                            + (slotKey ? ' <code class="small">' + self.escapeHtml(slotKey) + '</code>' : '');
                    }
                },
                {
                    label: 'Priorita',
                    field: 'priority',
                    sortable: true,
                    style: { textAlign: 'center', width: '90px' },
                    format: function (row) {
                        return parseInt(row.priority || '0', 10) || 0;
                    }
                },
                {
                    label: 'Impugnatura',
                    sortable: false,
                    style: { textAlign: 'center', width: '130px' },
                    format: function (row) {
                        if (self.extractTwoHanded(row)) {
                            return '<span class="badge text-bg-warning">Due mani</span>';
                        }
                        return '<span class="badge text-bg-secondary">Una mano</span>';
                    }
                },
                {
                    label: 'Munizioni',
                    sortable: false,
                    style: { textAlign: 'left', width: '250px' },
                    format: function (row) {
                        var ammo = self.extractAmmoRule(row);
                        if (!ammo.requires_ammo) {
                            return '<span class="text-muted">Non richieste</span>';
                        }
                        var label = ammo.ammo_item_name || ('Item #' + ammo.ammo_item_id);
                        var reloadInfo = (ammo.ammo_magazine_size > 0)
                            ? ' - caricatore ' + self.escapeHtml(String(ammo.ammo_magazine_size))
                            : '';
                        return '<span class="badge text-bg-info me-1">Richieste</span>'
                            + self.escapeHtml(label)
                            + ' <span class="text-muted small">(x' + self.escapeHtml(String(ammo.ammo_per_use)) + reloadInfo + ')</span>';
                    }
                },
                {
                    label: 'Qualita',
                    sortable: false,
                    style: { textAlign: 'left', width: '220px' },
                    format: function (row) {
                        var durability = self.extractDurabilityRule(row);
                        if (!durability.durability_enabled) {
                            return '<span class="text-muted">Disattivata</span>';
                        }
                        return '<span class="badge text-bg-primary me-1">Attiva</span>'
                            + '<span class="small text-muted">max '
                            + self.escapeHtml(String(durability.durability_max))
                            + ' · uso -'
                            + self.escapeHtml(String(durability.durability_loss_on_use))
                            + ' · equip -'
                            + self.escapeHtml(String(durability.durability_loss_on_equip))
                            + '</span>';
                    }
                },
                {
                    label: 'Inceppamento',
                    sortable: false,
                    style: { textAlign: 'left', width: '170px' },
                    format: function (row) {
                        var jam = self.extractJamRule(row);
                        if (!jam.jam_enabled) {
                            return '<span class="text-muted">No</span>';
                        }
                        return '<span class="badge text-bg-danger me-1">Si</span>'
                            + '<span class="small text-muted">' + self.escapeHtml(String(jam.jam_chance_percent)) + '%</span>';
                    }
                },
                {
                    label: 'Azioni',
                    sortable: false,
                    style: { textAlign: 'center', width: '80px' },
                    format: function (row) {
                        var id = self.escapeAttr(String(row.id || '0'));
                        return '<button type="button" class="btn btn-sm btn-outline-secondary" data-action="admin-item-equipment-rules-edit" data-id="' + id + '"><i class="bi bi-pencil"></i></button>';
                    }
                }
            ]
        });
    },

    setRows: function (rows) {
        this.rows = rows || [];
        this.rowsById = {};
        for (var i = 0; i < this.rows.length; i++) {
            var row = this.rows[i];
            if (row && row.id) {
                this.rowsById[row.id] = row;
            }
        }
    },

    loadDependencies: function (done) {
        var self = this;
        var missing = 2;
        var check = function () {
            missing -= 1;
            if (missing <= 0 && typeof done === 'function') {
                done();
            }
        };

        this.post('/admin/items/list', { query: {}, page: 1, results: 500, orderBy: 'name|ASC' }, function (response) {
            self.items = response && Array.isArray(response.dataset) ? response.dataset : [];
            self.fillItemSelects();
            check();
        }, function () {
            self.items = [];
            self.fillItemSelects();
            check();
        });

        var equipmentSlotsEndpoint = String(this.endpoints.equipmentSlotsList || '').trim();
        if (!equipmentSlotsEndpoint) {
            self.slots = [];
            self.fillSlotSelects();
            check();
            return;
        }

        this.post(equipmentSlotsEndpoint, { query: {}, page: 1, results: 200, orderBy: 'sort_order|ASC' }, function (response) {
            self.slots = response && Array.isArray(response.dataset) ? response.dataset : [];
            self.fillSlotSelects();
            check();
        }, function () {
            self.slots = [];
            self.fillSlotSelects();
            check();
        });
    },

    fillItemSelects: function () {
        var selects = [
            this.filtersForm ? this.filtersForm.querySelector('[name="item_id"]') : null,
            this.modalForm ? this.modalForm.querySelector('[name="item_id"]') : null,
            this.modalForm ? this.modalForm.querySelector('[name="ammo_item_id"]') : null
        ];
        for (var s = 0; s < selects.length; s++) {
            var select = selects[s];
            if (!select) { continue; }
            var current = String(select.value || '');
            while (select.options.length > 1) { select.remove(1); }
            for (var i = 0; i < this.items.length; i++) {
                var row = this.items[i] || {};
                var id = parseInt(row.id || '0', 10) || 0;
                if (id <= 0) { continue; }
                var opt = document.createElement('option');
                opt.value = String(id);
                var itemName = row.name || ('Oggetto #' + id);
                var itemType = String(row.type || '').trim();
                opt.textContent = itemType !== '' ? (itemName + ' - ' + itemType) : itemName;
                select.appendChild(opt);
            }
            if (current !== '') {
                select.value = current;
            }
        }
    },

    fillSlotSelects: function () {
        var selects = [
            this.filtersForm ? this.filtersForm.querySelector('[name="slot_id"]') : null,
            this.modalForm ? this.modalForm.querySelector('[name="slot_id"]') : null
        ];
        for (var s = 0; s < selects.length; s++) {
            var select = selects[s];
            if (!select) { continue; }
            var current = String(select.value || '');
            while (select.options.length > 1) { select.remove(1); }
            for (var i = 0; i < this.slots.length; i++) {
                var row = this.slots[i] || {};
                var id = parseInt(row.id || '0', 10) || 0;
                if (id <= 0) { continue; }
                var slotName = row.name || '';
                var slotKey = row.key || '';
                var opt = document.createElement('option');
                opt.value = String(id);
                var translated = slotName || this.getSlotKeyLabel(slotKey);
                opt.textContent = translated !== '' ? translated + (slotKey ? ' (' + slotKey + ')' : '') : ('Slot #' + id);
                select.appendChild(opt);
            }
            if (current !== '') {
                select.value = current;
            }
        }
    },

    getSlotKeyLabel: function (value) {
        var key = String(value || '').trim();
        if (!key) {
            return '';
        }
        return this.SLOT_KEY_LABELS[key] || key;
    },

    parseRuleMeta: function (row) {
        if (!row || typeof row !== 'object') {
            return {};
        }

        var raw = row.metadata_json;
        if (raw && typeof raw === 'object') {
            return raw;
        }
        if (typeof raw !== 'string') {
            return {};
        }

        try {
            var parsed = JSON.parse(raw);
            return (parsed && typeof parsed === 'object') ? parsed : {};
        } catch (error) {
            return {};
        }
    },

    toBool: function (value) {
        if (value === true || value === 1) {
            return true;
        }
        var raw = String(value == null ? '' : value).trim().toLowerCase();
        return raw === '1' || raw === 'true' || raw === 'yes' || raw === 'on';
    },

    extractTwoHanded: function (row) {
        var meta = this.parseRuleMeta(row);
        return this.toBool(meta.is_two_handed) || this.toBool(meta.two_handed);
    },

    extractAmmoRule: function (row) {
        var meta = this.parseRuleMeta(row);
        var requiresAmmo = this.toBool(meta.requires_ammo) || this.toBool(meta.ammo_required);
        var ammoItemId = parseInt(meta.ammo_item_id || '0', 10) || 0;
        var ammoPerUse = parseInt(meta.ammo_per_use || '1', 10) || 1;
        var ammoMagazineSize = parseInt(meta.ammo_magazine_size || '0', 10) || 0;
        if (ammoPerUse < 1) {
            ammoPerUse = 1;
        }
        if (ammoMagazineSize < 0) {
            ammoMagazineSize = 0;
        }
        if (ammoMagazineSize > 0 && ammoPerUse > ammoMagazineSize) {
            ammoPerUse = ammoMagazineSize;
        }

        var ammoItemName = '';
        if (ammoItemId > 0 && Array.isArray(this.items)) {
            for (var i = 0; i < this.items.length; i++) {
                var item = this.items[i] || {};
                var id = parseInt(item.id || '0', 10) || 0;
                if (id === ammoItemId) {
                    ammoItemName = String(item.name || '').trim();
                    break;
                }
            }
        }

        return {
            requires_ammo: (requiresAmmo || ammoItemId > 0),
            ammo_item_id: ammoItemId,
            ammo_item_name: ammoItemName,
            ammo_per_use: ammoPerUse,
            ammo_magazine_size: ammoMagazineSize
        };
    },

    extractDurabilityRule: function (row) {
        var meta = this.parseRuleMeta(row);
        var durabilityEnabled = this.toBool(meta.durability_enabled) || this.toBool(meta.quality_enabled);
        if (!Object.prototype.hasOwnProperty.call(meta, 'durability_enabled')
            && !Object.prototype.hasOwnProperty.call(meta, 'quality_enabled')
        ) {
            durabilityEnabled = true;
        }

        var durabilityMax = parseInt(meta.durability_max || '100', 10) || 100;
        if (durabilityMax < 1) {
            durabilityMax = 100;
        }
        var durabilityLossOnUse = parseInt(meta.durability_loss_on_use || '1', 10);
        if (isNaN(durabilityLossOnUse) || durabilityLossOnUse < 0) {
            durabilityLossOnUse = 1;
        }
        var durabilityLossOnEquip = parseInt(meta.durability_loss_on_equip || '1', 10);
        if (isNaN(durabilityLossOnEquip) || durabilityLossOnEquip < 0) {
            durabilityLossOnEquip = 1;
        }

        return {
            durability_enabled: durabilityEnabled,
            durability_max: durabilityMax,
            durability_loss_on_use: durabilityLossOnUse,
            durability_loss_on_equip: durabilityLossOnEquip
        };
    },

    extractJamRule: function (row) {
        var meta = this.parseRuleMeta(row);
        var jamEnabled = this.toBool(meta.jam_enabled);
        var jamChance = parseInt(meta.jam_chance_percent || '5', 10);
        if (isNaN(jamChance) || jamChance < 0) {
            jamChance = 5;
        }
        if (jamChance > 100) {
            jamChance = 100;
        }

        return {
            jam_enabled: jamEnabled,
            jam_chance_percent: jamChance
        };
    },

    toggleAmmoFields: function (enabled) {
        if (!this.modalForm) {
            return this;
        }

        var show = (enabled === true);
        var itemWrap = this.modalForm.querySelector('[data-role="ammo-item-wrap"]');
        var perUseWrap = this.modalForm.querySelector('[data-role="ammo-per-use-wrap"]');
        var magazineWrap = this.modalForm.querySelector('[data-role="ammo-magazine-wrap"]');
        if (itemWrap) { itemWrap.classList.toggle('d-none', !show); }
        if (perUseWrap) { perUseWrap.classList.toggle('d-none', !show); }
        if (magazineWrap) { magazineWrap.classList.toggle('d-none', !show); }
        if (!show) {
            this.setField('jam_enabled', '0');
            this.toggleJamFields(false);
        }
        return this;
    },

    toggleDurabilityFields: function (enabled) {
        if (!this.modalForm) {
            return this;
        }

        var show = (enabled === true);
        var maxWrap = this.modalForm.querySelector('[data-role="durability-max-wrap"]');
        var useWrap = this.modalForm.querySelector('[data-role="durability-loss-use-wrap"]');
        var equipWrap = this.modalForm.querySelector('[data-role="durability-loss-equip-wrap"]');
        if (maxWrap) { maxWrap.classList.toggle('d-none', !show); }
        if (useWrap) { useWrap.classList.toggle('d-none', !show); }
        if (equipWrap) { equipWrap.classList.toggle('d-none', !show); }
        return this;
    },

    toggleJamFields: function (enabled) {
        if (!this.modalForm) {
            return this;
        }

        var show = (enabled === true);
        var jamWrap = this.modalForm.querySelector('[data-role="jam-chance-wrap"]');
        if (jamWrap) { jamWrap.classList.toggle('d-none', !show); }
        return this;
    },

    loadGrid: function () {
        if (!this.grid || typeof this.grid.loadData !== 'function') {
            return this;
        }
        this.grid.loadData(this.buildFiltersPayload(), 30, 1, 'priority|ASC');
        return this;
    },

    buildFiltersPayload: function () {
        var q = {};
        if (!this.filtersForm) {
            return q;
        }
        var itemId = (this.filtersForm.querySelector('[name="item_id"]') || {}).value || '';
        var slotId = (this.filtersForm.querySelector('[name="slot_id"]') || {}).value || '';
        if (itemId) { q.item_id = parseInt(itemId, 10) || 0; }
        if (slotId) { q.slot_id = parseInt(slotId, 10) || 0; }
        return q;
    },

    openCreate: function () {
        if (this.modalForm) { this.modalForm.reset(); }
        this.fillItemSelects();
        this.fillSlotSelects();
        this.setField('id', '');
        this.setField('priority', '10');
        this.setField('is_two_handed', '0');
        this.setField('requires_ammo', '0');
        this.setField('ammo_item_id', '');
        this.setField('ammo_per_use', '1');
        this.setField('ammo_magazine_size', '0');
        this.setField('durability_enabled', '1');
        this.setField('durability_max', '100');
        this.setField('durability_loss_on_use', '1');
        this.setField('durability_loss_on_equip', '1');
        this.setField('jam_enabled', '0');
        this.setField('jam_chance_percent', '5');
        this.toggleAmmoFields(false);
        this.toggleDurabilityFields(true);
        this.toggleJamFields(false);
        this.toggleDelete(false);
        this.modal.show();
    },

    openEdit: function (id) {
        var row = this.rowsById[id] || null;
        if (!row) { return; }
        if (this.modalForm) { this.modalForm.reset(); }
        this.fillItemSelects();
        this.fillSlotSelects();
        this.setField('id', String(row.id || ''));
        this.setField('item_id', String(parseInt(row.item_id || '0', 10) || ''));
        this.setField('slot_id', String(parseInt(row.slot_id || '0', 10) || ''));
        this.setField('priority', String(parseInt(row.priority || '10', 10) || 10));
        this.setField('is_two_handed', this.extractTwoHanded(row) ? '1' : '0');
        var ammo = this.extractAmmoRule(row);
        this.setField('requires_ammo', ammo.requires_ammo ? '1' : '0');
        this.setField('ammo_item_id', ammo.ammo_item_id > 0 ? String(ammo.ammo_item_id) : '');
        this.setField('ammo_per_use', String(ammo.ammo_per_use || 1));
        this.setField('ammo_magazine_size', String(ammo.ammo_magazine_size || 0));
        this.toggleAmmoFields(ammo.requires_ammo);
        var durability = this.extractDurabilityRule(row);
        this.setField('durability_enabled', durability.durability_enabled ? '1' : '0');
        this.setField('durability_max', String(durability.durability_max || 100));
        this.setField('durability_loss_on_use', String(durability.durability_loss_on_use || 0));
        this.setField('durability_loss_on_equip', String(durability.durability_loss_on_equip || 0));
        this.toggleDurabilityFields(durability.durability_enabled);
        var jam = this.extractJamRule(row);
        this.setField('jam_enabled', jam.jam_enabled ? '1' : '0');
        this.setField('jam_chance_percent', String(jam.jam_chance_percent || 0));
        this.toggleJamFields(jam.jam_enabled);
        this.toggleDelete(true);
        this.modal.show();
    },

    toggleDelete: function (show) {
        var btn = this.modalNode ? this.modalNode.querySelector('[data-action="admin-item-equipment-rules-delete"]') : null;
        if (btn) {
            btn.classList.toggle('d-none', !show);
        }
    },

    setField: function (name, value) {
        var el = this.modalForm ? this.modalForm.querySelector('[name="' + name + '"]') : null;
        if (el) {
            el.value = value;
        }
    },

    getField: function (name) {
        var el = this.modalForm ? this.modalForm.querySelector('[name="' + name + '"]') : null;
        return el ? String(el.value || '') : '';
    },

    collectPayload: function () {
        return {
            id: parseInt(this.getField('id'), 10) || 0,
            item_id: parseInt(this.getField('item_id'), 10) || 0,
            slot_id: parseInt(this.getField('slot_id'), 10) || 0,
            priority: parseInt(this.getField('priority'), 10) || 10,
            is_two_handed: (this.getField('is_two_handed') === '1') ? 1 : 0,
            requires_ammo: (this.getField('requires_ammo') === '1') ? 1 : 0,
            ammo_item_id: parseInt(this.getField('ammo_item_id'), 10) || 0,
            ammo_per_use: parseInt(this.getField('ammo_per_use'), 10) || 1,
            ammo_magazine_size: parseInt(this.getField('ammo_magazine_size'), 10) || 0,
            durability_enabled: (this.getField('durability_enabled') === '1') ? 1 : 0,
            durability_max: parseInt(this.getField('durability_max'), 10) || 100,
            durability_loss_on_use: parseInt(this.getField('durability_loss_on_use'), 10) || 0,
            durability_loss_on_equip: parseInt(this.getField('durability_loss_on_equip'), 10) || 0,
            jam_enabled: (this.getField('jam_enabled') === '1') ? 1 : 0,
            jam_chance_percent: parseInt(this.getField('jam_chance_percent'), 10) || 0
        };
    },

    save: function () {
        var payload = this.collectPayload();
        if (payload.item_id <= 0 || payload.slot_id <= 0) {
            Toast.show({ body: 'Oggetto e slot sono obbligatori.', type: 'error' });
            return;
        }
        if (payload.requires_ammo === 1) {
            if (payload.ammo_item_id <= 0) {
                Toast.show({ body: 'Seleziona il tipo di munizione.', type: 'warning' });
                return;
            }
            if (payload.ammo_per_use < 1) {
                payload.ammo_per_use = 1;
            }
            if (payload.ammo_magazine_size < 0) {
                payload.ammo_magazine_size = 0;
            }
            if (payload.ammo_magazine_size > 0 && payload.ammo_per_use > payload.ammo_magazine_size) {
                payload.ammo_per_use = payload.ammo_magazine_size;
            }
        } else {
            payload.ammo_item_id = 0;
            payload.ammo_per_use = 1;
            payload.ammo_magazine_size = 0;
        }

        if (payload.durability_enabled === 1) {
            if (payload.durability_max < 1) {
                payload.durability_max = 100;
            }
            if (payload.durability_loss_on_use < 0) {
                payload.durability_loss_on_use = 0;
            }
            if (payload.durability_loss_on_equip < 0) {
                payload.durability_loss_on_equip = 0;
            }
        } else {
            payload.durability_max = 100;
            payload.durability_loss_on_use = 0;
            payload.durability_loss_on_equip = 0;
        }

        if (payload.jam_enabled === 1) {
            if (payload.requires_ammo !== 1) {
                Toast.show({ body: 'L\'inceppamento richiede munizioni attive.', type: 'warning' });
                return;
            }
            if (payload.jam_chance_percent < 0) {
                payload.jam_chance_percent = 0;
            }
            if (payload.jam_chance_percent > 100) {
                payload.jam_chance_percent = 100;
            }
        } else {
            payload.jam_chance_percent = 0;
        }

        var isNew = payload.id <= 0;
        var url = isNew ? this.endpoints.create : this.endpoints.update;
        var self = this;

        this.post(url, payload, function () {
            self.modal.hide();
            Toast.show({ body: isNew ? 'Regola creata.' : 'Regola aggiornata.', type: 'success' });
            self.loadGrid();
        });
    },

    remove: function () {
        var payload = this.collectPayload();
        if (payload.id <= 0) {
            return;
        }
        if (!confirm('Eliminare questa regola? L\'operazione non puo essere annullata.')) {
            return;
        }
        var self = this;
        this.post(this.endpoints['delete'], { id: payload.id }, function () {
            self.modal.hide();
            Toast.show({ body: 'Regola eliminata.', type: 'success' });
            self.loadGrid();
        });
    },

    post: function (url, payload, onSuccess, onError) {
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
            var msg = (error && error.message) ? error.message : 'Errore di rete.';
            Toast.show({ body: msg, type: 'error' });
        });
        return this;
    },

    escapeHtml: function (value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    },

    escapeAttr: function (value) {
        return String(value || '')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }
};

globalWindow.AdminItemEquipmentRules = AdminItemEquipmentRules;
export { AdminItemEquipmentRules as AdminItemEquipmentRules };
export default AdminItemEquipmentRules;

