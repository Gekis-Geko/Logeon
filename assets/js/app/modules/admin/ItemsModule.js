(function (window) {
    'use strict';

    function createAdminItemsModule() {
        return {
            mount: function () {
                if (typeof window.AdminItems !== 'undefined'
                    && window.AdminItems
                    && typeof window.AdminItems.init === 'function') {
                    window.AdminItems.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminItemsModuleFactory = createAdminItemsModule;
})(window);
