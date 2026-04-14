(function (window) {
    'use strict';

    function createAdminItemEquipmentRulesModule() {
        return {
            mount: function () {
                if (typeof window.AdminItemEquipmentRules !== 'undefined'
                    && window.AdminItemEquipmentRules
                    && typeof window.AdminItemEquipmentRules.init === 'function') {
                    window.AdminItemEquipmentRules.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminItemEquipmentRulesModuleFactory = createAdminItemEquipmentRulesModule;
})(window);
