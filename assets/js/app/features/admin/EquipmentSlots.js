const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

var AdminEquipmentSlots = {
    initialized: false,
    root: null,
    filtersForm: null,
    grid: null,
    modalNode: null,
    modal: null,
    modalForm: null,
    rows: [],
    rowsById: {},
    endpoints: {},
    SLOT_OPTIONS: [
        { value: 'amulet', label: 'Ciondolo', group: 'amulet' },
        { value: 'helm', label: 'Elmo', group: 'helm' },
        { value: 'weapon_1', label: 'Arma primaria', group: 'weapon' },
        { value: 'weapon_2', label: 'Arma secondaria', group: 'weapon' },
        { value: 'gloves', label: 'Guanti', group: 'gloves' },
        { value: 'armor', label: 'Armatura', group: 'armor' },
        { value: 'ring_1', label: 'Anello sinistro', group: 'ring' },
        { value: 'ring_2', label: 'Anello destro', group: 'ring' },
        { value: 'boots', label: 'Stivali', group: 'boots' },
        { value: 'custom_1', label: 'Slot custom 1', group: 'custom' },
        { value: 'custom_2', label: 'Slot custom 2', group: 'custom' },
        { value: 'custom_3', label: 'Slot custom 3', group: 'custom' },
        { value: 'custom_4', label: 'Slot custom 4', group: 'custom' },
        { value: 'custom_5', label: 'Slot custom 5', group: 'custom' },
        { value: 'custom_6', label: 'Slot custom 6', group: 'custom' },
        { value: 'custom_7', label: 'Slot custom 7', group: 'custom' },
        { value: 'custom_8', label: 'Slot custom 8', group: 'custom' },
        { value: 'custom_9', label: 'Slot custom 9', group: 'custom' },
        { value: 'custom_10', label: 'Slot custom 10', group: 'custom' },
        { value: 'custom_11', label: 'Slot custom 11', group: 'custom' },
        { value: 'custom_12', label: 'Slot custom 12', group: 'custom' }
    ],
    CORE_SLOT_KEYS: [
        'amulet',
        'helm',
        'weapon_1',
        'weapon_2',
        'gloves',
        'armor',
        'ring_1',
        'ring_2',
        'boots'
    ],
    GROUP_OPTIONS: [
        { value: 'weapon', label: 'Armi' },
        { value: 'ring', label: 'Anelli' },
        { value: 'amulet', label: 'Ciondoli' },
        { value: 'helm', label: 'Elmi' },
        { value: 'gloves', label: 'Guanti' },
        { value: 'armor', label: 'Armature' },
        { value: 'boots', label: 'Stivali' },
        { value: 'custom', label: 'Custom' }
    ],

    resolveEndpoints: function () {
        var moduleEndpoints = globalWindow.LogeonModuleEndpoints || {};
        var list = String(moduleEndpoints.equipmentSlotsList || '').trim();
        var create = String(moduleEndpoints.equipmentSlotsCreate || '').trim();
        var update = String(moduleEndpoints.equipmentSlotsUpdate || '').trim();
        var remove = String(moduleEndpoints.equipmentSlotsDelete || '').trim();
        if (!list || !create || !update || !remove) {
            return false;
        }
        this.endpoints = {
            list: list,
            create: create,
            update: update,
            'delete': remove
        };
        return true;
    },

    init: function () {
        if (this.initialized) {
            return this;
        }

        this.root = document.querySelector('#admin-page [data-admin-page="equipment-slots"]');
        if (!this.root) {
            return this;
        }

        this.filtersForm = this.root.querySelector('#admin-equipment-slots-filters');
        this.modalNode = this.root.querySelector('#admin-equipment-slots-modal');
        this.modalForm = this.root.querySelector('#admin-equipment-slots-form');

        if (!this.filtersForm || !this.modalNode || !this.modalForm) {
            return this;
        }
        if (!this.resolveEndpoints()) {
            return this;
        }

        this.modal = new bootstrap.Modal(this.modalNode);
        this.bind();
        this.fillKeySelects();
        this.fillGroupSelects();
        this.initGrid();
        this.loadGrid();

        this.initialized = true;
        return this;
    },

    bind: function () {
        var self = this;

        this.filtersForm.addEventListener('submit', function (event) {
            event.preventDefault();
            self.loadGrid();
        });

        var modalKey = this.modalForm ? this.modalForm.querySelector('[name="key"]') : null;
        if (modalKey) {
            modalKey.addEventListener('change', function () {
                self.syncGroupFromKey(false);
            });
        }

        var iconInput = this.modalForm ? this.modalForm.querySelector('[name="icon"]') : null;
        if (iconInput) {
            iconInput.addEventListener('input', function () {
                self.setIconPreview(iconInput.value);
            });
        }

        this.root.addEventListener('click', function (event) {
            var trigger = event.target.closest('[data-action]');
            if (!trigger) { return; }
            var action = String(trigger.getAttribute('data-action') || '').trim();

            if (action === 'admin-equipment-slots-reload') {
                event.preventDefault();
                self.loadGrid();
            } else if (action === 'admin-equipment-slots-create') {
                event.preventDefault();
                self.openCreate();
            } else if (action === 'admin-equipment-slots-edit') {
                event.preventDefault();
                self.openEdit(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0);
            } else if (action === 'admin-equipment-slots-save') {
                event.preventDefault();
                self.save();
            } else if (action === 'admin-equipment-slots-delete') {
                event.preventDefault();
                self.remove();
            }
        });
    },

    initGrid: function () {
        var self = this;

        this.grid = new Datagrid('grid-admin-equipment-slots', {
            name: 'AdminEquipmentSlots',
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
                    label: 'Chiave slot',
                    field: 'key',
                    sortable: true,
                    style: { textAlign: 'left', width: '170px' },
                    format: function (row) {
                        if (!row.key) {
                            return '<span class="text-muted">-</span>';
                        }
                        var key = String(row.key || '');
                        return self.escapeHtml(self.getSlotKeyLabel(key))
                            + '<div><code class="small">' + self.escapeHtml(key) + '</code></div>';
                    }
                },
                {
                    label: 'Tipo',
                    sortable: false,
                    style: { textAlign: 'center', width: '95px' },
                    format: function (row) {
                        var key = String(row.key || '').trim();
                        if (self.isCoreSlotKey(key)) {
                            return '<span class="badge text-bg-info">Core</span>';
                        }
                        return '<span class="badge text-bg-primary">Custom</span>';
                    }
                },
                {
                    label: 'Nome',
                    field: 'name',
                    sortable: true,
                    style: { textAlign: 'left', width: '200px' },
                    format: function (row) {
                        return self.escapeHtml(row.name || '-');
                    }
                },
                {
                    label: 'Gruppo slot',
                    field: 'group_key',
                    sortable: true,
                    style: { textAlign: 'left', width: '140px' },
                    format: function (row) {
                        if (!row.group_key) {
                            return '<span class="text-muted">-</span>';
                        }
                        var groupKey = String(row.group_key || '');
                        return self.escapeHtml(self.getGroupLabel(groupKey))
                            + '<div><code class="small">' + self.escapeHtml(groupKey) + '</code></div>';
                    }
                },
                {
                    label: 'Max equip',
                    field: 'max_equipped',
                    sortable: true,
                    style: { textAlign: 'center', width: '70px' },
                    format: function (row) {
                        return parseInt(row.max_equipped || '1', 10) || 1;
                    }
                },
                {
                    label: 'Ordine',
                    field: 'sort_order',
                    sortable: true,
                    style: { textAlign: 'center', width: '80px' },
                    format: function (row) {
                        return parseInt(row.sort_order || '0', 10) || 0;
                    }
                },
                {
                    label: 'Stato',
                    field: 'is_active',
                    sortable: true,
                    style: { textAlign: 'center', width: '90px' },
                    format: function (row) {
                        return parseInt(row.is_active || '0', 10) === 1
                            ? '<span class="badge text-bg-success">Attivo</span>'
                            : '<span class="badge text-bg-secondary">Inattivo</span>';
                    }
                },
                {
                    label: 'Azioni',
                    sortable: false,
                    style: { textAlign: 'center', width: '80px' },
                    format: function (row) {
                        var id = self.escapeAttr(String(row.id || '0'));
                        return '<button type="button" class="btn btn-sm btn-outline-secondary" data-action="admin-equipment-slots-edit" data-id="' + id + '"><i class="bi bi-pencil"></i></button>';
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

    loadGrid: function () {
        if (!this.grid || typeof this.grid.loadData !== 'function') {
            return this;
        }
        this.grid.loadData(this.buildFiltersPayload(), 30, 1, 'sort_order|ASC');
        return this;
    },

    buildFiltersPayload: function () {
        var q = {};
        var key = '';
        var name = '';
        var active = '';
        if (this.filtersForm) {
            key = (this.filtersForm.querySelector('[name="key"]') || {}).value || '';
            name = (this.filtersForm.querySelector('[name="name"]') || {}).value || '';
            active = (this.filtersForm.querySelector('[name="is_active"]') || {}).value || '';
        }
        if (key) { q.key = key; }
        if (name) { q.name = name; }
        if (active !== '') { q.is_active = active; }
        return q;
    },

    openCreate: function () {
        if (this.modalForm) { this.modalForm.reset(); }
        this.fillKeySelects();
        this.fillGroupSelects();
        this.setField('id', '');
        this.setField('max_equipped', '1');
        this.setField('sort_order', '0');
        this.setField('is_active', '1');
        this.syncGroupFromKey(true);
        this.setIconPreview('');
        this.setCoreEditMode(false);
        this.toggleDelete(false, false);
        this.modal.show();
    },

    openEdit: function (id) {
        var row = this.rowsById[id] || null;
        if (!row) { return; }
        if (this.modalForm) { this.modalForm.reset(); }
        this.fillKeySelects();
        this.fillGroupSelects();

        var keyValue = row.key || '';
        var isCoreSlot = this.isCoreSlotKey(keyValue);
        var keySelect = this.modalForm ? this.modalForm.querySelector('[name="key"]') : null;
        this.ensureSelectValue(keySelect, keyValue, this.getSlotKeyLabel(keyValue));

        this.setField('id', String(row.id || ''));
        this.setField('key', keyValue);
        this.setField('name', row.name || '');
        var groupValue = row.group_key || '';
        var groupSelect = this.modalForm ? this.modalForm.querySelector('[name="group_key"]') : null;
        this.ensureSelectValue(groupSelect, groupValue, this.getGroupLabel(groupValue));
        this.setField('group_key', groupValue);
        this.setField('description', row.description || '');
        this.setField('icon', row.icon || '');
        this.setField('sort_order', String(parseInt(row.sort_order || '0', 10) || 0));
        this.setField('max_equipped', String(parseInt(row.max_equipped || '1', 10) || 1));
        this.setField('is_active', String(parseInt(row.is_active || '0', 10) === 1 ? 1 : 0));

        this.setIconPreview(row.icon || '');
        this.setCoreEditMode(isCoreSlot);
        this.toggleDelete(true, isCoreSlot);
        this.modal.show();
    },

    toggleDelete: function (show, isCore) {
        var btn = this.modalNode ? this.modalNode.querySelector('[data-action="admin-equipment-slots-delete"]') : null;
        if (btn) {
            btn.classList.toggle('d-none', !show || isCore === true);
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
            key: this.getField('key').trim(),
            name: this.getField('name').trim(),
            group_key: this.getField('group_key').trim() || null,
            description: this.getField('description').trim() || null,
            icon: this.getField('icon').trim() || null,
            sort_order: parseInt(this.getField('sort_order'), 10) || 0,
            max_equipped: parseInt(this.getField('max_equipped'), 10) || 1,
            is_active: parseInt(this.getField('is_active'), 10) === 1 ? 1 : 0
        };
    },

    save: function () {
        var payload = this.collectPayload();
        if (!payload.key || !payload.name) {
            Toast.show({ body: 'Chiave slot e nome sono obbligatori.', type: 'error' });
            return;
        }

        var isNew = payload.id <= 0;
        var url = isNew ? this.endpoints.create : this.endpoints.update;
        var self = this;

        this.post(url, payload, function () {
            self.modal.hide();
            Toast.show({ body: isNew ? 'Slot creato.' : 'Slot aggiornato.', type: 'success' });
            self.loadGrid();
        });
    },

    remove: function () {
        var payload = this.collectPayload();
        if (payload.id <= 0) {
            return;
        }
        if (this.isCoreSlotKey(payload.key)) {
            Toast.show({ body: 'Gli slot core non possono essere eliminati: puoi disattivarli.', type: 'warning' });
            return;
        }
        if (!confirm('Eliminare questo slot? Verranno rimosse anche le regole associate e gli oggetti equipaggiati in questo slot verranno sganciati.')) {
            return;
        }

        var self = this;
        this.post(this.endpoints['delete'], { id: payload.id }, function (response) {
            self.modal.hide();
            var impact = (response && response.impact) ? response.impact : {};
            var rulesRemoved = parseInt(impact.rules_removed || 0, 10) || 0;
            var itemsUnequipped = parseInt(impact.instances_unequipped || 0, 10) || 0;
            var details = [];
            if (rulesRemoved > 0) {
                details.push('regole rimosse: ' + rulesRemoved);
            }
            if (itemsUnequipped > 0) {
                details.push('oggetti sganciati: ' + itemsUnequipped);
            }
            var message = 'Slot eliminato.';
            if (details.length) {
                message += ' (' + details.join(', ') + ')';
            }
            Toast.show({ body: message, type: 'success' });
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

    fillKeySelects: function () {
        var selects = [
            this.filtersForm ? this.filtersForm.querySelector('[name="key"]') : null,
            this.modalForm ? this.modalForm.querySelector('[name="key"]') : null
        ];

        for (var i = 0; i < selects.length; i++) {
            var select = selects[i];
            if (!select) { continue; }
            var current = String(select.value || '');
            while (select.options.length > 1) {
                select.remove(1);
            }
            for (var j = 0; j < this.SLOT_OPTIONS.length; j++) {
                var optDef = this.SLOT_OPTIONS[j];
                var option = document.createElement('option');
                option.value = optDef.value;
                option.textContent = optDef.label + ' (' + optDef.value + ')';
                select.appendChild(option);
            }
            if (current !== '') {
                this.ensureSelectValue(select, current, this.getSlotKeyLabel(current));
                select.value = current;
            }
        }
    },

    fillGroupSelects: function () {
        var select = this.modalForm ? this.modalForm.querySelector('[name="group_key"]') : null;
        if (!select) {
            return;
        }
        var current = String(select.value || '');
        while (select.options.length > 1) {
            select.remove(1);
        }
        for (var i = 0; i < this.GROUP_OPTIONS.length; i++) {
            var optDef = this.GROUP_OPTIONS[i];
            var option = document.createElement('option');
            option.value = optDef.value;
            option.textContent = optDef.label + ' (' + optDef.value + ')';
            select.appendChild(option);
        }
        if (current !== '') {
            this.ensureSelectValue(select, current, this.getGroupLabel(current));
            select.value = current;
        }
    },

    syncGroupFromKey: function (force) {
        var keySelect = this.modalForm ? this.modalForm.querySelector('[name="key"]') : null;
        var groupSelect = this.modalForm ? this.modalForm.querySelector('[name="group_key"]') : null;
        if (!keySelect || !groupSelect) {
            return;
        }
        var key = String(keySelect.value || '').trim();
        if (!key) {
            return;
        }
        var currentGroup = String(groupSelect.value || '').trim();
        if (!force && currentGroup) {
            return;
        }
        var slotDef = this.getSlotOptionByValue(key);
        var nextGroup = slotDef && slotDef.group ? slotDef.group : key;
        this.ensureSelectValue(groupSelect, nextGroup, this.getGroupLabel(nextGroup));
        groupSelect.value = nextGroup;
    },

    setCoreEditMode: function (enabled) {
        this.setFieldDisabled('key', !!enabled);
        this.setFieldDisabled('group_key', !!enabled);
        this.setCoreHintVisible(!!enabled);
    },

    setFieldDisabled: function (name, disabled) {
        var field = this.modalForm ? this.modalForm.querySelector('[name="' + name + '"]') : null;
        if (!field) {
            return;
        }
        field.disabled = !!disabled;
    },

    setCoreHintVisible: function (visible) {
        var hint = this.modalNode ? this.modalNode.querySelector('[data-role="equipment-slot-core-hint"]') : null;
        if (!hint) {
            return;
        }
        hint.classList.toggle('d-none', !visible);
    },

    isCoreSlotKey: function (value) {
        var key = String(value || '').trim().toLowerCase();
        if (!key) {
            return false;
        }
        return this.CORE_SLOT_KEYS.indexOf(key) !== -1;
    },

    getSlotOptionByValue: function (value) {
        var key = String(value || '').trim();
        if (!key) {
            return null;
        }
        for (var i = 0; i < this.SLOT_OPTIONS.length; i++) {
            if (this.SLOT_OPTIONS[i].value === key) {
                return this.SLOT_OPTIONS[i];
            }
        }
        return null;
    },

    getSlotKeyLabel: function (value) {
        var key = String(value || '').trim();
        var slotDef = this.getSlotOptionByValue(key);
        return slotDef ? slotDef.label : key;
    },

    getGroupLabel: function (value) {
        var groupKey = String(value || '').trim();
        if (!groupKey) {
            return '';
        }
        for (var i = 0; i < this.GROUP_OPTIONS.length; i++) {
            if (this.GROUP_OPTIONS[i].value === groupKey) {
                return this.GROUP_OPTIONS[i].label;
            }
        }
        return groupKey;
    },

    ensureSelectValue: function (select, value, label) {
        if (!select) {
            return;
        }
        var nextValue = String(value || '').trim();
        if (!nextValue) {
            return;
        }
        var found = false;
        for (var i = 0; i < select.options.length; i++) {
            if (String(select.options[i].value || '') === nextValue) {
                found = true;
                break;
            }
        }
        if (found) {
            return;
        }
        var option = document.createElement('option');
        option.value = nextValue;
        option.textContent = (label ? label : nextValue) + ' (' + nextValue + ')';
        select.appendChild(option);
    },

    setIconPreview: function (url) {
        var preview = this.modalNode ? this.modalNode.querySelector('[data-img-preview]') : null;
        if (!preview) {
            return;
        }
        var src = String(url || '').trim();
        if (!src) {
            preview.removeAttribute('src');
            preview.style.display = 'none';
            return;
        }
        preview.setAttribute('src', src);
        preview.style.display = '';
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

globalWindow.AdminEquipmentSlots = AdminEquipmentSlots;
export { AdminEquipmentSlots as AdminEquipmentSlots };
export default AdminEquipmentSlots;

