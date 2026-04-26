(function (window) {
    'use strict';

    function resolveHttp() {
        if (window.Request && window.Request.http && typeof window.Request.http.post === 'function') {
            return window.Request.http;
        }
        return null;
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function ensureRow(roleList) {
        if (!roleList || !roleList.length) {
            return $();
        }

        var existing = roleList.find('[data-role="location-profile-archetype-cell"]');
        if (existing.length) {
            return existing;
        }

        roleList.prepend(
            '<li class="list-group-item d-flex justify-content-between align-items-center" data-role="location-profile-archetype-row">'
            + '<span>Archetipo</span>'
            + '<span class="badge rounded-pill" data-role="location-profile-archetype-cell">...</span>'
            + '</li>'
        );

        return roleList.find('[data-role="location-profile-archetype-cell"]');
    }

    function bindLocationRoleHook() {
        if (typeof window.jQuery !== 'function') {
            return;
        }
        if (window.__archetypesLocationRoleHookBound === true) {
            return;
        }

        window.__archetypesLocationRoleHookBound = true;
        window.jQuery(document).on('location.profile.roles.rendered', function (_event, payload) {
            var data = (payload && typeof payload === 'object') ? payload : {};
            var roleList = data.roleList && data.roleList.length ? data.roleList : window.jQuery('#location-profile-role-list');
            if (!roleList || !roleList.length) {
                return;
            }

            var cell = ensureRow(roleList);
            if (!cell.length) {
                return;
            }
            cell.text('...');

            var http = resolveHttp();
            if (!http) {
                cell.text('-');
                return;
            }

            http.post('/archetypes/my', {}).then(function (response) {
                var list = (response && Array.isArray(response.dataset)) ? response.dataset : [];
                var label = list.length > 0
                    ? list.map(function (archetype) {
                        return escapeHtml(archetype && (archetype.name || archetype.slug || 'Archetipo'));
                    }).join(', ')
                    : '-';
                cell.html(label);
            }).catch(function () {
                cell.text('-');
            });
        });
    }

    function GameArchetypesLocationSidebarPage() {
        var page = {
            init: function () {
                bindLocationRoleHook();
                return this;
            },
            destroy: function () {
                return this;
            },
            unmount: function () {
                return this.destroy();
            }
        };

        return page.init();
    }

    window.GameArchetypesLocationSidebarPage = GameArchetypesLocationSidebarPage;
})(window);
