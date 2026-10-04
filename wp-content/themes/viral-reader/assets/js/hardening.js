/* Viral Reader — Presentation Hardening JS (opt-in, brand-neutral).
   Injects a per-heading anchor-copy control on singular content. Reuses the
   theme's vrL10n strings for i18n. No dependencies, no jQuery. Paired with
   assets/css/hardening.css and the vr_enable_presentation_hardening filter. */
(function () {
  'use strict';

  var L = (typeof window.vrL10n === 'object' && window.vrL10n) || {};
  var COPIED = L.copied || 'Link copied';

  function ready(fn) {
    if (document.readyState !== 'loading') { fn(); }
    else { document.addEventListener('DOMContentLoaded', fn); }
  }

  function slugify(s) {
    return (s || '')
      .toLowerCase()
      .trim()
      .replace(/[^\w\s-]/g, '')
      .replace(/\s+/g, '-')
      .replace(/-+/g, '-')
      .slice(0, 60);
  }

  function copy(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text);
    }
    return new Promise(function (resolve) {
      var t = document.createElement('textarea');
      t.value = text;
      t.style.position = 'fixed';
      t.style.opacity = '0';
      document.body.appendChild(t);
      t.select();
      try { document.execCommand('copy'); } catch (e) { /* no-op */ }
      document.body.removeChild(t);
      resolve();
    });
  }

  ready(function () {
    var scope = document.querySelector('.entry-content');
    if (!scope) { return; }

    var headings = scope.querySelectorAll('h2, h3');
    if (!headings.length) { return; }

    var toast = document.createElement('div');
    toast.className = 'vr-toast';
    toast.setAttribute('role', 'status');
    toast.setAttribute('aria-live', 'polite');
    toast.textContent = COPIED;
    document.body.appendChild(toast);

    var toastTimer;
    function showToast() {
      toast.classList.add('is-visible');
      clearTimeout(toastTimer);
      toastTimer = setTimeout(function () {
        toast.classList.remove('is-visible');
      }, 1800);
    }

    var used = {};
    headings.forEach(function (h) {
      if (h.querySelector('.vr-anchor')) { return; }

      if (!h.id) {
        var base = slugify(h.textContent) || 'section';
        var id = base, n = 2;
        while (document.getElementById(id) || used[id]) { id = base + '-' + n++; }
        h.id = id;
      }
      used[h.id] = true;

      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'vr-anchor';
      btn.setAttribute('aria-label', L.copyPrompt || 'Copy link to this section');
      btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.07 0l3-3a5 5 0 0 0-7.07-7.07l-1.5 1.5"></path><path d="M14 11a5 5 0 0 0-7.07 0l-3 3a5 5 0 0 0 7.07 7.07l1.5-1.5"></path></svg>';

      btn.addEventListener('click', function (e) {
        e.preventDefault();
        var url = location.origin + location.pathname + '#' + h.id;
        copy(url).then(function () {
          btn.classList.add('is-copied');
          showToast();
          try { history.replaceState(null, '', '#' + h.id); } catch (_) { /* no-op */ }
          setTimeout(function () { btn.classList.remove('is-copied'); }, 1200);
        });
      });

      h.appendChild(btn);
    });
  });

  /* ── Pinterest "Save" on images (opt-in via vr_enable_pinterest_save) ──────
     Adds a hover Save button to the featured image + each content image on
     single posts, opening Pinterest's composer pre-filled with the canonical
     URL, that image, and a keyword description (image alt/title + site name). */
  var CFG = (typeof window.vrHardening === 'object' && window.vrHardening) || {};
  // WP localizes a PHP false as "" and true as "1"; absent config defaults on.
  if (CFG.pinterest !== undefined && !CFG.pinterest) { return; }
  var PIN_SUFFIX = (CFG.pinDescSuffix || '').trim();
  var PIN_SAVE = CFG.pinSave || 'Save';
  var PIN_ARIA = CFG.pinAria || 'Save this image to Pinterest';

  var PIN_SVG = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2C6.48 2 2 6.48 2 12c0 4.24 2.64 7.86 6.36 9.32-.09-.79-.17-2 .04-2.86.19-.78 1.2-4.98 1.2-4.98s-.31-.61-.31-1.52c0-1.42.83-2.48 1.86-2.48.88 0 1.3.66 1.3 1.45 0 .88-.56 2.2-.85 3.42-.24 1.02.51 1.86 1.52 1.86 1.82 0 3.22-1.92 3.22-4.69 0-2.45-1.76-4.17-4.28-4.17-2.91 0-4.62 2.18-4.62 4.44 0 .88.34 1.82.76 2.33.08.1.09.19.07.29-.08.32-.25 1.02-.29 1.16-.05.19-.15.23-.35.14-1.3-.61-2.11-2.5-2.11-4.03 0-3.28 2.38-6.29 6.87-6.29 3.61 0 6.41 2.57 6.41 6.01 0 3.58-2.26 6.47-5.4 6.47-1.05 0-2.04-.55-2.38-1.19l-.65 2.47c-.23.9-.87 2.03-1.3 2.72.98.3 2.01.46 3.09.46 5.52 0 10-4.48 10-10S17.52 2 12 2z"/></svg>';

  ready(function () {
    if (!document.body.classList.contains('single')) { return; }

    var canonEl = document.querySelector('link[rel="canonical"]');
    var canonical = (canonEl && canonEl.href) || location.href.split('#')[0];
    var ogEl = document.querySelector('meta[property="og:image"]');
    var ogImg = (ogEl && ogEl.content) || '';
    var title = (document.title || '').trim();
    if (PIN_SUFFIX) {
      title = title.replace(new RegExp('\\s*[|\\u2013\\u2014-]\\s*' + PIN_SUFFIX.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '.*$'), '').trim();
    }

    // The post's own pin (a tall image made for Pinterest) rides on the featured
    // image as data-pin-media / data-pin-description, set in PHP or below.
    var PIN_MEDIA = CFG.pinMedia || '';
    var PIN_DESC = CFG.pinDescription || '';

    function pinDesc(img) {
      var own = (img.getAttribute('data-pin-description') || '').trim();
      if (own) { return own.slice(0, 480); }
      var alt = (img.alt || '').trim();
      var base = alt || title || 'Tutorial';
      return (PIN_SUFFIX ? base + ' — ' + PIN_SUFFIX : base).slice(0, 480);
    }
    function pinMedia(img) {
      var own = img.getAttribute('data-pin-media');
      if (own) { return own; }
      var m = img.currentSrc || img.src || ogImg;
      if (!m || m.indexOf('data:') === 0) { m = ogImg; }
      return m;
    }
    function makePin(img) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'vr-pin-save';
      btn.setAttribute('aria-label', PIN_ARIA);
      btn.innerHTML = PIN_SVG + '<span></span>';
      btn.querySelector('span').textContent = PIN_SAVE;
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var media = pinMedia(img);
        if (!media) { return; }
        var u = 'https://www.pinterest.com/pin/create/button/?url=' + encodeURIComponent(canonical) +
                '&media=' + encodeURIComponent(media) +
                '&description=' + encodeURIComponent(pinDesc(img));
        window.open(u, 'vrpinit', 'width=760,height=560,scrollbars=yes,resizable=yes');
      });
      return btn;
    }

    // Idempotent so LiteSpeed lazy-load re-processing the featured figure
    // (which can wipe a first-pass button) can't win.
    function injectPins() {
      // Featured image — the best pin. No size gate: a lazy-load placeholder
      // reports ~1px early; pinMedia() reads the real src at click time.
      var host = document.querySelector('.entry-featured-media, .post-thumbnail, .single-featured');
      if (host && !host.querySelector('.vr-pin-save')) {
        var fImg = host.querySelector('img');
        if (fImg && fImg.dataset.noPin === undefined && !fImg.classList.contains('avatar')) {
          if (PIN_MEDIA && !fImg.getAttribute('data-pin-media')) {
            fImg.setAttribute('data-pin-media', PIN_MEDIA);
            if (PIN_DESC) { fImg.setAttribute('data-pin-description', PIN_DESC); }
          }
          host.classList.add('vr-pin-host');
          host.appendChild(makePin(fImg));
        }
      }
      var scope = document.querySelector('.entry-content');
      if (scope) {
        scope.querySelectorAll('img').forEach(function (img) {
          if (img.closest('.vr-pin-wrap') || img.closest('.vr-pin-host')) { return; }
          if (img.dataset.noPin !== undefined || img.classList.contains('avatar')) { return; }
          var w = img.naturalWidth || img.width || 0;
          if (w && w < 200) { return; }
          var wrap = document.createElement('span');
          wrap.className = 'vr-pin-wrap';
          img.parentNode.insertBefore(wrap, img);
          wrap.appendChild(img);
          wrap.appendChild(makePin(img));
        });
      }
    }
    injectPins();
    window.addEventListener('load', injectPins);
    setTimeout(injectPins, 1500);
  });
})();
