var radioGroupMissingSelectionGroupWarned = false;

function radioGroupResolveSelectionGroup() {
    if (typeof window !== 'undefined' && typeof window.SelectionGroup === 'function') {
        return window.SelectionGroup;
    }
    if (typeof SelectionGroup === 'function') {
        return SelectionGroup;
    }
    return null;
}

function radioGroupWarnMissingSelectionGroup() {
    if (radioGroupMissingSelectionGroupWarned === true) {
        return;
    }
    radioGroupMissingSelectionGroupWarned = true;

    if (typeof console !== 'undefined' && typeof console.error === 'function') {
        console.error('[RadioGroup] SelectionGroup non disponibile.');
    }
}

function radioGroupCreateFallback(input) {
    var inputRef = null;
    if (typeof window !== 'undefined' && typeof window.$ === 'function') {
        inputRef = window.$(input);
    } else if (typeof $ === 'function') {
        inputRef = $(input);
    }

    return {
        input: inputRef,
        options: [],
        mounted: false,
        init: function () { return this.mount(); },
        mount: function () { this.mounted = true; return this; },
        unmount: function () { this.mounted = false; return this; },
        destroy: function () { return this.unmount(); },
        setOptions: function (options) {
            this.options = Array.isArray(options) ? options.slice() : [];
            return this;
        },
        refresh: function () { return this; },
        onChangeValue: function () { return this; },
        checkEnabled: function () {
            if (!this.input || !this.input.length) {
                return false;
            }
            return this.input.prop('disabled') !== true;
        },
        enable: function () {
            if (this.input && this.input.length) {
                this.input.prop('disabled', false);
            }
            return this;
        },
        disable: function () {
            if (this.input && this.input.length) {
                this.input.prop('disabled', true);
            }
            return this;
        },
        setDisabled: function (disabled) {
            if (disabled === true) {
                return this.disable();
            }
            return this.enable();
        },
        value: function () {
            if (!this.input || !this.input.length) {
                return '';
            }
            var current = this.input.val();
            return (current == null) ? '' : String(current);
        },
        getValue: function () {
            return this.value();
        },
        setValue: function (value) {
            if (this.input && this.input.length) {
                this.input.val((value == null) ? '' : String(value)).change();
            }
            return this;
        },
        select: function (value) {
            return this.setValue(value);
        },
        unselect: function () { return this; }
    };
}

function RadioGroup(input, extension) {
    var ext = (extension && typeof extension === 'object') ? Object.assign({}, extension) : {};
    ext.mode = 'single';

    var selectionGroupFactory = radioGroupResolveSelectionGroup();
    if (typeof selectionGroupFactory === 'function') {
        return selectionGroupFactory(input, ext);
    }

    radioGroupWarnMissingSelectionGroup();
    return radioGroupCreateFallback(input);
}

if (typeof window !== 'undefined') {
    window.RadioGroup = RadioGroup;
}
