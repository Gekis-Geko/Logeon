function Paginator() {

	var paginator = {
		urlupdate: true,
		range: 5,
		dataset: {},
		nav: {
			query: {},
			orderBy: '',
			page: 1,
			results: 20,
			results_page: 20,
			tot: { count: 0 }
		},
		handler: {
			url: null,
			action: null
		},
		lang: {
			"result_found"		: "Risultati trovati",
			"total_pages"		: "Numero di pagine",
			"result_per_page"	: "Risultati per pagina",
			"no_results"		: "Nessun risultato"
		},
		div: null,

		_showError: function (title, body, type) {
			var level = type || 'danger';
			if (typeof Dialog === 'function') {
				Dialog(level, {title: title, body: '<p>' + body + '</p>'}).show();
				return;
			}
			if (typeof console !== 'undefined' && typeof console.error === 'function') {
				console.error('[Paginator] ' + title + ': ' + body);
			}
		},
		
		search_form: null,
		searchTimer: null,
		firstLoad: false,
		lock_reload: false,
		lock_relaod: false,
		mounted: false,
		isLoading: false,
		requestSignature: null,

		init: function() {
			this.lock_relaod = this.lock_reload;
			this.mounted = true;
			return this;
		},

		mount: function () {
			return this.init();
		},

		setDataset: function (dataset) {
			this.dataset = dataset;
			this.onDatasetUpdate();

			return this;
		},

		onDatasetUpdate: function () {
			//user options
		},

		buildOrderBy: function (field, direction) {
			var f = String(field || '').trim();
			if (!f) {
				return '';
			}
			var d = String(direction || 'ASC').trim().toUpperCase();
			if (d !== 'ASC' && d !== 'DESC') {
				d = 'ASC';
			}
			return f + '|' + d;
		},

		parseOrderBy: function (orderBy) {
			var out = {
				field: '',
				direction: 'ASC'
			};
			var raw = String(orderBy || '').trim();
			if (!raw) {
				return out;
			}
			var chunks = raw.split('|');
			out.field = String(chunks[0] || '').trim();
			var direction = String(chunks[1] || 'ASC').trim().toUpperCase();
			out.direction = (direction === 'DESC') ? 'DESC' : 'ASC';
			return out;
		},

		normalizeCriteria: function (criteria, fallbackNav) {
			var nav = (fallbackNav && typeof fallbackNav === 'object') ? fallbackNav : this.nav;
			var source = (criteria && typeof criteria === 'object') ? criteria : {};

			var query = source.query;
			if (!query || typeof query !== 'object') {
				query = source.filters;
			}
			if (!query || typeof query !== 'object') {
				query = nav.query;
			}

			var pagination = (source.pagination && typeof source.pagination === 'object') ? source.pagination : {};
			var resultsRaw = source.results;
			if (resultsRaw == null) {
				resultsRaw = source.results_page;
			}
			if (resultsRaw == null) {
				resultsRaw = pagination.results;
			}
			if (resultsRaw == null) {
				resultsRaw = pagination.results_page;
			}
			if (resultsRaw == null) {
				resultsRaw = pagination.perPage;
			}
			var results = this.toPositiveInt(resultsRaw, nav.results);

			var pageRaw = source.page;
			if (pageRaw == null) {
				pageRaw = pagination.page;
			}
			var page = this.toPositiveInt(pageRaw, nav.page);

			var orderBy = '';
			if (typeof source.orderBy === 'string') {
				orderBy = source.orderBy;
			} else if (source.sort && typeof source.sort === 'object') {
				orderBy = this.buildOrderBy(source.sort.field, source.sort.direction);
			}
			if (!orderBy) {
				orderBy = nav.orderBy;
			}

			return {
				query: (query && typeof query === 'object') ? query : {},
				results: results,
				page: page,
				orderBy: String(orderBy || '')
			};
		},

		getCriteria: function () {
			var sort = this.parseOrderBy(this.nav.orderBy);
			return {
				filters: Object.assign({}, (this.nav.query && typeof this.nav.query === 'object') ? this.nav.query : {}),
				sort: sort,
				pagination: {
					page: this.toPositiveInt(this.nav.page, 1),
					results: this.toPositiveInt(this.nav.results, 20)
				},
				query: Object.assign({}, (this.nav.query && typeof this.nav.query === 'object') ? this.nav.query : {}),
				orderBy: String(this.nav.orderBy || '')
			};
		},

		setNav: function (obj) {
			obj = (obj && typeof obj === 'object') ? obj : {};

			if (obj.results == null && obj.results_page != null) {
				obj.results = obj.results_page;
			}

			if (obj.results != null) {
				obj.results = this.toPositiveInt(obj.results, this.nav.results);
			}

			if (obj.page != null) {
				obj.page = this.toPositiveInt(obj.page, this.nav.page);
			}

			Object.assign(this.nav, obj);
			this.nav.query = (this.nav.query && typeof this.nav.query === 'object') ? this.nav.query : {};
			this.nav.results = this.toPositiveInt(this.nav.results, 20);
			this.nav.results_page = this.nav.results;
			this.nav.page = this.toPositiveInt(this.nav.page, 1);
			this.nav.tot = (this.nav.tot && typeof this.nav.tot === 'object') ? this.nav.tot : { count: 0 };
			this.nav.tot.count = this.toNonNegativeInt(this.nav.tot.count, 0);

			if (this.urlupdate) {
				this.updateHash();
			}

			this.buildNav();

			return this;
		},

		updateHash: function () {
			if (typeof window === 'undefined' || !window.location) {
				return;
			}
			var hash = "query=" + encodeURIComponent(JSON.stringify(this.nav.query || {}))
				+ "&page=" + this.nav.page
				+ "&results=" + this.nav.results
				+ "&orderBy=" + encodeURIComponent(this.nav.orderBy || '');
			window.location.hash = hash;
		},

		setHandlerByURL: function (url) {
			if (typeof $ === 'undefined') {
				return this;
			}
			if (url == null || String(url).trim() === '') {
				return this;
			}
			var urlHack = $('<a></a>');
			urlHack.attr('href', url);

			var pieces = urlHack.search.substr(1).split("&");
			var querystring = {};
			for (var i = 0; i < pieces.length; i++) {
				if (!pieces[i]) {
					continue;
				}
				var d = pieces[i].split("=");
				var key = '';
				try {
					key = decodeURIComponent(d[0] || '');
				} catch (error) {
					key = d[0] || '';
				}
				if (!key) {
					continue;
				}
				if (d[1] == "null") {
					querystring[key] = "{}";
				} else {
					try {
						querystring[key] = decodeURIComponent(d[1] || '');
					} catch (err) {
						querystring[key] = d[1] || '';
					}
				}
			}

			var handler = {
				url: urlHack.pathname,
				action: querystring["action"],
			};
			this.setHandler(handler);

			return this;
		},

		setHandler: function (handler) {
			handler = (handler && typeof handler === 'object') ? handler : {};
			if (handler.url != null) {
				handler.url = String(handler.url).trim();
			}
			if (handler.action != null) {
				handler.action = String(handler.action).trim();
			}
			Object.assign(this.handler, handler);

			return this;
		},

		getTemplate: function() {
			return '<div class="row my-3 grid-paginations">'
						+ '<div class="col-3">'
							+ '<div class="nav-result-found">'
								+ '<b>' + this.lang.result_found + ': </b>'
								+ '<span name="result-found"></span>'
							+ '</div>'
							+ '<div class="nav-number-pages">'
								+ '<b>' + this.lang.total_pages + ': </b>'
								+ '<span name="number-pages"></span>'
							+'</div>'
						+ '</div>'
						+ '<div class="col-6 nav-paginations"></div>'
						+ '<div class="col-3 nav-results-page text-end">'
							+ '<b>' + this.lang.result_per_page + ': </b>'
							+'<span name="results-page"></span>'
						+ '</div>'
					+ '</div>';
		},

		getHash: function () {
			var hashObj = {};
			if (typeof window === 'undefined' || !window.location) {
				return hashObj;
			}
			var h = window.location.hash.substring(1, window.location.hash.length);
			if (!h) {
				return hashObj;
			}

			var pieces = h.split("&");
			for (var i = 0; i < pieces.length; i++) {
				if (!pieces[i]) {
					continue;
				}
				var d = pieces[i].split("=");
				var key = '';
				try {
					key = decodeURIComponent(d[0] || '');
				} catch (e) {
					key = d[0] || '';
				}
				if (!key) {
					continue;
				}
				if (d[1] == "null") {
					hashObj[key] = null;
				} else {
					try {
						hashObj[key] = decodeURIComponent(d[1] || '');
					} catch (error) {
						hashObj[key] = d[1] || '';
					}
				}
			}
			return hashObj;
		},

		onHashChange: function () {
			this.loadFromHash();
		},

		loadFromNav: function (nav) {
			nav = (nav && typeof nav === 'object') ? nav : {};
			return this.loadByCriteria(nav);
		},

		loadByCriteria: function (criteria) {
			var normalized = this.normalizeCriteria(criteria, this.nav);
			return this.loadData(normalized.query, normalized.results, normalized.page, normalized.orderBy);
		},

		loadData: function (query, results, page, orderBy) {
			var self = this;
			if (arguments.length === 1 && query && typeof query === 'object' && !Array.isArray(query)) {
				var normalizedInput = this.normalizeCriteria(query, this.nav);
				query = normalizedInput.query;
				results = normalizedInput.results;
				page = normalizedInput.page;
				orderBy = normalizedInput.orderBy;
			}
			query = (query && typeof query === 'object') ? query : {};
			results = this.toPositiveInt(results, this.nav.results);
			page = this.toPositiveInt(page, 1);
			orderBy = (typeof orderBy === 'string') ? orderBy : this.nav.orderBy;
			var signature = '';
			try {
				signature = JSON.stringify({
					query: query,
					results: results,
					page: page,
					orderBy: orderBy
				});
			} catch (e) {
				signature = String(Date.now());
			}
			if (this.isLoading === true && signature === this.requestSignature) {
				return this;
			}
			this.isLoading = true;
			this.requestSignature = signature;

			this.onLoad(query, results, page, orderBy);

			var dataset = {
				query: query,
				results: results,
				page: page,
				orderBy: orderBy
			};

			if (!this.handler || !this.handler.url) {
				this.isLoading = false;
				this.requestSignature = null;
				this.error('Handler non configurato.');
				return this;
			}
			if (typeof Request !== 'function') {
				this.isLoading = false;
				this.requestSignature = null;
				this.error(this.requestUnavailableMessage());
				return this;
			}

			var http = (Request.http && typeof Request.http.request === 'function') ? Request.http : null;
			if (!http) {
				this.isLoading = false;
				this.requestSignature = null;
				this.error(this.requestUnavailableMessage());
				return this;
			}
			http.request({
				url: this.handler.url,
				method: 'POST',
				data: dataset
			}).then(function (response) {
				self.complete(response);
			}).catch(function (error) {
				self.error(error);
			});
			this.firstLoad = true;

			return this;
		},

		onLoad: function(query, results, page, orderBy) {
			//hook

			return;
		},

		complete: function(r) {
			this.isLoading = false;
			this.requestSignature = null;
			var response = (r && typeof r === 'object') ? r : {};
			var properties = (response.properties && typeof response.properties === 'object') ? response.properties : {};
			var dataset = response.dataset;
			if (dataset == null) {
				dataset = [];
			}

			if (properties.query == null) {
				properties.query = this.nav.query;
			}
			if (properties.page == null) {
				properties.page = this.nav.page;
			}
			if (properties.orderBy == null) {
				properties.orderBy = this.nav.orderBy;
			}
			if (properties.results == null && properties.results_page == null) {
				properties.results = this.nav.results;
			}

			this.setNav(properties).setDataset(dataset);
			this.onComplete(r);

			return;
		},

		onComplete: function(r) {
			//hook

			return;
		},

		error: function(error) {
			this.isLoading = false;
			this.requestSignature = null;
			var message = this.normalizeError(error);
			this._showError('Errore caricamento', 'Impossibile scaricare i dati per la tabella: ' + message, 'danger');
			this.onError(error);
			return this;
		},

		normalizeError: function (error) {
			if (typeof window !== 'undefined' && window.Request && typeof window.Request.getErrorMessage === 'function') {
				return window.Request.getErrorMessage(error, 'errore sconosciuto');
			}
			if (typeof error === 'string' && error.trim() !== '') {
				return error.trim();
			}
			return 'errore sconosciuto';
		},

		requestUnavailableMessage: function () {
			if (typeof window !== 'undefined' && window.Request && typeof window.Request.getUnavailableMessage === 'function') {
				return window.Request.getUnavailableMessage();
			}
			return 'Servizio comunicazione non disponibile. Ricarica la pagina e riprova.';
		},

		onError: function(error) {
			//hook

			return;
		},

		loadFromHash: function (force) {
			var hashObj = this.getHash();
			if (this.search_form) {
				if (hashObj["query"]) {
					var parsedHashQuery = this.parseQuery(hashObj["query"], this.nav.query);
					this.setSearch(parsedHashQuery);
				}
			}

			var oldNav = {
				"query"		: this.nav.query,
				"results"	: this.nav.results,
				"page"		: this.nav.page,
				"orderBy"	: this.nav.orderBy,
			};

			var newNav = {
				"query"		: (hashObj["query"]) ? this.parseQuery(hashObj["query"], this.nav.query) : this.nav.query,
				"results"	: (hashObj["results"]) ? this.toPositiveInt(hashObj["results"], this.nav.results) : this.nav.results,
				"page"		: (hashObj["page"]) ? 1 * hashObj["page"] : this.nav.page,
				"orderBy"	: (hashObj["orderBy"]) ? hashObj["orderBy"] : this.nav.orderBy,
			};
			newNav.page = this.toPositiveInt(newNav.page, this.nav.page);

			if (this.firstLoad===true && force !== true && this.arrayCompare(oldNav, newNav)) {

				return false;
			}

			this.loadFromNav(newNav);

			return true;
		},

		reloadData: function () {
			this.loadFromNav(this.nav);

			return this;
		},

		setSearchForm: function (form_id) {
			if (typeof $ === 'undefined') {
				return this;
			}
			if (typeof Form !== 'function') {
				this._showError('Ricerca', 'Servizio Form non disponibile.', 'warning');
				return this;
			}
			if (!form_id) {
				this.search_form = null;
				return this;
			}

			var self = this;

			self.search_form = form_id;
			var formSelector = "#" + self.search_form;
			var instantSelector = formSelector + " [data-search-type=instant]";
			var standardSelector = formSelector + " [data-search-type=standard]";

			$(instantSelector).off('.paginatorSearch').on('keyup.paginatorSearch change.paginatorSearch',
				function () {
					var s = Form().getFields(self.search_form);
					self.instantSearch(s);
				}
			);
			$(standardSelector).off('.paginatorSearch').on('blur.paginatorSearch change.paginatorSearch',
				function () {
					var s = Form().getFields(self.search_form);
					self.doSearch(s);
				}
			);
			$(formSelector).off('submit.paginatorSearch').on('submit.paginatorSearch',
				function (e) {
					e.preventDefault();
					var s = Form().getFields(self.search_form);
					self.doSearch(s);

					return false;
				}
			);

			return this;
		},

		getSearch: function() {
			if (!this.search_form) {
				this._showError('Ricerca', 'Modulo di ricerca non impostato.', 'warning');

				return {};
			}
			if (typeof Form !== 'function') {
				this._showError('Ricerca', 'Servizio Form non disponibile.', 'warning');
				return {};
			}

			return Form().getFields(this.search_form);
		},

		setSearch: function (data) {
			if (!this.search_form || typeof Form !== 'function') {
				return this;
			}
			this.lock_reload = true;
			this.lock_relaod = true;
			try {
				Form().setFields(this.search_form, data);
			} finally {
				this.lock_reload = false;
				this.lock_relaod = false;
			}

			return this;
		},

		loadFromSearch: function () {
			if (!this.search_form) {
				this._showError('Ricerca', 'Modulo di ricerca non impostato.', 'warning');

				return false;
			}

			return this.doSearch(this.getSearch());
		},

		instantSearch: function () {
			if (this.lock_reload) {
				return;
			}
			if (!this.search_form || typeof Form !== 'function') {
				return;
			}

			var searchNewQuery = Form().getFields(this.search_form);
			if (this.searchTimer)
				window.clearTimeout(this.searchTimer);

			var self = this;
			this.searchTimer = setTimeout(function () {
				self.doSearch(searchNewQuery);
			}, 1000);

			return;
		},

		doSearch: function (searchNewQuery) {

			if (searchNewQuery == null) {
				searchNewQuery = {};
			}

			if (this.arrayCompare(searchNewQuery, this.nav.query)) {

				return false;
			}

			this.loadByCriteria({
				filters: searchNewQuery,
				sort: this.parseOrderBy(this.nav.orderBy),
				pagination: {
					page: 1,
					results: this.nav.results
				}
			});

			return true;
		},

		removeSearchTimer: function () {
			if (this.searchTimer) {
				window.clearTimeout(this.searchTimer);
			}

			return this;
		},

		clearSearch: function () {
			if (!this.search_form) {
				return this;
			}
			if (typeof $ === 'undefined') {
				return this;
			}
			$('#' + this.search_form).trigger("reset").submit();

			return this;
		},

		buildNav: function () {
			if (!this.div) {
				return this;
			}
			if (typeof $ === 'undefined') {
				return this;
			}

			var block = $(this.div);
			if (!block.length) {
				return this;
			}
			block.empty();
			var nav_template = $(this.getTemplate());

			var totalCount = this.toNonNegativeInt((this.nav.tot && this.nav.tot.count), 0);
			var navResults = this.toPositiveInt(this.nav.results, 20);
			this.nav.results = navResults;
			this.nav.results_page = navResults;
			this.nav.tot_pages = (totalCount > 0) ? Math.ceil(totalCount / navResults) : 0;

			nav_template.find('.nav-result-found [name="result-found"]').html(totalCount);
			nav_template.find('.nav-number-pages [name="number-pages"]').html(this.nav.tot_pages);

			this.buildPagination(nav_template.find('.nav-paginations'));

			nav_template.find('[name="results-page"]').html(navResults);

			nav_template.appendTo(block);

			return this;
		},

		buildPagination: function (div) {
			var range = this.toPositiveInt(this.range, 5);
			var page = this.nav.page * 1;
			var tot_pages = this.nav.tot_pages * 1;

			div.empty();
			if (tot_pages <= 0) {
				return this;
			}
			page = Math.max(1, Math.min(page, tot_pages));
			this.nav.page = page;

			var self = this;
			var addPageLink = function (targetPage) {
				var pagination = {
					_parent: self,
					query: self.nav.query,
					results: self.nav.results,
					page: targetPage,
					orderBy: self.nav.orderBy
				};
				var criteria = {
					_parent: self,
					filters: self.nav.query,
					sort: self.parseOrderBy(self.nav.orderBy),
					pagination: {
						page: targetPage,
						results: self.nav.results
					}
				};
				var complete_url = "#query=" + encodeURIComponent(JSON.stringify(pagination.query || {}))
					+ "&page=" + pagination.page
					+ "&results=" + pagination.results
					+ "&orderBy=" + encodeURIComponent(pagination.orderBy || '');

				var link_new = $('<a class="btn btn-light me-2"></a>')
					.html(targetPage)
					.attr('href', complete_url);

				if (page === targetPage) {
					link_new.addClass('btn-primary actual-page disabled');
					link_new.removeClass('btn-light');
				}

				link_new.data({
					pagination: pagination,
					criteria: criteria
				});
				link_new.on('click', function () {
					var data = $(this).data();
					if (data.criteria && data.criteria._parent && typeof data.criteria._parent.loadByCriteria === 'function') {
						data.criteria._parent.loadByCriteria(data.criteria);
						return false;
					}
					if (data.pagination && data.pagination._parent) {
						data.pagination._parent.loadData(data.pagination.query, data.pagination.results, data.pagination.page, data.pagination.orderBy);
					}
					return false;
				});

				div.append(link_new);
			};

			var addEllipsis = function () {
				div.append($('<span class="btn btn-primary me-2 disabled">..</span>'));
			};

			var startPage = Math.max(1, page - range);
			var endPage = Math.min(tot_pages, page + range);

			if (startPage > 1) {
				addPageLink(1);
				if (startPage > 2) {
					addEllipsis();
				}
			}

			for (var i = startPage; i <= endPage; i++) {
				addPageLink(i);
			}

			if (endPage < tot_pages) {
				if (endPage < tot_pages - 1) {
					addEllipsis();
				}
				addPageLink(tot_pages);
			}

			return this;
		},

		parseQuery: function (rawQuery, fallback) {
			if (rawQuery && typeof rawQuery === 'object') {
				return rawQuery;
			}
			if (rawQuery == null || rawQuery === '') {
				return (fallback && typeof fallback === 'object') ? fallback : {};
			}

			try {
				var parsed = JSON.parse(rawQuery);
				return (parsed && typeof parsed === 'object') ? parsed : {};
			} catch (e) {
				return (fallback && typeof fallback === 'object') ? fallback : {};
			}
		},

		destroy: function () {
			this.removeSearchTimer();
			if (typeof $ !== 'undefined' && this.search_form) {
				var formSelector = "#" + this.search_form;
				var instantSelector = formSelector + " [data-search-type=instant]";
				var standardSelector = formSelector + " [data-search-type=standard]";
				$(instantSelector).off('.paginatorSearch');
				$(standardSelector).off('.paginatorSearch');
				$(formSelector).off('submit.paginatorSearch');
			}
			this.search_form = null;
			this.isLoading = false;
			this.requestSignature = null;
			this.mounted = false;
			return this;
		},

		unmount: function () {
			return this.destroy();
		},

		toPositiveInt: function (value, fallback) {
			var parsed = parseInt(value, 10);
			return (isNaN(parsed) || parsed < 1) ? parseInt(fallback, 10) || 1 : parsed;
		},

		toNonNegativeInt: function (value, fallback) {
			var parsed = parseInt(value, 10);
			return (isNaN(parsed) || parsed < 0) ? parseInt(fallback, 10) || 0 : parsed;
		},

		arrayCompare: function (a, b) {
			var astring = '';
			var bstring = '';
			try {
				astring = JSON.stringify(a);
			} catch (e) {
				astring = '';
			}
			try {
				bstring = JSON.stringify(b);
			} catch (e) {
				bstring = '';
			}

			return (astring == bstring);
		}
	};

	let o = Object.assign({}, paginator);
	return o.init();
}

if (typeof window !== 'undefined') {
    window.Paginator = Paginator;
}
