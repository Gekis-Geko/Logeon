(function (window) {
    'use strict';

    function AdminNarrativeNpcsModuleFactory() {
        return {
            mount: function () {
                if (typeof window.AdminNarrativeNpcs !== 'undefined'
                    && window.AdminNarrativeNpcs
                    && typeof window.AdminNarrativeNpcs.init === 'function') {
                    window.AdminNarrativeNpcs.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminNarrativeNpcsModuleFactory = AdminNarrativeNpcsModuleFactory;
})(window);
