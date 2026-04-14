(function (window) {
    'use strict';

    function createAdminShopInventoryModule() {
        return {
            mount: function () {
                if (typeof window.AdminShopInventory !== 'undefined'
                    && window.AdminShopInventory
                    && typeof window.AdminShopInventory.init === 'function') {
                    window.AdminShopInventory.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminShopInventoryModuleFactory = createAdminShopInventoryModule;
})(window);
