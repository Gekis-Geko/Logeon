(function () {
    'use strict';

    var AdminNarrativeEvents = {
        initialized: false,
        root: null,
        grid: null,
        rowsById: {},
        filterType: null,
        filterScope: null,
        filterVisibility: null,
        filterTag: null,
        tagsCatalog: [],
        detailPanel: null,
        detailEmpty: null,
        visibilityIdInput: null,
        visibilitySelect: null,

        init: function () {
            if (this.initialized) {
                return this;
            }

            this.root = document.querySelector('#admin-page [data-admin-page="narrative-events"]');
            if (!this.root) {
                return this;
            }

            this.filterType = document.getElementById('admin-narrative-events-filter-type');
            this.filterScope = document.getElementById('admin-narrative-events-filter-scope');
            this.filterVisibility = document.getElementById('admin-narrative-events-filter-visibility');
            this.filterTag = document.getElementById('admin-narrative-events-filter-tag');
            this.detailPanel = document.getElementById('admin-narrative-event-detail-modal-body');
            this.detailEmpty = document.getElementById('admin-narrative-event-detail-modal-empty');
            this.visibilityIdInput = document.getElementById('admin-narrative-event-visibility-id');
            this.visibilitySelect = document.getElementById('admin-narrative-event-visibility-value');

            if (!document.getElementById('grid-admin-narrative-events')) {
                return this;
            }

            this.bindEvents();
            this.loadTagCatalog();
            this.initGrid();
            this.loadGrid();

            this.initialized = true;
            return this;
        },

        bindEvents: function () {
            var self = this;
            var filtersForm = document.getElementById('admin-narrative-events-filters');

            if (filtersForm) {
                filtersForm.addEventListener('submit', function (event) {
                    event.preventDefault();
                    self.loadGrid();
                });
            }

            [this.filterType, this.filterScope, this.filterVisibility, this.filterTag].forEach(function (el) {
                if (el) {
                    el.addEventListener('change', function () {
                        self.loadGrid();
                    });
                }
            });

            this.root.addEventListener('click', function (event) {
                var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
                if (!trigger) {
                    return;
                }

                var action = String(trigger.getAttribute('data-action') || '').trim();
                if (!action) {
                    return;
                }

                if (action === 'admin-narrative-events-reload') {
                    event.preventDefault();
                    self.loadGrid();
                    return;
                }

                if (action === 'admin-narrative-events-filters-reset') {
                    event.preventDefault();
                    if (self.filterType) { self.filterType.value = ''; }
                    if (self.filterScope) { self.filterScope.value = ''; }
                    if (self.filterVisibility) { self.filterVisibility.value = ''; }
                    if (self.filterTag) { self.filterTag.value = ''; }
                    self.loadGrid();
                    return;
                }

                if (action === 'admin-narrative-event-view') {
                    event.preventDefault();
                    self.viewDetail(self.findRowByTrigger(trigger));
                    return;
                }

                if (action === 'admin-narrative-event-visibility') {
                    event.preventDefault();
                    self.openVisibilityModal(self.findRowByTrigger(trigger));
                    return;
                }

                if (action === 'admin-narrative-event-visibility-save') {
                    event.preventDefault();
                    self.saveVisibilityFromModal();
                    return;
                }

                if (action === 'admin-narrative-event-delete') {
                    event.preventDefault();
                    self.confirmDelete(self.findRowByTrigger(trigger));
                    return;
                }

                if (action === 'admin-narrative-event-tags-save') {
                    event.preventDefault();
                    self.saveEventTags();
                }
            });
        },

        initGrid: function () {
            var self = this;

            this.grid = new Datagrid('grid-admin-narrative-events', {
                name: 'AdminNarrativeEvents',
                autoindex: 'id',
                orderable: true,
                thead: true,
                handler: { url: '/admin/narrative-events/list', action: 'list' },
                nav: { display: 'bottom', urlupdate: 0, results: 20, page: 1 },
                columns: [
                    { label: 'ID', field: 'id', sortable: true },
                    {
                        label: 'Titolo',
                        field: 'title',
                        sortable: true,
                        style: { textAlign: 'left' },
                        format: function (row) {
                            return '<span title="' + self.escapeHtml(row.title || '') + '">' + self.escapeHtml(self.truncate(row.title || '', 60)) + '</span>';
                        }
                    },
                    { label: 'Tipo', field: 'event_type', sortable: true },
                    { label: 'Scope', field: 'scope', sortable: true },
                    {
                        label: 'Visibilita',
                        field: 'visibility',
                        sortable: true,
                        format: function (row) {
                            var vis = String(row.visibility || 'public');
                            var map = {
                                'public': 'text-bg-success',
                                'private': 'text-bg-warning',
                                'staff_only': 'text-bg-info',
                                'hidden': 'text-bg-secondary'
                            };
                            return '<span class="badge ' + (map[vis] || 'text-bg-secondary') + '">' + self.escapeHtml(vis) + '</span>';
                        }
                    },
                    {
                        label: 'Origine',
                        field: 'source_system',
                        sortable: true,
                        format: function (row) {
                            var source = String(row.source_system || 'manual').trim();
                            return self.escapeHtml(source || 'manual');
                        }
                    },
                    {
                        label: 'Tag',
                        field: 'narrative_tags',
                        sortable: false,
                        format: function (row) {
                            var tags = Array.isArray(row.narrative_tags) ? row.narrative_tags : [];
                            if (!tags.length) {
                                return '<span class="text-muted small">-</span>';
                            }
                            return tags.slice(0, 3).map(function (tag) {
                                return '<span class="badge text-bg-secondary me-1">' + self.escapeHtml(tag.label || tag.slug || 'Tag') + '</span>';
                            }).join('');
                        }
                    },
                    {
                        label: 'Data',
                        field: 'created_at',
                        sortable: true,
                        format: function (row) {
                            return self.escapeHtml(row.created_at || row.date_created || '-');
                        }
                    },
                    {
                        label: 'Azioni',
                        sortable: false,
                        style: { textAlign: 'left' },
                        format: function (row) {
                            var id = parseInt(row.id || '0', 10) || 0;
                            if (id > 0) {
                                self.rowsById[id] = row;
                            }
                            return '<div class="d-flex flex-wrap gap-1">'
                                + '<button type="button" class="btn btn-sm btn-outline-secondary" data-action="admin-narrative-event-view" data-id="' + id + '">Dettaglio</button>'
                                + '<button type="button" class="btn btn-sm btn-outline-primary" data-action="admin-narrative-event-visibility" data-id="' + id + '">Visib.</button>'
                                + '<button type="button" class="btn btn-sm btn-outline-danger" data-action="admin-narrative-event-delete" data-id="' + id + '">Elimina</button>'
                                + '</div>';
                        }
                    }
                ]
            });
        },

        loadGrid: function () {
            if (!this.grid) {
                return this;
            }
            this.rowsById = {};
            this.grid.loadData({
                event_type: this.filterType ? (this.filterType.value || '') : '',
                scope: this.filterScope ? (this.filterScope.value || '') : '',
                visibility: this.filterVisibility ? (this.filterVisibility.value || '') : '',
                tag_ids: this.filterTag && this.filterTag.value ? [parseInt(this.filterTag.value || '0', 10) || 0] : []
            }, 20, 1, 'created_at|DESC');
            return this;
        },

        loadTagCatalog: function () {
            var self = this;
            if (!this.filterTag) { return; }
            this.requestPost('/list/narrative-tags', { entity_type: 'narrative_event' }, function (response) {
                var rows = response && Array.isArray(response.dataset) ? response.dataset : [];
                self.tagsCatalog = rows;
                self.renderTagFilterOptions();
            });
        },

        renderTagFilterOptions: function () {
            if (!this.filterTag) { return; }
            var current = String(this.filterTag.value || '');
            var html = '<option value="">Tutti i tag</option>';
            for (var i = 0; i < this.tagsCatalog.length; i += 1) {
                var row = this.tagsCatalog[i] || {};
                var id = parseInt(row.id || '0', 10) || 0;
                if (id <= 0) { continue; }
                html += '<option value="' + id + '">' + this.escapeHtml(row.label || row.slug || ('Tag #' + id)) + '</option>';
            }
            this.filterTag.innerHTML = html;
            if (current !== '') {
                this.filterTag.value = current;
            }
        },

        selectedEventId: 0,

        renderTagCheckboxes: function (selectedIds) {
            var container = document.getElementById('admin-narrative-event-tags-container');
            if (!container) { return; }
            if (!this.tagsCatalog.length) {
                container.innerHTML = '<span class="text-muted small">Nessun tag disponibile.</span>';
                return;
            }
            var selected = {};
            for (var i = 0; i < selectedIds.length; i++) {
                selected[parseInt(selectedIds[i], 10)] = true;
            }
            var html = '';
            for (var j = 0; j < this.tagsCatalog.length; j++) {
                var tag = this.tagsCatalog[j];
                var id = parseInt(tag.id || '0', 10);
                if (!id) { continue; }
                var checked = selected[id] ? ' checked' : '';
                html += '<div class="form-check form-check-inline mb-0">'
                    + '<input class="form-check-input" type="checkbox" value="' + id + '" id="ne-tag-' + id + '"' + checked + '>'
                    + '<label class="form-check-label small" for="ne-tag-' + id + '">' + this.escapeHtml(tag.label || tag.slug || ('Tag #' + id)) + '</label>'
                    + '</div>';
            }
            container.innerHTML = html || '<span class="text-muted small">Nessun tag disponibile.</span>';
        },

        collectTagIds: function () {
            var container = document.getElementById('admin-narrative-event-tags-container');
            if (!container) { return []; }
            var checks = container.querySelectorAll('input[type="checkbox"]:checked');
            var ids = [];
            for (var i = 0; i < checks.length; i++) {
                var v = parseInt(checks[i].value || '0', 10);
                if (v > 0) { ids.push(v); }
            }
            return ids;
        },

        saveEventTags: function () {
            var eventId = this.selectedEventId;
            if (eventId <= 0) { return; }
            var self = this;
            this.requestPost('/admin/narrative-events/tags/set', { id: eventId, tag_ids: this.collectTagIds() }, function () {
                Toast.show({ body: 'Tag aggiornati.', type: 'success' });
                self.reloadGridKeepingPosition();
            });
        },

        findRowByTrigger: function (trigger) {
            var id = parseInt(trigger.getAttribute('data-id') || '0', 10) || 0;
            return id > 0 ? (this.rowsById[id] || null) : null;
        },

        viewDetail: function (row) {
            if (!row || !row.id) {
                return;
            }
            var self = this;
            this.selectedEventId = parseInt(row.id || '0', 10) || 0;
            this.requestPost('/admin/narrative-events/get', { id: row.id }, function (response) {
                var ev = (response && response.dataset) ? response.dataset : row;
                self.renderDetail(ev);
                var tagIds = Array.isArray(ev.narrative_tag_ids) ? ev.narrative_tag_ids : [];
                self.renderTagCheckboxes(tagIds);
                var wrap = document.getElementById('admin-narrative-event-tags-wrap');
                if (wrap) { wrap.classList.remove('d-none'); }
                self.showModal('admin-narrative-event-detail-modal');
            });
        },

        renderDetail: function (event) {
            if (!this.detailPanel || !this.detailEmpty) {
                return;
            }

            var refs = Array.isArray(event.entity_refs) ? event.entity_refs : [];
            var tags = Array.isArray(event.narrative_tags) ? event.narrative_tags : [];
            var refsHtml = refs.length
                ? refs.map(function (r) {
                    var type = String(r.entity_type || '-');
                    var entityId = parseInt(r.entity_id || '0', 10) || 0;
                    var role = String(r.role || '-');
                    return '<li class="list-group-item py-1 small"><b>' + AdminNarrativeEvents.escapeHtml(type)
                        + '</b> #' + entityId
                        + ' <span class="text-muted">(' + AdminNarrativeEvents.escapeHtml(role) + ')</span></li>';
                }).join('')
                : '<li class="list-group-item py-1 small text-muted">Nessuna entita collegata.</li>';

            this.detailPanel.innerHTML = ''
                + '<div class="mb-2"><b>' + this.escapeHtml(event.title || '') + '</b></div>'
                + '<div class="small text-muted mb-2">'
                + 'Tipo: ' + this.escapeHtml(event.event_type || '-')
                + ' &bull; Scope: ' + this.escapeHtml(event.scope || '-')
                + ' &bull; Visibilita: ' + this.escapeHtml(event.visibility || '-')
                + ' &bull; Origine: ' + this.escapeHtml(event.source_system || 'manual')
                + ' &bull; ' + this.escapeHtml(event.created_at || event.date_created || '-')
                + '</div>'
                + (event.description ? '<p class="small mb-2">' + this.escapeHtml(event.description) + '</p>' : '')
                + (tags.length
                    ? ('<div class="small text-muted mb-2">Tag: '
                        + tags.map(function (tag) {
                            return '<span class="badge text-bg-secondary me-1">' + AdminNarrativeEvents.escapeHtml(tag.label || tag.slug || 'Tag') + '</span>';
                        }).join('')
                        + '</div>')
                    : '')
                + '<div class="small mb-1"><b>Entita collegate</b></div>'
                + '<ul class="list-group list-group-flush mb-0">' + refsHtml + '</ul>';

            this.detailEmpty.classList.add('d-none');
            this.detailPanel.classList.remove('d-none');
        },

        openVisibilityModal: function (row) {
            if (!row || !row.id) {
                return;
            }
            if (this.visibilityIdInput) {
                this.visibilityIdInput.value = String(parseInt(row.id || '0', 10) || 0);
            }
            if (this.visibilitySelect) {
                this.visibilitySelect.value = String(row.visibility || 'public');
            }
            this.showModal('admin-narrative-event-visibility-modal');
        },

        saveVisibilityFromModal: function () {
            var eventId = this.visibilityIdInput ? (parseInt(this.visibilityIdInput.value || '0', 10) || 0) : 0;
            var next = this.visibilitySelect ? String(this.visibilitySelect.value || '').trim() : '';
            if (eventId <= 0) {
                Toast.show({ body: 'Attivita non valida.', type: 'warning' });
                return;
            }
            if (['public', 'private', 'staff_only', 'hidden'].indexOf(next) === -1) {
                Toast.show({ body: 'Visibilita non valida.', type: 'warning' });
                return;
            }
            var self = this;
            this.requestPost('/admin/narrative-events/update', { id: eventId, visibility: next }, function () {
                self.hideModal('admin-narrative-event-visibility-modal');
                Toast.show({ body: 'Visibilita aggiornata: ' + next, type: 'success' });
                self.reloadGridKeepingPosition();
            });
        },

        confirmDelete: function (row) {
            if (!row || !row.id) {
                return;
            }
            var self = this;
            Dialog('danger', {
                title: 'Elimina attivita recente',
                body: '<p>Confermi l\'eliminazione di <b>' + this.escapeHtml(row.title || 'attivita') + '</b>?</p>'
                    + '<p class="small text-warning">L\'eliminazione e irreversibile.</p>'
            }, function () {
                self.hideConfirmDialog();
                self.requestPost('/admin/narrative-events/delete', { id: row.id }, function () {
                    Toast.show({ body: 'Attivita eliminata.', type: 'success' });
                    self.loadGrid();
                });
            }).show();
        },

        requestPost: function (url, payload, onSuccess, onError) {
            var self = this;
            if (!window.Request || !Request.http || typeof Request.http.post !== 'function') {
                Toast.show({ body: 'Servizio non disponibile.', type: 'error' });
                return this;
            }

            Request.http.post(url, payload || {}).then(function (response) {
                if (typeof onSuccess === 'function') {
                    onSuccess(response || null);
                }
            }).catch(function (error) {
                if (typeof onError === 'function') {
                    onError(error);
                    return;
                }
                Toast.show({ body: self.requestErrorMessage(error), type: 'error' });
            });
            return this;
        },

        requestErrorMessage: function (error) {
            if (window.Request && typeof window.Request.getErrorMessage === 'function') {
                return window.Request.getErrorMessage(error, 'Operazione non riuscita.');
            }
            if (error && typeof error.message === 'string' && error.message.trim()) {
                return error.message.trim();
            }
            return 'Operazione non riuscita.';
        },

        showModal: function (id) {
            var modalNode = document.getElementById(id);
            if (!modalNode) {
                return;
            }
            if (window.bootstrap && window.bootstrap.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(modalNode).show();
                return;
            }
            if (typeof $ === 'function') {
                $(modalNode).modal('show');
            }
        },

        hideModal: function (id) {
            var modalNode = document.getElementById(id);
            if (!modalNode) {
                return;
            }
            if (window.bootstrap && window.bootstrap.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(modalNode).hide();
                return;
            }
            if (typeof $ === 'function') {
                $(modalNode).modal('hide');
            }
        },

        reloadGridKeepingPosition: function () {
            if (this.grid && typeof this.grid.reloadData === 'function') {
                this.grid.reloadData();
                return;
            }
            this.loadGrid();
        },

        hideConfirmDialog: function () {
            if (window.SystemDialogs && typeof window.SystemDialogs.ensureGeneralConfirm === 'function') {
                var dialog = window.SystemDialogs.ensureGeneralConfirm();
                if (dialog && typeof dialog.hide === 'function') {
                    dialog.hide();
                }
            } else if (window.generalConfirm && typeof window.generalConfirm.hide === 'function') {
                window.generalConfirm.hide();
            }
        },

        truncate: function (value, max) {
            var str = String(value || '');
            return str.length > max ? str.substring(0, max) + '...' : str;
        },

        escapeHtml: function (value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }
    };

    window.AdminNarrativeEvents = AdminNarrativeEvents;
})();
