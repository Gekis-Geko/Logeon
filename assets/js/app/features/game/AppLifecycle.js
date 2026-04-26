const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function restartRuntime(options) {
    if (globalWindow.RuntimeBootstrap && typeof globalWindow.RuntimeBootstrap.restartGameRuntime === 'function') {
        return globalWindow.RuntimeBootstrap.restartGameRuntime(options || {}) === true;
    }
    return false;
}

function GameAppInit() {
        if (globalWindow.APP_BOOTSTRAP_ENABLED === true && globalWindow.APP_BOOTSTRAP_RUNTIME === 'game') {
            if (restartRuntime({ stop: false }) === true) {
                return this;
            }
            if (globalWindow.GameRuntime && typeof globalWindow.GameRuntime.start === 'function') {
                globalWindow.GameRuntime.start();
                return this;
            }
            if (globalWindow.GameGlobals && typeof globalWindow.GameGlobals.sync === 'function') {
                globalWindow.GameGlobals.sync();
            }
            return this;
        }

        if (typeof EventBus === 'function' && typeof globalWindow.AppEvents === 'undefined') {
            globalWindow.AppEvents = EventBus();
        }
        if (typeof PollManager === 'function') {
            PollManager();
        }

        var tooltipService = null;
        if (globalWindow.GameGlobals && typeof globalWindow.GameGlobals.resolveTooltipService === 'function') {
            tooltipService = globalWindow.GameGlobals.resolveTooltipService();
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
            if (globalWindow.GameGlobals && typeof globalWindow.GameGlobals.initTooltips === 'function') {
                globalWindow.GameGlobals.initTooltips(document);
            } else if (typeof globalWindow.initTooltips === 'function') {
                globalWindow.initTooltips(document);
            }
            if (!globalWindow.__app_tooltip_guard_bound) {
                globalWindow.__app_tooltip_guard_bound = true;

                $(document).on('click.app_tooltip', function () {
                    if (globalWindow.GameGlobals && typeof globalWindow.GameGlobals.hideOpenTooltips === 'function') {
                        globalWindow.GameGlobals.hideOpenTooltips();
                    } else if (typeof globalWindow.hideOpenTooltips === 'function') {
                        globalWindow.hideOpenTooltips();
                    }
                });

                $(document).on('shown.bs.modal.app_tooltip hidden.bs.modal.app_tooltip shown.bs.offcanvas.app_tooltip hidden.bs.offcanvas.app_tooltip', function () {
                    if (globalWindow.GameGlobals && typeof globalWindow.GameGlobals.hideOpenTooltips === 'function') {
                        globalWindow.GameGlobals.hideOpenTooltips();
                    } else if (typeof globalWindow.hideOpenTooltips === 'function') {
                        globalWindow.hideOpenTooltips();
                    }
                });
            }
        }

        Navbar('#appNavbar');       
        if (globalWindow.GameGlobals && typeof globalWindow.GameGlobals.initSummernote === 'function') {
            globalWindow.GameGlobals.initSummernote(document);
        } else if (globalWindow.TipTapEditor && typeof globalWindow.TipTapEditor.init === 'function') {
            globalWindow.TipTapEditor.init(document);
        }

        if ($('#inbox-modal').length) {
            this.Messages({ key: 'modal', root: '#inbox-modal' }).loadUnread();
        }
        if ($('#location-invite-modal').length && typeof globalWindow.LocationInvites === 'undefined') {
            globalWindow.LocationInvites = this.LocationInvites();
        }
        if (typeof globalWindow.AvailabilityObserver === 'undefined' && Storage().get('characterId')) {
            globalWindow.AvailabilityObserver = this.AvailabilityObserver();
        }

        return this;
    }

function GameAppSync() {
        if (globalWindow.APP_BOOTSTRAP_ENABLED === true && globalWindow.APP_BOOTSTRAP_RUNTIME === 'game') {
            var synced = restartRuntime({ stop: true }) === true;
            if (!synced && globalWindow.GameRuntime && typeof globalWindow.GameRuntime.stop === 'function' && typeof globalWindow.GameRuntime.start === 'function') {
                globalWindow.GameRuntime.stop();
                globalWindow.GameRuntime.start();
                synced = true;
            } else if (!synced && globalWindow.GameGlobals && typeof globalWindow.GameGlobals.sync === 'function') {
                globalWindow.GameGlobals.sync();
                synced = true;
            }

            if (synced && typeof globalWindow.AppEvents !== 'undefined' && typeof globalWindow.AppEvents.emit === 'function') {
                globalWindow.AppEvents.emit('app:sync');
            }
            return this;
        }

        this.init();
        if (typeof globalWindow.AppEvents !== 'undefined' && typeof globalWindow.AppEvents.emit === 'function') {
            globalWindow.AppEvents.emit('app:sync');
        }
    }

function GameAppReload() {
        Toast.show({
            body: '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Aggiornamento in corso ...',
        });

        if (globalWindow.APP_BOOTSTRAP_ENABLED === true && globalWindow.APP_BOOTSTRAP_RUNTIME === 'game') {
            var reloaded = restartRuntime({ stop: true }) === true;
            if (!reloaded && globalWindow.GameRuntime && typeof globalWindow.GameRuntime.stop === 'function' && typeof globalWindow.GameRuntime.start === 'function') {
                globalWindow.GameRuntime.stop();
                globalWindow.GameRuntime.start();
                reloaded = true;
            } else if (!reloaded && globalWindow.GameGlobals && typeof globalWindow.GameGlobals.sync === 'function') {
                globalWindow.GameGlobals.sync();
                reloaded = true;
            }

            if (reloaded && typeof globalWindow.AppEvents !== 'undefined' && typeof globalWindow.AppEvents.emit === 'function') {
                globalWindow.AppEvents.emit('app:reload');
            }
            return this;
        }

        this.init();
        if (typeof globalWindow.AppEvents !== 'undefined' && typeof globalWindow.AppEvents.emit === 'function') {
            globalWindow.AppEvents.emit('app:reload');
        }
    }

globalWindow.GameAppInit = GameAppInit;
globalWindow.GameAppSync = GameAppSync;
globalWindow.GameAppReload = GameAppReload;
export { GameAppInit as GameAppInit };
export { GameAppSync as GameAppSync };
export { GameAppReload as GameAppReload };
const GameAppLifecycleApi = { GameAppInit, GameAppSync, GameAppReload };
export default GameAppLifecycleApi;

