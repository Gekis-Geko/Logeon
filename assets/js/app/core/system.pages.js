(function (window) {
    'use strict';

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

    function normalizeError(error, fallback) {
        return getErrorInfo(error, fallback).message;
    }

    function isValidationErrorCode(errorCode) {
        var code = String(errorCode || '').trim().toLowerCase();
        if (!code) {
            return false;
        }

        if (code === 'validation_error' || code === 'request_unavailable') {
            return true;
        }

        var knownParts = [
            'invalid',
            'required',
            'forbidden',
            'unauthorized',
            'not_allowed',
            'not_found',
            'limit',
            'too_long',
            'empty',
            'insufficient',
            'unavailable',
            'rate_limited',
            'already'
        ];

        for (var i = 0; i < knownParts.length; i += 1) {
            if (code.indexOf(knownParts[i]) !== -1) {
                return true;
            }
        }

        return false;
    }

    function resolveErrorUiType(error, severeType, normalType) {
        var info = getErrorInfo(error, '');
        if (isValidationErrorCode(info.errorCode)) {
            return normalType;
        }
        return severeType;
    }

    function setAlertFromError(setAlert, error, fallback) {
        var info = getErrorInfo(error, fallback);
        var type = isValidationErrorCode(info.errorCode) ? 'warning' : 'danger';
        setAlert(type, info.message);
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

    function initContactPage() {
        if (window.__systemContactPageBound === true) {
            return;
        }
        if (typeof window.RadioGroup !== 'function') {
            return;
        }
        if (!document.getElementById('contact-sex')) {
            return;
        }

        window.RadioGroup('#contact-sex', {
            options: [
                { label: 'Uomo', value: '1' },
                { label: 'Donna', value: '0' }
            ]
        });

        window.__systemContactPageBound = true;
    }

    function initResetPasswordPage() {
        if (window.__systemResetPasswordPageBound === true) {
            return;
        }
        if (!document.getElementById('reset-password-form')) {
            return;
        }
        if (typeof window.Form !== 'function' || typeof window.Request !== 'function') {
            return;
        }

        var resetPasswordForm = window.Form().checkForm('reset-password-form');
        if (resetPasswordForm === false) {
            return;
        }

        resetPasswordForm.off('submit.system-reset-password').on('submit.system-reset-password', function (event) {
            event.preventDefault();
            requestPost('/reset-password', window.Form().getFields('reset-password-form'), 'resetPasswordConfirm', function (response) {
                    window.Dialog('default', {
                        title: response.success.title,
                        body: response.success.body
                    }).show();

                    setTimeout(function () {
                        window.$(location).attr('href', '/');
                    }, 1500);
            }, function (error) {
                window.Dialog(resolveErrorUiType(error, 'danger', 'warning'), {
                    title: 'Errore',
                    body: normalizeError(error, 'Reset password non riuscito.')
                }).show();
            });
        });

        window.__systemResetPasswordPageBound = true;
    }

    function initInstallPage() {
        if (window.__systemInstallPageBound === true) {
            return;
        }
        if (!document.getElementById('installer-page')) {
            return;
        }
        if (typeof window.Request !== 'function' || typeof window.$ === 'undefined') {
            return;
        }

        var currentStep = 1;
        var maxStep = 5;
        var dbTested = false;
        var configWritten = false;
        var dbInitialized = false;

        function setAlert(type, message) {
            var alert = window.$('#install-alert');
            alert.removeClass('d-none alert-danger alert-success alert-warning alert-info').addClass('alert-' + type);
            alert.text(message);
        }

        function clearAlert() {
            window.$('#install-alert').addClass('d-none').text('');
        }

        function updateStepper() {
            window.$('[data-install-step]').addClass('d-none');
            window.$('[data-install-step=\"' + currentStep + '\"]').removeClass('d-none');

            window.$('#install-step-label').text('Step ' + currentStep + '/' + maxStep);
            window.$('#install-progress').css('width', ((currentStep / maxStep) * 100) + '%');

            window.$('#btn-prev').prop('disabled', currentStep === 1);
            window.$('#btn-next').toggle(currentStep < maxStep);
        }

        function readAppData() {
            return {
                baseurl: window.$('#app-baseurl').val(),
                lang: window.$('#app-lang').val(),
                name: window.$('#app-name').val(),
                title: window.$('#app-title').val(),
                description: window.$('#app-description').val(),
                wm_name: window.$('#app-wm-name').val(),
                wm_email: window.$('#app-wm-email').val(),
                dba_name: window.$('#app-dba-name').val(),
                dba_email: window.$('#app-dba-email').val(),
                support_name: window.$('#app-support-name').val(),
                support_email: window.$('#app-support-email').val()
            };
        }

        function generateCryptKey() {
            var arr = new Uint8Array(16);
            window.crypto.getRandomValues(arr);
            return Array.from(arr).map(function (b) { return b.toString(16).padStart(2, '0'); }).join('');
        }

        function readDbData() {
            return {
                host: window.$('#db-host').val(),
                db_name: window.$('#db-name').val(),
                user: window.$('#db-user').val(),
                pwd: window.$('#db-pwd').val(),
                charset: window.$('#db-charset').val(),
                collation: window.$('#db-collation').val(),
                crypt_key: window.$('#db-crypt-key').val()
            };
        }

        window.$('#btn-regen-crypt-key').off('click.system-install').on('click.system-install', function () {
            window.$('#db-crypt-key').val(generateCryptKey());
        });

        function canGoNext() {
            if (currentStep === 2 && !dbTested) {
                setAlert('warning', 'Esegui prima il test connessione DB.');
                return false;
            }
            if (currentStep === 3 && !configWritten) {
                setAlert('warning', 'Scrivi prima la configurazione.');
                return false;
            }
            if (currentStep === 4 && !dbInitialized) {
                setAlert('warning', 'Inizializza prima il database.');
                return false;
            }
            return true;
        }

        function goNext() {
            clearAlert();
            if (currentStep === 1) {
                requestPost('/install/validate-app', { app: readAppData() }, 'installValidateApp', function () {
                        currentStep = 2;
                        updateStepper();
                }, function (error) {
                    setAlertFromError(setAlert, error, 'Dati applicazione non validi.');
                });
                return;
            }

            if (!canGoNext()) {
                return;
            }

            if (currentStep < maxStep) {
                currentStep += 1;
                updateStepper();
            }
        }

        function goPrev() {
            clearAlert();
            if (currentStep > 1) {
                currentStep -= 1;
                updateStepper();
            }
        }

        window.$('#btn-next').off('click.system-install').on('click.system-install', goNext);
        window.$('#btn-prev').off('click.system-install').on('click.system-install', goPrev);

        window.$('#btn-test-db').off('click.system-install').on('click.system-install', function () {
            clearAlert();
            requestPost('/install/test-db', { db: readDbData() }, 'installTestDb', function () {
                    dbTested = true;
                    setAlert('success', 'Connessione DB verificata correttamente.');
            }, function (error) {
                dbTested = false;
                setAlertFromError(setAlert, error, 'Test connessione DB fallito.');
            });
        });

        window.$('#btn-write-config').off('click.system-install').on('click.system-install', function () {
            clearAlert();
            requestPost('/install/write-config', {
                app: readAppData(),
                db: readDbData()
            }, 'installWriteConfig', function () {
                    configWritten = true;
                    setAlert('success', 'File di configurazione scritti con successo.');
            }, function (error) {
                configWritten = false;
                setAlertFromError(setAlert, error, 'Scrittura configurazione fallita.');
            });
        });

        window.$('#btn-init-db').off('click.system-install').on('click.system-install', function () {
            clearAlert();
            requestPost('/install/init-db', { db: readDbData() }, 'installInitDb', function () {
                    dbInitialized = true;
                    setAlert('success', 'Database inizializzato con successo.');
            }, function (error) {
                dbInitialized = false;
                setAlertFromError(setAlert, error, 'Inizializzazione database fallita.');
            });
        });

        function checkPasswordMatch() {
            var pwd     = window.$('#admin-password').val();
            var confirm = window.$('#admin-password-confirm').val();
            var feedback = window.$('#admin-password-match-feedback');

            if (confirm === '') {
                feedback.addClass('d-none').text('');
                window.$('#admin-password-confirm').removeClass('is-valid is-invalid');
                return true;
            }

            if (pwd === confirm) {
                feedback.removeClass('d-none').removeClass('text-danger').addClass('text-success').text('Le password coincidono.');
                window.$('#admin-password-confirm').removeClass('is-invalid').addClass('is-valid');
                return true;
            }

            feedback.removeClass('d-none').removeClass('text-success').addClass('text-danger').text('Le password non coincidono.');
            window.$('#admin-password-confirm').removeClass('is-valid').addClass('is-invalid');
            return false;
        }

        window.$('#admin-password, #admin-password-confirm').off('input.system-install').on('input.system-install', checkPasswordMatch);

        window.$('#btn-create-admin').off('click.system-install').on('click.system-install', function () {
            clearAlert();
            if (!checkPasswordMatch()) {
                setAlert('warning', 'Le password non coincidono. Correggile prima di procedere.');
                return;
            }
            var payload = {
                email:            window.$('#admin-email').val().trim(),
                password:         window.$('#admin-password').val(),
                password_confirm: window.$('#admin-password-confirm').val(),
                character_name:   window.$('#admin-character-name').val().trim(),
                gender:           parseInt(window.$('#admin-gender').val(), 10) || 1
            };
            requestPost('/install/create-admin', payload, 'installCreateAdmin', function () {
                setAlert('success', 'Account creato. Installazione completata. Reindirizzamento in corso...');
                setTimeout(function () {
                    location.href = '/';
                }, 1200);
            }, function (error) {
                setAlertFromError(setAlert, error, 'Creazione account fallita.');
            });
        });

        requestPost('/install/status', {}, 'installStatus', function (response) {
                var defaults = response.defaults || {};
                var app = defaults.app || {};
                var db = defaults.db || {};

                window.$('#app-baseurl').val(app.baseurl || '');
                window.$('#app-lang').val(app.lang || 'it');
                window.$('#app-name').val(app.name || '');
                window.$('#app-title').val(app.title || '');
                window.$('#app-description').val(app.description || '');
                function configVal(v) { return (v && v !== '-') ? v : ''; }
                window.$('#app-wm-name').val(configVal(app.wm_name));
                window.$('#app-wm-email').val(configVal(app.wm_email));
                window.$('#app-dba-name').val(configVal(app.dba_name));
                window.$('#app-dba-email').val(configVal(app.dba_email));
                window.$('#app-support-name').val(configVal(app.support_name));
                window.$('#app-support-email').val(configVal(app.support_email));

                window.$('#db-host').val(db.host || 'localhost');
                window.$('#db-name').val(db.db_name || '');
                window.$('#db-user').val(db.user || '');
                window.$('#db-charset').val(db.charset || 'utf8mb4');
                window.$('#db-collation').val(db.collation || 'utf8mb4_unicode_ci');
                window.$('#db-crypt-key').val(generateCryptKey());

                updateStepper();
        }, function () {
            updateStepper();
        });

        window.__systemInstallPageBound = true;
    }

    function initRulesPage() {
        if (window.__systemRulesPageBound === true) {
            return;
        }
        if (!document.getElementById('rules-page')) {
            return;
        }
        if (typeof window.DocsRender !== 'function') {
            return;
        }

        window.rulesPageDocsRenderer = window.DocsRender('#rules-page', {
            url: '/rules/list',
            prefix: 'rules-page',
            label: 'Regola',
            subLabel: 'Sottoregola',
            emptyText: 'Nessuna regola disponibile.'
        });

        window.__systemRulesPageBound = true;
    }

    function initStoryboardsPage() {
        if (window.__systemStoryboardsPageBound === true) {
            return;
        }
        if (!document.getElementById('storyboards-page')) {
            return;
        }
        if (typeof window.DocsRender !== 'function') {
            return;
        }

        window.storyboardsPageDocsRenderer = window.DocsRender('#storyboards-page', {
            url: '/storyboards/list',
            prefix: 'storyboard-page',
            label: 'Capitolo',
            subLabel: 'Sottocapitolo',
            emptyText: 'Nessun capitolo disponibile.'
        });

        window.__systemStoryboardsPageBound = true;
    }

    function initHowToPlayPage() {
        if (window.__systemHowToPlayPageBound === true) {
            return;
        }
        if (!document.getElementById('how-to-play-page')) {
            return;
        }
        if (typeof window.DocsRender !== 'function') {
            return;
        }

        window.howToPlayPageDocsRenderer = window.DocsRender('#how-to-play-page', {
            url: '/how-to-play/list',
            prefix: 'how-to-play-page',
            label: 'Passo',
            subLabel: 'Sottopasso',
            emptyText: 'Nessuna guida disponibile.'
        });

        window.__systemHowToPlayPageBound = true;
    }

    function start() {
        initContactPage();
        initResetPasswordPage();
        initInstallPage();
        initRulesPage();
        initStoryboardsPage();
        initHowToPlayPage();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            start();
        }, { once: true });
    } else {
        start();
    }

    window.SystemPages = window.SystemPages || {};
    window.SystemPages.start = start;
    window.SystemPages.initContactPage = initContactPage;
    window.SystemPages.initResetPasswordPage = initResetPasswordPage;
    window.SystemPages.initInstallPage = initInstallPage;
    window.SystemPages.initRulesPage = initRulesPage;
    window.SystemPages.initStoryboardsPage = initStoryboardsPage;
    window.SystemPages.initHowToPlayPage = initHowToPlayPage;
})(window);
