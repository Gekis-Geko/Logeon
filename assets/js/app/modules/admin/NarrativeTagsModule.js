const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function AdminNarrativeTagsModuleFactory() {
    return {
        mount: function () {
            if (typeof globalWindow.AdminNarrativeTags !== 'undefined' && globalWindow.AdminNarrativeTags && typeof globalWindow.AdminNarrativeTags.init === 'function') {
                globalWindow.AdminNarrativeTags.init();
            }
        },
        unmount: function () {}
    };
}

globalWindow.AdminNarrativeTagsModuleFactory = AdminNarrativeTagsModuleFactory;
export { AdminNarrativeTagsModuleFactory as AdminNarrativeTagsModuleFactory };
export default AdminNarrativeTagsModuleFactory;

