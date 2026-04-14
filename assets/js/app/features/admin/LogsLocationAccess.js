(function () {
    'use strict';

    var AdminLogsLocationAccess = {
        initialized: false,
        root: null,
        grid: null,
        filtersForm: null,

        init: function () {
            if (this.initialized) {
                return this;
            }

            this.root = document.querySelector('#admin-page [data-admin-page="logs-location-access"]');
            if (!this.root) {
                return this;
            }

            this.filtersForm = document.getElementById('admin-logs-location-access-filters');
            if (!this.filtersForm || !document.getElementById('grid-admin-logs-location-access')) {
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
                if (action === 'admin-logs-location-access-reload') {
                    event.preventDefault();
                    self.loadGrid();
                }
            });
        },

        initGrid: function () {
            var self = this;

            this.grid = new Datagrid('grid-admin-logs-location-access', {
                name: 'AdminLogsLocationAccess',
                autoindex: 'id',
                orderable: true,
                thead: true,
                handler: { url: '/admin/logs/location-access/list', action: 'list' },
                nav: { display: 'bottom', urlupdate: 0, results: 30, page: 1 },
                columns: [
                    { label: 'ID', field: 'id', sortable: true, style: { width: '70px' } },
                    {
                        label: 'Personaggio',
                        field: 'character_name',
                        sortable: true,
                        format: function (row) {
                            return self.escapeHtml(row.character_name || '-');
                        }
                    },
                    {
                        label: 'Luogo',
                        field: 'location_name',
                        sortable: true,
                        format: function (row) {
                            return self.escapeHtml(row.location_name || '-');
                        }
                    },
                    {
                        label: 'Consentito',
                        field: 'allowed',
                        sortable: true,
                        format: function (row) {
                            var val = row.allowed;
                            return (val === true || val === 1 || val === '1') ? 'Sì' : 'No';
                        }
                    },
                    {
                        label: 'Codice motivo',
                        field: 'reason_code',
                        sortable: true,
                        format: function (row) {
                            return self.escapeHtml(row.reason_code || '-');
                        }
                    },
                    {
                        label: 'Motivo',
                        field: 'reason',
                        sortable: false,
                        format: function (row) {
                            return self.escapeHtml(row.reason || '-');
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
                var characterId = this.filtersForm.elements.character_id;
                if (characterId && characterId.value) {
                    q.character_id = parseInt(characterId.value, 10) || 0;
                }
                var locationId = this.filtersForm.elements.location_id;
                if (locationId && locationId.value) {
                    q.location_id = parseInt(locationId.value, 10) || 0;
                }
                var allowed = this.filtersForm.elements.allowed;
                if (allowed && allowed.value !== '' && allowed.value !== 'all') {
                    q.allowed = allowed.value;
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

    window.AdminLogsLocationAccess = AdminLogsLocationAccess;
})();
