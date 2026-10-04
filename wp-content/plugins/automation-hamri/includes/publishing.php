<?php
/**
 * Distribution import, article publish, bulk publish, JSON export, title processing (process_title nests the AI hook/title generators — DO NOT EDIT the AI portions)
 *
 * Extracted verbatim from wp-automator-pro.php (single-file → modular).
 * Load order is fixed by the main file; every hook self-registers here.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function wpap_ajax_bulk_import_distribution() {
    check_ajax_referer( 'wpap_nonce', 'nonce' );
    @set_time_limit( 300 );
    @ignore_user_abort( true );
    @ini_set( 'max_execution_time', '300' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

    $raw_items = trim( (string) wp_unslash( $_POST['items'] ?? '' ) );
    if ( '' === $raw_items ) {
        wp_send_json_error( 'Paste a JSON array first.' );
    }

    if ( strlen( $raw_items ) > wpap_bulk_max_bytes() ) {
        wp_send_json_error( sprintf(
            'Payload too large (%d KB). Maximum is %d KB — split it into smaller batches.',
            (int) round( strlen( $raw_items ) / 1024 ),
            (int) round( wpap_bulk_max_bytes() / 1024 )
        ) );
    }

    $payload = json_decode( $raw_items, true );
    if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $payload ) ) {
        wp_send_json_error( 'Invalid JSON. Expected an array like [{"caption":"Hello","comment":"link","imageUrl":"https://..."}].' );
    }

    if ( isset( $payload['items'] ) && is_array( $payload['items'] ) ) {
        $payload = $payload['items'];
    } elseif ( isset( $payload['title'] ) || isset( $payload['caption'] ) || isset( $payload['comment'] ) || isset( $payload['imageUrl'] ) || isset( $payload['image_url'] ) || isset( $payload['image'] ) ) {
        $payload = array( $payload );
    }

    $items = array_values( array_filter( $payload, 'is_array' ) );
    if ( empty( $items ) ) {
        wp_send_json_error( 'No valid items found in the JSON payload.' );
    }

    global $wpdb;
    $table    = $wpdb->prefix . WPAP_TABLE;
    $created  = array();
    $messages = array();

    /* Cap batch size to bound worker time on a single request. */
    $wpap_max_items = wpap_bulk_max_items();
    if ( count( $items ) > $wpap_max_items ) {
        $messages[] = sprintf(
            '%d item(s) ignored: this batch is capped at %d items per request.',
            count( $items ) - $wpap_max_items,
            $wpap_max_items
        );
        $items = array_slice( $items, 0, $wpap_max_items );
    }

    foreach ( $items as $index => $item ) {
        $row_number = $index + 1;

        /* Per-item fatal isolation (matches wpap_ajax_bulk_publish_posts): a throwing
           save_post / term hook on ONE row must not abort the whole batch. */
        try {
        /* Prefer a Facebook-specific image for the poster row (this box builds FB-poster rows,
           and extraction uses the Facebook image); fall back to the single blog image. */
        $image_raw = $item['fbImageUrl'] ?? $item['fbImage'] ?? $item['imageUrl'] ?? $item['image_url'] ?? $item['image'] ?? '';
        $image_url = esc_url_raw( trim( is_scalar( $image_raw ) ? (string) $image_raw : '' ) );

        /* Field mapping is SYMMETRIC with the JSON export {caption, comment, imageUrl}, so an
           exported file re-imports faithfully (ported from build-final 8.45.0):
             caption → the FB post caption (the HOOK, _wpap_fb_hook / fb_text)
             comment → the FIRST-COMMENT template (_wpap_fb_comment) — the text with {{link}}.
                       A BARE URL in `comment` is a legacy "comment == link" file, NOT a template,
                       so it is not stored as one (the row still gets its own real permalink).
           (Before this, `comment` was misread as the hook and `caption` as the title, so a
           submitted first-comment template silently became the caption.) */
        $caption_raw = $item['caption'] ?? $item['hook'] ?? $item['fb_text'] ?? '';
        $hook_text   = sanitize_textarea_field( wp_unslash( is_scalar( $caption_raw ) ? (string) $caption_raw : '' ) );

        $comment_raw = $item['comment'] ?? $item['fb_comment'] ?? '';
        $comment_tpl = sanitize_textarea_field( wp_unslash( is_scalar( $comment_raw ) ? (string) $comment_raw : '' ) );
        $comment_is_template = ( '' !== trim( $comment_tpl ) ) && ! preg_match( '#^https?://\S+$#', trim( $comment_tpl ) );

        /* Title: explicit `title` if given, else derived from the caption/hook. */
        $title_raw = $item['title'] ?? '';
        $title     = sanitize_text_field( wp_unslash( is_scalar( $title_raw ) ? (string) $title_raw : '' ) );
        if ( '' === $title && '' !== $hook_text ) {
            $title = wp_trim_words( wp_strip_all_tags( $hook_text ), 10, '' );
        }
        if ( '' === $title ) {
            $title = sprintf( 'Imported Item %d', $row_number );
        }
        if ( '' === $hook_text ) {
            $hook_text = $title;
        }

        $stored_image_url = $image_url;

        /* This handler writes its own Hub row below — suppress the whole-blog auto-add for
           this insert (see wpap_autoadd_post_to_hub). */
        $GLOBALS['wpap_suppress_hub_autoadd'] = true;
        $post_id   = wp_insert_post( array(
            'post_author'    => get_current_user_id(),
            'post_title'     => $title,
            'post_content'   => $hook_text,
            'post_status'    => 'publish',
            'post_type'      => 'post',
            'comment_status' => 'open',
            'ping_status'    => 'open',
        ), true );
        unset( $GLOBALS['wpap_suppress_hub_autoadd'] );

        if ( is_wp_error( $post_id ) || ! $post_id ) {
            $messages[] = sprintf(
                'Row %d skipped: %s',
                $row_number,
                is_wp_error( $post_id ) ? $post_id->get_error_message() : 'post creation failed.'
            );
            continue;
        }

        $post_url   = wpap_public_permalink( $post_id );
        $smart_link = $post_url;   /* clean permalink (no ?v=) so shared links behave like a manual post */

        update_post_meta( $post_id, '_wpap_image_url',  $stored_image_url );
        update_post_meta( $post_id, '_wpap_fb_hook',    $hook_text );
        update_post_meta( $post_id, 'ah_social_hook',   $hook_text );
        update_post_meta( $post_id, '_wpap_smart_link', $smart_link );
        /* First-comment template carried through to the Hub/export (the compose helper renders {{link}}). */
        if ( $comment_is_template ) { update_post_meta( $post_id, '_wpap_fb_comment', $comment_tpl ); }

        $saved = $wpdb->insert( $table, array(
            'post_id'    => $post_id,
            'title'      => $title,
            'post_url'   => $post_url,
            'image_url'  => $stored_image_url,
            'fb_text'    => $hook_text,
            'fb_post_id' => '',
            'smart_link' => $smart_link,
        ) );

        if ( false === $saved ) {
            wp_delete_post( $post_id, true );
            $messages[] = sprintf( 'Row %d skipped: unable to save the distribution record.', $row_number );
            continue;
        }

        clean_post_cache( $post_id );

        $created[] = array(
            'id'         => (int) $wpdb->insert_id,
            'post_id'    => (int) $post_id,
            'title'      => $title,
            'post_url'   => $post_url,
            'image_url'  => $stored_image_url,
            'smart_link' => $smart_link,
        );
        } catch ( \Throwable $e ) {
            error_log( '[Automation Hamri] bulk import distribution crashed on row ' . $row_number . ': ' . $e->getMessage() );
            $messages[] = sprintf( 'Row %d failed (skipped): %s', $row_number, $e->getMessage() );
            continue;
        }
    }

    if ( empty( $created ) ) {
        wp_send_json_error( array(
            'message'  => 'No items were imported.',
            'messages' => $messages,
        ) );
    }

    wp_send_json_success( array(
        'created'  => count( $created ),
        'skipped'  => count( $items ) - count( $created ),
        'total'    => count( $items ),
        'messages' => $messages,
        'rows'     => $created,
    ) );
}

/* ════════════════════════════════════════════
   AJAX: BULK PUBLISH POSTS (NO EXTERNAL API)
   Publishes ready-made posts straight from JSON:
   [{ "title", "imageUrl", "content", "hook" }]
   - No Claude / Gemini calls.
   - Downloads imageUrl → sets it as the featured image.
   - Writes the same meta + distribution row the AI path
     does, so rows appear in the Distribution Hub and
     export as { caption:hook, comment:smart_link, imageUrl }.
════════════════════════════════════════════ */
/**
 * Publish ONE ready-made article (no AI). Shared by Direct Publish (AJAX)
 * and the Google-Sheet automation. Returns the new post ID (int) on success
 * or a WP_Error on failure.
 *
 * $item keys: title, content, imageUrl|image_url|image, hook|fb_text|comment,
 *             category (name or id, optional), parts (int, optional)
 * $opts keys: default_parts (int, default 1), schedule_window (float hrs 0–168, or "drip:N" string, default 0),
 *             default_category (string|int, optional),
 *             source_key (string, optional — stored as _wpap_source_key meta for dedup)
 * @return int|WP_Error
 */

/* True if a non-trashed post with this exact title already exists. Uses WP_Query
   (get_page_by_title is deprecated in WP 6.2) — exact post_title match, ids only. */
function wpap_post_title_exists( $title ) {
    $title = trim( (string) $title );
    if ( '' === $title ) { return false; }
    $q = new WP_Query( array(
        'post_type'      => 'post',
        'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private' ),
        'title'          => $title,
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'cache_results'  => false,
    ) );
    $found = ! empty( $q->posts );
    wp_reset_postdata();
    return $found;
}

/* Parse a human duration ("40 min", "2 hr", "1 hr 30 min", "PT1H30M", or a bare
   integer) into whole MINUTES for the _wpap_recipe_prep/cook/total meta — the SEO
   emitter (seo-schema.php) turns those minutes into an ISO-8601 PT..H..M string.
   Returns 0 when nothing parseable, so a missing time is simply omitted from schema. */
function wpap_parse_duration_to_minutes( $raw ) {
    if ( is_int( $raw ) ) { return max( 0, $raw ); }
    $s = strtolower( trim( (string) ( is_scalar( $raw ) ? $raw : '' ) ) );
    if ( '' === $s ) { return 0; }
    if ( ctype_digit( $s ) ) { return (int) $s; }                 /* bare number = minutes */
    if ( preg_match( '/^pt(?:(\d+)h)?(?:(\d+)m)?$/', $s, $m ) ) { /* ISO-8601 duration */
        return (int) ( $m[1] ?? 0 ) * 60 + (int) ( $m[2] ?? 0 );
    }
    $minutes = 0;
    /* Longest unit first + a "not followed by a letter" lookahead. (\b would fail when a
       DIGIT follows the unit — e.g. in "1h30m" the hour clause wouldn't match, dropping
       the hours and yielding 30 instead of 90.) */
    if ( preg_match( '/(\d+)\s*(?:hours?|hrs?|h)(?![a-z])/', $s, $m ) ) { $minutes += (int) $m[1] * 60; }
    if ( preg_match( '/(\d+)\s*(?:minutes?|mins?|m)(?![a-z])/', $s, $m ) ) { $minutes += (int) $m[1]; }
    return max( 0, $minutes );
}

/* Resolve a category value into a term id, creating terms as needed, and return the
   LEAF (most-specific) term id — or 0 if nothing resolves.
   Accepts, in order of precedence:
     • an existing numeric term id                       ("12")
     • a hierarchy PATH "Parent > Child > Leaf"           (each level created under its parent)
     • a bare category name                               ("Recipes")
   The path form is what lets the flat taxonomy grow sub-categories later WITHOUT any
   plugin change — the feed just starts sending "Recipes > Soups & Stews". */
function wpap_resolve_category_path( $raw ) {
    $raw = trim( (string) $raw );
    if ( '' === $raw ) { return 0; }
    if ( ctype_digit( $raw ) && term_exists( (int) $raw, 'category' ) ) { return (int) $raw; }

    $parts = array_values( array_filter( array_map( 'trim', preg_split( '/\s*>\s*/', $raw ) ) ) );
    if ( empty( $parts ) ) { return 0; }

    $parent = 0;
    $leaf   = 0;
    foreach ( $parts as $name ) {
        /* term_exists scoped to THIS parent so "Breakfast" under Recipes and under
           Tips stay distinct; falls back to any same-name term if creation collides. */
        /* Pass $parent (0 for the root segment, not null) so the lookup is scoped to that
           level — a bare "Football" resolves to a TOP-LEVEL Football, never binding to a
           nested "Sports > Football". */
        $existing = term_exists( $name, 'category', $parent );
        if ( $existing && ! is_wp_error( $existing ) ) {
            $leaf = (int) ( is_array( $existing ) ? $existing['term_id'] : $existing );
        } else {
            $new = wp_insert_term( $name, 'category', $parent ? array( 'parent' => $parent ) : array() );
            if ( is_wp_error( $new ) ) {
                /* Creation collided with an existing term: WP carries the real, parent-accurate
                   id in the error data — prefer it over a re-lookup that could match a
                   same-name term under a different parent. */
                $data = $new->get_error_data();
                if ( is_array( $data ) && ! empty( $data['term_id'] ) ) {
                    $leaf = (int) $data['term_id'];
                } elseif ( is_numeric( $data ) ) {
                    $leaf = (int) $data;
                } else {
                    $any  = term_exists( $name, 'category', $parent );
                    $leaf = ( $any && ! is_wp_error( $any ) ) ? (int) ( is_array( $any ) ? $any['term_id'] : $any ) : 0;
                }
            } else {
                $leaf = (int) $new['term_id'];
            }
        }
        if ( $leaf <= 0 ) { break; }
        $parent = $leaf;
    }
    return $leaf;
}

function wpap_publish_article( array $item, array $opts = array() ) {
    global $wpdb;
    $table = $wpdb->prefix . WPAP_TABLE;

    /* ── options ── */
    $default_parts    = isset( $opts['default_parts'] ) ? intval( $opts['default_parts'] ) : 1;
    $schedule_window  = isset( $opts['schedule_window'] ) ? $opts['schedule_window'] : 0;  /* float hours OR a "drip:N" string — wpap_compute_schedule() handles both */
    /* Ordered scheduling: the batch position + size, so scheduled posts go live
       in submission order (spread evenly across the window) instead of random. */
    $schedule_index   = isset( $opts['schedule_index'] ) ? (int) $opts['schedule_index'] : null;
    $schedule_total   = isset( $opts['schedule_total'] ) ? (int) $opts['schedule_total'] : null;
    $default_category = ( isset( $opts['default_category'] ) && is_scalar( $opts['default_category'] ) )
        ? trim( (string) $opts['default_category'] ) : '';
    $source_key       = ( isset( $opts['source_key'] ) && is_scalar( $opts['source_key'] ) )
        ? (string) $opts['source_key'] : '';
    /* Author: an explicit opt wins; otherwise a site can pin bulk-published posts to its
       real bylined writer (not whatever admin account is logged in) via the
       'wpap_default_author' filter — important for a single-author blog's E-E-A-T, so the
       byline, author archive, and Person schema all point at the writer. Default: the
       current user (unchanged behavior when no filter is set). */
    $author           = isset( $opts['author'] ) ? (int) $opts['author'] : (int) apply_filters( 'wpap_default_author', get_current_user_id() );
    $force_kses        = ! empty( $opts['force_kses'] );   /* automation forces kses on external content */

    /* ── title (legacy 'caption' fallback preserved for parity) ── */
    $title_raw = $item['title'] ?? $item['caption'] ?? '';
    $title     = sanitize_text_field( wp_unslash( is_scalar( $title_raw ) ? (string) $title_raw : '' ) );

    /* ── content (article body, HTML allowed — WP filters by capability) ── */
    $content_raw = $item['content'] ?? $item['post_content'] ?? $item['body'] ?? '';
    $content     = is_scalar( $content_raw ) ? (string) wp_unslash( $content_raw ) : '';

    /* ── hook (Facebook caption) ── */
    $hook_raw = $item['hook'] ?? $item['fb_text'] ?? $item['comment'] ?? '';
    $hook     = sanitize_textarea_field( wp_unslash( is_scalar( $hook_raw ) ? (string) $hook_raw : '' ) );

    /* ── first-comment template (Distribution Hub / export) ──
       The article feed carries a per-article `comment` (the first-comment text, with {{link}}
       where the post link goes) SEPARATE from the `hook` caption. Capture it so the export +
       "FB post" render it per article. Guards: a BARE URL is a link, not a template (don't
       store); and skip when `comment` only served as the HOOK fallback above (article had no
       distinct hook) so the caption isn't double-stored. (ported from build-final 8.46.0) */
    $fb_comment_raw    = $item['comment'] ?? $item['fb_comment'] ?? '';
    $fb_comment_tpl    = sanitize_textarea_field( wp_unslash( is_scalar( $fb_comment_raw ) ? (string) $fb_comment_raw : '' ) );
    $fb_comment_is_tpl = ( '' !== trim( $fb_comment_tpl ) )
        && ! preg_match( '#^https?://\S+$#', trim( $fb_comment_tpl ) )
        && $fb_comment_tpl !== $hook;

    /* ── image url ── */
    $image_raw = $item['imageUrl'] ?? $item['image_url'] ?? $item['image'] ?? '';
    $image_raw = is_scalar( $image_raw ) ? trim( (string) $image_raw ) : '';

    /* ── optional SEO metadata (per-item; the description auto-derives when absent) ── */
    $meta_desc_raw  = $item['metaDescription'] ?? $item['description'] ?? $item['excerpt'] ?? '';
    $meta_desc_in   = is_scalar( $meta_desc_raw ) ? sanitize_text_field( wp_unslash( (string) $meta_desc_raw ) ) : '';
    $meta_title_raw = $item['metaTitle'] ?? $item['seo_title'] ?? '';
    $meta_title     = is_scalar( $meta_title_raw ) ? sanitize_text_field( wp_unslash( (string) $meta_title_raw ) ) : '';
    $focus_kw_raw   = $item['focusKeyword'] ?? $item['keyword'] ?? '';
    $focus_kw       = is_scalar( $focus_kw_raw ) ? sanitize_text_field( wp_unslash( (string) $focus_kw_raw ) ) : '';
    $tags_raw       = $item['tags'] ?? '';

    /* ── content type → per-type schema (recipe vs article). ──
       `type`: recipe | guide | story | article (default article). A recipe is ALSO
       inferred when the item carries both ingredients AND steps, so a feed that omits
       `type` still earns Recipe schema. guide/story/article all render as Article
       (Google retired HowTo rich results in 2023, so there is no HowTo path). */
    $type_raw       = $item['type'] ?? $item['kind'] ?? '';
    $type           = strtolower( is_scalar( $type_raw ) ? trim( (string) $type_raw ) : '' );
    /* Accept ingredients/steps as an array OR a newline-delimited string (both are common
       feed shapes). */
    $wpap_to_lines  = static function ( $v ) {
        if ( is_array( $v ) ) { return $v; }
        if ( is_scalar( $v ) && '' !== trim( (string) $v ) ) { return preg_split( '/\r\n|\r|\n/', (string) $v ); }
        return array();
    };
    $ingredients_in = $wpap_to_lines( $item['ingredients'] ?? '' );
    $steps_in       = $wpap_to_lines( $item['steps'] ?? '' );
    /* An explicit non-recipe type is AUTHORITATIVE: an article/guide/story never gets
       Recipe markup even if it happens to carry ingredient/step fields. Otherwise a recipe
       is the stated type, or auto-detected from having BOTH ingredients and steps. */
    $is_recipe      = ! in_array( $type, array( 'article', 'guide', 'story', 'post', 'page' ), true )
        && ( 'recipe' === $type || ( ! empty( $ingredients_in ) && ! empty( $steps_in ) ) );

    if ( '' === $title && '' === trim( wp_strip_all_tags( $content ) ) ) {
        return new WP_Error( 'wpap_empty', 'needs at least a title or content.' );
    }
    if ( '' === $title ) {
        $title = wp_trim_words( wp_strip_all_tags( $content ), 8, '' );
        if ( '' === $title ) { $title = 'Untitled'; }
    }

    /* ── optional content guards (Settings → Content options; all OFF by default) ── */
    $copts = get_option( 'wpap_content_opts', array() );
    if ( ! is_array( $copts ) ) { $copts = array(); }

    /* Skip duplicate titles: don't create a second post with a title that already
       exists — protects against re-uploading the same file/batch. Only for
       manual/Direct-Publish (source_key empty); the Sheet automation has its own
       row-key dedup and we don't want to fight its retry logic. */
    if ( ! empty( $copts['skip_dupe_titles'] ) && '' === $source_key && wpap_post_title_exists( $title ) ) {
        return new WP_Error( 'wpap_dupe', sprintf( 'skipped: a post titled "%s" already exists.', $title ) );
    }

    /* Minimum word count: skip thin bodies (Unicode-aware; falls back safely). */
    $min_words = (int) ( $copts['min_words'] ?? 0 );
    if ( $min_words > 0 ) {
        $wc = preg_match_all( '/[\p{L}\p{N}]+/u', wp_strip_all_tags( $content ), $m );
        if ( false === $wc ) { $wc = str_word_count( wp_strip_all_tags( $content ) ); }   /* /u failed on bad UTF-8 */
        if ( (int) $wc < $min_words ) {
            return new WP_Error( 'wpap_thin', sprintf( 'skipped: %d words is below the %d-word minimum.', (int) $wc, $min_words ) );
        }
    }

    /* Comments: close them on published posts if the owner opted in (spam hygiene). */
    $comment_status = ! empty( $copts['disable_comments'] ) ? 'closed' : get_default_comment_status( 'post' );

    /* ── internal links: [[link:slug|anchor]] → a real <a href> when a PUBLISHED post owns the slug,
       else a reader-invisible, kses-safe marker the "Resolve internal links" pass upgrades once the
       target goes live (forward references across a batch self-heal). Runs on the raw body BEFORE the
       page split (tokens are inline, never straddle a <!--nextpage-->). Skips a self-link via the slug
       WordPress will derive from the title. No-op when the body carries no tokens. */
    $wpap_ilink_pending = 0;
    if ( function_exists( 'wpap_resolve_internal_links' ) ) {
        $content = wpap_resolve_internal_links( $content, sanitize_title( $title ), $wpap_ilink_pending );
    }

    /* ── split the body into pages (per-item "parts" overrides the global choice) ── */
    $item_parts = isset( $item['parts'] ) ? intval( $item['parts'] ) : $default_parts;
    if ( $item_parts < 1 )  { $item_parts = 1; }
    if ( $item_parts > 10 ) { $item_parts = 10; }
    $content_split = wpap_split_content_into_parts( $content, $item_parts );

    /* Sanitize the body unless this user may post raw HTML — OR the caller forces
       it (the automation does, because Sheet content is outside the trust boundary).
       kses runs per page so the <!--nextpage--> markers survive and the raw
       $wpdb->update below can't smuggle scripts. The wpap_ilink_kses flag lets the
       internal-link forward-ref marker's data attribute through kses for THIS pass only. */
    if ( $force_kses || ! current_user_can( 'unfiltered_html' ) ) {
        $GLOBALS['wpap_ilink_kses'] = true;
        $content_split = implode( '<!--nextpage-->', array_map(
            'wp_kses_post',
            explode( '<!--nextpage-->', $content_split )
        ) );
        unset( $GLOBALS['wpap_ilink_kses'] );
    }

    /* ── decide publish time (immediate, or a random slot in the next hours) ── */
    $sched = wpap_compute_schedule( $schedule_window, $schedule_index, $schedule_total );

    /* Meta description: use the supplied one, else auto-derive from the content. */
    $description = ( '' !== $meta_desc_in ) ? $meta_desc_in : wpap_make_excerpt( $content );

    /* ── create the post with REAL content (no AI) ── */
    /* Suppress the whole-blog auto-add hook for THIS insert: the plugin writes its own
       richer Hub row below, so wpap_autoadd_post_to_hub must not also insert one when the
       transition fires inside wp_insert_post. Cleared immediately after (autoadd runs during
       the insert, so the window is exactly this call). */
    $GLOBALS['wpap_suppress_hub_autoadd'] = true;
    $post_id = wp_insert_post( array(
        'post_author'   => $author,
        'post_title'    => wp_slash( $title ),
        'post_content'  => wp_slash( $content_split ),
        'post_excerpt'  => wp_slash( $description ),   /* clean meta description */
        'post_status'    => $sched['status'],
        'post_date'      => $sched['date'],
        'post_date_gmt'  => $sched['date_gmt'],
        'post_type'      => 'post',
        'comment_status' => $comment_status,
        /* Write the dedup anchor in the SAME insert so it survives even if a later
           step (category / image import / a third-party save_post hook) throws. The
           automation idempotency check keys on this meta, so an orphaned post
           WITHOUT it would be re-published as a duplicate on the next cron run. */
        'meta_input'     => ( '' !== $source_key ? array( '_wpap_source_key' => $source_key ) : array() ),
    ), true );
    unset( $GLOBALS['wpap_suppress_hub_autoadd'] );

    if ( is_wp_error( $post_id ) ) {
        return $post_id;
    }
    if ( ! $post_id ) {
        return new WP_Error( 'wpap_insert_failed', 'post creation failed.' );
    }
    $post_id = (int) $post_id;

    /* Guarantee the <!--nextpage--> markers survive kses (mirrors the AI path,
       which bypasses wp_insert_post for exactly this reason). */
    if ( $content_split !== $content && false !== strpos( $content_split, '<!--nextpage-->' ) ) {
        $wpdb->update( $wpdb->posts, array( 'post_content' => $content_split ), array( 'ID' => $post_id ) );
        clean_post_cache( $post_id );
    }

    /* ── category: item['category'] (name or numeric id) else default_category ── */
    $cat_raw = '';
    if ( isset( $item['category'] ) && is_scalar( $item['category'] ) ) {
        $cat_raw = trim( (string) wp_unslash( $item['category'] ) );
    }
    if ( '' === $cat_raw ) {
        $cat_raw = $default_category;
    }
    if ( '' !== $cat_raw ) {
        /* Resolve a bare name, a numeric id, OR a "Parent > Child" hierarchy path
           (each level created lazily). The leaf term becomes the post's category, so a
           flat feed ("Recipes") and a future nested one ("Recipes > Soups & Stews")
           both work with no plugin change. */
        $term_id = wpap_resolve_category_path( $cat_raw );
        if ( $term_id > 0 ) {
            wp_set_object_terms( $post_id, $term_id, 'category' );
        }
    }

    /* ── image → set as featured (non-fatal: failure never blocks publish) ──
       A LOCAL file (opts['local_image_path'], e.g. extracted from a Bulk ZIP bundle) is
       sideloaded straight from disk — no hosting/public URL needed; otherwise the remote
       image_raw is fetched. Both wire the featured image the same way via
       wpap_apply_featured_attachment(). (local branch ported from build-final 8.49.0) */
    $image_url        = '';
    $local_image_path = isset( $opts['local_image_path'] ) ? (string) $opts['local_image_path'] : '';
    if ( '' !== $local_image_path && @is_file( $local_image_path ) && @is_readable( $local_image_path ) ) {
        $attach_id = wpap_import_local_image_as_attachment( $local_image_path, $post_id, $title );
        if ( ! is_wp_error( $attach_id ) && $attach_id ) {
            $image_url = wpap_apply_featured_attachment( $post_id, (int) $attach_id, $title );
        }
    } elseif ( '' !== $image_raw && wp_http_validate_url( $image_raw ) ) {
        $attach_id = wpap_import_remote_image_as_attachment( $image_raw, $post_id, $title );
        if ( ! is_wp_error( $attach_id ) && $attach_id ) {
            $image_url = wpap_apply_featured_attachment( $post_id, (int) $attach_id, $title );
        }
    }

    /* Descriptive featured-image alt (SEO / Google Images). The importer defaults the
       attachment alt to the post title; a per-item `image_alt` overrides it with
       something more descriptive when the feed supplies one. */
    $image_alt_raw = $item['image_alt'] ?? $item['imageAlt'] ?? $item['alt'] ?? '';
    $image_alt     = is_scalar( $image_alt_raw ) ? sanitize_text_field( wp_unslash( (string) $image_alt_raw ) ) : '';
    if ( '' !== $image_alt && isset( $attach_id ) && is_int( $attach_id ) && $attach_id > 0 ) {
        update_post_meta( (int) $attach_id, '_wp_attachment_image_alt', $image_alt );
    }

    if ( '' === $hook ) { $hook = $title; }

    $post_url   = wpap_public_permalink( $post_id );
    $smart_link = $post_url;   /* clean permalink (no ?v=) so shared links behave like a manual post */

    /* ── meta (mirror the AI path + import handler) ── */
    update_post_meta( $post_id, '_wpap_image_url',  $image_url );

    /* ── Facebook image (optional, separate from the blog featured image) ──
       A post can carry a DIFFERENT image for Facebook than for the blog. A LOCAL zip file
       (opts['local_fb_image_path']) is sideloaded to a hosted attachment; a remote fbImageUrl is
       stored as-is. Kept in _wpap_fb_image_url; the Distribution Hub export uses it, falling back
       to the blog image when absent so one image can serve both. Never featured; never fatal. */
    $fb_raw        = $item['fbImageUrl'] ?? $item['fbImage'] ?? $item['facebook_image'] ?? $item['fb_image'] ?? '';
    $fb_raw        = is_scalar( $fb_raw ) ? trim( (string) $fb_raw ) : '';
    $local_fb_path = isset( $opts['local_fb_image_path'] ) ? (string) $opts['local_fb_image_path'] : '';
    $fb_image_url  = '';
    if ( '' !== $local_fb_path && @is_file( $local_fb_path ) && @is_readable( $local_fb_path ) ) {
        $fb_att = wpap_import_local_image_as_attachment( $local_fb_path, $post_id, $title . ' (Facebook)' );
        if ( ! is_wp_error( $fb_att ) && $fb_att ) {
            $fb_src = wp_get_attachment_image_url( (int) $fb_att, 'full' );
            if ( $fb_src ) { $fb_image_url = (string) $fb_src; }
        }
    } elseif ( '' !== $fb_raw && wp_http_validate_url( $fb_raw ) ) {
        $fb_image_url = esc_url_raw( $fb_raw );
    }
    if ( '' !== $fb_image_url ) { update_post_meta( $post_id, '_wpap_fb_image_url', $fb_image_url ); }

    update_post_meta( $post_id, '_wpap_fb_hook',    $hook );
    update_post_meta( $post_id, 'ah_social_hook',   $hook );
    update_post_meta( $post_id, '_wpap_smart_link', $smart_link );
    /* Stable content-hash dedup anchor: the automation cron checks this (via
       wpap_automation_alt_key_is_done) so a Sheet `id`-column change — which flips every row's
       primary key at once — can't make the archive look new and re-publish as duplicates. Stored
       for all publish paths (harmless; makes cross-path content dedup work too). */
    if ( '' !== trim( (string) ( $item['title'] ?? '' ) ) || '' !== trim( (string) ( $item['content'] ?? '' ) ) ) {
        update_post_meta( $post_id, '_wpap_source_alt_key', wpap_automation_content_key( $item ) );
    }
    /* Per-article first-comment template (renders {{link}} in the export / "FB post"). */
    if ( $fb_comment_is_tpl ) { update_post_meta( $post_id, '_wpap_fb_comment', $fb_comment_tpl ); }
    /* _wpap_source_key is written via wp_insert_post( meta_input ) above — early and
       atomic, so a throw in the category/image/SEO steps can't orphan the dedup key. */

    /* ── SEO metadata: meta description / title / focus keyword into whichever
       SEO plugin is active (Yoast, Rank Math). The description also lives in
       post_excerpt, which the plugin's own <head> emitter uses when no SEO
       plugin is installed — so a proper meta description is set either way.
       Optional per-item `noindex` (thin / utility / near-duplicate posts): true keeps
       the post out of search — enforced via wpap_robots_honor_noindex() even with no
       SEO plugin — and false explicitly re-indexes; omit to leave it indexable. ── */
    $seo_args = array();
    if ( array_key_exists( 'noindex', $item ) && null !== $item['noindex'] ) {
        $seo_args['noindex'] = filter_var( $item['noindex'], FILTER_VALIDATE_BOOLEAN );
    }
    wpap_set_seo_meta( $post_id, $description, $meta_title, $focus_kw, $seo_args );

    /* ── tags (array or comma-separated string) ── */
    $tags = array();
    if ( is_array( $tags_raw ) ) {
        foreach ( $tags_raw as $tg ) {
            if ( is_scalar( $tg ) ) {
                $t = sanitize_text_field( wp_unslash( (string) $tg ) );
                if ( '' !== $t ) { $tags[] = $t; }
            }
        }
    } elseif ( is_scalar( $tags_raw ) && '' !== trim( (string) $tags_raw ) ) {
        foreach ( explode( ',', (string) wp_unslash( $tags_raw ) ) as $tg ) {
            $t = sanitize_text_field( $tg );
            if ( '' !== $t ) { $tags[] = $t; }
        }
    }
    if ( ! empty( $tags ) ) {
        $tags = array_slice( array_values( array_unique( $tags ) ), 0, 15 );
        wp_set_post_terms( $post_id, $tags, 'post_tag', false );
    }

    /* ── recipe payload → schema.org/Recipe (per-type SEO). ──
       Setting _wpap_recipe_on + the ingredient/step/time meta makes the theme render
       the recipe card AND the theme/plugin emit Recipe JSON-LD automatically — the
       same meta the manual editor writes, so a bulk-imported recipe is a first-class
       recipe with zero manual steps. Guarded by $is_recipe so an article never gets
       recipe meta. This is a STRUCTURED-DATA mapping, not the AI recipe extractor. */
    if ( $is_recipe ) {
        $clean_lines = static function ( $arr ) {
            $out = array();
            foreach ( (array) $arr as $line ) {
                if ( ! is_scalar( $line ) ) { continue; }
                $t = sanitize_text_field( wp_unslash( (string) $line ) );
                if ( '' !== $t ) { $out[] = $t; }
            }
            /* Bound the lists so a malformed feed can't bloat one post's meta. */
            return array_slice( $out, 0, 60 );
        };
        $ing = $clean_lines( $ingredients_in );
        $stp = $clean_lines( $steps_in );

        /* Require BOTH lists to survive cleaning before flagging a recipe: a recipe-typed
           item with missing/partial data publishes as a valid Article instead of emitting a
           broken Recipe (recipeIngredient without recipeInstructions, or vice-versa). */
        if ( ! empty( $ing ) && ! empty( $stp ) ) {
            $prep  = wpap_parse_duration_to_minutes( $item['prep'] ?? $item['prepTime'] ?? '' );
            $cook  = wpap_parse_duration_to_minutes( $item['cook'] ?? $item['cookTime'] ?? '' );
            /* Prefer an explicit total; else derive it from prep + cook. */
            $total = wpap_parse_duration_to_minutes( $item['total'] ?? $item['totalTime'] ?? '' );
            if ( $total <= 0 ) { $total = $prep + $cook; }
            $serv_raw = $item['servings'] ?? $item['yield'] ?? '';
            $serv = is_scalar( $serv_raw ) ? sanitize_text_field( wp_unslash( (string) $serv_raw ) ) : '';
            /* recipeCategory (schema.org): the COURSE / meal type — "Main course",
               "Dessert", "Home remedy" — NOT the blog category. Accept `course` or the
               schema-native `recipeCategory` key. Omitted when absent (it is a
               recommended, not required, Recipe field). */
            $course_raw = $item['course'] ?? $item['recipeCategory'] ?? '';
            $course = is_scalar( $course_raw ) ? sanitize_text_field( wp_unslash( (string) $course_raw ) ) : '';

            update_post_meta( $post_id, '_wpap_recipe_on', '1' );
            update_post_meta( $post_id, '_wpap_recipe_ingredients', implode( "\n", $ing ) );
            update_post_meta( $post_id, '_wpap_recipe_steps', implode( "\n", $stp ) );
            if ( '' !== $serv )   { update_post_meta( $post_id, '_wpap_recipe_servings', $serv ); }
            if ( '' !== $course ) { update_post_meta( $post_id, '_wpap_recipe_course', $course ); }
            if ( $prep > 0 )      { update_post_meta( $post_id, '_wpap_recipe_prep', (string) $prep ); }
            if ( $cook > 0 )      { update_post_meta( $post_id, '_wpap_recipe_cook', (string) $cook ); }
            if ( $total > 0 )     { update_post_meta( $post_id, '_wpap_recipe_total', (string) $total ); }
        }
    }

    /* ── curated internal links: a per-item `related` list of slugs, stored for the theme /
       related-posts block to render as hand-picked cross-links (the block falls back to
       auto-by-category when this is absent). Accepts an array or a comma/newline string. */
    $related_raw   = $item['related'] ?? $item['related_articles'] ?? '';
    $related_slugs = array();
    if ( is_array( $related_raw ) ) {
        foreach ( $related_raw as $rs ) {
            if ( is_scalar( $rs ) ) { $s = sanitize_title( (string) $rs ); if ( '' !== $s ) { $related_slugs[] = $s; } }
        }
    } elseif ( is_scalar( $related_raw ) && '' !== trim( (string) $related_raw ) ) {
        foreach ( preg_split( '/[,\r\n]+/', (string) $related_raw ) as $rs ) {
            $s = sanitize_title( $rs ); if ( '' !== $s ) { $related_slugs[] = $s; }
        }
    }
    if ( ! empty( $related_slugs ) ) {
        update_post_meta( $post_id, '_wpap_related_manual', array_slice( array_values( array_unique( $related_slugs ) ), 0, 10 ) );
    }

    /* ── auto-link keywords: a per-item `keywords` list of phrases this post should be the internal-link
       TARGET for. Stored as a comma list in _wpap_keywords; the "Auto-link keywords" pass (and the
       auto-run after a bulk publish) then links the first mention of each phrase in OTHER posts to this
       one. Accepts an array or a comma/newline string. Bounded so a malformed feed can't bloat meta. */
    $keywords_raw = $item['keywords'] ?? $item['link_keywords'] ?? '';
    $kw_list      = array();
    if ( is_array( $keywords_raw ) ) {
        foreach ( $keywords_raw as $kw ) {
            if ( is_scalar( $kw ) ) { $k = sanitize_text_field( wp_unslash( (string) $kw ) ); if ( '' !== $k ) { $kw_list[] = $k; } }
        }
    } elseif ( is_scalar( $keywords_raw ) && '' !== trim( (string) $keywords_raw ) ) {
        foreach ( preg_split( '/[,\r\n]+/', (string) wp_unslash( $keywords_raw ) ) as $kw ) {
            $k = sanitize_text_field( $kw ); if ( '' !== $k ) { $kw_list[] = $k; }
        }
    }
    if ( ! empty( $kw_list ) ) {
        $kw_list = array_slice( array_values( array_unique( $kw_list ) ), 0, 12 );
        update_post_meta( $post_id, '_wpap_keywords', implode( ', ', $kw_list ) );
    }

    /* ── distribution row ── */
    /* The Hub row carries the FACEBOOK-preferred image so the export/auto-poster use it: the FB
       image when one was supplied (fbImage/local_fb_image_path, computed above), else the blog
       image so a single image still serves both. The export reads row.image_url directly, so the
       preference must be baked into the row here — matching wpap_autoadd_post_to_hub /
       wpap_restore_distribution_row_for_post, which resolve it via wpap_fb_image_url(). */
    $saved = $wpdb->insert( $table, array(
        'post_id'    => $post_id,
        'title'      => $title,
        'post_url'   => $post_url,
        'image_url'  => ( '' !== $fb_image_url ? $fb_image_url : $image_url ),
        'fb_text'    => $hook,
        'fb_post_id' => '',
        'smart_link' => $smart_link,
    ) );

    if ( false === $saved ) {
        /* The post is already live; a failed Hub-row insert must NOT discard it
           (that would orphan a published post and, for the automation, cause the
           same Sheet row to be republished next run). The post_id is the source
           of truth; the Hub row is best-effort and can self-heal on next listing. */
        error_log( '[Automation Hamri] Distribution row insert failed for post ' . $post_id . ': ' . $wpdb->last_error );
    }

    clean_post_cache( $post_id );

    return $post_id;
}

add_action( 'wp_ajax_wpap_bulk_publish_posts', 'wpap_ajax_bulk_publish_posts' );
function wpap_ajax_bulk_publish_posts() {
    check_ajax_referer( 'wpap_nonce', 'nonce' );
    @set_time_limit( 600 );
    @ignore_user_abort( true );
    @ini_set( 'max_execution_time', '600' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

    $raw_items = trim( (string) wp_unslash( $_POST['items'] ?? '' ) );
    if ( '' === $raw_items ) {
        wp_send_json_error( 'Paste a JSON array first.' );
    }

    if ( strlen( $raw_items ) > wpap_bulk_max_bytes() ) {
        wp_send_json_error( sprintf(
            'Payload too large (%d KB). Maximum is %d KB — split it into smaller batches.',
            (int) round( strlen( $raw_items ) / 1024 ),
            (int) round( wpap_bulk_max_bytes() / 1024 )
        ) );
    }

    $payload = json_decode( $raw_items, true );
    if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $payload ) ) {
        wp_send_json_error( 'Invalid JSON. Expected an array like [{"title":"...","imageUrl":"...","content":"...","hook":"..."}].' );
    }

    /* Accept {items:[...]} wrappers and a single bare object too. */
    if ( isset( $payload['items'] ) && is_array( $payload['items'] ) ) {
        $payload = $payload['items'];
    } elseif ( isset( $payload['title'] ) || isset( $payload['content'] ) || isset( $payload['hook'] ) || isset( $payload['imageUrl'] ) || isset( $payload['image_url'] ) ) {
        $payload = array( $payload );
    }

    $items = array_values( array_filter( $payload, 'is_array' ) );
    if ( empty( $items ) ) {
        wp_send_json_error( 'No valid items found in the JSON payload.' );
    }

    global $wpdb;
    $table    = $wpdb->prefix . WPAP_TABLE;
    $created  = array();
    $messages = array();

    /* Cap batch size to bound worker time on a single request. */
    $wpap_max_items = wpap_bulk_max_items();
    if ( count( $items ) > $wpap_max_items ) {
        $messages[] = sprintf(
            '%d item(s) ignored: this batch is capped at %d items per request.',
            count( $items ) - $wpap_max_items,
            $wpap_max_items
        );
        $items = array_slice( $items, 0, $wpap_max_items );
    }

    /* ── Publish options (apply to every item; a per-item "parts" wins) ── */
    $default_parts = intval( $_POST['num_parts'] ?? 1 );
    if ( $default_parts < 1 )  { $default_parts = 1; }
    if ( $default_parts > 10 ) { $default_parts = 10; }

    $schedule_window = isset( $_POST['schedule_window'] ) ? (float) $_POST['schedule_window'] : 0;
    if ( $schedule_window < 0 )   { $schedule_window = 0; }
    if ( $schedule_window > 168 ) { $schedule_window = 168; }   /* cap at 1 week */

    /* Optional default category (name or id) applied when an item has none. */
    $default_category = sanitize_text_field( wp_unslash( $_POST['default_category'] ?? '' ) );

    /* Concurrency guard: a double-click or a client retry must not publish the
       same batch twice. Hold a short lock for the run; a stale lock auto-expires
       (15 min) so a crash can never wedge publishing permanently. */
    $wpap_lock = 'wpap_bulk_publish_lock';
    $lock_now  = time();
    /* ATOMIC acquire via wpap_atomic_lock_acquire() (INSERT IGNORE — a true UNIQUE-key CAS;
       add_option() is NOT one, see its note in automation.php), so only ONE concurrent request
       wins. The original get_transient/set_transient pair was a check-then-set TOCTOU a
       double-click or XHR retry could slip through to publish the batch twice. Stale lock (a
       crashed run) reclaimed after 15 min via a compare-and-swap conditional DELETE. */
    if ( ! wpap_atomic_lock_acquire( $wpap_lock, $lock_now ) ) {
        $held = (int) get_option( $wpap_lock, 0 );
        if ( $held && ( $lock_now - $held ) < 15 * MINUTE_IN_SECONDS ) {
            wp_send_json_error( 'A publish run is already in progress — wait for it to finish before starting another.' );
        }
        if ( 0 === $held ) {
            /* The lock vanished between our failed add and this read (a concurrent run
               just released it). Claim it fresh rather than CAS-deleting a non-existent
               row (which would spuriously reject a legitimate publish). */
            if ( ! wpap_atomic_lock_acquire( $wpap_lock, $lock_now ) ) {
                wp_send_json_error( 'A publish run is already in progress — wait for it to finish before starting another.' );
            }
        } else {
            /* Stale lock: reclaim with a CAS (conditional DELETE keyed on the observed
               stale value) — only the racer whose DELETE removed the row may re-INSERT.
               A plain delete_option()+add_option() let two racers both proceed. */
            global $wpdb;
            $reclaimed = $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
                $wpap_lock, (string) $held
            ) );
            wp_cache_delete( $wpap_lock, 'options' );
            if ( ! $reclaimed || ! wpap_atomic_lock_acquire( $wpap_lock, $lock_now ) ) {
                wp_send_json_error( 'A publish run is already in progress — wait for it to finish before starting another.' );
            }
        }
    }
    /* Free the lock no matter how the request ends (incl. a PHP timeout mid-batch),
       but only if THIS request still owns it — never stomp a later run's lock. The
       explicit deletes below just free it a moment sooner on the normal paths. */
    register_shutdown_function( function () use ( $wpap_lock, $lock_now ) {
        wpap_release_lock_if_owner( $wpap_lock, $lock_now );
    } );

    foreach ( $items as $index => $item ) {
        $row_number = $index + 1;

        /* Single publish path shared with the Google-Sheet automation. Each item
           is ISOLATED: an unexpected fatal (bad image, odd content, a plugin on
           a hook throwing) is caught and recorded, so one broken item can NEVER
           abort the whole batch — every other item still publishes. */
        try {
            $result = wpap_publish_article( $item, array(
                'default_parts'    => $default_parts,
                'schedule_window'  => $schedule_window,
                'default_category' => $default_category,
                /* Ordered scheduling: this item's position + the batch size, so a
                   scheduled batch goes live in the exact order it was submitted. */
                'schedule_index'   => $index,
                'schedule_total'   => count( $items ),
            ) );
        } catch ( \Throwable $e ) {
            error_log( '[Automation Hamri] Publish crashed on row ' . $row_number . ': ' . $e->getMessage() );
            $messages[] = sprintf( 'Row %d failed (skipped): %s', $row_number, $e->getMessage() );
            continue;
        }

        if ( is_wp_error( $result ) ) {
            $messages[] = sprintf( 'Row %d skipped: %s', $row_number, $result->get_error_message() );
            continue;
        }

        $post_id = (int) $result;
        $post    = get_post( $post_id );

        /* Rebuild the response row from the freshly published post. */
        $post_url = wpap_public_permalink( $post_id );

        $smart_link = (string) get_post_meta( $post_id, '_wpap_smart_link', true );
        if ( '' === $smart_link ) { $smart_link = (string) $post_url; }

        $image_url = (string) get_post_meta( $post_id, '_wpap_image_url', true );
        if ( '' === $image_url ) { $image_url = (string) get_the_post_thumbnail_url( $post_id, 'full' ); }

        $status = $post ? $post->post_status : 'publish';
        $label  = ( $post && 'future' === $status ) ? mysql2date( 'M j, Y g:i A', $post->post_date ) : '';

        $content_now = $post ? (string) $post->post_content : '';
        $parts = ( false !== strpos( $content_now, '<!--nextpage-->' ) )
            ? ( substr_count( $content_now, '<!--nextpage-->' ) + 1 )
            : 1;

        /* Non-fatal image warning, re-derived (specific error text isn't available here). */
        $image_raw = $item['imageUrl'] ?? $item['image_url'] ?? $item['image'] ?? '';
        $image_raw = is_scalar( $image_raw ) ? trim( (string) $image_raw ) : '';
        if ( '' !== $image_raw && '' === $image_url ) {
            $messages[] = sprintf( 'Row %d: image could not be attached — published without a featured image.', $row_number );
        }

        /* Distribution-row id for the row just written by wpap_publish_article(). */
        $dist_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE post_id = %d ORDER BY id DESC LIMIT 1",
            $post_id
        ) );

        $created[] = array(
            'id'              => $dist_id,
            'post_id'         => $post_id,
            'title'           => $post ? $post->post_title : '',
            'post_url'        => (string) $post_url,
            'image_url'       => $image_url,
            'smart_link'      => $smart_link,
            'has_image'       => $image_url ? 1 : 0,
            'post_status'     => $status,
            'scheduled_label' => $label,
            'parts'           => $parts,
        );
    }

    if ( empty( $created ) ) {
        wpap_release_lock_if_owner( $wpap_lock, $lock_now );
        wp_send_json_error( array(
            'message'  => 'No posts were published.',
            'messages' => $messages,
        ) );
    }

    /* Bake in-content internal links across the freshly-published batch (forward-ref [[link]] markers +
       keyword cross-links). Bounded + self-isolating so it can never fail a publish that already
       succeeded. Same pass the Bulk-ZIP path runs. */
    if ( function_exists( 'wpap_internal_links_bake' ) ) {
        list( $il_linked, $kw_linked ) = wpap_internal_links_bake( 500 );
        if ( $il_linked > 0 || $kw_linked > 0 ) {
            $messages[] = sprintf( 'Internal links: resolved %d forward reference(s), added %d keyword link(s).', (int) $il_linked, (int) $kw_linked );
        }
    }

    /* Purge page caches so freshly published posts appear on the blog immediately. */
    wpap_purge_caches();

    wpap_release_lock_if_owner( $wpap_lock, $lock_now );
    wp_send_json_success( array(
        'created'  => count( $created ),
        'skipped'  => count( $items ) - count( $created ),
        'total'    => count( $items ),
        'messages' => $messages,
        'rows'     => $created,
        'nonce'    => wp_create_nonce( 'wpap_nonce' ),
    ) );
}

add_action( 'wp_ajax_wpap_export_distribution_json', 'wpap_ajax_export_distribution_json' );
function wpap_ajax_export_distribution_json() {
    check_ajax_referer( 'wpap_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

    global $wpdb;
    $table  = $wpdb->prefix . WPAP_TABLE;

    /* Export matches the Hub list EXACTLY — SAME search + status + Facebook filters (shared
       WHERE), SAME publish-first / id-DESC order (fetched filesort-free via wpap_hub_ordered_rows).
       Two modes: the CURRENT PAGE (default, per_page rows, so the export tracks the navigation)
       or ALL matching rows (?all=1, ~5000 cap) so the whole filtered set exports in one file.
       (ported from build-final 8.44/8.43 — first-comment templates + filtered export + post_ids) */
    $page   = max( 1, intval( $_GET['page'] ?? 1 ) );
    $search = sanitize_text_field( wp_unslash( $_GET['search'] ?? '' ) );
    $status = sanitize_key( $_GET['status'] ?? 'all' );
    if ( ! in_array( $status, array( 'all', 'publish', 'future', 'draft' ), true ) ) { $status = 'all'; }
    $fb     = sanitize_key( $_GET['fb'] ?? 'all' );
    if ( ! in_array( $fb, array( 'all', 'posted', 'unposted' ), true ) ) { $fb = 'all'; }
    $all    = ! empty( $_GET['all'] );   /* export EVERY matching row, not just the current page */
    $per    = wpap_distribution_per_page();

    $cols      = "t.id, t.post_id, t.title, t.post_url, t.image_url, t.fb_text, t.smart_link, p.post_status AS wp_status";
    $join      = "LEFT JOIN {$wpdb->posts} p ON p.ID = t.post_id";
    $wc        = wpap_distribution_where_clause( $search, $status, $fb );
    $where_sql = $wc['where'];
    $params    = $wc['params'];

    /* Total MATCHING rows (same filters) → drives "page X of Y" + the "export all" option. */
    $count_sql   = "SELECT COUNT(*) FROM {$table} t {$join} WHERE {$where_sql}";
    $total       = empty( $params ) ? (int) $wpdb->get_var( $count_sql ) : (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );
    $total_pages = max( 1, (int) ceil( $total / $per ) );

    $export_cap = 5000;   /* hard ceiling for an "all" export so a huge bank can't exhaust memory */
    if ( $all ) {
        @set_time_limit( 300 );   /* re-deriving link+image per row over the whole set can take a while */
        $page = 1;
        $rows = wpap_hub_ordered_rows( $cols, $where_sql, $params, $export_cap, 0 );
    } else {
        $page   = min( $page, $total_pages );
        $offset = ( $page - 1 ) * $per;
        $rows   = wpap_hub_ordered_rows( $cols, $where_sql, $params, $per, $offset );
    }

    /* Bulk-prime post meta ONCE so the per-row get_post_meta calls in the loop (hook, smart_link,
       fb comment/template) hit cache instead of firing a meta query per row — an N+1 that on the
       ?all=1 path (up to 5000 rows) meant thousands of queries. */
    $prime_ids = array_values( array_filter( array_map(
        static function ( $r ) { return (int) ( $r['post_id'] ?? 0 ); },
        (array) $rows
    ) ) );
    if ( $prime_ids ) { update_meta_cache( 'post', $prime_ids ); }

    $items    = array();
    $post_ids = array();   /* the exported (live) post ids — lets the Hub mark THIS batch as posted */
    $skipped  = 0;
    $no_link  = 0;   /* published rows dropped because their link resolved empty */
    foreach ( (array) $rows as $row ) {
        $pid = intval( $row['post_id'] ?? 0 );

        /* Only export LIVE (published) posts. A scheduled/draft link 301-redirects
           or 404s until it goes live, and this feed drives the auto-poster — so
           withhold non-live rows and report the count instead of poisoning it. */
        if ( 'publish' !== (string) ( $row['wp_status'] ?? '' ) ) {
            $skipped++;
            continue;
        }

        /* caption = the hook (table → post-meta fallback) */
        $hook = (string) ( $row['fb_text'] ?? '' );
        if ( '' === $hook && $pid ) { $hook = (string) get_post_meta( $pid, '_wpap_fb_hook', true ); }

        /* link = the post link (smart link → meta → plain permalink) */
        $link = trim( (string) ( $row['smart_link'] ?? '' ) );
        if ( '' === $link && $pid ) { $link = (string) get_post_meta( $pid, '_wpap_smart_link', true ); }
        if ( '' === $link ) { $link = (string) ( $row['post_url'] ?? '' ); }

        /* ?p= self-heal (mirrors the Hub): re-resolve a legacy ?p=<id> link to the
           pretty permalink so the exported link never 301-redirects (a redirect
           zeroes ad RPM), and persist it to meta + the table row. */
        if ( $pid && false !== strpos( $link, '?p=' ) ) {
            $pretty = wpap_public_permalink( $pid );
            if ( is_string( $pretty ) && '' !== $pretty && false === strpos( $pretty, '?p=' ) ) {
                $link = $pretty;
                update_post_meta( $pid, '_wpap_smart_link', $pretty );
                $wpdb->update( $table, array( 'post_url' => $pretty, 'smart_link' => $pretty ), array( 'id' => intval( $row['id'] ) ) );
            }
        }
        if ( '' !== $link ) { $link = remove_query_arg( 'v', $link ); }   /* strip any legacy ?v= */

        /* Never feed the auto-poster a LINKLESS post: a caption+image with no landing URL
           drives zero clicks → zero ad impressions. Skip it and count it distinctly. */
        if ( '' === trim( (string) $link ) ) { $no_link++; continue; }

        /* imageUrl = the original image (table → meta → featured image) */
        $img = (string) ( $row['image_url'] ?? '' );
        if ( '' === $img && $pid ) {
            $img = (string) get_post_meta( $pid, '_wpap_image_url', true );
            if ( '' === $img ) { $img = (string) get_the_post_thumbnail_url( $pid, 'full' ); }
        }

        $items[] = array(
            'caption'  => $hook,
            /* comment = the FIRST-COMMENT text (template with {{link}} → the link); with no
               template configured this is exactly the bare link, unchanged. `link` keeps the
               clean article URL on its own, and `template` is the RAW template so the Hub can
               re-compose client-side. */
            'comment'  => wpap_compose_fb_comment( $link, $pid ),
            'link'     => $link,
            'template' => wpap_resolve_fb_template( $pid ),
            'imageUrl' => $img,
        );
        if ( $pid > 0 ) { $post_ids[] = $pid; }
    }

    wp_send_json_success( array(
        'items'       => $items,
        'post_ids'    => $post_ids,   /* exported live posts → "mark this batch posted" in the Hub */
        'count'       => count( $items ),
        'skipped'     => $skipped,
        'no_link'     => $no_link,
        'page'        => $page,
        'per_page'    => $per,
        'total'       => $total,
        'total_pages' => $total_pages,
        'all'         => $all ? 1 : 0,
        'capped'      => ( $all && $total > $export_cap ) ? 1 : 0,
    ) );
}

/* ════════════════════════════════════════════
   BULK ZIP PUBLISH (ported from build-final 8.49.0)
   Upload ONE .zip (a posts.json array + the image files it references) and publish every
   post with its image pulled straight from the zip — no image hosting / public URLs.
════════════════════════════════════════════ */

/* Shutdown-time shield: convert a naked PHP fatal (which returns a bodyless HTTP 500) into a
   proper wp_send_json_error carrying the real message + file:line (path-stripped), so a bundle
   whose image import exhausts memory reports WHY instead of a white screen. */
function wpap_ajax_fatal_shield() {
    if ( defined( 'WPAP_SHIELD_INSTALLED' ) ) { return; }
    define( 'WPAP_SHIELD_INSTALLED', true );

    register_shutdown_function( function () {
        $err = function_exists( 'error_get_last' ) ? error_get_last() : null;
        if ( ! $err ) { return; }
        $fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR );
        if ( ! in_array( (int) $err['type'], $fatal_types, true ) ) { return; }
        if ( function_exists( 'headers_sent' ) && headers_sent() ) { return; }

        $where = isset( $err['file'] ) ? basename( (string) $err['file'] ) : 'unknown';
        $line  = isset( $err['line'] ) ? (int) $err['line'] : 0;
        $msg   = (string) ( $err['message'] ?? 'Unknown fatal error' );
        $msg   = preg_replace( '#/[a-zA-Z0-9/_\.-]+/wp-content/#', '/wp-content/', $msg );   /* strip abs paths */

        error_log( '[Automation Hamri] FATAL in AJAX handler at ' . $where . ':' . $line . ' — ' . $msg );

        @http_response_code( 500 );
        @header( 'Content-Type: application/json; charset=utf-8' );
        echo wp_json_encode( array(
            'success' => false,
            'data'    => 'Fatal PHP error: ' . $msg . ' (' . $where . ':' . $line . '). Check WordPress error log for details.',
        ) );
    } );
}

/** Recursively delete a directory (temp extract cleanup). No-op unless it's a real directory. */
function wpap_bundle_rrmdir( $dir ) {
    $dir = (string) $dir;
    if ( '' === $dir || ! is_dir( $dir ) ) { return; }
    $items = @scandir( $dir );
    if ( is_array( $items ) ) {
        foreach ( $items as $it ) {
            if ( '.' === $it || '..' === $it ) { continue; }
            $p = $dir . '/' . $it;
            if ( is_dir( $p ) && ! is_link( $p ) ) { wpap_bundle_rrmdir( $p ); }
            else { @unlink( $p ); }
        }
    }
    @rmdir( $dir );
}

/** Find posts.json in the extract dir (top-level first, else the shallowest match). Abs path or ''. */
function wpap_bundle_find_posts_json( $root ) {
    $root   = rtrim( (string) $root, '/\\' );
    $direct = $root . '/posts.json';
    if ( is_file( $direct ) ) { return $direct; }
    $found = '';
    $best  = PHP_INT_MAX;
    try {
        $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
        foreach ( $it as $f ) {
            if ( $f->isFile() && strtolower( $f->getFilename() ) === 'posts.json' ) {
                $depth = substr_count( str_replace( '\\', '/', $f->getPathname() ), '/' );
                if ( $depth < $best ) { $best = $depth; $found = $f->getPathname(); }
            }
        }
    } catch ( \Throwable $e ) { /* ignore */ }
    return $found;
}

/** Resolve an item's local image path SAFELY inside the extract root (rejects absolute / ..
 *  escapes / symlinks out). Returns an absolute path to a real file inside $root, or ''. */
function wpap_bundle_resolve_image( $base_dir, $root, $rel ) {
    $rel = str_replace( '\\', '/', trim( (string) $rel ) );
    if ( '' === $rel ) { return ''; }
    if ( '/' === $rel[0] || preg_match( '#^[A-Za-z]:/#', $rel ) || false !== strpos( $rel, '../' ) || false !== strpos( $rel, "\0" ) ) { return ''; }
    $candidate = rtrim( (string) $base_dir, '/\\' ) . '/' . $rel;
    $real      = realpath( $candidate );
    $root_real = realpath( (string) $root );
    if ( ! $real || ! $root_real ) { return ''; }
    $real_n = str_replace( '\\', '/', $real );
    $root_n = rtrim( str_replace( '\\', '/', $root_real ), '/' ) . '/';
    if ( 0 !== strpos( $real_n, $root_n ) ) { return ''; }   /* escaped the extract root */
    if ( ! is_file( $real ) ) { return ''; }
    return $real;
}

/** In-article images from the zip: every <img src="images/..."> in the published post whose src is a RELATIVE
 *  path (not http(s)/data:/protocol-relative) is resolved safely inside the extract root, sideloaded into the
 *  media library (attached to the post, NOT set as thumbnail), and its src rewritten to the attachment URL.
 *  A missing/unsafe/invalid file drops that <img> so no broken relative path is ever left on the live post.
 *  Returns the number of images imported. */
function wpap_bundle_sideload_inline_images( int $post_id, $img_base, $work, string $title, array &$messages, int $row_number ) {
    $post = get_post( $post_id );
    if ( ! $post ) { return 0; }
    $content = (string) $post->post_content;
    if ( false === stripos( $content, '<img' ) ) { return 0; }

    $max_inline = (int) apply_filters( 'wpap_bundle_max_inline_images', 30 );
    $done       = array();   /* rel path → attachment URL ('' = failed), so a repeated src imports once */
    $imported   = 0;
    $new = preg_replace_callback(
        '#<img\b[^>]*?\bsrc=(["\'])(?!https?:|data:|//)([^"\']+)\1[^>]*>#i',
        function ( $m ) use ( &$done, &$imported, &$messages, $max_inline, $post_id, $img_base, $work, $title, $row_number ) {
            $rel = html_entity_decode( $m[2], ENT_QUOTES );
            if ( ! array_key_exists( $rel, $done ) ) {
                $done[ $rel ] = '';
                if ( $imported < $max_inline ) {
                    $local = wpap_bundle_resolve_image( $img_base, $work, $rel );
                    if ( '' !== $local ) {
                        $aid = wpap_import_local_image_as_attachment( $local, $post_id, $title );
                        if ( ! is_wp_error( $aid ) ) {
                            update_post_meta( (int) $aid, '_wp_attachment_image_alt', $title );
                            $done[ $rel ] = (string) wp_get_attachment_url( (int) $aid );
                            $imported++;
                        } else {
                            $messages[] = sprintf( 'Row %d: in-article image "%s" skipped: %s', $row_number, sanitize_text_field( $rel ), $aid->get_error_message() );
                        }
                    } else {
                        $messages[] = sprintf( 'Row %d: in-article image "%s" was not found in the zip.', $row_number, sanitize_text_field( $rel ) );
                    }
                }
            }
            if ( '' === $done[ $rel ] ) { return ''; }   /* never leave a broken relative src on a live post */
            return str_replace( $m[1] . $m[2] . $m[1], $m[1] . esc_url( $done[ $rel ] ) . $m[1], $m[0] );
        },
        $content
    );
    if ( null !== $new && $new !== $content ) {
        global $wpdb;
        /* Direct update (like the <!--nextpage--> guard in wpap_publish_article): content was already kses'd on
           insert; only src values changed, and a wp_update_post round-trip could strip the page-break markers. */
        $wpdb->update( $wpdb->posts, array( 'post_content' => $new ), array( 'ID' => $post_id ) );
        clean_post_cache( $post_id );
    }
    return $imported;
}

/** AJAX: publish a whole .zip bundle. manage_options only; nonce + fatal-shield; ZipArchive required. */
add_action( 'wp_ajax_wpap_bulk_publish_zip', 'wpap_ajax_bulk_publish_zip' );
function wpap_ajax_bulk_publish_zip() {
    check_ajax_referer( 'wpap_nonce', 'nonce' );
    wpap_ajax_fatal_shield();
    @set_time_limit( 600 );
    @ignore_user_abort( true );
    @ini_set( 'max_execution_time', '600' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Unauthorized' ) ); }

    /* Concurrency guard (parity with the JSON bulk path's lock): a double-click or an
       XHR retry on a long-running bundle must not publish the whole zip twice. Atomic
       wpap_atomic_lock_acquire() (INSERT IGNORE CAS); a stale lock from a crashed run
       auto-reclaims after 15 min; released on shutdown even if the request dies mid-batch. */
    $wpap_zip_lock = 'wpap_bulk_zip_lock';
    $zip_lock_now  = time();
    if ( ! wpap_atomic_lock_acquire( $wpap_zip_lock, $zip_lock_now ) ) {
        $held = (int) get_option( $wpap_zip_lock, 0 );
        if ( $held && ( $zip_lock_now - $held ) < 15 * MINUTE_IN_SECONDS ) {
            wp_send_json_error( array( 'message' => 'A bundle publish is already in progress — wait for it to finish before uploading another.' ) );
        }
        if ( 0 === $held ) {
            if ( ! wpap_atomic_lock_acquire( $wpap_zip_lock, $zip_lock_now ) ) {
                wp_send_json_error( array( 'message' => 'A bundle publish is already in progress — wait for it to finish before uploading another.' ) );
            }
        } else {
            global $wpdb;
            $reclaimed = $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
                $wpap_zip_lock, (string) $held
            ) );
            wp_cache_delete( $wpap_zip_lock, 'options' );
            if ( ! $reclaimed || ! wpap_atomic_lock_acquire( $wpap_zip_lock, $zip_lock_now ) ) {
                wp_send_json_error( array( 'message' => 'A bundle publish is already in progress — wait for it to finish before uploading another.' ) );
            }
        }
    }
    register_shutdown_function( function () use ( $wpap_zip_lock, $zip_lock_now ) {
        wpap_release_lock_if_owner( $wpap_zip_lock, $zip_lock_now );
    } );

    if ( ! class_exists( 'ZipArchive' ) ) {
        wp_send_json_error( array( 'message' => "PHP's ZipArchive is not available on this server — ask your host to enable the zip extension." ) );
    }

    /* ── validate the upload ── */
    if ( empty( $_FILES['bundle'] ) || ! isset( $_FILES['bundle']['tmp_name'] ) ) {
        wp_send_json_error( array( 'message' => 'No file uploaded.' ) );
    }
    $file = $_FILES['bundle'];
    if ( ! empty( $file['error'] ) ) { wp_send_json_error( array( 'message' => 'Upload error (code ' . (int) $file['error'] . ').' ) ); }
    $tmp_upload = (string) $file['tmp_name'];
    if ( '' === $tmp_upload || ! is_uploaded_file( $tmp_upload ) ) { wp_send_json_error( array( 'message' => 'Invalid upload.' ) ); }
    $max_bytes = (int) apply_filters( 'wpap_bundle_max_bytes', 64 * 1024 * 1024 );
    if ( (int) $file['size'] > $max_bytes ) {
        wp_send_json_error( array( 'message' => sprintf( 'Zip too large (%d MB). Maximum is %d MB.', (int) round( $file['size'] / 1048576 ), (int) round( $max_bytes / 1048576 ) ) ) );
    }

    /* ── private temp extract dir under uploads (web access denied) ── */
    $up = wp_upload_dir();
    if ( ! empty( $up['error'] ) ) { wp_send_json_error( array( 'message' => 'Uploads directory unavailable.' ) ); }
    $base = trailingslashit( $up['basedir'] ) . 'wpap-bundle-tmp';
    if ( ! wp_mkdir_p( $base ) ) { wp_send_json_error( array( 'message' => 'Could not create the temp directory.' ) ); }
    if ( ! file_exists( $base . '/index.php' ) )  { @file_put_contents( $base . '/index.php', "<?php // silence\n" ); }
    if ( ! file_exists( $base . '/.htaccess' ) )  { @file_put_contents( $base . '/.htaccess', "Deny from all\n" ); }
    $work = $base . '/' . wp_generate_password( 16, false );
    if ( ! wp_mkdir_p( $work ) ) { wp_send_json_error( array( 'message' => 'Could not create a work directory.' ) ); }

    /* Clean up the extract dir even on an UNCATCHABLE fatal (e.g. an OOM during
       thumbnail generation), which the per-item try/catch cannot trap — otherwise a
       full extracted bundle (up to the uncompressed cap) leaks under uploads on every
       such crash. Idempotent with the explicit rrmdir on the normal/error paths below. */
    register_shutdown_function( function () use ( $work ) {
        if ( is_dir( $work ) ) { wpap_bundle_rrmdir( $work ); }
    } );

    $zip = new ZipArchive();
    if ( true !== $zip->open( $tmp_upload ) ) { wpap_bundle_rrmdir( $work ); wp_send_json_error( array( 'message' => 'Not a valid zip archive.' ) ); }

    /* ── guards: entry count, path traversal / absolute, allowed types only, uncompressed-size (zip bomb) ── */
    $max_entries      = (int) apply_filters( 'wpap_bundle_max_entries', 3000 );
    $max_uncompressed = (int) apply_filters( 'wpap_bundle_max_uncompressed', 512 * 1024 * 1024 );
    if ( $zip->numFiles > $max_entries ) { $zip->close(); wpap_bundle_rrmdir( $work ); wp_send_json_error( array( 'message' => 'Too many entries in the zip.' ) ); }
    $allowed_ext = array( 'json', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif' );
    $safe        = array();
    $total_unc   = 0;
    for ( $i = 0; $i < $zip->numFiles; $i++ ) {
        $st = $zip->statIndex( $i );
        if ( ! is_array( $st ) ) { continue; }
        $name = (string) $st['name'];
        if ( '' === $name || '/' === substr( $name, -1 ) ) { continue; }   /* directory entry */
        $norm = str_replace( '\\', '/', $name );
        if ( '/' === $norm[0] || preg_match( '#^[A-Za-z]:/#', $norm ) || false !== strpos( $norm, '../' ) || false !== strpos( $name, "\0" ) ) {
            $zip->close(); wpap_bundle_rrmdir( $work );
            wp_send_json_error( array( 'message' => 'Unsafe path in zip: ' . sanitize_text_field( $name ) ) );
        }
        $ext = strtolower( (string) pathinfo( $norm, PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, $allowed_ext, true ) ) { continue; }   /* only json + images are extracted */
        $total_unc += (int) $st['size'];
        if ( $total_unc > $max_uncompressed ) { $zip->close(); wpap_bundle_rrmdir( $work ); wp_send_json_error( array( 'message' => 'Zip contents exceed the size limit (bomb guard).' ) ); }
        $safe[] = $name;
    }
    if ( empty( $safe ) ) { $zip->close(); wpap_bundle_rrmdir( $work ); wp_send_json_error( array( 'message' => 'The zip has no posts.json or image files.' ) ); }
    if ( ! $zip->extractTo( $work, $safe ) ) { $zip->close(); wpap_bundle_rrmdir( $work ); wp_send_json_error( array( 'message' => 'Extraction failed.' ) ); }
    $zip->close();

    /* ── posts.json ── */
    $posts_json = wpap_bundle_find_posts_json( $work );
    if ( '' === $posts_json ) { wpap_bundle_rrmdir( $work ); wp_send_json_error( array( 'message' => 'No posts.json found in the zip.' ) ); }
    $payload = json_decode( (string) @file_get_contents( $posts_json ), true );
    if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $payload ) ) { wpap_bundle_rrmdir( $work ); wp_send_json_error( array( 'message' => 'posts.json is not valid JSON.' ) ); }
    if ( isset( $payload['items'] ) && is_array( $payload['items'] ) ) { $payload = $payload['items']; }
    $items = array_values( array_filter( $payload, 'is_array' ) );
    if ( empty( $items ) ) { wpap_bundle_rrmdir( $work ); wp_send_json_error( array( 'message' => 'posts.json has no items.' ) ); }

    $img_base = dirname( $posts_json );   /* images are resolved relative to posts.json */
    $messages = array();

    $max_items = wpap_bulk_max_items();
    if ( count( $items ) > $max_items ) {
        $messages[] = sprintf( '%d item(s) ignored: capped at %d per request.', count( $items ) - $max_items, $max_items );
        $items = array_slice( $items, 0, $max_items );
    }

    /* Publish options (mirror Direct Publish). */
    $default_parts = intval( $_POST['num_parts'] ?? 1 );
    if ( $default_parts < 1 ) { $default_parts = 1; }
    if ( $default_parts > 10 ) { $default_parts = 10; }
    /* Accept a plain hours window (0–168) OR the human "drip:N" mode (N posts/day, daytime 8am–10pm),
       parity with the REST endpoint. wpap_parse_schedule_window() clamps + sanitizes both forms. */
    $schedule_window = wpap_parse_schedule_window( $_POST['schedule_window'] ?? 0 );
    $default_category = sanitize_text_field( wp_unslash( $_POST['category'] ?? '' ) );

    $created = array();
    foreach ( $items as $index => $item ) {
        $row_number = $index + 1;
        try {
            $opts = array(
                'default_parts'    => $default_parts,
                'schedule_window'  => $schedule_window,
                'default_category' => $default_category,
            );

            /* Resolve the image: an http(s) URL uses the normal remote path; anything else is a
               path INSIDE the zip → resolve safely + pass as local_image_path so it sideloads
               with no public URL. */
            $img_ref = '';
            foreach ( array( 'image', 'imageUrl', 'image_url' ) as $k ) {
                if ( isset( $item[ $k ] ) && is_scalar( $item[ $k ] ) && '' !== trim( (string) $item[ $k ] ) ) {
                    $img_ref = trim( (string) $item[ $k ] );
                    break;
                }
            }
            if ( '' !== $img_ref && preg_match( '#^https?://#i', $img_ref ) ) {
                $item['imageUrl'] = $img_ref;   /* remote — normal download path */
            } elseif ( '' !== $img_ref ) {
                $local = wpap_bundle_resolve_image( $img_base, $work, $img_ref );
                if ( '' !== $local ) {
                    $opts['local_image_path'] = $local;
                } else {
                    $messages[] = sprintf( 'Row %d: image "%s" was not found in the zip.', $row_number, sanitize_text_field( $img_ref ) );
                }
            }

            /* Facebook image (optional, separate from the blog image): a remote URL passes
               through; a zip path resolves to a local file sideloaded for Facebook only. */
            $fb_ref = '';
            foreach ( array( 'fbImage', 'fbImageUrl', 'facebook_image', 'fb_image' ) as $fk ) {
                if ( isset( $item[ $fk ] ) && is_scalar( $item[ $fk ] ) && '' !== trim( (string) $item[ $fk ] ) ) {
                    $fb_ref = trim( (string) $item[ $fk ] );
                    break;
                }
            }
            if ( '' !== $fb_ref && preg_match( '#^https?://#i', $fb_ref ) ) {
                $item['fbImageUrl'] = $fb_ref;
            } elseif ( '' !== $fb_ref ) {
                $fb_local = wpap_bundle_resolve_image( $img_base, $work, $fb_ref );
                if ( '' !== $fb_local ) {
                    $opts['local_fb_image_path'] = $fb_local;
                } else {
                    $messages[] = sprintf( 'Row %d: Facebook image "%s" was not found in the zip.', $row_number, sanitize_text_field( $fb_ref ) );
                }
            }

            $result = wpap_publish_article( $item, $opts );
            if ( is_wp_error( $result ) ) {
                $messages[] = sprintf( 'Row %d skipped: %s', $row_number, $result->get_error_message() );
                continue;
            }

            $post_id   = (int) $result;
            $inline    = wpap_bundle_sideload_inline_images( $post_id, $img_base, $work, (string) ( $item['title'] ?? '' ), $messages, $row_number );
            $post      = get_post( $post_id );
            $post_url  = wpap_public_permalink( $post_id );
            $image_url = (string) get_post_meta( $post_id, '_wpap_image_url', true );
            if ( '' === $image_url ) { $image_url = (string) get_the_post_thumbnail_url( $post_id, 'full' ); }
            $status = $post ? $post->post_status : 'publish';
            $label  = ( $post && 'future' === $status ) ? mysql2date( 'M j, Y g:i A', $post->post_date ) : '';

            $created[] = array(
                'post_id'         => $post_id,
                'title'           => $post ? $post->post_title : '',
                'post_url'        => (string) $post_url,
                'has_image'       => $image_url ? 1 : 0,
                'inline_images'   => $inline,
                'post_status'     => $status,
                'scheduled_label' => $label,
            );
        } catch ( \Throwable $e ) {
            $messages[] = sprintf( 'Row %d skipped (fatal): %s', $row_number, $e->getMessage() );
            error_log( '[Automation Hamri] bulk ZIP publish crashed on row ' . $row_number . ': ' . $e->getMessage() );
            continue;
        }
    }

    wpap_bundle_rrmdir( $work );   /* always clean up the extracted files */

    if ( empty( $created ) ) {
        wp_send_json_error( array( 'message' => 'No posts were published from the bundle.', 'messages' => $messages ) );
    }

    /* Bake in-content internal links across the freshly-published batch: upgrade any forward-reference
       [[link:slug]] markers now that the whole batch is live, then weave keyword cross-links. Bounded +
       self-isolating so a linking hiccup can never fail the publish that already succeeded. */
    if ( function_exists( 'wpap_internal_links_bake' ) ) {
        list( $il_linked, $kw_linked ) = wpap_internal_links_bake( 500 );
        if ( $il_linked > 0 || $kw_linked > 0 ) {
            $messages[] = sprintf( 'Internal links: resolved %d forward reference(s), added %d keyword link(s).', (int) $il_linked, (int) $kw_linked );
        }
    }

    wpap_purge_caches();
    wp_send_json_success( array(
        'created'  => count( $created ),
        'skipped'  => count( $items ) - count( $created ),
        'total'    => count( $items ),
        'messages' => $messages,
        'rows'     => $created,
    ) );
}

add_action( 'wp_ajax_wpap_process_title', 'wpap_ajax_process_title' );
function wpap_ajax_process_title() {
    check_ajax_referer( 'wpap_nonce', 'nonce' );
    @set_time_limit( 300 );
    @ignore_user_abort( true );
    @ini_set( 'max_execution_time', '300' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

    /* Cost circuit-breaker: one AI generation per request, before any API call. */
    if ( ! wpap_rate_limit_ok( 1 ) ) {
        wp_send_json_error( 'Rate limit reached. Please wait a while before generating more articles.' );
    }

    $title = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
    $fallback_attach_id_early = intval( $_POST['fallback_attach_id'] ?? 0 );

    $claude_key = wpap_get_api_key( 'claude' );
    $gemini_key = wpap_get_api_key( 'gemini' );

    /* ── Vision fallback: no title but an image was provided ── */
    if ( ! $title && $fallback_attach_id_early > 0 ) {
        $image_path = get_attached_file( $fallback_attach_id_early );
        $mime_type  = get_post_mime_type( $fallback_attach_id_early );
        if ( $image_path && file_exists( $image_path ) && $mime_type ) {
            $image_data   = base64_encode( file_get_contents( $image_path ) );
            $vision_prompt = 'Look at this image and generate ONE catchy, SEO-friendly article title (max 12 words). Output only the title — no quotes, no punctuation at the end, no extra text.';
            $vision_title  = '';

            /* Step 1: Gemini Flash vision */
            if ( $gemini_key ) {
                $gem_body = wp_json_encode( array(
                    'contents' => array( array( 'parts' => array(
                        array( 'inlineData' => array( 'mimeType' => $mime_type, 'data' => $image_data ) ),
                        array( 'text'       => $vision_prompt ),
                    ) ) ),
                    'generationConfig' => array( 'maxOutputTokens' => 30, 'temperature' => 0.7 ),
                ) );
                $gem_r = wp_remote_post(
                    'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . $gemini_key,
                    array( 'timeout' => 20, 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => $gem_body )
                );
                if ( ! is_wp_error( $gem_r ) && 200 === (int) wp_remote_retrieve_response_code( $gem_r ) ) {
                    $gem_json    = json_decode( wp_remote_retrieve_body( $gem_r ), true );
                    $vision_title = trim( $gem_json['candidates'][0]['content']['parts'][0]['text'] ?? '' );
                }
            }

            /* Step 2: Claude Haiku fallback if Gemini failed or no key */
            if ( ! $vision_title && $claude_key ) {
                $cl_body = wp_json_encode( array(
                    'model'      => 'claude-haiku-4-5-20251001',
                    'max_tokens' => 40,
                    'messages'   => array( array( 'role' => 'user', 'content' => array(
                        array( 'type' => 'image', 'source' => array( 'type' => 'base64', 'media_type' => $mime_type, 'data' => $image_data ) ),
                        array( 'type' => 'text',  'text'   => $vision_prompt ),
                    ) ) ),
                ) );
                $cl_r = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
                    'timeout' => 20,
                    'headers' => array(
                        'x-api-key'         => $claude_key,
                        'anthropic-version' => '2023-06-01',
                        'content-type'      => 'application/json',
                    ),
                    'body' => $cl_body,
                ) );
                if ( ! is_wp_error( $cl_r ) && 200 === (int) wp_remote_retrieve_response_code( $cl_r ) ) {
                    $cl_json      = json_decode( wp_remote_retrieve_body( $cl_r ), true );
                    $vision_title = trim( $cl_json['content'][0]['text'] ?? '' );
                }
            }

            if ( $vision_title ) {
                /* Strip any surrounding quotes the model may have added */
                $vision_title = preg_replace( '/^[\'"""«»]+|[\'"""«»]+$/u', '', $vision_title );
                $title = sanitize_text_field( $vision_title );
            }
        }
    }

    if ( ! $title ) wp_send_json_error( 'No title provided and vision analysis could not generate one.' );
    $pexels_key = wpap_get_api_key( 'pexels' );
    /* Key validation happens per-engine in STEP A below */

    /* ── Read target language from front-end ── */
    $allowed_langs  = array(
        'auto','en','fr','es','de','it','pt','nl','pl','ro','hu','bg',
        'cs','sk','hr','sv','da','fi','el','ru','uk','tr',
        'ar','he','fa','zh','ja','ko','hi','id','vi','th',
    );
    $target_lang = sanitize_text_field( wp_unslash( $_POST['target_lang'] ?? 'auto' ) );
    if ( ! in_array( $target_lang, $allowed_langs, true ) ) {
        $target_lang = 'auto';
    }

    /* ── Read page count (2-5) ── */
    $num_pages = intval( $_POST['num_pages'] ?? 2 );
    if ( $num_pages < 2 || $num_pages > 5 ) { $num_pages = 2; }

    /* ── Read schedule window (hours; 0 = publish immediately) ── */
    $schedule_window = isset( $_POST['schedule_window'] ) ? (float) $_POST['schedule_window'] : 0;
    if ( $schedule_window < 0 )   { $schedule_window = 0; }
    if ( $schedule_window > 168 ) { $schedule_window = 168; }   /* cap at 1 week */

    /* ── Read image engine (gemini | claude | pexels | manual_only) ── */
    $valid_img = array( 'gemini_flash', 'gemini_pro', 'claude', 'pexels', 'manual_only' );
    $image_engine = sanitize_text_field( wp_unslash( $_POST['image_engine'] ?? 'gemini_flash' ) );
    if ( ! in_array( $image_engine, $valid_img, true ) ) { $image_engine = 'gemini_flash'; }

    /* ── Read content engine (3-tier) ── */
    $valid_cnt = array( 'claude_haiku', 'gemini_flash', 'gemini_pro' );
    $content_engine = sanitize_text_field( wp_unslash( $_POST['content_engine'] ?? 'claude_haiku' ) );
    if ( ! in_array( $content_engine, $valid_cnt, true ) ) { $content_engine = 'claude_haiku'; }

    /* ── STEP A: Generate content via selected engine ── */
    if ( $content_engine === 'gemini_flash' || $content_engine === 'gemini_pro' ) {
        $gemini_key_c = wpap_get_api_key( 'gemini' );
        if ( ! $gemini_key_c ) wp_send_json_error( 'Gemini API key missing — go to Settings.' );
        $content = wpap_generate_content_gemini( $title, $gemini_key_c, $target_lang, $num_pages );
    } else {
        if ( ! $claude_key ) wp_send_json_error( 'Claude API key missing — go to Settings.' );
        $content = wpap_generate_content( $title, $claude_key, $target_lang, $num_pages );
    }
    if ( is_wp_error( $content ) ) wp_send_json_error( $content->get_error_message() );

    $page1   = $content['page1'];
    $page2   = $content['page2'];
    $fb_text = $content['fb_text'];
    $lang    = $content['lang'];

    /* ── STEP B: Build translated labels ── */
    $next_map = array(
        'ar' => 'لا تفوّت الباقي! اضغط على الزر التالي لمواصلة القراءة',
        'fr' => 'Ne manquez pas la suite ! Appuyez sur Suivant pour continuer',
        'es' => '¡No te pierdas el resto! Presiona Siguiente para continuar',
        'de' => 'Verpassen Sie nicht den Rest! Weiter drücken um weiterzulesen',
        'it' => 'Non perdere il resto! Premi Avanti per continuare',
        'pt' => 'Não perca o resto! Pressione Próximo para continuar',
        'tr' => "Geri kalanı kaçırmayın! Devam için İleri'ye basın",
        'nl' => 'Mis de rest niet! Druk op Volgende om verder te lezen',
        'ru' => 'Не пропустите остальное! Нажмите Далее чтобы продолжить',
        'hu' => 'Ne maradj le a többiről! A folytatáshoz nyomja meg a Következő gombot',
        'en' => "Don't Miss The Rest! Press Next Button Below To Continue Reading",
    );
    $share_map = array(
        'ar' => 'إذا أعجبتك الوصفة، شاركها مع أصدقائك!',
        'fr' => 'Si vous avez aimé la recette, partagez-la avec vos amis !',
        'es' => '¡Si te gustó la receta, compártela con tus amigos!',
        'de' => 'Wenn dir das Rezept gefallen hat, teile es mit deinen Freunden!',
        'it' => 'Se ti è piaciuta la ricetta, condividila con i tuoi amici!',
        'pt' => 'Se gostou da receita, partilhe com os seus amigos!',
        'tr' => 'Tarifi beğendiyseniz arkadaşlarınızla paylaşın!',
        'nl' => 'Als je het recept lekker vond, deel het dan met je vrienden!',
        'ru' => 'Если вам понравился рецепт, поделитесь им с друзьями!',
        'hu' => 'Ha tetszett a recept, oszd meg barátaiddal!',
        'en' => 'If you liked the recipe, share it with your friends!',
    );
    /* If user forced a language, use it for UI labels; else use Claude-detected $lang */
    $ui_lang     = ( $target_lang !== 'auto' ) ? $target_lang : $lang;
    $next_label  = $next_map[ $ui_lang ]  ?? $next_map['en'];
    $share_label = $share_map[ $ui_lang ] ?? $share_map['en'];

    /* ── STEP C: Build full post content with <!--nextpage--> (N pages) ── */
    /* Red 'Next' button removed — theme provides its own navigation button.
       Only the translated teaser text is preserved. */
    $next_block = '<p class="wpap-next-teaser">' . esc_html( $next_label ) . '</p>';
    $share_block = '<p class="wpap-share-cta">' . esc_html( $share_label ) . '</p>';

    /* Split raw pages array from content and assemble with nextpage tags */
    $raw_pages    = $content['pages'];   /* array of HTML strings, length = $num_pages */
    $full_content = '';
    foreach ( $raw_pages as $idx => $pg_html ) {
        $full_content .= $pg_html;
        if ( $idx < count( $raw_pages ) - 1 ) {
            /* Add Next button + page break between every pair of pages */
            $full_content .= "\n\n" . $next_block . "\n\n<!--nextpage-->\n\n";
        }
    }
    $full_content .= "\n\n" . $share_block;

    /* ═══ NUCLEAR CONTENT CLEAN ═══
     * Strip ALL markdown artifacts right before saving to DB.
     * This runs AFTER page assembly so it catches anything
     * the AI smuggled into PAGE1...PAGEn blocks.
     */
    $full_content = str_replace( array( '```html', '```' ), '', $full_content );
    $full_content = trim( $full_content, " `\t\n\r\0\x0B" );
    /* Also kill any "html" that appears as the very first word (leftover label) */
    $full_content = preg_replace( '/^html\s*/i', '', $full_content );
    /* Remove any backtick sequences anywhere in the content */
    $full_content = str_replace( '`', '', $full_content );

    /* (S2) Sanitize the AI body unless this user may post raw HTML. The direct
       $wpdb->insert below bypasses WP's kses, so — like wpap_publish_article — we
       kses per page here so the <!--nextpage--> markers survive while a
       prompt-manipulated model can't smuggle <script> to visitors (matters on
       multisite / when DISALLOW_UNFILTERED_HTML is defined). No-op for a
       single-site admin, who has unfiltered_html. */
    if ( ! current_user_can( 'unfiltered_html' ) ) {
        $full_content = implode( '<!--nextpage-->', array_map(
            'wp_kses_post',
            explode( '<!--nextpage-->', $full_content )
        ) );
    }

    /* ── STEP D: Insert post directly via $wpdb (bypasses kses filter → preserves <!--nextpage-->) ── */
    global $wpdb;
    /* Publish now, or schedule to a random slot in the next hours (spreads a batch out). */
    $sched   = wpap_compute_schedule( $schedule_window );
    $now     = current_time( 'mysql' );      /* post_modified = actual write time */
    $now_gmt = current_time( 'mysql', 1 );
    $slug    = sanitize_title( $title );

    $inserted = $wpdb->insert( $wpdb->posts, array(
        'post_author'           => get_current_user_id(),
        'post_date'             => $sched['date'],
        'post_date_gmt'         => $sched['date_gmt'],
        'post_content'          => $full_content,
        'post_title'            => $title,
        'post_excerpt'          => '',
        'post_status'           => $sched['status'],
        'comment_status'        => 'open',
        'ping_status'           => 'open',
        'post_name'             => $slug,
        'post_modified'         => $now,
        'post_modified_gmt'     => $now_gmt,
        'post_type'             => 'post',
        'to_ping'               => '',
        'pinged'                => '',
        'post_content_filtered' => '',
        'guid'                  => home_url( '/?p=0' ),
    ) );

    if ( ! $inserted ) {
        wp_send_json_error( 'DB insert failed: ' . $wpdb->last_error );
    }
    $post_id = (int) $wpdb->insert_id;

    /* Raw insert skips wp_insert_post's future-post wiring, so schedule the
       auto-publish cron ourselves when this is a scheduled ("future") post. */
    if ( 'future' === $sched['status'] && $sched['ts_gmt'] ) {
        wpap_schedule_future_publish( $post_id, $sched['ts_gmt'] );
    }

    /* Fix slug FIRST, then read the permalink from the final post_name.
       (#1) wp_unique_post_slug can append -2/-3 on a duplicate title; computing
       the permalink before persisting that unique slug would capture the stale,
       pre-collision slug and store a link to the wrong post / a 404. */
    $unique_slug = wp_unique_post_slug( $slug, $post_id, $sched['status'], 'post', 0 );
    $wpdb->update( $wpdb->posts,
        array(
            'post_name' => $unique_slug,
            /* (#5) Keep guid opaque + immutable (WP default form ?p=<id>). Never
               overwrite it with the human permalink — feed readers key on guid. */
            'guid'      => home_url( '/?p=' . $post_id ),
        ),
        array( 'ID' => $post_id )
    );
    clean_post_cache( $post_id );
    $post_url = wpap_public_permalink( $post_id );
    /* ── Internal link injection: embed 2-3 links to existing posts ── */
    $il_posts = $wpdb->get_results( $wpdb->prepare(
        "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_status='publish' AND post_type='post' AND ID!=%d ORDER BY post_date DESC LIMIT 5",
        $post_id
    ) );
    if ( ! empty( $il_posts ) ) {
        $il_pool = array();
        foreach ( $il_posts as $ip ) {
            $il_pool[] = array( 'title' => $ip->post_title, 'url' => get_permalink( $ip->ID ) );
        }
        $il_updated = wpap_inject_internal_links( $full_content, $il_pool, $content_engine, $claude_key, $gemini_key );
        if ( $il_updated && $il_updated !== $full_content && strpos( $il_updated, '<a href' ) !== false ) {
            $wpdb->update( $wpdb->posts, array( 'post_content' => $il_updated ), array( 'ID' => $post_id ) );
            clean_post_cache( $post_id );
            $full_content = $il_updated;
        }
    }

    /* ── STEP E: IMAGE LOGIC (Manual takes 100% priority over AI) ──
     * sleep(2): small delay prevents bulk-mode HTTP blocking from
     * external image APIs (Pexels, Gemini, Pollinations).
     */
    if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) sleep( 2 );  /* 2s gap: rate-limit protection */
    $fallback_attach_id = intval( $_POST['fallback_attach_id'] ?? 0 );
    $attach_id          = 0;
    $image_url          = '';
    $image_debug        = '';

    if ( $fallback_attach_id > 0 ) {
        /*
         * MANUAL IMAGE — uploaded directly in the grid row.
         * This ALWAYS takes priority. No API calls. No exceptions.
         */
        $attach_id   = (int) $fallback_attach_id;
        $image_debug = 'manual_upload';
        /* Re-parent the attachment to this post */
        $wpdb->update( $wpdb->posts, array( 'post_parent' => $post_id ), array( 'ID' => $attach_id ) );
        clean_post_cache( $attach_id );
    } elseif ( $image_engine === 'pexels' ) {
        if ( ! $pexels_key ) { $image_debug = 'pexels:no_key'; }
        else {
            $r = wpap_generate_image_pexels( $title, $post_id, $pexels_key );
            if ( ! is_wp_error( $r ) ) { $attach_id = $r; $image_debug = 'pexels:ok'; }
            else { $image_debug = 'pexels:' . $r->get_error_message(); }
        }
    } elseif ( $image_engine === 'claude' ) {
        if ( ! $claude_key ) { $image_debug = 'claude_img:no_key'; }
        else {
            $r = wpap_generate_image_claude( $title, $post_id, $claude_key );
            if ( ! is_wp_error( $r ) ) { $attach_id = $r; $image_debug = 'claude:ok'; }
            else { $image_debug = 'claude:' . $r->get_error_message(); }
        }
    } elseif ( $image_engine === 'gemini_pro' ) {
        if ( ! $gemini_key ) { $image_debug = 'gemini_pro:no_key'; }
        else {
            $r = wpap_generate_image_gemini( $title, $post_id, $gemini_key, 'pro' );
            if ( ! is_wp_error( $r ) ) { $attach_id = $r; $image_debug = 'gemini_pro:ok'; }
            else { $image_debug = 'gemini_pro:' . $r->get_error_message(); }
        }
    } elseif ( $image_engine === 'manual_only' ) {
        /*
         * MANUAL ONLY — no AI/Pexels call at all.
         * If a fallback_attach_id was provided it was already handled above.
         * If not, the post simply has no featured image — intentional.
         */
        $image_debug = 'manual_only:skip_ai';
    } else { /* gemini_flash — default with 30s retry */
        if ( ! $gemini_key ) { $image_debug = 'gemini_flash:no_key'; }
        else {
            $r = wpap_generate_image_gemini( $title, $post_id, $gemini_key, 'flash' );
            if ( ! is_wp_error( $r ) ) { $attach_id = $r; $image_debug = 'gemini_flash:ok'; }
            else { $image_debug = 'gemini_flash:' . $r->get_error_message(); }
        }
    }
    /* Set featured image and get URL */
    if ( $attach_id > 0 ) {
        set_post_thumbnail( $post_id, $attach_id );
        $image_url = wp_get_attachment_url( $attach_id );
    }

    /* ── STEP F: Build smart link (clean permalink, no ?v= — matches a manual post) ── */
    $smart_link = $post_url;


/* ════════════════════════════════════════════════════════
   LANGUAGE-AWARE HOOK BUILDER
   Called right before STEP G.
   Detects content type (recipe vs article) from the AI's
   fb_text, then appends the correctly translated CTA line
   from a hardcoded 30-language map — no extra API call.
════════════════════════════════════════════════════════ */
/* ════════════════════════════════════════════════════════
   AI HOOK GENERATOR
   Separate lightweight API call AFTER the article is written.
   Sends the actual page1 text so the AI writes a real
   2-sentence teaser in the correct language — not just the title.
   Falls back silently if the call fails (queue never stops).
════════════════════════════════════════════════════════ */
function wpap_generate_hook_via_ai( $title, $page1_html, $lang, $content_engine, $claude_key, $gemini_key ) {

    $lang_names = array(
        'en'=>'English','fr'=>'French','es'=>'Spanish','de'=>'German',
        'it'=>'Italian','pt'=>'Portuguese','nl'=>'Dutch','pl'=>'Polish',
        'ro'=>'Romanian','hu'=>'Hungarian','bg'=>'Bulgarian','cs'=>'Czech',
        'sk'=>'Slovak','hr'=>'Croatian','sv'=>'Swedish','da'=>'Danish',
        'fi'=>'Finnish','el'=>'Greek','ru'=>'Russian','uk'=>'Ukrainian',
        'tr'=>'Turkish','ar'=>'Arabic','he'=>'Hebrew','fa'=>'Persian',
        'zh'=>'Chinese (Simplified)','ja'=>'Japanese','ko'=>'Korean',
        'hi'=>'Hindi','id'=>'Indonesian','vi'=>'Vietnamese','th'=>'Thai',
    );
    $lang_name = isset( $lang_names[ $lang ] ) ? $lang_names[ $lang ] : 'the same language as the article';

    /* Strip HTML, limit to 800 chars of real article text for richer context */
    $excerpt = mb_substr( strip_tags( $page1_html ), 0, 800 );

    $prompt = "You are a viral social media copywriter. Your job is to write a short, punchy teaser that makes people STOP scrolling.\n\n"
            . "Article title: \"" . addslashes( $title ) . "\"\n\n"
            . "Article excerpt (use this content to craft the teaser):\n" . $excerpt . "\n\n"
            . "TASK: Write a catchy 2-sentence viral teaser that summarises the KEY insight or benefit from the article excerpt above.\n"
            . "STRICT RULES — violating any rule means failure:\n"
            . "- FORBIDDEN: Do NOT copy, repeat, or paraphrase the title. The hook must feel completely different from the title.\n"
            . "- REQUIRED: Base your hook on the actual article excerpt content, not the title.\n"
            . "- REQUIRED: Write in " . $lang_name . " ONLY. Every single word must be in " . $lang_name . ".\n"
            . "- FORBIDDEN: No CTA, no hashtags, no emojis, no 'comment', no 'link in bio'.\n"
            . "- FORMAT: Exactly 2 sentences. No bullet points. No labels. No preamble.\n"
            . "OUTPUT: Your 2 sentences in " . $lang_name . ". Nothing else.";

    $result = '';

    if ( $content_engine === 'claude_haiku' && $claude_key ) {
        $r = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
            'timeout' => 25,
            'headers' => array(
                'x-api-key'         => $claude_key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ),
            'body' => wp_json_encode( array(
                'model'      => 'claude-haiku-4-5-20251001',
                'max_tokens' => 100,
                'messages'   => array( array( 'role' => 'user', 'content' => $prompt ) ),
            ) ),
        ) );
        if ( ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r ) ) {
            $b      = json_decode( wp_remote_retrieve_body( $r ), true );
            $result = trim( isset( $b['content'][0]['text'] ) ? $b['content'][0]['text'] : '' );
        }

    } elseif ( $gemini_key ) {
        $model    = ( $content_engine === 'gemini_pro' ) ? 'gemini-1.5-pro' : 'gemini-2.0-flash';
        $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $gemini_key;
        $r = wp_remote_post( $endpoint, array(
            'timeout' => 25,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( array(
                'contents'         => array( array( 'parts' => array( array( 'text' => $prompt ) ) ) ),
                'generationConfig' => array( 'maxOutputTokens' => 100, 'temperature' => 0.8 ),
            ) ),
        ) );
        if ( ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r ) ) {
            $b      = json_decode( wp_remote_retrieve_body( $r ), true );
            $result = trim( isset( $b['candidates'][0]['content']['parts'][0]['text'] )
                ? $b['candidates'][0]['content']['parts'][0]['text'] : '' );
        }
    }

    /* Strip any markdown/backticks the AI added */
    $result = str_replace( array( '```html', '```', '`' ), '', $result );
    $result = trim( $result );
    return $result;
}

function wpap_build_clean_hook( string $fb_text, string $lang, string $title = '', string $page1 = '' ): string {

    $thumb = "\xf0\x9f\x91\x87";   /* 👇 emoji */

    /* ════════════════════════════════════════════
       CTA MAPS — 30 languages × 2 types
    ════════════════════════════════════════════ */
    $recipe_cta = array(
        'en' => "Full recipe in the first comment {$thumb}",
        'fr' => "Recette complète en premier commentaire {$thumb}",
        'es' => "Receta completa en el primer comentario {$thumb}",
        'de' => "Vollständiges Rezept im ersten Kommentar {$thumb}",
        'it' => "Ricetta completa nel primo commento {$thumb}",
        'pt' => "Receita completa no primeiro comentário {$thumb}",
        'nl' => "Volledig recept in de eerste reactie {$thumb}",
        'pl' => "Pełny przepis w pierwszym komentarzu {$thumb}",
        'ro' => "Rețeta completă în primul comentariu {$thumb}",
        'hu' => "A teljes recept az első kommentben {$thumb}",
        'bg' => "Пълната рецепта в първия коментар {$thumb}",
        'cs' => "Celý recept v prvním komentáři {$thumb}",
        'sk' => "Celý recept v prvom komentári {$thumb}",
        'hr' => "Cijeli recept u prvom komentaru {$thumb}",
        'sv' => "Hela receptet i den första kommentaren {$thumb}",
        'da' => "Hele opskriften i den første kommentar {$thumb}",
        'fi' => "Koko resepti ensimmäisessä kommentissa {$thumb}",
        'el' => "Σύνολη συνταγή στο πρώτο σχόλιο {$thumb}",
        'ru' => "Полный рецепт в первом комментарии {$thumb}",
        'uk' => "Повний рецепт у першому коментарі {$thumb}",
        'tr' => "Tam tarif ilk yorumda {$thumb}",
        'ar' => "الوصفة الكاملة في أول تعليق {$thumb}",
        'he' => "המתכון המלא בתגובה הראשונה {$thumb}",
        'fa' => "دستور پخت کامل در اولین نظر {$thumb}",
        'zh' => "完整食谱在第一条评论 {$thumb}",
        'ja' => "完全なレシピは最初のコメントに {$thumb}",
        'ko' => "전체 레시피는 첫 번째 댓글에 {$thumb}",
        'hi' => "पूरी रेसिपी पहली कमेंट में {$thumb}",
        'id' => "Resep lengkap di komentar pertama {$thumb}",
        'vi' => "Công thức đầy đủ trong bình luận đầu tiên {$thumb}",
        'th' => "สูตรครบถ้วนในความคิดเห็นแรก {$thumb}",
    );

    $article_cta = array(
        'en' => "Details in the first comment {$thumb}",
        'fr' => "Détails en premier commentaire {$thumb}",
        'es' => "Detalles en el primer comentario {$thumb}",
        'de' => "Details im ersten Kommentar {$thumb}",
        'it' => "Dettagli nel primo commento {$thumb}",
        'pt' => "Detalhes no primeiro comentário {$thumb}",
        'nl' => "Details in de eerste reactie {$thumb}",
        'pl' => "Szczegóły w pierwszym komentarzu {$thumb}",
        'ro' => "Detalii în primul comentariu {$thumb}",
        'hu' => "Részletek az első kommentben {$thumb}",
        'bg' => "Подробности в първия коментар {$thumb}",
        'cs' => "Podrobnosti v prvním komentáři {$thumb}",
        'sk' => "Podrobnosti v prvom komentári {$thumb}",
        'hr' => "Detalji u prvom komentaru {$thumb}",
        'sv' => "Detaljer i den första kommentaren {$thumb}",
        'da' => "Detaljer i den første kommentar {$thumb}",
        'fi' => "Yksityiskohdat ensimmäisessä kommentissa {$thumb}",
        'el' => "Λεπτομέρειες στο πρώτο σχόλιο {$thumb}",
        'ru' => "Подробности в первом комментарии {$thumb}",
        'uk' => "Деталі у першому коментарі {$thumb}",
        'tr' => "Ayrıntılar ilk yorumda {$thumb}",
        'ar' => "التفاصيل في أول تعليق {$thumb}",
        'he' => "פרטים בתגובה הראשונה {$thumb}",
        'fa' => "جزئیات در اولین نظر {$thumb}",
        'zh' => "详情在第一条评论 {$thumb}",
        'ja' => "詳細は最初のコメントに {$thumb}",
        'ko' => "자세한 내용은 첫 번째 댓글에 {$thumb}",
        'hi' => "विवरण पहली कमेंट में {$thumb}",
        'id' => "Detail di komentar pertama {$thumb}",
        'vi' => "Chi tiết trong bình luận đầu tiên {$thumb}",
        'th' => "รายละเอียดในความคิดเห็นแรก {$thumb}",
    );

    /* ════════════════════════════════════════════
       SMART RECIPE DETECTION
       Check title first (most reliable), then content.
       Recipe keywords in major languages.
    ════════════════════════════════════════════ */
    $recipe_keywords = array(
        /* English */
        'recipe','recipes','cook','cooking','bake','baking','ingredient','ingredients',
        'dish','meal','dessert','cake','pie','bread','soup','salad','sauce','stew',
        'roast','grill','fry','casserole','muffin','cookie','brownie','cheesecake',
        'pancake','waffle','pasta','noodle','curry','taco','pizza','sandwich','roll',
        /* French */
        'recette','cuisiner','cuire','ingrédient','pâtisserie','gâteau','tarte',
        /* Spanish */
        'receta','cocinar','ingrediente','pastel','tarta','bizcocho','sopa',
        /* German */
        'rezept','kochen','backen','zutat','kuchen','suppe','braten',
        /* Italian */
        'ricetta','cucinare','ingrediente','torta','dolce','pasta','zuppa',
        /* Portuguese */
        'receita','cozinhar','ingrediente','bolo','torta','sopa',
        /* Dutch */
        'recept','koken','bakken','ingrediënt','taart','soep',
        /* Polish */
        'przepis','gotować','piec','składnik','ciasto','zupa',
        /* Hungarian */
        'recept','főzni','sütni','hozzávaló','torta','leves',
        /* Bulgarian */
        'рецепта','готвя','пека','съставка','торта','супа',
        /* Russian */
        'рецепт','готовить','печь','ингредиент','торт','суп',
        /* Turkish */
        'tarif','pişirmek','malzeme','kek','çorba',
        /* Arabic */
        'وصفة','طبخ','مكونات','كعكة','حلوى','شوربة',
    );

    /* Build search corpus: title + first 500 chars of page1 */
    $corpus = mb_strtolower( $title . ' ' . mb_substr( strip_tags( $page1 ), 0, 500 ) );

    $is_recipe = false;
    foreach ( $recipe_keywords as $kw ) {
        if ( mb_strpos( $corpus, mb_strtolower( $kw ) ) !== false ) {
            $is_recipe = true;
            break;
        }
    }

    /* ════════════════════════════════════════════
       BUILD THE HOOK
    ════════════════════════════════════════════ */
    $use_lang = ( strlen( $lang ) === 2 ) ? strtolower( $lang ) : 'en';
    $cta_map  = $is_recipe ? $recipe_cta : $article_cta;
    $cta      = $cta_map[ $use_lang ] ?? $cta_map['en'];

    /* Clean the AI-generated 2-sentence hook:
       Remove [POST_URL] and any CTA the AI may have accidentally added */
    $clean = trim( str_replace( '[POST_URL]', '', $fb_text ) );
    $clean = str_replace( array( '```html', '```', '`' ), '', $clean );

    /* Strip any line that already contains a 👇 emoji or comment-related CTA */
    $lines = array_filter( array_map( 'trim', explode( "\n", $clean ) ) );
    $lines = array_values( $lines );
    $filtered = array();
    foreach ( $lines as $line ) {
        /* Skip lines that look like CTA lines */
        if ( mb_strpos( $line, "\xf0\x9f\x91\x87" ) !== false ) continue; /* 👇 */
        if ( preg_match( '/\b(comment|koment|yorum|تعليق|коммент|komentár)\b/ui', $line ) ) continue;
        if ( preg_match( '/\b(first comment|premier commentaire|primer comentario|ersten kommentar)\b/ui', $line ) ) continue;
        $filtered[] = $line;
    }
    $clean = implode( "\n", $filtered );
    $clean = trim( $clean );

    /* Safety: if AI returned empty text, build a generic hook from title */
    if ( strlen( $clean ) < 10 ) {
        $clean = $title . '.';
    }

    /* Final hook = 2-sentence AI summary + correct translated CTA */
    return $clean . "\n" . $cta;
}


    /* ── STEP G: Build language-aware hook ──
     *
     * Step 1: wpap_generate_hook_via_ai()
     *   Sends the real page1 content to AI → gets a genuine 2-sentence
     *   teaser in the correct language (not just the title paraphrased).
     *   If this API call fails for any reason → falls back to $fb_text.
     *
     * Step 2: wpap_build_clean_hook()
     *   Detects recipe vs article (title + page1 corpus),
     *   strips any duplicate CTA, appends the translated CTA.
     */
    $ai_hook_text = wpap_generate_hook_via_ai(
        $title, $page1, $lang, $content_engine, $claude_key, $gemini_key
    );
    /* Use real AI hook if valid (>20 chars); fall back to main-call fb_text */
    $hook_source  = ( strlen( $ai_hook_text ) > 20 )
        ? $ai_hook_text
        : trim( str_replace( '[POST_URL]', '', $fb_text ) );
    $fb_hook_clean = wpap_build_clean_hook( $hook_source, $lang, $title, $page1 );
    $fb_text_with_link = $fb_hook_clean . "

" . $smart_link;

    /* ── STEP H: Save to post meta ── */
    update_post_meta( $post_id, '_wpap_image_url',  $image_url );
    update_post_meta( $post_id, '_wpap_fb_hook',    $fb_hook_clean );
    update_post_meta( $post_id, 'ah_social_hook',   $fb_hook_clean );  /* ah_social_hook — requested field */
    update_post_meta( $post_id, '_wpap_smart_link', $smart_link );

    /* ── STEP I: Save to plugin custom table ── */
    $wpdb->insert( $wpdb->prefix . WPAP_TABLE, array(
        'post_id'    => $post_id,
        'title'      => $title,
        'post_url'   => $post_url,
        'image_url'  => $image_url,
        'fb_text'    => $fb_hook_clean,
        'fb_post_id' => '',
        'smart_link' => $smart_link,
    ) );
    $wpap_row_id = (int) $wpdb->insert_id;

    /* Facebook posting removed — use Distribution Hub for manual sharing */
    wp_send_json_success( array(
        'title'       => $title,
        'post_url'    => $post_url,
        'image_url'   => $image_url,
        'smart_link'  => $smart_link,
        'fb_post_id'  => '',
        'fb_status'   => 'disabled',
        'fb_text'     => $fb_hook_clean,
        'image_debug' => $image_debug,
        'lang'        => $lang,
        'row_id'      => $wpap_row_id,
        'post_status' => $sched['status'],
        'scheduled_label' => $sched['label'],
        'nonce'       => wp_create_nonce( 'wpap_nonce' ),
    ) );
}

/* ════════════════════════════════════════════
   8. AJAX: GET POSTS TABLE
   Reads image_url and fb_text from post meta
   (_wpap_image_url, _wpap_fb_hook) as primary source,
   with plugin table as fallback.
════════════════════════════════════════════ */
add_action( 'wp_ajax_wpap_get_posts', 'wpap_ajax_get_posts' );

/* ════════════════════════════════════════════
   9. REST PUBLISH ENDPOINT  —  POST /wp-json/wpap/v1/publish
   Opt-in headless/programmatic publish via a WordPress Application Password
   (auth = manage_options; no anonymous access, registers no front-end output).
   Publishes each item through wpap_publish_article() — the SAME full contract as
   Direct Publish (bodies, [[link]] tokens, keywords, recipe, SEO). Lets an external
   tool push a batch without a live wp-admin session, so publishing can be fully
   automated. Per-item try/catch so one bad row can't wedge the batch.
   Body: { items:[ contract objects ], num_parts?:1-10, schedule_window?:0-168 hours (even-spread)
          OR "drip:N" (N posts/day, daytime, queued after the last), category?:name|id }.
════════════════════════════════════════════ */
add_action( 'rest_api_init', 'wpap_rest_register_routes' );
function wpap_rest_register_routes() {
    register_rest_route( 'wpap/v1', '/publish', array(
        'methods'             => 'POST',
        'callback'            => 'wpap_rest_publish',
        'permission_callback' => function () { return current_user_can( 'manage_options' ); },
    ) );
}
function wpap_rest_publish( WP_REST_Request $request ) {
    $items = $request->get_param( 'items' );
    if ( ! is_array( $items ) || empty( $items ) ) {
        return new WP_REST_Response( array( 'ok' => false, 'error' => 'Provide a non-empty "items" array.' ), 400 );
    }
    if ( count( $items ) > 2000 ) { $items = array_slice( $items, 0, 2000 ); }   /* sane per-request cap */

    $parts = (int) $request->get_param( 'num_parts' );
    if ( $parts < 1 )  { $parts = 1; }
    if ( $parts > 10 ) { $parts = 10; }
    $schedule_window = wpap_parse_schedule_window( $request->get_param( 'schedule_window' ) ?? 0 );  /* hours 0–168, or "drip:N" (N posts/day, daytime) */
    $default_category = sanitize_text_field( (string) ( $request->get_param( 'category' ) ?? '' ) );

    $created = array(); $skipped = 0; $failed = 0; $messages = array();
    foreach ( array_values( $items ) as $i => $item ) {
        if ( ! is_array( $item ) ) { $failed++; $messages[] = sprintf( 'Item %d: not an object.', $i + 1 ); continue; }
        try {
            $opts = array(
                'default_parts'    => $parts,
                'schedule_window'  => $schedule_window,
                'default_category' => $default_category,
            );
            $r = wpap_publish_article( $item, $opts );
            if ( is_wp_error( $r ) ) {
                $skipped++; $messages[] = sprintf( 'Item %d skipped: %s', $i + 1, $r->get_error_message() );
                continue;
            }
            $pid  = (int) $r;
            $post = get_post( $pid );
            $created[] = array(
                'post_id'   => $pid,
                'title'     => $post ? $post->post_title : '',
                'url'       => (string) wpap_public_permalink( $pid ),
                'status'    => $post ? $post->post_status : '',
                'scheduled' => ( $post && 'future' === $post->post_status ) ? $post->post_date : '',
            );
        } catch ( \Throwable $e ) {
            $failed++;
            $messages[] = sprintf( 'Item %d fatal: %s', $i + 1, $e->getMessage() );
            error_log( '[Automation Hamri] REST publish crashed on item ' . ( $i + 1 ) . ': ' . $e->getMessage() );
        }
    }

    return new WP_REST_Response( array(
        'ok'       => true,
        'created'  => count( $created ),
        'skipped'  => $skipped,
        'failed'   => $failed,
        'posts'    => $created,
        'messages' => array_slice( $messages, 0, 50 ),
    ), 200 );
}

/* ════════════════════════════════════════════
   NEAR-DUPLICATE CLEANUP — find posts covering the SAME topic under a reworded title (the dupes the
   exact-title guard can't catch), keep the oldest original, trash the rest. Preview → review → trash.
   Mirrors batch-enrich/check-against-live.mjs. manage_options + nonce; trash is recoverable.
════════════════════════════════════════════ */

/* Significant title tokens: lowercase, strip entities/punctuation, drop stopwords + generic filler
   ("old-fashioned", "remedy", "recipe", "the", …) so two REWORDED posts on the same subject match
   while distinct posts that merely share a common word do not. */
function wpap_dup_title_tokens( $title ) {
    static $stop = null;
    if ( null === $stop ) {
        $stop = array_flip( preg_split( '/\s+/', 'a an and the of for to in on with your you what it its is are was how why when this that these those i we my our from as at or but not no do does can cant cannot really actually old old-fashioned older oldest remedy remedies home homemade natural traditional folk grandma grandmother great-grandmother trick tricks secret secrets thing things simple little bit made make making use used using done good better best story kitchen classic vintage way ways before after into out up down more most about know knew known here there they them their just only every any each recipe recipes' ) );
    }
    $t = strtolower( (string) $title );
    $t = preg_replace( '/&#?[a-z0-9]+;/', ' ', $t );
    $t = preg_replace( '/[^a-z0-9\s-]/', ' ', $t );
    $t = str_replace( '-', ' ', $t );
    $out = array();
    foreach ( preg_split( '/\s+/', (string) $t ) as $w ) {
        if ( strlen( $w ) > 2 && ! isset( $stop[ $w ] ) ) { $out[ $w ] = true; }
    }
    return array_keys( $out );
}

function wpap_dup_jaccard( $a, $b ) {
    if ( empty( $a ) || empty( $b ) ) { return 0.0; }
    $sa = array_flip( $a );
    $inter = 0;
    foreach ( $b as $w ) { if ( isset( $sa[ $w ] ) ) { $inter++; } }
    $union = count( $a ) + count( $b ) - $inter;
    return $union > 0 ? $inter / $union : 0.0;
}

/* Cluster posts by title similarity (union-find). Each group of 2+ keeps the OLDEST post; the rest are
   returned as removable near-duplicates. O(n^2) on token sets, capped at $limit posts. */
function wpap_find_near_duplicate_groups( $threshold = 0.42, $limit = 3000 ) {
    global $wpdb;
    $threshold = max( 0.2, min( 0.95, (float) $threshold ) );
    $limit     = max( 1, min( 5000, (int) $limit ) );
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT ID, post_title, post_date FROM {$wpdb->posts}
          WHERE post_type='post' AND post_status IN ('publish','future','draft','pending','private')
            AND TRIM(post_title) <> '' ORDER BY ID ASC LIMIT %d", $limit ) );
    $rows = array_values( (array) $rows );
    $n = count( $rows );
    $tok = array(); $parent = range( 0, max( 0, $n - 1 ) );
    for ( $i = 0; $i < $n; $i++ ) { $tok[ $i ] = wpap_dup_title_tokens( $rows[ $i ]->post_title ); }
    $find = function ( $x ) use ( &$parent ) { while ( $parent[ $x ] !== $x ) { $parent[ $x ] = $parent[ $parent[ $x ] ]; $x = $parent[ $x ]; } return $x; };
    for ( $i = 0; $i < $n; $i++ ) {
        for ( $j = $i + 1; $j < $n; $j++ ) {
            if ( wpap_dup_jaccard( $tok[ $i ], $tok[ $j ] ) >= $threshold ) {
                $ri = $find( $i ); $rj = $find( $j );
                if ( $ri !== $rj ) { $parent[ $ri ] = $rj; }
            }
        }
    }
    $clusters = array();
    for ( $i = 0; $i < $n; $i++ ) { $clusters[ $find( $i ) ][] = $i; }
    $groups = array();
    foreach ( $clusters as $members ) {
        if ( count( $members ) < 2 ) { continue; }
        usort( $members, function ( $a, $b ) use ( $rows ) { return strcmp( (string) $rows[ $a ]->post_date, (string) $rows[ $b ]->post_date ); } );
        $keep = $rows[ $members[0] ];
        $dups = array();
        $cnt  = count( $members );
        for ( $k = 1; $k < $cnt; $k++ ) {
            $r = $rows[ $members[ $k ] ];
            $dups[] = array(
                'id'     => (int) $r->ID,
                'title'  => (string) $r->post_title,
                'date'   => substr( (string) $r->post_date, 0, 10 ),
                'status' => (string) get_post_status( $r->ID ),
            );
        }
        $groups[] = array(
            'keep' => array( 'id' => (int) $keep->ID, 'title' => (string) $keep->post_title, 'date' => substr( (string) $keep->post_date, 0, 10 ) ),
            'dups' => $dups,
        );
    }
    usort( $groups, function ( $a, $b ) { return count( $b['dups'] ) - count( $a['dups'] ); } );
    return $groups;
}

/* AJAX: preview near-duplicate groups (no changes made). */
add_action( 'wp_ajax_wpap_scan_near_duplicates', 'wpap_ajax_scan_near_duplicates' );
function wpap_ajax_scan_near_duplicates() {
    check_ajax_referer( 'wpap_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Unauthorized' ); }
    @set_time_limit( 120 );
    $threshold = isset( $_POST['threshold'] ) ? (float) $_POST['threshold'] : 0.42;
    $groups = wpap_find_near_duplicate_groups( $threshold );
    $total  = 0;
    foreach ( $groups as $g ) { $total += count( $g['dups'] ); }
    wp_send_json_success( array( 'groups' => $groups, 'group_count' => count( $groups ), 'dup_count' => $total, 'threshold' => $threshold ) );
}

/* AJAX: trash the SELECTED near-duplicate post IDs (recoverable). Trusts the reviewed id list — the UI
   pre-checks the dups and lets the user untick any false positive first. Only trashes real posts. */
add_action( 'wp_ajax_wpap_trash_near_duplicates', 'wpap_ajax_trash_near_duplicates' );
function wpap_ajax_trash_near_duplicates() {
    check_ajax_referer( 'wpap_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Unauthorized' ); }
    @set_time_limit( 300 );
    $ids = isset( $_POST['ids'] ) ? (array) wp_unslash( $_POST['ids'] ) : array();
    $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
    $trashed = 0; $deadline = time() + 240;
    foreach ( $ids as $id ) {
        if ( time() > $deadline ) { break; }
        if ( 'post' !== get_post_type( $id ) ) { continue; }
        if ( wp_trash_post( $id ) ) { $trashed++; }
    }
    wp_send_json_success( array( 'trashed' => (int) $trashed, 'requested' => count( $ids ) ) );
}
