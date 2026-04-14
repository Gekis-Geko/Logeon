(function (window) {
    'use strict';

    function createNarrativeEventsModule() {
        return {
            mount: function () {
                if (typeof window.AdminNarrativeEvents !== 'undefined'
                    && window.AdminNarrativeEvents
                    && typeof window.AdminNarrativeEvents.init === 'function') {
                    window.AdminNarrativeEvents.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminNarrativeEventsModuleFactory = createNarrativeEventsModule;
})(window);
