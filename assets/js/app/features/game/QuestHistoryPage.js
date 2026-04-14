(function (window) {
    'use strict';

    function resolveModule(name) {
        if (!window.RuntimeBootstrap || typeof window.RuntimeBootstrap.resolveAppModule !== 'function') {
            return null;
        }
        try { return window.RuntimeBootstrap.resolveAppModule(name); } catch (e) { return null; }
    }

    function showToast(type, body) {
        if (window.Toast && typeof window.Toast.show === 'function') {
            window.Toast.show({ type: type, body: body });
        }
    }

    function errorMessage(error, fallback) {
        if (window.Request && typeof window.Request.getErrorMessage === 'function') {
            return window.Request.getErrorMessage(error, fallback || 'Operazione non riuscita.');
        }
        if (error && typeof error.message === 'string' && error.message.trim() !== '') {
            return error.message.trim();
        }
        return fallback || 'Operazione non riuscita.';
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function statusLabel(status) {
        var map = {
            completed: 'Completata',
            failed: 'Fallita',
            cancelled: 'Annullata',
            expired: 'Scaduta',
            active: 'Attiva',
            available: 'Disponibile'
        };
        var key = String(status || '').toLowerCase();
        return map[key] || (key || '-');
    }

    function statusBadge(status) {
        var map = {
            completed: 'text-bg-success',
            failed: 'text-bg-danger',
            cancelled: 'text-bg-warning',
            expired: 'text-bg-dark',
            active: 'text-bg-primary',
            available: 'text-bg-info'
        };
        return map[String(status || '').toLowerCase()] || 'text-bg-secondary';
    }

    function intensityLabel(level) {
        var map = {
            CHILL: 'Chill',
            SOFT: 'Soft',
            STANDARD: 'Standard',
            HIGH: 'High',
            CRITICAL: 'Critical'
        };
        var key = String(level || '').trim().toUpperCase();
        return map[key] || (key || 'Standard');
    }

    function intensityBadgeClass(level) {
        var map = {
            CHILL: 'text-bg-success',
            SOFT: 'text-bg-info',
            STANDARD: 'text-bg-warning',
            HIGH: 'text-bg-primary',
            CRITICAL: 'text-bg-danger'
        };
        var key = String(level || '').trim().toUpperCase();
        return map[key] || 'text-bg-secondary';
    }

    function intensityMeta(row) {
        if (!row || String(row.intensity_visibility || '').toLowerCase() !== 'visible') {
            return null;
        }
        var level = String(
            row.intensity_level
            || row.effective_intensity_level
            || row.instance_intensity_level
            || row.definition_intensity_level
            || ''
        ).trim().toUpperCase();
        if (!level) { return null; }
        if (['CHILL', 'SOFT', 'STANDARD', 'HIGH', 'CRITICAL'].indexOf(level) === -1) {
            return null;
        }
        return {
            level: level,
            label: intensityLabel(level),
            badgeClass: intensityBadgeClass(level)
        };
    }

    function refreshTooltips(container) {
        if (!window.bootstrap || !window.bootstrap.Tooltip) { return; }
        var root = container && container.querySelectorAll ? container : document;
        var nodes = root.querySelectorAll('[data-bs-toggle="tooltip"]');
        for (var i = 0; i < nodes.length; i += 1) {
            window.bootstrap.Tooltip.getOrCreateInstance(nodes[i]);
        }
    }

    function QuestHistoryPage(extension) {
        var page = {
            root: null,
            moduleApi: null,
            listNode: null,
            loadingNode: null,
            emptyNode: null,
            moreBtn: null,
            detailBody: null,
            currentPage: 1,
            limit: 20,
            total: 0,

            init: function () {
                this.root = document.getElementById('quests-history-page');
                if (!this.root) { return this; }

                this.listNode = document.getElementById('quests-history-list');
                this.loadingNode = document.getElementById('quests-history-loading');
                this.emptyNode = document.getElementById('quests-history-empty');
                this.moreBtn = document.getElementById('quests-history-more');
                this.detailBody = document.getElementById('quests-history-detail-body');

                this.bind();
                this.load(1);
                return this;
            },

            resolveModuleApi: function () {
                if (this.moduleApi
                    && typeof this.moduleApi.historyList === 'function'
                    && typeof this.moduleApi.historyGet === 'function') {
                    return this.moduleApi;
                }
                return resolveModule('game.quests');
            },

            bind: function () {
                var self = this;
                this.root.addEventListener('click', function (event) {
                    var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
                    if (!trigger) { return; }
                    var action = String(trigger.getAttribute('data-action') || '');
                    if (!action) { return; }
                    event.preventDefault();

                    if (action === 'quests-history-reload') { self.load(1); return; }
                    if (action === 'quests-history-more') { self.load(self.currentPage + 1); return; }
                    if (action === 'quest-history-open') {
                        self.openDetail(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0);
                        return;
                    }
                });

                ['quests-history-filter-status', 'quests-history-filter-from', 'quests-history-filter-to'].forEach(function (id) {
                    var node = document.getElementById(id);
                    if (!node) { return; }
                    node.addEventListener('change', function () { self.load(1); });
                });

                var search = document.getElementById('quests-history-filter-search');
                if (search) {
                    search.addEventListener('input', function () {
                        if (self.__searchTimer) {
                            window.clearTimeout(self.__searchTimer);
                            self.__searchTimer = null;
                        }
                        self.__searchTimer = window.setTimeout(function () {
                            self.load(1);
                        }, 220);
                    });
                }
            },

            buildPayload: function (pageNumber) {
                var payload = {
                    page: pageNumber,
                    limit: this.limit,
                    status: String((document.getElementById('quests-history-filter-status') || {}).value || '').trim(),
                    search: String((document.getElementById('quests-history-filter-search') || {}).value || '').trim(),
                    from: String((document.getElementById('quests-history-filter-from') || {}).value || '').trim(),
                    to: String((document.getElementById('quests-history-filter-to') || {}).value || '').trim()
                };
                return payload;
            },

            setLoading: function (flag) {
                if (this.loadingNode) { this.loadingNode.classList.toggle('d-none', !flag); }
            },

            setEmpty: function (flag) {
                if (this.emptyNode) { this.emptyNode.classList.toggle('d-none', !flag); }
            },

            setMoreVisible: function (flag) {
                if (this.moreBtn) { this.moreBtn.classList.toggle('d-none', !flag); }
            },

            load: function (pageNumber) {
                var page = parseInt(pageNumber || '1', 10) || 1;
                if (page < 1) { page = 1; }
                var mod = this.resolveModuleApi();
                if (!mod || typeof mod.historyList !== 'function') {
                    return;
                }

                if (page === 1 && this.listNode) {
                    this.listNode.innerHTML = '';
                }
                this.setLoading(true);
                this.setEmpty(false);

                var self = this;
                mod.historyList(this.buildPayload(page)).then(function (response) {
                    self.setLoading(false);
                    var ds = response && response.dataset ? response.dataset : {};
                    var rows = Array.isArray(ds.rows) ? ds.rows : [];
                    var total = parseInt(ds.total || '0', 10) || 0;
                    var currentPage = parseInt(ds.page || page, 10) || page;
                    var limit = parseInt(ds.limit || self.limit, 10) || self.limit;

                    self.currentPage = currentPage;
                    self.total = total;
                    self.limit = limit;

                    if (!rows.length && currentPage === 1) {
                        self.setEmpty(true);
                        self.setMoreVisible(false);
                        return;
                    }

                    self.renderRows(rows, currentPage === 1);
                    self.setMoreVisible((currentPage * limit) < total);
                }).catch(function (error) {
                    self.setLoading(false);
                    showToast('error', errorMessage(error, 'Errore caricamento archivio quest.'));
                });
            },

            renderRows: function (rows, replace) {
                if (!this.listNode) { return; }
                var html = rows.map(function (row) {
                    var instanceId = parseInt(row.quest_instance_id || row.id || '0', 10) || 0;
                    var rewards = Array.isArray(row.rewards) ? row.rewards : [];
                    var intensity = intensityMeta(row);
                    var rewardsHtml = rewards.length
                        ? rewards.map(function (reward) { return '<span class="badge text-bg-secondary me-1 mb-1">' + escapeHtml(reward.reward_label || '-') + '</span>'; }).join('')
                        : '<span class="small text-muted">Nessuna ricompensa visibile</span>';

                    return '<div class="list-group-item py-3">'
                        + '<div class="d-flex justify-content-between align-items-start gap-2 mb-1">'
                        + '<div class="fw-bold">' + escapeHtml(row.quest_title || ('Quest #' + instanceId)) + '</div>'
                        + '<div class="d-flex flex-wrap justify-content-end align-items-center gap-1">'
                        + (intensity ? ('<span class="badge ' + intensity.badgeClass + '" data-bs-toggle="tooltip" data-bs-title="Pressione narrativa e peso delle conseguenze" title="Pressione narrativa e peso delle conseguenze">' + escapeHtml(intensity.label) + '</span>') : '')
                        + '<span class="badge ' + statusBadge(row.status) + '">' + escapeHtml(statusLabel(row.status)) + '</span>'
                        + '</div>'
                        + '</div>'
                        + '<div class="small text-muted mb-2">'
                        + 'Assegnata a: <b>' + escapeHtml(row.assignee_label || '-') + '</b>'
                        + (row.closed_at ? (' · Chiusa il ' + escapeHtml(row.closed_at)) : '')
                        + '</div>'
                        + '<div class="small mb-2"><b>Esito:</b> ' + escapeHtml(row.outcome_label || '-') + '</div>'
                        + (row.summary_public ? ('<p class="small mb-2">' + escapeHtml(row.summary_public) + '</p>') : '')
                        + '<div class="mb-2">' + rewardsHtml + '</div>'
                        + '<div class="d-flex justify-content-end">'
                        + '<button type="button" class="btn btn-sm btn-outline-secondary" data-action="quest-history-open" data-id="' + instanceId + '">Dettaglio</button>'
                        + '</div>'
                        + '</div>';
                }).join('');

                if (replace) {
                    this.listNode.innerHTML = html;
                } else {
                    this.listNode.insertAdjacentHTML('beforeend', html);
                }
                refreshTooltips(this.listNode);
            },

            openDetail: function (instanceId) {
                instanceId = parseInt(instanceId || '0', 10) || 0;
                if (instanceId <= 0) { return; }
                var mod = this.resolveModuleApi();
                if (!mod || typeof mod.historyGet !== 'function') { return; }

                if (this.detailBody) {
                    this.detailBody.innerHTML = '<div class="small text-muted">Caricamento...</div>';
                }

                var modalNode = document.getElementById('quests-history-detail-modal');
                if (modalNode && window.bootstrap && window.bootstrap.Modal) {
                    window.bootstrap.Modal.getOrCreateInstance(modalNode).show();
                }

                var self = this;
                mod.historyGet({ quest_instance_id: instanceId }).then(function (response) {
                    var ds = response && response.dataset ? response.dataset : {};
                    var history = ds.history || {};
                    var steps = Array.isArray(ds.steps) ? ds.steps : [];
                    var rewards = Array.isArray(history.rewards) ? history.rewards : [];
                    var logs = Array.isArray(ds.logs) ? ds.logs : [];
                    var intensity = intensityMeta(history);

                    var stepsHtml = steps.length
                        ? '<ul class="list-group list-group-flush mb-3">' + steps.map(function (step) {
                            return '<li class="list-group-item px-0 py-1 d-flex justify-content-between align-items-start">'
                                + '<span>' + escapeHtml(step.step_title || step.title || '-') + '</span>'
                                + '<span class="badge ' + statusBadge(step.progress_status || '') + '">' + escapeHtml(statusLabel(step.progress_status || '')) + '</span>'
                                + '</li>';
                        }).join('') + '</ul>'
                        : '<div class="small text-muted mb-3">Nessuno step registrato.</div>';

                    var rewardsHtml = rewards.length
                        ? '<ul class="list-group list-group-flush mb-3">' + rewards.map(function (reward) {
                            return '<li class="list-group-item px-0 py-1">'
                                + '<span class="fw-semibold">' + escapeHtml(reward.reward_label || '-') + '</span>'
                                + '<span class="small text-muted ms-2">(' + escapeHtml(reward.visibility || 'public') + ')</span>'
                                + '</li>';
                        }).join('') + '</ul>'
                        : '<div class="small text-muted mb-3">Nessuna ricompensa visibile.</div>';

                    var logsHtml = logs.length
                        ? '<ul class="list-group list-group-flush">' + logs.slice(0, 20).map(function (log) {
                            return '<li class="list-group-item px-0 py-1">'
                                + '<div class="small"><b>' + escapeHtml(log.log_type || '-') + '</b> · ' + escapeHtml(log.created_at || '-') + '</div>'
                                + '</li>';
                        }).join('') + '</ul>'
                        : '<div class="small text-muted">Nessun log disponibile.</div>';

                    if (self.detailBody) {
                        self.detailBody.innerHTML = ''
                            + '<div class="fw-bold mb-2">' + escapeHtml(history.quest_title || ('Quest #' + instanceId)) + '</div>'
                            + '<div class="small text-muted mb-2">Esito: <b>' + escapeHtml(history.outcome_label || '-') + '</b> · Stato: <b>' + escapeHtml(statusLabel(history.status || '')) + '</b></div>'
                            + (intensity ? ('<div class="small text-muted mb-2">Intensita narrativa: <span class="badge ' + intensity.badgeClass + '" data-bs-toggle="tooltip" data-bs-title="Pressione narrativa e peso delle conseguenze" title="Pressione narrativa e peso delle conseguenze">' + escapeHtml(intensity.label) + '</span></div>') : '')
                            + (history.summary_public ? ('<p class="mb-2">' + escapeHtml(history.summary_public) + '</p>') : '')
                            + '<div class="small text-muted mb-1">Step</div>' + stepsHtml
                            + '<div class="small text-muted mb-1">Ricompense</div>' + rewardsHtml
                            + '<div class="small text-muted mb-1">Log recenti</div>' + logsHtml;
                        refreshTooltips(self.detailBody);
                    }
                }).catch(function (error) {
                    if (self.detailBody) {
                        self.detailBody.innerHTML = '<div class="small text-danger">' + escapeHtml(errorMessage(error, 'Errore caricamento dettaglio quest.')) + '</div>';
                    }
                });
            },

            destroy: function () { return this; },
            unmount: function () { return this.destroy(); }
        };

        var instance = Object.assign({}, page, extension || {});
        return instance.init();
    }

    window.GameQuestHistoryPage = QuestHistoryPage;
})(window);
