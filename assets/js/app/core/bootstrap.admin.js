window.APP_BOOTSTRAP_ENABLED = true;
window.APP_BOOTSTRAP_RUNTIME = 'admin';

function startRuntime() {
    if (window.AdminRuntime && typeof window.AdminRuntime.start === 'function') {
        window.AdminRuntime.start();
    }
}

if (window.AdminFeatureLoader && typeof window.AdminFeatureLoader.loadForCurrentPage === 'function') {
    window.AdminFeatureLoader.loadForCurrentPage()
        .catch(function () {})
        .finally(function () {
            startRuntime();
        });
} else {
    startRuntime();
}
