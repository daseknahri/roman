/* Viral Reader — front-end JS (no dependencies). */
(function () {
	'use strict';

	var L = window.vrL10n || {};

	/* Polite live region for status messages (created once, reused). */
	var live;
	function announce(msg) {
		if (!live) {
			live = document.createElement('div');
			live.className = 'screen-reader-text';
			live.setAttribute('aria-live', 'polite');
			document.body.appendChild(live);
		}
		live.textContent = '';
		window.setTimeout(function () { live.textContent = msg; }, 30);
	}

	/* Mobile nav toggle. */
	var toggle = document.querySelector('.nav-toggle');
	var nav = document.getElementById('site-nav');
	if (toggle && nav) {
		toggle.addEventListener('click', function () {
			var open = document.body.classList.toggle('nav-open');
			toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
			/* Move focus into the menu on open. #site-nav is BEFORE .nav-toggle in the
			   DOM, so without this a keyboard / Switch Access / linear-swipe user would
			   tab PAST the just-revealed links; the Escape handler restores focus to the
			   toggle on close. Mirrors the search-reveal focus pattern below. */
			if (open) { var firstLink = nav.querySelector('a'); if (firstLink) { firstLink.focus(); } }
		});
		nav.addEventListener('click', function (e) {
			if (e.target.tagName === 'A') {
				document.body.classList.remove('nav-open');
				toggle.setAttribute('aria-expanded', 'false');
			}
		});
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && document.body.classList.contains('nav-open')) {
				document.body.classList.remove('nav-open');
				toggle.setAttribute('aria-expanded', 'false');
				toggle.focus();
			}
		});
	}

	/* Sub-navigation (1.9.31, hardened 1.9.33).
	   Phone (≤900px): ▾ buttons open one branch at a time (siblings close), the open menu is its own scroll panel
	   sized to the screen, and the page behind it doesn't scroll. The branch holding the current page opens itself.
	   Touch screens wider than 900px (no hover): the first tap on a parent opens its panel, a second tap follows the
	   link; a tap elsewhere or Escape closes it. Mouse/keyboard desktops keep hover + focus. */
	if (nav) {
		var mq = window.matchMedia ? window.matchMedia('(max-width: 900px)') : { matches: false };
		var noHover = window.matchMedia ? window.matchMedia('(hover: none)') : { matches: false };
		var uid = 0;
		/* a sub-item with its own sub-items = mega-menu (in case a plugin stripped the class) */
		Array.prototype.forEach.call(nav.querySelectorAll('#site-nav > ul > li'), function (li) {
			if (li.querySelector(':scope > ul > li > ul')) { li.classList.add('vr-mega'); }   /* :scope — plain 'ul ul' also matched through the outer menu */
		});
		var setOpen = function (li, open) {
			li.classList.toggle('is-open', open);
			var b = li.querySelector(':scope > .vr-subnav-toggle');
			if (b) { b.setAttribute('aria-expanded', open ? 'true' : 'false'); }
			var a = li.querySelector(':scope > a');
			if (a && !mq.matches) { a.setAttribute('aria-expanded', open ? 'true' : 'false'); }
			if (!open) { Array.prototype.forEach.call(li.querySelectorAll('.is-open'), function (x) { setOpen(x, false); }); }
		};
		var closeSiblings = function (li) {
			Array.prototype.forEach.call(li.parentNode.children, function (sib) { if (sib !== li && sib.classList.contains('is-open')) { setOpen(sib, false); } });
		};
		var closeAll = function () { Array.prototype.forEach.call(nav.querySelectorAll(':scope > ul > li.is-open'), function (x) { setOpen(x, false); }); };
		Array.prototype.forEach.call(nav.querySelectorAll('.menu-item-has-children'), function (li) {
			var link = li.querySelector(':scope > a');
			var sub = li.querySelector(':scope > .sub-menu');
			if (!link || !sub) { return; }
			if (!sub.id) { sub.id = 'vr-sub-' + (++uid); }
			var b = document.createElement('button');
			b.type = 'button';
			b.className = 'vr-subnav-toggle';
			b.setAttribute('aria-expanded', 'false');
			b.setAttribute('aria-controls', sub.id);
			b.setAttribute('aria-label', (link.textContent || '').trim());
			li.insertBefore(b, sub);
			b.addEventListener('click', function (e) {
				e.stopPropagation();
				var open = !li.classList.contains('is-open');
				if (open) { closeSiblings(li); }
				setOpen(li, open);
				if (open && mq.matches) { window.setTimeout(function () { var r = li.getBoundingClientRect(), n = nav.getBoundingClientRect(); if (r.top < n.top || r.top > n.bottom - 60) { nav.scrollTop += r.top - n.top - 8; } }, 0); }
			});
			/* touch screen, desktop layout: first tap opens, second tap navigates */
			link.addEventListener('click', function (e) {
				if (mq.matches || !noHover.matches) { return; }
				if (li.parentNode !== nav.querySelector(':scope > ul') && !li.classList.contains('vr-mega')) { return; }
				if (!li.classList.contains('is-open')) { e.preventDefault(); e.stopPropagation(); closeSiblings(li); setOpen(li, true); }
			});
		});
		document.addEventListener('click', function (e) { if (!mq.matches && !nav.contains(e.target)) { closeAll(); } });
		document.addEventListener('keydown', function (e) {
			if (e.key !== 'Escape' || mq.matches) { return; }
			var open = nav.querySelector(':scope > ul > li.is-open');
			if (open) { closeAll(); var a = open.querySelector(':scope > a'); if (a) { a.focus(); } }
		});
		/* open the branch that holds the page being read */
		var here = location.href.split('#')[0].replace(/\/$/, '');
		Array.prototype.forEach.call(nav.querySelectorAll('a[href]'), function (a) {
			if (a.href.split('#')[0].replace(/\/$/, '') !== here) { return; }
			a.setAttribute('aria-current', 'page');
			for (var p = a.parentNode.parentNode; p && p !== nav; p = p.parentNode) {
				if (p.classList && p.classList.contains('menu-item-has-children')) { p.classList.add('vr-current-branch'); }
			}
		});
		/* phone: the open menu is a panel that fits the screen */
		var fit = function () {
			if (!mq.matches || !document.body.classList.contains('nav-open')) { nav.style.maxHeight = ''; return; }
			var top = nav.getBoundingClientRect().top;
			nav.style.maxHeight = Math.max(200, window.innerHeight - Math.max(0, top)) + 'px';
		};
		if (toggle) {
			toggle.addEventListener('click', function () {
				if (document.body.classList.contains('nav-open') && mq.matches) {
					Array.prototype.forEach.call(nav.querySelectorAll('.vr-current-branch'), function (li) { setOpen(li, true); });
				}
				window.setTimeout(fit, 0);
			});
		}
		window.addEventListener('resize', fit);
		if (mq.addEventListener) { mq.addEventListener('change', function () { closeAll(); fit(); }); }
	}

	/* Header search reveal (progressive enhancement over the /?s= link). */
	var searchToggle = document.querySelector('[data-search-toggle]');
	var searchWrap = document.querySelector('.header-search');
	if (searchToggle && searchWrap) {
		var searchField = searchWrap.querySelector('input[type=search]');
		var closeSearch = function () {
			searchWrap.classList.remove('is-open');
			searchToggle.setAttribute('aria-expanded', 'false');
		};
		searchToggle.addEventListener('click', function (e) {
			e.preventDefault();
			var open = searchWrap.classList.toggle('is-open');
			searchToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
			if (open && searchField) { searchField.focus(); }
		});
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && searchWrap.classList.contains('is-open')) {
				closeSearch();
				searchToggle.focus();
			}
		});
		document.addEventListener('click', function (e) {
			if (searchWrap.classList.contains('is-open') && !searchWrap.contains(e.target)) {
				closeSearch();
			}
		});
	}

	/* Reading progress (single posts) — compositor transform, no per-frame layout. */
	var bar = document.getElementById('vr-progress');
	var src = document.querySelector('[data-reading-progress-source]');
	if (bar && src) {
		var ticking = false;
		var update = function () {
			var rect = src.getBoundingClientRect();
			var total = rect.height - window.innerHeight;
			var scrolled = Math.min(Math.max(-rect.top, 0), total > 0 ? total : 1);
			var pct = total > 0 ? scrolled / total : 0;
			bar.style.transform = 'scaleX(' + Math.min(1, Math.max(0, pct)).toFixed(4) + ')';
			ticking = false;
		};
		var onScroll = function () { if (!ticking) { window.requestAnimationFrame(update); ticking = true; } };
		window.addEventListener('scroll', onScroll, { passive: true });
		window.addEventListener('resize', onScroll, { passive: true });
		update();
	}

	/* Copy-link + print buttons. */
	document.addEventListener('click', function (e) {
		var copyBtn = e.target.closest ? e.target.closest('[data-copy-url]') : null;
		if (copyBtn) {
			var url = copyBtn.getAttribute('data-copy-url');
			var done = function () {
				copyBtn.setAttribute('data-copied', L.copiedShort || 'Copied'); /* localized tooltip text */
				copyBtn.classList.add('is-copied');
				announce(L.copied || 'Link copied');
				setTimeout(function () { copyBtn.classList.remove('is-copied'); }, 1500);
			};
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(url).then(done, function () { window.prompt(L.copyPrompt || 'Copy this link:', url); });
			} else {
				window.prompt(L.copyPrompt || 'Copy this link:', url);
			}
			return;
		}
		var printBtn = e.target.closest ? e.target.closest('[data-print]') : null;
		if (printBtn) { window.print(); }
	});
})();
