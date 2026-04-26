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

function normalizeDropsError(error, fallback) {
    if (globalWindow.GameFeatureError && typeof globalWindow.GameFeatureError.normalize === 'function') {
        return globalWindow.GameFeatureError.normalize(error, fallback || 'Operazione non riuscita.');
    }
    if (typeof error === 'string' && error.trim() !== '') {
        return error.trim();
    }
    if (error && typeof error.message === 'string' && error.message.trim() !== '') {
        return error.message.trim();
    }
    return fallback || 'Operazione non riuscita.';
}


function GameLocationDropsPage(extension) {
        let page = {
            dataset: [],
            dropsModule: null,
            init: function () {
                if (!$('#location-drops-list').length) {
                    return this;
                }
                this.load();
                return this;
            },
            getDropsModule: function () {
                if (this.dropsModule) {
                    return this.dropsModule;
                }
                if (typeof resolveModule !== 'function') {
                    return null;
                }

                this.dropsModule = resolveModule('game.location.drops');
                return this.dropsModule;
            },
            callDrops: function (method, payload, onSuccess, onError) {
                var mod = this.getDropsModule();
                var fn = String(method || '').trim();
                if (!mod || fn === '' || typeof mod[fn] !== 'function') {
                    if (typeof onError === 'function') {
                        onError(new Error('Drops module not available: ' + fn));
                    }
                    return false;
                }

                mod[fn](payload || {}).then(function (response) {
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
            reload: function () {
                this.load();
            },
            load: function () {
                var self = this;
                var onSuccess = function (response) {
                    self.dataset = (response && response.dataset) ? response.dataset : [];
                    self.build();
                };
                this.callDrops('list', null, onSuccess, function () {
                    self.dataset = [];
                    self.build();
                });
            },
            build: function () {
                let list = $('#location-drops-list');
                if (!list.length) {
                    return;
                }
                list.empty();
                this.updateBadge();
                if (!this.dataset || this.dataset.length === 0) {
                    list.append('<div class="text-muted text-center small py-2">Nessun oggetto a terra.</div>');
                    return;
                }

                for (var i in this.dataset) {
                    let row = this.dataset[i];
                    let image = (row.item_image && row.item_image !== '') ? row.item_image : '/assets/imgs/defaults-images/default-location.png';
                    let name = row.item_name || 'Oggetto';
                    let qty = parseInt(row.quantity, 10);
                    if (isNaN(qty) || qty < 1) {
                        qty = 1;
                    }
                    let qtyBadge = (parseInt(row.is_stackable, 10) === 1 && qty > 1) ? ('<span class="badge text-bg-secondary">x' + qty + '</span>') : '';

                    let item = ''
                        + '<div class="list-group-item d-flex align-items-center gap-2">'
                        + '  <img class="rounded" width="36" height="36" src="' + image + '" alt="">'
                        + '  <div class="flex-grow-1">'
                        + '    <div class="d-flex align-items-center gap-2">'
                        + '      <span>' + name + '</span>'
                        +        qtyBadge
                        + '    </div>'
                        + '  </div>'
                        + '  <button type="button" class="btn btn-sm btn-outline-light" data-action="pick-drop" data-drop-id="' + row.id + '">Raccogli</button>'
                        + '</div>';
                    list.append(item);
                }

                this.bindActions();
            },
            updateBadge: function () {
                var badge = $('#location-drops-count-badge');
                if (!badge.length) {
                    return;
                }
                var total = 0;
                if (this.dataset && this.dataset.length) {
                    for (var i = 0; i < this.dataset.length; i++) {
                        var qty = parseInt(this.dataset[i].quantity, 10);
                        if (isNaN(qty) || qty < 1) {
                            qty = 1;
                        }
                        total += qty;
                    }
                }
                var show = total > 0;
                var label = (total > 99) ? '99+' : String(total);
                badge.text(label);
                badge.toggleClass('d-none', !show);
            },
            bindActions: function () {
                var self = this;
                let list = $('#location-drops-list');
                if (!list.length) {
                    return;
                }
                list.off('click', '[data-action="pick-drop"]');
                list.on('click', '[data-action="pick-drop"]', function (e) {
                    e.preventDefault();
                    let btn = $(this);
                    let dropId = parseInt(btn.data('drop-id'), 10);
                    if (!dropId) {
                        Toast.show({ body: 'Oggetto non valido.', type: 'error' });
                        return;
                    }
                    var payload = { drop_id: dropId };
                    var onSuccess = function () {
                        Toast.show({ body: 'Oggetto raccolto.', type: 'success' });
                        self.reload();
                        if (globalWindow.LocationSidebar && typeof globalWindow.LocationSidebar.reloadInventory === 'function') {
                            globalWindow.LocationSidebar.reloadInventory();
                        }
                    };
                    var onError = function (error) {
                        Toast.show({ body: normalizeDropsError(error, 'Errore durante la raccolta.'), type: 'error' });
                    };

                    self.callDrops('pick', payload, onSuccess, onError);
                });
            }
        };

        let drops = Object.assign({}, page, extension);
        return drops.init();
}

globalWindow.GameLocationDropsPage = GameLocationDropsPage;
export { GameLocationDropsPage as GameLocationDropsPage };
export default GameLocationDropsPage;

