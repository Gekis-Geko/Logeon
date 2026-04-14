(function (window) {
    'use strict';

    function createAdminLogsSysModule() {
        return {
            mount: function () {
                if (typeof window.AdminLogsSys !== 'undefined'
                    && window.AdminLogsSys
                    && typeof window.AdminLogsSys.init === 'function') {
                    window.AdminLogsSys.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminLogsSysModuleFactory = createAdminLogsSysModule;
})(window);
