const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

var AdminCurrencies = {
    initialized: false,
    root: null,
    form: null,
    grid: null,
    modalNode: null,
    modal: null,
    modalForm: null,
    editingRow: null,
    rows: [],
    rowsById: {},
    capabilities: {
        can_create: true,
        can_delete: true,
        single_currency_mode: false
    },

    init: function () {
        if (this.initialized) {
            return this;
        }

        this.root = document.querySelector('#admin-page [data-admin-page="currencies"]');
        if (!this.root) {
            return this;
        }

        this.form = this.root.querySelector('#admin-currencies-filters');
        this.modalNode = this.root.querySelector('#admin-currencies-modal');
        this.modalForm = this.root.querySelector('#admin-currencies-form');

        if (!this.form || !this.modalNode || !this.modalForm || !document.getElementById('grid-admin-currencies')) {
            return this;
        }

        this.modal = new bootstrap.Modal(this.modalNode);
        this.bind();
        this.initGrid();
        this.loadGrid();

        this.initialized = true;
        return this;
    },

    bind: function () {
        var self = this;

        this.form.addEventListener('submit', function (event) {
            event.preventDefault();
            self.loadGrid();
        });

        this.root.addEventListener('click', function (event) {
            var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
            if (!trigger) {
                return;
            }

            var action = String(trigger.getAttribute('data-action') || '').trim();
            if (!action) {
                return;
            }

            if (action === 'admin-currencies-reload') {
                event.preventDefault();
                self.loadGrid();
                return;
            }

            if (action === 'admin-currencies-create') {
                event.preventDefault();
                if (!self.canCreate()) {
                    Toast.show({
                        body: 'In modalita core puoi modificare solo la valuta predefinita.',
                        type: 'warning'
                    });
                    return;
                }
                self.openCreate();
                return;
            }

            if (action === 'admin-currencies-edit') {
                event.preventDefault();
                self.openEdit(trigger);
                return;
            }

            if (action === 'admin-currencies-save') {
                event.preventDefault();
                self.save();
                return;
            }

            if (action === 'admin-currencies-delete') {
                event.preventDefault();
                self.remove();
            }
        });
    },

    initGrid: function () {
        var self = this;

        this.grid = new Datagrid('grid-admin-currencies', {
            name: 'AdminCurrencies',
            autoindex: 'id',
            orderable: true,
            thead: true,
            handler: { url: this.endpointList(), action: 'list' },
            nav: { display: 'bottom', urlupdate: 0, results: 20, page: 1 },
            onGetDataSuccess: function (response) {
                self.applyCapabilities(response && response.properties && response.properties.capabilities
                    ? response.properties.capabilities
                    : null);
                self.setRows(response && Array.isArray(response.dataset) ? response.dataset : []);
            },
            onGetDataError: function () {
                self.applyCapabilities(null);
                self.setRows([]);
            },
            columns: [
                { label: 'ID', field: 'id', sortable: true },
                {
                    label: 'Valuta',
                    field: 'name',
                    sortable: true,
                    style: { textAlign: 'left' },
                    format: function (row) {
                        return self.renderCurrencyCell(row);
                    }
                },
                { label: 'Codice', field: 'code', sortable: true },
                {
                    label: 'Stato',
                    sortable: false,
                    format: function (row) {
                        var out = [];
                        out.push((parseInt(row.is_active || 0, 10) === 1)
                            ? '<span class="badge text-bg-success">Attiva</span>'
                            : '<span class="badge text-bg-secondary">Inattiva</span>');
                        if (parseInt(row.is_default || 0, 10) === 1) {
                            out.push('<span class="badge text-bg-primary">Default</span>');
                        }
                        return out.join(' ');
                    }
                },
                {
                    label: 'Azioni',
                    sortable: false,
                    format: function (row) {
                        var id = parseInt(row.id || 0, 10) || 0;
                        if (id <= 0) {
                            return '-';
                        }
                        return '<button type="button" class="btn btn-sm btn-outline-primary" data-action="admin-currencies-edit" data-id="' + id + '">Modifica</button>';
                    }
                }
            ]
        });
    },

    loadGrid: function () {
        if (!this.grid || typeof this.grid.loadData !== 'function') {
            return this;
        }

        this.grid.loadData(this.buildFiltersPayload(), 20, 1, 'name|ASC');
        return this;
    },

    buildFiltersPayload: function () {
        var query = {};
        var code = this.getFilterValue('code');
        var name = this.getFilterValue('name');
        var status = String(this.getFilterValue('status') || 'all').trim().toLowerCase();
        var isDefault = String(this.getFilterValue('is_default') || 'all').trim().toLowerCase();

        if (code !== '') {
            query.code = code.toUpperCase();
        }
        if (name !== '') {
            query.name = name;
        }
        if (status === 'active') {
            query.is_active = 1;
        } else if (status === 'inactive') {
            query.is_active = 0;
        }
        if (isDefault === 'yes') {
            query.is_default = 1;
        } else if (isDefault === 'no') {
            query.is_default = 0;
        }

        return query;
    },

    getFilterValue: function (name) {
        if (!this.form || !this.form.elements || !this.form.elements[name]) {
            return '';
        }
        return String(this.form.elements[name].value || '').trim();
    },

    setRows: function (rows) {
        this.rows = Array.isArray(rows) ? rows.slice() : [];
        this.rowsById = {};
        for (var i = 0; i < this.rows.length; i++) {
            var id = parseInt(this.rows[i].id || 0, 10) || 0;
            if (id > 0) {
                this.rowsById[id] = this.rows[i];
            }
        }
        return this;
    },

    rowFromTrigger: function (trigger) {
        var id = parseInt(String(trigger.getAttribute('data-id') || '0'), 10) || 0;
        if (id <= 0) {
            return null;
        }
        return this.rowsById[id] || null;
    },

    openCreate: function () {
        this.editingRow = null;
        this.fillModalForm({});
        this.toggleDelete(false);
        this.modal.show();
        return this;
    },

    openEdit: function (trigger) {
        var row = this.rowFromTrigger(trigger);
        if (!row) {
            return this;
        }
        this.editingRow = row;
        this.fillModalForm(row);
        this.toggleDelete(true, row);
        this.modal.show();
        return this;
    },

    fillModalForm: function (row) {
        var data = row || {};
        this.setModalField('id', data.id || '');
        this.setModalField('code', data.code || '');
        this.setModalField('name', data.name || '');
        this.setModalField('symbol', data.symbol || '');
        this.setModalField('image', data.image || '');
        this.setModalField('is_default', (parseInt(data.is_default || 0, 10) === 1) ? '1' : '0');
        this.setModalField('is_active', (parseInt(data.is_active || 0, 10) === 1) ? '1' : '0');
        return this;
    },

    setModalField: function (name, value) {
        if (!this.modalForm) {
            return this;
        }
        var node = this.modalForm.querySelector('[name="' + name + '"]');
        if (!node) {
            return this;
        }
        node.value = value == null ? '' : String(value);
        return this;
    },

    getModalField: function (name) {
        if (!this.modalForm) {
            return '';
        }
        var node = this.modalForm.querySelector('[name="' + name + '"]');
        return node ? String(node.value || '').trim() : '';
    },

    toggleDelete: function (visible, row) {
        var button = this.root.querySelector('[data-action="admin-currencies-delete"]');
        if (!button) {
            return this;
        }
        var hide = (visible !== true) || !this.canDelete();
        if (!hide && row) {
            hide = (parseInt(row.is_default || 0, 10) === 1);
        }
        button.classList.toggle('d-none', hide);
        return this;
    },

    collectPayload: function () {
        return {
            id: parseInt(this.getModalField('id') || '0', 10) || 0,
            code: this.getModalField('code').toUpperCase(),
            name: this.getModalField('name'),
            symbol: this.getModalField('symbol'),
            image: this.getModalField('image'),
            is_default: (this.getModalField('is_default') === '1') ? 1 : 0,
            is_active: (this.getModalField('is_active') === '1') ? 1 : 0
        };
    },

    save: function () {
        var payload = this.collectPayload();
        if (!payload.code || !payload.name) {
            Toast.show({ body: 'Codice e nome sono obbligatori.', type: 'warning' });
            return this;
        }
        if (!this.canCreate() && payload.id <= 0) {
            Toast.show({
                body: 'In modalita core e possibile modificare solo la valuta predefinita.',
                type: 'warning'
            });
            return this;
        }

        var self = this;
        var url = payload.id > 0 ? this.endpointUpdate() : this.endpointCreate();
        this.post(url, payload, function () {
            Toast.show({ body: 'Valuta salvata.', type: 'success' });
            self.modal.hide();
            self.reloadGridKeepingPosition();
        });
        return this;
    },

    remove: function () {
        var id = parseInt(this.getModalField('id') || '0', 10) || 0;
        if (id <= 0) {
            return this;
        }
        var row = this.rowsById[id] || this.editingRow;
        if (row && parseInt(row.is_default || 0, 10) === 1) {
            Toast.show({
                body: 'La valuta predefinita non puo essere eliminata. Imposta prima un altra valuta come default.',
                type: 'warning'
            });
            return this;
        }

        var self = this;
        Dialog('warning', {
            title: 'Conferma eliminazione',
            body: '<p>Vuoi eliminare questa valuta?</p>',
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
                        self.post(self.endpointDelete(), { id: id }, function () {
                            Toast.show({ body: 'Valuta eliminata.', type: 'success' });
                            self.modal.hide();
                            self.reloadGridKeepingPosition();
                        });
                    }
                }
            ]
        }).show();

        return this;
    },

    reloadGridKeepingPosition: function () {
        if (this.grid && typeof this.grid.reloadData === 'function') {
            this.grid.reloadData();
            return this;
        }
        return this.loadGrid();
    },

    applyCapabilities: function (capabilities) {
        var fallback = {
            can_create: true,
            can_delete: true,
            single_currency_mode: false
        };
        var raw = (capabilities && typeof capabilities === 'object') ? capabilities : fallback;
        this.capabilities = {
            can_create: (raw.can_create === true || String(raw.can_create) === '1'),
            can_delete: (raw.can_delete === true || String(raw.can_delete) === '1'),
            single_currency_mode: (raw.single_currency_mode === true || String(raw.single_currency_mode) === '1')
        };

        var createBtn = this.root ? this.root.querySelector('[data-action="admin-currencies-create"]') : null;
        if (createBtn) {
            createBtn.classList.toggle('d-none', !this.canCreate());
        }

        if (this.modalForm) {
            var defaultSelect = this.modalForm.querySelector('[name="is_default"]');
            if (defaultSelect) {
                if (this.capabilities.single_currency_mode) {
                    defaultSelect.value = '1';
                    defaultSelect.setAttribute('disabled', 'disabled');
                } else {
                    defaultSelect.removeAttribute('disabled');
                }
            }
        }

        return this;
    },

    canCreate: function () {
        return this.capabilities && this.capabilities.can_create === true;
    },

    canDelete: function () {
        return this.capabilities && this.capabilities.can_delete === true;
    },

    endpointList: function () {
        var endpoints = globalWindow.LogeonModuleEndpoints || {};
        return String(endpoints.currenciesList || '/admin/core-currencies/list');
    },

    endpointCreate: function () {
        var endpoints = globalWindow.LogeonModuleEndpoints || {};
        return String(endpoints.currenciesCreate || '/admin/core-currencies/create');
    },

    endpointUpdate: function () {
        var endpoints = globalWindow.LogeonModuleEndpoints || {};
        return String(endpoints.currenciesUpdate || '/admin/core-currencies/update');
    },

    endpointDelete: function () {
        var endpoints = globalWindow.LogeonModuleEndpoints || {};
        return String(endpoints.currenciesDelete || '/admin/core-currencies/delete');
    },

    post: function (url, payload, onSuccess) {
        if (typeof Request !== 'function' || !Request.http || typeof Request.http.post !== 'function') {
            Toast.show({ body: 'Servizio non disponibile.', type: 'error' });
            return this;
        }

        Request.http.post(url, payload || {}).then(function (response) {
            if (typeof onSuccess === 'function') {
                onSuccess(response || null);
            }
        }).catch(function (error) {
            var message = 'Operazione non riuscita.';
            if (typeof Request.getErrorMessage === 'function') {
                message = Request.getErrorMessage(error, message);
            }
            Toast.show({ body: message, type: 'error' });
        });

        return this;
    },

    renderCurrencyCell: function (row) {
        var name = this.e(row.name || '-');
        var symbol = this.e(row.symbol || '');
        var code = this.e(row.code || '');
        var image = this.e(this.previewImage(row));
        var label = symbol ? (symbol + ' ') : '';
        label += code;

        return '<div class="d-flex align-items-center gap-2">'
            + '<img src="' + image + '" alt="" width="36" height="36" class="rounded border border-secondary-subtle" style="object-fit:cover;">'
            + '<div>'
            + '<b>' + name + '</b>'
            + (label ? ('<div class="small text-muted">' + this.e(label) + '</div>') : '')
            + '</div>'
            + '</div>';
    },

    previewImage: function (row) {
        if (row && typeof row.image === 'string' && row.image.trim() !== '') {
            return row.image.trim();
        }
        return '/assets/imgs/defaults-images/default-icon.png';
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
    globalWindow.AdminCurrencies = AdminCurrencies;
}
export { AdminCurrencies as AdminCurrencies };
export default AdminCurrencies;

