/**
 * Raccolta di utility generiche per formattazione e manipolazione dati.
 * Nessuna dipendenza esterna.
 *
 * @returns {Object} Istanza Utils con i metodi descritti.
 */
function Utils() {
    let base = {
        /**
         * Converte una stringa in uno slug URL-safe (lowercase, solo a-z0-9, trattini).
         * Rimuove accenti tramite NFD + strip diacritici.
         * @param {*} str - Valore da convertire (viene forzato a stringa).
         * @returns {string} Slug normalizzato.
         */
        stringify: function (str) {
            if (str == null) {
                return '';
            }

            let value = String(str).trim().toLowerCase();
            if (typeof value.normalize === 'function') {
                value = value.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            }

            value = value
                .replace(/[^a-z0-9 -]/g, '')
                .replace(/\s+/g, '-')
                .replace(/-+/g, '-');

            return value;
        },

        /**
         * Formatta un numero di byte in una stringa leggibile (es. '2.50 MB').
         * @param {number} bytes
         * @returns {string}
         */
        formatBytes: function (bytes) {
            let value = Number(bytes);
            if (!Number.isFinite(value) || value <= 0) {
                return '0 Byte';
            }

            let sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
            let idx = Math.min(Math.floor(Math.log(value) / Math.log(1024)), sizes.length - 1);
            let output = value / Math.pow(1024, idx);
            let decimals = (idx === 0) ? 0 : 2;
            let text = output.toFixed(decimals).replace(/\.00$/, '');

            return text + ' ' + sizes[idx];
        },

        /**
         * Formatta un numero con separatore migliaia punto e decimali virgola (stile IT).
         * @param {number|string} num
         * @param {number} [n_decimal=0] - Numero di cifre decimali.
         * @returns {string} Es. '1.234,56'
         */
        formatNumber: function (num, n_decimal) {
            let value = Number(num);
            if (!Number.isFinite(value)) {
                value = 0;
            }

            let decimals = parseInt(n_decimal, 10);
            if (!Number.isFinite(decimals) || decimals < 0) {
                decimals = 0;
            }

            let fixed = value.toFixed(decimals);
            let parts = fixed.split('.');
            let integer = parts[0];
            let sign = '';

            if (integer.charAt(0) === '-') {
                sign = '-';
                integer = integer.substring(1);
            }

            integer = integer.replace(/\B(?=(\d{3})+(?!\d))/g, '.');

            let output = sign + integer;
            if (parts.length > 1) {
                output += ',' + parts[1];
            }

            return output;
        },

        /**
         * Formatta un numero come prezzo con simbolo valuta.
         * @param {number|string} num
         * @param {number} [decimals=2]
         * @param {string} [currency='€']
         * @returns {string} Es. '€ 12,50'
         */
        formatPrice: function (num, decimals, currency) {
            let symbol = (currency == null || String(currency).trim() === '') ? '\u20AC' : String(currency);

            let safeDecimals = parseInt(decimals, 10);
            if (!Number.isFinite(safeDecimals) || safeDecimals < 0) {
                safeDecimals = 2;
            }

            return symbol + ' ' + this.formatNumber(num, safeDecimals);
        }
    };

    return base;
}

if (typeof window !== 'undefined') {
    window.Utils = Utils;
}
