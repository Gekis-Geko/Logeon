(function (window) {
    'use strict';

    function createFactionsModule() {
        return {
            mount: function () {
                if (typeof window.AdminFactions !== 'undefined'
                    && window.AdminFactions
                    && typeof window.AdminFactions.init === 'function') {
                    window.AdminFactions.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminFactionsModuleFactory = createFactionsModule;
})(window);
