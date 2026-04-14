function SlideShow(element, extension) {
	let base = {
		element: element,
		_elementObj: null,
		slideSelector: '.slide',
		pauseOnHover: true,
		autoplay: true,
		isMounted: false,

		transitionSpeed: 5000,
		animationSpeed: 600,
		animationStep: 0.02,
		startZindex: 0,

		slides: [],
		currentSlide: 0,

		intervalSlide: null,

		resolveElement: function () {
			if (this.element && this.element.jquery) {
				this._elementObj = this.element.first();
				return this._elementObj;
			}

			this._elementObj = $(this.element || []);
			if (!this._elementObj.length) {
				this._elementObj = $();
			}
			return this._elementObj;
		},

		hasSlides: function () {
			return Array.isArray(this.slides) && this.slides.length > 0;
		},

		normalizeIndex: function (index) {
			var total = this.slides.length;
			var parsed = parseInt(index, 10);
			if (!total) {
				return 0;
			}
			if (isNaN(parsed)) {
				return 0;
			}

			parsed = parsed % total;
			if (parsed < 0) {
				parsed = total + parsed;
			}
			return parsed;
		},

		collectSlides: function () {
			var self = this;
			this.slides = [];

			if (!this._elementObj || !this._elementObj.length) {
				return this.slides;
			}

			this._elementObj.children().each(function (k, v) {
				var $child = $(v);
				if ($child.is(self.slideSelector)) {
					self.slides.push($child);
				}
			});

			this.currentSlide = this.normalizeIndex(this.currentSlide);
			return this.slides;
		},

		notifyEmptySlides: function () {
			if (typeof window !== 'undefined' && typeof window.Dialog === 'function') {
				var dialog = window.Dialog('default', { title: 'SlideShow', body: '<p>Non ci sono slide da visualizzare.</p>' });
				if (dialog && typeof dialog.show === 'function') {
					dialog.show();
					return;
				}
			}

			if (typeof console !== 'undefined' && typeof console.warn === 'function') {
				console.warn('[SlideShow] Nessuna slide disponibile.');
			}
		},

		bind: function () {
			var self = this;
			if (!this._elementObj || !this._elementObj.length) {
				return this;
			}

			this.unbind();

			if (this.pauseOnHover !== true) {
				return this;
			}

			this._elementObj.on('mouseenter.slideshow', function () {
				self.stop();
			});
			this._elementObj.on('mouseleave.slideshow', function () {
				self.restart();
			});

			return this;
		},

		unbind: function () {
			if (this._elementObj && this._elementObj.length) {
				this._elementObj.off('.slideshow');
			}
			return this;
		},

		init: function () {
			if (null == this.element) {
				return false;
			}

			this.resolveElement();
			if (!this._elementObj.length) {
				return false;
			}

			if (this._elementObj.children().length == 0) {
				this.notifyEmptySlides();
				return false;
			}

			this.stop();
			this.collectSlides();
			if (!this.hasSlides()) {
				this.notifyEmptySlides();
				return false;
			}

			this.bind();
			this.animation();
			this.start();
			this.isMounted = true;
			return this;
		},

		start: function () {
			var self = this;
			if (this.autoplay !== true) {
				return this;
			}
			if (!this.hasSlides() || this.slides.length <= 1) {
				return this;
			}
			if (this.intervalSlide != null) {
				return this;
			}

			this.intervalSlide = setInterval(function () {
				self.next();
			}, this.transitionSpeed);
			return this;
		},

		stop: function () {
			if (this.intervalSlide != null) {
				clearInterval(this.intervalSlide);
				this.intervalSlide = null;
			}
			return this;
		},

		restart: function () {
			this.stop();
			this.start();
			return this;
		},

		next: function () {
			if (!this.hasSlides()) {
				return this;
			}
			var nextSlide = this.currentSlide + 1;
			this.currentSlide = this.normalizeIndex(nextSlide);
			this.animation();
			return this;
		},

		prev: function () {
			if (!this.hasSlides()) {
				return this;
			}
			var prevSlide = this.currentSlide - 1;
			this.currentSlide = this.normalizeIndex(prevSlide);
			this.animation();
			return this;
		},

		goTo: function (index) {
			if (!this.hasSlides()) {
				return this;
			}
			this.currentSlide = this.normalizeIndex(index);
			this.animation();
			this.restart();
			return this;
		},

		animation: function () {
			if (!this.hasSlides()) {
				return this;
			}

			for (var i = 0; i < this.slides.length; i++) {
				var $slide = this.slides[i];
				if (!$slide || typeof $slide.stop !== 'function') {
					continue;
				}

				$slide.stop(true, true);
				if (this.currentSlide === i) {
					$slide.fadeIn(this.animationSpeed);
				} else {
					$slide.fadeOut(this.animationSpeed);
				}
			}
			return this;
		},

		destroy: function () {
			this.stop();
			this.unbind();
			this.isMounted = false;
			return this;
		}
	};

	let o = Object.assign({}, base, extension);
	return o.init();
};

if (typeof window !== 'undefined') {
    window.SlideShow = SlideShow;
}
