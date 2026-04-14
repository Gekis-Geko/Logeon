(function (window) {
    'use strict';

    function AdminNarrativeTagsModuleFactory() {
        return {
            mount: function () {
                if (typeof window.AdminNarrativeTags !== 'undefined' && window.AdminNarrativeTags && typeof window.AdminNarrativeTags.init === 'function') {
                    window.AdminNarrativeTags.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminNarrativeTagsModuleFactory = AdminNarrativeTagsModuleFactory;
})(window);
