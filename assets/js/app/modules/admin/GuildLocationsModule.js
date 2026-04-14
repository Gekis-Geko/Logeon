(function (window) {
    'use strict';

    function createAdminGuildLocationsModule() {
        return {
            mount: function () {
                if (typeof window.AdminGuildLocations !== 'undefined'
                    && window.AdminGuildLocations
                    && typeof window.AdminGuildLocations.init === 'function') {
                    window.AdminGuildLocations.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminGuildLocationsModuleFactory = createAdminGuildLocationsModule;
})(window);
