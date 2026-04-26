import './game/ProfileArchetypes.js';
import './game/LocationSidebarArchetypes.js';

if (window.GamePage) {
    window.GamePage.registerPageController('profile', {
        global: 'ArchetypesProfile',
        factory: 'GameArchetypesProfilePage',
        args: [{}]
    });

    window.GamePage.registerPageController('location', {
        global: 'ArchetypesLocationSidebar',
        factory: 'GameArchetypesLocationSidebarPage',
        args: [{}]
    });
}
