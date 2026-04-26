const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createGameLifecycleModule() {
    return {
        ctx: null,

        mount: function (ctx, options) {
            this.ctx = ctx || null;
            this.loadIntoProfile();
            return this;
        },

        unmount: function () {},

        currentPhase: function (payload) {
            return this.request('/lifecycle/current', 'lifecycleCurrent', payload || {});
        },

        history: function (payload) {
            return this.request('/lifecycle/history', 'lifecycleHistory', payload || {});
        },

        loadIntoProfile: function () {
            var self    = this;
            var section = document.getElementById('profile-lifecycle-section');
            if (!section) { return; }

            this.currentPhase({}).then(function (response) {
                var data = response && response.dataset ? response.dataset : null;
                self.renderPhase(section, data);
            }).catch(function () {
                section.innerHTML = '';
            });
        },

        renderPhase: function (section, data) {
            if (!data || !data.phase) {
                section.innerHTML = '';
                return;
            }

            var phase = data.phase;
            var color = phase.color_hex || '#6c757d';
            var icon  = phase.icon ? '<img src="' + escapeHtml(phase.icon) + '" width="14" height="14" class="me-1" style="object-fit:contain;vertical-align:middle;" alt="">' : '';
            var date  = data.date_applied || data.date_created || '';

            section.innerHTML =
                '<div class="card mt-3">'
                + '<div class="card-header d-flex justify-content-between align-items-center">'
                + '<span class="fw-bold small"><i class="bi bi-arrow-repeat me-1"></i>Ciclo vita</span>'
                + '<span class="badge" style="background-color:' + escapeHtml(color) + '">' + icon + escapeHtml(phase.name || '') + '</span>'
                + '</div>'
                + (phase.description ? '<div class="card-body py-2"><p class="small text-muted mb-0">' + escapeHtml(phase.description) + '</p></div>' : '')
                + (date ? '<div class="card-footer text-muted" style="font-size:.75rem;">In questa fase dal: ' + escapeHtml(String(date)) + '</div>' : '')
                + '</div>';
        },

        request: function (url, action, payload) {
            if (!this.ctx || !this.ctx.services || !this.ctx.services.http) {
                return Promise.reject(new Error('HTTP service not available.'));
            }
            return this.ctx.services.http.request({ url: url, action: action, payload: payload || {} });
        }
    };
}

function escapeHtml(value) {
    return String(value || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

globalWindow.GameLifecycleModuleFactory = createGameLifecycleModule;
export { createGameLifecycleModule as GameLifecycleModuleFactory };
export default createGameLifecycleModule;

