(function (window) {
    'use strict';

    function resolveModule(name) {
        if (!window.RuntimeBootstrap || typeof window.RuntimeBootstrap.resolveAppModule !== 'function') {
            return null;
        }
        try {
            return window.RuntimeBootstrap.resolveAppModule(name);
        } catch (error) {
            return null;
        }
    }


    function normalizeSettingsError(error, fallback) {
        if (window.GameFeatureError && typeof window.GameFeatureError.normalize === 'function') {
            return window.GameFeatureError.normalize(error, fallback || 'Operazione non riuscita.');
        }
        if (typeof error === 'string' && error.trim() !== '') {
            return error.trim();
        }
        if (error && typeof error.message === 'string' && error.message.trim() !== '') {
            return error.message.trim();
        }
        if (error && typeof error.error === 'string' && error.error.trim() !== '') {
            return error.error.trim();
        }
        return fallback || 'Operazione non riuscita.';
    }

    function callSettingsModule(method, payload, onSuccess, onError) {
        if (typeof resolveModule !== 'function') {
            if (typeof onError === 'function') {
                onError(new Error('Settings module resolver not available: ' + method));
            }
            return false;
        }

        var mod = resolveModule('game.settings');
        if (!mod || typeof mod[method] !== 'function') {
            if (typeof onError === 'function') {
                onError(new Error('Settings module method not available: ' + method));
            }
            return false;
        }

        mod[method](payload).then(function (response) {
            if (typeof onSuccess === 'function') {
                onSuccess(response);
            }
        }).catch(function (error) {
            if (typeof onError === 'function') {
                onError(error);
            }
        });

        return true;
    }

    function GameSettingsPage(extension) {
            let page = {
                dataset: null,
                dmControl: null,
                inviteControl: null,
                notifyControl: null,
                deleteConfirmControl: null,
                deleteCountdownTimer: null,
                deleteCountdownSeconds: 8,
                init: function () {
                    if (!$('#settings-page').length) {
                        return this;
                    }

                    this.bindControls();
                    this.get();

                    return this;
                },
                bindControls: function () {
                    if (this.dmControl && typeof this.dmControl.destroy === 'function') {
                        this.dmControl.destroy();
                    }
                    if (this.inviteControl && typeof this.inviteControl.destroy === 'function') {
                        this.inviteControl.destroy();
                    }
                    if (this.notifyControl && typeof this.notifyControl.destroy === 'function') {
                        this.notifyControl.destroy();
                    }
                    if (this.deleteConfirmControl && typeof this.deleteConfirmControl.destroy === 'function') {
                        this.deleteConfirmControl.destroy();
                    }

                    if (typeof RadioGroup === 'function' && document.getElementById('settings-dm-policy')) {
                        this.dmControl = RadioGroup('#settings-dm-policy', {
                            btnClass: 'btn-sm',
                            options: [
                                { label: 'Tutti', value: '0' },
                                { label: 'Solo gilda', value: '1' },
                                { label: 'Nessuno', value: '2' }
                            ]
                        });
                    }

                    if (typeof RadioGroup === 'function' && document.getElementById('settings-invite-policy')) {
                        this.inviteControl = RadioGroup('#settings-invite-policy', {
                            btnClass: 'btn-sm',
                            options: [
                                { label: 'Tutti', value: '0' },
                                { label: 'Solo gilda', value: '1' },
                                { label: 'Nessuno', value: '2' }
                            ]
                        });
                    }

                    if (typeof CheckGroup === 'function' && document.getElementById('settings-notifications')) {
                        this.notifyControl = CheckGroup('#settings-notifications', {
                            btnClass: 'btn-sm',
                            options: [
                                { label: 'Messaggi', value: 'messages' },
                                { label: 'Inviti', value: 'invites' },
                                { label: 'News', value: 'news' }
                            ]
                        });
                    }

                    if (typeof CheckGroup === 'function' && document.getElementById('settings-delete-confirm')) {
                        this.deleteConfirmControl = CheckGroup('#settings-delete-confirm', {
                            btnClass: 'btn-sm',
                            options: [
                                { label: 'Ho capito', value: '1' }
                            ]
                        });
                    }

                    var self = this;
                    $('[data-settings-save]').off('click.settings').on('click.settings', function () {
                        self.savePreferences();
                    });
                    $('[data-password-submit]').off('click.settings').on('click.settings', function () {
                        self.changePassword();
                    });
                    $('[data-sessions-revoke]').off('click.settings').on('click.settings', function () {
                        self.revokeSessions();
                    });
                    $('[data-name-request-submit]').off('click.settings').on('click.settings', function () {
                        self.requestNameChange();
                    });
                    $('[data-delete-request]').off('click.settings').on('click.settings', function () {
                        self.requestDelete();
                    });
                    $('[data-delete-cancel]').off('click.settings').on('click.settings', function () {
                        self.cancelDelete();
                    });
                    let confirmInput = this.deleteConfirmControl ? this.deleteConfirmControl.input : $('#settings-delete-confirm');
                    confirmInput.off('change.settings').on('change.settings', function () {
                        self.updateDeleteState();
                    });
                },
                get: function () {
                    var self = this;
                    var id = Storage().get('characterId');
                    if (!id) {
                        return;
                    }
                    callSettingsModule('getProfile', id, function (response) {
                        if (!response || !response.dataset) {
                            return;
                        }
                        self.dataset = response.dataset;
                        self.build();
                    }, function (error) {
                        Toast.show({
                            body: normalizeSettingsError(error, 'Errore durante caricamento impostazioni.'),
                            type: 'error'
                        });
                    });
                },
                build: function () {
                    if (!this.dataset) {
                        return;
                    }

                    if (this.dmControl && this.dmControl.input) {
                        let val = (this.dataset.dm_policy !== undefined && this.dataset.dm_policy !== null) ? this.dataset.dm_policy : 0;
                        this.dmControl.input.val(val).change();
                    }

                    if (this.inviteControl && this.inviteControl.input) {
                        let val = (this.dataset.invite_policy !== undefined && this.dataset.invite_policy !== null) ? this.dataset.invite_policy : 0;
                        this.inviteControl.input.val(val).change();
                    }

                    if (this.notifyControl && this.notifyControl.input) {
                        let selected = [];
                        if (parseInt(this.dataset.notify_messages, 10) === 1) {
                            selected.push('messages');
                        }
                        if (parseInt(this.dataset.notify_invites, 10) === 1) {
                            selected.push('invites');
                        }
                        if (parseInt(this.dataset.notify_news, 10) === 1) {
                            selected.push('news');
                        }
                        this.notifyControl.input.val(selected).change();
                    }

                    this.buildNameRequest();
                    this.buildDeleteStatus();
                    this.buildSessionsStatus();
                    this.updateDeleteState();
                },
                buildNameRequest: function () {
                    let status = $('[data-name-request-status]');
                    let badge = $('[data-name-request-badge]');
                    let nameInput = $('#settings-name-request');
                    let reasonInput = $('#settings-name-reason');
                    let submitBtn = $('[data-name-request-submit]');
                    let request = this.dataset.name_request || null;
                    let cooldownLabel = $('[data-name-request-cooldown]');

                    if (!status.length) {
                        return;
                    }

                    if (cooldownLabel.length) {
                        let cooldownDays = parseInt(this.dataset.name_request_cooldown_days || 30, 10);
                        if (isNaN(cooldownDays) || cooldownDays <= 0) {
                            cooldownDays = 30;
                        }
                        cooldownLabel.text(cooldownDays);
                    }

                    if (!request) {
                        status.text('Inserisci il nuovo nome e invia la richiesta.');
                        if (badge.length) {
                            badge.addClass('d-none').removeClass('text-bg-warning text-bg-success text-bg-danger').text('');
                        }
                        nameInput.prop('disabled', false);
                        reasonInput.prop('disabled', false);
                        submitBtn.prop('disabled', false);
                        return;
                    }

                    let text = 'Ultima richiesta: ' + (request.new_name || '-');
                    let when = '';
                    let whenDate = request.status === 'pending' ? request.date_created : request.date_resolved;
                    if (whenDate) {
                        when = this.formatRelativeTime(whenDate);
                    }
                    if (request.status === 'pending') {
                        text = 'Richiesta in attesa: ' + (request.new_name || '-');
                        nameInput.prop('disabled', true);
                        reasonInput.prop('disabled', true);
                        submitBtn.prop('disabled', true);
                    } else {
                        nameInput.prop('disabled', false);
                        reasonInput.prop('disabled', false);
                        submitBtn.prop('disabled', false);
                    }
                    if (request.status === 'approved') {
                        text = 'Ultima richiesta approvata: ' + (request.new_name || '-');
                        if (badge.length) {
                            badge.removeClass('d-none text-bg-warning text-bg-danger').addClass('text-bg-success').text('Approvata' + (when ? ' · ' + when : ''));
                        }
                    } else if (request.status === 'rejected') {
                        text = 'Ultima richiesta rifiutata: ' + (request.new_name || '-');
                        if (badge.length) {
                            badge.removeClass('d-none text-bg-warning text-bg-success').addClass('text-bg-danger').text('Rifiutata' + (when ? ' · ' + when : ''));
                        }
                    } else if (request.status === 'pending') {
                        if (badge.length) {
                            badge.removeClass('d-none text-bg-success text-bg-danger').addClass('text-bg-warning').text('In attesa' + (when ? ' · ' + when : ''));
                        }
                    }
                    status.text(text);
                },
                formatRelativeTime: function (dateStr) {
                    if (!dateStr) {
                        return '';
                    }
                    if (typeof Dates !== 'function') {
                        return '';
                    }
                    let timestamp = Dates().getTimestamp(dateStr);
                    if (!timestamp) {
                        return '';
                    }
                    let diff = Math.floor((Date.now() - timestamp) / 1000);
                    if (diff < 60) {
                        return 'meno di 1 min fa';
                    }
                    if (diff < 3600) {
                        let mins = Math.floor(diff / 60);
                        return mins + ' min fa';
                    }
                    if (diff < 86400) {
                        let hours = Math.floor(diff / 3600);
                        return hours + ' ore fa';
                    }
                    let days = Math.floor(diff / 86400);
                    return days + ' giorni fa';
                },
                buildSessionsStatus: function () {
                    let block = $('[data-sessions-last-revoked]');
                    if (!block.length) {
                        return;
                    }
                    let value = this.dataset ? this.dataset.date_sessions_revoked : null;
                    if (!value) {
                        block.text('Nessuna revoca recente.');
                        return;
                    }
                    let formatted = value;
                    if (typeof Dates === 'function') {
                        formatted = Dates().formatHumanDateTime(value);
                    }
                    block.text('Ultima revoca: ' + formatted);
                },
                buildDeleteStatus: function () {
                    let status = $('[data-delete-status]');
                    let cancelBtn = $('[data-delete-cancel]');
                    let passwordInput = $('#settings-delete-password');
                    let confirmInput = this.deleteConfirmControl ? this.deleteConfirmControl.input : $('#settings-delete-confirm');

                    if (!status.length) {
                        return;
                    }

                    let scheduled = this.dataset.delete_scheduled_at || null;
                    if (scheduled) {
                        let formatted = scheduled;
                        if (typeof Dates === 'function') {
                            formatted = Dates().formatHumanDateTime(scheduled);
                        }
                        status.text('Eliminazione programmata per: ' + formatted);
                        cancelBtn.removeClass('d-none');
                        passwordInput.prop('disabled', true);
                        confirmInput.prop('disabled', true).change();
                    } else {
                        status.text('La cancellazione e reversibile per 10 giorni. Puoi annullarla prima della scadenza.');
                        cancelBtn.addClass('d-none');
                        passwordInput.prop('disabled', false);
                        confirmInput.prop('disabled', false).change();
                    }
                },
                isDeleteConfirmed: function () {
                    let values = this.deleteConfirmControl ? this.deleteConfirmControl.input.val() : $('#settings-delete-confirm').val();
                    if (!values || values.length === 0) {
                        return false;
                    }
                    return values.indexOf('1') !== -1;
                },
                updateDeleteState: function () {
                    let deleteBtn = $('[data-delete-request]');
                    let countdown = $('[data-delete-countdown]');

                    if (this.dataset && this.dataset.delete_scheduled_at) {
                        this.resetDeleteCountdown();
                        deleteBtn.prop('disabled', true);
                        countdown.text('');
                        return;
                    }

                    if (!this.isDeleteConfirmed()) {
                        this.resetDeleteCountdown();
                        deleteBtn.prop('disabled', true);
                        countdown.text('');
                        return;
                    }

                    this.startDeleteCountdown();
                },
                startDeleteCountdown: function () {
                    var self = this;
                    let deleteBtn = $('[data-delete-request]');
                    let countdown = $('[data-delete-countdown]');

                    if (this.deleteCountdownTimer) {
                        return;
                    }

                    let remaining = this.deleteCountdownSeconds;
                    deleteBtn.prop('disabled', true);
                    countdown.text('Attendi ' + remaining + 's...');
                    this.deleteCountdownTimer = setInterval(function () {
                        remaining -= 1;
                        if (remaining <= 0) {
                            clearInterval(self.deleteCountdownTimer);
                            self.deleteCountdownTimer = null;
                            deleteBtn.prop('disabled', false);
                            countdown.text('Pronto');
                            return;
                        }
                        countdown.text('Attendi ' + remaining + 's...');
                    }, 1000);
                },
                resetDeleteCountdown: function () {
                    if (this.deleteCountdownTimer) {
                        clearInterval(this.deleteCountdownTimer);
                        this.deleteCountdownTimer = null;
                    }
                },
                savePreferences: function () {
                    let dmVal = this.dmControl ? this.dmControl.input.val() : $('#settings-dm-policy').val();
                    let inviteVal = this.inviteControl ? this.inviteControl.input.val() : $('#settings-invite-policy').val();
                    let notifyVals = this.notifyControl ? this.notifyControl.input.val() : $('#settings-notifications').val();

                    if (notifyVals === null || notifyVals === undefined) {
                        notifyVals = [];
                    }

                    let payload = {
                        dm_policy: dmVal !== undefined && dmVal !== null && dmVal !== '' ? parseInt(dmVal, 10) : 0,
                        invite_policy: inviteVal !== undefined && inviteVal !== null && inviteVal !== '' ? parseInt(inviteVal, 10) : 0,
                        notify_messages: notifyVals.indexOf('messages') !== -1 ? 1 : 0,
                        notify_invites: notifyVals.indexOf('invites') !== -1 ? 1 : 0,
                        notify_news: notifyVals.indexOf('news') !== -1 ? 1 : 0
                    };
                    callSettingsModule('updateSettings', payload, function () {
                        Toast.show({
                            body: 'Preferenze salvate.',
                            type: 'success'
                        });
                    }, function (error) {
                        Toast.show({
                            body: normalizeSettingsError(error, 'Errore durante il salvataggio.'),
                            type: 'error'
                        });
                    });
                },
                changePassword: function () {
                    let oldPassword = $('#settings-old-password').val();
                    let newPassword = $('#settings-new-password').val();
                    let newPasswordConfirm = $('#settings-new-password-confirm').val();

                    if (!oldPassword || !newPassword || !newPasswordConfirm) {
                        Toast.show({
                            body: 'Compila tutti i campi della password.',
                            type: 'warning'
                        });
                        return;
                    }

                    if (newPassword !== newPasswordConfirm) {
                        Toast.show({
                            body: 'Le nuove password non coincidono.',
                            type: 'warning'
                        });
                        return;
                    }
                    let payload = {
                        old_password: oldPassword,
                        new_password: newPassword,
                        rewrite_new_password: newPasswordConfirm
                    };
                    callSettingsModule('changePassword', payload, function () {
                        $('#settings-old-password').val('');
                        $('#settings-new-password').val('');
                        $('#settings-new-password-confirm').val('');
                        Toast.show({
                            body: 'Password aggiornata.',
                            type: 'success'
                        });
                    }, function (error) {
                        Toast.show({
                            body: normalizeSettingsError(error, 'Errore durante il cambio password.'),
                            type: 'error'
                        });
                    });
                },
                revokeSessions: function () {
                    var self = this;
                    Dialog('warning', {
                        title: 'Sessioni attive',
                        body: '<p>Vuoi disconnettere tutte le sessioni attive?</p>'
                    }, function () {
                        callSettingsModule('revokeSessions', {}, function () {
                            if (!self.dataset) {
                                self.dataset = {};
                            }
                            let now = new Date();
                            let stamp = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0')
                                + ' ' + String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0') + ':' + String(now.getSeconds()).padStart(2, '0');
                            self.dataset.date_sessions_revoked = stamp;
                            self.buildSessionsStatus();
                            Toast.show({
                                body: 'Sessioni disconnesse.',
                                type: 'success'
                            });
                        }, function (error) {
                            Toast.show({
                                body: normalizeSettingsError(error, 'Errore durante la revoca.'),
                                type: 'error'
                            });
                        });
                    }).show();
                },
                requestNameChange: function () {
                    var self = this;
                    let newName = $('#settings-name-request').val();
                    let reason = $('#settings-name-reason').val();

                    if (!newName || String(newName).trim() === '') {
                        Toast.show({
                            body: 'Inserisci il nuovo nome.',
                            type: 'warning'
                        });
                        return;
                    }
                    let payload = {
                        new_name: newName,
                        reason: reason
                    };
                    callSettingsModule('requestNameChange', payload, function () {
                        Toast.show({
                            body: 'Richiesta inviata.',
                            type: 'success'
                        });
                        $('#settings-name-request').val('');
                        $('#settings-name-reason').val('');
                        self.get();
                    }, function (error) {
                        Toast.show({
                            body: normalizeSettingsError(error, 'Errore durante la richiesta.'),
                            type: 'error'
                        });
                    });
                },
                requestDelete: function () {
                    var self = this;
                    let password = $('#settings-delete-password').val();
                    if (!password) {
                        Toast.show({
                            body: 'Inserisci la password.',
                            type: 'warning'
                        });
                        return;
                    }
                    if (!this.isDeleteConfirmed()) {
                        Toast.show({
                            body: 'Conferma la richiesta di eliminazione.',
                            type: 'warning'
                        });
                        return;
                    }

                    Dialog('danger', {
                        title: 'Eliminazione personaggio',
                        body: '<p>Vuoi procedere con la cancellazione?</p>'
                    }, function () {
                        callSettingsModule('requestDelete', { password: password }, function (response) {
                            Toast.show({
                                body: 'Eliminazione programmata.',
                                type: 'success'
                            });
                            self.dataset.delete_scheduled_at = response.delete_scheduled_at || null;
                            self.buildDeleteStatus();
                            self.updateDeleteState();
                        }, function (error) {
                            Toast.show({
                                body: normalizeSettingsError(error, 'Errore durante la richiesta.'),
                                type: 'error'
                            });
                        });
                    }).show();
                },
                cancelDelete: function () {
                    var self = this;
                    callSettingsModule('cancelDelete', {}, function () {
                        Toast.show({
                            body: 'Eliminazione annullata.',
                            type: 'success'
                        });
                        self.dataset.delete_scheduled_at = null;
                        self.buildDeleteStatus();
                        self.updateDeleteState();
                    }, function (error) {
                        Toast.show({
                            body: normalizeSettingsError(error, 'Errore durante l\'annullamento.'),
                            type: 'error'
                        });
                    });
                }
            };

            let settings = Object.assign({}, page, extension);
            return settings.init();
        }

    window.GameSettingsPage = GameSettingsPage;
})(window);

