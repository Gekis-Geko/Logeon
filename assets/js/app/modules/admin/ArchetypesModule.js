(function (window) {
    'use strict';

    function AdminArchetypesModuleFactory() {
        return {
            mount: function () {
                if (typeof window.AdminArchetypes !== 'undefined' && window.AdminArchetypes && typeof window.AdminArchetypes.init === 'function') {
                    window.AdminArchetypes.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminArchetypesModuleFactory = AdminArchetypesModuleFactory;
})(window);
