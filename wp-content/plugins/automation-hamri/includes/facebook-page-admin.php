<?php
/**
 * Facebook Page auto-poster — settings panel + help tips (rendered inside the main settings form).
 *
 * @package Automation_Hamri
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/* ── build-v9 ports (guarded: build-final defines these in its main file) ───────────────────────────────── */

/* Append UTM params to one of our OWN links when the `wpap_utm` option is enabled (same option the Hub uses). */
if ( ! function_exists( 'wpap_apply_utm' ) ) {
	function wpap_apply_utm( $url, $post_id = 0 ) {
		$url = trim( (string) $url );
		$utm = get_option( 'wpap_utm', array() );
		if ( '' === $url || ! is_array( $utm ) || empty( $utm['enabled'] ) ) { return $url; }
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$home = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( $host && $home && 0 !== strcasecmp( (string) $host, (string) $home ) ) { return $url; }   /* our site only */
		if ( false !== stripos( $url, 'utm_' ) ) { return $url; }                                      /* already tagged */
		$args = array(
			'utm_source' => (string) ( $utm['source'] ?? 'facebook' ),
			'utm_medium' => (string) ( $utm['medium'] ?? 'social' ),
		);
		$tpl = trim( (string) ( $utm['campaign'] ?? '{slug}' ) );
		if ( '' !== $tpl ) {
			$p    = (int) $post_id > 0 ? get_post( (int) $post_id ) : null;
			$cats = $p ? get_the_category( (int) $post_id ) : array();
			$camp = sanitize_title( str_replace(
				array( '{slug}', '{category}', '{id}' ),
				array( $p ? (string) $p->post_name : '', $cats ? (string) $cats[0]->slug : '', (string) (int) $post_id ),
				$tpl
			) );
			if ( '' !== $camp ) { $args['utm_campaign'] = $camp; }
		}
		return add_query_arg( $args, $url );
	}
}

/* Hover-help "i" bubble. Tips come from the `wpap_help_tips_extra` filter (authored copy, kses-restricted). */
if ( ! function_exists( 'wpap_help_tip' ) ) {
	function wpap_help_tip( $key ) {
		$tips = apply_filters( 'wpap_help_tips_extra', array() );
		if ( empty( $tips[ $key ] ) ) { return ''; }
		$allowed = array(
			'strong' => array(), 'b' => array(), 'em' => array(), 'i' => array(),
			'br' => array(), 'ul' => array(), 'ol' => array(), 'li' => array(), 'code' => array(),
		);
		return '<span class="wpap-help" tabindex="0" role="img" aria-label="How to use this setting — hover or focus for directions">'
			. '<span class="wpap-help-i" aria-hidden="true">i</span>'
			. '<span class="wpap-tip" role="tooltip">' . wp_kses( (string) $tips[ $key ], $allowed ) . '</span></span>';
	}
}

/* When the next share is due, as site-time text (or why nothing is due). */
function wpap_fbp_next_text( $o ) {
	if ( ! $o['enabled'] ) { return 'Off.'; }
	if ( '' === $o['page_id'] || '' === $o['token'] ) { return 'Waiting for a Page ID and token.'; }
	if ( get_option( 'wpap_fbp_paused' ) ) { return 'Paused: fix the token, then Save.'; }
	if ( get_transient( 'wpap_fbp_backoff' ) ) { return 'Facebook asked us to slow down; sharing resumes within 3 hours by itself.'; }
	$done = wpap_fbp_shares_today();
	if ( $done >= $o['per_day'] ) { return 'Today\'s ' . $o['per_day'] . ' shares are done; next at ' . sprintf( '%02d:00', $o['start'] ) . ' tomorrow.'; }
	$gap  = ( $o['end'] - $o['start'] ) * 60 / $o['per_day'];
	$mins = (int) round( $o['start'] * 60 + $done * $gap );
	return 'Next share around ' . sprintf( '%02d:%02d', intdiv( $mins, 60 ), $mins % 60 ) . ' (site time), ' . $done . ' of ' . $o['per_day'] . ' done today.';
}

function wpap_fbp_render_settings() {
	$o      = wpap_fbp_opts();
	$paused = (string) get_option( 'wpap_fbp_paused', '' );
	$name   = (string) get_option( 'wpap_fbp_page_name', '' );
	$log    = get_option( 'wpap_fbp_log', array() );
	$nonce  = wp_create_nonce( 'wpap_fbp' );
	?>
	<style>
		.wpap-help{position:relative;display:inline-flex;vertical-align:middle;margin-left:6px;cursor:help}
		.wpap-help:focus{outline:none}
		.wpap-help-i{display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;border-radius:50%;background:#2271b1;color:#fff;font:700 11px/1 Georgia,"Times New Roman",serif;font-style:italic}
		.wpap-tip{display:none;position:absolute;left:0;top:24px;z-index:100000;width:340px;max-width:78vw;background:#1f2937;color:#f3f4f6;border-radius:8px;padding:12px 14px;font:400 12.5px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;text-align:left;white-space:normal;box-shadow:0 12px 34px -8px rgba(0,0,0,.55)}
		.wpap-help:hover .wpap-tip,.wpap-help:focus .wpap-tip,.wpap-help:focus-within .wpap-tip{display:block}
		.wpap-tip strong,.wpap-tip b{color:#fff}.wpap-tip code{background:rgba(255,255,255,.16);color:#fde68a;padding:1px 4px;border-radius:3px}
		.wpap-tip ul,.wpap-tip ol{margin:6px 0 0;padding-left:18px}
		details.wpap-group{border:1px solid #dcdcde;border-radius:10px;background:#fff;margin:24px 0 14px;max-width:940px}
		details.wpap-group>summary{cursor:pointer;padding:14px 16px;user-select:none}
		details.wpap-group>summary .wpap-g-title{font-size:14.5px;font-weight:700;color:#1d2327}
		details.wpap-group>summary .wpap-g-sub{font-weight:400;color:#646970;font-size:12.5px}
		details.wpap-group[open]>summary{border-bottom:1px solid #f0f0f1}
		details.wpap-group>.wpap-group-body{padding:2px 16px 6px}
	</style>
	<details class="wpap-group" id="wpap-grp-fbpage">
		<summary><span class="wpap-g-title">📘 Facebook Page posting</span> <span class="wpap-g-sub">&mdash; share each new post to your Page: photo + hook, link in the first comment (opt-in)</span></summary>
		<div class="wpap-group-body">
		<?php if ( '' !== $paused ) : ?>
			<div class="notice notice-error inline"><p><strong>Paused.</strong> Facebook refused the token: <?php echo esc_html( $paused ); ?> Paste a fresh Page access token below and Save.</p></div>
		<?php endif; ?>
		<table class="form-table">
			<tr>
				<th scope="row">Post to my Facebook Page <?php echo wpap_help_tip( 'fbp_enabled' ); ?></th>
				<td><label><input type="checkbox" name="wpap_fbp_enabled" value="1" <?php checked( $o['enabled'] ); ?> /> <strong>Share new posts to the Page automatically</strong></label>
					<p class="description"><?php echo esc_html( wpap_fbp_next_text( $o ) ); ?> Waiting to be shared: <strong><?php echo (int) wpap_fbp_queue_count( $o ); ?></strong>.</p></td>
			</tr>
			<tr>
				<th scope="row">Page ID <?php echo wpap_help_tip( 'fbp_page_id' ); ?></th>
				<td><input type="text" name="wpap_fbp_page_id" class="regular-text" inputmode="numeric" value="<?php echo esc_attr( $o['page_id'] ); ?>" placeholder="e.g. 61572084231051" autocomplete="off" />
					<?php if ( '' !== $name ) : ?><p class="description">Connected Page: <strong><?php echo esc_html( $name ); ?></strong></p><?php endif; ?></td>
			</tr>
			<tr>
				<th scope="row">Page access token <?php echo wpap_help_tip( 'fbp_token' ); ?></th>
				<td><input type="password" name="wpap_fbp_token" class="large-text" value="" placeholder="<?php echo esc_attr( '' !== $o['token'] ? 'Saved (ends in …' . substr( $o['token'], -4 ) . '). Paste a new one to replace it.' : 'Paste the Page access token' ); ?>" autocomplete="new-password" />
					<?php if ( '' !== $o['token'] ) : ?><p style="margin:6px 0 0"><label><input type="checkbox" name="wpap_fbp_token_clear" value="1" /> Remove the saved token</label></p><?php endif; ?></td>
			</tr>
			<tr>
				<th scope="row">Schedule <?php echo wpap_help_tip( 'fbp_schedule' ); ?></th>
				<td><label><input type="number" name="wpap_fbp_per_day" min="1" max="24" class="small-text" value="<?php echo esc_attr( (string) $o['per_day'] ); ?>" /> posts a day</label>,
					<label>between <input type="number" name="wpap_fbp_start" min="0" max="23" class="small-text" value="<?php echo esc_attr( (string) $o['start'] ); ?>" />:00</label>
					<label>and <input type="number" name="wpap_fbp_end" min="1" max="24" class="small-text" value="<?php echo esc_attr( (string) $o['end'] ); ?>" />:00</label> (site time)</td>
			</tr>
			<tr>
				<th scope="row">Which posts <?php echo wpap_help_tip( 'fbp_since' ); ?></th>
				<td><label>Share posts published from <input type="date" name="wpap_fbp_since" value="<?php echo esc_attr( $o['since'] ); ?>" /></label> (empty = from the day you switch it on)
					<p style="margin:8px 0 0"><label><input type="checkbox" name="wpap_fbp_backlog" value="1" <?php checked( $o['backlog'] ); ?> /> When those are all shared, keep going with older posts (newest first)</label> <?php echo wpap_help_tip( 'fbp_backlog' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row">Post style <?php echo wpap_help_tip( 'fbp_format' ); ?></th>
				<td><select name="wpap_fbp_format">
					<option value="photo" <?php selected( $o['format'], 'photo' ); ?>>Photo + hook, link in the first comment (recommended)</option>
					<option value="link" <?php selected( $o['format'], 'link' ); ?>>Link post (hook + link preview card)</option>
				</select></td>
			</tr>
			<tr>
				<th scope="row">Check it <?php echo wpap_help_tip( 'fbp_test' ); ?></th>
				<td><button type="button" class="button" data-wpap-fbp="wpap_fbp_test">Test connection</button>
					<button type="button" class="button" data-wpap-fbp="wpap_fbp_share_now">Share the next post now</button>
					<span id="wpap-fbp-msg" style="margin-left:8px"></span>
					<p class="description">Save your settings first; both buttons use the saved Page ID and token.</p></td>
			</tr>
		</table>
		<?php if ( is_array( $log ) && $log ) : ?>
			<p style="margin:12px 0 4px"><strong>Recent shares</strong></p>
			<ul style="margin:0 0 0 18px;list-style:disc">
			<?php foreach ( array_slice( $log, 0, 8 ) as $row ) : ?>
				<li><?php echo esc_html( wp_date( 'M j, H:i', (int) $row['t'] ) ); ?> &middot;
					<?php echo 'ok' === $row['s'] ? '✅' : '⚠️'; ?>
					<a href="<?php echo esc_url( get_permalink( (int) $row['pid'] ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( get_the_title( (int) $row['pid'] ) ); ?></a>
					&mdash; <?php echo esc_html( $row['m'] ); ?></li>
			<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		</div>
	</details>
	<script>
	/* Delegated, so buttons in later panels (📸 Instagram) work too; the result shows next to the button. */
	document.addEventListener('click', function (e) {
		var b = e.target.closest ? e.target.closest('[data-wpap-fbp]') : null;
		if (!b) { return; }
		(function () {
			var msg = b.parentNode.querySelector('.wpap-fbp-msg, #wpap-fbp-msg');
			if (!msg) { msg = document.createElement('span'); msg.className = 'wpap-fbp-msg'; msg.style.marginLeft = '8px'; b.after(msg); }
			var asks = { wpap_fbp_share_now: 'Post the next waiting recipe to your Facebook Page now?', wpap_igp_share_now: 'Post the next waiting recipe to Instagram now?' };
			if (asks[b.dataset.wpapFbp] && !confirm(asks[b.dataset.wpapFbp])) { return; }
			if (b.disabled) { return; }
			b.disabled = true;   /* no double clicks while the request runs */
			msg.textContent = 'Working…';
			var body = new URLSearchParams({ action: b.dataset.wpapFbp, nonce: <?php echo wp_json_encode( $nonce ); ?> });
			fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
				.then(function (r) { return r.json(); })
				.then(function (j) { msg.textContent = (j.success ? '✅ ' : '⚠️ ') + j.data; })
				.catch(function () { msg.textContent = '⚠️ The request failed. Try again.'; })
				.then(function () { b.disabled = false; });
		})();
	});
	</script>
	<?php
}

add_filter( 'wpap_help_tips_extra', function ( $t ) {
	return array_merge( (array) $t, array(
		'fbp_enabled'  => '<strong>Shares each new post to your Facebook Page by itself</strong>: the post\'s photo with its Facebook hook as the caption, then the link in the first comment (the same text the Distribution Hub exports).<br><strong>How to use it:</strong> fill in the Page ID and Page access token below, tick this, Save, then click <em>Test connection</em>.<br>Shares go out through the day on the schedule below; each post is shared once.<br><strong>Link preview:</strong> before the link goes out, the plugin asks Facebook to read the page so the card shows the recipe title and photo. If your host turns Facebook away, it tries again for about 20 seconds; the log below says when a card still came out without a title.<br><em>Default: OFF.</em>',
		'fbp_page_id'  => '<strong>Your Page\'s API number</strong> (not always the number in the Page\'s web address: new-style Pages show a <code>profile.php?id=…</code> number that the API does not accept).<br><strong>How to find it:</strong> open your token in <em>Tools &rarr; Access Token Debugger</em>; the number shown next to your Page\'s name under <em>Granular Scopes</em> (e.g. <code>pages_manage_posts &nbsp;1050925074772814 : My Page</code>) is the one to paste here. <em>Test connection</em> shows the Page name when it is right.',
		'fbp_token'    => '<strong>The key that lets this site post to your Page.</strong> It is stored on your site only, never shown again, never exported.<br><strong>How to get one:</strong><ol><li>At developers.facebook.com open <em>Tools &rarr; Graph API Explorer</em> and pick one of your apps (any <em>Business</em> app; create one if you have none).</li><li>Make sure the permissions include <code>pages_manage_posts</code>, <code>pages_manage_engagement</code>, <code>pages_read_engagement</code> and <code>pages_show_list</code>, click <em>Generate Access Token</em> and allow your Page.</li><li>Copy the token, open <em>Tools &rarr; Access Token Debugger</em>, paste it, click <em>Debug</em>, then <em>Extend Access Token</em> at the bottom, and copy the new long-lived token.</li><li>Paste it here and Save. The plugin swaps it for your Page\'s own token, which does not expire.</li><li><strong>Switch the app to Live</strong> (the <em>App Mode</em> toggle at the top of the app\'s page; it first needs a <em>Privacy policy URL</em> and a <em>Category</em> in <em>App settings &rarr; Basic</em>). While the app is in Development mode, Facebook shows its posts only to you. Your token keeps working after the switch.</li></ol>',
		'fbp_schedule' => '<strong>How many posts a day, and when.</strong> Shares are spaced evenly between the two hours (site time). One share at most every 15 minutes.<br><em>Default: 4 a day, 9:00 to 21:00.</em> WordPress runs the schedule when the site gets visits; a quiet site can use an external cron pinger.',
		'fbp_since'    => '<strong>Where the queue starts.</strong> Posts published on or after this date are shared, oldest first. Set an earlier date to share a batch you already published.<br><em>Default: the day you switch the poster on.</em>',
		'fbp_backlog'  => '<strong>Keeps the Page busy</strong> after the new posts run out, by sharing older posts, newest first, one slot at a time.<br><em>Default: OFF.</em>',
		'fbp_format'   => '<strong>Photo + first comment</strong> keeps the caption a clean hook with no link, which Facebook tends to show to more people; the link sits in the first comment. <strong>Link post</strong> puts a clickable preview card in the post itself (one click to the site, usually less reach).<br><em>Default: Photo.</em>',
		'fbp_test'     => '<strong>Test connection</strong> checks the saved token can see your Page and shows its name. <strong>Share the next post now</strong> posts the next waiting recipe straight away (it counts toward today\'s shares).',
	) );
} );
