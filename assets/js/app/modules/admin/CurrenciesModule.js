(function (window) {
    'use strict';

    function createAdminCurrenciesModule() {
        return {
            mount: function () {
                if (typeof window.AdminCurrencies !== 'undefined'
                    && window.AdminCurrencies
                    && typeof window.AdminCurrencies.init === 'function') {
                    window.AdminCurrencies.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminCurrenciesModuleFactory = createAdminCurrenciesModule;
})(window);
