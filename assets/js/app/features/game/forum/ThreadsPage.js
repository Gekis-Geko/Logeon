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

    function normalizeThreadsError(error, fallback) {
        if (window.GameFeatureError && typeof window.GameFeatureError.normalize === 'function') {
            return window.GameFeatureError.normalize(error, fallback || 'Operazione non riuscita.');
        }
        if (typeof error === 'string' && error.trim() !== '') {
            return error.trim();
        }
        if (error && typeof error.message === 'string' && error.message.trim() !== '') {
            return error.message.trim();
        }
        return fallback || 'Operazione non riuscita.';
    }


    function GameThreadsPage(forum_id, extension) {
            let page = {
                dataset: null,
                dg_threads: {},
                forumModule: null,
                authzEventName: 'authz:changed',
                _offAuthzChanged: null,
                init: function () {
                    if (null == forum_id) {
                        Dialog('danger', {title: 'Errore', body: '<p>Riferimento al forum mancante.</p>'}).show();
                    
                        return;
                    }

                    this.bindAuthzEvents();
                    this.get();

                    return this;
                },
                sync: function () {
                    this.init();
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
                    if (typeof window.EventBus === 'function') {
                        try {
                            return window.EventBus();
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
                    this.refreshThreadsGridByPermissions();
                    return this;
                },
                refreshThreadsGridByPermissions: function () {
                    var grid = this.dg_threads;
                    if (!grid || typeof grid !== 'object') {
                        return this;
                    }

                    if (typeof grid.updateTable === 'function') {
                        grid.updateTable();
                        return this;
                    }

                    if (typeof grid.reloadData === 'function') {
                        grid.reloadData();
                        return this;
                    }

                    return this;
                },
                get: function () {
                    var self = this;
                    var payload = { id: forum_id };
                    var onSuccess = function (response) {
                        self.dataset = response.dataset;

                        if (null != self.dataset) {
                            self.build();
                        }

                        return;
                    };
                    var onError = function (error) {
                        Toast.show({
                            body: normalizeThreadsError(error, 'Impossibile caricare i dettagli del forum.'),
                            type: 'error'
                        });
                    };

                    this.callForum('getForum', payload, onSuccess, onError);
                },            
                build: function () {
                    let block = $('#threads-page > header');
            
                    block.find('[name="forum_title"]').html(this.dataset.name);
                    block.find('[name="forum_type"]').html(this.dataset.title);
                    block.find('[name="forum_description"]').html(this.dataset.description);
                    this.buildDatagrid();
                },

                buildDatagrid: function () {
                    var config = null;
                    let mod = resolveModule('game.forum');
                    if (mod && typeof mod.threadsGridConfig === 'function') {
                        config = mod.threadsGridConfig();
                    }
                    if (!config) {
                        console.warn('[Threads] datagrid config missing.');
                        return;
                    }
                    if (!config.nav) {
                        config.nav = {};
                    }
                    config.nav.query = {
                        father_id: null,
                        forum_id: forum_id
                    };
                    this.dg_threads = new Datagrid("grid-threads", config);
                    this.dg_threads.loadData(
                        config.nav.query,
                        config.nav.results,
                        config.nav.page,
                        [
                            'is_important|DESC',
                            'date_created|DESC',
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

            let threads = Object.assign({}, page, extension);
            return threads.init();
        }

    window.GameThreadsPage = GameThreadsPage;
})(window);

