function PollManager(extension) {
    function isRuntimeDebugEnabled() {
        if (typeof window === 'undefined') {
            return false;
        }
        if (typeof window.__APP_RUNTIME_DEBUG === 'boolean') {
            return window.__APP_RUNTIME_DEBUG === true;
        }
        if (window.APP_CONFIG && parseInt(window.APP_CONFIG.runtime_debug, 10) === 1) {
            return true;
        }
        try {
            if (window.localStorage && window.localStorage.getItem('app.runtime.debug') === '1') {
                return true;
            }
        } catch (error) {}
        return false;
    }

    function pollDebug() {
        if (!isRuntimeDebugEnabled() || typeof console === 'undefined' || typeof console.info !== 'function') {
            return;
        }
        var args = Array.prototype.slice.call(arguments);
        args.unshift('[PollManager]');
        console.info.apply(console, args);
    }

    let base = {
        polls: {},
        isBound: false,
        isPausedByVisibility: false,

        init: function () {
            if (this.isBound || typeof document === 'undefined') {
                return this;
            }

            var self = this;
            this.isBound = true;
            document.addEventListener('visibilitychange', function () {
                if (document.hidden) {
                    self.pauseAllByVisibility();
                } else {
                    self.resumeAllByVisibility();
                }
            });

            return this;
        },

        start: function (key, callback, intervalMs, options) {
            if (!key || typeof callback !== 'function') {
                return null;
            }

            options = options || {};
            var ms = parseInt(intervalMs, 10);
            if (isNaN(ms) || ms < 250) {
                ms = 1000;
            }

            this.stop(key);
            this.polls[key] = {
                key: key,
                callback: callback,
                intervalMs: ms,
                options: {
                    immediate: options.immediate === true,
                    pauseOnHidden: options.pauseOnHidden !== false
                },
                timerId: null,
                paused: false
            };
            pollDebug('start', key, ms + 'ms');

            return this.resume(key);
        },

        resume: function (key) {
            var poll = this.polls[key];
            if (!poll) {
                return null;
            }

            if (poll.timerId) {
                clearInterval(poll.timerId);
            }

            var self = this;
            poll.paused = false;
            poll.timerId = setInterval(function () {
                poll.callback();
            }, poll.intervalMs);
            pollDebug('resume', key, poll.intervalMs + 'ms');

            if (poll.options.immediate === true) {
                try {
                    poll.callback();
                } catch (e) {
                    if (typeof console !== 'undefined' && console.error) {
                        console.error('PollManager immediate callback error:', key, e);
                    }
                }
            }

            return poll.timerId;
        },

        pause: function (key) {
            var poll = this.polls[key];
            if (!poll) {
                return;
            }
            poll.paused = true;
            if (poll.timerId) {
                clearInterval(poll.timerId);
                poll.timerId = null;
            }
            pollDebug('pause', key);
        },

        stop: function (key) {
            var poll = this.polls[key];
            if (!poll) {
                return;
            }
            if (poll.timerId) {
                clearInterval(poll.timerId);
            }
            delete this.polls[key];
            pollDebug('stop', key);
        },

        stopAll: function () {
            var keys = Object.keys(this.polls);
            for (var i = 0; i < keys.length; i++) {
                this.stop(keys[i]);
            }
        },

        pauseAllByVisibility: function () {
            this.isPausedByVisibility = true;
            var keys = Object.keys(this.polls);
            for (var i = 0; i < keys.length; i++) {
                var key = keys[i];
                var poll = this.polls[key];
                if (!poll || poll.options.pauseOnHidden === false) {
                    continue;
                }
                this.pause(key);
            }
        },

        resumeAllByVisibility: function () {
            this.isPausedByVisibility = false;
            var keys = Object.keys(this.polls);
            for (var i = 0; i < keys.length; i++) {
                var key = keys[i];
                var poll = this.polls[key];
                if (!poll || poll.options.pauseOnHidden === false) {
                    continue;
                }
                if (!poll.timerId) {
                    this.resume(key);
                }
            }
        }
    };

    if (typeof window !== 'undefined') {
        if (!window.__poll_manager_instance) {
            window.__poll_manager_instance = Object.assign({}, base, extension).init();
        }
        return window.__poll_manager_instance;
    }

    return Object.assign({}, base, extension).init();
}

if (typeof window !== 'undefined') {
    window.PollManager = PollManager;
}
