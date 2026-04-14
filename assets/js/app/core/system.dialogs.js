(function (window) {
    'use strict';

    function ensureGeneralConfirm() {
        if (typeof window.Modal !== 'function') {
            return null;
        }
        if (!document.getElementById('generic-confirm')) {
            return null;
        }
        if (window.generalConfirm && typeof window.generalConfirm.show === 'function') {
            return window.generalConfirm;
        }

        window.generalConfirm = window.Modal('generic-confirm', {
            settings: {
                backdrop: 'static',
                keyboard: false,
                viewer: true
            },
            beforeShow: function () {
                this.modal_id.find('h4').html(this.datafield.title || '');
                this.modal_id.find('[name="title"]').html(this.datafield.body || '');
                if (typeof this.datafield.confirm === 'function') {
                    this.confirm = this.datafield.confirm;
                    return;
                }

                var self = this;
                this.confirm = function () {
                    self.hide();
                };
            }
        });

        return window.generalConfirm;
    }

    function ensureGeneralConfirmDialog() {
        return ensureGeneralConfirm();
    }

    function getGeneralConfirmModal() {
        var dialog = ensureGeneralConfirm();
        if (!dialog || !dialog.modal_id) {
            return null;
        }
        return dialog.modal_id;
    }

    function hideGeneralConfirmDialog() {
        var dialog = ensureGeneralConfirm();
        if (dialog && typeof dialog.hide === 'function') {
            dialog.hide();
        }
    }

    function ensureGeneralDialog() {
        if (typeof window.Modal !== 'function') {
            return null;
        }
        if (!document.getElementById('generic-dialog')) {
            return null;
        }
        if (window.generalDialog && typeof window.generalDialog.show === 'function') {
            return window.generalDialog;
        }

        window.generalDialog = window.Modal('generic-dialog', {
            settings: {
                backdrop: 'static',
                keyboard: false,
                viewer: true
            },
            beforeShow: function () {
                this.modal_id.find('h4').html(this.datafield.title || '');
                this.modal_id.find('[name="title"]').html(this.datafield.body || '');
                if (typeof this.datafield.confirm === 'function') {
                    this.confirm = this.datafield.confirm;
                    return;
                }

                var self = this;
                this.confirm = function () {
                    self.hide();
                };
            }
        });

        return window.generalDialog;
    }

    function ensureToast() {
        if (!document.getElementById('system-toast')) {
            return null;
        }
        if (window.Toast && typeof window.Toast !== 'function' && typeof window.Toast.show === 'function') {
            return window.Toast;
        }

        if (typeof window.ToastFactory !== 'function' && typeof window.Toast === 'function') {
            window.ToastFactory = window.Toast;
        }
        if (typeof window.ToastFactory !== 'function') {
            return null;
        }

        window.Toast = window.ToastFactory('system-toast');
        return window.Toast;
    }

    function bindActions() {
        if (typeof window.$ === 'undefined') {
            return;
        }

        $('#generic-confirm').off('click.system-general-confirm');
        $('#generic-confirm').on('click.system-general-confirm', '[data-action="general-confirm-cancel"]', function (event) {
            event.preventDefault();
            if (window.generalConfirm && typeof window.generalConfirm.hide === 'function') {
                window.generalConfirm.hide();
            }
        });
        $('#generic-confirm').on('click.system-general-confirm', '[data-action="general-confirm-confirm"]', function (event) {
            event.preventDefault();
            if (window.generalConfirm && typeof window.generalConfirm.confirm === 'function') {
                window.generalConfirm.confirm();
            }
        });

        $('#generic-dialog').off('click.system-general-dialog');
        $('#generic-dialog').on('click.system-general-dialog', '[data-action="general-dialog-confirm"]', function (event) {
            event.preventDefault();
            if (window.generalDialog && typeof window.generalDialog.confirm === 'function') {
                window.generalDialog.confirm();
            }
        });
    }

    function start() {
        ensureGeneralConfirm();
        ensureGeneralDialog();
        ensureToast();
        bindActions();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            start();
        }, { once: true });
    } else {
        start();
    }

    window.SystemDialogs = window.SystemDialogs || {};
    window.SystemDialogs.start = start;
    window.SystemDialogs.ensureGeneralConfirm = ensureGeneralConfirm;
    window.SystemDialogs.ensureGeneralConfirmDialog = ensureGeneralConfirmDialog;
    window.SystemDialogs.getGeneralConfirmModal = getGeneralConfirmModal;
    window.SystemDialogs.hideGeneralConfirmDialog = hideGeneralConfirmDialog;
    window.SystemDialogs.ensureGeneralDialog = ensureGeneralDialog;
    window.SystemDialogs.ensureToast = ensureToast;
    window.ensureGeneralConfirmDialog = ensureGeneralConfirmDialog;
    window.getGeneralConfirmModal = getGeneralConfirmModal;
    window.hideGeneralConfirmDialog = hideGeneralConfirmDialog;
})(window);
