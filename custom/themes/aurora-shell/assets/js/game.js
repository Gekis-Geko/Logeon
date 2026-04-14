(() => {
    'use strict';

    const DEFAULT_AVATAR = '/assets/imgs/defaults-images/default-profile.png';

    function onReady(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback);
            return;
        }
        callback();
    }

    function toNumber(value, fallback) {
        const parsed = Number.parseFloat(value);
        return Number.isFinite(parsed) ? parsed : fallback;
    }

    function clamp(value, min, max) {
        return Math.max(min, Math.min(max, value));
    }

    function formatNumber(value, decimals) {
        if (typeof window.Utils === 'function') {
            return window.Utils().formatNumber(value, decimals || 0);
        }
        const precision = Number.isFinite(decimals) ? decimals : 0;
        return Number(value).toFixed(precision);
    }

    function getSessionCharacterId() {
        const pageRoot = document.querySelector('#page-content');
        if (!pageRoot) {
            return 0;
        }
        const id = Number.parseInt(pageRoot.getAttribute('data-session-character-id') || '0', 10);
        return Number.isFinite(id) && id > 0 ? id : 0;
    }

    function resolveProfileModule() {
        if (!window.RuntimeBootstrap || typeof window.RuntimeBootstrap.resolveAppModule !== 'function') {
            return null;
        }
        try {
            return window.RuntimeBootstrap.resolveAppModule('game.profile');
        } catch (error) {
            return null;
        }
    }

    function requestProfile(characterId) {
        return new Promise((resolve, reject) => {
            const profileModule = resolveProfileModule();
            if (profileModule && typeof profileModule.getProfile === 'function') {
                profileModule.getProfile(characterId).then(resolve).catch(reject);
                return;
            }

            if (window.Request && window.Request.http && typeof window.Request.http.request === 'function') {
                window.Request.http.request({
                    url: '/get/profile',
                    method: 'POST',
                    data: { id: characterId },
                }).then(resolve).catch(reject);
                return;
            }

            reject(new Error('Servizio profilo non disponibile.'));
        });
    }

    function renderCharacterWidget(widget, dataset) {
        if (!widget || !dataset) {
            return;
        }

        const avatarNode = widget.querySelector('[data-aurora-character-avatar]');
        const nameNode = widget.querySelector('[data-aurora-character-name]');
        const subtitleNode = widget.querySelector('[data-aurora-character-subtitle]');
        const healthLabelNode = widget.querySelector('[data-aurora-character-health-label]');
        const healthBarNode = widget.querySelector('[data-aurora-character-health-bar]');
        const expLabelNode = widget.querySelector('[data-aurora-character-exp-label]');
        const expBarNode = widget.querySelector('[data-aurora-character-exp-bar]');
        const expTotalNode = widget.querySelector('[data-aurora-character-exp-total]');

        const name = [dataset.name || '', dataset.surname || ''].join(' ').trim() || 'Personaggio';
        const subtitleParts = [];
        const rank = Number.parseInt(dataset.rank || '0', 10);
        if (Number.isFinite(rank) && rank > 0) {
            subtitleParts.push('Rango ' + rank);
        }
        if (dataset.job_name) {
            subtitleParts.push(String(dataset.job_name));
        }

        const health = Math.max(0, toNumber(dataset.health, 0));
        const healthMax = Math.max(1, toNumber(dataset.health_max || dataset.hp_max || dataset.max_health, 100));
        const healthPercent = clamp(Math.round((health / healthMax) * 100), 0, 100);

        const totalExperience = Math.max(0, toNumber(dataset.experience, 0));
        const threshold = toNumber(dataset.threshold_next_level, NaN);
        let expCurrent = 0;
        let expMax = 100;
        if (Number.isFinite(threshold) && threshold > 0) {
            expCurrent = clamp(totalExperience, 0, threshold);
            expMax = threshold;
        } else {
            expCurrent = ((totalExperience % 100) + 100) % 100;
        }
        const expPercent = clamp(Math.round((expCurrent / expMax) * 100), 0, 100);

        if (avatarNode) {
            avatarNode.src = (dataset.avatar && String(dataset.avatar).trim() !== '') ? dataset.avatar : DEFAULT_AVATAR;
        }
        if (nameNode) {
            nameNode.textContent = name;
        }
        if (subtitleNode) {
            subtitleNode.textContent = subtitleParts.length ? subtitleParts.join(' · ') : 'Scheda personaggio';
        }
        if (healthLabelNode) {
            healthLabelNode.textContent = formatNumber(health, 0) + ' / ' + formatNumber(healthMax, 0);
        }
        if (healthBarNode) {
            healthBarNode.style.width = healthPercent + '%';
            healthBarNode.setAttribute('aria-valuenow', String(healthPercent));
        }
        if (expLabelNode) {
            expLabelNode.textContent = formatNumber(expCurrent, 0) + ' / ' + formatNumber(expMax, 0);
        }
        if (expBarNode) {
            expBarNode.style.width = expPercent + '%';
            expBarNode.setAttribute('aria-valuenow', String(expPercent));
        }
        if (expTotalNode) {
            expTotalNode.textContent = formatNumber(totalExperience, 0);
        }
    }

    function initCharacterWidget() {
        const widget = document.querySelector('[data-aurora-character-widget]');
        if (!widget) {
            return;
        }

        const subtitleNode = widget.querySelector('[data-aurora-character-subtitle]');
        const characterId = getSessionCharacterId();
        if (characterId <= 0) {
            if (subtitleNode) {
                subtitleNode.textContent = 'Personaggio non selezionato';
            }
            return;
        }

        let attempts = 0;
        const maxAttempts = 6;

        const load = () => {
            requestProfile(characterId).then((response) => {
                const dataset = response && response.dataset ? response.dataset : null;
                if (!dataset) {
                    throw new Error('Dati profilo non disponibili');
                }
                renderCharacterWidget(widget, dataset);
            }).catch(() => {
                attempts += 1;
                if (attempts <= maxAttempts) {
                    window.setTimeout(load, 450);
                    return;
                }
                if (subtitleNode) {
                    subtitleNode.textContent = 'Profilo non disponibile';
                }
            });
        };

        load();
    }

    function initRadar() {
        const radar = document.querySelector('[data-aurora-radar]');
        if (!radar) {
            return;
        }

        const clockNode = radar.querySelector('[data-aurora-clock]');
        const seasonNode = radar.querySelector('[data-aurora-season]');
        const moonNode = radar.querySelector('[data-aurora-moon]');
        const roleNode = radar.querySelector('[data-aurora-session-role]');
        const notificationsNode = radar.querySelector('[data-aurora-badge-notifications]');
        const questsNode = radar.querySelector('[data-aurora-badge-quests]');
        const eventsNode = radar.querySelector('[data-aurora-badge-events]');
        const activitiesNode = radar.querySelector('[data-aurora-badge-activities]');

        const readCount = (selector) => {
            const node = document.querySelector(selector);
            if (!node) {
                return 0;
            }
            const value = Number.parseInt((node.textContent || '').trim(), 10);
            return Number.isFinite(value) ? Math.max(0, value) : 0;
        };

        const firstText = (selectors) => {
            for (let i = 0; i < selectors.length; i += 1) {
                const node = document.querySelector(selectors[i]);
                if (!node) {
                    continue;
                }
                const text = (node.textContent || '').trim();
                if (text !== '') {
                    return text;
                }
            }
            return 'N/D';
        };

        const updateClock = () => {
            if (!clockNode) {
                return;
            }
            const now = new Date();
            const hh = String(now.getHours()).padStart(2, '0');
            const mm = String(now.getMinutes()).padStart(2, '0');
            const ss = String(now.getSeconds()).padStart(2, '0');
            clockNode.textContent = hh + ':' + mm + ':' + ss;
        };

        const updateRole = () => {
            if (!roleNode) {
                return;
            }
            const pageRoot = document.querySelector('#page-content');
            if (!pageRoot) {
                roleNode.textContent = 'Giocatore';
                roleNode.className = 'badge text-bg-secondary';
                return;
            }
            const isAdmin = pageRoot.getAttribute('data-session-is-admin') === '1';
            const isModerator = pageRoot.getAttribute('data-session-is-moderator') === '1';
            const isMaster = pageRoot.getAttribute('data-session-is-master') === '1';

            if (isAdmin) {
                roleNode.textContent = 'Admin';
                roleNode.className = 'badge text-bg-danger';
                return;
            }
            if (isModerator) {
                roleNode.textContent = 'Moderatore';
                roleNode.className = 'badge text-bg-warning text-dark';
                return;
            }
            if (isMaster) {
                roleNode.textContent = 'Master';
                roleNode.className = 'badge text-bg-info text-dark';
                return;
            }
            roleNode.textContent = 'Giocatore';
            roleNode.className = 'badge text-bg-secondary';
        };

        const updateWeather = () => {
            if (seasonNode) {
                seasonNode.textContent = firstText([
                    '#location-weather .p-weather-season',
                    '[data-weather-widget="navbar-mobile"] .p-weather-season',
                ]);
            }
            if (moonNode) {
                moonNode.textContent = firstText([
                    '#location-weather .p-moonphases',
                    '[data-weather-widget="navbar-mobile"] .p-moonphases',
                ]);
            }
        };

        const updateFeedBadges = () => {
            if (notificationsNode) {
                notificationsNode.textContent = String(readCount('#notifications-navbar-badge'));
            }
            if (questsNode) {
                questsNode.textContent = String(readCount('[data-feed-badge="quests"]'));
            }
            if (eventsNode) {
                eventsNode.textContent = String(readCount('[data-feed-badge="system-events"]'));
            }
            if (activitiesNode) {
                activitiesNode.textContent = String(readCount('[data-feed-badge="narrative-events"]'));
            }
        };

        updateRole();
        updateWeather();
        updateFeedBadges();
        updateClock();

        window.setInterval(updateClock, 1000);
        window.setInterval(() => {
            updateWeather();
            updateFeedBadges();
        }, 3000);

        const observedTargets = [
            document.querySelector('#notifications-navbar-badge'),
            document.querySelector('[data-feed-badge="quests"]'),
            document.querySelector('[data-feed-badge="system-events"]'),
            document.querySelector('[data-feed-badge="narrative-events"]'),
            document.querySelector('#location-weather'),
        ].filter(Boolean);

        if (observedTargets.length > 0) {
            const observer = new MutationObserver(() => {
                updateWeather();
                updateFeedBadges();
            });
            observedTargets.forEach((target) => {
                observer.observe(target, {
                    subtree: true,
                    childList: true,
                    characterData: true,
                    attributes: true,
                });
            });
        }
    }

    onReady(() => {
        initCharacterWidget();
        initRadar();
    });
})();
