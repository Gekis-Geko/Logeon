function Calendar(elem, url, extension) {
    var base = {
        block: elem,
        table: '.calendar',
        urlEvents: url,
        enabledCreation: false,
        monthSelector: '[name="month"]',
        yearSelector: '[name="year"]',
        eventsBlockSelector: 'block[name="events_block"]',
        eventsTemplateSelector: '[name="template_event_block"]',

        selectMonth: null,
        selectYear: null,
        $block: null,
        $table: null,

        today: new Date(),
        currentMonth: (new Date()).getMonth(),
        currentYear: (new Date()).getFullYear(),

        months: [
            'Gennaio',
            'Febbraio',
            'Marzo',
            'Aprile',
            'Maggio',
            'Giugno',
            'Luglio',
            'Agosto',
            'Settembre',
            'Ottobre',
            'Novembre',
            'Dicembre'
        ],

        eventsDataset: null,
        getEventsDataset: [],
        monthEventsDataset: {},
        selectedEventsDataset: [],
        selectedDate: null,

        initDom: function () {
            this.$block = (this.block && this.block.jquery) ? this.block : $(this.block || document);
            if (!this.$block.length) {
                this.$block = $(document);
            }

            this.$table = this.$block.find(this.table).first();
            if (!this.$table.length) {
                this.$table = $(this.table).first();
            }

            this.selectMonth = this.$block.find(this.monthSelector).first();
            if (!this.selectMonth.length) {
                this.selectMonth = $(this.monthSelector).first();
            }

            this.selectYear = this.$block.find(this.yearSelector).first();
            if (!this.selectYear.length) {
                this.selectYear = $(this.yearSelector).first();
            }
        },

        getEventsBlock: function () {
            var block = this.$block.find(this.eventsBlockSelector).first();
            if (!block.length) {
                block = $(this.eventsBlockSelector).first();
            }
            return block;
        },

        getEventsTemplate: function () {
            var template = this.$block.find(this.eventsTemplateSelector).first();
            if (!template.length) {
                template = $(this.eventsTemplateSelector).first();
            }
            return template;
        },

        setDefaultEventsMessage: function () {
            var block = this.getEventsBlock();
            if (!block.length) {
                return;
            }

            block
                .empty()
                .html(
                    $('<p><i class="fas fa-2x fa-fw fa-mouse-pointer"></i><br/>Seleziona un giorno per visualizzare gli eventi.</p>')
                        .addClass('lead text-center')
                );
        },

        padNumber: function (n) {
            n = parseInt(n, 10);
            if (isNaN(n)) {
                n = 0;
            }
            return (n < 10) ? ('0' + n) : String(n);
        },

        normalizeYearMonth: function (year, month) {
            var y = parseInt(year, 10);
            var m = parseInt(month, 10);

            if (isNaN(y)) {
                y = this.currentYear;
            }
            if (isNaN(m)) {
                m = this.currentMonth;
            }

            if (m < 0) {
                m = 0;
            }
            if (m > 11) {
                m = 11;
            }

            return {
                year: y,
                month: m
            };
        },

        isEventsEnabled: function () {
            return this.urlEvents != null;
        },

        setMonthEventsDataset: function (dataset) {
            var normalized = (dataset && typeof dataset === 'object') ? dataset : {};
            this.monthEventsDataset = normalized;
            this.eventsDataset = normalized;
            this.getEventsDataset = normalized; // legacy alias (historical behavior)
            return normalized;
        },

        setSelectedEventsDataset: function (dataset) {
            var normalized = Array.isArray(dataset) ? dataset : [];
            this.selectedEventsDataset = normalized;
            this.getEventsDataset = normalized; // legacy alias (historical behavior)
            return normalized;
        },

        clearSelectedEventsDataset: function () {
            this.selectedDate = null;
            return this.setSelectedEventsDataset([]);
        },

        getMonthEventsForDate: function (dateString) {
            var source = (this.monthEventsDataset && typeof this.monthEventsDataset === 'object')
                ? this.monthEventsDataset
                : ((this.eventsDataset && typeof this.eventsDataset === 'object') ? this.eventsDataset : {});
            return source[dateString];
        },

        resolveSelectedDateFromCell: function (dayCell) {
            if (!dayCell || !dayCell.length) {
                return null;
            }

            var fromButton = String(dayCell.find('.btn-create').data('date') || '').trim();
            if (fromButton) {
                return fromButton;
            }

            var dayNumber = parseInt(dayCell.find('.day').first().text(), 10);
            if (isNaN(dayNumber)) {
                return null;
            }

            return this.currentYear + '-' + this.padNumber(this.currentMonth + 1) + '-' + this.padNumber(dayNumber);
        },

        normalizeEventsError: function (error, fallback) {
            if (typeof window !== 'undefined' && window.Request && typeof window.Request.getErrorMessage === 'function') {
                return window.Request.getErrorMessage(error, fallback || 'Errore nel caricamento degli eventi.');
            }
            if (error && typeof error.message === 'string' && error.message.trim()) {
                return error.message.trim();
            }
            if (typeof error === 'string' && error.trim()) {
                return error.trim();
            }
            return fallback || 'Errore nel caricamento degli eventi.';
        },

        notifyEventsError: function (error, fallback) {
            this.onEventsError(this.normalizeEventsError(error, fallback));
        },

        safeRequest: function (url, callbackName, data, extension) {
            var unavailableMessage = this.requestUnavailableMessage();
            var requestApi = (typeof window !== 'undefined' && window.Request) ? window.Request : ((typeof Request !== 'undefined') ? Request : null);
            var ext = (extension && typeof extension === 'object') ? extension : {};
            if (!requestApi || !requestApi.http || typeof requestApi.http.post !== 'function') {
                this.notifyEventsError(unavailableMessage, unavailableMessage);
                return false;
            }

            var name = String(callbackName || '').trim();
            var cap = name ? (name.charAt(0).toUpperCase() + name.slice(1)) : '';
            var onSuccess = cap ? ext['on' + cap + 'Success'] : null;
            var onError = cap ? ext['on' + cap + 'Error'] : null;

            requestApi.http.post(url, data || {}).then(function (response) {
                if (typeof onSuccess === 'function') {
                    onSuccess(response);
                }
            }).catch(function (error) {
                if (typeof onError === 'function') {
                    onError(error);
                    return;
                }
                this.notifyEventsError(error);
            }.bind(this));
            return true;
        },

        requestUnavailableMessage: function () {
            if (typeof window !== 'undefined' && window.Request && typeof window.Request.getUnavailableMessage === 'function') {
                return window.Request.getUnavailableMessage();
            }
            return 'Servizio comunicazione non disponibile. Ricarica la pagina e riprova.';
        },

        build: function () {
            this.initDom();
            this.unbind();

            if (this.isEventsEnabled()) {
                this.getEvents(this.currentYear, this.currentMonth);
            } else {
                var scopedEventsList = this.$block.find('#eventsList');
                if (scopedEventsList.length) {
                    scopedEventsList.remove();
                } else {
                    $('#eventsList').remove();
                }
                this.buildCalendar(this.currentYear, this.currentMonth);
            }

            this.bind();

            return this;
        },

        sync: function () {
            if (this.isEventsEnabled()) {
                this.getEvents(this.currentYear, this.currentMonth);
            } else {
                this.buildCalendar(this.currentYear, this.currentMonth);
            }
            return this;
        },

        getEventsDatasetFromSelection: function (dataset) {
            var self = this;
            var where = dataset || {};

            this.clearSelectedEventsDataset();

            if (!this.urlEvents || !this.urlEvents.list) {
                this.buildEventsList();
                return;
            }

            if (!this.safeRequest(this.urlEvents.list, 'events', where, {
                onEventsSuccess: function (R) {
                    if (R != null && Array.isArray(R.dataset)) {
                        self.setSelectedEventsDataset(R.dataset);
                    } else {
                        self.setSelectedEventsDataset([]);
                    }
                    self.buildEventsList();
                },
                onEventsError: function (error) {
                    self.setSelectedEventsDataset([]);
                    self.buildEventsList();
                    self.notifyEventsError(error, 'Errore nel caricamento degli eventi del giorno.');
                }
            })) {
                self.setSelectedEventsDataset([]);
                self.buildEventsList();
            }
        },

        getEvents: function (year, month) {
            if (year == null || month == null) {
                return;
            }
            var self = this;
            var normalized = this.normalizeYearMonth(year, month);
            var monthNumber = normalized.month + 1;
            var yearNumber = normalized.year;

            if (!this.urlEvents || !this.urlEvents.count) {
                this.setMonthEventsDataset({});
                this.buildCalendar(yearNumber, normalized.month);
                return;
            }

            var where = {
                query: [
                    'MONTH(date_start) = ' + monthNumber + ' OR MONTH(date_end) = ' + monthNumber,
                    'YEAR(date_start) = ' + yearNumber + ' OR YEAR(date_end) = ' + yearNumber
                ]
            };

            if (!this.safeRequest(this.urlEvents.count, 'allEvents', where, {
                onAllEventsSuccess: function (R) {
                    self.setMonthEventsDataset((R != null) ? R : {});
                    self.buildCalendar(yearNumber, normalized.month);
                },
                onAllEventsError: function (error) {
                    self.setMonthEventsDataset({});
                    self.buildCalendar(yearNumber, normalized.month);
                    self.notifyEventsError(error, 'Errore nel caricamento del calendario eventi.');
                }
            })) {
                self.setMonthEventsDataset({});
                self.buildCalendar(yearNumber, normalized.month);
            }
        },

        buildCalendar: function (year, month) {
            year = parseInt(year, 10);
            month = parseInt(month, 10);
            if (isNaN(year) || isNaN(month)) {
                return;
            }

            this.currentYear = year;
            this.currentMonth = month;

            var firstDay = (new Date(year, month, 1)).getDay();
            var daysInMonth = (new Date(year, month + 1, 0)).getDate();
            var tbody = this.$table.find('tbody').first();
            if (!tbody.length) {
                return;
            }
            tbody.empty();

            if (this.selectMonth && this.selectMonth.length) {
                this.selectMonth.val(month);
            }
            if (this.selectYear && this.selectYear.length) {
                this.selectYear.val(year);
            }

            var day = 1;
            var started = false;
            while (day <= daysInMonth) {
                var row = $('<tr></tr>').appendTo(tbody);

                for (var n = 0; n < 7; n++) {
                    if (!started && n < firstDay) {
                        $('<td></td>').appendTo(row);
                        continue;
                    }

                    started = true;
                    if (day > daysInMonth) {
                        $('<td></td>').appendTo(row);
                        continue;
                    }

                    var monthString = this.padNumber(month + 1);
                    var dayString = this.padNumber(day);
                    var dateString = year + '-' + monthString + '-' + dayString;
                    var td = null;

                    if (this.isEventsEnabled()) {
                        var eventsForDay = this.getMonthEventsForDate(dateString) || [];
                        var eventCount = Array.isArray(eventsForDay) ? eventsForDay.length : 0;
                        var eventLabel = (eventCount > 1) ? 'Eventi' : 'Evento';
                        var events = (eventCount === 0) ? '' : '<span class="events_number">' + eventCount + ' ' + eventLabel + '</span>';
                        var eventIds = (eventCount === 0) ? '' : eventsForDay.join(', ');
                        var btnCreate = (this.enabledCreation === false)
                            ? ''
                            : '<button class="btn btn-light btn-create round sm add" data-date="' + dateString + '"><i class="fas fa-fw fa-plus"></i></button>';

                        td = $('<td class="day-calendar"><div class="day-calendar-selection" data-events="' + eventIds + '">' + events + '</div><span class="day">' + day + '</span>' + btnCreate + '</td>')
                            .appendTo(row);
                    } else {
                        td = $('<td class="day-calendar"><div class="day-calendar-selection"></div><span class="day">' + day + '</span></td>')
                            .appendTo(row);
                    }

                    if (
                        day === this.today.getDate() &&
                        year === this.today.getFullYear() &&
                        month === this.today.getMonth()
                    ) {
                        td.find('.day').addClass('now');
                    }

                    day++;
                }
            }

            this.setDefaultEventsMessage();

            if (this.isEventsEnabled()) {
                this.selectionDay();
            }

            this.onBuilding();
        },

        buildEventsList: function () {
            var block = this.getEventsBlock();
            if (!block.length) {
                return;
            }

            block.empty();
            var listDataset = Array.isArray(this.getEventsDataset) ? this.getEventsDataset : this.selectedEventsDataset;
            if (listDataset == null || !Array.isArray(listDataset) || listDataset.length === 0) {
                block.html($('<p>Non ci sono eventi</p>').addClass('lead text-center'));
                this.onBuildingEventList();
                return;
            }

            var template = this.getEventsTemplate();
            if (!template.length) {
                block.html($('<p>Template eventi non disponibile</p>').addClass('lead text-center'));
                this.onBuildingEventList();
                return;
            }

            for (var i = 0; i < listDataset.length; i++) {
                var event = listDataset[i];
                var temp = $(template.html());
                var dateStart = String(event.date_start || '').split(' ');
                var dateEnd = String(event.date_end || '').split(' ');

                temp.find('[name="title"]').html(event.title || '');
                temp.find('[name="date_start"]').html('<i class="fas fa-fw fa-calendar-day"></i> ' + (dateStart[0] || '-') + '<br/><i class="fas fa-fw fa-clock"></i> ' + (dateStart[1] || '-'));
                temp.find('[name="date_end"]').html('<i class="fas fa-fw fa-calendar-day"></i> ' + (dateEnd[0] || '-') + '<br/><i class="fas fa-fw fa-clock"></i> ' + (dateEnd[1] || '-'));
                temp.find('[name="short_description"]').html(event.short_description || '');
                temp.find('.btn-update').data('value', JSON.stringify(event));
                temp.find('.btn-delete').data('value', JSON.stringify(event));
                temp.appendTo(block);
            }

            this.onBuildingEventList();
        },

        previous: function () {
            var self = this;
            this.$block.off('click.calendarPrev', '.previous').on('click.calendarPrev', '.previous', function (e) {
                e.preventDefault();
                self.currentYear = (self.currentMonth === 0) ? self.currentYear - 1 : self.currentYear;
                self.currentMonth = (self.currentMonth === 0) ? 11 : self.currentMonth - 1;
                self.sync();
            });
        },

        next: function () {
            var self = this;
            this.$block.off('click.calendarNext', '.next').on('click.calendarNext', '.next', function (e) {
                e.preventDefault();
                self.currentYear = (self.currentMonth === 11) ? self.currentYear + 1 : self.currentYear;
                self.currentMonth = (self.currentMonth === 11) ? 0 : self.currentMonth + 1;
                self.sync();
            });
        },

        goToNow: function () {
            var self = this;
            this.$block.off('click.calendarNow', '.goToNow').on('click.calendarNow', '.goToNow', function (e) {
                e.preventDefault();
                self.currentYear = (new Date()).getFullYear();
                self.currentMonth = (new Date()).getMonth();
                self.sync();
            });
        },

        goTo: function () {
            var self = this;
            if (!this.selectMonth.length || !this.selectYear.length) {
                return;
            }

            this.selectMonth.off('change.calendarGoTo').on('change.calendarGoTo', function () {
                var normalized = self.normalizeYearMonth(self.selectYear.val(), self.selectMonth.val());
                self.currentYear = normalized.year;
                self.currentMonth = normalized.month;
                self.sync();
            });

            this.selectYear.off('change.calendarGoTo').on('change.calendarGoTo', function () {
                var normalized = self.normalizeYearMonth(self.selectYear.val(), self.selectMonth.val());
                self.currentYear = normalized.year;
                self.currentMonth = normalized.month;
                self.sync();
            });
        },

        selectionDay: function () {
            var self = this;
            this.$table.off('click.calendarSelect', '.day-calendar-selection').on('click.calendarSelect', '.day-calendar-selection', function (e) {
                e.preventDefault();
                self.onSelection($(this));
            });
        },

        bind: function () {
            this.previous();
            this.goToNow();
            this.next();
            this.goTo();
            return this;
        },

        unbind: function () {
            if (this.$block && this.$block.length) {
                this.$block.off('click.calendarPrev', '.previous');
                this.$block.off('click.calendarNext', '.next');
                this.$block.off('click.calendarNow', '.goToNow');
            }
            if (this.selectMonth && this.selectMonth.length) {
                this.selectMonth.off('change.calendarGoTo');
            }
            if (this.selectYear && this.selectYear.length) {
                this.selectYear.off('change.calendarGoTo');
            }
            if (this.$table && this.$table.length) {
                this.$table.off('click.calendarSelect', '.day-calendar-selection');
            }
            return this;
        },

        onSelection: function (obj) {
            var dayCell = obj.parent();
            if (!dayCell.hasClass('selected')) {
                this.$table.find('.day-calendar').removeClass('selected');
                dayCell.addClass('selected');
                this.selectedDate = this.resolveSelectedDateFromCell(dayCell);

                var events = String(obj.data('events') || '').trim();
                if (!events) {
                    this.setSelectedEventsDataset([]);
                    this.buildEventsList();
                    this.onSelected();
                    return;
                }

                this.getEventsDatasetFromSelection({
                    query: [
                        'id IN (' + events + ')'
                    ]
                });

                this.onSelected();
            } else {
                dayCell.removeClass('selected');
                this.selectedDate = null;
                if (this.isEventsEnabled()) {
                    this.setDefaultEventsMessage();
                }
                this.onUnselected();
            }

            return;
        },

        onSelected: function () {},
        onUnselected: function () {},
        onBuilding: function () {},
        onBuildingEventList: function () {},
        onEventsError: function () {},

        destroy: function () {
            return this.unbind();
        }
    };

    var o = Object.assign({}, base, extension);
    return o.build();
}

if (typeof window !== 'undefined') {
    window.Calendar = Calendar;
}
