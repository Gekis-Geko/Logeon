(function (window) {
    'use strict';

    function AdminMessageReportsModuleFactory() {
        return {
            mount: function () {
                if (typeof window.AdminMessageReports !== 'undefined' && window.AdminMessageReports && typeof window.AdminMessageReports.init === 'function') {
                    window.AdminMessageReports.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminMessageReportsModuleFactory = AdminMessageReportsModuleFactory;
})(window);
