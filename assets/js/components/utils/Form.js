/**
 * Utility per la gestione di form jQuery: lettura, scrittura, reset e validazione.
 * Gestisce input standard, select (singolo e multiplo), checkbox, radio, file e campi rich text.
 * Dipende da jQuery (`$`).
 *
 * Uso tipico:
 * ```js
 * var f = Form();
 * var fields = f.getFields('#my-form');   // { name: 'Alice', role: 'admin', ... }
 * f.setFields('#my-form', { name: 'Bob', role: 'user' });
 * f.resetField('#my-form');
 * ```
 *
 * @returns {Object} Istanza Form con metodi getFields/setFields/checkForm/resetField/setFieldsInDiv.
 */
function Form() {
    var base = {
        form: null,
        richTextSelector: '.summernote, .richtext-editor',
        isRichTextInput: function (input) {
            return !!(input && input.length && (input.hasClass('summernote') || input.hasClass('richtext-editor')));
        },

        /**
         * Legge tutti i valori degli input del form come oggetto chiave-valore.
         * @param {string|jQuery|HTMLElement} form - Selettore, jQuery o elemento DOM.
         * @returns {Object.<string, string|number|boolean|string[]|null>} Mappa nome → valore.
         */
        getFields: function (form) {
            if (!this.checkForm(form)) {
                return {};
            }
            return this.getFormInputs();
        },

        /**
         * Imposta i valori degli input del form dal dataset fornito.
         * @param {string|jQuery|HTMLElement} form
         * @param {Object.<string, *>} dataset - Mappa nome → valore da applicare agli input.
         * @returns {Object} this
         */
        setFields: function (form, dataset) {
            if (!this.checkForm(form)) {
                return this;
            }
            var self = this;

            if (dataset == null || typeof dataset !== 'object') {
                dataset = {};
            }

            this.form.find(':input').each(function () {
                var input = $(this);
                var name = input.attr('name');
                if (!name) {
                    return;
                }

                var hasValue = Object.prototype.hasOwnProperty.call(dataset, name);
                var value = hasValue ? dataset[name] : null;

                if (input.is('select') && input.prop('multiple')) {
                    if (Array.isArray(value)) {
                        input.val(value.map(function (item) { return String(item); }));
                    } else if (value == null || value === '') {
                        input.val([]);
                    } else {
                        input.val([String(value)]);
                    }
                    return;
                }

                if (input.is('select') && !input.prop('multiple')) {
                    if (value == null || value === '') {
                        input.find('option:first').prop('selected', true);
                    } else {
                        input.val(value);
                    }
                    return;
                }

                if (input.is(':checkbox')) {
                    input.prop('checked', value == 1 || value === true || value === '1' || value === 'true' || value === 'on');
                    return;
                }

                if (input.is(':radio')) {
                    input.prop('checked', value != null && String(value) === String(input.val()));
                    return;
                }

                if (self.isRichTextInput(input)) {
                    if (typeof input.summernote === 'function') {
                        input.summernote('code', value != null ? String(value) : '');
                    } else {
                        input.val(value != null ? value : '');
                    }
                    return;
                }

                input.val(value != null ? value : '');
            }).trigger('change');

            return this;
        },

        setFieldsInDiv: function (container, dataset) {
            if (!container || !dataset || typeof dataset !== 'object') {
                return this;
            }

            for (var key in dataset) {
                if (!Object.prototype.hasOwnProperty.call(dataset, key)) {
                    continue;
                }

                var value = dataset[key];
                if (value === null || typeof value === 'undefined' || value === '') {
                    value = '-';
                }

                container.find('[name="' + key + '"]').text(value);
            }

            return this;
        },

        /**
         * Resetta il form: svuota input, deseleziona checkbox/radio, ripristina select al primo option.
         * Non tocca i campi `_csrf` e `csrf_token`.
         * @param {string|jQuery|HTMLElement} form
         * @returns {Object} this
         */
        resetField: function (form) {
            if (!this.checkForm(form)) {
                return this;
            }

            if (this.form.length && this.form[0] && typeof this.form[0].reset === 'function') {
                this.form[0].reset();
            }

            this.form.find(':checkbox, :radio').prop('checked', false);
            this.form.find('input[type="hidden"]').each(function () {
                var input = $(this);
                var name = String(input.attr('name') || '').trim();
                if (name === '_csrf' || name === 'csrf_token') {
                    return;
                }
                input.val('');
            });

            this.form.find('select').each(function () {
                var select = $(this);
                if (select.prop('multiple')) {
                    select.val([]);
                } else {
                    select.find('option:first').prop('selected', true);
                }
            });

            this.form.find(this.richTextSelector).each(function () {
                var editor = $(this);
                if (typeof editor.summernote === 'function') {
                    editor.summernote('code', '');
                } else {
                    editor.val('');
                }
            });

            this.form.find('input[type="hidden"]').trigger('change');
            return this;
        },

        getFormInputs: function () {
            var fields = {};
            var self = this;

            this.form.find(':input').not(':button,:submit,:reset').each(function () {
                var input = $(this);
                if (input.is(':disabled')) {
                    return;
                }

                if (input.is(':file')) {
                    return;
                }

                if (!input.attr('id') && !input.attr('name')) {
                    return;
                }

                var name = input.attr('name');
                if (name == null || String(name).trim() === '') {
                    var idAttr = input.attr('id');
                    if (!idAttr) {
                        return;
                    }
                    name = idAttr.split('_').pop();
                }
                if (!name) {
                    return;
                }

                if (input.is(':checkbox')) {
                    fields[name] = input.prop('checked') ? 1 : 0;
                    return;
                }

                if (input.is(':radio')) {
                    if (input.prop('checked')) {
                        fields[name] = input.val();
                    } else if (typeof fields[name] === 'undefined') {
                        fields[name] = null;
                    }
                    return;
                }

                if (input.is('select') && input.prop('multiple')) {
                    var values = input.val();
                    fields[name] = Array.isArray(values) ? values : [];
                    return;
                }

                if (self.isRichTextInput(input) && typeof input.summernote === 'function') {
                    var html = input.summernote('code');
                    fields[name] = (html === '<p><br></p>') ? '' : html;
                    return;
                }

                fields[name] = input.val();
            });

            return fields;
        },

        /**
         * Risolve e valida il riferimento al form; imposta `this.form` se valido.
         * @param {string|jQuery|HTMLElement} form
         * @returns {jQuery|false} L'oggetto jQuery del form, o false se non trovato.
         */
        checkForm: function (form) {
            if (form == null) {
                this._showError('Form non assegnata', 'Non e stata assegnata nessuna form.');
                return false;
            }

            var jForm = null;
            if (typeof window.$ !== 'undefined' && form && form.jquery) {
                jForm = form;
            } else if (typeof HTMLElement !== 'undefined' && form instanceof HTMLElement) {
                jForm = $(form);
            } else {
                var selector = String(form).trim();
                if (selector === '') {
                    this._showError('Form non assegnata', 'Non e stata assegnata nessuna form.');
                    return false;
                }
                if (selector.charAt(0) !== '#') {
                    selector = '#' + selector;
                }
                jForm = $(selector);
            }

            if (!jForm || jForm.length === 0) {
                this._showError('Form non trovata', 'Non e stata trovata la form assegnata.');
                return false;
            }

            this.form = jForm;
            return this.form;
        },

        _showError: function (title, body) {
            if (typeof window !== 'undefined' && typeof window.Dialog === 'function') {
                window.Dialog('danger', {
                    title: title,
                    body: body
                }).show();
                return;
            }

            if (typeof console !== 'undefined' && typeof console.error === 'function') {
                console.error('[Form] ' + title + ': ' + body);
            }
        }
    };

    return base;
}

if (typeof window !== 'undefined') {
    window.Form = Form;
}
