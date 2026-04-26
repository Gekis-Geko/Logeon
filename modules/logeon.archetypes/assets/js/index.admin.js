import './admin/Archetypes.js';
import './admin/ArchetypesModule.js';
import './admin/CharactersArchetypesExtension.js';

if (window.AdminRegistry) {
    window.AdminRegistry.registerModule('admin.archetypes', 'AdminArchetypesModuleFactory');
    window.AdminRegistry.extendPage('archetypes', ['admin.archetypes']);
}
if (window.AdminFeatureLoader) {
    window.AdminFeatureLoader.registerPageScripts('archetypes', [
        '/modules/logeon.archetypes/dist/admin.js'
    ]);
}
