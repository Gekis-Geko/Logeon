(function () {
    function isPlainObject(value) {
        return Object.prototype.toString.call(value) === '[object Object]';
    }

    function mergeConfig(base, extra) {
        var out = {};
        var key;

        for (key in base) {
            if (!Object.prototype.hasOwnProperty.call(base, key)) {
                continue;
            }
            if (isPlainObject(base[key])) {
                out[key] = mergeConfig(base[key], {});
            } else if (Array.isArray(base[key])) {
                out[key] = base[key].slice();
            } else {
                out[key] = base[key];
            }
        }

        if (!isPlainObject(extra)) {
            return out;
        }

        for (key in extra) {
            if (!Object.prototype.hasOwnProperty.call(extra, key)) {
                continue;
            }

            if (isPlainObject(extra[key]) && isPlainObject(out[key])) {
                out[key] = mergeConfig(out[key], extra[key]);
                continue;
            }

            if (Array.isArray(extra[key])) {
                out[key] = extra[key].slice();
                continue;
            }

            out[key] = extra[key];
        }

        return out;
    }

    function getByPath(obj, path, fallback) {
        if (!obj || !path) {
            return fallback;
        }

        var parts = String(path).split('.');
        var current = obj;
        for (var i = 0; i < parts.length; i++) {
            var part = parts[i];
            if (current === null || typeof current === 'undefined') {
                return fallback;
            }
            current = current[part];
        }

        return (typeof current === 'undefined') ? fallback : current;
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function requestUnavailableMessage() {
        if (typeof window !== 'undefined' && window.Request && typeof window.Request.getUnavailableMessage === 'function') {
            return window.Request.getUnavailableMessage();
        }
        return 'Servizio comunicazione non disponibile. Ricarica la pagina e riprova.';
    }

    function requestErrorMessage(error, fallback) {
        if (typeof window !== 'undefined' && window.Request && typeof window.Request.getErrorMessage === 'function') {
            return window.Request.getErrorMessage(error, fallback || 'Operazione non riuscita.');
        }
        if (typeof error === 'string' && error.trim() !== '') {
            return error.trim();
        }
        if (error && typeof error.message === 'string' && error.message.trim() !== '') {
            return error.message.trim();
        }
        return fallback || 'Operazione non riuscita.';
    }

    var defaultConfig = {
        rootSelector: '#admin-page [data-admin-page="dashboard"]',
        rootSelectors: [],
        selectors: {
            periodSelect: '#admin-dashboard-period',
            refreshBtn: '#admin-dashboard-refresh',
            kpiAttr: 'data-admin-kpi',
            compareAttr: 'data-admin-compare',
            periodLabelAttr: 'data-admin-period-label',
            periodShortLabelAttr: 'data-admin-period-label-short'
        },
        period: {
            param: 'period',
            default: '30d',
            allowed: ['7d', '30d', '90d'],
            labels: {
                '7d': '7 giorni',
                '30d': '30 giorni',
                '90d': '90 giorni'
            },
            shortLabels: {
                '7d': '7g',
                '30d': '30g',
                '90d': '90g'
            }
        },
        request: {
            url: '/admin/dashboard/summary',
            action: 'boDashboardSummary',
            payloadBuilder: null,
            responseMapper: null
        },
        bindings: {
            kpis: [
                { key: 'users_total', path: 'kpi.users_total', fallback: 0 },
                { key: 'characters_total', path: 'kpi.characters_total', fallback: 0 },
                { key: 'users_new_period', path: 'kpi.users_new_period', fallback: 0 },
                { key: 'characters_new_period', path: 'kpi.characters_new_period', fallback: 0 },
                { key: 'location_messages_period', path: 'kpi.location_messages_period', fallback: 0 },
                { key: 'dm_messages_period', path: 'kpi.dm_messages_period', fallback: 0 },
                { key: 'forum_threads_period', path: 'kpi.forum_threads_period', fallback: 0 },
                { key: 'availability_free', path: 'distributions.availability.free', fallback: 0 },
                { key: 'availability_busy', path: 'distributions.availability.busy', fallback: 0 },
                { key: 'availability_away', path: 'distributions.availability.away', fallback: 0 },
                { key: 'availability_other', path: 'distributions.availability.other', fallback: 0 }
            ],
            compares: [
                { key: 'users_new_period', path: 'compare.users_new_period', fallback: null },
                { key: 'characters_new_period', path: 'compare.characters_new_period', fallback: null },
                { key: 'location_messages_period', path: 'compare.location_messages_period', fallback: null },
                { key: 'dm_messages_period', path: 'compare.dm_messages_period', fallback: null },
                { key: 'forum_threads_period', path: 'compare.forum_threads_period', fallback: null }
            ],
            charts: [
                {
                    key: 'activity',
                    selector: '#admin-dashboard-activity-chart',
                    labelsPath: 'timeseries.labels',
                    series: [
                        { label: 'Luoghi', color: '#38BDF8', path: 'timeseries.location_messages_daily' },
                        { label: 'DM', color: '#8B5CF6', path: 'timeseries.dm_messages_daily' },
                        { label: 'Forum', color: '#F97316', path: 'timeseries.forum_threads_daily' }
                    ]
                },
                {
                    key: 'registrations',
                    selector: '#admin-dashboard-registration-chart',
                    labelsPath: 'timeseries.labels',
                    series: [
                        { label: 'Utenti', color: '#22C55E', path: 'timeseries.users_daily' },
                        { label: 'Personaggi', color: '#F59E0B', path: 'timeseries.characters_daily' }
                    ]
                }
            ],
            topLocations: {
                key: 'topLocations',
                selector: '#admin-dashboard-top-locations',
                path: 'distributions.top_locations'
            },
            recentLogs: {
                key: 'recentLogs',
                selector: '#admin-dashboard-recent-logs',
                path: 'recent.logs'
            },
            customApply: null
        },
        visibility: {
            hiddenSelectors: [],
            hiddenKpis: [],
            hiddenCompares: [],
            hiddenSections: []
        },
        hooks: {
            beforeLoad: null,
            afterLoad: null,
            onError: null
        }
    };

    var Dashboard = {
        initialized: false,
        root: null,
        periodSelect: null,
        refreshBtn: null,
        config: mergeConfig(defaultConfig, {}),

        configure: function (options) {
            this.config = mergeConfig(this.config || defaultConfig, options || {});
            return this;
        },

        resetConfig: function () {
            this.config = mergeConfig(defaultConfig, {});
            return this;
        },

        init: function (options) {
            if (options) {
                this.configure(options);
            }

            if (this.initialized) {
                return this;
            }

            this.root = this.resolveRoot();
            if (!this.root) {
                return this;
            }

            this.periodSelect = this.findOne(this.config.selectors.periodSelect);
            this.refreshBtn = this.findOne(this.config.selectors.refreshBtn);
            if (!this.periodSelect) {
                return this;
            }

            var period = this.getPeriodFromUrl();
            this.periodSelect.value = period;
            this.applyPeriodLabels(period);
            this.applyVisibility();

            var self = this;
            this.periodSelect.addEventListener('change', function () {
                self.load();
            });

            if (this.refreshBtn) {
                this.refreshBtn.addEventListener('click', function () {
                    self.load();
                });
            }

            this.initialized = true;
            this.load();

            return this;
        },

        resolveRoot: function () {
            var selectors = [];
            if (this.config.rootSelector) {
                selectors.push(this.config.rootSelector);
            }
            if (Array.isArray(this.config.rootSelectors)) {
                selectors = selectors.concat(this.config.rootSelectors);
            }

            for (var i = 0; i < selectors.length; i++) {
                var selector = selectors[i];
                if (!selector) {
                    continue;
                }
                var node = document.querySelector(selector);
                if (node) {
                    return node;
                }
            }

            return null;
        },

        findOne: function (selector) {
            if (!selector) {
                return null;
            }
            if (this.root && this.root.querySelector) {
                var local = this.root.querySelector(selector);
                if (local) {
                    return local;
                }
            }
            return document.querySelector(selector);
        },

        findAllByAttrValue: function (attrName, attrValue) {
            var selector = '[' + attrName + '="' + attrValue + '"]';
            var scoped = this.root ? $(this.root).find(selector) : $();
            if (scoped.length) {
                return scoped;
            }
            return $(selector);
        },

        findAllByAttr: function (attrName) {
            var selector = '[' + attrName + ']';
            var scoped = this.root ? $(this.root).find(selector) : $();
            if (scoped.length) {
                return scoped;
            }
            return $(selector);
        },

        setUrlParam: function (key, value) {
            var url = new URL(window.location.href);
            if (value === null || typeof value === 'undefined' || value === '') {
                url.searchParams.delete(key);
            } else {
                url.searchParams.set(key, String(value));
            }
            window.history.replaceState({}, '', url.pathname + url.search + url.hash);
        },

        normalizePeriod: function (period) {
            var allowed = (this.config.period && Array.isArray(this.config.period.allowed)) ? this.config.period.allowed : ['30d'];
            var normalized = String(period || this.config.period.default || '30d').toLowerCase();
            if (allowed.indexOf(normalized) === -1) {
                normalized = this.config.period.default || allowed[0] || '30d';
            }
            return normalized;
        },

        getPeriodFromUrl: function () {
            var url = new URL(window.location.href);
            var param = this.config.period.param || 'period';
            var period = url.searchParams.get(param);
            return this.normalizePeriod(period);
        },

        getPeriod: function () {
            if (!this.periodSelect) {
                return this.normalizePeriod(this.config.period.default);
            }
            return this.normalizePeriod(this.periodSelect.value);
        },

        periodLabel: function (period) {
            period = this.normalizePeriod(period);
            var labels = this.config.period.labels || {};
            return labels[period] || labels[this.config.period.default] || period;
        },

        periodShortLabel: function (period) {
            period = this.normalizePeriod(period);
            var labels = this.config.period.shortLabels || {};
            return labels[period] || labels[this.config.period.default] || period;
        },

        applyPeriodLabels: function (period) {
            var longAttr = this.config.selectors.periodLabelAttr;
            var shortAttr = this.config.selectors.periodShortLabelAttr;
            this.findAllByAttr(longAttr).text(this.periodLabel(period));
            this.findAllByAttr(shortAttr).text(this.periodShortLabel(period));
        },

        formatInt: function (value) {
            var n = parseInt(value, 10);
            if (isNaN(n)) {
                n = 0;
            }
            return n.toLocaleString('it-IT');
        },

        setKpi: function (key, value) {
            var attrName = this.config.selectors.kpiAttr;
            this.findAllByAttrValue(attrName, key).text(this.formatInt(value));
        },

        setCompare: function (key, compare) {
            var attrName = this.config.selectors.compareAttr;
            var target = this.findAllByAttrValue(attrName, key);
            if (!target.length) {
                return;
            }

            target.removeClass('admin-compare-up admin-compare-down admin-compare-flat');
            if (!compare || typeof compare !== 'object') {
                target.addClass('admin-compare-flat').text('-');
                return;
            }

            var trend = String(compare.trend || 'flat').toLowerCase();
            var delta = parseInt(compare.delta || 0, 10);
            if (isNaN(delta)) {
                delta = 0;
            }

            var arrow = '->';
            var klass = 'admin-compare-flat';
            if (trend === 'up') {
                arrow = '^';
                klass = 'admin-compare-up';
            } else if (trend === 'down') {
                arrow = 'v';
                klass = 'admin-compare-down';
            }

            var deltaText = (delta > 0 ? '+' : '') + this.formatInt(delta);
            var suffix = '';
            if (compare.previous === 0 && compare.current > 0) {
                suffix = ' (nuovo)';
            } else if (compare.delta_percent !== null && typeof compare.delta_percent !== 'undefined') {
                var pct = Number(compare.delta_percent);
                if (!isNaN(pct)) {
                    suffix = ' (' + (pct > 0 ? '+' : '') + pct.toLocaleString('it-IT', { maximumFractionDigits: 1 }) + '%)';
                }
            }

            target.addClass(klass).text(arrow + ' ' + deltaText + suffix + ' vs periodo precedente');
        },

        hideTargets: function (targets) {
            if (!targets || !targets.length) {
                return;
            }
            targets.each(function () {
                var container = this.closest('[data-dashboard-block]') || this.closest('.card') || this.closest('.col') || this;
                $(container).addClass('d-none');
            });
        },

        applyVisibility: function () {
            var visibility = this.config.visibility || {};
            var i;

            if (Array.isArray(visibility.hiddenSelectors)) {
                for (i = 0; i < visibility.hiddenSelectors.length; i++) {
                    var selector = visibility.hiddenSelectors[i];
                    if (!selector) {
                        continue;
                    }
                    $(selector).addClass('d-none');
                }
            }

            if (Array.isArray(visibility.hiddenKpis)) {
                for (i = 0; i < visibility.hiddenKpis.length; i++) {
                    this.hideTargets(this.findAllByAttrValue(this.config.selectors.kpiAttr, visibility.hiddenKpis[i]));
                }
            }

            if (Array.isArray(visibility.hiddenCompares)) {
                for (i = 0; i < visibility.hiddenCompares.length; i++) {
                    this.hideTargets(this.findAllByAttrValue(this.config.selectors.compareAttr, visibility.hiddenCompares[i]));
                }
            }

            if (Array.isArray(visibility.hiddenSections)) {
                var charts = this.config.bindings.charts || [];
                for (i = 0; i < charts.length; i++) {
                    if (visibility.hiddenSections.indexOf(charts[i].key) !== -1 && charts[i].selector) {
                        this.hideTargets($(charts[i].selector));
                    }
                }

                if (this.config.bindings.topLocations && visibility.hiddenSections.indexOf(this.config.bindings.topLocations.key) !== -1) {
                    this.hideTargets($(this.config.bindings.topLocations.selector));
                }

                if (this.config.bindings.recentLogs && visibility.hiddenSections.indexOf(this.config.bindings.recentLogs.key) !== -1) {
                    this.hideTargets($(this.config.bindings.recentLogs.selector));
                }
            }
        },

        renderLineChart: function (targetSelector, labels, seriesList) {
            var root = $(targetSelector);
            root.empty();

            if (!labels || !labels.length || !seriesList || !seriesList.length) {
                root.html('<div class="text-muted small">Nessun dato disponibile.</div>');
                return;
            }

            var width = 760;
            var height = 240;
            var padX = 34;
            var padY = 18;
            var plotWidth = width - (padX * 2);
            var plotHeight = height - (padY * 2);
            var maxValue = 0;

            for (var i = 0; i < seriesList.length; i++) {
                var arr = seriesList[i].values || [];
                for (var j = 0; j < arr.length; j++) {
                    var val = parseInt(arr[j], 10);
                    if (!isNaN(val) && val > maxValue) {
                        maxValue = val;
                    }
                }
            }

            if (maxValue < 1) {
                maxValue = 1;
            }

            function px(index) {
                if (labels.length === 1) {
                    return padX + (plotWidth / 2);
                }
                return padX + (index * (plotWidth / (labels.length - 1)));
            }

            function py(value) {
                var ratio = value / maxValue;
                if (ratio < 0) {
                    ratio = 0;
                }
                if (ratio > 1) {
                    ratio = 1;
                }
                return padY + (plotHeight - (ratio * plotHeight));
            }

            var grid = '';
            var ticks = [0, 0.25, 0.5, 0.75, 1];
            for (var t = 0; t < ticks.length; t++) {
                var y = padY + (plotHeight - (ticks[t] * plotHeight));
                var tickValue = Math.round(maxValue * ticks[t]);
                grid += '<line x1="' + padX + '" y1="' + y + '" x2="' + (padX + plotWidth) + '" y2="' + y + '" stroke="rgba(255,255,255,0.12)" stroke-width="1" />';
                grid += '<text x="' + (padX - 6) + '" y="' + (y + 4) + '" fill="rgba(234,240,255,0.7)" text-anchor="end" font-size="10">' + tickValue + '</text>';
            }

            var lines = '';
            for (var s = 0; s < seriesList.length; s++) {
                var points = [];
                var values = seriesList[s].values || [];
                for (var p = 0; p < labels.length; p++) {
                    var pointValue = parseInt(values[p], 10);
                    if (isNaN(pointValue)) {
                        pointValue = 0;
                    }
                    points.push(px(p) + ',' + py(pointValue));
                }
                lines += '<polyline fill="none" stroke="' + escapeHtml(seriesList[s].color || '#38BDF8') + '" stroke-width="2.5" points="' + points.join(' ') + '" />';
            }

            var axis = '';
            axis += '<line x1="' + padX + '" y1="' + (padY + plotHeight) + '" x2="' + (padX + plotWidth) + '" y2="' + (padY + plotHeight) + '" stroke="rgba(255,255,255,0.25)" stroke-width="1" />';
            var firstLabel = labels[0] || '';
            var midLabel = labels[Math.floor(labels.length / 2)] || '';
            var lastLabel = labels[labels.length - 1] || '';
            axis += '<text x="' + padX + '" y="' + (height - 2) + '" fill="rgba(234,240,255,0.7)" text-anchor="start" font-size="10">' + escapeHtml(firstLabel) + '</text>';
            axis += '<text x="' + (padX + (plotWidth / 2)) + '" y="' + (height - 2) + '" fill="rgba(234,240,255,0.7)" text-anchor="middle" font-size="10">' + escapeHtml(midLabel) + '</text>';
            axis += '<text x="' + (padX + plotWidth) + '" y="' + (height - 2) + '" fill="rgba(234,240,255,0.7)" text-anchor="end" font-size="10">' + escapeHtml(lastLabel) + '</text>';

            var html = '';
            html += '<svg viewBox="0 0 ' + width + ' ' + height + '" preserveAspectRatio="none">';
            html += grid + axis + lines;
            html += '</svg>';
            html += '<div class="admin-chart-legend">';
            for (var l = 0; l < seriesList.length; l++) {
                html += '<span><i class="admin-chart-dot" style="background:' + escapeHtml(seriesList[l].color || '#38BDF8') + '"></i>' + escapeHtml(seriesList[l].label || 'Serie') + '</span>';
            }
            html += '</div>';

            root.html(html);
        },

        renderTopLocations: function (targetSelector, dataset) {
            var target = $(targetSelector);
            target.empty();
            if (!dataset || !dataset.length) {
                target.html('<div class="text-muted small">Nessun dato disponibile.</div>');
                return;
            }

            var max = 1;
            for (var i = 0; i < dataset.length; i++) {
                var totalValue = parseInt(dataset[i].total, 10);
                if (!isNaN(totalValue) && totalValue > max) {
                    max = totalValue;
                }
            }

            for (var j = 0; j < dataset.length; j++) {
                var row = dataset[j] || {};
                var total = parseInt(row.total, 10);
                if (isNaN(total)) {
                    total = 0;
                }
                var pct = Math.round((total / max) * 100);
                var item = $('<div class="mb-2"></div>');
                item.append('<div class="d-flex justify-content-between"><span class="small">' + escapeHtml(row.name || '-') + '</span><b class="small">' + this.formatInt(total) + '</b></div>');
                item.append('<div class="progress" style="height:6px;"><div class="progress-bar bg-primary" role="progressbar" style="width:' + pct + '%"></div></div>');
                target.append(item);
            }
        },

        renderRecentLogs: function (targetSelector, dataset) {
            var target = $(targetSelector);
            target.empty();
            if (!dataset || !dataset.length) {
                target.html('<div class="text-muted small">Nessun log disponibile.</div>');
                return;
            }

            var table = $('<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>ID</th><th>Area</th><th>Azione</th><th>Data</th></tr></thead><tbody></tbody></table></div>');
            var body = table.find('tbody');
            for (var i = 0; i < dataset.length; i++) {
                var row = dataset[i] || {};
                body.append(
                    '<tr>' +
                    '<td>' + escapeHtml(row.id || '-') + '</td>' +
                    '<td>' + escapeHtml(row.area || '-') + '</td>' +
                    '<td>' + escapeHtml(row.action || '-') + '</td>' +
                    '<td>' + escapeHtml(row.date_created || '-') + '</td>' +
                    '</tr>'
                );
            }
            target.append(table);
        },

        applyBindings: function (data) {
            var bindings = this.config.bindings || {};
            var i;

            var kpis = Array.isArray(bindings.kpis) ? bindings.kpis.slice() : [];
            if (Array.isArray(bindings.extraKpis)) {
                kpis = kpis.concat(bindings.extraKpis);
            }
            for (i = 0; i < kpis.length; i++) {
                this.setKpi(kpis[i].key, getByPath(data, kpis[i].path, kpis[i].fallback));
            }

            var compares = Array.isArray(bindings.compares) ? bindings.compares.slice() : [];
            if (Array.isArray(bindings.extraCompares)) {
                compares = compares.concat(bindings.extraCompares);
            }
            for (i = 0; i < compares.length; i++) {
                this.setCompare(compares[i].key, getByPath(data, compares[i].path, compares[i].fallback));
            }

            var charts = Array.isArray(bindings.charts) ? bindings.charts : [];
            for (i = 0; i < charts.length; i++) {
                var chart = charts[i];
                if (!chart || !chart.selector) {
                    continue;
                }

                var labels = getByPath(data, chart.labelsPath, []);
                var seriesList = [];
                var series = Array.isArray(chart.series) ? chart.series : [];
                for (var s = 0; s < series.length; s++) {
                    seriesList.push({
                        label: series[s].label || 'Serie ' + (s + 1),
                        color: series[s].color || '#38BDF8',
                        values: getByPath(data, series[s].path, [])
                    });
                }

                this.renderLineChart(chart.selector, labels, seriesList);
            }

            if (bindings.topLocations && bindings.topLocations.selector) {
                this.renderTopLocations(bindings.topLocations.selector, getByPath(data, bindings.topLocations.path, []));
            }

            if (bindings.recentLogs && bindings.recentLogs.selector) {
                this.renderRecentLogs(bindings.recentLogs.selector, getByPath(data, bindings.recentLogs.path, []));
            }

            if (typeof bindings.customApply === 'function') {
                bindings.customApply(data, this, getByPath);
            }
        },

        load: function () {
            if (!this.periodSelect) {
                return;
            }

            var period = this.getPeriod();
            this.setUrlParam(this.config.period.param || 'period', period);
            this.applyPeriodLabels(period);

            var payload = {};
            payload[this.config.period.param || 'period'] = period;
            if (typeof this.config.request.payloadBuilder === 'function') {
                payload = this.config.request.payloadBuilder(period, this, payload) || payload;
            }

            if (typeof this.config.hooks.beforeLoad === 'function') {
                this.config.hooks.beforeLoad(period, this);
            }

            var self = this;
            var onSuccess = function (response) {
                var dataset = (response && response.dataset) ? response.dataset : {};
                var data = dataset;

                if (typeof self.config.request.responseMapper === 'function') {
                    data = self.config.request.responseMapper(response, dataset, period, self) || {};
                }

                var activePeriod = self.normalizePeriod(getByPath(data, 'meta.period', period));
                self.periodSelect.value = activePeriod;
                self.applyPeriodLabels(activePeriod);
                self.applyBindings(data);

                if (typeof self.config.hooks.afterLoad === 'function') {
                    self.config.hooks.afterLoad(data, self);
                }
            };
            var onError = function (error) {
                if (typeof self.config.hooks.onError === 'function') {
                    self.config.hooks.onError(error, self);
                    return;
                }

                var message = requestErrorMessage(error, 'Errore durante il caricamento dashboard.');

                if (typeof Toast !== 'undefined' && Toast && typeof Toast.show === 'function') {
                    Toast.show({
                        body: message,
                        type: 'error'
                    });
                }
            };

            if (typeof Request !== 'function') {
                onError(requestUnavailableMessage());
                return;
            }

            if (!Request.http || typeof Request.http.post !== 'function') {
                onError(requestUnavailableMessage());
                return;
            }
            Request.http.post(this.config.request.url, payload).then(onSuccess).catch(onError);
        }
    };

    window.Dashboard = Dashboard;
})();
