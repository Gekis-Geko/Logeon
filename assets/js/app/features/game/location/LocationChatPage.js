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

function normalizeLocationChatError(error, fallback) {
    if (globalWindow.GameFeatureError && typeof globalWindow.GameFeatureError.normalize === 'function') {
        return globalWindow.GameFeatureError.normalize(error, fallback || 'Operazione non riuscita.');
    }
    if (typeof error === 'string' && error.trim() !== '') {
        return error.trim();
    }
    if (error && typeof error.message === 'string' && error.message.trim() !== '') {
        return error.message.trim();
    }
    return fallback || 'Operazione non riuscita.';
}


function GameLocationChatPage(extension) {
        let page = {
            location_id: null,
            character_id: null,
            is_staff: false,
            last_id: 0,
            renderedMessageIds: {},
            loading: false,
            polling: null,
            pollKey: null,
            searchTimer: null,
            searchToken: 0,
            elements: {},
            positionTags: [],
            locationChatModule: null,
            abilitiesModule: null,
            inventoryModule: null,

            init: function () {
                this.location_id = parseInt($('[name="location_id"]').val(), 10);
                this.character_id = parseInt($('[name="character_id"]').val(), 10);
                this.is_staff = $('[data-requires-staff]').length > 0;
                if (!this.location_id || !$('#chat_messages').length) {
                    return this;
                }
                this.cache();
                this.bind();
                this.bindQuickActions();
                this.loadPositionTags();
                this.load();
                this.startPolling();
                return this;
            },
            cache: function () {
                this.elements = {
                    body: $('[name="chat_body"]'),
                    tag: $('[name="tag_position"]'),
                    send: $('[data-chat-send]'),
                    list: $('#chat_messages'),
                    empty: $('#chat_empty'),
                    commandSuggest: $('.chat-command-suggest'),
                    tagId: $('[name="location_tag_id"]'),
                    tagLabel: $('[name="location_tag_label"]'),
                    tagDetail: $('[name="location_tag_detail"]'),
                    tagDisplay: $('[name="location_tag_display"]'),
                    positionTagsPanel: $('#location-position-tags-panel'),
                    positionTagsList: $('#location-position-tags-list'),
                    positionTagsSuggest: $('#location-tag-position-suggestions'),
                    descriptionTagsSection: $('#location-description-tags-section'),
                    descriptionTagsList: $('#location-description-tags-list')
                };
            },
            getLocationChatModule: function () {
                if (this.locationChatModule) {
                    return this.locationChatModule;
                }
                if (typeof resolveModule !== 'function') {
                    return null;
                }

                this.locationChatModule = resolveModule('game.location.chat');
                return this.locationChatModule;
            },
            getAbilitiesModule: function () {
                if (this.abilitiesModule) {
                    return this.abilitiesModule;
                }
                if (typeof resolveModule !== 'function') {
                    return null;
                }

                var moduleKey = '';
                var sceneNode = document.getElementById('location-scene-abilities-body');
                if (sceneNode) {
                    moduleKey = String(sceneNode.getAttribute('data-module-key') || '').trim();
                }
                if (!moduleKey) {
                    var quickNode = document.getElementById('location-skills-body');
                    if (quickNode) {
                        moduleKey = String(quickNode.getAttribute('data-module-key') || '').trim();
                    }
                }
                if (!moduleKey) {
                    return null;
                }

                this.abilitiesModule = resolveModule(moduleKey);
                return this.abilitiesModule;
            },
            isAbilitiesModuleAvailable: function () {
                return !!this.getAbilitiesModule();
            },
            getInventoryModule: function () {
                if (this.inventoryModule) {
                    return this.inventoryModule;
                }
                if (typeof resolveModule !== 'function') {
                    return null;
                }

                this.inventoryModule = resolveModule('game.inventory');
                return this.inventoryModule;
            },
            callLocationChat: function (method, payload, onSuccess, onError) {
                var mod = this.getLocationChatModule();
                var fn = String(method || '').trim();
                if (!mod || fn === '' || typeof mod[fn] !== 'function') {
                    if (typeof onError === 'function') {
                        onError(new Error('Modulo chat luogo non disponibile: ' + fn));
                    }
                    return false;
                }

                mod[fn](payload || {}).then(function (response) {
                    if (typeof onSuccess === 'function') {
                        onSuccess(response);
                    }
                }).catch(function (error) {
                    if (typeof onError === 'function') {
                        onError(error);
                    }
                });

                return true;
            },
            callAbilities: function (method, payload, onSuccess, onError) {
                var mod = this.getAbilitiesModule();
                var fn = String(method || '').trim();
                if (!mod || fn === '' || typeof mod[fn] !== 'function') {
                    if (typeof onError === 'function') {
                        onError(new Error('Abilities module not available: ' + fn));
                    }
                    return false;
                }

                mod[fn](payload || {}).then(function (response) {
                    if (typeof onSuccess === 'function') {
                        onSuccess(response);
                    }
                }).catch(function (error) {
                    if (typeof onError === 'function') {
                        onError(error);
                    }
                });

                return true;
            },
            callInventory: function (method, payload, onSuccess, onError) {
                var mod = this.getInventoryModule();
                var fn = String(method || '').trim();
                if (!mod || fn === '' || typeof mod[fn] !== 'function') {
                    if (typeof onError === 'function') {
                        onError(new Error('Inventory module not available: ' + fn));
                    }
                    return false;
                }

                mod[fn](payload || {}).then(function (response) {
                    if (typeof onSuccess === 'function') {
                        onSuccess(response);
                    }
                }).catch(function (error) {
                    if (typeof onError === 'function') {
                        onError(error);
                    }
                });

                return true;
            },
            bind: function () {
                var self = this;
                this.elements.send.off('click.locationChat').on('click.locationChat', function () {
                    self.send();
                });
                this.elements.body.off('input.locationChat').on('input.locationChat', function () {
                    self.handleCommandSuggest();
                });
                this.elements.body.off('keydown.locationChat').on('keydown.locationChat', function (e) {
                    if (e.ctrlKey && e.key === 'Enter') {
                        self.send();
                        return;
                    }
                    if (e.key === 'Escape') {
                        self.hideCommandSuggest();
                    }
                });
                this.elements.commandSuggest.off('click.locationChat').on('click.locationChat', '[data-command-value]', function () {
                    var value = $(this).attr('data-command-value') || '';
                    self.elements.body.val(value).focus();
                    self.hideCommandSuggest();
                });
                $(document).off('click.locationCommandSuggest').on('click.locationCommandSuggest', function (event) {
                    if (!$(event.target).closest('.chat-command-suggest, [name="chat_body"]').length) {
                        self.hideCommandSuggest();
                    }
                });

                this.elements.tag.off('input.positionTagSuggest').on('input.positionTagSuggest', function () {
                    self.clearPositionTagSelection();
                    self.showPositionTagSuggestions($(this).val());
                });
                this.elements.tag.off('keydown.positionTagSuggest').on('keydown.positionTagSuggest', function (e) {
                    if (e.key === 'Escape') { self.hidePositionTagSuggestions(); }
                });
                $(document).off('click.positionTagSuggest').on('click.positionTagSuggest', function (event) {
                    if (!$(event.target).closest('#location-tag-position, #location-tag-position-suggestions').length) {
                        self.hidePositionTagSuggestions();
                    }
                });
                if (this.elements.positionTagsSuggest && this.elements.positionTagsSuggest.length) {
                    this.elements.positionTagsSuggest.off('click.positionTagSuggest').on('click.positionTagSuggest', '[data-position-tag-id]', function () {
                        var id   = parseInt($(this).attr('data-position-tag-id') || '0', 10);
                        var name = $(this).attr('data-position-tag-name') || '';
                        if (!id || !name) { return; }
                        self.selectPositionTag(id, name);
                        self.hidePositionTagSuggestions();
                    });
                }
            },
            escapeHtml: function (value) {
                return $('<div/>').text(value || '').html();
            },
            parseMetaJson: function (row) {
                if (!row || typeof row !== 'object') {
                    return null;
                }
                if (row.meta_json == null || row.meta_json === '') {
                    return null;
                }
                if (typeof row.meta_json === 'object') {
                    return row.meta_json;
                }
                if (typeof row.meta_json !== 'string') {
                    return null;
                }
                try {
                    return JSON.parse(row.meta_json);
                } catch (error) {
                    return null;
                }
            },
            buildDiceSystemBody: function (row) {
                if (!row || parseInt(row.type, 10) !== 3) {
                    return null;
                }

                var meta = this.parseMetaJson(row);
                if (!meta || String(meta.command || '').toLowerCase() !== 'dice') {
                    return null;
                }

                var expression = String(meta.expr || meta.expression || '').trim();
                var rolls = Array.isArray(meta.rolls) ? meta.rolls : [];
                var modifiers = Array.isArray(meta.modifiers) ? meta.modifiers : [];
                var total = parseInt(meta.total, 10);
                if (isNaN(total)) {
                    total = 0;
                }

                var formatted = String(meta.formatted_short || '').trim();
                if (typeof DiceEngine === 'function') {
                    try {
                        var engine = DiceEngine();
                        if (engine && typeof engine.format === 'function') {
                            formatted = engine.format({
                                expression: expression,
                                count: rolls.length || 1,
                                sides: parseInt(meta.sides, 10) || 20,
                                rolls: rolls,
                                modifiers: modifiers,
                                total: total
                            }, {
                                includeExpression: false
                            });
                        }
                    } catch (error) {}
                }

                if (!formatted) {
                    var rollLabel = '';
                    if (rolls.length > 1) {
                        rollLabel = rolls.join(' + ');
                    } else if (rolls.length === 1) {
                        rollLabel = String(rolls[0]);
                    } else {
                        rollLabel = String(total);
                    }

                    var mods = [];
                    for (var i = 0; i < modifiers.length; i++) {
                        var mod = parseInt(modifiers[i], 10);
                        if (isNaN(mod)) {
                            continue;
                        }
                        mods.push((mod >= 0 ? '+' : '') + mod);
                    }
                    if (mods.length) {
                        rollLabel += ' ' + mods.join(' ');
                    }
                    formatted = rollLabel + ' = ' + total;
                }

                var displayExpr = expression || '1d20';
                return '<div class="text-center"><p class="mb-1"><b>Dado</b> ' + this.escapeHtml(displayExpr) + '</p><p class="lead mb-0"><b>Risultato: <em>' + this.escapeHtml(formatted) + '</em></b></p></div>';
            },
            bindQuickActions: function () {
                var self = this;
                var root = $('#location-page');
                if (!root.length) {
                    return;
                }
                root.off('click.locationActions');
                root.on('click.locationActions', '[data-skill-use], [data-skill-use-id]', function (event) {
                    event.preventDefault();
                    var abilityId = parseInt($(this).attr('data-skill-use-id') || '0', 10);
                    if (isNaN(abilityId)) {
                        abilityId = 0;
                    }
                    var name = ($(this).attr('data-skill-use-name') || $(this).attr('data-skill-use') || '').trim();
                    if (name === '') {
                        return;
                    }
                    var body = '<p>Sei sicuro di voler usare <b>' + self.escapeHtml(name) + '</b>?</p>';
                    let dialog = Dialog('warning', {
                        title: 'Usa skill',
                        body: body
                    }, function () {
                        hideGeneralConfirmDialog();
                        self.useAbilityQuickAction(abilityId, name);
                    });
                    dialog.show();
                });
                root.on('click.locationActions', '[data-item-use], [data-item-use-id]', function (event) {
                    event.preventDefault();
                    var name = $(this).attr('data-item-use-name') || $(this).attr('data-item-use') || '';
                    var inventoryItemId = parseInt($(this).attr('data-item-use-id') || '0', 10);
                    if (isNaN(inventoryItemId)) {
                        inventoryItemId = 0;
                    }
                    name = (name || '').trim();
                    if (name === '') {
                        return;
                    }
                    var body = '<p>Sei sicuro di voler usare <b>' + self.escapeHtml(name) + '</b>?</p>';
                    let dialog = Dialog('warning', {
                        title: 'Usa oggetto',
                        body: body
                    }, function () {
                        hideGeneralConfirmDialog();
                        if (inventoryItemId > 0) {
                            self.useItemQuickAction(inventoryItemId, name);
                        } else {
                            self.sendCommand('/oggetto ' + name);
                        }
                    });
                    dialog.show();
                });
                root.on('click.locationActions', '[data-action="report-message"]', function (event) {
                    event.preventDefault();
                    var messageId = parseInt($(this).attr('data-message-id') || '0', 10);
                    if (!messageId) { return; }
                    self.openReportModal(messageId);
                });
            },
            openReportModal: function (messageId) {
                var modalEl = document.getElementById('modal-report-message');
                if (!modalEl) { return; }
                var form = document.getElementById('report-message-form');
                if (form) { form.reset(); }
                var msgInput = document.getElementById('report-message-id');
                if (msgInput) { msgInput.value = String(messageId); }
                var textWrap = document.getElementById('report-reason-text-wrap');
                if (textWrap) { textWrap.style.display = 'none'; }
                var reasonSel = document.getElementById('report-reason-code');
                if (reasonSel) {
                    $(reasonSel).off('change.reportModal').on('change.reportModal', function () {
                        var val = $(this).val();
                        if (textWrap) { textWrap.style.display = (val !== '') ? '' : 'none'; }
                        var reqSpan = document.getElementById('report-reason-text-required');
                        if (reqSpan) {
                            if (val === 'other') { reqSpan.classList.remove('d-none'); }
                            else                 { reqSpan.classList.add('d-none'); }
                        }
                    });
                }
                var submitBtn = document.getElementById('report-message-submit');
                if (submitBtn) {
                    $(submitBtn).off('click.reportModal').on('click.reportModal', function () {
                        var self2 = this;
                        var reasonCode = reasonSel ? reasonSel.value : '';
                        if (!reasonCode) { alert('Seleziona un motivo.'); return; }
                        var reasonTextEl = document.getElementById('report-reason-text');
                        var reasonText = reasonTextEl ? reasonTextEl.value.trim() : '';
                        if (reasonCode === 'other' && reasonText === '') {
                            alert('La descrizione è obbligatoria per il motivo selezionato.');
                            return;
                        }
                        $(self2).prop('disabled', true);
                        var http = globalWindow.Request && globalWindow.Request.http ? globalWindow.Request.http : null;
                        var payload = { message_id: messageId, reason_code: reasonCode, reason_text: reasonText };
                        var done = function () {
                            bootstrap.Modal.getInstance(modalEl).hide();
                            if (globalWindow.Toast && typeof globalWindow.Toast.show === 'function') {
                                globalWindow.Toast.show('Segnalazione inviata', 'success');
                            }
                        };
                        var fail = function (err) {
                            $(self2).prop('disabled', false);
                            var msg = globalWindow.Request && typeof globalWindow.Request.getErrorMessage === 'function'
                                ? globalWindow.Request.getErrorMessage(err) : 'Errore nell\'invio della segnalazione.';
                            alert(msg);
                        };
                        if (http && typeof http.post === 'function') {
                            http.post('/message/reports/create', payload).then(done).catch(fail);
                        } else if (typeof globalWindow.$ === 'function') {
                            globalWindow.$.ajax({
                                url: '/message/reports/create', method: 'POST',
                                contentType: 'application/json',
                                data: JSON.stringify({ data: payload }),
                                success: done, error: fail
                            });
                        }
                    });
                }
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            },
            useAbilityQuickAction: function (abilityId, abilityName) {
                var self = this;
                var payload = {
                    location_id: this.location_id,
                    scene_id: this.location_id
                };
                if (abilityId > 0) {
                    payload.ability_id = abilityId;
                }
                if (abilityName) {
                    payload.ability_name = abilityName;
                    payload.ability = abilityName;
                }

                var started = this.callAbilities('use', payload, function (response) {
                    var dataset = (response && response.dataset) ? response.dataset : {};
                    if (dataset.chat_message) {
                        self.appendMessages([dataset.chat_message]);
                    } else {
                        self.load(true);
                    }
                }, function (error) {
                    if (globalWindow.GameFeatureError && typeof globalWindow.GameFeatureError.toastMapped === 'function') {
                        globalWindow.GameFeatureError.toastMapped(error, 'Uso skill non riuscito.', {
                            defaultType: 'error',
                            validationType: 'warning',
                            validationCodes: [
                                'ability_not_found',
                                'ability_inactive',
                                'ability_forbidden',
                                'ability_target_required',
                                'state_not_found',
                                'state_conflict_blocked',
                                'state_apply_failed',
                                'state_remove_failed',
                                'validation_error'
                            ],
                            map: {
                                ability_not_found: 'Abilita non trovata.',
                                ability_inactive: 'Abilita non attiva.',
                                ability_forbidden: 'Abilita non disponibile per il personaggio.',
                                ability_target_required: 'Questa abilita richiede un target.',
                                state_not_found: 'Stato narrativo non trovato.',
                                state_conflict_blocked: 'Conflitto con uno stato narrativo gia attivo.',
                                state_apply_failed: 'Applicazione stato non riuscita.',
                                state_remove_failed: 'Rimozione stato non riuscita.'
                            }
                        });
                        return;
                    }
                    Toast.show({
                        body: normalizeLocationChatError(error, 'Uso skill non riuscito.'),
                        type: 'error'
                    });
                });

                if (!started) {
                    if (!this.isAbilitiesModuleAvailable()) {
                        if (globalWindow.Toast && typeof globalWindow.Toast.show === 'function') {
                            globalWindow.Toast.show({
                                body: 'Funzionalita abilita non attiva.',
                                type: 'warning'
                            });
                        }
                        return;
                    }
                    this.sendCommand('/skill ' + String(abilityName || '').trim());
                }
            },
            useItemQuickAction: function (inventoryItemId, itemName) {
                var self = this;
                var payload = {
                    inventory_item_id: inventoryItemId
                };

                var started = this.callInventory('useItem', payload, function () {
                    if (globalWindow.LocationChat && typeof globalWindow.LocationChat.load === 'function') {
                        globalWindow.LocationChat.load(true);
                    }
                    if (typeof globalWindow.LocationSidebar !== 'undefined' && globalWindow.LocationSidebar && typeof globalWindow.LocationSidebar.reloadInventory === 'function') {
                        globalWindow.LocationSidebar.reloadInventory();
                    }
                    if (globalWindow.Toast && typeof globalWindow.Toast.show === 'function') {
                        globalWindow.Toast.show({
                            body: 'Oggetto usato: ' + String(itemName || 'Oggetto') + '.',
                            type: 'success'
                        });
                    }
                }, function (error) {
                    if (globalWindow.GameFeatureError && typeof globalWindow.GameFeatureError.toastMapped === 'function') {
                        globalWindow.GameFeatureError.toastMapped(error, 'Uso oggetto non riuscito.', {
                            defaultType: 'error',
                            validationType: 'warning',
                            validationCodes: [
                                'character_invalid',
                                'item_invalid',
                                'item_not_found',
                                'item_not_usable',
                                'item_cooldown_active',
                                'ammo_required',
                                'ammo_not_enough',
                                'ammo_reload_required',
                                'ammo_reload_not_supported',
                                'ammo_reload_disabled_in_narrative',
                                'ammo_magazine_full',
                                'item_needs_maintenance',
                                'item_jammed',
                                'item_maintenance_not_supported',
                                'validation_error'
                            ],
                            map: {
                                character_invalid: 'Personaggio non valido.',
                                item_invalid: 'Oggetto non valido.',
                                item_not_found: 'Oggetto non trovato.',
                                item_not_usable: 'Questo oggetto non puo essere usato.',
                                item_cooldown_active: 'Oggetto in cooldown: attendi prima di riutilizzarlo.',
                                ammo_required: 'L\'arma da tiro non ha una munizione configurata.',
                                ammo_not_enough: 'Munizioni insufficienti per usare questa arma.',
                                ammo_reload_required: 'Arma scarica: ricarica prima di usarla.',
                                ammo_reload_not_supported: 'Ricarica non supportata per questo oggetto.',
                                ammo_reload_disabled_in_narrative: 'Ricarica disponibile solo con conflitto casuale attivo.',
                                ammo_magazine_full: 'Caricatore gia pieno.',
                                item_needs_maintenance: 'Questo equipaggiamento richiede manutenzione.',
                                item_jammed: 'L\'arma si e inceppata.',
                                item_maintenance_not_supported: 'Manutenzione non disponibile per questo oggetto.'
                            }
                        });
                        return;
                    }

                    Toast.show({
                        body: normalizeLocationChatError(error, 'Uso oggetto non riuscito.'),
                        type: 'error'
                    });
                });

                if (!started) {
                    this.sendCommand('/oggetto ' + String(itemName || '').trim());
                }
            },
            startPolling: function () {
                var self = this;
                this.stopPolling();
                this.pollKey = 'location.chat.' + this.location_id;

                if (typeof PollManager === 'function') {
                    this.polling = PollManager().start(this.pollKey, function () {
                        self.load(true);
                    }, 4000);
                    return;
                }

                this.polling = setInterval(function () {
                    self.load(true);
                }, 4000);
            },
            stopPolling: function () {
                if (this.polling) {
                    clearInterval(this.polling);
                    this.polling = null;
                }
                if (this.pollKey && typeof PollManager === 'function') {
                    PollManager().stop(this.pollKey);
                }
                this.pollKey = null;
            },
            send: function () {
                var text = this.elements.body.val();
                if (!text || text.trim() === '') {
                    return;
                }
                this.hideCommandSuggest();
                var data = {
                    location_id: this.location_id,
                    body: text,
                    tag_position: this.elements.tag.val()
                };
                var tagId = this.elements.tagId.length ? parseInt(this.elements.tagId.val() || '0', 10) : 0;
                if (tagId > 0) {
                    data.location_tag_id      = tagId;
                    data.location_tag_label   = this.elements.tagLabel.val() || '';
                    data.location_tag_detail  = this.elements.tagDetail.val() || '';
                    data.location_tag_display = this.elements.tagDisplay.val() || '';
                }
                var self = this;
                var onSuccess = function (response) {
                    self.elements.body.val('');
                    self.clearPositionTagSelection(true);
                if (response && response.channel === 'whisper') {
                    if (typeof globalWindow.LocationWhispers !== 'undefined') {
                        globalWindow.LocationWhispers.appendMessage(response.dataset);
                    }
                    return;
                }
                if (response && response.dataset) {
                    let messageId = parseInt(response.dataset.id, 10);
                    if (!isNaN(messageId) && messageId > self.last_id) {
                        self.last_id = messageId;
                    }
                    self.appendMessages([response.dataset]);
                } else {
                    self.load(true);
                }
            };
                this.callLocationChat('send', data, onSuccess, function (error) {
                    Toast.show({
                        body: normalizeLocationChatError(error, 'Invio messaggio non riuscito.'),
                        type: 'error'
                    });
                });
            },
            sendCommand: function (command) {
                if (typeof CommandParser === 'function') {
                    let parser = CommandParser();
                    let raw = (command || '').toString().trim();
                    let parsed = (parser && typeof parser.parse === 'function')
                        ? parser.parse(raw)
                        : null;

                    if (parsed && parsed.isCommand && parser && typeof parser.validate === 'function') {
                        let validationResult = parser.validate(raw);
                        if (validationResult && validationResult.type === 'dice' && validationResult.ok !== true) {
                            Toast.show({
                                body: validationResult.message || 'Formato dado non valido. Esempio: /dado 2d6+1',
                                type: 'warning'
                            });
                            return;
                        }
                    } else if (parser && typeof parser.isCommand === 'function' && parser.isCommand(raw)) {
                        if (typeof parser.isDiceCommand === 'function' && parser.isDiceCommand(raw)) {
                            let args = (parsed && typeof parsed.args === 'string')
                                ? parsed.args
                                : raw.replace(/^\/\S+\s*/i, '');
                            let validation = null;
                            if (typeof parser.validateDiceArgsDetailed === 'function') {
                                validation = parser.validateDiceArgsDetailed(args);
                            } else {
                                validation = {
                                    ok: parser.validateDiceArgs(args),
                                    message: 'Formato dado non valido. Esempio: /dado 2d6+1'
                                };
                            }
                            if (!validation || validation.ok !== true) {
                                Toast.show({
                                    body: (validation && validation.message) ? validation.message : 'Formato dado non valido. Esempio: /dado 2d6+1',
                                    type: 'warning'
                                });
                                return;
                            }
                        }
                    }
                }
                this.elements.body.val(command);
                this.hideCommandSuggest();
                this.send();
            },
            getBaseCommandItems: function (query) {
                var items = [];
                if (typeof CommandParser === 'function') {
                    items = CommandParser().getCommandSuggestions(query);
                } else {
                    var commands = [
                        {
                            key: '/dado',
                            value: '/dado 1d20',
                            hint: 'Tiro di dado. Esempio: /dado 2d6'
                        },
                        {
                            key: '/skill',
                            value: '/skill ',
                            hint: 'Usa un\'abilita in chat'
                        },
                        {
                            key: '/oggetto',
                            value: '/oggetto ',
                            hint: 'Usa un oggetto in chat'
                        },
                        {
                            key: '/sussurra',
                            value: '/sussurra "',
                            hint: 'Sussurro 1:1'
                        }
                    ];
                    if (this.is_staff) {
                        commands.push({
                            key: '/fato',
                            value: '/fato ',
                            hint: 'Narrazione Fato (solo master/staff)'
                        });
                    }

                    var normalized = (query || '').toLowerCase();
                    if (normalized === '' || normalized === '/') {
                        items = commands;
                    } else {
                        var filtered = [];
                        for (var i = 0; i < commands.length; i++) {
                            if (commands[i].key.indexOf(normalized) === 0) {
                                filtered.push(commands[i]);
                            }
                        }
                        items = filtered;
                    }
                }

                if (!this.isAbilitiesModuleAvailable()) {
                    var filteredItems = [];
                    for (var x = 0; x < items.length; x++) {
                        var row = items[x] || {};
                        var key = String(row.key || '').toLowerCase();
                        var value = String(row.value || '').toLowerCase();
                        if (key === '/skill' || value.indexOf('/skill ') === 0) {
                            continue;
                        }
                        filteredItems.push(row);
                    }
                    items = filteredItems;
                }

                if (!this.is_staff) {
                    var staffFiltered = [];
                    for (var s = 0; s < items.length; s++) {
                        var sk = String((items[s] || {}).key || '').toLowerCase();
                        if (sk === '/fato') { continue; }
                        staffFiltered.push(items[s]);
                    }
                    items = staffFiltered;
                }

                return items;
            },
            hideCommandSuggest: function () {
                if (this.elements.commandSuggest && this.elements.commandSuggest.length) {
                    this.elements.commandSuggest.addClass('d-none').empty();
                }
            },
            renderCommandSuggest: function (items) {
                if (!this.elements.commandSuggest || !this.elements.commandSuggest.length) {
                    return;
                }
                this.elements.commandSuggest.empty();
                if (!items || !items.length) {
                    this.hideCommandSuggest();
                    return;
                }

                for (var i = 0; i < items.length; i++) {
                    var item = items[i];
                    var row = $('<button type="button" class="list-group-item list-group-item-action"></button>');
                    row.attr('data-command-value', item.value || '');
                    row.html(
                        '<div class="d-flex justify-content-between align-items-center">'
                            + '<span><b>' + this.escapeHtml(item.key || '') + '</b></span>'
                            + '<small class="text-muted">' + this.escapeHtml(item.hint || '') + '</small>'
                        + '</div>'
                    );
                    this.elements.commandSuggest.append(row);
                }

                this.elements.commandSuggest.removeClass('d-none');
            },
            searchCharactersForWhisper: function (query, onDone) {
                var self = this;
                this.searchToken += 1;
                var token = this.searchToken;
                var payload = { query: query, location_id: this.location_id };
                var onSuccess = function (response) {
                    if (token !== self.searchToken) {
                        return;
                    }
                    onDone(response && response.dataset ? response.dataset : []);
                };
                this.callLocationChat('searchTargets', payload, onSuccess, function () {
                    if (token !== self.searchToken) {
                        return;
                    }
                    onDone([]);
                });
            },
            handleCommandSuggest: function () {
                var self = this;
                var raw = this.elements.body.val() || '';
                var text = raw.replace(/^\s+/, '');
                var parser = (typeof CommandParser === 'function') ? CommandParser() : null;
                var parsed = (parser && typeof parser.parse === 'function') ? parser.parse(text) : null;
                if (parser && parsed && !parsed.isCommand) {
                    this.hideCommandSuggest();
                    return;
                }
                if (parser && !parsed && typeof parser.isCommand === 'function' && !parser.isCommand(text)) {
                    this.hideCommandSuggest();
                    return;
                }
                // '#' è alias di /fato — mostra suggerimento solo allo staff
                if (text.charAt(0) === '#' && this.is_staff) {
                    this.renderCommandSuggest([{
                        key: '#',
                        value: '# ',
                        hint: 'Narrazione Fato (alias /fato)'
                    }]);
                    return;
                }

                if (text === '' || text.charAt(0) !== '/') {
                    this.hideCommandSuggest();
                    return;
                }

                var whisperMatch = null;
                var query = '';
                var hasWhisper = false;
                if (parser) {
                    let whisperData = parser.extractWhisperQuery(text);
                    if (whisperData !== null) {
                        hasWhisper = true;
                        if (whisperData.completed) {
                            this.hideCommandSuggest();
                            return;
                        }
                        query = whisperData.query || '';
                    }
                } else {
                    whisperMatch = text.match(/^\/(sussurra|w)\s+(.*)$/i);
                    if (whisperMatch) {
                        hasWhisper = true;
                        var remainder = whisperMatch[2] || '';
                        if (remainder.charAt(0) === '"') {
                            var closeQuoteIndex = remainder.indexOf('"', 1);
                            if (closeQuoteIndex > 0) {
                                this.hideCommandSuggest();
                                return;
                            }
                            query = remainder.substring(1).trim();
                        } else {
                            if (remainder.indexOf(' ') >= 0) {
                                this.hideCommandSuggest();
                                return;
                            }
                            query = remainder.trim();
                        }
                    }
                }
                if (hasWhisper) {

                    if (query.length < 2) {
                        this.hideCommandSuggest();
                        return;
                    }

                    if (this.searchTimer) {
                        clearTimeout(this.searchTimer);
                    }
                    this.searchTimer = setTimeout(function () {
                        self.searchCharactersForWhisper(query, function (dataset) {
                            var items = [];
                            for (var i = 0; i < dataset.length; i++) {
                                var row = dataset[i];
                                var fullName = ((row.name || '') + ' ' + (row.surname || '')).trim();
                                if (fullName === '') {
                                    continue;
                                }
                                if (parser) {
                                    let whisperItem = parser.buildWhisperSuggestion(fullName);
                                    if (whisperItem) {
                                        items.push(whisperItem);
                                    }
                                } else {
                                    items.push({
                                        key: fullName,
                                        value: '/sussurra "' + fullName + '" ',
                                        hint: 'Invia sussurro'
                                    });
                                }
                            }
                            self.renderCommandSuggest(items);
                        });
                    }, 180);
                    return;
                }

                if (this.searchTimer) {
                    clearTimeout(this.searchTimer);
                }
                var command = (parser && parsed)
                    ? ((parsed.token || '') || '/')
                    : (parser
                        ? (parser.getCommandToken(text) || '/')
                        : (text.split(/\s+/)[0] || '/'));
                this.renderCommandSuggest(this.getBaseCommandItems(command));
            },
            load: function (incremental) {
                if (this.loading) {
                    return;
                }
                this.loading = true;
                var self = this;
                var data = {
                    location_id: this.location_id,
                    limit: 50
                };
                if (incremental && this.last_id > 0) {
                    data.since_id = this.last_id;
                }
                var onSuccess = function (response) {
                    self.loading = false;
                    if (!response || !response.dataset) {
                        return;
                    }
                    if (response.last_id) {
                        self.last_id = response.last_id;
                    }
                    self.appendMessages(response.dataset, !incremental);
                };
                this.callLocationChat('list', data, onSuccess, function () {
                    self.loading = false;
                });
            },
            appendMessages: function (dataset, replace) {
                if (!dataset || dataset.length === 0) {
                    if (replace && this.elements.list.children().length === 0) {
                        this.elements.empty.removeClass('d-none');
                    }
                    return;
                }
                if (replace) {
                    this.elements.list.empty();
                    this.renderedMessageIds = {};
                }
                this.elements.empty.addClass('d-none');
                for (var i in dataset) {
                    var row = dataset[i];
                    this.renderMessage(row);
                }
                $('#chat_display').scrollTop($('#chat_display')[0].scrollHeight);
            },
            renderMessage: function (row) {
                let messageId = (row && row.id != null) ? parseInt(row.id, 10) : 0;
                if (!isNaN(messageId) && messageId > 0) {
                    if (this.renderedMessageIds[messageId] === true) {
                        return;
                    }
                    this.renderedMessageIds[messageId] = true;
                }

                var isMe = String(row.character_id) === String(this.character_id);
                var templateName = 'template_location_message_other';
                if (row.type == 3) {
                    templateName = 'template_location_message_system';
                } else if (isMe) {
                    templateName = 'template_location_message_me';
                }
                var template = $($('template[name="' + templateName + '"]').html());
                if (row.type != 3) {
                    var avatar = row.character_avatar || '/assets/imgs/defaults-images/default-profile.png';
                    template.find('.chat-avatar').attr('src', avatar).attr('alt', row.character_name || 'Avatar');
                    var time = row.date_created ? row.date_created.substr(11, 5) : '';
                    var name = row.character_name || '';
                    var surname = row.character_surname || '';
                    var label = isMe ? ('Tu <small>- ' + time + '</small>') : ('<small>' + time + ' -</small> ' + name + ' ' + surname);
                    template.find('.chat-meta').html(label.trim());
                    if (!isMe) {
                        var gender = row.character_gender;
                        var icon = '';
                        if (gender == 1) {
                            icon = ' <i class="bi bi-gender-male text-info" data-bs-toggle="tooltip" data-bs-title="Maschio"></i>';
                        } else if (gender == 2) {
                            icon = ' <i class="bi bi-gender-female text-danger" data-bs-toggle="tooltip" data-bs-title="Femmina"></i>';
                        }
                        template.find('.chat-meta').append(icon);
                        var reportBtn = template.find('.chat-report');
                        if (reportBtn.length) {
                            reportBtn
                                .attr('data-action', 'report-message')
                                .attr('data-message-id', String(row.id || ''))
                                .attr('data-message-author-id', String(row.character_id || ''))
                                .attr('data-location-id', String(this.location_id || ''));
                        }
                    } else {
                        template.find('.chat-report').remove();
                    }
                }
                var body = row.body_rendered || row.body || '';
                var diceBody = this.buildDiceSystemBody(row);
                if (diceBody !== null) {
                    body = diceBody;
                }
                if (row.tag_position) {
                    var tag = $('<span class="badge text-bg-light me-2"></span>').text('[' + row.tag_position + ']');
                    template.find('.chat-body').append(tag);
                }
                template.find('.chat-body').append(body);
                template.appendTo(this.elements.list);
            },
            loadPositionTags: function () {
                if (!this.location_id) { return; }
                if (!this.elements.positionTagsPanel || !this.elements.positionTagsPanel.length) { return; }

                var self = this;
                this.callLocationChat(
                    'listPositionTags',
                    { location_id: this.location_id },
                    function (response) {
                        var tags = response && Array.isArray(response.dataset) ? response.dataset : [];
                        self.renderPositionTags(tags);
                    },
                    function () {}
                );
            },

            renderPositionTags: function (tags) {
                var panel = this.elements.positionTagsPanel;
                var list  = this.elements.positionTagsList;
                if (!panel || !panel.length || !list || !list.length) { return; }

                this.positionTags = Array.isArray(tags) ? tags : [];

                if (!tags || !tags.length) {
                    panel.addClass('d-none');
                    return;
                }

                var self = this;
                var html = '';
                for (var i = 0; i < tags.length; i++) {
                    var t = tags[i];
                    var id   = parseInt(t.id || 0, 10);
                    var name = t.name || '';
                    if (!id || !name) { continue; }

                    var thumb = t.thumbnail
                        ? '<img src="' + this.escapeHtml(t.thumbnail) + '" alt="" class="location-position-tag__thumb" loading="lazy">'
                        : '';
                    var desc = t.short_description
                        ? '<div class="location-position-tag__desc small text-muted">' + this.escapeHtml(t.short_description) + '</div>'
                        : '';

                    html += '<button type="button"'
                        + ' class="location-position-tag btn btn-sm btn-outline-secondary w-100 text-start mb-1 d-flex align-items-start gap-2"'
                        + ' data-position-tag-id="' + id + '"'
                        + ' data-position-tag-name="' + this.escapeHtml(name) + '">'
                        + thumb
                        + '<span>'
                        + '<span class="d-block fw-semibold">' + this.escapeHtml(name) + '</span>'
                        + desc
                        + '</span>'
                        + '</button>';
                }

                if (!html) {
                    panel.addClass('d-none');
                    return;
                }

                list.html(html);
                panel.removeClass('d-none');

                list.off('click.positionTags').on('click.positionTags', '[data-position-tag-id]', function () {
                    var id   = parseInt($(this).attr('data-position-tag-id') || '0', 10);
                    var name = $(this).attr('data-position-tag-name') || '';
                    if (!id || !name) { return; }
                    self.selectPositionTag(id, name);
                });

                this.renderDescriptionModalTags(tags);
            },

            renderDescriptionModalTags: function (tags) {
                var section = this.elements.descriptionTagsSection;
                var list    = this.elements.descriptionTagsList;
                if (!section || !section.length || !list || !list.length) { return; }

                if (!tags || !tags.length) {
                    section.addClass('d-none');
                    return;
                }

                var html = '';
                for (var i = 0; i < tags.length; i++) {
                    var t = tags[i];
                    var name = t.name || '';
                    if (!name) { continue; }

                    var thumb = t.thumbnail
                        ? '<img src="' + this.escapeHtml(t.thumbnail) + '" alt=""'
                            + ' class="rounded me-3 flex-shrink-0"'
                            + ' style="width:48px;height:48px;object-fit:cover;"'
                            + ' loading="lazy">'
                        : '';
                    var desc = t.short_description
                        ? '<div class="small text-muted mt-1">' + this.escapeHtml(t.short_description) + '</div>'
                        : '';

                    html += '<div class="col-12 col-sm-6">'
                        + '<div class="d-flex align-items-center p-2 rounded" style="background:rgba(255,255,255,0.04);border:1px solid rgba(126,138,166,0.18);">'
                        + thumb
                        + '<div class="min-width-0">'
                        + '<div class="fw-semibold">' + this.escapeHtml(name) + '</div>'
                        + desc
                        + '</div>'
                        + '</div>'
                        + '</div>';
                }

                list.html(html);
                section.removeClass('d-none');
            },

            showPositionTagSuggestions: function (term) {
                var box = this.elements.positionTagsSuggest;
                if (!box || !box.length) { return; }
                var q = String(term || '').trim().toLowerCase();
                if (!q || !this.positionTags.length) {
                    box.addClass('d-none').empty();
                    return;
                }
                var results = [];
                for (var i = 0; i < this.positionTags.length && results.length < 8; i++) {
                    var t = this.positionTags[i];
                    if (String(t.name || '').toLowerCase().indexOf(q) >= 0) {
                        results.push(t);
                    }
                }
                if (!results.length) {
                    box.addClass('d-none').empty();
                    return;
                }
                var html = '';
                for (var j = 0; j < results.length; j++) {
                    var r = results[j];
                    var hint = r.short_description ? this.escapeHtml(r.short_description) : '';
                    html += '<button type="button" class="list-group-item list-group-item-action"'
                        + ' data-position-tag-id="' + parseInt(r.id, 10) + '"'
                        + ' data-position-tag-name="' + this.escapeHtml(r.name) + '">'
                        + '<div class="d-flex justify-content-between align-items-center">'
                        + '<span><b>' + this.escapeHtml(r.name) + '</b></span>'
                        + (hint ? '<small class="text-muted">' + hint + '</small>' : '')
                        + '</div>'
                        + '</button>';
                }
                box.html(html).removeClass('d-none');
            },

            hidePositionTagSuggestions: function () {
                var box = this.elements.positionTagsSuggest;
                if (box && box.length) { box.addClass('d-none').empty(); }
            },

            selectPositionTag: function (id, name) {
                if (!this.elements.tag || !this.elements.tag.length) { return; }
                this.elements.tag.val(name);
                if (this.elements.tagId.length)      { this.elements.tagId.val(String(id)); }
                if (this.elements.tagLabel.length)   { this.elements.tagLabel.val(name); }
                if (this.elements.tagDetail.length)  { this.elements.tagDetail.val(''); }
                if (this.elements.tagDisplay.length) { this.elements.tagDisplay.val(name); }
                this.elements.tag.focus();
            },

            clearPositionTagSelection: function (clearInput) {
                if (clearInput && this.elements.tag && this.elements.tag.length) { this.elements.tag.val(''); }
                if (this.elements.tagId && this.elements.tagId.length)           { this.elements.tagId.val(''); }
                if (this.elements.tagLabel && this.elements.tagLabel.length)     { this.elements.tagLabel.val(''); }
                if (this.elements.tagDetail && this.elements.tagDetail.length)   { this.elements.tagDetail.val(''); }
                if (this.elements.tagDisplay && this.elements.tagDisplay.length) { this.elements.tagDisplay.val(''); }
            },

            unbind: function () {
                this.stopPolling();

                if (this.searchTimer) {
                    clearTimeout(this.searchTimer);
                    this.searchTimer = null;
                }

                if (this.elements.send) {
                    this.elements.send.off('click.locationChat');
                }
                if (this.elements.body) {
                    this.elements.body.off('input.locationChat');
                    this.elements.body.off('keydown.locationChat');
                }
                if (this.elements.commandSuggest) {
                    this.elements.commandSuggest.off('click.locationChat');
                }

                $(document).off('click.locationCommandSuggest');
                $('#location-page').off('click.locationActions');
            },
            destroy: function () {
                this.hideCommandSuggest();
                this.unbind();
                return this;
            },
            unmount: function () {
                return this.destroy();
            }
        };
        let chat = Object.assign({}, page, extension);
        return chat.init();
}

globalWindow.GameLocationChatPage = GameLocationChatPage;
export { GameLocationChatPage as GameLocationChatPage };
export default GameLocationChatPage;

