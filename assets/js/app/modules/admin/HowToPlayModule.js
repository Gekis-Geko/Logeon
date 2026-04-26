const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createAdminHowToPlayModule() {
    return {
        mount: function () {
            if (typeof globalWindow.AdminHowToPlay !== 'undefined'
                && globalWindow.AdminHowToPlay
                && typeof globalWindow.AdminHowToPlay.init === 'function') {
                globalWindow.AdminHowToPlay.init();
            }
        },
        unmount: function () {}
    };
}

globalWindow.AdminHowToPlayModuleFactory = createAdminHowToPlayModule;
export { createAdminHowToPlayModule as AdminHowToPlayModuleFactory };
export default createAdminHowToPlayModule;

