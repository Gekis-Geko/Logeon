(function (window) {
    'use strict';

    function createAdminGuildEventsModule() {
        return {
            mount: function () {
                if (typeof window.AdminGuildEvents !== 'undefined'
                    && window.AdminGuildEvents
                    && typeof window.AdminGuildEvents.init === 'function') {
                    window.AdminGuildEvents.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminGuildEventsModuleFactory = createAdminGuildEventsModule;
})(window);
