(function (window) {
    'use strict';

    function createAdminLogsGuildModule() {
        return {
            mount: function () {
                if (typeof window.AdminLogsGuild !== 'undefined'
                    && window.AdminLogsGuild
                    && typeof window.AdminLogsGuild.init === 'function') {
                    window.AdminLogsGuild.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminLogsGuildModuleFactory = createAdminLogsGuildModule;
})(window);
