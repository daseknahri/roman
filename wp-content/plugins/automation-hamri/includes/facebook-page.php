<?php
/**
 * Facebook Page auto-poster (8.86.0). OPT-IN: nothing runs until the owner ticks "Post to my Facebook Page" in
 * Settings → "📘 Facebook Page posting" and saves a Page ID + Page access token.
 *
 * Each post is shared once, in publish order: a PHOTO post (featured image, the post's Facebook hook as the caption),
 * then the FIRST COMMENT carries the link (the post's first-comment template with {{link}} → its UTM-tagged permalink)
 * — the same caption and comment the Distribution Hub exports. Shares are spread through the day: N per day, evenly
 * between two hours (site time), from a 15-minute WP-cron tick, at most one share per tick. A token error pauses the
 * poster and a rate limit backs it off, instead of burning through the queue; a call Facebook never answered is not
 * retried (it may have posted). A pasted user token is swapped for the Page's own token on save. Settings live in
 * `wpap_fbpage`; the token is never printed back, exported, logged or put in a URL.
 *
 * @package Automation_Hamri
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

const WPAP_FBP_GRAPH     = 'https://graph.facebook.com/v25.0/';
const WPAP_FBP_MAX_TRIES = 3;    /* a post Facebook rejects this many times is skipped */
const WPAP_FBP_BACKOFF   = 3 * HOUR_IN_SECONDS;   /* pause after a rate limit / temporary block */
/* Graph error codes: token or permission problems (pause until a new token is saved) and rate limits (back off). */
const WPAP_FBP_AUTH_CODES  = array( 10, 102, 190 );
const WPAP_FBP_LIMIT_CODES = array( 4, 9, 17, 32, 341, 368, 613 );   /* 9 = Instagram's posting limit */
const WPAP_FBP_LOG_MAX   = 30;
const WPAP_FBP_CARD_WAITS = array( 0, 5, 15 );   /* seconds before each read of the link (the host's 429s are short bursts) */
const WPAP_FBP_CRON      = 'wpap_fbp_cron';

/* ── Settings ─────────────────────────────────────────────────────────────────────────────────────────────── */

function wpap_fbp_opts() {
	$o     = get_option( 'wpap_fbpage', array() );
	$o     = is_array( $o ) ? $o : array();
	$start = max( 0, min( 23, (int) ( $o['start'] ?? 9 ) ) );
	$end   = max( 1, min( 24, (int) ( $o['end'] ?? 21 ) ) );
	$end   = $end > $start ? $end : min( 24, $start + 1 );
	return array(
		'enabled' => ! empty( $o['enabled'] ),
		'page_id' => preg_replace( '/\D/', '', (string) ( $o['page_id'] ?? '' ) ),
		'token'   => (string) ( $o['token'] ?? '' ),
		/* at most one share per 15-minute tick inside the window */
		'per_day' => max( 1, min( 24, ( $end - $start ) * 4, (int) ( $o['per_day'] ?? 4 ) ) ),
		'start'   => $start,
		'end'     => $end,
		'since'   => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $o['since'] ?? '' ) ) ? (string) $o['since'] : '',
		'backlog' => ! empty( $o['backlog'] ),
		'format'  => ( 'link' === ( $o['format'] ?? 'photo' ) ) ? 'link' : 'photo',
	);
}

/* Called from the main settings save handler (same nonce + capability check). */
function wpap_fbp_save_from_post() {
	$old      = wpap_fbp_opts();
	$token_in = preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_POST['wpap_fbp_token'] ?? '' ) );   // phpcs:ignore WordPress.Security.NonceVerification -- verified by the caller
	$token    = ! empty( $_POST['wpap_fbp_token_clear'] ) ? '' : ( '' !== $token_in ? $token_in : $old['token'] );   // phpcs:ignore WordPress.Security.NonceVerification
	$enabled  = isset( $_POST['wpap_fbp_enabled'] );                                                             // phpcs:ignore WordPress.Security.NonceVerification
	$page_id  = substr( preg_replace( '/\D/', '', (string) wp_unslash( $_POST['wpap_fbp_page_id'] ?? '' ) ), 0, 30 );   // phpcs:ignore WordPress.Security.NonceVerification
	$since    = sanitize_text_field( wp_unslash( $_POST['wpap_fbp_since'] ?? '' ) );                            // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $since ) ) { $since = $old['since']; }
	if ( '' === $since ) { $since = current_datetime()->format( 'Y-m-d' ); }   /* default: from today */
	if ( '' !== $token_in && '' !== $page_id ) { $token = wpap_fbp_page_token( $page_id, $token_in ); }
	update_option( 'wpap_fbpage', array(
		'enabled' => $enabled ? 1 : 0,
		'page_id' => $page_id,
		'token'   => $token,
		'per_day' => (int) ( $_POST['wpap_fbp_per_day'] ?? 4 ),                                                  // phpcs:ignore WordPress.Security.NonceVerification
		'start'   => (int) ( $_POST['wpap_fbp_start'] ?? 9 ),                                                    // phpcs:ignore WordPress.Security.NonceVerification
		'end'     => (int) ( $_POST['wpap_fbp_end'] ?? 21 ),                                                     // phpcs:ignore WordPress.Security.NonceVerification
		'since'   => $since,
		'backlog' => isset( $_POST['wpap_fbp_backlog'] ) ? 1 : 0,                                               // phpcs:ignore WordPress.Security.NonceVerification
		'format'  => ( 'link' === ( $_POST['wpap_fbp_format'] ?? '' ) ) ? 'link' : 'photo',                     // phpcs:ignore WordPress.Security.NonceVerification
	), false );
	delete_option( 'wpap_fbp_paused' );   /* saving settings (e.g. a fresh token) resumes a paused poster */
	delete_transient( 'wpap_fbp_backoff' );
	wpap_fbp_schedule( wpap_fbp_cron_wanted() );
}

/* The tick runs while Facebook, Instagram or Pinterest posting is on (they share the schedule). */
function wpap_fbp_cron_wanted() {
	return wpap_fbp_opts()['enabled'] || ( function_exists( 'wpap_igp_opts' ) && wpap_igp_opts()['enabled'] )
		|| ( function_exists( 'wpap_pinp_opts' ) && wpap_pinp_opts()['enabled'] );
}

/* The owner may paste a USER token (from the Graph API Explorer or the Access Token Debugger). Ask Facebook for this
   Page's own token with it: a Page token made from a long-lived user token never expires. When the pasted token is
   already a Page token (or the lookup fails), it is kept as pasted. */
function wpap_fbp_page_token( $page_id, $token ) {
	$r = wpap_fbp_call( $page_id, array( 'fields' => 'name,access_token' ), $token, 'GET' );
	if ( is_wp_error( $r ) ) { return $token; }
	if ( ! empty( $r['name'] ) ) { update_option( 'wpap_fbp_page_name', sanitize_text_field( (string) $r['name'] ), false ); }
	$page_token = preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $r['access_token'] ?? '' ) );
	return '' !== $page_token ? $page_token : $token;
}

/* Self-heal: the tick is cleared on deactivation (or by a cron-cleanup plugin) but the poster may still be on. */
add_action( 'init', function () {
	if ( wpap_fbp_cron_wanted() && ! wp_next_scheduled( WPAP_FBP_CRON ) ) { wpap_fbp_schedule( true ); }
} );

/* ── Cron: a 15-minute tick while the poster is on ────────────────────────────────────────────────────────── */

add_filter( 'cron_schedules', function ( $s ) {
	$s['wpap_15min'] = array( 'interval' => 15 * MINUTE_IN_SECONDS, 'display' => 'Every 15 minutes (Automation Hamri)' );
	return $s;
} );

function wpap_fbp_schedule( $on ) {
	$next = wp_next_scheduled( WPAP_FBP_CRON );
	if ( $on && ! $next ) { wp_schedule_event( time() + MINUTE_IN_SECONDS, 'wpap_15min', WPAP_FBP_CRON ); }
	if ( ! $on && $next ) { wp_clear_scheduled_hook( WPAP_FBP_CRON ); }
}

add_action( WPAP_FBP_CRON, 'wpap_fbp_tick' );

/* The Page connection works: a Page and token are saved and Facebook hasn't paused or throttled us. */
function wpap_fbp_connected( $o ) {
	return '' !== $o['page_id'] && '' !== $o['token'] && ! get_option( 'wpap_fbp_paused' ) && ! get_transient( 'wpap_fbp_backoff' );
}

function wpap_fbp_ready( $o ) {
	return $o['enabled'] && wpap_fbp_connected( $o );
}

/* One tick: at most one Facebook share and one Instagram post, each only when a slot is due. */
function wpap_fbp_tick() {
	$o = wpap_fbp_opts();
	if ( wpap_fbp_ready( $o ) && wpap_fbp_shares_today() < wpap_fbp_slots_due( $o ) ) {
		$pid = wpap_fbp_next_post( $o );
		if ( $pid ) { wpap_fbp_share( $pid, $o ); }
	}
	if ( function_exists( 'wpap_igp_tick' ) ) { wpap_igp_tick( $o ); }
	if ( function_exists( 'wpap_pinp_tick' ) ) { wpap_pinp_tick( $o ); }
}

/* How many shares are due by now today: slot 1 at the start hour, then every (window / per_day). Outside the window
   nothing is due, so a missed slot never spills into the night. */
function wpap_fbp_slots_due( $o, $now = null ) {
	$now  = $now ? $now : current_datetime();
	$mins = (int) $now->format( 'G' ) * 60 + (int) $now->format( 'i' );
	$from = $o['start'] * 60;
	if ( $mins < $from || $mins >= $o['end'] * 60 ) { return 0; }
	$gap = ( $o['end'] * 60 - $from ) / $o['per_day'];
	return (int) min( $o['per_day'], floor( ( $mins - $from ) / $gap ) + 1 );
}

function wpap_fbp_shares_today( $opt = 'wpap_fbp_day' ) {
	$d = get_option( $opt, array() );
	return ( is_array( $d ) && ( $d['day'] ?? '' ) === current_datetime()->format( 'Y-m-d' ) ) ? (int) ( $d['n'] ?? 0 ) : 0;
}

function wpap_fbp_count_share( $opt = 'wpap_fbp_day' ) {
	update_option( $opt, array( 'day' => current_datetime()->format( 'Y-m-d' ), 'n' => wpap_fbp_shares_today( $opt ) + 1 ), false );
}

/* ── The queue: published posts not yet shared — from the start date oldest-first, then (optionally) older ones.
   $key = the "done" meta of the channel (_wpap_fbp_done for Facebook, _wpap_igp_done for Instagram). ── */

function wpap_fbp_queue_args( $o, $newer, $key = '_wpap_fbp_done' ) {
	$args = array( 'post_type' => 'post', 'post_status' => 'publish', 'fields' => 'ids', 'no_found_rows' => true,
		'ignore_sticky_posts' => true, 'orderby' => 'date', 'order' => $newer ? 'ASC' : 'DESC',
		'meta_query' => array( array( 'key' => $key, 'compare' => 'NOT EXISTS' ) ) );   // phpcs:ignore WordPress.DB.SlowDBQuery
	$since = '' !== $o['since'] ? $o['since'] : current_datetime()->format( 'Y-m-d' );   /* no date saved yet = today */
	$args['date_query'] = array( array( ( $newer ? 'after' : 'before' ) => $since . ' 00:00:00', 'inclusive' => $newer ) );
	return $args;
}

function wpap_fbp_next_post( $o, $key = '_wpap_fbp_done' ) {
	$ids = get_posts( wpap_fbp_queue_args( $o, true, $key ) + array( 'posts_per_page' => 1 ) );
	if ( ! $ids && $o['backlog'] ) { $ids = get_posts( wpap_fbp_queue_args( $o, false, $key ) + array( 'posts_per_page' => 1 ) ); }
	return $ids ? (int) $ids[0] : 0;
}

function wpap_fbp_queue_count( $o, $key = '_wpap_fbp_done' ) {
	$q = new WP_Query( array_merge( wpap_fbp_queue_args( $o, true, $key ), array( 'posts_per_page' => 1, 'no_found_rows' => false ) ) );
	$n = (int) $q->found_posts;
	if ( $o['backlog'] ) {
		$b  = new WP_Query( array_merge( wpap_fbp_queue_args( $o, false, $key ), array( 'posts_per_page' => 1, 'no_found_rows' => false ) ) );
		$n += (int) $b->found_posts;
	}
	return $n;
}

/* ── Sharing one post ─────────────────────────────────────────────────────────────────────────────────────── */

/* The caption, first comment, link and image for one post — the Distribution Hub's Facebook data. */
/* The link Facebook gets for one post (its UTM-tagged public permalink). */
function wpap_fbp_link( $pid ) {
	return function_exists( 'wpap_apply_utm' ) ? wpap_apply_utm( wpap_public_permalink( $pid ), $pid ) : get_permalink( $pid );
}

function wpap_fbp_payload( $pid ) {
	$link = wpap_fbp_link( $pid );
	$hook = html_entity_decode( wp_strip_all_tags( (string) get_post_meta( $pid, '_wpap_fb_hook', true ) ), ENT_QUOTES, 'UTF-8' );
	$hook = trim( str_replace( '{{link}}', '', $hook ) );
	if ( '' === $hook ) { $hook = html_entity_decode( get_the_title( $pid ), ENT_QUOTES, 'UTF-8' ); }
	$img = (string) get_the_post_thumbnail_url( $pid, 'full' );
	if ( '' === $img ) { $img = (string) get_post_meta( $pid, '_wpap_image_url', true ); }
	return array(
		'caption' => $hook,
		'comment' => wpap_compose_fb_comment( $link, $pid ),
		'link'    => $link,
		'image'   => wp_http_validate_url( $img ) ? $img : '',
		'file'    => wpap_fbp_image_file( (int) get_post_thumbnail_id( $pid ) ),
	);
}

/* The featured image as a file on this server (uploaded directly, so Facebook never has to fetch it from a host
   that may rate-limit its crawler). Uses the "large" size when the original is over Facebook's 4 MB sweet spot. */
function wpap_fbp_image_file( $att_id ) {
	if ( ! $att_id ) { return ''; }
	$file = (string) get_attached_file( $att_id );
	if ( '' === $file || ! is_readable( $file ) ) { return ''; }
	if ( filesize( $file ) > 4 * MB_IN_BYTES ) {
		$large = image_get_intermediate_size( $att_id, 'large' );
		$path  = ! empty( $large['path'] ) ? path_join( wp_get_upload_dir()['basedir'], $large['path'] ) : '';
		$file  = ( '' !== $path && is_readable( $path ) ) ? $path : '';
	}
	return $file;
}

/* Share one post now. Marks it before calling Facebook so a crash mid-call can never post it twice. What happens on
   an error depends on its kind:
     fbp_auth    token / permission → pause the whole poster (post stays queued) until a new token is saved
     fbp_limit   rate limit / temporary block → back off for a few hours (post stays queued, no try used)
     fbp_network no answer from Facebook: the post MAY already be up → mark it "unknown", never retried by itself
     fbp_api     Facebook refused this post → retried on later slots, skipped after WPAP_FBP_MAX_TRIES */
function wpap_fbp_share( $pid, $o ) {
	$pid = (int) $pid;
	/* claim the post first: a click during the cron tick (or a double click) can't share it twice */
	if ( ! add_post_meta( $pid, '_wpap_fbp_done', 'pending', true ) ) {
		return new WP_Error( 'fbp_busy', 'This post is already being shared.' );
	}
	$res = wpap_fbp_publish( wpap_fbp_payload( $pid ), $o );
	if ( is_wp_error( $res ) ) { return wpap_fbp_fail( $pid, $res, 'fbp', 'Facebook' ); }
	wpap_fbp_count_share();   /* only successful shares use up the day's slots */
	update_post_meta( $pid, '_wpap_fbp_done', time() );
	update_post_meta( $pid, '_wpap_fbp_post_id', $res['post_id'] );
	$msg = '' !== $res['comment_error'] ? 'posted; first comment failed: ' . $res['comment_error'] : ( $res['commented'] ? 'posted with first comment' : 'posted' );
	if ( ! $res['card_ok'] ) { $msg .= '; the link preview has no title (' . $res['card_error'] . ')'; }
	wpap_fbp_log( $pid, 'ok', $msg );
	return $res;
}

/* ── Link preview (the card under the link) ─────────────────────────────────────────────────────────────────
   Facebook reads a page once, when its link is posted, and the card keeps that first reading: reading the page again
   later does not update a card already posted (and editing the comment removes the card). If the host turns Facebook's
   crawler away at that moment (HTTP 429 "too many requests"), the card shows only the domain. So the link is read on
   purpose BEFORE it is posted, a few times over ~20 seconds until it comes back with a title. These calls never pause or
   back off the poster; the post still goes out if the page can't be read. */

/* Ask Facebook to read one link. True when it came back with a real title, else a WP_Error. */
function wpap_fbp_scrape( $link, $token ) {
	$r = wpap_fbp_call( '', array( 'id' => $link, 'scrape' => 'true' ), $token );
	if ( is_wp_error( $r ) ) { return $r; }
	$title = trim( (string) ( $r['title'] ?? '' ) );
	$host  = (string) wp_parse_url( $link, PHP_URL_HOST );
	if ( '' === $title || 0 === strcasecmp( $title, $host ) || 0 === strcasecmp( $title, $link ) ) {
		return new WP_Error( 'fbp_card', 'Facebook could not read the page' );
	}
	return true;
}

/* Read the link until the card has a title, waiting WPAP_FBP_CARD_WAITS between tries. A refusal of the call itself
   (token, permission) is not retried. */
function wpap_fbp_card_ready( $link, $token ) {
	$r = new WP_Error( 'fbp_card', 'not read' );
	foreach ( (array) apply_filters( 'wpap_fbp_card_waits', WPAP_FBP_CARD_WAITS ) as $wait ) {
		if ( $wait > 0 ) { sleep( (int) $wait ); }
		$r = wpap_fbp_scrape( $link, $token );
		if ( true === $r || 'fbp_card' !== $r->get_error_code() ) { break; }
	}
	return $r;
}

/* Record a failed share for one channel ($ch = 'fbp' Facebook / 'igp' Instagram) — see wpap_fbp_share. Each channel
   pauses and backs off on its own (wpap_<ch>_paused / wpap_<ch>_backoff), so an Instagram-only problem never stops
   the Facebook shares. */
function wpap_fbp_fail( $pid, WP_Error $res, $ch, $label ) {
	$code = $res->get_error_code();
	$msg  = $res->get_error_message();
	delete_post_meta( $pid, '_wpap_' . $ch . '_done' );
	if ( 'fbp_auth' === $code ) {
		update_option( 'wpap_' . $ch . '_paused', $msg, false );
	} elseif ( 'fbp_limit' === $code ) {
		set_transient( 'wpap_' . $ch . '_backoff', 1, WPAP_FBP_BACKOFF );
		$msg .= ' (pausing for 3 hours)';
	} elseif ( 'fbp_network' === $code ) {
		update_post_meta( $pid, '_wpap_' . $ch . '_done', 'unknown' );
		$msg .= ' Not retried, to avoid a duplicate: check ' . $label . ' and post it by hand if it is missing.';
	} else {
		$tries = (int) get_post_meta( $pid, '_wpap_' . $ch . '_tries', true ) + 1;
		update_post_meta( $pid, '_wpap_' . $ch . '_tries', $tries );
		if ( $tries >= WPAP_FBP_MAX_TRIES ) { update_post_meta( $pid, '_wpap_' . $ch . '_done', 'failed' ); }
	}
	wpap_fbp_log( $pid, 'error', ( 'fbp' === $ch ? '' : $label . ': ' ) . $msg );
	return new WP_Error( $code, $msg );
}

function wpap_fbp_publish( $d, $o ) {
	if ( 'photo' === $o['format'] && ( '' !== $d['file'] || '' !== $d['image'] ) ) {
		$r = '' !== $d['file']
			? wpap_fbp_upload( $o['page_id'] . '/photos', array( 'caption' => $d['caption'] ), $d['file'], $o['token'] )
			: wpap_fbp_call( $o['page_id'] . '/photos', array( 'url' => $d['image'], 'caption' => $d['caption'] ), $o['token'] );
		if ( is_wp_error( $r ) ) { return $r; }
		$target = (string) ( $r['post_id'] ?? ( $r['id'] ?? '' ) );
		$card   = wpap_fbp_card_ready( $d['link'], $o['token'] );   /* read the link first, so the comment's card is complete */
		$c      = '' !== $target ? wpap_fbp_call( $target . '/comments', array( 'message' => $d['comment'] ), $o['token'] ) : new WP_Error( 'fbp_api', 'no post id' );
		return array( 'post_id' => $target, 'comment_error' => is_wp_error( $c ) ? $c->get_error_message() : '', 'card_ok' => true === $card || is_wp_error( $c ), 'card_error' => is_wp_error( $card ) ? $card->get_error_message() : '', 'commented' => true );
	}
	/* Link post (or a post with no image): the link preview card carries the click, so no comment is added. */
	$card = wpap_fbp_card_ready( $d['link'], $o['token'] );
	$r    = wpap_fbp_call( $o['page_id'] . '/feed', array( 'message' => $d['caption'], 'link' => $d['link'] ), $o['token'] );
	if ( is_wp_error( $r ) ) { return $r; }
	return array( 'post_id' => (string) ( $r['id'] ?? '' ), 'comment_error' => '', 'card_ok' => true === $card, 'card_error' => is_wp_error( $card ) ? $card->get_error_message() : '', 'commented' => false );
}

/* One Graph API call (errors: see wpap_fbp_parse). The token goes in the Authorization header on reads and in the
   POST body on writes, never in a URL, so HTTP debug logs don't record it. */
function wpap_fbp_call( $path, $params, $token, $method = 'POST' ) {
	$url = (string) apply_filters( 'wpap_fbp_graph_base', WPAP_FBP_GRAPH ) . ltrim( $path, '/' );
	if ( 'GET' === $method || 'DELETE' === $method ) {
		$res = wp_remote_request( add_query_arg( array_map( 'rawurlencode', $params ), $url ),
			array( 'method' => $method, 'timeout' => 30, 'headers' => array( 'Authorization' => 'Bearer ' . $token ) ) );
	} else {
		$res = wp_remote_post( $url, array( 'timeout' => 45, 'body' => array_merge( $params, array( 'access_token' => $token ) ) ) );
	}
	return wpap_fbp_parse( $res );
}

/* A multipart upload of one local image file (the `source` field) plus text fields. */
function wpap_fbp_upload( $path, $fields, $file, $token ) {
	$boundary = 'wpap' . wp_generate_password( 20, false );
	$body     = '';
	foreach ( array_merge( $fields, array( 'access_token' => $token ) ) as $k => $v ) {
		$body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"" . $k . "\"\r\n\r\n" . $v . "\r\n";
	}
	$type  = wp_check_filetype( $file )['type'];
	$body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"source\"; filename=\"" . sanitize_file_name( basename( $file ) ) . "\"\r\n"
		. 'Content-Type: ' . ( $type ? $type : 'image/jpeg' ) . "\r\n\r\n" . file_get_contents( $file ) . "\r\n--" . $boundary . "--\r\n";   // phpcs:ignore WordPress.WP.AlternativeFunctions -- local upload file
	$url = (string) apply_filters( 'wpap_fbp_graph_base', WPAP_FBP_GRAPH ) . ltrim( $path, '/' );
	return wpap_fbp_parse( wp_remote_post( $url, array( 'timeout' => 60, 'body' => $body,
		'headers' => array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ) ) ) );
}

/* A Graph reply → its JSON, or a WP_Error whose code says what to do (see wpap_fbp_share). */
function wpap_fbp_parse( $res ) {
	if ( is_wp_error( $res ) ) { return new WP_Error( 'fbp_network', 'No answer from Facebook: ' . $res->get_error_message() . '.' ); }
	$http = (int) wp_remote_retrieve_response_code( $res );
	$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	if ( is_array( $json ) && isset( $json['error'] ) ) {
		$code = (int) ( $json['error']['code'] ?? 0 );
		$msg  = sanitize_text_field( (string) ( $json['error']['message'] ?? 'Facebook error' ) ) . ' (code ' . $code . ')';
		if ( in_array( $code, WPAP_FBP_AUTH_CODES, true ) || ( $code >= 200 && $code <= 299 ) ) { return new WP_Error( 'fbp_auth', $msg ); }
		if ( in_array( $code, WPAP_FBP_LIMIT_CODES, true ) ) { return new WP_Error( 'fbp_limit', $msg ); }
		return new WP_Error( 'fbp_api', $msg );
	}
	if ( ! is_array( $json ) ) {
		/* a 5xx or a garbled reply may still have created the post, so treat it like no answer */
		return new WP_Error( $http >= 500 || 0 === $http ? 'fbp_network' : 'fbp_api', 'Unexpected reply from Facebook (HTTP ' . $http . ').' );
	}
	return $json;
}

function wpap_fbp_log( $pid, $status, $msg ) {
	$log = get_option( 'wpap_fbp_log', array() );
	$log = is_array( $log ) ? $log : array();
	array_unshift( $log, array( 't' => time(), 'pid' => (int) $pid, 's' => $status, 'm' => mb_substr( (string) $msg, 0, 300 ) ) );
	update_option( 'wpap_fbp_log', array_slice( $log, 0, WPAP_FBP_LOG_MAX ), false );
}

/* ── Admin buttons: test the connection, share the next post now ──────────────────────────────────────────── */

add_action( 'wp_ajax_wpap_fbp_test', function () {
	if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Unauthorized', 403 ); }
	check_ajax_referer( 'wpap_fbp', 'nonce' );
	$o = wpap_fbp_opts();
	if ( '' === $o['page_id'] || '' === $o['token'] ) { wp_send_json_error( 'Save a Page ID and a Page access token first.' ); }
	/* A user token saved before the Page ID was known is swapped for the Page token here. */
	$page_token = wpap_fbp_page_token( $o['page_id'], $o['token'] );
	if ( $page_token !== $o['token'] ) {
		$raw          = (array) get_option( 'wpap_fbpage', array() );
		$raw['token'] = $page_token;
		update_option( 'wpap_fbpage', $raw, false );
		$o['token'] = $page_token;
	}
	$r = wpap_fbp_call( $o['page_id'], array( 'fields' => 'name' ), $o['token'], 'GET' );
	if ( is_wp_error( $r ) ) { wp_send_json_error( $r->get_error_message() ); }
	update_option( 'wpap_fbp_page_name', sanitize_text_field( (string) ( $r['name'] ?? '' ) ), false );
	$ig = '';
	if ( function_exists( 'wpap_igp_account' ) ) {
		$acc = wpap_igp_account( $o );
		$ig  = is_wp_error( $acc ) ? ' Instagram: not linked yet.' : ( '' !== wpap_igp_opts()['ig_user'] ? ' Instagram: @' . wpap_igp_opts()['ig_user'] . '.' : ' Instagram: linked.' );
	}
	wp_send_json_success( 'Connected to the Page "' . sanitize_text_field( (string) ( $r['name'] ?? '' ) ) . '".' . $ig );
} );

add_action( 'wp_ajax_wpap_fbp_share_now', function () {
	if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Unauthorized', 403 ); }
	check_ajax_referer( 'wpap_fbp', 'nonce' );
	$o = wpap_fbp_opts();
	if ( '' === $o['page_id'] || '' === $o['token'] ) { wp_send_json_error( 'Save a Page ID and a Page access token first.' ); }
	$pid = wpap_fbp_next_post( $o );
	if ( ! $pid ) { wp_send_json_error( 'Nothing waiting to be shared.' ); }
	$r = wpap_fbp_share( $pid, $o );
	if ( is_wp_error( $r ) ) { wp_send_json_error( $r->get_error_message() ); }
	wp_send_json_success( 'Shared "' . get_the_title( $pid ) . '"' . ( '' !== $r['comment_error'] ? ' (first comment failed: ' . $r['comment_error'] . ')' : ' with its first comment.' ) );
} );

require_once __DIR__ . '/facebook-page-admin.php';
