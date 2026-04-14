function DocsRender(containerSelector, options) {
    var registry = (typeof window !== 'undefined')
        ? (window.__docs_render_instances = window.__docs_render_instances || {})
        : {};

    function normalizeSelector(value) {
        return String(value || '').trim();
    }

    function sanitizePrefix(value, fallback) {
        var raw = String(value || '').trim();
        if (!raw) {
            raw = String(fallback || 'docs').trim();
        }
        raw = raw.replace(/^#+/, '').replace(/[^a-z0-9_-]+/gi, '-').replace(/-+/g, '-');
        return raw || 'docs';
    }

    function resolveRequestApi() {
        var requestApi = (typeof window !== 'undefined' && window.Request)
            ? window.Request
            : ((typeof Request !== 'undefined') ? Request : null);

        if (!requestApi || !requestApi.http || typeof requestApi.http.post !== 'function') {
            return null;
        }
        return requestApi;
    }

    function getRequestUnavailableMessage() {
        var requestApi = resolveRequestApi();
        if (requestApi && typeof requestApi.getUnavailableMessage === 'function') {
            return requestApi.getUnavailableMessage();
        }
        return 'Servizio comunicazione non disponibile. Ricarica la pagina e riprova.';
    }

    function getErrorMessage(error, fallback) {
        var requestApi = resolveRequestApi();
        if (requestApi && typeof requestApi.getErrorMessage === 'function') {
            return requestApi.getErrorMessage(error, fallback || 'Impossibile caricare il contenuto.');
        }
        if (typeof error === 'string' && error.trim() !== '') {
            return error.trim();
        }
        if (error && typeof error.message === 'string' && error.message.trim() !== '') {
            return error.message.trim();
        }
        return fallback || 'Impossibile caricare il contenuto.';
    }

    function createSettings(selector, opts) {
        var base = {
            url: null,
            prefix: null,
            label: 'Capitolo',
            subLabel: 'Sottocapitolo',
            emptyText: 'Nessun contenuto disponibile.',
            loadingText: 'Caricamento contenuti...',
            errorText: 'Impossibile caricare il contenuto.',
            cacheResponse: true,
            requestPayload: {}
        };
        var merged = Object.assign({}, base, opts || {});
        merged.prefix = sanitizePrefix(merged.prefix, selector);
        if (!merged.requestPayload || typeof merged.requestPayload !== 'object') {
            merged.requestPayload = {};
        }
        return merged;
    }

    function createInstance(selector, opts) {
        var instance = {
            selector: normalizeSelector(selector),
            settings: createSettings(selector, opts),
            mounted: false,
            destroyed: false,
            loading: false,
            loaded: false,
            requestId: 0,
            responseCache: null,
            $container: null,
            $nav: null,
            $content: null,
            $filter: null,
            _filterNs: null,

            reconfigure: function (nextOptions) {
                if (nextOptions && typeof nextOptions === 'object') {
                    var current = this.settings || createSettings(this.selector, {});
                    var merged = Object.assign({}, current, nextOptions);
                    if (current.requestPayload && typeof current.requestPayload === 'object' && nextOptions.requestPayload && typeof nextOptions.requestPayload === 'object') {
                        merged.requestPayload = Object.assign({}, current.requestPayload, nextOptions.requestPayload);
                    }
                    merged.prefix = sanitizePrefix(merged.prefix, this.selector);
                    this.settings = merged;
                }
                return this;
            },

            normalize: function (value) {
                return (value || '').toString().toLowerCase().trim();
            },

            mount: function () {
                if (this.destroyed === true) {
                    this.destroyed = false;
                }

                if (!this.selector) {
                    this.mounted = false;
                    return this;
                }

                if (typeof window.$ !== 'function') {
                    this.mounted = false;
                    return this;
                }

                this.$container = $(this.selector).first();
                if (!this.$container.length) {
                    this.mounted = false;
                    this.$nav = null;
                    this.$content = null;
                    this.$filter = null;
                    return this;
                }

                this.$nav = this.$container.find('[data-role="docs-nav"]').first();
                this.$content = this.$container.find('[data-role="docs-content"]').first();
                this.$filter = this.$container.find('[data-role="docs-filter"]').first();
                if (!this.$nav.length || !this.$content.length) {
                    this.mounted = false;
                    return this;
                }

                this._filterNs = '.docsrender_' + sanitizePrefix(this.selector, this.settings.prefix || 'docs');
                this.bindFilter();
                this.mounted = true;
                return this;
            },

            bindFilter: function () {
                if (!this.$filter || !this.$filter.length) {
                    return this;
                }
                var self = this;
                var ns = this._filterNs || '.docsrender';
                this.$filter.off('input' + ns);
                this.$filter.on('input' + ns, function () {
                    self.applyFilter();
                });
                return this;
            },

            unbindFilter: function () {
                if (this.$filter && this.$filter.length) {
                    var ns = this._filterNs || '.docsrender';
                    this.$filter.off('input' + ns);
                }
                return this;
            },

            setLoading: function (isLoading) {
                this.loading = !!isLoading;
                if (!this.$content || !this.$content.length) {
                    return this;
                }
                if (this.loading && !this.loaded) {
                    this.$content.html('<div class="text-muted py-3">' + this.settings.loadingText + '</div>');
                }
                return this;
            },

            showError: function (message) {
                if (this.$nav && this.$nav.length) {
                    this.$nav.empty();
                }
                if (this.$content && this.$content.length) {
                    this.$content.html('<div class="text-danger py-3">' + message + '</div>');
                }
                return this;
            },

            activateFirstVisibleTab: function () {
                if (!this.$nav || !this.$nav.length) {
                    return this;
                }

                var $visibleButtons = this.$nav
                    .find('.nav-item:not(.d-none) button.nav-link')
                    .filter(function () {
                        return !this.hasAttribute('hidden') && this.getAttribute('aria-disabled') !== 'true';
                    });

                if (!$visibleButtons.length) {
                    return this;
                }

                var $activeVisible = $visibleButtons.filter('.active').first();
                if ($activeVisible.length) {
                    return this;
                }

                var firstVisible = $visibleButtons.first()[0];
                if (!firstVisible) {
                    return this;
                }

                if (typeof window.bootstrap !== 'undefined' && window.bootstrap && window.bootstrap.Tab) {
                    try {
                        var tab = (typeof window.bootstrap.Tab.getOrCreateInstance === 'function')
                            ? window.bootstrap.Tab.getOrCreateInstance(firstVisible)
                            : new window.bootstrap.Tab(firstVisible);
                        if (tab && typeof tab.show === 'function') {
                            tab.show();
                            return this;
                        }
                    } catch (error) {
                        // fallback below
                    }
                }

                var targetSelector = String(firstVisible.getAttribute('data-bs-target') || '').trim();
                this.$nav.find('button.nav-link.active').removeClass('active').attr('aria-selected', 'false');
                $(firstVisible).addClass('active').attr('aria-selected', 'true');
                if (targetSelector && this.$content && this.$content.length) {
                    this.$content.find('.tab-pane').removeClass('show active');
                    this.$content.find(targetSelector).addClass('show active');
                }

                return this;
            },

            applyFilter: function () {
                if (!this.$filter || !this.$filter.length || !this.$nav || !this.$nav.length) {
                    return this;
                }

                var query = this.normalize(this.$filter.val());
                var $items = this.$nav.find('.nav-item');

                $items.each(function () {
                    var $item = $(this);
                    var $button = $item.find('button.nav-link').first();
                    var label = (($button.attr('data-doc-label') || $button.text()) || '').toString().toLowerCase().trim();
                    var visible = (query === '' || label.indexOf(query) !== -1);
                    $item.toggleClass('d-none', !visible);
                });

                this.activateFirstVisibleTab();
                return this;
            },

            renderTabs: function (response) {
                var chapters = (response && response.chapters) ? response.chapters : [];
                this.responseCache = response || { chapters: [] };
                this.loaded = true;

                if (!this.$nav || !this.$nav.length || !this.$content || !this.$content.length) {
                    return this;
                }

                this.$nav.empty();
                this.$content.empty();

                if (!chapters.length) {
                    this.$content.html('<div class="text-muted py-3">' + this.settings.emptyText + '</div>');
                    return this;
                }

                var prefix = sanitizePrefix(this.settings.prefix, this.selector);
                for (var i = 0; i < chapters.length; i++) {
                    var chapter = chapters[i] || {};
                    var isActive = (i === 0);
                    var chapterKey = (chapter.chapter != null) ? chapter.chapter : (i + 1);
                    var chapterId = prefix + '-chapter-' + String(chapterKey).replace(/[^a-z0-9_-]+/gi, '-');
                    var label = chapter.label || (this.settings.label + ' ' + chapterKey);
                    if (!chapter.label && chapter.title) {
                        label += ' - ' + chapter.title;
                    }

                    var $navItem = $('<li class="nav-item" role="presentation"></li>');
                    var $button = $('<button class="nav-link" type="button" role="tab"></button>');
                    if (isActive) {
                        $button.addClass('active').attr('aria-selected', 'true');
                    } else {
                        $button.attr('aria-selected', 'false');
                    }
                    $button.attr({
                        id: chapterId + '-tab',
                        'data-bs-toggle': 'tab',
                        'data-bs-target': '#' + chapterId + '-pane',
                        'aria-controls': chapterId + '-pane'
                    });
                    $button.attr('data-doc-label', label);
                    $button.text(label);
                    $navItem.append($button);
                    this.$nav.append($navItem);

                    var $pane = $('<div class="tab-pane fade p-1"></div>');
                    if (isActive) {
                        $pane.addClass('show active');
                    }
                    $pane.attr({
                        id: chapterId + '-pane',
                        role: 'tabpanel',
                        'aria-labelledby': chapterId + '-tab',
                        tabindex: i
                    });

                    $pane.append($('<h5></h5>').text(label));

                    if (chapter.body) {
                        $pane.append($('<div class="p-2"></div>').html(chapter.body));
                    }

                    if (Array.isArray(chapter.subchapters) && chapter.subchapters.length) {
                        for (var s = 0; s < chapter.subchapters.length; s++) {
                            var sub = chapter.subchapters[s] || {};
                            var subKey = (sub.subchapter != null) ? sub.subchapter : (s + 1);
                            var subLabel = this.settings.subLabel + ' ' + chapterKey + '.' + subKey;
                            if (sub.title) {
                                subLabel += ' - ' + sub.title;
                            }
                            var $block = $('<div class="mt-4"></div>');
                            $block.append($('<h6 class="fw-semibold"></h6>').text(subLabel));
                            if (sub.body) {
                                $block.append($('<div class="p-2"></div>').html(sub.body));
                            }
                            $pane.append($block);
                        }
                    }

                    if (!chapter.body && (!Array.isArray(chapter.subchapters) || chapter.subchapters.length === 0)) {
                        $pane.append('<div class="text-muted py-3">' + this.settings.emptyText + '</div>');
                    }

                    this.$content.append($pane);
                }

                this.applyFilter();
                return this;
            },

            load: function (opts) {
                var cfg = (opts && typeof opts === 'object') ? opts : {};
                var force = cfg.force === true;
                var payload = (cfg.payload && typeof cfg.payload === 'object')
                    ? cfg.payload
                    : (this.settings.requestPayload || {});

                this.mount();
                if (!this.mounted) {
                    return Promise.resolve(this);
                }

                if (!this.settings.url) {
                    this.showError(this.settings.errorText);
                    return Promise.resolve(this);
                }

                if (!force && this.loaded && this.settings.cacheResponse !== false && this.responseCache) {
                    this.renderTabs(this.responseCache);
                    return Promise.resolve(this);
                }

                var requestApi = resolveRequestApi();
                if (!requestApi) {
                    this.showError(getRequestUnavailableMessage());
                    return Promise.resolve(this);
                }

                var self = this;
                this.requestId += 1;
                var currentRequestId = this.requestId;
                this.setLoading(true);

                return requestApi.http.post(this.settings.url, payload).then(function (response) {
                    if (self.destroyed === true || currentRequestId !== self.requestId) {
                        return self;
                    }
                    self.setLoading(false);
                    self.renderTabs(response);
                    return self;
                }).catch(function (error) {
                    if (self.destroyed === true || currentRequestId !== self.requestId) {
                        return self;
                    }
                    self.setLoading(false);
                    self.showError(getErrorMessage(error, self.settings.errorText));
                    return self;
                });
            },

            refresh: function () {
                return this.load({ force: true });
            },

            destroy: function () {
                this.unbindFilter();
                this.$nav = null;
                this.$content = null;
                this.$filter = null;
                this.$container = null;
                this.mounted = false;
                this.destroyed = true;
                this.loading = false;
                this.requestId += 1;
                if (registry && this.selector && registry[this.selector] === this) {
                    delete registry[this.selector];
                }
                return this;
            },

            unmount: function () {
                this.unbindFilter();
                this.$nav = null;
                this.$content = null;
                this.$filter = null;
                this.$container = null;
                this.mounted = false;
                return this;
            }
        };

        return instance;
    }

    var selector = normalizeSelector(containerSelector);
    if (!selector) {
        return null;
    }

    var instance = registry[selector];
    if (!instance || typeof instance !== 'object') {
        instance = createInstance(selector, options || {});
        registry[selector] = instance;
    } else {
        instance.reconfigure(options || {});
    }

    instance.mount();
    instance.load();
    return instance;
}

if (typeof window !== 'undefined') {
    window.DocsRender = DocsRender;
}
