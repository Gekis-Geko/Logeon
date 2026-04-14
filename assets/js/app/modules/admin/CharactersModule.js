(function (window) {
    'use strict';

    function createAdminCharactersModule() {
        return {
            mount: function () {
                if (typeof window.AdminCharacters !== 'undefined'
                    && window.AdminCharacters
                    && typeof window.AdminCharacters.init === 'function') {
                    window.AdminCharacters.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminCharactersModuleFactory = createAdminCharactersModule;
})(window);
