# Automation Hamri — v8.9.0

AI-powered bulk content generator + manual content distribution dashboard for WordPress.
(Internal plugin name: **Automation Hamri**. The dashboard UI is branded "WP Automator Pro".)

### Instant indexing / IndexNow in 8.9.0 (no AI-generation changes)

Every time a post first goes live — from the WP editor, Direct Publish, the Google Sheet, or a scheduled post going live — the plugin pings the **IndexNow** API so search engines crawl it in minutes instead of days.

- **Zero setup** — a per-site key is generated automatically and served at `/{key}.txt` (same virtual-file trick as ads.txt). On by default; toggle under **Settings → Instant indexing**.
- **Non-blocking** — the ping is fire-and-forget, so publishing never waits on the network.
- **Covers all publish paths** via a global `transition_post_status` hook (no AI-generation code touched).
- **Which engines:** Bing, Yandex, Seznam, Naver and other IndexNow participants. **Google does not use IndexNow** — for Google, submit your XML sitemap once in Search Console (Yoast/Rank Math generate it for you). The Settings panel says this plainly so there's no confusion.
- Settings shows the key-file link (click to confirm it loads) and a "Last ping" timestamp.

### Sheet SEO columns in 8.8.1 (no AI-generation changes)

The Google-Sheet automation now reads the SEO columns from your sheet and passes them to the shared publisher (in 8.8.0 only Direct Publish's pasted JSON did). Add any of these header columns to your sheet and they're picked up automatically: `metaDescription` (or `meta_description` / `description`), `metaTitle` (or `meta_title` / `seo_title`), `focusKeyword` (or `focus_keyword` / `keyword`), and `tags` (comma-separated). All optional — omit and the description is still auto-derived. The starter template ships with these columns filled in as examples.

### Direct Publish SEO metadata in 8.8.0 (no AI-generation changes)

Direct Publish (and the Google-Sheet automation, which shares the same publisher) now fills in SEO metadata automatically:

- **Meta description** — auto-derived from the article body and written into your SEO plugin's field (**Yoast** `_yoast_wpseo_metadesc` / **Rank Math** `rank_math_description`), so posts no longer show an empty description. If you don't run an SEO plugin, the plugin's own `<head>` emitter outputs it. Either way it's set.
- **Optional per-item fields** in the JSON (all optional; omit and they're auto-filled or skipped):
  - `metaDescription` — a custom description (overrides the auto-derived one)
  - `metaTitle` — a custom SEO title
  - `focusKeyword` — the target keyword (written to Yoast/Rank Math)
  - `tags` — an array or comma-separated string, assigned as post tags (max 15)
  - `category` — already supported (name or id; created if new)

Everything is written into whichever SEO plugin you run (Yoast or Rank Math); with no SEO plugin, the description still ships via the built-in `<head>` output added in 8.5.0.

### Dashboard & Hub UX in 8.7.0 (no AI-generation changes)

- **Dashboard stat strip** — a compact row at the top of the dashboard shows Published / Scheduled / Drafts / Last 7 days / No image / Total at a glance (refreshes after each run).
- **Distribution Hub bulk delete** — checkboxes + select-all + a "Delete Selected" button to clear many Hub entries at once (removes the Hub records; the WordPress posts are untouched, same as single-row delete).
- **Distribution Hub status filter** — filter the Hub by All / Published / Scheduled / Draft.

### Roadmap batch in 8.6.0 (no AI-generation changes)

- **BreadcrumbList JSON-LD** — adds Home › Category › Post breadcrumbs to your SERP snippets (self-suppresses when an SEO plugin is active, like the rest of the 8.5.0 SEO output).
- **ads.txt manager** — paste your AdSense/ad-network `ads.txt` lines under **Settings**; served automatically at `/ads.txt` (unless a real file exists at your root). A missing/incorrect ads.txt is a common AdSense "earnings at risk" throttle.
- **Retry Failed** — after a bulk run, a button re-runs *only* the rows that errored, so a few flaky API calls don't force a full re-run (saves spend, avoids duplicates).
- **Per-row Run (▶)** — run a single grid row to test one title before a big batch.
- **Grid autosave** — your title list is saved locally as you type and offered back after an accidental refresh. Already-published rows are never re-queued or re-saved (no duplicate-publish).

### SEO output in 8.5.0

Better search + social visibility for the plugin's own posts, with **no changes to content generation** (all render-time hooks + the shared publisher):

- **Open Graph + Twitter Card tags** — controls the title, description, and preview **image** Facebook/Twitter show when your links are shared (previously they guessed).
- **Meta description** — a clean ~155-char summary in `<head>` (and stored as the post excerpt for Direct Publish / Sheet posts), so Google stops picking a random fragment.
- **Article JSON-LD** (schema.org) — rich-result eligibility.
- **Featured-image alt text** — set to the post title (Direct Publish / Sheet posts) for Google Images.
- **"You May Also Like" related-posts block** — appended to plugin posts (same category, crawlable links) for more internal linking and pages-per-session.

All of this **self-suppresses when a dedicated SEO plugin is active** (Yoast, Rank Math, All in One SEO, SEOPress, The SEO Framework), so there are no duplicate tags — it only fills the gap when you don't already have one. Scoped to posts this plugin created.

### Hardening in 8.4.1 (audit follow-up)

A second security/robustness pass over the new automation code (no feature or AI-generation changes):

- **SSRF: every redirect hop is now validated** on the Sheet fetch — a public URL can no longer 302/redirect the request to an internal address after the initial host check. Redirects are followed manually and re-checked, with `reject_unsafe_urls` as a backstop.
- **CSV parser is bounded** (max 20,000 rows / 500 columns) so a pathological or huge sheet can't exhaust memory and kill the cron.
- **The run lock is now atomic** (`add_option` INSERT instead of get-then-set), fully closing the small window where an overlapping cron + "Run now" could double-publish; combined with the per-post progress saving already in place.
- **A wall-clock budget** stops a run cleanly before the PHP time limit instead of being killed mid-image (and image downloads are capped at 60s).
- Minor: internal DB error text is no longer echoed to the browser on a Hub-delete failure (logged instead); the AI-queue "View" link is escaped for consistency.

### Google-Sheet auto-publish in 8.4.0

Publish ready-made articles automatically from a Google Sheet — **no API key, no login, no content generation** (write your articles however you like; the plugin only handles publishing).

**Setup:** In Google Sheets do **File → Share → Publish to web → (your tab) → Comma-separated values (.csv) → Publish**, copy the link, and paste it under **Settings → Automation — Google Sheet**. Give the sheet a header row with any of: `title`, `content`, `imageUrl`, `hook`, `category`, `id`. Each row needs at least a `title` or `content`; an `id` column is recommended for stable de-duplication.

**How it works:** an hourly WordPress cron polls the sheet, publishes new rows (up to **Max per run** each poll, capped by **Max per day**), sets the category, and de-dups so a row is never published twice — and a deleted post is never re-created. It publishes through the exact same path as Direct Publish (single page, clean permalink, optional random scheduling window), so it's RPM-safe. A **Run now** button polls immediately for testing, and a status panel shows the last run.

Notes: WordPress cron fires on site traffic, so on a quiet site timing drifts — add a real server cron hitting `wp-cron.php` for precise timing. Keep the sheet **private / owner-only**: its `content` is published as-is. Robustness: runs are mutually-locked (no double-publish), progress is saved per-post (a mid-run timeout can't republish), the sheet fetch blocks private/reserved addresses, and image downloads are time-capped.

*(Under the hood, the Direct Publish per-item logic was extracted into one shared `wpap_publish_article()` so the AJAX path and the automation publish identically; Direct Publish also gained category support. AI content/image generation is untouched.)*

### Security & robustness hardening in 8.3.0

A hardening pass that does **not** change AI content/image generation — every change lives at the config, transport, persistence, or UI boundary.

- **TLS verification re-enabled** for the AI/image API calls (Claude, Gemini, Pexels, Pollinations), pinned to WordPress's bundled CA. Your API keys are no longer sent over an unverified connection. A cert failure falls back to an unverified retry **only for the two image-only hosts** (which carry no key) — the key-bearing hosts are never downgraded, so a secret can never leak to a man-in-the-middle. If a key host ever fails verification it errors clearly (fix the server's CA bundle) instead of leaking.
- **API keys can live in `wp-config.php`** — define `WPAP_CLAUDE_API_KEY`, `WPAP_GEMINI_API_KEY`, and/or `WPAP_PEXELS_API_KEY` and they override the database value and never touch the DB or the admin form (the field shows a locked "Set via wp-config.php" note).
- **Cost circuit-breaker** — a per-user hourly (120) and global daily (1000) cap on AI article generation, so a hijacked session or a runaway retry loop can't burn your API budget. Both are filterable.
- **Payload & batch caps** on the JSON bulk endpoints (2 MB / 200 items) to prevent worker exhaustion; truncation is surfaced, not silent.
- **The Tailwind CDN was removed** — it was unused (the admin UI is styled entirely by the plugin's own stylesheet), so no third-party JavaScript runs in your admin session anymore.
- **License friction fixed** — deactivating the plugin no longer wipes your license (so a deactivate/reactivate or host migration won't force re-entry), and the license re-check now hits GitHub at most once per day instead of on every admin page load (no more hangs when GitHub is slow). Full license/secret teardown happens only on **delete**, via a new `uninstall.php` (which also drops the plugin table and settings — but leaves your posts intact).
- **Long-batch nonce refresh** — overnight bulk runs no longer 403 when the security nonce ages out mid-run.
- **PHP-hardening** — the image proxy no longer white-screens on hosts without the `fileinfo` extension.

### AdSense parity in 8.2.4 — plugin posts now behave like a manual paste

Testing showed that plugin-published posts earned far lower AdSense RPM than the *same article* pasted into the WordPress editor. Two structural differences were the cause; both are now neutralised so a plugin post is monetarily identical to a manual one:

- **Direct Publish defaults to "Single page."** Multi-page splitting (`<!--nextpage-->`) is what Google devalues (ad-driven pagination → low-value/unfilled ads → near-zero RPM). The 2/3-page options are still there if you ever want them, but single page is the default.
- **Shared links are now the clean permalink (no `?v=`).** The Distribution Hub, exports, and Facebook captions now use the plain post URL instead of appending a `?v=…` parameter, which could hurt ad value/contextualisation. Legacy `?v=` links on existing Hub rows are stripped on display too.

Nothing about AI content/image generation changed — only the page structure and the shared-link format.

### Cache auto-purge in 8.2.3

Scheduled posts publish silently in the background (via WP-Cron), so a page cache such as **LiteSpeed Cache** would keep serving a stale homepage/blog and the new posts wouldn't appear until you manually purged. The plugin now **purges the page cache automatically whenever one of its posts goes live** — including scheduled posts publishing later. Supports LiteSpeed, WP Rocket, W3 Total Cache, WP Super Cache, and SiteGround (each is a no-op if not installed). This is a cache hook only — it does not change how content or images are generated.

### Distribution Hub cleanup in 8.2.2

The Hub keeps its own list of generated posts, which previously left orphaned entries behind when you deleted the underlying post. Now:

- **Per-row 🗑 delete** — remove a single entry from the Hub (does not touch the WordPress post).
- **🧹 Clean Deleted** (toolbar button) — one click removes every Hub entry whose post has been deleted or trashed.
- **Auto-sync** — permanently deleting a post (empty trash / delete permanently) now automatically removes its Hub entry.

### Security hardening in 8.2.1

- **Image proxy locked to the uploads folder.** The `wpap_proxy_image` endpoint now enforces `realpath` containment and rejects `..`, closing an admin-only path-traversal that could read files such as `wp-config.php`.
- **Direct Publish content is sanitized.** Pasted post bodies are run through `wp_kses_post` (per page, so page-breaks survive) for any user without the `unfiltered_html` capability — this matters on multisite, where it prevents storing `<script>` in a post.
- **API keys no longer leave the server.** The Settings form shows a masked hint (`Saved (••••••1234)`) instead of the real key, all fields are `type="password"` with autofill off, blank = keep existing, and the keys option is no longer autoloaded on every request. A capability check was added to the settings page.
- **SSRF guard on image imports.** Remote image URLs that resolve to loopback / link-local / private ranges (e.g. `169.254.169.254` cloud-metadata) are blocked.
- **Admin dashboard output escaping.** `escHtml` now encodes quotes, closing an attribute-injection XSS in the Distribution Hub table.

> Not changed in 8.2.1 (by request, to avoid disturbing the working AI pipeline): TLS verification on the AI/image API calls (`sslverify`) and the AI content path. See **Security Notes** for the outstanding TLS item.

### What's new in 8.2.0

- **🕒 Auto-scheduling (random publish times).** Instead of publishing everything at once, both **Bulk Generator** and **Direct Publish** can spread a batch across the next few hours using WordPress's native scheduled-post feature. Pick a window (1h / 3h / 6h / 12h / 24h / 48h) and each post gets a **random** publish time inside it. Choose **"Publish now"** to keep the old instant behavior.
- **📄 Direct Publish page-splitting.** Direct Publish can now split each post into **2 or 3 pages** (a "Single page" option is also there) using WordPress's `<!--nextpage-->` pagination, so readers click **Next** to jump between pages. Default is 3. A per-item `"parts"` field in the JSON overrides the dropdown for individual posts.

---

## Installation

1. **To update your existing install (keeps your settings & license):** replace the files in your current plugin folder on the server — `wp-automator-pro.php`, `assets/admin-script.js`, `assets/admin-style.css` — with the ones from this build. **Do not rename the folder** — keeping the original folder name is what makes WordPress treat this as the same plugin (an enhancement), not a new one.
2. **Fresh install:** upload the zip via **Plugins → Add New → Upload Plugin**, then activate via **Plugins → Installed Plugins**.
3. The plugin is license-gated: enter your **Username + License Key** when prompted to unlock the menus.
4. Go to **WP Automator Pro → Settings** and add your API keys (only needed for the AI **Bulk Generator**, not for **Direct Publish**):
   - **Claude API Key** (Anthropic) — AI articles
   - **Gemini API Key** (Google) — AI articles + AI images
   - **Pexels API Key** — stock-photo image engine (optional)

---

## The dashboard has three tabs

### 1) Bulk Generator — *uses the AI/external APIs*
Generates full SEO posts from titles.

1. Add rows manually (**＋ Add Row**) or in bulk:
   - **📋 Bulk Import Rows** — paste JSON `[{"title":"Hello","imageUrl":"https://..."}]`, one title per line, or `Title | Image URL` lines.
   - **🖼 Bulk Image URLs** — paste one image URL per line (image-only rows; the AI writes the article).
   - *Both import buttons only **fill the grid** — they download images into the Media Library so a row is ready. They do not publish. Click ▶ Start Processing to generate + publish.*
2. Pick **Language**, **Pages** (2–5), **Image engine** (Gemini Flash/Pro, Claude, Pexels, Manual Only), **Content engine** (Claude Haiku, Gemini Flash, Gemini Pro), and **🕒 Schedule** (Publish now, or a random time within the next 1h–48h).
3. Click **▶ Start Processing**. Titles are processed one-by-one via AJAX (no timeout risk); the live log shows per-row status (including the scheduled time when scheduling is on). Each post is created with a featured image and a `?v=` smart link, and appears in the Distribution Hub.

### 2) 📦 Direct Publish — *no external API, no AI*
Bulk-publishes ready-made posts straight from JSON. Use this when you already have the title, body, image, and caption.

1. Paste a JSON array. Each item supports:
   - `title` — post title
   - `content` — the post body (HTML allowed)
   - `imageUrl` — downloaded and set as the **featured image** (optional)
   - `hook` — the Facebook caption (optional; defaults to the title)
   - `parts` — *(optional)* how many pages to split this post into (`1`–`3`); overrides the **Split** dropdown for this item only.
2. Choose the **📄 Split** (Single page / Split in 2 / Split in 3 — default 3) and **🕒 Schedule** (Publish now, or a random time within the next 1h–48h) controls.
3. Example:
   ```json
   [{"title":"My Post","imageUrl":"https://example.com/a.jpg","content":"<p>Full article…</p>","hook":"Catchy caption","parts":2}]
   ```
4. Click **📦 Publish All**. A confirmation summarizes the split + timing. Each post is created (real content + featured image), split into pages with `<!--nextpage-->` if requested, published now or scheduled to a random near-future time, given a `?v=` smart link, and added to the **Distribution Hub**. The live log lists every post (with a "view post" link, page count, and scheduled time) plus any skipped rows.

> **Splitting is HTML-safe:** posts are split only at paragraph/heading boundaries and pages are balanced by length, so tags are never cut in half. If the content already contains `<!--nextpage-->`, it's left as-is. Posts too short to split into the requested number of pages are split into as many as safely possible.

### 3) Distribution Hub — *export / share*
All generated **and** directly-published posts appear here.

- **Search** filters by title; results are paginated (10 per page).
- Per-row buttons: **📋 Copy Image** (PNG blob to clipboard), **⬇ Download**, **💬 Copy Hook**, **🔗 Smart Link**, **🗑 Delete** (removes just this Hub entry — the WordPress post is untouched).
- **🧹 Clean Deleted** — removes every Hub entry whose WordPress post has been deleted or trashed (handy for clearing leftovers in one click). Permanently deleting a post also auto-removes its Hub entry.
- **📥 Bulk Import JSON** — seed the hub from `[{"caption":"...","comment":"...","imageUrl":"..."}]`.
- **📤 Export JSON** — copies the current rows as:
  ```json
  [{"caption":"<hook>","comment":"<post link>","imageUrl":"<original image>"}]
  ```
  where **`caption` = the hook**, **`comment` = the post's smart link** (falls back to the plain permalink), and **`imageUrl` = the original image**. Paste this straight into your external Facebook-posting tool.

---

## Quick test checklist

1. **Direct Publish (no keys needed):** open the **📦 Direct Publish** tab, paste the example JSON above (use a real image URL), set **Split = 3** and **Schedule = Publish now**, click **Publish All**. Confirm the log shows ✅, a "view post" link, and "📄 3 pages"; open the post and confirm the **Next** pagination works.
2. **Scheduling:** repeat with **Schedule = Random ≤ 1h**. Confirm the log shows a 🕒 time, and that the post shows as **Scheduled** under **Posts → All Posts** (and appears in the Hub).
3. **Export:** in the Hub, click **📤 Export JSON** and confirm the output is `caption`=hook, `comment`=smart link, `imageUrl`=image.
4. **Bulk Generator (needs API keys):** add a title, pick engines + a Schedule window, **▶ Start Processing**, confirm the generated post appears in the Hub (scheduled if a window was chosen).

---

## Notes

- **Facebook auto-posting is not automated.** Posts are shared **manually** by exporting from the Distribution Hub into your own tool. (Earlier builds auto-posted to a page; that was removed.)
- **Direct Publish content & HTML:** WordPress filters post HTML by user capability. Administrators with `unfiltered_html` keep your markup as-is. Page-break markers (`<!--nextpage-->`) are written directly to the database so they survive regardless of capability.
- **Scheduled posts rely on WP-Cron.** A "future" post publishes when WP-Cron runs, which is triggered by site traffic. On very low-traffic sites a post may go live a little after its scheduled minute (on the next visit). For minute-accurate publishing, run WordPress on a real server cron (set `define('DISABLE_WP_CRON', true);` and add a system cron hitting `wp-cron.php`). Scheduled posts appear in **Posts → All Posts** as *Scheduled* and in the **Distribution Hub** immediately.
- **Random spread:** each post's time is chosen independently at random within the window (never sooner than ~5 minutes out), so a batch scatters naturally rather than all firing together.

---

## Requirements

- WordPress 6.0+
- PHP 8.1+
- For the **Bulk Generator** only: Claude API key (Anthropic), Gemini API key (Google), and optionally a Pexels key. **Direct Publish needs none of these.**

---

## Security Notes

- All AJAX endpoints use `check_ajax_referer()` + `current_user_can('manage_options')`, and are blocked until the plugin is licensed. No unauthenticated (`nopriv`) endpoints exist.
- API keys are stored in `wp_options` (now `autoload = no`) and are never rendered back into the Settings form. Consider a secrets manager or `wp-config.php` constants for production.
- The image proxy (`wpap_proxy_image`) serves files only from inside the site's uploads directory, enforced with `realpath` containment.
- Remote image imports block hosts resolving to private/reserved/link-local IPs (basic SSRF protection).
- **Outstanding (intentionally not changed in 8.2.1):** the AI/image API calls use `sslverify => false`, which exposes the Claude/Gemini/Pexels keys to a man-in-the-middle. This was left untouched to avoid disturbing the AI pipeline on servers with a broken CA bundle. **Recommended fix when ready:** repair the server's CA bundle, then remove the `sslverify => false` lines so TLS certificates are validated.
