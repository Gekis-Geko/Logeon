import './admin/CharacterAttributesModule.js';
import './admin/CharacterAttributes.js';

if (window.AdminRegistry) {
    window.AdminRegistry.registerModule('admin.character-attributes', 'AdminCharacterAttributesModuleFactory');
    window.AdminRegistry.extendPage('character-attributes', ['admin.character-attributes']);
}

if (window.AdminFeatureLoader) {
    window.AdminFeatureLoader.registerPageScripts('character-attributes', [
        '/modules/logeon.attributes/dist/admin.js'
    ]);
}
