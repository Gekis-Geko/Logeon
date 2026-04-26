import './game/FactionsModule.js';
import './game/FactionsPage.js';

const factionsBaseModules = [
    'game.notifications',
    'game.narrative-events',
    'game.system-events'
];
const factionsBaseScripts = [
    '/assets/js/app/features/game/NotificationsPage.js',
    '/assets/js/app/features/game/NarrativeEventsPage.js',
    '/assets/js/app/features/game/SystemEventsPage.js'
];

if (window.GameRegistry) {
    window.GameRegistry.registerModule('game.factions', 'FactionsModuleFactory');
    window.GameRegistry.extendPage('factions', factionsBaseModules);
    window.GameRegistry.extendPage('factions', ['game.factions']);
}

if (window.GameFeatureLoader) {
    window.GameFeatureLoader.registerPageScripts('factions', factionsBaseScripts);
    window.GameFeatureLoader.registerPageScripts('factions', [
        '/modules/logeon.factions/dist/game.js'
    ]);
}
