const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

var AUTO_INIT_FLAG = '__game_anagrafica_page_auto_init_done';

function toPositiveInt(value, fallback) {
    var parsed = parseInt(String(value || ''), 10);
    if (!isFinite(parsed) || parsed <= 0) {
        return parseInt(String(fallback || 1), 10) || 1;
    }
    return parsed;
}

function toNonNegativeInt(value, fallback) {
    var parsed = parseInt(String(value || ''), 10);
    if (!isFinite(parsed) || parsed < 0) {
        parsed = parseInt(String(fallback || 0), 10);
        if (!isFinite(parsed) || parsed < 0) {
            parsed = 0;
        }
    }
    return parsed;
}

function GameAnagraficaPage(extension) {
    var page = {
        root: null,
        paginator: null,

        init: function () {
            this.root = document.getElementById('anagrafica-page');
            if (!this.root || typeof globalWindow.Paginator !== 'function') {
                return this;
            }

            var navHost = document.getElementById('anagrafica-pagination');
            if (!navHost) {
                return this;
            }

            var total = toNonNegativeInt(this.root.getAttribute('data-total-count'), 0);
            var currentPage = toPositiveInt(this.root.getAttribute('data-page'), 1);
            var results = toPositiveInt(this.root.getAttribute('data-results'), 20);
            var searchQuery = String(this.root.getAttribute('data-search-query') || '').trim();

            if (total <= results) {
                navHost.classList.add('d-none');
            } else {
                navHost.classList.remove('d-none');
            }

            var paginator = new globalWindow.Paginator();
            paginator.urlupdate = false;
            paginator.range = 2;
            paginator.div = '#anagrafica-pagination';

            paginator.loadData = function (query, nextResults, nextPage) {
                var criteriaQuery = (query && typeof query === 'object') ? query : {};
                var valueQ = Object.prototype.hasOwnProperty.call(criteriaQuery, 'q')
                    ? String(criteriaQuery.q || '').trim()
                    : searchQuery;
                var valueResults = toPositiveInt(nextResults, results);
                var valuePage = toPositiveInt(nextPage, 1);

                var params = new URLSearchParams(globalWindow.location.search || '');

                if (valueQ !== '') {
                    params.set('q', valueQ);
                } else {
                    params.delete('q');
                }

                params.set('page', String(valuePage));
                params.set('results', String(valueResults));

                var target = globalWindow.location.pathname + '?' + params.toString();
                globalWindow.location.href = target;
                return this;
            };

            paginator.setNav({
                query: { q: searchQuery },
                page: currentPage,
                results: results,
                results_page: results,
                orderBy: '',
                tot: { count: total }
            });

            this.paginator = paginator;
            return this;
        }
    };

    var options = (extension && typeof extension === 'object') ? extension : {};
    return Object.assign({}, page, options).init();
}

globalWindow.GameAnagraficaPage = GameAnagraficaPage;

function autoInit() {
    if (globalWindow[AUTO_INIT_FLAG] === true) {
        return;
    }
    if (!document.getElementById('anagrafica-page')) {
        return;
    }
    globalWindow[AUTO_INIT_FLAG] = true;
    globalWindow.Anagrafica = globalWindow.GameAnagraficaPage({});
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', autoInit);
} else {
    autoInit();
}
export { GameAnagraficaPage as GameAnagraficaPage };
export default GameAnagraficaPage;

