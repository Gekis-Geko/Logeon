(function (window) {
    'use strict';

    function AdminNarrativeDelegationGrantsModuleFactory() {
        return {
            mount: function () {
                if (typeof window.AdminNarrativeDelegationGrants !== 'undefined'
                    && window.AdminNarrativeDelegationGrants
                    && typeof window.AdminNarrativeDelegationGrants.init === 'function') {
                    window.AdminNarrativeDelegationGrants.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminNarrativeDelegationGrantsModuleFactory = AdminNarrativeDelegationGrantsModuleFactory;
})(window);
