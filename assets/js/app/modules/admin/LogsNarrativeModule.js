(function (window) {
    'use strict';

    function createAdminLogsNarrativeModule() {
        return {
            mount: function () {
                if (typeof window.AdminLogsNarrative !== 'undefined'
                    && window.AdminLogsNarrative
                    && typeof window.AdminLogsNarrative.init === 'function') {
                    window.AdminLogsNarrative.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminLogsNarrativeModuleFactory = createAdminLogsNarrativeModule;
})(window);
