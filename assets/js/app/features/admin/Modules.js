(function () {
    'use strict';

    var STATUS_WEIGHT = {
        error: 0,
        detected: 1,
        inactive: 2,
        installed: 3,
        active: 4
    };

    var AdminModules = {
        initialized: false,
        root: null,
        grid: null,
        rawDataset: [],
        rowsById: {},
        summary: null,
        statusFilter: null,
        searchInput: null,
        switchSyncLocks: {},
        filtersForm: null,

        init: function () {
            if (this.initialized) {
                return this;
            }

            this.root = document.querySelector('#admin-page [data-admin-page="modules"]');
            if (!this.root || !document.getElementById('grid-admin-modules')) {
                return this;
            }

            this.summary = {
                active: this.root.querySelector('[data-role="modules-summary-active"]'),
                inactive: this.root.querySelector('[data-role="modules-summary-inactive"]'),
                detected: this.root.querySelector('[data-role="modules-summary-detected"]'),
                issues: this.root.querySelector('[data-role="modules-summary-issues"]')
            };
            this.statusFilter = this.root.querySelector('[data-role="admin-modules-filter-status"]');
            this.searchInput = this.root.querySelector('[data-role="admin-modules-filter-query"]');
            this.filtersForm = this.root.querySelector('[data-role="admin-modules-filters"]');

            this.bindEvents();
            this.initGrid();
            this.loadGrid();

            this.initialized = true;
            return this;
        },

        bindEvents: function () {
            var self = this;

            if (this.statusFilter) {
                this.statusFilter.addEventListener('change', function () {
                    self.refreshGridData();
                });
            }
            if (this.searchInput) {
                this.searchInput.addEventListener('input', function () {
                    self.refreshGridData();
                });
            }
            if (this.filtersForm) {
                this.filtersForm.addEventListener('submit', function (event) {
                    event.preventDefault();
                });
            }

            this.root.addEventListener('click', function (event) {
                var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
                if (!trigger) {
                    return;
                }

                var action = String(trigger.getAttribute('data-action') || '').trim();
                if (action === 'admin-modules-reload') {
                    event.preventDefault();
                    self.loadGrid();
                    return;
                }

                if (action === 'admin-modules-audit') {
                    event.preventDefault();
                    self.runAudit();
                    return;
                }

                if (action === 'admin-modules-reset-filters') {
                    event.preventDefault();
                    if (self.searchInput) {
                        self.searchInput.value = '';
                    }
                    if (self.statusFilter) {
                        self.statusFilter.value = 'all';
                    }
                    self.refreshGridData();
                    return;
                }

                if (action === 'admin-module-activate') {
                    event.preventDefault();
                    self.activateModule(self.findRowByTrigger(trigger));
                    return;
                }

                if (action === 'admin-module-deactivate') {
                    event.preventDefault();
                    self.deactivateModule(self.findRowByTrigger(trigger));
                    return;
                }

                if (action === 'admin-module-uninstall-safe') {
                    event.preventDefault();
                    self.uninstallModule(self.findRowByTrigger(trigger), false);
                    return;
                }

                if (action === 'admin-module-uninstall-purge') {
                    event.preventDefault();
                    self.uninstallModule(self.findRowByTrigger(trigger), true);
                }
            });
        },

        initGrid: function () {
            var self = this;
            this.grid = new Datagrid('grid-admin-modules', {
                name: 'AdminModules',
                autoindex: 'id',
                orderable: true,
                thead: true,
                handler: {
                    url: '/admin/modules/list',
                    action: 'list'
                },
                nav: {
                    display: 'bottom',
                    urlupdate: 0,
                    results: 20,
                    page: 1
                },
                onGetDataSuccess: function (response) {
                    self.onGridSuccess(response);
                },
                onGetDataError: function () {
                    self.rawDataset = [];
                    self.rowsById = {};
                    self.updateSummary([]);
                    self.refreshGridData();
                },
                columns: [
                    { label: 'ID', field: 'id', sortable: true, style: { textAlign: 'left' } },
                    {
                        label: 'Modulo',
                        field: 'name',
                        sortable: true,
                        style: { textAlign: 'left' },
                        format: function (row) {
                            var name = self.escapeHtml(row.name || row.id || '-');
                            var id = self.escapeHtml(row.id || '-');
                            var vendor = self.escapeHtml(row.vendor || '-');
                            var version = self.escapeHtml(row.version || '-');
                            return ''
                                + '<div><b>' + name + '</b></div>'
                                + '<div class="small text-muted">' + id + '</div>'
                                + '<div class="small text-muted">Vendor: ' + vendor + ' · v' + version + '</div>';
                        }
                    },
                    {
                        label: 'Attivo',
                        field: 'status',
                        sortable: true,
                        format: function (row) {
                            return self.statusBadge(row.status || 'detected');
                        }
                    },
                    {
                        label: 'Compatibilita core',
                        field: 'core_compatible',
                        sortable: true,
                        format: function (row) {
                            var ok = parseInt(row.core_compatible, 10) === 1;
                            var min = self.escapeHtml(row.core_min || '-');
                            var max = self.escapeHtml(row.core_max || '-');
                            return ''
                                + (ok
                                    ? '<span class="badge text-bg-success">Compatibile</span>'
                                    : '<span class="badge text-bg-danger">Non compatibile</span>')
                                + '<div class="small text-muted mt-1">Core min: ' + min + ' · max: ' + max + '</div>';
                        }
                    },
                    {
                        label: 'Dipendenze',
                        field: 'dependencies_required',
                        sortable: false,
                        style: { textAlign: 'left' },
                        format: function (row) {
                            return self.renderDependencies(row);
                        }
                    },
                    {
                        label: 'Note',
                        sortable: false,
                        style: { textAlign: 'left' },
                        format: function (row) {
                            return self.renderNotes(row);
                        }
                    },
                    {
                        label: 'Governance',
                        sortable: false,
                        style: { textAlign: 'left' },
                        format: function (row) {
                            return self.renderGovernance(row);
                        }
                    },
                    {
                        label: 'Stato',
                        sortable: false,
                        style: { textAlign: 'left' },
                        format: function (row) {
                            var moduleId = String(row.id || '').trim();
                            if (moduleId !== '') {
                                self.rowsById[moduleId] = row;
                            }

                            var active = parseInt(row.is_active, 10) === 1;
                            var blockers = self.activationBlockers(row);
                            var deactivateDependents = self.deactivationDependents(row);
                            var activateTitle = blockers.length > 0
                                ? self.escapeHtml(blockers.join(' | '))
                                : 'Attiva modulo';
                            var deactivateTitle = deactivateDependents.length > 0
                                ? self.escapeHtml('Disattivando questo modulo verranno disattivati anche: ' + deactivateDependents.join(', '))
                                : 'Disattiva modulo';
                            var title = active ? deactivateTitle : activateTitle;

                            return ''
                                + '<input type="hidden"'
                                + ' data-role="admin-module-status-switch"'
                                + ' data-id="' + self.escapeHtml(moduleId) + '"'
                                + ' value="' + (active ? '1' : '0') + '"'
                                + ' title="' + title + '">';
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

        onGridSuccess: function (response) {
            var dataset = this.extractDataset(response);
            this.rawDataset = Array.isArray(dataset) ? dataset.slice() : [];
            this.rowsById = this.indexById(this.rawDataset);
            this.updateSummary(this.rawDataset);
            this.refreshGridData();
        },

        extractDataset: function (response) {
            if (response && Array.isArray(response.dataset)) {
                return response.dataset;
            }
            if (this.grid && Array.isArray(this.grid.dataset)) {
                return this.grid.dataset;
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
            var detected = 0;
            var issues = 0;

            for (var i = 0; i < dataset.length; i++) {
                var row = dataset[i] || {};
                var status = String(row.status || '').trim().toLowerCase();

                if (status === 'active') {
                    active += 1;
                } else if (status === 'inactive' || status === 'installed') {
                    inactive += 1;
                } else if (status === 'detected') {
                    detected += 1;
                }

                if (this.rowHasIssues(row)) {
                    issues += 1;
                }
            }

            if (this.summary && this.summary.active) {
                this.summary.active.textContent = String(active);
            }
            if (this.summary && this.summary.inactive) {
                this.summary.inactive.textContent = String(inactive);
            }
            if (this.summary && this.summary.detected) {
                this.summary.detected.textContent = String(detected);
            }
            if (this.summary && this.summary.issues) {
                this.summary.issues.textContent = String(issues);
            }
        },

        refreshGridData: function () {
            if (!this.grid) {
                return this;
            }

            var dataset = Array.isArray(this.rawDataset) ? this.rawDataset.slice() : [];
            dataset = this.sortDataset(dataset, this.getCurrentOrderBy());
            dataset = this.filterDataset(dataset);

            this.grid.dataset = dataset;
            this.grid.rebuildIndex();
            this.grid.updateTable();
            this.mountStatusSwitches();

            return this;
        },

        getCurrentOrderBy: function () {
            if (this.grid && this.grid.paginator && this.grid.paginator.nav) {
                var orderBy = String(this.grid.paginator.nav.orderBy || '').trim();
                if (orderBy !== '') {
                    return orderBy;
                }
            }
            return '__default__|ASC';
        },

        parseOrderBy: function (orderBy) {
            var raw = String(orderBy || '').trim();
            if (raw === '') {
                return { field: '__default__', direction: 'ASC' };
            }

            var chunk = raw.split(',')[0] || '';
            var parts = chunk.split('|');
            var field = String(parts[0] || '').trim();
            var direction = String(parts[1] || 'ASC').trim().toUpperCase();

            if (field === '') {
                field = '__default__';
            }
            if (direction !== 'DESC') {
                direction = 'ASC';
            }

            return { field: field, direction: direction };
        },

        sortDataset: function (dataset, orderBy) {
            var parsed = this.parseOrderBy(orderBy);
            var field = parsed.field;
            var direction = parsed.direction;
            var factor = direction === 'DESC' ? -1 : 1;
            var self = this;

            dataset.sort(function (a, b) {
                var result = self.compareRows(a || {}, b || {}, field);
                if (result !== 0) {
                    return result * factor;
                }

                return String(a && a.id ? a.id : '').localeCompare(String(b && b.id ? b.id : ''), 'it', { sensitivity: 'base', numeric: true });
            });

            return dataset;
        },

        compareRows: function (a, b, field) {
            if (field === '__default__') {
                return this.compareDefaultOrder(a, b);
            }

            var valueA = this.resolveSortValue(a, field);
            var valueB = this.resolveSortValue(b, field);

            if (typeof valueA === 'number' && typeof valueB === 'number') {
                if (valueA === valueB) {
                    return 0;
                }
                return valueA < valueB ? -1 : 1;
            }

            var textA = String(valueA == null ? '' : valueA);
            var textB = String(valueB == null ? '' : valueB);
            return textA.localeCompare(textB, 'it', { sensitivity: 'base', numeric: true });
        },

        compareDefaultOrder: function (a, b) {
            var issueA = this.rowHasIssues(a) ? 0 : 1;
            var issueB = this.rowHasIssues(b) ? 0 : 1;
            if (issueA !== issueB) {
                return issueA < issueB ? -1 : 1;
            }

            var statusA = this.statusWeight(String(a.status || 'detected'));
            var statusB = this.statusWeight(String(b.status || 'detected'));
            if (statusA !== statusB) {
                return statusA < statusB ? -1 : 1;
            }

            var nameA = String(a.name || a.id || '').toLowerCase();
            var nameB = String(b.name || b.id || '').toLowerCase();
            return nameA.localeCompare(nameB, 'it', { sensitivity: 'base', numeric: true });
        },

        resolveSortValue: function (row, field) {
            if (field === 'status') {
                return this.statusWeight(String(row.status || 'detected'));
            }
            if (field === 'core_compatible') {
                return parseInt(row.core_compatible, 10) === 1 ? 1 : 0;
            }
            if (field === 'dependencies_required') {
                return Array.isArray(row.dependencies_required) ? row.dependencies_required.length : 0;
            }
            if (field === 'is_active') {
                return parseInt(row.is_active, 10) === 1 ? 1 : 0;
            }
            if (field === 'name' || field === 'id' || field === 'vendor' || field === 'version') {
                return String(row[field] || '').toLowerCase();
            }

            return row[field];
        },

        filterDataset: function (dataset) {
            var mode = this.statusFilter ? String(this.statusFilter.value || 'all').trim().toLowerCase() : 'all';
            var query = this.searchInput ? String(this.searchInput.value || '').trim().toLowerCase() : '';
            if (mode === '' || mode === 'all') {
                return this.filterByQuery(dataset, query);
            }

            var self = this;
            var byStatus = dataset.filter(function (row) {
                var status = String((row && row.status) || '').trim().toLowerCase();
                var isActive = parseInt(row && row.is_active, 10) === 1;

                if (mode === 'active') {
                    return isActive || status === 'active';
                }
                if (mode === 'inactive') {
                    return status === 'inactive' || status === 'installed' || (!isActive && status !== 'detected');
                }
                if (mode === 'detected') {
                    return status === 'detected';
                }
                if (mode === 'issues') {
                    return self.rowHasIssues(row || {});
                }

                return true;
            });
            return this.filterByQuery(byStatus, query);
        },

        filterByQuery: function (dataset, query) {
            var q = String(query || '').trim().toLowerCase();
            if (q === '') {
                return dataset;
            }

            return dataset.filter(function (row) {
                var fields = [
                    String((row && row.id) || ''),
                    String((row && row.name) || ''),
                    String((row && row.vendor) || ''),
                    String((row && row.version) || ''),
                    String((row && row.description) || '')
                ];
                var haystack = fields.join(' ').toLowerCase();
                return haystack.indexOf(q) !== -1;
            });
        },

        statusWeight: function (status) {
            var key = String(status || '').trim().toLowerCase();
            if (Object.prototype.hasOwnProperty.call(STATUS_WEIGHT, key)) {
                return STATUS_WEIGHT[key];
            }
            return 9;
        },

        findRowByTrigger: function (trigger) {
            var id = String(trigger.getAttribute('data-id') || '').trim();
            if (!id) {
                return null;
            }
            return this.rowsById[id] || null;
        },

        activateModule: function (row, options) {
            if (!row || !row.id) {
                return;
            }
            options = options || {};

            var blockers = this.activationBlockers(row);
            if (blockers.length > 0) {
                Toast.show({ body: blockers.join(' | '), type: 'warning' });
                if (typeof options.onCancelled === 'function') {
                    options.onCancelled();
                }
                return;
            }

            var self = this;
            this.confirmAction(
                'Attivazione modulo',
                'Confermi l\'attivazione del modulo <b>' + this.escapeHtml(row.name || row.id) + '</b>?',
                function () {
                    self.requestPost('/admin/modules/activate', { module_id: row.id }, function () {
                        Toast.show({ body: 'Modulo attivato: ' + row.id, type: 'success' });
                        self.loadGrid();
                    });
                },
                options.onCancelled
            );
        },

        deactivateModule: function (row, options) {
            if (!row || !row.id) {
                return;
            }
            options = options || {};
            var dependents = this.deactivationDependents(row);

            var self = this;
            var confirmBody = 'Confermi la disattivazione del modulo <b>' + this.escapeHtml(row.name || row.id) + '</b>?';
            if (dependents.length > 0) {
                confirmBody += '<br><br><span class="text-warning"><i class="bi bi-exclamation-triangle-fill me-1"></i>Verranno disattivati anche: <b>' + this.escapeHtml(dependents.join(', ')) + '</b></span>';
            }

            this.confirmAction(
                'Disattivazione modulo',
                confirmBody,
                function () {
                    self.requestPost(
                        '/admin/modules/deactivate',
                        { module_id: row.id, cascade: dependents.length > 0 ? 1 : 0 },
                        function (response) {
                            var dataset = response && response.dataset ? response.dataset : {};
                            var deactivated = Array.isArray(dataset.deactivated_modules) ? dataset.deactivated_modules : [];
                            if (deactivated.length > 0) {
                                Toast.show({ body: 'Moduli disattivati: ' + deactivated.join(', '), type: 'success' });
                            } else {
                                Toast.show({ body: 'Modulo disattivato: ' + row.id, type: 'success' });
                            }
                            self.loadGrid();
                        },
                        function (xhr) {
                            var error = self.parseErrorResponse(xhr);
                            if (error.code !== 'module_deactivation_requires_confirmation') {
                                return false;
                            }

                            var fallbackDependents = dependents.slice();
                            var serverDependents = Array.isArray(error.payload.dependents) ? error.payload.dependents : fallbackDependents;
                            self.confirmAction(
                                'Conferma disattivazione dipendenze',
                                'Per continuare verranno disattivati anche: <b>' + self.escapeHtml(serverDependents.join(', ')) + '</b>.',
                                function () {
                                    self.requestPost('/admin/modules/deactivate', { module_id: row.id, cascade: 1 }, function (response) {
                                        var dataset = response && response.dataset ? response.dataset : {};
                                        var deactivated = Array.isArray(dataset.deactivated_modules) ? dataset.deactivated_modules : [];
                                        if (deactivated.length > 0) {
                                            Toast.show({ body: 'Moduli disattivati: ' + deactivated.join(', '), type: 'success' });
                                        } else {
                                            Toast.show({ body: 'Modulo disattivato: ' + row.id, type: 'success' });
                                        }
                                        self.loadGrid();
                                    });
                                },
                                options.onCancelled
                            );
                            return true;
                        }
                    );
                },
                options.onCancelled
            );
        },

        uninstallModule: function (row, purge, options) {
            if (!row || !row.id) {
                return;
            }
            options = options || {};
            purge = purge === true;

            if (parseInt(row.is_active, 10) === 1) {
                Toast.show({ body: 'Disattiva prima il modulo per procedere con la disinstallazione.', type: 'warning' });
                if (typeof options.onCancelled === 'function') {
                    options.onCancelled();
                }
                return;
            }

            var self = this;
            var title = purge ? 'Disinstallazione purge modulo' : 'Disinstallazione safe modulo';
            var body = purge
                ? 'Confermi la <b>disinstallazione purge</b> del modulo <b>' + this.escapeHtml(row.name || row.id) + '</b>?<br><span class="small text-danger">La modalità purge esegue anche le migrazioni di uninstall e rimuove i metadati installazione.</span>'
                : 'Confermi la <b>disinstallazione safe</b> del modulo <b>' + this.escapeHtml(row.name || row.id) + '</b>?';

            this.confirmAction(title, body, function () {
                self.requestPost(
                    '/admin/modules/uninstall',
                    { module_id: row.id, purge: purge ? 1 : 0 },
                    function (response) {
                        var dataset = response && response.dataset ? response.dataset : {};
                        var mode = String(dataset.uninstall_mode || (purge ? 'purge' : 'safe'));
                        Toast.show({ body: 'Modulo disinstallato (' + mode + '): ' + row.id, type: 'success' });
                        self.loadGrid();
                    },
                    function () {
                        return false;
                    }
                );
            }, options.onCancelled);
        },

        runAudit: function () {
            var self = this;
            this.requestPost('/admin/modules/audit', {}, function (response) {
                var dataset = response && response.dataset ? response.dataset : {};
                var summary = dataset.summary || {};
                var orphanRows = parseInt(summary.orphan_installed_rows, 10) || 0;
                var orphanArtifacts = parseInt(summary.orphan_artifacts, 10) || 0;
                var activeWithoutArtifacts = parseInt(summary.active_without_artifacts, 10) || 0;

                var body = ''
                    + '<div class="small">'
                    + '<div><b>Moduli rilevati:</b> ' + self.escapeHtml(String(summary.discovered_modules || 0)) + '</div>'
                    + '<div><b>Moduli installati:</b> ' + self.escapeHtml(String(summary.installed_modules || 0)) + '</div>'
                    + '<div><b>Artifact tracciati:</b> ' + self.escapeHtml(String(summary.artifacts_tracked || 0)) + '</div>'
                    + '<hr class="my-2">'
                    + '<div><b>Orfani installazione:</b> ' + self.escapeHtml(String(orphanRows)) + '</div>'
                    + '<div><b>Artifact orfani:</b> ' + self.escapeHtml(String(orphanArtifacts)) + '</div>'
                    + '<div><b>Attivi senza artifact:</b> ' + self.escapeHtml(String(activeWithoutArtifacts)) + '</div>'
                    + '</div>';

                if (orphanRows > 0 || orphanArtifacts > 0 || activeWithoutArtifacts > 0) {
                    if (typeof Dialog === 'function') {
                        Dialog('warning', { title: 'Audit governance moduli', body: body }, function () {}).show();
                    } else {
                        Toast.show({ body: 'Audit completato con criticita. Apri i log audit.', type: 'warning' });
                    }
                } else {
                    Toast.show({ body: 'Audit moduli completato: nessuna criticita rilevata.', type: 'success' });
                }
            });
        },

        confirmAction: function (title, body, onConfirm, onCancel) {
            if (typeof Dialog === 'function') {
                var dialogRef = null;
                var confirmed = false;
                var dialogModal = null;
                var hiddenNs = '.admin-modules-confirm';

                if (window.SystemDialogs && typeof window.SystemDialogs.getGeneralConfirmModal === 'function') {
                    dialogModal = window.SystemDialogs.getGeneralConfirmModal();
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

            if (window.confirm((title || 'Conferma') + '\n\n' + String(body || '').replace(/<[^>]+>/g, ''))) {
                if (typeof onConfirm === 'function') {
                    onConfirm();
                }
            } else if (typeof onCancel === 'function') {
                onCancel();
            }
        },

        mountStatusSwitches: function () {
            if (!this.root || typeof window.$ !== 'function' || typeof window.SwitchGroup !== 'function') {
                return this;
            }

            var self = this;
            var inputs = window.$(this.root).find('input[data-role="admin-module-status-switch"]');
            inputs.each(function () {
                var field = window.$(this);
                var moduleId = String(field.attr('data-id') || '').trim();
                if (moduleId === '') {
                    return;
                }

                var row = self.rowsById[moduleId] || null;
                var active = row ? (parseInt(row.is_active, 10) === 1) : (String(field.val()) === '1');
                var value = active ? '1' : '0';

                self.switchSyncLocks[moduleId] = true;
                field.val(value);
                self.switchSyncLocks[moduleId] = false;

                var switchInstance = field.data('__switchGroupInstance');
                if (!switchInstance || typeof switchInstance.refresh !== 'function') {
                    switchInstance = window.SwitchGroup(field, {
                        preset: 'activeinactive',
                        trueValue: '1',
                        falseValue: '0'
                    });
                } else {
                    switchInstance.refresh();
                }

                field.off('change.admin-modules-switch').on('change.admin-modules-switch', function () {
                    if (self.switchSyncLocks[moduleId] === true) {
                        return;
                    }

                    var currentRow = self.rowsById[moduleId] || row;
                    if (!currentRow) {
                        return;
                    }

                    var currentActive = parseInt(currentRow.is_active, 10) === 1;
                    var nextActive = String(field.val()) === '1';
                    if (currentActive === nextActive) {
                        return;
                    }

                    var rollback = function () {
                        self.switchSyncLocks[moduleId] = true;
                        field.val(currentActive ? '1' : '0').change();
                        self.switchSyncLocks[moduleId] = false;

                        var currentSwitch = field.data('__switchGroupInstance');
                        if (currentSwitch && typeof currentSwitch.refresh === 'function') {
                            currentSwitch.refresh();
                        }
                    };

                    if (nextActive) {
                        self.activateModule(currentRow, { onCancelled: rollback });
                        return;
                    }

                    self.deactivateModule(currentRow, { onCancelled: rollback });
                });
            });

            return this;
        },

        dependencyState: function (dependencyId) {
            var row = this.rowsById[String(dependencyId)] || null;
            if (!row) {
                return 'missing';
            }

            if (parseInt(row.is_active, 10) === 1) {
                return 'active';
            }

            return 'inactive';
        },

        renderDependencies: function (row) {
            var required = Array.isArray(row.dependencies_required) ? row.dependencies_required : [];
            var optional = Array.isArray(row.dependencies_optional) ? row.dependencies_optional : [];
            var parts = [];

            if (!required.length && !optional.length) {
                return '<span class="text-muted">Nessuna</span>';
            }

            var self = this;
            if (required.length) {
                var requiredHtml = required.map(function (dep) {
                    var depId = dep && dep.id ? String(dep.id) : '';
                    var state = self.dependencyState(depId);
                    var badgeClass = 'text-bg-secondary';
                    var suffix = ' (inattivo)';
                    if (state === 'active') {
                        badgeClass = 'text-bg-success';
                        suffix = '';
                    } else if (state === 'missing') {
                        badgeClass = 'text-bg-danger';
                        suffix = ' (mancante)';
                    }
                    return '<span class="badge ' + badgeClass + ' me-1">' + self.escapeHtml(depId + suffix) + '</span>';
                }).join('');
                parts.push('<div class="small mb-1"><b>Richieste:</b></div><div class="mb-1">' + requiredHtml + '</div>');
            }

            if (optional.length) {
                var optionalHtml = optional.map(function (dep) {
                    var depId = dep && dep.id ? String(dep.id) : '';
                    var state = self.dependencyState(depId);
                    var badgeClass = (state === 'active') ? 'text-bg-info' : 'text-bg-secondary';
                    return '<span class="badge ' + badgeClass + ' me-1">' + self.escapeHtml(depId) + '</span>';
                }).join('');
                parts.push('<div class="small mb-1"><b>Opzionali:</b></div><div>' + optionalHtml + '</div>');
            }

            return parts.join('');
        },

        activationBlockers: function (row) {
            var blockers = [];
            if (!row) {
                return blockers;
            }

            if (parseInt(row.core_compatible, 10) !== 1) {
                blockers.push('Compatibilita core non valida');
            }

            var required = Array.isArray(row.dependencies_required) ? row.dependencies_required : [];
            for (var i = 0; i < required.length; i++) {
                var dep = required[i] || {};
                var depId = String(dep.id || '').trim();
                if (!depId) {
                    continue;
                }
                var depState = this.dependencyState(depId);
                if (depState === 'missing') {
                    blockers.push('Dipendenza mancante: ' + depId);
                } else if (depState === 'inactive') {
                    blockers.push('Dipendenza non attiva: ' + depId);
                }
            }

            return blockers;
        },

        deactivationDependents: function (row) {
            var dependents = [];
            if (!row) {
                return dependents;
            }
            var moduleId = String(row.id || '').trim();
            if (moduleId === '') {
                return dependents;
            }
            if (parseInt(row.is_active, 10) !== 1) {
                return dependents;
            }

            var dataset = Array.isArray(this.rawDataset) ? this.rawDataset : [];
            for (var i = 0; i < dataset.length; i += 1) {
                var candidate = dataset[i] || {};
                if (parseInt(candidate.is_active, 10) !== 1) {
                    continue;
                }
                var candidateId = String(candidate.id || '').trim();
                if (candidateId === '' || candidateId === moduleId) {
                    continue;
                }
                var required = Array.isArray(candidate.dependencies_required) ? candidate.dependencies_required : [];
                for (var j = 0; j < required.length; j += 1) {
                    var dep = required[j] || {};
                    var depId = String(dep.id || '').trim();
                    if (depId !== '' && depId === moduleId) {
                        dependents.push(candidateId);
                        break;
                    }
                }
            }

            return dependents;
        },

        rowHasIssues: function (row) {
            if (!row) {
                return false;
            }
            if (String(row.status || '').toLowerCase() === 'error') {
                return true;
            }
            if (parseInt(row.core_compatible, 10) !== 1) {
                return true;
            }
            return this.activationBlockers(row).length > 0;
        },

        renderNotes: function (row) {
            var notes = [];
            var blockers = this.activationBlockers(row);
            for (var i = 0; i < blockers.length; i++) {
                notes.push('<div class="small text-warning"><i class="bi bi-exclamation-triangle-fill me-1"></i>' + this.escapeHtml(blockers[i]) + '</div>');
            }

            var status = String(row.status || '').toLowerCase();
            if (status === 'error') {
                var lastError = String(row.last_error || '').trim();
                if (lastError !== '') {
                    notes.push('<div class="small text-danger"><i class="bi bi-x-octagon-fill me-1"></i>' + this.escapeHtml(lastError) + '</div>');
                } else {
                    notes.push('<div class="small text-danger"><i class="bi bi-x-octagon-fill me-1"></i>Modulo in stato errore</div>');
                }
            }

            if (!notes.length) {
                return '<span class="text-muted small">Nessuna criticita</span>';
            }

            return notes.join('');
        },

        renderGovernance: function (row) {
            if (!row || !row.id) {
                return '<span class="text-muted small">-</span>';
            }

            var moduleId = this.escapeHtml(String(row.id || '').trim());
            var isInstalled = parseInt(row.is_installed, 10) === 1;
            var isActive = parseInt(row.is_active, 10) === 1;

            if (!isInstalled) {
                return '<span class="text-muted small">Non installato</span>';
            }

            if (isActive) {
                return ''
                    + '<span class="text-muted small d-block mb-1">Disattiva il modulo prima della disinstallazione.</span>'
                    + '<button type="button" class="btn btn-sm btn-outline-secondary" data-id="' + moduleId + '" disabled>Disinstalla</button>';
            }

            return ''
                + '<div class="btn-group btn-group-sm" role="group">'
                + '  <button type="button" class="btn btn-outline-warning" data-action="admin-module-uninstall-safe" data-id="' + moduleId + '">Disinstalla</button>'
                + '  <button type="button" class="btn btn-outline-danger" data-action="admin-module-uninstall-purge" data-id="' + moduleId + '">Purge</button>'
                + '</div>';
        },

        errorMessageByCode: function (code) {
            var key = String(code || '').trim();
            if (key === 'module_not_found') {
                return 'Modulo non trovato.';
            }
            if (key === 'module_not_installed') {
                return 'Modulo non installato.';
            }
            if (key === 'module_dependency_missing') {
                return 'Operazione bloccata: attiva prima le dipendenze richieste.';
            }
            if (key === 'module_deactivation_requires_confirmation') {
                return 'Disattivazione con dipendenze: conferma necessaria.';
            }
            if (key === 'module_incompatible_core') {
                return 'Versione core incompatibile con il modulo.';
            }
            if (key === 'module_activation_failed') {
                return 'Attivazione modulo non riuscita.';
            }
            if (key === 'module_deactivation_failed') {
                return 'Disattivazione modulo non riuscita.';
            }
            if (key === 'module_uninstall_requires_inactive') {
                return 'Disattiva prima il modulo per poterlo disinstallare.';
            }
            if (key === 'module_uninstall_failed') {
                return 'Disinstallazione modulo non riuscita.';
            }
            if (key === 'csrf_invalid') {
                return 'Sessione scaduta: aggiorna la pagina e riprova.';
            }
            return '';
        },

        parseErrorResponse: function (xhr) {
            var response = null;
            if (xhr && xhr.responseJSON && typeof xhr.responseJSON === 'object') {
                response = xhr.responseJSON;
            } else if (xhr && typeof xhr.responseText === 'string' && xhr.responseText.trim() !== '') {
                var raw = String(xhr.responseText || '');
                try {
                    response = JSON.parse(raw);
                } catch (e) {
                    var splitMatch = raw.split(/\}\s*\{/);
                    if (splitMatch.length > 1) {
                        try {
                            response = JSON.parse(splitMatch[0] + '}');
                        } catch (ignore) {
                            response = null;
                        }
                    }
                }
            }

            var code = response && typeof response === 'object'
                ? String(response.error_code || '').trim()
                : '';
            var message = '';
            if (response && typeof response === 'object') {
                message = String(response.error || response.message || '').trim();
            }

            return {
                response: response && typeof response === 'object' ? response : {},
                code: code,
                message: message,
                payload: response && typeof response === 'object' && response.payload && typeof response.payload === 'object'
                    ? response.payload
                    : {}
            };
        },

        requestErrorMessage: function (xhr) {
            var parsed = this.parseErrorResponse(xhr);
            var code = parsed.code;
            var byCode = this.errorMessageByCode(code);
            if (byCode !== '') {
                return byCode;
            }

            if (parsed.message !== '') {
                return parsed.message;
            }

            return 'Operazione non riuscita.';
        },

        requestPost: function (url, payload, onSuccess, onFail) {
            var self = this;
            var csrfToken = this.getCsrfToken();
            var headers = {
                'X-Requested-With': 'XMLHttpRequest'
            };
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

        getCsrfToken: function () {
            var meta = document.querySelector('meta[name="csrf-token"]');
            if (!meta) {
                return '';
            }
            return String(meta.getAttribute('content') || '').trim();
        },

        statusBadge: function (status) {
            var key = String(status || '').trim().toLowerCase();
            if (key === 'active') {
                return '<span class="badge text-bg-success">Attivo</span>';
            }
            if (key === 'inactive') {
                return '<span class="badge text-bg-secondary">Inattivo</span>';
            }
            if (key === 'installed') {
                return '<span class="badge text-bg-info">Installato</span>';
            }
            if (key === 'error') {
                return '<span class="badge text-bg-danger">Errore</span>';
            }
            return '<span class="badge text-bg-warning">Rilevato</span>';
        },

        escapeHtml: function (value) {
            return $('<div/>').text(value || '').html();
        }
    };

    window.AdminModules = AdminModules;
})();
