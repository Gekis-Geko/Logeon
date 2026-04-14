function DiceEngine(extension) {
    var ext = (extension && typeof extension === 'object') ? Object.assign({}, extension) : {};

    var base = {
        defaultConfig: {
            defaultExpression: '1d20',
            defaultSides: 20,
            minCount: 1,
            maxCount: 100,
            minSides: 2,
            maxSides: 1000
        },
        config: null,

        randomSource: null,

        init: function () {
            if (!this.config || typeof this.config !== 'object') {
                this.config = Object.assign({}, this.defaultConfig);
            } else {
                this.config = Object.assign({}, this.defaultConfig, this.config);
            }
            return this;
        },

        getConfig: function () {
            return Object.assign({}, this.config || this.defaultConfig || {});
        },

        setConfig: function (config) {
            if (!config || typeof config !== 'object') {
                return this;
            }
            this.config = Object.assign({}, this.defaultConfig || {}, this.config || {}, config);
            return this;
        },

        resetConfig: function () {
            this.config = Object.assign({}, this.defaultConfig || {});
            return this;
        },

        setRandomSource: function (randomFn) {
            if (typeof randomFn === 'function') {
                this.randomSource = randomFn;
            }
            return this;
        },

        clearRandomSource: function () {
            this.randomSource = null;
            return this;
        },

        getRandomSource: function () {
            return (typeof this.randomSource === 'function') ? this.randomSource : Math.random;
        },

        getLimits: function () {
            return {
                minCount: parseInt(this.config.minCount, 10),
                maxCount: parseInt(this.config.maxCount, 10),
                minSides: parseInt(this.config.minSides, 10),
                maxSides: parseInt(this.config.maxSides, 10)
            };
        },

        normalizeSides: function (value) {
            var sides = parseInt(value, 10);
            if (isNaN(sides) || sides < this.config.minSides) {
                sides = this.config.defaultSides;
            }
            if (sides > this.config.maxSides) {
                sides = this.config.maxSides;
            }
            return sides;
        },

        randomInt: function (min, max) {
            min = parseInt(min, 10);
            max = parseInt(max, 10);
            if (isNaN(min) || isNaN(max) || max < min) {
                return 0;
            }

            var source = this.getRandomSource();
            var value = source();
            if (typeof value !== 'number' || !isFinite(value)) {
                value = Math.random();
            }
            if (value < 0) {
                value = 0;
            }
            if (value >= 1) {
                value = 0.9999999999999999;
            }

            return Math.floor(value * (max - min + 1)) + min;
        },

        normalizeExpression: function (expression) {
            var raw = (expression == null) ? '' : String(expression);
            return raw.replace(/\s+/g, '').toLowerCase();
        },

        makeError: function (code, message, raw, normalized) {
            return {
                ok: false,
                value: null,
                code: String(code || 'invalid_expression'),
                message: String(message || 'Espressione non valida.'),
                input: (raw == null) ? '' : String(raw),
                normalized: (normalized == null) ? '' : String(normalized)
            };
        },

        makeSuccess: function (value, raw, normalized) {
            return {
                ok: true,
                value: value,
                code: null,
                message: '',
                input: (raw == null) ? '' : String(raw),
                normalized: (normalized == null) ? '' : String(normalized)
            };
        },

        isSuccessResult: function (result) {
            return !!(result && typeof result === 'object' && result.ok === true);
        },

        isErrorResult: function (result) {
            return !!(result && typeof result === 'object' && result.ok === false);
        },

        formatModifiers: function (modifiers) {
            if (!Array.isArray(modifiers) || modifiers.length === 0) {
                return '';
            }

            var out = '';
            for (var i = 0; i < modifiers.length; i++) {
                var mod = parseInt(modifiers[i], 10);
                if (isNaN(mod)) {
                    continue;
                }
                out += (mod >= 0 ? '+' : '') + String(mod);
            }
            return out;
        },

        sumModifiers: function (modifiers) {
            if (!Array.isArray(modifiers) || modifiers.length === 0) {
                return 0;
            }
            var total = 0;
            for (var i = 0; i < modifiers.length; i++) {
                var mod = parseInt(modifiers[i], 10);
                if (isNaN(mod)) {
                    return null;
                }
                total += mod;
            }
            return total;
        },

        buildExpression: function (parsed) {
            if (!parsed || typeof parsed !== 'object') {
                return '';
            }
            return String(parsed.count) + 'd' + String(parsed.sides) + this.formatModifiers(parsed.modifiers);
        },

        parseDetailed: function (expression) {
            var raw = (expression == null) ? '' : String(expression);
            var normalized = this.normalizeExpression(raw);

            if (normalized === '') {
                normalized = this.config.defaultExpression;
            }

            var match = normalized.match(/^(\d*)d(\d+)(([+-]\d+)*)$/i);
            if (!match) {
                return this.makeError(
                    'invalid_format',
                    'Formato dado non valido. Esempio: 2d6+1',
                    raw,
                    normalized
                );
            }

            var count = (match[1] === '') ? 1 : parseInt(match[1], 10);
            if (isNaN(count) || count < this.config.minCount || count > this.config.maxCount) {
                return this.makeError(
                    'invalid_count',
                    'Numero dadi non valido. Range: ' + this.config.minCount + '-' + this.config.maxCount + '.',
                    raw,
                    normalized
                );
            }

            var sides = parseInt(match[2], 10);
            if (isNaN(sides) || sides < this.config.minSides || sides > this.config.maxSides) {
                return this.makeError(
                    'invalid_sides',
                    'Facce dado non valide. Range: ' + this.config.minSides + '-' + this.config.maxSides + '.',
                    raw,
                    normalized
                );
            }

            var modifiers = [];
            if (match[3] && match[3] !== '') {
                var tokens = match[3].match(/[+-]\d+/g) || [];
                for (var i = 0; i < tokens.length; i++) {
                    var parsedModifier = parseInt(tokens[i], 10);
                    if (isNaN(parsedModifier)) {
                        return this.makeError(
                            'invalid_modifier',
                            'Modificatore non valido.',
                            raw,
                            normalized
                        );
                    }
                    modifiers.push(parsedModifier);
                }
            }

            return this.makeSuccess({
                expression: normalized,
                count: count,
                sides: sides,
                modifiers: modifiers
            }, raw, normalized);
        },

        parse: function (expression) {
            var parsed = this.parseDetailed(expression);
            return parsed.ok ? parsed.value : null;
        },

        validate: function (expression, options) {
            var detailed = !!(options && options.detailed === true);
            var parsed = this.parseDetailed(expression);
            return detailed ? parsed : parsed.ok;
        },

        rollDetailed: function (expression) {
            var parsedResult = this.parseDetailed(expression);
            if (!parsedResult.ok) {
                return parsedResult;
            }

            var parsed = parsedResult.value;
            var rolls = [];
            var subtotal = 0;
            for (var i = 0; i < parsed.count; i++) {
                var rollValue = this.randomInt(1, parsed.sides);
                rolls.push(rollValue);
                subtotal += rollValue;
            }

            var modifierTotal = this.sumModifiers(parsed.modifiers);
            if (modifierTotal === null) {
                return this.makeError(
                    'invalid_modifier',
                    'Modificatore non valido.',
                    parsedResult.input,
                    parsedResult.normalized
                );
            }

            return this.makeSuccess({
                expression: parsed.expression,
                count: parsed.count,
                sides: parsed.sides,
                rolls: rolls,
                modifiers: parsed.modifiers.slice(),
                subtotal: subtotal,
                modifierTotal: modifierTotal,
                total: subtotal + modifierTotal
            }, parsedResult.input, parsedResult.normalized);
        },

        roll: function (expression) {
            var result = this.rollDetailed(expression);
            return result.ok ? result.value : null;
        },

        format: function (rollResult, options) {
            if (!rollResult || typeof rollResult !== 'object') {
                return '';
            }

            var opts = (options && typeof options === 'object') ? options : {};
            var includeRolls = opts.includeRolls !== false;
            var includeExpression = opts.includeExpression !== false;

            var expression = String(rollResult.expression || this.buildExpression(rollResult) || '');
            var rolls = Array.isArray(rollResult.rolls) ? rollResult.rolls : [];
            var modifiers = Array.isArray(rollResult.modifiers) ? rollResult.modifiers : [];
            var modifierLabel = this.formatModifiers(modifiers);
            var total = parseInt(rollResult.total, 10);
            if (isNaN(total)) {
                total = 0;
            }

            var parts = [];
            if (includeExpression && expression !== '') {
                parts.push(expression);
            }
            if (includeRolls && rolls.length > 0) {
                parts.push('[' + rolls.join(', ') + ']');
            }
            if (modifierLabel !== '') {
                parts.push(modifierLabel);
            }
            parts.push('= ' + total);
            return parts.join(' ');
        }
    };

    var applyExtension = function (instance) {
        if (ext && typeof ext === 'object') {
            Object.assign(instance, ext);
            if (ext.config && typeof ext.config === 'object') {
                instance.setConfig(ext.config);
            }
        }
        return instance;
    };

    if (typeof window !== 'undefined') {
        if (!window.__dice_engine_instance) {
            window.__dice_engine_instance = applyExtension(Object.assign({}, base)).init();
        } else if (ext && typeof ext === 'object') {
            applyExtension(window.__dice_engine_instance);
        }
        return window.__dice_engine_instance;
    }

    return applyExtension(Object.assign({}, base)).init();
}

if (typeof window !== 'undefined') {
    window.DiceEngine = DiceEngine;
}
