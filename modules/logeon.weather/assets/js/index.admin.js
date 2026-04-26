import './admin/WeatherModule.js';

const weatherPageKeys = ['weather', 'weather-overview', 'weather-catalogs', 'weather-profiles', 'weather-overrides'];

if (window.AdminRegistry) {
    weatherPageKeys.forEach(function (pageKey) {
        window.AdminRegistry.registerModule('admin.' + pageKey, 'AdminWeatherModuleFactory');
        window.AdminRegistry.extendPage(pageKey, ['admin.' + pageKey]);
    });
}
if (window.AdminFeatureLoader) {
    weatherPageKeys.forEach(function (pageKey) {
        window.AdminFeatureLoader.registerPageScripts(pageKey, [
            '/modules/logeon.weather/dist/admin.js'
        ]);
    });
}
