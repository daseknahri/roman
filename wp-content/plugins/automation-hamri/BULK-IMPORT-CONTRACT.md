# Bulk-import contract — Automation Hamri (build-v9)

The single source of truth for the JSON the **content-writing session** produces and the
**plugin** consumes. Follow this and every post lands with correct categories, per-type schema,
and full on-page SEO — automatically, with no manual editor step.

Version of this contract: **1.0** (plugin ≥ 9.22.0).

---

## 1. How it's submitted

Bulk publish path — `admin-ajax.php?action=wpap_bulk_publish_posts` (Direct Publish screen, the
"Bulk publish posts" box, or the Bulk ZIP bundle). It publishes **ready-made** posts — no AI is
called at import time. Each array element is one post and is passed straight to
`wpap_publish_article()`.

```
POST admin-ajax.php
  action = wpap_bulk_publish_posts
  nonce  = <wpap_nonce>
  items  = <JSON string: an array of item objects, or {"items":[ ... ]}>
  num_parts        = 1        (optional; default pages per post, 1–10)
  schedule_window  = 0        (optional; hours to spread publish times over, 0 = immediate)
  default_category = ""       (optional; applied to any item with no `category`)
```

Batches are capped per request (see `wpap_bulk_max_items` / `wpap_bulk_max_bytes`) — split large
runs. Each item is **fatal-isolated**: one bad row is skipped, the batch continues.

---

## 2. Item shape

### 2.1 Common fields (every type)

| Field | Type | Required | Notes |
|---|---|---|---|
| `type` | string | rec. | `recipe` \| `guide` \| `story` \| `article`. Decides schema. Omit and it defaults to `article` — **unless** `ingredients`+`steps` are present, which auto-promotes to `recipe`. |
| `title` | string | ✅ | The H1 / post title. **≤ 60 characters** for SEO. |
| `content` | string (HTML) | ✅ | The article body. `<p>`, headings, lists allowed (sanitized). **≥ 400 words** of original text (AdSense floor). For **recipes**, this is the narrative/intro prose only — do **not** repeat the ingredient/step lists here; they render from the structured fields below. |
| `category` | string | ✅ | `Recipes`, `Tips`, or `Stories` (see §3). May be a hierarchy path `Recipes > Soups & Stews`. Created if missing. |
| `tags` | string[] | rec. | 3–8 specific tags. Capped at 15. |
| `meta_description` | string | rec. | **150–160 characters.** Aliases: `metaDescription`, `description`, `excerpt`. Auto-derived from `content` if omitted. |
| `seo_title` | string | opt. | SEO `<title>` if you want it different from `title`. **≤ 60 chars.** Alias: `metaTitle`. |
| `focusKeyword` | string | opt. | Primary keyword (fed to Yoast/Rank Math if active). Alias: `keyword`. |
| `noindex` | bool | opt. | Keep this post OUT of search (thin / utility / near-duplicate content). Enforced via WordPress core's robots API even with **no SEO plugin** (adds `noindex` to the robots meta); a supported SEO plugin's own noindex flag is set too. Omit to leave it indexable (the default). |
| `imageUrl` | string (URL) | ✅ | **Blog** featured image; sideloaded + set as featured. Aliases: `image_url`, `image`. (Bundle ZIP uses a local path instead.) |
| `fbImage` | string | opt. | **Facebook** image — the image the Distribution Hub exports for FB posting, when it should differ from the blog image. URL, or a path inside the bundle ZIP (e.g. `fb-images/x.jpg`). Aliases: `fbImageUrl`, `facebook_image`, `fb_image`. **Omit it and the blog image is used for both.** A local (zip) FB image is hosted as an attachment; a remote URL is stored as-is. Stored in `_wpap_fb_image_url`; the Hub export's `imageUrl` resolves to the FB image → blog image → featured. |
| `image_alt` | string | rec. | Descriptive alt text for the featured image (Google Images / a11y). Defaults to `title`. Aliases: `imageAlt`, `alt`. |
| `hook` | string | opt. | Facebook caption for the Distribution Hub. Aliases: `fb_text`. |
| `comment` | string | opt. | First-comment template with `{{link}}` where the post URL goes. Alias: `fb_comment`. A bare URL is treated as a link, not a template. |
| `related` | string[] | opt. | Curated internal links — slugs of sibling posts to link first in the related block (auto-by-category fills any remaining slots). Array or comma/newline string; up to 10. Alias: `related_articles`. |
| `keywords` | string[] | opt. | Phrases this post should be the internal-link **TARGET** for. The auto-link pass links the first mention of each phrase in **other** posts to this one (bounded ≤4/post, tag-aware, idempotent). Array or comma/newline string; up to 12. Alias: `link_keywords`. Distinct from `focusKeyword` (which feeds the SEO plugin). |
| `parts` | int | opt. | Split the body into N paginated pages (1–10). |

**Inline internal-link tokens (in `content`).** Anywhere in the body you may write `[[link:some-slug]]` or
`[[link:some-slug|anchor text]]`. On publish it becomes a real link to the post whose slug is `some-slug`
(default anchor = that post's title). If the target isn't published yet (e.g. it's later in the same
batch), it's rendered as plain anchor text and auto-upgraded to a link once the target goes live — so
you can cross-link freely within a batch without worrying about order. Slugs are `sanitize_title()` of
each post's `title`. This is the writer-driven counterpart to the `keywords` field's automatic linking.

### 2.2 Recipe-only fields (`type: "recipe"`)

Required for a recipe to earn a valid **schema.org/Recipe** rich result:

| Field | Type | Notes |
|---|---|---|
| `ingredients` | string[] | One ingredient per element. e.g. `["1 large cabbage", "500g ground pork"]`. |
| `steps` | string[] | Ordered instructions, one step per element. |
| `prep` | string\|int | Prep time. `"40 min"`, `"1 hr 30 min"`, `"PT40M"`, or minutes as an int. Alias: `prepTime`. |
| `cook` | string\|int | Cook time (same formats). Alias: `cookTime`. |
| `servings` | string | Yield, e.g. `"6 servings"`. Alias: `yield`. |
| `course` | string | schema.org `recipeCategory` — the **course / meal type**, NOT the blog category. e.g. `"Main course"`, `"Dessert"`, `"Soup"`, `"Home remedy"`. Optional (recommended). Alias: `recipeCategory`. |

`total` time is computed from `prep + cook`, or supply an explicit `total`/`totalTime` to
override. `ingredients`/`steps` may be an array **or** a newline-delimited string. A recipe
needs **both** lists to survive — a recipe with missing/partial data publishes as a valid
Article instead of a broken Recipe. An explicit non-recipe `type` (`article`/`guide`/`story`)
is authoritative: such an item never gets Recipe markup even if it carries these fields.

---

## 3. Taxonomy (kepoli)

Flat three-pillar, hierarchy-ready. Assign exactly one of:

| `category` | `type` | Schema | What goes here |
|---|---|---|---|
| **Recipes** | `recipe` | Recipe + Breadcrumb | A dish with ingredients + steps. |
| **Tips** | `guide` | Article + Breadcrumb | How-to guides, techniques, storage, pantry, ingredient guides. |
| **Stories** | `story` | Article + Breadcrumb | Narrative / personal / editorial food writing. |

**Growing later (no plugin change):** when a pillar gets deep enough to split, send a path instead
of a bare name — the leaf is created under its parent and assigned:

```
"category": "Recipes > Soups & Stews"
"category": "Recipes > Baking & Desserts"
"category": "Tips > Storage & Pantry"
```

Do **not** introduce sub-categories until a pillar has enough posts to justify one (thin
categories hurt SEO). Start flat.

---

## 4. What the plugin does for you (so SEO is "automatic")

For every item, on publish:
- Creates/assigns the **category** (and any parent levels).
- Sideloads the **featured image** with your `image_alt`.
- Writes the **meta description** (`post_excerpt` + SEO plugin) and `seo_title`/keyword.
- Sets **tags**.
- **type = recipe** → writes `_wpap_recipe_*` → theme renders the recipe card and emits
  **Recipe** JSON-LD (ingredients, instructions, `prepTime`/`cookTime`/`totalTime`, yield, image,
  author). **Otherwise** → **Article** JSON-LD. Both get **BreadcrumbList**, canonical, and
  Open Graph/Twitter with real image dimensions. (Site-level Organization + WebSite come from the
  site's own mu-plugin.)
- Registers a Distribution Hub row for Facebook workflows.

You do **not** emit any schema yourself. You only supply the structured fields above.

---

## 5. SEO rules the content must satisfy

1. `title` ≤ 60 chars; `meta_description` 150–160 chars.
2. `content` ≥ 400 words of original text; recipes keep ingredient/step lists **out** of `content`.
3. Every item has an `imageUrl` + a descriptive `image_alt`.
4. Every recipe supplies `ingredients`, `steps`, `prep`, `cook`, `servings` (or the Recipe rich
   result is incomplete).
5. 3–8 specific `tags`.
6. One `category` from §3.

---

## 6. Idempotency

- Set `source_key` (via the automation) or rely on the content-hash anchor `_wpap_source_alt_key`
  to avoid re-publishing the same item as a duplicate on a re-run.
- With `skip_dupe_titles` on (Settings → Content options), an exact-title match is skipped.

---

## 7. Examples

Recipe:
```json
{
  "type": "recipe",
  "title": "Classic Cabbage Rolls",
  "category": "Recipes",
  "content": "<p>Tender cabbage leaves around a savory pork-and-rice filling, slow-simmered in tomato until everything turns meltingly soft. (400+ words of narrative…)</p>",
  "meta_description": "Cabbage rolls made the slow way: tender leaves around a savory pork-and-rice filling, simmered in tomato until soft.",
  "seo_title": "Cabbage Rolls: The Slow-Simmered Way",
  "tags": ["cabbage rolls", "comfort food", "make ahead"],
  "imageUrl": "https://cdn.example.com/cabbage-rolls.jpg",
  "image_alt": "Cabbage rolls in a pot with tomato sauce",
  "servings": "6 servings",
  "course": "Main course",
  "prep": "40 min",
  "cook": "2 hr",
  "ingredients": ["1 large cabbage", "500g ground pork", "1 cup rice", "2 cups tomato sauce"],
  "steps": ["Blanch the cabbage leaves", "Mix pork and rice", "Roll the filling", "Simmer in tomato until soft"]
}
```

Guide:
```json
{
  "type": "guide",
  "title": "How to Stock a Practical Pantry",
  "category": "Tips",
  "content": "<p>(400+ words of practical guidance…)</p>",
  "meta_description": "A practical pantry checklist for weeknight cooking — the staples that turn into dinner on a slow night.",
  "tags": ["pantry", "meal planning", "kitchen basics"],
  "imageUrl": "https://cdn.example.com/pantry.jpg",
  "image_alt": "A stocked pantry shelf of staples"
}
```
