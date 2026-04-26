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

function normalizeForumError(error, fallback) {
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


function GameForumPage(extension) {
        let page = {
            dataset: null,
            types: null,
            forumModule: null,
            authzEventName: 'authz:changed',
            _offAuthzChanged: null,
            init: function() {
                this.bindAuthzEvents();
                this.getTypes();
                return this;
            },
            sync: function () {
                return this.init();
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
                this.refreshPermissionSensitiveUi();
                return this;
            },
            refreshPermissionSensitiveUi: function () {
                if (!Array.isArray(this.types) || !Array.isArray(this.dataset)) {
                    return this;
                }

                this.buildTypesList();
                this.buildForumList();
                return this;
            },
            getSections: function() {
                var self = this;
                var payload = {
                    orderBy: 'id|ASC',
                    cache: true,
                    cache_ttl: 300
                };
                var onSuccess = function (response) {
                    self.dataset = self._setOrderInSections(response.dataset);
                    self.buildForumList();
                };
                var onError = function (error) {
                    Toast.show({
                        body: normalizeForumError(error, 'Impossibile caricare l\'elenco forum.'),
                        type: 'error'
                    });
                };

                this.callForum('listForums', payload, onSuccess, onError);
            },
            _setOrderInSections: function (dataset) {
                let arr = [];
                for (var i in this.types) {
                    arr[this.types[i].id] = [];
                }

                for (var i in dataset) {
                    arr[dataset[i].type].push(dataset[i]);
                }

                return arr;
            },
            getTypes: function() {
                var self = this;
                var payload = {
                    orderBy: 'id|ASC',
                    cache: true,
                    cache_ttl: 300
                };
                var onSuccess = function (response) {
                    self.types = response.dataset;
                    self.buildTypesList();
                    self.getSections();
                };
                var onError = function (error) {
                    Toast.show({
                        body: normalizeForumError(error, 'Impossibile caricare le categorie forum.'),
                        type: 'error'
                    });
                };

                this.callForum('listTypes', payload, onSuccess, onError);
            },
            buildTypesList: function() {
                var block = $('#forums-page-body').empty();
        
                for (var i in this.types) {
                    let template = $($('template[name="template_forum_section"]').html());
                    let is_on_game = (this.types[i].is_on_game == 1)? 'INGAME' : 'OFFGAME';
        
                    template.attr('ref', this.types[i].id);
                    template.find('[name="is_on_game"]').html(is_on_game);
                    template.find('[name="type_title"]').html(this.types[i].title);
        
                    template.appendTo(block);
                }
            },
            buildForumList: function() {
                for (var i in this.dataset) {
                    let block = $('.forum-section[ref="' + i + '"] .list-group');
                    if (block.length != 0) {
                        if (this.dataset[i].length == 0) {
                            block.append('<div class="list-group-item text-center"><h6 class="m-0">Non ci sono forum in questa sezione.</h6></div>');
                            continue;
                        } else {
                            for (var k in this.dataset[i]) {
                                let template = $($('template[name="template_forum_list_sections"]').html());

                                template.find('[name="title"]').attr('href', '/game/forum/' + this.dataset[i][k].id).html(this.dataset[i][k].name);
                                template.find('[name="chevron_link"]').attr('href', '/game/forum/' + this.dataset[i][k].id);
                                template.find('[name="body"]').html(this.dataset[i][k].description);
                                template.find('[name="count_thread"]').html(this.dataset[i][k].count_thread);
                
                                template.appendTo(block);
                            } 
                        }
                    }
                }
            },
            destroy: function () {
                this.unbindAuthzEvents();
                return this;
            },
            unmount: function () {
                return this.destroy();
            }
        };

        let forum = Object.assign({}, page, extension);
        return forum.init();
    }

globalWindow.GameForumPage = GameForumPage;
export { GameForumPage as GameForumPage };
export default GameForumPage;

