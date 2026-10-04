# Changelog — Automation Hamri (build-v9, the full/active product)

Newest-first. build-v9 is the modular (`includes/*.php`), full-featured product that keeps its
front-end (SEO/ads/recipe). See `readme.txt` for the WordPress-directory changelog.

## 9.42.0

**Bulk ZIP Publish: in-article images from the zip.** Until now only the featured image came from the bundle;
any image inside `content` had to be a public URL (hotlinked). Now every `<img src="images/...">` in a post's
`content` whose src is a RELATIVE path is resolved inside the extracted zip, imported into the Media Library
(attached to the post, not the thumbnail, alt = post title), and its src rewritten to the attachment URL.
No schema change; existing bundles behave exactly as before.

### Added
- `wpap_bundle_sideload_inline_images()`, called after each bundle post is created. Reuses the existing
  safe resolver (`wpap_bundle_resolve_image`: no absolute paths, no `..`, realpath-confined to the extract
  root) and the local importer (`wpap_import_local_image_as_attachment`: real-MIME check, clean-media naming,
  EXIF scrub). The same src used twice imports once. http(s), `data:`, and `//` srcs are untouched.
- A missing, unsafe, or invalid file DROPS that `<img>` (never leaves a broken relative path on a live post)
  and adds a row message. Cap: 30 inline images per post (`wpap_bundle_max_inline_images` filter).
- Result rows carry `inline_images` (count imported). Admin help text documents the feature.
- Content is written back with a direct `$wpdb->update` + `clean_post_cache()` (same as the existing
  `<!--nextpage-->` guard), so page-break markers survive. Lint-tested (php 8.2) + a stubbed functional test.

## 9.41.0

**AI-search discoverability (`/llms.txt` + `/ai.txt`)** — serve the emerging convention AI assistants
(ChatGPT, Claude, Perplexity, Grok) look for, generated from live content. Born here in the engine so every
site on the blog-creator can expose a machine-readable map of itself; mirrors build-final 8.80.0. Opt-in,
**default OFF** — the request hooks register only when the toggle is on.

### Added
- **Setting:** Settings → new **"AI search discoverability"** section → *Serve llms.txt & ai.txt* checkbox
  (`wpap_llms_txt`, stored in `wpap_content_opts`, saved on both the admin and settings-import paths).
- **`includes/seo-schema.php`:** `wpap_llms_txt_serve()` (hooked to `template_redirect` priority 0, registered
  only when enabled) answers:
  - `/llms.txt` — `# <name>`, `> <tagline>`, optional intro (`wpap_llms_intro` filter), each non-empty
    category as `## <name>` with up to 20 posts (`- [title](url): excerpt`), then top-level Pages. Cached in
    the `wpap_llms_txt_cache` transient (12h), flushed on `save_post`.
  - `/ai.txt` — `User-agent: * / Allow: / / Disallow: /wp-admin/`, `Sitemap:` + `Guidance:` → llms.txt
    (`wpap_ai_txt_lines` filter).
- Sends `status_header( 200 )` (WordPress 404s an unknown path before `template_redirect`; crawlers ignore
  404 bodies), `text/plain; charset=utf-8`, `X-Robots-Tag: noindex, follow`, `nocache_headers()`. A real
  static file at the site root always wins. Purge page cache after enabling.

## 9.35.0

**FAQPage schema (opt-in)** — folded up from kepoli's `kepoli-faq-schema.php` mu-plugin into `seo-schema.php` as
a reusable engine capability. `wpap_faq_head()` emits FAQPage JSON-LD built only from a post's own on-page
"<h2>… FAQ …</h2>" + `<h3>Q</h3><p>A</p>` section (additive, no fabrication). **DEFAULT OFF** —
`add_filter( 'wpap_faq_schema_enabled', '__return_true' )` to enable; also self-suppresses under an SEO plugin
and when a site mu-plugin already emits FAQPage (`kepoli_faq_jsonld`), so migrating the capability off the
mu-plugin never double-emits. First of the reusability "fold the generic mu-plugin capabilities up into the
engine" ports; ships dormant (no output change) until a site opts in.

## 9.34.0

**Human-drip scheduling** (ported from build-final). `wpap_compute_schedule()` now accepts a `"drip:N"` window
in addition to a numeric hours window: `wpap_compute_drip_schedule()` queues each post AFTER the latest
already-scheduled one, spaced ~14h-daytime-window / N (jittered) and nudged into 08:00–22:00 site-local, so a
batch fans out into a natural cadence over `ceil(total/N)` days instead of a random cluster or a 3am post — a
more human publish signal for search + AdSense. Chains off the real `future` queue (`wpap_last_scheduled_ts_gmt`),
so it self-corrects as posts publish/are deleted. `wpap_parse_schedule_window()` parses `drip:N` or hours; the
**REST publish endpoint** now takes `schedule_window: "drip:N"`. `wpap_publish_article()` passes the window
through untyped so the string survives. Backward-compatible: numeric windows behave exactly as before. (Admin
Direct-Publish UI still sends hours; a `drip:N` field there is a small follow-up.)

## 9.33.0

**REST publish endpoint** `POST /wp-json/wpap/v1/publish` (ported from build-final): headless/programmatic
publish via a WordPress Application Password (auth = `manage_options`, no anonymous access, no front-end
output — additive). Body `{ items:[contract objects], num_parts?:1-10, schedule_window?:0-168 hrs, category? }`;
each item goes through the same `wpap_publish_article()` full contract as Direct Publish (bodies, `[[link]]`
tokens, keywords, recipe, SEO), with per-item try/catch and a `created/skipped/failed` summary. Closes the
last case where publishing needed a live wp-admin session, enabling fully automated publishing. build-v9 uses
its native flat even-spread scheduling (hours); the build-final `drip:N` humanized cadence is a separate
follow-up (scheduling.php).

## 9.32.0

Settings → AdSense: **rendered the Custom-placements admin UI** (repeatable rows + "Add placement" button).
The model, save handler (`wpap_ads_cust_pos/after/code[]` → `wpap_ads_inject['custom']`, capped at 10),
normalizer, front-end injector and the live-preview JS all already existed — only the markup + the row
add/remove JS were missing, so an entire "place a unit after paragraph N / at top / before related" revenue
lever was unreachable dead code. Each row is `{pos: after|top|before_related, after: 1-50, code}`; blank rows
drop on save; honours the same min-gap/max-ads caps and updates the live preview. Admin-only; no front-end
change.

## 9.31.0

Noindex now also excludes the post from the core XML sitemap. `wpap_sitemap_exclude_noindex` filters
`wp_sitemaps_posts_query_args` so any post carrying `_wpap_noindex` is dropped from `wp-sitemap-posts-post`.
A noindexed URL sitting in the sitemap is self-contradictory — Google reports "Submitted URL marked
'noindex'" in Search Console and wastes crawl budget — so noindex now cleanly implies sitemap-exclusion.
No DB schema change.

## 9.30.0

Apply Rewrites now carries an optional per-item **`noindex`** flag: `{ id, title, content, "noindex": true }`
sets `_wpap_noindex` on that post (false clears it; omit to leave its index state untouched). So a single
upload can both push the rewritten titles/bodies AND thin redundant/duplicate posts out of the indexed set in
one pass — no separate chore. No DB schema change.

## 9.29.0

Apply Rewrites — an in-place content updater for existing posts. No DB schema change (adds a transient
`_wpap_rewrite_backup` post meta, removed on revert).

### Added
- **Settings menu → "Apply Rewrites"** (`includes/apply-rewrites.php`). Upload a JSON array of
  `{ id, title, content }` (aliases `new_title` / `new_html`) and it updates each post's **title + body in
  place** via `wp_update_post()`, under the normal admin session (`manage_options` + nonce) — **no external
  credential, no re-publishing, slugs unchanged**. Purpose: push an edited/rewritten catalogue onto live
  posts without the publisher (which creates new posts) or an app-password round-trip.
- **One-click reversibility.** Before the FIRST overwrite of a post, its original title + content are saved
  to `_wpap_rewrite_backup`; the page's **Revert all** button restores every backed-up post and clears the
  backups. The plugin never edits a post it can't put back.
- After a successful apply it **re-weaves the in-content internal links** (`wpap_internal_links_bake()`),
  since a rewrite drops the old inline links — repairing legacy nested-link damage first — and purges caches.
- Per-item fatal isolation (`try/catch`), 8 MB / 1000-item upload caps, and a results table with live links.

### Fixed
- **Nested-anchor corruption in `wpap_autolink_content()`.** Within a single text run, after the pass
  injected one keyword link it kept matching the *remaining* entries against the **modified** string — so
  a later phrase could match text **inside the just-injected link's `href`** and get nested into it,
  producing broken HTML like `href="…lemon-and-<a href="…">baking</a>-soda-fix/"`. The inner loop now
  scans each text run left-to-right and never re-scans injected markup (`$out`/`$rest` split), so a link
  can never land inside another link's URL. Longest-phrase-first priority is preserved.

### Added
- **`wpap_repair_nested_ilinks()`** — a one-time, idempotent repair that finds the nested-anchor damage
  and collapses the inner anchor back to plain text, restoring the intact outer link. It runs FIRST inside
  `wpap_internal_links_bake()`, so simply re-clicking **Settings → Internal linking → "Activate on existing
  posts"** (or the next bulk publish) both heals the damage and re-links cleanly. The button now also
  reports how many posts were repaired.

## 9.28.0

Internal linking — the passive build's in-content cross-linking engine, ported to the active plugin
(`includes/internal-links.php`). No DB schema change (adds the `_wpap_keywords` post meta).

### Added
- **`[[link:slug]]` / `[[link:slug|anchor text]]` writer tokens.** On publish each becomes a real
  `<a href>` when a **published** post owns that slug; a target not yet live becomes a reader-invisible,
  kses-safe marker (`<a class="wpap-ilink" data-wpap-ilink="slug">` — no href) that the **Resolve
  internal links** pass upgrades once the target goes live, so forward references across a batch
  self-heal. Resolved on the raw body at import (`wpap_resolve_internal_links()`), before the page split,
  with a kses allow-list guard (`wpap_ilink_kses_allow()`) that lets ONLY our own marker attribute
  through and only while the importer is sanitizing.
- **Auto keyword cross-linking.** A per-item **`keywords`** import field (array or comma/newline string,
  ≤12, stored as `_wpap_keywords`) registers the phrases a post should be the link **target** for. The
  **Auto-link keywords** pass (`wpap_auto_keyword_link_run()`) then links the first eligible mention of
  each phrase in **other** posts to that post — bounded (≤4 links/post), idempotent, tag-aware (never
  inside an existing `<a>`, a heading, or `code`/`pre`/`figure`), longest-phrase-first, oldest-post-wins.
- **Auto-run after every bulk publish.** Both Bulk-ZIP Publish and Direct Publish call
  `wpap_internal_links_bake()` at the tail of a successful batch — it upgrades forward-ref markers now
  that the whole batch is live, then weaves keyword links — so links are baked with no manual click.
  Self-isolating (`try/catch \Throwable`): a linking hiccup can never fail a publish that already
  succeeded. The two passes are also exposed as **admin buttons** (Settings → Internal linking) for a
  manual catalogue-wide re-run, mirroring the passive build.

- **Activate on already-published posts (one click).** For a catalogue published *before* this engine
  existed (no `keywords` set → nothing to link to), Settings → Internal linking → **Activate on existing
  posts** runs `wpap_backfill_keywords_from_tags()`: it seeds `_wpap_keywords` on live posts from each
  post's own tags (+ its focus keyword, if an SEO plugin stored one; generic terms stop-listed), then
  bakes the links — no re-publishing. Idempotent and non-destructive: it never overwrites a `keywords`
  list you supplied yourself, so it is safe to re-run.

### Notes
- These are the two IN-CONTENT mechanisms; the curated bottom-of-post `related` widget is unchanged.
- This is NOT the AI link injector (`wpap_inject_internal_links`, generation-time, untouched).

## 9.27.0

Per-post noindex control. No DB schema change.

### Added
- **`noindex` import field** (bool). Marks a post to be kept out of search — for thin, utility, or
  near-duplicate content. Enforced plugin-agnostically via WordPress core's `wp_robots` API
  (`wpap_robots_honor_noindex()` honors a `_wpap_noindex` marker), so it works on a site with **no SEO
  plugin** (adds `noindex` to the page's robots meta). When a supported SEO plugin is active,
  `wpap_set_seo_meta()` also sets that plugin's own noindex flag (Yoast `_yoast_wpseo_meta-robots-noindex`,
  Rank Math `rank_math_robots`, SEOPress `_seopress_robots_index`, TSF `_genesis_noindex`) so its UI
  reflects the state. `wpap_set_seo_meta()` gained a 5th `$args` parameter (`['noindex' => bool]`); it acts
  only when `noindex` is explicitly passed, so an ordinary editor save never touches a post's robots state.
  Omit the field and posts stay indexable exactly as before. (Ports the passive build's noindex enforcement
  to build-v9, the one SEO capability it lacked.)

## 9.26.0

Hardening + SEO/recipe compatibility pass (multi-agent review). No DB schema change.

### Added
- **SEOPress + The SEO Framework meta.** `wpap_set_seo_meta()` now fill-if-empty seeds SEOPress
  (`_seopress_titles_desc`/`_seopress_titles_title`/`_seopress_analysis_target_kw`) and TSF
  (`_genesis_description`/`_genesis_title`) in addition to Yoast + Rank Math. Both are already counted
  by `wpap_seo_plugin_active()` (so the plugin's own `<head>` stays silent on those sites), so without
  these branches the writer's curated meta was silently dropped and the SEO plugin auto-generated a
  generic one. (AIOSEO 4.x stores SEO in its own table, not post meta — a known limitation shared with
  build-final.)
- **Recipe-plugin deference.** New `wpap_recipe_plugin_active()` (WP Recipe Maker / Tasty Recipes /
  WPZOOM / WP Ultimate Recipe); `wpap_recipe_should_render()` returns false when one is active, so
  build-v9 no longer paints a second recipe card or emits a second Recipe JSON-LD (duplicate
  structured data Google Search Console flags). Ported from build-final.
- **Opt-in uninstall purge.** `define( 'WPAP_UNINSTALL_PURGE', true )` in wp-config.php makes uninstall
  drop the Hub table and delete every option (API keys + license included); default-off preserves the
  multi-version-folder-safe behavior.

### Fixed
- **Recipe JSON-LD image.** `wpap_recipe_head()` resolved the required `image` only from the featured
  attachment and still emitted the Recipe block when there was none — invalid structured data, and it
  ignored the external `_wpap_image_url` this pipeline usually uses. It now resolves featured →
  `_wpap_image_url` → first in-content `<img>` (via `set_url_scheme`), and skips the Recipe JSON-LD
  entirely when none resolves (the visible card still renders).
- **Atomic run locks.** New `wpap_atomic_lock_acquire()` uses `INSERT IGNORE` (a true UNIQUE-key CAS).
  The prior `add_option()` acquire is not atomic — WP core runs `INSERT … ON DUPLICATE KEY UPDATE`, so
  two runs straddling a 1-second boundary (both writing `time()`) could both return true and
  double-acquire. Applied to the automation cron lock and both bulk-publish locks (JSON + ZIP).

### Performance
- **Hub export N+1.** `wpap_ajax_export_distribution_json` bulk-primes post meta once
  (`update_meta_cache`) instead of a per-row meta query — thousands fewer queries on the `?all=1`
  (up to 5000-row) export.

## 9.25.1

Fix the 9.25.0 Facebook image on the primary publish path. No DB schema change.

### Fixed
- **FB image is now exported on the `wpap_publish_article` path.** 9.25.0 stored `_wpap_fb_image_url`
  but the Distribution Hub row insert kept `image_url => $image_url` (the blog image), and the export
  reads the row's `image_url` directly — so Direct Publish / bulk / Bulk-ZIP / Sheet-automation posts
  still sent the **blog** image to Facebook and the feature silently no-op'd there. The row now stores
  the Facebook-preferred image (`'' !== $fb_image_url ? $fb_image_url : $image_url`), matching
  `wpap_autoadd_post_to_hub` / `wpap_restore_distribution_row_for_post` (which use `wpap_fb_image_url()`).
  Only affects rows created after the fix; the fallback is unchanged when no `fbImage` is supplied.

## 9.25.0

Separate Facebook image (blog image vs FB image). No DB schema change.

### Added
- **`fbImage` import field** (aliases `fbImageUrl`, `facebook_image`, `fb_image`). A post can carry a
  DIFFERENT image for Facebook than for the blog featured image. A local path inside a Bulk-ZIP
  bundle (e.g. `fb-images/x.jpg`) is sideloaded to a hosted attachment; a remote URL is stored
  as-is. Kept in `_wpap_fb_image_url`. New `wpap_fb_image_url()` helper resolves the Distribution
  Hub export image as **FB image → blog image → featured thumbnail**, so exports/extraction use the
  Facebook image while a single image still serves both when only one is provided. `wpap_publish_article`
  stores it (Direct Publish + Bulk-ZIP, which resolves the zip path to `local_fb_image_path`); the Hub
  "Bulk Import JSON" box prefers `fbImage` for its poster rows. Back-compatible — omit it and nothing
  changes.

## 9.24.0

Recipe `course` → schema.org `recipeCategory`. No DB schema change. (Pairs with viral-reader ≥ 1.9.8.)

### Added
- **`course` recipe field.** A recipe item may now carry a `course` (alias `recipeCategory`) —
  the meal/course type ("Main course", "Dessert", "Home remedy"), which is what schema.org's
  `recipeCategory` means (NOT the blog category). `wpap_publish_article()` stores it as
  `_wpap_recipe_course`, `wpap_recipe_render_data()` exposes it, and both the plugin's Recipe
  renderer and the theme emit `recipeCategory` when present (entity-decoded via `wpap_ld_text`).
  Optional and back-compatible — recipes without a course emit exactly as before. Documented in
  `BULK-IMPORT-CONTRACT.md`; the content-pipeline can fill it from a profile `defaultCourse` /
  `courseByCategory` (the remedies profile defaults ingestible recipes to "Home remedy").

## 9.23.1

Structured-data correctness fix. No DB schema change. (Pairs with viral-reader ≥ 1.9.7.)

### Fixed
- **Double-encoded HTML entities in JSON-LD.** WordPress returns term names, titles, author
  display names, and bios entity-encoded for HTML display (`get_the_title()` on "Honey &
  Pepper" → `"Honey &amp; Pepper"`; a bio's "doesn't" → `"doesn&#039;t"`). The schema builders
  passed that straight to `wp_json_encode`, double-encoding it in the output
  (`"Colds &amp; Respiratory"`, which Google reads as the literal "Colds &amp;
  Respiratory"). Added `wpap_ld_text()` and decode at the JSON-LD boundary for the
  breadcrumb, `articleSection`, WebPage/Article title + description, author `Person`
  (name + bio), and the plugin's own Recipe renderer (name, description, ingredients,
  steps). HTML `<meta>` paths are unaffected — they still run through `esc_attr()`, which
  re-encodes for the HTML context.

## 9.23.0

Optional SEO enhancements. Richer structured data + curated internal linking. No DB schema
change. (Pairs with viral-reader ≥ 1.9.5 for the curated-links front-end.)

### Added
- **Connected schema `@graph` + E-E-A-T author**: the per-post JSON-LD is now one connected
  graph — `WebPage → Article → Person(author) → Organization(publisher)` plus a primary
  `ImageObject` and `BreadcrumbList`, all cross-referenced by `@id`. The author is a real
  **Person** with `url` (author archive), `image` (avatar), `description` (bio) and `sameAs`
  (website) instead of a bare name. The `#organization`/`#website` @ids reuse the site
  convention so they merge with a site-level Organization/WebSite graph when present. The
  Article node is still omitted when a Recipe renders as the primary entity.
- **Curated internal links**: a per-item `related` list of slugs (import field, stored as
  `_wpap_related_manual`) is rendered first by the related-posts block — the companion
  theme's and the plugin's own — with auto-by-category filling any remaining slots. Lets an
  author hand-pick cross-links; absent, behaviour is unchanged.

### Notes
- Deliberately NOT added: **FAQ schema** — Google restricted FAQ rich results to
  authoritative government/health sites in 2023, so it renders no rich result for these
  sites (same reason HowTo was skipped).

## 9.22.1

Hardening pass on the 9.22.0 bulk-import path (now the main publishing tool), from a
security + correctness review (2 lens agents + verification). No schema change, no
front-end change.

### Fixed
- **ZIP publish concurrency + temp cleanup**: the Bulk-ZIP handler now holds the same
  atomic `add_option` CAS lock the JSON path uses, so a double-click / XHR retry can't
  republish the whole bundle; plus a `register_shutdown_function` cleanup of the extract
  dir so an *uncatchable* OOM fatal (thumbnailing a large image) can't leak an extracted
  bundle under uploads.
- **Duration parser**: compact forms like `"1h30m"` / `"2hrs30mins"` no longer drop the
  hours (the `\b` boundary failed before a digit) — now 90 / 150. Spaced/ISO/bare forms
  unchanged.
- **Recipe gating**: an explicit non-recipe `type` (`article`/`guide`/`story`) is now
  authoritative and never gets Recipe markup; `ingredients`/`steps` are accepted as a
  newline STRING as well as an array; `_wpap_recipe_on` is set only when BOTH lists
  survive cleaning, so a partial/dataless recipe publishes as a valid Article instead of
  a broken Recipe; an explicit `total`/`totalTime` is honored.
- **Category hierarchy**: the root-segment lookup is scoped to top-level (a bare
  `"Football"` can't bind to a nested `"Sports > Football"`), and a creation collision
  recovers via the parent-accurate term id WordPress returns in the error.

## 9.22.0

Per-type SEO for bulk import. Bulk-published items now earn the RIGHT schema automatically —
recipes become real recipes, guides/stories stay Articles — and categories can nest. No DB
schema change, no front-end change. Contract: `BULK-IMPORT-CONTRACT.md`.

### Added
- **Recipe schema on bulk import** (`wpap_publish_article`): an item with `type:"recipe"` (or that
  simply carries both `ingredients` and `steps`) now sets the `_wpap_recipe_*` meta, so the theme
  renders the recipe card and emits **schema.org/Recipe** (ingredients, instructions, prep/cook/
  total times, yield) with zero manual editor steps. Previously bulk recipes published as plain
  Articles. Guides/stories stay **Article** (Google retired HowTo rich results in 2023).
- **Duration parser** (`wpap_parse_duration_to_minutes`): accepts `"40 min"`, `"2 hr"`,
  `"1 hr 30 min"`, `"PT1H30M"`, or a bare integer → minutes, which the SEO emitter renders as
  ISO-8601 `PT..H..M`.
- **Hierarchical categories** (`wpap_resolve_category_path`): `category` now accepts a
  `"Parent > Child"` path (each level created lazily) as well as a bare name or numeric id, so the
  flat Recipes/Tips/Stories taxonomy can grow sub-categories with no plugin change.
- **Descriptive featured-image alt**: a per-item `image_alt` overrides the title-derived default
  on the featured attachment (Google Images / accessibility).

### Notes
- All fields are optional and back-compatible: existing `{title, content, imageUrl, category}`
  feeds import exactly as before. The per-item fatal isolation, batch caps, atomic publish lock,
  and content-hash dedup already in the bulk handler still apply.

## 9.21.0

Second parity pass — build-final's safe back-end feature groups (8.50.0). No DB schema change,
no front-end change. (Skipped by design: the FB 1200×630 OG-card head-swap, external-image
thumbnail rendering, and content page-nav — build-v9 keeps its own `wpap_seo_head` front-end and
pairs with the viral-reader theme, which already covers those.)

### Added
- **Import Blog Posts → Hub** (`wpap_ajax_import_all_posts`, 🗂 button): backfills a Hub row for
  every published post not already tracked (idempotent, batched). Plus **auto-add** on future
  publishes (`wpap_autoadd_post_to_hub` on `transition_post_status`), suppressed for the plugin's
  own publishes (they write their own row) via `$GLOBALS['wpap_suppress_hub_autoadd']`.
- **Per-row Posted toggle** (`wpap_ajax_toggle_fb_posted`, ☐/✅ Posted button) for 1:1 Facebook
  tracking, alongside the existing bulk "Mark posted".
- **Automation de-dup hardening**: a stable **content-hash key** `_wpap_source_alt_key`
  (`wpap_automation_content_key` / `wpap_automation_alt_key_is_done`) checked as an additional
  anchor so a Google-Sheet `id`-column change can't re-publish the archive; **durable give-up
  markers** (`wpap_automation_mark_giveup`, `wpap_automation_giveup_keys`) so a 3-strike row isn't
  re-attempted after the seen cache ages out; a throttled admin **email alert**
  (`wpap_automation_alert`) on Sheet-read failure or an all-errored run; and a **WP-Cron-disabled
  warning** on the plugin's pages (`wpap_cron_health_notice`).
- Optional **"Clean media"** (Settings → Content options, off by default): SEO filenames from the
  post title + EXIF/metadata scrub on imported images (`wpap_clean_media_enabled`,
  `wpap_strip_image_metadata`) — applied by both the remote and local sideloaders.

### Changed
- A re-slugged **published** post now refreshes its stored Hub/share link
  (`wpap_sync_distribution_permalink` on `post_updated`, `wpap_refresh_stored_link`), and a
  scheduled post going live re-derives its canonical link — no more 404 share links after a
  permalink edit.

## 9.20.0

Parity port of build-final's 8.43.0–8.49.0 Distribution-Hub work into the modular build.
No DB schema change (reuses `wpap_generated_posts` + post meta). No front-end change.

### Added
- **First-comment templates** for the Distribution Hub. A global default (**Settings →
  First-comment text**, `wpap_content_opts['fb_comment_template']`, with a `{{link}}` token)
  and a per-post override (**✏️ Comment** on each Hub row → `_wpap_fb_comment`, AJAX
  `wpap_save_fb_comment`; blank clears to the global). The export and Hub compose the template
  with each post's real link; with no template the comment stays the bare link (unchanged).
  Helpers `wpap_resolve_fb_template()` / `wpap_compose_fb_comment()` (PHP) and
  `wpapComposeComment()` (JS).
- **Smart export.** **📤 Export page** / **📦 Export all** (every matching row, ~5000 cap →
  `capped` warns), a **Not-posted-yet** filter, a **✓ Mark these as posted** action
  (AJAX `wpap_mark_posted_bulk` → `_wpap_fb_posted`, drops rows from the filter), plus the
  existing Per-page (10/25/50/100) and First/Prev/…/Next/Last + Go-to-page pager.
- **Bulk ZIP Publish** — a new admin page (**WP Automator Pro → Bulk ZIP Publish**,
  `wpap_render_bundle`) that publishes a whole batch from one uploaded `.zip` (a `posts.json`
  array of `{title,content,hook,image}` + the referenced image files), each post's image pulled
  **straight from the zip** — no image hosting/public URLs. New AJAX `wpap_bulk_publish_zip`
  (`manage_options`, `wpap_nonce`, fatal-shielded; requires `ZipArchive`) extracts to a
  **private** temp dir under `wp_upload_dir()` with path-traversal, absolute-path and zip-bomb
  guards (entry-count, ~64 MB upload, uncompressed-size caps), publishes each item via
  `wpap_publish_article`, and always deletes the temp dir. Helpers `wpap_bundle_rrmdir`,
  `wpap_bundle_find_posts_json`, `wpap_bundle_resolve_image`, plus the shutdown fatal shield
  `wpap_ajax_fatal_shield`.
- New publish option `wpap_publish_article( $item, [ 'local_image_path' => … ] )`: a readable
  local file is sideloaded as the featured image via new `wpap_import_local_image_as_attachment()`
  (real-content mime check against jpg/png/gif/webp/avif).

### Changed
- The JSON export and the Hub list now share one WHERE clause (`wpap_distribution_where_clause`)
  so a filtered export always matches the table (Status + the new Facebook filter), and share
  `wpap_distribution_per_page()`. Publish-first ordering stays filesort-free (the two-query helper).
- Bulk-import field mapping is now symmetric with the export (`caption` = the hook,
  `comment` = the first-comment template, with a bare-URL guard), so an exported file re-imports
  faithfully. Publishing stores a per-article `comment` template (`_wpap_fb_comment`) with
  bare-URL and `!== hook` guards.
- Extracted the featured-image wiring shared by the remote and local sideloaders into
  `wpap_apply_featured_attachment()` and the allowed-mime filters into
  `wpap_ensure_image_mime_filters()` — no behavior change to existing publish paths.
  (build-v9 carries no Facebook-card / clean-media / EXIF features, so those build-final
  branches are intentionally omitted.)
