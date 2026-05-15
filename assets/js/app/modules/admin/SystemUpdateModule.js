const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function AdminSystemUpdateModuleFactory() {
    return {
        mount: function () {
            if (typeof globalWindow.AdminSystemUpdate !== 'undefined'
                && globalWindow.AdminSystemUpdate
                && typeof globalWindow.AdminSystemUpdate.init === 'function') {
                globalWindow.AdminSystemUpdate.init();
            }
        },
        unmount: function () {},
    };
}

globalWindow.AdminSystemUpdateModuleFactory = AdminSystemUpdateModuleFactory;
export { AdminSystemUpdateModuleFactory as AdminSystemUpdateModuleFactory };
export default AdminSystemUpdateModuleFactory;

