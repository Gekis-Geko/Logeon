var datagridID = new Array();

function Datagrid(div, options) {

	var datagrid = {

		method: "GET",
		orderable: true,
		thead: true,
		mounted: false,
		
		lang: {
			"result_found":		"Risultati trovati",
			"total_pages":		"Numero di pagine",
			"result_per_page":	"Risultati per pagina",
			"no_results":		"Nessun risultato"
		},

		_showError: function (title, body, type) {
			var level = type || 'danger';
			if (typeof Dialog === 'function') {
				Dialog(level, {title: title, body: '<p>' + body + '</p>'}).show();
				return;
			}
			if (typeof console !== 'undefined' && typeof console.error === 'function') {
				console.error('[DataGrid] ' + title + ': ' + body);
			}
		},

		init: function (div_id) {
			this.mounted = true;
			this.opt = (options && typeof options === 'object') ? options : {};
			this.ID = datagridID.length;
			datagridID[this.ID] = this;

			this.div_id = div_id;
			this.div = $('#' + div_id);
			if (!this.div.length) {
				var gridName = (this.opt && this.opt.name) ? this.opt.name : '(senza nome)';
				this._showError('Errore', 'Non posso creare la tabella ' + gridName + ': non trovo il DIV con ID: ' + div_id, 'danger');

				return false;
			}

			let theader = (this.thead) ? '<thead></thead>' : '';

			this.div.html(
				'<div class="paginator" name="top"></div>'
				+ '<div class="table-responsive"><table class="table table-striped">' + theader + '<tbody></tbody></table></div>'
				+ '<div class="paginator" name="bottom"></div>'
			);

			if (typeof Paginator !== 'function') {
				this._showError('Errore', 'Componente Paginator non disponibile.', 'danger');
				return false;
			}

			var self = this;
			this.paginator = new Paginator();
			this.paginator.setHandler(this.opt.handler || {});
			this.paginator.onDatasetUpdate = function () {
				self.setData(this.dataset);
			};
			this.paginator.onLoad = function (query, results, page, orderBy) {
				$('#' + self.div_id).fadeTo(200, 0.20);
				self.onGetDataStart(query, results, page, orderBy);
			};
			this.paginator.onComplete = function (data) {
				$('#' + self.div_id).fadeTo(100, 1);
				self.onGetDataSuccess(data);
			};
			this.paginator.onError = function (error) {
				$('#' + self.div_id).fadeTo(100, 1);
				self.onGetDataError(error);
			};

			this.table = $('#' + this.div_id + " table").get(0);
			this.tHead = (true == this.thead) ? this.table.tHead : [];
			this.tBody = this.table.tBodies[0];

			this.opt.thead = null != this.opt.thead ? this.opt.thead : this.thead;
			this.opt.orderable = null != this.opt.orderable ? this.opt.orderable : this.orderable;

			this.autoindex	= (this.opt["autoindex"]) ? this.opt["autoindex"] : null;
			this.getRowStyle = (this.opt["setRowClass"]) ? this.opt["setRowClass"] : function () {
				return false;
			};
			if (this.opt["data_source"]) this.paginator.setHandlerByURL(this.opt["data_source"]);
			if (this.opt["lang"]) this.setLanguage(this.opt["lang"]);
			if (this.opt["columns"]) this.setColumns(this.opt["columns"]);
			if (this.opt["dataset"]) this.setData(this.opt["dataset"]);
			if (this.opt["nav"]) this.setNavigator(this.opt["nav"]);

			return this;
		},

		mount: function (div_id) {
			return this.init(div_id || this.div_id);
		},

		setMethod: function (method) {
			method = method.toUpperCase();
			if (method!="GET" && method!="POST") {
				this._showError('Metodo non permesso', 'Metodo ' + method + ' non permesso.', 'warning');
				return this;
			}
			this.method = method;

			return this;
		},

		setLanguage: function (lang) {
			if (lang && typeof lang === 'object') {
				this.lang = Object.assign({}, this.lang, lang);
			}

			return this;
		},

		setNavigator: function (nav_obj) {
			if (!this.paginator) {
				return this;
			}
			if (!nav_obj || typeof nav_obj !== 'object') {
				return this;
			}

			if (nav_obj["results"] != null) {
				this.paginator.nav.results = 1 * nav_obj["results"];
			}
			if (nav_obj["results_page"] != null) {
				this.paginator.nav.results = 1 * nav_obj["results_page"];
			}
			this.paginator.nav.results_page = this.paginator.nav.results;

			if (nav_obj["urlupdate"] != null) {
				this.paginator.urlupdate = !!nav_obj["urlupdate"];
			}

			if (nav_obj["display"] != null && nav_obj["display"] !== false) {
				if (nav_obj["display"] == true) {
					this.paginator.div = '#' + this.div_id + " .paginator";
				}

				if (nav_obj["display"] == "top") {
					this.paginator.div = '#' + this.div_id + " .paginator[name=top]";
				}

				if (nav_obj["display"] == "bottom") {
					this.paginator.div = '#' + this.div_id + " .paginator[name=bottom]";
				}
			} else {
				this.paginator.div = null;
			}

			return this;
		},

		loadFromSearch: function() {
			if (!this.paginator || typeof this.paginator.loadFromSearch !== 'function') {
				return false;
			}
			return this.paginator.loadFromSearch();
		},

		loadFromHash: function(force) {
			if (!this.paginator || typeof this.paginator.loadFromHash !== 'function') {
				return false;
			}
			return this.paginator.loadFromHash(force);
		},

		loadData: function (query, results, page, orderBy) {
			if (!this.paginator || typeof this.paginator.loadData !== 'function') {
				return this;
			}
			return this.paginator.loadData(query, results, page, orderBy);
		},

		load: function (criteria) {
			if (!this.paginator) {
				return this;
			}
			if (typeof this.paginator.loadByCriteria === 'function') {
				return this.paginator.loadByCriteria(criteria || {});
			}
			return this.loadData(criteria);
		},

		getCriteria: function () {
			if (!this.paginator || typeof this.paginator.getCriteria !== 'function') {
				return {
					filters: {},
					sort: { field: '', direction: 'ASC' },
					pagination: { page: 1, results: 20 }
				};
			}
			return this.paginator.getCriteria();
		},

		setFilters: function (filters) {
			var criteria = this.getCriteria();
			criteria.filters = (filters && typeof filters === 'object') ? filters : {};
			criteria.query = criteria.filters;
			criteria.pagination.page = 1;
			return this.load(criteria);
		},

		setSort: function (field, direction) {
			var criteria = this.getCriteria();
			criteria.sort = {
				field: String(field || '').trim(),
				direction: String(direction || 'ASC').trim().toUpperCase() === 'DESC' ? 'DESC' : 'ASC'
			};
			criteria.pagination.page = 1;
			return this.load(criteria);
		},

		setPage: function (page) {
			var criteria = this.getCriteria();
			criteria.pagination.page = parseInt(page, 10) || criteria.pagination.page || 1;
			return this.load(criteria);
		},

		setResultsPerPage: function (results) {
			var criteria = this.getCriteria();
			criteria.pagination.results = parseInt(results, 10) || criteria.pagination.results || 20;
			criteria.pagination.page = 1;
			return this.load(criteria);
		},

		reloadData: function() {
			if (!this.paginator || typeof this.paginator.reloadData !== 'function') {
				return this;
			}
			return this.paginator.reloadData();
		},

		doSearch: function(search) {
			if (!this.paginator || typeof this.paginator.doSearch !== 'function') {
				return false;
			}
			return this.paginator.doSearch(search);
		},

		onGetDataStart: function (query, results, page, orderBy) {
			//user defined function
			//standard behaviour

			return;
		},

		onGetDataSuccess: function (R) {
			//user defined function
			//standard behaviour

			return;
		},

		onGetDataError: function (R) {
			//user defined function
			//standard behaviour

			return;
		},

		setData: function(dataset) {
			this.dataset = dataset;
			this.rebuildIndex();
			this.updateTable();

			return;
		},

		rebuildIndex: function() {
			var new_index = {};
			if (this.dataset != null && this.opt.autoindex != null) {
				for (var c in this.dataset) {
					if (!Object.prototype.hasOwnProperty.call(this.dataset, c)) {
						continue;
					}
					if (this.dataset[c] != null) {
						var key = this.dataset[c][this.autoindex];
						new_index[String(key)] = c;
					}

				}
			}
			this.index = new_index;

			return;
		},

		getElementIndex: function(id) {

			return this.index[String(id)];
		},

		getElementByID: function(id) {

			return this.dataset[this.getElementIndex(id)];
		},

		setColumns: function(columns) {
			if (!Array.isArray(columns)) {
				columns = [];
			}
			this.columns 		= columns;
			var result 			= this.checkColumnsRecursive(this.columns,1);
			this.columnsReal	= result[0];
			this.rowSpan		= result[1];

			this.updateTable();

			return;
		},

		checkColumnsRecursive: function(columns,rowSpan) {
			var c = [];
			var rowSpanMax = rowSpan;
			for (var l = 0; l < columns.length; l++) {
				var d = columns[l];
				if (d === undefined || d.groupName === undefined) {
					c[c.length]=d;

					continue;
				}
				if (d.groupName !== undefined) {
					var result = this.checkColumnsRecursive(d.columns,rowSpan+1);
					c = c.concat(result[0]);
					if (result[1] > rowSpanMax) rowSpanMax = result[1];
				}
			}

			return [c,rowSpanMax];
		},

		columnsHeaders: function(row, columns, rowSpan) {
			var columns_group = [];
			for (var i = 0; i < columns.length; i++) {
				var d = columns[i];
				var cell = row.insertCell(-1);

				// CELLA SEPARATORE
				if (d == undefined) {
					cell.rowSpan = rowSpan;
					cell.className = "separator";
					continue;
				}

				// CELLA GRUPPO
				if (d.groupName != null) {
					if (rowSpan > 1) {
						if (rowSpan > 2) {
							cell.rowSpan = rowSpan - 1;
						}
						cell.colSpan = d.columns.length;
						cell.className = "text-bg-secondary";
						cell.innerHTML = d.groupName;
						columns_group = columns_group.concat(d.columns);
					}

					continue;
				}

				// CELLA NORMALE
				cell.rowSpan = rowSpan;
				if (d.width != null) {
					cell.style.width = d.width;
				}
				cell.className = "text-bg-light";
				if (columns[i].label == undefined) {
					columns[i].label = this.ucwords(columns[i].field);
				}
				cell.innerHTML = d.label;
				if (d.labelStyle && typeof d.labelStyle === 'object') {
					for (var s in d.labelStyle) {
						if (Object.prototype.hasOwnProperty.call(d.labelStyle, s)) {
							cell.style[s] = d.labelStyle[s];
						}
					}
				}
				if (true == this.opt.orderable && d.sortable) {
					var sortWrap = document.createElement("span");
					sortWrap.className = "float-end ms-2";
					sortWrap.style.whiteSpace = "nowrap";
					cell.appendChild(sortWrap);

					var onclick = function () {
						if (this.criteria && this.criteria._parent && typeof this.criteria._parent.loadByCriteria === 'function') {
							this.criteria._parent.loadByCriteria(this.criteria);
							return false;
						}
						this.nav._parent.loadData(this.nav.query, this.nav.results, this.nav.page, this.nav.orderBy);
						return false;
					};

					var a_sort_asc = document.createElement("a");
					var nav_asc = {
						_parent: this,
						query: this.paginator.nav.query,
						results: this.paginator.nav.results,
						page: 1,
						orderBy: d.field + "|ASC"
					};
					var criteria_asc = {
						_parent: this.paginator,
						filters: this.paginator.nav.query,
						sort: {
							field: d.field,
							direction: "ASC"
						},
						pagination: {
							page: 1,
							results: this.paginator.nav.results
						}
					};
					a_sort_asc.nav = nav_asc;
					a_sort_asc.criteria = criteria_asc;
					a_sort_asc.onclick = onclick;
					a_sort_asc.href = "#query=" + encodeURIComponent(JSON.stringify(nav_asc.query || {})) + "&page=" + nav_asc.page + "&results=" + nav_asc.results + "&orderBy=" + encodeURIComponent(nav_asc.orderBy);
					a_sort_asc.className = "sort_asc text-secondary text-decoration-none";
					a_sort_asc.style.marginRight = "0.25rem";
					a_sort_asc.innerHTML = "&#9650;";
					sortWrap.appendChild(a_sort_asc);

					var a_sort_des = document.createElement("a");
					var nav_des = {
						_parent: this,
						query: this.paginator.nav.query,
						results: this.paginator.nav.results,
						page: 1,
						orderBy: d.field + "|DESC"
					};
					var criteria_des = {
						_parent: this.paginator,
						filters: this.paginator.nav.query,
						sort: {
							field: d.field,
							direction: "DESC"
						},
						pagination: {
							page: 1,
							results: this.paginator.nav.results
						}
					};
					a_sort_des.nav = nav_des;
					a_sort_des.criteria = criteria_des;
					a_sort_des.onclick = onclick;
					a_sort_des.href = "#query=" + encodeURIComponent(JSON.stringify(nav_des.query || {})) + "&page=" + nav_des.page + "&results=" + nav_des.results + "&orderBy=" + encodeURIComponent(nav_des.orderBy);
					a_sort_des.className = "sort_des text-secondary text-decoration-none";
					a_sort_des.innerHTML = "&#9660;";
					sortWrap.appendChild(a_sort_des);
				}

			}

			return columns_group;
		},

		updateTable: function() {
			if (!this.tBody) {
				return false;
			}
			if (!Array.isArray(this.columns)) {
				this.columns = [];
			}
			if (!Array.isArray(this.columnsReal)) {
				this.columnsReal = [];
			}
			if (typeof this.rowSpan !== 'number') {
				this.rowSpan = 1;
			}

			let columns = this.columns;
			let rowSpan = this.rowSpan;
			if (true == this.thead) {
				var thead = this.tHead;
				if (!thead || !thead.rows) {
					return false;
				}

				for (var i = (thead.rows.length - 1); i >= 0; i--) {
					thead.deleteRow(i);
				}

				while(rowSpan > 0) {
					var row = thead.insertRow(thead.rows.length);
					columns = this.columnsHeaders(row, columns, rowSpan);
	
					rowSpan--;
				}
			
			}

			//BODY
			var tbody = this.tBody;
			for (var i = (tbody.rows.length - 1); i >= 0; i--) tbody.deleteRow(i);

			//	DATASET VUOTO
			if (this.dataset !== undefined && (this.dataset == null || !this.dataset || (Array.isArray(this.dataset) && !this.dataset.length))) {
				var row = tbody.insertRow(tbody.rows.length);
				var cell = row.insertCell(0);
				cell.colSpan = (this.columnsReal && this.columnsReal.length) ? this.columnsReal.length : 1;
				cell.innerHTML = '<p class="lead text-center mt-3">' + this.lang.no_results + '</p>';
				return true;
			}

			// CICLO IL DATASET
			if (Array.isArray(this.dataset)) {
				for (var i = 0; i < this.dataset.length; i++) {
					this.insertRow(this.dataset[i]);
				}
			} else {
				for (var key in this.dataset) {
					if (Object.prototype.hasOwnProperty.call(this.dataset, key)) {
						this.insertRow(this.dataset[key]);
					}
				}
			}

			return;
		},

		insertRow: function(r) {
			var tbody = this.tBody;

			// NEW ROW
			var rowCount = tbody.rows.length;
			var row = tbody.insertRow(rowCount);
			var rclass = this.getRowStyle(r);
			if (rclass) row.className = rclass;
			if (this.autoindex!=null) row.setAttribute("name",r[this.autoindex]);

			var default_style = {
				textAlign: "center"
			};

			for (var l = 0; l < this.columnsReal.length; l++) {
				var d = this.columnsReal[l];
				if (d === undefined) {
					var cell = row.insertCell(-1);
					cell.className = "separator";
					continue;
				}
				var cell = row.insertCell(-1);
				var style = (d.style != null) ? d.style : default_style;
				for (var i in style) cell.style[i] = style[i];
				cell.innerHTML = (d.format != null) ? d.format(r) : r[d.field];
			}

			return;
		},

		setSearchForm: function(form_id) {
			if (!this.paginator || typeof this.paginator.setSearchForm !== 'function') {
				return this;
			}
			this.paginator.setSearchForm(form_id);
			if (typeof Search === 'function') {
				Search().bindDataGridForm(form_id);
			}

			return this;
		},

		clearSearch: function() {
			if (this.paginator && typeof this.paginator.clearSearch === 'function') {
				this.paginator.clearSearch();
			}

			return this;
		},

		destroy: function () {
			if (this.paginator && typeof this.paginator.destroy === 'function') {
				this.paginator.destroy();
			}
			this.paginator = null;
			this.table = null;
			this.tHead = null;
			this.tBody = null;
			this.dataset = [];
			this.index = {};
			if (this.div && this.div.length) {
				this.div.empty();
			}
			if (this.ID != null) {
				datagridID[this.ID] = null;
			}
			this.mounted = false;

			return this;
		},

		unmount: function () {
			return this.destroy();
		},

		ucwords: function(str) {
			if (!str) {
				return;
			}
			if (str == str.toUpperCase()) {
				return str;
			}

			return (str + '').replace(/^([a-z\u00E0-\u00FC])|\s+([a-z\u00E0-\u00FC])/g, function ($1) {

				return $1.toUpperCase();
			});
		},
	};

	var ext = (options && typeof options === 'object') ? options : {};
	let o = Object.assign({}, datagrid, ext);
	return o.init(div);
};

if (typeof window !== 'undefined') {
    window.Datagrid = Datagrid;
    if (typeof window.DataGrid === 'undefined') {
        window.DataGrid = Datagrid;
    }
}
