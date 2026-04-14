(function (window) {
    'use strict';

    function resolveModule(name) {
        if (window.RuntimeBootstrap && typeof window.RuntimeBootstrap.resolveAppModule === 'function') {
            try {
                return window.RuntimeBootstrap.resolveAppModule(name);
            } catch (error) {
                return null;
            }
        }
        return null;
    }

    function notifyMessagesModalError(error, fallback, type) {
        if (window.GameFeatureError && typeof window.GameFeatureError.toast === 'function') {
            window.GameFeatureError.toast(error, fallback || 'Impossibile aprire i messaggi.', type || 'warning');
            return;
        }
        if (window.Toast && typeof window.Toast.show === 'function') {
            window.Toast.show({
                body: fallback || 'Impossibile aprire i messaggi.',
                type: type || 'warning'
            });
            return;
        }
        if (typeof console !== 'undefined' && typeof console.warn === 'function') {
            console.warn('[MessagesModal]', error || fallback || 'Impossibile aprire i messaggi.');
        }
    }

    function openMessageModal(characterId, characterName) {
        if (!window.inboxModal || typeof window.inboxModal.show !== 'function') {
            notifyMessagesModalError(null, 'Modale messaggi non disponibile.');
            return;
        }

        var messages = null;
        var module = resolveModule('game.messages');
        if (module && typeof module.widget === 'function') {
            messages = module.widget({ key: 'modal', root: '#inbox-modal' });
        }

        if (!messages && typeof window.GameMessagesPage === 'function') {
            try {
                messages = window.GameMessagesPage({ key: 'modal', root: '#inbox-modal' });
            } catch (error) {
                notifyMessagesModalError(error, 'Errore durante inizializzazione messaggi.');
            }
        }

        if (!messages || typeof messages.startCompose !== 'function') {
            notifyMessagesModalError(null, 'Servizio messaggi non disponibile.');
            return;
        }

        messages.startCompose(characterId, characterName);
        window.inboxModal.show();
    }

    window.GameMessagesModal = window.GameMessagesModal || {};
    window.GameMessagesModal.openMessageModal = openMessageModal;
    window.GameOpenMessageModal = openMessageModal;
})(window);
