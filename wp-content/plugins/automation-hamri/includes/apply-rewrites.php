<?php
/**
 * Apply Rewrites — in-place content updater for existing posts.
 *
 * Purpose: push a batch of edited titles/bodies onto EXISTING posts without re-publishing and without any
 * external credential. The operator uploads a JSON array of { id, title, content } while logged in to
 * wp-admin; each item updates that post's title + body via wp_update_post(), under the normal admin session
 * (manage_options + nonce). Before the FIRST overwrite of a post, its original title + content are saved to
 * post meta (_wpap_rewrite_backup), so the whole operation is reversible with one click — the plugin never
 * touches a post it can't put back.
 *
 * This is NOT the publisher (which creates posts) and NOT the AI pipeline. It only edits post_title /
 * post_content of posts that already exist. Slugs are never changed (wp_update_post keeps an existing
 * post_name when the title changes).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Read + decode the uploaded JSON payload (multipart file field `payload`). Returns array|WP_Error. */
function wpap_apply_read_payload() {
	if ( empty( $_FILES['payload'] ) || ! isset( $_FILES['payload']['tmp_name'] ) ) {
		return new WP_Error( 'nofile', 'No JSON file was uploaded.' );
	}
	$err = isset( $_FILES['payload']['error'] ) ? (int) $_FILES['payload']['error'] : UPLOAD_ERR_NO_FILE;
	if ( UPLOAD_ERR_OK !== $err ) {
		return new WP_Error( 'upload', 'Upload failed (code ' . $err . ').' );
	}
	$tmp = $_FILES['payload']['tmp_name'];
	if ( ! is_uploaded_file( $tmp ) ) {
		return new WP_Error( 'upload', 'Invalid upload.' );
	}
	$size = (int) @filesize( $tmp );
	if ( $size <= 0 || $size > 8 * 1024 * 1024 ) {
		return new WP_Error( 'size', 'File is empty or larger than 8 MB.' );
	}
	$raw = file_get_contents( $tmp );
	if ( false === $raw ) {
		return new WP_Error( 'read', 'Could not read the uploaded file.' );
	}
	$data = json_decode( $raw, true );
	if ( JSON_ERROR_NONE !== json_last_error() ) {
		return new WP_Error( 'json', 'Invalid JSON: ' . json_last_error_msg() );
	}
	/* Accept a bare array or an {items:[...]} wrapper. */
	if ( is_array( $data ) && isset( $data['items'] ) && is_array( $data['items'] ) ) {
		$data = $data['items'];
	}
	if ( ! is_array( $data ) || empty( $data ) ) {
		return new WP_Error( 'shape', 'Expected a non-empty JSON array of { id, title, content } objects.' );
	}
	if ( count( $data ) > 1000 ) {
		return new WP_Error( 'toobig', 'Too many items (max 1000 per file).' );
	}
	return $data;
}

/* AJAX: apply the uploaded rewrites. */
add_action( 'wp_ajax_wpap_apply_rewrites', 'wpap_ajax_apply_rewrites' );
function wpap_ajax_apply_rewrites() {
	check_ajax_referer( 'wpap_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Unauthorized' ) ); }
	@set_time_limit( 300 );
	@ignore_user_abort( true );

	$items = wpap_apply_read_payload();
	if ( is_wp_error( $items ) ) { wp_send_json_error( array( 'message' => $items->get_error_message() ) ); }

	$updated = 0; $skipped = 0; $rows = array(); $messages = array();
	foreach ( $items as $n => $item ) {
		$row = $n + 1;
		try {
			if ( ! is_array( $item ) ) { $skipped++; $messages[] = "Row {$row}: not an object."; continue; }
			$id      = isset( $item['id'] ) ? (int) $item['id'] : 0;
			$title   = '';
			foreach ( array( 'title', 'new_title' ) as $k ) { if ( isset( $item[ $k ] ) && is_scalar( $item[ $k ] ) ) { $title = (string) $item[ $k ]; break; } }
			$content = null;
			foreach ( array( 'content', 'new_html' ) as $k ) { if ( isset( $item[ $k ] ) && is_scalar( $item[ $k ] ) ) { $content = (string) $item[ $k ]; break; } }

			if ( $id <= 0 ) { $skipped++; $messages[] = "Row {$row}: missing/invalid id."; continue; }
			$post = get_post( $id );
			if ( ! $post || 'post' !== $post->post_type ) { $skipped++; $messages[] = "Row {$row}: post {$id} not found (or not a post)."; continue; }
			if ( null === $content && '' === $title ) { $skipped++; $messages[] = "Row {$row}: nothing to update."; continue; }

			/* Back up the ORIGINAL once (first overwrite wins) so revert restores the true pre-rewrite state. */
			if ( '' === (string) get_post_meta( $id, '_wpap_rewrite_backup', true ) ) {
				update_post_meta( $id, '_wpap_rewrite_backup', wp_json_encode( array(
					'title'   => $post->post_title,
					'content' => $post->post_content,
					'time'    => time(),
				) ) );
			}

			$update = array( 'ID' => $id );
			if ( '' !== $title )      { $update['post_title']   = $title; }
			if ( null !== $content )  { $update['post_content'] = $content; }
			/* Suppress the Hub auto-add hook (no status transition on an update, but be safe). */
			$GLOBALS['wpap_suppress_hub_autoadd'] = true;
			$res = wp_update_post( $update, true );
			unset( $GLOBALS['wpap_suppress_hub_autoadd'] );

			if ( is_wp_error( $res ) ) { $skipped++; $messages[] = "Row {$row}: update failed — " . $res->get_error_message(); continue; }

			/* Optional per-item noindex: true keeps a redundant/thin post out of search (enforced by the
			   9.27.0 wp_robots filter honoring _wpap_noindex), false explicitly re-indexes; omit to leave
			   the post's index state untouched. Lets one upload both rewrite AND thin duplicate clusters. */
			if ( array_key_exists( 'noindex', $item ) && null !== $item['noindex'] ) {
				if ( filter_var( $item['noindex'], FILTER_VALIDATE_BOOLEAN ) ) { update_post_meta( $id, '_wpap_noindex', 1 ); }
				else { delete_post_meta( $id, '_wpap_noindex' ); }
			}

			clean_post_cache( $id );
			$updated++;
			$rows[] = array(
				'id'       => $id,
				'title'    => get_the_title( $id ),
				'post_url' => function_exists( 'wpap_public_permalink' ) ? (string) wpap_public_permalink( $id ) : get_permalink( $id ),
			);
		} catch ( \Throwable $e ) {
			$skipped++;
			$messages[] = "Row {$row}: fatal — " . $e->getMessage();
			error_log( '[Automation Hamri] apply-rewrites row ' . $row . ' failed: ' . $e->getMessage() );
		}
	}

	/* Re-weave in-content internal links across the freshly-updated catalogue (rewrites drop old links;
	   this re-adds them, and repairs any legacy nested-link damage first). Non-fatal. */
	$linked = array( 0, 0, 0 );
	if ( $updated > 0 && function_exists( 'wpap_internal_links_bake' ) ) {
		try { $linked = wpap_internal_links_bake( 2000 ); } catch ( \Throwable $e ) { error_log( '[Automation Hamri] apply-rewrites relink failed: ' . $e->getMessage() ); }
	}
	if ( function_exists( 'wpap_purge_caches' ) ) { wpap_purge_caches(); }

	wp_send_json_success( array(
		'updated'    => $updated,
		'skipped'    => $skipped,
		'total'      => count( $items ),
		'kw_links'   => (int) ( $linked[1] ?? 0 ),
		'repaired'   => (int) ( $linked[2] ?? 0 ),
		'rows'       => $rows,
		'messages'   => $messages,
	) );
}

/* AJAX: revert — restore every post that carries a rewrite backup to its original title + content. */
add_action( 'wp_ajax_wpap_revert_rewrites', 'wpap_ajax_revert_rewrites' );
function wpap_ajax_revert_rewrites() {
	check_ajax_referer( 'wpap_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Unauthorized' ) ); }
	@set_time_limit( 300 );
	global $wpdb;
	$ids = $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wpap_rewrite_backup' LIMIT 2000" );
	$reverted = 0; $messages = array();
	foreach ( array_map( 'intval', (array) $ids ) as $id ) {
		if ( $id <= 0 ) { continue; }
		try {
			$json = (string) get_post_meta( $id, '_wpap_rewrite_backup', true );
			$bak  = json_decode( $json, true );
			if ( ! is_array( $bak ) || ! isset( $bak['content'] ) ) { $messages[] = "Post {$id}: backup unreadable."; continue; }
			$GLOBALS['wpap_suppress_hub_autoadd'] = true;
			$res = wp_update_post( array( 'ID' => $id, 'post_title' => (string) ( $bak['title'] ?? '' ), 'post_content' => (string) $bak['content'] ), true );
			unset( $GLOBALS['wpap_suppress_hub_autoadd'] );
			if ( is_wp_error( $res ) ) { $messages[] = "Post {$id}: revert failed — " . $res->get_error_message(); continue; }
			delete_post_meta( $id, '_wpap_rewrite_backup' );
			clean_post_cache( $id );
			$reverted++;
		} catch ( \Throwable $e ) {
			$messages[] = "Post {$id}: fatal — " . $e->getMessage();
		}
	}
	if ( function_exists( 'wpap_purge_caches' ) ) { wpap_purge_caches(); }
	wp_send_json_success( array( 'reverted' => $reverted, 'messages' => $messages ) );
}

/** Admin page: Apply Rewrites. */
function wpap_render_apply_rewrites() {
	global $wpdb;
	$backups = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_wpap_rewrite_backup'" );
	$nonce   = wp_create_nonce( 'wpap_nonce' );
	$ajax    = admin_url( 'admin-ajax.php' );
	?>
	<div class="wrap">
	  <h1>Apply Rewrites</h1>
	  <p style="max-width:820px">Upload a JSON array of <code>{ "id": &lt;post ID&gt;, "title": "…", "content": "…&lt;html&gt;…" }</code>
	  (the keys <code>new_title</code> / <code>new_html</code> are also accepted; an optional <code>"noindex": true</code>
	  keeps a redundant post out of search). Each item updates that post's <strong>title and body in place</strong> —
	  no re-publishing, slugs unchanged. The original of every post is backed up on the server before its first
	  overwrite, so you can revert with one click. After applying, the in-content internal links are re-woven
	  automatically.</p>
	  <table class="form-table">
	    <tr><th scope="row"><label for="wpap-apply-file">Rewrites <code>.json</code></label></th>
	        <td><input type="file" id="wpap-apply-file" accept="application/json,.json" /></td></tr>
	  </table>
	  <p>
	    <button id="wpap-apply-go" class="button button-primary">Apply rewrites</button>
	    <button id="wpap-apply-revert" class="button" style="margin-left:12px;"<?php disabled( 0 === $backups ); ?>>Revert all (<?php echo (int) $backups; ?> backed up)</button>
	    &nbsp;<span id="wpap-apply-status" style="font-weight:600"></span>
	  </p>
	  <div id="wpap-apply-results" style="margin-top:16px"></div>
	</div>
	<script>
	(function () {
	  var AJAX = <?php echo wp_json_encode( $ajax ); ?>, NONCE = <?php echo wp_json_encode( $nonce ); ?>;
	  var go = document.getElementById('wpap-apply-go'),
	      rev = document.getElementById('wpap-apply-revert'),
	      status = document.getElementById('wpap-apply-status'),
	      results = document.getElementById('wpap-apply-results');
	  function esc(s){ var d=document.createElement('div'); d.textContent=(s==null?'':String(s)); return d.innerHTML; }
	  function render(rows, msgs){
	    var h='';
	    if (rows && rows.length){
	      h += '<h2 style="margin-bottom:6px">Updated ('+rows.length+')</h2><table class="widefat striped"><thead><tr><th style="width:60px">ID</th><th>Title</th><th style="width:70px">Link</th></tr></thead><tbody>';
	      rows.forEach(function(r){ h += '<tr><td>'+esc(r.id)+'</td><td>'+esc(r.title)+'</td><td><a href="'+esc(r.post_url)+'" target="_blank" rel="noopener">open</a></td></tr>'; });
	      h += '</tbody></table>';
	    }
	    if (msgs && msgs.length){ h += '<h2 style="margin:14px 0 6px">Notes</h2><ul style="list-style:disc;margin-left:20px">'; msgs.forEach(function(m){ h += '<li>'+esc(m)+'</li>'; }); h += '</ul>'; }
	    results.innerHTML = h;
	  }
	  if (go) go.addEventListener('click', function(){
	    var fi = document.getElementById('wpap-apply-file'); var f = fi && fi.files ? fi.files[0] : null;
	    if (!f){ alert('Choose a .json file first.'); return; }
	    var fd = new FormData(); fd.append('action','wpap_apply_rewrites'); fd.append('nonce', NONCE); fd.append('payload', f);
	    go.disabled = true; status.textContent = '⏳ Applying…'; results.innerHTML = '';
	    fetch(AJAX, { method:'POST', body:fd, credentials:'same-origin' }).then(function(r){ return r.json(); }).then(function(res){
	      go.disabled = false;
	      if (!res || !res.success){ status.textContent = '❌ ' + ((res&&res.data&&res.data.message)||'Failed.'); render([], (res&&res.data&&res.data.messages)||[]); return; }
	      var d = res.data||{};
	      status.textContent = '✓ Updated '+(d.updated||0)+' of '+(d.total||0)+' ('+(d.skipped||0)+' skipped) · '+(d.kw_links||0)+' internal links, '+(d.repaired||0)+' repaired.';
	      render(d.rows||[], d.messages||[]);
	    }).catch(function(e){ go.disabled = false; status.textContent = '❌ Network error: ' + e.message; });
	  });
	  if (rev) rev.addEventListener('click', function(){
	    if (!confirm('Restore every backed-up post to its original title + content?')) return;
	    rev.disabled = true; status.textContent = '⏳ Reverting…'; results.innerHTML = '';
	    fetch(AJAX, { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'}, body:'action=wpap_revert_rewrites&nonce='+encodeURIComponent(NONCE) })
	      .then(function(r){ return r.json(); }).then(function(res){
	        rev.disabled = false;
	        if (!res || !res.success){ status.textContent = '❌ ' + ((res&&res.data&&res.data.message)||'Failed.'); return; }
	        status.textContent = '✓ Reverted '+(res.data.reverted||0)+' post(s).';
	        render([], res.data.messages||[]);
	      }).catch(function(e){ rev.disabled = false; status.textContent = '❌ Network error: ' + e.message; });
	  });
	})();
	</script>
	<?php
}
