(function () {
    'use strict';

    function GameArchetypesDocsPageFactory() {
        return {
            mount: function () {
                var el = document.getElementById('archetypes-docs-page');
                if (!el || typeof window.DocsRender !== 'function') { return; }

                var viewMode = (el.dataset && el.dataset.viewMode) ? el.dataset.viewMode : 'navigation';

                window.archetypesDocsRenderer = window.DocsRender('#archetypes-docs-page', {
                    url: '/archetypes/docs/list',
                    prefix: 'archetypes-docs-page',
                    label: 'Archetipo',
                    subLabel: 'Approfondimento',
                    emptyText: 'Nessun archetipo disponibile.',
                    viewMode: viewMode
                });
                window.archetypesDocsRenderer.load();
            }
        };
    }

    window.GameArchetypesDocsPageFactory = GameArchetypesDocsPageFactory;
})();
