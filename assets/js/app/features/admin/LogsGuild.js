const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

var AdminLogsGuild = {
    initialized: false,
    root: null,
    grid: null,
    filtersForm: null,

    init: function () {
        if (this.initialized) {
            return this;
        }

        this.root = document.querySelector('#admin-page [data-admin-page="logs-guild"]');
        if (!this.root) {
            return this;
        }

        this.filtersForm = document.getElementById('admin-logs-guild-filters');
        if (!this.filtersForm || !document.getElementById('grid-admin-logs-guild')) {
            return this;
        }

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
            if (action === 'admin-logs-guild-reload') {
                event.preventDefault();
                self.loadGrid();
            }
        });
    },

    initGrid: function () {
        var self = this;

        this.grid = new Datagrid('grid-admin-logs-guild', {
            name: 'AdminLogsGuild',
            autoindex: 'id',
            orderable: true,
            thead: true,
            handler: { url: '/admin/logs/guild/list', action: 'list' },
            nav: { display: 'bottom', urlupdate: 0, results: 30, page: 1 },
            columns: [
                { label: 'ID', field: 'id', sortable: true, style: { width: '70px' } },
                {
                    label: 'Gilda',
                    field: 'guild_name',
                    sortable: true,
                    format: function (row) {
                        return self.escapeHtml(row.guild_name || '-');
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
                    label: 'Attore',
                    field: 'actor_name',
                    sortable: true,
                    format: function (row) {
                        return self.escapeHtml(row.actor_name || '-');
                    }
                },
                {
                    label: 'Destinatario',
                    field: 'target_name',
                    sortable: true,
                    format: function (row) {
                        return self.escapeHtml(row.target_name || '-');
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
            var guildId = this.filtersForm.elements.guild_id;
            if (guildId && guildId.value) {
                q.guild_id = parseInt(guildId.value, 10) || 0;
            }
            var action = this.filtersForm.elements.action;
            if (action && action.value) {
                q.action = String(action.value).trim();
            }
        }

        return q;
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

globalWindow.AdminLogsGuild = AdminLogsGuild;
export { AdminLogsGuild as AdminLogsGuild };
export default AdminLogsGuild;

