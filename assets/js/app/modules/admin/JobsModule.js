(function (window) {
    'use strict';

    function createAdminJobsModule() {
        return {
            mount: function () {
                if (typeof window.AdminJobs !== 'undefined'
                    && window.AdminJobs
                    && typeof window.AdminJobs.init === 'function') {
                    window.AdminJobs.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminJobsModuleFactory = createAdminJobsModule;
})(window);
