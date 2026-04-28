const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function thumbUrl(src, w, h) {
    if (!src || src.indexOf('/assets/imgs/') !== 0) { return src || ''; }
    return '/thumb.php?src=' + encodeURIComponent(src) + '&w=' + w + '&h=' + (h || w);
}

var AdminMaps = {
    initialized: false,
    root: null,
    grid: null,
    rows: [],
    rowsById: {},
    mapOptions: [],
    modalNode: null,
    modal: null,
    form: null,
    filtersForm: null,

    init: function () {
        if (this.initialized) return this;

        this.root = document.querySelector('#admin-page [data-admin-page="maps"]');
        if (!this.root) return this;

        this.form = this.root.querySelector('#admin-maps-form');
        this.modalNode = this.root.querySelector('#admin-maps-modal');
        this.filtersForm = document.getElementById('admin-maps-filters');
        if (!this.form || !this.modalNode || !document.getElementById('grid-admin-maps')) return this;

        this.modal = new bootstrap.Modal(this.modalNode);
        this.bind();
        this.bindFilters();
        this.initGrid();
        this.loadMapOptions();
        this.reload();

        this.initialized = true;
        return this;
    },

    bind: function () {
        var self = this;
        this.root.addEventListener('click', function (event) {
            var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
            if (!trigger) return;

            var action = String(trigger.getAttribute('data-action') || '').trim();
            if (!action) return;

            if (action === 'admin-maps-reload') {
                event.preventDefault();
                self.reload();
                return;
            }

            if (action === 'admin-maps-filters-reset') {
                event.preventDefault();
                if (self.filtersForm) { self.filtersForm.reset(); }
                self.reload();
                return;
            }

            if (action === 'admin-maps-create') {
                event.preventDefault();
                self.openCreate();
                return;
            }

            if (action === 'admin-maps-edit') {
                event.preventDefault();
                self.openEdit(trigger);
                return;
            }

            if (action === 'admin-maps-save') {
                event.preventDefault();
                self.save();
                return;
            }

            if (action === 'admin-maps-delete') {
                event.preventDefault();
                self.remove();
                return;
            }
        });
    },

    initGrid: function () {
        var self = this;
        this.grid = new Datagrid('grid-admin-maps', {
            name: 'AdminMaps',
            autoindex: 'id',
            orderable: true,
            thead: true,
            handler: { url: '/admin/maps/list', action: 'list' },
            nav: { display: 'bottom', urlupdate: 0, results: 20, page: 1 },
            onGetDataSuccess: function (response) {
                self.setRows(response && Array.isArray(response.dataset) ? response.dataset : []);
            },
            onGetDataError: function () {
                self.setRows([]);
            },
            columns: [
                { label: 'ID', field: 'id', sortable: true },
                {
                    label: 'Mappa',
                    field: 'name',
                    sortable: true,
                    style: { textAlign: 'left' },
                    format: function (row) {
                        var image = self.previewImage(row);
                        var name = self.e(row.name || '-');
                        var description = self.e(row.description || '');
                        return '<div class="d-flex align-items-center gap-2">'
                            + '<img src="' + thumbUrl(image, 88, 60) + '" alt="" width="44" height="30" class="rounded border border-secondary-subtle" style="object-fit:cover;">'
                            + '<div><b>' + name + '</b><div class="small text-muted">' + description + '</div></div>'
                            + '</div>';
                    }
                },
                {
                    label: 'Gerarchia',
                    sortable: false,
                    format: function (row) {
                        var parentId = parseInt(row.parent_map_id || 0, 10) || 0;
                        if (parentId <= 0) {
                            return '<span class="badge text-bg-light text-dark">Radice</span>';
                        }
                        var parentName = self.e(row.parent_map_name || ('#' + parentId));
                        return '<div class="small"><span class="text-muted">Dentro:</span> ' + parentName + '</div>';
                    }
                },
                { label: 'Posizione', field: 'position', sortable: true },
                {
                    label: 'Modalita',
                    field: 'render_mode',
                    sortable: true,
                    format: function (row) {
                        return self.renderModeBadge(row.render_mode);
                    }
                },
                {
                    label: 'Stato',
                    sortable: false,
                    format: function (row) {
                        var out = [];
                        out.push(parseInt(row.initial || 0, 10) === 1
                            ? '<span class="badge text-bg-primary">Iniziale</span>'
                            : '<span class="badge text-bg-secondary">Normale</span>');
                        if (parseInt(row.mobile || 0, 10) === 1) {
                            out.push('<span class="badge text-bg-warning">Mobile</span>');
                        }
                        return out.join(' ');
                    }
                },
                {
                    label: 'Azioni',
                    sortable: false,
                    format: function (row) {
                        var id = parseInt(row.id || 0, 10) || 0;
                        if (id <= 0) return '-';
                        return '<button class="btn btn-sm btn-outline-primary" data-action="admin-maps-edit" data-id="' + id + '">Modifica</button>';
                    }
                }
            ]
        });
    },

    bindFilters: function () {
        var self = this;
        if (!this.filtersForm) { return; }
        this.filtersForm.addEventListener('submit', function (event) {
            event.preventDefault();
            self.reload();
        });
    },

    buildFiltersPayload: function () {
        var payload = {};
        if (!this.filtersForm) { return payload; }
        var f = this.filtersForm.elements;
        var name = f.name ? String(f.name.value || '').trim() : '';
        var renderMode = f.render_mode ? String(f.render_mode.value || '').trim() : '';
        var initial = f.initial ? String(f.initial.value || '').trim() : '';
        var mobile = f.mobile ? String(f.mobile.value || '').trim() : '';
        if (name !== '')       { payload.name        = name; }
        if (renderMode !== '') { payload.render_mode = renderMode; }
        if (initial !== '')    { payload.initial     = parseInt(initial, 10); }
        if (mobile !== '')     { payload.mobile      = parseInt(mobile, 10); }
        return payload;
    },

    reload: function () {
        if (!this.grid || typeof this.grid.loadData !== 'function') return this;
        this.grid.loadData(this.buildFiltersPayload(), 20, 1, 'position|ASC');
        return this;
    },

    setRows: function (rows) {
        this.rows = Array.isArray(rows) ? rows.slice() : [];
        this.rowsById = {};
        for (var i = 0; i < this.rows.length; i++) {
            var id = parseInt(this.rows[i].id || 0, 10) || 0;
            if (id > 0) this.rowsById[id] = this.rows[i];
        }
        return this;
    },

    rowFromTrigger: function (trigger) {
        var id = parseInt(String(trigger.getAttribute('data-id') || '0'), 10) || 0;
        if (id <= 0) return null;
        return this.rowsById[id] || null;
    },

    loadMapOptions: function (callback) {
        var self = this;
        this.post('/admin/maps/list', { results: 500, page: 1, orderBy: 'name|ASC' }, function (response) {
            self.mapOptions = response && Array.isArray(response.dataset) ? response.dataset.slice() : [];
            if (typeof callback === 'function') {
                callback(self.mapOptions);
            }
        });
        return this;
    },

    populateParentOptions: function (selectedId, currentId) {
        var select = this.form ? this.form.querySelector('[name="parent_map_id"]') : null;
        if (!select) { return this; }

        var selected = parseInt(selectedId || 0, 10) || 0;
        var current = parseInt(currentId || 0, 10) || 0;
        var options = ['<option value="">Mappa radice</option>'];

        for (var i = 0; i < this.mapOptions.length; i++) {
            var row = this.mapOptions[i] || {};
            var id = parseInt(row.id || 0, 10) || 0;
            if (id <= 0 || id === current) {
                continue;
            }
            options.push(
                '<option value="' + id + '"' + (id === selected ? ' selected' : '') + '>'
                + this.e(row.name || ('Mappa #' + id))
                + '</option>'
            );
        }

        select.innerHTML = options.join('');
        return this;
    },

    openCreate: function () {
        this.fillForm({});
        this.populateParentOptions(0, 0);
        this.toggleDelete(false);
        this.modal.show();
        return this;
    },

    openEdit: function (trigger) {
        var row = this.rowFromTrigger(trigger);
        if (!row) return this;
        this.fillForm(row);
        this.populateParentOptions(row.parent_map_id || 0, row.id || 0);
        this.toggleDelete(true);
        this.modal.show();
        return this;
    },

    fillForm: function (row) {
        var data = row || {};
        this.setField('id', data.id || '');
        this.setField('name', data.name || '');
        this.setField('description', data.description || '');
        this.setField('position', data.position || '');
        this.setField('parent_map_id', data.parent_map_id || '');
        this.setField('mobile', (parseInt(data.mobile || 0, 10) === 1) ? '1' : '0');
        this.setField('initial', (parseInt(data.initial || 0, 10) === 1) ? '1' : '0');
        this.setField('icon', data.icon || '');
        this.setField('image', data.image || '');
        this.setField('meteo', data.meteo || '');
        this.setField('render_mode', this.normalizeRenderMode(data.render_mode || 'grid'));
        this.setStatusValue(data.status || '');
    },

    setStatusValue: function (value) {
        var select = this.form ? this.form.querySelector('[name="status"]') : null;
        if (!select) { return; }
        var normalized = String(value || '').trim();
        var prev = select.querySelector('[data-custom-status]');
        if (prev) { select.removeChild(prev); }
        select.value = normalized;
        if (select.value !== normalized) {
            var option = document.createElement('option');
            option.value = normalized;
            option.textContent = 'Personalizzato: ' + normalized;
            option.setAttribute('data-custom-status', '1');
            select.appendChild(option);
            select.value = normalized;
        }
    },

    toggleDelete: function (visible) {
        var button = this.root.querySelector('[data-action="admin-maps-delete"]');
        if (!button) return this;
        button.classList.toggle('d-none', visible !== true);
        return this;
    },

    setField: function (name, value) {
        var node = this.form.querySelector('[name="' + name + '"]');
        if (!node) return;
        node.value = value == null ? '' : String(value);
    },

    getField: function (name) {
        var node = this.form.querySelector('[name="' + name + '"]');
        return node ? String(node.value || '').trim() : '';
    },

    normalizeRenderMode: function (value) {
        var mode = String(value || '').trim().toLowerCase();
        return mode === 'visual' ? 'visual' : 'grid';
    },

    collectPayload: function () {
        var parentMapId = parseInt(this.getField('parent_map_id') || '0', 10) || 0;
        return {
            id: parseInt(this.getField('id') || '0', 10) || 0,
            name: this.getField('name'),
            description: this.getField('description'),
            status: this.getField('status'),
            position: parseInt(this.getField('position') || '0', 10) || 0,
            parent_map_id: parentMapId > 0 ? parentMapId : null,
            mobile: (this.getField('mobile') === '1') ? 1 : 0,
            initial: (this.getField('initial') === '1') ? 1 : 0,
            icon: this.getField('icon'),
            image: this.getField('image'),
            meteo: this.getField('meteo'),
            render_mode: this.normalizeRenderMode(this.getField('render_mode'))
        };
    },

    save: function () {
        var payload = this.collectPayload();
        if (!payload.name) {
            Toast.show({ body: 'Nome mappa obbligatorio.', type: 'warning' });
            return this;
        }

        var url = payload.id > 0 ? '/admin/maps/update' : '/admin/maps/create';
        var self = this;
        this.post(url, payload, function () {
            Toast.show({ body: 'Mappa salvata.', type: 'success' });
            self.modal.hide();
            self.loadMapOptions(function () {
                self.reload();
            });
        });
        return this;
    },

    remove: function () {
        var id = parseInt(this.getField('id') || '0', 10) || 0;
        if (id <= 0) return this;

        var self = this;
        Dialog('warning', {
            title: 'Conferma eliminazione',
            body: '<p>Vuoi eliminare questa mappa?</p>',
            buttons: [
                {
                    text: 'Annulla',
                    class: 'btn btn-secondary',
                    dismiss: true
                },
                {
                    text: 'Elimina',
                    class: 'btn btn-danger',
                    click: function () {
                        self.post('/admin/maps/delete', { id: id }, function () {
                            Toast.show({ body: 'Mappa eliminata.', type: 'success' });
                            self.modal.hide();
                            self.loadMapOptions(function () {
                                self.reload();
                            });
                        });
                    }
                }
            ]
        }).show();

        return this;
    },

    post: function (url, payload, onSuccess) {
        if (typeof Request !== 'function' || !Request.http || typeof Request.http.post !== 'function') {
            Toast.show({ body: 'Servizio non disponibile.', type: 'error' });
            return;
        }
        Request.http.post(url, payload || {}).then(function (response) {
            if (typeof onSuccess === 'function') onSuccess(response || null);
        }).catch(function (error) {
            var message = 'Operazione non riuscita.';
            if (typeof Request.getErrorMessage === 'function') {
                message = Request.getErrorMessage(error, message);
            }
            Toast.show({ body: message, type: 'error' });
        });
    },

    previewImage: function (row) {
        if (row && typeof row.image === 'string' && row.image.trim() !== '') return row.image.trim();
        if (row && typeof row.icon === 'string' && row.icon.trim() !== '') return row.icon.trim();
        return '/assets/imgs/defaults-images/default-map.png';
    },

    renderModeBadge: function (mode) {
        var raw = String(mode || '').trim().toLowerCase();
        if (raw !== 'grid' && raw !== 'visual') {
            return '<span class="badge text-bg-secondary">Legacy auto</span>';
        }
        var key = this.normalizeRenderMode(raw);
        if (key === 'visual') {
            return '<span class="badge text-bg-info">Visuale</span>';
        }
        return '<span class="badge text-bg-light text-dark">Griglia</span>';
    },

    e: function (value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
};

if (typeof window !== 'undefined') {
    globalWindow.AdminMaps = AdminMaps;
}
export { AdminMaps as AdminMaps };
export default AdminMaps;