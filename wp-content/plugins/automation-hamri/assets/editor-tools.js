/* WP Automator — Author Tools (editor enhancement)
 * Live readiness checklist + client-side "auto-fill empty fields" for the
 * Classic editor. Server-side save is the source of truth; this only mirrors
 * that behaviour in the browser so you see the values before saving. Degrades
 * quietly in the Block editor (server-side auto-fill still runs on save).
 */
(function ($) {
	'use strict';

	var ED = window.wpapEd || { minWords: 150, i18n: {} };
	var I = ED.i18n || {};

	function editor() {
		return (window.tinymce && tinymce.get && tinymce.get('content')) || null;
	}

	function contentText() {
		var e = editor();
		if (e && !e.isHidden()) {
			return e.getContent({ format: 'text' }) || '';
		}
		var $c = $('#content');
		if ($c.length) {
			/* Inert parse: a DOMParser document has no browsing context, so an
			   <img onerror=…> smuggled into post content (e.g. a bulk import that
			   bypassed kses) can't load/execute while we read its text. */
			return (new DOMParser().parseFromString($c.val() || '', 'text/html').body.textContent) || '';
		}
		return '';
	}

	function contentHtml() {
		var e = editor();
		if (e && !e.isHidden()) {
			return e.getContent() || '';
		}
		var $c = $('#content');
		return $c.length ? ($c.val() || '') : '';
	}

	function titleVal() {
		return ($('#title').val() || '').trim();
	}

	var ING_RE = /ingredient/i;
	var STEP_RE = /instruction|direction|step|method|preparation|how to make|how to prepare/i;

	function cleanItem(s) {
		s = (s || '').replace(/\s+/g, ' ').trim();
		s = s.replace(/^\s*(?:\d+\s*[.)\-:]|[\-*•‣◦⁃])\s*/, '');
		return s.trim();
	}

	/* Label of a block if it looks like a section header (h2-h4, or a paragraph
	   that is essentially just a bold word like "Ingredients:"), else null. */
	function labelOf(el) {
		var tag = (el.tagName || '').toLowerCase();
		if (/^h[2-4]$/.test(tag)) { return (el.textContent || '').trim(); }
		if (tag === 'p') {
			var b = el.querySelector('strong, b');
			if (b) {
				var bt = (b.textContent || '').trim();
				var pt = (el.textContent || '').trim();
				if (bt && pt.length <= bt.length + 2) { return bt; }
			}
		}
		return null;
	}

	function itemsFrom(el) {
		var out = [];
		var tag = (el.tagName || '').toLowerCase();
		if (tag === 'ul' || tag === 'ol') {
			Array.prototype.forEach.call(el.querySelectorAll('li'), function (li) {
				var t = cleanItem(li.textContent);
				if (t) { out.push(t); }
			});
		} else if (tag === 'p') {
			var t = cleanItem(el.textContent);
			if (t) { out.push(t); }
		}
		return out;
	}

	/* Pull ingredients + steps out of the post body by scanning for the usual
	   "Ingredients" / "Instructions" section headers and grabbing the list (or
	   paragraphs) that follow. Mirrors the PHP extractor on the server. */
	function extractRecipe(html) {
		var res = { ingredients: [], steps: [] };
		if (!html) { return res; }
		/* Inert parse (no browsing context) so smuggled <img onerror> can't fire. */
		var root = new DOMParser().parseFromString(html, 'text/html').body;
		var kids = Array.prototype.filter.call(root.childNodes, function (n) { return n.nodeType === 1; });
		var target = null;
		for (var i = 0; i < kids.length; i++) {
			var el = kids[i];
			var label = labelOf(el);
			if (label !== null) {
				if (ING_RE.test(label) && !res.ingredients.length) { target = 'ingredients'; }
				else if (STEP_RE.test(label) && !res.steps.length) { target = 'steps'; }
				else { target = null; }
				continue;
			}
			if (target) {
				var items = itemsFrom(el);
				if (items.length) {
					res[target] = res[target].concat(items);
					var t = (el.tagName || '').toLowerCase();
					if (t === 'ul' || t === 'ol') { target = null; }
				}
			}
		}
		return res;
	}

	function words(t) {
		t = (t || '').trim();
		return t ? t.split(/\s+/).length : 0;
	}

	function $f(name) { return $('[name="' + name + '"]'); }
	function val(name) { return ($f(name).val() || '').trim(); }

	function setIfEmpty(name, v) {
		var $e = $f(name);
		if ($e.length && !($e.val() || '').trim() && v) {
			$e.val(v);
			return true;
		}
		return false;
	}

	function hasCategory() {
		return $('#categorychecklist input:checked, #category-all input:checked, [name="tax_input[category][]"]:checked').length > 0;
	}
	function hasTags() {
		if ($('.tagchecklist li').length) { return true; }
		return (($('#tax-input-post_tag').val() || '').trim()).length > 0;
	}
	function hasFeatured() {
		return $('#postimagediv img, #set-post-thumbnail img').length > 0;
	}

	function clip(s, max) {
		s = (s || '').replace(/\s+/g, ' ').trim();
		if (s.length <= max) { return s; }
		var c = s.slice(0, max);
		var i = c.lastIndexOf(' ');
		if (i > max * 0.5) { c = c.slice(0, i); }
		return c.replace(/[\s,.;:—-]+$/, '');
	}

	function autofill() {
		var title = titleVal();
		var body = contentText();
		var did = false;
		did = setIfEmpty('_wpap_ed_seo_title', clip(title, 60)) || did;
		if (body) { did = setIfEmpty('_wpap_ed_meta_desc', clip(body, 155)) || did; }
		did = setIfEmpty('_wpap_ed_img_alt', title) || did;
		did = setIfEmpty('_wpap_ed_img_title', title) || did;

		/* Recipe ingredients/steps pulled from the body (only when flagged a
		   recipe, and only into empty fields). */
		if ($('[name="wpap_recipe_on"]').is(':checked')) {
			var needIng = !val('_wpap_recipe_ingredients');
			var needStep = !val('_wpap_recipe_steps');
			if (needIng || needStep) {
				var rx = extractRecipe(contentHtml());
				if (needIng && rx.ingredients.length) { $f('_wpap_recipe_ingredients').val(rx.ingredients.join('\n')); did = true; }
				if (needStep && rx.steps.length) { $f('_wpap_recipe_steps').val(rx.steps.join('\n')); did = true; }
			}
		}

		render();
		return did;
	}

	function chip(ok, label) {
		return '<span class="wpap-ed-chk ' + (ok ? 'ok' : 'no') + '">' + (ok ? '✓' : '○') + ' ' + label + '</span>';
	}

	function render() {
		var $box = $('#wpap-ed-checklist');
		if (!$box.length) { return; }
		var body = contentText();
		var checks = [
			[!!titleVal(), I.title || 'Title'],
			[words(body) >= (ED.minWords || 150), I.body || 'Enough content'],
			[!!val('_wpap_ed_meta_desc'), I.metaDesc || 'Meta description'],
			[hasCategory(), I.category || 'Category'],
			[hasTags(), I.tags || 'Tags'],
			[hasFeatured(), I.featured || 'Featured image'],
			[!!val('_wpap_ed_img_alt'), I.imageAlt || 'Image alt text']
		];
		var done = 0;
		checks.forEach(function (c) { if (c[0]) { done++; } });
		var html = '<div class="wpap-ed-score">' + done + ' / ' + checks.length + ' ' + (I.ready || 'ready') + '</div>';
		checks.forEach(function (c) { html += chip(c[0], c[1]); });
		$box.html(html);
	}

	$(function () {
		$('#wpap-ed-autofill').on('click', function (e) { e.preventDefault(); autofill(); });
		$('#wpap-ed-prepare').on('click', function (e) { e.preventDefault(); autofill(); render(); });
		$(document).on('input change', '#title, [name^="_wpap_ed_"], #tax-input-post_tag', render);
		$(document).on('change', '#categorychecklist input, #category-all input', render);

		render();

		if (window.tinymce) {
			$(document).on('tinymce-editor-init', render);
			var iv = setInterval(function () {
				var e = editor();
				if (e) {
					e.on('keyup change SetContent', function () { setTimeout(render, 50); });
					clearInterval(iv);
				}
			}, 500);
			setTimeout(function () { clearInterval(iv); }, 8000);
		}
	});
})(jQuery);
