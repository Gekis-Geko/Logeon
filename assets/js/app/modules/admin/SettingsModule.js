(function (window) {
    'use strict';

    function AdminSettingsModuleFactory() {
        return {
            mount: function () {
                if (typeof window.AdminSettings !== 'undefined' && window.AdminSettings && typeof window.AdminSettings.init === 'function') {
                    window.AdminSettings.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminSettingsModuleFactory = AdminSettingsModuleFactory;
})(window);
