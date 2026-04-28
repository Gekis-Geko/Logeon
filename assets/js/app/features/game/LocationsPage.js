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


function normalizeLocationsError(error, fallback) {
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

function GameLocationsPage(id, extension) {
    let page = {
        map_id: null,
        mapData: null,
        locations: [],
        childMaps: [],
        init: function () {
            if (null == id) {
                Dialog('warning', { title: 'Selezione mappa', body: '<p>Nessuna mappa selezionata.</p>' }).show();
                return;
            }

            this.map_id = id;
            this.get();

            return this;
        },
        requestModule: function (method, payload) {
            return new Promise(function (resolve, reject) {
                if (!callMapsModule(method, payload, resolve, reject)) {
                    reject(new Error('Maps module method not available: ' + method));
                }
            });
        },
        get: function () {
            var self = this;
            Promise.all([
                this.requestModule('listMaps', {
                    query: { id: self.map_id },
                    results: 1,
                    page: 1,
                    orderBy: 'position|ASC',
                    cache: false,
                    cache_ttl: 0
                }),
                this.requestModule('listMaps', {
                    query: { parent_map_id: self.map_id },
                    orderBy: 'position|ASC',
                    cache: false,
                    cache_ttl: 0
                }),
                this.requestModule('listLocations', {
                    query: { map_id: self.map_id },
                    orderBy: 'name|ASC',
                    cache: false,
                    cache_ttl: 0
                })
            ]).then(function (responses) {
                var mapResponse = responses[0] || {};
                var childMapsResponse = responses[1] || {};
                var locationsResponse = responses[2] || {};

                self.mapData = (mapResponse.dataset && mapResponse.dataset[0]) ? mapResponse.dataset[0] : null;
                self.childMaps = Array.isArray(childMapsResponse.dataset) ? childMapsResponse.dataset : [];
                self.locations = Array.isArray(locationsResponse.dataset) ? locationsResponse.dataset : [];
                self.build();
            }).catch(function (error) {
                Toast.show({
                    body: normalizeLocationsError(error, 'Errore durante caricamento mappa'),
                    type: 'error'
                });
            });
        },
        normalizePercent: function (value) {
            var number = parseFloat(value);
            if (isNaN(number)) {
                return null;
            }
            if (number < 0) {
                number = 0;
            }
            if (number > 100) {
                number = 100;
            }
            return number;
        },
        escapeHtml: function (value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        },
        stripHtml: function (value) {
            return String(value || '')
                .replace(/<[^>]*>/g, ' ')
                .replace(/\s+/g, ' ')
                .trim();
        },
        truncateText: function (value, maxLength) {
            var text = String(value || '');
            var limit = parseInt(maxLength, 10) || 0;
            if (limit <= 0 || text.length <= limit) {
                return text;
            }
            return text.substring(0, Math.max(0, limit - 3)).trim() + '...';
        },
        resolveMapPreviewImage: function (row) {
            if (row && typeof row.image === 'string' && row.image.trim() !== '') {
                return row.image.trim();
            }
            if (row && typeof row.icon === 'string' && row.icon.trim() !== '') {
                return row.icon.trim();
            }
            return '/assets/imgs/defaults-images/default-map.png';
        },
        resolveMapStatusText: function (value) {
            var statusLabels = {
                active: 'Attiva',
                inactive: 'Inattiva',
                maintenance: 'In manutenzione',
                offline: 'Offline',
                hidden: 'Nascosta'
            };
            var statusVal = String(value || '').toLowerCase().trim();
            if (!statusVal || statusVal === 'active') {
                return '';
            }
            return statusLabels[statusVal] || statusVal;
        },
        buildChildMaps: function () {
            var section = $('#map-child-maps-section');
            var block = $('#map-child-maps').empty();
            var rows = Array.isArray(this.childMaps) ? this.childMaps : [];

            if (rows.length === 0) {
                section.addClass('d-none');
                return this;
            }

            for (var i = 0; i < rows.length; i++) {
                var row = rows[i];
                var template = $($('template[name="template_child_map_card"]').html());
                var image = this.resolveMapPreviewImage(row);
                var statusText = this.resolveMapStatusText(row.status);

                template.find('.map-child-image').attr('src', image).attr('alt', row.name || 'Mappa');
                if (row.icon) {
                    template.find('.map-child-icon').attr('src', row.icon).removeClass('d-none');
                } else {
                    template.find('.map-child-icon').remove();
                }
                template.find('.map-child-title').text(row.name || 'Mappa');
                template.find('.map-child-description').html(row.description || '');
                if (statusText !== '') {
                    template.find('.map-child-status').text(statusText);
                } else {
                    template.find('.map-child-status').remove();
                }
                if (parseInt(row.mobile, 10) === 1) {
                    template.find('.map-child-mobile-badge').removeClass('d-none');
                } else {
                    template.find('.map-child-mobile-badge').remove();
                }
                template.find('.map-child-link').attr('href', '/game/maps/' + row.id);
                template.appendTo(block);
            }

            section.removeClass('d-none');
            return this;
        },
        isLocationAccessible: function (row) {
            if (!row) {
                return false;
            }
            return !(row.access === false || row.access === 0 || String(row.access) === '0');
        },
        resolveLocationCapacityLabel: function (row) {
            if (!row) {
                return '';
            }

            var maxGuests = parseInt(row.max_guests, 10);
            var guestsCount = parseInt(row.guests_count, 10);
            if (!isNaN(maxGuests) && maxGuests > 0) {
                return 'Presenti: ' + (isNaN(guestsCount) ? 0 : guestsCount) + '/' + maxGuests;
            }
            if (!isNaN(guestsCount) && guestsCount > 0) {
                return 'Presenti: ' + guestsCount;
            }

            return '';
        },
        resolveLocationCompactCount: function (row) {
            if (!row) {
                return '';
            }

            var maxGuests = parseInt(row.max_guests, 10);
            var guestsCount = parseInt(row.guests_count, 10);
            if (!isNaN(maxGuests) && maxGuests > 0) {
                return (isNaN(guestsCount) ? 0 : guestsCount) + '/' + maxGuests;
            }
            if (!isNaN(guestsCount) && guestsCount > 0) {
                return String(guestsCount);
            }

            return '';
        },
        resolveVisualNodeMeta: function (row) {
            var accessible = this.isLocationAccessible(row);
            var compactCount = this.resolveLocationCompactCount(row);
            var guestsCount = parseInt(row && row.guests_count, 10);
            var maxGuests = parseInt(row && row.max_guests, 10);
            var isFull = !!(row && row.is_full);
            var statusClass = 'is-idle';
            var iconClass = 'bi-geo-alt-fill';

            if (!accessible) {
                statusClass = 'is-locked';
                iconClass = 'bi-lock-fill';
            } else if (isFull || (!isNaN(maxGuests) && maxGuests > 0 && !isNaN(guestsCount) && guestsCount >= maxGuests)) {
                statusClass = 'is-full';
                iconClass = 'bi-people-fill';
            } else if (!isNaN(guestsCount) && guestsCount > 0) {
                statusClass = 'is-active';
                iconClass = 'bi-chat-dots-fill';
            }

            return {
                statusClass: statusClass,
                iconClass: iconClass,
                compactCount: compactCount
            };
        },
        buildVisualPinPopover: function (row) {
            var title = row && row.name ? String(row.name) : 'Location';
            var description = this.truncateText(this.stripHtml(row && row.short_description ? row.short_description : ''), 180);
            var capacityLabel = this.resolveLocationCapacityLabel(row);
            var canAccess = this.isLocationAccessible(row);
            var mapId = row && row.map_id ? String(row.map_id) : '';
            var locationId = row && row.id ? String(row.id) : '';
            var reason = row && row.access_reason ? String(row.access_reason) : 'Accesso non consentito';
            var content = [];

            if (description !== '') {
                content.push('<p class="small mb-2">' + this.escapeHtml(description) + '</p>');
            } else {
                content.push('<p class="small text-muted mb-2">Nessuna descrizione breve disponibile.</p>');
            }

            if (capacityLabel !== '') {
                content.push('<div class="small text-muted mb-2">' + this.escapeHtml(capacityLabel) + '</div>');
            }

            if (canAccess) {
                content.push(
                    '<a class="btn btn-primary btn-sm w-100" href="/game/maps/' + this.escapeHtml(mapId) + '/location/' + this.escapeHtml(locationId) + '">' +
                    'Entra nel luogo <i class="bi bi-arrow-right-short"></i>' +
                    '</a>'
                );
            } else {
                content.push('<div class="small text-warning mb-2">' + this.escapeHtml(reason) + '</div>');
                content.push('<button type="button" class="btn btn-secondary btn-sm w-100" disabled>Non accessibile</button>');
            }

            return {
                title: this.escapeHtml(title),
                content: content.join('')
            };
        },
        disposeVisualMapPopovers: function () {
            var root = document.getElementById('map-visual-pins');
            if (!root || typeof globalWindow.bootstrap === 'undefined' || !globalWindow.bootstrap.Popover) {
                return;
            }

            var pins = root.querySelectorAll('[data-lf-popover="location-pin"]');
            pins.forEach(function (pin) {
                var instance = globalWindow.bootstrap.Popover.getInstance(pin);
                if (instance) {
                    instance.dispose();
                }
                $(pin).off('.locations_popover');
            });
        },
        initVisualMapPopovers: function () {
            var root = document.getElementById('map-visual-pins');
            if (!root || typeof globalWindow.bootstrap === 'undefined' || !globalWindow.bootstrap.Popover) {
                return;
            }

            var pins = Array.prototype.slice.call(root.querySelectorAll('[data-lf-popover="location-pin"]'));
            if (pins.length === 0) {
                return;
            }

            pins.forEach(function (pin) {
                var current = globalWindow.bootstrap.Popover.getInstance(pin);
                if (current) {
                    current.dispose();
                }

                new globalWindow.bootstrap.Popover(pin, {
                    trigger: 'click',
                    html: true,
                    container: 'body',
                    boundary: 'viewport',
                    placement: 'auto',
                    sanitize: true
                });
            });

            pins.forEach(function (pin) {
                $(pin).off('show.bs.popover.locations_popover').on('show.bs.popover.locations_popover', function () {
                    pins.forEach(function (candidate) {
                        if (candidate === pin) {
                            return;
                        }
                        var instance = globalWindow.bootstrap.Popover.getInstance(candidate);
                        if (instance) {
                            instance.hide();
                        }
                    });
                });
            });

            $(document).off('click.locations_map_popover').on('click.locations_map_popover', function (event) {
                if ($(event.target).closest('[data-lf-popover="location-pin"], .popover').length > 0) {
                    return;
                }
                pins.forEach(function (pin) {
                    var instance = globalWindow.bootstrap.Popover.getInstance(pin);
                    if (instance) {
                        instance.hide();
                    }
                });
            });
        },
        resolveVisualPinPosition: function (row, index, total) {
            if (!row) {
                return null;
            }

            var x = this.normalizePercent(row.map_x);
            var y = this.normalizePercent(row.map_y);
            if (x !== null && y !== null) {
                return { x: x, y: y, generated: false };
            }

            var count = Math.max(1, parseInt(total, 10) || 1);
            var columns = Math.max(2, Math.min(6, Math.ceil(Math.sqrt(count))));
            var rows = Math.max(1, Math.ceil(count / columns));
            var col = index % columns;
            var line = Math.floor(index / columns);

            return {
                x: ((col + 1) / (columns + 1)) * 100,
                y: ((line + 1) / (rows + 1)) * 100,
                generated: true
            };
        },
        mapHasVisualImage: function (mapImage) {
            return typeof mapImage === 'string' && mapImage.trim() !== '';
        },
        resolveMapRenderMode: function (renderMode, mapImage) {
            var mode = String(renderMode || '').trim().toLowerCase();
            if (mode === 'visual') {
                return 'visual';
            }
            if (mode === 'grid') {
                return 'grid';
            }

            return this.mapHasVisualImage(mapImage) ? 'visual' : 'grid';
        },
        buildVisualMap: function (mapName, mapImage) {
            let visualBlock = $('#map-visual');
            let visualPins = $('#map-visual-pins');
            let visualImage = $('#map-visual-image');
            this.disposeVisualMapPopovers();
            visualPins.empty();
            visualBlock.removeClass('d-none');
            visualImage.attr('src', mapImage).attr('alt', mapName || 'Mappa');

            for (var i = 0; i < this.locations.length; i++) {
                let row = this.locations[i];
                let position = this.resolveVisualPinPosition(row, i, this.locations.length);
                if (!position) {
                    continue;
                }

                let nodeMeta = this.resolveVisualNodeMeta(row);
                let pin = $('<a class="lf-map-node position-absolute"></a>');
                pin.css({
                    left: position.x + '%',
                    top: position.y + '%',
                    transform: 'translate(-50%, -100%)'
                });
                pin.append(
                    '<span class="lf-map-node__dot ' + this.escapeHtml(nodeMeta.statusClass) + '">' +
                    '<i class="bi ' + this.escapeHtml(nodeMeta.iconClass) + '"></i>' +
                    '</span>' +
                    '<span class="lf-map-node__label">' +
                    '<span class="lf-map-node__name">' + this.escapeHtml(row.name || 'Location') + '</span>' +
                    (nodeMeta.compactCount !== '' ? '<span class="lf-map-node__meta">' + this.escapeHtml(nodeMeta.compactCount) + '</span>' : '') +
                    '</span>'
                );
                pin.attr('href', '#')
                    .attr('role', 'button')
                    .attr('data-lf-popover', 'location-pin')
                    .attr('data-bs-toggle', 'popover')
                    .attr('data-bs-trigger', 'click')
                    .on('click', function (e) { e.preventDefault(); });

                let popoverPayload = this.buildVisualPinPopover(row);
                pin.attr('data-bs-title', popoverPayload.title)
                    .attr('data-bs-content', popoverPayload.content);

                if (!this.isLocationAccessible(row)) {
                    pin.attr('aria-disabled', 'true');
                }

                if (position.generated) {
                    pin.addClass('lf-map-node--generated');
                }

                pin.appendTo(visualPins);
            }

            this.initVisualMapPopovers();
        },
        buildBlockMap: function () {
            let block = $('#locations-page-body').empty();
            let appended = 0;

            for (var i in this.locations) {
                let row = this.locations[i];
                let template = $($('template[name="template_locations_list"]').html());

                let image = row.image || '/assets/imgs/defaults-images/default-location.png';
                template.find('.card-img-top').attr('src', image);
                template.find('.card-title').addClass('flex items-center content-between').text(row.name);
                template.find('.card-text').html(row.short_description || '');
                template.find('.location-link').attr('href', '/game/maps/' + row.map_id + '/location/' + row.id);

                let capacityLabel = '';
                let maxGuests = parseInt(row.max_guests, 10);
                let guestsCount = parseInt(row.guests_count, 10);
                if (!isNaN(maxGuests) && maxGuests > 0) {
                    capacityLabel = 'Posti: ' + (isNaN(guestsCount) ? 0 : guestsCount) + '/' + maxGuests;
                } else if (!isNaN(guestsCount) && guestsCount > 0) {
                    capacityLabel = 'Presenti: ' + guestsCount;
                }
                let meta = template.find('.location-meta');
                if (meta.length) {
                    meta.find('.location-capacity').text(capacityLabel);
                    if (row.is_full) {
                        meta.find('.location-badge-full').removeClass('d-none');
                    } else {
                        meta.find('.location-badge-full').addClass('d-none');
                    }
                    if (capacityLabel === '' && !row.is_full) {
                        meta.addClass('d-none');
                    } else {
                        meta.removeClass('d-none');
                    }
                }

                if (row.access === false || row.access === 0) {
                    let reason = row.access_reason || 'Accesso non consentito';
                    template.find('.card').addClass('opacity-75');
                    template.find('.location-link')
                        .removeClass('btn-primary')
                        .addClass('btn-secondary disabled')
                        .attr('href', '#')
                        .attr('aria-disabled', 'true')
                        .attr('data-bs-toggle', 'tooltip')
                        .attr('data-bs-title', reason);

                    let lock = $('<i class="bi bi-lock-fill text-muted ms-2"></i>');
                    lock.attr('data-bs-toggle', 'tooltip');
                    lock.attr('data-bs-title', reason);
                    template.find('.card-title').append(lock);
                }

                template.appendTo(block);
                appended++;
            }

            if (appended === 0) {
                block.append('<div class="col-12"><div class="alert alert-info">Nessuna location disponibile.</div></div>');
            }
        },
        build: function () {
            let block = $('#locations-page-body').empty();
            let childSection = $('#map-child-maps-section').addClass('d-none');
            let locationsSection = $('#locations-page-section').addClass('d-none');
            let emptyState = $('#locations-page-empty').addClass('d-none').text('Questa mappa non contiene ancora luoghi o sottomappe.');
            let visualBlock = $('#map-visual');
            let visualPins = $('#map-visual-pins');
            this.disposeVisualMapPopovers();
            visualPins.empty();
            visualBlock.addClass('d-none');

            if (!this.mapData) {
                $('[name="map_name"]').html('Mappa');
                emptyState.removeClass('d-none').text('Mappa non trovata.');
                return this;
            }

            $('[name="map_name"]').html(this.mapData.name || 'Mappa');
            this.buildChildMaps();
            childSection = $('#map-child-maps-section');

            let mapName = this.mapData.name || '';
            let mapImage = (this.mapData.image || '').toString().trim();
            let mapRenderMode = this.resolveMapRenderMode(this.mapData.render_mode || '', mapImage);
            let useVisual = (mapRenderMode === 'visual' && this.mapHasVisualImage(mapImage) && this.locations.length > 0);

            if (this.locations.length > 0) {
                if (useVisual) {
                    this.buildVisualMap(mapName, mapImage);
                } else {
                    locationsSection.removeClass('d-none');
                    this.buildBlockMap();
                }
            }

            if (this.locations.length === 0 && childSection.hasClass('d-none')) {
                emptyState.removeClass('d-none');
            }

            initTooltips(document.getElementById('map-visual') || document);
            initTooltips(document.getElementById('locations-page-body') || document);
            initTooltips(document.getElementById('map-child-maps') || document);

            return this;
        },
        destroy: function () {
            this.disposeVisualMapPopovers();
            $(document).off('click.locations_map_popover');
            return this;
        },
        unmount: function () {
            return this.destroy();
        }
    };

    let locations = Object.assign({}, page, extension);
    return locations.init();
}

globalWindow.GameLocationsPage = GameLocationsPage;
export { GameLocationsPage as GameLocationsPage };
export default GameLocationsPage;