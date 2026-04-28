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


function normalizeMapsError(error, fallback) {
    if (globalWindow.GameFeatureError && typeof globalWindow.GameFeatureError.normalize === 'function') {
        return globalWindow.GameFeatureError.normalize(error, fallback || 'Operazione non riuscita.');
    }
    if (typeof error === 'string' && error.trim() !== '') {
        return error.trim();
    }
    if (error && typeof error.message === 'string' && error.message.trim() !== '') {
        return error.message.trim();
    }
    if (error && typeof error.error === 'string' && error.error.trim() !== '') {
        return error.error.trim();
    }
    return fallback || 'Operazione non riuscita.';
}

function callMapsModule(method, payload, onSuccess, onError) {
    if (typeof resolveModule !== 'function') {
        if (typeof onError === 'function') {
            onError(new Error('Maps module resolver not available: ' + method));
        }
        return false;
    }

    var mod = resolveModule('game.maps');
    if (!mod || typeof mod[method] !== 'function') {
        if (typeof onError === 'function') {
            onError(new Error('Maps module method not available: ' + method));
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

function GameMapsPage(extension) {            
        let page = {
            dataset: {},

            init: function () {
                this.getMaps();

                return this;
            },
            getMaps: function() {
                var self = this;
                var payload = {
                    query: {
                        parent_map_id: null
                    },
                    orderBy: 'position|ASC',
                    cache: false,
                    cache_ttl: 0
                };
                callMapsModule('listMaps', payload, function (response) {
                    if (null == response) {
                        return;
                    }

                    self.dataset = response.dataset;
                    self.build();
                }, function (error) {
                    Toast.show({
                        body: normalizeMapsError(error, 'Errore durante caricamento mappe'),
                        type: 'error'
                    });
                });
            },
            build: function () {
                let block = $('#maps-page-body').empty();
                if (!this.dataset || this.dataset.length === 0) {
                    block.append('<div class="col-12"><div class="alert alert-info">Nessuna mappa disponibile.</div></div>');
                    return this;
                }

                let byPosition = {};
                for (var i in this.dataset) {
                    let mapRow = this.dataset[i];
                    let posKey = (mapRow.position !== undefined && mapRow.position !== null) ? mapRow.position : 0;
                    if (!byPosition[posKey]) {
                        byPosition[posKey] = [];
                    }
                    byPosition[posKey].push(mapRow);
                }

                for (var i in this.dataset) {
                    let row = this.dataset[i];
                    let template = $($('template[name="template_maps_card"]').html());

                    let image = row.image || row.icon || '/assets/imgs/defaults-images/default-map.png';
                    template.find('.map-image').attr('src', image).attr('alt', row.name || 'Mappa');

                    if (row.icon) {
                        template.find('.map-icon').attr('src', row.icon).removeClass('d-none');
                    }

                    template.find('.map-title').text(row.name || 'Mappa');
                    template.find('.map-description').html(row.description || '');

                    var statusLabels = {
                        active: 'Attiva',
                        inactive: 'Inattiva',
                        maintenance: 'In manutenzione',
                        offline: 'Offline',
                        hidden: 'Nascosta'
                    };
                    var statusVal = String(row.status || '').toLowerCase().trim();
                    if (statusVal && statusVal !== 'active') {
                        var statusText = statusLabels[statusVal] || statusVal;
                        template.find('.map-status').text(statusText);
                    } else {
                        template.find('.map-status').remove();
                    }

                    if (parseInt(row.mobile, 10) === 1) {
                        template.find('.map-mobile-badge').removeClass('d-none');
                    } else {
                        template.find('.map-mobile-badge').remove();
                    }

                    let posKey = (row.position !== undefined && row.position !== null) ? row.position : 0;
                    let neighbors = (byPosition[posKey] || []).filter(function (m) {
                        return String(m.id) !== String(row.id);
                    });
                    if (neighbors.length > 0) {
                        let links = neighbors.map(function (m) {
                            return '<a href="/game/maps/' + m.id + '">' + m.name + '</a>';
                        }).join(', ');
                        template.find('.map-neighbors').html('Vicino a: ' + links);
                    } else {
                        template.find('.map-neighbors').remove();
                    }

                    template.find('.map-link').attr('href', '/game/maps/' + row.id);
                    template.appendTo(block);
                }

                return this;
            }
        };
        let maps = Object.assign({}, page, extension);
        return maps.init();
}

globalWindow.GameMapsPage = GameMapsPage;
export { GameMapsPage as GameMapsPage };
export default GameMapsPage;