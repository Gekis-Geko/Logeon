(function (window) {
    'use strict';

    function createAdminCharacterRequestsModule() {
        return {
            mount: function () {
                if (typeof window.AdminCharacterRequests !== 'undefined'
                    && window.AdminCharacterRequests
                    && typeof window.AdminCharacterRequests.init === 'function') {
                    window.AdminCharacterRequests.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminCharacterRequestsModuleFactory = createAdminCharacterRequestsModule;
})(window);
