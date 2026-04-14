(function (window) {
    'use strict';

    function createAdminLogsJobModule() {
        return {
            mount: function () {
                if (typeof window.AdminLogsJob !== 'undefined'
                    && window.AdminLogsJob
                    && typeof window.AdminLogsJob.init === 'function') {
                    window.AdminLogsJob.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminLogsJobModuleFactory = createAdminLogsJobModule;
})(window);
