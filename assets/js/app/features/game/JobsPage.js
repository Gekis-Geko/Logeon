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


function normalizeJobsError(error, fallback) {
    if (globalWindow.GameFeatureError && typeof globalWindow.GameFeatureError.normalize === 'function') {
        return globalWindow.GameFeatureError.normalize(error, fallback || 'Operazione non riuscita.');
    }
    if (typeof error === 'string' && error.trim() !== '') {
        return error.trim();
    }
    if (error && typeof error.message === 'string' && error.message.trim() !== '') {
        return error.message.trim();
    }
    if (error && typeof error.error === 'string' && error.error.trim() !== '') {
        return error.error.trim();
    }
    return fallback || 'Operazione non riuscita.';
}

function callJobsModule(method, payload, onSuccess, onError) {
    if (typeof resolveModule !== 'function') {
        if (typeof onError === 'function') {
            onError(new Error('Jobs module resolver not available: ' + method));
        }
        return false;
    }

    var mod = resolveModule('game.jobs');
    if (!mod || typeof mod[method] !== 'function') {
        if (typeof onError === 'function') {
            onError(new Error('Jobs module method not available: ' + method));
        }
        return false;
    }

    var args = [];
    if (typeof payload !== 'undefined') {
        args.push(payload);
    }

    var request = mod[method].apply(mod, args);
    if (!request || typeof request.then !== 'function') {
        if (typeof onError === 'function') {
            onError(new Error('Jobs module method is not async: ' + method));
        }
        return false;
    }

    request.then(function (response) {
        if (typeof onSuccess === 'function') {
            onSuccess(response);
        }
    }).catch(function (error) {
        if (typeof onError === 'function') {
            onError(error);
        }
    });

    return true;
}

function GameJobsPage(extension) {
    let page = {
        job: null,
        tasks: [],
        history: [],
        available: [],
        showing_available: false,
        boardAlwaysVisible: false,
        location_id: null,
        resetCountdownInterval: null,

        init: function () {
            if (!$('#jobs-panel').length) {
                return this;
            }

            let container = $('#jobs-page');
            if (container.length) {
                let loc = container.attr('data-location-id');
                if (loc !== undefined && loc !== null && loc !== '') {
                    this.location_id = loc;
                }

                this.boardAlwaysVisible = (container.attr('data-jobs-mode') === 'board-open');
            }

            this.bindActions();
            this.startResetCountdown();
            this.loadCurrent();
            return this;
        },
        bindActions: function () {
            var self = this;
            $('#job-change').on('click', function () {
                if (self.boardAlwaysVisible) {
                    self.showing_available = true;
                    self.loadCurrent();
                    return;
                }

                self.showing_available = !self.showing_available;
                if (self.showing_available) {
                    self.loadAvailable();
                } else {
                    $('#job-available').addClass('d-none');
                }
            });

            $('#job-leave').on('click', function () {
                self.leaveCurrentJob();
            });
        },
        formatNumber: function (value) {
            let num = Number(value || 0);
            if (!isFinite(num)) {
                num = 0;
            }

            try {
                if (typeof Utils === 'function') {
                    return Utils().formatNumber(num);
                }
            } catch (error) {
            }

            return String(num);
        },
        formatDateTime: function (value) {
            let raw = String(value || '').trim();
            if (raw === '') {
                return '-';
            }

            let normalized = raw.replace(' ', 'T');
            let date = new Date(normalized);
            if (isNaN(date.getTime())) {
                return raw;
            }

            return date.toLocaleString('it-IT', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit'
            });
        },
        renderResetCountdown: function () {
            let now = new Date();
            let target = null;
            let labelPrefix = 'Nuovi incarichi tra ';
            let isJobLocked = this.job && parseInt(this.job.tasks_locked || 0, 10) === 1;

            if (isJobLocked && this.job.tasks_unlock_at) {
                let unlockRaw = String(this.job.tasks_unlock_at || '').trim();
                let unlockDate = new Date(unlockRaw.replace(' ', 'T'));
                if (!isNaN(unlockDate.getTime())) {
                    target = unlockDate;
                    labelPrefix = 'Incarichi disponibili tra ';
                }
            }

            if (!target) {
                target = new Date();
                target.setHours(24, 0, 0, 0);
                labelPrefix = 'Nuovi incarichi tra ';
            }

            let diff = target.getTime() - now.getTime();
            if (diff <= 0) {
                if (isJobLocked) {
                    $('[name="job-reset-countdown"]').text('Incarichi disponibili ora');
                } else {
                    $('[name="job-reset-countdown"]').text('Nuovi incarichi in aggiornamento...');
                }
                return;
            }

            let totalSeconds = Math.floor(diff / 1000);
            let hours = Math.floor(totalSeconds / 3600);
            let minutes = Math.floor((totalSeconds % 3600) / 60);
            let seconds = totalSeconds % 60;

            let hh = String(hours).padStart(2, '0');
            let mm = String(minutes).padStart(2, '0');
            let ss = String(seconds).padStart(2, '0');

            let text = labelPrefix + hh + ':' + mm + ':' + ss;
            if (isJobLocked && this.job.tasks_unlock_at) {
                text += ' (dal ' + this.formatDateTime(this.job.tasks_unlock_at) + ')';
            }

            $('[name="job-reset-countdown"]').text(text);
        },
        startResetCountdown: function () {
            this.renderResetCountdown();
            if (this.resetCountdownInterval) {
                clearInterval(this.resetCountdownInterval);
            }
            this.resetCountdownInterval = setInterval(this.renderResetCountdown.bind(this), 1000);
        },
        loadCurrent: function () {
            var self = this;
            callJobsModule('current', null, function (response) {
                self.job = response.job || null;
                self.tasks = response.tasks || [];
                self.history = response.history || [];
                self.renderResetCountdown();
                self.updateProfileLabel();
                self.build();
            }, function (error) {
                Toast.show({
                    body: normalizeJobsError(error, 'Errore durante caricamento lavoro corrente'),
                    type: 'error'
                });
            });
        },
        loadAvailable: function () {
            var self = this;
            let payload = {};
            if (self.location_id) {
                payload.location_id = self.location_id;
            }
            callJobsModule('available', payload, function (response) {
                self.available = response.dataset || [];
                self.buildAvailable();
            }, function (error) {
                Toast.show({
                    body: normalizeJobsError(error, 'Errore durante caricamento lavori disponibili'),
                    type: 'error'
                });
            });
        },
        assign: function (job_id) {
            var self = this;
            callJobsModule('assign', { job_id: job_id }, function () {
                self.showing_available = self.boardAlwaysVisible;
                if (!self.boardAlwaysVisible) {
                    $('#job-available').addClass('d-none');
                }
                self.loadCurrent();
            }, function (error) {
                Toast.show({
                    body: normalizeJobsError(error, 'Errore durante assegnazione lavoro'),
                    type: 'error'
                });
            });
        },
        leaveCurrentJob: function () {
            var self = this;
            callJobsModule('leave', {}, function () {
                self.showing_available = self.boardAlwaysVisible;
                self.loadCurrent();
            }, function (error) {
                Toast.show({
                    body: normalizeJobsError(error, 'Errore durante uscita dal lavoro'),
                    type: 'error'
                });
            });
        },
        completeTask: function (assignment_id, choice_id) {
            var self = this;
            var normalizedAssignmentId = parseInt(assignment_id, 10);
            var normalizedChoiceId = parseInt(choice_id, 10);

            if (!isFinite(normalizedAssignmentId) || normalizedAssignmentId <= 0 || !isFinite(normalizedChoiceId) || normalizedChoiceId <= 0) {
                Toast.show({
                    body: 'Incarico non valido.',
                    type: 'error'
                });
                return;
            }

            callJobsModule('completeTask', {
                assignment_id: normalizedAssignmentId,
                choice_id: normalizedChoiceId
            }, function (response) {
                if (response && response.character && typeof response.character.money !== 'undefined') {
                    let moneyValue = parseFloat(response.character.money);
                    let moneyLabel = (moneyValue <= 0) ? 'Vuote' : Utils().formatNumber(moneyValue);
                    $('[name="character_money"]').text(moneyLabel);
                }
                if (response && response.job) {
                    $('[name="job_level"]').text(response.job.level ? ('Liv. ' + response.job.level) : '');
                    $('[name="job_current_level"]').text(response.job.level ? ('Liv. ' + response.job.level) : '');
                }
                Toast.show({
                    body: 'Incarico completato.',
                    type: 'success'
                });
                self.loadCurrent();
            }, function (error) {
                Toast.show({
                    body: normalizeJobsError(error, 'Errore durante completamento incarico'),
                    type: 'error'
                });
            });
        },
        updateProfileLabel: function () {
            let label = 'Disoccupato';
            let levelLabel = '';
            if (this.job) {
                label = this.job.name || 'Lavoro';
                if (this.job.level) {
                    levelLabel = 'Liv. ' + this.job.level;
                }
            }
            $('[name="job_name"]').text(label);
            $('[name="job_level"]').text(levelLabel);
        },
        buildProgress: function (current) {
            let progressText = current.find('[name="job_progress_text"]');
            let progressBar = current.find('[name="job_progress_bar"]');
            let bonusText = current.find('[name="job_bonus"]');

            if (!progressText.length || !progressBar.length || !bonusText.length || !this.job) {
                return;
            }

            let currentPoints = parseInt(this.job.points || 0, 10);
            let nextMin = this.job.next_level_min_points;
            let pointsToNext = parseInt(this.job.points_to_next_level || 0, 10);
            let progressPercent = parseInt(this.job.progress_percent || 0, 10);
            if (isNaN(progressPercent) || progressPercent < 0) {
                progressPercent = 0;
            }
            if (progressPercent > 100) {
                progressPercent = 100;
            }

            if (nextMin === null || typeof nextMin === 'undefined') {
                progressText.text('Livello massimo raggiunto');
                progressBar.css('width', '100%').attr('aria-valuenow', '100');
            } else {
                progressText.text(currentPoints + ' / ' + nextMin + ' pt (mancano ' + pointsToNext + ')');
                progressBar.css('width', progressPercent + '%').attr('aria-valuenow', String(progressPercent));
            }

            let bonus = parseInt(this.job.pay_bonus_percent || 0, 10);
            if (isNaN(bonus)) {
                bonus = 0;
            }

            let bonusParts = ['Bonus retribuzione attivo: +' + bonus + '%'];
            if (this.job.level_title) {
                bonusParts.push('Titolo: ' + this.job.level_title);
            }
            if (this.job.next_level_title) {
                bonusParts.push('Prossimo: ' + this.job.next_level_title);
            }
            bonusText.text(bonusParts.join(' | '));
        },
        build: function () {
            let panel = $('#jobs-panel');
            if (!panel.length) {
                return;
            }

            let current = $('#job-current');
            let empty = $('#job-empty');
            let tasksBlock = $('#job-tasks').empty();
            let historyBlock = $('#job-history').empty();
            let changeBtn = $('#job-change');
            let leaveBtn = $('#job-leave');

            if (!this.job) {
                current.addClass('d-none');
                empty.removeClass('d-none');
                if (this.boardAlwaysVisible) {
                    changeBtn.removeClass('d-none').html('<i class="bi bi-arrow-clockwise"></i> Aggiorna bacheca');
                } else {
                    changeBtn.addClass('d-none').html('<i class="bi bi-arrow-left-right"></i> Cambia lavoro');
                }
                leaveBtn.addClass('d-none');
                this.showing_available = true;
                this.loadAvailable();
                this.buildHistory(historyBlock);
                return;
            }

            empty.addClass('d-none');
            current.removeClass('d-none');
            changeBtn.removeClass('d-none');
            if (this.boardAlwaysVisible) {
                changeBtn.html('<i class="bi bi-arrow-clockwise"></i> Aggiorna bacheca');
            } else {
                changeBtn.html('<i class="bi bi-arrow-left-right"></i> Cambia lavoro');
            }
            leaveBtn.removeClass('d-none');
            current.find('[name="job_current_name"]').text(this.job.name || '');
            current.find('[name="job_current_level"]').text(this.job.level ? ('Liv. ' + this.job.level) : '');

            let meta = [];
            if (this.job.location_name) {
                meta.push('Luogo: ' + this.job.location_name);
            }
            if (this.job.level_title) {
                meta.push(this.job.level_title);
            }
            current.find('[name="job_current_meta"]').text(meta.join(' | '));

            this.buildProgress(current);
            this.buildTasks(tasksBlock);
            this.buildHistory(historyBlock);

            if (this.boardAlwaysVisible) {
                this.showing_available = true;
                this.loadAvailable();
            } else if (!this.showing_available) {
                $('#job-available').addClass('d-none');
            }
        },
        buildAvailable: function () {
            let block = $('#job-available').empty().removeClass('d-none');
            if (!this.available || this.available.length === 0) {
                block.append('<div class="border rounded p-3 text-muted small">Nessuna opportunita disponibile al momento.</div>');
                return;
            }

            for (var i in this.available) {
                let job = this.available[i];
                let template = $($('template[name="template_job_available"]').html());
                let meta = [];
                if (job.location_name) {
                    meta.push('Luogo: ' + job.location_name);
                }
                if (job.required_status_name) {
                    meta.push('Richiede: ' + job.required_status_name);
                }
                template.find('[name="name"]').text(job.name || '');
                template.find('[name="meta"]').text(meta.join(' | '));

                let choose = template.find('[name="choose"]');
                if (!job.is_available) {
                    choose.addClass('disabled').attr('aria-disabled', 'true');
                    if (job.reason) {
                        choose.attr('data-bs-toggle', 'tooltip');
                        choose.attr('data-bs-title', job.reason);
                    }
                } else {
                    choose.on('click', this.assign.bind(this, job.id));
                }

                block.append(template);
            }

            initTooltips(block[0]);
        },
        buildTasks: function (block) {
            if (!this.tasks || this.tasks.length === 0) {
                block.append('<div class="border rounded p-3 text-muted small">Per oggi non hai incarichi disponibili.</div>');
                return;
            }

            for (var i in this.tasks) {
                let task = this.tasks[i];
                let template = $($('template[name="template_job_task"]').html());
                template.find('[name="title"]').text(task.title || '');
                template.find('[name="body"]').text(task.body || '');

                let choicesBlock = template.find('[name="choices"]').empty();
                let statusBlock = template.find('[name="status"]');
                let goLocation = template.find('[name="go_location"]');
                goLocation.addClass('d-none');

                if (task.status === 'completed') {
                    statusBlock.text('Completato');
                } else if (task.status === 'expired') {
                    statusBlock.text('Scaduto');
                } else {
                    if (task.choices && task.choices.length) {
                        for (var c in task.choices) {
                            let choice = task.choices[c];
                            let label = choice.label || choice.choice_code || 'Scegli';
                            let btn = $('<button type="button" class="btn btn-sm btn-outline-primary d-inline-flex flex-column align-items-start px-2 py-1"></button>');

                            let rewardParts = [];
                            if (choice.pay) {
                                rewardParts.push('+' + this.formatNumber(choice.pay) + ' denaro');
                            }
                            if (choice.fame) {
                                rewardParts.push('+' + this.formatNumber(choice.fame) + ' fama');
                            }
                            if (choice.points) {
                                rewardParts.push('+' + this.formatNumber(choice.points) + ' pt');
                            }

                            btn.append($('<span class="d-block"></span>').text(label));
                            if (rewardParts.length) {
                                btn.append($('<span class="d-block small opacity-75"></span>').text(rewardParts.join(' | ')));
                            }

                            if (task.can_complete === 0 || task.can_complete === false) {
                                btn.addClass('disabled').attr('aria-disabled', 'true');
                            } else {
                                let assignmentId = task.assignment_id || task.id || null;
                                let choiceId = choice.id || choice.choice_id || null;
                                btn.on('click', this.completeTask.bind(this, assignmentId, choiceId));
                            }
                            choicesBlock.append(btn);
                        }
                    }
                    if (task.lock_reason) {
                        statusBlock.text(task.lock_reason);
                    } else if (task.requires_location_name) {
                        statusBlock.text('Richiede presenza in: ' + task.requires_location_name);
                    }

                    if ((task.can_complete === 0 || task.can_complete === false) && task.requires_location_id) {
                        let href = '/game/maps';
                        if (task.requires_map_id) {
                            href = '/game/maps/' + task.requires_map_id + '/location/' + task.requires_location_id;
                        }
                        goLocation.attr('href', href).removeClass('d-none');
                    }
                }

                block.append(template);
            }

            initTooltips(block[0]);
        },
        buildHistory: function (block) {
            if (!block || !block.length) {
                return;
            }

            block.empty();

            if (!this.history || this.history.length === 0) {
                block.append('<div class="border rounded p-3 text-muted small">Nessun incarico completato di recente.</div>');
                return;
            }

            let list = $('<div class="list-group"></div>');
            if (!this.boardAlwaysVisible) {
                list.addClass('mt-2');
            }
            for (var i in this.history) {
                let row = this.history[i] || {};
                let item = $('<div class="list-group-item px-3 py-2"></div>');
                let wrap = $('<div class="d-flex justify-content-between align-items-start gap-2"></div>');
                let left = $('<div></div>');

                let title = row.title || 'Incarico';
                if (row.choice_label) {
                    title += ' | ' + row.choice_label;
                }

                let rewardParts = [];
                if (row.pay) {
                    rewardParts.push('+' + this.formatNumber(row.pay) + ' denaro');
                }
                if (row.fame) {
                    rewardParts.push('+' + this.formatNumber(row.fame) + ' fama');
                }
                if (row.points) {
                    rewardParts.push('+' + this.formatNumber(row.points) + ' pt');
                }

                left.append($('<div class="fw-semibold small"></div>').text(title));
                left.append($('<div class="small text-muted"></div>').text(rewardParts.length ? rewardParts.join(' | ') : 'Nessuna ricompensa'));

                let completedAt = row.date_completed || row.assigned_date || '';
                wrap.append(left);
                wrap.append($('<small class="text-muted"></small>').text(this.formatDateTime(completedAt)));
                item.append(wrap);
                list.append(item);
            }

            block.append(list);
        }
    };

    let jobs = Object.assign({}, page, extension);
    return jobs.init();
}

function GameJobSummaryPage(extension) {
    let page = {
        init: function () {
            if ($('[name="job_name"]').length === 0) {
                return this;
            }

            this.load();
            return this;
        },
        load: function () {
            callJobsModule('summary', undefined, function (response) {
                let label = 'Disoccupato';
                let levelLabel = '';
                if (response && response.job) {
                    label = response.job.name || 'Lavoro';
                    if (response.job.level) {
                        levelLabel = 'Liv. ' + response.job.level;
                    }
                }
                $('[name="job_name"]').text(label);
                $('[name="job_level"]').text(levelLabel);
            }, function (error) {
                console.warn('[JobSummary] module call failed', error);
            });
        }
    };

    let summary = Object.assign({}, page, extension);
    return summary.init();
}

globalWindow.GameJobsPage = GameJobsPage;
globalWindow.GameJobSummaryPage = GameJobSummaryPage;
export { GameJobsPage as GameJobsPage };
export { GameJobSummaryPage as GameJobSummaryPage };
export default GameJobSummaryPage;

