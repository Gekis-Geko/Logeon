(function (window) {
    'use strict';

    function createSystemEventsModule() {
        return {
            mount: function () {
                if (typeof window.AdminSystemEvents !== 'undefined'
                    && window.AdminSystemEvents
                    && typeof window.AdminSystemEvents.init === 'function') {
                    window.AdminSystemEvents.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminSystemEventsModuleFactory = createSystemEventsModule;
})(window);
