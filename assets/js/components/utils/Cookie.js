function Cookies() {
    let base = {
        setCookie: function (name, value, expire) {
            if (name == null || String(name).trim() === '') {
                return this;
            }

            let cookieName = encodeURIComponent(String(name).trim());
            let cookieValue = encodeURIComponent((value == null) ? '' : String(value));
            let cookie = cookieName + '=' + cookieValue + ';path=/;SameSite=Lax';

            let days = parseInt(expire, 10);
            if (!Number.isNaN(days) && days > 0) {
                let d = new Date();
                d.setTime(d.getTime() + (days * 24 * 60 * 60 * 1000));
                cookie += ';expires=' + d.toUTCString();
            }

            document.cookie = cookie;
            return this;
        },
        getCookie: function (name) {
            if (name == null) {
                return false;
            }

            let target = encodeURIComponent(String(name).trim());
            if (target === '') {
                return false;
            }

            let needle = target + '=';
            let cookies = document.cookie ? document.cookie.split(';') : [];

            for (let i = 0; i < cookies.length; i++) {
                let cookie = cookies[i].trim();
                if (cookie.indexOf(needle) === 0) {
                    let value = cookie.substring(needle.length);
                    try {
                        return decodeURIComponent(value);
                    } catch (error) {
                        return value;
                    }
                }
            }

            return false;
        },
        checkCookie: function (name) {
            let cookie = this.getCookie(name);
            return cookie !== false && cookie !== null && cookie !== '';
        },
        deleteCookie: function (name) {
            if (name == null || String(name).trim() === '') {
                return this;
            }

            let cookieName = encodeURIComponent(String(name).trim());
            document.cookie = cookieName + '=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/;SameSite=Lax';
            return this;
        }
    };

    return base;
};

if (typeof window !== 'undefined') {
    window.Cookies = Cookies;
}
