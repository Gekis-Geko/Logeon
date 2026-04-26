const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function resolveModule(name) {
    if (!globalWindow.RuntimeBootstrap || typeof globalWindow.RuntimeBootstrap.resolveAppModule !== 'function') {
        return null;
    }
    try {
        return globalWindow.RuntimeBootstrap.resolveAppModule(name);
    } catch (error) {
        return null;
    }
}

function escapeHtml(value) {
    return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function toInt(value, fallback) {
    var num = parseInt(value, 10);
    if (isNaN(num)) {
        return fallback;
    }
    return num;
}

function buildItemNarrativeBadges(item) {
    var badges = [];
    if (toInt(item && item.usable, 0) === 1) {
        badges.push('<span class="badge text-bg-success">Uso</span>');
    }
    var cooldown = toInt(item && item.cooldown, 0);
    if (cooldown > 0) {
        badges.push('<span class="badge text-bg-secondary">CD ' + escapeHtml(String(cooldown)) + 's</span>');
    }
    var appliesState = String((item && item.applies_state_name) || '').trim();
    var removesState = String((item && item.removes_state_name) || '').trim();
    if (appliesState !== '') {
        badges.push('<span class="badge text-bg-info">Applica: ' + escapeHtml(appliesState) + '</span>');
    }
    if (removesState !== '') {
        badges.push('<span class="badge text-bg-warning">Rimuove: ' + escapeHtml(removesState) + '</span>');
    }
    return badges.join(' ');
}

function getInventoryErrorInfo(error, fallback) {
    var fb = fallback || 'Operazione non riuscita.';
    if (globalWindow.GameFeatureError && typeof globalWindow.GameFeatureError.info === 'function') {
        return globalWindow.GameFeatureError.info(error, fb);
    }
    if (globalWindow.GameFeatureError && typeof globalWindow.GameFeatureError.normalize === 'function') {
        return {
            message: globalWindow.GameFeatureError.normalize(error, fb),
            errorCode: '',
            raw: error
        };
    }
    if (globalWindow.Request && typeof globalWindow.Request.getErrorInfo === 'function') {
        return globalWindow.Request.getErrorInfo(error, fb);
    }
    if (globalWindow.Request && typeof globalWindow.Request.getErrorMessage === 'function') {
        return {
            message: globalWindow.Request.getErrorMessage(error, fb),
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
    return { message: fb, errorCode: '', raw: error };
}

function showInventoryError(error, fallback) {
    if (globalWindow.GameFeatureError && typeof globalWindow.GameFeatureError.toastMapped === 'function') {
        return globalWindow.GameFeatureError.toastMapped(error, fallback, {
            map: {
                character_invalid: 'Personaggio non valido.',
                item_invalid: 'Oggetto non valido.',
                item_not_found: 'Oggetto non trovato.',
                item_not_equippable: 'Questo oggetto non puo essere equipaggiato.',
                item_not_usable: 'Questo oggetto non puo essere usato.',
                item_cooldown_active: 'Oggetto in cooldown: attendi prima di riutilizzarlo.',
                ammo_required: 'L\'arma da tiro non ha una munizione configurata.',
                ammo_not_enough: 'Munizioni insufficienti per usare questa arma.',
                ammo_reload_required: 'Arma scarica: ricarica prima di usarla.',
                ammo_reload_not_supported: 'Ricarica non supportata per questo oggetto.',
                ammo_reload_disabled_in_narrative: 'Ricarica disponibile solo con conflitto casuale attivo.',
                ammo_magazine_full: 'Caricatore gia pieno.',
                item_needs_maintenance: 'Questo equipaggiamento richiede manutenzione.',
                item_jammed: 'L\'arma si e inceppata.',
                item_maintenance_not_supported: 'Manutenzione non disponibile per questo oggetto.',
                item_equipped: 'Questo oggetto risulta gia equipaggiato.',
                item_not_sellable: 'Questo oggetto non e vendibile.',
                slot_required: 'Devi selezionare uno slot.',
                slot_invalid: 'Lo slot selezionato non e valido.',
                slot_unavailable: 'Lo slot selezionato non e disponibile.',
                slot_group_limit_reached: 'Hai raggiunto il limite equipaggiabile per questo gruppo di slot.',
                equipment_requirement_not_met: 'Requisiti di equipaggiamento non soddisfatti.',
                equipment_schema_missing: 'Schema equipaggiamento mancante: applica le patch database della fase equipment.',
                swap_source_empty: 'Nessun oggetto equipaggiato nello slot di origine.',
                swap_target_incompatible: 'L\'oggetto selezionato non e compatibile con lo slot destinazione.',
                swap_source_incompatible: 'L\'oggetto nello slot destinazione non e compatibile con lo slot origine.',
                quantity_invalid: 'Quantita non valida.',
                quantity_unavailable: 'Quantita non disponibile.',
                sell_price_invalid: 'Prezzo di vendita non valido.',
                inventory_capacity_reached: 'Inventario pieno: libera spazio prima di aggiungere nuovi oggetti.',
                inventory_stack_limit_reached: 'Hai raggiunto la quantita massima trasportabile per questo oggetto.'
            },
            validationCodes: [
                'character_invalid',
                'item_invalid',
                'item_not_found',
                'item_not_equippable',
                'item_not_usable',
                'item_cooldown_active',
                'ammo_required',
                'ammo_not_enough',
                'ammo_reload_required',
                'ammo_reload_not_supported',
                'ammo_reload_disabled_in_narrative',
                'ammo_magazine_full',
                'item_needs_maintenance',
                'item_jammed',
                'item_maintenance_not_supported',
                'item_equipped',
                'item_not_sellable',
                'slot_required',
                'slot_invalid',
                'slot_unavailable',
                'slot_group_limit_reached',
                'equipment_requirement_not_met',
                'equipment_schema_missing',
                'swap_source_empty',
                'swap_target_incompatible',
                'swap_source_incompatible',
                'quantity_invalid',
                'quantity_unavailable',
                'sell_price_invalid',
                'inventory_capacity_reached',
                'inventory_stack_limit_reached'
            ],
            validationType: 'warning',
            defaultType: 'error',
            preferServerMessageCodes: ['equipment_requirement_not_met']
        });
    }

    var errorInfo = getInventoryErrorInfo(error, fallback);
    Toast.show({
        body: errorInfo.message || fallback || 'Operazione non riuscita.',
        type: 'error'
    });
    return errorInfo;
}


function GameBagPage(char_id, extension) {
        let page = {
            dataset: null,
            dg_threads: {},
            dg_bag_config: null,
            categories: [],
            category_id: null,
            search_term: '',
            search_timer: null,
            sort_field: 'name',
            sort_direction: 'ASC',
            inventoryModule: null,
            getInventoryModule: function () {
                if (this.inventoryModule) {
                    return this.inventoryModule;
                }
                if (typeof resolveModule !== 'function') {
                    return null;
                }

                this.inventoryModule = resolveModule('game.inventory');
                return this.inventoryModule;
            },
            callInventory: function (method, payload, action, onSuccess, onError) {
                var mod = this.getInventoryModule();
                var fn = String(method || '').trim();
                if (!mod || fn === '' || typeof mod[fn] !== 'function') {
                    if (typeof onError === 'function') {
                        onError(new Error('Inventory module not available: ' + fn));
                    }
                    return false;
                }

                var request = null;
                if (typeof action === 'string' && action.trim() !== '') {
                    request = mod[fn](payload, action);
                } else {
                    request = mod[fn](payload);
                }

                Promise.resolve(request).then(function (response) {
                    if (typeof onSuccess === 'function') {
                        onSuccess(response);
                    }
                }).catch(function (error) {
                    if (typeof onError === 'function') {
                        onError(error);
                    }
                });

                return true;
            },
            getBagDatagridConfig: function () {
                let mod = this.getInventoryModule();
                if (mod && typeof mod.bagGridConfig === 'function') {
                    try {
                        return mod.bagGridConfig();
                    } catch (e) {
                        console.warn('[Bag] module bagGridConfig failed:', e);
                    }
                }
                return null;
            },
            init: function () {
                if (null == char_id) {
                    Dialog('danger', {title: 'Errore', body: '<p>Riferimento al personaggio mancante.</p>'}).show();

                    return;
                }

                this.bindSearch();
                this.bindSort();
                var self = this;
                this.loadCategories(function () {
                    self.get();
                });

                return this;
            },
            sync: function () {
                this.init();
            },
            get: function () {
                var self = this;
                this.callInventory('getBag', { id: char_id }, null, function (response) {
                    self.dataset = response.dataset;

                    if (null != self.dataset) {
                        self.build();
                    }
                }, function (error) {
                    showInventoryError(error, 'Impossibile caricare la borsa.');
                });
            },
            build: function () {
                this.buildDatagrid();
            },
            bindSearch: function () {
                var self = this;
                let input = $('#bag-search');
                if (input.length) {
                    input.off('input').on('input', function () {
                        self.search_term = $(this).val().toLowerCase().trim();
                        if (self.search_timer) {
                            globalWindow.clearTimeout(self.search_timer);
                        }
                        self.search_timer = globalWindow.setTimeout(function () {
                            self.applyFilters();
                        }, 300);
                    });
                }
                let clear = $('[data-action="bag-search-clear"]');
                if (clear.length) {
                    clear.off('click').on('click', function () {
                        if (input.length) {
                            input.val('');
                        }
                        self.search_term = '';
                        self.applyFilters();
                    });
                }
            },
            bindSort: function () {
                var self = this;
                let fields = $('[data-role="bag-sort-field"]');
                if (!fields.length) {
                    fields = $('[data-role="bag-sort"]'); // backward compatibility
                }
                let directionBtn = $('[data-role="bag-sort-direction"]');

                if (!fields.length && !directionBtn.length) {
                    return;
                }

                fields.off('click').on('click', function () {
                    let btn = $(this);
                    let field = (btn.data('sort-field') || '').toString().trim();
                    if (!field) {
                        return;
                    }

                    let changedField = (self.sort_field !== field);
                    self.sort_field = field;
                    if (changedField) {
                        let defaultDir = (btn.data('sort-default-direction') || 'asc').toString().toUpperCase();
                        self.sort_direction = (defaultDir === 'DESC') ? 'DESC' : 'ASC';
                    }

                    self.refreshSortButtons();
                    self.applyFilters();
                });

                directionBtn.off('click').on('click', function () {
                    self.sort_direction = (self.sort_direction === 'DESC') ? 'ASC' : 'DESC';
                    self.refreshSortButtons();
                    self.applyFilters();
                });

                this.refreshSortButtons();
            },
            refreshSortButtons: function () {
                let fields = $('[data-role="bag-sort-field"]');
                if (!fields.length) {
                    fields = $('[data-role="bag-sort"]'); // backward compatibility
                }
                let directionBtn = $('[data-role="bag-sort-direction"]');
                let directionIcon = $('[data-role="bag-sort-direction-icon"]');
                let directionLabel = $('[data-role="bag-sort-direction-label"]');

                this.sort_direction = (this.sort_direction === 'DESC') ? 'DESC' : 'ASC';
                let activeField = this.sort_field;
                let fieldFound = false;

                fields.each(function () {
                    let btn = $(this);
                    let field = (btn.data('sort-field') || '').toString().trim();
                    let isActive = (field === activeField);
                    btn.toggleClass('active', isActive);
                    btn.attr('aria-pressed', isActive ? 'true' : 'false');
                    if (isActive) {
                        fieldFound = true;
                    }
                });

                if (!fieldFound && fields.length) {
                    let firstFieldBtn = fields.eq(0);
                    let firstField = (firstFieldBtn.data('sort-field') || '').toString().trim();
                    if (firstField) {
                        this.sort_field = firstField;
                        fields.removeClass('active').attr('aria-pressed', 'false');
                        firstFieldBtn.addClass('active').attr('aria-pressed', 'true');
                    }
                }

                if (directionBtn.length) {
                    let isDesc = (this.sort_direction === 'DESC');
                    let text = isDesc ? 'DESC' : 'ASC';
                    let title = isDesc ? 'Ordine discendente' : 'Ordine ascendente';
                    directionBtn.attr('title', title);
                    directionBtn.attr('aria-label', title);
                    if (directionLabel.length) {
                        directionLabel.text(text);
                    }
                    if (directionIcon.length) {
                        directionIcon.removeClass('bi-sort-up bi-sort-down');
                        directionIcon.addClass(isDesc ? 'bi-sort-down' : 'bi-sort-up');
                    }
                }
            },
            loadCategories: function (onComplete) {
                var self = this;
                this.callInventory('categories', null, null, function (response) {
                    self.categories = (response && response.dataset) ? response.dataset : [];
                    self.buildCategoryTabs();
                    if (typeof onComplete === 'function') {
                        onComplete();
                    }
                }, function () {
                    self.categories = [];
                    self.buildCategoryTabs();
                    if (typeof onComplete === 'function') {
                        onComplete();
                    }
                });
            },
            buildCategoryTabs: function () {
                let tabs = $('#bag-category-tabs');
                if (!tabs.length) {
                    return;
                }

                tabs.empty();

                if (!this.categories || !this.categories.length) {
                    this.category_id = null;
                    tabs.append(
                        '<li class="nav-item" role="presentation">'
                        + '<span class="text-muted small">Nessuna categoria disponibile.</span>'
                        + '</li>'
                    );
                    if (this.dg_bag) {
                        this.applyFilters();
                    }
                    return;
                }

                let selected = null;
                for (var c in this.categories) {
                    if (!this.categories[c]) {
                        continue;
                    }
                    let maybeId = (this.categories[c].category_id !== null && typeof this.categories[c].category_id !== 'undefined')
                        ? parseInt(this.categories[c].category_id, 10)
                        : 0;
                    if (!isNaN(maybeId)) {
                        if (selected === null) {
                            selected = maybeId;
                        }
                        if (this.category_id !== null && parseInt(this.category_id, 10) === maybeId) {
                            selected = maybeId;
                            break;
                        }
                    }
                }
                this.category_id = selected;

                for (var i in this.categories) {
                    let row = this.categories[i];
                    if (!row) {
                        continue;
                    }
                    let id = (row.category_id !== null && typeof row.category_id !== 'undefined') ? parseInt(row.category_id, 10) : 0;
                    if (isNaN(id)) {
                        id = 0;
                    }
                    let name = row.name || 'Altro';
                    let active = (this.category_id !== null && id === parseInt(this.category_id, 10)) ? ' active' : '';
                    tabs.append(
                        '<li class="nav-item" role="presentation">'
                        + '<button class="nav-link w-100 text-start' + active + '" type="button" data-role="bag-category" data-category-id="' + id + '">' + name + '</button>'
                        + '</li>'
                    );
                }

                this.bindCategoryTabs();
                if (this.dg_bag) {
                    this.applyFilters();
                }
            },
            bindCategoryTabs: function () {
                var self = this;
                let tabs = $('#bag-category-tabs');
                if (!tabs.length) {
                    return;
                }

                tabs.off('click', '[data-role="bag-category"]');
                tabs.on('click', '[data-role="bag-category"]', function () {
                    let btn = $(this);

                    tabs.find('.nav-link').removeClass('active');
                    btn.addClass('active');

                    let id = btn.data('category-id');
                    if (id === '' || typeof id === 'undefined' || id === null) {
                        self.category_id = null;
                    } else {
                        let parsed = parseInt(id, 10);
                        self.category_id = isNaN(parsed) ? null : parsed;
                    }
                    self.applyFilters();
                });
            },
            buildDatagrid: function () {
                var self = this;
                this.dg_bag_config = this.getBagDatagridConfig();
                if (!this.dg_bag_config) {
                    console.warn('[Bag] datagrid config not available.');
                    return;
                }

                if (!this.dg_bag_config.nav) {
                    this.dg_bag_config.nav = {};
                }
                if (typeof this.dg_bag_config.nav.results === 'undefined') {
                    this.dg_bag_config.nav.results = 10;
                }

                this.dg_bag = new Datagrid('grid-bag', this.dg_bag_config);
                this.dg_bag.onGetDataSuccess = function () {
                    self.bindDropActions();
                    self.bindDestroyActions();
                };
                this.applyFilters();
            },
            buildOrderBy: function () {
                let field = (this.sort_field || 'name').toString().trim();
                let direction = (this.sort_direction === 'DESC') ? 'DESC' : 'ASC';
                return field + '|' + direction;
            },
            applyFilters: function () {
                if (!this.dg_bag || !this.dg_bag_config || !this.dg_bag_config.nav) {
                    return;
                }

                let query = {
                    char_id: char_id
                };
                if (this.search_term) {
                    query.search = this.search_term;
                }
                if (this.category_id !== null && typeof this.category_id !== 'undefined') {
                    query.category_id = this.category_id;
                }

                if (this.dg_bag && this.dg_bag.lang) {
                    this.dg_bag.lang.no_results = (this.category_id !== null && typeof this.category_id !== 'undefined')
                        ? 'Non ci sono oggetti per questa categoria'
                        : 'Nessun risultato';
                }

                this.dg_bag_config.nav.query = query;
                this.dg_bag.loadData(
                    this.dg_bag_config.nav.query,
                    this.dg_bag_config.nav.results,
                    1,
                    [
                        this.buildOrderBy(),
                    ]
                );
            },
            bindDropActions: function () {
                var self = this;
                let grid = $('#grid-bag');
                if (!grid.length) {
                    return;
                }
                grid.off('click', '[data-action="drop"]');

                grid.on('click', '[data-action="drop"]', function (e) {
                    e.preventDefault();
                    let btn = $(this);
                    let source = (btn.data('source') || '').toString();
                    let itemName = (btn.data('item-name') || 'Oggetto').toString();
                    let characterItemId = parseInt(btn.data('character-item-id'), 10);
                    let instanceId = parseInt(btn.data('instance-id'), 10);
                    let qty = parseInt(btn.data('quantity'), 10);
                    if (isNaN(qty) || qty < 1) {
                        qty = 1;
                    }

                    if (source === 'instance' && !instanceId) {
                        Toast.show({ body: 'Oggetto non valido.', type: 'error' });
                        return;
                    }
                    if (source === 'stack' && !characterItemId) {
                        Toast.show({ body: 'Oggetto non valido.', type: 'error' });
                        return;
                    }

                    let body = '<div class="text-start text-body">';
                    body += '<p>Vuoi lasciare <b>' + itemName + '</b> nella location?</p>';
                    if (source === 'stack' && qty > 1) {
                        body += '<label class="form-label">Quantita</label>';
                        body += '<input type="number" class="form-control" name="drop-quantity" min="1" max="' + qty + '" value="1">';
                    }
                    body += '</div>';

                    let dialog = Dialog('warning', {
                        title: 'Lascia oggetto',
                        body: body
                    }, function () {
                        let dropQty = 1;
                        if (source === 'stack' && qty > 1) {
                            let confirmModal = getGeneralConfirmModal();
                            if (!confirmModal) {
                                Toast.show({ body: 'Dialog di conferma non disponibile.', type: 'error' });
                                return;
                            }
                            dropQty = parseInt(confirmModal.find('[name="drop-quantity"]').val(), 10);
                            if (isNaN(dropQty) || dropQty < 1) {
                                Toast.show({ body: 'Quantita non valida.', type: 'error' });
                                return;
                            }
                            if (dropQty > qty) {
                                dropQty = qty;
                            }
                        }
                        hideGeneralConfirmDialog();
                        if (source === 'instance') {
                            self.drop({
                                character_item_instance_id: instanceId
                            });
                        } else {
                            self.drop({
                                character_item_id: characterItemId,
                                quantity: dropQty
                            });
                        }
                    });
                    dialog.show();
                });
            },
            drop: function (payload) {
                var self = this;
                this.callInventory('drop', payload, null, function () {
                    Toast.show({ body: 'Oggetto lasciato.', type: 'success' });
                    if (self.dg_bag) {
                        self.dg_bag.reloadData();
                    }
                    if (globalWindow.LocationDrops && typeof globalWindow.LocationDrops.reload === 'function') {
                        globalWindow.LocationDrops.reload();
                    }
                }, function (error) {
                    showInventoryError(error, 'Errore durante il rilascio.');
                });
            },
            bindDestroyActions: function () {
                var self = this;
                let grid = $('#grid-bag');
                if (!grid.length) { return; }
                grid.off('click', '[data-action="destroy"]');
                grid.on('click', '[data-action="destroy"]', function (e) {
                    e.preventDefault();
                    let btn = $(this);
                    let source = (btn.data('source') || '').toString();
                    let itemName = (btn.data('item-name') || 'Oggetto').toString();
                    let characterItemId = parseInt(btn.data('character-item-id'), 10);
                    let instanceId = parseInt(btn.data('instance-id'), 10);
                    let qty = parseInt(btn.data('quantity'), 10);
                    if (isNaN(qty) || qty < 1) { qty = 1; }

                    let body = '<div class="text-start text-body">';
                    body += '<p class="text-danger fw-bold mb-1">Operazione irreversibile!</p>';
                    body += '<p>Distruggere definitivamente <b>' + itemName + '</b>?</p>';
                    if (source === 'stack' && qty > 1) {
                        body += '<label class="form-label">Quantità da distruggere</label>';
                        body += '<input type="number" class="form-control" name="destroy-quantity" min="1" max="' + qty + '" value="1">';
                    }
                    body += '</div>';

                    let dialog = Dialog('danger', { title: 'Distruggi oggetto', body: body }, function () {
                        let destroyQty = 1;
                        if (source === 'stack' && qty > 1) {
                            let confirmModal = getGeneralConfirmModal();
                            if (!confirmModal) { return; }
                            destroyQty = parseInt(confirmModal.find('[name="destroy-quantity"]').val(), 10);
                            if (isNaN(destroyQty) || destroyQty < 1) {
                                Toast.show({ body: 'Quantità non valida.', type: 'error' });
                                return;
                            }
                            if (destroyQty > qty) { destroyQty = qty; }
                        }
                        hideGeneralConfirmDialog();
                        if (source === 'instance') {
                            self.destroyItem({ character_item_instance_id: instanceId });
                        } else {
                            self.destroyItem({ character_item_id: characterItemId, quantity: destroyQty });
                        }
                    });
                    dialog.show();
                });
            },
            destroyItem: function (payload) {
                var self = this;
                this.callInventory('destroy', payload, null, function () {
                    Toast.show({ body: 'Oggetto distrutto.', type: 'success' });
                    if (self.dg_bag) { self.dg_bag.reloadData(); }
                }, function (error) {
                    showInventoryError(error, 'Errore durante la distruzione.');
                });
            },
            useItem: function (payload) {
                var self = this;
                this.callInventory('useItem', payload, null, function () {
                    Toast.show({ body: 'Oggetto usato.', type: 'success' });
                    if (self.dg_bag) {
                        self.dg_bag.reloadData();
                    }
                }, function (error) {
                    showInventoryError(error, 'Errore durante l\'uso dell\'oggetto.');
                });
            }
        };

        let bag = Object.assign({}, page, extension);
        return bag.init();
    }

function GameEquipsPage(extension) {
        let page = {
            items: [],
            available: [],
            slots: [],
            slotIndex: {},
            groups: [],
            inventoryModule: null,
            getInventoryModule: function () {
                if (this.inventoryModule) {
                    return this.inventoryModule;
                }
                if (typeof resolveModule !== 'function') {
                    return null;
                }

                this.inventoryModule = resolveModule('game.inventory');
                return this.inventoryModule;
            },
            callInventory: function (method, payload, action, onSuccess, onError) {
                var mod = this.getInventoryModule();
                var fn = String(method || '').trim();
                if (!mod || fn === '' || typeof mod[fn] !== 'function') {
                    if (typeof onError === 'function') {
                        onError(new Error('Inventory module not available: ' + fn));
                    }
                    return false;
                }

                var request = null;
                if (typeof action === 'string' && action.trim() !== '') {
                    request = mod[fn](payload, action);
                } else {
                    request = mod[fn](payload);
                }

                Promise.resolve(request).then(function (response) {
                    if (typeof onSuccess === 'function') {
                        onSuccess(response);
                    }
                }).catch(function (error) {
                    if (typeof onError === 'function') {
                        onError(error);
                    }
                });

                return true;
            },
            init: function () {
                if (!$('#equips-page').length) {
                    return this;
                }
                this.load();
                return this;
            },
            reload: function () {
                this.load();
            },
            legacySlots: function () {
                return [
                    { key: 'amulet', name: 'Ciondolo', group_key: 'amulet', sort_order: 10 },
                    { key: 'helm', name: 'Elmo', group_key: 'helm', sort_order: 20 },
                    { key: 'weapon_1', name: 'Arma 1', group_key: 'weapon', sort_order: 30 },
                    { key: 'gloves', name: 'Guanti', group_key: 'gloves', sort_order: 40 },
                    { key: 'armor', name: 'Armatura', group_key: 'armor', sort_order: 50 },
                    { key: 'weapon_2', name: 'Arma 2', group_key: 'weapon', sort_order: 60 },
                    { key: 'ring_1', name: 'Anello 1', group_key: 'ring', sort_order: 70 },
                    { key: 'boots', name: 'Stivali', group_key: 'boots', sort_order: 80 },
                    { key: 'ring_2', name: 'Anello 2', group_key: 'ring', sort_order: 90 }
                ];
            },
            normalizeSlots: function (rows, useLegacyFallback) {
                var source = Array.isArray(rows) ? rows : [];
                if (!source.length && useLegacyFallback === true) {
                    source = this.legacySlots();
                }

                var normalized = [];
                for (var i = 0; i < source.length; i++) {
                    var slot = source[i] || {};
                    var key = (slot.key || '').toString().trim();
                    if (!key) {
                        continue;
                    }

                    var sortOrder = parseInt(slot.sort_order, 10);
                    if (isNaN(sortOrder)) {
                        sortOrder = 9999;
                    }

                    normalized.push({
                        id: slot.id || 0,
                        key: key,
                        name: (slot.name || key).toString(),
                        group_key: (slot.group_key || key).toString(),
                        sort_order: sortOrder
                    });
                }

                normalized.sort(function (a, b) {
                    if (a.sort_order !== b.sort_order) {
                        return a.sort_order - b.sort_order;
                    }
                    return a.key.localeCompare(b.key);
                });

                return normalized;
            },
            parseCsv: function (value) {
                var raw = (value || '').toString().trim();
                if (!raw) {
                    return [];
                }

                var chunks = raw.split(',');
                var out = [];
                for (var i = 0; i < chunks.length; i++) {
                    var part = (chunks[i] || '').toString().trim();
                    if (!part) {
                        continue;
                    }
                    if (out.indexOf(part) === -1) {
                        out.push(part);
                    }
                }
                return out;
            },
            rebuildSlotIndex: function () {
                this.slotIndex = {};
                this.groups = [];
                for (var i = 0; i < this.slots.length; i++) {
                    var slot = this.slots[i];
                    this.slotIndex[slot.key] = slot;
                    if (this.groups.indexOf(slot.group_key) === -1) {
                        this.groups.push(slot.group_key);
                    }
                }
            },
            load: function () {
                var self = this;
                this.callInventory('slots', null, null, function (response) {
                    self.slots = self.normalizeSlots((response && response.slots) ? response.slots : [], false);
                    self.rebuildSlotIndex();
                    self.buildSlotSkeleton();
                    self.buildTabsSkeleton();
                    self.loadEquipped();
                }, function () {
                    self.slots = self.normalizeSlots([], false);
                    self.rebuildSlotIndex();
                    self.buildSlotSkeleton();
                    self.buildTabsSkeleton();
                    self.loadEquipped();
                });
            },
            loadEquipped: function () {
                var self = this;
                this.callInventory('equipped', null, null, function (response) {
                    self.items = (response && response.items) ? response.items : [];
                    self.build();
                    self.loadAvailable();
                }, function () {
                    self.items = [];
                    self.build();
                    self.loadAvailable();
                });
            },
            loadAvailable: function () {
                var self = this;
                this.callInventory('available', null, null, function (response) {
                    self.available = (response && response.items) ? response.items : [];
                    self.buildLists();
                }, function () {
                    self.available = [];
                    self.buildLists();
                });
            },
            groupLabel: function (groupKey) {
                var key = (groupKey || '').toString().trim();
                var map = {
                    weapon: 'Armi',
                    ring: 'Anelli',
                    helm: 'Elmi',
                    armor: 'Armature',
                    gloves: 'Guanti',
                    boots: 'Stivali',
                    amulet: 'Ciondoli',
                    accessory: 'Accessori'
                };

                if (map[key]) {
                    return map[key];
                }

                if (!key) {
                    return 'Altro';
                }

                return key.charAt(0).toUpperCase() + key.slice(1);
            },
            buildSlotSkeleton: function () {
                var grid = $('[data-role="equip-slots-grid"]');
                if (!grid.length) {
                    return;
                }

                grid.empty();
                if (!this.slots.length) {
                    grid.html('<div class="text-muted small">Nessuno slot disponibile.</div>');
                    return;
                }

                for (var i = 0; i < this.slots.length; i++) {
                    var slot = this.slots[i];
                    grid.append('<div data-equip-slot="' + slot.key + '"></div>');
                }
            },
            buildTabsSkeleton: function () {
                var tabs = $('[data-role="equip-tabs"]');
                var content = $('[data-role="equip-tab-content"]');
                if (!tabs.length || !content.length) {
                    return;
                }

                tabs.empty();
                content.empty();

                var groups = this.groups.slice();
                if (!groups.length) {
                    groups = ['equipment'];
                }

                for (var i = 0; i < groups.length; i++) {
                    var groupKey = groups[i];
                    var safeGroup = groupKey.replace(/[^a-zA-Z0-9_-]/g, '-');
                    var tabId = 'equips-group-' + safeGroup + '-tab';
                    var paneId = 'equips-group-' + safeGroup + '-pane';
                    var activeClass = (i === 0) ? ' active' : '';
                    var activeSelected = (i === 0) ? 'true' : 'false';
                    var activePane = (i === 0) ? ' show active' : '';

                    tabs.append(
                        '<li class="nav-item" role="presentation">'
                        + '<button class="nav-link' + activeClass + '" id="' + tabId + '" data-bs-toggle="tab" data-bs-target="#' + paneId + '" type="button" role="tab" aria-controls="' + paneId + '" aria-selected="' + activeSelected + '">'
                        + this.groupLabel(groupKey)
                        + '</button>'
                        + '</li>'
                    );

                    content.append(
                        '<div class="tab-pane fade' + activePane + '" id="' + paneId + '" role="tabpanel" aria-labelledby="' + tabId + '" tabindex="0">'
                        + '  <div class="table-responsive">'
                        + '    <table class="table table-striped table-hover align-middle mb-0">'
                        + '      <thead>'
                        + '        <tr>'
                        + '          <th style="width: 120px;">Oggetto</th>'
                        + '          <th>Nome</th>'
                        + '          <th style="width: 140px;">Azione</th>'
                        + '        </tr>'
                        + '      </thead>'
                        + '      <tbody data-equip-list="' + groupKey + '"></tbody>'
                        + '    </table>'
                        + '  </div>'
                        + '</div>'
                    );
                }
            },
            slotLabel: function (slot) {
                var slotKey = (slot || '').toString().trim();
                if (slotKey && this.slotIndex[slotKey] && this.slotIndex[slotKey].name) {
                    return this.slotIndex[slotKey].name;
                }
                return slotKey || 'Slot';
            },
            slotGroupByKey: function (slotKey) {
                var key = (slotKey || '').toString().trim();
                if (!key) {
                    return '';
                }
                if (this.slotIndex[key] && this.slotIndex[key].group_key) {
                    return this.slotIndex[key].group_key;
                }
                return '';
            },
            mapLegacyEquipSlotToGroup: function (equipSlot) {
                var slot = (equipSlot || '').toString().trim();
                if (!slot) {
                    return '';
                }
                if (slot === 'weapon' || slot === 'ring') {
                    return slot;
                }
                var fromKey = this.slotGroupByKey(slot);
                if (fromKey) {
                    return fromKey;
                }
                return slot;
            },
            getEquipSlots: function (equipSlot) {
                var slot = (equipSlot || '').toString().trim();
                if (slot === '') {
                    return [];
                }

                if (this.slotIndex[slot]) {
                    return [slot];
                }

                var byGroup = [];
                for (var i = 0; i < this.slots.length; i++) {
                    if (this.slots[i].group_key === slot) {
                        byGroup.push(this.slots[i].key);
                    }
                }
                if (byGroup.length) {
                    return byGroup;
                }

                if (slot === 'weapon') {
                    return ['weapon_1', 'weapon_2'];
                }
                if (slot === 'ring') {
                    return ['ring_1', 'ring_2'];
                }
                return [slot];
            },
            chooseTargetGroupForItem: function (item, listsByGroup) {
                var allowedGroups = this.parseCsv(item && item.allowed_slot_groups);
                var allowedSlots = this.parseCsv(item && item.allowed_slot_keys);

                if (!allowedGroups.length && allowedSlots.length) {
                    for (var i = 0; i < allowedSlots.length; i++) {
                        var g = this.slotGroupByKey(allowedSlots[i]);
                        if (g && allowedGroups.indexOf(g) === -1) {
                            allowedGroups.push(g);
                        }
                    }
                }

                if (!allowedGroups.length) {
                    var fallbackGroup = this.mapLegacyEquipSlotToGroup(item && item.equip_slot);
                    if (fallbackGroup) {
                        allowedGroups.push(fallbackGroup);
                    }
                }

                for (var j = 0; j < allowedGroups.length; j++) {
                    if (listsByGroup[allowedGroups[j]]) {
                        return allowedGroups[j];
                    }
                }

                var keys = Object.keys(listsByGroup);
                return keys.length ? keys[0] : '';
            },
            build: function () {
                if ($('#equips-slots').length) {
                    this.buildGridSlots();
                    return;
                }
                this.buildSlotMap();
            },
            buildLists: function () {
                var lists = $('[data-equip-list]');
                if (!lists.length) {
                    return;
                }

                var listsByGroup = {};
                lists.each(function () {
                    var body = $(this);
                    var groupKey = (body.data('equip-list') || '').toString();
                    body.empty();
                    if (groupKey) {
                        listsByGroup[groupKey] = body;
                    }
                });

                if (!this.available || this.available.length === 0) {
                    lists.each(function () {
                        $(this).html('<tr><td colspan="3" class="text-muted">Nessun oggetto disponibile.</td></tr>');
                    });
                    return;
                }

                for (var i = 0; i < this.available.length; i++) {
                    var item = this.available[i] || {};
                    var targetGroup = this.chooseTargetGroupForItem(item, listsByGroup);
                    if (!targetGroup || !listsByGroup[targetGroup]) {
                        continue;
                    }

                    var allowedSlots = this.parseCsv(item.allowed_slot_keys);
                    if (!allowedSlots.length) {
                        allowedSlots = this.getEquipSlots(item.equip_slot || '');
                    }

                    var image = (item.image && item.image !== '') ? item.image : '/assets/imgs/defaults-images/default-location.png';
                    var name = item.name || 'Senza nome';
                    var safeName = name.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
                    var allowedSlotsAttr = allowedSlots.join(',');
                    var narrativeBadges = buildItemNarrativeBadges(item);
                    var qualityInfo = this.getQualityInfo(item);
                    var detailHtml = '<div>' + escapeHtml(name) + '</div>';
                    if (narrativeBadges !== '') {
                        detailHtml += '<div class="d-flex flex-wrap gap-1 mt-1">' + narrativeBadges + '</div>';
                    }
                    if (qualityInfo && qualityInfo.label) {
                        detailHtml += '<div class="d-flex flex-wrap gap-1 mt-1"><span class="badge ' + qualityInfo.badgeClass + '">' + escapeHtml(qualityInfo.label) + '</span></div>';
                    }

                    var row = ''
                        + '<tr>'
                        + '  <td><img class="img-fluid" width="120" src="' + image + '" alt=""></td>'
                        + '  <td>' + detailHtml + '</td>'
                        + '  <td class="equip-list-actions">'
                        + '    <button type="button" class="btn btn-sm btn-outline-success equip-list-action-btn equip-action-btn" style="display:inline-block;width:auto;height:auto;" data-action="equip" data-instance-id="' + item.character_item_instance_id + '" data-equip-slot="' + (item.equip_slot || '') + '" data-allowed-slots="' + allowedSlotsAttr + '" data-item-name="' + safeName + '">Equipaggia</button>'
                        + (qualityInfo && qualityInfo.canMaintain
                            ? '    <button type="button" class="btn btn-sm btn-outline-primary ms-1" data-action="maintain-item" data-instance-id="' + item.character_item_instance_id + '" data-item-name="' + safeName + '">Manut.</button>'
                            : '')
                        + '    <button type="button" class="btn btn-sm btn-outline-dark ms-1" data-action="destroy-equip" data-instance-id="' + item.character_item_instance_id + '" data-item-name="' + safeName + '">Distruggi</button>'
                        + '  </td>'
                        + '</tr>';
                    listsByGroup[targetGroup].append(row);
                }

                lists.each(function () {
                    var body = $(this);
                    if (!body.children().length) {
                        body.html('<tr><td colspan="3" class="text-muted">Nessun oggetto disponibile per questa categoria.</td></tr>');
                    }
                });

                this.bindEquipListActions();
            },
            bindEquipListActions: function () {
                var self = this;
                var block = $('#equips-page');
                block.off('click', '[data-action="equip"]');
                block.on('click', '[data-action="equip"]', function (e) {
                    e.preventDefault();
                    var btn = $(this);
                    var instanceId = parseInt(btn.data('instance-id'), 10);
                    var equipSlot = (btn.data('equip-slot') || '').toString();
                    var allowedSlots = self.parseCsv(btn.data('allowed-slots'));
                    var itemName = (btn.data('item-name') || 'Oggetto').toString();
                    if (!instanceId) {
                        Toast.show({ body: 'Oggetto non valido.', type: 'error' });
                        return;
                    }
                    self.showEquipDialog(instanceId, allowedSlots, itemName, equipSlot);
                });
                block.off('click', '[data-action="destroy-equip"]');
                block.on('click', '[data-action="destroy-equip"]', function (e) {
                    e.preventDefault();
                    var btn = $(this);
                    var instanceId = parseInt(btn.data('instance-id'), 10);
                    var itemName = (btn.data('item-name') || 'Oggetto').toString();
                    if (!instanceId) {
                        Toast.show({ body: 'Oggetto non valido.', type: 'error' });
                        return;
                    }
                    var body = '<div class="text-start text-body">';
                    body += '<p class="text-danger fw-bold mb-1">Operazione irreversibile!</p>';
                    body += '<p>Distruggere definitivamente <b>' + itemName + '</b>?</p>';
                    body += '</div>';
                    var dialog = Dialog('danger', { title: 'Distruggi oggetto', body: body }, function () {
                        hideGeneralConfirmDialog();
                        self.destroyEquipItem({ character_item_instance_id: instanceId });
                    });
                    dialog.show();
                });
                block.off('click', '[data-action="maintain-item"]');
                block.on('click', '[data-action="maintain-item"]', function (e) {
                    e.preventDefault();
                    var btn = $(this);
                    var instanceId = parseInt(btn.data('instance-id'), 10);
                    var itemName = (btn.data('item-name') || 'Oggetto').toString();
                    if (!instanceId) {
                        Toast.show({ body: 'Oggetto non valido.', type: 'error' });
                        return;
                    }
                    self.maintainItem(instanceId, itemName);
                });
            },
            destroyEquipItem: function (payload) {
                var self = this;
                this.callInventory('destroy', payload, null, function () {
                    Toast.show({ body: 'Oggetto distrutto.', type: 'success' });
                    self.loadAvailable();
                }, function (error) {
                    showInventoryError(error, 'Errore durante la distruzione.');
                });
            },
            getEquipSlotLabel: function (slot) {
                return this.slotLabel(slot);
            },
            showEquipDialog: function (instanceId, allowedSlots, itemName, legacyEquipSlot) {
                var self = this;
                var slots = Array.isArray(allowedSlots) ? allowedSlots.slice() : [];
                if (!slots.length) {
                    slots = this.getEquipSlots(legacyEquipSlot);
                }
                if (!slots.length) {
                    Toast.show({ body: 'Slot non disponibile.', type: 'error' });
                    return;
                }
                if (slots.length === 1) {
                    self.equip(instanceId, slots[0]);
                    return;
                }

                var optionHtml = '';
                for (var i = 0; i < slots.length; i++) {
                    optionHtml += '<option value="' + slots[i] + '">' + this.getEquipSlotLabel(slots[i]) + '</option>';
                }

                var body = '<div class="text-start text-body">';
                body += '<p>Seleziona lo slot per <b>' + itemName + '</b></p>';
                body += '<label class="form-label">Slot</label>';
                body += '<div><select class="form-select" name="equip-slot"><option value="">Seleziona...</option>' + optionHtml + '</select></div>';
                body += '</div>';

                var dialog = Dialog('default', {
                    title: 'Equipaggia',
                    body: body
                }, function () {
                    var confirmModal = getGeneralConfirmModal();
                    if (!confirmModal) {
                        Toast.show({ body: 'Dialog di conferma non disponibile.', type: 'error' });
                        return;
                    }
                    var selected = confirmModal.find('[name="equip-slot"]').val();
                    if (!selected) {
                        Toast.show({ body: 'Seleziona uno slot.', type: 'error' });
                        return;
                    }
                    hideGeneralConfirmDialog();
                    self.equip(instanceId, selected);
                });
                dialog.show();
            },
            equip: function (instanceId, slot) {
                var self = this;
                this.callInventory('equip', {
                    character_item_instance_id: instanceId,
                    slot: slot
                }, null, function () {
                    Toast.show({ body: 'Oggetto equipaggiato.', type: 'success' });
                    self.reload();
                    if (globalWindow.Bag && typeof globalWindow.Bag.sync === 'function') {
                        globalWindow.Bag.sync();
                    }
                }, function (error) {
                    showInventoryError(error, 'Errore durante equipaggiamento.');
                });
            },
            getSwapTargets: function (currentSlot, item) {
                var slotKey = (currentSlot || '').toString().trim();
                if (!slotKey) {
                    return [];
                }

                var allowed = this.parseCsv(item && item.allowed_slot_keys);
                if (!allowed.length) {
                    allowed = this.getEquipSlots(item && item.equip_slot ? item.equip_slot : slotKey);
                }

                var targets = [];
                for (var i = 0; i < allowed.length; i++) {
                    var target = (allowed[i] || '').toString().trim();
                    if (!target || target === slotKey) {
                        continue;
                    }
                    if (targets.indexOf(target) === -1) {
                        targets.push(target);
                    }
                }

                if (!targets.length) {
                    var groupKey = this.slotGroupByKey(slotKey);
                    if (groupKey) {
                        for (var s = 0; s < this.slots.length; s++) {
                            var slotRow = this.slots[s] || {};
                            if ((slotRow.group_key || '') === groupKey && slotRow.key !== slotKey) {
                                targets.push(slotRow.key);
                            }
                        }
                    }
                }

                return targets;
            },
            getAmmoInfo: function (item) {
                if (!item || toInt(item.requires_ammo, 0) !== 1) {
                    return null;
                }

                var ammoName = String(item.ammo_item_name || 'Munizioni');
                var ammoPerUse = toInt(item.ammo_per_use, 1);
                if (ammoPerUse < 1) {
                    ammoPerUse = 1;
                }
                var magazineSize = toInt(item.ammo_magazine_size, 0);
                if (magazineSize < 0) {
                    magazineSize = 0;
                }
                var loaded = toInt(item.ammo_loaded, 0);
                if (loaded < 0) {
                    loaded = 0;
                }
                if (magazineSize > 0 && loaded > magazineSize) {
                    loaded = magazineSize;
                }

                if (magazineSize > 0) {
                    return {
                        label: 'Caricatore ' + loaded + '/' + magazineSize + ' - ' + ammoName + ' (x' + ammoPerUse + ')',
                        canReload: loaded < magazineSize
                    };
                }

                return {
                    label: 'Munizioni dirette - ' + ammoName + ' (x' + ammoPerUse + ')',
                    canReload: false
                };
            },
            getQualityInfo: function (item) {
                if (!item || toInt(item.quality_enabled, 0) !== 1) {
                    return null;
                }

                var current = toInt(item.quality_current, 0);
                var max = toInt(item.quality_max, 100);
                if (max < 1) {
                    max = 100;
                }
                if (current < 0) {
                    current = 0;
                }
                if (current > max) {
                    current = max;
                }
                var percent = toInt(item.quality_percent, 0);
                if (percent < 0 || percent > 100) {
                    percent = Math.round((current / max) * 100);
                }
                if (percent < 0) {
                    percent = 0;
                }
                if (percent > 100) {
                    percent = 100;
                }

                var badgeClass = 'text-bg-success';
                if (percent <= 25) {
                    badgeClass = 'text-bg-danger';
                } else if (percent <= 50) {
                    badgeClass = 'text-bg-warning';
                } else if (percent <= 75) {
                    badgeClass = 'text-bg-info';
                }

                return {
                    label: 'Qualita ' + current + '/' + max + ' (' + percent + '%)',
                    badgeClass: badgeClass,
                    canMaintain: current < max,
                    needsMaintenance: toInt(item.needs_maintenance, 0) === 1 || current <= 0
                };
            },
            reloadAmmo: function (instanceId, itemName) {
                var self = this;
                this.callInventory('reloadItem', {
                    character_item_instance_id: instanceId
                }, null, function (response) {
                    var reloaded = toInt(response && response.ammo ? response.ammo.reloaded : 0, 0);
                    var body = reloaded > 0
                        ? ('Ricarica completata: +' + reloaded + ' colpi (' + itemName + ').')
                        : ('Ricarica completata (' + itemName + ').');
                    Toast.show({ body: body, type: 'success' });
                    self.reload();
                    if (globalWindow.Bag && typeof globalWindow.Bag.sync === 'function') {
                        globalWindow.Bag.sync();
                    }
                }, function (error) {
                    showInventoryError(error, 'Errore durante la ricarica.');
                });
            },
            maintainItem: function (instanceId, itemName) {
                var self = this;
                this.callInventory('maintainItem', {
                    character_item_instance_id: instanceId
                }, null, function (response) {
                    var qualityAfter = response && response.quality && response.quality.after ? response.quality.after : null;
                    var label = qualityAfter
                        ? ('Manutenzione completata: ' + qualityAfter.quality_current + '/' + qualityAfter.quality_max + ' (' + itemName + ').')
                        : ('Manutenzione completata (' + itemName + ').');
                    Toast.show({ body: label, type: 'success' });
                    self.reload();
                    if (globalWindow.Bag && typeof globalWindow.Bag.sync === 'function') {
                        globalWindow.Bag.sync();
                    }
                }, function (error) {
                    showInventoryError(error, 'Errore durante la manutenzione.');
                });
            },
            showSwapDialog: function (fromSlot, itemName, targets) {
                var self = this;
                var slot = (fromSlot || '').toString().trim();
                if (!slot) {
                    Toast.show({ body: 'Slot non valido.', type: 'error' });
                    return;
                }
                if (!targets || !targets.length) {
                    Toast.show({ body: 'Nessuno slot compatibile disponibile per lo scambio.', type: 'warning' });
                    return;
                }

                var options = '';
                for (var i = 0; i < targets.length; i++) {
                    var t = targets[i];
                    options += '<option value="' + escapeHtml(t) + '">' + escapeHtml(this.getEquipSlotLabel(t)) + '</option>';
                }

                var body = '<div class="text-start text-body">';
                body += '<p>Sposta <b>' + escapeHtml(itemName || 'Oggetto') + '</b> in un altro slot.</p>';
                body += '<label class="form-label">Slot destinazione</label>';
                body += '<div><select class="form-select" name="swap-slot-target"><option value="">Seleziona...</option>' + options + '</select></div>';
                body += '</div>';

                var dialog = Dialog('default', {
                    title: 'Sposta equip',
                    body: body
                }, function () {
                    var confirmModal = getGeneralConfirmModal();
                    if (!confirmModal) {
                        Toast.show({ body: 'Dialog di conferma non disponibile.', type: 'error' });
                        return;
                    }
                    var targetSlot = (confirmModal.find('[name="swap-slot-target"]').val() || '').toString().trim();
                    if (!targetSlot) {
                        Toast.show({ body: 'Seleziona uno slot destinazione.', type: 'error' });
                        return;
                    }
                    hideGeneralConfirmDialog();
                    self.swapSlots(slot, targetSlot);
                });

                dialog.show();
            },
            swapSlots: function (fromSlot, toSlot) {
                var self = this;
                this.callInventory('swap', {
                    from_slot: fromSlot,
                    to_slot: toSlot
                }, null, function () {
                    Toast.show({ body: 'Equipaggiamento aggiornato.', type: 'success' });
                    self.reload();
                    if (globalWindow.Bag && typeof globalWindow.Bag.sync === 'function') {
                        globalWindow.Bag.sync();
                    }
                }, function (error) {
                    showInventoryError(error, 'Errore durante scambio slot.');
                });
            },
            buildGridSlots: function () {
                var block = $('#equips-slots').empty();
                if (!block.length) {
                    return;
                }

                var map = {};
                for (var i = 0; i < this.items.length; i++) {
                    var equipped = this.items[i];
                    if (equipped && equipped.slot) {
                        map[equipped.slot] = equipped;
                    }
                }

                for (var s = 0; s < this.slots.length; s++) {
                    var slot = this.slots[s];
                    var key = slot.key;
                    var item = map[key] || null;
                    var label = this.slotLabel(key);
                    var image = (item && item.image) ? item.image : '/assets/imgs/defaults-images/default-location.png';
                    var name = (item && item.name) ? item.name : 'Vuoto';
                    var swapTargets = this.getSwapTargets(key, item);
                    var swapTargetsAttr = swapTargets.join(',');
                    var hasSwapTargets = swapTargets.length > 0;
                    var narrativeBadges = item ? buildItemNarrativeBadges(item) : '';
                    var narrativeBlock = '';
                    var ammoInfo = item ? this.getAmmoInfo(item) : null;
                    var qualityInfo = item ? this.getQualityInfo(item) : null;
                    if (narrativeBadges !== '') {
                        narrativeBlock = '<div class="d-flex flex-wrap gap-1 mt-1">' + narrativeBadges + '</div>';
                    }
                    if (ammoInfo && ammoInfo.label) {
                        narrativeBlock += '<div class="d-flex flex-wrap gap-1 mt-1"><span class="badge text-bg-dark">' + escapeHtml(ammoInfo.label) + '</span></div>';
                    }
                    if (qualityInfo && qualityInfo.label) {
                        narrativeBlock += '<div class="d-flex flex-wrap gap-1 mt-1"><span class="badge ' + qualityInfo.badgeClass + '">' + escapeHtml(qualityInfo.label) + '</span></div>';
                    }
                    var body = ''
                        + '<div class="card h-100 border-secondary">'
                        + '  <div class="card-body d-flex gap-3 align-items-center">'
                        + '    <img class="rounded" width="48" height="48" src="' + image + '" alt="">'
                        + '    <div class="flex-grow-1">'
                        + '      <div class="fw-semibold">' + escapeHtml(label) + '</div>'
                        + '      <div class="text-muted small">' + escapeHtml(name) + '</div>'
                        +        narrativeBlock
                        + '    </div>';
                    if (item && item.character_item_instance_id) {
                        body += '    <div>';
                        if (hasSwapTargets) {
                            body += '      <button type="button" class="btn btn-sm btn-outline-info me-1" data-action="swap-slot" data-slot="' + key + '" data-item-name="' + escapeHtml(name) + '" data-allowed-slots="' + swapTargetsAttr + '">Sposta</button>';
                        }
                        if (ammoInfo && ammoInfo.canReload) {
                            body += '      <button type="button" class="btn btn-sm btn-outline-primary me-1" data-action="reload-ammo" data-instance-id="' + item.character_item_instance_id + '" data-item-name="' + escapeHtml(name) + '">Ricarica</button>';
                        }
                        if (qualityInfo && qualityInfo.canMaintain) {
                            body += '      <button type="button" class="btn btn-sm btn-outline-primary me-1" data-action="maintain-item" data-instance-id="' + item.character_item_instance_id + '" data-item-name="' + escapeHtml(name) + '">Manut.</button>';
                        }
                        body += '      <button type="button" class="btn btn-sm btn-warning" data-action="unequip" data-instance-id="' + item.character_item_instance_id + '">Rimuovi</button>'
                            + '    </div>';
                    }
                    body += '  </div>'
                        + '</div>';

                    var col = $('<div class="col-12 col-md-6 col-lg-4"></div>');
                    col.html(body);
                    col.appendTo(block);
                }

                this.bindActions('#equips-slots');
            },
            buildSlotMap: function () {
                var slots = $('[data-equip-slot]');
                if (!slots.length) {
                    this.buildSlotSkeleton();
                    slots = $('[data-equip-slot]');
                }
                if (!slots.length) {
                    return;
                }

                var map = {};
                for (var i = 0; i < this.items.length; i++) {
                    var item = this.items[i];
                    if (item && item.slot) {
                        map[item.slot] = item;
                    }
                }

                var self = this;
                slots.each(function () {
                    var el = $(this);
                    var slot = (el.data('equip-slot') || '').toString();
                    var item = map[slot] || null;
                    var label = self.slotLabel(slot);
                    var image = (item && item.image) ? item.image : '/assets/imgs/defaults-images/default-location.png';
                    var name = (item && item.name) ? item.name : 'Vuoto';
                    var swapTargets = self.getSwapTargets(slot, item);
                    var swapTargetsAttr = swapTargets.join(',');
                    var hasSwapTargets = swapTargets.length > 0;
                    var narrativeBadges = item ? buildItemNarrativeBadges(item) : '';
                    var ammoInfo = item ? self.getAmmoInfo(item) : null;
                    var qualityInfo = item ? self.getQualityInfo(item) : null;
                    var narrativeRow = '';
                    if (narrativeBadges !== '') {
                        narrativeRow = '<div class="equip-slot-narrative mt-1 d-flex flex-wrap gap-1">' + narrativeBadges + '</div>';
                    }
                    if (ammoInfo && ammoInfo.label) {
                        narrativeRow += '<div class="equip-slot-narrative mt-1 d-flex flex-wrap gap-1"><span class="badge text-bg-dark">' + escapeHtml(ammoInfo.label) + '</span></div>';
                    }
                    if (qualityInfo && qualityInfo.label) {
                        narrativeRow += '<div class="equip-slot-narrative mt-1 d-flex flex-wrap gap-1"><span class="badge ' + qualityInfo.badgeClass + '">' + escapeHtml(qualityInfo.label) + '</span></div>';
                    }
                    var html = ''
                        + '<div class="equip-slot-box' + ((item && item.character_item_instance_id) ? ' is-filled' : '') + '">'
                        + '  <div class="equip-slot-label">' + escapeHtml(label) + '</div>';
                    if (item && item.character_item_instance_id) {
                        html += '  <img class="equip-slot-image" src="' + image + '" alt="">'
                            + '  <div class="equip-slot-name">' + escapeHtml(name) + narrativeRow + '</div>';
                        if (hasSwapTargets) {
                            html += '  <button type="button" class="btn btn-sm btn-outline-info equip-slot-swap me-2" data-action="swap-slot" data-slot="' + slot + '" data-item-name="' + escapeHtml(name) + '" data-allowed-slots="' + swapTargetsAttr + '">Sposta</button>';
                        }
                        if (ammoInfo && ammoInfo.canReload) {
                            html += '  <button type="button" class="btn btn-sm btn-outline-primary equip-slot-swap me-2" data-action="reload-ammo" data-instance-id="' + item.character_item_instance_id + '" data-item-name="' + escapeHtml(name) + '">Ricarica</button>';
                        }
                        if (qualityInfo && qualityInfo.canMaintain) {
                            html += '  <button type="button" class="btn btn-sm btn-outline-primary equip-slot-swap me-2" data-action="maintain-item" data-instance-id="' + item.character_item_instance_id + '" data-item-name="' + escapeHtml(name) + '">Manut.</button>';
                        }
                        html += '  <button type="button" class="btn btn-sm btn-warning equip-slot-remove" data-action="unequip" data-instance-id="' + item.character_item_instance_id + '">Rimuovi</button>';
                    } else {
                        html += '  <div class="equip-slot-empty">Vuoto</div>';
                    }
                    html += '</div>';
                    el.html(html);
                });

                this.bindActions('#equips-page');
            },
            bindActions: function (scopeSelector) {
                var self = this;
                var block = scopeSelector ? $(scopeSelector) : $('#equips-slots');
                if (!block.length) {
                    block = $('#equips-page');
                }
                block.off('click', '[data-action="unequip"]');
                block.off('click', '[data-action="swap-slot"]');
                block.off('click', '[data-action="reload-ammo"]');
                block.off('click', '[data-action="maintain-item"]');
                block.on('click', '[data-action="unequip"]', function (e) {
                    e.preventDefault();
                    var instanceId = parseInt($(this).data('instance-id'), 10);
                    if (!instanceId) {
                        Toast.show({ body: 'Oggetto non valido.', type: 'error' });
                        return;
                    }
                    self.callInventory('unequip', {
                        character_item_instance_id: instanceId
                    }, null, function () {
                        Toast.show({ body: 'Oggetto rimosso.', type: 'success' });
                        self.reload();
                        if (globalWindow.Bag && typeof globalWindow.Bag.sync === 'function') {
                            globalWindow.Bag.sync();
                        }
                    }, function (error) {
                        showInventoryError(error, 'Errore durante rimozione.');
                    });
                });

                block.on('click', '[data-action="swap-slot"]', function (e) {
                    e.preventDefault();
                    var btn = $(this);
                    var fromSlot = (btn.data('slot') || '').toString().trim();
                    if (!fromSlot) {
                        Toast.show({ body: 'Slot non valido.', type: 'error' });
                        return;
                    }
                    var targets = self.parseCsv(btn.data('allowed-slots'));
                    var itemName = (btn.data('item-name') || 'Oggetto').toString();
                    self.showSwapDialog(fromSlot, itemName, targets);
                });

                block.on('click', '[data-action="reload-ammo"]', function (e) {
                    e.preventDefault();
                    var btn = $(this);
                    var instanceId = parseInt(btn.data('instance-id'), 10);
                    var itemName = (btn.data('item-name') || 'Oggetto').toString();
                    if (!instanceId) {
                        Toast.show({ body: 'Oggetto non valido.', type: 'error' });
                        return;
                    }
                    self.reloadAmmo(instanceId, itemName);
                });

                block.on('click', '[data-action="maintain-item"]', function (e) {
                    e.preventDefault();
                    var btn = $(this);
                    var instanceId = parseInt(btn.data('instance-id'), 10);
                    var itemName = (btn.data('item-name') || 'Oggetto').toString();
                    if (!instanceId) {
                        Toast.show({ body: 'Oggetto non valido.', type: 'error' });
                        return;
                    }
                    self.maintainItem(instanceId, itemName);
                });
            }
        };

        let equips = Object.assign({}, page, extension);
        return equips.init();
    }

globalWindow.GameBagPage = GameBagPage;
globalWindow.GameEquipsPage = GameEquipsPage;
export { GameBagPage as GameBagPage };
export { GameEquipsPage as GameEquipsPage };
export default GameEquipsPage;

