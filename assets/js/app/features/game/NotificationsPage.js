const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

var $ = typeof jQuery !== 'undefined' ? jQuery : null;
var NotificationsPage = {
    initialized: false,
    currentTab: 'all',
    pollKey: null,
    _pollInterval: null,
    pollIntervalMs: 30000,
    unreadCount: 0,
    pendingCount: 0,
    tabControl: null,
    loadingList: false,

    init: function () {
        if (this.initialized) return this;
        this.bind();
        this.startPoll();
        this.initialized = true;
        return this;
    },

    destroy: function () {
        this.stopPoll();
        this.initialized = false;
    },

    bind: function () {
        var self = this;

        $('#offcanvasNotifications').off('show.bs.offcanvas.notifications')
            .on('show.bs.offcanvas.notifications', function () {
                self.load();
            });
        this.initTabControl();

        $(document).off('click.notifications-action', '[data-action="notifications-read-all"]')
            .on('click.notifications-action', '[data-action="notifications-read-all"]', function () {
                self.readAll();
            });

        $(document).off('click.notifications-row', '#notifications-list [data-action]')
            .on('click.notifications-row', '#notifications-list [data-action]', function (e) {
                var el = $(this);
                var action = el.data('action');
                var notifId = parseInt(el.data('notification-id'), 10) || 0;
                if (action === 'notification-read') {
                    self.markRead(notifId);
                } else if (action === 'notification-read-delete') {
                    self.readAndDelete(notifId);
                } else if (action === 'notification-delete') {
                    self.deleteNotification(notifId);
                } else if (action === 'notification-open') {
                    self.openNotification(el);
                } else if (action === 'notification-accept') {
                    self.respond(notifId, 'accepted');
                } else if (action === 'notification-reject') {
                    self.respond(notifId, 'rejected');
                }
            });
    },

    initTabControl: function () {
        var self = this;
        var input = $('#notifications-tab-filter');
        if (!input.length) {
            return;
        }

        if (typeof globalWindow.RadioGroup === 'function') {
            input.val(this.currentTab);
            this.tabControl = globalWindow.RadioGroup('#notifications-tab-filter', {
                groupClass: 'btn-group-sm',
                options: this.buildTabOptions(this.unreadCount, this.pendingCount)
            });
        } else {
            input.val(this.currentTab);
        }

        input.off('change.notifications-tab-filter').on('change.notifications-tab-filter', function () {
            var tab = String(input.val() || 'all');
            if (tab !== 'pending' && tab !== 'all') {
                tab = 'all';
            }
            self.currentTab = tab;
            self.load();
        });
    },

    buildTabOptions: function (unread, pending) {
        var pendingLabel = 'Da confermare';
        var allLabel = 'Tutte';

        if (pending > 0) {
            pendingLabel += ' (' + String(pending > 99 ? '99+' : pending) + ')';
        }
        if (unread > 0) {
            allLabel += ' (' + String(unread > 99 ? '99+' : unread) + ')';
        }

        return [
            { value: 'all', label: allLabel, style: 'outline-primary' },
            { value: 'pending', label: pendingLabel, style: 'outline-primary' }
        ];
    },

    refreshTabControlOptions: function (unread, pending) {
        if (!this.tabControl || typeof this.tabControl.setOptions !== 'function') {
            return;
        }
        var current = this.currentTab || 'pending';
        this.tabControl.setOptions(this.buildTabOptions(unread, pending));
        if (this.tabControl.input && this.tabControl.input.length) {
            this.tabControl.input.val(current);
        }
        if (typeof this.tabControl.refresh === 'function') {
            this.tabControl.refresh();
        }
    },

    getCsrfToken: function () {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? String(meta.getAttribute('content') || '').trim() : '';
    },

    startPoll: function () {
        var self = this;
        var manager = globalWindow.AppLifecycle && typeof globalWindow.AppLifecycle.getPollManager === 'function'
            ? globalWindow.AppLifecycle.getPollManager()
            : null;

        this.pollKey = 'game.notifications.poll';

        if (manager && typeof manager.start === 'function') {
            manager.start(this.pollKey, function () {
                self.fetchCounts();
            }, this.pollIntervalMs);
        } else {
            this._pollInterval = setInterval(function () {
                self.fetchCounts();
            }, self.pollIntervalMs);
        }

        this.fetchCounts();
    },

    stopPoll: function () {
        if (this._pollInterval) {
            clearInterval(this._pollInterval);
            this._pollInterval = null;
        }
        var manager = globalWindow.AppLifecycle && typeof globalWindow.AppLifecycle.getPollManager === 'function'
            ? globalWindow.AppLifecycle.getPollManager()
            : null;
        if (!manager || typeof manager.stop !== 'function' || !this.pollKey) return;
        manager.stop(this.pollKey);
    },

    apiPost: function (url, data, successFn, errorFn) {
        var payload = (data && typeof data === 'object') ? Object.assign({}, data) : {};
        var csrfToken = this.getCsrfToken();
        if (csrfToken && !payload._csrf) {
            payload._csrf = csrfToken;
        }

        var facade = globalWindow.AppFacade && typeof globalWindow.AppFacade.http === 'function'
            ? globalWindow.AppFacade.http()
            : null;
        if (facade && typeof facade.post === 'function') {
            facade.post(url, payload).then(successFn).catch(errorFn || function () {});
            return;
        }
        if (typeof $ !== 'undefined' && $.ajax) {
            var headers = { 'X-Requested-With': 'XMLHttpRequest' };
            if (csrfToken) {
                headers['X-CSRF-Token'] = csrfToken;
            }
            $.ajax({
                url: url,
                method: 'POST',
                dataType: 'json',
                headers: headers,
                processData: true,
                contentType: 'application/x-www-form-urlencoded; charset=UTF-8',
                data: 'data=' + encodeURIComponent(JSON.stringify(payload)),
                success: successFn,
                error: errorFn || function () {}
            });
        }
    },

    fetchCounts: function () {
        var self = this;
        this.apiPost('/notifications/unread-count', {}, function (res) {
            var count = parseInt((res && res.unread_count) || 0, 10);
            self.unreadCount = count;
            self.updateNavBadge(count);
            self.updateTabBadges(self.unreadCount, self.pendingCount);
        }, function (err) {
            console.warn('[NotificationsPage] fetchCounts failed', err);
        });
    },

    updateNavBadge: function (count) {
        var label = count > 99 ? '99+' : String(count);
        var show = count > 0;
        var badges = document.querySelectorAll('[data-feed-badge="notifications"]');
        for (var i = 0; i < badges.length; i++) {
            badges[i].textContent = label;
            badges[i].classList.toggle('d-none', !show);
            badges[i].classList.toggle('feed-badge-pulse', show);
        }
        if (show && globalWindow.AppSounds && typeof globalWindow.AppSounds.play === 'function') {
            globalWindow.AppSounds.play('notifications');
        }
    },

    load: function () {
        var self = this;
        if (this.loadingList === true) {
            return;
        }
        this.loadingList = true;
        var list = $('#notifications-list');
        var empty = $('#notifications-empty');
        var loading = $('#notifications-loading');

        empty.addClass('d-none');
        loading.removeClass('d-none');
        list.find('.notification-item').remove();

        var filters = { page: 1, results: 50 };
        if (this.currentTab === 'pending') {
            filters.pending_only = 1;
        }

        this.apiPost('/notifications/list', filters, function (res) {
            self.loadingList = false;
            loading.addClass('d-none');
            if (!res || !res.dataset) return;

            var meta = res.dataset.meta || {};
            var rows = res.dataset.rows || [];

            self.unreadCount = parseInt(meta.unread_count || 0, 10);
            self.pendingCount = parseInt(meta.pending_count || 0, 10);
            self.updateNavBadge(self.unreadCount);
            self.updateTabBadges(self.unreadCount, self.pendingCount);

            if (!rows.length) {
                empty.removeClass('d-none').text('Nessuna notifica.');
                return;
            }

            var html = '';
            for (var i = 0; i < rows.length; i++) {
                html += self.renderRow(rows[i]);
            }
            list.append(html);
        }, function () {
            self.loadingList = false;
            loading.addClass('d-none');
            empty.removeClass('d-none').text('Errore nel caricamento delle notifiche.');
        });
    },

    renderRow: function (row) {
        var id = parseInt(row.id, 10) || 0;
        var isRead = parseInt(row.is_read, 10) === 1;
        var isPending = (row.action_status === 'pending');
        var kind = String(row.kind || '');
        var topic = String(row.topic || '');
        var title = this.escapeHtml(String(row.title || ''));
        var message = row.message ? this.escapeHtml(String(row.message)) : '';
        var date = this.formatDate(String(row.date_created || ''));
        var actionUrl = String(row.action_url || '');
        var bgClass = isRead ? '' : 'bg-light';

        var kindIcon = kind === 'action_required' ? '<i class="bi bi-question-circle-fill text-warning me-2"></i>'
            : kind === 'decision_result' ? '<i class="bi bi-check-circle-fill text-success me-2"></i>'
            : '<i class="bi bi-info-circle-fill text-secondary me-2"></i>';

        var actions = '';
        if (isPending && kind === 'action_required') {
            actions = '<div class="d-flex gap-1 mt-2">'
                + '<button type="button" class="btn btn-sm btn-outline-success" data-action="notification-accept" data-notification-id="' + id + '">Accetta</button>'
                + '<button type="button" class="btn btn-sm btn-outline-danger" data-action="notification-reject" data-notification-id="' + id + '">Rifiuta</button>'
                + '</div>';
        }

        var sideActions = '';
        if (!isPending) {
            if (topic === 'direct_message' || topic === 'news_publish' || actionUrl !== '') {
                sideActions += '<button type="button" class="btn btn-sm btn-link text-muted p-0 me-2"'
                    + ' data-action="notification-open"'
                    + ' data-notification-id="' + id + '"'
                    + ' data-notification-topic="' + this.escapeHtml(topic) + '"'
                    + ' data-notification-url="' + this.escapeHtml(actionUrl) + '"'
                    + ' title="Apri"><i class="bi bi-box-arrow-up-right"></i></button>';
            }
            if (!isRead) {
                sideActions += '<button type="button" class="btn btn-sm btn-link text-muted p-0 me-2" data-action="notification-read-delete" data-notification-id="' + id + '" title="Leggi e rimuovi"><i class="bi bi-check2"></i></button>';
            }
            sideActions += '<button type="button" class="btn btn-sm btn-link text-muted p-0" data-action="notification-delete" data-notification-id="' + id + '" title="Elimina"><i class="bi bi-trash"></i></button>';
        } else if (!isRead) {
            sideActions = '<button type="button" class="btn btn-sm btn-link text-muted p-0" data-action="notification-read" data-notification-id="' + id + '" title="Segna come letto"><i class="bi bi-check"></i></button>';
        }

        return '<div class="notification-item border-bottom px-3 py-2 ' + bgClass + '" data-notification-id="' + id + '">'
            + '<div class="d-flex justify-content-between align-items-start">'
            + '<div class="flex-grow-1">'
            + '<div class="small fw-semibold">' + kindIcon + title + '</div>'
            + (message ? '<div class="small text-muted mt-1">' + message + '</div>' : '')
            + '<div class="small text-muted mt-1">' + date + '</div>'
            + actions
            + '</div>'
            + sideActions
            + '</div>'
            + '</div>';
    },

    openNotification: function (trigger) {
        if (!trigger || !trigger.length) {
            return;
        }

        var topic = String(trigger.data('notification-topic') || '');
        var actionUrl = String(trigger.data('notification-url') || '');

        if (topic === 'news_publish') {
            var newsLauncher = document.querySelector('[data-action="open-news"]');
            if (newsLauncher && typeof newsLauncher.click === 'function') {
                newsLauncher.click();
                return;
            }
            if (typeof window.newsModal !== 'undefined' && window.newsModal && typeof window.newsModal.show === 'function') {
                window.newsModal.show();
                return;
            }
        }

        if (actionUrl !== '') {
            window.location.href = actionUrl;
        }
    },

    updateTabBadges: function (unread, pending) {
        var unreadBadge = $('#notifications-unread-badge');
        var pendingBadge = $('#notifications-pending-badge');

        if (unread > 0) {
            unreadBadge.text(unread > 99 ? '99+' : String(unread))
                .removeClass('d-none')
                .addClass('feed-badge-pulse');
        } else {
            unreadBadge.addClass('d-none').removeClass('feed-badge-pulse').text('0');
        }
        if (pending > 0) {
            pendingBadge.text(pending > 99 ? '99+' : String(pending))
                .removeClass('d-none')
                .addClass('feed-badge-pulse');
        } else {
            pendingBadge.addClass('d-none').removeClass('feed-badge-pulse').text('0');
        }

        this.refreshTabControlOptions(unread, pending);
    },

    markRead: function (notificationId) {
        var self = this;
        if (!notificationId) return;
        this.apiPost('/notifications/read', { notification_id: notificationId }, function () {
            var item = $('#notifications-list [data-notification-id="' + notificationId + '"]');
            item.removeClass('bg-light');
            item.find('[data-action="notification-read"]').remove();
            self.unreadCount = Math.max(0, self.unreadCount - 1);
            self.updateNavBadge(self.unreadCount);
        });
    },

    readAndDelete: function (notificationId) {
        var self = this;
        if (!notificationId) return;
        this.apiPost('/notifications/read-delete', { notification_id: notificationId }, function (res) {
            self.removeNotificationRow(notificationId, res && res.dataset ? res.dataset : null);
        }, function (err) {
            self.handleActionError(err, 'Impossibile leggere e rimuovere la notifica.');
        });
    },

    deleteNotification: function (notificationId) {
        var self = this;
        if (!notificationId) return;
        this.apiPost('/notifications/delete', { notification_id: notificationId }, function (res) {
            self.removeNotificationRow(notificationId, res && res.dataset ? res.dataset : null);
        }, function (err) {
            self.handleActionError(err, 'Impossibile eliminare la notifica.');
        });
    },

    removeNotificationRow: function (notificationId, payload) {
        var item = $('#notifications-list [data-notification-id="' + notificationId + '"]');
        var wasUnread = parseInt((payload && payload.was_unread) || 0, 10) === 1;

        if (wasUnread) {
            this.unreadCount = Math.max(0, this.unreadCount - 1);
        }
        this.updateNavBadge(this.unreadCount);
        this.updateTabBadges(this.unreadCount, this.pendingCount);

        item.fadeOut(160, function () {
            $(this).remove();
            if (!$('#notifications-list .notification-item').length) {
                $('#notifications-empty').removeClass('d-none').text('Nessuna notifica.');
            }
        });
    },

    handleActionError: function (err, fallbackMessage) {
        var msg = (err && err.responseJSON && (err.responseJSON.error || err.responseJSON.message))
            || fallbackMessage
            || 'Operazione non riuscita.';
        if (globalWindow.AppFacade && typeof globalWindow.AppFacade.toast === 'function') {
            globalWindow.AppFacade.toast(msg, 'error');
        }
    },

    readAll: function () {
        var self = this;
        this.apiPost('/notifications/read-all', {}, function () {
            self.unreadCount = 0;
            self.updateNavBadge(0);
            $('#notifications-unread-badge').addClass('d-none').removeClass('feed-badge-pulse').text('0');
            self.load();
        });
    },

    respond: function (notificationId, decision) {
        var self = this;
        if (!notificationId) return;
        this.apiPost('/notifications/respond', { notification_id: notificationId, decision: decision }, function (res) {
            var item = $('#notifications-list [data-notification-id="' + notificationId + '"]');
            var wasUnread = item.hasClass('bg-light');
            item.find('[data-action="notification-accept"], [data-action="notification-reject"]').remove();
            item.removeClass('bg-light');
            var label = decision === 'accepted' ? '<span class="badge text-bg-success">Accettato</span>' : '<span class="badge text-bg-secondary">Rifiutato</span>';
            item.find('.d-flex.gap-1').replaceWith('<div class="mt-2">' + label + '</div>');
            if (wasUnread) {
                self.unreadCount = Math.max(0, self.unreadCount - 1);
                self.updateNavBadge(self.unreadCount);
            }
            self.pendingCount = Math.max(0, self.pendingCount - 1);
            self.updateTabBadges(self.unreadCount, self.pendingCount);
            if (self.currentTab === 'pending') {
                // rimuovi dalla lista se non ci sono piu azioni pending
                setTimeout(function () { item.fadeOut(300, function () { $(this).remove(); }); }, 800);
            }
        }, function (err) {
            var msg = (err && err.responseJSON && err.responseJSON.error) || 'Errore nella risposta.';
            if (typeof globalWindow.AppFacade !== 'undefined' && globalWindow.AppFacade.toast) {
                globalWindow.AppFacade.toast(msg, 'error');
            }
        });
    },

    escapeHtml: function (str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    },

    formatDate: function (dateStr) {
        if (!dateStr) return '';
        try {
            var d = new Date(dateStr.replace(' ', 'T'));
            if (isNaN(d.getTime())) return dateStr;
            return d.toLocaleDateString('it-IT', { day: '2-digit', month: '2-digit', year: 'numeric' })
                + ' ' + d.toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' });
        } catch (e) {
            return dateStr;
        }
    }
};

globalWindow.NotificationsPage = NotificationsPage;
export { NotificationsPage as NotificationsPage };
export default NotificationsPage;

