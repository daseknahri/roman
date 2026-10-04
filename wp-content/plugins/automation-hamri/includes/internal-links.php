<?php
/**
 * Internal linking — the passive build's cross-linking engine, ported to the active plugin.
 *
 * THREE cooperating mechanisms (the third — the curated `related` widget — already lives in the
 * importer + theme; this module adds the two IN-CONTENT ones that lift crawl depth / pages-per-session,
 * the internal-linking signal Google and AdSense reward):
 *
 *   A. WRITER TOKENS — `[[link:slug]]` or `[[link:slug|anchor text]]` placed in the body. On publish each
 *      becomes a real <a href> when a PUBLISHED post owns that slug; a target not yet live becomes a
 *      reader-INVISIBLE, kses-safe marker (<a class="wpap-ilink" data-wpap-ilink="slug"> — no href, so it
 *      renders as plain anchor text) that the "Resolve internal links" pass upgrades once the target
 *      goes live. Forward references across a batch therefore self-heal.
 *
 *   B. AUTO KEYWORD LINKING — each post registers phrases it should be the link TARGET for via its
 *      top-level `keywords` (stored as _wpap_keywords). The pass then links the first eligible mention of
 *      each phrase, in OTHER posts, to that post — cross-linking the whole catalogue by topic. Bounded,
 *      idempotent, tag-aware (never links inside an existing <a>, a heading, or code/pre/figure).
 *
 * Both bake links into stored post_content by a deliberate admin action (a button, or the auto-run at the
 * end of a bulk publish) — NOTHING runs on the front-end hot path. Every write is idempotent, so re-runs
 * are safe. This is NOT the AI link injector (that is generation-time and lives in ai-content.php).
 *
 * Load order is fixed by the main file; every hook self-registers here.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/* ══════════════════════════════════════════════════════════════════════════════════════════════
   A. WRITER TOKENS  [[link:slug|anchor]]
══════════════════════════════════════════════════════════════════════════════════════════════ */

/* Allow the forward-ref marker's data attr + class through kses, but ONLY while WE are sanitizing our
   own content (the importer sets $GLOBALS['wpap_ilink_kses'] around its kses pass). */
add_filter( 'wp_kses_allowed_html', 'wpap_ilink_kses_allow', 10, 2 );
function wpap_ilink_kses_allow( $allowed, $context ) {
	if ( 'post' === $context && ! empty( $GLOBALS['wpap_ilink_kses'] ) && isset( $allowed['a'] ) ) {
		$allowed['a']['data-wpap-ilink'] = true;
		$allowed['a']['class']           = true;
	}
	return $allowed;
}

/* Resolve a slug → the PUBLISHED post's [permalink, title, id], or false. */
function wpap_ilink_lookup( $slug ) {
	$slug = sanitize_title( (string) $slug );
	if ( '' === $slug ) { return false; }
	$q = new WP_Query( array(
		'name'                => $slug,
		'post_type'           => 'post',
		'post_status'         => 'publish',
		'posts_per_page'      => 1,
		'no_found_rows'       => true,
		'ignore_sticky_posts' => true,
		'fields'              => 'ids',
	) );
	if ( empty( $q->posts ) ) { return false; }
	$pid = (int) $q->posts[0];
	return array( (string) wpap_public_permalink( $pid ), (string) get_the_title( $pid ), $pid );
}

/* Turn [[link:slug|anchor]] tokens into links. $self_slug skips a self-link; &$pending returns how many
   still-unresolved (forward-ref) markers were left behind. */
function wpap_resolve_internal_links( $content, $self_slug = '', &$pending = 0 ) {
	$content = (string) $content;
	$pending = 0;
	if ( false === strpos( $content, '[[link:' ) ) { return $content; }
	$self_slug = sanitize_title( (string) $self_slug );
	return (string) preg_replace_callback(
		'#\[\[\s*link:\s*([A-Za-z0-9\-_/]+)\s*(?:\|\s*([^\]]*?))?\s*\]\]#',
		function ( $m ) use ( $self_slug, &$pending ) {
			$slug   = sanitize_title( $m[1] );
			$anchor = isset( $m[2] ) ? trim( (string) $m[2] ) : '';
			if ( '' === $slug ) { return esc_html( $anchor ); }                              /* malformed → plain text */
			if ( '' !== $self_slug && $slug === $self_slug ) { return esc_html( $anchor ); }  /* no self-link */
			$t = wpap_ilink_lookup( $slug );
			if ( is_array( $t ) ) {
				if ( '' === $anchor ) { $anchor = (string) $t[1]; }                          /* default anchor = target title */
				return '<a href="' . esc_url( $t[0] ) . '">' . esc_html( $anchor ) . '</a>';
			}
			if ( '' === $anchor ) { $anchor = $slug; }
			$pending++;
			return '<a class="wpap-ilink" data-wpap-ilink="' . esc_attr( $slug ) . '">' . esc_html( $anchor ) . '</a>';
		},
		$content
	);
}

/* Re-resolution pass: find posts still carrying wpap-ilink markers and link any whose target is NOW live.
   Bounded batch. Returns [ scanned, linked ]. */
function wpap_resolve_pending_ilinks( $limit = 300 ) {
	global $wpdb;
	$limit = max( 1, min( 2000, (int) $limit ) );
	$ids   = $wpdb->get_col( $wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts}
		  WHERE post_type = 'post' AND post_status IN ('publish','future','draft','pending')
		    AND post_content LIKE %s LIMIT %d",
		'%data-wpap-ilink%', $limit
	) );
	$scanned = 0;
	$linked  = 0;
	foreach ( array_map( 'intval', (array) $ids ) as $pid ) {
		if ( $pid <= 0 ) { continue; }
		$scanned++;
		$post = get_post( $pid );
		if ( ! $post ) { continue; }
		$before = (string) $post->post_content;
		$after  = (string) preg_replace_callback(
			'#<a class="wpap-ilink" data-wpap-ilink="([a-z0-9\-_/]+)">(.*?)</a>#s',
			function ( $m ) use ( &$linked ) {
				$t = wpap_ilink_lookup( $m[1] );
				if ( is_array( $t ) ) { $linked++; return '<a href="' . esc_url( $t[0] ) . '">' . $m[2] . '</a>'; }
				return $m[0];   /* target still not live — keep the marker for next time */
			},
			$before
		);
		if ( $after !== $before ) {
			/* Raw update: we only replaced our own kses-clean markers with a plain <a href> (esc_url'd). */
			$wpdb->update( $wpdb->posts, array( 'post_content' => $after ), array( 'ID' => $pid ) );
			clean_post_cache( $pid );
		}
	}
	return array( $scanned, $linked );
}

/* ══════════════════════════════════════════════════════════════════════════════════════════════
   B. AUTO KEYWORD LINKING  (a manual admin pass, also auto-run after a bulk publish)
══════════════════════════════════════════════════════════════════════════════════════════════ */

/* Build [ phrase_lc => ['kw'=>display, 'pid'=>target, 'permalink'=>url, 're'=>regex] ], longest phrase
   first, oldest post wins a shared phrase. */
function wpap_keyword_index() {
	global $wpdb;
	$rows = $wpdb->get_results(
		"SELECT pm.post_id, pm.meta_value
		   FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		  WHERE pm.meta_key = '_wpap_keywords' AND p.post_type = 'post' AND p.post_status = 'publish'
		  ORDER BY pm.post_id ASC",
		ARRAY_A
	);
	if ( ! is_array( $rows ) ) { return array(); }
	$by_phrase = array();
	foreach ( $rows as $r ) {
		$pid = (int) $r['post_id'];
		foreach ( array_filter( array_map( 'trim', explode( ',', (string) $r['meta_value'] ) ), 'strlen' ) as $kw ) {
			$lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $kw ) : strtolower( $kw );
			if ( mb_strlen( $lc ) < 3 ) { continue; }                 /* skip trivially short phrases */
			if ( ! isset( $by_phrase[ $lc ] ) ) { $by_phrase[ $lc ] = array( 'kw' => $kw, 'pid' => $pid ); }
		}
	}
	if ( empty( $by_phrase ) ) { return array(); }
	/* Prime permalinks for the target ids in one shot. */
	$pids = array_values( array_unique( array_map( function ( $e ) { return $e['pid']; }, $by_phrase ) ) );
	foreach ( array_chunk( $pids, 100 ) as $ch ) { _prime_post_caches( $ch, false, false ); }
	$entries = array();
	foreach ( $by_phrase as $lc => $e ) {
		$entries[] = array(
			'kw'        => $e['kw'],
			'pid'       => $e['pid'],
			'permalink' => (string) wpap_public_permalink( $e['pid'] ),
			're'        => '/\b' . preg_quote( $e['kw'], '/' ) . '\b/iu',
		);
	}
	/* Longest phrase first so "gut health" links before "health". */
	usort( $entries, function ( $a, $b ) { return mb_strlen( $b['kw'] ) - mb_strlen( $a['kw'] ); } );
	return $entries;
}

/* Link keyword phrases into ONE post's content (tag-aware, bounded, idempotent). Returns [content, added]. */
function wpap_autolink_content( $content, $entries, $self_id, $max_links = 4 ) {
	$content = (string) $content;
	if ( empty( $entries ) || '' === $content ) { return array( $content, 0 ); }
	/* Pre-seed: a target already linked in this content is off-limits (idempotent re-runs, and honors an
	   explicit [[link]] the writer already placed). */
	$used = array();
	foreach ( $entries as $e ) {
		if ( (int) $e['pid'] === (int) $self_id ) { $used[ (int) $e['pid'] ] = true; continue; }
		if ( '' !== $e['permalink'] && false !== strpos( $content, 'href="' . $e['permalink'] ) ) { $used[ (int) $e['pid'] ] = true; }
	}
	$parts = preg_split( '/(<[^>]+>)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE );
	if ( ! is_array( $parts ) ) { return array( $content, 0 ); }
	$in_a = 0; $in_skip = 0; $added = 0;
	foreach ( $parts as $i => $part ) {
		if ( '' === $part ) { continue; }
		if ( '<' === $part[0] ) {
			if ( preg_match( '#^<a\b#i', $part ) )                                          { $in_a++; }
			elseif ( preg_match( '#^</a>#i', $part ) )                                      { $in_a = max( 0, $in_a - 1 ); }
			elseif ( preg_match( '#^<(h[1-6]|script|style|code|pre|figure)\b#i', $part ) )  { $in_skip++; }
			elseif ( preg_match( '#^</(h[1-6]|script|style|code|pre|figure)>#i', $part ) )  { $in_skip = max( 0, $in_skip - 1 ); }
			continue;
		}
		if ( $in_a > 0 || $in_skip > 0 || $added >= $max_links ) { continue; }
		/* Scan this text run left-to-right, matching ONLY against not-yet-linked text. Injected
		   <a>…</a> markup accumulates in $out and is NEVER re-scanned, so a later phrase can't land
		   inside an earlier link's href — that intra-part re-injection was the bug that produced nested,
		   broken anchors (e.g. href="…lemon-and-<a href="…">baking</a>-soda-fix/"). Longest-phrase-first
		   priority is preserved by iterating $entries (already longest-first) and, each round, taking the
		   earliest match (ties keep the longer, earlier-listed entry). */
		$out  = '';
		$rest = $part;
		while ( $added < $max_links && '' !== $rest ) {
			$best_off = -1; $best_entry = null; $best_match = '';
			foreach ( $entries as $e ) {
				if ( isset( $used[ (int) $e['pid'] ] ) || '' === $e['permalink'] ) { continue; }
				if ( preg_match( $e['re'], $rest, $mm, PREG_OFFSET_CAPTURE ) ) {
					$off = (int) $mm[0][1];
					if ( $best_off < 0 || $off < $best_off ) {
						$best_off = $off; $best_entry = $e; $best_match = (string) $mm[0][0];
					}
				}
			}
			if ( null === $best_entry ) { break; }
			$out .= substr( $rest, 0, $best_off )
			      . '<a href="' . esc_url( $best_entry['permalink'] ) . '">' . $best_match . '</a>';
			$rest = substr( $rest, $best_off + strlen( $best_match ) );
			$used[ (int) $best_entry['pid'] ] = true;
			$added++;
		}
		$parts[ $i ] = $out . $rest;
	}
	return array( implode( '', $parts ), $added );
}

/* The pass: auto-link keyword phrases across posts. Returns [ scanned, linked_posts, links_added ]. */
function wpap_auto_keyword_link_run( $limit = 300 ) {
	global $wpdb;
	$limit   = max( 1, min( 2000, (int) $limit ) );
	$entries = wpap_keyword_index();
	if ( empty( $entries ) ) { return array( 0, 0, 0 ); }
	$ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} WHERE post_type='post' AND post_status='publish' ORDER BY ID DESC LIMIT %d",
		$limit
	) );
	$scanned = 0; $posts_linked = 0; $links = 0;
	foreach ( array_map( 'intval', (array) $ids ) as $pid ) {
		if ( $pid <= 0 ) { continue; }
		$scanned++;
		$post = get_post( $pid );
		if ( ! $post ) { continue; }
		list( $after, $added ) = wpap_autolink_content( (string) $post->post_content, $entries, $pid, 4 );
		if ( $added > 0 && $after !== $post->post_content ) {
			$wpdb->update( $wpdb->posts, array( 'post_content' => $after ), array( 'ID' => $pid ) );
			clean_post_cache( $pid );
			$posts_linked++;
			$links += $added;
		}
	}
	return array( $scanned, $posts_linked, $links );
}

/* Seed _wpap_keywords on ALREADY-PUBLISHED posts that lack it, deriving target phrases from the post's
   own tags (+ its focus keyword if an SEO plugin stored one). This lights up the auto-keyword linker on a
   catalogue that was published BEFORE the `keywords` field existed — with NO re-publishing. Idempotent:
   skips a post that already carries _wpap_keywords unless $overwrite (so an author-supplied list, or a
   prior run, is never clobbered). Returns [ scanned, seeded ]. */
function wpap_backfill_keywords_from_tags( $limit = 2000, $overwrite = false ) {
	global $wpdb;
	$limit = max( 1, min( 5000, (int) $limit ) );
	$ids   = $wpdb->get_col( $wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} WHERE post_type='post' AND post_status='publish' ORDER BY ID DESC LIMIT %d",
		$limit
	) );
	/* Ultra-generic phrases make noisy link TARGETS (they still work fine as sources) — skip them. */
	$stop = array(
		'health', 'wellness', 'tips', 'tip', 'food', 'foods', 'recipe', 'recipes', 'home', 'natural',
		'diy', 'kitchen', 'remedy', 'remedies', 'guide', 'guides', 'life', 'lifestyle', 'benefits',
		'how to', 'best', 'easy', 'quick', 'healthy',
	);
	$scanned = 0; $seeded = 0;
	foreach ( array_map( 'intval', (array) $ids ) as $pid ) {
		if ( $pid <= 0 ) { continue; }
		$scanned++;
		if ( ! $overwrite && '' !== (string) get_post_meta( $pid, '_wpap_keywords', true ) ) { continue; }
		$phrases = array();
		/* Tags — the primary, always-present source of specific topical anchors. */
		$terms = get_the_terms( $pid, 'post_tag' );
		if ( is_array( $terms ) ) {
			foreach ( $terms as $t ) { if ( isset( $t->name ) ) { $phrases[] = (string) $t->name; } }
		}
		/* Focus keyword from whichever SEO plugin stored it (bonus, first hit wins). */
		foreach ( array( '_yoast_wpseo_focuskw', 'rank_math_focus_keyword', '_seopress_analysis_target_kw' ) as $mk ) {
			$fk = (string) get_post_meta( $pid, $mk, true );
			if ( '' !== trim( $fk ) ) { $phrases[] = $fk; break; }
		}
		/* Clean: collapse whitespace, dedupe case-insensitively, drop <3 chars + the generic stop-list. */
		$seen = array(); $clean = array();
		foreach ( $phrases as $p ) {
			$p = trim( (string) preg_replace( '/\s+/', ' ', (string) $p ) );
			if ( '' === $p ) { continue; }
			$lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $p ) : strtolower( $p );
			if ( mb_strlen( $lc ) < 3 || in_array( $lc, $stop, true ) || isset( $seen[ $lc ] ) ) { continue; }
			$seen[ $lc ] = true;
			$clean[]     = $p;
		}
		if ( empty( $clean ) ) { continue; }
		update_post_meta( $pid, '_wpap_keywords', implode( ', ', array_slice( $clean, 0, 12 ) ) );
		$seeded++;
	}
	return array( $scanned, $seeded );
}

/* Repair posts damaged by the pre-9.28.1 auto-linker bug: a keyword link that was injected INSIDE another
   link's href value, e.g.  <a href="…lemon-and-<a href="…">baking</a>-soda-fix/">dark spots</a>. The inner
   anchor is collapsed back to its plain text, which restores the intact outer link. Idempotent + safe to
   re-run (a clean post never matches). Returns [ scanned, repaired ]. */
function wpap_repair_nested_ilinks( $limit = 2000 ) {
	global $wpdb;
	$limit = max( 1, min( 5000, (int) $limit ) );
	/* Cheap pre-filter: at least two link-starts (the corruption needs an inner <a href=" inside an outer
	   one). The regex below is the real test, so a normal two-link post is scanned but left untouched. */
	$ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts}
		  WHERE post_type='post' AND post_status IN ('publish','future','draft','pending')
		    AND post_content LIKE %s LIMIT %d",
		'%<a href="%<a href="%', $limit
	) );
	$scanned = 0; $repaired = 0;
	foreach ( array_map( 'intval', (array) $ids ) as $pid ) {
		if ( $pid <= 0 ) { continue; }
		$scanned++;
		$post = get_post( $pid );
		if ( ! $post ) { continue; }
		$before = (string) $post->post_content;
		$after  = $before;
		/* Collapse an inner <a href="…">text</a> that sits WITHIN an unfinished href value, repeatedly
		   (handles more than one nesting), with a hard guard against pathological loops. */
		$guard = 0;
		do {
			$prev  = $after;
			$after = (string) preg_replace(
				'#(<a href="[^"]*?)<a href="[^"]*?">([^<>]*)</a>#i',
				'$1$2',
				$after
			);
			$guard++;
		} while ( '' !== $after && $after !== $prev && $guard < 30 );
		if ( '' !== $after && $after !== $before ) {
			$wpdb->update( $wpdb->posts, array( 'post_content' => $after ), array( 'ID' => $pid ) );
			clean_post_cache( $pid );
			$repaired++;
		}
	}
	return array( $scanned, $repaired );
}

/* Run BOTH in-content passes over the freshly-published catalogue. Called at the tail of a bulk publish
   (so forward-ref markers link up once the whole batch is live, then keyword cross-links are woven) and
   by the admin buttons. Per-item isolation lives in the passes; wrap the whole thing so a linking hiccup
   can never fail the publish response. Returns [ ilinks_linked, kw_links ]. */
function wpap_internal_links_bake( $limit = 500 ) {
	$rep = array( 0, 0 );
	$il  = array( 0, 0 );
	$kw  = array( 0, 0, 0 );
	/* Repair FIRST: heal any nested-anchor corruption from the pre-9.28.1 bug before (re)linking, so a
	   re-run of "Activate on existing posts" both fixes the damage and links cleanly. */
	try {
		$rep = wpap_repair_nested_ilinks( max( $limit, 2000 ) );
	} catch ( \Throwable $e ) {
		error_log( '[Automation Hamri] repair-nested-ilinks pass failed: ' . $e->getMessage() );
	}
	try {
		$il = wpap_resolve_pending_ilinks( $limit );
	} catch ( \Throwable $e ) {
		error_log( '[Automation Hamri] resolve-ilinks pass failed: ' . $e->getMessage() );
	}
	try {
		$kw = wpap_auto_keyword_link_run( $limit );
	} catch ( \Throwable $e ) {
		error_log( '[Automation Hamri] auto-keyword-link pass failed: ' . $e->getMessage() );
	}
	return array( (int) ( $il[1] ?? 0 ), (int) ( $kw[2] ?? 0 ), (int) ( $rep[1] ?? 0 ) );
}

/* ══════════════════════════════════════════════════════════════════════════════════════════════
   C. ADMIN AJAX  (manual passes — parity with the passive build's buttons)
══════════════════════════════════════════════════════════════════════════════════════════════ */

/* Resolve pending internal-link markers (forward references) → real links now that their targets are live.
   Idempotent: re-run anytime after publishing a batch to link articles that pointed at later-published ones. */
add_action( 'wp_ajax_wpap_resolve_ilinks', 'wpap_ajax_resolve_ilinks' );
function wpap_ajax_resolve_ilinks() {
	check_ajax_referer( 'wpap_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Unauthorized' ); }
	@set_time_limit( 300 );
	list( $scanned, $linked ) = wpap_resolve_pending_ilinks( 2000 );
	wp_send_json_success( array( 'scanned' => (int) $scanned, 'linked' => (int) $linked ) );
}

/* Auto-link keyword phrases across posts (each post's `keywords` → cross-links by topic). */
add_action( 'wp_ajax_wpap_auto_keyword_link', 'wpap_ajax_auto_keyword_link' );
function wpap_ajax_auto_keyword_link() {
	check_ajax_referer( 'wpap_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Unauthorized' ); }
	@set_time_limit( 300 );
	list( $scanned, $posts_linked, $links ) = wpap_auto_keyword_link_run( 2000 );
	wp_send_json_success( array( 'scanned' => (int) $scanned, 'posts_linked' => (int) $posts_linked, 'links' => (int) $links ) );
}

/* One-click "activate on already-published content": seed keyword targets on live posts that lack them
   (from their tags + focus keyword), then bake the links (resolve forward-refs + cross-link by keyword).
   For a catalogue published before the internal-linking engine existed — no re-publishing. */
add_action( 'wp_ajax_wpap_backfill_keywords', 'wpap_ajax_backfill_keywords' );
function wpap_ajax_backfill_keywords() {
	check_ajax_referer( 'wpap_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Unauthorized' ); }
	@set_time_limit( 300 );
	list( $scanned, $seeded )               = wpap_backfill_keywords_from_tags( 5000, false );
	list( $il_linked, $kw_links, $repaired ) = wpap_internal_links_bake( 2000 );
	wp_send_json_success( array(
		'scanned'  => (int) $scanned,
		'seeded'   => (int) $seeded,
		'resolved' => (int) $il_linked,
		'links'    => (int) $kw_links,
		'repaired' => (int) $repaired,
	) );
}
