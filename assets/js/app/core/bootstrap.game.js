(function (window) {
    'use strict';

    window.APP_BOOTSTRAP_ENABLED = true;
    window.APP_BOOTSTRAP_RUNTIME = 'game';

    function startRuntime() {
        if (window.GameRuntime && typeof window.GameRuntime.start === 'function') {
            window.GameRuntime.start();
        }
    }

    if (window.GameFeatureLoader && typeof window.GameFeatureLoader.loadForCurrentPage === 'function') {
        window.GameFeatureLoader.loadForCurrentPage()
            .catch(function () {})
            .finally(function () {
                startRuntime();
            });
    } else {
        startRuntime();
    }
})(window);
