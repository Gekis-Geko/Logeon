(function (window) {
    'use strict';

    function createAdminLogsConflictsModule() {
        return {
            mount: function () {
                if (typeof window.AdminLogsConflicts !== 'undefined'
                    && window.AdminLogsConflicts
                    && typeof window.AdminLogsConflicts.init === 'function') {
                    window.AdminLogsConflicts.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminLogsConflictsModuleFactory = createAdminLogsConflictsModule;
})(window);
