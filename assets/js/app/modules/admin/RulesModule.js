(function (window) {
    'use strict';

    function createAdminRulesModule() {
        return {
            mount: function () {
                if (typeof window.AdminRules !== 'undefined'
                    && window.AdminRules
                    && typeof window.AdminRules.init === 'function') {
                    window.AdminRules.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminRulesModuleFactory = createAdminRulesModule;
})(window);
