function Dates() {
    var base = {
        getDate: function () {
            return this._formatDateValue(new Date(), true);
        },

        getTimestamp: function (dates) {
            var parsed = this._parse(dates);
            return parsed ? parsed.getTime() : null;
        },

        formatHumanDate: function (dates) {
            var parsed = this._parse(dates);
            return parsed ? this._formatHumanDate(parsed) : '';
        },

        formatHumanTime: function (dates) {
            var parsed = this._parse(dates);
            return parsed ? this._formatHumanTime(parsed) : '';
        },

        formatHumanDateTime: function (dates) {
            var parsed = this._parse(dates);
            if (!parsed) {
                return '';
            }
            return this._formatHumanDate(parsed) + ' alle ' + this._formatHumanTime(parsed);
        },

        formatHumanShortDateTime: function (dates) {
            var parsed = this._parse(dates);
            if (!parsed) {
                return '';
            }
            return this._formatHumanDate(parsed) + ' ' + this._formatHumanTime(parsed);
        },

        _parse: function (dates) {
            if (dates instanceof Date) {
                return Number.isNaN(dates.getTime()) ? null : new Date(dates.getTime());
            }

            if (dates == null) {
                return null;
            }

            var raw = String(dates).trim();
            if (raw === '') {
                return null;
            }

            // Normalize common SQL/ISO formats.
            var normalized = raw.replace('T', ' ').replace(/\.\d+$/, '').replace(/Z$/, '');
            var match = normalized.match(/^(\d{4})-(\d{2})-(\d{2})(?:\s+(\d{2}):(\d{2})(?::(\d{2}))?)?$/);
            if (match) {
                var year = parseInt(match[1], 10);
                var month = parseInt(match[2], 10);
                var day = parseInt(match[3], 10);
                var hour = parseInt(match[4] || '0', 10);
                var minute = parseInt(match[5] || '0', 10);
                var second = parseInt(match[6] || '0', 10);

                if (!this._isValidDateParts(year, month, day, hour, minute, second)) {
                    return null;
                }

                var dt = new Date(year, month - 1, day, hour, minute, second);
                if (this._matchesDateParts(dt, year, month, day, hour, minute, second)) {
                    return dt;
                }
            }

            var timestamp = Date.parse(raw);
            if (Number.isNaN(timestamp)) {
                return null;
            }

            var parsed = new Date(timestamp);
            return Number.isNaN(parsed.getTime()) ? null : parsed;
        },

        _isValidDateParts: function (year, month, day, hour, minute, second) {
            if (!Number.isInteger(year) || year < 1900 || year > 9999) {
                return false;
            }
            if (!Number.isInteger(month) || month < 1 || month > 12) {
                return false;
            }
            if (!Number.isInteger(day) || day < 1 || day > 31) {
                return false;
            }
            if (!Number.isInteger(hour) || hour < 0 || hour > 23) {
                return false;
            }
            if (!Number.isInteger(minute) || minute < 0 || minute > 59) {
                return false;
            }
            if (!Number.isInteger(second) || second < 0 || second > 59) {
                return false;
            }
            return true;
        },

        _matchesDateParts: function (date, year, month, day, hour, minute, second) {
            if (!(date instanceof Date) || Number.isNaN(date.getTime())) {
                return false;
            }

            return date.getFullYear() === year &&
                (date.getMonth() + 1) === month &&
                date.getDate() === day &&
                date.getHours() === hour &&
                date.getMinutes() === minute &&
                date.getSeconds() === second;
        },

        _pad: function (num) {
            return String(num).padStart(2, '0');
        },

        _formatHumanDate: function (date) {
            return [
                this._pad(date.getDate()),
                this._pad(date.getMonth() + 1),
                date.getFullYear()
            ].join('/');
        },

        _formatHumanTime: function (date) {
            return [
                this._pad(date.getHours()),
                this._pad(date.getMinutes())
            ].join(':');
        },

        _formatDateValue: function (date, withSeconds) {
            if (!(date instanceof Date) || Number.isNaN(date.getTime())) {
                return '';
            }

            var datePart = [
                date.getFullYear(),
                this._pad(date.getMonth() + 1),
                this._pad(date.getDate())
            ].join('-');

            var time = [
                this._pad(date.getHours()),
                this._pad(date.getMinutes())
            ];
            if (withSeconds) {
                time.push(this._pad(date.getSeconds()));
            }

            return datePart + ' ' + time.join(':');
        }
    };

    return base;
}

if (typeof window !== 'undefined') {
    window.Dates = Dates;
}
