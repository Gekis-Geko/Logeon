(function () {
    var COOKIE_NAME = 'lf_cookie_consent';
    var COOKIE_DAYS = 365;

    var cookies = Cookies();

    function init() {
        if (cookies.checkCookie(COOKIE_NAME)) {
            return;
        }
        var banner = document.getElementById('cookie-banner');
        if (!banner) {
            return;
        }
        banner.classList.remove('d-none');
        banner.querySelector('[data-action="cookie-accept"]').addEventListener('click', function () {
            cookies.setCookie(COOKIE_NAME, '1', COOKIE_DAYS);
            banner.classList.add('d-none');
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
