(function (window) {
    'use strict';

    function createAdminJobsLevelsModule() {
        return {
            mount: function () {
                if (typeof window.AdminJobsLevels !== 'undefined'
                    && window.AdminJobsLevels
                    && typeof window.AdminJobsLevels.init === 'function') {
                    window.AdminJobsLevels.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminJobsLevelsModuleFactory = createAdminJobsLevelsModule;
})(window);
