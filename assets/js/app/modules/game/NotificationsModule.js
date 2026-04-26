const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function NotificationsModuleFactory() {
    return {
        mount: function (ctx, options) {
            if (typeof globalWindow.NotificationsPage !== 'undefined'
                && globalWindow.NotificationsPage
                && typeof globalWindow.NotificationsPage.init === 'function') {
                globalWindow.NotificationsPage.init(ctx, options);
            }
        },
        unmount: function () {
            if (typeof globalWindow.NotificationsPage !== 'undefined'
                && globalWindow.NotificationsPage
                && typeof globalWindow.NotificationsPage.destroy === 'function') {
                globalWindow.NotificationsPage.destroy();
            }
        }
    };
}

globalWindow.NotificationsModuleFactory = NotificationsModuleFactory;
export { NotificationsModuleFactory as NotificationsModuleFactory };
export default NotificationsModuleFactory;

