(function (window) {
    'use strict';

    function resolveModule(name) {
        if (!window.RuntimeBootstrap || typeof window.RuntimeBootstrap.resolveAppModule !== 'function') {
            return null;
        }
        try {
            return window.RuntimeBootstrap.resolveAppModule(name);
        } catch (error) {
            return null;
        }
    }


    function GameLocationSidebarPage(extension) {
            let page = {
                location_id: null,
                character_id: null,
                bagItems: [],
                bagCategories: [],
                bagCategoryActive: null,
                equipmentSlots: [],
                equippedItems: [],
                locationCharacters: [],
                itemsGrid: null,
                bagGrid: null,
                equipGrid: null,
                itemsFullData: [],
                bagFullData: [],
                equipFullData: [],
                profileModule: null,
                inventoryModule: null,
                onlinesModule: null,
                isStaff: false,
                pollManager: null,
                profilePollKey: null,
                profilePollIntervalMs: 10000,
                profileSyncInFlight: false,
                conflictsFeed: { active: [], proposals: [] },
                conflictsPollKey: null,
                conflictsPollIntervalMs: 15000,
                conflictsSyncInFlight: false,
                npcs: [],
                npcsExpanded: false,
                combatEnabled: false,
                combatTierLevel: 1,
                combatTaxonomy: {},
                combatState: null,
                combatSelectedConflictId: 0,

                init: function () {
                    this.location_id = parseInt($('[name="location_id"]').val(), 10);
                    this.character_id = parseInt($('[name="character_id"]').val(), 10);
                    this.isStaff = $('[data-requires-staff]').length > 0;
                    this.combatEnabled = $('#location-conflicts-pane-combat').length > 0;
                    if (!this.character_id || !$('#chat-utils').length) {
                        return this;
                    }
                    this.profilePollIntervalMs = this.resolveProfilePollIntervalMs();
                    this.profilePollKey = 'location.sidebar.profile.'
                        + (this.location_id > 0 ? this.location_id : 0)
                        + '.'
                        + this.character_id;
                    this.conflictsPollIntervalMs = this.resolveConflictsPollIntervalMs();
                    this.conflictsPollKey = 'location.sidebar.conflicts.'
                        + (this.location_id > 0 ? this.location_id : 0)
                        + '.'
                        + this.character_id;
                    this.bind();
                    this.loadProfile({ keepOnError: false, skipIfBusy: false });
                    this.startProfilePolling();
                    this.loadConflictsFeed({ keepOnError: false, skipIfBusy: false });
                    this.startConflictsPolling();
                    if (this.combatEnabled) {
                        this.loadCombatTaxonomy();
                    }
                    document.dispatchEvent(new CustomEvent('location:sceneLauncher.init', { detail: { location_id: this.location_id, character_id: this.character_id } }));
                    this.loadBag();
                    this.loadEquipmentSlots();
                    this.loadEquipped();
                    this.loadLocationCharacters();
                    this.loadNpcs();
                    this.loadNarrativeCapabilities();
                    return this;
                },
                bind: function () {
                    var self = this;
                    $('#location-bag-categories').off('click.locationBagTab').on('click.locationBagTab', '[data-bag-category]', function (event) {
                        event.preventDefault();
                        self.bagCategoryActive = String($(this).attr('data-bag-category'));
                        self.renderBagTabs();
                        self.renderBagItems();
                    });
                    $('#location-scene-launcher-modal').off('shown.bs.modal.locationSceneLauncher').on('shown.bs.modal.locationSceneLauncher', function () {
                        self.refreshSceneLauncher();
                    });
                    $('#location-scene-launcher-modal').off('click.locationSceneLauncher').on('click.locationSceneLauncher', '[data-action="scene-launcher-refresh"]', function (event) {
                        event.preventDefault();
                        self.refreshSceneLauncher();
                    });
                    $('#location-scene-launcher-modal').on('click.locationSceneLauncher', '[data-action="scene-launcher-item-use"]', function (event) {
                        event.preventDefault();
                        var btn = $(this);
                        var inventoryItemId = self.toInt(btn.attr('data-inventory-item-id'), 0);
                        var itemName = String(btn.attr('data-item-name') || 'Oggetto');
                        if (inventoryItemId <= 0) {
                            if (window.Toast && typeof window.Toast.show === 'function') {
                                window.Toast.show({
                                    body: 'Questo oggetto non supporta uso diretto dal launcher.',
                                    type: 'warning'
                                });
                            }
                            return;
                        }

                        var body = '<p>Sei sicuro di voler usare <b>' + self.escapeHtml(itemName) + '</b>?</p>';
                        var dialog = Dialog('warning', {
                            title: 'Usa oggetto',
                            body: body
                        }, function () {
                            hideGeneralConfirmDialog();
                            self.useSceneItem(inventoryItemId, itemName);
                        });
                        dialog.show();
                    });
                    $('#location-scene-launcher-modal').on('click.locationSceneLauncher', '[data-action="scene-launcher-item-drop"]', function (event) {
                        event.preventDefault();
                        var btn = $(this);
                        var source = String(btn.attr('data-source') || 'stack');
                        var characterItemId = self.toInt(btn.attr('data-character-item-id'), 0);
                        var instanceId = self.toInt(btn.attr('data-instance-id'), 0);
                        var maxQty = self.toInt(btn.attr('data-quantity'), 1);
                        var itemName = String(btn.attr('data-item-name') || 'Oggetto');

                        var qtyInput = '';
                        if (source === 'stack' && maxQty > 1) {
                            qtyInput = '<div class="mt-2"><label class="form-label form-label-sm">Quantita (max ' + maxQty + ')</label>'
                                + '<input type="number" class="form-control form-control-sm" id="scene-drop-qty-input" min="1" max="' + maxQty + '" value="1">'
                                + '</div>';
                        }
                        var body = '<p>Sei sicuro di voler lasciare <b>' + self.escapeHtml(itemName) + '</b> a terra?</p>' + qtyInput;
                        var dialog = Dialog('warning', {
                            title: 'Lascia oggetto',
                            body: body
                        }, function () {
                            hideGeneralConfirmDialog();
                            var qty = 1;
                            if (source === 'stack' && maxQty > 1) {
                                qty = Math.max(1, Math.min(maxQty, self.toInt($('#scene-drop-qty-input').val(), 1)));
                            }
                            self.dropSceneItem(source, characterItemId, instanceId, qty, itemName);
                        });
                        dialog.show();
                    });
                    $('#location-scene-launcher-modal').on('click.locationSceneLauncher', '[data-action="scene-launcher-item-unequip"]', function (event) {
                        event.preventDefault();
                        var btn = $(this);
                        var instanceId = self.toInt(btn.attr('data-instance-id'), 0);
                        var itemName = String(btn.attr('data-item-name') || 'oggetto');
                        if (!instanceId) { return; }
                        var dialog = Dialog('warning', {
                            title: 'Rimuovi equipaggiamento',
                            body: '<p>Rimuovere <b>' + self.escapeHtml(itemName) + '</b> dallo slot di equipaggiamento?</p><p class="text-muted small">Dopo la rimozione potrai lasciarlo a terra.</p>'
                        }, function () {
                            hideGeneralConfirmDialog();
                            self.callInventory('unequip', { character_item_instance_id: instanceId }, null, function () {
                                self.loadBag();
                                self.loadEquipped();
                            }, function () {
                                if (window.Toast && typeof window.Toast.show === 'function') {
                                    window.Toast.show({ body: 'Errore durante la rimozione dell\'equipaggiamento.', type: 'error' });
                                }
                            });
                        });
                        dialog.show();
                    });
                    $('#location-scene-launcher-modal').on('click.locationSceneLauncher', '[data-action="scene-launcher-money-give"]', function (event) {
                        event.preventDefault();
                        self.giveSceneMoney();
                    });
                    $('#location-scene-money-tab').off('shown.bs.tab.sceneMoney').on('shown.bs.tab.sceneMoney', function () {
                        self.refreshSceneLauncherMoneyTab();
                    });
                    $('#location-scene-money-target-name').off('input.sceneMoneySearch').on('input.sceneMoneySearch', function () {
                        var query = $(this).val().trim();
                        if (!query || query.length < 2) {
                            $('#location-scene-money-suggestions').addClass('d-none').empty();
                            $('#location-scene-money-target-id').val('');
                            return;
                        }
                        self.apiPost('/list/characters/search', { query: query, location_id: self.location_id }, function (response) {
                            self.renderSceneMoneyCharacterSuggestions(response ? response.dataset : []);
                        }, function () {
                            self.renderSceneMoneyCharacterSuggestions([]);
                        });
                    });
                    $(document).off('click.sceneMoneyTargetDismiss').on('click.sceneMoneyTargetDismiss', function (e) {
                        if (!$(e.target).closest('#location-scene-money-target-name, #location-scene-money-suggestions').length) {
                            $('#location-scene-money-suggestions').addClass('d-none').empty();
                        }
                    });
                    $('#location-scene-launcher-modal').on('click.locationSceneLauncher', '[data-action="scene-money-select-character"]', function (event) {
                        event.preventDefault();
                        var btn = $(this);
                        var charId = self.toInt(btn.attr('data-character-id'), 0);
                        var charName = String(btn.attr('data-character-name') || '');
                        $('#location-scene-money-target-id').val(charId > 0 ? charId : '');
                        $('#location-scene-money-target-name').val(charName);
                        $('#location-scene-money-suggestions').addClass('d-none').empty();
                    });
                    $('#location-conflicts-modal').off('shown.bs.modal.locationConflicts').on('shown.bs.modal.locationConflicts', function () {
                        self.loadConflictsFeed({ keepOnError: true, skipIfBusy: false });
                        if (self.combatEnabled) {
                            self.syncCombatConflictSelect();
                            self.renderCombatHub();
                            var selectedConflictId = self.getSelectedCombatConflictId();
                            if (selectedConflictId > 0) {
                                self.loadCombatState(selectedConflictId, false);
                            }
                        }
                    });

                    $('#location-conflict-proposal-target-name').off('input.conflictTargetSearch').on('input.conflictTargetSearch', function () {
                        var query = $(this).val().trim();
                        if (!query || query.length < 2) {
                            $('#location-conflict-proposal-suggestions').addClass('d-none').empty();
                            $('#location-conflict-proposal-target-id').val('');
                            return;
                        }
                        self.apiPost('/list/characters/search', { query: query, location_id: self.location_id }, function (response) {
                            self.renderConflictTargetSuggestions(response ? response.dataset : []);
                        }, function () {
                            self.renderConflictTargetSuggestions([]);
                        });
                    });

                    $(document).off('click.conflictTargetDismiss').on('click.conflictTargetDismiss', function (e) {
                        if (!$(e.target).closest('#location-conflict-proposal-target-name, #location-conflict-proposal-suggestions').length) {
                            $('#location-conflict-proposal-suggestions').addClass('d-none').empty();
                        }
                    });
                    $('#location-npcs-panel').off('click.locationNpcsPanel').on('click.locationNpcsPanel', '[data-action="location-npcs-toggle"]', function () {
                        self.npcsExpanded = !self.npcsExpanded;
                        $('#location-npcs-list').toggleClass('d-none', !self.npcsExpanded);
                        var icon = $('#location-npcs-toggle-icon');
                        icon.toggleClass('bi-chevron-down', !self.npcsExpanded);
                        icon.toggleClass('bi-chevron-up', self.npcsExpanded);
                    });

                    // ── Scene narrative ─────────────────────────────────────
                    document.addEventListener('narrative:scene-changed', function () {
                        self.loadScenes();
                    });

                    $('#location-scenes-panel').off('click.locationScenes').on('click.locationScenes', '[data-action="sidebar-scene-close"]', function () {
                        var eventId = parseInt($(this).attr('data-event-id') || '0', 10);
                        if (!eventId) { return; }
                        var btn = this;
                        $(btn).prop('disabled', true);
                        var mod = self.resolveNarrativeEventsModule();
                        if (!mod || typeof mod.close !== 'function') { return; }
                        mod.close({ event_id: eventId }).then(function () {
                            if (window.Toast) { window.Toast.show({ body: 'Scena chiusa.', type: 'success' }); }
                            document.dispatchEvent(new CustomEvent('narrative:scene-changed'));
                        }).catch(function (err) {
                            $(btn).prop('disabled', false);
                            if (window.Toast) { window.Toast.show({ body: (err && err.message) || 'Errore.', type: 'error' }); }
                        });
                    });

                    $('#location-scene-launcher-modal').off('click.locationSceneNarrative').on('click.locationSceneNarrative', '[data-action]', function () {
                        var action = $(this).attr('data-action');
                        if (action === 'scene-create-local') {
                            self.createLocalScene(this);
                        }
                        if (action === 'scene-narrative-close') {
                            if (!self.activeScene) { return; }
                            var btn = this;
                            $(btn).prop('disabled', true);
                            var mod = self.resolveNarrativeEventsModule();
                            if (!mod || typeof mod.close !== 'function') { return; }
                            mod.close({ event_id: self.activeScene.id }).then(function () {
                                if (window.Toast) { window.Toast.show({ body: 'Scena chiusa.', type: 'success' }); }
                                document.dispatchEvent(new CustomEvent('narrative:scene-changed'));
                            }).catch(function (err) {
                                $(btn).prop('disabled', false);
                                if (window.Toast) { window.Toast.show({ body: (err && err.message) || 'Errore.', type: 'error' }); }
                            });
                        }
                        if (action === 'scene-spawn-npc') {
                            var form = document.getElementById('location-scene-narrative-spawn-form');
                            var alert = document.getElementById('scene-npc-spawn-alert');
                            if (form) { form.classList.remove('d-none'); }
                            if (alert) { alert.classList.add('d-none'); alert.textContent = ''; }
                            var nameEl = document.getElementById('scene-npc-name');
                            var descEl = document.getElementById('scene-npc-description');
                            if (nameEl) { nameEl.value = ''; nameEl.focus(); }
                            if (descEl) { descEl.value = ''; }
                        }
                        if (action === 'scene-spawn-npc-cancel') {
                            var form = document.getElementById('location-scene-narrative-spawn-form');
                            if (form) { form.classList.add('d-none'); }
                        }
                        if (action === 'scene-spawn-npc-confirm') {
                            self.spawnEphemeralNpc(this);
                        }
                        if (action === 'scene-delete-npc') {
                            var npcId = parseInt($(this).attr('data-npc-id') || '0', 10);
                            if (npcId > 0) { self.deleteEphemeralNpc(npcId, this); }
                        }
                    });
                    $('#location-conflicts-panel').off('click.locationConflictsPanel').on('click.locationConflictsPanel', '[data-action="location-conflict-open-detail"]', function () {
                        var trigger = $(this);
                        var conflictId = self.toInt(trigger.attr('data-conflict-id'), 0);
                        setTimeout(function () {
                            self.loadConflictDetail(conflictId);
                        }, 180);
                    });
                    $('#location-conflicts-modal').off('click.locationConflicts').on('click.locationConflicts', '[data-action]', function (event) {
                        var trigger = $(this);
                        var action = String(trigger.attr('data-action') || '').trim();
                        if (action === '') {
                            return;
                        }
                        event.preventDefault();

                        if (action === 'location-conflicts-refresh') {
                            self.loadConflictsFeed({ keepOnError: true, skipIfBusy: false });
                            return;
                        }
                        if (action === 'location-conflict-proposal-submit') {
                            self.submitConflictProposal();
                            return;
                        }
                        if (action === 'location-conflict-respond') {
                            var conflictId = self.toInt(trigger.attr('data-conflict-id'), 0);
                            var response = String(trigger.attr('data-response') || '').trim();
                            self.respondConflictProposal(conflictId, response);
                            return;
                        }
                        if (action === 'location-conflict-open-detail') {
                            var detailConflictId = self.toInt(trigger.attr('data-conflict-id'), 0);
                            self.loadConflictDetail(detailConflictId);
                            self.setCombatConflictId(detailConflictId);
                            return;
                        }
                        if (action === 'location-combat-load') {
                            self.loadCombatState(self.getSelectedCombatConflictId(), true);
                            return;
                        }
                        if (action === 'location-combat-start') {
                            self.startCombatContext(self.getSelectedCombatConflictId());
                            return;
                        }
                        if (action === 'location-combat-sync') {
                            self.syncCombatParticipants(self.getSelectedCombatConflictId());
                            return;
                        }
                        if (action === 'location-combat-declare') {
                            self.declareCombatAction(self.getSelectedCombatConflictId());
                            return;
                        }
                        if (action === 'location-combat-guard-add') {
                            self.createCombatGuardRelation(self.getSelectedCombatConflictId());
                            return;
                        }
                        if (action === 'location-combat-guard-remove') {
                            self.removeCombatGuardRelation(self.getSelectedCombatConflictId());
                            return;
                        }
                        if (action === 'location-combat-env-save') {
                            self.saveCombatEnvironment(self.getSelectedCombatConflictId());
                            return;
                        }
                        if (action === 'location-combat-resolve') {
                            var actionIntentId = self.toInt(trigger.attr('data-intent-id'), 0);
                            self.resolveCombatAction(actionIntentId);
                            return;
                        }
                    });
                    $('#location-combat-conflict-id').off('change.locationCombat').on('change.locationCombat', function () {
                        var selected = self.toInt($(this).val(), 0);
                        self.setCombatConflictId(selected);
                        self.loadCombatState(selected, false);
                    });
                    $('#location-combat-action-type').off('change.locationCombat').on('change.locationCombat', function () {
                        self.renderCombatTargetModeControls();
                    });
                    $('#location-combat-target-mode').off('change.locationCombat').on('change.locationCombat', function () {
                        self.renderCombatTargetModeControls();
                    });
                    $('#location-conflicts-modal').off('change.locationCombatTargetPreview').on('change.locationCombatTargetPreview', '#location-combat-target-character-id, #location-combat-target-team-key, #location-combat-target-multi-list [data-role="combat-target-multi"]', function () {
                        self.renderCombatTargetPreview();
                    });
                },
                unbind: function () {
                    $('#location-bag-categories').off('click.locationBagTab');
                    $('#location-scene-launcher-modal').off('shown.bs.modal.locationSceneLauncher');
                    $('#location-scene-launcher-modal').off('click.locationSceneLauncher');
                    $('#location-npcs-panel').off('click.locationNpcsPanel');
                    $('#location-conflicts-panel').off('click.locationConflictsPanel');
                    $('#location-conflicts-modal').off('shown.bs.modal.locationConflicts');
                    $('#location-conflicts-modal').off('click.locationConflicts');
                    $('#location-combat-conflict-id').off('change.locationCombat');
                    $('#location-combat-action-type').off('change.locationCombat');
                    $('#location-combat-target-mode').off('change.locationCombat');
                    $('#location-conflicts-modal').off('change.locationCombatTargetPreview');
                },
                escapeHtml: function (value) {
                    return $('<div/>').text(value || '').html();
                },
                slotLabel: function (slot) {
                    var map = {
                        weapon_1: 'Arma 1',
                        weapon_2: 'Arma 2',
                        helm: 'Elmo',
                        armor: 'Armatura',
                        gloves: 'Guanti',
                        boots: 'Stivali',
                        ring_1: 'Anello 1',
                        ring_2: 'Anello 2',
                        amulet: 'Ciondolo'
                    };
                    return map[slot] || slot || '-';
                },
                itemTypeLabel: function (type) {
                    var key = String(type || '').toLowerCase().trim();
                    var map = {
                        weapon: 'Arma',
                        armor: 'Armatura',
                        shield: 'Scudo',
                        accessory: 'Accessorio',
                        trinket: 'Monile',
                        equipment: 'Equipaggiamento',
                        consumable: 'Consumabile',
                        material: 'Materiale',
                        key_item: 'Oggetto chiave',
                        quest: 'Oggetto missione',
                        misc: 'Varie'
                    };
                    if (map[key]) {
                        return map[key];
                    }
                    if (key === '') {
                        return '-';
                    }
                    return type;
                },
                toInt: function (value, fallback) {
                    var num = parseInt(value, 10);
                    if (isNaN(num)) {
                        return fallback;
                    }
                    return num;
                },
                toFloat: function (value, fallback) {
                    var num = parseFloat(value);
                    if (isNaN(num)) {
                        return fallback;
                    }
                    return num;
                },
                toMetricNumber: function (value, fallback) {
                    var text = String(value == null ? '' : value).trim().replace(',', '.');
                    if (text === '') {
                        return fallback;
                    }
                    var num = parseFloat(text);
                    if (!isFinite(num)) {
                        return fallback;
                    }
                    return num;
                },
                formatNumber: function (value, maxFractionDigits) {
                    var num = this.toFloat(value, 0);
                    if (typeof num !== 'number' || isNaN(num)) {
                        return '0';
                    }
                    var digits = (typeof maxFractionDigits === 'number') ? maxFractionDigits : 0;
                    try {
                        return num.toLocaleString('it-IT', { maximumFractionDigits: digits });
                    } catch (error) {
                        return String(num);
                    }
                },
                renderQuickAttributes: function (profile) {
                    var wrap = $('#location-profile-attributes-summary');
                    var list = $('#location-profile-attributes-list');
                    if (!wrap.length || !list.length) {
                        return;
                    }

                    list.empty();

                    var payload = profile && profile.character_attributes ? profile.character_attributes : null;
                    var enabled = !!(payload && parseInt(payload.enabled, 10) === 1);
                    var locationGroups = payload && payload.location ? payload.location : null;
                    if (!enabled || !locationGroups) {
                        wrap.addClass('d-none');
                        return;
                    }

                    var total = 0;
                    var groupsOrder = ['primary', 'secondary', 'narrative'];
                    for (var i = 0; i < groupsOrder.length; i++) {
                        var key = groupsOrder[i];
                        var entries = Array.isArray(locationGroups[key]) ? locationGroups[key] : [];
                        for (var e = 0; e < entries.length; e++) {
                            var entry = entries[e] || {};
                            var name = String(entry.name || entry.slug || 'Attributo').trim();
                            if (name === '') {
                                name = 'Attributo';
                            }
                            var value = this.formatNumber(entry.effective_value, 2);
                            list.append(
                                '<div class="d-flex justify-content-between align-items-center">'
                                + '<span>' + this.escapeHtml(name) + '</span>'
                                + '<b>' + this.escapeHtml(value) + '</b>'
                                + '</div>'
                            );
                            total += 1;
                        }
                    }

                    if (total <= 0) {
                        wrap.addClass('d-none');
                        return;
                    }

                    wrap.removeClass('d-none');
                },
                setProgress: function (barSelector, labelSelector, current, max, fallbackLabel) {
                    var c = this.toMetricNumber(current, -1);
                    var m = this.toMetricNumber(max, -1);
                    var percent = 0;
                    var label = fallbackLabel || 'N/D';
                    if (c >= 0 && m > 0) {
                        percent = Math.max(0, Math.min(100, Math.round((c / m) * 100)));
                        label = this.formatNumber(c, 2) + ' / ' + this.formatNumber(m, 2);
                    }
                    $(barSelector).css('width', percent + '%').attr('aria-valuenow', percent);
                    $(labelSelector).text(label);
                },
                resolveHealthState: function (percent) {
                    if (percent >= 90) {
                        return 'Ottima';
                    }
                    if (percent >= 70) {
                        return 'Stabile';
                    }
                    if (percent >= 40) {
                        return 'Ferito';
                    }
                    if (percent > 0) {
                        return 'Critica';
                    }
                    return 'K.O.';
                },
                renderQuickVitals: function (profile) {
                    var hpCurrent = this.toMetricNumber(
                        profile ? (
                            profile.health != null ? profile.health
                                : (profile.health_current != null ? profile.health_current : profile.hp_current)
                        ) : null,
                        0
                    );
                    if (hpCurrent < 0) {
                        hpCurrent = 0;
                    }

                    var hpMax = this.toMetricNumber(
                        profile ? (
                            profile.health_max != null ? profile.health_max
                                : (profile.hp_max != null ? profile.hp_max : profile.max_health)
                        ) : null,
                        100
                    );
                    if (!(hpMax > 0)) {
                        hpMax = 100;
                    }

                    if (hpCurrent > hpMax) {
                        hpCurrent = hpMax;
                    }

                    this.setProgress('#location-profile-hp-bar', '#location-profile-hp-label', hpCurrent, hpMax, 'N/D');
                    $('#location-profile-hp-current').text(this.formatNumber(hpCurrent, 2));
                    $('#location-profile-hp-max').text(this.formatNumber(hpMax, 2));

                    var hpPercent = 0;
                    if (hpCurrent >= 0 && hpMax > 0) {
                        hpPercent = Math.max(0, Math.min(100, Math.round((hpCurrent / hpMax) * 100)));
                        $('#location-profile-hp-percent').text(hpPercent + '%');
                        $('#location-profile-hp-state').text(this.resolveHealthState(hpPercent));
                    } else {
                        $('#location-profile-hp-percent').text('N/D');
                        $('#location-profile-hp-state').text('Sconosciuto');
                    }

                    var experienceTotal = this.toFloat(profile ? profile.experience : null, 0);
                    var expProgress = Math.round(((experienceTotal % 100) + 100) % 100);
                    this.setProgress('#location-profile-exp-bar', '#location-profile-exp-label', expProgress, 100, 'N/D');
                    $('#location-profile-exp-current').text(String(expProgress));
                    $('#location-profile-exp-max').text('100');
                    $('#location-profile-exp-percent').text(expProgress + '%');
                    $('#location-profile-exp-total').text(this.formatNumber(experienceTotal, 2));
                    this.renderQuickAttributes(profile || null);
                },
                resolveProfilePollIntervalMs: function () {
                    var seconds = parseInt(window.APP_CONFIG && window.APP_CONFIG.location_sidebar_profile_poll_seconds, 10);
                    if (isNaN(seconds) || seconds < 3) {
                        seconds = 10;
                    }
                    if (seconds > 120) {
                        seconds = 120;
                    }
                    return seconds * 1000;
                },
                getPollManager: function () {
                    if (this.pollManager) {
                        return this.pollManager;
                    }
                    if (typeof window.PollManager !== 'function') {
                        return null;
                    }
                    try {
                        this.pollManager = window.PollManager();
                    } catch (error) {
                        this.pollManager = null;
                    }
                    return this.pollManager;
                },
                startProfilePolling: function () {
                    this.stopProfilePolling();
                    if (!this.profilePollKey) {
                        return;
                    }
                    var manager = this.getPollManager();
                    if (!manager || typeof manager.start !== 'function') {
                        return;
                    }
                    var self = this;
                    manager.start(
                        this.profilePollKey,
                        function () {
                            self.loadProfile({ keepOnError: true, skipIfBusy: true });
                        },
                        this.profilePollIntervalMs,
                        { immediate: false, pauseOnHidden: true }
                    );
                },
                stopProfilePolling: function () {
                    var manager = this.getPollManager();
                    if (!manager || typeof manager.stop !== 'function' || !this.profilePollKey) {
                        return;
                    }
                    manager.stop(this.profilePollKey);
                },
                resolveConflictsPollIntervalMs: function () {
                    var seconds = parseInt(window.APP_CONFIG && window.APP_CONFIG.location_conflicts_poll_seconds, 10);
                    if (isNaN(seconds) || seconds < 5) {
                        seconds = 15;
                    }
                    if (seconds > 120) {
                        seconds = 120;
                    }
                    return seconds * 1000;
                },
                startConflictsPolling: function () {
                    this.stopConflictsPolling();
                    if (!this.conflictsPollKey) {
                        return;
                    }
                    var manager = this.getPollManager();
                    if (!manager || typeof manager.start !== 'function') {
                        return;
                    }
                    var self = this;
                    manager.start(
                        this.conflictsPollKey,
                        function () {
                            self.loadConflictsFeed({ keepOnError: true, skipIfBusy: true });
                        },
                        this.conflictsPollIntervalMs,
                        { immediate: false, pauseOnHidden: true }
                    );
                },
                stopConflictsPolling: function () {
                    var manager = this.getPollManager();
                    if (!manager || typeof manager.stop !== 'function' || !this.conflictsPollKey) {
                        return;
                    }
                    manager.stop(this.conflictsPollKey);
                },
                apiPost: function (url, payload, onSuccess, onError) {
                    var meta = document.querySelector('meta[name="csrf-token"]');
                    var csrfToken = meta ? (meta.getAttribute('content') || '') : '';
                    var headers = { 'X-Requested-With': 'XMLHttpRequest' };
                    if (csrfToken) {
                        headers['X-CSRF-Token'] = csrfToken;
                    }
                    $.ajax({
                        url: String(url || ''),
                        type: 'POST',
                        dataType: 'json',
                        headers: headers,
                        data: {
                            data: JSON.stringify(payload || {})
                        }
                    }).done(function (response) {
                        if (typeof onSuccess === 'function') {
                            onSuccess(response || {});
                        }
                    }).fail(function (xhr) {
                        var error = new Error('request_failed');
                        error.xhr = xhr || null;
                        error.response = (xhr && xhr.responseJSON) ? xhr.responseJSON : null;
                        error.error_code = error.response && error.response.error_code
                            ? String(error.response.error_code)
                            : '';
                        if (typeof onError === 'function') {
                            onError(error);
                        }
                    });
                },
                showConflictError: function (error, fallbackMessage) {
                    var code = String(error && error.error_code ? error.error_code : '');
                    var map = {
                        conflict_proposal_not_found: 'Proposta non trovata.',
                        conflict_proposal_expired: 'La proposta e scaduta.',
                        conflict_proposal_forbidden: 'Non puoi rispondere a questa proposta.',
                        conflict_overlap_detected: 'E presente un conflitto in sovrapposizione nella location.',
                        conflict_not_found: 'Conflitto non trovato.',
                        conflict_write_forbidden: 'Non puoi modificare questo conflitto.',
                        combat_feature_not_enabled: 'Funzionalita combattimento non attiva nel tier corrente.',
                        combat_context_not_found: 'Nessun contesto combattimento avviato per questo conflitto.',
                        combat_staff_only: 'Questa azione e riservata allo staff.',
                        combat_param_invalid: 'Parametri non validi per il combattimento.',
                        combat_guard_self_invalid: 'Un personaggio non puo proteggere se stesso.',
                        conflict_inactivity_escalated: 'La proposta e scaduta ed e stata inoltrata allo staff.'
                    };
                    var body = map[code] || (fallbackMessage || 'Operazione conflitto non riuscita.');
                    if (window.Toast && typeof window.Toast.show === 'function') {
                        window.Toast.show({
                            body: body,
                            type: 'warning'
                        });
                    }
                },
                loadConflictsFeed: function (options) {
                    options = options || {};
                    var keepOnError = options.keepOnError === true;
                    var skipIfBusy = options.skipIfBusy === true;
                    if (skipIfBusy && this.conflictsSyncInFlight) {
                        return;
                    }
                    if (!this.location_id || this.location_id <= 0) {
                        return;
                    }

                    var self = this;
                    this.conflictsSyncInFlight = true;
                    this.apiPost('/conflicts/location/feed', {
                        location_id: this.location_id
                    }, function (response) {
                        self.conflictsSyncInFlight = false;
                        var dataset = response && response.dataset ? response.dataset : {};
                        self.conflictsFeed = {
                            active: Array.isArray(dataset.active) ? dataset.active : [],
                            proposals: Array.isArray(dataset.proposals) ? dataset.proposals : [],
                            overlap_warnings: Array.isArray(dataset.overlap_warnings) ? dataset.overlap_warnings : [],
                            settings: dataset.settings || {}
                        };
                        self.renderConflictsPanel();
                        self.renderConflictsModal();
                    }, function () {
                        self.conflictsSyncInFlight = false;
                        if (!keepOnError) {
                            self.conflictsFeed = { active: [], proposals: [], overlap_warnings: [], settings: {} };
                            self.renderConflictsPanel();
                            self.renderConflictsModal();
                        }
                    });
                },
                conflictStatusBadge: function (status) {
                    var value = String(status || '').toLowerCase();
                    var map = {
                        proposal: { label: 'Proposta', cls: 'text-bg-warning' },
                        open: { label: 'Aperto', cls: 'text-bg-primary' },
                        active: { label: 'Attivo', cls: 'text-bg-success' },
                        awaiting_resolution: { label: 'In attesa', cls: 'text-bg-info' },
                        resolved: { label: 'Risolto', cls: 'text-bg-secondary' },
                        closed: { label: 'Chiuso', cls: 'text-bg-dark' }
                    };
                    var item = map[value] || { label: value || '-', cls: 'text-bg-secondary' };
                    return '<span class="badge ' + item.cls + '">' + this.escapeHtml(item.label) + '</span>';
                },
                conflictLabel: function (conflictId) {
                    var id = this.toInt(conflictId, 0);
                    return 'Conflitto #' + (id > 0 ? String(id) : '-');
                },
                renderConflictsAlerts: function () {
                    var wrap = $('#location-conflicts-modal-alerts');
                    if (!wrap.length) {
                        return;
                    }

                    var feed = this.conflictsFeed || {};
                    var overlap = Array.isArray(feed.overlap_warnings) ? feed.overlap_warnings : [];
                    var active = Array.isArray(feed.active) ? feed.active : [];
                    var proposals = Array.isArray(feed.proposals) ? feed.proposals : [];
                    var source = proposals.concat(active);
                    var inactiveWarnings = 0;
                    for (var i = 0; i < source.length; i++) {
                        var row = source[i] || {};
                        if (this.toInt(row.inactivity_warning, 0) === 1) {
                            inactiveWarnings += 1;
                        }
                    }

                    var blocks = [];
                    if (overlap.length > 0) {
                        blocks.push('<div class="alert alert-warning py-2 mb-2"><b>Attenzione:</b> rilevate sovrapposizioni di partecipazione tra conflitti in location.</div>');
                    }
                    if (this.isStaff && inactiveWarnings > 0) {
                        blocks.push('<div class="alert alert-warning py-2 mb-0"><b>Revisione staff:</b> ' + this.escapeHtml(String(inactiveWarnings)) + ' conflitti hanno superato la soglia di inattivita.</div>');
                    }

                    if (!blocks.length) {
                        wrap.addClass('d-none').empty();
                        return;
                    }

                    wrap.html(blocks.join('')).removeClass('d-none');
                },
                renderConflictsPanel: function () {
                    var feed = this.conflictsFeed || {};
                    var proposals = Array.isArray(feed.proposals) ? feed.proposals : [];
                    var active = Array.isArray(feed.active) ? feed.active : [];
                    var total = proposals.length + active.length;
                    var warningsCount = 0;
                    var overlap = Array.isArray(feed.overlap_warnings) ? feed.overlap_warnings : [];
                    if (overlap.length > 0) {
                        warningsCount += overlap.length;
                    }
                    var sourceWarnings = proposals.concat(active);
                    for (var sw = 0; sw < sourceWarnings.length; sw++) {
                        if (this.toInt(sourceWarnings[sw] && sourceWarnings[sw].inactivity_warning, 0) === 1) {
                            warningsCount += 1;
                        }
                    }

                    var badge = $('#location-conflicts-proposals-badge');
                    if (badge.length) {
                        if (proposals.length > 0) {
                            badge.text(String(proposals.length)).removeClass('d-none');
                        } else {
                            badge.addClass('d-none');
                        }
                    }

                    var meta = $('#location-conflicts-panel-meta');
                    if (meta.length) {
                        var warningsLabel = warningsCount > 0 ? (' | attenzioni: ' + warningsCount) : '';
                        meta.text(active.length + ' attivi, ' + proposals.length + ' proposte.' + warningsLabel);
                    }

                    var empty = $('#location-conflicts-panel-empty');
                    var list = $('#location-conflicts-panel-list');
                    if (!list.length) {
                        return;
                    }

                    list.empty();
                    if (total <= 0) {
                        list.addClass('d-none');
                        if (empty.length) {
                            empty.removeClass('d-none');
                        }
                        return;
                    }

                    if (empty.length) {
                        empty.addClass('d-none');
                    }
                    list.removeClass('d-none');

                    var source = proposals.concat(active).slice(0, 4);
                    for (var i = 0; i < source.length; i++) {
                        var row = source[i] || {};
                        var conflictId = this.toInt(row.id, 0);
                        var label = this.conflictLabel(conflictId);
                        var lastBody = row.last_action && row.last_action.action_body ? String(row.last_action.action_body) : '';
                        list.append(
                            '<div class="list-group-item py-2">'
                            + '<div class="d-flex justify-content-between align-items-start gap-2">'
                            + '<div><b>' + this.escapeHtml(label) + '</b> ' + this.conflictStatusBadge(row.status) + '</div>'
                            + '<button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#location-conflicts-modal" data-action="location-conflict-open-detail" data-conflict-id="' + conflictId + '">Dettaglio</button>'
                            + '</div>'
                            + (lastBody !== '' ? ('<div class="small text-muted mt-1">' + this.escapeHtml(lastBody) + '</div>') : '')
                            + '</div>'
                        );
                    }
                },
                renderConflictsModal: function () {
                    var feed = this.conflictsFeed || {};
                    var proposals = Array.isArray(feed.proposals) ? feed.proposals : [];
                    var active = Array.isArray(feed.active) ? feed.active : [];

                    $('#location-conflicts-modal-proposals-count').text(String(proposals.length));
                    $('#location-conflicts-modal-active-count').text(String(active.length));
                    this.renderConflictsAlerts();

                    var proposalsWrap = $('#location-conflicts-modal-proposals');
                    var activeWrap = $('#location-conflicts-modal-active');
                    var proposalsEmpty = $('#location-conflicts-modal-proposals-empty');
                    var activeEmpty = $('#location-conflicts-modal-active-empty');
                    if (proposalsWrap.length) {
                        proposalsWrap.empty();
                    }
                    if (activeWrap.length) {
                        activeWrap.empty();
                    }

                    if (proposals.length === 0) {
                        proposalsEmpty.removeClass('d-none');
                    } else {
                        proposalsEmpty.addClass('d-none');
                        for (var p = 0; p < proposals.length; p++) {
                            var proposal = proposals[p] || {};
                            var proposalId = this.toInt(proposal.id, 0);
                            var actions = '';
                            if (this.toInt(proposal.viewer_can_respond_proposal, 0) === 1) {
                                actions = ''
                                    + '<button type="button" class="btn btn-sm btn-outline-success me-1" data-action="location-conflict-respond" data-conflict-id="' + proposalId + '" data-response="accept">Accetta</button>'
                                    + '<button type="button" class="btn btn-sm btn-outline-danger me-1" data-action="location-conflict-respond" data-conflict-id="' + proposalId + '" data-response="reject">Rifiuta</button>';
                            }
                            actions += '<button type="button" class="btn btn-sm btn-outline-primary" data-action="location-conflict-open-detail" data-conflict-id="' + proposalId + '">Dettaglio</button>';

                            proposalsWrap.append(
                                '<div class="list-group-item py-2">'
                                + '<div class="d-flex justify-content-between align-items-start gap-2">'
                                + '<div><b>Conflitto #' + this.escapeHtml(String(proposalId || '-')) + '</b> ' + this.conflictStatusBadge(proposal.status) + '</div>'
                                + '<div class="small text-muted">' + this.escapeHtml(String(proposal.created_at || '')) + '</div>'
                                + '</div>'
                                + '<div class="small mt-1">' + this.escapeHtml(String((proposal.last_action && proposal.last_action.action_body) || 'In attesa risposta.')) + '</div>'
                                + '<div class="mt-2">' + actions + '</div>'
                                + '</div>'
                            );
                        }
                    }

                    if (active.length === 0) {
                        activeEmpty.removeClass('d-none');
                    } else {
                        activeEmpty.addClass('d-none');
                        for (var a = 0; a < active.length; a++) {
                            var conflict = active[a] || {};
                            var conflictId = this.toInt(conflict.id, 0);
                            activeWrap.append(
                                '<div class="list-group-item py-2">'
                                + '<div class="d-flex justify-content-between align-items-start gap-2">'
                                + '<div><b>Conflitto #' + this.escapeHtml(String(conflictId || '-')) + '</b> ' + this.conflictStatusBadge(conflict.status) + '</div>'
                                + '<button type="button" class="btn btn-sm btn-outline-primary" data-action="location-conflict-open-detail" data-conflict-id="' + conflictId + '">Dettaglio</button>'
                                + '</div>'
                                + '<div class="small text-muted mt-1">Partecipanti: ' + this.escapeHtml(String(this.toInt(conflict.participants_count, 0))) + ' | Tiri: ' + this.escapeHtml(String(this.toInt(conflict.rolls_count, 0))) + '</div>'
                                + '</div>'
                            );
                        }
                    }

                    if (this.combatEnabled) {
                        this.syncCombatConflictSelect();
                        this.renderCombatHub();
                    }
                },
                renderConflictTargetSuggestions: function (dataset) {
                    var self = this;
                    var box = $('#location-conflict-proposal-suggestions');
                    box.empty();
                    if (!dataset || !dataset.length) {
                        box.addClass('d-none');
                        return;
                    }
                    for (var i = 0; i < dataset.length; i++) {
                        var row = dataset[i];
                        var label = (String(row.name || '') + ' ' + String(row.surname || '')).trim();
                        var btn = $('<button type="button" class="list-group-item list-group-item-action small py-1"></button>').text(label);
                        btn.on('click', (function (r, l) {
                            return function () {
                                $('#location-conflict-proposal-target-id').val(r.id);
                                $('#location-conflict-proposal-target-name').val(l);
                                $('#location-conflict-proposal-suggestions').addClass('d-none').empty();
                            };
                        })(row, label));
                        box.append(btn);
                    }
                    box.removeClass('d-none');
                },

                submitConflictProposal: function () {
                    var targetId = this.toInt($('#location-conflict-proposal-target-id').val(), 0);
                    var summary = String($('#location-conflict-proposal-summary').val() || '').trim();
                    if (summary === '') {
                        this.showConflictError({ error_code: '' }, 'Inserisci un contesto per la proposta.');
                        return;
                    }

                    var command = '/conflitto ';
                    if (targetId > 0) {
                        command += '@' + targetId + ' ' + summary;
                    } else {
                        command += summary;
                    }

                    var self = this;
                    this.apiPost('/location/messages/send', {
                        location_id: this.location_id,
                        body: command
                    }, function (response) {
                        if (window.Toast && typeof window.Toast.show === 'function') {
                            window.Toast.show({
                                body: 'Proposta conflitto inviata.',
                                type: 'success'
                            });
                        }
                        $('#location-conflict-proposal-summary').val('');
                        $('#location-conflict-proposal-target-id').val('');
                        $('#location-conflict-proposal-target-name').val('');

                        var row = response && response.dataset ? response.dataset : null;
                        if (row && window.LocationChat && typeof window.LocationChat.appendMessages === 'function') {
                            window.LocationChat.appendMessages([row]);
                        } else if (window.LocationChat && typeof window.LocationChat.load === 'function') {
                            window.LocationChat.load(true);
                        }

                        self.loadConflictsFeed({ keepOnError: true, skipIfBusy: false });
                    }, function (error) {
                        self.showConflictError(error, 'Invio proposta non riuscito.');
                    });
                },
                respondConflictProposal: function (conflictId, response) {
                    var id = this.toInt(conflictId, 0);
                    var decision = String(response || '').trim().toLowerCase();
                    if (id <= 0 || (decision !== 'accept' && decision !== 'reject' && decision !== 'escalate')) {
                        return;
                    }

                    var actionLabelMap = {
                        accept: 'accettare',
                        reject: 'rifiutare',
                        escalate: 'escalare allo staff'
                    };
                    var actionLabel = actionLabelMap[decision] || 'confermare';
                    var body = '<p class="mb-1">Conflitto <b>#' + this.escapeHtml(String(id)) + '</b></p>'
                        + '<p class="mb-0">Confermi di voler <b>' + this.escapeHtml(actionLabel) + '</b> la proposta?</p>';

                    var self = this;
                    var dialog = Dialog('warning', {
                        title: 'Conferma risposta proposta',
                        body: body
                    }, function () {
                        hideGeneralConfirmDialog();
                        self.apiPost('/conflicts/proposal/respond', {
                            conflict_id: id,
                            response: decision
                        }, function () {
                            if (window.Toast && typeof window.Toast.show === 'function') {
                                window.Toast.show({
                                    body: 'Risposta proposta registrata.',
                                    type: 'success'
                                });
                            }
                            self.loadConflictsFeed({ keepOnError: true, skipIfBusy: false });
                            self.loadConflictDetail(id);
                        }, function (error) {
                            self.showConflictError(error, 'Risposta proposta non riuscita.');
                        });
                    });
                    dialog.show();
                },
                loadConflictDetail: function (conflictId) {
                    var id = this.toInt(conflictId, 0);
                    if (id <= 0) {
                        return;
                    }

                    var self = this;
                    this.apiPost('/conflicts/get', {
                        conflict_id: id
                    }, function (response) {
                        var detail = response && response.dataset ? response.dataset : null;
                        self.renderConflictDetail(detail);
                        if (self.combatEnabled) {
                            self.setCombatConflictId(id);
                            self.loadCombatState(id, false);
                        }
                    }, function (error) {
                        self.showConflictError(error, 'Dettaglio conflitto non disponibile.');
                    });
                },
                conflictFmtDate: function (value) {
                    var text = String(value || '').trim();
                    if (text === '' || text === '0000-00-00 00:00:00') { return '-'; }
                    var date = new Date(text.replace(' ', 'T'));
                    if (isNaN(date.getTime())) { return this.escapeHtml(text); }
                    try {
                        return new Intl.DateTimeFormat('it-IT', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(date);
                    } catch (e) { return this.escapeHtml(text); }
                },
                conflictModeLabel: function (mode) {
                    var m = String(mode || '').toLowerCase();
                    if (m === 'random') { return 'Random'; }
                    if (m === 'narrative') { return 'Narrativo'; }
                    return m !== '' ? this.escapeHtml(mode) : '-';
                },
                conflictAuthLabel: function (auth) {
                    var a = String(auth || '').toLowerCase();
                    if (a === 'players') { return 'Giocatori'; }
                    if (a === 'master') { return 'Master'; }
                    if (a === 'mixed') { return 'Misto'; }
                    if (a === 'deferred_review') { return 'Revisione differita'; }
                    return a !== '' ? this.escapeHtml(auth) : '-';
                },
                conflictRoleLabel: function (role) {
                    var r = String(role || '').toLowerCase();
                    if (r === 'actor') { return 'Attore'; }
                    if (r === 'target') { return 'Bersaglio'; }
                    if (r === 'support') { return 'Supporto'; }
                    if (r === 'witness') { return 'Testimone'; }
                    if (r === 'other') { return 'Altro'; }
                    return r !== '' ? this.escapeHtml(role) : 'Attore';
                },
                renderConflictDetail: function (detail) {
                    var empty = $('#location-conflicts-modal-detail-empty');
                    var box = $('#location-conflicts-modal-detail');
                    if (!box.length) {
                        return;
                    }

                    if (!detail || !detail.conflict) {
                        box.addClass('d-none').empty();
                        empty.removeClass('d-none');
                        return;
                    }

                    var conflict = detail.conflict || {};
                    var participants = Array.isArray(detail.participants) ? detail.participants : [];
                    var actions = Array.isArray(detail.actions) ? detail.actions : [];
                    var participantsHtml = '';
                    if (!participants.length) {
                        participantsHtml = '<div class="small text-muted">Nessun partecipante.</div>';
                    } else {
                        var items = [];
                        for (var i = 0; i < participants.length; i++) {
                            var p = participants[i] || {};
                            var label = ((p.character_name || '') + ' ' + (p.character_surname || '')).trim();
                            if (label === '') {
                                label = 'PG #' + this.toInt(p.character_id, 0);
                            }
                            items.push('<li class="small">' + this.escapeHtml(label) + ' <span class="text-muted">(' + this.conflictRoleLabel(p.participant_role) + ')</span></li>');
                        }
                        participantsHtml = '<ul class="mb-2">' + items.join('') + '</ul>';
                    }

                    var actionsHtml = '';
                    if (!actions.length) {
                        actionsHtml = '<div class="small text-muted">Nessuna azione registrata.</div>';
                    } else {
                        var lines = [];
                        var max = Math.min(actions.length, 5);
                        for (var a = 0; a < max; a++) {
                            var row = actions[a] || {};
                            lines.push(
                                '<div class="small mb-1"><b>' + this.escapeHtml(String(row.actor_name || 'Sistema')) + '</b> '
                                + '<span class="text-muted">' + this.conflictFmtDate(row.created_at) + '</span>'
                                + '<div>' + this.escapeHtml(String(row.action_body || '')) + '</div></div>'
                            );
                        }
                        actionsHtml = lines.join('');
                    }

                    box.html(
                        '<div class="row g-2">'
                        + '<div class="col-12 col-md-4"><div class="small text-muted">Conflitto</div><div><b>#' + this.escapeHtml(String(this.toInt(conflict.id, 0))) + '</b> ' + this.conflictStatusBadge(conflict.status) + '</div></div>'
                        + '<div class="col-12 col-md-4"><div class="small text-muted">Modalita</div><div>' + this.conflictModeLabel(conflict.resolution_mode) + '</div></div>'
                        + '<div class="col-12 col-md-4"><div class="small text-muted">Autorita</div><div>' + this.conflictAuthLabel(conflict.resolution_authority) + '</div></div>'
                        + '</div>'
                        + '<hr class="my-2">'
                        + '<div><h6 class="mb-1">Partecipanti</h6>' + participantsHtml + '</div>'
                        + '<div><h6 class="mb-1">Ultime azioni</h6>' + actionsHtml + '</div>'
                    );
                    box.removeClass('d-none');
                    empty.addClass('d-none');
                },
                getSelectedCombatConflictId: function () {
                    var fromSelect = this.toInt($('#location-combat-conflict-id').val(), 0);
                    if (fromSelect > 0) {
                        return fromSelect;
                    }
                    return this.toInt(this.combatSelectedConflictId, 0);
                },
                setCombatConflictId: function (conflictId) {
                    var id = this.toInt(conflictId, 0);
                    this.combatSelectedConflictId = id > 0 ? id : 0;
                    var select = $('#location-combat-conflict-id');
                    if (select.length) {
                        if (id > 0 && select.find('option[value="' + id + '"]').length === 0) {
                            select.append('<option value="' + id + '">Conflitto #' + id + '</option>');
                        }
                        if (id > 0) {
                            select.val(String(id));
                        }
                    }
                    this.renderCombatSelectedConflictSummary();
                    this.renderCombatTargetPreview();
                },
                syncCombatConflictSelect: function () {
                    if (!this.combatEnabled) {
                        return;
                    }
                    var select = $('#location-combat-conflict-id');
                    if (!select.length) {
                        return;
                    }

                    var feed = this.conflictsFeed || {};
                    var proposals = Array.isArray(feed.proposals) ? feed.proposals : [];
                    var active = Array.isArray(feed.active) ? feed.active : [];
                    var source = active.concat(proposals);
                    var seen = {};
                    var options = [];
                    for (var i = 0; i < source.length; i++) {
                        var row = source[i] || {};
                        var id = this.toInt(row.id, 0);
                        if (id <= 0 || seen[id]) {
                            continue;
                        }
                        seen[id] = true;
                        var label = 'Conflitto #' + id;
                        var status = String(row.status || '').trim();
                        if (status !== '') {
                            label += ' (' + status + ')';
                        }
                        options.push({ id: id, label: label });
                    }

                    var previous = this.getSelectedCombatConflictId();
                    select.empty();
                    if (options.length === 0) {
                        select.append('<option value="">Nessun conflitto disponibile</option>');
                        this.combatSelectedConflictId = 0;
                        this.combatState = null;
                        this.renderCombatSelectedConflictSummary();
                        this.renderCombatTargetPreview();
                        return;
                    }

                    for (var n = 0; n < options.length; n++) {
                        var item = options[n];
                        select.append('<option value=\"' + item.id + '\">' + this.escapeHtml(item.label) + '</option>');
                    }

                    var selected = previous > 0 && seen[previous] ? previous : options[0].id;
                    this.combatSelectedConflictId = selected;
                    select.val(String(selected));
                    this.renderCombatSelectedConflictSummary();
                    this.renderCombatTargetPreview();
                },
                loadCombatTaxonomy: function () {
                    if (!this.combatEnabled) {
                        return;
                    }
                    var self = this;
                    this.apiPost('/combat/taxonomy', {}, function (response) {
                        self.combatTaxonomy = response && response.dataset ? response.dataset : {};
                        self.renderCombatActionOptions();
                    }, function () {
                        self.combatTaxonomy = {};
                        self.renderCombatActionOptions();
                    });
                },
                renderCombatActionOptions: function () {
                    var select = $('#location-combat-action-type');
                    if (!select.length) {
                        return;
                    }
                    var taxonomy = this.combatTaxonomy || {};
                    var keys = Object.keys(taxonomy);
                    select.empty();
                    select.append('<option value="">Seleziona azione...</option>');
                    if (!keys.length) {
                        this.renderCombatTargetModeControls();
                        return;
                    }

                    keys.sort();
                    for (var i = 0; i < keys.length; i++) {
                        var type = String(keys[i] || '').trim();
                        if (type === '') {
                            continue;
                        }
                        var family = String(taxonomy[type] || '').trim();
                        var label = type;
                        if (family !== '') {
                            label += ' (' + family + ')';
                        }
                        select.append('<option value="' + this.escapeHtml(type) + '">' + this.escapeHtml(label) + '</option>');
                    }
                    this.renderCombatTargetModeControls();
                },
                suggestCombatTargetModeForAction: function (actionType) {
                    var type = String(actionType || '').trim().toLowerCase();
                    if (type === '') {
                        return 'self';
                    }

                    var taxonomy = this.combatTaxonomy || {};
                    var family = String(taxonomy[type] || '').trim().toLowerCase();
                    if (family === 'offensive' || family === 'control' || family === 'positional') {
                        return 'character';
                    }
                    if (family === 'support') {
                        return 'character';
                    }
                    if (family === 'defensive' || family === 'recovery') {
                        return 'self';
                    }
                    return 'self';
                },
                populateCombatTargetCharacterSelect: function (participants, preferredId) {
                    var select = $('#location-combat-target-character-id');
                    if (!select.length) {
                        return 0;
                    }

                    var previous = this.toInt(select.val(), 0);
                    var preferred = this.toInt(preferredId, 0);
                    select.empty();
                    select.append('<option value="">Seleziona personaggio...</option>');

                    var options = [];
                    if (Array.isArray(participants)) {
                        for (var i = 0; i < participants.length; i++) {
                            var row = participants[i] || {};
                            var characterId = this.toInt(row.character_id, 0);
                            if (characterId <= 0) {
                                continue;
                            }
                            options.push({ id: characterId, label: 'PG #' + characterId });
                        }
                    }

                    options.sort(function (a, b) { return a.id - b.id; });
                    for (var n = 0; n < options.length; n++) {
                        var item = options[n];
                        select.append('<option value="' + item.id + '">' + this.escapeHtml(item.label) + '</option>');
                    }

                    var selected = 0;
                    if (preferred > 0 && select.find('option[value="' + preferred + '"]').length > 0) {
                        selected = preferred;
                    } else if (previous > 0 && select.find('option[value="' + previous + '"]').length > 0) {
                        selected = previous;
                    }
                    if (selected > 0) {
                        select.val(String(selected));
                    }
                    return selected;
                },
                populateCombatTargetTeamSelect: function (sideSummary, preferredKey) {
                    var select = $('#location-combat-target-team-key');
                    if (!select.length) {
                        return '';
                    }

                    var previous = String(select.val() || '').trim();
                    var preferred = String(preferredKey || '').trim();
                    select.empty();
                    select.append('<option value="">Seleziona squadra...</option>');

                    var sides = Array.isArray(sideSummary && sideSummary.sides) ? sideSummary.sides : [];
                    for (var i = 0; i < sides.length; i++) {
                        var row = sides[i] || {};
                        var key = String(row.team_key || '').trim();
                        if (key === '') {
                            continue;
                        }
                        var count = this.toInt(row.count, 0);
                        var label = key + ' (' + count + ')';
                        select.append('<option value="' + this.escapeHtml(key) + '">' + this.escapeHtml(label) + '</option>');
                    }

                    function hasOptionByValue($select, value) {
                        return $select.find('option').filter(function () {
                            return String($(this).val() || '') === String(value || '');
                        }).length > 0;
                    }

                    var selected = '';
                    if (preferred !== '' && hasOptionByValue(select, preferred)) {
                        selected = preferred;
                    } else if (previous !== '' && hasOptionByValue(select, previous)) {
                        selected = previous;
                    }

                    if (selected !== '') {
                        select.val(selected);
                    }
                    return selected;
                },
                populateCombatTargetMultiList: function (participants) {
                    var wrap = $('#location-combat-target-multi-list');
                    if (!wrap.length) {
                        return [];
                    }

                    var previous = [];
                    wrap.find('input[type="checkbox"][data-role="combat-target-multi"]').each(function () {
                        if ($(this).is(':checked')) {
                            var id = parseInt($(this).val(), 10);
                            if (!isNaN(id) && id > 0) {
                                previous.push(id);
                            }
                        }
                    });

                    var seen = {};
                    var rows = [];
                    if (Array.isArray(participants)) {
                        for (var i = 0; i < participants.length; i++) {
                            var p = participants[i] || {};
                            var id = this.toInt(p.character_id, 0);
                            if (id <= 0 || seen[id]) {
                                continue;
                            }
                            seen[id] = true;
                            rows.push({ id: id, label: 'PG #' + id });
                        }
                    }

                    rows.sort(function (a, b) { return a.id - b.id; });
                    wrap.empty();

                    if (!rows.length) {
                        wrap.html('<div class="small text-muted">Nessun personaggio disponibile.</div>');
                        return [];
                    }

                    for (var n = 0; n < rows.length; n++) {
                        var row = rows[n];
                        var checked = previous.indexOf(row.id) >= 0 ? ' checked' : '';
                        var checkboxId = 'location-combat-target-multi-' + row.id;
                        wrap.append(
                            '<div class="form-check mb-1">'
                            + '<input class="form-check-input" type="checkbox" value="' + row.id + '" id="' + checkboxId + '" data-role="combat-target-multi"' + checked + '>'
                            + '<label class="form-check-label small" for="' + checkboxId + '">' + this.escapeHtml(row.label) + '</label>'
                            + '</div>'
                        );
                    }

                    return this.readCombatTargetMultiSelection();
                },
                readCombatTargetMultiSelection: function () {
                    var wrap = $('#location-combat-target-multi-list');
                    if (!wrap.length) {
                        return [];
                    }
                    var selected = [];
                    wrap.find('input[type="checkbox"][data-role="combat-target-multi"]').each(function () {
                        if (!$(this).is(':checked')) {
                            return;
                        }
                        var id = parseInt($(this).val(), 10);
                        if (!isNaN(id) && id > 0) {
                            selected.push(id);
                        }
                    });

                    var unique = [];
                    var seen = {};
                    for (var i = 0; i < selected.length; i++) {
                        var id = selected[i];
                        if (!seen[id]) {
                            seen[id] = true;
                            unique.push(id);
                        }
                    }
                    return unique;
                },
                combatParticipantLabelById: function (characterId) {
                    var id = this.toInt(characterId, 0);
                    if (id <= 0) {
                        return '-';
                    }

                    var state = this.combatState || {};
                    var participants = Array.isArray(state.participant_states) ? state.participant_states : [];
                    for (var i = 0; i < participants.length; i++) {
                        var row = participants[i] || {};
                        if (this.toInt(row.character_id, 0) !== id) {
                            continue;
                        }

                        var parts = [];
                        var name = String(row.character_name || '').trim();
                        var surname = String(row.character_surname || '').trim();
                        if (name !== '') {
                            parts.push(name);
                        }
                        if (surname !== '') {
                            parts.push(surname);
                        }
                        var label = parts.join(' ').trim();
                        if (label !== '') {
                            return label;
                        }
                    }

                    return 'PG #' + id;
                },
                buildCombatTargetPreview: function () {
                    var mode = String($('#location-combat-target-mode').val() || 'self').trim().toLowerCase();
                    if (mode === 'character') {
                        var characterId = this.toInt($('#location-combat-target-character-id').val(), 0);
                        if (characterId <= 0) {
                            return 'Nessun personaggio selezionato';
                        }
                        return this.combatParticipantLabelById(characterId) + ' (PG #' + characterId + ')';
                    }
                    if (mode === 'team') {
                        var teamKey = String($('#location-combat-target-team-key').val() || '').trim();
                        if (teamKey === '') {
                            return 'Nessuna squadra selezionata';
                        }
                        return 'Squadra: ' + teamKey;
                    }
                    if (mode === 'multiple') {
                        var selected = this.readCombatTargetMultiSelection();
                        if (!selected.length) {
                            return 'Nessun bersaglio multiplo selezionato';
                        }
                        var labels = [];
                        for (var i = 0; i < selected.length; i++) {
                            var cid = this.toInt(selected[i], 0);
                            if (cid <= 0) {
                                continue;
                            }
                            labels.push(this.combatParticipantLabelById(cid) + ' (PG #' + cid + ')');
                        }
                        return labels.join(', ');
                    }
                    return 'Se stesso';
                },
                renderCombatSelectedConflictSummary: function () {
                    var wrap = $('#location-combat-selected-conflict');
                    if (!wrap.length) {
                        return;
                    }

                    var conflictId = this.getSelectedCombatConflictId();
                    if (conflictId <= 0) {
                        wrap.text('Conflitto selezionato: -');
                        return;
                    }

                    wrap.text('Conflitto selezionato: #' + conflictId);
                },
                renderCombatTargetPreview: function () {
                    var wrap = $('#location-combat-target-preview');
                    if (!wrap.length) {
                        return;
                    }

                    wrap.text('Target selezionato: ' + this.buildCombatTargetPreview());
                },                renderCombatTargetModeControls: function () {
                    var modeSelect = $('#location-combat-target-mode');
                    var actionSelect = $('#location-combat-action-type');
                    if (!modeSelect.length || !actionSelect.length) {
                        return;
                    }

                    var actionType = String(actionSelect.val() || '').trim();
                    var suggested = this.suggestCombatTargetModeForAction(actionType);
                    var mode = String(modeSelect.val() || '').trim();
                    if (mode === '') {
                        mode = suggested;
                        modeSelect.val(mode);
                    }

                    var state = this.combatState || {};
                    var participants = Array.isArray(state.participant_states) ? state.participant_states : [];
                    var sideSummary = state.side_summary || {};

                    this.populateCombatTargetCharacterSelect(participants, 0);
                    this.populateCombatTargetTeamSelect(sideSummary, '');
                    this.populateCombatTargetMultiList(participants);

                    var showCharacter = mode === 'character';
                    var showTeam = mode === 'team';
                    var showMulti = mode === 'multiple';
                    $('#location-combat-target-character-wrap').toggleClass('d-none', !showCharacter);
                    $('#location-combat-target-team-wrap').toggleClass('d-none', !showTeam);
                    $('#location-combat-target-multi-wrap').toggleClass('d-none', !showMulti);
                    this.renderCombatSelectedConflictSummary();
                    this.renderCombatTargetPreview();
                },
                loadCombatState: function (conflictId, showMissingToast) {
                    if (!this.combatEnabled) {
                        return;
                    }
                    var id = this.toInt(conflictId, 0);
                    if (id <= 0) {
                        this.combatState = null;
                        this.renderCombatHub();
                        return;
                    }
                    this.combatSelectedConflictId = id;
                    var self = this;
                    this.apiPost('/combat/state', { conflict_id: id }, function (response) {
                        self.combatState = response && response.dataset ? response.dataset : null;
                        self.combatTierLevel = self.toInt(self.combatState && self.combatState.tier_level, 1);
                        self.renderCombatHub();
                    }, function (error) {
                        self.combatState = null;
                        self.renderCombatHub();
                        if (showMissingToast) {
                            self.showConflictError(error, 'Stato combattimento non disponibile.');
                        }
                    });
                },
                renderCombatHub: function () {
                    if (!this.combatEnabled) {
                        return;
                    }
                    var state = this.combatState || {};
                    var tier = this.toInt(state.tier_level || this.combatTierLevel, 1);
                    $('#location-combat-tier-badge').text('Tier ' + tier);

                    var summary = $('#location-combat-summary');
                    var participants = Array.isArray(state.participant_states) ? state.participant_states : [];
                    var effects = Array.isArray(state.active_effects) ? state.active_effects : [];
                    var pending = Array.isArray(state.pending_actions) ? state.pending_actions : [];
                    if (summary.length) {
                        if (this.combatSelectedConflictId <= 0) {
                            summary.text('Seleziona un conflitto per vedere lo stato di combattimento.');
                        } else if (!this.combatState) {
                            summary.text('Nessun contesto combattimento avviato per questo conflitto.');
                        } else {
                            summary.text(
                                'Conflitto #' + this.combatSelectedConflictId
                                + ' | Partecipanti: ' + participants.length
                                + ' | Effetti: ' + effects.length
                                + ' | Azioni pendenti: ' + pending.length
                            );
                        }
                    }

                    this.renderCombatTier2State(tier, state, participants);
                    this.renderCombatTargetModeControls();
                    this.renderCombatPending();
                    this.renderCombatTimeline();
                },
                renderCombatTier2State: function (tier, state, participants) {
                    var isTier2 = this.toInt(tier, 1) >= 2;
                    var hasState = !!this.combatState;
                    var hasConflict = this.combatSelectedConflictId > 0;

                    $('#location-combat-tier2-badge').toggleClass('d-none', !isTier2);
                    $('#location-combat-tier2-content').toggleClass('d-none', !(isTier2 && hasState));
                    $('#location-combat-tier2-empty').toggleClass('d-none', isTier2 && hasState);
                    $('#location-combat-tier2-controls').toggleClass('d-none', !(isTier2 && hasState && hasConflict));

                    if (!(isTier2 && hasState)) {
                        $('#location-combat-phase-label').text('-');
                        $('#location-combat-phase-cue').text('-');
                        $('#location-combat-indicators').empty();
                        $('#location-combat-sides').text('-');
                        $('#location-combat-guards').empty();
                        $('#location-combat-environment').text('-');
                        $('#location-combat-environment-raw').text('');
                        this.renderCombatTier2Controls([], null, tier);
                        return;
                    }

                    var phaseInfo = state.phase_info || {};
                    $('#location-combat-phase-label').text(String(phaseInfo.label || phaseInfo.phase || '-'));
                    $('#location-combat-phase-cue').text(String(phaseInfo.narrative_cue || ''));

                    this.renderCombatIndicators(Array.isArray(state.advantage_indicators) ? state.advantage_indicators : []);
                    this.renderCombatSides(state.side_summary || {});
                    this.renderCombatGuards(Array.isArray(state.guard_relations) ? state.guard_relations : []);
                    this.renderCombatEnvironment(
                        Array.isArray(state.environment_conditions) ? state.environment_conditions : [],
                        state.environment_raw || null
                    );
                    this.renderCombatTier2Controls(participants, state.environment_raw || null, tier);
                },
                renderCombatIndicators: function (indicators) {
                    var wrap = $('#location-combat-indicators');
                    if (!wrap.length) {
                        return;
                    }
                    wrap.empty();
                    if (!Array.isArray(indicators) || !indicators.length) {
                        wrap.append('<span class="small text-muted">Nessun indicatore disponibile.</span>');
                        return;
                    }

                    for (var i = 0; i < indicators.length; i++) {
                        var row = indicators[i] || {};
                        var characterId = this.toInt(row.character_id, 0);
                        var label = String(row.label || '-');
                        var text = 'PG #' + characterId + ': ' + label;
                        if (this.isStaff && row.raw !== undefined && row.raw !== null && row.raw !== '') {
                            text += ' (' + this.escapeHtml(String(row.raw)) + ')';
                        }
                        wrap.append('<span class="badge text-bg-secondary">' + this.escapeHtml(text) + '</span>');
                    }
                },
                renderCombatSides: function (sideSummary) {
                    var wrap = $('#location-combat-sides');
                    if (!wrap.length) {
                        return;
                    }
                    var sides = Array.isArray(sideSummary && sideSummary.sides) ? sideSummary.sides : [];
                    var superiority = String((sideSummary && sideSummary.numerical_superiority) || '').trim();
                    if (!sides.length) {
                        wrap.text('Nessun lato disponibile.');
                        return;
                    }

                    var lines = [];
                    for (var i = 0; i < sides.length; i++) {
                        var side = sides[i] || {};
                        var teamKey = String(side.team_key || 'solo');
                        var count = this.toInt(side.count, 0);
                        var avg = this.toFloat(side.advantage_avg, 0);
                        var row = '<li><b>' + this.escapeHtml(teamKey) + '</b>: ' + count + ' attivi, vantaggio medio ' + this.formatNumber(avg, 1);
                        if (superiority !== '' && superiority === teamKey) {
                            row += ' <span class="badge text-bg-primary">Superiore</span>';
                        }
                        row += '</li>';
                        lines.push(row);
                    }

                    wrap.html('<ul class="mb-0 ps-3 small">' + lines.join('') + '</ul>');
                },
                renderCombatGuards: function (guards) {
                    var wrap = $('#location-combat-guards');
                    if (!wrap.length) {
                        return;
                    }
                    wrap.empty();
                    if (!Array.isArray(guards) || !guards.length) {
                        wrap.append('<li class="text-muted">Nessuna guardia attiva.</li>');
                        return;
                    }

                    for (var i = 0; i < guards.length; i++) {
                        var row = guards[i] || {};
                        var guardianId = this.toInt(row.guardian_id, 0);
                        var protectedId = this.toInt(row.protected_id, 0);
                        var upkeep = this.toInt(row.stamina_upkeep, 0);
                        wrap.append('<li>PG #' + guardianId + ' protegge PG #' + protectedId + ' <span class="text-muted">(-' + upkeep + ' stamina)</span></li>');
                    }
                },
                renderCombatEnvironment: function (conditions, envRaw) {
                    var wrap = $('#location-combat-environment');
                    if (wrap.length) {
                        wrap.empty();
                        if (!Array.isArray(conditions) || !conditions.length) {
                            wrap.html('<span class="text-muted">Nessuna condizione ambientale.</span>');
                        } else {
                            for (var i = 0; i < conditions.length; i++) {
                                var label = String(conditions[i] || '').trim();
                                if (label === '') {
                                    continue;
                                }
                                wrap.append('<span class="badge text-bg-secondary me-1 mb-1">' + this.escapeHtml(label) + '</span>');
                            }
                        }
                    }

                    var raw = $('#location-combat-environment-raw');
                    if (raw.length) {
                        if (envRaw && typeof envRaw === 'object') {
                            var rawText = 'Visibilita: ' + this.toInt(envRaw.visibility_level, 0)
                                + ' | Mobilita: ' + this.toInt(envRaw.mobility_level, 0)
                                + ' | Pericolo: ' + this.toInt(envRaw.hazard_level, 0)
                                + ' | Copertura: ' + this.toInt(envRaw.cover_density, 0);
                            raw.text(rawText);
                        } else {
                            raw.text('');
                        }
                    }
                },
                renderCombatTier2Controls: function (participants, envRaw, tier) {
                    var isTier2 = this.toInt(tier, 1) >= 2;
                    var guardianSelect = $('#location-combat-guard-guardian-id');
                    var protectedSelect = $('#location-combat-guard-protected-id');

                    if (guardianSelect.length && protectedSelect.length) {
                        var oldGuardian = this.toInt(guardianSelect.val(), 0);
                        var oldProtected = this.toInt(protectedSelect.val(), 0);
                        guardianSelect.empty();
                        protectedSelect.empty();
                        guardianSelect.append('<option value="">Guardiano</option>');
                        protectedSelect.append('<option value="">Protetto</option>');

                        if (Array.isArray(participants) && participants.length) {
                            for (var i = 0; i < participants.length; i++) {
                                var row = participants[i] || {};
                                var characterId = this.toInt(row.character_id, 0);
                                if (characterId <= 0) {
                                    continue;
                                }
                                var label = 'PG #' + characterId;
                                guardianSelect.append('<option value="' + characterId + '">' + this.escapeHtml(label) + '</option>');
                                protectedSelect.append('<option value="' + characterId + '">' + this.escapeHtml(label) + '</option>');
                            }
                        }

                        if (oldGuardian > 0 && guardianSelect.find('option[value="' + oldGuardian + '"]').length > 0) {
                            guardianSelect.val(String(oldGuardian));
                        }
                        if (oldProtected > 0 && protectedSelect.find('option[value="' + oldProtected + '"]').length > 0) {
                            protectedSelect.val(String(oldProtected));
                        }
                    }

                    var visibility = $('#location-combat-env-visibility');
                    var mobility = $('#location-combat-env-mobility');
                    var hazard = $('#location-combat-env-hazard');
                    var cover = $('#location-combat-env-cover');
                    var notes = $('#location-combat-env-notes');

                    if (!(visibility.length && mobility.length && hazard.length && cover.length && notes.length)) {
                        return;
                    }

                    if (!isTier2) {
                        visibility.val('');
                        mobility.val('');
                        hazard.val('');
                        cover.val('');
                        notes.val('');
                        return;
                    }

                    if (envRaw && typeof envRaw === 'object') {
                        visibility.val(String(this.toInt(envRaw.visibility_level, 10)));
                        mobility.val(String(this.toInt(envRaw.mobility_level, 10)));
                        hazard.val(String(this.toInt(envRaw.hazard_level, 0)));
                        cover.val(String(this.toInt(envRaw.cover_density, 0)));
                        notes.val(String(envRaw.notes || ''));
                    } else {
                        if (String(visibility.val() || '').trim() === '') {
                            visibility.val('10');
                        }
                        if (String(mobility.val() || '').trim() === '') {
                            mobility.val('10');
                        }
                        if (String(hazard.val() || '').trim() === '') {
                            hazard.val('0');
                        }
                        if (String(cover.val() || '').trim() === '') {
                            cover.val('0');
                        }
                    }
                },
                createCombatGuardRelation: function (conflictId) {
                    var id = this.toInt(conflictId, 0);
                    if (id <= 0) {
                        this.showConflictError(null, 'Seleziona un conflitto valido.');
                        return;
                    }

                    var guardianId = this.toInt($('#location-combat-guard-guardian-id').val(), 0);
                    var protectedId = this.toInt($('#location-combat-guard-protected-id').val(), 0);
                    var upkeep = this.toInt($('#location-combat-guard-upkeep').val(), 5);
                    if (guardianId <= 0 || protectedId <= 0) {
                        this.showConflictError(null, 'Seleziona guardiano e protetto.');
                        return;
                    }

                    var self = this;
                    this.apiPost('/combat/group/guard', {
                        conflict_id: id,
                        guardian_id: guardianId,
                        protected_id: protectedId,
                        stamina_upkeep: Math.max(0, Math.min(50, upkeep))
                    }, function () {
                        self.loadCombatState(id, false);
                        if (window.Toast && typeof window.Toast.show === 'function') {
                            window.Toast.show({ body: 'Guardia aggiornata.', type: 'success' });
                        }
                    }, function (error) {
                        self.showConflictError(error, 'Aggiornamento guardia non riuscito.');
                    });
                },
                removeCombatGuardRelation: function (conflictId) {
                    var id = this.toInt(conflictId, 0);
                    if (id <= 0) {
                        this.showConflictError(null, 'Seleziona un conflitto valido.');
                        return;
                    }

                    var guardianId = this.toInt($('#location-combat-guard-guardian-id').val(), 0);
                    if (guardianId <= 0) {
                        this.showConflictError(null, 'Seleziona il guardiano da rimuovere.');
                        return;
                    }

                    var self = this;
                    this.apiPost('/combat/group/unguard', {
                        conflict_id: id,
                        guardian_id: guardianId
                    }, function () {
                        self.loadCombatState(id, false);
                        if (window.Toast && typeof window.Toast.show === 'function') {
                            window.Toast.show({ body: 'Guardia rimossa.', type: 'success' });
                        }
                    }, function (error) {
                        self.showConflictError(error, 'Rimozione guardia non riuscita.');
                    });
                },
                saveCombatEnvironment: function (conflictId) {
                    var id = this.toInt(conflictId, 0);
                    if (id <= 0) {
                        this.showConflictError(null, 'Seleziona un conflitto valido.');
                        return;
                    }

                    var visibility = Math.max(0, Math.min(10, this.toInt($('#location-combat-env-visibility').val(), 10)));
                    var mobility = Math.max(0, Math.min(10, this.toInt($('#location-combat-env-mobility').val(), 10)));
                    var hazard = Math.max(0, Math.min(10, this.toInt($('#location-combat-env-hazard').val(), 0)));
                    var cover = Math.max(0, Math.min(10, this.toInt($('#location-combat-env-cover').val(), 0)));
                    var notes = String($('#location-combat-env-notes').val() || '').trim();

                    var self = this;
                    this.apiPost('/combat/env/set', {
                        conflict_id: id,
                        visibility_level: visibility,
                        mobility_level: mobility,
                        hazard_level: hazard,
                        cover_density: cover,
                        notes: notes
                    }, function () {
                        self.loadCombatState(id, false);
                        if (window.Toast && typeof window.Toast.show === 'function') {
                            window.Toast.show({ body: 'Ambiente aggiornato.', type: 'success' });
                        }
                    }, function (error) {
                        self.showConflictError(error, 'Aggiornamento ambiente non riuscito.');
                    });
                },
                renderCombatPending: function () {
                    var state = this.combatState || {};
                    var pending = Array.isArray(state.pending_actions) ? state.pending_actions : [];
                    var wrap = $('#location-combat-pending');
                    var empty = $('#location-combat-pending-empty');
                    if (!wrap.length) {
                        return;
                    }
                    wrap.empty();
                    if (!pending.length) {
                        empty.removeClass('d-none');
                        return;
                    }
                    empty.addClass('d-none');

                    for (var i = 0; i < pending.length; i++) {
                        var row = pending[i] || {};
                        var actionId = this.toInt(row.id, 0);
                        var actorId = this.toInt(row.actor_id, 0);
                        var actionType = String(row.action_type || '-');
                        var primaryTargetId = this.toInt(row.primary_target_id, 0);
                        var secondaryTargets = [];
                        try {
                            secondaryTargets = JSON.parse(String(row.secondary_targets || '[]'));
                            if (!Array.isArray(secondaryTargets)) {
                                secondaryTargets = [];
                            }
                        } catch (err) {
                            secondaryTargets = [];
                        }
                        var secondaryCount = secondaryTargets.length;
                        var targetLabel = '';
                        if (primaryTargetId > 0) {
                            targetLabel = ' -> PG #' + primaryTargetId;
                            if (secondaryCount > 0) {
                                targetLabel += ' +' + secondaryCount;
                            }
                        }
                        var line = ''
                            + '<div class="list-group-item py-2">'
                            + '<div class="d-flex justify-content-between align-items-center gap-2">'
                            + '<div class="small"><b>PG #' + this.escapeHtml(String(actorId)) + '</b> - ' + this.escapeHtml(actionType) + this.escapeHtml(targetLabel) + '</div>';
                        if (this.isStaff && actionId > 0) {
                            line += '<button type="button" class="btn btn-sm btn-outline-primary" data-action="location-combat-resolve" data-intent-id="' + actionId + '">Risolvi</button>';
                        }
                        line += '</div></div>';
                        wrap.append(line);
                    }
                },
                renderCombatTimeline: function () {
                    var state = this.combatState || {};
                    var timeline = Array.isArray(state.timeline) ? state.timeline : [];
                    var wrap = $('#location-combat-timeline');
                    var empty = $('#location-combat-timeline-empty');
                    if (!wrap.length) {
                        return;
                    }
                    wrap.empty();
                    if (!timeline.length) {
                        empty.removeClass('d-none');
                        return;
                    }
                    empty.addClass('d-none');

                    var max = Math.min(timeline.length, 12);
                    for (var i = timeline.length - 1; i >= timeline.length - max; i--) {
                        var row = timeline[i] || {};
                        var actorId = this.toInt(row.actor_id, 0);
                        var actionType = String(row.action_type || '-');
                        var created = String(row.created_at || '');
                        var status = String(row.resolution_status || '');
                        wrap.append(
                            '<div class="list-group-item py-2">'
                            + '<div class="small d-flex justify-content-between align-items-center">'
                            + '<span><b>PG #' + this.escapeHtml(String(actorId)) + '</b> - ' + this.escapeHtml(actionType) + '</span>'
                            + '<span class="text-muted">' + this.escapeHtml(created) + '</span>'
                            + '</div>'
                            + '<div class="small text-muted">Stato: ' + this.escapeHtml(status) + '</div>'
                            + '</div>'
                        );
                    }
                },
                startCombatContext: function (conflictId) {
                    var id = this.toInt(conflictId, 0);
                    if (id <= 0) {
                        this.showConflictError(null, 'Seleziona un conflitto valido.');
                        return;
                    }
                    var self = this;
                    this.apiPost('/combat/start', { conflict_id: id }, function () {
                        self.syncCombatParticipants(id);
                        self.loadCombatState(id, false);
                        if (window.Toast && typeof window.Toast.show === 'function') {
                            window.Toast.show({ body: 'Contesto combattimento avviato.', type: 'success' });
                        }
                    }, function (error) {
                        self.showConflictError(error, 'Avvio contesto combattimento non riuscito.');
                    });
                },
                syncCombatParticipants: function (conflictId) {
                    var id = this.toInt(conflictId, 0);
                    if (id <= 0) {
                        this.showConflictError(null, 'Seleziona un conflitto valido.');
                        return;
                    }
                    var self = this;
                    this.apiPost('/combat/participants/sync', { conflict_id: id }, function (response) {
                        if (window.Toast && typeof window.Toast.show === 'function') {
                            var initialized = self.toInt(response && response.dataset ? response.dataset.initialized : 0, 0);
                            window.Toast.show({ body: 'Partecipanti sincronizzati: ' + initialized + '.', type: 'success' });
                        }
                        self.loadCombatState(id, false);
                    }, function (error) {
                        self.showConflictError(error, 'Sincronizzazione partecipanti non riuscita.');
                    });
                },
                declareCombatAction: function (conflictId) {
                    var id = this.toInt(conflictId, 0);
                    if (id <= 0) {
                        this.showConflictError(null, 'Seleziona un conflitto valido.');
                        return;
                    }

                    var actionType = String($('#location-combat-action-type').val() || '').trim();
                    if (actionType === '') {
                        this.showConflictError(null, 'Seleziona una azione.');
                        return;
                    }

                    var mode = String($('#location-combat-target-mode').val() || '').trim().toLowerCase();
                    if (mode === '') {
                        mode = this.suggestCombatTargetModeForAction(actionType);
                    }

                    var primaryTargetId = 0;
                    var secondaryTargets = [];
                    var targetSummary = 'Se stesso';

                    if (mode === 'character') {
                        primaryTargetId = this.toInt($('#location-combat-target-character-id').val(), 0);
                        if (primaryTargetId <= 0) {
                            this.showConflictError(null, 'Seleziona il personaggio bersaglio.');
                            return;
                        }

                        targetSummary = this.combatParticipantLabelById(primaryTargetId) + ' (PG #' + primaryTargetId + ')';
                    } else if (mode === 'team') {
                        var teamKey = String($('#location-combat-target-team-key').val() || '').trim();
                        if (teamKey === '') {
                            this.showConflictError(null, 'Seleziona la squadra bersaglio.');
                            return;
                        }

                        var sideSummary = (this.combatState && this.combatState.side_summary) ? this.combatState.side_summary : {};
                        var sides = Array.isArray(sideSummary.sides) ? sideSummary.sides : [];
                        var members = [];
                        for (var i = 0; i < sides.length; i++) {
                            var side = sides[i] || {};
                            var sideKey = String(side.team_key || '').trim();
                            if (sideKey !== teamKey) {
                                continue;
                            }
                            var ids = Array.isArray(side.character_ids) ? side.character_ids : [];
                            for (var n = 0; n < ids.length; n++) {
                                var cid = this.toInt(ids[n], 0);
                                if (cid > 0) {
                                    members.push(cid);
                                }
                            }
                            break;
                        }

                        if (!members.length) {
                            this.showConflictError(null, 'Nessun bersaglio disponibile nella squadra selezionata.');
                            return;
                        }

                        primaryTargetId = members[0];
                        for (var m = 1; m < members.length; m++) {
                            if (members[m] > 0 && members[m] !== primaryTargetId) {
                                secondaryTargets.push(members[m]);
                            }
                        }

                        var memberLabels = [];
                        for (var k = 0; k < members.length; k++) {
                            var memberId = this.toInt(members[k], 0);
                            if (memberId <= 0) {
                                continue;
                            }
                            memberLabels.push(this.combatParticipantLabelById(memberId) + ' (PG #' + memberId + ')');
                        }
                        targetSummary = 'Squadra ' + teamKey + ': ' + memberLabels.join(', ');
                    } else if (mode === 'multiple') {
                        var selectedTargets = this.readCombatTargetMultiSelection();
                        if (!selectedTargets.length) {
                            this.showConflictError(null, 'Seleziona almeno un bersaglio multiplo.');
                            return;
                        }

                        primaryTargetId = this.toInt(selectedTargets[0], 0);
                        for (var x = 1; x < selectedTargets.length; x++) {
                            var sid = this.toInt(selectedTargets[x], 0);
                            if (sid > 0 && sid !== primaryTargetId) {
                                secondaryTargets.push(sid);
                            }
                        }

                        var multiLabels = [];
                        for (var z = 0; z < selectedTargets.length; z++) {
                            var multiId = this.toInt(selectedTargets[z], 0);
                            if (multiId <= 0) {
                                continue;
                            }
                            multiLabels.push(this.combatParticipantLabelById(multiId) + ' (PG #' + multiId + ')');
                        }
                        targetSummary = multiLabels.join(', ');
                    }

                    var payload = {
                        conflict_id: id,
                        action_type: actionType
                    };
                    if (primaryTargetId > 0) {
                        payload.primary_target_id = primaryTargetId;
                    }
                    if (secondaryTargets.length > 0) {
                        payload.secondary_targets = secondaryTargets;
                    }

                    var dialogBody = '<p class="mb-1">Conflitto <b>#' + this.escapeHtml(String(id)) + '</b></p>'
                        + '<p class="mb-1">Azione: <b>' + this.escapeHtml(actionType) + '</b></p>'
                        + '<p class="mb-0">Target: <b>' + this.escapeHtml(targetSummary) + '</b></p>';

                    var self = this;
                    var dialog = Dialog('warning', {
                        title: 'Conferma dichiarazione azione',
                        body: dialogBody
                    }, function () {
                        hideGeneralConfirmDialog();
                        self.apiPost('/combat/action/declare', payload, function () {
                            self.loadCombatState(id, false);
                            if (window.Toast && typeof window.Toast.show === 'function') {
                                window.Toast.show({ body: 'Azione dichiarata.', type: 'success' });
                            }
                        }, function (error) {
                            self.showConflictError(error, 'Dichiarazione azione non riuscita.');
                        });
                    });
                    dialog.show();
                },
                resolveCombatAction: function (actionIntentId) {
                    var id = this.toInt(actionIntentId, 0);
                    if (id <= 0) {
                        return;
                    }
                    var self = this;
                    this.apiPost('/combat/action/resolve', {
                        action_intent_id: id,
                        location_id: this.location_id
                    }, function () {
                        self.loadCombatState(self.getSelectedCombatConflictId(), false);
                        if (window.LocationChat && typeof window.LocationChat.load === 'function') {
                            window.LocationChat.load(true);
                        }
                        if (window.Toast && typeof window.Toast.show === 'function') {
                            window.Toast.show({ body: 'Azione risolta.', type: 'success' });
                        }
                    }, function (error) {
                        self.showConflictError(error, 'Risoluzione azione non riuscita.');
                    });
                },
                getProfileModule: function () {
                    if (this.profileModule) {
                        return this.profileModule;
                    }
                    if (typeof resolveModule !== 'function') {
                        return null;
                    }

                    this.profileModule = resolveModule('game.profile');
                    return this.profileModule;
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
                getOnlinesModule: function () {
                    if (this.onlinesModule) {
                        return this.onlinesModule;
                    }
                    if (typeof resolveModule !== 'function') {
                        return null;
                    }

                    this.onlinesModule = resolveModule('game.onlines');
                    return this.onlinesModule;
                },
                callProfile: function (method, payload, onSuccess, onError) {
                    var mod = this.getProfileModule();
                    var fn = String(method || '').trim();
                    if (!mod || fn === '' || typeof mod[fn] !== 'function') {
                        if (typeof onError === 'function') {
                            onError(new Error('Profile module not available: ' + fn));
                        }
                        return false;
                    }

                    mod[fn](payload).then(function (response) {
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
                callInventory: function (method, payload, action, onSuccess, onError) {
                    var mod = this.getInventoryModule();
                    var fn = String(method || '').trim();
                    if (!mod || fn === '' || typeof mod[fn] !== 'function') {
                        if (typeof onError === 'function') {
                            onError(new Error('Inventory module not available: ' + fn));
                        }
                        return false;
                    }

                    var request = null;
                    if (typeof action === 'string' && action.trim() !== '') {
                        request = mod[fn](payload, action);
                    } else {
                        request = mod[fn](payload);
                    }

                    Promise.resolve(request).then(function (response) {
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
                callOnlines: function (method, payload, onSuccess, onError) {
                    var mod = this.getOnlinesModule();
                    var fn = String(method || '').trim();
                    if (!mod || fn === '' || typeof mod[fn] !== 'function') {
                        if (typeof onError === 'function') {
                            onError(new Error('Onlines module not available: ' + fn));
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
                loadProfile: function (options) {
                    options = options || {};
                    var keepOnError = options.keepOnError === true;
                    var skipIfBusy = options.skipIfBusy === true;
                    if (skipIfBusy && this.profileSyncInFlight) {
                        return;
                    }
                    var self = this;
                    this.profileSyncInFlight = true;
                    var started = this.callProfile('getProfile', this.character_id, function (response) {
                        self.profileSyncInFlight = false;
                        self.renderProfile(response && response.dataset ? response.dataset : null);
                    }, function () {
                        self.profileSyncInFlight = false;
                        if (!keepOnError) {
                            self.renderProfile(null);
                        }
                    });
                    if (!started) {
                        this.profileSyncInFlight = false;
                        if (!keepOnError) {
                            this.renderProfile(null);
                        }
                    }
                },
                renderProfile: function (profile) {
                    var loading = $('#location-profile-loading');
                    var content = $('#location-profile-content');
                    var facts = $('#location-profile-facts');
                    var roleList = $('#location-profile-role-list');
                    var modWrap = $('#location-mod-status-wrap');
                    var modText = $('#location-mod-status-text');

                    loading.addClass('d-none');
                    content.removeClass('d-none');
                    facts.empty();
                    if (roleList.length) { roleList.empty(); }

                    if (!profile) {
                        this.renderQuickVitals(null);
                        this.renderUtilityAttributes(null);
                        if (modWrap.length) { modWrap.addClass('d-none'); }
                        return;
                    }

                    this.renderQuickVitals(profile);
                    this.renderUtilityAttributes(profile);

                    var genderLabel = profile.gender == 1 ? 'Maschio' : (profile.gender == 2 ? 'Femmina' : '-');

                    // Main facts list (personal data)
                    var heightLabel = profile.height != null ? profile.height + ' m' : '-';
                    var weightLabel = profile.weight != null ? profile.weight + ' kg' : '-';

                    var rows = [
                        { label: 'Genere',   value: genderLabel },
                        { label: 'Altezza',  value: heightLabel },
                        { label: 'Peso',     value: weightLabel }
                    ];

                    for (var i = 0; i < rows.length; i++) {
                        var row = rows[i];
                        facts.append(
                            '<li class="list-group-item d-flex justify-content-between align-items-center">'
                                + '<span>' + this.escapeHtml(row.label) + '</span>'
                                + '<span class="badge rounded-pill">' + this.escapeHtml(String(row.value)) + '</span>'
                            + '</li>'
                        );
                    }

                    // Segni particolari card
                    var signsWrap = $('#location-profile-signs-wrap');
                    var signsText = $('#location-profile-signs-text');
                    var signsVal = (profile.particular_signs || '').toString().trim();
                    if (signsWrap.length) {
                        if (signsVal) {
                            signsText.text(signsVal);
                            signsWrap.removeClass('d-none');
                        } else {
                            signsWrap.addClass('d-none');
                        }
                    }

                    // Role & belonging list (populated with archetype loaded async)
                    var guilds = Array.isArray(profile.guilds) ? profile.guilds : [];
                    var guildsLabel = guilds.length > 0
                        ? guilds.slice(0, 3).map(function (g) { return g.guild_name || 'Gilda'; }).join(', ')
                            + (guilds.length > 3 ? ' (+' + (guilds.length - 3) + ')' : '')
                        : '-';

                    var roleRows = [
                        { label: 'Archetipo',    value: '...', id: 'location-profile-archetype-cell' },
                        { label: 'Stato sociale', value: profile.socialstatus_name || '-' },
                        { label: 'Occupazione',  value: profile.job_name || '-' },
                        { label: 'Gilde',        value: guildsLabel }
                    ];

                    for (var r = 0; r < roleRows.length; r++) {
                        var rr = roleRows[r];
                        var idAttr = rr.id ? ' id="' + rr.id + '"' : '';
                        roleList.append(
                            '<li class="list-group-item d-flex justify-content-between align-items-center">'
                                + '<span>' + this.escapeHtml(rr.label) + '</span>'
                                + '<span class="badge rounded-pill"' + idAttr + '>' + this.escapeHtml(String(rr.value)) + '</span>'
                            + '</li>'
                        );
                    }

                    // Load archetypes async and update the cell
                    this.apiPost('/archetypes/my', {}, function (response) {
                        var list = (response && Array.isArray(response.dataset)) ? response.dataset : [];
                        var label = list.length > 0
                            ? list.map(function (a) { return a.name || a.slug || 'Archetipo'; }).join(', ')
                            : '-';
                        var cell = $('#location-profile-archetype-cell');
                        if (cell.length) { cell.text(label); }
                    }, function () {
                        var cell = $('#location-profile-archetype-cell');
                        if (cell.length) { cell.text('-'); }
                    });

                    // Note del Master card in aside
                    var modStatus = (profile.mod_status || '').toString().trim();
                    if (modWrap.length) {
                        if (modStatus) {
                            modText.text(modStatus);
                            modWrap.removeClass('d-none');
                        } else {
                            modWrap.addClass('d-none');
                        }
                    }

                },
                renderUtilityAttributes: function (profile) {
                    var wrap = $('#location-utils-attributes-wrap');
                    var list = $('#location-utils-attributes-list');
                    if (!wrap.length || !list.length) {
                        return;
                    }

                    list.empty();
                    var payload = profile && profile.character_attributes ? profile.character_attributes : null;
                    var enabled = !!(payload && parseInt(payload.enabled, 10) === 1);
                    var groups = payload && payload.location ? payload.location : null;
                    if (!enabled || !groups) {
                        wrap.addClass('d-none');
                        return;
                    }

                    var rendered = 0;
                    var order = ['primary', 'secondary', 'narrative'];
                    for (var i = 0; i < order.length; i++) {
                        var entries = Array.isArray(groups[order[i]]) ? groups[order[i]] : [];
                        for (var e = 0; e < entries.length; e++) {
                            var entry = entries[e] || {};
                            var name = String(entry.name || entry.slug || 'Attributo').trim();
                            var value = this.formatNumber(entry.effective_value, 2);
                            list.append(
                                '<div class="d-flex justify-content-between align-items-center">'
                                + '<span>' + this.escapeHtml(name) + '</span>'
                                + '<b>' + this.escapeHtml(value) + '</b>'
                                + '</div>'
                            );
                            rendered++;
                        }
                    }

                    if (rendered <= 0) {
                        wrap.addClass('d-none');
                        return;
                    }

                    wrap.removeClass('d-none');
                },
                refreshSceneLauncher: function () {
                    this.loadBag();
                    document.dispatchEvent(new CustomEvent('location:sceneLauncher.refresh', { detail: { location_id: this.location_id } }));
                    this.loadLocationCharacters();
                    this.loadScenes();
                },
                loadLocationCharacters: function () {
                    var self = this;
                    var started = this.callOnlines('complete', {}, function (response) {
                        var rows = (response && response.dataset) ? response.dataset : [];
                        var locationId = self.toInt(self.location_id, 0);
                        var out = [];
                        for (var i = 0; i < rows.length; i++) {
                            var row = rows[i] || {};
                            var rowLocationId = self.toInt(row.location_id, 0);
                            if (locationId > 0 && rowLocationId !== locationId) {
                                continue;
                            }
                            var characterId = self.toInt(row.id, 0);
                            if (characterId <= 0) {
                                continue;
                            }
                            var label = ((row.name || '') + ' ' + (row.surname || '')).trim();
                            if (label === '') {
                                label = 'Personaggio #' + characterId;
                            }
                            out.push({
                                id: characterId,
                                label: label
                            });
                        }

                        if (out.length === 0 && self.character_id > 0) {
                            out.push({
                                id: self.character_id,
                                label: 'Te stesso'
                            });
                        }

                        self.locationCharacters = out;
                        document.dispatchEvent(new CustomEvent('location:characters.loaded', { detail: { characters: out } }));
                    }, function () {
                        self.locationCharacters = [];
                        document.dispatchEvent(new CustomEvent('location:characters.loaded', { detail: { characters: [] } }));
                    });

                    if (!started) {
                        this.locationCharacters = [];
                        document.dispatchEvent(new CustomEvent('location:characters.loaded', { detail: { characters: [] } }));
                    }
                },
                resolveAbilityTargetType: function (ability) {
                    var target = String((ability && ability.target_type) || 'self').toLowerCase();
                    if (target !== 'character' && target !== 'scene') {
                        target = 'self';
                    }
                    if (target === 'self' && this.toInt(ability && ability.requires_target, 0) === 1) {
                        target = 'character';
                    }
                    return target;
                },
                formatLauncherItemUsage: function (item) {
                    var parts = [];
                    if (this.toInt(item.usable, 0) === 1) {
                        parts.push('<span class="badge text-bg-success">Uso</span>');
                    }
                    if (this.toInt(item.cooldown, 0) > 0) {
                        parts.push('<span class="badge text-bg-secondary">CD ' + this.escapeHtml(String(this.toInt(item.cooldown, 0))) + 's</span>');
                    }
                    var appliesState = String(item.applies_state_name || '').trim();
                    var removesState = String(item.removes_state_name || '').trim();
                    if (appliesState !== '') {
                        parts.push('<span class="badge text-bg-info">Applica: ' + this.escapeHtml(appliesState) + '</span>');
                    }
                    if (removesState !== '') {
                        parts.push('<span class="badge text-bg-warning">Rimuove: ' + this.escapeHtml(removesState) + '</span>');
                    }
                    return parts.join(' ');
                },
                initSceneLauncherGrids: function () {
                    if (typeof Datagrid !== 'function') { return; }
                    var self = this;
                    var PER_PAGE = 10;

                    if (!this.itemsGrid && $('#location-scene-items-grid').length) {
                        this.itemsGrid = Datagrid('location-scene-items-grid', {
                            thead: true,
                            columns: [
                                {
                                    field: 'item_name', label: 'Oggetto', sortable: true,
                                    style: { textAlign: 'left' },
                                    format: function (r) { return self.escapeHtml(String(r.item_name || 'Oggetto')); }
                                },
                                {
                                    field: 'quantity', label: 'Q.tà', width: '60px', sortable: true,
                                    style: { textAlign: 'center' },
                                    format: function (r) { var q = self.toInt(r.quantity, 1); return String(q < 1 ? 1 : q); }
                                },
                                {
                                    field: 'usage', label: 'Uso',
                                    style: { textAlign: 'left' },
                                    format: function (r) { var u = self.formatLauncherItemUsage(r); return u !== '' ? u : '<span class="text-muted">-</span>'; }
                                },
                                {
                                    label: '', width: '220px',
                                    style: { textAlign: 'right' },
                                    format: function (r) { return self.renderSceneLauncherItemActions(r); }
                                }
                            ],
                            lang: { no_results: 'Nessun oggetto disponibile.' },
                            nav: { display: 'bottom', results: PER_PAGE, urlupdate: false }
                        });

                        if (this.itemsGrid) {
                            this.itemsGrid.paginator.urlupdate = false;
                            (function (grid) {
                                grid.paginator.loadData = function (query, results, page, orderBy) {
                                    return self._sceneLauncherLoadData(this, self.itemsFullData, 'item_name', '#location-scene-items-search', query, results, page, orderBy);
                                };
                            })(this.itemsGrid);
                            $('#location-scene-items-search').off('input.sceneLauncher').on('input.sceneLauncher', function () {
                                var p = self.itemsGrid.paginator;
                                p.loadData({ name: $(this).val() }, p.nav.results, 1, p.nav.orderBy);
                            });
                        }
                    }

                    if (!this.abilitiesGrid && $('#location-scene-abilities-grid').length) {
                        this.abilitiesGrid = Datagrid('location-scene-abilities-grid', {
                            thead: true,
                            columns: [
                                {
                                    field: 'name', label: 'Abilità', sortable: true,
                                    style: { textAlign: 'left' },
                                    format: function (r) { return self.escapeHtml(String(r.name || 'Abilità')); }
                                },
                                {
                                    field: 'target', label: 'Bersaglio',
                                    style: { textAlign: 'left' },
                                    format: function (r) { return self.renderSceneLauncherAbilityTarget(r); }
                                },
                                {
                                    field: 'applies_state_name', label: 'Stato',
                                    style: { textAlign: 'left' },
                                    format: function (r) { var s = String(r.applies_state_name || '').trim(); return s !== '' ? self.escapeHtml(s) : '<span class="text-muted">-</span>'; }
                                },
                                {
                                    label: '', width: '120px',
                                    style: { textAlign: 'right' },
                                    format: function (r) { return self.renderSceneLauncherAbilityActions(r); }
                                }
                            ],
                            lang: { no_results: 'Nessuna abilità disponibile.' },
                            nav: { display: 'bottom', results: PER_PAGE, urlupdate: false }
                        });

                        if (this.abilitiesGrid) {
                            this.abilitiesGrid.paginator.urlupdate = false;
                            (function (grid) {
                                grid.paginator.loadData = function (query, results, page, orderBy) {
                                    return self._sceneLauncherLoadData(this, self.abilitiesFullData, 'name', '#location-scene-abilities-search', query, results, page, orderBy);
                                };
                            })(this.abilitiesGrid);
                            $('#location-scene-abilities-search').off('input.sceneLauncher').on('input.sceneLauncher', function () {
                                var p = self.abilitiesGrid.paginator;
                                p.loadData({ name: $(this).val() }, p.nav.results, 1, p.nav.orderBy);
                            });
                        }
                    }
                },

                _sceneLauncherLoadData: function (paginatorInst, fullData, nameField, searchSelector, query, results, page, orderBy) {
                    query = (query && typeof query === 'object') ? query : {};
                    results = paginatorInst.toPositiveInt(results, paginatorInst.nav.results);
                    page = paginatorInst.toPositiveInt(page, 1);
                    orderBy = (typeof orderBy === 'string' && orderBy !== '') ? orderBy : (paginatorInst.nav.orderBy || '');

                    // Always read the current input value so sort clicks preserve the active filter
                    var inputEl = searchSelector ? $(searchSelector) : null;
                    var inputName = (inputEl && inputEl.length) ? String(inputEl.val() || '').trim() : '';
                    if (inputName) {
                        query = Object.assign({}, query, { name: inputName });
                    }

                    var nameFilter = String(query.name || '').trim().toLowerCase();
                    var filtered = [];
                    for (var i = 0; i < (fullData || []).length; i++) {
                        var r = fullData[i];
                        if (nameFilter && String(r[nameField] || '').toLowerCase().indexOf(nameFilter) === -1) { continue; }
                        filtered.push(r);
                    }

                    if (orderBy) {
                        var parts = orderBy.split('|');
                        var sortField = String(parts[0] || '').trim();
                        var sortDir = String(parts[1] || 'ASC').trim().toUpperCase() === 'DESC' ? 'DESC' : 'ASC';
                        if (sortField) {
                            filtered.sort(function (a, b) {
                                var av = a[sortField], bv = b[sortField];
                                var an = parseFloat(av), bn = parseFloat(bv);
                                if (!isNaN(an) && !isNaN(bn)) { return sortDir === 'DESC' ? bn - an : an - bn; }
                                var as = String(av || '').toLowerCase();
                                var bs = String(bv || '').toLowerCase();
                                return as < bs ? (sortDir === 'DESC' ? 1 : -1) : as > bs ? (sortDir === 'DESC' ? -1 : 1) : 0;
                            });
                        }
                    }

                    var total = filtered.length;
                    var start = (page - 1) * results;
                    var slice = filtered.slice(start, start + results);

                    paginatorInst.complete({
                        dataset: slice,
                        properties: {
                            query: query,
                            page: page,
                            results_page: results,
                            orderBy: orderBy,
                            tot: { count: total }
                        }
                    });

                    return paginatorInst;
                },

                initUtilsGrids: function () {
                    if (typeof Datagrid !== 'function') { return; }
                    var self = this;
                    var PER_PAGE = 15;

                    if (!this.bagGrid && $('#location-bag-grid').length) {
                        this.bagGrid = Datagrid('location-bag-grid', {
                            thead: true,
                            columns: [
                                {
                                    field: 'item_image', label: '', width: '52px', sortable: false,
                                    style: { textAlign: 'center', padding: '4px' },
                                    format: function (r) {
                                        var src = (r.item_image && String(r.item_image).trim() !== '')
                                            ? self.escapeHtml(String(r.item_image))
                                            : '/assets/imgs/defaults-images/default-location.png';
                                        return '<img src="' + src + '" width="40" height="40" style="object-fit:cover;border-radius:6px;" alt="">';
                                    }
                                },
                                {
                                    field: 'item_name', label: 'Nome', sortable: true,
                                    style: { textAlign: 'left' },
                                    format: function (r) { return self.escapeHtml(String(r.item_name || 'Oggetto')); }
                                },
                                {
                                    field: 'quantity', label: 'Q.tà', width: '70px', sortable: true,
                                    style: { textAlign: 'center' },
                                    format: function (r) { var q = self.toInt(r.quantity, 1); return String(q < 1 ? 1 : q); }
                                }
                            ],
                            lang: { no_results: 'Nessun oggetto in inventario.' },
                            nav: { display: 'bottom', results: PER_PAGE, urlupdate: false }
                        });

                        if (this.bagGrid) {
                            this.bagGrid.paginator.urlupdate = false;
                            (function (grid) {
                                grid.paginator.loadData = function (query, results, page, orderBy) {
                                    return self._sceneLauncherLoadData(this, self.bagFullData, 'item_name', null, query, results, page, orderBy);
                                };
                            })(this.bagGrid);
                        }
                    }

                    if (!this.equipGrid && $('#location-equip-details-grid').length) {
                        this.equipGrid = Datagrid('location-equip-details-grid', {
                            thead: true,
                            columns: [
                                {
                                    field: 'slot_name', label: 'Slot', width: '120px', sortable: true,
                                    style: { textAlign: 'left' },
                                    format: function (r) { return self.escapeHtml(String(r.slot_name || self.slotLabel(r.slot) || r.slot || '')); }
                                },
                                {
                                    field: 'name', label: 'Oggetto', sortable: true,
                                    style: { textAlign: 'left' },
                                    format: function (r) { return self.escapeHtml(String(r.name || 'Oggetto')); }
                                },
                                {
                                    field: 'type', label: 'Tipo', width: '100px', sortable: true,
                                    style: { textAlign: 'left' },
                                    format: function (r) { return self.escapeHtml(self.itemTypeLabel(r.type)); }
                                }
                            ],
                            lang: { no_results: 'Nessun oggetto equipaggiato.' },
                            nav: { display: 'bottom', results: PER_PAGE, urlupdate: false }
                        });

                        if (this.equipGrid) {
                            this.equipGrid.paginator.urlupdate = false;
                            (function (grid) {
                                grid.paginator.loadData = function (query, results, page, orderBy) {
                                    return self._sceneLauncherLoadData(this, self.equipFullData, 'name', null, query, results, page, orderBy);
                                };
                            })(this.equipGrid);
                        }
                    }
                },

                renderSceneLauncherItemActions: function (item) {
                    var inventoryItemId = this.toInt(item.character_item_id, 0);
                    var instanceId = this.toInt(item.character_item_instance_id, 0);
                    var source = String(item.source || 'stack');
                    var qty = Math.max(1, this.toInt(item.quantity, 1));
                    var name = String(item.item_name || 'Oggetto');
                    var isEquipped = this.toInt(item.is_equipped, 0) === 1;
                    var isDroppable = this.toInt(item.droppable, 0) === 1;
                    var isUsable = this.toInt(item.usable, 0) === 1;
                    var html = '';

                    if (isUsable && inventoryItemId > 0) {
                        html += '<button type="button" class="btn btn-sm btn-outline-primary" data-action="scene-launcher-item-use" data-inventory-item-id="' + inventoryItemId + '" data-item-name="' + this.escapeHtml(name) + '">Usa</button>';
                    }
                    if (isDroppable) {
                        if (isEquipped && instanceId > 0) {
                            html += '<button type="button" class="btn btn-sm btn-outline-secondary ms-1" data-action="scene-launcher-item-unequip" data-instance-id="' + instanceId + '" data-item-name="' + this.escapeHtml(name) + '" title="Rimuovi dall\'equipaggiamento prima di lasciarlo">Rimuovi</button>';
                        } else {
                            html += '<button type="button" class="btn btn-sm btn-outline-warning ms-1" data-action="scene-launcher-item-drop" data-source="' + this.escapeHtml(source) + '" data-character-item-id="' + inventoryItemId + '" data-instance-id="' + instanceId + '" data-quantity="' + qty + '" data-item-name="' + this.escapeHtml(name) + '">Lascia</button>';
                        }
                    }
                    return html;
                },

                renderSceneLauncherItems: function () {
                    if (!this.itemsGrid) { this.initSceneLauncherGrids(); }
                    if (!this.itemsGrid) { return; }

                    var items = [];
                    for (var i = 0; i < this.bagItems.length; i++) {
                        var row = this.bagItems[i] || {};
                        if (!this.toInt(row.usable, 0) && !this.toInt(row.droppable, 0)) { continue; }
                        items.push(row);
                    }

                    this.itemsFullData = items;
                    var p = this.itemsGrid.paginator;
                    p.loadData(p.nav.query || {}, p.nav.results, 1, p.nav.orderBy || 'item_name|ASC');
                },
                renderSceneLauncherAbilityTarget: function (row) {
                    var abilityId = this.toInt(row.id, 0);
                    var targetType = this.resolveAbilityTargetType(row);
                    if (targetType === 'character') {
                        var options = '<option value="">Seleziona...</option>';
                        for (var c = 0; c < this.locationCharacters.length; c++) {
                            var t = this.locationCharacters[c];
                            options += '<option value="' + this.escapeHtml(String(t.id)) + '">' + this.escapeHtml(String(t.label || ('Personaggio #' + t.id))) + '</option>';
                        }
                        return '<select class="form-select form-select-sm" id="location-scene-ability-target-' + abilityId + '">' + options + '</select>';
                    }
                    if (targetType === 'scene') {
                        return '<span class="badge text-bg-info">scena corrente</span>';
                    }
                    return '<span class="badge text-bg-secondary">se stesso</span>';
                },

                renderSceneLauncherAbilityActions: function (row) {
                    var abilityId = this.toInt(row.id, 0);
                    if (abilityId <= 0) { return ''; }
                    var abilityName = String(row.name || 'Abilita');
                    var targetType = this.resolveAbilityTargetType(row);
                    var canUse = targetType !== 'character' || this.locationCharacters.length > 0;
                    return '<button type="button" class="btn btn-sm btn-outline-primary" data-action="scene-launcher-ability-use" data-ability-id="' + abilityId + '" data-ability-name="' + this.escapeHtml(abilityName) + '" data-target-type="' + this.escapeHtml(targetType) + '"' + (canUse ? '' : ' disabled') + '>Usa</button>';
                },

                renderSceneLauncherAbilities: function () {
                    if (!this.abilitiesGrid) { this.initSceneLauncherGrids(); }
                    if (!this.abilitiesGrid) { return; }

                    var items = [];
                    for (var i = 0; i < (this.skillsItems || []).length; i++) {
                        var row = this.skillsItems[i] || {};
                        if (this.toInt(row.id, 0) <= 0) { continue; }
                        items.push(row);
                    }

                    this.abilitiesFullData = items;
                    var p = this.abilitiesGrid.paginator;
                    p.loadData(p.nav.query || {}, p.nav.results, 1, p.nav.orderBy || 'name|ASC');
                },
                useSceneItem: function (inventoryItemId, itemName) {
                    var self = this;
                    this.callInventory('useItem', {
                        inventory_item_id: inventoryItemId
                    }, null, function () {
                        if (window.Toast && typeof window.Toast.show === 'function') {
                            window.Toast.show({
                                body: 'Oggetto usato: ' + itemName + '.',
                                type: 'success'
                            });
                        }

                        self.loadBag();
                        self.loadProfile({ keepOnError: true, skipIfBusy: false });
                        if (window.LocationChat && typeof window.LocationChat.load === 'function') {
                            window.LocationChat.load(true);
                        }
                    }, function (error) {
                        if (window.GameFeatureError && typeof window.GameFeatureError.toastMapped === 'function') {
                            window.GameFeatureError.toastMapped(error, 'Uso oggetto non riuscito.', {
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

                        if (window.Toast && typeof window.Toast.show === 'function') {
                            window.Toast.show({
                                body: 'Uso oggetto non riuscito.',
                                type: 'error'
                            });
                        }
                    });
                },
                dropSceneItem: function (source, characterItemId, instanceId, qty, itemName) {
                    var self = this;
                    var payload = {};
                    if (source === 'instance' && instanceId > 0) {
                        payload.character_item_instance_id = instanceId;
                    } else {
                        payload.character_item_id = characterItemId;
                        payload.quantity = qty;
                    }

                    this.callInventory('drop', payload, null, function () {
                        if (window.Toast && typeof window.Toast.show === 'function') {
                            window.Toast.show({
                                body: 'Oggetto lasciato a terra: ' + itemName + '.',
                                type: 'success'
                            });
                        }
                        self.loadBag();
                        if (window.LocationDrops && typeof window.LocationDrops.reload === 'function') {
                            window.LocationDrops.reload();
                        }
                    }, function (error) {
                        var code = String(error && error.error_code ? error.error_code : '');
                        var map = {
                            item_not_found: 'Oggetto non trovato nell\'inventario.',
                            item_not_droppable: 'Questo oggetto non puo essere lasciato a terra.',
                            insufficient_funds: 'Quantita non sufficiente.'
                        };
                        var msg = map[code] || 'Impossibile lasciare l\'oggetto a terra.';
                        if (window.Toast && typeof window.Toast.show === 'function') {
                            window.Toast.show({ body: msg, type: 'warning' });
                        }
                    });
                },
                refreshSceneLauncherMoneyTab: function () {
                    var self = this;
                    this.callProfile('getProfile', this.character_id, function (response) {
                        var dataset = response && response.dataset ? response.dataset : null;
                        var money = dataset ? self.toInt(dataset.money, 0) : 0;
                        $('#location-scene-money-balance').text(money + ' monete');
                    }, function () {
                        $('#location-scene-money-balance').text('-');
                    });
                },
                renderSceneMoneyCharacterSuggestions: function (rows) {
                    var list = $('#location-scene-money-suggestions');
                    list.empty();
                    if (!rows || rows.length === 0) {
                        list.addClass('d-none');
                        return;
                    }
                    for (var i = 0; i < rows.length; i++) {
                        var row = rows[i];
                        var charId = this.toInt(row.id, 0);
                        var label = String(row.label || row.name || ('Personaggio #' + charId)).trim();
                        list.append(
                            '<button type="button" class="list-group-item list-group-item-action py-1 px-2 small" data-action="scene-money-select-character" data-character-id="' + charId + '" data-character-name="' + this.escapeHtml(label) + '">'
                                + this.escapeHtml(label)
                            + '</button>'
                        );
                    }
                    list.removeClass('d-none');
                },
                giveSceneMoney: function () {
                    var self = this;
                    var targetId = this.toInt($('#location-scene-money-target-id').val(), 0);
                    var targetName = String($('#location-scene-money-target-name').val() || '').trim();
                    var amount = this.toInt($('#location-scene-money-amount').val(), 0);
                    var alertsEl = $('#location-scene-money-alerts');

                    alertsEl.addClass('d-none').empty();

                    if (targetName === '') {
                        alertsEl.removeClass('d-none').html('<div class="alert alert-warning py-1 mb-0 small">Seleziona un destinatario.</div>');
                        return;
                    }
                    if (amount <= 0) {
                        alertsEl.removeClass('d-none').html('<div class="alert alert-warning py-1 mb-0 small">Inserisci una quantita valida.</div>');
                        return;
                    }

                    var target = targetId > 0 ? ('@' + targetId) : targetName;
                    var command = '/dai ' + target + ' ' + amount;

                    this.apiPost('/location/messages/send', {
                        location_id: this.location_id,
                        body: command
                    }, function (response) {
                        if (window.Toast && typeof window.Toast.show === 'function') {
                            window.Toast.show({
                                body: 'Trasferimento completato: ' + amount + ' monete a ' + targetName + '.',
                                type: 'success'
                            });
                        }
                        $('#location-scene-money-target-id').val('');
                        $('#location-scene-money-target-name').val('');
                        $('#location-scene-money-amount').val('');
                        var row = response && response.dataset ? response.dataset : null;
                        if (row && window.LocationChat && typeof window.LocationChat.appendMessages === 'function') {
                            window.LocationChat.appendMessages([row]);
                        } else if (window.LocationChat && typeof window.LocationChat.load === 'function') {
                            window.LocationChat.load(true);
                        }
                        self.refreshSceneLauncherMoneyTab();
                        self.loadProfile({ keepOnError: true, skipIfBusy: false });
                    }, function (error) {
                        var code = String(error && error.error_code ? error.error_code : '');
                        var map = {
                            insufficient_funds: 'Fondi insufficienti.',
                            target_not_found: 'Personaggio destinatario non trovato nella location.',
                            give_self_not_allowed: 'Non puoi dare monete a te stesso.',
                            invalid_amount: 'Quantita non valida.'
                        };
                        var msg = map[code] || 'Trasferimento non riuscito.';
                        alertsEl.removeClass('d-none').html('<div class="alert alert-warning py-1 mb-0 small">' + self.escapeHtml(msg) + '</div>');
                    });
                },
                useSceneAbility: function (payload) {
                    if (!this.abilitiesEnabled) {
                        if (window.Toast && typeof window.Toast.show === 'function') {
                            window.Toast.show({
                                body: 'Funzionalita abilita non attiva.',
                                type: 'warning'
                            });
                        }
                        return;
                    }

                    var self = this;
                    var abilityId = this.toInt(payload && payload.ability_id, 0);
                    var abilityName = String((payload && payload.ability_name) || '').trim();
                    var targetType = String((payload && payload.target_type) || 'self').toLowerCase();
                    var targetId = this.toInt(payload && payload.target_id, 0);

                    var requestPayload = {
                        location_id: this.location_id,
                        scene_id: this.location_id
                    };
                    if (abilityId > 0) {
                        requestPayload.ability_id = abilityId;
                    }
                    if (abilityName !== '') {
                        requestPayload.ability_name = abilityName;
                        requestPayload.ability = abilityName;
                    }
                    if (targetType === 'character' && targetId > 0) {
                        requestPayload.target_id = targetId;
                        requestPayload.target_character_id = targetId;
                    }
                    if (targetType === 'scene') {
                        requestPayload.scene_id = this.location_id;
                    }

                    this.callAbilities('use', requestPayload, function (response) {
                        var dataset = (response && response.dataset) ? response.dataset : {};
                        if (dataset.chat_message && window.LocationChat && typeof window.LocationChat.appendMessages === 'function') {
                            window.LocationChat.appendMessages([dataset.chat_message]);
                        } else if (window.LocationChat && typeof window.LocationChat.load === 'function') {
                            window.LocationChat.load(true);
                        }

                        if (window.Toast && typeof window.Toast.show === 'function') {
                            window.Toast.show({
                                body: 'Abilita usata.',
                                type: 'success'
                            });
                        }
                    }, function (error) {
                        if (window.GameFeatureError && typeof window.GameFeatureError.toastMapped === 'function') {
                            window.GameFeatureError.toastMapped(error, 'Uso abilita non riuscito.', {
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
                                    ability_target_required: 'Questa abilita richiede un bersaglio.',
                                    state_not_found: 'Stato narrativo non trovato.',
                                    state_conflict_blocked: 'Conflitto con uno stato narrativo gia attivo.',
                                    state_apply_failed: 'Applicazione stato non riuscita.',
                                    state_remove_failed: 'Rimozione stato non riuscita.'
                                }
                            });
                            return;
                        }

                        if (window.Toast && typeof window.Toast.show === 'function') {
                            window.Toast.show({
                                body: 'Uso abilita non riuscito.',
                                type: 'error'
                            });
                        }
                    });
                },
                loadBag: function () {
                    var self = this;
                    this.callInventory('categories', null, 'getLocationBagCategories', function (response) {
                        self.bagCategories = (response && response.dataset) ? response.dataset : [];
                        if (self.bagCategoryActive === null) {
                            self.bagCategoryActive = 'all';
                        }
                        self.renderBagTabs();
                    }, function () {
                        self.bagCategories = [];
                        if (self.bagCategoryActive === null) {
                            self.bagCategoryActive = 'all';
                        }
                        self.renderBagTabs();
                    });

                    this.callInventory('bagItems', {
                        page: 1,
                        results: 250,
                        orderBy: 'item_name|ASC'
                    }, 'getLocationBagItems', function (response) {
                        self.bagItems = (response && response.dataset) ? response.dataset : [];
                        self.renderBagItems();
                        self.renderSceneLauncherItems();
                    }, function () {
                        self.bagItems = [];
                        self.renderBagItems();
                        self.renderSceneLauncherItems();
                    });
                },
                renderBagTabs: function () {
                    var tabs = $('#location-bag-categories');
                    if (!tabs.length) {
                        return;
                    }
                    tabs.empty();

                    var allActive = (this.bagCategoryActive === 'all' || this.bagCategoryActive === null);
                    tabs.append(
                        '<li class="nav-item" role="presentation">'
                            + '<a href="#" class="nav-link ' + (allActive ? 'active' : '') + '" data-bag-category="all">Tutti</a>'
                        + '</li>'
                    );

                    if (!this.bagCategories || this.bagCategories.length === 0) {
                        return;
                    }

                    for (var i = 0; i < this.bagCategories.length; i++) {
                        var c = this.bagCategories[i];
                        var id = String(this.toInt(c.category_id, 0));
                        var name = c.name || 'Altro';
                        var active = (this.bagCategoryActive === id);
                        tabs.append(
                            '<li class="nav-item" role="presentation">'
                                + '<a href="#" class="nav-link ' + (active ? 'active' : '') + '" data-bag-category="' + this.escapeHtml(id) + '">' + this.escapeHtml(name) + '</a>'
                            + '</li>'
                        );
                    }
                },
                renderBagItems: function () {
                    if (!$('#location-bag-grid').length) {
                        return;
                    }

                    this.initUtilsGrids();
                    if (!this.bagGrid) { return; }

                    var items = [];
                    var active = this.bagCategoryActive;
                    for (var i = 0; i < this.bagItems.length; i++) {
                        var row = this.bagItems[i];
                        var categoryId = String(this.toInt(row.item_category_id, 0));
                        if (active && active !== 'all' && categoryId !== active) {
                            continue;
                        }
                        items.push(row);
                    }

                    this.bagFullData = items;
                    var p = this.bagGrid.paginator;
                    p.loadData(p.nav.query || {}, p.nav.results, 1, p.nav.orderBy || 'item_name|ASC');
                },
                loadEquipped: function () {
                    var self = this;
                    this.callInventory('equipped', null, 'getLocationEquipped', function (response) {
                        self.equippedItems = (response && response.items) ? response.items : [];
                        self.renderEquipped();
                    }, function () {
                        self.equippedItems = [];
                        self.renderEquipped();
                    });
                },
                loadEquipmentSlots: function () {
                    var self = this;
                    this.callInventory('slots', null, 'getLocationEquipSlots', function (response) {
                        self.equipmentSlots = (response && response.slots) ? response.slots : [];
                        self.renderEquipped();
                    }, function () {
                        self.equipmentSlots = [];
                        self.renderEquipped();
                    });
                },
                renderEquipped: function () {
                    var slotGridEl = $('#location-equip-slots-grid');
                    var detailsGridEl = $('#location-equip-details-grid');
                    if (!slotGridEl.length && !detailsGridEl.length) {
                        return;
                    }

                    var equipped = this.equippedItems || [];
                    var slots = this.equipmentSlots || [];
                    var bySlot = {};
                    for (var i = 0; i < equipped.length; i++) {
                        if (!equipped[i] || !equipped[i].slot) { continue; }
                        bySlot[String(equipped[i].slot)] = equipped[i];
                    }

                    if (!slots.length && equipped.length) {
                        for (var d = 0; d < equipped.length; d++) {
                            var row = equipped[d] || {};
                            var dynamicKey = String(row.slot || '').trim();
                            if (!dynamicKey) { continue; }
                            slots.push({ key: dynamicKey, name: String(row.slot_name || this.slotLabel(dynamicKey)) });
                        }
                    }

                    // Render slot visuals into the pre-defined container
                    if (slotGridEl.length) {
                        slotGridEl.empty();
                        if (!slots.length) {
                            slotGridEl.append('<div class="text-muted small">Nessuno slot disponibile.</div>');
                        } else {
                            for (var s = 0; s < slots.length; s++) {
                                var slotDef = slots[s] || {};
                                var slotKey = String(slotDef.key || '').trim();
                                if (!slotKey) {
                                    continue;
                                }

                                var item = bySlot[slotKey] || null;
                                var label = String(slotDef.name || this.slotLabel(slotKey) || slotKey);
                                var image = (item && item.image) ? String(item.image) : '/assets/imgs/defaults-images/default-location.png';
                                var name = (item && item.name) ? String(item.name) : 'Vuoto';

                                var slotNode = $('<div class="border" data-equip-slot="' + this.escapeHtml(slotKey) + '"></div>');
                                var slotHtml = ''
                                    + '<div class="equip-slot-box' + (item ? ' is-filled' : '') + '">'
                                    + '  <div class="equip-slot-label">' + this.escapeHtml(label) + '</div>';
                                if (item) {
                                    slotHtml += '  <img class="equip-slot-image" src="' + this.escapeHtml(image) + '" alt="">'
                                        + '  <div class="equip-slot-name">' + this.escapeHtml(name) + '</div>';
                                } else {
                                    slotHtml += '  <div class="equip-slot-empty">Vuoto</div>';
                                }
                                slotHtml += '</div>';

                                slotNode.html(slotHtml);
                                slotGridEl.append(slotNode);
                            }
                        }
                    }

                    // Render equipped details into the Datagrid
                    if (detailsGridEl.length) {
                        this.initUtilsGrids();
                        if (this.equipGrid) {
                            this.equipFullData = equipped.slice();
                            var p = this.equipGrid.paginator;
                            p.loadData(p.nav.query || {}, p.nav.results, 1, p.nav.orderBy || 'slot_name|ASC');
                        }
                    }
                },
                loadNpcs: function () {
                    var self = this;
                    this.apiPost('/narrative-npcs/list', {}, function (response) {
                        self.npcs = (response && Array.isArray(response.dataset)) ? response.dataset : [];
                        self.renderNpcsPanel();
                    }, function () {
                        self.npcs = [];
                        self.renderNpcsPanel();
                    });
                },
                renderNpcsPanel: function () {
                    var panel = $('#location-npcs-panel');
                    var list = $('#location-npcs-list');
                    if (!panel.length || !list.length) { return; }

                    var npcs = (this.npcs || []).concat(
                        (this.ephemeralNpcs || []).map(function (n) {
                            return Object.assign({}, n, { _ephemeral: true });
                        })
                    );
                    if (npcs.length === 0) {
                        panel.addClass('d-none');
                        return;
                    }

                    panel.removeClass('d-none');

                    var html = '';
                    for (var i = 0; i < npcs.length; i++) {
                        var npc = npcs[i];
                        var name = this.escapeHtml(String(npc.name || ''));
                        var desc = npc.description ? this.escapeHtml(String(npc.description)) : '';
                        var img  = npc.image ? String(npc.image) : '';

                        var groupBadge = '';
                        if (npc._ephemeral) {
                            groupBadge = ' <span class="badge text-bg-warning" style="font-size:.65rem;">Effimero</span>';
                        } else if (npc.group_type && npc.group_type !== 'none') {
                            var groupLabel = npc.group_type === 'guild' ? 'Gilda' : 'Fazione';
                            groupBadge = ' <span class="badge text-bg-secondary" style="font-size:.65rem;">' + this.escapeHtml(groupLabel) + '</span>';
                        }

                        var avatarHtml = img
                            ? '<img src="' + this.escapeHtml(img) + '" alt="" class="rounded me-2 flex-shrink-0" style="width:32px;height:32px;object-fit:cover;">'
                            : '<span class="me-2 flex-shrink-0 rounded bg-secondary d-flex align-items-center justify-content-center" style="width:32px;height:32px;"><i class="bi bi-person-fill text-light" style="font-size:.9rem;"></i></span>';

                        html += '<div class="d-flex align-items-start mb-2 pb-2' + (i < npcs.length - 1 ? ' border-bottom' : '') + '">';
                        html += avatarHtml;
                        html += '<div class="min-w-0">';
                        html += '<div class="small fw-semibold lh-sm">' + name + groupBadge + '</div>';
                        if (desc) {
                            html += '<div class="small text-muted mt-1" style="line-height:1.3;">' + desc + '</div>';
                        }
                        html += '</div></div>';
                    }

                    list.html(html);
                },
                spawnEphemeralNpc: function (btn) {
                    var self = this;
                    if (!self.activeScene) { return; }
                    var nameEl    = document.getElementById('scene-npc-name');
                    var descEl    = document.getElementById('scene-npc-description');
                    var alertEl   = document.getElementById('scene-npc-spawn-alert');
                    var spinner   = document.getElementById('scene-npc-spawn-spinner');
                    var name = nameEl ? nameEl.value.trim() : '';
                    if (!name) {
                        if (alertEl) { alertEl.textContent = 'Il nome è obbligatorio.'; alertEl.classList.remove('d-none'); }
                        return;
                    }
                    if (alertEl) { alertEl.classList.add('d-none'); }
                    if (spinner) { spinner.classList.remove('d-none'); }
                    if (btn) { btn.disabled = true; }

                    var mod = self.resolveEphemeralNpcsModule();
                    if (!mod || typeof mod.spawn !== 'function') { return; }
                    mod.spawn({
                        event_id: self.activeScene.id,
                        name: name,
                        description: descEl ? descEl.value.trim() : '',
                        location_id: self.location_id
                    }).then(function () {
                        if (spinner) { spinner.classList.add('d-none'); }
                        if (btn) { btn.disabled = false; }
                        var form = document.getElementById('location-scene-narrative-spawn-form');
                        if (form) { form.classList.add('d-none'); }
                        if (window.Toast) { window.Toast.show({ body: 'PNG Effimero spawnato.', type: 'success' }); }
                        self.loadEphemeralNpcs();
                    }).catch(function (err) {
                        if (spinner) { spinner.classList.add('d-none'); }
                        if (btn) { btn.disabled = false; }
                        var msg = (err && err.message) || 'Errore durante lo spawn.';
                        if (alertEl) { alertEl.textContent = msg; alertEl.classList.remove('d-none'); }
                    });
                },

                deleteEphemeralNpc: function (npcId, btn) {
                    var self = this;
                    var mod = self.resolveEphemeralNpcsModule();
                    if (!mod || typeof mod.delete !== 'function') { return; }
                    if (btn) { btn.disabled = true; }
                    mod.delete({ id: npcId }).then(function () {
                        self.loadEphemeralNpcs();
                    }).catch(function (err) {
                        if (btn) { btn.disabled = false; }
                        if (window.Toast) { window.Toast.show({ body: (err && err.message) || 'Errore.', type: 'error' }); }
                    });
                },

                updateNarrativeTab: function () {
                    this.renderSceneLauncherNarrativeTab();
                },

                reloadInventory: function () {
                    this.loadBag();
                    this.loadEquipmentSlots();
                    this.loadEquipped();
                },

                // ── Scene narrative ──────────────────────────────────────────
                narrativeCapabilities: [],
                narrativeCanCreate: false,
                activeScene: null,
                ephemeralNpcs: [],

                loadNarrativeCapabilities: function () {
                    var self = this;
                    var mod = this.resolveNarrativeEventsModule();
                    if (!mod || typeof mod.getCapabilities !== 'function') { return; }
                    mod.getCapabilities().then(function (res) {
                        var caps = (res && res.dataset && Array.isArray(res.dataset.capabilities)) ? res.dataset.capabilities : [];
                        self.narrativeCapabilities = caps;
                        self.narrativeCanCreate = caps.indexOf('narrative.event.create') !== -1;
                        self.updateNarrativeTab();
                        self.loadScenes();
                    }).catch(function () {});
                },

                loadScenes: function () {
                    var self = this;
                    var mod = this.resolveNarrativeEventsModule();
                    if (!mod || typeof mod.listScenes !== 'function') { return; }
                    mod.listScenes({ location_id: self.location_id }).then(function (res) {
                        var scenes = (res && Array.isArray(res.dataset)) ? res.dataset : [];
                        self.activeScene = scenes.length > 0 ? scenes[0] : null;
                        self.renderScenesPanel();
                        self.loadEphemeralNpcs();
                        self.renderSceneLauncherNarrativeTab();
                    }).catch(function () {});
                },

                loadEphemeralNpcs: function () {
                    var self = this;
                    if (!self.activeScene) { self.ephemeralNpcs = []; return; }
                    var mod = this.resolveEphemeralNpcsModule();
                    if (!mod || typeof mod.list !== 'function') { return; }
                    mod.list({ location_id: self.location_id }).then(function (res) {
                        self.ephemeralNpcs = (res && Array.isArray(res.dataset)) ? res.dataset : [];
                        self.renderNpcsPanel();
                        self.renderSceneLauncherNarrativeTab();
                    }).catch(function () {});
                },

                renderScenesPanel: function () {
                    var self = this;
                    var panel = $('#location-scenes-panel');
                    var list  = $('#location-scenes-list');
                    if (!panel.length) { return; }

                    var scene = self.activeScene;
                    if (!scene) { panel.addClass('d-none'); list.html(''); return; }

                    panel.removeClass('d-none');
                    var title = self.escapeHtml(String(scene.title || 'Scena'));
                    var desc  = scene.description ? self.escapeHtml(String(scene.description)) : '';
                    var canClose = self.narrativeCanCreate;
                    var html = '<div class="small fw-semibold mb-1">' + title + '</div>'
                        + (desc ? '<div class="small text-muted mb-2" style="line-height:1.3;">' + desc + '</div>' : '')
                        + (canClose
                            ? '<button type="button" class="btn btn-xs btn-outline-danger py-0 px-1 w-100 mt-1" style="font-size:.7rem;" data-action="sidebar-scene-close" data-event-id="' + (scene.id || 0) + '">Chiudi scena</button>'
                            : '');
                    list.html(html);
                },

                renderSceneLauncherNarrativeTab: function () {
                    var self = this;
                    var tabItem    = document.getElementById('location-scene-narrative-tab-item');
                    if (!tabItem) { return; }

                    if (!self.narrativeCanCreate) { tabItem.classList.add('d-none'); return; }
                    tabItem.classList.remove('d-none');

                    var scene      = self.activeScene;
                    var activeDiv  = document.getElementById('location-scene-narrative-active');
                    var createForm = document.getElementById('location-scene-narrative-create-form');
                    var emptyDiv   = document.getElementById('location-scene-narrative-empty');
                    var titleEl    = document.getElementById('location-scene-narrative-title');
                    var descEl     = document.getElementById('location-scene-narrative-desc');
                    var npcsList   = document.getElementById('location-scene-ephemeral-npcs-list');
                    var spawnForm  = document.getElementById('location-scene-narrative-spawn-form');

                    if (!scene) {
                        // Nessuna scena: mostra form di creazione locale
                        if (activeDiv)  { activeDiv.classList.add('d-none'); }
                        if (emptyDiv)   { emptyDiv.classList.add('d-none'); }
                        if (spawnForm)  { spawnForm.classList.add('d-none'); }
                        if (createForm) {
                            createForm.classList.remove('d-none');
                            // Reset form
                            var titleInput  = document.getElementById('scene-create-title');
                            var descInput   = document.getElementById('scene-create-description');
                            var impactInput = document.getElementById('scene-create-impact');
                            var alertEl     = document.getElementById('scene-create-alert');
                            var spinner     = document.getElementById('scene-create-spinner');
                            if (titleInput)  { titleInput.value  = ''; }
                            if (descInput)   { descInput.value   = ''; }
                            if (impactInput) { impactInput.value = '0'; }
                            if (alertEl)     { alertEl.classList.add('d-none'); alertEl.textContent = ''; }
                            if (spinner)     { spinner.classList.add('d-none'); }
                        }
                        return;
                    }

                    // Scena attiva: mostra gestione
                    if (createForm) { createForm.classList.add('d-none'); }
                    if (emptyDiv)   { emptyDiv.classList.add('d-none'); }
                    if (activeDiv)  { activeDiv.classList.remove('d-none'); }
                    if (titleEl)    { titleEl.textContent = scene.title || ''; }
                    if (descEl)     { descEl.textContent  = scene.description || ''; }

                    // Render lista PNG Effimeri
                    if (npcsList) {
                        var npcs = self.ephemeralNpcs || [];
                        if (!npcs.length) {
                            npcsList.innerHTML = '<div class="text-muted small">Nessun PNG Effimero.</div>';
                        } else {
                            npcsList.innerHTML = npcs.map(function (n) {
                                return '<div class="d-flex justify-content-between align-items-center mb-1">'
                                    + '<span class="small">' + self.escapeHtml(n.name || '') + ' <span class="badge text-bg-warning" style="font-size:.6rem;">Effimero</span></span>'
                                    + '<button type="button" class="btn btn-xs btn-outline-danger py-0 px-1" style="font-size:.65rem;" data-action="scene-delete-npc" data-npc-id="' + (n.id || 0) + '">&#x2715;</button>'
                                    + '</div>';
                            }).join('');
                        }
                    }
                },

                createLocalScene: function (btn) {
                    var self = this;
                    var titleInput  = document.getElementById('scene-create-title');
                    var descInput   = document.getElementById('scene-create-description');
                    var impactInput = document.getElementById('scene-create-impact');
                    var alertEl     = document.getElementById('scene-create-alert');
                    var spinner     = document.getElementById('scene-create-spinner');

                    var title  = titleInput  ? titleInput.value.trim()              : '';
                    var desc   = descInput   ? descInput.value.trim()               : '';
                    var impact = impactInput ? parseInt(impactInput.value || '0', 10) : 0;

                    if (alertEl) { alertEl.classList.add('d-none'); alertEl.textContent = ''; }

                    if (!title) {
                        if (alertEl) { alertEl.textContent = 'Il titolo è obbligatorio.'; alertEl.classList.remove('d-none'); }
                        if (titleInput) { titleInput.focus(); }
                        return;
                    }

                    var mod = self.resolveNarrativeEventsModule();
                    if (!mod || typeof mod.create !== 'function') { return; }

                    if (spinner) { spinner.classList.remove('d-none'); }
                    if (btn)     { btn.disabled = true; }

                    mod.create({
                        title:       title,
                        description: desc,
                        scope:       'local',
                        impact_level: impact,
                        location_id: self.location_id || 0
                    }).then(function (response) {
                        if (spinner) { spinner.classList.add('d-none'); }
                        if (btn)     { btn.disabled = false; }
                        var msg = (response && response.message) ? response.message : 'Scena avviata.';
                        if (window.Toast) { window.Toast.show({ body: msg, type: 'success' }); }
                        document.dispatchEvent(new CustomEvent('narrative:scene-changed'));
                    }).catch(function (err) {
                        if (spinner) { spinner.classList.add('d-none'); }
                        if (btn)     { btn.disabled = false; }
                        var msg = (err && err.message) || 'Errore durante la creazione della scena.';
                        if (alertEl) { alertEl.textContent = msg; alertEl.classList.remove('d-none'); }
                    });
                },

                resolveNarrativeEventsModule: function () {
                    if (!window.RuntimeBootstrap || typeof window.RuntimeBootstrap.resolveAppModule !== 'function') { return null; }
                    try { return window.RuntimeBootstrap.resolveAppModule('game.narrative-events'); } catch (e) { return null; }
                },

                resolveEphemeralNpcsModule: function () {
                    if (!window.RuntimeBootstrap || typeof window.RuntimeBootstrap.resolveAppModule !== 'function') { return null; }
                    try { return window.RuntimeBootstrap.resolveAppModule('game.narrative-ephemeral-npcs'); } catch (e) { return null; }
                },

                destroy: function () {
                    this.stopProfilePolling();
                    this.stopConflictsPolling();
                    this.unbind();
                    return this;
                },
                unmount: function () {
                    return this.destroy();
                }
            };
            let sidebar = Object.assign({}, page, extension);
            return sidebar.init();
    }

    window.GameLocationSidebarPage = GameLocationSidebarPage;
})(window);




















