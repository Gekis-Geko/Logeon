(function (window) {
    'use strict';

    function createCharacterLifecycleModule() {
        return {
            mount: function () {
                if (typeof window.AdminCharacterLifecycle !== 'undefined'
                    && window.AdminCharacterLifecycle
                    && typeof window.AdminCharacterLifecycle.init === 'function') {
                    window.AdminCharacterLifecycle.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminCharacterLifecycleModuleFactory = createCharacterLifecycleModule;
})(window);
