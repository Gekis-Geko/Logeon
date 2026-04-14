(function (window) {
    'use strict';

    function NotificationsModuleFactory() {
        return {
            mount: function (ctx, options) {
                if (typeof window.NotificationsPage !== 'undefined'
                    && window.NotificationsPage
                    && typeof window.NotificationsPage.init === 'function') {
                    window.NotificationsPage.init(ctx, options);
                }
            },
            unmount: function () {
                if (typeof window.NotificationsPage !== 'undefined'
                    && window.NotificationsPage
                    && typeof window.NotificationsPage.destroy === 'function') {
                    window.NotificationsPage.destroy();
                }
            }
        };
    }

    window.NotificationsModuleFactory = NotificationsModuleFactory;
})(window);
