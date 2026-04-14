(function (window) {
    'use strict';

    function createAdminLogsCurrencyModule() {
        return {
            mount: function () {
                if (typeof window.AdminLogsCurrency !== 'undefined'
                    && window.AdminLogsCurrency
                    && typeof window.AdminLogsCurrency.init === 'function') {
                    window.AdminLogsCurrency.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminLogsCurrencyModuleFactory = createAdminLogsCurrencyModule;
})(window);
