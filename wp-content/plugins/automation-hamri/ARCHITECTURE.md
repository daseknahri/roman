# Automation Hamri — architecture

`wp-automator-pro.php` was historically a single ~6,900-line file. As of **9.18.0** it is
split into a slim bootstrap plus per-concern modules under `includes/`. The split is a
**verbatim, order-preserving extraction** — every function kept its exact body; the only
new code is the `require_once` block and each module's `<?php`/ABSPATH guard. Behaviour is
identical (verified by byte-exact reconstruction, `php -l` on all files, and a live
activation + front-end smoke test).

## Load model

`wp-automator-pro.php` defines the plugin header, all `WPAP_*` constants, licensing, the
HTTP/SSRF transport layer, activation/DB-upgrade, and the admin-menu registration — then
`require_once`s each module (in the order below). Every module **self-registers its own
hooks** (`add_action`/`add_filter`/`add_shortcode`) next to the handler, so load order only
matters for constants (defined before the requires) — never for hook callbacks, which fire
after the full plugin is loaded.

## Modules

| File | Concern |
|------|---------|
| `wp-automator-pro.php` | Bootstrap: header, constants, **licensing**, HTTP/SSRF transport (`wpap_safe_remote_get`, TLS/redirect pinning), rate limits, `wpap_create_table` / `wpap_ensure_meta_dedup_index` / `wpap_maybe_upgrade_db` / `wpap_activate`, admin-menu + asset registration, and the module `require` block. |
| `includes/admin.php` | Admin UI — dashboard shell + the full settings page (with its inline preview/estimator JS). |
| `includes/scheduling.php` | Queue-anchored ordered scheduling, public permalink resolution, content→pages splitting. |
| `includes/media.php` | Image upload fallback, WebP conversion (memory pre-flight), **SSRF-guarded** remote image import + dedup, per-hop-safe download. |
| `includes/publishing.php` | The **bulk-import publishing pipeline** (the product's main tool): `wpap_publish_article` (one ready-made item → post, with per-type schema routing, `Parent > Child` category resolution, featured image, SEO meta, curated links), the JSON bulk handler (`wpap_ajax_bulk_publish_posts`) and the **Bulk-ZIP** handler (`wpap_ajax_bulk_publish_zip` — upload→extract→publish with zip-slip/zip-bomb guards + a concurrency lock), JSON export, and `wpap_ajax_process_title`. Spec: `BULK-IMPORT-CONTRACT.md`. ⚠️ **`process_title` nests the AI hook/title generators (`wpap_generate_hook_via_ai`, `wpap_build_clean_hook`) — do NOT edit the AI portions.** |
| `includes/internal-links.php` | In-content internal linking: `[[link:slug\|anchor]]` writer-token resolution (`wpap_resolve_internal_links`) with a self-healing forward-ref marker + kses allow-list guard, and auto keyword cross-linking (`_wpap_keywords` → `wpap_keyword_index` / `wpap_autolink_content` / `wpap_auto_keyword_link_run`). Baked at publish via `wpap_internal_links_bake()` (auto-run after both bulk paths) and exposed as two admin buttons. NOT the AI link injector. |
| `includes/distribution.php` | Distribution Hub list/stats queries, delete/bulk-delete/cleanup, row lifecycle on trash/untrash/delete, cache purge, image proxy. |
| `includes/automation.php` | Google-Sheet CSV automation cron (fetch/parse/dedup/run), SSRF IP helpers, lock ownership release. |
| `includes/ai-content.php` | ⚠️ **AI content + image generation pipeline — do NOT edit.** `wpap_generate_content(_gemini)`, markdown/cleanup, `wpap_generate_image_*`, `wpap_save_image_to_library`. |
| `includes/seo-schema.php` | Front-end `wp_head`: OG/Twitter + meta description, and a **connected schema `@graph`** (WebPage → Article → Person author → Organization publisher, plus a primary ImageObject + BreadcrumbList, all `@id`-linked). Recipe card + Recipe schema when a post carries `_wpap_recipe_*`. Related-posts block (curated `related` first, else auto-by-category). Defers to a dedicated SEO plugin, and defers the recipe card / related UI to the companion theme, when present. |
| `includes/ads.php` | `ads.txt`, IndexNow (opt-in), ad zones (shortcode + block), header/footer ad output, in-content ad injection with density guard + storage-time length cap. |
| `includes/settings-io.php` | Settings export/import (secrets excluded) + the admin dashboard health widget. |
| `includes/editor-tools.php` | Gutenberg "Author Tools" — meta box + derived-field save (`wpap_ed_*`). |

## Do-not-touch (AI generation pipeline)

By project constraint the AI generation code is off-limits. It lives in
`includes/ai-content.php` (entirely) and inside `wpap_ajax_process_title` in
`includes/publishing.php` (the nested `wpap_generate_hook_via_ai` / `wpap_build_clean_hook`).
Edit everything else freely.

## Scale notes (9.18.0)

- Dedup lookups (`_wpap_source_key`, image imports keyed on `_wpap_source_image_hash`) are
  index-backed by a composite `wp_postmeta (meta_key(32), meta_value(191))` index added on
  upgrade (`wpap_ensure_meta_dedup_index`, idempotent + best-effort).
- Orphan-Hub cleanup is a single batched set-based `DELETE` (no fetch-all + N+1).
- Dashboard stats (5-min) and the related-posts block (12-h) are transient-cached.
  Because a related block embeds *other* posts, invalidation is site-wide: any plugin-post
  publish/trash/delete calls `wpap_invalidate_content_caches()`, which clears the dashboard
  transient and bumps `wpap_rel_ver` (folded into every related cache key), so every block
  recomputes at once — no stale links to removed posts.
- The `wpap_automation_seen` map is written once per cron run; ad code is length-capped at
  storage; the Hub count drops its `wp_posts` join on the default (unfiltered) view.

### Extreme-scale items (implemented in 9.19.0)

- **Hub list ordering** no longer filesorts a computed joined-column expression. `wpap_hub_ordered_rows()`
  (distribution.php) fetches "published first, then newest" as two segments, each ordered by the
  primary key `t.id` (index-backed, no filesort), with straddle math preserving the exact
  pagination. Used by both the Hub list and the JSON export. (Chosen over denormalizing
  `post_status` into the Hub table, which couldn't be kept correct without editing the off-limits
  AI handler where one of the row-inserts lives.)
- **Tombstones** moved from the O(n) `wpap_automation_deleted_keys` option to the indexed
  `wpap_tombstones` table (`WPAP_TOMB_TABLE`): recording a deletion is one `INSERT IGNORE`
  (`wpap_automation_tombstone_key`), the dedup check is an indexed `SELECT`
  (`wpap_automation_key_is_done`), and existing tombstones backfill once on upgrade
  (`wpap_migrate_tombstones_to_table`, DB v4). The legacy option is left in place for rollback.

## Bulk-import publishing pipeline (the main tool)

A batch of **ready-made** items is published with no AI call at import time; each item is one
post. Entry points (`includes/publishing.php`, all `manage_options` + nonce):

- **`wpap_ajax_bulk_publish_posts`** — a JSON array (or `{items:[…]}`) pasted in Direct Publish.
- **`wpap_ajax_bulk_publish_zip`** — an uploaded `.zip` bundle (`posts.json` + `images/`, images
  referenced by in-zip path). Extracts to a private temp dir under `uploads/` (`Deny from all`)
  with: a per-entry name guard (absolute / `..` / drive-letter / NUL rejected), an extension
  allowlist (json + images only), entry-count + uncompressed-size caps (zip-bomb), a
  `realpath`-inside-root check on every resolved image, an atomic `add_option` CAS **concurrency
  lock** (a retry can't double-publish the bundle), and a `register_shutdown_function` cleanup so
  an uncatchable OOM can't leak the extract dir.
- **`wpap_ajax_bulk_import_distribution`** — lightweight Facebook-distribution rows (hook + link +
  image), no article body.

The first two funnel each item into **`wpap_publish_article($item, $opts)`**, which:

1. Inserts the post — title, `kses`'d content split into `parts`, `post_excerpt` = meta description.
2. **Category** — `wpap_resolve_category_path`: a bare name, a numeric id, or a `Parent > Child`
   path (each level created lazily; the root segment is scoped to top-level so a bare name can't
   bind to a nested same-name term).
3. **Featured image** — local (bundle) or remote (SSRF-guarded) sideload, with a descriptive
   per-item `image_alt` overriding the title default.
4. **Per-type schema routing** — `type:"recipe"` (or ingredients+steps present, *unless* an
   explicit `article`/`guide`/`story` type says otherwise) → `_wpap_recipe_*` meta (ingredients,
   steps, `wpap_parse_duration_to_minutes` on prep/cook/total, yield) → **schema.org/Recipe**.
   Requires BOTH lists to survive or it falls back to a valid **Article** (never a broken Recipe).
   Everything else → Article.
5. **SEO + linking** — `wpap_set_seo_meta` (description/title/keyword), tags (≤15), curated
   internal links (`_wpap_related_manual`), `_wpap_smart_link`, content-hash dedup anchors, and
   the Distribution-Hub row.

The item shape is the single source of truth in **`BULK-IMPORT-CONTRACT.md`**, shared with the
content-generation source (which supplies *raw material*; category/type/recipe/alt enrichment is
done in the processing layer, not the generator).

## SEO & structured data

Emission is layered so each part is self-contained and defers cleanly:

- **Plugin** (`seo-schema.php`, on `_wpap_smart_link` posts): OG/Twitter + meta description, and a
  connected `@graph` — WebPage → Article → **Person** (author, with `url`/avatar/`description`/
  `sameAs` for E-E-A-T) → **Organization** (publisher) + primary ImageObject + BreadcrumbList,
  all `@id`-linked. The `#organization` / `#website` `@id`s reuse the site convention so they
  **merge** with a site-level Organization/WebSite graph. The Article node is omitted when a
  Recipe will render as the page's primary entity.
- **Theme** (companion viral-reader): the Recipe card + Recipe JSON-LD from `_wpap_recipe_*`, and
  the related-posts UI — the plugin defers both to the theme when its functions exist.
- **Site level** (optional per-site mu-plugin): Organization + WebSite (SearchAction); merges with
  the plugin graph by shared `@id`.
- **Deferral**: with a dedicated SEO plugin (Yoast / Rank Math / AIOSEO / SEOPress / SEO Framework)
  active, the plugin's meta/OG/schema step stands aside entirely to avoid duplication.

Deliberately **not** emitted: **HowTo** and **FAQ** schema — Google retired HowTo (2023) and
restricted FAQ rich results to authoritative government/health sites (2023), so neither renders a
rich result for a typical site.
