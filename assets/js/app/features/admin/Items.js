(function () {
    'use strict';

    var AdminItems = {
        initialized: false,
        root: null,
        filtersForm: null,
        grid: null,
        modalNode: null,
        modal: null,
        modalForm: null,
        rows: [],
        rowsById: {},
        categories: [],
        rarities: [],
        types: [],
        equipmentSlots: [],
        editingRow: null,

        TYPE_LABELS: {
            'weapon':     'Arma',
            'armor':      'Armatura',
            'helm':       'Elmo',
            'ring':       'Anello',
            'amulet':     'Ciondolo',
            'boots':      'Stivali',
            'gloves':     'Guanti',
            'consumable': 'Consumabile',
            'quest':      'Quest',
            'misc':       'Miscellaneo'
        },

        TYPE_DEFAULTS: ['weapon', 'armor', 'helm', 'ring', 'amulet', 'boots', 'gloves', 'consumable', 'quest', 'misc'],

        init: function () {
            if (this.initialized) {
                return this;
            }

            this.root = document.querySelector('#admin-page [data-admin-page="items"]');
            if (!this.root) {
                return this;
            }

            this.filtersForm = this.root.querySelector('#admin-items-filters');
            this.modalNode   = this.root.querySelector('#admin-items-modal');
            this.modalForm   = this.root.querySelector('#admin-items-form');

            if (!this.filtersForm || !this.modalNode || !this.modalForm) {
                return this;
            }

            this.modal = new bootstrap.Modal(this.modalNode);
            this.bindModal();
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

            this.root.addEventListener('click', function (event) {
                var trigger = event.target.closest('[data-action]');
                if (!trigger) { return; }
                var action = String(trigger.getAttribute('data-action') || '').trim();

                if (action === 'admin-items-reload') {
                    event.preventDefault();
                    self.loadGrid();
                } else if (action === 'admin-items-create') {
                    event.preventDefault();
                    self.openCreate();
                } else if (action === 'admin-items-edit') {
                    event.preventDefault();
                    var id = parseInt(trigger.getAttribute('data-id') || '0', 10);
                    self.openEdit(id);
                } else if (action === 'admin-items-save') {
                    event.preventDefault();
                    self.save();
                } else if (action === 'admin-items-delete') {
                    event.preventDefault();
                    self.remove();
                }
            });
        },

        bindModal: function () {
            var self = this;

            // Toggle equip_slot visibility based on is_equippable
            var equippableSel = this.modalForm ? this.modalForm.querySelector('[name="is_equippable"]') : null;
            if (equippableSel) {
                equippableSel.addEventListener('change', function () {
                    self.syncEquipSlot();
                });
            }

            // Toggle max_stack visibility based on is_stackable
            var stackableSel = this.modalForm ? this.modalForm.querySelector('[name="is_stackable"]') : null;
            if (stackableSel) {
                stackableSel.addEventListener('change', function () {
                    self.syncMaxStack();
                });
            }
        },

        syncEquipSlot: function () {
            if (!this.modalNode) { return; }
            var wrap = this.modalNode.querySelector('#admin-items-modal-equip-slot-wrap');
            var sel  = this.modalForm ? this.modalForm.querySelector('[name="is_equippable"]') : null;
            if (wrap && sel) {
                wrap.classList.toggle('d-none', sel.value !== '1');
            }
        },

        syncMaxStack: function () {
            if (!this.modalNode) { return; }
            var wrap = this.modalNode.querySelector('#admin-items-modal-max-stack-wrap');
            var sel  = this.modalForm ? this.modalForm.querySelector('[name="is_stackable"]') : null;
            if (wrap && sel) {
                wrap.classList.toggle('d-none', sel.value === '0');
            }
        },

        initGrid: function () {
            var self = this;

            this.grid = new Datagrid('grid-admin-items', {
                name: 'AdminItems',
                autoindex: 'id',
                orderable: true,
                thead: true,
                handler: { url: '/admin/items/list', action: 'list' },
                nav: { display: 'bottom', urlupdate: 0, results: 30, page: 1 },
                onGetDataSuccess: function (response) {
                    self.setRows(response && Array.isArray(response.dataset) ? response.dataset : []);
                },
                onGetDataError: function () {
                    self.setRows([]);
                },
                columns: [
                    {
                        label: 'ID',
                        field: 'id',
                        sortable: true,
                        style: { textAlign: 'center', width: '60px' }
                    },
                    {
                        label: 'Nome',
                        field: 'name',
                        sortable: true,
                        style: { textAlign: 'left' },
                        format: function (row) {
                            return self.escapeHtml(row.name || '-');
                        }
                    },
                    {
                        label: 'Categoria',
                        field: 'category_name',
                        sortable: false,
                        style: { textAlign: 'left', width: '140px' },
                        format: function (row) {
                            return row.category_name
                                ? '<span class="small">' + self.escapeHtml(row.category_name) + '</span>'
                                : '<span class="text-muted">—</span>';
                        }
                    },
                    {
                        label: 'Rarità',
                        field: 'rarity_name',
                        sortable: false,
                        style: { textAlign: 'center', width: '110px' },
                        format: function (row) {
                            if (!row.rarity_name) { return '<span class="text-muted">—</span>'; }
                            var color = row.rarity_color || '#6c757d';
                            return '<span class="badge" style="background-color:' + self.escapeAttr(color) + '">'
                                + self.escapeHtml(row.rarity_name) + '</span>';
                        }
                    },
                    {
                        label: 'Prezzo',
                        field: 'price',
                        sortable: true,
                        style: { textAlign: 'center', width: '80px' },
                        format: function (row) {
                            return parseInt(row.price, 10) || 0;
                        }
                    },
                    {
                        label: 'Stack',
                        field: 'is_stackable',
                        sortable: false,
                        style: { textAlign: 'center', width: '70px' },
                        format: function (row) {
                            return parseInt(row.is_stackable, 10) === 1
                                ? '<i class="bi bi-check-lg text-success"></i>'
                                : '<i class="bi bi-x-lg text-muted"></i>';
                        }
                    },
                    {
                        label: 'Equip.',
                        field: 'is_equippable',
                        sortable: false,
                        style: { textAlign: 'center', width: '70px' },
                        format: function (row) {
                            return parseInt(row.is_equippable, 10) === 1
                                ? '<i class="bi bi-check-lg text-success"></i>'
                                : '<i class="bi bi-x-lg text-muted"></i>';
                        }
                    },
                    {
                        label: 'Azioni',
                        sortable: false,
                        style: { textAlign: 'center', width: '80px' },
                        format: function (row) {
                            var id = self.escapeAttr(String(row.id || ''));
                            return '<button type="button" class="btn btn-sm btn-outline-secondary"'
                                + ' data-action="admin-items-edit" data-id="' + id + '">'
                                + '<i class="bi bi-pencil"></i></button>';
                        }
                    }
                ]
            });
        },

        setRows: function (rows) {
            this.rows     = rows || [];
            this.rowsById = {};
            for (var i = 0; i < this.rows.length; i++) {
                var r = this.rows[i];
                if (r && r.id) { this.rowsById[r.id] = r; }
            }
        },

        loadGrid: function () {
            if (!this.grid || typeof this.grid.loadData !== 'function') { return this; }
            this.grid.loadData(this.buildFiltersPayload(), 30, 1, 'name|ASC');
            return this;
        },

        buildFiltersPayload: function () {
            var q = {};
            if (this.filtersForm) {
                var name       = (this.filtersForm.querySelector('[name="name"]')        || {}).value || '';
                var categoryId = (this.filtersForm.querySelector('[name="category_id"]') || {}).value || '';
                var rarityId   = (this.filtersForm.querySelector('[name="rarity_id"]')   || {}).value || '';
                if (name)       { q.name        = name; }
                if (categoryId) { q.category_id = categoryId; }
                if (rarityId)   { q.rarity_id   = rarityId; }
            }
            return q;
        },

        // ── Dependencies ──────────────────────────────────────────────────────

        loadDependencies: function (cb) {
            var self = this;
            var done = 0;
            var total = 4;
            var check = function () {
                done++;
                if (done >= total && typeof cb === 'function') { cb(); }
            };

            this.post('/admin/categories/list', { query: {}, page: 1, results: 200, orderBy: 'name|ASC' }, function (res) {
                self.categories = (res && Array.isArray(res.dataset)) ? res.dataset : [];
                self.fillCategorySelects();
                check();
            }, function () { check(); });

            this.post('/admin/items/rarities/list', {}, function (res) {
                self.rarities = (res && Array.isArray(res.dataset)) ? res.dataset : [];
                self.fillRaritySelects();
                check();
            }, function () { check(); });

            this.post('/admin/items/types', {}, function (res) {
                self.types = (res && Array.isArray(res.dataset)) ? res.dataset : [];
                self.fillTypeSelects();
                check();
            }, function () { check(); });

            var moduleEndpoints = window.LogeonModuleEndpoints || {};
            var equipmentSlotsEndpoint = String(moduleEndpoints.equipmentSlotsList || '').trim();
            if (!equipmentSlotsEndpoint) {
                equipmentSlotsEndpoint = '/admin/equipment-slots/list';
            }
            this.post(equipmentSlotsEndpoint, {}, function (res) {
                self.equipmentSlots = (res && Array.isArray(res.dataset)) ? res.dataset : [];
                self.fillEquipSlotSelects();
                check();
            }, function () {
                self.equipmentSlots = [];
                self.fillEquipSlotSelects();
                check();
            });
        },

        fillCategorySelects: function () {
            var selects = [
                this.filtersForm ? this.filtersForm.querySelector('[name="category_id"]') : null,
                this.modalForm   ? this.modalForm.querySelector('[name="category_id"]')   : null
            ];
            for (var s = 0; s < selects.length; s++) {
                var sel = selects[s];
                if (!sel) { continue; }
                var cur = sel.value;
                while (sel.options.length > 1) { sel.remove(1); }
                for (var i = 0; i < this.categories.length; i++) {
                    var c = this.categories[i];
                    var opt = document.createElement('option');
                    opt.value = String(c.id);
                    opt.textContent = c.name || String(c.id);
                    sel.appendChild(opt);
                }
                if (cur) { sel.value = cur; }
            }
        },

        fillRaritySelects: function () {
            var selects = [
                this.filtersForm ? this.filtersForm.querySelector('[name="rarity_id"]') : null,
                this.modalForm   ? this.modalForm.querySelector('[name="rarity_id"]')   : null
            ];
            for (var s = 0; s < selects.length; s++) {
                var sel = selects[s];
                if (!sel) { continue; }
                var cur = sel.value;
                while (sel.options.length > 1) { sel.remove(1); }
                for (var i = 0; i < this.rarities.length; i++) {
                    var r = this.rarities[i];
                    var opt = document.createElement('option');
                    opt.value = String(r.id);
                    opt.textContent = r.name || r.code || String(r.id);
                    sel.appendChild(opt);
                }
                if (cur) { sel.value = cur; }
            }
        },

        fillTypeSelects: function () {
            var allValues = this.TYPE_DEFAULTS.slice();
            for (var i = 0; i < this.types.length; i++) {
                if (allValues.indexOf(this.types[i]) === -1) {
                    allValues.push(this.types[i]);
                }
            }

            var sel = this.modalForm ? this.modalForm.querySelector('[name="type"]') : null;
            if (!sel) { return; }
            var cur = sel.value;
            while (sel.options.length > 1) { sel.remove(1); }
            for (var j = 0; j < allValues.length; j++) {
                var opt = document.createElement('option');
                opt.value = allValues[j];
                opt.textContent = this.TYPE_LABELS[allValues[j]] || allValues[j];
                sel.appendChild(opt);
            }
            if (cur) { sel.value = cur; }
        },

        fillEquipSlotSelects: function () {
            var sel = this.modalForm ? this.modalForm.querySelector('[name="equip_slot"]') : null;
            if (!sel) { return; }
            var cur = sel.value;
            while (sel.options.length > 1) { sel.remove(1); }
            for (var i = 0; i < this.equipmentSlots.length; i++) {
                var slot = this.equipmentSlots[i];
                var opt = document.createElement('option');
                opt.value = slot.key || '';
                opt.textContent = slot.name || slot.key || String(i + 1);
                sel.appendChild(opt);
            }
            if (cur) { sel.value = cur; }
        },

        // ── Modal ─────────────────────────────────────────────────────────────

        openCreate: function () {
            this.editingRow = null;
            if (this.modalForm) { this.modalForm.reset(); }
            this.setField('id', '');
            this.setField('value', '0');
            this.setField('cooldown', '0');
            this.setField('is_stackable', '1');
            this.setField('max_stack', '50');
            this.setField('usable', '0');
            this.setField('consumable', '0');
            this.setField('tradable', '1');
            this.setField('droppable', '1');
            this.setField('destroyable', '1');
            this.setField('is_equippable', '0');
            this.fillCategorySelects();
            this.fillRaritySelects();
            this.fillTypeSelects();
            this.fillEquipSlotSelects();
            this.syncEquipSlot();
            this.syncMaxStack();
            this.toggleDelete(false);
            this.modal.show();
        },

        openEdit: function (id) {
            var row = this.rowsById[id] || null;
            if (!row) { return; }
            this.editingRow = row;
            if (this.modalForm) { this.modalForm.reset(); }
            this.fillCategorySelects();
            this.fillRaritySelects();
            this.fillTypeSelects();
            this.fillEquipSlotSelects();
            this.setField('id', String(row.id));
            this.setField('name', row.name || '');
            this.setField('category_id', String(row.category_id || ''));
            this.setField('rarity_id', String(row.rarity_id || ''));
            this.setField('description', row.description || '');
            this.setField('icon', row.icon || row.image || '');
            this.setField('value', String(row.value || row.price || '0'));
            this.setField('weight', row.weight || '');
            this.setField('cooldown', String(row.cooldown || '0'));
            this.setField('type', row.type || '');
            this.setField('is_stackable', String(parseInt(row.is_stackable, 10) || 0));
            this.setField('max_stack', String(row.max_stack || '50'));
            this.setField('usable', String(parseInt(row.usable, 10) || 0));
            this.setField('consumable', String(parseInt(row.consumable, 10) || 0));
            this.setField('tradable', String(parseInt(row.tradable, 10) === 0 ? 0 : 1));
            this.setField('droppable', String(parseInt(row.droppable, 10) === 0 ? 0 : 1));
            this.setField('destroyable', String(parseInt(row.destroyable, 10) === 0 ? 0 : 1));
            this.setField('is_equippable', String(parseInt(row.is_equippable, 10) || 0));
            this.setField('equip_slot', row.equip_slot || '');
            this.syncEquipSlot();
            this.syncMaxStack();
            this.toggleDelete(true);
            this.modal.show();
        },

        toggleDelete: function (show) {
            if (!this.modalNode) { return; }
            var btn = this.modalNode.querySelector('[data-action="admin-items-delete"]');
            if (btn) { btn.classList.toggle('d-none', !show); }
        },

        setField: function (name, value) {
            if (!this.modalForm) { return; }
            var el = this.modalForm.querySelector('[name="' + name + '"]');
            if (el) { el.value = value; }
        },

        getField: function (name) {
            if (!this.modalForm) { return ''; }
            var el = this.modalForm.querySelector('[name="' + name + '"]');
            return el ? el.value : '';
        },

        collectPayload: function () {
            return {
                id:          parseInt(this.getField('id'), 10) || 0,
                name:        this.getField('name').trim(),
                category_id: this.getField('category_id') || null,
                rarity_id:   this.getField('rarity_id') || null,
                description: this.getField('description').trim() || null,
                icon:        this.getField('icon').trim() || null,
                value:       parseInt(this.getField('value'), 10) || 0,
                weight:      this.getField('weight').trim() || null,
                cooldown:    parseInt(this.getField('cooldown'), 10) || 0,
                type:        this.getField('type').trim() || null,
                is_stackable: this.getField('is_stackable'),
                max_stack:   parseInt(this.getField('max_stack'), 10) || 50,
                usable:      this.getField('usable'),
                consumable:  this.getField('consumable'),
                tradable:    this.getField('tradable'),
                droppable:   this.getField('droppable'),
                destroyable: this.getField('destroyable'),
                is_equippable: this.getField('is_equippable'),
                equip_slot:  this.getField('equip_slot').trim() || null
            };
        },

        save: function () {
            var payload = this.collectPayload();
            if (!payload.name) {
                if (typeof Toast !== 'undefined') { Toast.show({ body: 'Il nome è obbligatorio.', type: 'error' }); }
                return;
            }

            var isNew = !payload.id;
            var url   = isNew ? '/admin/items/create' : '/admin/items/update';
            var self  = this;

            this.post(url, payload, function () {
                self.modal.hide();
                if (typeof Toast !== 'undefined') {
                    Toast.show({ body: isNew ? 'Oggetto creato.' : 'Oggetto aggiornato.', type: 'success' });
                }
                self.loadGrid();
            });
        },

        remove: function () {
            var payload = this.collectPayload();
            if (!payload.id) { return; }
            if (!confirm('Eliminare questo oggetto? L\'operazione non può essere annullata.')) { return; }

            var self = this;
            this.post('/admin/items/delete', { id: payload.id }, function () {
                self.modal.hide();
                if (typeof Toast !== 'undefined') { Toast.show({ body: 'Oggetto eliminato.', type: 'success' }); }
                self.loadGrid();
            });
        },

        // ── HTTP helper ────────────────────────────────────────────────────────

        post: function (url, payload, onSuccess, onError) {
            if (typeof Request !== 'function' || !Request.http || typeof Request.http.post !== 'function') {
                if (typeof Toast !== 'undefined') { Toast.show({ body: 'Servizio non disponibile.', type: 'error' }); }
                return this;
            }
            Request.http.post(url, payload || {}).then(function (response) {
                if (typeof onSuccess === 'function') { onSuccess(response || null); }
            }).catch(function (error) {
                if (typeof onError === 'function') {
                    onError(error);
                } else if (typeof Toast !== 'undefined') {
                    var msg = (error && error.message) ? error.message : 'Errore di rete.';
                    Toast.show({ body: msg, type: 'error' });
                }
            });
        },

        escapeHtml: function (str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        },

        escapeAttr: function (str) {
            return String(str).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }
    };

    window.AdminItems = AdminItems;
})();
