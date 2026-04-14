var checkGroupMissingSelectionGroupWarned = false;

function checkGroupResolveSelectionGroup() {
    if (typeof window !== 'undefined' && typeof window.SelectionGroup === 'function') {
        return window.SelectionGroup;
    }
    if (typeof SelectionGroup === 'function') {
        return SelectionGroup;
    }
    return null;
}

function checkGroupWarnMissingSelectionGroup() {
    if (checkGroupMissingSelectionGroupWarned === true) {
        return;
    }
    checkGroupMissingSelectionGroupWarned = true;

    if (typeof console !== 'undefined' && typeof console.error === 'function') {
        console.error('[CheckGroup] SelectionGroup non disponibile.');
    }
}

function checkGroupNormalizeValues(values) {
    var source = values;
    if (!Array.isArray(source)) {
        source = (source == null || source === '') ? [] : [source];
    }

    var normalized = [];
    for (var i = 0; i < source.length; i++) {
        var value = String(source[i]);
        if (normalized.indexOf(value) === -1) {
            normalized.push(value);
        }
    }
    return normalized;
}

function checkGroupCreateFallback(input) {
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
                return [];
            }
            return checkGroupNormalizeValues(this.input.val());
        },
        getValue: function () {
            return this.value();
        },
        setValue: function (value) {
            if (this.input && this.input.length) {
                this.input.val(checkGroupNormalizeValues(value)).change();
            }
            return this;
        },
        select: function (value) {
            var values = this.value();
            var target = String(value);
            if (values.indexOf(target) === -1) {
                values.push(target);
                this.setValue(values);
            }
            return this;
        },
        unselect: function (value) {
            var values = this.value();
            var target = String(value);
            var index = values.indexOf(target);
            if (index !== -1) {
                values.splice(index, 1);
                this.setValue(values);
            }
            return this;
        }
    };
}

function CheckGroup(input, extension) {
    var ext = (extension && typeof extension === 'object') ? Object.assign({}, extension) : {};
    ext.mode = 'multiple';

    var selectionGroupFactory = checkGroupResolveSelectionGroup();
    if (typeof selectionGroupFactory === 'function') {
        return selectionGroupFactory(input, ext);
    }

    checkGroupWarnMissingSelectionGroup();
    return checkGroupCreateFallback(input);
}

if (typeof window !== 'undefined') {
    window.CheckGroup = CheckGroup;
}
