const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createAdminEquipmentSlotsModule() {
    return {
        mount: function () {
            if (typeof globalWindow.AdminEquipmentSlots !== 'undefined'
                && globalWindow.AdminEquipmentSlots
                && typeof globalWindow.AdminEquipmentSlots.init === 'function') {
                globalWindow.AdminEquipmentSlots.init();
            }
        },
        unmount: function () {}
    };
}

globalWindow.AdminEquipmentSlotsModuleFactory = createAdminEquipmentSlotsModule;
export { createAdminEquipmentSlotsModule as AdminEquipmentSlotsModuleFactory };
export default createAdminEquipmentSlotsModule;

