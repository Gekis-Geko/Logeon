function switchGroupResolveJQuery() {
    if (typeof window !== 'undefined' && typeof window.$ === 'function') {
        return window.$;
    }
    if (typeof $ === 'function') {
        return $;
    }
    return null;
}

function switchGroupNormalizeValue(value, fallback) {
    if (value === null || typeof value === 'undefined') {
        return String(fallback);
    }
    return String(value);
}

function switchGroupGetPresetLabels(preset) {
    var key = String(preset || 'onOff').toLowerCase();

    if (key === 'yesno') {
        return ['Si', 'No'];
    }
    if (key === 'activeinactive') {
        return ['Attivo', 'Disattivo'];
    }
    if (key === 'enableddisabled') {
        return ['Abilitato', 'Disabilitato'];
    }

    return ['On', 'Off'];
}

function switchGroupStripHtml(value) {
    return String(value || '')
        .replace(/<[^>]*>/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

function switchGroupSetLabelContent(node, content, asHtml, fallbackAria) {
    if (!node || !node.length) {
        return;
    }

    if (asHtml === true) {
        node.html(content);
    } else {
        node.text(String(content));
    }

    var aria = switchGroupStripHtml(content);
    if (aria === '') {
        aria = String(fallbackAria || '');
    }
    if (aria !== '') {
        node.attr('aria-label', aria);
    }
}

function switchGroupBuildFallback(input) {
    return {
        input: input,
        mounted: false,
        trueValue: '1',
        falseValue: '0',
        init: function () { return this.mount(); },
        mount: function () { this.mounted = true; return this; },
        unmount: function () { this.mounted = false; return this; },
        destroy: function () { return this.unmount(); },
        refresh: function () { return this; },
        value: function () {
            if (!this.input || typeof this.input.val !== 'function') {
                return '';
            }
            var current = this.input.val();
            return (current == null) ? '' : String(current);
        },
        getValue: function () { return this.value(); },
        setValue: function (value) {
            if (this.input && typeof this.input.val === 'function') {
                this.input.val((value == null) ? '' : String(value));
                var el = this.input[0];
                if (el && typeof el.dispatchEvent === 'function') {
                    el.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }
            return this;
        },
        setOn: function () { return this.setValue(this.trueValue); },
        setOff: function () { return this.setValue(this.falseValue); },
        isOn: function () { return this.value() === this.trueValue; },
        isOff: function () { return this.value() === this.falseValue; },
        toggle: function () {
            if (this.isOn()) {
                return this.setOff();
            }
            return this.setOn();
        },
        checkEnabled: function () {
            if (!this.input || typeof this.input.prop !== 'function') {
                return false;
            }
            return this.input.prop('disabled') !== true;
        },
        enable: function () {
            if (this.input && typeof this.input.prop === 'function') {
                this.input.prop('disabled', false);
            }
            return this;
        },
        disable: function () {
            if (this.input && typeof this.input.prop === 'function') {
                this.input.prop('disabled', true);
            }
            return this;
        },
        setDisabled: function (disabled) {
            if (disabled === true) {
                return this.disable();
            }
            return this.enable();
        }
    };
}

function SwitchGroup(input, extension) {
    var jq = switchGroupResolveJQuery();
    var ext = (extension && typeof extension === 'object') ? Object.assign({}, extension) : {};
    var inputRef = (ext.input && jq) ? jq(ext.input) : (jq ? jq(input) : null);

    var labels = switchGroupGetPresetLabels(ext.preset);
    var trueLabel = String((ext.trueLabel != null) ? ext.trueLabel : labels[0]);
    var falseLabel = String((ext.falseLabel != null) ? ext.falseLabel : labels[1]);
    var labelsAsHtml = (ext.labelsAsHtml === true);
    var trueLabelIsHtml = (labelsAsHtml || ext.trueLabelIsHtml === true);
    var falseLabelIsHtml = (labelsAsHtml || ext.falseLabelIsHtml === true);
    var trueValue = switchGroupNormalizeValue(ext.trueValue, '1');
    var falseValue = switchGroupNormalizeValue(ext.falseValue, '0');
    var defaultValue = (typeof ext.defaultValue !== 'undefined' && ext.defaultValue !== null)
        ? String(ext.defaultValue)
        : trueValue;
    var showLabels = (ext.showLabels !== false);

    if (!jq || !inputRef || !inputRef.length) {
        var fallback = switchGroupBuildFallback(inputRef);
        fallback.trueValue = trueValue;
        fallback.falseValue = falseValue;
        return fallback.init();
    }

    var ns = '.switchgroup';

    var control = {
        input: inputRef,
        mounted: false,
        trueValue: trueValue,
        falseValue: falseValue,
        defaultValue: defaultValue,
        trueLabel: trueLabel,
        falseLabel: falseLabel,
        trueLabelIsHtml: trueLabelIsHtml,
        falseLabelIsHtml: falseLabelIsHtml,
        showLabels: showLabels,
        container: null,
        switchInput: null,
        labelOn: null,
        labelOff: null,

        init: function () {
            return this.mount();
        },

        mount: function () {
            var existingSelection = this.input.data('__selectionGroupInstance');
            if (existingSelection && typeof existingSelection.destroy === 'function') {
                existingSelection.destroy();
            }

            var existing = this.input.data('__switchGroupInstance');
            if (existing && existing !== this && typeof existing.destroy === 'function') {
                existing.destroy();
            }

            this.buildUI();
            this.bindEvents();

            if (!this.hasValue()) {
                this.setValue(this.defaultValue);
            } else {
                this.syncFromInput();
            }

            this.input.data('__switchGroupInstance', this);
            this.mounted = true;
            return this;
        },

        hasValue: function () {
            var current = this.input.val();
            return !(current === null || typeof current === 'undefined' || String(current) === '');
        },

        buildUI: function () {
            this.input.addClass('d-none');

            var next = this.input.next('[data-role="switch-group"]');
            if (next.length) {
                this.container = next.empty();
            } else {
                this.container = jq('<div data-role="switch-group" class="switch-group"></div>');
                this.input.after(this.container);
            }

            if (this.showLabels) {
                this.labelOff = jq('<button type="button" class="switch-group__label switch-group__label--off"></button>');
                switchGroupSetLabelContent(this.labelOff, this.falseLabel, this.falseLabelIsHtml, 'Off');
                this.container.append(this.labelOff);
            }

            this.switchInput = jq('<button type="button" class="switch-group__toggle" role="switch" aria-checked="false"></button>');
            this.switchInput.append('<span class="switch-group__thumb" aria-hidden="true"></span>');
            this.container.append(this.switchInput);

            if (this.showLabels) {
                this.labelOn = jq('<button type="button" class="switch-group__label switch-group__label--on"></button>');
                switchGroupSetLabelContent(this.labelOn, this.trueLabel, this.trueLabelIsHtml, 'On');
                this.container.append(this.labelOn);
            }

            return this;
        },

        bindEvents: function () {
            var self = this;

            if (this.switchInput && this.switchInput.length) {
                this.switchInput.off('click' + ns).on('click' + ns, function (event) {
                    event.preventDefault();
                    self.toggle();
                });

                this.switchInput.off('keydown' + ns).on('keydown' + ns, function (event) {
                    if (event.key === ' ' || event.key === 'Enter') {
                        event.preventDefault();
                        self.toggle();
                    }
                });
            }

            if (this.labelOff && this.labelOff.length) {
                this.labelOff.off('click' + ns).on('click' + ns, function (event) {
                    event.preventDefault();
                    self.setOff();
                });
            }

            if (this.labelOn && this.labelOn.length) {
                this.labelOn.off('click' + ns).on('click' + ns, function (event) {
                    event.preventDefault();
                    self.setOn();
                });
            }

            this.input.off('change' + ns).on('change' + ns, function () {
                self.syncFromInput();
            });

            return this;
        },

        syncLabels: function (isOn) {
            if (!this.showLabels) {
                return this;
            }

            if (this.labelOn && this.labelOn.length) {
                this.labelOn.toggleClass('is-active', isOn);
            }
            if (this.labelOff && this.labelOff.length) {
                this.labelOff.toggleClass('is-active', !isOn);
            }

            return this;
        },

        syncFromInput: function () {
            if (!this.switchInput || !this.switchInput.length) {
                return this;
            }

            var current = this.getValue();
            if (current !== this.trueValue && current !== this.falseValue) {
                current = this.defaultValue;
                if (current !== this.trueValue && current !== this.falseValue) {
                    current = this.trueValue;
                }
                this.input.val(current);
            }

            var isOn = (current === this.trueValue);

            this.switchInput.toggleClass('is-on', isOn);
            this.switchInput.attr('aria-checked', isOn ? 'true' : 'false');
            this.syncLabels(isOn);
            this.checkEnabled();

            return this;
        },

        refresh: function () {
            return this.syncFromInput();
        },

        value: function () {
            var current = this.input.val();
            return (current == null) ? '' : String(current);
        },

        getValue: function () {
            return this.value();
        },

        setValue: function (value) {
            var next = (value == null) ? '' : String(value);
            this.input.val(next);
            var el = this.input && this.input[0];
            if (el && typeof el.dispatchEvent === 'function') {
                el.dispatchEvent(new Event('change', { bubbles: true }));
            }
            return this;
        },

        setOn: function () {
            return this.setValue(this.trueValue);
        },

        setOff: function () {
            return this.setValue(this.falseValue);
        },

        isOn: function () {
            return this.getValue() === this.trueValue;
        },

        isOff: function () {
            return this.getValue() === this.falseValue;
        },

        toggle: function () {
            if (this.input.prop('disabled') === true) {
                return this;
            }
            if (this.isOn()) {
                return this.setOff();
            }
            return this.setOn();
        },

        checkEnabled: function () {
            var enabled = (this.input.prop('disabled') !== true);
            if (this.switchInput && this.switchInput.length) {
                this.switchInput.prop('disabled', !enabled);
                this.switchInput.attr('aria-disabled', enabled ? 'false' : 'true');
            }
            if (this.labelOn && this.labelOn.length) {
                this.labelOn.prop('disabled', !enabled);
            }
            if (this.labelOff && this.labelOff.length) {
                this.labelOff.prop('disabled', !enabled);
            }
            if (this.container && this.container.length) {
                this.container.toggleClass('is-disabled', !enabled);
            }
            return enabled;
        },

        enable: function () {
            this.input.prop('disabled', false);
            this.checkEnabled();
            return this;
        },

        disable: function () {
            this.input.prop('disabled', true);
            this.checkEnabled();
            return this;
        },

        setDisabled: function (disabled) {
            if (disabled === true) {
                return this.disable();
            }
            return this.enable();
        },

        setOptions: function (options) {
            if (!Array.isArray(options) || options.length < 2) {
                return this;
            }

            var first = options[0] || {};
            var second = options[1] || {};

            this.trueValue = switchGroupNormalizeValue(first.value, this.trueValue);
            this.falseValue = switchGroupNormalizeValue(second.value, this.falseValue);
            this.trueLabel = String(first.label != null ? first.label : this.trueLabel);
            this.falseLabel = String(second.label != null ? second.label : this.falseLabel);
            if (typeof first.labelIsHtml !== 'undefined') {
                this.trueLabelIsHtml = (first.labelIsHtml === true);
            }
            if (typeof second.labelIsHtml !== 'undefined') {
                this.falseLabelIsHtml = (second.labelIsHtml === true);
            }

            this.buildUI();
            this.bindEvents();
            if (!this.hasValue()) {
                this.setValue(this.defaultValue);
            } else {
                this.syncFromInput();
            }

            return this;
        },

        unmount: function () {
            this.input.off(ns);

            if (this.switchInput && this.switchInput.length) {
                this.switchInput.off(ns);
            }
            if (this.labelOn && this.labelOn.length) {
                this.labelOn.off(ns);
            }
            if (this.labelOff && this.labelOff.length) {
                this.labelOff.off(ns);
            }
            if (this.container && this.container.length) {
                this.container.remove();
            }

            this.input.removeClass('d-none');
            if (typeof this.input.removeData === 'function') {
                this.input.removeData('__switchGroupInstance');
            }

            this.switchInput = null;
            this.labelOn = null;
            this.labelOff = null;
            this.container = null;
            this.mounted = false;

            return this;
        },

        destroy: function () {
            return this.unmount();
        }
    };

    return control.init();
}

if (typeof window !== 'undefined') {
    window.SwitchGroup = SwitchGroup;
}
