(function () {
    'use strict';

    var AdminQuests = {
        initialized: false,
        root: null,
        grid: null,
        rowsById: {},
        selectedQuestId: 0,
        selectedQuest: null,
        detailPanel: null,
        detailEmpty: null,
        detailActions: [],
        debounceTimers: {},
        narrativeStateLookup: {},
        tagsCatalog: [],

        init: function () {
            if (this.initialized) { return this; }
            this.root = document.querySelector('#admin-page [data-admin-page="quests"]');
            if (!this.root) { return this; }

            this.detailPanel = document.getElementById('admin-quest-detail');
            this.detailEmpty = document.getElementById('admin-quest-detail-empty');
            this.detailActions = Array.prototype.slice.call(this.root.querySelectorAll('[data-role="admin-quests-detail-action"]'));

            if (!document.getElementById('grid-admin-quests') || !document.getElementById('admin-quest-form')) {
                return this;
            }

            this.bindEvents();
            this.bindAutocompleteInputs();
            this.loadTagCatalog();
            this.initGrid();
            this.setDetailActionsEnabled(false);
            this.loadGrid();
            this.setScopeInputState();
            this.setInstanceAssigneeInputState();
            this.applyConditionTypePreset();
            this.updateConditionGuidedUi();
            this.syncOutcomeLogTypeField();
            this.syncLogsTypeFilter();
            this.syncRewardTypeField();

            this.initialized = true;
            return this;
        },

        bindEvents: function () {
            var self = this;
            var filtersForm = document.getElementById('admin-quests-filters');
            var statusFilter = document.getElementById('admin-quests-filter-status');
            var scopeFilter = document.getElementById('admin-quests-filter-scope');
            var tagFilter = document.getElementById('admin-quests-filter-tag');
            var searchFilter = document.getElementById('admin-quests-filter-search');
            var scopeTypeSelect = document.querySelector('#admin-quest-form [data-role="admin-quest-scope-type"]');
            var linkEntityType = document.querySelector('#admin-quest-link-form [data-role="admin-quest-link-entity-type"]');
            var conditionStepFilter = document.getElementById('admin-quest-condition-step-filter');
            var conditionTypeSelect = document.querySelector('#admin-quest-condition-form [name="condition_type"]');
            var instanceAssigneeType = document.querySelector('#admin-quest-instance-form [data-role="admin-quest-instance-assignee-type"]');
            var conditionFieldKeyPreset = document.querySelector('#admin-quest-condition-form [data-role="admin-quest-condition-field-key-preset"]');
            var conditionFieldValuePreset = document.querySelector('#admin-quest-condition-form [data-role="admin-quest-condition-value-preset"]');
            var conditionFieldKeyCustom = document.querySelector('#admin-quest-condition-form [name="field_key_custom"]');
            var conditionFieldValueCustom = document.querySelector('#admin-quest-condition-form [name="field_value_custom"]');
            var outcomeLogTypePreset = document.querySelector('#admin-quest-outcome-form [data-role="admin-quest-outcome-log-type-preset"]');
            var outcomeLogTypeCustom = document.querySelector('#admin-quest-outcome-form [name="log_type_custom"]');
            var logsTypePreset = document.querySelector('#admin-quest-logs-modal [data-role="admin-quest-logs-filter-type-preset"]');
            var logsTypeCustom = document.getElementById('admin-quest-logs-filter-type-custom');
            var rewardTypeSelect = document.querySelector('#admin-quest-reward-form [name="reward_type"]');

            if (filtersForm) {
                filtersForm.addEventListener('submit', function (event) {
                    event.preventDefault();
                    self.loadGrid();
                });
            }
            if (statusFilter) { statusFilter.addEventListener('change', function () { self.loadGrid(); }); }
            if (scopeFilter) { scopeFilter.addEventListener('change', function () { self.loadGrid(); }); }
            if (tagFilter) { tagFilter.addEventListener('change', function () { self.loadGrid(); }); }
            if (searchFilter) {
                searchFilter.addEventListener('input', function () {
                    self.debounce('quest-search', 220, function () { self.loadGrid(); });
                });
            }
            if (scopeTypeSelect) {
                scopeTypeSelect.addEventListener('change', function () {
                    self.resetSuggestionField('#admin-quest-form', 'scope_id', '[data-role="admin-quest-scope-label"]', '[data-role="admin-quest-scope-suggestions"]');
                    self.setScopeInputState();
                });
            }
            if (linkEntityType) {
                linkEntityType.addEventListener('change', function () {
                    self.resetSuggestionField('#admin-quest-link-form', 'entity_id', '[data-role="admin-quest-link-entity-label"]', '[data-role="admin-quest-link-entity-suggestions"]');
                });
            }
            if (conditionStepFilter) {
                conditionStepFilter.addEventListener('change', function () { self.loadConditions(); });
            }
            if (conditionTypeSelect) {
                conditionTypeSelect.addEventListener('change', function () { self.applyConditionTypePreset(); });
            }
            if (instanceAssigneeType) {
                instanceAssigneeType.addEventListener('change', function () {
                    self.resetSuggestionField('#admin-quest-instance-form', 'assignee_id', '[data-role="admin-quest-instance-assignee-label"]', '[data-role="admin-quest-instance-assignee-suggestions"]');
                    self.setInstanceAssigneeInputState();
                });
            }
            if (conditionFieldKeyPreset) {
                conditionFieldKeyPreset.addEventListener('change', function () { self.updateConditionGuidedUi(); });
            }
            if (conditionFieldValuePreset) {
                conditionFieldValuePreset.addEventListener('change', function () { self.updateConditionGuidedUi(); });
            }
            if (conditionFieldKeyCustom) {
                conditionFieldKeyCustom.addEventListener('input', function () { self.syncConditionPayloadInputs(); });
            }
            if (conditionFieldValueCustom) {
                conditionFieldValueCustom.addEventListener('input', function () { self.syncConditionPayloadInputs(); });
            }
            if (outcomeLogTypePreset) {
                outcomeLogTypePreset.addEventListener('change', function () { self.syncOutcomeLogTypeField(); });
            }
            if (outcomeLogTypeCustom) {
                outcomeLogTypeCustom.addEventListener('input', function () { self.syncOutcomeLogTypeField(); });
            }
            if (logsTypePreset) {
                logsTypePreset.addEventListener('change', function () { self.syncLogsTypeFilter(); });
            }
            if (logsTypeCustom) {
                logsTypeCustom.addEventListener('input', function () { self.syncLogsTypeFilter(); });
            }
            if (rewardTypeSelect) {
                rewardTypeSelect.addEventListener('change', function () { self.syncRewardTypeField(); });
            }

            var questTagsFilterInput = document.querySelector('#admin-quest-form [data-role="admin-quest-tags-filter"]');
            if (questTagsFilterInput) {
                questTagsFilterInput.addEventListener('input', function () {
                    var current = self.collectQuestModalTagIds();
                    self.renderQuestModalTagCheckboxes(current);
                });
            }

            this.root.addEventListener('click', function (event) {
                if (self.handleSuggestionClick(event)) { return; }

                var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
                if (!trigger) { return; }
                var action = String(trigger.getAttribute('data-action') || '');
                if (!action) { return; }
                event.preventDefault();

                if (action === 'admin-quests-reload') { self.loadGrid(); return; }
                if (action === 'admin-quests-filters-reset') {
                    if (statusFilter) { statusFilter.value = ''; }
                    if (scopeFilter) { scopeFilter.value = ''; }
                    if (tagFilter) { tagFilter.value = ''; }
                    if (searchFilter) { searchFilter.value = ''; }
                    self.loadGrid();
                    return;
                }
                if (action === 'admin-quests-maintenance') { self.runMaintenance(); return; }
                if (action === 'admin-quest-create') { self.openQuestModal(null); return; }
                if (action === 'admin-quest-save') { self.saveQuest(); return; }

                if (action === 'admin-quest-view') { self.openQuestDetailModal(self.findRowByTrigger(trigger)); return; }
                if (action === 'admin-quest-edit') { self.openQuestModal(self.findRowByTrigger(trigger)); return; }
                if (action === 'admin-quest-publish') { self.setQuestStatus(self.findRowByTrigger(trigger), 'published'); return; }
                if (action === 'admin-quest-archive') { self.setQuestStatus(self.findRowByTrigger(trigger), 'archived'); return; }
                if (action === 'admin-quest-delete') { self.deleteQuest(self.findRowByTrigger(trigger)); return; }

                if (action === 'admin-quests-open-steps') { self.openStepsModal(); return; }
                if (action === 'admin-quests-open-conditions') { self.openConditionsModal(); return; }
                if (action === 'admin-quests-open-outcomes') { self.openOutcomesModal(); return; }
                if (action === 'admin-quests-open-links') { self.openLinksModal(); return; }
                if (action === 'admin-quests-open-instances') { self.openInstancesModal(); return; }
                if (action === 'admin-quests-open-closures') { self.openClosuresModal(); return; }
                if (action === 'admin-quests-open-rewards') { self.openRewardsModal(); return; }
                if (action === 'admin-quests-open-logs') { self.openLogsModal(); return; }

                if (action === 'admin-quest-steps-reload') { self.loadSteps(); return; }
                if (action === 'admin-quest-step-reset') { self.resetStepForm(); return; }
                if (action === 'admin-quest-step-save') { self.saveStep(); return; }
                if (action === 'admin-quest-step-reorder') { self.reorderSteps(); return; }
                if (action === 'admin-quest-step-edit') { self.editStep(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); return; }
                if (action === 'admin-quest-step-delete') { self.deleteStep(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); return; }

                if (action === 'admin-quest-conditions-reload') { self.loadConditions(); return; }
                if (action === 'admin-quest-condition-reset') { self.resetConditionForm(); return; }
                if (action === 'admin-quest-condition-save') { self.saveCondition(); return; }
                if (action === 'admin-quest-condition-edit') { self.editCondition(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); return; }
                if (action === 'admin-quest-condition-delete') { self.deleteCondition(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); return; }

                if (action === 'admin-quest-outcomes-reload') { self.loadOutcomes(); return; }
                if (action === 'admin-quest-outcome-reset') { self.resetOutcomeForm(); return; }
                if (action === 'admin-quest-outcome-save') { self.saveOutcome(); return; }
                if (action === 'admin-quest-outcome-edit') { self.editOutcome(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); return; }
                if (action === 'admin-quest-outcome-delete') { self.deleteOutcome(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); return; }

                if (action === 'admin-quest-links-reload') { self.loadLinks(); return; }
                if (action === 'admin-quest-link-save') { self.saveLink(); return; }
                if (action === 'admin-quest-link-edit') { self.editLink(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); return; }
                if (action === 'admin-quest-link-delete') { self.deleteLink(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); return; }

                if (action === 'admin-quest-instances-reload') { self.loadInstances(); return; }
                if (action === 'admin-quest-instance-assign') { self.assignInstance(); return; }
                if (action === 'admin-quest-instance-select') { self.selectInstance(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); return; }
                if (action === 'admin-quest-instance-set-status') { self.setInstanceStatus(); return; }
                if (action === 'admin-quest-instance-set-step') { self.setInstanceStepStatus(); return; }

                if (action === 'admin-quest-closures-reload') { self.loadClosures(); return; }
                if (action === 'admin-quest-closure-load') { self.loadClosureDetail(); return; }
                if (action === 'admin-quest-closure-save') { self.saveClosure(); return; }
                if (action === 'admin-quest-closure-select') { self.selectClosureInstance(parseInt(trigger.getAttribute('data-instance-id') || '0', 10) || 0); return; }

                if (action === 'admin-quest-rewards-reload') { self.loadRewards(); return; }
                if (action === 'admin-quest-reward-assign') { self.assignReward(); return; }
                if (action === 'admin-quest-reward-remove') { self.removeReward(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); return; }

                if (action === 'admin-quest-logs-reload') { self.loadLogs(); return; }
            });

            document.addEventListener('click', function (event) {
                if (!event.target || !event.target.closest) { return; }
                self.hideOutsideSuggestions(event.target);
            });
        },

        initGrid: function () {
            var self = this;
            this.grid = new Datagrid('grid-admin-quests', {
                name: 'AdminQuests',
                autoindex: 'id',
                orderable: true,
                thead: true,
                handler: { url: '/admin/quests/definitions/list', action: 'list' },
                nav: { display: 'bottom', urlupdate: 0, results: 20, page: 1 },
                onGetDataSuccess: function (response) { self.storeRows(self.parseRows(response)); },
                onGetDataError: function () { self.storeRows([]); },
                columns: [
                    { label: 'ID', field: 'id', sortable: true },
                    {
                        label: 'Titolo', field: 'title', sortable: true, style: { textAlign: 'left' },
                        format: function (row) {
                            var title = self.escapeHtml(row.title || ('Quest #' + row.id));
                            var slug = self.escapeHtml(row.slug || '');
                            return '<div><b>' + title + '</b></div><div class="small text-muted">' + slug + '</div>';
                        }
                    },
                    { label: 'Tipo', field: 'quest_type', sortable: true, format: function (row) { return self.escapeHtml(self.questTypeLabel(row.quest_type)); } },
                    {
                        label: 'Intensita',
                        field: 'intensity_level',
                        sortable: true,
                        format: function (row) {
                            var level = String(row.intensity_level || 'STANDARD');
                            var visibility = String(row.intensity_visibility || 'visible');
                            return self.intensityBadge(level) + (visibility === 'hidden' ? ' <span class="badge text-bg-dark">Nascosta</span>' : '');
                        }
                    },
                    {
                        label: 'Tag',
                        field: 'narrative_tags',
                        sortable: false,
                        format: function (row) {
                            var tags = Array.isArray(row.narrative_tags) ? row.narrative_tags : [];
                            if (!tags.length) { return '<span class="text-muted small">-</span>'; }
                            return tags.slice(0, 3).map(function (tag) {
                                return '<span class="badge text-bg-secondary me-1">' + self.escapeHtml(tag.label || tag.slug || 'Tag') + '</span>';
                            }).join('');
                        }
                    },
                    { label: 'Stato', field: 'status', sortable: true, format: function (row) { return '<span class="badge ' + self.statusBadge(row.status) + '">' + self.escapeHtml(self.statusLabel(row.status)) + '</span>'; } },
                    { label: 'Ambito', field: 'scope_type', sortable: true, format: function (row) { return self.escapeHtml(self.scopeLabel(row.scope_type) + ((parseInt(row.scope_id || '0', 10) || 0) > 0 ? (' #' + row.scope_id) : '')); } },
                    { label: 'Step', field: 'steps_count', sortable: true, format: function (row) { return String(parseInt(row.steps_count || '0', 10) || 0); } },
                    { label: 'Istanze', field: 'active_instances', sortable: true, format: function (row) { return String(parseInt(row.active_instances || '0', 10) || 0); } },
                    {
                        label: 'Azioni', sortable: false, style: { textAlign: 'left' },
                        format: function (row) {
                            var id = parseInt(row.id || '0', 10) || 0;
                            if (id > 0) { self.rowsById[id] = row; }
                            return '<div class="d-flex flex-wrap gap-1">'
                                + '<button class="btn btn-sm btn-outline-secondary" data-action="admin-quest-view" data-id="' + id + '">Dettaglio</button>'
                                + '<button class="btn btn-sm btn-outline-primary" data-action="admin-quest-edit" data-id="' + id + '">Modifica</button>'
                                + '<button class="btn btn-sm btn-outline-success" data-action="admin-quest-publish" data-id="' + id + '">Pubblica</button>'
                                + '<button class="btn btn-sm btn-outline-warning" data-action="admin-quest-archive" data-id="' + id + '">Archivia</button>'
                                + '<button class="btn btn-sm btn-outline-danger" data-action="admin-quest-delete" data-id="' + id + '">Elimina</button>'
                                + '</div>';
                        }
                    }
                ]
            });
        },

        loadGrid: function () {
            if (!this.grid) { return; }
            this.rowsById = {};
            var statusNode = document.getElementById('admin-quests-filter-status');
            var scopeNode = document.getElementById('admin-quests-filter-scope');
            var tagNode = document.getElementById('admin-quests-filter-tag');
            var searchNode = document.getElementById('admin-quests-filter-search');
            this.grid.loadData({
                status: statusNode ? (statusNode.value || '') : '',
                scope_type: scopeNode ? (scopeNode.value || '') : '',
                tag_ids: tagNode && tagNode.value ? [parseInt(tagNode.value || '0', 10) || 0] : [],
                search: searchNode ? (searchNode.value || '') : ''
            }, 20, 1, 'sort_order|ASC');
        },

        loadTagCatalog: function () {
            var self = this;
            var tagNode = document.getElementById('admin-quests-filter-tag');
            if (!tagNode) { return; }
            this.request('/list/narrative-tags', { entity_type: 'quest_definition' }, function (response) {
                var rows = self.parseRows(response);
                self.tagsCatalog = Array.isArray(rows) ? rows : [];
                self.renderTagFilterOptions();
            });
        },

        renderTagFilterOptions: function () {
            var tagNode = document.getElementById('admin-quests-filter-tag');
            if (!tagNode) { return; }
            var current = String(tagNode.value || '');
            var html = '<option value="">Tutti i tag</option>';
            for (var i = 0; i < this.tagsCatalog.length; i += 1) {
                var row = this.tagsCatalog[i] || {};
                var id = parseInt(row.id || '0', 10) || 0;
                if (id <= 0) { continue; }
                html += '<option value="' + id + '">' + this.escapeHtml(row.label || row.slug || ('Tag #' + id)) + '</option>';
            }
            tagNode.innerHTML = html;
            if (current !== '') {
                tagNode.value = current;
            }
        },

        storeRows: function (rows) {
            this.rowsById = {};
            for (var i = 0; i < rows.length; i += 1) {
                var id = parseInt(rows[i].id || '0', 10) || 0;
                if (id > 0) { this.rowsById[id] = rows[i]; }
            }
        },

        selectQuest: function (row) {
            if (!row || !row.id) { return; }
            this.selectedQuestId = parseInt(row.id || '0', 10) || 0;
            this.selectedQuest = row;
            this.renderDetail(row);
            this.setDetailActionsEnabled(this.selectedQuestId > 0);
        },

        openQuestDetailModal: function (row) {
            this.selectQuest(row);
            if (this.selectedQuestId > 0) {
                this.showModal('admin-quest-detail-modal');
            }
        },

        renderDetail: function (row) {
            if (!this.detailPanel || !this.detailEmpty) { return; }
            this.detailPanel.innerHTML = '<div class="fw-bold mb-2">' + this.escapeHtml(row.title || ('Quest #' + row.id)) + '</div>'
                + '<div class="small text-muted mb-1">Slug: <b>' + this.escapeHtml(row.slug || '-') + '</b></div>'
                + '<div class="small text-muted mb-1">Stato: <b>' + this.escapeHtml(this.statusLabel(row.status)) + '</b> - Visibilita: <b>' + this.escapeHtml(this.visibilityLabel(row.visibility)) + '</b></div>'
                + '<div class="small text-muted mb-1">Intensita: <b>' + this.escapeHtml(this.intensityLabel(row.intensity_level || 'STANDARD')) + '</b> - Mostra ai player: <b>' + this.escapeHtml(this.intensityVisibilityLabel(row.intensity_visibility || 'visible')) + '</b></div>'
                + '<div class="small text-muted mb-1">Ambito: <b>' + this.escapeHtml(this.scopeLabel(row.scope_type) + ((parseInt(row.scope_id || '0', 10) || 0) > 0 ? (' #' + row.scope_id) : '')) + '</b></div>'
                + (Array.isArray(row.narrative_tags) && row.narrative_tags.length
                    ? ('<div class="small text-muted mb-1">Tag: '
                        + row.narrative_tags.map(function (tag) {
                            return '<span class="badge text-bg-secondary me-1">' + AdminQuests.escapeHtml(tag.label || tag.slug || 'Tag') + '</span>';
                        }).join('')
                        + '</div>')
                    : '')
                + '<div class="small text-muted mb-2">Step: <b>' + (parseInt(row.steps_count || '0', 10) || 0) + '</b> - Istanze attive: <b>' + (parseInt(row.active_instances || '0', 10) || 0) + '</b></div>'
                + (row.summary ? '<p class="small mb-2">' + this.escapeHtml(row.summary) + '</p>' : '')
                + (row.description ? '<p class="small mb-0">' + this.escapeHtml(row.description) + '</p>' : '');
            this.detailEmpty.classList.add('d-none');
            this.detailPanel.classList.remove('d-none');
        },

        setDetailActionsEnabled: function (enabled) {
            for (var i = 0; i < this.detailActions.length; i += 1) {
                this.detailActions[i].disabled = !enabled;
            }
        },

        openQuestModal: function (row) {
            var form = document.getElementById('admin-quest-form');
            if (!form) { return; }
            form.reset();
            form.elements.id.value = '0';
            form.elements.scope_id.value = '0';
            form.elements.status.value = 'draft';
            form.elements.visibility.value = 'public';
            form.elements.scope_type.value = 'world';
            form.elements.intensity_level.value = 'STANDARD';
            form.elements.intensity_visibility.value = 'visible';
            var scopeLabel = form.querySelector('[data-role="admin-quest-scope-label"]');
            if (scopeLabel) { scopeLabel.value = ''; }
            this.setScopeInputState();

            var questTagsFilterInput = form.querySelector('[data-role="admin-quest-tags-filter"]');
            if (questTagsFilterInput) { questTagsFilterInput.value = ''; }
            this.renderQuestModalTagCheckboxes([]);

            if (row && row.id) {
                form.elements.id.value = String(parseInt(row.id || '0', 10) || 0);
                form.elements.slug.value = String(row.slug || '');
                form.elements.title.value = String(row.title || '');
                this.setSelectValue(form.elements.quest_type, String(row.quest_type || 'personal'), 'Tipo personalizzato: ');
                form.elements.status.value = String(row.status || 'draft');
                form.elements.visibility.value = String(row.visibility || 'public');
                form.elements.availability_type.value = String(row.availability_type || 'manual_join');
                form.elements.scope_type.value = String(row.scope_type || 'world');
                form.elements.intensity_level.value = String(row.intensity_level || 'STANDARD');
                form.elements.intensity_visibility.value = String(row.intensity_visibility || 'visible');
                form.elements.scope_id.value = String(parseInt(row.scope_id || '0', 10) || 0);
                form.elements.sort_order.value = String(parseInt(row.sort_order || '0', 10) || 0);
                form.elements.summary.value = String(row.summary || '');
                form.elements.description.value = String(row.description || '');
                if (scopeLabel) {
                    scopeLabel.value = (parseInt(form.elements.scope_id.value || '0', 10) || 0) > 0
                        ? (this.scopeLabel(form.elements.scope_type.value) + ' #' + form.elements.scope_id.value)
                        : '';
                }
                this.setScopeInputState();
                this.loadQuestModalTags(parseInt(row.id || '0', 10) || 0);
            }

            this.showModal('admin-quest-modal');
        },

        saveQuest: function () {
            var form = document.getElementById('admin-quest-form');
            if (!form) { return; }
            var payload = {
                id: parseInt(form.elements.id.value || '0', 10) || 0,
                slug: String(form.elements.slug.value || '').trim(),
                title: String(form.elements.title.value || '').trim(),
                quest_type: String(form.elements.quest_type.value || 'personal').trim(),
                status: String(form.elements.status.value || 'draft').trim(),
                visibility: String(form.elements.visibility.value || 'public').trim(),
                availability_type: String(form.elements.availability_type.value || 'manual_join').trim(),
                scope_type: String(form.elements.scope_type.value || 'world').trim(),
                intensity_level: String(form.elements.intensity_level.value || 'STANDARD').trim().toUpperCase(),
                intensity_visibility: String(form.elements.intensity_visibility.value || 'visible').trim().toLowerCase(),
                scope_id: parseInt(form.elements.scope_id.value || '0', 10) || 0,
                sort_order: parseInt(form.elements.sort_order.value || '0', 10) || 0,
                summary: String(form.elements.summary.value || '').trim(),
                description: String(form.elements.description.value || '').trim()
            };
            if (!payload.slug || !payload.title) {
                Toast.show({ body: 'Slug e titolo sono obbligatori.', type: 'warning' });
                return;
            }
            if (payload.scope_type === 'world') { payload.scope_id = 0; }
            var self = this;
            var tagIds = this.collectQuestModalTagIds();
            this.request(payload.id > 0 ? '/admin/quests/definitions/update' : '/admin/quests/definitions/create', payload, function (res) {
                var ds = res && res.dataset ? res.dataset : null;
                var savedId = parseInt((ds ? ds.id : null) || payload.id || 0, 10) || 0;
                var finish = function () {
                    self.hideModal('admin-quest-modal');
                    self.loadGrid();
                    Toast.show({ body: payload.id > 0 ? 'Quest aggiornata.' : 'Quest creata.', type: 'success' });
                    if (ds && ds.id) { self.selectQuest(ds); }
                };
                self.syncQuestModalTags(savedId, tagIds, finish);
            });
        },

        setQuestStatus: function (row, status) {
            if (!row || !row.id) { return; }
            var url = status === 'published' ? '/admin/quests/definitions/publish' : '/admin/quests/definitions/archive';
            var self = this;
            this.request(url, { quest_definition_id: row.id }, function () {
                Toast.show({ body: 'Stato quest aggiornato.', type: 'success' });
                self.loadGrid();
            });
        },

        deleteQuest: function (row) {
            if (!row || !row.id) { return; }
            var self = this;
            Dialog('danger', {
                title: 'Elimina quest',
                body: '<p>Confermi eliminazione di <b>' + self.escapeHtml(row.title || ('Quest #' + row.id)) + '</b>?</p>'
            }, function () {
                self.hideConfirm();
                self.request('/admin/quests/definitions/delete', { quest_definition_id: row.id }, function () {
                    Toast.show({ body: 'Quest eliminata.', type: 'success' });
                    self.loadGrid();
                    if (self.selectedQuestId === (parseInt(row.id, 10) || 0)) {
                        self.selectedQuestId = 0;
                        self.selectedQuest = null;
                        if (self.detailPanel) { self.detailPanel.classList.add('d-none'); self.detailPanel.innerHTML = ''; }
                        if (self.detailEmpty) { self.detailEmpty.classList.remove('d-none'); }
                        self.setDetailActionsEnabled(false);
                    }
                });
            }).show();
        },

        runMaintenance: function () {
            var self = this;
            this.request('/admin/quests/maintenance/run', { force: 1 }, function (res) {
                var dataset = res && res.dataset ? res.dataset : {};
                var count = Array.isArray(dataset.expired_ids) ? dataset.expired_ids.length : 0;
                Toast.show({ body: 'Manutenzione completata. Istanze scadute: ' + count + '.', type: 'success' });
                self.loadGrid();
                if (self.selectedQuestId > 0) {
                    self.loadInstances();
                    self.loadLogs();
                }
            });
        },
        openStepsModal: function () {
            if (!this.assertQuestSelected()) { return; }
            this.resetStepForm();
            this.showModal('admin-quest-steps-modal');
            this.loadSteps();
        },

        loadSteps: function () {
            var self = this;
            this.request('/admin/quests/steps/list', { quest_definition_id: this.selectedQuestId }, function (res) {
                var rows = self.parseRows(res);
                self.renderSteps(rows);
                self.fillStepSelectors(rows);
            });
        },

        renderSteps: function (rows) {
            var box = document.getElementById('admin-quest-steps-list');
            if (!box) { return; }
            if (!rows.length) {
                box.innerHTML = '<div class="small text-muted">Nessuno step configurato.</div>';
                return;
            }
            var self = this;
            box.innerHTML = rows.map(function (row) {
                var id = parseInt(row.id || '0', 10) || 0;
                var order = parseInt(row.order_index || '0', 10) || 0;
                return '<div class="list-group-item">'
                    + '<div class="d-flex justify-content-between align-items-start gap-2">'
                    + '<div><div class="fw-bold small">' + self.escapeHtml(row.title || ('Step #' + id)) + '</div>'
                    + '<div class="small text-muted">' + self.escapeHtml(row.step_key || '-') + ' - ' + self.escapeHtml(self.stepTypeLabel(row.step_type)) + '</div></div>'
                    + '<div class="d-flex align-items-center gap-1">'
                    + '<input type="number" class="form-control form-control-sm" style="width:78px;" data-role="admin-quest-step-order" data-step-id="' + id + '" value="' + order + '">'
                    + '<button class="btn btn-sm btn-outline-secondary" data-action="admin-quest-step-edit" data-id="' + id + '">Modifica</button>'
                    + '<button class="btn btn-sm btn-outline-danger" data-action="admin-quest-step-delete" data-id="' + id + '">Elimina</button>'
                    + '</div></div></div>';
            }).join('');
        },

        fillStepSelectors: function (steps) {
            var selectA = document.querySelector('#admin-quest-condition-form [data-role="admin-quest-condition-step-id"]');
            var filter = document.getElementById('admin-quest-condition-step-filter');
            if (selectA) {
                selectA.innerHTML = '<option value="0">Condizione di quest</option>';
                for (var i = 0; i < steps.length; i += 1) {
                    var row = steps[i];
                    var id = parseInt(row.id || '0', 10) || 0;
                    if (id <= 0) { continue; }
                    selectA.innerHTML += '<option value="' + id + '">' + this.escapeHtml(row.title || ('Step #' + id)) + '</option>';
                }
            }
            if (filter) {
                filter.innerHTML = '<option value="0">Tutti gli step</option>';
                for (var j = 0; j < steps.length; j += 1) {
                    var rw = steps[j];
                    var sid = parseInt(rw.id || '0', 10) || 0;
                    if (sid <= 0) { continue; }
                    filter.innerHTML += '<option value="' + sid + '">' + this.escapeHtml(rw.title || ('Step #' + sid)) + '</option>';
                }
            }
        },

        resetStepForm: function () {
            var form = document.getElementById('admin-quest-step-form');
            if (!form) { return; }
            form.reset();
            form.elements.step_id.value = '0';
            form.elements.order_index.value = '0';
        },

        editStep: function (stepId) {
            stepId = parseInt(stepId || '0', 10) || 0;
            if (stepId <= 0) { return; }
            var self = this;
            this.request('/admin/quests/steps/list', { quest_definition_id: this.selectedQuestId }, function (res) {
                var rows = self.parseRows(res);
                var item = null;
                for (var i = 0; i < rows.length; i += 1) {
                    if ((parseInt(rows[i].id || '0', 10) || 0) === stepId) { item = rows[i]; break; }
                }
                if (!item) { return; }
                var form = document.getElementById('admin-quest-step-form');
                if (!form) { return; }
                form.elements.step_id.value = String(stepId);
                form.elements.step_key.value = String(item.step_key || '');
                form.elements.title.value = String(item.title || '');
                form.elements.step_type.value = String(item.step_type || 'action');
                form.elements.order_index.value = String(parseInt(item.order_index || '0', 10) || 0);
                form.elements.is_optional.value = String(parseInt(item.is_optional || '0', 10) === 1 ? 1 : 0);
                form.elements.description.value = String(item.description || '');
            });
        },

        saveStep: function () {
            var form = document.getElementById('admin-quest-step-form');
            if (!form) { return; }
            var payload = {
                quest_definition_id: this.selectedQuestId,
                step_id: parseInt(form.elements.step_id.value || '0', 10) || 0,
                step_key: String(form.elements.step_key.value || '').trim(),
                title: String(form.elements.title.value || '').trim(),
                step_type: String(form.elements.step_type.value || 'action').trim(),
                order_index: parseInt(form.elements.order_index.value || '0', 10) || 0,
                is_optional: parseInt(form.elements.is_optional.value || '0', 10) === 1 ? 1 : 0,
                description: String(form.elements.description.value || '').trim()
            };
            if (!payload.step_key || !payload.title) {
                Toast.show({ body: 'Identificatore step e titolo sono obbligatori.', type: 'warning' });
                return;
            }
            var self = this;
            this.request('/admin/quests/steps/upsert', payload, function () {
                Toast.show({ body: 'Step salvato.', type: 'success' });
                self.loadSteps();
                self.loadGrid();
                self.resetStepForm();
            });
        },

        reorderSteps: function () {
            var nodes = this.root.querySelectorAll('[data-role="admin-quest-step-order"]');
            var items = [];
            for (var i = 0; i < nodes.length; i += 1) {
                var id = parseInt(nodes[i].getAttribute('data-step-id') || '0', 10) || 0;
                if (id <= 0) { continue; }
                items.push({ id: id, sort_order: parseInt(nodes[i].value || '0', 10) || 0 });
            }
            var self = this;
            this.request('/admin/quests/steps/reorder', { quest_definition_id: this.selectedQuestId, items: items }, function () {
                Toast.show({ body: 'Ordine step aggiornato.', type: 'success' });
                self.loadSteps();
            });
        },

        deleteStep: function (stepId) {
            stepId = parseInt(stepId || '0', 10) || 0;
            if (stepId <= 0) { return; }
            var self = this;
            this.request('/admin/quests/steps/delete', { quest_definition_id: this.selectedQuestId, step_id: stepId }, function () {
                Toast.show({ body: 'Step eliminato.', type: 'success' });
                self.loadSteps();
                self.loadGrid();
            });
        },

        openConditionsModal: function () {
            if (!this.assertQuestSelected()) { return; }
            this.resetConditionForm();
            this.showModal('admin-quest-conditions-modal');
            this.loadSteps();
            this.loadConditions();
        },

        loadConditions: function () {
            var self = this;
            var stepFilter = document.getElementById('admin-quest-condition-step-filter');
            this.request('/admin/quests/conditions/list', {
                quest_definition_id: this.selectedQuestId,
                quest_step_definition_id: stepFilter ? (parseInt(stepFilter.value || '0', 10) || 0) : 0
            }, function (res) {
                var rows = self.parseRows(res);
                var box = document.getElementById('admin-quest-conditions-list');
                if (!box) { return; }
                if (!rows.length) {
                    box.innerHTML = '<div class="small text-muted">Nessuna condizione.</div>';
                    return;
                }
                box.innerHTML = rows.map(function (row) {
                    var id = parseInt(row.id || '0', 10) || 0;
                    var payload = row.condition_payload && typeof row.condition_payload === 'object' ? JSON.stringify(row.condition_payload) : '{}';
                    return '<div class="list-group-item d-flex justify-content-between align-items-start gap-2">'
                        + '<div><div class="small"><b>' + self.escapeHtml(self.conditionTypeLabel(row.condition_type)) + '</b> - ' + self.escapeHtml(self.operatorLabel(row.operator)) + '</div>'
                        + '<div class="small text-muted">Step #' + (parseInt(row.quest_step_definition_id || '0', 10) || 0) + ' - ' + self.escapeHtml(payload) + '</div></div>'
                        + '<div class="d-flex gap-1"><button class="btn btn-sm btn-outline-secondary" data-action="admin-quest-condition-edit" data-id="' + id + '">Modifica</button>'
                        + '<button class="btn btn-sm btn-outline-danger" data-action="admin-quest-condition-delete" data-id="' + id + '">Elimina</button></div></div>';
                }).join('');
            });
        },

        resetConditionForm: function () {
            var form = document.getElementById('admin-quest-condition-form');
            if (!form) { return; }
            form.reset();
            form.elements.condition_id.value = '0';
            form.elements.quest_step_definition_id.value = '0';
            form.elements.is_active.value = '1';
            if (form.elements.field_key) { form.elements.field_key.value = ''; }
            if (form.elements.field_value) { form.elements.field_value.value = ''; }
            this.applyConditionTypePreset();
            this.updateConditionGuidedUi();
        },

        saveCondition: function () {
            var form = document.getElementById('admin-quest-condition-form');
            if (!form) { return; }
            this.syncConditionPayloadInputs(form);
            var key = String(form.elements.field_key.value || '').trim();
            var rawValue = String(form.elements.field_value.value || '').trim();
            var operator = String(form.elements.operator.value || 'eq');
            var value = rawValue;
            if (operator === 'in' || operator === 'not_in') {
                value = rawValue ? rawValue.split(',').map(function (x) { return String(x || '').trim(); }).filter(function (x) { return x !== ''; }) : [];
            }
            var payload = {
                condition_id: parseInt(form.elements.condition_id.value || '0', 10) || 0,
                quest_definition_id: this.selectedQuestId,
                quest_step_definition_id: parseInt(form.elements.quest_step_definition_id.value || '0', 10) || 0,
                condition_type: String(form.elements.condition_type.value || '').trim(),
                operator: operator,
                evaluation_mode: String(form.elements.evaluation_mode.value || 'all_required').trim(),
                is_active: parseInt(form.elements.is_active.value || '1', 10) === 1 ? 1 : 0,
                condition_payload: key ? { field: key, value: value } : {}
            };
            if (!payload.condition_type) {
                Toast.show({ body: 'Tipo trigger obbligatorio.', type: 'warning' });
                return;
            }
            var self = this;
            this.request('/admin/quests/conditions/upsert', payload, function () {
                Toast.show({ body: 'Condizione salvata.', type: 'success' });
                self.loadConditions();
                self.resetConditionForm();
            });
        },

        editCondition: function (conditionId) {
            conditionId = parseInt(conditionId || '0', 10) || 0;
            if (conditionId <= 0) { return; }
            var self = this;
            this.request('/admin/quests/conditions/list', { quest_definition_id: this.selectedQuestId }, function (res) {
                var rows = self.parseRows(res);
                var row = null;
                for (var i = 0; i < rows.length; i += 1) {
                    if ((parseInt(rows[i].id || '0', 10) || 0) === conditionId) { row = rows[i]; break; }
                }
                if (!row) { return; }
                var form = document.getElementById('admin-quest-condition-form');
                if (!form) { return; }
                form.elements.condition_id.value = String(conditionId);
                form.elements.quest_step_definition_id.value = String(parseInt(row.quest_step_definition_id || '0', 10) || 0);
                form.elements.condition_type.value = String(row.condition_type || 'manual_staff');
                form.elements.operator.value = String(row.operator || 'eq');
                form.elements.evaluation_mode.value = String(row.evaluation_mode || 'all_required');
                form.elements.is_active.value = String(parseInt(row.is_active || '1', 10) === 1 ? 1 : 0);
                var pl = row.condition_payload && typeof row.condition_payload === 'object' ? row.condition_payload : {};
                var fieldKey = String(pl.field || '');
                var fieldValue = '';
                if (Array.isArray(pl.value)) {
                    fieldValue = pl.value.join(',');
                } else {
                    fieldValue = String(pl.value || '');
                }
                self.applyConditionGuidedValues(fieldKey, fieldValue);
            });
        },

        deleteCondition: function (conditionId) {
            conditionId = parseInt(conditionId || '0', 10) || 0;
            if (conditionId <= 0) { return; }
            var self = this;
            this.request('/admin/quests/conditions/delete', { condition_id: conditionId }, function () {
                Toast.show({ body: 'Condizione eliminata.', type: 'success' });
                self.loadConditions();
            });
        },

        applyConditionTypePreset: function () {
            var form = document.getElementById('admin-quest-condition-form');
            if (!form) { return; }
            var type = String(form.elements.condition_type ? form.elements.condition_type.value : '').trim();
            var keyPreset = form.elements.field_key_preset || null;
            var valuePreset = form.elements.field_value_preset || null;
            if (!keyPreset || !valuePreset) { return; }
            var map = {
                'manual_staff': { key: '', value: '' },
                'narrative.event.created': { key: 'event_type', value: 'quest_update' },
                'system_event.status_changed': { key: 'status', value: 'active' },
                'faction.membership.changed': { key: 'membership_action', value: 'joined' },
                'lifecycle.phase.entered': { key: 'phase', value: '' },
                'presence.position_changed': { key: 'location_id', value: '' },
                'quest_lifecycle': { key: 'status', value: 'active' }
            };
            var preset = map[type] || { key: '', value: '' };
            keyPreset.value = preset.key;
            valuePreset.value = preset.value;
            this.updateConditionGuidedUi();
        },

        updateConditionGuidedUi: function () {
            var form = document.getElementById('admin-quest-condition-form');
            if (!form) { return; }
            var fieldKeyPreset = String(form.elements.field_key_preset ? form.elements.field_key_preset.value : '').trim();
            var fieldValuePreset = String(form.elements.field_value_preset ? form.elements.field_value_preset.value : '').trim();
            var keyCustom = form.elements.field_key_custom || null;
            var valueCustom = form.elements.field_value_custom || null;

            if (keyCustom) {
                var showKeyCustom = fieldKeyPreset === 'custom';
                keyCustom.classList.toggle('d-none', !showKeyCustom);
                if (!showKeyCustom) { keyCustom.value = ''; }
            }
            if (valueCustom) {
                var showValueCustom = fieldValuePreset === 'custom';
                valueCustom.classList.toggle('d-none', !showValueCustom);
                if (!showValueCustom) { valueCustom.value = ''; }
            }
            this.syncConditionPayloadInputs(form);
        },

        syncConditionPayloadInputs: function (formNode) {
            var form = formNode || document.getElementById('admin-quest-condition-form');
            if (!form) { return; }
            var fieldKeyPreset = String(form.elements.field_key_preset ? form.elements.field_key_preset.value : '').trim();
            var fieldValuePreset = String(form.elements.field_value_preset ? form.elements.field_value_preset.value : '').trim();
            var keyCustom = String(form.elements.field_key_custom ? form.elements.field_key_custom.value : '').trim();
            var valueCustom = String(form.elements.field_value_custom ? form.elements.field_value_custom.value : '').trim();

            var key = fieldKeyPreset === 'custom' ? keyCustom : fieldKeyPreset;
            var value = fieldValuePreset === 'custom' ? valueCustom : fieldValuePreset;
            form.elements.field_key.value = key;
            form.elements.field_value.value = value;
        },

        applyConditionGuidedValues: function (fieldKey, fieldValue) {
            var form = document.getElementById('admin-quest-condition-form');
            if (!form) { return; }
            this.setSelectValue(form.elements.field_key_preset, String(fieldKey || ''), 'Campo personalizzato: ');
            this.setSelectValue(form.elements.field_value_preset, String(fieldValue || ''), 'Valore personalizzato: ');

            if (form.elements.field_key_custom) {
                form.elements.field_key_custom.value = String(form.elements.field_key_preset.value || '') === 'custom'
                    ? String(fieldKey || '')
                    : '';
            }
            if (form.elements.field_value_custom) {
                form.elements.field_value_custom.value = String(form.elements.field_value_preset.value || '') === 'custom'
                    ? String(fieldValue || '')
                    : '';
            }
            this.updateConditionGuidedUi();
        },

        openOutcomesModal: function () {
            if (!this.assertQuestSelected()) { return; }
            this.resetOutcomeForm();
            this.showModal('admin-quest-outcomes-modal');
            this.loadOutcomes();
        },

        loadOutcomes: function () {
            var self = this;
            this.request('/admin/quests/outcomes/list', { quest_definition_id: this.selectedQuestId }, function (res) {
                var rows = self.parseRows(res);
                var box = document.getElementById('admin-quest-outcomes-list');
                if (!box) { return; }
                if (!rows.length) {
                    box.innerHTML = '<div class="small text-muted">Nessun esito.</div>';
                    return;
                }
                box.innerHTML = rows.map(function (row) {
                    var id = parseInt(row.id || '0', 10) || 0;
                    return '<div class="list-group-item d-flex justify-content-between align-items-start gap-2">'
                        + '<div><div class="small"><b>' + self.escapeHtml(self.triggerTypeLabel(row.trigger_type)) + '</b> &rarr; ' + self.escapeHtml(self.outcomeTypeLabel(row.outcome_type)) + '</div>'
                        + '<div class="small text-muted">Ordine ' + (parseInt(row.sort_order || '0', 10) || 0) + ' - Vis. ' + self.escapeHtml(self.visibilityLabel(row.visibility)) + '</div></div>'
                        + '<div class="d-flex gap-1"><button class="btn btn-sm btn-outline-secondary" data-action="admin-quest-outcome-edit" data-id="' + id + '">Modifica</button>'
                        + '<button class="btn btn-sm btn-outline-danger" data-action="admin-quest-outcome-delete" data-id="' + id + '">Elimina</button></div></div>';
                }).join('');
            });
        },

        syncOutcomeLogTypeField: function (formNode) {
            var form = formNode || document.getElementById('admin-quest-outcome-form');
            if (!form) { return; }
            var preset = String(form.elements.log_type_preset ? form.elements.log_type_preset.value : '').trim();
            var customInput = form.elements.log_type_custom || null;
            var hidden = form.elements.log_type || null;
            var isCustom = preset === 'custom';
            if (customInput) {
                customInput.classList.toggle('d-none', !isCustom);
                if (!isCustom) { customInput.value = ''; }
            }
            if (hidden) {
                hidden.value = isCustom
                    ? String(customInput ? customInput.value : '').trim()
                    : preset;
            }
        },

        applyOutcomeLogTypeValue: function (formNode, value) {
            var form = formNode || document.getElementById('admin-quest-outcome-form');
            if (!form) { return; }
            this.setSelectValue(form.elements.log_type_preset, String(value || ''), 'Tipo log personalizzato: ');
            if (form.elements.log_type_custom) {
                form.elements.log_type_custom.value = String(form.elements.log_type_preset.value || '') === 'custom'
                    ? String(value || '')
                    : '';
            }
            this.syncOutcomeLogTypeField(form);
        },

        resetOutcomeForm: function () {
            var form = document.getElementById('admin-quest-outcome-form');
            if (!form) { return; }
            form.reset();
            form.elements.outcome_id.value = '0';
            form.elements.sort_order.value = '0';
            form.elements.target_quest_definition_id.value = '0';
            form.elements.state_id.value = '0';
            var input = form.querySelector('[data-role="admin-quest-outcome-target-label"]');
            if (input) { input.value = ''; }
            var stateInput = form.querySelector('[data-role="admin-quest-outcome-state-label"]');
            if (stateInput) { stateInput.value = ''; }
            this.hideSuggestions(form.querySelector('[data-role="admin-quest-outcome-target-suggestions"]'));
            this.hideSuggestions(form.querySelector('[data-role="admin-quest-outcome-state-suggestions"]'));
            this.syncOutcomeLogTypeField(form);
        },

        saveOutcome: function () {
            var form = document.getElementById('admin-quest-outcome-form');
            if (!form) { return; }
            this.syncOutcomeLogTypeField(form);
            if (String(form.elements.log_type_preset ? form.elements.log_type_preset.value : '') === 'custom'
                && String(form.elements.log_type.value || '').trim() === '') {
                Toast.show({ body: 'Inserisci un tipo log personalizzato oppure scegli un preset.', type: 'warning' });
                return;
            }
            var payload = {
                quest_definition_id: this.selectedQuestId,
                outcome_id: parseInt(form.elements.outcome_id.value || '0', 10) || 0,
                trigger_type: String(form.elements.trigger_type.value || 'step_completed').trim(),
                outcome_type: String(form.elements.outcome_type.value || 'log_progress').trim(),
                visibility: String(form.elements.visibility.value || 'hidden').trim(),
                requires_staff_confirmation: parseInt(form.elements.requires_staff_confirmation.value || '0', 10) === 1 ? 1 : 0,
                sort_order: parseInt(form.elements.sort_order.value || '0', 10) || 0,
                is_active: 1,
                outcome_payload: {
                    target_quest_definition_id: parseInt(form.elements.target_quest_definition_id.value || '0', 10) || 0,
                    narrative_event_type: String(form.elements.narrative_event_type.value || '').trim(),
                    narrative_event_title: String(form.elements.narrative_event_title.value || '').trim(),
                    message: String(form.elements.message.value || '').trim(),
                    state_id: parseInt(form.elements.state_id.value || '0', 10) || 0,
                    intensity: parseFloat(form.elements.intensity.value || '0') || 0,
                    duration_value: parseInt(form.elements.duration_value.value || '0', 10) || 0,
                    duration_unit: String(form.elements.duration_unit.value || '').trim(),
                    log_type: String(form.elements.log_type.value || '').trim()
                }
            };
            var self = this;
            this.request('/admin/quests/outcomes/upsert', payload, function () {
                Toast.show({ body: 'Esito salvato.', type: 'success' });
                self.loadOutcomes();
                self.resetOutcomeForm();
            });
        },

        editOutcome: function (outcomeId) {
            outcomeId = parseInt(outcomeId || '0', 10) || 0;
            if (outcomeId <= 0) { return; }
            var self = this;
            this.request('/admin/quests/outcomes/list', { quest_definition_id: this.selectedQuestId }, function (res) {
                var rows = self.parseRows(res);
                var row = null;
                for (var i = 0; i < rows.length; i += 1) {
                    if ((parseInt(rows[i].id || '0', 10) || 0) === outcomeId) { row = rows[i]; break; }
                }
                if (!row) { return; }
                var form = document.getElementById('admin-quest-outcome-form');
                if (!form) { return; }
                var payload = row.outcome_payload && typeof row.outcome_payload === 'object' ? row.outcome_payload : {};
                form.elements.outcome_id.value = String(outcomeId);
                form.elements.trigger_type.value = String(row.trigger_type || 'step_completed');
                form.elements.outcome_type.value = String(row.outcome_type || 'log_progress');
                form.elements.visibility.value = String(row.visibility || 'hidden');
                form.elements.requires_staff_confirmation.value = String(parseInt(row.requires_staff_confirmation || '0', 10) === 1 ? 1 : 0);
                form.elements.sort_order.value = String(parseInt(row.sort_order || '0', 10) || 0);
                form.elements.target_quest_definition_id.value = String(parseInt(payload.target_quest_definition_id || '0', 10) || 0);
                var targetLabel = form.querySelector('[data-role="admin-quest-outcome-target-label"]');
                if (targetLabel) {
                    targetLabel.value = (parseInt(form.elements.target_quest_definition_id.value || '0', 10) || 0) > 0
                        ? ('Quest #' + form.elements.target_quest_definition_id.value)
                        : '';
                }
                self.setSelectValue(form.elements.narrative_event_type, String(payload.narrative_event_type || ''), 'Tipo personalizzato: ');
                form.elements.narrative_event_title.value = String(payload.narrative_event_title || '');
                form.elements.message.value = String(payload.message || '');
                var stateId = parseInt(payload.state_id || '0', 10) || 0;
                form.elements.state_id.value = String(stateId);
                var stateInput = form.querySelector('[data-role="admin-quest-outcome-state-label"]');
                if (stateInput) {
                    stateInput.value = stateId > 0 ? self.stateLabelById(stateId) : '';
                }
                form.elements.intensity.value = String(payload.intensity || '');
                form.elements.duration_value.value = String(parseInt(payload.duration_value || '0', 10) || 0);
                form.elements.duration_unit.value = String(payload.duration_unit || '');
                self.applyOutcomeLogTypeValue(form, String(payload.log_type || ''));
            });
        },

        deleteOutcome: function (outcomeId) {
            outcomeId = parseInt(outcomeId || '0', 10) || 0;
            if (outcomeId <= 0) { return; }
            var self = this;
            this.request('/admin/quests/outcomes/delete', { quest_definition_id: this.selectedQuestId, outcome_id: outcomeId }, function () {
                Toast.show({ body: 'Esito eliminato.', type: 'success' });
                self.loadOutcomes();
            });
        },
        openLinksModal: function () {
            if (!this.assertQuestSelected()) { return; }
            this.resetLinkForm();
            this.showModal('admin-quest-links-modal');
            this.loadLinks();
        },

        loadLinks: function () {
            var self = this;
            this.request('/admin/quests/links/list', { quest_definition_id: this.selectedQuestId }, function (res) {
                var rows = self.parseRows(res);
                var box = document.getElementById('admin-quest-links-list');
                if (!box) { return; }
                if (!rows.length) {
                    box.innerHTML = '<div class="small text-muted">Nessun link.</div>';
                    return;
                }
                box.innerHTML = rows.map(function (row) {
                    var id = parseInt(row.id || '0', 10) || 0;
                    var label = row.narrative_event_id ? ('Narrative #' + row.narrative_event_id) : (row.system_event_id ? ('System #' + row.system_event_id) : 'N/D');
                    return '<div class="list-group-item d-flex justify-content-between align-items-center">'
                        + '<div class="small"><b>' + self.escapeHtml(label) + '</b> - ' + self.escapeHtml(self.linkTypeLabel(row.link_type)) + '</div>'
                        + '<div class="d-flex gap-1"><button class="btn btn-sm btn-outline-secondary" data-action="admin-quest-link-edit" data-id="' + id + '">Modifica</button>'
                        + '<button class="btn btn-sm btn-outline-danger" data-action="admin-quest-link-delete" data-id="' + id + '">Elimina</button></div></div>';
                }).join('');
            });
        },

        resetLinkForm: function () {
            var form = document.getElementById('admin-quest-link-form');
            if (!form) { return; }
            form.reset();
            form.elements.link_id.value = '0';
            form.elements.entity_id.value = '0';
            var label = form.querySelector('[data-role="admin-quest-link-entity-label"]');
            if (label) { label.value = ''; }
            this.hideSuggestions(form.querySelector('[data-role="admin-quest-link-entity-suggestions"]'));
        },

        saveLink: function () {
            var form = document.getElementById('admin-quest-link-form');
            if (!form) { return; }
            var entityType = String(form.elements.entity_type.value || 'narrative_event').trim();
            var entityId = parseInt(form.elements.entity_id.value || '0', 10) || 0;
            if (entityId <= 0) {
                Toast.show({ body: 'Seleziona una entita valida.', type: 'warning' });
                return;
            }
            var payload = {
                link_id: parseInt(form.elements.link_id.value || '0', 10) || 0,
                quest_definition_id: this.selectedQuestId,
                link_type: String(form.elements.link_type.value || 'contextualized_by').trim(),
                narrative_event_id: entityType === 'narrative_event' ? entityId : 0,
                system_event_id: entityType === 'system_event' ? entityId : 0
            };
            var self = this;
            this.request('/admin/quests/links/upsert', payload, function () {
                Toast.show({ body: 'Link salvato.', type: 'success' });
                self.loadLinks();
                self.resetLinkForm();
            });
        },

        editLink: function (linkId) {
            linkId = parseInt(linkId || '0', 10) || 0;
            if (linkId <= 0) { return; }
            var self = this;
            this.request('/admin/quests/links/list', { quest_definition_id: this.selectedQuestId }, function (res) {
                var rows = self.parseRows(res);
                var row = null;
                for (var i = 0; i < rows.length; i += 1) {
                    if ((parseInt(rows[i].id || '0', 10) || 0) === linkId) { row = rows[i]; break; }
                }
                if (!row) { return; }
                var form = document.getElementById('admin-quest-link-form');
                if (!form) { return; }
                form.elements.link_id.value = String(linkId);
                var entityType = (parseInt(row.system_event_id || '0', 10) || 0) > 0 ? 'system_event' : 'narrative_event';
                var entityId = entityType === 'system_event' ? (parseInt(row.system_event_id || '0', 10) || 0) : (parseInt(row.narrative_event_id || '0', 10) || 0);
                form.elements.entity_type.value = entityType;
                form.elements.entity_id.value = String(entityId);
                form.elements.link_type.value = String(row.link_type || 'contextualized_by');
                var input = form.querySelector('[data-role="admin-quest-link-entity-label"]');
                if (input) { input.value = (entityType === 'system_event' ? 'Evento sistema #' : 'Evento narrativo #') + entityId; }
            });
        },

        deleteLink: function (linkId) {
            linkId = parseInt(linkId || '0', 10) || 0;
            if (linkId <= 0) { return; }
            var self = this;
            this.request('/admin/quests/links/delete', { link_id: linkId }, function () {
                Toast.show({ body: 'Link eliminato.', type: 'success' });
                self.loadLinks();
            });
        },

        openInstancesModal: function () {
            if (!this.assertQuestSelected()) { return; }
            this.resetInstanceForms();
            this.showModal('admin-quest-instances-modal');
            this.setInstanceAssigneeInputState();
            this.loadInstances();
        },

        resetInstanceForms: function () {
            var assignForm = document.getElementById('admin-quest-instance-form');
            var actionForm = document.getElementById('admin-quest-instance-action-form');
            if (assignForm) {
                assignForm.reset();
                assignForm.elements.assignee_id.value = '0';
                if (assignForm.elements.intensity_level) {
                    assignForm.elements.intensity_level.value = 'STANDARD';
                }
                var label = assignForm.querySelector('[data-role="admin-quest-instance-assignee-label"]');
                if (label) { label.value = ''; }
                this.hideSuggestions(assignForm.querySelector('[data-role="admin-quest-instance-assignee-suggestions"]'));
            }
            if (actionForm) {
                actionForm.reset();
                actionForm.elements.quest_instance_id.value = '0';
                actionForm.elements.step_instance_id.innerHTML = '<option value="0">Seleziona uno step</option>';
                if (actionForm.elements.instance_intensity_level) {
                    actionForm.elements.instance_intensity_level.value = '';
                }
                var intensityInfo = actionForm.querySelector('[data-role="admin-quest-instance-intensity-current"]');
                if (intensityInfo) {
                    intensityInfo.textContent = 'Intensita corrente: -';
                }
            }
        },

        loadInstances: function () {
            var self = this;
            var statusFilter = document.getElementById('admin-quest-instances-filter-status');
            var assigneeFilter = document.getElementById('admin-quest-instances-filter-assignee');
            this.request('/admin/quests/instances/list', {
                quest_definition_id: this.selectedQuestId,
                status: statusFilter ? (statusFilter.value || '') : '',
                assignee_type: assigneeFilter ? (assigneeFilter.value || '') : ''
            }, function (res) {
                var dataset = res && res.dataset ? res.dataset : {};
                var rows = Array.isArray(dataset.rows) ? dataset.rows : [];
                var box = document.getElementById('admin-quest-instances-list');
                if (!box) { return; }
                if (!rows.length) {
                    box.innerHTML = '<div class="small text-muted">Nessuna istanza trovata.</div>';
                    return;
                }
                box.innerHTML = rows.map(function (row) {
                    var id = parseInt(row.id || '0', 10) || 0;
                    var intensityBadge = self.intensityBadge(row.intensity_level || row.effective_intensity_level || row.definition_intensity_level || 'STANDARD');
                    return '<button type="button" class="list-group-item list-group-item-action" data-action="admin-quest-instance-select" data-id="' + id + '">'
                        + '<div class="d-flex justify-content-between align-items-center"><span><b>#' + id + '</b> ' + self.escapeHtml(row.assignee_label || '-')
                        + '</span><span class="badge ' + self.statusBadge(row.current_status) + '">' + self.escapeHtml(self.statusLabel(row.current_status)) + '</span></div>'
                        + '<div class="small text-muted mt-1">' + intensityBadge + '</div>'
                        + '</button>';
                }).join('');
            });
        },
        assignInstance: function () {
            var form = document.getElementById('admin-quest-instance-form');
            if (!form) { return; }
            var assigneeType = String(form.elements.assignee_type.value || 'character').trim();
            var assigneeId = parseInt(form.elements.assignee_id.value || '0', 10) || 0;
            if (assigneeType !== 'world' && assigneeId <= 0) {
                Toast.show({ body: 'Seleziona un target valido.', type: 'warning' });
                return;
            }
            var payload = {
                quest_definition_id: this.selectedQuestId,
                assignee_type: assigneeType,
                assignee_id: assigneeType === 'world' ? 0 : assigneeId,
                status: String(form.elements.status.value || 'available').trim(),
                expires_at: this.toMysqlDate(form.elements.expires_at.value || ''),
                notes: String(form.elements.notes.value || '').trim()
            };
            var instanceIntensity = String((form.elements.intensity_level && form.elements.intensity_level.value) || '').trim().toUpperCase();
            if (instanceIntensity !== '') {
                payload.intensity_level = instanceIntensity;
            }
            var self = this;
            this.request('/admin/quests/instances/assign', payload, function () {
                Toast.show({ body: 'Istanza assegnata.', type: 'success' });
                self.loadInstances();
            });
        },

        selectInstance: function (instanceId) {
            instanceId = parseInt(instanceId || '0', 10) || 0;
            if (instanceId <= 0) { return; }
            var actionForm = document.getElementById('admin-quest-instance-action-form');
            if (!actionForm) { return; }
            actionForm.elements.quest_instance_id.value = String(instanceId);
            var self = this;
            this.request('/admin/quests/instances/get', { quest_instance_id: instanceId }, function (res) {
                var ds = res && res.dataset ? res.dataset : {};
                var instance = ds.instance && typeof ds.instance === 'object' ? ds.instance : {};
                var steps = Array.isArray(ds.steps) ? ds.steps : [];
                var select = actionForm.elements.step_instance_id;
                select.innerHTML = '<option value="0">Seleziona uno step</option>';
                if (actionForm.elements.instance_status) {
                    actionForm.elements.instance_status.value = String(instance.current_status || 'available');
                }
                if (actionForm.elements.instance_intensity_level) {
                    actionForm.elements.instance_intensity_level.value = String(instance.instance_intensity_level || '');
                }
                var intensityInfo = actionForm.querySelector('[data-role="admin-quest-instance-intensity-current"]');
                if (intensityInfo) {
                    var effective = String(ds.effective_intensity_level || instance.effective_intensity_level || instance.intensity_level || 'STANDARD');
                    var definition = String(ds.definition_intensity_level || instance.definition_intensity_level || 'STANDARD');
                    var instanceLevel = String(ds.instance_intensity_level || instance.instance_intensity_level || '');
                    var mode = instanceLevel ? ('override ' + self.intensityLabel(instanceLevel)) : ('definizione ' + self.intensityLabel(definition));
                    intensityInfo.textContent = 'Intensita corrente: ' + self.intensityLabel(effective) + ' (' + mode + ')';
                }
                for (var i = 0; i < steps.length; i += 1) {
                    var row = steps[i];
                    var id = parseInt(row.id || '0', 10) || 0;
                    if (id <= 0) { continue; }
                    select.innerHTML += '<option value="' + id + '">' + self.escapeHtml(row.step_title || ('Step #' + id)) + ' [' + self.escapeHtml(self.statusLabel(row.progress_status)) + ']</option>';
                }
            });
        },

        setInstanceStatus: function () {
            var form = document.getElementById('admin-quest-instance-action-form');
            if (!form) { return; }
            var instanceId = parseInt(form.elements.quest_instance_id.value || '0', 10) || 0;
            if (instanceId <= 0) {
                Toast.show({ body: 'Seleziona una istanza valida.', type: 'warning' });
                return;
            }
            var self = this;
            var payload = {
                quest_instance_id: instanceId,
                status: String(form.elements.instance_status.value || '').trim()
            };
            if (form.elements.instance_intensity_level) {
                var selectedIntensity = String(form.elements.instance_intensity_level.value || '').trim();
                if (selectedIntensity === 'null') {
                    payload.intensity_level = '';
                } else if (selectedIntensity !== '') {
                    payload.intensity_level = selectedIntensity;
                }
            }
            this.request('/admin/quests/instances/status/set', payload, function () {
                Toast.show({ body: 'Stato istanza aggiornato.', type: 'success' });
                self.loadInstances();
                self.selectInstance(instanceId);
            });
        },

        setInstanceStepStatus: function () {
            var form = document.getElementById('admin-quest-instance-action-form');
            if (!form) { return; }
            var instanceId = parseInt(form.elements.quest_instance_id.value || '0', 10) || 0;
            var stepId = parseInt(form.elements.step_instance_id.value || '0', 10) || 0;
            if (instanceId <= 0 || stepId <= 0) {
                Toast.show({ body: 'Seleziona istanza e step validi.', type: 'warning' });
                return;
            }
            var self = this;
            this.request('/admin/quests/instances/step/set', {
                quest_instance_id: instanceId,
                step_instance_id: stepId,
                status: String(form.elements.step_status.value || '').trim()
            }, function () {
                Toast.show({ body: 'Step istanza aggiornato.', type: 'success' });
                self.selectInstance(instanceId);
                self.loadInstances();
            });
        },

        openLogsModal: function () {
            if (!this.assertQuestSelected()) { return; }
            this.showModal('admin-quest-logs-modal');
            this.syncLogsTypeFilter();
            this.loadLogs();
        },

        syncLogsTypeFilter: function () {
            var preset = document.getElementById('admin-quest-logs-filter-type-preset');
            var custom = document.getElementById('admin-quest-logs-filter-type-custom');
            var hidden = document.getElementById('admin-quest-logs-filter-type');
            if (!preset || !custom || !hidden) { return; }
            var selected = String(preset.value || '').trim();
            var isCustom = selected === 'custom';
            custom.classList.toggle('d-none', !isCustom);
            if (!isCustom) { custom.value = ''; }
            hidden.value = isCustom ? String(custom.value || '').trim() : selected;
        },

        applyLogsTypeFilterValue: function (value) {
            var preset = document.getElementById('admin-quest-logs-filter-type-preset');
            var custom = document.getElementById('admin-quest-logs-filter-type-custom');
            if (!preset || !custom) { return; }
            var normalized = String(value || '').trim();
            if (!normalized) {
                preset.value = '';
                custom.value = '';
                this.syncLogsTypeFilter();
                return;
            }
            var known = {
                quest_status_changed: 1,
                step_status_changed: 1,
                outcome_applied: 1,
                condition_matched: 1,
                staff_override: 1,
                maintenance: 1
            };
            if (known[normalized]) {
                preset.value = normalized;
                custom.value = '';
            } else {
                preset.value = 'custom';
                custom.value = normalized;
            }
            this.syncLogsTypeFilter();
        },

        loadLogs: function () {
            var self = this;
            this.syncLogsTypeFilter();
            var instanceNode = document.getElementById('admin-quest-logs-filter-instance-id');
            var typeNode = document.getElementById('admin-quest-logs-filter-type');
            this.request('/admin/quests/logs/list', {
                quest_definition_id: this.selectedQuestId,
                quest_instance_id: instanceNode ? (parseInt(instanceNode.value || '0', 10) || 0) : 0,
                log_type: typeNode ? String(typeNode.value || '').trim() : ''
            }, function (res) {
                var rows = self.parseRows(res);
                var box = document.getElementById('admin-quest-logs-list');
                if (!box) { return; }
                if (!rows.length) {
                    box.innerHTML = '<div class="small text-muted">Nessun log disponibile.</div>';
                    return;
                }
                box.innerHTML = rows.map(function (row) {
                    var payload = row.payload && typeof row.payload === 'object' ? JSON.stringify(row.payload) : '{}';
                    return '<div class="list-group-item"><div class="small"><b>' + self.escapeHtml(self.logTypeLabel(row.log_type)) + '</b> - istanza #' + (parseInt(row.quest_instance_id || '0', 10) || 0)
                        + ' - ' + self.escapeHtml(row.created_at || '-') + '</div><div class="small text-muted">' + self.escapeHtml(payload) + '</div></div>';
                }).join('');
            });
        },

        bindAutocompleteInputs: function () {
            var self = this;
            this.bindAutoInput('#admin-quest-form [data-role="admin-quest-scope-label"]', 'quest-scope', function () { self.searchScopeSuggestions(); });
            this.bindAutoInput('#admin-quest-outcome-form [data-role="admin-quest-outcome-target-label"]', 'quest-outcome-target', function () { self.searchQuestSuggestions(); });
            this.bindAutoInput('#admin-quest-outcome-form [data-role="admin-quest-outcome-state-label"]', 'quest-outcome-state', function () { self.searchOutcomeStateSuggestions(); });
            this.bindAutoInput('#admin-quest-link-form [data-role="admin-quest-link-entity-label"]', 'quest-link-entity', function () { self.searchLinkEntitySuggestions(); });
            this.bindAutoInput('#admin-quest-instance-form [data-role="admin-quest-instance-assignee-label"]', 'quest-instance-assignee', function () { self.searchInstanceAssigneeSuggestions(); });
            this.bindAutoInput('#admin-quest-closure-instance-label', 'quest-closure-instance', function () { self.searchClosureInstanceSuggestions(); });
            this.bindAutoInput('#admin-quest-reward-form [data-role="admin-quest-reward-instance-label"]', 'quest-reward-instance', function () { self.searchRewardInstanceSuggestions(); });
            this.bindAutoInput('#admin-quest-reward-form [data-role="admin-quest-reward-character-label"]', 'quest-reward-character', function () { self.searchRewardCharacterSuggestions(); });
            this.bindAutoInput('#admin-quest-reward-form [data-role="admin-quest-reward-item-label"]', 'quest-reward-item', function () { self.searchRewardItemSuggestions(); });
            this.bindAutoInput('#admin-quest-logs-filter-instance-label', 'quest-logs-instance', function () { self.searchLogsInstanceSuggestions(); });
        },

        bindAutoInput: function (selector, key, fn) {
            var input = document.querySelector(selector);
            var self = this;
            if (!input) { return; }
            input.addEventListener('input', function () {
                self.debounce(key, 200, function () { fn(); });
            });
        },

        searchScopeSuggestions: function () {
            var form = document.getElementById('admin-quest-form');
            if (!form) { return; }
            var scopeType = String(form.elements.scope_type.value || 'world').trim();
            var input = form.querySelector('[data-role="admin-quest-scope-label"]');
            var hidden = form.elements.scope_id;
            var box = form.querySelector('[data-role="admin-quest-scope-suggestions"]');
            if (!input || !hidden || !box) { return; }
            hidden.value = '0';
            if (scopeType === 'world') { this.hideSuggestions(box); return; }
            var query = String(input.value || '').trim();
            if (query.length < 2) { this.hideSuggestions(box); return; }
            this.searchByType(scopeType, query, box, 'admin-quest-scope-suggestion');
        },

        searchQuestSuggestions: function () {
            var form = document.getElementById('admin-quest-outcome-form');
            if (!form) { return; }
            var input = form.querySelector('[data-role="admin-quest-outcome-target-label"]');
            var hidden = form.elements.target_quest_definition_id;
            var box = form.querySelector('[data-role="admin-quest-outcome-target-suggestions"]');
            if (!input || !hidden || !box) { return; }
            hidden.value = '0';
            var query = String(input.value || '').trim();
            if (query.length < 2) { this.hideSuggestions(box); return; }
            this.request('/admin/quests/definitions/list', { search: query, results: 20, page: 1, status: '' }, function (res) {
                var rows = AdminQuests.parseRows(res).map(function (row) {
                    return { id: parseInt(row.id || '0', 10) || 0, label: String(row.title || '').trim() };
                }).filter(function (row) { return row.id > 0 && row.label; });
                AdminQuests.renderSuggestionBox(box, rows, 'admin-quest-outcome-target-suggestion');
            }, function () { AdminQuests.hideSuggestions(box); });
        },

        searchOutcomeStateSuggestions: function () {
            var form = document.getElementById('admin-quest-outcome-form');
            if (!form) { return; }
            var input = form.querySelector('[data-role="admin-quest-outcome-state-label"]');
            var hidden = form.elements.state_id;
            var box = form.querySelector('[data-role="admin-quest-outcome-state-suggestions"]');
            if (!input || !hidden || !box) { return; }
            hidden.value = '0';
            var query = String(input.value || '').trim().toLowerCase();
            if (query.length < 2) { this.hideSuggestions(box); return; }
            var endpoints = window.LogeonModuleEndpoints || {};
            var endpoint = String(endpoints.narrativeStatesList || '').trim();
            if (!endpoint) {
                this.hideSuggestions(box);
                return;
            }
            this.request(endpoint, { include_inactive: 0, results: 80, page: 1 }, function (res) {
                var rows = AdminQuests.parseRows(res).map(function (row) {
                    var id = parseInt(row.id || '0', 10) || 0;
                    var label = String(row.name || row.title || row.slug || '').trim();
                    return { id: id, label: label || ('Stato #' + id) };
                }).filter(function (row) {
                    return row.id > 0 && row.label.toLowerCase().indexOf(query) !== -1;
                }).slice(0, 14);
                for (var i = 0; i < rows.length; i += 1) {
                    AdminQuests.narrativeStateLookup[String(rows[i].id)] = String(rows[i].label);
                }
                AdminQuests.renderSuggestionBox(box, rows, 'admin-quest-outcome-state-suggestion');
            }, function () { AdminQuests.hideSuggestions(box); });
        },
        searchLinkEntitySuggestions: function () {
            var form = document.getElementById('admin-quest-link-form');
            if (!form) { return; }
            var type = String(form.elements.entity_type.value || 'narrative_event').trim();
            var input = form.querySelector('[data-role="admin-quest-link-entity-label"]');
            var hidden = form.elements.entity_id;
            var box = form.querySelector('[data-role="admin-quest-link-entity-suggestions"]');
            if (!input || !hidden || !box) { return; }
            hidden.value = '0';
            var query = String(input.value || '').trim().toLowerCase();
            if (query.length < 2) { this.hideSuggestions(box); return; }
            var endpoint = type === 'system_event' ? '/admin/system-events/list' : '/admin/narrative-events/list';
            this.request(endpoint, { results: 80, page: 1 }, function (res) {
                var rows = AdminQuests.parseRows(res).map(function (row) {
                    var id = parseInt(row.id || '0', 10) || 0;
                    var title = String(row.title || row.name || '').trim();
                    return { id: id, label: title || ('ID #' + id) };
                }).filter(function (row) { return row.id > 0 && row.label.toLowerCase().indexOf(query) !== -1; }).slice(0, 14);
                AdminQuests.renderSuggestionBox(box, rows, 'admin-quest-link-entity-suggestion');
            }, function () { AdminQuests.hideSuggestions(box); });
        },

        searchInstanceAssigneeSuggestions: function () {
            var form = document.getElementById('admin-quest-instance-form');
            if (!form) { return; }
            var type = String(form.elements.assignee_type.value || 'character').trim();
            var input = form.querySelector('[data-role="admin-quest-instance-assignee-label"]');
            var hidden = form.elements.assignee_id;
            var box = form.querySelector('[data-role="admin-quest-instance-assignee-suggestions"]');
            if (!input || !hidden || !box) { return; }
            hidden.value = '0';
            if (type === 'world') { this.hideSuggestions(box); return; }
            var query = String(input.value || '').trim();
            if (query.length < 2) { this.hideSuggestions(box); return; }
            this.searchByType(type, query, box, 'admin-quest-instance-assignee-suggestion');
        },

        searchByType: function (type, query, box, role) {
            var self = this;
            type = String(type || '').toLowerCase();
            if (type === 'character') {
                this.request('/list/characters/search', { query: query }, function (res) {
                    var rows = self.parseRows(res).map(function (row) {
                        var id = parseInt(row.id || row.character_id || '0', 10) || 0;
                        var label = String((row.name || '') + ' ' + (row.surname || '')).trim();
                        return { id: id, label: label || ('PG #' + id) };
                    }).filter(function (row) { return row.id > 0; });
                    self.renderSuggestionBox(box, rows, role);
                }, function () { self.hideSuggestions(box); });
                return;
            }
            var endpoint = type === 'faction' ? '/admin/factions/list'
                : (type === 'guild' ? '/admin/guilds/list'
                : (type === 'map' ? '/list/maps' : '/list/locations'));
            this.request(endpoint, { results: 250, page: 1 }, function (res) {
                var q = String(query || '').trim().toLowerCase();
                var rows = self.parseRows(res).map(function (row) {
                    var id = parseInt(row.id || row.map_id || row.location_id || '0', 10) || 0;
                    var label = String(row.name || row.title || row.code || '').trim();
                    return { id: id, label: label || ('ID #' + id) };
                }).filter(function (row) { return row.id > 0 && row.label.toLowerCase().indexOf(q) !== -1; }).slice(0, 20);
                self.renderSuggestionBox(box, rows, role);
            }, function () { self.hideSuggestions(box); });
        },

        searchClosureInstanceSuggestions: function () {
            if (!this.assertQuestSelected()) { return; }
            var input = document.getElementById('admin-quest-closure-instance-label');
            var hidden = document.getElementById('admin-quest-closure-instance-id');
            var box = document.getElementById('admin-quest-closure-instance-suggestions');
            if (!input || !hidden || !box) { return; }
            hidden.value = '0';
            var query = String(input.value || '').trim().toLowerCase();
            if (query.length < 2) { this.hideSuggestions(box); return; }

            var self = this;
            this.request('/admin/quests/instances/list', {
                quest_definition_id: this.selectedQuestId,
                results: 100,
                page: 1
            }, function (res) {
                var rows = self.parseRows(res).map(function (row) {
                    var id = parseInt(row.id || '0', 10) || 0;
                    var label = '#' + id + ' · ' + String(row.assignee_label || '-').trim() + ' · ' + self.statusLabel(row.current_status);
                    return { id: id, label: label };
                }).filter(function (row) {
                    return row.id > 0 && row.label.toLowerCase().indexOf(query) !== -1;
                }).slice(0, 20);
                self.renderSuggestionBox(box, rows, 'admin-quest-closure-instance-suggestion');
            }, function () {
                self.hideSuggestions(box);
            });
        },

        pickClosureInstanceSuggestion: function (node) {
            var id = parseInt(node.getAttribute('data-id') || '0', 10) || 0;
            var label = String(node.getAttribute('data-label') || '');
            var input = document.getElementById('admin-quest-closure-instance-label');
            var hidden = document.getElementById('admin-quest-closure-instance-id');
            if (input) { input.value = label; }
            if (hidden) { hidden.value = String(id); }
            this.hideSuggestions(node.parentElement);
            this.loadClosureDetail();
        },

        selectClosureInstance: function (instanceId) {
            instanceId = parseInt(instanceId || '0', 10) || 0;
            if (instanceId <= 0) { return; }
            var input = document.getElementById('admin-quest-closure-instance-label');
            var hidden = document.getElementById('admin-quest-closure-instance-id');
            if (hidden) { hidden.value = String(instanceId); }
            if (input) { input.value = 'Istanza #' + instanceId; }
            this.loadClosureDetail();
        },

        openClosuresModal: function () {
            if (!this.assertQuestSelected()) { return; }
            var hidden = document.getElementById('admin-quest-closure-instance-id');
            var label = document.getElementById('admin-quest-closure-instance-label');
            if (hidden) { hidden.value = '0'; }
            if (label) { label.value = ''; }
            var form = document.getElementById('admin-quest-closure-form');
            if (form) { form.reset(); }
            this.showModal('admin-quest-closures-modal');
            this.loadClosures();
        },

        loadClosures: function () {
            if (!this.assertQuestSelected()) { return; }
            var self = this;
            var box = document.getElementById('admin-quest-closures-list');
            if (box) {
                box.innerHTML = '<div class="small text-muted">Caricamento...</div>';
            }
            var closureTypeFilter = document.getElementById('admin-quest-closure-type-filter');
            this.request('/admin/quests/closures/list', {
                quest_definition_id: this.selectedQuestId,
                closure_type: closureTypeFilter ? String(closureTypeFilter.value || '').trim() : '',
                results: 100,
                page: 1
            }, function (res) {
                var rows = self.parseRows(res);
                if (!box) { return; }
                if (!rows.length) {
                    box.innerHTML = '<div class="small text-muted">Nessuna chiusura registrata.</div>';
                    return;
                }
                box.innerHTML = rows.map(function (row) {
                    var instanceId = parseInt(row.quest_instance_id || '0', 10) || 0;
                    return '<button type="button" class="list-group-item list-group-item-action" data-action="admin-quest-closure-select" data-instance-id="' + instanceId + '">'
                        + '<div class="d-flex justify-content-between align-items-center">'
                        + '<span><b>Istanza #' + instanceId + '</b></span>'
                        + '<span class="badge text-bg-secondary">' + self.escapeHtml(row.closure_type || '-') + '</span>'
                        + '</div>'
                        + '<div class="small text-muted">' + self.escapeHtml(row.outcome_label || '-') + ' · ' + self.escapeHtml(row.closed_at || '-') + '</div>'
                        + '</button>';
                }).join('');
            }, function (error) {
                if (box) {
                    box.innerHTML = '<div class="small text-danger">' + self.escapeHtml(self.errMsg(error)) + '</div>';
                }
            });
        },

        loadClosureDetail: function () {
            var instanceId = parseInt((document.getElementById('admin-quest-closure-instance-id') || {}).value || '0', 10) || 0;
            if (instanceId <= 0) {
                Toast.show({ body: 'Seleziona una istanza valida.', type: 'warning' });
                return;
            }
            var form = document.getElementById('admin-quest-closure-form');
            if (!form) { return; }
            var self = this;
            this.request('/admin/quests/closures/get', { quest_instance_id: instanceId }, function (res) {
                var ds = res && res.dataset ? res.dataset : {};
                var report = ds.closure_report || null;
                var instance = ds.instance || null;

                form.elements.final_status.value = String((instance && instance.current_status) ? instance.current_status : 'completed');
                form.elements.closure_type.value = String((report && report.closure_type) ? report.closure_type : 'success');
                form.elements.player_visible.value = String((report && parseInt(report.player_visible || '0', 10) === 0) ? '0' : '1');
                form.elements.outcome_label.value = String((report && report.outcome_label) ? report.outcome_label : '');
                form.elements.summary_public.value = String((report && report.summary_public) ? report.summary_public : '');
                form.elements.summary_private.value = String((report && report.summary_private) ? report.summary_private : '');
                form.elements.staff_notes.value = String((report && report.staff_notes) ? report.staff_notes : '');
            }, function (error) {
                Toast.show({ body: self.errMsg(error), type: 'error' });
            });
        },

        saveClosure: function () {
            var instanceId = parseInt((document.getElementById('admin-quest-closure-instance-id') || {}).value || '0', 10) || 0;
            if (instanceId <= 0) {
                Toast.show({ body: 'Seleziona una istanza valida.', type: 'warning' });
                return;
            }
            var form = document.getElementById('admin-quest-closure-form');
            if (!form) { return; }
            var payload = {
                quest_instance_id: instanceId,
                final_status: String(form.elements.final_status.value || 'completed').trim(),
                closure_type: String(form.elements.closure_type.value || 'success').trim(),
                player_visible: parseInt(form.elements.player_visible.value || '1', 10) === 0 ? 0 : 1,
                outcome_label: String(form.elements.outcome_label.value || '').trim(),
                summary_public: String(form.elements.summary_public.value || '').trim(),
                summary_private: String(form.elements.summary_private.value || '').trim(),
                staff_notes: String(form.elements.staff_notes.value || '').trim()
            };
            var self = this;
            this.request('/admin/quests/instances/status/set', {
                quest_instance_id: instanceId,
                status: payload.final_status
            }, function () {
                self.request('/admin/quests/closures/upsert', payload, function () {
                    Toast.show({ body: 'Report di chiusura salvato.', type: 'success' });
                    self.loadClosures();
                });
            });
        },

        openRewardsModal: function () {
            if (!this.assertQuestSelected()) { return; }
            var form = document.getElementById('admin-quest-reward-form');
            if (form) {
                form.reset();
                form.elements.quest_instance_id.value = '0';
                form.elements.recipient_id.value = '0';
                form.elements.reward_reference_id.value = '0';
            }
            this.syncRewardTypeField();
            this.showModal('admin-quest-rewards-modal');
            this.loadRewards();
        },

        syncRewardTypeField: function () {
            var form = document.getElementById('admin-quest-reward-form');
            if (!form) { return; }
            var type = String(form.elements.reward_type.value || 'experience').trim().toLowerCase();
            var itemWrap = form.querySelector('[data-role="admin-quest-reward-item-wrap"]');
            var qtyInput = form.elements.item_quantity;
            var expInput = form.elements.experience_amount;
            var qtyCol = qtyInput && typeof qtyInput.closest === 'function' ? qtyInput.closest('.col-12') : null;
            var expCol = expInput && typeof expInput.closest === 'function' ? expInput.closest('.col-12') : null;
            if (itemWrap) {
                itemWrap.classList.toggle('d-none', type !== 'item');
            }
            if (qtyCol) {
                qtyCol.classList.toggle('d-none', type !== 'item');
            }
            if (expCol) {
                expCol.classList.toggle('d-none', type !== 'experience');
            }
        },

        searchRewardInstanceSuggestions: function () {
            if (!this.assertQuestSelected()) { return; }
            var form = document.getElementById('admin-quest-reward-form');
            if (!form) { return; }
            var input = form.querySelector('[data-role="admin-quest-reward-instance-label"]');
            var hidden = form.elements.quest_instance_id;
            var box = form.querySelector('[data-role="admin-quest-reward-instance-suggestions"]');
            if (!input || !hidden || !box) { return; }
            hidden.value = '0';
            var query = String(input.value || '').trim().toLowerCase();
            if (query.length < 2) { this.hideSuggestions(box); return; }
            var self = this;
            this.request('/admin/quests/instances/list', {
                quest_definition_id: this.selectedQuestId,
                results: 120,
                page: 1
            }, function (res) {
                var rows = self.parseRows(res).map(function (row) {
                    var id = parseInt(row.id || '0', 10) || 0;
                    var label = '#' + id + ' · ' + String(row.assignee_label || '-').trim();
                    return { id: id, label: label };
                }).filter(function (row) {
                    return row.id > 0 && row.label.toLowerCase().indexOf(query) !== -1;
                }).slice(0, 20);
                self.renderSuggestionBox(box, rows, 'admin-quest-reward-instance-suggestion');
            }, function () { self.hideSuggestions(box); });
        },

        searchLogsInstanceSuggestions: function () {
            if (!this.assertQuestSelected()) { return; }
            var input = document.getElementById('admin-quest-logs-filter-instance-label');
            var hidden = document.getElementById('admin-quest-logs-filter-instance-id');
            var box = document.querySelector('#admin-quest-logs-modal [data-role="admin-quest-logs-instance-suggestions"]');
            if (!input || !hidden || !box) { return; }
            hidden.value = '0';
            var query = String(input.value || '').trim().toLowerCase();
            if (query.length < 2) { this.hideSuggestions(box); return; }

            var self = this;
            this.request('/admin/quests/instances/list', {
                quest_definition_id: this.selectedQuestId,
                results: 120,
                page: 1
            }, function (res) {
                var rows = self.parseRows(res).map(function (row) {
                    var id = parseInt(row.id || '0', 10) || 0;
                    var label = '#' + id + ' · ' + String(row.assignee_label || '-').trim() + ' · ' + self.statusLabel(row.current_status);
                    return { id: id, label: label };
                }).filter(function (row) {
                    return row.id > 0 && row.label.toLowerCase().indexOf(query) !== -1;
                }).slice(0, 20);
                self.renderSuggestionBox(box, rows, 'admin-quest-logs-instance-suggestion');
            }, function () {
                self.hideSuggestions(box);
            });
        },

        pickLogsInstanceSuggestion: function (node) {
            if (!node) { return; }
            var id = parseInt(node.getAttribute('data-id') || '0', 10) || 0;
            var label = String(node.getAttribute('data-label') || '');
            var input = document.getElementById('admin-quest-logs-filter-instance-label');
            var hidden = document.getElementById('admin-quest-logs-filter-instance-id');
            if (input) { input.value = label; }
            if (hidden) { hidden.value = String(id); }
            this.hideSuggestions(node.parentElement);
            this.loadLogs();
        },

        searchRewardCharacterSuggestions: function () {
            var form = document.getElementById('admin-quest-reward-form');
            if (!form) { return; }
            var input = form.querySelector('[data-role="admin-quest-reward-character-label"]');
            var hidden = form.elements.recipient_id;
            var box = form.querySelector('[data-role="admin-quest-reward-character-suggestions"]');
            if (!input || !hidden || !box) { return; }
            hidden.value = '0';
            var query = String(input.value || '').trim();
            if (query.length < 2) { this.hideSuggestions(box); return; }
            var self = this;
            this.request('/list/characters/search', { query: query }, function (res) {
                var rows = self.parseRows(res).map(function (row) {
                    var id = parseInt(row.id || row.character_id || '0', 10) || 0;
                    var label = String((row.name || '') + ' ' + (row.surname || '')).trim();
                    return { id: id, label: label || ('PG #' + id) };
                }).filter(function (row) { return row.id > 0; }).slice(0, 20);
                self.renderSuggestionBox(box, rows, 'admin-quest-reward-character-suggestion');
            }, function () { self.hideSuggestions(box); });
        },

        searchRewardItemSuggestions: function () {
            var form = document.getElementById('admin-quest-reward-form');
            if (!form) { return; }
            var input = form.querySelector('[data-role="admin-quest-reward-item-label"]');
            var hidden = form.elements.reward_reference_id;
            var box = form.querySelector('[data-role="admin-quest-reward-item-suggestions"]');
            if (!input || !hidden || !box) { return; }
            hidden.value = '0';
            var query = String(input.value || '').trim().toLowerCase();
            if (query.length < 2) { this.hideSuggestions(box); return; }
            var self = this;
            this.request('/list/items', {}, function (res) {
                var rows = self.parseRows(res).map(function (row) {
                    var id = parseInt(row.id || '0', 10) || 0;
                    var label = String(row.name || '').trim();
                    return { id: id, label: label || ('Oggetto #' + id) };
                }).filter(function (row) {
                    return row.id > 0 && row.label.toLowerCase().indexOf(query) !== -1;
                }).slice(0, 20);
                self.renderSuggestionBox(box, rows, 'admin-quest-reward-item-suggestion');
            }, function () { self.hideSuggestions(box); });
        },

        loadRewards: function () {
            var form = document.getElementById('admin-quest-reward-form');
            var box = document.getElementById('admin-quest-rewards-list');
            if (!form || !box) { return; }
            var instanceId = parseInt(form.elements.quest_instance_id.value || '0', 10) || 0;
            if (instanceId <= 0) {
                box.innerHTML = '<div class="small text-muted">Seleziona una istanza per visualizzare le ricompense.</div>';
                return;
            }
            var self = this;
            this.request('/admin/quests/rewards/list', {
                quest_instance_id: instanceId,
                results: 100,
                page: 1
            }, function (res) {
                var rows = self.parseRows(res);
                if (!rows.length) {
                    box.innerHTML = '<div class="small text-muted">Nessuna ricompensa assegnata.</div>';
                    return;
                }
                box.innerHTML = rows.map(function (row) {
                    var id = parseInt(row.id || '0', 10) || 0;
                    return '<div class="list-group-item">'
                        + '<div class="d-flex justify-content-between align-items-center">'
                        + '<div>'
                        + '<div class="small fw-semibold">' + self.escapeHtml(row.reward_label || '-') + '</div>'
                        + '<div class="small text-muted">' + self.escapeHtml(row.recipient_label || '-') + ' · ' + self.escapeHtml(self.visibilityLabel(row.visibility || 'public')) + '</div>'
                        + '</div>'
                        + '<button type="button" class="btn btn-sm btn-outline-danger" data-action="admin-quest-reward-remove" data-id="' + id + '">Rimuovi</button>'
                        + '</div>'
                        + '</div>';
                }).join('');
            }, function (error) {
                box.innerHTML = '<div class="small text-danger">' + self.escapeHtml(self.errMsg(error)) + '</div>';
            });
        },

        assignReward: function () {
            var form = document.getElementById('admin-quest-reward-form');
            if (!form) { return; }
            var instanceId = parseInt(form.elements.quest_instance_id.value || '0', 10) || 0;
            var recipientId = parseInt(form.elements.recipient_id.value || '0', 10) || 0;
            var rewardType = String(form.elements.reward_type.value || 'experience').trim().toLowerCase();
            if (instanceId <= 0 || recipientId <= 0) {
                Toast.show({ body: 'Seleziona istanza e destinatario.', type: 'warning' });
                return;
            }

            var payload = {
                quest_instance_id: instanceId,
                recipient_type: 'character',
                recipient_id: recipientId,
                reward_type: rewardType,
                visibility: String(form.elements.visibility.value || 'public').trim()
            };

            if (rewardType === 'experience') {
                payload.reward_value = parseFloat(String(form.elements.experience_amount.value || '0').replace(',', '.')) || 0;
                if (payload.reward_value <= 0) {
                    Toast.show({ body: 'Inserisci un valore esperienza valido.', type: 'warning' });
                    return;
                }
            } else if (rewardType === 'item') {
                payload.reward_reference_id = parseInt(form.elements.reward_reference_id.value || '0', 10) || 0;
                payload.reward_value = parseInt(form.elements.item_quantity.value || '1', 10) || 1;
                if (payload.reward_reference_id <= 0) {
                    Toast.show({ body: 'Seleziona un oggetto valido.', type: 'warning' });
                    return;
                }
            }

            var self = this;
            this.request('/admin/quests/rewards/assign', payload, function () {
                Toast.show({ body: 'Ricompensa assegnata.', type: 'success' });
                self.loadRewards();
            });
        },

        removeReward: function (rewardId) {
            rewardId = parseInt(rewardId || '0', 10) || 0;
            if (rewardId <= 0) { return; }
            var form = document.getElementById('admin-quest-reward-form');
            var instanceId = form ? (parseInt(form.elements.quest_instance_id.value || '0', 10) || 0) : 0;
            var self = this;
            this.request('/admin/quests/rewards/remove', {
                reward_id: rewardId,
                quest_instance_id: instanceId
            }, function () {
                Toast.show({ body: 'Ricompensa rimossa dal tracciamento.', type: 'success' });
                self.loadRewards();
            });
        },

        handleSuggestionClick: function (event) {
            var node = event.target && event.target.closest ? event.target.closest('[data-role]') : null;
            if (!node) { return false; }
            var role = String(node.getAttribute('data-role') || '');
            if (role === 'admin-quest-scope-suggestion') { event.preventDefault(); this.pickSuggestion('#admin-quest-form', node, 'scope_id', '[data-role="admin-quest-scope-label"]'); return true; }
            if (role === 'admin-quest-outcome-target-suggestion') { event.preventDefault(); this.pickSuggestion('#admin-quest-outcome-form', node, 'target_quest_definition_id', '[data-role="admin-quest-outcome-target-label"]'); return true; }
            if (role === 'admin-quest-outcome-state-suggestion') { event.preventDefault(); this.pickSuggestion('#admin-quest-outcome-form', node, 'state_id', '[data-role="admin-quest-outcome-state-label"]'); return true; }
            if (role === 'admin-quest-link-entity-suggestion') { event.preventDefault(); this.pickSuggestion('#admin-quest-link-form', node, 'entity_id', '[data-role="admin-quest-link-entity-label"]'); return true; }
            if (role === 'admin-quest-instance-assignee-suggestion') { event.preventDefault(); this.pickSuggestion('#admin-quest-instance-form', node, 'assignee_id', '[data-role="admin-quest-instance-assignee-label"]'); return true; }
            if (role === 'admin-quest-closure-instance-suggestion') { event.preventDefault(); this.pickClosureInstanceSuggestion(node); return true; }
            if (role === 'admin-quest-logs-instance-suggestion') { event.preventDefault(); this.pickLogsInstanceSuggestion(node); return true; }
            if (role === 'admin-quest-reward-instance-suggestion') {
                event.preventDefault();
                this.pickSuggestion('#admin-quest-reward-form', node, 'quest_instance_id', '[data-role="admin-quest-reward-instance-label"]');
                this.loadRewards();
                return true;
            }
            if (role === 'admin-quest-reward-character-suggestion') { event.preventDefault(); this.pickSuggestion('#admin-quest-reward-form', node, 'recipient_id', '[data-role="admin-quest-reward-character-label"]'); return true; }
            if (role === 'admin-quest-reward-item-suggestion') { event.preventDefault(); this.pickSuggestion('#admin-quest-reward-form', node, 'reward_reference_id', '[data-role="admin-quest-reward-item-label"]'); return true; }
            return false;
        },

        pickSuggestion: function (formSelector, node, hiddenName, labelSelector) {
            var form = document.querySelector(formSelector);
            if (!form || !node) { return; }
            var id = parseInt(node.getAttribute('data-id') || '0', 10) || 0;
            if (id <= 0) { return; }
            form.elements[hiddenName].value = String(id);
            var label = form.querySelector(labelSelector);
            if (label) { label.value = String(node.getAttribute('data-label') || ''); }
            this.hideSuggestions(node.parentElement);
        },

        hideOutsideSuggestions: function (target) {
            var boxes = this.root.querySelectorAll('[data-role$="suggestions"]');
            for (var i = 0; i < boxes.length; i += 1) {
                var box = boxes[i];
                if (!box.classList.contains('d-none') && !target.closest('[data-role$="suggestions"]')) {
                    this.hideSuggestions(box);
                }
            }
        },

        hideSuggestions: function (box) {
            if (!box) { return; }
            box.classList.add('d-none');
            box.innerHTML = '';
        },

        renderSuggestionBox: function (box, rows, role) {
            if (!box) { return; }
            box.innerHTML = '';
            if (!rows.length) { box.classList.add('d-none'); return; }
            for (var i = 0; i < rows.length; i += 1) {
                var row = rows[i];
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'list-group-item list-group-item-action small py-1';
                btn.setAttribute('data-role', role);
                btn.setAttribute('data-id', String(row.id || 0));
                btn.setAttribute('data-label', String(row.label || ''));
                btn.textContent = String(row.label || '');
                box.appendChild(btn);
            }
            box.classList.remove('d-none');
        },

        resetSuggestionField: function (formSelector, hiddenName, labelSelector, boxSelector) {
            var form = document.querySelector(formSelector);
            if (!form) { return; }
            if (form.elements[hiddenName]) { form.elements[hiddenName].value = '0'; }
            var label = form.querySelector(labelSelector);
            if (label) { label.value = ''; }
            this.hideSuggestions(form.querySelector(boxSelector));
        },

        setScopeInputState: function () {
            var form = document.getElementById('admin-quest-form');
            if (!form) { return; }
            var scopeType = String(form.elements.scope_type.value || 'world').toLowerCase();
            var input = form.querySelector('[data-role="admin-quest-scope-label"]');
            if (!input) { return; }
            if (scopeType === 'world') {
                input.disabled = true;
                input.placeholder = 'Nessun target richiesto';
                form.elements.scope_id.value = '0';
            } else {
                input.disabled = false;
                var placeholders = {
                    character: 'Cerca personaggio...',
                    faction: 'Cerca fazione...',
                    guild: 'Cerca gilda...',
                    map: 'Cerca mappa...',
                    location: 'Cerca luogo...'
                };
                input.placeholder = placeholders[scopeType] || 'Cerca...';
            }
        },

        setInstanceAssigneeInputState: function () {
            var form = document.getElementById('admin-quest-instance-form');
            if (!form) { return; }
            var type = String(form.elements.assignee_type.value || 'character').toLowerCase();
            var input = form.querySelector('[data-role="admin-quest-instance-assignee-label"]');
            if (!input) { return; }
            if (type === 'world') {
                input.disabled = true;
                input.placeholder = 'Nessun target richiesto';
                input.value = '';
                form.elements.assignee_id.value = '0';
            } else {
                input.disabled = false;
                input.placeholder = type === 'character' ? 'Cerca personaggio...' : (type === 'faction' ? 'Cerca fazione...' : 'Cerca gilda...');
            }
        },

        assertQuestSelected: function () {
            if (this.selectedQuestId > 0) { return true; }
            Toast.show({ body: 'Seleziona prima una quest.', type: 'warning' });
            return false;
        },

        setSelectValue: function (node, value, customPrefix) {
            if (!node || !node.options) { return; }
            var normalized = String(value || '').trim();
            var i = 0;
            for (i = node.options.length - 1; i >= 0; i -= 1) {
                if (String(node.options[i].getAttribute('data-custom-option') || '') === '1') {
                    node.remove(i);
                }
            }
            if (!normalized) {
                node.value = '';
                return;
            }
            for (i = 0; i < node.options.length; i += 1) {
                if (String(node.options[i].value || '').trim() === normalized) {
                    node.value = normalized;
                    return;
                }
            }
            var option = document.createElement('option');
            option.value = normalized;
            option.textContent = String(customPrefix || 'Valore personalizzato: ') + normalized;
            option.setAttribute('data-custom-option', '1');
            node.appendChild(option);
            node.value = normalized;
        },

        parseRows: function (response) {
            if (!response) { return []; }
            var dataset = response.dataset;
            if (Array.isArray(dataset)) { return dataset; }
            if (dataset && Array.isArray(dataset.rows)) { return dataset.rows; }
            if (response.properties && Array.isArray(response.properties.dataset)) { return response.properties.dataset; }
            return [];
        },

        request: function (url, payload, onSuccess, onError) {
            var self = this;
            if (!window.Request || !Request.http || typeof Request.http.post !== 'function') {
                Toast.show({ body: 'Servizio non disponibile.', type: 'error' });
                return this;
            }
            Request.http.post(String(url || ''), payload || {}).then(function (response) {
                if (typeof onSuccess === 'function') { onSuccess(response || null); }
            }).catch(function (error) {
                if (typeof onError === 'function') { onError(error || null); return; }
                Toast.show({ body: self.errMsg(error), type: 'error' });
            });
            return this;
        },

        errMsg: function (error) {
            if (window.Request && typeof window.Request.getErrorMessage === 'function') {
                return window.Request.getErrorMessage(error, 'Operazione non riuscita.');
            }
            if (error && typeof error.message === 'string' && error.message.trim() !== '') {
                return error.message.trim();
            }
            return 'Operazione non riuscita.';
        },

        showModal: function (id) {
            var node = document.getElementById(id);
            if (!node) { return; }
            if (window.bootstrap && window.bootstrap.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(node).show();
                return;
            }
            if (typeof window.$ === 'function') {
                window.$(node).modal('show');
            }
        },

        hideModal: function (id) {
            var node = document.getElementById(id);
            if (!node) { return; }
            if (window.bootstrap && window.bootstrap.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(node).hide();
                return;
            }
            if (typeof window.$ === 'function') {
                window.$(node).modal('hide');
            }
        },

        hideConfirm: function () {
            if (window.SystemDialogs && typeof window.SystemDialogs.ensureGeneralConfirm === 'function') {
                var dialog = window.SystemDialogs.ensureGeneralConfirm();
                if (dialog && typeof dialog.hide === 'function') {
                    dialog.hide();
                    return;
                }
            }
            if (window.generalConfirm && typeof window.generalConfirm.hide === 'function') {
                window.generalConfirm.hide();
            }
        },

        toMysqlDate: function (value) {
            var raw = String(value || '').trim();
            if (!raw) { return null; }
            if (raw.indexOf('T') !== -1) {
                return raw.replace('T', ' ') + ':00';
            }
            return raw;
        },

        debounce: function (key, delay, fn) {
            key = String(key || '');
            if (!key || typeof fn !== 'function') { return; }
            if (this.debounceTimers[key]) {
                window.clearTimeout(this.debounceTimers[key]);
                this.debounceTimers[key] = null;
            }
            this.debounceTimers[key] = window.setTimeout(function () { fn(); }, Math.max(0, parseInt(delay, 10) || 0));
        },

        statusLabel: function (status) {
            var map = {
                draft: 'Bozza',
                published: 'Pubblicata',
                archived: 'Archiviata',
                locked: 'Bloccata',
                available: 'Disponibile',
                active: 'Attiva',
                completed: 'Completata',
                failed: 'Fallita',
                cancelled: 'Annullata',
                expired: 'Scaduta'
            };
            var key = String(status || '').toLowerCase();
            return map[key] || (key || '-');
        },

        statusBadge: function (status) {
            var map = {
                draft: 'text-bg-secondary',
                published: 'text-bg-success',
                archived: 'text-bg-dark',
                locked: 'text-bg-secondary',
                available: 'text-bg-info',
                active: 'text-bg-primary',
                completed: 'text-bg-success',
                failed: 'text-bg-danger',
                cancelled: 'text-bg-warning',
                expired: 'text-bg-dark'
            };
            return map[String(status || '').toLowerCase()] || 'text-bg-secondary';
        },

        scopeLabel: function (scope) {
            var map = {
                world: 'Mondo',
                character: 'Personaggio',
                faction: 'Fazione',
                guild: 'Gilda',
                map: 'Mappa',
                location: 'Luogo'
            };
            return map[String(scope || '').toLowerCase()] || (String(scope || '') || '-');
        },

        stateLabelById: function (stateId) {
            stateId = parseInt(stateId || '0', 10) || 0;
            if (stateId <= 0) { return ''; }
            var key = String(stateId);
            if (this.narrativeStateLookup && this.narrativeStateLookup[key]) {
                return String(this.narrativeStateLookup[key]);
            }
            return 'Stato #' + key;
        },

        visibilityLabel: function (visibility) {
            var map = {
                public: 'Pubblica',
                private: 'Privata',
                staff_only: 'Solo staff',
                hidden: 'Nascosta'
            };
            return map[String(visibility || '').toLowerCase()] || (String(visibility || '') || '-');
        },

        intensityLabel: function (level) {
            var map = {
                CHILL: 'Chill',
                SOFT: 'Soft',
                STANDARD: 'Standard',
                HIGH: 'High',
                CRITICAL: 'Critical'
            };
            var key = String(level || '').trim().toUpperCase();
            return map[key] || (key || 'Standard');
        },

        intensityVisibilityLabel: function (visibility) {
            var map = { visible: 'Si', hidden: 'No' };
            var key = String(visibility || '').trim().toLowerCase();
            return map[key] || (key === 'hidden' ? 'No' : 'Si');
        },

        intensityBadge: function (level) {
            var key = String(level || '').trim().toUpperCase();
            var tone = {
                CHILL: 'text-bg-success',
                SOFT: 'text-bg-info',
                STANDARD: 'text-bg-warning',
                HIGH: 'text-bg-primary',
                CRITICAL: 'text-bg-danger'
            };
            return '<span class="badge ' + (tone[key] || 'text-bg-secondary') + '">' + this.escapeHtml(this.intensityLabel(key || 'STANDARD')) + '</span>';
        },

        questTypeLabel: function (type) {
            var map = {
                personal: 'Personale',
                faction: 'Fazione',
                guild: 'Gilda',
                world: 'Mondo',
                storyline: 'Trama',
                event: 'Evento'
            };
            return map[String(type || '').toLowerCase()] || (String(type || '') || '-');
        },

        conditionTypeLabel: function (type) {
            var map = {
                'manual_staff': 'Manuale staff',
                'narrative.event.created': 'Evento narrativo',
                'system_event.status_changed': 'Evento di sistema',
                'faction.membership.changed': 'Appartenenza fazione',
                'lifecycle.phase.entered': 'Ciclo vita',
                'presence.position_changed': 'Cambio posizione',
                'quest_lifecycle': 'Ciclo vita quest'
            };
            return map[String(type || '')] || (String(type || '') || '-');
        },

        operatorLabel: function (op) {
            var map = {
                eq: 'Uguale (=)',
                ne: 'Diverso (\u2260)',
                'in': 'Incluso in',
                not_in: 'Escluso da',
                gt: 'Maggiore di',
                gte: 'Magg. o uguale',
                lt: 'Minore di',
                lte: 'Min. o uguale',
                contains: 'Contiene'
            };
            return map[String(op || '').toLowerCase()] || (String(op || '') || '-');
        },

        stepTypeLabel: function (type) {
            var map = {
                action: 'Azione',
                dialogue: 'Dialogo',
                checkpoint: 'Checkpoint',
                system: 'Sistema'
            };
            return map[String(type || '').toLowerCase()] || (String(type || '') || '-');
        },

        triggerTypeLabel: function (type) {
            var map = {
                step_completed: 'Step completato',
                quest_completed: 'Quest completata',
                quest_failed: 'Quest fallita',
                manual_staff: 'Manuale staff'
            };
            return map[String(type || '')] || (String(type || '') || '-');
        },

        outcomeTypeLabel: function (type) {
            var map = {
                log_progress: 'Registra progressione',
                notify: 'Notifica',
                create_narrative_event: 'Crea evento narrativo',
                unlock_quest: 'Sblocca quest',
                complete_quest: 'Completa quest',
                fail_quest: 'Fallisci quest',
                apply_narrative_state: 'Applica stato narrativo',
                remove_narrative_state: 'Rimuovi stato narrativo'
            };
            return map[String(type || '')] || (String(type || '') || '-');
        },

        linkTypeLabel: function (type) {
            var map = {
                contextualized_by: 'Contestualizzata da',
                source_of: 'Fonte di',
                triggered_by: 'Innescata da',
                related_to: 'Correlata a'
            };
            return map[String(type || '')] || (String(type || '') || '-');
        },

        logTypeLabel: function (type) {
            var map = {
                custom_log: 'Log personalizzato',
                quest_status_changed: 'Cambio stato quest',
                step_status_changed: 'Cambio stato step',
                outcome_applied: 'Esito applicato',
                condition_matched: 'Condizione soddisfatta',
                staff_override: 'Override staff',
                maintenance: 'Manutenzione'
            };
            return map[String(type || '')] || (String(type || '') || '-');
        },

        escapeHtml: function (value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        },

        renderQuestModalTagCheckboxes: function (selectedIds) {
            var form = document.getElementById('admin-quest-form');
            if (!form) { return; }
            var box = form.querySelector('[data-role="admin-quest-tags-checkboxes"]');
            if (!box) { return; }
            var filterInput = form.querySelector('[data-role="admin-quest-tags-filter"]');
            var search = filterInput ? String(filterInput.value || '').trim().toLowerCase() : '';
            var selected = {};
            for (var i = 0; i < (selectedIds || []).length; i += 1) {
                var sid = parseInt(selectedIds[i] || '0', 10) || 0;
                if (sid > 0) { selected[sid] = true; }
            }
            var rows = this.tagsCatalog.filter(function (row) {
                if (!search) { return true; }
                var text = String((row.label || '') + ' ' + (row.slug || '')).toLowerCase();
                return text.indexOf(search) !== -1;
            });
            if (rows.length === 0) {
                box.innerHTML = '<span class="small text-muted">' + (this.tagsCatalog.length === 0 ? 'Nessun tag disponibile.' : 'Nessun tag trovato.') + '</span>';
                return;
            }
            var html = '';
            for (var j = 0; j < rows.length; j += 1) {
                var row = rows[j];
                var id = parseInt(row.id || '0', 10) || 0;
                if (id <= 0) { continue; }
                var checked = selected[id] ? ' checked' : '';
                html += '<div class="form-check">'
                    + '<input class="form-check-input" type="checkbox" id="admin-quest-tag-' + id + '" data-role="admin-quest-tag-checkbox" value="' + id + '"' + checked + '>'
                    + '<label class="form-check-label small" for="admin-quest-tag-' + id + '">' + this.escapeHtml(row.label || row.slug || ('Tag #' + id)) + '</label>'
                    + '</div>';
            }
            box.innerHTML = html;
        },

        collectQuestModalTagIds: function () {
            var form = document.getElementById('admin-quest-form');
            if (!form) { return []; }
            var checkboxes = form.querySelectorAll('[data-role="admin-quest-tag-checkbox"]:checked');
            var ids = [];
            for (var i = 0; i < checkboxes.length; i += 1) {
                var id = parseInt(checkboxes[i].value || '0', 10) || 0;
                if (id > 0) { ids.push(id); }
            }
            return ids;
        },

        loadQuestModalTags: function (questId) {
            var self = this;
            if (!(questId > 0)) {
                this.renderQuestModalTagCheckboxes([]);
                return;
            }
            this.request('/admin/narrative-tags/entity/get', {
                entity_type: 'quest_definition',
                entity_id: questId
            }, function (response) {
                var rows = Array.isArray(response && response.dataset ? response.dataset : null) ? response.dataset : [];
                var ids = [];
                for (var i = 0; i < rows.length; i += 1) {
                    var id = parseInt((rows[i] || {}).id || '0', 10) || 0;
                    if (id > 0) { ids.push(id); }
                }
                self.renderQuestModalTagCheckboxes(ids);
            }, function () {
                self.renderQuestModalTagCheckboxes([]);
            });
        },

        syncQuestModalTags: function (questId, tagIds, onDone) {
            if (!(questId > 0)) {
                if (typeof onDone === 'function') { onDone(); }
                return;
            }
            this.request('/admin/narrative-tags/entity/sync', {
                entity_type: 'quest_definition',
                entity_id: questId,
                tag_ids: tagIds
            }, function () {
                if (typeof onDone === 'function') { onDone(); }
            }, function () {
                if (typeof onDone === 'function') { onDone(); }
            });
        },

        findRowByTrigger: function (trigger) {
            var id = parseInt(trigger.getAttribute('data-id') || '0', 10) || 0;
            return id > 0 ? (this.rowsById[id] || { id: id }) : null;
        }
    };

    window.AdminQuests = AdminQuests;
})();
