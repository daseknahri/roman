<?php
/**
 * Admin UI — dashboard render + settings page
 *
 * Extracted verbatim from wp-automator-pro.php (single-file → modular).
 * Load order is fixed by the main file; every hook self-registers here.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function wpap_render_dashboard() {
    echo '<div id="wpap-app" class="wpap-root"><div style="padding:40px;text-align:center;font-family:sans-serif;color:#888;font-size:32px;">&#9889; Loading&hellip;</div></div>';
    echo '<p style="text-align:center;margin-top:16px;font-family:sans-serif;font-size:13px;color:#666;">Need help? Contact Oussama Hamri &mdash; <a href="https://wa.me/+212637122491" target="_blank" rel="noopener" style="color:#6366f1;font-weight:600;text-decoration:none;">Click Here</a></p>';
}

/* ════════════════════════════════════════════
   5. SETTINGS PAGE
════════════════════════════════════════════ */
function wpap_render_settings() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html( 'You do not have permission to access this page.' ) );
    }

    /* Map each API-key field to the wp-config.php constant that can lock it. */
    $const_map = array(
        'claude_api_key' => 'WPAP_CLAUDE_API_KEY',
        'gemini_api_key' => 'WPAP_GEMINI_API_KEY',
        'pexels_api_key' => 'WPAP_PEXELS_API_KEY',
    );

    if ( isset( $_POST['wpap_save'] ) && check_admin_referer( 'wpap_settings_nonce' ) ) {
        $existing = get_option( 'wpap_settings', array() );
        if ( ! is_array( $existing ) ) { $existing = array(); }
        $saved = $existing;
        foreach ( array( 'claude_api_key', 'gemini_api_key', 'pexels_api_key' ) as $field ) {
            /* Skip fields locked by a wp-config.php constant — never store a shadow value. */
            $const = $const_map[ $field ] ?? '';
            if ( $const && defined( $const ) && '' !== trim( (string) constant( $const ) ) ) {
                continue;
            }
            $submitted = sanitize_text_field( wp_unslash( $_POST[ $field ] ?? '' ) );
            /* Blank field = keep the stored key (the form never re-echoes secrets). */
            if ( '' !== $submitted ) {
                $saved[ $field ] = $submitted;
            }
        }
        update_option( 'wpap_settings', $saved, false );   /* autoload = no: keep secrets out of the all-options cache */

        /* ── Automation (Google Sheet) settings — same nonce/cap guard ── */
        update_option( 'wpap_automation', array(
            'enabled'          => isset( $_POST['wpap_auto_enabled'] ) ? 1 : 0,
            'sheet_url'        => esc_url_raw( wp_unslash( $_POST['wpap_auto_sheet_url'] ?? '' ) ),
            'per_day'          => max( 0, min( 500, (int) ( $_POST['wpap_auto_per_day'] ?? 12 ) ) ),
            'per_run'          => max( 1, min( 50,  (int) ( $_POST['wpap_auto_per_run'] ?? 3 ) ) ),
            'default_category' => sanitize_text_field( wp_unslash( $_POST['wpap_auto_default_category'] ?? '' ) ),
            'schedule_window'  => max( 0, min( 168, (float) ( $_POST['wpap_auto_schedule_window'] ?? 0 ) ) ),
        ), false );

        /* ads.txt content (plain text; tags stripped). */
        update_option( 'wpap_ads_txt', sanitize_textarea_field( wp_unslash( $_POST['wpap_ads_txt'] ?? '' ) ), false );

        /* ── AdSense ad-injection settings (wpap_ads_inject) ── */
        /* Custom placements (Option 2): parallel arrays, one entry per row.
           Empty-code rows are dropped, so a blank row simply disappears and the
           three arrays stay index-aligned. Capped at 10. */
        $wpap_custom   = array();
        $wpap_cust_pos = ( isset( $_POST['wpap_ads_cust_pos'] ) && is_array( $_POST['wpap_ads_cust_pos'] ) ) ? wp_unslash( $_POST['wpap_ads_cust_pos'] ) : array();
        $wpap_cust_aft = ( isset( $_POST['wpap_ads_cust_after'] ) && is_array( $_POST['wpap_ads_cust_after'] ) ) ? wp_unslash( $_POST['wpap_ads_cust_after'] ) : array();
        $wpap_cust_cod = ( isset( $_POST['wpap_ads_cust_code'] ) && is_array( $_POST['wpap_ads_cust_code'] ) ) ? wp_unslash( $_POST['wpap_ads_cust_code'] ) : array();
        $wpap_cust_n   = count( $wpap_cust_cod );
        for ( $wpap_i = 0; $wpap_i < $wpap_cust_n && count( $wpap_custom ) < 10; $wpap_i++ ) {
            $wpap_code = wpap_cap_ad_code( trim( (string) ( $wpap_cust_cod[ $wpap_i ] ?? '' ) ) );
            if ( '' === $wpap_code ) { continue; }
            $wpap_pos = isset( $wpap_cust_pos[ $wpap_i ] ) ? sanitize_key( (string) $wpap_cust_pos[ $wpap_i ] ) : 'after';
            if ( ! in_array( $wpap_pos, array( 'after', 'top', 'before_related' ), true ) ) { $wpap_pos = 'after'; }
            $wpap_custom[] = array(
                'pos'   => $wpap_pos,
                'after' => max( 1, min( 50, (int) ( $wpap_cust_aft[ $wpap_i ] ?? 2 ) ) ),
                'code'  => $wpap_code,
            );
        }

        update_option( 'wpap_ads_inject', array(
            'enabled'   => isset( $_POST['wpap_ads_enabled'] ) ? 1 : 0,
            'scope_all' => isset( $_POST['wpap_ads_scope_all'] ) ? 1 : 0,
            'auto_code' => wpap_cap_ad_code( trim( (string) wp_unslash( $_POST['wpap_ads_auto_code'] ?? '' ) ) ),
            'min_gap'   => max( 0, min( 20, (int) ( $_POST['wpap_ads_min_gap'] ?? 1 ) ) ),
            'max_ads'   => max( 0, min( 20, (int) ( $_POST['wpap_ads_max_ads'] ?? 0 ) ) ),
            'label'     => isset( $_POST['wpap_ads_label'] ) ? 1 : 0,
            'zones'     => array(
                'header'  => array( 'on' => isset( $_POST['wpap_ads_zone_header_on'] ) ? 1 : 0,  'code' => wpap_cap_ad_code( trim( (string) wp_unslash( $_POST['wpap_ads_zone_header_code'] ?? '' ) ) ) ),
                'sidebar' => array( 'on' => isset( $_POST['wpap_ads_zone_sidebar_on'] ) ? 1 : 0, 'code' => wpap_cap_ad_code( trim( (string) wp_unslash( $_POST['wpap_ads_zone_sidebar_code'] ?? '' ) ) ) ),
                'footer'  => array( 'on' => isset( $_POST['wpap_ads_zone_footer_on'] ) ? 1 : 0,  'code' => wpap_cap_ad_code( trim( (string) wp_unslash( $_POST['wpap_ads_zone_footer_code'] ?? '' ) ) ) ),
            ),
            'custom'    => $wpap_custom,
            'slots'     => array(
                'top'       => array(
                    'on'   => isset( $_POST['wpap_ads_top_on'] ) ? 1 : 0,
                    'code' => wpap_cap_ad_code( trim( (string) wp_unslash( $_POST['wpap_ads_top_code'] ?? '' ) ) ),
                ),
                'incontent' => array(
                    'on'    => isset( $_POST['wpap_ads_inc_on'] ) ? 1 : 0,
                    'code'  => wpap_cap_ad_code( trim( (string) wp_unslash( $_POST['wpap_ads_inc_code'] ?? '' ) ) ),
                    'after' => max( 1, min( 50, (int) ( $_POST['wpap_ads_inc_after'] ?? 2 ) ) ),
                ),
                'repeat'    => array(
                    'on'    => isset( $_POST['wpap_ads_rep_on'] ) ? 1 : 0,
                    'code'  => wpap_cap_ad_code( trim( (string) wp_unslash( $_POST['wpap_ads_rep_code'] ?? '' ) ) ),
                    'every' => max( 1, min( 50, (int) ( $_POST['wpap_ads_rep_every'] ?? 4 ) ) ),
                    'max'   => max( 1, min( 10, (int) ( $_POST['wpap_ads_rep_max'] ?? 3 ) ) ),
                ),
                'bottom'    => array(
                    'on'   => isset( $_POST['wpap_ads_bot_on'] ) ? 1 : 0,
                    'code' => wpap_cap_ad_code( trim( (string) wp_unslash( $_POST['wpap_ads_bot_code'] ?? '' ) ) ),
                ),
            ),
        ), true );   /* autoload=yes: read on every front-end request (wp_head + the_content) */

        /* IndexNow instant-indexing toggle. */
        update_option( 'wpap_indexnow', array(
            'enabled' => isset( $_POST['wpap_indexnow_enabled'] ) ? 1 : 0,
        ), false );

        /* ── Content options (publishing guards; all off by default) ── */
        update_option( 'wpap_content_opts', array(
            'min_words'        => max( 0, min( 5000, (int) ( $_POST['wpap_min_words'] ?? 0 ) ) ),
            'skip_dupe_titles' => isset( $_POST['wpap_skip_dupe_titles'] ) ? 1 : 0,
            'disable_comments' => isset( $_POST['wpap_disable_comments'] ) ? 1 : 0,
            'clean_media'      => isset( $_POST['wpap_clean_media'] ) ? 1 : 0,
            /* AI-search discoverability: serve /llms.txt + /ai.txt (opt-in, default off). */
            'llms_txt'         => isset( $_POST['wpap_llms_txt'] ) ? 1 : 0,
            /* Global default first-comment template (Distribution Hub / export). {{link}} is
               replaced by each post's link; a per-post _wpap_fb_comment override wins. */
            'fb_comment_template' => mb_substr( sanitize_textarea_field( (string) wp_unslash( $_POST['wpap_fb_comment_template'] ?? '' ) ), 0, 2000 ),
            /* Facebook identifiers (both optional, default empty = nothing emitted). Domain-verification is
               output site-wide (Meta crawls the homepage <head>); fb:app_id only on this plugin's post pages. */
            'fb_domain_verify' => preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_POST['wpap_fb_domain_verify'] ?? '' ) ),
            'fb_app_id'        => preg_replace( '/[^0-9]/', '', (string) wp_unslash( $_POST['wpap_fb_app_id'] ?? '' ) ),
        ), false );

        /* Image optimization: convert imported JPEG/PNG to WebP (default ON). */
        update_option( 'wpap_webp_enabled', isset( $_POST['wpap_webp_enabled'] ) ? '1' : '0', true );

        echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
    }
    $s = get_option( 'wpap_settings', array() );
    if ( ! is_array( $s ) ) { $s = array(); }

    /* Automation (Google Sheet) state for the UI below. */
    $auto        = wpap_get_automation();
    $auto_status = get_option( 'wpap_automation_status', array() );
    if ( ! is_array( $auto_status ) ) { $auto_status = array(); }

    /* Show a masked hint instead of the real key, so secrets never leave the server. */
    $hint = function ( $key ) {
        $key = (string) $key;
        if ( '' === $key ) { return 'Not set'; }
        $tail = strlen( $key ) > 4 ? substr( $key, -4 ) : $key;
        return 'Saved (' . str_repeat( "\xE2\x80\xA2", 6 ) . $tail . ') — leave blank to keep';
    };

    /* True when a field is locked by a non-empty wp-config.php constant. */
    $is_const = function ( $field ) use ( $const_map ) {
        $c = $const_map[ $field ] ?? '';
        return ( $c && defined( $c ) && '' !== trim( (string) constant( $c ) ) );
    };

    /* Render one settings row: a locked note when constant-defined, else the input. */
    $render_key_row = function ( $label, $field, $desc_html = '' ) use ( $s, $hint, $is_const, $const_map ) {
        echo '<tr><th>' . esc_html( $label ) . '</th><td>';
        if ( $is_const( $field ) ) {
            echo '<input type="text" value="' . esc_attr( 'Set via wp-config.php' ) . '" class="large-text" disabled />';
            echo '<p class="description">&#128274; Defined in <code>wp-config.php</code> via <code>' . esc_html( $const_map[ $field ] ) . '</code>. Remove that constant to manage this key here.</p>';
        } else {
            echo '<input type="password" name="' . esc_attr( $field ) . '" value="" autocomplete="new-password" placeholder="' . esc_attr( $hint( $s[ $field ] ?? '' ) ) . '" class="large-text" />';
            if ( '' !== $desc_html ) {
                echo $desc_html;   /* trusted static markup passed from the caller below */
            }
        }
        echo '</td></tr>';
    };
    ?>
    <div class="wrap">
        <h1>WP Automator Pro &mdash; Settings</h1>
        <form method="post" autocomplete="off">
            <?php wp_nonce_field( 'wpap_settings_nonce' ); ?>
            <table class="form-table">
                <?php
                $render_key_row( 'Claude API Key', 'claude_api_key' );
                $render_key_row( 'Gemini API Key', 'gemini_api_key' );
                $render_key_row( 'Pexels API Key', 'pexels_api_key', '<p class="description">Free images — <a href="https://www.pexels.com/api/" target="_blank">pexels.com/api</a></p>' );
                ?>
            </table>

            <?php /* ── Automation — Google Sheet (config; saved by the same button) ── */ ?>
            <h2 style="margin-top:32px;">Automation &mdash; Google Sheet</h2>
            <p class="description" style="max-width:760px;">
                Auto-publish ready-made articles straight from a Google Sheet &mdash; no API key or login.
                In Google Sheets: <strong>File &rarr; Share &rarr; Publish to web</strong>, choose the specific
                tab, pick <strong>Comma-separated values (.csv)</strong>, click <strong>Publish</strong>, and paste
                the link below. First row must be the header. Recognized columns:
                <code>title</code>, <code>content</code>, <code>imageUrl</code>, <code>hook</code>,
                <code>category</code>, <code>id</code>. Each row needs at least a <code>title</code> or
                <code>content</code>. An <code>id</code> is optional but recommended (stable dedup).
            </p>
            <p class="description" style="max-width:760px;">
                <strong>Timing:</strong> the poll runs on WordPress cron, which fires on site traffic &mdash; on a
                quiet site ticks can drift. For reliable timing, add a real server cron hitting
                <code>wp-cron.php</code>. For an even drip set <em>Max per run</em> to 1 and raise
                <em>Max per day</em>. Keep the Sheet private / owner-only &mdash; its <code>content</code> is published as-is.
            </p>
            <table class="form-table">
                <tr>
                    <th scope="row">Enable auto-publish</th>
                    <td>
                        <label>
                            <input type="checkbox" name="wpap_auto_enabled" value="1" <?php checked( ! empty( $auto['enabled'] ) ); ?> />
                            Publish new Sheet rows automatically (hourly).
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Published CSV URL</th>
                    <td>
                        <input type="url" name="wpap_auto_sheet_url" class="large-text" value="<?php echo esc_attr( $auto['sheet_url'] ); ?>"
                               placeholder="https://docs.google.com/spreadsheets/d/e/&hellip;/pub?gid=0&amp;single=true&amp;output=csv" />
                        <p class="description">The &ldquo;Publish to web&rdquo; CSV link (ends in <code>output=csv</code>).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Max per day</th>
                    <td><input type="number" name="wpap_auto_per_day" min="0" max="500" step="1" class="small-text" value="<?php echo esc_attr( (string) $auto['per_day'] ); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row">Max per run</th>
                    <td>
                        <input type="number" name="wpap_auto_per_run" min="1" max="50" step="1" class="small-text" value="<?php echo esc_attr( (string) $auto['per_run'] ); ?>" />
                        <p class="description">Published each hourly run, capped by the daily limit.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Default category</th>
                    <td><input type="text" name="wpap_auto_default_category" class="regular-text" placeholder="Uncategorized" value="<?php echo esc_attr( $auto['default_category'] ); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row">Schedule window (hours)</th>
                    <td>
                        <input type="number" name="wpap_auto_schedule_window" min="0" max="168" step="0.5" class="small-text" value="<?php echo esc_attr( (string) $auto['schedule_window'] ); ?>" />
                        <p class="description">0 = publish immediately. Otherwise each post is scheduled at a random time within this many hours.</p>
                    </td>
                </tr>
            </table>

            <?php /* ── ads.txt (AdSense) ── */ ?>
            <?php /* ── AdSense ad placement (ported engine; saved by the same button) ── */ ?>
            <?php $ads = wpap_get_ads(); ?>
            <h2 style="margin-top:32px;">AdSense ad placement</h2>
            <p class="description" style="max-width:820px;">
                Paste your own AdSense code into any slot below and turn it on. <strong>Auto Ads</strong> lets Google
                place ads automatically; the <strong>manual slots</strong> give you exact control (use an
                <em>In-article</em> unit for the in-content slots). The <strong>zones</strong> feed a compatible theme's
                header/sidebar/footer. Leave a slot blank/off to skip it. Nothing is sent anywhere — your code is only
                printed on your own pages.
            </p>

            <h3 style="margin:18px 0 4px;">Live preview <span style="font-weight:400;color:#666;">— where your ads land, at their real sizes</span></h3>
            <p class="description" style="max-width:820px;">A sample story rendered like your theme. It updates as you change the settings below, so you can see the placement and typical AdSense unit sizes before you paste any code. Auto Ads add more, placed by Google.</p>
            <style>
                .wpap-adprev{max-width:430px;margin:8px 0 24px}
                .wpap-adp-off{background:#fff8ef;border:1px solid #f0dccb;color:#b45309;border-radius:10px;padding:16px;font-size:13px;text-align:center}
                .wpap-adp-art{background:#fff;border:1px solid #e8e2d8;border-radius:14px;padding:18px 18px 22px;box-shadow:0 8px 26px -14px rgba(60,50,40,.3);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
                .wpap-adp-art .chip{display:inline-block;background:#d1604a;color:#fff;font-size:10px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;padding:.3em .6em;border-radius:5px}
                .wpap-adp-art h3.t{font-family:Georgia,"Times New Roman",serif;font-size:20px;line-height:1.15;margin:.5em 0 .3em;color:#1b1815;font-weight:800}
                .wpap-adp-art .meta{color:#8a8178;font-size:12px;margin-bottom:12px}
                .wpap-adp-art .hero{aspect-ratio:16/9;border-radius:9px;background:linear-gradient(135deg,#f9a03f,#d1604a 55%,#8e2d8e);display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.92);font-size:12px;font-weight:600;margin-bottom:14px}
                .wpap-adp-art p{font-size:13.5px;line-height:1.7;color:#3a352f;margin:0 0 .85em}
                .wpap-adp-art p.lede{font-size:15px;color:#1b1815}
                .wpap-adp-art .rel{border-top:1px solid #eee7dd;margin-top:14px;padding-top:10px}
                .wpap-adp-art .rel h4{font-family:Georgia,serif;font-size:15px;margin:0 0 8px;color:#1b1815}
                .wpap-adp-art .relg{display:grid;grid-template-columns:1fr 1fr;gap:8px}
                .wpap-adp-art .relg span{height:46px;border-radius:6px;background:#f1ede6;display:block}
                .wpap-adp-ad{border:1px dashed #e6b98f;background:repeating-linear-gradient(45deg,#fffaf4,#fffaf4 8px,#fdf2e7 8px,#fdf2e7 16px);border-radius:8px;margin:14px 0;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;color:#b06a3a}
                .wpap-adp-ad .lab{font-size:8.5px;letter-spacing:.14em;text-transform:uppercase;color:#c99b6f;font-weight:700}
                .wpap-adp-ad .nm{font-size:13px;font-weight:800;margin-top:2px}
                .wpap-adp-ad .sz{font-size:10.5px;color:#9c7a55;margin-top:1px;font-weight:600}
                .wpap-adp-ad.hwide{min-height:58px}
                .wpap-adp-ad.hmed{min-height:92px}
                .wpap-adp-ad.htall{min-height:150px}
                .wpap-adp-ad.hbox{min-height:180px;max-width:250px;margin-left:auto;margin-right:auto}
                .wpap-adp-zone{margin:0 0 12px}
                .wpap-adp-auto{margin-top:12px;font-size:12px;color:#4f46e5;background:#eef0ff;border:1px solid #dfe3ff;border-radius:8px;padding:8px 12px}
                .wpap-adp-side{margin-top:12px;border:1px dashed #cbd5e1;border-radius:8px;padding:10px}
                .wpap-adp-side .note{display:block;font-size:11px;color:#94a3b8;margin-top:6px;text-align:center}
                .wpap-adp-count{font-size:12px;color:#6b7280;margin:0 0 10px}
                .wpap-adp-count b{color:#111827}
            </style>
            <div id="wpap-adprev" class="wpap-adprev"></div>
            <script>
            (function(){
                var host = document.getElementById('wpap-adprev');
                if ( ! host ) { return; }
                function el(n){ return document.querySelector('[name="'+n+'"]'); }
                function on(n){ var e=el(n); return !!(e && e.checked); }
                function numv(n,d){ var e=el(n); var v=parseInt(e&&e.value,10); return isNaN(v)?d:v; }
                function customs(){
                    var pos=document.getElementsByName('wpap_ads_cust_pos[]');
                    var aft=document.getElementsByName('wpap_ads_cust_after[]');
                    var out=[];
                    for(var i=0;i<pos.length;i++){ out.push({pos:pos[i].value, after:(parseInt(aft[i]&&aft[i].value,10)||2)}); }
                    return out;
                }
                var SZ={
                    top:['Top banner','Leaderboard 728×90 / responsive','hwide'],
                    incontent:['In-content','In-article · responsive','hmed'],
                    repeat:['Repeating','In-article · responsive','hmed'],
                    custom:['Custom','In-article · responsive','hmed'],
                    bottom:['Before related','Multiplex / display · responsive','htall'],
                    header:['Header zone','Leaderboard 728×90 / responsive','hwide'],
                    footer:['Footer zone','Banner · responsive','hwide'],
                    sidebar:['Sidebar zone','Medium rectangle 300×250','hbox']
                };
                function ad(kind){
                    var s=SZ[kind];
                    return '<div class="wpap-adp-ad '+s[2]+'"><span class="lab">Advertisement</span><span class="nm">'+s[0]+'</span><span class="sz">'+s[1]+'</span></div>';
                }
                var PARAS=[
                    'It started, like most things do now, with a single photo shared into a group of half a million people.',
                    'By morning the little town square had a line around it, and nobody could quite say why.',
                    'The story is simple, which is exactly why it travels: a shopkeeper, a folded note, and a promise.',
                    'She did not recognize him at first, but she knew the handwriting the moment he slid it across the counter.',
                    'Nineteen years earlier he had left that note on her door and then left the town for good.',
                    'Neighbours gathered. Phones came out. The clip was already climbing before lunch.',
                    'What he wrote, and what he came back to do, is the part people keep screenshotting.',
                    'They closed the shop for the afternoon. Someone brought chairs out to the pavement.',
                    'A town nobody could find on a map the day before had the whole internet’s attention.',
                    'And the shopkeeper, who had kept the note in a drawer all this time, finally read it aloud.',
                    'By evening the video had four million views and a comment section full of strangers in tears.',
                    'Some stories are shared because they shock you. This one was shared because it was kind.'
                ];
                function render(){
                    if ( ! on('wpap_ads_enabled') ){
                        host.innerHTML='<div class="wpap-adp-off">Ad injection is OFF — turn on the master switch below to preview placements.</div>';
                        return;
                    }
                    var minGap=numv('wpap_ads_min_gap',1), maxAds=numv('wpap_ads_max_ads',0);
                    var incOn=on('wpap_ads_inc_on'), incAfter=numv('wpap_ads_inc_after',2);
                    var repOn=on('wpap_ads_rep_on'), repEvery=numv('wpap_ads_rep_every',4), repMax=numv('wpap_ads_rep_max',3);
                    var topOn=on('wpap_ads_top_on'), botOn=on('wpap_ads_bot_on');
                    var cust=customs();

                    var topHtml='', botHtml='', afterMap={};
                    if(topOn){ topHtml+=ad('top'); }
                    if(botOn){ botHtml+=ad('bottom'); }   /* seed named bottom first, like the injector ($bot) */
                    if(incOn){ (afterMap[incAfter]=afterMap[incAfter]||[]).push('incontent'); }
                    cust.forEach(function(c){
                        if(c.pos==='top'){ topHtml+=ad('custom'); }
                        else if(c.pos==='before_related'){ botHtml+=ad('custom'); }
                        else { (afterMap[c.after]=afterMap[c.after]||[]).push('custom'); }
                    });

                    var body='', para=0, reps=0, placed=0, last=null;
                    for(var i=0;i<PARAS.length;i++){
                        body+='<p'+(i===0?' class="lede"':'')+'>'+PARAS[i]+'</p>';
                        para=i+1;
                        var here=[];
                        if(afterMap[para]){ afterMap[para].forEach(function(k){ here.push(k); }); }
                        else if(repOn && repEvery>0 && (para%repEvery)===0 && reps<repMax){ here.push('repeat'); }
                        for(var j=0;j<here.length;j++){
                            if(maxAds>0 && placed>=maxAds){ break; }
                            if(minGap>0 && last!==null && (para-last)<minGap){ break; }
                            body+=ad(here[j]);
                            last=para; placed++;
                            if(here[j]==='repeat'){ reps++; }
                        }
                    }

                    var hZone=on('wpap_ads_zone_header_on')?'<div class="wpap-adp-zone">'+ad('header')+'</div>':'';
                    var fZone=on('wpap_ads_zone_footer_on')?'<div class="wpap-adp-zone">'+ad('footer')+'</div>':'';
                    var sZone=on('wpap_ads_zone_sidebar_on')?'<div class="wpap-adp-side">'+ad('sidebar')+'<span class="note">Sidebar shows on desktop, beside the article</span></div>':'';
                    var aCode=el('wpap_ads_auto_code');
                    var aNote=(aCode && aCode.value.trim()!=='')?'<div class="wpap-adp-auto">＋ Auto Ads is on: Google places additional ads automatically, site-wide.</div>':'';

                    var inContentCount=placed;
                    var totalManual=inContentCount+(topOn?1:0)+(botOn?1:0);
                    cust.forEach(function(c){ if(c.pos==='top'||c.pos==='before_related'){ totalManual++; } });

                    host.innerHTML=
                        '<p class="wpap-adp-count"><b>'+totalManual+'</b> ad'+(totalManual===1?'':'s')+' in this article ('+inContentCount+' in-content)'+(maxAds>0?' · cap '+maxAds:'')+' · min '+minGap+' ¶ apart</p>'+
                        hZone+
                        '<article class="wpap-adp-art">'+
                            '<span class="chip">Human Stories</span>'+
                            '<h3 class="t">The Note on the Door That a Town Waited 19 Years to Read</h3>'+
                            '<div class="meta">By Sara M. · 4 min read</div>'+
                            '<div class="hero">Featured image</div>'+
                            topHtml+body+botHtml+
                            '<div class="rel"><h4>You May Also Like</h4><div class="relg"><span></span><span></span><span></span><span></span></div></div>'+
                        '</article>'+
                        fZone+aNote+sZone;
                }
                var t=null;
                document.addEventListener('input', function(e){ if(e.target&&e.target.name&&e.target.name.indexOf('wpap_ads_')===0){ clearTimeout(t); t=setTimeout(render,120); } });
                document.addEventListener('change', function(e){ if(e.target&&e.target.name&&e.target.name.indexOf('wpap_ads_')===0){ render(); } });
                document.addEventListener('click', function(e){ if(e.target&&(e.target.id==='wpap-add-placement'||(e.target.className&&(''+e.target.className).indexOf('wpap-cust-remove')>-1))){ setTimeout(render,60); } });
                render();
            })();
            </script>

            <?php /* ── Earnings estimator + one-click ad modes (additive; fills the slots below) ── */ ?>
            <h3 style="margin:26px 0 4px;">Earnings estimator &amp; ad modes <span style="font-weight:400;color:#666;">— rough monthly projection</span></h3>
            <p class="description" style="max-width:820px;">Set your pageviews and a realistic viewable eCPM, then compare three ready-made layouts. Click <strong>Apply</strong> to fill the slots below with that mode (the live preview updates) — then <strong>Save changes</strong> to keep it. Estimates only; nothing here reads or touches your AdSense account.</p>
            <style>
                .wpap-earn{max-width:820px;margin:8px 0 26px}
                .wpap-earn .ctl{display:flex;flex-wrap:wrap;gap:16px 22px;align-items:flex-end;margin-bottom:14px}
                .wpap-earn .ctl label{font-size:12px;font-weight:600;color:#444;display:flex;flex-direction:column;gap:5px}
                .wpap-earn .ctl input{font-size:14px;padding:6px 8px;border:1px solid #c7cdd6;border-radius:6px;width:150px}
                .wpap-earn table{border-collapse:collapse;width:100%;background:#fff;border:1px solid #e2e4e9;border-radius:10px;overflow:hidden}
                .wpap-earn th,.wpap-earn td{padding:10px 12px;text-align:right;border-bottom:1px solid #eef0f3;font-variant-numeric:tabular-nums}
                .wpap-earn th:first-child,.wpap-earn td:first-child{text-align:left}
                .wpap-earn thead th{background:#f6f7f9;font-size:11px;letter-spacing:.04em;text-transform:uppercase;color:#6b7280}
                .wpap-earn tbody tr:last-child td{border-bottom:0}
                .wpap-earn .m{font-weight:700}
                .wpap-earn .sub{font-size:11px;color:#8a8f98;font-weight:400}
                .wpap-earn .money{color:#158a55;font-weight:700}
                .wpap-earn .note{font-size:11.5px;color:#8a8f98;margin-top:9px;line-height:1.5}
            </style>
            <div class="wpap-earn">
                <div class="ctl">
                    <label>Monthly pageviews<input type="text" id="wpap-earn-pv" value="120,000" inputmode="numeric"></label>
                    <label>Viewable eCPM ($ per 1,000)<input type="number" id="wpap-earn-ecpm" value="5" min="0" step="0.5"></label>
                </div>
                <table>
                    <thead><tr><th>Ad mode</th><th>Ads / page</th><th>Reader load</th><th>Est. RPM</th><th>Est. monthly</th><th></th></tr></thead>
                    <tbody id="wpap-earn-body"></tbody>
                </table>
                <p class="note">RPM = revenue per 1,000 pageviews. &ldquo;Reader load&rdquo; is how heavy the page feels. Real earnings depend on your niche, country, season and Google&rsquo;s auction &mdash; set the eCPM to your own number. More ads earn more per page but ask more of the reader. <strong>Maximized&rsquo;s total includes Auto Ads</strong> &mdash; Apply sets the manual slots, but paste your Auto Ads code in the field below to actually turn those extra ads on.</p>
            </div>
            <script>
            (function(){
                var body=document.getElementById('wpap-earn-body');
                if(!body){return;}
                function el(n){return document.querySelector('[name="'+n+'"]');}
                function pnum(s){var n=parseInt((''+s).replace(/[^0-9]/g,''),10);return isNaN(n)?0:n;}
                var CAT={
                    header:{v:.68,p:.95}, top:{v:.72,p:1.0}, incontent:{v:.66,p:1.15},
                    repeat:{v:.50,p:1.0}, bottom:{v:.46,p:.9}, sidebar:{v:.60,p:.85}, footer:{v:.30,p:.7}
                };
                var DECAY=[.54,.47,.41,.36,.32];
                var MODES={
                    minimal:{label:'Minimal',load:'Light',auto:false,slots:['incontent','bottom'],
                        apply:{enabled:1,top:0,inc:1,incAfter:3,rep:0,repEvery:4,repMax:3,bot:1,zh:0,zs:0,zf:0,gap:2,max:0}},
                    balanced:{label:'Balanced',load:'Medium',auto:false,slots:['top','incontent','repeat','repeat','bottom','sidebar'],
                        apply:{enabled:1,top:1,inc:1,incAfter:2,rep:1,repEvery:4,repMax:2,bot:1,zh:0,zs:1,zf:0,gap:1,max:0}},
                    max:{label:'Maximized',load:'Heavy',auto:true,slots:['header','top','incontent','repeat','repeat','repeat','repeat','bottom','sidebar','footer'],
                        apply:{enabled:1,top:1,inc:1,incAfter:2,rep:1,repEvery:3,repMax:4,bot:1,zh:1,zs:1,zf:1,gap:1,max:0}}
                };
                function money(n){return '$'+Math.round(n).toLocaleString('en-US');}
                function calc(mode,pv,ecpm){
                    var total=0,ri=0;
                    mode.slots.forEach(function(k){
                        var c=CAT[k]; var view=(k==='repeat')?DECAY[Math.min(ri++,DECAY.length-1)]:c.v;
                        total+= pv*view*(ecpm*c.p)/1000;
                    });
                    if(mode.auto){ total*=1.14; }
                    var count=mode.slots.length+(mode.auto?1:0);
                    var rpm=pv>0?total/pv*1000:0;
                    return {count:count,rpm:rpm,total:total};
                }
                function render(){
                    var pv=pnum(document.getElementById('wpap-earn-pv').value);
                    var ecpm=Math.max(0,parseFloat(document.getElementById('wpap-earn-ecpm').value)||0);
                    var html='';
                    Object.keys(MODES).forEach(function(key){
                        var m=MODES[key]; var r=calc(m,pv,ecpm);
                        html+='<tr><td class="m">'+m.label+(m.auto?' <span class="sub">+ Auto Ads</span>':'')+'</td>'+
                              '<td>'+r.count+'</td><td>'+m.load+'</td><td>$'+r.rpm.toFixed(2)+'</td>'+
                              '<td class="money">'+money(r.total)+'</td>'+
                              '<td><button type="button" class="button button-small" data-wpapmode="'+key+'">Apply</button></td></tr>';
                    });
                    body.innerHTML=html;
                }
                function applyMode(key){
                    var a=MODES[key].apply;
                    function setc(name,val){var e=el(name);if(e){e.checked=!!val;}}
                    function setn(name,val){var e=el(name);if(e){e.value=val;}}
                    setc('wpap_ads_enabled',a.enabled);
                    setc('wpap_ads_top_on',a.top);
                    setc('wpap_ads_inc_on',a.inc); setn('wpap_ads_inc_after',a.incAfter);
                    setc('wpap_ads_rep_on',a.rep); setn('wpap_ads_rep_every',a.repEvery); setn('wpap_ads_rep_max',a.repMax);
                    setc('wpap_ads_bot_on',a.bot);
                    setc('wpap_ads_zone_header_on',a.zh); setc('wpap_ads_zone_sidebar_on',a.zs); setc('wpap_ads_zone_footer_on',a.zf);
                    setn('wpap_ads_min_gap',a.gap); setn('wpap_ads_max_ads',a.max);
                    var m=el('wpap_ads_enabled'); if(m){ m.dispatchEvent(new Event('change',{bubbles:true})); }
                    var host=document.getElementById('wpap-adprev'); if(host&&host.scrollIntoView){ host.scrollIntoView({behavior:'smooth',block:'center'}); }
                }
                document.getElementById('wpap-earn-pv').addEventListener('input',render);
                document.getElementById('wpap-earn-ecpm').addEventListener('input',render);
                body.addEventListener('click',function(e){
                    var b=e.target.closest?e.target.closest('[data-wpapmode]'):null;
                    if(b){ applyMode(b.getAttribute('data-wpapmode')); }
                });
                render();
            })();
            </script>

            <table class="form-table">
                <tr>
                    <th scope="row">Enable ads</th>
                    <td>
                        <label><input type="checkbox" name="wpap_ads_enabled" value="1" <?php checked( ! empty( $ads['enabled'] ) ); ?>> Master switch — print ads on the front end</label><br>
                        <label style="display:inline-block;margin-top:6px;"><input type="checkbox" name="wpap_ads_scope_all" value="1" <?php checked( ! empty( $ads['scope_all'] ) ); ?>> Apply to <strong>all</strong> posts (off = only posts this plugin created)</label><br>
                        <label style="display:inline-block;margin-top:6px;"><input type="checkbox" name="wpap_ads_label" value="1" <?php checked( ! empty( $ads['label'] ) ); ?>> Show a small &ldquo;Advertisement&rdquo; label above each ad</label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Auto Ads code</th>
                    <td>
                        <textarea name="wpap_ads_auto_code" rows="4" class="large-text code" placeholder="&lt;script async src=&quot;https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-XXXX&quot; crossorigin=&quot;anonymous&quot;&gt;&lt;/script&gt;"><?php echo esc_textarea( (string) $ads['auto_code'] ); ?></textarea>
                        <p class="description">Your Auto Ads snippet from AdSense. This snippet is also the loader for the manual units below, so pasting it once is enough.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">In-content density</th>
                    <td>
                        Min paragraphs between ads: <input type="number" name="wpap_ads_min_gap" min="0" max="20" step="1" value="<?php echo esc_attr( (string) $ads['min_gap'] ); ?>" class="small-text" />
                        &nbsp;&nbsp; Max in-content ads per post (0 = no limit): <input type="number" name="wpap_ads_max_ads" min="0" max="20" step="1" value="<?php echo esc_attr( (string) $ads['max_ads'] ); ?>" class="small-text" />
                    </td>
                </tr>
            </table>

            <h3 style="margin-top:20px;">Manual slots</h3>
            <table class="form-table">
                <tr>
                    <th scope="row">Top of article</th>
                    <td>
                        <label><input type="checkbox" name="wpap_ads_top_on" value="1" <?php checked( ! empty( $ads['slots']['top']['on'] ) ); ?>> On</label>
                        <textarea name="wpap_ads_top_code" rows="3" class="large-text code" placeholder="Paste an AdSense unit…"><?php echo esc_textarea( (string) $ads['slots']['top']['code'] ); ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th scope="row">In-content (after a paragraph)</th>
                    <td>
                        <label><input type="checkbox" name="wpap_ads_inc_on" value="1" <?php checked( ! empty( $ads['slots']['incontent']['on'] ) ); ?>> On</label>
                        &nbsp; after paragraph <input type="number" name="wpap_ads_inc_after" min="1" max="50" step="1" value="<?php echo esc_attr( (string) $ads['slots']['incontent']['after'] ); ?>" class="small-text" />
                        <textarea name="wpap_ads_inc_code" rows="3" class="large-text code" placeholder="Use an In-article unit…"><?php echo esc_textarea( (string) $ads['slots']['incontent']['code'] ); ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Repeating in-content</th>
                    <td>
                        <label><input type="checkbox" name="wpap_ads_rep_on" value="1" <?php checked( ! empty( $ads['slots']['repeat']['on'] ) ); ?>> On</label>
                        &nbsp; every <input type="number" name="wpap_ads_rep_every" min="1" max="50" step="1" value="<?php echo esc_attr( (string) $ads['slots']['repeat']['every'] ); ?>" class="small-text" /> paragraphs,
                        max <input type="number" name="wpap_ads_rep_max" min="1" max="10" step="1" value="<?php echo esc_attr( (string) $ads['slots']['repeat']['max'] ); ?>" class="small-text" /> times
                        <textarea name="wpap_ads_rep_code" rows="3" class="large-text code" placeholder="Use an In-article unit…"><?php echo esc_textarea( (string) $ads['slots']['repeat']['code'] ); ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Bottom of article</th>
                    <td>
                        <label><input type="checkbox" name="wpap_ads_bot_on" value="1" <?php checked( ! empty( $ads['slots']['bottom']['on'] ) ); ?>> On</label>
                        <textarea name="wpap_ads_bot_code" rows="3" class="large-text code" placeholder="Paste an AdSense unit…"><?php echo esc_textarea( (string) $ads['slots']['bottom']['code'] ); ?></textarea>
                    </td>
                </tr>
            </table>

            <h3 style="margin-top:20px;">Page zones (header / sidebar / footer)</h3>
            <p class="description" style="max-width:820px;">Filled into a compatible theme's ad zones (via <code>wpap_zone_html()</code>). If your theme doesn't call it, these are simply unused.</p>
            <table class="form-table">
                <tr>
                    <th scope="row">Header</th>
                    <td>
                        <label><input type="checkbox" name="wpap_ads_zone_header_on" value="1" <?php checked( ! empty( $ads['zones']['header']['on'] ) ); ?>> On</label>
                        <textarea name="wpap_ads_zone_header_code" rows="2" class="large-text code"><?php echo esc_textarea( (string) $ads['zones']['header']['code'] ); ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Sidebar</th>
                    <td>
                        <label><input type="checkbox" name="wpap_ads_zone_sidebar_on" value="1" <?php checked( ! empty( $ads['zones']['sidebar']['on'] ) ); ?>> On</label>
                        <textarea name="wpap_ads_zone_sidebar_code" rows="2" class="large-text code"><?php echo esc_textarea( (string) $ads['zones']['sidebar']['code'] ); ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Footer</th>
                    <td>
                        <label><input type="checkbox" name="wpap_ads_zone_footer_on" value="1" <?php checked( ! empty( $ads['zones']['footer']['on'] ) ); ?>> On</label>
                        <textarea name="wpap_ads_zone_footer_code" rows="2" class="large-text code"><?php echo esc_textarea( (string) $ads['zones']['footer']['code'] ); ?></textarea>
                    </td>
                </tr>
            </table>

            <?php /* ── Custom placements (Option 2): unlimited, up to 10 — {pos, after, code} rows ── */ ?>
            <h3 style="margin-top:20px;">Custom placements <span style="font-weight:400;color:#666;">— add your own, up to 10</span></h3>
            <p class="description" style="max-width:820px;">Place an extra unit exactly where you want it: <strong>after paragraph&nbsp;N</strong>, at the <strong>top</strong> of the article, or <strong>before the related posts</strong>. Each row needs its own AdSense code, and all placements honour the min-gap / max-ads caps below. A row left blank is simply dropped on save.</p>
            <div id="wpap-cust-rows">
                <?php
                $wpap_custom_rows = ( isset( $ads['custom'] ) && is_array( $ads['custom'] ) ) ? $ads['custom'] : array();
                foreach ( $wpap_custom_rows as $wpap_cr ) :
                    $cr_pos   = isset( $wpap_cr['pos'] ) ? (string) $wpap_cr['pos'] : 'after';
                    $cr_after = isset( $wpap_cr['after'] ) ? (int) $wpap_cr['after'] : 2;
                    $cr_code  = isset( $wpap_cr['code'] ) ? (string) $wpap_cr['code'] : '';
                    ?>
                    <div class="wpap-cust-row" style="border:1px solid #e2e0d8;border-radius:8px;padding:12px 14px;margin:0 0 12px;max-width:820px;background:#fcfbf8">
                        <label>Position
                            <select name="wpap_ads_cust_pos[]">
                                <option value="after" <?php selected( $cr_pos, 'after' ); ?>>After paragraph</option>
                                <option value="top" <?php selected( $cr_pos, 'top' ); ?>>Top of article</option>
                                <option value="before_related" <?php selected( $cr_pos, 'before_related' ); ?>>Before related posts</option>
                            </select>
                        </label>
                        &nbsp; ¶ <input type="number" name="wpap_ads_cust_after[]" min="1" max="50" step="1" value="<?php echo esc_attr( (string) $cr_after ); ?>" class="small-text" title="Paragraph number (used only for the After-paragraph position)" />
                        <button type="button" class="button-link wpap-cust-remove" style="color:#b32d2e;float:right">Remove</button>
                        <textarea name="wpap_ads_cust_code[]" rows="3" class="large-text code" placeholder="Paste an AdSense unit…"><?php echo esc_textarea( $cr_code ); ?></textarea>
                    </div>
                <?php endforeach; ?>
            </div>
            <template id="wpap-cust-tpl">
                <div class="wpap-cust-row" style="border:1px solid #e2e0d8;border-radius:8px;padding:12px 14px;margin:0 0 12px;max-width:820px;background:#fcfbf8">
                    <label>Position
                        <select name="wpap_ads_cust_pos[]">
                            <option value="after">After paragraph</option>
                            <option value="top">Top of article</option>
                            <option value="before_related">Before related posts</option>
                        </select>
                    </label>
                    &nbsp; ¶ <input type="number" name="wpap_ads_cust_after[]" min="1" max="50" step="1" value="2" class="small-text" title="Paragraph number (used only for the After-paragraph position)" />
                    <button type="button" class="button-link wpap-cust-remove" style="color:#b32d2e;float:right">Remove</button>
                    <textarea name="wpap_ads_cust_code[]" rows="3" class="large-text code" placeholder="Paste an AdSense unit…"></textarea>
                </div>
            </template>
            <p><button type="button" class="button" id="wpap-add-placement">+ Add placement</button> &nbsp;<span class="description">Up to 10. Saved with the same “Save changes” button below.</span></p>
            <script>
            (function(){
                var addBtn = document.getElementById('wpap-add-placement');
                var rows   = document.getElementById('wpap-cust-rows');
                var tpl    = document.getElementById('wpap-cust-tpl');
                if ( ! addBtn || ! rows || ! tpl ) { return; }
                addBtn.addEventListener('click', function(){
                    if ( rows.querySelectorAll('.wpap-cust-row').length >= 10 ) { window.alert('Up to 10 custom placements.'); return; }
                    rows.appendChild( tpl.content.cloneNode( true ) );
                });
                rows.addEventListener('click', function(e){
                    var b = e.target && e.target.closest ? e.target.closest('.wpap-cust-remove') : null;
                    if ( b ) { var row = b.closest('.wpap-cust-row'); if ( row ) { row.remove(); } }
                });
            })();
            </script>

            <?php /* ── Content options (publishing guards) ── */ ?>
            <?php
            $copts_ui = get_option( 'wpap_content_opts', array() );
            if ( ! is_array( $copts_ui ) ) { $copts_ui = array(); }
            ?>
            <h2 style="margin-top:32px;">Content options</h2>
            <p class="description" style="max-width:760px;">Optional safety nets for Direct Publish &amp; the Google-Sheet automation. All off by default.</p>
            <table class="form-table">
                <tr>
                    <th scope="row">Skip duplicate titles</th>
                    <td><label><input type="checkbox" name="wpap_skip_dupe_titles" value="1" <?php checked( ! empty( $copts_ui['skip_dupe_titles'] ) ); ?> /> Don't publish a post if one with the same title already exists</label>
                        <p class="description">Protects against creating duplicates when you re-upload the same file/batch.</p></td>
                </tr>
                <tr>
                    <th scope="row">Minimum word count</th>
                    <td><input type="number" name="wpap_min_words" min="0" max="5000" step="10" class="small-text" value="<?php echo esc_attr( (string) ( $copts_ui['min_words'] ?? 0 ) ); ?>" /> words
                        <p class="description">Skip publishing a post whose body is below this. 0 = no minimum.</p></td>
                </tr>
                <tr>
                    <th scope="row">Disable comments</th>
                    <td><label><input type="checkbox" name="wpap_disable_comments" value="1" <?php checked( ! empty( $copts_ui['disable_comments'] ) ); ?> /> Close comments on posts this plugin publishes</label></td>
                </tr>
                <tr>
                    <th scope="row">Clean media</th>
                    <td><label><input type="checkbox" name="wpap_clean_media" value="1" <?php checked( ! empty( $copts_ui['clean_media'] ) ); ?> /> Rename imported images from the post title (SEO-friendly) and strip EXIF/metadata</label>
                        <p class="description">Off by default. Applies to remote downloads and Bulk-ZIP local images.</p></td>
                </tr>
                <tr>
                    <th scope="row">First-comment text</th>
                    <td>
                        <textarea id="wpap_fb_comment_template" name="wpap_fb_comment_template" rows="4" class="large-text code" placeholder="&#128073; Full article here:&#10;{{link}}&#10;&#10;&#10102; Save &amp; share!"><?php echo esc_textarea( (string) ( $copts_ui['fb_comment_template'] ?? '' ) ); ?></textarea>
                        <p class="description">Default text for the Distribution Hub's exported <strong>first comment</strong>. Use <code>{{link}}</code> where the article link goes (omit it and the link is appended on its own line). Leave blank to export just the bare link. A single post can override this from its Hub row (&#9999;&#65039; Comment).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Convert images to WebP</th>
                    <td><label><input type="checkbox" name="wpap_webp_enabled" value="1" <?php checked( '1' === (string) get_option( 'wpap_webp_enabled', '1' ) ); ?> /> Re-encode downloaded JPEG/PNG images to WebP &mdash; ~25&ndash;50% smaller, faster on mobile</label>
                        <p class="description">On by default. Applies to newly imported images (featured image + all sizes). Needs GD or Imagick with WebP support (standard on most hosts); if unsupported it keeps the original automatically, so publishing is never affected.</p></td>
                </tr>
            </table>

            <h2 style="margin-top:32px;">Facebook identifiers</h2>
            <p class="description" style="max-width:760px;">Both optional and empty by default &mdash; nothing is added to your pages until you fill one in. Purge any page cache after saving.</p>
            <table class="form-table">
                <tr>
                    <th scope="row">Domain verification</th>
                    <td><input type="text" name="wpap_fb_domain_verify" class="regular-text" value="<?php echo esc_attr( (string) ( $copts_ui['fb_domain_verify'] ?? '' ) ); ?>" placeholder="e.g. a1b2c3d4e5f6g7h8" autocomplete="off" />
                        <p class="description">Proves to Meta that you own this domain, so you control how its links preview on Facebook. In Meta Business Suite &rarr; Settings &rarr; Brand safety &amp; suitability &rarr; Domains, add your domain, choose <em>Add a meta-tag</em>, and paste ONLY the tag&rsquo;s <code>content</code> value here (not the whole tag). Output site-wide &mdash; Meta crawls the homepage <code>&lt;head&gt;</code>.</p></td>
                </tr>
                <tr>
                    <th scope="row">App ID <span style="font-weight:400;color:#666">(<code>fb:app_id</code>)</span></th>
                    <td><input type="text" name="wpap_fb_app_id" class="regular-text" value="<?php echo esc_attr( (string) ( $copts_ui['fb_app_id'] ?? '' ) ); ?>" placeholder="numeric app id" inputmode="numeric" autocomplete="off" />
                        <p class="description">Optional. If you have a Meta for Developers app, paste its numeric App ID to silence the Sharing Debugger&rsquo;s &ldquo;missing <code>fb:app_id</code>&rdquo; warning and tie shares to your app for Insights. Output only on this plugin&rsquo;s post pages. Leave blank if you don&rsquo;t have one.</p></td>
                </tr>
            </table>

            <h2 style="margin-top:32px;">AI search discoverability</h2>
            <p class="description" style="max-width:760px;">Serves two small files that AI assistants (ChatGPT, Claude, Perplexity, Grok) look for &mdash; generated automatically from your live content. Complements your XML sitemap; it does <strong>not</strong> replace it (Google doesn&rsquo;t rank on these). Opt-in, off by default. Purge any page cache after saving.</p>
            <table class="form-table">
                <tr>
                    <th scope="row">Serve llms.txt &amp; ai.txt</th>
                    <td><label><input type="checkbox" name="wpap_llms_txt" value="1" <?php checked( ! empty( $copts_ui['llms_txt'] ) ); ?> /> Serve <code>/llms.txt</code> (a plain-text map of your site &mdash; name, tagline, each category with its posts, following the <em>llmstxt.org</em> convention) and <code>/ai.txt</code> (an AI-crawler policy that welcomes crawling and citation with attribution and points to your sitemap)</label>
                        <p class="description">Both are generated from your live posts and pages and refresh when you publish. Once on, visit <code><?php echo esc_html( home_url( '/llms.txt' ) ); ?></code> to confirm. A real <code>llms.txt</code>/<code>ai.txt</code> file at your site root always wins.</p></td>
                </tr>
            </table>

            <h2 style="margin-top:32px;">Duplicate cleanup</h2>
            <p class="description" style="max-width:860px;">Finds posts that cover the <strong>same topic under a reworded title</strong> &mdash; the near-duplicates the exact-title guard can&rsquo;t catch (e.g. &ldquo;Rice Water for Tender Skin&rdquo; vs &ldquo;The Humblest Medicine: Rice Water&hellip;&rdquo;). It groups them, keeps the <strong>oldest original</strong>, and lets you move the rest to <strong>Trash</strong> (recoverable). Duplicate/thin content is a common AdSense &amp; SEO problem &mdash; but always <strong>review the list and untick any false positive</strong> (two different recipes can share words) before trashing.</p>
            <p>
                <button type="button" class="button button-primary" id="wpap-dup-scan">&#128269; Scan for duplicates</button>
                <label style="margin-left:12px">Sensitivity <input type="number" id="wpap-dup-threshold" value="0.42" min="0.2" max="0.95" step="0.02" class="small-text" title="Lower catches looser matches (more false positives); higher is stricter. 0.42 is a good default." /></label>
                <button type="button" class="button" id="wpap-dup-trash" style="display:none;margin-left:12px">&#129529; Move checked to Trash</button>
                <span id="wpap-dup-status" style="margin-left:8px;color:#334155;"></span>
            </p>
            <div id="wpap-dup-results" style="max-width:920px"></div>
            <script>
            (function(){
                var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
                    nonce   = <?php echo wp_json_encode( wp_create_nonce( 'wpap_nonce' ) ); ?>;
                var scanBtn=document.getElementById('wpap-dup-scan'), trashBtn=document.getElementById('wpap-dup-trash'),
                    out=document.getElementById('wpap-dup-results'), statusEl=document.getElementById('wpap-dup-status'),
                    thr=document.getElementById('wpap-dup-threshold');
                if(!scanBtn) return;
                function esc(s){var d=document.createElement('div');d.textContent=(s==null?'':String(s));return d.innerHTML;}
                scanBtn.addEventListener('click', function(){
                    scanBtn.disabled=true; statusEl.textContent='Scanning…'; out.innerHTML=''; trashBtn.style.display='none';
                    fetch(ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
                        body:'action=wpap_scan_near_duplicates&nonce='+encodeURIComponent(nonce)+'&threshold='+encodeURIComponent(thr.value||'0.42')})
                    .then(function(r){return r.json();}).then(function(res){
                        scanBtn.disabled=false;
                        if(!res||!res.success){statusEl.textContent='Error: '+((res&&res.data)||'failed');return;}
                        var d=res.data;
                        if(!d.group_count){statusEl.textContent='No near-duplicates found. ✅';return;}
                        statusEl.textContent=d.group_count+' duplicate group(s) · '+d.dup_count+' post(s) can be trashed (checked below). KEEP = the oldest original.';
                        var html='';
                        d.groups.forEach(function(g){
                            html+='<div style="border:1px solid #e2e8f0;border-radius:6px;padding:10px 12px;margin:8px 0;background:#fff;">';
                            html+='<div style="color:#0f766e"><strong>KEEP</strong> <span style="color:#64748b">['+esc(g.keep.date)+']</span> '+esc(g.keep.title)+'</div>';
                            g.dups.forEach(function(dp){
                                html+='<div style="margin-top:4px"><label><input type="checkbox" class="wpap-dup-cb" checked value="'+esc(dp.id)+'"> <strong style="color:#b91c1c">TRASH</strong> <span style="color:#64748b">['+esc(dp.date)+' · '+esc(dp.status)+']</span> '+esc(dp.title)+'</label></div>';
                            });
                            html+='</div>';
                        });
                        out.innerHTML=html; trashBtn.style.display='';
                    }).catch(function(){scanBtn.disabled=false;statusEl.textContent='Request failed.';});
                });
                trashBtn.addEventListener('click', function(){
                    var checked=[].slice.call(document.querySelectorAll('.wpap-dup-cb:checked'));
                    var ids=checked.map(function(c){return c.value;});
                    if(!ids.length){statusEl.textContent='Nothing checked.';return;}
                    if(!window.confirm('Move '+ids.length+' post(s) to Trash? They stay recoverable in Posts → Trash.'))return;
                    trashBtn.disabled=true; statusEl.textContent='Trashing '+ids.length+'…';
                    fetch(ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
                        body:'action=wpap_trash_near_duplicates&nonce='+encodeURIComponent(nonce)+'&'+ids.map(function(i){return 'ids[]='+encodeURIComponent(i);}).join('&')})
                    .then(function(r){return r.json();}).then(function(res){
                        trashBtn.disabled=false;
                        if(!res||!res.success){statusEl.textContent='Error: '+((res&&res.data)||'failed');return;}
                        statusEl.textContent='Trashed '+res.data.trashed+' post(s). ✅ Recoverable from Posts → Trash. Re-scan to confirm.';
                        checked.forEach(function(c){var box=c.closest('div');if(box){box.style.opacity=0.4;}c.disabled=true;c.checked=false;});
                        trashBtn.style.display='none';
                    }).catch(function(){trashBtn.disabled=false;statusEl.textContent='Request failed.';});
                });
            })();
            </script>

            <h2 style="margin-top:32px;">ads.txt (AdSense)</h2>
            <p class="description" style="max-width:760px;">
                Paste your ad networks' <code>ads.txt</code> lines. Served automatically at
                <code><?php echo esc_html( home_url( '/ads.txt' ) ); ?></code> unless a real
                <code>ads.txt</code> file already exists at your site root. Leave blank to disable.
            </p>
            <table class="form-table">
                <tr>
                    <th scope="row">ads.txt content</th>
                    <td><textarea name="wpap_ads_txt" rows="4" class="large-text code" placeholder="google.com, pub-0000000000000000, DIRECT, f08c47fec0942fa0"><?php echo esc_textarea( (string) get_option( 'wpap_ads_txt', '' ) ); ?></textarea></td>
                </tr>
            </table>

            <?php /* ── IndexNow (instant indexing) ── */ ?>
            <?php $in_on = wpap_indexnow_enabled(); $in_key = wpap_indexnow_key(); $in_last = get_option( 'wpap_indexnow_last', array() ); ?>
            <h2 style="margin-top:32px;">Instant indexing (IndexNow)</h2>
            <p class="description" style="max-width:760px;">
                Pings Bing, Yandex &amp; other IndexNow engines the moment a post goes live, so it's
                crawled in minutes instead of days. <strong>Google doesn't use IndexNow</strong> — for Google,
                submit your XML sitemap once in Search Console (Yoast/Rank Math generate it automatically).
            </p>
            <table class="form-table">
                <tr>
                    <th scope="row">Enable</th>
                    <td><label><input type="checkbox" name="wpap_indexnow_enabled" value="1" <?php checked( $in_on ); ?>> Ping on every new publish</label></td>
                </tr>
                <tr>
                    <th scope="row">Key file</th>
                    <td>
                        <a href="<?php echo esc_url( home_url( '/' . $in_key . '.txt' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( home_url( '/' . $in_key . '.txt' ) ); ?></a>
                        <p class="description">Served automatically — click to confirm it loads (it should show the key).</p>
                    </td>
                </tr>
                <?php if ( ! empty( $in_last['time'] ) ) : ?>
                <tr>
                    <th scope="row">Last ping</th>
                    <td><?php echo esc_html( human_time_diff( (int) $in_last['time'] ) . ' ago — ' . (int) ( $in_last['count'] ?? 0 ) . ' URL(s)' ); ?></td>
                </tr>
                <?php endif; ?>
            </table>

            <?php submit_button( 'Save Settings', 'primary', 'wpap_save' ); ?>
        </form>

        <?php /* ── Automation status + manual Run-now (own AJAX action, outside the settings form) ── */ ?>
        <h2 style="margin-top:32px;">Automation status</h2>
        <table class="form-table">
            <tr><th scope="row">Last run</th><td><?php echo esc_html( (string) ( $auto_status['last_run']   ?? '—' ) ); ?></td></tr>
            <tr><th scope="row">Rows found</th><td><?php echo esc_html( (string) ( $auto_status['rows_found'] ?? '—' ) ); ?></td></tr>
            <tr><th scope="row">Published</th><td><?php echo esc_html( (string) ( $auto_status['published']  ?? '—' ) ); ?></td></tr>
            <tr><th scope="row">Skipped</th><td><?php echo esc_html( (string) ( $auto_status['skipped']    ?? '—' ) ); ?></td></tr>
            <tr><th scope="row">Errors</th><td><?php echo esc_html( (string) ( $auto_status['errors']     ?? '—' ) ); ?></td></tr>
            <tr><th scope="row">Message</th><td><?php echo esc_html( (string) ( $auto_status['message']    ?? '' ) ); ?></td></tr>
        </table>
        <p>
            <button type="button" class="button button-secondary" id="wpap-auto-run-now">Run now</button>
            <span id="wpap-auto-run-msg" style="margin-left:10px;color:#555;"></span>
        </p>
        <script>
        (function(){
            var btn = document.getElementById('wpap-auto-run-now'),
                msg = document.getElementById('wpap-auto-run-msg');
            if(!btn) return;
            var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
                nonce   = <?php echo wp_json_encode( wp_create_nonce( 'wpap_nonce' ) ); ?>;
            btn.addEventListener('click', function(){
                btn.disabled = true; msg.textContent = 'Running…';
                var body = 'action=wpap_automation_run_now&nonce=' + encodeURIComponent(nonce);
                fetch(ajaxUrl, {
                    method:'POST', credentials:'same-origin',
                    headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
                    body: body
                }).then(function(r){ return r.json(); }).then(function(res){
                    btn.disabled = false;
                    if(res && res.success){ msg.textContent = (res.data && res.data.message) ? res.data.message : 'Done.'; }
                    else { msg.textContent = 'Error: ' + ((res && res.data) ? res.data : 'unknown'); }
                }).catch(function(e){ btn.disabled = false; msg.textContent = 'Request failed: ' + e; });
            });
        })();
        </script>

        <?php /* ── Internal linking: manual passes (also auto-run at the end of every bulk publish) ── */ ?>
        <h2 style="margin-top:32px;">Internal linking</h2>
        <p class="description" style="max-width:760px;">
            In-content cross-links lift crawl depth and pages-per-session (an SEO / AdSense signal). Both passes are baked into stored content and run automatically after each bulk publish — use these buttons to re-run them across the whole catalogue by hand.
        </p>
        <p class="description" style="max-width:760px;margin-top:6px;">
            <strong>Already-published posts?</strong> They have no link keywords yet, so the linker has nothing to point at. Click <strong>Activate on existing posts</strong> once — it seeds keyword targets from each post's tags (and focus keyword), then links the whole live catalogue. No re-publishing. Safe to re-run; it never overwrites keywords you set yourself.
        </p>
        <p style="margin-top:8px;">
            <button type="button" class="button button-primary" id="wpap-backfill-keywords">Activate on existing posts</button>
            <button type="button" class="button button-secondary" id="wpap-resolve-ilinks" style="margin-left:12px;">Resolve internal links</button>
            <button type="button" class="button button-secondary" id="wpap-auto-keyword-link" style="margin-left:6px;">Auto-link keywords</button>
            <span id="wpap-ilink-msg" style="margin-left:10px;color:#555;"></span>
        </p>
        <script>
        (function(){
            var bBtn = document.getElementById('wpap-backfill-keywords'),
                rBtn = document.getElementById('wpap-resolve-ilinks'),
                kBtn = document.getElementById('wpap-auto-keyword-link'),
                msg  = document.getElementById('wpap-ilink-msg');
            if(!rBtn || !kBtn) return;
            var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
                nonce   = <?php echo wp_json_encode( wp_create_nonce( 'wpap_nonce' ) ); ?>;
            function run(action, done){
                if(bBtn){ bBtn.disabled = true; } rBtn.disabled = kBtn.disabled = true; msg.textContent = 'Running…';
                fetch(ajaxUrl, {
                    method:'POST', credentials:'same-origin',
                    headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
                    body: 'action=' + action + '&nonce=' + encodeURIComponent(nonce)
                }).then(function(r){ return r.json(); }).then(function(res){
                    if(bBtn){ bBtn.disabled = false; } rBtn.disabled = kBtn.disabled = false;
                    if(res && res.success){ msg.textContent = done(res.data || {}); }
                    else { msg.textContent = 'Error: ' + ((res && res.data) ? res.data : 'unknown'); }
                }).catch(function(e){ if(bBtn){ bBtn.disabled = false; } rBtn.disabled = kBtn.disabled = false; msg.textContent = 'Request failed: ' + e; });
            }
            if(bBtn){ bBtn.addEventListener('click', function(){
                run('wpap_backfill_keywords', function(d){ return 'Seeded keywords on ' + (d.seeded||0) + ' post(s) (of ' + (d.scanned||0) + '); repaired ' + (d.repaired||0) + ' post(s); added ' + (d.links||0) + ' keyword link(s); resolved ' + (d.resolved||0) + ' marker(s).'; });
            }); }
            rBtn.addEventListener('click', function(){
                run('wpap_resolve_ilinks', function(d){ return 'Resolved ' + (d.linked||0) + ' link(s) across ' + (d.scanned||0) + ' post(s) with pending markers.'; });
            });
            kBtn.addEventListener('click', function(){
                run('wpap_auto_keyword_link', function(d){ return 'Added ' + (d.links||0) + ' keyword link(s) in ' + (d.posts_linked||0) + ' post(s) (scanned ' + (d.scanned||0) + ').'; });
            });
        })();
        </script>

        <h2 style="margin-top:32px;">Backup &amp; restore</h2>
        <?php
        if ( isset( $_GET['wpap_imported'] ) ) {
            echo '<div class="notice notice-success"><p>Settings imported.</p></div>';
        } elseif ( isset( $_GET['wpap_import_error'] ) ) {
            $err_map = array(
                'nofile' => 'No file was uploaded.',
                'size'   => 'The file is empty or larger than 512 KB.',
                'parse'  => 'That file isn\'t a valid WP Automator settings export.',
            );
            $ec = sanitize_key( wp_unslash( $_GET['wpap_import_error'] ) );
            echo '<div class="notice notice-error"><p>Import failed: ' . esc_html( $err_map[ $ec ] ?? 'unknown error.' ) . '</p></div>';
        }
        ?>
        <p class="description" style="max-width:760px;">
            Download every plugin setting — ad codes, all placements, publishing guards, automation (including your Google Sheet link), IndexNow, ads.txt, UTM link tracking — as one file, or restore it here (or on another site). <strong>Your API keys and license are never included</strong> in the export, so re-enter those after restoring.
        </p>
        <div style="display:flex;gap:28px;flex-wrap:wrap;align-items:flex-start;margin-top:8px;">
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="wpap_export_settings">
                <?php wp_nonce_field( 'wpap_export_settings' ); ?>
                <?php submit_button( 'Export settings', 'secondary', 'submit', false ); ?>
            </form>
            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <input type="hidden" name="action" value="wpap_import_settings">
                <?php wp_nonce_field( 'wpap_import_settings' ); ?>
                <input type="file" name="wpap_import_file" accept="application/json,.json" required>
                <?php submit_button( 'Import settings', 'secondary', 'submit', false ); ?>
            </form>
        </div>

        <p style="margin-top:24px;font-size:13px;color:#666;">Need help? Contact Oussama Hamri &mdash; <a href="https://wa.me/+212637122491" target="_blank" rel="noopener" style="color:#6366f1;font-weight:600;text-decoration:none;">Click Here</a></p>
    </div>
    <?php
}

/* ════════════════════════════════════════════
   5b. SCHEDULING + CONTENT-SPLIT HELPERS
   Shared by the Bulk Generator (wpap_ajax_process_title)
   and Direct Publish (wpap_ajax_bulk_publish_posts).
════════════════════════════════════════════ */

/**
 * Turn a "schedule window" (in hours) into a concrete post status + dates.
 *
 * When $window_hours is 0 (or less) the post publishes immediately. Otherwise
 * the post is set to WordPress's native "future" status with a RANDOM publish
 * time between +5 minutes and +$window_hours from now, so a batch of posts
 * spreads out naturally over the next few hours instead of all going live at
 * the exact same moment.
 *
 * @param float $window_hours Max hours ahead to schedule (0 = publish now).
 * @return array{status:string,date:string,date_gmt:string,ts_gmt:?int,label:string}
 */
/* Unix-GMT timestamp of the latest currently-scheduled ('future') post, or 0.
   Used as the durable ordered-schedule anchor so batches chain after the real queue
   regardless of the transient's state. */

/** Admin page: Bulk ZIP Publish (submenu wp-automator-pro-bundle). Self-contained inline JS
 *  uploads the .zip as multipart FormData to admin-ajax (reusing wpap_nonce). (ported from build-final 8.49.0) */
function wpap_render_bundle() {
    $has_zip = class_exists( 'ZipArchive' );
    $nonce   = wp_create_nonce( 'wpap_nonce' );
    $ajax    = admin_url( 'admin-ajax.php' );
    ?>
    <div class="wrap">
      <h1>Bulk ZIP Publish</h1>
      <p style="max-width:820px">Upload one <code>.zip</code> containing a <code>posts.json</code> plus the image files it references. Every post is published with its image pulled <strong>straight from the zip</strong> — no image hosting or public URLs needed. <code>posts.json</code> is an array of <code>{ "title", "content", "hook", "image" }</code>, where <code>image</code> is the path inside the zip (e.g. <code>images/remedy_01.jpg</code>). If <code>image</code> is an <code>http(s)</code> URL instead, it's downloaded the normal way. Images <em>inside</em> the article work too: any <code>&lt;img src="images/…"&gt;</code> in <code>content</code> that points at a file in the zip is imported into the Media Library and its link rewritten automatically.</p>
      <?php if ( ! $has_zip ) : ?>
      <div class="notice notice-error"><p>PHP's <code>ZipArchive</code> extension is not available on this server. Ask your host to enable the <code>zip</code> PHP extension to use Bulk ZIP Publish.</p></div>
      <?php endif; ?>
      <table class="form-table">
        <tr><th scope="row"><label for="wpap-bundle-file">Bundle <code>.zip</code></label></th><td><input type="file" id="wpap-bundle-file" accept=".zip,application/zip,application/x-zip-compressed" <?php disabled( ! $has_zip ); ?> /></td></tr>
        <tr><th scope="row"><label for="wpap-bundle-cat">Category <span style="font-weight:400;color:#666">(optional)</span></label></th><td><input type="text" id="wpap-bundle-cat" class="regular-text" placeholder="name or id" /> <span class="description">Applied when an item has none.</span></td></tr>
        <tr><th scope="row"><label for="wpap-bundle-parts">Pages per post</label></th><td><input type="number" id="wpap-bundle-parts" min="1" max="10" value="1" class="small-text" /> <span class="description">Split each post into N pages (1 = single page). A <code>[nextpage]</code> token in the content still wins.</span></td></tr>
        <tr><th scope="row"><label for="wpap-bundle-hours">Schedule</label></th><td><input type="text" id="wpap-bundle-hours" value="0" class="regular-text" style="max-width:140px" /> <span class="description"><code>0</code> = publish now &middot; a number = spread evenly across that many hours (0&ndash;168) &middot; <code>drip:3</code> = ~3 posts/day, daytime only (08:00&ndash;22:00) &mdash; the most human, AdSense-friendly cadence.</span></td></tr>
      </table>
      <p><button id="wpap-bundle-go" class="button button-primary" <?php disabled( ! $has_zip ); ?>>Publish bundle</button> &nbsp;<span id="wpap-bundle-status" style="font-weight:600"></span></p>
      <div id="wpap-bundle-results" style="margin-top:16px"></div>
    </div>
    <script>
    (function () {
      var AJAX = <?php echo wp_json_encode( $ajax ); ?>;
      var NONCE = <?php echo wp_json_encode( $nonce ); ?>;
      var go = document.getElementById('wpap-bundle-go');
      if (!go) return;
      function esc(s){ var d=document.createElement('div'); d.textContent=(s==null?'':String(s)); return d.innerHTML; }
      function render(rows, msgs){
        var out = document.getElementById('wpap-bundle-results'); var h='';
        if (rows && rows.length){
          h += '<h2 style="margin-bottom:6px">Published</h2><table class="widefat striped"><thead><tr><th style="width:40px">#</th><th>Title</th><th>Status</th><th style="width:60px">Image</th><th style="width:70px">Link</th></tr></thead><tbody>';
          rows.forEach(function(r,i){
            var st = esc(r.post_status) + (r.scheduled_label ? (' — ' + esc(r.scheduled_label)) : '');
            h += '<tr><td>'+(i+1)+'</td><td>'+esc(r.title)+'</td><td>'+st+'</td><td>'+(r.has_image?'✓':'—')+'</td><td><a href="'+esc(r.post_url)+'" target="_blank" rel="noopener">open</a></td></tr>';
          });
          h += '</tbody></table>';
        }
        if (msgs && msgs.length){ h += '<h2 style="margin:14px 0 6px">Notes</h2><ul style="list-style:disc;margin-left:20px">'; msgs.forEach(function(m){ h += '<li>'+esc(m)+'</li>'; }); h += '</ul>'; }
        out.innerHTML = h;
      }
      go.addEventListener('click', function(){
        var fi = document.getElementById('wpap-bundle-file');
        var f = fi && fi.files ? fi.files[0] : null;
        if (!f){ alert('Choose a .zip file first.'); return; }
        var fd = new FormData();
        fd.append('action','wpap_bulk_publish_zip');
        fd.append('nonce', NONCE);
        fd.append('bundle', f);
        fd.append('category', (document.getElementById('wpap-bundle-cat').value||''));
        fd.append('num_parts', (document.getElementById('wpap-bundle-parts').value||'1'));
        fd.append('schedule_window', (document.getElementById('wpap-bundle-hours').value||'0'));
        var status = document.getElementById('wpap-bundle-status');
        go.disabled = true; status.textContent = '⏳ Uploading & publishing…';
        document.getElementById('wpap-bundle-results').innerHTML = '';
        fetch(AJAX, { method:'POST', body:fd, credentials:'same-origin' })
          .then(function(r){ return r.json(); })
          .then(function(res){
            go.disabled = false;
            if (!res || !res.success){
              var d = res && res.data ? res.data : {};
              status.textContent = '❌ ' + (d.message || (typeof d==='string'?d:'Failed.'));
              render([], d.messages || []);
              return;
            }
            var d = res.data || {};
            status.textContent = '✓ Published ' + (d.created||0) + ' of ' + (d.total||0) + ' (' + (d.skipped||0) + ' skipped).';
            render(d.rows||[], d.messages||[]);
          })
          .catch(function(e){ go.disabled = false; status.textContent = '❌ Network error: ' + e.message; });
      });
    })();
    </script>
    <?php
}
