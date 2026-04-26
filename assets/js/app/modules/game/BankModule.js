const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createBankModule() {
    return {
        ctx: null,
        options: {},

        mount: function (ctx, options) {
            this.ctx = ctx || null;
            this.options = options || {};
            return this;
        },

        unmount: function () {},

        summary: function (payload) {
            return this.request('/bank/summary', 'bankSummary', payload || {});
        },

        deposit: function (payload) {
            return this.request('/bank/deposit', 'bankDeposit', payload || {});
        },

        withdraw: function (payload) {
            return this.request('/bank/withdraw', 'bankWithdraw', payload || {});
        },

        transfer: function (payload) {
            return this.request('/bank/transfer', 'bankTransfer', payload || {});
        },

        request: function (url, action, payload) {
            if (!this.ctx || !this.ctx.services || !this.ctx.services.http) {
                return Promise.reject(new Error('HTTP service not available.'));
            }

            return this.ctx.services.http.request({
                url: url,
                action: action,
                payload: payload || {}
            });
        }
    };
}

globalWindow.GameBankModuleFactory = createBankModule;
export { createBankModule as GameBankModuleFactory };
export default createBankModule;

