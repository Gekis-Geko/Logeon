const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function AdminNarrativeDelegationGrantsModuleFactory() {
    return {
        mount: function () {
            if (typeof globalWindow.AdminNarrativeDelegationGrants !== 'undefined'
                && globalWindow.AdminNarrativeDelegationGrants
                && typeof globalWindow.AdminNarrativeDelegationGrants.init === 'function') {
                globalWindow.AdminNarrativeDelegationGrants.init();
            }
        },
        unmount: function () {}
    };
}

globalWindow.AdminNarrativeDelegationGrantsModuleFactory = AdminNarrativeDelegationGrantsModuleFactory;
export { AdminNarrativeDelegationGrantsModuleFactory as AdminNarrativeDelegationGrantsModuleFactory };
export default AdminNarrativeDelegationGrantsModuleFactory;

