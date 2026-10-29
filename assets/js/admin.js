(function () {
	'use strict';

	var reduceMotion = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

	function countUp(node) {
		var target = parseFloat(node.getAttribute('data-countup'));
		if (isNaN(target)) {
			return;
		}

		var decimals = parseInt(node.getAttribute('data-decimals') || '0', 10);
		var suffix = node.getAttribute('data-suffix') || '';
		var finalText = node.getAttribute('data-final') || node.textContent;
		var token = (node.gwipToken || 0) + 1;
		var start = null;
		var duration = 800;

		node.gwipToken = token;
		node.setAttribute('data-final', finalText);

		if (reduceMotion || 0 === target) {
			node.textContent = finalText;
			return;
		}

		function format(value) {
			return value.toLocaleString(undefined, {
				minimumFractionDigits: decimals,
				maximumFractionDigits: decimals
			}) + suffix;
		}

		function frame(now) {
			if (node.gwipToken !== token) {
				return;
			}
			if (null === start) {
				start = now;
			}
			var t = Math.min(1, (now - start) / duration);
			var eased = 1 - Math.pow(1 - t, 3);
			node.textContent = t < 1 ? format(target * eased) : finalText;
			if (t < 1) {
				window.requestAnimationFrame(frame);
			}
		}

		node.textContent = format(0);
		window.requestAnimationFrame(frame);
	}

	function runCountUps(scope) {
		if (scope) {
			[].forEach.call(scope.querySelectorAll('[data-countup]'), countUp);
		}
	}


	function initTabs() {
		var root = document.querySelector('.kwip-wrap');
		var tablist = root && root.querySelector('.kwip-tabs');
		if (!tablist) {
			return;
		}

		root.classList.add('kwip-js');

		var tabs = [].slice.call(tablist.querySelectorAll('[role="tab"]'));
		var indicator = tablist.querySelector('.kwip-tabs__indicator');
		var active = tabs.filter(function (tab) {
			return 'true' === tab.getAttribute('aria-selected');
		})[0] || tabs[0];

		function find(slug) {
			return tabs.filter(function (tab) {
				return slug === tab.getAttribute('data-tab');
			})[0] || null;
		}

		function place() {
			if (!indicator || !active) {
				return;
			}
			indicator.style.width = active.offsetWidth + 'px';
			indicator.style.transform = 'translateX(' + active.offsetLeft + 'px)';
		}

		function activate(slug, history, focus) {
			var tab = find(slug);
			if (!tab) {
				return;
			}
			active = tab;

			tabs.forEach(function (item) {
				var on = item === tab;
				var panel = document.getElementById(item.getAttribute('aria-controls'));

				item.classList.toggle('is-active', on);
				item.setAttribute('aria-selected', on ? 'true' : 'false');
				if (on) {
					item.removeAttribute('tabindex');
				} else {
					item.setAttribute('tabindex', '-1');
				}
				if (panel) {
					panel.hidden = !on;
					if (on) {
						runCountUps(panel);
					}
				}
			});

			place();

			if (tab.scrollIntoView) {
				tab.scrollIntoView({ block: 'nearest', inline: 'nearest' });
			}
			if (window.history) {
				if ('push' === history && window.history.pushState) {
					window.history.pushState({ gwipTab: slug }, '', tab.href);
				} else if ('replace' === history && window.history.replaceState) {
					window.history.replaceState({ gwipTab: slug }, '', tab.href);
				}
			}
			if (focus) {
				tab.focus();
			}
		}

		tablist.addEventListener('click', function (event) {
			var tab = event.target.closest ? event.target.closest('[role="tab"]') : null;
			if (!tab || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
				return;
			}
			event.preventDefault();
			activate(tab.getAttribute('data-tab'), 'push', false);
		});

		tablist.addEventListener('keydown', function (event) {
			var index = tabs.indexOf(document.activeElement);
			if (index < 0) {
				return;
			}
			var rtl = 'rtl' === window.getComputedStyle(tablist).direction;
			var next = null;

			switch (event.key) {
				case 'ArrowRight':
					next = tabs[(index + (rtl ? -1 : 1) + tabs.length) % tabs.length];
					break;
				case 'ArrowLeft':
					next = tabs[(index + (rtl ? 1 : -1) + tabs.length) % tabs.length];
					break;
				case 'Home':
					next = tabs[0];
					break;
				case 'End':
					next = tabs[tabs.length - 1];
					break;
			}
			if (next) {
				event.preventDefault();
				activate(next.getAttribute('data-tab'), 'replace', true);
			}
		});

		document.addEventListener('click', function (event) {
			var link = event.target.closest ? event.target.closest('[data-kwip-goto]') : null;
			if (!link) {
				return;
			}
			event.preventDefault();
			activate(link.getAttribute('data-kwip-goto'), 'push', false);
			window.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });
		});

		window.addEventListener('popstate', function (event) {
			var slug = event.state && event.state.gwipTab ? event.state.gwipTab : (new URLSearchParams(window.location.search).get('tab') || 'overview');
			activate(slug, '', false);
		});

		var form = document.querySelector('.kwip-form');
		var settingsTab = find('settings');
		if (form && settingsTab) {
			form.addEventListener('submit', function () {
				var referer = form.querySelector('input[name="_wp_http_referer"]');
				if (referer) {
					var url = new URL(settingsTab.href, window.location.href);
					referer.value = url.pathname + url.search;
				}
			});
		}

		place();
		window.requestAnimationFrame(function () {
			if (indicator) {
				indicator.classList.add('is-ready');
			}
		});
		window.addEventListener('resize', place);
		if (document.fonts && document.fonts.ready) {
			document.fonts.ready.then(place);
		}
		if (window.history && window.history.replaceState) {
			window.history.replaceState({ gwipTab: active.getAttribute('data-tab') }, '', window.location.href);
		}

		runCountUps(document.getElementById(active.getAttribute('aria-controls')));
	}


	function initForm() {
		var form = document.querySelector('.kwip-form');
		if (!form) {
			return;
		}

		var number = document.getElementById('kwip-quality');
		var range = document.getElementById('kwip-quality-range');
		var presets = [].slice.call(form.querySelectorAll('input[name="gwip_preset"]'));
		var custom = document.getElementById('kwip-custom-note');
		var bar = document.getElementById('kwip-savebar');
		var discard = document.getElementById('kwip-discard');

		function clamp(value) {
			var v = parseInt(value, 10);
			if (isNaN(v)) {
				return null;
			}
			return Math.min(100, Math.max(1, v));
		}

		function snapshot() {
			return new URLSearchParams(new FormData(form)).toString();
		}

		var initial = snapshot();

		function checkDirty() {
			if (bar) {
				bar.classList.toggle('is-visible', snapshot() !== initial);
			}
		}

		function syncPresets() {
			if (!number) {
				return;
			}
			var q = parseInt(number.value, 10);
			var matched = false;

			presets.forEach(function (radio) {
				var match = parseInt(radio.value, 10) === q;
				radio.checked = match;
				matched = matched || match;
			});

			if (custom) {
				var template = custom.getAttribute('data-template');
				custom.hidden = matched || isNaN(q);
				if (template && !isNaN(q)) {
					custom.textContent = template.replace('%d', q);
				}
			}
		}

		function syncQuality() {
			if (number && range) {
				var v = clamp(number.value);
				range.value = null === v ? range.value : v;
			}
		}

		if (number && range) {
			range.addEventListener('input', function () {
				number.value = range.value;
				syncPresets();
			});

			number.addEventListener('input', function () {
				syncQuality();
				syncPresets();
			});

			number.addEventListener('blur', function () {
				var v = clamp(number.value);
				number.value = null === v ? range.value : v;
				range.value = number.value;
				syncPresets();
				checkDirty();
			});

			presets.forEach(function (radio) {
				radio.addEventListener('change', function () {
					if (radio.checked) {
						number.value = radio.value;
						range.value = radio.value;
						syncPresets();
					}
				});
			});
		}

		form.addEventListener('input', checkDirty);
		form.addEventListener('change', checkDirty);

		if (discard) {
			discard.addEventListener('click', function () {
				form.reset();
				window.setTimeout(function () {
					syncQuality();
					syncPresets();
					checkDirty();
				}, 0);
			});
		}
	}

	initTabs();
	initForm();
}());