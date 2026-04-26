const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

var AdminShopInventory = {
    initialized: false,
    root: null,
    filtersForm: null,
    grid: null,
    modalNode: null,
    modal: null,
    modalForm: null,
    rows: [],
    rowsById: {},
    shops: [],
    shopsById: {},
    items: [],
    itemsById: {},
    currencies: [],
    editingRow: null,

    init: function () {
        if (this.initialized) {
            return this;
        }

        this.root = document.querySelector('#admin-page [data-admin-page="inventory-shop"]');
        if (!this.root) {
            return this;
        }

        this.filtersForm = this.root.querySelector('#admin-shop-inventory-filters');
        this.modalNode   = this.root.querySelector('#admin-shop-inventory-modal');
        this.modalForm   = this.root.querySelector('#admin-shop-inventory-form');

        if (!this.filtersForm || !this.modalNode || !this.modalForm || !document.getElementById('grid-admin-shop-inventory')) {
            return this;
        }

        this.modal = new bootstrap.Modal(this.modalNode);
        this.bind();
        this.initGrid();

        var self = this;
        this.loadShops(function () {
            self.loadItems(function () {
                self.loadCurrencies(function () {
                    self.loadGrid();
                });
            });
        });

        this.initialized = true;
        return this;
    },

    bind: function () {
        var self = this;

        this.filtersForm.addEventListener('submit', function (event) {
            event.preventDefault();
            self.loadGrid();
        });

        // Filter shop autocomplete
        var filterShopInput = this.filtersForm.querySelector('[name="shop_label"]');
        if (filterShopInput) {
            filterShopInput.addEventListener('input', function () {
                self.syncHidden(self.filtersForm, 'shop_id');
                self.renderSuggestions(
                    self.filtersForm,
                    '[data-role="admin-shop-inventory-filter-shop-suggestions"]',
                    filterShopInput.value,
                    self.shops,
                    'admin-shop-inventory-pick-shop',
                    'filter'
                );
            });
        }

        // Modal shop autocomplete
        var modalShopInput = this.modalForm.querySelector('[name="shop_label"]');
        if (modalShopInput) {
            modalShopInput.addEventListener('input', function () {
                self.syncHidden(self.modalForm, 'shop_id');
                self.renderSuggestions(
                    self.modalForm,
                    '[data-role="admin-shop-inventory-shop-suggestions"]',
                    modalShopInput.value,
                    self.shops,
                    'admin-shop-inventory-pick-shop',
                    'form'
                );
            });
        }

        // Modal item autocomplete
        var modalItemInput = this.modalForm.querySelector('[name="item_label"]');
        if (modalItemInput) {
            modalItemInput.addEventListener('input', function () {
                self.syncHidden(self.modalForm, 'item_id');
                self.renderSuggestions(
                    self.modalForm,
                    '[data-role="admin-shop-inventory-item-suggestions"]',
                    modalItemInput.value,
                    self.items,
                    'admin-shop-inventory-pick-item',
                    'form'
                );
            });
        }

        // Toggle promo discount field visibility
        var isPromoSelect = this.modalForm.querySelector('[name="is_promo"]');
        if (isPromoSelect) {
            isPromoSelect.addEventListener('change', function () {
                self.togglePromoDiscount(isPromoSelect.value === '1');
            });
        }

        // Global click: suggestion picks + close suggestions on outside click
        document.addEventListener('click', function (event) {
            if (!self.root || !self.root.contains(event.target)) {
                return;
            }

            var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
            if (!trigger) {
                if (!event.target.closest('[name="shop_label"], [name="item_label"]')) {
                    self.hideAllSuggestions();
                }
                return;
            }

            var action = String(trigger.getAttribute('data-action') || '').trim();

            if (action === 'admin-shop-inventory-pick-shop') {
                event.preventDefault();
                var scope = String(trigger.getAttribute('data-scope') || 'form');
                self.pickShop(scope === 'filter' ? self.filtersForm : self.modalForm, trigger);
                return;
            }

            if (action === 'admin-shop-inventory-pick-item') {
                event.preventDefault();
                self.pickItem(self.modalForm, trigger);
                return;
            }
        });

        // Root action delegation
        this.root.addEventListener('click', function (event) {
            var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
            if (!trigger) {
                return;
            }

            var action = String(trigger.getAttribute('data-action') || '').trim();

            if (action === 'admin-shop-inventory-reload') {
                event.preventDefault();
                self.loadGrid();
                return;
            }
            if (action === 'admin-shop-inventory-create') {
                event.preventDefault();
                self.openCreate();
                return;
            }
            if (action === 'admin-shop-inventory-edit') {
                event.preventDefault();
                self.openEdit(trigger);
                return;
            }
            if (action === 'admin-shop-inventory-save') {
                event.preventDefault();
                self.save();
                return;
            }
            if (action === 'admin-shop-inventory-delete') {
                event.preventDefault();
                self.remove();
                return;
            }
        });
    },

    initGrid: function () {
        var self = this;

        this.grid = new Datagrid('grid-admin-shop-inventory', {
            name: 'AdminShopInventory',
            autoindex: 'id',
            orderable: true,
            thead: true,
            handler: { url: '/admin/inventory/list', action: 'list' },
            nav: { display: 'bottom', urlupdate: 0, results: 20, page: 1 },
            onGetDataSuccess: function (response) {
                self.setRows(response && Array.isArray(response.dataset) ? response.dataset : []);
            },
            onGetDataError: function () {
                self.setRows([]);
            },
            columns: [
                { label: 'ID', field: 'id', sortable: true, style: { width: '60px' } },
                {
                    label: 'Negozio',
                    field: 'shop_name',
                    sortable: true,
                    style: { textAlign: 'left' },
                    format: function (row) {
                        return self.escapeHtml(row.shop_name || '-');
                    }
                },
                {
                    label: 'Oggetto',
                    field: 'item_name',
                    sortable: true,
                    style: { textAlign: 'left' },
                    format: function (row) {
                        return self.escapeHtml(row.item_name || '-');
                    }
                },
                {
                    label: 'Prezzo',
                    field: 'price',
                    sortable: true,
                    format: function (row) {
                        var symbol = self.escapeHtml(row.currency_symbol || row.currency_code || '');
                        return self.escapeHtml(String(row.price || 0)) + (symbol ? ' ' + symbol : '');
                    }
                },
                {
                    label: 'Stock',
                    field: 'stock',
                    sortable: false,
                    format: function (row) {
                        return (row.stock === null || row.stock === undefined || row.stock === '')
                            ? '<span class="text-muted">∞</span>'
                            : self.escapeHtml(String(row.stock));
                    }
                },
                {
                    label: 'Promo',
                    sortable: false,
                    format: function (row) {
                        if (parseInt(row.is_promo || 0, 10) !== 1) {
                            return '<span class="badge text-bg-secondary">No</span>';
                        }
                        var discount = parseInt(row.promo_discount || 0, 10);
                        return '<span class="badge text-bg-warning">-' + discount + '%</span>';
                    }
                },
                {
                    label: 'Stato',
                    sortable: false,
                    format: function (row) {
                        return parseInt(row.is_active || 0, 10) === 1
                            ? '<span class="badge text-bg-success">Attivo</span>'
                            : '<span class="badge text-bg-secondary">Inattivo</span>';
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
                        return '<button type="button" class="btn btn-sm btn-outline-primary"'
                            + ' data-action="admin-shop-inventory-edit"'
                            + ' data-id="' + id + '">Modifica</button>';
                    }
                }
            ]
        });
    },

    loadShops: function (onDone) {
        var self = this;
        this.post('/admin/shops/list', { results: 500, page: 1, orderBy: 'name|ASC' }, function (response) {
            var rows = response && Array.isArray(response.dataset) ? response.dataset : [];
            self.shops = [];
            self.shopsById = {};
            for (var i = 0; i < rows.length; i++) {
                var r = rows[i] || {};
                var id = parseInt(r.id || 0, 10) || 0;
                if (id <= 0) { continue; }
                var item = { id: id, label: String(r.name || ('Negozio #' + id)) };
                self.shops.push(item);
                self.shopsById[id] = item;
            }
            if (typeof onDone === 'function') { onDone(); }
        }, function () {
            self.shops = [];
            if (typeof onDone === 'function') { onDone(); }
        });
    },

    loadItems: function (onDone) {
        var self = this;
        this.post('/admin/items/list', { results: 1000, page: 1, orderBy: 'name|ASC' }, function (response) {
            var rows = response && Array.isArray(response.dataset) ? response.dataset : [];
            self.items = [];
            self.itemsById = {};
            for (var i = 0; i < rows.length; i++) {
                var r = rows[i] || {};
                var id = parseInt(r.id || 0, 10) || 0;
                if (id <= 0) { continue; }
                var item = { id: id, label: String(r.name || ('Oggetto #' + id)) };
                self.items.push(item);
                self.itemsById[id] = item;
            }
            if (typeof onDone === 'function') { onDone(); }
        }, function () {
            self.items = [];
            if (typeof onDone === 'function') { onDone(); }
        });
    },

    loadCurrencies: function (onDone) {
        var self = this;
        this.post(this.currencyListEndpoint(), { results: 100, page: 1, orderBy: 'name|ASC' }, function (response) {
            var rows = response && Array.isArray(response.dataset) ? response.dataset : [];
            self.currencies = [];
            for (var i = 0; i < rows.length; i++) {
                var r = rows[i] || {};
                var id = parseInt(r.id || 0, 10) || 0;
                if (id <= 0) { continue; }
                self.currencies.push({
                    id: id,
                    name: String(r.name || ''),
                    symbol: String(r.symbol || ''),
                    code: String(r.code || '')
                });
            }
            self.populateCurrencySelect();
            if (typeof onDone === 'function') { onDone(); }
        }, function () {
            self.currencies = [];
            if (typeof onDone === 'function') { onDone(); }
        });
    },

    populateCurrencySelect: function () {
        var select = this.modalForm ? this.modalForm.querySelector('[name="currency_id"]') : null;
        if (!select) { return; }
        var current = select.value;
        select.innerHTML = '<option value="">Seleziona valuta...</option>';
        for (var i = 0; i < this.currencies.length; i++) {
            var c = this.currencies[i];
            var label = c.name + (c.symbol ? ' (' + c.symbol + ')' : '');
            var opt = document.createElement('option');
            opt.value = String(c.id);
            opt.textContent = label;
            select.appendChild(opt);
        }
        if (current) { select.value = current; }
    },

    currencyListEndpoint: function () {
        var endpoints = globalWindow.LogeonModuleEndpoints || {};
        return String(endpoints.currenciesList || '/admin/core-currencies/list');
    },

    loadGrid: function () {
        if (!this.grid || typeof this.grid.loadData !== 'function') {
            return this;
        }
        this.grid.loadData(this.buildFiltersPayload(), 20, 1, 'si.id|ASC');
        return this;
    },

    buildFiltersPayload: function () {
        var query = {};
        var shopId   = parseInt(this.getFilterValue('shop_id') || '0', 10) || 0;
        var itemName = this.getFilterValue('item_name');
        var isActive = this.getFilterValue('is_active');
        var isPromo  = this.getFilterValue('is_promo');

        if (shopId > 0)      { query.shop_id   = shopId; }
        if (itemName !== '')  { query.item_name = itemName; }
        if (isActive !== '')  { query.is_active = parseInt(isActive, 10); }
        if (isPromo !== '')   { query.is_promo  = parseInt(isPromo, 10); }

        return query;
    },

    getFilterValue: function (name) {
        if (!this.filtersForm || !this.filtersForm.elements || !this.filtersForm.elements[name]) {
            return '';
        }
        return String(this.filtersForm.elements[name].value || '').trim();
    },

    // ── Autocomplete helpers ──────────────────────────────────────────────

    syncHidden: function (form, hiddenName) {
        var hidden = form ? form.querySelector('[name="' + hiddenName + '"]') : null;
        if (hidden) { hidden.value = ''; }
    },

    renderSuggestions: function (form, boxSelector, searchTerm, dataset, pickAction, scope) {
        if (!form) { return; }
        var box = form.querySelector(boxSelector);
        if (!box) { return; }

        var term = String(searchTerm || '').trim().toLowerCase();
        if (term.length < 1) {
            box.classList.add('d-none');
            box.innerHTML = '';
            return;
        }

        var matches = [];
        for (var i = 0; i < dataset.length; i++) {
            if (String(dataset[i].label || '').toLowerCase().indexOf(term) === -1) { continue; }
            matches.push(dataset[i]);
            if (matches.length >= 8) { break; }
        }

        if (!matches.length) {
            box.classList.add('d-none');
            box.innerHTML = '';
            return;
        }

        var html = '';
        for (var m = 0; m < matches.length; m++) {
            var match = matches[m];
            html += '<button type="button" class="list-group-item list-group-item-action"'
                + ' data-action="' + pickAction + '"'
                + ' data-scope="' + scope + '"'
                + ' data-id="' + match.id + '"'
                + ' data-label="' + this.escapeAttr(match.label) + '">'
                + this.escapeHtml(match.label)
                + '</button>';
        }
        box.innerHTML = html;
        box.classList.remove('d-none');
    },

    pickShop: function (form, trigger) {
        if (!form || !trigger) { return this; }
        var id    = parseInt(trigger.getAttribute('data-id') || '0', 10) || 0;
        var label = String(trigger.getAttribute('data-label') || '').trim();
        var labelInput  = form.querySelector('[name="shop_label"]');
        var hiddenInput = form.querySelector('[name="shop_id"]');
        if (labelInput)  { labelInput.value  = label; }
        if (hiddenInput) { hiddenInput.value = id > 0 ? String(id) : ''; }
        this.hideAllSuggestions();
        return this;
    },

    pickItem: function (form, trigger) {
        if (!form || !trigger) { return this; }
        var id    = parseInt(trigger.getAttribute('data-id') || '0', 10) || 0;
        var label = String(trigger.getAttribute('data-label') || '').trim();
        var labelInput  = form.querySelector('[name="item_label"]');
        var hiddenInput = form.querySelector('[name="item_id"]');
        if (labelInput)  { labelInput.value  = label; }
        if (hiddenInput) { hiddenInput.value = id > 0 ? String(id) : ''; }
        this.hideAllSuggestions();
        return this;
    },

    hideAllSuggestions: function () {
        if (!this.root) { return this; }
        var boxes = this.root.querySelectorAll(
            '[data-role="admin-shop-inventory-filter-shop-suggestions"],'
            + '[data-role="admin-shop-inventory-shop-suggestions"],'
            + '[data-role="admin-shop-inventory-item-suggestions"]'
        );
        for (var i = 0; i < boxes.length; i++) {
            boxes[i].classList.add('d-none');
            boxes[i].innerHTML = '';
        }
        return this;
    },

    togglePromoDiscount: function (visible) {
        var group = this.modalForm ? this.modalForm.querySelector('[data-role="admin-shop-inventory-promo-discount-group"]') : null;
        if (group) {
            group.style.display = visible ? '' : 'none';
        }
    },

    // ── Modal CRUD ────────────────────────────────────────────────────────

    openCreate: function () {
        this.editingRow = null;
        this.fillModalForm({});
        this.toggleDelete(false);
        this.modal.show();
        return this;
    },

    openEdit: function (trigger) {
        var row = this.rowFromTrigger(trigger);
        if (!row) { return this; }
        this.editingRow = row;
        this.fillModalForm(row);
        this.toggleDelete(true);
        this.modal.show();
        return this;
    },

    fillModalForm: function (row) {
        var data      = row || {};
        var shopId    = parseInt(data.shop_id || 0, 10) || 0;
        var itemId    = parseInt(data.item_id || 0, 10) || 0;
        var isPromo   = parseInt(data.is_promo || 0, 10) === 1;

        this.setField('id',                  data.id || '');
        this.setField('shop_label',          shopId > 0 ? (this.shopsById[shopId] ? this.shopsById[shopId].label : ('Negozio #' + shopId)) : '');
        this.setField('shop_id',             shopId > 0 ? String(shopId) : '');
        this.setField('item_label',          itemId > 0 ? (this.itemsById[itemId] ? this.itemsById[itemId].label : ('Oggetto #' + itemId)) : '');
        this.setField('item_id',             itemId > 0 ? String(itemId) : '');
        this.setField('currency_id',         data.currency_id ? String(data.currency_id) : '');
        this.setField('price',               data.price !== undefined ? String(data.price) : '0');
        this.setField('stock',               data.stock !== null && data.stock !== undefined ? String(data.stock) : '');
        this.setField('per_character_limit', data.per_character_limit !== null && data.per_character_limit !== undefined ? String(data.per_character_limit) : '');
        this.setField('per_day_limit',       data.per_day_limit !== null && data.per_day_limit !== undefined ? String(data.per_day_limit) : '');
        this.setField('is_promo',            isPromo ? '1' : '0');
        this.setField('promo_discount',      data.promo_discount !== undefined ? String(data.promo_discount) : '0');
        this.setField('is_active',           (parseInt(data.is_active !== undefined ? data.is_active : 1, 10) === 1) ? '1' : '0');

        this.togglePromoDiscount(isPromo);
        this.hideAllSuggestions();
        return this;
    },

    setField: function (name, value) {
        if (!this.modalForm) { return this; }
        var node = this.modalForm.querySelector('[name="' + name + '"]');
        if (!node) { return this; }
        node.value = value == null ? '' : String(value);
        return this;
    },

    getField: function (name) {
        if (!this.modalForm) { return ''; }
        var node = this.modalForm.querySelector('[name="' + name + '"]');
        return node ? String(node.value || '').trim() : '';
    },

    collectPayload: function () {
        var stockRaw    = this.getField('stock');
        var pcLimitRaw  = this.getField('per_character_limit');
        var pdLimitRaw  = this.getField('per_day_limit');
        var isPromo     = this.getField('is_promo') === '1' ? 1 : 0;

        return {
            id:                  parseInt(this.getField('id') || '0', 10) || 0,
            shop_id:             parseInt(this.getField('shop_id') || '0', 10) || 0,
            item_id:             parseInt(this.getField('item_id') || '0', 10) || 0,
            currency_id:         parseInt(this.getField('currency_id') || '0', 10) || 0,
            price:               parseInt(this.getField('price') || '0', 10) || 0,
            stock:               stockRaw !== '' ? (parseInt(stockRaw, 10) || 0) : null,
            per_character_limit: pcLimitRaw !== '' ? (parseInt(pcLimitRaw, 10) || null) : null,
            per_day_limit:       pdLimitRaw !== '' ? (parseInt(pdLimitRaw, 10) || null) : null,
            is_promo:            isPromo,
            promo_discount:      isPromo ? (parseInt(this.getField('promo_discount') || '0', 10) || 0) : 0,
            is_active:           this.getField('is_active') === '1' ? 1 : 0
        };
    },

    save: function () {
        var payload = this.collectPayload();

        if (payload.shop_id <= 0) {
            Toast.show({ body: 'Seleziona un negozio.', type: 'warning' });
            return this;
        }
        if (payload.item_id <= 0) {
            Toast.show({ body: 'Seleziona un oggetto.', type: 'warning' });
            return this;
        }
        if (payload.currency_id <= 0) {
            Toast.show({ body: 'Seleziona una valuta.', type: 'warning' });
            return this;
        }

        var self = this;
        var url  = payload.id > 0 ? '/admin/inventory/update' : '/admin/inventory/create';
        this.post(url, payload, function () {
            Toast.show({ body: 'Voce inventario salvata.', type: 'success' });
            self.modal.hide();
            self.reloadGridKeepingPosition();
        });
        return this;
    },

    remove: function () {
        var id = parseInt(this.getField('id') || '0', 10) || 0;
        if (id <= 0) { return this; }

        var self = this;
        Dialog('warning', {
            title: 'Conferma eliminazione',
            body: '<p>Vuoi eliminare questa voce dall\'inventario?</p>',
            buttons: [
                { text: 'Annulla', class: 'btn btn-secondary', dismiss: true },
                {
                    text: 'Elimina',
                    class: 'btn btn-danger',
                    click: function () {
                        self.post('/admin/inventory/delete', { id: id }, function () {
                            Toast.show({ body: 'Voce inventario eliminata.', type: 'success' });
                            self.modal.hide();
                            self.reloadGridKeepingPosition();
                        });
                    }
                }
            ]
        }).show();

        return this;
    },

    // ── Utility ───────────────────────────────────────────────────────────

    toggleDelete: function (visible) {
        var button = this.root ? this.root.querySelector('[data-action="admin-shop-inventory-delete"]') : null;
        if (button) { button.classList.toggle('d-none', visible !== true); }
        return this;
    },

    setRows: function (rows) {
        this.rows = Array.isArray(rows) ? rows.slice() : [];
        this.rowsById = {};
        for (var i = 0; i < this.rows.length; i++) {
            var id = parseInt(this.rows[i].id || 0, 10) || 0;
            if (id > 0) { this.rowsById[id] = this.rows[i]; }
        }
        return this;
    },

    rowFromTrigger: function (trigger) {
        var id = parseInt(String(trigger.getAttribute('data-id') || '0'), 10) || 0;
        if (id <= 0) { return null; }
        return this.rowsById[id] || null;
    },

    reloadGridKeepingPosition: function () {
        if (this.grid && typeof this.grid.reloadData === 'function') {
            this.grid.reloadData();
            return this;
        }
        return this.loadGrid();
    },

    post: function (url, payload, onSuccess, onError) {
        if (typeof Request !== 'function' || !Request.http || typeof Request.http.post !== 'function') {
            Toast.show({ body: 'Servizio non disponibile.', type: 'error' });
            return this;
        }

        Request.http.post(url, payload || {}).then(function (response) {
            if (typeof onSuccess === 'function') { onSuccess(response || null); }
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
    },

    escapeAttr: function (value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }
};

if (typeof window !== 'undefined') {
    globalWindow.AdminShopInventory = AdminShopInventory;
}
export { AdminShopInventory as AdminShopInventory };
export default AdminShopInventory;

