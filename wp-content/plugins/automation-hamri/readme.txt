=== Automation Hamri ===
Contributors: oussamahamri
Tags: content, automation, adsense, seo, publishing
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 9.28.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-assisted bulk content publishing with a Distribution Hub, Google-Sheet automation, an AdSense ad manager, and built-in SEO/structured-data output.

== Description ==

Automation Hamri turns ready-made content into published WordPress posts at scale and keeps the front-end SEO/ads plumbing consistent:

* **Bulk publishing** — Direct Publish from JSON (one item per request for reliability) and a Distribution Hub to manage every published item.
* **Google-Sheet automation** — poll a published-CSV sheet on an hourly cron and drip posts out in submission order, deduplicated so a row is published once and never recreated.
* **AdSense ad manager** — header/sidebar/footer zones, in-content injection with a density guardrail, a live placement preview, an earnings estimator, and a thin-content safety net; ads.txt served from your publisher ID.
* **SEO & structured data** — Open Graph / Twitter cards, meta descriptions, Article + BreadcrumbList JSON-LD, related-posts block, and a theme-independent Recipe card + Recipe schema. Defers automatically to Yoast / Rank Math / AIOSEO / SEOPress / The SEO Framework when one is active.
* **Internal linking** — in-content cross-links that lift crawl depth and pages-per-session: `[[link:slug]]` writer tokens (self-healing across a batch) plus keyword-based auto-linking, both baked in on publish.
* **Instant indexing (IndexNow)** — opt-in (off by default); pings Bing/Yandex on publish once enabled in Settings.

The plugin pairs with the companion "Viral Reader" theme but works on any theme.

== Installation ==

1. Upload the `automation-hamri` folder to `/wp-content/plugins/`, or install the ZIP via Plugins → Add New → Upload.
2. Activate the plugin through the Plugins screen.
3. Configure API keys, ad placements, and (optionally) Google-Sheet automation under the plugin's settings.

Note: on some hosts (OPcache), overwriting the plugin folder in place may not reload changed code — upload as a fresh folder or clear OPcache after an update.

== Frequently Asked Questions ==

= Does it send data anywhere by default? =
No. Instant indexing (IndexNow) is opt-in and off until you enable it. License and image-source requests only run for features you configure.

= Does it require a specific theme? =
No. It renders its own ad zones and SEO/recipe output on any theme, and defers to a dedicated SEO plugin if one is active.

== Changelog ==

= 9.28.1 =
* Fix: the 9.28.0 keyword auto-linker could, within one paragraph, insert a link inside another link's URL (a nested, broken anchor). The linker no longer re-scans text it just linked. A built-in repair pass fixes any posts already affected — just re-open Settings → Internal linking and click "Activate on existing posts" (it repairs first, then re-links cleanly, and reports how many posts it fixed).

= 9.28.0 =
* Internal linking: two in-content cross-linking mechanisms that lift crawl depth and pages-per-session (an SEO / AdSense signal). Writers can drop `[[link:slug]]` or `[[link:slug|anchor text]]` tokens that become real links on publish (and self-heal across a batch when the target is published later). A per-item `keywords` field registers the phrases a post should be linked FROM elsewhere, and an "Auto-link keywords" pass weaves the first mention of each into other posts — bounded, idempotent, and tag-aware (never inside a heading, code, or an existing link). Both run automatically after every bulk publish, and are also available as manual buttons under Settings → Internal linking. Already have a published catalogue? One click on "Activate on existing posts" seeds keyword targets from each post's tags and links everything — no re-publishing.

= 9.27.0 =
* Noindex control: a post can now be kept out of search with an optional `noindex` import field (true/false). It is enforced plugin-agnostically through WordPress core's robots API, so it works even with no SEO plugin installed — and, when a supported SEO plugin (Yoast/Rank Math/SEOPress/The SEO Framework) is active, its own noindex flag is set too so its UI matches. Use it for thin, utility, or near-duplicate posts. Omit it and posts stay indexable as before.

= 9.26.0 =
* SEO compatibility: the writer's meta title/description/keyword are now also written for SEOPress and The SEO Framework (previously only Yoast and Rank Math), so the curated meta is no longer silently lost on those sites.
* Recipe rich results: defer to a dedicated recipe plugin (WP Recipe Maker, Tasty Recipes, WPZOOM, WP Ultimate Recipe) when one is active — no more duplicate recipe card or conflicting Recipe schema. The Recipe JSON-LD now resolves its required image from the featured image, the stored remote image, or the first in-content image, and is skipped entirely when no image exists (never emits an invalid, image-less Recipe).
* Reliability: bulk-publish, ZIP-publish, and Google-Sheet automation acquire their run lock with a true atomic INSERT IGNORE compare-and-swap (WordPress's add_option is INSERT … ON DUPLICATE KEY UPDATE, which could let two simultaneous runs both acquire and double-publish).
* Performance: the Distribution Hub JSON export bulk-primes post meta once instead of a per-row query (thousands fewer queries on a full export).
* Uninstall: opt-in full data wipe — define WPAP_UNINSTALL_PURGE in wp-config.php to also remove the stored API keys, license, and Hub table on delete (off by default; safe for single-copy installs).

= 9.25.1 =
* Fix: the separate Facebook image (9.25.0) is now actually used by the Distribution Hub export on the primary publish path (Direct Publish / bulk / ZIP bundle / Sheet automation). It was stored but the Hub row kept the blog image, so Facebook still received the blog image; the row now carries the Facebook-preferred image (falls back to the blog image when none is given), matching the manual-publish auto-add path.

= 9.25.0 =
* Separate Facebook image: a post can now carry a different image for Facebook than for the blog, via a new optional `fbImage` import field (URL or a path inside the Bulk-ZIP bundle). The Distribution Hub export uses the Facebook image; if none is given, the blog image is used for both. Fully back-compatible.

= 9.24.0 =
* Recipes can now carry a course/meal type ("Main course", "Dessert", "Home remedy") via a new `course` import field (alias `recipeCategory`), emitted as schema.org `recipeCategory` for richer Recipe rich results. Optional; recipes without it are unchanged. (Pairs with Viral Reader 1.9.8.)

= 9.23.1 =
* Fixed double-encoded HTML entities in structured data: category names, titles, author bios, and recipe fields containing "&" or apostrophes (e.g. "Colds & Respiratory", "doesn't") were emitted in JSON-LD as "Colds &amp; Respiratory" / "doesn&#039;t". They now carry clean text in breadcrumb, articleSection, Recipe, and author Person nodes. (Pairs with Viral Reader 1.9.7.)

= 9.23.0 =
* Richer SEO structured data: each post now emits one connected schema graph (WebPage, Article, Author, Publisher, image, and breadcrumb linked by @id) with a real author profile — author page, photo, bio, and website — instead of just a name. Merges cleanly with a site-level Organization/Website graph.
* Curated internal links: a post can specify a "related" list of slugs at import; the related-posts block links those first and fills the rest by category. (Front-end pairs with Viral Reader 1.9.5+.)

= 9.22.1 =
* Hardening for bulk import: the ZIP publisher can no longer double-publish on a retry and cleans up its temp files even after a crash; it parses compact times like "1h30m" correctly; an explicit article/guide/story type is respected (never forced into Recipe markup); ingredients/steps are accepted as a list OR newline text; a recipe with incomplete data publishes as a normal Article instead of broken recipe data; and "Parent > Child" categories are filed accurately.

= 9.22.0 =
* Bulk import now sets the RIGHT schema per content type automatically: an item marked "recipe" (or that includes ingredients and steps) publishes as a real recipe with schema.org/Recipe (ingredients, steps, prep/cook/total times, yield) and a recipe card; guides and stories stay Articles. No more manual editor step for bulk recipes.
* Categories accept a "Parent > Child" path (created automatically), so a flat category list can grow sub-categories without any change. A per-item image alt is honored for the featured image.
* Fully back-compatible: existing title/content/imageUrl/category feeds import exactly as before.

= 9.21.0 =
* Distribution Hub — Import Blog Posts: one click backfills a Hub row for every published post not already tracked (manual + pre-existing articles), and future manual publishes are auto-added, so the Hub mirrors the whole blog. Plus a per-row Posted toggle for one-by-one Facebook tracking.
* Automation — hardier de-duplication: a stable content-hash anchor (survives a Google-Sheet id-column change without re-publishing the archive), durable give-up markers so a permanently-failing row isn't re-attempted every ~120 days, an admin email alert (throttled) when a run can't read the Sheet or every row errors, and a WP-Cron-disabled warning on the plugin's pages.
* Reliability — a re-slugged published post now refreshes its stored Hub/share link (no more 404 links after a permalink edit).
* Optional "Clean media" (Settings → Content options): rename imported images from the post title and strip EXIF/metadata (off by default).

= 9.20.0 =
* Distribution Hub — First-comment templates: a global default (Settings → First-comment text, with a {{link}} token) and a per-post override (the ✏️ Comment button on each Hub row). The export renders the template with each post's real link; with no template the comment stays the bare link. Publishing and bulk-import carry a per-article `comment` template through.
* Distribution Hub — Smart export: 📤 Export page / 📦 Export all, a Not-posted-yet filter, and a ✓ Mark these as posted action that flags an exported batch (drops them from the filter). The JSON export now applies the same Status + Facebook filters as the table (one shared query) and returns page/total metadata.
* Distribution Hub — Bulk-import field mapping is now symmetric with the export (`caption` = the hook, `comment` = the first-comment text), so an exported file re-imports faithfully.
* Bulk ZIP Publish — a new admin page that publishes a whole batch from one uploaded .zip (a posts.json array + the image files it references), with each post's image pulled straight from the zip — no image hosting needed. Requires PHP's ZipArchive; extracts to a private temp dir with path-traversal and zip-bomb guards.

= 9.19.1 =
* Social: posts now emit og:image:width/height (+ og:image:alt) so Facebook shows the image on the very first share instead of a blank card until it re-scrapes.
* Fix: on a manual reinstall/reactivate upgrade, the one-time tombstone migration is now run from activation too — previously it could be skipped, which (on a shared database) could let the automation cron re-create a permanently-deleted post.
* Fix: the ad-zone option is now reliably moved to the autoloaded (fast) path on WordPress 5.8–6.3, not only 6.4+.

= 9.19.0 =
* Scale: the Distribution Hub list and JSON export no longer filesort the whole table on every page — "published first, then newest" is now served by two index-backed (primary-key-ordered) queries, so the Hub stays fast at 100k+ rows. Ordering and pagination are unchanged.
* Scale: automation tombstones (deleted source keys) moved from a single growing option into a dedicated indexed table — recording a deletion is now one O(1) insert and the dedup check is an indexed lookup, instead of unserializing and rewriting a large option on every permanent delete. Existing tombstones are migrated automatically on upgrade.

= 9.18.0 =
* Scale: dedup lookups (`_wpap_source_key`, image imports) are now index-backed — a composite (meta_key, meta_value) index is added to postmeta on upgrade, and image dedup keys on a fixed-length hash so long CDN URLs stay index-covered. Removes full-table meta scans on large sites.
* Scale: orphaned-Hub cleanup now runs a single batched set-based DELETE instead of loading the whole table and one query per row (no more timeouts on large hubs).
* Scale: dashboard stats and the related-posts block are cached (5-min / 12-hour transients, invalidated on publish), the Hub count drops an unnecessary join on the default view, ad code is length-capped at storage, and the automation "seen" map is written once per run instead of per post.
* Internal: the single-file plugin was reorganized into per-concern modules under includes/ (no behaviour change).

= 9.17.0 =
* Security & reliability hardening: SSRF connection-pinning with fail-closed host validation; atomic compare-and-swap publish/automation locks with ownership-guarded release; dedup anchor written atomically; per-item fatal isolation in bulk loops; WebP-decode memory pre-flight; queue-anchored ordered scheduling; front-end i18n.
* IndexNow is now OPT-IN (off by default) — no third-party pings until you enable it.
* Added readme.txt + Requires headers; removed a stale Plugin URI.

= 9.16.0 =
* Ad-zone shortcode + block for placing header/sidebar/footer zones on any theme.

= 9.0.0–9.15.0 =
* Forward-ported ad engine, Distribution Hub tools, live ad preview + earnings estimator, settings backup/restore, dashboard health widget, Gutenberg Author Tools, and the ad-zone shortcode/block onto the stable base.
