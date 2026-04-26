const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createNarrativeEventsModule() {
    return {
        mount: function () {
            if (typeof globalWindow.AdminNarrativeEvents !== 'undefined'
                && globalWindow.AdminNarrativeEvents
                && typeof globalWindow.AdminNarrativeEvents.init === 'function') {
                globalWindow.AdminNarrativeEvents.init();
            }
        },
        unmount: function () {}
    };
}

globalWindow.AdminNarrativeEventsModuleFactory = createNarrativeEventsModule;
export { createNarrativeEventsModule as AdminNarrativeEventsModuleFactory };
export default createNarrativeEventsModule;

