var ROLE_LABELS = { member: 'Membro', leader: 'Leader', advisor: 'Consigliere', agent: 'Agente', initiate: 'Iniziato' };
var REL_LABELS  = { ally: 'Alleata', neutral: 'Neutrale', rival: 'Rivale', enemy: 'Nemica', vassal: 'Vassalla', overlord: 'Signora' };
var REL_COLORS  = { ally: 'text-bg-success', neutral: 'text-bg-secondary', rival: 'text-bg-warning', enemy: 'text-bg-danger', vassal: 'text-bg-info', overlord: 'text-bg-primary' };
var TYPE_LABELS = { political: 'Politica', military: 'Militare', religious: 'Religiosa', criminal: 'Criminale', mercantile: 'Mercantile', other: 'Altra' };

function resolveModule(name) {
    if (!window.RuntimeBootstrap || typeof window.RuntimeBootstrap.resolveAppModule !== 'function') {
        return null;
    }
    try { return window.RuntimeBootstrap.resolveAppModule(name); } catch (e) { return null; }
}

function escapeHtml(value) {
    return String(value || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

function showModal(id) {
    var n = document.getElementById(id);
    if (!n) { return; }
    if (window.bootstrap && window.bootstrap.Modal) { window.bootstrap.Modal.getOrCreateInstance(n).show(); return; }
    if (typeof $ === 'function') { $(n).modal('show'); }
}

function hideModal(id) {
    var n = document.getElementById(id);
    if (!n) { return; }
    if (window.bootstrap && window.bootstrap.Modal) { window.bootstrap.Modal.getOrCreateInstance(n).hide(); return; }
    if (typeof $ === 'function') { $(n).modal('hide'); }
}

function toast(body, type) {
    if (window.Toast && typeof window.Toast.show === 'function') {
        window.Toast.show({ body: body, type: type || 'info' });
    }
}

function GameFactionsPage(extension) {
    var page = {
        root: null,
        myFactions: [],
        moduleApi: null,
        activeFactionId: 0,
        activeFaction: null,
        inviteSearchTimer: null,

        resolveModuleApi: function () {
            if (this.moduleApi && typeof this.moduleApi.list === 'function') {
                return this.moduleApi;
            }
            return resolveModule('game.factions');
        },

        init: function () {
            this.root = document.querySelector('[data-game-page="factions"]');
            if (!this.root) { return this; }

            this.bindEvents();
            this.loadMyFactions();
            this.loadMyJoinRequests();
            this.loadPublicFactions();
            return this;
        },

        bindEvents: function () {
            var self = this;
            if (!this.root) { return; }

            this.root.addEventListener('click', function (event) {
                var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
                if (!trigger) { return; }
                var action = String(trigger.getAttribute('data-action') || '').trim();

                switch (action) {
                    case 'factions-reload':             event.preventDefault(); self.loadPublicFactions(); break;
                    case 'faction-detail':              event.preventDefault(); self.openDetail(parseInt(trigger.getAttribute('data-id') || '0', 10) || 0); break;
                    case 'faction-leave':               event.preventDefault(); self.confirmLeave(); break;
                    case 'faction-join-request':        event.preventDefault(); self.openJoinRequestModal(); break;
                    case 'faction-join-request-submit': event.preventDefault(); self.submitJoinRequest(); break;
                    case 'join-request-withdraw':       event.preventDefault(); self.withdrawRequest(parseInt(trigger.getAttribute('data-request-id') || '0', 10) || 0); break;
                    case 'faction-leader-invite':       event.preventDefault(); self.leaderInvite(); break;
                    case 'faction-leader-propose-relation': event.preventDefault(); self.leaderProposeRelation(); break;
                    case 'faction-pending-approve':     event.preventDefault(); self.reviewRequest(parseInt(trigger.getAttribute('data-request-id') || '0', 10), 'approved'); break;
                    case 'faction-pending-reject':      event.preventDefault(); self.reviewRequest(parseInt(trigger.getAttribute('data-request-id') || '0', 10), 'rejected'); break;
                    case 'faction-leader-expel':        event.preventDefault(); self.leaderExpel(parseInt(trigger.getAttribute('data-character-id') || '0', 10) || 0); break;
                }

                // invite suggestion
                var suggestion = event.target && event.target.closest ? event.target.closest('[data-role="faction-invite-suggestion"]') : null;
                if (suggestion) {
                    event.preventDefault();
                    self.selectInviteSuggestion(suggestion);
                }
            });

            // Invite search
            var inviteNameInput = document.getElementById('faction-invite-name');
            if (inviteNameInput) {
                inviteNameInput.addEventListener('input', function () { self.handleInviteSearch(); });
            }

            document.addEventListener('click', function (event) {
                var sugEl = document.getElementById('faction-invite-suggestions');
                var invEl = document.getElementById('faction-invite-name');
                if (!sugEl || !invEl) { return; }
                if (!event.target.closest || (!event.target.closest('#faction-invite-suggestions') && event.target !== invEl)) {
                    sugEl.classList.add('d-none');
                    sugEl.innerHTML = '';
                }
            });
        },

        // ---------------------------------------------------------------
        // My factions panel
        // ---------------------------------------------------------------

        loadMyFactions: function () {
            var self = this;
            var mod = this.resolveModuleApi();
            if (!mod || typeof mod.myFactions !== 'function') {
                this._hide('my-factions-loading');
                return;
            }

            mod.myFactions({}).then(function (response) {
                var rows = (response && response.dataset) ? response.dataset : [];
                self.myFactions = Array.isArray(rows) ? rows : [];
                self.renderMyFactions();
            }).catch(function () {
                self._hide('my-factions-loading');
            });
        },

        renderMyFactions: function () {
            var list = document.getElementById('my-factions-list');
            this._hide('my-factions-loading');

            if (!list) { return; }

            if (!this.myFactions.length) {
                this._show('my-factions-empty');
                return;
            }

            list.innerHTML = this.myFactions.map(function (m) {
                var color = m.color_hex || '#6c757d';
                var icon  = m.icon ? '<img src="' + escapeHtml(m.icon) + '" width="14" height="14" class="me-1" style="object-fit:contain;vertical-align:middle;" alt="">' : '';
                var roleLabel = ROLE_LABELS[m.role] || escapeHtml(m.role || 'membro');
                return '<div class="list-group-item list-group-item-action px-3 py-2" style="cursor:pointer;" data-action="faction-detail" data-id="' + (parseInt(m.faction_id || m.id, 10) || 0) + '">'
                    + '<div class="fw-bold small" style="color:' + escapeHtml(color) + '">' + icon + escapeHtml(m.faction_name || m.name || '') + '</div>'
                    + '<div class="text-muted" style="font-size:.75rem;">' + roleLabel + (m.rank ? ' &bull; ' + escapeHtml(m.rank) : '') + '</div>'
                    + '</div>';
            }).join('');
        },

        // ---------------------------------------------------------------
        // My join requests panel
        // ---------------------------------------------------------------

        loadMyJoinRequests: function () {
            var self = this;
            var mod = this.resolveModuleApi();
            if (!mod || typeof mod.myJoinRequests !== 'function') {
                this._hide('my-join-requests-loading');
                return;
            }

            mod.myJoinRequests({}).then(function (response) {
                var rows = (response && response.dataset) ? response.dataset : [];
                self.renderMyJoinRequests(Array.isArray(rows) ? rows : []);
            }).catch(function () {
                self._hide('my-join-requests-loading');
            });
        },

        renderMyJoinRequests: function (rows) {
            var list = document.getElementById('my-join-requests-list');
            this._hide('my-join-requests-loading');

            if (!list) { return; }

            if (!rows.length) {
                this._show('my-join-requests-empty');
                return;
            }

            var statusLabel = { pending: 'In attesa', approved: 'Approvata', rejected: 'Rifiutata', withdrawn: 'Ritirata' };
            var statusColor = { pending: 'text-bg-warning', approved: 'text-bg-success', rejected: 'text-bg-danger', withdrawn: 'text-bg-secondary' };

            list.innerHTML = rows.map(function (r) {
                var st = r.status || 'pending';
                var canWithdraw = st === 'pending';
                return '<div class="list-group-item px-3 py-2">'
                    + '<div class="d-flex justify-content-between align-items-center">'
                    + '<span class="small fw-bold">' + escapeHtml(r.faction_name || '#' + r.faction_id) + '</span>'
                    + '<span class="badge ' + (statusColor[st] || 'text-bg-secondary') + '">' + (statusLabel[st] || escapeHtml(st)) + '</span>'
                    + '</div>'
                    + (canWithdraw ? '<button type="button" class="btn btn-sm btn-outline-secondary mt-1" data-action="join-request-withdraw" data-request-id="' + parseInt(r.id, 10) + '">Ritira</button>' : '')
                    + '</div>';
            }).join('');
        },

        // ---------------------------------------------------------------
        // Public factions list
        // ---------------------------------------------------------------

        loadPublicFactions: function () {
            var loading = document.getElementById('factions-loading');
            var empty   = document.getElementById('factions-empty');
            var list    = document.getElementById('factions-list');

            if (loading) { loading.classList.remove('d-none'); }
            if (empty)   { empty.classList.add('d-none'); }
            if (list)    { list.innerHTML = ''; }

            var mod = this.resolveModuleApi();
            if (!mod || typeof mod.list !== 'function') {
                if (loading) { loading.classList.add('d-none'); }
                return;
            }

            mod.list({ limit: 50, page: 1 }).then(function (response) {
                if (loading) { loading.classList.add('d-none'); }
                var rows = (response && response.dataset && response.dataset.rows) ? response.dataset.rows
                         : (response && Array.isArray(response.dataset)) ? response.dataset : [];
                if (!rows.length) {
                    if (empty) { empty.classList.remove('d-none'); }
                    return;
                }
                if (!list) { return; }

                list.innerHTML = rows.map(function (f) {
                    var color = f.color_hex || '#6c757d';
                    var icon  = f.icon ? '<img src="' + escapeHtml(f.icon) + '" width="14" height="14" class="me-1" style="object-fit:contain;vertical-align:middle;" alt="">' : '';
                    var typeLabel = TYPE_LABELS[f.type] || escapeHtml(f.type || '');
                    var joinBadge = parseInt(f.allow_join_requests, 10) === 1
                        ? ' <span class="badge text-bg-info" style="font-size:.65rem;">Adesioni aperte</span>'
                        : '';
                    return '<div class="list-group-item list-group-item-action px-3 py-2" style="cursor:pointer;" data-action="faction-detail" data-id="' + (parseInt(f.id, 10) || 0) + '">'
                        + '<div class="d-flex justify-content-between align-items-center">'
                        + '<span class="fw-bold" style="color:' + escapeHtml(color) + '">' + icon + escapeHtml(f.name || '') + '</span>'
                        + '<span>' + joinBadge + ' <span class="badge text-bg-secondary small">' + typeLabel + '</span></span>'
                        + '</div>'
                        + (f.description ? '<div class="text-muted small mt-1">' + escapeHtml(String(f.description).substring(0, 100)) + (String(f.description).length > 100 ? '…' : '') + '</div>' : '')
                        + '</div>';
                }).join('');
            }).catch(function (e) {
                if (loading) { loading.classList.add('d-none'); }
                console.warn('[FactionsPage] list failed', e);
            });
        },

        // ---------------------------------------------------------------
        // Faction detail modal
        // ---------------------------------------------------------------

        openDetail: function (factionId) {
            if (!factionId) { return; }
            var self = this;
            var mod  = this.resolveModuleApi();
            if (!mod || typeof mod.get !== 'function') { return; }

            mod.get({ id: factionId }).then(function (response) {
                var f = (response && response.dataset) ? response.dataset : null;
                if (!f) { return; }

                self.activeFactionId = factionId;
                self.activeFaction   = f;

                var nameEl   = document.getElementById('faction-detail-name');
                var typeEl   = document.getElementById('faction-detail-type');
                var badgesEl = document.getElementById('faction-detail-badges');
                var descEl   = document.getElementById('faction-detail-description');

                if (nameEl)   { nameEl.textContent = f.name || ''; }
                if (typeEl)   { typeEl.textContent = TYPE_LABELS[f.type] || (f.type || ''); }
                if (descEl)   { descEl.textContent = f.description || ''; }

                if (badgesEl) {
                    var scopeMap = { local: 'Locale', regional: 'Regionale', global: 'Globale' };
                    badgesEl.innerHTML = '<span class="badge text-bg-secondary me-1">' + (scopeMap[f.scope] || escapeHtml(f.scope || '')) + '</span>'
                        + '<span class="badge text-bg-secondary">Potere ' + parseInt(f.power_level, 10) + '/10</span>';
                }

                // Membership check
                var myMembership = self.myFactions.find(function (m) {
                    return parseInt(m.faction_id || m.id, 10) === factionId;
                });
                var isMember  = !!myMembership;
                var myRole    = isMember ? (myMembership.role || 'member') : '';
                var isLeader  = isMember && (myRole === 'leader' || myRole === 'advisor');

                var memEl       = document.getElementById('faction-detail-membership');
                var roleEl      = document.getElementById('faction-detail-role');
                var joinWrap    = document.getElementById('faction-join-request-wrap');
                var leaderPanel = document.getElementById('faction-leader-panel');

                if (memEl)    { memEl.classList.toggle('d-none', !isMember); }
                if (roleEl)   { roleEl.textContent = ROLE_LABELS[myRole] || myRole; }
                if (joinWrap) { joinWrap.classList.toggle('d-none', isMember || !parseInt(f.allow_join_requests, 10)); }
                if (leaderPanel) { leaderPanel.classList.toggle('d-none', !isLeader); }

                // Load members + relations
                self.loadFactionMembers(factionId, isLeader);
                self.loadFactionRelations(factionId);

                // Load pending requests if leader
                if (isLeader) {
                    self.loadPendingRequests();
                    self.loadRelationTargets();
                }

                showModal('faction-detail-modal');
            }).catch(function (e) {
                console.warn('[FactionsPage] get failed', e);
            });
        },

        loadFactionMembers: function (factionId, isLeader) {
            var self = this;
            var loading = document.getElementById('faction-members-loading');
            var empty   = document.getElementById('faction-members-empty');
            var list    = document.getElementById('faction-members-list');
            if (!list) { return; }
            if (loading) { loading.classList.remove('d-none'); }
            if (empty)   { empty.classList.add('d-none'); }
            list.innerHTML = '';

            var mod = this.resolveModuleApi();
            if (!mod || typeof mod.getFactionMembers !== 'function') {
                if (loading) { loading.classList.add('d-none'); }
                return;
            }

            mod.getFactionMembers({ faction_id: factionId }).then(function (response) {
                if (loading) { loading.classList.add('d-none'); }
                var rows = (response && response.dataset) ? response.dataset : [];
                if (!Array.isArray(rows) || !rows.length) {
                    if (empty) { empty.classList.remove('d-none'); }
                    return;
                }
                list.innerHTML = rows.map(function (m) {
                    var roleLabel = ROLE_LABELS[m.role] || escapeHtml(m.role || 'member');
                    var expelBtn  = isLeader && m.role !== 'leader'
                        ? ' <button type="button" class="btn btn-sm btn-outline-danger btn-xs" data-action="faction-leader-expel" data-character-id="' + parseInt(m.character_id || m.id, 10) + '">Espelli</button>'
                        : '';
                    return '<div class="d-flex justify-content-between align-items-center py-1 border-bottom">'
                        + '<div>'
                        + '<span class="small fw-bold">' + escapeHtml(m.character_name || '') + '</span>'
                        + ' <span class="badge text-bg-secondary">' + roleLabel + '</span>'
                        + (m.rank ? ' <span class="small text-muted">' + escapeHtml(m.rank) + '</span>' : '')
                        + '</div>'
                        + expelBtn
                        + '</div>';
                }).join('');
            }).catch(function () {
                if (loading) { loading.classList.add('d-none'); }
            });
        },

        loadFactionRelations: function (factionId) {
            var self = this;
            var loading = document.getElementById('faction-relations-loading');
            var empty   = document.getElementById('faction-relations-empty');
            var list    = document.getElementById('faction-relations-list');
            if (!list) { return; }
            if (loading) { loading.classList.remove('d-none'); }
            if (empty)   { empty.classList.add('d-none'); }
            list.innerHTML = '';

            var mod = this.resolveModuleApi();
            if (!mod || typeof mod.getFactionRelations !== 'function') {
                if (loading) { loading.classList.add('d-none'); }
                return;
            }

            mod.getFactionRelations({ faction_id: factionId }).then(function (response) {
                if (loading) { loading.classList.add('d-none'); }
                var rows = (response && response.dataset) ? response.dataset : [];
                if (!Array.isArray(rows) || !rows.length) {
                    if (empty) { empty.classList.remove('d-none'); }
                    return;
                }
                list.innerHTML = rows.map(function (r) {
                    var rel = r.relation_type || 'neutral';
                    var relLabel = REL_LABELS[rel] || escapeHtml(rel);
                    return '<div class="d-flex justify-content-between align-items-center py-1 border-bottom">'
                        + '<span class="small">' + escapeHtml(r.target_name || r.target_code || '') + '</span>'
                        + '<span class="badge ' + (REL_COLORS[rel] || 'text-bg-secondary') + '">' + relLabel + '</span>'
                        + '</div>';
                }).join('');
            }).catch(function () {
                if (loading) { loading.classList.add('d-none'); }
            });
        },

        // ---------------------------------------------------------------
        // Leave faction
        // ---------------------------------------------------------------

        confirmLeave: function () {
            var self = this;
            if (!this.activeFactionId) { return; }
            var factionName = this.activeFaction ? (this.activeFaction.name || '') : '';

            if (window.Dialog && typeof window.Dialog === 'function') {
                Dialog('danger', {
                    title: 'Abbandona fazione',
                    body: '<p>Sei sicuro di voler abbandonare <b>' + escapeHtml(factionName) + '</b>?</p>'
                }, function () {
                    self._hideConfirm();
                    self.doLeaveFaction();
                }).show();
            } else if (window.confirm('Abbandonare la fazione ' + factionName + '?')) {
                self.doLeaveFaction();
            }
        },

        doLeaveFaction: function () {
            var self = this;
            var mod  = this.resolveModuleApi();
            if (!mod || typeof mod.leaveFaction !== 'function') { return; }

            mod.leaveFaction({ faction_id: this.activeFactionId }).then(function () {
                toast('Hai abbandonato la fazione.', 'success');
                hideModal('faction-detail-modal');
                self.loadMyFactions();
                self.loadPublicFactions();
            }).catch(function (e) {
                toast(self._errorMsg(e), 'error');
            });
        },

        // ---------------------------------------------------------------
        // Join request (player)
        // ---------------------------------------------------------------

        openJoinRequestModal: function () {
            if (!this.activeFaction) { return; }
            var nameEl = document.getElementById('join-request-faction-name');
            var msgEl  = document.getElementById('join-request-message');
            if (nameEl) { nameEl.textContent = this.activeFaction.name || ''; }
            if (msgEl)  { msgEl.value = ''; }
            showModal('faction-join-request-modal');
        },

        submitJoinRequest: function () {
            var self = this;
            var mod  = this.resolveModuleApi();
            if (!mod || typeof mod.sendJoinRequest !== 'function') { return; }
            var msgEl = document.getElementById('join-request-message');
            var message = msgEl ? String(msgEl.value || '').trim() : '';

            mod.sendJoinRequest({ faction_id: this.activeFactionId, message: message }).then(function () {
                toast('Richiesta di adesione inviata!', 'success');
                hideModal('faction-join-request-modal');
                self.loadMyJoinRequests();
            }).catch(function (e) {
                toast(self._errorMsg(e), 'error');
            });
        },

        withdrawRequest: function (requestId) {
            var self = this;
            var mod  = this.resolveModuleApi();
            if (!mod || typeof mod.withdrawJoinRequest !== 'function' || !requestId) { return; }

            mod.withdrawJoinRequest({ request_id: requestId }).then(function () {
                toast('Richiesta ritirata.', 'success');
                self.loadMyJoinRequests();
            }).catch(function (e) {
                toast(self._errorMsg(e), 'error');
            });
        },

        // ---------------------------------------------------------------
        // Leader — pending requests
        // ---------------------------------------------------------------

        loadPendingRequests: function () {
            var self = this;
            var loadingEl = document.getElementById('faction-pending-requests-loading');
            var emptyEl   = document.getElementById('faction-pending-requests-empty');
            var listEl    = document.getElementById('faction-pending-requests-list');
            if (!listEl) { return; }

            if (loadingEl) { loadingEl.classList.remove('d-none'); }
            if (emptyEl)   { emptyEl.classList.add('d-none'); }
            listEl.innerHTML = '';

            var mod = this.resolveModuleApi();
            if (!mod || typeof mod.leaderListRequests !== 'function') {
                if (loadingEl) { loadingEl.classList.add('d-none'); }
                return;
            }

            mod.leaderListRequests({ faction_id: this.activeFactionId }).then(function (response) {
                if (loadingEl) { loadingEl.classList.add('d-none'); }
                var rows = (response && response.dataset) ? response.dataset : [];
                var countEl = document.getElementById('faction-pending-count');
                if (countEl) { countEl.textContent = String(Array.isArray(rows) ? rows.length : 0); }

                if (!Array.isArray(rows) || !rows.length) {
                    if (emptyEl) { emptyEl.classList.remove('d-none'); }
                    return;
                }
                listEl.innerHTML = rows.map(function (r) {
                    return '<div class="border-bottom pb-2 mb-2">'
                        + '<div class="small fw-bold">' + escapeHtml(r.character_name || '#' + r.character_id) + '</div>'
                        + (r.message ? '<div class="small text-muted mb-1">' + escapeHtml(String(r.message).substring(0, 120)) + '</div>' : '')
                        + '<div class="d-flex gap-1">'
                        + '<button type="button" class="btn btn-sm btn-success" data-action="faction-pending-approve" data-request-id="' + parseInt(r.id, 10) + '">Approva</button>'
                        + '<button type="button" class="btn btn-sm btn-outline-danger" data-action="faction-pending-reject" data-request-id="' + parseInt(r.id, 10) + '">Rifiuta</button>'
                        + '</div>'
                        + '</div>';
                }).join('');
            }).catch(function () {
                if (loadingEl) { loadingEl.classList.add('d-none'); }
            });
        },

        reviewRequest: function (requestId, decision) {
            var self = this;
            var mod  = this.resolveModuleApi();
            if (!mod || typeof mod.leaderReviewRequest !== 'function' || !requestId) { return; }

            mod.leaderReviewRequest({ request_id: requestId, decision: decision }).then(function () {
                var msg = decision === 'approved' ? 'Richiesta approvata.' : 'Richiesta rifiutata.';
                toast(msg, 'success');
                self.loadPendingRequests();
                if (decision === 'approved') { self.loadFactionMembers(self.activeFactionId, true); }
            }).catch(function (e) {
                toast(self._errorMsg(e), 'error');
            });
        },

        // ---------------------------------------------------------------
        // Leader — invite member
        // ---------------------------------------------------------------

        handleInviteSearch: function () {
            var self  = this;
            var input = document.getElementById('faction-invite-name');
            var idEl  = document.getElementById('faction-invite-character-id');
            var sugEl = document.getElementById('faction-invite-suggestions');
            if (!input) { return; }

            var query = String(input.value || '').trim();
            if (idEl) { idEl.value = ''; }

            if (this.inviteSearchTimer) { clearTimeout(this.inviteSearchTimer); }
            if (query.length < 2) {
                if (sugEl) { sugEl.classList.add('d-none'); sugEl.innerHTML = ''; }
                return;
            }

            this.inviteSearchTimer = setTimeout(function () {
                var mod = self.resolveModuleApi();
                if (!mod || !mod.ctx || !mod.ctx.services || !mod.ctx.services.http) { return; }
                mod.ctx.services.http.request({ url: '/list/characters/search', action: 'searchCharacters', payload: { query: query } })
                    .then(function (r) {
                        var rows = (r && r.dataset) ? r.dataset : [];
                        self._renderInviteSuggestions(Array.isArray(rows) ? rows : []);
                    }).catch(function () {});
            }, 180);
        },

        _renderInviteSuggestions: function (rows) {
            var sugEl = document.getElementById('faction-invite-suggestions');
            if (!sugEl) { return; }
            sugEl.innerHTML = '';
            if (!rows.length) { sugEl.classList.add('d-none'); return; }
            rows.forEach(function (row) {
                var id    = parseInt(row.id || '0', 10) || 0;
                var label = (String(row.name || '') + ' ' + String(row.surname || '')).trim() || ('PG #' + id);
                var btn   = document.createElement('button');
                btn.type      = 'button';
                btn.className = 'list-group-item list-group-item-action small py-1';
                btn.setAttribute('data-role', 'faction-invite-suggestion');
                btn.setAttribute('data-character-id', String(id));
                btn.setAttribute('data-character-label', label);
                btn.textContent = label;
                sugEl.appendChild(btn);
            });
            sugEl.classList.remove('d-none');
        },

        selectInviteSuggestion: function (node) {
            var id    = parseInt(node.getAttribute('data-character-id') || '0', 10) || 0;
            var label = String(node.getAttribute('data-character-label') || '').trim();
            var idEl  = document.getElementById('faction-invite-character-id');
            var nameEl= document.getElementById('faction-invite-name');
            var sugEl = document.getElementById('faction-invite-suggestions');
            if (id <= 0) { return; }
            if (idEl)   { idEl.value   = String(id); }
            if (nameEl) { nameEl.value = label; }
            if (sugEl)  { sugEl.classList.add('d-none'); sugEl.innerHTML = ''; }
        },

        leaderInvite: function () {
            var self  = this;
            var idEl  = document.getElementById('faction-invite-character-id');
            var nameEl= document.getElementById('faction-invite-name');
            var characterId = idEl ? (parseInt(idEl.value || '0', 10) || 0) : 0;

            if (!characterId) {
                toast('Seleziona un personaggio dalla lista.', 'warning');
                return;
            }

            var mod = this.resolveModuleApi();
            if (!mod || typeof mod.leaderInvite !== 'function') { return; }

            mod.leaderInvite({ faction_id: this.activeFactionId, target_character_id: characterId }).then(function () {
                toast('Personaggio invitato nella fazione.', 'success');
                if (idEl)   { idEl.value   = ''; }
                if (nameEl) { nameEl.value = ''; }
                self.loadFactionMembers(self.activeFactionId, true);
            }).catch(function (e) {
                toast(self._errorMsg(e), 'error');
            });
        },

        leaderExpel: function (characterId) {
            var self = this;
            if (!characterId || !this.activeFactionId) { return; }
            var mod = this.resolveModuleApi();
            if (!mod || typeof mod.leaderExpel !== 'function') { return; }

            mod.leaderExpel({ faction_id: this.activeFactionId, target_character_id: characterId }).then(function () {
                toast('Membro espulso.', 'success');
                self.loadFactionMembers(self.activeFactionId, true);
            }).catch(function (e) {
                toast(self._errorMsg(e), 'error');
            });
        },

        // ---------------------------------------------------------------
        // Leader — propose relation
        // ---------------------------------------------------------------

        loadRelationTargets: function () {
            var self   = this;
            var select = document.getElementById('faction-relation-target');
            if (!select) { return; }

            var mod = this.resolveModuleApi();
            if (!mod || typeof mod.list !== 'function') { return; }

            mod.list({ limit: 100, page: 1 }).then(function (response) {
                var rows = (response && response.dataset && response.dataset.rows) ? response.dataset.rows
                         : (response && Array.isArray(response.dataset)) ? response.dataset : [];
                select.innerHTML = '<option value="">Seleziona fazione…</option>';
                rows.forEach(function (f) {
                    if (parseInt(f.id, 10) === self.activeFactionId) { return; }
                    var opt = document.createElement('option');
                    opt.value = f.id;
                    opt.textContent = f.name + ' (' + f.code + ')';
                    select.appendChild(opt);
                });
            }).catch(function () {});
        },

        leaderProposeRelation: function () {
            var self       = this;
            var targetEl   = document.getElementById('faction-relation-target');
            var typeEl     = document.getElementById('faction-relation-type');
            var targetId   = targetEl ? (parseInt(targetEl.value || '0', 10) || 0) : 0;
            var relType    = typeEl ? String(typeEl.value || 'neutral').trim() : 'neutral';

            if (!targetId) {
                toast('Seleziona una fazione target.', 'warning');
                return;
            }

            var mod = this.resolveModuleApi();
            if (!mod || typeof mod.leaderProposeRelation !== 'function') { return; }

            mod.leaderProposeRelation({ faction_id: this.activeFactionId, target_faction_id: targetId, relation_type: relType }).then(function () {
                toast('Relazione proposta registrata.', 'success');
                self.loadFactionRelations(self.activeFactionId);
            }).catch(function (e) {
                toast(self._errorMsg(e), 'error');
            });
        },

        // ---------------------------------------------------------------
        // Helpers
        // ---------------------------------------------------------------

        _show: function (id) {
            var el = document.getElementById(id);
            if (el) { el.classList.remove('d-none'); }
        },

        _hide: function (id) {
            var el = document.getElementById(id);
            if (el) { el.classList.add('d-none'); }
        },

        _hideConfirm: function () {
            if (window.SystemDialogs && typeof window.SystemDialogs.ensureGeneralConfirm === 'function') {
                var d = window.SystemDialogs.ensureGeneralConfirm();
                if (d && typeof d.hide === 'function') { d.hide(); }
            } else if (window.generalConfirm && typeof window.generalConfirm.hide === 'function') {
                window.generalConfirm.hide();
            }
        },

        _errorMsg: function (error) {
            if (window.Request && typeof window.Request.getErrorMessage === 'function') {
                return window.Request.getErrorMessage(error, 'Operazione non riuscita.');
            }
            if (error && typeof error.message === 'string' && error.message.trim()) { return error.message.trim(); }
            return 'Operazione non riuscita.';
        },

        destroy: function () { return this; },
        unmount: function () { return this.destroy(); }
    };

    var instance = Object.assign({}, page, extension || {});
    return instance.init();
}

window.GameFactionsPage = GameFactionsPage;
