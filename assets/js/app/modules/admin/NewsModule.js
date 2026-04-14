(function (window) {
    'use strict';

    function createAdminNewsModule() {
        return {
            mount: function () {
                if (typeof window.AdminNews !== 'undefined'
                    && window.AdminNews
                    && typeof window.AdminNews.init === 'function') {
                    window.AdminNews.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminNewsModuleFactory = createAdminNewsModule;
})(window);
