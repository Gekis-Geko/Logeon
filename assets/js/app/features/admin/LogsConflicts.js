const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

var AdminLogsConflicts = {
    initialized: false,
    root: null,
    grid: null,
    filtersForm: null,

    init: function () {
        if (this.initialized) {
            return this;
        }

        this.root = document.querySelector('#admin-page [data-admin-page="logs-conflicts"]');
        if (!this.root) {
            return this;
        }

        this.filtersForm = document.getElementById('admin-logs-conflicts-filters');
        if (!this.filtersForm || !document.getElementById('grid-admin-logs-conflicts')) {
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
            if (action === 'admin-logs-conflicts-reload') {
                event.preventDefault();
                self.loadGrid();
            }
        });
    },

    initGrid: function () {
        var self = this;

        this.grid = new Datagrid('grid-admin-logs-conflicts', {
            name: 'AdminLogsConflicts',
            autoindex: 'id',
            orderable: true,
            thead: true,
            handler: { url: '/admin/logs/conflicts/list', action: 'list' },
            nav: { display: 'bottom', urlupdate: 0, results: 30, page: 1 },
            columns: [
                { label: 'ID', field: 'id', sortable: true, style: { width: '70px' } },
                {
                    label: 'Attore',
                    field: 'actor_name',
                    sortable: true,
                    format: function (row) {
                        return self.escapeHtml(row.actor_name || '-');
                    }
                },
                {
                    label: 'Tipo tiro',
                    field: 'roll_type',
                    sortable: true,
                    format: function (row) {
                        return self.escapeHtml(row.roll_type || '-');
                    }
                },
                {
                    label: 'Dado',
                    field: 'die_used',
                    sortable: true,
                    format: function (row) {
                        return self.escapeHtml(row.die_used || '-');
                    }
                },
                {
                    label: 'Tiro base',
                    field: 'base_roll',
                    sortable: true,
                    format: function (row) {
                        return self.escapeHtml(String(row.base_roll != null ? row.base_roll : '-'));
                    }
                },
                {
                    label: 'Modificatori',
                    field: 'modifiers',
                    sortable: false,
                    format: function (row) {
                        return self.escapeHtml(String(row.modifiers != null ? row.modifiers : '-'));
                    }
                },
                {
                    label: 'Risultato',
                    field: 'final_result',
                    sortable: true,
                    format: function (row) {
                        return self.escapeHtml(String(row.final_result != null ? row.final_result : '-'));
                    }
                },
                {
                    label: 'Critico',
                    field: 'critical_flag',
                    sortable: true,
                    format: function (row) {
                        var val = row.critical_flag;
                        return (val === true || val === 1 || val === '1') ? 'Sì' : 'No';
                    }
                },
                {
                    label: 'Margine',
                    field: 'margin',
                    sortable: true,
                    format: function (row) {
                        return self.escapeHtml(String(row.margin != null ? row.margin : '-'));
                    }
                },
                {
                    label: 'Data/Ora',
                    field: 'timestamp',
                    sortable: true,
                    format: function (row) {
                        return self.formatDateTime(row.timestamp);
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
            var conflictId = this.filtersForm.elements.conflict_id;
            if (conflictId && conflictId.value) {
                q.conflict_id = parseInt(conflictId.value, 10) || 0;
            }
            var rollType = this.filtersForm.elements.roll_type;
            if (rollType && rollType.value) {
                q.roll_type = String(rollType.value).trim();
            }
            var dieUsed = this.filtersForm.elements.die_used;
            if (dieUsed && dieUsed.value) {
                q.die_used = String(dieUsed.value).trim();
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

globalWindow.AdminLogsConflicts = AdminLogsConflicts;
export { AdminLogsConflicts as AdminLogsConflicts };
export default AdminLogsConflicts;

