(function (window) {
    'use strict';

    function createAdminHowToPlayModule() {
        return {
            mount: function () {
                if (typeof window.AdminHowToPlay !== 'undefined'
                    && window.AdminHowToPlay
                    && typeof window.AdminHowToPlay.init === 'function') {
                    window.AdminHowToPlay.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminHowToPlayModuleFactory = createAdminHowToPlayModule;
})(window);
