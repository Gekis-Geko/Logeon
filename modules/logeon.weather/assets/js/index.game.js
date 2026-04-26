import './game/WeatherModule.js';
import './game/WeatherPage.js';

const weatherGamePages = [
    'home', 'forum', 'threads', 'thread', 'profile', 'profile-edit',
    'onlines', 'location', 'shop', 'bank', 'maps', 'locations',
    'settings', 'jobs', 'guilds', 'guild', 'bag', 'equips',
    'quests-history', 'factions', 'anagrafica', 'archetypes'
];

if (window.GameRegistry) {
    window.GameRegistry.registerModule('game.weather', 'GameWeatherModuleFactory');
    weatherGamePages.forEach(function (pageKey) {
        window.GameRegistry.extendPage(pageKey, ['game.weather']);
    });
}
if (window.GameFeatureLoader) {
    weatherGamePages.forEach(function (pageKey) {
        window.GameFeatureLoader.registerPageScripts(pageKey, [
            '/modules/logeon.weather/dist/game.js'
        ]);
    });
}

if (window.GamePage) {
    window.GamePage.registerSharedController({
        global: 'Weather',
        factory: 'GameWeatherPage',
        args: [{}]
    });

    window.GamePage.registerPageController('location', {
        global: 'LocationWeatherStaff',
        factory: 'GameWeatherStaffPage',
        args: [{}]
    });
}
