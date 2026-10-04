<?php
/**
 * ads.txt, IndexNow, ad zones (shortcode/block) + in-content injection
 *
 * Extracted verbatim from wp-automator-pro.php (single-file → modular).
 * Load order is fixed by the main file; every hook self-registers here.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function wpap_serve_ads_txt() {
    $path = strtok( isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '', '?' );
    if ( '/ads.txt' !== $path ) { return; }

    $content = trim( (string) get_option( 'wpap_ads_txt', '' ) );
    if ( '' === $content ) { return; }                     /* not configured — let WP handle it */
    if ( @file_exists( ABSPATH . 'ads.txt' ) ) { return; } /* a real root file always wins */

    nocache_headers();
    header( 'Content-Type: text/plain; charset=utf-8' );
    header( 'X-Content-Type-Options: nosniff' );
    echo $content . "\n";   /* text/plain: the browser never renders this as HTML */
    exit;
}

/* ════════════════════════════════════════════
   12b. INDEXNOW — instant search-engine indexing
   On every new publish (manual editor, Direct Publish, Sheet auto-publish,
   or a scheduled post going live) we ping the IndexNow API so Bing, Yandex,
   Seznam, Naver — and a growing list of others — crawl the URL within
   minutes instead of days. A per-site key is generated once and served as a
   virtual /{key}.txt file (same trick as ads.txt above). The ping is
   fire-and-forget (non-blocking) so it never slows publishing down.
   Note: Google does NOT consume IndexNow — for Google, submit your XML
   sitemap once in Search Console (Yoast/Rank Math generate it for you).
════════════════════════════════════════════ */

/* True unless the user explicitly turned IndexNow off in Settings. */
function wpap_indexnow_enabled() {
    $in = get_option( 'wpap_indexnow', array() );
    /* Default OFF (opt-in): a plugin shipped to many sites must not ping a third
       party (api.indexnow.org) on every publish until the admin explicitly enables
       it in Settings. Installs that already saved a choice keep it. */
    if ( ! is_array( $in ) || ! isset( $in['enabled'] ) ) { return false; }
    return (bool) $in['enabled'];
}

/* The site's IndexNow key (32 hex chars). Generated once, then reused. */
function wpap_indexnow_key() {
    $k = get_option( 'wpap_indexnow_key', '' );
    if ( ! is_string( $k ) || ! preg_match( '/^[a-zA-Z0-9-]{8,128}$/', $k ) ) {
        $k = '';
        if ( function_exists( 'random_bytes' ) ) {
            try { $k = bin2hex( random_bytes( 16 ) ); }
            catch ( Exception $e ) { $k = ''; }
        }
        if ( '' === $k ) {   /* fallback if the CSPRNG is unavailable */
            $k = substr( str_replace( array( '-', '_' ), '', wp_generate_password( 40, false, false ) ) . '00000000', 0, 32 );
        }
        update_option( 'wpap_indexnow_key', $k, false );
    }
    return $k;
}

/* Serve the key verification file at /{key}.txt (virtual, like ads.txt). */
add_action( 'init', 'wpap_serve_indexnow_key', 0 );
function wpap_serve_indexnow_key() {
    $path = strtok( isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '', '?' );
    $path = ltrim( (string) $path, '/' );
    if ( '' === $path || '.txt' !== substr( $path, -4 ) ) { return; }   /* cheap gate before any option read */
    $key = wpap_indexnow_key();
    if ( '' === $key || $path !== $key . '.txt' ) { return; }

    nocache_headers();
    header( 'Content-Type: text/plain; charset=utf-8' );
    header( 'X-Content-Type-Options: nosniff' );
    echo $key . "\n";
    exit;
}

/* Ping IndexNow when a post/page first goes public (covers every path). */
add_action( 'transition_post_status', 'wpap_indexnow_on_publish', 10, 3 );
function wpap_indexnow_on_publish( $new_status, $old_status, $post ) {
    if ( 'publish' !== $new_status || 'publish' === $old_status ) { return; }
    if ( ! wpap_indexnow_enabled() ) { return; }
    if ( ! is_object( $post ) || empty( $post->ID ) ) { return; }
    if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) { return; }
    if ( '' !== (string) $post->post_password ) { return; }             /* never announce protected URLs */
    $url = get_permalink( $post->ID );
    if ( ! $url ) { return; }
    wpap_indexnow_submit( array( $url ) );
}

/* POST a list of URLs to the IndexNow API (fire-and-forget). */
function wpap_indexnow_submit( $urls ) {
    $urls = array_values( array_unique( array_filter( array_map( 'strval', (array) $urls ) ) ) );
    if ( empty( $urls ) ) { return; }
    $key  = wpap_indexnow_key();
    $host = wp_parse_url( home_url(), PHP_URL_HOST );
    if ( '' === $key || ! $host ) { return; }
    if ( 'localhost' === $host || false === strpos( $host, '.' ) ) { return; }   /* skip local/dev hosts */

    $body = wp_json_encode( array(
        'host'        => $host,
        'key'         => $key,
        'keyLocation' => home_url( '/' . $key . '.txt' ),
        'urlList'     => array_slice( $urls, 0, 100 ),                  /* IndexNow caps at 10k; we send far fewer */
    ) );

    wp_remote_post( 'https://api.indexnow.org/indexnow', array(
        'timeout'   => 5,
        'blocking'  => false,        /* fire-and-forget: the editor never waits on the network */
        'sslverify' => true,
        'headers'   => array( 'Content-Type' => 'application/json; charset=utf-8' ),
        'body'      => $body,
    ) );

    update_option( 'wpap_indexnow_last', array(
        'time'  => time(),
        'count' => count( $urls ),
        'url'   => $urls[0],
    ), false );
}

/* ════════════════════════════════════════════
   ADSENSE AD PLACEMENT (ported from v8.25 onto the v8.9.0 base, 2026-08-08)
   Self-contained: front-end only (the_content prio 15 + wp_head prio 8).
   Does NOT touch the publish path. Configured under Settings → AdSense.
   ════════════════════════════════════════════ */

/* Normalize the wpap_ads_inject option into a stable shape (with a light
   migration from the flat single-unit schema). Used by both the UI and engine. */
function wpap_get_ads() {
    /* (#13) Memoize per request: this normalized array is rebuilt ~5-6× on a single
       post render (wp_head, the_content, and each theme ad zone). The option is
       stable within a request and the front-end path is read-only. */
    static $cache = null;
    if ( null !== $cache ) { return $cache; }
    $a = get_option( 'wpap_ads_inject', array() );
    if ( ! is_array( $a ) ) { $a = array(); }
    $slots = ( isset( $a['slots'] ) && is_array( $a['slots'] ) ) ? $a['slots'] : array();

    /* Back-compat: map the old single-unit schema onto the new slots. */
    if ( empty( $slots ) && ! empty( $a['incontent'] ) ) {
        $slots['incontent'] = array( 'on' => 1, 'code' => (string) $a['incontent'], 'after' => (int) ( $a['first_after'] ?? 2 ) );
        if ( ! empty( $a['every'] ) ) {
            $slots['repeat'] = array( 'on' => 1, 'code' => (string) $a['incontent'], 'every' => (int) $a['every'], 'max' => (int) ( $a['max'] ?? 3 ) );
        }
    }

    $g = function ( $slot, $key, $default ) use ( $slots ) {
        return isset( $slots[ $slot ][ $key ] ) ? $slots[ $slot ][ $key ] : $default;
    };

    /* Unlimited custom placements (Option 2): a list of {pos, after, code}.
       Only entries with non-empty code count; capped at 10. */
    $custom     = array();
    $custom_raw = ( isset( $a['custom'] ) && is_array( $a['custom'] ) ) ? $a['custom'] : array();
    foreach ( $custom_raw as $c ) {
        if ( ! is_array( $c ) ) { continue; }
        $code = trim( (string) ( $c['code'] ?? '' ) );
        if ( '' === $code ) { continue; }
        $pos = isset( $c['pos'] ) ? (string) $c['pos'] : 'after';
        if ( ! in_array( $pos, array( 'after', 'top', 'before_related' ), true ) ) { $pos = 'after'; }
        $custom[] = array(
            'pos'   => $pos,
            'after' => max( 1, min( 50, (int) ( $c['after'] ?? 2 ) ) ),
            'code'  => $code,
        );
        if ( count( $custom ) >= 10 ) { break; }
    }

    return $cache = array(
        'enabled'   => ! empty( $a['enabled'] ) ? 1 : 0,
        'scope_all' => ! isset( $a['scope_all'] ) ? 1 : ( ! empty( $a['scope_all'] ) ? 1 : 0 ),
        'auto_code' => trim( (string) ( $a['auto_code'] ?? '' ) ),
        /* Density guardrail: min paragraphs between in-content ads (0 = off;
           default 1 blocks two ads landing on the same paragraph) + an optional
           hard cap on in-content ads per post (0 = unlimited). */
        'min_gap'   => ! isset( $a['min_gap'] ) ? 1 : max( 0, min( 20, (int) $a['min_gap'] ) ),
        'max_ads'   => max( 0, min( 20, (int) ( $a['max_ads'] ?? 0 ) ) ),
        'label'     => ! empty( $a['label'] ) ? 1 : 0,
        'zones'     => array(
            'header'  => array( 'on' => ! empty( $a['zones']['header']['on'] ) ? 1 : 0,  'code' => isset( $a['zones']['header']['code'] ) ? trim( (string) $a['zones']['header']['code'] ) : '' ),
            'sidebar' => array( 'on' => ! empty( $a['zones']['sidebar']['on'] ) ? 1 : 0, 'code' => isset( $a['zones']['sidebar']['code'] ) ? trim( (string) $a['zones']['sidebar']['code'] ) : '' ),
            'footer'  => array( 'on' => ! empty( $a['zones']['footer']['on'] ) ? 1 : 0,  'code' => isset( $a['zones']['footer']['code'] ) ? trim( (string) $a['zones']['footer']['code'] ) : '' ),
        ),
        'custom'    => $custom,
        'slots'     => array(
            'top'       => array(
                'on'   => ! empty( $g( 'top', 'on', 0 ) ) ? 1 : 0,
                'code' => trim( (string) $g( 'top', 'code', '' ) ),
            ),
            'incontent' => array(
                'on'    => ! empty( $g( 'incontent', 'on', 0 ) ) ? 1 : 0,
                'code'  => trim( (string) $g( 'incontent', 'code', '' ) ),
                'after' => max( 1, min( 50, (int) $g( 'incontent', 'after', 2 ) ) ),
            ),
            'repeat'    => array(
                'on'    => ! empty( $g( 'repeat', 'on', 0 ) ) ? 1 : 0,
                'code'  => trim( (string) $g( 'repeat', 'code', '' ) ),
                'every' => max( 1, min( 50, (int) $g( 'repeat', 'every', 4 ) ) ),
                'max'   => max( 1, min( 10, (int) $g( 'repeat', 'max', 3 ) ) ),
            ),
            'bottom'    => array(
                'on'   => ! empty( $g( 'bottom', 'on', 0 ) ) ? 1 : 0,
                'code' => trim( (string) $g( 'bottom', 'code', '' ) ),
            ),
        ),
    );
}

/* Wrap an ad snippet in a labelled container. */
function wpap_ad_box( $code, $slot ) {
    static $label = null;
    if ( null === $label ) {
        $ads   = wpap_get_ads();
        $label = ! empty( $ads['label'] );
    }
    $prefix = $label ? '<span class="wpap-ad-label">' . esc_html__( 'Advertisement', 'wp-automator-pro' ) . '</span>' : '';
    $code   = wpap_cap_ad_code( $code );   /* defense-in-depth: never echo an unbounded blob */
    return '<div class="wpap-ad wpap-ad-' . sanitize_html_class( $slot ) . '">' . $prefix . $code . '</div>';
}

/* Bound any ad-code string before it's echoed. Real AdSense units are < 2 KB;
   20 KB is generous. Guards against option bloat / page-weight amplification if
   a huge string ever ends up stored. Multibyte-safe where available. */
function wpap_cap_ad_code( $code ) {
    $code = (string) $code;
    return function_exists( 'mb_substr' ) ? mb_substr( $code, 0, 20000 ) : substr( $code, 0, 20000 );
}

/* Ad HTML for a page zone (header / sidebar / footer) when it's enabled and has
   code, else ''. The companion theme calls this to fill its zones from the
   plugin settings (so there's no need to use WordPress → Widgets). */
function wpap_zone_html( $which ) {
    $ads = wpap_get_ads();
    if ( empty( $ads['enabled'] ) ) { return ''; }
    $which = (string) $which;
    if ( empty( $ads['zones'][ $which ]['on'] ) || '' === (string) $ads['zones'][ $which ]['code'] ) { return ''; }
    return wpap_ad_box( $ads['zones'][ $which ]['code'], 'zone-' . sanitize_html_class( $which ) );
}

/* Place a named ad zone anywhere — no theme edits — via a shortcode
   [wpap_zone name="sidebar"] or the "WP Automator Ad Zone" block (wpap/ad-zone).
   Both funnel through wpap_zone_html(), so output honours the plugin's ad
   settings and is length-capped + wrapped by wpap_ad_box(). The name whitelist
   (header/sidebar/footer) is the guard; the returned markup is the owner's own
   ad code, exactly what the theme already echoes, so it is intentionally not
   re-escaped here. */
function wpap_zone_shortcode( $atts ) {
    $atts = shortcode_atts( array( 'name' => '' ), $atts, 'wpap_zone' );
    $name = sanitize_key( $atts['name'] );
    if ( ! in_array( $name, array( 'header', 'sidebar', 'footer' ), true ) ) { return ''; }
    return wpap_zone_html( $name );   /* owner's own ad markup, already capped + wrapped by wpap_ad_box */
}

/* Server-side render for the wpap/ad-zone block. Dynamic (no saved markup) so
   the front end always reflects current ad settings and no ad code is stored in
   post content. Same whitelist + passthrough as the shortcode. */
function wpap_adzone_block_render( $attrs ) {
    $name = isset( $attrs['name'] ) ? sanitize_key( $attrs['name'] ) : 'sidebar';
    if ( ! in_array( $name, array( 'header', 'sidebar', 'footer' ), true ) ) { return ''; }
    return wpap_zone_html( $name );
}

/* Register the shortcode + dynamic block on init. The shortcode works on any WP
   (classic included); the block registers only where the block API exists. */
add_action( 'init', 'wpap_register_ad_zone' );
function wpap_register_ad_zone() {
    add_shortcode( 'wpap_zone', 'wpap_zone_shortcode' );
    if ( ! function_exists( 'register_block_type' ) ) { return; }   /* classic-only WP: shortcode still works */
    wp_register_script(
        'wpap-adzone-block',
        plugins_url( 'assets/ad-zone-block.js', __FILE__ ),
        array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n' ),
        WPAP_VERSION,
        true
    );
    register_block_type( 'wpap/ad-zone', array(
        'api_version'     => 2,
        'editor_script'   => 'wpap-adzone-block',
        'attributes'      => array( 'name' => array( 'type' => 'string', 'default' => 'sidebar' ) ),
        'render_callback' => 'wpap_adzone_block_render',
    ) );
}

/* <head>: Auto Ads snippet + a preconnect so the first ad paints sooner. */
add_action( 'wp_head', 'wpap_ads_head', 8 );
function wpap_ads_head() {
    if ( is_admin() ) { return; }
    $ads = wpap_get_ads();
    if ( ! $ads['enabled'] ) { return; }

    /* Serve the head block if Auto Ads OR any enabled manual slot has code
       (the manual units need the adsbygoogle loader; the preconnect helps too). */
    $has_manual = ! empty( $ads['custom'] );
    if ( ! $has_manual ) {
        foreach ( array_merge( array_values( $ads['slots'] ), array_values( $ads['zones'] ) ) as $slot ) {
            if ( ! empty( $slot['on'] ) && '' !== (string) $slot['code'] ) { $has_manual = true; break; }
        }
    }
    if ( '' === $ads['auto_code'] && ! $has_manual ) { return; }

    echo "\n";
    echo '<link rel="preconnect" href="https://pagead2.googlesyndication.com" crossorigin>' . "\n";
    echo '<link rel="dns-prefetch" href="//pagead2.googlesyndication.com">' . "\n";
    if ( '' !== $ads['auto_code'] ) {
        echo wpap_cap_ad_code( $ads['auto_code'] ) . "\n";   /* verbatim: owner's own AdSense code (this snippet IS the loader) */
    } elseif ( $has_manual ) {
        /* Manual units need the adsbygoogle loader to fill. When the owner
           didn't paste an Auto Ads snippet (which itself is the loader), ship a
           bare loader so <ins> units still render even if a pasted unit omits
           its own loader line. The data-ad-client on each <ins> supplies the
           publisher id; a duplicate loader (if a unit includes one) is harmless. */
        echo '<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js" crossorigin="anonymous"></script>' . "\n";
    }
    echo "";
}

/* the_content: inject the manual slots. Priority 15 — after wpautop (10) wraps
   paragraphs, before the related block (20) appends its list. */
add_filter( 'the_content', 'wpap_inject_in_content_ads', 15 );
function wpap_inject_in_content_ads( $content ) {
    if ( is_admin() || is_feed() || ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) { return $content; }

    $ads = wpap_get_ads();
    if ( ! $ads['enabled'] ) { return $content; }

    $post_id = (int) get_the_ID();
    if ( ! $post_id ) { return $content; }
    /* Scope: all posts, or only the plugin's own posts. */
    if ( ! $ads['scope_all'] && ! get_post_meta( $post_id, '_wpap_smart_link', true ) ) { return $content; }

    static $done = array();
    if ( isset( $done[ $post_id ] ) ) { return $content; }   /* once per post per request */
    $done[ $post_id ] = true;

    /* AdSense-policy safety net: don't sandwich a near-empty page with ads
       ("low-value content" is a common ground for ad limitations). Count words
       Unicode-safely (works for English / Romanian / Arabic alike) and skip all
       in-content injection on very short pages. 150-word default never trips a
       real article; adjust via the wpap_min_words_for_ads filter. */
    $wpap_plain = trim( wp_strip_all_tags( (string) $content ) );
    if ( '' === $wpap_plain ) {
        $wpap_wc = 0;
    } else {
        $wpap_words = preg_split( '/\s+/u', $wpap_plain, -1, PREG_SPLIT_NO_EMPTY );
        if ( ! is_array( $wpap_words ) ) {                                      /* /u failed on bad UTF-8 */
            $wpap_words = preg_split( '/\s+/', $wpap_plain, -1, PREG_SPLIT_NO_EMPTY );
        }
        /* If both splits fail, err toward showing ads (don't over-suppress). */
        $wpap_wc = is_array( $wpap_words ) ? count( $wpap_words ) : PHP_INT_MAX;
    }
    $wpap_min = (int) apply_filters( 'wpap_min_words_for_ads', 150 );
    if ( $wpap_min > 0 && $wpap_wc < $wpap_min ) { return $content; }

    $s = $ads['slots'];

    /* Prepend (top) + append (before-related) buffers, from named slots and
       any custom placements pointing there. */
    $top = ( ! empty( $s['top']['on'] )    && '' !== $s['top']['code'] )    ? wpap_ad_box( $s['top']['code'], 'top' )       : '';
    $bot = ( ! empty( $s['bottom']['on'] ) && '' !== $s['bottom']['code'] ) ? wpap_ad_box( $s['bottom']['code'], 'bottom' ) : '';

    /* Map: paragraph number => list of ad HTML to place after it (named
       in-content slot + any "after paragraph N" custom placements). */
    $after_map = array();
    if ( ! empty( $s['incontent']['on'] ) && '' !== $s['incontent']['code'] ) {
        $after_map[ (int) $s['incontent']['after'] ][] = wpap_ad_box( $s['incontent']['code'], 'incontent' );
    }
    foreach ( $ads['custom'] as $c ) {
        $box = wpap_ad_box( $c['code'], 'custom' );
        if ( 'top' === $c['pos'] ) {
            $top .= $box;
        } elseif ( 'before_related' === $c['pos'] ) {
            $bot .= $box;
        } else {
            $after_map[ (int) $c['after'] ][] = $box;
        }
    }

    $rep_on   = ! empty( $s['repeat']['on'] ) && '' !== $s['repeat']['code'];
    $every    = (int) $s['repeat']['every'];
    $rep_max  = (int) $s['repeat']['max'];
    $rep_code = $rep_on ? wpap_ad_box( $s['repeat']['code'], 'repeat' ) : '';

    $min_gap = (int) $ads['min_gap'];   /* min paragraphs between in-content ads (0 = off) */
    $max_ads = (int) $ads['max_ads'];   /* hard cap on in-content ads per post (0 = unlimited) */

    $body = $content;
    if ( ! empty( $after_map ) || $rep_on ) {
        $parts = preg_split( '/(<\/p>)/i', $content, -1, PREG_SPLIT_DELIM_CAPTURE );
        if ( is_array( $parts ) && count( $parts ) >= 3 ) {   /* need real paragraphs */
            $out       = '';
            $para      = 0;
            $reps      = 0;
            $placed    = 0;      /* in-content ads placed so far (guardrail count) */
            $last_para = null;   /* paragraph index of the last placed ad */
            foreach ( $parts as $chunk ) {
                $out .= $chunk;
                if ( '</p>' === strtolower( (string) $chunk ) ) {
                    $para++;

                    /* Candidates after this paragraph: fixed placements (named
                       in-content + customs), else the repeat slot. Each is
                       array( html, is_repeat ). */
                    $here = array();
                    if ( isset( $after_map[ $para ] ) ) {
                        foreach ( $after_map[ $para ] as $h ) { $here[] = array( $h, false ); }
                    } elseif ( $rep_on && $every > 0 && 0 === ( $para % $every ) && $reps < $rep_max ) {
                        $here[] = array( $rep_code, true );
                    }

                    foreach ( $here as $cand ) {
                        if ( $max_ads > 0 && $placed >= $max_ads ) { break; }                                     /* hard cap reached */
                        if ( $min_gap > 0 && null !== $last_para && ( $para - $last_para ) < $min_gap ) { break; }  /* too close: skip this + siblings at this paragraph */
                        $out      .= $cand[0];
                        $last_para = $para;
                        $placed++;
                        if ( $cand[1] ) { $reps++; }   /* count the repeat toward its own max only when actually placed */
                    }
                }
            }
            $body = $out;
        }
    }

    return $top . $body . $bot;
}

/* ════════ SETTINGS BACKUP/RESTORE + DASHBOARD HEALTH WIDGET (ported) ════════ */
add_action( 'admin_post_wpap_export_settings', 'wpap_handle_export_settings' );
