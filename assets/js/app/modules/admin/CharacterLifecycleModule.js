const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createCharacterLifecycleModule() {
    return {
        mount: function () {
            if (typeof globalWindow.AdminCharacterLifecycle !== 'undefined'
                && globalWindow.AdminCharacterLifecycle
                && typeof globalWindow.AdminCharacterLifecycle.init === 'function') {
                globalWindow.AdminCharacterLifecycle.init();
            }
        },
        unmount: function () {}
    };
}

globalWindow.AdminCharacterLifecycleModuleFactory = createCharacterLifecycleModule;
export { createCharacterLifecycleModule as AdminCharacterLifecycleModuleFactory };
export default createCharacterLifecycleModule;

