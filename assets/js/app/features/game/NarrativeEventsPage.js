(function (window) {
    'use strict';

    function resolveModule(name) {
        if (!window.RuntimeBootstrap || typeof window.RuntimeBootstrap.resolveAppModule !== 'function') {
            return null;
        }
        try { return window.RuntimeBootstrap.resolveAppModule(name); } catch (e) { return null; }
    }

    function GameNarrativeEventsPage(extension) {
        var page = {
            offcanvas: null,
            sourceFilter: '',
            tagFilterId: 0,
            tagsCatalog: [],
            locationsCatalog: [],
            loaded: false,
            capabilities: [],
            canCreate: false,
            indicatorPollKey: 'game.narrative-events.feed-indicator',
            indicatorPollMs: 45000,
            indicatorStorageKey: 'game.feed.narrative-events.seen_marker',
            indicatorLatestMarker: 0,

            init: function () {
                var el = document.getElementById('offcanvasNarrativeEvents');
                if (!el) { return this; }

                this.offcanvas = el;
                this.bind();
                this.bindCreate();
                this.startIndicatorPoll();
                this.pollIndicator(false);
                return this;
            },

            bind: function () {
                var self = this;
                var el   = this.offcanvas;

                el.addEventListener('show.bs.offcanvas', function () {
                    self.loadTagsCatalog();
                    self.loadCapabilities();
                    self.load();
                    self.pollIndicator(true);
                });

                el.addEventListener('click', function (event) {
                    var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
                    if (!trigger) { return; }
                    var action = trigger.getAttribute('data-action');
                    if (action === 'narrative-events-reload') {
                        event.preventDefault();
                        self.load();
                    }
                    if (action === 'narrative-events-create') {
                        event.preventDefault();
                        self.showCreateModal();
                    }
                    if (action === 'narrative-scene-close') {
                        event.preventDefault();
                        var eventId = parseInt(trigger.getAttribute('data-event-id') || '0', 10);
                        if (eventId > 0) { self.closeScene(eventId, trigger); }
                    }
                });

                var filter = el.querySelector('#narrative-events-type-filter');
                if (filter) {
                    filter.addEventListener('change', function () {
                        self.sourceFilter = filter.value || '';
                        self.load();
                    });
                }

                var tagFilter = el.querySelector('#narrative-events-tag-filter');
                if (tagFilter) {
                    tagFilter.addEventListener('change', function () {
                        self.tagFilterId = parseInt(tagFilter.value || '0', 10) || 0;
                        self.load();
                    });
                }
            },

            loadTagsCatalog: function () {
                var self = this;
                if (!window.Request || !Request.http || typeof Request.http.post !== 'function') {
                    return;
                }
                Request.http.post('/list/narrative-tags', { entity_type: 'narrative_event' }).then(function (response) {
                    var rows = (response && response.dataset && Array.isArray(response.dataset)) ? response.dataset : [];
                    self.tagsCatalog = rows;
                    self.populateTagFilter();
                }).catch(function () {});
            },

            populateTagFilter: function () {
                var select = document.getElementById('narrative-events-tag-filter');
                if (!select) { return; }
                var current = String(this.tagFilterId || '');
                var html = '<option value="">Tutti i tag</option>';
                for (var i = 0; i < this.tagsCatalog.length; i += 1) {
                    var row = this.tagsCatalog[i] || {};
                    var id = parseInt(row.id || '0', 10) || 0;
                    if (id <= 0) { continue; }
                    html += '<option value="' + id + '">' + this.escapeHtml(row.label || row.slug || ('Tag #' + id)) + '</option>';
                }
                select.innerHTML = html;
                if (current !== '') {
                    select.value = current;
                }
            },

            readSeenMarker: function () {
                if (!window.localStorage) { return 0; }
                var raw = window.localStorage.getItem(this.indicatorStorageKey);
                var value = parseInt(raw || '0', 10);
                return value > 0 ? value : 0;
            },

            writeSeenMarker: function (value) {
                if (!window.localStorage) { return; }
                var marker = parseInt(value || '0', 10);
                if (marker > 0) {
                    window.localStorage.setItem(this.indicatorStorageKey, String(marker));
                }
            },

            parseDateMarker: function (rawValue) {
                var raw = String(rawValue || '').trim();
                if (raw === '') { return 0; }
                var date = new Date(raw.replace(' ', 'T'));
                if (isNaN(date.getTime())) { return 0; }
                return date.getTime();
            },

            extractRowMarker: function (row) {
                var idMarker = parseInt((row && row.id) || '0', 10);
                if (idMarker > 0) {
                    return idMarker;
                }
                return this.parseDateMarker((row && (row.created_at || row.date_created || row.date_start)) || '');
            },

            latestMarkerFromRows: function (rows) {
                var latest = 0;
                var list = Array.isArray(rows) ? rows : [];
                for (var i = 0; i < list.length; i += 1) {
                    var marker = this.extractRowMarker(list[i]);
                    if (marker > latest) {
                        latest = marker;
                    }
                }
                return latest;
            },

            countNewRows: function (rows, seenMarker) {
                var count = 0;
                var list = Array.isArray(rows) ? rows : [];
                for (var i = 0; i < list.length; i += 1) {
                    if (this.extractRowMarker(list[i]) > seenMarker) {
                        count += 1;
                    }
                }
                return count;
            },

            setIndicatorBadge: function (count) {
                var safeCount = parseInt(count || '0', 10);
                if (safeCount < 0) { safeCount = 0; }
                var labels = document.querySelectorAll('[data-feed-badge="narrative-events"]');
                for (var i = 0; i < labels.length; i += 1) {
                    var label = labels[i];
                    if (safeCount > 0) {
                        label.textContent = safeCount > 99 ? '99+' : String(safeCount);
                        label.classList.remove('d-none');
                        label.classList.add('feed-badge-pulse');
                    } else {
                        label.textContent = '0';
                        label.classList.remove('feed-badge-pulse');
                        label.classList.add('d-none');
                    }
                }
            },

            processIndicatorRows: function (rows, markSeen) {
                var latest = this.latestMarkerFromRows(rows);
                if (latest > this.indicatorLatestMarker) {
                    this.indicatorLatestMarker = latest;
                }

                var seen = this.readSeenMarker();

                if (markSeen === true && latest > 0) {
                    seen = latest;
                    this.writeSeenMarker(latest);
                }

                var newCount = 0;
                if (markSeen !== true && latest > seen) {
                    newCount = this.countNewRows(rows, seen);
                }
                this.setIndicatorBadge(newCount);
            },

            pollIndicator: function (markSeen) {
                var mod = resolveModule('game.narrative-events');
                if (!mod || typeof mod.list !== 'function') { return; }

                var self = this;
                mod.list({ limit: 50, page: 1 }).then(function (response) {
                    var rows = (response && response.dataset && response.dataset.rows) ? response.dataset.rows : [];
                    self.processIndicatorRows(Array.isArray(rows) ? rows : [], markSeen === true);
                }).catch(function () {});
            },

            startIndicatorPoll: function () {
                var manager = window.AppLifecycle && typeof window.AppLifecycle.getPollManager === 'function'
                    ? window.AppLifecycle.getPollManager()
                    : null;
                if (!manager || typeof manager.start !== 'function') {
                    return;
                }
                var self = this;
                manager.start(this.indicatorPollKey, function () {
                    self.pollIndicator(false);
                }, this.indicatorPollMs);
            },

            stopIndicatorPoll: function () {
                var manager = window.AppLifecycle && typeof window.AppLifecycle.getPollManager === 'function'
                    ? window.AppLifecycle.getPollManager()
                    : null;
                if (!manager || typeof manager.stop !== 'function') {
                    return;
                }
                manager.stop(this.indicatorPollKey);
            },

            load: function () {
                var self = this;
                var list = document.getElementById('narrative-events-list');
                var empty = document.getElementById('narrative-events-empty');
                var loading = document.getElementById('narrative-events-loading');

                if (list) { list.innerHTML = ''; }
                if (empty)   { empty.classList.add('d-none'); }
                if (loading) { loading.classList.remove('d-none'); }

                var mod = resolveModule('game.narrative-events');
                if (!mod || typeof mod.list !== 'function') {
                    if (loading) { loading.classList.add('d-none'); }
                    return;
                }

                // Carica scene aperte e feed in parallelo
                var scenesPromise = (typeof mod.listScenes === 'function')
                    ? mod.listScenes({}).catch(function () { return { dataset: [] }; })
                    : Promise.resolve({ dataset: [] });

                var payload = { limit: 50, page: 1 };
                if (self.sourceFilter) { payload.source_system = self.sourceFilter; }
                if (self.tagFilterId > 0) { payload.tag_ids = [self.tagFilterId]; }

                Promise.all([scenesPromise, mod.list(payload)]).then(function (results) {
                    if (loading) { loading.classList.add('d-none'); }

                    var scenes = (results[0] && Array.isArray(results[0].dataset)) ? results[0].dataset : [];
                    var rows   = (results[1] && results[1].dataset && results[1].dataset.rows) ? results[1].dataset.rows : [];

                    self.renderActiveScenes(scenes);

                    if (!rows.length && !scenes.length) {
                        if (empty) { empty.classList.remove('d-none'); }
                        return;
                    }
                    if (rows.length) { self.render(rows); }
                }).catch(function (e) {
                    if (loading) { loading.classList.add('d-none'); }
                    console.warn('[NarrativeEvents] load failed', e);
                });
            },

            renderActiveScenes: function (scenes) {
                var self = this;
                var container = document.getElementById('narrative-events-active-scenes');
                if (!container) { return; }

                if (!scenes.length) {
                    container.classList.add('d-none');
                    container.innerHTML = '';
                    return;
                }

                container.classList.remove('d-none');
                container.innerHTML = scenes.map(function (sc) {
                    var title = self.escapeHtml(sc.title || 'Scena');
                    var scopeLabel = { local: 'Locale', regional: 'Regionale', global: 'Globale' }[sc.scope] || sc.scope;
                    var canClose = self.canCreate; // chi può creare può anche chiudere
                    return '<div class="list-group-item px-3 py-2 border-start border-4 border-warning">'
                        + '<div class="d-flex justify-content-between align-items-center gap-2">'
                        + '<div>'
                        + '<span class="badge text-bg-warning me-1" style="font-size:.6rem;">ATTIVA</span>'
                        + '<span class="badge text-bg-secondary me-1" style="font-size:.6rem;">' + self.escapeHtml(scopeLabel) + '</span>'
                        + '<span class="fw-semibold small">' + title + '</span>'
                        + '</div>'
                        + (canClose
                            ? '<button class="btn btn-xs btn-outline-danger py-0 px-1" style="font-size:.7rem;" data-action="narrative-scene-close" data-event-id="' + (sc.id || 0) + '">Chiudi</button>'
                            : '')
                        + '</div>'
                        + (sc.description ? '<div class="small text-muted mt-1">' + self.escapeHtml(sc.description) + '</div>' : '')
                        + '</div>';
                }).join('');
            },

            closeScene: function (eventId, btn) {
                var self = this;
                var mod = resolveModule('game.narrative-events');
                if (!mod || typeof mod.close !== 'function') { return; }

                if (btn) { btn.disabled = true; }

                mod.close({ event_id: eventId }).then(function () {
                    if (window.Toast && typeof window.Toast.show === 'function') {
                        window.Toast.show({ body: 'Scena chiusa.', type: 'success' });
                    }
                    document.dispatchEvent(new CustomEvent('narrative:scene-changed'));
                    self.load();
                }).catch(function (err) {
                    if (btn) { btn.disabled = false; }
                    var msg = (err && err.message) ? err.message : 'Errore durante la chiusura della scena.';
                    if (window.Toast && typeof window.Toast.show === 'function') {
                        window.Toast.show({ body: msg, type: 'error' });
                    }
                });
            },

            render: function (rows) {
                var self = this;
                var list = document.getElementById('narrative-events-list');
                if (!list) { return; }

                var sourceLabels = {
                    conflict: 'Conflitto',
                    faction: 'Fazione',
                    system_event: 'Evento',
                    quest: 'Quest',
                    lifecycle: 'Ciclo vita',
                    manual: 'Manuale',
                    system: 'Sistema'
                };
                var sourceIcons = {
                    conflict: '/assets/imgs/defaults-images/icons/last-activity-conflict.png',
                    faction: '/assets/imgs/defaults-images/icons/last-activity-faction.png',
                    system_event: '/assets/imgs/defaults-images/icons/last-activity-event.png',
                    quest: '/assets/imgs/defaults-images/icons/last-activity-quest.png',
                    lifecycle: '/assets/imgs/defaults-images/icons/last-activity-lifecycle.png',
                    manual: '/assets/imgs/defaults-images/icons/last-activity-manual.png',
                    system: '/assets/imgs/defaults-images/icons/last-activity-event.png'
                };
                var sourceColors = {
                    conflict: 'text-bg-danger',
                    faction: 'text-bg-info',
                    system_event: 'text-bg-warning',
                    quest: 'text-bg-success',
                    lifecycle: 'text-bg-primary',
                    manual: 'text-bg-secondary',
                    system: 'text-bg-dark'
                };

                list.innerHTML = rows.map(function (ev) {
                    var source = (ev.source_system || 'manual');
                    var label = sourceLabels[source] || source;
                    var icon = sourceIcons[source] || '/assets/imgs/defaults-images/icons/last-activity-event.png';
                    var color = sourceColors[source] || 'text-bg-secondary';
                    var title = ev.title || ev.name || 'Evento';
                    var body  = ev.body  || ev.description || ev.excerpt || '';
                    var date  = ev.date_created || ev.date_start || '';

                    return '<div class="list-group-item px-3 py-3">'
                        + '<div class="d-flex justify-content-between align-items-start mb-1">'
                        + '<div class="d-flex align-items-center gap-2 pe-2">'
                        + '<img src="' + self.escapeHtml(icon) + '" alt="' + self.escapeHtml(label) + '" class="rounded-2 mt-1" width="48" height="48" loading="lazy" decoding="async">'
                        + '<span class="fw-bold small">' + self.escapeHtml(title) + '</span>'
                        + '</div>'
                        + '<span class="badge ' + color + ' ms-2">' + self.escapeHtml(label) + '</span>'
                        + '</div>'
                        + (body ? '<p class="small text-muted mb-1" style="white-space:pre-line;">' + self.escapeHtml(body) + '</p>' : '')
                        + self.renderTagBadges(ev.narrative_tags)
                        + (date ? '<div class="text-muted" style="font-size:.7rem;">' + self.escapeHtml(String(date)) + '</div>' : '')
                        + '</div>';
                }).join('');
            },

            renderTagBadges: function (tags) {
                var rows = Array.isArray(tags) ? tags : [];
                if (!rows.length) { return ''; }
                var html = '<div class="d-flex flex-wrap gap-1 mb-1">';
                for (var i = 0; i < rows.length; i += 1) {
                    var row = rows[i] || {};
                    html += '<span class="badge text-bg-secondary">' + this.escapeHtml(row.label || row.slug || 'Tag') + '</span>';
                }
                html += '</div>';
                return html;
            },

            escapeHtml: function (value) {
                return String(value || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
            },

            loadCapabilities: function () {
                var self = this;
                var mod = resolveModule('game.narrative-events');
                if (!mod || typeof mod.getCapabilities !== 'function') { return; }

                mod.getCapabilities().then(function (response) {
                    var caps = (response && response.dataset && Array.isArray(response.dataset.capabilities))
                        ? response.dataset.capabilities
                        : [];
                    self.capabilities = caps;
                    self.canCreate = caps.indexOf('narrative.event.create') !== -1;
                    self.updateCreateButtonVisibility();
                }).catch(function () {
                    self.capabilities = [];
                    self.canCreate = false;
                    self.updateCreateButtonVisibility();
                });
            },

            updateCreateButtonVisibility: function () {
                var btn = document.getElementById('narrative-events-create-btn');
                if (!btn) { return; }
                if (this.canCreate) {
                    btn.classList.remove('d-none');
                } else {
                    btn.classList.add('d-none');
                }
            },

            loadLocationsDatalist: function () {
                var self = this;
                if (!window.Request || !Request.http || typeof Request.http.post !== 'function') {
                    return;
                }
                Request.http.post('/narrative-events/locations', {}).then(function (response) {
                    var rows = (response && Array.isArray(response.dataset)) ? response.dataset : [];
                    self.locationsCatalog = rows;
                    var dl = document.getElementById('narrative-event-locations-datalist');
                    if (!dl) { return; }
                    dl.innerHTML = rows.map(function (loc) {
                        var id   = parseInt(loc.id || '0', 10) || 0;
                        var name = self.escapeHtml(loc.name || ('Location #' + id));
                        return '<option value="' + name + '" data-location-id="' + id + '">';
                    }).join('');
                }).catch(function () {});
            },

            resolveLocationIdFromText: function (text) {
                var trimmed = String(text || '').trim().toLowerCase();
                if (!trimmed) { return 0; }
                var catalog = this.locationsCatalog;
                for (var i = 0; i < catalog.length; i += 1) {
                    var loc = catalog[i] || {};
                    var name = String(loc.name || '').trim().toLowerCase();
                    if (name === trimmed) {
                        return parseInt(loc.id || '0', 10) || 0;
                    }
                }
                // Fallback: prova a interpretare come numero diretto
                var parsed = parseInt(text || '0', 10);
                return parsed > 0 ? parsed : 0;
            },

            updateLocationWrapVisibility: function () {
                var scopeEl = document.getElementById('narrative-event-create-scope');
                var wrap    = document.getElementById('narrative-event-create-location-wrap');
                if (!scopeEl || !wrap) { return; }
                // La location è utile solo per scope regionale (determina la mappa)
                if (scopeEl.value === 'global') {
                    wrap.classList.add('d-none');
                } else {
                    wrap.classList.remove('d-none');
                }
            },

            showCreateModal: function () {
                var modalEl = document.getElementById('modalCreateNarrativeEvent');
                if (!modalEl) { return; }

                // Reset form
                var titleEl      = document.getElementById('narrative-event-create-title');
                var descEl       = document.getElementById('narrative-event-create-description');
                var locationEl   = document.getElementById('narrative-event-create-location');
                var locationIdEl = document.getElementById('narrative-event-create-location-id');
                var scopeEl      = document.getElementById('narrative-event-create-scope');
                var impactEl     = document.getElementById('narrative-event-create-impact');
                var impactHigh   = document.getElementById('narrative-event-create-impact-high');
                var alertEl      = document.getElementById('narrative-event-create-alert');
                var spinner      = document.getElementById('narrative-event-create-spinner');

                if (titleEl)      { titleEl.value      = ''; }
                if (descEl)       { descEl.value       = ''; }
                if (locationEl)   { locationEl.value   = ''; }
                if (locationIdEl) { locationIdEl.value = ''; }
                if (scopeEl)      { scopeEl.value      = 'regional'; }
                if (impactEl)     { impactEl.value     = '0'; }
                if (impactHigh)   { impactHigh.classList.toggle('d-none', !this.canCreate); }
                if (alertEl)      { alertEl.classList.add('d-none'); alertEl.textContent = ''; }
                if (spinner)      { spinner.classList.add('d-none'); }

                this.updateLocationWrapVisibility();
                this.loadLocationsDatalist();

                var modal = window.bootstrap && window.bootstrap.Modal
                    ? window.bootstrap.Modal.getOrCreateInstance(modalEl)
                    : null;
                if (modal) { modal.show(); }
            },

            bindCreate: function () {
                var self    = this;
                var submitBtn = document.getElementById('narrative-event-create-submit');
                if (submitBtn) {
                    submitBtn.addEventListener('click', function () {
                        self.submitCreate();
                    });
                }

                var scopeEl = document.getElementById('narrative-event-create-scope');
                if (scopeEl) {
                    scopeEl.addEventListener('change', function () {
                        self.updateLocationWrapVisibility();
                    });
                }
            },

            submitCreate: function () {
                var titleEl    = document.getElementById('narrative-event-create-title');
                var descEl     = document.getElementById('narrative-event-create-description');
                var locationEl = document.getElementById('narrative-event-create-location');
                var scopeEl    = document.getElementById('narrative-event-create-scope');
                var impactEl   = document.getElementById('narrative-event-create-impact');
                var alertEl    = document.getElementById('narrative-event-create-alert');
                var spinner    = document.getElementById('narrative-event-create-spinner');
                var submitBtn  = document.getElementById('narrative-event-create-submit');

                var title      = titleEl    ? titleEl.value.trim()                                          : '';
                var desc       = descEl     ? descEl.value.trim()                                         : '';
                var locationId = locationEl ? this.resolveLocationIdFromText(locationEl.value)             : 0;
                var scope      = scopeEl    ? scopeEl.value                                               : 'local';
                var impact     = impactEl   ? parseInt(impactEl.value || '0', 10)                         : 0;

                if (alertEl) { alertEl.classList.add('d-none'); alertEl.textContent = ''; }

                if (!title) {
                    if (alertEl) {
                        alertEl.textContent = 'Il titolo è obbligatorio.';
                        alertEl.classList.remove('d-none');
                    }
                    if (titleEl) { titleEl.focus(); }
                    return;
                }

                var mod = resolveModule('game.narrative-events');
                if (!mod || typeof mod.create !== 'function') { return; }

                if (spinner)   { spinner.classList.remove('d-none'); }
                if (submitBtn) { submitBtn.disabled = true; }

                var self = this;
                mod.create({ title: title, description: desc, location_id: locationId, scope: scope, impact_level: impact })
                    .then(function (response) {
                        if (spinner)   { spinner.classList.add('d-none'); }
                        if (submitBtn) { submitBtn.disabled = false; }

                        var modalEl = document.getElementById('modalCreateNarrativeEvent');
                        var modal = window.bootstrap && window.bootstrap.Modal
                            ? window.bootstrap.Modal.getInstance(modalEl)
                            : null;
                        if (modal) { modal.hide(); }

                        var msg = (response && response.message) ? response.message : 'Evento creato con successo.';
                        if (window.Toast && typeof window.Toast.show === 'function') {
                            window.Toast.show({ body: msg, type: 'success' });
                        }

                        document.dispatchEvent(new CustomEvent('narrative:scene-changed'));
                        self.load();
                    })
                    .catch(function (err) {
                        if (spinner)   { spinner.classList.add('d-none'); }
                        if (submitBtn) { submitBtn.disabled = false; }

                        var msg = (err && err.message) ? err.message : 'Errore durante la creazione dell\'evento.';
                        if (alertEl) {
                            alertEl.textContent = msg;
                            alertEl.classList.remove('d-none');
                        }
                    });
            },

            destroy: function () {
                this.stopIndicatorPoll();
                return this;
            },
            unmount: function () { return this.destroy(); }
        };

        var instance = Object.assign({}, page, extension || {});
        return instance.init();
    }

    window.GameNarrativeEventsPage = GameNarrativeEventsPage;
})(window);
