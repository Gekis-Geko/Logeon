(function (window) {
    'use strict';

    function createAdminLogsFameModule() {
        return {
            mount: function () {
                if (typeof window.AdminLogsFame !== 'undefined'
                    && window.AdminLogsFame
                    && typeof window.AdminLogsFame.init === 'function') {
                    window.AdminLogsFame.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminLogsFameModuleFactory = createAdminLogsFameModule;
})(window);
