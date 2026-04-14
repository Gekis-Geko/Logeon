(function (window) {
    'use strict';

    function thumbUrl(src, w, h) {
        if (!src || src.indexOf('/assets/imgs/') !== 0) { return src || ''; }
        return '/thumb.php?src=' + encodeURIComponent(src) + '&w=' + w + '&h=' + (h || w);
    }

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


    function normalizeOnlinesError(error, fallback) {
        if (window.GameFeatureError && typeof window.GameFeatureError.normalize === 'function') {
            return window.GameFeatureError.normalize(error, fallback || 'Operazione non riuscita.');
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

    function callOnlinesModule(method, payload, onSuccess, onError) {
        if (typeof resolveModule !== 'function') {
            if (typeof onError === 'function') {
                onError(new Error('Onlines module resolver not available: ' + method));
            }
            return false;
        }

        var mod = resolveModule('game.onlines');
        if (!mod || typeof mod[method] !== 'function') {
            if (typeof onError === 'function') {
                onError(new Error('Onlines module method not available: ' + method));
            }
            return false;
        }

        mod[method](payload || null).then(function (response) {
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

    function openComposeModal(characterId, characterName) {
        var openModal = null;
        if (window.GameMessagesModal && typeof window.GameMessagesModal.openMessageModal === 'function') {
            openModal = window.GameMessagesModal.openMessageModal;
        } else if (typeof window.GameOpenMessageModal === 'function') {
            openModal = window.GameOpenMessageModal;
        }

        if (typeof openModal !== 'function') {
            return;
        }

        openModal(characterId, characterName);
    }

    function GameOnlinesPage(extension, mode) {
            let base = {
                dataset: null,
                _reusableItems: null,
                showAutoToast: false,
                syncTimeout: null,
                syncDelayMs: 60,
                mode: String(mode || 'smart').toLowerCase(),
                resyncTimer: null,
                pollKey: null,
                usesPollManager: false,
                _offConfigChanged: null,
                _configChangedEventName: 'config:changed',
                init: function () {
                    var self = this;
                    this.syncAutoToastConfig();
                    this.bindConfigEvents();

                    if (this.mode !== 'smart') {
                        this.startResync();
                        this.list();

                        $('#pageResync').off('click.onlines-resync').on('click.onlines-resync', function() {
                            Toast.show({
                                body: '<span class="spinner-border spinner-border-sm mx-4" role="status" aria-hidden="true"></span> Aggiorno i presenti ...',
                            });
                            self.list();
                            self.startResync();
                        });

                        return this;
                    }
                    
                    this.get();
                    this.startResync();

                    $('#offcanvasOnline').off('hidden.bs.offcanvas.onlines-resync').on('hidden.bs.offcanvas.onlines-resync', function () {
                        self.stopResync();
                    });

                    return this;
                },

                getEventBus: function () {
                    if (typeof window.AppEvents !== 'undefined' && window.AppEvents && typeof window.AppEvents.on === 'function') {
                        return window.AppEvents;
                    }
                    if (typeof window.EventBus === 'function') {
                        return window.EventBus();
                    }
                    return null;
                },

                syncAutoToastConfig: function () {
                    if (typeof ConfigStore === 'function') {
                        this.showAutoToast = ConfigStore().getBool('onlines_auto_toast', false);
                        return this.showAutoToast;
                    }
                    if (typeof window.APP_CONFIG !== 'undefined') {
                        this.showAutoToast = (parseInt(window.APP_CONFIG.onlines_auto_toast, 10) === 1);
                        return this.showAutoToast;
                    }
                    this.showAutoToast = false;
                    return this.showAutoToast;
                },

                isRelevantConfigChange: function (payload) {
                    if (!payload || typeof payload !== 'object') {
                        return false;
                    }

                    var direct = payload.change;
                    if (direct && (direct.key === 'onlines_auto_toast' || direct.path === 'onlines_auto_toast' || direct.path === 'feature.onlines.auto_toast')) {
                        return true;
                    }

                    if (!Array.isArray(payload.changes)) {
                        return false;
                    }

                    for (var i = 0; i < payload.changes.length; i++) {
                        var change = payload.changes[i];
                        if (!change || typeof change !== 'object') {
                            continue;
                        }
                        if (change.key === 'onlines_auto_toast' || change.path === 'onlines_auto_toast' || change.path === 'feature.onlines.auto_toast') {
                            return true;
                        }
                    }

                    return false;
                },

                bindConfigEvents: function () {
                    this.unbindConfigEvents();

                    var bus = this.getEventBus();
                    if (!bus || typeof bus.on !== 'function') {
                        return this;
                    }

                    var self = this;
                    this._offConfigChanged = bus.on(this._configChangedEventName, function (payload) {
                        if (!self.isRelevantConfigChange(payload)) {
                            return;
                        }
                        self.syncAutoToastConfig();
                    });

                    return this;
                },

                unbindConfigEvents: function () {
                    if (typeof this._offConfigChanged === 'function') {
                        this._offConfigChanged();
                    }
                    this._offConfigChanged = null;
                    return this;
                },
                
                getPollKey: function () {
                    return 'onlines.resync.' + (this.mode === 'smart' ? 'smart' : 'complete');
                },

                startResync: function () {
                    var self = this;
                    this.stopResync();

                    var runner = function () {
                        if (self.showAutoToast === true) {
                            Toast.show({
                                body: '<span class="spinner-border spinner-border-sm mx-4" role="status" aria-hidden="true"></span> Aggiorno i presenti ...',
                            });
                        }
                        if (self.mode !== 'smart') {
                            self.list();
                        }
                        self.get();
                    };

                    if (typeof PollManager === 'function') {
                        this.pollKey = this.getPollKey();
                        this.usesPollManager = true;
                        this.resyncTimer = PollManager().start(this.pollKey, runner, 30000);
                        return this.resyncTimer;
                    }

                    this.usesPollManager = false;
                    this.resyncTimer = setInterval(runner, 30000);
                    return this.resyncTimer;
                },

                stopResync: function () {
                    if (this.syncTimeout) {
                        clearTimeout(this.syncTimeout);
                        this.syncTimeout = null;
                    }

                    if (this.usesPollManager === true && this.pollKey && typeof PollManager === 'function') {
                        PollManager().stop(this.pollKey);
                    }

                    if (this.resyncTimer) {
                        clearInterval(this.resyncTimer);
                        this.resyncTimer = null;
                    }

                    this.usesPollManager = false;
                    this.pollKey = null;
                },

                get: function () {
                    var self = this;

                    callOnlinesModule('list', null, function (response) {
                        if (null == response) {
                            return;
                        }

                        if (!response.dataset || typeof response.dataset !== 'object') {
                            self.dataset = { totOnlines: [], inLocation: [], loggedIn: [], loggedOut: [] };
                        } else {
                            self.dataset = response.dataset;
                        }
                        self.build();
                    }, function (error) {
                        Toast.show({
                            body: normalizeOnlinesError(error, 'Errore durante caricamento presenti'),
                            type: 'error'
                        });
                        $('[name="list_inlocation"]').empty().append('<div class="list-group-item text-center">-</div>');
                        $('[name="list_loggedin"]').empty();
                        $('[name="list_loggedout"]').empty();
                    });
                },

                list: function() {
                    var self = this;

                    callOnlinesModule('complete', null, function (response) {
                        if (null == response) {
                            return;
                        }

                        self.dataset = response.dataset;
                        self.buildCompleteList();
                    }, function (error) {
                        Toast.show({
                            body: normalizeOnlinesError(error, 'Errore durante caricamento elenco presenti'),
                            type: 'error'
                        });
                    });
                },

                build: function () {
                    this._buildInLocationSection();

                    var sections = [
                        { block: 'list_loggedin',  obj: 'loggedIn'  },
                        { block: 'list_loggedout', obj: 'loggedOut' }
                    ];
                    for (var i in sections) {
                        this._buildListing(sections[i]);
                    }
                    this._buildTotOnlines();
                    this._syncAvailabilityControl();
                    this._scheduleSync();
                },

                buildCompleteList: function () {
                    var self = this;

                    // Detach existing character items before wiping the DOM so their
                    // already-loaded <img> elements are preserved in memory and can be reused.
                    var reusable = {};
                    $('#onlines-page-body [data-char-id]').each(function () {
                        var id = String($(this).data('charId') || '');
                        if (id) {
                            reusable[id] = $(this).detach();
                        }
                    });
                    this._reusableItems = reusable;

                    let blockContent = $('#onlines-page-body').empty();
                    let grouped = this._groupCompleteByMapAndLocation();

                    if (!Array.isArray(grouped) || grouped.length === 0) {
                        blockContent.append('<div class="alert alert-info mb-0">Nessun personaggio online.</div>');
                        this._reusableItems = null;
                        this._scheduleSync();
                        return;
                    }

                    for (let g = 0; g < grouped.length; g++) {
                        let mapGroup = grouped[g];
                        let tempMap = $($('[name="template_onlines_section"]').html());
                        let mapIcon = String(mapGroup.map_icon || '').trim();
                        let mapName = String(mapGroup.map_name || '').trim();

                        if (mapName === '') {
                            mapName = 'Alle mappe';
                        }
                        if (mapIcon === '') {
                            mapIcon = '/assets/imgs/defaults-images/default-map.png';
                        }

                        tempMap.find('[name="map_icon"]').attr('src', thumbUrl(mapIcon, 64, 64));
                        tempMap.find('[name="map_name"]').text('Presenti in ' + mapName);

                        let groupContainer = tempMap.find('.onlines-map-groups');

                        if (Array.isArray(mapGroup.in_map) && mapGroup.in_map.length > 0) {
                            let mapOnlySection = $($('[name="template_onlines_subsection"]').html());
                            mapOnlySection.find('[name="group_title"]').text('In mappa (nessun luogo)');
                            mapOnlySection.find('[name="group_travel_link"]').addClass('d-none');
                            let mapOnlyList = mapOnlySection.find('.list-group');
                            for (let m = 0; m < mapGroup.in_map.length; m++) {
                                self._buildCompleteItem(mapGroup.in_map[m]).appendTo(mapOnlyList);
                            }
                            mapOnlySection.appendTo(groupContainer);
                        }

                        if (Array.isArray(mapGroup.locations) && mapGroup.locations.length > 0) {
                            for (let l = 0; l < mapGroup.locations.length; l++) {
                                let locationGroup = mapGroup.locations[l];
                                let locationSection = $($('[name="template_onlines_subsection"]').html());
                                locationSection.find('[name="group_title"]').text(locationGroup.location_name);
                                let locationTravel = locationSection.find('[name="group_travel_link"]');
                                if (parseInt(locationGroup.location_id, 10) > 0 && parseInt(mapGroup.map_id, 10) > 0) {
                                    locationTravel
                                        .attr('href', '/game/maps/' + mapGroup.map_id + '/location/' + locationGroup.location_id)
                                        .removeClass('d-none');
                                } else {
                                    locationTravel.addClass('d-none');
                                }
                                let locationList = locationSection.find('.list-group');

                                for (let u = 0; u < locationGroup.users.length; u++) {
                                    self._buildCompleteItem(locationGroup.users[u]).appendTo(locationList);
                                }

                                locationSection.appendTo(groupContainer);
                            }
                        }

                        if (
                            (!Array.isArray(mapGroup.in_map) || mapGroup.in_map.length === 0) &&
                            (!Array.isArray(mapGroup.locations) || mapGroup.locations.length === 0)
                        ) {
                            groupContainer.append('<div class="text-muted small">Nessun personaggio online in questa mappa.</div>');
                        }

                        tempMap.appendTo(blockContent);
                    }

                    this._reusableItems = null;
                    this._scheduleSync();
                },

                _groupCompleteByMapAndLocation: function () {
                    if (!Array.isArray(this.dataset) || this.dataset.length === 0) {
                        return [];
                    }

                    let mapsByKey = {};
                    let mapOrder = [];

                    for (let i = 0; i < this.dataset.length; i++) {
                        let row = this.dataset[i];
                        let mapId = parseInt(row.map_id, 10);
                        if (isNaN(mapId) || mapId <= 0) {
                            mapId = 0;
                        }

                        let mapKey = 'map_' + mapId;
                        if (!mapsByKey[mapKey]) {
                            mapsByKey[mapKey] = {
                                map_id: mapId,
                                map_name: String(row.map_name || '').trim(),
                                map_icon: String(row.map_icon || '').trim(),
                                in_map: [],
                                locations: [],
                                _locationsByKey: {}
                            };
                            mapOrder.push(mapKey);
                        }

                        let mapGroup = mapsByKey[mapKey];
                        if (mapGroup.map_name === '') {
                            mapGroup.map_name = String(row.map_name || '').trim();
                        }
                        if (mapGroup.map_icon === '') {
                            mapGroup.map_icon = String(row.map_icon || '').trim();
                        }

                        let isHiddenLocation = (parseInt(row.location_hidden, 10) === 1);
                        if (isHiddenLocation) {
                            mapGroup.in_map.push(row);
                            continue;
                        }

                        let locationId = parseInt(row.location_id, 10);
                        if (isNaN(locationId) || locationId <= 0) {
                            mapGroup.in_map.push(row);
                            continue;
                        }

                        let locationKey = 'location_' + locationId;
                        if (!mapGroup._locationsByKey[locationKey]) {
                            let locationName = String(row.location_name || '').trim();
                            if (locationName === '') {
                                locationName = 'Luogo sconosciuto';
                            }

                            mapGroup._locationsByKey[locationKey] = {
                                location_id: locationId,
                                location_name: locationName,
                                users: []
                            };
                            mapGroup.locations.push(mapGroup._locationsByKey[locationKey]);
                        }

                        mapGroup._locationsByKey[locationKey].users.push(row);
                    }

                    let grouped = [];
                    for (let m = 0; m < mapOrder.length; m++) {
                        let key = mapOrder[m];
                        let entry = mapsByKey[key];
                        if (!entry) {
                            continue;
                        }
                        delete entry._locationsByKey;
                        grouped.push(entry);
                    }

                    return grouped;
                },

                _buildCompleteItem: function (row) {
                    var charId = String(row.id || '');
                    var availability = this._setAvailability(row.availability);
                    var gender = this._setGender(row.gender);
                    var isInvisible = (parseInt(row.is_visible, 10) === 0);
                    var surnameHtml = (null == row.surname) ? '' : ' <em>' + row.surname + '</em>';
                    var namingHtml = row.name + surnameHtml;
                    var isSelf = (Storage().get('characterId') == row.id);
                    var targetId = row.id;
                    var targetName = ((row.name || '') + ' ' + ((null == row.surname) ? '' : row.surname)).trim();

                    // Reuse the existing DOM element for this character when available.
                    // This preserves already-loaded <img> elements, avoiding redundant
                    // HTTP requests on every list refresh (critical on connection-limited hosts).
                    var existing = (this._reusableItems && charId) ? this._reusableItems[charId] : null;
                    if (existing) {
                        delete this._reusableItems[charId];

                        existing.find('[name="availability"]')
                            .removeClass()
                            .addClass('bi bi-circle-fill me-3 ' + availability.class)
                            .attr('data-bs-title', availability.text);
                        existing.find('[name="naming"]').html(namingHtml);
                        existing.find('[name="gender"]')
                            .removeClass()
                            .addClass('ms-2 bi ' + (gender ? gender.class + ' ' + gender.icon : ''))
                            .attr('data-bs-title', gender ? gender.text : '');
                        existing.find('[name="invisible_icon"]').toggleClass('d-none', !isInvisible);
                        existing.find('[name="online_status"]').html(row.online_status);

                        // Update image src only if it actually changed to avoid browser re-fetch
                        var avatarThumb = thumbUrl(row.avatar || '', 46, 46);
                        var avatarEl = existing.find('[name="avatar"]');
                        if (avatarEl.attr('src') !== avatarThumb) {
                            avatarEl.attr('src', avatarThumb);
                        }
                        var statusThumb = thumbUrl(row.socialstatus_icon || '', 32, 32);
                        var statusIconEl = existing.find('[name="socialstatus_icon"]');
                        if (statusIconEl.attr('src') !== statusThumb) {
                            statusIconEl.attr('src', statusThumb);
                        }
                        statusIconEl.attr({ title: row.socialstatus_name, 'data-bs-title': row.socialstatus_name });

                        // Rebind message link in case compose modal target changed
                        existing.find('[name="message_link"]').off('click.onlines').on('click.onlines', function (e) {
                            e.preventDefault();
                            openComposeModal(targetId, targetName);
                        });

                        return existing;
                    }

                    // No reusable element — create a fresh one from the template
                    var tempItem = $($('[name="template_onlines_list_sections"]').html());
                    tempItem.attr('data-char-id', charId);

                    tempItem.find('[name="availability"]').attr('data-bs-title', availability.text).addClass(availability.class);
                    tempItem.find('[name="naming"]').html(namingHtml);
                    tempItem.find('[name="gender"]').attr('data-bs-title', gender ? gender.text : '').addClass(gender ? [gender.class, gender.icon] : []);
                    if (isInvisible) {
                        tempItem.find('[name="invisible_icon"]').removeClass('d-none');
                    }
                    tempItem.find('[name="online_status"]').html(row.online_status);

                    // Avatar: lazy loading + one automatic retry on connection error
                    var avatarImg = tempItem.find('[name="avatar"]')[0];
                    if (avatarImg) {
                        avatarImg.setAttribute('loading', 'lazy');
                        avatarImg.setAttribute('src', thumbUrl(row.avatar || '', 46, 46));
                        avatarImg.addEventListener('error', function () {
                            if (this._retried) { return; }
                            this._retried = true;
                            var src = this.src;
                            this.src = '';
                            var img = this;
                            setTimeout(function () { img.src = src; }, 4000);
                        });
                    }

                    var statusIconEl = tempItem.find('[name="socialstatus_icon"]');
                    statusIconEl.attr({
                        src: thumbUrl(row.socialstatus_icon || '', 32, 32),
                        loading: 'lazy',
                        title: row.socialstatus_name,
                        'data-bs-title': row.socialstatus_name
                    });

                    if (isSelf) {
                        tempItem.find('[name="profile_link"]').remove();
                        tempItem.find('[name="message_link"]').remove();
                    } else {
                        tempItem.find('[name="profile_link"]').attr('href', '/game/profile/' + row.id);
                        tempItem.find('[name="message_link"]').attr('href', '#').on('click.onlines', function (e) {
                            e.preventDefault();
                            openComposeModal(targetId, targetName);
                        });
                    }

                    return tempItem;
                },
                
                _scheduleSync: function () {
                    var self = this;
                    if (this.syncTimeout) {
                        clearTimeout(this.syncTimeout);
                    }
                    this.syncTimeout = setTimeout(function () {
                        if (window.GameGlobals && typeof window.GameGlobals.initTooltips === 'function') {
                            window.GameGlobals.initTooltips(document);
                        } else if (typeof window.initTooltips === 'function') {
                            window.initTooltips(document);
                        }
                        self.syncTimeout = null;
                    }, this.syncDelayMs);
                },
                
                _buildTotOnlines: function () {
                    let block = $('[name="tot_onlines"]');
                    var total = (this.dataset && Array.isArray(this.dataset.totOnlines) && this.dataset.totOnlines[0])
                        ? (parseInt(this.dataset.totOnlines[0].tot_onlines, 10) || 0)
                        : 0;
                    block.html(total);
                },
                _syncAvailabilityControl: function () {
                    let controls = [];
                    if (typeof window.AvailabilityControl !== 'undefined') {
                        controls.push(window.AvailabilityControl);
                    }
                    if (typeof window.AvailabilityNavControl !== 'undefined') {
                        controls.push(window.AvailabilityNavControl);
                    }
                    if (!controls.length) {
                        return;
                    }

                    let me = Storage().get('characterId');
                    if (!me || null == this.dataset) {
                        return;
                    }

                    let availability = null;
                    let lists = [];

                    if (Array.isArray(this.dataset.inLocation)) {
                        lists.push(this.dataset.inLocation);
                    }
                    if (Array.isArray(this.dataset.loggedIn)) {
                        lists.push(this.dataset.loggedIn);
                    }
                    if (Array.isArray(this.dataset.loggedOut)) {
                        lists.push(this.dataset.loggedOut);
                    }

                    for (let i = 0; i < lists.length; i++) {
                        let items = lists[i];
                        for (let j = 0; j < items.length; j++) {
                            if (parseInt(items[j].id, 10) === parseInt(me, 10)) {
                                availability = items[j].availability;
                                break;
                            }
                        }
                        if (availability !== null) {
                            break;
                        }
                    }

                    if (availability === null) {
                        return;
                    }

                    for (let c = 0; c < controls.length; c++) {
                        if (controls[c] && controls[c].input) {
                            controls[c].input.val(availability).change();
                        }
                    }
                    Storage().set('characterAvailability', availability);
                    if (typeof window.updateAvailabilityIndicator === 'function') {
                        window.updateAvailabilityIndicator(availability);
                    }
                },

                _buildInLocationSection: function () {
                    var dataset = this.dataset || {};
                    var viewerLocationId = parseInt(dataset.viewer_location_id, 10) || 0;
                    var viewerMapId = parseInt(dataset.viewer_map_id, 10) || 0;
                    var inLocation = Array.isArray(dataset.inLocation) ? dataset.inLocation : [];

                    var titleEl = $('[name="inlocation_title"]');
                    var iconEl = $('[name="inlocation_icon"]');
                    var block = $('[name="list_inlocation"]').empty();

                    var viewerIsInLocation = (viewerLocationId > 0 && viewerMapId > 0);

                    if (!viewerIsInLocation) {
                        titleEl.text('In giro');
                        iconEl.removeClass('bi-geo-alt').addClass('bi-compass');
                    } else {
                        // Viewer è in un luogo — titolo dalla prima row o fallback generico
                        if (inLocation.length > 0) {
                            var first = inLocation[0];
                            var titleParts = [];
                            if (first.map_name) { titleParts.push(first.map_name); }
                            if (first.location_name) { titleParts.push(first.location_name); }
                            titleEl.text(titleParts.length > 0 ? titleParts.join(' — ') : 'Nel mio luogo');
                        } else {
                            titleEl.text('Nel mio luogo');
                        }
                        iconEl.removeClass('bi-compass').addClass('bi-geo-alt');
                    }

                    if (inLocation.length === 0) {
                        block.append('<div class="list-group-item text-center">Non ci sono utenti</div>');
                        return;
                    }

                    for (var i = 0; i < inLocation.length; i++) {
                        var row = inLocation[i];
                        var template = $($('template[name="item_list_online"]').html());
                        var availability = this._setAvailability(row.availability);
                        var gender = this._setGender(row.gender);

                        template.find('[name="availability"]').attr('data-bs-title', availability.text).addClass(availability.class);
                        template.find('[name="gender"]').attr('data-bs-title', gender ? gender.text : '').addClass(gender ? [gender.class, gender.icon] : []);
                        if (parseInt(row.is_visible, 10) === 0) {
                            template.find('[name="invisible_icon"]').removeClass('d-none');
                        }
                        template.find('[name="name"]').html(row.name);

                        // Label "[mappa] — [luogo]"
                        var locationLabel = [];
                        if (row.map_name) { locationLabel.push(row.map_name); }
                        if (row.location_name) { locationLabel.push(row.location_name); }
                        if (locationLabel.length > 0) {
                            template.find('[name="item_location_label"]').text(locationLabel.join(' — '));
                            template.find('[name="item_location"]').removeClass('d-none');
                        }

                        if (Storage().get('characterId') == row.id) {
                            template.find('[name="profile_link"]').remove();
                            template.find('[name="message_link"]').remove();
                        } else {
                            template.find('[name="profile_link"]').attr('href', '/game/profile/' + row.id);
                            var targetId = row.id;
                            var targetName = ((row.name || '') + ' ' + (row.surname || '')).trim();
                            template.find('[name="message_link"]').attr('href', '#').on('click', function (e) {
                                e.preventDefault();
                                openComposeModal(targetId, targetName);
                            });
                        }
                        template.appendTo(block);
                    }
                },

                _buildListing: function (obj) {
                    var block = $('[name="' + obj.block + '"]').empty();

                    if (!this.dataset || !Array.isArray(this.dataset[obj.obj]) || this.dataset[obj.obj].length === 0) {
                        block.append('<div class="list-group-item text-center">Non ci sono utenti</div>');
                        return;
                    }

                    for (var i in this.dataset[obj.obj]) {
                        var row = this.dataset[obj.obj][i];
                        var template = $($('template[name="item_list_online"]').html());
                        var availability = this._setAvailability(row.availability);
                        var gender = this._setGender(row.gender);

                        template.find('[name="availability"]').attr('data-bs-title', availability.text).addClass(availability.class);
                        template.find('[name="gender"]').attr('data-bs-title', gender ? gender.text : '').addClass(gender ? [gender.class, gender.icon] : []);
                        if (parseInt(row.is_visible, 10) === 0) {
                            template.find('[name="invisible_icon"]').removeClass('d-none');
                        }
                        template.find('[name="name"]').html(row.name);

                        if (Storage().get('characterId') == row.id) {
                            template.find('[name="profile_link"]').remove();
                            template.find('[name="message_link"]').remove();
                        } else {
                            template.find('[name="profile_link"]').attr('href', '/game/profile/' + row.id);
                            var targetId = row.id;
                            var targetName = ((row.name || '') + ' ' + (row.surname || '')).trim();
                            template.find('[name="message_link"]').attr('href', '#').on('click', function (e) {
                                e.preventDefault();
                                openComposeModal(targetId, targetName);
                            });
                        }
                        template.appendTo(block);
                    }
                },

                _setAvailability: function (availability) {
                    switch(availability) {
                        case 0:
                            return {
                                text: 'Occupato',
                                class: 'text-danger',
                            };
                        case 1:
                            return {
                                text: 'Disponibile',
                                class: 'text-success',
                            };
                        case 2:
                            return {
                                text: 'Non al PC',
                                class: 'text-warning',
                            };
                        case 3:
                            return {
                                text: 'Ricerca di gioco',
                                class: 'text-info',
                            };
                        default:
                            return {
                                text: 'Disponibile',
                                class: 'text-success',
                            };
                    }
                },

                _setGender: function (gender) {
                    var g = parseInt(gender, 10);
                    if (g === 2 || g === 0) {
                        return { text: 'Femmina', class: 'text-danger', icon: 'bi-gender-female' };
                    }
                    return { text: 'Maschio', class: 'text-info', icon: 'bi-gender-male' };
                },

                destroy: function () {
                    this.stopResync();
                    this.unbindConfigEvents();
                    $('#pageResync').off('click.onlines-resync');
                    $('#offcanvasOnline').off('hidden.bs.offcanvas.onlines-resync');
                    return this;
                },

                unmount: function () {
                    return this.destroy();
                }
            }

            let o = Object.assign({}, base, extension);
            return o.init();
    }
    window.GameOnlinesPage = GameOnlinesPage;
})(window);

