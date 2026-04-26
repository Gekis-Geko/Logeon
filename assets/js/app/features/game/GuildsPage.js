const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function resolveModule(name) {
    if (!globalWindow.RuntimeBootstrap || typeof globalWindow.RuntimeBootstrap.resolveAppModule !== 'function') {
        return null;
    }
    try {
        return globalWindow.RuntimeBootstrap.resolveAppModule(name);
    } catch (error) {
        return null;
    }
}


function normalizeGuildsError(error, fallback) {
    if (globalWindow.GameFeatureError && typeof globalWindow.GameFeatureError.normalize === 'function') {
        return globalWindow.GameFeatureError.normalize(error, fallback || 'Operazione non riuscita.');
    }
    if (typeof error === 'string' && error.trim() !== '') {
        return error.trim();
    }
    if (error && typeof error.message === 'string' && error.message.trim() !== '') {
        return error.message.trim();
    }
    if (error && typeof error.error === 'string' && error.error.trim() !== '') {
        return error.error.trim();
    }
    return fallback || 'Operazione non riuscita.';
}

function callGuildsModule(method, payload, onSuccess, onError) {
    if (typeof resolveModule !== 'function') {
        if (typeof onError === 'function') {
            onError(new Error('Guilds module resolver not available: ' + method));
        }
        return false;
    }

    var guildsModule = resolveModule('game.guilds');
    if (!guildsModule || typeof guildsModule[method] !== 'function') {
        if (typeof onError === 'function') {
            onError(new Error('Guilds module method not available: ' + method));
        }
        return false;
    }

    guildsModule[method](payload || {}).then(function (response) {
        if (typeof onSuccess === 'function') {
            onSuccess(response);
        }
    }).catch(function (error) {
        if (typeof onError === 'function') {
            onError(error);
        }
    });

    return true;
}
function GameGuildsPage(extension) {
        let page = {
            dataset: [],
            init: function () {
                if (!$('#guilds-page').length) {
                    return this;
                }

                this.load();
                return this;
            },
            load: function () {
                var self = this;
                callGuildsModule('list', null, function (response) {
                    self.dataset = response.dataset || [];
                    self.build();
                }, function (error) {
                    Toast.show({
                        body: normalizeGuildsError(error, 'Impossibile caricare le gilde.'),
                        type: 'error'
                    });
                });
            },
            build: function () {
                let block = $('#guilds-list').empty();
                if (!this.dataset || this.dataset.length === 0) {
                    block.append('<div class="col-12"><div class="alert alert-info">Nessuna gilda disponibile.</div></div>');
                    return;
                }

                for (var i in this.dataset) {
                    let guild = this.dataset[i];
                    let template = $($('template[name="template_guilds_list"]').html());
                    let image = (guild.image && guild.image !== '') ? guild.image : '/assets/imgs/defaults-images/default-location.png';
                    let alignment = guild.alignment_name ? ('Allineamento: ' + guild.alignment_name) : 'Allineamento non definito';
                    let members = (guild.members_count != null) ? ('Membri: ' + guild.members_count) : '';

                    template.find('[name="image"]').attr('src', image);
                    template.find('[name="name"]').text(guild.name || '');
                    template.find('[name="alignment"]').text(alignment);
                    template.find('[name="members"]').text(members);

                    let badge = template.find('[name="badge"]');
                    if (guild.is_member) {
                        badge.text('Membro').removeClass('d-none');
                    } else if (guild.is_visible != null && parseInt(guild.is_visible, 10) === 0) {
                        badge.text('Riservata').removeClass('d-none');
                    } else {
                        badge.addClass('d-none');
                    }

                    template.find('[name="open"]').attr('href', '/game/guilds/' + guild.id);
                    template.appendTo(block);
                }
            }
        };

        let guilds = Object.assign({}, page, extension);
        return guilds.init();
    
}

function GameGuildPage(extension) {
        let page = {
            guild_id: null,
            guild: null,
            requirements: [],
            requirements_missing: [],
            membership: null,
            membership_count: 0,
            is_member: false,
            can_apply: false,
            can_claim_salary: false,
            members: [],
            applications: [],
            roles: [],
            roles_loaded: false,
            announcements: [],
            events: [],
            logs: [],
            announcementsPaginator: null,
            eventsPaginator: null,
            logsPaginator: null,
            announcement_pin_ready: false,
            announcementPinSwitch: null,
            memberActionTarget: null,
            requirementSocialStatuses: [],
            requirementJobs: [],
            requirementSocialStatusMap: {},
            requirementJobsMap: {},
            requirementOptionsLoaded: false,
            init: function () {
                let container = $('#guild-page');
                if (!container.length) {
                    return this;
                }

                this.guild_id = container.attr('data-guild-id');
                if (!this.guild_id) {
                    return this;
                }
                this.bindActions();
                this.load();
                return this;
            },
            showModal: function (selector) {
                let element = null;
                if (typeof selector === 'string') {
                    element = document.querySelector(selector);
                } else {
                    element = selector;
                }
                if (!element) {
                    return;
                }

                if (globalWindow.bootstrap && globalWindow.bootstrap.Modal) {
                    globalWindow.bootstrap.Modal.getOrCreateInstance(element).show();
                    return;
                }

                if (typeof $ === 'function') {
                    $(element).modal('show');
                }
            },
            hideModal: function (selector) {
                let element = null;
                if (typeof selector === 'string') {
                    element = document.querySelector(selector);
                } else {
                    element = selector;
                }
                if (!element) {
                    return;
                }

                if (globalWindow.bootstrap && globalWindow.bootstrap.Modal) {
                    globalWindow.bootstrap.Modal.getOrCreateInstance(element).hide();
                    return;
                }

                if (typeof $ === 'function') {
                    $(element).modal('hide');
                }
            },
            isLeader: function () {
                return !!(this.membership && parseInt(this.membership.is_leader, 10) === 1);
            },
            isOfficer: function () {
                return !!(this.membership && parseInt(this.membership.is_officer, 10) === 1);
            },
            bindActions: function () {
                var self = this;
                $('#guild-apply-submit').on('click', function () {
                    self.apply();
                });
                $('#guild-claim-salary').on('click', function () {
                    self.claimSalary();
                });
                $('#guild-primary-toggle').on('click', function () {
                    self.setPrimary();
                });
                $('#guild-announcement-submit').on('click', function () {
                    self.createAnnouncement();
                });
                $('#guild-event-submit').on('click', function () {
                    self.createEvent();
                });
                $('#guild-doc-purpose-open').on('click', function () {
                    self.showModal('#guild-doc-purpose-modal');
                });
                $('#guild-doc-statute-open').on('click', function () {
                    self.showModal('#guild-doc-statute-modal');
                });
                $('#guild-doc-objectives-open').on('click', function () {
                    self.showModal('#guild-doc-objectives-modal');
                });
                $('#guild-announcement-open').on('click', function () {
                    self.openAnnouncementModal();
                });
                $('#guild-event-open').on('click', function () {
                    self.openEventModal();
                });
                $('#guild-requirement-open').on('click', function () {
                    self.openRequirementModal(null);
                });
                $('#guild-requirement-save').on('click', function () {
                    self.saveRequirement();
                });
                $('#guild-requirement-delete').on('click', function () {
                    self.deleteRequirementFromModal();
                });
                $('#guild-requirement-type').on('change', function () {
                    self.syncRequirementTypeUI();
                });
                $('#guild-requirement-value-fame').on('input change', function () {
                    self.syncRequirementValueHidden();
                });
                $('#guild-requirement-value-social').on('change', function () {
                    self.syncRequirementValueHidden();
                });
                $('#guild-requirement-value-job').on('change', function () {
                    self.syncRequirementValueHidden();
                });
                $('#guild-member-actions-save').on('click', function () {
                    self.applyMemberRoleChange();
                });
                $('#guild-member-actions-kick').on('click', function () {
                    if (self.memberActionTarget && self.memberActionTarget.character_id) {
                        self.hideModal('#guild-member-actions-modal');
                        self.directKick(self.memberActionTarget.character_id);
                    }
                });
                $('#guild-member-actions-kick-request').on('click', function () {
                    if (self.memberActionTarget && self.memberActionTarget.character_id) {
                        self.hideModal('#guild-member-actions-modal');
                        self.requestKick(self.memberActionTarget.character_id);
                    }
                });
            },
            togglePaginatorNav: function (selector, paginator) {
                let nav = $(selector);
                if (!nav.length) {
                    return;
                }
                if (!paginator || !paginator.nav) {
                    nav.addClass('d-none').empty();
                    return;
                }
                let total = parseInt((paginator.nav.tot && paginator.nav.tot.count) || 0, 10) || 0;
                let results = parseInt(paginator.nav.results, 10) || 1;
                nav.toggleClass('d-none', total <= results);
            },
            buildLocalPageSlice: function (dataset, criteria, fallbackResults) {
                let rows = Array.isArray(dataset) ? dataset : [];
                let results = parseInt(criteria.results, 10);
                if (!results || results < 1) {
                    results = fallbackResults || 5;
                }
                let total = rows.length;
                let totalPages = total > 0 ? Math.ceil(total / results) : 1;
                let page = parseInt(criteria.page, 10);
                if (!page || page < 1) {
                    page = 1;
                }
                if (page > totalPages) {
                    page = totalPages;
                }
                let start = (page - 1) * results;

                return {
                    rows: rows.slice(start, start + results),
                    total: total,
                    page: page,
                    results: results
                };
            },
            refreshLocalPaginator: function (paginator, fallbackResults) {
                if (!paginator || typeof paginator.loadByCriteria !== 'function') {
                    return;
                }
                let nav = paginator.nav || {};
                paginator.loadByCriteria({
                    query: nav.query || {},
                    page: nav.page || 1,
                    results: nav.results || fallbackResults || 5,
                    orderBy: nav.orderBy || ''
                });
            },
            ensureAnnouncementsPaginator: function () {
                if (this.announcementsPaginator || typeof Paginator !== 'function') {
                    return;
                }
                let self = this;
                let paginator = new Paginator();
                paginator.urlupdate = false;
                paginator.range = 2;
                paginator.div = '#guild-announcements-pagination';
                paginator.onDatasetUpdate = function () {
                    self.renderAnnouncements(this.dataset || []);
                };
                paginator.loadByCriteria = function (criteria) {
                    let normalized = this.normalizeCriteria(criteria, this.nav);
                    let slice = self.buildLocalPageSlice(self.announcements, normalized, 5);
                    this.complete({
                        properties: {
                            query: normalized.query || {},
                            page: slice.page,
                            results: slice.results,
                            orderBy: normalized.orderBy || '',
                            tot: { count: slice.total }
                        },
                        dataset: slice.rows
                    });
                    self.togglePaginatorNav('#guild-announcements-pagination', this);
                    return this;
                };
                paginator.setNav({
                    query: {},
                    orderBy: '',
                    page: 1,
                    results: 5,
                    tot: { count: 0 }
                });
                this.announcementsPaginator = paginator;
            },
            ensureEventsPaginator: function () {
                if (this.eventsPaginator || typeof Paginator !== 'function') {
                    return;
                }
                let self = this;
                let paginator = new Paginator();
                paginator.urlupdate = false;
                paginator.range = 2;
                paginator.div = '#guild-events-pagination';
                paginator.onDatasetUpdate = function () {
                    self.renderEvents(this.dataset || []);
                };
                paginator.loadByCriteria = function (criteria) {
                    let normalized = this.normalizeCriteria(criteria, this.nav);
                    let slice = self.buildLocalPageSlice(self.events, normalized, 5);
                    this.complete({
                        properties: {
                            query: normalized.query || {},
                            page: slice.page,
                            results: slice.results,
                            orderBy: normalized.orderBy || '',
                            tot: { count: slice.total }
                        },
                        dataset: slice.rows
                    });
                    self.togglePaginatorNav('#guild-events-pagination', this);
                    return this;
                };
                paginator.setNav({
                    query: {},
                    orderBy: '',
                    page: 1,
                    results: 5,
                    tot: { count: 0 }
                });
                this.eventsPaginator = paginator;
            },
            ensureLogsPaginator: function () {
                if (this.logsPaginator || typeof Paginator !== 'function') {
                    return;
                }
                let self = this;
                let paginator = new Paginator();
                paginator.urlupdate = false;
                paginator.range = 2;
                paginator.div = '#guild-logs-pagination';
                paginator.onDatasetUpdate = function () {
                    self.renderLogs(this.dataset || []);
                };
                paginator.loadByCriteria = function (criteria) {
                    let normalized = this.normalizeCriteria(criteria, this.nav);
                    let slice = self.buildLocalPageSlice(self.logs, normalized, 5);
                    this.complete({
                        properties: {
                            query: normalized.query || {},
                            page: slice.page,
                            results: slice.results,
                            orderBy: normalized.orderBy || '',
                            tot: { count: slice.total }
                        },
                        dataset: slice.rows
                    });
                    self.togglePaginatorNav('#guild-logs-pagination', this);
                    return this;
                };
                paginator.setNav({
                    query: {},
                    orderBy: '',
                    page: 1,
                    results: 5,
                    tot: { count: 0 }
                });
                this.logsPaginator = paginator;
            },
            load: function () {
                var self = this;
                callGuildsModule('get', { id: self.guild_id }, function (response) {
                    self.guild = response.guild || null;
                    self.requirements = response.requirements || [];
                    self.requirements_missing = response.requirements_missing || [];
                    self.membership = response.membership || null;
                    self.membership_count = response.membership_count || 0;
                    self.is_member = response.is_member ? true : false;
                    self.can_apply = response.can_apply ? true : false;
                    self.can_claim_salary = response.can_claim_salary ? true : false;

                    self.build();
                    self.loadMembers();
                    if (self.is_member) {
                        self.loadAnnouncements();
                        self.loadEvents();
                    }

                    if (self.membership && self.membership.is_leader) {
                        self.loadRoles(function () {
                            self.loadApplications();
                        });
                    } else if (self.membership && self.membership.is_officer) {
                        self.loadApplications();
                    } else {
                        $('#guild-applications-panel').addClass('d-none');
                    }

                    if (self.membership && (self.membership.is_leader || self.membership.is_officer)) {
                        self.loadLogs();
                    } else {
                        $('#guild-logs-panel').addClass('d-none');
                    }
                }, function (error) {
                    Toast.show({
                        body: normalizeGuildsError(error, 'Impossibile caricare la gilda.'),
                        type: 'error'
                    });
                });
            },
            loadRoles: function (onDone) {
                var self = this;
                if (self.roles_loaded) {
                    if (typeof onDone === 'function') {
                        onDone();
                    }
                    return;
                }
                callGuildsModule('roles', { guild_id: self.guild_id }, function (response) {
                    self.roles = response.dataset || [];
                    self.roles_loaded = true;
                    self.buildMembers();
                    if (typeof onDone === 'function') {
                        onDone();
                    }
                }, function () {
                    self.roles_loaded = true;
                    if (typeof onDone === 'function') {
                        onDone();
                    }
                });
            },
            build: function () {
                let guild = this.guild || {};
                let hero = $('#guild-hero');
                let image = (guild.image && guild.image !== '') ? guild.image : '/assets/imgs/defaults-images/default-location.png';
                hero.find('[name="guild_image"]').attr('src', image);
                hero.find('[name="guild_name"]').text(guild.name || '');
                hero.find('[name="guild_alignment"]').text(guild.alignment_name ? ('Allineamento: ' + guild.alignment_name) : '');
                hero.find('[name="guild_members_count"]').text('Membri: --');

                let website = hero.find('[name="guild_website"]');
                if (guild.website_url && guild.website_url !== '') {
                    website.attr('href', guild.website_url).removeClass('d-none');
                } else {
                    website.addClass('d-none');
                }

                let purpose = guild.purpose_html ? guild.purpose_html : '<span class="text-muted">Nessuna informazione.</span>';
                let statute = guild.statute_html ? guild.statute_html : '<span class="text-muted">Nessuna informazione.</span>';
                let objectives = guild.objectives_html ? guild.objectives_html : '<span class="text-muted">Nessuna informazione.</span>';

                $('[name="guild_purpose"]').html(purpose);
                $('[name="guild_statute"]').html(statute);
                $('[name="guild_objectives"]').html(objectives);

                this.buildRequirements();
                this.buildMembership();
                this.buildApplyPanel();

                if (this.isLeader()) {
                    $('#guild-announcement-open').removeClass('d-none');
                    $('#guild-event-open').removeClass('d-none');
                    $('#guild-requirement-open').removeClass('d-none');
                    this.initAnnouncementPin();
                } else {
                    $('#guild-announcement-open').addClass('d-none');
                    $('#guild-event-open').addClass('d-none');
                    $('#guild-requirement-open').addClass('d-none');
                }
            },
            buildRequirements: function () {
                let block = $('#guild-requirements').empty();
                let canManage = this.isLeader();
                let missingMap = {};

                if (Array.isArray(this.requirements_missing)) {
                    for (var mi in this.requirements_missing) {
                        let token = this.normalizeRequirementToken(this.requirements_missing[mi]);
                        if (token !== '') {
                            missingMap[token] = true;
                        }
                    }
                }

                if (!this.requirements || this.requirements.length === 0) {
                    block.append('<div class="text-muted">Nessun requisito.</div>');
                } else {
                    let list = $('<ul class="list-group list-group-flush"></ul>');
                    for (var i in this.requirements) {
                        let req = this.requirements[i];
                        let item = $('<li class="list-group-item small d-flex justify-content-between align-items-center gap-2"></li>');

                        let labelWrap = $('<div class="d-flex align-items-center"></div>');
                        if (this.isRequirementMissing(req, missingMap)) {
                            labelWrap.append('<i class="bi bi-exclamation-triangle-fill text-warning me-2" data-bs-toggle="tooltip" data-bs-title="Requisito mancante" title="Requisito mancante"></i>');
                        }
                        labelWrap.append($('<span></span>').text(this.formatRequirement(req)));
                        item.append(labelWrap);

                        if (canManage) {
                            let actions = $('<div class="d-flex gap-1"></div>');
                            let editBtn = $('<button type="button" class="btn btn-sm btn-outline-secondary" title="Modifica requisito"><i class="bi bi-pencil-square"></i></button>');
                            editBtn.on('click', this.openRequirementModal.bind(this, req));
                            let delBtn = $('<button type="button" class="btn btn-sm btn-outline-danger" title="Elimina requisito"><i class="bi bi-trash"></i></button>');
                            delBtn.on('click', this.deleteRequirement.bind(this, req.id));
                            actions.append(editBtn).append(delBtn);
                            item.append(actions);
                        }

                        list.append(item);
                    }
                    block.append(list);

                    if (globalWindow.bootstrap && globalWindow.bootstrap.Tooltip) {
                        block.find('[data-bs-toggle="tooltip"]').each(function () {
                            globalWindow.bootstrap.Tooltip.getOrCreateInstance(this);
                        });
                    }
                }
            },
            formatRequirement: function (req) {
                if (req.label && req.label !== '') {
                    return req.label;
                }
                if (req.type === 'min_fame') {
                    return 'Fama minima: ' + req.value;
                }
                if (req.type === 'min_socialstatus_id') {
                    return 'Stato sociale richiesto: ' + this.resolveRequirementOptionLabel('social', req.value);
                }
                if (req.type === 'job_id') {
                    return 'Lavoro richiesto: ' + this.resolveRequirementOptionLabel('job', req.value);
                }
                if (req.type === 'no_job') {
                    return 'Nessun lavoro attivo';
                }
                return (req.type || 'Requisito') + (req.value != null ? (': ' + req.value) : '');
            },
            resolveRequirementOptionLabel: function (kind, value) {
                let numericValue = parseInt(value, 10);
                if (!numericValue || numericValue <= 0) {
                    return '-';
                }

                let key = String(numericValue);
                if (kind === 'social') {
                    return this.requirementSocialStatusMap[key] || ('Stato #' + key);
                }
                if (kind === 'job') {
                    return this.requirementJobsMap[key] || ('Lavoro #' + key);
                }
                return key;
            },
            normalizeRequirementToken: function (value) {
                return String(value || '').trim().toLowerCase().replace(/\\s+/g, ' ');
            },
            defaultRequirementMissingLabel: function (req) {
                if (!req || !req.type) {
                    return '';
                }
                if (req.type === 'min_fame') {
                    return 'Fama minima: ' + (req.value || '');
                }
                if (req.type === 'min_socialstatus_id') {
                    return 'Stato sociale richiesto';
                }
                if (req.type === 'job_id') {
                    return 'Lavoro richiesto';
                }
                if (req.type === 'no_job') {
                    return 'Non devi avere un lavoro attivo';
                }
                return '';
            },
            isRequirementMissing: function (req, missingMap) {
                if (!missingMap) {
                    return false;
                }

                let candidates = [];
                candidates.push(this.formatRequirement(req));
                if (req && req.label) {
                    candidates.push(req.label);
                }
                let fallback = this.defaultRequirementMissingLabel(req);
                if (fallback !== '') {
                    candidates.push(fallback);
                }

                for (var i = 0; i < candidates.length; i++) {
                    let token = this.normalizeRequirementToken(candidates[i]);
                    if (token !== '' && missingMap[token]) {
                        return true;
                    }
                }

                return false;
            },
            openRequirementModal: function (req) {
                var self = this;
                req = req || null;

                this.loadRequirementOptions(function () {
                    $('#guild-requirement-id').val(req ? (req.id || '') : '');
                    $('#guild-requirement-type').val(req ? (req.type || 'min_fame') : 'min_fame');
                    $('#guild-requirement-value').val(req ? (req.value || '') : '');
                    $('#guild-requirement-value-fame').val('');
                    $('#guild-requirement-value-social').val('');
                    $('#guild-requirement-value-job').val('');
                    $('#guild-requirement-label').val(req ? (req.label || '') : '');
                    $('#guild-requirement-delete').toggleClass('d-none', !(req && req.id));
                    self.syncRequirementTypeUI();
                    self.showModal('#guild-requirement-modal');
                });
            },
            loadRequirementOptions: function (onDone) {
                var self = this;
                if (self.requirementOptionsLoaded) {
                    if (typeof onDone === 'function') {
                        onDone();
                    }
                    return;
                }

                callGuildsModule('requirementOptions', {
                    guild_id: self.guild_id
                }, function (response) {
                    let dataset = response && response.dataset ? response.dataset : {};
                    self.requirementSocialStatuses = Array.isArray(dataset.social_statuses) ? dataset.social_statuses : [];
                    self.requirementJobs = Array.isArray(dataset.jobs) ? dataset.jobs : [];
                    self.requirementOptionsLoaded = true;
                    self.populateRequirementOptions();
                    if (typeof onDone === 'function') {
                        onDone();
                    }
                }, function () {
                    self.requirementSocialStatuses = [];
                    self.requirementJobs = [];
                    self.requirementOptionsLoaded = true;
                    self.populateRequirementOptions();
                    if (typeof onDone === 'function') {
                        onDone();
                    }
                });
            },
            populateRequirementOptions: function () {
                let socialSelect = $('#guild-requirement-value-social');
                let jobSelect = $('#guild-requirement-value-job');
                let socialCurrent = String(socialSelect.val() || '');
                let jobCurrent = String(jobSelect.val() || '');

                this.requirementSocialStatusMap = {};
                this.requirementJobsMap = {};

                socialSelect.empty().append('<option value="">Seleziona stato sociale...</option>');
                for (var si = 0; si < this.requirementSocialStatuses.length; si++) {
                    let status = this.requirementSocialStatuses[si] || {};
                    let id = parseInt(status.id, 10) || 0;
                    if (id <= 0) {
                        continue;
                    }
                    let label = String(status.name || ('Stato #' + id)).trim();
                    this.requirementSocialStatusMap[String(id)] = label;
                    socialSelect.append(
                        $('<option></option>').attr('value', String(id)).text(label)
                    );
                }

                jobSelect.empty().append('<option value="">Seleziona lavoro...</option>');
                for (var ji = 0; ji < this.requirementJobs.length; ji++) {
                    let job = this.requirementJobs[ji] || {};
                    let id = parseInt(job.id, 10) || 0;
                    if (id <= 0) {
                        continue;
                    }
                    let label = String(job.name || ('Lavoro #' + id)).trim();
                    this.requirementJobsMap[String(id)] = label;
                    jobSelect.append(
                        $('<option></option>').attr('value', String(id)).text(label)
                    );
                }

                if (socialCurrent !== '') {
                    socialSelect.val(socialCurrent);
                }
                if (jobCurrent !== '') {
                    jobSelect.val(jobCurrent);
                }
            },
            ensureRequirementSelectValue: function (kind, value) {
                let normalized = String(value || '').trim();
                if (normalized === '') {
                    return;
                }

                let select = kind === 'social'
                    ? $('#guild-requirement-value-social')
                    : $('#guild-requirement-value-job');
                if (!select.length) {
                    return;
                }

                if (select.find('option[value="' + normalized.replace(/"/g, '\\"') + '"]').length > 0) {
                    return;
                }

                let fallback = kind === 'social'
                    ? ('Stato #' + normalized)
                    : ('Lavoro #' + normalized);
                select.append(
                    $('<option></option>').attr('value', normalized).text(fallback)
                );
                if (kind === 'social') {
                    this.requirementSocialStatusMap[normalized] = fallback;
                } else {
                    this.requirementJobsMap[normalized] = fallback;
                }
            },
            syncRequirementValueHidden: function () {
                let type = String($('#guild-requirement-type').val() || '');
                if (type === 'min_fame') {
                    $('#guild-requirement-value').val(String($('#guild-requirement-value-fame').val() || '').trim());
                    return;
                }
                if (type === 'min_socialstatus_id') {
                    $('#guild-requirement-value').val(String($('#guild-requirement-value-social').val() || '').trim());
                    return;
                }
                if (type === 'job_id') {
                    $('#guild-requirement-value').val(String($('#guild-requirement-value-job').val() || '').trim());
                    return;
                }
                if (type === 'no_job') {
                    $('#guild-requirement-value').val('1');
                }
            },
            syncRequirementTypeUI: function () {
                let type = $('#guild-requirement-type').val();
                let valueWrap = $('#guild-requirement-value-wrap');
                let fieldFame = $('#guild-requirement-value-fame');
                let fieldSocial = $('#guild-requirement-value-social');
                let fieldJob = $('#guild-requirement-value-job');
                let hiddenValue = String($('#guild-requirement-value').val() || '').trim();

                fieldFame.addClass('d-none');
                fieldSocial.addClass('d-none');
                fieldJob.addClass('d-none');

                if (type === 'no_job') {
                    valueWrap.addClass('d-none');
                    this.syncRequirementValueHidden();
                } else {
                    valueWrap.removeClass('d-none');
                    if (type === 'min_fame') {
                        fieldFame.removeClass('d-none');
                        if (hiddenValue !== '') {
                            fieldFame.val(hiddenValue);
                        } else if (String(fieldFame.val() || '').trim() === '') {
                            fieldFame.val('0');
                        }
                    } else if (type === 'min_socialstatus_id') {
                        fieldSocial.removeClass('d-none');
                        this.ensureRequirementSelectValue('social', hiddenValue);
                        if (hiddenValue !== '') {
                            fieldSocial.val(hiddenValue);
                        }
                    } else if (type === 'job_id') {
                        fieldJob.removeClass('d-none');
                        this.ensureRequirementSelectValue('job', hiddenValue);
                        if (hiddenValue !== '') {
                            fieldJob.val(hiddenValue);
                        }
                    }
                    this.syncRequirementValueHidden();
                }
            },
            saveRequirement: function () {
                var self = this;
                let id = $('#guild-requirement-id').val();
                let type = $('#guild-requirement-type').val();
                this.syncRequirementValueHidden();
                let value = String($('#guild-requirement-value').val() || '').trim();
                let label = $('#guild-requirement-label').val();

                callGuildsModule('requirementUpsert', {
                    guild_id: self.guild_id,
                    id: id || null,
                    type: type,
                    value: value,
                    label: (typeof label === 'string' ? label.trim() : '')
                }, function (response) {
                    self.requirements = response.dataset || [];
                    self.buildRequirements();
                    self.hideModal('#guild-requirement-modal');
                    Toast.show({ body: 'Requisito salvato.', type: 'success' });
                }, function (error) {
                    Toast.show({ body: normalizeGuildsError(error, 'Errore durante salvataggio requisito.'), type: 'error' });
                });
            },
            deleteRequirement: function (id) {
                var self = this;
                if (!id) {
                    return;
                }
                Dialog('danger', {
                    title: 'Eliminazione requisito',
                    body: '<p>Vuoi davvero eliminare questo requisito?</p>'
                }, function () {
                    var dialog = this;
                    callGuildsModule('requirementDelete', {
                        guild_id: self.guild_id,
                        id: id
                    }, function (response) {
                        dialog.hide();
                        self.requirements = response.dataset || [];
                        self.buildRequirements();
                        Toast.show({ body: 'Requisito eliminato.', type: 'success' });
                    }, function (error) {
                        if (dialog.setNormalStatus) {
                            dialog.setNormalStatus();
                        }
                        Toast.show({ body: normalizeGuildsError(error, 'Errore durante eliminazione requisito.'), type: 'error' });
                    });
                }).show();
            },
            deleteRequirementFromModal: function () {
                let id = $('#guild-requirement-id').val();
                if (!id) {
                    return;
                }
                this.hideModal('#guild-requirement-modal');
                this.deleteRequirement(id);
            },
            buildMembership: function () {
                let panel = $('#guild-membership-panel');
                let claimBtn = $('#guild-claim-salary');
                let status = $('#guild-salary-status');
                let bankStatus = $('#guild-bank-status');
                let roleBadge = panel.find('[name="membership_role_badge"]');
                if (this.is_member && this.membership) {
                    panel.removeClass('d-none');
                    let roleName = this.membership.role_name || 'Membro';
                    panel.find('[name="membership_role"]').text(roleName);
                    roleBadge.text(roleName);
                    if (this.membership.monthly_salary && parseInt(this.membership.monthly_salary, 10) > 0) {
                        panel.find('[name="membership_salary"]').text(this.membership.monthly_salary);
                    } else {
                        panel.find('[name="membership_salary"]').text('Nessuno');
                    }

                    let primaryLabel = panel.find('[name="membership_primary_label"]');
                    let primaryBtn = $('#guild-primary-toggle');
                    if (this.membership.is_primary && parseInt(this.membership.is_primary, 10) === 1) {
                        primaryLabel.text('Principale');
                        primaryBtn.addClass('d-none');
                    } else {
                        primaryLabel.text('Secondaria');
                        if (this.membership_count > 1) {
                            primaryBtn.removeClass('d-none');
                        } else {
                            primaryBtn.addClass('d-none');
                        }
                    }

                    if (this.can_claim_salary) {
                        claimBtn.prop('disabled', false);
                        status.removeClass('d-none').text('Stipendio disponibile.');
                    } else {
                        claimBtn.prop('disabled', true);
                        status.removeClass('d-none').text('Stipendio gia riscosso questo mese.');
                    }
                    bankStatus.addClass('d-none').text('');
                } else {
                    panel.addClass('d-none');
                }
            },
            buildApplyPanel: function () {
                let panel = $('#guild-apply-panel');
                let disabled = $('#guild-apply-disabled');
                let submit = $('#guild-apply-submit');
                if (this.is_member) {
                    panel.addClass('d-none');
                    return;
                }

                panel.removeClass('d-none');
                if (this.can_apply) {
                    submit.prop('disabled', false);
                    disabled.addClass('d-none').text('');
                } else {
                    submit.prop('disabled', true);
                    let msg = 'Non puoi candidarti al momento.';
                    if (this.requirements_missing && this.requirements_missing.length) {
                        msg = 'Requisiti mancanti: ' + this.requirements_missing.join(', ');
                    }
                    disabled.removeClass('d-none').text(msg);
                }
            },
            loadMembers: function () {
                var self = this;
                callGuildsModule('members', { guild_id: self.guild_id }, function (response) {
                    self.members = response.dataset || [];
                    self.buildMembers();
                }, function () {
                    self.members = [];
                    self.buildMembers();
                });
            },
            openMemberActions: function (member, name) {
                member = member || null;
                if (!member) {
                    return;
                }

                this.memberActionTarget = member;
                $('#guild-member-actions-target-id').val(member.character_id || '');
                $('#guild-member-actions-name').text(name || 'Membro');

                let isTargetLeader = parseInt(member.is_leader, 10) === 1;
                let canChangeRole = this.isLeader() && !isTargetLeader;
                let canDirectKick = this.isLeader() && !isTargetLeader;
                let canRequestKick = this.isOfficer() && !this.isLeader() && !isTargetLeader;

                let roleWrap = $('#guild-member-actions-role-wrap');
                let roleSelect = $('#guild-member-actions-role').empty();
                if (canChangeRole && this.roles && this.roles.length) {
                    let roleCount = 0;
                    for (var i in this.roles) {
                        let role = this.roles[i];
                        if (parseInt(role.is_leader, 10) === 1) {
                            continue;
                        }
                        let option = $('<option></option>').val(role.id).text(role.name);
                        if (String(role.id) === String(member.role_id)) {
                            option.prop('selected', true);
                        }
                        roleSelect.append(option);
                        roleCount++;
                    }
                    canChangeRole = roleCount > 0;
                } else {
                    canChangeRole = false;
                }

                roleWrap.toggleClass('d-none', !canChangeRole);
                $('#guild-member-actions-save').toggleClass('d-none', !canChangeRole);
                $('#guild-member-actions-kick').toggleClass('d-none', !canDirectKick);
                $('#guild-member-actions-kick-request').toggleClass('d-none', !canRequestKick);

                let hint = '';
                if (canChangeRole || canDirectKick || canRequestKick) {
                    hint = 'Seleziona un azione disponibile per questo membro.';
                } else {
                    hint = 'Nessuna azione disponibile su questo membro.';
                }
                $('#guild-member-actions-hint').text(hint);

                this.showModal('#guild-member-actions-modal');
            },
            applyMemberRoleChange: function () {
                if (!this.memberActionTarget || !this.memberActionTarget.character_id) {
                    return;
                }
                let roleId = $('#guild-member-actions-role').val();
                if (!roleId) {
                    Toast.show({
                        body: 'Seleziona un ruolo.',
                        type: 'error'
                    });
                    return;
                }
                this.hideModal('#guild-member-actions-modal');
                this.promote(this.memberActionTarget.character_id, roleId);
            },
            buildMembers: function () {
                let block = $('#guild-members').empty();
                let count = (this.members && this.members.length) ? this.members.length : 0;
                $('#guild-hero [name="guild_members_count"]').text('Membri: ' + count);

                if (!this.members || this.members.length === 0) {
                    block.append('<div class="text-muted">Nessun membro trovato.</div>');
                    return;
                }

                for (var i in this.members) {
                    let member = this.members[i];
                    let template = $($('template[name="template_guild_member"]').html());
                    let name = ((member.name || '') + ' ' + (member.surname || '')).trim();
                    let roleParts = [];
                    if (member.role_name) {
                        roleParts.push(member.role_name);
                    }
                    if (member.is_leader) {
                        roleParts.push('Capo');
                    } else if (member.is_officer) {
                        roleParts.push('Vice');
                    }
                    let roleHtml = '';
                    if (roleParts.length) {
                        let safe = [];
                        for (var rp in roleParts) {
                            safe.push($('<span></span>').text(roleParts[rp]).html());
                        }
                        roleHtml = safe.join(' <i class="bi bi-arrow-right-short"></i> ');
                    }
                    let avatar = (member.avatar && member.avatar !== '') ? member.avatar : '/assets/imgs/defaults-images/default-profile.png';

                    template.find('[name="name"]').text(name);
                    if (roleHtml !== '') {
                        template.find('[name="role"]').html(roleHtml);
                    } else {
                        template.find('[name="role"]').text('Ruolo non definito');
                    }
                    template.find('[name="avatar"]').attr('src', avatar);

                    let isTargetLeader = parseInt(member.is_leader, 10) === 1;
                    let isSelf = this.membership && String(this.membership.character_id) === String(member.character_id);
                    let canManage = !isSelf && !isTargetLeader && (this.isLeader() || this.isOfficer());

                    let manageBtn = template.find('[name="member_manage"]');
                    if (canManage) {
                        manageBtn.removeClass('d-none');
                        manageBtn.on('click', this.openMemberActions.bind(this, member, name));
                    } else {
                        manageBtn.addClass('d-none');
                    }

                    template.appendTo(block);
                }
            },
            loadApplications: function () {
                var self = this;
                callGuildsModule('applications', { guild_id: self.guild_id }, function (response) {
                    self.applications = response.dataset || [];
                    self.buildApplications();
                }, function () {
                    $('#guild-applications-panel').addClass('d-none');
                });
            },
            buildApplications: function () {
                let panel = $('#guild-applications-panel');
                let block = $('#guild-applications').empty();
                if (!this.applications || this.applications.length === 0) {
                    panel.addClass('d-none');
                    return;
                }

                panel.removeClass('d-none');
                for (var i in this.applications) {
                    let app = this.applications[i];
                    let template = $($('template[name="template_guild_application"]').html());
                    let name = ((app.name || '') + ' ' + (app.surname || '')).trim();
                    let date = app.date_created ? Dates().formatHumanDateTime(app.date_created) : '';
                    let message = app.message ? app.message : 'Nessun messaggio.';

                    template.find('[name="name"]').text(name);
                    template.find('[name="date"]').text(date);
                    template.find('[name="message"]').text(message);

                    let rolePicker = template.find('[name="role_picker"]');
                    let roleSelect = template.find('[name="role_select"]');
                    if (this.roles && this.roles.length) {
                        let roles = this.roles.filter(function (r) { return parseInt(r.is_leader, 10) !== 1; });
                        if (roles.length) {
                            rolePicker.removeClass('d-none');
                            for (var r in roles) {
                                let role = roles[r];
                                let opt = $('<option></option>').val(role.id).text(role.name);
                                if (parseInt(role.is_default, 10) === 1) {
                                    opt.prop('selected', true);
                                }
                                roleSelect.append(opt);
                            }
                            if (roleSelect.find('option:selected').length === 0) {
                                roleSelect.find('option').first().prop('selected', true);
                            }
                        }
                    }

                    template.find('[name="accept"]').on('click', this.decideApplication.bind(this, app.id, 'accept', roleSelect));
                    template.find('[name="decline"]').on('click', this.decideApplication.bind(this, app.id, 'decline'));

                    template.appendTo(block);
                }
            },
            decideApplication: function (application_id, action, roleSelect) {
                var self = this;
                var payload = {
                    application_id: application_id,
                    action: action
                };
                if (action === 'accept' && roleSelect && roleSelect.length) {
                    payload.role_id = roleSelect.val();
                }
                callGuildsModule('decideApplication', {
                    application_id: application_id,
                    action: action,
                    role_id: payload.role_id
                }, function () {
                    Toast.show({
                        body: 'Operazione completata.',
                        type: 'success'
                    });
                    self.loadApplications();
                    self.loadMembers();
                }, function (error) {
                    Toast.show({
                        body: normalizeGuildsError(error, 'Errore durante aggiornamento candidatura.'),
                        type: 'error'
                    });
                });
            },
            apply: function () {
                var self = this;
                if (!self.can_apply) {
                    return;
                }
                let message = $('#guild-apply-message').val().trim();
                callGuildsModule('apply', {
                    guild_id: self.guild_id,
                    message: message
                }, function () {
                    self.can_apply = false;
                    $('#guild-apply-submit').prop('disabled', true);
                    $('#guild-apply-disabled').removeClass('d-none').text('Candidatura inviata.');
                    Toast.show({
                        body: 'Candidatura inviata.',
                        type: 'success'
                    });
                }, function (error) {
                    Toast.show({
                        body: normalizeGuildsError(error, 'Errore durante invio candidatura.'),
                        type: 'error'
                    });
                });
            },
            claimSalary: function () {
                var self = this;
                callGuildsModule('claimSalary', {
                    guild_id: self.guild_id
                }, function (response) {
                    self.can_claim_salary = false;
                    self.buildMembership();
                    let bankStatus = $('#guild-bank-status');
                    if (response && typeof response.bank !== 'undefined') {
                        bankStatus.removeClass('d-none').text('Saldo banca aggiornato: ' + response.bank);
                    } else {
                        bankStatus.addClass('d-none').text('');
                    }
                    Toast.show({
                        body: 'Stipendio riscosso.',
                        type: 'success'
                    });
                }, function (error) {
                    Toast.show({
                        body: normalizeGuildsError(error, 'Errore durante riscatto stipendio.'),
                        type: 'error'
                    });
                });
            },
            setPrimary: function () {
                var self = this;
                callGuildsModule('setPrimary', {
                    guild_id: self.guild_id
                }, function () {
                    if (self.membership) {
                        self.membership.is_primary = 1;
                    }
                    self.buildMembership();
                    Toast.show({
                        body: 'Gilda principale aggiornata.',
                        type: 'success'
                    });
                }, function (error) {
                    Toast.show({
                        body: normalizeGuildsError(error, 'Errore durante aggiornamento gilda principale.'),
                        type: 'error'
                    });
                });
            },
            requestKick: function (target_id) {
                var self = this;
                Dialog('warning', {
                    title: 'Richiesta espulsione',
                    body: '<p>Motivo della richiesta di espulsione (opzionale):</p><textarea class="form-control" name="kick_reason" rows="3" placeholder="Scrivi il motivo..."></textarea>'
                }, function () {
                    var dialog = this;
                    let reason = dialog.dialog.modal_id.find('[name="kick_reason"]').val();
                    if (typeof reason === 'string') {
                        reason = reason.trim();
                    }
                    callGuildsModule('requestKick', {
                        guild_id: self.guild_id,
                        target_id: target_id,
                        reason: reason
                    }, function () {
                        dialog.hide();
                        Toast.show({
                            body: 'Richiesta inviata.',
                            type: 'success'
                        });
                    }, function (error) {
                        if (dialog.setNormalStatus) {
                            dialog.setNormalStatus();
                        }
                        Toast.show({
                            body: normalizeGuildsError(error, 'Errore durante invio richiesta.'),
                            type: 'error'
                        });
                    });
                }).show();
            },
            directKick: function (target_id) {
                var self = this;
                Dialog('danger', {
                    title: 'Espulsione membro',
                    body: '<p>Vuoi davvero espellere questo membro?</p>'
                }, function () {
                    var dialog = this;
                    callGuildsModule('directKick', {
                        guild_id: self.guild_id,
                        target_id: target_id
                    }, function () {
                        dialog.hide();
                        Toast.show({
                            body: 'Membro espulso.',
                            type: 'success'
                        });
                        self.loadMembers();
                    }, function (error) {
                        if (dialog.setNormalStatus) {
                            dialog.setNormalStatus();
                        }
                        Toast.show({
                            body: normalizeGuildsError(error, 'Errore durante espulsione.'),
                            type: 'error'
                        });
                    });
                }).show();
            },
            promote: function (target_id, role_id) {
                var self = this;
                callGuildsModule('promote', {
                    guild_id: self.guild_id,
                    target_id: target_id,
                    role_id: role_id
                }, function () {
                    Toast.show({
                        body: 'Ruolo aggiornato.',
                        type: 'success'
                    });
                    self.loadMembers();
                }, function (error) {
                    Toast.show({
                        body: normalizeGuildsError(error, 'Errore durante aggiornamento ruolo.'),
                        type: 'error'
                    });
                });
            },
            loadAnnouncements: function () {
                var self = this;
                callGuildsModule('announcements', { guild_id: self.guild_id }, function (response) {
                    self.announcements = response.dataset || [];
                    self.buildAnnouncements();
                }, function () {
                    self.announcements = [];
                    self.buildAnnouncements();
                });
            },
            buildAnnouncements: function () {
                if (typeof Paginator === 'function') {
                    this.ensureAnnouncementsPaginator();
                    this.refreshLocalPaginator(this.announcementsPaginator, 5);
                    return;
                }
                $('#guild-announcements-pagination').addClass('d-none').empty();
                this.renderAnnouncements(this.announcements || []);
            },
            renderAnnouncements: function (rows) {
                let block = $('#guild-announcements').empty();
                if (!rows || rows.length === 0) {
                    block.append('<div class="text-muted">Nessun annuncio.</div>');
                } else {
                    for (var i in rows) {
                        let row = rows[i];
                        let template = $($('template[name="template_guild_announcement"]').html());
                        let author = ((row.name || '') + ' ' + (row.surname || '')).trim();
                        let date = row.date_created ? Dates().formatHumanDateTime(row.date_created) : '';
                        template.find('[name="title"]').text(row.title || '');
                        template.find('[name="meta"]').text(author + (date ? (' - ' + date) : ''));
                        template.find('[name="body"]').html(row.body_html || '');
                        if (row.is_pinned && parseInt(row.is_pinned, 10) === 1) {
                            template.find('[name="title"]').prepend('<i class="bi bi-pin-angle-fill me-2"></i>');
                        }

                        let actions = template.find('[name="actions"]').empty();
                        if (this.isLeader()) {
                            let delBtn = $('<button type="button" class="btn btn-sm btn-outline-danger">Elimina</button>');
                            delBtn.on('click', this.deleteAnnouncement.bind(this, row.id));
                            actions.append(delBtn);
                        }
                        block.append(template);
                    }
                }
            },
            initAnnouncementPin: function () {
                if (this.announcement_pin_ready) {
                    return;
                }
                let input = $('#guild-announcement-pin');
                if (!input.length || typeof SwitchGroup !== 'function') {
                    return;
                }

                if (input.is('select') && input.find('option').length === 0) {
                    input.append('<option value="0">No</option>');
                    input.append('<option value="1">Si</option>');
                }

                this.announcementPinSwitch = SwitchGroup('#guild-announcement-pin', {
                    preset: 'yesNo',
                    trueLabel: 'Si',
                    falseLabel: 'No',
                    trueValue: '1',
                    falseValue: '0',
                    defaultValue: '0',
                    showLabels: true
                });
                if (this.announcementPinSwitch && typeof this.announcementPinSwitch.setOff === 'function') {
                    this.announcementPinSwitch.setOff();
                } else {
                    input.val('0').change();
                }

                this.announcement_pin_ready = true;
            },
            openAnnouncementModal: function () {
                if (!this.isLeader()) {
                    return;
                }
                $('#guild-announcement-title').val('');
                if (typeof $.fn.summernote === 'function' && $('#guild-announcement-body').length) {
                    $('#guild-announcement-body').summernote('code', '');
                } else {
                    $('#guild-announcement-body').val('');
                }
                if (this.announcementPinSwitch && typeof this.announcementPinSwitch.setOff === 'function') {
                    this.announcementPinSwitch.setOff();
                } else {
                    $('#guild-announcement-pin').val('0').change();
                }
                this.showModal('#guild-announcement-modal');
            },
            createAnnouncement: function () {
                var self = this;
                let title = $('#guild-announcement-title').val().trim();
                let body = '';
                if (typeof $.fn.summernote === 'function' && $('#guild-announcement-body').length) {
                    body = $('#guild-announcement-body').summernote('code');
                } else {
                    body = $('#guild-announcement-body').val();
                }

                let pinValue = null;
                if (this.announcementPinSwitch && typeof this.announcementPinSwitch.getValue === 'function') {
                    pinValue = this.announcementPinSwitch.getValue();
                } else {
                    pinValue = $('#guild-announcement-pin').val();
                }
                let pinned = (String(pinValue) === '1') ? 1 : 0;

                if (title === '') {
                    Toast.show({ body: 'Titolo obbligatorio.', type: 'error' });
                    return;
                }
                callGuildsModule('createAnnouncement', {
                    guild_id: self.guild_id,
                    title: title,
                    body_html: body,
                    is_pinned: pinned
                }, function () {
                    self.hideModal('#guild-announcement-modal');
                    self.loadAnnouncements();
                    Toast.show({ body: 'Annuncio pubblicato.', type: 'success' });
                }, function (error) {
                    Toast.show({
                        body: normalizeGuildsError(error, 'Errore durante pubblicazione annuncio.'),
                        type: 'error'
                    });
                });
            },
            deleteAnnouncement: function (announcement_id) {
                var self = this;
                Dialog('danger', {
                    title: 'Eliminazione annuncio',
                    body: '<p>Vuoi davvero eliminare questo annuncio?</p>'
                }, function () {
                    var dialog = this;
                    callGuildsModule('deleteAnnouncement', {
                        guild_id: self.guild_id,
                        announcement_id: announcement_id
                    }, function () {
                        dialog.hide();
                        self.loadAnnouncements();
                        Toast.show({
                            body: 'Annuncio eliminato.',
                            type: 'success'
                        });
                    }, function (error) {
                        if (dialog.setNormalStatus) {
                            dialog.setNormalStatus();
                        }
                        Toast.show({
                            body: normalizeGuildsError(error, 'Errore durante eliminazione annuncio.'),
                            type: 'error'
                        });
                    });
                }).show();
            },
            loadEvents: function () {
                var self = this;
                callGuildsModule('events', { guild_id: self.guild_id }, function (response) {
                    self.events = response.dataset || [];
                    self.buildEvents();
                }, function () {
                    self.events = [];
                    self.buildEvents();
                });
            },
            buildEvents: function () {
                if (typeof Paginator === 'function') {
                    this.ensureEventsPaginator();
                    this.refreshLocalPaginator(this.eventsPaginator, 5);
                    return;
                }
                $('#guild-events-pagination').addClass('d-none').empty();
                this.renderEvents(this.events || []);
            },
            renderEvents: function (rows) {
                let block = $('#guild-events').empty();
                if (!rows || rows.length === 0) {
                    block.append('<div class="text-muted">Nessun evento in calendario.</div>');
                } else {
                    for (var i in rows) {
                        let row = rows[i];
                        let template = $($('template[name="template_guild_event"]').html());
                        let author = ((row.name || '') + ' ' + (row.surname || '')).trim();
                        let date = row.starts_at ? Dates().formatHumanDateTime(row.starts_at) : '';
                        let endDate = row.ends_at ? Dates().formatHumanDateTime(row.ends_at) : '';
                        let dateLabel = date;
                        template.find('[name="title"]').text(row.title || '');
                        if (endDate) {
                            let safeStart = $('<span></span>').text(date).html();
                            let safeEnd = $('<span></span>').text(endDate).html();
                            dateLabel = safeStart + ' <i class="bi bi-arrow-right-short"></i> ' + safeEnd;
                            template.find('[name="meta"]').html(dateLabel);
                        } else {
                            template.find('[name="meta"]').text(dateLabel);
                        }
                        template.find('[name="author"]').text(author);
                        template.find('[name="body"]').html(row.body_html || '');

                        let actions = template.find('[name="actions"]').empty();
                        if (this.isLeader()) {
                            let delBtn = $('<button type="button" class="btn btn-sm btn-outline-danger">Elimina</button>');
                            delBtn.on('click', this.deleteEvent.bind(this, row.id));
                            actions.append(delBtn);
                        }
                        block.append(template);
                    }
                }
            },
            openEventModal: function () {
                if (!this.isLeader()) {
                    return;
                }
                $('#guild-event-title').val('');
                $('#guild-event-start').val('');
                $('#guild-event-end').val('');
                if (typeof $.fn.summernote === 'function' && $('#guild-event-body').length) {
                    $('#guild-event-body').summernote('code', '');
                } else {
                    $('#guild-event-body').val('');
                }
                this.showModal('#guild-event-modal');
            },
            createEvent: function () {
                var self = this;
                let title = $('#guild-event-title').val().trim();
                let starts = $('#guild-event-start').val();
                let ends = $('#guild-event-end').val();
                let body = '';
                if (typeof $.fn.summernote === 'function' && $('#guild-event-body').length) {
                    body = $('#guild-event-body').summernote('code');
                } else {
                    body = $('#guild-event-body').val();
                }

                if (title === '' || starts === '') {
                    Toast.show({ body: 'Titolo e data inizio obbligatori.', type: 'error' });
                    return;
                }
                callGuildsModule('createEvent', {
                    guild_id: self.guild_id,
                    title: title,
                    body_html: body,
                    starts_at: starts,
                    ends_at: ends || null
                }, function () {
                    self.hideModal('#guild-event-modal');
                    self.loadEvents();
                    Toast.show({ body: 'Evento creato.', type: 'success' });
                }, function (error) {
                    Toast.show({
                        body: normalizeGuildsError(error, 'Errore durante creazione evento.'),
                        type: 'error'
                    });
                });
            },
            deleteEvent: function (event_id) {
                var self = this;
                Dialog('danger', {
                    title: 'Eliminazione evento',
                    body: '<p>Vuoi davvero eliminare questo evento?</p>'
                }, function () {
                    var dialog = this;
                    callGuildsModule('deleteEvent', {
                        guild_id: self.guild_id,
                        event_id: event_id
                    }, function () {
                        dialog.hide();
                        self.loadEvents();
                        Toast.show({
                            body: 'Evento eliminato.',
                            type: 'success'
                        });
                    }, function (error) {
                        if (dialog.setNormalStatus) {
                            dialog.setNormalStatus();
                        }
                        Toast.show({
                            body: normalizeGuildsError(error, 'Errore durante eliminazione evento.'),
                            type: 'error'
                        });
                    });
                }).show();
            },
            loadLogs: function () {
                var self = this;
                callGuildsModule('logs', { guild_id: self.guild_id }, function (response) {
                    self.logs = response.dataset || [];
                    self.buildLogs();
                }, function () {
                    self.logs = [];
                    self.buildLogs();
                });
            },
            buildLogs: function () {
                let panel = $('#guild-logs-panel');
                panel.removeClass('d-none');

                if (typeof Paginator === 'function') {
                    this.ensureLogsPaginator();
                    this.refreshLocalPaginator(this.logsPaginator, 5);
                    return;
                }

                $('#guild-logs-pagination').addClass('d-none').empty();
                this.renderLogs(this.logs || []);
            },
            renderLogs: function (rows) {
                let panel = $('#guild-logs-panel');
                let block = $('#guild-logs').empty();
                panel.removeClass('d-none');

                if (!rows || rows.length === 0) {
                    block.append('<div class="text-muted">Nessun evento registrato.</div>');
                    return;
                }

                for (var i in rows) {
                    let row = rows[i];
                    let template = $($('template[name="template_guild_log"]').html());
                    let actor = ((row.actor_name || '') + ' ' + (row.actor_surname || '')).trim();
                    let target = ((row.target_name || '') + ' ' + (row.target_surname || '')).trim();
                    let date = row.date_created ? Dates().formatHumanDateTime(row.date_created) : '';
                    template.find('[name="action"]').text(this.formatLog(row.action, actor, target, row.meta));
                    template.find('[name="date"]').text(date);
                    block.append(template);
                }
            },
            formatLog: function (action, actor, target, meta) {
                if (action === 'application_submitted') {
                    return actor + ' ha inviato una candidatura.';
                }
                if (action === 'application_accepted') {
                    return actor + ' ha accettato ' + target + ' nella gilda.';
                }
                if (action === 'application_declined') {
                    return actor + ' ha rifiutato la candidatura di ' + target + '.';
                }
                if (action === 'role_changed') {
                    return actor + ' ha cambiato ruolo a ' + target + '.';
                }
                if (action === 'kick_requested') {
                    return actor + ' ha richiesto l\'espulsione di ' + target + '.';
                }
                if (action === 'kick_approved') {
                    return actor + ' ha approvato l\'espulsione di ' + target + '.';
                }
                if (action === 'kick_declined') {
                    return actor + ' ha rifiutato l\'espulsione di ' + target + '.';
                }
                if (action === 'member_removed') {
                    return actor + ' ha espulso ' + target + '.';
                }
                if (action === 'salary_claimed') {
                    return actor + ' ha riscosso lo stipendio.';
                }
                if (action === 'primary_set') {
                    return actor + ' ha impostato la gilda principale.';
                }
                if (action === 'announcement_created') {
                    return actor + ' ha pubblicato un annuncio.';
                }
                if (action === 'announcement_deleted') {
                    return actor + ' ha eliminato un annuncio.';
                }
                if (action === 'event_created') {
                    return actor + ' ha creato un evento.';
                }
                if (action === 'event_deleted') {
                    return actor + ' ha eliminato un evento.';
                }
                if (action === 'requirement_upserted') {
                    return actor + ' ha aggiornato i requisiti della gilda.';
                }
                if (action === 'requirement_deleted') {
                    return actor + ' ha eliminato un requisito della gilda.';
                }
                return actor + ' ha eseguito un\'azione.';
            }
        };

        let guild = Object.assign({}, page, extension);
        return guild.init();
    
}

globalWindow.GameGuildsPage = GameGuildsPage;
globalWindow.GameGuildPage = GameGuildPage;
export { GameGuildsPage as GameGuildsPage };
export { GameGuildPage as GameGuildPage };
export default GameGuildPage;

