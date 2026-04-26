const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createSystemEventsModule() {
    return {
        mount: function () {
            if (typeof globalWindow.AdminSystemEvents !== 'undefined'
                && globalWindow.AdminSystemEvents
                && typeof globalWindow.AdminSystemEvents.init === 'function') {
                globalWindow.AdminSystemEvents.init();
            }
        },
        unmount: function () {}
    };
}

globalWindow.AdminSystemEventsModuleFactory = createSystemEventsModule;
export { createSystemEventsModule as AdminSystemEventsModuleFactory };
export default createSystemEventsModule;

