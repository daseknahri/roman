<?php
/**
 * Gutenberg Author Tools — meta box + derived fields
 *
 * Extracted verbatim from wp-automator-pro.php (single-file → modular).
 * Load order is fixed by the main file; every hook self-registers here.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/* mbstring-safe string helpers (some hosts ship without mbstring). */
function wpap_ed_lower( $s ) {
    $s = (string) $s;
    return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
}
function wpap_ed_len( $s ) {
    $s = (string) $s;
    return function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : strlen( $s );
}

/* English stop-words for niche-agnostic tag / category suggestion. */
function wpap_ed_stopwords() {
    static $s = null;
    if ( null !== $s ) { return $s; }
    $s = array_flip( array(
        'the','and','for','are','but','not','you','all','any','can','had','her','was','one',
        'our','out','day','get','has','him','his','how','man','new','now','old','see','two',
        'way','who','boy','did','its','let','put','say','she','too','use','that','this','with',
        'have','from','they','will','would','there','their','what','about','which','when','make',
        'like','time','just','know','take','into','your','some','them','than','then','look',
        'only','come','over','also','back','after','work','first','well','even','want','because',
        'these','give','most','made','more','such','very','here','through','being','while','where',
        'been','were','does','did','done','using','used','onto','off',
    ) );
    return $s;
}

/* Top keywords: unicode letter-words 3+ chars, stop-words removed, by frequency. */
function wpap_ed_keywords( $text, $limit = 5 ) {
    $text = wpap_ed_lower( wp_strip_all_tags( (string) $text ) );
    if ( ! preg_match_all( '/[\p{L}]{3,}/u', $text, $m ) ) { return array(); }
    $stop = wpap_ed_stopwords();
    $freq = array();
    foreach ( $m[0] as $w ) {
        if ( isset( $stop[ $w ] ) ) { continue; }
        $freq[ $w ] = isset( $freq[ $w ] ) ? $freq[ $w ] + 1 : 1;
    }
    if ( empty( $freq ) ) { return array(); }
    arsort( $freq );
    return array_slice( array_keys( $freq ), 0, max( 0, (int) $limit ) );
}

/* Truncate a one-line string to a character budget on a word boundary. */
function wpap_ed_clip( $str, $max ) {
    $str = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $str ) ) );
    if ( wpap_ed_len( $str ) <= $max ) { return $str; }
    $cut = function_exists( 'mb_substr' ) ? mb_substr( $str, 0, $max, 'UTF-8' ) : substr( $str, 0, $max );
    $sp  = function_exists( 'mb_strrpos' ) ? mb_strrpos( $cut, ' ', 0, 'UTF-8' ) : strrpos( $cut, ' ' );
    if ( $sp && $sp > (int) ( $max * 0.5 ) ) {
        $cut = function_exists( 'mb_substr' ) ? mb_substr( $cut, 0, $sp, 'UTF-8' ) : substr( $cut, 0, $sp );
    }
    return rtrim( $cut, " \t\n\r\0\x0B,.;:—-" );
}

/* Clean a single extracted recipe line: strip tags, collapse whitespace, drop a
   leading list marker ("1.", "2)", bullet). */
function wpap_ed_clean_item( $raw ) {
    $t = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $raw ) ) );
    $t = preg_replace( '/^\s*(?:\d+\s*[.\)\-:]|[\x{2022}\x{2023}\x{25E6}\x{2043}\-\*])\s*/u', '', $t );
    return trim( (string) $t );
}

/* Collect items from a section body: prefer <li> items, else split paragraphs
   / <br> lines. Caps at 60 items so a runaway body can't bloat the meta. */
function wpap_ed_list_items( $body ) {
    $items = array();
    if ( preg_match_all( '/<li[^>]*>(.*?)<\/li>/is', (string) $body, $mm ) ) {
        foreach ( $mm[1] as $li ) {
            $t = wpap_ed_clean_item( $li );
            if ( '' !== $t ) { $items[] = $t; }
        }
    } else {
        $b = preg_replace( '/<\/(p|div|h[1-6])>/i', "\n", (string) $body );
        $b = preg_replace( '/<br\s*\/?>/i', "\n", $b );
        foreach ( preg_split( '/\r\n|\r|\n/', wp_strip_all_tags( $b ) ) as $line ) {
            $t = wpap_ed_clean_item( $line );
            if ( '' !== $t ) { $items[] = $t; }
        }
    }
    return array_slice( $items, 0, 60 );
}

/* Pull ingredients + steps out of post HTML by locating the usual section
   headers ("Ingredients", "Instructions"/"Directions"/"Steps"/…) — either real
   headings (h2-h4) or a paragraph that's essentially one bold label — and taking
   the list (or paragraphs) that follow, up to the next header. Mirrors the JS
   extractor. Returns array( 'ingredients' => [...], 'steps' => [...] ). */
function wpap_ed_extract_recipe( $html ) {
    $out  = array( 'ingredients' => array(), 'steps' => array() );
    $html = (string) $html;
    if ( '' === trim( $html ) ) { return $out; }

    $marker = '/<h[2-4][^>]*>(.*?)<\/h[2-4]>|<p[^>]*>\s*<(?:strong|b)>(.*?)<\/(?:strong|b)>\s*:?\s*<\/p>/is';
    if ( ! preg_match_all( $marker, $html, $mm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
        return $out;
    }

    $n = count( $mm );
    for ( $i = 0; $i < $n; $i++ ) {
        $g1    = ( isset( $mm[ $i ][1] ) && '' !== $mm[ $i ][1][0] ) ? $mm[ $i ][1][0] : '';
        $g2    = ( isset( $mm[ $i ][2] ) ) ? $mm[ $i ][2][0] : '';
        $label = wp_strip_all_tags( '' !== $g1 ? $g1 : $g2 );
        $start = $mm[ $i ][0][1] + strlen( $mm[ $i ][0][0] );
        $end   = ( $i + 1 < $n ) ? $mm[ $i + 1 ][0][1] : strlen( $html );
        $body  = substr( $html, $start, $end - $start );

        if ( empty( $out['ingredients'] ) && preg_match( '/ingredient/i', $label ) ) {
            $out['ingredients'] = wpap_ed_list_items( $body );
        } elseif ( empty( $out['steps'] ) && preg_match( '/instruction|direction|step|method|preparation|how to make|how to prepare/i', $label ) ) {
            $out['steps'] = wpap_ed_list_items( $body );
        }
    }
    return $out;
}

/* Resolve the split selector into a part count (0 = don't split). */
function wpap_ed_resolve_split_parts( $mode, $content ) {
    if ( '2' === $mode ) { return 2; }
    if ( '3' === $mode ) { return 3; }
    if ( 'smart' === $mode ) {
        $words = str_word_count( wp_strip_all_tags( (string) $content ) );
        if ( $words >= 1300 ) { return 3; }
        if ( $words >= 650 )  { return 2; }
    }
    return 0;
}

/* Assign a best-match existing category (if the post has none) and keyword tags
   (if the post has none). Only ever ADDS — never removes your selections. */
function wpap_ed_maybe_assign_terms( $post_id, $title, $content ) {
    $default_cat = (int) get_option( 'default_category' );

    $cur      = wp_get_post_categories( $post_id );
    $has_real = false;
    foreach ( $cur as $cid ) { if ( (int) $cid !== $default_cat ) { $has_real = true; break; } }

    if ( ! $has_real ) {
        $text = wpap_ed_lower( $title . ' ' . $title . ' ' . wp_strip_all_tags( (string) $content ) );
        $cats = get_categories( array( 'hide_empty' => false, 'number' => 300 ) );
        $best = 0; $best_score = 0;
        foreach ( $cats as $c ) {
            if ( (int) $c->term_id === $default_cat ) { continue; }
            $words = preg_split( '/[^\p{L}]+/u', wpap_ed_lower( $c->name . ' ' . $c->slug ) );
            $score = 0;
            foreach ( (array) $words as $nw ) {
                if ( wpap_ed_len( $nw ) < 3 ) { continue; }
                $score += substr_count( $text, $nw );
            }
            if ( $score > $best_score ) { $best_score = $score; $best = (int) $c->term_id; }
        }
        if ( $best > 0 ) {
            wp_set_post_categories( $post_id, array( $best ), true );
            update_post_meta( $post_id, '_wpap_ed_auto_category', '1' );
        }
    }

    $cur_tags = wp_get_post_tags( $post_id, array( 'fields' => 'ids' ) );
    if ( empty( $cur_tags ) ) {
        $kw = wpap_ed_keywords( $title . ' ' . $title . ' ' . $content, 5 );
        if ( ! empty( $kw ) ) {
            wp_set_post_terms( $post_id, $kw, 'post_tag', true );
            update_post_meta( $post_id, '_wpap_ed_auto_tags', '1' );
        }
    }
}

/* Push the entered image metadata onto the featured-image attachment. */
function wpap_ed_apply_image_meta( $post_id ) {
    $thumb = (int) get_post_thumbnail_id( $post_id );
    if ( $thumb <= 0 ) { return; }
    $alt     = (string) get_post_meta( $post_id, '_wpap_ed_img_alt', true );
    $title   = (string) get_post_meta( $post_id, '_wpap_ed_img_title', true );
    $caption = (string) get_post_meta( $post_id, '_wpap_ed_img_caption', true );
    $desc    = (string) get_post_meta( $post_id, '_wpap_ed_img_desc', true );
    /* Fill-if-empty ONLY: the attachment is SHARED across posts, so never clobber
       its curated metadata — seed a blank field, then leave it alone. Otherwise
       every post that reuses the image would rewrite the attachment's alt/title to
       its own (last-saved-post wins). */
    $att = get_post( $thumb );
    if ( '' !== $alt && '' === trim( (string) get_post_meta( $thumb, '_wp_attachment_image_alt', true ) ) ) {
        update_post_meta( $thumb, '_wp_attachment_image_alt', $alt );
    }
    $up = array();
    if ( '' !== $title   && $att && '' === trim( (string) $att->post_title ) )   { $up['post_title']   = $title; }
    if ( '' !== $caption && $att && '' === trim( (string) $att->post_excerpt ) ) { $up['post_excerpt'] = $caption; }
    if ( '' !== $desc    && $att && '' === trim( (string) $att->post_content ) ) { $up['post_content'] = $desc; }
    if ( ! empty( $up ) ) {
        $up['ID'] = $thumb;
        wp_update_post( $up );   /* attachment save — does not fire save_post_post */
    }
}

/* ── REST meta registration (lets the Gutenberg sidebar panel bind + persist) ──
   Every field the meta box exposes is registered for REST so the block-editor
   panel can read/write it through the core/editor store. These are protected
   underscore-prefixed keys, so an auth_callback is required. Toggle convention
   everywhere: '1' = on, anything else = off. autofill/manage are OPT-IN — stored
   explicitly for new posts by the editor (no registered default), so editing an
   existing/legacy post never silently enrolls it into plugin management. */
add_action( 'init', 'wpap_ed_register_meta' );
function wpap_ed_register_meta() {
    /* Per-POST permission (not the general edit_posts) — a contributor who can't
       edit THIS post can't write its meta over REST either. */
    $auth = function ( $allowed, $meta_key, $object_id ) {
        return current_user_can( 'edit_post', (int) $object_id );
    };
    /* sanitize_callback runs on EVERY write (REST panel AND the classic $_POST
       path via sanitize_meta), so meta is sanitized at input regardless of source. */
    $text     = 'sanitize_text_field';
    $textarea = 'sanitize_textarea_field';
    $flag     = function ( $v ) { return '1' === (string) $v ? '1' : ''; };
    $minutes  = function ( $v ) { return max( 0, min( 2880, (int) $v ) ); };
    $split    = function ( $v ) { $v = sanitize_text_field( (string) $v ); return in_array( $v, array( '0', 'smart', '2', '3' ), true ) ? $v : '0'; };

    $string_fields = array(
        '_wpap_ed_seo_title'       => $text,
        '_wpap_ed_img_alt'         => $text,
        '_wpap_ed_img_title'       => $text,
        '_wpap_recipe_servings'    => $text,
        '_wpap_ed_meta_desc'       => $textarea,
        '_wpap_ed_img_caption'     => $textarea,
        '_wpap_ed_img_desc'        => $textarea,
        '_wpap_recipe_ingredients' => $textarea,
        '_wpap_recipe_steps'       => $textarea,
        '_wpap_recipe_on'          => $flag,
        '_wpap_ed_split'           => $split,
    );
    foreach ( $string_fields as $key => $sanitize ) {
        register_post_meta( 'post', $key, array(
            'single'            => true,
            'type'              => 'string',
            'show_in_rest'      => true,
            'sanitize_callback' => $sanitize,
            'auth_callback'     => $auth,
        ) );
    }

    foreach ( array( '_wpap_recipe_prep', '_wpap_recipe_cook', '_wpap_recipe_total' ) as $key ) {
        register_post_meta( 'post', $key, array(
            'single'            => true,
            'type'              => 'integer',
            'show_in_rest'      => true,
            'sanitize_callback' => $minutes,
            'auth_callback'     => $auth,
        ) );
    }

    /* No registered 'default' => '1' here: a registered default makes get_post_meta
       return '1' for posts that never stored the key, which would make apply_derived
       (it runs on EVERY REST save) silently "adopt" any legacy/third-party-edited
       post into plugin management. Instead the opt-in is stored EXPLICITLY for new
       posts — by the Classic meta box (checkbox posts its value) and by the Gutenberg
       panel (seeds '1' on a clean new post). Existing posts stay opt-OUT unless set. */
    foreach ( array( '_wpap_ed_autofill', '_wpap_ed_manage' ) as $key ) {
        register_post_meta( 'post', $key, array(
            'single'            => true,
            'type'              => 'string',
            'show_in_rest'      => true,
            'sanitize_callback' => $flag,
            'auth_callback'     => $auth,
        ) );
    }
}

/* ── Meta box ── */
add_action( 'add_meta_boxes_post', 'wpap_ed_add_meta_box' );
function wpap_ed_add_meta_box() {
    /* Classic editor only — the Block editor gets the Gutenberg sidebar panel
       instead (assets/editor-gutenberg.js), so the two never render together. */
    $s = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( $s && method_exists( $s, 'is_block_editor' ) && $s->is_block_editor() ) { return; }
    add_meta_box(
        'wpap-author-tools',
        __( 'WP Automator — Author Tools', 'wp-automator-pro' ),
        'wpap_ed_render_meta_box',
        'post',
        'normal',
        'high'
    );
}

function wpap_ed_render_meta_box( $post ) {
    wp_nonce_field( 'wpap_ed_save', 'wpap_ed_nonce' );
    $m = function ( $k, $d = '' ) use ( $post ) {
        $v = get_post_meta( $post->ID, $k, true );
        return ( '' !== $v && null !== $v ) ? $v : $d;
    };
    /* New posts default ON (mirrors the plugin's manage-by-default); an EXISTING
       post with no stored flag defaults OFF so editing legacy content never
       silently enrolls it. Once set, the stored value wins. */
    $is_new      = ( 'auto-draft' === $post->post_status );
    $autofill_on = $is_new ? true : ( '1' === (string) $m( '_wpap_ed_autofill', '' ) );
    $manage_on   = $is_new ? true : ( '1' === (string) $m( '_wpap_ed_manage', '' ) );
    $split       = (string) $m( '_wpap_ed_split', '0' );

    $ta = 'style="width:100%;box-sizing:border-box;" rows="2"';
    $tx = 'style="width:100%;box-sizing:border-box;"';
    ?>
    <div class="wpap-ed">
        <div class="wpap-ed-actions">
            <button type="button" class="button button-primary" id="wpap-ed-autofill"><?php esc_html_e( 'Auto-fill empty fields', 'wp-automator-pro' ); ?></button>
            <button type="button" class="button" id="wpap-ed-prepare"><?php esc_html_e( 'Prepare for publishing', 'wp-automator-pro' ); ?></button>
            <span class="wpap-ed-note"><?php esc_html_e( 'Auto-fill only touches EMPTY fields — it never overwrites what you typed.', 'wp-automator-pro' ); ?></span>
        </div>

        <div class="wpap-ed-checklist" id="wpap-ed-checklist" aria-live="polite"></div>

        <div class="wpap-ed-grid">
            <p>
                <label for="wpap_ed_seo_title"><strong><?php esc_html_e( 'SEO title', 'wp-automator-pro' ); ?></strong></label>
                <input type="text" <?php echo $tx; // phpcs:ignore ?> id="wpap_ed_seo_title" name="_wpap_ed_seo_title" value="<?php echo esc_attr( $m( '_wpap_ed_seo_title' ) ); ?>" maxlength="120" />
            </p>
            <p>
                <label for="wpap_ed_meta_desc"><strong><?php esc_html_e( 'Meta description', 'wp-automator-pro' ); ?></strong></label>
                <textarea <?php echo $ta; // phpcs:ignore ?> id="wpap_ed_meta_desc" name="_wpap_ed_meta_desc" maxlength="180"><?php echo esc_textarea( $m( '_wpap_ed_meta_desc' ) ); ?></textarea>
            </p>
        </div>

        <details class="wpap-ed-details">
            <summary><?php esc_html_e( 'Featured-image metadata', 'wp-automator-pro' ); ?></summary>
            <p><label><?php esc_html_e( 'Alt text', 'wp-automator-pro' ); ?><br><input type="text" <?php echo $tx; // phpcs:ignore ?> name="_wpap_ed_img_alt" value="<?php echo esc_attr( $m( '_wpap_ed_img_alt' ) ); ?>" /></label></p>
            <p><label><?php esc_html_e( 'Title', 'wp-automator-pro' ); ?><br><input type="text" <?php echo $tx; // phpcs:ignore ?> name="_wpap_ed_img_title" value="<?php echo esc_attr( $m( '_wpap_ed_img_title' ) ); ?>" /></label></p>
            <p><label><?php esc_html_e( 'Caption', 'wp-automator-pro' ); ?><br><textarea <?php echo $ta; // phpcs:ignore ?> name="_wpap_ed_img_caption"><?php echo esc_textarea( $m( '_wpap_ed_img_caption' ) ); ?></textarea></label></p>
            <p><label><?php esc_html_e( 'Description', 'wp-automator-pro' ); ?><br><textarea <?php echo $ta; // phpcs:ignore ?> name="_wpap_ed_img_desc"><?php echo esc_textarea( $m( '_wpap_ed_img_desc' ) ); ?></textarea></label></p>
        </details>

        <details class="wpap-ed-details">
            <summary><?php esc_html_e( 'Recipe details (adds Recipe rich-result schema)', 'wp-automator-pro' ); ?></summary>
            <p><label><input type="checkbox" name="wpap_recipe_on" value="1" <?php checked( '1' === (string) $m( '_wpap_recipe_on' ) ); ?> /> <strong><?php esc_html_e( 'This post is a recipe', 'wp-automator-pro' ); ?></strong></label></p>
            <p class="wpap-ed-recipe-row">
                <label><?php esc_html_e( 'Servings', 'wp-automator-pro' ); ?><br><input type="text" name="_wpap_recipe_servings" value="<?php echo esc_attr( $m( '_wpap_recipe_servings' ) ); ?>" style="width:110px;" /></label>
                <label><?php esc_html_e( 'Prep (min)', 'wp-automator-pro' ); ?><br><input type="number" min="0" max="1440" name="_wpap_recipe_prep" value="<?php echo esc_attr( $m( '_wpap_recipe_prep' ) ); ?>" style="width:90px;" /></label>
                <label><?php esc_html_e( 'Cook (min)', 'wp-automator-pro' ); ?><br><input type="number" min="0" max="1440" name="_wpap_recipe_cook" value="<?php echo esc_attr( $m( '_wpap_recipe_cook' ) ); ?>" style="width:90px;" /></label>
                <label><?php esc_html_e( 'Total (min)', 'wp-automator-pro' ); ?><br><input type="number" min="0" max="2880" name="_wpap_recipe_total" value="<?php echo esc_attr( $m( '_wpap_recipe_total' ) ); ?>" style="width:90px;" /></label>
            </p>
            <p><label><?php esc_html_e( 'Ingredients (one per line)', 'wp-automator-pro' ); ?><br><textarea name="_wpap_recipe_ingredients" rows="5" style="width:100%;box-sizing:border-box;"><?php echo esc_textarea( $m( '_wpap_recipe_ingredients' ) ); ?></textarea></label></p>
            <p><label><?php esc_html_e( 'Steps (one per line)', 'wp-automator-pro' ); ?><br><textarea name="_wpap_recipe_steps" rows="6" style="width:100%;box-sizing:border-box;"><?php echo esc_textarea( $m( '_wpap_recipe_steps' ) ); ?></textarea></label></p>
            <p class="wpap-ed-note"><?php esc_html_e( 'The Viral Reader theme shows a recipe card and emits matching Recipe schema (JSON-LD). Total auto-fills from prep + cook if left blank.', 'wp-automator-pro' ); ?></p>
        </details>

        <div class="wpap-ed-controls">
            <p>
                <label for="wpap_ed_split"><strong><?php esc_html_e( 'Split into pages', 'wp-automator-pro' ); ?></strong></label>
                <select id="wpap_ed_split" name="wpap_ed_split">
                    <option value="0" <?php selected( $split, '0' ); ?>><?php esc_html_e( 'Off — single page', 'wp-automator-pro' ); ?></option>
                    <option value="smart" <?php selected( $split, 'smart' ); ?>><?php esc_html_e( 'Smart (long posts only)', 'wp-automator-pro' ); ?></option>
                    <option value="2" <?php selected( $split, '2' ); ?>><?php esc_html_e( '2 pages', 'wp-automator-pro' ); ?></option>
                    <option value="3" <?php selected( $split, '3' ); ?>><?php esc_html_e( '3 pages', 'wp-automator-pro' ); ?></option>
                </select>
                <span class="wpap-ed-note"><?php esc_html_e( 'Skipped if the post already has manual page breaks.', 'wp-automator-pro' ); ?></span>
            </p>
            <p><label><input type="checkbox" name="wpap_ed_autofill" value="1" <?php checked( $autofill_on ); ?> /> <?php esc_html_e( 'Auto-fill empty fields when I save', 'wp-automator-pro' ); ?></label></p>
            <p><label><input type="checkbox" name="wpap_ed_manage" value="1" <?php checked( $manage_on ); ?> /> <?php esc_html_e( 'Let WP Automator manage this post (SEO schema, related links, ad zones)', 'wp-automator-pro' ); ?></label></p>
        </div>
    </div>
    <?php
}

/* ── Save pipeline (Classic editor) ──
   Step 1 persists the meta-box $_POST fields; the derived work (recipe extract,
   auto-fill, SEO, image meta, smart-link, split/excerpt) is delegated to the
   meta-driven wpap_ed_apply_derived() so the Gutenberg REST path can reuse it. */
add_action( 'save_post_post', 'wpap_ed_save', 20, 3 );
function wpap_ed_save( $post_id, $post, $update ) {
    if ( empty( $_POST['wpap_ed_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wpap_ed_nonce'] ) ), 'wpap_ed_save' ) ) { return; }
    if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) { return; }
    if ( ! $post instanceof WP_Post || 'post' !== $post->post_type ) { return; }
    if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }

    /* 1. Persist the editable meta-box fields ($_POST → meta). */
    $text_fields = array( '_wpap_ed_seo_title', '_wpap_ed_img_alt', '_wpap_ed_img_title' );
    foreach ( $text_fields as $key ) {
        if ( isset( $_POST[ $key ] ) ) { update_post_meta( $post_id, $key, sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) ); }
    }
    $area_fields = array( '_wpap_ed_meta_desc', '_wpap_ed_img_caption', '_wpap_ed_img_desc' );
    foreach ( $area_fields as $key ) {
        if ( isset( $_POST[ $key ] ) ) { update_post_meta( $post_id, $key, sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) ); }
    }
    $split_mode = isset( $_POST['wpap_ed_split'] ) ? sanitize_text_field( wp_unslash( $_POST['wpap_ed_split'] ) ) : '0';
    update_post_meta( $post_id, '_wpap_ed_split', $split_mode );
    update_post_meta( $post_id, '_wpap_ed_autofill', isset( $_POST['wpap_ed_autofill'] ) ? '1' : '0' );
    update_post_meta( $post_id, '_wpap_ed_manage', isset( $_POST['wpap_ed_manage'] ) ? '1' : '0' );

    /* Recipe details (feeds the theme's Recipe card + JSON-LD). */
    update_post_meta( $post_id, '_wpap_recipe_on', isset( $_POST['wpap_recipe_on'] ) ? '1' : '' );
    if ( isset( $_POST['_wpap_recipe_servings'] ) ) { update_post_meta( $post_id, '_wpap_recipe_servings', sanitize_text_field( wp_unslash( $_POST['_wpap_recipe_servings'] ) ) ); }
    foreach ( array( '_wpap_recipe_prep', '_wpap_recipe_cook', '_wpap_recipe_total' ) as $rk ) {
        if ( isset( $_POST[ $rk ] ) ) {
            $rv = is_scalar( $_POST[ $rk ] ) ? (int) $_POST[ $rk ] : 0;   /* shape-guard: (int) on an array warns + stores 1 */
            update_post_meta( $post_id, $rk, max( 0, min( 2880, $rv ) ) );
        }
    }
    foreach ( array( '_wpap_recipe_ingredients', '_wpap_recipe_steps' ) as $rk ) {
        if ( isset( $_POST[ $rk ] ) ) { update_post_meta( $post_id, $rk, sanitize_textarea_field( wp_unslash( $_POST[ $rk ] ) ) ); }
    }

    /* 2. Derived pipeline (meta-driven; shared with the Gutenberg REST path). */
    wpap_ed_apply_derived( $post_id );
}

/* Shared derived-content pipeline. Reads every input from post meta + the post
   object (NOT $_POST), so it runs identically from the Classic save above and
   from the Gutenberg REST save (rest_after_insert_post) — that hook fires AFTER
   core persists the REST meta, so the freshly-saved field values are visible.
   Does recipe auto-extract, empty-field auto-fill, SEO sync, featured-image meta,
   the managed smart-link, and split/excerpt. A static $busy guard plus removing
   our own save_post hook around the internal wp_update_post prevent re-entry. */
function wpap_ed_apply_derived( $post_id ) {
    $post = get_post( $post_id );
    if ( ! $post instanceof WP_Post || 'post' !== $post->post_type ) { return; }
    /* Skip non-content states so a REST save of an auto-draft (or a trashed post)
       can't set a smart_link / auto-fill prematurely. */
    if ( in_array( $post->post_status, array( 'auto-draft', 'trash', 'inherit' ), true ) ) { return; }

    static $busy = false;
    if ( $busy ) { return; }
    $busy = true;

    $autofill   = ( '1' === (string) get_post_meta( $post_id, '_wpap_ed_autofill', true ) ) ? 1 : 0;
    $manage     = ( '1' === (string) get_post_meta( $post_id, '_wpap_ed_manage', true ) ) ? 1 : 0;
    $split_mode = (string) get_post_meta( $post_id, '_wpap_ed_split', true );
    if ( '' === $split_mode ) { $split_mode = '0'; }

    $content = (string) $post->post_content;
    $title   = (string) $post->post_title;

    /* Auto-extract ingredients/steps from the post body — only when this is a
       recipe, auto-fill is on, and the field was left empty. Provenance flags let
       a later manual edit stick. */
    if ( $autofill && '1' === (string) get_post_meta( $post_id, '_wpap_recipe_on', true ) ) {
        $need_ing  = ( '' === trim( (string) get_post_meta( $post_id, '_wpap_recipe_ingredients', true ) ) );
        $need_step = ( '' === trim( (string) get_post_meta( $post_id, '_wpap_recipe_steps', true ) ) );
        if ( $need_ing || $need_step ) {
            $rx = wpap_ed_extract_recipe( $content );
            if ( $need_ing && ! empty( $rx['ingredients'] ) ) {
                update_post_meta( $post_id, '_wpap_recipe_ingredients', sanitize_textarea_field( implode( "\n", $rx['ingredients'] ) ) );
                update_post_meta( $post_id, '_wpap_recipe_auto_ingredients', 1 );
            }
            if ( $need_step && ! empty( $rx['steps'] ) ) {
                update_post_meta( $post_id, '_wpap_recipe_steps', sanitize_textarea_field( implode( "\n", $rx['steps'] ) ) );
                update_post_meta( $post_id, '_wpap_recipe_auto_steps', 1 );
            }
        }
    }

    /* 2. Auto-fill EMPTY fields only. */
    if ( $autofill ) {
        if ( '' === trim( (string) get_post_meta( $post_id, '_wpap_ed_seo_title', true ) ) && '' !== $title ) {
            update_post_meta( $post_id, '_wpap_ed_seo_title', wpap_ed_clip( $title, 60 ) );
            update_post_meta( $post_id, '_wpap_ed_auto_seo_title', '1' );
        }
        if ( '' === trim( (string) get_post_meta( $post_id, '_wpap_ed_meta_desc', true ) ) ) {
            $md = wpap_make_excerpt( $content, 155 );
            if ( '' !== $md ) {
                update_post_meta( $post_id, '_wpap_ed_meta_desc', $md );
                update_post_meta( $post_id, '_wpap_ed_auto_meta_desc', '1' );
            }
        }
        if ( '' === trim( (string) get_post_meta( $post_id, '_wpap_ed_img_alt', true ) ) && '' !== $title ) {
            update_post_meta( $post_id, '_wpap_ed_img_alt', $title );
        }
        if ( '' === trim( (string) get_post_meta( $post_id, '_wpap_ed_img_title', true ) ) && '' !== $title ) {
            update_post_meta( $post_id, '_wpap_ed_img_title', $title );
        }
        wpap_ed_maybe_assign_terms( $post_id, $title, $content );
    }

    /* 3. Sync SEO to Yoast / Rank Math. */
    $seo_title = (string) get_post_meta( $post_id, '_wpap_ed_seo_title', true );
    $meta_desc = (string) get_post_meta( $post_id, '_wpap_ed_meta_desc', true );
    if ( '' !== $seo_title || '' !== $meta_desc ) {
        wpap_set_seo_meta( $post_id, $meta_desc, $seo_title );
    }

    /* 4. Featured-image metadata. */
    wpap_ed_apply_image_meta( $post_id );

    /* 5. Plugin-managed flag (enables our SEO JSON-LD + related links + ads). */
    if ( $manage ) {
        $perma = wpap_public_permalink( $post_id );
        if ( $perma ) { update_post_meta( $post_id, '_wpap_smart_link', $perma ); }
    } else {
        delete_post_meta( $post_id, '_wpap_smart_link' );
    }

    /* 6. Content pagination + excerpt (single wp_update_post, self-hook removed). */
    $new_content = $content;
    $parts = wpap_ed_resolve_split_parts( $split_mode, $content );
    if ( $parts >= 2 && false === strpos( $content, '<!--nextpage-->' ) ) {
        $split = wpap_split_content_into_parts( $content, $parts );
        if ( is_string( $split ) && '' !== $split ) { $new_content = $split; }
    }
    $cur_excerpt = (string) $post->post_excerpt;
    $new_excerpt = null;
    if ( $autofill && '' === trim( $cur_excerpt ) ) {
        $auto_ex = wpap_make_excerpt( $content, 200 );
        if ( '' !== $auto_ex ) { $new_excerpt = $auto_ex; }
    }

    $changes = array();
    if ( $new_content !== $content ) { $changes['post_content'] = $new_content; }
    if ( null !== $new_excerpt && $new_excerpt !== $cur_excerpt ) { $changes['post_excerpt'] = $new_excerpt; }
    if ( ! empty( $changes ) ) {
        $changes['ID'] = $post_id;
        remove_action( 'save_post_post', 'wpap_ed_save', 20 );
        wp_update_post( $changes );
        add_action( 'save_post_post', 'wpap_ed_save', 20, 3 );
    }

    $busy = false;
}

/* Gutenberg (block editor) save path. Core writes the REST meta AFTER save_post,
   so the derived pipeline must run on this later hook to see the fresh field
   values (reading them in save_post for a REST save would miss them). A Classic
   post.php save is not a REST request (this hook never fires for it); a REST save
   carries no meta-box nonce (wpap_ed_save returns) — so the two paths are
   mutually exclusive and wpap_ed_apply_derived never double-runs per request. */
add_action( 'rest_after_insert_post', function ( $post ) {
    if ( $post instanceof WP_Post && 'post' === $post->post_type ) {
        wpap_ed_apply_derived( $post->ID );
    }
}, 20 );

/* ── Editor-only assets (separate enqueuer; does NOT relax the dashboard guard) ── */
add_action( 'admin_enqueue_scripts', 'wpap_ed_enqueue' );
function wpap_ed_enqueue( $hook ) {
    if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) { return; }
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || 'post' !== $screen->post_type ) { return; }

    wp_enqueue_style( 'wpap-editor-tools', plugins_url( 'assets/editor-tools.css', __FILE__ ), array(), WPAP_VERSION );
    wp_enqueue_script( 'wpap-editor-tools', plugins_url( 'assets/editor-tools.js', __FILE__ ), array( 'jquery' ), WPAP_VERSION, true );
    wp_localize_script( 'wpap-editor-tools', 'wpapEd', array(
        'minWords' => 150,
        'i18n'     => array(
            'ready'      => __( 'ready', 'wp-automator-pro' ),
            'title'      => __( 'Title', 'wp-automator-pro' ),
            'body'       => __( 'Enough content', 'wp-automator-pro' ),
            'metaDesc'   => __( 'Meta description', 'wp-automator-pro' ),
            'category'   => __( 'Category', 'wp-automator-pro' ),
            'tags'       => __( 'Tags', 'wp-automator-pro' ),
            'featured'   => __( 'Featured image', 'wp-automator-pro' ),
            'imageAlt'   => __( 'Image alt text', 'wp-automator-pro' ),
            'filled'     => __( 'Filled empty fields from the title and content.', 'wp-automator-pro' ),
        ),
    ) );
}

/* ── Block-editor (Gutenberg) assets ── Registers the PluginDocumentSettingPanel.
   Post type `post` only; the panel binds to the REST-registered meta above. */
add_action( 'enqueue_block_editor_assets', 'wpap_ed_block_enqueue' );
function wpap_ed_block_enqueue() {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || 'post' !== $screen->post_type ) { return; }

    wp_enqueue_style( 'wpap-editor-tools', plugins_url( 'assets/editor-tools.css', __FILE__ ), array(), WPAP_VERSION );
    wp_enqueue_script(
        'wpap-editor-gutenberg',
        plugins_url( 'assets/editor-gutenberg.js', __FILE__ ),
        array( 'wp-plugins', 'wp-editor', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n', 'wp-core-data' ),
        WPAP_VERSION,
        true
    );
    if ( function_exists( 'wp_set_script_translations' ) ) {
        wp_set_script_translations( 'wpap-editor-gutenberg', 'wp-automator-pro' );
    }
}
