const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function initializeWeatherFeature() {
    if (globalWindow.__coreWeatherFeatureLoaded === true) {
        return;
    }
    globalWindow.__coreWeatherFeatureLoaded = true;

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

    function createWeatherModule() {
        return {
            ctx: null,
            options: {},

            mount: function (ctx, options) {
                this.ctx = ctx || null;
                this.options = options || {};
                return this;
            },

            unmount: function () {},

            get: function (payload, action) {
                return this.request('/get/weather', action || 'getWeather', payload || {});
            },

            state: function (payload) {
                return this.request('/get/weather', 'getLocationWeatherState', payload || {});
            },

            optionsList: function (payload, action) {
                return this.request('/weather/options', action || 'getWeatherOptions', payload || {});
            },

            setLocation: function (payload, action) {
                return this.request('/weather/location/set', action || 'setLocationWeather', payload || {});
            },

            clearLocation: function (payload, action) {
                return this.request('/weather/location/clear', action || 'clearLocationWeather', payload || {});
            },

            request: function (url, action, payload) {
                if (!this.ctx || !this.ctx.services || !this.ctx.services.http) {
                    return Promise.reject(new Error('HTTP service not available.'));
                }

                return this.ctx.services.http.request({
                    url: url,
                    action: action,
                    payload: payload || {}
                });
            }
        };
    }

    function normalizeWeatherError(error, fallback) {
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

    function callWeatherModule(method, payload, onSuccess, onError) {
        var mod = resolveModule('game.weather');
        if (!mod || typeof mod[method] !== 'function') {
            if (typeof onError === 'function') {
                onError(new Error('Weather module method not available: ' + method));
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

    function buildWeatherPopoverContent(dataset) {
        var html = '<div class="d-flex flex-column gap-2 small">';

        if (dataset.weather) {
            if (dataset.render_mode === 'image' && dataset.weather.img) {
                html += '<div class="icon p-weather"><img src="' + dataset.weather.img + '" alt="' + (dataset.weather.title || '') + '" style="width:32px;height:32px;object-fit:contain;"></div>';
            } else if (dataset.weather.body) {
                html += '<div class="icon p-weather">' + dataset.weather.body + '</div>';
            }
        }

        if (dataset.season && dataset.season.name) {
            html += '<div class="text-center"><span>' + dataset.season.name + '</span></div>';
        }

        if (dataset.temperatures && dataset.temperatures.degrees !== undefined) {
            html += '<div class="text-center"><span class="bi bi-thermometer-half me-1"></span>' + dataset.temperatures.degrees + '&deg;</div>';
        }

        if (dataset.moon && dataset.moon.img) {
            html += '<div class="text-center mb-2"><hr class="mb-3 mt-1 mx-auto w-75"><img src="' + dataset.moon.img + '" alt="' + (dataset.moon.phase || '') + '" style="width:16px;height:16px;flex-shrink:0;"> ' + (dataset.moon.title || '') + '</div>';
        }

        if (dataset.override_active === true || parseInt(dataset.override_active || '0', 10) === 1) {
            html += '<hr class="my-1 mx-auto w-75"><span class="badge text-bg-warning my-2">Override</span>';
        }

        html += '</div>';
        return html;
    }

    function ensureWeatherNavbarPopover() {
        if (typeof globalWindow.jQuery !== 'function') {
            return;
        }
        var weatherPopoverEl = document.getElementById('weatherNavbarPopoverBtn');
        if (!weatherPopoverEl || typeof globalWindow.bootstrap === 'undefined' || !globalWindow.bootstrap.Popover) {
            return;
        }

        var existingWeatherPopover = globalWindow.bootstrap.Popover.getInstance(weatherPopoverEl);
        if (existingWeatherPopover) {
            existingWeatherPopover.dispose();
        }

        globalWindow.__weatherNavbarPopover = new globalWindow.bootstrap.Popover(weatherPopoverEl, {
            trigger: 'click',
            placement: 'bottom',
            html: true,
            sanitize: false,
            content: '&nbsp;'
        });

        globalWindow.jQuery(document).off('click.weather-navbar-popover').on('click.weather-navbar-popover', function (event) {
            if (!globalWindow.jQuery(event.target).closest('#weatherNavbarPopoverBtn, .popover').length) {
                globalWindow.__weatherNavbarPopover.hide();
            }
        });
    }

    function GameWeatherPage(extension) {
        if (typeof globalWindow.jQuery !== 'function') {
            return Object.assign({}, extension || {});
        }
        var widget = {
            dataset: null,
            location_id: null,
            pollMs: 60000,
            timer: null,
            pollKey: 'weather.widget',
            usesPollManager: false,

            init: function () {
                var self = this;
                ensureWeatherNavbarPopover();
                this.location_id = this.resolveLocationId();
                this.get();
                if (typeof document !== 'undefined') {
                    document.addEventListener('DOMContentLoaded', function () {
                        self.get();
                    }, { once: true });
                }
                this.startPolling();
                return this;
            },

            resolveLocationId: function () {
                var input = globalWindow.jQuery('[name="location_id"]').first();
                if (!input.length) {
                    return null;
                }
                var value = parseInt(input.val(), 10);
                if (isNaN(value) || value <= 0) {
                    return null;
                }
                return value;
            },

            startPolling: function () {
                var self = this;
                this.stopPolling();
                if (typeof PollManager === 'function') {
                    this.usesPollManager = true;
                    this.timer = PollManager().start(this.pollKey, function () {
                        self.get();
                    }, this.pollMs);
                    return;
                }
                this.usesPollManager = false;
                this.timer = setInterval(function () {
                    self.get();
                }, this.pollMs);
            },

            stopPolling: function () {
                if (this.timer) {
                    clearInterval(this.timer);
                    this.timer = null;
                }
                if (this.usesPollManager === true && typeof PollManager === 'function') {
                    PollManager().stop(this.pollKey);
                }
                this.usesPollManager = false;
            },

            get: function () {
                var self = this;
                this.location_id = this.resolveLocationId();
                var payload = {};
                if (this.location_id !== null) {
                    payload.location_id = this.location_id;
                }
                callWeatherModule('get', payload, function (response) {
                    self.dataset = response;
                    if (response != null) {
                        self.build();
                    }
                }, function (error) {
                    console.warn('[WeatherPage] get failed', error);
                });
            },

            build: function () {
                var blocks = globalWindow.jQuery('[data-weather-widget]');
                if (!blocks.length || !this.dataset) {
                    return;
                }

                var dataset = this.dataset;
                var scopeText = String(dataset.scope || dataset.scope_type || 'auto');
                var seasonLabel = (dataset.season && dataset.season.name) ? String(dataset.season.name) : 'Stagione n/d';
                var overrideLabel = (dataset.override_reason && String(dataset.override_reason).trim() !== '')
                    ? ('Override: ' + String(dataset.override_reason).trim())
                    : 'Override meteo attivo';

                blocks.each(function () {
                    var block = globalWindow.jQuery(this);
                    if (dataset && dataset.weather) {
                        if (dataset.render_mode === 'image' && dataset.weather.img) {
                            block.find('.p-weather').html(
                                '<img class="img-fluid p-weather-icon-image" style="width:32px;height:32px;object-fit:contain;" src="'
                                + dataset.weather.img + '" alt="' + (dataset.weather.title || dataset.weather.key || 'Meteo') + '">'
                            );
                        } else {
                            block.find('.p-weather').html(dataset.weather.body || '');
                        }
                    } else {
                        block.find('.p-weather').html('');
                    }

                    if (dataset && dataset.temperatures && dataset.temperatures.degrees !== undefined) {
                        block.find('.p-weather-degrees').html(dataset.temperatures.degrees + '&deg;');
                    }

                    if (dataset && dataset.moon) {
                        block.find('.p-moonphases').html('<img style="width:16px;" src="' + dataset.moon.img + '" title="' + dataset.moon.title + '" alt="' + dataset.moon.phase + '"> ' + dataset.moon.title);
                    }

                    block.find('.p-weather-season').text(seasonLabel);
                    block.find('.p-weather-season').attr('title', 'Fonte: ' + scopeText);

                    var overrideBadge = block.find('.p-weather-override');
                    if (overrideBadge.length) {
                        if (dataset && (dataset.override_active === true || parseInt(dataset.override_active || '0', 10) === 1)) {
                            overrideBadge.removeClass('d-none');
                            overrideBadge.attr('title', overrideLabel);
                        } else {
                            overrideBadge.addClass('d-none');
                            overrideBadge.attr('title', '');
                        }
                    }
                });

                if (globalWindow.__weatherNavbarPopover && typeof globalWindow.__weatherNavbarPopover.setContent === 'function') {
                    globalWindow.__weatherNavbarPopover.setContent({
                        '.popover-body': buildWeatherPopoverContent(dataset)
                    });
                }
            },

            destroy: function () {
                this.stopPolling();
                return this;
            },

            unmount: function () {
                return this.destroy();
            }
        };

        var weather = Object.assign({}, widget, extension || {});
        return weather.init();
    }

    function GameWeatherStaffPage(extension) {
        if (typeof globalWindow.jQuery !== 'function') {
            return Object.assign({}, extension || {});
        }
        var page = {
            location_id: null,
            weatherGroup: null,
            options: null,

            init: function () {
                if (!globalWindow.jQuery('#location-weather-staff').length) {
                    return this;
                }
                this.location_id = this.resolveLocationId();
                if (!this.location_id) {
                    return this;
                }

                this.bind();
                this.loadOptions();
                this.loadState();
                return this;
            },

            resolveLocationId: function () {
                var input = globalWindow.jQuery('[name="location_id"]').first();
                if (!input.length) {
                    return null;
                }
                var value = parseInt(input.val(), 10);
                if (isNaN(value) || value <= 0) {
                    return null;
                }
                return value;
            },

            bind: function () {
                var self = this;
                globalWindow.jQuery('#location-weather-staff').off('click.weatherStaff');
                globalWindow.jQuery('#location-weather-staff').on('click.weatherStaff', '[data-weather-location-save]', function (event) {
                    event.preventDefault();
                    self.save();
                });
                globalWindow.jQuery('#location-weather-staff').on('click.weatherStaff', '[data-weather-location-clear]', function (event) {
                    event.preventDefault();
                    self.clear();
                });
            },

            loadOptions: function () {
                var self = this;
                callWeatherModule('optionsList', null, function (response) {
                    if (!response || !response.dataset) {
                        return;
                    }
                    self.options = response.dataset;
                    self.buildOptions();
                }, function (error) {
                    console.warn('[WeatherStaff] loadOptions failed', error);
                });
            },

            buildOptions: function () {
                var input = globalWindow.jQuery('#weather-location-key');
                if (!input.length) {
                    return;
                }

                var options = [{ label: 'Auto', value: 'inherit', style: 'secondary' }];
                if (this.options && Array.isArray(this.options.conditions)) {
                    for (var i = 0; i < this.options.conditions.length; i += 1) {
                        var row = this.options.conditions[i];
                        options.push({
                            label: row.title,
                            value: row.key,
                            style: 'primary'
                        });
                    }
                }

                if (typeof RadioGroup === 'function') {
                    if (this.weatherGroup && typeof this.weatherGroup.setOptions === 'function') {
                        this.weatherGroup.setOptions(options);
                    } else {
                        this.weatherGroup = RadioGroup('#weather-location-key', {
                            options: options,
                            btnClass: 'btn-sm'
                        });
                    }
                }

                var moon = globalWindow.jQuery('#weather-location-moon');
                if (!moon.length) {
                    return;
                }
                moon.empty();
                moon.append('<option value="">Auto</option>');
                if (this.options && Array.isArray(this.options.moon_phases)) {
                    for (var j = 0; j < this.options.moon_phases.length; j += 1) {
                        var moonRow = this.options.moon_phases[j];
                        moon.append('<option value="' + moonRow.phase + '">' + moonRow.title + '</option>');
                    }
                }
            },

            loadState: function () {
                var self = this;
                callWeatherModule('state', { location_id: this.location_id }, function (response) {
                    if (response) {
                        self.applyState(response);
                    }
                }, function (error) {
                    console.warn('[WeatherStaff] loadState failed', error);
                });
            },

            applyState: function (state) {
                var scope = globalWindow.jQuery('[data-weather-staff-scope]');
                if (scope.length) {
                    var text = 'Auto';
                    var klass = 'text-bg-secondary';
                    if (state.scope === 'location') {
                        text = 'Locale';
                        klass = 'text-bg-primary';
                    } else if (state.scope === 'map') {
                        text = 'Mappa';
                        klass = 'text-bg-primary';
                    } else if (state.scope === 'global') {
                        text = 'Globale';
                        klass = 'text-bg-dark';
                    } else if (state.scope === 'world') {
                        text = 'Mondo';
                        klass = 'text-bg-dark';
                    }
                    scope.removeClass('text-bg-secondary text-bg-primary text-bg-dark text-bg-info').addClass(klass).text(text);
                }

                var areaInfo = document.getElementById('weather-staff-area-info');
                if (areaInfo) {
                    if (state.climate_area && state.climate_area.name) {
                        areaInfo.style.display = '';
                        var nameEl = areaInfo.querySelector('[data-weather-staff-area-name]');
                        if (nameEl) {
                            nameEl.textContent = state.climate_area.name;
                        }
                    } else {
                        areaInfo.style.display = 'none';
                    }
                }

                var expiresInfo = document.getElementById('weather-staff-expires-info');
                if (expiresInfo) {
                    if (state.override_expires_at) {
                        expiresInfo.style.display = '';
                        var expiresEl = expiresInfo.querySelector('[data-weather-staff-expires]');
                        if (expiresEl) {
                            expiresEl.textContent = state.override_expires_at;
                        }
                    } else {
                        expiresInfo.style.display = 'none';
                    }
                }

                var local = state.location_override_data || null;
                var key = (local && local.weather_key) ? local.weather_key : 'inherit';
                globalWindow.jQuery('#weather-location-key').val(key).change();
                globalWindow.jQuery('[name="weather_location_degrees"]').val((local && local.degrees !== null && local.degrees !== '') ? local.degrees : '');
                globalWindow.jQuery('[name="weather_location_moon_phase"]').val((local && local.moon_phase) ? local.moon_phase : '');
                globalWindow.jQuery('[name="weather_location_expires_at"]').val('');
                globalWindow.jQuery('[name="weather_location_note"]').val((local && local.note) ? local.note : '');
            },

            save: function () {
                var self = this;
                var expiresRaw = globalWindow.jQuery('[name="weather_location_expires_at"]').val();
                var payload = {
                    location_id: this.location_id,
                    weather_key: globalWindow.jQuery('#weather-location-key').val(),
                    degrees: globalWindow.jQuery('[name="weather_location_degrees"]').val(),
                    moon_phase: globalWindow.jQuery('[name="weather_location_moon_phase"]').val(),
                    expires_at: expiresRaw || null,
                    note: globalWindow.jQuery('[name="weather_location_note"]').val() || null
                };

                callWeatherModule('setLocation', payload, function (response) {
                    Toast.show({ body: 'Meteo location aggiornato.', type: 'success' });
                    if (response && response.dataset) {
                        self.applyState(response.dataset);
                    } else {
                        self.loadState();
                    }
                    if (typeof globalWindow.Weather !== 'undefined' && typeof globalWindow.Weather.get === 'function') {
                        globalWindow.Weather.get();
                    }
                }, function (error) {
                    Toast.show({
                        body: normalizeWeatherError(error, 'Errore durante aggiornamento meteo location.'),
                        type: 'error'
                    });
                });
            },

            clear: function () {
                var self = this;
                callWeatherModule('clearLocation', { location_id: this.location_id }, function (response) {
                    Toast.show({ body: 'Override meteo rimosso.', type: 'success' });
                    if (response && response.dataset) {
                        self.applyState(response.dataset);
                    } else {
                        self.loadState();
                    }
                    if (typeof globalWindow.Weather !== 'undefined' && typeof globalWindow.Weather.get === 'function') {
                        globalWindow.Weather.get();
                    }
                }, function (error) {
                    Toast.show({
                        body: normalizeWeatherError(error, 'Errore durante rimozione override meteo.'),
                        type: 'error'
                    });
                });
            },

            destroy: function () {
                globalWindow.jQuery('#location-weather-staff').off('click.weatherStaff');
                if (this.weatherGroup && typeof this.weatherGroup.destroy === 'function') {
                    this.weatherGroup.destroy();
                }
                this.weatherGroup = null;
                return this;
            },

            unmount: function () {
                return this.destroy();
            }
        };

        var weatherStaff = Object.assign({}, page, extension || {});
        return weatherStaff.init();
    }

    globalWindow.GameWeatherModuleFactory = createWeatherModule;
    globalWindow.GameWeatherPage = GameWeatherPage;
    globalWindow.GameWeatherStaffPage = GameWeatherStaffPage;

    var weatherGamePages = [
        'home', 'forum', 'threads', 'thread', 'profile', 'profile-edit',
        'onlines', 'location', 'shop', 'bank', 'maps', 'locations',
        'settings', 'jobs', 'guilds', 'guild', 'bag', 'equips',
        'anagrafica'
    ];

    if (globalWindow.GameRegistry) {
        globalWindow.GameRegistry.registerModule('game.weather', 'GameWeatherModuleFactory');
        weatherGamePages.forEach(function (pageKey) {
            globalWindow.GameRegistry.extendPage(pageKey, ['game.weather']);
        });
    }

    if (globalWindow.GamePage) {
        globalWindow.GamePage.registerSharedController({
            global: 'Weather',
            factory: 'GameWeatherPage',
            args: [{}]
        });

        globalWindow.GamePage.registerPageController('location', {
            global: 'LocationWeatherStaff',
            factory: 'GameWeatherStaffPage',
            args: [{}]
        });
    }
}

initializeWeatherFeature();

export { initializeWeatherFeature };
export default initializeWeatherFeature;

