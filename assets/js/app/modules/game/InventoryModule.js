(function (window) {
    'use strict';

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function isValidHexColor(value) {
        return /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(String(value || '').trim());
    }

    function toInt(value, fallback) {
        var num = parseInt(value, 10);
        if (isNaN(num)) {
            return fallback;
        }
        return num;
    }

    function buildNarrativeBadges(response) {
        var badges = [];
        if (toInt(response.usable, 0) === 1) {
            badges.push('<span class="badge text-bg-success">Uso</span>');
        }
        var cooldown = toInt(response.cooldown, 0);
        if (cooldown > 0) {
            badges.push('<span class="badge text-bg-secondary">CD ' + escapeHtml(String(cooldown)) + 's</span>');
        }
        var appliesState = String(response.applies_state_name || '').trim();
        var removesState = String(response.removes_state_name || '').trim();
        if (appliesState !== '') {
            badges.push('<span class="badge text-bg-info">Applica: ' + escapeHtml(appliesState) + '</span>');
        }
        if (removesState !== '') {
            badges.push('<span class="badge text-bg-warning">Rimuove: ' + escapeHtml(removesState) + '</span>');
        }
        return badges;
    }

    function buildBagGridConfig() {
        return {
            name: 'Bags',
            autoindex: 'id',
            orderable: false,
            thead: false,
            handler: {
                url: '/list/profile/bag',
                action: 'list'
            },
            nav: {
                display: 'bottom',
                urlupdate: 1,
                results: 10,
                page: 1
            },
            columns: [
                {
                    label: '',
                    sortable: false,
                    style: {
                        textAlign: 'left'
                    },
                    format: function (response) {
                        var image = (response.item_image && response.item_image !== '') ? response.item_image : '/assets/imgs/defaults-images/default-location.png';
                        var name = response.item_name || 'Senza nome';
                        var description = response.item_description || '';
                        var qty = (response.quantity != null) ? response.quantity : 1;
                        var equipped = (parseInt(response.is_equipped, 10) === 1) ? '<span class="badge text-bg-success ms-2">Equipaggiato</span>' : '';
                        var actions = [];
                        var instanceId = response.character_item_instance_id || null;
                        var stackId = response.character_item_id || null;
                        var canDrop = (parseInt(response.droppable, 10) === 1);
                        var safeName = escapeHtml(name);
                        var rarityName = (response.rarity_name || '').toString().trim();
                        var rarityColor = (response.rarity_color || '').toString().trim();
                        var rarityBadge = '';
                        var narrativeBadges = buildNarrativeBadges(response);
                        var narrativeHtml = '';

                        if (rarityName !== '') {
                            var badgeStyle = '';
                            if (isValidHexColor(rarityColor)) {
                                badgeStyle = ' style="background-color:' + rarityColor + ';border:1px solid ' + rarityColor + ';color:#fff;"';
                            }
                            rarityBadge = '<span class="badge ms-2"' + badgeStyle + '>' + escapeHtml(rarityName) + '</span>';
                        }
                        if (narrativeBadges.length) {
                            narrativeHtml = '<div class="d-flex flex-wrap gap-1 mt-2">' + narrativeBadges.join(' ') + '</div>';
                        }

                        if (instanceId && parseInt(response.is_equipped, 10) !== 1) {
                            if (canDrop) {
                                actions.push('<button type="button" class="btn btn-sm btn-outline-danger" data-action="drop" data-source="instance" data-instance-id="' + instanceId + '" data-item-name="' + safeName + '">Lascia</button>');
                            }
                            actions.push('<button type="button" class="btn btn-sm btn-outline-dark" data-action="destroy" data-source="instance" data-instance-id="' + instanceId + '" data-item-name="' + safeName + '" data-quantity="1">Distruggi</button>');
                        } else if (stackId) {
                            if (canDrop) {
                                actions.push('<button type="button" class="btn btn-sm btn-outline-danger" data-action="drop" data-source="stack" data-character-item-id="' + stackId + '" data-quantity="' + qty + '" data-item-name="' + safeName + '">Lascia</button>');
                            }
                            actions.push('<button type="button" class="btn btn-sm btn-outline-dark" data-action="destroy" data-source="stack" data-character-item-id="' + stackId + '" data-quantity="' + qty + '" data-item-name="' + safeName + '">Distruggi</button>');
                        }

                        var actionsBlock = actions.length ? '<div class="d-inline-flex align-items-center justify-content-end flex-wrap gap-2">' + actions.join('') + '</div>' : '';
                        return ''
                            + '<div class="card mb-2">'
                            + '  <div class="card-body d-flex gap-3 align-items-center flex-wrap">'
                            + '    <img class="rounded" width="64" height="64" src="' + image + '" alt="">'
                            + '    <div class="flex-grow-1">'
                            + '      <div class="d-flex align-items-center flex-wrap gap-2">'
                            + '        <h6 class="mb-0">' + escapeHtml(name) + '</h6>'
                            +          equipped
                            +          rarityBadge
                            + '      </div>'
                            + '      <div class="text-muted small">' + escapeHtml(description) + '</div>'
                            +        narrativeHtml
                            + '    </div>'
                            + '    <div class="text-end d-flex flex-column align-items-end gap-2">'
                            + '      <span class="badge text-bg-dark">x' + qty + '</span>'
                            +        (actionsBlock ? actionsBlock : '')
                            + '    </div>'
                            + '  </div>'
                            + '</div>';
                    }
                }
            ]
        };
    }

    function createInventoryModule() {
        return {
            ctx: null,
            options: {},

            mount: function (ctx, options) {
                this.ctx = ctx || null;
                this.options = options || {};
                return this;
            },

            unmount: function () {},

            getBag: function (payload) {
                return this.request('/get/bag', 'getBag', payload || {});
            },

            categories: function (payload, action) {
                return this.request('/inventory/categories', action || 'getInventoryCategories', payload || null);
            },

            slots: function (payload, action) {
                return this.request('/inventory/slots', action || 'getInventorySlots', payload || null);
            },

            bagItems: function (payload, action) {
                return this.request('/list/profile/bag', action || 'getBagItems', payload || {});
            },

            bagGridConfig: function () {
                return buildBagGridConfig();
            },

            equip: function (payload) {
                return this.request('/inventory/equip', 'equipItem', payload || {});
            },

            unequip: function (payload) {
                return this.request('/inventory/unequip', 'unequipItem', payload || {});
            },

            swap: function (payload) {
                return this.request('/inventory/swap', 'swapEquipment', payload || {});
            },

            drop: function (payload) {
                return this.request('/location/drops/drop', 'dropItem', payload || {});
            },

            useItem: function (payload) {
                return this.request('/items/use', 'useInventoryItem', payload || {});
            },

            reloadItem: function (payload) {
                return this.request('/items/reload', 'reloadInventoryItem', payload || {});
            },

            maintainItem: function (payload) {
                return this.request('/inventory/maintenance', 'maintainInventoryItem', payload || {});
            },

            destroy: function (payload) {
                return this.request('/inventory/destroy', 'destroyItem', payload || {});
            },

            equipped: function (payload, action) {
                return this.request('/inventory/equipped', action || 'getEquipped', payload || null);
            },

            available: function (payload, action) {
                return this.request('/inventory/available', action || 'getAvailable', payload || null);
            },

            request: function (url, action, payload) {
                if (!this.ctx || !this.ctx.services || !this.ctx.services.http) {
                    return Promise.reject(new Error('HTTP service not available.'));
                }

                return this.ctx.services.http.request({
                    url: url,
                    action: action,
                    payload: payload || {}
                });
            }
        };
    }

    window.GameInventoryModuleFactory = createInventoryModule;
})(window);
