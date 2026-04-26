const globalWindow = (typeof window !== 'undefined') ? window : globalThis;
const RICH_TEXT_SELECTOR = '.summernote, .richtext-editor';

function isRichTextInput(input) {
    return !!(input && input.length && input.is(RICH_TEXT_SELECTOR));
}

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

function normalizeProfileError(error, fallback) {
    var fb = fallback || 'Operazione non riuscita.';

    if (globalWindow.GameFeatureError && typeof globalWindow.GameFeatureError.info === 'function') {
        var info = globalWindow.GameFeatureError.info(error, fb) || {};
        var message = (typeof info.message === 'string') ? info.message.trim() : '';
        var code = (typeof info.errorCode === 'string') ? info.errorCode.trim() : '';
        var status = 0;
        if (info.raw && info.raw.xhr && info.raw.xhr.status) {
            status = parseInt(info.raw.xhr.status, 10) || 0;
        }

        if (message === '' || message.toLowerCase() === 'si e verificato un errore.') {
            var textStatus = '';
            if (info.raw && typeof info.raw.textStatus === 'string') {
                textStatus = info.raw.textStatus.trim().toLowerCase();
            }
            if (status === 200 && textStatus === 'parsererror') {
                return 'Risposta API non valida (JSON non parseabile).';
            }
            if (status > 0 && code !== '') {
                return 'Errore ' + status + ' (' + code + ')';
            }
            if (status > 0) {
                return 'Errore ' + status;
            }
            if (code !== '') {
                return 'Errore (' + code + ')';
            }
        }

        if (message !== '') {
            return message;
        }
    }

    if (globalWindow.GameFeatureError && typeof globalWindow.GameFeatureError.normalize === 'function') {
        return globalWindow.GameFeatureError.normalize(error, fb);
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
    return fb;
}


function callProfileModule(method, payload, onSuccess, onError) {
    if (typeof resolveModule !== 'function') {
        if (typeof onError === 'function') {
            onError(new Error('Profile module resolver not available: ' + method));
        }
        return false;
    }

    var mod = resolveModule('game.profile');
    if (!mod || typeof mod[method] !== 'function') {
        if (typeof onError === 'function') {
            onError(new Error('Profile module method not available: ' + method));
        }
        return false;
    }

    mod[method](payload).then(function (response) {
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

function GameProfilePage(character_id, extension) {
        let page = {
            dataset: null,
            character_id: null,
            bond_dataset: null,
            is_owner: false,
            events: [],
            can_manage_events: false,
            event_locations: [],
            event_locations_loaded: false,
            upload_max_bytes: 5 * 1024 * 1024,
            upload_max_concurrency: 3,
            uploaders: {},
            privateLogGrids: {},
            autoOpenedPrivateLog: false,
            upload_max_by_target: {
                avatar: 2,
                background_music_url: 10
            },
            profile_music_storage_key: 'logeon_profile_music_muted',
            init: function () {        
                if (null == character_id) {
                    Dialog('warning', {title: 'Selezione personaggio', body: '<p>Nessun personaggio selezionato.</p>'}).show();
    
                    return;
                }
    
                this.character_id = character_id;
                var ownerAttr = $('#profile-edit-page, #profile-page').first().attr('data-is-owner');
                this.is_owner = parseInt(ownerAttr, 10) === 1;

                this.updateUploadLimitLabel();
                this.loadUploadSettings();
    
                this.get();
    
                return this;
            },
            sync: function (character_id) {
                this.init(character_id);
            },
            loadUploadSettings: function () {
                var self = this;
                callProfileModule('uploadSettings', {}, function (response) {
                    if (!response || !response.dataset) {
                        return;
                    }
                    var mb = parseInt(response.dataset.upload_max_mb, 10);
                    if (!isNaN(mb) && mb > 0) {
                        self.upload_max_bytes = mb * 1024 * 1024;
                    }
                    var concurrency = parseInt(response.dataset.upload_max_concurrency, 10);
                    if (!isNaN(concurrency) && concurrency > 0) {
                        self.upload_max_concurrency = concurrency;
                    }
                    var avatarMb = parseInt(response.dataset.upload_max_avatar_mb, 10);
                    if (!isNaN(avatarMb) && avatarMb > 0) {
                        self.upload_max_by_target.avatar = avatarMb;
                    }
                    var audioMb = parseInt(response.dataset.upload_max_audio_mb, 10);
                    if (!isNaN(audioMb) && audioMb > 0) {
                        self.upload_max_by_target.background_music_url = audioMb;
                    }
                    self.updateUploadLimitLabel();
                }, function (error) {
                    console.warn('[ProfilePage] uploadSettings failed', error);
                });
            },
            updateUploadLimitLabel: function () {
                var mb = Math.round(this.upload_max_bytes / (1024 * 1024));
                if (!mb || mb < 1) {
                    mb = 5;
                }
                var self = this;
                $('[data-upload-max-label]').each(function () {
                    var target = $(this).attr('data-upload-max-label');
                    var targetMb = mb;
                    if (target) {
                        targetMb = Math.round(self.getUploadMaxBytes(target) / (1024 * 1024));
                        if (!targetMb || targetMb < 1) {
                            targetMb = mb;
                        }
                    }
                    $(this).text(targetMb);
                });
            },
            getUploadMaxBytes: function (target) {
                var maxBytes = this.upload_max_bytes || 0;
                if (target && this.upload_max_by_target && this.upload_max_by_target[target]) {
                    var targetMb = parseInt(this.upload_max_by_target[target], 10);
                    if (!isNaN(targetMb) && targetMb > 0) {
                        var targetBytes = targetMb * 1024 * 1024;
                        if (maxBytes === 0 || targetBytes < maxBytes) {
                            maxBytes = targetBytes;
                        }
                    }
                }
                return maxBytes;
            },
            get: function () {
                var self = this;
                callProfileModule('getProfile', self.character_id, function (response) {
                    self.dataset = response.dataset;
                    if (null != response)
                        self.build();

                    return;
                }, function (error) {
                    Toast.show({
                        body: normalizeProfileError(error, 'Errore durante caricamento profilo.'),
                        type: 'error'
                    });
                });
            },
            build: function () {
                let dataset = this.dataset;
                let block = $('#profile-page');
                let gender = (dataset.gender == 1) ? 'Maschio <i class="bi bi-gender-male"></i>' : 'Femmina <i class="bi bi-gender-female"></i>';
                let date_created = dataset.date_created;
                let date_last_signin = dataset.date_last_signin;
                
                Form().setFieldsInDiv(block, dataset);
                block.find('[name="date_created"]').text(this.formatLogDateTime(date_created));
                block.find('[name="date_last_signin"]').text(this.formatLogDateTime(date_last_signin));
                this.renderRichTextSections(block, dataset);
                block.find('[name="character_name_surname"]').html(dataset.name + ' ' + ((null != dataset.surname) ? dataset.surname : ''));
                block.find('[name="character_profile_img"]').attr('src', dataset.avatar);
                this.setupProfileMusic(block, dataset);
                block.find('[name="gender"]').html(gender);
                this.renderCoreStats(block, dataset);
                this.renderCharacterAttributes(block, dataset);
                let moneyValue = (dataset.money != null) ? parseFloat(dataset.money) : null;
                let moneyLabel = (moneyValue === null) ? '-' : ((moneyValue <= 0) ? '0' : Utils().formatNumber(moneyValue));
                block.find('[name="character_money"]').text(moneyLabel);
                let bankValue = (dataset.bank != null) ? parseFloat(dataset.bank) : null;
                let bankLabel = (bankValue === null) ? '-' : ((bankValue <= 0) ? '0' : Utils().formatNumber(bankValue));
                block.find('[name="character_bank"]').text(bankLabel);
                let socialIcon = block.find('[name="socialstatus_icon"]');
                socialIcon.attr('src', dataset.socialstatus_icon);
                if (dataset.socialstatus_description) {
                    socialIcon.attr('data-bs-title', dataset.socialstatus_description);
                    if (socialIcon.length) {
                        initTooltips(socialIcon[0].parentNode || socialIcon[0]);
                    }
                }
                block.find('[name="socialstatus_badge"]').text(dataset.socialstatus_name || '');

                this.renderGuilds(block, this.character_id);

                let jobIcon = block.find('[name="job_icon"]');
                if (dataset.job_icon) {
                    jobIcon.attr('src', dataset.job_icon).show();
                    if (dataset.job_name) {
                        jobIcon.attr('data-bs-title', dataset.job_name);
                        if (jobIcon.length) {
                            initTooltips(jobIcon[0].parentNode || jobIcon[0]);
                        }
                    }
                }

                let defaultCurrency = dataset.currency_default || {};
                let currencyAbbreviation = (defaultCurrency.code || defaultCurrency.name || 'Denaro').toString().trim();
                let currencyLabelParts = [];
                if (defaultCurrency.image) {
                    currencyLabelParts.push('<img src="' + defaultCurrency.image + '" alt="" style="width: 16px; height: 16px; border-radius: 3px; margin-right: 4px;">');
                }
                currencyLabelParts.push('<span>' + currencyAbbreviation + '</span>');
                block.find('[name="currency_name"]').html(currencyLabelParts.join(''));

                let walletsGroup = block.find('[data-role="wallets-list-group"]');
                walletsGroup.find('[data-role="wallet-extra-currency"]').remove();
                let wallets = dataset.wallets || [];
                for (var w in wallets) {
                    let row = wallets[w];
                    let label = row.name || row.code || 'Valuta';
                    let currencyShort = (row.code || row.name || 'Valuta').toString().trim();
                    let amount = Utils().formatNumber(row.balance || 0);

                    let walletItem = $('<li class="list-group-item d-flex justify-content-between align-items-center" data-role="wallet-extra-currency"></li>');

                    let left = $('<span class="d-inline-flex align-items-center"></span>');
                    left.append($('<span></span>').text(label));

                    let right = $('<span class="d-inline-flex align-items-center"></span>');
                    if (row.image && row.image !== '') {
                        right.append('<img src="' + row.image + '" alt="" style="width: 14px; height: 14px; border-radius: 3px; margin-right: 6px;">');
                    }
                    right.append($('<b></b>').text(amount));
                    right.append($('<span class="ms-1"></span>').text(currencyShort));

                    walletItem.append(left).append(right);
                    walletsGroup.append(walletItem);
                }

                let guildsBlock = block.find('[name="guilds_list"]').empty();
                let guilds = dataset.guilds || [];
                if (!guilds.length) {
                    guildsBlock.text('Nessuna gilda');
                } else {
                    let lastPrimary = null;
                    for (var i in guilds) {
                        let g = guilds[i];
                        let isPrimary = (g.is_primary && parseInt(g.is_primary, 10) === 1);
                        if (lastPrimary !== null && lastPrimary !== isPrimary) {
                            guildsBlock.append('<hr class="my-1" />');
                        }
                        lastPrimary = isPrimary;

                        let line = $('<div class="d-flex align-items-center gap-2 mb-1 flex-wrap"></div>');
                        let image = (g.guild_image && g.guild_image !== '') ? g.guild_image : null;
                        if (image) {
                            line.append('<img class="rounded" style="width: 20px; height: 20px;" src="' + image + '" alt="">');
                        }
                        let link = $('<a class="text-decoration-none"></a>');
                        link.attr('href', '/game/guilds/' + g.guild_id);
                        link.text(g.guild_name || 'Gilda');

                        line.append(link);
                        if (isPrimary) {
                            line.append('<span class="badge text-bg-dark">Principale</span>');
                        } else {
                            line.append('<span class="badge text-bg-secondary">Secondaria</span>');
                        }
                        if (g.is_leader && parseInt(g.is_leader, 10) === 1) {
                            line.append('<span class="badge text-bg-primary">Capo</span>');
                        } else if (g.is_officer && parseInt(g.is_officer, 10) === 1) {
                            line.append('<span class="badge text-bg-info">Vice</span>');
                        }
                        if (g.role_name) {
                            line.append('<span class="badge text-bg-light text-dark">' + g.role_name + '</span>');
                        }
                        guildsBlock.append(line);
                    }
                }
    
                if ($('#profile-events').length) {
                    this.loadEvents();
                }

                this.loadBonds();
                this.bindBondActions();
                this.bindPrivateLogs();
                this.openPrivateLogFromQuery();
                this.bindMasterNotesEditor();
                this.bindProfileMetricsEditors();
                this.bindAttributesEditor();
                this.bindForm();
                this.syncForm();
                this.openEditTabIfRequested();

                return;
            },
            toMetricNumber: function (value, fallback) {
                var number = parseFloat(value);
                if (isNaN(number)) {
                    return fallback;
                }
                return number;
            },
            escapeHtml: function (value) {
                return $('<div/>').text(value == null ? '' : String(value)).html();
            },
            formatMetric: function (value, decimals, fallback) {
                if (value === null || value === undefined || value === '' || isNaN(value)) {
                    return fallback || '0';
                }
                if (typeof Utils === 'function') {
                    return Utils().formatNumber(value, decimals || 0);
                }
                return String(value);
            },
            renderCoreStats: function (block, dataset) {
                if (!block || !block.length || !dataset) {
                    return;
                }

                var health = this.toMetricNumber(dataset.health, 0);
                if (health < 0) {
                    health = 0;
                }
                var healthMax = this.toMetricNumber(dataset.health_max || dataset.hp_max || dataset.max_health, 100);
                if (!(healthMax > 0)) {
                    healthMax = 100;
                }
                var healthPercent = Math.max(0, Math.min(100, Math.round((health / healthMax) * 100)));

                var healthStateLabel = 'Ottimo';
                var healthStateClass = 'text-bg-success';
                if (healthPercent <= 25) {
                    healthStateLabel = 'Critico';
                    healthStateClass = 'text-bg-danger';
                } else if (healthPercent <= 50) {
                    healthStateLabel = 'Ferito';
                    healthStateClass = 'text-bg-warning';
                } else if (healthPercent <= 80) {
                    healthStateLabel = 'Stabile';
                    healthStateClass = 'text-bg-info';
                }

                var totalExperience = this.toMetricNumber(dataset.experience, 0);
                if (totalExperience < 0) {
                    totalExperience = 0;
                }

                var levelThreshold = this.toMetricNumber(dataset.threshold_next_level, NaN);
                var currentLevelExperience = 0;
                var maxLevelExperience = 100;

                if (!isNaN(levelThreshold) && levelThreshold > 0) {
                    currentLevelExperience = Math.max(0, Math.min(totalExperience, levelThreshold));
                    maxLevelExperience = levelThreshold;
                } else {
                    currentLevelExperience = totalExperience % 100;
                    if (currentLevelExperience < 0) {
                        currentLevelExperience = 0;
                    }
                }

                var experiencePercent = Math.max(0, Math.min(100, Math.round((currentLevelExperience / maxLevelExperience) * 100)));
                var remainingToNextRank = Math.max(0, maxLevelExperience - currentLevelExperience);

                var rank = parseInt(dataset.rank, 10);
                if (isNaN(rank) || rank < 1) {
                    rank = 1;
                }

                var healthCurrentLabel = this.formatMetric(health, 0, '0');
                var healthMaxLabel = this.formatMetric(healthMax, 0, '100');
                var experienceCurrentLabel = this.formatMetric(currentLevelExperience, 2, '0');
                var experienceMaxLabel = this.formatMetric(maxLevelExperience, 2, '100');
                var experienceTotalLabel = this.formatMetric(totalExperience, 2, '0');
                var experienceRemainingLabel = this.formatMetric(remainingToNextRank, 2, '0');

                block.find('[data-role="profile-health-bar"]').css('width', healthPercent + '%').attr('aria-valuenow', healthPercent).attr('aria-valuetext', healthCurrentLabel + ' su ' + healthMaxLabel);
                block.find('[data-role="profile-health-label"]').text(healthCurrentLabel + ' / ' + healthMaxLabel);
                block.find('[data-role="profile-health-current"]').text(healthCurrentLabel);
                block.find('[data-role="profile-health-max"]').text(healthMaxLabel);
                block.find('[data-role="profile-health-percent"]').text(healthPercent + '%');

                var healthState = block.find('[data-role="profile-health-state"]');
                healthState.removeClass('text-bg-danger text-bg-warning text-bg-info text-bg-success text-bg-secondary');
                healthState.addClass(healthStateClass).text(healthStateLabel);

                block.find('[data-role="profile-experience-bar"]').css('width', experiencePercent + '%').attr('aria-valuenow', experiencePercent).attr('aria-valuetext', experienceCurrentLabel + ' su ' + experienceMaxLabel);
                block.find('[data-role="profile-experience-label"]').text(experienceCurrentLabel + ' / ' + experienceMaxLabel);
                block.find('[data-role="profile-experience-current"]').text(experienceCurrentLabel);
                block.find('[data-role="profile-experience-max"]').text(experienceMaxLabel);
                block.find('[data-role="profile-experience-percent"]').text(experiencePercent + '%');
                block.find('[data-role="profile-experience-total"]').text(experienceTotalLabel);
                block.find('[data-role="profile-next-rank-remaining"]').text(experienceRemainingLabel);
                block.find('[data-role="profile-rank-current"]').text(rank);
                block.find('[data-role="profile-rank-next"]').text(rank + 1);
            },
            formatAttributeValue: function (value, decimals) {
                if (value === null || value === undefined || value === '') {
                    return '-';
                }
                var number = this.toMetricNumber(value, NaN);
                if (isNaN(number)) {
                    return String(value);
                }
                var precision = (typeof decimals === 'number') ? decimals : 2;
                if (typeof Utils === 'function') {
                    return Utils().formatNumber(number, precision);
                }
                return String(number);
            },
            renderCharacterAttributes: function (block, dataset) {
                var card = block.find('[data-role="profile-attributes-card"]');
                if (!card.length) {
                    return;
                }

                var payload = (dataset && dataset.character_attributes) ? dataset.character_attributes : null;
                var enabled = !!(payload && parseInt(payload.enabled, 10) === 1);
                var disabledAlert = card.find('[data-role="profile-attributes-disabled"]');
                if (!enabled) {
                    card.addClass('d-none');
                    disabledAlert.removeClass('d-none');
                    card.find('[data-role^="profile-attributes-group-"]').addClass('d-none');
                    return;
                }

                card.removeClass('d-none');
                disabledAlert.addClass('d-none');
                card.find('[data-role^="profile-attributes-group-"][data-role$="-wrap"]').removeClass('d-none');

                var groups = payload.profile || {};
                var self = this;
                ['primary', 'secondary', 'narrative'].forEach(function (groupKey) {
                    var listNode = card.find('[data-role="profile-attributes-group-' + groupKey + '"]');
                    var emptyNode = card.find('[data-role="profile-attributes-group-' + groupKey + '-empty"]');
                    listNode.empty();

                    var entries = Array.isArray(groups[groupKey]) ? groups[groupKey] : [];
                    if (!entries.length) {
                        emptyNode.removeClass('d-none');
                        return;
                    }
                    emptyNode.addClass('d-none');

                    entries.forEach(function (entry) {
                        var label = String(entry.name || entry.slug || 'Attributo');
                        var value = self.formatAttributeValue(entry.effective_value, 2);
                        var typeBadge = '';
                        if (parseInt(entry.is_derived, 10) === 1) {
                            typeBadge = '<span class="badge text-bg-secondary ms-2">Derivato</span>';
                        }

                        var row = ''
                            + '<li class="list-group-item d-flex justify-content-between align-items-center">'
                            + '<span>' + self.escapeHtml(label) + typeBadge + '</span>'
                            + '<b>' + self.escapeHtml(value) + '</b>'
                            + '</li>';
                        listNode.append(row);
                    });
                });
            },
            loadAttributesEditorData: function () {
                var self = this;
                var modal = $('#profile-attributes-edit-modal');
                if (!modal.length) {
                    return;
                }
                var body = modal.find('[data-role="profile-attributes-edit-body"]');
                var empty = modal.find('[data-role="profile-attributes-edit-empty"]');
                body.html('<tr><td colspan="6" class="text-muted small">Caricamento...</td></tr>');
                empty.addClass('d-none');

                callProfileModule('listAttributes', { character_id: self.character_id }, function (response) {
                    var rows = (response && Array.isArray(response.dataset)) ? response.dataset : [];
                    body.empty();
                    if (!rows.length) {
                        empty.removeClass('d-none');
                        return;
                    }

                    rows.forEach(function (row) {
                        var attributeId = parseInt(row.attribute_id || 0, 10) || 0;
                        if (attributeId <= 0) {
                            return;
                        }

                        var isDerived = parseInt(row.is_derived || 0, 10) === 1;
                        var canOverride = parseInt(row.allow_manual_override || 0, 10) === 1;
                        var group = String(row.attribute_group || 'primary');

                        var baseValue = (row.base_value === null || row.base_value === undefined) ? '' : String(row.base_value);
                        var overrideValue = (row.override_value === null || row.override_value === undefined) ? '' : String(row.override_value);
                        var effectiveValue = self.formatAttributeValue(row.effective_value, 2);

                        var baseInputDisabled = isDerived ? ' disabled' : '';
                        var overrideInputDisabled = canOverride ? '' : ' disabled';

                        var tr = $('<tr data-attribute-id="' + attributeId + '"></tr>');
                        tr.append('<td><div><b>' + self.escapeHtml(row.name || row.slug || ('#' + attributeId)) + '</b></div><div class="small text-muted">' + self.escapeHtml(row.slug || '') + '</div></td>');
                        tr.append('<td>' + self.escapeHtml(group) + '</td>');
                        tr.append('<td>' + (isDerived ? '<span class="badge text-bg-secondary">Si</span>' : '<span class="badge text-bg-light text-dark">No</span>') + '</td>');
                        tr.append('<td><input type="number" step="0.01" class="form-control form-control-sm" data-field="base_value"' + baseInputDisabled + ' value="' + self.escapeHtml(baseValue) + '"></td>');
                        tr.append('<td><input type="number" step="0.01" class="form-control form-control-sm" data-field="override_value"' + overrideInputDisabled + ' value="' + self.escapeHtml(overrideValue) + '"></td>');
                        tr.append('<td><span class="small fw-semibold">' + self.escapeHtml(effectiveValue) + '</span></td>');
                        body.append(tr);
                    });
                }, function (error) {
                    body.html('<tr><td colspan="6" class="text-danger small">' + self.escapeHtml(normalizeProfileError(error, 'Errore durante caricamento attributi.')) + '</td></tr>');
                });
            },
            collectAttributesEditorPayload: function () {
                var modal = $('#profile-attributes-edit-modal');
                var entries = [];
                modal.find('[data-role="profile-attributes-edit-body"] tr[data-attribute-id]').each(function () {
                    var row = $(this);
                    var attributeId = parseInt(row.attr('data-attribute-id') || '0', 10) || 0;
                    if (attributeId <= 0) {
                        return;
                    }

                    var baseInput = row.find('[data-field="base_value"]');
                    var overrideInput = row.find('[data-field="override_value"]');

                    var item = { attribute_id: attributeId };
                    if (baseInput.length && !baseInput.is(':disabled')) {
                        var baseValue = String(baseInput.val() || '').trim();
                        item.base_value = baseValue === '' ? null : baseValue;
                    }
                    if (overrideInput.length) {
                        var overrideValue = String(overrideInput.val() || '').trim();
                        item.override_value = overrideValue === '' ? null : overrideValue;
                    }

                    entries.push(item);
                });
                return entries;
            },
            bindAttributesEditor: function () {
                var self = this;
                var page = $('#profile-page');
                if (!page.length) {
                    return;
                }

                page.off('click.profileAttributesEdit').on('click.profileAttributesEdit', '[data-action="profile-open-attributes-edit"]', function (event) {
                    event.preventDefault();
                    var modal = $('#profile-attributes-edit-modal');
                    if (!modal.length) {
                        return;
                    }
                    modal.modal('show');
                    self.loadAttributesEditorData();
                });

                var modal = $('#profile-attributes-edit-modal');
                if (!modal.length) {
                    return;
                }

                modal.off('click.profileAttributesSave').on('click.profileAttributesSave', '[data-action="profile-attributes-save"]', function (event) {
                    event.preventDefault();
                    var values = self.collectAttributesEditorPayload();
                    if (!values.length) {
                        Toast.show({ body: 'Nessun valore da aggiornare.', type: 'warning' });
                        return;
                    }

                    callProfileModule('updateAttributeValues', {
                        character_id: self.character_id,
                        values: values
                    }, function () {
                        Toast.show({ body: 'Attributi aggiornati.', type: 'success' });
                        self.get();
                        self.loadAttributesEditorData();
                    }, function (error) {
                        Toast.show({
                            body: normalizeProfileError(error, 'Aggiornamento attributi non riuscito.'),
                            type: 'error'
                        });
                    });
                });

                modal.off('click.profileAttributesRecompute').on('click.profileAttributesRecompute', '[data-action="profile-attributes-recompute"]', function (event) {
                    event.preventDefault();
                    callProfileModule('recomputeAttributes', { character_id: self.character_id }, function () {
                        Toast.show({ body: 'Ricalcolo attributi completato.', type: 'success' });
                        self.get();
                        self.loadAttributesEditorData();
                    }, function (error) {
                        Toast.show({
                            body: normalizeProfileError(error, 'Ricalcolo attributi non riuscito.'),
                            type: 'error'
                        });
                    });
                });
            },
            renderGuilds: function (block, characterId) {
                var container = block.find('[name="guild_icons"]');
                if (!container.length) { return; }

                var http = (globalWindow.Request && globalWindow.Request.http) ? globalWindow.Request.http : null;
                if (!http || typeof http.post !== 'function') { return; }

                http.post('/guilds/character/list', { character_id: characterId || 0 }).then(function (response) {
                    var list = (response && Array.isArray(response.dataset)) ? response.dataset : [];
                    container.empty();
                    for (var i = 0; i < list.length; i++) {
                        var g = list[i];
                        var img = $('<img width="32" data-bs-toggle="tooltip">')
                            .attr('src', g.icon || '')
                            .attr('data-bs-title', g.name || '');
                        container.append(img);
                    }
                    if (list.length) {
                        initTooltips(container[0]);
                    }
                }).catch(function () {});
            },
            renderRichTextSections: function (block, dataset) {
                if (!block || !block.length || !dataset) {
                    return;
                }

                var htmlFields = [
                    { key: 'description_body', selector: '[name="description_body"]' },
                    { key: 'description_temper', selector: '[name="description_temper"]' },
                    { key: 'background_story', selector: '[name="background_story"]' },
                    { key: 'friends_knowledge_html', selector: '[name="friends-list"], [name="friends_knowledge_html"]' }
                ];

                for (var i = 0; i < htmlFields.length; i++) {
                    var config = htmlFields[i];
                    var node = block.find(config.selector);
                    if (!node.length) {
                        continue;
                    }

                    var value = dataset[config.key];
                    if (value === null || typeof value === 'undefined' || String(value).trim() === '') {
                        node.html('<span class="text-muted">-</span>');
                        continue;
                    }

                    node.html(String(value));
                }
            },
            bondTypeLabel: function (type) {
                var map = {
                    conoscente: 'Conoscente',
                    amico: 'Amico',
                    alleato: 'Alleato',
                    rivale: 'Rivale',
                    famiglia: 'Famiglia',
                    mentore: 'Mentore'
                };
                var key = String(type || '').toLowerCase();
                return map[key] || (type || '-');
            },
            bondActionLabel: function (action) {
                var map = {
                    create: 'Nuovo legame',
                    change_type: 'Cambio tipo',
                    close: 'Chiusura'
                };
                var key = String(action || '').toLowerCase();
                return map[key] || (action || '-');
            },
            formatBondDate: function (value) {
                if (!value) {
                    return '-';
                }
                if (typeof Dates === 'function') {
                    var helper = Dates();
                    if (helper && typeof helper.formatHumanDateTime === 'function') {
                        return helper.formatHumanDateTime(value);
                    }
                }
                return String(value);
            },
            loadBonds: function () {
                var self = this;
                var root = $('#profile-page');
                if (!root.length || !root.find('[data-role="bonds-list"]').length) {
                    return;
                }

                callProfileModule('listBonds', { character_id: this.character_id }, function (response) {
                    self.bond_dataset = (response && response.dataset) ? response.dataset : {
                        bonds: [],
                        incoming_requests: [],
                        outgoing_requests: []
                    };
                    self.renderBonds();
                }, function (error) {
                    self.renderBondsError(normalizeProfileError(error, 'Errore durante caricamento legami.'));
                });
            },
            renderBondsError: function (message) {
                var root = $('#profile-page');
                var list = root.find('[data-role="bonds-list"]');
                if (!list.length) {
                    return;
                }
                list.html('<div class="list-group-item text-danger small">' + String(message || 'Errore caricamento legami.') + '</div>');
                root.find('[data-role="bonds-empty"]').addClass('d-none');
            },
            renderBonds: function () {
                var root = $('#profile-page');
                var list = root.find('[data-role="bonds-list"]');
                if (!list.length) {
                    return;
                }

                var dataset = this.bond_dataset || {};
                var bonds = Array.isArray(dataset.bonds) ? dataset.bonds : [];
                var empty = root.find('[data-role="bonds-empty"]');

                list.empty();
                if (!bonds.length) {
                    empty.removeClass('d-none');
                } else {
                    empty.addClass('d-none');
                    for (var i = 0; i < bonds.length; i++) {
                        var row = bonds[i] || {};
                        var item = $('<div class="list-group-item"></div>');
                        var line = $('<div class="d-flex justify-content-between align-items-start flex-wrap gap-2"></div>');
                        var left = $('<div></div>');

                        var otherId = parseInt(row.other_character_id, 10) || 0;
                        var otherName = row.other_character_name || (otherId > 0 ? ('Personaggio #' + otherId) : 'Personaggio');
                        if (otherId > 0) {
                            var link = $('<a class="fw-semibold text-decoration-none"></a>');
                            link.attr('href', '/game/profile/' + otherId);
                            link.text(otherName);
                            left.append(link);
                        } else {
                            left.append($('<span class="fw-semibold"></span>').text(otherName));
                        }

                        var intensity = parseInt(row.intensity, 10);
                        if (isNaN(intensity) || intensity < 0) {
                            intensity = 0;
                        }
                        var meta = $('<div class="small text-muted"></div>');
                        meta.text('Tipo: ' + this.bondTypeLabel(row.bond_type) + ' | Intensita: ' + intensity + '/100');
                        left.append(meta);

                        var right = $('<div class="text-end"></div>');
                        right.append($('<span class="badge text-bg-secondary"></span>').text(this.bondTypeLabel(row.bond_type)));
                        var dateLabel = this.formatBondDate(row.last_interaction_at || row.date_updated || row.date_created || '');
                        right.append($('<div class="small text-muted mt-1"></div>').text('Ultima interazione: ' + dateLabel));

                        line.append(left);
                        line.append(right);
                        item.append(line);
                        list.append(item);
                    }
                }

                this.renderBondRequests('incoming', dataset.incoming_requests || []);
                this.renderBondRequests('outgoing', dataset.outgoing_requests || []);
            },
            renderBondRequests: function (kind, rows) {
                var root = $('#profile-page');
                var listSelector = (kind === 'incoming')
                    ? '[data-role="bond-requests-incoming"]'
                    : '[data-role="bond-requests-outgoing"]';
                var emptySelector = (kind === 'incoming')
                    ? '[data-role="bond-requests-incoming-empty"]'
                    : '[data-role="bond-requests-outgoing-empty"]';

                var list = root.find(listSelector);
                var empty = root.find(emptySelector);
                if (!list.length || !empty.length) {
                    return;
                }

                list.empty();
                rows = Array.isArray(rows) ? rows : [];
                if (!rows.length) {
                    empty.removeClass('d-none');
                    return;
                }

                empty.addClass('d-none');
                for (var i = 0; i < rows.length; i++) {
                    var row = rows[i] || {};
                    var item = $('<div class="list-group-item"></div>');
                    var header = $('<div class="d-flex justify-content-between align-items-start flex-wrap gap-2"></div>');
                    var left = $('<div></div>');

                    if (kind === 'incoming') {
                        var requesterId = parseInt(row.requester_id, 10) || 0;
                        var requesterName = row.requester_name || (requesterId > 0 ? ('Personaggio #' + requesterId) : 'Personaggio');
                        if (requesterId > 0) {
                            var linkIn = $('<a class="fw-semibold text-decoration-none"></a>');
                            linkIn.attr('href', '/game/profile/' + requesterId);
                            linkIn.text(requesterName);
                            left.append(linkIn);
                        } else {
                            left.append($('<span class="fw-semibold"></span>').text(requesterName));
                        }
                    } else {
                        var targetId = parseInt(row.target_id, 10) || 0;
                        var targetName = row.target_name || (targetId > 0 ? ('Personaggio #' + targetId) : 'Personaggio');
                        if (targetId > 0) {
                            var linkOut = $('<a class="fw-semibold text-decoration-none"></a>');
                            linkOut.attr('href', '/game/profile/' + targetId);
                            linkOut.text(targetName);
                            left.append(linkOut);
                        } else {
                            left.append($('<span class="fw-semibold"></span>').text(targetName));
                        }
                    }

                    var details = this.bondActionLabel(row.action_type);
                    if (row.requested_type) {
                        details += ' | ' + this.bondTypeLabel(row.requested_type);
                    }
                    left.append($('<div class="small text-muted"></div>').text(details));
                    if (row.message) {
                        left.append($('<div class="small mt-1"></div>').text(String(row.message)));
                    }

                    header.append(left);

                    if (kind === 'incoming') {
                        var actions = $('<div class="btn-group btn-group-sm" role="group"></div>');
                        var acceptBtn = $('<button type="button" class="btn btn-outline-success">Accetta</button>');
                        acceptBtn.attr('data-action', 'bond-request-respond');
                        acceptBtn.attr('data-request-id', row.id);
                        acceptBtn.attr('data-decision', 'accepted');
                        var rejectBtn = $('<button type="button" class="btn btn-outline-danger">Rifiuta</button>');
                        rejectBtn.attr('data-action', 'bond-request-respond');
                        rejectBtn.attr('data-request-id', row.id);
                        rejectBtn.attr('data-decision', 'rejected');
                        actions.append(acceptBtn);
                        actions.append(rejectBtn);
                        header.append(actions);
                    } else {
                        header.append($('<span class="badge text-bg-warning">In attesa</span>'));
                    }

                    item.append(header);
                    item.append($('<div class="small text-muted mt-1"></div>').text('Richiesta del ' + this.formatBondDate(row.date_created || '')));
                    list.append(item);
                }
            },
            bindBondActions: function () {
                var self = this;
                var root = $('#profile-page');
                if (!root.length) {
                    return;
                }

                root.off('click.profile-bonds');

                root.on('click.profile-bonds', '[data-action="bond-request-create"]', function (event) {
                    event.preventDefault();
                    var btn = $(this);
                    if (btn.data('busy') === 1) {
                        return;
                    }
                    btn.data('busy', 1);

                    var requestedType = String(btn.attr('data-requested-type') || 'conoscente');
                    callProfileModule('requestBond', {
                        target_id: self.character_id,
                        action_type: 'create',
                        requested_type: requestedType
                    }, function () {
                        btn.data('busy', 0);
                        Toast.show({
                            body: 'Richiesta legame inviata.',
                            type: 'success'
                        });
                        self.loadBonds();
                    }, function (error) {
                        btn.data('busy', 0);
                        Toast.show({
                            body: normalizeProfileError(error, 'Errore durante invio richiesta legame.'),
                            type: 'error'
                        });
                    });
                });

                root.on('click.profile-bonds', '[data-action="bond-request-respond"]', function (event) {
                    event.preventDefault();
                    var btn = $(this);
                    if (btn.data('busy') === 1) {
                        return;
                    }

                    var requestId = parseInt(btn.attr('data-request-id'), 10);
                    var decision = String(btn.attr('data-decision') || '').toLowerCase();
                    if (!requestId || (decision !== 'accepted' && decision !== 'rejected')) {
                        Toast.show({
                            body: 'Richiesta non valida.',
                            type: 'warning'
                        });
                        return;
                    }

                    btn.data('busy', 1);
                    callProfileModule('respondBondRequest', {
                        request_id: requestId,
                        decision: decision
                    }, function () {
                        btn.data('busy', 0);
                        Toast.show({
                            body: (decision === 'accepted') ? 'Richiesta accettata.' : 'Richiesta rifiutata.',
                            type: 'success'
                        });
                        self.loadBonds();
                    }, function (error) {
                        btn.data('busy', 0);
                        Toast.show({
                            body: normalizeProfileError(error, 'Errore durante risposta alla richiesta.'),
                            type: 'error'
                        });
                    });
                });
            },
            bindPrivateLogs: function () {
                var self = this;
                var root = $('#profile-page');
                if (!root.length) {
                    return;
                }

                root.off('click.profile-logs');

                root.on('click.profile-dm', '[data-action="profile-send-dm"]', function (event) {
                    event.preventDefault();
                    if (typeof globalWindow.GameOpenMessageModal !== 'function') { return; }
                    var ds = self.dataset || {};
                    var name = ((ds.name || '') + ' ' + (ds.surname || '')).trim();
                    globalWindow.GameOpenMessageModal(parseInt(ds.id, 10) || 0, name);
                });

                root.on('click.profile-logs', '[data-action="profile-open-experience-logs"]', function (event) {
                    event.preventDefault();
                    self.showModal('#profile-experience-logs-modal');
                    self.loadExperienceLogs();
                });

                root.on('click.profile-logs', '[data-action="profile-open-economy-logs"]', function (event) {
                    event.preventDefault();
                    self.showModal('#profile-economy-logs-modal');
                    self.loadEconomyLogs();
                });

                root.on('click.profile-logs', '[data-action="profile-open-session-logs"]', function (event) {
                    event.preventDefault();
                    self.showModal('#profile-session-logs-modal');
                    self.loadSessionLogs();
                });
            },
            openPrivateLogFromQuery: function () {
                if (this.autoOpenedPrivateLog) {
                    return;
                }

                var logType = this.readLogTypeFromUrl();
                if (logType === '') {
                    return;
                }

                this.autoOpenedPrivateLog = true;
                if (logType === 'experience') {
                    this.showModal('#profile-experience-logs-modal');
                    this.loadExperienceLogs();
                    return;
                }
                if (logType === 'economy') {
                    this.showModal('#profile-economy-logs-modal');
                    this.loadEconomyLogs();
                    return;
                }
                if (logType === 'session') {
                    this.showModal('#profile-session-logs-modal');
                    this.loadSessionLogs();
                }
            },
            readLogTypeFromUrl: function () {
                try {
                    var params = new URL(globalWindow.location.href).searchParams;
                    var value = String(params.get('open_log') || '').trim().toLowerCase();
                    if (value === 'experience' || value === 'economy' || value === 'session') {
                        return value;
                    }
                } catch (error) {}
                return '';
            },
            bindMasterNotesEditor: function () {
                var self = this;
                var root = $('#profile-page');
                var modal = $('#profile-master-notes-modal');
                var form = $('#profile-master-notes-form');

                if (!root.length || !modal.length || !form.length) {
                    return;
                }

                root.off('click.profile-master-notes');
                form.off('submit.profile-master-notes');

                root.on('click.profile-master-notes', '[data-action="profile-open-master-notes-edit"]', function (event) {
                    event.preventDefault();

                    var currentValue = '';
                    if (self.dataset && self.dataset.mod_status !== null && self.dataset.mod_status !== undefined) {
                        currentValue = String(self.dataset.mod_status);
                    }

                    form.find('[name="character_id"]').val(self.character_id || '');
                    form.find('[name="mod_status"]').val(currentValue);
                    self.showModal(modal[0]);
                });

                form.on('submit.profile-master-notes', function (event) {
                    event.preventDefault();

                    var submit = form.find('[data-master-notes-save]');
                    if (submit.data('busy') === 1) {
                        return;
                    }

                    var payload = {
                        character_id: parseInt(form.find('[name="character_id"]').val(), 10) || self.character_id,
                        mod_status: form.find('[name="mod_status"]').val()
                    };

                    submit.data('busy', 1).prop('disabled', true);

                    callProfileModule('updateMasterNotes', payload, function (response) {
                        submit.data('busy', 0).prop('disabled', false);

                        var dataset = (response && response.dataset) ? response.dataset : {};
                        var updatedValue = (dataset.mod_status !== undefined && dataset.mod_status !== null)
                            ? String(dataset.mod_status)
                            : String(payload.mod_status || '').trim();

                        if (!self.dataset) {
                            self.dataset = {};
                        }
                        self.dataset.mod_status = updatedValue;

                        root.find('[name="mod_status"]').text(updatedValue !== '' ? updatedValue : '-');
                        self.hideModal(modal[0]);
                        Toast.show({
                            body: 'Note del master aggiornate.',
                            type: 'success'
                        });
                    }, function (error) {
                        submit.data('busy', 0).prop('disabled', false);
                        Toast.show({
                            body: normalizeProfileError(error, 'Errore durante aggiornamento note del master.'),
                            type: 'error'
                        });
                    });
                });
            },
            normalizeDecimalInput: function (value, fallback) {
                if (value === null || value === undefined) {
                    return fallback;
                }
                var normalized = String(value).replace(',', '.').trim();
                if (normalized === '') {
                    return fallback;
                }
                var number = parseFloat(normalized);
                if (isNaN(number)) {
                    return fallback;
                }
                return number;
            },
            bindProfileMetricsEditors: function () {
                var self = this;
                var root = $('#profile-page');
                var healthModal = $('#profile-health-modal');
                var healthForm = $('#profile-health-form');
                var experienceModal = $('#profile-experience-assign-modal');
                var experienceForm = $('#profile-experience-assign-form');

                if (!root.length) {
                    return;
                }

                root.off('click.profile-metrics');
                if (healthForm.length) {
                    healthForm.off('submit.profile-health');
                }
                if (experienceForm.length) {
                    experienceForm.off('submit.profile-experience');
                }

                if (healthModal.length && healthForm.length) {
                    root.on('click.profile-metrics', '[data-action="profile-open-health-edit"]', function (event) {
                        event.preventDefault();

                        var currentHealth = self.toMetricNumber(self.dataset ? self.dataset.health : null, 0);
                        var currentHealthMax = self.toMetricNumber(self.dataset ? (self.dataset.health_max || self.dataset.hp_max || self.dataset.max_health) : null, 100);
                        if (!(currentHealthMax > 0)) {
                            currentHealthMax = 100;
                        }
                        if (currentHealth < 0) {
                            currentHealth = 0;
                        }
                        if (currentHealth > currentHealthMax) {
                            currentHealth = currentHealthMax;
                        }

                        healthForm.find('[name="character_id"]').val(self.character_id || '');
                        healthForm.find('[name="health"]').val(Math.round(currentHealth * 100) / 100);
                        healthForm.find('[name="health_max"]').val(Math.round(currentHealthMax * 100) / 100);
                        self.showModal(healthModal[0]);
                    });

                    healthForm.on('submit.profile-health', function (event) {
                        event.preventDefault();

                        var submit = healthForm.find('[data-health-save]');
                        if (submit.data('busy') === 1) {
                            return;
                        }

                        var health = self.normalizeDecimalInput(healthForm.find('[name="health"]').val(), NaN);
                        var healthMax = self.normalizeDecimalInput(healthForm.find('[name="health_max"]').val(), NaN);

                        if (isNaN(health) || health < 0) {
                            Toast.show({ body: 'Valore salute non valido.', type: 'warning' });
                            return;
                        }
                        if (isNaN(healthMax) || healthMax <= 0) {
                            Toast.show({ body: 'Valore salute massima non valido.', type: 'warning' });
                            return;
                        }
                        if (health > healthMax) {
                            Toast.show({ body: 'La salute attuale non puo superare la salute massima.', type: 'warning' });
                            return;
                        }

                        var payload = {
                            character_id: parseInt(healthForm.find('[name="character_id"]').val(), 10) || self.character_id,
                            health: health,
                            health_max: healthMax
                        };

                        submit.data('busy', 1).prop('disabled', true);

                        callProfileModule('updateHealth', payload, function (response) {
                            submit.data('busy', 0).prop('disabled', false);

                            var dataset = (response && response.dataset) ? response.dataset : {};
                            if (!self.dataset) {
                                self.dataset = {};
                            }
                            self.dataset.health = (dataset.health !== undefined && dataset.health !== null) ? dataset.health : health;
                            self.dataset.health_max = (dataset.health_max !== undefined && dataset.health_max !== null) ? dataset.health_max : healthMax;

                            self.renderCoreStats(root, self.dataset);
                            self.hideModal(healthModal[0]);
                            Toast.show({
                                body: 'Salute aggiornata.',
                                type: 'success'
                            });
                        }, function (error) {
                            submit.data('busy', 0).prop('disabled', false);
                            Toast.show({
                                body: normalizeProfileError(error, 'Errore durante aggiornamento salute.'),
                                type: 'error'
                            });
                        });
                    });
                }

                if (experienceModal.length && experienceForm.length) {
                    root.on('click.profile-metrics', '[data-action="profile-open-experience-assign"]', function (event) {
                        event.preventDefault();

                        experienceForm.find('[name="character_id"]').val(self.character_id || '');
                        experienceForm.find('[name="delta"]').val('');
                        experienceForm.find('[name="reason"]').val('');
                        self.showModal(experienceModal[0]);
                    });

                    experienceForm.on('submit.profile-experience', function (event) {
                        event.preventDefault();

                        var submit = experienceForm.find('[data-experience-assign-save]');
                        if (submit.data('busy') === 1) {
                            return;
                        }

                        var delta = self.normalizeDecimalInput(experienceForm.find('[name="delta"]').val(), NaN);
                        var reason = String(experienceForm.find('[name="reason"]').val() || '').trim();

                        if (isNaN(delta) || delta <= 0) {
                            Toast.show({ body: 'Quantita esperienza non valida.', type: 'warning' });
                            return;
                        }
                        if (reason === '') {
                            Toast.show({ body: 'Inserisci una motivazione valida.', type: 'warning' });
                            return;
                        }

                        var payload = {
                            character_id: parseInt(experienceForm.find('[name="character_id"]').val(), 10) || self.character_id,
                            delta: delta,
                            reason: reason
                        };

                        submit.data('busy', 1).prop('disabled', true);

                        callProfileModule('assignExperience', payload, function (response) {
                            submit.data('busy', 0).prop('disabled', false);

                            var dataset = (response && response.dataset) ? response.dataset : {};
                            if (!self.dataset) {
                                self.dataset = {};
                            }
                            if (dataset.experience_after !== undefined && dataset.experience_after !== null) {
                                self.dataset.experience = dataset.experience_after;
                            } else {
                                var current = self.toMetricNumber(self.dataset.experience, 0);
                                self.dataset.experience = current + delta;
                            }

                            self.renderCoreStats(root, self.dataset);
                            self.hideModal(experienceModal[0]);
                            self.loadExperienceLogs();

                            Toast.show({
                                body: 'Esperienza assegnata.',
                                type: 'success'
                            });
                        }, function (error) {
                            submit.data('busy', 0).prop('disabled', false);
                            Toast.show({
                                body: normalizeProfileError(error, 'Errore durante assegnazione esperienza.'),
                                type: 'error'
                            });
                        });
                    });
                }
            },
            showModal: function (selector) {
                var element = null;
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
                var element = null;
                if (typeof selector === 'string') {
                    element = document.querySelector(selector);
                } else {
                    element = selector;
                }
                if (!element) {
                    return;
                }

                if (globalWindow.bootstrap && globalWindow.bootstrap.Modal) {
                    var instance = globalWindow.bootstrap.Modal.getOrCreateInstance(element);
                    instance.hide();
                    return;
                }

                if (typeof $ === 'function') {
                    $(element).modal('hide');
                }
            },
            formatLogDateTime: function (value) {
                if (!value) {
                    return '-';
                }
                if (typeof Dates === 'function') {
                    var helper = Dates();
                    if (helper && typeof helper.formatHumanDateTime === 'function') {
                        return helper.formatHumanDateTime(value);
                    }
                }
                return String(value);
            },
            formatLogNumber: function (value) {
                if (value === null || value === undefined || value === '') {
                    return '-';
                }
                var number = parseFloat(value);
                if (isNaN(number)) {
                    return '-';
                }
                if (typeof Utils === 'function') {
                    return Utils().formatNumber(number);
                }
                return String(number);
            },
            formatLogMeta: function (value) {
                if (value === null || value === undefined || String(value).trim() === '') {
                    return '-';
                }
                var raw = String(value);
                try {
                    raw = JSON.stringify(JSON.parse(raw));
                } catch (error) {}
                if (raw.length > 200) {
                    raw = raw.substring(0, 197) + '...';
                }
                return raw;
            },
            getLogGridConfig: function (kind) {
                var self = this;
                var configs = {
                    experience: {
                        gridId: 'profile-experience-logs-grid',
                        endpoint: '/profile/logs/experience',
                        action: 'experienceLogs',
                        defaultOrderBy: 'date_created|DESC',
                        pageSize: 10,
                        emptyText: 'Nessun log esperienza disponibile.',
                        columns: [
                            {
                                field: 'date_created',
                                label: 'Data/Ora',
                                sortable: true,
                                style: { textAlign: 'left' },
                                format: function (row) {
                                    return self.formatLogDateTime(row.date_created);
                                }
                            },
                            {
                                field: 'delta',
                                label: 'Delta',
                                style: { textAlign: 'right' },
                                format: function (row) {
                                    return self.formatLogNumber(row.delta);
                                }
                            },
                            {
                                field: 'experience_before',
                                label: 'Prima',
                                style: { textAlign: 'right' },
                                format: function (row) {
                                    return self.formatLogNumber(row.experience_before);
                                }
                            },
                            {
                                field: 'experience_after',
                                label: 'Dopo',
                                style: { textAlign: 'right' },
                                format: function (row) {
                                    return self.formatLogNumber(row.experience_after);
                                }
                            },
                            {
                                field: 'source',
                                label: 'Fonte',
                                style: { textAlign: 'left' },
                                format: function (row) {
                                    return row.source_label || row.source || '-';
                                }
                            },
                            {
                                field: 'reason',
                                label: 'Motivo',
                                style: { textAlign: 'left' },
                                format: function (row) {
                                    return row.reason || '-';
                                }
                            },
                            {
                                field: 'author_username',
                                label: 'Operatore',
                                style: { textAlign: 'left' },
                                format: function (row) {
                                    return row.author_username || (row.author_id ? ('#' + row.author_id) : '-');
                                }
                            }
                        ]
                    },
                    economy: {
                        gridId: 'profile-economy-logs-grid',
                        endpoint: '/profile/logs/economy',
                        action: 'economyLogs',
                        defaultOrderBy: 'date_created|DESC',
                        pageSize: 10,
                        emptyText: 'Nessuna transazione disponibile.',
                        columns: [
                            {
                                field: 'date_created',
                                label: 'Data/Ora',
                                sortable: true,
                                style: { textAlign: 'left' },
                                format: function (row) {
                                    return self.formatLogDateTime(row.date_created);
                                }
                            },
                            {
                                field: 'amount',
                                label: 'Importo',
                                style: { textAlign: 'right' },
                                format: function (row) {
                                    var amount = parseFloat(row.amount || 0);
                                    var label = self.formatLogNumber(amount);
                                    if (!isNaN(amount) && amount > 0) {
                                        label = '+' + label;
                                    }
                                    return label;
                                }
                            },
                            {
                                field: 'balance_before',
                                label: 'Saldo prima',
                                style: { textAlign: 'right' },
                                format: function (row) {
                                    return self.formatLogNumber(row.balance_before);
                                }
                            },
                            {
                                field: 'balance_after',
                                label: 'Saldo dopo',
                                style: { textAlign: 'right' },
                                format: function (row) {
                                    return self.formatLogNumber(row.balance_after);
                                }
                            },
                            {
                                field: 'currency_name',
                                label: 'Valuta',
                                style: { textAlign: 'left' },
                                format: function (row) {
                                    return row.currency_name || row.currency_label || '-';
                                }
                            },
                            {
                                field: 'source',
                                label: 'Origine',
                                style: { textAlign: 'left' },
                                format: function (row) {
                                    return row.source_label || row.source || '-';
                                }
                            },
                            {
                                field: 'meta',
                                label: 'Meta',
                                style: { textAlign: 'left' },
                                format: function (row) {
                                    return row.meta_label || self.formatLogMeta(row.meta);
                                }
                            }
                        ]
                    },
                    session: {
                        gridId: 'profile-session-logs-grid',
                        endpoint: '/profile/logs/sessions',
                        action: 'sessionLogs',
                        defaultOrderBy: 'date_created|DESC',
                        pageSize: 10,
                        emptyText: 'Nessun accesso registrato.',
                        columns: [
                            {
                                field: 'date_created',
                                label: 'Data/Ora',
                                sortable: true,
                                style: { textAlign: 'left' },
                                format: function (row) {
                                    return self.formatLogDateTime(row.date_created);
                                }
                            },
                            {
                                field: 'action',
                                label: 'Azione',
                                style: { textAlign: 'left' },
                                format: function (row) {
                                    return (row.action || '-').toUpperCase();
                                }
                            },
                            {
                                field: 'url',
                                label: 'URL',
                                style: { textAlign: 'left' },
                                format: function (row) {
                                    return row.url || '-';
                                }
                            }
                        ]
                    }
                };

                if (!Object.prototype.hasOwnProperty.call(configs, kind)) {
                    return null;
                }

                return configs[kind];
            },
            getLogErrorMessage: function (kind) {
                var messages = {
                    experience: 'Errore durante caricamento log esperienza.',
                    economy: 'Errore durante caricamento log economici.',
                    session: 'Errore durante caricamento log accessi.'
                };

                return messages[kind] || 'Errore durante caricamento log.';
            },
            ensureLogGrid: function (kind) {
                if (this.privateLogGrids && this.privateLogGrids[kind]) {
                    return this.privateLogGrids[kind];
                }

                if (typeof Datagrid !== 'function') {
                    return null;
                }

                var config = this.getLogGridConfig(kind);
                if (!config) {
                    return null;
                }

                var container = $('#' + config.gridId);
                if (!container.length) {
                    return null;
                }

                var self = this;
                var grid = new Datagrid(config.gridId, {
                    thead: true,
                    orderable: true,
                    columns: config.columns,
                    dataset: [],
                    handler: {
                        url: config.endpoint,
                        action: config.action
                    },
                    nav: {
                        display: 'bottom',
                        urlupdate: 0,
                        results: config.pageSize || 10,
                        page: 1
                    }
                });

                if (grid && grid.lang) {
                    grid.lang.no_results = config.emptyText;
                }

                if (grid) {
                    grid.onGetDataStart = function () {
                        self.prepareLogModalState(kind);
                    };

                    grid.onGetDataSuccess = function (response) {
                        var rows = (response && Array.isArray(response.dataset)) ? response.dataset : [];
                        self.finalizeLogModalState(kind, rows);
                    };

                    grid.onGetDataError = function (error) {
                        self.finalizeLogModalState(kind, []);
                        Toast.show({
                            body: normalizeProfileError(error, self.getLogErrorMessage(kind)),
                            type: 'error'
                        });
                    };
                }

                this.privateLogGrids[kind] = grid;
                return grid;
            },
            setLogGridRows: function (kind, rows) {
                var grid = this.privateLogGrids[kind] || this.ensureLogGrid(kind);
                if (!grid || typeof grid.setData !== 'function') {
                    return false;
                }

                grid.setData(Array.isArray(rows) ? rows : []);
                return true;
            },
            prepareLogModalState: function (kind) {
                $('[data-role="profile-' + kind + '-logs-empty"]').addClass('d-none');
                $('[data-role="profile-' + kind + '-logs-loading"]').removeClass('d-none');
            },
            finalizeLogModalState: function (kind, rows) {
                $('[data-role="profile-' + kind + '-logs-loading"]').addClass('d-none');
                var grid = this.privateLogGrids[kind] || this.ensureLogGrid(kind);
                if (grid) {
                    $('[data-role="profile-' + kind + '-logs-empty"]').addClass('d-none');
                    return;
                }
                if (!rows || !rows.length) {
                    $('[data-role="profile-' + kind + '-logs-empty"]').removeClass('d-none');
                } else {
                    $('[data-role="profile-' + kind + '-logs-empty"]').addClass('d-none');
                }
            },
            loadLogGrid: function (kind) {
                var config = this.getLogGridConfig(kind);
                if (!config) {
                    return;
                }

                var grid = this.ensureLogGrid(kind);
                if (!grid || typeof grid.loadData !== 'function') {
                    return;
                }

                var query = {
                    character_id: this.character_id
                };

                grid.loadData(
                    query,
                    config.pageSize || 10,
                    1,
                    config.defaultOrderBy || 'date_created|DESC'
                );
            },
            loadExperienceLogs: function () {
                this.loadLogGrid('experience');
            },
            loadEconomyLogs: function () {
                this.loadLogGrid('economy');
            },
            loadSessionLogs: function () {
                this.loadLogGrid('session');
            },
            bindForm: function () {
                var self = this;
                let form = $('#profile-form');
                if (!form.length) {
                    return;
                }

                form.off('submit.profile').on('submit.profile', function (event) {
                    event.preventDefault();
                    self.save();
                });

                form.find('[data-profile-save]').off('click.profile').on('click.profile', function () {
                    self.save();
                });

                $('[data-loanface-request-submit]').off('click.profile').on('click.profile', function () {
                    self.requestLoanfaceChange();
                });

                $('[data-identity-request-submit]').off('click.profile').on('click.profile', function () {
                    self.requestIdentityChange();
                });

                form.find('[data-preview-target]').off('input.profile change.profile').on('input.profile change.profile', function () {
                    self.updatePreview($(this));
                });
                form.find('[name="background_music_url"]').off('input.profile change.profile').on('input.profile change.profile', function () {
                    self.updateMusicPreview();
                });

                $('[data-music-tab-btn]').off('click.profile').on('click.profile', function () {
                    var tab = $(this).attr('data-music-tab-btn');
                    self.switchMusicTab(tab);
                });

                $('[data-avatar-tab-btn]').off('click.profile').on('click.profile', function () {
                    var tab = $(this).attr('data-avatar-tab-btn');
                    self.switchAvatarTab(tab);
                });

                form.find('[data-upload-target]').off('click.profile').on('click.profile', function () {
                    let target = $(this).attr('data-upload-target');
                    self.openUploader(target);
                });
                form.find('[data-upload-cancel]').off('click.profile').on('click.profile', function () {
                    let target = $(this).attr('data-upload-cancel');
                    self.handleUploadAction(target);
                });

                form.find('[data-clear-target]').off('click.profile').on('click.profile', function () {
                    let target = $(this).attr('data-clear-target');
                    self.clearImage(target);
                });

                this.setupUploadDropAreas();
            },
            syncForm: function () {
                if (!this.dataset) {
                    return;
                }
                let form = $('#profile-form');
                if (!form.length) {
                    return;
                }

                Form().setFields('profile-form', this.dataset);
                this.applyOnceLocks();
                this.initIdentityRequestSection();
                this.buildLoanfaceRequestStatus();
                this.updateAllPreviews();
                this.updateMusicPreview();
                this.initMusicTabState();
                this.initAvatarTabState();
            },
            applyOnceLocks: function () {
                let dataset = this.dataset || {};
                let form = $('#profile-form');
                if (!form.length) {
                    return;
                }

                var adminBypass = this.hasAdminBypass();

                form.find('[data-once="1"]').each(function () {
                    let input = $(this);
                    let name = input.attr('name');
                    let value = (dataset[name] !== undefined && dataset[name] !== null) ? String(dataset[name]).trim() : '';
                    let locked = value !== '' && !adminBypass;
                    let group = input.closest('.form-once-group');

                    if (locked) {
                        input.prop('disabled', true);
                        group.find('[data-lock-msg]').removeClass('d-none');
                    } else {
                        input.prop('disabled', false);
                        group.find('[data-lock-msg]').addClass('d-none');
                    }
                });
            },
            hasAdminBypass: function () {
                if (typeof globalWindow.Storage !== 'function') { return false; }
                var st = globalWindow.Storage();
                if (!st || typeof st.get !== 'function') { return false; }
                return st.get('userIsAdministrator') === 1 || st.get('userIsSuperuser') === 1;
            },
            formatRelativeTime: function (dateStr) {
                if (!dateStr) {
                    return '';
                }
                if (typeof Dates !== 'function') {
                    return '';
                }
                let timestamp = Dates().getTimestamp(dateStr);
                if (!timestamp) {
                    return '';
                }
                let diff = Math.floor((Date.now() - timestamp) / 1000);
                if (diff < 60) {
                    return 'meno di 1 min fa';
                }
                if (diff < 3600) {
                    let mins = Math.floor(diff / 60);
                    return mins + ' min fa';
                }
                if (diff < 86400) {
                    let hours = Math.floor(diff / 3600);
                    return hours + ' ore fa';
                }
                let days = Math.floor(diff / 86400);
                return days + ' giorni fa';
            },
            buildLoanfaceRequestStatus: function () {
                let status = $('[data-loanface-request-status]');
                if (!status.length) {
                    return;
                }

                let badge = $('[data-loanface-request-badge]');
                let field = $('#profile-loanface-request');
                let reason = $('#profile-loanface-request-reason');
                let submit = $('[data-loanface-request-submit]');
                let request = this.dataset ? (this.dataset.loanface_request || null) : null;
                let cooldownLabel = $('[data-loanface-request-cooldown]');

                if (cooldownLabel.length) {
                    let cooldownDays = parseInt(this.dataset && this.dataset.loanface_request_cooldown_days ? this.dataset.loanface_request_cooldown_days : 90, 10);
                    if (isNaN(cooldownDays) || cooldownDays <= 0) {
                        cooldownDays = 90;
                    }
                    cooldownLabel.text(cooldownDays);
                }

                if (!request) {
                    status.text('Se vuoi cambiare presta-volto, invia una richiesta con motivazione.');
                    badge.addClass('d-none').removeClass('text-bg-warning text-bg-success text-bg-danger').text('');
                    field.prop('disabled', false);
                    reason.prop('disabled', false);
                    submit.prop('disabled', false);
                    return;
                }

                let whenDate = request.status === 'pending' ? request.date_created : request.date_resolved;
                let when = this.formatRelativeTime(whenDate);

                if (request.status === 'pending') {
                    status.text('Richiesta in attesa: ' + (request.new_loanface || '-'));
                    badge.removeClass('d-none text-bg-success text-bg-danger').addClass('text-bg-warning').text('In attesa' + (when ? ' | ' + when : ''));
                    field.prop('disabled', true);
                    reason.prop('disabled', true);
                    submit.prop('disabled', true);
                    return;
                }

                field.prop('disabled', false);
                reason.prop('disabled', false);
                submit.prop('disabled', false);

                if (request.status === 'approved') {
                    status.text('Ultima richiesta approvata: ' + (request.new_loanface || '-'));
                    badge.removeClass('d-none text-bg-warning text-bg-danger').addClass('text-bg-success').text('Approvata' + (when ? ' | ' + when : ''));
                } else if (request.status === 'rejected') {
                    status.text('Ultima richiesta rifiutata: ' + (request.new_loanface || '-'));
                    badge.removeClass('d-none text-bg-warning text-bg-success').addClass('text-bg-danger').text('Rifiutata' + (when ? ' | ' + when : ''));
                } else {
                    status.text('Stato richiesta non disponibile.');
                    badge.addClass('d-none').removeClass('text-bg-warning text-bg-success text-bg-danger').text('');
                }
            },
            initIdentityRequestSection: function () {
                var card = $('#identity-request-card');
                if (!card.length) { return; }

                if (this.hasAdminBypass()) {
                    card.hide();
                    return;
                }

                var dataset = this.dataset || {};
                var onceFields = ['surname', 'height', 'weight', 'eyes', 'hair', 'skin'];
                var anyLocked = false;
                for (var i = 0; i < onceFields.length; i++) {
                    var v = dataset[onceFields[i]];
                    if (v !== null && v !== undefined && String(v).trim() !== '') {
                        anyLocked = true;
                        break;
                    }
                }

                if (anyLocked) {
                    card.show();
                    this.buildIdentityRequestStatus();
                } else {
                    card.hide();
                }
            },
            buildIdentityRequestStatus: function () {
                var statusEl = $('[data-identity-request-status]');
                var badge = $('[data-identity-request-badge]');
                var form = $('#identity-request-form');
                var submit = $('[data-identity-request-submit]');
                if (!statusEl.length) { return; }

                var request = this.dataset ? (this.dataset.identity_request || null) : null;

                if (!request) {
                    statusEl.text('Campi bloccati? Invia una richiesta di modifica con motivazione.');
                    badge.addClass('d-none').text('');
                    form.find('input').prop('disabled', false);
                    submit.prop('disabled', false);
                    return;
                }

                var whenDate = request.status === 'pending' ? request.date_created : request.date_resolved;
                var when = this.formatRelativeTime(whenDate);

                if (request.status === 'pending') {
                    statusEl.text('Richiesta in attesa di approvazione.');
                    badge.removeClass('d-none text-bg-success text-bg-danger').addClass('text-bg-warning').text('In attesa' + (when ? ' | ' + when : ''));
                    form.find('input').prop('disabled', true);
                    submit.prop('disabled', true);
                    return;
                }

                form.find('input').prop('disabled', false);
                submit.prop('disabled', false);

                if (request.status === 'approved') {
                    statusEl.text('Ultima richiesta approvata.');
                    badge.removeClass('d-none text-bg-warning text-bg-danger').addClass('text-bg-success').text('Approvata' + (when ? ' | ' + when : ''));
                } else if (request.status === 'rejected') {
                    statusEl.text('Ultima richiesta rifiutata. Puoi inviarne una nuova.');
                    badge.removeClass('d-none text-bg-warning text-bg-success').addClass('text-bg-danger').text('Rifiutata' + (when ? ' | ' + when : ''));
                } else {
                    statusEl.text('Campi bloccati? Invia una richiesta di modifica con motivazione.');
                    badge.addClass('d-none').text('');
                }
            },
            requestIdentityChange: function () {
                var payload = {
                    new_surname: String($('#identity-request-form [name="ir_surname"]').val() || '').trim(),
                    new_height:  String($('#identity-request-form [name="ir_height"]').val() || '').trim(),
                    new_weight:  String($('#identity-request-form [name="ir_weight"]').val() || '').trim(),
                    new_eyes:    String($('#identity-request-form [name="ir_eyes"]').val() || '').trim(),
                    new_hair:    String($('#identity-request-form [name="ir_hair"]').val() || '').trim(),
                    new_skin:    String($('#identity-request-form [name="ir_skin"]').val() || '').trim(),
                    reason:      String($('#identity-request-form [name="ir_reason"]').val() || '').trim()
                };

                var hasAny = payload.new_surname || payload.new_height || payload.new_weight ||
                             payload.new_eyes || payload.new_hair || payload.new_skin;
                if (!hasAny) {
                    Toast.show({ body: 'Compila almeno un campo da modificare.', type: 'warning' });
                    return;
                }

                var self = this;
                callProfileModule('requestIdentityChange', payload, function () {
                    Toast.show({ body: 'Richiesta inviata.', type: 'success' });
                    $('#identity-request-form [name]').val('');
                    self.get();
                }, function (error) {
                    Toast.show({
                        body: normalizeProfileError(error, 'Errore durante l\'invio della richiesta.'),
                        type: 'error'
                    });
                });
            },
            updatePreview: function (input) {
                if (!input || !input.length) {
                    return;
                }
                let target = input.attr('data-preview-target');
                if (!target) {
                    return;
                }
                let value = (input.val() !== undefined && input.val() !== null) ? String(input.val()).trim() : '';
                let img = $(target);
                if (!img.length) {
                    return;
                }
                if (value === '') {
                    img.attr('src', '').addClass('d-none');
                } else {
                    img.attr('src', value).removeClass('d-none');
                }
            },
            updateAllPreviews: function () {
                let form = $('#profile-form');
                if (!form.length) {
                    return;
                }
                let self = this;
                form.find('[data-preview-target]').each(function () {
                    self.updatePreview($(this));
                });
            },
            getMusicUrl: function (value) {
                if (value === null || value === undefined) {
                    return '';
                }
                return String(value).trim();
            },
            isAudioUrl: function (value) {
                var url = this.getMusicUrl(value);
                if (url === '') {
                    return false;
                }
                if (!(url.startsWith('http://') || url.startsWith('https://') || url.startsWith('/'))) {
                    return false;
                }
                var path = url.split('#')[0].split('?')[0];
                return /\.(mp3|ogg|wav|m4a|aac|webm)$/i.test(path);
            },
            updateMusicPreview: function () {
                var form = $('#profile-form');
                if (!form.length) {
                    return;
                }

                var input = form.find('[name="background_music_url"]');
                if (!input.length) {
                    return;
                }

                var warning = form.find('[data-music-preview-warning]');
                var wrap = form.find('[data-music-preview-wrap]');
                var audio = form.find('[data-music-preview-audio]');
                if (!audio.length) {
                    return;
                }

                var url = this.getMusicUrl(input.val());
                if (url === '') {
                    warning.addClass('d-none');
                    wrap.addClass('d-none');
                    audio.attr('src', '');
                    try {
                        audio.get(0).pause();
                    } catch (error) {}
                    return;
                }

                if (!this.isAudioUrl(url)) {
                    warning.removeClass('d-none');
                    wrap.addClass('d-none');
                    audio.attr('src', '');
                    try {
                        audio.get(0).pause();
                    } catch (error) {}
                    return;
                }

                warning.addClass('d-none');
                wrap.removeClass('d-none');
                if (audio.attr('src') !== url) {
                    audio.attr('src', url);
                }
            },
            switchAvatarTab: function (tab) {
                $('[data-avatar-tab-btn]').removeClass('active btn-secondary').addClass('btn-outline-secondary');
                $('[data-avatar-tab-btn="' + tab + '"]').addClass('active btn-secondary').removeClass('btn-outline-secondary');
                $('[data-avatar-tab-panel]').hide();
                $('[data-avatar-tab-panel="' + tab + '"]').show();
            },
            initAvatarTabState: function () {
                var url = String($('#profile-form [name="avatar"]').val() || '').trim();
                var isLocal = url.indexOf('/assets/imgs/uploads/characters/') === 0;
                this.switchAvatarTab(isLocal ? 'upload' : 'link');
            },
            switchMusicTab: function (tab) {
                $('[data-music-tab-btn]').removeClass('active btn-secondary').addClass('btn-outline-secondary');
                $('[data-music-tab-btn="' + tab + '"]').addClass('active btn-secondary').removeClass('btn-outline-secondary');
                $('[data-music-tab-panel]').hide();
                $('[data-music-tab-panel="' + tab + '"]').show();
            },
            initMusicTabState: function () {
                var url = String($('#profile-form [name="background_music_url"]').val() || '').trim();
                var isLocal = url.indexOf('/assets/imgs/uploads/users/') === 0;
                this.switchMusicTab(isLocal ? 'upload' : 'link');
                if (isLocal) {
                    this.updateMusicUploadCurrent(url);
                }
            },
            updateMusicUploadCurrent: function (url) {
                var node = $('[data-music-upload-current]');
                if (!node.length) { return; }
                var filename = url ? url.split('/').pop() : '';
                if (filename) {
                    node.text('File caricato: ' + filename).removeClass('d-none');
                } else {
                    node.addClass('d-none').text('');
                }
            },
            getStoredProfileMusicMuted: function () {
                if (!globalWindow.localStorage) {
                    return false;
                }
                try {
                    return globalWindow.localStorage.getItem(this.profile_music_storage_key) === '1';
                } catch (error) {
                    return false;
                }
            },
            setStoredProfileMusicMuted: function (muted) {
                if (!globalWindow.localStorage) {
                    return;
                }
                try {
                    globalWindow.localStorage.setItem(this.profile_music_storage_key, muted ? '1' : '0');
                } catch (error) {}
            },
            setupProfileMusic: function (block, dataset) {
                if (!block || !block.length) {
                    return;
                }

                var controlWrap = block.find('[data-profile-music-control-wrap]');
                var toggle = block.find('[data-profile-music-toggle]');
                var player = block.find('[data-profile-music-player]');
                if (!controlWrap.length || !toggle.length || !player.length) {
                    return;
                }

                var url = this.getMusicUrl(dataset && dataset.background_music_url ? dataset.background_music_url : '');
                if (!this.isAudioUrl(url)) {
                    controlWrap.addClass('d-none');
                    player.attr('src', '');
                    try { player.get(0).pause(); } catch (e) {}
                    return;
                }

                controlWrap.removeClass('d-none');
                if (player.attr('src') !== url) {
                    player.attr('src', url);
                }

                var self = this;
                var domPlayer = player.get(0);
                domPlayer.loop = true;

                var muted = this.getStoredProfileMusicMuted();
                // trueValue '1' = audio attivo, falseValue '0' = audio disattivato
                toggle.val(muted ? '0' : '1');

                var sw = SwitchGroup(toggle, {
                    trueValue: '1',
                    falseValue: '0',
                    trueLabel: '<i class="bi bi-volume-up"></i>',
                    falseLabel: '<i class="bi bi-volume-mute"></i>',
                    labelsAsHtml: true
                });

                domPlayer.muted = muted;

                toggle.off('change.profile-music').on('change.profile-music', function () {
                    var isOn = sw.value() === '1';
                    domPlayer.muted = !isOn;
                    self.setStoredProfileMusicMuted(!isOn);

                    if (!isOn) {
                        try { domPlayer.pause(); } catch (e) {}
                        return;
                    }

                    var pp = domPlayer.play();
                    if (pp && typeof pp.catch === 'function') {
                        pp.catch(function () {
                            domPlayer.muted = true;
                            self.setStoredProfileMusicMuted(true);
                            sw.setValue('0');
                        });
                    }
                });

                if (!muted) {
                    var pp = domPlayer.play();
                    if (pp && typeof pp.catch === 'function') {
                        pp.catch(function () {
                            domPlayer.muted = true;
                            self.setStoredProfileMusicMuted(true);
                            sw.setValue('0');
                        });
                    }
                } else {
                    try { domPlayer.pause(); } catch (e) {}
                }
            },
            openEditTabIfRequested: function () {
                if (globalWindow.location.hash !== '#edit') {
                    return;
                }
                if (globalWindow.location.pathname === '/game/profile') {
                    globalWindow.location.href = '/game/profile/edit';
                    return;
                }
            },
            formatBytes: function (bytes) {
                if (!bytes || bytes <= 0) {
                    return '0 B';
                }
                let units = ['B', 'KB', 'MB', 'GB'];
                let index = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
                let value = bytes / Math.pow(1024, index);
                return value.toFixed(1).replace('.0', '') + ' ' + units[index];
            },
            checkUploadSize: function (file, target) {
                let maxBytes = this.getUploadMaxBytes(target);
                if (!maxBytes) {
                    return true;
                }
                let size = file && (file.filesize || (file.file ? file.file.size : 0));
                if (size && size > maxBytes) {
                    Dialog('warning', {
                        title: 'Uploader',
                        body: '<p>File troppo grande. Dimensione massima: ' + this.formatBytes(maxBytes) + '.</p>'
                    }).show();
                    return false;
                }
                return true;
            },
            buildUploader: function (target, options) {
                var self = this;
                if (!this.uploaders) {
                    this.uploaders = {};
                }
                if (this.uploaders[target]) {
                    if (options && options.dropArea) {
                        this.uploaders[target].setDropArea(options.dropArea);
                    }
                    return this.uploaders[target];
                }
                var audioMimeTypes = {
                    'audio/mpeg': 'MP3', 'audio/mp3': 'MP3', 'audio/ogg': 'OGG',
                    'audio/wav': 'WAV', 'audio/x-wav': 'WAV', 'audio/aac': 'AAC',
                    'audio/mp4': 'M4A', 'audio/x-m4a': 'M4A', 'audio/webm': 'WEBM'
                };
                let config = {
                    url: '/uploader',
                    multiple: false,
                    autostart: true,
                    target: target,
                    allowed_mime: target === 'background_music_url' ? audioMimeTypes : undefined,
                    newFile: function (file) {
                        if (!self.checkUploadSize(file, target)) {
                            file.analyze = function () {};
                            file.upload = function () {};
                            return file;
                        }
                        file.max_file_size = self.getUploadMaxBytes(target);
                        file.max_uploads = self.upload_max_concurrency;
                        var prevProgress = file.onProgress;
                        var prevCancel = file.onCancel;
                        var prevError = file.onError;
                        file.onProgress = function () {
                            if (typeof prevProgress === 'function') {
                                prevProgress.call(this);
                            }
                            self.updateUploadProgress(target, this);
                        };
                        file.onComplete = function () {
                            self.finalizeUpload(target, this);
                        };
                        file.onCancel = function () {
                            if (typeof prevCancel === 'function') {
                                prevCancel.call(this);
                            }
                            self.onUploadCancelled(target, this);
                        };
                        file.onError = function (message) {
                            if (typeof prevError === 'function') {
                                prevError.call(this, message);
                            }
                            self.onUploadError(target, this, message);
                        };
                        return file;
                    },
                    onAddFile: function (file) {
                        self.setUploadActionMode(target, 'cancel');
                        self.updateUploadProgress(target, file);
                        Toast.show({
                            body: 'Caricamento in corso...',
                            type: 'info'
                        });
                    }
                };
                if (options) {
                    config = Object.assign({}, config, options);
                }
                var uploader = Uploader(config);
                this.uploaders[target] = uploader;
                if (options && options.dropArea) {
                    uploader.setDropArea(options.dropArea);
                }
                return uploader;
            },
            setupUploadDropAreas: function () {
                var self = this;
                let form = $('#profile-form');
                if (!form.length) {
                    return;
                }
                form.find('[data-upload-drop]').each(function () {
                    let drop = $(this);
                    if (drop.attr('data-uploader-bound') === '1') {
                        return;
                    }
                    let target = drop.attr('data-upload-drop');
                    if (!target) {
                        return;
                    }
                    drop.attr('data-uploader-bound', '1');
                    self.buildUploader(target, {
                        dropArea: drop
                    });
                });
            },
            openUploader: function (target) {
                if (!target) {
                    return;
                }
                this.buildUploader(target).open();
            },
            clearImage: function (target) {
                if (!target) {
                    return;
                }
                let input = $('#profile-form [name="' + target + '"]');
                if (!input.length) {
                    return;
                }
                input.val('').trigger('input');
                this.resetUploadProgress(target);
            },
            getUploadActionButton: function (target) {
                return $('[data-upload-cancel="' + target + '"]');
            },
            setUploadActionMode: function (target, mode) {
                var btn = this.getUploadActionButton(target);
                if (!btn.length) {
                    return;
                }
                if (mode === 'cancel') {
                    btn.removeClass('d-none btn-outline-warning').addClass('btn-outline-danger');
                    btn.attr('data-upload-mode', 'cancel');
                    btn.text('Annulla');
                    return;
                }
                if (mode === 'retry') {
                    btn.removeClass('d-none btn-outline-danger').addClass('btn-outline-warning');
                    btn.attr('data-upload-mode', 'retry');
                    btn.text('Riprova');
                    return;
                }
                btn.addClass('d-none');
                btn.attr('data-upload-mode', '');
                btn.removeClass('btn-outline-warning').addClass('btn-outline-danger');
                btn.text('Annulla');
            },
            handleUploadAction: function (target) {
                if (!target || !this.uploaders || !this.uploaders[target]) {
                    return;
                }
                var uploader = this.uploaders[target];
                var file = uploader.currentFile;
                if (!file) {
                    this.resetUploadProgress(target);
                    return;
                }

                var mode = this.getUploadActionButton(target).attr('data-upload-mode') || '';
                var shouldRetry = (mode === 'retry') || file.state === 'error' || file.cancelled === true;
                if (shouldRetry) {
                    this.retryUpload(target);
                    return;
                }

                if (typeof file.cancel === 'function') {
                    file.cancel();
                }
            },
            cancelUpload: function (target) {
                this.handleUploadAction(target);
            },
            retryUpload: function (target) {
                if (!target || !this.uploaders || !this.uploaders[target]) {
                    return;
                }
                var uploader = this.uploaders[target];
                var file = uploader.currentFile;
                if (!file) {
                    return;
                }
                this.updateUploadProgress(target, file);
                this.setUploadActionMode(target, 'cancel');
                if (typeof uploader.retryFile === 'function') {
                    uploader.retryFile(file);
                }
                Toast.show({
                    body: 'Upload riavviato.',
                    type: 'info'
                });
            },
            onUploadError: function (target, file, message) {
                var wrap = $('[data-upload-progress="' + target + '"]');
                if (wrap.length) {
                    var bar = wrap.find('.progress-bar');
                    var perc = file && file.getPercLoaded ? file.getPercLoaded() : 0;
                    if (isNaN(perc) || perc < 0) {
                        perc = 0;
                    }
                    wrap.removeClass('d-none');
                    bar.removeClass('bg-success').addClass('bg-danger');
                    bar.css('width', perc + '%').text('Errore');
                }
                this.setUploadActionMode(target, 'retry');
            },
            onUploadCancelled: function (target, file) {
                var wrap = $('[data-upload-progress="' + target + '"]');
                if (wrap.length) {
                    var bar = wrap.find('.progress-bar');
                    wrap.removeClass('d-none');
                    bar.removeClass('bg-danger bg-success');
                    bar.css('width', '0%').text('Annullato');
                }
                this.setUploadActionMode(target, 'retry');
                if (!file || !file.last_error) {
                    Toast.show({
                        body: 'Upload annullato.',
                        type: 'warning'
                    });
                }
            },
            updateUploadProgress: function (target, file) {
                if (!target || !file) {
                    return;
                }
                var wrap = $('[data-upload-progress="' + target + '"]');
                if (!wrap.length) {
                    return;
                }
                var bar = wrap.find('.progress-bar');
                var perc = file.getPercLoaded ? file.getPercLoaded() : 0;
                if (isNaN(perc) || perc < 0) {
                    perc = 0;
                }
                wrap.removeClass('d-none');
                bar.removeClass('bg-danger').addClass('bg-success');
                bar.css('width', perc + '%').text(perc + '%');
                this.setUploadActionMode(target, 'cancel');
            },
            resetUploadProgress: function (target) {
                var wrap = $('[data-upload-progress="' + target + '"]');
                if (!wrap.length) {
                    return;
                }
                var bar = wrap.find('.progress-bar');
                bar.removeClass('bg-danger bg-success');
                bar.css('width', '0%').text('0%');
                wrap.addClass('d-none');
                this.setUploadActionMode(target, '');
            },
            toggleUploadCancel: function (target, visible) {
                this.setUploadActionMode(target, visible ? 'cancel' : '');
            },
            finalizeUpload: function (target, file) {
                var self = this;
                if (!file || !file.token) {
                    Toast.show({
                        body: 'Upload non valido.',
                        type: 'error'
                    });
                    return;
                }
                callProfileModule('finalizeUpload', { token: file.token, target: target }, function (response) {
                    if (!response || !response.dataset || !response.dataset.url) {
                        self.setUploadActionMode(target, 'retry');
                        Toast.show({
                            body: 'Upload completato ma URL non disponibile.',
                            type: 'error'
                        });
                        return;
                    }
                    let url = response.dataset.url;
                    let input = $('#profile-form [name="' + target + '"]');
                    if (input.length) {
                        input.val(url).trigger('input');
                    }
                    self.resetUploadProgress(target);
                    if (target === 'background_music_url') {
                        self.updateMusicUploadCurrent(url);
                        Toast.show({ body: 'Audio caricato.', type: 'success' });
                    } else {
                        Toast.show({ body: 'Immagine caricata.', type: 'success' });
                    }
                }, function (error) {
                    self.setUploadActionMode(target, 'retry');
                    Toast.show({
                        body: normalizeProfileError(error, 'Errore durante upload immagine.'),
                        type: 'error'
                    });
                });
            },
            collectForm: function () {
                let form = $('#profile-form');
                if (!form.length) {
                    return {};
                }
                let data = {};
                form.find(':input').each(function () {
                    let input = $(this);
                    let name = input.attr('name');
                    if (!name) {
                        return;
                    }
                    if (input.is(':button')) {
                        return;
                    }
                    if (input.prop('disabled')) {
                        return;
                    }

                    if (input.is(':checkbox')) {
                        data[name] = input.prop('checked') ? 1 : 0;
                    } else if (input.is(':radio')) {
                        if (input.prop('checked')) {
                            data[name] = input.val();
                        }
                    } else if (isRichTextInput(input) && typeof $.fn.summernote === 'function') {
                        let code = input.summernote('code');
                        if (code === '<p><br></p>') {
                            code = '';
                        }
                        data[name] = code;
                    } else {
                        data[name] = input.val();
                    }
                });

                return data;
            },
            validateForm: function (data) {
                if (!data) {
                    return { ok: true };
                }

                let height = data.height;
                let weight = data.weight;
                if (height !== undefined && height !== null && String(height).trim() !== '') {
                    let h = parseFloat(String(height).replace(',', '.'));
                    if (isNaN(h) || h < 0.5 || h > 3.0) {
                        return { ok: false, message: 'Altezza non valida (0.50 - 3.00).' };
                    }
                }
                if (weight !== undefined && weight !== null && String(weight).trim() !== '') {
                    let w = parseFloat(String(weight).replace(',', '.'));
                    if (isNaN(w) || w < 20 || w > 500) {
                        return { ok: false, message: 'Peso non valido (20 - 500).' };
                    }
                }

                let urlFields = ['avatar'];
                for (var i in urlFields) {
                    let key = urlFields[i];
                    if (!data[key]) {
                        continue;
                    }
                    let value = String(data[key]).trim();
                    if (value === '') {
                        continue;
                    }
                    if (!(value.startsWith('http://') || value.startsWith('https://') || value.startsWith('/'))) {
                        return { ok: false, message: 'URL non valido per ' + key + '.' };
                    }
                }

                if (data.background_music_url !== undefined && data.background_music_url !== null) {
                    let musicUrl = String(data.background_music_url).trim();
                    if (musicUrl !== '' && !this.isAudioUrl(musicUrl)) {
                        return { ok: false, message: 'Link audio non valido. Usa un URL diretto a file mp3, ogg, wav, m4a, aac o webm.' };
                    }
                }

                return { ok: true };
            },
            requestLoanfaceChange: function () {
                var self = this;
                let currentLoanface = (this.dataset && this.dataset.loanface) ? String(this.dataset.loanface).trim() : '';
                if (currentLoanface === '') {
                    Toast.show({
                        body: 'Imposta prima il presta-volto nel campo principale.',
                        type: 'warning'
                    });
                    return;
                }

                let newLoanface = $('#profile-loanface-request').val();
                let reason = $('#profile-loanface-request-reason').val();
                if (!newLoanface || String(newLoanface).trim() === '') {
                    Toast.show({
                        body: 'Inserisci il nuovo presta-volto.',
                        type: 'warning'
                    });
                    return;
                }

                let payload = {
                    new_loanface: String(newLoanface).trim(),
                    reason: reason
                };

                callProfileModule('requestLoanfaceChange', payload, function () {
                    Toast.show({
                        body: 'Richiesta inviata.',
                        type: 'success'
                    });
                    $('#profile-loanface-request').val('');
                    $('#profile-loanface-request-reason').val('');
                    self.get();
                }, function (error) {
                    Toast.show({
                        body: normalizeProfileError(error, 'Errore durante invio richiesta presta-volto.'),
                        type: 'error'
                    });
                });
            },
            save: function () {
                var self = this;
                let payload = this.collectForm();
                payload.id = this.character_id;

                let keys = Object.keys(payload || {});
                if (keys.length <= 1) {
                    Toast.show({
                        body: 'Nessuna modifica da salvare.',
                        type: 'info'
                    });
                    return;
                }

                let validation = this.validateForm(payload);
                if (!validation.ok) {
                    Dialog('warning', {
                        title: 'Dati non validi',
                        body: '<p>' + validation.message + '</p>'
                    }).show();
                    return;
                }
                callProfileModule('updateProfile', payload, function () {
                    Toast.show({
                        body: 'Profilo aggiornato.',
                        type: 'success'
                    });
                    self.get();
                }, function (error) {
                    Toast.show({
                        body: normalizeProfileError(error, 'Errore durante aggiornamento profilo.'),
                        type: 'error'
                    });
                });
            },
            loadEvents: function () {
                var self = this;
                callProfileModule('listEvents', self.character_id, function (response) {
                    self.events = response.dataset || [];
                    self.can_manage_events = response.can_manage == 1;
                    self.buildEvents();
                }, function (error) {
                    Toast.show({
                        body: normalizeProfileError(error, 'Errore durante caricamento avvenimenti.'),
                        type: 'error'
                    });
                });
            },
            buildEvents: function () {
                let block = $('#profile-events').empty();
                let empty = $('#profile-events-empty');
                if (!block.length) {
                    return;
                }
                if (!this.events || this.events.length === 0) {
                    if (empty.length) {
                        empty.removeClass('d-none');
                    }
                    return;
                }

                if (empty.length) {
                    empty.addClass('d-none');
                }

                for (var i in this.events) {
                    let row = this.events[i];
                    let template = $($('template[name="template_profile_event"]').html());
                    let dateEvent = row.date_event ? Dates().formatHumanDate(row.date_event) : Dates().formatHumanDate(row.date_created);
                    let dateUpdated = row.date_updated ? Dates().formatHumanDateTime(row.date_updated) : Dates().formatHumanDateTime(row.date_created);
                    template.find('[name="title"]').text(row.title || '');
                    template.find('[name="body"]').html(String(row.body || ''));
                    template.find('[name="date_event"]').text(dateEvent || '-');
                    template.find('[name="date_updated"]').html(dateUpdated || '-');

                    let locationLabel = '';
                    if (row.location_name) {
                        locationLabel = 'Luogo: ' + row.location_name;
                    }
                    if (locationLabel !== '') {
                        template.find('[name="location"]').text(locationLabel).removeClass('d-none');
                    } else {
                        template.find('[name="location"]').addClass('d-none');
                    }

                    let visibility = template.find('[name="visibility"]');
                    if (row.is_visible != null && parseInt(row.is_visible, 10) === 0) {
                        visibility.removeClass('d-none');
                        if (visibility.length) {
                            initTooltips(visibility[0].parentNode || visibility[0]);
                        }
                    } else {
                        visibility.addClass('d-none');
                    }

                    let archiveLinkWrap = template.find('[name="archive_link_wrap"]');
                    let archiveLink = template.find('[name="archive_link"]');
                    let linkedArchiveId = parseInt(row.linked_archive_id || 0, 10) || 0;
                    let linkedArchivesCount = parseInt(row.linked_archives_count || 0, 10) || 0;
                    if (archiveLinkWrap.length && archiveLink.length) {
                        if (linkedArchiveId > 0) {
                            let archiveLabel = row.linked_archive_title || ('Archivio #' + linkedArchiveId);
                            if (linkedArchivesCount > 1) {
                                archiveLabel += ' +' + (linkedArchivesCount - 1);
                            }
                            archiveLink
                                .attr('href', '/game/chat-archives?archive=' + linkedArchiveId + '&diary_event=' + (parseInt(row.id || 0, 10) || 0))
                                .text(archiveLabel);
                            archiveLinkWrap.removeClass('d-none');
                        } else {
                            archiveLinkWrap.addClass('d-none');
                        }
                    }

                    let actions = template.find('[name="actions"]').empty();
                    if (row.can_edit == 1) {
                        let editBtn = $('<button type="button" class="btn btn-sm btn-outline-primary"><span class="bi bi-pencil"></span></button>');
                        editBtn.on('click', this.editEvent.bind(this, row));
                        actions.append(editBtn);
                    }
                    if (row.can_delete == 1) {
                        let delBtn = $('<button type="button" class="btn btn-sm btn-outline-danger"><span class="bi bi-trash"></span></button>');
                        delBtn.on('click', this.deleteEvent.bind(this, row.id));
                        actions.append(delBtn);
                    }

                    block.append(template);
                }
            },
            openDiaryModal: function () {
                if (typeof editDiaryModal === 'undefined') {
                    return;
                }
                editDiaryModal.show({
                    character_id: this.character_id
                });
            },
            editEvent: function (row) {
                if (typeof editDiaryModal === 'undefined') {
                    return;
                }
                editDiaryModal.show({
                    id: row.id,
                    character_id: this.character_id,
                    title: row.title || '',
                    body: row.body || '',
                    location_id: row.location_id || '',
                    date_event: row.date_event || '',
                    is_visible: (row.is_visible != null) ? row.is_visible : 1
                });
            },
            deleteEvent: function (event_id) {
                var self = this;
                Dialog('danger', {
                    title: 'Eliminazione avvenimento',
                    body: '<p>Vuoi davvero eliminare questo avvenimento?</p>'
                }, function () {
                    var dialog = this;
                    callProfileModule('deleteEvent', event_id, function () {
                        dialog.hide();
                        self.loadEvents();
                        Toast.show({
                            body: 'Avvenimento eliminato.',
                            type: 'success'
                        });
                    }, function (error) {
                        if (dialog.setNormalStatus) {
                            dialog.setNormalStatus();
                        }
                        Toast.show({
                            body: normalizeProfileError(error, 'Errore durante eliminazione avvenimento.'),
                            type: 'error'
                        });
                    });
                }).show();
            },
            loadEventLocations: function (selected) {
                var self = this;
                let select = $('#edit-diary-modal-form select[name=location_id]');
                if (!select.length) {
                    return;
                }

                if (this.event_locations_loaded) {
                    this.populateEventLocations(select, selected);
                    return;
                }
                callProfileModule('listLocations', { results: 500, page: 1, orderBy: 'id|ASC' }, function (response) {
                    self.event_locations = response.dataset || [];
                    self.event_locations_loaded = true;
                    self.populateEventLocations(select, selected);
                }, function (error) {
                    console.warn('[ProfilePage] loadEventLocations failed', error);
                });
            },
            populateEventLocations: function (select, selected) {
                select.empty();
                select.append('<option value="">Nessun luogo</option>');
                for (var i in this.event_locations) {
                    let row = this.event_locations[i];
                    select.append('<option value="' + row.id + '">' + row.name + '</option>');
                }
                if (selected !== null && selected !== undefined && selected !== '') {
                    select.val(selected);
                }
            },
        }

        let profile = Object.assign({}, page, extension);
        return profile.init();
    }

globalWindow.GameProfilePage = GameProfilePage;
export { GameProfilePage as GameProfilePage };
export default GameProfilePage;

