(function (window) {
    'use strict';

    var extension = {
        currentArchetypeIds: [],

        escapeHtml: function (value) {
            return String(value == null ? '' : value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        },

        reset: function (modal) {
            var root = modal ? window.jQuery(modal) : window.jQuery('#admin-characters-edit-modal');
            if (!root.length) {
                return;
            }

            var tabBtn = root.find('[data-role="admin-edit-tab-archetypes"]');
            var listNode = root.find('[data-role="admin-edit-archetypes-list"]');
            var hintNode = root.find('[data-role="admin-edit-archetypes-hint"]');

            tabBtn.addClass('d-none');
            listNode.empty();
            hintNode.text('');
            this.currentArchetypeIds = [];
        },

        onEditOpen: function (ctx) {
            this.reset(ctx.modal);
        },

        onEditDataLoaded: function (ctx) {
            var self = this;
            var modal = ctx.modal;
            this.reset(modal);

            ctx.requestPost('/admin/archetypes/config/get', {}, function (configResp) {
                var config = (configResp && configResp.dataset) ? configResp.dataset : {};
                var enabled = parseInt(config.archetypes_enabled || 0, 10) === 1;
                if (!enabled) {
                    return;
                }

                var root = modal ? window.jQuery(modal) : window.jQuery('#admin-characters-edit-modal');
                if (!root.length) {
                    return;
                }
                var tabBtn = root.find('[data-role="admin-edit-tab-archetypes"]');
                var listNode = root.find('[data-role="admin-edit-archetypes-list"]');
                var hintNode = root.find('[data-role="admin-edit-archetypes-hint"]');
                tabBtn.removeClass('d-none');

                var multiple = parseInt(config.multiple_archetypes_allowed || 0, 10) === 1;
                hintNode.text(multiple
                    ? 'Puoi assegnare piu archetipi contemporaneamente.'
                    : 'Puoi assegnare un solo archetipo per volta.'
                );

                var available = null;
                var current = null;
                var done = 0;
                function checkDone() {
                    done += 1;
                    if (done !== 2) {
                        return;
                    }
                    self.currentArchetypeIds = (current || []).map(function (row) {
                        return parseInt(row.id, 10);
                    });
                    self.renderList(listNode, config, available || [], self.currentArchetypeIds);
                }

                ctx.requestPost('/admin/archetypes/list', { is_active: 1, is_selectable: 1, results: 100 }, function (listResp) {
                    available = (listResp && Array.isArray(listResp.dataset)) ? listResp.dataset : [];
                    checkDone();
                });

                ctx.requestPost('/admin/archetypes/character/list', { character_id: ctx.characterId }, function (charResp) {
                    current = (charResp && Array.isArray(charResp.dataset)) ? charResp.dataset : [];
                    checkDone();
                });
            });
        },

        renderList: function (listNode, config, available, currentIds) {
            if (!listNode || !listNode.length) {
                return;
            }

            var multiple = parseInt((config && config.multiple_archetypes_allowed) || 0, 10) === 1;
            var inputType = multiple ? 'checkbox' : 'radio';

            if (!available.length) {
                listNode.html('<div class="col-12"><p class="text-muted">Nessun archetipo selezionabile disponibile.</p></div>');
                return;
            }

            var html = '';
            for (var i = 0; i < available.length; i++) {
                var archetype = available[i];
                var id = parseInt(archetype.id, 10);
                var checked = currentIds.indexOf(id) !== -1 ? ' checked' : '';
                var inputId = 'ae-archetype-' + id;
                html += '<div class="col-md-4 col-sm-6">'
                    + '<div class="border rounded p-2 h-100">'
                    + '<div class="form-check">'
                    + '<input class="form-check-input" type="' + inputType + '" name="ae_archetype" id="' + inputId + '" value="' + id + '"' + checked + '>'
                    + '<label class="form-check-label" for="' + inputId + '">' + this.escapeHtml(archetype.name || '') + '</label>'
                    + '</div>'
                    + '</div>'
                    + '</div>';
            }

            listNode.html(html);
        },

        handleEditSave: function (ctx) {
            if (ctx.section !== 'archetypes') {
                return false;
            }

            var modal = ctx.modal;
            if (!modal) {
                return true;
            }

            var archetypeInputs = modal.querySelectorAll('[name="ae_archetype"]:checked');
            var selected = [];
            for (var i = 0; i < archetypeInputs.length; i++) {
                var value = parseInt(archetypeInputs[i].value, 10);
                if (value > 0 && selected.indexOf(value) === -1) {
                    selected.push(value);
                }
            }

            var previousIds = this.currentArchetypeIds.slice();
            var toAssign = [];
            var toRemove = [];

            for (var a = 0; a < selected.length; a++) {
                if (previousIds.indexOf(selected[a]) === -1) {
                    toAssign.push(selected[a]);
                }
            }
            for (var r = 0; r < previousIds.length; r++) {
                if (selected.indexOf(previousIds[r]) === -1) {
                    toRemove.push(previousIds[r]);
                }
            }

            if (toAssign.length === 0 && toRemove.length === 0) {
                ctx.showToast('Nessuna modifica.', 'info');
                return true;
            }

            var self = this;
            var totalOps = toAssign.length + toRemove.length;
            var doneOps = 0;
            function onOpDone() {
                doneOps += 1;
                if (doneOps === totalOps) {
                    self.currentArchetypeIds = selected.slice();
                    ctx.showToast('Archetipi aggiornati.', 'success');
                }
            }

            for (var ia = 0; ia < toAssign.length; ia++) {
                ctx.requestPost('/admin/archetypes/character/assign', {
                    character_id: ctx.characterId,
                    archetype_id: toAssign[ia]
                }, onOpDone);
            }
            for (var ir = 0; ir < toRemove.length; ir++) {
                ctx.requestPost('/admin/archetypes/character/remove', {
                    character_id: ctx.characterId,
                    archetype_id: toRemove[ir]
                }, onOpDone);
            }

            return true;
        }
    };

    function registerWithAdminCharacters(attempt) {
        var tries = parseInt(attempt || 0, 10) || 0;
        if (window.__adminArchetypesCharactersExtensionRegistered === true) {
            return;
        }
        if (window.AdminCharacters && typeof window.AdminCharacters.registerExtension === 'function') {
            window.AdminCharacters.registerExtension(extension);
            window.__adminArchetypesCharactersExtensionRegistered = true;
            return;
        }
        if (tries >= 80) {
            return;
        }
        window.setTimeout(function () {
            registerWithAdminCharacters(tries + 1);
        }, 100);
    }

    registerWithAdminCharacters(0);
})(window);
