(function (window) {
    'use strict';

    function createAdminEquipmentSlotsModule() {
        return {
            mount: function () {
                if (typeof window.AdminEquipmentSlots !== 'undefined'
                    && window.AdminEquipmentSlots
                    && typeof window.AdminEquipmentSlots.init === 'function') {
                    window.AdminEquipmentSlots.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminEquipmentSlotsModuleFactory = createAdminEquipmentSlotsModule;
})(window);
