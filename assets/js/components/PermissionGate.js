function PermissionGate(extension) {
    let ext = (extension && typeof extension === 'object') ? Object.assign({}, extension) : {};

    let base = {
        storage: null,
        eventBus: null,
        emitEvents: false,
        eventNameChanged: 'authz:changed',
        storageKeys: {
            userId: 'userId',
            isAdmin: 'userIsAdministrator',
            isModerator: 'userIsModerator',
            isMaster: 'userIsMaster'
        },
        roleAliases: {
            admin: 'admin',
            administrator: 'admin',
            moderator: 'moderator',
            mod: 'moderator',
            master: 'master',
            gm: 'master',
            staff: 'staff'
        },
        _memoryStorage: {},

        toBool: function (value) {
            if (typeof value === 'boolean') {
                return value;
            }
            if (value == null) {
                return false;
            }

            var normalized = String(value).trim().toLowerCase();
            if (normalized === 'true' || normalized === 'yes' || normalized === 'on') {
                return true;
            }
            if (normalized === 'false' || normalized === 'no' || normalized === 'off' || normalized === '') {
                return false;
            }

            return parseInt(value, 10) === 1;
        },

        _resolveStorageApi: function () {
            if (this.storage && typeof this.storage.get === 'function' && typeof this.storage.set === 'function') {
                return this.storage;
            }

            if (typeof window !== 'undefined' && typeof window.Storage === 'function') {
                try {
                    var storageApi = window.Storage();
                    if (storageApi && typeof storageApi.get === 'function' && typeof storageApi.set === 'function') {
                        return storageApi;
                    }
                } catch (error) {
                    // fallback to memory storage
                }
            }

            if (typeof Storage !== 'undefined' && typeof Storage === 'function') {
                try {
                    var storageGlobal = Storage();
                    if (storageGlobal && typeof storageGlobal.get === 'function' && typeof storageGlobal.set === 'function') {
                        return storageGlobal;
                    }
                } catch (error) {
                    // fallback to memory storage
                }
            }

            var self = this;
            return {
                get: function (key) {
                    return self._memoryStorage[String(key || '')];
                },
                set: function (key, value) {
                    self._memoryStorage[String(key || '')] = value;
                    return true;
                },
                remove: function (key) {
                    delete self._memoryStorage[String(key || '')];
                    return true;
                }
            };
        },

        _getStorageValue: function (key, fallbackValue) {
            var storageApi = this._resolveStorageApi();
            if (!storageApi || typeof storageApi.get !== 'function') {
                return fallbackValue;
            }
            var value = storageApi.get(key);
            return (typeof value === 'undefined') ? fallbackValue : value;
        },

        _setStorageValue: function (key, value) {
            var storageApi = this._resolveStorageApi();
            if (!storageApi || typeof storageApi.set !== 'function') {
                this._memoryStorage[String(key || '')] = value;
                return this;
            }
            storageApi.set(key, value);
            return this;
        },

        _removeStorageValue: function (key) {
            var storageApi = this._resolveStorageApi();
            if (storageApi && typeof storageApi.remove === 'function') {
                storageApi.remove(key);
            } else {
                delete this._memoryStorage[String(key || '')];
            }
            return this;
        },

        _resolveEventBus: function () {
            if (this.eventBus && typeof this.eventBus.emit === 'function') {
                return this.eventBus;
            }

            if (typeof window !== 'undefined' && typeof window.EventBus === 'function') {
                try {
                    var bus = window.EventBus();
                    if (bus && typeof bus.emit === 'function') {
                        return bus;
                    }
                } catch (error) {
                    return null;
                }
            }

            if (typeof EventBus !== 'undefined' && typeof EventBus === 'function') {
                try {
                    var globalBus = EventBus();
                    if (globalBus && typeof globalBus.emit === 'function') {
                        return globalBus;
                    }
                } catch (error) {
                    return null;
                }
            }

            return null;
        },

        _emitChanged: function (payload) {
            if (this.emitEvents !== true) {
                return this;
            }
            var bus = this._resolveEventBus();
            if (!bus || typeof bus.emit !== 'function') {
                return this;
            }
            bus.emit(this.eventNameChanged || 'authz:changed', Object.assign({
                userId: this.getUserId(),
                flags: this.getFlags(),
                roles: this.getRoles()
            }, (payload && typeof payload === 'object') ? payload : {}));
            return this;
        },

        getUserId: function () {
            var key = this.storageKeys && this.storageKeys.userId ? this.storageKeys.userId : 'userId';
            return parseInt(this._getStorageValue(key, 0) || 0, 10) || 0;
        },

        setUserId: function (userId) {
            var key = this.storageKeys && this.storageKeys.userId ? this.storageKeys.userId : 'userId';
            this._setStorageValue(key, parseInt(userId, 10) || 0);
            return this;
        },

        isAuthenticated: function () {
            return this.getUserId() > 0;
        },

        isAdmin: function () {
            var key = this.storageKeys && this.storageKeys.isAdmin ? this.storageKeys.isAdmin : 'userIsAdministrator';
            return this.toBool(this._getStorageValue(key, 0));
        },

        isModerator: function () {
            var key = this.storageKeys && this.storageKeys.isModerator ? this.storageKeys.isModerator : 'userIsModerator';
            return this.toBool(this._getStorageValue(key, 0));
        },

        isMaster: function () {
            var key = this.storageKeys && this.storageKeys.isMaster ? this.storageKeys.isMaster : 'userIsMaster';
            return this.toBool(this._getStorageValue(key, 0));
        },

        isStaff: function () {
            return this.isAdmin() || this.isModerator() || this.isMaster();
        },

        isOwner: function (ownerUserId) {
            let me = this.getUserId();
            let owner = parseInt(ownerUserId, 10) || 0;
            return me > 0 && owner > 0 && me === owner;
        },

        getFlags: function () {
            return {
                isAdmin: this.isAdmin(),
                isModerator: this.isModerator(),
                isMaster: this.isMaster(),
                isStaff: this.isStaff()
            };
        },

        getRoles: function () {
            var roles = [];
            if (this.isAdmin()) {
                roles.push('admin');
            }
            if (this.isModerator()) {
                roles.push('moderator');
            }
            if (this.isMaster()) {
                roles.push('master');
            }
            if (roles.length > 1 || (roles.length === 1 && roles[0] !== 'staff')) {
                if (this.isStaff()) {
                    roles.push('staff');
                }
            }
            return roles;
        },

        hasRole: function (role) {
            var normalized = String(role || '').trim().toLowerCase();
            if (normalized === '') {
                return false;
            }

            var aliasMap = (this.roleAliases && typeof this.roleAliases === 'object') ? this.roleAliases : {};
            var resolved = aliasMap[normalized] || normalized;

            switch (resolved) {
                case 'admin':
                    return this.isAdmin();
                case 'moderator':
                    return this.isModerator();
                case 'master':
                    return this.isMaster();
                case 'staff':
                    return this.isStaff();
                default:
                    return false;
            }
        },

        can: function (capability, context) {
            var normalized = String(capability || '').trim().toLowerCase();
            if (normalized === '') {
                return false;
            }

            if (typeof this.capabilities === 'function') {
                return this.toBool(this.capabilities(normalized, context, this));
            }

            var map = (this.capabilities && typeof this.capabilities === 'object') ? this.capabilities : null;
            if (map && Object.prototype.hasOwnProperty.call(map, normalized)) {
                var rule = map[normalized];
                if (typeof rule === 'function') {
                    return this.toBool(rule.call(this, context, this));
                }
                if (Array.isArray(rule)) {
                    for (var i = 0; i < rule.length; i++) {
                        if (this.hasRole(rule[i])) {
                            return true;
                        }
                    }
                    return false;
                }
                return this.toBool(rule);
            }

            switch (normalized) {
                case 'forum.admin':
                    return this.canAdminForum();
                case 'forum.moderate':
                    return this.isStaff();
                case 'staff':
                    return this.isStaff();
                case 'admin':
                    return this.isAdmin();
                case 'moderator':
                    return this.isModerator();
                case 'master':
                    return this.isMaster();
                case 'owner':
                    return this.isOwner(context && context.ownerUserId);
                default:
                    return false;
            }
        },

        canAdminForum: function () {
            return this.isAdmin();
        },

        setFromUser: function (user, options) {
            if (!user || typeof user !== 'object') {
                return this;
            }

            var cfg = (options && typeof options === 'object') ? options : {};
            var changed = [];

            var userIdKey = this.storageKeys && this.storageKeys.userId ? this.storageKeys.userId : 'userId';
            if (cfg.setUserId !== false && Object.prototype.hasOwnProperty.call(user, 'id')) {
                var nextUserId = parseInt(user.id, 10) || 0;
                var currentUserId = this.getUserId();
                if (currentUserId !== nextUserId) {
                    this._setStorageValue(userIdKey, nextUserId);
                    changed.push({
                        key: userIdKey,
                        previous: currentUserId,
                        next: nextUserId
                    });
                }
            }

            var mappings = [
                { field: 'is_administrator', key: this.storageKeys.isAdmin || 'userIsAdministrator' },
                { field: 'is_moderator', key: this.storageKeys.isModerator || 'userIsModerator' },
                { field: 'is_master', key: this.storageKeys.isMaster || 'userIsMaster' }
            ];

            for (var i = 0; i < mappings.length; i++) {
                var item = mappings[i];
                var nextValue = this.toBool(user[item.field]) ? 1 : 0;
                var prevValue = this.toBool(this._getStorageValue(item.key, 0)) ? 1 : 0;
                if (prevValue !== nextValue) {
                    this._setStorageValue(item.key, nextValue);
                    changed.push({
                        key: item.key,
                        previous: prevValue,
                        next: nextValue
                    });
                } else {
                    this._setStorageValue(item.key, nextValue);
                }
            }

            if (changed.length > 0) {
                this._emitChanged({
                    operation: 'set_from_user',
                    user: user,
                    changes: changed
                });
            }

            return this;
        },

        setUser: function (user, options) {
            return this.setFromUser(user, options);
        },

        clearRoles: function (options) {
            var cfg = (options && typeof options === 'object') ? options : {};
            var changes = [];

            var roleKeys = [
                this.storageKeys.isAdmin || 'userIsAdministrator',
                this.storageKeys.isModerator || 'userIsModerator',
                this.storageKeys.isMaster || 'userIsMaster'
            ];

            for (var i = 0; i < roleKeys.length; i++) {
                var key = roleKeys[i];
                var prev = this.toBool(this._getStorageValue(key, 0)) ? 1 : 0;
                if (prev !== 0) {
                    changes.push({
                        key: key,
                        previous: prev,
                        next: 0
                    });
                }
                this._setStorageValue(key, 0);
            }

            if (cfg.keepUserId !== true) {
                var userIdKey = this.storageKeys.userId || 'userId';
                var prevUserId = this.getUserId();
                if (prevUserId !== 0) {
                    changes.push({
                        key: userIdKey,
                        previous: prevUserId,
                        next: 0
                    });
                }
                this._setStorageValue(userIdKey, 0);
            }

            if (changes.length > 0) {
                this._emitChanged({
                    operation: 'clear_roles',
                    changes: changes
                });
            }

            return this;
        }
    };

    var applyExtension = function (instance, extra) {
        if (!extra || typeof extra !== 'object') {
            return instance;
        }

        var customStorageKeys = (extra.storageKeys && typeof extra.storageKeys === 'object')
            ? Object.assign({}, extra.storageKeys)
            : null;
        var customRoleAliases = (extra.roleAliases && typeof extra.roleAliases === 'object')
            ? Object.assign({}, extra.roleAliases)
            : null;
        var customCapabilities = (typeof extra.capabilities !== 'undefined')
            ? extra.capabilities
            : undefined;

        var plainExt = Object.assign({}, extra);
        delete plainExt.storageKeys;
        delete plainExt.roleAliases;
        delete plainExt.capabilities;

        Object.assign(instance, plainExt);

        if (customStorageKeys) {
            instance.storageKeys = Object.assign({}, instance.storageKeys || {}, customStorageKeys);
        }
        if (customRoleAliases) {
            instance.roleAliases = Object.assign({}, instance.roleAliases || {}, customRoleAliases);
        }
        if (typeof customCapabilities !== 'undefined') {
            if (customCapabilities && typeof customCapabilities === 'object' && typeof customCapabilities !== 'function') {
                instance.capabilities = Object.assign({}, (instance.capabilities && typeof instance.capabilities === 'object') ? instance.capabilities : {}, customCapabilities);
            } else {
                instance.capabilities = customCapabilities;
            }
        }

        return instance;
    };

    if (typeof window !== 'undefined') {
        if (!window.__permission_gate_instance) {
            window.__permission_gate_instance = applyExtension(Object.assign({}, base), ext);
        } else if (ext && typeof ext === 'object') {
            applyExtension(window.__permission_gate_instance, ext);
        }
        return window.__permission_gate_instance;
    }

    return applyExtension(Object.assign({}, base), ext);
}

if (typeof window !== 'undefined') {
    window.PermissionGate = PermissionGate;
}
