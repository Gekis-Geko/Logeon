/**
 * Gestisce i flussi di autenticazione lato client (signin, signout, forgot_password, signup).
 * Dipende da: `Form()`, `Dialog()`, `Request.http`.
 *
 * Uso tipico:
 * ```js
 * Auth('signin', '/api/auth/signin', '/dashboard', '#form-login');
 * Auth('signout', '/api/auth/signout', '/login');
 * Auth('forgot_password', '/api/auth/forgot', null, '#form-forgot');
 * ```
 *
 * @param {'signin'|'signout'|'forgot_password'|'signup'} action
 *        Tipo di flusso auth da gestire.
 * @param {string} url          - Endpoint API da chiamare.
 * @param {string|null} redirectUrl - URL di reindirizzamento post-successo (non richiesto per forgot_password e signup).
 * @param {string|jQuery|HTMLElement} [form] - Selettore, elemento jQuery o DOM del form (non richiesto per signout).
 * @param {Object} [extension]  - Override di metodi sull'istanza.
 * @returns {Object} Istanza Auth già inizializzata.
 */
function Auth(action, url, redirectUrl, form, extension) {
    let base = {
        form: null,
        url: null,
        redirectUrl: null,

        init: function () {
            if (null == url) {
                Dialog('danger', {
                    title: 'Url non dichiarato',
                    body: 'Non e stata dichiarata la Url di riferimento.'
                }).show();

                return;
            }

            if (null == redirectUrl && (action != 'forgot_password' && action != 'signup')) {
                Dialog('danger', {
                    title: 'Url di Reindirizzamento non dichiarato',
                    body: 'Non e stata dichiarata la Url di Reindirizzamento.'
                }).show();
                
                return;
            }
            
            var self = this;
            this.url = url;
            this.redirectUrl = redirectUrl;
            
            switch(action) {
                case 'signin':
                    this.form = Form().checkForm(form);

                    if (false != this.form) {
                        this.form.off('submit.auth').on('submit.auth', function (e) {
                            e.preventDefault();
                            self.signin(Form().getFields(form));
                
                            return;
                        });
                    }
                    break;
                case 'signout':
                    this.signout();
                    break;
                case 'forgot_password':
                    this.form = Form().checkForm(form);

                    if (false != this.form) {
                        this.form.off('submit.auth').on('submit.auth', function (e) {
                            e.preventDefault();
                            self.forgotPassword(Form().getFields(form));
                
                            return;
                        });
                    }
                    break;
            }

            return this;
        },
        signin: function (data) {
            var self = this;
            this.requestPost('signin', data, function (response) {
                let error = null;
                let dataset = null;

                if (null != response.error_auth) {
                    dataset = response.error_auth;
                    error = Dialog('danger', {
                        title: dataset.title,
                        body: dataset.body
                    });
                }

                if (null != response.error_character) {
                    dataset = response.error_character;
                    error = Dialog('confirm', {
                        title: dataset.title,
                        body: dataset.body,
                        dataset: dataset.user
                    });
                
                    self._setStorageSessions(response);
                }

                if (null != response.error_character_select) {
                    dataset = response.error_character_select;
                    error = {
                        type: 'character_select',
                        dataset: dataset
                    };

                    self._setStorageSessions(response);
                }

                if (null == error)
                    self._setStorageSessions(response);

                self.onSignin(error, response);

                return;
            });
        },
        signout: function () {
            var self = this;

            Dialog('danger', {
                title: 'Uscita in corso...',
                body: 'Vuoi uscire veramente? Verrai riportato alla homepage'
            }, function () {
                var modal = this;
                self.requestPost('signout', {}, function () {
                    Storage().empty();

                    modal.hide();
                    self.onSignout(); 

                    $(location).attr('href', self.redirectUrl);
                });
            }).show();
        },
        forgotPassword: function (data) {            
            this.requestPost('forgotPassword', data, function (response) {
                if (null != response.error_auth) {
                    let error = response.error_auth;
                    Dialog('danger', {
                        title: error.title,
                        body: error.body
                    }).show();
    
                    return false;
                } else {
                    let success = response.success;
                    Dialog('default', {
                        title: success.title,
                        body: success.body
                    }).show();
                }
            })
        },

        requestPost: function (actionName, payload, onSuccess, onError) {
            var action = String(actionName || '').trim();
            if (!action) {
                return;
            }

            if (typeof Request !== 'function') {
                if (typeof onError === 'function') {
                    onError(this.requestUnavailableMessage());
                }
                return;
            }

            if (!Request.http || typeof Request.http.post !== 'function') {
                if (typeof onError === 'function') {
                    onError(this.requestUnavailableMessage());
                }
                return;
            }

            Request.http.post(this.url, payload || {}).then(function (response) {
                if (typeof onSuccess === 'function') {
                    onSuccess(response || null);
                }
            }).catch(function (error) {
                if (typeof onError === 'function') {
                    onError(error);
                }
            });
        },

        requestUnavailableMessage: function () {
            if (typeof window !== 'undefined' && window.Request && typeof window.Request.getUnavailableMessage === 'function') {
                return window.Request.getUnavailableMessage();
            }
            return 'Servizio comunicazione non disponibile. Ricarica la pagina e riprova.';
        },

        onSignin: function (errors, response) {},
        onSignout: function () {},

        _setStorageSessions: function (response) {
            let user = null;

            if (response && response.user) {
                user = response.user;
            } else if (response && response.error_character && response.error_character.user) {
                user = response.error_character.user;
            }

            if (user) {
                Storage().set('userId', user.id);
                Storage().set('userGender', user.gender);
                Storage().set('userLastPass', user.date_last_pass);
                if (typeof PermissionGate === 'function') {
                    PermissionGate().setFromUser(user);
                } else {
                    Storage().set('userIsAdministrator', (parseInt(user.is_administrator, 10) === 1) ? 1 : 0);
                    Storage().set('userIsModerator', (parseInt(user.is_moderator, 10) === 1) ? 1 : 0);
                    Storage().set('userIsMaster', (parseInt(user.is_master, 10) === 1) ? 1 : 0);
                }
            }

            if (response && response.character) {
                Storage().set('characterId', response.character.id);
                Storage().set('characterGender', response.character.gender);
                Storage().set('characterAvailability', response.character.availability);
            }
        }
    };

    let o = Object.assign({}, base, extension);
    return o.init(); 
}

if (typeof window !== 'undefined') {
    window.Auth = Auth;
}
