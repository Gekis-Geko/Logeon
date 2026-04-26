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

function normalizeInvitesError(error, fallback) {
    if (globalWindow.GameFeatureError && typeof globalWindow.GameFeatureError.normalize === 'function') {
        return globalWindow.GameFeatureError.normalize(error, fallback || 'Operazione non riuscita.');
    }
    if (typeof error === 'string' && error.trim() !== '') {
        return error.trim();
    }
    if (error && typeof error.message === 'string' && error.message.trim() !== '') {
        return error.message.trim();
    }
    return fallback || 'Operazione non riuscita.';
}


function GameLocationInvitesPage(extension) {
        let page = {
            pending: [],
            pending_ids: {},
            current: null,
            poll_timer: null,
            owner_timer: null,
            invitesModule: null,

            init: function () {
                this.fetchInvites();
                this.fetchOwnerUpdates();
                if (typeof PollManager === 'function') {
                    this.poll_timer = PollManager().start('location.invites.pending', this.fetchInvites.bind(this), 30000);
                    this.owner_timer = PollManager().start('location.invites.owner_updates', this.fetchOwnerUpdates.bind(this), 30000);
                } else {
                    this.poll_timer = setInterval(this.fetchInvites.bind(this), 30000);
                    this.owner_timer = setInterval(this.fetchOwnerUpdates.bind(this), 30000);
                }
                return this;
            },
            getInvitesModule: function () {
                if (this.invitesModule) {
                    return this.invitesModule;
                }
                if (typeof resolveModule !== 'function') {
                    return null;
                }

                this.invitesModule = resolveModule('game.location.invites');
                return this.invitesModule;
            },
            callInvites: function (method, payload, onSuccess, onError) {
                var mod = this.getInvitesModule();
                var fn = String(method || '').trim();
                if (!mod || fn === '' || typeof mod[fn] !== 'function') {
                    if (typeof onError === 'function') {
                        onError(new Error('Invites module not available: ' + fn));
                    }
                    return false;
                }

                mod[fn](payload || {}).then(function (response) {
                    if (typeof onSuccess === 'function') {
                        onSuccess(response);
                    }
                }).catch(function (error) {
                    if (typeof onError === 'function') {
                        onError(error);
                    }
                });

                return true;
            },
            fetchInvites: function () {
                var self = this;
                var onSuccess = function (response) {
                    if (!response || !response.dataset || response.dataset.length === 0) {
                        return;
                    }
                    self.enqueue(response.dataset);
                    self.showNext();
                };
                this.callInvites('pending', null, onSuccess, function () {});
            },
            fetchOwnerUpdates: function () {
                var onSuccess = function (response) {
                    if (!response || !response.dataset || response.dataset.length === 0) {
                        return;
                    }
                    for (var i in response.dataset) {
                        let row = response.dataset[i];
                        let name = (row.invited_name || '') + ' ' + (row.invited_surname || '');
                        let statusLabel = (row.status === 'accepted') ? 'ha accettato' : 'ha rifiutato';
                        let statusType = (row.status === 'accepted') ? 'success' : 'warning';
                        if (row.status === 'expired') {
                            statusLabel = 'non ha risposto in tempo';
                            statusType = 'warning';
                        }
                        Toast.show({
                            body: (name.trim() || 'Un personaggio') + ' ' + statusLabel + " l'invito per " + (row.location_name || 'la location') + '.',
                            type: statusType
                        });
                    }
                };
                this.callInvites('ownerUpdates', null, onSuccess, function () {});
            },
            enqueue: function (list) {
                for (var i in list) {
                    let invite = list[i];
                    if (!invite || !invite.id) {
                        continue;
                    }
                    if (this.current && this.current.id == invite.id) {
                        continue;
                    }
                    if (this.pending_ids[invite.id]) {
                        continue;
                    }
                    this.pending_ids[invite.id] = true;
                    this.pending.push(invite);
                }
            },
            showNext: function () {
                if (typeof globalWindow.locationInviteModal === 'undefined' || !globalWindow.locationInviteModal) {
                    return;
                }
                if (this.current || this.pending.length === 0) {
                    return;
                }
                this.current = this.pending.shift();
                if (this.current && this.current.id) {
                    delete this.pending_ids[this.current.id];
                }
                let ownerName = (this.current.owner_name || '') + ' ' + (this.current.owner_surname || '');
                globalWindow.locationInviteModal.show({
                    owner_name: ownerName.trim() || 'Un giocatore',
                    location_name: this.current.location_name || 'una location'
                });
            },
            accept: function () {
                var self = this;
                if (!this.current || !this.current.id) {
                    return;
                }
                var payload = {
                    invite_id: this.current.id,
                    action: 'accept'
                };
                var onSuccess = function (response) {
                    if (globalWindow.locationInviteModal && typeof globalWindow.locationInviteModal.hide === 'function') {
                        globalWindow.locationInviteModal.hide();
                    }
                    if (response && response.location) {
                        $(location).attr('href', '/game/maps/' + response.location.map_id + '/location/' + response.location.id);
                        return;
                    }
                    self.current = null;
                    self.showNext();
                };
                var onError = function (error) {
                    Toast.show({
                        body: normalizeInvitesError(error, 'Errore nella risposta all\'invito'),
                        type: 'error'
                    });
                    self.current = null;
                    self.showNext();
                };

                this.callInvites('respond', payload, onSuccess, onError);
            },
            decline: function () {
                var self = this;
                if (!this.current || !this.current.id) {
                    return;
                }
                var payload = {
                    invite_id: this.current.id,
                    action: 'decline'
                };
                var onSuccess = function () {
                    if (globalWindow.locationInviteModal && typeof globalWindow.locationInviteModal.hide === 'function') {
                        globalWindow.locationInviteModal.hide();
                    }
                    Toast.show({
                        body: 'Invito rifiutato.',
                        type: 'warning'
                    });
                    self.current = null;
                    self.showNext();
                };
                var onError = function (error) {
                    Toast.show({
                        body: normalizeInvitesError(error, 'Errore nella risposta all\'invito'),
                        type: 'error'
                    });
                    self.current = null;
                    self.showNext();
                };

                this.callInvites('respond', payload, onSuccess, onError);
            }
        };

        let invites = Object.assign({}, page, extension);
        return invites.init();
}
globalWindow.GameLocationInvitesPage = GameLocationInvitesPage;
export { GameLocationInvitesPage as GameLocationInvitesPage };
export default GameLocationInvitesPage;

