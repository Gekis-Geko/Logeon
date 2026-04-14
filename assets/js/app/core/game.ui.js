(function (window) {
    'use strict';

    function initOnlinesOffcanvas() {
        if (typeof window.GameOnlinesPage === 'function') {
            try {
                window.OnlinesOffcanvas = window.GameOnlinesPage({}, 'smart');
                return window.OnlinesOffcanvas;
            } catch (error) {}
        }

        return null;
    }

    function bindOnlinesOffcanvasUi() {
        if (window.__gameOnlinesOffcanvasUiBound === true) {
            return;
        }

        var visibilitySwitchSync = false;

        function initVisibilitySwitch() {
            var input = $('#staff-visibility-switch');
            if (!input.length || typeof window.SwitchGroup !== 'function') {
                return null;
            }

            if (window.StaffVisibilitySwitch && typeof window.StaffVisibilitySwitch.destroy === 'function') {
                window.StaffVisibilitySwitch.destroy();
            }

            window.StaffVisibilitySwitch = window.SwitchGroup(input, {
                trueLabel: '<span class="bi bi-eye-fill" data-bs-toggle="tooltip" data-bs-title="Visibile"></span>',
                falseLabel: '<span class="bi bi-eye-slash-fill text-muted" data-bs-toggle="tooltip" data-bs-title="Invisibile"></span>',
                trueLabelIsHtml: true,
                falseLabelIsHtml: true,
                trueValue: '1',
                falseValue: '0',
                defaultValue: '1'
            });
            if (window.GameGlobals && typeof window.GameGlobals.initTooltips === 'function') {
                window.GameGlobals.initTooltips(document.getElementById('offcanvasOnline'));
            } else if (typeof window.initTooltips === 'function') {
                window.initTooltips(document.getElementById('offcanvasOnline'));
            }

            input.off('change.game-onlines-visibility-switch').on('change.game-onlines-visibility-switch', function () {
                if (visibilitySwitchSync) {
                    return;
                }
                saveVisibilityState($(this).val());
            });

            return window.StaffVisibilitySwitch;
        }

        function setVisibilitySwitch(isVisible, silent) {
            var input = $('#staff-visibility-switch');
            if (!input.length) {
                return;
            }

            var value = (parseInt(isVisible, 10) === 1) ? '1' : '0';
            visibilitySwitchSync = (silent === true);
            input.val(value).change();
            visibilitySwitchSync = false;
        }

        function loadVisibilityState() {
            if (!$('#staff-visibility-switch').length) {
                return;
            }
            if (typeof Request !== 'function') {
                return;
            }
            if (!Request.http || typeof Request.http.post !== 'function') {
                return;
            }
            Request.http.post('/profile/visibility', { mode: 'get' }).then(function (response) {
                if (response && response.success && response.is_visible !== undefined) {
                    setVisibilitySwitch(response.is_visible, true);
                }
            }).catch(function () {});
        }

        function saveVisibilityState(nextRaw) {
            var next = (parseInt(nextRaw, 10) === 1) ? 1 : 0;
            if (typeof Request !== 'function') {
                return;
            }
            if (!Request.http || typeof Request.http.post !== 'function') {
                return;
            }
            var onVisibilitySaved = function (response) {
                if (response && response.success) {
                    setVisibilitySwitch(response.is_visible, true);
                    Toast.show({
                        body: response.is_visible == 1 ? 'Ora sei visibile.' : 'Ora sei invisibile.',
                        type: 'success'
                    });
                }
            };
            Request.http.post('/profile/visibility', { is_visible: next }).then(onVisibilitySaved).catch(function () {
                loadVisibilityState();
            });
        }

        $('#offcanvasOnline').off('shown.bs.offcanvas.game-onlines');
        $('#offcanvasOnline').on('shown.bs.offcanvas.game-onlines', function () {
            initOnlinesOffcanvas();
            initVisibilitySwitch();
            loadVisibilityState();
        });

        window.__gameOnlinesOffcanvasUiBound = true;
    }

    function bindNavbarUi() {
        if (window.__gameNavbarUiBound === true) {
            return;
        }

        if (typeof window.Navbar === 'function' && document.getElementById('appNavbar')) {
            window.Navbar('#appNavbar');
        }

        // Weather mobile popover — content is updated by WeatherPage.js build() via window.__weatherNavbarPopover
        var weatherPopoverEl = document.getElementById('weatherNavbarPopoverBtn');
        if (weatherPopoverEl && typeof window.bootstrap !== 'undefined' && window.bootstrap.Popover) {
            var existingWeatherPopover = window.bootstrap.Popover.getInstance(weatherPopoverEl);
            if (existingWeatherPopover) { existingWeatherPopover.dispose(); }

            window.__weatherNavbarPopover = new window.bootstrap.Popover(weatherPopoverEl, {
                trigger: 'click',
                placement: 'bottom',
                html: true,
                sanitize: false,
                content: '&nbsp;'
            });

            $(document).off('click.weather-navbar-popover').on('click.weather-navbar-popover', function (e) {
                if (!$(e.target).closest('#weatherNavbarPopoverBtn, .popover').length) {
                    window.__weatherNavbarPopover.hide();
                }
            });
        }

        $(document).off('click.game-nav-actions');
        $(document).on('click.game-nav-actions', '[data-action="app-reload"]', function (event) {
            event.preventDefault();
            if (window.RuntimeBootstrap && typeof window.RuntimeBootstrap.restartGameRuntime === 'function') {
                var restarted = window.RuntimeBootstrap.restartGameRuntime({ stop: true }) === true;
                if (restarted) {
                    return;
                }
            }
            if (window.GameRuntime && typeof window.GameRuntime.stop === 'function' && typeof window.GameRuntime.start === 'function') {
                window.GameRuntime.stop();
                window.GameRuntime.start();
                return;
            }
            if (window.GameGlobals && typeof window.GameGlobals.sync === 'function') {
                window.GameGlobals.sync();
            }
        });
        $(document).on('click.game-nav-actions', '[data-action="open-inbox"]', function (event) {
            event.preventDefault();
            if (typeof window.inboxModal !== 'undefined' && window.inboxModal && typeof window.inboxModal.show === 'function') {
                window.inboxModal.show();
            }
        });
        $(document).on('click.game-nav-actions', '[data-action="open-news"]', function (event) {
            event.preventDefault();
            if (typeof window.newsModal !== 'undefined' && window.newsModal && typeof window.newsModal.show === 'function') {
                window.newsModal.show();
            }
        });
        $(document).on('click.game-nav-actions', '[data-action="open-rules"]', function (event) {
            event.preventDefault();
            if (typeof window.rulesModal !== 'undefined' && window.rulesModal && typeof window.rulesModal.show === 'function') {
                window.rulesModal.show();
            }
        });
        $(document).on('click.game-nav-actions', '[data-action="open-storyboards"]', function (event) {
            event.preventDefault();
            if (typeof window.storyboardsModal !== 'undefined' && window.storyboardsModal && typeof window.storyboardsModal.show === 'function') {
                window.storyboardsModal.show();
            }
        });
        $(document).on('click.game-nav-actions', '[data-action="open-how-to-play"]', function (event) {
            event.preventDefault();
            if (typeof window.howToPlayModal !== 'undefined' && window.howToPlayModal && typeof window.howToPlayModal.show === 'function') {
                window.howToPlayModal.show();
            }
        });
        $(document).on('click.game-nav-actions', '[data-action="auth-signout"]', function (event) {
            event.preventDefault();
            if (typeof window.Auth === 'function') {
                window.Auth('signout', '/signout', '/');
            }
        });

        function updateAvailabilityIndicator(value) {
            var indicator = $('[name="availability_self_indicator"]');
            if (!indicator.length) {
                return;
            }
            var statusText = 'Disponibile';
            var statusClass = 'text-success';
            switch (parseInt(value, 10)) {
                case 0:
                    statusText = 'Occupato';
                    statusClass = 'text-danger';
                    break;
                case 2:
                    statusText = 'Non al PC';
                    statusClass = 'text-warning';
                    break;
                case 3:
                    statusText = 'Ricerca di gioco';
                    statusClass = 'text-info';
                    break;
            }
            indicator.removeClass('text-success text-danger text-warning text-info').addClass(statusClass);
            indicator.attr('data-bs-title', statusText);
            if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
                var instance = bootstrap.Tooltip.getInstance(indicator[0]);
                if (instance) {
                    instance.setContent({ '.tooltip-inner': statusText });
                }
            }
        }
        window.updateAvailabilityIndicator = updateAvailabilityIndicator;

        function syncAvailabilityRemote(value) {
            var payload = { availability: value };
            var requestFallback = function () {
                if (typeof Request !== 'function') {
                    return;
                }
                if (!Request.http || typeof Request.http.post !== 'function') {
                    return;
                }
                Request.http.post('/profile/availability', payload).catch(function () {});
            };

            try {
                var runtime = window.GameApp || window.AppRuntime || window.AdminApp;
                if (!runtime || !runtime.registry) {
                    requestFallback();
                    return;
                }

                var mod = (typeof runtime.registry.get === 'function') ? runtime.registry.get('game.presence') : null;
                if (!mod && typeof runtime.registry.mount === 'function') {
                    mod = runtime.registry.mount('game.presence', {});
                }
                if (!mod || typeof mod.setAvailability !== 'function') {
                    requestFallback();
                    return;
                }

                mod.setAvailability(payload).catch(function () {
                    requestFallback();
                });
            } catch (error) {
                requestFallback();
            }
        }

        if (typeof RadioGroup === 'function') {
            if (window.AvailabilityNavControl && typeof window.AvailabilityNavControl.destroy === 'function') {
                window.AvailabilityNavControl.destroy();
            }

            var availabilityGroups = [];
            var availabilitySelectors = ['#availability-navbar', '#availability-navbar-mobile'];

            function onAvailabilitySelect(value) {
                Storage().set('characterAvailability', value);
                Storage().set('availability_last_activity', Date.now());
                Storage().set('availability_last_sync', Date.now());
                Storage().unset('availability_auto_idle');
                updateAvailabilityIndicator(value);
                syncAvailabilityRemote(value);
                for (var si = 0; si < availabilityGroups.length; si++) {
                    var ag = availabilityGroups[si];
                    if (ag && ag.input && typeof ag.input.val === 'function' && ag.input.val() !== value) {
                        ag.input.val(value).change();
                    }
                }
            }

            for (var ai = 0; ai < availabilitySelectors.length; ai++) {
                if (document.querySelector(availabilitySelectors[ai])) {
                    availabilityGroups.push(RadioGroup(availabilitySelectors[ai], {
                        btnClass: 'btn-sm',
                        groupClass: 'w-100',
                        vertical: true,
                        options: [
                            { label: 'Disponibile', value: '1', style: 'success' },
                            { label: 'Occupato', value: '0', style: 'danger' },
                            { label: 'Non al PC', value: '2', style: 'warning' },
                            { label: 'Ricerca di gioco', value: '3', style: 'info' }
                        ],
                        onSelect: onAvailabilitySelect
                    }));
                }
            }

            if (availabilityGroups.length) {
                window.AvailabilityNavControl = {
                    input: {
                        val: function (value) {
                            if (arguments.length === 0) {
                                return availabilityGroups[0] && availabilityGroups[0].input ? availabilityGroups[0].input.val() : '';
                            }
                            for (var ji = 0; ji < availabilityGroups.length; ji++) {
                                if (availabilityGroups[ji] && availabilityGroups[ji].input && typeof availabilityGroups[ji].input.val === 'function') {
                                    availabilityGroups[ji].input.val(value);
                                }
                            }
                            return this;
                        },
                        change: function () {
                            for (var ji = 0; ji < availabilityGroups.length; ji++) {
                                if (availabilityGroups[ji] && availabilityGroups[ji].input && typeof availabilityGroups[ji].input.change === 'function') {
                                    availabilityGroups[ji].input.change();
                                }
                            }
                            return this;
                        }
                    },
                    destroy: function () {
                        for (var ji = 0; ji < availabilityGroups.length; ji++) {
                            if (availabilityGroups[ji] && typeof availabilityGroups[ji].destroy === 'function') {
                                availabilityGroups[ji].destroy();
                            }
                        }
                        availabilityGroups = [];
                        window.AvailabilityNavControl = undefined;
                    }
                };

                var storedAvailability = Storage().get('characterAvailability');
                if (storedAvailability !== null && storedAvailability !== undefined && storedAvailability !== '') {
                    window.AvailabilityNavControl.input.val(storedAvailability).change();
                    updateAvailabilityIndicator(storedAvailability);
                }
            }
        }

        window.__gameNavbarUiBound = true;
    }

    function unbindNavbarUi() {
        if (typeof window.$ !== 'undefined') {
            window.$(document).off('click.game-nav-actions');
        }
        if (window.AvailabilityNavControl && typeof window.AvailabilityNavControl.destroy === 'function') {
            window.AvailabilityNavControl.destroy();
        }
        window.AvailabilityNavControl = undefined;
        window.__gameNavbarUiBound = false;
    }

    function unbindOnlinesOffcanvasUi() {
        if (typeof window.$ !== 'undefined') {
            window.$('#staff-visibility-switch').off('change.game-onlines-visibility-switch');
            window.$('#offcanvasOnline').off('shown.bs.offcanvas.game-onlines');
        }
        if (window.StaffVisibilitySwitch && typeof window.StaffVisibilitySwitch.destroy === 'function') {
            window.StaffVisibilitySwitch.destroy();
        }
        window.StaffVisibilitySwitch = undefined;
        if (window.OnlinesOffcanvas && typeof window.OnlinesOffcanvas.destroy === 'function') {
            window.OnlinesOffcanvas.destroy();
        }
        window.OnlinesOffcanvas = undefined;
        window.__gameOnlinesOffcanvasUiBound = false;
    }

    function bind() {
        bindNavbarUi();
        bindOnlinesOffcanvasUi();
    }

    function unbind() {
        unbindNavbarUi();
        unbindOnlinesOffcanvasUi();
    }

    window.GameUi = window.GameUi || {};
    window.GameUi.bind = bind;
    window.GameUi.unbind = unbind;
    window.GameUi.bindNavbarUi = bindNavbarUi;
    window.GameUi.unbindNavbarUi = unbindNavbarUi;
    window.GameUi.bindOnlinesOffcanvasUi = bindOnlinesOffcanvasUi;
    window.GameUi.unbindOnlinesOffcanvasUi = unbindOnlinesOffcanvasUi;
    window.GameUi.initOnlinesOffcanvas = initOnlinesOffcanvas;
})(window);
