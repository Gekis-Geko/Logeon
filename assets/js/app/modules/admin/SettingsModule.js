const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function AdminSettingsModuleFactory() {
    return {
        mount: function () {
            if (typeof globalWindow.AdminSettings !== 'undefined' && globalWindow.AdminSettings && typeof globalWindow.AdminSettings.init === 'function') {
                globalWindow.AdminSettings.init();
            }
        },
        unmount: function () {}
    };
}

globalWindow.AdminSettingsModuleFactory = AdminSettingsModuleFactory;
export { AdminSettingsModuleFactory as AdminSettingsModuleFactory };
export default AdminSettingsModuleFactory;

