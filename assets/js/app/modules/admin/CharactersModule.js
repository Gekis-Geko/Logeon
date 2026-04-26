const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createAdminCharactersModule() {
    return {
        mount: function () {
            if (typeof globalWindow.AdminCharacters !== 'undefined'
                && globalWindow.AdminCharacters
                && typeof globalWindow.AdminCharacters.init === 'function') {
                globalWindow.AdminCharacters.init();
            }
        },
        unmount: function () {}
    };
}

globalWindow.AdminCharactersModuleFactory = createAdminCharactersModule;
export { createAdminCharactersModule as AdminCharactersModuleFactory };
export default createAdminCharactersModule;

