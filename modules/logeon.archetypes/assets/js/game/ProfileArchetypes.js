(function (window) {
    'use strict';

    function resolveHttp() {
        if (window.Request && window.Request.http && typeof window.Request.http.post === 'function') {
            return window.Request.http;
        }
        return null;
    }

    function renderArchetypes(list, archetypeItem, iconsContainer, labelNode) {
        var rows = Array.isArray(list) ? list : [];
        if (!rows.length) {
            return;
        }

        if (labelNode && labelNode.length) {
            labelNode.text(rows.length > 1 ? 'Archetipi' : 'Archetipo');
        }

        iconsContainer.empty();
        var hasContent = false;
        for (var i = 0; i < rows.length; i++) {
            var archetype = rows[i];
            if (!archetype) {
                continue;
            }

            var name = archetype.name ? String(archetype.name).trim() : '';
            var icon = archetype.icon ? String(archetype.icon).trim() : '';
            if (icon !== '') {
                hasContent = true;
                var img = $('<img width="32" height="32" class="rounded" data-bs-toggle="tooltip" alt="">');
                img.attr('src', icon);
                img.attr('data-bs-title', name || 'Archetipo');
                iconsContainer.append(img);
                continue;
            }

            if (name !== '') {
                hasContent = true;
                iconsContainer.append($('<span class="badge text-bg-secondary"></span>').text(name));
            }
        }

        if (!hasContent) {
            return;
        }

        if (typeof window.initTooltips === 'function' && iconsContainer.length) {
            window.initTooltips(iconsContainer[0]);
        }
        archetypeItem.show();
    }

    function GameArchetypesProfilePage() {
        var page = {
            init: function () {
                var root = $('#profile-page');
                if (!root.length) {
                    return this;
                }

                var archetypeItem = root.find('[data-role="profile-archetypes-item"]');
                if (!archetypeItem.length) {
                    return this;
                }

                var iconsContainer = archetypeItem.find('[data-role="profile-archetypes-icons"]');
                if (!iconsContainer.length) {
                    return this;
                }

                var labelNode = archetypeItem.find('[data-role="profile-archetypes-label"]');
                var http = resolveHttp();
                if (!http) {
                    return this;
                }

                http.post('/archetypes/my', {}).then(function (response) {
                    var list = (response && Array.isArray(response.dataset)) ? response.dataset : [];
                    renderArchetypes(list, archetypeItem, iconsContainer, labelNode);
                }).catch(function () {});

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

    window.GameArchetypesProfilePage = GameArchetypesProfilePage;
})(window);
