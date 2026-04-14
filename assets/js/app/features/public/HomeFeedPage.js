(function () {
    'use strict';

    var HomeFeedPage = {
        initialized: false,
        modalNode: null,
        modalTitle: null,
        modalSubtitle: null,
        modalBody: null,
        modal: null,

        init: function () {
            if (this.initialized) {
                return this;
            }

            this.modalNode = document.getElementById('home-feed-detail-modal');
            this.modalTitle = document.getElementById('home-feed-detail-title');
            this.modalSubtitle = document.getElementById('home-feed-detail-subtitle');
            this.modalBody = document.getElementById('home-feed-detail-body');

            if (!this.modalNode || !this.modalTitle || !this.modalBody || typeof window.bootstrap === 'undefined' || !window.bootstrap || !window.bootstrap.Modal) {
                return this;
            }

            this.modal = new window.bootstrap.Modal(this.modalNode);
            this.bindEvents();
            this.initialized = true;
            return this;
        },

        bindEvents: function () {
            var self = this;

            document.addEventListener('click', function (event) {
                var trigger = event.target && event.target.closest ? event.target.closest('[data-action="home-feed-open-detail"]') : null;
                if (!trigger) {
                    return;
                }
                event.preventDefault();
                self.openFromTrigger(trigger);
            });

        },

        openFromTrigger: function (trigger) {
            var feedType = String(trigger.getAttribute('data-feed-type') || '').trim();
            var feedId = String(trigger.getAttribute('data-feed-id') || '').trim();
            var title = String(trigger.getAttribute('data-feed-title') || 'Dettaglio').trim();

            if (!feedType || !feedId) {
                return;
            }

            var templateId = '';
            if (feedType === 'news') {
                templateId = 'home-feed-template-news-' + feedId;
            } else if (feedType === 'system-event') {
                templateId = 'home-feed-template-system-event-' + feedId;
            } else {
                return;
            }

            var template = document.getElementById(templateId);
            if (!template) {
                this.modalTitle.textContent = title || 'Dettaglio';
                if (this.modalSubtitle) {
                    this.modalSubtitle.textContent = 'Nessun contenuto disponibile';
                }
                this.modalBody.innerHTML = '<p class="small text-muted mb-0">Il dettaglio non e disponibile.</p>';
                this.modal.show();
                return;
            }

            this.modalTitle.textContent = title || 'Dettaglio';
            if (this.modalSubtitle) {
                this.modalSubtitle.innerHTML = (feedType === 'news')
                    ? 'Dettaglio news'
                    : 'Dettaglio evento di sistema';
            }

            var html = String(template.innerHTML || '').trim();
            this.modalBody.innerHTML = html !== '' ? html : '<p class="small text-muted mb-0">Il dettaglio non e disponibile.</p>';

            var images = this.modalBody.querySelectorAll('img');
            for (var i = 0; i < images.length; i += 1) {
                var img = images[i];
                if (!img.classList.contains('img-fluid')) {
                    img.classList.add('img-fluid');
                }
                if (!img.classList.contains('rounded')) {
                    img.classList.add('rounded');
                }
            }

            this.modal.show();
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            HomeFeedPage.init();
        });
    } else {
        HomeFeedPage.init();
    }
})();
