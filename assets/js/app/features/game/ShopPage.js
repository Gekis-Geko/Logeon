(function (window) {
    'use strict';

    function resolveModule(name) {
        if (!window.RuntimeBootstrap || typeof window.RuntimeBootstrap.resolveAppModule !== 'function') {
            return null;
        }
        try {
            return window.RuntimeBootstrap.resolveAppModule(name);
        } catch (error) {
            return null;
        }
    }


    function getShopErrorInfo(error, fallback) {
        var fb = fallback || 'Operazione non riuscita.';
        if (window.GameFeatureError && typeof window.GameFeatureError.info === 'function') {
            return window.GameFeatureError.info(error, fb);
        }
        if (window.GameFeatureError && typeof window.GameFeatureError.normalize === 'function') {
            return {
                message: window.GameFeatureError.normalize(error, fb),
                errorCode: '',
                raw: error
            };
        }
        if (typeof error === 'string' && error.trim() !== '') {
            return { message: error.trim(), errorCode: '', raw: error };
        }
        if (error && typeof error.message === 'string' && error.message.trim() !== '') {
            return { message: error.message.trim(), errorCode: '', raw: error };
        }
        if (error && typeof error.error === 'string' && error.error.trim() !== '') {
            return { message: error.error.trim(), errorCode: '', raw: error };
        }
        return { message: fb, errorCode: '', raw: error };
    }

    function showShopError(error, fallback) {
        if (window.GameFeatureError && typeof window.GameFeatureError.toastMapped === 'function') {
            return window.GameFeatureError.toastMapped(error, fallback, {
                map: {
                    character_invalid: 'Personaggio non valido.',
                    insufficient_funds: 'Fondi insufficienti per completare l\'operazione.',
                    shop_limit_reached: 'Hai raggiunto il limite massimo acquistabile per questo oggetto.',
                    shop_daily_limit_reached: 'Hai raggiunto il limite giornaliero per questo oggetto.',
                    stock_insufficient: 'Stock insufficiente.',
                    quantity_invalid: 'Quantita non valida.',
                    quantity_unavailable: 'Quantita non disponibile.',
                    shop_item_invalid: 'Oggetto non valido.',
                    shop_item_unavailable: 'Oggetto non disponibile.',
                    shop_unavailable: 'Negozio non disponibile.',
                    currency_unavailable: 'Valuta non disponibile.',
                    item_not_found: 'Oggetto non trovato.',
                    item_not_sellable: 'Oggetto non vendibile.',
                    item_equipped: 'Oggetto equipaggiato: rimuovilo prima di venderlo.',
                    sell_price_invalid: 'Prezzo di vendita non valido.',
                    inventory_capacity_reached: 'Inventario pieno: libera spazio prima di acquistare.',
                    inventory_stack_limit_reached: 'Hai raggiunto la quantita massima trasportabile per questo oggetto.'
                },
                validationCodes: [
                    'character_invalid',
                    'insufficient_funds',
                    'shop_limit_reached',
                    'shop_daily_limit_reached',
                    'stock_insufficient',
                    'quantity_invalid',
                    'quantity_unavailable',
                    'shop_item_invalid',
                    'shop_item_unavailable',
                    'shop_unavailable',
                    'currency_unavailable',
                    'item_not_found',
                    'item_not_sellable',
                    'item_equipped',
                    'sell_price_invalid',
                    'inventory_capacity_reached',
                    'inventory_stack_limit_reached'
                ],
                validationType: 'warning',
                defaultType: 'error'
            });
        }

        var errorInfo = getShopErrorInfo(error, fallback);
        Toast.show({
            body: errorInfo.message || fallback || 'Operazione non riuscita.',
            type: 'error'
        });
        return errorInfo;
    }

    function callShopModule(method, payload, onSuccess, onError) {
        if (typeof resolveModule !== 'function') {
            if (typeof onError === 'function') {
                onError(new Error('Shop module resolver not available: ' + method));
            }
            return false;
        }

        var mod = resolveModule('game.shop');
        if (!mod || typeof mod[method] !== 'function') {
            if (typeof onError === 'function') {
                onError(new Error('Shop module method not available: ' + method));
            }
            return false;
        }

        mod[method](payload || {}).then(function (response) {
            if (typeof onSuccess === 'function') {
                onSuccess(response);
            }
        }).catch(function (error) {
            if (typeof onError === 'function') {
                onError(error);
            }
        });

        return true;
    }

    function GameShopPage(extension) {
            let page = {
                items: [],
                categories: [],
                currencies: [],
                balances: {},
                shop: null,
                shop_id: null,
                location_id: null,
                active_category: null,
                sort_key: 'name',
                filter_sellable: false,
                search_term: '',
                search_timer: null,
                sortControl: null,
                filterControl: null,

                init: function () {
                    let container = $('#shop-page');
                    if (!container.length) {
                        return this;
                    }

                    this.shop_id = container.attr('data-shop-id') || null;
                    this.location_id = container.attr('data-location-id') || null;

                    this.bindSearch();
                    this.bindSort();
                    this.bindFilters();
                    this.loadItems();
                    return this;
                },
                loadItems: function () {
                    var self = this;
                    var payload = {
                        shop_id: self.shop_id,
                        location_id: self.location_id
                    };
                    var onSuccess = function (response) {
                        self.shop = response.shop || null;
                        self.items = response.items || [];
                        self.categories = response.categories || [];
                        self.currencies = response.currencies || [];
                        self.balances = response.balances || {};
                        self.build();
                    };
                    callShopModule('items', payload, onSuccess, function (error) {
                        showShopError(error, 'Errore durante caricamento shop.');
                    });
                },
                build: function () {
                    this.buildBalances();
                    this.buildCategories();
                    this.buildItems();
                },
                bindSearch: function () {
                    var self = this;
                    let input = $('#shop-search');
                    if (input.length) {
                        input.off('input').on('input', function () {
                            self.search_term = $(this).val().toLowerCase().trim();
                            if (self.search_timer) {
                                window.clearTimeout(self.search_timer);
                            }
                            self.search_timer = window.setTimeout(function () {
                                self.buildItems();
                            }, 300);
                        });
                    }
                    let clear = $('[data-action="shop-search-clear"]');
                    if (clear.length) {
                        clear.off('click').on('click', function () {
                            if (input.length) {
                                input.val('');
                            }
                            self.search_term = '';
                            self.buildItems();
                        });
                    }
                },
                bindSort: function () {
                    var self = this;
                    let input = $('#shop-sort');
                    if (!input.length) {
                        return;
                    }
                    if (this.sortControl && typeof this.sortControl.destroy === 'function') {
                        this.sortControl.destroy();
                    }
                    if (typeof RadioGroup === 'function') {
                        this.sortControl = RadioGroup('#shop-sort', {
                            btnClass: 'btn-sm',
                            options: [
                                { label: 'Nome', value: 'name' },
                                { label: 'Prezzo', value: 'price' },
                                { label: 'Promo', value: 'promo' }
                            ]
                        });
                    }
                    input.off('change.shop').on('change.shop', function () {
                        let val = $(this).val();
                        self.sort_key = val || 'name';
                        self.buildItems();
                    });
                    if (!input.val()) {
                        input.val('name').change();
                    }
                },
                bindFilters: function () {
                    var self = this;
                    let input = $('#shop-filter');
                    if (!input.length) {
                        return;
                    }
                    if (this.filterControl && typeof this.filterControl.destroy === 'function') {
                        this.filterControl.destroy();
                    }
                    if (typeof RadioGroup === 'function') {
                        this.filterControl = RadioGroup('#shop-filter', {
                            btnClass: 'btn-sm',
                            options: [
                                { label: 'Tutti', value: 'all' },
                                { label: 'Vendibili', value: 'sellable' }
                            ]
                        });
                    }
                    input.off('change.shop').on('change.shop', function () {
                        let value = $(this).val();
                        self.filter_sellable = (value === 'sellable');
                        self.buildItems();
                    });
                    if (!input.val()) {
                        input.val('all').change();
                    }
                },
                buildBalances: function () {
                    let block = $('#shop-balance').empty();
                    if (!this.currencies || this.currencies.length === 0) {
                        return;
                    }

                    let parts = [];
                    let defaultCurrency = null;
                    let secondary = [];
                    for (var i in this.currencies) {
                        let currency = this.currencies[i];
                        if (parseInt(currency.is_default, 10) === 1 && !defaultCurrency) {
                            defaultCurrency = currency;
                        } else {
                            secondary.push(currency);
                        }
                    }
                    if (defaultCurrency) {
                        let balance = (this.balances && this.balances[defaultCurrency.id] != null) ? this.balances[defaultCurrency.id] : 0;
                        let label = Utils().formatNumber(balance);
                        if (defaultCurrency.image && defaultCurrency.image !== '') {
                            label = '<img src="' + defaultCurrency.image + '" class="me-3" alt="' + defaultCurrency.name + '" title="' + defaultCurrency.name + '" style="width: 24px; height: 24px;">' + label;
                        } else {
                            label = (defaultCurrency.symbol && defaultCurrency.symbol != '') ? (defaultCurrency.symbol + ' ' + balance) : (balance + ' ' + defaultCurrency.name);
                        }
                        parts.push('<span class="border-primary">' + label + '</span>');
                    }
                    if (secondary.length > 0) {
                        let extra = [];
                        for (var j in secondary) {
                            let currency = secondary[j];
                            let balance = (this.balances && this.balances[currency.id] != null) ? this.balances[currency.id] : 0;
                            let label = Utils().formatNumber(balance);
                            if (currency.image && currency.image !== '') {
                                label = '<img src="' + currency.image + '" class="me-3" alt="' + currency.name + '" title="' + currency.name + '" style="width: 24px; height: 24px;">' + label;
                            } else {
                                label = (currency.symbol && currency.symbol != '') ? (currency.symbol + ' ' + balance) : (balance + ' ' + currency.name);
                            }
                            extra.push('<span>' + label + '</span>');
                        }
                        parts = parts.concat(extra);
                    }

                    block.html(parts.join(''));
                },
                getDefaultCurrency: function () {
                    if (!this.currencies || this.currencies.length === 0) {
                        return null;
                    }
                    for (var i in this.currencies) {
                        if (parseInt(this.currencies[i].is_default, 10) === 1) {
                            return this.currencies[i];
                        }
                    }
                    return null;
                },
                formatCurrency: function (amount, currency) {
                    let label = amount;
                    if (currency && currency.symbol) {
                        label = currency.symbol + ' ' + amount;
                    } else if (currency && currency.code) {
                        label = amount + ' ' + currency.code;
                    }
                    if (currency && currency.image) {
                        label = '<img src="' + currency.image + '" alt="" style="width: 14px; height: 14px; border-radius: 3px; margin-right: 4px;">' + label;
                    }
                    return label;
                },
                buildCategories: function () {
                    let tabs = $('#shop-category-tabs');
                    if (!tabs.length) {
                        return;
                    }
                    tabs.empty();
                    let allActive = (this.active_category == null) ? ' active' : '';
                    tabs.append(
                        '<li class="nav-item" role="presentation">'
                        + '<button class="nav-link' + allActive + '" type="button" data-role="shop-category" data-category-id="">Tutti</button>'
                        + '</li>'
                    );
                    for (var i in this.categories) {
                        let row = this.categories[i];
                        if (!row) {
                            continue;
                        }
                        let id = (row.id !== null && typeof row.id !== 'undefined') ? row.id : 0;
                        let name = row.name || 'Altro';
                        let active = (this.active_category != null && this.active_category == id) ? ' active' : '';
                        tabs.append(
                            '<li class="nav-item" role="presentation">'
                            + '<button class="nav-link' + active + '" type="button" data-role="shop-category" data-category-id="' + id + '">' + name + '</button>'
                            + '</li>'
                        );
                    }
                    this.bindCategoryTabs();
                },
                bindCategoryTabs: function () {
                    var self = this;
                    let tabs = $('#shop-category-tabs');
                    if (!tabs.length) {
                        return;
                    }
                    tabs.off('click', '[data-role="shop-category"]');
                    tabs.on('click', '[data-role="shop-category"]', function () {
                        let btn = $(this);
                        tabs.find('.nav-link').removeClass('active');
                        btn.addClass('active');
                        let id = btn.data('category-id');
                        if (id === '' || typeof id === 'undefined') {
                            self.active_category = null;
                        } else {
                            let parsed = parseInt(id, 10);
                            self.active_category = isNaN(parsed) ? null : parsed;
                        }
                        self.buildItems();
                    });
                },
                buildItems: function () {
                    let block = $('#shop-items').empty();
                    var self = this;

                    if (!this.items || this.items.length === 0) {
                        block.append('<div class="col-12"><div class="alert alert-info">Nessun oggetto disponibile.</div></div>');
                        return;
                    }

                    let list = (this.items || []).slice();
                    let selfRef = this;
                    list = list.filter(function (item) {
                        if (selfRef.active_category != null && item.category_id != selfRef.active_category) {
                            return false;
                        }
                        if (selfRef.search_term) {
                            let name = (item.name || '').toLowerCase();
                            let desc = (item.description || '').toLowerCase();
                            if (name.indexOf(selfRef.search_term) === -1 && desc.indexOf(selfRef.search_term) === -1) {
                                return false;
                            }
                        }
                        if (selfRef.filter_sellable) {
                            let sellableQty = parseInt(item.sellable_qty, 10);
                            let sellUnitPrice = parseInt(item.sell_unit_price, 10);
                            if (isNaN(sellableQty) || sellableQty < 1) {
                                return false;
                            }
                            if (isNaN(sellUnitPrice) || sellUnitPrice < 1) {
                                return false;
                            }
                        }
                        return true;
                    });

                    let sortKey = this.sort_key || 'name';
                    let byName = function (a, b) {
                        return (a.name || '').toString().localeCompare((b.name || '').toString());
                    };
                    list.sort(function (a, b) {
                        if (sortKey === 'price') {
                            let pa = parseInt(a.price, 10) || 0;
                            let pb = parseInt(b.price, 10) || 0;
                            if (pa === pb) {
                                return byName(a, b);
                            }
                            return pa - pb;
                        }
                        if (sortKey === 'promo') {
                            let pa = parseInt(a.is_promo, 10) || 0;
                            let pb = parseInt(b.is_promo, 10) || 0;
                            if (pa !== pb) {
                                return pb - pa;
                            }
                            return byName(a, b);
                        }
                        return byName(a, b);
                    });

                    if (list.length === 0) {
                        block.append('<div class="col-12"><div class="alert alert-info">Nessun risultato per i filtri selezionati.</div></div>');
                        return;
                    }

                    for (var i = 0; i < list.length; i++) {
                        let item = list[i];

                        let template = $($('template[name="template_shop_item"]').html());
                        let image = (item.image && item.image != '') ? item.image : '/assets/imgs/defaults-images/default-location.png';
                        let priceLabel = (item.currency_symbol && item.currency_symbol != '') ? (item.currency_symbol + ' ' + item.price) : (item.price + ' ' + (item.currency_code || ''));
                        if (item.currency_image && item.currency_image !== '') {
                            priceLabel = '<img src="' + item.currency_image + '" alt="" style="width: 14px; height: 14px; border-radius: 3px; margin-right: 4px;">' + priceLabel;
                        }
                        let stockVal = (item.stock != null) ? parseInt(item.stock, 10) : null;
                        if (isNaN(stockVal)) {
                            stockVal = null;
                        }
                        let maxPurchase = (item.max_purchase != null) ? parseInt(item.max_purchase, 10) : null;
                        if (isNaN(maxPurchase)) {
                            maxPurchase = null;
                        }
                        let balance = 0;
                        if (this.balances && item.currency_id != null && this.balances[item.currency_id] != null) {
                            balance = parseInt(this.balances[item.currency_id], 10);
                            if (isNaN(balance)) {
                                balance = 0;
                            }
                        }
                        let priceVal = (item.price != null) ? parseInt(item.price, 10) : 0;
                        if (isNaN(priceVal)) {
                            priceVal = 0;
                        }
                        let canAfford = (priceVal <= balance);
                        let stockLabel = '';
                        if (stockVal !== null) {
                            stockLabel = (stockVal <= 0) ? 'Esaurito' : ('Disponibili: ' + stockVal);
                        }
                        let sellableQty = parseInt(item.sellable_qty, 10);
                        if (isNaN(sellableQty) || sellableQty < 0) {
                            sellableQty = 0;
                        }
                        let sellUnitPrice = parseInt(item.sell_unit_price, 10);
                        if (isNaN(sellUnitPrice) || sellUnitPrice < 1) {
                            sellUnitPrice = 0;
                        }

                        template.find('[name="image"]').attr('src', image);
                        let nameEl = template.find('[name="name"]');
                        nameEl.text(item.name);
                        template.find('[name="description"]').text(item.description || '');
                        template.find('[name="price"]').html(priceLabel);
                        template.find('[name="stock"]').text(stockLabel);
                        template.find('[name="afford"]').toggleClass('d-none', canAfford);
                        template.find('[name="meta"]').html(this.buildMeta(item));
                        let buyBtn = template.find('[name="buy"]');
                        buyBtn.removeClass('btn-outline-secondary btn-outline-warning').addClass('btn-success').text('Compra');
                        buyBtn.on('click', this.buy.bind(this, item));
                        let wrapTooltip = function (btn, message) {
                            let wrapper = $('<span class="d-inline-block"></span>');
                            wrapper.attr('data-bs-toggle', 'tooltip');
                            wrapper.attr('data-bs-title', message);
                            btn.wrap(wrapper);
                        };
                        if (stockVal !== null && stockVal <= 0) {
                            template.find('[name="soldout"]').removeClass('d-none');
                            buyBtn.prop('disabled', true).removeClass('btn-success').addClass('btn-outline-secondary').text('Esaurito');
                            wrapTooltip(buyBtn, 'Esaurito');
                        } else if (maxPurchase !== null && maxPurchase <= 0) {
                            buyBtn.prop('disabled', true).removeClass('btn-success').addClass('btn-outline-warning').text('Limite raggiunto');
                            wrapTooltip(buyBtn, 'Limite raggiunto');
                        } else if (!canAfford) {
                            buyBtn.prop('disabled', true).removeClass('btn-success').addClass('btn-outline-secondary');
                            wrapTooltip(buyBtn, 'Saldo insufficiente');
                        }
                        if (parseInt(item.is_promo, 10) === 1) {
                            template.find('.card').addClass('border-warning');
                            if (item.promo_discount && parseInt(item.promo_discount, 10) > 0) {
                                nameEl.append(' <span class="badge text-bg-warning ms-2">Promo -' + item.promo_discount + '%</span>');
                            } else {
                                nameEl.append(' <span class="badge text-bg-warning ms-2">Promo</span>');
                            }
                        }
                        if (item.discount_percent && parseInt(item.discount_percent, 10) > 0) {
                            nameEl.append(' <span class="badge text-bg-warning ms-2">Sconto -' + item.discount_percent + '%</span>');
                        }
                        let sellBtn = template.find('[name="sell"]');
                        if (sellableQty > 0 && sellUnitPrice > 0) {
                            sellBtn.removeClass('d-none');
                            sellBtn.on('click', this.sell.bind(this, item));
                        } else {
                            sellBtn.remove();
                        }

                        template.appendTo(block);
                    }
                    initTooltips(block[0]);
                },
                buildMeta: function (item) {
                    let parts = [];
                    if (item.per_character_limit != null) {
                        parts.push('<span class="badge text-bg-secondary">Limite: ' + item.per_character_limit + '</span>');
                    }
                    if (item.per_day_limit != null) {
                        parts.push('<span class="badge text-bg-secondary">Limite giorno: ' + item.per_day_limit + '</span>');
                    }
                    if (parseInt(item.usable, 10) === 1) {
                        parts.push('<span class="badge text-bg-success">Uso</span>');
                    }
                    if (parseInt(item.cooldown, 10) > 0) {
                        parts.push('<span class="badge text-bg-dark">CD ' + item.cooldown + 's</span>');
                    }
                    if (item.applies_state_name) {
                        parts.push('<span class="badge text-bg-info">Applica: ' + this.escapeHtml(item.applies_state_name) + '</span>');
                    }
                    if (item.removes_state_name) {
                        parts.push('<span class="badge text-bg-warning">Rimuove: ' + this.escapeHtml(item.removes_state_name) + '</span>');
                    }
                    return parts.join(' ');
                },
                escapeHtml: function (value) {
                    return $('<div/>').text(value || '').html();
                },
                getBuyMax: function (item) {
                    let maxPurchase = (item && item.max_purchase != null) ? parseInt(item.max_purchase, 10) : null;
                    if (!isNaN(maxPurchase) && maxPurchase >= 0) {
                        return maxPurchase;
                    }
                    let stockVal = (item && item.stock != null) ? parseInt(item.stock, 10) : null;
                    if (isNaN(stockVal)) {
                        stockVal = null;
                    }
                    if (stockVal !== null) {
                        return stockVal;
                    }
                    let stackable = (item && parseInt(item.is_stackable, 10) === 1);
                    return stackable ? 99 : 10;
                },
                buy: function (item) {
                    var self = this;
                    if (!item || !item.shop_item_id) {
                        Toast.show({ body: 'Oggetto non valido.', type: 'error' });
                        return;
                    }
                    let maxQty = this.getBuyMax(item);
                    if (!maxQty || maxQty < 1) {
                        Toast.show({ body: 'Oggetto non disponibile.', type: 'error' });
                        return;
                    }
                    let itemName = (item.name || 'oggetto').toString();
                    let body = '<div class="text-start text-body">';
                    body += '<p>Quanti <b>' + itemName + '</b> vuoi comprare?</p>';
                    body += '<label class="form-label">Quantita</label>';
                    if (maxQty === 1) {
                        body += '<div class="mt-2">Quantita: <b>1</b></div>';
                        body += '<input type="hidden" name="buy-quantity" value="1">';
                    } else {
                        body += '<input type="range" class="form-range" name="buy-quantity-range" min="1" max="' + maxQty + '" value="1">';
                        body += '<div class="d-flex justify-content-between small text-muted"><span>1</span><span>' + maxQty + '</span></div>';
                        body += '<div class="d-flex justify-content-between mt-2">';
                        body += '<button type="button" class="btn btn-sm btn-outline-secondary" name="buy-quantity-min">Min</button>';
                        body += '<button type="button" class="btn btn-sm btn-outline-secondary" name="buy-quantity-max">Max</button>';
                        body += '</div>';
                        body += '<div class="mt-2">Selezionati: <span name="buy-quantity-label">1</span></div>';
                        body += '<input type="hidden" name="buy-quantity" value="1">';
                    }
                    if (item.stock != null) {
                        body += '<div class="small text-muted mt-2">Disponibili: ' + item.stock + '</div>';
                    }
                    if (item.remaining_per_character != null) {
                        body += '<div class="small text-muted">Limite personaggio: ' + item.remaining_per_character + '</div>';
                    }
                    if (item.remaining_per_day != null) {
                        body += '<div class="small text-muted">Limite giornaliero: ' + item.remaining_per_day + '</div>';
                    }
                    body += '</div>';

                    let dialog = Dialog('warning', {
                        title: 'Compra oggetto',
                        body: body
                    }, function () {
                        let confirmModal = getGeneralConfirmModal();
                        if (!confirmModal) {
                            Toast.show({ body: 'Dialog di conferma non disponibile.', type: 'error' });
                            return;
                        }
                        let rangeQty = parseInt(confirmModal.find('[name="buy-quantity-range"]').val(), 10);
                        let hiddenQty = parseInt(confirmModal.find('[name="buy-quantity"]').val(), 10);
                        let qty = (!isNaN(rangeQty) && rangeQty > 0) ? rangeQty : hiddenQty;
                        if (isNaN(qty) || qty < 1) {
                            qty = 1;
                        }
                        if (qty > maxQty) {
                            qty = maxQty;
                        }
                        hideGeneralConfirmDialog();
                        var payload = {
                            shop_item_id: item.shop_item_id,
                            quantity: qty
                        };
                        var onSuccess = function (response) {
                            if (response && response.balances) {
                                self.balances = response.balances;
                                self.buildBalances();
                            }
                            self.loadItems();
                            Toast.show({
                                body: 'Acquisto completato.',
                                type: 'success'
                            });
                        };
                        var onError = function (error) {
                            showShopError(error, 'Errore durante acquisto.');
                        };
                        callShopModule('buy', payload, onSuccess, onError);
                    });
                    dialog.show();
                    setTimeout(function () {
                        let modal = getGeneralConfirmModal();
                        if (!modal) {
                            return;
                        }
                        let range = modal.find('[name="buy-quantity-range"]');
                        let label = modal.find('[name="buy-quantity-label"]');
                        let hidden = modal.find('[name="buy-quantity"]');
                        let minBtn = modal.find('[name="buy-quantity-min"]');
                        let maxBtn = modal.find('[name="buy-quantity-max"]');
                        if (hidden.length) {
                            hidden.val(1);
                        }
                        if (label.length) {
                            label.text('1');
                        }
                        if (range.length) {
                            range.val(1);
                            range.off('input.shop').on('input.shop', function () {
                                let val = $(this).val();
                                label.text(val);
                                hidden.val(val);
                            });
                            if (minBtn.length) {
                                minBtn.off('click.shop').on('click.shop', function () {
                                    range.val(1).trigger('input');
                                });
                            }
                            if (maxBtn.length) {
                                maxBtn.off('click.shop').on('click.shop', function () {
                                    range.val(maxQty).trigger('input');
                                });
                            }
                        }
                    }, 0);
                },
                sell: function (item) {
                    var self = this;
                    if (!item || !item.item_id) {
                        Toast.show({
                            body: 'Oggetto non valido.',
                            type: 'error'
                        });
                        return;
                    }

                    let sellableQty = parseInt(item.sellable_qty, 10);
                    if (isNaN(sellableQty) || sellableQty < 1) {
                        Toast.show({
                            body: 'Oggetto non vendibile.',
                            type: 'error'
                        });
                        return;
                    }
                    let unitPrice = parseInt(item.sell_unit_price, 10);
                    if (isNaN(unitPrice) || unitPrice < 1) {
                        Toast.show({
                            body: 'Prezzo di vendita non valido.',
                            type: 'error'
                        });
                        return;
                    }

                    let body = '<div class="text-start text-body">';
                    body += '<p>Quanti <b>' + item.name + '</b> vuoi vendere?</p>';
                    body += '<label class="form-label">Quantita</label>';
                    if (sellableQty === 1) {
                        body += '<div class="mt-2">Quantita: <b>1</b></div>';
                        body += '<input type="hidden" name="sell-quantity" value="1">';
                    } else {
                        body += '<input type="range" class="form-range" name="sell-quantity-range" min="1" max="' + sellableQty + '" value="1">';
                        body += '<div class="d-flex justify-content-between small text-muted"><span>1</span><span>' + sellableQty + '</span></div>';
                        body += '<div class="d-flex justify-content-between mt-2">';
                        body += '<button type="button" class="btn btn-sm btn-outline-secondary" name="sell-quantity-min">Min</button>';
                        body += '<button type="button" class="btn btn-sm btn-outline-secondary" name="sell-quantity-max">Max</button>';
                        body += '</div>';
                        body += '<div class="mt-2">Selezionati: <span name="sell-quantity-label">1</span></div>';
                        body += '<input type="hidden" name="sell-quantity" value="1">';
                    }
                    body += '<div class="small text-muted mt-2">Prezzo vendita unitario: ' + this.formatCurrency(unitPrice, this.getDefaultCurrency()) + '</div>';
                    body += '</div>';

                    let dialog = Dialog('warning', {
                        title: 'Vendi oggetto',
                        body: body
                    }, function () {
                        let confirmModal = getGeneralConfirmModal();
                        if (!confirmModal) {
                            Toast.show({ body: 'Dialog di conferma non disponibile.', type: 'error' });
                            return;
                        }
                        let qty = parseInt(confirmModal.find('[name="sell-quantity"]').val(), 10);
                        if (isNaN(qty) || qty < 1) {
                            Toast.show({ body: 'Quantita non valida.', type: 'error' });
                            return;
                        }
                        if (qty > sellableQty) {
                            qty = sellableQty;
                        }
                        hideGeneralConfirmDialog();
                        var payload = {
                            item_id: item.item_id,
                            quantity: qty,
                            shop_id: self.shop_id,
                            location_id: self.location_id
                        };
                        var onSuccess = function (response) {
                            if (response && response.balances) {
                                self.balances = response.balances;
                                self.buildBalances();
                            }
                            self.loadItems();
                            Toast.show({
                                body: 'Vendita completata.',
                                type: 'success'
                            });
                        };
                        var onError = function (error) {
                            showShopError(error, 'Errore durante vendita.');
                        };
                        callShopModule('sell', payload, onSuccess, onError);
                    });
                    dialog.show();
                    setTimeout(function () {
                        let modal = getGeneralConfirmModal();
                        if (!modal) {
                            return;
                        }
                        let range = modal.find('[name="sell-quantity-range"]');
                        let label = modal.find('[name="sell-quantity-label"]');
                        let hidden = modal.find('[name="sell-quantity"]');
                        let minBtn = modal.find('[name="sell-quantity-min"]');
                        let maxBtn = modal.find('[name="sell-quantity-max"]');
                        if (range.length) {
                            range.off('input.shop').on('input.shop', function () {
                                let val = $(this).val();
                                label.text(val);
                                hidden.val(val);
                            });
                            if (minBtn.length) {
                                minBtn.off('click.shop').on('click.shop', function () {
                                    range.val(1).trigger('input');
                                });
                            }
                            if (maxBtn.length) {
                                maxBtn.off('click.shop').on('click.shop', function () {
                                    range.val(sellableQty).trigger('input');
                                });
                            }
                        }
                    }, 0);
                }
            };

            let shop = Object.assign({}, page, extension);
            return shop.init();
    }

    window.GameShopPage = GameShopPage;
})(window);



