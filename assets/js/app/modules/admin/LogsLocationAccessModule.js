(function (window) {
    'use strict';

    function createAdminLogsLocationAccessModule() {
        return {
            mount: function () {
                if (typeof window.AdminLogsLocationAccess !== 'undefined'
                    && window.AdminLogsLocationAccess
                    && typeof window.AdminLogsLocationAccess.init === 'function') {
                    window.AdminLogsLocationAccess.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminLogsLocationAccessModuleFactory = createAdminLogsLocationAccessModule;
})(window);
