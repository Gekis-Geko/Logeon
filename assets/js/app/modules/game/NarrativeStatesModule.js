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

    function createGameNarrativeStatesModule() {
        return {
            ctx: null,
            grid: null,
            fullData: [],
            filterCategory: '',
            filterScope: '',

            mount: function (ctx) {
                this.ctx = ctx || null;
                this.loadIntoProfile();
                return this;
            },

            unmount: function () {},

            myStates: function (payload) {
                return this.request('/narrative-states/my-states', 'narrativeStatesMyStates', payload || {});
            },

            loadIntoProfile: function () {
                var self = this;
                var section = document.getElementById('profile-narrative-states-section');
                if (!section) { return; }

                var characterId = parseInt(section.getAttribute('data-character-id') || '0', 10) || 0;
                var payload = characterId > 0 ? { character_id: characterId } : {};

                this.myStates(payload).then(function (response) {
                    var rows = response && Array.isArray(response.dataset) ? response.dataset : [];
                    if (!rows.length) {
                        section.innerHTML = '';
                        return;
                    }
                    self.fullData = rows;
                    self.renderSection(section);
                    self.initGrid(section);
                }).catch(function () {
                    section.innerHTML = '';
                });
            },

            renderSection: function (section) {
                var scopeOptions = [
                    { value: '', label: 'Tutti gli ambiti' },
                    { value: 'character', label: 'Personaggio' },
                    { value: 'world', label: 'Mondo' },
                    { value: 'faction', label: 'Fazione' },
                    { value: 'guild', label: 'Gilda' }
                ];

                var categories = [];
                var seen = {};
                for (var i = 0; i < this.fullData.length; i++) {
                    var cat = String(this.fullData[i].category || '').trim();
                    if (cat && !seen[cat]) {
                        seen[cat] = true;
                        categories.push(cat);
                    }
                }
                categories.sort();

                var categoryOptions = '<option value="">Tutte le categorie</option>';
                for (var c = 0; c < categories.length; c++) {
                    categoryOptions += '<option value="' + escapeHtml(categories[c]) + '">' + escapeHtml(categories[c]) + '</option>';
                }

                var scopeOpts = '';
                for (var s = 0; s < scopeOptions.length; s++) {
                    scopeOpts += '<option value="' + escapeHtml(scopeOptions[s].value) + '">' + escapeHtml(scopeOptions[s].label) + '</option>';
                }

                section.innerHTML = '<div class="card mt-3">'
                    + '<div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">'
                    + '<span class="fw-bold small"><i class="bi bi-lightning-charge me-1"></i>Stati attivi</span>'
                    + '<div class="d-flex gap-2 flex-wrap">'
                    + '<select id="profile-ns-filter-category" class="form-select form-select-sm" style="width:auto;">' + categoryOptions + '</select>'
                    + '<select id="profile-ns-filter-scope" class="form-select form-select-sm" style="width:auto;">' + scopeOpts + '</select>'
                    + '</div>'
                    + '</div>'
                    + '<div class="card-body p-0"><div id="profile-narrative-states-grid"></div></div>'
                    + '</div>';
            },

            initGrid: function (section) {
                var self = this;
                var DatagridFn = (this.ctx && this.ctx.services && this.ctx.services.datagrid)
                    ? this.ctx.services.datagrid
                    : (typeof window.Datagrid === 'function' ? window.Datagrid : null);

                if (!DatagridFn || !document.getElementById('profile-narrative-states-grid')) { return; }

                this.grid = DatagridFn('profile-narrative-states-grid', {
                    thead: true,
                    columns: [
                        {
                            field: 'name', label: 'Nome', sortable: true,
                            format: function (r) {
                                var stacks = (r.stack_mode === 'stack' && parseInt(r.stacks, 10) > 1)
                                    ? ' <span class="badge text-bg-secondary ms-1">x' + parseInt(r.stacks, 10) + '</span>'
                                    : '';
                                return '<span class="fw-semibold">' + escapeHtml(r.name || r.code || 'Stato') + '</span>' + stacks;
                            }
                        },
                        {
                            field: 'category', label: 'Categoria', sortable: true, width: '130px',
                            format: function (r) {
                                return r.category ? escapeHtml(r.category) : '<span class="text-muted">—</span>';
                            }
                        },
                        {
                            field: 'scope', label: 'Ambito', sortable: true, width: '100px',
                            format: function (r) {
                                var labels = { character: 'Personaggio', world: 'Mondo', faction: 'Fazione', guild: 'Gilda' };
                                return escapeHtml(labels[r.scope] || r.scope || '—');
                            }
                        },
                        {
                            field: 'expires_at', label: 'Scadenza', sortable: true, width: '130px',
                            format: function (r) {
                                return r.expires_at
                                    ? '<span class="small">' + escapeHtml(String(r.expires_at)) + '</span>'
                                    : '<span class="text-muted small">—</span>';
                            }
                        }
                    ],
                    lang: { no_results: 'Nessuno stato attivo.' },
                    nav: { display: 'bottom', results: 10, urlupdate: false }
                });

                if (!this.grid) { return; }

                this.grid.paginator.urlupdate = false;
                (function (grid) {
                    grid.paginator.loadData = function (query, results, page, orderBy) {
                        return self._loadData(this, query, results, page, orderBy);
                    };
                })(this.grid);

                // Bind filter controls
                var catEl = document.getElementById('profile-ns-filter-category');
                var scopeEl = document.getElementById('profile-ns-filter-scope');
                if (catEl) {
                    catEl.addEventListener('change', function () {
                        self.filterCategory = this.value;
                        self._reload();
                    });
                }
                if (scopeEl) {
                    scopeEl.addEventListener('change', function () {
                        self.filterScope = this.value;
                        self._reload();
                    });
                }

                this._reload();
            },

            _reload: function () {
                if (!this.grid) { return; }
                var p = this.grid.paginator;
                p.loadData(p.nav.query || {}, p.nav.results, 1, p.nav.orderBy || 'name|ASC');
            },

            _loadData: function (paginatorInst, query, results, page, orderBy) {
                query = (query && typeof query === 'object') ? query : {};
                results = paginatorInst.toPositiveInt(results, paginatorInst.nav.results);
                page = paginatorInst.toPositiveInt(page, 1);
                orderBy = (typeof orderBy === 'string' && orderBy !== '') ? orderBy : (paginatorInst.nav.orderBy || '');

                var catFilter = this.filterCategory;
                var scopeFilter = this.filterScope;

                var filtered = [];
                for (var i = 0; i < this.fullData.length; i++) {
                    var r = this.fullData[i];
                    if (catFilter && String(r.category || '') !== catFilter) { continue; }
                    if (scopeFilter && String(r.scope || '') !== scopeFilter) { continue; }
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
                    properties: { query: query, page: page, results_page: results, orderBy: orderBy, tot: { count: total } }
                });

                return paginatorInst;
            },

            request: function (url, action, payload) {
                if (!this.ctx || !this.ctx.services || !this.ctx.services.http) {
                    return Promise.reject(new Error('HTTP service not available.'));
                }
                return this.ctx.services.http.request({ url: url, action: action, payload: payload || {} });
            }
        };
    }

    window.GameNarrativeStatesModuleFactory = createGameNarrativeStatesModule;
})(window);
