function CommandParser(extension) {
    let defaultCommands = [
        { key: '/dado',      value: '/dado 1d20',  hint: 'Tiro di dado. Esempio: /dado 2d6', aliases: ['/dice'], kind: 'dice' },
        { key: '/skill',     value: '/skill ',     hint: 'Usa una skill in chat', kind: 'skill' },
        { key: '/oggetto',   value: '/oggetto ',   hint: 'Usa un oggetto in chat', kind: 'oggetto' },
        { key: '/conflitto', value: '/conflitto @', hint: 'Proponi un conflitto in location (es. /conflitto @12 Duello rituale)', kind: 'conflitto' },
        { key: '/sussurra',  value: '/sussurra "', hint: 'Sussurro 1:1', aliases: ['/w', '/whisper'], kind: 'whisper' },
        { key: '/w',         value: '/w "',        hint: 'Alias sussurro', kind: 'whisper' },
        { key: '/fato',      value: '/fato ',      hint: 'Narrazione Fato (solo master/staff)', kind: 'fato' },
        { key: '/png',       value: '/png @',      hint: 'Messaggio come PNG narrativo. Es: /png @NomePNG messaggio', kind: 'png' },
        { key: '/lascia',    value: '/lascia ',    hint: 'Lascia un oggetto a terra. Es: /lascia @SpadaMagica', kind: 'lascia' },
        { key: '/dai',       value: '/dai ',       hint: 'Dai monete a un personaggio in location. Es: /dai @Mario 50', kind: 'dai' }
    ];

    let hasOwn = function (obj, key) {
        return !!obj && Object.prototype.hasOwnProperty.call(obj, key);
    };

    let base = {
        commands: [],
        defaults: {
            commands: defaultCommands,
            whisperCommandTokens: ['/sussurra', '/w', '/whisper'],
            diceCommandTokens: ['/dado', '/dice']
        },
        whisperCommandTokens: ['/sussurra', '/w', '/whisper'],
        diceCommandTokens: ['/dado', '/dice'],
        diceValidationFallbackMessage: 'Formato dado non valido. Esempio: /dado 2d6+1',
        emitEvents: false,
        eventBus: null,
        eventNames: {
            parsed: 'command:parsed',
            suggested: 'command:suggested',
            validated: 'command:validated',
            rejected: 'command:rejected'
        },

        init: function () {
            this.whisperCommandTokens = this.normalizeTokenList(this.whisperCommandTokens || this.defaults.whisperCommandTokens || []);
            this.diceCommandTokens = this.normalizeTokenList(this.diceCommandTokens || this.defaults.diceCommandTokens || []);

            if (!Array.isArray(this.commands) || this.commands.length === 0) {
                this.resetCommands();
            } else {
                this.commands = this.normalizeCommands(this.commands);
            }
            return this;
        },

        configure: function (options) {
            if (!options || typeof options !== 'object') {
                return this;
            }

            let patch = Object.assign({}, options);
            let hasCommands = hasOwn(patch, 'commands');
            let hasWhisperTokens = hasOwn(patch, 'whisperCommandTokens');
            let hasDiceTokens = hasOwn(patch, 'diceCommandTokens');
            delete patch.commands;
            delete patch.whisperCommandTokens;
            delete patch.diceCommandTokens;

            Object.assign(this, patch);

            if (hasWhisperTokens) {
                this.whisperCommandTokens = this.normalizeTokenList(options.whisperCommandTokens);
            }
            if (hasDiceTokens) {
                this.diceCommandTokens = this.normalizeTokenList(options.diceCommandTokens);
            }
            if (hasCommands) {
                this.setCommands(options.commands);
            }

            return this;
        },

        resolveEventBus: function () {
            if (this.eventBus && typeof this.eventBus === 'object') {
                return this.eventBus;
            }

            if (typeof this.eventBusFactory === 'function') {
                try {
                    return this.eventBusFactory();
                } catch (error) {
                    return null;
                }
            }

            if (typeof EventBus === 'function') {
                try {
                    return EventBus();
                } catch (error) {
                    return null;
                }
            }

            return null;
        },

        shouldEmitEvents: function () {
            return this.emitEvents === true;
        },

        emitEvent: function (name, payload) {
            if (!this.shouldEmitEvents()) {
                return false;
            }

            let eventName = ((name || '').toString()).trim();
            if (eventName === '') {
                return false;
            }

            let bus = this.resolveEventBus();
            if (!bus) {
                return false;
            }

            try {
                if (typeof bus.emit === 'function') {
                    bus.emit(eventName, payload);
                    return true;
                }
                if (typeof bus.publish === 'function') {
                    bus.publish(eventName, payload);
                    return true;
                }
            } catch (error) {
                return false;
            }

            return false;
        },

        getEventName: function (key, fallbackName) {
            if (this.eventNames && typeof this.eventNames === 'object' && hasOwn(this.eventNames, key)) {
                return (this.eventNames[key] || '').toString();
            }
            return (fallbackName || '').toString();
        },

        cloneCommand: function (row) {
            if (!row || typeof row !== 'object') {
                return null;
            }

            let copy = Object.assign({}, row);
            if (Array.isArray(row.aliases)) {
                copy.aliases = row.aliases.slice();
            }
            return copy;
        },

        normalizeInput: function (input) {
            return (input || '').toString().replace(/^\s+/, '');
        },

        normalizeCommandKey: function (key) {
            let value = ((key || '').toString()).trim().toLowerCase();
            if (value === '') {
                return '';
            }
            return value.charAt(0) === '/' ? value : ('/' + value);
        },

        normalizeTokenList: function (rows) {
            let list = Array.isArray(rows) ? rows : [];
            let out = [];
            for (let i = 0; i < list.length; i++) {
                let token = this.normalizeCommandKey(list[i]);
                if (token === '' || out.indexOf(token) >= 0) {
                    continue;
                }
                out.push(token);
            }
            return out;
        },

        escapeRegExp: function (value) {
            return String(value || '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        },

        buildCommandMatchRegex: function (tokens) {
            let list = this.normalizeTokenList(tokens || []);
            if (list.length === 0) {
                return null;
            }

            let escaped = [];
            for (let i = 0; i < list.length; i++) {
                escaped.push(this.escapeRegExp(list[i]));
            }

            return new RegExp('^(?:' + escaped.join('|') + ')\\s+(.*)$', 'i');
        },

        getPrimaryCommandTokenFromList: function (tokens, fallback) {
            let list = this.normalizeTokenList(tokens || []);
            if (list.length > 0) {
                return list[0];
            }
            return this.normalizeCommandKey(fallback || '');
        },

        normalizeCommandRow: function (row) {
            if (!row || typeof row !== 'object') {
                return null;
            }

            let key = this.normalizeCommandKey(row.key);
            if (key === '') {
                return null;
            }

            let normalized = this.cloneCommand(row) || {};
            normalized.key = key;
            normalized.value = hasOwn(normalized, 'value') ? (normalized.value || '').toString() : (key + ' ');
            normalized.hint = hasOwn(normalized, 'hint') ? (normalized.hint || '').toString() : '';
            normalized.aliases = this.normalizeTokenList(normalized.aliases || []);
            return normalized;
        },

        normalizeCommands: function (rows) {
            let list = Array.isArray(rows) ? rows : [];
            let out = [];
            let seen = [];

            for (let i = 0; i < list.length; i++) {
                let normalized = this.normalizeCommandRow(list[i]);
                if (!normalized) {
                    continue;
                }

                if (seen.indexOf(normalized.key) >= 0) {
                    let existingIndex = -1;
                    for (let j = 0; j < out.length; j++) {
                        if (out[j].key === normalized.key) {
                            existingIndex = j;
                            break;
                        }
                    }
                    if (existingIndex >= 0) {
                        out[existingIndex] = normalized;
                    }
                    continue;
                }

                seen.push(normalized.key);
                out.push(normalized);
            }

            return out;
        },

        getCommands: function () {
            let out = [];
            for (let i = 0; i < this.commands.length; i++) {
                let cloned = this.cloneCommand(this.commands[i]);
                if (cloned) {
                    out.push(cloned);
                }
            }
            return out;
        },

        setCommands: function (rows) {
            this.commands = this.normalizeCommands(rows);
            return this;
        },

        resetCommands: function () {
            return this.setCommands(this.defaults.commands || defaultCommands);
        },

        registerCommand: function (row, options) {
            let normalized = this.normalizeCommandRow(row);
            if (!normalized) {
                return null;
            }

            let replace = true;
            if (options && hasOwn(options, 'replace')) {
                replace = options.replace === true;
            }

            let existingIndex = -1;
            for (let i = 0; i < this.commands.length; i++) {
                if (this.commands[i].key === normalized.key) {
                    existingIndex = i;
                    break;
                }
            }

            if (existingIndex >= 0) {
                if (replace) {
                    this.commands[existingIndex] = normalized;
                    return this.cloneCommand(this.commands[existingIndex]);
                }
                return this.cloneCommand(this.commands[existingIndex]);
            }

            this.commands.push(normalized);
            return this.cloneCommand(normalized);
        },

        registerCommands: function (rows, options) {
            let list = Array.isArray(rows) ? rows : [];
            let registered = [];
            for (let i = 0; i < list.length; i++) {
                let row = this.registerCommand(list[i], options);
                if (row) {
                    registered.push(row);
                }
            }
            return registered;
        },

        unregisterCommand: function (key) {
            let normalizedKey = this.normalizeCommandKey(key);
            if (normalizedKey === '') {
                return false;
            }

            let next = [];
            let removed = false;
            for (let i = 0; i < this.commands.length; i++) {
                if (this.commands[i].key === normalizedKey) {
                    removed = true;
                    continue;
                }
                next.push(this.commands[i]);
            }
            this.commands = next;
            return removed;
        },

        isCommand: function (input) {
            let text = this.normalizeInput(input);
            return text.length > 0 && text.charAt(0) === '/';
        },

        getCommandToken: function (input) {
            if (!this.isCommand(input)) {
                return '';
            }
            let text = this.normalizeInput(input);
            return this.normalizeCommandKey(text.split(/\s+/)[0] || '');
        },

        getCommandArgs: function (input) {
            if (!this.isCommand(input)) {
                return '';
            }
            let text = this.normalizeInput(input);
            return text.replace(/^\/\S+\s*/i, '');
        },

        getCommandAliases: function (row) {
            if (!row || typeof row !== 'object') {
                return [];
            }

            let list = [row.key];
            if (Array.isArray(row.aliases)) {
                for (let i = 0; i < row.aliases.length; i++) {
                    list.push(row.aliases[i]);
                }
            }
            return this.normalizeTokenList(list);
        },

        resolveCommandRow: function (inputOrToken) {
            let token = this.isCommand(inputOrToken)
                ? this.getCommandToken(inputOrToken)
                : this.normalizeCommandKey(inputOrToken);

            if (token === '') {
                return null;
            }

            for (let i = 0; i < this.commands.length; i++) {
                let aliases = this.getCommandAliases(this.commands[i]);
                if (aliases.indexOf(token) >= 0) {
                    return this.cloneCommand(this.commands[i]);
                }
            }
            return null;
        },

        commandMatchesTokens: function (inputOrToken, acceptedTokens) {
            let token = this.isCommand(inputOrToken)
                ? this.getCommandToken(inputOrToken)
                : this.normalizeCommandKey(inputOrToken);

            if (token === '') {
                return false;
            }

            let accepted = this.normalizeTokenList(acceptedTokens || []);
            return accepted.indexOf(token) >= 0;
        },

        parse: function (input) {
            let normalized = this.normalizeInput(input);
            let isCommand = this.isCommand(normalized);
            let token = isCommand ? this.getCommandToken(normalized) : '';
            let args = isCommand ? this.getCommandArgs(normalized) : '';
            let command = token !== '' ? this.resolveCommandRow(token) : null;
            let result = {
                raw: (input || '').toString(),
                normalized: normalized,
                isCommand: isCommand,
                token: token,
                args: args,
                command: command,
                known: command !== null
            };

            this.emitEvent(this.getEventName('parsed', 'command:parsed'), {
                input: result.raw,
                normalized: result.normalized,
                isCommand: result.isCommand,
                token: result.token,
                args: result.args,
                known: result.known
            });

            return result;
        },

        getCommandSuggestions: function (input) {
            let token = this.getCommandToken(input);
            if (token === '' || token === '/') {
                let defaultSuggestions = this.getCommands();
                this.emitEvent(this.getEventName('suggested', 'command:suggested'), {
                    input: (input || '').toString(),
                    token: token,
                    count: defaultSuggestions.length,
                    suggestions: defaultSuggestions
                });
                return defaultSuggestions;
            }

            let rows = [];
            let seenKeys = [];
            for (let i = 0; i < this.commands.length; i++) {
                let row = this.commands[i];
                let aliases = this.getCommandAliases(row);
                let matched = false;

                for (let j = 0; j < aliases.length; j++) {
                    if (aliases[j].indexOf(token) === 0) {
                        matched = true;
                        break;
                    }
                }

                if (!matched || seenKeys.indexOf(row.key) >= 0) {
                    continue;
                }

                seenKeys.push(row.key);
                rows.push(this.cloneCommand(row));
            }
            this.emitEvent(this.getEventName('suggested', 'command:suggested'), {
                input: (input || '').toString(),
                token: token,
                count: rows.length,
                suggestions: rows
            });
            return rows;
        },

        isWhisperCommand: function (input) {
            return this.commandMatchesTokens(input, this.whisperCommandTokens);
        },

        isDiceCommand: function (input) {
            return this.commandMatchesTokens(input, this.diceCommandTokens);
        },

        buildWhisperSuggestion: function (fullName) {
            let value = ((fullName || '').toString()).trim();
            if (value === '') {
                return null;
            }
            let whisperCommand = this.getPrimaryCommandTokenFromList(this.whisperCommandTokens, '/sussurra') || '/sussurra';
            return {
                key: value,
                value: whisperCommand + ' "' + value + '" ',
                hint: 'Invia sussurro'
            };
        },

        extractWhisperQuery: function (input) {
            let text = this.normalizeInput(input);
            if (!this.isWhisperCommand(text)) {
                return null;
            }

            let whisperRegex = this.buildCommandMatchRegex(this.whisperCommandTokens);
            let match = whisperRegex ? text.match(whisperRegex) : null;
            if (!match) {
                return {
                    query: '',
                    completed: false
                };
            }

            let remainder = (match[1] || '').toString();
            if (remainder.charAt(0) === '"') {
                let closeQuoteIndex = remainder.indexOf('"', 1);
                if (closeQuoteIndex > 0) {
                    return {
                        query: '',
                        completed: true
                    };
                }
                return {
                    query: remainder.substring(1).trim(),
                    completed: false
                };
            }

            if (remainder.indexOf(' ') >= 0) {
                return {
                    query: '',
                    completed: true
                };
            }

            return {
                query: remainder.trim(),
                completed: false
            };
        },

        validate: function (input) {
            let parsed = this.parse(input);
            if (!parsed.isCommand) {
                let textResult = {
                    ok: true,
                    type: 'text',
                    token: '',
                    args: '',
                    message: ''
                };
                this.emitEvent(this.getEventName('validated', 'command:validated'), {
                    input: parsed.raw,
                    result: textResult
                });
                return textResult;
            }

            if (this.isDiceCommand(parsed.token)) {
                let diceResult = this.validateDiceArgsDetailed(parsed.args);
                let validatedDice = {
                    ok: !!(diceResult && diceResult.ok),
                    type: 'dice',
                    token: parsed.token,
                    args: parsed.args,
                    message: (diceResult && diceResult.message) ? diceResult.message : '',
                    detail: diceResult || null
                };
                this.emitEvent(this.getEventName('validated', 'command:validated'), {
                    input: parsed.raw,
                    parsed: parsed,
                    result: validatedDice
                });
                if (validatedDice.ok !== true) {
                    this.emitEvent(this.getEventName('rejected', 'command:rejected'), {
                        input: parsed.raw,
                        parsed: parsed,
                        result: validatedDice
                    });
                }
                return validatedDice;
            }

            let commandResult = {
                ok: true,
                type: 'command',
                token: parsed.token,
                args: parsed.args,
                message: ''
            };
            this.emitEvent(this.getEventName('validated', 'command:validated'), {
                input: parsed.raw,
                parsed: parsed,
                result: commandResult
            });
            return commandResult;
        },

        getDiceEngine: function () {
            if (typeof this.diceEngineFactory === 'function') {
                try {
                    return this.diceEngineFactory();
                } catch (error) {
                    return null;
                }
            }

            if (typeof DiceEngine === 'function') {
                try {
                    return DiceEngine();
                } catch (error) {
                    return null;
                }
            }

            return null;
        },

        validateDiceArgs: function (args) {
            let result = this.validateDiceArgsDetailed(args);
            return !!(result && result.ok);
        },

        validateDiceArgsDetailed: function (args) {
            let expr = (args || '').toString().trim();
            if (expr === '') {
                expr = '1d20';
            }

            let engine = this.getDiceEngine();
            if (!engine) {
                return {
                    ok: true,
                    value: null,
                    code: null,
                    message: ''
                };
            }

            if (typeof engine.validate === 'function') {
                try {
                    return engine.validate(expr, { detailed: true });
                } catch (error) {
                    return {
                        ok: false,
                        value: null,
                        code: 'dice_validate_error',
                        message: this.diceValidationFallbackMessage
                    };
                }
            }

            if (typeof engine.parse === 'function') {
                try {
                    let parsed = engine.parse(expr);
                    return parsed !== null
                        ? { ok: true, value: parsed, code: null, message: '' }
                        : { ok: false, value: null, code: 'invalid_format', message: this.diceValidationFallbackMessage };
                } catch (error) {
                    return {
                        ok: false,
                        value: null,
                        code: 'dice_parse_error',
                        message: this.diceValidationFallbackMessage
                    };
                }
            }

            return {
                ok: true,
                value: null,
                code: null,
                message: ''
            };
        }
    };

    let createInstance = function (ext) {
        let instance = Object.assign({}, base);
        instance.defaults = {
            commands: defaultCommands,
            whisperCommandTokens: base.defaults.whisperCommandTokens.slice(),
            diceCommandTokens: base.defaults.diceCommandTokens.slice()
        };
        instance.commands = [];
        instance.init();
        if (ext && typeof ext === 'object') {
            instance.configure(ext);
        }
        return instance;
    };

    if (typeof window !== 'undefined') {
        if (!window.__command_parser_instance) {
            window.__command_parser_instance = createInstance(extension);
        } else if (extension && typeof extension === 'object' && typeof window.__command_parser_instance.configure === 'function') {
            window.__command_parser_instance.configure(extension);
        } else if (extension && typeof extension === 'object') {
            Object.assign(window.__command_parser_instance, extension);
        }
        return window.__command_parser_instance;
    }

    return createInstance(extension);
}

if (typeof window !== 'undefined') {
    window.CommandParser = CommandParser;
}
