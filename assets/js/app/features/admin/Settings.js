const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

var AdminSettings = {
    initialized: false,
    root: null,
    form: null,
    alertEl: null,
    dataset: null,
    switches: {},
    uploaders: {},

    soundTypes: ['dm', 'notifications', 'whispers', 'global'],

    audioMimeTypes: {
        'audio/mpeg': 'MP3', 'audio/mp3': 'MP3', 'audio/ogg': 'OGG',
        'audio/wav': 'WAV', 'audio/x-wav': 'WAV', 'audio/aac': 'AAC',
        'audio/mp4': 'M4A', 'audio/x-m4a': 'M4A', 'audio/webm': 'WEBM'
    },

    init: function () {
        if (this.initialized) {
            return this;
        }

        this.root = document.querySelector('#admin-page [data-admin-page="settings"]');
        if (!this.root) {
            return this;
        }

        this.form    = document.getElementById('admin-settings-form');
        this.alertEl = document.getElementById('admin-settings-alert');

        if (!this.form) {
            return this;
        }

        this.initSwitches();
        this.refreshGoogleOauthConfigVisibility();
        this.refreshMultiCharacterConfigVisibility();
        this.refreshNarrativeDelegationLevelVisibility();
        this.bind();
        this.load();
        this.buildSounds();
        this.initSoundUploads();

        this.initialized = true;
        return this;
    },

    initSwitches: function () {
        if (typeof globalWindow.SwitchGroup !== 'function' || typeof globalWindow.$ !== 'function') {
            return this;
        }

        var sw = globalWindow.SwitchGroup;
        var jq = globalWindow.$;

        this.switches.onlines_auto_toast = sw(jq('#s-onlines-toast'), {
            preset: 'enableddisabled',
            trueValue: '1',
            falseValue: '0',
            defaultValue: '0'
        });

        this.switches.presence_resume_last_position_on_signin = sw(jq('#s-presence-resume'), {
            preset: 'enableddisabled',
            trueValue: '1',
            falseValue: '0',
            defaultValue: '0'
        });

        this.switches.multi_character_enabled = sw(jq('#s-multi-character-enabled'), {
            preset: 'enableddisabled',
            trueValue: '1',
            falseValue: '0',
            defaultValue: '0'
        });

        this.switches.auth_google_enabled = sw(jq('#s-auth-google-enabled'), {
            preset: 'enableddisabled',
            trueValue: '1',
            falseValue: '0',
            defaultValue: '0'
        });

        var googleSwitchInput = this.form ? this.form.elements['auth_google_enabled'] : null;
        if (googleSwitchInput) {
            var self = this;
            // SwitchGroup emette il cambio via jQuery (.change()), quindi agganciamo qui.
            globalWindow.$(googleSwitchInput)
                .off('change.adminSettingsGoogleOauth')
                .on('change.adminSettingsGoogleOauth', function () {
                    self.refreshGoogleOauthConfigVisibility();
                });

            // Fallback difensivo: alcuni temi/plugin intercettano il click sullo switch
            // senza propagare il change sul campo hidden.
            globalWindow.$(googleSwitchInput)
                .siblings('[data-role="switch-group"]')
                .off('click.adminSettingsGoogleOauth')
                .on('click.adminSettingsGoogleOauth', function () {
                    globalWindow.setTimeout(function () {
                        self.refreshGoogleOauthConfigVisibility();
                    }, 0);
                });
        }

        var multiCharacterInput = this.form ? this.form.elements['multi_character_enabled'] : null;
        if (multiCharacterInput) {
            var self2 = this;
            globalWindow.$(multiCharacterInput)
                .off('change.adminSettingsMultiCharacter')
                .on('change.adminSettingsMultiCharacter', function () {
                    self2.refreshMultiCharacterConfigVisibility();
                });
        }

        this.switches.narrative_delegation_enabled = sw(jq('#s-nd-enabled'), {
            preset: 'enableddisabled',
            trueValue: '1',
            falseValue: '0',
            defaultValue: '0'
        });

        var ndEnabledInput = this.form ? this.form.elements['narrative_delegation_enabled'] : null;
        if (ndEnabledInput) {
            var self3 = this;
            globalWindow.$(ndEnabledInput)
                .off('change.adminSettingsNarrativeDelegation')
                .on('change.adminSettingsNarrativeDelegation', function () {
                    self3.refreshNarrativeDelegationLevelVisibility();
                });
        }

        return this;
    },

    refreshGoogleOauthConfigVisibility: function () {
        if (!this.form) {
            return this;
        }
        var wrapper = document.getElementById('admin-settings-google-oauth-config');
        if (!wrapper) {
            return this;
        }
        var enabled = false;
        if (this.switches.auth_google_enabled && typeof this.switches.auth_google_enabled.getValue === 'function') {
            enabled = (String(this.switches.auth_google_enabled.getValue() || '0') === '1');
        } else {
            var input = this.form.elements['auth_google_enabled'];
            enabled = input ? (String(input.value || '0') === '1') : false;
        }
        wrapper.classList.toggle('d-none', !enabled);
        return this;
    },

    refreshNarrativeDelegationLevelVisibility: function () {
        if (!this.form) { return this; }
        var wrapper = document.getElementById('admin-settings-nd-level-wrap');
        if (!wrapper) { return this; }
        var input = this.form.elements['narrative_delegation_enabled'];
        var enabled = input ? (String(input.value || '0') === '1') : false;
        wrapper.classList.toggle('d-none', !enabled);
        return this;
    },

    refreshMultiCharacterConfigVisibility: function () {
        if (!this.form) {
            return this;
        }
        var wrapper = document.getElementById('admin-settings-multi-character-max-wrap');
        if (!wrapper) {
            return this;
        }
        var input = this.form.elements['multi_character_enabled'];
        var enabled = input ? (String(input.value || '0') === '1') : false;
        wrapper.classList.toggle('d-none', !enabled);
        return this;
    },

    bind: function () {
        var self = this;

        this.form.addEventListener('submit', function (e) {
            e.preventDefault();
            self.save();
        });

        this.root.addEventListener('click', function (e) {
            var trigger = e.target && e.target.closest ? e.target.closest('[data-action]') : null;
            if (!trigger) { return; }
            var action = String(trigger.getAttribute('data-action') || '').trim();
            if (action === 'admin-settings-reload') {
                e.preventDefault();
                self.load();
            }
        });

        var saveBtn = document.getElementById('admin-sounds-save');
        if (saveBtn) {
            saveBtn.addEventListener('click', function () {
                self.saveSounds();
            });
        }

        var soundsCard = document.getElementById('admin-settings-sounds-card');
        if (soundsCard) {
            soundsCard.addEventListener('click', function (e) {
                var testBtn = e.target && e.target.closest ? e.target.closest('[data-sound-test]') : null;
                if (testBtn) {
                    var type = String(testBtn.getAttribute('data-sound-test') || '');
                    var input = soundsCard.querySelector('[data-sound-type="' + type + '"]');
                    var url = input ? String(input.value || '').trim() : '';
                    if (!url) {
                        self.showAlert('warning', 'Inserisci un URL prima di testare.');
                        return;
                    }
                    if (globalWindow.AppSounds && typeof globalWindow.AppSounds.previewUrl === 'function') {
                        globalWindow.AppSounds.previewUrl(url);
                    }
                    return;
                }

                var clearBtn = e.target && e.target.closest ? e.target.closest('[data-sound-clear]') : null;
                if (clearBtn) {
                    var clearType = String(clearBtn.getAttribute('data-sound-clear') || '');
                    var clearInput = soundsCard.querySelector('[data-sound-type="' + clearType + '"]');
                    if (clearInput) { clearInput.value = ''; }
                    return;
                }

                // Tab toggle within a sound row
                var tabBtn = e.target && e.target.closest ? e.target.closest('[data-sound-tab-btn]') : null;
                if (tabBtn) {
                    var row = tabBtn.closest('[data-sound-row]');
                    if (!row) { return; }
                    var tab = String(tabBtn.getAttribute('data-sound-tab-btn') || '');
                    row.querySelectorAll('[data-sound-tab-btn]').forEach(function (b) {
                        b.classList.toggle('active', b.getAttribute('data-sound-tab-btn') === tab);
                    });
                    row.querySelectorAll('[data-sound-tab-panel]').forEach(function (p) {
                        p.style.display = p.getAttribute('data-sound-tab-panel') === tab ? '' : 'none';
                    });
                    return;
                }

                // Upload target button
                var uploadTargetBtn = e.target && e.target.closest ? e.target.closest('[data-upload-target]') : null;
                if (uploadTargetBtn) {
                    var uploadTarget = String(uploadTargetBtn.getAttribute('data-upload-target') || '');
                    if (uploadTarget) { self.openSoundUploader(uploadTarget); }
                    return;
                }

                // Upload cancel button
                var uploadCancelBtn = e.target && e.target.closest ? e.target.closest('[data-upload-cancel]') : null;
                if (uploadCancelBtn) {
                    var cancelTarget = String(uploadCancelBtn.getAttribute('data-upload-cancel') || '');
                    if (cancelTarget) { self.cancelSoundUpload(cancelTarget); }
                }
            });
        }
    },

    load: function () {
        var self = this;
        this.hideAlert();

        this.post('/admin/settings/get', {}, function (res) {
            if (res && res.dataset) {
                self.dataset = res.dataset;
                self.populateForm(res.dataset);
            }
        });
    },

    save: function () {
        var self = this;
        this.hideAlert();

        var saveBtn = document.getElementById('admin-settings-save');
        if (saveBtn) { saveBtn.disabled = true; }

        var payload = this.collectPayload();

        this.post('/admin/settings/update', payload, function (res) {
            if (res && res.dataset) {
                self.dataset = res.dataset;
                self.populateForm(res.dataset);
            }
            self.showAlert('success', 'Impostazioni salvate correttamente.');
            self.notifyToast('success', 'Impostazioni salvate correttamente.');
            if (saveBtn) { saveBtn.disabled = false; }
        }, function (err) {
            var message = 'Errore durante il salvataggio: ' + self.err(err);
            self.showAlert('danger', message);
            self.notifyToast('error', message);
            if (saveBtn) { saveBtn.disabled = false; }
        });
    },

    populateForm: function (d) {
        var fields = [
            'upload_max_mb',
            'upload_max_concurrency',
            'upload_max_avatar_mb',
            'inventory_capacity_max',
            'inventory_stack_max',
            'location_chat_history_hours',
            'location_whisper_retention_hours',
            'availability_idle_minutes',
            'location_invite_expiry_hours',
            'location_invite_max_active',
            'rate_auth_signin_limit',
            'rate_auth_signin_window_seconds',
            'rate_auth_reset_limit',
            'rate_auth_reset_window_seconds',
            'rate_auth_reset_confirm_limit',
            'rate_auth_reset_confirm_window_seconds',
            'rate_dm_send_limit',
            'rate_dm_send_window_seconds',
            'rate_location_chat_limit',
            'rate_location_chat_window_seconds',
            'rate_location_whisper_limit',
            'rate_location_whisper_window_seconds',
            'multi_character_max_per_user',
            'auth_google_client_id',
            'auth_google_client_secret',
            'auth_google_redirect_uri',
            'narrative_delegation_level',
            'storyboard_view_mode',
            'rules_view_mode',
            'how_to_play_view_mode',
            'archetypes_view_mode'
        ];

        for (var i = 0; i < fields.length; i++) {
            var key = fields[i];
            if (d[key] === undefined) { continue; }
            var el = this.form.elements[key];
            if (el) { el.value = d[key]; }
        }

        // Campi gestiti da SwitchGroup
        var switchKeys = ['onlines_auto_toast', 'presence_resume_last_position_on_signin', 'auth_google_enabled', 'multi_character_enabled', 'narrative_delegation_enabled'];
        for (var j = 0; j < switchKeys.length; j++) {
            var sKey = switchKeys[j];
            if (d[sKey] === undefined) { continue; }
            if (this.switches[sKey] && typeof this.switches[sKey].setValue === 'function') {
                this.switches[sKey].setValue(String(d[sKey]));
            } else {
                var sEl = this.form.elements[sKey];
                if (sEl) { sEl.value = d[sKey]; }
            }
        }

        this.refreshGoogleOauthConfigVisibility();
        this.refreshMultiCharacterConfigVisibility();
        this.refreshNarrativeDelegationLevelVisibility();
    },

    collectPayload: function () {
        var payload = {};
        var els = this.form.elements;

        var numericFields = [
            'upload_max_mb',
            'upload_max_concurrency',
            'upload_max_avatar_mb',
            'inventory_capacity_max',
            'inventory_stack_max',
            'location_chat_history_hours',
            'location_whisper_retention_hours',
            'availability_idle_minutes',
            'onlines_auto_toast',
            'presence_resume_last_position_on_signin',
            'location_invite_expiry_hours',
            'location_invite_max_active',
            'rate_auth_signin_limit',
            'rate_auth_signin_window_seconds',
            'rate_auth_reset_limit',
            'rate_auth_reset_window_seconds',
            'rate_auth_reset_confirm_limit',
            'rate_auth_reset_confirm_window_seconds',
            'rate_dm_send_limit',
            'rate_dm_send_window_seconds',
            'rate_location_chat_limit',
            'rate_location_chat_window_seconds',
            'rate_location_whisper_limit',
            'rate_location_whisper_window_seconds',
            'multi_character_max_per_user',
            'multi_character_enabled',
            'auth_google_enabled',
            'narrative_delegation_enabled',
            'narrative_delegation_level'
        ];

        for (var i = 0; i < numericFields.length; i++) {
            var key = numericFields[i];
            if (els[key]) {
                payload[key] = parseInt(els[key].value, 10) || 0;
            }
        }

        var stringFields = [
            'auth_google_client_id',
            'auth_google_client_secret',
            'auth_google_redirect_uri',
            'storyboard_view_mode',
            'rules_view_mode',
            'how_to_play_view_mode',
            'archetypes_view_mode'
        ];
        for (var j = 0; j < stringFields.length; j++) {
            var skey = stringFields[j];
            if (els[skey]) {
                payload[skey] = String(els[skey].value || '').trim();
            }
        }

        return payload;
    },

    buildSounds: function () {
        if (!globalWindow.AppSounds || typeof globalWindow.AppSounds.get !== 'function') {
            return;
        }
        var soundsCard = document.getElementById('admin-settings-sounds-card');
        if (!soundsCard) { return; }
        var types = this.soundTypes;
        for (var i = 0; i < types.length; i++) {
            var url = globalWindow.AppSounds.get(types[i]);
            var input = soundsCard.querySelector('[data-sound-type="' + types[i] + '"]');
            if (input && url !== '') {
                input.value = url;
            }
        }
    },

    saveSounds: function () {
        if (!globalWindow.AppSounds || typeof globalWindow.AppSounds.set !== 'function') {
            this.showAlert('danger', 'Funzionalita suoni non disponibile.');
            return;
        }
        var soundsCard = document.getElementById('admin-settings-sounds-card');
        if (!soundsCard) { return; }
        var types = this.soundTypes;
        for (var i = 0; i < types.length; i++) {
            var input = soundsCard.querySelector('[data-sound-type="' + types[i] + '"]');
            var url = input ? String(input.value || '').trim() : '';
            globalWindow.AppSounds.set(types[i], url);
        }
        this.showAlert('success', 'Suoni salvati correttamente.');
    },

    initSoundUploads: function () {
        if (typeof globalWindow.Uploader !== 'function') { return; }
        var self = this;
        var soundsCard = document.getElementById('admin-settings-sounds-card');
        if (!soundsCard) { return; }

        soundsCard.querySelectorAll('[data-upload-drop]').forEach(function (drop) {
            if (drop.getAttribute('data-uploader-bound') === '1') { return; }
            var target = String(drop.getAttribute('data-upload-drop') || '');
            if (!target) { return; }
            drop.setAttribute('data-uploader-bound', '1');
            self.buildSoundUploader(target, { dropArea: drop });
        });
    },

    buildSoundUploader: function (uploadTarget, options) {
        var self = this;
        if (this.uploaders[uploadTarget]) {
            if (options && options.dropArea) {
                this.uploaders[uploadTarget].setDropArea(options.dropArea);
            }
            return this.uploaders[uploadTarget];
        }

        var config = {
            url: '/uploader',
            multiple: false,
            autostart: true,
            target: uploadTarget,
            allowed_mime: this.audioMimeTypes,
            newFile: function (file) {
                file.onProgress = function () {
                    self.updateSoundUploadProgress(uploadTarget, this);
                };
                file.onComplete = function () {
                    self.finalizeSoundUpload(uploadTarget, this);
                };
                file.onCancel = function () {
                    self.resetSoundUploadProgress(uploadTarget);
                    self.setSoundUploadActionMode(uploadTarget, '');
                };
                file.onError = function () {
                    self.setSoundUploadActionMode(uploadTarget, 'retry');
                    var wrap = document.querySelector('[data-upload-progress="' + uploadTarget + '"]');
                    if (wrap) {
                        var bar = wrap.querySelector('.progress-bar');
                        if (bar) { bar.className = 'progress-bar bg-danger'; bar.textContent = 'Errore'; }
                        wrap.classList.remove('d-none');
                    }
                };
                return file;
            },
            onAddFile: function (file) {
                self.setSoundUploadActionMode(uploadTarget, 'cancel');
                self.updateSoundUploadProgress(uploadTarget, file);
            }
        };

        if (options) {
            var merged = {};
            for (var k in config) { if (config.hasOwnProperty(k)) { merged[k] = config[k]; } }
            for (var k2 in options) { if (options.hasOwnProperty(k2)) { merged[k2] = options[k2]; } }
            config = merged;
        }

        var uploader = globalWindow.Uploader(config);
        this.uploaders[uploadTarget] = uploader;
        if (options && options.dropArea) {
            uploader.setDropArea(options.dropArea);
        }
        return uploader;
    },

    openSoundUploader: function (uploadTarget) {
        this.buildSoundUploader(uploadTarget).open();
    },

    cancelSoundUpload: function (uploadTarget) {
        var self = this;
        var uploader = this.uploaders[uploadTarget];
        if (!uploader) { return; }
        var btn = document.querySelector('[data-upload-cancel="' + uploadTarget + '"]');
        var mode = btn ? (btn.getAttribute('data-upload-mode') || '') : '';
        var file = uploader.currentFile;
        if (mode === 'retry' || (file && (file.state === 'error' || file.cancelled))) {
            if (file && typeof uploader.retryFile === 'function') {
                self.setSoundUploadActionMode(uploadTarget, 'cancel');
                uploader.retryFile(file);
            }
            return;
        }
        if (file && typeof file.cancel === 'function') {
            file.cancel();
        }
    },

    finalizeSoundUpload: function (uploadTarget, file) {
        var self = this;
        if (!file || !file.token) { return; }

        var soundType = uploadTarget.replace(/^sound_/, '');

        this.post('/uploader?action=uploadFinalize&token=' + encodeURIComponent(file.token), { target: uploadTarget }, function (res) {
            if (!res || !res.dataset || !res.dataset.url) {
                self.setSoundUploadActionMode(uploadTarget, 'retry');
                self.showAlert('danger', 'Upload completato ma URL non disponibile.');
                return;
            }
            var url = res.dataset.url;
            var input = document.querySelector('[data-sound-type="' + soundType + '"]');
            if (input) { input.value = url; }
            self.resetSoundUploadProgress(uploadTarget);
            self.showAlert('success', 'Audio caricato correttamente.');
        }, function () {
            self.setSoundUploadActionMode(uploadTarget, 'retry');
            self.showAlert('danger', 'Errore durante il caricamento del file audio.');
        });
    },

    updateSoundUploadProgress: function (uploadTarget, file) {
        var wrap = document.querySelector('[data-upload-progress="' + uploadTarget + '"]');
        if (!wrap) { return; }
        var bar = wrap.querySelector('.progress-bar');
        var perc = file && typeof file.getPercLoaded === 'function' ? file.getPercLoaded() : 0;
        if (isNaN(perc) || perc < 0) { perc = 0; }
        wrap.classList.remove('d-none');
        if (bar) {
            bar.className = 'progress-bar bg-success';
            bar.style.width = perc + '%';
            bar.textContent = perc + '%';
        }
    },

    resetSoundUploadProgress: function (uploadTarget) {
        var wrap = document.querySelector('[data-upload-progress="' + uploadTarget + '"]');
        if (!wrap) { return; }
        var bar = wrap.querySelector('.progress-bar');
        if (bar) { bar.className = 'progress-bar'; bar.style.width = '0%'; bar.textContent = '0%'; }
        wrap.classList.add('d-none');
    },

    setSoundUploadActionMode: function (uploadTarget, mode) {
        var btn = document.querySelector('[data-upload-cancel="' + uploadTarget + '"]');
        if (!btn) { return; }
        if (mode === 'cancel') {
            btn.classList.remove('d-none', 'btn-outline-warning');
            btn.classList.add('btn-outline-danger');
            btn.setAttribute('data-upload-mode', 'cancel');
            btn.textContent = 'Annulla';
        } else if (mode === 'retry') {
            btn.classList.remove('d-none', 'btn-outline-danger');
            btn.classList.add('btn-outline-warning');
            btn.setAttribute('data-upload-mode', 'retry');
            btn.textContent = 'Riprova';
        } else {
            btn.classList.add('d-none');
            btn.setAttribute('data-upload-mode', '');
            btn.classList.remove('btn-outline-warning');
            btn.classList.add('btn-outline-danger');
            btn.textContent = 'Annulla';
        }
    },

    showAlert: function (type, message) {
        if (!this.alertEl) { return; }
        this.alertEl.className = 'alert alert-' + type + ' mb-3';
        this.alertEl.textContent = message;
    },

    hideAlert: function () {
        if (!this.alertEl) { return; }
        this.alertEl.className = 'alert d-none mb-3';
        this.alertEl.textContent = '';
    },

    notifyToast: function (type, message) {
        var toastApi = null;
        if (typeof globalWindow.Toast !== 'undefined' && globalWindow.Toast && typeof globalWindow.Toast.show === 'function') {
            toastApi = globalWindow.Toast;
        } else if (typeof Toast !== 'undefined' && Toast && typeof Toast.show === 'function') {
            // Fallback difensivo: in alcuni boot-order Toast puo essere globale ma non ancora assegnato su globalWindow.Toast.
            toastApi = Toast;
        }

        if (!toastApi) {
            return;
        }

        toastApi.show({
            body: String(message || ''),
            type: String(type || 'info')
        });
    },

    post: function (url, payload, ok, fail) {
        var self = this;
        var data = (payload && typeof payload === 'object') ? payload : {};

        if (typeof globalWindow.Request !== 'undefined'
            && globalWindow.Request
            && globalWindow.Request.http
            && typeof globalWindow.Request.http.post === 'function') {
            globalWindow.Request.http.post(url, data)
                .then(function (r) { if (typeof ok === 'function') ok(r || {}); })
                .catch(function (error) {
                    if (typeof fail === 'function') fail(error);
                    else self.showAlert('danger', self.err(error));
                });
            return;
        }

        if (typeof globalWindow.$ === 'function') {
            globalWindow.$.ajax({
                url: url,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(data),
                success: function (r) { if (typeof ok === 'function') ok(r || {}); },
                error: function (xhr) {
                    var e = {};
                    try { e = JSON.parse(xhr.responseText); } catch (ex) { e = { message: xhr.statusText }; }
                    if (typeof fail === 'function') fail(e);
                    else self.showAlert('danger', self.err(e));
                }
            });
            return;
        }

        self.showAlert('danger', 'Nessun client HTTP disponibile.');
    },

    err: function (error) {
        var message = 'Errore sconosciuto';
        if (typeof globalWindow.Request !== 'undefined' && globalWindow.Request) {
            if (typeof globalWindow.Request.getErrorMessage === 'function') {
                message = String(globalWindow.Request.getErrorMessage(error, '') || '').trim() || message;
            }
        }
        if (message === 'Errore sconosciuto' && error && error.message) {
            message = String(error.message).trim() || message;
        }
        return message;
    },

    extractError: function (err) {
        if (err && err.message) { return err.message; }
        if (typeof err === 'string') { return err; }
        return 'Errore sconosciuto';
    }
};

globalWindow.AdminSettings = AdminSettings;
export { AdminSettings as AdminSettings };
export default AdminSettings;

