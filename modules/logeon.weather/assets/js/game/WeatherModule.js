(function (window) {
    'use strict';

    function createWeatherModule() {
        return {
            ctx: null,
            options: {},

            mount: function (ctx, options) {
                this.ctx = ctx || null;
                this.options = options || {};
                return this;
            },

            unmount: function () {},

            get: function (payload, action) {
                return this.request('/get/weather', action || 'getWeather', payload || {});
            },

            state: function (payload) {
                return this.request('/get/weather', 'getLocationWeatherState', payload || {});
            },

            optionsList: function (payload, action) {
                return this.request('/weather/options', action || 'getWeatherOptions', payload || {});
            },

            setLocation: function (payload, action) {
                return this.request('/weather/location/set', action || 'setLocationWeather', payload || {});
            },

            clearLocation: function (payload, action) {
                return this.request('/weather/location/clear', action || 'clearLocationWeather', payload || {});
            },

            climateAreaList: function (payload, action) {
                return this.request('/weather/climate-areas', action || 'listClimateAreas', payload || {});
            },

            climateAreaCreate: function (payload, action) {
                return this.request('/weather/climate-areas/create', action || 'createClimateArea', payload || {});
            },

            climateAreaUpdate: function (payload, action) {
                return this.request('/weather/climate-areas/update', action || 'updateClimateArea', payload || {});
            },

            climateAreaDelete: function (payload, action) {
                return this.request('/weather/climate-areas/delete', action || 'deleteClimateArea', payload || {});
            },

            climateAreaAssign: function (payload, action) {
                return this.request('/weather/climate-areas/assign', action || 'assignClimateArea', payload || {});
            },

            request: function (url, action, payload) {
                if (!this.ctx || !this.ctx.services || !this.ctx.services.http) {
                    return Promise.reject(new Error('HTTP service not available.'));
                }

                return this.ctx.services.http.request({
                    url: url,
                    action: action,
                    payload: payload || {}
                });
            }
        };
    }

    window.GameWeatherModuleFactory = createWeatherModule;
})(window);
