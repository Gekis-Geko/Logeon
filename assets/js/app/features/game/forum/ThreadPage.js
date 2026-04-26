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

function normalizeThreadError(error, fallback) {
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


function GameThreadPage(thread_id, extension) {
        let page = {
            thread_id: null,
            user_id: null,
            dataset: null,
            dg_answers: {},
            forumModule: null,
            moveModal: null,
            authzEventName: 'authz:changed',
            _offAuthzChanged: null,

            init: function () {
                if (null == thread_id) {
                    Dialog('danger', {title: 'Errore', body: '<p>ID mancanti nel thread.</p>'}).show();

                    return;
                }

                this.thread_id = thread_id;
                this.user_id = Storage().get('userId');
                this.bindAuthzEvents();
                
                this._getThread();

                return this;
            },
            sync: function () {
                this.init(this.thread_id, this.user_id);
            },
            getForumModule: function () {
                if (this.forumModule) {
                    return this.forumModule;
                }
                if (typeof resolveModule !== 'function') {
                    return null;
                }

                this.forumModule = resolveModule('game.forum');
                return this.forumModule;
            },
            callForum: function (method, payload, onSuccess, onError) {
                var mod = this.getForumModule();
                var fn = String(method || '').trim();
                if (!mod || fn === '' || typeof mod[fn] !== 'function') {
                    if (typeof onError === 'function') {
                        onError(new Error('Forum module not available: ' + fn));
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
            getEventBus: function () {
                if (typeof globalWindow.EventBus === 'function') {
                    try {
                        return globalWindow.EventBus();
                    } catch (error) {
                        return null;
                    }
                }
                return null;
            },
            bindAuthzEvents: function () {
                if (typeof this._offAuthzChanged === 'function') {
                    return this;
                }

                var bus = this.getEventBus();
                if (!bus || typeof bus.on !== 'function') {
                    return this;
                }

                var self = this;
                this._offAuthzChanged = bus.on(this.authzEventName || 'authz:changed', function () {
                    self.handleAuthzChanged();
                });

                return this;
            },
            unbindAuthzEvents: function () {
                if (typeof this._offAuthzChanged === 'function') {
                    this._offAuthzChanged();
                }
                this._offAuthzChanged = null;
                return this;
            },
            handleAuthzChanged: function () {
                this.user_id = Storage().get('userId');
                this.refreshThreadButtons();
                this.refreshAnswerButtonsByPermissions();
                return this;
            },
            refreshThreadButtons: function () {
                if (!this.dataset || typeof this.dataset !== 'object') {
                    return this;
                }

                $('[name="thread_buttons"]').empty();
                this._buildButtonsEdit();
                this._buildButtonsImportants();
                this._buildButtonsClosed();
                this._buildButtonsMove();
                this._buildButtonsQuotes();
                this._buildButtonsDelete();
                this.bindThreadButtons();

                return this;
            },
            refreshAnswerButtonsByPermissions: function () {
                var grid = this.dg_answers || this.dg_threads;
                if (!grid || typeof grid !== 'object') {
                    return this;
                }

                if (typeof grid.updateTable === 'function') {
                    grid.updateTable();
                    this.bindAnswerActionButtons();
                    return this;
                }

                if (typeof grid.reloadData === 'function') {
                    grid.reloadData();
                    return this;
                }

                return this;
            },
            _getThread: function () {
                var self = this;
                var payload = { id: this.thread_id };
                var onSuccess = function (response) {
                    self.dataset = response.dataset;

                    if (null == response.dataset) {
                        return {};
                    }

                    self._build();
                };
                var onError = function (error) {
                    Toast.show({
                        body: normalizeThreadError(error, 'Impossibile caricare il thread.'),
                        type: 'error'
                    });
                };

                this.callForum('getThread', payload, onSuccess, onError);
            },
            _build: function () {
                let important = (this.dataset.is_important) ? '<i class="bi bi-star-fill text-warning mx-2" title="Importante"></i>' : '';
				    let closed = (this.dataset.is_closed) ? '<i class="bi bi-lock-fill text-danger mx-2" title="Chiuso"></i>' : '';

                if (this.dataset.is_closed) {
                    $('#thread_answer').hide();
                } else {
                    $('#thread_answer').show();
                }
                $('[name="forum_title"]').attr('href', '/game/forum/' + this.dataset.forum_id).html(this.dataset.forum_name);
                $('[name="link_profile"]').attr('href', '/game/profile/' + this.dataset.character_id);
        
                $('[name="character_name"]').html(this.dataset.name);            
                $('[name="thread_title"]').html(this.dataset.title);
                $('[name="details"]').html(important + closed);
                $('[name="thread_body"]').html(this.dataset.body);
        
                if (null != this.dataset.date_updated) {
                    $('[name="date_created"]').html('<b>' + Dates().formatHumanDateTime(this.dataset.date_created) + '</b> e l\'ultima modifica il: <b>' + Dates().formatHumanDateTime(this.dataset.date_updated) + '</b>');
                } else {
                    $('[name="date_created"]').html('<b>' + Dates().formatHumanDateTime(this.dataset.date_created) + '</b>');
                }
        
                this.refreshThreadButtons();

                this._buildDatagridAnswers();
            },
            bindThreadButtons: function () {
                var self = this;
                let block = $('[name="thread_buttons"]');
                if (!block.length) {
                    return;
                }

                block.off('click', '[data-action="thread-edit"]');
                block.off('click', '[data-action="thread-important"]');
                block.off('click', '[data-action="thread-closed"]');
                block.off('click', '[data-action="thread-move"]');
                block.off('click', '[data-action="thread-delete"]');
                block.off('click', '[data-action="thread-quote"]');

                block.on('click', '[data-action="thread-edit"]', function (e) {
                    e.preventDefault();
                    if (typeof globalWindow.editThreadModal !== 'undefined' && globalWindow.editThreadModal && typeof globalWindow.editThreadModal.show === 'function') {
                        globalWindow.editThreadModal.show(self.dataset);
                    }
                });
                block.on('click', '[data-action="thread-important"]', function (e) {
                    e.preventDefault();
                    self.importantThreadDialog();
                });
                block.on('click', '[data-action="thread-closed"]', function (e) {
                    e.preventDefault();
                    self.closedThreadDialog();
                });
                block.on('click', '[data-action="thread-move"]', function (e) {
                    e.preventDefault();
                    self.moveThreadDialog();
                });
                block.on('click', '[data-action="thread-delete"]', function (e) {
                    e.preventDefault();
                    self.deleteThreadDialog();
                });
                block.on('click', '[data-action="thread-quote"]', function (e) {
                    e.preventDefault();
                    self.setQuotes();
                });
            },
            isForumAdmin: function () {
                if (typeof PermissionGate === 'function') {
                    return PermissionGate().canAdminForum();
                }
                return false;
            },
            _buildButtonsEdit: function () {
                if (this.isForumAdmin() || this.user_id == this.dataset.user_id) {
                    $('[name="thread_buttons"]').append(
                        "<button type='button' title='Modifica' class='btn btn-sm btn-secondary mx-1' data-action='thread-edit'><i class='bi bi-pencil-fill'></i></button>"
                    );
                }
            },
            _buildButtonsImportants: function () {
                if (this.isForumAdmin() && this.dataset.is_important == 1) {
                    $('[name="thread_buttons"]').append(
                        "<button type='button' title='Non importante' class='btn btn-sm btn-secondary mx-1' data-action='thread-important'><i class='bi bi-star'></i></button>"
                    );
                } else if (this.isForumAdmin() && this.dataset.is_important == 0) {
                    $('[name="thread_buttons"]').append(
                        "<button type='button' title='Importante' class='btn btn-sm btn-warning mx-1' data-action='thread-important'><i class='bi bi-star-fill'></i></button>"
                    );
                }
            },
            _buildButtonsClosed: function () {
                if (this.isForumAdmin() && this.dataset.is_closed == 1) {
                    $('[name="thread_buttons"]').append(
                        "<button type='button' title='Apri' class='btn btn-sm btn-secondary mx-1' data-action='thread-closed'><i class='bi bi-unlock-fill'></i></button>"
                    );
                } else if (this.isForumAdmin() && this.dataset.is_closed == 0) {
                    $('[name="thread_buttons"]').append(
                        "<button type='button' title='Chiudi' class='btn btn-sm btn-dark mx-1' data-action='thread-closed'><i class='bi bi-lock-fill'></i></button>"
                    );
                }
            },
            _buildButtonsDelete: function () {
                if (this.isForumAdmin() || this.user_id == this.dataset.user_id) {
                    $('[name="thread_buttons"]').append(
                        "<button type='button' title='Elimina' class='btn btn-sm btn-danger mx-1' data-action='thread-delete'><i class='bi bi-trash2-fill'></i></button>"
                    );
                }
            },
        
            _buildButtonsMove: function () {
                if (this.isForumAdmin()) {
                    $('[name="thread_buttons"]').append(
                        "<button type='button' title='Sposta discussione' class='btn btn-sm btn-outline-secondary mx-1' data-action='thread-move'><i class='bi bi-folder-symlink-fill'></i></button>"
                    );
                }
            },

            moveThreadDialog: function () {
                var self = this;
                var modal = this._getMoveModal();
                if (!modal) { return; }

                var select = document.getElementById('thread-move-forum-select');
                if (select) {
                    while (select.options.length > 0) { select.remove(0); }
                    var loadingOpt = document.createElement('option');
                    loadingOpt.value = '';
                    loadingOpt.textContent = '— caricamento... —';
                    select.appendChild(loadingOpt);
                }

                modal.show();

                this.callForum('listForums', {}, function (response) {
                    var forums = (response && Array.isArray(response.dataset)) ? response.dataset : [];
                    if (!select) { return; }
                    while (select.options.length > 0) { select.remove(0); }
                    var emptyOpt = document.createElement('option');
                    emptyOpt.value = '';
                    emptyOpt.textContent = '— seleziona una sezione —';
                    select.appendChild(emptyOpt);
                    for (var i = 0; i < forums.length; i++) {
                        var f = forums[i];
                        var opt = document.createElement('option');
                        opt.value = String(f.id || '');
                        opt.textContent = f.name || f.title || String(f.id);
                        if (String(f.id) === String(self.dataset.forum_id)) {
                            opt.disabled = true;
                            opt.textContent += ' (sezione corrente)';
                        }
                        select.appendChild(opt);
                    }
                }, function () {
                    if (!select) { return; }
                    while (select.options.length > 0) { select.remove(0); }
                    var errOpt = document.createElement('option');
                    errOpt.value = '';
                    errOpt.textContent = '— errore nel caricamento —';
                    select.appendChild(errOpt);
                });
            },

            _getMoveModal: function () {
                if (this.moveModal) { return this.moveModal; }
                var node = document.getElementById('thread-move-modal');
                if (!node || typeof bootstrap === 'undefined' || !bootstrap.Modal) { return null; }

                this.moveModal = new bootstrap.Modal(node);
                var self = this;
                node.addEventListener('click', function (e) {
                    var trigger = e.target && e.target.closest ? e.target.closest('[data-action]') : null;
                    if (!trigger) { return; }
                    var action = String(trigger.getAttribute('data-action') || '').trim();
                    if (action === 'thread-move-send') {
                        e.preventDefault();
                        self._doMoveThread();
                    }
                });

                return this.moveModal;
            },

            _doMoveThread: function () {
                var self = this;
                var select = document.getElementById('thread-move-forum-select');
                var forumId = select ? parseInt(select.value, 10) : 0;
                if (!forumId || forumId <= 0) {
                    if (typeof Toast !== 'undefined') {
                        Toast.show({ body: 'Seleziona una sezione di destinazione.', type: 'warning' });
                    }
                    return;
                }

                var payload = { id: this.thread_id, forum_id: forumId };
                var onSuccess = function () {
                    if (self.moveModal) { self.moveModal.hide(); }
                    if (typeof Toast !== 'undefined') {
                        Toast.show({ body: '<i class="bi bi-check"></i> Discussione <b>spostata</b> con successo', type: 'success' });
                    }
                    setTimeout(function () {
                        globalWindow.location.href = '/game/forum/' + forumId + '/thread/' + self.thread_id + '/';
                    }, 1200);
                };
                var onError = function (error) {
                    if (typeof Toast !== 'undefined') {
                        Toast.show({ body: normalizeThreadError(error, 'Non è possibile spostare la discussione.'), type: 'error' });
                    }
                };

                this.callForum('moveThread', payload, onSuccess, onError);
            },

            _buildButtonsQuotes: function () {
                $('[name="thread_buttons"]').append(
                    "<button type='button' title='Quota il messaggio' class='btn btn-sm btn-secondary mx-1' data-action='thread-quote'><i class='bi bi-quote'></i></button>"
                );
            },
            bindAnswerActionButtons: function () {
                var self = this;
                let grid = $('#grid-answers');
                if (!grid.length) {
                    return;
                }

                grid.off('click', '[data-action="thread-answer-edit"]');
                grid.off('click', '[data-action="thread-answer-quote"]');
                grid.off('click', '[data-action="thread-answer-delete"]');

                grid.on('click', '[data-action="thread-answer-edit"]', function (e) {
                    e.preventDefault();
                    let id = parseInt($(this).attr('data-answer-id'), 10);
                    if (!isNaN(id) && id > 0) {
                        self.editAnswerById(id);
                    }
                });
                grid.on('click', '[data-action="thread-answer-quote"]', function (e) {
                    e.preventDefault();
                    self.quoteAnswerFromElement(this);
                });
                grid.on('click', '[data-action="thread-answer-delete"]', function (e) {
                    e.preventDefault();
                    let id = parseInt($(this).attr('data-answer-id'), 10);
                    if (!isNaN(id) && id > 0) {
                        self.deleteAnswerDialog(id);
                    }
                });
            },
        
            setQuotes: function (name, body) {
                let n = (name !== null && typeof name !== 'undefined' && String(name) !== '') ? name : this.dataset.name;
                let b = (body !== null && typeof body !== 'undefined' && String(body) !== '') ? body : this.dataset.body;
                let quote = '<blockquote class="blockquote"><h5>' + n + ' ha scritto:</h5><hr />' + b + '</blockquote>';
                let editor = $('#answer_editor');

                editor.summernote('pasteHTML', quote);
                //editor.summernote('focus');
        
                return;
            },
            editAnswerById: function (id) {
                if (typeof editThreadModal === 'undefined') {
                    return;
                }
                let grid = this.dg_answers || this.dg_threads;
                if (!grid || typeof grid.getElementByID !== 'function') {
                    return;
                }
                let row = grid.getElementByID(id);
                if (!row) {
                    return;
                }
                editThreadModal.show(row);
            },
            quoteAnswerFromElement: function (element) {
                let node = $(element);
                let name = '';
                let body = '';

                try {
                    name = decodeURIComponent(node.attr('data-quote-name') || '');
                } catch (e) {
                    name = node.attr('data-quote-name') || '';
                }

                try {
                    body = decodeURIComponent(node.attr('data-quote-body') || '');
                } catch (e) {
                    body = node.attr('data-quote-body') || '';
                }

                return this.setQuotes(name, body);
            },
            deleteAnswerDialog: function (id) {
                var self = this;
                Dialog('danger', {
                    title: 'Eliminazione della risposta',
                    body: '<p>Vuoi davvero eliminare quest risposta?</p>'
                }, function () {
                    var dialog = this;
                    var payload = { id: id };
                    var onSuccess = function () {
                        dialog.hide();
                        Toast.show({
                            body: '<i class="bi bi-check"></i> Risposta <b>eliminata</b> con successo',
                            type: 'success'
                        });
                        self.sync();
                    };
                    var onError = function (error) {
                        Toast.show({
                            body: normalizeThreadError(error, '<i class="bi bi-times"></i> C\'e stato un errore: non e possibile eliminare la risposta'),
                            type: 'error'
                        });
                    };

                    self.callForum('deleteThread', payload, onSuccess, onError);
                }).show();
            },
            answered: function () {
                var self = this;
                var dataset = Form().getFields('thread_answer') || {};

                if (!dataset.father_id || parseInt(dataset.father_id, 10) <= 0) {
                    dataset.father_id = this.thread_id;
                }

                if ((!dataset.forum_id || parseInt(dataset.forum_id, 10) <= 0) && this.dataset && this.dataset.forum_id) {
                    dataset.forum_id = this.dataset.forum_id;
                }

                if (!dataset.title || String(dataset.title).trim() === '') {
                    var threadTitle = (this.dataset && this.dataset.title) ? String(this.dataset.title).trim() : '';
                    dataset.title = threadTitle !== '' ? ('Re: ' + threadTitle) : 'Risposta';
                }

                var onSuccess = function () {
                    let editor = $('#answer_editor');

                    Toast.show({
                        body: '<i class="bi bi-check"></i> Risposta al <b>thread</b> inserita con successo',
                        type: 'success'
                    });
                    editor.summernote('reset');
                    self.sync();
                };
                var onError = function (error) {
                    Toast.show({
                        body: normalizeThreadError(error, 'C\'e stato un errore: non e possibile inserire la risposta al <b>thread</b>'),
                        type: 'error'
                    });
                };

                this.callForum('answer', dataset, onSuccess, onError);
            },
            importantThreadDialog: function () {
                var self = this;
                if (this.isForumAdmin() && this.dataset.is_important == 1) {
                    Dialog('default', {
                        title: 'Togliere l\'evidenza del thread?',
                        body: '<p>Vuoi davvero togliere l\'importanza di questo thread?</p><p class="border p-3 rounded lead">"' + self.dataset.title + '"</p>'
                    }, function () {
                        var dialog = this;
                        var onSuccess = function () {
                            dialog.hide();
                            Toast.show({
                                body: '<i class="bi bi-check"></i> Thread reso <b>comune</b> con successo',
                                type: 'success'
                            });
                            self.sync();
                        };
                        var onError = function (error) {
                            Toast.show({
                                body: normalizeThreadError(error, 'C\'e stato un errore: non e possibile rendere comune il thread'),
                                type: 'error'
                            });
                        };

                        self.callForum('setImportantCommon', self.dataset, onSuccess, onError);
                    }).show();
                } else if (this.isForumAdmin() && this.dataset.is_important == 0) {
                    Dialog('default', {
                        title: 'Togliere l\'evidenza del thread?',
                        body: '<p>Vuoi davvero rendere importante questo thread?</p><p class="border p-3 rounded lead">"' + self.dataset.title + '"</p>'
                    }, function () {
                        var dialog = this;
                        var onSuccess = function () {
                            dialog.hide();
                            Toast.show({
                                body: '<i class="bi bi-check"></i> Thread reso <b>importante</b> con successo',
                                type: 'success'
                            });
                            self.sync();
                        };
                        var onError = function (error) {
                            Toast.show({
                                body: normalizeThreadError(error, 'C\'e stato un errore: non e possibile rendere importante il thread'),
                                type: 'error'
                            });
                        };

                        self.callForum('setImportant', self.dataset, onSuccess, onError);
                    }).show();
                }
            },
            closedThreadDialog: function () {
                var self = this;
                if (this.isForumAdmin() && this.dataset.is_closed == 1) {
                    Dialog('default', {
                        title: 'Aprire il thread?',
                        body: '<p>Vuoi davvero Aprire questo thread?</p><p class="border p-3 rounded lead">"' + self.dataset.title + '"</p>'
                    }, function () {
                        var dialog = this;
                        var onSuccess = function () {
                            dialog.hide();
                            Toast.show({
                                body: '<i class="bi bi-check"></i> Thread <b>aperto</b> con successo',
                                type: 'success'
                            });
                            self.sync();
                        };
                        var onError = function (error) {
                            Toast.show({
                                body: normalizeThreadError(error, '<i class="bi bi-times"></i> C\'e stato un errore: non e possibile aprire il thread'),
                                type: 'error'
                            });
                        };

                        self.callForum('unlockThread', self.dataset, onSuccess, onError);
                    }).show();
                } else if (this.isForumAdmin() && this.dataset.is_closed == 0) {
                    Dialog('default', {
                        title: 'Chiudere il thread?',
                        body: '<p>Vuoi davvero chiudere questo thread?</p><p class="border p-3 rounded lead">"' + self.dataset.title + '"</p>'
                    }, function () {
                        var dialog = this;
                        var onSuccess = function () {
                            dialog.hide();
                            Toast.show({
                                body: '<i class="bi bi-check"></i> Thread <b>chiuso</b> con successo',
                                type: 'success'
                            });
                            self.sync();
                        };
                        var onError = function (error) {
                            Toast.show({
                                body: normalizeThreadError(error, '<i class="bi bi-times"></i> C\'e stato un errore: non e possibile chiudere il thread'),
                                type: 'error'
                            });
                        };

                        self.callForum('lockThread', self.dataset, onSuccess, onError);
                    }).show();
                }
            },
            deleteThreadDialog: function () {
                var self = this;
                Dialog('danger', {
                    title: 'Eliminazione del thread',
                    body: '<p>Vuoi davvero eliminare questo thread?</p><p class="border p-3 rounded lead">"' + self.dataset.title + '"</p>'
                }, function () {
                    var dialog = this;
                    var onSuccess = function () {
                        dialog.hide();
                        Toast.show({
                            body: '<i class="bi bi-check"></i> Thread <b>eliminato</b> con successo',
                            type: 'success'
                        });

                        if (null == self.dataset.father_id) {
                            setTimeout(function () {
                                $(location).attr('href', '/game/forum/' + self.dataset.forum_id);
                            }, 2000);
                        }
                    };
                    var onError = function (error) {
                        Toast.show({
                            body: normalizeThreadError(error, '<i class="bi bi-times"></i> C\'e stato un errore: non e possibile eliminare il thread'),
                            type: 'error'
                        });
                    };

                    self.callForum('deleteThread', self.dataset, onSuccess, onError);
                }).show();
            },
            _buildDatagridAnswers: function () {
                var self = this;
                var config = null;
                let mod = resolveModule('game.forum');
                if (mod && typeof mod.answersGridConfig === 'function') {
                    config = mod.answersGridConfig();
                }
                if (!config) {
                    console.warn('[Thread] answers datagrid config missing.');
                    return;
                }
                if (!config.nav) {
                    config.nav = {};
                }
                config.nav.query = {
                    father_id: self.dataset.id,
                };
                this.dg_answers = new Datagrid("grid-answers", config);
                this.dg_threads = this.dg_answers;
                this.dg_answers.onGetDataSuccess = function () {
                    self.bindAnswerActionButtons();
                };
                this.dg_answers.loadData(
                    config.nav.query,
                    config.nav.results,
                    config.nav.page,
                    [
                        'date_created|ASC',
                    ]
                );
            },
            destroy: function () {
                this.unbindAuthzEvents();
                return this;
            },
            unmount: function () {
                return this.destroy();
            }
        };

        let thread = Object.assign({}, page, extension);
        return thread.init();
    }

globalWindow.GameThreadPage = GameThreadPage;
export { GameThreadPage as GameThreadPage };
export default GameThreadPage;

