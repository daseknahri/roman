<?php
/**
 * Plugin Name: Automation Hamri
 * Description: An advanced AI-powered bulk content generator for WordPress that automates SEO articles, internal linking, and multi-engine image sourcing. Optimized for high-traffic niches.
 * Version:     9.43.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author:      Oussama Hamri
 * License:     GPL-2.0+
 * Text Domain: wp-automator-pro
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

/* Load translations for the (self-hosted) plugin. No-op until a /languages MO is
   shipped, but keeps the domain consistent with every __()/esc_html__() call. */
add_action( 'init', function () {
    load_plugin_textdomain( 'wp-automator-pro', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

/* ════════════════════════════════════════════
   LICENSE PROTECTION SYSTEM
   Remote verification against GitHub Gist JSON.
   All plugin menus are locked until a valid
   Username + License Key pair is confirmed.
════════════════════════════════════════════ */

define( 'WPAP_LICENSE_URL',
    'https://gist.githubusercontent.com/hamrioussama19-jpg/9d6bbf3b426c750cd0f481814fbeed37/raw/Automation%2520Hamri%2520Licenses'
);
define( 'WPAP_LICENSE_OPTION',  'wpap_license_data' );   /* stores {user, key, status} */
define( 'WPAP_LICENSE_CACHE',   'wpap_license_cache' );  /* transient: remote JSON     */

/* ── Helper: is the plugin currently activated? ── */
function wpap_is_licensed(): bool {
    /* Licensing removed — the GitHub-Gist access-key system is disabled and the
       plugin is fully unlocked. Always licensed. */
    return true;
}

/* ── Helper: fetch & cache remote license list (60-min transient) ── */
function wpap_fetch_license_list(): array {
    $cached = get_transient( WPAP_LICENSE_CACHE );
    if ( is_array( $cached ) ) {
        return $cached;
    }
    $bust_url = WPAP_LICENSE_URL . '?t=' . time();
    $response = wp_remote_get( $bust_url, array(
        'timeout'   => 15,
        'sslverify' => true,
    ) );
    if ( is_wp_error( $response ) ) {
        return array();
    }
    $code = wp_remote_retrieve_response_code( $response );
    if ( $code !== 200 ) {
        return array();
    }
    $body = wp_remote_retrieve_body( $response );
    $json = json_decode( $body, true );
    if ( ! is_array( $json ) ) {
        return array();
    }
    set_transient( WPAP_LICENSE_CACHE, $json, HOUR_IN_SECONDS );
    return $json;
}

/* ── Helper: verify a username+key pair against remote JSON ── */
/**
 * Verify a username + key pair against the remote JSON.
 *
 * Returns an array with:
 *   'valid'  => bool   -- credentials matched
 *   'error'  => string -- human-readable reason when not valid (empty on success)
 *   'domain' => string -- the domain value stored in the remote JSON entry
 */
function wpap_verify_license( string $username, string $key ): array {
    $list = wpap_fetch_license_list();
    if ( empty( $list ) ) {
        return array( 'valid' => false, 'error' => 'Could not reach the license server. Please try again.', 'domain' => '' );
    }

    foreach ( $list as $entry ) {
        if ( ! is_array( $entry ) ) {
            continue;
        }
        $remote_user   = trim( (string) ( $entry['user']    ?? $entry['username'] ?? '' ) );
        $remote_key    = trim( (string) ( $entry['key']     ?? $entry['license']  ?? '' ) );
        $remote_domain = trim( (string) ( $entry['domain']  ?? '' ) );

        if (
            strtolower( $remote_user ) !== strtolower( trim( $username ) ) ||
            $remote_key                !== trim( $key )
        ) {
            continue;
        }

        /* Credentials matched -- now check domain lock */
        $site_domain = (string) parse_url( get_site_url(), PHP_URL_HOST );

        if ( $remote_domain === '' ) {
            return array( 'valid' => true, 'error' => '', 'domain' => $remote_domain );
        }

        if ( $remote_domain === $site_domain ) {
            return array( 'valid' => true, 'error' => '', 'domain' => $remote_domain );
        }

        return array(
            'valid'  => false,
            'error'  => 'This license is already locked to another website. Please contact Oussama Hamri.',
            'domain' => $remote_domain,
        );
    }

    return array( 'valid' => false, 'error' => 'Invalid Username or License Key. Please check your credentials and try again.', 'domain' => '' );
}

/* ── Admin menu: show only Activate License when unlicensed ── */
add_action( 'admin_menu', 'wpap_license_menu', 5 );   /* priority 5 — runs before main menu */
function wpap_license_menu(): void {
    if ( wpap_is_licensed() ) {
        return;   /* licensed: let the real menu register normally */
    }
    /* Unlicensed: register a locked top-level menu with only the activation page */
    add_menu_page(
        'Automation Hamri — Activate',
        'Automation Hamri',
        'manage_options',
        'wpap-activate',
        'wpap_render_activation_page',
        'dashicons-lock',
        3
    );
}

/* ── Activation page renderer ── */
function wpap_render_activation_page(): void {
    $error   = '';
    $success = '';

    /* Show revocation notice if the background check fired */
    $revoke_msg = get_transient( 'wpap_revoke_notice' );
    if ( $revoke_msg ) {
        $error = $revoke_msg;
        delete_transient( 'wpap_revoke_notice' );
    }

    if ( isset( $_POST['wpap_activate_license'] ) && check_admin_referer( 'wpap_activate_nonce' ) ) {
        $username = sanitize_text_field( wp_unslash( $_POST['wpap_license_user'] ?? '' ) );
        $key      = sanitize_text_field( wp_unslash( $_POST['wpap_license_key']  ?? '' ) );

        if ( empty( $username ) || empty( $key ) ) {
            $error = 'Please enter both your Username and License Key.';
        } else {
            /* Clear cache to force a fresh remote fetch on activation */
            delete_transient( WPAP_LICENSE_CACHE );
            $license_result = wpap_verify_license( $username, $key );
            if ( $license_result['valid'] ) {
                update_option( WPAP_LICENSE_OPTION, array(
                    'user'   => $username,
                    'key'    => $key,
                    'status' => 'active',
                ), false );   /* autoload = no: the license key is a secret — keep it out of the all-options cache, like every other secret option */
                $success = 'License activated successfully! Redirecting…';
                echo '<script>setTimeout(function(){ window.location.href="' . esc_url( admin_url( 'admin.php?page=wp-automator-pro' ) ) . '"; }, 1500);</script>';
            } else {
                $error = $license_result['error'];
            }
        }
    }
    ?>
    <style>
        .wpap-activate-wrap {
            max-width: 480px;
            margin: 80px auto;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 40px 48px;
            box-shadow: 0 4px 24px rgba(0,0,0,.08);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        .wpap-activate-wrap h1 {
            font-size: 22px;
            margin: 0 0 8px;
            color: #1a1a2e;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .wpap-activate-wrap p.sub {
            color: #666;
            margin: 0 0 28px;
            font-size: 14px;
        }
        .wpap-activate-wrap label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 5px;
            color: #333;
        }
        .wpap-activate-wrap input[type="text"],
        .wpap-activate-wrap input[type="password"] {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 14px;
            margin-bottom: 18px;
            box-sizing: border-box;
        }
        .wpap-activate-wrap input:focus {
            border-color: #6366f1;
            outline: none;
            box-shadow: 0 0 0 3px rgba(99,102,241,.15);
        }
        .wpap-activate-wrap .btn-activate {
            width: 100%;
            background: #6366f1;
            color: #fff;
            border: none;
            border-radius: 6px;
            padding: 12px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: background .2s;
        }
        .wpap-activate-wrap .btn-activate:hover { background: #4f46e5; }
        .wpap-activate-notice {
            border-radius: 6px;
            padding: 10px 14px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .wpap-notice-error   { background: #fef2f2; border-left: 4px solid #ef4444; color: #b91c1c; }
        .wpap-notice-success { background: #f0fdf4; border-left: 4px solid #22c55e; color: #15803d; }
        .wpap-lock-icon { font-size: 26px; }
        /* Password toggle */
        .wpap-key-wrap { position: relative; margin-bottom: 18px; }
        .wpap-key-wrap input { margin-bottom: 0 !important; padding-right: 44px !important; }
        .wpap-eye-btn {
            position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
            background: none; border: none; cursor: pointer; padding: 4px;
            color: #888; line-height: 1;
        }
        .wpap-eye-btn:hover { color: #6366f1; }
        /* Support link */
        .wpap-support-line {
            margin-top: 18px; text-align: center;
            font-size: 13px; color: #666;
        }
        .wpap-support-line a { color: #6366f1; text-decoration: none; font-weight: 600; }
        .wpap-support-line a:hover { text-decoration: underline; }
    </style>
    <div class="wpap-activate-wrap">
        <h1><span class="wpap-lock-icon">🔐</span> Automation Hamri</h1>
        <p class="sub">Enter your license credentials to activate the plugin.</p>
        <?php if ( $error )   : ?><div class="wpap-activate-notice wpap-notice-error"><?php echo esc_html( $error ); ?></div><?php endif; ?>
        <?php if ( $success ) : ?><div class="wpap-activate-notice wpap-notice-success"><?php echo esc_html( $success ); ?></div><?php endif; ?>
        <form method="post">
            <?php wp_nonce_field( 'wpap_activate_nonce' ); ?>
            <label for="wpap_license_user">Username</label>
            <input type="text" id="wpap_license_user" name="wpap_license_user"
                   value="<?php echo esc_attr( wp_unslash( $_POST['wpap_license_user'] ?? '' ) ); ?>"
                   placeholder="Your license username" autocomplete="username" />
            <label for="wpap_license_key">License Key</label>
            <div class="wpap-key-wrap">
                <input type="password" id="wpap_license_key" name="wpap_license_key"
                       placeholder="Your license key" autocomplete="off" />
                <button type="button" class="wpap-eye-btn" id="wpap_toggle_key" title="Show / Hide">
                    <svg id="wpap-eye-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                    </svg>
                </button>
            </div>
            <button type="submit" name="wpap_activate_license" class="btn-activate">
                Activate License
            </button>
        </form>
        <p class="wpap-support-line">Need help? Contact Oussama Hamri &mdash; <a href="https://wa.me/+212637122491" target="_blank" rel="noopener">Click Here</a></p>
    </div>
    <script>
    (function(){
        var btn   = document.getElementById('wpap_toggle_key');
        var field = document.getElementById('wpap_license_key');
        var icon  = document.getElementById('wpap-eye-icon');
        var eyeOpen  = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
        var eyeOff   = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/>';
        if ( btn && field ) {
            btn.addEventListener('click', function(){
                if ( field.type === 'password' ) {
                    field.type = 'text';
                    icon.innerHTML = eyeOff;
                } else {
                    field.type = 'password';
                    icon.innerHTML = eyeOpen;
                }
            });
        }
    })();
    </script>
    <?php
}

/* ── Block all real plugin AJAX actions when unlicensed ── */
add_action( 'wp_ajax_wpap_process_title', 'wpap_ajax_license_gate', 1 );
add_action( 'wp_ajax_wpap_get_posts',     'wpap_ajax_license_gate', 1 );
add_action( 'wp_ajax_wpap_upload_fallback','wpap_ajax_license_gate', 1 );
add_action( 'wp_ajax_wpap_bulk_import_distribution', 'wpap_ajax_license_gate', 1 );
add_action( 'wp_ajax_wpap_bulk_import_remote_images', 'wpap_ajax_license_gate', 1 );
add_action( 'wp_ajax_wpap_export_distribution_json', 'wpap_ajax_license_gate', 1 );
add_action( 'wp_ajax_wpap_proxy_image',   'wpap_ajax_license_gate', 1 );
add_action( 'wp_ajax_wpap_bulk_publish_posts', 'wpap_ajax_license_gate', 1 );
add_action( 'wp_ajax_wpap_delete_distribution',  'wpap_ajax_license_gate', 1 );
add_action( 'wp_ajax_wpap_cleanup_distribution', 'wpap_ajax_license_gate', 1 );
add_action( 'wp_ajax_wpap_automation_run_now',   'wpap_ajax_license_gate', 1 );
add_action( 'wp_ajax_wpap_dashboard_stats',       'wpap_ajax_license_gate', 1 );
add_action( 'wp_ajax_wpap_bulk_delete_distribution', 'wpap_ajax_license_gate', 1 );
function wpap_ajax_license_gate(): void {
    if ( ! wpap_is_licensed() ) {
        wp_send_json_error( 'Plugin not activated. Please enter your license key.' );
    }
    /* Licensed — let the real handler run (do nothing, return) */
}

/* ── Deactivation handler: keep the license so deactivate/reactivate does
   NOT force re-activation. Full license teardown lives in uninstall.php
   (runs only on delete). Only the remote-list cache is cleared here. ── */
register_deactivation_hook( __FILE__, 'wpap_deactivate' );
function wpap_deactivate( $network_wide = false ): void {
    /* On a NETWORK deactivate the hook fires once (main site), but the init self-heal
       schedules wpap_automation_cron into EACH visited subsite's own cron, so clear it
       per site or it dangles indefinitely. A PER-SITE deactivate ($network_wide false)
       must touch ONLY the current site — never other subsites where the plugin is still
       active (WordPress passes the network-wide flag to this callback). */
    if ( is_multisite() && $network_wide ) {
        foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $blog_id ) {
            switch_to_blog( (int) $blog_id );
            delete_transient( WPAP_LICENSE_CACHE );
            wp_clear_scheduled_hook( 'wpap_automation_cron' );
            restore_current_blog();
        }
        return;
    }
    delete_transient( WPAP_LICENSE_CACHE );
    /* Google-Sheet auto-publish: stop the recurring cron. */
    wp_clear_scheduled_hook( 'wpap_automation_cron' );
    wp_clear_scheduled_hook( 'wpap_fbp_cron' );
}

/* ════════════════════════════════════════════
   PERIODIC BACKGROUND LICENSE CHECK
   Fires only when an admin loads the plugin's
   own Dashboard or Settings pages.
   Always fetches live from the Gist (no cache).
   Auto-revokes and redirects if the entry has
   been deleted or the domain changed.
════════════════════════════════════════════ */
add_action( 'admin_init', 'wpap_schedule_revalidation' );
function wpap_schedule_revalidation(): void {
    /* Licensing removed — no remote re-check. */
    return;
    /* Only relevant in the admin area — never on the front-end */
    if ( ! is_admin() ) {
        return;
    }
    /* Only check when the current user can manage options */
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    /* Only proceed if the plugin thinks it is currently licensed */
    if ( ! wpap_is_licensed() ) {
        return;
    }
    /* Determine which admin page is loading */
    $screen_id = $_GET['page'] ?? '';
    $is_plugin_page = in_array( $screen_id, array(
        'wp-automator-pro',
        'wp-automator-pro-settings',
    ), true );
    if ( ! $is_plugin_page ) {
        return;
    }

    /* ── Throttle: run the live Gist check at most once per 24h ──────────
       Without this, every plugin admin page load makes a blocking request
       to GitHub and can hang the page. Between checks the stored license
       option remains the gate (wpap_is_licensed above). A revoke is still
       honored the next time the check actually runs (within 24h), and the
       initial activation verification is unaffected. */
    $last_check = (int) get_option( 'wpap_license_last_check', 0 );
    if ( $last_check > 0 && ( time() - $last_check ) < DAY_IN_SECONDS ) {
        return;
    }
    /* Stamp BEFORE the network call so a slow/unreachable Gist does not
       re-hang the next page load — mirrors the existing "benefit of the
       doubt" behavior when the remote is down. The revoke logic below still
       runs fully in THIS request; the stamp only gates future loads. */
    update_option( 'wpap_license_last_check', time(), false );

    /* ── Always fetch live — bypass transient cache ── */
    delete_transient( WPAP_LICENSE_CACHE );
    $bust_url = WPAP_LICENSE_URL . '?nocache=' . time();
    $response = wp_remote_get( $bust_url, array(
        'timeout'   => 12,
        'sslverify' => true,
    ) );

    /* If the remote is unreachable, do nothing — benefit of the doubt */
    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
        return;
    }

    $body = wp_remote_retrieve_body( $response );
    $list = json_decode( $body, true );
    if ( ! is_array( $list ) ) {
        return;   /* Malformed JSON — do nothing */
    }

    /* ── Load the stored license data ── */
    $stored      = get_option( WPAP_LICENSE_OPTION, array() );
    $stored_user = strtolower( trim( (string) ( $stored['user'] ?? '' ) ) );
    $stored_key  = trim( (string) ( $stored['key'] ?? '' ) );
    $site_domain = (string) parse_url( get_site_url(), PHP_URL_HOST );

    /* ── Search for a matching entry in the live Gist ── */
    $entry_found  = false;
    $domain_valid = false;

    foreach ( $list as $entry ) {
        if ( ! is_array( $entry ) ) {
            continue;
        }
        $remote_user   = strtolower( trim( (string) ( $entry['user']    ?? $entry['username'] ?? '' ) ) );
        $remote_key    = trim( (string) ( $entry['key']     ?? $entry['license']  ?? '' ) );
        $remote_domain = trim( (string) ( $entry['domain']  ?? '' ) );

        if ( $remote_user !== $stored_user || $remote_key !== $stored_key ) {
            continue;
        }

        /* Credentials still exist in the Gist */
        $entry_found = true;

        /* Domain check: empty = unlocked (valid); non-empty must match site */
        $domain_valid = ( $remote_domain === '' || $remote_domain === $site_domain );
        break;
    }

    /* ── If entry gone or domain mismatched → revoke immediately ── */
    if ( ! $entry_found || ! $domain_valid ) {
        delete_option( WPAP_LICENSE_OPTION );
        delete_transient( WPAP_LICENSE_CACHE );

        /* Store a one-time revocation message for the activation page */
        set_transient( 'wpap_revoke_notice',
            'Your license has been revoked or modified. Please contact Oussama Hamri.',
            120
        );

        wp_safe_redirect( admin_url( 'admin.php?page=wpap-activate' ) );
        exit;
    }
}

/* ════════════════════════════════════════════
   END LICENSE SYSTEM — plugin continues below
════════════════════════════════════════════ */

/* ── Unblock outgoing HTTP ── */
if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) ) {
    define( 'WP_HTTP_BLOCK_EXTERNAL', false );
}
if ( defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL && ! defined( 'WP_ACCESSIBLE_HOSTS' ) ) {
    define( 'WP_ACCESSIBLE_HOSTS', 'api.anthropic.com,generativelanguage.googleapis.com,image.pollinations.ai,api.pexels.com,images.pexels.com' );
}

/* ── Increase wp_remote_* timeout for all AJAX requests ── */
add_filter( 'http_request_timeout', function ( $t ) {
    return ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ? 180 : $t;
} );

/* ── Unblock external image/AI API hosts unconditionally ── */
add_filter( 'http_request_args', function ( $args, $url ) {
    static $allowed = array(
        'api.anthropic.com',
        'generativelanguage.googleapis.com',
        'image.pollinations.ai',
        'api.pexels.com',
        'images.pexels.com',
    );
    $host = wp_parse_url( $url, PHP_URL_HOST );
    if ( in_array( $host, $allowed, true ) ) {
        $args['reject_unsafe_urls'] = false;
        if ( wpap_tls_should_bypass() ) {
            /* One-shot fallback: this host's CA store is broken — retry without verification. */
            $args['sslverify'] = false;
        } else {
            $args['sslverify'] = true;
            $bundle = ABSPATH . WPINC . '/certificates/ca-bundle.crt';
            if ( is_readable( $bundle ) ) {
                $args['sslcertificates'] = $bundle;
            }
        }
    }
    return $args;
}, 10, 2 );

/* ════════════════════════════════════════════
   SECURITY HARDENING HELPERS
   TLS resilience + cost circuit-breaker + payload caps.
   NOTE: this block does NOT touch AI content/image generation.
════════════════════════════════════════════ */

/* TLS bypass state: true only during a one-shot retry after a CA/cert failure. */
function wpap_tls_should_bypass( $set = null ) {
    static $bypass = false;
    if ( null !== $set ) {
        $bypass = (bool) $set;
    }
    return $bypass;
}

/* Verify TLS by default for the trusted AI/image hosts; retry once WITHOUT
   verification only when the failure is a genuine certificate/CA problem, so a
   broken CA store never hard-breaks generation. Implemented via pre_http_request
   because it is the only WP HTTP hook that lets us observe the WP_Error and
   re-issue the request without editing the protected generator functions. */
add_filter( 'pre_http_request', 'wpap_tls_resilient_request', 10, 3 );
function wpap_tls_resilient_request( $pre, $parsed_args, $url ) {
    static $in_flight = false;

    /* Respect an earlier short-circuit, and never recurse into our own retry. */
    if ( false !== $pre || $in_flight ) {
        return $pre;
    }

    static $allowed = array(
        'api.anthropic.com',
        'generativelanguage.googleapis.com',
        'image.pollinations.ai',
        'api.pexels.com',
        'images.pexels.com',
    );
    $host = wp_parse_url( $url, PHP_URL_HOST );
    if ( ! in_array( $host, $allowed, true ) ) {
        return $pre; /* not one of ours — let WP handle it normally */
    }

    $in_flight = true;
    $response  = wp_remote_request( $url, $parsed_args );

    if ( is_wp_error( $response ) ) {
        $msg = $response->get_error_message();
        $is_cert_error = ( false !== stripos( $msg, 'certificate' )
            || false !== stripos( $msg, 'SSL' )
            || false !== stripos( $msg, 'cURL error 60' )
            || false !== stripos( $msg, 'cURL error 77' ) );

        /* Only the two image-only hosts (which carry NO API key in the request)
           may fall back to an unverified retry. The key-bearing hosts —
           Anthropic (x-api-key), Gemini (?key= in URL), Pexels API
           (Authorization) — are NEVER retried without verification, so a secret
           can never be sent over an unverified / MITM'd connection. A genuine
           cert failure on a key host surfaces as an error the operator can fix
           (usually a stale server CA bundle) instead of silently leaking. */
        $bypass_ok_hosts = array( 'image.pollinations.ai', 'images.pexels.com' );
        if ( $is_cert_error && in_array( $host, $bypass_ok_hosts, true ) ) {
            error_log( '[Automation Hamri] TLS verification failed for image host ' . $host . ' — ' . $msg . '. Retrying once without verification (no secret in this request).' );
            wpap_tls_should_bypass( true );
            $response = wp_remote_request( $url, $parsed_args );
            wpap_tls_should_bypass( false );
        } elseif ( $is_cert_error ) {
            error_log( '[Automation Hamri] TLS verification failed for key-bearing host ' . $host . ' — ' . $msg . '. NOT retrying unverified (protecting the API key). Fix the server CA bundle if this persists.' );
        }
    }

    $in_flight = false;
    return $response;
}

/* Cost circuit-breaker: per-user hourly + global daily ceilings via transients.
   $units = how many generation units this request will consume. */
function wpap_rate_limit_ok( $units = 1 ) {
    $units = max( 1, (int) $units );

    $hourly_cap = (int) apply_filters( 'wpap_rate_limit_per_user_hourly', 120 );
    $daily_cap  = (int) apply_filters( 'wpap_rate_limit_global_daily', 1000 );

    /* Per-user, per-clock-hour bucket (key rotates each hour). */
    $user_key = 'wpap_rl_u_' . get_current_user_id() . '_' . gmdate( 'YmdH' );
    $user_now = (int) get_transient( $user_key );
    if ( $user_now + $units > $hourly_cap ) {
        return false;
    }

    /* Global, per-day bucket. */
    $day_key = 'wpap_rl_g_' . gmdate( 'Ymd' );
    $day_now = (int) get_transient( $day_key );
    if ( $day_now + $units > $daily_cap ) {
        return false;
    }

    set_transient( $user_key, $user_now + $units, HOUR_IN_SECONDS );
    set_transient( $day_key,  $day_now  + $units, DAY_IN_SECONDS );
    return true;
}

/* Payload guards for the JSON bulk endpoints (filterable). */
function wpap_bulk_max_bytes() {
    return (int) apply_filters( 'wpap_bulk_max_payload_bytes', 2 * 1024 * 1024 );
}
function wpap_bulk_max_items() {
    /* Per-request cap. The whole batch is processed in ONE request (downloading
       each image in turn), so this is bounded by PHP's execution time on this
       host. 200 was proven safe here; 300 gives headroom. If a very large batch
       ever errors (a timeout), split it — or raise this via the filter. */
    return (int) apply_filters( 'wpap_bulk_max_items', 300 );
}

define( 'WPAP_VERSION', '9.43.0' );
define( 'WPAP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPAP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPAP_TABLE',      'wpap_generated_posts' );
define( 'WPAP_TOMB_TABLE', 'wpap_tombstones' );   /* durable source-key tombstones (indexed; replaces the O(n) wpap_automation_deleted_keys option) */
define( 'WPAP_DB_VERSION', '4' );   /* bump to re-run the table dbDelta on init (v2: image_url VARCHAR(800)→TEXT — signed S3/CDN URLs run >800 bytes and were truncating; matches build-final so the shared table never downgrades. v3: add a composite (meta_key,meta_value) index to wp_postmeta so the dedup lookups on _wpap_source_key / _wpap_source_image_hash are index seeks, not full meta_key-group scans, at 100k+ posts. v4: add the wpap_tombstones table + backfill it from the wpap_automation_deleted_keys option, so tombstone writes are O(1) INSERT IGNOREs and the dedup lookup is an indexed SELECT instead of unserializing a ~1.2MB option) */

/* ── API key resolver: a wp-config.php constant wins over the DB option ──
   Lets site owners define keys in wp-config.php (kept out of the database
   and out of the admin UI). Falls back to the value saved in wpap_settings.
   $which is one of 'claude', 'gemini', 'pexels'. Returns '' when unset. */
function wpap_get_api_key( string $which ): string {
    static $map = array(
        'claude' => array( 'WPAP_CLAUDE_API_KEY', 'claude_api_key' ),
        'gemini' => array( 'WPAP_GEMINI_API_KEY', 'gemini_api_key' ),
        'pexels' => array( 'WPAP_PEXELS_API_KEY', 'pexels_api_key' ),
    );
    if ( ! isset( $map[ $which ] ) ) {
        return '';
    }
    list( $const_name, $option_key ) = $map[ $which ];

    /* 1) wp-config.php constant takes precedence when defined and non-empty. */
    if ( defined( $const_name ) ) {
        $const_val = trim( (string) constant( $const_name ) );
        if ( '' !== $const_val ) {
            return $const_val;
        }
    }

    /* 2) Fall back to the saved setting. */
    $settings = get_option( 'wpap_settings', array() );
    if ( ! is_array( $settings ) ) {
        $settings = array();
    }
    return trim( (string) ( $settings[ $option_key ] ?? '' ) );
}

/* ════════════════════════════════════════════
   1. ACTIVATION — create table + default settings
════════════════════════════════════════════ */
/* Create/upgrade the distribution table. Shared by activation AND the init
   self-heal below. No "IF NOT EXISTS": dbDelta mis-parses the table name when it's
   present (it captures "IF"), which would block future column migrations — dbDelta
   already handles existence, only creating or ALTERing what's missing. */
function wpap_create_table() {
    global $wpdb;
    $t = $wpdb->prefix . WPAP_TABLE;
    $c = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( "CREATE TABLE {$t} (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        post_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
        title       VARCHAR(500)    NOT NULL DEFAULT '',
        post_url    VARCHAR(800)    NOT NULL DEFAULT '',
        image_url   TEXT,
        fb_text     TEXT,
        fb_post_id  VARCHAR(200)    NOT NULL DEFAULT '',
        smart_link  VARCHAR(900)    NOT NULL DEFAULT '',
        created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY post_id  (post_id)
    ) {$c};" );

    /* Durable source-key tombstones: a small indexed table so recording a deleted key is
       one O(1) INSERT IGNORE and the automation dedup check is an indexed SELECT — replacing
       the old wpap_automation_deleted_keys option, which was unserialized + rewritten whole
       (~1.2MB at the 20k cap) on every permanent delete. source_key holds a Sheet id or a
       32-char md5 (wpap_automation_row_key); 191 chars fits utf8mb4 within the index limit. */
    $tomb = $wpdb->prefix . WPAP_TOMB_TABLE;
    dbDelta( "CREATE TABLE {$tomb} (
        source_key  VARCHAR(191)    NOT NULL,
        created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (source_key)
    ) {$c};" );
}

/* Add a composite index on wp_postmeta so the plugin's dedup lookups become index
   seeks instead of full meta_key-group scans at scale. The plugin filters postmeta
   on meta_value for _wpap_source_key (automation dedup) and _wpap_source_image_hash
   (image dedup); WP core indexes only meta_key(191), so at 100k+ posts each lookup
   scans every row carrying that key. A (meta_key(32), meta_value(191)) index turns
   both into seeks. Idempotent (SHOW INDEX guard) and best-effort — a host that
   refuses ALTER on the shared core table simply keeps the old behaviour, never a
   fatal. Additive only: an index never changes query results. */
function wpap_ensure_meta_dedup_index() {
    global $wpdb;
    $index = 'wpap_meta_dedup';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema check, one-time, no user input
    $exists = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(1) FROM information_schema.STATISTICS
         WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s",
        $wpdb->postmeta, $index
    ) );
    if ( $exists ) { return; }
    $suppress = $wpdb->suppress_errors( true );
    // meta_key(32) covers the plugin's underscore-prefixed keys; meta_value(191) fully
    // covers the 32-char md5 image hash and short source keys.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL -- DDL, identifiers are core-derived, no user input
    $wpdb->query( "ALTER TABLE {$wpdb->postmeta} ADD KEY {$index} (meta_key(32), meta_value(191))" );
    $wpdb->suppress_errors( $suppress );
}

/* Self-heal the table on init when an install was activated WITHOUT the
   activation hook (e.g. a seeder that writes the active_plugins option directly),
   so the Hub / export / delete hooks never hit a missing table. Gated by a version
   option so dbDelta runs at most once per schema version — the steady-state cost is
   a single autoloaded option read. */
add_action( 'init', 'wpap_maybe_upgrade_db' );
function wpap_maybe_upgrade_db() {
    /* Self-heal the automation cron the same way we self-heal the table: an install
       activated WITHOUT the activation hook (a seeder writing active_plugins) would
       otherwise never schedule 'wpap_automation_cron', silently disabling the
       Google-Sheet auto-publish while Settings still shows it enabled. The
       wp_next_scheduled guard reads the (autoloaded) cron array, so this is cheap. */
    if ( ! wp_next_scheduled( 'wpap_automation_cron' ) ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'wpap_automation_cron' );
    }
    /* One-time: move the front-end hot-path option wpap_ads_inject to autoload=yes on
       existing installs (it was stored autoload=no, forcing an extra options query on
       every uncached pageview). Guarded by its own flag so it runs at most once, and
       decoupled from the shared wpap_db_version so it never touches the schema gate. */
    if ( ! get_option( 'wpap_ads_autoload_fix' ) ) {
        $wpap_ai = get_option( 'wpap_ads_inject', null );
        if ( is_array( $wpap_ai ) ) {
            /* FORCE the autoload flip. Passing the SAME value to update_option($v,true) does
               NOT flip autoload on WP < 6.4 (core short-circuits an unchanged value before it
               touches the autoload column), and the plugin supports 5.8+. Use the dedicated
               API when present (6.4+), else delete+add with autoload=yes so the front-end
               hot-path option actually leaves the per-request options query behind. */
            if ( function_exists( 'wp_set_option_autoload' ) ) {
                wp_set_option_autoload( 'wpap_ads_inject', true );
            } else {
                delete_option( 'wpap_ads_inject' );
                add_option( 'wpap_ads_inject', $wpap_ai, '', 'yes' );
            }
        }
        update_option( 'wpap_ads_autoload_fix', '1', true );
    }
    /* MONOTONIC gate: migrate only when the stored version is LOWER than ours, never on a mere
       inequality. build-v9 and build-final share this table + option key; an equality check let a
       lower-versioned build DOWNGRADE the schema (e.g. image_url TEXT→VARCHAR(800), re-truncating
       stored URLs). With `>=`, neither build ever runs its create/ALTER against an already-newer
       schema, so the shared column only ever moves up. */
    if ( (int) get_option( 'wpap_db_version', '0' ) >= (int) WPAP_DB_VERSION ) {
        return;
    }
    wpap_create_table();
    wpap_ensure_meta_dedup_index();
    wpap_migrate_tombstones_to_table();
    update_option( 'wpap_db_version', WPAP_DB_VERSION );
}

/* One-time backfill of the wpap_tombstones table from the legacy
   wpap_automation_deleted_keys option (keys => timestamps). Idempotent: INSERT IGNORE
   skips keys already present, and a flag guards against re-running. The legacy option is
   left in place (harmless, autoload=no) so a rollback to an older build still has its
   tombstones; new writes go only to the table. */
function wpap_migrate_tombstones_to_table() {
    if ( get_option( 'wpap_tomb_migrated' ) ) { return; }
    global $wpdb;
    $tomb  = get_option( 'wpap_automation_deleted_keys', array() );
    $table = $wpdb->prefix . WPAP_TOMB_TABLE;
    if ( is_array( $tomb ) && $tomb ) {
        foreach ( array_keys( $tomb ) as $key ) {
            $key = (string) $key;
            if ( '' === $key ) { continue; }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-time bounded backfill (<=20k rows)
            $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (source_key) VALUES (%s)", substr( $key, 0, 191 ) ) );
        }
    }
    update_option( 'wpap_tomb_migrated', '1', true );
}

register_activation_hook( __FILE__, 'wpap_activate' );
function wpap_activate() {
    wpap_create_table();
    wpap_ensure_meta_dedup_index();
    /* Backfill legacy tombstones HERE too — activation stamps wpap_db_version below, after
       which the init self-heal (wpap_maybe_upgrade_db) early-returns forever, so this is the
       only chance to migrate the old wpap_automation_deleted_keys option on a normal
       Plugins→Activate upgrade. Idempotent (wpap_tomb_migrated flag + INSERT IGNORE); a
       no-op on fresh installs with no legacy option. */
    wpap_migrate_tombstones_to_table();
    update_option( 'wpap_db_version', WPAP_DB_VERSION );
    $existing = get_option( 'wpap_settings', array() );
    $defaults = array(
        'claude_api_key'  => '',
        'gemini_api_key'  => '',
        'pexels_api_key'  => '',
    );
    update_option( 'wpap_settings', array_merge( $defaults, $existing ), false );   /* autoload = no: API keys stay out of the all-options cache */

    /* Google-Sheet auto-publish: schedule the hourly poll cron (guarded so a
       re-activate doesn't stack duplicate events; first run +1h out). */
    if ( ! wp_next_scheduled( 'wpap_automation_cron' ) ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'wpap_automation_cron' );
    }
}

/* ════════════════════════════════════════════
   2. ADMIN MENU
════════════════════════════════════════════ */

add_action( 'admin_menu', 'wpap_admin_menu' );
function wpap_admin_menu() {
    if ( ! wpap_is_licensed() ) {
        return;   /* locked — only the activation menu is shown */
    }
    add_menu_page( 'WP Automator Pro', 'WP Automator Pro', 'manage_options', 'wp-automator-pro', 'wpap_render_dashboard', 'dashicons-superhero', 3 );
    add_submenu_page( 'wp-automator-pro', 'Dashboard', 'Dashboard', 'manage_options', 'wp-automator-pro', 'wpap_render_dashboard' );
    add_submenu_page( 'wp-automator-pro', 'Settings', 'Settings', 'manage_options', 'wp-automator-pro-settings', 'wpap_render_settings' );
    add_submenu_page( 'wp-automator-pro', 'Bulk ZIP Publish', 'Bulk ZIP Publish', 'manage_options', 'wp-automator-pro-bundle', 'wpap_render_bundle' );
    add_submenu_page( 'wp-automator-pro', 'Apply Rewrites', 'Apply Rewrites', 'manage_options', 'wp-automator-pro-apply', 'wpap_render_apply_rewrites' );
}

/* ════════════════════════════════════════════
   3. ENQUEUE ASSETS
════════════════════════════════════════════ */
add_action( 'admin_enqueue_scripts', 'wpap_enqueue_assets' );
function wpap_enqueue_assets( $hook ) {
    if ( strpos( $hook, 'wp-automator-pro' ) === false ) return;
    /* Version assets by file modification time so browsers always pick up the
       latest file after an update (no stale-cache "nothing changed" surprises). */
    $css_path = WPAP_PLUGIN_DIR . 'assets/admin-style.css';
    $js_path  = WPAP_PLUGIN_DIR . 'assets/admin-script.js';
    $css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : WPAP_VERSION;
    $js_ver   = file_exists( $js_path )  ? (string) filemtime( $js_path )  : WPAP_VERSION;
    wp_enqueue_style(  'wpap-style',  WPAP_PLUGIN_URL . 'assets/admin-style.css', array(), $css_ver );
    wp_enqueue_script( 'wpap-script', WPAP_PLUGIN_URL . 'assets/admin-script.js', array( 'jquery' ), $js_ver, true );
    $wpap_copts_js = get_option( 'wpap_content_opts', array() );
    wp_localize_script( 'wpap-script', 'WPAP', array(
        'ajax_url'   => admin_url( 'admin-ajax.php' ),
        'nonce'      => wp_create_nonce( 'wpap_nonce' ),
        'plugin_url' => WPAP_PLUGIN_URL,
        /* Global first-comment template → lets the Hub compose the comment client-side. */
        'fb_comment_template' => is_array( $wpap_copts_js ) ? (string) ( $wpap_copts_js['fb_comment_template'] ?? '' ) : '',
    ) );
}

/* ════════════════════════════════════════════
   4. DASHBOARD PAGE
════════════════════════════════════════════ */


/* -------------------------------------------------------------------------
 * Modular includes (extracted from the historical single-file build).
 * Each module is a verbatim contiguous slice; every hook self-registers
 * inside its module. Order below matches original top-level execution order.
 * See ARCHITECTURE.md for the concern map.
 * ---------------------------------------------------------------------- */
require_once __DIR__ . '/includes/admin.php'; // Admin UI — dashboard render + settings page
require_once __DIR__ . '/includes/scheduling.php'; // Scheduling, public permalinks, content splitting
require_once __DIR__ . '/includes/media.php'; // Image upload / WebP conversion / SSRF-guarded remote import
require_once __DIR__ . '/includes/publishing.php'; // Distribution import, article publish, bulk publish, JSON export, title processing (process_title nests the AI hook/title generators — DO NOT EDIT the AI portions)
require_once __DIR__ . '/includes/internal-links.php'; // In-content internal linking: [[link:slug]] tokens + auto-keyword cross-linking (baked at publish; admin passes)
require_once __DIR__ . '/includes/apply-rewrites.php'; // In-place content rewrite applier: upload {id,title,content} JSON → update posts under admin session, with server-side backup + one-click revert
require_once __DIR__ . '/includes/distribution.php'; // Distribution Hub queries, delete/cleanup, row lifecycle, cache purge, image proxy
require_once __DIR__ . '/includes/automation.php'; // Google-Sheet automation cron + SSRF IP helpers + lock ownership
require_once __DIR__ . '/includes/ai-content.php'; // AI content + image generation — DO NOT EDIT (AI generation pipeline)
require_once __DIR__ . '/includes/seo-schema.php'; // Front-end SEO head, meta description, Recipe/Article/Breadcrumb schema, related posts
require_once __DIR__ . '/includes/ads.php'; // ads.txt, IndexNow, ad zones (shortcode/block) + in-content injection
require_once __DIR__ . '/includes/settings-io.php'; // Settings export/import + admin dashboard health widget
require_once __DIR__ . '/includes/editor-tools.php'; // Gutenberg Author Tools — meta box + derived fields
require_once __DIR__ . '/includes/facebook-page.php'; // 9.43.0: Facebook Page auto-poster (opt-in; pulls in facebook-page-admin.php)
