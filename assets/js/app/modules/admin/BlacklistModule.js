(function (window) {
    'use strict';

    function createAdminBlacklistModule() {
        return {
            mount: function () {
                if (typeof window.AdminBlacklist !== 'undefined'
                    && window.AdminBlacklist
                    && typeof window.AdminBlacklist.init === 'function') {
                    window.AdminBlacklist.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminBlacklistModuleFactory = createAdminBlacklistModule;
})(window);
