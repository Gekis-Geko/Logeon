function Dice(sidesOrExpression = 20, extension) {
    let ext = (extension && typeof extension === 'object') ? Object.assign({}, extension) : {};

    let base = {
        root: null,
        rootSelector: null,
        die: null,
        dieSelector: '.die',
        faceLinkSelector: '[data-dice-face-link], [data-dice-face], [href]',
        faceAttribute: 'data-face',
        activeClass: 'active',
        rollingClass: 'rolling',
        animationDuration: 1200,
        scopedFaceLinks: true,
        fallbackOnInvalidExpression: true,
        allowVisualFallbackWithoutEngine: true,

        sides: 20,
        expression: null,
        lastFace: null,
        timeoutId: null,
        mounted: false,
        disposed: false,
        lastResult: null,
        lastError: null,

        init: function () {
            this.disposed = false;
            this.mount();

            if (typeof sidesOrExpression === 'number') {
                this.sides = this.normalizeSides(sidesOrExpression);
            } else if (typeof sidesOrExpression === 'string') {
                this.expression = sidesOrExpression;
                let parsed = this.parseExpression(sidesOrExpression);
                if (parsed && parsed.count === 1 && Array.isArray(parsed.modifiers) && parsed.modifiers.length === 0) {
                    this.sides = this.normalizeSides(parsed.sides);
                }
            }
            return this;
        },

        mount: function () {
            if (this.disposed !== true && this.mounted === true) {
                return this.refresh();
            }

            this.disposed = false;
            this.refresh();
            this.mounted = true;
            return this;
        },

        refresh: function () {
            let $jq = this.getJquery();
            if (!$jq) {
                this.root = null;
                this.die = null;
                return this;
            }

            this.root = this.resolveRoot();
            this.die = this.resolveDie();
            return this;
        },

        destroy: function () {
            this.stopAnimation();
            this.root = null;
            this.die = null;
            this.mounted = false;
            this.disposed = true;
            this.lastResult = null;
            this.lastError = null;
            return this;
        },

        unmount: function () {
            return this.destroy();
        },

        getJquery: function () {
            if (typeof window !== 'undefined' && typeof window.$ === 'function') {
                return window.$;
            }
            if (typeof $ === 'function') {
                return $;
            }
            return null;
        },

        resolveRoot: function () {
            let $jq = this.getJquery();
            if (!$jq) {
                return null;
            }

            if (this.root && typeof this.root.find === 'function' && typeof this.root.length !== 'undefined') {
                return this.root;
            }

            if (this.rootSelector && typeof this.rootSelector === 'string' && this.rootSelector.trim() !== '') {
                return $jq(this.rootSelector).first();
            }

            if (ext.root) {
                if (typeof ext.root === 'string') {
                    return $jq(ext.root).first();
                }
                if (ext.root.jquery) {
                    return ext.root.first();
                }
                if (ext.root.nodeType === 1 || ext.root === document) {
                    return $jq(ext.root);
                }
            }

            return null;
        },

        resolveDie: function () {
            let $jq = this.getJquery();
            if (!$jq) {
                return null;
            }

            if (this.die && typeof this.die.length !== 'undefined') {
                return this.die;
            }

            let root = this.root && this.root.length ? this.root : this.resolveRoot();
            if (root && root.length) {
                let scoped = root.find(String(this.dieSelector || '.die')).first();
                if (scoped.length) {
                    return scoped;
                }
            }

            return $jq(String(this.dieSelector || '.die')).first();
        },

        normalizeSides: function (value) {
            let sides = parseInt(value, 10);
            if (isNaN(sides) || sides < 2) {
                sides = 20;
            }
            if (sides > 1000) {
                sides = 1000;
            }
            return sides;
        },

        setSides: function (value) {
            this.sides = this.normalizeSides(value);
            return this;
        },

        setExpression: function (expression) {
            this.expression = (expression == null) ? null : String(expression);
            return this;
        },

        setAnimationDuration: function (durationMs) {
            let value = parseInt(durationMs, 10);
            if (!isNaN(value) && value >= 0) {
                this.animationDuration = value;
            }
            return this;
        },

        randomInt: function (min, max) {
            min = parseInt(min, 10);
            max = parseInt(max, 10);
            if (isNaN(min) || isNaN(max) || max < min) {
                return 0;
            }
            return Math.floor(Math.random() * (max - min + 1)) + min;
        },

        resolveDiceEngine: function () {
            if (typeof window !== 'undefined' && typeof window.DiceEngine === 'function') {
                return window.DiceEngine();
            }
            if (typeof DiceEngine === 'function') {
                return DiceEngine();
            }
            return null;
        },

        parseExpression: function (expression) {
            let engine = this.resolveDiceEngine();
            if (!engine || typeof engine.parse !== 'function') {
                return null;
            }
            return engine.parse(expression);
        },

        parseExpressionDetailed: function (expression) {
            let engine = this.resolveDiceEngine();
            if (!engine) {
                return null;
            }
            if (typeof engine.parseDetailed === 'function') {
                return engine.parseDetailed(expression);
            }
            let parsed = (typeof engine.parse === 'function') ? engine.parse(expression) : null;
            if (parsed) {
                return {
                    ok: true,
                    value: parsed,
                    code: null,
                    message: '',
                    input: String(expression || ''),
                    normalized: String(parsed.expression || '')
                };
            }
            return null;
        },

        rollExpression: function (expression) {
            let engine = this.resolveDiceEngine();
            if (!engine || typeof engine.roll !== 'function') {
                return null;
            }
            return engine.roll(expression);
        },

        rollExpressionDetailed: function (expression) {
            let engine = this.resolveDiceEngine();
            if (!engine) {
                return null;
            }
            if (typeof engine.rollDetailed === 'function') {
                return engine.rollDetailed(expression);
            }
            let rolled = (typeof engine.roll === 'function') ? engine.roll(expression) : null;
            if (rolled) {
                return {
                    ok: true,
                    value: rolled,
                    code: null,
                    message: '',
                    input: String(expression || ''),
                    normalized: String(rolled.expression || '')
                };
            }
            return null;
        },

        randomFace: function () {
            let face = this.randomInt(1, this.sides);
            if (this.sides > 2 && face === this.lastFace) {
                face = this.randomInt(1, this.sides);
            }
            this.lastFace = face;
            return face;
        },

        _faceLinkMatches: function (node, face) {
            if (!node || !node.getAttribute) {
                return false;
            }
            let value = String(face);

            let dataFaceLink = node.getAttribute('data-dice-face-link');
            if (dataFaceLink != null && String(dataFaceLink) === value) {
                return true;
            }

            let dataFace = node.getAttribute('data-dice-face');
            if (dataFace != null && String(dataFace) === value) {
                return true;
            }

            let href = node.getAttribute('href');
            if (href != null && String(href) === value) {
                return true;
            }

            return false;
        },

        getFaceLinksScope: function () {
            let $jq = this.getJquery();
            if (!$jq) {
                return null;
            }

            if (this.scopedFaceLinks !== false) {
                if (this.root && this.root.length) {
                    return this.root;
                }
                if (this.die && this.die.length) {
                    let parent = this.die.parent();
                    if (parent && parent.length) {
                        return parent;
                    }
                }
            }

            return (typeof document !== 'undefined') ? $jq(document) : null;
        },

        getFaceLinks: function () {
            let scope = this.getFaceLinksScope();
            if (!scope || typeof scope.find !== 'function') {
                return null;
            }
            return scope.find(String(this.faceLinkSelector || '[href]'));
        },

        clearFaceLinksActive: function () {
            let links = this.getFaceLinks();
            if (links && links.length) {
                links.removeClass(String(this.activeClass || 'active'));
            }
            return this;
        },

        setFace: function (face) {
            return this.rollTo(face);
        },

        rollTo: function (face) {
            let faceValue = parseInt(face, 10) || 1;
            this.stopAnimation({ keepFace: true });
            this.refresh();

            let links = this.getFaceLinks();
            if (links && links.length) {
                this.clearFaceLinksActive();

                let activeClass = String(this.activeClass || 'active');
                let self = this;
                let target = links.filter(function (index, node) {
                    return self._faceLinkMatches(node, faceValue);
                }).first();
                if (target && target.length) {
                    target.addClass(activeClass);
                }
            }

            if (this.die && this.die.length) {
                this.die.attr(String(this.faceAttribute || 'data-face'), faceValue);
            }

            this.lastFace = faceValue;
            return this;
        },

        stopAnimation: function (options) {
            let cfg = (options && typeof options === 'object') ? options : {};
            clearTimeout(this.timeoutId);
            this.timeoutId = null;

            if (cfg.keepFace !== true) {
                this.lastFace = null;
            }

            if (this.die && this.die.length) {
                this.die.removeClass(String(this.rollingClass || 'rolling'));
            }

            return this;
        },

        _buildFallbackResult: function (face, expression, reason, errorResult) {
            let payload = {
                expression: '1d' + this.sides,
                count: 1,
                sides: this.sides,
                rolls: [face],
                modifiers: [],
                subtotal: face,
                modifierTotal: 0,
                total: face,
                fallback: true,
                fallbackReason: String(reason || 'visual')
            };

            if (expression != null) {
                payload.requestedExpression = String(expression);
            }

            if (errorResult && typeof errorResult === 'object') {
                payload.error = {
                    code: errorResult.code || 'invalid_expression',
                    message: errorResult.message || 'Espressione non valida.'
                };
            }

            return payload;
        },

        _animateToFace: function (face) {
            this.refresh();

            if (this.die && this.die.length) {
                let self = this;
                this.die.addClass(String(this.rollingClass || 'rolling'));
                clearTimeout(this.timeoutId);
                this.timeoutId = setTimeout(function () {
                    self.timeoutId = null;
                    self.die.removeClass(String(self.rollingClass || 'rolling'));
                    self.rollTo(face);
                }, parseInt(this.animationDuration, 10) || 0);
            } else {
                this.rollTo(face);
            }

            return this;
        },

        roll: function (expression) {
            let expr = (typeof expression !== 'undefined' && expression !== null)
                ? expression
                : (this.expression || ('1d' + this.sides));

            this.lastError = null;

            let detailed = this.rollExpressionDetailed(expr);
            let result = null;

            if (detailed && detailed.ok === true) {
                result = detailed.value;
            } else if (detailed && detailed.ok === false) {
                this.lastError = detailed;
                if (this.fallbackOnInvalidExpression !== true) {
                    this.lastResult = detailed;
                    return detailed;
                }
            } else if (this.allowVisualFallbackWithoutEngine !== true) {
                let unavailable = {
                    ok: false,
                    code: 'engine_unavailable',
                    message: 'DiceEngine non disponibile.',
                    input: (expr == null) ? '' : String(expr),
                    normalized: ''
                };
                this.lastError = unavailable;
                this.lastResult = unavailable;
                return unavailable;
            }

            let face = this.randomFace();
            if (result && result.count === 1 && Array.isArray(result.modifiers) && result.modifiers.length === 0) {
                face = result.rolls[0];
                this.lastFace = face;
            }

            this._animateToFace(face);

            let finalResult = result || this._buildFallbackResult(
                face,
                expr,
                detailed && detailed.ok === false ? 'invalid_expression' : 'engine_unavailable',
                (detailed && detailed.ok === false) ? detailed : null
            );

            this.lastResult = finalResult;
            return finalResult;
        }
    };

    let o = Object.assign({}, base, ext);
    return o.init();
}

if (typeof window !== 'undefined') {
    window.Dice = Dice;
}
