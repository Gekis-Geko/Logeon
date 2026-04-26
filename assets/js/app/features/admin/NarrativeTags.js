const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

var AdminNarrativeTags = {
    initialized: false,
    root: null,
    filtersForm: null,
    grid: null,
    modalNode: null,
    modal: null,
    assignmentModalNode: null,
    assignmentModal: null,
    modalForm: null,
    rows: [],
    rowsById: {},
    editingRow: null,
    switches: {},
    assignmentForm: null,
    assignmentEntityInput: null,
    assignmentEntitySuggestions: null,
    assignmentTagsBox: null,
    assignmentTagFilterInput: null,
    assignmentStatus: null,
    assignmentCatalog: [],
    assignmentDebounceTimer: null,

    init: function () {
        if (this.initialized) {
            return this;
        }

        this.root = document.querySelector('#admin-page [data-admin-page="narrative-tags"]');
        if (!this.root) {
            return this;
        }

        this.filtersForm = this.root.querySelector('#admin-narrative-tags-filters');
        this.modalNode   = this.root.querySelector('#admin-narrative-tags-modal');
        this.assignmentModalNode = this.root.querySelector('#admin-narrative-tags-assignments-modal');
        this.modalForm   = this.root.querySelector('#admin-narrative-tags-form');
        this.assignmentForm = this.root.querySelector('#admin-narrative-tags-assignment-form');
        this.assignmentEntityInput = this.root.querySelector('[data-role="admin-narrative-tags-entity-input"]');
        this.assignmentEntitySuggestions = this.root.querySelector('[data-role="admin-narrative-tags-entity-suggestions"]');
        this.assignmentTagsBox = this.root.querySelector('[data-role="admin-narrative-tags-checkboxes"]');
        this.assignmentTagFilterInput = this.root.querySelector('[data-role="admin-narrative-tags-filter-tags"]');
        this.assignmentStatus = this.root.querySelector('[data-role="admin-narrative-tags-assign-status"]');

        if (!this.filtersForm || !this.modalNode || !this.modalForm) {
            return this;
        }

        this.modal = new bootstrap.Modal(this.modalNode);
        if (this.assignmentModalNode) {
            this.assignmentModal = new bootstrap.Modal(this.assignmentModalNode);
        }
        this.bind();
        this.initGrid();
        this.loadGrid();
        this.loadAssignmentCatalog();

        this.initialized = true;
        return this;
    },

    // ── Modal switch (is_active) ───────────────────────────────────────────

    initModalSwitch: function () {
        if (!this.modalForm) { return this; }
        if (typeof globalWindow.SwitchGroup !== 'function' || typeof globalWindow.$ !== 'function') {
            return this;
        }
        if (!this.switches.is_active) {
            this.switches.is_active = globalWindow.SwitchGroup(
                globalWindow.$('#admin-narrative-tags-form [name="is_active"]'),
                { preset: 'activeinactive', trueValue: '1', falseValue: '0', defaultValue: '1' }
            );
        }
        return this;
    },

    // ── Events ────────────────────────────────────────────────────────────

    bind: function () {
        var self = this;

        this.filtersForm.addEventListener('submit', function (event) {
            event.preventDefault();
            self.loadGrid();
        });

        if (this.assignmentEntityInput) {
            this.assignmentEntityInput.addEventListener('input', function () {
                self.resetAssignmentEntityId();
                self.debounceAssignmentSearch();
            });
        }

        if (this.assignmentTagFilterInput) {
            this.assignmentTagFilterInput.addEventListener('input', function () {
                self.renderAssignmentTagCheckboxes();
            });
        }

        if (this.assignmentForm && this.assignmentForm.elements && this.assignmentForm.elements.entity_type) {
            this.assignmentForm.elements.entity_type.addEventListener('change', function () {
                self.resetAssignmentEntity();
            });
        }

        this.root.addEventListener('click', function (event) {
            var suggestion = event.target && event.target.closest
                ? event.target.closest('[data-role="admin-narrative-tags-entity-suggestion"]')
                : null;
            if (suggestion) {
                event.preventDefault();
                self.pickAssignmentSuggestion(suggestion);
                return;
            }

            var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
            if (!trigger) { return; }
            var action = String(trigger.getAttribute('data-action') || '').trim();

            switch (action) {
                case 'admin-narrative-tags-open-assignments':
                    event.preventDefault();
                    self.openAssignmentsModal();
                    break;
                case 'admin-narrative-tags-create':
                    event.preventDefault();
                    self.openCreate();
                    break;
                case 'admin-narrative-tags-reload':
                    event.preventDefault();
                    self.loadGrid();
                    break;
                case 'admin-narrative-tag-edit':
                    event.preventDefault();
                    self.openEdit(parseInt(trigger.getAttribute('data-id') || '0', 10));
                    break;
                case 'admin-narrative-tag-save':
                    event.preventDefault();
                    self.save();
                    break;
                case 'admin-narrative-tag-delete':
                    event.preventDefault();
                    self.remove();
                    break;
                case 'admin-narrative-tags-entity-load':
                    event.preventDefault();
                    self.loadAssignmentEntityTags();
                    break;
                case 'admin-narrative-tags-entity-save':
                    event.preventDefault();
                    self.saveAssignmentEntityTags();
                    break;
            }
        });

        document.addEventListener('click', function (event) {
            if (!self.assignmentEntitySuggestions) { return; }
            if (!event.target || !event.target.closest) { return; }
            if (event.target.closest('[data-role="admin-narrative-tags-entity-suggestions"]')
                || event.target === self.assignmentEntityInput) {
                return;
            }
            self.hideAssignmentSuggestions();
        });
    },

    // ── Grid ──────────────────────────────────────────────────────────────

    initGrid: function () {
        var self = this;

        this.grid = new Datagrid('grid-admin-narrative-tags', {
            name: 'AdminNarrativeTags',
            autoindex: 'id',
            orderable: true,
            thead: true,
            handler: { url: '/admin/narrative-tags/list', action: 'list' },
            nav: { display: 'bottom', urlupdate: 0, results: 25, page: 1 },
            onGetDataSuccess: function (response) {
                self.setRows(response && Array.isArray(response.dataset) ? response.dataset : []);
            },
            onGetDataError: function () {
                self.setRows([]);
            },
            columns: [
                {
                    label: 'ID',
                    field: 'id',
                    sortable: true,
                    width: '60px',
                    format: function (row) {
                        return '<span class="text-muted small">' + (row.id || '') + '</span>';
                    }
                },
                {
                    label: 'Slug',
                    field: 'slug',
                    sortable: true,
                    format: function (row) {
                        return '<code class="small">' + self.escapeHtml(row.slug || '') + '</code>';
                    }
                },
                {
                    label: 'Label',
                    field: 'label',
                    sortable: true,
                    format: function (row) {
                        return '<span class="fw-semibold">' + self.escapeHtml(row.label || '') + '</span>';
                    }
                },
                {
                    label: 'Categoria',
                    field: 'category',
                    sortable: true,
                    format: function (row) {
                        var cat = row.category || '';
                        if (!cat) { return '<span class="text-muted small">—</span>'; }
                        return '<span class="badge bg-secondary">' + self.escapeHtml(cat) + '</span>';
                    }
                },
                {
                    label: 'Assegnazioni',
                    field: 'assignments_count',
                    sortable: false,
                    format: function (row) {
                        var n = parseInt(row.assignments_count || 0, 10);
                        return '<span class="badge bg-light text-dark">' + n + '</span>';
                    }
                },
                {
                    label: 'Stato',
                    field: 'is_active',
                    sortable: true,
                    format: function (row) {
                        return parseInt(row.is_active, 10) === 1
                            ? '<span class="badge bg-success">Attivo</span>'
                            : '<span class="badge bg-danger">Inattivo</span>';
                    }
                },
                {
                    label: '',
                    field: 'id',
                    sortable: false,
                    format: function (row) {
                        return '<button class="btn btn-xs btn-outline-primary" data-action="admin-narrative-tag-edit" data-id="' + self.escapeAttr(row.id) + '">'
                            + '<i class="bi bi-pencil"></i></button>';
                    }
                }
            ]
        });
    },

    buildFiltersPayload: function () {
        var payload = {};
        var searchEl    = this.root.querySelector('#admin-narrative-tags-search');
        var categoryEl  = this.root.querySelector('#admin-narrative-tags-filter-category');
        var activeEl    = this.root.querySelector('#admin-narrative-tags-filter-active');

        if (searchEl   && searchEl.value.trim())   { payload.search    = searchEl.value.trim(); }
        if (categoryEl && categoryEl.value.trim()) { payload.category  = categoryEl.value.trim(); }
        if (activeEl   && activeEl.value !== '')   { payload.is_active = activeEl.value; }

        return payload;
    },

    loadGrid: function () {
        if (!this.grid || typeof this.grid.loadData !== 'function') { return; }
        this.grid.loadData(this.buildFiltersPayload(), 25, 1, 'label|ASC');
    },

    setRows: function (rows) {
        this.rows = rows;
        this.rowsById = {};
        for (var i = 0; i < rows.length; i++) {
            this.rowsById[rows[i].id] = rows[i];
        }
    },

    // ── Modal CRUD ────────────────────────────────────────────────────────

    openCreate: function () {
        this.editingRow = null;
        this.initModalSwitch();
        this.resetForm();
        this.toggleDeleteButton(false);
        this.modal.show();
    },

    openAssignmentsModal: function () {
        this.resetAssignmentEntity();
        if (this.assignmentModal && typeof this.assignmentModal.show === 'function') {
            this.assignmentModal.show();
        }
    },

    openEdit: function (id) {
        var row = this.rowsById[id];
        if (!row) { return; }
        this.editingRow = row;
        this.initModalSwitch();
        this.resetForm();
        this.setField('id',          row.id || '');
        this.setField('slug',        row.slug || '');
        this.setField('label',       row.label || '');
        this.setField('category',    row.category || '');
        this.setField('description', row.description || '');
        if (this.switches.is_active) {
            this.switches.is_active.setValue(String(row.is_active) === '1' ? '1' : '0');
        }
        this.toggleDeleteButton(true);
        this.modal.show();
    },

    resetForm: function () {
        if (!this.modalForm) { return; }
        this.modalForm.reset();
        this.setField('id', '');
        if (this.switches.is_active) {
            this.switches.is_active.setValue('1');
        }
    },

    toggleDeleteButton: function (show) {
        var btn = this.modalForm && this.modalForm.closest('.modal-content')
            ? this.modalForm.closest('.modal-content').querySelector('[data-action="admin-narrative-tag-delete"]')
            : null;
        if (!btn) { return; }
        if (show) { btn.classList.remove('d-none'); }
        else      { btn.classList.add('d-none'); }
    },

    setField: function (name, value) {
        var el = this.modalForm ? this.modalForm.querySelector('[name="' + name + '"]') : null;
        if (el) { el.value = value; }
    },

    getField: function (name) {
        var el = this.modalForm ? this.modalForm.querySelector('[name="' + name + '"]') : null;
        return el ? el.value : '';
    },

    collectPayload: function () {
        var isActive = this.switches.is_active
            ? this.switches.is_active.getValue()
            : this.getField('is_active');

        return {
            id:          this.getField('id') ? parseInt(this.getField('id'), 10) : undefined,
            slug:        this.getField('slug').trim(),
            label:       this.getField('label').trim(),
            category:    this.getField('category'),
            description: this.getField('description').trim(),
            is_active:   isActive === '1' ? 1 : 0
        };
    },

    save: function () {
        var self    = this;
        var payload = this.collectPayload();
        var url     = payload.id ? '/admin/narrative-tags/update' : '/admin/narrative-tags/create';

        if (!payload.slug || !payload.label) {
            if (typeof Toast !== 'undefined') {
                Toast.show({ body: 'Slug e label sono obbligatori.', type: 'warning' });
            }
            return;
        }

        this.post(url, payload, function () {
            self.modal.hide();
            self.loadGrid();
            Toast.show({ body: payload.id ? 'Tag aggiornato.' : 'Tag creato.', type: 'success' });
        });
    },

    remove: function () {
        var self = this;
        var id   = parseInt(this.getField('id') || '0', 10);
        if (!id) { return; }

        Dialog('danger', {
            title: 'Elimina tag',
            body: '<p>Eliminare questo tag? Verranno rimosse tutte le assegnazioni associate.</p>'
        }, function () {
            self.post('/admin/narrative-tags/delete', { id: id }, function () {
                self.modal.hide();
                self.loadGrid();
                Toast.show({ body: 'Tag eliminato.', type: 'success' });
            });
        }).show();
    },

    // ── Assignment UI ──────────────────────────────────────────────────────

    debounceAssignmentSearch: function () {
        var self = this;
        if (this.assignmentDebounceTimer) {
            clearTimeout(this.assignmentDebounceTimer);
        }
        this.assignmentDebounceTimer = setTimeout(function () {
            self.searchAssignmentEntities();
        }, 180);
    },

    assignmentEntityType: function () {
        if (!this.assignmentForm || !this.assignmentForm.elements || !this.assignmentForm.elements.entity_type) {
            return 'quest_definition';
        }
        return String(this.assignmentForm.elements.entity_type.value || 'quest_definition').trim();
    },

    assignmentEntityId: function () {
        if (!this.assignmentForm || !this.assignmentForm.elements || !this.assignmentForm.elements.entity_id) {
            return 0;
        }
        return parseInt(this.assignmentForm.elements.entity_id.value || '0', 10) || 0;
    },

    resetAssignmentEntityId: function () {
        if (!this.assignmentForm || !this.assignmentForm.elements || !this.assignmentForm.elements.entity_id) { return; }
        this.assignmentForm.elements.entity_id.value = '0';
    },

    resetAssignmentEntity: function () {
        if (this.assignmentEntityInput) {
            this.assignmentEntityInput.value = '';
        }
        this.resetAssignmentEntityId();
        this.hideAssignmentSuggestions();
        this.setAssignmentStatus('Seleziona una entita per modificare i tag.');
        this.clearAssignmentChecked();
    },

    hideAssignmentSuggestions: function () {
        if (!this.assignmentEntitySuggestions) { return; }
        this.assignmentEntitySuggestions.classList.add('d-none');
        this.assignmentEntitySuggestions.innerHTML = '';
    },

    searchAssignmentEntities: function () {
        var self = this;
        if (!this.assignmentEntityInput || !this.assignmentEntitySuggestions) { return; }
        var query = String(this.assignmentEntityInput.value || '').trim();
        var entityType = this.assignmentEntityType();
        if (query.length < 2) {
            this.hideAssignmentSuggestions();
            return;
        }

        this.post('/admin/narrative-tags/entity/search', {
            entity_type: entityType,
            query: query,
            limit: 20
        }, function (response) {
            var rows = response && Array.isArray(response.dataset) ? response.dataset : [];
            if (!rows.length) {
                self.hideAssignmentSuggestions();
                return;
            }
            self.assignmentEntitySuggestions.innerHTML = rows.map(function (row) {
                var id = parseInt(row.id || '0', 10) || 0;
                var label = String(row.label || ('ID #' + id));
                var secondary = String(row.secondary || '').trim();
                return '<button type="button" class="list-group-item list-group-item-action py-1 small"'
                    + ' data-role="admin-narrative-tags-entity-suggestion"'
                    + ' data-id="' + id + '"'
                    + ' data-label="' + self.escapeAttr(label) + '">'
                    + self.escapeHtml(label)
                    + (secondary ? (' <span class="text-muted">(' + self.escapeHtml(secondary) + ')</span>') : '')
                    + '</button>';
            }).join('');
            self.assignmentEntitySuggestions.classList.remove('d-none');
        }, function () {
            self.hideAssignmentSuggestions();
        });
    },

    pickAssignmentSuggestion: function (node) {
        if (!node || !this.assignmentForm || !this.assignmentForm.elements) { return; }
        var id = parseInt(node.getAttribute('data-id') || '0', 10) || 0;
        var label = String(node.getAttribute('data-label') || '').trim();
        if (id <= 0) { return; }

        this.assignmentForm.elements.entity_id.value = String(id);
        if (this.assignmentEntityInput) {
            this.assignmentEntityInput.value = label || ('ID #' + id);
        }
        this.hideAssignmentSuggestions();
        this.loadAssignmentEntityTags();
    },

    loadAssignmentCatalog: function () {
        var self = this;
        if (!this.assignmentTagsBox) { return; }
        this.assignmentTagsBox.innerHTML = '<div class="small text-muted">Caricamento tag...</div>';
        this.post('/admin/narrative-tags/list', {
            is_active: 1,
            page: 1,
            results_page: 300,
            orderBy: 'label|ASC'
        }, function (response) {
            self.assignmentCatalog = response && Array.isArray(response.dataset) ? response.dataset : [];
            self.renderAssignmentTagCheckboxes();
        }, function () {
            self.assignmentCatalog = [];
            self.assignmentTagsBox.innerHTML = '<div class="small text-danger">Impossibile caricare il catalogo tag.</div>';
        });
    },

    renderAssignmentTagCheckboxes: function () {
        if (!this.assignmentTagsBox) { return; }
        var search = this.assignmentTagFilterInput ? String(this.assignmentTagFilterInput.value || '').trim().toLowerCase() : '';
        var rows = this.assignmentCatalog.filter(function (row) {
            if (!search) { return true; }
            var text = String((row.label || '') + ' ' + (row.slug || '')).toLowerCase();
            return text.indexOf(search) !== -1;
        });
        if (!rows.length) {
            this.assignmentTagsBox.innerHTML = '<div class="small text-muted">Nessun tag disponibile.</div>';
            return;
        }
        this.assignmentTagsBox.innerHTML = rows.map(function (row) {
            var id = parseInt(row.id || '0', 10) || 0;
            if (id <= 0) { return ''; }
            var label = String(row.label || row.slug || ('Tag #' + id));
            var slug = String(row.slug || '').trim();
            var inputId = 'admin-narrative-tag-assignment-' + id;
            return '<div class="form-check py-1 mb-0">'
                + '<input class="form-check-input float-none me-2 mt-0" type="checkbox"'
                + ' id="' + inputId + '"'
                + ' data-role="admin-narrative-tags-checkbox"'
                + ' value="' + id + '">'
                + '<label class="form-check-label small lh-sm text-break d-inline" for="' + inputId + '">'
                + AdminNarrativeTags.escapeHtml(label)
                + (slug ? (' <span class="text-muted">(' + AdminNarrativeTags.escapeHtml(slug) + ')</span>') : '')
                + '</label>'
                + '</div>';
        }).join('');
    },

    clearAssignmentChecked: function () {
        if (!this.assignmentTagsBox) { return; }
        var checks = this.assignmentTagsBox.querySelectorAll('[data-role="admin-narrative-tags-checkbox"]');
        for (var i = 0; i < checks.length; i += 1) {
            checks[i].checked = false;
        }
    },

    setAssignmentChecked: function (tagIds) {
        if (!this.assignmentTagsBox) { return; }
        var map = {};
        for (var i = 0; i < tagIds.length; i += 1) {
            map[String(parseInt(tagIds[i] || '0', 10) || 0)] = true;
        }
        var checks = this.assignmentTagsBox.querySelectorAll('[data-role="admin-narrative-tags-checkbox"]');
        for (var j = 0; j < checks.length; j += 1) {
            var id = String(parseInt(checks[j].value || '0', 10) || 0);
            checks[j].checked = !!map[id];
        }
    },

    collectAssignmentChecked: function () {
        var out = [];
        if (!this.assignmentTagsBox) { return out; }
        var checks = this.assignmentTagsBox.querySelectorAll('[data-role="admin-narrative-tags-checkbox"]:checked');
        for (var i = 0; i < checks.length; i += 1) {
            var id = parseInt(checks[i].value || '0', 10) || 0;
            if (id > 0) { out.push(id); }
        }
        return out;
    },

    setAssignmentStatus: function (message, type) {
        if (!this.assignmentStatus) { return; }
        this.assignmentStatus.classList.remove('text-danger', 'text-success');
        if (type === 'error') {
            this.assignmentStatus.classList.add('text-danger');
        } else if (type === 'success') {
            this.assignmentStatus.classList.add('text-success');
        }
        this.assignmentStatus.textContent = message || '';
    },

    loadAssignmentEntityTags: function () {
        var self = this;
        var entityId = this.assignmentEntityId();
        var entityType = this.assignmentEntityType();
        if (entityId <= 0) {
            this.setAssignmentStatus('Seleziona prima una entita valida.', 'error');
            return;
        }
        this.post('/admin/narrative-tags/entity/get', {
            entity_type: entityType,
            entity_id: entityId
        }, function (response) {
            var rows = response && Array.isArray(response.dataset) ? response.dataset : [];
            var tagIds = rows.map(function (row) { return parseInt(row.id || '0', 10) || 0; }).filter(function (id) { return id > 0; });
            self.setAssignmentChecked(tagIds);
            self.setAssignmentStatus('Assegnazioni caricate. Tag attivi: ' + tagIds.length + '.', 'success');
        });
    },

    saveAssignmentEntityTags: function () {
        var self = this;
        var entityId = this.assignmentEntityId();
        var entityType = this.assignmentEntityType();
        if (entityId <= 0) {
            this.setAssignmentStatus('Seleziona prima una entita valida.', 'error');
            return;
        }
        var tagIds = this.collectAssignmentChecked();
        this.post('/admin/narrative-tags/entity/sync', {
            entity_type: entityType,
            entity_id: entityId,
            tag_ids: tagIds
        }, function () {
            self.setAssignmentStatus('Assegnazioni salvate. Tag associati: ' + tagIds.length + '.', 'success');
            self.loadGrid();
        });
    },

    // ── HTTP ──────────────────────────────────────────────────────────────

    post: function (url, payload, onSuccess, onError) {
        var http = globalWindow.Request && globalWindow.Request.http ? globalWindow.Request.http : null;
        if (http && typeof http.post === 'function') {
            http.post(url, payload)
                .then(function (response) { if (typeof onSuccess === 'function') { onSuccess(response); } })
                .catch(function (err) {
                    var msg = globalWindow.Request && typeof globalWindow.Request.getErrorMessage === 'function'
                        ? globalWindow.Request.getErrorMessage(err)
                        : 'Errore nella richiesta.';
                    alert(msg);
                    if (typeof onError === 'function') { onError(err); }
                });
            return;
        }
        if (typeof globalWindow.$ === 'function' && typeof globalWindow.$.ajax === 'function') {
            globalWindow.$.ajax({
                url: url,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ data: payload }),
                success: function (response) { if (typeof onSuccess === 'function') { onSuccess(response); } },
                error: function (err) {
                    alert('Errore nella richiesta.');
                    if (typeof onError === 'function') { onError(err); }
                }
            });
        }
    },

    // ── Utilities ─────────────────────────────────────────────────────────

    escapeHtml: function (str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    },

    escapeAttr: function (str) {
        return String(str || '').replace(/"/g, '&quot;');
    }
};

globalWindow.AdminNarrativeTags = AdminNarrativeTags;
export { AdminNarrativeTags as AdminNarrativeTags };
export default AdminNarrativeTags;

