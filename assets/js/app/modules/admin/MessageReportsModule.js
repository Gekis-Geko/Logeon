const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function AdminMessageReportsModuleFactory() {
    return {
        mount: function () {
            if (typeof globalWindow.AdminMessageReports !== 'undefined' && globalWindow.AdminMessageReports && typeof globalWindow.AdminMessageReports.init === 'function') {
                globalWindow.AdminMessageReports.init();
            }
        },
        unmount: function () {}
    };
}

globalWindow.AdminMessageReportsModuleFactory = AdminMessageReportsModuleFactory;
export { AdminMessageReportsModuleFactory as AdminMessageReportsModuleFactory };
export default AdminMessageReportsModuleFactory;

