const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

var AdminLogsSys = {
    initialized: false,
    root: null,
    grid: null,
    filtersForm: null,
    currentUserIsSuperuser: false,

    init: function () {
        if (this.initialized) {
            return this;
        }

        this.root = document.querySelector('#admin-page [data-admin-page="logs-sys"]');
        if (!this.root) {
            return this;
        }

        this.filtersForm = document.getElementById('admin-logs-sys-filters');
        var gridEl = document.getElementById('grid-admin-logs-sys');
        if (!this.filtersForm || !gridEl) {
            return this;
        }

        this.currentUserIsSuperuser = parseInt(gridEl.getAttribute('data-current-user-is-superuser') || '0', 10) === 1;

        this.bind();
        this.initGrid();
        this.loadGrid();

        this.initialized = true;
        return this;
    },

    bind: function () {
        var self = this;

        this.filtersForm.addEventListener('submit', function (event) {
            event.preventDefault();
            self.loadGrid();
        });

        this.root.addEventListener('click', function (event) {
            var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
            if (!trigger) {
                return;
            }

            var action = String(trigger.getAttribute('data-action') || '').trim();
            if (action === 'admin-logs-sys-reload') {
                event.preventDefault();
                self.loadGrid();
            }
        });
    },

    initGrid: function () {
        var self = this;

        this.grid = new Datagrid('grid-admin-logs-sys', {
            name: 'AdminLogsSys',
            autoindex: 'id',
            orderable: true,
            thead: true,
            handler: { url: '/admin/logs/sys/list', action: 'list' },
            nav: { display: 'bottom', urlupdate: 0, results: 30, page: 1 },
            columns: [
                { label: 'ID', field: 'id', sortable: true, style: { width: '70px' } },
                {
                    label: 'Autore',
                    field: self.currentUserIsSuperuser ? 'author_email' : 'author_name',
                    sortable: false,
                    format: function (row) {
                        return self.renderIdentity(row);
                    }
                },
                {
                    label: 'Area',
                    field: 'area',
                    sortable: true,
                    format: function (row) {
                        return self.escapeHtml(row.area || '-');
                    }
                },
                {
                    label: 'Modulo',
                    field: 'module',
                    sortable: true,
                    format: function (row) {
                        return self.escapeHtml(row.module || '-');
                    }
                },
                {
                    label: 'Azione',
                    field: 'action',
                    sortable: true,
                    format: function (row) {
                        return self.escapeHtml(row.action || '-');
                    }
                },
                {
                    label: 'URL',
                    field: 'url',
                    sortable: false,
                    format: function (row) {
                        return self.escapeHtml(row.url || '-');
                    }
                },
                {
                    label: 'Data/Ora',
                    field: 'date_created',
                    sortable: true,
                    format: function (row) {
                        return self.formatDateTime(row.date_created);
                    }
                }
            ]
        });
    },

    loadGrid: function () {
        if (!this.grid) {
            return this;
        }

        this.grid.setFilters(this.buildFiltersPayload());
        return this;
    },

    buildFiltersPayload: function () {
        var q = {};

        if (this.filtersForm) {
            var area = this.filtersForm.elements.area;
            if (area && area.value) {
                q.area = String(area.value).trim();
            }
            var action = this.filtersForm.elements.action;
            if (action && action.value) {
                q.action = String(action.value).trim();
            }
            var author = this.filtersForm.elements.author;
            if (author && author.value) {
                q.author = parseInt(author.value, 10) || 0;
            }
        }

        return q;
    },

    renderIdentity: function (row) {
        var characterName = String(row && row.author_name ? row.author_name : '').trim() || 'Nessun personaggio associato';
        var email = String(row && row.author_email ? row.author_email : '').trim();

        if (this.currentUserIsSuperuser === true) {
            return '<div class="fw-semibold">' + this.escapeHtml(email || '-') + '</div>'
                 + '<div class="small text-muted">' + this.escapeHtml(characterName) + '</div>';
        }

        return '<div class="fw-semibold">' + this.escapeHtml(characterName) + '</div>';
    },

    escapeHtml: function (value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    },

    escapeAttr: function (value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    },

    formatDateTime: function (value) {
        var raw = String(value || '').trim();
        if (!raw) return '-';
        var date = new Date(raw.replace(' ', 'T'));
        if (isNaN(date.getTime())) return this.escapeHtml(raw);
        return date.toLocaleString('it-IT', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' });
    },

    formatNumber: function (value) {
        var parsed = parseFloat(value);
        if (isNaN(parsed)) return '-';
        if (typeof Utils === 'function') return Utils().formatNumber(parsed);
        return parsed.toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },

    formatSigned: function (value) {
        var parsed = parseFloat(value);
        if (isNaN(parsed)) return '-';
        return (parsed > 0 ? '+' : '') + this.formatNumber(parsed);
    }
};

globalWindow.AdminLogsSys = AdminLogsSys;
export { AdminLogsSys as AdminLogsSys };
export default AdminLogsSys;

