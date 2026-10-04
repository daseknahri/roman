<?php
/**
 * Settings export/import + admin dashboard health widget
 *
 * Extracted verbatim from wp-automator-pro.php (single-file → modular).
 * Load order is fixed by the main file; every hook self-registers here.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function wpap_handle_export_settings() {
    if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Unauthorized', '', array( 'response' => 403 ) ); }
    check_admin_referer( 'wpap_export_settings' );

    $bundle = array(
        'plugin'   => 'wp-automator-pro',
        'version'  => defined( 'WPAP_VERSION' ) ? WPAP_VERSION : '',
        'exported' => gmdate( 'c' ),
        'options'  => array(
            'wpap_ads_inject'   => get_option( 'wpap_ads_inject', array() ),
            'wpap_content_opts' => get_option( 'wpap_content_opts', array() ),
            'wpap_indexnow'     => get_option( 'wpap_indexnow', array() ),
            'wpap_ads_txt'      => (string) get_option( 'wpap_ads_txt', '' ),
            'wpap_automation'   => get_option( 'wpap_automation', array() ),
            'wpap_utm'          => get_option( 'wpap_utm', array() ),
        ),
        /* Secrets (API keys in wpap_settings, license) are intentionally omitted. */
    );

    $json  = wp_json_encode( $bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    $fname = 'wp-automator-settings-' . gmdate( 'Ymd-His' ) . '.json';

    nocache_headers();
    header( 'Content-Type: application/json; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="' . $fname . '"' );
    header( 'Content-Length: ' . strlen( (string) $json ) );
    header( 'X-Content-Type-Options: nosniff' );
    header( 'X-Robots-Tag: noindex, nofollow' );   /* keep a settings dump out of any index if ever linked */
    echo $json;   // JSON download, not rendered as HTML
    exit;
}

add_action( 'admin_post_wpap_import_settings', 'wpap_handle_import_settings' );
function wpap_handle_import_settings() {
    if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Unauthorized', '', array( 'response' => 403 ) ); }
    check_admin_referer( 'wpap_import_settings' );

    $redirect = admin_url( 'admin.php?page=wp-automator-pro-settings' );
    $fail     = function ( $code ) use ( $redirect ) {
        wp_safe_redirect( add_query_arg( 'wpap_import_error', $code, $redirect ) );
        exit;
    };

    if ( empty( $_FILES['wpap_import_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['wpap_import_file']['tmp_name'] ) ) { $fail( 'nofile' ); }
    $size = (int) ( $_FILES['wpap_import_file']['size'] ?? 0 );
    if ( $size <= 0 || $size > 512 * 1024 ) { $fail( 'size' ); }   /* cap 512 KB */

    $raw  = file_get_contents( $_FILES['wpap_import_file']['tmp_name'] );
    $data = json_decode( (string) $raw, true );
    if ( ! is_array( $data ) || empty( $data['options'] ) || ! is_array( $data['options'] ) ) { $fail( 'parse' ); }
    $opts = $data['options'];

    if ( isset( $opts['wpap_ads_inject'] ) && is_array( $opts['wpap_ads_inject'] ) ) {
        update_option( 'wpap_ads_inject', wpap_sanitize_ads_import( $opts['wpap_ads_inject'] ), true );   /* autoload=yes: front-end hot-path option */
    }
    if ( isset( $opts['wpap_content_opts'] ) && is_array( $opts['wpap_content_opts'] ) ) {
        $c = $opts['wpap_content_opts'];
        update_option( 'wpap_content_opts', array(
            'min_words'        => max( 0, min( 5000, (int) ( $c['min_words'] ?? 0 ) ) ),
            'skip_dupe_titles' => ! empty( $c['skip_dupe_titles'] ) ? 1 : 0,
            'disable_comments' => ! empty( $c['disable_comments'] ) ? 1 : 0,
            'clean_media'      => ! empty( $c['clean_media'] ) ? 1 : 0,
            'llms_txt'         => ! empty( $c['llms_txt'] ) ? 1 : 0,
            'fb_comment_template' => mb_substr( sanitize_textarea_field( (string) ( $c['fb_comment_template'] ?? '' ) ), 0, 2000 ),
            'fb_domain_verify' => preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $c['fb_domain_verify'] ?? '' ) ),
            'fb_app_id'        => preg_replace( '/[^0-9]/', '', (string) ( $c['fb_app_id'] ?? '' ) ),
        ), false );
    }
    if ( isset( $opts['wpap_indexnow'] ) && is_array( $opts['wpap_indexnow'] ) ) {
        update_option( 'wpap_indexnow', array( 'enabled' => ! empty( $opts['wpap_indexnow']['enabled'] ) ? 1 : 0 ), false );
    }
    if ( isset( $opts['wpap_ads_txt'] ) && is_scalar( $opts['wpap_ads_txt'] ) ) {
        update_option( 'wpap_ads_txt', sanitize_textarea_field( (string) $opts['wpap_ads_txt'] ), false );
    }
    if ( isset( $opts['wpap_automation'] ) && is_array( $opts['wpap_automation'] ) ) {
        $a = $opts['wpap_automation'];
        update_option( 'wpap_automation', array(
            'enabled'          => ! empty( $a['enabled'] ) ? 1 : 0,
            'sheet_url'        => esc_url_raw( (string) ( $a['sheet_url'] ?? '' ) ),
            'per_day'          => max( 0, min( 500, (int) ( $a['per_day'] ?? 12 ) ) ),
            'per_run'          => max( 1, min( 50, (int) ( $a['per_run'] ?? 3 ) ) ),
            'default_category' => sanitize_text_field( (string) ( $a['default_category'] ?? '' ) ),
            'schedule_window'  => max( 0, min( 168, (float) ( $a['schedule_window'] ?? 0 ) ) ),
            'alert_email'      => ! empty( $a['alert_email'] ) ? 1 : 0,
        ), false );
    }
    if ( isset( $opts['wpap_utm'] ) && is_array( $opts['wpap_utm'] ) ) {
        $u = $opts['wpap_utm'];
        update_option( 'wpap_utm', array(
            'enabled'  => ! empty( $u['enabled'] ) ? 1 : 0,
            'source'   => sanitize_text_field( (string) ( $u['source'] ?? 'facebook' ) ),
            'medium'   => sanitize_text_field( (string) ( $u['medium'] ?? 'social' ) ),
            'campaign' => sanitize_text_field( (string) ( $u['campaign'] ?? '{slug}' ) ),
            'groups'   => sanitize_textarea_field( (string) ( $u['groups'] ?? '' ) ),
        ), false );
    }

    wp_safe_redirect( add_query_arg( 'wpap_imported', '1', $redirect ) );
    exit;
}

/* Re-sanitize an imported ad-placement structure into the storable shape
   (mirrors the settings-save + normalizer). Ad code stays raw — same trust
   model as the settings form; the file is admin-uploaded and nonce-guarded. */
function wpap_sanitize_ads_import( $a ) {
    if ( ! is_array( $a ) ) { $a = array(); }
    $slots_in = ( isset( $a['slots'] ) && is_array( $a['slots'] ) ) ? $a['slots'] : array();

    /* Back-compat: map the old single-unit schema onto the new slots (mirrors
       wpap_get_ads) so importing a legacy config that was never re-saved on the
       new settings page doesn't silently drop the in-content / repeat ad. */
    if ( empty( $slots_in ) && ! empty( $a['incontent'] ) ) {
        $slots_in['incontent'] = array( 'on' => 1, 'code' => (string) $a['incontent'], 'after' => (int) ( $a['first_after'] ?? 2 ) );
        if ( ! empty( $a['every'] ) ) {
            $slots_in['repeat'] = array( 'on' => 1, 'code' => (string) $a['incontent'], 'every' => (int) $a['every'], 'max' => (int) ( $a['max'] ?? 3 ) );
        }
    }

    $gs = function ( $slot, $key, $default ) use ( $slots_in ) {
        return isset( $slots_in[ $slot ][ $key ] ) ? $slots_in[ $slot ][ $key ] : $default;
    };

    $custom = array();
    if ( isset( $a['custom'] ) && is_array( $a['custom'] ) ) {
        foreach ( $a['custom'] as $c ) {
            if ( ! is_array( $c ) ) { continue; }
            $code = wpap_cap_ad_code( trim( (string) ( $c['code'] ?? '' ) ) );
            if ( '' === $code ) { continue; }
            $pos = isset( $c['pos'] ) ? (string) $c['pos'] : 'after';
            if ( ! in_array( $pos, array( 'after', 'top', 'before_related' ), true ) ) { $pos = 'after'; }
            $custom[] = array( 'pos' => $pos, 'after' => max( 1, min( 50, (int) ( $c['after'] ?? 2 ) ) ), 'code' => $code );
            if ( count( $custom ) >= 10 ) { break; }
        }
    }

    return array(
        'enabled'   => ! empty( $a['enabled'] ) ? 1 : 0,
        'scope_all' => ! isset( $a['scope_all'] ) ? 1 : ( ! empty( $a['scope_all'] ) ? 1 : 0 ),
        'auto_code' => wpap_cap_ad_code( trim( (string) ( $a['auto_code'] ?? '' ) ) ),
        'min_gap'   => ! isset( $a['min_gap'] ) ? 1 : max( 0, min( 20, (int) $a['min_gap'] ) ),
        'max_ads'   => max( 0, min( 20, (int) ( $a['max_ads'] ?? 0 ) ) ),
        'label'     => ! empty( $a['label'] ) ? 1 : 0,
        'zones'     => array(
            'header'  => array( 'on' => ! empty( $a['zones']['header']['on'] ) ? 1 : 0,  'code' => isset( $a['zones']['header']['code'] ) ? wpap_cap_ad_code( trim( (string) $a['zones']['header']['code'] ) ) : '' ),
            'sidebar' => array( 'on' => ! empty( $a['zones']['sidebar']['on'] ) ? 1 : 0, 'code' => isset( $a['zones']['sidebar']['code'] ) ? wpap_cap_ad_code( trim( (string) $a['zones']['sidebar']['code'] ) ) : '' ),
            'footer'  => array( 'on' => ! empty( $a['zones']['footer']['on'] ) ? 1 : 0,  'code' => isset( $a['zones']['footer']['code'] ) ? wpap_cap_ad_code( trim( (string) $a['zones']['footer']['code'] ) ) : '' ),
        ),
        'custom'    => $custom,
        'slots'     => array(
            'top'       => array( 'on' => ! empty( $gs( 'top', 'on', 0 ) ) ? 1 : 0, 'code' => wpap_cap_ad_code( trim( (string) $gs( 'top', 'code', '' ) ) ) ),
            'incontent' => array( 'on' => ! empty( $gs( 'incontent', 'on', 0 ) ) ? 1 : 0, 'code' => wpap_cap_ad_code( trim( (string) $gs( 'incontent', 'code', '' ) ) ), 'after' => max( 1, min( 50, (int) $gs( 'incontent', 'after', 2 ) ) ) ),
            'repeat'    => array( 'on' => ! empty( $gs( 'repeat', 'on', 0 ) ) ? 1 : 0, 'code' => wpap_cap_ad_code( trim( (string) $gs( 'repeat', 'code', '' ) ) ), 'every' => max( 1, min( 50, (int) $gs( 'repeat', 'every', 4 ) ) ), 'max' => max( 1, min( 10, (int) $gs( 'repeat', 'max', 3 ) ) ) ),
            'bottom'    => array( 'on' => ! empty( $gs( 'bottom', 'on', 0 ) ) ? 1 : 0, 'code' => wpap_cap_ad_code( trim( (string) $gs( 'bottom', 'code', '' ) ) ) ),
        ),
    );
}

/* ════════════════════════════════════════════
   5c. WP-ADMIN DASHBOARD HEALTH WIDGET
   A native dashboard widget on the wp-admin home so publishing health
   (WP-Cron, automation, post counts) is visible at a glance.
════════════════════════════════════════════ */
add_action( 'wp_dashboard_setup', 'wpap_register_dashboard_widget' );
function wpap_register_dashboard_widget() {
    if ( ! current_user_can( 'manage_options' ) ) { return; }
    wp_add_dashboard_widget( 'wpap_health_widget', 'WP Automator — Health', 'wpap_render_dashboard_widget' );
}

function wpap_render_dashboard_widget() {
    if ( ! current_user_can( 'manage_options' ) ) { return; }

    $auto     = wpap_get_automation();
    $status   = get_option( 'wpap_automation_status', array() );
    if ( ! is_array( $status ) ) { $status = array(); }
    $counts   = wp_count_posts( 'post' );
    $next     = wp_next_scheduled( 'wpap_automation_cron' );
    $cron_off = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

    $row = function ( $label, $value, $color = '' ) {
        $style = $color ? ' style="color:' . esc_attr( $color ) . ';"' : '';
        echo '<li style="display:flex;justify-content:space-between;gap:12px;padding:7px 0;border-bottom:1px solid #f0f0f1;">'
            . '<span>' . esc_html( $label ) . '</span>'
            . '<strong' . $style . '>' . wp_kses_post( $value ) . '</strong></li>';
    };

    echo '<ul style="margin:0;">';
    $row( 'WP-Cron', $cron_off ? 'Disabled &#9888;' : 'Active', $cron_off ? '#b32d2e' : '#1a7f37' );
    $row( 'Published', number_format_i18n( isset( $counts->publish ) ? (int) $counts->publish : 0 ) );
    $row( 'Scheduled', number_format_i18n( isset( $counts->future ) ? (int) $counts->future : 0 ) );
    $row( 'Drafts', number_format_i18n( isset( $counts->draft ) ? (int) $counts->draft : 0 ) );

    if ( ! empty( $auto['enabled'] ) ) {
        $row( 'Sheet automation', 'On', '#1a7f37' );
        $row( 'Next run', $next ? esc_html( human_time_diff( time(), $next ) . ' from now' ) : '&mdash;' );
        if ( ! empty( $status['last_run'] ) ) {
            $row( 'Last run', esc_html( (string) $status['last_run'] ) );
        }
    } else {
        $row( 'Sheet automation', 'Off', '#8a8a8a' );
    }
    echo '</ul>';

    if ( $cron_off && ! empty( $auto['enabled'] ) ) {
        echo '<p style="margin:10px 0 0;color:#b32d2e;">WP-Cron is off but auto-publish is on — scheduled &amp; automated posts won\'t fire until a real server cron calls <code>wp-cron.php</code>.</p>';
    }

    echo '<p style="margin:12px 0 0;">'
        . '<a href="' . esc_url( admin_url( 'admin.php?page=wp-automator-pro' ) ) . '">Open dashboard</a> &middot; '
        . '<a href="' . esc_url( admin_url( 'admin.php?page=wp-automator-pro-settings' ) ) . '">Settings</a></p>';
}

/* ════════════════════════════════════════════
   AUTHOR TOOLS — in-editor authoring assistant
   ────────────────────────────────────────────
   A self-contained, additive module: one meta box on the post editor plus a
   save_post pipeline that auto-fills EMPTY fields only (never clobbering your
   text), applies featured-image metadata, syncs SEO to Yoast / Rank Math, can
   paginate long posts, and (opt-in) marks the post so the plugin's own SEO
   JSON-LD + related-links + ads apply to it. It reuses existing helpers
   (wpap_make_excerpt, wpap_set_seo_meta, wpap_split_content_into_parts) and
   NEVER touches the AI generation pipeline. Works in Classic and Block editors
   (the live checklist / auto-fill buttons enhance the Classic editor; the Block
   editor still gets full server-side auto-fill on save).
════════════════════════════════════════════ */

