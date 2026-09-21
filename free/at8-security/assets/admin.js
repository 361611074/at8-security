/*
 * At8 Security — settings screen behaviour.
 *
 * Kept in a file rather than printed inline so the markup stays clean and the
 * script is served with a version string. Written in plain ES5 on purpose: no
 * framework, no jQuery dependency, nothing to keep in step with core.
 */
(function () {
	'use strict';

	function panelId(slug) {
		return 'at8sec-panel-' + slug;
	}

	function activate(slug, tabs, panels) {
		var i;

		for (i = 0; i < tabs.length; i++) {
			if (tabs[i].getAttribute('data-at8sec-tab') === slug) {
				tabs[i].classList.add('nav-tab-active');
			} else {
				tabs[i].classList.remove('nav-tab-active');
			}
		}

		for (i = 0; i < panels.length; i++) {
			panels[i].style.display = panels[i].id === panelId(slug) ? '' : 'none';
		}
	}

	function init() {
		var tabs = document.querySelectorAll('[data-at8sec-tab]');
		var panels = document.querySelectorAll('.at8sec-panel');
		var i;

		if (!tabs.length || !panels.length) {
			return;
		}

		for (i = 0; i < tabs.length; i++) {
			tabs[i].addEventListener('click', function (event) {
				var slug = this.getAttribute('data-at8sec-tab');

				event.preventDefault();
				activate(slug, tabs, panels);

				if (window.history && window.history.replaceState) {
					window.history.replaceState(null, '', '#' + slug);
				}
			});
		}

		/*
		 * Reopen the tab named in the URL. WordPress appends its own hash on
		 * some screens, so only a slug we actually know about is honoured.
		 */
		var hash = (window.location.hash || '').replace('#', '');
		if (hash && document.getElementById(panelId(hash))) {
			activate(hash, tabs, panels);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
