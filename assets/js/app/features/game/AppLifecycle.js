(function (window) {
    'use strict';

    function restartRuntime(options) {
        if (window.RuntimeBootstrap && typeof window.RuntimeBootstrap.restartGameRuntime === 'function') {
            return window.RuntimeBootstrap.restartGameRuntime(options || {}) === true;
        }
        return false;
    }

    function GameAppInit() {
            if (window.APP_BOOTSTRAP_ENABLED === true && window.APP_BOOTSTRAP_RUNTIME === 'game') {
                if (restartRuntime({ stop: false }) === true) {
                    return this;
                }
                if (window.GameRuntime && typeof window.GameRuntime.start === 'function') {
                    window.GameRuntime.start();
                    return this;
                }
                if (window.GameGlobals && typeof window.GameGlobals.sync === 'function') {
                    window.GameGlobals.sync();
                }
                return this;
            }

            if (typeof EventBus === 'function' && typeof window.AppEvents === 'undefined') {
                window.AppEvents = EventBus();
            }
            if (typeof PollManager === 'function') {
                PollManager();
            }

            var tooltipService = null;
            if (window.GameGlobals && typeof window.GameGlobals.resolveTooltipService === 'function') {
                tooltipService = window.GameGlobals.resolveTooltipService();
            } else if (typeof Tooltip === 'function') {
                tooltipService = Tooltip();
            }

            if (tooltipService) {
                if (typeof tooltipService.init === 'function') {
                    tooltipService.init(document);
                }
                if (typeof tooltipService.bindGlobalGuards === 'function') {
                    tooltipService.bindGlobalGuards();
                }
            } else {
                if (window.GameGlobals && typeof window.GameGlobals.initTooltips === 'function') {
                    window.GameGlobals.initTooltips(document);
                } else if (typeof window.initTooltips === 'function') {
                    window.initTooltips(document);
                }
                if (!window.__app_tooltip_guard_bound) {
                    window.__app_tooltip_guard_bound = true;

                    $(document).on('click.app_tooltip', function () {
                        if (window.GameGlobals && typeof window.GameGlobals.hideOpenTooltips === 'function') {
                            window.GameGlobals.hideOpenTooltips();
                        } else if (typeof window.hideOpenTooltips === 'function') {
                            window.hideOpenTooltips();
                        }
                    });

                    $(document).on('shown.bs.modal.app_tooltip hidden.bs.modal.app_tooltip shown.bs.offcanvas.app_tooltip hidden.bs.offcanvas.app_tooltip', function () {
                        if (window.GameGlobals && typeof window.GameGlobals.hideOpenTooltips === 'function') {
                            window.GameGlobals.hideOpenTooltips();
                        } else if (typeof window.hideOpenTooltips === 'function') {
                            window.hideOpenTooltips();
                        }
                    });
                }
            }

            Navbar('#appNavbar');       
            if (window.GameGlobals && typeof window.GameGlobals.patchSummernoteAutoLink === 'function') {
                window.GameGlobals.patchSummernoteAutoLink();
            }
            $('.summernote').summernote({
                lang: 'it-IT',
                height: 250,
                icons: {
                    bold: 'bi bi-type-bold',
                    italic: 'bi bi-type-italic',
                    underline: 'bi bi-type-underline',
                    unorderedlist: 'bi bi-list-ul',
                    orderedlist: 'bi bi-list-ol',
                    paragraph: 'bi bi-text-paragraph',
                    table: 'bi bi-table',
                    link: 'bi bi-link-45deg',
                    picture: 'bi bi-image',
                    caret: 'bi bi-caret-down-fill'
                },
                toolbar: [
                    ['font', ['bold', 'underline', 'italic']],
                    ['para', ['ul', 'ol', 'paragraph']],
                    ['table', ['table']],
                    ['insert', ['link', 'picture']]
                  ],
                callbacks: {
                    onInit: function () {
                        $(this).next('.note-editor').addClass('ui-richtext');
                    }
                }
            });

            if ($('#inbox-modal').length) {
                this.Messages({ key: 'modal', root: '#inbox-modal' }).loadUnread();
            }
            if ($('#location-invite-modal').length && typeof window.LocationInvites === 'undefined') {
                window.LocationInvites = this.LocationInvites();
            }
            if (typeof window.AvailabilityObserver === 'undefined' && Storage().get('characterId')) {
                window.AvailabilityObserver = this.AvailabilityObserver();
            }

            return this;
        }

    function GameAppSync() {
            if (window.APP_BOOTSTRAP_ENABLED === true && window.APP_BOOTSTRAP_RUNTIME === 'game') {
                var synced = restartRuntime({ stop: true }) === true;
                if (!synced && window.GameRuntime && typeof window.GameRuntime.stop === 'function' && typeof window.GameRuntime.start === 'function') {
                    window.GameRuntime.stop();
                    window.GameRuntime.start();
                    synced = true;
                } else if (!synced && window.GameGlobals && typeof window.GameGlobals.sync === 'function') {
                    window.GameGlobals.sync();
                    synced = true;
                }

                if (synced && typeof window.AppEvents !== 'undefined' && typeof window.AppEvents.emit === 'function') {
                    window.AppEvents.emit('app:sync');
                }
                return this;
            }

            this.init();
            if (typeof window.AppEvents !== 'undefined' && typeof window.AppEvents.emit === 'function') {
                window.AppEvents.emit('app:sync');
            }
        }

    function GameAppReload() {
            Toast.show({
                body: '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Aggiornamento in corso ...',
            });

            if (window.APP_BOOTSTRAP_ENABLED === true && window.APP_BOOTSTRAP_RUNTIME === 'game') {
                var reloaded = restartRuntime({ stop: true }) === true;
                if (!reloaded && window.GameRuntime && typeof window.GameRuntime.stop === 'function' && typeof window.GameRuntime.start === 'function') {
                    window.GameRuntime.stop();
                    window.GameRuntime.start();
                    reloaded = true;
                } else if (!reloaded && window.GameGlobals && typeof window.GameGlobals.sync === 'function') {
                    window.GameGlobals.sync();
                    reloaded = true;
                }

                if (reloaded && typeof window.AppEvents !== 'undefined' && typeof window.AppEvents.emit === 'function') {
                    window.AppEvents.emit('app:reload');
                }
                return this;
            }

            this.init();
            if (typeof window.AppEvents !== 'undefined' && typeof window.AppEvents.emit === 'function') {
                window.AppEvents.emit('app:reload');
            }
        }

    window.GameAppInit = GameAppInit;
    window.GameAppSync = GameAppSync;
    window.GameAppReload = GameAppReload;
})(window);
