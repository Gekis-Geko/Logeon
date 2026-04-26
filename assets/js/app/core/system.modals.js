function requestUnavailableMessage() {
    if (window.Request && typeof window.Request.getUnavailableMessage === 'function') {
        return window.Request.getUnavailableMessage();
    }
    return 'Servizio comunicazione non disponibile. Ricarica la pagina e riprova.';
}

function requestUnavailableError() {
    return {
        message: requestUnavailableMessage(),
        errorCode: 'request_unavailable'
    };
}

function getErrorInfo(error, fallback) {
    var fb = fallback || 'Operazione non riuscita.';
    if (window.GameFeatureError && typeof window.GameFeatureError.info === 'function') {
        return window.GameFeatureError.info(error, fb);
    }
    if (window.Request && typeof window.Request.getErrorInfo === 'function') {
        return window.Request.getErrorInfo(error, fb);
    }
    if (window.Request && typeof window.Request.getErrorMessage === 'function') {
        return {
            message: window.Request.getErrorMessage(error, fb),
            errorCode: '',
            raw: error
        };
    }
    if (typeof error === 'string' && error.trim() !== '') {
        return { message: error.trim(), errorCode: '', raw: error };
    }
    if (error && typeof error.message === 'string' && error.message.trim() !== '') {
        return { message: error.message.trim(), errorCode: '', raw: error };
    }
    return { message: fb, errorCode: '', raw: error };
}

function requestPost(url, payload, action, onSuccess, onError) {
    if (typeof window.Request !== 'function') {
        if (typeof onError === 'function') {
            onError(requestUnavailableError());
        }
        return;
    }

    if (!window.Request.http || typeof window.Request.http.post !== 'function') {
        if (typeof onError === 'function') {
            onError(requestUnavailableError());
        }
        return;
    }

    window.Request.http.post(url, payload || {}).then(function (response) {
        if (typeof onSuccess === 'function') {
            onSuccess(response || null);
        }
    }).catch(function (error) {
        if (typeof onError === 'function') {
            onError(error);
        }
    });
}

function dispatchCreateCharacterEvent(eventName, detail) {
    if (!eventName || typeof document === 'undefined') {
        return;
    }
    var payload = (detail && typeof detail === 'object') ? detail : {};
    try {
        document.dispatchEvent(new CustomEvent(String(eventName), { detail: payload }));
    } catch (error) {
        try {
            var legacy = document.createEvent('CustomEvent');
            legacy.initCustomEvent(String(eventName), false, false, payload);
            document.dispatchEvent(legacy);
        } catch (_legacyError) {}
    }
}

function toCharactersArray(raw) {
    if (!Array.isArray(raw)) {
        return [];
    }
    var out = [];
    for (var i = 0; i < raw.length; i++) {
        var row = raw[i] || {};
        var id = parseInt(row.id, 10) || 0;
        if (id <= 0) {
            continue;
        }
        out.push({
            id: id,
            name: String(row.name || '').trim(),
            surname: String(row.surname || '').trim(),
            gender: parseInt(row.gender, 10) || 0
        });
    }
    return out;
}

function initHomeNavbar() {
    if (typeof window.Navbar !== 'function') {
        return null;
    }
    if (!document.getElementById('homeNavbar')) {
        return null;
    }
    return window.Navbar('#homeNavbar');
}

function initCreateCharacterModal() {
    if (typeof window.Modal !== 'function') {
        return null;
    }
    if (!document.getElementById('create-character-modal')) {
        return null;
    }
    if (window.createCharacterModal && typeof window.createCharacterModal.show === 'function') {
        return window.createCharacterModal;
    }

    window.createCharacterModal = window.Modal('create-character-modal', {
        settings: {
            backdrop: 'static',
            keyboard: false
        },
        onInit: function () {
            if (typeof window.RadioGroup === 'function') {
                window.RadioGroup('#' + this.form + ' [name="gender"]', {
                    options: [
                        { label: 'Maschio', value: '1' },
                        { label: 'Femmina', value: '0' }
                    ]
                });
            }

            var self = this;
            var formEl = document.getElementById(this.form);
            if (formEl) {
                formEl.addEventListener('submit', function (event) {
                    event.preventDefault();
                    if (typeof self.onSubmit === 'function') {
                        var formData = {};
                        var inputs = formEl.querySelectorAll('[name]');
                        for (var i = 0; i < inputs.length; i++) {
                            formData[inputs[i].name] = inputs[i].value;
                        }
                        self.onSubmit(formData);
                    }
                });
            }

            dispatchCreateCharacterEvent('character-create:init', {
                formId: this.form,
                form: formEl,
                modal: this
            });
        },
        beforeShow: function () {
            if (typeof window.Form === 'function') {
                window.Form().resetField(this.form);
            }

            dispatchCreateCharacterEvent('character-create:before-show', {
                formId: this.form,
                form: document.getElementById(this.form),
                modal: this
            });
        },
        onSubmit: function (formData) {
            var payload = {
                name: formData.name || '',
                surname: formData.surname || '',
                gender: formData.gender || ''
            };

            dispatchCreateCharacterEvent('character-create:collect-payload', {
                formId: this.form,
                form: document.getElementById(this.form),
                formData: formData,
                payload: payload,
                modal: this
            });

            requestPost('/character/create', payload, 'createCharacter', function (response) {
                var redirect = (response && response.redirect) ? response.redirect : '/game';
                window.$(location).attr('href', redirect);
            }, function (error) {
                var info = getErrorInfo(error, 'Impossibile creare il personaggio.');
                if (window.Toast && typeof window.Toast.show === 'function') {
                    window.Toast.show({ body: info.message, type: 'error' });
                }
            });

            return false;
        },
        back: function () {
            this.hide();
            if (window.signinModal && typeof window.signinModal.show === 'function') {
                window.signinModal.show();
            }
        }
    });

    return window.createCharacterModal;
}

function initSelectCharacterModal() {
    if (typeof window.Modal !== 'function') {
        return null;
    }
    if (!document.getElementById('select-character-modal')) {
        return null;
    }
    if (window.selectCharacterModal && typeof window.selectCharacterModal.show === 'function') {
        return window.selectCharacterModal;
    }

    window.selectCharacterModal = window.Modal('select-character-modal', {
        characters: [],
        onInit: function () {
            var self = this;
            var formEl = document.getElementById(this.form);
            if (formEl) {
                formEl.addEventListener('submit', function (event) {
                    event.preventDefault();
                    if (typeof self.onSubmit === 'function') {
                        var formData = {};
                        var inputs = formEl.querySelectorAll('[name]');
                        for (var i = 0; i < inputs.length; i++) {
                            formData[inputs[i].name] = inputs[i].value;
                        }
                        self.onSubmit(formData);
                    }
                });
            }

            var list = document.getElementById('select-character-modal-list');
            if (list) {
                list.addEventListener('click', function (event) {
                    var item = event.target && event.target.closest ? event.target.closest('[data-character-id]') : null;
                    if (!item) {
                        return;
                    }
                    event.preventDefault();
                    var id = parseInt(item.getAttribute('data-character-id') || '0', 10) || 0;
                    self.pickCharacter(id);
                });
            }
        },
        setCharacters: function (rawCharacters) {
            this.characters = toCharactersArray(rawCharacters);
            this.renderCharacters();
        },
        renderCharacters: function () {
            var list = document.getElementById('select-character-modal-list');
            var hidden = document.querySelector('#' + this.form + ' [name="character_id"]');
            if (!list) {
                return;
            }
            list.innerHTML = '';
            if (!this.characters.length) {
                var empty = document.createElement('div');
                empty.className = 'list-group-item text-muted small';
                empty.textContent = 'Nessun personaggio disponibile.';
                list.appendChild(empty);
                if (hidden) {
                    hidden.value = '';
                }
                return;
            }

            for (var i = 0; i < this.characters.length; i++) {
                var c = this.characters[i];
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'list-group-item list-group-item-action';
                btn.setAttribute('data-character-id', String(c.id));

                var fullName = c.name;
                if (c.surname) {
                    fullName += ' ' + c.surname;
                }
                if (fullName.trim() === '') {
                    fullName = 'Personaggio #' + c.id;
                }

                var genderLabel = (c.gender === 1) ? 'Uomo' : 'Donna';
                btn.innerHTML = '<div class="d-flex justify-content-between align-items-center">'
                    + '<span class="fw-semibold">' + fullName + '</span>'
                    + '<span class="badge text-bg-secondary">' + genderLabel + '</span>'
                    + '</div>';
                list.appendChild(btn);
            }

            var firstId = this.characters[0].id;
            if (hidden) {
                hidden.value = String(firstId);
            }
            this.pickCharacter(firstId);
        },
        pickCharacter: function (characterId) {
            var id = parseInt(characterId, 10) || 0;
            var hidden = document.querySelector('#' + this.form + ' [name="character_id"]');
            if (hidden) {
                hidden.value = id > 0 ? String(id) : '';
            }
            var list = document.getElementById('select-character-modal-list');
            if (!list) {
                return;
            }
            var nodes = list.querySelectorAll('[data-character-id]');
            for (var i = 0; i < nodes.length; i++) {
                var nodeId = parseInt(nodes[i].getAttribute('data-character-id') || '0', 10) || 0;
                nodes[i].classList.toggle('active', nodeId === id);
            }
        },
        onSubmit: function (formData) {
            var selectedId = parseInt(formData.character_id || '0', 10) || 0;
            if (selectedId <= 0) {
                if (window.Toast && typeof window.Toast.show === 'function') {
                    window.Toast.show({ body: 'Seleziona un personaggio valido.', type: 'warning' });
                }
                return false;
            }

            requestPost('/signin/character/select', { character_id: selectedId }, 'signinCharacterSelect', function (response) {
                if (response && response.error_auth) {
                    var message = String(response.error_auth.body || response.error_auth.title || 'Operazione non riuscita.');
                    if (window.Toast && typeof window.Toast.show === 'function') {
                        window.Toast.show({ body: message, type: 'error' });
                    }
                    return;
                }

                if (response && response.character && response.character.id) {
                    window.$(location).attr('href', '/game');
                    return;
                }

                if (window.Toast && typeof window.Toast.show === 'function') {
                    window.Toast.show({ body: 'Impossibile completare la selezione del personaggio.', type: 'error' });
                }
            }, function (error) {
                var info = getErrorInfo(error, 'Impossibile selezionare il personaggio.');
                if (window.Toast && typeof window.Toast.show === 'function') {
                    window.Toast.show({ body: info.message, type: 'error' });
                }
            });

            return false;
        }
    });

    return window.selectCharacterModal;
}

function initSigninModal() {
    if (typeof window.Modal !== 'function') {
        return null;
    }
    if (!document.getElementById('signin-modal')) {
        return null;
    }
    if (window.signinModal && typeof window.signinModal.show === 'function') {
        return window.signinModal;
    }

    window.signinModal = window.Modal('signin-modal', {
        beforeShow: function () {
            if (typeof window.Form === 'function') {
                window.Form().resetField(this.form);
            }

            var modal = this;
            var auth = window.Auth('signin', '/signin', '/game', this.form);
            auth.onSignin = function (errors) {
                if (errors != null) {
                    if (errors.type === 'character_select') {
                        modal.hide();
                        if (window.selectCharacterModal && typeof window.selectCharacterModal.setCharacters === 'function') {
                            var characters = (errors.dataset && errors.dataset.characters) ? errors.dataset.characters : [];
                            window.selectCharacterModal.setCharacters(characters);
                            window.selectCharacterModal.show();
                            return;
                        }
                        if (window.Toast && typeof window.Toast.show === 'function') {
                            window.Toast.show({ body: 'Impossibile aprire la selezione del personaggio.', type: 'error' });
                        }
                        return;
                    }
                    if (errors.type === 'confirm') {
                        errors.callback = function () {
                            this.hide();
                            modal.hide();
                            if (window.createCharacterModal && typeof window.createCharacterModal.show === 'function') {
                                window.createCharacterModal.show(this.dataset);
                            }
                        };
                    }
                    errors.show();
                    return;
                }

                if (typeof window.Form === 'function') {
                    window.Form().resetField(modal.form);
                }
                window.$(location).attr('href', this.redirectUrl);
            };
        }
    });

    return window.signinModal;
}

function initSignupModal() {
    if (typeof window.Modal !== 'function') {
        return null;
    }
    if (!document.getElementById('signup-modal')) {
        return null;
    }
    if (window.signupModal && typeof window.signupModal.show === 'function') {
        return window.signupModal;
    }

    window.signupModal = window.Modal('signup-modal', {
        onInit: function () {
            var self = this;
            var formEl = document.getElementById(this.form);
            if (!formEl) {
                return;
            }
            formEl.addEventListener('submit', function (event) {
                event.preventDefault();
                if (typeof self.onSubmit !== 'function') {
                    return;
                }
                var formData = {};
                var inputs = formEl.querySelectorAll('[name]');
                for (var i = 0; i < inputs.length; i++) {
                    formData[inputs[i].name] = inputs[i].value;
                }
                self.onSubmit(formData);
            });
        },
        beforeShow: function () {
            if (window.signinModal && typeof window.signinModal.hide === 'function') {
                window.signinModal.hide();
            }
            if (typeof window.Form === 'function') {
                window.Form().resetField(this.form);
            }
        },
        onSubmit: function (formData) {
            var email = String(formData.email || '').trim().toLowerCase();
            var password = String(formData.password || '');
            var passwordConfirm = String(formData.password_confirm || '');
            var passwordRules = [];

            if (password.length < 10) {
                passwordRules.push('almeno 10 caratteri');
            }
            if (!/[a-z]/.test(password)) {
                passwordRules.push('una lettera minuscola');
            }
            if (!/[A-Z]/.test(password)) {
                passwordRules.push('una lettera maiuscola');
            }
            if (!/[0-9]/.test(password)) {
                passwordRules.push('un numero');
            }
            if (!/[^A-Za-z0-9]/.test(password)) {
                passwordRules.push('un simbolo');
            }
            if (password.length > 72) {
                passwordRules.push('massimo 72 caratteri');
            }

            if (email === '' || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) {
                if (window.Toast && typeof window.Toast.show === 'function') {
                    window.Toast.show({ body: 'Inserisci un indirizzo email valido.', type: 'warning' });
                }
                return false;
            }

            if (passwordRules.length > 0) {
                if (window.Toast && typeof window.Toast.show === 'function') {
                    window.Toast.show({
                        body: 'Password non valida: ' + passwordRules.join(', ') + '.',
                        type: 'warning'
                    });
                }
                return false;
            }

            if (passwordConfirm === '' || password !== passwordConfirm) {
                if (window.Toast && typeof window.Toast.show === 'function') {
                    window.Toast.show({ body: 'Le password non corrispondono.', type: 'warning' });
                }
                return false;
            }

            var self = this;
            requestPost('/signup', {
                email: email,
                password: password,
                password_confirm: passwordConfirm
            }, 'signup', function (response) {
                var success = response && response.success ? response.success : null;
                if (window.Toast && typeof window.Toast.show === 'function') {
                    window.Toast.show({
                        body: success && success.body ? success.body : 'Registrazione completata.',
                        type: 'success'
                    });
                }

                self.hide();
                if (window.signinModal && typeof window.signinModal.show === 'function') {
                    window.signinModal.show();
                    setTimeout(function () {
                        var emailField = document.querySelector('#signin-modal-form [name="email"]');
                        if (emailField) {
                            emailField.value = email;
                            emailField.focus();
                        }
                    }, 120);
                }
            }, function (error) {
                var info = getErrorInfo(error, 'Registrazione non riuscita.');
                if (window.Toast && typeof window.Toast.show === 'function') {
                    window.Toast.show({ body: info.message, type: 'error' });
                }
            });
            return false;
        },
        back: function () {
            this.hide();
            if (window.signinModal && typeof window.signinModal.show === 'function') {
                window.signinModal.show();
            }
        }
    });

    return window.signupModal;
}

function initForgotPasswordModal() {
    if (typeof window.Modal !== 'function') {
        return null;
    }
    if (!document.getElementById('forgot-password-modal')) {
        return null;
    }
    if (window.forgotPasswordModal && typeof window.forgotPasswordModal.show === 'function') {
        return window.forgotPasswordModal;
    }

    window.forgotPasswordModal = window.Modal('forgot-password-modal', {
        beforeShow: function () {
            if (window.signinModal && typeof window.signinModal.hide === 'function') {
                window.signinModal.hide();
            }
            if (typeof window.Form === 'function') {
                window.Form().resetField(this.form);
            }
            window.Auth('forgot_password', '/forgot-password', null, this.form);
        },
        back: function () {
            this.hide();
            if (window.signinModal && typeof window.signinModal.show === 'function') {
                window.signinModal.show();
            }
        }
    });

    return window.forgotPasswordModal;
}

function initLicenseModal() {
    if (typeof window.Modal !== 'function') {
        return null;
    }
    if (!document.getElementById('license-modal')) {
        return null;
    }
    if (window.licenseModal && typeof window.licenseModal.show === 'function') {
        return window.licenseModal;
    }

    window.licenseModal = window.Modal('license-modal', {
        settings: {
            viewer: true
        }
    });

    return window.licenseModal;
}

function parseJsonSafe(rawValue) {
    var raw = String(rawValue || '').trim();
    if (!raw) {
        return null;
    }
    try {
        return JSON.parse(raw);
    } catch (error) {
        return null;
    }
}

function getGoogleAuthContext() {
    var node = document.getElementById('google-auth-context');
    if (!node) {
        return null;
    }
    return parseJsonSafe(node.textContent || '');
}

function applyGoogleAuthContext() {
    var context = getGoogleAuthContext();
    if (!context || typeof context !== 'object') {
        return;
    }

    var toastMessage = String(context.error_message || '').trim();
    var toastType = String(context.toast_type || '').trim();
    if (!toastType) {
        toastType = 'warning';
    }

    if (toastMessage !== '' && window.Toast && typeof window.Toast.show === 'function') {
        window.Toast.show({
            body: toastMessage,
            type: toastType
        });
    }

    if (context.open_create_character === true && window.createCharacterModal && typeof window.createCharacterModal.show === 'function') {
        window.createCharacterModal.show();
        var prefillName = String(context.prefill_name || '').trim();
        if (prefillName !== '') {
            setTimeout(function () {
                var nameField = document.querySelector('#create-character-modal-form [name="name"]');
                if (nameField) {
                    nameField.value = prefillName;
                }
            }, 80);
        }
        return;
    }

    if (context.open_select_character === true && window.selectCharacterModal && typeof window.selectCharacterModal.setCharacters === 'function') {
        var characters = Array.isArray(context.select_characters) ? context.select_characters : [];
        window.selectCharacterModal.setCharacters(characters);
        window.selectCharacterModal.show();
    }
}

function bindActions() {
    if (typeof window.$ === 'undefined') {
        return;
    }

    window.$(document).off('click.system-modals');

    window.$(document).on('click.system-modals', '[data-action=\"open-signin-modal\"]', function (event) {
        event.preventDefault();
        if (window.signinModal && typeof window.signinModal.show === 'function') {
            window.signinModal.show();
        }
    });

    window.$(document).on('click.system-modals', '[data-action=\"open-forgot-password-modal\"]', function (event) {
        event.preventDefault();
        if (window.forgotPasswordModal && typeof window.forgotPasswordModal.show === 'function') {
            window.forgotPasswordModal.show();
        }
    });

    window.$(document).on('click.system-modals', '[data-action=\"open-signup-modal\"]', function (event) {
        event.preventDefault();
        if (window.signupModal && typeof window.signupModal.show === 'function') {
            window.signupModal.show();
        }
    });

    window.$(document).on('click.system-modals', '[data-action=\"signup-modal-back\"]', function (event) {
        event.preventDefault();
        if (window.signupModal && typeof window.signupModal.back === 'function') {
            window.signupModal.back();
        }
    });

    window.$(document).on('click.system-modals', '[data-action=\"forgot-password-modal-back\"]', function (event) {
        event.preventDefault();
        if (window.forgotPasswordModal && typeof window.forgotPasswordModal.back === 'function') {
            window.forgotPasswordModal.back();
        }
    });

    window.$(document).on('click.system-modals', '[data-action=\"create-character-modal-back\"]', function (event) {
        event.preventDefault();
        if (window.createCharacterModal && typeof window.createCharacterModal.back === 'function') {
            window.createCharacterModal.back();
        }
    });

    window.$(document).on('click.system-modals', '[data-action=\"open-license-modal\"]', function (event) {
        event.preventDefault();
        if (window.licenseModal && typeof window.licenseModal.show === 'function') {
            window.licenseModal.show();
        }
    });

}

function start() {
    initHomeNavbar();
    initCreateCharacterModal();
    initSelectCharacterModal();
    initSigninModal();
    initSignupModal();
    initForgotPasswordModal();
    initLicenseModal();
    bindActions();
    applyGoogleAuthContext();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
        start();
    }, { once: true });
} else {
    start();
}

window.SystemModals = window.SystemModals || {};
window.SystemModals.start = start;
window.SystemModals.initHomeNavbar = initHomeNavbar;
window.SystemModals.initSigninModal = initSigninModal;
window.SystemModals.initSignupModal = initSignupModal;
window.SystemModals.initForgotPasswordModal = initForgotPasswordModal;
window.SystemModals.initCreateCharacterModal = initCreateCharacterModal;
window.SystemModals.initSelectCharacterModal = initSelectCharacterModal;
window.SystemModals.initLicenseModal = initLicenseModal;
