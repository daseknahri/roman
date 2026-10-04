# Viral Reader — theme

A warm, editorial, mobile-first WordPress theme for **viral story & health blogs**, in English, built to pair with the **WP Automator Pro** plugin.

## The look
- **Warm "paper" palette** — cream background, near-white cards, olive/copper accents, soft warm shadows and hairlines. No cool grays.
- **Serif reading, sans chrome** — Georgia for headlines and article body, Arial for eyebrows, meta, nav and buttons. System fonts only (no web-font download).
- **Cinematic hero** on the home page — your latest story as a full-bleed image with an overlay, big serif headline, and a copper "Read the story" button.
- **740px reading column** with a **sticky sidebar** (recent stories + topics), a reading-progress bar, breadcrumbs, and share buttons (Facebook / WhatsApp / email / copy / print).
- **Lift-on-hover cards**, pill category chips, a **dark footer** with an olive→copper accent rule.
- Fast + light: no jQuery, block-editor CSS dequeued, LCP hint on the featured image, light mode.

## Ads — the plugin owns them
This theme does **not** inject its own in-content ads (so nothing double-runs). Ads come from **WP Automator Pro**:
- **In-content** ads: configured in the plugin (Settings → AdSense ad placement) and injected into the article body.
- **Header / Sidebar / Footer zones**: set in the plugin too (Settings → AdSense ad placement → "Around the article"); the theme renders them via `wpap_zone_html()`. If the plugin is inactive, the theme falls back to its own **Header Ad / Sidebar / Footer** widget areas, so it still works standalone.
- Injected `.wpap-ad` blocks and the "You May Also Like" block are styled to match the paper palette, with an "Advertisement" label.

## Install
1. **Appearance → Themes → Add New → Upload Theme** → upload the zip → **Activate**.
2. **Appearance → Menus** — create a menu and assign it to *Primary Menu* (until then, the header auto-fills with your top categories).
3. Ads: configure them in the **plugin**, not here (or drop AdSense units into Appearance → Widgets → Header Ad / Sidebar / Footer if you're not using the plugin zones).

## Site options (1.9.23+)
**Appearance → Customize → Site options** — per-site choices, no code. Defaults keep the original look.
- **What this site publishes** — Stories / Recipes / Articles; swaps the word in the theme's buttons and headings.
- **Homepage hero layout** — headline over the photo, or photo above the headline (for images with printed text).
- **Photos on topic tiles and category headers** (off by default) — uses the newest post’s photo when a category has no cover.
- **Show the author name and photo on posts** — untick for a brand-voice site (also hides the author box under posts).

## Notes
- The theme adds **no `<head>` SEO meta** — the plugin (or Yoast/Rank Math) owns that, so nothing is duplicated.
- Standalone: no external services, no custom post meta, no mu-plugin dependencies. Requires WordPress 5.5+ / PHP 7.2+. Text domain `viral-reader`, GPL-2.0+.
