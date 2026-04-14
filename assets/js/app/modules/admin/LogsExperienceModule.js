(function (window) {
    'use strict';

    function createAdminLogsExperienceModule() {
        return {
            mount: function () {
                if (typeof window.AdminLogsExperience !== 'undefined'
                    && window.AdminLogsExperience
                    && typeof window.AdminLogsExperience.init === 'function') {
                    window.AdminLogsExperience.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminLogsExperienceModuleFactory = createAdminLogsExperienceModule;
})(window);
