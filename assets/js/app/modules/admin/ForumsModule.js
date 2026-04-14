(function (window) {
    'use strict';

    function createAdminForumsModule() {
        return {
            mount: function () {
                if (typeof window.AdminForums !== 'undefined'
                    && window.AdminForums
                    && typeof window.AdminForums.init === 'function') {
                    window.AdminForums.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminForumsModuleFactory = createAdminForumsModule;
})(window);
