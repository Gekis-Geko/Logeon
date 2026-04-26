const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function resolveModule(name) {
    if (!globalWindow.RuntimeBootstrap || typeof globalWindow.RuntimeBootstrap.resolveAppModule !== 'function') {
        return null;
    }
    try {
        return globalWindow.RuntimeBootstrap.resolveAppModule(name);
    } catch (error) {
        return null;
    }
}

function toInt(value, fallback) {
    var parsed = parseInt(String(value == null ? '' : value), 10);
    if (!isFinite(parsed)) {
        return parseInt(String(fallback == null ? '0' : fallback), 10) || 0;
    }
    return parsed;
}

function escapeHtml(value) {
    return String(value == null ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function formatNumber(value) {
    if (typeof globalWindow.Utils === 'function') {
        return globalWindow.Utils().formatNumber(value);
    }
    var parsed = Number(value);
    if (!isFinite(parsed)) {
        parsed = 0;
    }
    return String(Math.round(parsed));
}

function showBankError(error, fallback) {
    if (globalWindow.GameFeatureError && typeof globalWindow.GameFeatureError.toastMapped === 'function') {
        globalWindow.GameFeatureError.toastMapped(error, fallback || 'Operazione non riuscita.', {
            map: {
                bank_amount_invalid: 'Importo non valido.',
                bank_insufficient_cash: 'Contanti insufficienti.',
                bank_insufficient_funds: 'Saldo banca insufficiente.',
                bank_target_invalid: 'Destinatario non valido.',
                bank_target_same: 'Non puoi inviare un bonifico a te stesso.',
                currency_default_missing: 'Valuta principale non configurata.',
                character_invalid: 'Personaggio non valido.'
            },
            validationCodes: [
                'bank_amount_invalid',
                'bank_insufficient_cash',
                'bank_insufficient_funds',
                'bank_target_invalid',
                'bank_target_same',
                'currency_default_missing',
                'character_invalid'
            ],
            validationType: 'warning',
            defaultType: 'error'
        });
        return;
    }

    if (globalWindow.Toast && typeof globalWindow.Toast.show === 'function') {
        globalWindow.Toast.show({
            body: fallback || 'Operazione non riuscita.',
            type: 'error'
        });
    }
}

function showToast(type, body) {
    if (globalWindow.Toast && typeof globalWindow.Toast.show === 'function') {
        globalWindow.Toast.show({
            type: type,
            body: body
        });
    }
}

function GameBankPage(extension) {
    var page = {
        root: null,
        moduleApi: null,
        summaryData: null,
        searchTimer: null,
        searchBusy: false,
        operationBusy: false,

        init: function () {
            this.root = document.getElementById('bank-page');
            if (!this.root) {
                return this;
            }
            this.bind();
            this.loadSummary();
            return this;
        },

        resolveModuleApi: function () {
            if (
                this.moduleApi
                && typeof this.moduleApi.summary === 'function'
                && typeof this.moduleApi.deposit === 'function'
                && typeof this.moduleApi.withdraw === 'function'
                && typeof this.moduleApi.transfer === 'function'
            ) {
                return this.moduleApi;
            }
            return resolveModule('game.bank');
        },

        bind: function () {
            var self = this;
            var refreshBtn = this.root.querySelector('[data-action="bank-refresh"]');
            if (refreshBtn) {
                refreshBtn.addEventListener('click', function (event) {
                    event.preventDefault();
                    self.loadSummary();
                });
            }

            var fillDepositBtn = this.root.querySelector('[data-action="bank-fill-deposit-all"]');
            if (fillDepositBtn) {
                fillDepositBtn.addEventListener('click', function (event) {
                    event.preventDefault();
                    self.fillAllDeposit();
                });
            }

            var fillWithdrawBtn = this.root.querySelector('[data-action="bank-fill-withdraw-all"]');
            if (fillWithdrawBtn) {
                fillWithdrawBtn.addEventListener('click', function (event) {
                    event.preventDefault();
                    self.fillAllWithdraw();
                });
            }

            var depositForm = document.getElementById('bank-deposit-form');
            if (depositForm) {
                depositForm.addEventListener('submit', function (event) {
                    event.preventDefault();
                    self.submitDeposit();
                });
            }

            var withdrawForm = document.getElementById('bank-withdraw-form');
            if (withdrawForm) {
                withdrawForm.addEventListener('submit', function (event) {
                    event.preventDefault();
                    self.submitWithdraw();
                });
            }

            var transferForm = document.getElementById('bank-transfer-form');
            if (transferForm) {
                transferForm.addEventListener('submit', function (event) {
                    event.preventDefault();
                    self.submitTransfer();
                });
            }

            var targetNameInput = document.getElementById('bank-transfer-target-name');
            if (targetNameInput) {
                targetNameInput.addEventListener('input', function () {
                    self.onRecipientSearchInput();
                });
            }

            var suggestions = document.getElementById('bank-transfer-suggestions');
            if (suggestions) {
                suggestions.addEventListener('click', function (event) {
                    var trigger = event.target && event.target.closest
                        ? event.target.closest('[data-action="bank-select-recipient"]')
                        : null;
                    if (!trigger) {
                        return;
                    }
                    event.preventDefault();
                    self.selectRecipient(
                        toInt(trigger.getAttribute('data-character-id'), 0),
                        String(trigger.getAttribute('data-character-name') || '')
                    );
                });
            }

            document.addEventListener('click', function (event) {
                var target = event.target;
                if (!target || !target.closest) {
                    return;
                }
                if (target.closest('#bank-transfer-target-name, #bank-transfer-suggestions')) {
                    return;
                }
                self.hideRecipientSuggestions();
            });
        },

        setLoading: function (flag) {
            var loading = document.getElementById('bank-movements-loading');
            if (!loading) {
                return;
            }
            if (flag) {
                loading.classList.remove('d-none');
            } else {
                loading.classList.add('d-none');
            }
        },

        setMovementsEmpty: function (flag) {
            var empty = document.getElementById('bank-movements-empty');
            if (!empty) {
                return;
            }
            if (flag) {
                empty.classList.remove('d-none');
            } else {
                empty.classList.add('d-none');
            }
        },

        loadSummary: function () {
            var mod = this.resolveModuleApi();
            if (!mod || typeof mod.summary !== 'function') {
                return;
            }

            var self = this;
            this.setLoading(true);
            mod.summary({ limit: 20 }).then(function (response) {
                self.setLoading(false);
                var summary = response && response.dataset ? response.dataset : null;
                self.applySummary(summary);
            }).catch(function (error) {
                self.setLoading(false);
                showBankError(error, 'Errore durante il caricamento della banca.');
            });
        },

        applySummary: function (summary) {
            this.summaryData = summary || null;
            this.renderBalances();
            this.renderMovements();
        },

        renderBalances: function () {
            var balances = this.summaryData && this.summaryData.balances ? this.summaryData.balances : {};
            var currency = this.summaryData && this.summaryData.currency ? this.summaryData.currency : {};

            var cash = toInt(balances.cash, 0);
            var bank = toInt(balances.bank, 0);
            var currencyLabel = String(currency.code || currency.name || '').trim();
            var image = String(currency.image || '').trim();

            var cashEl = document.getElementById('bank-cash-balance');
            var bankEl = document.getElementById('bank-account-balance');
            if (!cashEl || !bankEl) {
                return;
            }

            var cashText = formatNumber(cash) + (currencyLabel !== '' ? (' ' + currencyLabel) : '');
            var bankText = formatNumber(bank) + (currencyLabel !== '' ? (' ' + currencyLabel) : '');

            if (image !== '') {
                cashEl.innerHTML = '<img src="' + escapeHtml(image) + '" alt="" class="me-2 rounded-1" style="width:20px;height:20px;object-fit:cover;">' + escapeHtml(cashText);
                bankEl.innerHTML = '<img src="' + escapeHtml(image) + '" alt="" class="me-2 rounded-1" style="width:20px;height:20px;object-fit:cover;">' + escapeHtml(bankText);
                return;
            }

            cashEl.textContent = cashText;
            bankEl.textContent = bankText;
        },

        renderMovements: function () {
            var list = document.getElementById('bank-movements-list');
            if (!list) {
                return;
            }

            var rows = (this.summaryData && Array.isArray(this.summaryData.movements))
                ? this.summaryData.movements
                : [];

            list.innerHTML = '';
            if (!rows.length) {
                this.setMovementsEmpty(true);
                return;
            }

            this.setMovementsEmpty(false);
            var html = [];
            for (var i = 0; i < rows.length; i += 1) {
                var row = rows[i] || {};
                var amount = toInt(row.amount, 0);
                var amountClass = amount >= 0 ? 'text-success' : 'text-danger';
                var amountIcon = amount >= 0 ? 'bi-arrow-down-circle' : 'bi-arrow-up-circle';
                var label = String(row.source_label || 'Movimento');
                var description = String(row.description || '');
                var date = String(row.date_created || '');

                var noteHtml = '';
                if (row.meta && typeof row.meta === 'object' && String(row.meta.note || '').trim() !== '') {
                    noteHtml = '<div class="small text-muted mt-1"><i class="bi bi-chat-left-text me-1"></i>' + escapeHtml(String(row.meta.note || '').trim()) + '</div>';
                }

                html.push(
                    '<div class="list-group-item py-2">'
                    + '<div class="d-flex justify-content-between align-items-start gap-2">'
                    + '<div>'
                    + '<div class="small fw-semibold">' + escapeHtml(label) + '</div>'
                    + '<div class="small text-muted">' + escapeHtml(description) + '</div>'
                    + noteHtml
                    + '</div>'
                    + '<div class="text-end">'
                    + '<div class="small fw-semibold ' + amountClass + '"><i class="bi ' + amountIcon + ' me-1"></i>' + (amount >= 0 ? '+' : '') + formatNumber(amount) + '</div>'
                    + '<div class="small text-muted">' + escapeHtml(date) + '</div>'
                    + '</div>'
                    + '</div>'
                    + '</div>'
                );
            }
            list.innerHTML = html.join('');
        },

        fillAllDeposit: function () {
            var amountInput = document.getElementById('bank-deposit-amount');
            var balances = this.summaryData && this.summaryData.balances ? this.summaryData.balances : {};
            var cash = toInt(balances.cash, 0);
            if (!amountInput || cash <= 0) {
                return;
            }
            amountInput.value = String(cash);
            amountInput.focus();
        },

        fillAllWithdraw: function () {
            var amountInput = document.getElementById('bank-withdraw-amount');
            var balances = this.summaryData && this.summaryData.balances ? this.summaryData.balances : {};
            var bank = toInt(balances.bank, 0);
            if (!amountInput || bank <= 0) {
                return;
            }
            amountInput.value = String(bank);
            amountInput.focus();
        },

        submitDeposit: function () {
            if (this.operationBusy) {
                return;
            }

            var mod = this.resolveModuleApi();
            if (!mod || typeof mod.deposit !== 'function') {
                return;
            }

            var amountInput = document.getElementById('bank-deposit-amount');
            var amount = toInt(amountInput ? amountInput.value : 0, 0);
            if (amount <= 0) {
                showToast('warning', 'Inserisci un importo valido.');
                return;
            }

            var self = this;
            this.operationBusy = true;
            mod.deposit({ amount: amount }).then(function (response) {
                self.operationBusy = false;
                var summary = response && response.dataset ? response.dataset.summary : null;
                self.applySummary(summary);
                if (amountInput) {
                    amountInput.value = '';
                }
                showToast('success', 'Versamento completato.');
            }).catch(function (error) {
                self.operationBusy = false;
                showBankError(error, 'Versamento non riuscito.');
            });
        },

        submitWithdraw: function () {
            if (this.operationBusy) {
                return;
            }

            var mod = this.resolveModuleApi();
            if (!mod || typeof mod.withdraw !== 'function') {
                return;
            }

            var amountInput = document.getElementById('bank-withdraw-amount');
            var amount = toInt(amountInput ? amountInput.value : 0, 0);
            if (amount <= 0) {
                showToast('warning', 'Inserisci un importo valido.');
                return;
            }

            var self = this;
            this.operationBusy = true;
            mod.withdraw({ amount: amount }).then(function (response) {
                self.operationBusy = false;
                var summary = response && response.dataset ? response.dataset.summary : null;
                self.applySummary(summary);
                if (amountInput) {
                    amountInput.value = '';
                }
                showToast('success', 'Prelievo completato.');
            }).catch(function (error) {
                self.operationBusy = false;
                showBankError(error, 'Prelievo non riuscito.');
            });
        },

        submitTransfer: function () {
            if (this.operationBusy) {
                return;
            }

            var mod = this.resolveModuleApi();
            if (!mod || typeof mod.transfer !== 'function') {
                return;
            }

            var targetIdInput = document.getElementById('bank-transfer-target-id');
            var targetNameInput = document.getElementById('bank-transfer-target-name');
            var amountInput = document.getElementById('bank-transfer-amount');
            var noteInput = document.getElementById('bank-transfer-note');

            var targetId = toInt(targetIdInput ? targetIdInput.value : 0, 0);
            var amount = toInt(amountInput ? amountInput.value : 0, 0);
            var note = noteInput ? String(noteInput.value || '').trim() : '';

            if (targetId <= 0) {
                showToast('warning', 'Seleziona un destinatario dalla ricerca.');
                return;
            }
            if (amount <= 0) {
                showToast('warning', 'Inserisci un importo valido.');
                return;
            }

            var self = this;
            this.operationBusy = true;
            mod.transfer({
                target_character_id: targetId,
                amount: amount,
                note: note
            }).then(function (response) {
                self.operationBusy = false;
                var summary = response && response.dataset ? response.dataset.summary : null;
                var result = response && response.dataset ? response.dataset.result : {};
                self.applySummary(summary);

                if (targetIdInput) {
                    targetIdInput.value = '';
                }
                if (targetNameInput) {
                    targetNameInput.value = '';
                }
                if (amountInput) {
                    amountInput.value = '';
                }
                if (noteInput) {
                    noteInput.value = '';
                }
                self.hideRecipientSuggestions();

                var targetName = result && result.target ? String(result.target.character_name || '') : '';
                if (targetName !== '') {
                    showToast('success', 'Bonifico inviato a ' + targetName + '.');
                } else {
                    showToast('success', 'Bonifico inviato.');
                }
            }).catch(function (error) {
                self.operationBusy = false;
                showBankError(error, 'Bonifico non riuscito.');
            });
        },

        onRecipientSearchInput: function () {
            var self = this;
            var input = document.getElementById('bank-transfer-target-name');
            var targetIdInput = document.getElementById('bank-transfer-target-id');
            if (!input) {
                return;
            }

            if (targetIdInput) {
                targetIdInput.value = '';
            }

            var query = String(input.value || '').trim();
            if (this.searchTimer) {
                globalWindow.clearTimeout(this.searchTimer);
                this.searchTimer = null;
            }

            if (query.length < 2) {
                this.hideRecipientSuggestions();
                return;
            }

            this.searchTimer = globalWindow.setTimeout(function () {
                self.searchRecipients(query);
            }, 240);
        },

        searchRecipients: function (query) {
            var self = this;
            if (this.searchBusy) {
                return;
            }
            if (!globalWindow.Request || !Request.http || typeof Request.http.post !== 'function') {
                return;
            }

            this.searchBusy = true;
            Request.http.post('/list/characters/search', {
                query: query
            }).then(function (response) {
                self.searchBusy = false;
                var rows = response && Array.isArray(response.dataset) ? response.dataset : [];
                self.renderRecipientSuggestions(rows);
            }).catch(function () {
                self.searchBusy = false;
                self.hideRecipientSuggestions();
            });
        },

        renderRecipientSuggestions: function (rows) {
            var box = document.getElementById('bank-transfer-suggestions');
            if (!box) {
                return;
            }

            box.innerHTML = '';
            if (!rows || !rows.length) {
                box.classList.add('d-none');
                return;
            }

            var html = [];
            for (var i = 0; i < rows.length; i += 1) {
                var row = rows[i] || {};
                var id = toInt(row.id, 0);
                if (id <= 0) {
                    continue;
                }
                var fullName = String((row.name || '') + ' ' + (row.surname || '')).trim();
                if (fullName === '') {
                    fullName = 'PG #' + id;
                }
                var avatar = String(row.avatar || '').trim();
                var avatarHtml = avatar !== ''
                    ? '<img src="' + escapeHtml(avatar) + '" alt="" class="rounded border border-dark-subtle me-2" style="width:22px;height:22px;object-fit:cover;">'
                    : '<span class="bi bi-person-circle me-2"></span>';

                html.push(
                    '<button type="button" class="list-group-item list-group-item-action py-1 small"'
                    + ' data-action="bank-select-recipient"'
                    + ' data-character-id="' + id + '"'
                    + ' data-character-name="' + escapeHtml(fullName) + '">'
                    + avatarHtml + escapeHtml(fullName)
                    + '</button>'
                );
            }

            if (!html.length) {
                box.classList.add('d-none');
                return;
            }

            box.innerHTML = html.join('');
            box.classList.remove('d-none');
        },

        selectRecipient: function (characterId, fullName) {
            var idInput = document.getElementById('bank-transfer-target-id');
            var nameInput = document.getElementById('bank-transfer-target-name');
            if (idInput) {
                idInput.value = characterId > 0 ? String(characterId) : '';
            }
            if (nameInput) {
                nameInput.value = fullName || '';
            }
            this.hideRecipientSuggestions();
        },

        hideRecipientSuggestions: function () {
            var box = document.getElementById('bank-transfer-suggestions');
            if (!box) {
                return;
            }
            box.classList.add('d-none');
            box.innerHTML = '';
        }
    };

    var options = (extension && typeof extension === 'object') ? extension : {};
    return Object.assign(page, options).init();
}

globalWindow.GameBankPage = GameBankPage;
export { GameBankPage as GameBankPage };
export default GameBankPage;

