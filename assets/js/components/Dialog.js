/**
 * @typedef {Object} DialogData
 * @property {string} [type]          - Tipo dialog: 'danger'|'warning'|'success'|'info'|'confirm'.
 * @property {string} [title]         - Titolo da mostrare nel dialog.
 * @property {string|HTMLElement} [body] - Corpo del dialog (testo o HTML).
 * @property {string} [confirmLabel]  - Testo del pulsante di conferma (default: 'Confermo').
 * @property {string} [confirmText]   - Alias di confirmLabel.
 * @property {string} [cancelLabel]   - Testo del pulsante annulla (default: 'Annulla').
 * @property {string} [cancelText]    - Alias di cancelLabel.
 * @property {function} [confirm]     - Callback invocato alla conferma.
 */

/**
 * @typedef {Object} DialogExtension
 * Mixin opzionale che sovrascrive metodi o aggiunge stato all'istanza Dialog.
 * Tutti i campi di `DialogData` sono accettati come shorthand nell'extension
 * (vengono applicati tramite `setData()` nel costruttore).
 */

/**
 * Crea e mostra un dialog modale Bootstrap riusabile.
 * Supporta tipologie: 'danger', 'warning', 'success', 'info', 'confirm'.
 *
 * Uso tipico:
 * ```js
 * Dialog('danger', { title: 'Errore', body: 'Operazione fallita.' }).show();
 * Dialog('confirm', { title: 'Sicuro?', body: 'Eliminare?' }, function() { doDelete(); }).show();
 * ```
 *
 * @param {string} type                     - Tipo del dialog (determina icona e colore).
 * @param {DialogData|DialogExtension} [extension] - Dati iniziali e/o override di metodi.
 * @param {function} [callbackConfirm]      - Shorthand per il callback di conferma.
 * @returns {Object} Istanza dialog con metodi `show()`, `hide()`, `setData()`, `dispose()`.
 */
function Dialog(type, extension, callbackConfirm) {
    var base = {
        type: null,
        dialog: null,
        btn_cancel: null,
        btn_confirm: null,
        title: null,
        body: null,
        callback: null,
        confirmLabel: 'Confermo',
        cancelLabel: 'Annulla',
        mounted: false,
        disposed: false,

        spinner: '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>',

        init: function () {
            this.disposed = false;
            this.mounted = true;
            if (type == null || String(type).trim() === '') {
                this._showFatal({
                    title: 'Tipologia non dichiarata',
                    body: 'Non e stata dichiarata la tipologia di Dialog da visualizzare'
                });
                return this;
            }

            this.type = String(type).trim().toLowerCase();
            this._setCallbackConfirm();
            this._refreshDialog(true);
            return this;
        },

        mount: function () {
            return this.init();
        },

        setData: function (data) {
            if (!data || typeof data !== 'object') {
                return this;
            }

            if (Object.prototype.hasOwnProperty.call(data, 'type') && data.type != null) {
                this.type = String(data.type).trim().toLowerCase();
            }
            if (Object.prototype.hasOwnProperty.call(data, 'title')) {
                this.title = data.title;
            }
            if (Object.prototype.hasOwnProperty.call(data, 'body')) {
                this.body = data.body;
            }
            if (Object.prototype.hasOwnProperty.call(data, 'confirmLabel')) {
                this.confirmLabel = String(data.confirmLabel || 'Confermo');
            } else if (Object.prototype.hasOwnProperty.call(data, 'confirmText')) {
                this.confirmLabel = String(data.confirmText || 'Confermo');
            }
            if (Object.prototype.hasOwnProperty.call(data, 'cancelLabel')) {
                this.cancelLabel = String(data.cancelLabel || 'Annulla');
            } else if (Object.prototype.hasOwnProperty.call(data, 'cancelText')) {
                this.cancelLabel = String(data.cancelText || 'Annulla');
            }
            if (typeof data.confirm === 'function') {
                this.callback = data.confirm;
            }

            return this;
        },

        _resolveConfirmDialog: function () {
            var dialog = (typeof window !== 'undefined') ? window.generalConfirm : null;

            if ((!dialog || typeof dialog.show !== 'function') &&
                typeof window !== 'undefined' &&
                window.SystemDialogs &&
                typeof window.SystemDialogs.ensureGeneralConfirm === 'function') {
                dialog = window.SystemDialogs.ensureGeneralConfirm();
            }

            return dialog || null;
        },

        _refreshDialog: function (showFatalOnMissing) {
            var dialog = this._resolveConfirmDialog();
            if (!dialog || !dialog.modal_id) {
                if (showFatalOnMissing === true) {
                    this._showFatal({
                        title: 'Dialog non disponibile',
                        body: 'Il dialogo di conferma non e disponibile in questa pagina.'
                    });
                }
                return false;
            }

            this.dialog = dialog;
            this.btn_cancel = this.dialog.modal_id.find('[name="btn-cancel"]');
            this.btn_confirm = this.dialog.modal_id.find('[name="btn-confirm"]');
            this._setDialogType();
            return true;
        },

        _showFatal: function (data) {
            var title = (data && data.title) ? data.title : 'Errore';
            var body = (data && data.body) ? data.body : 'Errore inatteso.';
            var fallback = null;

            if (typeof window !== 'undefined' &&
                window.SystemDialogs &&
                typeof window.SystemDialogs.ensureGeneralDialog === 'function') {
                fallback = window.SystemDialogs.ensureGeneralDialog();
            } else if (typeof window !== 'undefined' &&
                window.generalDialog &&
                typeof window.generalDialog.show === 'function') {
                fallback = window.generalDialog;
            }

            if (fallback && typeof fallback.show === 'function') {
                fallback.show({ title: title, body: body });
                return;
            }

            if (typeof alert === 'function') {
                alert(title + '\n' + String(body).replace(/<[^>]*>/g, ''));
            }
        },

        _setDialogType: function () {
            if (!this.dialog || !this.dialog.modal_id) {
                return this;
            }

            this._cleanElementsClasses();
            switch (this.type) {
                case 'confirm':
                case 'success':
                    this.dialog.modal_id.find('.modal-footer').addClass('text-bg-success');
                    this.btn_cancel.addClass('btn-light');
                    this.btn_confirm.addClass('btn-success');
                    break;
                case 'warning':
                    this.dialog.modal_id.find('.modal-footer').addClass('text-bg-warning');
                    this.btn_cancel.addClass('btn-light');
                    this.btn_confirm.addClass('btn-warning');
                    break;
                case 'danger':
                    this.dialog.modal_id.find('.modal-footer').addClass('text-bg-danger');
                    this.btn_cancel.addClass('btn-light');
                    this.btn_confirm.addClass('btn-danger');
                    break;
                case 'info':
                    this.dialog.modal_id.find('.modal-footer').addClass('text-bg-info');
                    this.btn_cancel.addClass('btn-light');
                    this.btn_confirm.addClass('btn-info');
                    break;
                case 'default':
                default:
                    this.dialog.modal_id.find('.modal-footer').addClass('text-bg-light');
                    this.btn_cancel.addClass('btn-dark');
                    this.btn_confirm.addClass('btn-light');
                    break;
            }

            return this;
        },

        _setCallbackConfirm: function () {
            var self = this;
            if (typeof callbackConfirm === 'function') {
                this.callback = callbackConfirm;
                return this;
            }

            if (typeof this.callback === 'function') {
                return this;
            }

            this.callback = function () {
                self.hide();
            };
            return this;
        },

        _cleanElementsClasses: function () {
            if (!this.dialog || !this.dialog.modal_id) {
                return this;
            }

            this.dialog.modal_id.find('.modal-footer').removeClass([
                'text-bg-light',
                'text-bg-success',
                'text-bg-warning',
                'text-bg-danger',
                'text-bg-info'
            ]);

            if (this.btn_cancel && this.btn_cancel.length) {
                this.btn_cancel.removeClass([
                    'btn-light',
                    'btn-success',
                    'btn-warning',
                    'btn-danger',
                    'btn-info'
                ]);
            }

            if (this.btn_confirm && this.btn_confirm.length) {
                this.btn_confirm.removeClass([
                    'btn-success',
                    'btn-light'
                ]);
            }

            return this;
        },

        setLoadingStatus: function () {
            if (this.btn_confirm && this.btn_confirm.length) {
                this.btn_confirm.html(this.spinner);
            }
            return this;
        },

        setNormalStatus: function () {
            if (this.btn_confirm && this.btn_confirm.length) {
                this.btn_confirm.html(this.confirmLabel);
            }
            if (this.btn_cancel && this.btn_cancel.length) {
                this.btn_cancel.text(this.cancelLabel);
            }
            return this;
        },

        setLabels: function (confirmLabel, cancelLabel) {
            if (confirmLabel != null) {
                this.confirmLabel = String(confirmLabel);
            }
            if (cancelLabel != null) {
                this.cancelLabel = String(cancelLabel);
            }
            return this.setNormalStatus();
        },

        resetState: function () {
            this.setNormalStatus();
            return this;
        },

        show: function (data) {
            if (this.disposed === true) {
                return this;
            }
            if (!this._refreshDialog(false)) {
                return this;
            }

            if (data && typeof data === 'object') {
                this.setData(data);
                this._setCallbackConfirm();
                this._setDialogType();
            }

            this.resetState();

            if (this.dialog && typeof this.dialog.show === 'function') {
                this.dialog.show({
                    title: this.title,
                    body: this.body,
                    confirm: this.callback
                });
            }

            return this;
        },

        hide: function () {
            if (this.disposed === true) {
                return this;
            }
            if (!this._refreshDialog(false)) {
                return this;
            }
            this.resetState();
            if (this.dialog && typeof this.dialog.hide === 'function') {
                this.dialog.hide();
            }
            return this;
        },

        destroy: function () {
            this.dialog = null;
            this.btn_cancel = null;
            this.btn_confirm = null;
            this.callback = null;
            this.disposed = true;
            this.mounted = false;
            return this;
        },

        unmount: function () {
            return this.destroy();
        }
    };

    var o = Object.assign({}, base, extension || {});
    return o.init();
}

if (typeof window !== 'undefined') {
    window.Dialog = Dialog;
}
