const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

var _uploaders = {};
var _imageMime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml', 'image/x-icon'];

function _csrf() {
    var m = document.querySelector('meta[name="csrf-token"]');
    return m ? (m.getAttribute('content') || '') : '';
}

function _updateProgress(target, file) {
    var wrap = document.querySelector('[data-upload-progress="' + target + '"]');
    if (!wrap) { return; }
    var bar = wrap.querySelector('.progress-bar');
    var perc = file && typeof file.getPercLoaded === 'function' ? file.getPercLoaded() : 0;
    if (isNaN(perc) || perc < 0) { perc = 0; }
    wrap.classList.remove('d-none');
    if (bar) { bar.className = 'progress-bar bg-success'; bar.style.width = perc + '%'; bar.textContent = perc + '%'; }
}

function _resetProgress(target) {
    var wrap = document.querySelector('[data-upload-progress="' + target + '"]');
    if (!wrap) { return; }
    var bar = wrap.querySelector('.progress-bar');
    if (bar) { bar.className = 'progress-bar'; bar.style.width = '0%'; bar.textContent = '0%'; }
    wrap.classList.add('d-none');
}

function _setActionMode(target, mode) {
    var btn = document.querySelector('[data-upload-cancel="' + target + '"]');
    if (!btn) { return; }
    if (mode === 'cancel') {
        btn.classList.remove('d-none', 'btn-outline-warning');
        btn.classList.add('btn-outline-danger');
        btn.setAttribute('data-upload-mode', 'cancel');
        btn.textContent = 'Annulla';
    } else if (mode === 'retry') {
        btn.classList.remove('d-none', 'btn-outline-danger');
        btn.classList.add('btn-outline-warning');
        btn.setAttribute('data-upload-mode', 'retry');
        btn.textContent = 'Riprova';
    } else {
        btn.classList.add('d-none');
        btn.removeAttribute('data-upload-mode');
    }
}

function _showTab(wrapEl, tab) {
    wrapEl.querySelectorAll('[data-img-tab-btn]').forEach(function (b) {
        b.classList.toggle('active', b.getAttribute('data-img-tab-btn') === tab);
    });
    wrapEl.querySelectorAll('[data-img-tab-panel]').forEach(function (p) {
        p.style.display = p.getAttribute('data-img-tab-panel') === tab ? '' : 'none';
    });
}

function _finalizeUpload(target, inputEl, previewEl, file) {
    if (!file || !file.token) { return; }
    $.ajax({
        url: '/uploader?action=uploadFinalize&token=' + encodeURIComponent(file.token),
        type: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({ target: target }),
        headers: { 'X-CSRF-Token': _csrf(), 'X-Requested-With': 'XMLHttpRequest' },
        success: function (res) {
            if (res && res.dataset && res.dataset.url) {
                if (inputEl) { inputEl.value = res.dataset.url; }
                if (previewEl) { previewEl.src = res.dataset.url; previewEl.style.display = ''; }
                _resetProgress(target);
                _setActionMode(target, '');
                var dropEl = document.querySelector('[data-upload-drop="' + target + '"]');
                if (dropEl) {
                    var wrapEl = dropEl.closest('[data-img-upload-wrap]');
                    if (wrapEl) { _showTab(wrapEl, 'link'); }
                }
            } else {
                _setActionMode(target, 'retry');
            }
        },
        error: function () { _setActionMode(target, 'retry'); }
    });
}

function _cancelUpload(target) {
    var uploader = _uploaders[target];
    if (!uploader) { return; }
    var btn = document.querySelector('[data-upload-cancel="' + target + '"]');
    var mode = btn ? (btn.getAttribute('data-upload-mode') || '') : '';
    var file = uploader.currentFile;
    if (mode === 'retry' || (file && (file.state === 'error' || file.cancelled))) {
        if (file && typeof uploader.retryFile === 'function') {
            _setActionMode(target, 'cancel');
            uploader.retryFile(file);
        }
        return;
    }
    if (file && typeof file.cancel === 'function') { file.cancel(); }
}

function _buildUploader(target, inputEl, previewEl, dropArea) {
    if (_uploaders[target]) {
        if (dropArea) { _uploaders[target].setDropArea(dropArea); }
        return _uploaders[target];
    }
    var config = {
        url: '/uploader',
        multiple: false,
        autostart: true,
        target: target,
        allowed_mime: _imageMime,
        newFile: function (file) {
            file.onProgress = function () { _updateProgress(target, this); };
            file.onComplete = function () { _finalizeUpload(target, inputEl, previewEl, this); };
            file.onCancel  = function () { _resetProgress(target); _setActionMode(target, ''); };
            file.onError   = function () {
                _setActionMode(target, 'retry');
                var wrap = document.querySelector('[data-upload-progress="' + target + '"]');
                if (wrap) {
                    var bar = wrap.querySelector('.progress-bar');
                    if (bar) { bar.className = 'progress-bar bg-danger'; bar.textContent = 'Errore'; }
                    wrap.classList.remove('d-none');
                }
            };
            return file;
        },
        onAddFile: function (file) { _setActionMode(target, 'cancel'); _updateProgress(target, file); }
    };
    var uploader = globalWindow.Uploader(config);
    _uploaders[target] = uploader;
    if (dropArea) { uploader.setDropArea(dropArea); }
    return uploader;
}

function _initWrap(wrapEl) {
    if (typeof globalWindow.Uploader !== 'function') { return; }
    if (wrapEl.getAttribute('data-img-uploader-bound') === '1') { return; }
    wrapEl.setAttribute('data-img-uploader-bound', '1');

    var linkPanel  = wrapEl.querySelector('[data-img-tab-panel="link"]');
    var inputEl    = linkPanel ? linkPanel.querySelector('input') : null;
    var previewEl  = wrapEl.querySelector('[data-img-preview]') || null;
    var dropEl     = wrapEl.querySelector('[data-upload-drop]');
    if (!dropEl) { return; }
    var target = String(dropEl.getAttribute('data-upload-drop') || '');
    if (!target) { return; }

    wrapEl.querySelectorAll('[data-img-tab-btn]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            _showTab(wrapEl, btn.getAttribute('data-img-tab-btn'));
        });
    });

    if (dropEl.getAttribute('data-uploader-bound') !== '1') {
        dropEl.setAttribute('data-uploader-bound', '1');
        _buildUploader(target, inputEl, previewEl, dropEl);
    }

    var openBtn = wrapEl.querySelector('[data-upload-target="' + target + '"]');
    if (openBtn) {
        openBtn.addEventListener('click', function () {
            _buildUploader(target, inputEl, previewEl, null).open();
        });
    }

    var cancelBtn = wrapEl.querySelector('[data-upload-cancel="' + target + '"]');
    if (cancelBtn) {
        cancelBtn.addEventListener('click', function () { _cancelUpload(target); });
    }
}

function init(container) {
    var root = container || document;
    root.querySelectorAll('[data-img-upload-wrap]').forEach(function (wrapEl) {
        _initWrap(wrapEl);
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { init(document); });
} else {
    init(document);
}

document.addEventListener('show.bs.modal', function (e) { init(e.target); });

var AdminImageUploader = { init: init };

if (typeof window !== 'undefined') {
    globalWindow.AdminImageUploader = AdminImageUploader;
    globalWindow.__admin_image_uploader_loaded = true;
}
export { AdminImageUploader as AdminImageUploader };
export default AdminImageUploader;

