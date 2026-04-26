import './admin/FactionsModule.js';
import './admin/Factions.js';

if (window.AdminRegistry) {
    window.AdminRegistry.registerModule('admin.factions', 'AdminFactionsModuleFactory');
    window.AdminRegistry.extendPage('factions', ['admin.factions']);
}

if (window.AdminFeatureLoader) {
    window.AdminFeatureLoader.registerPageScripts('factions', [
        '/modules/logeon.factions/dist/admin.js'
    ]);
}
