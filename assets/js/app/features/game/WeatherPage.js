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


    function normalizeWeatherError(error, fallback) {
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

    function callWeatherModule(method, payload, onSuccess, onError) {
        if (typeof resolveModule !== 'function') {
            if (typeof onError === 'function') {
                onError(new Error('Weather module resolver not available: ' + method));
            }
            return false;
        }

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
                html += '<div class="icon p-weather">'
                    + '<img src="' + dataset.weather.img + '" alt="' + (dataset.weather.title || '') + '" style="width:32px;height:32px;object-fit:contain;">'
                    + '</div>';
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
            html += '<div class="text-center mb-2"><hr class="mb-3 mt-1 mx-auto w-75">'
                + '<img src="' + dataset.moon.img + '" alt="' + (dataset.moon.phase || '') + '" style="width:16px;height:16px;flex-shrink:0;"> '
                + (dataset.moon.title || '')
                + '</div>';
        }

        if (dataset.override_active === true || parseInt(dataset.override_active || '0', 10) === 1) {
            html += '<hr class="my-1 mx-auto w-75"><span class="badge text-bg-warning my-2">Override</span>';
        }

        html += '</div>';
        return html;
    }

    function GameWeatherPage(extension) {
            let widget = {
                dataset: null,
                location_id: null,
                pollMs: 60000,
                timer: null,
                pollKey: 'weather.widget',
                usesPollManager: false,
                init: function () {
                    var self = this;
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
                    let input = $('[name="location_id"]').first();
                    if (!input.length) {
                        return null;
                    }
                    let value = parseInt(input.val(), 10);
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
                    // The navbar script is parsed before page content.
                    // Re-resolve location on each refresh so location-specific meteo works.
                    this.location_id = this.resolveLocationId();
                    let payload = {};
                    if (this.location_id !== null) {
                        payload.location_id = this.location_id;
                    }
                    callWeatherModule('get', payload, function (response) {
                        self.dataset = response;
                        if (null != response) {
                            self.build();
                        }
                    }, function (error) {
                        console.warn('[WeatherPage] get failed', error);
                    });
                },

                build: function () {
                    let blocks = $('[data-weather-widget]');
                    if (!blocks.length) {
                        blocks = $('#location-weather');
                    }
                    if (!blocks.length || !this.dataset) {
                        return;
                    }
                    let dataset = this.dataset;

                    var scopeText = String(dataset.scope || dataset.scope_type || 'auto');
                    var seasonLabel = (dataset.season && dataset.season.name)
                        ? String(dataset.season.name)
                        : 'Stagione n/d';
                    var overrideLabel = (dataset.override_reason && String(dataset.override_reason).trim() !== '')
                        ? ('Override: ' + String(dataset.override_reason).trim())
                        : 'Override meteo attivo';

                    blocks.each(function () {
                        let block = $(this);
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
                            block.find('.p-moonphases').html('<img style="width: 16px;" src="' + dataset.moon.img + '" title="' + dataset.moon.title + '" alt="' + dataset.moon.phase + '"> ' + dataset.moon.title);
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

                    // Update weather navbar mobile popover content with fresh dataset
                    if (window.__weatherNavbarPopover && typeof window.__weatherNavbarPopover.setContent === 'function') {
                        window.__weatherNavbarPopover.setContent({
                            '.popover-body': buildWeatherPopoverContent(dataset)
                        });
                    }

                    return;
                },
                destroy: function () {
                    this.stopPolling();
                    return this;
                },
                unmount: function () {
                    return this.destroy();
                }
            };

            let weather = Object.assign({}, widget, extension);
            return weather.init();
        }

    function GameWeatherStaffPage(extension) {
            let page = {
                location_id: null,
                weatherGroup: null,
                options: null,
                init: function () {
                    if (!$('#location-weather-staff').length) {
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
                    let input = $('[name="location_id"]').first();
                    if (!input.length) {
                        return null;
                    }
                    let value = parseInt(input.val(), 10);
                    if (isNaN(value) || value <= 0) {
                        return null;
                    }
                    return value;
                },
                bind: function () {
                    var self = this;
                    $('#location-weather-staff').off('click.weatherStaff');
                    $('#location-weather-staff').on('click.weatherStaff', '[data-weather-location-save]', function (event) {
                        event.preventDefault();
                        self.save();
                    });
                    $('#location-weather-staff').on('click.weatherStaff', '[data-weather-location-clear]', function (event) {
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
                    let input = $('#weather-location-key');
                    if (!input.length) {
                        return;
                    }

                    let options = [
                        { label: 'Auto', value: 'inherit', style: 'secondary' }
                    ];
                    if (this.options && Array.isArray(this.options.conditions)) {
                        for (let i = 0; i < this.options.conditions.length; i++) {
                            let row = this.options.conditions[i];
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

                    let moon = $('#weather-location-moon');
                    if (moon.length) {
                        moon.empty();
                        moon.append('<option value="">Auto</option>');
                        if (this.options && Array.isArray(this.options.moon_phases)) {
                            for (let i = 0; i < this.options.moon_phases.length; i++) {
                                let row = this.options.moon_phases[i];
                                moon.append('<option value="' + row.phase + '">' + row.title + '</option>');
                            }
                        }
                    }
                },
                loadState: function () {
                    var self = this;
                    var payload = {
                        location_id: this.location_id
                    };
                    callWeatherModule('state', payload, function (response) {
                        if (!response) {
                            return;
                        }
                        self.applyState(response);
                    }, function (error) {
                        console.warn('[WeatherStaff] loadState failed', error);
                    });
                },
                applyState: function (state) {
                    let scope = $('[data-weather-staff-scope]');
                    if (scope.length) {
                        let text = 'Auto';
                        let klass = 'text-bg-secondary';
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
                        } else if (state.scope === 'climate_area') {
                            text = 'Area clima';
                            klass = 'text-bg-info';
                        } else if (state.scope === 'area') {
                            text = 'Area';
                            klass = 'text-bg-info';
                        } else if (state.scope === 'region') {
                            text = 'Regione';
                            klass = 'text-bg-info';
                        }
                        scope.removeClass('text-bg-secondary text-bg-primary text-bg-dark text-bg-info').addClass(klass).text(text);
                    }

                    // Climate area info
                    let areaInfo = document.getElementById('weather-staff-area-info');
                    if (areaInfo) {
                        if (state.climate_area && state.climate_area.name) {
                            areaInfo.style.display = '';
                            let nameEl = areaInfo.querySelector('[data-weather-staff-area-name]');
                            if (nameEl) { nameEl.textContent = state.climate_area.name; }
                        } else {
                            areaInfo.style.display = 'none';
                        }
                    }

                    // Override expiry info
                    let expiresInfo = document.getElementById('weather-staff-expires-info');
                    if (expiresInfo) {
                        if (state.override_expires_at) {
                            expiresInfo.style.display = '';
                            let expiresEl = expiresInfo.querySelector('[data-weather-staff-expires]');
                            if (expiresEl) { expiresEl.textContent = state.override_expires_at; }
                        } else {
                            expiresInfo.style.display = 'none';
                        }
                    }

                    let local = state.location_override_data || null;
                    let key = (local && local.weather_key) ? local.weather_key : 'inherit';
                    $('#weather-location-key').val(key).change();
                    $('[name="weather_location_degrees"]').val((local && local.degrees !== null && local.degrees !== '') ? local.degrees : '');
                    $('[name="weather_location_moon_phase"]').val((local && local.moon_phase) ? local.moon_phase : '');
                    $('[name="weather_location_expires_at"]').val('');
                    $('[name="weather_location_note"]').val((local && local.note) ? local.note : '');
                },
                save: function () {
                    var self = this;
                    var expiresRaw = $('[name="weather_location_expires_at"]').val();
                    var payload = {
                        location_id: this.location_id,
                        weather_key: $('#weather-location-key').val(),
                        degrees: $('[name="weather_location_degrees"]').val(),
                        moon_phase: $('[name="weather_location_moon_phase"]').val(),
                        expires_at: expiresRaw || null,
                        note: $('[name="weather_location_note"]').val() || null
                    };
                    callWeatherModule('setLocation', payload, function (response) {
                        Toast.show({ body: 'Meteo location aggiornato.', type: 'success' });
                        if (response && response.dataset) {
                            self.applyState(response.dataset);
                        } else {
                            self.loadState();
                        }
                        if (typeof window.Weather !== 'undefined' && typeof window.Weather.get === 'function') {
                            window.Weather.get();
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
                    var payload = {
                        location_id: this.location_id
                    };
                    callWeatherModule('clearLocation', payload, function (response) {
                        Toast.show({ body: 'Override meteo rimosso.', type: 'success' });
                        if (response && response.dataset) {
                            self.applyState(response.dataset);
                        } else {
                            self.loadState();
                        }
                        if (typeof window.Weather !== 'undefined' && typeof window.Weather.get === 'function') {
                            window.Weather.get();
                        }
                    }, function (error) {
                        Toast.show({
                            body: normalizeWeatherError(error, 'Errore durante rimozione override meteo.'),
                            type: 'error'
                        });
                    });
                },
                destroy: function () {
                    $('#location-weather-staff').off('click.weatherStaff');
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

            let weatherStaff = Object.assign({}, page, extension);
            return weatherStaff.init();
        }
    window.GameWeatherPage = GameWeatherPage;
    window.GameWeatherStaffPage = GameWeatherStaffPage;
})(window);

