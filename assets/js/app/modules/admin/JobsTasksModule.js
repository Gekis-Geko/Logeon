(function (window) {
    'use strict';

    function createAdminJobsTasksModule() {
        return {
            mount: function () {
                if (typeof window.AdminJobsTasks !== 'undefined'
                    && window.AdminJobsTasks
                    && typeof window.AdminJobsTasks.init === 'function') {
                    window.AdminJobsTasks.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminJobsTasksModuleFactory = createAdminJobsTasksModule;
})(window);
