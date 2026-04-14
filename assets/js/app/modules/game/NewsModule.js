(function (window) {
    'use strict';

    function parseRows(response) {
        if (!response) { return []; }
        var dataset = response.dataset;
        if (Array.isArray(dataset)) {
            return dataset;
        }
        return [];
    }

    function parseDateMarker(rawValue) {
        var raw = String(rawValue || '').trim();
        if (raw === '') { return 0; }
        var date = new Date(raw.replace(' ', 'T'));
        if (isNaN(date.getTime())) { return 0; }
        return date.getTime();
    }

    function createNewsModule() {
        return {
            ctx: null,
            options: {},
            indicatorPollKey: 'game.news.feed-indicator',
            indicatorPollMs: 45000,
            indicatorStorageKey: 'game.feed.news.seen_marker',
            indicatorLatestMarker: 0,
            onNewsModalShown: null,

            mount: function (ctx, options) {
                this.ctx = ctx || null;
                this.options = options || {};
                this.bindIndicatorEvents();
                this.startIndicatorPoll();
                this.pollIndicator(false);
                return this;
            },

            unmount: function () {
                this.stopIndicatorPoll();
                this.unbindIndicatorEvents();
            },

            widget: function (extension) {
                if (typeof window.GameNewsPage === 'function') {
                    try {
                        return window.GameNewsPage(extension || {});
                    } catch (error) {}
                }

                return null;
            },

            list: function (payload) {
                var query = payload || {
                    cache: true,
                    cache_ttl: 120
                };
                return this.request('/list/news', 'getNews', query);
            },

            readSeenMarker: function () {
                if (!window.localStorage) { return 0; }
                var raw = window.localStorage.getItem(this.indicatorStorageKey);
                var value = parseInt(raw || '0', 10);
                return value > 0 ? value : 0;
            },

            writeSeenMarker: function (value) {
                if (!window.localStorage) { return; }
                var marker = parseInt(value || '0', 10);
                if (marker > 0) {
                    window.localStorage.setItem(this.indicatorStorageKey, String(marker));
                }
            },

            extractRowMarker: function (row) {
                var idMarker = parseInt((row && row.id) || '0', 10);
                if (idMarker > 0) {
                    return idMarker;
                }
                return parseDateMarker((row && (row.date_publish || row.date_published || row.date_created)) || '');
            },

            latestMarkerFromRows: function (rows) {
                var latest = 0;
                var list = Array.isArray(rows) ? rows : [];
                for (var i = 0; i < list.length; i += 1) {
                    var marker = this.extractRowMarker(list[i]);
                    if (marker > latest) {
                        latest = marker;
                    }
                }
                return latest;
            },

            countNewRows: function (rows, seenMarker) {
                var count = 0;
                var list = Array.isArray(rows) ? rows : [];
                for (var i = 0; i < list.length; i += 1) {
                    if (this.extractRowMarker(list[i]) > seenMarker) {
                        count += 1;
                    }
                }
                return count;
            },

            setIndicatorBadge: function (count) {
                var safeCount = parseInt(count || '0', 10);
                if (safeCount < 0) { safeCount = 0; }
                var labels = document.querySelectorAll('[data-feed-badge="news"]');
                for (var i = 0; i < labels.length; i += 1) {
                    var label = labels[i];
                    if (safeCount > 0) {
                        label.textContent = safeCount > 99 ? '99+' : String(safeCount);
                        label.classList.remove('d-none');
                        label.classList.add('feed-badge-pulse');
                    } else {
                        label.textContent = '0';
                        label.classList.remove('feed-badge-pulse');
                        label.classList.add('d-none');
                    }
                }
            },

            processIndicatorRows: function (rows, markSeen) {
                var latest = this.latestMarkerFromRows(rows);
                if (latest > this.indicatorLatestMarker) {
                    this.indicatorLatestMarker = latest;
                }

                var seen = this.readSeenMarker();
                if (seen <= 0 && latest > 0) {
                    seen = latest;
                    this.writeSeenMarker(latest);
                }

                if (markSeen === true && latest > 0) {
                    seen = latest;
                    this.writeSeenMarker(latest);
                }

                var newCount = 0;
                if (markSeen !== true && latest > seen) {
                    newCount = this.countNewRows(rows, seen);
                }
                this.setIndicatorBadge(newCount);
            },

            pollIndicator: function (markSeen) {
                var self = this;
                this.list({
                    cache: true,
                    cache_ttl: 120,
                    results: 20,
                    page: 1
                }).then(function (response) {
                    self.processIndicatorRows(parseRows(response), markSeen === true);
                }).catch(function () {});
            },

            startIndicatorPoll: function () {
                var manager = window.AppLifecycle && typeof window.AppLifecycle.getPollManager === 'function'
                    ? window.AppLifecycle.getPollManager()
                    : null;
                if (!manager || typeof manager.start !== 'function') {
                    return;
                }
                var self = this;
                manager.start(this.indicatorPollKey, function () {
                    self.pollIndicator(false);
                }, this.indicatorPollMs);
            },

            stopIndicatorPoll: function () {
                var manager = window.AppLifecycle && typeof window.AppLifecycle.getPollManager === 'function'
                    ? window.AppLifecycle.getPollManager()
                    : null;
                if (!manager || typeof manager.stop !== 'function') {
                    return;
                }
                manager.stop(this.indicatorPollKey);
            },

            bindIndicatorEvents: function () {
                var modal = document.getElementById('news-modal');
                if (!modal) {
                    return;
                }
                var self = this;
                if (!this.onNewsModalShown) {
                    this.onNewsModalShown = function () {
                        self.pollIndicator(true);
                    };
                }
                modal.addEventListener('shown.bs.modal', this.onNewsModalShown);
            },

            unbindIndicatorEvents: function () {
                var modal = document.getElementById('news-modal');
                if (!modal || !this.onNewsModalShown) {
                    return;
                }
                modal.removeEventListener('shown.bs.modal', this.onNewsModalShown);
                this.onNewsModalShown = null;
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

    window.GameNewsModuleFactory = createNewsModule;
})(window);
