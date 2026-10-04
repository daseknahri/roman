<?php
/**
 * Google-Sheet automation cron + SSRF IP helpers + lock ownership
 *
 * Extracted verbatim from wp-automator-pro.php (single-file → modular).
 * Load order is fixed by the main file; every hook self-registers here.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/* ── Settings accessor with typed defaults ── */
function wpap_get_automation() {
    $raw = get_option( 'wpap_automation', array() );
    if ( ! is_array( $raw ) ) { $raw = array(); }
    $d = array(
        'enabled'          => false,
        'sheet_url'        => '',
        'per_day'          => 12,
        'per_run'          => 3,
        'default_category' => '',
        'schedule_window'  => 0.0,
    );
    $a = array_merge( $d, $raw );
    return array(
        'enabled'          => (bool) $a['enabled'],
        'sheet_url'        => trim( (string) $a['sheet_url'] ),
        'per_day'          => max( 0, (int) $a['per_day'] ),
        'per_run'          => max( 1, (int) $a['per_run'] ),
        'default_category' => trim( (string) $a['default_category'] ),
        'schedule_window'  => max( 0.0, (float) $a['schedule_window'] ),
    );
}

/* ── Robust RFC-4180 CSV parser (CRLF, quoted fields, embedded newlines/commas,
   doubled "" quotes, UTF-8 BOM). Version-independent. ── */
function wpap_automation_parse_csv( $text ) {
    $text = (string) $text;
    if ( "\xEF\xBB\xBF" === substr( $text, 0, 3 ) ) {   /* strip UTF-8 BOM */
        $text = substr( $text, 3 );
    }
    $rows      = array();
    $row       = array();
    $field     = '';
    $in_quotes = false;
    $started   = false;
    $len       = strlen( $text );
    $max_rows  = 20000;   /* bound memory on a pathological/huge CSV (all-\n or all-,) */
    $max_cols  = 500;

    for ( $i = 0; $i < $len; $i++ ) {
        $ch = $text[ $i ];

        if ( $in_quotes ) {
            if ( '"' === $ch ) {
                if ( $i + 1 < $len && '"' === $text[ $i + 1 ] ) {   /* "" => literal " */
                    $field .= '"';
                    $i++;
                } else {
                    $in_quotes = false;
                }
            } else {
                $field .= $ch;
            }
            continue;
        }

        if ( '"' === $ch ) {
            $in_quotes = true;
            $started   = true;
        } elseif ( ',' === $ch ) {
            $row[]   = $field;
            $field   = '';
            $started = true;
            if ( count( $row ) > $max_cols ) { break; }   /* runaway columns */
        } elseif ( "\n" === $ch ) {
            $row[]   = $field;
            $rows[]  = $row;
            $row     = array();
            $field   = '';
            $started = false;
            if ( count( $rows ) >= $max_rows ) { break; }   /* runaway rows */
        } elseif ( "\r" === $ch ) {
            if ( $i + 1 < $len && "\n" === $text[ $i + 1 ] ) {
                continue;   /* CRLF: let the \n branch close the line */
            }
            $row[]   = $field;
            $rows[]  = $row;
            $row     = array();
            $field   = '';
            $started = false;
            if ( count( $rows ) >= $max_rows ) { break; }
        } else {
            $field  .= $ch;
            $started = true;
        }
    }
    if ( '' !== $field || ! empty( $row ) || $started ) {
        $row[]  = $field;
        $rows[] = $row;
    }
    return $rows;
}

/* ── Block a URL that resolves to a private/reserved/link-local address
   (basic SSRF guard; mirrors the remote-image importer). ── */
/* ── SSRF helpers ──────────────────────────────────────────────────────────────
   Resolve a host ONCE to a validated public IP and PIN the connection to that IP
   (CURLOPT_RESOLVE), so the HTTP transport can't perform a second, independent DNS
   lookup that returns a private address between our check and the connect — the
   DNS-rebinding TOCTOU. We also FAIL CLOSED: an unresolvable host is blocked, never
   allowed through. */
function wpap_ip_is_public( $ip ) {
    $ip = (string) $ip;
    /* Normalize IPv4-mapped IPv6 (::ffff:169.254.169.254 and the hex-grouped
       ::ffff:a9fe:a9fe form) to the embedded IPv4 so a mapped address can't slip
       past the private/reserved filter on PHP builds that don't fold it. */
    if ( 0 === stripos( $ip, '::ffff:' ) ) {
        $tail = substr( $ip, 7 );
        if ( filter_var( $tail, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
            $ip = $tail;
        } elseif ( preg_match( '/^([0-9a-f]{1,4}):([0-9a-f]{1,4})$/i', $tail, $m ) ) {
            $ip = long2ip( ( hexdec( $m[1] ) << 16 ) | hexdec( $m[2] ) );
        }
    }
    if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) { return false; }
    return (bool) filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
}

/* Resolve $host and return ONE validated public IP, or '' if it is unresolvable
   (fail closed) or ANY resolved address is private/reserved (reject the host). */
function wpap_resolve_public_ip( $host ) {
    $host = (string) $host;
    if ( '' === $host ) { return ''; }
    if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
        return wpap_ip_is_public( $host ) ? $host : '';
    }
    $ips     = array();
    $records = @dns_get_record( $host, DNS_A + DNS_AAAA );
    if ( is_array( $records ) ) {
        foreach ( $records as $r ) {
            if ( ! empty( $r['ip'] ) )   { $ips[] = $r['ip']; }
            if ( ! empty( $r['ipv6'] ) ) { $ips[] = $r['ipv6']; }
        }
    }
    if ( empty( $ips ) && function_exists( 'gethostbynamel' ) ) {
        $v4 = @gethostbynamel( $host );
        if ( is_array( $v4 ) ) { $ips = $v4; }
    }
    if ( empty( $ips ) ) { return ''; }   /* FAIL CLOSED: unresolvable => blocked */
    $safe = '';
    foreach ( $ips as $ip ) {
        if ( ! wpap_ip_is_public( $ip ) ) { return ''; }   /* any bad IP rejects the host */
        if ( '' === $safe ) { $safe = $ip; }
    }
    return $safe;
}

/* SSRF-safe GET: resolve+validate the host, then pin curl to that exact IP for the
   connection so no second, unvalidated resolution can occur (the hostname is still
   used for the Host header + TLS SNI/cert). Returns a WP HTTP response or WP_Error.
   NOTE: pinning applies to the curl transport (WP default); on the rare streams
   transport the pre-resolve validation + fail-closed still apply. */
function wpap_safe_remote_get( $url, array $args ) {
    $parts  = wp_parse_url( (string) $url );
    $host   = isset( $parts['host'] ) ? $parts['host'] : '';
    $scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
    if ( '' === $host || ( 'http' !== $scheme && 'https' !== $scheme ) ) {
        return new WP_Error( 'wpap_bad_url', 'Unsupported or malformed URL.' );
    }
    $ip = wpap_resolve_public_ip( $host );
    if ( '' === $ip ) {
        return new WP_Error( 'wpap_blocked_host', 'The URL host is unresolvable or resolves to a private/reserved address, and was blocked.' );
    }
    $port = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );
    $pin  = function ( $handle ) use ( $host, $port, $ip ) {
        if ( defined( 'CURLOPT_RESOLVE' ) ) {
            @curl_setopt( $handle, CURLOPT_RESOLVE, array( $host . ':' . $port . ':' . $ip ) );
        }
    };
    add_action( 'http_api_curl', $pin, 10, 1 );
    $response = wp_remote_get( $url, $args );
    remove_action( 'http_api_curl', $pin, 10 );
    return $response;
}

/* Back-compat boolean guard, now FAIL-CLOSED (delegates to the resolver above). */
function wpap_remote_host_is_public( $url ) {
    $host = wp_parse_url( (string) $url, PHP_URL_HOST );
    if ( ! $host ) { return false; }
    return '' !== wpap_resolve_public_ip( $host );
}

/* ── Fetch the published CSV and map rows to publishable items. Returns array|WP_Error. ── */
function wpap_automation_fetch_rows( $url ) {
    $url = trim( (string) $url );
    if ( '' === $url ) {
        return new WP_Error( 'wpap_no_url', 'No Google Sheet CSV URL configured.' );
    }
    /* Fetch with per-hop SSRF protection: wpap_safe_remote_get() resolves EVERY
       target (initial + each redirect) to a validated public IP and pins the socket
       to it, so a public URL can't bounce — or DNS-rebind — the request to an
       internal address. We follow redirects manually (redirection=0). */
    $current  = $url;
    $response = null;
    $followed = 0;
    while ( true ) {
        $response = wpap_safe_remote_get( $current, array(
            'timeout'             => 10,
            'redirection'         => 0,            /* follow manually, re-resolving + re-pinning each hop */
            'sslverify'           => true,
            'reject_unsafe_urls'  => true,
            'limit_response_size' => 5 * 1024 * 1024,
            'headers'             => array( 'Accept' => 'text/csv, text/plain, */*' ),
        ) );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $rcode = (int) wp_remote_retrieve_response_code( $response );
        if ( $rcode < 300 || $rcode >= 400 ) {
            break;   /* not a redirect — done */
        }
        if ( ++$followed > 5 ) {
            return new WP_Error( 'wpap_redirects', 'Too many redirects fetching the Sheet.' );
        }
        $loc = trim( (string) wp_remote_retrieve_header( $response, 'location' ) );
        if ( '' === $loc ) {
            return new WP_Error( 'wpap_redirect', 'Sheet redirect had no destination.' );
        }
        if ( preg_match( '#^https?://#i', $loc ) ) {
            $current = $loc;
        } elseif ( 0 === strpos( $loc, '/' ) ) {
            $p = wp_parse_url( $current );
            if ( empty( $p['scheme'] ) || empty( $p['host'] ) ) {
                return new WP_Error( 'wpap_redirect', 'Could not resolve the Sheet redirect.' );
            }
            $current = $p['scheme'] . '://' . $p['host'] . $loc;
        } else {
            return new WP_Error( 'wpap_redirect', 'Unsupported Sheet redirect target.' );
        }
    }
    $code = (int) wp_remote_retrieve_response_code( $response );
    if ( 200 !== $code ) {
        return new WP_Error( 'wpap_http_' . $code, sprintf( 'Sheet fetch failed (HTTP %d). Check the published-CSV URL.', $code ) );
    }

    $body = (string) wp_remote_retrieve_body( $response );
    if ( '' === trim( $body ) ) {
        return new WP_Error( 'wpap_empty', 'The published Sheet returned no data.' );
    }

    /* Guard against the wrong URL (edit/view page instead of the CSV export). */
    $ctype = (string) wp_remote_retrieve_header( $response, 'content-type' );
    if ( false !== stripos( $ctype, 'text/html' ) || 0 === strncmp( ltrim( $body ), '<', 1 ) ) {
        return new WP_Error( 'wpap_not_csv', 'That URL returned a web page, not CSV. Use File -> Share -> Publish to web -> CSV (the link should end in output=csv).' );
    }

    $table = wpap_automation_parse_csv( $body );
    if ( count( $table ) < 2 ) {
        return new WP_Error( 'wpap_no_rows', 'The Sheet has a header but no data rows.' );
    }

    /* Header row: lowercased + trimmed; first occurrence of each name wins. */
    $header = array_map( function ( $h ) { return strtolower( trim( (string) $h ) ); }, array_shift( $table ) );
    $idx = array();
    foreach ( $header as $pos => $name ) {
        if ( '' !== $name && ! isset( $idx[ $name ] ) ) {
            $idx[ $name ] = $pos;
        }
    }
    $get = function ( array $cols, $names ) use ( $idx ) {
        foreach ( (array) $names as $n ) {
            if ( isset( $idx[ $n ], $cols[ $idx[ $n ] ] ) ) {
                $v = trim( (string) $cols[ $idx[ $n ] ] );
                if ( '' !== $v ) { return $v; }
            }
        }
        return '';
    };

    $items = array();
    foreach ( $table as $cols ) {
        if ( ! is_array( $cols ) ) { continue; }
        $title   = $get( $cols, array( 'title' ) );
        $content = $get( $cols, array( 'content', 'body' ) );
        if ( '' === $title && '' === $content ) {   /* skip empty rows */
            continue;
        }
        $items[] = array(
            'title'           => $title,
            'content'         => $content,
            'imageUrl'        => $get( $cols, array( 'imageurl', 'image_url', 'image' ) ),
            'hook'            => $get( $cols, array( 'hook', 'comment', 'caption' ) ),
            'category'        => $get( $cols, array( 'category' ) ),
            'id'              => $get( $cols, array( 'id' ) ),
            'metaDescription' => $get( $cols, array( 'metadescription', 'meta_description', 'description' ) ),
            'metaTitle'       => $get( $cols, array( 'metatitle', 'meta_title', 'seo_title' ) ),
            'focusKeyword'    => $get( $cols, array( 'focuskeyword', 'focus_keyword', 'keyword' ) ),
            'tags'            => $get( $cols, array( 'tags' ) ),
        );
    }
    if ( empty( $items ) ) {
        return new WP_Error( 'wpap_no_valid', 'No rows with a title or content were found.' );
    }
    return $items;
}

/* ── Dedup key: trimmed id if present, else md5(lower(title)|md5(content)) ── */
function wpap_automation_row_key( array $item ) {
    $id = isset( $item['id'] ) ? trim( (string) $item['id'] ) : '';
    if ( '' !== $id ) {
        return $id;
    }
    $title = (string) ( $item['title'] ?? '' );
    $title = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $title ) ) : strtolower( trim( $title ) );
    return md5( $title . '|' . md5( (string) ( $item['content'] ?? '' ) ) );
}

function wpap_automation_get_seen() {
    $seen = get_option( 'wpap_automation_seen', array() );
    return is_array( $seen ) ? $seen : array();
}
function wpap_automation_prune_seen( array $seen, $max_age_days = 120, $max_entries = 5000 ) {
    $cutoff = time() - ( (int) $max_age_days * DAY_IN_SECONDS );
    foreach ( $seen as $k => $ts ) {
        if ( (int) $ts < $cutoff ) { unset( $seen[ $k ] ); }
    }
    if ( count( $seen ) > $max_entries ) {
        asort( $seen, SORT_NUMERIC );
        $seen = array_slice( $seen, count( $seen ) - $max_entries, null, true );
    }
    return $seen;
}

/* Durable "already handled" check for a Sheet-row key, independent of the prunable
   seen cache: true if the key is tombstoned as user-deleted, or a post already
   carries this _wpap_source_key. Anchoring de-dup here (not just the seen cache)
   is what makes "published once, never recreated" actually hold (gap #6). */
function wpap_automation_key_is_done( $key ) {
    $key = (string) $key;
    if ( '' === $key ) { return false; }
    global $wpdb;
    $tomb_table = $wpdb->prefix . WPAP_TOMB_TABLE;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- indexed PK lookup, O(1)
    if ( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$tomb_table} WHERE source_key = %s LIMIT 1", substr( $key, 0, 191 ) ) ) ) {
        return true;
    }
    $existing = get_posts( array(
        'post_type'        => 'post',
        'post_status'        => 'any',
        'meta_key'         => '_wpap_source_key',
        'meta_value'       => $key,
        'fields'           => 'ids',
        'posts_per_page'   => 1,
        'no_found_rows'    => true,
        'suppress_filters' => true,
    ) );
    return ! empty( $existing );
}

/* Durable check for the stable CONTENT key: true if any post already carries this
   _wpap_source_alt_key. Index-backed by the composite postmeta index. Additional dedup anchor so
   a Sheet `id`-column change can't re-publish the archive as duplicates. (ported from build-final) */
function wpap_automation_alt_key_is_done( $ckey ) {
    $ckey = (string) $ckey;
    if ( '' === $ckey ) { return false; }
    $existing = get_posts( array(
        'post_type'        => 'post',
        'post_status'      => 'any',
        'meta_key'         => '_wpap_source_alt_key',
        'meta_value'       => $ckey,
        'fields'           => 'ids',
        'posts_per_page'   => 1,
        'no_found_rows'    => true,
        'suppress_filters' => true,
    ) );
    return ! empty( $existing );
}

function wpap_automation_bump_failure( $key ) {
    $f = get_option( 'wpap_automation_fails', array() );
    if ( ! is_array( $f ) ) { $f = array(); }
    $n = isset( $f[ $key ] ) ? (int) $f[ $key ] + 1 : 1;
    $f[ $key ] = $n;
    if ( count( $f ) > 1000 ) { $f = array_slice( $f, -1000, null, true ); }
    update_option( 'wpap_automation_fails', $f, false );
    return $n;
}
function wpap_automation_clear_failure( $key ) {
    $f = get_option( 'wpap_automation_fails', array() );
    if ( ! is_array( $f ) || ! isset( $f[ $key ] ) ) { return; }
    unset( $f[ $key ] );
    update_option( 'wpap_automation_fails', $f, false );
}

function wpap_automation_write_status( array $partial ) {
    $status = array_merge( array(
        'last_run'   => current_time( 'mysql' ),
        'rows_found' => 0,
        'published'  => 0,
        'skipped'    => 0,
        'errors'     => 0,
        'message'    => '',
    ), $partial );
    update_option( 'wpap_automation_status', $status, false );
    return $status;
}

/* ── The run: fetch the Sheet, publish up to the per-run/per-day budget ── */
/* Release an add_option-based lock ONLY if we still own it: an ATOMIC conditional
   DELETE keyed on our token, so a slow run whose stale lock was already reclaimed by
   another run can't stomp the new owner's lock on its own late release (which would
   re-open the double-publish window). A plain get_option()+delete_option() can't do
   this safely because the per-process options cache still holds OUR old token, so
   get_option() would wrongly report us as the owner. */
function wpap_release_lock_if_owner( $name, $token ) {
    global $wpdb;
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
        $name, (string) $token
    ) );
    wp_cache_delete( $name, 'options' );
}

/* TRUE atomic lock acquire — the one guarantee WP's add_option() does NOT give us. Core's
   add_option() runs `INSERT ... ON DUPLICATE KEY UPDATE`, so a SECOND concurrent writer whose
   value differs (two runs that straddle a 1-second boundary while both writing time()) gets
   affected-rows = 2 and add_option returns TRUE → a silent double-acquire. `INSERT IGNORE`
   inserts exactly one row and the UNIQUE(option_name) key makes every other racer a no-op
   (affected-rows = 0) — the real compare-and-swap. Returns true only for the single winner.
   Autoload is 'no' (a lock is never wanted on the front end). Pair with wpap_release_lock_if_owner(). */
function wpap_atomic_lock_acquire( $name, $value ) {
    global $wpdb;
    $inserted = $wpdb->query( $wpdb->prepare(
        "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
        (string) $name, (string) $value
    ) );
    /* The raw INSERT bypassed the options cache: drop any cached value AND the "notoptions"
       marker so a later get_option( $name ) on the reclaim path reads the real DB row. */
    wp_cache_delete( $name, 'options' );
    wp_cache_delete( 'notoptions', 'options' );
    return ( 1 === (int) $inserted );
}

/* Stable CONTENT key: md5(lower(title)|md5(content)), independent of the Sheet's optional `id`
   column. Stored on every published post as _wpap_source_alt_key and checked as an ADDITIONAL
   dedup anchor, so adding/removing/renaming the Sheet's `id` column — which flips
   wpap_automation_row_key's basis for EVERY row at once — can't make the whole archive look new
   and re-publish as duplicates. (ported from build-final) */
function wpap_automation_content_key( array $item ) {
    $title = (string) ( $item['title'] ?? '' );
    $title = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $title ) ) : strtolower( trim( $title ) );
    return md5( $title . '|' . md5( (string) ( $item['content'] ?? '' ) ) );
}

/* DURABLE give-up marker for a Sheet row that failed 3 times (a permanent no-image / thin-content
   row). The seen cache alone isn't enough — it ages out at 120 days, after which the identical row
   is re-attempted, re-burns 3 strikes and re-fires the alert email forever on a ~120-day cycle.
   Bounded like the tombstone option; autoload off. (ported from build-final) */
function wpap_automation_mark_giveup( $key ) {
    $key = (string) $key;
    if ( '' === $key ) { return; }
    $g = get_option( 'wpap_automation_giveup_keys', array() );
    if ( ! is_array( $g ) ) { $g = array(); }
    if ( isset( $g[ $key ] ) ) { return; }
    $g[ $key ] = time();
    if ( count( $g ) > 20000 ) { asort( $g ); $g = array_slice( $g, -20000, null, true ); }
    update_option( 'wpap_automation_giveup_keys', $g, false );
}

/* Email the site admin when automation hits a problem it can't self-heal (Sheet URL stopped
   resolving, or every row in a run errored). On by default; throttled to one message per 12h so a
   persistent fault can't flood the inbox. Toggle under Settings → Automation. (ported from build-final) */
function wpap_automation_alert( $auto, $message ) {
    $on = ! is_array( $auto ) || ! isset( $auto['alert_email'] ) ? true : ! empty( $auto['alert_email'] );
    if ( ! $on ) { return; }
    $to = get_option( 'admin_email' );
    if ( ! is_email( $to ) ) { return; }
    $last = (int) get_option( 'wpap_automation_alert_sent', 0 );
    if ( ( time() - $last ) < 12 * HOUR_IN_SECONDS ) { return; }   /* throttle */
    update_option( 'wpap_automation_alert_sent', time(), false );

    $site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
    $body = "Your WP Automator Pro auto-publish reported a problem:\n\n"
          . $message . "\n\n"
          . "Open WordPress → WP Automator Pro → Settings → \"Automation status\" to check.\n\n"
          . "(You're getting this because email alerts are on. Turn them off under Settings → Automation.)\n";
    wp_mail( $to, sprintf( '[%s] Auto-publish needs attention', $site ), $body );
}

/* Contextual admin notice: on THIS plugin's pages only, warn when WP-Cron is disabled while
   auto-publish is enabled — the usual cause of "nothing gets published". (ported from build-final) */
add_action( 'admin_notices', 'wpap_cron_health_notice' );
function wpap_cron_health_notice() {
    if ( ! current_user_can( 'manage_options' ) ) { return; }
    $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
    if ( 0 !== strpos( $page, 'wp-automator-pro' ) ) { return; }          /* our pages only */
    if ( ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) { return; }
    $auto = get_option( 'wpap_automation', array() );
    if ( ! is_array( $auto ) || empty( $auto['enabled'] ) ) { return; }   /* only when something depends on cron */
    echo '<div class="notice notice-warning"><p><strong>WP Automator Pro:</strong> WP-Cron is disabled '
       . '(<code>DISABLE_WP_CRON</code> is set in <code>wp-config.php</code>) while Google-Sheet auto-publish is on. '
       . 'Scheduled and auto-published posts fire through WP-Cron, so <strong>nothing will publish automatically</strong> '
       . 'until a real server cron job calls <code>wp-cron.php</code> (every 5&ndash;15 minutes). Ask your host to add one.</p></div>';
}

function wpap_automation_run( $force = false ) {
    @set_time_limit( 300 );
    $auto      = wpap_get_automation();
    $sheet_url = $auto['sheet_url'];

    /* Automation is a licensed-only feature (mirrors the rest of the plugin). */
    if ( ! wpap_is_licensed() ) {
        return wpap_automation_write_status( array( 'message' => 'Plugin not activated.' ) );
    }
    if ( ! $force && ! $auto['enabled'] ) {
        return wpap_automation_write_status( array( 'message' => 'Automation is disabled.' ) );
    }
    if ( '' === $sheet_url ) {
        return wpap_automation_write_status( array( 'message' => 'No Google Sheet CSV URL is configured.' ) );
    }

    /* Atomic mutual exclusion for BOTH cron and manual Run-now via wpap_atomic_lock_acquire()
       (INSERT IGNORE — a true UNIQUE-key CAS; see its note on why add_option() is NOT one), so
       only ONE concurrent run acquires the lock and two runs can't read the same seen-set and
       double-publish. Stale locks (a prior run that died) are reclaimed after 10 minutes. */
    $now = time();
    if ( ! wpap_atomic_lock_acquire( 'wpap_automation_lock', $now ) ) {
        $held = (int) get_option( 'wpap_automation_lock', 0 );
        if ( $held && ( $now - $held ) < 10 * MINUTE_IN_SECONDS ) {
            return wpap_automation_write_status( array( 'message' => 'A run is already in progress — try again shortly.' ) );
        }
        if ( 0 === $held ) {
            /* The lock vanished between our failed add and this read (another run just
               released it). Claim it fresh rather than CAS-deleting a non-existent row
               (which would spuriously report "in progress"). */
            if ( ! wpap_atomic_lock_acquire( 'wpap_automation_lock', $now ) ) {
                return wpap_automation_write_status( array( 'message' => 'A run is already in progress — try again shortly.' ) );
            }
        } else {
            /* Stale lock (a prior run died). Reclaim with a real compare-and-swap: a
               conditional DELETE keyed on the EXACT stale value we observed, so only the
               one racer whose DELETE removed that row may re-INSERT. (delete_option()+
               add_option() is two statements with no compare — two racers could both
               delete-and-reinsert and both proceed, double-publishing.) Bust the options
               cache after the raw DELETE so add_option() checks the real DB state. */
            global $wpdb;
            $removed = $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
                'wpap_automation_lock', (string) $held
            ) );
            wp_cache_delete( 'wpap_automation_lock', 'options' );
            if ( ! $removed || ! wpap_atomic_lock_acquire( 'wpap_automation_lock', $now ) ) {
                return wpap_automation_write_status( array( 'message' => 'A run is already in progress — try again shortly.' ) );
            }
        }
    }
    $locked = true;
    /* Release the lock even on a FATAL (matches the bulk path's shutdown guard),
       but only if this run still owns it — never stomp a lock a later run took. */
    register_shutdown_function( function () use ( $now ) {
        wpap_release_lock_if_owner( 'wpap_automation_lock', $now );
    } );

    /* Per-day budget (resets when the local date rolls over). */
    $today = current_time( 'Ymd' );
    $count = get_option( 'wpap_automation_count', array() );
    if ( ! is_array( $count ) || ( isset( $count['date'] ) ? $count['date'] : '' ) !== $today ) {
        $count = array( 'date' => $today, 'count' => 0 );
    }
    $todays_count    = (int) $count['count'];
    $remaining_today = max( 0, (int) $auto['per_day'] - $todays_count );

    if ( $remaining_today <= 0 ) {
        if ( $locked ) { wpap_release_lock_if_owner( 'wpap_automation_lock', $now ); }
        return wpap_automation_write_status( array(
            'message' => sprintf( 'Daily limit reached (%d/%d).', $todays_count, (int) $auto['per_day'] ),
        ) );
    }

    $rows = wpap_automation_fetch_rows( $sheet_url );
    if ( is_wp_error( $rows ) ) {
        if ( $locked ) { wpap_release_lock_if_owner( 'wpap_automation_lock', $now ); }
        wpap_automation_alert( $auto, 'Could not read the Google Sheet: ' . $rows->get_error_message() );
        return wpap_automation_write_status( array(
            'errors'  => 1,
            'message' => 'Fetch error: ' . $rows->get_error_message(),
        ) );
    }

    /* Author the posts to an administrator (cron has no logged-in user, which
       would author as user 0). We set post_author explicitly rather than
       wp_set_current_user() so we don't mutate the global current user for other
       cron callbacks — and we force kses on the body below because Sheet content
       is external (outside the trust boundary), regardless of author. */
    $author_id = get_current_user_id();
    if ( ! $author_id ) {
        $admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID', 'order' => 'ASC' ) );
        if ( ! empty( $admins ) ) { $author_id = (int) $admins[0]; }
    }

    $rows_found   = count( $rows );
    $budget       = min( (int) $auto['per_run'], $remaining_today );
    $seen         = wpap_automation_get_seen();
    $givenup      = get_option( 'wpap_automation_giveup_keys', array() );   /* durable 3-strike give-ups */
    if ( ! is_array( $givenup ) ) { $givenup = array(); }
    $published    = 0;
    $skipped      = 0;
    $errors       = 0;
    $last_err     = '';
    $attempts     = 0;
    $max_attempts = $budget + 10;
    $deadline     = time() + 240;   /* stop before the 300s time limit to avoid a mid-loop kill */

    foreach ( $rows as $item ) {
        if ( $published >= $budget || $attempts >= $max_attempts || time() > $deadline ) {
            break;
        }
        $key  = wpap_automation_row_key( $item );
        $ckey = wpap_automation_content_key( $item );   /* stable content hash — survives an id-column change */
        /* De-dup on DURABLE state, not just the prunable seen cache: skip if seen (by row key
           OR content key), OR given-up (3-strike, durable), OR a post already carries this
           source key / content key, OR the key is tombstoned as user-deleted. This is what makes
           "published once, never recreated" true — the seen cache alone ages out (120d / 5000
           LRU) and would otherwise republish a long-lived Sheet row as duplicate content and
           resurrect deleted posts (gap #6); the content key additionally survives a Sheet
           `id`-column change that would flip every row key at once. */
        if ( isset( $seen[ $key ] ) || isset( $seen[ $ckey ] )
             || isset( $givenup[ $key ] ) || isset( $givenup[ $ckey ] )
             || wpap_automation_key_is_done( $key ) || wpap_automation_alt_key_is_done( $ckey ) ) {
            $seen[ $key ]  = time();   /* refresh so the durable check isn't re-run forever */
            $seen[ $ckey ] = time();
            $skipped++;
            continue;
        }
        $attempts++;

        /* Isolate a per-row FATAL like the bulk path does: an uncaught Throwable
           inside wpap_publish_article (image decode OOM, a third-party save_post
           hook, etc.) must NOT abort the cron run — otherwise the same poison row
           is re-hit and re-fatals every run, wedging every later row forever. */
        try {
            $result = wpap_publish_article( $item, array(
                'default_parts'    => 1,
                'schedule_window'  => (float) $auto['schedule_window'],
                'default_category' => (string) $auto['default_category'],
                'source_key'       => $key,
                'author'           => $author_id,
                'force_kses'       => true,
            ) );
        } catch ( \Throwable $e ) {
            $errors++;
            $last_err = $e->getMessage();
            error_log( '[Automation Hamri] cron publish crashed on ' . $key . ': ' . $e->getMessage() );
            if ( wpap_automation_bump_failure( $key ) >= 3 ) {
                $seen[ $key ]  = time();
                $seen[ $ckey ] = time();
                wpap_automation_mark_giveup( $key );    /* durable: don't resurrect after the seen cache ages out */
                wpap_automation_mark_giveup( $ckey );   /* also under the stable content key (survives an id-column change) */
                wpap_automation_clear_failure( $key );
                update_option( 'wpap_automation_seen', $seen, false );
            }
            continue;
        }

        if ( is_wp_error( $result ) ) {
            $errors++;
            $last_err = $result->get_error_message();
            /* Retry next run, but give up after 3 failures so a permanently
               bad row can't loop forever. */
            if ( wpap_automation_bump_failure( $key ) >= 3 ) {
                $seen[ $key ]  = time();
                $seen[ $ckey ] = time();
                wpap_automation_mark_giveup( $key );
                wpap_automation_mark_giveup( $ckey );
                wpap_automation_clear_failure( $key );
                update_option( 'wpap_automation_seen', $seen, false );   /* persist the give-up NOW (mirror the Throwable branch): clear_failure already persisted the counter=0, so a crash before the end-of-loop write must not reset the 3-strike cap */
            }
            continue;
        }

        $seen[ $key ]  = time();
        $seen[ $ckey ] = time();
        $published++;
        $todays_count++;
        wpap_automation_clear_failure( $key );
        /* No per-row seen write: a mid-run crash cannot republish this row because
           wpap_publish_article wrote _wpap_source_key atomically (meta_input), so
           wpap_automation_key_is_done() already dedups it durably next run. The seen
           map is persisted once at end-of-loop (below). The give-up branches above DO
           persist immediately — those rows never published, so they carry no source-key
           meta and rely on seen to remember the 3-strike cap across a crash. The daily
           counter is still written per-row so the rate cap survives a crash. */
        update_option( 'wpap_automation_count', array( 'date' => $today, 'count' => $todays_count ), false );
    }

    update_option( 'wpap_automation_seen', wpap_automation_prune_seen( $seen ), false );
    $count['date']  = $today;
    $count['count'] = $todays_count;
    update_option( 'wpap_automation_count', $count, false );

    if ( $published > 0 ) {
        wpap_purge_caches();
    }
    /* Every row in a non-empty run errored → something is wrong (bad columns, permanent image
       failures). Alert the admin (throttled 12h). */
    if ( $rows_found > 0 && 0 === $published && $errors > 0 ) {
        wpap_automation_alert( $auto, sprintf( 'A run found %d row(s) but published none (%d error(s)). Last: %s', $rows_found, $errors, $last_err ) );
    }
    if ( $locked ) { wpap_release_lock_if_owner( 'wpap_automation_lock', $now ); }

    $msg = sprintf( 'Found %d row(s); published %d, skipped %d, error(s) %d.', $rows_found, $published, $skipped, $errors );
    if ( '' !== $last_err ) { $msg .= ' Last error: ' . $last_err; }

    return wpap_automation_write_status( array(
        'rows_found' => $rows_found,
        'published'  => $published,
        'skipped'    => $skipped,
        'errors'     => $errors,
        'message'    => $msg,
    ) );
}

/* Recurring cron → the runner (scheduled in wpap_activate, cleared in wpap_deactivate). */
add_action( 'wpap_automation_cron', 'wpap_automation_run' );

/* AJAX: "Run now" button on the Settings page. */
add_action( 'wp_ajax_wpap_automation_run_now', 'wpap_ajax_automation_run_now' );
function wpap_ajax_automation_run_now() {
    check_ajax_referer( 'wpap_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }
    @set_time_limit( 120 );
    $status = wpap_automation_run( true );
    wp_send_json_success( $status );
}

/* ════════════════════════════════════════════
   10. CLAUDE CONTENT GENERATOR
════════════════════════════════════════════ */
