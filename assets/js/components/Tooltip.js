function Tooltip(extension) {
    var base = {
        globalBound: false,
        fallbackEventsBound: false,
        fallbackHandlers: null,

        init: function (context) {
            if (typeof bootstrap === 'undefined' || !bootstrap.Tooltip) {
                return this;
            }

            var root = context || document;
            var nodes = [];
            if (root && root.querySelectorAll) {
                nodes = root.querySelectorAll('[data-bs-toggle="tooltip"]');
            } else {
                nodes = document.querySelectorAll('[data-bs-toggle="tooltip"]');
            }

            nodes.forEach(function (el) {
                var title = el.getAttribute('data-bs-title');
                if (title === null || title === '') {
                    title = el.getAttribute('title');
                }

                var current = bootstrap.Tooltip.getInstance(el);
                if (current) {
                    current.dispose();
                }

                if (title === null || title === '') {
                    return;
                }

                new bootstrap.Tooltip(el, {
                    title: title,
                    trigger: 'hover',
                    container: 'body',
                    boundary: 'window'
                });
            });

            return this;
        },

        hideAll: function () {
            if (typeof bootstrap === 'undefined' || !bootstrap.Tooltip) {
                return this;
            }

            document.querySelectorAll('[data-bs-toggle="tooltip"][aria-describedby]').forEach(function (el) {
                var instance = bootstrap.Tooltip.getInstance(el);
                if (instance) {
                    instance.hide();
                }
            });

            document.querySelectorAll('.tooltip.show').forEach(function (tip) {
                tip.remove();
            });

            return this;
        },

        dispose: function (context) {
            if (typeof bootstrap === 'undefined' || !bootstrap.Tooltip) {
                return this;
            }

            var root = context || document;
            var nodes = [];
            if (root && root.querySelectorAll) {
                nodes = root.querySelectorAll('[data-bs-toggle="tooltip"]');
            } else {
                nodes = document.querySelectorAll('[data-bs-toggle="tooltip"]');
            }

            nodes.forEach(function (el) {
                var instance = bootstrap.Tooltip.getInstance(el);
                if (instance) {
                    instance.dispose();
                }
            });

            return this;
        },

        bindGlobalGuards: function () {
            if (this.globalBound) {
                return this;
            }
            this.globalBound = true;

            var self = this;
            if (typeof window.$ !== 'undefined') {
                $(document).off('click.app_tooltip');
                $(document).on('click.app_tooltip', function () {
                    self.hideAll();
                });

                $(document).off('shown.bs.modal.app_tooltip hidden.bs.modal.app_tooltip shown.bs.offcanvas.app_tooltip hidden.bs.offcanvas.app_tooltip');
                $(document).on('shown.bs.modal.app_tooltip hidden.bs.modal.app_tooltip shown.bs.offcanvas.app_tooltip hidden.bs.offcanvas.app_tooltip', function () {
                    self.hideAll();
                });

                return this;
            }

            if (!this.fallbackEventsBound) {
                this.fallbackEventsBound = true;
                this.fallbackHandlers = {
                    click: function () {
                        self.hideAll();
                    },
                    shownModal: function () {
                        self.hideAll();
                    },
                    hiddenModal: function () {
                        self.hideAll();
                    },
                    shownOffcanvas: function () {
                        self.hideAll();
                    },
                    hiddenOffcanvas: function () {
                        self.hideAll();
                    }
                };

                document.addEventListener('click', this.fallbackHandlers.click);
                document.addEventListener('shown.bs.modal', this.fallbackHandlers.shownModal);
                document.addEventListener('hidden.bs.modal', this.fallbackHandlers.hiddenModal);
                document.addEventListener('shown.bs.offcanvas', this.fallbackHandlers.shownOffcanvas);
                document.addEventListener('hidden.bs.offcanvas', this.fallbackHandlers.hiddenOffcanvas);
            }

            return this;
        },

        unbindGlobalGuards: function () {
            if (typeof window.$ !== 'undefined') {
                $(document).off('click.app_tooltip');
                $(document).off('shown.bs.modal.app_tooltip hidden.bs.modal.app_tooltip shown.bs.offcanvas.app_tooltip hidden.bs.offcanvas.app_tooltip');
            }

            if (this.fallbackEventsBound && this.fallbackHandlers) {
                document.removeEventListener('click', this.fallbackHandlers.click);
                document.removeEventListener('shown.bs.modal', this.fallbackHandlers.shownModal);
                document.removeEventListener('hidden.bs.modal', this.fallbackHandlers.hiddenModal);
                document.removeEventListener('shown.bs.offcanvas', this.fallbackHandlers.shownOffcanvas);
                document.removeEventListener('hidden.bs.offcanvas', this.fallbackHandlers.hiddenOffcanvas);
            }

            this.fallbackHandlers = null;
            this.fallbackEventsBound = false;
            this.globalBound = false;
            return this;
        },

        destroy: function (context) {
            this.hideAll();
            this.dispose(context || document);
            this.unbindGlobalGuards();
            return this;
        }
    };

    if (typeof window !== 'undefined') {
        if (!window.__tooltip_instance) {
            window.__tooltip_instance = Object.assign({}, base, extension);
        } else if (extension && typeof extension === 'object') {
            Object.assign(window.__tooltip_instance, extension);
        }
        return window.__tooltip_instance;
    }

    return Object.assign({}, base, extension);
}

if (typeof window !== 'undefined') {
    window.Tooltip = Tooltip;
}
