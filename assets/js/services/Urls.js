/**
 * @typedef {Object} UrlInfo
 * @property {string} origin    - Origine completa (es. 'https://example.com').
 * @property {string} protocol  - Protocollo (es. 'https:').
 * @property {string} host      - Host con porta se non standard (es. 'example.com:8080').
 * @property {string} pathName  - Path completo (es. '/admin/users').
 * @property {string[]} paths   - Segmenti del path senza stringhe vuote (es. ['admin', 'users']).
 */

/**
 * Legge e decompone l'URL corrente della pagina.
 * Dipende da jQuery `$(location)` per leggere `href`.
 *
 * @param {Object} [extension] - Override di metodi sull'istanza.
 * @returns {UrlInfo} Oggetto con i componenti dell'URL corrente, già popolato.
 */
function Urls(extension) {
    let base = {
        protocol: null,
        host: null,
        orgin: null,
        pathName: null,
        paths: [],

        init: function () {
            this.setUrl();

            return this.getUrl();
        },
        getUrl: function () {
            return {
                'origin': this.origin,
                'protocol': this.protocol,
                'host': this.host,
                'pathName': this.pathName,
                'paths': (null == this.paths) ? [] : this.paths,
            };
        },
        setUrl: function () {
            let url = new URL($(location).attr('href'));

            this.protocol = url.protocol;
            this.host = url.host;
            this.origin = url.origin;
            this.pathName = url.pathname;
            this.paths = url.pathname.split('/').filter((el) => {
                return el != ''
            });
        }
    }

    let o = Object.assign({}, base, extension);
	return o.init();
};
if (typeof window !== 'undefined') {
    window.Urls = Urls;
}
