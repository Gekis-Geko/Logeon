(function (window) {
    'use strict';

    function createAdminItemsRaritiesModule() {
        return {
            mount: function () {
                if (typeof window.AdminItemsRarities !== 'undefined'
                    && window.AdminItemsRarities
                    && typeof window.AdminItemsRarities.init === 'function') {
                    window.AdminItemsRarities.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminItemsRaritiesModuleFactory = createAdminItemsRaritiesModule;
})(window);
