const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function resolveModule(name) {
    if (globalWindow.RuntimeBootstrap && typeof globalWindow.RuntimeBootstrap.resolveAppModule === 'function') {
        try {
            return globalWindow.RuntimeBootstrap.resolveAppModule(name);
        } catch (error) {
            return null;
        }
    }
    return null;
}

function notifyMessagesModalError(error, fallback, type) {
    if (globalWindow.GameFeatureError && typeof globalWindow.GameFeatureError.toast === 'function') {
        globalWindow.GameFeatureError.toast(error, fallback || 'Impossibile aprire i messaggi.', type || 'warning');
        return;
    }
    if (globalWindow.Toast && typeof globalWindow.Toast.show === 'function') {
        globalWindow.Toast.show({
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
    if (!globalWindow.inboxModal || typeof globalWindow.inboxModal.show !== 'function') {
        notifyMessagesModalError(null, 'Modale messaggi non disponibile.');
        return;
    }

    var messages = null;
    var module = resolveModule('game.messages');
    if (module && typeof module.widget === 'function') {
        messages = module.widget({ key: 'modal', root: '#inbox-modal' });
    }

    if (!messages && typeof globalWindow.GameMessagesPage === 'function') {
        try {
            messages = globalWindow.GameMessagesPage({ key: 'modal', root: '#inbox-modal' });
        } catch (error) {
            notifyMessagesModalError(error, 'Errore durante inizializzazione messaggi.');
        }
    }

    if (!messages || typeof messages.startCompose !== 'function') {
        notifyMessagesModalError(null, 'Servizio messaggi non disponibile.');
        return;
    }

    messages.startCompose(characterId, characterName);
    globalWindow.inboxModal.show();
}

globalWindow.GameMessagesModal = globalWindow.GameMessagesModal || {};
globalWindow.GameMessagesModal.openMessageModal = openMessageModal;
globalWindow.GameOpenMessageModal = openMessageModal;
export { openMessageModal as GameOpenMessageModal };
export default openMessageModal;

