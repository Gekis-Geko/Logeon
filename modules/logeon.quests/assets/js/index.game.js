import './game/QuestsModule.js';
import './game/QuestsPage.js';
import './game/QuestHistoryPage.js';

const questGamePages = [
    'home', 'forum', 'threads', 'thread', 'profile', 'profile-edit',
    'onlines', 'location', 'shop', 'bank', 'maps', 'locations',
    'settings', 'jobs', 'guilds', 'guild', 'bag', 'equips',
    'quests-history', 'factions', 'anagrafica'
];
const questHistoryBaseModules = [
    'game.notifications',
    'game.narrative-events',
    'game.system-events'
];
const questHistoryBaseScripts = [
    '/assets/js/app/features/game/NotificationsPage.js',
    '/assets/js/app/features/game/NarrativeEventsPage.js',
    '/assets/js/app/features/game/SystemEventsPage.js'
];

if (window.GameRegistry) {
    window.GameRegistry.registerModule('game.quests', 'QuestsModuleFactory');
    window.GameRegistry.extendPage('quests-history', questHistoryBaseModules);
    questGamePages.forEach(function (pageKey) {
        window.GameRegistry.extendPage(pageKey, ['game.quests']);
    });
}

if (window.GameFeatureLoader) {
    window.GameFeatureLoader.registerPageScripts('quests-history', questHistoryBaseScripts);
    questGamePages.forEach(function (pageKey) {
        window.GameFeatureLoader.registerPageScripts(pageKey, [
            '/modules/logeon.quests/dist/game.js'
        ]);
    });
}

if (window.GamePage) {
    window.GamePage.registerPageController('quests-history', {
        global: 'QuestHistory',
        module: 'game.quests',
        factory: 'GameQuestHistoryPage',
        args: [{}]
    });
}
