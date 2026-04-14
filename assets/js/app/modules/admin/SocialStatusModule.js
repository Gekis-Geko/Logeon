(function (window) {
    'use strict';

    function createAdminSocialStatusModule() {
        return {
            mount: function () {
                if (typeof window.AdminSocialStatus !== 'undefined'
                    && window.AdminSocialStatus
                    && typeof window.AdminSocialStatus.init === 'function') {
                    window.AdminSocialStatus.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminSocialStatusModuleFactory = createAdminSocialStatusModule;
})(window);
