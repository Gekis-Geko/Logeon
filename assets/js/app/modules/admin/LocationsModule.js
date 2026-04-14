(function (window) {
    'use strict';

    function createAdminLocationsModule() {
        return {
            mount: function () {
                if (typeof window.AdminLocations !== 'undefined'
                    && window.AdminLocations
                    && typeof window.AdminLocations.init === 'function') {
                    window.AdminLocations.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminLocationsModuleFactory = createAdminLocationsModule;
})(window);
