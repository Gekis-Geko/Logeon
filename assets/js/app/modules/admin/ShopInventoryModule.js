const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createAdminShopInventoryModule() {
    return {
        mount: function () {
            if (typeof globalWindow.AdminShopInventory !== 'undefined'
                && globalWindow.AdminShopInventory
                && typeof globalWindow.AdminShopInventory.init === 'function') {
                globalWindow.AdminShopInventory.init();
            }
        },
        unmount: function () {}
    };
}

globalWindow.AdminShopInventoryModuleFactory = createAdminShopInventoryModule;
export { createAdminShopInventoryModule as AdminShopInventoryModuleFactory };
export default createAdminShopInventoryModule;

