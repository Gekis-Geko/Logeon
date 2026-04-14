(function (window) {
    'use strict';

    function createAdminGuildsModule() {
        return {
            mount: function () {
                if (typeof window.AdminGuilds !== 'undefined'
                    && window.AdminGuilds
                    && typeof window.AdminGuilds.init === 'function') {
                    window.AdminGuilds.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminGuildsModuleFactory = createAdminGuildsModule;
})(window);
