const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createAdminLocationPositionTagsModule() {
    return {
        mount: function () {
            if (typeof globalWindow.AdminLocationPositionTags !== 'undefined'
                && globalWindow.AdminLocationPositionTags
                && typeof globalWindow.AdminLocationPositionTags.init === 'function') {
                globalWindow.AdminLocationPositionTags.init();
            }
        },
        unmount: function () {}
    };
}

globalWindow.AdminLocationPositionTagsModuleFactory = createAdminLocationPositionTagsModule;
export { createAdminLocationPositionTagsModule as AdminLocationPositionTagsModuleFactory };
export default createAdminLocationPositionTagsModule;
