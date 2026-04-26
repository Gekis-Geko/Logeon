const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

// Quando un <select> dentro una form-filtri admin cambia valore,
// dispatcha un evento 'submit' sulla form così ogni modulo
// (che ascolta filtersForm.addEventListener('submit', ...))
// riceve il segnale e ricarica la griglia senza richiedere un click.

var debounceTimers = {};

function debounce(key, ms, fn) {
    if (debounceTimers[key]) { clearTimeout(debounceTimers[key]); }
    debounceTimers[key] = setTimeout(function () {
        debounceTimers[key] = null;
        fn();
    }, ms);
}

function filterFormOf(el) {
    if (!el || !el.closest) { return null; }
    var form = el.closest('form[id$="-filters"]');
    if (!form) { return null; }
    return form;
}

function dispatchSubmit(form) {
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
}

// SELECT → submit immediato
document.addEventListener('change', function (event) {
    var el = event.target;
    if (!el || el.tagName !== 'SELECT') { return; }
    var form = filterFormOf(el);
    if (!form) { return; }
    dispatchSubmit(form);
});

// INPUT[type=search] → submit con debounce 280ms
document.addEventListener('input', function (event) {
    var el = event.target;
    if (!el || el.tagName !== 'INPUT') { return; }
    var type = String(el.type || '').toLowerCase();
    if (type !== 'search') { return; }
    var form = filterFormOf(el);
    if (!form) { return; }
    var key = 'adminfl_' + (form.id || 'form');
    debounce(key, 280, function () { dispatchSubmit(form); });
});

// Quando un input[type=search] viene svuotato via "×" del browser,
// scatta 'search' (non 'input'), gestiamo anche quello
document.addEventListener('search', function (event) {
    var el = event.target;
    if (!el || el.tagName !== 'INPUT') { return; }
    var form = filterFormOf(el);
    if (!form) { return; }
    dispatchSubmit(form);
});

const AdminFilterLiveApi = {
    debounce: debounce,
    filterFormOf: filterFormOf,
    dispatchSubmit: dispatchSubmit
};

export { debounce, filterFormOf, dispatchSubmit };
export default AdminFilterLiveApi;

