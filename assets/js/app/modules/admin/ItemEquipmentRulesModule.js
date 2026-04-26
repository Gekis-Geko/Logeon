const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createAdminItemEquipmentRulesModule() {
    return {
        mount: function () {
            if (typeof globalWindow.AdminItemEquipmentRules !== 'undefined'
                && globalWindow.AdminItemEquipmentRules
                && typeof globalWindow.AdminItemEquipmentRules.init === 'function') {
                globalWindow.AdminItemEquipmentRules.init();
            }
        },
        unmount: function () {}
    };
}

globalWindow.AdminItemEquipmentRulesModuleFactory = createAdminItemEquipmentRulesModule;
export { createAdminItemEquipmentRulesModule as AdminItemEquipmentRulesModuleFactory };
export default createAdminItemEquipmentRulesModule;

