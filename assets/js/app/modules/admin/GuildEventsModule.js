const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createAdminGuildEventsModule() {
    return {
        mount: function () {
            if (typeof globalWindow.AdminGuildEvents !== 'undefined'
                && globalWindow.AdminGuildEvents
                && typeof globalWindow.AdminGuildEvents.init === 'function') {
                globalWindow.AdminGuildEvents.init();
            }
        },
        unmount: function () {}
    };
}

globalWindow.AdminGuildEventsModuleFactory = createAdminGuildEventsModule;
export { createAdminGuildEventsModule as AdminGuildEventsModuleFactory };
export default createAdminGuildEventsModule;

