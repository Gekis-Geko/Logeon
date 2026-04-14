(function (window) {
    'use strict';

    function createAdminWeatherModule() {
        return {
            mount: function () {
                if (typeof window.AdminWeather !== 'undefined'
                    && window.AdminWeather
                    && typeof window.AdminWeather.init === 'function') {
                    window.AdminWeather.init();
                }
            },
            unmount: function () {}
        };
    }

    window.AdminWeatherModuleFactory = createAdminWeatherModule;
})(window);
