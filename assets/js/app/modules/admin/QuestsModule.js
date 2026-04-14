(function (window) {
    'use strict';

    function createQuestsModule() {
        return {
            mount: function () {
                if (typeof window.AdminQuests !== 'undefined'
                    && window.AdminQuests
                    && typeof window.AdminQuests.init === 'function') {
                    window.AdminQuests.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminQuestsModuleFactory = createQuestsModule;
})(window);
