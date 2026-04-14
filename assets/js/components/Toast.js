function Toast(id, extension) {
    var base = {
        id: id,
        node: null,
        toast_id: null,
        toast: null,
        mounted: false,
        type: null,
        body: null,
        title: null,
        disposed: false,
        defaultType: 'default',
        bodyAsHtml: true,
        _toastTypeClasses: 'text-bg-success text-bg-warning text-bg-danger text-bg-dark text-bg-info',
        settings: {
            autohide: true,
            delay: 4000
        },

        init: function () {
            this.disposed = false;
            this.mounted = false;
            if (this.id == null || String(this.id).trim() === '') {
                this._showFatal(
                    'Id non dichiarato',
                    'Non e stato dichiarato l\'elemento del Toast da visualizzare.'
                );
                return this;
            }

            if (typeof window.$ === 'undefined') {
                this._showFatal(
                    'jQuery non disponibile',
                    'Impossibile inizializzare Toast senza jQuery.'
                );
                return this;
            }

            this.node = document.getElementById(this.id);
            if (!this.node) {
                this._showFatal(
                    'Toast non trovato',
                    'Elemento toast non trovato con ID: ' + this.id
                );
                return this;
            }

            this.toast_id = $(this.node);

            if (typeof bootstrap === 'undefined' || !bootstrap.Toast) {
                this._showFatal(
                    'Bootstrap non disponibile',
                    'bootstrap.Toast non e disponibile.'
                );
                return this;
            }

            if (typeof bootstrap.Toast.getOrCreateInstance === 'function') {
                this.toast = bootstrap.Toast.getOrCreateInstance(this.node, {
                    autohide: this.settings.autohide,
                    delay: this.settings.delay
                });
            } else if (typeof bootstrap.Toast.getInstance === 'function') {
                this.toast = bootstrap.Toast.getInstance(this.node) || new bootstrap.Toast(this.node, {
                    autohide: this.settings.autohide,
                    delay: this.settings.delay
                });
            } else {
                this.toast = new bootstrap.Toast(this.node, {
                    autohide: this.settings.autohide,
                    delay: this.settings.delay
                });
            }

            this.disposed = false;
            this.mounted = true;
            return this;
        },

        mount: function () {
            return this.init();
        },

        _showFatal: function (title, body) {
            if (typeof window !== 'undefined' &&
                window.SystemDialogs &&
                typeof window.SystemDialogs.ensureGeneralDialog === 'function') {
                var dialog = window.SystemDialogs.ensureGeneralDialog();
                if (dialog && typeof dialog.show === 'function') {
                    dialog.show({
                        title: title,
                        body: '<p>' + body + '</p>'
                    });
                    return;
                }
            }

            if (typeof console !== 'undefined' && typeof console.error === 'function') {
                console.error('[Toast] ' + title + ': ' + body);
            }
        },

        setToastType: function () {
            if (!this.toast_id || !this.toast_id.length) {
                return this;
            }

            this.toast_id.removeClass(this._toastTypeClasses);

            switch (this.type) {
                case 'success':
                    this.toast_id.addClass('text-bg-success');
                    break;
                case 'warning':
                    this.toast_id.addClass('text-bg-warning');
                    break;
                case 'error':
                case 'danger':
                    this.toast_id.addClass('text-bg-danger');
                    break;
                case 'info':
                    this.toast_id.addClass('text-bg-info');
                    break;
                default:
                    this.toast_id.addClass('text-bg-dark');
            }

            return this;
        },

        setToastTitle: function () {
            if (!this.toast_id || !this.toast_id.length) {
                return this;
            }

            var headerTitle = this.toast_id.find('.toast-header [name="title"], .toast-header .toast-title').first();
            if (!headerTitle.length) {
                return this;
            }

            if (this.title == null || String(this.title).trim() === '') {
                headerTitle.text('');
                return this;
            }

            headerTitle.text(String(this.title));
            return this;
        },

        setToastBody: function () {
            if (!this.toast_id || !this.toast_id.length) {
                return this;
            }

            var bodyNode = this.toast_id.find('.toast-body').first();
            if (!bodyNode.length) {
                return this;
            }

            if (this.bodyAsHtml === true) {
                bodyNode.html(this.body || '');
            } else {
                bodyNode.text((this.body == null) ? '' : String(this.body));
            }
            return this;
        },

        setData: function (data) {
            if (!data || typeof data !== 'object') {
                return this;
            }

            if (Object.prototype.hasOwnProperty.call(data, 'type')) {
                this.type = data.type;
            } else if (!this.type) {
                this.type = this.defaultType;
            }

            if (Object.prototype.hasOwnProperty.call(data, 'title')) {
                this.title = data.title;
            }

            if (Object.prototype.hasOwnProperty.call(data, 'body')) {
                this.body = data.body;
                this.bodyAsHtml = true;
            } else if (Object.prototype.hasOwnProperty.call(data, 'message')) {
                this.body = data.message;
                this.bodyAsHtml = false;
            } else if (Object.prototype.hasOwnProperty.call(data, 'text')) {
                this.body = data.text;
                this.bodyAsHtml = false;
            }

            if (Object.prototype.hasOwnProperty.call(data, 'html')) {
                this.bodyAsHtml = (data.html === true);
            }

            return this;
        },

        refresh: function () {
            if (this.disposed) {
                return this;
            }
            if (!this.toast_id || !this.toast_id.length) {
                return this;
            }
            this.setToastType();
            this.setToastTitle();
            this.setToastBody();
            return this;
        },

        show: function (data) {
            if (this.disposed) {
                return this;
            }

            if (data != null) {
                this.setData(data);
            }
            if (!this.type) {
                this.type = this.defaultType;
            }

            this.refresh();

            if (!this.toast || typeof this.toast.show !== 'function') {
                return this;
            }
            this.toast.show();
            return this;
        },

        hide: function () {
            if (!this.toast || typeof this.toast.hide !== 'function') {
                return this;
            }
            this.toast.hide();
            return this;
        },

        reset: function () {
            this.type = this.defaultType;
            this.title = null;
            this.body = null;
            this.bodyAsHtml = true;
            return this.refresh();
        },

        destroy: function () {
            if (this.toast && typeof this.toast.dispose === 'function') {
                this.toast.dispose();
            }
            this.toast = null;
            this.toast_id = null;
            this.node = null;
            this.disposed = true;
            this.mounted = false;
            return this;
        },

        unmount: function () {
            return this.destroy();
        }
    };

    var ext = (extension && typeof extension === 'object') ? Object.assign({}, extension) : {};
    if (ext.settings && typeof ext.settings === 'object') {
        base.settings = Object.assign({}, base.settings, ext.settings);
        delete ext.settings;
    }

    var o = Object.assign({}, base, ext);
    return o.init();
}

Toast.show = function (data) {
    if (typeof window === 'undefined') {
        return;
    }

    if (window.Toast &&
        window.Toast !== Toast &&
        typeof window.Toast !== 'function' &&
        typeof window.Toast.show === 'function') {
        window.Toast.show(data);
        return;
    }

    if (window.SystemDialogs && typeof window.SystemDialogs.ensureToast === 'function') {
        var toast = window.SystemDialogs.ensureToast();
        if (toast && typeof toast !== 'function' && typeof toast.show === 'function') {
            toast.show(data);
        }
    }
};

Toast.hide = function () {
    if (typeof window === 'undefined') {
        return;
    }

    if (window.Toast &&
        window.Toast !== Toast &&
        typeof window.Toast !== 'function' &&
        typeof window.Toast.hide === 'function') {
        window.Toast.hide();
        return;
    }

    if (window.SystemDialogs && typeof window.SystemDialogs.ensureToast === 'function') {
        var toast = window.SystemDialogs.ensureToast();
        if (toast && typeof toast !== 'function' && typeof toast.hide === 'function') {
            toast.hide();
        }
    }
};

if (typeof window !== 'undefined' && typeof window.ToastFactory !== 'function') {
    window.ToastFactory = Toast;
}
