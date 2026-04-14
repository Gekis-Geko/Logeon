function Navbar(navbar_id, extension) {
    var base = {
        navbar: navbar_id,
        root: null,
        activeButton: null,
        mounted: false,
        disposed: false,
        routeMap: null,
        refAttribute: 'data-ref',
        linkSelector: '.nav-link',
        activeClass: 'active',
        errorMode: 'dialog',

        init: function () {
            this.disposed = false;
            this.mounted = false;
            this.navbar = String(this.navbar || '').trim();
            if (this.navbar === '') {
                this._showError('Navbar mancante', 'Navbar non assegnata.');
                return this;
            }

            if (typeof window.$ === 'undefined') {
                this._showError('jQuery non disponibile', 'Impossibile inizializzare Navbar senza jQuery.');
                return this;
            }

            this.root = $(this.navbar).first();
            if (!this.root.length) {
                this._showError('Navbar non trovata', 'Navbar non trovata con selettore: ' + this.navbar);
                return this;
            }

            this.setActiveButton();
            this.mounted = true;
            return this;
        },

        mount: function () {
            if (this.mounted === true && this.disposed !== true) {
                return this;
            }
            return this.init();
        },

        _showError: function (title, body) {
            if (this.errorMode === 'silent') {
                return;
            }
            if (this.errorMode === 'console') {
                if (typeof console !== 'undefined' && typeof console.error === 'function') {
                    console.error('[Navbar] ' + title + ': ' + body);
                }
                return;
            }
            if (typeof window !== 'undefined' && typeof window.Dialog === 'function') {
                window.Dialog('danger', {
                    title: title,
                    body: '<p>' + body + '</p>'
                }).show();
                return;
            }

            if (typeof console !== 'undefined' && typeof console.error === 'function') {
                console.error('[Navbar] ' + title + ': ' + body);
            }
        },

        _escapeSelectorValue: function (value) {
            var normalized = String(value || '');
            if (typeof window !== 'undefined' && window.CSS && typeof window.CSS.escape === 'function') {
                return window.CSS.escape(normalized);
            }
            return normalized.replace(/["\\]/g, '\\$&');
        },

        _resolveRefAttributeSelector: function (ref) {
            var attr = String(this.refAttribute || 'data-ref').trim() || 'data-ref';
            var escapedRef = this._escapeSelectorValue(ref);
            return '[' + attr + '="' + escapedRef + '"] ' + String(this.linkSelector || '.nav-link');
        },

        _resolveCurrentPath: function () {
            try {
                if (typeof window.Urls === 'function') {
                    var urlService = window.Urls();
                    if (urlService && Array.isArray(urlService.paths) && urlService.paths.length) {
                        var currentPath = (urlService.paths[0] === 'game') ? urlService.paths[1] : urlService.paths[0];
                        return String(currentPath || '').trim();
                    }
                }
            } catch (error) {
                // fallback to location.pathname
            }

            var parts = String((window.location && window.location.pathname) || '')
                .split('/')
                .filter(function (chunk) {
                    return chunk !== '';
                });
            if (!parts.length) {
                return '';
            }
            return parts[0] === 'game' ? String(parts[1] || '').trim() : String(parts[0] || '').trim();
        },

        _resolveRefFromPath: function (path) {
            var normalizedPath = String(path || '').trim();
            var map = this.routeMap;
            if (map && typeof map === 'object') {
                if (Object.prototype.hasOwnProperty.call(map, normalizedPath)) {
                    return String(map[normalizedPath] || '').trim();
                }
                if (normalizedPath === '' && Object.prototype.hasOwnProperty.call(map, '')) {
                    return String(map[''] || '').trim();
                }
            }

            if (normalizedPath === '') {
                return 'home';
            }
            return normalizedPath;
        },

        clearActiveButton: function () {
            if (!this.root || !this.root.length) {
                this.activeButton = null;
                return this;
            }
            this.root.find(String(this.linkSelector || '.nav-link') + '.' + String(this.activeClass || 'active')).removeClass(this.activeClass || 'active');
            this.activeButton = null;
            return this;
        },

        getActiveButton: function () {
            return this.activeButton;
        },

        setActiveButton: function (force) {
            if (!this.root || !this.root.length) {
                return this;
            }

            var activeSelector = String(this.linkSelector || '.nav-link') + '.' + String(this.activeClass || 'active');
            var alreadyActive = this.root.find(activeSelector);
            if (force !== true && alreadyActive.length > 0) {
                this.activeButton = alreadyActive.first();
                return this;
            }

            var path = this._resolveCurrentPath();
            var ref = this._resolveRefFromPath(path);
            var target = null;
            target = this.root.find(this._resolveRefAttributeSelector(ref)).first();

            if (target && target.length) {
                this.clearActiveButton();
                target.addClass(this.activeClass || 'active');
                this.activeButton = target;
            } else {
                this.activeButton = null;
            }

            return this;
        },

        setActive: function (ref) {
            if (!this.root || !this.root.length) {
                return this;
            }

            var normalizedRef = String(ref || '').trim();
            if (!normalizedRef) {
                return this.clearActiveButton();
            }

            var target = this.root.find(this._resolveRefAttributeSelector(normalizedRef)).first();
            if (!target.length) {
                this.activeButton = null;
                return this;
            }

            this.clearActiveButton();
            target.addClass(this.activeClass || 'active');
            this.activeButton = target;
            return this;
        },

        refresh: function () {
            return this.setActiveButton(true);
        },

        destroy: function () {
            this.activeButton = null;
            this.root = null;
            this.mounted = false;
            this.disposed = true;
            return this;
        },

        unmount: function () {
            return this.destroy();
        }
    };

    var o = Object.assign({}, base, extension);
    return o.init();
}

if (typeof window !== 'undefined') {
    window.Navbar = Navbar;
}
