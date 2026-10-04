<?php
/**
 * Front-end SEO head, meta description, Recipe/Article/Breadcrumb schema, related posts
 *
 * Extracted verbatim from wp-automator-pro.php (single-file → modular).
 * Load order is fixed by the main file; every hook self-registers here.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function wpap_frontend() {
    if ( ! is_singular() ) { return; }
    /* Only emit this CSS on the plugin's OWN posts — the classes it styles
       (.wpap-next-teaser / .wpap-share-cta / .wpap-related*) are rendered only on
       posts the plugin manages. On plain Pages / non-plugin posts this block would
       otherwise ship ~1KB of unused inline CSS into <head> on every view. (The dead
       wpapNextPage() script was removed — the theme handles page navigation.) */
    if ( ! get_post_meta( get_queried_object_id(), '_wpap_smart_link', true ) ) { return; }
    ?>
    <style>
    /* .wpap-next-page-wrap and .wpap-next-btn removed — theme handles navigation */
    .wpap-next-teaser{display:block;font-size:1rem;font-weight:700;color:#374151;text-align:center;margin:1.5rem 0;padding:.75rem 1rem;background:#f9fafb;border-radius:6px;border:1px solid #e5e7eb;line-height:1.5}
    .wpap-share-cta{text-align:center;font-size:1.05rem;font-weight:700;color:#e11d48;margin:2rem 0;padding:1rem;border-top:2px dashed #fca5a5}
    .wpap-related{margin:2.5rem 0;padding-top:1rem;border-top:1px solid #e5e7eb}
    .wpap-related-title{font-size:1.15rem;font-weight:800;margin:0 0 1rem}
    .wpap-related-list{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:1rem}
    .wpap-related-item a{display:block;text-decoration:none;color:inherit}
    .wpap-related-item img{width:100%;height:110px;object-fit:cover;border-radius:8px;display:block;margin-bottom:.5rem}
    .wpap-related-item span{font-size:.9rem;font-weight:600;line-height:1.35;display:block}
    </style>
    <?php
}

/* ════════════════════════════════════════════
   11. SEO OUTPUT (front-end, non-AI)
   Open Graph + Twitter Cards + Article JSON-LD + a related-posts
   block for the plugin's OWN posts, so shares and SERP snippets use
   the right title/image/description. Self-suppresses when a dedicated
   SEO plugin is active. Render-time hooks only — does NOT touch
   content/image generation.
════════════════════════════════════════════ */

/* A ~155-char plain-text summary derived from HTML content.
   Uses wp_html_excerpt() (multibyte-safe, and always defined — WordPress does
   not polyfill mb_strrpos, so we avoid it). */
function wpap_make_excerpt( $html, $max = 155 ) {
    /* Insert a space at block/line boundaries BEFORE stripping tags, so adjacent
       block text doesn't merge into one word — e.g. "flour</li><li>1 tsp" must not
       become "flour1 tsp" in an auto meta description / excerpt. */
    $html = preg_replace( '#</(?:p|div|li|ul|ol|h[1-6]|section|article|blockquote|tr|td|th|figure|figcaption)>|<br\s*/?>#i', ' ', (string) $html );
    $text = wp_strip_all_tags( (string) $html );
    $text = trim( preg_replace( '/\s+/', ' ', $text ) );
    if ( '' === $text ) { return ''; }
    $excerpt = wp_html_excerpt( $text, $max, '' );
    if ( $excerpt !== $text ) {
        /* Drop a trailing partial word, then trailing spaces/punctuation.
           Both use UTF-8-aware regexes — a byte-wise rtrim() with the multibyte
           em-dash could corrupt an excerpt ending in a non-Latin character. */
        $trimmed = preg_replace( '/\s+\S*$/u', '', $excerpt );
        if ( null !== $trimmed && '' !== $trimmed ) { $excerpt = $trimmed; }
        $stripped = preg_replace( '/[\s,.;:—-]+$/u', '', $excerpt );
        if ( null !== $stripped && '' !== $stripped ) { $excerpt = $stripped; }
        $excerpt .= '…';
    }
    return $excerpt;
}

/* Write the meta description / SEO title / focus keyword into whichever SEO
   plugin is active. Only the active plugin's keys are written; when no SEO
   plugin is installed, the render-time wpap_seo_head() emits the description
   from post_excerpt instead, so the meta description is covered either way. */
function wpap_set_seo_meta( $post_id, $description, $title = '', $keyword = '', $args = array() ) {
    $post_id = (int) $post_id;
    if ( $post_id <= 0 ) { return; }
    $description = trim( (string) $description );
    $title       = trim( (string) $title );
    $keyword     = trim( (string) $keyword );

    $yoast    = defined( 'WPSEO_VERSION' );
    $rank     = defined( 'RANK_MATH_VERSION' );
    $seopress = defined( 'SEOPRESS_VERSION' );
    $tsf      = function_exists( 'the_seo_framework' );   /* TSF reads Genesis-compat _genesis_* post meta */

    /* Fill-if-empty ONLY: seed the SEO plugin's field when it's blank, but never
       overwrite a value the user typed directly into the Yoast / Rank Math panel.
       apply_derived runs on every save, so an unconditional write would clobber
       their edits on the next save. */
    $fill = function ( $pid, $key, $val ) {
        if ( '' === $val ) { return; }
        if ( '' === trim( (string) get_post_meta( $pid, $key, true ) ) ) {
            update_post_meta( $pid, $key, $val );
        }
    };
    if ( $yoast ) {
        $fill( $post_id, '_yoast_wpseo_metadesc', $description );
        $fill( $post_id, '_yoast_wpseo_title',    $title );
        $fill( $post_id, '_yoast_wpseo_focuskw',  $keyword );
    }
    if ( $rank ) {
        $fill( $post_id, 'rank_math_description',   $description );
        $fill( $post_id, 'rank_math_title',         $title );
        $fill( $post_id, 'rank_math_focus_keyword', $keyword );
    }
    /* SEOPress and The SEO Framework are BOTH counted by wpap_seo_plugin_active() as "an SEO
       plugin owns the head" (so wpap_seo_head() stays silent on those sites) — but without these
       branches the writer's meta title/description/keyword were never written to them either, so
       the curated meta was silently lost and the SEO plugin auto-generated a generic one. Keys
       match the passive build (build-final). (AIOSEO 4.x stores SEO in its own table, not post
       meta, so it can't be seeded this way — a known limitation shared with build-final.) */
    if ( $seopress ) {
        $fill( $post_id, '_seopress_titles_desc',        $description );
        $fill( $post_id, '_seopress_titles_title',       $title );
        $fill( $post_id, '_seopress_analysis_target_kw', $keyword );
    }
    if ( $tsf ) {
        /* The SEO Framework has no first-class focus-keyword meta; seed title + description. */
        $fill( $post_id, '_genesis_description', $description );
        $fill( $post_id, '_genesis_title',       $title );
    }

    /* ── Robots: honor an explicit noindex request ($args['noindex']). Writes the active SEO
       plugin's own noindex meta (so its UI reflects it) AND our own _wpap_noindex marker, which
       wpap_robots_honor_noindex() enforces via WP core's wp_robots API even on a site with NO SEO
       plugin. Acts ONLY when the caller passes an explicit noindex (isset) — an editor save with no
       $args never touches robots, so a manually-noindexed post is never silently re-indexed. ── */
    if ( isset( $args['noindex'] ) ) {
        if ( $args['noindex'] ) {
            if ( $yoast ) { update_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', '1' ); }
            if ( $rank )  {
                $r = get_post_meta( $post_id, 'rank_math_robots', true );
                if ( ! is_array( $r ) ) { $r = array(); }
                $r = array_values( array_diff( $r, array( 'index' ) ) );   /* drop a stale 'index' */
                if ( ! in_array( 'noindex', $r, true ) ) { $r[] = 'noindex'; }
                update_post_meta( $post_id, 'rank_math_robots', $r );
            }
            if ( $seopress ) { update_post_meta( $post_id, '_seopress_robots_index', 'yes' ); }   /* SEOPress: 'yes' == noindex */
            if ( $tsf )      { update_post_meta( $post_id, '_genesis_noindex', '1' ); }
            update_post_meta( $post_id, '_wpap_noindex', 1 );
        } else {
            /* Explicit index request — clear a prior noindex marker + the SEO plugin's flag. */
            delete_post_meta( $post_id, '_wpap_noindex' );
            if ( $yoast ) { delete_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex' ); }
            if ( $rank )  {
                $r = get_post_meta( $post_id, 'rank_math_robots', true );
                if ( is_array( $r ) ) { update_post_meta( $post_id, 'rank_math_robots', array_values( array_diff( $r, array( 'noindex' ) ) ) ); }
            }
            if ( $seopress ) { delete_post_meta( $post_id, '_seopress_robots_index' ); }
            if ( $tsf )      { delete_post_meta( $post_id, '_genesis_noindex' ); }
        }
    }
}

/* Enforce a post's noindex request plugin-agnostically via WordPress core's wp_robots API.
   wpap_set_seo_meta() writes the per-plugin noindex meta (so each SEO plugin's UI reflects it);
   THIS filter additionally honors our own _wpap_noindex marker, so an explicitly-noindexed post
   stays out of search even on a site with NO SEO plugin (kepoli's case) or one that composes with
   core wp_robots. Acts ONLY on a singular post carrying the marker — every other page is returned
   untouched, so the default (indexable) behavior is never changed. */
add_filter( 'wp_robots', 'wpap_robots_honor_noindex', 20 );
function wpap_robots_honor_noindex( $robots ) {
    if ( ! is_array( $robots ) || is_admin() || ! is_singular( 'post' ) ) { return $robots; }
    $id = (int) get_queried_object_id();
    if ( $id > 0 && get_post_meta( $id, '_wpap_noindex', true ) ) {
        unset( $robots['index'] );
        $robots['noindex'] = true;
    }
    return $robots;
}

/* Keep _wpap_noindex posts OUT of the core XML sitemap. A noindexed URL sitting in the sitemap is
   self-contradictory — Google reports "Submitted URL marked 'noindex'" in Search Console and wastes crawl
   budget on pages we've told it not to index. This makes noindex imply sitemap-exclusion (correct + tidy). */
add_filter( 'wp_sitemaps_posts_query_args', 'wpap_sitemap_exclude_noindex', 10, 2 );
function wpap_sitemap_exclude_noindex( $args, $post_type ) {
    if ( 'post' !== $post_type ) { return $args; }
    $mq = ( isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ) ? $args['meta_query'] : array();
    $mq[] = array(
        'relation' => 'OR',
        array( 'key' => '_wpap_noindex', 'compare' => 'NOT EXISTS' ),
        array( 'key' => '_wpap_noindex', 'value' => '1', 'compare' => '!=' ),
    );
    $args['meta_query'] = $mq;
    return $args;
}

/* True when a dedicated SEO plugin is already handling <head> meta. */
function wpap_seo_plugin_active() {
    return ( defined( 'WPSEO_VERSION' )                /* Yoast SEO      */
        || defined( 'RANK_MATH_VERSION' )              /* Rank Math      */
        || defined( 'AIOSEO_VERSION' )                 /* All in One SEO */
        || defined( 'SEOPRESS_VERSION' )               /* SEOPress       */
        || function_exists( 'the_seo_framework' ) );   /* SEO Framework  */
}

/* Decode HTML entities to raw UTF-8 for JSON-LD values. WP returns titles, term names,
   author display names, and bios entity-encoded for HTML display ("doesn&#039;t", "Salt
   &amp; Pepper"); passing that to wp_json_encode DOUBLE-encodes it in structured data.
   Decode once at the JSON-LD boundary — HTML output paths keep using esc_* which
   re-encodes for their context. Arrays are decoded element-by-element. */
function wpap_ld_text( $v ) {
    if ( is_array( $v ) ) { return array_map( 'wpap_ld_text', $v ); }
    return html_entity_decode( (string) $v, ENT_QUOTES, 'UTF-8' );
}

add_action( 'wp_head', 'wpap_seo_head', 1 );
function wpap_seo_head() {
    if ( ! is_singular( 'post' ) ) { return; }
    $post_id = (int) get_queried_object_id();
    if ( ! $post_id || ! get_post_meta( $post_id, '_wpap_smart_link', true ) ) { return; }   /* plugin posts only */
    if ( wpap_seo_plugin_active() ) { return; }   /* an SEO plugin owns the head */

    $post = get_post( $post_id );
    if ( ! $post ) { return; }
    /* Decode HTML entities to raw UTF-8 for JSON-LD. WP stores term names and titles
       entity-encoded (get_the_title() on "Honey & Pepper" => "Honey &amp; Pepper");
       feeding that straight to wp_json_encode double-encodes it in structured data
       ("Honey &amp; Pepper"). Decode once here — every HTML <meta> use below still
       goes through esc_attr(), which re-encodes for the HTML context, so both are correct. */
    $title = html_entity_decode( (string) get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' );
    $url   = wpap_public_permalink( $post_id );   /* (#4) pretty even for a logged-in preview of a future post */
    $desc  = html_entity_decode( (string) ( ( '' !== (string) $post->post_excerpt ) ? $post->post_excerpt : wpap_make_excerpt( $post->post_content ) ), ENT_QUOTES, 'UTF-8' );
    $img   = (string) get_post_meta( $post_id, '_wpap_image_url', true );
    $img_w = 0;
    $img_h = 0;
    if ( '' === $img ) {
        /* Prefer the featured attachment — it hands us the URL AND the real pixel size in
           one call, so we can emit og:image:width/height (below) and Facebook renders the
           card image on the FIRST share instead of showing nothing until it re-scrapes. */
        $thumb_id = get_post_thumbnail_id( $post_id );
        if ( $thumb_id ) {
            $src = wp_get_attachment_image_src( $thumb_id, 'full' );
            if ( is_array( $src ) ) {
                $img   = (string) $src[0];
                $img_w = (int) ( $src[1] ?? 0 );
                $img_h = (int) ( $src[2] ?? 0 );
            }
        }
    } else {
        /* External _wpap_image_url: only trust dimensions if the URL maps to a local
           attachment whose FULL-size URL is exactly this og:image — attachment_url_to_postid
           resolves a resized URL to its parent, whose metadata is the full size, so without
           the exact-URL check we could advertise the wrong dimensions. One indexed lookup,
           only on a cache MISS; otherwise we skip width/height rather than fetch the remote
           file. */
        $maybe_id = attachment_url_to_postid( $img );
        if ( $maybe_id ) {
            $src = wp_get_attachment_image_src( $maybe_id, 'full' );
            if ( is_array( $src ) && isset( $src[0] ) && $src[0] === $img ) {
                $img_w = (int) ( $src[1] ?? 0 );
                $img_h = (int) ( $src[2] ?? 0 );
            }
        }
    }
    /* Final fallback so a post published without a featured image (or _wpap_image_url)
       never shares as a blank card. Prefers the theme's filterable fallback image (e.g.
       a per-category cover), then a site-supplied default via filter, then the site icon.
       All optional: a bare install with none of these degrades to no og:image, exactly as
       before, rather than a broken one. */
    if ( '' === $img ) {
        if ( function_exists( 'vr_fallback_image_url' ) ) {
            $img = (string) vr_fallback_image_url( $post_id );
        }
        if ( '' === $img ) {
            $img = (string) apply_filters( 'wpap_default_og_image', '', $post_id );
        }
        if ( '' === $img && function_exists( 'get_site_icon_url' ) ) {
            $img = (string) get_site_icon_url( 512 );
        }
    }
    /* (#3) Keep the social image scheme consistent with the https canonical/og:url:
       an externally-imported _wpap_image_url may be http, which Facebook/Twitter
       downgrade and browsers flag as mixed content. Upgrade ONLY when the site is
       itself https (never force https on an http-only host). */
    if ( '' !== $img && 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ) ) {
        $img = set_url_scheme( $img, 'https' );
    }
    $site  = html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' );
    $pub   = get_the_date( 'c', $post_id );
    $mod   = get_the_modified_date( 'c', $post_id );

    $out  = "\n";
    if ( '' !== (string) $desc ) {
        $out .= '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
    }
    $out .= '<meta property="og:type" content="article">' . "\n";
    $out .= '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
    if ( '' !== (string) $desc ) { $out .= '<meta property="og:description" content="' . esc_attr( $desc ) . '">' . "\n"; }
    $out .= '<meta property="og:url" content="' . esc_url( $url ) . '">' . "\n";
    $out .= '<meta property="og:site_name" content="' . esc_attr( $site ) . '">' . "\n";
    if ( '' !== $img ) {
        $out .= '<meta property="og:image" content="' . esc_url( $img ) . '">' . "\n";
        if ( 0 === strpos( $img, 'https:' ) ) { $out .= '<meta property="og:image:secure_url" content="' . esc_url( $img ) . '">' . "\n"; }
        /* Explicit dimensions → Facebook shows the image on the first scrape (no blank
           card until it re-fetches). Emitted only when known from a local attachment. */
        if ( $img_w > 0 && $img_h > 0 ) {
            $out .= '<meta property="og:image:width" content="' . (int) $img_w . '">' . "\n";
            $out .= '<meta property="og:image:height" content="' . (int) $img_h . '">' . "\n";
        }
        $out .= '<meta property="og:image:alt" content="' . esc_attr( $title ) . '">' . "\n";
    }
    $out .= '<meta property="article:published_time" content="' . esc_attr( $pub ) . '">' . "\n";
    $out .= '<meta property="article:modified_time" content="' . esc_attr( $mod ) . '">' . "\n";
    $out .= '<meta name="twitter:card" content="' . ( '' !== $img ? 'summary_large_image' : 'summary' ) . '">' . "\n";
    $out .= '<meta name="twitter:title" content="' . esc_attr( $title ) . '">' . "\n";
    if ( '' !== (string) $desc ) { $out .= '<meta name="twitter:description" content="' . esc_attr( $desc ) . '">' . "\n"; }
    if ( '' !== $img ) { $out .= '<meta name="twitter:image" content="' . esc_url( $img ) . '">' . "\n"; }

    /* ── Connected schema @graph (E-E-A-T author + @id-linked nodes). ──
       ONE graph — WebPage → Article → Person(author) → Organization(publisher), plus a
       primary ImageObject and the BreadcrumbList — cross-referenced by @id so Google reads
       one connected entity instead of loose nodes. The Article node is still omitted when a
       more-specific Recipe will render (theme via vr_recipe_data, or this plugin's own
       renderer) — keying on the flag alone would suppress it on a recipe-flagged post with
       no ingredients/steps, leaving no schema; but the WebPage/author/publisher/breadcrumb
       always emit so the page keeps a complete graph either way. The #organization /
       #website @ids reuse the site convention so these MERGE with a site-level
       Organization/WebSite graph (e.g. the companion mu-plugin) when present. */
    $recipe_will_render = ( function_exists( 'vr_recipe_data' ) && vr_recipe_data( $post_id ) )
        || wpap_recipe_should_render( $post_id );

    $home    = home_url( '/' );
    $lang    = str_replace( '_', '-', get_locale() );
    $org_id  = $home . '#organization';
    $site_id = $home . '#website';
    $page_id = $url . '#webpage';
    $img_id  = $url . '#primaryimage';
    $bc_id   = $url . '#breadcrumb';
    $author_num = (int) $post->post_author;
    $person_id  = $home . '#/schema/person/' . $author_num;

    $graph = array();

    /* Publisher Organization (minimal; merges by @id with a site-level Organization node,
       and stands alone on a site without one). */
    $org = array( '@type' => 'Organization', '@id' => $org_id, 'name' => $site, 'url' => $home );
    $logo = function_exists( 'get_site_icon_url' ) ? get_site_icon_url( 512 ) : '';
    if ( $logo ) { $org['logo'] = array( '@type' => 'ImageObject', 'url' => $logo, 'width' => 512, 'height' => 512 ); }
    $graph[] = $org;

    /* Author Person — E-E-A-T: a real url (author archive), avatar, bio, and website
       (sameAs), not just a bare name. */
    $person = array(
        '@type' => 'Person',
        '@id'   => $person_id,
        'name'  => wpap_ld_text( get_the_author_meta( 'display_name', $author_num ) ),
        'url'   => get_author_posts_url( $author_num ),
    );
    $avatar = get_avatar_url( $author_num, array( 'size' => 512 ) );
    if ( $avatar ) { $person['image'] = array( '@type' => 'ImageObject', 'url' => $avatar ); }
    $bio = (string) get_the_author_meta( 'description', $author_num );
    if ( '' !== trim( $bio ) ) { $person['description'] = wpap_ld_text( $bio ); }
    /* sameAs = the author's REAL external profiles. Prefer the theme's collected social
       links (vr_social_* contact-method meta); the raw user_url is often set to the on-site
       author/bio page, which is not a valid sameAs, so only include it when it is genuinely
       an off-site URL. */
    $same = array();
    if ( function_exists( 'vr_author_social_links' ) ) {
        foreach ( (array) vr_author_social_links( $author_num ) as $lnk ) {
            if ( is_array( $lnk ) ) { $lnk = isset( $lnk['url'] ) ? $lnk['url'] : ''; }
            if ( is_string( $lnk ) && preg_match( '#^https?://#i', $lnk ) ) { $same[] = $lnk; }
        }
    }
    $author_site = (string) get_the_author_meta( 'user_url', $author_num );
    $site_host   = (string) ( wp_parse_url( home_url(), PHP_URL_HOST ) ?: '' );
    if ( '' !== trim( $author_site ) && ( '' === $site_host || false === strpos( $author_site, $site_host ) ) ) {
        $same[] = $author_site;
    }
    $same = array_values( array_unique( array_filter( $same ) ) );
    if ( ! empty( $same ) ) { $person['sameAs'] = $same; }
    $graph[] = $person;

    /* Primary image node (referenced by @id from WebPage + Article). */
    if ( '' !== $img ) {
        $image_obj = array( '@type' => 'ImageObject', '@id' => $img_id, 'url' => $img );
        if ( $img_w > 0 && $img_h > 0 ) { $image_obj['width'] = $img_w; $image_obj['height'] = $img_h; }
        $graph[] = $image_obj;
    }

    /* WebPage. */
    $webpage = array(
        '@type'         => 'WebPage',
        '@id'           => $page_id,
        'url'           => $url,
        'name'          => $title,
        'isPartOf'      => array( '@id' => $site_id ),
        'inLanguage'    => $lang,
        'datePublished' => $pub,
        'dateModified'  => $mod,
        'breadcrumb'    => array( '@id' => $bc_id ),
    );
    if ( '' !== (string) $desc ) { $webpage['description'] = $desc; }
    if ( '' !== $img ) {
        $webpage['primaryImageOfPage'] = array( '@id' => $img_id );
        $webpage['image']              = array( '@id' => $img_id );
    }
    $graph[] = $webpage;

    /* Article (omitted when a Recipe will render as the page's primary entity). */
    if ( ! $recipe_will_render ) {
        $article = array(
            '@type'            => 'Article',
            '@id'              => $url . '#article',
            'isPartOf'         => array( '@id' => $page_id ),
            'mainEntityOfPage' => array( '@id' => $page_id ),
            'headline'         => $title,
            'datePublished'    => $pub,
            'dateModified'     => $mod,
            'author'           => array( '@id' => $person_id ),
            'publisher'        => array( '@id' => $org_id ),
            'inLanguage'       => $lang,
        );
        if ( '' !== (string) $desc ) { $article['description'] = $desc; }
        if ( '' !== $img )           { $article['image'] = array( '@id' => $img_id ); }
        $acats = get_the_category( $post_id );
        if ( ! empty( $acats ) ) { $article['articleSection'] = html_entity_decode( (string) $acats[0]->name, ENT_QUOTES, 'UTF-8' ); }
        $graph[] = $article;
    }

    /* BreadcrumbList: Home › Category › Post. */
    $pos    = 1;
    $crumbs = array( array( '@type' => 'ListItem', 'position' => $pos++, 'name' => $site, 'item' => $home ) );
    $cats   = get_the_category( $post_id );
    if ( ! empty( $cats ) ) {
        $c        = $cats[0];
        $c_link   = get_category_link( $c->term_id );
        $crumbs[] = array( '@type' => 'ListItem', 'position' => $pos++, 'name' => html_entity_decode( (string) $c->name, ENT_QUOTES, 'UTF-8' ), 'item' => is_wp_error( $c_link ) ? $home : $c_link );
    }
    $crumbs[] = array( '@type' => 'ListItem', 'position' => $pos, 'name' => $title, 'item' => $url );
    $graph[] = array( '@type' => 'BreadcrumbList', '@id' => $bc_id, 'itemListElement' => $crumbs );

    $ld = array( '@context' => 'https://schema.org', '@graph' => $graph );
    $out .= '<script type="application/ld+json">' . wp_json_encode( $ld, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
    $out .= "";

    echo $out;   /* every dynamic value is individually escaped above */
}

/* Append a crawlable "You may also like" block to the plugin's own posts
   (more internal links + pages-per-session, both good for SEO and RPM). */
add_filter( 'the_content', 'wpap_related_posts_block', 20 );

/* ════════════════════════════════════════════
   RECIPE RENDERING (theme-independent)
   Renders a visible recipe card + matching schema.org/Recipe JSON-LD from the
   Author Tools recipe meta (_wpap_recipe_*), so recipes work on ANY theme with no
   theme code. DEFERS to a theme that already renders them (the viral-reader theme
   defines vr_recipe_card / vr_recipe_jsonld) so there's never a duplicate card or
   duplicate schema. Driven purely by _wpap_recipe_on — independent of
   _wpap_smart_link — so a hand-written recipe post shows a card even if it isn't
   plugin-"managed". Front-end display only; nothing here touches AI generation.
   ════════════════════════════════════════════ */

/* Parsed recipe payload for a post, or null when it isn't a recipe / has no data. */
function wpap_recipe_render_data( $post_id ) {
    if ( '1' !== (string) get_post_meta( $post_id, '_wpap_recipe_on', true ) ) {
        return null;
    }
    $split = static function ( $raw ) {
        $lines = preg_split( '/\r\n|\r|\n/', (string) $raw );
        return array_values( array_filter( array_map( 'trim', (array) $lines ), 'strlen' ) );
    };
    $ingredients = $split( get_post_meta( $post_id, '_wpap_recipe_ingredients', true ) );
    $steps       = $split( get_post_meta( $post_id, '_wpap_recipe_steps', true ) );
    if ( empty( $ingredients ) && empty( $steps ) ) {
        return null;
    }
    $prep  = (int) get_post_meta( $post_id, '_wpap_recipe_prep', true );
    $cook  = (int) get_post_meta( $post_id, '_wpap_recipe_cook', true );
    $total = (int) get_post_meta( $post_id, '_wpap_recipe_total', true );
    if ( $total <= 0 ) { $total = $prep + $cook; }
    return array(
        'servings'    => (string) get_post_meta( $post_id, '_wpap_recipe_servings', true ),
        'course'      => (string) get_post_meta( $post_id, '_wpap_recipe_course', true ),
        'prep'        => $prep,
        'cook'        => $cook,
        'total'       => $total,
        'ingredients' => $ingredients,
        'steps'       => $steps,
    );
}

/* Whole minutes → ISO-8601 duration (90 → PT1H30M). */
function wpap_recipe_iso_minutes( $min ) {
    $min = (int) $min;
    if ( $min <= 0 ) { return ''; }
    $h = intdiv( $min, 60 );
    $m = $min % 60;
    return 'PT' . ( $h ? $h . 'H' : '' ) . ( $m ? $m . 'M' : ( $h ? '' : '0M' ) );
}

/* Whole minutes → "1 hr 30 min". */
function wpap_recipe_human_minutes( $min ) {
    $min = (int) $min;
    if ( $min <= 0 ) { return ''; }
    $h = intdiv( $min, 60 );
    $m = $min % 60;
    $out = array();
    /* translators: %d: number of hours */
    if ( $h ) { $out[] = sprintf( _n( '%d hr', '%d hr', $h, 'wp-automator-pro' ), $h ); }
    /* translators: %d: number of minutes */
    if ( $m ) { $out[] = sprintf( _n( '%d min', '%d min', $m, 'wp-automator-pro' ), $m ); }
    return implode( ' ', $out );
}

/* A dedicated recipe plugin already owns the recipe card + Recipe schema → don't add a second.
   Two Recipe blocks on one page is duplicate/conflicting structured data that Google Search Console
   flags and that can suppress the rich result (plus a visibly doubled card). Mirrors build-final. */
function wpap_recipe_plugin_active() {
    return defined( 'WPRM_VERSION' ) || class_exists( 'WP_Recipe_Maker' )
        || defined( 'TASTY_RECIPES_VERSION' ) || class_exists( 'Tasty_Recipes' )
        || class_exists( 'WPZOOM_Recipe_Card_Block' ) || defined( 'WP_ULTIMATE_RECIPE' );
}

/* Render recipe output for this post? No when it's not a recipe, when the active theme OR a
   dedicated recipe plugin already owns recipe rendering, or when filtered off. */
function wpap_recipe_should_render( $post_id ) {
    if ( ! wpap_recipe_render_data( $post_id ) ) { return false; }
    /* (#12) A password-protected post shows only the password form — do NOT leak
       its ingredients/steps through the card or the <head> JSON-LD. */
    if ( post_password_required( $post_id ) ) { return false; }
    if ( function_exists( 'vr_recipe_card' ) || function_exists( 'vr_recipe_jsonld' ) ) { return false; }
    if ( wpap_recipe_plugin_active() ) { return false; }   /* a recipe plugin already emits Recipe schema/card */
    return (bool) apply_filters( 'wpap_recipe_render_enabled', true, $post_id );
}

/* <head>: theme-neutral card CSS + matching Recipe JSON-LD. */
add_action( 'wp_head', 'wpap_recipe_head', 2 );
function wpap_recipe_head() {
    if ( ! is_singular( 'post' ) ) { return; }
    $id = (int) get_queried_object_id();
    if ( ! wpap_recipe_should_render( $id ) ) { return; }
    $r = wpap_recipe_render_data( $id );

    echo "\n<style id=\"wpap-recipe-css\">"
        . '.wpap-recipe-card{border:1px solid #e5e7eb;border-radius:10px;padding:20px 22px;margin:28px 0;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.05)}'
        . '.wpap-recipe-card__title{margin:0 0 14px;font-size:1.4em}'
        . '.wpap-recipe-card__meta{list-style:none;margin:0 0 16px;padding:0 0 14px;border-bottom:1px solid #e5e7eb;display:flex;flex-wrap:wrap;gap:18px}'
        . '.wpap-recipe-card__meta li{display:flex;flex-direction:column;gap:2px}'
        . '.wpap-recipe-card__k{font-size:.72em;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#6b7280}'
        . '.wpap-recipe-card__v{font-size:1.05em}'
        . '.wpap-recipe-card__sec{margin-top:14px}'
        . '.wpap-recipe-card__sec h3{margin:0 0 .4em;font-size:1.1em}'
        . '.wpap-recipe-card__sec ul,.wpap-recipe-card__sec ol{margin:0;padding-left:1.3em}'
        . '.wpap-recipe-card__sec li{margin:.35em 0}'
        . "</style>\n";

    $data = array(
        '@context'      => 'https://schema.org/',
        '@type'         => 'Recipe',
        'name'          => wpap_ld_text( wp_strip_all_tags( get_the_title( $id ) ) ),
        'author'        => array( '@type' => 'Person', 'name' => wpap_ld_text( get_the_author_meta( 'display_name', (int) get_post_field( 'post_author', $id ) ) ) ),
        'datePublished' => get_the_date( 'c', $id ),
    );
    $desc = has_excerpt( $id ) ? get_the_excerpt( $id ) : wp_trim_words( wp_strip_all_tags( (string) get_post_field( 'post_content', $id ) ), 40, '' );
    if ( '' !== trim( (string) $desc ) ) { $data['description'] = wpap_ld_text( $desc ); }
    /* Google REQUIRES `image` for a Recipe rich result. Resolve it the way the rest of the plugin
       does: featured attachment → the stored remote URL (_wpap_image_url — the common case in this
       pipeline, an externally-hosted image with no local attachment) → the first in-content <img>.
       If none resolves, skip the whole Recipe block below (an image-less Recipe is invalid
       structured data — worse than emitting nothing). Matches build-final. */
    $thumb_id = get_post_thumbnail_id( $id );
    $img      = $thumb_id ? (string) wp_get_attachment_image_url( $thumb_id, 'large' ) : '';
    $img_alt  = '';
    if ( '' !== $img ) {
        $img_alt = trim( (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ) );
    } else {
        $meta_img = trim( (string) get_post_meta( $id, '_wpap_image_url', true ) );
        if ( '' !== $meta_img ) {
            $img = $meta_img;
        } elseif ( preg_match( '#<img[^>]+src=(["\'])(https?://[^"\']+)\1#i', (string) get_post_field( 'post_content', $id ), $mimg ) ) {
            $img = $mimg[2];
        }
    }
    if ( '' !== $img ) {
        $img = set_url_scheme( $img );   /* https, absolute — match the canonical scheme */
        $data['image'] = ( '' !== $img_alt )
            ? array( '@type' => 'ImageObject', 'url' => $img, 'caption' => wpap_ld_text( $img_alt ) )
            : array( $img );
    }
    if ( '' !== trim( $r['servings'] ) ) { $data['recipeYield'] = $r['servings']; }
    if ( '' !== trim( (string) ( $r['course'] ?? '' ) ) ) { $data['recipeCategory'] = wpap_ld_text( $r['course'] ); }
    if ( $r['prep'] > 0 )  { $data['prepTime']  = wpap_recipe_iso_minutes( $r['prep'] ); }
    if ( $r['cook'] > 0 )  { $data['cookTime']  = wpap_recipe_iso_minutes( $r['cook'] ); }
    if ( $r['total'] > 0 ) { $data['totalTime'] = wpap_recipe_iso_minutes( $r['total'] ); }
    if ( ! empty( $r['ingredients'] ) ) { $data['recipeIngredient'] = wpap_ld_text( $r['ingredients'] ); }
    if ( ! empty( $r['steps'] ) ) {
        $data['recipeInstructions'] = array_map(
            static function ( $s ) { return array( '@type' => 'HowToStep', 'text' => wpap_ld_text( $s ) ); },
            $r['steps']
        );
    }
    /* No resolvable image → don't emit an invalid (image-less) Recipe; the visible card, whose CSS
       was already printed above, still renders. */
    if ( '' === $img ) { return; }
    /* (#10) JSON_HEX_TAG|JSON_HEX_AMP (matching the Article/Breadcrumb emitters) so a
       stray </script> in any field can't break out of the JSON-LD block. Dropped
       JSON_UNESCAPED_SLASHES — its default /-escaping is itself a breakout defense. */
    echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
}

/* ── FAQPage schema (OPT-IN) ─────────────────────────────────────────────────────────────────────────
   Emits FAQPage JSON-LD built ONLY from a post's own on-page "<h2>… FAQ / Frequently Asked Questions …</h2>"
   section of <h3>Question?</h3><p>Answer</p> pairs — purely additive, no new copy, no fabrication. OFF by
   default; a site opts in with:  add_filter( 'wpap_faq_schema_enabled', '__return_true' );  Self-suppresses
   when an SEO plugin owns the head, OR when a site plugin already emits FAQPage (function kepoli_faq_jsonld) —
   so migrating that capability from a site mu-plugin into this engine never double-emits. Posts only. */
add_action( 'wp_head', 'wpap_faq_head', 23 );   /* after the @graph(1) and recipe(2) emitters */
function wpap_faq_head() {
    if ( ! apply_filters( 'wpap_faq_schema_enabled', false ) ) { return; }   /* opt-in — DEFAULT OFF */
    if ( ! is_singular( 'post' ) || is_feed() ) { return; }
    if ( wpap_seo_plugin_active() ) { return; }                              /* an SEO plugin owns the head */
    if ( function_exists( 'kepoli_faq_jsonld' ) ) { return; }                /* a site mu-plugin already emits it — defer */

    $content = (string) get_post_field( 'post_content', get_queried_object_id() );
    if ( ! preg_match( '/<h2\b[^>]*>[^<]*(?:FAQ|Frequently\s+Asked\s+Questions|Questions)[^<]*<\/h2>(.*?)(?=<h2\b|\z)/is', $content, $sec ) ) {
        return;
    }
    if ( ! preg_match_all( '/<h3\b[^>]*>(.*?)<\/h3>\s*((?:(?!<h3\b|<h2\b).)*)/is', $sec[1], $pairs, PREG_SET_ORDER ) ) {
        return;
    }
    $entities = array();
    foreach ( $pairs as $p ) {
        $q = wpap_faq_text( $p[1] );
        $a = wpap_faq_text( isset( $p[2] ) ? $p[2] : '' );
        if ( '' === $q || '' === $a ) { continue; }
        $entities[] = array(
            '@type'          => 'Question',
            'name'           => $q,
            'acceptedAnswer' => array( '@type' => 'Answer', 'text' => $a ),
        );
    }
    if ( empty( $entities ) ) { return; }   /* never emit an empty/invalid FAQPage */
    $ld = array( '@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $entities );
    echo '<script type="application/ld+json">' . wp_json_encode( $ld, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
}

/* Flatten an HTML fragment to clean plain text for schema: replace every tag with a space (so block
   boundaries don't merge adjacent words), decode entities, collapse whitespace. */
function wpap_faq_text( $html ) {
    $text = preg_replace( '/<[^>]+>/', ' ', (string) $html );
    $text = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    $text = preg_replace( '/\s+/u', ' ', (string) $text );
    return trim( (string) $text );
}

/* Body: append the visible recipe card so schema matches on-page content. */
add_filter( 'the_content', 'wpap_recipe_card', 9 );
function wpap_recipe_card( $content ) {
    if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) { return $content; }
    $id = (int) get_the_ID();
    if ( ! wpap_recipe_should_render( $id ) ) { return $content; }
    $r = wpap_recipe_render_data( $id );

    $meta = array();
    if ( '' !== trim( $r['servings'] ) )      { $meta[] = array( __( 'Servings', 'wp-automator-pro' ), $r['servings'] ); }
    if ( $r['prep'] > 0 && wpap_recipe_human_minutes( $r['prep'] ) )   { $meta[] = array( __( 'Prep', 'wp-automator-pro' ), wpap_recipe_human_minutes( $r['prep'] ) ); }
    if ( $r['cook'] > 0 && wpap_recipe_human_minutes( $r['cook'] ) )   { $meta[] = array( __( 'Cook', 'wp-automator-pro' ), wpap_recipe_human_minutes( $r['cook'] ) ); }
    if ( $r['total'] > 0 && wpap_recipe_human_minutes( $r['total'] ) ) { $meta[] = array( __( 'Total', 'wp-automator-pro' ), wpap_recipe_human_minutes( $r['total'] ) ); }

    $h  = '<div class="wpap-recipe-card">';
    $h .= '<h2 class="wpap-recipe-card__title">' . esc_html( get_the_title( $id ) ) . '</h2>';
    if ( $meta ) {
        $h .= '<ul class="wpap-recipe-card__meta">';
        foreach ( $meta as $row ) {
            $h .= '<li><span class="wpap-recipe-card__k">' . esc_html( $row[0] ) . '</span><span class="wpap-recipe-card__v">' . esc_html( $row[1] ) . '</span></li>';
        }
        $h .= '</ul>';
    }
    if ( ! empty( $r['ingredients'] ) ) {
        $h .= '<div class="wpap-recipe-card__sec"><h3>' . esc_html__( 'Ingredients', 'wp-automator-pro' ) . '</h3><ul>';
        foreach ( $r['ingredients'] as $ing ) { $h .= '<li>' . esc_html( $ing ) . '</li>'; }
        $h .= '</ul></div>';
    }
    if ( ! empty( $r['steps'] ) ) {
        $h .= '<div class="wpap-recipe-card__sec"><h3>' . esc_html__( 'Instructions', 'wp-automator-pro' ) . '</h3><ol>';
        foreach ( $r['steps'] as $step ) { $h .= '<li>' . esc_html( $step ) . '</li>'; }
        $h .= '</ol></div>';
    }
    $h .= '</div>';
    return $content . $h;
}
function wpap_related_posts_block( $content ) {
    if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) { return $content; }
    /* (#8) Defer to a theme that already renders related posts (viral-reader's
       vr_related_posts), exactly like the recipe card defers via
       function_exists('vr_recipe_card'). Otherwise a full-control site paints TWO
       related sections and runs two WP_Querys per article. */
    if ( function_exists( 'vr_related_posts' ) || current_theme_supports( 'wpap-related-posts' ) ) { return $content; }
    $post_id = (int) get_the_ID();
    if ( ! $post_id || ! get_post_meta( $post_id, '_wpap_smart_link', true ) ) { return $content; }   /* plugin posts only */

    /* On a paginated post, only append on the final page. */
    global $page, $numpages, $multipage;
    if ( ! empty( $multipage ) && (int) $page !== (int) $numpages ) { return $content; }

    static $done = array();
    if ( isset( $done[ $post_id ] ) ) { return $content; }
    $done[ $post_id ] = true;

    /* Cache the computed block. `category__in` forces a temp-table + filesort over the
       WHOLE category (tens of thousands of rows on a mature site) to pick 4 links — the
       single most expensive front-end query here, and re-run on every LiteSpeed cache
       MISS (which a bulk publisher triggers constantly by purging on each publish).
       Related posts tolerate brief staleness, so cache the HTML for 12h; the empty-string
       case is cached too so a post with no siblings doesn't re-query. The key carries the
       site-wide `wpap_rel_ver` stamp, bumped by wpap_invalidate_content_caches() on any
       plugin-post publish/trash/delete — so when a sibling appears or disappears EVERY
       related block recomputes at once (no stale links to removed posts, and an early
       post that cached an empty block picks up later siblings). */
    $cache_key = 'wpap_rel_' . $post_id . '_' . (int) get_option( 'wpap_rel_ver', 0 );
    $cached    = get_transient( $cache_key );
    if ( is_string( $cached ) ) { return ( '' === $cached ) ? $content : $content . $cached; }

    /* Curated internal links first: a hand-picked slug list (_wpap_related_manual, set by
       the import) is used in order; the category query fills any remaining slots. */
    $limit   = 4;
    $exclude = array( $post_id );
    $ids     = array();
    $manual  = get_post_meta( $post_id, '_wpap_related_manual', true );
    if ( is_array( $manual ) ) {
        foreach ( $manual as $slug ) {
            if ( count( $ids ) >= $limit ) { break; }
            $slug = sanitize_title( (string) $slug );
            if ( '' === $slug ) { continue; }
            $p = get_page_by_path( $slug, OBJECT, 'post' );
            if ( $p && 'publish' === $p->post_status && ! in_array( (int) $p->ID, $exclude, true ) ) {
                $ids[]     = (int) $p->ID;
                $exclude[] = (int) $p->ID;
            }
        }
    }
    if ( count( $ids ) < $limit ) {
        $cats = wp_get_post_categories( $post_id );
        $args = array(
            'post__not_in'        => $exclude,
            'posts_per_page'      => $limit - count( $ids ),
            'post_status'         => 'publish',
            'ignore_sticky_posts' => 1,
            'no_found_rows'       => true,
            'fields'              => 'ids',
            'orderby'             => 'date',
            'order'               => 'DESC',
        );
        if ( ! empty( $cats ) ) { $args['category__in'] = $cats; }
        $q   = new WP_Query( $args );
        $ids = array_merge( $ids, array_map( 'intval', $q->posts ) );
        wp_reset_postdata();
    }
    if ( empty( $ids ) ) { set_transient( $cache_key, '', 12 * HOUR_IN_SECONDS ); return $content; }

    $html = '<div class="wpap-related"><h3 class="wpap-related-title">' . esc_html__( 'You May Also Like', 'wp-automator-pro' ) . '</h3><ul class="wpap-related-list">';
    foreach ( $ids as $rid ) {
        $thumb = get_the_post_thumbnail_url( $rid, 'medium' );
        $html .= '<li class="wpap-related-item"><a href="' . esc_url( get_permalink( $rid ) ) . '">';
        if ( $thumb ) { $html .= '<img src="' . esc_url( $thumb ) . '" alt="' . esc_attr( get_the_title( $rid ) ) . '" loading="lazy">'; }
        $html .= '<span>' . esc_html( get_the_title( $rid ) ) . '</span></a></li>';
    }
    $html .= '</ul></div>';

    set_transient( $cache_key, $html, 12 * HOUR_IN_SECONDS );
    return $content . $html;
}

/* ════════════════════════════════════════════
   12. ads.txt MANAGER
   Serves the publisher's ads.txt line(s) at /ads.txt when none exists
   as a physical file — a missing/incorrect ads.txt is a common AdSense
   "earnings at risk" throttle. Configured under Settings.
════════════════════════════════════════════ */
add_action( 'init', 'wpap_serve_ads_txt', 0 );

/* ════════════════════════════════════════════
   13. FACEBOOK IDENTIFIERS (opt-in, both default empty = emit nothing)
   Stored in wpap_content_opts (Settings → Facebook identifiers):
   - facebook-domain-verification: emitted SITE-WIDE — Meta verifies a domain by crawling the
     HOMEPAGE <head> for this tag, so it must appear everywhere (incl. the front page).
   - fb:app_id: an Open Graph property, emitted only on THIS plugin's post pages (gated on the
     _wpap_smart_link marker, alongside the OG block) — silences the Sharing Debugger's last
     "missing fb:app_id" warning and ties shares to a Meta app for Insights.
   The wp_head hook is registered ONLY when at least one value is set (passive when unused).
   Values are sanitised on save AND re-escaped here (defence in depth).
════════════════════════════════════════════ */
$wpap_fb_ids = get_option( 'wpap_content_opts', array() );
if ( is_array( $wpap_fb_ids ) && ( ! empty( $wpap_fb_ids['fb_domain_verify'] ) || ! empty( $wpap_fb_ids['fb_app_id'] ) ) ) {
    add_action( 'wp_head', 'wpap_fb_ids_head', 5 );
}
function wpap_fb_ids_head() {
    $c = get_option( 'wpap_content_opts', array() );
    if ( ! is_array( $c ) ) { return; }
    $dv  = isset( $c['fb_domain_verify'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) $c['fb_domain_verify'] ) : '';
    $app = isset( $c['fb_app_id'] )        ? preg_replace( '/[^0-9]/', '',      (string) $c['fb_app_id'] )        : '';
    $out = '';
    if ( '' !== $dv ) {
        $out .= '<meta name="facebook-domain-verification" content="' . esc_attr( $dv ) . '">' . "\n";
    }
    if ( '' !== $app && is_singular( 'post' ) && '' !== (string) get_post_meta( (int) get_queried_object_id(), '_wpap_smart_link', true ) ) {
        $out .= '<meta property="fb:app_id" content="' . esc_attr( $app ) . '">' . "\n";
    }
    if ( '' !== $out ) {
        echo "\n<!-- Automation Hamri Facebook IDs -->\n" . $out; // phpcs:ignore WordPress.Security.EscapeOutput -- values escaped above
    }
}

/* ════════════════════════════════════════════
   llms.txt / ai.txt — AI-crawler discoverability (opt-in `llms_txt`, DEFAULT OFF).
   Serves a plain-text map of the site at /llms.txt (llmstxt.org convention) + an AI-crawler policy at
   /ai.txt, both generated from live content and cached. The request hooks are registered ONLY when the
   option is on (passive when unused). A real static file at the site root always wins — WordPress never
   routes an existing file through PHP, so this only answers when no such file exists.
   ════════════════════════════════════════════ */
function wpap_llms_txt_enabled() {
    $c = get_option( 'wpap_content_opts', array() );
    return is_array( $c ) && ! empty( $c['llms_txt'] );
}
if ( wpap_llms_txt_enabled() ) {
    add_action( 'template_redirect', 'wpap_llms_txt_serve', 0 );
    add_action( 'save_post', 'wpap_llms_txt_flush' );
}
function wpap_llms_txt_flush() {
    delete_transient( 'wpap_llms_txt_cache' );
}
function wpap_llms_txt_serve() {
    $path = strtok( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), '?' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared to a literal allowlist below, never output
    $path = '/' . ltrim( untrailingslashit( $path ), '/' );
    if ( '/llms.txt' !== $path && '/ai.txt' !== $path ) {
        return;
    }
    /* WordPress 404s an unknown path before template_redirect; force 200 or crawlers ignore the body. */
    status_header( 200 );
    nocache_headers();
    header( 'Content-Type: text/plain; charset=utf-8' );
    header( 'X-Robots-Tag: noindex, follow' );
    if ( '/ai.txt' === $path ) {
        echo wpap_build_ai_txt(); // phpcs:ignore WordPress.Security.EscapeOutput -- plain-text file body, not HTML
        exit;
    }
    $body = get_transient( 'wpap_llms_txt_cache' );
    if ( false === $body ) {
        $body = wpap_build_llms_txt();
        set_transient( 'wpap_llms_txt_cache', $body, 12 * HOUR_IN_SECONDS );
    }
    echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- plain-text file body, not HTML
    exit;
}
function wpap_build_ai_txt() {
    $home    = home_url( '/' );
    $sitemap = home_url( '/wp-sitemap.xml' );
    $lines   = array(
        '# ai.txt — AI crawler policy for ' . get_bloginfo( 'name' ),
        '# Original content. Crawling and citation with attribution are welcome.',
        '',
        'User-agent: *',
        'Allow: /',
        'Disallow: /wp-admin/',
        '',
        'Sitemap: ' . $sitemap,
        'Guidance: ' . $home . 'llms.txt',
    );
    /** Filter the ai.txt lines (array of plain strings, joined with newlines). */
    $lines = apply_filters( 'wpap_ai_txt_lines', $lines );
    return implode( "\n", (array) $lines ) . "\n";
}
function wpap_build_llms_txt() {
    $name = get_bloginfo( 'name' );
    $desc = get_bloginfo( 'description' );
    $out  = '# ' . $name . "\n\n";
    if ( '' !== (string) $desc ) {
        $out .= '> ' . $desc . "\n\n";
    }
    /** Filter an intro paragraph placed under the site name/tagline (default empty). */
    $intro = (string) apply_filters( 'wpap_llms_intro', '' );
    if ( '' !== $intro ) {
        $out .= $intro . "\n\n";
    }
    $cats = get_categories( array(
        'orderby'    => 'count',
        'order'      => 'DESC',
        'hide_empty' => true,
    ) );
    foreach ( $cats as $cat ) {
        $q = new WP_Query( array(
            'cat'                 => $cat->term_id,
            'posts_per_page'      => 20,
            'post_status'         => 'publish',
            'ignore_sticky_posts' => true,
            'orderby'             => 'date',
            'order'               => 'DESC',
            'no_found_rows'       => true,
        ) );
        if ( ! $q->have_posts() ) {
            continue;
        }
        $out .= '## ' . $cat->name . "\n";
        while ( $q->have_posts() ) {
            $q->the_post();
            $title = get_the_title();
            $url   = get_permalink();
            $ex    = trim( wp_strip_all_tags( get_the_excerpt() ) );
            if ( function_exists( 'mb_strlen' ) && mb_strlen( $ex ) > 130 ) {
                $ex = rtrim( mb_substr( $ex, 0, 130 ) ) . '…';
            }
            $out .= '- [' . $title . '](' . $url . ')' . ( '' !== $ex ? ': ' . $ex : '' ) . "\n";
        }
        wp_reset_postdata();
        $out .= "\n";
    }
    $pages = get_pages( array(
        'sort_column' => 'menu_order',
        'parent'      => 0,
    ) );
    if ( $pages ) {
        $out .= "## Pages\n";
        foreach ( $pages as $p ) {
            $out .= '- [' . get_the_title( $p->ID ) . '](' . get_permalink( $p->ID ) . ")\n";
        }
        $out .= "\n";
    }
    return $out;
}
