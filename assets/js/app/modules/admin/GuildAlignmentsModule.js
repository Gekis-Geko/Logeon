(function (window) {
    'use strict';

    function createAdminGuildAlignmentsModule() {
        return {
            mount: function () {
                if (typeof window.AdminGuildAlignments !== 'undefined'
                    && window.AdminGuildAlignments
                    && typeof window.AdminGuildAlignments.init === 'function') {
                    window.AdminGuildAlignments.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminGuildAlignmentsModuleFactory = createAdminGuildAlignmentsModule;
})(window);
