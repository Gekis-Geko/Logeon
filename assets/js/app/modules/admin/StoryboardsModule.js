(function (window) {
    'use strict';

    function createAdminStoryboardsModule() {
        return {
            mount: function () {
                if (typeof window.AdminStoryboards !== 'undefined'
                    && window.AdminStoryboards
                    && typeof window.AdminStoryboards.init === 'function') {
                    window.AdminStoryboards.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminStoryboardsModuleFactory = createAdminStoryboardsModule;
})(window);
