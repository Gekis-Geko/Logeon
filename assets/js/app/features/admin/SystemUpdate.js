const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

var AdminSystemUpdate = {
    initialized: false,
    root: null,
    alertEl: null,
    state: {
        targetVersion: '',
        backupId: '',
    },

    init: function () {
        if (this.initialized) {
            return this;
        }

        this.root = document.querySelector('#admin-page [data-admin-page="system-update"]');
        if (!this.root) {
            return this;
        }

        this.alertEl = document.getElementById('admin-system-update-alert');
        this.bind();
        this.loadStatus();

        this.initialized = true;
        return this;
    },

    bind: function () {
        var self = this;
        this.root.addEventListener('click', function (event) {
            var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
            if (!trigger) {
                return;
            }

            var action = String(trigger.getAttribute('data-action') || '').trim();
            if (action === 'admin-system-update-check') {
                event.preventDefault();
                self.checkUpdates();
                return;
            }
            if (action === 'admin-system-update-preflight') {
                event.preventDefault();
                self.runPreflight();
                return;
            }
            if (action === 'admin-system-update-backup') {
                event.preventDefault();
                self.createBackup();
                return;
            }
            if (action === 'admin-system-update-download') {
                event.preventDefault();
                self.downloadPackage();
                return;
            }
            if (action === 'admin-system-update-apply') {
                event.preventDefault();
                self.applyUpdate();
                return;
            }
            if (action === 'admin-system-update-logs') {
                event.preventDefault();
                self.loadLogs();
            }
        });
    },

    loadStatus: function () {
        var self = this;
        this.post('/admin/system/update/status', {}, function (response) {
            var dataset = response && response.dataset ? response.dataset : {};
            self.renderStatus(dataset);
            self.loadLogs();
        }, function (error) {
            self.showAlert('danger', 'Impossibile leggere lo stato updater: ' + self.errorMessage(error));
        });
    },

    checkUpdates: function () {
        var self = this;
        this.showAlert('info', 'Controllo aggiornamenti in corso...');
        this.post('/admin/system/update/check', {}, function (response) {
            var dataset = response && response.dataset ? response.dataset : {};
            self.renderCheck(dataset);
            if (dataset.update_available === true) {
                self.showAlert('success', 'Nuova versione disponibile: ' + String(dataset.latest_version || '-'));
                return;
            }
            self.showAlert('success', 'Nessun aggiornamento disponibile al momento.');
        }, function (error) {
            self.renderCheckResult('errore');
            self.showAlert('danger', self.errorMessage(error));
        });
    },

    runPreflight: function () {
        var self = this;
        var targetVersion = this.resolveTargetVersion();
        this.renderPipelineValue('update-preflight-result', 'in corso...');
        this.showAlert('info', 'Preflight in esecuzione...');
        this.post('/admin/system/update/preflight', {
            target_version: targetVersion
        }, function (response) {
            var dataset = response && response.dataset ? response.dataset : {};
            self.state.targetVersion = String(dataset.target_version || targetVersion || '').trim();
            self.setText('update-target-version', self.state.targetVersion || '-');
            self.renderPreflightChecks(dataset.checks || []);
            self.renderPipelineValue('update-preflight-result', dataset.ok ? 'ok' : 'bloccato');
            if (dataset.ok) {
                self.showAlert('success', 'Preflight completato: puoi procedere al backup.');
            } else {
                self.showAlert('warning', 'Preflight non superato. Verifica i controlli bloccanti.');
            }
        }, function (error) {
            self.renderPipelineValue('update-preflight-result', 'errore');
            self.showAlert('danger', self.errorMessage(error));
        });
    },

    createBackup: function () {
        var self = this;
        var targetVersion = this.resolveTargetVersion();
        this.renderPipelineValue('update-backup-result', 'in corso...');
        this.showAlert('info', 'Creazione backup in corso...');
        this.post('/admin/system/update/backup', {
            target_version: targetVersion
        }, function (response) {
            var dataset = response && response.dataset ? response.dataset : {};
            self.state.backupId = String(dataset.backup_id || '').trim();
            self.renderPipelineValue('update-backup-result', self.state.backupId !== '' ? ('ok (' + self.state.backupId + ')') : 'ok');
            self.showAlert('success', 'Backup creato: ' + (self.state.backupId || 'n/d'));
        }, function (error) {
            self.renderPipelineValue('update-backup-result', 'errore');
            self.showAlert('danger', self.errorMessage(error));
        });
    },

    downloadPackage: function () {
        var self = this;
        var targetVersion = this.resolveTargetVersion();
        this.renderPipelineValue('update-download-result', 'in corso...');
        this.showAlert('info', 'Download pacchetto in corso...');
        this.post('/admin/system/update/download', {
            target_version: targetVersion
        }, function (response) {
            var dataset = response && response.dataset ? response.dataset : {};
            var version = String(dataset.target_version || targetVersion || '').trim();
            if (version !== '') {
                self.state.targetVersion = version;
                self.setText('update-target-version', version);
            }
            self.renderPipelineValue('update-download-result', 'ok');
            self.showAlert('success', 'Pacchetto scaricato e validato.');
        }, function (error) {
            self.renderPipelineValue('update-download-result', 'errore');
            self.showAlert('danger', self.errorMessage(error));
        });
    },

    applyUpdate: function () {
        var self = this;
        var targetVersion = this.resolveTargetVersion();
        if (targetVersion === '') {
            this.showAlert('warning', 'Versione target non disponibile. Esegui prima il check.');
            return;
        }
        if (String(this.state.backupId || '').trim() === '') {
            this.showAlert('warning', 'Backup non trovato. Esegui prima "Crea backup".');
            return;
        }

        this.renderPipelineValue('update-apply-result', 'in corso...');
        this.showAlert('info', 'Apply update in corso...');
        this.post('/admin/system/update/apply', {
            target_version: targetVersion,
            backup_id: this.state.backupId
        }, function (response) {
            var dataset = response && response.dataset ? response.dataset : {};
            self.renderPipelineValue('update-apply-result', dataset.ok ? 'completato' : 'parziale');
            self.showAlert('success', 'Aggiornamento completato. Versione aggiornata.');
            self.loadStatus();
        }, function (error) {
            self.renderPipelineValue('update-apply-result', 'errore');
            self.showAlert('danger', self.errorMessage(error));
        });
    },

    loadLogs: function () {
        var self = this;
        this.post('/admin/system/update/logs', { limit: 20 }, function (response) {
            var dataset = response && response.dataset ? response.dataset : {};
            self.renderLogs(dataset.updates || []);
            if (dataset.runtime) {
                self.renderRuntime(dataset.runtime);
            }
        }, function () {});
    },

    renderStatus: function (dataset) {
        this.setText('update-current-version', dataset.current_version || '-');
        this.setText('update-distribution', dataset.distribution || '-');
        this.setText('update-channel', dataset.channel || '-');
        this.setText('update-latest-version', '-');
        this.setText('update-manifest-url', dataset.manifest_url || 'non configurato');

        var distribution = String(dataset.distribution || '').toLowerCase();
        var modeNote = distribution === 'ready'
            ? 'Distribuzione ready: updater web operativo.'
            : 'Distribuzione source-dev/legacy: updater web solo informativo.';
        this.setText('update-mode-note', modeNote);

        this.renderCheckResult('in attesa');
        this.renderPipelineValue('update-preflight-result', 'in attesa');
        this.renderPipelineValue('update-backup-result', 'in attesa');
        this.renderPipelineValue('update-download-result', 'in attesa');
        this.renderPipelineValue('update-apply-result', 'in attesa');

        this.renderRuntime(dataset.runtime || {});
    },

    renderCheck: function (dataset) {
        this.setText('update-current-version', dataset.current_version || '-');
        this.setText('update-distribution', dataset.distribution || '-');
        this.setText('update-channel', dataset.channel || '-');
        this.setText('update-latest-version', dataset.latest_version || '-');
        this.setText('update-manifest-url', (dataset.source && dataset.source.manifest_url) ? dataset.source.manifest_url : 'non configurato');

        var distribution = String(dataset.distribution || '').toLowerCase();
        var modeNote = distribution === 'ready'
            ? 'Distribuzione ready: apply consentito dopo preflight, backup e download.'
            : 'Distribuzione source-dev: check disponibile, apply web non consentito.';
        this.setText('update-mode-note', modeNote);

        this.renderCheckResult(dataset.update_available ? 'update disponibile' : 'nessun update');
        this.renderRelease(dataset.release || null);

        var targetVersion = String(dataset.latest_version || (dataset.release && dataset.release.version) || '').trim();
        this.state.targetVersion = targetVersion;
        this.setText('update-target-version', targetVersion || '-');

        this.renderRuntime(dataset.runtime || {});
    },

    renderRuntime: function (runtime) {
        var lock = runtime && runtime.lock ? runtime.lock : null;
        var maintenance = runtime && runtime.maintenance ? runtime.maintenance : null;
        this.setText('update-lock-state', lock ? ('attivo (' + String(lock.status || 'running') + ')') : 'nessuno');
        this.setText('update-maintenance-state', maintenance ? 'attivo' : 'non attivo');
    },

    renderRelease: function (release) {
        if (!release) {
            this.setText('update-release-version', '-');
            this.setText('update-release-date', '-');
            this.setText('update-release-migration', '-');
            this.setText('update-release-package', '-');
            this.setReleaseLink('');
            return;
        }

        this.setText('update-release-version', release.version || '-');
        this.setText('update-release-date', release.date || '-');
        this.setText('update-release-migration', release.requires_db_migration ? 'si' : 'no');
        this.setText('update-release-package', (release.package && release.package.url) ? release.package.url : '-');
        this.setReleaseLink(release.changelog_url || '');
    },

    renderPreflightChecks: function (checks) {
        var node = this.root.querySelector('[data-role="update-preflight-checks"]');
        if (!node) {
            return;
        }

        if (!Array.isArray(checks) || checks.length === 0) {
            node.textContent = 'Nessun controllo disponibile.';
            return;
        }

        var html = [];
        for (var i = 0; i < checks.length; i++) {
            var check = checks[i] || {};
            var ok = check.ok === true;
            var icon = ok ? 'bi-check-circle-fill text-success' : 'bi-exclamation-triangle-fill text-danger';
            var key = String(check.key || 'check');
            var message = String(check.message || '');
            html.push('<div class="mb-1"><i class="bi ' + icon + ' me-1"></i><strong>' + this.escapeHtml(key) + '</strong>: ' + this.escapeHtml(message) + '</div>');
        }
        node.innerHTML = html.join('');
    },

    renderLogs: function (rows) {
        var node = this.root.querySelector('[data-role="update-logs-list"]');
        if (!node) {
            return;
        }
        if (!Array.isArray(rows) || rows.length === 0) {
            node.textContent = 'Nessun log disponibile.';
            return;
        }

        var html = [];
        for (var i = 0; i < rows.length; i++) {
            var row = rows[i] || {};
            var line = '#'
                + String(row.id || '-')
                + ' · '
                + String(row.from_version || '-')
                + ' -> '
                + String(row.to_version || '-')
                + ' · '
                + String(row.status || '-')
                + ' · '
                + String(row.started_at || '-');
            if (String(row.error_code || '').trim() !== '') {
                line += ' · errore: ' + String(row.error_code || '');
            }
            html.push('<div class="mb-1">' + this.escapeHtml(line) + '</div>');
        }
        node.innerHTML = html.join('');
    },

    renderPipelineValue: function (role, value) {
        this.setText(role, value);
    },

    resolveTargetVersion: function () {
        var current = String(this.state.targetVersion || '').trim();
        if (current !== '') {
            return current;
        }
        var node = this.root.querySelector('[data-role="update-latest-version"]');
        if (!node) {
            return '';
        }
        return String(node.textContent || '').trim();
    },

    setReleaseLink: function (url) {
        var node = this.root.querySelector('[data-role="update-release-changelog"]');
        if (!node) {
            return;
        }

        var href = String(url || '').trim();
        if (href === '') {
            node.textContent = '-';
            node.setAttribute('href', '#');
            return;
        }

        node.textContent = href;
        node.setAttribute('href', href);
    },

    renderCheckResult: function (value) {
        this.setText('update-check-result', value);
    },

    setText: function (role, value) {
        var node = this.root.querySelector('[data-role="' + role + '"]');
        if (!node) {
            return;
        }
        node.textContent = String(value || '');
    },

    showAlert: function (type, message) {
        if (!this.alertEl) {
            return;
        }
        this.alertEl.className = 'alert alert-' + type + ' mb-3';
        this.alertEl.textContent = String(message || '');
    },

    post: function (url, payload, ok, fail) {
        var data = (payload && typeof payload === 'object') ? payload : {};

        if (globalWindow.Request && globalWindow.Request.http && typeof globalWindow.Request.http.post === 'function') {
            globalWindow.Request.http.post(url, data)
                .then(function (response) {
                    if (typeof ok === 'function') {
                        ok(response || {});
                    }
                })
                .catch(function (error) {
                    if (typeof fail === 'function') {
                        fail(error);
                    }
                });
            return;
        }

        if (typeof globalWindow.fetch !== 'function') {
            if (typeof fail === 'function') {
                fail({ message: 'Client HTTP non disponibile' });
            }
            return;
        }

        globalWindow.fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(data),
        })
            .then(function (response) {
                return response.text().then(function (text) {
                    var parsed = {};
                    try {
                        parsed = text ? JSON.parse(text) : {};
                    } catch (error) {
                        parsed = {};
                    }
                    if (!response.ok) {
                        throw parsed;
                    }
                    return parsed;
                });
            })
            .then(function (response) {
                if (typeof ok === 'function') {
                    ok(response || {});
                }
            })
            .catch(function (error) {
                if (typeof fail === 'function') {
                    fail(error || {});
                }
            });
    },

    escapeHtml: function (value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    },

    errorMessage: function (error) {
        if (globalWindow.Request && typeof globalWindow.Request.getErrorMessage === 'function') {
            var viaRequest = String(globalWindow.Request.getErrorMessage(error, '') || '').trim();
            if (viaRequest !== '') {
                return viaRequest;
            }
        }

        if (error && typeof error.error_code === 'string' && error.error_code.trim() !== '') {
            return error.error_code;
        }
        if (error && typeof error.message === 'string' && error.message.trim() !== '') {
            return error.message;
        }
        if (error && typeof error.error === 'string' && error.error.trim() !== '') {
            return error.error;
        }
        return 'Errore sconosciuto nel flusso aggiornamenti.';
    },
};

globalWindow.AdminSystemUpdate = AdminSystemUpdate;
export { AdminSystemUpdate as AdminSystemUpdate };
export default AdminSystemUpdate;

