function Modal(id, extension) {
    var ext = (extension && typeof extension === 'object') ? Object.assign({}, extension) : {};

    if (typeof ext.beuoreShow === 'function') {
        if (typeof ext.beforeShow !== 'function') {
            ext.beforeShow = ext.beuoreShow;
        }
        delete ext.beuoreShow;
        if (typeof console !== 'undefined' && typeof console.warn === 'function') {
            console.warn('[Modal] Trovato hook non valido "beuoreShow" su "' + id + '". Usa "beforeShow".');
        }
    }

    var base = {
        id: id,
        form: null,
        modal: null,
        modal_id: null,
        modalNode: null,
        lifecycleBound: false,
        lifecycleNs: null,
        disposed: false,
        mounted: false,

        datafield: {},
        dataform: null,
        response: null,

        settings: {
            backdrop: true,
            keyboard: true,
            viewer: false,
            spinner: '<div class="spinner-border" role="status"></div>',
            urls: {
                create: '',
                update: '',
                delete: '',
                get: '',
                list: ''
            }
        },

        init: function () {
            this.disposed = false;
            this.mounted = false;

            if (!this.id || String(this.id).trim() === '') {
                this._showError('Modale mancante', 'ID modale non dichiarato.');
                return this;
            }

            if (typeof window.$ === 'undefined') {
                this._showError('jQuery non disponibile', 'Impossibile inizializzare la modale senza jQuery.');
                return this;
            }

            this.modal_id = $('#' + this.id);
            if (this.modal_id.length === 0) {
                this._showError('Modale mancante', 'La modale dichiarata non e presente nel corpo della pagina. Controlla: ' + this.id);
                return this;
            }

            if (this.settings.viewer === false) {
                if ($('#' + this.id + '-form').length === 0) {
                    this._showError('Form mancante', 'La form non e stata trovata. La nomenclatura deve essere: ' + this.id + '-form');
                    return this;
                }
                this.form = this.id + '-form';
            }

            if (typeof bootstrap === 'undefined' || !bootstrap.Modal) {
                this._showError('Bootstrap non disponibile', 'bootstrap.Modal non e disponibile.');
                return this;
            }

            this.modalNode = document.getElementById(this.id);
            if (!this.modalNode) {
                this._showError('Modale mancante', 'Elemento DOM modale non trovato: ' + this.id);
                return this;
            }

            this.modal = this._getOrCreateModal(this.modalNode);
            this._bindLifecycleHandlers();
            this.mounted = true;
            this.onInit();

            return this;
        },

        mount: function () {
            if (this.disposed !== true && this.mounted === true) {
                return this;
            }
            return this.init();
        },

        _getOrCreateModal: function (node) {
            if (!node || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
                return null;
            }

            var options = {
                backdrop: this.settings.backdrop,
                keyboard: this.settings.keyboard
            };

            if (typeof bootstrap.Modal.getOrCreateInstance === 'function') {
                return bootstrap.Modal.getOrCreateInstance(node, options);
            }

            var instance = (typeof bootstrap.Modal.getInstance === 'function')
                ? bootstrap.Modal.getInstance(node)
                : null;

            if (instance) {
                return instance;
            }

            return new bootstrap.Modal(node, options);
        },

        _showError: function (title, body) {
            if (typeof window !== 'undefined' && typeof window.Dialog === 'function') {
                window.Dialog('danger', {
                    title: title,
                    body: '<p>' + body + '</p>'
                }).show();
                return;
            }

            if (typeof console !== 'undefined' && typeof console.error === 'function') {
                console.error('[Modal] ' + title + ': ' + body);
            }
        },

        _getRequestApi: function () {
            var requestApi = (typeof window !== 'undefined' && window.Request)
                ? window.Request
                : ((typeof Request !== 'undefined') ? Request : null);

            if (!requestApi || !requestApi.http || typeof requestApi.http.post !== 'function') {
                return null;
            }

            return requestApi;
        },

        _bindLifecycleHandlers: function () {
            if (!this.modal_id || !this.modal_id.length) {
                return;
            }

            var self = this;
            var safeId = String(this.id || '').replace(/[^a-z0-9_-]/gi, '_');
            this.lifecycleNs = '.modal_component_' + safeId;

            this.modal_id.off('show.bs.modal' + this.lifecycleNs);
            this.modal_id.on('show.bs.modal' + this.lifecycleNs, function () {
                self.beforeShow();
            });

            this.modal_id.off('shown.bs.modal' + this.lifecycleNs);
            this.modal_id.on('shown.bs.modal' + this.lifecycleNs, function () {
                self.afterShow();
            });

            this.modal_id.off('hide.bs.modal' + this.lifecycleNs);
            this.modal_id.on('hide.bs.modal' + this.lifecycleNs, function () {
                self.beforeHide();
            });

            this.modal_id.off('hidden.bs.modal' + this.lifecycleNs);
            this.modal_id.on('hidden.bs.modal' + this.lifecycleNs, function () {
                self.afterHide();
            });

            this.lifecycleBound = true;
        },

        show: function (data) {
            if (this.disposed) {
                return this;
            }

            if (!this.modal || typeof this.modal.show !== 'function') {
                this.modalNode = document.getElementById(this.id);
                this.modal = this._getOrCreateModal(this.modalNode);
            }

            if (!this.modal || typeof this.modal.show !== 'function') {
                return this;
            }

            this.setData(data);

            if (this.settings.viewer === false && typeof window.Form === 'function') {
                window.Form().setFields(this.form, this.datafield);
            }

            if (data && typeof data === 'object' && Object.prototype.hasOwnProperty.call(data, 'title')) {
                this.setModalTitle(data.title);
            }

            this.modal.show();
            return this;
        },

        open: function (data) {
            return this.show(data);
        },

        hide: function () {
            if (!this.modal || typeof this.modal.hide !== 'function') {
                return this;
            }

            this.resetState();

            var modalNode = this.modalNode || document.getElementById(this.id);
            var active = document.activeElement;
            if (modalNode && active && typeof modalNode.contains === 'function' && modalNode.contains(active) && typeof active.blur === 'function') {
                active.blur();
            }

            if (this.settings.viewer === false && typeof window.Form === 'function') {
                window.Form().resetField(this.form);
            }

            this.modal.hide();
            return this;
        },

        close: function () {
            return this.hide();
        },

        setData: function (data, replace) {
            if (data != null && typeof data === 'object') {
                if (replace === true) {
                    this.datafield = Object.assign({}, data);
                } else {
                    this.datafield = Object.assign({}, this.datafield || {}, data);
                }
            } else if (!this.datafield || typeof this.datafield !== 'object') {
                this.datafield = {};
            }
            return this;
        },

        resetState: function (options) {
            var cfg = (options && typeof options === 'object') ? options : {};
            if (cfg.keepDatafield !== true) {
                this.datafield = {};
            }
            if (cfg.clearForm === true) {
                this.dataform = null;
            }
            if (cfg.clearResponse === true) {
                this.response = null;
            }
            return this;
        },

        send: function (action) {
            if (this.settings.viewer === false && typeof window.Form !== 'function') {
                this._showError('Form non disponibile', 'Impossibile leggere i dati della form.');
                return this;
            }

            this.dataform = (this.settings.viewer === false) ? window.Form().getFields(this.form) : {};
            this.beforeSend();

            var self = this;
            var url = this.getUrlForSend(action);
            if (!url) {
                this._showError('URL non configurato', 'Nessun URL valido per l\'azione richiesta.');
                return this;
            }

            var done = function (response) {
                if (response != null) {
                    self.response = response;
                }
                self.onSend(response);
            };

            var fail = function (error) {
                self.onSendError(error);
            };

            var requestApi = this._getRequestApi();
            if (!requestApi) {
                this._showError('Request non disponibile', this._requestUnavailableMessage());
                return this;
            }
            requestApi.http.post(url, this.dataform).then(done).catch(fail);

            return this;
        },

        submit: function (action) {
            return this.send(action);
        },

        setModalTitle: function (title) {
            if (!this.modal_id || !this.modal_id.length) {
                return this;
            }

            var modalTitle = this.modal_id.find('.modal-title');
            if (modalTitle.length !== 0) {
                modalTitle.html(title);
            }

            return this;
        },

        getUrlForSend: function (action) {
            var mode = String(action || '').trim();
            var data = (this.datafield && typeof this.datafield === 'object') ? this.datafield : {};

            switch (mode) {
                case 'edit':
                    if (data.id == null || data.id === '') {
                        return this.settings.urls.create;
                    }
                    return this.settings.urls.update;
                case 'delete':
                    if (data.id != null && data.id !== '') {
                        return this.settings.urls.delete;
                    }
                    return null;
                case 'get':
                    return this.settings.urls.get;
                case 'list':
                    return this.settings.urls.list;
                default:
                    return null;
            }
        },

        _normalizeErrorMessage: function (error) {
            if (typeof window !== 'undefined' && window.Request && typeof window.Request.getErrorMessage === 'function') {
                return window.Request.getErrorMessage(error, 'Operazione non riuscita.');
            }
            if (typeof error === 'string' && error.trim() !== '') {
                return error.trim();
            }
            return 'Operazione non riuscita.';
        },

        _requestUnavailableMessage: function () {
            if (typeof window !== 'undefined' && window.Request && typeof window.Request.getUnavailableMessage === 'function') {
                return window.Request.getUnavailableMessage();
            }
            return 'Servizio comunicazione non disponibile. Ricarica la pagina e riprova.';
        },

        onInit: function () {},
        beforeShow: function () {},
        afterShow: function () {},
        beforeHide: function () {},
        afterHide: function () {},
        beforeSend: function () {},
        onSend: function () {
            this.hide();
        },
        onSendError: function (error) {
            this._showError('Invio non riuscito', this._normalizeErrorMessage(error));
        },

        destroy: function () {
            if (this.modal_id && this.modal_id.length && this.lifecycleNs) {
                this.modal_id.off('show.bs.modal' + this.lifecycleNs);
                this.modal_id.off('shown.bs.modal' + this.lifecycleNs);
                this.modal_id.off('hide.bs.modal' + this.lifecycleNs);
                this.modal_id.off('hidden.bs.modal' + this.lifecycleNs);
            }

            if (this.modal && typeof this.modal.dispose === 'function') {
                this.modal.dispose();
            }

            this.lifecycleBound = false;
            this.modal = null;
            this.modal_id = null;
            this.modalNode = null;
            this.datafield = {};
            this.dataform = null;
            this.response = null;
            this.disposed = true;
            this.mounted = false;
            return this;
        },

        unmount: function () {
            return this.destroy();
        }
    };

    if (ext != null && ext.settings != null && typeof ext.settings === 'object') {
        var customSettings = Object.assign({}, ext.settings);
        var customUrls = (customSettings.urls && typeof customSettings.urls === 'object') ? customSettings.urls : {};
        delete customSettings.urls;

        base.settings = Object.assign({}, base.settings, customSettings);
        base.settings.urls = Object.assign({}, base.settings.urls, customUrls);
        delete ext.settings;
    }

    var o = Object.assign({}, base, ext);
    return o.init();
}

if (typeof window !== 'undefined') {
    window.Modal = Modal;
}
