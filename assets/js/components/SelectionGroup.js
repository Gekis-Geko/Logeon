function SelectionGroup(input, extension) {
    var ext = (extension && typeof extension === 'object') ? Object.assign({}, extension) : {};
    var mode = String(ext.mode || 'single').toLowerCase() === 'multiple' ? 'multiple' : 'single';
    delete ext.mode;

    var inputRef = ext.input || (typeof window !== 'undefined' && typeof window.$ === 'function' ? window.$(input) : null);
    delete ext.input;

    var base = {
        mode: mode,
        mounted: false,
        div: null,
        class: null,
        btnClass: null,
        groupClass: null,
        vertical: false,
        input: inputRef,
        options: [],
        _eventNs: mode === 'multiple' ? '.checkgroup' : '.radiogroup',
        _groupClassName: mode === 'multiple' ? 'check-group' : 'radio-group',
        _inputTypeSwapped: false,
        _originalInputType: null,
        _originalMultiple: null,
        _createdWrapper: false,
        _destroyed: false,

        init: function () {
            return this.mount();
        },

        mount: function () {
            var self = this;
            if (!this.input || !this.input.length) {
                return this;
            }
            this._destroyed = false;
            if (this.mounted === true) {
                this.onChangeValue();
                return this;
            }

            var existing = this.input.data('__selectionGroupInstance');
            if (existing && existing !== this && typeof existing.destroy === 'function') {
                existing.destroy();
            }

            this.prepareInput();
            this.prepareContainer();
            this.buildHTML();

            this.input.off('change' + this._eventNs).on('change' + this._eventNs, function () {
                self.onChangeValue();
            });
            this.onChangeValue();
            this.input.data('__selectionGroupInstance', this);
            this.mounted = true;

            return this;
        },

        prepareInput: function () {
            if (this._originalMultiple === null) {
                this._originalMultiple = (this.input.prop('multiple') === true);
            }

            if (this.mode === 'multiple') {
                this.input.hide();
                this.input.attr('multiple', true);
                return this;
            }

            if (this.input.is('input')) {
                this._originalInputType = this.input.attr('type') || 'text';
                this.input.attr('type', 'hidden');
                this._inputTypeSwapped = true;
            } else {
                this.input.hide();
            }

            return this;
        },

        prepareContainer: function () {
            if (this.input.parent().hasClass(this._groupClassName)) {
                this.div = this.input.parent();
                this._createdWrapper = false;
            } else {
                this.input.wrap("<div class='" + this._groupClassName + "'></div>");
                this.div = this.input.parent();
                this._createdWrapper = true;
            }

            var name = this.input.attr('name');
            if (name != null && name !== '') {
                this.div.attr('name', name);
            }
            if (this.class != null) {
                this.div.addClass(this.class);
            }

            return this;
        },

        normalizeValues: function (values) {
            var normalized = [];
            if (values == null) {
                return normalized;
            }
            if (!Array.isArray(values)) {
                values = [values];
            }

            for (var i = 0; i < values.length; i++) {
                var value = String(values[i]);
                if (normalized.indexOf(value) === -1) {
                    normalized.push(value);
                }
            }

            return normalized;
        },

        select: function (value) {
            if (!this.checkEnabled()) {
                return this;
            }

            var target = String(value);
            if (this.mode === 'multiple') {
                var selected = this.normalizeValues(this.input.val());
                if (selected.indexOf(target) !== -1) {
                    return this;
                }
                selected.push(target);
                this.input.val(selected).change();
                this.onSelect(target);
                return this;
            }

            var currentValue = this.input.val();
            currentValue = (currentValue == null) ? '' : String(currentValue);
            if (target === currentValue) {
                return this;
            }

            this.input.val(target).change();
            this.onSelect(target);
            return this;
        },

        unselect: function (value) {
            if (this.mode !== 'multiple') {
                return this;
            }
            if (!this.checkEnabled()) {
                return this;
            }

            var selected = this.normalizeValues(this.input.val());
            var target = String(value);
            var index = selected.indexOf(target);
            if (index !== -1) {
                selected.splice(index, 1);
                this.input.val(selected).change();
                this.onUnselect(target);
            }

            return this;
        },

        onSelect: function () {},
        onUnselect: function () {},

        setOptions: function (options) {
            if (!Array.isArray(options)) {
                options = [];
            }
            this.options = options.slice();
            if (this.mounted === true) {
                this.buildButtons();
            }
            return this;
        },

        value: function () {
            if (!this.input || !this.input.length) {
                return (this.mode === 'multiple') ? [] : '';
            }
            if (this.mode === 'multiple') {
                return this.normalizeValues(this.input.val());
            }
            var current = this.input.val();
            return (current == null) ? '' : String(current);
        },

        getValue: function () {
            return this.value();
        },

        setValue: function (value) {
            if (!this.input || !this.input.length) {
                return this;
            }
            if (this.mode === 'multiple') {
                this.input.val(this.normalizeValues(value)).change();
                return this;
            }

            var normalized = (value == null) ? '' : String(value);
            this.input.val(normalized).change();
            return this;
        },

        refresh: function () {
            if (!this.mounted) {
                return this;
            }
            this.onChangeValue();
            return this;
        },

        setDisabled: function (disabled) {
            if (!this.input || !this.input.length) {
                return this;
            }
            this.input.prop('disabled', disabled === true);
            this.checkEnabled();
            return this;
        },

        buildHTML: function () {
            if (this.div == null || !this.div.length) {
                return false;
            }

            var group = this.vertical === false ? 'btn-group' : 'btn-group-vertical';
            var optionsWrap = this.div.find("div[name=options]").first();
            if (!optionsWrap.length) {
                optionsWrap = $("<div name='options'></div>").appendTo(this.div);
            }

            optionsWrap.removeClass('btn-group btn-group-vertical').addClass(group);
            if (this.groupClass != null) {
                optionsWrap.addClass(this.groupClass);
            }

            this.buildButtons();
            this.checkEnabled();

            return true;
        },

        buildButtons: function () {
            var self = this;
            if (this.div == null || !this.div.length) {
                return this;
            }

            var optionsWrap = this.div.find("div[name=options]").first().empty();
            var validValues = [];
            var currentValue = this.input.val();
            currentValue = (currentValue == null) ? '' : String(currentValue);
            var selectedValues = this.normalizeValues(this.input.val());
            var useSelect = this.input.is('select');

            if (useSelect) {
                this.input.empty();
            }

            for (var i = 0; i < this.options.length; i++) {
                var elem = this.options[i];
                if (!elem || typeof elem.value === 'undefined' || elem.value === null) {
                    continue;
                }

                var value = String(elem.value);
                var button = this.formatButton(elem);
                if (this.btnClass != null) {
                    button.addClass(this.btnClass);
                }
                optionsWrap.append(button);
                validValues.push(value);

                if (useSelect) {
                    this.buildOption(elem);
                }
            }

            optionsWrap.find('button').off('click' + this._eventNs).on('click' + this._eventNs, function () {
                var value = String($(this).attr('data-value'));
                if (self.mode === 'multiple') {
                    var isActive = $(this).hasClass('active');
                    if (isActive) {
                        self.unselect(value);
                    } else {
                        self.select(value);
                    }
                    return;
                }
                self.select(value);
            });

            if (this.mode === 'multiple') {
                var normalizedSelected = [];
                for (var n = 0; n < selectedValues.length; n++) {
                    if (validValues.indexOf(selectedValues[n]) !== -1) {
                        normalizedSelected.push(selectedValues[n]);
                    }
                }
                this.input.val(normalizedSelected);
            } else {
                if (currentValue !== '' && validValues.indexOf(currentValue) === -1) {
                    currentValue = '';
                }
                this.input.val(currentValue);
            }

            this.onChangeValue();
            return this;
        },

        buildOption: function (elem) {
            var label = (elem.label == null) ? elem.value : elem.label;
            var value = String(elem.value);
            var option = $('<option></option>').attr('value', value).text(String(label));
            if (elem.disabled === true) {
                option.prop('disabled', true);
            }
            option.appendTo(this.input);
            return this;
        },

        formatButton: function (elem) {
            var label = (elem.label == null) ? elem.value : elem.label;
            var value = String(elem.value);
            var style = (elem.style == null) ? 'primary' : String(elem.style);
            if (!/^[a-z0-9_-]+$/i.test(style)) {
                style = 'primary';
            }

            return $('<button type="button"></button>')
                .addClass('btn btn-' + style)
                .attr('data-value', value)
                .attr('data-option-disabled', (elem && elem.disabled === true) ? '1' : '0')
                .prop('disabled', elem && elem.disabled === true)
                .text(String(label));
        },

        onChangeValue: function () {
            if (!this.div || !this.div.length) {
                return;
            }

            if (this.mode === 'multiple') {
                var values = this.normalizeValues(this.input.val());
                this.div.find("div[name=options] button").each(function () {
                    var button = $(this);
                    var value = String(button.attr('data-value'));
                    button.toggleClass('active', values.indexOf(value) !== -1);
                });
            } else {
                var value = this.input.val();
                value = (value == null) ? '' : String(value);
                this.div.find("div[name=options] button").each(function () {
                    var button = $(this);
                    var buttonValue = String(button.attr('data-value'));
                    button.toggleClass('active', value !== '' && buttonValue === value);
                });
            }

            this.checkEnabled();
        },

        checkEnabled: function () {
            var disabled = (this.input.prop('disabled') === true);
            if (disabled) {
                this.disable();
            } else {
                this.enable();
            }
            return !disabled;
        },

        enable: function () {
            if (!this.div || !this.div.length) {
                return this;
            }
            var buttons = this.div.find("div[name=options] button");
            buttons.removeAttr('readonly');
            buttons.each(function () {
                var button = $(this);
                if (button.attr('data-option-disabled') === '1') {
                    button.attr('disabled', true);
                    return;
                }
                button.removeAttr('disabled');
            });
            return this;
        },

        disable: function () {
            if (!this.div || !this.div.length) {
                return this;
            }
            this.div.find("div[name=options] button").attr('readonly', true).attr('disabled', true);
            return this;
        },

        unmount: function () {
            if (!this.input || !this.input.length) {
                return this;
            }

            this.input.off('change' + this._eventNs);
            if (this.div && this.div.length) {
                this.div.find("div[name=options] button").off('click' + this._eventNs);
                this.div.find("div[name=options]").remove();
            }

            if (this._originalMultiple === true) {
                this.input.attr('multiple', true);
            } else {
                this.input.removeAttr('multiple');
            }

            if (this._inputTypeSwapped && this.input.is('input')) {
                this.input.attr('type', this._originalInputType || 'text');
            } else {
                this.input.show();
            }

            if (typeof this.input.removeData === 'function') {
                this.input.removeData('__selectionGroupInstance');
            }
            this.mounted = false;

            return this;
        },

        destroy: function () {
            this._destroyed = true;
            return this.unmount();
        }
    };

    var out = Object.assign({}, base, ext);
    return out.init();
}

if (typeof window !== 'undefined') {
    window.SelectionGroup = SelectionGroup;
}
