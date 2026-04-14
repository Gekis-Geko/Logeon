(function (window) {
    'use strict';

    function createAdminGuildReqsModule() {
        return {
            mount: function () {
                if (typeof window.AdminGuildReqs !== 'undefined'
                    && window.AdminGuildReqs
                    && typeof window.AdminGuildReqs.init === 'function') {
                    window.AdminGuildReqs.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminGuildReqsModuleFactory = createAdminGuildReqsModule;
})(window);
