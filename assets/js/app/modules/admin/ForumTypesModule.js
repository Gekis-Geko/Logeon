const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createAdminForumTypesModule() {
    return {
        mount: function () {
            if (typeof globalWindow.AdminForumTypes !== 'undefined'
                && globalWindow.AdminForumTypes
                && typeof globalWindow.AdminForumTypes.init === 'function') {
                globalWindow.AdminForumTypes.init();
            }
        },
        unmount: function () {}
    };
}

globalWindow.AdminForumTypesModuleFactory = createAdminForumTypesModule;
export { createAdminForumTypesModule as AdminForumTypesModuleFactory };
export default createAdminForumTypesModule;

