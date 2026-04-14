(function (window) {
    'use strict';

    function createAdminItemsCategoriesModule() {
        return {
            mount: function () {
                if (typeof window.AdminItemsCategories !== 'undefined'
                    && window.AdminItemsCategories
                    && typeof window.AdminItemsCategories.init === 'function') {
                    window.AdminItemsCategories.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminItemsCategoriesModuleFactory = createAdminItemsCategoriesModule;
})(window);
