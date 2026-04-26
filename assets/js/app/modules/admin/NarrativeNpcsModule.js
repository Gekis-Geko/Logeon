const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function AdminNarrativeNpcsModuleFactory() {
    return {
        mount: function () {
            if (typeof globalWindow.AdminNarrativeNpcs !== 'undefined'
                && globalWindow.AdminNarrativeNpcs
                && typeof globalWindow.AdminNarrativeNpcs.init === 'function') {
                globalWindow.AdminNarrativeNpcs.init();
            }
        },
        unmount: function () {}
    };
}

globalWindow.AdminNarrativeNpcsModuleFactory = AdminNarrativeNpcsModuleFactory;
export { AdminNarrativeNpcsModuleFactory as AdminNarrativeNpcsModuleFactory };
export default AdminNarrativeNpcsModuleFactory;

