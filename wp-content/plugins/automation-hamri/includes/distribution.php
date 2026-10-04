<?php
/**
 * Distribution Hub queries, delete/cleanup, row lifecycle, cache purge, image proxy
 *
 * Extracted verbatim from wp-automator-pro.php (single-file → modular).
 * Load order is fixed by the main file; every hook self-registers here.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/* Shared, filesort-free "published first, then newest" fetch for the Hub list + JSON export.
   The old single query ordered by ( p.post_status = 'publish' ) DESC — an expression over a
   JOINED column, which no index can satisfy, so MySQL joined and filesorted EVERY matching
   row before LIMIT/OFFSET (O(all rows) on every Hub pageview at 100k+ rows). Instead, query
   the two segments separately, each ordered by the PRIMARY KEY t.id DESC (index-backed, no
   filesort): published rows first, then non-published (incl. rows whose post was deleted →
   p.post_status IS NULL). Straddle math splits a page that spans the boundary so pagination
   is byte-identical to the old order. $cols is the SELECT list; $where_sql/$params must NOT
   carry a status filter (callers use this only for the mixed 'all' view). */
/** The image to submit to Facebook for a post: the FB-specific image (_wpap_fb_image_url) if set,
 *  else the blog featured/source image (_wpap_image_url, then the WP thumbnail). Lets a post carry
 *  a DIFFERENT image for Facebook than for the blog, while one image serves both when only one was
 *  provided. The Hub stores + exports this as the row's image_url, so extraction uses the FB image. */
function wpap_fb_image_url( $post_id ) {
    $fb = (string) get_post_meta( (int) $post_id, '_wpap_fb_image_url', true );
    if ( '' !== $fb ) { return $fb; }
    $img = (string) get_post_meta( (int) $post_id, '_wpap_image_url', true );
    if ( '' !== $img ) { return $img; }
    return (string) get_the_post_thumbnail_url( (int) $post_id, 'full' );
}

function wpap_hub_ordered_rows( $cols, $where_sql, $params, $per, $offset ) {
    global $wpdb;
    $table = $wpdb->prefix . WPAP_TABLE;
    $join  = "LEFT JOIN {$wpdb->posts} p ON p.ID = t.post_id";
    $pub   = $where_sql . " AND p.post_status = 'publish'";
    $npub  = $where_sql . " AND ( p.post_status IS NULL OR p.post_status <> 'publish' )";

    $pub_count_sql = "SELECT COUNT(*) FROM {$table} t {$join} WHERE {$pub}";
    $pub_total = (int) ( empty( $params )
        ? $wpdb->get_var( $pub_count_sql )
        : $wpdb->get_var( $wpdb->prepare( $pub_count_sql, $params ) ) );

    $rows = array();
    if ( $offset < $pub_total ) {
        $take = min( $per, $pub_total - $offset );
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT {$cols} FROM {$table} t {$join} WHERE {$pub} ORDER BY t.id DESC LIMIT %d OFFSET %d",
            array_merge( $params, array( $take, $offset ) )
        ), ARRAY_A );
    }
    if ( count( $rows ) < $per ) {
        $rows = array_merge( $rows, (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT {$cols} FROM {$table} t {$join} WHERE {$npub} ORDER BY t.id DESC LIMIT %d OFFSET %d",
            array_merge( $params, array( $per - count( $rows ), max( 0, $offset - $pub_total ) ) )
        ), ARRAY_A ) );
    }
    return $rows;
}

/* ── First-comment templates + shared Hub filters (ported from build-final 8.43/8.44/8.48) ──
   The export's `comment` can be first-comment TEXT with the article link inside it (a
   `{{link}}` token), resolved per-post (`_wpap_fb_comment`) then the global default
   (`wpap_content_opts['fb_comment_template']`). The WHERE clause + per-page helper are shared
   by the Hub list AND its export so a filtered export always matches the table. (No UTM here —
   build-v9 does not carry the UTM feature; the link is used as-is.) */

/** Resolve the RAW first-comment template for a post (no substitution): the post's own
 *  override (`_wpap_fb_comment`) → the global default → ''. */
function wpap_resolve_fb_template( $pid = 0 ) {
    $tpl = '';
    if ( $pid ) { $tpl = (string) get_post_meta( (int) $pid, '_wpap_fb_comment', true ); }
    if ( '' === trim( $tpl ) ) {
        $copts = get_option( 'wpap_content_opts', array() );
        if ( is_array( $copts ) ) { $tpl = (string) ( $copts['fb_comment_template'] ?? '' ); }
    }
    return $tpl;
}

/** Compose the first-comment text: template with `{{link}}` → the post link. No template →
 *  the bare link (historical behavior). A template that omits `{{link}}` gets the link
 *  appended on its own line so the link is never silently lost. */
function wpap_compose_fb_comment( $link, $pid = 0 ) {
    $link = (string) $link;
    $tpl  = wpap_resolve_fb_template( $pid );
    if ( '' === trim( $tpl ) ) { return $link; }
    if ( false === strpos( $tpl, '{{link}}' ) ) {
        return '' === trim( $link ) ? $tpl : rtrim( $tpl ) . "\n" . $link;
    }
    return str_replace( '{{link}}', $link, $tpl );
}

/** The ONE WHERE clause shared by the Hub list (`wpap_ajax_get_posts`) and its JSON export so
 *  a filtered export always matches what the table shows. Built for a query aliased
 *  `{TABLE} t LEFT JOIN {posts} p ON p.ID = t.post_id`. Returns `['where'=>sql,'params'=>[]]`.
 *  `$status` applies only for a concrete status (publish/future/draft); `$fb` = posted|unposted|all
 *  (the Facebook-posted toggle, keyed on the `_wpap_fb_posted` meta). */
function wpap_distribution_where_clause( $search, $status, $fb ) {
    global $wpdb;
    $where  = array( '1=1' );
    $params = array();
    if ( '' !== (string) $search ) {
        $where[]  = 't.title LIKE %s';
        $params[] = '%' . $wpdb->esc_like( (string) $search ) . '%';
    }
    if ( in_array( $status, array( 'publish', 'future', 'draft' ), true ) ) {
        $where[]  = 'p.post_status = %s';
        $params[] = $status;
    }
    if ( 'posted' === $fb ) {
        $where[] = "EXISTS ( SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.post_id = t.post_id AND pm.meta_key = '_wpap_fb_posted' AND pm.meta_value <> '' )";
    } elseif ( 'unposted' === $fb ) {
        $where[] = "NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.post_id = t.post_id AND pm.meta_key = '_wpap_fb_posted' AND pm.meta_value <> '' )";
    }
    return array( 'where' => implode( ' AND ', $where ), 'params' => $params );
}

/** Rows-per-page for the Hub list AND its export (the export's current-page mode must page the
 *  same way the table does). `$_GET['per_page']` ∈ {10,25,50,100}; anything else falls back to 10. */
function wpap_distribution_per_page() {
    $per = intval( $_GET['per_page'] ?? 10 );
    return in_array( $per, array( 10, 25, 50, 100 ), true ) ? $per : 10;
}

function wpap_ajax_get_posts() {
    check_ajax_referer( 'wpap_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

    global $wpdb;
    $table  = $wpdb->prefix . WPAP_TABLE;
    $page   = max( 1, intval( $_GET['page']   ?? 1 ) );
    $search = sanitize_text_field( wp_unslash( $_GET['search'] ?? '' ) );
    $status = sanitize_key( $_GET['status'] ?? 'all' );
    if ( ! in_array( $status, array( 'all', 'publish', 'future', 'draft' ), true ) ) { $status = 'all'; }
    $fb = sanitize_key( $_GET['fb'] ?? 'all' );   /* Facebook-posted filter: all | posted | unposted */
    if ( ! in_array( $fb, array( 'all', 'posted', 'unposted' ), true ) ) { $fb = 'all'; }
    $per    = wpap_distribution_per_page();   /* 10/25/50/100 — shared with the export */
    $offset = ( $page - 1 ) * $per;

    /* Build WHERE via the shared clause (search + status + Facebook filter) so a filtered
       export matches the table exactly. ALWAYS LEFT JOIN wp_posts so every row carries its
       publish status — used to (a) sort PUBLISHED rows first so a scheduled/draft link is
       never at the top where it'd be copied by accident, (b) badge "not live yet" in the UI,
       and (c) re-resolve a legacy ?p= permalink below. LEFT (not INNER) so a row whose post
       was deleted still shows. */
    $join      = "LEFT JOIN {$wpdb->posts} p ON p.ID = t.post_id";
    $wc        = wpap_distribution_where_clause( $search, $status, $fb );
    $where_sql = $wc['where'];
    $params    = $wc['params'];

    /* The COUNT only needs the wp_posts JOIN when a status filter references p.post_status.
       For the default 'all' view the LEFT JOIN onto the unique wp_posts PK is row-preserving,
       so COUNT(*) is identical without it — dropping the join avoids ~N pointless PK probes
       into wp_posts on every Hub pageview and pagination click (matters on MySQL, which does
       not eliminate the join automatically). */
    $count_join = ( 'all' !== $status ) ? $join : '';
    $count_sql  = "SELECT COUNT(*) FROM {$table} t {$count_join} WHERE {$where_sql}";
    $total = empty( $params )
        ? (int) $wpdb->get_var( $count_sql )
        : (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );

    /* PUBLISHED rows first (never copy a scheduled/draft link), then newest first — done
       filesort-free (see wpap_hub_ordered_rows) for the mixed 'all' view. A specific status
       filter needs no publish-first split, so it orders by the PK t.id directly (also no
       filesort). Both avoid the old ORDER BY on a computed joined-column expression. */
    if ( 'all' === $status ) {
        $rows = wpap_hub_ordered_rows( 't.*, p.post_status AS wp_status', $where_sql, $params, $per, $offset );
    } else {
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT t.*, p.post_status AS wp_status FROM {$table} t {$join} WHERE {$where_sql} ORDER BY t.id DESC LIMIT %d OFFSET %d",
            array_merge( $params, array( $per, $offset ) )
        ), ARRAY_A );
    }

    /* Enrich each row from post meta */
    foreach ( $rows as &$row ) {
        $pid          = intval( $row['post_id'] ?? 0 );
        $needs_update = false;

        if ( $pid ) {
            /* ?p= self-heal (2026-08-11): a row created while its post was SCHEDULED stored the ugly `?p=<id>` permalink
               (get_permalink() returns that for a non-`publish` post). Re-resolve it to the pretty URL so the shared link
               stops 301-redirecting (a redirect zeroes ad RPM). wpap_public_permalink resolves cleanly even for a post
               that is STILL scheduled. Persisted via $needs_update (post_url is in the write-back array below). */
            $has_ugly = ( false !== strpos( (string) ( $row['post_url'] ?? '' ), '?p=' ) )
                     || ( false !== strpos( (string) ( $row['smart_link'] ?? '' ), '?p=' ) );
            if ( $has_ugly ) {
                $pretty = wpap_public_permalink( $pid );
                if ( is_string( $pretty ) && '' !== $pretty && false === strpos( $pretty, '?p=' ) ) {
                    $row['post_url']   = $pretty;
                    $row['smart_link'] = $pretty;
                    update_post_meta( $pid, '_wpap_smart_link', $pretty );
                    $needs_update = true;
                }
            }

            /* image_url: the Facebook image → blog image → WP featured (see wpap_fb_image_url) */
            if ( empty( $row['image_url'] ) ) {
                $img = wpap_fb_image_url( $pid );
                if ( '' !== $img ) {
                    $row['image_url'] = $img;
                    $needs_update     = true;
                }
            }

            /* fb_text: post meta → plugin table */
            if ( empty( $row['fb_text'] ) ) {
                $meta_hook = get_post_meta( $pid, '_wpap_fb_hook', true );
                if ( $meta_hook ) {
                    $row['fb_text'] = $meta_hook;
                    $needs_update   = true;
                }
            }

            /* smart_link: post meta → generate from post_url */
            if ( empty( $row['smart_link'] ) ) {
                $meta_link = get_post_meta( $pid, '_wpap_smart_link', true );
                if ( $meta_link ) {
                    $row['smart_link'] = $meta_link;
                } elseif ( ! empty( $row['post_url'] ) ) {
                    $row['smart_link'] = $row['post_url'];
                }
                $needs_update = true;
            }
        }

        /* Always hand back a clean permalink — strip any legacy ?v= from old rows. */
        if ( ! empty( $row['smart_link'] ) ) {
            $row['smart_link'] = remove_query_arg( 'v', $row['smart_link'] );
        }

        /* Write repairs back to plugin table */
        if ( $needs_update ) {
            $wpdb->update( $table, array(
                'post_url'   => $row['post_url']   ?? '',
                'image_url'  => $row['image_url']  ?? '',
                'fb_text'    => $row['fb_text']    ?? '',
                'smart_link' => $row['smart_link'] ?? '',
            ), array( 'id' => $row['id'] ) );
        }

        /* Facebook 1:1 posting status → the Hub "Posted" badge/toggle + the "Not posted" filter. */
        $row['fb_posted'] = ( $pid && '' !== (string) get_post_meta( $pid, '_wpap_fb_posted', true ) ) ? 1 : 0;

        /* First-comment text for this row: the per-post override (or global default) with
           {{link}} substituted for the display link. `fb_comment_tpl` is the RAW per-post
           override (blank = the row inherits the global default) for the Hub's per-row editor. */
        $row['fb_comment']     = wpap_compose_fb_comment( (string) ( $row['smart_link'] ?? '' ), $pid );
        $row['fb_comment_tpl'] = $pid ? (string) get_post_meta( $pid, '_wpap_fb_comment', true ) : '';
    }
    unset( $row );

    wp_send_json_success( array(
        'rows'        => $rows,
        'total'       => (int) $total,
        'per_page'    => $per,
        'page'        => $page,
        'total_pages' => (int) ceil( $total / $per ),
    ) );
}

/* Mark a BATCH of posts as Facebook-posted (or not) in one call — powers the Hub's "export &
   mark posted" flow: grab a page's JSON, then flag those posts so the "Not posted yet" filter
   drops them and the operator walks the queue page by page. Accepts post_ids as an array or a
   comma list; `posted=0` clears instead. (ported from build-final 8.47.0) */
add_action( 'wp_ajax_wpap_mark_posted_bulk', 'wpap_ajax_mark_posted_bulk' );
function wpap_ajax_mark_posted_bulk() {
    check_ajax_referer( 'wpap_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
    $raw = $_POST['post_ids'] ?? array();
    if ( is_array( $raw ) ) {
        $ids = array_map( 'intval', $raw );
    } else {
        $ids = array_map( 'intval', array_filter( explode( ',', (string) wp_unslash( $raw ) ), 'strlen' ) );
    }
    $ids = array_values( array_unique( array_filter( $ids, function ( $x ) { return $x > 0; } ) ) );
    if ( empty( $ids ) ) { wp_send_json_error( 'No posts to mark.' ); }
    if ( count( $ids ) > 1000 ) { $ids = array_slice( $ids, 0, 1000 ); }   /* bound one request */

    $want   = isset( $_POST['posted'] ) ? ( ! empty( $_POST['posted'] ) ? 1 : 0 ) : 1;   /* default = mark posted */
    $marked = 0;
    foreach ( $ids as $pid ) {
        if ( 'post' !== get_post_type( $pid ) ) { continue; }
        if ( $want ) { update_post_meta( $pid, '_wpap_fb_posted', time() ); }
        else { delete_post_meta( $pid, '_wpap_fb_posted' ); }
        $marked++;
    }
    wp_send_json_success( array( 'marked' => $marked, 'posted' => $want ) );
}

/* Toggle ONE post's Facebook "posted" status (or set it explicitly) — drives the Hub's per-row
   Posted badge/toggle. Stored as `_wpap_fb_posted` (a timestamp). (ported from build-final) */
add_action( 'wp_ajax_wpap_toggle_fb_posted', 'wpap_ajax_toggle_fb_posted' );
function wpap_ajax_toggle_fb_posted() {
    check_ajax_referer( 'wpap_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
    $pid = intval( $_POST['post_id'] ?? 0 );
    if ( $pid <= 0 || 'post' !== get_post_type( $pid ) ) { wp_send_json_error( 'Invalid post.' ); }
    $want = isset( $_POST['posted'] ) ? ( ! empty( $_POST['posted'] ) ? 1 : 0 ) : null;   /* default = toggle */
    $now  = '' !== (string) get_post_meta( $pid, '_wpap_fb_posted', true ) ? 1 : 0;
    $next = ( null === $want ) ? ( $now ? 0 : 1 ) : $want;
    if ( $next ) { update_post_meta( $pid, '_wpap_fb_posted', time() ); }
    else { delete_post_meta( $pid, '_wpap_fb_posted' ); }
    wp_send_json_success( array( 'posted' => $next ) );
}

/* IMPORT ENTIRE BLOG → HUB. One click backfills a Hub row for every published post not already
   tracked — manual publishes and pre-existing articles included — so the Distribution Hub mirrors
   the WHOLE blog, not just plugin-published posts. Idempotent (existing post_ids skipped); rows are
   written in batches with primed caches so a blog with thousands of posts imports in a handful of
   queries. Stores link + image up front so each imported row is export-ready. (ported from build-final) */
add_action( 'wp_ajax_wpap_import_all_posts', 'wpap_ajax_import_all_posts' );
function wpap_ajax_import_all_posts() {
    check_ajax_referer( 'wpap_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Unauthorized' ); }
    @set_time_limit( 300 );
    global $wpdb;
    $table = $wpdb->prefix . WPAP_TABLE;

    $have = array_flip( array_map( 'intval', (array) $wpdb->get_col( "SELECT post_id FROM {$table}" ) ) );
    $ids  = array_map( 'intval', (array) $wpdb->get_col(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish'"
    ) );
    $todo = array();
    foreach ( $ids as $id ) { if ( $id > 0 && ! isset( $have[ $id ] ) ) { $todo[] = $id; } }

    $cols     = '(post_id, title, post_url, image_url, fb_text, fb_post_id, smart_link)';
    $imported = 0;
    foreach ( array_chunk( $todo, 100 ) as $chunk ) {
        _prime_post_caches( $chunk, true, true );
        $values = array();
        foreach ( $chunk as $id ) {
            $title = (string) get_the_title( $id );
            $link  = (string) wpap_public_permalink( $id );
            $hook  = (string) get_post_meta( $id, '_wpap_fb_hook', true );
            $image = (string) get_post_meta( $id, '_wpap_image_url', true );
            if ( '' === $image ) { $image = (string) get_the_post_thumbnail_url( $id, 'full' ); }
            $cap   = ( '' !== $hook ) ? $hook : $title;
            $values[] = $wpdb->prepare( "(%d,%s,%s,%s,%s,'',%s)", $id, mb_substr( $title, 0, 500 ), $link, $image, $cap, $link );
        }
        if ( $values ) {
            // phpcs:ignore WordPress.DB.PreparedSQL -- each row was individually $wpdb->prepare()'d above
            $res = $wpdb->query( "INSERT INTO {$table} {$cols} VALUES " . implode( ',', $values ) );
            if ( false !== $res ) { $imported += (int) $res; }
        }
    }

    $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
    wp_send_json_success( array( 'imported' => (int) $imported, 'total' => $total ) );
}

/* AUTO-ADD future publishes → HUB. Any post that goes live outside the plugin (a manual editor
   publish) gets a Hub row on the spot, so the Hub keeps mirroring the whole blog with no re-import.
   The plugin's OWN publishes set $wpap_suppress_hub_autoadd (they write their own richer row moments
   later); posts already tracked are skipped. (ported from build-final) */
add_action( 'transition_post_status', 'wpap_autoadd_post_to_hub', 20, 3 );
function wpap_autoadd_post_to_hub( $new_status, $old_status, $post ) {
    if ( 'publish' !== $new_status || 'publish' === $old_status ) { return; }
    if ( ! empty( $GLOBALS['wpap_suppress_hub_autoadd'] ) ) { return; }   /* plugin's own publish handles its row */
    if ( ! ( $post instanceof WP_Post ) || 'post' !== $post->post_type ) { return; }
    $post_id = (int) $post->ID;
    if ( $post_id <= 0 ) { return; }

    global $wpdb;
    $table = $wpdb->prefix . WPAP_TABLE;
    if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE post_id = %d LIMIT 1", $post_id ) ) ) { return; }

    $url   = wpap_public_permalink( $post_id );
    $image = wpap_fb_image_url( $post_id );
    $hook  = (string) get_post_meta( $post_id, '_wpap_fb_hook', true );
    $title = get_the_title( $post_id );

    $wpdb->insert( $table, array(
        'post_id'    => $post_id,
        'title'      => $title,
        'post_url'   => $url,
        'image_url'  => $image,
        'fb_text'    => ( '' !== $hook ) ? $hook : $title,
        'fb_post_id' => '',
        'smart_link' => $url,
    ) );
}

/* The AI generator (wpap_ajax_process_title) writes its own Hub row and must NOT get a second
   from wpap_autoadd_post_to_hub. It publishes via a raw insert, not wpap_publish_article, so set
   the suppress flag for the whole request via a priority-0 pre-hook on its AJAX action (the flag
   is request-scoped and the handler ends by sending JSON, so no cleanup is needed). Bulk / Direct
   / ZIP publishing all route through wpap_publish_article, which sets the flag itself. */
add_action( 'wp_ajax_wpap_process_title', function () { $GLOBALS['wpap_suppress_hub_autoadd'] = true; }, 0 );

/* Per-post FIRST-COMMENT override. Saves (or clears) `_wpap_fb_comment` for one post from its
   Hub row, so a single article can carry bespoke first-comment text while the rest inherit the
   global default. An empty value DELETES the override (the row falls back to the global
   template). Returns the freshly composed comment for the row's link. (ported from build-final 8.43.0) */
add_action( 'wp_ajax_wpap_save_fb_comment', 'wpap_ajax_save_fb_comment' );
function wpap_ajax_save_fb_comment() {
    check_ajax_referer( 'wpap_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
    $pid = intval( $_POST['post_id'] ?? 0 );
    if ( $pid <= 0 || 'post' !== get_post_type( $pid ) ) { wp_send_json_error( 'Invalid post.' ); }
    $tpl = mb_substr( sanitize_textarea_field( (string) wp_unslash( $_POST['template'] ?? '' ) ), 0, 2000 );
    if ( '' === trim( $tpl ) ) {
        delete_post_meta( $pid, '_wpap_fb_comment' );
        $tpl = '';
    } else {
        update_post_meta( $pid, '_wpap_fb_comment', $tpl );
    }
    /* Re-derive the row's display link (canonical) so the returned preview matches the Hub/export. */
    $link = (string) wpap_public_permalink( $pid );
    wp_send_json_success( array(
        'template'   => $tpl,                                     /* '' = now inheriting the global default */
        'fb_comment' => wpap_compose_fb_comment( $link, $pid ),   /* composed preview */
    ) );
}

/* ════════════════════════════════════════════
   8a2. AJAX: DASHBOARD STATS (plugin posts at a glance)
════════════════════════════════════════════ */
add_action( 'wp_ajax_wpap_dashboard_stats', 'wpap_ajax_dashboard_stats' );
function wpap_ajax_dashboard_stats() {
    check_ajax_referer( 'wpap_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

    /* These three postmeta-JOIN COUNTs each touch every plugin post; at 100k+ posts they
       add multi-second latency to every dashboard open/refresh. The numbers are at-a-glance
       and tolerate minor staleness, so cache the assembled array for 5 minutes and invalidate
       on publish/trash transitions (wpap_purge_cache_on_publish). */
    $cached = get_transient( 'wpap_dash_stats' );
    if ( is_array( $cached ) ) {
        wp_send_json_success( $cached );
    }

    global $wpdb;
    /* Plugin posts = posts carrying the _wpap_smart_link meta. */
    $base = "FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wpap_smart_link' WHERE p.post_type = 'post'";

    $counts = array( 'publish' => 0, 'future' => 0, 'draft' => 0, 'pending' => 0, 'trash' => 0 );
    $total  = 0;
    $srows  = $wpdb->get_results( "SELECT p.post_status AS st, COUNT(*) AS c {$base} GROUP BY p.post_status", ARRAY_A );
    foreach ( (array) $srows as $r ) {
        $st = (string) $r['st'];
        $c  = (int) $r['c'];
        if ( isset( $counts[ $st ] ) ) { $counts[ $st ] = $c; }
        if ( 'trash' !== $st ) { $total += $c; }
    }

    $since = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
    $last7 = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) {$base} AND p.post_status = 'publish' AND p.post_date_gmt >= %s",
        $since
    ) );

    /* Posts with no featured image. (The whole stats array is transient-cached above, so
       this runs at most once per 5 min rather than on every dashboard poll.) */
    $no_image = (int) $wpdb->get_var(
        "SELECT COUNT(*) {$base} AND p.post_status IN ('publish','future','draft')
         AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} tm WHERE tm.post_id = p.ID AND tm.meta_key = '_thumbnail_id' )"
    );

    $data = array(
        'total'     => (int) $total,
        'published' => (int) $counts['publish'],
        'scheduled' => (int) $counts['future'],
        'drafts'    => (int) ( $counts['draft'] + $counts['pending'] ),
        'last7'     => $last7,
        'no_image'  => $no_image,
    );
    set_transient( 'wpap_dash_stats', $data, 5 * MINUTE_IN_SECONDS );
    wp_send_json_success( $data );
}

/* ════════════════════════════════════════════
   8a3. AJAX: BULK-DELETE DISTRIBUTION HUB ENTRIES
   Removes the plugin's own rows for the selected ids. Does NOT
   touch the WordPress posts (same as the per-row delete).
════════════════════════════════════════════ */
add_action( 'wp_ajax_wpap_bulk_delete_distribution', 'wpap_ajax_bulk_delete_distribution' );
function wpap_ajax_bulk_delete_distribution() {
    check_ajax_referer( 'wpap_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
    @set_time_limit( 300 );   /* force-deleting up to 500 posts can take a moment */

    global $wpdb;
    $table = $wpdb->prefix . WPAP_TABLE;

    $raw = isset( $_POST['ids'] ) ? (array) wp_unslash( $_POST['ids'] ) : array();
    $ids = array();
    foreach ( $raw as $v ) {
        $v = (int) $v;
        if ( $v > 0 ) { $ids[] = $v; }
    }
    $ids = array_values( array_unique( $ids ) );
    if ( empty( $ids ) ) { wp_send_json_error( 'No rows selected.' ); }
    if ( count( $ids ) > 500 ) { $ids = array_slice( $ids, 0, 500 ); }

    /* SAFETY: Hub delete is Hub-ONLY by default (the JS never sends delete_posts).
       If a legacy/cached client ever does, we move the post to the TRASH — which
       is recoverable — NEVER a permanent force-delete. Cap re-checked per post. */
    $post_ids_deleted = 0;
    if ( ! empty( $_POST['delete_posts'] ) ) {
        $ph       = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $post_ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$table} WHERE id IN ($ph)", $ids ) );
        foreach ( (array) $post_ids as $pid ) {
            $pid = (int) $pid;
            try {
                if ( $pid > 0 && current_user_can( 'delete_post', $pid ) && wp_trash_post( $pid ) ) {   /* Trash, recoverable — never force-delete */
                    $post_ids_deleted++;
                }
            } catch ( \Throwable $e ) {
                error_log( '[Automation Hamri] Bulk post trash failed for #' . $pid . ': ' . $e->getMessage() );
                continue;
            }
        }
    }

    $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
    $deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ($placeholders)", $ids ) );

    wp_send_json_success( array( 'deleted' => (int) $deleted, 'posts_deleted' => (int) $post_ids_deleted ) );
}

/* ════════════════════════════════════════════
   8b. AJAX: DELETE A DISTRIBUTION HUB ENTRY
   Removes the plugin's own record for a row. Does NOT touch
   the WordPress post (delete that from Posts if you want it gone —
   the before_delete_post hook below then removes the Hub row too).
════════════════════════════════════════════ */
add_action( 'wp_ajax_wpap_delete_distribution', 'wpap_ajax_delete_distribution' );
function wpap_ajax_delete_distribution() {
    check_ajax_referer( 'wpap_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

    global $wpdb;
    $table   = $wpdb->prefix . WPAP_TABLE;
    $id      = intval( $_POST['id'] ?? 0 );
    $post_id = intval( $_POST['post_id'] ?? 0 );

    /* SAFETY: Hub-only by default. If a legacy client sends delete_post, move the
       post to the TRASH (recoverable) — never a permanent force-delete. */
    $post_deleted = 0;
    if ( ! empty( $_POST['delete_post'] ) ) {
        if ( $post_id <= 0 && $id > 0 ) {
            $post_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$table} WHERE id = %d", $id ) );
        }
        if ( $post_id > 0 && current_user_can( 'delete_post', $post_id ) && wp_trash_post( $post_id ) ) {   /* Trash, recoverable */
            $post_deleted = 1;
        }
    }

    if ( $id > 0 ) {
        $deleted = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
    } elseif ( $post_id > 0 ) {
        $deleted = $wpdb->delete( $table, array( 'post_id' => $post_id ), array( '%d' ) );
    } else {
        wp_send_json_error( 'Missing row id.' );
    }

    if ( false === $deleted ) {
        error_log( '[Automation Hamri] Distribution row delete failed: ' . $wpdb->last_error );
        wp_send_json_error( 'Could not delete the entry — see the server error log.' );
    }
    wp_send_json_success( array( 'id' => $id, 'post_id' => $post_id, 'deleted' => (int) $deleted, 'post_deleted' => $post_deleted ) );
}

/* ════════════════════════════════════════════
   8c. AJAX: CLEAN UP ORPHANED HUB ENTRIES
   Removes every Hub row whose linked post has been deleted or trashed.
════════════════════════════════════════════ */
add_action( 'wp_ajax_wpap_cleanup_distribution', 'wpap_ajax_cleanup_distribution' );
function wpap_ajax_cleanup_distribution() {
    check_ajax_referer( 'wpap_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

    global $wpdb;
    $table   = $wpdb->prefix . WPAP_TABLE;
    $removed = 0;

    /* Set-based, index-backed anti-join run in bounded batches: drop rows whose linked
       post is gone (p.ID IS NULL) or trashed, for post_id>0 rows only — the exact
       semantics of the old per-row get_post_status() loop, but resolved in the DB using
       KEY post_id → wp_posts PK instead of loading the entire Hub table into PHP and
       firing one get_post() per row (which OOM'd / timed out on large sites — the very
       ones that need cleanup). MySQL multi-table DELETE forbids LIMIT, so the batch is
       bounded via an id sub-select. */
    $batch = 1000;
    $guard = 0;   /* hard stop: 1000 batches = up to 1M rows, far beyond any real Hub */
    do {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL -- set-based cleanup, table names are core/plugin-derived, batch size is an int literal
        $affected = $wpdb->query(
            "DELETE FROM {$table}
             WHERE id IN (
                 SELECT id FROM (
                     SELECT t.id
                     FROM {$table} t
                     LEFT JOIN {$wpdb->posts} p ON p.ID = t.post_id
                     WHERE t.post_id > 0 AND ( p.ID IS NULL OR p.post_status = 'trash' )
                     LIMIT {$batch}
                 ) x
             )"
        );
        $affected = is_numeric( $affected ) ? (int) $affected : 0;
        $removed += $affected;
    } while ( $affected === $batch && ++$guard < 1000 );

    wp_send_json_success( array( 'removed' => $removed ) );
}

/* Keep the Hub in sync with WordPress: drop a post's Hub row when it is either
   permanently deleted OR moved to the Trash (fixes the long-standing complaint that
   removing a post left a ghost row in the Distribution Hub), and RE-INSERT the row
   when a trashed post is restored so a routine trash→restore never loses the item. */
add_action( 'before_delete_post', 'wpap_remove_distribution_row_for_post' );
add_action( 'trashed_post',       'wpap_remove_distribution_row_for_post' );
add_action( 'untrashed_post',     'wpap_restore_distribution_row_for_post' );
function wpap_remove_distribution_row_for_post( $post_id ) {
    global $wpdb;
    $wpdb->delete( $wpdb->prefix . WPAP_TABLE, array( 'post_id' => (int) $post_id ), array( '%d' ) );
    /* Tombstone the automation source key so the Sheet cron never RECREATES a post
       the operator deliberately deleted. Runs while the post meta still exists.
       Without it, once the seen cache ages out (120d / 5000-LRU) the still-present
       Sheet row would be republished. */
    $key = (string) get_post_meta( (int) $post_id, '_wpap_source_key', true );
    if ( '' !== $key ) {
        wpap_automation_tombstone_key( $key );
    }
    /* Permanent delete fires before_delete_post but NOT transition_post_status, so refresh
       the content caches here (guarded to plugin posts) — a deleted post must drop out of
       every sibling's related block and the dashboard counts. Trash is already covered by
       the transition hook; a redundant bump here is harmless. */
    if ( get_post_meta( (int) $post_id, '_wpap_smart_link', true ) ) {
        wpap_invalidate_content_caches();
    }
}

/* Tombstone a deleted source key so the Sheet cron never RECREATES a post the operator
   deliberately deleted. A single INSERT IGNORE into the indexed wpap_tombstones table:
   O(1), inherently race-safe (the PRIMARY KEY makes concurrent inserts of the same key a
   no-op, so no lock is needed), and unbounded without the old ~1.2MB option rewrite. */
function wpap_automation_tombstone_key( $key ) {
    $key = (string) $key;
    if ( '' === $key ) { return; }
    global $wpdb;
    $table = $wpdb->prefix . WPAP_TOMB_TABLE;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- durable dedup record, keyed insert
    $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (source_key) VALUES (%s)", substr( $key, 0, 191 ) ) );
}

/* Restore a trashed post's Hub row from its surviving post meta, so an operator who
   trashes then restores a post gets the Distribution item back (the caption/image/
   link all persist in post meta). Only acts on THIS plugin's posts, and never
   duplicates an existing row. The source-key tombstone is intentionally NOT lifted:
   the restored post already carries its _wpap_source_key meta, so the Sheet cron
   still treats that row as handled and won't create a duplicate. */
function wpap_restore_distribution_row_for_post( $post_id ) {
    global $wpdb;
    $post_id = (int) $post_id;
    $smart   = (string) get_post_meta( $post_id, '_wpap_smart_link', true );
    if ( '' === $smart ) { return; }   /* not a plugin-managed post */
    $table   = $wpdb->prefix . WPAP_TABLE;
    $exists  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE post_id = %d", $post_id ) );
    if ( $exists > 0 ) { return; }
    $wpdb->insert( $table, array(
        'post_id'    => $post_id,
        'title'      => get_the_title( $post_id ),
        'post_url'   => wpap_public_permalink( $post_id ),
        'image_url'  => wpap_fb_image_url( $post_id ),
        'fb_text'    => (string) get_post_meta( $post_id, '_wpap_fb_hook', true ),
        'fb_post_id' => '',
        'smart_link' => $smart,
    ) );
}

/* ════════════════════════════════════════════
   CACHE PURGE — make new/scheduled posts appear on cached blog pages.
   Scheduled posts publish silently via WP-Cron, so a page cache (LiteSpeed,
   etc.) keeps serving a stale homepage/blog until it is purged. These hooks
   purge automatically whenever one of THIS plugin's posts goes live.
════════════════════════════════════════════ */
function wpap_purge_caches() {
    /* LiteSpeed Cache — official integration action (no-op if not installed). */
    do_action( 'litespeed_purge_all', 'WP Automator Pro published a post' );
    /* Other common page caches. */
    if ( function_exists( 'rocket_clean_domain' ) )  { rocket_clean_domain(); }          /* WP Rocket */
    if ( function_exists( 'w3tc_flush_all' ) )       { w3tc_flush_all(); }               /* W3 Total Cache */
    if ( function_exists( 'wp_cache_clear_cache' ) ) { wp_cache_clear_cache(); }         /* WP Super Cache */
    if ( function_exists( 'sg_cachepress_purge_cache' ) ) { sg_cachepress_purge_cache(); } /* SiteGround */
}

/* Re-derive + persist a post's canonical link when it moved, so the Hub/export can never carry
   a stale (404 / wrong-post) url. Returns the canonical link, or '' when unresolvable (post gone —
   callers keep whatever they had). update_post_meta / $wpdb->update fire no post_updated, so no
   recursion. (ported from build-final) */
function wpap_refresh_stored_link( $pid ) {
    $pid = (int) $pid;
    if ( $pid <= 0 ) { return ''; }
    $fresh = wpap_public_permalink( $pid );
    if ( ! is_string( $fresh ) || '' === $fresh || false !== strpos( $fresh, '?p=' ) ) { return ''; }
    $stored = (string) get_post_meta( $pid, '_wpap_smart_link', true );
    if ( $fresh !== $stored ) {
        global $wpdb;
        update_post_meta( $pid, '_wpap_smart_link', $fresh );
        $wpdb->update( $wpdb->prefix . WPAP_TABLE, array( 'post_url' => $fresh, 'smart_link' => $fresh ), array( 'post_id' => $pid ) );
    }
    return $fresh;
}

/* When a managed post is re-slugged while PUBLISHED (Quick Edit / permalink edit), the stored
   post_url/smart_link becomes stale — the Hub/export would emit the OLD url, which 404s (or resolves
   to the WRONG post if that slug is later reused). The ?p= heal doesn't catch a stale-but-pretty url,
   so re-derive on update and refresh the row + meta. Managed posts only, once live. (ported from build-final) */
add_action( 'post_updated', 'wpap_sync_distribution_permalink', 10, 3 );
function wpap_sync_distribution_permalink( $post_id, $post_after, $post_before ) {
    $post_id = (int) $post_id;
    if ( ! $post_id ) { return; }
    if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) { return; }
    if ( ! ( $post_after instanceof WP_Post ) || 'post' !== $post_after->post_type ) { return; }
    if ( 'publish' !== $post_after->post_status ) { return; }
    if ( '' === (string) get_post_meta( $post_id, '_wpap_smart_link', true ) ) { return; }   /* managed posts only */
    wpap_refresh_stored_link( $post_id );
}

/* Invalidate the plugin's CONTENT caches (dashboard stats + related-posts blocks) when
   the published-post set changes. The related block for post A embeds sibling posts'
   links/titles/thumbnails, so a per-post key can't self-invalidate correctly — trashing
   sibling B must also refresh A, and an early post that cached an empty block must pick
   up siblings published afterward. A monotonic site-wide version stamp folded into every
   related cache key busts them all at once; the tiny int is autoloaded so the front-end
   key-build read costs no extra query. */
function wpap_invalidate_content_caches() {
    delete_transient( 'wpap_dash_stats' );
    update_option( 'wpap_rel_ver', ( (int) get_option( 'wpap_rel_ver', 0 ) ) + 1, true );
}

/* Fire the content-cache invalidation whenever a plugin post's smart-link meta is written
   — this is the reliable "a plugin post was created/(re)published" signal that fires on
   EVERY path (bulk, automation, Direct Publish, and the AI generator), including cases
   where transition_post_status runs before the marker meta exists. Scoped to the one key,
   and update_metadata skips the hook when the value is unchanged, so it is not hot. */
add_action( 'added_post_meta',   'wpap_content_cache_on_smartlink', 10, 3 );
add_action( 'updated_post_meta', 'wpap_content_cache_on_smartlink', 10, 3 );
function wpap_content_cache_on_smartlink( $meta_id, $post_id, $meta_key ) {
    if ( '_wpap_smart_link' === $meta_key ) { wpap_invalidate_content_caches(); }
}

/* Fires when any post transitions INTO the published state — including a
   scheduled post going live via cron. Scoped to this plugin's posts. */
add_action( 'transition_post_status', 'wpap_purge_cache_on_publish', 10, 3 );
function wpap_purge_cache_on_publish( $new_status, $old_status, $post ) {
    if ( empty( $post->ID ) || 'post' !== $post->post_type )      return;
    /* Only react to posts this plugin created (they carry a smart-link meta). */
    if ( ! get_post_meta( $post->ID, '_wpap_smart_link', true ) ) return;
    /* Any status change of a plugin post makes the cached dashboard counts stale and can
       change related-posts blocks across the whole category (a scheduled post going live,
       or a post being trashed, adds/removes a sibling). Bust both caches site-wide. */
    wpap_invalidate_content_caches();
    /* Page-cache purge only when a post actually goes live (incl. a scheduled post
       published by cron). */
    if ( 'publish' !== $new_status || 'publish' === $old_status ) return;
    /* A SCHEDULED post goes live via WP-Cron (transition, but NOT post_updated), so
       wpap_sync_distribution_permalink never runs on go-live. If the slug was edited during
       the `future` window the stored link would 404. Re-derive + persist now. (build-final) */
    wpap_refresh_stored_link( (int) $post->ID );
    wpap_purge_caches();
}

/* ════════════════════════════════════════════
   9. AJAX: IMAGE PROXY (clipboard CORS fix)
════════════════════════════════════════════ */
add_action( 'wp_ajax_wpap_proxy_image', 'wpap_ajax_proxy_image' );
function wpap_ajax_proxy_image() {
    check_ajax_referer( 'wpap_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized', 403 );
    $url = esc_url_raw( wp_unslash( $_GET['url'] ?? '' ) );
    if ( ! $url ) wp_die( 'No URL', 400 );
    if ( wp_parse_url( $url, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) wp_die( 'Forbidden', 403 );
    if ( false !== strpos( $url, '..' ) ) wp_die( 'Forbidden', 403 );   /* reject path traversal outright */

    $upload = wp_upload_dir();
    $base   = trailingslashit( $upload['baseurl'] );
    if ( 0 !== strpos( $url, $base ) ) wp_die( 'Forbidden', 403 );      /* must be an uploads URL */

    $file = str_replace( $base, trailingslashit( $upload['basedir'] ), $url );

    /* Containment check: the resolved path MUST sit inside the uploads dir.
       realpath() collapses any ../ and resolves symlinks; wp_normalize_path()
       makes the prefix comparison correct on Windows servers too. */
    $real = realpath( $file );
    $root = realpath( $upload['basedir'] );
    if ( false === $real || false === $root ) wp_die( 'Not found', 404 );
    if ( 0 !== strpos( wp_normalize_path( $real ), trailingslashit( wp_normalize_path( $root ) ) ) ) {
        wp_die( 'Forbidden', 403 );
    }

    /* Resolve MIME safely. fileinfo (mime_content_type) is disabled on some
       hosts and would fatal here, white-screening the proxy. Prefer WP's
       extension-based lookup, fall back to fileinfo if present, then default. */
    $ct   = wp_check_filetype( $real );
    $mime = ! empty( $ct['type'] ) ? $ct['type'] : '';
    if ( ! $mime && function_exists( 'mime_content_type' ) ) {
        $detected = @mime_content_type( $real );
        if ( $detected ) { $mime = $detected; }
    }
    if ( ! $mime ) { $mime = 'application/octet-stream'; }
    header( 'Content-Type: ' . $mime );
    header( 'Cache-Control: max-age=86400' );
    readfile( $real );
    exit;
}

/* ════════════════════════════════════════════
   8b. GOOGLE-SHEET AUTO-PUBLISH AUTOMATION
   Keyless: the user "Publishes" their Google Sheet
   to the web as CSV; we fetch, dedup, and publish
   ready-made rows via wpap_publish_article().
   Options (all autoload=no): wpap_automation (settings),
   wpap_automation_seen (dedup key=>ts), wpap_automation_count
   ({date,count}), wpap_automation_status (readout),
   wpap_automation_fails (key=>fail count).
   NOTE: this module does NOT touch AI content/image generation.
════════════════════════════════════════════ */

