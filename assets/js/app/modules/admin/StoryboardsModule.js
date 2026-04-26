const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createAdminStoryboardsModule() {
    return {
        mount: function () {
            if (typeof globalWindow.AdminStoryboards !== 'undefined'
                && globalWindow.AdminStoryboards
                && typeof globalWindow.AdminStoryboards.init === 'function') {
                globalWindow.AdminStoryboards.init();
            }
        },
        unmount: function () {}
    };
}

globalWindow.AdminStoryboardsModuleFactory = createAdminStoryboardsModule;
export { createAdminStoryboardsModule as AdminStoryboardsModuleFactory };
export default createAdminStoryboardsModule;

