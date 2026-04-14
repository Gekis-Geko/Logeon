(function (window) {
    'use strict';

    function createAdminShopsModule() {
        return {
            mount: function () {
                if (typeof window.AdminShops !== 'undefined'
                    && window.AdminShops
                    && typeof window.AdminShops.init === 'function') {
                    window.AdminShops.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminShopsModuleFactory = createAdminShopsModule;
})(window);
