import './admin/SocialStatusModule.js';
import './admin/SocialStatus.js';

if (window.AdminRegistry) {
    window.AdminRegistry.registerModule('admin.social-status', 'AdminSocialStatusModuleFactory');
    window.AdminRegistry.extendPage('social-status', ['admin.social-status']);
}

if (window.AdminFeatureLoader) {
    window.AdminFeatureLoader.registerPageScripts('social-status', [
        '/modules/logeon.social-status/dist/admin.js'
    ]);
}

