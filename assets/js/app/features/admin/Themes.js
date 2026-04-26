const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

var AdminThemes = {
    initialized: false,
    root: null,
    form: null,
    grid: null,
    rawDataset: [],
    rowsById: {},
    searchInput: null,
    statusFilter: null,
    summary: null,
    switchSyncLocks: {},

    init: function () {
        if (this.initialized) {
            return this;
        }

        this.root = document.querySelector('#admin-page [data-admin-page="themes"]');
        if (!this.root || !document.getElementById('grid-admin-themes')) {
            return this;
        }

        this.form = this.root.querySelector('#admin-themes-filters');
        this.searchInput = this.root.querySelector('#admin-themes-filter-query');
        this.statusFilter = this.root.querySelector('#admin-themes-filter-status');
        this.summary = {
            active: this.root.querySelector('[data-role="themes-summary-active"]'),
            inactive: this.root.querySelector('[data-role="themes-summary-inactive"]'),
            invalid: this.root.querySelector('[data-role="themes-summary-invalid"]')
        };

        this.bindEvents();
        this.initGrid();
        this.loadGrid();

        this.initialized = true;
        return this;
    },

    bindEvents: function () {
        var self = this;

        if (this.form) {
            this.form.addEventListener('submit', function (event) {
                event.preventDefault();
            });
        }

        if (this.searchInput) {
            this.searchInput.addEventListener('input', function () {
                self.refreshGridData();
            });
        }
        if (this.statusFilter) {
            this.statusFilter.addEventListener('change', function () {
                self.refreshGridData();
            });
        }

        this.root.addEventListener('click', function (event) {
            var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
            if (!trigger) {
                return;
            }

            var action = String(trigger.getAttribute('data-action') || '').trim();
            if (action === 'admin-themes-reload') {
                event.preventDefault();
                self.loadGrid();
                return;
            }
            if (action === 'admin-themes-reset-filters') {
                event.preventDefault();
                if (self.searchInput) {
                    self.searchInput.value = '';
                }
                if (self.statusFilter) {
                    self.statusFilter.value = 'all';
                }
                self.refreshGridData();
            }
        });
    },

    initGrid: function () {
        var self = this;
        this.grid = new Datagrid('grid-admin-themes', {
            name: 'AdminThemes',
            autoindex: 'id',
            orderable: true,
            thead: true,
            handler: {
                url: '/admin/themes/list',
                action: 'list'
            },
            nav: {
                display: 'bottom',
                urlupdate: 0,
                results: 20,
                page: 1
            },
            onGetDataSuccess: function (response) {
                self.rawDataset = self.extractDataset(response);
                self.rowsById = self.indexById(self.rawDataset);
                self.updateSummary(self.rawDataset);
                self.refreshGridData();
            },
            onGetDataError: function () {
                self.rawDataset = [];
                self.rowsById = {};
                self.updateSummary([]);
                self.refreshGridData();
            },
            columns: [
                {
                    label: 'ID',
                    field: 'id',
                    sortable: true,
                    style: { textAlign: 'left' }
                },
                {
                    label: 'Tema',
                    field: 'name',
                    sortable: true,
                    style: { textAlign: 'left' },
                    format: function (row) {
                        return ''
                            + '<div><b>' + self.escapeHtml(row.name || row.id || '-') + '</b></div>'
                            + '<div class="small text-muted">' + self.escapeHtml(row.id || '-') + ' · v' + self.escapeHtml(row.version || '-') + '</div>'
                            + ((row.author && String(row.author).trim() !== '')
                                ? '<div class="small text-muted">Autore: ' + self.escapeHtml(row.author) + '</div>'
                                : '');
                    }
                },
                {
                    label: 'Shell',
                    field: 'shell',
                    sortable: false,
                    style: { textAlign: 'left' },
                    format: function (row) {
                        return '<span class="small text-muted">' + self.escapeHtml(row.shell || '-') + '</span>';
                    }
                },
                {
                    label: 'Compatibilita',
                    field: 'compat',
                    sortable: true,
                    style: { textAlign: 'left' },
                    format: function (row) {
                        return self.escapeHtml(row.compat || '-');
                    }
                },
                {
                    label: 'Note',
                    sortable: false,
                    style: { textAlign: 'left' },
                    format: function (row) {
                        var description = String(row.description || '').trim();
                        var errors = Array.isArray(row.errors) ? row.errors : [];
                        var out = [];
                        if (description !== '') {
                            out.push('<div class="small text-muted">' + self.escapeHtml(description) + '</div>');
                        }
                        if (errors.length > 0) {
                            out.push('<div class="small text-warning mt-1"><i class="bi bi-exclamation-triangle-fill me-1"></i>' + self.escapeHtml(errors.join(' | ')) + '</div>');
                        }
                        if (out.length === 0) {
                            return '<span class="small text-muted">Nessuna nota</span>';
                        }
                        return out.join('');
                    }
                },
                {
                    label: 'Stato',
                    sortable: false,
                    style: { textAlign: 'left' },
                    format: function (row) {
                        var themeId = String(row.id || '').trim();
                        if (themeId !== '') {
                            self.rowsById[themeId] = row;
                        }
                        var active = parseInt(row.is_active, 10) === 1;
                        var canToggle = parseInt(row.is_valid, 10) === 1;
                        var title = canToggle
                            ? (active ? 'Disattiva tema' : 'Attiva tema')
                            : 'Tema non valido: controlla gli errori';
                        return ''
                            + '<input type="hidden"'
                            + ' data-role="admin-theme-status-switch"'
                            + ' data-id="' + self.escapeHtml(themeId) + '"'
                            + ' data-valid="' + (canToggle ? '1' : '0') + '"'
                            + ' value="' + (active ? '1' : '0') + '"'
                            + ' title="' + self.escapeHtml(title) + '">';
                    }
                }
            ]
        });
    },

    loadGrid: function () {
        if (!this.grid) {
            return this;
        }
        this.rawDataset = [];
        this.rowsById = {};
        this.grid.loadData({}, 20, 1, '__default__|ASC');
        return this;
    },

    extractDataset: function (response) {
        if (response && Array.isArray(response.dataset)) {
            return response.dataset.slice();
        }
        if (this.grid && Array.isArray(this.grid.dataset)) {
            return this.grid.dataset.slice();
        }
        return [];
    },

    indexById: function (dataset) {
        var map = {};
        for (var i = 0; i < dataset.length; i++) {
            var row = dataset[i] || {};
            var id = String(row.id || '').trim();
            if (id !== '') {
                map[id] = row;
            }
        }
        return map;
    },

    updateSummary: function (dataset) {
        var active = 0;
        var inactive = 0;
        var invalid = 0;

        for (var i = 0; i < dataset.length; i++) {
            var row = dataset[i] || {};
            var isActive = parseInt(row.is_active, 10) === 1;
            var isValid = parseInt(row.is_valid, 10) === 1;
            if (isActive) {
                active += 1;
            } else {
                inactive += 1;
            }
            if (!isValid) {
                invalid += 1;
            }
        }

        if (this.summary && this.summary.active) {
            this.summary.active.textContent = String(active);
        }
        if (this.summary && this.summary.inactive) {
            this.summary.inactive.textContent = String(inactive);
        }
        if (this.summary && this.summary.invalid) {
            this.summary.invalid.textContent = String(invalid);
        }
    },

    refreshGridData: function () {
        if (!this.grid) {
            return this;
        }

        var filtered = this.filterDataset(this.rawDataset.slice());
        this.grid.dataset = filtered;
        this.grid.rebuildIndex();
        this.grid.updateTable();
        this.mountStatusSwitches();
        return this;
    },

    filterDataset: function (dataset) {
        var mode = this.statusFilter ? String(this.statusFilter.value || 'all').trim().toLowerCase() : 'all';
        var query = this.searchInput ? String(this.searchInput.value || '').trim().toLowerCase() : '';

        var rows = dataset.filter(function (row) {
            var isActive = parseInt(row.is_active, 10) === 1;
            var isValid = parseInt(row.is_valid, 10) === 1;
            if (mode === 'active') {
                return isActive;
            }
            if (mode === 'inactive') {
                return !isActive;
            }
            if (mode === 'invalid') {
                return !isValid;
            }
            return true;
        });

        if (query === '') {
            return rows;
        }

        return rows.filter(function (row) {
            var haystack = [
                row.id || '',
                row.name || '',
                row.author || '',
                row.version || '',
                row.description || ''
            ].join(' ').toLowerCase();
            return haystack.indexOf(query) !== -1;
        });
    },

    mountStatusSwitches: function () {
        if (!this.root || typeof globalWindow.$ !== 'function' || typeof globalWindow.SwitchGroup !== 'function') {
            return this;
        }

        var self = this;
        var inputs = globalWindow.$(this.root).find('input[data-role="admin-theme-status-switch"]');
        inputs.each(function () {
            var field = globalWindow.$(this);
            var themeId = String(field.attr('data-id') || '').trim();
            if (themeId === '') {
                return;
            }

            var row = self.rowsById[themeId] || null;
            var active = row ? (parseInt(row.is_active, 10) === 1) : (String(field.val()) === '1');
            var isValid = String(field.attr('data-valid') || '0') === '1';

            self.switchSyncLocks[themeId] = true;
            field.val(active ? '1' : '0');
            self.switchSyncLocks[themeId] = false;

            var switchInstance = field.data('__switchGroupInstance');
            if (!switchInstance || typeof switchInstance.refresh !== 'function') {
                switchInstance = globalWindow.SwitchGroup(field, {
                    preset: 'activeinactive',
                    trueValue: '1',
                    falseValue: '0'
                });
            } else {
                switchInstance.refresh();
            }

            if (!isValid) {
                field.prop('disabled', true);
                return;
            }

            field.prop('disabled', false);
            field.off('change.admin-themes-switch').on('change.admin-themes-switch', function () {
                if (self.switchSyncLocks[themeId] === true) {
                    return;
                }

                var currentRow = self.rowsById[themeId] || row;
                if (!currentRow) {
                    return;
                }

                var currentActive = parseInt(currentRow.is_active, 10) === 1;
                var nextActive = String(field.val()) === '1';
                if (currentActive === nextActive) {
                    return;
                }

                var rollback = function () {
                    self.switchSyncLocks[themeId] = true;
                    field.val(currentActive ? '1' : '0').change();
                    self.switchSyncLocks[themeId] = false;
                };

                if (nextActive) {
                    self.activateTheme(currentRow, rollback);
                    return;
                }
                self.deactivateTheme(currentRow, rollback);
            });
        });
        return this;
    },

    activateTheme: function (row, onCancelled) {
        var self = this;
        if (!row || !row.id) {
            return;
        }

        var currentActive = this.getCurrentActiveThemeId();
        var replaceNote = '';
        if (currentActive !== '' && currentActive !== String(row.id)) {
            replaceNote = '<div class="small text-muted mt-2">Il tema attivo <b>' + this.escapeHtml(currentActive) + '</b> verra disattivato automaticamente.</div>';
        }

        this.confirmAction(
            'Attiva tema',
            'Attivare il tema <b>' + this.escapeHtml(row.name || row.id) + '</b>?' + replaceNote,
            function () {
                self.requestPost('/admin/themes/activate', { theme_id: row.id }, function (response) {
                    var replacedId = '';
                    if (response && response.dataset) {
                        replacedId = String(response.dataset.previous_active_theme || '').trim();
                    }
                    var message = 'Tema attivato.';
                    if (replacedId !== '') {
                        message = 'Tema attivato. Tema precedente disattivato: ' + replacedId + '.';
                    }
                    Toast.show({ body: message, type: 'success' });
                    self.loadGrid();
                }, function () {
                    if (typeof onCancelled === 'function') {
                        onCancelled();
                    }
                });
            },
            function () {
                if (typeof onCancelled === 'function') {
                    onCancelled();
                }
            }
        );
    },

    getCurrentActiveThemeId: function () {
        var map = this.rowsById || {};
        var keys = Object.keys(map);
        for (var i = 0; i < keys.length; i++) {
            var row = map[keys[i]] || {};
            if (parseInt(row.is_active, 10) === 1) {
                return String(row.id || '').trim();
            }
        }
        return '';
    },

    deactivateTheme: function (row, onCancelled) {
        var self = this;
        if (!row || !row.id) {
            return;
        }

        this.confirmAction(
            'Disattiva tema',
            'Disattivare il tema attivo e tornare al layout core?',
            function () {
                self.requestPost('/admin/themes/deactivate', { theme_id: row.id }, function () {
                    Toast.show({ body: 'Tema disattivato.', type: 'success' });
                    self.loadGrid();
                }, function () {
                    if (typeof onCancelled === 'function') {
                        onCancelled();
                    }
                });
            },
            function () {
                if (typeof onCancelled === 'function') {
                    onCancelled();
                }
            }
        );
    },

    confirmAction: function (title, body, onConfirm, onCancel) {
        if (typeof Dialog === 'function') {
            var dialogRef = null;
            var confirmed = false;
            var dialogModal = null;
            var hiddenNs = '.admin-themes-confirm';

            if (globalWindow.SystemDialogs && typeof globalWindow.SystemDialogs.getGeneralConfirmModal === 'function') {
                dialogModal = globalWindow.SystemDialogs.getGeneralConfirmModal();
            }
            if (dialogModal && dialogModal.length) {
                dialogModal.off('hidden.bs.modal' + hiddenNs).on('hidden.bs.modal' + hiddenNs, function () {
                    dialogModal.off('hidden.bs.modal' + hiddenNs);
                    if (!confirmed && typeof onCancel === 'function') {
                        onCancel();
                    }
                });
            }

            dialogRef = Dialog('warning', { title: title, body: '<p>' + body + '</p>' }, function () {
                confirmed = true;
                if (dialogRef && typeof dialogRef.hide === 'function') {
                    dialogRef.hide();
                }
                if (typeof onConfirm === 'function') {
                    onConfirm();
                }
            });
            dialogRef.show();
            return;
        }

        if (globalWindow.confirm((title || 'Conferma') + '\n\n' + String(body || '').replace(/<[^>]+>/g, ''))) {
            if (typeof onConfirm === 'function') {
                onConfirm();
            }
        } else if (typeof onCancel === 'function') {
            onCancel();
        }
    },

    requestPost: function (url, payload, onSuccess, onFail) {
        var self = this;
        var csrfToken = this.getCsrfToken();
        var headers = { 'X-Requested-With': 'XMLHttpRequest' };
        if (csrfToken !== '') {
            headers['X-CSRF-Token'] = csrfToken;
        }

        $.ajax({
            method: 'POST',
            url: url,
            headers: headers,
            data: {
                action: 'list',
                _csrf: csrfToken,
                data: JSON.stringify(payload || {})
            }
        }).done(function (response) {
            if (typeof onSuccess === 'function') {
                onSuccess(response || {});
            }
        }).fail(function (xhr) {
            if (typeof onFail === 'function') {
                var handled = onFail(xhr);
                if (handled === true) {
                    return;
                }
            }
            Toast.show({ body: self.requestErrorMessage(xhr), type: 'danger' });
            self.loadGrid();
        });
    },

    requestErrorMessage: function (xhr) {
        var response = null;
        if (xhr && xhr.responseJSON && typeof xhr.responseJSON === 'object') {
            response = xhr.responseJSON;
        } else if (xhr && typeof xhr.responseText === 'string' && xhr.responseText.trim() !== '') {
            try {
                response = JSON.parse(xhr.responseText);
            } catch (e) {
                response = null;
            }
        }
        if (response && typeof response.error === 'string' && response.error.trim() !== '') {
            return response.error.trim();
        }
        return 'Operazione non riuscita.';
    },

    getCsrfToken: function () {
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (!meta) {
            return '';
        }
        return String(meta.getAttribute('content') || '').trim();
    },

    escapeHtml: function (value) {
        return $('<div/>').text(value || '').html();
    }
};

globalWindow.AdminThemes = AdminThemes;
export { AdminThemes as AdminThemes };
export default AdminThemes;

