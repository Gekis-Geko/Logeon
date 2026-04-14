(function (window) {
    'use strict';

    function createAdminForumTypesModule() {
        return {
            mount: function () {
                if (typeof window.AdminForumTypes !== 'undefined'
                    && window.AdminForumTypes
                    && typeof window.AdminForumTypes.init === 'function') {
                    window.AdminForumTypes.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminForumTypesModuleFactory = createAdminForumTypesModule;
})(window);
