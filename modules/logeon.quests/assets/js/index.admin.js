import './admin/QuestsModule.js';
import './admin/Quests.js';

if (window.AdminRegistry) {
    window.AdminRegistry.registerModule('admin.quests', 'AdminQuestsModuleFactory');
    window.AdminRegistry.extendPage('quests', ['admin.quests']);
}

if (window.AdminFeatureLoader) {
    window.AdminFeatureLoader.registerPageScripts('quests', [
        '/modules/logeon.quests/dist/admin.js'
    ]);
}
