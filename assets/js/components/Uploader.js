var UPLOADER_DROP_EVENTS_NS = '.uploader_drop';
var uploaderGlobalDropGuardRefCount = 0;
var uploaderGlobalDropHandlers = null;

function uploaderShowDialog(type, body, title) {
	if (typeof Dialog === 'function') {
		Dialog(type || 'warning', {
			title: title || 'Uploader',
			body: '<p>' + String(body || 'Operazione non riuscita.') + '</p>'
		}).show();
		return;
	}
	if (typeof console !== 'undefined' && typeof console.error === 'function') {
		console.error('[Uploader]', body);
	}
}

function uploaderBindGlobalDropGuard() {
	if (uploaderGlobalDropGuardRefCount > 0) {
		uploaderGlobalDropGuardRefCount += 1;
		return;
	}

	if (typeof window.$ !== 'undefined') {
		$(window).on('dragover' + UPLOADER_DROP_EVENTS_NS + ' drop' + UPLOADER_DROP_EVENTS_NS, function (e) {
			e.preventDefault();
		});
		uploaderGlobalDropGuardRefCount = 1;
		return;
	}

	var preventDefault = function (e) {
		e = e || window.event;
		if (e && typeof e.preventDefault === 'function') {
			e.preventDefault();
		}
	};
	window.addEventListener('dragover', preventDefault, false);
	window.addEventListener('drop', preventDefault, false);
	uploaderGlobalDropHandlers = {
		dragover: preventDefault,
		drop: preventDefault
	};
	uploaderGlobalDropGuardRefCount = 1;
}

function uploaderUnbindGlobalDropGuard() {
	if (uploaderGlobalDropGuardRefCount <= 0) {
		uploaderGlobalDropGuardRefCount = 0;
		return;
	}

	uploaderGlobalDropGuardRefCount -= 1;
	if (uploaderGlobalDropGuardRefCount > 0) {
		return;
	}

	if (typeof window.$ !== 'undefined') {
		$(window).off('dragover' + UPLOADER_DROP_EVENTS_NS + ' drop' + UPLOADER_DROP_EVENTS_NS);
		return;
	}

	if (uploaderGlobalDropHandlers) {
		window.removeEventListener('dragover', uploaderGlobalDropHandlers.dragover, false);
		window.removeEventListener('drop', uploaderGlobalDropHandlers.drop, false);
		uploaderGlobalDropHandlers = null;
	}
}

function uploaderBufferToWordArray(buffer) {
	var u8 = new Uint8Array(buffer);
	var words = [];
	for (var i = 0; i < u8.length; i += 4) {
		words.push(
			(u8[i] << 24)
			| ((u8[i + 1] || 0) << 16)
			| ((u8[i + 2] || 0) << 8)
			| (u8[i + 3] || 0)
		);
	}
	return CryptoJS.lib.WordArray.create(words, u8.length);
}

function uploaderFormatBytes(bytes) {
	var size = parseInt(bytes, 10);
	if (!size || size < 1) {
		return '0 B';
	}
	var units = ['B', 'KB', 'MB', 'GB', 'TB'];
	var idx = Math.min(Math.floor(Math.log(size) / Math.log(1024)), units.length - 1);
	var value = size / Math.pow(1024, idx);
	return value.toFixed(1).replace('.0', '') + ' ' + units[idx];
}

function uploaderNormalizeError(error, fallback) {
	if (typeof window !== 'undefined' && window.Request && typeof window.Request.getErrorMessage === 'function') {
		return window.Request.getErrorMessage(error, fallback || 'Operazione non riuscita.');
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

function uploaderRequestUnavailableMessage() {
	if (typeof window !== 'undefined' && window.Request && typeof window.Request.getUnavailableMessage === 'function') {
		return window.Request.getUnavailableMessage();
	}
	return 'Servizio comunicazione non disponibile. Ricarica la pagina e riprova.';
}

function uploaderGetRequestApi() {
	var requestApi = (typeof window !== 'undefined' && window.Request) ? window.Request : ((typeof Request !== 'undefined') ? Request : null);
	if (!requestApi || !requestApi.http || typeof requestApi.http.post !== 'function') {
		return null;
	}
	return requestApi;
}

function uploaderActionCallbacks(action, onSuccess, onError) {
	var actionName = String(action || 'send');
	actionName = actionName.charAt(0).toUpperCase() + actionName.slice(1);
	var callbacks = {};
	callbacks['on' + actionName + 'Success'] = onSuccess;
	callbacks['on' + actionName + 'Error'] = onError;
	callbacks.onError = function (obj, textStatus, errorThrown) {
		if (typeof onError === 'function') {
			onError(obj || errorThrown || textStatus || 'Errore richiesta');
		}
	};
	return callbacks;
}

function uploaderDefaultRequestService(config) {
	return new Promise(function (resolve, reject) {
		var req = config || {};
		var requestApi = uploaderGetRequestApi();
		if (!requestApi) {
			reject(new Error(uploaderRequestUnavailableMessage()));
			return;
		}
		requestApi.http.post(req.url, req.payload || {}).then(function (response) {
			resolve(response || null);
		}, function (error) {
			reject(error || new Error('Errore richiesta.'));
		});
	});
}

function uploaderDefaultChunkUploadService(config) {
	var cfg = config || {};
	if (typeof $ === 'undefined' || typeof $.ajax !== 'function') {
		if (typeof cfg.onError === 'function') {
			cfg.onError(null, 'error', 'jQuery.ajax non disponibile');
		}
		return null;
	}

	return $.ajax({
		url: cfg.url,
		data: cfg.payload,
		cache: false,
		contentType: 'application/octet-stream',
		processData: false,
		dataType: 'json',
		method: 'POST',
		type: 'POST',
		timeout: cfg.timeout,
		headers: cfg.headers || {},
		xhr: function () {
			var xhr = $.ajaxSettings.xhr();
			if (xhr.upload && typeof cfg.onProgress === 'function') {
				xhr.upload.addEventListener('progress', function (e) {
					cfg.onProgress(e);
				}, false);
			}
			return xhr;
		},
		success: function (response) {
			if (typeof cfg.onSuccess === 'function') {
				cfg.onSuccess(response);
			}
		},
		error: function (xhr, textStatus, errorThrown) {
			if (typeof cfg.onError === 'function') {
				cfg.onError(xhr, textStatus, errorThrown);
			}
		}
	});
}

function uploaderResolveServices(services) {
	var resolved = (services && typeof services === 'object') ? Object.assign({}, services) : {};
	if (typeof resolved.notify !== 'function') {
		resolved.notify = function (type, body, title) {
			uploaderShowDialog(type, body, title);
		};
	}
	if (typeof resolved.request !== 'function') {
		resolved.request = uploaderDefaultRequestService;
	}
	if (typeof resolved.chunkUpload !== 'function') {
		resolved.chunkUpload = uploaderDefaultChunkUploadService;
	}
	return resolved;
}

function Uploader(ext) {
	var baseclass = {
		url: null,
		id: null,
		completed: false,
		autostart: true,
		multiple: false,
		target: null,
		max_file_size: null,
		debug: false,
		inputs: [],
		files: [],
		currentFile: null,
		dropArea: null,
		dropAreas: [],
		div: null,
		loaded: 0,
		total: 0,
		initialized: false,
		_complete_emitted: false,
		destroyed: false,
		prevent_window_drop: true,
		_global_drop_guard_bound: false,
		services: null,
		allowed_mime: {
			'image/jpeg': 'JPG',
			'image/gif'	: 'GIF',
			'image/png'	: 'PNG',
		},

		ensureHiddenContainer: function () {
			if (typeof $ === 'undefined') {
				return null;
			}
			if (this.div && this.div.length) {
				return this.div;
			}
			if (this.id == null) {
				this.id = (new Date()).getTime();
			}
			this.div = $("<div>").appendTo("body").css("display", "none").attr("id", "pxwUploader" + this.id);
			return this.div;
		},

		cleanupInputs: function () {
			var valid = [];
			for (var i = 0; i < this.inputs.length; i++) {
				var input = this.inputs[i];
				if (!input || typeof input.length === 'undefined' || !input.length) {
					continue;
				}
				valid.push(input);
			}
			this.inputs = valid;
			return this;
		},

		resetAggregateCompletion: function () {
			this._complete_emitted = false;
			return this;
		},

		init: function () {
			if (this.destroyed) {
				this.emitError('Uploader distrutto.', 'warning');
				return this;
			}
			if (this.initialized === true) {
				return this;
			}

			this.services = uploaderResolveServices(this.services);

			if (this.url == null) {
				this.emitError('URL di upload non configurato.', 'danger');
				return this;
			}

			if (typeof CryptoJS === 'undefined' || !CryptoJS || !CryptoJS.algo || !CryptoJS.lib) {
				this.emitError('Libreria CryptoJS non caricata.', 'danger');
				return this;
			}

			if (typeof $ === 'undefined') {
				this.emitError('Libreria jQuery non caricata.', 'danger');
				return this;
			}

			if (this.prevent_window_drop === true) {
				uploaderBindGlobalDropGuard();
				this._global_drop_guard_bound = true;
			}

			if (this.dropArea != null) {
				this.setDropArea(this.dropArea);
			}

			this.id = (new Date()).getTime();
			this.ensureHiddenContainer();
			this.initialized = true;
			this.resetAggregateCompletion();
			return this;
		},

		open: function () {
			if (!this.initialized) {
				this.init();
			}
			if (!this.initialized) {
				return this;
			}
			if (this.destroyed) {
				this.emitError('Uploader distrutto.', 'warning');
				return this;
			}
			if (!this.ensureHiddenContainer()) {
				this.emitError('Container uploader non disponibile.', 'danger');
				return this;
			}
			this.cleanupInputs();

			var self = this;
			var input = $("<input type='file'>");

			if (this.multiple == true) {
				input.prop("multiple", true);
			}
			var accept = this.getAccept();
			if (accept !== null) {
				input.attr("accept", accept);
			}
			this.div.append(input);

			input.on("change", function (e) {
				for (var i = 0; i < this.files.length; i++) {
					self.addFile(this.files[i]);
				}
				input.remove();
				self.cleanupInputs();
			});

			//scheda l'input
			this.inputs.push(input);

			//ora aprimi! :)
			input.trigger("click");
			return this;
		},

		formatBytes: function (bytes) {
			return uploaderFormatBytes(bytes);
		},

		getAllowedMimeList: function () {
			if (!this.allowed_mime || Object.keys(this.allowed_mime).length === 0) {
				return '';
			}
			var list = [];
			var keys = Object.keys(this.allowed_mime);
			for (var i = 0; i < keys.length; i++) {
				list.push(this.allowed_mime[keys[i]]);
			}
			return list.join(', ');
		},

		validateFile: function (filePointer) {
			if (!filePointer || typeof filePointer.size === 'undefined') {
				return {
					ok: false,
					code: 'file_invalid',
					message: 'File non valido.'
				};
			}

			if (!this.isMimeAllowed(filePointer.type)) {
				var allowed = this.getAllowedMimeList();
				return {
					ok: false,
					code: 'mime_not_allowed',
					message: allowed
						? 'Formato file non supportato. Consentiti: ' + allowed + '.'
						: 'Formato file non supportato.'
				};
			}

			if (this.max_file_size !== null && filePointer.size > this.max_file_size) {
				return {
					ok: false,
					code: 'max_size_exceeded',
					message: 'File troppo grande. Massimo consentito: ' + this.formatBytes(this.max_file_size) + '.'
				};
			}

			return {
				ok: true,
				code: 'ok',
				message: ''
			};
		},

		addFile: function (fp) {
			if (this.destroyed) {
				return this;
			}
			var self = this;
			if (!fp) {
				return this;
			}
			var validation = this.validateFile(fp);
			if (!validation.ok) {
				this.emitError(validation.message, 'warning');
				return this;
			}
			var file = uploaderFile(fp, this.url);
			file.services = this.services;
			file.debug = this.debug;
			file.target = this.target;
			file.state = 'queued';
			file.last_error = null;
			this.log('AddFile:', file);

			//connetto i due eventi onProgress
			file.onProgress = function () {
				self.progress();
			};

			//creo un costruttore personalizzabile
			file = this.newFile(file);
			if (!file) {
				this.emitError('File non valido dopo personalizzazione.', 'warning');
				return this;
			}
			file.services = this.services;
			if (!file.state) {
				file.state = 'queued';
			}

			//aggiungilo al database
			this.files.push(file);
			this.currentFile = file;
			this.resetAggregateCompletion();

			//aggiungi l'autostart
			if (this.autostart) {
				this.log('Autostart attivo');
				file.onAnalyzeComplete = function () {
					self.uploadFile(this);
				};
			}

			//analizza il file
			file.analyze();

			this.updateLoadedBytes();
			this.onAddFile(file);

			return this;
		},

		removeFile: function (index) {
			var file = this.files.splice(index, 1);
			if (!file || file.length === 0) {
				return this;
			}
			if (file[0] && typeof file[0].cancel === 'function' && file[0].completed !== true && file[0].cancelled !== true) {
				file[0].cancel();
			}
			if (file[0] && typeof file[0].revokePreview === 'function') {
				file[0].revokePreview();
			}
			if (this.currentFile === file[0]) {
				this.currentFile = this.files.length ? this.files[this.files.length - 1] : null;
			}
			this.resetAggregateCompletion();
			this.updateLoadedBytes();
			this.onRemoveFile(file[0]);

			return this;
		},
		
		removeAllFiles: function () {
			for (var i = 0; i < this.files.length; i++) {
				var file = this.files[i];
				if (file && typeof file.cancel === 'function' && file.completed !== true && file.cancelled !== true) {
					file.cancel();
				}
				if (file && typeof file.revokePreview === 'function') {
					file.revokePreview();
				}
			}
			this.files = [];
			this.currentFile = null;
			this.resetAggregateCompletion();
			this.updateLoadedBytes();
			this.onRemoveAllFiles();
			return this;
		},

		getFile: function (index) {
			return this.files[index];
		},

		uploadFile: function (file) {
			if (!file || typeof file.upload !== 'function') {
				return this;
			}
			this.resetAggregateCompletion();

			//forzo il cambio di URL prima che parta l'upload
			file.url = this.url;
			file.services = this.services;
			if (typeof file.setState === 'function') {
				file.setState('starting');
			}

			//lancio l'upload
			file.upload();

			return this;
		},

		uploadFileByIndex: function (index) {
			var file = this.getFile(index);
			//bloccare index inesistenti
			if (!file) {
				return this;
			}
			this.uploadFile(file);

			return this;
		},

		uploadAll: function () {
			for (var i = 0; i < this.files.length; i++) {
				this.uploadFile(this.files[i]);
			}
			return this;
		},

		retryFile: function (indexOrFile) {
			var file = indexOrFile;
			if (typeof indexOrFile === 'number') {
				file = this.getFile(indexOrFile);
			}
			if (!file || typeof file.retry !== 'function') {
				return this;
			}
			file.services = this.services;
			this.currentFile = file;
			file.retry();
			return this;
		},

		retryAllFailed: function () {
			for (var i = 0; i < this.files.length; i++) {
				var file = this.files[i];
				if (!file) {
					continue;
				}
				if (file.state === 'error' || file.cancelled === true) {
					this.retryFile(file);
				}
			}
			return this;
		},

		progress: function () {
			this.updateLoadedBytes();
			this.onProgress();
			if (this.total > 0 && this.loaded >= this.total && this._complete_emitted !== true) {
				this._complete_emitted = true;
				this.onComplete();
			}
		},
		
		updateLoadedBytes: function() {
			this.loaded = 0;
			this.total = 0;

			for (var i = 0; i < this.files.length; i++) {
				var file = this.files[i];
				if (!file) {
					continue;
				}
				this.loaded += file.loaded || 0;
				this.total += file.filesize || 0;
			}
			if (this.total === 0 || this.loaded < this.total) {
				this._complete_emitted = false;
			}
		},
		
		getPercLoaded: function() {
			return (this.total>0)
				? Math.ceil(this.loaded * 100 / this.total)
				: 0;
		},

		//HOOKS
		newFile: function (file) {
			//personalizzazione dei metodi di creazione del file
			return file;
		},

		//
		setDropArea: function (div) {
			if (this.destroyed || typeof $ === 'undefined') {
				return this;
			}
			if (!div) {
				return this;
			}
			var self = this;
			var area = $(div);
			if (!area.length) {
				return this;
			}

			area.off('dragover' + UPLOADER_DROP_EVENTS_NS + ' drop' + UPLOADER_DROP_EVENTS_NS);
			area.on('dragover' + UPLOADER_DROP_EVENTS_NS, function (e) {
				e.stopPropagation();
				e.preventDefault();
			});
			area.on('drop' + UPLOADER_DROP_EVENTS_NS, function (e) {
				e.stopPropagation();
				e.preventDefault();

				var originalEvent = e.originalEvent || e;
				var dataTransfer = originalEvent ? originalEvent.dataTransfer : null;
				if (!dataTransfer) {
					return;
				}
				if (dataTransfer.items) {
					for (var i = 0; i < dataTransfer.items.length; i++) {
						if (dataTransfer.items[i].kind === 'file') {
							self.addFile(dataTransfer.items[i].getAsFile());
						}
					}
				} else {
					for (var i = 0; i < dataTransfer.files.length; i++) {
						self.addFile(dataTransfer.files[i]);
					}
				}
			});
			if (this.dropAreas.indexOf(area[0]) === -1) {
				this.dropAreas.push(area[0]);
			}
			
			return this;
		},

		clearDropAreas: function () {
			if (typeof $ === 'undefined') {
				this.dropAreas = [];
				return this;
			}
			for (var i = 0; i < this.dropAreas.length; i++) {
				$(this.dropAreas[i]).off('dragover' + UPLOADER_DROP_EVENTS_NS + ' drop' + UPLOADER_DROP_EVENTS_NS);
			}
			this.dropAreas = [];
			return this;
		},
		
		isMimeAllowed: function (mime) {
			if (!this.allowed_mime || Object.keys(this.allowed_mime).length === 0) {
				return true;
			}
			return !!this.allowed_mime[mime];
		},
		
		getAccept: function () {
			if (!this.allowed_mime || Object.keys(this.allowed_mime).length === 0) {
				return null;
			}
			return Object.keys(this.allowed_mime).join(',');
		},

		onAddFile: function (file) {

		},

		onRemoveFile: function (file) {

		},
		
		onRemoveAllFiles: function () {

		},

		onProgress: function () {

		},

		onComplete: function () {

		},

		onError: function () {

		},

		emitError: function (message, type) {
			var normalizedMessage = uploaderNormalizeError(message, 'Operazione non riuscita.');
			this.onError(normalizedMessage, type || 'error');
			if (this.services && typeof this.services.notify === 'function') {
				this.services.notify(type || 'error', normalizedMessage, 'Uploader');
			} else {
				uploaderShowDialog(type || 'error', normalizedMessage, 'Uploader');
			}
			return this;
		},

		log: function () {
			if (!this.debug) {
				return;
			}
			if (typeof console !== 'undefined' && typeof console.log === 'function') {
				console.log.apply(console, arguments);
			}
		},

		destroy: function () {
			if (this.destroyed) {
				return this;
			}

			this.removeAllFiles();
			this.clearDropAreas();

			for (var i = 0; i < this.inputs.length; i++) {
				if (this.inputs[i] && typeof this.inputs[i].off === 'function') {
					this.inputs[i].off();
					this.inputs[i].remove();
				}
			}
			this.inputs = [];

			if (this.div && this.div.length) {
				this.div.remove();
			}
			this.div = null;

			if (this._global_drop_guard_bound) {
				uploaderUnbindGlobalDropGuard();
				this._global_drop_guard_bound = false;
			}

			this.initialized = false;
			this._complete_emitted = false;
			this.destroyed = true;
			return this;
		},

	};

	var o = Object.assign({}, baseclass, ext);
	o.init();
	return o;
}


function uploaderFile(filePointer, url) {

	var o = {
		url: url,
		file: null,
		filehash: null,
		_filehash: null,
		filesize: 0,
		loaded: 0,

		_preview: "",
		_preview_url: null,
		can_preview: false,
		max_size_for_preview: 5 * 1024 * 1024,

		max_uploads: 5,
		chunk_timeout: 30000,
		chunks_size: 1024 * 1024,
		//chunks_size: 100,
		chunks: [],
		uploads: [],
		activeXhrs: {},
		cancelled: false,
		max_retries: 3,
		stats: {},
		state: 'idle',
		error: null,
		last_error: null,
		services: null,
		log: function () {
			if (!this.debug) {
				return;
			}
			if (typeof console !== 'undefined' && console && console.log) {
				console.log.apply(console, arguments);
			}
		},

		handleReaderError: function (stage) {
			var message = (stage === 'chunk')
				? 'Errore lettura chunk file.'
				: 'Errore lettura file durante analisi.';
			this.emitError(message);
			return this;
		},

		setState: function (state, meta) {
			this.state = String(state || '').trim() || this.state || 'idle';
			if (meta && Object.prototype.hasOwnProperty.call(meta, 'error')) {
				this.error = meta.error;
				this.last_error = meta.error;
			}
			return this;
		},

		request: function (config) {
			if (!this.services || typeof this.services.request !== 'function') {
				return Promise.reject(new Error('Servizio request uploader non disponibile.'));
			}
			return this.services.request(config || {});
		},

		chunkUpload: function (config) {
			if (!this.services || typeof this.services.chunkUpload !== 'function') {
				return null;
			}
			return this.services.chunkUpload(config || {});
		},

		setFile: function (fp) {
			if (this._preview_url && typeof URL !== 'undefined' && typeof URL.revokeObjectURL === 'function') {
				try {
					URL.revokeObjectURL(this._preview_url);
				} catch (e) {}
			}
			this.file = fp;
			this.filesize = this.file.size;
			this.filehash = null;
			this._filehash = CryptoJS.algo.SHA256.create();
			this.chunks_total = Math.ceil(this.file.size / this.chunks_size);
			this.completed = false;
			this.cancelled = false;
			this.activeXhrs = {};
			this.can_preview = false;
			this._preview_url = null;
			this.error = null;
			this.setState('queued');

			this.log("Dimensione File:", this.filesize);
			this.log("Numero totali di chunks:", this.chunks_total);
			this.log("Mime Type:", this.file.type);

			if (this.filesize < this.max_size_for_preview && /^image\/.+$/i.test(this.file.type)) {
				this.can_preview = true;
				try {
					this._preview_url = URL.createObjectURL(this.file);
				} catch (e) {
					this._preview_url = null;
				}
			}

			return this;
		},

		revokePreview: function () {
			if (this._preview_url && typeof URL !== 'undefined' && typeof URL.revokeObjectURL === 'function') {
				try {
					URL.revokeObjectURL(this._preview_url);
				} catch (e) {}
			}
			this._preview_url = null;
			return this;
		},

		setChunk: function (chunk_id, size, hash) {
			this.chunks[chunk_id] = {
				"chunk_id": chunk_id,
				"size": size,
				"hash": hash,
				"status": 0,
				"loaded": 0,
				"retries": 0,
			};
		},

		analyze: function (c) {
			if (this.completed == true) {
				this.log("File gia caricato");
				return;
			}

			if (this.filehash != null) {
				this.log("File gia analizzato");
				return;
			}

			if (c == null) {
				c = 0;
			}
			this.setState('analyzing');

			var self = this;
			var start = this.chunks_size * c;
			var stop = (c + 1 != this.chunks_total)
					? this.chunks_size * (c + 1)
					: this.file.size;


			this.log("Leggo il chunk numero: " + c + " da " + start + " a " + stop);
			var blob = this.file.slice(start, stop);

			var readers = new FileReader();
			readers.onerror = function () {
				self.handleReaderError('analyze');
			};
			readers.onloadend = function (e) {
				if (!e || !e.target || e.target.result == null) {
					return;
				}
				self.log("Letto un nuovo chunk " + c);
				var buffer = e.target.result;
				var wordArray = uploaderBufferToWordArray(buffer);

				self.setChunk(c, stop - start, CryptoJS.SHA256(wordArray).toString());
				self._filehash.update(wordArray);

				//verifico che non sia l'ultimo chunk
				++c;
				if (c >= self.chunks_total) {
					self.filehash = self._filehash.finalize().toString();
					self.setState('analyzed');
					self.onAnalyzeComplete();
					return;
				}

				self.analyze(c);
			};
			readers.readAsArrayBuffer(blob);
		},

		onAnalyzeComplete: function () {
			this.log(this.chunks);
			this.setState('analyzed');
			this.upload();
		},

		getPreview: function () {
			if (this._preview_url) {
				return this._preview_url;
			}
			return null;
		},

		upload: function () {
			if (this.filehash == null) {
				this.emitError('File non ancora analizzato.');
				return;
			}
			if (!this.services || typeof this.services.request !== 'function') {
				this.emitError(uploaderRequestUnavailableMessage());
				return;
			}

			var self = this;
			this.cancelled = false;
			this.error = null;
			this.setState('starting');

			var data = {
				"name": this.file.name,
				"size": this.file.size,
				"type": this.file.type,
				"lastModified": this.file.lastModified,
				"hash": this.filehash,
				"chunks": this.chunks,
				"chunks_total": this.chunks_total,
				"chunk_size": this.chunks_size,
				"target": this.target
			};

			this.request({
				url: this.url + '?action=uploadStart',
				action: 'uploadStart',
				payload: data
			}).then(function (data) {
					self.log("Ricezione del Token", data && data.dataset ? data.dataset.token : '');
					self.token = (data && data.dataset) ? data.dataset.token : null;
					if (data && data.dataset && data.dataset.max_bytes && !isNaN(parseInt(data.dataset.max_bytes, 10))) {
						self.max_file_size = parseInt(data.dataset.max_bytes, 10);
					}
					if (!self.token && !(data && data.dataset && (data.dataset.completed === true || data.dataset.url))) {
						self.emitError('Token upload non ricevuto.');
						return;
					}
					if (data && data.dataset && (data.dataset.completed === true || data.dataset.url)) {
						if (null == self.stats["time_start"]) {
							self.stats["time_start"] = (new Date).getTime();
						}
						self.complete();
						return;
					}
					self.setState('uploading');
					self.uploadHandler();
				}).catch(function (data) {
				self.emitError(uploaderNormalizeError(data, 'Errore avvio upload.'));
			});
		},

		uploadHandler: function () {
			if (this.cancelled) {
				return;
			}
			this.setState('uploading');
			if (null == this.stats["time_start"]) {
				this.stats["time_start"] = (new Date).getTime();
			}

			if (true === this.completed) {
				this.log("File gia caricato");
				return;
			}

			if (this.uploads.length >= this.max_uploads) {
				return;
			}

			var found = false;
			var completed = true;
			var i = 0;
			for (i = 0; i < this.chunks.length; i++) {
				if (!this.chunks[i]) {
					continue;
				}
				if (this.chunks[i].status != 2) {
					completed = false;
				}
				if (this.chunks[i].status == 0) {
					found = true;
					break;
				}
			}

			if (found) {
				this.uploadChunk(i);
				this.uploadHandler();
				return;
			}

			if (completed) {
				this.checkComplete();
				return;
			}
		},

		uploadChunk: function (c) {
			var self = this;

			c = parseInt(c, 10);
			if (isNaN(c)) {
				return;
			}
			var chunk = this.chunks[c];
			if (!chunk) {
				return;
			}

			if (this.cancelled) {
				return;
			}

			//imposta subito in caricamento il blocco
			chunk.status = 1;
			this.uploads.push(c);

			var start = this.chunks_size * c;
			var stop = (c + 1 != this.chunks_total)
					? this.chunks_size * (c + 1)
					: this.file.size;

			var blob = this.file.slice(start, stop);

			var readers = new FileReader();
			readers.onerror = function () {
				self.uploadChunkFailed(c, 'Errore lettura chunk file');
			};
			readers.onloadend = function (e) {
				if (!e || !e.target || e.target.result == null) {
					return;
				}
				var url = self.url + '?action=uploadChunk';
				url += '&token=' + self.token;
				url += '&chunk_id=' + c;

				var meta = document.querySelector('meta[name="csrf-token"]');
				var csrfToken = meta ? meta.getAttribute('content') : '';
				var payload = new Blob([e.target.result]);

				var xhr = self.chunkUpload({
					url: url,
					payload: payload,
					timeout: self.chunk_timeout,
					headers: {
						'X-CSRF-Token': csrfToken
					},
					onSuccess: function (response) {
						var data = response;
						if (typeof response === 'string') {
							try {
								data = JSON.parse(response);
							} catch (e) {
								data = null;
							}
						}
						if (!data) {
							self.uploadChunkFailed(c, 'Risposta non valida');
							return;
						}
						if (data.error) {
							self.uploadChunkFailed(c, data.error);
							return;
						}
						self.uploadChunkCompleted(c, data.dataset || {});
					},
					onError: function (xhr, textStatus) {
						if (textStatus === 'timeout') {
							self.uploadChunkFailed(c, 'Timeout upload');
						} else {
							self.uploadChunkFailed(c, 'Errore upload');
						}
					},
					onProgress: function (event) {
						if (event.lengthComputable) {
							chunk.loaded = event.loaded;
						}
						self.progress();
					}
				});
				if (!xhr) {
					self.uploadChunkFailed(c, 'Trasporto upload non disponibile');
					return;
				}
				self.activeXhrs[c] = xhr;

			};
			readers.readAsArrayBuffer(blob);
		},

		uploadChunkCompleted: function (c, data) {
			this.log("Completato il caricamento del blocco " + c);
			this.setState('uploading');

			if (data.completed == true) {
				this.complete();
			}

			if (this.chunks[c]) {
				this.chunks[c].loaded = this.chunks[c].size;
			}
			this.chunks[c].status = 2;
			var i = this.uploads.indexOf(c);
			if (i > -1) {
				this.uploads.splice(i, 1);
			}
			if (this.activeXhrs[c]) {
				delete this.activeXhrs[c];
			}
			this.uploadHandler();
		},

		uploadChunkFailed: function (c, message) {
			var errorMessage = uploaderNormalizeError(message, 'Errore chunk upload.');
			if (!this.chunks[c]) {
				this.emitError(errorMessage);
				return;
			}
			this.setState('error', { error: errorMessage });
			this.chunks[c].status = 0;
			this.chunks[c].retries = (this.chunks[c].retries || 0) + 1;
			var i = this.uploads.indexOf(c);
			if (i > -1) {
				this.uploads.splice(i, 1);
			}
			if (this.activeXhrs[c]) {
				delete this.activeXhrs[c];
			}
			if (this.chunks[c].retries > this.max_retries) {
				this.emitError(errorMessage || 'Upload fallito. Riprova piu tardi.');
				this.cancel();
				return;
			}
			this.log('Retry chunk ' + c + ' (' + this.chunks[c].retries + '/' + this.max_retries + ')');
			this.uploadHandler();
		},

		progress: function () {
			var loaded = 0;

			for (var i = 0; i < this.chunks.length; i++) {
				if (!this.chunks[i]) {
					continue;
				}
				loaded += this.chunks[i].loaded;
			}

			this.loaded = loaded;
			this.onProgress();
		},
		
		getPercLoaded: function() {
			return (this.filesize>0)
				? Math.ceil(this.loaded * 100 / this.filesize)
				: 0;
		},

		checkComplete: function () {
			var self = this;
			if (!this.services || typeof this.services.request !== 'function') {
				self.emitError(uploaderRequestUnavailableMessage());
				return;
			}

			this.request({
				url: this.url + "?action=uploadCheck&token=" + this.token,
				action: 'uploadCheck',
				payload: {}
			}).then(function (data) {
					if (data && data.dataset && data.dataset.completed == true) {
						self.complete();
					}
				}).catch(function (error) {
				self.emitError(uploaderNormalizeError(error, 'Verifica upload fallita.'));
			});
		},

		complete: function () {
			if (this.completed == true) {
				this.log("complete() gia eseguito");
				return this;
			}

			this.log("Eseguo complete()");

			this.completed = true;
			this.error = null;
			this.setState('completed');
			if (null == this.stats["time_end"]) {
				this.stats["time_end"] = (new Date).getTime();
			}

			this.revokePreview();
			this.onComplete();
			return this;
		},

		cancel: function () {
			if (this.cancelled) {
				return this;
			}
			this.cancelled = true;
			this.setState('cancelled');
			var xhrKeys = Object.keys(this.activeXhrs || {});
			for (var keyIndex = 0; keyIndex < xhrKeys.length; keyIndex++) {
				var key = xhrKeys[keyIndex];
				if (this.activeXhrs[key] && typeof this.activeXhrs[key].abort === 'function') {
					this.activeXhrs[key].abort();
				}
			}
			this.activeXhrs = {};
			this.uploads = [];
			for (var i = 0; i < this.chunks.length; i++) {
				if (!this.chunks[i]) {
					continue;
				}
				this.chunks[i].status = 0;
				this.chunks[i].loaded = 0;
			}
			this.loaded = 0;
			if (this.token && this.services && typeof this.services.request === 'function') {
				this.request({
					url: this.url + '?action=uploadCancel&token=' + this.token,
					action: 'uploadCancel',
					payload: {}
				}).catch(function () {});
			}
			this.revokePreview();
			this.onCancel();
			return this;
		},

		retry: function () {
			if (this.completed === true) {
				return this;
			}

			this.cancelled = false;
			this.error = null;
			this.loaded = 0;
			this.uploads = [];
			this.activeXhrs = {};

			for (var i = 0; i < this.chunks.length; i++) {
				if (!this.chunks[i]) {
					continue;
				}
				this.chunks[i].status = 0;
				this.chunks[i].loaded = 0;
				this.chunks[i].retries = 0;
			}

			this.setState('queued');

			if (this.filehash == null) {
				this.chunks = [];
				this._filehash = CryptoJS.algo.SHA256.create();
				this.analyze(0);
				return this;
			}

			this.upload();
			return this;
		},

		onProgress: function () {
			//HOOK DA PERSONALIZZARE
			this.log(this.getPercLoaded() + "% - Caricati " + this.loaded + " bytes su " + this.filesize + " bytes");
		},

		onComplete: function () {
			//HOOK DA PERSONALIZZARE
			var ms = (this.stats.time_end - this.stats.time_start) / 1000;
			var mbs = (this.file.size * 8 / (1024 * 1024)) / ms;
			this.log("Caricamento avvenuto in " + ms + " secondi");
			this.log("Velocita di caricamento " + mbs + " mbit/sec");
		},

		onCancel: function () {
			//HOOK DA PERSONALIZZARE
		},

		onError: function () {
			//HOOK DA PERSONALIZZARE
		},

		emitError: function (message) {
			var msg = uploaderNormalizeError(message, 'Errore upload.');
			this.error = msg;
			this.setState('error', { error: msg });
			this.onError(msg);
			if (this.services && typeof this.services.notify === 'function') {
				this.services.notify('danger', msg, 'Uploader');
				return;
			}
			uploaderShowDialog('danger', msg, 'Uploader');
			return this;
		},

	};

	return o.setFile(filePointer);
};


if (typeof window !== 'undefined') {
    window.Uploader = Uploader;
}
